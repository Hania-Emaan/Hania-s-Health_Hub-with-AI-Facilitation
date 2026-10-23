<?php
// pages/patient_details.php
require_once __DIR__ . '/../includes/db.php';

// Get patient ID from URL
$patient_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($patient_id <= 0) {
    header("Location: patient_list.php");
    exit();
}

// Fetch patient details
$sql = "SELECT id, uh_id, full_name, dob, sex, phone, address, created_at FROM patients WHERE id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $patient_id);
$stmt->execute();
$result = $stmt->get_result();
$patient = $result->fetch_assoc();

$stmt->close();

if (!$patient) {
    // Patient not found
    include_once __DIR__ . '/../includes/header.php';
    echo '<div class="container" style="padding-top: 40px;"><div class="alert alert-danger">Patient not found.</div><a href="patient_list.php" class="view-btn" style="padding: 8px 15px; display:inline-block; border-radius:4px;">Back to Patient List</a></div>';
    include_once __DIR__ . '/../includes/footer.php';
    $conn->close();
    exit();
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding-top: 40px; padding-bottom: 40px;">
    <h2 class="page-title" style="text-align: left; margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">Patient Details</h2>
    
    <div style="background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); max-width: 800px; margin: 0 auto;">
        <table style="margin-top: 0; min-width: 100%;">
            <tr>
                <th style="width: 30%; background-color: #f1f5f9;">UH ID</th>
                <td><strong><?php echo htmlspecialchars($patient['uh_id']); ?></strong></td>
            </tr>
            <tr>
                <th style="background-color: #f1f5f9;">Full Name</th>
                <td><?php echo htmlspecialchars($patient['full_name']); ?></td>
            </tr>
            <tr>
                <th style="background-color: #f1f5f9;">Date of Birth</th>
                <td><?php echo htmlspecialchars($patient['dob']); ?></td>
            </tr>
            <tr>
                <th style="background-color: #f1f5f9;">Sex</th>
                <td><?php echo htmlspecialchars($patient['sex']); ?></td>
            </tr>
            <tr>
                <th style="background-color: #f1f5f9;">Phone</th>
                <td><?php echo htmlspecialchars($patient['phone']); ?></td>
            </tr>
            <tr>
                <th style="background-color: #f1f5f9;">Address</th>
                <td><?php echo nl2br(htmlspecialchars($patient['address'])); ?></td>
            </tr>
            <tr>
                <th style="background-color: #f1f5f9;">Registration Date</th>
                <td><?php echo htmlspecialchars($patient['created_at']); ?></td>
            </tr>
        </table>

        <div style="margin-top: 30px; display: flex; gap: 15px;">
            <a href="patient_edit.php?id=<?php echo $patient['id']; ?>" class="edit-btn" style="padding: 10px 20px; border-radius: 4px; color: white; font-weight: 500;">Edit Patient</a>
            <a href="patient_list.php" class="view-btn" style="padding: 10px 20px; border-radius: 4px; color: white; font-weight: 500; background-color: #64748b;">Back to List</a>
        </div>
    </div>
</div>

<?php
include_once __DIR__ . '/../includes/footer.php';
$conn->close();
?>