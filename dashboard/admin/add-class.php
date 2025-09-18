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

// Get all teachers for the dropdown
try {
  $stmt = $pdo->query("SELECT user_id, name, username FROM users WHERE role = 'teacher' ORDER BY name ASC");
  $teachers = $stmt->fetchAll();
} catch (PDOException $e) {
  $teachers = [];
  $error_message = "Error fetching teachers: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $class_name = trim($_POST['class_name']);
  $class_code = trim(strtoupper($_POST['class_code']));
  $teacher_id = $_POST['teacher_id'];

  // Validation
  if (empty($class_name) || empty($class_code) || empty($teacher_id)) {
    $error_message = "All fields are required.";
  } elseif (strlen($class_code) > 10) {
    $error_message = "Class code must be 10 characters or less.";
  } elseif (!preg_match('/^[A-Z0-9]+$/', $class_code)) {
    $error_message = "Class code can only contain uppercase letters and numbers.";
  } else {
    try {
      // Check if class code already exists
      $stmt = $pdo->prepare("SELECT class_id FROM classes WHERE class_code = ?");
      $stmt->execute([$class_code]);

      if ($stmt->fetch()) {
        $error_message = "Class code already exists. Please choose a different code.";
      } else {
        // Insert new class
        $stmt = $pdo->prepare("INSERT INTO classes (class_name, class_code, teacher_id) VALUES (?, ?, ?)");
        $stmt->execute([$class_name, $class_code, $teacher_id]);

        $success_message = "Class created successfully!";
        // Clear form data
        $class_name = $class_code = $teacher_id = '';
      }
    } catch (PDOException $e) {
      $error_message = "Error creating class: " . $e->getMessage();
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add New Class - Admin Dashboard</title>
  <link rel="stylesheet" href="../../css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    .admin-container {
      max-width: 800px;
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

    .form-container {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 40px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
    }

    .form-group {
      margin-bottom: 25px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: rgba(255, 255, 255, 0.9);
      font-weight: 600;
      font-size: 1rem;
    }

    .form-control {
      width: 100%;
      padding: 15px 20px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      color: white;
      font-size: 1rem;
      transition: all 0.3s ease;
    }

    .form-control::placeholder {
      color: rgba(255, 255, 255, 0.5);
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
      background: rgba(255, 255, 255, 0.08);
    }

    .form-control select {
      background: rgba(255, 255, 255, 0.05);
      color: white;
    }

    .form-control select option {
      background: #2d3748;
      color: white;
    }

    .teacher-selection {
      position: relative;
    }

    .teacher-option {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      margin-bottom: 10px;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .teacher-option:hover {
      background: rgba(255, 255, 255, 0.1);
    }

    .teacher-option input[type="radio"] {
      display: none;
    }

    .teacher-option input[type="radio"]:checked~.teacher-info {
      background: rgba(108, 92, 231, 0.2);
      border-color: var(--primary);
    }

    .teacher-option:has(input[type="radio"]:checked) {
      background: rgba(108, 92, 231, 0.15);
      border: 1px solid var(--primary);
      border-radius: 8px;
    }

    .teacher-option:has(input[type="radio"]:checked) .teacher-info {
      background: rgba(108, 92, 231, 0.2);
      border-color: var(--primary);
    }

    /* JavaScript-controlled selected state for better browser compatibility */
    .teacher-option.selected {
      background: rgba(108, 92, 231, 0.15);
      border: 1px solid var(--primary);
      border-radius: 8px;
    }

    .teacher-option.selected .teacher-info {
      background: rgba(108, 92, 231, 0.2);
      border-color: var(--primary);
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
      font-size: 1rem;
      color: white;
    }

    .teacher-info {
      flex: 1;
      padding: 10px;
      border: 2px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      transition: all 0.3s ease;
    }

    .teacher-name {
      font-weight: 600;
      color: white;
      margin-bottom: 5px;
    }

    .teacher-username {
      color: rgba(255, 255, 255, 0.6);
      font-size: 0.9rem;
    }

    .btn-submit {
      width: 100%;
      padding: 18px;
      font-size: 1.1rem;
      font-weight: 600;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--success), #00cec9);
      color: white;
      border: none;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(0, 184, 148, 0.4);
      margin-top: 10px;
    }

    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 184, 148, 0.6);
    }

    .btn-submit:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    .alert {
      padding: 15px 20px;
      border-radius: 10px;
      margin-bottom: 25px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 10px;
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

    .form-help {
      margin-top: 8px;
      font-size: 0.9rem;
      color: rgba(255, 255, 255, 0.6);
    }

    @media (max-width: 768px) {
      .admin-container {
        padding: 15px;
      }

      .page-header {
        flex-direction: column;
        gap: 20px;
        text-align: center;
      }

      .form-container {
        padding: 25px;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="admin-container">
    <div class="page-header">
      <h1 class="page-title">Add New Class</h1>
      <a href="classes.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Classes
      </a>
    </div>

    <div class="form-container">
      <?php if ($success_message): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle"></i>
          <?php echo $success_message; ?>
        </div>
      <?php endif; ?>

      <?php if ($error_message): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle"></i>
          <?php echo $error_message; ?>
        </div>
      <?php endif; ?>

      <form method="POST" id="addClassForm">
        <div class="form-group">
          <label for="class_name">Class Name</label>
          <input type="text" id="class_name" name="class_name" class="form-control"
            placeholder="Enter class name (e.g., Mathematics 101)" value="<?php echo htmlspecialchars($class_name ?? ''); ?>" required>
          <div class="form-help">Enter a descriptive name for the class</div>
        </div>

        <div class="form-group">
          <label for="class_code">Class Code</label>
          <input type="text" id="class_code" name="class_code" class="form-control"
            placeholder="Enter class code (e.g., MATH101)" value="<?php echo htmlspecialchars($class_code ?? ''); ?>"
            maxlength="10" style="text-transform: uppercase;" required>
          <div class="form-help">Unique code for students to join the class (max 10 characters, letters and numbers only)</div>
        </div>

        <div class="form-group">
          <label>Assign Teacher</label>
          <?php if (empty($teachers)): ?>
            <div class="alert alert-error">
              <i class="fas fa-exclamation-triangle"></i>
              No teachers found. Please create teacher accounts first.
            </div>
          <?php else: ?>
            <div class="teacher-selection">
              <?php foreach ($teachers as $teacher): ?>
                <div class="teacher-option">
                  <input type="radio" id="teacher_<?php echo $teacher['user_id']; ?>" name="teacher_id"
                    value="<?php echo $teacher['user_id']; ?>"
                    <?php echo (isset($teacher_id) && $teacher_id == $teacher['user_id']) ? 'checked' : ''; ?>>
                  <div class="teacher-avatar">
                    <?php echo strtoupper(substr($teacher['name'], 0, 1)); ?>
                  </div>
                  <label for="teacher_<?php echo $teacher['user_id']; ?>" class="teacher-info">
                    <div class="teacher-name"><?php echo htmlspecialchars($teacher['name']); ?></div>
                    <div class="teacher-username">@<?php echo htmlspecialchars($teacher['username']); ?></div>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($teachers)): ?>
          <button type="submit" class="btn-submit">
            <i class="fas fa-plus"></i> Create Class
          </button>
        <?php endif; ?>
      </form>
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

    // Auto-uppercase class code
    document.getElementById('class_code').addEventListener('input', function(e) {
      e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });

    // Handle teacher selection highlighting
    document.querySelectorAll('input[name="teacher_id"]').forEach(radio => {
      radio.addEventListener('change', function() {
        // Remove highlight from all teacher options
        document.querySelectorAll('.teacher-option').forEach(option => {
          option.classList.remove('selected');
        });

        // Add highlight to selected teacher option
        if (this.checked) {
          this.closest('.teacher-option').classList.add('selected');
        }
      });

      // Set initial state
      if (radio.checked) {
        radio.closest('.teacher-option').classList.add('selected');
      }
    });

    // Form validation
    document.getElementById('addClassForm').addEventListener('submit', function(e) {
      const className = document.getElementById('class_name').value.trim();
      const classCode = document.getElementById('class_code').value.trim();
      const teacherSelected = document.querySelector('input[name="teacher_id"]:checked');

      if (!className || !classCode || !teacherSelected) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return;
      }

      if (classCode.length > 10) {
        e.preventDefault();
        alert('Class code must be 10 characters or less.');
        return;
      }

      if (!/^[A-Z0-9]+$/.test(classCode)) {
        e.preventDefault();
        alert('Class code can only contain uppercase letters and numbers.');
        return;
      }
    });
  </script>
</body>

</html>