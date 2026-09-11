// Show current year in the footer
document.getElementById("year").textContent = new Date().getFullYear();

// Stamp the load time, used by contact.php to catch bots that submit
// the form instantly
document.getElementById("form-ts").value = Date.now();

// Send the contact form with fetch so the page doesn't reload
var form = document.getElementById("contact-form");
var statusText = document.getElementById("form-status");
var submitBtn = document.getElementById("submit-btn");

form.addEventListener("submit", function (event) {
  event.preventDefault();

  submitBtn.disabled = true;
  submitBtn.textContent = "Sending...";
  statusText.textContent = "";

  fetch("contact.php", {
    method: "POST",
    headers: { "Accept": "application/json" },
    body: new FormData(form)
  })
    .then(function (response) {
      return response.json();
    })
    .then(function (result) {
      statusText.textContent = result.message;
      if (result.success) {
        form.reset();
      }
    })
    .catch(function () {
      statusText.textContent = "Something went wrong. Please try again or email us directly.";
    })
    .finally(function () {
      submitBtn.disabled = false;
      submitBtn.textContent = "Send";
    });
});
