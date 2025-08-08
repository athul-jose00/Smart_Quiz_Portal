-- Create AI Conversations Table
-- Run this in phpMyAdmin or your MySQL interface

USE smart_quiz_portal;

CREATE TABLE ai_conversations (
    conversation_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    quiz_id INT NOT NULL,
    question_id INT NOT NULL,
    user_question TEXT NOT NULL,
    ai_response TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(question_id) ON DELETE CASCADE,
    INDEX idx_user_quiz (user_id, quiz_id),
    INDEX idx_question (question_id)
);

-- Verify table creation
DESCRIBE ai_conversations;