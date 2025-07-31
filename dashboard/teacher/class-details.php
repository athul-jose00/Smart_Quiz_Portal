<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$class_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Verify the teacher owns this class
$verify_sql = "SELECT c.class_id, c.class_name, c.class_code 
               FROM classes c 
               WHERE c.class_id = ? AND c.teacher_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param("ii", $class_id, $user_id);
$verify_stmt->execute();
$class = $verify_stmt->get_result()->fetch_assoc();
$verify_stmt->close();

if (!$class) {
    header("Location: classes.php?message=Class not found or access denied");
    exit();
}

// Handle new quiz creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quiz_title'])) {
    $quiz_title = trim($_POST['quiz_title']);
    $time_limit = intval($_POST['time_limit']);

    $insert_sql = "INSERT INTO quizzes (title, time_limit, created_by, class_id) 
                   VALUES (?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("siii", $quiz_title, $time_limit, $user_id, $class_id);

    if ($insert_stmt->execute()) {
        $success_message = "Quiz created successfully!";
    } else {
        $error_message = "Error creating quiz: " . $conn->error;
    }
    $insert_stmt->close();
}

// Handle quiz deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['quiz_id'])) {
    $quiz_id = intval($_GET['quiz_id']);

    // Verify the quiz belongs to this class and teacher
    $verify_quiz_sql = "SELECT q.quiz_id FROM quizzes q 
                        WHERE q.quiz_id = ? AND q.class_id = ? AND q.created_by = ?";
    $verify_quiz_stmt = $conn->prepare($verify_quiz_sql);
    $verify_quiz_stmt->bind_param("iii", $quiz_id, $class_id, $user_id);
    $verify_quiz_stmt->execute();
    $quiz_result = $verify_quiz_stmt->get_result();
    $verify_quiz_stmt->close();

    if ($quiz_result->num_rows > 0) {
        try {
            // Start transaction for safer deletion
            $conn->begin_transaction();

            // First delete responses (they reference options, questions, and quizzes)
            $conn->query("DELETE FROM responses WHERE quiz_id = $quiz_id");

            // Delete results
            $conn->query("DELETE FROM results WHERE quiz_id = $quiz_id");

            // Get all question IDs for this quiz
            $question_ids_result = $conn->query("SELECT question_id FROM questions WHERE quiz_id = $quiz_id");
            if ($question_ids_result && $question_ids_result->num_rows > 0) {
                while ($row = $question_ids_result->fetch_assoc()) {
                    $question_id = $row['question_id'];
                    // Delete options for each question
                    $conn->query("DELETE FROM options WHERE question_id = $question_id");
                }
            }

            // Delete questions
            $conn->query("DELETE FROM questions WHERE quiz_id = $quiz_id");

            // Finally delete the quiz itself
            $conn->query("DELETE FROM quizzes WHERE quiz_id = $quiz_id");

            // Commit the transaction
            $conn->commit();
            $success_message = "Quiz deleted successfully!";
        } catch (Exception $e) {
            // Roll back the transaction if something failed
            $conn->rollback();
            $error_message = "Error deleting quiz: " . $e->getMessage();
        }
    } else {
        $error_message = "Quiz not found or you don't have permission to delete it.";
    }
}

// Get enrolled students
$students_sql = "SELECT u.user_id, u.name, u.email 
                 FROM users u
                 JOIN user_classes uc ON u.user_id = uc.user_id
                 WHERE uc.class_id = ? AND u.role = 'student'";
$students_stmt = $conn->prepare($students_sql);
$students_stmt->bind_param("i", $class_id);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

// Get active quizzes
$quizzes_sql = "SELECT quiz_id, title, time_limit, created_at 
                FROM quizzes 
                WHERE class_id = ?
                ORDER BY created_at DESC";
$quizzes_stmt = $conn->prepare($quizzes_sql);
$quizzes_stmt->bind_param("i", $class_id);
$quizzes_stmt->execute();
$quizzes = $quizzes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$quizzes_stmt->close();

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
    <title><?php echo htmlspecialchars($class['class_name']); ?> | Smart Quiz Portal</title>
    <link rel="stylesheet" href="../../css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        body {
            color: rgba(255, 255, 255, 0.9);
        }

        /* Dashboard Layout */
        .dashboard-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            height: 89vh;
        }

        /* Enhanced Sidebar */
        .sidebar {
            background: rgba(20, 20, 40, 0.95);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            padding: 20px 0;
            margin-top: -1px;
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .teacher-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .teacher-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: white;
        }

        .teacher-info h3 {
            margin-bottom: 5px;
            font-size: 1.1rem;
            color: white;
        }

        .teacher-info p {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        /* Improved Menu Items */
        .menu-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .menu-item:hover,
        .menu-item.active {
            background: rgba(108, 92, 231, 0.25);
            color: white;
        }

        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        /* Main Content Improvements */
        .main-content {
            padding: 2rem;
            background: rgba(15, 15, 30, 0.8);
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .page-title h1 {
            font-size: 1.7rem;
            margin: 0 0 0.5rem;
            background: linear-gradient(90deg, var(--accent), var(--primary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }

        .page-title p {
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.95rem;
            margin: 0;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
        }

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

        .class-header {
            background: rgba(108, 92, 231, 0.1);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border: 1px solid rgba(108, 92, 231, 0.3);
        }

        .class-code-display {
            font-family: monospace;
            background: rgba(0, 0, 0, 0.2);
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.9rem;
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

        .student-list,
        .quiz-list {
            display: grid;
            gap: 1rem;
        }

        .student-item,
        .quiz-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .student-info,
        .quiz-info {
            flex: 1;
        }

        .student-name {
            font-weight: 500;
            margin-bottom: 5px;
        }

        .student-email {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .quiz-title {
            font-weight: 500;
            margin-bottom: 5px;
        }

        .quiz-meta {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
            display: flex;
            gap: 15px;
        }

        .empty-message {
            color: rgba(255, 255, 255, 0.5);
            font-style: italic;
            padding: 1rem;
            text-align: center;
        }

        /* Add quiz form */
        .add-quiz-form {
            margin-top: 2rem;
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
        .form-group select {
            width: 100%;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            color: white;
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

        .quiz-actions {
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

        .view-btn {
            background: rgba(108, 92, 231, 0.2);
            color: white;
        }

        .delete-btn {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }

        .view-btn:hover {
            background: rgba(108, 92, 231, 0.4);
        }

        .delete-btn:hover {
            background: rgba(231, 76, 60, 0.4);
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
                <div class="class-header">
                    <h1><?php echo htmlspecialchars($class['class_name']); ?></h1>
                    <p>Class Code: <span class="class-code-display"><?php echo htmlspecialchars($class['class_code']); ?></span></p>
                </div>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Enrolled Students Section -->
                <h2 class="section-title">Enrolled Students (<?php echo count($students); ?>)</h2>

                <div class="card">
                    <?php if (!empty($students)): ?>
                        <div class="student-list">
                            <?php foreach ($students as $student): ?>
                                <div class="student-item">
                                    <div class="student-info">
                                        <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                                        <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-message">No students enrolled in this class yet.</div>
                    <?php endif; ?>
                </div>

                <!-- Active Quizzes Section -->
                <h2 class="section-title">Active Quizzes (<?php echo count($quizzes); ?>)</h2>

                <div class="card">
                    <?php if (!empty($quizzes)): ?>
                        <div class="quiz-list">
                            <?php foreach ($quizzes as $quiz): ?>
                                <div class="quiz-item">
                                    <div class="quiz-info">
                                        <div class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></div>
                                        <div class="quiz-meta">
                                            <span>Time Limit: <?php echo $quiz['time_limit']; ?> mins</span>
                                            <span>Created: <?php echo date('M d, Y', strtotime($quiz['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="quiz-actions">
                                        <a href="quiz-results.php?id=<?php echo $quiz['quiz_id']; ?>" class="action-btn view-btn">
                                            <i class="fas fa-chart-bar"></i> Results
                                        </a>
                                        <a href="edit-quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="action-btn view-btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="class-details.php?id=<?php echo $class_id; ?>&action=delete&quiz_id=<?php echo $quiz['quiz_id']; ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this quiz?');">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-message">No quizzes created for this class yet.</div>
                    <?php endif; ?>
                </div>

                <!-- Add Quiz Form -->
                <div class="card add-quiz-form">
                    <h2 class="section-title">Create New Quiz</h2>

                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="quiz_title">Quiz Title</label>
                                <input type="text" id="quiz_title" name="quiz_title" required placeholder="Enter quiz title">
                            </div>
                            <div class="form-group">
                                <label for="time_limit">Time Limit (minutes)</label>
                                <input type="number" id="time_limit" name="time_limit" min="1" value="30" required>
                            </div>
                        </div>
                        <button type="submit" class="submit-btn">
                            <i class="fas fa-plus"></i> Create Quiz
                        </button>
                    </form>
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
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>