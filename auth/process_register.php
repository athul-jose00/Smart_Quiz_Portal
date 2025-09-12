<?php
session_start();

// Database configuration
require_once '../includes/db.php';

// Process form data
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Get inputs
    $username = trim($_POST["username"]);
    $name     = trim($_POST["name"]);
    $email    = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];
    $role     = $_POST["role"];

    // Validation and JS alert for each error
    if (empty($username)) {
        echo "<script>alert('Username is required'); window.history.back();</script>";
        exit();
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        echo "<script>alert('Username can only contain letters, numbers, and underscores'); window.history.back();</script>";
        exit();
    }

    if (empty($name)) {
        echo "<script>alert('Full name is required'); window.history.back();</script>";
        exit();
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>alert('Valid email is required'); window.history.back();</script>";
        exit();
    }

    if (strlen($password) < 8) {
        echo "<script>alert('Password must be at least 8 characters'); window.history.back();</script>";
        exit();
    } elseif ($password !== $confirm_password) {
        echo "<script>alert('Passwords do not match'); window.history.back();</script>";
        exit();
    }

    // Check if username or email already exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo "<script>alert('Username or email already exists'); window.history.back();</script>";
        $stmt->close();
        exit();
    }
    $stmt->close();

    // Register user
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, name, email, password, role) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $username, $name, $email, $hashed_password, $role);

    if ($stmt->execute()) {
        // Store a temporary flag in the session
        $_SESSION["registration_success"] = true;
        header("Location: login.php");
        exit();
    } else {
        echo "<script>alert('Registration failed. Please try again.'); window.history.back();</script>";
    }

    $stmt->close();
}

$conn->close();

?>
