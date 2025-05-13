<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$autoloadFile = 'vendor/autoload.php';
if (!file_exists($autoloadFile)) {
    error_log("Composer autoload file missing: $autoloadFile");
    return ['success' => false, 'error' => 'Email service unavailable: Composer autoload missing'];
}

// Load PHPMailer via Composer autoload
require $autoloadFile;

function sendEmail($to, $toName, $subject, $htmlBody, $textBody) {
    // Validate input parameters
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address provided: $to");
        return ['success' => false, 'error' => 'Invalid email address'];
    }
    if (empty($subject) || empty($htmlBody) || empty($textBody)) {
        error_log("Missing required email parameters: subject=$subject");
        return ['success' => false, 'error' => 'Missing required email parameters'];
    }

    $mail = new PHPMailer(true);
    try {
        // SMTP settings from environment variables with defaults
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'mailhog';
        $mail->Port = getenv('SMTP_PORT') ? (int)getenv('SMTP_PORT') : 1025;
        $mail->SMTPAuth = getenv('SMTP_AUTH') === 'true';
        $mail->Username = getenv('SMTP_USERNAME') ?: '';
        $mail->Password = getenv('SMTP_PASSWORD') ?: '';
        $mail->SMTPSecure = getenv('SMTP_SECURE') === 'false' ? false : (getenv('SMTP_SECURE') ?: '');
        $mail->SMTPAutoTLS = getenv('SMTP_AUTO_TLS') === 'true';

        // Sender and recipient
        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'no-reply@noteapp.com';
        $fromName = getenv('SMTP_FROM_NAME') ?: 'My Note';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to, $toName ?: '');

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        // Send email
        $mail->send();
        error_log("Email sent successfully to $to for subject: $subject");
        return ['success' => true];
    } catch (Exception $e) {
        $errorMessage = "Failed to send email to $to: {$mail->ErrorInfo}";
        error_log($errorMessage);
        return ['success' => false, 'error' => 'Failed to send email: ' . $e->getMessage()];
    }
}
?>