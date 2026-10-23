    <?php
    // hanias_health_hub/index.php

    // Enable error reporting for debugging - REMOVE THIS IN PRODUCTION
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    require_once __DIR__ . '/includes/db.php'; // Include database connection

    // --- Fetching Sample Data for Dashboard Metrics ---
    $total_patients = 0;
    $today_visits = 0;
    $pending_tests = 0;
    $today_collection = 0;

    // Fetch Total Patients
    $sql_patients = "SELECT COUNT(*) AS total FROM patients";
    $result_patients = $conn->query($sql_patients);
    if ($result_patients && $result_patients->num_rows > 0) {
        $row = $result_patients->fetch_assoc();
        $total_patients = $row['total'];
    } else {
        error_log("Error fetching total patients: " . $conn->error);
    }

    // Fetch Today's Visits
    $today_date = date('Y-m-d');
    $sql_visits = "SELECT COUNT(*) AS total FROM visits WHERE visit_date = '$today_date'";
    $result_visits = $conn->query($sql_visits);
    if ($result_visits && $result_visits->num_rows > 0) {
        $row = $result_visits->fetch_assoc();
        $today_visits = $row['total'];
    } else {
        error_log("Error fetching today's visits: " . $conn->error);
    }

    // Fetch Pending Lab Tests
    $sql_tests = "SELECT COUNT(*) AS total FROM lab_orders WHERE status = 'Pending'";
    $result_tests = $conn->query($sql_tests);
    if ($result_tests && $result_tests->num_rows > 0) {
        $row = $result_tests->fetch_assoc();
        $pending_tests = $row['total'];
    } else {
        error_log("Error fetching pending lab tests: " . $conn->error);
    }

    // Fetch Today's Collection (from payments)
    $sql_collection = "SELECT SUM(amount_paid) AS total FROM payments WHERE payment_date = '$today_date'";
    $result_collection = $conn->query($sql_collection);
    if ($result_collection && $result_collection->num_rows > 0) {
        $row = $result_collection->fetch_assoc();
        $today_collection = $row['total'] ?? 0; // Use ?? 0 in case total is NULL
    } else {
        error_log("Error fetching today's collection: " . $conn->error);
    }

    // Close connection if not needed elsewhere (good practice)
    // $conn->close(); // Closing here might affect other pages if they include this directly. Better to close at the end of script execution or in footer.
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Hania's Health Hub - Dashboard</title>
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/css/style.css">
        <style>
            /* Dashboard Specific Styles */
            body {
                margin: 0;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: #f8fafc; /* Light background */
                color: #1a202c;
            }
            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
            }
            .header {
                background-color: #1a202c;
                color: #ffffff;
                padding: 15px 0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            .header h1 {
                margin: 0;
                font-size: 1.8em;
                font-weight: bold;
                text-align: center;
            }
            .nav ul {
                list-style: none;
                padding: 0;
                margin: 0;
                display: flex;
                justify-content: center;
                flex-wrap: wrap;
                background-color: #2d3748; /* Darker nav background */
                padding: 10px 0;
            }
            .nav li {
                margin: 0 15px;
            }
            .nav a {
                color: #cbd5e0;
                font-weight: 500;
                padding: 5px 0;
                transition: color 0.2s ease-in-out;
            }
            .nav a:hover {
                color: #ffffff;
                text-decoration: none;
            }

            .dashboard-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-top: 30px;
            }
            .card {
                background-color: #ffffff;
                border-radius: 8px;
                padding: 25px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
                text-align: center;
                transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            }
            .card:hover {
                transform: translateY(-5px);
                box-shadow: 0 8px 20px rgba(0,0,0,0.12);
            }
            .card-title {
                font-size: 1.1em;
                color: #718096; /* Medium gray for titles */
                margin-bottom: 10px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .card-value {
                font-size: 2.8em;
                font-weight: bold;
                color: #2563eb; /* Primary blue for values */
                margin-bottom: 5px;
            }
            .card-label {
                font-size: 0.9em;
                color: #a0aec0; /* Lighter gray for labels */
            }
            .welcome-section {
                text-align: center;
                margin-top: 40px;
                padding: 30px;
                background-color: #e2e8f0;
                border-radius: 8px;
            }
            .welcome-section h2 {
                font-size: 2em;
                color: #2563eb;
                margin-bottom: 15px;
            }
            .welcome-section p {
                font-size: 1.1em;
                color: #4a5568;
                line-height: 1.6;
            }
            .footer {
                text-align: center;
                margin-top: 50px;
                padding: 20px;
                font-size: 0.9em;
                color: #718096;
                border-top: 1px solid #e2e8f0;
            }

            /* Responsive adjustments */
            @media (max-width: 768px) {
                .nav ul {
                    flex-direction: column;
                    align-items: center;
                }
                .nav li {
                    margin: 5px 0;
                }
                .dashboard-grid {
                    grid-template-columns: 1fr; /* Stack cards on smaller screens */
                }
                .welcome-section h2 {
                    font-size: 1.6em;
                }
                .welcome-section p {
                    font-size: 1em;
                }
            }
        </style>
    </head>
    <body>
        <header class="header">
            <div class="container">
                <h1>Hania's Health Hub</h1>
            </div>
        </header>

        <nav class="nav">
            <ul>
                <li><a href="<?php echo BASE_URL; ?>/index.php">Dashboard</a></li>
                <li><a href="<?php echo BASE_URL; ?>/pages/patient_registration.php">Register Patient</a></li>
                <li><a href="<?php echo BASE_URL; ?>/pages/patient_list.php">Patient List</a></li>
                <li><a href="<?php echo BASE_URL; ?>/pages/doctor_visit.php">Doctor Visit</a></li>
                <li><a href="<?php echo BASE_URL; ?>/pages/lab_tests.php">Lab Tests</a></li>
                <li><a href="<?php echo BASE_URL; ?>/pages/reports.php">Reports</a></li>
                 <li><a href="<?php echo BASE_URL; ?>/pages/ai_chat.php">AI Report Chat</a></li> 
                <!-- Add more links as needed -->
            </ul>
        </nav>

        <div class="container">
            <section class="welcome-section">
                <h2>Welcome to Hania's Health Hub</h2>
                <p>Your central system for managing patient care, appointments, and reports efficiently.</p>
            </section>

            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-title">Total Patients</div>
                    <div class="card-value"><?php echo htmlspecialchars($total_patients); ?></div>
                    <div class="card-label">Registered in the system</div>
                </div>
                <div class="card">
                    <div class="card-title">Today's Visits</div>
                    <div class="card-value"><?php echo htmlspecialchars($today_visits); ?></div>
                    <div class="card-label">Appointments scheduled for today</div>
                </div>
                <div class="card">
                    <div class="card-title">Pending Lab Tests</div>
                    <div class="card-value"><?php echo htmlspecialchars($pending_tests); ?></div>
                    <div class="card-label">Tests awaiting results</div>
                </div>
                <div class="card">
                    <div class="card-title">Today's Collection</div>
                    <div class="card-value">Tk <?php echo number_format($today_collection, 2); ?></div>
                    <div class="card-label">Revenue collected today</div>
                </div>
            </div>
        </div>

        <footer class="footer">
            <div class="container">
                <p>&copy; <?php echo date('Y'); ?> Hania's Health Hub. All rights reserved.</p>
            </div>
        </footer>

        <script src="<?php echo BASE_URL; ?>/js/script.js"></script>
    </body>
    </html>
    