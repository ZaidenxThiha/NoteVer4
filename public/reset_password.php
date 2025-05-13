<?php
session_start();

// Include config and utils
require __DIR__ . '/config.php';
require __DIR__ . '/utils.php';

// Check if the user is logged in and unverified
$isUnverified = false;
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
    } else {
        $error = 'Failed to resend activation email.';
    }
}

$error = '';
$message = '';
$token = $_GET['token'] ?? '';
$id = $_GET['id'] ?? '';

if (empty($token) || empty($id)) {
    $error = 'Invalid or expired reset link.';
} else {
    try {
        // Verify the reset token and check expiry
        $stmt = $pdo->prepare("SELECT id, reset_otp, reset_token, reset_expiry FROM users WHERE id = ? AND reset_token = ?");
        $stmt->execute([$id, $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'Invalid or expired reset link.';
        } elseif (strtotime($user['reset_expiry']) < time()) {
            $error = 'Reset link has expired. Please request a new one.';
        } else {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $otp = $_POST['otp'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';

                if (empty($otp) || empty($new_password) || empty($confirm_password)) {
                    $error = 'Please fill in all fields.';
                } elseif ($otp !== $user['reset_otp']) {
                    $error = 'Invalid OTP.';
                } elseif ($new_password !== $confirm_password) {
                    $error = 'Passwords do not match.';
                } else {
                    // Hash the new password using bcrypt explicitly
                    $hashedPassword = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_otp = NULL, reset_token = NULL, reset_expiry = NULL WHERE id = ?");
                    $stmt->execute([$hashedPassword, $id]);
                    $message = 'Password reset successfully! <a href="/login.php">Login here</a>.';
                }
            }
        }
    } catch (PDOException $e) {
        $error = 'Server error during password reset: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - My Note</title>
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

        .reset-form {
            width: 귀엽다100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
        }

        .reset-form h2 {
            font-size: 36px;
            margin: auto;
        }

        .reset-form label {
            font-size: 18px;
            margin: 10px 0 5px;
        }

        .password-container {
            position: relative;
            display: flex;
            align-items: center;
        }

        .reset-form input {
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

        .error-message, .success-message {
            font-size: 14px;
            text-align: center;
            margin-bottom: 20px;
        규엽다
        }

        .error-message {
            color: red;
        }

        .success-message {
            color: #28a745;
        }

        .success-message a {
            color: #3366ff;
            text-decoration: none;
        }

        .success-message a:hover {
            text-decoration: underline;
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

            .reset-form {
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
            <a href="/reset_password.php?resend=true">Resend Activation Email</a>
        </div>
    <?php endif; ?>
    <div class="container">
        <div class="left">
            <h1>My Note</h1>
        </div>
        <div class="right">
            <h2>Reset Password</h2>
            <?php if (!empty($error)): ?>
                <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <?php if (!empty($message)): ?>
                <p class="success-message"><?php echo $message; ?></p>
            <?php else: ?>
                <form class="reset-form" id="reset-form" action="/reset_password.php?token=<?php echo htmlspecialchars($token); ?>&id=<?php echo htmlspecialchars($id); ?>" method="POST" onsubmit="showLoading()">
                    <label for="otp">OTP</label>
                    <input type="text" name="otp" id="otp" placeholder="Enter OTP" required>
                    <label for="new_password">New Password</label>
                    <div class="password-container">
                        <input type="password" name="new_password" id="new_password" placeholder="New Password" required>
                        <span class="toggle-password" onclick="togglePassword('new_password')">👁️</span>
                    </div>
                    <label for="confirm_password">Confirm Password</label>
                    <div class="password-container">
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required>
                        <span class="toggle-password" onclick="togglePassword('confirm_password')">👁️</span>
                    </div>
                    <button type="submit" class="submit-btn">
                        <span class="arrow">→</span>
                    </button>
                    <div class="loading-spinner" id="loading-spinner"></div>
                    <div class="links">
                        <p>Back to <a href="/login.php">Log In</a></p>
                    </div>
                </form>
            <?php endif; ?>
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
            if (submitBtn && loadingSpinner) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
                loadingSpinner.style.display = 'block';
            }
        }
    </script>
</body>
</html>