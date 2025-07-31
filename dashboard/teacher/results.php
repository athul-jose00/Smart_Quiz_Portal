<?php
session_start();
require_once '../../includes/db.php';

// --- Authentication Check ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
  header("Location: ../../auth/login.php");
  exit();
}
$user_id = $_SESSION['user_id'];

// --- Get Teacher's Info ---
$teacher_sql = "SELECT name FROM users WHERE user_id = ?";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $user_id);
$teacher_stmt->execute();
$teacher_stmt->bind_result($full_name);
$teacher_stmt->fetch();
$teacher_stmt->close();
$initials = '';
foreach (explode(' ', $full_name ?? '') as $p) {
  if ($p) $initials .= strtoupper($p[0]);
}

// --- Fetch all classes for the dropdown ---
$classes_sql = "SELECT class_id, class_name FROM classes WHERE teacher_id = ? ORDER BY class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $user_id);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

// --- Initialize Variables ---
$selected_class_id = $selected_quiz_id = null;
$results = [];
$quiz_title = '';
$analytics = [
  'total' => 0,
  'avg' => 0,
  'min' => 0,
  'max' => 0,
  'median' => 0,
  'std' => 0,
  'distribution' => [],
  'participation' => null
];
$chart_labels = [];
$chart_data = [];

// --- Handle User Input (GET/POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $selected_class_id = intval($_POST['class_id'] ?? 0);
  $selected_quiz_id = intval($_POST['quiz_id'] ?? 0);
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $selected_class_id = intval($_GET['class_id'] ?? 0);
  $selected_quiz_id = intval($_GET['quiz_id'] ?? 0);
}

// --- Get Quizzes for the selected class ---
$quizzes = [];
if ($selected_class_id > 0) {
  $quizzes_sql = "SELECT quiz_id, title FROM quizzes WHERE class_id = ? AND created_by = ? ORDER BY created_at DESC";
  $quizzes_stmt = $conn->prepare($quizzes_sql);
  $quizzes_stmt->bind_param("ii", $selected_class_id, $user_id);
  $quizzes_stmt->execute();
  $quizzes_result = $quizzes_stmt->get_result();
  $quizzes = $quizzes_result->fetch_all(MYSQLI_ASSOC);
  $quizzes_stmt->close();
}

// --- Fetch Results and Calculate Analytics if a quiz is selected ---
if ($selected_quiz_id > 0) {
  // Verify quiz belongs to the teacher
  $verify_sql = "SELECT title FROM quizzes WHERE quiz_id = ? AND created_by = ?";
  $verify_stmt = $conn->prepare($verify_sql);
  $verify_stmt->bind_param("ii", $selected_quiz_id, $user_id);
  $verify_stmt->execute();
  $verify_stmt->bind_result($quiz_title);
  $exists = $verify_stmt->fetch();
  $verify_stmt->close();
  if ($exists) {
    // Fetch student results
    $results_sql = "SELECT r.user_id, u.name as student_name, u.email,
                               r.total_score, r.percentage, r.completed_at
                        FROM results r
                        JOIN users u ON r.user_id = u.user_id
                        WHERE r.quiz_id = ?
                        ORDER BY r.total_score DESC, r.completed_at ASC";
    $results_stmt = $conn->prepare($results_sql);
    $results_stmt->bind_param("i", $selected_quiz_id);
    $results_stmt->execute();
    $results_result = $results_stmt->get_result();
    $results = $results_result->fetch_all(MYSQLI_ASSOC);
    $results_stmt->close();
    if ($results) {
      $scores = array_column($results, 'total_score');
      $percentages = array_column($results, 'percentage');
      $analytics['total'] = count($results);
      $analytics['avg'] = round(array_sum($scores) / $analytics['total'], 2);
      $analytics['min'] = min($scores);
      $analytics['max'] = max($scores);
      // Median
      sort($scores);
      $mid = floor(($analytics['total'] - 1) / 2);
      $analytics['median'] = ($analytics['total'] % 2) ? $scores[$mid] : round(($scores[$mid] + $scores[$mid + 1]) / 2, 2);
      // Standard Deviation
      if ($analytics['total'] > 1) {
        $sum_sq_diff = array_sum(array_map(fn($x) => pow($x - $analytics['avg'], 2), $scores));
        $analytics['std'] = round(sqrt($sum_sq_diff / ($analytics['total'] - 1)), 2);
      }
      // Participation Rate
      $r = $conn->query("SELECT COUNT(*) FROM user_classes WHERE class_id=$selected_class_id");
      $class_student_count = $r ? intval($r->fetch_row()[0]) : 0;
      $analytics['participation'] = $class_student_count > 0 ? round(100 * $analytics['total'] / $class_student_count, 1) : null;
      // --- Prepare data for Chart.js ---
      $distribution = array_fill(0, 10, 0);
      foreach ($percentages as $p) {
        $bucket_index = floor(floatval($p) / 10);
        if ($bucket_index >= 10) $bucket_index = 9; // Group 100% with 90-99%
        $distribution[$bucket_index]++;
      }
      $chart_labels = ['0-9%', '10-19%', '20-29%', '30-39%', '40-49%', '50-59%', '60-69%', '70-79%', '80-89%', '90-100%'];
      $chart_data = array_values($distribution);
    }
  } else {
    $selected_quiz_id = 0; // Quiz not found or doesn't belong to teacher
  }
}

// --- CSV Export Logic ---
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $selected_quiz_id > 0 && !empty($results)) {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $quiz_title . '_results') . '.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Student Name', 'Email', 'Total Score', 'Percentage', 'Completed At']);
  foreach ($results as $r) {
    fputcsv($out, [$r['student_name'], $r['email'], $r['total_score'], $r['percentage'] . '%', $r['completed_at']]);
  }
  fclose($out);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Quiz Results & Analytics</title>
  <link rel="stylesheet" href="../../css/style.css">
  <link rel="stylesheet" href="./styles/sidebar.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Chart.js library -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* --- Base Layout Improvements --- */
    .main-content {
      padding: 2.2rem;
      /* Slightly reduced from 2.5rem for balance */
    }

    .dashboard-header {
      margin-bottom: 2.2rem;
      /* Match spacing */
    }

    .dashboard-header .page-title h1 {
      margin-bottom: 0.6rem;
      /* Space between title and subtitle */
    }

    .dashboard-header .page-title p {
      color: rgba(255, 255, 255, 0.75);
      /* Softer subtitle color */
      font-size: 1.05rem;
    }

    /* --- Filter Form Styling --- */
    .filter-form {
      background: rgba(255, 255, 255, 0.06);
      /* Slightly less opaque */
      padding: 1.7rem;
      /* Increased padding */
      border-radius: 14px;
      /* Slightly more rounded */
      margin-bottom: 2.5rem;
      /* Space below form */
      border: 1px solid rgba(255, 255, 255, 0.08);
      /* Softer border */
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      /* Subtle shadow */
    }

    .form-row {
      display: flex;
      gap: 1.5rem;
      align-items: flex-end;
      flex-wrap: wrap;
    }

    .form-group {
      flex: 1;
      min-width: 200px;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.55rem;
      /* Increased space */
      color: rgba(255, 255, 255, 0.85);
      /* Slightly brighter label */
      font-size: 0.95rem;
      /* Slightly larger font */
      font-weight: 500;
      /* Light bold */
    }

    select.form-control {
      width: 100%;
      background: rgba(26, 26, 46, 0.85);
      /* Darker background */
      color: #fff;
      border: 1.5px solid var(--primary);
      /* Slightly thicker border */
      border-radius: 10px;
      /* More rounded */
      padding: 11px 15px;
      /* Increased padding */
      font-size: 1.02rem;
      /* Slightly larger font */
      transition: all 0.25s ease;
      /* Smooth transitions */
      appearance: none;
      background-image: url("data:image/svg+xml;utf8,<svg fill='white' height='16' viewBox='0 0 24 24' width='16' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5'/></svg>");
      background-repeat: no-repeat;
      background-position: right 16px center;
      background-size: 18px 18px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1) inset;
      /* Inner shadow */
    }

    select.form-control:focus {
      border-color: var(--accent);
      background: rgba(26, 26, 46, 1);
      /* Solid background on focus */
      outline: none;
      box-shadow: 0 0 0 3px rgba(253, 121, 168, 0.3);
      /* Focus ring */
    }

    select.form-control:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .btn-primary {
      height: 46px;
      /* Align height with select */
      padding: 0 22px;
      /* More padding */
      font-size: 1.02rem;
      /* Larger font */
      font-weight: 500;
      /* Light bold */
      border-radius: 10px;
      /* Match select border radius */
      transition: background-color 0.25s ease, transform 0.15s ease;
      /* Smooth hover/active */
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      /* Button shadow */
    }

    .btn-primary:hover:not(:disabled) {
      transform: translateY(-2px);
      /* Lift effect on hover */
      box-shadow: 0 6px 8px rgba(0, 0, 0, 0.15);
    }

    .btn-primary:active:not(:disabled) {
      transform: translateY(0);
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .btn-primary:disabled {
      opacity: 0.7;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    /* --- Analytics Cards Styling --- */
    .analytics-cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
      /* Slightly wider min */
      gap: 1.8rem;
      /* Increased gap */
      margin-bottom: 2.5rem;
      /* Space below cards */
    }

    .analytics-card {
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
      /* Subtle gradient */
      border-radius: 12px;
      /* More rounded */
      padding: 1.4rem 1.2rem;
      /* Increased padding */
      text-align: center;
      border: 1px solid rgba(255, 255, 255, 0.07);
      /* Softer border */
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      /* Card shadow */
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      /* Hover effect */
    }

    .analytics-card:hover {
      transform: translateY(-3px);
      /* Lift on hover */
      box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }

    .analytics-label {
      font-size: 0.95rem;
      /* Slightly larger */
      color: rgba(255, 255, 255, 0.75);
      /* Softer label color */
      margin-bottom: 0.6rem;
      /* Increased space */
      font-weight: 500;
      /* Light bold */
    }

    .analytics-card h3 {
      font-size: 2.1rem;
      /* Slightly reduced from 2.2rem */
      margin: 0;
      color: white;
      line-height: 1.2;
    }

    .analytics-card h3 small {
      font-size: 1.2rem;
      color: var(--accent);
    }

    /* --- Chart Container Styling --- */
    .chart-container {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 20px;
      padding: 3rem 2.5rem;
      margin: 3rem 0;
      border: 1px solid rgba(255, 255, 255, 0.15);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
      backdrop-filter: blur(15px);
      min-height: 600px;
    }

    .chart-title {
      font-size: 1.8rem;
      margin-bottom: 2.5rem;
      color: rgba(255, 255, 255, 0.95);
      font-weight: 700;
      text-align: center;
      letter-spacing: 0.8px;
      text-transform: uppercase;
    }

    /* Make canvas responsive within its container */
    #scoreDistributionChart {
      max-width: 100%;
      height: 500px !important;
    }

    /* Additional Statistics Container */
    .distribution-stats-container {
      background: rgba(255, 255, 255, 0.06);
      border-radius: 16px;
      padding: 2rem;
      margin: 2rem 0;
      border: 1px solid rgba(255, 255, 255, 0.12);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1.5rem;
    }

    .stats-grid .stat-item {
      text-align: center;
      color: rgba(255, 255, 255, 0.85);
      padding: 1.5rem;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      transition: all 0.3s ease;
    }

    .stats-grid .stat-item:hover {
      background: rgba(255, 255, 255, 0.08);
      transform: translateY(-2px);
    }

    .stats-grid .stat-value {
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--accent);
      margin-bottom: 8px;
    }

    .stats-grid .stat-label {
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-weight: 500;
    }

    /* --- Results Table Styling --- */
    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.3rem;
      /* Space below header */
    }

    .section-title {
      font-size: 1.55rem;
      /* Slightly larger */
      margin-bottom: 0;
      color: rgba(255, 255, 255, 0.92);
      /* Slightly brighter */
      font-weight: 500;
    }

    .export-btn {
      background: var(--accent);
      color: white;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 19px;
      /* Increased padding */
      border-radius: 8px;
      /* Slightly more rounded */
      font-size: 1rem;
      /* Slightly larger */
      font-weight: 500;
      /* Light bold */
      transition: background-color 0.25s ease, transform 0.15s ease;
      /* Smooth hover/active */
      box-shadow: 0 3px 5px rgba(0, 0, 0, 0.1);
      /* Button shadow */
    }

    .export-btn:hover {
      background: #e0608a;
      transform: translateY(-2px);
      /* Lift effect */
      box-shadow: 0 5px 7px rgba(0, 0, 0, 0.15);
    }

    .export-btn:active {
      transform: translateY(0);
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .results-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 0;
      /* Removed top margin, handled by section-header */
      background: rgba(255, 255, 255, 0.04);
      /* Slightly less opaque */
      border-radius: 10px;
      /* More rounded */
      overflow: hidden;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
      /* Table shadow */
    }

    .results-table th,
    .results-table td {
      padding: 15px 18px;
      /* Increased padding */
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      /* Softer border */
    }

    .results-table th {
      background: rgba(108, 92, 231, 0.18);
      /* Adjusted opacity */
      color: var(--accent);
      font-weight: 600;
      font-size: 1.02rem;
      /* Slightly larger */
    }

    .results-table tr:last-child td {
      border-bottom: none;
    }

    .results-table tr:hover {
      background: rgba(255, 255, 255, 0.07);
      /* Slightly more opaque on hover */
    }

    /* --- Empty Message Styling --- */
    .empty-message {
      background: rgba(255, 255, 255, 0.04);
      border: 1px dashed rgba(255, 255, 255, 0.18);
      padding: 2.5rem;
      /* Increased padding */
      text-align: center;
      border-radius: 10px;
      /* More rounded */
      color: rgba(255, 255, 255, 0.65);
      /* Softer color */
      margin-top: 2.5rem;
      /* Space above */
      font-size: 1.1rem;
      /* Slightly larger */
    }

    /* --- Responsive Adjustments --- */
    @media (max-width: 768px) {
      .main-content {
        padding: 1.5rem;
      }

      .analytics-cards {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 1.2rem;
      }

      .analytics-card {
        padding: 1.1rem 0.9rem;
      }

      .analytics-card h3 {
        font-size: 1.8rem;
      }

      .chart-container {
        padding: 1.5rem 1rem;
        height: 350px;
      }

      .chart-title {
        font-size: 1.3rem;
      }

      .results-table th,
      .results-table td {
        padding: 12px 14px;
        font-size: 0.92rem;
      }

      .section-title {
        font-size: 1.3rem;
      }

      .export-btn {
        padding: 8px 15px;
        font-size: 0.92rem;
      }
    }
  </style>
</head>

<body>
  <div id="particles-js"></div>
  <div class="container-dashboard" style="padding: 1px">
    <!-- Header -->
    <header>
      <div class="logo">
        <h1>Smart Quiz Portal</h1>
      </div>

      <div class="auth-buttons">
        <div class="header-user">
          <button class="user-dropdown-btn" id="userDropdownBtn">
            <div class="user-avatar"><?= $initials ?></div>
            <span style="font-size: 1rem"><?= htmlspecialchars($full_name ?? 'Teacher') ?></span>
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
            <div class="teacher-avatar"><?= $initials ?></div>
            <div class="teacher-info">
              <h3><?= htmlspecialchars($full_name ?? 'Teacher'); ?></h3>
              <p>Teacher</p>
            </div>
          </div>
        </div>
        <nav class="sidebar-menu">
          <a href="dashboard.php" class="menu-item"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
          <a href="classes.php" class="menu-item"><i class="fas fa-users"></i>My Classes</a>
          <a href="quizzes.php" class="menu-item"><i class="fas fa-question-circle"></i>Quizzes</a>
          <a href="create-quiz.php" class="menu-item"><i class="fas fa-plus-circle"></i>Create Quiz</a>
          <a href="results.php" class="menu-item active"><i class="fas fa-chart-bar"></i>Results</a>
        </nav>
      </aside>
      <!-- Main Content -->
      <main class="main-content">
        <div class="dashboard-header">
          <div class="page-title">
            <h1>Quiz Results & Analytics</h1>
            <p>Analyze student performance, participation, and score distribution.</p>
          </div>
        </div>

        <!-- Filter Form -->
        <div class="filter-form">
          <form method="POST" action="results.php" id="filterForm">
            <div class="form-row">
              <div class="form-group">
                <label for="class_id">1. Select a Class</label>
                <select name="class_id" id="class_id" class="form-control" onchange="document.getElementById('filterForm').submit()">
                  <option value="">-- Choose Class --</option>
                  <?php foreach ($classes as $class): ?>
                    <option value="<?= $class['class_id'] ?>" <?= $selected_class_id == $class['class_id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($class['class_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label for="quiz_id">2. Select a Quiz</label>
                <select name="quiz_id" id="quiz_id" class="form-control" <?= !$selected_class_id ? 'disabled' : '' ?>>
                  <option value="">-- Choose Quiz --</option>
                  <?php foreach ($quizzes as $quiz): ?>
                    <option value="<?= $quiz['quiz_id'] ?>" <?= $selected_quiz_id == $quiz['quiz_id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($quiz['title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <button type="submit" class="btn btn-primary" <?= !$selected_class_id ? 'disabled' : '' ?>>View Results</button>
              </div>
            </div>
          </form>
        </div>

        <?php if ($selected_quiz_id > 0 && !empty($results)): ?>
          <!-- Analytics Section -->
          <div class="analytics-cards">
            <div class="analytics-card">
              <div class="analytics-label">Participants</div>
              <h3><?= $analytics['total'] ?></h3>
            </div>
            <div class="analytics-card">
              <div class="analytics-label">Avg. Score</div>
              <h3><?= $analytics['avg'] ?></h3>
            </div>
            <!-- Removed Median Score Card -->
            <!-- Removed Std. Deviation Card -->
            <div class="analytics-card">
              <div class="analytics-label">Highest Score</div>
              <h3><?= $analytics['max'] ?></h3>
            </div>
            <div class="analytics-card">
              <div class="analytics-label">Lowest Score</div>
              <h3><?= $analytics['min'] ?></h3>
            </div>
            <?php if (isset($analytics['participation'])): ?>
              <div class="analytics-card">
                <div class="analytics-label">Participation</div>
                <h3><?= $analytics['participation'] ?>%</h3>
              </div>
            <?php endif; ?>
          </div>

          <!-- Chart.js Container -->
          <div class="chart-container">
            <h3 class="chart-title">Score Distribution</h3>
            <canvas id="scoreDistributionChart"></canvas>
          </div>

          <!-- Detailed Results Table -->
          <div class="section-header">
            <h2 class="section-title">Detailed Student Results</h2>
            <a href="?export=csv&class_id=<?= $selected_class_id ?>&quiz_id=<?= $selected_quiz_id ?>" class="export-btn">
              <i class="fas fa-download"></i> Export to CSV
            </a>
          </div>
          <table class="results-table">
            <thead>
              <tr>
                <th>Student Name</th>
                <th>Email</th>
                <th>Score</th>
                <th>Percentage</th>
                <th>Completed At</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($results as $result): ?>
                <tr>
                  <td><?= htmlspecialchars($result['student_name']) ?></td>
                  <td><?= htmlspecialchars($result['email']) ?></td>
                  <td><?= htmlspecialchars($result['total_score']) ?></td>
                  <td><?= htmlspecialchars($result['percentage']) ?>%</td>
                  <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($result['completed_at']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="empty-message">
            <p>Please select a class and a quiz to view the analytics and results.</p>
          </div>
        <?php endif; ?>
      </main>
    </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
      // --- Particles.js Initialization ---
      document.addEventListener("DOMContentLoaded", function() {
        particlesJS.load('particles-js', '../../assets/js/particles.json', function() {
          console.log('Particles.js loaded');
        });

        // --- User Dropdown Functionality ---
        const dropdownBtn = document.getElementById("userDropdownBtn");
        const dropdownMenu = document.getElementById("userDropdown");

        if (dropdownBtn && dropdownMenu) {
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
        }

        // --- Chart.js Initialization ---
        const ctx = document.getElementById('scoreDistributionChart');
        <?php if ($selected_quiz_id > 0 && !empty($results)): ?>
          if (ctx) {
            // Destroy existing chart instance if it exists (for potential re-renders)
            if (window.scoreChartInstance) {
              window.scoreChartInstance.destroy();
            }
            window.scoreChartInstance = new Chart(ctx, {
              type: 'bar',
              data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                  label: 'Number of Students',
                  data: <?php echo json_encode($chart_data); ?>,
                  backgroundColor: 'rgba(108, 92, 231, 0.7)',
                  borderColor: 'rgba(108, 92, 231, 1)',
                  borderWidth: 1.5,
                  borderRadius: 6,
                  borderSkipped: false, // Draw borders on all sides
                  hoverBackgroundColor: 'rgba(253, 121, 168, 0.85)',
                  hoverBorderColor: 'rgba(253, 121, 168, 1)',
                  hoverBorderWidth: 2,
                }]
              },
              options: {
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                  legend: {
                    display: false
                  },
                  tooltip: {
                    backgroundColor: 'rgba(30, 30, 46, 0.9)', // Match sidebar bg
                    titleColor: '#a29bfe',
                    bodyColor: '#fff',
                    titleFont: {
                      size: 14,
                      weight: 'bold'
                    },
                    bodyFont: {
                      size: 13
                    },
                    padding: 12,
                    cornerRadius: 6,
                    displayColors: false, // Hide the color box
                    callbacks: {
                      title: function(tooltipItems) {
                        // Make title just the label
                        return tooltipItems[0].label;
                      },
                      label: function(context) {
                        // Customize label text
                        return `Students: ${context.parsed.y}`;
                      }
                    }
                  }
                },
                scales: {
                  y: {
                    beginAtZero: true,
                    title: {
                      display: true,
                      text: 'Number of Students',
                      color: 'rgba(255, 255, 255, 0.9)',
                      font: {
                        size: 14,
                        weight: 'bold',
                        family: 'Arial, sans-serif'
                      },
                      padding: {
                        bottom: 10
                      }
                    },
                    ticks: {
                      color: 'rgba(255, 255, 255, 0.8)',
                      precision: 0,
                      font: {
                        size: 13,
                        weight: '500'
                      },
                      padding: 8
                    },
                    grid: {
                      color: 'rgba(255, 255, 255, 0.1)',
                      drawBorder: false,
                      lineWidth: 1
                    },
                    border: {
                      display: false
                    }
                  },
                  x: {
                    title: {
                      display: true,
                      text: 'Score Range (%)',
                      color: 'rgba(255, 255, 255, 0.9)',
                      font: {
                        size: 14,
                        weight: 'bold',
                        family: 'Arial, sans-serif'
                      },
                      padding: {
                        top: 10
                      }
                    },
                    ticks: {
                      color: 'rgba(255, 255, 255, 0.8)',
                      font: {
                        size: 13,
                        weight: '500'
                      },
                      maxRotation: 0,
                      autoSkip: false,
                      padding: 8
                    },
                    grid: {
                      display: false,
                      drawBorder: false
                    },
                    border: {
                      display: false
                    }
                  }
                }
              }
            });
          }
        <?php endif; ?>
      });
    </script>
</body>

</html>