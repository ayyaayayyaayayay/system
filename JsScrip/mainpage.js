// Main Page JavaScript - Login Functionality

// Wait for DOM to be fully loaded
document.addEventListener("DOMContentLoaded", function () {
  // Initialize the page
  initializeLoginPage();
});

/**
 * Initialize the login page
 */
function initializeLoginPage() {
  checkExistingSession();
  setupLoginForm();
  setupForgotPassword();
}

/**
 * Setup login form submission
 */
function setupLoginForm() {
  const loginForm = document.querySelector(".login-form");
  const loginBtn = document.getElementById("loginBtn");
  const usernameInput = document.getElementById("username");
  const passwordInput = document.getElementById("password");

  // Handle form submission
  loginBtn.addEventListener("click", function (e) {
    e.preventDefault();
    handleLogin();
  });

  // Allow Enter key to submit
  usernameInput.addEventListener("keypress", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      handleLogin();
    }
  });

  passwordInput.addEventListener("keypress", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      handleLogin();
    }
  });
}

/**
 * Handle login process — calls PHP backend API
 */
function handleLogin() {
  const username = document.getElementById("username").value.trim();
  const password = document.getElementById("password").value.trim();

  // Enhanced validation
  if (!username) {
    showError("Please enter a username");
    return;
  }

  // Length validation
  if (username.length > 50 || password.length > 50) {
    showError("Invalid credentials");
    return;
  }

  // Basic sanitization - remove any HTML tags
  const sanitizedUsername = username.replace(/<[^>]*>/g, "");
  const sanitizedPassword = password.replace(/<[^>]*>/g, "");

  // Show loading state
  const loginBtn = document.getElementById("loginBtn");
  const originalText = loginBtn.querySelector("span").textContent;
  loginBtn.querySelector("span").textContent = "Logging in...";
  loginBtn.disabled = true;

  // Call PHP login API
  fetch("../api/login.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      username: sanitizedUsername,
      password: sanitizedPassword,
    }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        storeUserSession(data);
        redirectToDashboard(data.role);
      } else {
        showError(data.error || "Invalid username or password");
        loginBtn.querySelector("span").textContent = originalText;
        loginBtn.disabled = false;
      }
    })
    .catch(() => {
      showError("Login service is unavailable. Please try again.");
      loginBtn.querySelector("span").textContent = originalText;
      loginBtn.disabled = false;
    });
}

function detectUserRoleFallback(username, password) {
  // Intentionally disabled: login is API-only.
  return null;
}

/**
 * Store user session in localStorage
 * @param {Object} authData - Login response payload
 */
function storeUserSession(authData) {
    const username = String(authData && authData.username || '').trim();
    const role = String(authData && authData.role || '').trim();
    const fullName = String(authData && authData.fullName || username).trim();
    const userId = String(authData && authData.userId || '').trim();
    const email = String(authData && authData.email || '').trim();
    const studentNumber = String(authData && authData.studentNumber || '').trim();
    const employeeId = String(authData && authData.employeeId || '').trim();
    const status = String(authData && authData.status || 'active').trim().toLowerCase();

    SharedData.setSession(username, role, {
        fullName: fullName,
        userId: userId,
        email: email,
        studentNumber: studentNumber,
        employeeId: employeeId,
        status: status === 'inactive' ? 'inactive' : 'active'
    });

    // Log the login activity
    SharedData.addActivityLogEntry({
        action: 'Login',
        description: fullName + ' logged in as ' + role,
        role: role,
        user_id: userId || username,
        type: 'login'
    });
}

/**
 * Redirect to appropriate dashboard
 * @param {string} role - User role
 */
function redirectToDashboard(role) {
  if (role === "student") {
    window.location.href = "studentpanel.html";
  } else if (role === "osa") {
    window.location.href = "osapanel.html";
  } else if (role === "hr") {
    window.location.href = "hrpanel.html";
  } else if (role === "vpaa") {
    window.location.href = "vpaapanel.html";
  } else if (role === "dean") {
    window.location.href = "daenpanel.html";
  } else if (role === "professor") {
    window.location.href = "profesorpanel.html";
  } else if (role === "admin") {
    window.location.href = "adminpanel.html";
  } else {
    // Placeholder for other roles
    alert(
      `${role.charAt(0).toUpperCase() + role.slice(1)} dashboard is coming soon!`,
    );
  }
}

/**
 * Show error message
 * @param {string} message - Error message to display
 */
function showError(message) {
  // Remove existing error message if any
  const existingError = document.querySelector(".error-message");
  if (existingError) {
    existingError.remove();
  }

  // Create error message element
  const errorDiv = document.createElement("div");
  errorDiv.className = "error-message";
  errorDiv.textContent = message;
  errorDiv.style.cssText = `
        background-color: #fee2e2;
        color: #dc2626;
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 500;
        animation: fadeIn 0.3s ease;
    `;

  // Insert error message before login form
  const loginForm = document.querySelector(".login-form");
  loginForm.parentNode.insertBefore(errorDiv, loginForm);

  // Auto-remove after 5 seconds
  setTimeout(() => {
    errorDiv.style.animation = "fadeOut 0.3s ease";
    setTimeout(() => errorDiv.remove(), 300);
  }, 5000);
}

/**
 * Check if user is already logged in
 */
function checkExistingSession() {
    if (SharedData.isAuthenticated()) {
        redirectToDashboard(SharedData.getRole());
    }
}

/**
 * Setup forgot password modal functionality
 */
function setupForgotPassword() {
  const forgotPasswordLink = document.getElementById("forgotPasswordLink");
  const modal = document.getElementById("forgotPasswordModal");
  const closeModal = document.querySelector(".close-modal");
  const sendResetBtn = document.getElementById("sendResetLinkBtn");
  const resetEmailInput = document.getElementById("resetEmail");

  if (!forgotPasswordLink || !modal) return;

  // Open modal
  forgotPasswordLink.addEventListener("click", function (e) {
    e.preventDefault();
    modal.classList.add("show");
    resetEmailInput.value = ""; // clear previous input
    resetEmailInput.focus();
  });

  // Close modal via (X)
  closeModal.addEventListener("click", function () {
    modal.classList.remove("show");
  });

  // Close when clicking outside of modal content
  window.addEventListener("click", function (e) {
    if (e.target === modal) {
      modal.classList.remove("show");
    }
  });

  // Send reset link simulation
  sendResetBtn.addEventListener("click", function () {
    const email = resetEmailInput.value.trim();

    if (!email) {
      showErrorInModal("Please enter your Gmail address");
      return;
    }

    if (!email.toLowerCase().endsWith("@gmail.com")) {
      showErrorInModal("Please enter a valid Gmail address");
      return;
    }

    const originalText = sendResetBtn.querySelector("span").textContent;
    sendResetBtn.querySelector("span").textContent = "Sending...";
    sendResetBtn.disabled = true;

    // Simulate sending email API call
    setTimeout(() => {
      alert(
        `A reset password link has been sent to ${email}!\n\n(This is a simulation. No database is connected yet.)`,
      );
      modal.classList.remove("show");
      sendResetBtn.querySelector("span").textContent = originalText;
      sendResetBtn.disabled = false;
    }, 1500);
  });
}

/**
 * Show error message inside the modal
 * @param {string} message - Error message to display
 */
function showErrorInModal(message) {
  const modalContent = document.querySelector(
    "#forgotPasswordModal .modal-content",
  );

  const existingError = modalContent.querySelector(".error-message");
  if (existingError) existingError.remove();

  const errorDiv = document.createElement("div");
  errorDiv.className = "error-message";
  errorDiv.textContent = message;
  errorDiv.style.cssText = `
        background-color: #fee2e2;
        color: #dc2626;
        padding: 10px;
        border-radius: 8px;
        margin-bottom: 16px;
        font-size: 14px;
        text-align: center;
        animation: fadeIn 0.3s ease;
    `;

  const formGroup = modalContent.querySelector(".form-group");
  modalContent.insertBefore(errorDiv, formGroup);

  setTimeout(() => {
    if (errorDiv.parentNode) {
      errorDiv.style.animation = "fadeOut 0.3s ease";
      setTimeout(() => errorDiv.remove(), 300);
    }
  }, 4000);
}

// Export functions for future use
if (typeof module !== "undefined" && module.exports) {
  module.exports = {
    handleLogin,
    detectUserRoleFallback,
    storeUserSession,
    redirectToDashboard,
  };
}


