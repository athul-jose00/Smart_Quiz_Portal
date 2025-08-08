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
}
