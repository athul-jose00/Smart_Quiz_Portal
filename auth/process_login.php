<?php
session_start();

require_once '../includes/db.php';

// Process form data
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $username_or_email = trim($_POST["username"]);
    $password = $_POST["password"];
    

    // Store username for repopulating form if needed
    $_SESSION['login_username'] = $username_or_email;

    // Find user by username or email
    $stmt = $conn->prepare("SELECT user_id, username, email, password, role FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username_or_email, $username_or_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            // Set remember me cookie if checked
            if ($remember) {
                $cookie_value = base64_encode($user['user_id'] . ':' . hash('sha256', $user['password']));
                setcookie('remember_token', $cookie_value, time() + (86400 * 30), "/"); // 30 days
            }
            
            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header("Location: admin/admin.php");
                    break;
                case 'teacher':
                    header("Location: ../dashboard/teacher/teacher.php");
                    break;
                case 'student':
                    header("Location:../dashboard/student/student.php");
                    break;
                default:
                    header("Location: index.php");
            }
            exit();
        } else {
            $_SESSION['login_error'] = "Invalid username or password";
        }
    } else {
        $_SESSION['login_error'] = "Invalid username or password";
    }
    
    $stmt->close();
    header("Location: login.php");
    exit();
}

$conn->close();
?>