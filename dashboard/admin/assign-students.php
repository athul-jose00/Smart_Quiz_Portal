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

// Handle student assignment/removal
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  if (isset($_POST['assign_student'])) {
    $student_id = $_POST['student_id'];
    $class_id = $_POST['class_id'];

    try {
      // Check if student is already assigned to this class
      $stmt = $pdo->prepare("SELECT * FROM user_classes WHERE user_id = ? AND class_id = ?");
      $stmt->execute([$student_id, $class_id]);

      if ($stmt->fetch()) {
        $error_message = "Student is already assigned to this class.";
      } else {
        // Assign student to class
        $stmt = $pdo->prepare("INSERT INTO user_classes (user_id, class_id) VALUES (?, ?)");
        $stmt->execute([$student_id, $class_id]);
        $success_message = "Student assigned to class successfully!";
      }
    } catch (PDOException $e) {
      $error_message = "Error assigning student: " . $e->getMessage();
    }
  }

  if (isset($_POST['remove_assignment'])) {
    $student_id = $_POST['student_id'];
    $class_id = $_POST['class_id'];

    try {
      $stmt = $pdo->prepare("DELETE FROM user_classes WHERE user_id = ? AND class_id = ?");
      $stmt->execute([$student_id, $class_id]);
      $success_message = "Student removed from class successfully!";
    } catch (PDOException $e) {
      $error_message = "Error removing student: " . $e->getMessage();
    }
  }
}

// Get all students
try {
  $stmt = $pdo->query("SELECT user_id, name, username, email FROM users WHERE role = 'student' ORDER BY name ASC");
  $students = $stmt->fetchAll();
} catch (PDOException $e) {
  $students = [];
  $error_message = "Error fetching students: " . $e->getMessage();
}

// Get all classes
try {
  $stmt = $pdo->query("
    SELECT c.class_id, c.class_name, c.class_code, u.name as teacher_name
    FROM classes c
    LEFT JOIN users u ON c.teacher_id = u.user_id
    ORDER BY c.class_name ASC
  ");
  $classes = $stmt->fetchAll();
} catch (PDOException $e) {
  $classes = [];
  $error_message = "Error fetching classes: " . $e->getMessage();
}

// Get current assignments
try {
  $stmt = $pdo->query("
    SELECT uc.user_id, uc.class_id, u.name as student_name, c.class_name, c.class_code
    FROM user_classes uc
    JOIN users u ON uc.user_id = u.user_id
    JOIN classes c ON uc.class_id = c.class_id
    ORDER BY u.name ASC, c.class_name ASC
  ");
  $assignments = $stmt->fetchAll();
} catch (PDOException $e) {
  $assignments = [];
}

// Group assignments by student
$student_assignments = [];
foreach ($assignments as $assignment) {
  $student_assignments[$assignment['user_id']][] = $assignment;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Assign Students to Classes - Admin Dashboard</title>
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

    .content-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 30px;
      margin-bottom: 30px;
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



    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: rgba(255, 255, 255, 0.9);
      font-weight: 600;
      font-size: 0.9rem;
    }

    .form-control {
      width: 100%;
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

    /* Fix dropdown styling for assignment forms */
    .assignment-form select {
      min-width: 120px;
      padding: 6px 10px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 6px;
      color: white;
      font-size: 0.8rem;
      transition: all 0.3s ease;
    }

    .assignment-form select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
      background: rgba(255, 255, 255, 0.08);
    }

    .assignment-form select option {
      background: #2d3748;
      color: white;
      padding: 8px;
    }

    /* Ensure all select elements follow the theme */
    select {
      background: rgba(255, 255, 255, 0.05) !important;
      color: white !important;
      border: 1px solid rgba(255, 255, 255, 0.1) !important;
    }

    select option {
      background: #2d3748 !important;
      color: white !important;
    }

    .student-list {
      max-height: 500px;
      overflow-y: auto;
    }

    .student-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 15px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      margin-bottom: 10px;
      transition: all 0.3s ease;
    }

    .student-item:hover {
      background: rgba(255, 255, 255, 0.1);
    }

    .student-info {
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
      font-size: 0.9rem;
    }

    .student-details h4 {
      margin: 0 0 5px 0;
      color: white;
      font-size: 0.95rem;
    }

    .student-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.8rem;
    }

    .student-classes {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
      margin-top: 5px;
    }

    .class-badge {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 0.7rem;
      font-weight: 500;
    }

    .assignment-form {
      display: flex;
      gap: 8px;
      align-items: center;
    }



    .btn-assign {
      background: linear-gradient(135deg, var(--success), #00cec9);
      color: white;
      padding: 6px 12px;
      border-radius: 6px;
      border: none;
      font-size: 0.8rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .btn-assign:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(0, 184, 148, 0.4);
    }

    .btn-remove {
      background: linear-gradient(135deg, var(--danger), #e74c3c);
      color: white;
      padding: 4px 8px;
      border-radius: 4px;
      border: none;
      font-size: 0.7rem;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-left: 5px;
    }

    .btn-remove:hover {
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
      padding: 40px 20px;
      color: rgba(255, 255, 255, 0.6);
    }

    .empty-state i {
      font-size: 3rem;
      margin-bottom: 15px;
      opacity: 0.5;
    }

    .stats-summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 15px;
      margin-bottom: 20px;
    }

    .stat-item {
      background: rgba(255, 255, 255, 0.05);
      padding: 15px;
      border-radius: 8px;
      text-align: center;
    }

    .stat-number {
      font-size: 1.5rem;
      font-weight: 700;
      color: white;
      margin-bottom: 5px;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    @media (max-width: 992px) {
      .content-grid {
        grid-template-columns: 1fr;
      }


    }

    @media (max-width: 768px) {
      .page-header {
        flex-direction: column;
        gap: 20px;
        text-align: center;
      }

      .assignment-form {
        flex-direction: column;
        gap: 5px;
      }

      .assignment-form select {
        min-width: 100px;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="admin-container">
    <div class="page-header">
      <h1 class="page-title">Assign Students to Classes</h1>
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

    <!-- Statistics Summary -->
    <div class="section">
      <h2><i class="fas fa-chart-bar"></i> Assignment Statistics</h2>
      <div class="stats-summary">
        <div class="stat-item">
          <div class="stat-number"><?php echo count($students); ?></div>
          <div class="stat-label">Total Students</div>
        </div>
        <div class="stat-item">
          <div class="stat-number"><?php echo count($classes); ?></div>
          <div class="stat-label">Total Classes</div>
        </div>
        <div class="stat-item">
          <div class="stat-number"><?php echo count($assignments); ?></div>
          <div class="stat-label">Total Assignments</div>
        </div>
        <div class="stat-item">
          <div class="stat-number">
            <?php
            $students_with_classes = count(array_unique(array_column($assignments, 'user_id')));
            echo $students_with_classes;
            ?>
          </div>
          <div class="stat-label">Students Assigned</div>
        </div>
      </div>
    </div>



    <div class="content-grid">
      <!-- Students List -->
      <div class="section">
        <h2><i class="fas fa-user-graduate"></i> Students (<?php echo count($students); ?>)</h2>

        <?php if (empty($students)): ?>
          <div class="empty-state">
            <i class="fas fa-user-graduate"></i>
            <h4>No Students Found</h4>
            <p>Please create student accounts first.</p>
          </div>
        <?php else: ?>
          <div class="student-list">
            <?php foreach ($students as $student): ?>
              <div class="student-item">
                <div class="student-info">
                  <div class="student-avatar">
                    <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                  </div>
                  <div class="student-details">
                    <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                    <p>@<?php echo htmlspecialchars($student['username']); ?></p>
                    <div class="student-classes">
                      <?php if (isset($student_assignments[$student['user_id']])): ?>
                        <?php foreach ($student_assignments[$student['user_id']] as $assignment): ?>
                          <span class="class-badge">
                            <?php echo htmlspecialchars($assignment['class_code']); ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove student from this class?');">
                              <input type="hidden" name="student_id" value="<?php echo $student['user_id']; ?>">
                              <input type="hidden" name="class_id" value="<?php echo $assignment['class_id']; ?>">
                              <button type="submit" name="remove_assignment" class="btn-remove">×</button>
                            </form>
                          </span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span style="color: rgba(255,255,255,0.5); font-size: 0.8rem;">No classes assigned</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <!-- Individual Assignment Form -->
                <form method="POST" class="assignment-form">
                  <input type="hidden" name="student_id" value="<?php echo $student['user_id']; ?>">
                  <select name="class_id" required>
                    <option value="">Select class...</option>
                    <?php foreach ($classes as $class): ?>
                      <option value="<?php echo $class['class_id']; ?>">
                        <?php echo htmlspecialchars($class['class_code']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" name="assign_student" class="btn-assign">
                    <i class="fas fa-plus"></i>
                  </button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Classes List -->
      <div class="section">
        <h2><i class="fas fa-school"></i> Available Classes (<?php echo count($classes); ?>)</h2>

        <?php if (empty($classes)): ?>
          <div class="empty-state">
            <i class="fas fa-school"></i>
            <h4>No Classes Found</h4>
            <p>Please create classes first.</p>
          </div>
        <?php else: ?>
          <div class="student-list">
            <?php foreach ($classes as $class): ?>
              <div class="student-item">
                <div class="student-info">
                  <div class="student-avatar" style="background: var(--success);">
                    <i class="fas fa-school"></i>
                  </div>
                  <div class="student-details">
                    <h4><?php echo htmlspecialchars($class['class_name']); ?></h4>
                    <p>Code: <?php echo htmlspecialchars($class['class_code']); ?></p>
                    <p>Teacher: <?php echo htmlspecialchars($class['teacher_name'] ?? 'Unknown'); ?></p>
                    <div class="student-classes">
                      <?php
                      $class_student_count = 0;
                      foreach ($assignments as $assignment) {
                        if ($assignment['class_id'] == $class['class_id']) {
                          $class_student_count++;
                        }
                      }
                      ?>
                      <span class="class-badge">
                        <?php echo $class_student_count; ?> students enrolled
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
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