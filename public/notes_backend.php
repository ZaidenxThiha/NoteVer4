<?php
// Disable all error display
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 0);

// Start output buffering
ob_start();

try {
    session_start();

    require_once 'config.php';
    header('Content-Type: application/json');

    // Check if user is authenticated
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false]);
        ob_end_flush();
        exit();
    }

    $userId = $_SESSION['user_id'];

    // Initialize session array for verified passwords
    if (!isset($_SESSION['verified_notes'])) {
        $_SESSION['verified_notes'] = [];
    }

    // Fetch user details
    try {
        $stmt = $pdo->prepare("SELECT display_name, is_activated FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $username = $user ? htmlspecialchars($user['display_name']) : 'Unknown User';
        $isActivated = $user && $user['is_activated'] ? 'Activated' : 'Not Activated';
    } catch (PDOException $e) {
        $username = 'Unknown User';
        $isActivated = 'Error';
    }

    // Check if a note is accessible (owner or shared user)
    function isNoteAccessible($pdo, $noteId, $userId) {
        try {
            $stmt = $pdo->prepare("
                SELECT n.password_hash, n.user_id, ns.recipient_user_id 
                FROM notes n 
                LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.recipient_user_id = :user_id 
                WHERE n.id = :note_id
            ");
            $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$note) {
                return false; // Note doesn't exist
            }
            if ($note['user_id'] != $userId && !$note['recipient_user_id']) {
                return false; // User is neither owner nor shared recipient
            }
            if ($note['password_hash'] === null) {
                return true; // No password protection
            }
            return isset($_SESSION['verified_notes'][$noteId]) && $_SESSION['verified_notes'][$noteId] === true;
        } catch (PDOException $e) {
            return false;
        }
    }

    // Handle image upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;

        if (!$noteId) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        if (!isset($_FILES['image']) || empty($_FILES['image']['name']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            // Check if note is accessible
            if (!isNoteAccessible($pdo, $noteId, $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            // Check if user owns the note or has edit permission
            $stmt = $pdo->prepare("SELECT n.id, n.user_id, ns.permission FROM notes n LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.recipient_user_id = :user_id WHERE n.id = :note_id");
            $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result || ($result['user_id'] != $userId && $result['permission'] != 'edit')) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            // Use absolute path
            $uploadDir = '/var/www/html/public/uploads/';

            // Create directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0775, true)) {
                    http_response_code(500);
                    echo json_encode(['success' => false]);
                    ob_end_flush();
                    exit();
                }
                chown($uploadDir, 'www-data');
                chgrp($uploadDir, 'www-data');
                chmod($uploadDir, 0775);
            }

            if (!is_writable($uploadDir)) {
                http_response_code(500);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            // Validate and process the uploaded file
            $file = $_FILES['image'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            // Validate file size
            $maxFileSize = 5 * 1024 * 1024; // 5MB
            if ($file['size'] > $maxFileSize) {
                http_response_code(400);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            // Validate file type
            if (!function_exists('finfo_open')) {
                http_response_code(500);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (!$finfo) {
                http_response_code(500);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($mimeType, $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            // Generate unique filename
            $fileName = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', basename($file['name']));
            $filePath = $uploadDir . $fileName;
            $relativePath = '/public/uploads/' . $fileName;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                http_response_code(500);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            // Set file permissions
            if (!chmod($filePath, 0644)) {
                http_response_code(500);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            // Insert into database
            $stmt = $pdo->prepare("INSERT INTO note_images (note_id, image_path) VALUES (:note_id, :image_path)");
            $stmt->execute(['note_id' => $noteId, 'image_path' => $relativePath]);
            echo json_encode(['success' => true, 'image_path' => $relativePath]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Load utils.php for actions requiring sendEmail
    require_once 'utils.php';

    // Send sharing email
    function sendSharingEmail($pdo, $recipientEmail, $noteId, $noteTitle, $permission) {
        $htmlBody = "A note titled '$noteTitle' has been shared with you (Permission: $permission). <a href='http://localhost/notes_frontend.php?action=edit&id=$noteId'>View Note</a>";
        $textBody = "A note titled '$noteTitle' has been shared with you (Permission: $permission). View it at: http://localhost/notes_frontend.php?action=edit&id=$noteId";
        $result = sendEmail($recipientEmail, '', 'Note Shared with You', $htmlBody, $textBody);
        return $result['success'];
    }

    // Handle set password
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
        $password = trim($_POST['password'] ?? '');

        if (!$noteId || empty($password)) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            // Verify user owns the note
            $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE notes SET password_hash = :password_hash WHERE id = :note_id");
            $stmt->execute(['password_hash' => $passwordHash, 'note_id' => $noteId]);
            $_SESSION['verified_notes'][$noteId] = true;
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle remove password
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_password'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;

        if (!$noteId) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            // Verify user owns the note
            $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $stmt = $pdo->prepare("UPDATE notes SET password_hash = NULL WHERE id = :note_id");
            $stmt->execute(['note_id' => $noteId]);
            unset($_SESSION['verified_notes'][$noteId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle verify password
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_password'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
        $password = trim($_POST['password'] ?? '');

        if (!$noteId || empty($password)) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT password_hash FROM notes WHERE id = :note_id");
            $stmt->execute(['note_id' => $noteId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$note) {
                http_response_code(404);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            if ($note['password_hash'] === null || password_verify($password, $note['password_hash'])) {
                $_SESSION['verified_notes'][$noteId] = true;
                echo json_encode(['success' => true]);
            } else {
                http_response_code(403);
                echo json_encode(['success' => false]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle relock
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['relock'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;

        if (!$noteId) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT password_hash FROM notes WHERE id = :note_id AND user_id = :user_id");
            $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$note || $note['password_hash'] === null) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            unset($_SESSION['verified_notes'][$noteId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle save (autosave via JSON POST ?save=1&id=:id or /notes/:id)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_GET['save']) && $_GET['save'] == 1 || preg_match('#^/notes/(\d+)$#', $_SERVER['REQUEST_URI'], $matches))) {
        $noteId = isset($matches[1]) ? (int)$matches[1] : (filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $labelNames = isset($input['labels']) ? array_filter(array_map('trim', explode(',', $input['labels']))) : [];
        $updatedAt = isset($input['updated_at']) ? trim($input['updated_at']) : date('c');

        if (empty($content)) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $updatedAtDate = new DateTime($updatedAt);
            $mysqlUpdatedAt = $updatedAtDate->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $pdo->beginTransaction();

            if ($noteId) {
                if (!isNoteAccessible($pdo, $noteId, $userId)) {
                    http_response_code(403);
                    echo json_encode(['success' => false]);
                    ob_end_flush();
                    exit();
                }

                $stmt = $pdo->prepare("SELECT n.id, n.user_id, ns.permission FROM notes n LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.recipient_user_id = :user_id WHERE n.id = :note_id");
                $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$result || ($result['user_id'] != $userId && $result['permission'] != 'edit')) {
                    http_response_code(403);
                    echo json_encode(['success' => false]);
                    ob_end_flush();
                    exit();
                }

                $stmt = $pdo->prepare("UPDATE notes SET title = :title, content = :content, updated_at = :updated_at WHERE id = :id");
                $stmt->execute(['title' => $title, 'content' => $content, 'updated_at' => $mysqlUpdatedAt, 'id' => $noteId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content, created_at, updated_at, is_trashed) VALUES (:user_id, :title, :content, NOW(), :updated_at, 0)");
                $stmt->execute(['user_id' => $userId, 'title' => $title, 'content' => $content, 'updated_at' => $mysqlUpdatedAt]);
                $noteId = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("DELETE FROM note_labels WHERE note_id = :note_id");
            $stmt->execute(['note_id' => $noteId]);
            if (!empty($labelNames)) {
                $labelIds = [];
                foreach ($labelNames as $labelName) {
                    if (!empty($labelName)) {
                        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
                        $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
                        $label = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($label) {
                            $labelIds[] = $label['id'];
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO labels (user_id, name) VALUES (:user_id, :name)");
                            $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
                            $labelIds[] = $pdo->lastInsertId();
                        }
                    }
                }
                if (!empty($labelIds)) {
                    $placeholders = implode(',', array_fill(0, count($labelIds), '(?, ?)'));
                    $values = [];
                    foreach ($labelIds as $labelId) {
                        $values[] = $noteId;
                        $values[] = $labelId;
                    }
                    $stmt = $pdo->prepare("INSERT IGNORE INTO note_labels (note_id, label_id) VALUES $placeholders");
                    $stmt->execute($values);
                }
            }

            $pdo->commit();
            echo json_encode(['id' => $noteId, 'success' => true, 'updated_at' => $mysqlUpdatedAt]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle rename label
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_label'])) {
        $oldName = trim($_POST['old_name'] ?? '');
        $newName = trim($_POST['new_name'] ?? '');
        if (empty($oldName) || empty($newName)) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
            $stmt->execute(['user_id' => $userId, 'name' => $oldName]);
            $label = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$label) {
                http_response_code(404);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
            $stmt->execute(['user_id' => $userId, 'name' => $newName]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $stmt = $pdo->prepare("UPDATE labels SET name = :new_name WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['new_name' => $newName, 'id' => $label['id'], 'user_id' => $userId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle delete label
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_label'])) {
        $labelName = trim($_POST['label_name'] ?? '');
        if (empty($labelName)) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
            $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
            $label = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$label) {
                http_response_code(404);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM note_labels WHERE label_id = :label_id");
            $stmt->execute(['label_id' => $label['id']]);
            $stmt = $pdo->prepare("DELETE FROM labels WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $label['id'], 'user_id' => $userId]);
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle pin/unpin
    if (isset($_GET['pin']) && isset($_GET['id'])) {
        $isPinned = filter_input(INPUT_GET, 'pin', FILTER_VALIDATE_INT) === 1 ? 1 : 0;
        $noteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $pinnedAt = $isPinned ? date('Y-m-d H:i:s') : null;

        if (!$noteId) {
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE notes SET is_pinned = :pinned, pinned_at = :pinned_at WHERE id = :id AND user_id = :user_id");
            $stmt->bindValue(':pinned', $isPinned, PDO::PARAM_INT);
            $stmt->bindValue(':pinned_at', $pinnedAt, $pinnedAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':id', $noteId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle note sharing
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_note'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
        $emails = isset($_POST['emails']) ? array_filter(array_map('trim', explode(',', $_POST['emails']))) : [];
        $permission = filter_input(INPUT_POST, 'permission', FILTER_SANITIZE_STRING);

        if (!$noteId || empty($emails) || !in_array($permission, ['read', 'edit'])) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id, title FROM notes WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$note) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $successCount = 0;
            $failedEmails = [];
            foreach ($emails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $failedEmails[] = $email;
                    continue;
                }
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $stmt->execute(['email' => $email]);
                $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($recipient) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO note_shares (note_id, recipient_user_id, permission) VALUES (:note_id, :recipient_user_id, :permission)");
                    $stmt->execute(['note_id' => $noteId, 'recipient_user_id' => $recipient['id'], 'permission' => $permission]);
                    if ($stmt->rowCount()) {
                        if (sendSharingEmail($pdo, $email, $noteId, $note['title'], $permission)) {
                            $successCount++;
                        } else {
                            $failedEmails[] = $email;
                        }
                    }
                } else {
                    $failedEmails[] = $email;
                }
            }
            $response = ['success' => true, 'message' => "Shared with $successCount user(s)"];
            if (!empty($failedEmails)) {
                $response['warning'] = "Failed to share with: " . implode(', ', $failedEmails);
            }
            echo json_encode($response);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle update sharing permissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_share'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
        $recipientUserId = filter_input(INPUT_POST, 'recipient_user_id', FILTER_VALIDATE_INT) ?: null;
        $permission = filter_input(INPUT_POST, 'permission', FILTER_SANITIZE_STRING);

        if (!$noteId || !$recipientUserId || !in_array($permission, ['read', 'edit'])) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $stmt = $pdo->prepare("UPDATE note_shares SET permission = :permission WHERE note_id = :note_id AND recipient_user_id = :recipient_user_id");
            $stmt->execute(['permission' => $permission, 'note_id' => $noteId, 'recipient_user_id' => $recipientUserId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle revoke sharing
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_share'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;
        $recipientUserId = filter_input(INPUT_POST, 'recipient_user_id', FILTER_VALIDATE_INT) ?: null;

        if (!$noteId || !$recipientUserId) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $stmt = $pdo->prepare("DELETE FROM note_shares WHERE note_id = :note_id AND recipient_user_id = :recipient_user_id");
            $stmt->execute(['note_id' => $noteId, 'recipient_user_id' => $recipientUserId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle trash note
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trash_note'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;

        if (!$noteId) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $stmt = $pdo->prepare("UPDATE notes SET is_trashed = 1 WHERE id = :note_id");
            $stmt->execute(['note_id' => $noteId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Handle restore note
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_note'])) {
        $noteId = filter_input(INPUT_POST, 'note_id', FILTER_VALIDATE_INT) ?: null;

        if (!$noteId) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM notes WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false]);
                ob_end_flush();
                exit();
            }

            $stmt = $pdo->prepare("UPDATE notes SET is_trashed = 0 WHERE id = :note_id");
            $stmt->execute(['note_id' => $noteId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Delete note
    if (isset($_GET['delete']) && isset($_GET['id'])) {
        $noteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$noteId) {
            http_response_code(400);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        if (!isNoteAccessible($pdo, $noteId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false]);
            ob_end_flush();
            exit();
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM notes WHERE id = :id AND user_id = :user_id");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Return notes HTML for live reload via AJAX
    if (isset($_GET['action']) && $_GET['action'] === 'list') {
        $search = trim($_GET['search'] ?? '');
        $labelFilter = trim($_GET['label'] ?? '');
        $isTrashed = filter_input(INPUT_GET, 'is_trashed', FILTER_VALIDATE_INT, ['options' => ['default' => 0]]) ? 1 : 0;

        $query = "
            SELECT n.id, n.user_id, n.title, n.content, n.is_pinned, n.pinned_at, n.password_hash, n.updated_at, n.is_trashed,
                   GROUP_CONCAT(ni.image_path) as image_paths, GROUP_CONCAT(l.name) as label_names,
                   GROUP_CONCAT(DISTINCT ns.recipient_user_id) as shared_user_ids,
                   GROUP_CONCAT(DISTINCT ns.permission) as shared_permissions,
                   GROUP_CONCAT(DISTINCT u2.email) as shared_emails
            FROM notes n
            LEFT JOIN note_images ni ON n.id = ni.note_id
            LEFT JOIN note_labels nl ON n.id = nl.note_id
            LEFT JOIN labels l ON nl.label_id = l.id
            LEFT JOIN note_shares ns ON n.id = ns.note_id
            LEFT JOIN users u2 ON ns.recipient_user_id = u2.id
            WHERE (n.user_id = :user_id OR ns.recipient_user_id = :user_id)
            AND n.is_trashed = :is_trashed
        ";
        $params = ['user_id' => $userId, 'is_trashed' => $isTrashed];

        if (!empty($search)) {
            $query .= " AND (n.title LIKE :search OR n.content LIKE :search OR l.name LIKE :search)";
            $params['search'] = "%$search%";
        }

        if (!empty($labelFilter)) {
            $query .= " AND n.id IN (
                SELECT nl2.note_id 
                FROM note_labels nl2 
                JOIN labels l2 ON nl2.label_id = l2.id 
                WHERE l2.name = :label AND l2.user_id = :user_id
            )";
            $params['label'] = $labelFilter;
        }

        $query .= " GROUP BY n.id ORDER BY n.is_pinned DESC, COALESCE(n.pinned_at, '1970-01-01') DESC, n.updated_at DESC";
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $html = '';
            if (empty($notes) && $isTrashed) {
                $html = '<div class="empty-trash-message"><p>Your trash is empty.</p></div>';
            } elseif (empty($notes)) {
                $html = '<div class="no-results"><p>No notes found.</p></div>';
            }
            foreach ($notes as $note) {
                $isLocked = $note['password_hash'] !== null;
                $isShared = !empty($note['shared_user_ids']);
                $isAccessible = isNoteAccessible($pdo, $note['id'], $userId);
                $isOwner = $note['user_id'] == $userId;
                $html .= '<div class="note-card existing-note-card" data-id="' . $note['id'] . '">';
                $html .= '<button class="pin-btn ' . ($note['is_pinned'] ? 'pinned' : '') . '" onclick="pinNote(' . $note['id'] . ', ' . ($note['is_pinned'] ? 0 : 1) . ')" aria-label="' . ($note['is_pinned'] ? 'Unpin' : 'Pin') . ' note"><i class="fas fa-thumbtack"></i></button>';
                $html .= '<button class="delete-btn" onclick="' . ($isTrashed ? 'deleteNote' : 'trashNote') . '(' . $note['id'] . ')" aria-label="' . ($isTrashed ? 'Delete permanently' : 'Move to trash') . '"><i class="fas fa-trash"></i></button>';
                if ($isTrashed) {
                    $html .= '<button class="restore-btn" onclick="restoreNote(' . $note['id'] . ')" aria-label="Restore note" title="Restore note"><i class="fas fa-undo"></i></button>';
                } else {
                    $html .= '<button class="image-btn" onclick="openImageModal(' . $note['id'] . ')" aria-label="Add image" title="Add image"><i class="fas fa-image"></i></button>';
                    $html .= '<button class="label-btn" onclick="openNoteLabelsModal(' . $note['id'] . ')" aria-label="Manage labels" title="Manage labels"><i class="fas fa-tag"></i></button>';
                }
                $html .= '<div class="note-title-container">';
                $html .= '<h6 onclick="' . ($isTrashed ? '' : 'editNote(' . $note['id'] . ')') . '">' . htmlspecialchars($note['title'] ?: 'Untitled') . '</h6>';
                if ($note['label_names'] && $isAccessible) {
                    $html .= '<div class="note-labels">';
                    foreach (explode(',', $note['label_names']) as $label) {
                        $html .= '<span class="note-label-tag">' . htmlspecialchars($label) . '</span>';
                    }
                    $html .= '</div>';
                }
                if ($isAccessible) {
                    $html .= '<p>' . nl2br(htmlspecialchars($note['content'])) . '</p>';
                    if (!empty($note['image_paths'])) {
                        $html .= '<div class="note-images">';
                        foreach (explode(',', $note['image_paths']) as $image) {
                            $html .= '<img src="' . htmlspecialchars($image) . '" class="note-image-thumb" onclick="openImageViewer(\'' . htmlspecialchars($image) . '\')" alt="Note image">';
                        }
                        $html .= '</div>';
                    }
                    if (!empty($note['shared_emails'])) {
                        $html .= '<div class="note-shared"><span>Shared with: ' . htmlspecialchars($note['shared_emails']) . '</span></div>';
                    }
                } else {
                    $html .= '<p>Enter password to view content</p>';
                }
                $html .= '</div>';
                $html .= '<small>Last updated: ' . $note['updated_at'] . '</small>';
                if ($isOwner && !$isTrashed) {
                    $html .= '<div class="dropdown">';
                    $html .= '<button class="btn settings-button" onclick="toggleDropdown(event, \'dropdown-' . $note['id'] . '\')" aria-label="Note settings"><i class="fas fa-cog"></i></button>';
                    $html .= '<div class="dropdown-content" id="dropdown-' . $note['id'] . '">';
                    $html .= '<a href="#" onclick="openShareModal(' . $note['id'] . '); return false;">📤 Share</a>';
                    if ($isAccessible) {
                        if ($isLocked) {
                            $html .= '<a href="#" onclick="relockNote(' . $note['id'] . '); return false;">🔐 Relock</a>';
                            $html .= '<a href="#" onclick="openPasswordModal(' . $note['id'] . ', \'change\'); return false;">🔐 Change Password</a>';
                            $html .= '<a href="#" onclick="removePassword(' . $note['id'] . '); return false;">🔓 Remove Password</a>';
                        } else {
                            $html .= '<a href="#" onclick="openPasswordModal(' . $note['id'] . ', \'set\'); return false;">🔒 Lock Note</a>';
                        }
                    } else {
                        $html .= '<a href="#" onclick="promptPassword(' . $note['id'] . ', \'access\'); return false;">🔓 Unlock</a>';
                    }
                    $html .= '</div>';
                    $html .= '</div>';
                } elseif (!$isAccessible) {
                    $html .= '<button class="btn" onclick="promptPassword(' . $note['id'] . ', \'access\')" aria-label="Unlock note">🔓 Unlock</button>';
                }
                $html .= '</div>';
            }
            echo json_encode(['success' => true, 'html' => $html]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Fetch labels for sidebar
    if (isset($_GET['action']) && $_GET['action'] === 'labels') {
        try {
            $stmt = $pdo->prepare("SELECT DISTINCT name FROM labels WHERE user_id = :user_id ORDER BY name");
            $stmt->execute(['user_id' => $userId]);
            $labels = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['success' => true, 'labels' => $labels]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // Load note for editing (return JSON for AJAX)
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
        $noteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if ($noteId) {
            if (!isNoteAccessible($pdo, $noteId, $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'is_locked' => true]);
                ob_end_flush();
                exit();
            }

            try {
                $stmt = $pdo->prepare("
                    SELECT n.*, GROUP_CONCAT(ns.recipient_user_id) as shared_user_ids,
                           GROUP_CONCAT(ns.permission) as shared_permissions,
                           GROUP_CONCAT(u2.email) as shared_emails,
                           GROUP_CONCAT(l.name) as label_names,
                           GROUP_CONCAT(ni.image_path) as image_paths
                    FROM notes n
                    LEFT JOIN note_shares ns ON n.id = ns.note_id
                    LEFT JOIN users u2 ON ns.recipient_user_id = u2.id
                    LEFT JOIN note_labels nl ON n.id = nl.note_id
                    LEFT JOIN labels l ON nl.label_id = l.id
                    LEFT JOIN note_images ni ON n.id = ni.note_id
                    WHERE n.id = :id AND (n.user_id = :user_id OR ns.recipient_user_id = :user_id)
                    GROUP BY n.id
                ");
                $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
                $note = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($note) {
                    echo json_encode([
                        'success' => true,
                        'id' => $note['id'],
                        'title' => $note['title'],
                        'content' => $note['content'],
                        'labels' => $note['label_names'] ? $note['label_names'] : '',
                        'updated_at' => $note['updated_at'],
                        'shared_emails' => $note['shared_emails'] ? explode(',', $note['shared_emails']) : [],
                        'shared_permissions' => $note['shared_permissions'] ? explode(',', $note['shared_permissions']) : [],
                        'shared_user_ids' => $note['shared_user_ids'] ? explode(',', $note['shared_user_ids']) : [],
                        'is_owner' => $note['user_id'] == $userId,
                        'is_locked' => $note['password_hash'] !== null,
                        'images' => $note['image_paths'] ? explode(',', $note['image_paths']) : [],
                        'is_pinned' => $note['is_pinned'] == 1,
                        'is_trashed' => $note['is_trashed'] == 1
                    ]);
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false]);
                }
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['success' => false]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false]);
        }
        ob_end_flush();
        exit();
    }

    // If no valid action is matched
    http_response_code(400);
    echo json_encode(['success' => false]);
    ob_end_flush();
    exit();

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false]);
    exit();
}
?>