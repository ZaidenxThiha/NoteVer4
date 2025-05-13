<?php
session_start();
require_once 'config.php';
require_once 'utils.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT display_name, is_activated, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header("Location: /login.php");
        exit();
    }

    $display_name = $user['display_name'];
    $activationStatus = $user['is_activated'] ? 'Activated' : 'Not Activated';
    $activationMessage = $user['is_activated'] ? '' : '<p>Please check your email or the registration page for the activation link.</p>';
    $isUnverified = !$user['is_activated'];
    $userEmail = $user['email'];
} catch (PDOException $e) {
    die("Error fetching user data: " . $e->getMessage());
}

// Handle resend activation email request
if (isset($_GET['resend']) && $isUnverified && isset($userEmail)) {
    $stmt = $pdo->prepare("SELECT activation_token FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $activationToken = $user['activation_token'];
    $userId = $_SESSION['user_id'];

    $activationLink = "http://localhost/activate.php?token=$activationToken&id=$userId";
    $htmlBody = "<p>Hello $display_name,</p>
                 <p>Please click the link below to activate your account:</p>
                 <a href='$activationLink'>Activate Account</a>
                 <p>If you did not register, please ignore this email.</p>";
    $textBody = "Hello $display_name,\n\nPlease activate your account by visiting the following link: $activationLink\n\nIf you did not register, please ignore this email.";
    
    if (sendEmail($userEmail, $display_name, 'Activate Your My Note Account', $htmlBody, $textBody)) {
        $message = 'Activation email resent successfully! Please check your email.';
    } else {
        $error = "Failed to resend activation email.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - My Note</title>
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

        .home-container {
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
            text-align: center;
        }

        .home-container h2 {
            font-size: 36px;
            margin: auto;
            margin-bottom: 20px;
        }

        .home-container p {
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
        }

        .home-container a {
            color: #3366ff;
            text-decoration: none;
            font-size: 16px;
        }

        .home-container a:hover {
            text-decoration: underline;
        }

        .success-message {
            font-size: 14px;
            color: #28a745;
            text-align: center;
            margin-bottom: 20px;
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

            .home-container {
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
            <a href="/home.php?resend=true">Resend Activation Email</a>
        </div>
    <?php endif; ?>
    <div class="container">
        <div class="left">
            <h1>My Note</h1>
        </div>
        <div class="right">
            <div class="home-container">
                <h2>Welcome, <?php echo htmlspecialchars($display_name); ?>!</h2>
                <p>Account Status: <?php echo htmlspecialchars($activationStatus); ?></p>
                <?php echo $activationMessage; ?>
                <p>You are now logged in to My Note.</p>
                <?php if (!empty($message)): ?>
                    <p class="success-message"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>
                <a href="/notes_frontend.php">Go to Notes</a><br>
                <a href="/logout.php">Logout</a>
            </div>
        </div>
    </div>
</body>
</html>