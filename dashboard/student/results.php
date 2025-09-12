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
$selected_period = isset($_GET['period']) ? $_GET['period'] : 'all';

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

// Build date filter condition
$date_condition = "";
$date_params = [];
switch ($selected_period) {
    case 'week':
        $date_condition = " AND r.completed_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
        break;
    case 'month':
        $date_condition = " AND r.completed_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        break;
    case 'semester':
        $date_condition = " AND r.completed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
        break;
}

// Build class filter condition
$class_condition = "";
$class_params = [];
if ($selected_class_id > 0) {
    $class_condition = " AND c.class_id = ?";
    $class_params = [$selected_class_id];
}
// Get comprehensive results data with attempt numbers
$results_sql = "SELECT r.result_id, r.quiz_id, r.total_score, r.percentage, r.completed_at, r.attempt_number,
                q.title as quiz_title, q.time_limit,
                c.class_id, c.class_name, c.class_code, u.name as teacher_name,
                (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id = q.quiz_id) as total_questions
                FROM results r
                JOIN quizzes q ON r.quiz_id = q.quiz_id
                JOIN classes c ON q.class_id = c.class_id
                JOIN users u ON c.teacher_id = u.user_id
                WHERE r.user_id = ?" . $class_condition . $date_condition . "
                ORDER BY r.completed_at DESC";

$params = array_merge([$user_id], $class_params);
$param_types = str_repeat('i', count($params));

$results_stmt = $conn->prepare($results_sql);
if (!empty($params)) {
    $results_stmt->bind_param($param_types, ...$params);
}
$results_stmt->execute();
$results_result = $results_stmt->get_result();
$all_results = $results_result->fetch_all(MYSQLI_ASSOC);
$results_stmt->close();

// Calculate overall statistics
$total_quizzes = count($all_results);
$total_score = 0;
$highest_score = 0;
$lowest_score = 100;
$scores = [];

foreach ($all_results as $result) {
    $score = floatval($result['percentage']);
    $scores[] = $score;
    $total_score += $score;
    $highest_score = max($highest_score, $score);
    $lowest_score = $total_quizzes > 0 ? min($lowest_score, $score) : 0;
}

$average_score = $total_quizzes > 0 ? round($total_score / $total_quizzes, 1) : 0;

// Calculate grade distribution
$grade_distribution = [
    'A' => 0, // 90-100%
    'B' => 0, // 80-89%
    'C' => 0, // 70-79%
    'D' => 0, // 60-69%
    'F' => 0  // Below 60%
];

foreach ($scores as $score) {
    if ($score >= 90) $grade_distribution['A']++;
    elseif ($score >= 80) $grade_distribution['B']++;
    elseif ($score >= 70) $grade_distribution['C']++;
    elseif ($score >= 60) $grade_distribution['D']++;
    else $grade_distribution['F']++;
}

// Get class-wise performance
$class_performance = [];
foreach ($all_results as $result) {
    $class_id = $result['class_id'];
    if (!isset($class_performance[$class_id])) {
        $class_performance[$class_id] = [
            'class_name' => $result['class_name'],
            'class_code' => $result['class_code'],
            'teacher_name' => $result['teacher_name'],
            'quiz_count' => 0,
            'total_score' => 0,
            'scores' => []
        ];
    }
    $class_performance[$class_id]['quiz_count']++;
    $class_performance[$class_id]['total_score'] += floatval($result['percentage']);
    $class_performance[$class_id]['scores'][] = floatval($result['percentage']);
}

// Calculate averages for each class
foreach ($class_performance as &$class) {
    $class['average_score'] = $class['quiz_count'] > 0 ? round($class['total_score'] / $class['quiz_count'], 1) : 0;
    $class['highest_score'] = !empty($class['scores']) ? max($class['scores']) : 0;
    $class['lowest_score'] = !empty($class['scores']) ? min($class['scores']) : 0;
}
unset($class);

// Get recent performance trend (last 10 quizzes)
$trend_results = array_slice($all_results, 0, 10);
$trend_scores = array_reverse(array_column($trend_results, 'percentage'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Results | Smart Quiz Portal</title>
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

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 2rem 1.5rem 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.12);
            position: relative;
            overflow: visible;
        }

        .chart-title {
            font-size: 1.2rem;
            color: var(--accent);
            margin-bottom: 1rem;
            text-align: center;
        }

        .chart-canvas {
            max-height: 400px;
            
        }

        /* Class Performance Section */
        .class-performance {
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

        .class-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }

        .class-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease;
        }

        .class-card:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .class-name {
            font-size: 1.1rem;
            color: white;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .class-teacher {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .class-code {
            background: rgba(108, 92, 231, 0.2);
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .class-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
            text-align: center;
        }

        .class-stat {
            background: rgba(255, 255, 255, 0.05);
            padding: 0.75rem;
            border-radius: 6px;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--accent);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Results Table */
        .results-table-container {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .results-table th,
        .results-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .results-table th {
            background: rgba(108, 92, 231, 0.2);
            color: var(--accent);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .results-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .results-table td {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
        }

        .score-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .score-excellent {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }

        .score-good {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }

        .score-average {
            background: rgba(241, 196, 15, 0.2);
            color: #f1c40f;
        }

        .score-poor {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }

        .view-btn {
            padding: 4px 8px;
            background: rgba(108, 92, 231, 0.2);
            color: var(--primary);
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.8rem;
            transition: all 0.2s ease;
        }

        .view-btn:hover {
            background: rgba(108, 92, 231, 0.4);
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
            .charts-section {
                grid-template-columns: 1fr;
            }

            .filter-row {
                flex-direction: column;
            }

            .filter-group {
                min-width: 100%;
            }

            .class-grid {
                grid-template-columns: 1fr;
            }

            .class-stats {
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
                <div class="dashboard-header">
                    <div class="page-title">
                        <h1>My Results</h1>
                        <p>Track your performance and analyze your progress across all quizzes.</p>
                    </div>

                    <div class="quick-actions">
                        <a href="quizzes.php" class="btn btn-primary">
                            <i class="fas fa-play"></i> Take Quiz
                        </a>
                        <a href="classes.php" class="btn btn-outline">
                            <i class="fas fa-users"></i> My Classes
                        </a>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <i class="fas fa-clipboard-list"></i>
                        <h3><?php echo $total_quizzes; ?></h3>
                        <p>Total Quizzes</p>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-chart-line"></i>
                        <h3><?php echo $average_score; ?>%</h3>
                        <p>Average Score</p>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-trophy"></i>
                        <h3><?php echo $highest_score; ?>%</h3>
                        <p>Highest Score</p>
                    </div>

                    <div class="stat-card">
                        <i class="fas fa-chart-bar"></i>
                        <h3><?php echo $lowest_score; ?>%</h3>
                        <p>Lowest Score</p>
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
                                <label for="period">Time Period</label>
                                <select name="period" id="period">
                                    <option value="all" <?php echo $selected_period === 'all' ? 'selected' : ''; ?>>All Time</option>
                                    <option value="week" <?php echo $selected_period === 'week' ? 'selected' : ''; ?>>Last Week</option>
                                    <option value="month" <?php echo $selected_period === 'month' ? 'selected' : ''; ?>>Last Month</option>
                                    <option value="semester" <?php echo $selected_period === 'semester' ? 'selected' : ''; ?>>This Semester</option>
                                </select>
                            </div>

                            <button type="submit" class="filter-btn">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <?php if (empty($all_results)): ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <h3>No Results Found</h3>
                        <p>You haven't completed any quizzes yet or no results match your filters.</p>
                        <a href="quizzes.php" class="btn btn-primary">
                            <i class="fas fa-play"></i> Take Your First Quiz
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Charts Section -->
                    <div class="charts-section">
                        <div class="chart-container">
                            <h3 class="chart-title">Grade Distribution</h3>
                            <canvas id="gradeChart" class="chart-canvas"></canvas>
                        </div>

                        <div class="chart-container">
                            <h3 class="chart-title">Performance Trend</h3>
                            <canvas id="trendChart" class="chart-canvas"></canvas>
                        </div>
                    </div>

                    <!-- Class Performance -->
                    <?php if (!empty($class_performance)): ?>
                        <div class="class-performance">
                            <h2 class="section-title">
                                <i class="fas fa-users"></i>
                                Performance by Class
                            </h2>

                            <div class="class-grid">
                                <?php foreach ($class_performance as $class): ?>
                                    <div class="class-card">
                                        <div class="class-header">
                                            <div>
                                                <div class="class-name"><?php echo htmlspecialchars($class['class_name']); ?></div>
                                                <div class="class-teacher"><?php echo htmlspecialchars($class['teacher_name']); ?></div>
                                            </div>
                                            <div class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></div>
                                        </div>

                                        <div class="class-stats">
                                            <div class="class-stat">
                                                <div class="stat-value"><?php echo $class['quiz_count']; ?></div>
                                                <div class="stat-label">Quizzes</div>
                                            </div>
                                            <div class="class-stat">
                                                <div class="stat-value"><?php echo $class['average_score']; ?>%</div>
                                                <div class="stat-label">Average</div>
                                            </div>
                                            <div class="class-stat">
                                                <div class="stat-value"><?php echo $class['highest_score']; ?>%</div>
                                                <div class="stat-label">Best</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <!-- Detailed Results Table -->
                    <div class="results-table-container">
                        <h2 class="section-title">
                            <i class="fas fa-list"></i>
                            Detailed Results
                        </h2>

                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>Quiz</th>
                                    <th>Class</th>
                                    <th>Attempt</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_results as $result): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($result['quiz_title']); ?></td>
                                        <td><?php echo htmlspecialchars($result['class_name']); ?></td>
                                        <td>
                                            <span style="background: rgba(108, 92, 231, 0.2); color: var(--primary); padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: 600;">
                                                #<?php echo $result['attempt_number']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $result['total_score']; ?>/<?php echo $result['total_questions']; ?></td>
                                        <td>
                                            <?php
                                            $percentage = floatval($result['percentage']);
                                            $badge_class = 'score-poor';
                                            if ($percentage >= 90) $badge_class = 'score-excellent';
                                            elseif ($percentage >= 80) $badge_class = 'score-good';
                                            elseif ($percentage >= 70) $badge_class = 'score-average';
                                            ?>
                                            <span class="score-badge <?php echo $badge_class; ?>">
                                                <?php echo $result['percentage']; ?>%
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($result['completed_at'])); ?></td>
                                        <td>
                                            <a href="quiz-result.php?id=<?php echo $result['quiz_id']; ?>&attempt=<?php echo $result['attempt_number']; ?>" class="view-btn">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
        // Chart.js initialization
        <?php if (!empty($all_results)): ?>
            // Grade Distribution Chart
            const gradeCtx = document.getElementById('gradeChart').getContext('2d');
            new Chart(gradeCtx, {
                type: 'doughnut',
                data: {
                    labels: ['A (90-100%)', 'B (80-89%)', 'C (70-79%)', 'D (60-69%)', 'F (Below 60%)'],
                    datasets: [{
                        data: [
                            <?php echo $grade_distribution['A']; ?>,
                            <?php echo $grade_distribution['B']; ?>,
                            <?php echo $grade_distribution['C']; ?>,
                            <?php echo $grade_distribution['D']; ?>,
                            <?php echo $grade_distribution['F']; ?>
                        ],
                        backgroundColor: [
                            'rgba(46, 204, 113, 0.8)',
                            'rgba(52, 152, 219, 0.8)',
                            'rgba(241, 196, 15, 0.8)',
                            'rgba(230, 126, 34, 0.8)',
                            'rgba(231, 76, 60, 0.8)'
                        ],
                        borderColor: [
                            'rgba(46, 204, 113, 1)',
                            'rgba(52, 152, 219, 1)',
                            'rgba(241, 196, 15, 1)',
                            'rgba(230, 126, 34, 1)',
                            'rgba(231, 76, 60, 1)'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 20,
                            bottom: 20,
                            left: 10,
                            right: 10
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: 'rgba(255, 255, 255, 0.8)',
                                font: {
                                    size: 12
                                },
                                padding: 20
                            }
                        }
                    },
                    aspectRatio: 1.2
                }
            });

            // Performance Trend Chart
            const trendCtx = document.getElementById('trendChart').getContext('2d');
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: [<?php echo '"Quiz ' . implode('", "Quiz ', range(1, count($trend_scores))) . '"'; ?>],
                    datasets: [{
                        label: 'Score %',
                        data: [<?php echo implode(', ', $trend_scores); ?>],
                        borderColor: 'rgba(108, 92, 231, 1)',
                        backgroundColor: 'rgba(108, 92, 231, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: 'rgba(253, 121, 168, 1)',
                        pointBorderColor: 'rgba(253, 121, 168, 1)',
                        pointRadius: 5,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 20,
                            bottom: 20,
                            left: 10,
                            right: 10
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    aspectRatio: 1.2,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.8)',
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.8)'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        }
                    }
                }
            });
        <?php endif; ?>
    </script>
</body>

</html>
<?php
$conn->close();
?>