<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/gemini_ai.php';
require_once '../config/ai_config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
  echo json_encode(['error' => 'Unauthorized access']);
  exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['error' => 'Method not allowed']);
  exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$question_id = intval($input['question_id'] ?? 0);
$user_question = trim($input['user_question'] ?? '');
$quiz_id = intval($input['quiz_id'] ?? 0);

if (!$question_id || !$user_question || !$quiz_id) {
  echo json_encode(['error' => 'Missing required fields']);
  exit();
}

try {
  // Get question details with student's response
  $question_sql = "SELECT q.question_text, q.quiz_id,
                     o_correct.option_text as correct_answer,
                     o_student.option_text as student_answer,
                     c.class_name as subject
                     FROM questions q
                     LEFT JOIN options o_correct ON q.question_id = o_correct.question_id AND o_correct.is_correct = 1
                     LEFT JOIN responses r ON q.question_id = r.question_id AND r.user_id = ?
                     LEFT JOIN options o_student ON r.selected_option = o_student.option_id
                     LEFT JOIN quizzes qz ON q.quiz_id = qz.quiz_id
                     LEFT JOIN classes c ON qz.class_id = c.class_id
                     WHERE q.question_id = ? AND q.quiz_id = ?";

  $stmt = $conn->prepare($question_sql);
  $stmt->bind_param("iii", $_SESSION['user_id'], $question_id, $quiz_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $question_data = $result->fetch_assoc();

  if (!$question_data) {
    echo json_encode(['error' => 'Question not found or access denied']);
    exit();
  }

  // Initialize Gemini AI with API key from config
  $ai = new GeminiAI(GEMINI_API_KEY);

  // Determine response type based on user question
  $user_question_lower = strtolower($user_question);

  if (
    strpos($user_question_lower, 'explain') !== false ||
    strpos($user_question_lower, 'why') !== false ||
    $user_question === 'Why is my answer wrong?' ||
    $user_question === 'Explain the correct answer'
  ) {

    // Use the explanation method
    $ai_response = $ai->explainQuizQuestion(
      $question_data['question_text'],
      $question_data['correct_answer'],
      $question_data['student_answer'] ?? 'No answer selected',
      $question_data['subject']
    );
  } elseif (
    strpos($user_question_lower, 'example') !== false ||
    $user_question === 'Give me similar examples'
  ) {

    // Use similar examples method
    $ai_response = $ai->getSimilarExamples(
      $question_data['question_text'],
      $question_data['correct_answer'],
      $question_data['subject']
    );
  } elseif (
    strpos($user_question_lower, 'study') !== false ||
    strpos($user_question_lower, 'tip') !== false
  ) {

    // Use study tips method
    $ai_response = $ai->getStudyTips(
      $question_data['question_text'],
      $question_data['subject']
    );
  } else {
    // Use custom question method
    $ai_response = $ai->answerCustomQuestion(
      $question_data['question_text'],
      $question_data['correct_answer'],
      $user_question,
      $question_data['subject']
    );
  }

  // Save conversation to database
  $save_sql = "INSERT INTO ai_conversations (user_id, quiz_id, question_id, user_question, ai_response) 
                 VALUES (?, ?, ?, ?, ?)";
  $save_stmt = $conn->prepare($save_sql);
  $save_stmt->bind_param("iiiss", $_SESSION['user_id'], $quiz_id, $question_id, $user_question, $ai_response);
  $save_stmt->execute();

  echo json_encode([
    'success' => true,
    'response' => $ai_response,
    'timestamp' => date('Y-m-d H:i:s')
  ]);
} catch (Exception $e) {
  error_log("AI Chat Error: " . $e->getMessage());
  echo json_encode(['error' => 'Sorry, I encountered an error. Please try again.']);
}
