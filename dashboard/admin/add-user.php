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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $username = trim($_POST['username']);
  $name = trim($_POST['name']);
  $email = trim($_POST['email']);
  $password = $_POST['password'];
  $role = $_POST['role'];

  // Validation
  if (empty($username) || empty($name) || empty($email) || empty($password) || empty($role)) {
    $error_message = "All fields are required.";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error_message = "Please enter a valid email address.";
  } elseif (strlen($password) < 6) {
    $error_message = "Password must be at least 6 characters long.";
  } elseif (!in_array($role, ['student', 'teacher', 'admin'])) {
    $error_message = "Please select a valid role.";
  } else {
    try {
      // Check if username or email already exists
      $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
      $stmt->execute([$username, $email]);

      if ($stmt->fetch()) {
        $error_message = "Username or email already exists.";
      } else {
        // Hash password and insert user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, name, email, password, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $name, $email, $hashed_password, $role]);

        $success_message = "User created successfully!";
        // Clear form data
        $username = $name = $email = $password = $role = '';
      }
    } catch (PDOException $e) {
      $error_message = "Error creating user: " . $e->getMessage();
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add New User - Admin Dashboard</title>
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

    .role-selection {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 15px;
      margin-top: 10px;
    }

    .role-option {
      position: relative;
    }

    .role-option input[type="radio"] {
      display: none;
    }

    .role-option label {
      display: block;
      padding: 20px;
      background: rgba(255, 255, 255, 0.05);
      border: 2px solid rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.3s ease;
      text-align: center;
      font-weight: 600;
    }

    .role-option input[type="radio"]:checked+label {
      border-color: var(--primary);
      background: rgba(108, 92, 231, 0.2);
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(108, 92, 231, 0.3);
    }

    .role-icon {
      font-size: 1.8rem;
      margin-bottom: 10px;
      display: block;
    }

    .role-student .role-icon {
      color: var(--success);
    }

    .role-teacher .role-icon {
      color: var(--primary);
    }

    .role-admin .role-icon {
      color: var(--danger);
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

    .password-strength {
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

      .role-selection {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <div class="admin-container">
    <div class="page-header">
      <h1 class="page-title">Add New User</h1>
      <a href="users.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Users
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

      <form method="POST" id="addUserForm">
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" class="form-control"
            placeholder="Enter username" value="<?php echo htmlspecialchars($username ?? ''); ?>" required>
        </div>

        <div class="form-group">
          <label for="name">Full Name</label>
          <input type="text" id="name" name="name" class="form-control"
            placeholder="Enter full name" value="<?php echo htmlspecialchars($name ?? ''); ?>" required>
        </div>

        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" class="form-control"
            placeholder="Enter email address" value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" class="form-control"
            placeholder="Enter password (min. 6 characters)" required>
          <div class="password-strength">
            Password must be at least 6 characters long
          </div>
        </div>

        <div class="form-group">
          <label>Select Role</label>
          <div class="role-selection">
            <div class="role-option role-student">
              <input type="radio" id="role_student" name="role" value="student"
                <?php echo (isset($role) && $role === 'student') ? 'checked' : ''; ?>>
              <label for="role_student">
                <i class="fas fa-user-graduate role-icon"></i>
                Student
              </label>
            </div>

            <div class="role-option role-teacher">
              <input type="radio" id="role_teacher" name="role" value="teacher"
                <?php echo (isset($role) && $role === 'teacher') ? 'checked' : ''; ?>>
              <label for="role_teacher">
                <i class="fas fa-chalkboard-teacher role-icon"></i>
                Teacher
              </label>
            </div>

            <div class="role-option role-admin">
              <input type="radio" id="role_admin" name="role" value="admin"
                <?php echo (isset($role) && $role === 'admin') ? 'checked' : ''; ?>>
              <label for="role_admin">
                <i class="fas fa-user-shield role-icon"></i>
                Admin
              </label>
            </div>
          </div>
        </div>

        <button type="submit" class="btn-submit">
          <i class="fas fa-user-plus"></i> Create User
        </button>
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

    // Form validation
    document.getElementById('addUserForm').addEventListener('submit', function(e) {
      const password = document.getElementById('password').value;
      const role = document.querySelector('input[name="role"]:checked');

      if (password.length < 6) {
        e.preventDefault();
        alert('Password must be at least 6 characters long.');
        return;
      }

      if (!role) {
        e.preventDefault();
        alert('Please select a role for the user.');
        return;
      }
    });
  </script>
</body>

</html>