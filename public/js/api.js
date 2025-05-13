const API = {
  async saveNote(noteId, data) {
    const url = noteId
      ? `notes_backend.php?save=1&id=${encodeURIComponent(noteId)}`
      : `notes_backend.php?save=1&id=0`;
    console.log(`Saving note to URL: ${url}`, data);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Save note failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        let errorData = { error: "Unknown error" };
        try {
          errorData = JSON.parse(text);
        } catch (e) {
          console.error(`Failed to parse error response: ${e.message}`);
        }
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            errorData.error || text
          }`
        );
      }
      const result = await response.json();
      console.log(`Save note response:`, result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Save note request timed out");
      }
      console.error(`Save note error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async loadNote(id) {
    console.log(`Loading note ID: ${id}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(
        `notes_backend.php?action=edit&id=${encodeURIComponent(id)}`,
        {
          signal: controller.signal,
        }
      );
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Load note failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        let errorData = { error: "Unknown error", is_locked: false };
        try {
          errorData = JSON.parse(text);
        } catch (e) {
          console.error(`Failed to parse error response: ${e.message}`);
        }
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${
            errorData.error || text
          }`,
          { cause: { is_locked: errorData.is_locked || false } }
        );
      }
      const note = await response.json();
      console.log(`Load note response:`, note);
      return note;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Load note request timed out");
      }
      console.error(`Load note error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async loadNotes(search = "", label = "", isTrashed = 0) {
    console.log(
      `Loading notes with search: ${search}, label: ${label}, is_trashed: ${isTrashed}`
    );
    const url = `notes_backend.php?action=list&search=${encodeURIComponent(
      search
    )}&label=${encodeURIComponent(label)}&is_trashed=${encodeURIComponent(
      isTrashed
    )}`;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(url, {
        headers: { Accept: "application/json" },
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Load notes failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Raw loadNotes response:", result);
      if (result.success) {
        return result.html || "";
      } else {
        throw new Error(result.error || "Failed to load notes");
      }
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Load notes request timed out");
      }
      console.error(`Load notes error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async uploadImage(formData) {
    console.log(`Uploading images`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        body: formData,
        signal: controller.signal,
      });
      let errorData = { error: "Unknown error" };
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Upload image failed: ${response.status} ${response.statusText}, Response: ${text}`
        );
        if (text) {
          try {
            errorData = JSON.parse(text);
          } catch (e) {
            console.error(
              `Failed to parse error response: ${e.message}, Raw response: ${text}`
            );
            errorData.error = text || "Server returned an empty response";
          }
        } else {
          errorData.error = "Server returned an empty response";
        }
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${errorData.error}`
        );
      }
      const result = await response.json();
      console.log("Upload image response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Upload image request timed out");
      }
      console.error(`Upload image error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async addLabel(labelName) {
    console.log(`Adding label: ${labelName}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `add_label=1&label_name=${encodeURIComponent(labelName)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Add label failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Add label response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Add label request timed out");
      }
      console.error(`Add label error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async renameLabel(oldName, newName) {
    console.log(`Renaming label from ${oldName} to ${newName}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `rename_label=1&old_name=${encodeURIComponent(
          oldName
        )}&new_name=${encodeURIComponent(newName)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Rename label failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Rename label response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Rename label request timed out");
      }
      console.error(`Rename label error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async deleteLabel(labelName) {
    console.log(`Deleting label: ${labelName}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `delete_label=1&label_name=${encodeURIComponent(labelName)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Delete label failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Delete label response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Delete label request timed out");
      }
      console.error(`Delete label error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async setPassword(noteId, password) {
    console.log(`Setting password for note ID: ${noteId}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `set_password=1&note_id=${encodeURIComponent(
          noteId
        )}&password=${encodeURIComponent(password)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Set password failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Set password response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Set password request timed out");
      }
      console.error(`Set password error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async removePassword(noteId) {
    console.log(`Removing password for note ID: ${noteId}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `remove_password=1&note_id=${encodeURIComponent(noteId)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Remove password failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Remove password response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Remove password request timed out");
      }
      console.error(`Remove password error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async verifyPassword(noteId, password) {
    console.log(`Verifying password for note ID: ${noteId}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `verify_password=1&note_id=${encodeURIComponent(
          noteId
        )}&password=${encodeURIComponent(password)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Verify password failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Verify password response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Verify password request timed out");
      }
      console.error(`Verify password error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async shareNote(noteId, emails, permission) {
    console.log(
      `Sharing note ID: ${noteId} with emails: ${emails}, permission: ${permission}`
    );
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `share_note=1&note_id=${encodeURIComponent(
          noteId
        )}&emails=${encodeURIComponent(emails)}&permission=${encodeURIComponent(
          permission
        )}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Share note failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Share note response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Share note request timed out");
      }
      console.error(`Share note error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async updateShare(noteId, recipientUserId, permission) {
    console.log(
      `Updating share for note ID: ${noteId}, user ID: ${recipientUserId}, permission: ${permission}`
    );
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `update_share=1&note_id=${encodeURIComponent(
          noteId
        )}&recipient_user_id=${encodeURIComponent(
          recipientUserId
        )}&permission=${encodeURIComponent(permission)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Update share failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Update share response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Update share request timed out");
      }
      console.error(`Update share error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async revokeShare(noteId, recipientUserId) {
    console.log(
      `Revoking share for note ID: ${noteId}, user ID: ${recipientUserId}`
    );
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `revoke_share=1&note_id=${encodeURIComponent(
          noteId
        )}&recipient_user_id=${encodeURIComponent(recipientUserId)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Revoke share failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Revoke share response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Revoke share request timed out");
      }
      console.error(`Revoke share error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async deleteNote(id) {
    console.log(`Deleting note ID: ${id}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(
        `notes_backend.php?delete=1&id=${encodeURIComponent(id)}`,
        {
          signal: controller.signal,
        }
      );
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Delete note failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Delete note response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Delete note request timed out");
      }
      console.error(`Delete note error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async pinNote(id, pin) {
    console.log(`Pinning note ID: ${id}, pin: ${pin}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(
        `notes_backend.php?pin=${encodeURIComponent(
          pin
        )}&id=${encodeURIComponent(id)}`,
        { signal: controller.signal }
      );
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Pin note failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Pin note response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Pin note request timed out");
      }
      console.error(`Pin note error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async trashNote(id) {
    console.log(`Trashing note ID: ${id}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `trash_note=1&note_id=${encodeURIComponent(id)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Trash note failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Trash note response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Trash note request timed out");
      }
      console.error(`Trash note error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },

  async restoreNote(id) {
    console.log(`Restoring note ID: ${id}`);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch("notes_backend.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `restore_note=1&note_id=${encodeURIComponent(id)}`,
        signal: controller.signal,
      });
      if (!response.ok) {
        const text = await response.text();
        console.error(
          `Restore note failed: ${response.status} ${
            response.statusText
          }, Response: ${text.slice(0, 100)}...`
        );
        throw new Error(
          `HTTP error! Status: ${response.status}, Message: ${text}`
        );
      }
      const result = await response.json();
      console.log("Restore note response:", result);
      return result;
    } catch (error) {
      if (error.name === "AbortError") {
        throw new Error("Restore note request timed out");
      }
      console.error(`Restore note error:`, error);
      throw error;
    } finally {
      clearTimeout(timeout);
    }
  },
};
