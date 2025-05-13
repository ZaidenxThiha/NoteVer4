<?php
session_start();

// Redirect unauthenticated users to login page
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

// Database connection
$db_host = 'mysql';
$db_user = 'noteapp_user';
$db_pass = 'YourStrong@Passw0rd';
$db_name = 'noteapp';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch user data to check activation status
    $stmt = $pdo->prepare("SELECT is_activated, email, display_name, preferences FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header("Location: /login.php");
        exit();
    }

    $isUnverified = !$user['is_activated'];
    $userEmail = $user['email'];
    $displayName = $user['display_name'];
    
    // Get user preferences
    $preferences = json_decode($user['preferences'] ?? '{}', true);
    $defaultView = $preferences['note_view'] ?? 'grid'; // Default to grid view
    
    // Handle view preference change
    if (isset($_POST['change_view'])) {
        $newView = $_POST['view_type'] === 'grid' ? 'grid' : 'list';
        $preferences['note_view'] = $newView;
        $stmt = $pdo->prepare("UPDATE users SET preferences = ? WHERE id = ?");
        $stmt->execute([json_encode($preferences), $_SESSION['user_id']]);
        $defaultView = $newView;
    }

    // Handle note operations
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['note_id'])) {
            // Update existing note
            $noteId = $_POST['note_id'];
            $title = $_POST['title'] ?? '';
            $content = $_POST['content'] ?? '';
            
            if (!empty($title) || !empty($content)) {
                $stmt = $pdo->prepare("UPDATE notes SET title = ?, content = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                $stmt->execute([$title, $content, $noteId, $_SESSION['user_id']]);
            }
        } else {
            // Create new note
            $title = $_POST['title'] ?? '';
            $content = $_POST['content'] ?? '';
            
            if (!empty($title) || !empty($content)) {
                $stmt = $pdo->prepare("INSERT INTO notes (user_id, title, content) VALUES (?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $title, $content]);
            }
        }
    }
    
    // Handle note deletion
    if (isset($_GET['delete_note'])) {
        $noteId = $_GET['delete_note'];
        $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ? AND user_id = ?");
        $stmt->execute([$noteId, $_SESSION['user_id']]);
    }

    // Fetch all notes for the user
    $stmt = $pdo->prepare("SELECT id, title, content, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notes - My Note</title>
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
            min-height: 100vh;
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

        .header {
            background: #fffdf4;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            font-size: 24px;
            color: #333;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #dedede;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .main-container {
            display: flex;
            min-height: calc(100vh - 80px);
        }

        .sidebar {
            width: 250px;
            background: #fffdf4;
            padding: 20px;
            border-right: 1px solid #eee;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu li {
            margin-bottom: 15px;
        }

        .sidebar-menu a {
            text-decoration: none;
            color: #333;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: #dedede;
        }

        .sidebar-menu i {
            font-size: 20px;
        }

        .content {
            flex: 1;
            padding: 30px;
            background: #fffffd;
        }

        .view-options {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
            gap: 10px;
        }

        .view-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            color: #666;
            padding: 5px;
        }

        .view-btn.active {
            color: #3366ff;
        }

        .notes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .notes-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .note-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            padding: 20px;
            transition: all 0.3s;
            position: relative;
        }

        .note-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-3px);
        }

        .note-card.grid {
            height: 250px;
            display: flex;
            flex-direction: column;
        }

        .note-card.list {
            display: flex;
            flex-direction: column;
        }

        .note-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
            border: none;
            background: transparent;
            width: 100%;
            padding: 5px;
        }

        .note-title:focus {
            outline: none;
            background: #f5f5f5;
            border-radius: 5px;
        }

        .note-content {
            flex: 1;
            border: none;
            background: transparent;
            width: 100%;
            resize: none;
            padding: 5px;
            font-family: 'Poppins', sans-serif;
        }

        .note-content:focus {
            outline: none;
            background: #f5f5f5;
            border-radius: 5px;
        }

        .note-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }

        .delete-note {
            color: #ff4d4d;
            cursor: pointer;
            font-size: 14px;
        }

        .add-note-btn {
            background: #3366ff;
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            font-size: 24px;
            position: fixed;
            bottom: 30px;
            right: 30px;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .add-note-btn:hover {
            background: #254eda;
        }

        /* Auto-save indicator */
        .save-status {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 12px;
            color: #666;
        }

        .saving {
            color: #ffaa00;
        }

        .saved {
            color: #28a745;
        }

        /* RESPONSIVE for mobile */
        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #eee;
            }

            .sidebar-menu {
                display: flex;
                overflow-x: auto;
                padding-bottom: 10px;
            }

            .sidebar-menu li {
                margin-bottom: 0;
                margin-right: 15px;
                white-space: nowrap;
            }

            .notes-grid {
                grid-template-columns: 1fr;
            }

            .add-note-btn {
                bottom: 20px;
                right: 20px;
            }
        }
    </style>
</head>
<body>
    <?php if ($isUnverified): ?>
        <div class="notification">
            Your account is unverified. Please check your email to complete the activation process.
            <a href="/welcome.php?resend=true">Resend Activation Email</a>
        </div>
    <?php endif; ?>
    
    <div class="header">
        <h1>My Notes</h1>
        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr($displayName, 0, 1)); ?></div>
            <span><?php echo htmlspecialchars($displayName); ?></span>
        </div>
    </div>

    <div class="main-container">
        <div class="sidebar">
            <ul class="sidebar-menu">
                <li><a href="/home.php" class="active"><i>📝</i> Notes</a></li>
                <li><a href="#"><i>⚙️</i> Settings</a></li>
                <li><a href="/logout.php"><i>🚪</i> Logout</a></li>
            </ul>
        </div>

        <div class="content">
            <form method="post" class="view-options">
                <button type="submit" name="change_view" class="view-btn <?php echo $defaultView === 'grid' ? 'active' : ''; ?>" value="grid">
                    <i>☷</i> Grid
                </button>
                <button type="submit" name="change_view" class="view-btn <?php echo $defaultView === 'list' ? 'active' : ''; ?>" value="list">
                    <i>☰</i> List
                </button>
                <input type="hidden" name="view_type" value="<?php echo $defaultView; ?>">
            </form>

            <div class="<?php echo $defaultView === 'grid' ? 'notes-grid' : 'notes-list'; ?>">
                <!-- Add new note card -->
                <div class="note-card <?php echo $defaultView === 'grid' ? 'grid' : 'list'; ?>">
                    <input type="text" class="note-title" placeholder="New Note Title" name="title" form="note-form-new">
                    <textarea class="note-content" placeholder="Start writing your note here..." name="content" form="note-form-new"></textarea>
                    <div class="note-footer">
                        <span>Just now</span>
                    </div>
                    <form id="note-form-new" method="post" style="display: none;"></form>
                </div>

                <!-- Existing notes -->
                <?php foreach ($notes as $note): ?>
                    <div class="note-card <?php echo $defaultView === 'grid' ? 'grid' : 'list'; ?>">
                        <div class="save-status saved">Saved</div>
                        <input type="text" class="note-title" placeholder="Note Title" 
                               value="<?php echo htmlspecialchars($note['title']); ?>" 
                               name="title" 
                               form="note-form-<?php echo $note['id']; ?>">
                        <textarea class="note-content" placeholder="Note content" 
                                  name="content" 
                                  form="note-form-<?php echo $note['id']; ?>"><?php echo htmlspecialchars($note['content']); ?></textarea>
                        <div class="note-footer">
                            <span><?php echo date('M j, Y g:i a', strtotime($note['updated_at'])); ?></span>
                            <a href="?delete_note=<?php echo $note['id']; ?>" class="delete-note">Delete</a>
                        </div>
                        <form id="note-form-<?php echo $note['id']; ?>" method="post" style="display: none;">
                            <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-save functionality
        document.addEventListener('DOMContentLoaded', function() {
            const noteForms = document.querySelectorAll('[id^="note-form-"]');
            
            noteForms.forEach(form => {
                const inputs = form.querySelectorAll('input, textarea');
                const saveStatus = form.closest('.note-card')?.querySelector('.save-status');
                let saveTimeout;
                
                inputs.forEach(input => {
                    input.addEventListener('input', function() {
                        if (saveStatus) {
                            saveStatus.textContent = 'Saving...';
                            saveStatus.classList.remove('saved');
                            saveStatus.classList.add('saving');
                        }
                        
                        // Clear any existing timeout
                        clearTimeout(saveTimeout);
                        
                        // Set a new timeout to submit the form after 1 second of inactivity
                        saveTimeout = setTimeout(() => {
                            form.submit();
                            
                            if (saveStatus) {
                                setTimeout(() => {
                                    saveStatus.textContent = 'Saved';
                                    saveStatus.classList.remove('saving');
                                    saveStatus.classList.add('saved');
                                }, 500);
                            }
                        }, 1000);
                    });
                });
            });

            // Handle new note submission
            const newNoteForm = document.getElementById('note-form-new');
            const newNoteInputs = newNoteForm.querySelectorAll('input, textarea');
            
            newNoteInputs.forEach(input => {
                input.addEventListener('input', function() {
                    // Clear any existing timeout
                    clearTimeout(saveTimeout);
                    
                    // Set a new timeout to submit the form after 1 second of inactivity
                    saveTimeout = setTimeout(() => {
                        if (input.value.trim() !== '') {
                            newNoteForm.submit();
                        }
                    }, 1000);
                });
            });

            // Toggle view buttons
            const viewButtons = document.querySelectorAll('.view-btn');
            const viewTypeInput = document.querySelector('input[name="view_type"]');
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    viewTypeInput.value = this.value;
                });
            });
        });
    </script>
</body>
</html>