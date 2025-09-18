<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../auth/login.php');
  exit();
}

$quiz_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$quiz_id) {
  header('Location: quiz-overview.php');
  exit();
}

$error_message = '';
$success_message = '';

try {
  // Get quiz details
  $quiz_stmt = $pdo->prepare("
        SELECT q.quiz_id, q.title, q.time_limit, q.created_at,
               u.name as teacher_name, u.username as teacher_username, u.email as teacher_email,
               c.class_name, c.class_code, c.class_id
        FROM quizzes q
        LEFT JOIN users u ON q.created_by = u.user_id
        LEFT JOIN classes c ON q.class_id = c.class_id
        WHERE q.quiz_id = ?
    ");
  $quiz_stmt->execute([$quiz_id]);
  $quiz = $quiz_stmt->fetch();

  if (!$quiz) {
    header('Location: quiz-overview.php?error=Quiz not found');
    exit();
  }

  // Get quiz questions and options
  $questions_stmt = $pdo->prepare("
        SELECT q.question_id, q.question_text, q.points,
               o.option_id, o.option_text, o.is_correct
        FROM questions q
        LEFT JOIN options o ON q.question_id = o.question_id
        WHERE q.quiz_id = ?
        ORDER BY q.question_id, o.option_id
    ");
  $questions_stmt->execute([$quiz_id]);
  $question_data = $questions_stmt->fetchAll();

  // Organize questions and options
  $questions = [];
  foreach ($question_data as $row) {
    if (!isset($questions[$row['question_id']])) {
      $questions[$row['question_id']] = [
        'question_id' => $row['question_id'],
        'question_text' => $row['question_text'],
        'points' => $row['points'],
        'options' => []
      ];
    }

    if ($row['option_id']) {
      $questions[$row['question_id']]['options'][] = [
        'option_id' => $row['option_id'],
        'option_text' => $row['option_text'],
        'is_correct' => $row['is_correct']
      ];
    }
  }

  // Get quiz results and statistics
  $results_stmt = $pdo->prepare("
        SELECT r.result_id, r.user_id, r.total_score, r.percentage, r.completed_at,
               u.name as student_name, u.username as student_username, u.email as student_email
        FROM results r
        JOIN users u ON r.user_id = u.user_id
        WHERE r.quiz_id = ?
        ORDER BY r.completed_at DESC
    ");
  $results_stmt->execute([$quiz_id]);
  $results = $results_stmt->fetchAll();

  // Calculate statistics
  $stats = [
    'total_questions' => count($questions),
    'total_attempts' => count($results),
    'avg_score' => 0,
    'highest_score' => 0,
    'lowest_score' => 0,
    'pass_rate' => 0
  ];

  if (!empty($results)) {
    $scores = array_column($results, 'percentage');
    $stats['avg_score'] = round(array_sum($scores) / count($scores), 1);
    $stats['highest_score'] = max($scores);
    $stats['lowest_score'] = min($scores);
    $stats['pass_rate'] = round((count(array_filter($scores, fn($score) => $score >= 60)) / count($scores)) * 100, 1);
  }

  // Get recent activity
  $activity_stmt = $pdo->prepare("
        SELECT u.name as student_name, r.percentage, r.completed_at
        FROM results r
        JOIN users u ON r.user_id = u.user_id
        WHERE r.quiz_id = ?
        ORDER BY r.completed_at DESC
        LIMIT 10
    ");
  $activity_stmt->execute([$quiz_id]);
  $recent_activity = $activity_stmt->fetchAll();
} catch (PDOException $e) {
  $error_message = "Error fetching quiz details: " . $e->getMessage();
  $quiz = null;
  $questions = [];
  $results = [];
  $stats = [];
  $recent_activity = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($quiz['title'] ?? 'Quiz Details'); ?> - Admin Dashboard</title>
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

    .header-content {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
    }

    .quiz-info h1 {
      font-size: 2.2rem;
      margin: 0 0 10px 0;
      background: linear-gradient(90deg, var(--accent), var(--primary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .quiz-meta {
      display: flex;
      gap: 20px;
      margin-bottom: 15px;
      flex-wrap: wrap;
    }

    .meta-item {
      display: flex;
      align-items: center;
      gap: 8px;
      color: rgba(255, 255, 255, 0.8);
      font-size: 0.9rem;
    }

    .meta-item i {
      color: var(--accent);
    }

    .class-badge {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .teacher-info {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-top: 10px;
    }

    .teacher-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--success);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      color: white;
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
      white-space: nowrap;
    }

    .back-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(108, 92, 231, 0.6);
    }

    .content-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 30px;
      margin-bottom: 30px;
    }

    .main-content {
      display: flex;
      flex-direction: column;
      gap: 30px;
    }

    .sidebar-content {
      display: flex;
      flex-direction: column;
      gap: 30px;
    }

    .card {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 25px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
    }

    .card-header {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 20px;
    }

    .card-header h2 {
      margin: 0;
      color: white;
      font-size: 1.3rem;
    }

    .card-header i {
      color: var(--accent);
      font-size: 1.2rem;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px;
    }

    .stat-item {
      background: rgba(255, 255, 255, 0.05);
      padding: 15px;
      border-radius: 10px;
      text-align: center;
    }

    .stat-number {
      font-size: 1.8rem;
      font-weight: 700;
      color: var(--accent);
      display: block;
      margin-bottom: 5px;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .question-list {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .question-item {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 10px;
      padding: 20px;
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .question-header {
      display: flex;
      justify-content: between;
      align-items: flex-start;
      gap: 15px;
      margin-bottom: 15px;
    }

    .question-number {
      background: var(--primary);
      color: white;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.9rem;
      flex-shrink: 0;
    }

    .question-text {
      color: white;
      font-size: 1rem;
      line-height: 1.5;
      flex: 1;
    }

    .question-type {
      background: rgba(253, 121, 168, 0.2);
      color: var(--accent);
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 0.7rem;
      font-weight: 600;
      text-transform: uppercase;
      flex-shrink: 0;
    }

    .options-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-left: 45px;
    }

    .option-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      border-radius: 6px;
      background: rgba(255, 255, 255, 0.03);
    }

    .option-item.correct {
      background: rgba(0, 184, 148, 0.2);
      border: 1px solid rgba(0, 184, 148, 0.3);
    }

    .option-marker {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      border: 2px solid rgba(255, 255, 255, 0.3);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .option-item.correct .option-marker {
      background: var(--success);
      border-color: var(--success);
      color: white;
    }

    .option-text {
      color: rgba(255, 255, 255, 0.9);
      font-size: 0.9rem;
    }

    .option-item.correct .option-text {
      color: white;
      font-weight: 500;
    }

    .results-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 15px;
    }

    .results-table th,
    .results-table td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .results-table th {
      background: rgba(255, 255, 255, 0.1);
      color: var(--accent);
      font-weight: 600;
      font-size: 0.9rem;
    }

    .results-table td {
      color: rgba(255, 255, 255, 0.9);
      font-size: 0.9rem;
    }

    .results-table tr:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .score-badge {
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 0.8rem;
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

    .activity-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
      max-height: 300px;
      overflow-y: auto;
    }

    .activity-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 6px;
    }

    .activity-info {
      flex: 1;
    }

    .activity-student {
      color: white;
      font-weight: 500;
      font-size: 0.9rem;
    }

    .activity-time {
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.8rem;
    }

    .activity-score {
      font-weight: 600;
      font-size: 0.9rem;
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

    @media (max-width: 1024px) {
      .content-grid {
        grid-template-columns: 1fr;
      }

      .header-content {
        flex-direction: column;
        align-items: stretch;
      }
    }

    @media (max-width: 768px) {
      .admin-container {
        padding: 15px;
      }

      .quiz-meta {
        flex-direction: column;
        gap: 10px;
      }

      .stats-grid {
        grid-template-columns: 1fr;
      }

      .options-list {
        margin-left: 0;
      }

      .question-header {
        flex-direction: column;
        align-items: flex-start;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="admin-container">
    <?php if ($error_message): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
      </div>
    <?php endif; ?>

    <?php if ($quiz): ?>
      <div class="page-header">
        <div class="header-content">
          <div class="quiz-info">
            <h1><?php echo htmlspecialchars($quiz['title']); ?></h1>

            <div class="quiz-meta">
              <div class="meta-item">
                <i class="fas fa-clock"></i>
                <span><?php echo $quiz['time_limit']; ?> minutes</span>
              </div>
              <div class="meta-item">
                <i class="fas fa-calendar-alt"></i>
                <span>Created <?php echo date('M j, Y', strtotime($quiz['created_at'])); ?></span>
              </div>
              <div class="meta-item">
                <i class="fas fa-hashtag"></i>
                <span>Quiz ID: <?php echo $quiz['quiz_id']; ?></span>
              </div>
            </div>

            <?php if ($quiz['class_name']): ?>
              <span class="class-badge">
                <?php echo htmlspecialchars($quiz['class_code']); ?> - <?php echo htmlspecialchars($quiz['class_name']); ?>
              </span>
            <?php endif; ?>

            <?php if ($quiz['teacher_name']): ?>
              <div class="teacher-info">
                <div class="teacher-avatar">
                  <?php echo strtoupper(substr($quiz['teacher_name'], 0, 1)); ?>
                </div>
                <div>
                  <div style="color: white; font-weight: 500;"><?php echo htmlspecialchars($quiz['teacher_name']); ?></div>
                  <div style="color: rgba(255,255,255,0.6); font-size: 0.8rem;">@<?php echo htmlspecialchars($quiz['teacher_username']); ?></div>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <a href="quiz-overview.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Overview
          </a>
        </div>
      </div>

      <div class="content-grid">
        <div class="main-content">
          <!-- Quiz Questions -->
          <div class="card">
            <div class="card-header">
              <i class="fas fa-question-circle"></i>
              <h2>Quiz Questions (<?php echo count($questions); ?>)</h2>
            </div>

            <?php if (empty($questions)): ?>
              <div class="empty-state">
                <i class="fas fa-question-circle"></i>
                <p>No questions found for this quiz.</p>
              </div>
            <?php else: ?>
              <div class="question-list">
                <?php $question_number = 1; ?>
                <?php foreach ($questions as $question): ?>
                  <div class="question-item">
                    <div class="question-header">
                      <div class="question-number"><?php echo $question_number++; ?></div>
                      <div class="question-text"><?php echo htmlspecialchars($question['question_text']); ?></div>
                      <div class="question-type"><?php echo $question['points']; ?> pts</div>
                    </div>

                    <?php if (!empty($question['options'])): ?>
                      <div class="options-list">
                        <?php foreach ($question['options'] as $option): ?>
                          <div class="option-item <?php echo $option['is_correct'] ? 'correct' : ''; ?>">
                            <div class="option-marker">
                              <?php if ($option['is_correct']): ?>
                                <i class="fas fa-check"></i>
                              <?php endif; ?>
                            </div>
                            <div class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Quiz Results -->
          <div class="card">
            <div class="card-header">
              <i class="fas fa-chart-bar"></i>
              <h2>Student Results (<?php echo count($results); ?>)</h2>
            </div>

            <?php if (empty($results)): ?>
              <div class="empty-state">
                <i class="fas fa-chart-bar"></i>
                <p>No results available for this quiz yet.</p>
              </div>
            <?php else: ?>
              <table class="results-table">
                <thead>
                  <tr>
                    <th>Student</th>
                    <th>Score</th>
                    <th>Percentage</th>
                    <th>Completed</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($results as $result): ?>
                    <tr>
                      <td>
                        <div><?php echo htmlspecialchars($result['student_name']); ?></div>
                        <small style="color: rgba(255,255,255,0.6);">@<?php echo htmlspecialchars($result['student_username']); ?></small>
                      </td>
                      <td><?php echo $result['total_score']; ?>/<?php echo $stats['total_questions']; ?></td>
                      <td>
                        <span class="score-badge <?php echo $result['percentage'] >= 80 ? 'score-excellent' : ($result['percentage'] >= 60 ? 'score-good' : 'score-poor'); ?>">
                          <?php echo $result['percentage']; ?>%
                        </span>
                      </td>
                      <td><?php echo date('M j, Y g:i A', strtotime($result['completed_at'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>

        <div class="sidebar-content">
          <!-- Quiz Statistics -->
          <div class="card">
            <div class="card-header">
              <i class="fas fa-chart-pie"></i>
              <h2>Statistics</h2>
            </div>

            <div class="stats-grid">
              <div class="stat-item">
                <span class="stat-number"><?php echo $stats['total_questions']; ?></span>
                <span class="stat-label">Questions</span>
              </div>
              <div class="stat-item">
                <span class="stat-number"><?php echo $stats['total_attempts']; ?></span>
                <span class="stat-label">Attempts</span>
              </div>
              <div class="stat-item">
                <span class="stat-number"><?php echo $stats['avg_score']; ?>%</span>
                <span class="stat-label">Avg Score</span>
              </div>
              <div class="stat-item">
                <span class="stat-number"><?php echo $stats['pass_rate']; ?>%</span>
                <span class="stat-label">Pass Rate</span>
              </div>
              <div class="stat-item">
                <span class="stat-number"><?php echo $stats['highest_score']; ?>%</span>
                <span class="stat-label">Highest</span>
              </div>
              <div class="stat-item">
                <span class="stat-number"><?php echo $stats['lowest_score']; ?>%</span>
                <span class="stat-label">Lowest</span>
              </div>
            </div>
          </div>

          <!-- Recent Activity -->
          <div class="card">
            <div class="card-header">
              <i class="fas fa-clock"></i>
              <h2>Recent Activity</h2>
            </div>

            <?php if (empty($recent_activity)): ?>
              <div class="empty-state">
                <i class="fas fa-clock"></i>
                <p>No recent activity.</p>
              </div>
            <?php else: ?>
              <div class="activity-list">
                <?php foreach ($recent_activity as $activity): ?>
                  <div class="activity-item">
                    <div class="activity-info">
                      <div class="activity-student"><?php echo htmlspecialchars($activity['student_name']); ?></div>
                      <div class="activity-time"><?php echo date('M j, g:i A', strtotime($activity['completed_at'])); ?></div>
                    </div>
                    <div class="activity-score <?php echo $activity['percentage'] >= 80 ? 'score-excellent' : ($activity['percentage'] >= 60 ? 'score-good' : 'score-poor'); ?>">
                      <?php echo $activity['percentage']; ?>%
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
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