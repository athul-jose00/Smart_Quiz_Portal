<?php
session_start();
require_once '../../includes/db.php';

// Make sure the user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
  header("Location: ../../auth/login.php");
  exit();
}

$user_id = $_SESSION['user_id'];
$quiz_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$attempt_number = isset($_GET['attempt']) ? intval($_GET['attempt']) : 0;

if (!$quiz_id) {
  header("Location: results.php");
  exit();
}

// If no attempt specified, get the latest attempt
if (!$attempt_number) {
  $latest_attempt_sql = "SELECT MAX(COALESCE(attempt_number, 1)) as latest_attempt FROM results WHERE user_id = ? AND quiz_id = ?";
  $latest_stmt = $conn->prepare($latest_attempt_sql);
  if (!$latest_stmt) {
    die("Prepare failed: " . $conn->error);
  }
  $latest_stmt->bind_param("ii", $user_id, $quiz_id);
  $latest_stmt->execute();
  $latest_result = $latest_stmt->get_result();
  $latest_row = $latest_result->fetch_assoc();
  $attempt_number = $latest_row['latest_attempt'] ?: 1;
  $latest_stmt->close();
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

// Get quiz result details for specific attempt
$result_sql = "SELECT r.result_id, r.total_score, r.percentage, r.completed_at, 
               COALESCE(r.attempt_number, 1) as attempt_number,
               q.title as quiz_title, q.time_limit,
               c.class_id, c.class_name, c.class_code, u.name as teacher_name,
               (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id = q.quiz_id) as total_questions
               FROM results r
               JOIN quizzes q ON r.quiz_id = q.quiz_id
               JOIN classes c ON q.class_id = c.class_id
               JOIN users u ON c.teacher_id = u.user_id
               WHERE r.user_id = ? AND r.quiz_id = ? AND COALESCE(r.attempt_number, 1) = ?";
$result_stmt = $conn->prepare($result_sql);
$result_stmt->bind_param("iii", $user_id, $quiz_id, $attempt_number);
$result_stmt->execute();
$result_result = $result_stmt->get_result();
$quiz_result = $result_result->fetch_assoc();
$result_stmt->close();

// Get all attempts for this quiz
$all_attempts_sql = "SELECT COALESCE(attempt_number, 1) as attempt_number, percentage, completed_at FROM results 
                     WHERE user_id = ? AND quiz_id = ? 
                     ORDER BY COALESCE(attempt_number, 1) DESC";
$all_attempts_stmt = $conn->prepare($all_attempts_sql);
$all_attempts_stmt->bind_param("ii", $user_id, $quiz_id);
$all_attempts_stmt->execute();
$all_attempts_result = $all_attempts_stmt->get_result();
$all_attempts = $all_attempts_result->fetch_all(MYSQLI_ASSOC);
$all_attempts_stmt->close();

if (!$quiz_result) {
  header("Location: results.php?error=Result not found");
  exit();
}

// Get detailed question responses for specific attempt
$responses_sql = "SELECT q.question_id, q.question_text, q.points,
                  r.selected_option,
                  o_selected.option_text as selected_text,
                  o_correct.option_id as correct_option_id,
                  o_correct.option_text as correct_text,
                  CASE WHEN o_selected.is_correct = 1 THEN 1 ELSE 0 END as is_correct
                  FROM questions q
                  LEFT JOIN responses r ON q.question_id = r.question_id AND r.user_id = ? AND COALESCE(r.attempt_number, 1) = ?
                  LEFT JOIN options o_selected ON r.selected_option = o_selected.option_id
                  LEFT JOIN options o_correct ON q.question_id = o_correct.question_id AND o_correct.is_correct = 1
                  WHERE q.quiz_id = ?
                  ORDER BY q.question_id";
$responses_stmt = $conn->prepare($responses_sql);
$responses_stmt->bind_param("iii", $user_id, $attempt_number, $quiz_id);
$responses_stmt->execute();
$responses_result = $responses_stmt->get_result();
$detailed_responses = $responses_result->fetch_all(MYSQLI_ASSOC);
$responses_stmt->close();

// Calculate statistics
$correct_answers = 0;
$total_possible_points = 0;
foreach ($detailed_responses as $response) {
  $total_possible_points += $response['points'];
  if ($response['is_correct']) {
    $correct_answers++;
  }
}

// Get class average for comparison
$class_avg_sql = "SELECT AVG(r.percentage) as class_average
                  FROM results r
                  JOIN quizzes q ON r.quiz_id = q.quiz_id
                  WHERE q.class_id = ? AND r.quiz_id = ?";
$class_avg_stmt = $conn->prepare($class_avg_sql);
$class_avg_stmt->bind_param("ii", $quiz_result['class_id'], $quiz_id);
$class_avg_stmt->execute();
$class_avg_result = $class_avg_stmt->get_result();
$class_average = $class_avg_result->fetch_assoc()['class_average'];
$class_avg_stmt->close();

$class_average = $class_average ? round($class_average, 1) : 0;

// Determine grade
function getGrade($percentage)
{
  if ($percentage >= 90) return 'A';
  elseif ($percentage >= 80) return 'B';
  elseif ($percentage >= 70) return 'C';
  elseif ($percentage >= 60) return 'D';
  else return 'F';
}

$grade = getGrade($quiz_result['percentage']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Quiz Result - <?php echo htmlspecialchars($quiz_result['quiz_title']); ?> | Smart Quiz Portal</title>
  <link rel="stylesheet" href="../../css/style.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    /* Result Header */
    .result-header {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 16px;
      padding: 2rem;
      margin-bottom: 2rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
      text-align: center;
    }

    .quiz-title {
      font-size: 1.8rem;
      margin-bottom: 0.5rem;
      color: white;
      font-weight: 600;
    }

    .quiz-info {
      color: rgba(255, 255, 255, 0.7);
      margin-bottom: 2rem;
    }

    .grade-display {
      display: inline-block;
      font-size: 4rem;
      font-weight: bold;
      padding: 1rem 2rem;
      border-radius: 20px;
      margin-bottom: 1rem;
    }

    .grade-A {
      background: rgba(46, 204, 113, 0.2);
      color: #2ecc71;
    }

    .grade-B {
      background: rgba(52, 152, 219, 0.2);
      color: #3498db;
    }

    .grade-C {
      background: rgba(241, 196, 15, 0.2);
      color: #f1c40f;
    }

    .grade-D {
      background: rgba(230, 126, 34, 0.2);
      color: #e67e22;
    }

    .grade-F {
      background: rgba(231, 76, 60, 0.2);
      color: #e74c3c;
    }

    .score-details {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 1rem;
      margin-top: 2rem;
    }

    .score-item {
      background: rgba(255, 255, 255, 0.05);
      padding: 1rem;
      border-radius: 8px;
      text-align: center;
    }

    .score-value {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--accent);
      margin-bottom: 0.25rem;
    }

    .score-label {
      font-size: 0.9rem;
      color: rgba(255, 255, 255, 0.7);
    }

    /* Statistics Grid */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .stat-card {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 1.5rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
      text-align: center;
    }

    .stat-card i {
      font-size: 1.8rem;
      margin-bottom: 10px;
      color: var(--accent);
    }

    .stat-card h3 {
      font-size: 1.8rem;
      margin-bottom: 5px;
      color: white;
    }

    .stat-card p {
      color: rgba(255, 255, 255, 0.75);
      font-size: 0.9rem;
    }

    /* Question Review */
    .questions-section {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 2rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
    }

    .section-title {
      font-size: 1.5rem;
      color: var(--accent);
      margin-bottom: 2rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .question-item {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      border-left: 4px solid transparent;
    }

    .question-item.correct {
      border-left-color: #2ecc71;
    }

    .question-item.incorrect {
      border-left-color: #e74c3c;
    }

    .question-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 1rem;
    }

    .question-number {
      background: rgba(108, 92, 231, 0.2);
      color: var(--accent);
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
    }

    .question-points {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
    }

    .question-text {
      font-size: 1.1rem;
      color: white;
      margin-bottom: 1rem;
      line-height: 1.5;
    }

    .answer-section {
      display: grid;
      gap: 0.75rem;
    }

    .answer-item {
      padding: 0.75rem 1rem;
      border-radius: 6px;
      font-size: 0.95rem;
    }

    .answer-item.selected {
      background: rgba(108, 92, 231, 0.2);
      border: 1px solid rgba(108, 92, 231, 0.4);
      color: white;
    }

    .answer-item.correct {
      background: rgba(46, 204, 113, 0.2);
      border: 1px solid rgba(46, 204, 113, 0.4);
      color: #2ecc71;
    }

    .answer-item.incorrect {
      background: rgba(231, 76, 60, 0.2);
      border: 1px solid rgba(231, 76, 60, 0.4);
      color: #e74c3c;
    }

    .answer-item.default {
      background: rgba(255, 255, 255, 0.05);
      color: rgba(255, 255, 255, 0.8);
    }

    .answer-label {
      font-weight: 600;
      margin-right: 8px;
    }

    .result-status {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 1rem;
      font-weight: 600;
    }

    .result-status.correct {
      color: #2ecc71;
    }

    .result-status.incorrect {
      color: #e74c3c;
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 1rem;
      margin-top: 2rem;
      flex-wrap: wrap;
    }

    .btn {
      padding: 12px 24px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-primary {
      background: var(--primary);
      color: white;
    }

    .btn-outline {
      background: transparent;
      color: rgba(255, 255, 255, 0.8);
      border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .btn:hover {
      transform: translateY(-2px);
    }

    /* Chart Container */
    .chart-container {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 2rem;
      margin-bottom: 2rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
    }

    .chart-wrapper {
      position: relative;
      height: 300px;
      margin-top: 1rem;
    }

    /* Attempt Selector */
    .attempt-selector {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
    }

    .attempt-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
    }

    .attempt-title {
      font-size: 1.2rem;
      color: var(--accent);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .attempt-dropdown {
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 8px;
      padding: 8px 12px;
      color: white;
      font-size: 0.95rem;
      cursor: pointer;
    }

    .attempt-dropdown:focus {
      outline: none;
      border-color: var(--primary);
    }

    .attempt-dropdown option {
      background: #1a1a2e;
      color: white;
    }

    .attempts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 1rem;
    }

    .attempt-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      padding: 1rem;
      border: 2px solid transparent;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .attempt-card.current {
      border-color: var(--primary);
      background: rgba(108, 92, 231, 0.1);
    }

    .attempt-card:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .attempt-number {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--accent);
      margin-bottom: 0.5rem;
    }

    .attempt-score {
      font-size: 1.5rem;
      font-weight: bold;
      color: white;
      margin-bottom: 0.25rem;
    }

    .attempt-date {
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.6);
    }

    .btn-success {
      background: #2ecc71;
      color: white;
    }

    .btn-success:hover {
      background: #27ae60;
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
          <a href="quizzes.php" class="menu-item">
            <i class="fas fa-question-circle"></i> Available Quizzes
          </a>
          <a href="results.php" class="menu-item active">
            <i class="fas fa-chart-bar"></i> My Results
          </a>
          <a href="join-class.php" class="menu-item">
            <i class="fas fa-plus-circle"></i> Join Class
          </a>
        </nav>
      </aside>

      <!-- Main Content -->
      <main class="main-content">
        <!-- Attempt Selector -->
        <?php if (count($all_attempts) > 1): ?>
          <div class="attempt-selector">
            <div class="attempt-header">
              <h2 class="attempt-title">
                <i class="fas fa-redo"></i>
                Quiz Attempts
              </h2>
              <select class="attempt-dropdown" onchange="changeAttempt(this.value)">
                <?php foreach ($all_attempts as $attempt): ?>
                  <option value="<?php echo $attempt['attempt_number']; ?>" <?php echo $attempt['attempt_number'] == $attempt_number ? 'selected' : ''; ?>>
                    Attempt <?php echo $attempt['attempt_number']; ?> - <?php echo $attempt['percentage']; ?>%
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="attempts-grid">
              <?php foreach ($all_attempts as $attempt): ?>
                <div class="attempt-card <?php echo $attempt['attempt_number'] == $attempt_number ? 'current' : ''; ?>"
                  onclick="changeAttempt(<?php echo $attempt['attempt_number']; ?>)">
                  <div class="attempt-number">Attempt <?php echo $attempt['attempt_number']; ?></div>
                  <div class="attempt-score"><?php echo $attempt['percentage']; ?>%</div>
                  <div class="attempt-date"><?php echo date('M d, Y g:i A', strtotime($attempt['completed_at'])); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Result Header -->
        <div class="result-header">
          <h1 class="quiz-title"><?php echo htmlspecialchars($quiz_result['quiz_title']); ?></h1>
          <div class="quiz-info">
            <?php echo htmlspecialchars($quiz_result['class_name']); ?> •
            <?php echo htmlspecialchars($quiz_result['teacher_name']); ?> •
            Attempt <?php echo $quiz_result['attempt_number']; ?> •
            Completed on <?php echo date('M d, Y \a\t g:i A', strtotime($quiz_result['completed_at'])); ?>
          </div>

          <div class="grade-display grade-<?php echo $grade; ?>">
            <?php echo $grade; ?>
          </div>

          <div class="score-details">
            <div class="score-item">
              <div class="score-value"><?php echo $quiz_result['percentage']; ?>%</div>
              <div class="score-label">Your Score</div>
            </div>
            <div class="score-item">
              <div class="score-value"><?php echo $quiz_result['total_score']; ?>/<?php echo $total_possible_points; ?></div>
              <div class="score-label">Points Earned</div>
            </div>
            <div class="score-item">
              <div class="score-value"><?php echo $correct_answers; ?>/<?php echo $quiz_result['total_questions']; ?></div>
              <div class="score-label">Correct Answers</div>
            </div>
            <div class="score-item">
              <div class="score-value"><?php echo $class_average; ?>%</div>
              <div class="score-label">Class Average</div>
            </div>
          </div>
        </div>

        <!-- Performance Chart -->
        <div class="chart-container">
          <h2 class="section-title">
            <i class="fas fa-chart-line"></i>
            Performance Analysis
          </h2>
          <div class="chart-wrapper">
            <canvas id="performanceChart"></canvas>
          </div>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
          <div class="stat-card">
            <i class="fas fa-clock"></i>
            <h3><?php echo $quiz_result['time_limit']; ?> min</h3>
            <p>Time Limit</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-question-circle"></i>
            <h3><?php echo $quiz_result['total_questions']; ?></h3>
            <p>Total Questions</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <h3><?php echo $correct_answers; ?></h3>
            <p>Correct Answers</p>
          </div>

          <div class="stat-card">
            <i class="fas fa-times-circle"></i>
            <h3><?php echo ($quiz_result['total_questions'] - $correct_answers); ?></h3>
            <p>Incorrect Answers</p>
          </div>
        </div>

        <!-- Question Review -->
        <div class="questions-section">
          <h2 class="section-title">
            <i class="fas fa-list-alt"></i>
            Question Review
          </h2>

          <?php foreach ($detailed_responses as $index => $response): ?>
            <div class="question-item <?php echo $response['is_correct'] ? 'correct' : 'incorrect'; ?>">
              <div class="question-header">
                <span class="question-number">Question <?php echo ($index + 1); ?></span>
                <span class="question-points"><?php echo $response['points']; ?> points</span>
              </div>

              <div class="question-text">
                <?php echo htmlspecialchars($response['question_text']); ?>
              </div>

              <div class="answer-section">
                <?php if ($response['selected_option']): ?>
                  <div class="answer-item <?php echo $response['is_correct'] ? 'correct selected' : 'incorrect selected'; ?>">
                    <span class="answer-label">Your Answer:</span>
                    <?php echo htmlspecialchars($response['selected_text']); ?>
                  </div>
                <?php else: ?>
                  <div class="answer-item incorrect">
                    <span class="answer-label">Your Answer:</span>
                    <em>No answer selected</em>
                  </div>
                <?php endif; ?>

                <?php if (!$response['is_correct']): ?>
                  <div class="answer-item correct">
                    <span class="answer-label">Correct Answer:</span>
                    <?php echo htmlspecialchars($response['correct_text']); ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="result-status <?php echo $response['is_correct'] ? 'correct' : 'incorrect'; ?>">
                <i class="fas fa-<?php echo $response['is_correct'] ? 'check-circle' : 'times-circle'; ?>"></i>
                <?php echo $response['is_correct'] ? 'Correct' : 'Incorrect'; ?>
                <?php if ($response['is_correct']): ?>
                  (+<?php echo $response['points']; ?> points)
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
          <a href="take-quiz.php?id=<?php echo $quiz_id; ?>" class="btn btn-success">
            <i class="fas fa-redo"></i> Take Again
          </a>
          <a href="class-details.php?id=<?php echo $quiz_result['class_id']; ?>" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i> Back to Class
          </a>
          <a href="results.php" class="btn btn-outline">
            <i class="fas fa-chart-bar"></i> All Results
          </a>
          <a href="quizzes.php" class="btn btn-outline">
            <i class="fas fa-play"></i> Take Another Quiz
          </a>
        </div>
      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      // Particles.js configuration
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

      // Performance Chart
      const ctx = document.getElementById('performanceChart').getContext('2d');
      const performanceChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: ['Correct Answers', 'Incorrect Answers'],
          datasets: [{
            data: [<?php echo $correct_answers; ?>, <?php echo ($quiz_result['total_questions'] - $correct_answers); ?>],
            backgroundColor: [
              'rgba(46, 204, 113, 0.8)',
              'rgba(231, 76, 60, 0.8)'
            ],
            borderColor: [
              'rgba(46, 204, 113, 1)',
              'rgba(231, 76, 60, 1)'
            ],
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                color: 'rgba(255, 255, 255, 0.8)',
                padding: 20,
                font: {
                  size: 14
                }
              }
            },
            tooltip: {
              backgroundColor: 'rgba(26, 26, 46, 0.9)',
              titleColor: 'white',
              bodyColor: 'white',
              borderColor: 'rgba(255, 255, 255, 0.1)',
              borderWidth: 1,
              callbacks: {
                label: function(context) {
                  const label = context.label || '';
                  const value = context.parsed;
                  const total = context.dataset.data.reduce((a, b) => a + b, 0);
                  const percentage = Math.round((value / total) * 100);
                  return `${label}: ${value} (${percentage}%)`;
                }
              }
            },
            datalabels: {
              color: 'white',
              font: {
                size: 16,
                weight: 'bold'
              },
              formatter: function(value, context) {
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage = Math.round((value / total) * 100);
                return `${value}\n(${percentage}%)`;
              },
              textAlign: 'center'
            }
          }
        },
        plugins: [{
          id: 'centerText',
          beforeDraw: function(chart) {
            const ctx = chart.ctx;
            const centerX = chart.chartArea.left + (chart.chartArea.right - chart.chartArea.left) / 2;
            const centerY = chart.chartArea.top + (chart.chartArea.bottom - chart.chartArea.top) / 2;

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
            ctx.font = 'bold 18px Arial';
            ctx.fillText('<?php echo $quiz_result["percentage"]; ?>%', centerX, centerY - 10);
            ctx.font = '14px Arial';
            ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
            ctx.fillText('Overall Score', centerX, centerY + 15);
            ctx.restore();
          }
        }]
      });
    });

    // Function to change attempt
    function changeAttempt(attemptNumber) {
      window.location.href = `quiz-result.php?id=<?php echo $quiz_id; ?>&attempt=${attemptNumber}`;
    }
  </script>
</body>

</html>
<?php
$conn->close();
?>