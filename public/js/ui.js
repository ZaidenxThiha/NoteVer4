const UI = {
  showSaving() {
    // Remove any existing saving notification
    const existing = document.querySelector(".saving");
    if (existing) existing.remove();

    const indicator = document.createElement("div");
    indicator.id = "save-indicator";
    indicator.className = "saving animate-pulse";
    indicator.setAttribute("role", "alert");
    indicator.setAttribute("aria-live", "assertive");
    indicator.textContent = "Saving...";
    document.body.appendChild(indicator);
    console.log("UI: Showing saving indicator");
  },

  showSaved() {
    const indicator = document.querySelector(".saving");
    if (indicator) {
      indicator.className = "saved animate-fade-in";
      indicator.textContent = "Saved ✓";
      indicator.setAttribute("role", "alert");
      indicator.setAttribute("aria-live", "assertive");
      console.log("UI: Showing saved indicator");
      setTimeout(() => {
        indicator.className = "saved animate-fade-out";
        setTimeout(() => {
          indicator.remove();
          console.log("UI: Cleared saved indicator");
        }, 500);
      }, 2000);
    }
  },

  showError(message) {
    // Remove any existing error notification
    const existing = document.querySelector(".error");
    if (existing) existing.remove();

    let displayMessage = message;
    if (message.includes("403")) {
      displayMessage = "You do not have permission to perform this action";
    } else if (message.includes("Label already exists")) {
      displayMessage = "This label name is already in use";
    } else if (message.includes("Label not found")) {
      displayMessage = "The specified label does not exist";
    } else if (message.includes("Label name required")) {
      displayMessage = "Please enter a label name";
    } else if (
      message.includes("Please save the note before uploading an image")
    ) {
      displayMessage =
        "Please save the note before uploading an image. Click outside the form to save.";
    } else if (message.includes("API is not defined")) {
      displayMessage =
        "Application error: API is not loaded. Please refresh the page.";
    } else if (message.includes("Unexpected token")) {
      displayMessage =
        "Failed to load note: Server returned an unexpected response. Please try again.";
    } else if (message.includes("Password required")) {
      displayMessage =
        "This note is locked. Please enter the password to view or edit.";
    } else if (message.includes("Incorrect password")) {
      displayMessage = "Incorrect password for this note. Please try again.";
    } else if (message.includes("Invalid callback")) {
      displayMessage =
        "Application error: Unable to process password prompt. Please try again.";
    }

    const errorDiv = document.createElement("div");
    errorDiv.className = "error animate-fade-in";
    errorDiv.setAttribute("role", "alert");
    errorDiv.setAttribute("aria-live", "assertive");
    errorDiv.textContent = displayMessage;
    document.body.appendChild(errorDiv);
    console.error("UI: Error displayed:", message);
    setTimeout(() => {
      errorDiv.className = "error animate-fade-out";
      setTimeout(() => errorDiv.remove(), 500);
    }, 3000);
  },

  showPasswordPrompt(noteId, action, callback, cancelCallback = () => {}) {
    const modal = document.getElementById("passwordModal");
    if (!modal) {
      console.error("Password modal not found in DOM");
      this.showError("Application error: Password prompt unavailable");
      return;
    }

    const title = document.getElementById("passwordModalLabel");
    const input = document.getElementById("notePassword");
    const submitButton = document.getElementById("passwordSubmit");

    if (!title || !input || !submitButton) {
      console.error("Password modal elements missing");
      this.showError("Application error: Password prompt unavailable");
      return;
    }

    title.textContent =
      action === "set"
        ? "Set Note Password"
        : action === "change"
        ? "Change Note Password"
        : action === "save"
        ? "Unlock Note to Save"
        : "Unlock Note to View";
    input.value = "";

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();

    const handleSubmit = () => {
      const password = input.value.trim();
      if (!password) {
        this.showError("Password required");
        return;
      }
      if (typeof callback !== "function") {
        console.error(
          "Callback is not a function in showPasswordPrompt, received:",
          callback
        );
        this.showError("Invalid callback for password prompt");
        bsModal.hide();
        return;
      }
      try {
        callback(password);
      } catch (error) {
        console.error("Error executing callback in showPasswordPrompt:", error);
        this.showError("Application error: Failed to process password");
      }
      bsModal.hide();
    };

    input.onkeydown = (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        handleSubmit();
      }
    };

    submitButton.onclick = handleSubmit;

    modal.addEventListener(
      "hidden.bs.modal",
      () => {
        input.onkeydown = null;
        submitButton.onclick = null;
        if (typeof cancelCallback === "function") {
          cancelCallback();
        }
      },
      { once: true }
    );

    input.focus();
  },

  initViewToggle() {
    const viewToggle = document.getElementById("toggleViewBtn");
    const notesContainer = document.querySelector(".notes-container");
    const savedView = localStorage.getItem("noteView") || "grid";

    if (savedView === "list-view") {
      notesContainer.classList.add("list-view");
    }
    viewToggle.textContent = savedView === "grid" ? "List View" : "Grid View";

    viewToggle.addEventListener("click", () => {
      const isGridView = notesContainer.classList.contains("list-view");
      notesContainer.classList.toggle("list-view", !isGridView);
      viewToggle.textContent = isGridView ? "List View" : "Grid View";
      localStorage.setItem("noteView", isGridView ? "grid" : "list-view");
      console.log(`UI: Switched to ${isGridView ? "grid" : "list-view"} view`);
    });
  },
};
