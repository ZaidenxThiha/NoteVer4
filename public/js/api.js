const API = {
  async saveNote(noteId, data) {
    console.log(`Saving note ID: ${noteId || "new"}`, data);
    try {
      const response = await fetch(
        `notes_backend.php?save=1${noteId ? "&id=" + noteId : ""}`,
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(data),
          credentials: "include",
        }
      );
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Save note failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Save note successful:", result);
      return result;
    } catch (error) {
      console.error("Save note error:", error);
      throw error;
    }
  },

  async loadNote(noteId) {
    console.log(`Loading note ID: ${noteId}`);
    try {
      const response = await fetch(
        `notes_backend.php?action=edit&id=${noteId}`,
        { credentials: "include" }
      );
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Load note failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Load note successful:", result);
      return result;
    } catch (error) {
      console.error("Load note error:", error);
      throw error;
    }
  },

  async loadNotes(search = "", label = "", isTrashed = 0) {
    console.log(
      `Loading notes with search: ${search}, label: ${label}, is_trashed: ${isTrashed}`
    );
    try {
      const params = new URLSearchParams({
        search,
        label,
        is_trashed: isTrashed,
      });
      const response = await fetch(
        `notes_backend.php?action=notes&${params.toString()}`,
        { credentials: "include" }
      );
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Load notes failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Raw loadNotes response:", result);
      return (
        result.html || '<div class="no-results"><p>No notes found.</p></div>'
      );
    } catch (error) {
      console.error("Load notes error:", error);
      throw error;
    }
  },

  async uploadImage(formData) {
    console.log("Uploading image");
    try {
      const response = await fetch("notes_backend.php?action=upload_image", {
        method: "POST",
        body: formData,
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Image upload failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Image upload successful:", result);
      return result;
    } catch (error) {
      console.error("Image upload error:", error);
      throw error;
    }
  },

  async shareNote(noteId, emails, permission) {
    console.log(
      `Sharing note ID: ${noteId} with ${emails}, permission: ${permission}`
    );
    try {
      const response = await fetch("notes_backend.php?action=share", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId, emails, permission }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Share note failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Share note successful:", result);
      return result;
    } catch (error) {
      console.error("Share note error:", error);
      throw error;
    }
  },

  async updateShare(noteId, userId, permission) {
    console.log(
      `Updating share for note ID: ${noteId}, user ID: ${userId}, permission: ${permission}`
    );
    try {
      const response = await fetch("notes_backend.php?action=update_share", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId, user_id: userId, permission }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Update share failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Update share successful:", result);
      return result;
    } catch (error) {
      console.error("Update share error:", error);
      throw error;
    }
  },

  async revokeShare(noteId, userId) {
    console.log(`Revoking share for note ID: ${noteId}, user ID: ${userId}`);
    try {
      const response = await fetch("notes_backend.php?action=revoke_share", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId, user_id: userId }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Revoke share failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Revoke share successful:", result);
      return result;
    } catch (error) {
      console.error("Revoke share error:", error);
      throw error;
    }
  },

  async verifyPassword(noteId, password) {
    console.log(`Verifying password for note ID: ${noteId}`);
    try {
      const response = await fetch("notes_backend.php?action=verify_password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId, password }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Password verification failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Password verification successful:", result);
      return result;
    } catch (error) {
      console.error("Password verification error:", error);
      throw error;
    }
  },

  async setPassword(noteId, password) {
    console.log(`Setting password for note ID: ${noteId}`);
    try {
      const response = await fetch("notes_backend.php?action=set_password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId, password }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Set password failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Set password successful:", result);
      return result;
    } catch (error) {
      console.error("Set password error:", error);
      throw error;
    }
  },

  async removePassword(noteId) {
    console.log(`Removing password for note ID: ${noteId}`);
    try {
      const response = await fetch("notes_backend.php?action=remove_password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Remove password failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Remove password successful:", result);
      return result;
    } catch (error) {
      console.error("Remove password error:", error);
      throw error;
    }
  },

  async relock(noteId) {
    console.log(`Relocking note ID: ${noteId}`);
    try {
      const response = await fetch("notes_backend.php?action=relock", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Relock failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Relock successful:", result);
      return result;
    } catch (error) {
      console.error("Relock error:", error);
      throw error;
    }
  },

  async pinNote(noteId, pin) {
    console.log(`Pinning note ID: ${noteId}, pin: ${pin}`);
    try {
      const response = await fetch("notes_backend.php?action=pin", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId, pin }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Pin note failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Pin note successful:", result);
      return result;
    } catch (error) {
      console.error("Pin note error:", error);
      throw error;
    }
  },

  async trashNote(noteId) {
    console.log(`Trashing note ID: ${noteId}`);
    try {
      const response = await fetch("notes_backend.php?action=trash", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Trash note failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Trash note successful:", result);
      return result;
    } catch (error) {
      console.error("Trash note error:", error);
      throw error;
    }
  },

  async restoreNote(noteId) {
    console.log(`Restoring note ID: ${noteId}`);
    try {
      const response = await fetch("notes_backend.php?action=restore", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Restore note failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Restore note successful:", result);
      return result;
    } catch (error) {
      console.error("Restore note error:", error);
      throw error;
    }
  },

  async deleteNote(noteId) {
    console.log(`Deleting note ID: ${noteId}`);
    try {
      const response = await fetch("notes_backend.php?action=delete", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ note_id: noteId }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Delete note failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Delete note successful:", result);
      return result;
    } catch (error) {
      console.error("Delete note error:", error);
      throw error;
    }
  },

  async addLabel(labelName) {
    console.log(`Adding label: ${labelName}`);
    try {
      const response = await fetch("notes_backend.php?action=add_label", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ label: labelName }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Add label failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Add label successful:", result);
      return result;
    } catch (error) {
      console.error("Add label error:", error);
      throw error;
    }
  },

  async renameLabel(oldName, newName) {
    console.log(`Renaming label from ${oldName} to ${newName}`);
    try {
      const response = await fetch("notes_backend.php?action=rename_label", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ old_name: oldName, new_name: newName }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Rename label failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Rename label successful:", result);
      return result;
    } catch (error) {
      console.error("Rename label error:", error);
      throw error;
    }
  },

  async deleteLabel(labelName) {
    console.log(`Deleting label: ${labelName}`);
    try {
      const response = await fetch("notes_backend.php?action=delete_label", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ label: labelName }),
        credentials: "include",
      });
      const result = await response.json();
      if (!response.ok) {
        console.error(
          `Delete label failed: ${response.status} ${response.statusText}`,
          result
        );
        const error = new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            result.error || JSON.stringify(result)
          }`
        );
        error.cause = result;
        throw error;
      }
      console.log("Delete label successful:", result);
      return result;
    } catch (error) {
      console.error("Delete label error:", error);
      throw error;
    }
  },
};
