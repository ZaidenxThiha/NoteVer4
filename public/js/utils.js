export function htmlspecialchars(str) {
  if (typeof str !== "string") return "";
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

export function nl2br(str) {
  return htmlspecialchars(str).replace(/(?:\r\n|\r|\n)/g, "<br>");
}
