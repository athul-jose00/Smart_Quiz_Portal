<?php
session_start();
require_once '../../includes/db.php';

// Make sure the user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
  header("Location: ../../auth/login.php");
  exit();
}

$user_id = $_SESSION['user_id'];

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

// Get count of enrolled classes
$classes_sql = "SELECT COUNT(*) as class_count FROM user_classes uc 
                JOIN classes c ON uc.class_id = c.class_id 
                WHERE uc.user_id = ?";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $user_id);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$class_count = $classes_result->fetch_assoc()['class_count'];
$classes_stmt->close();

// Get count of completed quizzes
$completed_quizzes_sql = "SELECT COUNT(*) as quiz_count FROM results WHERE user_id = ?";
$completed_stmt = $conn->prepare($completed_quizzes_sql);
$completed_stmt->bind_param("i", $user_id);
$completed_stmt->execute();
$completed_result = $completed_stmt->get_result();
$completed_count = $completed_result->fetch_assoc()['quiz_count'];
$completed_stmt->close();

// Get average score
$avg_score_sql = "SELECT AVG(percentage) as avg_score FROM results WHERE user_id = ?";
$avg_stmt = $conn->prepare($avg_score_sql);
$avg_stmt->bind_param("i", $user_id);
$avg_stmt->execute();
$avg_result = $avg_stmt->get_result();
$avg_score = $avg_result->fetch_assoc()['avg_score'];
$avg_score = $avg_score ? round($avg_score, 1) : 0;
$avg_stmt->close();

// Get enrolled classes
$enrolled_classes_sql = "SELECT c.class_id, c.class_name, c.class_code, u.name as teacher_name
                        FROM user_classes uc
                        JOIN classes c ON uc.class_id = c.class_id
                        JOIN users u ON c.teacher_id = u.user_id
                        WHERE uc.user_id = ?
                        ORDER BY c.class_name LIMIT 5";
$enrolled_stmt = $conn->prepare($enrolled_classes_sql);
$enrolled_stmt->bind_param("i", $user_id);
$enrolled_stmt->execute();
$enrolled_result = $enrolled_stmt->get_result();
$enrolled_classes = $enrolled_result->fetch_all(MYSQLI_ASSOC);
$enrolled_stmt->close();

// Get recent quiz results
$recent_results_sql = "SELECT r.quiz_id, r.total_score, r.percentage, r.completed_at, 
                      q.title as quiz_title, c.class_name
                      FROM results r
                      JOIN quizzes q ON r.quiz_id = q.quiz_id
                      JOIN classes c ON q.class_id = c.class_id
                      WHERE r.user_id = ?
                      ORDER BY r.completed_at DESC LIMIT 5";
$results_stmt = $conn->prepare($recent_results_sql);
$results_stmt->bind_param("i", $user_id);
$results_stmt->execute();
$results_result = $results_stmt->get_result();
$recent_results = $results_result->fetch_all(MYSQLI_ASSOC);
$results_stmt->close();

// Get available quizzes (not yet taken)
$available_quizzes_sql = "SELECT q.quiz_id, q.title, q.time_limit, c.class_name, c.class_id
                         FROM quizzes q
                         JOIN classes c ON q.class_id = c.class_id
                         JOIN user_classes uc ON c.class_id = uc.class_id
                         WHERE uc.user_id = ? 
                         AND q.quiz_id NOT IN (SELECT quiz_id FROM results WHERE user_id = ?)
                         ORDER BY q.created_at DESC LIMIT 5";
$available_stmt = $conn->prepare($available_quizzes_sql);
$available_stmt->bind_param("ii", $user_id, $user_id);
$available_stmt->execute();
$available_result = $available_stmt->get_result();
$available_quizzes = $available_result->fetch_all(MYSQLI_ASSOC);
$available_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Dashboard | Smart Quiz Portal</title>
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

    /* Results specific styling */
    .result-score {
      font-weight: bold;
      color: var(--accent);
    }

    .result-date {
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.6);
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
          <a href="student.php" class="menu-item active">
            <i class="fas fa-tachometer-alt"></i> Dashboard
          </a>
          <a href="classes.php" class="menu-item">
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
        <div class="dashboard-header">
          <div class="page-title">
            <h1>Student Dashboard</h1>
            <p>Welcome back! Here's your learning progress.</p>
          </div>

          <div class="quick-actions">
            <a href="quizzes.php" class="btn btn-primary">
              <i class="fas fa-play"></i> Take Quiz
            </a>
            <a href="join-class.php" class="btn btn-outline">
              <i class="fas fa-plus"></i> Join Class
            </a>
          </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-cards">
          <div class="stat-card">
            <i class="fas fa-users"></i>
            <h3><?php echo $class_count; ?></h3>
            <p>Enrolled Classes</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <h3><?php echo $completed_count; ?></h3>
            <p>Completed Quizzes</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-chart-line"></i>
            <h3><?php echo $avg_score; ?>%</h3>
            <p>Average Score</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-calendar"></i>
            <h3><?php echo date('d'); ?></h3>
            <p><?php echo date('F Y'); ?></p>
          </div>
        </div>

        <!-- Enrolled Classes -->
        <div class="dashboard-section">
          <div class="section-header">
            <h2>My Classes</h2>
            <a href="classes.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
          </div>

          <div class="classes-grid">
            <?php if (empty($enrolled_classes)): ?>
              <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>You haven't joined any classes yet</p>
                <a href="join-class.php" class="btn btn-sm btn-primary">Join Class</a>
              </div>
            <?php else: ?>
              <?php foreach ($enrolled_classes as $class): ?>
                <div class="class-card">
                  <div class="class-card-header">
                    <h3><?php echo htmlspecialchars($class['class_name']); ?></h3>
                    <span class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></span>
                  </div>
                  <div class="class-card-body">
                    <div class="class-meta">
                      <span><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($class['teacher_name']); ?></span>
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

        <!-- Available Quizzes -->
        <div class="dashboard-section">
          <div class="section-header">
            <h2>Available Quizzes</h2>
            <a href="quizzes.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
          </div>

          <div class="quizzes-grid">
            <?php if (empty($available_quizzes)): ?>
              <div class="empty-state">
                <i class="fas fa-question-circle"></i>
                <p>No quizzes available at the moment</p>
                <a href="classes.php" class="btn btn-sm btn-primary">Join More Classes</a>
              </div>
            <?php else: ?>
              <?php foreach ($available_quizzes as $quiz): ?>
                <div class="quiz-card">
                  <div class="quiz-card-header">
                    <h3><?php echo htmlspecialchars($quiz['title']); ?></h3>
                    <span class="quiz-class"><?php echo htmlspecialchars($quiz['class_name']); ?></span>
                  </div>
                  <div class="quiz-card-body">
                    <div class="quiz-meta">
                      <span><i class="far fa-clock"></i> <?php echo $quiz['time_limit']; ?> minutes</span>
                    </div>
                    <div class="quiz-actions">
                      <a href="take-quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-play"></i> Start Quiz
                      </a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Recent Results -->
        <div class="dashboard-section">
          <div class="section-header">
            <h2>Recent Results</h2>
            <a href="results.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
          </div>

          <div class="quizzes-grid">
            <?php if (empty($recent_results)): ?>
              <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <p>No quiz results yet</p>
                <a href="quizzes.php" class="btn btn-sm btn-primary">Take Your First Quiz</a>
              </div>
            <?php else: ?>
              <?php foreach ($recent_results as $result): ?>
                <div class="quiz-card">
                  <div class="quiz-card-header">
                    <h3><?php echo htmlspecialchars($result['quiz_title']); ?></h3>
                    <span class="quiz-class"><?php echo htmlspecialchars($result['class_name']); ?></span>
                  </div>
                  <div class="quiz-card-body">
                    <div class="quiz-meta">
                      <span class="result-score">Score: <?php echo $result['percentage']; ?>%</span>
                      <span class="result-date"><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($result['completed_at'])); ?></span>
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
<?php
$conn->close();
?>