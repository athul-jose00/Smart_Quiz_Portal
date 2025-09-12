<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../auth/login.php');
  exit();
}

// Get performance analytics data
try {
  // Overall performance metrics
  $overall_stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT qr.student_id) as active_students,
            COUNT(qr.id) as total_submissions,
            AVG(qr.score) as overall_avg_score,
            MAX(qr.score) as highest_score,
            MIN(qr.score) as lowest_score
        FROM quiz_results qr
    ");
  $overall_stats = $overall_stmt->fetch();

  // Performance by class
  $class_stmt = $pdo->query("
        SELECT 
            c.name as class_name,
            COUNT(DISTINCT qr.student_id) as student_count,
            COUNT(qr.id) as submissions,
            AVG(qr.score) as avg_score,
            MAX(qr.score) as max_score
        FROM classes c
        LEFT JOIN quizzes q ON c.id = q.class_id
        LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
        GROUP BY c.id, c.name
        HAVING submissions > 0
        ORDER BY avg_score DESC
    ");
  $class_performance = $class_stmt->fetchAll();

  // Top performing students
  $top_students_stmt = $pdo->query("
        SELECT 
            u.username,
            u.email,
            COUNT(qr.id) as quiz_count,
            AVG(qr.score) as avg_score,
            MAX(qr.score) as best_score
        FROM users u
        JOIN quiz_results qr ON u.id = qr.student_id
        WHERE u.role = 'student'
        GROUP BY u.id, u.username, u.email
        HAVING quiz_count >= 1
        ORDER BY avg_score DESC, quiz_count DESC
        LIMIT 10
    ");
  $top_students = $top_students_stmt->fetchAll();

  // Quiz difficulty analysis
  $quiz_difficulty_stmt = $pdo->query("
        SELECT 
            q.title,
            q.duration,
            COUNT(qr.id) as attempts,
            AVG(qr.score) as avg_score,
            CASE 
                WHEN AVG(qr.score) >= 80 THEN 'Easy'
                WHEN AVG(qr.score) >= 60 THEN 'Medium'
                ELSE 'Hard'
            END as difficulty_level
        FROM quizzes q
        LEFT JOIN quiz_results qr ON q.id = qr.quiz_id
        GROUP BY q.id, q.title, q.duration
        HAVING attempts > 0
        ORDER BY avg_score ASC
    ");
  $quiz_difficulty = $quiz_difficulty_stmt->fetchAll();

  // Recent activity
  $recent_activity_stmt = $pdo->query("
        SELECT 
            u.username as student_name,
            q.title as quiz_title,
            qr.score,
            qr.completed_at,
            c.name as class_name
        FROM quiz_results qr
        JOIN users u ON qr.student_id = u.id
        JOIN quizzes q ON qr.quiz_id = q.id
        LEFT JOIN classes c ON q.class_id = c.id
        ORDER BY qr.completed_at DESC
        LIMIT 15
    ");
  $recent_activity = $recent_activity_stmt->fetchAll();
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
      <a href="index.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
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