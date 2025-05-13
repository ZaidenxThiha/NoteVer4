<?php
session_start();
require_once 'config.php';

// Redirect to login if user is not authenticated
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT display_name, is_activated FROM users WHERE id = :user_id");
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $username = $user ? htmlspecialchars($user['display_name']) : 'Unknown User';
    $isActivated = $user && $user['is_activated'] ? 'Activated' : 'Not Activated';
} catch (PDOException $e) {
    error_log("User fetch PDO error: " . $e->getMessage());
    $username = 'Unknown User';
    $isActivated = 'Error';
}

$note = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $noteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($noteId) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM notes WHERE id = :id AND (user_id = :user_id OR id IN (SELECT note_id FROM note_shares WHERE recipient_user_id = :user_id))");
            $stmt->execute(['id' => $noteId, 'user_id' => $userId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Edit note PDO error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZeroNote</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="style.css" rel="stylesheet">
    <script>
        window.userId = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;
        window.currentNoteId = <?php echo isset($note) && $note ? json_encode($note['id']) : 'null'; ?>;
    </script>
    <!-- Load scripts with defer and relative paths -->
    <script defer src="js/ui.js"></script>
    <script defer src="js/api.js"></script>
    <script defer src="js/autosave.js"></script>
    <script defer src="js/notes.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Detect script loading errors
        window.addEventListener('error', (event) => {
            if (event.target && event.target.tagName === 'SCRIPT' && event.target.src.includes('ui.js')) {
                console.error('Failed to load ui.js:', event.message);
                alert('Application error: Failed to load UI components. Please check your network and refresh the page.');
            }
        }, true);
        // Fallback if UI.js fails to load
        window.addEventListener('DOMContentLoaded', () => {
            if (typeof UI === 'undefined') {
                console.error('UI object is not defined. Ensure ui.js is loaded correctly.');
                document.body.innerHTML = '<div class="error">Error: Application failed to initialize. Please refresh the page.</div>';
            }
        });
    </script>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <button id="sidebarToggle" class="btn toggle-btn">
                <i class="fas fa-bars"></i>
            </button>
            <div class="sidebar-content">
                <div class="sidebar-item active" id="notesBtn">
                    <i class="fas fa-sticky-note"></i>
                    <span class="label">Notes</span>
                </div>
                <div class="sidebar-item" id="labelsBtn" data-bs-toggle="modal" data-bs-target="#labelManagementModal">
                    <i class="fas fa-folder-plus"></i>
                    <span class="label">Labels</span>
                </div>
                <div id="labelFiltersList" class="label-filters-list"></div>
                <div class="sidebar-item" id="trashBtn">
                    <i class="fas fa-trash-alt"></i>
                    <span class="label">Trash</span>
                </div>
                <div class="sidebar-item" data-view="saved">
                    <i class="fas fa-save"></i>
                    <span class="label">Saved</span>
                </div>
            </div>
            <div class="sidebar-item profile-btn mt-auto">
                <i class="fas fa-user-alt"></i>
                <span class="label"><?php echo $username; ?></span>
                <small class="activation-status <?php echo strtolower(str_replace(' ', '-', $isActivated)); ?>">
                    <?php echo $isActivated; ?>
                </small>
                <a href="logout.php" class="logout-link ms-2"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>

        <!-- Main content -->
        <div class="main-content flex-grow-1">
            <!-- Header -->
            <div class="w-100 header-bar d-flex justify-content-between align-items-center px-4 py-3">
                <div class="d-flex align-items-center gap-2">
                    <button id="sidebarToggleMobile" class="btn sidebar-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h5 class="m-0">ZeroNote</h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-outline-secondary btn-sm" id="filterBtn" title="Filter">
                        <i class="fas fa-filter fa-lg"></i>
                    </button>
                    <button class="btn btn-secondary btn-sm" id="toggleViewBtn">List View</button>
                    <div class="input-group" style="max-width: 300px;">
                        <input type="text" class="form-control rounded-start" id="searchInput" placeholder="Search notes...">
                        <span class="input-group-text rounded-end"><i class="fas fa-search"></i></span>
                    </div>
                    <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
                </div>
            </div>

            <!-- Inline Note Form Container -->
            <div id="note-form-container" class="note-form-container" style="display: none;">
                <form id="inlineNoteForm">
                    <input type="text" id="inlineTitle" name="title" class="form-control" placeholder="Title" />
                    <textarea id="inlineContent" name="content" class="form-control" rows="3" placeholder="Take a note..."></textarea>
                    <input type="text" id="inlineLabels" name="labels" class="form-control" placeholder="Labels (comma-separated, e.g., work,personal)" />
                    <div class="form-actions">
                        <input type="file" id="image-upload" name="image" class="form-control" accept="image/*" multiple />
                        <button type="button" id="inlineShareButton" class="btn btn-info" onclick="openShareModal(window.currentNoteId)" style="display: none;">Share</button>
                        <button type="button" class="btn btn-secondary" onclick="resetForm()">Clear</button>
                        <div id="save-indicator"></div>
                    </div>
                </form>
            </div>

            <!-- Notes Grid -->
            <div class="notes-container" id="notesGrid">
                <div class="note-card create-note-card" onclick="showInlineForm()">
                    <div class="plus-icon">+</div>
                    <p>Create New Note</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Note Creation Modal -->
    <div class="modal fade" id="createNoteModal" tabindex="-1" aria-labelledby="createNoteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createNoteModalLabel">Create Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="modalNoteForm">
                        <div class="mb-3">
                            <input type="text" class="form-control" id="modalTitle" placeholder="Title" required>
                        </div>
                        <div class="mb-3">
                            <textarea class="form-control" id="modalContent" rows="4" placeholder="Content" required></textarea>
                        </div>
                        <div class="mb-3">
                            <input type="text" class="form-control" id="modalLabels" placeholder="Labels (comma-separated, e.g., work,personal)">
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm()" data-bs-dismiss="modal">Clear</button>
                            <button type="button" class="btn btn-primary" onclick="saveNoteFromModal()">Save</button>
                            <button type="button" class="btn btn-info" id="modalShareButton" onclick="openShareModal(window.currentNoteId)" style="display: none;">Share</button>
                            <div id="save-indicator"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Upload Modal -->
    <div class="modal fade" id="imageUploadModal" tabindex="-1" aria-labelledby="imageUploadModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageUploadModalLabel">Add Images to Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="file" class="form-control mb-3" id="imageInput" accept="image/*" multiple>
                    <div id="imagePreview" class="image-preview-grid"></div>
                    <button type="button" class="btn btn-primary mt-3" id="uploadImagesBtn">Add Images</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Viewer Modal -->
    <div class="modal fade" id="imageViewerModal" tabindex="-1" aria-labelledby="imageViewerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageViewerModalLabel">View Image</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="viewerImage" src="" alt="Full-size note image" style="max-width: 100%; max-height: 80vh; object-fit: contain;">
                </div>
            </div>
        </div>
    </div>

    <!-- Label Management Modal -->
    <div class="modal fade" id="labelManagementModal" tabindex="-1" aria-labelledby="labelManagementModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="labelManagementModalLabel">Manage Labels</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="label-input-group mb-3">
                        <input type="text" class="form-control" id="newLabelInput" placeholder="Create new label">
                        <button type="button" class="btn btn-primary" id="addLabelBtn" onclick="addLabel()">Add</button>
                    </div>
                    <div id="labelsList" class="labels-list"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Note Labels Modal -->
    <div class="modal fade" id="noteLabelsModal" tabindex="-1" aria-labelledby="noteLabelsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="noteLabelsModalLabel">Manage Note Labels</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="noteLabelsCheckboxes" class="note-labels-checkboxes"></div>
                    <button type="button" class="btn btn-primary mt-3" id="saveNoteLabelsBtn" onclick="saveNoteLabels()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Modal -->
    <div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="shareModalLabel">Share Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="shareEmails" class="form-label">Recipient Emails (comma-separated):</label>
                        <input type="text" class="form-control" id="shareEmails" placeholder="email1@example.com,email2@example.com">
                    </div>
                    <div class="mb-3">
                        <label for="sharePermission" class="form-label">Permission:</label>
                        <select class="form-select" id="sharePermission">
                            <option value="read">Read</option>
                            <option value="edit">Edit</option>
                        </select>
                    </div>
                    <div id="share-settings" class="share-settings"></div>
                    <button type="button" class="btn btn-primary" onclick="shareNote()">Share</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="closeShareModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rename Label Modal -->
    <div class="modal fade" id="renameLabelModal" tabindex="-1" aria-labelledby="renameLabelModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="renameLabelModalLabel">Rename Label</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="renameLabelOld" class="form-label">Current Name:</label>
                        <input type="text" class="form-control" id="renameLabelOld" readonly />
                    </div>
                    <div class="mb-3">
                        <label for="renameLabelNew" class="form-label">New Name:</label>
                        <input type="text" class="form-control" id="renameLabelNew" placeholder="Enter new label name" />
                    </div>
                    <button type="button" class="btn btn-primary" onclick="renameLabel()">Rename</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="closeRenameLabelModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Password Modal -->
    <div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="passwordModalLabel">Enter Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="password" class="form-control mb-3" id="notePassword" placeholder="Password">
                    <button type="button" class="btn btn-primary" id="passwordSubmit">Submit</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>