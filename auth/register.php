<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Smart Quiz Portal</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
</head>
<body>
    <div class="particles" id="particles-js"></div>
    
    <div class="container">
        <header>
            <div class="logo">
                <!--<img src="images/logo.png" alt="Smart Quiz Portal Logo">-->
                <h1>Smart Quiz Portal</h1>
            </div>
            
            <nav>
                <ul>
                    <li><a href="../index.html">Home</a></li>
                    <li><a href="../index.html#features">Features</a></li>
                    <li><a href="../index.html#how-it-works">How It Works</a></li>
                    <li><a href="../index.html#contact">Contact</a></li>
                </ul>
            </nav>
            
            <div class="auth-buttons">
                <a href="login.php" class="btn btn-outline">Login</a>
                <a href="register.php" class="btn btn-primary">Register</a>
            </div>
        </header>
        
        <main>
            <div class="registration-container">
                <h2 class="registration-title">Create Your Account</h2>
                
                <form action="process_register.php" method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control" placeholder="Enter username" required onblur="checkUsernameAvailability()">
                        <small id="username-feedback" class="form-text"></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control" placeholder="Enter your full name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Create password" required>
                        <small id="password-length-feedback" class="form-text"></small>

                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm password" required>
                        <small id="password-feedback" class="form-text"></small>

                    </div>
                    
                    <div class="role-selection">
                        <div class="role-option">
                            <input type="radio" id="role_student" name="role" value="student" checked>
                            <label for="role_student">
                                <div class="role-icon">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                Student
                            </label>
                        </div>
                        
                        <div class="role-option">
                            <input type="radio" id="role_teacher" name="role" value="teacher">
                            <label for="role_teacher">
                                <div class="role-icon">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                                Teacher
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-register">Register Now</button>
                    
                    <div class="login-link">
                        Already have an account? <a href="login.php">Login here</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
  document.addEventListener("DOMContentLoaded", function () {
    particlesJS("particles-js", {
      "particles": {
        "number": {
          "value": 80,  // Fewer nodes for cleaner neural network look
          "density": {
            "enable": true,
            "value_area": 900
          }
        },
        "color": {
          "value": "#4e73df"  // Blue color resembling neurons
        },
        "shape": {
          "type": "circle",
          "stroke": {
            "width": 0,
            "color": "#000000"
          }
        },
        "opacity": {
          "value": 0.8,
          "random": true,
          "anim": {
            "enable": true,  // Enable pulsing animation
            "speed": 1,
            "opacity_min": 0.2,
            "sync": false
          }
        },
        "size": {
          "value": 4,
          "random": true,
          "anim": {
            "enable": true,  // Size animation for firing effect
            "speed": 2,
            "size_min": 2,
            "sync": false
          }
        },
        "line_linked": {
          "enable": true,
          "distance": 150,  // Connection distance
          "color": "#4e73df",
          "opacity": 0.4,
          "width": 1.5  // Slightly thicker connections
        },
        "move": {
          "enable": true,
          "speed": 1,  // Slower movement
          "direction": "none",
          "random": true,
          "straight": false,
          "out_mode": "bounce",  // Nodes bounce at edges
          "bounce": true,
          "attract": {
            "enable": true,
            "rotateX": 600,
            "rotateY": 1200
          }
        }
      },
      "interactivity": {
        "detect_on": "canvas",
        "events": {
          "onhover": {
            "enable": true,
            "mode": "grab"  // Creates connections on hover
          },
          "onclick": {
            "enable": true,
            "mode": "push"  // Pushes nodes on click
          },
          "resize": true
        },
        "modes": {
          "grab": {
            "distance": 200,
            "line_linked": {
              "opacity": 0.8  // Stronger connections on hover
            }
          },
          "bubble": {
            "distance": 400,
            "size": 40,
            "duration": 2,
            "opacity": 8,
            "speed": 3
          },
          "repulse": {
            "distance": 100,
            "duration": 0.4
          },
          "push": {
            "particles_nb": 4
          },
          "remove": {
            "particles_nb": 2
          }
        }
      },
      "retina_detect": true
    });
  });
</script>


    <script>
function checkUsernameAvailability() {
    const username = document.getElementById('username').value.trim();
    if (username.length === 0) return;
    
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `check_username.php?username=${encodeURIComponent(username)}`, true);
    
    xhr.onload = function() {
        if (this.status === 200) {
            try {
                const response = JSON.parse(this.responseText);
                const usernameFeedback = document.getElementById('username-feedback');
                
                if (response.available) {
                    usernameFeedback.textContent = 'Username is available';
                    usernameFeedback.style.color = 'green';
                } else {
                    usernameFeedback.textContent = response.message;
                    usernameFeedback.style.color = 'red';
                }
            } catch (e) {
                console.error('Error parsing response', e);
            }
        }
    };
    
    xhr.onerror = function() {
        console.error('Request failed');
    };
    
    xhr.send();
}

// Attach the event listener to your username input
document.getElementById('username').addEventListener('blur', checkUsernameAvailability);
</script>
<script>
function checkPasswordMatch() {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const feedback = document.getElementById('password-feedback');

    if (confirmPassword.length === 0) {
        feedback.textContent = '';
        return;
    }

    if (password === confirmPassword) {
        feedback.textContent = 'Passwords match';
        feedback.style.color = 'green';
    } else {
        feedback.textContent = 'Passwords do not match';
        feedback.style.color = 'red';
    }
}

// Add event listeners to both password fields
document.getElementById('password').addEventListener('input', checkPasswordMatch);
document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);
document.getElementById('confirm_password').addEventListener('blur', checkPasswordMatch);
</script>
<script>
function checkPasswordLength() {
    const password = document.getElementById('password').value;
    const lengthFeedback = document.getElementById('password-length-feedback');

    if (password.length === 0) {
        lengthFeedback.textContent = '';
        return;
    }

    if (password.length >= 8) {
        lengthFeedback.textContent = 'Password length is good';
        lengthFeedback.style.color = 'green';
    } else {
        lengthFeedback.textContent = 'Password must be at least 8 characters';
        lengthFeedback.style.color = 'red';
    }
}

function checkPasswordMatch() {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const matchFeedback = document.getElementById('password-feedback');

    if (confirmPassword.length === 0) {
        matchFeedback.textContent = '';
        return;
    }

    if (password === confirmPassword) {
        matchFeedback.textContent = 'Passwords match';
        matchFeedback.style.color = 'green';
    } else {
        matchFeedback.textContent = 'Passwords do not match';
        matchFeedback.style.color = 'red';
    }
}

// Attach both checks
document.getElementById('password').addEventListener('input', () => {
    checkPasswordLength();
    checkPasswordMatch();  // Also update match status in case password is edited
});
document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);
document.getElementById('confirm_password').addEventListener('blur', checkPasswordMatch);
</script>

</body>
</html>