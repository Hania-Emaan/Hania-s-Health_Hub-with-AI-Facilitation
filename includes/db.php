    <?php
    // hanias_health_hub/includes/db.php

    // Database configuration
    $db_host = 'localhost';
    $db_name = 'hanias_health_hub';
    $db_user = 'root'; // Default XAMPP username
    $db_pass = '';     // Default XAMPP password

    // Create connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    // Check connection
    if ($conn->connect_error) {
        // In a production environment, you'd log this error and show a generic message.
        // For development, showing the error is helpful.
        die("Connection failed: " . $conn->connect_error);
    }

    // Set charset to utf8mb4 for better character support
    $conn->set_charset("utf8mb4");

    // Define BASE_URL for consistent pathing
    // This assumes your hanias_health_hub folder is directly under htdocs
    define('BASE_URL', 'http://localhost/hanias_health_hub'); 

    ?>
    