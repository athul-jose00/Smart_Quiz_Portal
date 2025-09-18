<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../auth/login.php');
  exit();
}

$error_message = '';

// Get filter parameters
$class_filter = $_GET['class'] ?? '';
$quiz_filter = $_GET['quiz'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if ($class_filter) {
  $where_conditions[] = "c.class_id = ?";
  $params[] = $class_filter;
}

if ($quiz_filter) {
  $where_conditions[] = "q.quiz_id = ?";
  $params[] = $quiz_filter;
}

if ($date_filter) {
  $where_conditions[] = "DATE(r.completed_at) = ?";
  $params[] = $date_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get participation data
try {
  $stmt = $pdo->prepare("
    SELECT r.quiz_id, r.user_id, r.percentage as score, r.completed_at as created_at,
           u.name as student_name, u.username as student_username,
           q.title as quiz_title, q.time_limit,
           c.class_name, c.class_code,
           t.name as teacher_name
    FROM results r
    JOIN users u ON r.user_id = u.user_id
    JOIN quizzes q ON r.quiz_id = q.quiz_id
    JOIN classes c ON q.class_id = c.class_id
    JOIN users t ON q.created_by = t.user_id
    $where_clause
    ORDER BY r.completed_at DESC
  ");
  $stmt->execute($params);
  $participations = $stmt->fetchAll();
} catch (PDOException $e) {
  $participations = [];
  $error_message = "Error fetching participation data: " . $e->getMessage();
}

// Get classes for filter dropdown
try {
  $stmt = $pdo->query("SELECT class_id, class_name, class_code FROM classes ORDER BY class_name ASC");
  $classes = $stmt->fetchAll();
} catch (PDOException $e) {
  $classes = [];
}

// Get quizzes for filter dropdown
try {
  $stmt = $pdo->query("SELECT quiz_id, title FROM quizzes ORDER BY title ASC");
  $quizzes = $stmt->fetchAll();
} catch (PDOException $e) {
  $quizzes = [];
}

// Get participation statistics
$stats = [];
try {
  $stmt = $pdo->prepare("
    SELECT 
      COUNT(*) as total_attempts,
      COUNT(DISTINCT r.user_id) as unique_participants,
      AVG(r.percentage) as avg_score,
      COUNT(CASE WHEN r.percentage >= 80 THEN 1 END) as high_scores
    FROM results r
    JOIN quizzes q ON r.quiz_id = q.quiz_id
    JOIN classes c ON q.class_id = c.class_id
    $where_clause
  ");
  $stmt->execute($params);
  $stats = $stmt->fetch();
} catch (PDOException $e) {
  $stats = [
    'total_attempts' => 0,
    'unique_participants' => 0,
    'avg_score' => 0,
    'high_scores' => 0
  ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Participation Tracking - Admin Dashboard</title>
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

    .filters-section {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 25px;
      margin-bottom: 30px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
    }

    .filters-form {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      align-items: end;
    }

    .form-group {
      display: flex;
      flex-direction: column;
    }

    .form-group label {
      color: rgba(255, 255, 255, 0.9);
      font-weight: 600;
      margin-bottom: 8px;
      font-size: 0.9rem;
    }

    .form-control {
      padding: 12px 15px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      color: white;
      font-size: 0.9rem;
      transition: all 0.3s ease;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
    }

    .form-control option {
      background: #2d3748;
      color: white;
    }

    .btn-filter {
      background: linear-gradient(135deg, var(--success), #00cec9);
      color: white;
      padding: 12px 20px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(0, 184, 148, 0.4);
    }

    .btn-filter:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 184, 148, 0.6);
    }

    .btn-clear {
      background: rgba(255, 255, 255, 0.1);
      color: white;
      padding: 12px 20px;
      border-radius: 8px;
      border: none;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      text-decoration: none;
      display: inline-block;
      text-align: center;
    }

    .btn-clear:hover {
      background: rgba(255, 255, 255, 0.2);
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .stat-card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 25px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      text-align: center;
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      border-radius: 15px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 15px;
      font-size: 1.5rem;
      color: white;
    }

    .stat-icon.attempts {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
    }

    .stat-icon.participants {
      background: linear-gradient(135deg, var(--success), #00cec9);
    }

    .stat-icon.score {
      background: linear-gradient(135deg, var(--warning), #e17055);
    }

    .stat-icon.high-scores {
      background: linear-gradient(135deg, #f093fb, #f5576c);
    }

    .stat-number {
      font-size: 2rem;
      font-weight: 700;
      color: white;
      margin-bottom: 5px;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .participation-table-container {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 30px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      overflow-x: auto;
    }

    .participation-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }

    .participation-table th,
    .participation-table td {
      padding: 15px;
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .participation-table th {
      background: rgba(255, 255, 255, 0.1);
      color: var(--accent);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
      font-size: 0.9rem;
    }

    .participation-table td {
      color: rgba(255, 255, 255, 0.9);
    }

    .participation-table tr:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .student-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .student-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: var(--accent);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.8rem;
      color: white;
    }

    .quiz-badge {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 4px 10px;
      border-radius: 15px;
      font-size: 0.8rem;
      font-weight: 500;
    }

    .class-badge {
      background: linear-gradient(135deg, var(--success), #00cec9);
      color: white;
      padding: 4px 10px;
      border-radius: 15px;
      font-size: 0.8rem;
      font-weight: 500;
    }

    .score-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-weight: 600;
      font-size: 0.9rem;
    }

    .score-excellent {
      background: rgba(0, 184, 148, 0.2);
      color: var(--success);
    }

    .score-good {
      background: rgba(253, 203, 110, 0.2);
      color: var(--warning);
    }

    .score-average {
      background: rgba(255, 107, 107, 0.2);
      color: var(--danger);
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

    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: rgba(255, 255, 255, 0.6);
    }

    .empty-state i {
      font-size: 4rem;
      margin-bottom: 20px;
      opacity: 0.5;
    }

    @media (max-width: 768px) {
      .page-header {
        flex-direction: column;
        gap: 20px;
        text-align: center;
      }

      .filters-form {
        grid-template-columns: 1fr;
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .participation-table-container {
        padding: 20px;
      }

      .participation-table {
        font-size: 0.9rem;
      }

      .participation-table th,
      .participation-table td {
        padding: 10px 8px;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="admin-container">
    <div class="page-header">
      <h1 class="page-title">Student Participation Tracking</h1>
      <a href="admin.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>

    <?php if ($error_message): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
      </div>
    <?php endif; ?>

    <!-- Filters Section -->
    <div class="filters-section">
      <h2 style="color: white; margin: 0 0 20px 0; font-size: 1.3rem; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-filter"></i> Filter Participation Data
      </h2>

      <form method="GET" class="filters-form">
        <div class="form-group">
          <label for="class">Filter by Class</label>
          <select id="class" name="class" class="form-control">
            <option value="">All Classes</option>
            <?php foreach ($classes as $class): ?>
              <option value="<?php echo $class['class_id']; ?>" <?php echo $class_filter == $class['class_id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($class['class_name']); ?> (<?php echo htmlspecialchars($class['class_code']); ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="quiz">Filter by Quiz</label>
          <select id="quiz" name="quiz" class="form-control">
            <option value="">All Quizzes</option>
            <?php foreach ($quizzes as $quiz): ?>
              <option value="<?php echo $quiz['quiz_id']; ?>" <?php echo $quiz_filter == $quiz['quiz_id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($quiz['title']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="date">Filter by Date</label>
          <input type="date" id="date" name="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>">
        </div>

        <div class="form-group">
          <button type="submit" class="btn-filter">
            <i class="fas fa-search"></i> Apply Filters
          </button>
        </div>

        <div class="form-group">
          <a href="participation-tracking.php" class="btn-clear">
            <i class="fas fa-times"></i> Clear Filters
          </a>
        </div>
      </form>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon attempts">
          <i class="fas fa-clipboard-check"></i>
        </div>
        <div class="stat-number"><?php echo $stats['total_attempts']; ?></div>
        <div class="stat-label">Total Attempts</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon participants">
          <i class="fas fa-users"></i>
        </div>
        <div class="stat-number"><?php echo $stats['unique_participants']; ?></div>
        <div class="stat-label">Unique Participants</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon score">
          <i class="fas fa-percentage"></i>
        </div>
        <div class="stat-number"><?php echo round($stats['avg_score'] ?? 0, 1); ?>%</div>
        <div class="stat-label">Average Score</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon high-scores">
          <i class="fas fa-trophy"></i>
        </div>
        <div class="stat-number"><?php echo $stats['high_scores']; ?></div>
        <div class="stat-label">High Scores (80%+)</div>
      </div>
    </div>

    <div class="participation-table-container">
      <h2 style="color: white; margin: 0 0 20px 0; font-size: 1.3rem; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-chart-bar"></i> Participation Details
      </h2>

      <?php if (empty($participations)): ?>
        <div class="empty-state">
          <i class="fas fa-chart-bar"></i>
          <h3>No Participation Data Found</h3>
          <p>No quiz attempts match your current filters.</p>
        </div>
      <?php else: ?>
        <table class="participation-table">
          <thead>
            <tr>
              <th>Student</th>
              <th>Quiz</th>
              <th>Class</th>
              <th>Teacher</th>
              <th>Score</th>
              <th>Time Limit</th>
              <th>Completed At</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($participations as $participation): ?>
              <tr>
                <td>
                  <div class="student-info">
                    <div class="student-avatar">
                      <?php echo strtoupper(substr($participation['student_name'], 0, 1)); ?>
                    </div>
                    <div>
                      <div><?php echo htmlspecialchars($participation['student_name']); ?></div>
                      <small style="color: rgba(255,255,255,0.6);">@<?php echo htmlspecialchars($participation['student_username']); ?></small>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="quiz-badge">
                    <?php echo htmlspecialchars($participation['quiz_title']); ?>
                  </span>
                </td>
                <td>
                  <span class="class-badge">
                    <?php echo htmlspecialchars($participation['class_code']); ?>
                  </span>
                  <div style="font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-top: 5px;">
                    <?php echo htmlspecialchars($participation['class_name']); ?>
                  </div>
                </td>
                <td>
                  <?php echo htmlspecialchars($participation['teacher_name']); ?>
                </td>
                <td>
                  <?php
                  $score = $participation['score'];
                  $score_class = 'score-average';
                  if ($score >= 80) $score_class = 'score-excellent';
                  elseif ($score >= 60) $score_class = 'score-good';
                  ?>
                  <span class="score-badge <?php echo $score_class; ?>">
                    <?php echo $score; ?>%
                  </span>
                </td>
                <td>
                  <?php echo $participation['time_limit']; ?> min
                </td>
                <td>
                  <?php echo date('M j, Y', strtotime($participation['created_at'])); ?>
                  <div style="font-size: 0.8rem; color: rgba(255,255,255,0.6);">
                    <?php echo date('g:i A', strtotime($participation['created_at'])); ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
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
  </script>
</body>

</html>