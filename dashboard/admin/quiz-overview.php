<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit();
}

$error_message = '';
$success_message = '';

// Handle quiz deletion
if (isset($_POST['delete_quiz'])) {
    $quiz_id = $_POST['quiz_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM quizzes WHERE quiz_id = ?");
        $stmt->execute([$quiz_id]);
        $success_message = "Quiz deleted successfully!";
    } catch (PDOException $e) {
        $error_message = "Error deleting quiz: " . $e->getMessage();
    }
}

// Get all quizzes with detailed information
try {
    $stmt = $pdo->query("
    SELECT q.quiz_id, q.title, q.time_limit, q.created_at,
           u.name as teacher_name, u.username as teacher_username,
           c.class_name, c.class_code,
           COUNT(DISTINCT r.user_id) as participants_count,
           AVG(r.percentage) as avg_score
    FROM quizzes q
    LEFT JOIN users u ON q.created_by = u.user_id
    LEFT JOIN classes c ON q.class_id = c.class_id
    LEFT JOIN results r ON q.quiz_id = r.quiz_id
    GROUP BY q.quiz_id, q.title, q.time_limit, q.created_at, u.name, u.username, c.class_name, c.class_code
    ORDER BY q.created_at DESC
  ");
    $quizzes = $stmt->fetchAll();
} catch (PDOException $e) {
    $quizzes = [];
    $error_message = "Error fetching quizzes: " . $e->getMessage();
}

// Get quiz statistics
$stats = [];
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM quizzes");
    $stats['total_quizzes'] = $stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as total FROM results");
    $stats['total_participants'] = $stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT AVG(percentage) as avg FROM results");
    $stats['avg_score'] = round($stmt->fetch()['avg'] ?? 0, 1);

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM results WHERE completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['recent_attempts'] = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $stats = [
        'total_quizzes' => 0,
        'total_participants' => 0,
        'avg_score' => 0,
        'recent_attempts' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Overview - Admin Dashboard</title>
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

        .stat-icon.quizzes {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        .stat-icon.participants {
            background: linear-gradient(135deg, var(--success), #00cec9);
        }

        .stat-icon.score {
            background: linear-gradient(135deg, var(--warning), #e17055);
        }

        .stat-icon.recent {
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

        .quizzes-table-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 30px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            overflow-x: auto;
        }

        .quizzes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .quizzes-table th,
        .quizzes-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .quizzes-table th {
            background: rgba(255, 255, 255, 0.1);
            color: var(--accent);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }

        .quizzes-table td {
            color: rgba(255, 255, 255, 0.9);
        }

        .quizzes-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .quiz-title {
            font-weight: 600;
            color: white;
            margin-bottom: 5px;
        }

        .quiz-meta {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.6);
        }

        .class-badge {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .teacher-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--success);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.8rem;
            color: white;
        }

        .stat-badge {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.8);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-right: 10px;
        }

        .stat-badge.participants {
            background: rgba(0, 184, 148, 0.2);
            color: var(--success);
        }

        .stat-badge.score {
            background: rgba(253, 203, 110, 0.2);
            color: var(--warning);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-view {
            background: linear-gradient(135deg, var(--success), #00cec9);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 184, 148, 0.4);
        }

        .btn-delete {
            background: linear-gradient(135deg, var(--danger), #e74c3c);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            border: none;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(214, 48, 49, 0.4);
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .quizzes-table-container {
                padding: 20px;
            }

            .quizzes-table {
                font-size: 0.9rem;
            }

            .quizzes-table th,
            .quizzes-table td {
                padding: 10px 8px;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="particles" id="particles-js"></div>

    <div class="admin-container">
        <div class="page-header">
            <h1 class="page-title">Quiz Overview</h1>
            <a href="admin.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon quizzes">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-number"><?php echo $stats['total_quizzes']; ?></div>
                <div class="stat-label">Total Quizzes</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon participants">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo $stats['total_participants']; ?></div>
                <div class="stat-label">Total Participants</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon score">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number"><?php echo $stats['avg_score']; ?>%</div>
                <div class="stat-label">Average Score</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon recent">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo $stats['recent_attempts']; ?></div>
                <div class="stat-label">Recent Attempts (7 days)</div>
            </div>
        </div>

        <div class="quizzes-table-container">
            <h2 style="color: white; margin: 0 0 20px 0; font-size: 1.3rem; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-list"></i> All Quizzes
            </h2>

            <?php if (empty($quizzes)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No Quizzes Found</h3>
                    <p>No quizzes have been created yet.</p>
                </div>
            <?php else: ?>
                <table class="quizzes-table">
                    <thead>
                        <tr>
                            <th>Quiz Details</th>
                            <th>Class</th>
                            <th>Teacher</th>
                            <th>Time Limit</th>
                            <th>Statistics</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quizzes as $quiz): ?>
                            <tr>
                                <td>
                                    <div class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></div>
                                    <div class="quiz-meta">Quiz ID: #<?php echo $quiz['quiz_id']; ?></div>
                                </td>
                                <td>
                                    <span class="class-badge">
                                        <?php echo htmlspecialchars($quiz['class_code'] ?? 'N/A'); ?>
                                    </span>
                                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-top: 5px;">
                                        <?php echo htmlspecialchars($quiz['class_name'] ?? 'Unknown Class'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="teacher-info">
                                        <div class="teacher-avatar">
                                            <?php echo strtoupper(substr($quiz['teacher_name'] ?? 'T', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div><?php echo htmlspecialchars($quiz['teacher_name'] ?? 'Unknown'); ?></div>
                                            <small style="color: rgba(255,255,255,0.6);">@<?php echo htmlspecialchars($quiz['teacher_username'] ?? 'unknown'); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo $quiz['time_limit']; ?> min</strong>
                                </td>
                                <td>
                                    <span class="stat-badge participants">
                                        <i class="fas fa-users"></i>
                                        <?php echo $quiz['participants_count']; ?> participants
                                    </span>
                                    <br>
                                    <span class="stat-badge score">
                                        <i class="fas fa-percentage"></i>
                                        <?php echo round($quiz['avg_score'] ?? 0, 1); ?>% avg
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($quiz['created_at'])); ?>
                                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.6);">
                                        <?php echo date('g:i A', strtotime($quiz['created_at'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="quiz-details.php?id=<?php echo $quiz['quiz_id']; ?>" class="btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this quiz? This will also delete all associated results.');">
                                            <input type="hidden" name="quiz_id" value="<?php echo $quiz['quiz_id']; ?>">
                                            <button type="submit" name="delete_quiz" class="btn-delete">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
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