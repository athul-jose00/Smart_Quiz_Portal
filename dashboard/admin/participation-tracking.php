<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../auth/login.php');
  exit();
}

// Get participation data
try {
  $stmt = $pdo->query("
        SELECT 
            u.id as student_id,
            u.username as student_name,
            u.email as student_email,
            COUNT(DISTINCT qr.quiz_id) as quizzes_taken,
            AVG(qr.score) as avg_score,
            MAX(qr.completed_at) as last_activity,
            c.name as class_name
        FROM users u
        LEFT JOIN quiz_results qr ON u.id = qr.student_id
        LEFT JOIN class_students cs ON u.id = cs.student_id
        LEFT JOIN classes c ON cs.class_id = c.id
        WHERE u.role = 'student'
        GROUP BY u.id, u.username, u.email, c.name
        ORDER BY quizzes_taken DESC, avg_score DESC
    ");
  $students = $stmt->fetchAll();

  // Get overall statistics
  $stats_stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT u.id) as total_students,
            COUNT(DISTINCT qr.quiz_id) as total_quizzes,
            COUNT(qr.id) as total_attempts,
            AVG(qr.score) as overall_avg_score
        FROM users u
        LEFT JOIN quiz_results qr ON u.id = qr.student_id
        WHERE u.role = 'student'
    ");
  $stats = $stats_stmt->fetch();
} catch (PDOException $e) {
  $students = [];
  $stats = ['total_students' => 0, 'total_quizzes' => 0, 'total_attempts' => 0, 'overall_avg_score' => 0];
  $error_message = "Error fetching participation data: " . $e->getMessage();
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

    .stats-overview {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
      transition: all 0.3s ease;
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }

    .stat-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 15px;
      font-size: 1.3rem;
      color: white;
    }

    .stat-icon.students {
      background: linear-gradient(135deg, var(--success), #00cec9);
    }

    .stat-icon.quizzes {
      background: linear-gradient(135deg, var(--warning), #e17055);
    }

    .stat-icon.attempts {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
    }

    .stat-icon.average {
      background: linear-gradient(135deg, var(--accent), #fd79a8);
    }

    .stat-number {
      font-size: 2rem;
      font-weight: bold;
      color: white;
      display: block;
      margin-bottom: 5px;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 1px;
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
      position: sticky;
      top: 0;
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
      gap: 12px;
    }

    .student-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: bold;
      font-size: 1.1rem;
    }

    .student-details h4 {
      margin: 0;
      color: white;
      font-size: 1rem;
    }

    .student-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.85rem;
    }

    .participation-level {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .level-high {
      background: rgba(0, 184, 148, 0.2);
      color: var(--success);
      border: 1px solid rgba(0, 184, 148, 0.3);
    }

    .level-medium {
      background: rgba(253, 203, 110, 0.2);
      color: var(--warning);
      border: 1px solid rgba(253, 203, 110, 0.3);
    }

    .level-low {
      background: rgba(214, 48, 49, 0.2);
      color: var(--danger);
      border: 1px solid rgba(214, 48, 49, 0.3);
    }

    .score-badge {
      padding: 4px 10px;
      border-radius: 15px;
      font-size: 0.85rem;
      font-weight: 600;
    }

    .score-excellent {
      background: rgba(0, 184, 148, 0.2);
      color: var(--success);
    }

    .score-good {
      background: rgba(253, 203, 110, 0.2);
      color: var(--warning);
    }

    .score-poor {
      background: rgba(214, 48, 49, 0.2);
      color: var(--danger);
    }

    .last-activity {
      font-size: 0.85rem;
      color: rgba(255, 255, 255, 0.7);
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

      .student-info {
        flex-direction: column;
        gap: 8px;
        text-align: center;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="admin-container">
    <div class="page-header">
      <h1 class="page-title">Student Participation Tracking</h1>
      <a href="index.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>

    <?php if (isset($error_message)): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
      </div>
    <?php endif; ?>

    <!-- Statistics Overview -->
    <div class="stats-overview">
      <div class="stat-card">
        <div class="stat-icon students">
          <i class="fas fa-user-graduate"></i>
        </div>
        <span class="stat-number"><?php echo $stats['total_students']; ?></span>
        <span class="stat-label">Total Students</span>
      </div>

      <div class="stat-card">
        <div class="stat-icon quizzes">
          <i class="fas fa-clipboard-list"></i>
        </div>
        <span class="stat-number"><?php echo $stats['total_quizzes']; ?></span>
        <span class="stat-label">Available Quizzes</span>
      </div>

      <div class="stat-card">
        <div class="stat-icon attempts">
          <i class="fas fa-chart-line"></i>
        </div>
        <span class="stat-number"><?php echo $stats['total_attempts']; ?></span>
        <span class="stat-label">Total Attempts</span>
      </div>

      <div class="stat-card">
        <div class="stat-icon average">
          <i class="fas fa-trophy"></i>
        </div>
        <span class="stat-number"><?php echo $stats['overall_avg_score'] ? round($stats['overall_avg_score'], 1) . '%' : '0%'; ?></span>
        <span class="stat-label">Overall Average</span>
      </div>
    </div>

    <div class="participation-table-container">
      <?php if (empty($students)): ?>
        <div class="empty-state">
          <i class="fas fa-users"></i>
          <h3>No Student Data Found</h3>
          <p>Student participation data will appear here once students start taking quizzes.</p>
        </div>
      <?php else: ?>
        <table class="participation-table">
          <thead>
            <tr>
              <th>Student</th>
              <th>Class</th>
              <th>Quizzes Taken</th>
              <th>Average Score</th>
              <th>Participation Level</th>
              <th>Last Activity</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $student): ?>
              <tr>
                <td>
                  <div class="student-info">
                    <div class="student-avatar">
                      <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                    </div>
                    <div class="student-details">
                      <h4><?php echo htmlspecialchars($student['student_name']); ?></h4>
                      <p><?php echo htmlspecialchars($student['student_email']); ?></p>
                    </div>
                  </div>
                </td>
                <td><?php echo htmlspecialchars($student['class_name'] ?: 'Not assigned'); ?></td>
                <td>
                  <strong><?php echo $student['quizzes_taken'] ?: '0'; ?></strong>
                </td>
                <td>
                  <?php if ($student['avg_score']): ?>
                    <?php
                    $score = round($student['avg_score'], 1);
                    $score_class = $score >= 80 ? 'score-excellent' : ($score >= 60 ? 'score-good' : 'score-poor');
                    ?>
                    <span class="score-badge <?php echo $score_class; ?>">
                      <?php echo $score; ?>%
                    </span>
                  <?php else: ?>
                    <span class="score-badge score-poor">No attempts</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                  $quizzes_taken = $student['quizzes_taken'] ?: 0;
                  if ($quizzes_taken >= 5) {
                    $level_class = 'level-high';
                    $level_text = 'High';
                  } elseif ($quizzes_taken >= 2) {
                    $level_class = 'level-medium';
                    $level_text = 'Medium';
                  } else {
                    $level_class = 'level-low';
                    $level_text = 'Low';
                  }
                  ?>
                  <span class="participation-level <?php echo $level_class; ?>">
                    <?php echo $level_text; ?>
                  </span>
                </td>
                <td>
                  <div class="last-activity">
                    <?php
                    if ($student['last_activity']) {
                      echo date('M j, Y', strtotime($student['last_activity']));
                    } else {
                      echo 'Never';
                    }
                    ?>
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