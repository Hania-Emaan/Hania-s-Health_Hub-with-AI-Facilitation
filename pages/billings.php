<?php
// pages/billings.php
// Billing page for the existing hanias_health_hub database schema.

require_once __DIR__ . '/../includes/db.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getScalarTotal(mysqli $conn, string $sql, string $type, int $patientId): float
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare a billing query: ' . $conn->error);
    }

    $stmt->bind_param($type, $patientId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not calculate billing totals: ' . $error);
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return (float) ($row['total'] ?? 0);
}

function getPatientTotals(mysqli $conn, int $patientId, string $uhId): array
{
    $visitCharges = getScalarTotal(
        $conn,
        'SELECT COALESCE(SUM(fee), 0) AS total FROM visits WHERE patient_id = ?',
        'i',
        $patientId
    );

    $labCharges = getScalarTotal(
        $conn,
        'SELECT COALESCE(SUM(fee), 0) AS total FROM lab_orders WHERE patient_id = ?',
        'i',
        $patientId
    );

    $visitPayments = 0.0;
    $labPayments = 0.0;
    $stmt = $conn->prepare(
        'SELECT payment_type, COALESCE(SUM(amount), 0) AS total FROM payments WHERE uh_id = ? GROUP BY payment_type'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not prepare the payment totals query: ' . $conn->error);
    }

    $stmt->bind_param('s', $uhId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not calculate payment totals: ' . $error);
    }

    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($row['payment_type'] === 'Visit Fee') {
            $visitPayments = (float) $row['total'];
        } elseif ($row['payment_type'] === 'Lab Test') {
            $labPayments = (float) $row['total'];
        }
    }
    $stmt->close();

    return [
        'visit_charges' => $visitCharges,
        'lab_charges' => $labCharges,
        'visit_payments' => $visitPayments,
        'lab_payments' => $labPayments,
        'total_charges' => $visitCharges + $labCharges,
        'total_paid' => $visitPayments + $labPayments,
        'visit_due' => max(0, $visitCharges - $visitPayments),
        'lab_due' => max(0, $labCharges - $labPayments),
        'total_due' => max(0, ($visitCharges + $labCharges) - ($visitPayments + $labPayments)),
    ];
}

$errors = [];
$selectedPatientId = 0;
$selectedPatient = null;
$totals = null;
$successMessage = isset($_GET['saved']) ? 'Payment recorded successfully.' : '';

// Read the patient selection from either the form or the URL.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedPatientId = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT) ?: 0;
} else {
    $selectedPatientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT) ?: 0;
}

// Load patients for the patient selector.
$patients = [];
$patientQuery = $conn->query('SELECT id, uh_id, full_name FROM patients ORDER BY full_name');
if ($patientQuery) {
    while ($row = $patientQuery->fetch_assoc()) {
        $patients[] = $row;
    }
} else {
    $errors[] = 'Could not load patients: ' . $conn->error;
}

// Load the selected patient, when one is selected.
if ($selectedPatientId > 0) {
    $stmt = $conn->prepare('SELECT id, uh_id, full_name, phone FROM patients WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $selectedPatientId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $selectedPatient = $result->fetch_assoc();
        } else {
            $errors[] = 'Could not load the selected patient: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $errors[] = 'Could not prepare the patient lookup: ' . $conn->error;
    }

    if (!$selectedPatient && !$errors) {
        $errors[] = 'The selected patient was not found.';
    }
}

// Record a payment. This schema associates payments with a patient UH ID and category,
// but not with an individual visit or lab order.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedPatient) {
    $amountInput = trim((string) ($_POST['amount'] ?? ''));
    $amount = filter_var($amountInput, FILTER_VALIDATE_FLOAT);
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
    $paymentType = trim((string) ($_POST['payment_type'] ?? ''));
    $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));

    $allowedMethods = ['Cash', 'Card', 'bKash'];
    $allowedTypes = ['Visit Fee', 'Lab Test'];
    $dateObject = DateTime::createFromFormat('!Y-m-d', $paymentDate);
    $validDate = $dateObject && $dateObject->format('Y-m-d') === $paymentDate;

    if ($amount === false || $amount <= 0) {
        $errors[] = 'Enter a payment amount greater than zero.';
    }
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        $errors[] = 'Choose a valid payment method.';
    }
    if (!in_array($paymentType, $allowedTypes, true)) {
        $errors[] = 'Choose whether this payment is for a visit fee or a lab test.';
    }
    if (!$validDate) {
        $errors[] = 'Enter a valid payment date.';
    }

    if (!$errors) {
        try {
            $totals = getPatientTotals($conn, (int) $selectedPatient['id'], (string) $selectedPatient['uh_id']);
            $categoryDue = $paymentType === 'Visit Fee' ? $totals['visit_due'] : $totals['lab_due'];

            if ($amount > $categoryDue + 0.00001) {
                $errors[] = 'This amount is higher than the remaining due for the selected payment category (' . number_format($categoryDue, 2) . ').';
            } else {
                $insert = $conn->prepare(
                    'INSERT INTO payments (uh_id, amount, payment_method, payment_type, payment_date) VALUES (?, ?, ?, ?, ?)'
                );
                if (!$insert) {
                    throw new RuntimeException('Could not prepare the payment insert: ' . $conn->error);
                }

                $uhId = (string) $selectedPatient['uh_id'];
                $insert->bind_param('sdsss', $uhId, $amount, $paymentMethod, $paymentType, $paymentDate);
                if (!$insert->execute()) {
                    $insertError = $insert->error;
                    $insert->close();
                    throw new RuntimeException('Could not save the payment: ' . $insertError);
                }
                $insert->close();

                header('Location: billings.php?patient_id=' . (int) $selectedPatient['id'] . '&saved=1');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Calculate the selected patient's balance for display.
if ($selectedPatient) {
    try {
        $totals = getPatientTotals($conn, (int) $selectedPatient['id'], (string) $selectedPatient['uh_id']);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$visitRows = [];
$labRows = [];
$paymentRows = [];

if ($selectedPatient) {
    $visitStmt = $conn->prepare(
        'SELECT v.id, v.visit_date, v.complaint, v.fee, d.name AS doctor_name
         FROM visits v
         LEFT JOIN doctors d ON d.id = v.doctor_id
         WHERE v.patient_id = ?
         ORDER BY v.visit_date DESC, v.id DESC'
    );
    if ($visitStmt) {
        $visitStmt->bind_param('i', $selectedPatient['id']);
        if ($visitStmt->execute()) {
            $result = $visitStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $visitRows[] = $row;
            }
        } else {
            $errors[] = 'Could not load visit charges: ' . $visitStmt->error;
        }
        $visitStmt->close();
    } else {
        $errors[] = 'Could not prepare the visit list: ' . $conn->error;
    }

    $labStmt = $conn->prepare(
        'SELECT lo.id, lo.order_date, lo.fee, lo.status, lt.test_name
         FROM lab_orders lo
         LEFT JOIN lab_tests lt ON lt.id = lo.test_id
         WHERE lo.patient_id = ?
         ORDER BY lo.order_date DESC, lo.id DESC'
    );
    if ($labStmt) {
        $labStmt->bind_param('i', $selectedPatient['id']);
        if ($labStmt->execute()) {
            $result = $labStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $labRows[] = $row;
            }
        } else {
            $errors[] = 'Could not load lab charges: ' . $labStmt->error;
        }
        $labStmt->close();
    } else {
        $errors[] = 'Could not prepare the lab list: ' . $conn->error;
    }

    $paymentStmt = $conn->prepare(
        'SELECT id, amount, payment_method, payment_type, payment_date
         FROM payments WHERE uh_id = ? ORDER BY payment_date DESC, id DESC'
    );
    if ($paymentStmt) {
        $paymentStmt->bind_param('s', $selectedPatient['uh_id']);
        if ($paymentStmt->execute()) {
            $result = $paymentStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $paymentRows[] = $row;
            }
        } else {
            $errors[] = 'Could not load payment history: ' . $paymentStmt->error;
        }
        $paymentStmt->close();
    } else {
        $errors[] = 'Could not prepare the payment history: ' . $conn->error;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .billing-page { max-width: 1200px; margin: 0 auto; padding: 32px 20px 48px; }
    .billing-page h2 { margin: 0 0 8px; color: #1a202c; }
    .billing-intro { margin: 0 0 24px; color: #64748b; }
    .billing-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px; margin: 18px 0; box-shadow: 0 4px 14px rgba(15, 23, 42, .05); }
    .billing-card h3 { margin: 0 0 16px; color: #1e293b; }
    .billing-form-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; align-items: end; }
    .billing-field label { display: block; margin: 0 0 6px; font-weight: 600; color: #334155; }
    .billing-field input, .billing-field select { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; font: inherit; }
    .billing-button { display: inline-block; border: 0; border-radius: 7px; padding: 11px 16px; background: #2563eb; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
    .billing-button:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .billing-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 18px; }
    .billing-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px; padding: 16px; }
    .billing-stat span { display: block; color: #64748b; font-size: .92rem; }
    .billing-stat strong { display: block; margin-top: 6px; font-size: 1.4rem; color: #0f172a; }
    .billing-stat.due strong { color: #b42318; }
    .billing-table-wrap { overflow-x: auto; }
    .billing-page table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .billing-page th, .billing-page td { padding: 11px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; white-space: normal; overflow-wrap: anywhere; }
    .billing-page th { background: #f1f5f9; color: #334155; font-size: .86rem; text-transform: uppercase; }
    .billing-page tr:hover td { background: #f8fafc; }
    .billing-alert { padding: 12px 14px; border-radius: 8px; margin: 12px 0; }
    .billing-alert.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .billing-alert.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .billing-muted { color: #64748b; }
    .billing-section-heading { margin-top: 26px !important; }
    @media (max-width: 768px) {
        .billing-page { padding: 20px 16px 32px; }
        .billing-form-grid, .billing-summary { grid-template-columns: 1fr; }
        .billing-card { padding: 16px; }
    }
</style>

<main class="billing-page">
    <h2>Billing &amp; Payments</h2>
    <p class="billing-intro">Review patient charges, record payments, and check the remaining balance.</p>

    <?php if ($successMessage): ?>
        <div class="billing-alert success"><?= h($successMessage) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="billing-alert error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <section class="billing-card">
        <h3>Select patient</h3>
        <form method="get" action="billings.php" class="billing-form-grid">
            <div class="billing-field" style="grid-column: span 3;">
                <label for="patient_id">Patient</label>
                <select name="patient_id" id="patient_id" required>
                    <option value="">-- Select Patient --</option>
                    <?php foreach ($patients as $patient): ?>
                        <option value="<?= (int) $patient['id'] ?>" <?= $selectedPatientId === (int) $patient['id'] ? 'selected' : '' ?>>
                            <?= h($patient['uh_id']) ?> — <?= h($patient['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="billing-button">View billing</button>
        </form>
    </section>

    <?php if ($selectedPatient && $totals): ?>
        <section class="billing-card">
            <h3><?= h($selectedPatient['full_name']) ?> <span class="billing-muted">· <?= h($selectedPatient['uh_id']) ?></span></h3>
            <div class="billing-summary">
                <div class="billing-stat"><span>Total charges</span><strong>Tk <?= number_format($totals['total_charges'], 2) ?></strong></div>
                <div class="billing-stat"><span>Total paid</span><strong>Tk <?= number_format($totals['total_paid'], 2) ?></strong></div>
                <div class="billing-stat due"><span>Total due</span><strong>Tk <?= number_format($totals['total_due'], 2) ?></strong></div>
            </div>
            <div class="billing-summary">
                <div class="billing-stat"><span>Visit charges · paid · due</span><strong>Tk <?= number_format($totals['visit_charges'], 2) ?></strong><span>Paid Tk <?= number_format($totals['visit_payments'], 2) ?> · Due Tk <?= number_format($totals['visit_due'], 2) ?></span></div>
                <div class="billing-stat"><span>Lab charges · paid · due</span><strong>Tk <?= number_format($totals['lab_charges'], 2) ?></strong><span>Paid Tk <?= number_format($totals['lab_payments'], 2) ?> · Due Tk <?= number_format($totals['lab_due'], 2) ?></span></div>
                <div class="billing-stat"><span>Patient contact</span><strong style="font-size:1.05rem;"><?= h($selectedPatient['phone'] ?? '—') ?></strong></div>
            </div>
        </section>

        <section class="billing-card">
            <h3>Record a payment</h3>
            <?php if ($totals['total_due'] <= 0): ?>
                <p class="billing-muted">This patient has no outstanding balance.</p>
            <?php else: ?>
                <form method="post" action="billings.php" class="billing-form-grid">
                    <input type="hidden" name="patient_id" value="<?= (int) $selectedPatient['id'] ?>">
                    <div class="billing-field">
                        <label for="payment_type">Payment for</label>
                        <select name="payment_type" id="payment_type" required>
                            <option value="">-- Select category --</option>
                            <option value="Visit Fee">Visit Fee (due Tk <?= number_format($totals['visit_due'], 2) ?>)</option>
                            <option value="Lab Test">Lab Test (due Tk <?= number_format($totals['lab_due'], 2) ?>)</option>
                        </select>
                    </div>
                    <div class="billing-field">
                        <label for="amount">Amount (Tk)</label>
                        <input type="number" name="amount" id="amount" min="0.01" step="0.01" max="<?= h(number_format($totals['total_due'], 2, '.', '')) ?>" required>
                    </div>
                    <div class="billing-field">
                        <label for="payment_method">Payment method</label>
                        <select name="payment_method" id="payment_method" required>
                            <option value="">-- Select method --</option>
                            <option value="Cash">Cash</option>
                            <option value="Card">Card</option>
                            <option value="bKash">bKash</option>
                        </select>
                    </div>
                    <div class="billing-field">
                        <label for="payment_date">Payment date</label>
                        <input type="date" name="payment_date" id="payment_date" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <button type="submit" class="billing-button">Save payment</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="billing-card">
            <h3>Doctor visits</h3>
            <div class="billing-table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Doctor</th><th>Complaint</th><th>Charge</th></tr></thead>
                    <tbody>
                    <?php if (!$visitRows): ?>
                        <tr><td colspan="4" class="billing-muted">No visit charges recorded.</td></tr>
                    <?php else: foreach ($visitRows as $row): ?>
                        <tr>
                            <td><?= h($row['visit_date']) ?></td>
                            <td><?= h($row['doctor_name'] ?? '—') ?></td>
                            <td><?= h($row['complaint'] ?? '—') ?></td>
                            <td>Tk <?= number_format((float) $row['fee'], 2) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <h3 class="billing-section-heading">Laboratory orders</h3>
            <div class="billing-table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Test</th><th>Status</th><th>Charge</th></tr></thead>
                    <tbody>
                    <?php if (!$labRows): ?>
                        <tr><td colspan="4" class="billing-muted">No laboratory charges recorded.</td></tr>
                    <?php else: foreach ($labRows as $row): ?>
                        <tr>
                            <td><?= h($row['order_date']) ?></td>
                            <td><?= h($row['test_name'] ?? '—') ?></td>
                            <td><?= h($row['status']) ?></td>
                            <td>Tk <?= number_format((float) $row['fee'], 2) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <h3 class="billing-section-heading">Payment history</h3>
            <div class="billing-table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Category</th><th>Method</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php if (!$paymentRows): ?>
                        <tr><td colspan="4" class="billing-muted">No payments recorded.</td></tr>
                    <?php else: foreach ($paymentRows as $row): ?>
                        <tr>
                            <td><?= h($row['payment_date']) ?></td>
                            <td><?= h($row['payment_type']) ?></td>
                            <td><?= h($row['payment_method']) ?></td>
                            <td>Tk <?= number_format((float) $row['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>