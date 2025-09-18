<?php
session_start();
require_once '../../includes/db.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../auth/login.php');
  exit();
}

// Handle user deletion
if (isset($_POST['delete_user'])) {
  $user_id = $_POST['user_id'];
  try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ? AND user_id != ?");
    $stmt->execute([$user_id, $_SESSION['user_id']]); // Prevent admin from deleting themselves
    $success_message = "User deleted successfully!";
  } catch (PDOException $e) {
    $error_message = "Error deleting user: " . $e->getMessage();
  }
}

// Get all users
try {
  $stmt = $pdo->query("SELECT user_id, username, name, email, role FROM users ORDER BY user_id DESC");
  $users = $stmt->fetchAll();
} catch (PDOException $e) {
  $users = [];
  $error_message = "Error fetching users: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management - Admin Dashboard</title>
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

    .users-table-container {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 15px;
      padding: 30px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      overflow-x: auto;
    }

    .users-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }

    .users-table th,
    .users-table td {
      padding: 15px;
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .users-table th {
      background: rgba(255, 255, 255, 0.1);
      color: var(--accent);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
      font-size: 0.9rem;
    }

    .users-table td {
      color: rgba(255, 255, 255, 0.9);
    }

    .users-table tr:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .role-badge {
      padding: 5px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .role-admin {
      background: linear-gradient(135deg, var(--danger), #e74c3c);
      color: white;
    }

    .role-teacher {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
    }

    .role-student {
      background: linear-gradient(135deg, var(--success), #00cec9);
      color: white;
    }

    .action-buttons {
      display: flex;
      gap: 8px;
    }

    .btn-edit {
      background: linear-gradient(135deg, var(--warning), #e17055);
      color: white;
      padding: 8px 12px;
      border-radius: 6px;
      text-decoration: none;
      font-size: 0.8rem;
      transition: all 0.3s ease;
    }

    .btn-edit:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(253, 203, 110, 0.4);
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

    .add-user-btn {
      background: linear-gradient(135deg, var(--success), #00cec9);
      color: white;
      padding: 15px 25px;
      border-radius: 25px;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(0, 184, 148, 0.4);
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 20px;
    }

    .add-user-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 184, 148, 0.6);
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

      .users-table-container {
        padding: 20px;
      }

      .users-table {
        font-size: 0.9rem;
      }

      .users-table th,
      .users-table td {
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
      <h1 class="page-title">User Management</h1>
      <a href="admin.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>

    <?php if (isset($success_message)): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
      </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
      </div>
    <?php endif; ?>

    <a href="add-user.php" class="add-user-btn">
      <i class="fas fa-user-plus"></i>
      Add New User
    </a>

    <div class="users-table-container">
      <?php if (empty($users)): ?>
        <div class="empty-state">
          <i class="fas fa-users"></i>
          <h3>No Users Found</h3>
          <p>Start by adding your first user to the system.</p>
        </div>
      <?php else: ?>
        <table class="users-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <tr>
                <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
                <td><?php echo htmlspecialchars($user['name']); ?></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td>
                  <span class="role-badge role-<?php echo $user['role']; ?>">
                    <?php echo ucfirst($user['role']); ?>
                  </span>
                </td>
                <td>
                  <div class="action-buttons">
                    <a href="edit-user.php?id=<?php echo $user['user_id']; ?>" class="btn-edit">
                      <i class="fas fa-edit"></i> Edit
                    </a>
                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                      <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                        <button type="submit" name="delete_user" class="btn-delete">
                          <i class="fas fa-trash"></i> Delete
                        </button>
                      </form>
                    <?php endif; ?>
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