<?php
session_start();
require_once '../../includes/db.php';

// Make sure the user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$class_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$class_id) {
    header("Location: classes.php");
    exit();
}

// Get student's name
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

// Verify student is enrolled in this class and get class details
$class_sql = "SELECT c.class_id, c.class_name, c.class_code, u.name as teacher_name, u.email as teacher_email,
              (SELECT COUNT(*) FROM user_classes uc WHERE uc.class_id = c.class_id) as student_count,
              (SELECT COUNT(*) FROM quizzes q WHERE q.class_id = c.class_id) as total_quizzes
              FROM classes c
              JOIN users u ON c.teacher_id = u.user_id
              JOIN user_classes uc ON c.class_id = uc.class_id
              WHERE c.class_id = ? AND uc.user_id = ?";
$class_stmt = $conn->prepare($class_sql);
$class_stmt->bind_param("ii", $class_id, $user_id);
$class_stmt->execute();
$class_result = $class_stmt->get_result();
$class = $class_result->fetch_assoc();
$class_stmt->close();

if (!$class) {
    header("Location: classes.php?error=Class not found or access denied");
    exit();
}

// Get all quizzes for this class with student's results
$quizzes_sql = "SELECT q.quiz_id, q.title, q.time_limit, q.created_at,
                (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id = q.quiz_id) as question_count,
                r.result_id, r.total_score, r.percentage, r.completed_at
                FROM quizzes q
                LEFT JOIN results r ON q.quiz_id = r.quiz_id AND r.user_id = ?
                WHERE q.class_id = ?
                ORDER BY q.created_at DESC";
$quizzes_stmt = $conn->prepare($quizzes_sql);
$quizzes_stmt->bind_param("ii", $user_id, $class_id);
$quizzes_stmt->execute();
$quizzes_result = $quizzes_stmt->get_result();
$all_quizzes = $quizzes_result->fetch_all(MYSQLI_ASSOC);
$quizzes_stmt->close();

// Separate available and completed quizzes
$available_quizzes = [];
$completed_quizzes = [];
$total_score = 0;
$completed_count = 0;

foreach ($all_quizzes as $quiz) {
    if ($quiz['result_id']) {
        $completed_quizzes[] = $quiz;
        $total_score += $quiz['percentage'];
        $completed_count++;
    } else {
        $available_quizzes[] = $quiz;
    }
}

$average_score = $completed_count > 0 ? round($total_score / $completed_count, 1) : 0;

// Get other students in this class (for community feel)
$classmates_sql = "SELECT u.name, u.user_id
                   FROM user_classes uc
                   JOIN users u ON uc.user_id = u.user_id
                   WHERE uc.class_id = ? AND u.user_id != ? AND u.role = 'student'
                   ORDER BY u.name
                   LIMIT 10";
$classmates_stmt = $conn->prepare($classmates_sql);
$classmates_stmt->bind_param("ii", $class_id, $user_id);
$classmates_stmt->execute();
$classmates_result = $classmates_stmt->get_result();
$classmates = $classmates_result->fetch_all(MYSQLI_ASSOC);
$classmates_stmt->close();
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

        .student-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .student-avatar {
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

        .student-info h3 {
            margin-bottom: 5px;
            font-size: 1.1rem;
            color: white;
        }

        .student-info p {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        /* Menu Items */
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

        /* Main Content */
        .main-content {
            padding: 2rem;
            background: rgba(15, 15, 30, 0.8);
            overflow-y: auto;
        }

        .class-header {
            background: linear-gradient(135deg, rgba(108, 92, 231, 0.2), rgba(253, 121, 168, 0.1));
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .class-title {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, var(--accent), var(--primary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }

        .class-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.8);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.12);
        }

        .stat-card i {
            font-size: 1.8rem;
            margin-bottom: 10px;
            color: var(--accent);
        }

        .stat-card h3 {
            font-size: 2rem;
            margin-bottom: 5px;
            color: white;
        }

        .stat-card p {
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.9rem;
        }

        .section {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .section-title {
            font-size: 1.3rem;
            color: var(--accent);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quiz-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }

        .quiz-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease;
        }

        .quiz-card:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .quiz-title {
            font-size: 1.1rem;
            color: white;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .quiz-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 1rem;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }

        .quiz-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-outline {
            background: transparent;
            color: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn-success {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.3);
        }

        .empty-message {
            text-align: center;
            padding: 2rem;
            color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px dashed rgba(255, 255, 255, 0.1);
        }

        .classmates-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .classmate-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .classmate-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: bold;
            color: white;
        }

        .classmate-name {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
        }
    </style>
</head>

<body>
    <div class="particles" id="particles-js"></div>

    <div class="container-dashboard" style="padding: 1px">
        <!-- Header -->
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
            <!-- Sidebar -->
            <aside class="sidebar">
                <div class="sidebar-header">
                    <div class="student-profile">
                        <div class="student-avatar"><?php echo $initials; ?></div>
                        <div class="student-info">
                            <h3><?php echo htmlspecialchars($full_name); ?></h3>
                            <p>Student</p>
                        </div>
                    </div>
                </div>

                <nav class="sidebar-menu">
                    <a href="student.php" class="menu-item">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="classes.php" class="menu-item active">
                        <i class="fas fa-users"></i> My Classes
                    </a>
                    <a href="quizzes.php" class="menu-item">
                        <i class="fas fa-question-circle"></i> Available Quizzes
                    </a>
                    <a href="results.php" class="menu-item">
                        <i class="fas fa-chart-bar"></i> My Results
                    </a>
                    <a href="join-class.php" class="menu-item">
                        <i class="fas fa-plus-circle"></i> Join Class
                    </a>
                </nav>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Class Header -->
                <div class="class-header">
                    <h1 class="class-title"><?php echo htmlspecialchars($class['class_name']); ?></h1>

                    <div class="class-meta">
                        <div class="meta-item">
                            <i class="fas fa-code"></i>
                            <span>Class Code: <?php echo htmlspecialchars($class['class_code']); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <span><?php echo htmlspecialchars($class['teacher_name']); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($class['teacher_email']); ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-users"></i>
                            <span><?php echo $class['student_count']; ?> students enrolled</span>
                        </div>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <i class="fas fa-clipboard-list"></i>
                        <h3><?php echo $class['total_quizzes']; ?></h3>
                        <p>Total Quizzes</p>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-check-circle"></i>
                        <h3><?php echo $completed_count; ?></h3>
                        <p>Completed</p>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-play-circle"></i>
                        <h3><?php echo count($available_quizzes); ?></h3>
                        <p>Available</p>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $average_score; ?>%</h3>
                        <p>Average Score</p>
                    </div>
                </div>

                <!-- Available Quizzes -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-play-circle"></i>
                        Available Quizzes
                    </h2>

                    <?php if (empty($available_quizzes)): ?>
                        <div class="empty-message">
                            <i class="fas fa-check-circle"></i>
                            <p>All quizzes completed! Great job!</p>
                        </div>
                    <?php else: ?>
                        <div class="quiz-grid">
                            <?php foreach ($available_quizzes as $quiz): ?>
                                <div class="quiz-card">
                                    <div class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></div>
                                    <div class="quiz-meta">
                                        <span><i class="far fa-clock"></i> <?php echo $quiz['time_limit']; ?> mins</span>
                                        <span><i class="far fa-question-circle"></i> <?php echo $quiz['question_count']; ?> questions</span>
                                        <span><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($quiz['created_at'])); ?></span>
                                    </div>
                                    <div class="quiz-actions">
                                        <a href="take-quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-play"></i> Start Quiz
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Completed Quizzes -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-check-circle"></i>
                        Completed Quizzes
                    </h2>

                    <?php if (empty($completed_quizzes)): ?>
                        <div class="empty-message">
                            <i class="fas fa-question-circle"></i>
                            <p>No completed quizzes yet. Start with an available quiz above!</p>
                        </div>
                    <?php else: ?>
                        <div class="quiz-grid">
                            <?php foreach ($completed_quizzes as $quiz): ?>
                                <div class="quiz-card">
                                    <div class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></div>
                                    <div class="quiz-meta">
                                        <span><i class="fas fa-trophy"></i> <?php echo $quiz['percentage']; ?>%</span>
                                        <span><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($quiz['completed_at'])); ?></span>
                                    </div>
                                    <div class="quiz-actions">
                                        <a href="quiz-result.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-outline">
                                            <i class="fas fa-eye"></i> View Results
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Classmates -->
                <?php if (!empty($classmates)): ?>
                    <div class="section">
                        <h2 class="section-title">
                            <i class="fas fa-user-friends"></i>
                            Classmates
                        </h2>

                        <div class="classmates-list">
                            <?php foreach ($classmates as $classmate): ?>
                                <?php
                                $classmate_initials = '';
                                $parts = explode(' ', $classmate['name']);
                                foreach ($parts as $p) {
                                    $classmate_initials .= strtoupper($p[0]);
                                }
                                ?>
                                <div class="classmate-item">
                                    <div class="classmate-avatar"><?php echo $classmate_initials; ?></div>
                                    <div class="classmate-name"><?php echo htmlspecialchars($classmate['name']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Particles.js and dropdown functionality
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