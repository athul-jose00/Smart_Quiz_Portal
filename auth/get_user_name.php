<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if (isset($_GET['identifier'])) {
    $identifier = trim($_GET['identifier']);
    
    // Check if the identifier is an email or username
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
    
    if ($isEmail) {
        $stmt = $conn->prepare("SELECT name FROM users WHERE email = ?");
    } else {
        $stmt = $conn->prepare("SELECT name FROM users WHERE username = ?");
    }
    
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo json_encode([
            'success' => true, 
            'name' => $user['name'],
            'type' => $isEmail ? 'email' : 'username'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'User not found',
            'type' => $isEmail ? 'email' : 'username'
        ]);
    }
    
    $stmt->close();
    $conn->close();
} else {
    echo json_encode(['success' => false, 'message' => 'No identifier provided']);
}
exit();
?>