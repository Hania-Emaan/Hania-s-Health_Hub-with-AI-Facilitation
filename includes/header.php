<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/hanias_health_hub');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hania's Health Hub</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/css/style.css">
    <style>
        .site-header {
            background-color: #1a202c;
            color: #ffffff;
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .site-header .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .site-title {
            font-size: 1.8em;
            font-weight: bold;
            margin: 0;
            color: #ffffff;
        }
        .site-nav ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
        }
        .site-nav li {
            margin-left: 25px;
        }
        .site-nav a {
            color: #cbd5e0;
            font-weight: 500;
            padding: 5px 0;
            text-decoration: none;
            transition: color 0.2s ease-in-out;
        }
        .site-nav a:hover {
            color: #ffffff;
        }
        @media (max-width: 768px) {
            .site-header .container {
                flex-direction: column;
                text-align: center;
            }
            .site-title {
                margin-bottom: 15px;
            }
            .site-nav ul {
                justify-content: center;
            }
            .site-nav li {
                margin: 0 10px;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <h1 class="site-title">Hania's Health Hub</h1>
            <nav class="site-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>/index.php">Dashboard</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/pages/patient_registration.php">Register Patient</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/pages/patient_list.php">Patient List</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/pages/doctor_visit.php">Doctor Visit</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/pages/lab_tests.php">Lab Tests</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/pages/reports.php">Reports</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/pages/billings.php">Billing</a></li>
                     <li><a href="<?php echo BASE_URL; ?>/pages/ai_chat.php">AI Report Chat</a></li> 
                </ul>
            </nav>
        </div>
    </header>