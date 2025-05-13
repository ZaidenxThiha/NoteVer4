// Define htmlspecialchars globally to ensure availability
function htmlspecialchars(str) {
  if (typeof str !== "string") return "";
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

// Define nl2br globally, using htmlspecialchars
function nl2br(str) {
  return htmlspecialchars(str).replace(/(?:\r\n|\r|\n)/g, "<br>");
}
let selectedLabel = "";
let isTrashView = false;

function syncForms(direction = "modal-to-inline") {
  const inlineTitle = document.getElementById("inlineTitle");
  const inlineContent = document.getElementById("inlineContent");
  const inlineLabels = document.getElementById("inlineLabels");
  const modalTitle = document.getElementById("modalTitle");
  const modalContent = document.getElementById("modalContent");
  const modalLabels = document.getElementById("modalLabels");

  if (
    inlineTitle &&
    inlineContent &&
    inlineLabels &&
    modalTitle &&
    modalContent &&
    modalLabels
  ) {
    if (direction === "modal-to-inline") {
      inlineTitle.value = modalTitle.value;
      inlineContent.value = modalContent.value;
      inlineLabels.value = modalLabels.value;
      AutoSave.flush();
    } else {
      modalTitle.value = inlineTitle.value;
      modalContent.value = inlineContent.value;
      modalLabels.value = inlineLabels.value;
    }
  }
}

function resetForm() {
  document.getElementById("modalNoteForm").reset();
  document.getElementById("inlineNoteForm").reset();
  document.getElementById("inlineTitle").value = "";
  document.getElementById("inlineContent").value = "";
  document.getElementById("inlineLabels").value = "";
  document.getElementById("modalTitle").value = "";
  document.getElementById("modalContent").value = "";
  document.getElementById("modalLabels").value = "";
  document.getElementById("note-form-container").style.display = "none";
  window.currentNoteId = null;
  AutoSave.pendingImages = [];
  AutoSave.lastSavedData = null;
  window.history.replaceState({}, "", "notes_frontend.php");
  const modal = bootstrap.Modal.getInstance(
    document.getElementById("createNoteModal")
  );
  if (modal) {
    if (document.activeElement) document.activeElement.blur();
    modal.hide();
    const createNoteCard = document.querySelector(".create-note-card");
    if (createNoteCard) createNoteCard.focus();
  }
  loadNotes();
}

function showInlineForm() {
  console.log("Showing inline note form");
  const formContainer = document.getElementById("note-form-container");
  formContainer.style.display = "block";
  document.getElementById("inlineTitle").focus();
}

function editNote(id) {
  console.log(`Editing note with ID: ${id}`);
  API.loadNote(id)
    .then((note) => {
      if (note && note.success) {
        if (
          !note.is_owner &&
          (!note.shared_permissions ||
            !note.shared_permissions.includes("edit"))
        ) {
          if (typeof UI !== "undefined") {
            UI.showError("You do not have permission to edit this note");
          } else {
            alert("You do not have permission to edit this note");
          }
          return;
        }
        document.getElementById("inlineTitle").value = note.title || "";
        document.getElementById("inlineContent").value = note.content || "";
        document.getElementById("inlineLabels").value = note.labels || "";
        document.getElementById("modalTitle").value = note.title || "";
        document.getElementById("modalContent").value = note.content || "";
        document.getElementById("modalLabels").value = note.labels || "";
        window.currentNoteId = id;
        document.getElementById("inlineShareButton").style.display =
          note.is_owner ? "inline-block" : "none";
        document.getElementById("modalShareButton").style.display =
          note.is_owner ? "inline-block" : "none";
        if (note.is_owner) {
          updateShareSettings(
            note.shared_emails,
            note.shared_permissions,
            note.shared_user_ids
          );
        }
        window.history.replaceState(
          {},
          "",
          `notes_frontend.php?action=edit&id=${id}`
        );
        showInlineForm();
      } else {
        if (typeof UI !== "undefined") {
          UI.showError(note.error || "Failed to load note");
        } else {
          console.error(
            "Failed to load note: " + (note.error || "Unknown error")
          );
          alert("Failed to load note: " + (note.error || "Unknown error"));
        }
      }
    })
    .catch((error) => {
      if (
        error.message.includes("403") &&
        error.cause &&
        error.cause.is_locked
      ) {
        promptPassword(id, "edit", () => editNote(id));
      } else {
        if (typeof UI !== "undefined") {
          UI.showError("Network error during note loading: " + error.message);
        } else {
          console.error("Network error during note loading: " + error.message);
          alert("Network error during note loading: " + error.message);
        }
      }
    });
}

function saveNoteFromModal() {
  AutoSave.flush();
  const modal = bootstrap.Modal.getInstance(
    document.getElementById("createNoteModal")
  );
  if (modal) {
    if (document.activeElement) document.activeElement.blur();
    modal.hide();
    const createNoteCard = document.querySelector(".create-note-card");
    if (createNoteCard) createNoteCard.focus();
  }
  document.getElementById("note-form-container").style.display = "none";
  loadNotes();
}

// Updated updateNoteUI to support note card edit mode
// Updated updateNoteUI with htmlspecialchars
function updateNoteUI(noteId, noteData, isAccessible, isLocked) {
  const noteElement = document.querySelector(`.note-card[data-id="${noteId}"]`);
  if (!noteElement) {
    console.warn(`Note element with ID ${noteId} not found`);
    loadNotes();
    return;
  }

  noteElement.className = `note-card existing-note-card ${
    noteData.is_pinned ? "pinned" : ""
  } ${isTrashView ? "" : "cursor-pointer"}`;
  let html = `
    <button class="pin-btn ${
      noteData.is_pinned ? "pinned" : ""
    }" onclick="pinNote(${noteId}, ${
    noteData.is_pinned ? 0 : 1
  }); event.stopPropagation();" aria-label="${
    noteData.is_pinned ? "Unpin" : "Pin"
  } note">
      <i class="fas fa-thumbtack"></i>
    </button>
    <button class="delete-btn" onclick="${
      isTrashView ? "deleteNote" : "trashNote"
    }(${noteId}); event.stopPropagation();" aria-label="${
    isTrashView ? "Delete permanently" : "Move to trash"
  }">
      <i class="fas fa-trash"></i>
    </button>
  `;
  if (isTrashView) {
    html += `<button class="restore-btn" onclick="restoreNote(${noteId}); event.stopPropagation();" aria-label="Restore note" title="Restore note"><i class="fas fa-undo"></i></button>`;
  } else {
    html += `
      <button class="image-btn" onclick="openImageModal(${noteId}); event.stopPropagation();" aria-label="Add image" title="Add image"><i class="fas fa-image"></i></button>
      <button class="label-btn" onclick="openNoteLabelsModal(${noteId}); event.stopPropagation();" aria-label="Manage labels" title="Manage labels"><i class="fas fa-tag"></i></button>
    `;
  }
  html += `<div class="note-title-container">
    <h6>${htmlspecialchars(noteData.title || "Untitled")}</h6>
  `;

  if (isAccessible) {
    if (noteData.labels) {
      html += '<div class="note-labels">';
      noteData.labels.split(",").forEach((label) => {
        html += `<span class="note-label-tag">${htmlspecialchars(
          label
        )}</span>`;
      });
      html += "</div>";
    }
    html += `<p>${nl2br(htmlspecialchars(noteData.content || ""))}</p>`;
    if (noteData.images && noteData.images.length) {
      html += '<div class="note-images">';
      noteData.images.forEach((image) => {
        html += `<img src="${htmlspecialchars(
          image
        )}" class="note-image-thumb" onclick="openImageViewer('${htmlspecialchars(
          image
        )}')" alt="Note image">`;
      });
      html += "</div>";
    }
    if (noteData.shared_emails && noteData.shared_emails.length) {
      html += `<div class="note-shared"><span>Shared with: ${htmlspecialchars(
        noteData.shared_emails.join(", ")
      )}</span></div>`;
    }
  } else {
    html += `<p>Enter password to view content</p>`;
  }
  html += `</div>`;
  html += `<small>Last updated: ${noteData.updated_at}</small>`;

  if (noteData.is_owner && !isTrashView) {
    html += `
      <div class="dropdown">
        <button class="btn settings-button" onclick="toggleDropdown(event, 'dropdown-${noteId}'); event.stopPropagation();" aria-label="Note settings">
          <i class="fas fa-cog"></i>
        </button>
        <div class="dropdown-content" id="dropdown-${noteId}">
          <a href="#" onclick="openShareModal(${noteId}); return false;">📤 Share</a>
          ${
            isAccessible
              ? isLocked
                ? `<a href="#" onclick="relockNote(${noteId}); return false;">🔐 Relock</a>
                   <a href="#" onclick="openPasswordModal(${noteId}, 'change'); return false;">🔐 Change Password</a>
                   <a href="#" onclick="removePassword(${noteId}); return false;">🔓 Remove Password</a>`
                : `<a href="#" onclick="openPasswordModal(${noteId}, 'set'); return false;">🔒 Lock Note</a>`
              : `<a href="#" onclick="promptPassword(${noteId}, 'access'); return false;">🔓 Unlock</a>`
          }
        </div>
      </div>
    `;
  } else if (!isAccessible) {
    html += `<button class="btn" onclick="promptPassword(${noteId}, 'access'); event.stopPropagation();" aria-label="Unlock note">🔓 Unlock</button>`;
  }

  noteElement.innerHTML = html;
}

// Updated nl2br with htmlspecialchars
function nl2br(str) {
  return htmlspecialchars(str).replace(/(?:\r\n|\r|\n)/g, "<br>");
}

function openImageModal(noteId) {
  console.log(`Opening image upload modal for note ID: ${noteId}`);
  window.currentNoteId = noteId;
  const modal = new bootstrap.Modal(
    document.getElementById("imageUploadModal")
  );
  const imagePreview = document.getElementById("imagePreview");
  imagePreview.innerHTML = "";
  modal.show();

  const imageInput = document.getElementById("imageInput");
  imageInput.onchange = () => {
    imagePreview.innerHTML = "";
    Array.from(imageInput.files).forEach((file) => {
      const reader = new FileReader();
      reader.onload = (e) => {
        const div = document.createElement("div");
        div.className = "image-preview-item";
        div.innerHTML = `
          <img src="${e.target.result}" alt="Image preview">
          <button class="remove-preview" onclick="this.parentElement.remove()">×</button>
        `;
        imagePreview.appendChild(div);
      };
      reader.readAsDataURL(file);
    });
  };

  document.getElementById("uploadImagesBtn").onclick = () => {
    const imageInput = document.getElementById("imageInput");
    const files = imageInput.files;
    if (files.length === 0) {
      UI.showError("No images selected");
      return;
    }
    // For simplicity, handle one file at a time
    const file = files[0]; // Take the first file
    const formData = new FormData();
    formData.append("image", file); // Use 'image' instead of 'image[]'
    formData.append("note_id", noteId);
    API.uploadImage(formData)
      .then((data) => {
        if (data.success) {
          modal.hide();
          loadNotes();
        } else {
          UI.showError(data.error || "Failed to upload images");
        }
      })
      .catch((error) => {
        UI.showError("Network error during image upload: " + error.message);
      });
  };
}

function openShareModal(noteId) {
  console.log(`Opening share modal for note ID: ${noteId}`);
  API.loadNote(noteId)
    .then((note) => {
      if (note && note.success) {
        window.currentNoteId = noteId;
        const modal = new bootstrap.Modal(
          document.getElementById("shareModal")
        );
        updateShareSettings(
          note.shared_emails,
          note.shared_permissions,
          note.shared_user_ids
        );
        modal.show();
      } else {
        UI.showError(note.error || "Failed to load note for sharing");
      }
    })
    .catch((error) => {
      if (
        error.message.includes("403") &&
        error.cause &&
        error.cause.is_locked
      ) {
        promptPassword(noteId, "share", () => openShareModal(noteId));
      } else {
        UI.showError("Network error during note loading: " + error.message);
      }
    });
}

function closeShareModal() {
  const modal = bootstrap.Modal.getInstance(
    document.getElementById("shareModal")
  );
  if (modal) modal.hide();
  document.getElementById("shareEmails").value = "";
  document.getElementById("sharePermission").value = "read";
}

function updateShareSettings(emails, permissions, userIds) {
  const shareSettings = document.getElementById("share-settings");
  shareSettings.innerHTML = "";
  if (emails && emails.length) {
    emails.forEach((email, index) => {
      const userId = userIds[index];
      const permission = permissions[index];
      const div = document.createElement("div");
      div.className = "share-setting";
      div.innerHTML = `
        <span>${email} (${permission})</span>
        <select onchange="updatePermission(${userId}, this.value)">
          <option value="read" ${
            permission === "read" ? "selected" : ""
          }>Read</option>
          <option value="edit" ${
            permission === "edit" ? "selected" : ""
          }>Edit</option>
        </select>
        <button class="btn btn-danger btn-sm" onclick="revokeShare(${userId})">Revoke</button>
      `;
      shareSettings.appendChild(div);
    });
  }
}

function shareNote() {
  console.log(`Sharing note ID: ${window.currentNoteId}`);
  const emails = document.getElementById("shareEmails").value.trim();
  const permission = document.getElementById("sharePermission").value;
  if (!window.currentNoteId || !emails) {
    UI.showError("Note ID or emails required");
    return;
  }
  API.shareNote(window.currentNoteId, emails, permission)
    .then((data) => {
      if (data.success) {
        UI.showSaved();
        closeShareModal();
        loadNotes();
      } else {
        UI.showError(data.error || "Failed to share note");
      }
    })
    .catch((error) => {
      UI.showError("Network error during sharing: " + error.message);
    });
}

function updatePermission(userId, permission) {
  console.log(`Updating permission for user ID: ${userId} to ${permission}`);
  if (!window.currentNoteId) {
    UI.showError("No note selected");
    return;
  }
  API.updateShare(window.currentNoteId, userId, permission)
    .then((data) => {
      if (data.success) {
        UI.showSaved();
        loadNotes();
      } else {
        UI.showError(data.error || "Failed to update permission");
      }
    })
    .catch((error) => {
      UI.showError("Network error during permission update: " + error.message);
    });
}

function revokeShare(userId) {
  console.log(`Revoking share for user ID: ${userId}`);
  if (!window.currentNoteId) {
    UI.showError("No note selected");
    return;
  }
  if (confirm("Revoke sharing for this user?")) {
    API.revokeShare(window.currentNoteId, userId)
      .then((data) => {
        if (data.success) {
          UI.showSaved();
          loadNotes();
          API.loadNote(window.currentNoteId).then((note) => {
            if (note && note.success) {
              updateShareSettings(
                note.shared_emails,
                note.shared_permissions,
                note.shared_user_ids
              );
            }
          });
        } else {
          UI.showError(data.error || "Failed to revoke sharing");
        }
      })
      .catch((error) => {
        UI.showError("Network error during revoke: " + error.message);
      });
  }
}

function openImageViewer(imageUrl) {
  console.log(`Opening image viewer for URL: ${imageUrl}`);
  const modal = new bootstrap.Modal(
    document.getElementById("imageViewerModal")
  );
  document.getElementById("viewerImage").src = imageUrl;
  modal.show();
}

function openNoteLabelsModal(noteId) {
  console.log(`Opening note labels modal for note ID: ${noteId}`);
  window.currentNoteId = noteId;
  API.loadNote(noteId).then((note) => {
    if (note && note.success) {
      API.loadLabels().then((data) => {
        if (data.success) {
          const checkboxes = document.getElementById("noteLabelsCheckboxes");
          checkboxes.innerHTML = "";
          data.labels.forEach((label) => {
            const div = document.createElement("div");
            div.className = "note-label-checkbox";
            const isChecked =
              note.labels && note.labels.split(",").includes(label);
            div.innerHTML = `
              <input type="checkbox" id="label-${label}" value="${label}" ${
              isChecked ? "checked" : ""
            }>
              <label for="label-${label}">${htmlspecialchars(label)}</label>
            `;
            checkboxes.appendChild(div);
          });
          const modal = new bootstrap.Modal(
            document.getElementById("noteLabelsModal")
          );
          modal.show();
        } else {
          UI.showError(data.error || "Failed to load labels");
        }
      });
    } else {
      UI.showError(note.error || "Failed to load note");
    }
  });
}

function saveNoteLabels() {
  console.log(`Saving labels for note ID: ${window.currentNoteId}`);
  const checkboxes = document.querySelectorAll(
    "#noteLabelsCheckboxes input:checked"
  );
  const labels = Array.from(checkboxes)
    .map((cb) => cb.value)
    .join(",");
  if (!window.currentNoteId) {
    UI.showError("No note selected");
    return;
  }
  API.saveNote(window.currentNoteId, { labels })
    .then((data) => {
      if (data.success) {
        UI.showSaved();
        loadNotes();
        const modal = bootstrap.Modal.getInstance(
          document.getElementById("noteLabelsModal")
        );
        if (modal) modal.hide();
      } else {
        UI.showError(data.error || "Failed to save labels");
      }
    })
    .catch((error) => {
      UI.showError("Network error during label save: " + error.message);
    });
}

function loadNotes(
  search = "",
  label = selectedLabel,
  isTrashed = isTrashView ? 1 : 0
) {
  console.log(
    `Loading notes with search: '${search}', label: '${label}', trash: ${isTrashed}`
  );
  API.loadNotes(search, label, isTrashed)
    .then((html) => {
      const container = document.getElementById("notesGrid");
      container.innerHTML = `
        <div class="note-card create-note-card" onclick="showInlineForm()" style="${
          isTrashView ? "display: none;" : ""
        }">
          <div class="plus-icon">+</div>
          <p>Create New Note</p>
        </div>
      `;
      container.insertAdjacentHTML("beforeend", html);
      console.log("Notes loaded successfully via AJAX");
      attachSidebarEventListeners();
    })
    .catch((error) => {
      UI.showError("Failed to load notes: " + error.message);
      console.error("Load notes error:", error);
    });
}

function deleteNote(id) {
  console.log(`Deleting note ID: ${id}`);
  API.loadNote(id)
    .then((note) => {
      if (note && note.success) {
        if (confirm("Permanently delete this note?")) {
          API.deleteNote(id)
            .then((data) => {
              if (data.success) {
                loadNotes();
              } else {
                UI.showError(data.error || "Failed to delete note");
              }
            })
            .catch((error) => {
              UI.showError("Network error during deletion: " + error.message);
            });
        }
      } else {
        UI.showError(note.error || "Failed to load note for deletion");
      }
    })
    .catch((error) => {
      if (
        error.message.includes("403") &&
        error.cause &&
        error.cause.is_locked
      ) {
        promptPassword(id, "delete", () => {
          if (confirm("Permanently delete this note?")) {
            API.deleteNote(id)
              .then((data) => {
                if (data.success) {
                  loadNotes();
                } else {
                  UI.showError(data.error || "Failed to delete note");
                }
              })
              .catch((retryError) => {
                UI.showError(
                  "Network error during deletion: " + retryError.message
                );
              });
          }
        });
      } else {
        UI.showError("Network error during note loading: " + error.message);
      }
    });
}

function trashNote(id) {
  console.log(`Trashing note ID: ${id}`);
  API.loadNote(id)
    .then((note) => {
      if (note && note.success) {
        if (confirm("Move this note to trash?")) {
          API.trashNote(id)
            .then((data) => {
              if (data.success) {
                loadNotes();
              } else {
                UI.showError(data.error || "Failed to move note to trash");
              }
            })
            .catch((error) => {
              UI.showError("Network error during trashing: " + error.message);
            });
        }
      } else {
        UI.showError(note.error || "Failed to load note for trashing");
      }
    })
    .catch((error) => {
      if (
        error.message.includes("403") &&
        error.cause &&
        error.cause.is_locked
      ) {
        promptPassword(id, "trash", () => {
          if (confirm("Move this note to trash?")) {
            API.trashNote(id)
              .then((data) => {
                if (data.success) {
                  loadNotes();
                } else {
                  UI.showError(data.error || "Failed to move note to trash");
                }
              })
              .catch((retryError) => {
                UI.showError(
                  "Network error during trashing: " + retryError.message
                );
              });
          }
        });
      } else {
        UI.showError("Network error during note loading: " + error.message);
      }
    });
}

function restoreNote(id) {
  console.log(`Restoring note ID: ${id}`);
  API.loadNote(id)
    .then((note) => {
      if (note && note.success) {
        if (confirm("Restore this note?")) {
          API.restoreNote(id)
            .then((data) => {
              if (data.success) {
                loadNotes();
              } else {
                UI.showError(data.error || "Failed to restore note");
              }
            })
            .catch((error) => {
              UI.showError(
                "Network error during restoration: " + error.message
              );
            });
        }
      } else {
        UI.showError(note.error || "Failed to load note for restoration");
      }
    })
    .catch((error) => {
      if (
        error.message.includes("403") &&
        error.cause &&
        error.cause.is_locked
      ) {
        promptPassword(id, "restore", () => {
          if (confirm("Restore this note?")) {
            API.restoreNote(id)
              .then((data) => {
                if (data.success) {
                  loadNotes();
                } else {
                  UI.showError(data.error || "Failed to restore note");
                }
              })
              .catch((retryError) => {
                UI.showError(
                  "Network error during restoration: " + retryError.message
                );
              });
          }
        });
      } else {
        UI.showError("Network error during note loading: " + error.message);
      }
    });
}

function pinNote(id, pin) {
  console.log(`Pinning/unpinning note ID: ${id}, pin: ${pin}`);
  API.loadNote(id)
    .then((note) => {
      if (note && note.success) {
        API.pinNote(id, pin)
          .then((data) => {
            if (data.success) {
              loadNotes();
            } else {
              UI.showError(data.error || "Failed to update pin status");
            }
          })
          .catch((error) => {
            UI.showError("Network error during pin action: " + error.message);
          });
      } else {
        UI.showError(note.error || "Failed to load note for pinning");
      }
    })
    .catch((error) => {
      if (
        error.message.includes("403") &&
        error.cause &&
        error.cause.is_locked
      ) {
        promptPassword(id, "pin", () => {
          API.pinNote(id, pin)
            .then((data) => {
              if (data.success) {
                loadNotes();
              } else {
                UI.showError(data.error || "Failed to update pin status");
              }
            })
            .catch((retryError) => {
              UI.showError(
                "Network error during pin action: " + retryError.message
              );
            });
        });
      } else {
        UI.showError("Network error during note loading: " + error.message);
      }
    });
}

function searchNotes() {
  const query = document.getElementById("searchInput").value.trim();
  loadNotes(query);
}

function selectLabel(label) {
  if (isTrashView) return;
  selectedLabel = label === selectedLabel ? "" : label;
  console.log(`Selected label: '${selectedLabel}'`);
  document.querySelectorAll(".label-filter-btn").forEach((item) => {
    item.classList.toggle("active", item.dataset.label === selectedLabel);
  });
  loadNotes();
}

function addLabel() {
  console.log("Adding new label");
  const labelName = document.getElementById("newLabelInput").value.trim();
  if (!labelName) {
    UI.showError("Label name required");
    return;
  }
  API.addLabel(labelName)
    .then((data) => {
      if (data.success) {
        document.getElementById("newLabelInput").value = "";
        loadLabels();
      } else {
        UI.showError(data.error || "Failed to add label");
      }
    })
    .catch((error) => {
      UI.showError("Network error during label addition: " + error.message);
    });
}

function openRenameLabelModal(oldName) {
  console.log(`Opening rename label modal for: ${oldName}`);
  document.getElementById("renameLabelOld").value = oldName;
  document.getElementById("renameLabelNew").value = oldName;
  const modal = new bootstrap.Modal(
    document.getElementById("renameLabelModal")
  );
  modal.show();
}

function closeRenameLabelModal() {
  const modal = bootstrap.Modal.getInstance(
    document.getElementById("renameLabelModal")
  );
  if (modal) modal.hide();
  document.getElementById("renameLabelOld").value = "";
  document.getElementById("renameLabelNew").value = "";
}

function renameLabel() {
  console.log("Renaming label");
  const oldName = document.getElementById("renameLabelOld").value.trim();
  const newName = document.getElementById("renameLabelNew").value.trim();
  if (!oldName || !newName) {
    UI.showError("Old and new label names required");
    return;
  }
  API.renameLabel(oldName, newName)
    .then((data) => {
      if (data.success) {
        closeRenameLabelModal();
        loadLabels();
        loadNotes();
      } else {
        UI.showError(data.error || "Failed to rename label");
      }
    })
    .catch((error) => {
      UI.showError("Network error during label renaming: " + error.message);
    });
}

function deleteLabel(labelName) {
  console.log(`Deleting label: ${labelName}`);
  if (confirm(`Delete label "${labelName}"?`)) {
    API.deleteLabel(labelName)
      .then((data) => {
        if (data.success) {
          loadLabels();
          if (selectedLabel === labelName) {
            selectedLabel = "";
            loadNotes();
          }
        } else {
          UI.showError(data.error || "Failed to delete label");
        }
      })
      .catch((error) => {
        UI.showError("Network error during label deletion: " + error.message);
      });
  }
}

function openPasswordModal(noteId, action) {
  console.log(
    `Opening password modal for note ID: ${noteId}, action: ${action}`
  );
  UI.showPasswordPrompt(noteId, action, (password) => {
    API.setPassword(noteId, password)
      .then((data) => {
        if (data.success) {
          UI.showSaved();
          API.loadNote(noteId).then((note) => {
            if (note && note.success) {
              updateNoteUI(
                noteId,
                {
                  title: note.title,
                  content: note.content,
                  labels: note.labels,
                  updated_at: note.updated_at,
                  is_pinned: note.is_pinned || false,
                  is_owner: note.is_owner,
                  shared_emails: note.shared_emails || [],
                  shared_permissions: note.shared_permissions || [],
                  shared_user_ids: note.shared_user_ids || [],
                  images: note.images || [],
                },
                true,
                note.is_locked
              );
            } else {
              loadNotes();
            }
          });
        } else {
          UI.showError(data.error || "Failed to set password");
        }
      })
      .catch((error) => {
        UI.showError("Network error during password setting: " + error.message);
      });
  });
}

function removePassword(noteId) {
  console.log(`Removing password for note ID: ${noteId}`);
  if (confirm("Remove password protection for this note?")) {
    API.removePassword(noteId)
      .then((data) => {
        if (data.success) {
          UI.showSaved();
          API.loadNote(noteId).then((note) => {
            if (note && note.success) {
              updateNoteUI(
                noteId,
                {
                  title: note.title,
                  content: note.content,
                  labels: note.labels,
                  updated_at: note.updated_at,
                  is_pinned: note.is_pinned || false,
                  is_owner: note.is_owner,
                  shared_emails: note.shared_emails || [],
                  shared_permissions: note.shared_permissions || [],
                  shared_user_ids: note.shared_user_ids || [],
                  images: note.images || [],
                },
                true,
                false
              );
            } else {
              loadNotes();
            }
          });
        } else {
          UI.showError(data.error || "Failed to remove password");
        }
      })
      .catch((error) => {
        UI.showError("Network error during password removal: " + error.message);
      });
  }
}

function relockNote(noteId) {
  console.log(`Relocking note ID: ${noteId}`);
  if (
    confirm(
      "Relock this note? You will need to enter the password again to view it."
    )
  ) {
    API.relock(noteId)
      .then((data) => {
        if (data.success) {
          UI.showSaved();
          updateNoteUI(
            noteId,
            {
              title: document.getElementById("inlineTitle")?.value || "",
              content: "",
              labels: "",
              updated_at: new Date().toISOString(),
              is_pinned: false,
              is_owner: true,
              shared_emails: [],
              shared_permissions: [],
              shared_user_ids: [],
              images: [],
            },
            false,
            true
          );
        } else {
          UI.showError(data.error || "Failed to relock note");
        }
      })
      .catch((error) => {
        UI.showError("Network error during relock: " + error.message);
      });
  }
}

function promptPassword(
  noteId,
  action,
  successCallback = () => {},
  cancelCallback = () => {}
) {
  console.log(`Prompting password for note ID: ${noteId}, action: ${action}`);
  if (typeof successCallback !== "function") {
    console.warn(
      "Invalid success callback provided to promptPassword, using default no-op"
    );
    successCallback = () => {};
  }
  if (typeof cancelCallback !== "function") {
    console.warn(
      "Invalid cancel callback provided to promptPassword, using default no-op"
    );
    cancelCallback = () => {};
  }
  UI.showPasswordPrompt(
    noteId,
    action,
    (password) => {
      API.verifyPassword(noteId, password)
        .then((data) => {
          if (data.success) {
            API.loadNote(noteId).then((note) => {
              if (note && note.success) {
                updateNoteUI(
                  noteId,
                  {
                    title: note.title,
                    content: note.content,
                    labels: note.labels,
                    updated_at: note.updated_at,
                    is_pinned: note.is_pinned || false,
                    is_owner: note.is_owner,
                    shared_emails: note.shared_emails || [],
                    shared_permissions: note.shared_permissions || [],
                    shared_user_ids: note.shared_user_ids || [],
                    images: note.images || [],
                  },
                  true,
                  note.is_locked
                );
                successCallback(noteId);
              } else {
                UI.showError(
                  note.error || "Failed to load note after unlocking"
                );
                loadNotes();
              }
            });
          } else {
            UI.showError(data.error || "Failed to verify password");
          }
        })
        .catch((error) => {
          UI.showError(
            "Network error during password verification: " + error.message
          );
        });
    },
    cancelCallback
  );
}

function loadLabels() {
  console.log("Loading labels");
  fetch("notes_backend.php?action=labels")
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        const labelList = document.getElementById("labelFiltersList");
        labelList.innerHTML = "";
        data.labels.forEach((label) => {
          const li = document.createElement("button");
          li.className = `label-filter-btn ${
            selectedLabel === label ? "active" : ""
          }`;
          li.dataset.label = label;
          li.innerHTML = `
            <i class="fas fa-tag"></i>
            <span>${htmlspecialchars(label)}</span>
          `;
          li.onclick = () => selectLabel(label);
          labelList.appendChild(li);
        });
        const labelsList = document.getElementById("labelsList");
        labelsList.innerHTML = "";
        data.labels.forEach((label) => {
          const div = document.createElement("div");
          div.className = "label-item";
          div.innerHTML = `
            <span class="label-name">${htmlspecialchars(label)}</span>
            <div class="label-actions">
              <button class="edit-btn" onclick="openRenameLabelModal('${htmlspecialchars(
                label
              )}')"><i class="fas fa-edit"></i></button>
              <button class="delete-btn" onclick="deleteLabel('${htmlspecialchars(
                label
              )}')"><i class="fas fa-trash"></i></button>
            </div>
          `;
          labelsList.appendChild(div);
        });
      } else {
        if (typeof UI !== "undefined") {
          UI.showError(data.error || "Failed to load labels");
        } else {
          console.error(
            "Failed to load labels: " + (data.error || "Unknown error")
          );
          alert("Failed to load labels: " + (data.error || "Unknown error"));
        }
      }
    })
    .catch((error) => {
      if (typeof UI !== "undefined") {
        UI.showError("Network error during label loading: " + error.message);
      } else {
        console.error("Network error during label loading: " + error.message);
        alert("Network error during label loading: " + error.message);
      }
    });
}

function toggleSidebar() {
  console.log("Toggling sidebar");
  const sidebar = document.getElementById("sidebar");
  const mainContent = document.querySelector(".main-content");
  sidebar.classList.toggle("expanded");
  mainContent.classList.toggle("sidebar-expanded");
  localStorage.setItem(
    "sidebarExpanded",
    sidebar.classList.contains("expanded") ? "true" : "false"
  );
}
function toggleDropdown(event, dropdownId) {
  event.preventDefault();
  const dropdown = document.getElementById(dropdownId);
  const isVisible = dropdown.style.display === "block";
  document
    .querySelectorAll(".dropdown-content")
    .forEach((dd) => (dd.style.display = "none"));
  dropdown.style.display = isVisible ? "none" : "block";
}

function goToTrashView() {
  console.log("Navigating to trash view");
  isTrashView = true;
  selectedLabel = "";
  document.getElementById("notesBtn").classList.remove("active");
  document.getElementById("labelsBtn").classList.remove("active");
  document.getElementById("trashBtn").classList.add("active");
  document.querySelector(".header-bar h5").textContent = "Trash";
  document.getElementById("labelFiltersList").style.display = "none";
  document.getElementById("searchInput").value = "";
  loadNotes();
}

function goToHomepage() {
  console.log("Navigating to homepage");
  isTrashView = false;
  selectedLabel = "";
  document.getElementById("notesBtn").classList.add("active");
  document.getElementById("labelsBtn").classList.remove("active");
  document.getElementById("trashBtn").classList.remove("active");
  document.querySelector(".header-bar h5").textContent = "ZeroNote";
  document.getElementById("labelFiltersList").style.display = "";
  document.getElementById("searchInput").value = "";
  loadNotes();
}

// Updated goToSavedView to prevent editing
function goToSavedView() {
  console.log("Navigating to saved view");
  isTrashView = false;
  selectedLabel = "";
  window.currentNoteId = null; // Reset currentNoteId to prevent editing
  document.getElementById("notesBtn").classList.remove("active");
  document.getElementById("labelsBtn").classList.remove("active");
  document.getElementById("trashBtn").classList.remove("active");
  document
    .querySelector('.sidebar-item[data-view="saved"]')
    .classList.add("active");
  document.querySelector(".header-bar h5").textContent = "Saved Notes";
  document.getElementById("labelFiltersList").style.display = "none";
  document.getElementById("searchInput").value = "";
  document.getElementById("note-form-container").style.display = "none"; // Hide form to prevent editing
  window.history.replaceState({}, "", "notes_frontend.php");
  loadNotes("", "", 0); // Load non-trashed notes
}

// Updated attachSidebarEventListeners to stop propagation
// Updated attachSidebarEventListeners to stop propagation
function attachSidebarEventListeners() {
  const sidebarToggle = document.getElementById("sidebarToggle");
  const sidebarToggleMobile = document.getElementById("sidebarToggleMobile");
  const notesBtn = document.getElementById("notesBtn");
  const labelsBtn = document.getElementById("labelsBtn");
  const trashBtn = document.getElementById("trashBtn");
  const savedBtn = document.querySelector('.sidebar-item[data-view="saved"]');

  const newSidebarToggle = sidebarToggle.cloneNode(true);
  const newSidebarToggleMobile = sidebarToggleMobile.cloneNode(true);
  const newNotesBtn = notesBtn.cloneNode(true);
  const newLabelsBtn = labelsBtn.cloneNode(true);
  const newTrashBtn = trashBtn.cloneNode(true);
  const newSavedBtn = savedBtn.cloneNode(true);

  sidebarToggle.parentNode.replaceChild(newSidebarToggle, sidebarToggle);
  sidebarToggleMobile.parentNode.replaceChild(
    newSidebarToggleMobile,
    sidebarToggleMobile
  );
  notesBtn.parentNode.replaceChild(newNotesBtn, notesBtn);
  labelsBtn.parentNode.replaceChild(newLabelsBtn, labelsBtn);
  trashBtn.parentNode.replaceChild(newTrashBtn, trashBtn);
  savedBtn.parentNode.replaceChild(newSavedBtn, savedBtn);

  newSidebarToggle.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    toggleSidebar();
  });
  newSidebarToggleMobile.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    toggleSidebar();
  });
  newNotesBtn.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    goToHomepage();
  });
  newLabelsBtn.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (isTrashView) goToHomepage();
    const modal = new bootstrap.Modal(
      document.getElementById("labelManagementModal")
    );
    modal.show();
  });
  newTrashBtn.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    goToTrashView();
  });
  newSavedBtn.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    goToSavedView();
  });

  const isExpanded = localStorage.getItem("sidebarExpanded") === "true";
  const sidebar = document.getElementById("sidebar");
  const mainContent = document.querySelector(".main-content");
  if (isExpanded || window.innerWidth >= 992) {
    sidebar.classList.add("expanded");
    mainContent.classList.add("sidebar-expanded");
  } else {
    sidebar.classList.remove("expanded");
    mainContent.classList.remove("sidebar-expanded");
  }
}

document.addEventListener("DOMContentLoaded", () => {
  console.log("DOM fully loaded, initializing app");
  console.log(
    "htmlspecialchars defined:",
    typeof htmlspecialchars === "function"
  );
  const inlineTitle = document.getElementById("inlineTitle");
  const inlineContent = document.getElementById("inlineContent");
  const inlineLabels = document.getElementById("inlineLabels");
  const modalTitle = document.getElementById("modalTitle");
  const modalContent = document.getElementById("modalContent");
  const modalLabels = document.getElementById("modalLabels");
  const search = document.getElementById("searchInput");
  const newLabelInput = document.getElementById("newLabelInput");
  const sidebarToggle = document.getElementById("sidebarToggle");
  const sidebarToggleMobile = document.getElementById("sidebarToggleMobile");
  const notesBtn = document.getElementById("notesBtn");
  const labelsBtn = document.getElementById("labelsBtn");
  const trashBtn = document.getElementById("trashBtn");
  const toggleViewBtn = document.getElementById("toggleViewBtn");
  const createNoteModal = document.getElementById("createNoteModal");
  const formContainer = document.getElementById("note-form-container");

  if (
    inlineTitle &&
    inlineContent &&
    inlineLabels &&
    modalTitle &&
    modalContent &&
    modalLabels &&
    search &&
    newLabelInput &&
    sidebarToggle &&
    sidebarToggleMobile &&
    notesBtn &&
    labelsBtn &&
    trashBtn &&
    toggleViewBtn &&
    formContainer
  ) {
    console.log("All required DOM elements found");

    if (typeof UI === "undefined") {
      console.error(
        "UI object is not defined. Skipping UI-dependent initialization."
      );
      const errorDiv = document.createElement("div");
      errorDiv.className = "error";
      errorDiv.textContent =
        "Application error: UI components not loaded. Limited functionality available.";
      document.body.prepend(errorDiv);
      if (toggleViewBtn) {
        toggleViewBtn.style.display = "none"; // Hide view toggle button if UI is unavailable
      }
    } else {
      UI.initViewToggle();
    }

    // Initialize core features
    attachSidebarEventListeners();

    if (createNoteModal) {
      createNoteModal.addEventListener("shown.bs.modal", () => {
        const modalTitleInput = document.getElementById("modalTitle");
        if (modalTitleInput) modalTitleInput.focus();
      });
      createNoteModal.addEventListener("hidden.bs.modal", () => {
        if (document.activeElement) document.activeElement.blur();
        const createNoteCard = document.querySelector(".create-note-card");
        if (createNoteCard) createNoteCard.focus();
        syncForms("modal-to-inline");
      });
    }

    search.addEventListener("input", () => {
      console.log("Search input changed");
      clearTimeout(search.timer);
      search.timer = setTimeout(searchNotes, 300);
    });

    AutoSave.init({
      titleInput: inlineTitle,
      contentInput: inlineContent,
      labelInput: inlineLabels,
      modalTitleInput: modalTitle,
      modalContentInput: modalContent,
      modalLabelInput: modalLabels,
      imageInput: document.getElementById("image-upload"),
      onSave: (noteId, data) => {
        if (typeof UI !== "undefined") UI.showSaving();
        return API.saveNote(noteId, data)
          .then((response) => {
            if (typeof UI !== "undefined") UI.showSaved();
            loadLabels();
            loadNotes();
            formContainer.style.display = "none";
            resetForm();
            return response;
          })
          .catch((error) => {
            if (typeof UI !== "undefined") {
              UI.showError("Failed to save note: " + error.message);
            } else {
              console.error("Failed to save note: " + error.message);
              alert("Failed to save note: " + error.message);
            }
            throw error;
          });
      },
      onIdUpdate: (newId) => {
        window.currentNoteId = newId;
        window.history.replaceState({}, "", `notes_frontend.php?id=${newId}`);
      },
      onImageUpload: (formData) => {
        return API.uploadImage(formData);
      },
    });

    inlineTitle.addEventListener("input", () => syncForms("inline-to-modal"));
    inlineContent.addEventListener("input", () => syncForms("inline-to-modal"));
    inlineLabels.addEventListener("input", () => syncForms("inline-to-modal"));
    modalTitle.addEventListener("input", () => syncForms("modal-to-inline"));
    modalContent.addEventListener("input", () => syncForms("modal-to-inline"));
    modalLabels.addEventListener("input", () => syncForms("modal-to-inline"));

    document.addEventListener("click", (e) => {
      const sidebar = document.getElementById("sidebar");
      if (
        window.innerWidth <= 991 &&
        sidebar.classList.contains("expanded") &&
        !sidebar.contains(e.target) &&
        !sidebarToggle.contains(e.target) &&
        !sidebarToggleMobile.contains(e.target)
      ) {
        console.log("Closing sidebar on mobile click outside");
        sidebar.classList.remove("expanded");
        document
          .querySelector(".main-content")
          .classList.remove("sidebar-expanded");
        localStorage.setItem("sidebarExpanded", "false");
      }

      if (
        formContainer.style.display === "block" &&
        !formContainer.contains(e.target) &&
        !e.target.closest(".create-note-card") &&
        !e.target.closest(".existing-note-card") &&
        !e.target.closest(".modal") && // Exclude clicks within modals
        !e.target.closest(".modal-backdrop") // Exclude modal backdrop clicks
      ) {
        console.log("Click outside note form, triggering autosave");
        AutoSave.flush();
      }
    });

    newLabelInput.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        console.log("Enter pressed in new label input");
        event.preventDefault();
        addLabel();
      }
    });

    if (
      typeof window.currentNoteId !== "undefined" &&
      window.currentNoteId !== null
    ) {
      API.loadNote(window.currentNoteId)
        .then((note) => {
          if (note && note.success) {
            // Check if user has edit permission before entering edit mode
            if (
              note.is_owner ||
              (note.shared_permissions &&
                note.shared_permissions.includes("edit"))
            ) {
              editNote(window.currentNoteId);
            } else {
              if (typeof UI !== "undefined") {
                UI.showError("You do not have permission to edit this note");
              } else {
                alert("You do not have permission to edit this note");
              }
              window.currentNoteId = null;
              window.history.replaceState({}, "", "notes_frontend.php");
              loadNotes();
            }
          } else {
            if (typeof UI !== "undefined") {
              UI.showError(note.error || "Failed to load note");
            } else {
              console.error(
                "Failed to load note: " + (note.error || "Unknown error")
              );
              alert("Failed to load note: " + (note.error || "Unknown error"));
            }
          }
        })
        .catch((error) => {
          if (
            error.message.includes("403") &&
            error.cause &&
            error.cause.is_locked
          ) {
            promptPassword(
              window.currentNoteId,
              "edit",
              () => editNote(window.currentNoteId),
              () => {
                window.currentNoteId = null;
                AutoSave.queue = AutoSave.queue.filter(
                  (item) => item.noteId !== window.currentNoteId
                );
                AutoSave.saveQueue();
                if (typeof UI !== "undefined") {
                  UI.showError(
                    "Note is locked. Please unlock it to edit or save changes."
                  );
                } else {
                  console.error(
                    "Note is locked. Please unlock it to edit or save changes."
                  );
                  alert(
                    "Note is locked. Please unlock it to edit or save changes."
                  );
                }
                window.history.replaceState({}, "", "notes_frontend.php");
              }
            );
          } else {
            if (typeof UI !== "undefined") {
              UI.showError(
                "Network error during note loading: " + error.message
              );
            } else {
              console.error(
                "Network error during note loading: " + error.message
              );
              alert("Network error during note loading: " + error.message);
            }
          }
        });
    }

    window.addEventListener("beforeunload", (event) => {
      if (AutoSave.hasPendingSaves()) {
        AutoSave.flush();
        event.returnValue =
          "You have unsaved changes. Are you sure you want to leave?";
      }
    });

    console.log("Initializing homepage");
    goToHomepage();
    loadLabels();

    // Add click handler for note cards
    document.getElementById("notesGrid").addEventListener("click", (e) => {
      const noteCard = e.target.closest(".existing-note-card");
      if (!noteCard) return;

      const noteId = noteCard.dataset.id;
      const isButton = e.target.closest(
        ".pin-btn, .delete-btn, .image-btn, .label-btn, .settings-button, .dropdown-content, .restore-btn"
      );
      if (isButton) {
        e.stopPropagation(); // Prevent button clicks from triggering edit
        return;
      }

      if (!isTrashView) {
        console.log(`Note card clicked, entering edit mode for ID: ${noteId}`);
        API.loadNote(noteId).then((note) => {
          if (note && note.success) {
            if (
              note.is_owner ||
              (note.shared_permissions &&
                note.shared_permissions.includes("edit"))
            ) {
              editNote(noteId);
            } else {
              if (typeof UI !== "undefined") {
                UI.showError("You do not have permission to edit this note");
              } else {
                alert("You do not have permission to edit this note");
              }
            }
          }
        });
      }
    });
  } else {
    if (typeof UI !== "undefined") {
      UI.showError("Form initialization error: Missing form elements");
    } else {
      console.error(
        "Form initialization error: Missing form elements and UI object"
      );
      const errorDiv = document.createElement("div");
      errorDiv.className = "error";
      errorDiv.textContent =
        "Application error: Missing required elements. Please refresh the page.";
      document.body.prepend(errorDiv);
    }
    console.error("Missing elements:", {
      inlineTitle,
      inlineContent,
      inlineLabels,
      modalTitle,
      modalContent,
      modalLabels,
      search,
      newLabelInput,
      sidebarToggle,
      sidebarToggleMobile,
      notesBtn,
      labelsBtn,
      trashBtn,
      toggleViewBtn,
    });
  }

  document.getElementById("notesGrid").addEventListener("click", (e) => {
    const noteCard = e.target.closest(".existing-note-card");
    if (!noteCard) return;

    const noteId = noteCard.dataset.id;
    const isButton = e.target.closest(
      ".pin-btn, .delete-btn, .image-btn, .label-btn, .settings-button, .dropdown-content, .restore-btn"
    );
    if (isButton) {
      e.stopPropagation(); // Prevent button clicks from triggering edit
      return;
    }

    if (!isTrashView) {
      console.log(`Note card clicked, entering edit mode for ID: ${noteId}`);
      editNote(noteId);
    }
  });
});

document.addEventListener("click", (event) => {
  if (!event.target.closest(".dropdown")) {
    document
      .querySelectorAll(".dropdown-content")
      .forEach((dd) => (dd.style.display = "none"));
  }
});
