<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if (isset($_GET['username'])) {
    $username = trim($_GET['username']);
    
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        echo json_encode(['available' => false, 'message' => 'Username already taken']);
    } else {
        echo json_encode(['available' => true, 'message' => 'Username available']);
    }
    
    $stmt->close();
    $conn->close();
} else {
    echo json_encode(['available' => false, 'message' => 'No username provided']);
}
exit();
?>