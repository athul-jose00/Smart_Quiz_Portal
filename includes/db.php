<?php
// db.php - database connection file

$host = "localhost";
$username = "root";
$password = "";
$database = "SmartQuizPortal";

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
