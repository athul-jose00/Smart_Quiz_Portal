<?php
session_start();
require_once '../../includes/db.php';

// Verify user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get teacher's classes
$classes_sql = "SELECT class_id, class_name FROM classes WHERE teacher_id = ?";
$stmt = $conn->prepare($classes_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get teacher's name for header
$teacher_sql = "SELECT name FROM users WHERE user_id = ?";
$stmt = $conn->prepare($teacher_sql);
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

// Get the current step
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;

// Handle navigation
if (isset($_GET['prev']) && isset($_SESSION['current_quiz'])) {
    if ($_SESSION['current_quiz']['current_question'] > 1) {
        $_SESSION['current_quiz']['current_question']--;
    }
    header("Location: create-quiz.php?step=2");
    exit();
}

// Load existing question data if navigating back
$existing_question = null;
$existing_options = [];
if (isset($_SESSION['current_quiz']) && $step === 2) {
    $quiz_id = $_SESSION['current_quiz']['id'];
    $current_q = $_SESSION['current_quiz']['current_question'];

    // Get existing question data
    $question_sql = "SELECT question_id, question_text, points FROM questions WHERE quiz_id = ? ORDER BY question_id LIMIT 1 OFFSET ?";
    $stmt = $conn->prepare($question_sql);
    $offset = $current_q - 1;
    $stmt->bind_param("ii", $quiz_id, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $existing_question = $result->fetch_assoc();

        // Get existing options
        $options_sql = "SELECT option_id, option_text, is_correct FROM options WHERE question_id = ? ORDER BY option_id";
        $opt_stmt = $conn->prepare($options_sql);
        $opt_stmt->bind_param("i", $existing_question['question_id']);
        $opt_stmt->execute();
        $opt_result = $opt_stmt->get_result();

        while ($option = $opt_result->fetch_assoc()) {
            $existing_options[] = $option;
        }
        $opt_stmt->close();
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_quiz'])) {
        // Step 1: Create quiz with basic info
        $class_id = intval($_POST['class_id']);
        $title = trim($_POST['title']);
        $time_limit = intval($_POST['time_limit']);
        $question_count = intval($_POST['question_count']);

        // Insert quiz
        $insert_sql = "INSERT INTO quizzes (title, time_limit, created_by, class_id) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("siii", $title, $time_limit, $user_id, $class_id);

        if ($stmt->execute()) {
            $quiz_id = $stmt->insert_id;
            $_SESSION['current_quiz'] = [
                'id' => $quiz_id,
                'title' => $title,
                'time_limit' => $time_limit,
                'class_id' => $class_id,
                'question_count' => $question_count,
                'current_question' => 1
            ];
            header("Location: create-quiz.php?step=2");
            exit();
        } else {
            $error = "Failed to create quiz: " . $conn->error;
        }
        $stmt->close();
    } elseif (isset($_POST['add_question']) && isset($_SESSION['current_quiz'])) {
        // Step 2: Add or update questions
        $quiz_id = $_SESSION['current_quiz']['id'];
        $question_text = trim($_POST['question_text']);
        $points = intval($_POST['points']);
        $options = $_POST['options'];
        $correct_option = intval($_POST['correct_option']);

        // Check if we're updating an existing question
        if (isset($_POST['question_id'])) {
            // Update existing question
            $question_id = intval($_POST['question_id']);
            $update_sql = "UPDATE questions SET question_text = ?, points = ? WHERE question_id = ? AND quiz_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("siis", $question_text, $points, $question_id, $quiz_id);

            if ($stmt->execute()) {
                // Delete existing options
                $delete_options_sql = "DELETE FROM options WHERE question_id = ?";
                $delete_stmt = $conn->prepare($delete_options_sql);
                $delete_stmt->bind_param("i", $question_id);
                $delete_stmt->execute();
                $delete_stmt->close();

                // Insert new options
                $option_sql = "INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)";
                $option_stmt = $conn->prepare($option_sql);

                foreach ($options as $index => $option_text) {
                    $is_correct = ($index == $correct_option) ? 1 : 0;
                    $option_stmt->bind_param("isi", $question_id, $option_text, $is_correct);
                    $option_stmt->execute();
                }
                $option_stmt->close();
            } else {
                $error = "Failed to update question: " . $conn->error;
                $stmt->close();
                // Don't proceed further if there was an error
                goto end_processing;
            }
        } else {
            // Insert new question
            $question_sql = "INSERT INTO questions (quiz_id, question_text, points) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($question_sql);
            $stmt->bind_param("isi", $quiz_id, $question_text, $points);

            if ($stmt->execute()) {
                $question_id = $stmt->insert_id;

                // Insert options
                $option_sql = "INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)";
                $option_stmt = $conn->prepare($option_sql);

                foreach ($options as $index => $option_text) {
                    $is_correct = ($index == $correct_option) ? 1 : 0;
                    $option_stmt->bind_param("isi", $question_id, $option_text, $is_correct);
                    $option_stmt->execute();
                }
                $option_stmt->close();
            } else {
                $error = "Failed to add question: " . $conn->error;
                $stmt->close();
                // Don't proceed further if there was an error
                goto end_processing;
            }
        }
        // Update current question counter
        $_SESSION['current_quiz']['current_question']++;

        // Check if we've added all questions
        if ($_SESSION['current_quiz']['current_question'] > $_SESSION['current_quiz']['question_count']) {
            unset($_SESSION['current_quiz']);
            header("Location: quizzes.php?success=Quiz created successfully");
            exit();
        } else {
            header("Location: create-quiz.php?step=2");
            exit();
        }
    }
}

// Label for goto statement
end_processing:

// We already defined $step at the top, so we don't need to redefine it here
$current_question = $_SESSION['current_quiz']['current_question'] ?? 1;
$question_count = $_SESSION['current_quiz']['question_count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $step === 1 ? 'Create New Quiz' : 'Add Questions'; ?> | Smart Quiz Portal</title>
    <link rel="stylesheet" href="../../css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="stylesheet" href="styles/sidebar.css" />
    <style>
        .quiz-create-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
        }

        .step {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            position: relative;
        }

        .step.active {
            color: var(--accent);
            font-weight: bold;
        }

        .step.completed {
            color: var(--primary);
        }

        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 0;
            width: 100%;
            height: 2px;
            background: rgba(255, 255, 255, 0.2);
            z-index: -1;
        }

        .step-number {
            display: inline-block;
            width: 30px;
            height: 30px;
            line-height: 30px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            margin-bottom: 0.5rem;
        }

        .step.active .step-number {
            background: var(--accent);
            color: white;
        }

        .step.completed .step-number {
            background: var(--primary);
            color: white;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: rgba(255, 255, 255, 0.9);
        }


        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            color: white;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg fill='white' height='24' viewBox='0 0 24 24' width='24' xmlns='http://www.w3.org/2000/svg'><path d='M7 10l5 5 5-5z'/><path d='M0 0h24v24H0z' fill='none'/></svg>");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(108, 92, 231, 0.2);
            background-color: rgba(255, 255, 255, 0.1);
        }

        .form-group select option {
            background-color: rgba(26, 26, 46, 0.98) !important;
            color: white !important;
        }

        /* Fix for Firefox */
        @-moz-document url-prefix() {
            .form-group select {
                background-color: rgba(255, 255, 255, 0.1);
                color: white;
            }

            .form-group select option {
                background-color: rgba(26, 26, 46, 0.98);
            }
        }

        /* Fix for Chrome and Edge */
        @media screen and (-webkit-min-device-pixel-ratio:0) {
            .form-group select {
                background-color: rgba(255, 255, 255, 0.1);
            }

            .form-group select option {
                background-color: rgba(26, 26, 46, 0.98);
            }
        }

        .form-group input {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 6px;
            color: white;
            padding: 10px;
            width: 4rem;
        }

        .form-group input[type="text"] {
            width: 100%;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .option-item {
            display: grid;
            grid-template-columns: 1fr 50px 50px;
            align-items: center;
            margin-bottom: 0.5rem;
            gap: 10px;
        }

        .option-item input[type="text"] {
            width: 100%;
        }

        /* Custom Checkbox Styling */
        .custom-checkbox {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            width: 50px;
            height: 24px;
            cursor: pointer;
        }

        .custom-checkbox input[type="radio"] {
            opacity: 0;
            position: absolute;
            width: 100%;
            height: 100%;
            margin: 0;
            cursor: pointer;
        }

        .checkbox-mark {
            position: absolute;
            top: 0;
            left: 8px;
            width: 24px;
            height: 24px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .custom-checkbox input[type="radio"]:checked+.checkbox-mark {
            background: var(--primary);
            border-color: var(--primary);
        }

        .custom-checkbox input[type="radio"]:checked+.checkbox-mark::after {
            content: '\2713';
            color: white;
            font-size: 16px;
            font-weight: bold;
        }

        .custom-checkbox:hover .checkbox-mark {
            border-color: var(--primary);
            background: rgba(108, 92, 231, 0.1);
        }

        .delete-option-btn {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: none;
            padding: 8px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .delete-option-btn:hover {
            background: rgba(220, 53, 69, 0.4);
            color: white;
        }

        .option-header {
            display: grid;
            grid-template-columns: 1fr 50px 50px;
            align-items: center;
            margin-bottom: 0.5rem;
            gap: 10px;
            font-weight: 500;
        }

        .correct-answer-label {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.7);
            font-weight: normal;
        }

        .add-option-btn {
            background: rgba(108, 92, 231, 0.2);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 1rem;
        }

        .add-option-btn:hover {
            background: rgba(108, 92, 231, 0.4);
        }

        .navigation-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-outline {
            background: transparent;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .progress-text {
            text-align: center;
            margin: 1rem 0;
            color: rgba(255, 255, 255, 0.7);
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
                    <a href="classes.php" class="menu-item">
                        <i class="fas fa-users"></i> My Classes
                    </a>
                    <a href="quizzes.php" class="menu-item">
                        <i class="fas fa-question-circle"></i> Quizzes
                    </a>
                    <a href="create-quiz.php" class="menu-item active">
                        <i class="fas fa-plus-circle"></i> Create Quiz
                    </a>
                    <a href="results.php" class="menu-item">
                        <i class="fas fa-chart-bar"></i> Results
                    </a>
                </nav>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <div class="quiz-create-container">
                    <!-- Step Indicator -->
                    <div class="step-indicator">
                        <div class="step <?php echo $step === 1 ? 'active' : ($step > 1 ? 'completed' : ''); ?>">
                            <div class="step-number">1</div>
                            <div>Quiz Details</div>
                        </div>
                        <div class="step <?php echo $step === 2 ? 'active' : ($step > 2 ? 'completed' : ''); ?>">
                            <div class="step-number">2</div>
                            <div>Add Questions</div>
                        </div>
                    </div>

                    <?php if (isset($error)): ?>
                        <div class="alert alert-error" style="margin-bottom: 1rem;">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Step 1: Quiz Details -->
                    <?php if ($step === 1): ?>
                        <form method="POST" action="create-quiz.php">
                            <div class="form-group">
                                <label for="class_id">Class</label>
                                <select id="class_id" name="class_id" required>
                                    <option value="">Select a class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['class_id']; ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="title">Quiz Title</label>
                                <input type="text" id="title" name="title" required>
                            </div>

                            <div class="form-row" style="display: flex; gap: 15px;">
                                <div class="form-group" style="flex: 1;">
                                    <label for="time_limit">Time Limit (minutes)</label>
                                    <input type="number" id="time_limit" name="time_limit" min="1" value="30" required>
                                </div>
                                <div class="form-group" style="flex: 1;">
                                    <label for="question_count">Number of Questions</label>
                                    <input type="number" id="question_count" name="question_count" min="1" max="50" value="10" required>
                                </div>
                            </div>

                            <div class="navigation-buttons">
                                <a href="quizzes.php" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" name="create_quiz" class="btn btn-primary">
                                    Next: Add Questions <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </form>

                        <!-- Step 2: Add Questions -->
                    <?php elseif ($step === 2 && isset($_SESSION['current_quiz'])): ?>
                        <div class="progress-text">
                            Question <?php echo $current_question; ?> of <?php echo $question_count; ?>
                        </div>

                        <form method="POST" action="create-quiz.php">
                            <?php if ($existing_question): ?>
                                <input type="hidden" name="question_id" value="<?php echo $existing_question['question_id']; ?>">
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="question_text">Question <?php echo $current_question; ?> Text</label>
                                <textarea id="question_text" name="question_text" required><?php echo $existing_question ? htmlspecialchars($existing_question['question_text']) : ''; ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="points">Points</label>
                                <input type="number" id="points" name="points" min="1" value="<?php echo $existing_question ? $existing_question['points'] : 1; ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Options</label>
                                <div class="option-header">
                                    <span>Answer Options</span>
                                    <span class="correct-answer-label">Correct Answer</span>
                                    <span class="correct-answer-label">Delete</span>
                                </div>
                                <div id="options-container">
                                    <?php if (!empty($existing_options)): ?>
                                        <?php foreach ($existing_options as $i => $option): ?>
                                            <div class="option-item">
                                                <input type="text" name="options[]" value="<?php echo htmlspecialchars($option['option_text']); ?>" placeholder="Option <?php echo $i + 1; ?>" required>
                                                <label class="custom-checkbox">
                                                    <input type="radio" name="correct_option" value="<?php echo $i; ?>" <?php echo $option['is_correct'] ? 'checked' : ''; ?>>
                                                    <span class="checkbox-mark"></span>
                                                </label>
                                                <button type="button" class="delete-option-btn" onclick="deleteOption(this)" <?php echo count($existing_options) <= 2 ? 'style="visibility: hidden;"' : ''; ?>>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php for ($i = 0; $i < 2; $i++): ?>
                                            <div class="option-item">
                                                <input type="text" name="options[]" placeholder="Option <?php echo $i + 1; ?>" required>
                                                <label class="custom-checkbox">
                                                    <input type="radio" name="correct_option" value="<?php echo $i; ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                                                    <span class="checkbox-mark"></span>
                                                </label>
                                                <button type="button" class="delete-option-btn" onclick="deleteOption(this)" style="visibility: hidden;">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        <?php endfor; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" id="add-option-btn" class="add-option-btn">
                                    <i class="fas fa-plus"></i> Add Another Option
                                </button>
                            </div>

                            <div class="navigation-buttons">
                                <?php if ($current_question > 1): ?>
                                    <a href="create-quiz.php?step=2&prev=1" class="btn btn-outline">
                                        <i class="fas fa-arrow-left"></i> Previous Question
                                    </a>
                                <?php else: ?>
                                    <a href="create-quiz.php?step=1" class="btn btn-outline">
                                        <i class="fas fa-arrow-left"></i> Back to Quiz Details
                                    </a>
                                <?php endif; ?>

                                <?php if ($current_question < $question_count): ?>
                                    <button type="submit" name="add_question" class="btn btn-primary">
                                        Next Question <i class="fas fa-arrow-right"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="submit" name="add_question" class="btn btn-primary">
                                        <i class="fas fa-check"></i> Complete Quiz
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
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

            // Add option button functionality
            document.getElementById('add-option-btn')?.addEventListener('click', function() {
                const optionsContainer = document.getElementById('options-container');
                const optionCount = optionsContainer.querySelectorAll('.option-item').length;

                const newOption = document.createElement('div');
                newOption.className = 'option-item';
                newOption.innerHTML = `
                    <input type="text" name="options[]" placeholder="Option ${optionCount + 1}" required>
                    <label class="custom-checkbox">
                        <input type="radio" name="correct_option" value="${optionCount}">
                        <span class="checkbox-mark"></span>
                    </label>
                    <button type="button" class="delete-option-btn" onclick="deleteOption(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                `;

                optionsContainer.appendChild(newOption);
                updateOptionNumbers();
            });
        });

        // Delete option functionality
        function deleteOption(button) {
            const optionsContainer = document.getElementById('options-container');
            const optionItems = optionsContainer.querySelectorAll('.option-item');

            // Don't allow deletion if only 2 options remain
            if (optionItems.length <= 2) {
                alert('A question must have at least 2 options.');
                return;
            }

            // Remove the option
            button.parentElement.remove();

            // Update option numbers and radio button values
            updateOptionNumbers();
        }

        // Update option numbers and radio button values
        function updateOptionNumbers() {
            const optionsContainer = document.getElementById('options-container');
            const optionItems = optionsContainer.querySelectorAll('.option-item');

            optionItems.forEach((item, index) => {
                const textInput = item.querySelector('input[type="text"]');
                const radioInput = item.querySelector('input[type="radio"]');
                const deleteBtn = item.querySelector('.delete-option-btn');

                textInput.placeholder = `Option ${index + 1}`;
                radioInput.value = index;

                // Hide delete button for first two options
                if (index < 2) {
                    deleteBtn.style.visibility = 'hidden';
                } else {
                    deleteBtn.style.visibility = 'visible';
                }
            });
        }
    </script>
</body>

</html>