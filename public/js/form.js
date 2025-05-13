export function syncForms(direction = "modal-to-inline") {
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

export function resetForm() {
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

export function showInlineForm() {
  console.log("Showing inline note form");
  const formContainer = document.getElementById("note-form-container");
  formContainer.style.display = "block";
  document.getElementById("inlineTitle").focus();
}

export function saveNoteFromModal() {
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
