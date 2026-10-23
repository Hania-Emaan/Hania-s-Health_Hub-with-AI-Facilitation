    <?php
    // hanias_health_hub/pages/patient_list.php

    // Enable error reporting for debugging - REMOVE THIS IN PRODUCTION
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    require_once __DIR__ . '/../includes/db.php'; // Include database connection
    require_once __DIR__ . '/../includes/header.php'; // Include header for navigation and styling

    $patients = [];
    $message = '';
    $message_type = '';

    // --- Check for session messages (from patient_registration.php) ---
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $message_type = $_SESSION['message_type'];
        unset($_SESSION['message']); // Clear message after displaying
        unset($_SESSION['message_type']);
    }

    // --- Fetch Patients from Database ---
    $sql = "SELECT id, uh_id, full_name, sex, phone, address, created_at FROM patients ORDER BY created_at DESC";
    $result = $conn->query($sql);

    if ($result) {
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $patients[] = $row;
            }
        } else {
            $message = "No patients registered yet. Please add a new patient.";
            $message_type = 'info'; // Use a different type for informational messages
        }
    } else {
        $message = "Error retrieving patient data: " . htmlspecialchars($conn->error);
        $message_type = 'danger';
        error_log("SQL Error in patient_list.php: " . $conn->error);
    }

    // Close connection
    // $conn->close(); // Better to close at the end of script execution or in footer.
    ?>
    <div class="container page-content">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <h2>Patient List</h2>
        <p>View all registered patients and manage their records.</p>

        <?php if (!empty($patients)): ?>
        <div class="table-responsive">
            <table data-table>
                <thead>
                    <tr>
                        <th>UH ID</th>
                        <th>Full Name</th>
                        <th>Sex</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Registered On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patients as $patient): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($patient['uh_id']); ?></td>
                        <td><?php echo htmlspecialchars($patient['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($patient['sex']); ?></td>
                        <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($patient['address'])); ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($patient['created_at'])); ?></td>
                        <td class="action-buttons">
                            <a href="patient_details.php?id=<?php echo $patient['id']; ?>" class="view-btn">View</a>
                            <a href="patient_edit.php?id=<?php echo $patient['id']; ?>" class="edit-btn">Edit</a>
                            <!-- Delete button will be implemented later -->
                            <!-- <a href="patient_delete.php?id=<?php echo $patient['id']; ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this patient?');">Delete</a> -->
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <style>
        /* Inline styles for the patient list page */
        .page-content {
            padding-top: 30px;
            padding-bottom: 50px;
        }
        .page-content h2 {
            text-align: center;
            color: #2563eb;
            margin-bottom: 10px;
        }
        .page-content p {
            text-align: center;
            color: #718096;
            margin-bottom: 30px;
        }
        /* Styles for table responsive and general table appearance are in css/style.css */
        /* Additional styles specific to this page if needed */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }
        .alert-success {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
    </style>
    