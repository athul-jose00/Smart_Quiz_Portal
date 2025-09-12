<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../auth/login.php');
  exit();
}

// Get admin details
$admin_id = $_SESSION['user_id'];
try {
  $stmt = $pdo->prepare("SELECT username, name FROM users WHERE user_id = ?");
  $stmt->execute([$admin_id]);
  $admin = $stmt->fetch();

  $full_name = $admin['name'] ?? 'Admin';
  $initials = '';
  $name_parts = explode(' ', $full_name);
  foreach ($name_parts as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
    if (strlen($initials) >= 2) break;
  }
} catch (PDOException $e) {
  $full_name = 'Administrator';
  $initials = 'A';
}

// Get dashboard statistics
$stats = [];
try {
  // Total users
  $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
  $stats['total_users'] = $stmt->fetch()['total'];

  // Total students
  $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'student'");
  $stats['total_students'] = $stmt->fetch()['total'];

  // Total teachers
  $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'teacher'");
  $stats['total_teachers'] = $stmt->fetch()['total'];

  // Total classes - check if table exists first
  try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM classes");
    $stats['total_classes'] = $stmt->fetch()['total'];
  } catch (PDOException $e) {
    // Table might not exist yet
    $stats['total_classes'] = 0;
  }

  // Total quizzes - check if table exists first
  try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM quizzes");
    $stats['total_quizzes'] = $stmt->fetch()['total'];
  } catch (PDOException $e) {
    // Table might not exist yet
    $stats['total_quizzes'] = 0;
  }
} catch (PDOException $e) {
  // Log the error for debugging
  error_log("Dashboard stats error: " . $e->getMessage());
  $stats = [
    'total_users' => 0,
    'total_students' => 0,
    'total_teachers' => 0,
    'total_classes' => 0,
    'total_quizzes' => 0
  ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard - EduQuiz AI</title>
  <link rel="stylesheet" href="../../css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* Reset and base styles for dashboard */
    body {
      margin: 0;
      padding: 0;
      background: #0a0e2e;
      overflow-x: hidden;
    }

    /* Simple Header */
    .simple-header {
      background: rgba(26, 26, 46, 0.95);
      backdrop-filter: blur(15px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      padding: 1rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .logo h1 {
      font-size: 1.8rem;
      margin: 0;
      background: linear-gradient(90deg, var(--accent), var(--primary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .logout-btn {
      background: linear-gradient(135deg, var(--danger), #e74c3c);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 500;
      transition: all 0.3s ease;
      font-size: 0.9rem;
    }

    .logout-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(214, 48, 49, 0.4);
    }

    /* Dashboard Layout */
    .dashboard-container {
      min-height: calc(100vh - 80px);
      background: transparent;
      padding: 3rem 2rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }

    /* Main Content */
    .main-content {
      background: transparent;
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
    }

    .dashboard-header {
      text-align: center;
      margin-bottom: 3rem;
    }

    .page-title h1 {
      color: #fff;
      font-size: 3rem;
      margin: 0 0 1rem 0;
      background: linear-gradient(90deg, var(--accent), var(--primary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .page-title p {
      color: rgba(255, 255, 255, 0.7);
      margin: 0;
      font-size: 1.2rem;
    }

    .header-actions {
      display: flex;
      gap: 1rem;
      align-items: center;
    }

    .notifications {
      position: relative;
      color: #fff;
      font-size: 1.2rem;
      cursor: pointer;
    }

    .notifications .badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background: #e74c3c;
      color: white;
      border-radius: 50%;
      padding: 0.25rem 0.5rem;
      font-size: 0.7rem;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      color: #fff;
      position: relative;
      cursor: pointer;
      padding: 0.5rem;
      border-radius: 8px;
      transition: background 0.3s ease;
    }

    .user-profile:hover {
      background: rgba(255, 255, 255, 0.1);
    }

    .user-profile .avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #4e73df;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 0.9rem;
    }

    .user-profile .username {
      font-weight: 500;
    }

    .dropdown-menu {
      position: absolute;
      top: 100%;
      right: 0;
      background: #2d3748;
      border-radius: 8px;
      padding: 0.5rem 0;
      min-width: 200px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
      opacity: 0;
      visibility: hidden;
      transform: translateY(10px);
      transition: all 0.3s ease;
      z-index: 1000;
    }

    .user-profile:hover .dropdown-menu {
      opacity: 1;
      visibility: visible;
      transform: translateY(5px);
    }

    .dropdown-item {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.75rem 1.25rem;
      color: #e2e8f0;
      text-decoration: none;
      transition: all 0.2s ease;
    }

    .dropdown-item:hover {
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
    }

    .dropdown-item i {
      width: 20px;
      text-align: center;
    }

    .dropdown-divider {
      height: 1px;
      background: rgba(255, 255, 255, 0.1);
      margin: 0.5rem 0;
    }

    .text-danger {
      color: #e74c3c;
    }

    .admin-title {
      font-size: 2.5rem;
      margin-bottom: 10px;
      background: linear-gradient(90deg, var(--accent), var(--primary));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .admin-subtitle {
      color: rgba(255, 255, 255, 0.7);
      font-size: 1.1rem;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .stat-card {
      background: rgba(255, 255, 255, 0.05);
      padding: 1.5rem;
      border-radius: 10px;
      text-align: left;
      transition: all 0.3s ease;
      color: #fff;
      border-left: 4px solid #4e73df;
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      border-radius: 15px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 15px;
      font-size: 1.5rem;
      color: white;
    }

    .stat-icon.users {
      background: linear-gradient(135deg, #667eea, #764ba2);
    }

    .stat-icon.students {
      background: linear-gradient(135deg, #f093fb, #f5576c);
    }

    .stat-icon.teachers {
      background: linear-gradient(135deg, #4facfe, #00f2fe);
    }

    .stat-icon.classes {
      background: linear-gradient(135deg, #43e97b, #38f9d7);
    }

    .stat-icon.quizzes {
      background: linear-gradient(135deg, #fa709a, #fee140);
    }

    .stat-number {
      font-size: 2rem;
      font-weight: 700;
      color: white;
      margin: 0.5rem 0;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .management-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
      gap: 2rem;
      max-width: 1200px;
      margin: 0 auto;
      justify-content: center;
    }

    .management-card {
      background: rgba(255, 255, 255, 0.03);
      border-radius: 12px;
      padding: 1.75rem;
      border: 1px solid rgba(255, 255, 255, 0.05);
      backdrop-filter: blur(10px);
      transition: all 0.3s ease;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .management-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
      border-color: rgba(78, 115, 223, 0.3);
    }

    .management-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
    }

    .management-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .management-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      color: white;
      background: linear-gradient(135deg, #4e73df 0%, #3a5bd9 100%);
      flex-shrink: 0;
    }

    .management-icon.user-mgmt {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
    }

    .management-icon.class-mgmt {
      background: linear-gradient(135deg, var(--success), #00cec9);
    }

    .management-icon.quiz-mgmt {
      background: linear-gradient(135deg, var(--warning), #e17055);
    }

    .management-title {
      font-size: 1.25rem;
      color: white;
      margin: 0;
      font-weight: 600;
    }

    .management-description {
      color: rgba(255, 255, 255, 0.6);
      margin-bottom: 1.5rem;
      line-height: 1.6;
      font-size: 0.95rem;
    }

    .action-buttons {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .action-btn {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.75rem 1rem;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 8px;
      color: rgba(255, 255, 255, 0.9);
      text-decoration: none;
      transition: all 0.3s ease;
      font-size: 0.9rem;
      font-weight: 500;
    }

    .action-btn:hover {
      background: rgba(78, 115, 223, 0.2);
      border-color: rgba(78, 115, 223, 0.3);
      color: #fff;
      transform: translateX(5px);
    }

    .action-btn i {
      color: #4e73df;
      transition: color 0.3s ease;
    }

    .action-btn:hover i {
      color: #fff;
    }

    .action-btn i {
      font-size: 0.9rem;
      opacity: 0.8;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 1.5rem;
      margin-bottom: 3rem;
      max-width: 1200px;
      margin-left: auto;
      margin-right: auto;
    }

    .stat-card {
      background: rgba(255, 255, 255, 0.05);
      padding: 1.5rem;
      border-radius: 10px;
      text-align: left;
      transition: all 0.3s ease;
      color: #fff;
      border-left: 4px solid #4e73df;
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      border-radius: 15px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 15px;
      font-size: 1.5rem;
      color: white;
    }

    .stat-icon.users {
      background: linear-gradient(135deg, #667eea, #764ba2);
    }

    .stat-icon.students {
      background: linear-gradient(135deg, #f093fb, #f5576c);
    }

    .stat-icon.teachers {
      background: linear-gradient(135deg, #4facfe, #00f2fe);
    }

    .stat-icon.classes {
      background: linear-gradient(135deg, #43e97b, #38f9d7);
    }

    .stat-icon.quizzes {
      background: linear-gradient(135deg, #fa709a, #fee140);
    }

    .stat-number {
      font-size: 2rem;
      font-weight: 700;
      color: white;
      margin: 0.5rem 0;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Responsive Design */
    @media (max-width: 1200px) {
      .management-grid {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      }
    }

    @media (max-width: 992px) {
      .dashboard-container {
        padding: 2rem 1rem;
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .management-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
      }

      .page-title h1 {
        font-size: 2.5rem;
      }
    }

    @media (max-width: 768px) {
      .simple-header {
        padding: 1rem;
        flex-direction: column;
        gap: 1rem;
        text-align: center;
      }

      .logo h1 {
        font-size: 1.5rem;
      }

      .page-title h1 {
        font-size: 2rem;
      }

      .user-profile .username {
        display: none;
      }

      .dashboard-container {
        padding: 1.5rem 1rem;
      }

      .stats-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 576px) {
      .management-card {
        padding: 1.25rem;
      }

      .management-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }

      .management-title {
        font-size: 1.1rem;
      }
    }

    @media (max-width: 768px) {
      .stats-container {
        grid-template-columns: 1fr;
      }

      .logout-btn {
        position: static;
        margin-bottom: 20px;
        text-align: center;
      }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles-js"></div>

  <!-- Simple Header with Logout -->
  <div class="simple-header">
    <div class="logo">
      <h1>Smart Quiz Portal - Admin</h1>
    </div>
    <div class="header-actions">
      <div class="user-profile">
        <div class="avatar">
          <?php echo substr($full_name, 0, 1); ?>
        </div>
        <span class="username"><?php echo htmlspecialchars($full_name); ?></span>
        <a href="../../auth/logout.php" class="logout-btn">
          <i class="fas fa-sign-out-alt"></i> Logout
        </a>
      </div>
    </div>
  </div>

  <div class="dashboard-container">
    <!-- Main Content -->
    <main class="main-content">
      <div class="dashboard-header">
        <div class="page-title">
          <h1>Admin Dashboard</h1>
          <p>Welcome back! Here's what's happening today.</p>
        </div>
      </div>

      <!-- Statistics Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon users">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-number"><?php echo $stats['total_users']; ?></div>
          <div class="stat-label">Total Users</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon students">
            <i class="fas fa-user-graduate"></i>
          </div>
          <div class="stat-number"><?php echo $stats['total_students']; ?></div>
          <div class="stat-label">Students</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon teachers">
            <i class="fas fa-chalkboard-teacher"></i>
          </div>
          <div class="stat-number"><?php echo $stats['total_teachers']; ?></div>
          <div class="stat-label">Teachers</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon classes">
            <i class="fas fa-school"></i>
          </div>
          <div class="stat-number"><?php echo $stats['total_classes']; ?></div>
          <div class="stat-label">Classes</div>
        </div>

        <div class="stat-card">
          <div class="stat-icon quizzes">
            <i class="fas fa-clipboard-list"></i>
          </div>
          <div class="stat-number"><?php echo $stats['total_quizzes']; ?></div>
          <div class="stat-label">Quizzes</div>
        </div>
      </div>

      <!-- Management Sections -->
      <div class="management-grid">
        <!-- User Management -->
        <div class="management-card">
          <div class="management-header">
            <div class="management-icon user-mgmt">
              <i class="fas fa-users-cog"></i>
            </div>
            <h2 class="management-title">User Management</h2>
          </div>
          <p class="management-description">
            Manage all users in the system including students and teachers. Add new users, edit existing profiles, and assign roles.
          </p>
          <div class="action-buttons">
            <a href="users.php" class="action-btn">
              <i class="fas fa-list"></i>
              View All Users
            </a>
            <a href="add-user.php" class="action-btn">
              <i class="fas fa-user-plus"></i>
              Add New User
            </a>
            
          </div>
        </div>

        <!-- Class Management -->
        <div class="management-card">
          <div class="management-header">
            <div class="management-icon class-mgmt">
              <i class="fas fa-school"></i>
            </div>
            <h2 class="management-title">Class Management</h2>
          </div>
          <p class="management-description">
            Organize and manage classes, assign students to classes, and oversee class activities and assignments.
          </p>
          <div class="action-buttons">
            <a href="classes.php" class="action-btn">
              <i class="fas fa-list"></i>
              View All Classes
            </a>
            <a href="assign-students.php" class="action-btn">
              <i class="fas fa-user-plus"></i>
              Assign Students to Classes
            </a>
            <a href="class-reports.php" class="action-btn">
              <i class="fas fa-chart-bar"></i>
              Class Performance Reports
            </a>
          </div>
        </div>

        <!-- Quiz Monitoring -->
        <div class="management-card">
          <div class="management-header">
            <div class="management-icon quiz-mgmt">
              <i class="fas fa-clipboard-list"></i>
            </div>
            <h2 class="management-title">Quiz Monitoring</h2>
          </div>
          <p class="management-description">
            Monitor all quizzes created by teachers, track student participation, and analyze performance metrics.
          </p>
          <div class="action-buttons">
            <a href="quiz-overview.php" class="action-btn">
              <i class="fas fa-eye"></i>
              View All Quizzes
            </a>
            <a href="participation-tracking.php" class="action-btn">
              <i class="fas fa-users"></i>
              Track Student Participation
            </a>
            <a href="performance-analytics.php" class="action-btn">
              <i class="fas fa-chart-line"></i>
              Performance Analytics
            </a>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
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

    // Simple navigation functionality can be added here if needed
  </script>
</body>

</html>