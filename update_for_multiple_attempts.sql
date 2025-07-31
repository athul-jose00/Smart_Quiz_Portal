-- SQL script to update existing Smart Quiz Portal database for multiple attempts support
-- Run this script if you already have the database set up and want to add multiple attempts functionality

USE SmartQuizPortal;

-- Add attempt_number column to responses table if it doesn't exist
ALTER TABLE responses ADD COLUMN IF NOT EXISTS attempt_number INT DEFAULT 1;

-- Add attempt_number column to results table if it doesn't exist  
ALTER TABLE results ADD COLUMN IF NOT EXISTS attempt_number INT DEFAULT 1;

-- Update existing records to have attempt_number = 1 (for backward compatibility)
UPDATE responses SET attempt_number = 1 WHERE attempt_number IS NULL OR attempt_number = 0;
UPDATE results SET attempt_number = 1 WHERE attempt_number IS NULL OR attempt_number = 0;

-- Add indexes for better performance with multiple attempts
CREATE INDEX IF NOT EXISTS idx_responses_attempt ON responses(user_id, quiz_id, attempt_number);
CREATE INDEX IF NOT EXISTS idx_results_attempt ON results(user_id, quiz_id, attempt_number);

-- Verify the changes
SELECT 'Responses table updated' as status, COUNT(*) as total_responses FROM responses;
SELECT 'Results table updated' as status, COUNT(*) as total_results FROM results;

-- Show sample data to verify attempt numbers are set
SELECT 'Sample responses with attempts' as info;
SELECT user_id, quiz_id, attempt_number, COUNT(*) as response_count 
FROM responses 
GROUP BY user_id, quiz_id, attempt_number 
LIMIT 5;

SELECT 'Sample results with attempts' as info;
SELECT user_id, quiz_id, attempt_number, percentage, completed_at 
FROM results 
ORDER BY completed_at DESC 
LIMIT 5;