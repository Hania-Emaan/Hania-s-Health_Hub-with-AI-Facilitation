<?php
// pages/lab_tests.php
// Uses the existing project connection: includes/db.php defines $conn.
require_once __DIR__ . '/../includes/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$patients = [];
$doctors = [];
$testCatalogue = [];
$orders = [];
$error = '';
$success = '';

// Add a lab order or update its status/result.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_order') {
            $patientId = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
            $doctorId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
            $testId = filter_input(INPUT_POST, 'test_id', FILTER_VALIDATE_INT);
            $orderInput = trim($_POST['order_date'] ?? '');

            if (!$patientId || !$doctorId || !$testId || $orderInput === '') {
                throw new RuntimeException('Choose a patient, doctor, test, and order date.');
            }

            $timestamp = strtotime($orderInput);
            if ($timestamp === false) {
                throw new RuntimeException('Enter a valid order date and time.');
            }
            $orderDate = date('Y-m-d H:i:s', $timestamp);

            // Read the UH ID and current catalogue price from the database;
            // do not trust a price or UH ID submitted by the browser.
            $patientStmt = $conn->prepare('SELECT uh_id FROM patients WHERE id = ?');
            if (!$patientStmt) {
                throw new RuntimeException('Could not prepare the patient lookup.');
            }
            $patientStmt->bind_param('i', $patientId);
            $patientStmt->execute();
            $patient = $patientStmt->get_result()->fetch_assoc();
            $patientStmt->close();

            $testStmt = $conn->prepare('SELECT price FROM lab_tests WHERE id = ?');
            if (!$testStmt) {
                throw new RuntimeException('Could not prepare the test lookup.');
            }
            $testStmt->bind_param('i', $testId);
            $testStmt->execute();
            $test = $testStmt->get_result()->fetch_assoc();
            $testStmt->close();

            if (!$patient) {
                throw new RuntimeException('The selected patient was not found.');
            }
            if (!$test) {
                throw new RuntimeException('The selected test was not found.');
            }

            $uhId = $patient['uh_id'];
            $fee = (float)$test['price'];
            $status = 'Pending';
            $resultText = null;

            $insert = $conn->prepare(
                'INSERT INTO lab_orders (patient_id, uh_id, doctor_id, test_id, fee, status, result, order_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Could not prepare the lab order. Check that lab_orders has patient_id, uh_id, doctor_id, test_id, fee, status, result, and order_date columns.');
            }
            $insert->bind_param('isiidsss', $patientId, $uhId, $doctorId, $testId, $fee, $status, $resultText, $orderDate);
            $insert->execute();
            $insert->close();

            header('Location: lab_tests.php?saved=1');
            exit;
        }

        if ($action === 'update_order') {
            $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? '';
            $resultText = trim($_POST['result'] ?? '');
            $resultDateInput = trim($_POST['result_date'] ?? '');

            if (!$orderId || !in_array($status, ['Pending', 'Done', 'Cancelled'], true)) {
                throw new RuntimeException('The order or selected status is invalid.');
            }

            if ($status === 'Done') {
                if ($resultText === '' || $resultDateInput === '') {
                    throw new RuntimeException('Enter the test result and result date before marking the order Done.');
                }
                $resultTimestamp = strtotime($resultDateInput);
                if ($resultTimestamp === false) {
                    throw new RuntimeException('Enter a valid result date.');
                }
                $resultDate = date('Y-m-d H:i:s', $resultTimestamp);
            } else {
                $resultText = null;
                $resultDate = null;
            }

            $update = $conn->prepare('UPDATE lab_orders SET status = ?, result = ?, result_date = ? WHERE id = ?');
            if (!$update) {
                throw new RuntimeException('Could not prepare the order update. Check that lab_orders has a result_date column.');
            }
            $update->bind_param('sssi', $status, $resultText, $resultDate, $orderId);
            $update->execute();
            $update->close();

            header('Location: lab_tests.php?updated=1');
            exit;
        }
    } catch (Throwable $exception) {
        // Keep the page usable while showing the database/PHP issue during local development.
        $error = $exception->getMessage();
    }
}

if (isset($_GET['saved'])) {
    $success = 'Lab test order added successfully.';
} elseif (isset($_GET['updated'])) {
    $success = 'Lab test order updated successfully.';
}

// Load dropdown data and current orders using the actual project schema.
try {
    $result = $conn->query('SELECT id, uh_id, full_name FROM patients ORDER BY full_name');
    while ($row = $result->fetch_assoc()) {
        $patients[] = $row;
    }

    $result = $conn->query('SELECT id, name FROM doctors ORDER BY name');
    while ($row = $result->fetch_assoc()) {
        $doctors[] = $row;
    }

    // The existing lab_tests table is the test catalogue: id, test_name, price.
    $result = $conn->query('SELECT id, test_name, price FROM lab_tests ORDER BY test_name');
    while ($row = $result->fetch_assoc()) {
        $testCatalogue[] = $row;
    }

    $sql = "SELECT lo.id, lo.uh_id, lo.order_date, lo.fee, lo.status, lo.result, lo.result_date,
                   p.full_name AS patient_name,
                   d.name AS doctor_name,
                   lt.test_name
            FROM lab_orders lo
            LEFT JOIN patients p ON p.id = lo.patient_id
            LEFT JOIN doctors d ON d.id = lo.doctor_id
            LEFT JOIN lab_tests lt ON lt.id = lo.test_id
            ORDER BY lo.order_date DESC, lo.id DESC";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
} catch (Throwable $exception) {
    if ($error === '') {
        $error = 'Could not load lab test information: ' . $exception->getMessage();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .lab-page { max-width: 1200px; margin: 32px auto; padding: 0 20px 40px; color: #1a202c; }
    .lab-page h2 { margin: 0 0 8px; color: #1a202c; font-size: 1.8rem; }
    .lab-intro { margin: 0 0 24px; color: #64748b; }
    .lab-card { margin: 20px 0; padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 4px 14px rgba(15,23,42,.05); }
    .lab-card h3 { margin: 0 0 20px; color: #1e3a5f; font-size: 1.2rem; }
    .lab-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
    .lab-field label { display: block; margin-bottom: 7px; font-weight: 600; color: #334155; }
    .lab-field input, .lab-field select, .lab-field textarea, .row-form input, .row-form select { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #1e293b; font: inherit; }
    .lab-field textarea { min-height: 72px; resize: vertical; }
    .lab-actions { margin-top: 20px; display: flex; gap: 10px; }
    .lab-btn { display: inline-block; border: 0; border-radius: 6px; padding: 10px 16px; background: #2563eb; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
    .lab-btn:hover { background: #1d4ed8; color: #fff; text-decoration: none; }
    .lab-btn-secondary { background: #64748b; }
    .lab-btn-secondary:hover { background: #475569; }
    .lab-alert { padding: 12px 15px; margin: 16px 0; border-radius: 6px; }
    .lab-alert-success { color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; }
    .lab-alert-error { color: #991b1b; background: #fee2e2; border: 1px solid #fecaca; overflow-wrap: anywhere; }
    .lab-table-wrap { overflow-x: auto; }
    .lab-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .lab-table th, .lab-table td { padding: 11px 12px; border: 1px solid #e2e8f0; text-align: left; vertical-align: top; white-space: normal; overflow-wrap: break-word; }
    .lab-table th { background: #f1f5f9; color: #334155; font-size: .85rem; text-transform: uppercase; letter-spacing: .03em; }
    .lab-table tbody tr:nth-child(even) { background: #f8fafc; }
    .status { display: inline-block; padding: 4px 9px; border-radius: 999px; font-size: .82rem; font-weight: 700; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-done { background: #dcfce7; color: #166534; }
    .status-cancelled { background: #fee2e2; color: #991b1b; }
    .row-form { min-width: 190px; display: grid; gap: 7px; }
    .row-form .lab-btn { padding: 8px 11px; font-size: .9rem; }
    .muted { color: #64748b; }
    @media (max-width: 768px) {
        .lab-page { padding: 0 20px 28px !important; max-width: 100% !important; }
        .lab-form-grid { grid-template-columns: 1fr !important; }
        .lab-card { padding: 16px !important; }
        .lab-page h2 { font-size: 22pt !important; }
        .lab-table { font-size: 11pt !important; }
        .lab-actions { flex-direction: column; }
        .lab-actions .lab-btn { text-align: center; }
    }
</style>

<main class="lab-page">
    <h2>Lab Tests</h2>
    <p class="lab-intro">Order tests from the existing price list, then record status and results.</p>

    <?php if ($success !== ''): ?>
        <div class="lab-alert lab-alert-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="lab-alert lab-alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="lab-card">
        <h3>Order a Lab Test</h3>
        <form method="post" action="lab_tests.php">
            <input type="hidden" name="action" value="add_order">
            <div class="lab-form-grid">
                <div class="lab-field">
                    <label for="patient_id">Patient *</label>
                    <select id="patient_id" name="patient_id" required>
                        <option value="">Select patient</option>
                        <?php foreach ($patients as $patient): ?>
                            <option value="<?= h($patient['id']) ?>"><?= h($patient['full_name']) ?> (<?= h($patient['uh_id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lab-field">
                    <label for="doctor_id">Ordering doctor *</label>
                    <select id="doctor_id" name="doctor_id" required>
                        <option value="">Select doctor</option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?= h($doctor['id']) ?>"><?= h($doctor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lab-field">
                    <label for="test_id">Test *</label>
                    <select id="test_id" name="test_id" required>
                        <option value="">Select test</option>
                        <?php foreach ($testCatalogue as $test): ?>
                            <option value="<?= h($test['id']) ?>" data-price="<?= h($test['price']) ?>">
                                <?= h($test['test_name']) ?> — Tk <?= number_format((float)$test['price'], 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lab-field">
                    <label for="order_date">Order date and time *</label>
                    <input type="datetime-local" id="order_date" name="order_date" value="<?= h(date('Y-m-d\TH:i')) ?>" required>
                </div>
                <div class="lab-field">
                    <label for="fee_display">Fee</label>
                    <input type="text" id="fee_display" value="Select a test to see its price" readonly>
                    <small class="muted">The saved fee is read from the test price list.</small>
                </div>
            </div>
            <div class="lab-actions">
                <button class="lab-btn" type="submit">Add Lab Order</button>
            </div>
        </form>
    </section>

    <section class="lab-card">
        <h3>Lab Test Orders</h3>
        <div class="lab-table-wrap" role="region" aria-label="Lab test orders" tabindex="0">
            <table class="lab-table">
                <thead>
                    <tr>
                        <th>Order</th><th>Patient / UH ID</th><th>Doctor</th><th>Test</th><th>Ordered</th><th>Fee</th><th>Status</th><th>Result</th><th>Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$orders): ?>
                        <tr><td colspan="9" class="muted">No lab test orders yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <?php $statusClass = strtolower((string)$order['status']); ?>
                            <tr>
                                <td><?= h($order['id']) ?></td>
                                <td><?= h($order['patient_name'] ?: 'Patient record unavailable') ?><br><span class="muted"><?= h($order['uh_id']) ?></span></td>
                                <td><?= h($order['doctor_name'] ?: 'Doctor record unavailable') ?></td>
                                <td><?= h($order['test_name'] ?: 'Test record unavailable') ?></td>
                                <td><?= h($order['order_date']) ?></td>
                                <td>Tk <?= number_format((float)$order['fee'], 2) ?></td>
                                <td><span class="status status-<?= h($statusClass) ?>"><?= h($order['status']) ?></span></td>
                                <td>
                                    <?= h($order['result'] ?: '—') ?>
                                    <?php if (!empty($order['result_date'])): ?><br><span class="muted"><?= h($order['result_date']) ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <form class="row-form" method="post" action="lab_tests.php">
                                        <input type="hidden" name="action" value="update_order">
                                        <input type="hidden" name="order_id" value="<?= h($order['id']) ?>">
                                        <select name="status" aria-label="Order status">
                                            <?php foreach (['Pending', 'Done', 'Cancelled'] as $option): ?>
                                                <option value="<?= h($option) ?>" <?= $order['status'] === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="result" placeholder="Result (required for Done)" value="<?= h($order['result']) ?>">
                                        <input type="date" name="result_date" value="<?= !empty($order['result_date']) ? h(date('Y-m-d', strtotime($order['result_date']))) : '' ?>" aria-label="Result date">
                                        <button class="lab-btn" type="submit">Save Update</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
    // Display the selected test's catalogue price; the server independently
    // looks up and stores the authoritative price on submission.
    document.addEventListener('DOMContentLoaded', function () {
        const testSelect = document.getElementById('test_id');
        const feeDisplay = document.getElementById('fee_display');
        if (!testSelect || !feeDisplay) return;

        function showPrice() {
            const option = testSelect.options[testSelect.selectedIndex];
            const price = option ? option.dataset.price : '';
            feeDisplay.value = price ? 'Tk ' + Number(price).toFixed(2) : 'Select a test to see its price';
        }

        testSelect.addEventListener('change', showPrice);
        showPrice();
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>