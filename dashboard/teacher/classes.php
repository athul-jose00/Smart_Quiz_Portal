<?php
session_start();
require_once '../../includes/db.php';

// Make sure the user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get teacher info
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

// Handle form submission for new class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class'])) {
    $class_name = trim($_POST['class_name']);

    // Generate unique class code (6 characters)
    $class_code = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6);

    // Check if code exists (very unlikely but just in case)
    $check_sql = "SELECT class_id FROM classes WHERE class_code = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $class_code);
    $check_stmt->execute();
    $check_stmt->store_result();

    while ($check_stmt->num_rows > 0) {
        $class_code = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6);
        $check_stmt->bind_param("s", $class_code);
        $check_stmt->execute();
        $check_stmt->store_result();
    }
    $check_stmt->close();

    // Insert new class
    $insert_sql = "INSERT INTO classes (class_name, class_code, teacher_id) VALUES (?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("ssi", $class_name, $class_code, $user_id);

    if ($insert_stmt->execute()) {
        $success_message = "Class created successfully! Class Code: $class_code";
    } else {
        $error_message = "Error creating class: " . $conn->error;
    }
    $insert_stmt->close();
}

// Handle class deletion
if (isset($_GET['delete'])) {
    $class_id = intval($_GET['delete']);

    // Verify the class belongs to this teacher before deleting
    $verify_sql = "SELECT class_id FROM classes WHERE class_id = ? AND teacher_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $class_id, $user_id);
    $verify_stmt->execute();
    $verify_stmt->store_result();

    if ($verify_stmt->num_rows > 0) {
        // Delete the class (ON DELETE CASCADE will handle user_classes entries)
        $delete_sql = "DELETE FROM classes WHERE class_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $class_id);

        if ($delete_stmt->execute()) {
            $success_message = "Class deleted successfully!";
        } else {
            $error_message = "Error deleting class: " . $conn->error;
        }
        $delete_stmt->close();
    } else {
        $error_message = "You don't have permission to delete this class or it doesn't exist.";
    }
    $verify_stmt->close();

    // Redirect to avoid resubmission on refresh
    header("Location: classes.php?message=" . urlencode($success_message ?? $error_message));
    exit();
}

// Get message from URL if present
if (isset($_GET['message'])) {
    if (strpos($_GET['message'], 'successfully') !== false) {
        $success_message = $_GET['message'];
    } else {
        $error_message = $_GET['message'];
    }
}

// Get all classes taught by this teacher
$classes_sql = "SELECT class_id, class_name, class_code FROM classes WHERE teacher_id = ?";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $user_id);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$total_classes = $classes_result->num_rows;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Classes | Smart Quiz Portal</title>
    <link rel="stylesheet" href="../../css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        /* Improved Visibility */
        body {
            color: rgba(255, 255, 255, 0.9);
        }

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

        .teacher-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .teacher-avatar {
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

        .teacher-info h3 {
            margin-bottom: 5px;
            font-size: 1.1rem;
            color: white;
        }

        .teacher-info p {
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

        .quick-actions {
            display: flex;
            gap: 1rem;
        }

        /* Enhanced Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 22px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            background: rgba(255, 255, 255, 0.12);
        }

        .stat-card i {
            font-size: 1.6rem;
            margin-bottom: 15px;
            color: var(--accent);
        }

        .stat-card h3 {
            font-size: 2.2rem;
            margin-bottom: 5px;
            color: white;
        }

        .stat-card p {
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.95rem;
        }

        /* Classes Table */
        .classes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        .classes-table th,
        .classes-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .classes-table th {
            background: rgba(108, 92, 231, 0.3);
            color: white;
            font-weight: 500;
        }

        .classes-table tr:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .class-code {
            font-family: monospace;
            letter-spacing: 1px;
            color: var(--accent);
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .view-btn {
            background: rgba(108, 92, 231, 0.2);
            color: white;
        }

        .view-btn:hover {
            background: rgba(108, 92, 231, 0.4);
        }

        /* Add Class Form */
        .add-class-form {
            background: rgba(255, 255, 255, 0.08);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.9);
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            color: white;
            font-size: 1rem;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .submit-btn {
            background: var(--primary);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .submit-btn:hover {
            background: var(--primary-dark);
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
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

        .delete-btn {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
            margin-left: 8px;
        }

        .delete-btn:hover {
            background: rgba(231, 76, 60, 0.4);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        /* Confirmation modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: rgba(26, 26, 46, 0.98);
            padding: 25px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .modal-btn {
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            border: none;
        }

        .modal-cancel {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .modal-confirm {
            background: #e74c3c;
            color: white;
        }
    </style>
</head>

<body>
    <div class="particles" id="particles-js"></div>

    <div class="container-dashboard" style="padding: 1px">
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
                    <div class="teacher-profile">
                        <div class="teacher-avatar"><?php echo $initials; ?></div>
                        <div class="teacher-info">
                            <h3><?php echo htmlspecialchars($full_name); ?></h3>
                            <p>Teacher</p>
                        </div>
                    </div>
                </div>

                <nav class="sidebar-menu">
                    <a href="teacher.php" class="menu-item">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="classes.php" class="menu-item active">
                        <i class="fas fa-users"></i> My Classes
                    </a>
                    <a href="quizzes.php" class="menu-item">
                        <i class="fas fa-question-circle"></i> Quizzes
                    </a>
                    <a href="create-quiz.php" class="menu-item">
                        <i class="fas fa-plus-circle"></i> Create Quiz
                    </a>
                    <a href="results.php" class="menu-item">
                        <i class="fas fa-chart-bar"></i> Results
                    </a>
                </nav>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <div class="dashboard-header">
                    <div class="page-title">
                        <h1>My Classes</h1>
                        <p>Manage your classes and view student enrollments</p>
                    </div>

                    <div class="quick-actions">
                        <a href="create-quiz.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Quiz
                        </a>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="stats-cards">
                    <div class="stat-card">
                        <i class="fas fa-users"></i>
                        <h3><?php echo $total_classes; ?></h3>
                        <p>Total Classes</p>
                    </div>
                </div>

                <!-- Add Class Form -->
                <div class="add-class-form">
                    <h2 style="margin-top: 0; margin-bottom: 20px;">Add New Class</h2>

                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-error">
                            <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="class_name">Class Name</label>
                            <input type="text" id="class_name" name="class_name" required placeholder="e.g., Mathematics 101">
                        </div>

                        <button type="submit" name="add_class" class="submit-btn">
                            <i class="fas fa-plus"></i> Create Class
                        </button>
                    </form>
                </div>

                <!-- Classes Table -->
                <h2 style="margin-bottom: 15px;">Your Classes</h2>

                <?php if ($total_classes > 0): ?>
                    <table class="classes-table">
                        <thead>
                            <tr>
                                <th>Class Name</th>
                                <th>Class Code</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($class = $classes_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($class['class_name']); ?></td>
                                    <td><span class="class-code"><?php echo htmlspecialchars($class['class_code']); ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="class-details.php?id=<?php echo $class['class_id']; ?>" class="action-btn view-btn">
                                                <i class="fas fa-info-circle"></i> Details
                                            </a>
                                            <a href="results.php?class_id=<?php echo $class['class_id']; ?>" class="action-btn view-btn" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71;">
                                                <i class="fas fa-chart-bar"></i> Results
                                            </a>
                                            <button class="action-btn delete-btn" onclick="confirmDelete(<?php echo $class['class_id']; ?>, '<?php echo htmlspecialchars(addslashes($class['class_name'])); ?>')">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: rgba(255, 255, 255, 0.7);">You haven't created any classes yet. Use the form above to add your first class.</p>
                <?php endif; ?>
            </main>
            <!-- Delete Confirmation Modal -->
            <div id="deleteModal" class="modal">
                <div class="modal-content">
                    <h3>Confirm Deletion</h3>
                    <p id="deleteMessage">Are you sure you want to delete this class?</p>
                    <div class="modal-actions">
                        <button class="modal-btn modal-cancel" onclick="closeModal()">Cancel</button>
                        <button class="modal-btn modal-confirm" id="confirmDeleteBtn">Delete</button>
                    </div>
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
                });
            </script>
            <script>
                let classToDelete = null;

                function confirmDelete(classId, className) {
                    classToDelete = classId;
                    document.getElementById('deleteMessage').textContent =
                        `Are you sure you want to delete the class "${className}"? This action cannot be undone.`;
                    document.getElementById('deleteModal').style.display = 'flex';
                }

                function closeModal() {
                    document.getElementById('deleteModal').style.display = 'none';
                    classToDelete = null;
                }

                document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
                    if (classToDelete) {
                        window.location.href = `classes.php?delete=${classToDelete}`;
                    }
                });

                // Close modal when clicking outside
                window.addEventListener('click', function(event) {
                    if (event.target === document.getElementById('deleteModal')) {
                        closeModal();
                    }
                });
            </script>
</body>

</html>
<?php
$classes_stmt->close();
$conn->close();
?>