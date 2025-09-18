<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../auth/login.php');
  exit();
}

// Handle filters
$filter_class = isset($_GET['filter_class']) ? $_GET['filter_class'] : '';
$filter_student = isset($_GET['filter_student']) ? $_GET['filter_student'] : '';
$filter_date_from = isset($_GET['filter_date_from']) ? $_GET['filter_date_from'] : '';
$filter_date_to = isset($_GET['filter_date_to']) ? $_GET['filter_date_to'] : '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if (!empty($filter_class)) {
  $where_conditions[] = "c.class_id = ?";
  $params[] = $filter_class;
}

if (!empty($filter_student)) {
  $where_conditions[] = "u.user_id = ?";
  $params[] = $filter_student;
}

if (!empty($filter_date_from)) {
  $where_conditions[] = "DATE(r.completed_at) >= ?";
  $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
  $where_conditions[] = "DATE(r.completed_at) <= ?";
  $params[] = $filter_date_to;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get performance analytics data
try {
  // Get all classes for filter dropdown
  $classes_stmt = $pdo->query("SELECT class_id, class_name FROM classes ORDER BY class_name");
  $all_classes = $classes_stmt->fetchAll();

  // Get all students for filter dropdown
  $students_stmt = $pdo->query("SELECT user_id, name, username FROM users WHERE role = 'student' ORDER BY name");
  $all_students = $students_stmt->fetchAll();

  // Overall performance metrics with filters
  $overall_sql = "
        SELECT 
            COUNT(DISTINCT r.user_id) as active_students,
            COUNT(r.result_id) as total_submissions,
            AVG(r.percentage) as overall_avg_score,
            MAX(r.percentage) as highest_score,
            MIN(r.percentage) as lowest_score
        FROM results r
        JOIN users u ON r.user_id = u.user_id
        JOIN quizzes q ON r.quiz_id = q.quiz_id
        LEFT JOIN classes c ON q.class_id = c.class_id
        $where_clause
    ";

  if (!empty($params)) {
    $overall_stmt = $pdo->prepare($overall_sql);
    $overall_stmt->execute($params);
  } else {
    $overall_stmt = $pdo->query($overall_sql);
  }
  $overall_stats = $overall_stmt->fetch();

  // Performance by class with filters
  $class_sql = "
        SELECT 
            c.class_name as class_name,
            c.class_id,
            COUNT(DISTINCT r.user_id) as student_count,
            COUNT(r.result_id) as submissions,
            AVG(r.percentage) as avg_score,
            MAX(r.percentage) as max_score
        FROM classes c
        LEFT JOIN quizzes q ON c.class_id = q.class_id
        LEFT JOIN results r ON q.quiz_id = r.quiz_id
        LEFT JOIN users u ON r.user_id = u.user_id
        $where_clause
        GROUP BY c.class_id, c.class_name
        HAVING submissions > 0
        ORDER BY avg_score DESC
    ";

  if (!empty($params)) {
    $class_stmt = $pdo->prepare($class_sql);
    $class_stmt->execute($params);
  } else {
    $class_stmt = $pdo->query($class_sql);
  }
  $class_performance = $class_stmt->fetchAll();

  // Top performing students with filters
  $student_where = $where_clause;
  if (empty($where_conditions)) {
    $student_where = "WHERE u.role = 'student'";
  } else {
    $student_where = str_replace("WHERE", "WHERE u.role = 'student' AND", $where_clause);
  }

  $students_sql = "
        SELECT 
            u.username,
            u.name,
            u.email,
            u.user_id,
            COUNT(r.result_id) as quiz_count,
            AVG(r.percentage) as avg_score,
            MAX(r.percentage) as best_score
        FROM users u
        JOIN results r ON u.user_id = r.user_id
        JOIN quizzes q ON r.quiz_id = q.quiz_id
        LEFT JOIN classes c ON q.class_id = c.class_id
        $student_where
        GROUP BY u.user_id, u.username, u.name, u.email
        HAVING quiz_count >= 1
        ORDER BY avg_score DESC, quiz_count DESC
        LIMIT 20
    ";

  if (!empty($params)) {
    $top_students_stmt = $pdo->prepare($students_sql);
    $top_students_stmt->execute($params);
  } else {
    $top_students_stmt = $pdo->query($students_sql);
  }
  $top_students = $top_students_stmt->fetchAll();

  // Quiz difficulty analysis
  $quiz_difficulty_stmt = $pdo->query("
        SELECT 
            q.title,
            q.time_limit as duration,
            COUNT(r.result_id) as attempts,
            AVG(r.percentage) as avg_score,
            CASE 
                WHEN AVG(r.percentage) >= 80 THEN 'Easy'
                WHEN AVG(r.percentage) >= 60 THEN 'Medium'
                ELSE 'Hard'
            END as difficulty_level
        FROM quizzes q
        LEFT JOIN results r ON q.quiz_id = r.quiz_id
        GROUP BY q.quiz_id, q.title, q.time_limit
        HAVING attempts > 0
        ORDER BY avg_score ASC
    ");
  $quiz_difficulty = $quiz_difficulty_stmt->fetchAll();

  // Recent activity with filters
  $activity_sql = "
        SELECT 
            u.username as student_name,
            u.name as full_name,
            q.title as quiz_title,
            r.percentage as score,
            r.completed_at,
            c.class_name as class_name
        FROM results r
        JOIN users u ON r.user_id = u.user_id
        JOIN quizzes q ON r.quiz_id = q.quiz_id
        LEFT JOIN classes c ON q.class_id = c.class_id
        $where_clause
        ORDER BY r.completed_at DESC
        LIMIT 25
    ";

  if (!empty($params)) {
    $recent_activity_stmt = $pdo->prepare($activity_sql);
    $recent_activity_stmt->execute($params);
  } else {
    $recent_activity_stmt = $pdo->query($activity_sql);
  }
  $recent_activity = $recent_activity_stmt->fetchAll();

  // Individual student detailed metrics (when student filter is applied)
  $individual_metrics = [];
  if (!empty($filter_student)) {
    $individual_sql = "
          SELECT 
              q.title as quiz_title,
              r.percentage as score,
              r.total_score,
              r.completed_at,
              c.class_name,
              (SELECT COUNT(*) FROM questions WHERE quiz_id = q.quiz_id) as total_questions
          FROM results r
          JOIN quizzes q ON r.quiz_id = q.quiz_id
          LEFT JOIN classes c ON q.class_id = c.class_id
          WHERE r.user_id = ?
          ORDER BY r.completed_at DESC
      ";
    $individual_stmt = $pdo->prepare($individual_sql);
    $individual_stmt->execute([$filter_student]);
    $individual_metrics = $individual_stmt->fetchAll();
  }
} catch (PDOException $e) {
  $overall_stats = ['active_students' => 0, 'total_submissions' => 0, 'overall_avg_score' => 0, 'highest_score' => 0, 'lowest_score' => 0];
  $class_performance = [];
  $top_students = [];
  $quiz_difficulty = [];
  $recent_activity = [];
  $error_message = "Error fetching analytics data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Performance Analytics - Admin Dashboard</title>
  <link rel="stylesheet" href="../../css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    .admin-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 20px;
      min-height: 100vh;
    }

    .page-header {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 30px;
      margin-bottom: 30px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .page-title {
      font-size: 2.2rem;
      margin: 0;
      background: linear-gradient(90deg, var(--accent), var(--primary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .back-btn {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 12px 20px;
      border-radius: 25px;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4);
    }

    .back-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(108, 92, 231, 0.6);
    }

    .analytics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 25px;
      margin-bottom: 30px;
    }

    .analytics-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 25px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      transition: all 0.3s ease;
    }

    .analytics-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
    }

    .card-header {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 20px;
    }

    .card-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      color: white;
    }

    .card-icon.overview {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
    }

    .card-icon.performance {
      background: linear-gradient(135deg, var(--success), #00cec9);
    }

    .card-icon.students {
      background: linear-gradient(135deg, var(--accent), #fd79a8);
    }

    .card-icon.difficulty {
      background: linear-gradient(135deg, var(--warning), #e17055);
    }

    .card-title {
      font-size: 1.3rem;
      color: white;
      margin: 0;
      font-weight: 600;
    }

    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 15px;
      margin-bottom: 20px;
    }

    .metric-item {
      text-align: center;
      padding: 15px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 10px;
    }

    .metric-number {
      font-size: 1.8rem;
      font-weight: bold;
      color: var(--accent);
      display: block;
      margin-bottom: 5px;
    }

    .metric-label {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .performance-list {
      max-height: 300px;
      overflow-y: auto;
    }

    .performance-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .performance-item:last-child {
      border-bottom: none;
    }

    .item-info h4 {
      margin: 0 0 5px 0;
      color: white;
      font-size: 0.95rem;
    }

    .item-info p {
      margin: 0;
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.8rem;
    }

    .item-score {
      text-align: right;
    }

    .score-value {
      font-size: 1.1rem;
      font-weight: bold;
      color: var(--success);
      display: block;
    }

    .score-label {
      font-size: 0.75rem;
      color: rgba(255, 255, 255, 0.6);
    }

    .difficulty-badge {
      padding: 4px 10px;
      border-radius: 15px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
    }

    .difficulty-easy {
      background: rgba(0, 184, 148, 0.2);
      color: var(--success);
    }

    .difficulty-medium {
      background: rgba(253, 203, 110, 0.2);
      color: var(--warning);
    }

    .difficulty-hard {
      background: rgba(214, 48, 49, 0.2);
      color: var(--danger);
    }

    .activity-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .activity-item:last-child {
      border-bottom: none;
    }

    .activity-info {
      flex: 1;
    }

    .activity-info h5 {
      margin: 0 0 3px 0;
      color: white;
      font-size: 0.9rem;
    }

    .activity-info p {
      margin: 0;
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.8rem;
    }

    .activity-score {
      padding: 3px 8px;
      border-radius: 10px;
      font-size: 0.8rem;
      font-weight: 600;
      margin-left: 10px;
    }

    .activity-time {
      font-size: 0.75rem;
      color: rgba(255, 255, 255, 0.5);
      margin-left: 10px;
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: rgba(255, 255, 255, 0.6);
    }

    .empty-state i {
      font-size: 2.5rem;
      margin-bottom: 15px;
      opacity: 0.5;
    }

    .alert {
      padding: 15px 20px;
      border-radius: 10px;
      margin-bottom: 20px;
      font-weight: 500;
    }

    .alert-error {
      background: rgba(214, 48, 49, 0.2);
      border: 1px solid rgba(214, 48, 49, 0.3);
      color: var(--danger);
    }

    /* Filters Section */
    .filters-section {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      margin-bottom: 30px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
    }

    .filters-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px 25px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      cursor: pointer;
    }

    .filters-header h3 {
      margin: 0;
      color: white;
      font-size: 1.2rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .toggle-btn {
      background: none;
      border: none;
      color: rgba(255, 255, 255, 0.7);
      font-size: 1rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .toggle-btn:hover {
      color: white;
    }

    .filters-content {
      padding: 25px;
      display: none;
    }

    .filters-content.show {
      display: block;
    }

    .filter-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 20px;
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .filter-group label {
      color: rgba(255, 255, 255, 0.9);
      font-size: 0.9rem;
      font-weight: 500;
    }

    .filter-group select,
    .filter-group input {
      padding: 10px 12px;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 8px;
      color: white;
      font-size: 0.9rem;
    }

    .filter-group select:focus,
    .filter-group input:focus {
      outline: none;
      border-color: var(--primary);
      background: rgba(255, 255, 255, 0.15);
    }

    /* Fix dropdown options background */
    .filter-group select option {
      background: rgba(26, 26, 46, 0.98);
      color: white;
      padding: 8px 12px;
    }

    .filter-group select option:hover,
    .filter-group select option:focus {
      background: rgba(108, 92, 231, 0.3);
      color: white;
    }

    .filter-group select option:checked {
      background: var(--primary);
      color: white;
    }

    .filter-actions {
      display: flex;
      gap: 15px;
      justify-content: flex-end;
    }

    .apply-btn,
    .clear-btn {
      padding: 10px 20px;
      border-radius: 8px;
      font-size: 0.9rem;
      font-weight: 500;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.3s ease;
      border: none;
      cursor: pointer;
    }

    .apply-btn {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
    }

    .apply-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(108, 92, 231, 0.4);
    }

    .clear-btn {
      background: rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.8);
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .clear-btn:hover {
      background: rgba(255, 255, 255, 0.15);
      color: white;
    }

    /* Individual Metrics */
    .individual-metrics {
      max-height: 500px;
      overflow-y: auto;
    }

    .student-summary {
      background: rgba(255, 255, 255, 0.05);
      padding: 20px;
      border-radius: 10px;
      margin-bottom: 20px;
    }

    .student-summary h4 {
      margin: 0 0 15px 0;
      color: white;
      font-size: 1.2rem;
    }

    .summary-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 15px;
    }

    .summary-item {
      text-align: center;
      padding: 10px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
    }

    .summary-number {
      display: block;
      font-size: 1.5rem;
      font-weight: bold;
      color: var(--accent);
      margin-bottom: 5px;
    }

    .summary-label {
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.7);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .quiz-results-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .quiz-result-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 15px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      transition: all 0.3s ease;
    }

    .quiz-result-item:hover {
      background: rgba(255, 255, 255, 0.1);
    }

    .quiz-result-info h5 {
      margin: 0 0 5px 0;
      color: white;
      font-size: 1rem;
    }

    .quiz-result-info p {
      margin: 0;
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.85rem;
    }

    .quiz-result-score {
      text-align: right;
    }

    .score-percentage {
      display: block;
      font-size: 1.2rem;
      font-weight: bold;
      margin-bottom: 3px;
    }

    .score-percentage.excellent {
      color: var(--success);
    }

    .score-percentage.good {
      color: var(--warning);
    }

    .score-percentage.poor {
      color: var(--danger);
    }

    .score-details {
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.6);
    }

    @media (max-width: 768px) {
      .page-header {
        flex-direction: column;
        gap: 20px;
        text-align: center;
      }

      .analytics-grid {
        grid-template-columns: 1fr;
        gap: 20px;
      }

      .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .performance-item,
      .activity-item {
        flex-direction: column;
        gap: 10px;
        text-align: center;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="admin-container">
    <div class="page-header">
      <h1 class="page-title">Performance Analytics</h1>
      <a href="admin.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
      <div class="filters-header">
        <h3><i class="fas fa-filter"></i> Filters</h3>
        <button type="button" id="toggleFilters" class="toggle-btn">
          <i class="fas fa-chevron-down"></i>
        </button>
      </div>

      <div class="filters-content" id="filtersContent">
        <form method="GET" action="" class="filters-form">
          <div class="filter-row">
            <div class="filter-group">
              <label for="filter_class">Class</label>
              <select name="filter_class" id="filter_class">
                <option value="">All Classes</option>
                <?php foreach ($all_classes as $class): ?>
                  <option value="<?php echo $class['class_id']; ?>" <?php echo $filter_class == $class['class_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($class['class_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-group">
              <label for="filter_student">Student</label>
              <select name="filter_student" id="filter_student">
                <option value="">All Students</option>
                <?php foreach ($all_students as $student): ?>
                  <option value="<?php echo $student['user_id']; ?>" <?php echo $filter_student == $student['user_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($student['name'] . ' (@' . $student['username'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-group">
              <label for="filter_date_from">From Date</label>
              <input type="date" name="filter_date_from" id="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>

            <div class="filter-group">
              <label for="filter_date_to">To Date</label>
              <input type="date" name="filter_date_to" id="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
          </div>

          <div class="filter-actions">
            <button type="submit" class="apply-btn">
              <i class="fas fa-search"></i> Apply Filters
            </button>
            <a href="performance-analytics.php" class="clear-btn">
              <i class="fas fa-times"></i> Clear All
            </a>
          </div>
        </form>
      </div>
    </div>

    <?php if (isset($error_message)): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
      </div>
    <?php endif; ?>

    <div class="analytics-grid">
      <!-- Overall Performance Overview -->
      <div class="analytics-card">
        <div class="card-header">
          <div class="card-icon overview">
            <i class="fas fa-chart-bar"></i>
          </div>
          <h2 class="card-title">Overall Performance</h2>
        </div>

        <div class="metrics-grid">
          <div class="metric-item">
            <span class="metric-number"><?php echo $overall_stats['active_students'] ?: '0'; ?></span>
            <span class="metric-label">Active Students</span>
          </div>
          <div class="metric-item">
            <span class="metric-number"><?php echo $overall_stats['total_submissions'] ?: '0'; ?></span>
            <span class="metric-label">Total Submissions</span>
          </div>
          <div class="metric-item">
            <span class="metric-number"><?php echo $overall_stats['overall_avg_score'] ? round($overall_stats['overall_avg_score'], 1) . '%' : '0%'; ?></span>
            <span class="metric-label">Average Score</span>
          </div>
          <div class="metric-item">
            <span class="metric-number"><?php echo $overall_stats['highest_score'] ?: '0'; ?>%</span>
            <span class="metric-label">Highest Score</span>
          </div>
        </div>
      </div>

      <!-- Class Performance -->
      <div class="analytics-card">
        <div class="card-header">
          <div class="card-icon performance">
            <i class="fas fa-school"></i>
          </div>
          <h2 class="card-title">Class Performance</h2>
        </div>

        <div class="performance-list">
          <?php if (empty($class_performance)): ?>
            <div class="empty-state">
              <i class="fas fa-chart-line"></i>
              <p>No class performance data available</p>
            </div>
          <?php else: ?>
            <?php foreach ($class_performance as $class): ?>
              <div class="performance-item">
                <div class="item-info">
                  <h4><?php echo htmlspecialchars($class['class_name']); ?></h4>
                  <p><?php echo $class['student_count']; ?> students • <?php echo $class['submissions']; ?> submissions</p>
                </div>
                <div class="item-score">
                  <span class="score-value"><?php echo round($class['avg_score'], 1); ?>%</span>
                  <span class="score-label">Average</span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Top Performing Students -->
      <div class="analytics-card">
        <div class="card-header">
          <div class="card-icon students">
            <i class="fas fa-trophy"></i>
          </div>
          <h2 class="card-title">Top Students</h2>
        </div>

        <div class="performance-list">
          <?php if (empty($top_students)): ?>
            <div class="empty-state">
              <i class="fas fa-user-graduate"></i>
              <p>No student performance data available</p>
            </div>
          <?php else: ?>
            <?php foreach ($top_students as $index => $student): ?>
              <div class="performance-item">
                <div class="item-info">
                  <h4><?php echo ($index + 1) . '. ' . htmlspecialchars($student['username']); ?></h4>
                  <p><?php echo $student['quiz_count']; ?> quizzes completed</p>
                </div>
                <div class="item-score">
                  <span class="score-value"><?php echo round($student['avg_score'], 1); ?>%</span>
                  <span class="score-label">Average</span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quiz Difficulty Analysis -->
      <div class="analytics-card">
        <div class="card-header">
          <div class="card-icon difficulty">
            <i class="fas fa-clipboard-list"></i>
          </div>
          <h2 class="card-title">Quiz Difficulty</h2>
        </div>

        <div class="performance-list">
          <?php if (empty($quiz_difficulty)): ?>
            <div class="empty-state">
              <i class="fas fa-question-circle"></i>
              <p>No quiz difficulty data available</p>
            </div>
          <?php else: ?>
            <?php foreach ($quiz_difficulty as $quiz): ?>
              <div class="performance-item">
                <div class="item-info">
                  <h4><?php echo htmlspecialchars($quiz['title']); ?></h4>
                  <p><?php echo $quiz['attempts']; ?> attempts • <?php echo $quiz['duration']; ?> min</p>
                </div>
                <div class="item-score">
                  <span class="difficulty-badge difficulty-<?php echo strtolower($quiz['difficulty_level']); ?>">
                    <?php echo $quiz['difficulty_level']; ?>
                  </span>
                  <span class="score-value"><?php echo round($quiz['avg_score'], 1); ?>%</span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="analytics-card" style="grid-column: 1 / -1;">
        <div class="card-header">
          <div class="card-icon overview">
            <i class="fas fa-clock"></i>
          </div>
          <h2 class="card-title">Recent Activity</h2>
        </div>

        <div class="performance-list">
          <?php if (empty($recent_activity)): ?>
            <div class="empty-state">
              <i class="fas fa-history"></i>
              <p>No recent activity data available</p>
            </div>
          <?php else: ?>
            <?php foreach ($recent_activity as $activity): ?>
              <div class="activity-item">
                <div class="activity-info">
                  <h5><?php echo htmlspecialchars($activity['student_name']); ?> completed "<?php echo htmlspecialchars($activity['quiz_title']); ?>"</h5>
                  <p><?php echo htmlspecialchars($activity['class_name'] ?: 'No class'); ?></p>
                </div>
                <span class="activity-score <?php echo $activity['score'] >= 80 ? 'score-excellent' : ($activity['score'] >= 60 ? 'score-good' : 'score-poor'); ?>">
                  <?php echo $activity['score']; ?>%
                </span>
                <span class="activity-time">
                  <?php echo date('M j, g:i A', strtotime($activity['completed_at'])); ?>
                </span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Individual Student Metrics (shown when student filter is applied) -->
      <?php if (!empty($filter_student) && !empty($individual_metrics)): ?>
        <div class="analytics-card" style="grid-column: 1 / -1;">
          <div class="card-header">
            <div class="card-icon students">
              <i class="fas fa-user-graduate"></i>
            </div>
            <h2 class="card-title">Individual Student Performance</h2>
          </div>

          <div class="individual-metrics">
            <?php
            $selected_student = null;
            foreach ($all_students as $student) {
              if ($student['user_id'] == $filter_student) {
                $selected_student = $student;
                break;
              }
            }
            ?>

            <div class="student-summary">
              <h4><?php echo htmlspecialchars($selected_student['name']); ?> (@<?php echo htmlspecialchars($selected_student['username']); ?>)</h4>
              <div class="summary-stats">
                <div class="summary-item">
                  <span class="summary-number"><?php echo count($individual_metrics); ?></span>
                  <span class="summary-label">Quizzes Taken</span>
                </div>
                <div class="summary-item">
                  <span class="summary-number"><?php echo round(array_sum(array_column($individual_metrics, 'score')) / count($individual_metrics), 1); ?>%</span>
                  <span class="summary-label">Average Score</span>
                </div>
                <div class="summary-item">
                  <span class="summary-number"><?php echo max(array_column($individual_metrics, 'score')); ?>%</span>
                  <span class="summary-label">Best Score</span>
                </div>
              </div>
            </div>

            <div class="quiz-results-list">
              <?php foreach ($individual_metrics as $metric): ?>
                <div class="quiz-result-item">
                  <div class="quiz-result-info">
                    <h5><?php echo htmlspecialchars($metric['quiz_title']); ?></h5>
                    <p>
                      <?php echo htmlspecialchars($metric['class_name'] ?: 'No class'); ?> •
                      <?php echo date('M j, Y g:i A', strtotime($metric['completed_at'])); ?>
                    </p>
                  </div>
                  <div class="quiz-result-score">
                    <span class="score-percentage <?php echo $metric['score'] >= 80 ? 'excellent' : ($metric['score'] >= 60 ? 'good' : 'poor'); ?>">
                      <?php echo $metric['score']; ?>%
                    </span>
                    <span class="score-details">
                      <?php echo $metric['total_score']; ?>/<?php echo $metric['total_questions']; ?> points
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
  <script>
    particlesJS('particles-js', {
      particles: {
        number: {
          value: 80,
          density: {
            enable: true,
            value_area: 800
          }
        },
        color: {
          value: '#6c5ce7'
        },
        shape: {
          type: 'circle'
        },
        opacity: {
          value: 0.5,
          random: false
        },
        size: {
          value: 3,
          random: true
        },
        line_linked: {
          enable: true,
          distance: 150,
          color: '#6c5ce7',
          opacity: 0.4,
          width: 1
        },
        move: {
          enable: true,
          speed: 6,
          direction: 'none',
          random: false,
          straight: false,
          out_mode: 'out',
          bounce: false
        }
      },
      interactivity: {
        detect_on: 'canvas',
        events: {
          onhover: {
            enable: true,
            mode: 'repulse'
          },
          onclick: {
            enable: true,
            mode: 'push'
          },
          resize: true
        },
        modes: {
          grab: {
            distance: 400,
            line_linked: {
              opacity: 1
            }
          },
          bubble: {
            distance: 400,
            size: 40,
            duration: 2,
            opacity: 8,
            speed: 3
          },
          repulse: {
            distance: 200,
            duration: 0.4
          },
          push: {
            particles_nb: 4
          },
          remove: {
            particles_nb: 2
          }
        }
      },
      retina_detect: true
    });

    // Filter toggle functionality
    document.addEventListener('DOMContentLoaded', function() {
      const toggleBtn = document.getElementById('toggleFilters');
      const filtersContent = document.getElementById('filtersContent');
      const toggleIcon = toggleBtn.querySelector('i');

      // Show filters if any filter is applied
      const hasFilters = <?php echo (!empty($filter_class) || !empty($filter_student) || !empty($filter_date_from) || !empty($filter_date_to)) ? 'true' : 'false'; ?>;

      if (hasFilters) {
        filtersContent.classList.add('show');
        toggleIcon.classList.remove('fa-chevron-down');
        toggleIcon.classList.add('fa-chevron-up');
      }

      toggleBtn.addEventListener('click', function() {
        filtersContent.classList.toggle('show');

        if (filtersContent.classList.contains('show')) {
          toggleIcon.classList.remove('fa-chevron-down');
          toggleIcon.classList.add('fa-chevron-up');
        } else {
          toggleIcon.classList.remove('fa-chevron-up');
          toggleIcon.classList.add('fa-chevron-down');
        }
      });

      // Auto-submit form when filters change (optional)
      const filterInputs = document.querySelectorAll('#filtersContent select, #filtersContent input');
      filterInputs.forEach(input => {
        input.addEventListener('change', function() {
          // Optional: Auto-submit on change
          // this.form.submit();
        });
      });
    });
  </script>
</body>

</html>