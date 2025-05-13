<?php
session_start();

// Include config and utils
require __DIR__ . '/config.php';
require __DIR__ . '/utils.php';

$isUnverified = false;
$userEmail = '';
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT is_activated, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $isUnverified = !$user['is_activated'];
        $userEmail = $user['email'];
    }
}

// Handle resend activation email request
if (isset($_GET['resend']) && $isUnverified && isset($userEmail)) {
    $stmt = $pdo->prepare("SELECT activation_token FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $activationToken = $user['activation_token'];
    $userId = $_SESSION['user_id'];
    $display_name = $_SESSION['display_name'];

    $activationLink = "http://localhost/activate.php?token=$activationToken&id=$userId";
    $htmlBody = "<p>Hello $display_name,</p>
                 <p>Please click the link below to activate your account:</p>
                 <a href='$activationLink'>Activate Account</a>
                 <p>If you did not register, please ignore this email.</p>";
    $textBody = "Hello $display_name,\n\nPlease activate your account by visiting the following link: $activationLink\n\nIf you did not register, please ignore this email.";

    if (sendEmail($userEmail, $display_name, 'Activate Your My Note Account', $htmlBody, $textBody)) {
        $message = 'Activation email resent successfully! Please check your email.';
    }
}

$error = '';
$email_value = '';
$display_name_value = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $display_name = $_POST['display_name'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Store email and display name values to repopulate the form
    $email_value = htmlspecialchars($email);
    $display_name_value = htmlspecialchars($display_name);

    if (empty($email) || empty($display_name) || empty($password) || empty($confirm_password)) {
        // Do nothing since error messages are removed
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords are not matching!';
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                // Do nothing since error messages are removed
            } else {
                // Hash the password using bcrypt explicitly
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $activationToken = bin2hex(random_bytes(32));

                // Insert user into database
                $stmt = $pdo->prepare("INSERT INTO users (email, display_name, password, activation_token, is_activated) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$email, $display_name, $hashedPassword, $activationToken, 0]);
                $userId = $pdo->lastInsertId();

                // Send activation email
                $activationLink = "http://localhost/activate.php?token=$activationToken&id=$userId";
                $htmlBody = "<p>Hello $display_name,</p>
                             <p>Please click the link below to activate your account:</p>
                             <a href='$activationLink'>Activate Account</a>
                             <p>If you did not register, please ignore this email.</p>";
                $textBody = "Hello $display_name,\n\nPlease activate your account by visiting the following link: $activationLink\n\nIf you did not register, please ignore this email.";

                sendEmail($email, $display_name, 'Activate Your My Note Account', $htmlBody, $textBody);

                // Send activation link to WebSocket server
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'http://phpapp:8080/send-activation-link');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'destination' => '/hello',
                    'display_name' => $display_name,
                    'activation_token' => $activationToken,
                    'user_id' => $userId
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    // Silently handle the error since we're removing error message display
                }
                curl_close($ch);

                // Auto-login after registration
                $_SESSION['user_id'] = $userId;
                $_SESSION['display_name'] = $display_name;
                header("Location: /home.php");
                exit();
            }
        } catch (PDOException $e) {
            // Silently handle the error since we're removing error message display
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - My Note</title>
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins';
        }

        body {
            background: #fffffd;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification {
            background-color: #FF4D4D;
            color: #fff;
            padding: 15px;
            text-align: center;
            font-size: 16px;
            width: 100%;
            position: fixed;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .notification a {
            color: #fff;
            text-decoration: underline;
            cursor: pointer;
        }

        .notification a:hover {
            text-decoration: none;
        }

        .container {
            background: #fffdf4;
            width: 90%;
            height: 90%;
            display: flex;
            border-radius: 50px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }

        .left,
        .right {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .left {
            flex-direction: column;
        }

        .left h1 {
            font-size: 40px;
        }

        .right {
            background: #fffffd;
            border-radius: 30px;
            margin: 30px;
            padding: 20px;
            flex-direction: column;
        }

        .register-form {
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
        }

        .register-form h2 {
            font-size: 36px;
            margin: auto;
        }

        .register-form label {
            font-size: 18px;
            margin: 10px 0 5px;
        }

        .password-container {
            position: relative;
            display: flex;
            align-items: center;
        }

        .register-form input {
            padding: 15px;
            border: none;
            background: #dedede;
            border-radius: 30px;
            margin-bottom: 20px;
            font-size: 16px;
            width: 100%;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            cursor: pointer;
            font-size: 16px;
            color: #666;
        }

        button {
            padding: 10px;
            border: none;
            background: #dedede;
            border-radius: 10px;
            cursor: pointer;
            width: 70px;
            align-self: center;
            font-size: 24px;
            font-weight: bold;
        }

        button:hover {
            background: #fffffd;
            font-size: xx-large;
        }

        .links {
            margin-top: 50px;
            font-size: 14px;
            text-align: left;
        }

        .links a {
            color: #3366ff;
            text-decoration: none;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .error-message {
            font-size: 14px;
            color: red;
            text-align: center;
            margin-bottom: 20px;
        }

        .success-message {
            font-size: 14px;
            color: #28a745;
            text-align: center;
            margin-bottom: 20px;
        }

        .loading-spinner {
            display: none;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3366ff;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* RESPONSIVE for mobile */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                height: auto;
                padding: 20px;
                margin-top: 80px;
            }

            .left,
            .right {
                width: 100%;
                margin: 0;
                border-radius: 0;
                padding: 20px 0;
            }

            .left h1 {
                font-size: 32px;
                text-align: center;
            }

            .right {
                padding: 20px;
                margin: 0;
            }

            .register-form {
                max-width: 100%;
            }

            body {
                height: auto;
                padding: 20px 0;
            }
        }
    </style>
</head>
<body>
    <?php if ($isUnverified): ?>
        <div class="notification">
            Your account is unverified. Please check your email to complete the activation process. 
            <a href="/register.php?resend=true">Resend Activation Email</a>
        </div>
    <?php endif; ?>
    <div class="container">
        <div class="left">
            <h1>My Note</h1>
        </div>
        <div class="right">
            <h2>Register</h2>
            <form class="register-form" id="register-form" action="/register.php" method="POST" onsubmit="showLoading()">
                <?php if (!empty($error)): ?>
                    <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
                <?php if (!empty($message)): ?>
                    <p class="success-message"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>
                <label for="email">Email</label>
                <input type="email" name="email" id="email" placeholder="Enter your email" value="<?php echo $email_value; ?>" required>

                <label for="display_name">Display Name</label>
                <input type="text" name="display_name" id="display_name" placeholder="Enter your display name" value="<?php echo $display_name_value; ?>" required>

                <label for="password">Password</label>
                <div class="password-container">
                    <input type="password" name="password" id="password" placeholder="Enter your password" required>
                    <span class="toggle-password" onclick="togglePassword('password')">👁️</span>
                </div>

                <label for="confirm_password">Confirm Password</label>
                <div class="password-container">
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm your password" required>
                    <span class="toggle-password" onclick="togglePassword('confirm_password')">👁️</span>
                </div>

                <button type="submit" class="submit-btn">
                    <span class="arrow">→</span>
                </button>
                <div class="loading-spinner" id="loading-spinner"></div>

                <div class="links">
                    <p>Already have an account? <a href="/login.php">Log In</a></p>
                </div>
            </form>
        </div>
    </div>
    <script>
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const toggleIcon = passwordInput.nextElementSibling;
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.textContent = '👁️‍🗨️';
            } else {
                passwordInput.type = 'password';
                toggleIcon.textContent = '👁️';
            }
        }

        function showLoading() {
            const submitBtn = document.querySelector('.submit-btn');
            const loadingSpinner = document.getElementById('loading-spinner');
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
            loadingSpinner.style.display = 'block';
        }

        // WebSocket client with reconnection logic
        let ws;
        let reconnectionAttempts = 0;
        const maxReconnectionAttempts = 5;
        const reconnectionDelay = 5000;

        function connectWebSocket() {
            ws = new WebSocket('ws://localhost:8080');

            ws.onopen = function() {
                console.log('WebSocket connection established');
                reconnectionAttempts = 0;
            };

            ws.onerror = function(error) {
                console.error('WebSocket error:', error);
            };

            ws.onclose = function() {
                if (reconnectionAttempts < maxReconnectionAttempts) {
                    reconnectionAttempts++;
                    setTimeout(connectWebSocket, reconnectionDelay);
                } else {
                    console.error('Max WebSocket reconnection attempts reached.');
                }
            };
        }

        // Initial WebSocket connection
        connectWebSocket();
    </script>
</body>
</html>