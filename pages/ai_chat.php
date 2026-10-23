<?php
// pages/ai_chat.php
// The browser sends report data to the local Node server. The ChatterMate API key
// remains inside the root .env file and is never placed in this PHP page.
require_once __DIR__ . '/../includes/db.php';

function reportDate(string $value, string $fallback): string
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
}

function money(float $value): string
{
    return 'Tk ' . number_format($value, 2);
}

$today = date('Y-m-d');
$from = reportDate((string) ($_GET['from'] ?? $today), $today);
$to = reportDate((string) ($_GET['to'] ?? $today), $today);

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$reportData = [
    'period' => [
        'from' => $from,
        'to' => $to,
    ],
    'money_unit' => 'Taka (Tk)',
    'daily_collection' => [
        'visit_fees' => 0,
        'lab_tests' => 0,
        'total_collected' => 0,
        'payment_count' => 0,
    ],
    'patient_registrations' => [
        'count' => 0,
    ],
    'doctor_wise' => [],
    'lab_tests_report' => [
        'ordered' => 0,
        'done' => 0,
        'pending' => 0,
        'by_test' => [],
    ],
    'patients_with_due' => [],
    'data_notes' => [
        'Patient balances are all-time totals calculated by UH ID.',
        'Payments are linked to patients by UH ID, not to individual visit or lab-order records.',
        'Only fictional demonstration data should be used in this project.',
    ],
];

$pageError = '';

try {
    // Dates are validated as YYYY-MM-DD before being escaped and used in queries.
    $fromSql = $conn->real_escape_string($from);
    $toSql = $conn->real_escape_string($to);

    // Collection report.
    $sql = "SELECT payment_type, COUNT(*) AS payment_count,
                   COALESCE(SUM(amount), 0) AS amount
            FROM payments
            WHERE payment_date BETWEEN '{$fromSql}' AND '{$toSql}'
            GROUP BY payment_type";

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        if ($row['payment_type'] === 'Visit Fee') {
            $reportData['daily_collection']['visit_fees'] = (float) $row['amount'];
        } elseif ($row['payment_type'] === 'Lab Test') {
            $reportData['daily_collection']['lab_tests'] = (float) $row['amount'];
        }

        $reportData['daily_collection']['payment_count'] += (int) $row['payment_count'];
    }

    $reportData['daily_collection']['total_collected'] =
        $reportData['daily_collection']['visit_fees'] +
        $reportData['daily_collection']['lab_tests'];

    // Patient registrations.
    $result = $conn->query("SELECT COUNT(*) AS total
                            FROM patients
                            WHERE DATE(created_at) BETWEEN '{$fromSql}' AND '{$toSql}'");
    $reportData['patient_registrations']['count'] = (int) $result->fetch_assoc()['total'];

    // Doctor-wise visits and fees.
    $sql = "SELECT d.name AS doctor_name,
                   d.department,
                   COUNT(v.id) AS visit_count,
                   COALESCE(SUM(v.fee), 0) AS fees
            FROM visits v
            INNER JOIN doctors d ON d.id = v.doctor_id
            WHERE v.visit_date BETWEEN '{$fromSql}' AND '{$toSql}'
            GROUP BY d.id, d.name, d.department
            ORDER BY visit_count DESC, fees DESC";

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $reportData['doctor_wise'][] = [
            'doctor' => $row['doctor_name'],
            'department' => $row['department'],
            'visits' => (int) $row['visit_count'],
            'fees' => (float) $row['fees'],
        ];
    }

    // Lab test report.
    $sql = "SELECT lo.status,
                   lt.test_name,
                   COUNT(*) AS order_count,
                   COALESCE(SUM(lo.fee), 0) AS fees
            FROM lab_orders lo
            LEFT JOIN lab_tests lt ON lt.id = lo.test_id
            WHERE lo.order_date BETWEEN '{$fromSql}' AND '{$toSql}'
            GROUP BY lo.status, lt.test_name
            ORDER BY lt.test_name, lo.status";

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $status = strtolower((string) $row['status']);
        $count = (int) $row['order_count'];

        $reportData['lab_tests_report']['ordered'] += $count;

        if ($status === 'done') {
            $reportData['lab_tests_report']['done'] += $count;
        } elseif ($status === 'pending') {
            $reportData['lab_tests_report']['pending'] += $count;
        }

        $reportData['lab_tests_report']['by_test'][] = [
            'test' => $row['test_name'] ?: 'Unlisted test',
            'status' => $row['status'],
            'count' => $count,
            'fees' => (float) $row['fees'],
        ];
    }

    // Patient-level outstanding balances.
    // The current schema stores payments by UH ID, so these are all-time balances.
    $sql = "SELECT p.uh_id,
                   p.full_name,
                   (COALESCE(v.visit_charges, 0) + COALESCE(l.lab_charges, 0)) AS total_charges,
                   COALESCE(pay.amount_paid, 0) AS amount_paid,
                   (COALESCE(v.visit_charges, 0) + COALESCE(l.lab_charges, 0)
                    - COALESCE(pay.amount_paid, 0)) AS amount_due
            FROM patients p
            LEFT JOIN (
                SELECT uh_id, SUM(fee) AS visit_charges
                FROM visits
                GROUP BY uh_id
            ) v ON v.uh_id = p.uh_id
            LEFT JOIN (
                SELECT uh_id, SUM(fee) AS lab_charges
                FROM lab_orders
                GROUP BY uh_id
            ) l ON l.uh_id = p.uh_id
            LEFT JOIN (
                SELECT uh_id, SUM(amount) AS amount_paid
                FROM payments
                GROUP BY uh_id
            ) pay ON pay.uh_id = p.uh_id
            HAVING amount_due > 0
            ORDER BY amount_due DESC
            LIMIT 100";

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $reportData['patients_with_due'][] = [
            'uh_id' => $row['uh_id'],
            'name' => $row['full_name'],
            'total_charges' => (float) $row['total_charges'],
            'paid' => (float) $row['amount_paid'],
            'due' => (float) $row['amount_due'],
        ];
    }
} catch (Throwable $e) {
    $pageError = 'Could not load report data. Check your database connection and table columns.';
}

$totalDoctorVisits = 0;
foreach ($reportData['doctor_wise'] as $doctor) {
    $totalDoctorVisits += $doctor['visits'];
}

totalDue:
$totalDue = 0;
foreach ($reportData['patients_with_due'] as $patient) {
    $totalDue += $patient['due'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .ai-shell {
        max-width: 1380px;
        margin: 0 auto;
        padding: 28px 20px 44px;
    }

    .ai-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 22px;
    }

    .ai-page-header h2 {
        margin: 0 0 7px;
        color: #172033;
        font-size: 30px;
        line-height: 1.15;
    }

    .ai-page-header p {
        margin: 0;
        color: #64748b;
        line-height: 1.55;
    }

    .ai-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex: 0 0 auto;
        padding: 9px 13px;
        border-radius: 999px;
        background: #e8f7f3;
        color: #087f68;
        font-size: 13px;
        font-weight: 700;
    }

    .ai-layout {
        display: grid;
        grid-template-columns: minmax(300px, 0.9fr) minmax(0, 1.45fr);
        align-items: start;
        gap: 20px;
    }

    .report-sidebar,
    .chat-panel,
    .date-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
    }

    .report-sidebar {
        position: sticky;
        top: 18px;
        overflow: hidden;
    }

    .sidebar-heading {
        padding: 18px 18px 15px;
        border-bottom: 1px solid #e2e8f0;
    }

    .sidebar-heading h3 {
        margin: 0 0 5px;
        color: #172033;
        font-size: 17px;
    }

    .sidebar-heading p {
        margin: 0;
        color: #64748b;
        font-size: 13px;
        line-height: 1.45;
    }

    .date-card {
        margin: 14px;
        padding: 14px;
        background: #f8fafc;
        box-shadow: none;
    }

    .date-card-label {
        display: block;
        margin-bottom: 5px;
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .07em;
        text-transform: uppercase;
    }

    .date-card strong {
        color: #172033;
        font-size: 14px;
    }

    .sidebar-section {
        padding: 0 14px 14px;
    }

    .sidebar-section-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin: 14px 4px 8px;
        color: #334155;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .06em;
        text-transform: uppercase;
    }

    .sidebar-section-title span {
        color: #94a3b8;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0;
        text-transform: none;
    }

    .metric-list {
        display: grid;
        gap: 8px;
    }

    .metric-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 11px 12px;
        border: 1px solid #edf2f7;
        border-radius: 10px;
        background: #ffffff;
    }

    .metric-row span {
        color: #64748b;
        font-size: 13px;
        overflow-wrap: anywhere;
    }

    .metric-row strong {
        color: #172033;
        font-size: 14px;
        text-align: right;
        overflow-wrap: anywhere;
    }

    .metric-row.total {
        border-color: #bce8dc;
        background: #effbf7;
    }

    .metric-row.total strong {
        color: #087f68;
    }

    .mini-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
        font-size: 12px;
    }

    .mini-table th,
    .mini-table td {
        padding: 9px 7px;
        border-bottom: 1px solid #edf2f7;
        text-align: left;
        vertical-align: top;
        overflow-wrap: anywhere;
    }

    .mini-table th {
        color: #64748b;
        background: transparent;
        font-size: 10px;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .mini-table td {
        color: #334155;
    }

    .mini-table td:last-child,
    .mini-table th:last-child {
        text-align: right;
    }

    .empty-state {
        padding: 12px;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        color: #64748b;
        font-size: 13px;
        line-height: 1.45;
    }

    .due-list {
        display: grid;
        gap: 8px;
        max-height: 230px;
        overflow-y: auto;
    }

    .due-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 11px;
        border-radius: 10px;
        background: #fff7ed;
        border: 1px solid #fed7aa;
    }

    .due-item span {
        min-width: 0;
        color: #7c2d12;
        font-size: 12px;
        overflow-wrap: anywhere;
    }

    .due-item strong {
        color: #c2410c;
        font-size: 12px;
        white-space: normal;
        text-align: right;
    }

    .chat-panel {
        min-width: 0;
        overflow: hidden;
    }

    .chat-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 19px 20px;
        border-bottom: 1px solid #e2e8f0;
    }

    .assistant-identity {
        display: flex;
        align-items: center;
        gap: 11px;
        min-width: 0;
    }

    .assistant-avatar {
        display: grid;
        flex: 0 0 42px;
        width: 42px;
        height: 42px;
        place-items: center;
        border-radius: 13px;
        background: linear-gradient(135deg, #087f68, #20b996);
        color: #ffffff;
        font-size: 19px;
        font-weight: 800;
    }

    .assistant-identity h3 {
        margin: 0 0 3px;
        color: #172033;
        font-size: 18px;
    }

    .assistant-identity p {
        margin: 0;
        color: #64748b;
        font-size: 12px;
    }

    .online-dot {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #087f68;
        font-size: 12px;
        font-weight: 700;
        white-space: normal;
    }

    .online-dot::before {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #19b987;
        content: '';
        box-shadow: 0 0 0 4px #d9f8ed;
    }

    .chat-messages {
        display: flex;
        min-height: 440px;
        max-height: 600px;
        flex-direction: column;
        gap: 14px;
        overflow-y: auto;
        padding: 22px;
        background: #f8fafc;
    }

    .message-row {
        display: flex;
        gap: 9px;
        align-items: flex-end;
    }

    .message-row.user {
        justify-content: flex-end;
    }

    .message-bubble {
        max-width: min(720px, 84%);
        padding: 13px 15px;
        border-radius: 15px;
        line-height: 1.6;
        overflow-wrap: anywhere;
        white-space: pre-wrap;
    }

    .message-bubble.assistant {
        border: 1px solid #e2e8f0;
        border-bottom-left-radius: 4px;
        background: #ffffff;
        color: #263449;
    }

    .message-bubble.user {
        border-bottom-right-radius: 4px;
        background: #087f68;
        color: #ffffff;
    }

    .message-time {
        padding: 0 3px 3px;
        color: #94a3b8;
        font-size: 10px;
    }

    .suggestions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 15px 18px 16px;
        border-top: 1px solid #e2e8f0;
        background: #ffffff;
    }

    .suggestion-button {
        border: 1px solid #dbe4ee;
        border-radius: 999px;
        padding: 8px 11px;
        background: #ffffff;
        color: #334155;
        cursor: pointer;
        font: inherit;
        font-size: 12px;
        transition: border-color .15s ease, color .15s ease, background .15s ease;
    }

    .suggestion-button:hover {
        border-color: #087f68;
        background: #effbf7;
        color: #066957;
    }

    .composer {
        display: flex;
        align-items: flex-end;
        gap: 10px;
        padding: 15px 18px 18px;
        background: #ffffff;
    }

    .composer textarea {
        min-height: 51px;
        max-height: 160px;
        flex: 1;
        resize: vertical;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 12px;
        color: #1e293b;
        font: inherit;
        line-height: 1.45;
    }

    .composer textarea:focus,
    .date-filter input:focus {
        outline: 3px solid rgba(8, 127, 104, .14);
        border-color: #087f68;
    }

    .primary-button {
        min-height: 51px;
        border: 0;
        border-radius: 10px;
        padding: 11px 17px;
        background: #087f68;
        color: #ffffff;
        cursor: pointer;
        font: inherit;
        font-weight: 800;
        transition: background .15s ease, transform .15s ease;
    }

    .primary-button:hover {
        background: #066957;
        transform: translateY(-1px);
    }

    .primary-button:disabled {
        cursor: wait;
        opacity: .58;
        transform: none;
    }

    .date-filter {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 10px;
        margin: 0 0 20px;
        padding: 14px;
        border: 1px solid #e2e8f0;
        border-radius: 13px;
        background: #ffffff;
        box-shadow: 0 7px 20px rgba(15, 23, 42, .04);
    }

    .date-filter label {
        display: block;
        margin-bottom: 5px;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
    }

    .date-filter input {
        min-height: 40px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 8px 10px;
        color: #1e293b;
        font: inherit;
    }

    .date-filter .primary-button {
        min-height: 40px;
        padding: 9px 14px;
        font-size: 13px;
    }

    .warning-box {
        margin: 0 0 17px;
        padding: 12px 14px;
        border: 1px solid #fecdd3;
        border-radius: 10px;
        background: #fff1f2;
        color: #9f1239;
        font-size: 13px;
        line-height: 1.5;
    }

    .chat-note {
        margin: 12px 2px 0;
        color: #64748b;
        font-size: 12px;
        line-height: 1.5;
    }

    @media (max-width: 1050px) {
        .ai-layout {
            grid-template-columns: minmax(270px, .85fr) minmax(0, 1.35fr);
            gap: 14px;
        }

        .message-bubble {
            max-width: 91%;
        }
    }

    @media (max-width: 800px) {
        .ai-shell {
            padding: 22px 16px 32px;
        }

        .ai-page-header {
            flex-direction: column;
        }

        .ai-page-header h2 {
            font-size: 26px;
        }

        .ai-layout {
            grid-template-columns: 1fr;
        }

        .report-sidebar {
            position: static;
        }

        .chat-messages {
            min-height: 360px;
            max-height: 520px;
        }
    }

    @media (max-width: 560px) {
        .date-filter,
        .composer {
            align-items: stretch;
            flex-direction: column;
        }

        .date-filter > div,
        .date-filter input,
        .date-filter .primary-button,
        .composer .primary-button {
            width: 100%;
        }

        .chat-panel-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .message-bubble {
            max-width: 94%;
        }

        .suggestions {
            flex-direction: column;
            align-items: stretch;
        }

        .suggestion-button {
            width: 100%;
            text-align: left;
        }
    }
</style>

<main class="ai-shell">
    <header class="ai-page-header">
        <div>
            <h2>AI Report Assistant</h2>
            <p>Review the live report details on the left and ask questions in the assistant on the right.</p>
        </div>
        <span class="ai-status">● ChatterMate · report data only</span>
    </header>

    <?php if ($pageError !== ''): ?>
        <div class="warning-box" role="alert">
            <?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form class="date-filter" method="get" action="">
        <div>
            <label for="from">Report period from</label>
            <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div>
            <label for="to">Report period to</label>
            <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <button class="primary-button" type="submit">Update report details</button>
    </form>

    <section class="ai-layout">
        <aside class="report-sidebar" aria-label="Report details">
            <div class="sidebar-heading">
                <h3>Report details</h3>
                <p>These are the numbers supplied to the AI assistant.</p>
            </div>

            <div class="date-card">
                <span class="date-card-label">Selected period</span>
                <strong><?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>

            <section class="sidebar-section">
                <div class="sidebar-section-title">Collection <span><?php echo number_format($reportData['daily_collection']['payment_count']); ?> payments</span></div>
                <div class="metric-list">
                    <div class="metric-row">
                        <span>Visit fees</span>
                        <strong><?php echo money($reportData['daily_collection']['visit_fees']); ?></strong>
                    </div>
                    <div class="metric-row">
                        <span>Lab tests</span>
                        <strong><?php echo money($reportData['daily_collection']['lab_tests']); ?></strong>
                    </div>
                    <div class="metric-row total">
                        <span>Total collected</span>
                        <strong><?php echo money($reportData['daily_collection']['total_collected']); ?></strong>
                    </div>
                </div>
            </section>

            <section class="sidebar-section">
                <div class="sidebar-section-title">Activity</div>
                <div class="metric-list">
                    <div class="metric-row">
                        <span>New patients</span>
                        <strong><?php echo number_format($reportData['patient_registrations']['count']); ?></strong>
                    </div>
                    <div class="metric-row">
                        <span>Doctor visits</span>
                        <strong><?php echo number_format($totalDoctorVisits); ?></strong>
                    </div>
                    <div class="metric-row">
                        <span>Tests ordered</span>
                        <strong><?php echo number_format($reportData['lab_tests_report']['ordered']); ?></strong>
                    </div>
                    <div class="metric-row">
                        <span>Tests completed</span>
                        <strong><?php echo number_format($reportData['lab_tests_report']['done']); ?></strong>
                    </div>
                    <div class="metric-row">
                        <span>Tests pending</span>
                        <strong><?php echo number_format($reportData['lab_tests_report']['pending']); ?></strong>
                    </div>
                </div>
            </section>

            <section class="sidebar-section">
                <div class="sidebar-section-title">Doctor-wise visits <span>selected period</span></div>
                <?php if (empty($reportData['doctor_wise'])): ?>
                    <div class="empty-state">No doctor visits were recorded for this period.</div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th>Doctor</th>
                                    <th>Visits</th>
                                    <th>Fees</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['doctor_wise'] as $doctor): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($doctor['doctor'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo number_format($doctor['visits']); ?></td>
                                        <td><?php echo money($doctor['fees']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="sidebar-section">
                <div class="sidebar-section-title">Lab tests <span>by status</span></div>
                <?php if (empty($reportData['lab_tests_report']['by_test'])): ?>
                    <div class="empty-state">No lab orders were recorded for this period.</div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th>Test</th>
                                    <th>Status</th>
                                    <th>Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['lab_tests_report']['by_test'] as $lab): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($lab['test'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($lab['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo number_format($lab['count']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="sidebar-section">
                <div class="sidebar-section-title">Outstanding balances <span><?php echo money($totalDue); ?></span></div>
                <?php if (empty($reportData['patients_with_due'])): ?>
                    <div class="empty-state">No outstanding patient balances were found.</div>
                <?php else: ?>
                    <div class="due-list">
                        <?php foreach ($reportData['patients_with_due'] as $patient): ?>
                            <div class="due-item">
                                <span><?php echo htmlspecialchars($patient['uh_id'] . ' · ' . $patient['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <strong><?php echo money($patient['due']); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </aside>

        <section class="chat-panel" aria-label="ChatterMate report assistant">
            <div class="chat-panel-header">
                <div class="assistant-identity">
                    <div class="assistant-avatar" aria-hidden="true">✦</div>
                    <div>
                        <h3>Hania's Health Hub AI</h3>
                        <p>Ask about collections, visits, lab tests, patients, or dues.</p>
                    </div>
                </div>
                <span class="online-dot">Ready to answer</span>
            </div>

            <div class="chat-messages" id="messages" aria-live="polite">
                <div class="message-row">
                    <div class="assistant-avatar" aria-hidden="true" style="width:30px;height:30px;flex-basis:30px;border-radius:9px;font-size:13px;">✦</div>
                    <div class="message-bubble assistant">Hello! I can answer questions using the report details shown on the left. Try asking “How much did we collect?” or “Which doctor had the most visits?”</div>
                    <span class="message-time">Now</span>
                </div>
            </div>

            <div class="suggestions" aria-label="Suggested questions">
                <button class="suggestion-button" type="button" data-question="How much did we collect in this period, split by visit fees and lab tests?">Collection total</button>
                <button class="suggestion-button" type="button" data-question="Which doctor had the most visits in this period?">Top doctor</button>
                <button class="suggestion-button" type="button" data-question="How many lab tests are pending and how many are done?">Lab status</button>
                <button class="suggestion-button" type="button" data-question="Which patients have an outstanding balance?">Outstanding balances</button>
            </div>

            <form class="composer" id="chatForm">
                <textarea id="question" name="question" placeholder="Type your report question…" aria-label="Your report question" required></textarea>
                <button class="primary-button" id="sendButton" type="submit">Send question</button>
            </form>
        </section>
    </section>

    <p class="chat-note">The report details are calculated from your MySQL database. Patient balances are all-time patient-level estimates because payments are recorded by UH ID, not attached to a specific visit or lab order.</p>
</main>

<script>
    const reportData = <?php echo json_encode($reportData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    const chatForm = document.getElementById('chatForm');
    const questionInput = document.getElementById('question');
    const sendButton = document.getElementById('sendButton');
    const messages = document.getElementById('messages');

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
    }

    function safeAnswerHtml(value) {
        return escapeHtml(value).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    }

    function addMessage(text, role) {
        const row = document.createElement('div');
        row.className = 'message-row ' + role;

        if (role === 'assistant') {
            const avatar = document.createElement('div');
            avatar.className = 'assistant-avatar';
            avatar.style.width = '30px';
            avatar.style.height = '30px';
            avatar.style.flexBasis = '30px';
            avatar.style.borderRadius = '9px';
            avatar.style.fontSize = '13px';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = '✦';
            row.appendChild(avatar);
        }

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble ' + role;
        bubble.textContent = text;
        row.appendChild(bubble);

        const time = document.createElement('span');
        time.className = 'message-time';
        time.textContent = 'Now';
        row.appendChild(time);

        messages.appendChild(row);
        messages.scrollTop = messages.scrollHeight;
        return bubble;
    }

    async function askQuestion(question) {
        const cleanQuestion = question.trim();
        if (!cleanQuestion) return;

        addMessage(cleanQuestion, 'user');
        questionInput.value = '';
        sendButton.disabled = true;
        sendButton.textContent = 'Thinking…';
        const loadingBubble = addMessage('Checking the report details…', 'assistant');

        try {
            const response = await fetch('http://localhost:3000/ask', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ question: cleanQuestion, data: reportData })
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.error || 'The local AI server returned an error.');
            }

            if (typeof result.answer !== 'string' || result.answer.trim() === '') {
                throw new Error('The AI server returned no answer.');
            }

            loadingBubble.innerHTML = safeAnswerHtml(result.answer);
        } catch (error) {
            loadingBubble.textContent = 'Could not reach the AI server: ' + error.message + ' Make sure server.js is running at http://localhost:3000.';
        } finally {
            sendButton.disabled = false;
            sendButton.textContent = 'Send question';
            questionInput.focus();
            messages.scrollTop = messages.scrollHeight;
        }
    }

    chatForm.addEventListener('submit', event => {
        event.preventDefault();
        askQuestion(questionInput.value);
    });

    questionInput.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            chatForm.requestSubmit();
        }
    });

    document.querySelectorAll('.suggestion-button').forEach(button => {
        button.addEventListener('click', () => {
            askQuestion(button.dataset.question || '');
        });
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>