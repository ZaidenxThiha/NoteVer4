const AutoSave = {
  queue: [],
  retryInterval: 1000,
  maxRetryInterval: 60000,
  storageKey: "noteapp_pending_saves",
  lastSavedData: null,
  isProcessing: false,
  pendingImages: [],
  retryTimeout: null,

  // Helper function to format date as MySQL DATETIME (YYYY-MM-DD HH:MM:SS)
  formatMySQLDateTime(date) {
    const d = new Date(date);
    return d.toISOString().slice(0, 19).replace("T", " ");
  },

  init({
    titleInput,
    contentInput,
    labelInput,
    imageInput,
    modalTitleInput,
    modalContentInput,
    modalLabelInput,
    onSave,
    onIdUpdate,
    onImageUpload,
  }) {
    if (typeof API === "undefined") {
      console.error("API is not defined. Autosave will not function.");
      UI.showError(
        "Application error: API is not loaded. Please refresh the page."
      );
      return;
    }
    this.onSave = onSave;
    this.onIdUpdate = onIdUpdate;
    this.onImageUpload = onImageUpload;

    this.loadQueue();
    console.log("Autosave initialized, queue:", this.queue);

    this.flush = async () => {
      const data = {
        title: titleInput.value.trim() || modalTitleInput.value.trim(),
        content: contentInput.value.trim() || modalContentInput.value.trim(),
        labels: labelInput.value.trim() || modalLabelInput.value.trim(),
        updated_at: this.formatMySQLDateTime(new Date()),
      };
      const hasImages = imageInput.files.length > 0;
      if (
        (data.content || hasImages) &&
        (JSON.stringify(data) !== JSON.stringify(this.lastSavedData) ||
          hasImages)
      ) {
        // Use a unique temporary ID for offline notes
        const tempId = `offline-${Date.now()}-${Math.random()
          .toString(36)
          .slice(2, 9)}`;
        this.queueSave(
          tempId,
          data,
          hasImages ? Array.from(imageInput.files) : []
        );
        this.lastSavedData = data;
        if (hasImages) {
          this.pendingImages = Array.from(imageInput.files);
          imageInput.value = "";
        }
        if (!navigator.onLine && typeof saveToIndexedDB === "function") {
          try {
            await saveToIndexedDB("notes", {
              id: tempId,
              title: data.title,
              content: data.content,
              labels: data.labels,
              updated_at: data.updated_at,
              is_offline: true,
            });
            console.log(`Saved note ${tempId} to IndexedDB`);
            UI.showOfflineStatus();
            loadNotes();
          } catch (error) {
            console.error(`Failed to save note ${tempId} to IndexedDB:`, error);
            UI.showError(
              "Offline: Failed to save note locally. Please try again."
            );
            return; // Prevent further queuing on failure
          }
        }
      } else {
        console.log("No changes to save or empty content:", data);
        if (!data.content && !hasImages) {
          const formContainer = document.getElementById("note-form-container");
          if (formContainer) {
            formContainer.style.display = "none";
            titleInput.value = "";
            contentInput.value = "";
            labelInput.value = "";
            modalTitleInput.value = "";
            modalContentInput.value = "";
            modalLabelInput.value = "";
            this.lastSavedData = null;
            this.pendingImages = [];
          }
        }
      }
    };

    this.processQueue();
    window.addEventListener("online", () => {
      console.log("Device back online, syncing queue...");
      UI.showSyncProgress();
      this.processQueue();
    });
  },

  queueSave(noteId, data, images) {
    // Treat temporary IDs (starting with 'offline-' or '17') as new notes
    if (noteId && (noteId.startsWith("offline-") || noteId.startsWith("17"))) {
      console.log(`Temporary ID ${noteId} detected, treating as new note`);
      noteId = null;
    }
    this.queue = this.queue.filter((item) => item.noteId !== noteId);
    this.queue.push({
      noteId,
      data: {
        ...data,
        updated_at: this.formatMySQLDateTime(data.updated_at),
      },
      images,
      retries: 0,
      nextRetry: Date.now(),
      locked: false,
      tempId:
        noteId ||
        `offline-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`, // Store tempId for syncing
    });
    this.saveQueue();
    console.log("Queued save:", {
      noteId: noteId || "new",
      tempId: noteId || this.queue[this.queue.length - 1].tempId,
      data,
      hasImages: images.length > 0,
    });
    this.processQueue();
  },

  async processQueue() {
    if (this.isProcessing || !navigator.onLine) {
      console.log(
        `Queue processing skipped: ${
          this.isProcessing ? "already processing" : "offline"
        }, retrying in ${this.retryInterval}ms`
      );
      if (!this.retryTimeout) {
        this.retryTimeout = setTimeout(() => {
          this.retryTimeout = null;
          this.processQueue();
        }, this.retryInterval);
      }
      return;
    }

    this.isProcessing = true;
    try {
      const now = Date.now();
      const pending = this.queue.filter((item) => item.nextRetry <= now);
      const maxRetries = 5;

      for (const item of pending) {
        try {
          console.log("Processing queued save:", {
            tempId: item.tempId,
            noteId: item.noteId,
            data: item.data,
          });
          if (typeof API === "undefined") {
            throw new Error(
              "API is not defined. Please check if api.js is loaded."
            );
          }

          UI.showSaving();

          if (
            item.noteId &&
            !item.noteId.startsWith("offline-") &&
            !item.noteId.startsWith("17")
          ) {
            try {
              const accessResponse = await API.loadNote(item.noteId);
              if (accessResponse.is_locked && !accessResponse.success) {
                console.log(
                  "Note is locked, prompting for password:",
                  item.noteId
                );
                item.locked = true;
                await new Promise((resolve, reject) => {
                  promptPassword(
                    item.noteId,
                    "save",
                    async () => {
                      try {
                        const response = await this.onSave(
                          item.noteId,
                          item.data
                        );
                        if (response.success) {
                          if (!item.noteId && response.id) {
                            this.onIdUpdate(response.id);
                            item.noteId = response.id;
                          }
                          if (item.images.length > 0) {
                            const formData = new FormData();
                            item.images.forEach((image) =>
                              formData.append("image[]", image)
                            );
                            formData.append(
                              "note_id",
                              response.id || item.noteId
                            );
                            await this.onImageUpload(formData);
                            console.log(
                              "Images uploaded for note ID:",
                              response.id || item.noteId
                            );
                          }
                          this.queue = this.queue.filter(
                            (q) => q.tempId !== item.tempId
                          );
                          this.saveQueue();
                          UI.showSaved();
                          if (typeof deleteFromIndexedDB === "function") {
                            await deleteFromIndexedDB("notes", item.tempId);
                          }
                          console.log(
                            "Save successful after password prompt:",
                            response
                          );
                          resolve();
                        } else {
                          throw new Error(
                            response.error || "Unknown server error"
                          );
                        }
                      } catch (saveError) {
                        console.error(
                          "Save failed after password prompt:",
                          saveError.message,
                          item
                        );
                        reject(saveError);
                      }
                    },
                    () => {
                      console.log(
                        "Password prompt canceled for note ID:",
                        item.noteId
                      );
                      this.queue = this.queue.filter(
                        (q) => q.tempId !== item.tempId
                      );
                      this.saveQueue();
                      UI.showError(
                        "Password prompt canceled. Note removed from queue."
                      );
                      resolve();
                    }
                  );
                });
                continue;
              } else if (
                !accessResponse.is_owner &&
                (!accessResponse.shared_permissions ||
                  !accessResponse.shared_permissions.includes("edit"))
              ) {
                console.error(
                  "User lacks permission to edit note ID:",
                  item.noteId
                );
                this.queue = this.queue.filter((q) => q.tempId !== item.tempId);
                this.saveQueue();
                UI.showError("You do not have permission to save this note.");
                continue;
              }
            } catch (error) {
              if (
                error.message.includes("403") &&
                error.cause &&
                error.cause.is_locked
              ) {
                console.log(
                  "Note is locked, prompting for password:",
                  item.noteId
                );
                item.locked = true;
                await new Promise((resolve, reject) => {
                  promptPassword(
                    item.noteId,
                    "save",
                    async () => {
                      try {
                        const response = await this.onSave(
                          item.noteId,
                          item.data
                        );
                        if (response.success) {
                          if (!item.noteId && response.id) {
                            this.onIdUpdate(response.id);
                            item.noteId = response.id;
                          }
                          if (item.images.length > 0) {
                            const formData = new FormData();
                            item.images.forEach((image) =>
                              formData.append("image[]", image)
                            );
                            formData.append(
                              "note_id",
                              response.id || item.noteId
                            );
                            await this.onImageUpload(formData);
                            console.log(
                              "Images uploaded for note ID:",
                              response.id || item.noteId
                            );
                          }
                          this.queue = this.queue.filter(
                            (q) => q.tempId !== item.tempId
                          );
                          this.saveQueue();
                          UI.showSaved();
                          if (typeof deleteFromIndexedDB === "function") {
                            await deleteFromIndexedDB("notes", item.tempId);
                          }
                          console.log(
                            "Save successful after password prompt:",
                            response
                          );
                          resolve();
                        } else {
                          throw new Error(
                            response.error || "Unknown server error"
                          );
                        }
                      } catch (saveError) {
                        console.error(
                          "Save failed after password prompt:",
                          saveError.message,
                          item
                        );
                        reject(saveError);
                      }
                    },
                    () => {
                      console.log(
                        "Password prompt canceled for note ID:",
                        item.noteId
                      );
                      this.queue = this.queue.filter(
                        (q) => q.tempId !== item.tempId
                      );
                      this.saveQueue();
                      UI.showError(
                        "Password prompt canceled. Note removed from queue."
                      );
                      resolve();
                    }
                  );
                });
                continue;
              }
              throw error;
            }
          }

          item.locked = false;
          const response = await this.onSave(item.noteId, item.data);
          console.log("Server response for tempId", item.tempId, ":", response);
          if (response.success) {
            if (!item.noteId && response.id) {
              this.onIdUpdate(response.id);
              item.noteId = response.id;
              // Update IndexedDB with server ID
              if (typeof saveToIndexedDB === "function") {
                try {
                  await saveToIndexedDB("notes", {
                    id: response.id,
                    title: item.data.title,
                    content: item.data.content,
                    labels: item.data.labels,
                    updated_at: item.data.updated_at,
                    is_offline: false,
                  });
                  await deleteFromIndexedDB("notes", item.tempId);
                  console.log(
                    `Updated IndexedDB: tempId ${item.tempId} -> serverId ${response.id}`
                  );
                } catch (error) {
                  console.error(
                    `Failed to update IndexedDB for note ${response.id}:`,
                    error
                  );
                }
              }
            }
            if (item.images.length > 0) {
              try {
                const formData = new FormData();
                item.images.forEach((image) =>
                  formData.append("image[]", image)
                );
                formData.append("note_id", response.id || item.noteId);
                await this.onImageUpload(formData);
                console.log(
                  "Images uploaded for note ID:",
                  response.id || item.noteId
                );
              } catch (imageError) {
                console.error("Image upload failed:", imageError.message);
                UI.showError(
                  "Failed to upload images. Note saved without images."
                );
              }
            }
            this.queue = this.queue.filter((q) => q.tempId !== item.tempId);
            this.saveQueue();
            UI.showSaved();
            if (typeof deleteFromIndexedDB === "function") {
              await deleteFromIndexedDB("notes", item.tempId);
            }
            console.log("Save successful, removed from queue:", {
              tempId: item.tempId,
              serverId: response.id,
            });
          } else {
            throw new Error(response.error || "Unknown server error");
          }
        } catch (error) {
          item.retries++;
          if (item.retries >= maxRetries) {
            console.error(
              `Autosave failed after ${item.retries} attempts for tempId ${item.tempId}:`,
              error.message,
              item
            );
            this.queue = this.queue.filter((q) => q.tempId !== item.tempId);
            this.saveQueue();
            UI.showError(
              `Failed to save note after multiple attempts: ${error.message}. Please try again.`
            );
          } else if (
            error.message.includes("403") &&
            error.cause &&
            !error.cause.is_locked
          ) {
            if (error.cause.error === "User not authenticated") {
              console.error(
                "Authentication error, stopping retries for tempId",
                item.tempId,
                ":",
                error.message,
                item
              );
              this.queue = this.queue.filter((q) => q.tempId !== item.tempId);
              this.saveQueue();
              UI.showError("Session expired. Please log in again.");
              window.location.href = "login.php";
            } else {
              item.nextRetry =
                now +
                Math.min(
                  this.retryInterval * Math.pow(2, item.retries),
                  this.maxRetryInterval
                );
              this.saveQueue();
              UI.showError(
                `Permission error: ${
                  error.cause.error || "Unknown error"
                }. Retrying...`
              );
              console.error(
                "Permission error, retrying for tempId",
                item.tempId,
                ":",
                error.message,
                item
              );
            }
          } else if (
            error.message.includes("API is not defined") ||
            error.message.includes("Unexpected token")
          ) {
            console.error(
              "Unrecoverable error, stopping retries for tempId",
              item.tempId,
              ":",
              error.message,
              item
            );
            this.queue = this.queue.filter((q) => q.tempId !== item.tempId);
            this.saveQueue();
            UI.showError(
              error.message.includes("API is not defined")
                ? "Application error: API is not loaded. Please refresh the page."
                : "Server error: Failed to process request. Please try again."
            );
          } else {
            item.nextRetry =
              now +
              Math.min(
                this.retryInterval * Math.pow(2, item.retries),
                this.maxRetryInterval
              );
            if (item.locked) {
              item.nextRetry += 10000;
              UI.showError("Note is locked. Please unlock it to save changes.");
            }
            this.saveQueue();
            console.error(
              "Save failed, retrying for tempId",
              item.tempId,
              ":",
              error.message,
              item
            );
          }
        }
      }

      if (typeof getFromIndexedDB === "function") {
        try {
          const offlineRequests = await getFromIndexedDB("offlineRequests");
          UI.showSyncProgress();
          for (const request of offlineRequests) {
            try {
              const response = await fetch(request.url, {
                method: request.method,
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                  ...request.body,
                  updated_at: this.formatMySQLDateTime(request.body.updated_at),
                }),
                credentials: "include",
              });
              if (response.ok) {
                await deleteFromIndexedDB("offlineRequests", request.timestamp);
                UI.showSaved();
              } else {
                const result = await response.json();
                if (result.error === "User not authenticated") {
                  UI.showError("Session expired. Please log in again.");
                  window.location.href = "login.php";
                }
              }
            } catch (error) {
              console.error("Failed to sync offline request:", error);
            }
          }
          UI.hideSyncProgress();
        } catch (error) {
          console.error("Error syncing offline requests:", error);
          UI.showError("Failed to sync offline changes: " + error.message);
          UI.hideSyncProgress();
        }
      } else {
        console.warn(
          "IndexedDB functions not available, skipping offline request sync."
        );
        UI.hideSyncProgress();
      }
    } catch (error) {
      console.error("Unexpected error in processQueue:", error);
      UI.showError("Unexpected error during autosave: " + error.message);
      UI.hideSyncProgress();
    } finally {
      this.isProcessing = false;
      if (this.queue.length && !this.retryTimeout) {
        this.retryTimeout = setTimeout(() => {
          this.retryTimeout = null;
          this.processQueue();
        }, this.retryInterval);
      }
    }
  },

  saveQueue() {
    localStorage.setItem(
      this.storageKey,
      JSON.stringify(
        this.queue.map((item) => ({
          noteId: item.noteId,
          data: item.data,
          retries: item.retries,
          nextRetry: item.nextRetry,
          locked: item.locked,
          tempId: item.tempId,
        }))
      )
    );
    console.log(`Queue saved, size: ${this.queue.length}`);
  },

  loadQueue() {
    const saved = localStorage.getItem(this.storageKey);
    if (saved) {
      this.queue = JSON.parse(saved).map((item) => ({
        noteId: item.noteId,
        data: {
          ...item.data,
          updated_at: this.formatMySQLDateTime(item.data.updated_at),
        },
        images: [],
        retries: item.retries,
        nextRetry: item.nextRetry,
        locked: item.locked,
        tempId:
          item.tempId ||
          `offline-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
      }));
      console.log("Loaded queue from LocalStorage:", this.queue);
    }
  },

  hasPendingSaves() {
    return this.queue.length > 0;
  },
};

function debounce(func, wait) {
  let timeout;
  return function (...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(this, args), wait);
  };
}

AutoSave.flush = debounce(AutoSave.flush, 500);
