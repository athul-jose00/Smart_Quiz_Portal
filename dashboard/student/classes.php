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

// Get all enrolled classes with detailed information
$classes_sql = "SELECT c.class_id, c.class_name, c.class_code, u.name as teacher_name,
                (SELECT COUNT(*) FROM quizzes q WHERE q.class_id = c.class_id) as total_quizzes,
                (SELECT COUNT(*) FROM results r 
                 JOIN quizzes q ON r.quiz_id = q.quiz_id 
                 WHERE q.class_id = c.class_id AND r.user_id = ?) as completed_quizzes,
                (SELECT AVG(r.percentage) FROM results r 
                 JOIN quizzes q ON r.quiz_id = q.quiz_id 
                 WHERE q.class_id = c.class_id AND r.user_id = ?) as avg_score,
                (SELECT COUNT(*) FROM user_classes uc WHERE uc.class_id = c.class_id) as student_count
                FROM user_classes uc
                JOIN classes c ON uc.class_id = c.class_id
                JOIN users u ON c.teacher_id = u.user_id
                WHERE uc.user_id = ?
                ORDER BY c.class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("iii", $user_id, $user_id, $user_id);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$enrolled_classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

// Get recent activity for each class
foreach ($enrolled_classes as &$class) {
  // Get recent quiz results for this class
  $recent_sql = "SELECT r.quiz_id, r.percentage, r.completed_at, q.title as quiz_title
                   FROM results r
                   JOIN quizzes q ON r.quiz_id = q.quiz_id
                   WHERE q.class_id = ? AND r.user_id = ?
                   ORDER BY r.completed_at DESC LIMIT 3";
  $recent_stmt = $conn->prepare($recent_sql);
  $recent_stmt->bind_param("ii", $class['class_id'], $user_id);
  $recent_stmt->execute();
  $recent_result = $recent_stmt->get_result();
  $class['recent_results'] = $recent_result->fetch_all(MYSQLI_ASSOC);
  $recent_stmt->close();

  // Get available quizzes for this class
  $available_sql = "SELECT q.quiz_id, q.title, q.time_limit, q.created_at
                      FROM quizzes q
                      WHERE q.class_id = ? 
                      AND q.quiz_id NOT IN (SELECT quiz_id FROM results WHERE user_id = ?)
                      ORDER BY q.created_at DESC LIMIT 3";
  $available_stmt = $conn->prepare($available_sql);
  $available_stmt->bind_param("ii", $class['class_id'], $user_id);
  $available_stmt->execute();
  $available_result = $available_stmt->get_result();
  $class['available_quizzes'] = $available_result->fetch_all(MYSQLI_ASSOC);
  $available_stmt->close();
}
unset($class); // Break the reference
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Classes | Smart Quiz Portal</title>
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

    /* Class Cards */
    .classes-container {
      display: grid;
      gap: 2rem;
    }

    .class-card {
      background: rgba(0, 0, 0, 0.2);
      border-radius: 16px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.12);
      transition: all 0.3s ease;
    }

    .class-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
      background: rgba(255, 255, 255, 0.08);
      cursor: pointer;
    }

    .class-header {
      background: linear-gradient(135deg, rgba(108, 92, 231, 0.2), rgba(253, 121, 168, 0.1));
      padding: 2rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .class-title-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 1rem;
      flex-wrap: wrap;
      gap: 1rem;
    }

    .class-title {
      font-size: 1.5rem;
      color: white;
      margin: 0;
      font-weight: 600;
    }

    .class-code {
      background: rgba(108, 92, 231, 0.25);
      padding: 8px 16px;
      border-radius: 25px;
      color: #ffffff;
      font-weight: 700;
      letter-spacing: 1px;
      font-size: 1rem;
      border: 1px solid rgba(108, 92, 231, 0.4);
    }

    .class-teacher {
      color: rgba(255, 255, 255, 0.8);
      font-size: 1rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .class-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 1rem;
      margin-top: 1.5rem;
    }

    .stat-item {
      text-align: center;
      background: rgba(255, 255, 255, 0.1);
      padding: 1rem;
      border-radius: 10px;
    }

    .stat-value {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--accent);
      margin-bottom: 0.25rem;
    }

    .stat-label {
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.7);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .class-body {
      padding: 2rem;
      background: linear-gradient(135deg, rgba(108, 92, 231, 0.2), rgba(253, 121, 168, 0.1));
    }

    .class-section {
      margin-bottom: 2rem;
    }

    .section-title {
      font-size: 1.1rem;
      color: var(--accent);
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .quiz-list {
      display: grid;
      gap: 0.75rem;
    }

    .quiz-item {
      background: rgba(255, 255, 255, 0.05);
      padding: 1rem;
      border-radius: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: all 0.2s ease;
    }

    .quiz-item:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .quiz-info {
      flex: 1;
    }

    .quiz-title {
      color: white;
      font-weight: 500;
      margin-bottom: 0.25rem;
    }

    .quiz-meta {
      font-size: 0.85rem;
      color: rgba(255, 255, 255, 0.6);
      display: flex;
      gap: 15px;
    }

    .quiz-actions {
      display: flex;
      gap: 8px;
    }

    .btn-sm {
      padding: 6px 12px;
      font-size: 0.85rem;
      border-radius: 6px;
      text-decoration: none;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .btn-primary {
      background: var(--primary);
      color: white;
    }

    .btn-primary:hover {
      background: var(--accent);
      transform: translateY(-1px);
    }

    .btn-outline {
      background: transparent;
      color: rgba(255, 255, 255, 0.8);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .btn-outline:hover {
      background: rgba(255, 255, 255, 0.1);
      color: white;
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

    .empty-message i {
      font-size: 2rem;
      margin-bottom: 1rem;
      color: rgba(255, 255, 255, 0.3);
    }

    /* Empty State for No Classes */
    .no-classes {
      text-align: center;
      padding: 4rem 2rem;
      color: rgba(255, 255, 255, 0.6);
      background: rgba(255, 255, 255, 0.05);
      border-radius: 16px;
      border: 2px dashed rgba(255, 255, 255, 0.1);
    }

    .no-classes i {
      font-size: 4rem;
      margin-bottom: 2rem;
      color: rgba(255, 255, 255, 0.3);
    }

    .no-classes h3 {
      color: rgba(255, 255, 255, 0.8);
      margin-bottom: 1rem;
      font-size: 1.5rem;
    }

    .no-classes p {
      margin-bottom: 2rem;
      font-size: 1.1rem;
    }

    /* Progress indicators */
    .progress-bar {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      height: 8px;
      overflow: hidden;
      margin-top: 0.5rem;
    }

    .progress-fill {
      background: linear-gradient(90deg, var(--primary), var(--accent));
      height: 100%;
      border-radius: 10px;
      transition: width 0.3s ease;
    }

    .score-badge {
      background: linear-gradient(135deg, var(--primary), var(--accent));
      color: white;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 0.8rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      //justify-content: center;
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
        <div class="dashboard-header">
          <div class="page-title">
            <h1>My Classes</h1>
            <p>Manage your enrolled classes and track your progress.</p>
          </div>

          <div class="quick-actions">
            <a href="join-class.php" class="btn btn-primary">
              <i class="fas fa-plus"></i> Join New Class
            </a>
            <a href="quizzes.php" class="btn btn-outline">
              <i class="fas fa-play"></i> Take Quiz
            </a>
          </div>
        </div>

        <!-- Classes Container -->
        <div class="classes-container">
          <?php if (empty($enrolled_classes)): ?>
            <div class="no-classes">
              <i class="fas fa-users"></i>
              <h3>No Classes Enrolled</h3>
              <p>You haven't joined any classes yet. Get started by joining your first class!</p>
              <a href="join-class.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Join Your First Class
              </a>
            </div>
          <?php else: ?>
            <?php foreach ($enrolled_classes as $class): ?>
              <div class="class-card">
                <div class="class-header">
                  <div class="class-title-row">
                    <h2 class="class-title"><?php echo htmlspecialchars($class['class_name']); ?></h2>
                    <div style="display: flex; align-items: center; gap: 15px;">
                      <span class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></span>
                      <a href="class-details.php?id=<?php echo $class['class_id']; ?>" class="btn-sm btn-primary">
                        <i class="fas fa-eye"></i> View Details
                      </a>
                    </div>
                  </div>
                  <div class="class-teacher">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <?php echo htmlspecialchars($class['teacher_name']); ?>
                  </div>

                  <div class="class-stats">
                    <div class="stat-item">
                      <div class="stat-value"><?php echo $class['total_quizzes']; ?></div>
                      <div class="stat-label">Total Quizzes</div>
                    </div>
                    <div class="stat-item">
                      <div class="stat-value"><?php echo $class['completed_quizzes']; ?></div>
                      <div class="stat-label">Completed</div>
                    </div>
                    <div class="stat-item">
                      <div class="stat-value"><?php echo $class['avg_score'] ? round($class['avg_score'], 1) . '%' : 'N/A'; ?></div>
                      <div class="stat-label">Avg Score</div>
                    </div>
                    <div class="stat-item">
                      <div class="stat-value"><?php echo $class['student_count']; ?></div>
                      <div class="stat-label">Students</div>
                    </div>
                  </div>

                  <?php if ($class['total_quizzes'] > 0): ?>
                    <div class="progress-bar">
                      <div class="progress-fill" style="width: <?php echo ($class['completed_quizzes'] / $class['total_quizzes']) * 100; ?>%"></div>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="class-body">
                  <div class="class-section">
                    <h3 class="section-title">
                      <i class="fas fa-play-circle"></i>
                      Available Quizzes
                    </h3>

                    <?php if (empty($class['available_quizzes'])): ?>
                      <div class="empty-message">
                        <i class="fas fa-check-circle"></i>
                        <p>All quizzes completed! Great job!</p>
                      </div>
                    <?php else: ?>
                      <div class="quiz-list">
                        <?php foreach ($class['available_quizzes'] as $quiz): ?>
                          <div class="quiz-item">
                            <div class="quiz-info">
                              <div class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></div>
                              <div class="quiz-meta">
                                <span><i class="far fa-clock"></i> <?php echo $quiz['time_limit']; ?> mins</span>
                                <span><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($quiz['created_at'])); ?></span>
                              </div>
                            </div>
                            <div class="quiz-actions">
                              <a href="take-quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn-sm btn-primary">
                                <i class="fas fa-play"></i> Start
                              </a>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="class-section">
                    <h3 class="section-title">
                      <i class="fas fa-chart-line"></i>
                      Recent Results
                    </h3>

                    <?php if (empty($class['recent_results'])): ?>
                      <div class="empty-message">
                        <i class="fas fa-question-circle"></i>
                        <p>No quiz results yet. Take your first quiz to see results here!</p>
                      </div>
                    <?php else: ?>
                      <div class="quiz-list">
                        <?php foreach ($class['recent_results'] as $result): ?>
                          <div class="quiz-item">
                            <div class="quiz-info">
                              <div class="quiz-title"><?php echo htmlspecialchars($result['quiz_title']); ?></div>
                              <div class="quiz-meta">
                                <span><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($result['completed_at'])); ?></span>
                              </div>
                            </div>
                            <div class="quiz-actions">
                              <span class="score-badge"><?php echo $result['percentage']; ?>%</span>
                              <a href="quiz-result.php?id=<?php echo $result['quiz_id']; ?>" class="btn-sm btn-outline">
                                <i class="fas fa-eye"></i> View
                              </a>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
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
    });
  </script>
</body>

</html>
<?php
$conn->close();
?>