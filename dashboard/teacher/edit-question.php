<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$question_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Initialize message variables
$question_message = null;
$question_message_type = null;
$option_message = null;
$option_message_type = null;

// Get question details and verify ownership
$question_sql = "SELECT q.question_id, q.question_text, q.points, q.quiz_id, 
                         qz.title as quiz_title, qz.class_id, c.class_name
                 FROM questions q
                 JOIN quizzes qz ON q.quiz_id = qz.quiz_id
                 JOIN classes c ON qz.class_id = c.class_id
                 WHERE q.question_id = ? AND qz.created_by = ?";
$question_stmt = $conn->prepare($question_sql);
$question_stmt->bind_param("ii", $question_id, $user_id);
$question_stmt->execute();
$question = $question_stmt->get_result()->fetch_assoc();
$question_stmt->close();

if (!$question) {
    header("Location: classes.php?message=Question not found or access denied");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update question
    if (isset($_POST['update_question'])) {
        $question_text = trim($_POST['question_text']);
        $points = intval($_POST['points']);

        $update_sql = "UPDATE questions SET question_text = ?, points = ? WHERE question_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sii", $question_text, $points, $question_id);

        if ($update_stmt->execute()) {
            $question_message = "Question updated successfully!";
            $question_message_type = 'success';
            // Refresh question data
            $question['question_text'] = $question_text;
            $question['points'] = $points;
        } else {
            $question_message = "Error updating question: " . $conn->error;
            $question_message_type = 'error';
        }
        $update_stmt->close();
    }

    // Add new option
    if (isset($_POST['add_option'])) {
        $option_text = trim($_POST['option_text']);
        $is_correct = isset($_POST['is_correct']) ? 1 : 0;

        $insert_sql = "INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("isi", $question_id, $option_text, $is_correct);

        if ($insert_stmt->execute()) {
            $option_message = "Option added successfully!";
            $option_message_type = 'success';
            $new_option_id = $conn->insert_id;

            // If this is the correct answer, ensure no other options are marked correct
            if ($is_correct) {
                $update_sql = "UPDATE options SET is_correct = 0 
                                 WHERE question_id = ? AND option_id != ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $question_id, $new_option_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
        } else {
            $option_message = "Error adding option: " . $conn->error;
            $option_message_type = 'error';
        }
        $insert_stmt->close();
    }

    // Update existing option
    if (isset($_POST['update_option'])) {
        $option_id = intval($_POST['option_id']);
        $option_text = trim($_POST['option_text']);
        $is_correct = isset($_POST['is_correct']) ? 1 : 0;

        $update_sql = "UPDATE options SET option_text = ?, is_correct = ? WHERE option_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sii", $option_text, $is_correct, $option_id);

        if ($update_stmt->execute()) {
            $option_message = "Option updated successfully!";
            $option_message_type = 'success';

            // If this is now the correct answer, ensure no other options are marked correct
            if ($is_correct) {
                $update_sql = "UPDATE options SET is_correct = 0 
                                 WHERE question_id = ? AND option_id != ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $question_id, $option_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
        } else {
            $option_message = "Error updating option: " . $conn->error;
            $option_message_type = 'error';
        }
    }
}

// Handle deletions
if (isset($_GET['delete_option'])) {
    $option_id = intval($_GET['delete_option']);
    $message = '';

    // Verify option belongs to this question
    $verify_sql = "SELECT option_id FROM options WHERE option_id = ? AND question_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $option_id, $question_id);
    $verify_stmt->execute();
    $verify_stmt->store_result();

    if ($verify_stmt->num_rows > 0) {
        // Delete the option
        $delete_sql = "DELETE FROM options WHERE option_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $option_id);

        if ($delete_stmt->execute()) {
            $message = "Option deleted successfully!";
        } else {
            $message = "Error deleting option: " . $conn->error;
        }
        $delete_stmt->close();
    }
    $verify_stmt->close();

    header("Location: edit-question.php?id=$question_id&message=" . urlencode($message) . "#options-section");
    exit();
}

// Handle edit mode toggle
$editing_option = isset($_GET['edit_option']) ? intval($_GET['edit_option']) : null;

// Get message from URL if present (primarily for delete redirect)
if (isset($_GET['message'])) {
    $option_message = $_GET['message'];
    if (strpos($_GET['message'], 'successfully') !== false) {
        $option_message_type = 'success';
    } else {
        $option_message_type = 'error';
    }
}

// Get all options for this question
$options_sql = "SELECT option_id, option_text, is_correct 
                 FROM options 
                 WHERE question_id = ?
                 ORDER BY option_id";
$options_stmt = $conn->prepare($options_sql);
$options_stmt->bind_param("i", $question_id);
$options_stmt->execute();
$options = $options_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$options_stmt->close();

// Get teacher info for header
$teacher_sql = "SELECT name FROM users WHERE user_id = ?";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $user_id);
$teacher_stmt->execute();
$teacher_stmt->bind_result($full_name);
$teacher_stmt->fetch();
$teacher_stmt->close();

// Generate initials
$initials = '';
$parts = explode(' ', $full_name);
foreach ($parts as $p) {
    $initials .= strtoupper($p[0]);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Question | Smart Quiz Portal</title>
    <link rel="stylesheet" href="../../css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="stylesheet" href="styles/sidebar.css" />
    <style>
        /* Enhanced Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 22px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            background: rgba(255, 255, 255, 0.12);
        }

        .stat-card i {
            font-size: 1.6rem;
            margin-bottom: 15px;
            color: var(--accent);
        }

        .stat-card h3 {
            font-size: 2.2rem;
            margin-bottom: 5px;
            color: white;
        }

        .stat-card p {
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.95rem;
        }

        /* Classes Table */
        .classes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        .classes-table th,
        .classes-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .classes-table th {
            background: rgba(108, 92, 231, 0.3);
            color: white;
            font-weight: 500;
        }

        .classes-table tr:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .class-code {
            font-family: monospace;
            letter-spacing: 1px;
            color: var(--accent);
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .view-btn {
            background: rgba(108, 92, 231, 0.2);
            color: white;
        }

        .view-btn:hover {
            background: rgba(108, 92, 231, 0.4);
        }

        /* Add Class Form */
        .add-class-form {
            background: rgba(255, 255, 255, 0.08);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.9);
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            color: white;
            font-size: 1rem;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .submit-btn {
            background: var(--primary);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .submit-btn:hover {
            background: var(--primary-dark);
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.2);
            border: 1px solid rgba(46, 204, 113, 0.3);
            color: #2ecc71;
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: #e74c3c;
        }

        /* Header styles */
        header {
            padding: 5px 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            background-color: rgba(26, 26, 46, 0.9);
            backdrop-filter: blur(10px);
            z-index: 100;
        }

        .header-user {
            position: relative;
            display: inline-block;
        }

        .user-dropdown-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: transparent;
            border: none;
            color: white;
            cursor: pointer;
            padding: 8px 15px;
            border-radius: 50px;
            transition: all 0.3s ease;
        }

        .user-dropdown-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 80%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: bold;
        }

        .user-dropdown {
            position: absolute;
            right: 0;
            top: 100%;
            background: rgba(26, 26, 46, 0.98);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 10px 0;
            min-width: 200px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            display: none;
        }

        .user-dropdown.show {
            display: block;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .dropdown-item:hover {
            background: rgba(108, 92, 231, 0.2);
            color: white;
        }

        .dropdown-item i {
            width: 20px;
            text-align: center;
        }

        .dropdown-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 8px 0;
        }

        .quiz-header {
            background: rgba(108, 92, 231, 0.1);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border: 1px solid rgba(108, 92, 231, 0.3);
        }

        .section-title {
            margin-top: 2rem;
            margin-bottom: 1rem;
            font-size: 1.3rem;
            color: var(--accent);
        }

        .card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            flex: 1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.9);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            color: white;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .submit-btn {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .submit-btn:hover {
            background: var(--primary-dark);
        }

        .question-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            /* Added margin-bottom for spacing */
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .question-text {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .question-points {
            color: var(--accent);
            font-size: 0.9rem;
        }

        .options-list {
            margin-top: 1rem;
            /* Removed padding-left to align with card padding */
        }

        .option-item {
            display: flex;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.1);
            margin-bottom: 0.5rem;
            /* Added margin-bottom for spacing */
        }

        .option-text {
            flex: 1;
            margin-left: 10px;
        }

        .correct-option {
            color: #2ecc71;
            margin-left: 20px;
            margin-right: 20px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .edit-btn {
            background: rgba(108, 92, 231, 0.2);
            color: white;
        }

        .delete-btn {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }

        .edit-btn:hover {
            background: rgba(108, 92, 231, 0.4);
        }

        .delete-btn:hover {
            background: rgba(231, 76, 60, 0.4);
        }

        .empty-message {
            color: rgba(255, 255, 255, 0.5);
            font-style: italic;
            padding: 1rem;
            text-align: center;
        }

        .correct-checkbox {
            margin-right: 10px;
        }

        /* Removed .add-option-form specific styles */
        .add-option-section {
            /* New class for the add option section within the card */
            /* Removed margin-top, padding-top, border-top as it's now in its own card */
        }

        .question-header {
            background: rgba(108, 92, 231, 0.1);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border: 1px solid rgba(108, 92, 231, 0.3);
        }

        .breadcrumb {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .breadcrumb a {
            color: var(--accent);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .section-title {
            margin-top: 2rem;
            margin-bottom: 1rem;
            font-size: 1.3rem;
            color: var(--accent);
        }

        .card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            /* This will provide spacing between cards */
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.9);
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            color: white;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .submit-btn {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .submit-btn:hover {
            background: var(--primary-dark);
        }

        .edit-form {
            background: rgba(255, 255, 255, 0.05);
            padding: 1.5rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            border: 1px solid rgba(108, 92, 231, 0.3);
        }

        .edit-form .form-row {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .edit-form .form-group {
            flex-grow: 1;
            margin-bottom: 0;
        }

        .edit-form .correct-answer-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .edit-form .correct-answer-group label {
            margin-bottom: 0;
            white-space: nowrap;
            cursor: pointer;
        }

        .edit-form .correct-checkbox {
            width: 18px;
            height: 18px;
            margin-right: 0;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }

        .edit-actions {
            display: flex;
            gap: 10px;
            margin-top: 1rem;
        }

        .cancel-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <div class="particles" id="particles-js"></div>

    <div class="container-dashboard" style="padding: 1px">
        <!-- Header (same as classes.php) -->
        <header>
            <div class="logo">
                <h1>Smart Quiz Portal</h1>
            </div>

            <div class="auth-buttons">
                <div class="header-user">
                    <button class="user-dropdown-btn" id="userDropdownBtn">
                        <div class="user-avatar"><?php echo $initials; ?></div>
                        <span style="font-size: 1rem"><?php echo htmlspecialchars($full_name); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-dropdown" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="../../auth/logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-container">
            <!-- Sidebar (same as classes.php) -->
            <aside class="sidebar">
                <div class="sidebar-header">
                    <div class="teacher-profile">
                        <div class="teacher-avatar"><?php echo $initials; ?></div>
                        <div class="teacher-info">
                            <h3><?php echo htmlspecialchars($full_name); ?></h3>
                            <p>Teacher</p>
                        </div>
                    </div>
                </div>

                <nav class="sidebar-menu">
                    <a href="teacher.php" class="menu-item">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="classes.php" class="menu-item">
                        <i class="fas fa-users"></i> My Classes
                    </a>
                    <a href="quizzes.php" class="menu-item">
                        <i class="fas fa-question-circle"></i> Quizzes
                    </a>
                    <a href="create-quiz.php" class="menu-item">
                        <i class="fas fa-plus-circle"></i> Create Quiz
                    </a>
                    <a href="results.php" class="menu-item">
                        <i class="fas fa-chart-bar"></i> Results
                    </a>
                </nav>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <div class="breadcrumb">
                    <a href="classes.php">My Classes</a> &gt;
                    <a href="class-details.php?id=<?php echo $question['class_id']; ?>"><?php echo htmlspecialchars($question['class_name']); ?></a> &gt;
                    <a href="edit-quiz.php?id=<?php echo $question['quiz_id']; ?>"><?php echo htmlspecialchars($question['quiz_title']); ?></a> &gt;
                    Edit Question
                </div>
                <div class="question-header">

                    <h1>Edit Question <span class="question-points">(<?php echo $question['points']; ?> point(s))</span></h1>
                </div>

                <?php if (isset($question_message)): ?>
                    <div class="alert alert-<?php echo $question_message_type; ?>" id="question-alert">
                        <?php echo htmlspecialchars($question_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Edit Question Form -->
                <div class="card" id="question-section">
                    <form method="POST" action="?id=<?php echo $question_id; ?>#question-alert">
                        <div class="form-group">
                            <label for="question_text">Question Text</label>
                            <textarea id="question_text" name="question_text" required><?php echo htmlspecialchars($question['question_text']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="points">Points</label>
                            <input type="number" id="points" name="points" min="1" value="<?php echo $question['points']; ?>">
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="update_question" class="submit-btn">
                                <i class="fas fa-save"></i> Save Question
                            </button>
                            <a href="edit-quiz.php?id=<?php echo $question['quiz_id']; ?>" class="cancel-btn">
                                <i class="fas fa-times"></i> Back to Quiz
                            </a>
                        </div>
                    </form>
                </div>

                <?php if (isset($option_message)): ?>
                    <div class="alert alert-<?php echo $option_message_type; ?>" id="option-alert">
                        <?php echo htmlspecialchars($option_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Options Section -->
                <h2 class="section-title" id="options-section">Options (<?php echo count($options); ?>)</h2>

                <div class="card">
                    <?php if (!empty($options)): ?>
                        <div class="options-list">
                            <?php foreach ($options as $option): ?>
                                <?php if ($editing_option === $option['option_id']): ?>
                                    <!-- Edit Option Form -->
                                    <div class="edit-form">
                                        <form method="POST" action="?id=<?php echo $question_id; ?>#options-section">
                                            <input type="hidden" name="option_id" value="<?php echo $option['option_id']; ?>">
                                            <div class="form-row">
                                                <div class="form-group">
                                                    <label for="edit_option_text_<?php echo $option['option_id']; ?>" class="sr-only">Option Text</label>
                                                    <input type="text" id="edit_option_text_<?php echo $option['option_id']; ?>" name="option_text" value="<?php echo htmlspecialchars($option['option_text']); ?>" required>
                                                </div>
                                                <div class="correct-answer-group">
                                                    <input type="checkbox" id="edit_is_correct_<?php echo $option['option_id']; ?>" name="is_correct" class="correct-checkbox" <?php echo $option['is_correct'] ? 'checked' : ''; ?>>
                                                    <label for="edit_is_correct_<?php echo $option['option_id']; ?>">Correct Answer</label>
                                                </div>
                                            </div>
                                            <div class="edit-actions">
                                                <button type="submit" name="update_option" class="submit-btn">
                                                    <i class="fas fa-save"></i> Update Option
                                                </button>
                                                <a href="edit-question.php?id=<?php echo $question_id; ?>#options-section" class="cancel-btn">
                                                    <i class="fas fa-times"></i> Cancel
                                                </a>
                                            </div>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <!-- Display Option -->
                                    <div class="option-item">
                                        <span class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></span>
                                        <?php if ($option['is_correct']): ?>
                                            <span class="correct-option"><i class="fas fa-check-circle"></i> Correct</span>
                                        <?php endif; ?>
                                        <div class="action-buttons">
                                            <a href="edit-question.php?id=<?php echo $question_id; ?>&edit_option=<?php echo $option['option_id']; ?>#options-section" class="action-btn edit-btn">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="edit-question.php?id=<?php echo $question_id; ?>&delete_option=<?php echo $option['option_id']; ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this option?');">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty-message">No options added yet. Add one below!</p>
                    <?php endif; ?>
                </div>

                <!-- Add New Option Form (now in its own card) -->
                <div class="card">
                    <h2 class="section-title" style="margin-top:0; margin-bottom: 1rem;">Add New Option</h2>
                    <form method="POST" action="?id=<?php echo $question_id; ?>#options-section">
                        <div class="form-group">
                            <label for="option_text_new">Option Text</label>
                            <input type="text" id="option_text_new" name="option_text" required placeholder="Enter option text">
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 5px; cursor:pointer;">
                                <input type="checkbox" name="is_correct" class="correct-checkbox" style="width: 18px; height: 18px;">
                                Mark as correct answer
                            </label>
                        </div>
                        <button type="submit" name="add_option" class="submit-btn">
                            <i class="fas fa-plus"></i> Add Option
                        </button>
                    </form>
                </div>

                <!-- Back to Quiz Button -->
                <div style="margin-top: 2rem;">
                    <a href="edit-quiz.php?id=<?php echo $question['quiz_id']; ?>" class="submit-btn" style="display: inline-block;">
                        <i class="fas fa-arrow-left"></i> Back to Quiz
                    </a>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Particles.js initialization
        document.addEventListener("DOMContentLoaded", function() {
            particlesJS("particles-js", {
                particles: {
                    number: {
                        value: 80,
                        density: {
                            enable: true,
                            value_area: 800
                        }
                    },
                    color: {
                        value: "#6c5ce7"
                    }, // Changed to primary color for consistency
                    shape: {
                        type: "circle"
                    },
                    opacity: {
                        value: 0.5
                    },
                    size: {
                        value: 3,
                        random: true
                    },
                    line_linked: {
                        enable: true,
                        distance: 150,
                        color: "#a29bfe", // Changed to accent color for consistency
                        opacity: 0.4,
                        width: 1,
                    },
                    move: {
                        enable: true,
                        speed: 2,
                        direction: "none"
                    },
                },
                interactivity: {
                    detect_on: "canvas",
                    events: {
                        onhover: {
                            enable: true,
                            mode: "grab"
                        },
                        onclick: {
                            enable: true,
                            mode: "push"
                        },
                    },
                },
            });

            // User dropdown functionality
            const dropdownBtn = document.getElementById("userDropdownBtn");
            const dropdownMenu = document.getElementById("userDropdown");

            dropdownBtn.addEventListener("click", function(e) {
                e.stopPropagation();
                dropdownMenu.classList.toggle("show");
            });

            // Close dropdown when clicking outside
            document.addEventListener("click", function() {
                if (dropdownMenu.classList.contains("show")) {
                    dropdownMenu.classList.remove("show");
                }
            });

            // Scroll to message on page load if message exists
            const questionAlert = document.getElementById('question-alert');
            const optionAlert = document.getElementById('option-alert');

            if (questionAlert) {
                questionAlert.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            } else if (optionAlert) {
                optionAlert.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>