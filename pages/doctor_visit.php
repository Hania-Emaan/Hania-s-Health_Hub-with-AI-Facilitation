<?php
// pages/doctor_visit.php

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

$patients = [];
$doctors = [];

// Load patients for the dropdown.
$patient_query = $conn->query(
    "SELECT id, uh_id, full_name
     FROM patients
     ORDER BY full_name ASC"
);

if ($patient_query) {
    while ($row = $patient_query->fetch_assoc()) {
        $patients[] = $row;
    }
    $patient_query->free();
} else {
    $error = 'Unable to load patients: ' . $conn->error;
}

// Load doctors for the dropdown.
$doctor_query = $conn->query(
    "SELECT id, name
     FROM doctors
     ORDER BY name ASC"
);

if ($doctor_query) {
    while ($row = $doctor_query->fetch_assoc()) {
        $doctors[] = $row;
    }
    $doctor_query->free();
} else {
    $error = 'Unable to load doctors: ' . $conn->error;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id  = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
    $doctor_id   = isset($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : 0;
    $complaint   = trim($_POST['complaint'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $fee         = isset($_POST['fee']) ? (float) $_POST['fee'] : 0;
    $visit_date  = trim($_POST['visit_date'] ?? '');

    if ($visit_date === '') {
        $visit_date = date('Y-m-d H:i:s');
    } else {
        $visit_date = str_replace('T', ' ', $visit_date);
        if (strlen($visit_date) === 16) {
            $visit_date .= ':00';
        }
    }

    if ($patient_id <= 0) {
        $error = 'Please select a patient.';
    } elseif ($doctor_id <= 0) {
        $error = 'Please select a doctor.';
    } elseif ($fee < 0) {
        $error = 'Fee cannot be negative.';
    } else {
        $patient_stmt = $conn->prepare(
            "SELECT uh_id FROM patients WHERE id = ? LIMIT 1"
        );

        if (!$patient_stmt) {
            $error = 'Error preparing patient lookup: ' . $conn->error;
        } else {
            $patient_stmt->bind_param('i', $patient_id);
            $patient_stmt->execute();
            $patient_result = $patient_stmt->get_result();
            $selected_patient = $patient_result->fetch_assoc();
            $patient_stmt->close();

            if (!$selected_patient) {
                $error = 'The selected patient was not found.';
            } else {
                $uh_id = $selected_patient['uh_id'];

                $visit_stmt = $conn->prepare(
                    "INSERT INTO visits
                    (patient_id, uh_id, doctor_id, complaint, prescription, fee, visit_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                if (!$visit_stmt) {
                    $error = 'Error preparing visit statement: ' . $conn->error;
                } else {
                    $visit_stmt->bind_param(
                        'isissds',
                        $patient_id,
                        $uh_id,
                        $doctor_id,
                        $complaint,
                        $prescription,
                        $fee,
                        $visit_date
                    );

                    if ($visit_stmt->execute()) {
                        $success = 'Doctor visit recorded successfully.';
                        $_POST = [];
                    } else {
                        $error = 'Error recording visit: ' . $visit_stmt->error;
                    }

                    $visit_stmt->close();
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: 40px; padding-bottom: 40px;">
    <h2 class="page-title" style="text-align: left; margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Record Doctor Visit</h2>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" style="padding: 15px; margin-bottom: 20px; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24; background-color: #f8d7da;">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success" style="padding: 15px; margin-bottom: 20px; border: 1px solid #bee5eb; border-radius: 4px; color: #0c5460; background-color: #d1ecf1;">
            <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div style="background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); max-width: 800px; margin: 0 auto;">
        <form method="post" action="">
            <div style="margin-bottom: 20px;">
                <label for="patient_id" style="display: block; font-weight: 600; margin-bottom: 8px; color: #4a5568;">Patient <span style="color: #e53e3e;">*</span></label>
                <select id="patient_id" name="patient_id" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 1em; background-color: #fff;">
                    <option value="">-- Select Patient --</option>
                    <?php foreach ($patients as $patient): ?>
                        <?php
                        $patient_value = (string) ($patient['id'] ?? '');
                        $selected = ((string) ($_POST['patient_id'] ?? '') === $patient_value) ? 'selected' : '';
                        ?>
                        <option value="<?php echo htmlspecialchars($patient_value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>>
                            <?php echo htmlspecialchars($patient['uh_id'] . ' - ' . $patient['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="doctor_id" style="display: block; font-weight: 600; margin-bottom: 8px; color: #4a5568;">Doctor <span style="color: #e53e3e;">*</span></label>
                <select id="doctor_id" name="doctor_id" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 1em; background-color: #fff;">
                    <option value="">-- Select Doctor --</option>
                    <?php foreach ($doctors as $doctor): ?>
                        <?php
                        $doctor_value = (string) ($doctor['id'] ?? '');
                        $selected = ((string) ($_POST['doctor_id'] ?? '') === $doctor_value) ? 'selected' : '';
                        ?>
                        <option value="<?php echo htmlspecialchars($doctor_value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>>
                            <?php echo htmlspecialchars($doctor['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="visit_date" style="display: block; font-weight: 600; margin-bottom: 8px; color: #4a5568;">Visit Date/Time <span style="color: #e53e3e;">*</span></label>
                <input
                    type="datetime-local"
                    id="visit_date"
                    name="visit_date"
                    value="<?php echo htmlspecialchars($_POST['visit_date'] ?? date('Y-m-d\\TH:i'), ENT_QUOTES, 'UTF-8'); ?>"
                    required
                    style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 1em; box-sizing: border-box;"
                >
            </div>

            <div style="margin-bottom: 20px;">
                <label for="fee" style="display: block; font-weight: 600; margin-bottom: 8px; color: #4a5568;">Fee <span style="color: #e53e3e;">*</span></label>
                <input
                    type="number"
                    id="fee"
                    name="fee"
                    min="0"
                    step="0.01"
                    placeholder="e.g., 500.00"
                    value="<?php echo htmlspecialchars($_POST['fee'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    required
                    style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 1em; box-sizing: border-box;"
                >
            </div>

            <div style="margin-bottom: 20px;">
                <label for="complaint" style="display: block; font-weight: 600; margin-bottom: 8px; color: #4a5568;">Problem Description:</label>
                <textarea id="complaint" name="complaint" rows="4" placeholder="Enter the patient's complaint or problem" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 1em; box-sizing: border-box;"><?php echo htmlspecialchars($_POST['complaint'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div style="margin-bottom: 30px;">
                <label for="prescription" style="display: block; font-weight: 600; margin-bottom: 8px; color: #4a5568;">Prescribed Medicines:</label>
                <textarea id="prescription" name="prescription" rows="4" placeholder="Enter prescribed medicines and instructions" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 1em; box-sizing: border-box;"><?php echo htmlspecialchars($_POST['prescription'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div style="display: flex; gap: 15px;">
                <button type="submit" class="edit-btn" style="padding: 10px 20px; border-radius: 4px; color: white; font-weight: 500; border: none; cursor: pointer; background-color: #2563eb; font-size: 1em;">Record Visit</button>
                <a href="<?php echo BASE_URL; ?>/index.php" class="view-btn" style="padding: 10px 20px; border-radius: 4px; color: white; font-weight: 500; text-decoration: none; background-color: #64748b; font-size: 1em; display: inline-block;">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>