// Main Page JavaScript - Login Functionality

const loginFlowState = {
  pendingOtp: null,
};

// Wait for DOM to be fully loaded
document.addEventListener("DOMContentLoaded", function () {
  initializeLoginPage();
});

/**
 * Initialize the login page
 */
function initializeLoginPage() {
  checkExistingSession();
  setupLoginForm();
  setupOtpVerification();
  setupForgotPassword();
}

/**
 * Setup login form submission
 */
function setupLoginForm() {
  const loginBtn = document.getElementById("loginBtn");
  const usernameInput = document.getElementById("username");
  const passwordInput = document.getElementById("password");
  if (!loginBtn || !usernameInput || !passwordInput) return;

  loginBtn.addEventListener("click", function (e) {
    e.preventDefault();
    handleLogin();
  });

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

function sanitizeOtpCode(value) {
  return String(value || "")
    .replace(/\D/g, "")
    .slice(0, 6);
}

function formatDisplayDateTime(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) return raw;
  return date.toLocaleString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatLockMessage(lockUntil) {
  const text = formatDisplayDateTime(lockUntil);
  if (!text) {
    return "Your account is temporarily locked due to suspicious login activity.";
  }
  return "Your account is locked until " + text + ".";
}

function setOtpInlineMessage(message, type) {
  const el = document.getElementById("otpInlineMessage");
  if (!el) return;
  const tone = String(type || "error").toLowerCase();
  const isSuccess = tone === "success";
  if (!message) {
    el.style.display = "none";
    el.textContent = "";
    return;
  }
  el.style.display = "block";
  el.textContent = String(message);
  el.style.marginTop = "8px";
  el.style.marginBottom = "14px";
  el.style.fontSize = "13px";
  el.style.fontWeight = "600";
  el.style.color = isSuccess ? "#166534" : "#b91c1c";
}

function updateOtpMetaText() {
  const meta = document.getElementById("otpMetaText");
  if (!meta) return;
  const pending = loginFlowState.pendingOtp;
  if (!pending) {
    meta.textContent = "Enter the 6-digit verification code sent to your Gmail.";
    return;
  }
  const maskedEmail = String(pending.maskedEmail || "").trim();
  const expiresAt = formatDisplayDateTime(pending.expiresAt);
  let text = "Enter the 6-digit verification code sent to your Gmail.";
  if (maskedEmail) {
    text = "Enter the 6-digit verification code sent to " + maskedEmail + ".";
  }
  if (expiresAt) {
    text += " Code expires at " + expiresAt + ".";
  }
  meta.textContent = text;
}

function openOtpModal() {
  const modal = document.getElementById("otpVerificationModal");
  const input = document.getElementById("otpCodeInput");
  if (!modal) return;
  updateOtpMetaText();
  setOtpInlineMessage("", "error");
  modal.style.display = "flex";
  modal.style.opacity = "1";
  modal.classList.add("show");
  if (input) {
    input.value = "";
    input.focus();
  }
}

function closeOtpModal() {
  const modal = document.getElementById("otpVerificationModal");
  if (!modal) return;
  modal.classList.remove("show");
  modal.style.display = "";
  modal.style.opacity = "";
}

/**
 * Handle login process — calls PHP backend API
 */
function handleLogin() {
  const username = document.getElementById("username").value.trim();
  const password = document.getElementById("password").value.trim();

  if (!username) {
    showError("Please enter a username");
    return;
  }

  if (username.length > 100 || password.length > 255) {
    showError("Invalid credentials");
    return;
  }

  const sanitizedUsername = username.replace(/<[^>]*>/g, "");
  const sanitizedPassword = password.replace(/<[^>]*>/g, "");

  const loginBtn = document.getElementById("loginBtn");
  const originalText = loginBtn.querySelector("span").textContent;
  loginBtn.querySelector("span").textContent = "Logging in...";
  loginBtn.disabled = true;

  fetch("../api/login.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      action: "login",
      username: sanitizedUsername,
      password: sanitizedPassword,
    }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        loginFlowState.pendingOtp = null;
        storeUserSession(data);
        redirectToDashboard(data.role);
        return;
      }

      if (data && data.locked) {
        loginFlowState.pendingOtp = null;
        closeOtpModal();
        showError(formatLockMessage(data.lockUntil));
        return;
      }

      if (data && data.otpRequired) {
        loginFlowState.pendingOtp = {
          username: sanitizedUsername,
          challengeId: String(data.otpChallengeId || "").trim(),
          expiresAt: String(data.otpExpiresAt || "").trim(),
          maskedEmail: String(data.maskedEmail || "").trim(),
        };
        openOtpModal();
        if (data.error) {
          setOtpInlineMessage(data.error, "error");
        }
        return;
      }

      showError((data && data.error) || "Invalid username or password");
    })
    .catch(() => {
      showError("Login service is unavailable. Please try again.");
    })
    .finally(() => {
      loginBtn.querySelector("span").textContent = originalText;
      loginBtn.disabled = false;
    });
}

function handleOtpVerification() {
  const pending = loginFlowState.pendingOtp;
  const input = document.getElementById("otpCodeInput");
  const verifyBtn = document.getElementById("verifyOtpBtn");
  if (!input || !verifyBtn) return;

  if (!pending || !pending.username || !pending.challengeId) {
    setOtpInlineMessage("OTP session not found. Please log in again.", "error");
    return;
  }

  const otpCode = sanitizeOtpCode(input.value);
  input.value = otpCode;
  if (otpCode.length !== 6) {
    setOtpInlineMessage("Please enter a valid 6-digit OTP code.", "error");
    return;
  }

  const originalText = verifyBtn.querySelector("span").textContent;
  verifyBtn.querySelector("span").textContent = "Verifying...";
  verifyBtn.disabled = true;

  fetch("../api/login.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      action: "verifyOtp",
      username: pending.username,
      otpChallengeId: pending.challengeId,
      otpCode: otpCode,
    }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data && data.success && data.otpVerified) {
        loginFlowState.pendingOtp = null;
        closeOtpModal();
        input.value = "";
        if (data.role) {
          storeUserSession(data);
          redirectToDashboard(data.role);
          return;
        }
        setOtpInlineMessage(data.message || "OTP verified.", "success");
        return;
      }

      if (data && data.locked) {
        loginFlowState.pendingOtp = null;
        closeOtpModal();
        showError(formatLockMessage(data.lockUntil));
        return;
      }

      if (data && data.otpRequired) {
        loginFlowState.pendingOtp = {
          username: pending.username,
          challengeId: String(data.otpChallengeId || pending.challengeId || "").trim(),
          expiresAt: String(data.otpExpiresAt || pending.expiresAt || "").trim(),
          maskedEmail: String(data.maskedEmail || pending.maskedEmail || "").trim(),
        };
        updateOtpMetaText();
        input.value = "";
        setOtpInlineMessage(
          data.error || "Invalid OTP code. Please try again.",
          "error",
        );
        input.focus();
        return;
      }

      setOtpInlineMessage(
        (data && data.error) || "OTP verification failed. Please log in again.",
        "error",
      );
    })
    .catch(() => {
      setOtpInlineMessage(
        "OTP verification service is unavailable. Please try again.",
        "error",
      );
    })
    .finally(() => {
      verifyBtn.querySelector("span").textContent = originalText;
      verifyBtn.disabled = false;
    });
}

function setupOtpVerification() {
  const modal = document.getElementById("otpVerificationModal");
  const closeBtn = document.getElementById("closeOtpModal");
  const verifyBtn = document.getElementById("verifyOtpBtn");
  const input = document.getElementById("otpCodeInput");
  if (!modal || !closeBtn || !verifyBtn || !input) return;

  closeBtn.addEventListener("click", function () {
    closeOtpModal();
  });

  verifyBtn.addEventListener("click", function () {
    handleOtpVerification();
  });

  input.addEventListener("input", function () {
    this.value = sanitizeOtpCode(this.value);
  });

  input.addEventListener("keypress", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      handleOtpVerification();
    }
  });

  window.addEventListener("click", function (e) {
    if (e.target === modal) {
      closeOtpModal();
    }
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
  const payload =
    authData && authData.user && typeof authData.user === "object"
      ? Object.assign({}, authData.user, {
          csrfToken:
            String((authData && authData.csrfToken) || authData.user.csrfToken || "").trim(),
        })
      : authData || {};

  const username = String((payload && payload.username) || "").trim();
  const role = String((payload && payload.role) || "").trim();
  const fullName = String((payload && payload.fullName) || username).trim();
  const userId = String((payload && payload.userId) || "").trim();
  const email = String((payload && payload.email) || "").trim();
  const studentNumber = String((payload && payload.studentNumber) || "").trim();
  const employeeId = String((payload && payload.employeeId) || "").trim();
  const status = String((payload && payload.status) || "active")
    .trim()
    .toLowerCase();
  const csrfToken = String((payload && payload.csrfToken) || "").trim();

  SharedData.setSession(username, role, {
    fullName: fullName,
    userId: userId,
    email: email,
    studentNumber: studentNumber,
    employeeId: employeeId,
    status: status === "inactive" ? "inactive" : "active",
    csrfToken: csrfToken,
  });

  // Log successful login activity
  SharedData.addActivityLogEntry({
    action: "Login",
    description: fullName + " logged in as " + role,
    role: role,
    user_id: userId || username,
    type: "login",
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
  const existingError = document.querySelector(".error-message");
  if (existingError) {
    existingError.remove();
  }

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

  const loginForm = document.querySelector(".login-form");
  loginForm.parentNode.insertBefore(errorDiv, loginForm);

  setTimeout(() => {
    errorDiv.style.animation = "fadeOut 0.3s ease";
    setTimeout(() => errorDiv.remove(), 300);
  }, 5000);
}

/**
 * Check if user is already logged in
 */
function checkExistingSession() {
  try {
    const session = SharedData.refreshSession(true);
    if (session && session.role) {
      redirectToDashboard(session.role);
    }
  } catch (_error) {
    SharedData.clearSession({ localOnly: true });
  }
}

/**
 * Setup forgot password modal functionality
 */
function setupForgotPassword() {
  const forgotPasswordLink = document.getElementById("forgotPasswordLink");
  const modal = document.getElementById("forgotPasswordModal");
  const closeModal = modal ? modal.querySelector(".close-modal") : null;
  const sendResetBtn = document.getElementById("sendResetLinkBtn");
  const resetEmailInput = document.getElementById("resetEmail");

  if (!forgotPasswordLink || !modal || !closeModal || !sendResetBtn || !resetEmailInput) return;

  forgotPasswordLink.addEventListener("click", function (e) {
    e.preventDefault();
    modal.classList.add("show");
    resetEmailInput.value = "";
    resetEmailInput.focus();
  });

  closeModal.addEventListener("click", function () {
    modal.classList.remove("show");
  });

  window.addEventListener("click", function (e) {
    if (e.target === modal) {
      modal.classList.remove("show");
    }
  });

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
 * Show error message inside the forgot password modal
 * @param {string} message - Error message to display
 */
function showErrorInModal(message) {
  const modalContent = document.querySelector("#forgotPasswordModal .modal-content");
  if (!modalContent) return;

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

if (typeof module !== "undefined" && module.exports) {
  module.exports = {
    handleLogin,
    handleOtpVerification,
    detectUserRoleFallback,
    storeUserSession,
    redirectToDashboard,
  };
}
