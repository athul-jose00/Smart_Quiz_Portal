<?php
// Test Gemini API Integration
require_once 'includes/gemini_ai.php';
require_once 'config/ai_config.php';

echo "<h2>🤖 Testing Gemini AI Integration</h2>";

try {
  $ai = new GeminiAI(GEMINI_API_KEY);

  echo "<h3>Test 1: Basic Connection</h3>";
  $response = $ai->generateContent("Hello! Can you help students with quiz questions?");
  echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
  echo "<strong>AI Response:</strong><br>" . nl2br(htmlspecialchars($response));
  echo "</div>";

  echo "<h3>Test 2: Quiz Question Explanation</h3>";
  $response = $ai->explainQuizQuestion(
    "What is the capital of France?",
    "Paris",
    "London",
    "Geography"
  );
  echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
  echo "<strong>AI Explanation:</strong><br>" . nl2br(htmlspecialchars($response));
  echo "</div>";

  echo "<h3>✅ Integration Successful!</h3>";
  echo "<p>Your Gemini AI is working correctly. You can now:</p>";
  echo "<ul>";
  echo "<li>✅ Run the SQL file (create_ai_table.sql) in phpMyAdmin</li>";
  echo "<li>✅ Take a quiz and check the results page</li>";
  echo "<li>✅ Click 'Ask AI about this question' on any question</li>";
  echo "<li>✅ Try the quick questions or ask custom questions</li>";
  echo "</ul>";
} catch (Exception $e) {
  echo "<h3>❌ Error Testing API</h3>";
  echo "<div style='background: #ffe8e8; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
  echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
  echo "</div>";
  echo "<p>Please check:</p>";
  echo "<ul>";
  echo "<li>Your API key is correct</li>";
  echo "<li>You have internet connection</li>";
  echo "<li>The Gemini API service is available</li>";
  echo "</ul>";
}
