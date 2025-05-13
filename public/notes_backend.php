<?php
// Start output buffering to prevent premature output
ob_start();

session_start();
require_once 'config.php';
require_once 'utils.php';

header('Content-Type: application/json');

function sendResponse($success, $data = [], $error = null) {
    // Ensure no output before JSON
    ob_end_clean();
    echo json_encode(array_merge(['success' => $success], $data, $error ? ['error' => $error] : []));
    exit;
}

function custom_htmlspecialchars($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function custom_nl2br($string) {
    return nl2br(custom_htmlspecialchars($string));
}

// Check authentication
if (empty($_SESSION['user_id'])) {
    error_log("No user_id in session for request to {$_SERVER['REQUEST_URI']}");
    sendResponse(false, [], 'User not authenticated');
}

$userId = $_SESSION['user_id'];

// Validate database schema
try {
    $stmt = $pdo->query("DESCRIBE notes");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $requiredColumns = ['id', 'user_id', 'title', 'content', 'is_pinned', 'password_hash', 'is_locked', 'is_trashed', 'created_at', 'updated_at'];
    foreach ($requiredColumns as $col) {
        if (!in_array($col, $columns)) {
            error_log("Missing column in notes table: $col");
            sendResponse(false, [], "Database schema error: Missing column $col");
        }
    }
    $pdo->query("DESCRIBE labels");
    $pdo->query("DESCRIBE note_labels");
    $pdo->query("DESCRIBE note_shares");
    $pdo->query("DESCRIBE users");
    $pdo->query("DESCRIBE note_images");
} catch (PDOException $e) {
    error_log("Schema validation error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    sendResponse(false, [], 'Database schema validation failed: ' . $e->getMessage());
}

// Handle actions
$action = $_GET['action'] ?? '';
$save = isset($_GET['save']) ? (int)$_GET['save'] : 0;
$noteId = isset($_GET['id']) ? (int)$_GET['id'] : null;

try {
    if ($save === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Save note
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['title'], $data['content'], $data['labels'], $data['updated_at'])) {
            error_log("Invalid note data for save: " . print_r($data, true));
            sendResponse(false, [], 'Invalid note data');
        }

        // Verify permissions
        if ($noteId) {
            $stmt = $pdo->prepare(
                "SELECT user_id, password_hash, is_locked FROM notes WHERE id = :id AND (user_id = :user_id OR id IN (SELECT note_id FROM note_shares WHERE recipient_user_id = :user_id AND permission = 'edit'))"
            );
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$note) {
                error_log("Note $noteId not found or user $userId lacks edit permissions");
                sendResponse(false, [], 'Note not found or you lack edit permissions');
            }
            if ($note['password_hash'] && $note['is_locked']) {
                error_log("Note $noteId is password-protected for user $userId");
                sendResponse(false, ['is_locked' => true], 'Note is password-protected');
            }
        }

        // Save or update note
        if ($noteId) {
            $stmt = $pdo->prepare(
                "UPDATE notes SET title = :title, content = :content, updated_at = :updated_at WHERE id = :id"
            );
            $stmt->execute([
                'id' => $noteId,
                'title' => $data['title'],
                'content' => $data['content'],
                'updated_at' => $data['updated_at']
            ]);

            // Update labels
            $labels = array_filter(array_map('trim', explode(',', $data['labels'])));
            $stmt = $pdo->prepare("DELETE FROM note_labels WHERE note_id = :note_id");
            $stmt->execute(['note_id' => $noteId]);

            foreach ($labels as $labelName) {
                if (empty($labelName)) continue;
                $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
                $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
                $labelId = $stmt->fetchColumn();

                if (!$labelId) {
                    $stmt = $pdo->prepare("INSERT INTO labels (user_id, name) VALUES (:user_id, :name)");
                    $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
                    $labelId = $pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("INSERT INTO note_labels (note_id, label_id) VALUES (:note_id, :label_id)");
                $stmt->execute(['note_id' => $noteId, 'label_id' => $labelId]);
            }

            sendResponse(true, ['id' => $noteId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO notes (user_id, title, content, updated_at) VALUES (:user_id, :title, :content, :updated_at)"
            );
            $stmt->execute([
                'user_id' => $userId,
                'title' => $data['title'],
                'content' => $data['content'],
                'updated_at' => $data['updated_at']
            ]);
            $newId = $pdo->lastInsertId();

            $labels = array_filter(array_map('trim', explode(',', $data['labels'])));
            foreach ($labels as $labelName) {
                if (empty($labelName)) continue;
                $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
                $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
                $labelId = $stmt->fetchColumn();

                if (!$labelId) {
                    $stmt = $pdo->prepare("INSERT INTO labels (user_id, name) VALUES (:user_id, :name)");
                    $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
                    $labelId = $pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("INSERT INTO note_labels (note_id, label_id) VALUES (:note_id, :label_id)");
                $stmt->execute(['note_id' => $newId, 'label_id' => $labelId]);
            }

            sendResponse(true, ['id' => $newId]);
        }
    } elseif ($action === 'edit' && $noteId) {
        // Load note
        $stmt = $pdo->prepare(
            "SELECT n.*, GROUP_CONCAT(u.email) AS shared_emails, GROUP_CONCAT(s.permission) AS shared_permissions, GROUP_CONCAT(s.recipient_user_id) AS shared_user_ids,
                    GROUP_CONCAT(l.name) AS labels
             FROM notes n
             LEFT JOIN note_shares s ON n.id = s.note_id
             LEFT JOIN users u ON s.recipient_user_id = u.id
             LEFT JOIN note_labels nl ON n.id = nl.note_id
             LEFT JOIN labels l ON nl.label_id = l.id
             WHERE n.id = :id AND (n.user_id = :user_id OR n.id IN (SELECT note_id FROM note_shares WHERE recipient_user_id = :user_id))
             GROUP BY n.id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$note) {
            error_log("Note $noteId not found or user $userId lacks access");
            sendResponse(false, [], 'Note not found or you lack access');
        }
        if ($note['password_hash'] && $note['is_locked']) {
            error_log("Note $noteId is password-protected for user $userId");
            sendResponse(false, ['is_locked' => true], 'Note is password-protected');
        }

        $note['is_owner'] = $note['user_id'] == $userId;
        $note['shared_emails'] = $note['shared_emails'] ? explode(',', $note['shared_emails']) : [];
        $note['shared_permissions'] = $note['shared_permissions'] ? explode(',', $note['shared_permissions']) : [];
        $note['shared_user_ids'] = $note['shared_user_ids'] ? explode(',', $note['shared_user_ids']) : [];
        $note['labels'] = $note['labels'] ? explode(',', $note['labels']) : [];
        $note['images'] = [];
        try {
            $stmt = $pdo->prepare("SELECT image_path FROM note_images WHERE note_id = :note_id");
            $stmt->execute(['note_id' => $noteId]);
            $note['images'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Failed to fetch images for note $noteId: " . $e->getMessage());
        }
        sendResponse(true, $note);
    } elseif ($action === 'notes') {
        // Load all notes
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $label = isset($_GET['label']) ? trim($_GET['label']) : '';
        $isTrashed = isset($_GET['is_trashed']) ? (int)$_GET['is_trashed'] : 0;

        $query = "SELECT n.*, GROUP_CONCAT(u.email) AS shared_emails, GROUP_CONCAT(l.name) AS labels
                  FROM notes n
                  LEFT JOIN note_shares s ON n.id = s.note_id
                  LEFT JOIN users u ON s.recipient_user_id = u.id
                  LEFT JOIN note_labels nl ON n.id = nl.note_id
                  LEFT JOIN labels l ON nl.label_id = l.id
                  WHERE (n.user_id = :user_id OR n.id IN (SELECT note_id FROM note_shares WHERE recipient_user_id = :user_id))
                  AND n.is_trashed = :is_trashed";
        $params = ['user_id' => $userId, 'is_trashed' => $isTrashed];

        if ($search) {
            $query .= " AND (n.title LIKE :search OR n.content LIKE :search)";
            $params['search'] = "%$search%";
        }
        if ($label) {
            $query .= " AND EXISTS (SELECT 1 FROM note_labels nl2 JOIN labels l2 ON nl2.label_id = l2.id WHERE nl2.note_id = n.id AND l2.name = :label)";
            $params['label'] = $label;
        }

        $query .= " GROUP BY n.id ORDER BY n.is_pinned DESC, n.updated_at DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $html = '';
        if (empty($notes)) {
            $html = '<div class="no-results"><p>No notes found.</p></div>';
        } else {
            foreach ($notes as $note) {
                $isAccessible = !$note['password_hash'] || !$note['is_locked'] || ($note['user_id'] == $userId);
                $html .= '<div class="note-card existing-note-card ' . ($note['is_pinned'] ? 'pinned' : '') . ($isTrashed ? '' : ' cursor-pointer') . '" data-id="' . $note['id'] . '">';
                $html .= '<button class="pin-btn ' . ($note['is_pinned'] ? 'pinned' : '') . '" onclick="pinNote(' . $note['id'] . ', ' . ($note['is_pinned'] ? 0 : 1) . '); event.stopPropagation();" aria-label="' . ($note['is_pinned'] ? 'Unpin' : 'Pin') . ' note">';
                $html .= '<i class="fas fa-thumbtack"></i></button>';
                $html .= '<button class="delete-btn" onclick="' . ($isTrashed ? 'deleteNote' : 'trashNote') . '(' . $note['id'] . '); event.stopPropagation();" aria-label="' . ($isTrashed ? 'Delete permanently' : 'Move to trash') . '">';
                $html .= '<i class="fas fa-trash"></i></button>';

                if ($isTrashed) {
                    $html .= '<button class="restore-btn" onclick="restoreNote(' . $note['id'] . '); event.stopPropagation();" aria-label="Restore note" title="Restore note"><i class="fas fa-undo"></i></button>';
                } else {
                    $html .= '<button class="image-btn" onclick="openImageModal(' . $note['id'] . '); event.stopPropagation();" aria-label="Add image" title="Add image"><i class="fas fa-image"></i></button>';
                    $html .= '<button class="label-btn" onclick="openNoteLabelsModal(' . $note['id'] . '); event.stopPropagation();" aria-label="Manage labels" title="Manage labels"><i class="fas fa-tag"></i></button>';
                }

                $html .= '<div class="note-title-container">';
                $html .= '<h6>' . htmlspecialchars($note['title'] ?: 'Untitled') . '</h6>';

                if ($isAccessible) {
                    if ($note['labels']) {
                        $html .= '<div class="note-labels">';
                        foreach (explode(',', $note['labels']) as $lbl) {
                            $html .= '<span class="note-label-tag">' . htmlspecialchars(trim($lbl)) . '</span>';
                        }
                        $html .= '</div>';
                    }
                    $html .= '<p>' . custom_nl2br($note['content'] ?: '') . '</p>';
                    if ($note['shared_emails']) {
                        $html .= '<div class="note-shared"><span>Shared with: ' . htmlspecialchars($note['shared_emails']) . '</span></div>';
                    }
                    // Add images
                    $images = [];
                    try {
                        $stmt = $pdo->prepare("SELECT image_path FROM note_images WHERE note_id = :note_id");
                        $stmt->execute(['note_id' => $note['id']]);
                        $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    } catch (PDOException $e) {
                        error_log("Failed to fetch images for note {$note['id']}: " . $e->getMessage());
                    }
                    if ($images) {
                        $html .= '<div class="note-images">';
                        foreach ($images as $image) {
                            $html .= '<img src="' . htmlspecialchars($image) . '" class="note-image-thumb" onclick="openImageViewer(\'' . htmlspecialchars($image) . '\')" alt="Note image">';
                        }
                        $html .= '</div>';
                    }
                } else {
                    $html .= '<p>Enter password to view content</p>';
                }

                $html .= '</div>';
                $html .= '<small>Last updated: ' . $note['updated_at'] . '</small>';

                if ($note['user_id'] == $userId && !$isTrashed) {
                    $html .= '<div class="dropdown">';
                    $html .= '<button class="btn settings-button" onclick="toggleDropdown(event, \'dropdown-' . $note['id'] . '\'); event.stopPropagation();" aria-label="Note settings">';
                    $html .= '<i class="fas fa-cog"></i></button>';
                    $html .= '<div class="dropdown-content" id="dropdown-' . $note['id'] . '">';
                    $html .= '<a href="#" onclick="openShareModal(' . $note['id'] . '); return false;">📤 Share</a>';
                    if ($isAccessible) {
                        if ($note['password_hash'] && $note['is_locked']) {
                            $html .= '<a href="#" onclick="relockNote(' . $note['id'] . '); return false;">🔐 Relock</a>';
                            $html .= '<a href="#" onclick="openPasswordModal(' . $note['id'] . ', \'change\'); return false;">🔐 Change Password</a>';
                            $html .= '<a href="#" onclick="removePassword(' . $note['id'] . '); return false;">🔓 Remove Password</a>';
                        } else {
                            $html .= '<a href="#" onclick="openPasswordModal(' . $note['id'] . ', \'set\'); return false;">🔒 Lock Note</a>';
                        }
                    } else {
                        $html .= '<a href="#" onclick="promptPassword(' . $note['id'] . ', \'access\'); return false;">🔓 Unlock</a>';
                    }
                    $html .= '</div></div>';
                } elseif (!$isAccessible) {
                    $html .= '<button class="btn" onclick="promptPassword(' . $note['id'] . ', \'access\'); event.stopPropagation();" aria-label="Unlock note">🔓 Unlock</button>';
                }

                $html .= '</div>';
            }
        }

        sendResponse(true, ['html' => $html]);
    } elseif ($action === 'labels') {
        // Load labels
        $stmt = $pdo->prepare(
            "SELECT DISTINCT l.name
             FROM labels l
             WHERE l.user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        $labels = $stmt->fetchAll(PDO::FETCH_COLUMN);
        sendResponse(true, ['labels' => array_unique(array_filter($labels))]);
    } elseif ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Upload image
        if (!isset($_FILES['image']) || !isset($_POST['note_id'])) {
            http_response_code(400);
            sendResponse(false, [], 'Image or note ID missing');
        }

        $noteId = (int)$_POST['note_id'];
        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            sendResponse(false, [], 'Image upload error');
        }

        $uploadDir = 'Uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('img_') . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            http_response_code(500);
            sendResponse(false, [], 'Failed to save image');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO note_images (note_id, image_path) VALUES (:note_id, :image_path)"
        );
        $stmt->execute(['note_id' => $noteId, 'image_path' => $destination]);
        sendResponse(true, ['image_path' => $destination]);
    } elseif ($action === 'share' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Share note
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'], $data['emails'], $data['permission'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid share data');
        }

        $noteId = (int)$data['note_id'];
        $emails = array_filter(array_map('trim', explode(',', $data['emails'])));
        $permission = $data['permission'] === 'edit' ? 'edit' : 'read';

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        foreach ($emails as $email) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $recipientId = $stmt->fetchColumn();

            if ($recipientId) {
                $stmt = $pdo->prepare(
                    "INSERT INTO note_shares (note_id, recipient_user_id, permission) 
                     VALUES (:note_id, :recipient_user_id, :permission)
                     ON DUPLICATE KEY UPDATE permission = :permission"
                );
                $stmt->execute([
                    'note_id' => $noteId,
                    'recipient_user_id' => $recipientId,
                    'permission' => $permission
                ]);
            }
        }

        sendResponse(true, []);
    } elseif ($action === 'update_share' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Update share permission
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'], $data['user_id'], $data['permission'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid update share data');
        }

        $noteId = (int)$data['note_id'];
        $recipientId = (int)$data['user_id'];
        $permission = $data['permission'] === 'edit' ? 'edit' : 'read';

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $stmt = $ppo->prepare(
            "UPDATE note_shares SET permission = :permission WHERE note_id = :note_id AND recipient_user_id = :recipient_user_id"
        );
        $stmt->execute([
            'note_id' => $noteId,
            'recipient_user_id' => $recipientId,
            'permission' => $permission
        ]);

        sendResponse(true, []);
    } elseif ($action === 'revoke_share' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Revoke share
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'], $data['user_id'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid revoke share data');
        }

        $noteId = (int)$data['note_id'];
        $recipientId = (int)$data['user_id'];

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $stmt = $pdo->prepare(
            "DELETE FROM note_shares WHERE note_id = :note_id AND recipient_user_id = :recipient_user_id"
        );
        $stmt->execute(['note_id' => $noteId, 'recipient_user_id' => $recipientId]);

        sendResponse(true, []);
    } elseif ($action === 'verify_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify password
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'], $data['password'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid password data');
        }

        $noteId = (int)$data['note_id'];
        $password = $data['password'];

        $stmt = $pdo->prepare(
            "SELECT password_hash FROM notes WHERE id = :id AND (user_id = :user_id OR id IN (SELECT note_id FROM note_shares WHERE recipient_user_id = :user_id))"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$note) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack access');
        }

        if (!$note['password_hash'] || password_verify($password, $note['password_hash'])) {
            $stmt = $pdo->prepare("UPDATE notes SET is_locked = 0 WHERE id = :id");
            $stmt->execute(['id' => $noteId]);
            sendResponse(true, []);
        } else {
            http_response_code(403);
            sendResponse(false, [], 'Incorrect password');
        }
    } elseif ($action === 'set_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Set password
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'], $data['password'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid password data');
        }

        $noteId = (int)$data['note_id'];
        $password = $data['password'];

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "UPDATE notes SET password_hash = :password_hash, is_locked = 1 WHERE id = :id"
        );
        $stmt->execute(['id' => $noteId, 'password_hash' => $passwordHash]);
        sendResponse(true, []);
    } elseif ($action === 'remove_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Remove password
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid password data');
        }

        $noteId = (int)$data['note_id'];

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $stmt = $pdo->prepare(
            "UPDATE notes SET password_hash = NULL, is_locked = 0 WHERE id = :id"
        );
        $stmt->execute(['id' => $noteId]);
        sendResponse(true, []);
    } elseif ($action === 'relock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Relock note
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid relock data');
        }

        $noteId = (int)$data['note_id'];

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $stmt = $pdo->prepare("UPDATE notes SET is_locked = 1 WHERE id = :id");
        $stmt->execute(['id' => $noteId]);
        sendResponse(true, []);
    } elseif ($action === 'pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Pin/unpin note
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'], $data['pin'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid pin data');
        }

        $noteId = (int)$data['note_id'];
        $pin = (int)$data['pin'];

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $pinnedAt = $pin ? 'NOW()' : 'NULL';
        $stmt = $pdo->prepare(
            "UPDATE notes SET is_pinned = :pin, pinned_at = $pinnedAt WHERE id = :id"
        );
        $stmt->execute(['id' => $noteId, 'pin' => $pin]);
        sendResponse(true, []);
    } elseif ($action === 'trash' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Trash note
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid trash data');
        }

        $noteId = (int)$data['note_id'];

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $stmt = $pdo->prepare("UPDATE notes SET is_trashed = 1 WHERE id = :id");
        $stmt->execute(['id' => $noteId]);
        sendResponse(true, []);
    } elseif ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Restore note
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid restore data');
        }

        $noteId = (int)$data['note_id'];

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $stmt = $pdo->prepare("UPDATE notes SET is_trashed = 0 WHERE id = :id");
        $stmt->execute(['id' => $noteId]);
        sendResponse(true, []);
    } elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Delete note
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['note_id'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid delete data');
        }

        $noteId = (int)$data['note_id'];

        $stmt = $pdo->prepare(
            "SELECT user_id FROM notes WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            sendResponse(false, [], 'Note not found or you lack permissions');
        }

        $stmt = $pdo->prepare("DELETE FROM notes WHERE id = :id");
        $stmt->execute(['id' => $noteId]);
        sendResponse(true, []);
    } elseif ($action === 'add_label' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Add label
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['label'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid label data');
        }

        $labelName = trim($data['label']);
        if (empty($labelName)) {
            http_response_code(400);
            sendResponse(false, [], 'Label name required');
        }

        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
        $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
        if ($stmt->fetch()) {
            http_response_code(400);
            sendResponse(false, [], 'Label already exists');
        }

        $stmt = $pdo->prepare("INSERT INTO labels (user_id, name) VALUES (:user_id, :name)");
        $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
        sendResponse(true, []);
    } elseif ($action === 'rename_label' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Rename label
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['old_name'], $data['new_name'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid rename label data');
        }

        $oldName = trim($data['old_name']);
        $newName = trim($data['new_name']);
        if (empty($oldName) || empty($newName)) {
            http_response_code(400);
            sendResponse(false, [], 'Old and new label names required');
        }

        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
        $stmt->execute(['user_id' => $userId, 'name' => $oldName]);
        $labelId = $stmt->fetchColumn();

        if (!$labelId) {
            http_response_code(404);
            sendResponse(false, [], 'Label not found');
        }

        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
        $stmt->execute(['user_id' => $userId, 'name' => $newName]);
        if ($stmt->fetch()) {
            http_response_code(400);
            sendResponse(false, [], 'New label name already exists');
        }

        $stmt = $pdo->prepare("UPDATE labels SET name = :new_name WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $labelId, 'new_name' => $newName, 'user_id' => $userId]);
        sendResponse(true, []);
    } elseif ($action === 'delete_label' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Delete label
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['label'])) {
            http_response_code(400);
            sendResponse(false, [], 'Invalid delete label data');
        }

        $labelName = trim($data['label']);
        if (empty($labelName)) {
            http_response_code(400);
            sendResponse(false, [], 'Label name required');
        }

        $stmt = $pdo->prepare("SELECT id FROM labels WHERE user_id = :user_id AND name = :name");
        $stmt->execute(['user_id' => $userId, 'name' => $labelName]);
        $labelId = $stmt->fetchColumn();

        if (!$labelId) {
            http_response_code(404);
            sendResponse(false, [], 'Label not found');
        }

        $stmt = $pdo->prepare("DELETE FROM note_labels WHERE label_id = :label_id");
        $stmt->execute(['label_id' => $labelId]);

        $stmt = $pdo->prepare("DELETE FROM labels WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $labelId, 'user_id' => $userId]);
        sendResponse(true, []);
    } elseif ($action === 'debug_session') {
        // Debug session
        error_log("Session debug: user_id = " . ($_SESSION['user_id'] ?? 'none'));
        sendResponse(true, ['user_id' => $_SESSION['user_id'] ?? null]);
    } else {
        http_response_code(400);
        sendResponse(false, [], 'Invalid action: ' . htmlspecialchars($action));
    }
} catch (PDOException $e) {
    error_log("Backend PDO error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("SQL Query: " . ($stmt->queryString ?? 'N/A'));
    error_log("Parameters: " . print_r($params ?? [], true));
    sendResponse(false, [], 'Database error: ' . $e->getMessage());
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    sendResponse(false, [], 'Server error: ' . $e->getMessage());
} finally {
    // Clean up output buffer
    ob_end_clean();
}
?>