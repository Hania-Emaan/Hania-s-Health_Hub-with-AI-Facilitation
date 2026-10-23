<?php
// pages/patient_edit.php
require_once __DIR__ . '/../includes/db.php';

$patient_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($patient_id <= 0) {
    header("Location: patient_list.php");
    exit();
}

$error_message = '';
$success_message = '';

// Handle form submission for update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $dob       = trim($_POST['dob'] ?? '');
    $sex       = trim($_POST['sex'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');

    if (empty($full_name) || empty($sex) || empty($phone)) {
        $error_message = "Please fill in all required fields.";
    } else {
        $sql = "UPDATE patients SET full_name=?, dob=?, sex=?, phone=?, address=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("sssssi", $full_name, $dob, $sex, $phone, $address, $patient_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Patient record updated successfully!";
                header("Location: patient_list.php");
                exit();
            } else {
                $error_message = "Error updating record: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Error preparing statement: " . $conn->error;
        }
    }
}

// Fetch current patient data
$sql = "SELECT id, uh_id, full_name, dob, sex, phone, address, created_at FROM patients WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();
$patient = $result->fetch_assoc();
$stmt->close();

if (!$patient) {
    header("Location: patient_list.php");
    exit();
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: 40px; padding-bottom: 40px;">
    <h2 class="page-title" style="text-align: left; margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Edit Patient Record</h2>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <div style="background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); max-width: 800px; margin: 0 auto;">
        <form action="patient_edit.php?id=<?php echo $patient['id']; ?>" method="POST">
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">UH ID (Read-only)</label>
                <input type="text" value="<?php echo htmlspecialchars($patient['uh_id']); ?>" disabled style="width: 100%; padding: 10px; background-color: #f1f5f9; border: 1px solid #cbd5e0; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Full Name *</label>
                <input type="text" name="full_name" required value="<?php echo htmlspecialchars($patient['full_name']); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Date of Birth</label>
                <input type="date" name="dob" value="<?php echo htmlspecialchars($patient['dob']); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Sex *</label>
                <select name="sex" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; background: white;">
                    <option value="Male" <?php echo ($patient['sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo ($patient['sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                    <option value="Other" <?php echo ($patient['sex'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Phone *</label>
                <input type="text" name="phone" required value="<?php echo htmlspecialchars($patient['phone']); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Address</label>
                <textarea name="address" rows="3" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px;"><?php echo htmlspecialchars($patient['address']); ?></textarea>
            </div>

            <div style="display: flex; gap: 15px; margin-top: 30px;">
                <button type="submit" class="edit-btn" style="padding: 10px 25px; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; font-weight: 500;">Update Patient</button>
                <a href="patient_list.php" class="view-btn" style="padding: 10px 25px; border-radius: 4px; background-color: #64748b; color: white; display: inline-block; text-align: center;">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php
include_once __DIR__ . '/../includes/footer.php';
$conn->close();
?>