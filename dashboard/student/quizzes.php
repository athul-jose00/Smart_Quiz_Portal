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

// Get filter parameters
$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Get student's enrolled classes for filter dropdown
$classes_sql = "SELECT c.class_id, c.class_name, c.class_code
                FROM user_classes uc
                JOIN classes c ON uc.class_id = c.class_id
                WHERE uc.user_id = ?
                ORDER BY c.class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $user_id);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$enrolled_classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

// Build the main query based on filters
$where_conditions = ["uc.user_id = ?"];
$params = [$user_id];
$param_types = "i";

if ($selected_class_id > 0) {
  $where_conditions[] = "c.class_id = ?";
  $params[] = $selected_class_id;
  $param_types .= "i";
}

// Base query for all quizzes in enrolled classes
$base_sql = "SELECT q.quiz_id, q.title, q.time_limit, q.created_at,
             c.class_id, c.class_name, c.class_code, u.name as teacher_name,
             (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id = q.quiz_id) as question_count,
             r.result_id, r.percentage, r.completed_at
             FROM user_classes uc
             JOIN classes c ON uc.class_id = c.class_id
             JOIN users u ON c.teacher_id = u.user_id
             JOIN quizzes q ON c.class_id = q.class_id
             LEFT JOIN results r ON q.quiz_id = r.quiz_id AND r.user_id = ?
             WHERE " . implode(" AND ", $where_conditions);

// Add status filter
if ($filter_status === 'available') {
  $base_sql .= " AND r.result_id IS NULL";
} elseif ($filter_status === 'completed') {
  $base_sql .= " AND r.result_id IS NOT NULL";
}

$base_sql .= " ORDER BY q.created_at DESC";

// Prepare and execute the query
$stmt = $conn->prepare($base_sql);
$all_params = array_merge([$user_id], $params);
$all_param_types = "i" . $param_types;
$stmt->bind_param($all_param_types, ...$all_params);
$stmt->execute();
$result = $stmt->get_result();
$all_quizzes = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Separate quizzes into available and completed
$available_quizzes = [];
$completed_quizzes = [];

foreach ($all_quizzes as $quiz) {
  if ($quiz['result_id']) {
    $completed_quizzes[] = $quiz;
  } else {
    $available_quizzes[] = $quiz;
  }
}

// Get overall statistics
$total_available = count($available_quizzes);
$total_completed = count($completed_quizzes);
$avg_score = 0;

if ($total_completed > 0) {
  $total_score = array_sum(array_column($completed_quizzes, 'percentage'));
  $avg_score = round($total_score / $total_completed, 1);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Available Quizzes | Smart Quiz Portal</title>
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

    /* Stats Cards */
    .stats-cards {
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
      transition: all 0.3s ease;
      text-align: center;
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
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

    /* Filter Section */
    .filter-section {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
    }

    .filter-row {
      display: flex;
      gap: 1rem;
      align-items: flex-end;
      flex-wrap: wrap;
    }

    .filter-group {
      flex: 1;
      min-width: 200px;
    }

    .filter-group label {
      display: block;
      margin-bottom: 0.5rem;
      color: rgba(255, 255, 255, 0.9);
      font-weight: 500;
    }

    .filter-group select {
      width: 100%;
      padding: 10px;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 8px;
      color: white;
      font-size: 0.95rem;
    }

    .filter-group select:focus {
      outline: none;
      border-color: var(--primary);
    }

    .filter-group select option {
      background: #1a1a2e;
      color: white;
    }

    .filter-btn {
      padding: 10px 20px;
      background: var(--primary);
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.95rem;
      transition: all 0.2s ease;
    }

    .filter-btn:hover {
      background: var(--accent);
    }

    /* Quiz Sections */
    .quiz-section {
      margin-bottom: 3rem;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }

    .section-title {
      font-size: 1.4rem;
      color: var(--accent);
      margin: 0;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .quiz-count {
      background: rgba(108, 92, 231, 0.2);
      color: var(--primary);
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    /* Quiz Grid */
    .quizzes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
      gap: 20px;
    }

    .quiz-card {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.12);
      transition: all 0.3s ease;
    }

    .quiz-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
      background: rgba(255, 255, 255, 0.12);
    }

    .quiz-card-header {
      padding: 1.5rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      position: relative;
    }

    .quiz-title {
      font-size: 1.2rem;
      color: white;
      margin: 0 0 0.5rem 0;
      font-weight: 600;
    }

    .quiz-class {
      color: var(--accent);
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .quiz-status {
      position: absolute;
      top: 1rem;
      right: 1rem;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .status-available {
      background: rgba(46, 204, 113, 0.2);
      color: #2ecc71;
      border: 1px solid rgba(46, 204, 113, 0.3);
    }

    .status-completed {
      background: rgba(108, 92, 231, 0.2);
      color: var(--primary);
      border: 1px solid rgba(108, 92, 231, 0.3);
    }

    .quiz-card-body {
      padding: 1.5rem;
    }

    .quiz-meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .meta-item {
      display: flex;
      align-items: center;
      gap: 8px;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
    }

    .quiz-score {
      background: linear-gradient(135deg, var(--primary), var(--accent));
      color: white;
      padding: 8px 16px;
      border-radius: 20px;
      font-weight: 600;
      text-align: center;
      margin-bottom: 1rem;
    }

    .quiz-actions {
      display: flex;
      gap: 10px;
    }

    .btn {
      padding: 10px 20px;
      border-radius: 8px;
      text-decoration: none;
      font-size: 0.9rem;
      font-weight: 500;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      flex: 1;
      justify-content: center;
    }

    .btn-primary {
      background: var(--primary);
      color: white;
    }

    .btn-primary:hover {
      background: var(--accent);
      transform: translateY(-2px);
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

    .btn-success:hover {
      background: rgba(46, 204, 113, 0.3);
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      color: rgba(255, 255, 255, 0.6);
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      border: 2px dashed rgba(255, 255, 255, 0.1);
    }

    .empty-state i {
      font-size: 4rem;
      margin-bottom: 2rem;
      color: rgba(255, 255, 255, 0.3);
    }

    .empty-state h3 {
      color: rgba(255, 255, 255, 0.8);
      margin-bottom: 1rem;
      font-size: 1.5rem;
    }

    .empty-state p {
      margin-bottom: 2rem;
      font-size: 1.1rem;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .quizzes-grid {
        grid-template-columns: 1fr;
      }

      .filter-row {
        flex-direction: column;
      }

      .filter-group {
        min-width: 100%;
      }

      .quiz-meta {
        grid-template-columns: 1fr;
      }
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
          <a href="classes.php" class="menu-item">
            <i class="fas fa-users"></i> My Classes
          </a>
          <a href="quizzes.php" class="menu-item active">
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
            <h1>Available Quizzes</h1>
            <p>Browse and take quizzes from your enrolled classes.</p>
          </div>

          <div class="quick-actions">
            <a href="join-class.php" class="btn btn-outline">
              <i class="fas fa-plus"></i> Join Class
            </a>
            <a href="results.php" class="btn btn-primary">
              <i class="fas fa-chart-bar"></i> View Results
            </a>
          </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-cards">
          <div class="stat-card">
            <i class="fas fa-play-circle"></i>
            <h3><?php echo $total_available; ?></h3>
            <p>Available Quizzes</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <h3><?php echo $total_completed; ?></h3>
            <p>Completed Quizzes</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-chart-line"></i>
            <h3><?php echo $avg_score; ?>%</h3>
            <p>Average Score</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-users"></i>
            <h3><?php echo count($enrolled_classes); ?></h3>
            <p>Enrolled Classes</p>
          </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
          <form method="GET" action="">
            <div class="filter-row">
              <div class="filter-group">
                <label for="class_id">Filter by Class</label>
                <select name="class_id" id="class_id">
                  <option value="0">All Classes</option>
                  <?php foreach ($enrolled_classes as $class): ?>
                    <option value="<?php echo $class['class_id']; ?>" <?php echo $selected_class_id == $class['class_id'] ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($class['class_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="filter-group">
                <label for="status">Filter by Status</label>
                <select name="status" id="status">
                  <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Quizzes</option>
                  <option value="available" <?php echo $filter_status === 'available' ? 'selected' : ''; ?>>Available Only</option>
                  <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed Only</option>
                </select>
              </div>

              <button type="submit" class="filter-btn">
                <i class="fas fa-filter"></i> Apply Filters
              </button>
            </div>
          </form>
        </div>

        <!-- Available Quizzes Section -->
        <?php if ($filter_status === 'all' || $filter_status === 'available'): ?>
          <div class="quiz-section">
            <div class="section-header">
              <h2 class="section-title">
                <i class="fas fa-play-circle"></i>
                Available Quizzes
                <span class="quiz-count"><?php echo count($available_quizzes); ?></span>
              </h2>
            </div>

            <?php if (empty($available_quizzes)): ?>
              <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>All Caught Up!</h3>
                <p>You've completed all available quizzes. Great job!</p>
                <a href="join-class.php" class="btn btn-primary">
                  <i class="fas fa-plus"></i> Join More Classes
                </a>
              </div>
            <?php else: ?>
              <div class="quizzes-grid">
                <?php foreach ($available_quizzes as $quiz): ?>
                  <div class="quiz-card">
                    <div class="quiz-card-header">
                      <div class="quiz-status status-available">Available</div>
                      <h3 class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                      <div class="quiz-class">
                        <i class="fas fa-book"></i>
                        <?php echo htmlspecialchars($quiz['class_name']); ?>
                      </div>
                    </div>
                    <div class="quiz-card-body">
                      <div class="quiz-meta">
                        <div class="meta-item">
                          <i class="far fa-clock"></i>
                          <?php echo $quiz['time_limit']; ?> minutes
                        </div>
                        <div class="meta-item">
                          <i class="far fa-question-circle"></i>
                          <?php echo $quiz['question_count']; ?> questions
                        </div>
                        <div class="meta-item">
                          <i class="fas fa-chalkboard-teacher"></i>
                          <?php echo htmlspecialchars($quiz['teacher_name']); ?>
                        </div>
                        <div class="meta-item">
                          <i class="far fa-calendar-alt"></i>
                          <?php echo date('M d, Y', strtotime($quiz['created_at'])); ?>
                        </div>
                      </div>
                      <div class="quiz-actions">
                        <a href="take-quiz.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-primary">
                          <i class="fas fa-play"></i> Start Quiz
                        </a>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Completed Quizzes Section -->
        <?php if ($filter_status === 'all' || $filter_status === 'completed'): ?>
          <div class="quiz-section">
            <div class="section-header">
              <h2 class="section-title">
                <i class="fas fa-check-circle"></i>
                Completed Quizzes
                <span class="quiz-count"><?php echo count($completed_quizzes); ?></span>
              </h2>
            </div>

            <?php if (empty($completed_quizzes)): ?>
              <div class="empty-state">
                <i class="fas fa-question-circle"></i>
                <h3>No Completed Quizzes</h3>
                <p>You haven't completed any quizzes yet. Start with an available quiz above!</p>
              </div>
            <?php else: ?>
              <div class="quizzes-grid">
                <?php foreach ($completed_quizzes as $quiz): ?>
                  <div class="quiz-card">
                    <div class="quiz-card-header">
                      <div class="quiz-status status-completed">Completed</div>
                      <h3 class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                      <div class="quiz-class">
                        <i class="fas fa-book"></i>
                        <?php echo htmlspecialchars($quiz['class_name']); ?>
                      </div>
                    </div>
                    <div class="quiz-card-body">
                      <div class="quiz-score">
                        Score: <?php echo $quiz['percentage']; ?>%
                      </div>
                      <div class="quiz-meta">
                        <div class="meta-item">
                          <i class="far fa-clock"></i>
                          <?php echo $quiz['time_limit']; ?> minutes
                        </div>
                        <div class="meta-item">
                          <i class="far fa-question-circle"></i>
                          <?php echo $quiz['question_count']; ?> questions
                        </div>
                        <div class="meta-item">
                          <i class="fas fa-chalkboard-teacher"></i>
                          <?php echo htmlspecialchars($quiz['teacher_name']); ?>
                        </div>
                        <div class="meta-item">
                          <i class="far fa-calendar-alt"></i>
                          <?php echo date('M d, Y', strtotime($quiz['completed_at'])); ?>
                        </div>
                      </div>
                      <div class="quiz-actions">
                        <a href="quiz-result.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn btn-outline">
                          <i class="fas fa-eye"></i> View Results
                        </a>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
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