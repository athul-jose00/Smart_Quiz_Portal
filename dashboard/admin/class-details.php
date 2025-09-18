<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../auth/login.php');
  exit();
}

// Check if class ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
  header('Location: classes.php');
  exit();
}

$class_id = $_GET['id'];
$error_message = '';
$success_message = '';

// Get class details
try {
  $stmt = $pdo->prepare("
    SELECT c.class_id, c.class_name, c.class_code, c.teacher_id,
           u.name as teacher_name, u.username as teacher_username, u.email as teacher_email
    FROM classes c
    LEFT JOIN users u ON c.teacher_id = u.user_id
    WHERE c.class_id = ?
  ");
  $stmt->execute([$class_id]);
  $class = $stmt->fetch();

  if (!$class) {
    header('Location: classes.php');
    exit();
  }
} catch (PDOException $e) {
  $error_message = "Error fetching class details: " . $e->getMessage();
}

// Get enrolled students
try {
  $stmt = $pdo->prepare("
    SELECT u.user_id, u.name, u.username, u.email,
           COUNT(DISTINCT r.result_id) as quiz_attempts,
           AVG(r.percentage) as avg_score
    FROM user_classes uc
    JOIN users u ON uc.user_id = u.user_id
    LEFT JOIN results r ON u.user_id = r.user_id 
                        AND r.quiz_id IN (SELECT quiz_id FROM quizzes WHERE class_id = ?)
    WHERE uc.class_id = ?
    GROUP BY u.user_id, u.name, u.username, u.email
    ORDER BY u.name ASC
  ");
  $stmt->execute([$class_id, $class_id]);
  $students = $stmt->fetchAll();
} catch (PDOException $e) {
  $students = [];
  $error_message = "Error fetching students: " . $e->getMessage();
}

// Get class quizzes
try {
  $stmt = $pdo->prepare("
    SELECT q.quiz_id, q.title, q.time_limit, q.created_at,
           COUNT(DISTINCT qu.question_id) as question_count,
           COUNT(DISTINCT r.result_id) as attempt_count,
           AVG(r.percentage) as avg_score
    FROM quizzes q
    LEFT JOIN questions qu ON q.quiz_id = qu.quiz_id
    LEFT JOIN results r ON q.quiz_id = r.quiz_id
    WHERE q.class_id = ?
    GROUP BY q.quiz_id, q.title, q.time_limit, q.created_at
    ORDER BY q.created_at DESC
  ");
  $stmt->execute([$class_id]);
  $quizzes = $stmt->fetchAll();
} catch (PDOException $e) {
  $quizzes = [];
  $error_message = "Error fetching quizzes: " . $e->getMessage();
}

// Handle student removal
if (isset($_POST['remove_student'])) {
  $student_id = $_POST['student_id'];
  try {
    $stmt = $pdo->prepare("DELETE FROM user_classes WHERE user_id = ? AND class_id = ?");
    $stmt->execute([$student_id, $class_id]);
    $success_message = "Student removed from class successfully!";

    // Refresh students list
    $stmt = $pdo->prepare("
      SELECT u.user_id, u.name, u.username, u.email,
             COUNT(DISTINCT r.result_id) as quiz_attempts,
             AVG(r.percentage) as avg_score
      FROM user_classes uc
      JOIN users u ON uc.user_id = u.user_id
      LEFT JOIN results r ON u.user_id = r.user_id 
                          AND r.quiz_id IN (SELECT quiz_id FROM quizzes WHERE class_id = ?)
      WHERE uc.class_id = ?
      GROUP BY u.user_id, u.name, u.username, u.email
      ORDER BY u.name ASC
    ");
    $stmt->execute([$class_id, $class_id]);
    $students = $stmt->fetchAll();
  } catch (PDOException $e) {
    $error_message = "Error removing student: " . $e->getMessage();
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Class Details - <?php echo htmlspecialchars($class['class_name'] ?? 'Unknown Class'); ?></title>
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
    }

    .class-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
    }

    .class-info h1 {
      font-size: 2.2rem;
      margin: 0 0 10px 0;
      background: linear-gradient(90deg, var(--accent), var(--primary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .class-code {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 8px 16px;
      border-radius: 25px;
      font-size: 0.9rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
      display: inline-block;
      margin-bottom: 15px;
    }

    .teacher-info {
      display: flex;
      align-items: center;
      gap: 15px;
      background: rgba(255, 255, 255, 0.05);
      padding: 15px;
      border-radius: 10px;
      margin-bottom: 20px;
    }

    .teacher-avatar {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: var(--success);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 1.2rem;
      color: white;
    }

    .teacher-details h3 {
      margin: 0 0 5px 0;
      color: white;
      font-size: 1.1rem;
    }

    .teacher-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
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

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .stat-card {
      background: rgba(255, 255, 255, 0.05);
      padding: 20px;
      border-radius: 10px;
      text-align: center;
      border-left: 4px solid var(--primary);
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

    .content-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 30px;
    }

    .section {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 25px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
    }

    .section h2 {
      color: white;
      margin: 0 0 20px 0;
      font-size: 1.3rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .student-list,
    .quiz-list {
      max-height: 400px;
      overflow-y: auto;
    }

    .student-item,
    .quiz-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 15px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      margin-bottom: 10px;
      transition: all 0.3s ease;
    }

    .student-item:hover,
    .quiz-item:hover {
      background: rgba(255, 255, 255, 0.1);
    }

    .student-info,
    .quiz-info {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
    }

    .student-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--accent);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      color: white;
    }

    .quiz-icon {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      background: var(--warning);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
    }

    .item-details h4 {
      margin: 0 0 5px 0;
      color: white;
      font-size: 0.95rem;
    }

    .item-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.8rem;
    }

    .item-stats {
      text-align: right;
      margin-right: 10px;
    }

    .stat-badge {
      background: rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.8);
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 500;
      display: inline-block;
      margin-bottom: 3px;
    }

    .btn-remove {
      background: linear-gradient(135deg, var(--danger), #e74c3c);
      color: white;
      padding: 6px 10px;
      border-radius: 6px;
      border: none;
      font-size: 0.75rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .btn-remove:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(214, 48, 49, 0.4);
    }

    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: rgba(255, 255, 255, 0.6);
    }

    .empty-state i {
      font-size: 3rem;
      margin-bottom: 15px;
      opacity: 0.5;
    }

    .alert {
      padding: 15px 20px;
      border-radius: 10px;
      margin-bottom: 20px;
      font-weight: 500;
    }

    .alert-success {
      background: rgba(0, 184, 148, 0.2);
      border: 1px solid rgba(0, 184, 148, 0.3);
      color: var(--success);
    }

    .alert-error {
      background: rgba(214, 48, 49, 0.2);
      border: 1px solid rgba(214, 48, 49, 0.3);
      color: var(--danger);
    }

    @media (max-width: 992px) {
      .content-grid {
        grid-template-columns: 1fr;
      }

      .class-header {
        flex-direction: column;
        gap: 20px;
      }
    }

    @media (max-width: 768px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .student-item,
      .quiz-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }

      .item-stats {
        text-align: left;
        margin-right: 0;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="admin-container">
    <?php if (isset($success_message) && !empty($success_message)): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
      </div>
    <?php endif; ?>

    <?php if (isset($error_message) && !empty($error_message)): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
      </div>
    <?php endif; ?>

    <?php if (isset($class)): ?>
      <div class="page-header">
        <div class="class-header">
          <div class="class-info">
            <h1><?php echo htmlspecialchars($class['class_name']); ?></h1>
            <div class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></div>

            <div class="teacher-info">
              <div class="teacher-avatar">
                <?php echo strtoupper(substr($class['teacher_name'] ?? 'T', 0, 1)); ?>
              </div>
              <div class="teacher-details">
                <h3><?php echo htmlspecialchars($class['teacher_name'] ?? 'Unknown Teacher'); ?></h3>
                <p>@<?php echo htmlspecialchars($class['teacher_username'] ?? 'unknown'); ?></p>
                <p><?php echo htmlspecialchars($class['teacher_email'] ?? ''); ?></p>
              </div>
            </div>
          </div>

          <a href="classes.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Classes
          </a>
        </div>
      </div>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-number"><?php echo count($students); ?></div>
          <div class="stat-label">Enrolled Students</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?php echo count($quizzes); ?></div>
          <div class="stat-label">Total Quizzes</div>
        </div>
        <div class="stat-card">
          <div class="stat-number">
            <?php
            $total_attempts = array_sum(array_column($quizzes, 'attempt_count'));
            echo $total_attempts;
            ?>
          </div>
          <div class="stat-label">Quiz Attempts</div>
        </div>
        <div class="stat-card">
          <div class="stat-number">
            <?php
            $avg_scores = array_filter(array_column($quizzes, 'avg_score'));
            echo !empty($avg_scores) ? round(array_sum($avg_scores) / count($avg_scores), 1) . '%' : '0%';
            ?>
          </div>
          <div class="stat-label">Average Score</div>
        </div>
      </div>

      <div class="content-grid">
        <!-- Students Section -->
        <div class="section">
          <h2>
            <i class="fas fa-user-graduate"></i>
            Enrolled Students (<?php echo count($students); ?>)
          </h2>

          <?php if (empty($students)): ?>
            <div class="empty-state">
              <i class="fas fa-user-graduate"></i>
              <h4>No Students Enrolled</h4>
              <p>Students can join this class using the class code: <strong><?php echo htmlspecialchars($class['class_code']); ?></strong></p>
            </div>
          <?php else: ?>
            <div class="student-list">
              <?php foreach ($students as $student): ?>
                <div class="student-item">
                  <div class="student-info">
                    <div class="student-avatar">
                      <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                    </div>
                    <div class="item-details">
                      <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                      <p>@<?php echo htmlspecialchars($student['username']); ?></p>
                    </div>
                  </div>
                  <div class="item-stats">
                    <div class="stat-badge">
                      <?php echo $student['quiz_attempts']; ?> attempts
                    </div>
                    <div class="stat-badge">
                      <?php echo $student['avg_score'] ? round($student['avg_score'], 1) . '%' : '0%'; ?> avg
                    </div>
                  </div>
                  <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove this student from the class?');">
                    <input type="hidden" name="student_id" value="<?php echo $student['user_id']; ?>">
                    <button type="submit" name="remove_student" class="btn-remove">
                      <i class="fas fa-times"></i>
                    </button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Quizzes Section -->
        <div class="section">
          <h2>
            <i class="fas fa-clipboard-list"></i>
            Class Quizzes (<?php echo count($quizzes); ?>)
          </h2>

          <?php if (empty($quizzes)): ?>
            <div class="empty-state">
              <i class="fas fa-clipboard-list"></i>
              <h4>No Quizzes Created</h4>
              <p>The teacher hasn't created any quizzes for this class yet.</p>
            </div>
          <?php else: ?>
            <div class="quiz-list">
              <?php foreach ($quizzes as $quiz): ?>
                <div class="quiz-item">
                  <div class="quiz-info">
                    <div class="quiz-icon">
                      <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="item-details">
                      <h4><?php echo htmlspecialchars($quiz['title']); ?></h4>
                      <p><?php echo $quiz['time_limit']; ?> minutes • <?php echo $quiz['question_count']; ?> questions</p>
                      <p>Created: <?php echo date('M j, Y', strtotime($quiz['created_at'])); ?></p>
                    </div>
                  </div>
                  <div class="item-stats">
                    <div class="stat-badge">
                      <?php echo $quiz['attempt_count']; ?> attempts
                    </div>
                    <div class="stat-badge">
                      <?php echo $quiz['avg_score'] ? round($quiz['avg_score'], 1) . '%' : '0%'; ?> avg
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
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