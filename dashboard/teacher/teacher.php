<?php
session_start();
require_once '../../includes/db.php';

// Make sure the user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
  header("Location: ../../auth/login.php");
  exit();
}

$user_id = $_SESSION['user_id'];

// Get teacher's name
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

// Get count of active classes
$classes_sql = "SELECT COUNT(*) as class_count FROM classes WHERE teacher_id = ?";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $user_id);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$class_count = $classes_result->fetch_assoc()['class_count'];
$classes_stmt->close();

// Get count of quizzes created
$quizzes_sql = "SELECT COUNT(*) as quiz_count FROM quizzes WHERE created_by = ?";
$quizzes_stmt = $conn->prepare($quizzes_sql);
$quizzes_stmt->bind_param("i", $user_id);
$quizzes_stmt->execute();
$quizzes_result = $quizzes_stmt->get_result();
$quiz_count = $quizzes_result->fetch_assoc()['quiz_count'];
$quizzes_stmt->close();

// Get recent classes
$recent_classes_sql = "SELECT c.class_id, c.class_name, c.class_code, 
                      (SELECT COUNT(*) FROM user_classes uc WHERE uc.class_id = c.class_id) as student_count
                      FROM classes c 
                      WHERE c.teacher_id = ? 
                      ORDER BY c.class_id DESC LIMIT 5";
$recent_classes_stmt = $conn->prepare($recent_classes_sql);
$recent_classes_stmt->bind_param("i", $user_id);
$recent_classes_stmt->execute();
$recent_classes_result = $recent_classes_stmt->get_result();
$recent_classes = $recent_classes_result->fetch_all(MYSQLI_ASSOC);
$recent_classes_stmt->close();

// Get recent quizzes
$recent_quizzes_sql = "SELECT q.quiz_id, q.title, q.time_limit, q.created_at, c.class_name, c.class_id,
                      (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id = q.quiz_id) as question_count
                      FROM quizzes q
                      JOIN classes c ON q.class_id = c.class_id
                      WHERE q.created_by = ?
                      ORDER BY q.created_at DESC LIMIT 5";
$recent_quizzes_stmt = $conn->prepare($recent_quizzes_sql);
$recent_quizzes_stmt->bind_param("i", $user_id);
$recent_quizzes_stmt->execute();
$recent_quizzes_result = $recent_quizzes_stmt->get_result();
$recent_quizzes = $recent_quizzes_result->fetch_all(MYSQLI_ASSOC);
$recent_quizzes_stmt->close();
?>



<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Teacher Dashboard | Smart Quiz Portal</title>
  <link rel="stylesheet" href="../../css/style.css" />
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="styles/sidebar.css" />
  <style>
    /* Dashboard Layout */
    .dashboard-container {
      display: grid;
      grid-template-columns: 250px 1fr;
      height: 89vh;
    }

    /* Main Content Improvements */
    .main-content {
      padding: 2rem;
      background: rgba(15, 15, 30, 0.8);
      overflow-y: auto;
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

    /* Dashboard Sections */
    .dashboard-section {
      margin-bottom: 2.5rem;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }

    .section-header h2 {
      font-size: 1.3rem;
      color: var(--accent);
      margin: 0;
    }

    .view-all {
      color: var(--primary);
      text-decoration: none;
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 5px;
      transition: all 0.2s ease;
    }

    .view-all:hover {
      color: var(--accent);
    }

    /* Classes Grid */
    .classes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 20px;
    }

    .class-card {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.12);
      transition: all 0.3s ease;
    }

    .class-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
      background: rgba(255, 255, 255, 0.12);
    }

    .class-card-header {
      padding: 15px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      position: relative;
    }

    .class-card-header h3 {
      margin: 0 0 5px 0;
      font-size: 1.1rem;
      color: white;
    }

    .class-code {
      font-size: 0.8rem;
      background: rgba(108, 92, 231, 0.2);
      padding: 3px 8px;
      border-radius: 20px;
      color: var(--primary);
    }

    .class-card-body {
      padding: 15px;
    }

    .class-meta {
      margin-bottom: 15px;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
    }

    .class-meta span {
      display: flex;
      align-items: center;
      gap: 5px;
      margin-bottom: 5px;
    }

    /* Quizzes Grid */
    .quizzes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 20px;
    }

    .quiz-card {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 10px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.12);
      transition: all 0.3s ease;
    }

    .quiz-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
      background: rgba(255, 255, 255, 0.12);
    }

    .quiz-card-header {
      padding: 15px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .quiz-card-header h3 {
      margin: 0 0 5px 0;
      font-size: 1.1rem;
      color: white;
    }

    .quiz-class {
      font-size: 0.8rem;
      color: var(--accent);
    }

    .quiz-card-body {
      padding: 15px;
    }

    .quiz-meta {
      margin-bottom: 15px;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.85rem;
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .quiz-meta span {
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .quiz-actions {
      display: flex;
      gap: 10px;
    }

    .btn-sm {
      padding: 5px 12px;
      font-size: 0.85rem;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 30px 20px;
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

    .empty-state p {
      margin-bottom: 15px;
    }
  </style>

</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="container-dashboard" style="padding: 1px">
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
          <div class="teacher-profile">
            <div class="teacher-avatar"><?php echo $initials; ?></div>


            <div class="teacher-info">
              <h3><?php echo htmlspecialchars($full_name); ?></h3>

            </div>
          </div>
        </div>

        <nav class="sidebar-menu">
          <a href="dashboard.php" class="menu-item active">
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
        <div class="dashboard-header">
          <div class="page-title">
            <h1>Teacher Dashboard</h1>
            <p>Welcome back! Here's what's happening today.</p>
          </div>

          <div class="quick-actions">
            <a href="create-quiz.php" class="btn btn-primary">
              <i class="fas fa-plus"></i> New Quiz
            </a>
            <a href="classes.php" class="btn btn-outline">
              <i class="fas fa-users"></i> Manage Classes
            </a>
          </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-cards">
          <div class="stat-card">
            <i class="fas fa-users"></i>
            <h3><?php echo $class_count; ?></h3>
            <p>Active Classes</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-question-circle"></i>
            <h3><?php echo $quiz_count; ?></h3>
            <p>Quizzes Created</p>
          </div>

          
        </div>

        <!-- Recent Classes -->
        <div class="dashboard-section">
          <div class="section-header">
            <h2>Recent Classes</h2>
            <a href="classes.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
          </div>

          <div class="classes-grid">
            <?php if (empty($recent_classes)): ?>
              <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>You haven't created any classes yet</p>
                <a href="classes.php" class="btn btn-sm btn-primary">Create Class</a>
              </div>
            <?php else: ?>
              <?php foreach ($recent_classes as $class): ?>
                <div class="class-card">
                  <div class="class-card-header">
                    <h3><?php echo htmlspecialchars($class['class_name']); ?></h3>
                    <span class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></span>
                  </div>
                  <div class="class-card-body">
                    <div class="class-meta">
                      <span><i class="fas fa-user-graduate"></i> <?php echo $class['student_count']; ?> students</span>
                    </div>
                    <a href="class-details.php?id=<?php echo $class['class_id']; ?>" class="btn btn-sm btn-outline">
                      <i class="fas fa-eye"></i> View Class
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Recent Quizzes -->
        <div class="dashboard-section">
          <div class="section-header">
            <h2>Recent Quizzes</h2>
            <a href="quizzes.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
          </div>

          <div class="quizzes-grid">
            <?php if (empty($recent_quizzes)): ?>
              <div class="empty-state">
                <i class="fas fa-question-circle"></i>
                <p>You haven't created any quizzes yet</p>
                <a href="create-quiz.php" class="btn btn-sm btn-primary">Create Quiz</a>
              </div>
            <?php else: ?>
              <?php foreach ($recent_quizzes as $quiz): ?>
                <div class="quiz-card">
                  <div class="quiz-card-header">
                    <h3><?php echo htmlspecialchars($quiz['title']); ?></h3>
                    <span class="quiz-class"><?php echo htmlspecialchars($quiz['class_name']); ?></span>
                  </div>
                  <div class="quiz-card-body">
                    <div class="quiz-meta">
                      <span><i class="far fa-clock"></i> <?php echo $quiz['time_limit']; ?> mins</span>
                      <span><i class="far fa-question-circle"></i> <?php echo $quiz['question_count']; ?> questions</span>
                      <span><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($quiz['created_at'])); ?></span>
                    </div>
                    <div class="quiz-actions">
                      <a href="edit-quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-edit"></i> Edit
                      </a>
                      <a href="quiz-results.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-chart-bar"></i> Results
                      </a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
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