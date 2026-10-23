<?php
// pages/reports.php
// Reports use the existing MySQL tables and columns; no schema changes required.
require_once __DIR__ . '/../includes/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function validDate(string $value, string $fallback): string
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
}

$today = date('Y-m-d');
$fromDate = validDate((string)($_GET['from'] ?? $today), $today);
$toDate = validDate((string)($_GET['to'] ?? $today), $today);
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$allowedReports = ['collection', 'patients', 'doctors', 'labs'];
$report = (string)($_GET['report'] ?? 'collection');
if (!in_array($report, $allowedReports, true)) {
    $report = 'collection';
}

$reportTitles = [
    'collection' => 'Daily Collection',
    'patients' => 'Patient Registration Report',
    'doctors' => 'Doctor-wise Report',
    'labs' => 'Laboratory Test Report',
];

$errorMessage = '';
$rows = [];
$summary = [];

try {
    if ($report === 'collection') {
        $stmt = $conn->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN payment_type = 'Visit Fee' THEN amount ELSE 0 END), 0) AS visit_collected,
                COALESCE(SUM(CASE WHEN payment_type = 'Lab Test' THEN amount ELSE 0 END), 0) AS lab_collected,
                COALESCE(SUM(amount), 0) AS total_collected,
                COUNT(*) AS payment_count
             FROM payments
             WHERE payment_date BETWEEN ? AND ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare collection report: ' . $conn->error);
        }
        $stmt->bind_param('ss', $fromDate, $toDate);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not load collection report: ' . $stmt->error);
        }
        $summary = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        // Aggregate each patient's lifetime balance separately so one patient's
        // overpayment cannot cancel another patient's outstanding balance.
        $dueResult = $conn->query(
            "SELECT COALESCE(SUM(GREATEST(
                COALESCE(v.visit_total, 0) + COALESCE(l.lab_total, 0) - COALESCE(p.paid_total, 0), 0
            )), 0) AS total_due
            FROM patients patient
            LEFT JOIN (
                SELECT uh_id, SUM(fee) AS visit_total FROM visits GROUP BY uh_id
            ) v ON v.uh_id = patient.uh_id
            LEFT JOIN (
                SELECT uh_id, SUM(fee) AS lab_total FROM lab_orders GROUP BY uh_id
            ) l ON l.uh_id = patient.uh_id
            LEFT JOIN (
                SELECT uh_id, SUM(amount) AS paid_total FROM payments GROUP BY uh_id
            ) p ON p.uh_id = patient.uh_id"
        );
        if (!$dueResult) {
            throw new RuntimeException('Could not calculate outstanding balance: ' . $conn->error);
        }
        $dueRow = $dueResult->fetch_assoc() ?: [];
        $summary['total_due'] = $dueRow['total_due'] ?? 0;

    } elseif ($report === 'patients') {
        $stmt = $conn->prepare(
            "SELECT uh_id, full_name, sex, phone, address, created_at
             FROM patients
             WHERE DATE(created_at) BETWEEN ? AND ?
             ORDER BY created_at DESC, id DESC"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare patient report: ' . $conn->error);
        }
        $stmt->bind_param('ss', $fromDate, $toDate);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not load patient report: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        $summary['patient_count'] = count($rows);

    } elseif ($report === 'doctors') {
        $stmt = $conn->prepare(
            "SELECT d.id, d.name, d.department,
                    COUNT(v.id) AS visit_count,
                    COALESCE(SUM(v.fee), 0) AS total_fees
             FROM doctors d
             LEFT JOIN visits v
               ON v.doctor_id = d.id
              AND v.visit_date BETWEEN ? AND ?
             GROUP BY d.id, d.name, d.department
             ORDER BY visit_count DESC, d.name ASC"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare doctor report: ' . $conn->error);
        }
        $stmt->bind_param('ss', $fromDate, $toDate);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not load doctor report: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        $totalVisits = 0;
        $totalFees = 0.0;
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
            $totalVisits += (int)$row['visit_count'];
            $totalFees += (float)$row['total_fees'];
        }
        $stmt->close();
        $summary = ['visit_count' => $totalVisits, 'total_fees' => $totalFees];

    } else { // labs
        $stmt = $conn->prepare(
            "SELECT t.id, t.test_name,
                    COUNT(o.id) AS ordered_count,
                    COALESCE(SUM(CASE WHEN o.status = 'Done' THEN 1 ELSE 0 END), 0) AS done_count,
                    COALESCE(SUM(CASE WHEN o.status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_count,
                    COALESCE(SUM(o.fee), 0) AS total_revenue
             FROM lab_tests t
             LEFT JOIN lab_orders o
               ON o.test_id = t.id
              AND o.order_date BETWEEN ? AND ?
             GROUP BY t.id, t.test_name
             ORDER BY t.test_name ASC"
        );
        if (!$stmt) {
            throw new RuntimeException('Could not prepare laboratory report: ' . $conn->error);
        }
        $stmt->bind_param('ss', $fromDate, $toDate);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not load laboratory report: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        $ordered = $done = $pending = 0;
        $revenue = 0.0;
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
            $ordered += (int)$row['ordered_count'];
            $done += (int)$row['done_count'];
            $pending += (int)$row['pending_count'];
            $revenue += (float)$row['total_revenue'];
        }
        $stmt->close();
        $summary = [
            'ordered_count' => $ordered,
            'done_count' => $done,
            'pending_count' => $pending,
            'total_revenue' => $revenue,
        ];
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

function money($amount): string
{
    return 'Tk ' . number_format((float)$amount, 2);
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .reports-page { max-width: 1200px; margin: 0 auto; padding: 34px 20px 48px; }
    .reports-heading { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin-bottom: 22px; }
    .reports-heading h2 { color: #172554; font-size: 28px; margin: 0 0 6px; }
    .reports-heading p { color: #64748b; margin: 0; }
    .report-panel, .report-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 8px 24px rgba(15, 23, 42, .05); }
    .report-panel { padding: 20px; margin-bottom: 20px; }
    .filter-form { display: flex; flex-wrap: wrap; align-items: end; gap: 14px; }
    .filter-field { display: flex; flex-direction: column; gap: 6px; min-width: 160px; flex: 1; }
    .filter-field label { font-size: 13px; font-weight: 700; color: #334155; }
    .filter-field select, .filter-field input { font: inherit; width: 100%; min-height: 42px; padding: 9px 11px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; color: #0f172a; }
    .report-button { min-height: 42px; border: 0; border-radius: 7px; background: #0f766e; color: #fff; padding: 0 18px; font-weight: 700; cursor: pointer; }
    .report-button:hover { background: #115e59; }
    .print-button { background: #1e293b; }
    .print-button:hover { background: #0f172a; }
    .report-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin: 18px 0; }
    .report-card { padding: 18px; }
    .report-card .label { color: #64748b; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
    .report-card .value { color: #0f172a; font-size: 23px; font-weight: 800; overflow-wrap: anywhere; }
    .report-card.accent { border-top: 3px solid #0f766e; }
    .table-wrap { width: 100%; overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
    table.report-table { width: 100%; min-width: 620px; border-collapse: collapse; margin: 0; }
    .report-table th, .report-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; white-space: normal; overflow-wrap: break-word; vertical-align: top; }
    .report-table th { background: #f1f5f9; color: #334155; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
    .report-table tbody tr:hover { background: #f8fafc; }
    .report-table tfoot td { background: #f8fafc; font-weight: 800; border-top: 2px solid #cbd5e1; }
    .empty-state { background: #fff; border: 1px dashed #cbd5e1; border-radius: 10px; color: #64748b; padding: 26px; text-align: center; }
    .notice { border-radius: 8px; padding: 13px 15px; margin: 14px 0; color: #475569; background: #f1f5f9; border-left: 4px solid #0f766e; }
    .error-box { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; overflow-wrap: anywhere; }
    .summary-actions { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin: 18px 0 12px; }
    .summary-actions h3 { margin: 0; color: #172554; font-size: 18px; }
    .period-label { color: #64748b; font-size: 14px; }
    @media (max-width: 768px) {
        .reports-page, body { padding: 20px !important; max-width: 100% !important; }
        .reports-page { padding: 22px 14px 34px !important; }
        .reports-heading, .filter-form { flex-direction: column !important; align-items: stretch; }
        .reports-heading h2 { font-size: 23px; }
        .filter-field { min-width: 0; }
        .report-card, .report-panel { padding: 16px !important; }
        table { font-size: 11pt !important; }
    }
    @media print {
        .site-header, .site-footer, .filter-panel, .summary-actions .print-button, .site-nav { display: none !important; }
        body { background: #fff !important; padding: 0 !important; }
        .reports-page { max-width: none; padding: 0 !important; }
        .report-panel, .report-card, .table-wrap { box-shadow: none; border-color: #cbd5e1; }
        .table-wrap { overflow: visible; }
        table.report-table { min-width: 0; }
    }
</style>

<main class="reports-page">
    <div class="reports-heading">
        <div>
            <h2>Reports</h2>
            <p>Review hospital activity and totals from the selected date range.</p>
        </div>
        <button type="button" class="report-button print-button" onclick="window.print()">Print Report</button>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="error-box"><strong>Report could not be loaded.</strong><br><?php echo h($errorMessage); ?></div>
    <?php endif; ?>

    <section class="report-panel filter-panel" aria-label="Report filters">
        <form class="filter-form" method="get" action="reports.php">
            <div class="filter-field">
                <label for="report">Report type</label>
                <select id="report" name="report">
                    <?php foreach ($reportTitles as $key => $title): ?>
                        <option value="<?php echo h($key); ?>" <?php echo $report === $key ? 'selected' : ''; ?>><?php echo h($title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="from">From date</label>
                <input type="date" id="from" name="from" value="<?php echo h($fromDate); ?>" required>
            </div>
            <div class="filter-field">
                <label for="to">To date</label>
                <input type="date" id="to" name="to" value="<?php echo h($toDate); ?>" required>
            </div>
            <button type="submit" class="report-button">Run Report</button>
        </form>
    </section>

    <div class="summary-actions">
        <div>
            <h3><?php echo h($reportTitles[$report]); ?></h3>
            <div class="period-label">Period: <?php echo h($fromDate); ?> to <?php echo h($toDate); ?></div>
        </div>
    </div>

    <?php if ($errorMessage === '' && $report === 'collection'): ?>
        <section class="report-cards" aria-label="Collection totals">
            <div class="report-card accent"><div class="label">Visit payments received</div><div class="value"><?php echo money($summary['visit_collected'] ?? 0); ?></div></div>
            <div class="report-card accent"><div class="label">Lab payments received</div><div class="value"><?php echo money($summary['lab_collected'] ?? 0); ?></div></div>
            <div class="report-card accent"><div class="label">Total received</div><div class="value"><?php echo money($summary['total_collected'] ?? 0); ?></div></div>
            <div class="report-card"><div class="label">Payments recorded</div><div class="value"><?php echo number_format((int)($summary['payment_count'] ?? 0)); ?></div></div>
            <div class="report-card"><div class="label">Outstanding due · all time</div><div class="value"><?php echo money($summary['total_due'] ?? 0); ?></div></div>
        </section>
        <div class="notice">Outstanding due is calculated per patient as recorded visit and lab charges minus all recorded payments. Payments are not linked to individual visits or lab orders in the current database, so this is an aggregate patient balance.</div>
        <div class="table-wrap">
            <table class="report-table">
                <thead><tr><th>Collection category</th><th>Amount received</th></tr></thead>
                <tbody>
                    <tr><td>Doctor visits</td><td><?php echo money($summary['visit_collected'] ?? 0); ?></td></tr>
                    <tr><td>Laboratory tests</td><td><?php echo money($summary['lab_collected'] ?? 0); ?></td></tr>
                </tbody>
                <tfoot><tr><td>Total received in selected period</td><td><?php echo money($summary['total_collected'] ?? 0); ?></td></tr></tfoot>
            </table>
        </div>

    <?php elseif ($errorMessage === '' && $report === 'patients'): ?>
        <section class="report-cards"><div class="report-card accent"><div class="label">Patients registered</div><div class="value"><?php echo number_format((int)($summary['patient_count'] ?? 0)); ?></div></div></section>
        <?php if ($rows): ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th>UH ID</th><th>Full name</th><th>Sex</th><th>Phone</th><th>Address</th><th>Registered on</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?>
                    <tr><td><?php echo h($row['uh_id']); ?></td><td><?php echo h($row['full_name']); ?></td><td><?php echo h($row['sex']); ?></td><td><?php echo h($row['phone']); ?></td><td><?php echo h($row['address']); ?></td><td><?php echo h($row['created_at']); ?></td></tr>
                <?php endforeach; ?></tbody>
                <tfoot><tr><td colspan="5">Total patients registered in selected period</td><td><?php echo number_format((int)$summary['patient_count']); ?></td></tr></tfoot>
            </table></div>
        <?php else: ?><div class="empty-state">No patients were registered in this date range.</div><?php endif; ?>

    <?php elseif ($errorMessage === '' && $report === 'doctors'): ?>
        <section class="report-cards">
            <div class="report-card accent"><div class="label">Total visits</div><div class="value"><?php echo number_format((int)($summary['visit_count'] ?? 0)); ?></div></div>
            <div class="report-card accent"><div class="label">Total consultation fees</div><div class="value"><?php echo money($summary['total_fees'] ?? 0); ?></div></div>
        </section>
        <?php if ($rows): ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th>Doctor</th><th>Department</th><th>Visits</th><th>Fees</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?>
                    <tr><td><?php echo h($row['name']); ?></td><td><?php echo h($row['department']); ?></td><td><?php echo number_format((int)$row['visit_count']); ?></td><td><?php echo money($row['total_fees']); ?></td></tr>
                <?php endforeach; ?></tbody>
                <tfoot><tr><td colspan="2">Total</td><td><?php echo number_format((int)$summary['visit_count']); ?></td><td><?php echo money($summary['total_fees']); ?></td></tr></tfoot>
            </table></div>
        <?php else: ?><div class="empty-state">No doctors or visits were found.</div><?php endif; ?>

    <?php elseif ($errorMessage === '' && $report === 'labs'): ?>
        <section class="report-cards">
            <div class="report-card accent"><div class="label">Tests ordered</div><div class="value"><?php echo number_format((int)($summary['ordered_count'] ?? 0)); ?></div></div>
            <div class="report-card"><div class="label">Completed</div><div class="value"><?php echo number_format((int)($summary['done_count'] ?? 0)); ?></div></div>
            <div class="report-card"><div class="label">Pending</div><div class="value"><?php echo number_format((int)($summary['pending_count'] ?? 0)); ?></div></div>
            <div class="report-card accent"><div class="label">Lab order revenue</div><div class="value"><?php echo money($summary['total_revenue'] ?? 0); ?></div></div>
        </section>
        <?php if ($rows): ?>
            <div class="table-wrap"><table class="report-table">
                <thead><tr><th>Test</th><th>Ordered</th><th>Completed</th><th>Pending</th><th>Revenue</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?>
                    <tr><td><?php echo h($row['test_name']); ?></td><td><?php echo number_format((int)$row['ordered_count']); ?></td><td><?php echo number_format((int)$row['done_count']); ?></td><td><?php echo number_format((int)$row['pending_count']); ?></td><td><?php echo money($row['total_revenue']); ?></td></tr>
                <?php endforeach; ?></tbody>
                <tfoot><tr><td>Total</td><td><?php echo number_format((int)$summary['ordered_count']); ?></td><td><?php echo number_format((int)$summary['done_count']); ?></td><td><?php echo number_format((int)$summary['pending_count']); ?></td><td><?php echo money($summary['total_revenue']); ?></td></tr></tfoot>
            </table></div>
        <?php else: ?><div class="empty-state">No laboratory tests were found in this date range.</div><?php endif; ?>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>