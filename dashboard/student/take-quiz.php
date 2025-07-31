<?php
session_start();
require_once '../../includes/db.php';

// Make sure the user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$quiz_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$quiz_id) {
    header("Location: quizzes.php");
    exit();
}

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

// Get the next attempt number for this student and quiz
$attempt_sql = "SELECT COALESCE(MAX(attempt_number), 0) + 1 as next_attempt FROM results WHERE user_id = ? AND quiz_id = ?";
$attempt_stmt = $conn->prepare($attempt_sql);
$attempt_stmt->bind_param("ii", $user_id, $quiz_id);
$attempt_stmt->execute();
$attempt_result = $attempt_stmt->get_result();
$attempt_row = $attempt_result->fetch_assoc();
$next_attempt = $attempt_row['next_attempt'];
$attempt_stmt->close();

// Get previous attempts count for display
$prev_attempts_sql = "SELECT COUNT(*) as attempt_count FROM results WHERE user_id = ? AND quiz_id = ?";
$prev_stmt = $conn->prepare($prev_attempts_sql);
$prev_stmt->bind_param("ii", $user_id, $quiz_id);
$prev_stmt->execute();
$prev_result = $prev_stmt->get_result();
$prev_row = $prev_result->fetch_assoc();
$previous_attempts = $prev_row['attempt_count'];
$prev_stmt->close();

// Get quiz details and verify student is enrolled in the class
$quiz_sql = "SELECT q.quiz_id, q.title, q.time_limit, q.created_at,
             c.class_id, c.class_name, c.class_code, u.name as teacher_name,
             (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id = q.quiz_id) as question_count
             FROM quizzes q
             JOIN classes c ON q.class_id = c.class_id
             JOIN users u ON c.teacher_id = u.user_id
             JOIN user_classes uc ON c.class_id = uc.class_id
             WHERE q.quiz_id = ? AND uc.user_id = ?";
$quiz_stmt = $conn->prepare($quiz_sql);
$quiz_stmt->bind_param("ii", $quiz_id, $user_id);
$quiz_stmt->execute();
$quiz_result = $quiz_stmt->get_result();
$quiz = $quiz_result->fetch_assoc();
$quiz_stmt->close();

if (!$quiz) {
    header("Location: quizzes.php?error=Quiz not found or access denied");
    exit();
}

// Get quiz questions with options and randomize them
$questions_sql = "SELECT q.question_id, q.question_text, q.points,
                  o.option_id, o.option_text, o.is_correct
                  FROM questions q
                  LEFT JOIN options o ON q.question_id = o.question_id
                  WHERE q.quiz_id = ?
                  ORDER BY RAND(), q.question_id, RAND()";
$questions_stmt = $conn->prepare($questions_sql);
$questions_stmt->bind_param("i", $quiz_id);
$questions_stmt->execute();
$questions_result = $questions_stmt->get_result();

$questions = [];
while ($row = $questions_result->fetch_assoc()) {
    $question_id = $row['question_id'];
    if (!isset($questions[$question_id])) {
        $questions[$question_id] = [
            'question_id' => $question_id,
            'question_text' => $row['question_text'],
            'points' => $row['points'],
            'options' => []
        ];
    }
    if ($row['option_id']) {
        $questions[$question_id]['options'][] = [
            'option_id' => $row['option_id'],
            'option_text' => $row['option_text'],
            'is_correct' => $row['is_correct']
        ];
    }
}
$questions_stmt->close();

// Randomize questions order
$questions_array = array_values($questions);
shuffle($questions_array);

// Randomize options within each question
foreach ($questions_array as &$question) {
    shuffle($question['options']);
}
unset($question);

// Convert back to associative array for easier access
$questions = [];
foreach ($questions_array as $index => $question) {
    $questions[$index + 1] = $question;
}

if (empty($questions)) {
    header("Location: quizzes.php?error=No questions found for this quiz");
    exit();
}

// Handle quiz submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $total_score = 0;
    $total_points = 0;
    $responses = [];

    foreach ($questions as $question) {
        $question_id = $question['question_id'];
        $total_points += $question['points'];

        if (isset($_POST['question_' . $question_id])) {
            $selected_option = intval($_POST['question_' . $question_id]);

            // Find the correct answer and check if selected option is correct
            $is_correct = false;
            foreach ($question['options'] as $option) {
                if ($option['option_id'] == $selected_option && $option['is_correct']) {
                    $is_correct = true;
                    $total_score += $question['points'];
                    break;
                }
            }

            // Store response with attempt number
            $response_sql = "INSERT INTO responses (user_id, quiz_id, question_id, selected_option, attempt_number) VALUES (?, ?, ?, ?, ?)";
            $response_stmt = $conn->prepare($response_sql);
            $response_stmt->bind_param("iiiii", $user_id, $quiz_id, $question_id, $selected_option, $next_attempt);
            $response_stmt->execute();
            $response_stmt->close();
        }
    }

    // Calculate percentage
    $percentage = $total_points > 0 ? round(($total_score / $total_points) * 100, 2) : 0;

    // Store result with attempt number
    $result_sql = "INSERT INTO results (user_id, quiz_id, total_score, percentage, attempt_number) VALUES (?, ?, ?, ?, ?)";
    $result_stmt = $conn->prepare($result_sql);
    $result_stmt->bind_param("iiidi", $user_id, $quiz_id, $total_score, $percentage, $next_attempt);
    $result_stmt->execute();
    $result_stmt->close();

    // Redirect to results page
    header("Location: quiz-result.php?id=" . $quiz_id);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($quiz['title']); ?> | Smart Quiz Portal</title>
    <link rel="stylesheet" href="../../css/style.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            color: white;
        }

        .quiz-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
        }

        .quiz-header {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }

        .quiz-title {
            font-size: 2rem;
            margin-bottom: 1rem;
            background: linear-gradient(90deg, var(--accent), var(--primary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }

        .quiz-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.8);
        }

        .timer-container {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid rgba(231, 76, 60, 0.3);
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            margin-bottom: 1rem;
        }

        .timer {
            font-size: 2rem;
            font-weight: bold;
            color: #e74c3c;
            margin-bottom: 0.5rem;
        }

        .timer-label {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
        }

        .progress-bar {
            background: rgba(255, 255, 255, 0.1);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .progress-fill {
            background: linear-gradient(90deg, var(--primary), var(--accent));
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
        }

        .question-container {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .question-number {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .question-points {
            color: var(--accent);
            font-weight: 600;
        }

        .question-text {
            font-size: 1.2rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            color: rgba(255, 255, 255, 0.95);
        }

        .options-container {
            display: grid;
            gap: 1rem;
        }

        .option {
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .option:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(108, 92, 231, 0.5);
        }

        .option.selected {
            background: rgba(108, 92, 231, 0.2);
            border-color: var(--primary);
        }

        .option input[type="radio"] {
            width: 20px;
            height: 20px;
            accent-color: var(--primary);
        }

        .option-text {
            flex: 1;
            font-size: 1rem;
            line-height: 1.4;
        }

        .navigation-container {
            position: sticky;
            bottom: 2rem;
            background: rgba(26, 26, 46, 0.95);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .nav-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-success {
            background: #2ecc71;
            color: white;
        }

        .btn-success:hover {
            background: #27ae60;
            transform: translateY(-2px);
        }

        .question-nav {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .question-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .question-dot.answered {
            background: var(--primary);
        }

        .question-dot.current {
            background: var(--accent);
            transform: scale(1.2);
        }

        .warning-message {
            background: rgba(241, 196, 15, 0.2);
            border: 1px solid rgba(241, 196, 15, 0.3);
            color: #f1c40f;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: none;
        }

        .hidden {
            display: none;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .quiz-container {
                padding: 1rem;
            }

            .quiz-info {
                grid-template-columns: 1fr;
            }

            .navigation-container {
                flex-direction: column;
                gap: 1rem;
            }

            .question-nav {
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="particles" id="particles-js"></div>

    <div class="quiz-container">
        <!-- Quiz Header -->
        <div class="quiz-header">
            <h1 class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></h1>

            <div class="quiz-info">
                <div class="info-item">
                    <i class="fas fa-book"></i>
                    <span><?php echo htmlspecialchars($quiz['class_name']); ?></span>
                </div>
                <div class="info-item">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span><?php echo htmlspecialchars($quiz['teacher_name']); ?></span>
                </div>
                <div class="info-item">
                    <i class="fas fa-question-circle"></i>
                    <span><?php echo count($questions); ?> Questions</span>
                </div>
                <div class="info-item">
                    <i class="fas fa-clock"></i>
                    <span><?php echo $quiz['time_limit']; ?> Minutes</span>
                </div>
                <div class="info-item">
                    <i class="fas fa-redo"></i>
                    <span>Attempt <?php echo $next_attempt; ?><?php echo $previous_attempts > 0 ? " (Previous: $previous_attempts)" : ""; ?></span>
                </div>
            </div>

            <div class="timer-container">
                <div class="timer" id="timer"><?php echo $quiz['time_limit']; ?>:00</div>
                <div class="timer-label">Time Remaining</div>
            </div>

            <div class="progress-bar">
                <div class="progress-fill" id="progressBar"></div>
            </div>
        </div>

        <!-- Warning Message -->
        <div class="warning-message" id="warningMessage">
            <i class="fas fa-exclamation-triangle"></i>
            Please answer all questions before submitting the quiz.
        </div>

        <!-- Quiz Form -->
        <form id="quizForm" method="POST" action="">
            <?php $question_index = 0;
            foreach ($questions as $question): $question_index++; ?>
                <div class="question-container" data-question="<?php echo $question_index; ?>">
                    <div class="question-header">
                        <span class="question-number">Question <?php echo $question_index; ?></span>
                        <span class="question-points"><?php echo $question['points']; ?> point<?php echo $question['points'] > 1 ? 's' : ''; ?></span>
                    </div>

                    <div class="question-text">
                        <?php echo htmlspecialchars($question['question_text']); ?>
                    </div>

                    <div class="options-container">
                        <?php foreach ($question['options'] as $option): ?>
                            <label class="option" for="option_<?php echo $option['option_id']; ?>">
                                <input
                                    type="radio"
                                    id="option_<?php echo $option['option_id']; ?>"
                                    name="question_<?php echo $question['question_id']; ?>"
                                    value="<?php echo $option['option_id']; ?>"
                                    onchange="updateProgress()">
                                <span class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <input type="hidden" name="submit_quiz" value="1">
        </form>

        <!-- Navigation -->
        <div class="navigation-container">
            <button type="button" class="nav-btn btn-secondary" id="prevBtn" onclick="previousQuestion()">
                <i class="fas fa-chevron-left"></i> Previous
            </button>

            <div class="question-nav" id="questionNav">
                <?php for ($i = 1; $i <= count($questions); $i++): ?>
                    <div class="question-dot" data-question="<?php echo $i; ?>" onclick="goToQuestion(<?php echo $i; ?>)"></div>
                <?php endfor; ?>
            </div>

            <button type="button" class="nav-btn btn-primary" id="nextBtn" onclick="nextQuestion()">
                Next <i class="fas fa-chevron-right"></i>
            </button>

            <button type="button" class="nav-btn btn-success hidden" id="submitBtn" onclick="submitQuiz()">
                <i class="fas fa-check"></i> Submit Quiz
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Quiz variables
        let currentQuestion = 1;
        const totalQuestions = <?php echo count($questions); ?>;
        const timeLimit = <?php echo $quiz['time_limit']; ?> * 60; // Convert to seconds
        let timeRemaining = timeLimit;
        let timerInterval;

        // Initialize particles
        document.addEventListener("DOMContentLoaded", function() {
            particlesJS("particles-js", {
                particles: {
                    number: {
                        value: 50,
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
                        value: 0.3
                    },
                    size: {
                        value: 3,
                        random: true
                    },
                    line_linked: {
                        enable: true,
                        distance: 150,
                        color: "#ffffff",
                        opacity: 0.2,
                        width: 1,
                    },
                    move: {
                        enable: true,
                        speed: 1,
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

            // Initialize quiz
            showQuestion(1);
            startTimer();
            updateProgress();
        });

        // Timer functions
        function startTimer() {
            timerInterval = setInterval(function() {
                timeRemaining--;
                updateTimerDisplay();

                if (timeRemaining <= 0) {
                    clearInterval(timerInterval);
                    autoSubmitQuiz();
                }
            }, 1000);
        }

        function updateTimerDisplay() {
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            const display = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
            document.getElementById('timer').textContent = display;

            // Change color when time is running low
            if (timeRemaining <= 300) { // 5 minutes
                document.getElementById('timer').style.color = '#e74c3c';
            }
        }

        // Navigation functions
        function showQuestion(questionNum) {
            // Hide all questions
            const questions = document.querySelectorAll('.question-container');
            questions.forEach(q => q.classList.add('hidden'));

            // Show current question
            const currentQ = document.querySelector(`[data-question="${questionNum}"]`);
            if (currentQ) {
                currentQ.classList.remove('hidden');
            }

            // Update navigation
            updateNavigation();
            updateQuestionDots();
        }

        function updateNavigation() {
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const submitBtn = document.getElementById('submitBtn');

            prevBtn.style.display = currentQuestion === 1 ? 'none' : 'flex';

            if (currentQuestion === totalQuestions) {
                nextBtn.classList.add('hidden');
                submitBtn.classList.remove('hidden');
            } else {
                nextBtn.classList.remove('hidden');
                submitBtn.classList.add('hidden');
            }
        }

        function updateQuestionDots() {
            const dots = document.querySelectorAll('.question-dot');
            dots.forEach((dot, index) => {
                dot.classList.remove('current');
                if (index + 1 === currentQuestion) {
                    dot.classList.add('current');
                }
            });
        }

        function nextQuestion() {
            if (currentQuestion < totalQuestions) {
                currentQuestion++;
                showQuestion(currentQuestion);
            }
        }

        function previousQuestion() {
            if (currentQuestion > 1) {
                currentQuestion--;
                showQuestion(currentQuestion);
            }
        }

        function goToQuestion(questionNum) {
            currentQuestion = questionNum;
            showQuestion(currentQuestion);
        }

        // Progress tracking
        function updateProgress() {
            const answeredQuestions = document.querySelectorAll('input[type="radio"]:checked').length;
            const progress = (answeredQuestions / totalQuestions) * 100;
            document.getElementById('progressBar').style.width = progress + '%';

            // Update question dots
            const dots = document.querySelectorAll('.question-dot');
            dots.forEach((dot, index) => {
                const questionNum = index + 1;
                const isAnswered = document.querySelector(`input[name="question_${getQuestionId(questionNum)}"]:checked`);
                if (isAnswered) {
                    dot.classList.add('answered');
                } else {
                    dot.classList.remove('answered');
                }
            });
        }

        function getQuestionId(questionNum) {
            const questionIds = <?php echo json_encode(array_column($questions, 'question_id')); ?>;
            return questionIds[questionNum - 1];
        }

        // Quiz submission
        function submitQuiz() {
            const answeredQuestions = document.querySelectorAll('input[type="radio"]:checked').length;

            if (answeredQuestions < totalQuestions) {
                document.getElementById('warningMessage').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('warningMessage').style.display = 'none';
                }, 5000);
                return;
            }

            if (confirm('Are you sure you want to submit your quiz? You cannot change your answers after submission.')) {
                clearInterval(timerInterval);
                document.getElementById('quizForm').submit();
            }
        }

        function autoSubmitQuiz() {
            alert('Time is up! Your quiz will be submitted automatically.');
            document.getElementById('quizForm').submit();
        }

        // Prevent accidental page refresh
        window.addEventListener('beforeunload', function(e) {
            if (timeRemaining > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Handle option selection styling
        document.addEventListener('change', function(e) {
            if (e.target.type === 'radio') {
                // Remove selected class from all options in this question
                const questionContainer = e.target.closest('.question-container');
                const options = questionContainer.querySelectorAll('.option');
                options.forEach(option => option.classList.remove('selected'));

                // Add selected class to chosen option
                e.target.closest('.option').classList.add('selected');
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>