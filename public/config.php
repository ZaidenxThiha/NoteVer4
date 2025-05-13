<?php
// Database configuration using environment variables for security and flexibility
$db_host = getenv('DB_HOST') ?: 'mysql';
$db_user = getenv('DB_USER') ?: 'noteapp_user';
$db_pass = getenv('DB_PASS') ?: 'YourStrong@Passw0rd';
$db_name = getenv('DB_NAME') ?: 'noteapp';

try {
    // Initialize PDO connection
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    
    // Set PDO attributes
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Log successful connection (avoid logging sensitive details)
    error_log("Database connection established successfully for $db_name");
} catch (PDOException $e) {
    // Log the error with details for debugging
    error_log("Database connection failed: " . $e->getMessage());
    
    // Display a user-friendly message and exit
    http_response_code(500);
    die("Unable to connect to the database. Please try again later.");
}
?>