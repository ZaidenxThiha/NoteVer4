const AutoSave = {
  queue: [],
  retryInterval: 1000, // Start with 1s
  maxRetryInterval: 60000, // Max 1min
  storageKey: "noteapp_pending_saves",
  lastSavedData: null,
  isProcessing: false,
  pendingImages: [], // Store pending images for upload
  retryTimeout: null,

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

    // Load any pending saves from LocalStorage
    this.loadQueue();
    console.log("Autosave initialized, queue:", this.queue);

    // Save handler
    this.flush = async () => {
      const data = {
        title: titleInput.value.trim() || modalTitleInput.value.trim(),
        content: contentInput.value.trim() || modalContentInput.value.trim(),
        labels: labelInput.value.trim() || modalLabelInput.value.trim(),
        updated_at: new Date().toISOString(),
      };
      // Check for pending images
      const hasImages = imageInput.files.length > 0;
      // Only queue if data has changed or there are images to upload
      if (
        (data.content || hasImages) &&
        (JSON.stringify(data) !== JSON.stringify(this.lastSavedData) ||
          hasImages)
      ) {
        this.queueSave(
          window.currentNoteId,
          data,
          hasImages ? Array.from(imageInput.files) : []
        );
        this.lastSavedData = data;
        if (hasImages) {
          this.pendingImages = Array.from(imageInput.files);
          imageInput.value = ""; // Clear the input after queuing
        }
      } else {
        console.log("No changes to save or empty content:", data);
        // Hide and reset form if no content and no images
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

    // Start retrying pending saves
    this.processQueue();
  },

  queueSave(noteId, data, images) {
    // Remove existing entries for the same noteId to prevent duplicates
    this.queue = this.queue.filter((item) => item.noteId !== noteId);
    this.queue.push({
      noteId,
      data,
      images,
      retries: 0,
      nextRetry: Date.now(),
      locked: false, // Track if the note is locked
    });
    this.saveQueue();
    console.log("Queued save:", { noteId, data, hasImages: images.length > 0 });
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
          console.log("Processing queued save:", item);
          if (typeof API === "undefined") {
            throw new Error(
              "API is not defined. Please check if api.js is loaded."
            );
          }

          UI.showSaving();

          // Check if note is accessible and editable
          if (item.noteId) {
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
                          this.queue = this.queue.filter((q) => q !== item);
                          this.saveQueue();
                          UI.showSaved();
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
                      this.queue = this.queue.filter((q) => q !== item);
                      this.saveQueue();
                      UI.showError(
                        "Password prompt canceled. Please unlock the note to save changes."
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
                this.queue = this.queue.filter((q) => q !== item);
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
                          this.queue = this.queue.filter((q) => q !== item);
                          this.saveQueue();
                          UI.showSaved();
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
                      this.queue = this.queue.filter((q) => q !== item);
                      this.saveQueue();
                      UI.showError(
                        "Password prompt canceled. Please unlock the note to save changes."
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

          // Save the note (unlocked or new note)
          item.locked = false;
          const response = await this.onSave(item.noteId, item.data);
          console.log("Server response:", response);
          if (response.success) {
            if (!item.noteId && response.id) {
              this.onIdUpdate(response.id);
              item.noteId = response.id;
            }
            // If there are images, upload them
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
            this.queue = this.queue.filter((q) => q !== item);
            this.saveQueue();
            UI.showSaved();
            console.log("Save successful, removed from queue:", response);
          } else {
            throw new Error(response.error || "Unknown server error");
          }
        } catch (error) {
          item.retries++;
          if (item.retries >= maxRetries) {
            console.error(
              `Autosave failed after ${item.retries} attempts:`,
              error.message,
              item
            );
            this.queue = this.queue.filter((q) => q !== item);
            this.saveQueue();
            UI.showError(
              "Failed to save note after multiple attempts. Please try again."
            );
          } else if (error.message.includes("403") && !error.cause?.is_locked) {
            console.error(
              "Unrecoverable permission error, stopping retries:",
              error.message,
              item
            );
            this.queue = this.queue.filter((q) => q !== item);
            this.saveQueue();
            // Suppress redundant error message, as handled above
            console.log(
              "Permission error already displayed, skipping additional UI error"
            );
          } else if (
            error.message.includes("API is not defined") ||
            error.message.includes("Unexpected token")
          ) {
            console.error(
              "Unrecoverable error, stopping retries:",
              error.message,
              item
            );
            this.queue = this.queue.filter((q) => q !== item);
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
              item.nextRetry += 10000; // Add 10s delay for locked notes
              UI.showError("Note is locked. Please unlock it to save changes.");
            }
            this.saveQueue();
            console.error("Save failed, retrying:", error.message, item);
          }
        }
      }
    } catch (error) {
      console.error("Unexpected error in processQueue:", error);
      UI.showError("Unexpected error during autosave. Please try again.");
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
        data: item.data,
        images: [], // Images are not stored in LocalStorage
        retries: item.retries,
        nextRetry: item.nextRetry,
        locked: item.locked,
      }));
      console.log("Loaded queue from LocalStorage:", this.queue);
    }
  },

  hasPendingSaves() {
    return this.queue.length > 0;
  },
};

// Debounce utility to prevent excessive flush calls
function debounce(func, wait) {
  let timeout;
  return function (...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(this, args), wait);
  };
}

AutoSave.flush = debounce(AutoSave.flush, 500);
