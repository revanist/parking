// Shared UI interactions only. Backend/database logic stays in PHP files.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-demo]').forEach((el) => {
    el.addEventListener('click', (event) => event.preventDefault());
  });
});
