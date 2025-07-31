<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Smart Quiz Portal</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        
    
        .login-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 40px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .login-title {
            text-align: center;
            margin-bottom: 30px;
            font-size: 2rem;
            background: linear-gradient(90deg, var(--accent), var(--primary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            transition: all 0.3s ease;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .form-control {
            width: 100%;
            padding: 15px 20px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
        }
        
        .password-container {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 70%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
        }
        
        .btn-login {
            width: 100%;
            padding: 15px;
            font-size: 1.1rem;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.4);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 92, 231, 0.6);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .login-footer a {
            color: var(--accent);
            text-decoration: none;
        }
        
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            color: rgba(255, 255, 255, 0.7); /* Semi-transparent white text */
        }

        .remember-me {
            display: flex;
            align-items: center;
        }

        .remember-me input[type="checkbox"] {
            margin-right: 8px;
            -webkit-appearance: none;
            appearance: none;
            width: 18px;
            height: 18px;
            border: 1px solid var(--primary); /* Uses your primary color */
            border-radius: 4px;
            outline: none;
            cursor: pointer;
            position: relative;
            background: rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }

        .remember-me input[type="checkbox"]:checked {
            background-color: var(--primary); /* Primary color when checked */
            border-color: var(--primary);
        }

        .remember-me input[type="checkbox"]:checked::after {
            content: "✓";
            position: absolute;
            color: white;
            font-size: 12px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .remember-me label {
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .remember-me:hover label {
            color: white; /* Brightens text on hover */
        }

        .forgot-password-link {
            color: var(--accent); /* Uses your accent color */
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .forgot-password-link:hover {
            
            text-decoration: underline;
            transition: text-decoration 0.3s ease;
            
        }
        
        .error-message {
            color: var(--danger);
            background: rgba(214, 48, 49, 0.1);
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }

         @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
            }
    </style>
</head>
<body>
    <div class="particles" id="particles-js"></div>
    
    <div class="container">
        <header>
            <div class="logo">
                
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
            <div class="login-container">
                <h2 class="login-title">Welcome Back</h2>
                
                <?php if (isset($_SESSION['login_error'])): ?>
                    <div class="error-message">
                        <?php echo htmlspecialchars($_SESSION['login_error']); ?>
                    </div>
                    <?php unset($_SESSION['login_error']); ?>
                <?php endif; ?>
                
                <form action="process_login.php" method="POST">
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username or email" required
                            value="<?php echo isset($_SESSION['login_username']) ? htmlspecialchars($_SESSION['login_username']) : ''; ?>">
                        <?php unset($_SESSION['login_username']); ?>
                    </div>
                    
                    <div class="form-group password-container">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword()"></i>
                    </div>
                    
                    <div class="remember-forgot">
                        <div class="remember-me">
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember">Remember me</label>
                        </div>
                        <a class="forgot-password-link" href="forgot_password.php">Forgot password?</a>
                    </div>
                    
                    <button type="submit" class="btn-login">Login</button>
                    
                    <div class="login-footer">
                        Don't have an account? <a href="register.php">Register here</a>
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
    // Welcome message based on username or email
    document.getElementById('username').addEventListener('blur', function() {
        const input = this.value.trim();
        const loginTitle = document.querySelector('.login-title');
        
        if (input.length > 0) {  // Only check if there's input
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `get_user_name.php?identifier=${encodeURIComponent(input)}`, true);
            
            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        const response = JSON.parse(this.responseText);
                        if (response.success) {
                            loginTitle.textContent = `Welcome Back, ${response.name}`;
                            loginTitle.style.animation = 'fadeIn 0.5s ease-in-out';
                        } else {
                            // Reset to default if user not found
                            loginTitle.textContent = 'Welcome Back';
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
        } else {
            // Reset to default if input is empty
            loginTitle.textContent = 'Welcome Back';
        }
    });
</script>
<script>
function togglePassword() {
    const passwordInput = document.getElementById("password");
    const toggleIcon = document.querySelector(".toggle-password");

    const isPasswordVisible = passwordInput.type === "text";
    passwordInput.type = isPasswordVisible ? "password" : "text";

    toggleIcon.classList.toggle("fa-eye");
    toggleIcon.classList.toggle("fa-eye-slash");
}
</script>

</body>
</html>