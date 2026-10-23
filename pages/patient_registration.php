    <?php
    // hanias_health_hub/pages/patient_registration.php

    // Enable error reporting for debugging - REMOVE THIS IN PRODUCTION
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    require_once __DIR__ . '/../includes/db.php'; // Include database connection
    require_once __DIR__ . '/../includes/header.php'; // Include header for navigation and styling

    $message = ''; // To store success or error messages
    $message_type = ''; // 'success' or 'danger'

    // --- Handle Form Submission ---
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Retrieve and sanitize form data
        $full_name = trim($_POST["full_name"]);
        $dob = $_POST["dob"];
        $sex = trim($_POST["sex"]);
        $phone = trim($_POST["phone"]);
        $address = trim($_POST["address"]);

        // --- Data Validation ---
        $errors = [];
        if (empty($full_name)) {
            $errors[] = "Full Name is required.";
        }
        if (empty($sex)) {
            $errors[] = "Sex is required.";
        }
        if (empty($phone)) {
            $errors[] = "Phone number is required.";
        }
        // Basic email format check for phone if needed, or just keep as string
        // if (!filter_var($phone, FILTER_VALIDATE_EMAIL)) { // Example for email, adjust for phone
        //     $errors[] = "Invalid phone number format.";
        // }
        if (empty($dob)) {
            // Date validation can be more complex, checking format YYYY-MM-DD
            // For now, just ensure it's not empty if the input type is date
        }


        if (empty($errors)) {
            // --- Generate Unique UH ID ---
            $uh_id = 'HHH-';
            // Get the latest UH_ID from the database
            $sql_latest_id = "SELECT uh_id FROM patients ORDER BY id DESC LIMIT 1";
            $result_latest_id = $conn->query($sql_latest_id);

            if ($result_latest_id && $result_latest_id->num_rows > 0) {
                $row = $result_latest_id->fetch_assoc();
                $latest_id_str = $row['uh_id']; // e.g., "HHH-00005"

                // Extract the number part and increment
                $numeric_part = intval(substr($latest_id_str, 4)); // Get the number after "HHH-"
                $new_numeric_part = $numeric_part + 1;
                $uh_id .= sprintf("%05d", $new_numeric_part); // Pad with leading zeros to 5 digits
            } else {
                // If no records yet, start with HHH-00001
                $uh_id .= "00001";
                if ($conn->error) {
                    error_log("Error fetching latest UH ID: " . $conn->error);
                    // Fallback if DB error occurs during ID generation
                    $uh_id = 'HHH-' . uniqid(); // Use a fallback if DB query fails
                }
            }

            // --- Prepare and Execute SQL Statement ---
            $sql = "INSERT INTO patients (uh_id, full_name, dob, sex, phone, address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
            
            if ($stmt = $conn->prepare($sql)) {
                // Bind parameters
                $stmt->bind_param("ssssss", $uh_id, $full_name, $dob, $sex, $phone, $address);

                // Execute statement
                if ($stmt->execute()) {
                    // Success
                    $_SESSION['message'] = "Patient registered successfully with UH ID: " . htmlspecialchars($uh_id);
                    $_SESSION['message_type'] = 'success';
                    // Redirect to patient list page
                    header("Location: " . BASE_URL . "/pages/patient_list.php");
                    exit();
                } else {
                    // Error executing statement
                    $message = "Error: Could not register patient. " . htmlspecialchars($stmt->error);
                    $message_type = 'danger';
                    error_log("SQL Error: " . $stmt->error);
                }

                // Close statement
                $stmt->close();
            } else {
                // Error preparing statement
                $message = "Error: Could not prepare statement. " . htmlspecialchars($conn->error);
                $message_type = 'danger';
                error_log("SQL Prepare Error: " . $conn->error);
            }
        } else {
            // Display validation errors
            $message = "Please correct the following errors: <br>" . implode("<br>", $errors);
            $message_type = 'danger';
        }
    }

    // Close the database connection
    // $conn->close(); // Better to close at the end of script execution or in footer.
    ?>
    <div class="container page-content">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <h2>Patient Registration</h2>
        <p>Please fill in the patient's details below.</p>

        <div class="form-container">
            <form action="patient_registration.php" method="post">
                <div class="form-group">
                    <label for="full_name">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="dob">Date of Birth:</label>
                    <input type="date" id="dob" name="dob" value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="sex">Sex:</label>
                    <select id="sex" name="sex" required>
                        <option value="">-- Select Sex --</option>
                        <option value="Male" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number:</label>
                    <input type="tel" id="phone" name="phone" required value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="address">Address:</label>
                    <textarea id="address" name="address" rows="3"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                </div>

                <button type="submit" class="submit-btn">Register Patient</button>
            </form>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <style>
        /* Inline styles for the form page */
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
        .form-container {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            max-width: 600px;
            margin: 0 auto;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
        }
        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="tel"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e0;
            border-radius: 4px;
            font-size: 1em;
            box-sizing: border-box; /* Include padding and border in the element's total width and height */
        }
        .form-group textarea {
            resize: vertical; /* Allow vertical resizing */
        }
        .submit-btn {
            background-color: #2563eb;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
            width: 100%; /* Make button full width */
        }
        .submit-btn:hover {
            background-color: #1d4ed8;
        }

        /* Responsive adjustments for form */
        @media (max-width: 600px) {
            .form-container {
                padding: 20px;
            }
            .submit-btn {
                font-size: 1em;
            }
        }
    </style>
    