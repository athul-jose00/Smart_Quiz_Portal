<?php
session_start();
require_once '../../includes/db.p    
                    <div class="quiz-header">
                            <h3 class="quiz-title"><?php echo htmlspecialchars($quiz['title']); ?></h3>
                            <span class="quiz-status <?php echo $quiz['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $quiz['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        
                        <div class="quiz-info">
                            <div class="info-item">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <span>Teacher: <?php echo htmlspecialchars($quiz['teacher_name'] ?: 'Unknown'); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-school"></i>
                                <span>Class: <?php echo htmlspecialchars($quiz['class_name'] ?: 'No class assigned'); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-calendar"></i>
                                <span>Created: <?php echo date('M j, Y', strtotime($quiz['created_at'])); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-clock"></i>
                                <span>Duration: <?php echo $quiz['duration']; ?> minutes</span>
                            </div>
                        </div>
                        
                        <div class="quiz-metrics">
                            <div class="metric-item">
                                <span class="metric-number"><?php echo $quiz['total_attempts'] ?: '0'; ?></span>
                                <span class="metric-label">Attempts</span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-number"><?php echo $quiz['avg_score'] ? round($quiz['avg_score'], 1) . '%' : '0%'; ?></span>
                                <span class="metric-label">Avg Score</span>
                            </div>
                            <div class="metric-item">
                                <span class="metric-number">
                                    <?php 
                                    // Get question count
                                    try {
                                        $q_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM quiz_questions WHERE quiz_id = ?");
                                        $q_stmt->execute([$quiz['id']]);
                                        echo $q_stmt->fetch()['count'];
                                    } catch (PDOException $e) {
                                        echo '0';
                                    }
                                    ?>
                                </span>
                                <span class="metric-label">Questions</span>
                            </div>
                        </div>
                        
                        <div class="quiz-actions">
                            <a href="quiz-details.php?id=<?php echo $quiz['id']; ?>" class="btn-action btn-view">
                                <i class="fas fa-eye"></i>
                                View Details
                            </a>
                            <a href="quiz-results.php?id=<?php echo $quiz['id']; ?>" class="btn-action btn-results">
                                <i class="fas fa-chart-bar"></i>
                                View Results
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <script>
        particlesJS('particles-js', {
            particles: {
                number: { value: 80, density: { enable: true, value_area: 800 } },
                color: { value: '#6c5ce7' },
                shape: { type: 'circle' },
                opacity: { value: 0.5, random: false },
                size: { value: 3, random: true },
                line_linked: { enable: true, distance: 150, color: '#6c5ce7', opacity: 0.4, width: 1 },
                move: { enable: true, speed: 6, direction: 'none', random: false, straight: false, out_mode: 'out', bounce: false }
            },
            interactivity: {
                detect_on: 'canvas',
                events: { onhover: { enable: true, mode: 'repulse' }, onclick: { enable: true, mode: 'push' }, resize: true },
                modes: { grab: { distance: 400, line_linked: { opacity: 1 } }, bubble: { distance: 400, size: 40, duration: 2, opacity: 8, speed: 3 }, repulse: { distance: 200, duration: 0.4 }, push: { particles_nb: 4 }, remove: { particles_nb: 2 } }
            },
            retina_detect: true
        });
    </script>
</body>
</html>