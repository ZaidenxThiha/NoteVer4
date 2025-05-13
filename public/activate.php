<?php
session_start();
require_once 'config.php';

$message = '';
$token = $_GET['token'] ?? '';
$id = $_GET['id'] ?? '';

if (empty($token) || empty($id)) {
    $message = 'Invalid or expired activation link.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT id, activation_token, is_activated FROM users WHERE id = ? AND activation_token = ?");
        $stmt->execute([$id, $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $message = 'Invalid or expired activation link.';
        } elseif ($user['is_activated']) {
            $message = 'Account already activated. <a href="/login.php">Login here</a>.';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET is_activated = 1, activation_token = NULL WHERE id = ?");
            $stmt->execute([$id]);
            if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $id) {
                // Redirect to notes frontend page if the user is logged in
                header("Location: /notes_frontend.php");
                exit();
            } else {
                $message = 'Account activated successfully! <a href="/login.php">Login here</a>.';
            }
        }
    } catch (PDOException $e) {
        $message = 'Server error during activation: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate Account - My Note</title>
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

        .activate-container {
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
            text-align: center;
        }

        .activate-container h2 {
            font-size: 36px;
            margin: auto;
            margin-bottom: 20px;
        }

        .activate-container p {
            font-size: 14px;
            color: #666;
        }

        .activate-container .error {
            color: red;
        }

        .activate-container a {
            color: #3366ff;
            text-decoration: none;
        }

        .activate-container a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                height: auto;
                padding: 20px;
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

            .activate-container {
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
    <div class="container">
        <div class="left">
            <h1>My Note</h1>
        </div>
        <div class="right">
            <div class="activate-container">
                <h2>Account Activation</h2>
                <p <?php if (strpos($message, 'error') !== false) echo 'class="error"'; ?>>
                    <?php echo $message; ?>
                </p>
            </div>
        </div>
    </div>
</body>
</html>