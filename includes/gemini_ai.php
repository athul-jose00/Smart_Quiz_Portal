<?php
class GeminiAI
{
  private $api_key;
  private $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

  public function __construct($api_key)
  {
    $this->api_key = $api_key;
  }

  public function generateContent($prompt)
  {
    $data = [
      'contents' => [
        [
          'parts' => [
            ['text' => $prompt]
          ]
        ]
      ],
      'generationConfig' => [
        'temperature' => 0.7,
        'topK' => 40,
        'topP' => 0.95,
        'maxOutputTokens' => 1024,
      ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->api_url . '?key=' . $this->api_key);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
      $decoded = json_decode($response, true);
      return $decoded['candidates'][0]['content']['parts'][0]['text'] ?? 'No response generated';
    } else {
      $error = json_decode($response, true);
      return 'Error: ' . ($error['error']['message'] ?? 'Unknown error');
    }
  }

  public function explainQuizQuestion($question_text, $correct_answer, $student_answer, $subject = '')
  {
    $prompt = "You are a helpful and encouraging tutor. A student needs help understanding a quiz question.

Subject: {$subject}

Question: {$question_text}

Correct Answer: {$correct_answer}
Student's Answer: {$student_answer}

Please:
1. Explain why the correct answer is right
2. If the student's answer was wrong, gently explain why
3. Provide additional context or examples to help understanding
4. Be encouraging and supportive

Keep your explanation clear, concise, and educational.";

    return $this->generateContent($prompt);
  }

  public function answerCustomQuestion($question_text, $correct_answer, $user_question, $subject = '')
  {
    $prompt = "You are a helpful tutor. A student is asking about this quiz question:

Subject: {$subject}
Question: {$question_text}
Correct Answer: {$correct_answer}

Student's Question: {$user_question}

Please provide a helpful, educational answer that relates to the quiz question. Be clear and encouraging.";

    return $this->generateContent($prompt);
  }

  public function getStudyTips($question_text, $subject = '')
  {
    $prompt = "Based on this quiz question: '{$question_text}' in the subject '{$subject}', provide 3-4 study tips or related concepts the student should focus on to better understand this topic. Be specific and actionable.";

    return $this->generateContent($prompt);
  }

  public function getSimilarExamples($question_text, $correct_answer, $subject = '')
  {
    $prompt = "Based on this quiz question: '{$question_text}' with correct answer: '{$correct_answer}' in subject '{$subject}', provide 2-3 similar example questions or scenarios that would help the student practice this concept.";

    return $this->generateContent($prompt);
  }

  public function analyzeOverallQuizPerformance($analysis_data)
  {
    $percentage = $analysis_data['percentage'];
    $grade = $analysis_data['grade'];
    $correct_answers = $analysis_data['correct_answers'];
    $total_questions = $analysis_data['total_questions'];
    $class_average = $analysis_data['class_average'];
    $quiz_title = $analysis_data['quiz_title'];
    $attempt_number = $analysis_data['attempt_number'];
    $total_attempts = $analysis_data['total_attempts'];

    // Determine if this is a perfect score
    $is_perfect = ($percentage == 100);

    // Determine performance level
    $performance_level = '';
    if ($percentage >= 90) {
      $performance_level = 'excellent';
    } elseif ($percentage >= 80) {
      $performance_level = 'very good';
    } elseif ($percentage >= 70) {
      $performance_level = 'good';
    } elseif ($percentage >= 60) {
      $performance_level = 'satisfactory';
    } else {
      $performance_level = 'needs improvement';
    }

    // Compare to class average
    $comparison = '';
    if ($percentage > $class_average) {
      $comparison = 'above the class average';
    } elseif ($percentage == $class_average) {
      $comparison = 'at the class average';
    } else {
      $comparison = 'below the class average';
    }

    $prompt = "You are an encouraging and insightful AI tutor providing personalized feedback on a student's quiz performance.

Quiz Details:
- Quiz: {$quiz_title}
- Student Score: {$percentage}% (Grade: {$grade})
- Questions Correct: {$correct_answers} out of {$total_questions}
- Class Average: {$class_average}%
- Performance Level: {$performance_level}
- Comparison: {$comparison}
- Attempt: {$attempt_number} of {$total_attempts}

Please provide a comprehensive analysis that includes:

1. **Overall Performance Assessment**: Start with either congratulations (if perfect score) or acknowledgment of their effort
2. **Strengths**: What they did well
3. **Areas for Improvement**: Specific areas to focus on (if not perfect score)
4. **Comparison Context**: How they performed relative to the class
5. **Next Steps**: Actionable recommendations for continued learning
6. **Encouragement**: Motivational closing remarks

Guidelines:
- If they got 100%, focus heavily on congratulations and encouragement to maintain excellence
- If they scored below 100%, be constructive and supportive, focusing on growth opportunities
- Be specific and actionable in your recommendations
- Keep the tone positive and motivating
- Use bullet points or clear sections for readability
- Limit response to about 200-300 words

Make this personal and encouraging while being educationally valuable.";

    return $this->generateContent($prompt);
  }
}
