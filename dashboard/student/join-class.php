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

$success_message = '';
$error_message = '';

// Handle class joining
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['class_code'])) {
  $class_code = trim(strtoupper($_POST['class_code']));

  if (empty($class_code)) {
    $error_message = "Please enter a class code.";
  } else {
    // Check if class exists
    $class_sql = "SELECT c.class_id, c.class_name, u.name as teacher_name 
                     FROM classes c 
                     JOIN users u ON c.teacher_id = u.user_id 
                     WHERE c.class_code = ?";
    $class_stmt = $conn->prepare($class_sql);
    $class_stmt->bind_param("s", $class_code);
    $class_stmt->execute();
    $class_result = $class_stmt->get_result();

    if ($class_result->num_rows > 0) {
      $class_data = $class_result->fetch_assoc();
      $class_id = $class_data['class_id'];

      // Check if student is already enrolled
      $check_sql = "SELECT * FROM user_classes WHERE user_id = ? AND class_id = ?";
      $check_stmt = $conn->prepare($check_sql);
      $check_stmt->bind_param("ii", $user_id, $class_id);
      $check_stmt->execute();
      $check_result = $check_stmt->get_result();

      if ($check_result->num_rows > 0) {
        $error_message = "You are already enrolled in this class.";
      } else {
        // Enroll student in class
        $enroll_sql = "INSERT INTO user_classes (user_id, class_id) VALUES (?, ?)";
        $enroll_stmt = $conn->prepare($enroll_sql);
        $enroll_stmt->bind_param("ii", $user_id, $class_id);

        if ($enroll_stmt->execute()) {
          $success_message = "Successfully joined '{$class_data['class_name']}' taught by {$class_data['teacher_name']}!";
        } else {
          $error_message = "Error joining class. Please try again.";
        }
        $enroll_stmt->close();
      }
      $check_stmt->close();
    } else {
      $error_message = "Invalid class code. Please check and try again.";
    }
    $class_stmt->close();
  }
}

// Get student's enrolled classes
$enrolled_sql = "SELECT c.class_id, c.class_name, c.class_code, u.name as teacher_name,
                 (SELECT COUNT(*) FROM quizzes q WHERE q.class_id = c.class_id) as quiz_count
                 FROM user_classes uc
                 JOIN classes c ON uc.class_id = c.class_id
                 JOIN users u ON c.teacher_id = u.user_id
                 WHERE uc.user_id = ?
                 ORDER BY c.class_name";
$enrolled_stmt = $conn->prepare($enrolled_sql);
$enrolled_stmt->bind_param("i", $user_id);
$enrolled_stmt->execute();
$enrolled_result = $enrolled_stmt->get_result();
$enrolled_classes = $enrolled_result->fetch_all(MYSQLI_ASSOC);
$enrolled_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Join Class | Smart Quiz Portal</title>
  <link rel="stylesheet" href="../../css/style.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
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

    /* Join Class Form */
    .join-class-container {
      max-width: 600px;
      margin: 0 auto 3rem auto;
    }

    .join-form-card {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 16px;
      padding: 2.5rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
      text-align: center;
    }

    .join-form-icon {
      font-size: 3rem;
      color: var(--accent);
      margin-bottom: 1.5rem;
    }

    .join-form-title {
      font-size: 1.5rem;
      color: white;
      margin-bottom: 0.5rem;
      font-weight: 600;
    }

    .join-form-subtitle {
      color: rgba(255, 255, 255, 0.7);
      margin-bottom: 2rem;
      font-size: 1rem;
    }

    .form-group {
      margin-bottom: 1.5rem;
      text-align: left;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      color: rgba(255, 255, 255, 0.9);
      font-weight: 500;
    }

    .form-group input {
      width: 100%;
      padding: 15px;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 10px;
      color: white;
      font-size: 1.1rem;
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 2px;
      font-weight: bold;
      transition: all 0.3s ease;
    }

    .form-group input:focus {
      outline: none;
      border-color: var(--primary);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
    }

    .form-group input::placeholder {
      color: rgba(255, 255, 255, 0.5);
      text-transform: none;
      letter-spacing: normal;
      font-weight: normal;
    }

    .submit-btn {
      background: linear-gradient(135deg, var(--primary), var(--accent));
      color: white;
      padding: 15px 30px;
      border: none;
      border-radius: 10px;
      cursor: pointer;
      font-size: 1.1rem;
      font-weight: 600;
      transition: all 0.3s ease;
      width: 100%;
      margin-top: 1rem;
    }

    .submit-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(108, 92, 231, 0.4);
    }

    .submit-btn:active {
      transform: translateY(0);
    }

    /* Alert Messages */
    .alert {
      padding: 15px 20px;
      border-radius: 10px;
      margin-bottom: 2rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .alert-success {
      background: rgba(46, 204, 113, 0.2);
      border: 1px solid rgba(46, 204, 113, 0.3);
      color: #2ecc71;
    }

    .alert-error {
      background: rgba(231, 76, 60, 0.2);
      border: 1px solid rgba(231, 76, 60, 0.3);
      color: #e74c3c;
    }

    /* Enrolled Classes Section */
    .enrolled-classes {
      margin-top: 3rem;
    }

    .section-title {
      font-size: 1.4rem;
      color: var(--accent);
      margin-bottom: 1.5rem;
      text-align: center;
    }

    .classes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 20px;
    }

    .class-card {
      background: rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.12);
      transition: all 0.3s ease;
    }

    .class-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
      background: rgba(255, 255, 255, 0.12);
    }

    .class-card-header {
      padding: 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .class-card-header h3 {
      margin: 0 0 8px 0;
      font-size: 1.2rem;
      color: white;
    }

    .class-code {
      font-size: 0.9rem;
      background: rgba(108, 92, 231, 0.2);
      padding: 4px 12px;
      border-radius: 20px;
      color: var(--primary);
      font-weight: 600;
      letter-spacing: 1px;
    }

    .class-card-body {
      padding: 20px;
    }

    .class-meta {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.95rem;
      margin-bottom: 15px;
    }

    .class-meta span {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
    }

    .class-actions {
      display: flex;
      gap: 10px;
    }

    .btn-sm {
      padding: 8px 16px;
      font-size: 0.9rem;
      border-radius: 6px;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: rgba(255, 255, 255, 0.6);
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      border: 2px dashed rgba(255, 255, 255, 0.1);
    }

    .empty-state i {
      font-size: 3rem;
      margin-bottom: 20px;
      color: rgba(255, 255, 255, 0.3);
    }

    .empty-state h3 {
      color: rgba(255, 255, 255, 0.8);
      margin-bottom: 10px;
    }

    .empty-state p {
      margin-bottom: 20px;
    }

    /* Instructions */
    .instructions {
      background: rgba(108, 92, 231, 0.1);
      border: 1px solid rgba(108, 92, 231, 0.2);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 2rem;
    }

    .instructions h4 {
      color: var(--primary);
      margin-bottom: 15px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .instructions ul {
      color: rgba(255, 255, 255, 0.8);
      padding-left: 20px;
    }

    .instructions li {
      margin-bottom: 8px;
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
          <a href="results.php" class="menu-item">
            <i class="fas fa-chart-bar"></i> My Results
          </a>
          <a href="join-class.php" class="menu-item active">
            <i class="fas fa-plus-circle"></i> Join Class
          </a>
        </nav>
      </aside>

      <!-- Main Content -->
      <main class="main-content">
        <div class="dashboard-header">
          <div class="page-title">
            <h1>Join a Class</h1>
            <p>Enter a class code to join and start learning.</p>
          </div>
        </div>

        <!-- Alert Messages -->
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

        <!-- Instructions -->
        <div class="instructions">
          <h4><i class="fas fa-info-circle"></i> How to Join a Class</h4>
          <ul>
            <li>Get the class code from your teacher</li>
            <li>Enter the code in the form below (case-insensitive)</li>
            <li>Click "Join Class" to enroll</li>
            <li>You'll immediately have access to all class quizzes</li>
          </ul>
        </div>

        <!-- Join Class Form -->
        <div class="join-class-container">
          <div class="join-form-card">
            <div class="join-form-icon">
              <i class="fas fa-plus-circle"></i>
            </div>
            <h2 class="join-form-title">Join a Class</h2>
            <p class="join-form-subtitle">Enter the class code provided by your teacher</p>

            <form method="POST" action="">
              <div class="form-group">
                <label for="class_code">Class Code</label>
                <input
                  type="text"
                  id="class_code"
                  name="class_code"
                  placeholder="Enter class code (e.g., ABC123)"
                  required
                  maxlength="10"
                  pattern="[A-Za-z0-9]+"
                  title="Class code should contain only letters and numbers">
              </div>
              <button type="submit" class="submit-btn">
                <i class="fas fa-plus"></i> Join Class
              </button>
            </form>
          </div>
        </div>

        <!-- Enrolled Classes -->
        <div class="enrolled-classes">
          <h2 class="section-title">My Enrolled Classes (<?php echo count($enrolled_classes); ?>)</h2>

          <?php if (empty($enrolled_classes)): ?>
            <div class="empty-state">
              <i class="fas fa-users"></i>
              <h3>No Classes Yet</h3>
              <p>You haven't joined any classes. Enter a class code above to get started!</p>
            </div>
          <?php else: ?>
            <div class="classes-grid">
              <?php foreach ($enrolled_classes as $class): ?>
                <div class="class-card">
                  <div class="class-card-header">
                    <h3><?php echo htmlspecialchars($class['class_name']); ?></h3>
                    <span class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></span>
                  </div>
                  <div class="class-card-body">
                    <div class="class-meta">
                      <span><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($class['teacher_name']); ?></span>
                      <span><i class="fas fa-question-circle"></i> <?php echo $class['quiz_count']; ?> quizzes available</span>
                    </div>
                    <div class="class-actions">
                      <a href="class-details.php?id=<?php echo $class['class_id']; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-eye"></i> View Class
                      </a>
                      <a href="quizzes.php?class_id=<?php echo $class['class_id']; ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-play"></i> Take Quizzes
                      </a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
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

      // Auto-uppercase class code input
      const classCodeInput = document.getElementById('class_code');
      classCodeInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
      });

      // Clear form after successful submission
      <?php if ($success_message): ?>
        document.getElementById('class_code').value = '';
      <?php endif; ?>
    });
  </script>
</body>

</html>
<?php
$conn->close();
?>