<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$quiz_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get quiz details and verify ownership
$quiz_sql = "SELECT q.quiz_id, q.title, q.time_limit, q.class_id, c.class_name 
             FROM quizzes q
             JOIN classes c ON q.class_id = c.class_id
             WHERE q.quiz_id = ? AND q.created_by = ?";
$quiz_stmt = $conn->prepare($quiz_sql);
$quiz_stmt->bind_param("ii", $quiz_id, $user_id);
$quiz_stmt->execute();
$quiz = $quiz_stmt->get_result()->fetch_assoc();
$quiz_stmt->close();

if (!$quiz) {
    header("Location: classes.php?message=Quiz not found or access denied");
    exit();
}

// Initialize message variables
$quiz_message_success = '';
$quiz_message_error = '';
$question_add_message_success = '';
$question_add_message_error = '';
$option_action_message_success = '';
$option_action_message_error = '';
$scroll_to_question_id = 0; // Initialize a variable to hold the question ID to scroll to

// Handle quiz update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update quiz details
    if (isset($_POST['update_quiz'])) {
        $title = trim($_POST['title']);
        $time_limit = intval($_POST['time_limit']);

        $update_sql = "UPDATE quizzes SET title = ?, time_limit = ? WHERE quiz_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sii", $title, $time_limit, $quiz_id);

        if ($update_stmt->execute()) {
            $quiz_message_success = "Quiz updated successfully!";
            // Refresh quiz data
            $quiz['title'] = $title;
            $quiz['time_limit'] = $time_limit;
            header("Location: edit-quiz.php?id=$quiz_id&status=success&type=quiz_update&message=" . urlencode($quiz_message_success));
            exit();
        } else {
            $quiz_message_error = "Error updating quiz: " . $conn->error;
            header("Location: edit-quiz.php?id=$quiz_id&status=error&type=quiz_update&message=" . urlencode($quiz_message_error));
            exit();
        }
        $update_stmt->close();
    }

    // Add new question
    if (isset($_POST['add_question'])) {
        $question_text = trim($_POST['question_text']);
        $points = intval($_POST['points']);

        $insert_sql = "INSERT INTO questions (quiz_id, question_text, points) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("isi", $quiz_id, $question_text, $points);

        if ($insert_stmt->execute()) {
            $question_add_message_success = "Question added successfully!";
            // Redirect to refresh the page and display the new question, scroll to it
            header("Location: edit-quiz.php?id=$quiz_id&status=success&type=add_question&message=" . urlencode($question_add_message_success));
            exit();
        } else {
            $question_add_message_error = "Error adding question: " . $conn->error;
            header("Location: edit-quiz.php?id=$quiz_id&status=error&type=add_question&message=" . urlencode($question_add_message_error));
            exit();
        }
        $insert_stmt->close();
    }

    // Add option to question
    if (isset($_POST['add_option'])) {
        $question_id = intval($_POST['question_id']);
        $option_text = trim($_POST['option_text']);
        $is_correct = isset($_POST['is_correct']) ? 1 : 0;

        $insert_sql = "INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("isi", $question_id, $option_text, $is_correct);

        if ($insert_stmt->execute()) {
            $option_action_message_success = "Option added successfully!";
            $scroll_to_question_id = $question_id; // Set scroll target to the question where option was added

            // If this is the correct answer, ensure no other options are marked correct
            if ($is_correct) {
                $update_sql = "UPDATE options SET is_correct = 0 
                               WHERE question_id = ? AND option_id != ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $question_id, $conn->insert_id);
                $update_stmt->execute();
                $update_stmt->close();
            }
            header("Location: edit-quiz.php?id=$quiz_id&status=success&type=option_action&message=" . urlencode($option_action_message_success) . "&scroll_to_question=" . $scroll_to_question_id);
            exit();
        } else {
            $option_action_message_error = "Error adding option: " . $conn->error;
            $scroll_to_question_id = $question_id; // Still scroll to the question even on error
            header("Location: edit-quiz.php?id=$quiz_id&status=error&type=option_action&message=" . urlencode($option_action_message_error) . "&scroll_to_question=" . $scroll_to_question_id);
            exit();
        }
        $insert_stmt->close();
    }
}

// Handle deletions (GET requests)
if (isset($_GET['delete_question'])) {
    $question_id = intval($_GET['delete_question']);

    // Verify question belongs to this quiz
    $verify_sql = "SELECT question_id FROM questions WHERE question_id = ? AND quiz_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $question_id, $quiz_id);
    $verify_stmt->execute();
    $verify_stmt->store_result();

    if ($verify_stmt->num_rows > 0) {
        // Delete the question (cascade will delete options)
        $delete_sql = "DELETE FROM questions WHERE question_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $question_id);

        if ($delete_stmt->execute()) {
            $question_delete_message_success = "Question deleted successfully!";
            header("Location: edit-quiz.php?id=$quiz_id&status=success&type=delete_question&message=" . urlencode($question_delete_message_success));
            exit();
        } else {
            $question_delete_message_error = "Error deleting question: " . $conn->error;
            header("Location: edit-quiz.php?id=$quiz_id&status=error&type=delete_question&message=" . urlencode($question_delete_message_error));
            exit();
        }
        $delete_stmt->close();
    } else {
        $question_delete_message_error = "Question not found or access denied.";
        header("Location: edit-quiz.php?id=$quiz_id&status=error&type=delete_question&message=" . urlencode($question_delete_message_error));
        exit();
    }
    $verify_stmt->close();
}

if (isset($_GET['delete_option'])) {
    $option_id = intval($_GET['delete_option']);
    $question_id_for_option_action = 0; // Initialize for redirection

    // Verify option belongs to this quiz and get its question_id
    $verify_sql = "SELECT o.option_id, q.question_id 
                     FROM options o
                     JOIN questions q ON o.question_id = q.question_id
                     WHERE o.option_id = ? AND q.quiz_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $option_id, $quiz_id);
    $verify_stmt->execute();
    $verify_stmt->bind_result($option_id_found, $question_id_for_option_action);
    $verify_stmt->fetch();
    $verify_stmt->close();

    if ($option_id_found) {
        // Delete the option
        $delete_sql = "DELETE FROM options WHERE option_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $option_id);

        if ($delete_stmt->execute()) {
            $option_action_message_success = "Option deleted successfully!";
            header("Location: edit-quiz.php?id=$quiz_id&status=success&type=option_action&message=" . urlencode($option_action_message_success) . "&scroll_to_question=" . $question_id_for_option_action);
            exit();
        } else {
            $option_action_message_error = "Error deleting option: " . $conn->error;
            header("Location: edit-quiz.php?id=$quiz_id&status=error&type=option_action&message=" . urlencode($option_action_message_error) . "&scroll_to_question=" . $question_id_for_option_action);
            exit();
        }
        $delete_stmt->close();
    } else {
        $option_action_message_error = "Option not found or access denied.";
        header("Location: edit-quiz.php?id=$quiz_id&status=error&type=option_action&message=" . urlencode($option_action_message_error) . "&scroll_to_question=" . $question_id_for_option_action);
        exit();
    }
}


// Get message from URL if present (after a redirect)
if (isset($_GET['message'])) {
    $message_status = $_GET['status'] ?? '';
    $message_type = $_GET['type'] ?? '';
    $message_text = $_GET['message'];

    if ($message_status === 'success') {
        if ($message_type === 'quiz_update') {
            $quiz_message_success = $message_text;
        } elseif ($message_type === 'add_question') {
            $question_add_message_success = $message_text;
        } elseif ($message_type === 'option_action') {
            $option_action_message_success = $message_text;
        } elseif ($message_type === 'delete_question') {
            $question_add_message_success = $message_text; // Re-using for general question success
        }
    } elseif ($message_status === 'error') {
        if ($message_type === 'quiz_update') {
            $quiz_message_error = $message_text;
        } elseif ($message_type === 'add_question') {
            $question_add_message_error = $message_text;
        } elseif ($message_type === 'option_action') {
            $option_action_message_error = $message_text;
        } elseif ($message_type === 'delete_question') {
            $question_add_message_error = $message_text; // Re-using for general question error
        }
    }
}

// Get scroll target from URL if present (after a redirect)
if (isset($_GET['scroll_to_question'])) {
    $scroll_to_question_id = intval($_GET['scroll_to_question']);
}

// Get all questions for this quiz
$questions_sql = "SELECT question_id, question_text, points 
                  FROM questions 
                  WHERE quiz_id = ?
                  ORDER BY question_id";
$questions_stmt = $conn->prepare($questions_sql);
$questions_stmt->bind_param("i", $quiz_id);
$questions_stmt->execute();
$questions = $questions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$questions_stmt->close();

// Get options for each question
foreach ($questions as &$question) {
    $options_sql = "SELECT option_id, option_text, is_correct 
                    FROM options 
                    WHERE question_id = ?
                    ORDER BY option_id";
    $options_stmt = $conn->prepare($options_sql);
    $options_stmt->bind_param("i", $question['question_id']);
    $options_stmt->execute();
    $question['options'] = $options_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $options_stmt->close();
}
unset($question); // Break the reference with the last element

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
    <title>Edit <?php echo htmlspecialchars($quiz['title']); ?> | Smart Quiz Portal</title>
    <link rel="stylesheet" href="../../css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="stylesheet" href="styles/sidebar.css" />
    <style>
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
            padding-left: 1.5rem;
        }

        .option-item {
            display: flex;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.1);
        }

        .option-text {
            flex: 1;
            margin-left: 10px;
        }

        .correct-option {
            color: #2ecc71;
            margin-left: 10px;
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

        .add-option-form {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px dashed rgba(255, 255, 255, 0.1);
        }

        .scroll-target {
            /* Adjust this value based on your fixed header height if needed */
            scroll-margin-top: 20px;
        }

        /* Position alerts relative to their containers */
        .question-alert {
            margin-bottom: 1rem;
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
                <div class="quiz-header">
                    <h1>Edit Quiz: <?php echo htmlspecialchars($quiz['title']); ?></h1>
                    <p>Class: <?php echo htmlspecialchars($quiz['class_name']); ?></p>
                </div>

                <!-- Quiz-wide alerts -->
                <?php if (!empty($quiz_message_success)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($quiz_message_success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($quiz_message_error)): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($quiz_message_error); ?>
                    </div>
                <?php endif; ?>

                <!-- Quiz Details Form -->
                <div class="card">
                    <h2 class="section-title">Quiz Details</h2>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="title">Quiz Title</label>
                                <input type="text" id="title" name="title" required
                                    value="<?php echo htmlspecialchars($quiz['title']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="time_limit">Time Limit (minutes)</label>
                                <input type="number" id="time_limit" name="time_limit" min="1"
                                    value="<?php echo $quiz['time_limit']; ?>" required>
                            </div>
                        </div>
                        <button type="submit" name="update_quiz" class="submit-btn">
                            <i class="fas fa-save"></i> Update Quiz
                        </button>
                    </form>
                </div>

                <!-- Add Question Form -->
                <div class="card">
                    <h2 class="section-title">Add New Question</h2>
                    <?php if (!empty($question_add_message_success)): ?>
                        <div class="alert alert-success question-alert">
                            <?php echo htmlspecialchars($question_add_message_success); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($question_add_message_error)): ?>
                        <div class="alert alert-error question-alert">
                            <?php echo htmlspecialchars($question_add_message_error); ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="#question-section">
                        <div class="form-group">
                            <label for="question_text">Question Text</label>
                            <textarea id="question_text" name="question_text" required></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="points">Points</label>
                                <input type="number" id="points" name="points" min="1" value="1">
                            </div>
                        </div>
                        <button type="submit" name="add_question" class="submit-btn">
                            <i class="fas fa-plus"></i> Add Question
                        </button>
                    </form>
                </div>

                <!-- Questions List -->
                <div id="question-section">
                    <h2 class="section-title">Questions (<?php echo count($questions); ?>)</h2>

                    <?php if (!empty($questions)): ?>
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="question-item scroll-target" id="question-<?php echo $question['question_id']; ?>">
                                <?php
                                // Display success/error messages specifically for this question (add/delete option)
                                if ($scroll_to_question_id == $question['question_id']) {
                                    if (!empty($option_action_message_success)) { ?>
                                        <div class="alert alert-success question-alert">
                                            <?php echo htmlspecialchars($option_action_message_success); ?>
                                        </div>
                                    <?php } elseif (!empty($option_action_message_error)) { ?>
                                        <div class="alert alert-error question-alert">
                                            <?php echo htmlspecialchars($option_action_message_error); ?>
                                        </div>
                                <?php }
                                }
                                ?>

                                <div class="question-header">
                                    <div>
                                        <div class="question-text"><?php echo htmlspecialchars($question['question_text']); ?></div>
                                        <div class="question-points"><?php echo $question['points']; ?> point(s)</div>
                                    </div>
                                    <div class="action-buttons">
                                        <a href="edit-question.php?id=<?php echo $question['question_id']; ?>" class="action-btn edit-btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="edit-quiz.php?id=<?php echo $quiz_id; ?>&delete_question=<?php echo $question['question_id']; ?>"
                                            class="action-btn delete-btn"
                                            onclick="return confirm('Are you sure you want to delete this question?')">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </a>
                                    </div>
                                </div>

                                <!-- Options List -->
                                <h3 style="font-size: 1rem; margin: 1rem 0 0.5rem;">Options:</h3>

                                <?php if (!empty($question['options'])): ?>
                                    <ul class="options-list">
                                        <?php foreach ($question['options'] as $option): ?>
                                            <li class="option-item">
                                                <?php if ($option['is_correct']): ?>
                                                    <span class="correct-option"><i class="fas fa-check-circle"></i></span>
                                                <?php endif; ?>
                                                <div class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></div>
                                                <div class="action-buttons">
                                                    <a href="edit-quiz.php?id=<?php echo $quiz_id; ?>&delete_option=<?php echo $option['option_id']; ?>"
                                                        class="action-btn delete-btn"
                                                        onclick="return confirm('Are you sure you want to delete this option?')">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div class="empty-message">No options added yet.</div>
                                <?php endif; ?>

                                <!-- Add Option Form -->
                                <div class="add-option-form">
                                    <form method="POST" action="">
                                        <input type="hidden" name="question_id" value="<?php echo $question['question_id']; ?>">
                                        <div class="form-group">
                                            <label for="option_text_<?php echo $question['question_id']; ?>">Add Option</label>
                                            <div style="display: flex; gap: 10px; align-items: center;">
                                                <input type="text" id="option_text_<?php echo $question['question_id']; ?>"
                                                    name="option_text" required
                                                    style="flex: 1;" placeholder="Enter option text">
                                                <label style="display: flex; align-items: center; gap: 5px;">
                                                    <input type="checkbox" name="is_correct" class="correct-checkbox">
                                                    Correct Answer
                                                </label>
                                            </div>
                                        </div>
                                        <button type="submit" name="add_option" class="submit-btn" style="padding: 8px 15px;">
                                            <i class="fas fa-plus"></i> Add Option
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-message">No questions added yet.</div>
                    <?php endif; ?>
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
                        value: "#ffffff"
                    },
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
                        color: "#ffffff",
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

            // --- Enhanced Scrolling Logic ---
            const scrollToQuestionId = <?php echo $scroll_to_question_id; ?>;
            const messageType = "<?php echo $_GET['type'] ?? ''; ?>"; // Get the message type from URL

            if (messageType === 'add_question') {
                // Scroll to the last question (newly added)
                const questions = document.querySelectorAll('.question-item');
                if (questions.length > 0) {
                    questions[questions.length - 1].scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            } else if (scrollToQuestionId > 0 && messageType === 'option_action') {
                // Scroll to the specific question after option add/delete
                const questionElement = document.getElementById('question-' + scrollToQuestionId);
                if (questionElement) {
                    questionElement.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>