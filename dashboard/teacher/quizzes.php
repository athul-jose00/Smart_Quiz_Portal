<?php
session_start();
require_once '../../includes/db.php';

// Verify user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$quiz_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Handle quiz deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['quiz_id'])) {
    $quiz_id = intval($_GET['quiz_id']);

    // Verify the quiz belongs to this teacher
    $verify_quiz_sql = "SELECT q.quiz_id FROM quizzes q 
                        WHERE q.quiz_id = ? AND q.created_by = ?";
    $verify_quiz_stmt = $conn->prepare($verify_quiz_sql);
    $verify_quiz_stmt->bind_param("ii", $quiz_id, $user_id);
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

// Get teacher's name for the sidebar
$sql = "SELECT name FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($full_name);
$stmt->fetch();
$stmt->close();

// Generate initials
$initials = '';
$parts = explode(' ', $full_name);
foreach ($parts as $p) {
    $initials .= strtoupper($p[0]);
}

// Get all classes taught by this teacher
$classes = [];
$sql = "SELECT c.class_id, c.class_name, c.class_code 
        FROM classes c 
        WHERE c.teacher_id = ? 
        ORDER BY c.class_name";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $classes[$row['class_id']] = $row;
}
$stmt->close();

// Get quizzes for each class
foreach ($classes as &$class) {
    $sql = "SELECT q.quiz_id, q.title, q.time_limit, q.created_at, 
                   COUNT(qu.question_id) as question_count
            FROM quizzes q
            LEFT JOIN questions qu ON q.quiz_id = qu.quiz_id
            WHERE q.class_id = ?
            GROUP BY q.quiz_id
            ORDER BY q.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $class['class_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $class['quizzes'] = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
unset($class); // Break the reference
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Quizzes | Smart Quiz Portal</title>
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

        .page-title h1 {
            font-size: 1.8rem;
            margin-bottom: 2rem;
            background: linear-gradient(90deg, var(--accent), var(--primary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }

        .page-title p {
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.95rem;
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

        /* Improved Header with Dropdown */
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

        .quiz-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .quiz-container h2 {
            margin-top: 0;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quiz-container h2 .class-code {
            font-size: 0.8rem;
            background: rgba(108, 92, 231, 0.2);
            padding: 3px 8px;
            border-radius: 20px;
            color: var(--primary);
        }

        .quizzes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .quiz-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .quiz-card:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.12);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .quiz-card h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: white;
        }

        .quiz-meta {
            display: flex;
            justify-content: space-between;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }

        .quiz-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        /* Ensure all buttons have proper icon alignment */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: .85rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px dashed rgba(255, 255, 255, 0.1);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 15px;
            color: rgba(255, 255, 255, 0.3);
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

        .btn-delete {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .btn-delete:hover {
            background: rgba(231, 76, 60, 0.4);
        }
    </style>
</head>

<body>
    <div class="particles" id="particles-js"></div>
    <div class="container-dashboard" style="padding: 1px">
        <!-- Header (same as dashboard) -->
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
            <!-- Sidebar (same as dashboard) -->
            <aside class="sidebar">
                <div class="sidebar-header">
                    <div class="teacher-profile">
                        <div class="teacher-avatar"><?php echo $initials; ?></div>
                        <div class="teacher-info">
                            <h3><?php echo htmlspecialchars($full_name); ?></h3>
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
                    <a href="quizzes.php" class="menu-item active">
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
                <div class="dashboard-header">
                    <div class="page-title">
                        <h1>My Quizzes</h1>
                        <p>Manage all quizzes for your classes</p>
                    </div>
                    <div class="quick-actions">
                        <a href="create-quiz.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Quiz
                        </a>
                    </div>
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

                <?php if (empty($classes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book-open"></i>
                        <h3>No Classes Found</h3>
                        <p>You don't have any classes assigned yet. Create a class to start adding quizzes.</p>
                        <a href="classes.php" class="btn btn-primary mt-3">
                            <i class="fas fa-plus"></i> Create Class
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($classes as $class): ?>
                        <div class="quiz-container">
                            <h2>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                                <span class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></span>
                            </h2>

                            <?php if (empty($class['quizzes'])): ?>
                                <div class="empty-state" style="padding: 20px; margin-top: 15px;">
                                    <i class="fas fa-question-circle"></i>
                                    <p>No quizzes created for this class yet</p>
                                    <a href="create-quiz.php?class_id=<?php echo $class['class_id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-plus"></i> Create Quiz
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="quizzes-grid">
                                    <?php foreach ($class['quizzes'] as $quiz): ?>
                                        <div class="quiz-card">
                                            <h3><?php echo htmlspecialchars($quiz['title']); ?></h3>
                                            <div class="quiz-meta">
                                                <span>
                                                    <i class="far fa-clock"></i>
                                                    <?php echo htmlspecialchars($quiz['time_limit']); ?> mins
                                                </span>
                                                <span>
                                                    <i class="far fa-question-circle"></i>
                                                    <?php echo htmlspecialchars($quiz['question_count']); ?> questions
                                                </span>
                                            </div>
                                            <div class="quiz-meta">
                                                <span>
                                                    <i class="far fa-calendar-alt"></i>
                                                    <?php echo date('M d, Y', strtotime($quiz['created_at'])); ?>
                                                </span>
                                            </div>
                                            <div class="quiz-actions">
                                                <a href="edit-quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="results.php?class_id=<?php echo $class['class_id']; ?>&quiz_id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-sm btn-outline">
                                                    <i class="fas fa-chart-bar"></i> Results
                                                </a>
                                                <a href="quizzes.php?action=delete&quiz_id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-sm btn-delete" onclick="return confirm('Are you sure you want to delete this quiz?');">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Particles.js initialization (same as dashboard)
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