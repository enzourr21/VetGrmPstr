(function () {
  'use strict';

  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var usernamePattern = /^[a-zA-Z0-9_.]{3,60}$/;
  var phonePattern = /^[0-9+()\-\s]{6,30}$/;

  function animateStageIn(el, focusSelector) {
    if (!el) return;
    var wasHidden = el.hasAttribute('hidden');
    el.removeAttribute('hidden');
    if (wasHidden && !reducedMotion && typeof el.animate === 'function') {
      el.animate(
        [
          { opacity: 0, transform: 'translateY(-8px) scale(0.98)' },
          { opacity: 1, transform: 'translateY(0) scale(1)' }
        ],
        {
          duration: 220,
          easing: 'cubic-bezier(0.23, 1, 0.32, 1)',
          fill: 'both'
        }
      );
    }
    if (focusSelector) {
      var target = el.querySelector(focusSelector);
      if (target) {
        setTimeout(function () { target.focus(); }, 50);
      }
    }
  }

  function animateTextSwap(el, nextText) {
    if (!el) return;
    if (el.textContent === nextText) return;
    if (!reducedMotion) {
      el.classList.add('is-blur-swapping');
      setTimeout(function () {
        el.classList.remove('is-blur-swapping');
      }, 140);
    }
    el.textContent = nextText;
  }

  function showErrorSmooth(errEl, message) {
    if (!errEl) return;
    errEl.textContent = message;
    var wasHidden = errEl.hasAttribute('hidden');
    errEl.removeAttribute('hidden');
    if (wasHidden && !reducedMotion && typeof errEl.animate === 'function') {
      errEl.animate(
        [
          { opacity: 0, transform: 'translateY(-4px)' },
          { opacity: 1, transform: 'translateY(0)' }
        ],
        {
          duration: 180,
          easing: 'cubic-bezier(0.23, 1, 0.32, 1)',
          fill: 'both'
        }
      );
    }
  }

  function updateStepTracker(container, currentStage) {
    if (!container) return;
    var chips = container.querySelectorAll('[data-step-chip]');
    chips.forEach(function (chip) {
      var step = parseInt(chip.getAttribute('data-step-chip'), 10);
      chip.classList.remove('is-active', 'is-done');
      if (step < currentStage) {
        chip.classList.add('is-done');
      } else if (step === currentStage) {
        chip.classList.add('is-active');
      }
    });
  }

  // ---- Password Visibility Toggles with Blur-Masked State Swap --------
  document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-pw-toggle');
      var input = document.getElementById(targetId);
      if (!input) return;
      var showing = input.type === 'text';
      if (!reducedMotion) {
        input.classList.add('is-blur-swapping');
        setTimeout(function () {
          input.classList.remove('is-blur-swapping');
        }, 140);
      }
      input.type = showing ? 'password' : 'text';
      btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
      btn.setAttribute('aria-label', (showing ? 'Show ' : 'Hide ') + (input.name === 'password_confirm' ? 'confirmation password' : 'password'));
    });
  });

  // ---- Sign-In: Email-First (Strictly via Continue Action) ------------
  var loginForm = document.querySelector('[data-progressive-login]');
  if (loginForm) {
    var emailInput = loginForm.querySelector('#email');
    var emailError = loginForm.querySelector('[data-email-error]');
    var continueWrap = loginForm.querySelector('[data-login-continue-wrap]');
    var continueBtn = loginForm.querySelector('[data-login-continue]');
    var editEmailBtn = loginForm.querySelector('[data-login-edit-email]');
    var passwordStage = loginForm.querySelector('#login-stage-password');

    function validateAndUnlockLogin() {
      var val = (emailInput.value || '').trim();
      var isValidIdentifier = val.indexOf('@') !== -1 ? emailPattern.test(val) : usernamePattern.test(val);
      if (!isValidIdentifier) {
        showErrorSmooth(emailError, 'Enter a valid email address (for example name@example.com) or username.');
        emailInput.focus();
        return false;
      }
      if (emailError) {
        emailError.setAttribute('hidden', 'hidden');
      }
      if (continueWrap) {
        continueWrap.setAttribute('hidden', 'hidden');
      }
      if (editEmailBtn) {
        editEmailBtn.removeAttribute('hidden');
      }
      animateStageIn(passwordStage, '#password');
      updateStepTracker(loginForm.closest('.auth-card') || loginForm, 2);
      return true;
    }

    if (continueBtn) {
      continueBtn.addEventListener('click', function () {
        validateAndUnlockLogin();
      });
    }

    if (editEmailBtn) {
      editEmailBtn.addEventListener('click', function () {
        if (passwordStage) passwordStage.setAttribute('hidden', 'hidden');
        if (continueWrap) animateStageIn(continueWrap);
        editEmailBtn.setAttribute('hidden', 'hidden');
        updateStepTracker(loginForm.closest('.auth-card') || loginForm, 1);
        emailInput.focus();
      });
    }

    if (emailInput) {
      emailInput.addEventListener('input', function () {
        if (emailError && !emailError.hasAttribute('hidden')) {
          emailError.setAttribute('hidden', 'hidden');
        }
      });

      emailInput.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && passwordStage && passwordStage.hasAttribute('hidden')) {
          ev.preventDefault();
          validateAndUnlockLogin();
        }
      });
    }

    loginForm.addEventListener('submit', function (ev) {
      if (passwordStage && passwordStage.hasAttribute('hidden')) {
        ev.preventDefault();
        validateAndUnlockLogin();
      }
    });
  }

  // ---- Registration: 4-Step Progressive Disclosure --------------------
  var regForm = document.querySelector('[data-progressive-register]');
  if (regForm) {
    var regCard = regForm.closest('.auth-card') || regForm;

    // Step 1: Full Name
    var stage1 = regForm.querySelector('#reg-stage-1');
    var firstName = regForm.querySelector('#first_name');
    var middleName = regForm.querySelector('#middle_name');
    var lastName = regForm.querySelector('#last_name');
    var regNameError = regForm.querySelector('[data-reg-name-error]');
    var step1Actions = regForm.querySelector('[data-reg-step1-actions]');
    var unlock2Btn = regForm.querySelector('[data-reg-unlock-2]');

    // Step 2: Additional Details
    var stage2 = regForm.querySelector('#reg-stage-2');
    var phoneInput = regForm.querySelector('#phone');
    var regPhoneError = regForm.querySelector('[data-reg-phone-error]');
    var addressInput = regForm.querySelector('#address_line1');
    var cityInput = regForm.querySelector('#city');
    var provinceInput = regForm.querySelector('#province');
    var postalCodeInput = regForm.querySelector('#postal_code');
    var regPostalError = regForm.querySelector('[data-reg-postal-error]');
    var step2Actions = regForm.querySelector('[data-reg-step2-actions]');
    var unlock3Btn = regForm.querySelector('[data-reg-unlock-3]');

    // Step 3: Set and Verify Email
    var stage3 = regForm.querySelector('#reg-stage-3');
    var regEmail = regForm.querySelector('#email');
    var regEmailError = regForm.querySelector('[data-reg-email-error]');
    var sendOtpBtn = regForm.querySelector('[data-send-otp-btn]');
    var otpSection = regForm.querySelector('[data-otp-section]');
    var otpBoxes = regForm.querySelectorAll('[data-otp-box]');
    var hiddenOtpInput = regForm.querySelector('#email_otp');
    var otpFeedback = regForm.querySelector('[data-otp-feedback]');
    var step3Actions = regForm.querySelector('[data-reg-step3-actions]');
    var unlock4Btn = regForm.querySelector('[data-reg-unlock-4]');

    // Step 4: Set Password
    var stage4 = regForm.querySelector('#reg-stage-4');
    var pwInput = regForm.querySelector('#password');
    var pwConfirmInput = regForm.querySelector('#password_confirm');
    var ruleLength = regForm.querySelector('[data-pw-rule="length"]');
    var ruleMatch = regForm.querySelector('[data-pw-rule="match"]');
    var ruleMatchText = regForm.querySelector('[data-pw-match-text]');
    var submitBtn = regForm.querySelector('#reg-submit-btn');

    // Email verification state (Step 4 is strictly inaccessible until verification succeeds)
    var csrfInput = regForm.querySelector('input[name="csrf_token"]');
    var isEmailVerified = stage4 && !stage4.hasAttribute('hidden');
    var isSendingOtp = false;
    var isVerifyingOtp = false;
    var otpSentForEmail = '';
    var cooldownTimerId = null;
    var cooldownRemaining = 0;

    if (!isEmailVerified && otpSection) {
      otpSection.setAttribute('hidden', 'hidden');
    }

    function getCsrfToken() {
      return csrfInput ? (csrfInput.value || '') : '';
    }

    function updateCsrfToken(data) {
      if (data && typeof data.csrf_token === 'string' && data.csrf_token !== '' && csrfInput) {
        csrfInput.value = data.csrf_token;
      }
    }

    function postFormJson(url, params) {
      return fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: params.toString()
      }).then(function (resp) {
        return resp.json().catch(function () {
          return { ok: false, status: resp.status, error: 'Unexpected server response. Please try again.' };
        }).then(function (data) {
          if (typeof data.status !== 'number') {
            data.status = resp.status;
          }
          updateCsrfToken(data);
          return data;
        });
      });
    }

    function stopResendCooldown() {
      if (cooldownTimerId !== null) {
        clearInterval(cooldownTimerId);
        cooldownTimerId = null;
      }
      cooldownRemaining = 0;
      if (sendOtpBtn && !isSendingOtp) {
        sendOtpBtn.removeAttribute('disabled');
        sendOtpBtn.textContent = otpSentForEmail !== '' ? 'Resend Code' : 'Send Verification Code';
      }
    }

    function startResendCooldown(seconds) {
      stopResendCooldown();
      cooldownRemaining = Math.max(1, parseInt(seconds, 10) || 60);
      if (sendOtpBtn) {
        sendOtpBtn.setAttribute('disabled', 'disabled');
        sendOtpBtn.textContent = 'Resend (' + cooldownRemaining + 's)';
      }
      cooldownTimerId = setInterval(function () {
        cooldownRemaining -= 1;
        if (cooldownRemaining <= 0) {
          stopResendCooldown();
        } else if (sendOtpBtn) {
          sendOtpBtn.textContent = 'Resend (' + cooldownRemaining + 's)';
        }
      }, 1000);
    }

    function stageHasVisibleError(stageEl) {
      if (!stageEl) return false;
      var errs = stageEl.querySelectorAll('.field-error');
      for (var i = 0; i < errs.length; i++) {
        if (!errs[i].hasAttribute('hidden') && (errs[i].textContent || '').trim() !== '') {
          return true;
        }
      }
      return false;
    }

    function setMobileStageCollapsed(stageEl, editBtn, collapsed) {
      if (!stageEl) return;
      stageEl.classList.toggle('is-mobile-collapsed', collapsed);
      if (editBtn) {
        editBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        editBtn.textContent = collapsed ? 'Edit' : 'Done';
      }
    }

    function syncMobileRegisterStages(activeStageNum) {
      var stages = [stage1, stage2, stage3, stage4];
      stages.forEach(function (stEl, idx) {
        var stepNum = idx + 1;
        if (!stEl) return;
        var editBtn = regForm.querySelector('[data-stage-edit="' + stepNum + '"]');
        if (stepNum < activeStageNum) {
          if (editBtn) editBtn.removeAttribute('hidden');
          setMobileStageCollapsed(stEl, editBtn, !stageHasVisibleError(stEl));
        } else {
          if (editBtn) editBtn.setAttribute('hidden', 'hidden');
          setMobileStageCollapsed(stEl, editBtn, false);
        }
      });
    }

    regForm.querySelectorAll('[data-stage-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var stepNum = btn.getAttribute('data-stage-edit');
        var stEl = regForm.querySelector('[data-progressive-stage="' + stepNum + '"]');
        if (!stEl) return;
        var willCollapse = !stEl.classList.contains('is-mobile-collapsed');
        setMobileStageCollapsed(stEl, btn, willCollapse);
      });
    });

    // Initialize mobile collapse state if PHP rendered at a later stage (e.g., server validation error reload)
    var initialActiveStage = 1;
    if (stage4 && !stage4.hasAttribute('hidden')) initialActiveStage = 4;
    else if (stage3 && !stage3.hasAttribute('hidden')) initialActiveStage = 3;
    else if (stage2 && !stage2.hasAttribute('hidden')) initialActiveStage = 2;
    if (initialActiveStage > 1) {
      syncMobileRegisterStages(initialActiveStage);
    }

    function unlockStep4Ui() {
      if (step3Actions) step3Actions.setAttribute('hidden', 'hidden');
      syncMobileRegisterStages(4);
      animateStageIn(stage4, '#password');
      updateStepTracker(regCard, 4);
      evaluatePasswordState();
    }

    function showOtpFeedback(msg, type) {
      if (!otpFeedback) return;
      otpFeedback.textContent = msg;
      otpFeedback.className = 'otp-feedback-msg is-' + type;
      otpFeedback.removeAttribute('hidden');
    }

    function getEnteredOtp() {
      var code = '';
      otpBoxes.forEach(function (b) {
        code += (b.value || '').trim();
      });
      return code;
    }

    function clearOtpBoxes() {
      otpBoxes.forEach(function (b) {
        b.value = '';
        b.classList.remove('is-filled', 'is-invalid');
        b.removeAttribute('disabled');
      });
      if (hiddenOtpInput) hiddenOtpInput.value = '';
    }

    function proceedToStep2() {
      var fn = (firstName ? firstName.value : '').trim();
      var ln = (lastName ? lastName.value : '').trim();
      if (fn.length < 1 || ln.length < 1) {
        showErrorSmooth(regNameError, 'Please enter both your first name and last name to continue.');
        if (fn.length < 1) {
          if (firstName) firstName.focus();
        } else {
          if (lastName) lastName.focus();
        }
        return false;
      }
      if (regNameError) regNameError.setAttribute('hidden', 'hidden');
      if (step1Actions) step1Actions.setAttribute('hidden', 'hidden');
      syncMobileRegisterStages(2);
      animateStageIn(stage2, '#phone');
      updateStepTracker(regCard, 2);
      return true;
    }

    function proceedToStep3() {
      var ph = (phoneInput ? phoneInput.value : '').trim();
      if (ph !== '' && !phonePattern.test(ph)) {
        showErrorSmooth(regPhoneError, 'Please enter a valid phone number (6–30 digits) or leave optional.');
        phoneInput.focus();
        return false;
      }
      var pc = (postalCodeInput ? postalCodeInput.value : '').trim();
      if (pc !== '' && !/^[0-9]{4}$/.test(pc)) {
        showErrorSmooth(regPostalError, 'Postal code must accept exactly four numeric digits.');
        postalCodeInput.focus();
        return false;
      }
      if (regPhoneError) regPhoneError.setAttribute('hidden', 'hidden');
      if (regPostalError) regPostalError.setAttribute('hidden', 'hidden');
      if (step2Actions) step2Actions.setAttribute('hidden', 'hidden');
      syncMobileRegisterStages(3);
      animateStageIn(stage3, '#email');
      updateStepTracker(regCard, 3);
      return true;
    }

    function handleSendOtp() {
      var emailVal = (regEmail ? regEmail.value : '').trim();
      if (!emailPattern.test(emailVal)) {
        showErrorSmooth(regEmailError, 'Enter a valid email address, for example name@example.com.');
        if (regEmail) regEmail.focus();
        return;
      }
      if (regEmailError) regEmailError.setAttribute('hidden', 'hidden');
      if (isSendingOtp || isVerifyingOtp || cooldownRemaining > 0) return;

      var isResend = otpSentForEmail !== '' && otpSentForEmail === emailVal;
      var endpoint = isResend ? '/register/otp/resend' : '/register/otp/send';
      var params = new URLSearchParams();
      params.append('csrf_token', getCsrfToken());

      if (!isResend) {
        params.append('first_name', firstName ? firstName.value.trim() : '');
        params.append('middle_name', middleName ? middleName.value.trim() : '');
        params.append('last_name', lastName ? lastName.value.trim() : '');
        params.append('phone', phoneInput ? phoneInput.value.trim() : '');
        params.append('address_line1', addressInput ? addressInput.value.trim() : '');
        params.append('city', cityInput ? cityInput.value.trim() : '');
        params.append('province', provinceInput ? provinceInput.value.trim() : '');
        params.append('postal_code', postalCodeInput ? postalCodeInput.value.trim() : '');
        params.append('email', emailVal);
      }

      isSendingOtp = true;
      if (sendOtpBtn) {
        sendOtpBtn.setAttribute('disabled', 'disabled');
        sendOtpBtn.textContent = isResend ? 'Resending...' : 'Sending...';
      }
      if (otpSection && !otpSection.hasAttribute('hidden')) {
        showOtpFeedback('Sending verification code...', 'info');
      }

      postFormJson(endpoint, params)
        .then(function (data) {
          isSendingOtp = false;
          if (data && data.ok) {
            otpSentForEmail = emailVal;
            if (data.already_verified) {
              isEmailVerified = true;
              stopResendCooldown();
              if (otpSection) otpSection.removeAttribute('hidden');
              showOtpFeedback(data.message || 'Email is already verified.', 'info');
              unlockStep4Ui();
              return;
            }
            if (otpSection) otpSection.removeAttribute('hidden');
            clearOtpBoxes();
            showOtpFeedback(data.message || 'A 6-digit verification code has been sent to your email.', 'info');
            startResendCooldown(60);
            if (otpBoxes.length > 0) {
              otpBoxes[0].focus();
            }
            return;
          }

          var errMsg = (data && data.error) ? data.error : 'Unable to send verification code. Please try again.';
          if (data && data.errors && typeof data.errors === 'object') {
            if (data.errors.email) errMsg = data.errors.email;
            else if (data.errors.first_name) errMsg = data.errors.first_name;
            else if (data.errors.last_name) errMsg = data.errors.last_name;
            else if (data.errors.phone) errMsg = data.errors.phone;
            else if (data.errors.postal_code) errMsg = data.errors.postal_code;
          }

          if (data && typeof data.retry_after === 'number' && data.retry_after > 0) {
            if (otpSection) otpSection.removeAttribute('hidden');
            startResendCooldown(data.retry_after);
          } else if (sendOtpBtn) {
            sendOtpBtn.removeAttribute('disabled');
            sendOtpBtn.textContent = isResend ? 'Resend Code' : 'Send Verification Code';
          }

          if (otpSection && !otpSection.hasAttribute('hidden')) {
            showOtpFeedback(errMsg, 'error');
          } else {
            showErrorSmooth(regEmailError, errMsg);
          }
        })
        .catch(function () {
          isSendingOtp = false;
          if (sendOtpBtn) {
            sendOtpBtn.removeAttribute('disabled');
            sendOtpBtn.textContent = isResend ? 'Resend Code' : 'Send Verification Code';
          }
          var netErr = 'Network error while sending verification code. Please try again.';
          if (otpSection && !otpSection.hasAttribute('hidden')) {
            showOtpFeedback(netErr, 'error');
          } else {
            showErrorSmooth(regEmailError, netErr);
          }
        });
    }

    function proceedToStep4() {
      var emailVal = (regEmail ? regEmail.value : '').trim();
      if (!emailPattern.test(emailVal)) {
        showErrorSmooth(regEmailError, 'Enter a valid email address, for example name@example.com.');
        if (regEmail) regEmail.focus();
        return false;
      }
      if (regEmailError) regEmailError.setAttribute('hidden', 'hidden');

      if (isEmailVerified) {
        unlockStep4Ui();
        return true;
      }

      if (otpSentForEmail === '' || otpSentForEmail !== emailVal) {
        showErrorSmooth(regEmailError, 'Please click Send Verification Code to verify your email address.');
        if (sendOtpBtn) sendOtpBtn.focus();
        return false;
      }

      var code = getEnteredOtp();
      if (code.length < 6) {
        showOtpFeedback('Please enter all 6 digits of the verification code.', 'error');
        var firstEmpty = null;
        otpBoxes.forEach(function (b) {
          if (!b.value) {
            b.classList.add('is-invalid');
            if (!firstEmpty) firstEmpty = b;
          }
        });
        if (firstEmpty) firstEmpty.focus();
        return false;
      }

      if (isVerifyingOtp || isSendingOtp) return false;
      isVerifyingOtp = true;
      if (unlock4Btn) {
        unlock4Btn.setAttribute('disabled', 'disabled');
        unlock4Btn.textContent = 'Verifying...';
      }

      var verifyParams = new URLSearchParams();
      verifyParams.append('csrf_token', getCsrfToken());
      verifyParams.append('otp', code);

      postFormJson('/register/otp/verify', verifyParams)
        .then(function (data) {
          isVerifyingOtp = false;
          if (unlock4Btn) {
            unlock4Btn.removeAttribute('disabled');
            unlock4Btn.textContent = 'Continue';
          }

          if (data && data.ok) {
            isEmailVerified = true;
            stopResendCooldown();
            otpBoxes.forEach(function (b) {
              b.classList.remove('is-invalid');
            });
            showOtpFeedback(data.message || 'Email verified successfully.', 'info');
            unlockStep4Ui();
            return;
          }

          otpBoxes.forEach(function (b) {
            b.classList.add('is-invalid');
          });
          var errMsg = (data && data.error) ? data.error : 'Verification failed. Please check the code and try again.';
          showOtpFeedback(errMsg, 'error');
          if (data && (data.invalidated || data.expired)) {
            clearOtpBoxes();
            if (sendOtpBtn && cooldownRemaining <= 0) {
              sendOtpBtn.focus();
            }
          } else if (otpBoxes.length > 0) {
            otpBoxes[0].focus();
          }
        })
        .catch(function () {
          isVerifyingOtp = false;
          if (unlock4Btn) {
            unlock4Btn.removeAttribute('disabled');
            unlock4Btn.textContent = 'Continue';
          }
          showOtpFeedback('Network error while verifying code. Please try again.', 'error');
        });

      return false;
    }

    function evaluatePasswordState() {
      if (!pwInput || !pwConfirmInput) return;
      var pw = pwInput.value || '';
      var cf = pwConfirmInput.value || '';
      var lengthOk = pw.length >= 10;
      var matchOk = pw.length > 0 && cf.length > 0 && pw === cf;
      var mismatch = cf.length > 0 && pw !== cf;

      if (ruleLength) {
        ruleLength.classList.toggle('is-met', lengthOk);
        var lenIcon = ruleLength.querySelector('.pw-rule-icon');
        if (lenIcon) lenIcon.textContent = lengthOk ? '✓' : '•';
      }

      if (ruleMatch && ruleMatchText) {
        ruleMatch.classList.remove('is-met', 'is-mismatch');
        var matchIcon = ruleMatch.querySelector('.pw-rule-icon');
        if (matchOk) {
          ruleMatch.classList.add('is-met');
          animateTextSwap(ruleMatchText, 'Passwords match');
          if (matchIcon) matchIcon.textContent = '✓';
        } else if (mismatch) {
          ruleMatch.classList.add('is-mismatch');
          animateTextSwap(ruleMatchText, 'Passwords do not match');
          if (matchIcon) matchIcon.textContent = '!';
        } else {
          animateTextSwap(ruleMatchText, 'Both passwords must match');
          if (matchIcon) matchIcon.textContent = '•';
        }
      }

      if (submitBtn) {
        if (lengthOk && matchOk) {
          submitBtn.removeAttribute('disabled');
        } else {
          submitBtn.setAttribute('disabled', 'disabled');
        }
      }
    }

    // Step 1 event listeners
    if (unlock2Btn) {
      unlock2Btn.addEventListener('click', proceedToStep2);
    }
    [firstName, middleName, lastName].forEach(function (inp) {
      if (!inp) return;
      inp.addEventListener('input', function () {
        if (regNameError && !regNameError.hasAttribute('hidden')) {
          regNameError.setAttribute('hidden', 'hidden');
        }
      });
      inp.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && stage2 && stage2.hasAttribute('hidden')) {
          ev.preventDefault();
          proceedToStep2();
        }
      });
    });

    // Step 2 event listeners
    if (unlock3Btn) {
      unlock3Btn.addEventListener('click', proceedToStep3);
    }
    if (postalCodeInput) {
      postalCodeInput.addEventListener('input', function () {
        // Enforce exactly numeric digits; reject letters, spaces, and special characters
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4);
        if (regPostalError && !regPostalError.hasAttribute('hidden')) {
          regPostalError.setAttribute('hidden', 'hidden');
        }
      });
    }
    if (phoneInput) {
      phoneInput.addEventListener('input', function () {
        if (regPhoneError && !regPhoneError.hasAttribute('hidden')) {
          regPhoneError.setAttribute('hidden', 'hidden');
        }
      });
    }
    [phoneInput, addressInput, cityInput, provinceInput, postalCodeInput].forEach(function (inp) {
      if (!inp) return;
      inp.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && stage3 && stage3.hasAttribute('hidden')) {
          ev.preventDefault();
          proceedToStep3();
        }
      });
    });

    // Step 3 event listeners
    if (sendOtpBtn) {
      sendOtpBtn.addEventListener('click', handleSendOtp);
    }
    if (unlock4Btn) {
      unlock4Btn.addEventListener('click', proceedToStep4);
    }
    if (regEmail) {
      regEmail.addEventListener('input', function () {
        if (regEmailError && !regEmailError.hasAttribute('hidden')) {
          regEmailError.setAttribute('hidden', 'hidden');
        }
        var currentEmail = (regEmail.value || '').trim();
        if (otpSentForEmail !== '' && currentEmail !== otpSentForEmail) {
          otpSentForEmail = '';
          stopResendCooldown();
          clearOtpBoxes();
          if (otpSection) otpSection.setAttribute('hidden', 'hidden');
        }
        if (isEmailVerified) {
          isEmailVerified = false;
          otpSentForEmail = '';
          stopResendCooldown();
          clearOtpBoxes();
          if (otpSection) otpSection.setAttribute('hidden', 'hidden');
          if (stage4) stage4.setAttribute('hidden', 'hidden');
          if (step3Actions) step3Actions.removeAttribute('hidden');
          syncMobileRegisterStages(3);
          updateStepTracker(regCard, 3);
        }
      });
      regEmail.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          handleSendOtp();
        }
      });
    }

    // 6 OTP boxes: numeric input, auto-advance, backspace navigation, paste complete code
    otpBoxes.forEach(function (box, idx) {
      box.addEventListener('focus', function () {
        box.select();
      });

      box.addEventListener('input', function () {
        var raw = (box.value || '').replace(/\D/g, '');
        if (raw.length > 0) {
          box.value = raw.charAt(raw.length - 1);
          box.classList.add('is-filled');
          box.classList.remove('is-invalid');
          if (idx < otpBoxes.length - 1) {
            otpBoxes[idx + 1].focus();
            otpBoxes[idx + 1].select();
          }
        } else {
          box.value = '';
          box.classList.remove('is-filled');
        }
        if (hiddenOtpInput) hiddenOtpInput.value = getEnteredOtp();
        if (otpFeedback && !otpFeedback.hasAttribute('hidden')) {
          otpFeedback.setAttribute('hidden', 'hidden');
        }
      });

      box.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace') {
          if (!box.value && idx > 0) {
            e.preventDefault();
            var prevBox = otpBoxes[idx - 1];
            prevBox.focus();
            prevBox.value = '';
            prevBox.classList.remove('is-filled');
            if (hiddenOtpInput) hiddenOtpInput.value = getEnteredOtp();
          } else {
            box.value = '';
            box.classList.remove('is-filled');
            if (hiddenOtpInput) hiddenOtpInput.value = getEnteredOtp();
          }
        } else if (e.key === 'ArrowLeft' && idx > 0) {
          e.preventDefault();
          otpBoxes[idx - 1].focus();
          otpBoxes[idx - 1].select();
        } else if (e.key === 'ArrowRight' && idx < otpBoxes.length - 1) {
          e.preventDefault();
          otpBoxes[idx + 1].focus();
          otpBoxes[idx + 1].select();
        } else if (e.key === 'Enter') {
          e.preventDefault();
          proceedToStep4();
        }
      });

      box.addEventListener('paste', function (e) {
        e.preventDefault();
        var clipboard = e.clipboardData || window.clipboardData;
        var text = clipboard ? clipboard.getData('text') : '';
        var digits = (text || '').replace(/\D/g, '').slice(0, 6);
        if (!digits) return;

        var start = digits.length === 6 ? 0 : idx;
        for (var i = 0; i < digits.length && (start + i) < otpBoxes.length; i++) {
          var targetBox = otpBoxes[start + i];
          targetBox.value = digits[i];
          targetBox.classList.add('is-filled');
          targetBox.classList.remove('is-invalid');
        }
        if (hiddenOtpInput) hiddenOtpInput.value = getEnteredOtp();
        if (otpFeedback && !otpFeedback.hasAttribute('hidden')) {
          otpFeedback.setAttribute('hidden', 'hidden');
        }
        var nextIdx = Math.min(start + digits.length, otpBoxes.length - 1);
        otpBoxes[nextIdx].focus();
        if (nextIdx === otpBoxes.length - 1 && digits.length === 6) {
          otpBoxes[nextIdx].select();
        }
      });
    });

    // Step 4 password listeners
    if (pwInput) pwInput.addEventListener('input', evaluatePasswordState);
    if (pwConfirmInput) pwConfirmInput.addEventListener('input', evaluatePasswordState);
    if (isEmailVerified) evaluatePasswordState();
  }

  // ---- Services Segmented Filter Bar ----------------------------------
  var serviceFilterBtns = document.querySelectorAll('[data-service-filter]');
  if (serviceFilterBtns.length > 0) {
    var vetSection = document.getElementById('veterinary');
    var groomSection = document.getElementById('grooming');
    serviceFilterBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-service-filter');
        serviceFilterBtns.forEach(function (b) {
          b.classList.toggle('is-active', b === btn);
          b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
        });
        if (vetSection) {
          if (target === 'all' || target === 'veterinary') {
            animateStageIn(vetSection);
          } else {
            vetSection.setAttribute('hidden', 'hidden');
          }
        }
        if (groomSection) {
          if (target === 'all' || target === 'grooming') {
            animateStageIn(groomSection);
          } else {
            groomSection.setAttribute('hidden', 'hidden');
          }
        }
      });
    });
  }

  // ---- Customer Reviews Sticky Composer, Validation & Loading State ---
  var reviewWorkspace = document.querySelector('[data-reviews-workspace]');
  if (reviewWorkspace) {
    var reviewDock = reviewWorkspace.querySelector('[data-review-composer]');
    var reviewToggle = reviewWorkspace.querySelector('[data-review-composer-toggle]');
    var reviewForm = reviewWorkspace.querySelector('[data-review-form]');

    if (reviewDock && reviewToggle) {
      reviewToggle.addEventListener('click', function () {
        var expanded = reviewDock.classList.toggle('is-expanded');
        reviewToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        reviewToggle.textContent = expanded ? 'Minimize' : 'Expand';
      });
    }

    if (reviewForm) {
      var titleInput = reviewForm.querySelector('#review_title');
      var commentInput = reviewForm.querySelector('#comment');
      var charCounter = reviewForm.querySelector('[data-review-char-count]');
      var alertBox = reviewForm.querySelector('[data-review-form-alert]');
      var titleError = reviewForm.querySelector('[data-review-error="review_title"]');
      var commentError = reviewForm.querySelector('[data-review-error="comment"]');
      var reviewSubmitBtn = reviewForm.querySelector('[data-review-submit]');

      function syncReviewCharCount() {
        if (!commentInput || !charCounter) return;
        charCounter.textContent = (commentInput.value || '').length + ' / 1500';
      }

      function clearReviewError(errEl) {
        if (errEl) {
          errEl.textContent = '';
          errEl.setAttribute('hidden', 'hidden');
        }
      }

      if (titleInput) {
        titleInput.addEventListener('input', function () {
          clearReviewError(titleError);
          if (alertBox) alertBox.setAttribute('hidden', 'hidden');
        });
      }

      if (commentInput) {
        commentInput.addEventListener('input', function () {
          syncReviewCharCount();
          clearReviewError(commentError);
          if (alertBox) alertBox.setAttribute('hidden', 'hidden');
        });
        syncReviewCharCount();
      }

      reviewForm.addEventListener('submit', function (ev) {
        if (reviewForm._submitting) {
          ev.preventDefault();
          return;
        }

        var titleVal = (titleInput ? titleInput.value : '').trim();
        var commentVal = (commentInput ? commentInput.value : '').trim();
        var firstBad = null;

        clearReviewError(titleError);
        clearReviewError(commentError);
        if (alertBox) alertBox.setAttribute('hidden', 'hidden');

        if (titleVal === '' || titleVal.length > 150) {
          showErrorSmooth(titleError, 'Enter a short headline for your review (max 150 characters).');
          firstBad = firstBad || titleInput;
        }
        if (commentVal.length < 5 || commentVal.length > 1500) {
          showErrorSmooth(commentError, 'Share a few words about your experience (5 to 1500 characters).');
          firstBad = firstBad || commentInput;
        }

        if (firstBad) {
          ev.preventDefault();
          if (reviewDock && !reviewDock.classList.contains('is-expanded')) {
            reviewDock.classList.add('is-expanded');
            if (reviewToggle) {
              reviewToggle.setAttribute('aria-expanded', 'true');
              reviewToggle.textContent = 'Minimize';
            }
          }
          if (alertBox) {
            showErrorSmooth(alertBox, 'Please fix the highlighted review fields before submitting.');
          }
          firstBad.focus();
          return;
        }

        reviewForm._submitting = true;
        if (reviewSubmitBtn) {
          reviewSubmitBtn.disabled = true;
          reviewSubmitBtn.textContent = 'Submitting review...';
        }
      });
    }
  }

  // ---- Homepage Business Unit Image Fallback ------------------------
  document.querySelectorAll('[data-fallback-img]').forEach(function (img) {
    function revealFallback() {
      var media = img.closest('[data-unit-media]');
      if (!media) return;
      media.classList.add('is-image-error');
      img.hidden = true;
      var fallback = media.querySelector('.home-unit-fallback');
      if (fallback) {
        fallback.hidden = false;
      }
    }

    img.addEventListener('error', revealFallback);
    if (img.complete && img.naturalWidth === 0) {
      revealFallback();
    }
  });

  // ---- Live Grooming Appointment Selection Summary --------------------
  var apptForm = document.querySelector('[data-appointment-form]');
  if (apptForm) {
    var summaryText = apptForm.querySelector('[data-appointment-summary]');
    var checkboxes = apptForm.querySelectorAll('input[type="checkbox"][data-price]');
    var updateApptSummary = function () {
      if (!summaryText) return;
      var petsSelected = {};
      var count = 0;
      var total = 0;
      var mins = 0;
      checkboxes.forEach(function (cb) {
        if (cb.checked) {
          count += 1;
          total += parseFloat(cb.getAttribute('data-price') || '0');
          mins += parseInt(cb.getAttribute('data-mins') || '0', 10);
          var petId = cb.getAttribute('data-pet-id');
          if (petId) petsSelected[petId] = true;
        }
      });
      var petCount = Object.keys(petsSelected).length;
      var nextMsg = count === 0
        ? 'Select at least one grooming service for your pet(s) below.'
        : count + ' service' + (count === 1 ? '' : 's') + ' selected across ' + petCount + ' pet' + (petCount === 1 ? '' : 's') + ' · Est. ' + mins + ' mins · $' + total.toFixed(2);
      animateTextSwap(summaryText, nextMsg);
    };
    checkboxes.forEach(function (cb) {
      cb.addEventListener('change', updateApptSummary);
    });
    updateApptSummary();
  }

  // ---- Checkout Fulfillment Progressive Feedback ----------------------
  var checkoutForm = document.querySelector('[data-checkout-form]');
  if (checkoutForm) {
    var deliveryWrap = checkoutForm.querySelector('[data-delivery-fields]');
    var deliveryHint = checkoutForm.querySelector('[data-delivery-hint]');
    var submitBtn = checkoutForm.querySelector('[data-checkout-submit]');
    var summaryBox = document.querySelector('[data-checkout-summary]');
    var subtotalEl = summaryBox ? summaryBox.querySelector('[data-checkout-subtotal]') : null;
    var feeEl = summaryBox ? summaryBox.querySelector('[data-checkout-delivery-fee]') : null;
    var feeNoteEl = summaryBox ? summaryBox.querySelector('[data-checkout-delivery-note]') : null;
    var totalEl = summaryBox ? summaryBox.querySelector('[data-checkout-total]') : null;
    var radios = checkoutForm.querySelectorAll('input[name="fulfillment_method"]');

    var updateFulfillment = function () {
      var selected = checkoutForm.querySelector('input[name="fulfillment_method"]:checked');
      var isDelivery = selected && selected.value === 'delivery';
      var subtotalFmt = checkoutForm.getAttribute('data-subtotal-formatted') || '';
      var pickupFeeFmt = checkoutForm.getAttribute('data-pickup-fee-formatted') || '';
      var pickupTotalFmt = checkoutForm.getAttribute('data-pickup-total-formatted') || subtotalFmt;
      var deliveryFeeFmt = checkoutForm.getAttribute('data-delivery-fee-formatted') || '';
      var deliveryTotalFmt = checkoutForm.getAttribute('data-delivery-total-formatted') || '';
      var feeConfigured = checkoutForm.getAttribute('data-delivery-fee-configured') === '1' && deliveryFeeFmt !== '' && deliveryTotalFmt !== '';
      var noPayment = checkoutForm.getAttribute('data-no-payment-available') === '1';

      if (deliveryWrap) {
        deliveryWrap.classList.toggle('is-dimmed', !isDelivery);
      }
      if (deliveryHint) {
        animateTextSwap(
          deliveryHint,
          isDelivery
            ? 'Required for delivery: enter the street address, city, and postal code.'
            : 'Optional for branch pickup — only required when Delivery is selected.'
        );
      }
      if (subtotalEl && subtotalFmt !== '') {
        subtotalEl.textContent = subtotalFmt;
      }
      if (feeEl && totalEl) {
        if (!isDelivery) {
          if (pickupFeeFmt !== '') feeEl.textContent = pickupFeeFmt;
          if (pickupTotalFmt !== '') totalEl.textContent = pickupTotalFmt;
          if (feeNoteEl) feeNoteEl.textContent = '(Pickup — no delivery fee)';
        } else if (feeConfigured) {
          feeEl.textContent = deliveryFeeFmt;
          totalEl.textContent = deliveryTotalFmt;
          if (feeNoteEl) feeNoteEl.textContent = '(Configured delivery fee)';
        } else {
          feeEl.textContent = 'Unavailable';
          totalEl.textContent = 'Unavailable';
          if (feeNoteEl) feeNoteEl.textContent = '(Delivery unavailable — fee not configured)';
        }
      }
      if (submitBtn) {
        submitBtn.disabled = noPayment || (isDelivery && !feeConfigured);
      }
    };
    radios.forEach(function (r) {
      r.addEventListener('change', updateFulfillment);
    });
    updateFulfillment();
  }

  // ---- Dynamic Business-Unit Position & Role Filtering (Super Admin) ---
  document.querySelectorAll('[data-unit-position-form]').forEach(function (form) {
    var unitSelect = form.querySelector('[data-unit-select]');
    var posSelect = form.querySelector('[data-position-select]');
    var roleCheckboxes = form.querySelectorAll('input[type="checkbox"][name="roles[]"]');
    if (!unitSelect || !posSelect) return;

    var positionCatalog = [];
    Array.prototype.forEach.call(posSelect.querySelectorAll('option[data-unit-id]'), function (opt) {
      positionCatalog.push({
        value: opt.value,
        text: (opt.textContent || '').trim(),
        unitId: (opt.getAttribute('data-unit-id') || '').trim(),
        defaultRoles: (opt.getAttribute('data-default-roles') || '').trim()
      });
    });
    var initialPosValue = posSelect.value || '';
    var autoCheckedByPosition = {};

    roleCheckboxes.forEach(function (cb) {
      cb.addEventListener('change', function () {
        delete autoCheckedByPosition[cb.value];
      });
    });

    function syncPositionRoles() {
      roleCheckboxes.forEach(function (cb) {
        if (autoCheckedByPosition[cb.value]) {
          cb.checked = false;
          delete autoCheckedByPosition[cb.value];
        }
      });

      var selectedPosOpt = posSelect.options[posSelect.selectedIndex];
      var rawRoles = selectedPosOpt ? (selectedPosOpt.getAttribute('data-default-roles') || '').trim() : '';
      if (!rawRoles) return;

      var targetRoles = rawRoles.split(',').map(function (r) { return r.trim(); }).filter(Boolean);
      roleCheckboxes.forEach(function (cb) {
        if (!cb.disabled && targetRoles.indexOf(cb.value) !== -1) {
          if (!cb.checked) {
            cb.checked = true;
            autoCheckedByPosition[cb.value] = true;
          }
        }
      });
    }

    function syncUnitOptions() {
      var selectedUnit = (unitSelect.value || '').trim();
      var hasUnit = selectedUnit !== '';

      if (positionCatalog.length > 0) {
        var prevValue = posSelect.value || initialPosValue;
        initialPosValue = '';
        posSelect.innerHTML = '';

        var placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = hasUnit ? 'Select default position...' : 'Select business unit first...';
        posSelect.appendChild(placeholderOpt);

        if (!hasUnit) {
          posSelect.disabled = true;
          posSelect.value = '';
        } else {
          posSelect.disabled = false;
          var matchedPrev = false;
          positionCatalog.forEach(function (item) {
            if (item.unitId !== selectedUnit) return;
            var opt = document.createElement('option');
            opt.value = item.value;
            opt.textContent = item.text;
            opt.setAttribute('data-unit-id', item.unitId);
            if (item.defaultRoles !== '') {
              opt.setAttribute('data-default-roles', item.defaultRoles);
            }
            if (prevValue !== '' && item.value === prevValue) {
              opt.selected = true;
              matchedPrev = true;
            }
            posSelect.appendChild(opt);
          });
          if (!matchedPrev) {
            posSelect.value = '';
          }
        }
      } else {
        var optgroups = posSelect.querySelectorAll('optgroup[data-unit-group]');
        optgroups.forEach(function (og) {
          var groupUnit = og.getAttribute('data-unit-group');
          var match = !hasUnit || groupUnit === selectedUnit;
          og.disabled = !match;
          og.hidden = !match;
        });
        var currentOpt = posSelect.options[posSelect.selectedIndex];
        if (currentOpt && currentOpt.parentElement && currentOpt.parentElement.tagName === 'OPTGROUP') {
          if (currentOpt.parentElement.disabled) {
            posSelect.value = '';
          }
        }
      }

      var selectedUnitOpt = unitSelect.options[unitSelect.selectedIndex];
      var selectedUnitName = selectedUnitOpt ? (selectedUnitOpt.getAttribute('data-unit-name') || '').trim() : '';
      var isVet = selectedUnitName === 'Veterinary Clinic' || (selectedUnitName === '' && selectedUnit === '2');

      roleCheckboxes.forEach(function (cb) {
        var label = cb.closest('label.check');
        var requiredUnit = label ? (label.getAttribute('data-role-unit') || '').trim() : '';
        var isClinical = label && label.getAttribute('data-clinical-role') === '1';
        var unitAllowed = true;
        if (requiredUnit !== '') {
          unitAllowed = selectedUnitName !== '' && selectedUnitName === requiredUnit;
        } else if (isClinical) {
          unitAllowed = isVet;
        }
        var shouldDisable = !hasUnit || !unitAllowed;
        cb.disabled = shouldDisable;
        if (shouldDisable) {
          cb.checked = false;
          delete autoCheckedByPosition[cb.value];
        }
        if (label) {
          label.classList.toggle('is-disabled', shouldDisable);
          if (shouldDisable) {
            label.setAttribute('aria-disabled', 'true');
          } else {
            label.removeAttribute('aria-disabled');
          }
          label.style.opacity = '';
        }
      });

      syncPositionRoles();
    }

    unitSelect.addEventListener('change', syncUnitOptions);
    posSelect.addEventListener('change', syncPositionRoles);
    window.addEventListener('pageshow', syncUnitOptions);
    syncUnitOptions();
  });

  // ---- System Administrator Provisioning Form: Auto-Derived Position & Email Validation ---
  document.querySelectorAll('[data-sysadmin-provision-form]').forEach(function (form) {
    var unitSelect = form.querySelector('[data-sysadmin-unit-select]');
    var posDisplay = form.querySelector('[data-sysadmin-position-display]');
    var emailInput = form.querySelector('#sa_email');
    var emailError = form.querySelector('[data-sa-email-error]');
    var strictEmailPattern = /^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/;

    function syncDerivedPosition() {
      if (!unitSelect || !posDisplay) return;
      var selectedOpt = unitSelect.options[unitSelect.selectedIndex];
      var posText = selectedOpt ? (selectedOpt.getAttribute('data-sysadmin-position') || '').trim() : '';
      var placeholder = posDisplay.getAttribute('data-placeholder') || 'Select a business unit to assign position';
      if (posText !== '') {
        posDisplay.textContent = posText;
        posDisplay.classList.remove('is-placeholder');
      } else {
        posDisplay.textContent = placeholder;
        posDisplay.classList.add('is-placeholder');
      }
    }

    function validateSysAdminEmail(showEmptyError) {
      if (!emailInput || !emailError) return true;
      var val = (emailInput.value || '').trim();
      if (val === '') {
        if (!showEmptyError) {
          emailError.setAttribute('hidden', 'hidden');
          emailError.textContent = '';
          emailInput.setAttribute('aria-invalid', 'false');
          return true;
        }
        emailInput.setAttribute('aria-invalid', 'true');
        showErrorSmooth(emailError, 'Enter a valid email address (for example, name@vetgrpet.local).');
        return false;
      }
      if (val.length > 255 || !strictEmailPattern.test(val)) {
        emailInput.setAttribute('aria-invalid', 'true');
        showErrorSmooth(emailError, 'Enter a valid email address (for example, name@vetgrpet.local).');
        return false;
      }
      emailError.setAttribute('hidden', 'hidden');
      emailError.textContent = '';
      emailInput.setAttribute('aria-invalid', 'false');
      return true;
    }

    if (unitSelect && posDisplay) {
      unitSelect.addEventListener('change', syncDerivedPosition);
      window.addEventListener('pageshow', syncDerivedPosition);
      syncDerivedPosition();
    }

    if (emailInput && emailError) {
      var emailTouched = !emailError.hasAttribute('hidden') && (emailError.textContent || '').trim() !== '';
      emailInput.addEventListener('blur', function () {
        emailTouched = true;
        validateSysAdminEmail(false);
      });
      emailInput.addEventListener('input', function () {
        if (emailTouched || emailInput.getAttribute('aria-invalid') === 'true') {
          validateSysAdminEmail(false);
        }
      });
      form.addEventListener('submit', function (ev) {
        emailTouched = true;
        if (!validateSysAdminEmail(true)) {
          ev.preventDefault();
          emailInput.focus();
        }
      });
    }
  });

  // ---- Confirmation Guard for Sensitive Admin Actions ------------------
  document.querySelectorAll('[data-confirm-action]').forEach(function (el) {
    var msg = el.getAttribute('data-confirm-action');
    if (el.tagName === 'FORM') {
      el.addEventListener('submit', function (ev) {
        if (msg && !window.confirm(msg)) ev.preventDefault();
      });
      return;
    }
    el.addEventListener('click', function (ev) {
      if (msg && !window.confirm(msg)) ev.preventDefault();
    });
  });

  // ---- Native <dialog> modals (roster Show All, role picker, employee modal) ---
  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var dlgId = btn.getAttribute('data-modal-open');
      var dlg = document.getElementById(dlgId);
      if (dlg && typeof dlg.showModal === 'function') {
        dlg._triggerBtn = btn;
        document.body.classList.add('has-modal-open');
        dlg.showModal();
        validateEmployeeModal();
        var closeBtn = dlg.querySelector('[data-modal-close]');
        if (closeBtn && typeof closeBtn.focus === 'function') {
          setTimeout(function () { closeBtn.focus(); }, 40);
        }
      }
    });
  });

  // Auto-open modal on page load if marked (e.g. form validation error)
  document.querySelectorAll('dialog.modal[data-open-on-load="true"]').forEach(function (dlg) {
    if (typeof dlg.showModal === 'function' && !dlg.open) {
      document.body.classList.add('has-modal-open');
      dlg.showModal();
    }
  });

  // ---- Pet Store Employee Creation Modal Validation (Send Invite Link) --
  function validateEmployeeModal() {
    var empForm = document.getElementById('add-employee-form');
    if (!empForm) return;
    var submitBtn = document.getElementById('create-emp-submit-btn') || empForm.querySelector('button[type="submit"]');
    if (!submitBtn) return;

    var nameInput = empForm.querySelector('input[name="full_name"]');
    var emailInput = empForm.querySelector('input[name="email"]');
    var posSelect = empForm.querySelector('select[name="position"]');
    var chips = empForm.querySelector('[data-role-chips]');
    var usernameInput = empForm.querySelector('input[name="username"]');
    var phoneInput = empForm.querySelector('input[name="phone"]');

    // 1. Employee full name: required, non-empty trimmed string
    var nameVal = nameInput ? nameInput.value.trim() : '';
    var nameValid = nameVal.length > 0;

    // 2. Email address: required, valid email format
    var emailVal = emailInput ? emailInput.value.trim() : '';
    var emailPattern = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;
    var emailValid = emailVal.length > 0 && emailPattern.test(emailVal);

    // 3. Default position: required, non-empty selection
    var posVal = posSelect ? posSelect.value.trim() : '';
    var posValid = posVal.length > 0;

    // 4. System roles: at least one system role chip selected
    var roleChips = chips ? chips.querySelectorAll('[data-role-chip]') : [];
    var rolesValid = roleChips.length > 0;

    // 5. Optional username: if entered, 3-60 chars (letters, numbers, _, .)
    var userVal = usernameInput ? usernameInput.value.trim() : '';
    var usernameValid = userVal === '' || /^[a-zA-Z0-9_.]{3,60}$/.test(userVal);

    // 6. Optional phone: if entered, 6-30 chars
    var phoneVal = phoneInput ? phoneInput.value.trim() : '';
    var phoneValid = phoneVal === '' || /^[0-9+()\-\s]{6,30}$/.test(phoneVal);

    var allValid = nameValid && emailValid && posValid && rolesValid && usernameValid && phoneValid;

    if (allValid) {
      submitBtn.removeAttribute('disabled');
      submitBtn.disabled = false;
    } else {
      submitBtn.setAttribute('disabled', 'disabled');
      submitBtn.disabled = true;
    }
  }

  // Prevent double submissions on employee creation form
  var empForm = document.getElementById('add-employee-form');
  if (empForm) {
    empForm.addEventListener('input', validateEmployeeModal);
    empForm.addEventListener('change', validateEmployeeModal);
    empForm.addEventListener('submit', function (ev) {
      if (empForm._submitting) {
        ev.preventDefault();
        return;
      }
      var submitBtn = document.getElementById('create-emp-submit-btn') || empForm.querySelector('button[type="submit"]');
      if (submitBtn) {
        if (submitBtn.disabled) {
          ev.preventDefault();
          return;
        }
        empForm._submitting = true;
        submitBtn.disabled = true;
        submitBtn.setAttribute('data-original-text', submitBtn.textContent);
        submitBtn.textContent = 'Sending Invite Link...';
      }
    });
  }

  document.querySelectorAll('dialog.modal').forEach(function (dlg) {
    dlg.querySelectorAll('[data-modal-close]').forEach(function (b) {
      b.addEventListener('click', function () {
        dlg.close();
      });
    });

    var mouseDownOnBackdrop = false;
    dlg.addEventListener('mousedown', function (ev) {
      mouseDownOnBackdrop = (ev.target === dlg);
    });
    dlg.addEventListener('click', function (ev) {
      if (ev.target === dlg && mouseDownOnBackdrop) {
        dlg.close();
      }
      mouseDownOnBackdrop = false;
    });

    dlg.addEventListener('close', function () {
      if (!document.querySelector('dialog.modal[open]')) {
        document.body.classList.remove('has-modal-open');
      }
      if (dlg._triggerBtn && typeof dlg._triggerBtn.focus === 'function') {
        dlg._triggerBtn.focus();
        dlg._triggerBtn = null;
      }
    });
  });

  // ---- Add Role picker (employee creation) -----------------------------
  document.querySelectorAll('[data-role-picker]').forEach(function (root) {
    var addBtn = root.querySelector('[data-role-add]');
    var dlg = root.querySelector('#role-modal');
    var chips = root.querySelector('[data-role-chips]');
    var emptyHint = root.querySelector('[data-role-empty]');
    var posSelect = root.querySelector('select[name="position"]');
    if (!chips) return;

    var posRoles = {};
    if (posSelect && posSelect.getAttribute('data-position-roles')) {
      try {
        posRoles = JSON.parse(posSelect.getAttribute('data-position-roles') || '{}');
      } catch (e) {
        posRoles = {};
      }
    }

    function selected() {
      return Array.prototype.map.call(chips.querySelectorAll('[data-role-chip]'), function (c) {
        return c.getAttribute('data-role-chip');
      });
    }
    function sync() {
      var sel = selected();
      if (dlg) {
        dlg.querySelectorAll('[data-role-option]').forEach(function (o) {
          o.disabled = sel.indexOf(o.getAttribute('data-role-option')) !== -1;
        });
      }
      if (emptyHint) emptyHint.hidden = sel.length > 0;
      validateEmployeeModal();
    }
    function addChip(name, isAuto) {
      if (selected().indexOf(name) !== -1) {
        var existing = chips.querySelector('[data-role-chip="' + name + '"]');
        if (existing && isAuto) {
          existing.setAttribute('data-role-auto', 'true');
        }
        return;
      }
      var chip = document.createElement('span');
      chip.className = 'role-item' + (isAuto ? ' is-auto-role' : '');
      chip.setAttribute('data-role-chip', name);
      if (isAuto) {
        chip.setAttribute('data-role-auto', 'true');
      }
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'roles[]';
      input.value = name;
      var label = document.createElement('span');
      label.textContent = name;
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'role-item-remove';
      rm.setAttribute('data-role-remove', '');
      rm.setAttribute('aria-label', 'Remove role ' + name);
      rm.innerHTML = '&times;';
      chip.appendChild(input);
      chip.appendChild(label);
      chip.appendChild(rm);
      chips.appendChild(chip);
    }

    function updateAutoRole(isInitial) {
      if (!posSelect) return;
      var currentPos = posSelect.value;
      var rawTarget = posRoles[currentPos] || null;
      var targetRoles = Array.isArray(rawTarget) ? rawTarget : (rawTarget ? [rawTarget] : []);

      // If position changed, remove previous auto-assigned chips that are not in targetRoles
      var prevAutoChips = chips.querySelectorAll('[data-role-auto="true"]');
      prevAutoChips.forEach(function (prevChip) {
        var prevRole = prevChip.getAttribute('data-role-chip');
        if (targetRoles.indexOf(prevRole) === -1) {
          prevChip.remove();
        }
      });

      // Add new auto-assigned role chips if defined
      targetRoles.forEach(function (roleName) {
        var existing = chips.querySelector('[data-role-chip="' + roleName + '"]');
        if (!existing) {
          addChip(roleName, true);
        } else {
          existing.setAttribute('data-role-auto', 'true');
        }
      });
      sync();
    }

    if (posSelect) {
      posSelect.addEventListener('change', function () {
        updateAutoRole(false);
      });
      // Initial sync on load if position is selected but auto role not yet assigned
      if (posSelect.value && !chips.querySelector('[data-role-auto="true"]')) {
        updateAutoRole(true);
      }
    }

    if (addBtn && dlg) {
      addBtn.addEventListener('click', function () {
        sync();
        if (typeof dlg.showModal === 'function') {
          dlg._triggerBtn = addBtn;
          document.body.classList.add('has-modal-open');
          dlg.showModal();
          var closeBtn = dlg.querySelector('[data-modal-close]');
          if (closeBtn && typeof closeBtn.focus === 'function') {
            setTimeout(function () { closeBtn.focus(); }, 40);
          }
        }
      });
      dlg.querySelectorAll('[data-role-option]').forEach(function (o) {
        o.addEventListener('click', function () {
          addChip(o.getAttribute('data-role-option'), false);
          sync();
          dlg.close();
        });
      });
    }

    chips.addEventListener('click', function (ev) {
      var rm = ev.target.closest('[data-role-remove]');
      if (!rm) return;
      var chip = rm.closest('[data-role-chip]');
      if (chip) chip.remove();
      sync();
    });
    sync();
    validateEmployeeModal();
  });

  // ---- Collapsible role cards (Pet Store Roles & Permissions) ----------
  function toggleRoleCard(card, btn) {
    if (!card) return;
    if (!btn) btn = card.querySelector('[data-role-toggle]');

    var isExpanding = !card.classList.contains('is-expanded');
    var chevron = card.querySelector('.role-card-chevron');

    // 1. Set ARIA state immediately
    if (btn) {
      btn.setAttribute('aria-expanded', isExpanding ? 'true' : 'false');
      var label = btn.getAttribute('aria-label') || '';
      btn.setAttribute('aria-label', isExpanding ? label.replace(/Show/i, 'Hide') : label.replace(/Hide/i, 'Show'));
    }

    // 2. Rotate chevron explicitly
    if (chevron) {
      chevron.style.transform = isExpanding ? 'rotate(180deg)' : 'rotate(0deg)';
    }

    // 3. Toggle expanded class on card
    card.classList.toggle('is-expanded', isExpanding);
  }

  // Delegated click listener so clicks on the toggle button, the chevron SVG, or the card toggle reliably
  document.addEventListener('click', function (ev) {
    var toggle = ev.target.closest('[data-role-toggle]');
    if (toggle) {
      var card = toggle.closest('[data-role-card]');
      if (card) {
        ev.preventDefault();
        toggleRoleCard(card, toggle);
      }
      return;
    }

    // Clicking anywhere on the role card outside interactive form elements also toggles
    var card = ev.target.closest('[data-role-card]');
    if (card && !ev.target.closest('a, button, input, select, textarea, label, form, .status, .meta-label, code')) {
      var btn = card.querySelector('[data-role-toggle]');
      if (btn) {
        toggleRoleCard(card, btn);
      }
    }
  });

  // ---- Active Navigation Indicators (Section 8: Topbar & Admin Subnav) ---
  function initActiveNavIndicators() {
    var navConfigs = [
      {
        container: document.querySelector('.topbar nav'),
        storageKey: 'vetgrpet_topbar_active_rect',
        isCurrent: function (link, pathname) {
          if (link.classList.contains('is-current')) return true;
          var linkPath = link.getAttribute('href');
          if (!linkPath || linkPath.startsWith('#') || linkPath.startsWith('javascript:')) return false;
          linkPath = linkPath.split('?')[0].replace(/\/+$/, '') || '/';
          var cleanCurrent = pathname.split('?')[0].replace(/\/+$/, '') || '/';
          if (linkPath === '/workspace' && (cleanCurrent.startsWith('/sysadmin') || cleanCurrent.startsWith('/manager') || cleanCurrent.startsWith('/super-admin') || cleanCurrent.startsWith('/workspace'))) {
            return true;
          }
          if (linkPath === cleanCurrent) return true;
          if (linkPath === '/' || linkPath === '/super-admin' || linkPath === '/sysadmin' || linkPath === '/workspace' || linkPath === '/manager') {
            return false;
          }
          if (cleanCurrent.startsWith(linkPath + '/')) return true;
          return false;
        },
        activeClass: 'is-current'
      }
    ];

    var isReload = false;
    try {
      var navEntry = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
      if (navEntry && navEntry.type === 'reload') {
        isReload = true;
      } else if (window.performance && window.performance.navigation && window.performance.navigation.type === 1) {
        isReload = true;
      }
    } catch (e) { }

    navConfigs.forEach(function (cfg) {
      var container = cfg.container;
      if (!container) return;

      container.classList.add('has-nav-indicator');

      var indicator = container.querySelector('.nav-active-indicator');
      if (!indicator) {
        indicator = document.createElement('span');
        indicator.className = 'nav-active-indicator';
        indicator.setAttribute('aria-hidden', 'true');
        container.appendChild(indicator);
      }

      function updateActiveItem(clickedLink) {
        var links = Array.from(container.querySelectorAll('a'));
        var currentPath = window.location.pathname;
        var activeLink = clickedLink || null;

        if (!activeLink) {
          // 1. Check if server-rendered active class is present
          for (var i = 0; i < links.length; i++) {
            if (links[i].classList.contains(cfg.activeClass)) {
              activeLink = links[i];
              break;
            }
          }
        }

        if (!activeLink) {
          // 2. Exact match on URL path
          var cleanCurrent = currentPath.split('?')[0].replace(/\/+$/, '') || '/';
          for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript:')) continue;
            var cleanHref = href.split('?')[0].replace(/\/+$/, '') || '/';
            if (cleanHref === cleanCurrent) {
              activeLink = links[i];
              break;
            }
          }
        }

        if (!activeLink) {
          // 3. Longest prefix match (excluding root dashboards unless exact)
          var bestMatch = null;
          var bestLen = 0;
          var cleanCurrent = currentPath.split('?')[0].replace(/\/+$/, '') || '/';
          for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript:')) continue;
            var cleanHref = href.split('?')[0].replace(/\/+$/, '') || '/';
            if (cleanHref === '/' || cleanHref === '/super-admin' || cleanHref === '/sysadmin' || cleanHref === '/workspace') {
              continue;
            }
            if ((cleanCurrent === cleanHref || cleanCurrent.startsWith(cleanHref + '/')) && cleanHref.length > bestLen) {
              bestMatch = links[i];
              bestLen = cleanHref.length;
            }
          }
          if (bestMatch) {
            activeLink = bestMatch;
          }
        }

        if (!activeLink) {
          for (var i = 0; i < links.length; i++) {
            if (cfg.isCurrent(links[i], currentPath)) {
              activeLink = links[i];
              break;
            }
          }
        }

        if (!activeLink && links.length > 0 && links[0].classList.contains(cfg.activeClass)) {
          activeLink = links[0];
        }

        links.forEach(function (lnk) {
          if (lnk === activeLink) {
            lnk.classList.add(cfg.activeClass);
          } else {
            lnk.classList.remove(cfg.activeClass);
          }
        });

        if (!activeLink) {
          indicator.style.opacity = '0';
          return;
        }

        var navRect = container.getBoundingClientRect();
        var linkRect = activeLink.getBoundingClientRect();
        var targetLeft = linkRect.left - navRect.left + container.scrollLeft + 6;
        var targetWidth = Math.max(14, linkRect.width - 12);

        var prevStored = null;
        try {
          var raw = sessionStorage.getItem(cfg.storageKey);
          if (raw) prevStored = JSON.parse(raw);
        } catch (e) { }

        var hadTransition = prevStored && (Date.now() - prevStored.time < 4000) && prevStored.path !== currentPath;

        if (isReload) {
          indicator.style.transition = 'none';
          indicator.style.left = targetLeft + 'px';
          indicator.style.width = targetWidth + 'px';
          indicator.style.opacity = '1';
        } else if (hadTransition && !reducedMotion) {
          indicator.style.transition = 'none';
          indicator.style.left = prevStored.left + 'px';
          indicator.style.width = prevStored.width + 'px';
          indicator.style.opacity = '1';

          requestAnimationFrame(function () {
            requestAnimationFrame(function () {
              indicator.style.transition = 'left 240ms cubic-bezier(0.23, 1, 0.32, 1), width 240ms cubic-bezier(0.23, 1, 0.32, 1), opacity 180ms ease';
              indicator.style.left = targetLeft + 'px';
              indicator.style.width = targetWidth + 'px';
            });
          });
          try { sessionStorage.removeItem(cfg.storageKey); } catch (e) { }
        } else if (!reducedMotion && !prevStored) {
          var center = targetLeft + (targetWidth / 2);
          indicator.style.transition = 'none';
          indicator.style.left = center + 'px';
          indicator.style.width = '0px';
          indicator.style.opacity = '0';

          requestAnimationFrame(function () {
            requestAnimationFrame(function () {
              indicator.style.transition = 'left 220ms cubic-bezier(0.23, 1, 0.32, 1), width 220ms cubic-bezier(0.23, 1, 0.32, 1), opacity 180ms ease';
              indicator.style.left = targetLeft + 'px';
              indicator.style.width = targetWidth + 'px';
              indicator.style.opacity = '1';
            });
          });
        } else {
          indicator.style.transition = 'left 220ms cubic-bezier(0.23, 1, 0.32, 1), width 220ms cubic-bezier(0.23, 1, 0.32, 1), opacity 180ms ease';
          indicator.style.left = targetLeft + 'px';
          indicator.style.width = targetWidth + 'px';
          indicator.style.opacity = '1';
        }

        try {
          sessionStorage.setItem(cfg.storageKey, JSON.stringify({
            left: targetLeft,
            width: targetWidth,
            path: currentPath,
            time: Date.now()
          }));
        } catch (e) { }
      }

      container.addEventListener('click', function (ev) {
        var link = ev.target.closest('a');
        if (!link || !container.contains(link)) return;
        var href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

        var navRect = container.getBoundingClientRect();
        var linkRect = link.getBoundingClientRect();
        var targetLeft = linkRect.left - navRect.left + container.scrollLeft + 6;
        var targetWidth = Math.max(14, linkRect.width - 12);

        try {
          sessionStorage.setItem(cfg.storageKey, JSON.stringify({
            left: targetLeft,
            width: targetWidth,
            path: window.location.pathname,
            time: Date.now()
          }));
        } catch (e) { }

        indicator.style.transition = 'left 220ms cubic-bezier(0.23, 1, 0.32, 1), width 220ms cubic-bezier(0.23, 1, 0.32, 1), opacity 180ms ease';
        indicator.style.left = targetLeft + 'px';
        indicator.style.width = targetWidth + 'px';
        indicator.style.opacity = '1';

        var links = Array.from(container.querySelectorAll('a'));
        links.forEach(function (l) {
          if (l === link) l.classList.add(cfg.activeClass);
          else l.classList.remove(cfg.activeClass);
        });
      });

      updateActiveItem();

      window.addEventListener('popstate', function () {
        updateActiveItem();
      });
      window.addEventListener('pageshow', function () {
        updateActiveItem();
      });

      var resizeTimer;
      window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
          updateActiveItem();
        }, 60);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initActiveNavIndicators);
  } else {
    initActiveNavIndicators();
  }

  // ---- Compact Mobile Navigation Drawer Toggle (<= 768px) ---------------
  var mobileNavToggle = document.getElementById('mobile-nav-toggle');
  var topbarEl = document.querySelector('[data-topbar]') || document.querySelector('.topbar');
  var mainNavEl = document.getElementById('main-nav');
  if (mobileNavToggle && topbarEl && mainNavEl) {
    function setMobileNavOpen(open) {
      topbarEl.classList.toggle('is-nav-open', open);
      mobileNavToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      mobileNavToggle.setAttribute('aria-label', open ? 'Hide additional pages' : 'Show more pages');
    }

    mobileNavToggle.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var isOpen = topbarEl.classList.contains('is-nav-open');
      setMobileNavOpen(!isOpen);
    });

    mainNavEl.addEventListener('click', function (ev) {
      if (ev.target.closest('a[href]')) {
        setMobileNavOpen(false);
      }
    });

    document.addEventListener('click', function (ev) {
      if (topbarEl.classList.contains('is-nav-open') && !topbarEl.contains(ev.target)) {
        setMobileNavOpen(false);
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && topbarEl.classList.contains('is-nav-open')) {
        setMobileNavOpen(false);
        mobileNavToggle.focus();
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 768 && topbarEl.classList.contains('is-nav-open')) {
        setMobileNavOpen(false);
      }
    });
  }

  // ---- Full-Height Living Woodland & Animated Pet World Engine ----------
  var sceneFigures = document.querySelectorAll('[data-scene-target]');
  var mobileAnimalFigures = document.querySelectorAll('[data-mobile-animal]');
  var sceneSections = document.querySelectorAll('[data-scene]');

  // Helper: Build exact SVG transform around an explicit (cx, cy) pivot point in SVG user-space
  function svgTransform(cx, cy, tx, ty, deg, sx, sy) {
    tx = tx || 0;
    ty = ty || 0;
    deg = deg || 0;
    sx = sx === undefined ? 1 : sx;
    sy = sy === undefined ? sx : sy;
    return (
      'translate(' + (cx + tx).toFixed(2) + ' ' + (cy + ty).toFixed(2) + ') ' +
      'rotate(' + deg.toFixed(2) + ') ' +
      'scale(' + sx.toFixed(3) + ' ' + sy.toFixed(3) + ') ' +
      'translate(' + (-cx).toFixed(2) + ' ' + (-cy).toFixed(2) + ')'
    );
  }

  function parsePivot(el, fallbackX, fallbackY) {
    if (!el) return [fallbackX, fallbackY];
    var raw = el.getAttribute('data-pivot');
    if (!raw) return [fallbackX, fallbackY];
    var parts = raw.trim().split(/\s+/);
    return [parseFloat(parts[0]) || fallbackX, parseFloat(parts[1]) || fallbackY];
  }

  function setSvgPartTransform(el, tx, ty, deg, sx, sy) {
    if (!el) return;
    if (!el._pivot) {
      el._pivot = parsePivot(el, 140, 280);
    }
    el.setAttribute('transform', svgTransform(el._pivot[0], el._pivot[1], tx, ty, deg, sx, sy));
  }

  function setDialogueState(el, alpha, tx, ty, scale) {
    if (!el) return;
    var clamped = Math.max(0, Math.min(1, alpha));
    el.setAttribute('opacity', clamped.toFixed(3));
    el.style.opacity = clamped.toFixed(3);
    setSvgPartTransform(el, tx || 0, ty || 0, 0, scale || 1, scale || 1);
  }

  function animateScenicEntry() {
    sceneFigures.forEach(function (fig) {
      if (!fig.classList.contains('is-active') || typeof fig.animate !== 'function') return;
      var isLeft = !!fig.closest('[data-scenic-rail="left"]');
      var xOffset = isLeft ? '-18px' : '18px';
      var canopy = fig.querySelector('.scenic-canopy');
      var character = fig.querySelector('.scenic-character');
      var undergrowth = fig.querySelector('.scenic-undergrowth');

      if (canopy && typeof canopy.animate === 'function') {
        canopy.animate(
          [
            { opacity: 0.15, transform: 'translate3d(' + xOffset + ', -18px, 0) scale(0.94)' },
            { opacity: 1, transform: 'translate3d(0, 0, 0) scale(1)' }
          ],
          { duration: 280, easing: 'cubic-bezier(0.23, 1, 0.32, 1)', fill: 'both' }
        );
      }
      if (character && typeof character.animate === 'function') {
        character.animate(
          [
            { opacity: 0.15, transform: 'translate3d(' + xOffset + ', 16px, 0) scale(0.92)' },
            { opacity: 1, transform: 'translate3d(0, 0, 0) scale(1)' }
          ],
          { duration: 300, delay: 25, easing: 'cubic-bezier(0.23, 1, 0.32, 1)', fill: 'both' }
        );
      }
      if (undergrowth && typeof undergrowth.animate === 'function') {
        undergrowth.animate(
          [
            { opacity: 0.15, transform: 'translate3d(' + xOffset + ', 20px, 0) scale(0.94)' },
            { opacity: 1, transform: 'translate3d(0, 0, 0) scale(1)' }
          ],
          { duration: 300, delay: 50, easing: 'cubic-bezier(0.23, 1, 0.32, 1)', fill: 'both' }
        );
      }
    });
  }

  if (sceneFigures.length > 0 || mobileAnimalFigures.length > 0) {
    document.body.classList.add('js-pet-world-ready');

    // Animate the 2 active animals and their woodland props on initial page arrival
    animateScenicEntry();

    window.addEventListener('pageshow', function (ev) {
      document.body.classList.remove('is-page-switching');
      if (ev.persisted) {
        animateScenicEntry();
      }
    });

    // Smoothly animate animals, foliage, and main content when navigating between pages
    document.querySelectorAll('a[href^="/"]').forEach(function (link) {
      link.addEventListener('click', function (ev) {
        if (
          ev.defaultPrevented ||
          ev.button !== 0 ||
          ev.metaKey ||
          ev.ctrlKey ||
          ev.shiftKey ||
          ev.altKey ||
          link.target === '_blank' ||
          link.hasAttribute('download')
        ) {
          return;
        }
        var href = link.getAttribute('href') || '';
        if (href.indexOf('#') !== -1 && href.split('#')[0] === window.location.pathname) {
          return;
        }
        document.body.classList.add('is-page-switching');
      });
    });

    // ---- Build Per-Animal Articulated Rigs & Independent State Machines ----
    var rigs = {};
    var mobileRigs = {};
    var mobileCompanionMq = window.matchMedia ? window.matchMedia('(max-width: 480px)') : null;

    function buildAnimalRig(fig, index, isMobile) {
      var parts = {};
      fig.querySelectorAll('[data-part]').forEach(function (p) {
        parts[p.getAttribute('data-part')] = p;
      });
      var nowInit = performance.now();
      return {
        el: fig,
        parts: parts,
        isMobile: !!isMobile,
        phase: index * 1.37,
        // Independent blink state machine for each animal
        blinkStart: 0,
        blinkDuration: 165,
        doubleBlink: false,
        nextBlinkAt: nowInit + 900 + index * 520 + Math.random() * 1800,
        // Independent behavior action state machine (panting, barking, meowing/purring, ear perks, chirping, gnawing)
        actionType: 'idle',
        actionStart: 0,
        actionDuration: 0,
        nextActionAt: isMobile ? (nowInit + 650) : (nowInit + 700 + index * 480 + Math.random() * 1400),
        cycleCount: 0
      };
    }

    sceneFigures.forEach(function (fig, index) {
      var name = fig.getAttribute('data-animal') || ('animal-' + index);
      rigs[name] = buildAnimalRig(fig, index, false);
    });

    mobileAnimalFigures.forEach(function (fig, index) {
      var name = fig.getAttribute('data-mobile-animal') || ('mobile-animal-' + index);
      var rig = buildAnimalRig(fig, index + 10, true);
      if (name === 'clover') {
        rig.actionType = 'gnawing';
        rig.actionStart = performance.now();
        rig.actionDuration = 2200;
      }
      mobileRigs[name] = rig;
    });

    // Helper: Update natural blinking (scales eyes & shows fur-colored eyelids during blink)
    function updateAnimalBlink(rig, now) {
      var eyes = rig.parts['eyes'];
      var eyelids = rig.parts['eyelids'];
      if (!eyes) return;

      if (now >= rig.nextBlinkAt && rig.blinkStart === 0) {
        rig.blinkStart = now;
        rig.doubleBlink = Math.random() < 0.28;
        rig.blinkDuration = rig.doubleBlink ? 340 : 165;
      }

      var blinkClosed = 0;
      if (rig.blinkStart > 0) {
        var elapsed = now - rig.blinkStart;
        if (elapsed >= rig.blinkDuration) {
          rig.blinkStart = 0;
          rig.nextBlinkAt = now + 2200 + Math.random() * 3200;
        } else if (!rig.doubleBlink) {
          var p = elapsed / rig.blinkDuration;
          blinkClosed = Math.sin(p * Math.PI);
        } else {
          // Two quick blinks in a row
          var p2 = (elapsed / rig.blinkDuration) * 2;
          blinkClosed = Math.sin((p2 % 1) * Math.PI);
        }
      }

      // Purring cat half-closes eyelids contentedly
      if (rig.extraEyelidClosure && rig.extraEyelidClosure > blinkClosed) {
        blinkClosed = rig.extraEyelidClosure;
      }

      var sy = Math.max(0.08, 1 - blinkClosed * 0.92);
      setSvgPartTransform(eyes, 0, 0, 0, 1, sy);
      if (eyelids) {
        var lidAlpha = blinkClosed > 0.35 ? Math.min(1, (blinkClosed - 0.35) / 0.45) : 0;
        eyelids.setAttribute('opacity', lidAlpha.toFixed(3));
      }
    }

    // Helper: Animate surrounding branches, leaves, vines, ferns, bubbles & hearts
    function updateWoodlandFoliage(rig, t) {
      var ph = rig.phase;
      var branch = rig.parts['branch'];
      var leaves = rig.parts['leaves'];
      var vine = rig.parts['vine'];
      var fern = rig.parts['fern'];
      var bubbles = rig.parts['bubbles'];
      var heart = rig.parts['heart'];

      if (branch) {
        setSvgPartTransform(branch, 0, Math.sin(t * 1.1 + ph) * 2.2, Math.sin(t * 0.9 + ph) * 1.8, 1, 1);
      }
      if (leaves) {
        setSvgPartTransform(leaves, 0, 0, Math.sin(t * 2.1 + ph) * 4.5, 1 + Math.sin(t * 1.7 + ph) * 0.025, 1);
      }
      if (vine) {
        setSvgPartTransform(vine, 0, 0, Math.sin(t * 1.5 + ph) * 3.8, 1, 1);
      }
      if (fern) {
        setSvgPartTransform(fern, 0, Math.cos(t * 1.3 + ph) * -1.8, Math.sin(t * 1.4 + ph) * 3.4, 1, 1);
      }
      if (bubbles) {
        setSvgPartTransform(bubbles, Math.sin(t * 1.6 + ph) * 3.5, Math.sin(t * 1.9 + ph) * -8.5, 0, 1 + Math.sin(t * 2.2) * 0.05);
      }
      if (heart) {
        var hs = 1 + Math.sin(t * 2.8 + ph) * 0.12;
        setSvgPartTransform(heart, Math.sin(t * 1.5) * 2, Math.sin(t * 2.2) * -6.5, Math.sin(t * 1.8) * 5, hs, hs);
      }
    }

    // Main 60fps Living Pet World Animation Loop
    function tickPetWorld(now) {
      var t = now * 0.001;

      // 1. DOG 1: SUNNY (Golden Retriever — Continuous Breathing + Alternating Open-Mouth Panting & Barking with Temporary "Woof! ♪")
      var sunny = rigs['sunny'];
      if (sunny) {
        updateWoodlandFoliage(sunny, t);

        if (sunny.actionType === 'idle' && now >= sunny.nextActionAt) {
          sunny.cycleCount += 1;
          sunny.actionType = sunny.cycleCount % 2 === 1 ? 'panting' : 'barking';
          sunny.actionStart = now;
          sunny.actionDuration = sunny.actionType === 'panting' ? (2400 + Math.random() * 1100) : 1450;
        } else if (sunny.actionType !== 'idle' && now - sunny.actionStart >= sunny.actionDuration) {
          sunny.actionType = 'idle';
          sunny.nextActionAt = now + 2400 + Math.random() * 2400;
        }

        var isPanting = sunny.actionType === 'panting';
        var isSunnyBarking = sunny.actionType === 'barking';
        var pantEnv = 0;
        var sunnyBarkOpen = 0;
        var sunnyBarkBubbleAlpha = 0;
        var sunnyBarkBubbleY = 8;
        var sunnyBarkBubbleScale = 0.9;

        if (isPanting) {
          var pElapsed = now - sunny.actionStart;
          var fadeIn = Math.min(1, pElapsed / 260);
          var fadeOut = Math.min(1, (sunny.actionDuration - pElapsed) / 280);
          pantEnv = Math.max(0, Math.min(fadeIn, fadeOut));
        } else if (isSunnyBarking) {
          var sbElapsed = now - sunny.actionStart;
          var sbPulse1 = sbElapsed >= 60 && sbElapsed <= 440 ? Math.sin(((sbElapsed - 60) / 380) * Math.PI) : 0;
          var sbPulse2 = sbElapsed >= 540 && sbElapsed <= 960 ? Math.sin(((sbElapsed - 540) / 420) * Math.PI) : 0;
          sunnyBarkOpen = Math.max(sbPulse1, sbPulse2);

          var sbIn = Math.min(1, sbElapsed / 180);
          var sbOut = Math.min(1, (sunny.actionDuration - sbElapsed) / 240);
          sunnyBarkBubbleAlpha = Math.max(0, Math.min(sbIn, sbOut));
          sunnyBarkBubbleY = (1 - sunnyBarkBubbleAlpha) * 8 - sunnyBarkOpen * 3.5;
          sunnyBarkBubbleScale = 0.92 + sunnyBarkBubbleAlpha * 0.08 + sunnyBarkOpen * 0.05;
        }

        // Chest & ribcage visibly expands and contracts (faster while panting)
        var breatheWave = Math.sin(t * (isPanting ? 4.2 : 2.35));
        var chestSx = 1 + breatheWave * 0.048 + pantEnv * 0.015 + sunnyBarkOpen * 0.025;
        var chestSy = 1 + breatheWave * 0.056 + pantEnv * 0.018 + sunnyBarkOpen * 0.03;
        setSvgPartTransform(sunny.parts['chest'], 0, -breatheWave * 2.8, 0, chestSx, chestSy);

        // Head subtly bobs with breath or tilts up when barking; ears perk
        var headBobY = -breatheWave * 2.4 + Math.sin(t * 1.5) * 1.2 - sunnyBarkOpen * 6.5;
        var headRot = Math.sin(t * 1.2) * 1.6 - sunnyBarkOpen * 6.0;
        setSvgPartTransform(sunny.parts['head'], 0, headBobY, headRot, 1, 1);
        setSvgPartTransform(sunny.parts['ear-l'], 0, 0, Math.sin(t * 1.8) * 4.5 - pantEnv * 3 - sunnyBarkOpen * 9, 1, 1);
        setSvgPartTransform(sunny.parts['ear-r'], 0, 0, -Math.sin(t * 1.8 + 0.4) * 4.5 + pantEnv * 3 + sunnyBarkOpen * 9, 1, 1);

        // Happy plume tail wag
        setSvgPartTransform(sunny.parts['tail'], 0, 0, Math.sin(t * (isSunnyBarking ? 7.5 : 3.8)) * 12, 1, 1);

        // Panting jaw & moving tongue OR barking jaw opening/closing
        var pantBob = (Math.sin(t * 16.5) + 1) * 0.5;
        var jawOpen = Math.max(0.22 + pantEnv * (0.68 + pantBob * 0.35), sunnyBarkOpen * 1.25);
        var jawY = pantEnv * (1.8 + pantBob * 2.2) + sunnyBarkOpen * 2.5;
        setSvgPartTransform(sunny.parts['jaw'], 0, jawY, 0, 1, jawOpen);
        setSvgPartTransform(
          sunny.parts['tongue'],
          Math.sin(t * 8.2) * 0.8 * pantEnv,
          pantEnv * pantBob * 3.2,
          0,
          1,
          0.5 + pantEnv * (0.5 + pantBob * 0.35) + sunnyBarkOpen * 0.35
        );

        setDialogueState(sunny.parts['dialogue-bark'], sunnyBarkBubbleAlpha, 0, sunnyBarkBubbleY, sunnyBarkBubbleScale);
        updateAnimalBlink(sunny, now);
      }

      // 2. DOG 2: BELLA (Groomed Poodle — Subtle Idle + Barking Jaw & Head + Temporary "Woof! ♪" Bubble)
      var bella = rigs['bella'];
      if (bella) {
        updateWoodlandFoliage(bella, t);

        if (bella.actionType === 'idle' && now >= bella.nextActionAt) {
          bella.actionType = 'barking';
          bella.actionStart = now;
          bella.actionDuration = 1450;
        } else if (bella.actionType === 'barking' && now - bella.actionStart >= bella.actionDuration) {
          bella.actionType = 'idle';
          bella.nextActionAt = now + 3400 + Math.random() * 2800;
        }

        // Continuous idle chest breathing & pom-pom tail wagging
        var bellaBreathe = Math.sin(t * 2.5 + 1.1);
        setSvgPartTransform(bella.parts['chest'], 0, -bellaBreathe * 2.2, 0, 1 + bellaBreathe * 0.038, 1 + bellaBreathe * 0.044);
        setSvgPartTransform(bella.parts['tail'], 0, 0, Math.sin(t * 4.4) * 13, 1, 1);

        var barkOpen = 0;
        var barkHeadY = -bellaBreathe * 1.8;
        var barkHeadRot = Math.sin(t * 1.4) * 1.4;
        var earBounce = Math.sin(t * 2.0) * 3;
        var barkBubbleAlpha = 0;
        var barkBubbleY = 8;
        var barkBubbleScale = 0.9;

        if (bella.actionType === 'barking') {
          var bElapsed = now - bella.actionStart;
          // Two energetic barks ("Woof! Woof!") during the 1450ms episode
          var pulse1 = bElapsed >= 60 && bElapsed <= 440 ? Math.sin(((bElapsed - 60) / 380) * Math.PI) : 0;
          var pulse2 = bElapsed >= 540 && bElapsed <= 960 ? Math.sin(((bElapsed - 540) / 420) * Math.PI) : 0;
          barkOpen = Math.max(pulse1, pulse2);

          barkHeadY -= barkOpen * 6.5;
          barkHeadRot -= barkOpen * 6.2;
          earBounce = -barkOpen * 9.5;

          // Temporary dialogue bubble appears ONLY during the active bark event and vanishes afterward
          var bIn = Math.min(1, bElapsed / 180);
          var bOut = Math.min(1, (bella.actionDuration - bElapsed) / 240);
          barkBubbleAlpha = Math.max(0, Math.min(bIn, bOut));
          barkBubbleY = (1 - barkBubbleAlpha) * 8 - barkOpen * 3.5;
          barkBubbleScale = 0.92 + barkBubbleAlpha * 0.08 + barkOpen * 0.05;
        }

        setSvgPartTransform(bella.parts['head'], 0, barkHeadY, barkHeadRot, 1, 1);
        setSvgPartTransform(bella.parts['ear-l'], 0, 0, earBounce, 1, 1);
        setSvgPartTransform(bella.parts['ear-r'], 0, 0, -earBounce, 1, 1);

        if (bella.parts['mouth-closed']) {
          bella.parts['mouth-closed'].setAttribute('opacity', barkOpen > 0.12 ? '0' : '1');
        }
        if (bella.parts['mouth-open']) {
          bella.parts['mouth-open'].setAttribute('opacity', barkOpen > 0.12 ? '1' : '0');
          setSvgPartTransform(bella.parts['mouth-open'], 0, barkOpen * 1.5, 0, 0.85 + barkOpen * 0.3, Math.max(0.2, barkOpen * 1.25));
        }

        setDialogueState(bella.parts['dialogue-bark'], barkBubbleAlpha, 0, barkBubbleY, barkBubbleScale);
        updateAnimalBlink(bella, now);
      }

      // 3. BROWN DOG: COCO (Chestnut Dachshund — Continuous Hip-Pivoted Tail Wagging, Breathing & Blinking)
      var coco = rigs['coco'];
      if (coco) {
        updateWoodlandFoliage(coco, t);

        if (coco.actionType === 'idle' && now >= coco.nextActionAt) {
          coco.actionType = 'happy-wag';
          coco.actionStart = now;
          coco.actionDuration = 2200 + Math.random() * 1400;
        } else if (coco.actionType === 'happy-wag' && now - coco.actionStart >= coco.actionDuration) {
          coco.actionType = 'idle';
          coco.nextActionAt = now + 2600 + Math.random() * 2400;
        }

        var isFastWag = coco.actionType === 'happy-wag';
        // Tail pivots around exact hip attachment (194, 342) without rotating the dog
        var wagSpeed = isFastWag ? 13.5 : 8.2;
        var wagAmp = isFastWag ? 27 : 20;
        var tailAngle = Math.sin(t * wagSpeed) * wagAmp;
        setSvgPartTransform(coco.parts['tail'], 0, 0, tailAngle, 1, 1);

        // Subtle breathing chest & natural head/ear movement
        var cocoBreathe = Math.sin(t * 2.6 + 2.2);
        setSvgPartTransform(coco.parts['chest'], 0, -cocoBreathe * 1.8, 0, 1 + cocoBreathe * 0.034, 1 + cocoBreathe * 0.042);
        setSvgPartTransform(
          coco.parts['head'],
          0,
          -cocoBreathe * 1.6 + Math.sin(t * 1.7) * 1.2,
          Math.sin(t * 1.3) * 1.8 + (isFastWag ? Math.sin(t * 6.5) * 1.5 : 0),
          1,
          1
        );
        setSvgPartTransform(coco.parts['ear-l'], 0, 0, Math.sin(t * 2.4) * 5.5, 1, 1);

        updateAnimalBlink(coco, now);
      }

      // Helper: Shared Luna Silver Tabby animation (desktop rail + mobile reviews companion)
      function animateLunaRig(lunaRig, nowTs, sec) {
        updateWoodlandFoliage(lunaRig, sec);

        if (lunaRig.actionType === 'idle' && nowTs >= lunaRig.nextActionAt) {
          lunaRig.cycleCount += 1;
          lunaRig.actionType = lunaRig.isMobile
            ? (lunaRig.cycleCount % 3 !== 0 ? 'meowing' : 'purring')
            : (lunaRig.cycleCount % 2 === 1 ? 'meowing' : 'purring');
          lunaRig.actionStart = nowTs;
          lunaRig.actionDuration = lunaRig.actionType === 'meowing'
            ? (lunaRig.isMobile ? 1750 : 1500)
            : 2400;
        } else if (lunaRig.actionType !== 'idle' && nowTs - lunaRig.actionStart >= lunaRig.actionDuration) {
          lunaRig.actionType = 'idle';
          lunaRig.nextActionAt = nowTs + (lunaRig.isMobile ? (1800 + Math.random() * 1200) : (3200 + Math.random() * 2800));
        }

        var isMeowing = lunaRig.actionType === 'meowing';
        var isPurring = lunaRig.actionType === 'purring';

        // Continuous swishing ringed tail around (180, 378)
        var tailSwish = Math.sin(sec * 2.1 + 0.5) * 14 + Math.sin(sec * 0.9) * 4;
        setSvgPartTransform(lunaRig.parts['tail'], 0, 0, tailSwish, 1, 1);

        // Breathing & purring chest/body
        var catBreathe = Math.sin(sec * 2.2 + 0.8);
        var purrShimmer = isPurring ? Math.sin(sec * 26) * 0.012 : 0;
        setSvgPartTransform(
          lunaRig.parts['chest'],
          0,
          -catBreathe * 2.2,
          0,
          1 + catBreathe * 0.036 + purrShimmer,
          1 + catBreathe * 0.044 + purrShimmer
        );

        // Ear twitches & whisker movement
        var earTwitchL = Math.sin(sec * 1.7) > 0.82 ? -8 * Math.sin(sec * 9) : Math.sin(sec * 1.3) * 2.5;
        var earTwitchR = Math.sin(sec * 1.9 + 1.2) > 0.82 ? 8 * Math.sin(sec * 9) : -Math.sin(sec * 1.4) * 2.5;
        setSvgPartTransform(lunaRig.parts['ear-l'], 0, 0, earTwitchL, 1, 1);
        setSvgPartTransform(lunaRig.parts['ear-r'], 0, 0, earTwitchR, 1, 1);
        setSvgPartTransform(lunaRig.parts['whiskers'], 0, 0, Math.sin(sec * 2.6) * 2.2, 1 + Math.sin(sec * 3.1) * 0.025, 1);

        var meowOpen = 0;
        var meowBubbleAlpha = 0;
        var zzzBubbleAlpha = 0;
        var catHeadY = -catBreathe * 1.8;
        var catHeadRot = Math.sin(sec * 1.1) * 1.5;
        lunaRig.extraEyelidClosure = 0;

        if (isMeowing) {
          var mElapsed = nowTs - lunaRig.actionStart;
          // Smooth opening and closing mouth during "Meow~"
          var openEnd = lunaRig.actionDuration - 320;
          if (mElapsed >= 80 && mElapsed <= openEnd) {
            meowOpen = Math.sin(((mElapsed - 80) / (openEnd - 80)) * Math.PI);
          }
          catHeadY -= meowOpen * 4.5;
          catHeadRot += meowOpen * 3.2;

          var mIn = Math.min(1, mElapsed / 200);
          var mOut = Math.min(1, (lunaRig.actionDuration - mElapsed) / 260);
          meowBubbleAlpha = Math.max(0, Math.min(mIn, mOut));
        } else if (isPurring) {
          var zElapsed = nowTs - lunaRig.actionStart;
          var zIn = Math.min(1, zElapsed / 320);
          var zOut = Math.min(1, (lunaRig.actionDuration - zElapsed) / 360);
          zzzBubbleAlpha = Math.max(0, Math.min(zIn, zOut));
          // Contented resting/purring eyes close softly while "Purr… zzz" floats
          lunaRig.extraEyelidClosure = zzzBubbleAlpha * 0.85;
          catHeadY += zzzBubbleAlpha * 2.2;
          catHeadRot -= zzzBubbleAlpha * 2.0;
        }

        setSvgPartTransform(lunaRig.parts['head'], 0, catHeadY, catHeadRot, 1, 1);

        if (lunaRig.parts['mouth-closed']) {
          lunaRig.parts['mouth-closed'].setAttribute('opacity', meowOpen > 0.15 ? '0' : '1');
        }
        if (lunaRig.parts['mouth-open']) {
          lunaRig.parts['mouth-open'].setAttribute('opacity', meowOpen > 0.15 ? '1' : '0');
          setSvgPartTransform(lunaRig.parts['mouth-open'], 0, meowOpen * 1.2, 0, 0.85 + meowOpen * 0.25, Math.max(0.15, meowOpen * 1.2));
        }

        setDialogueState(
          lunaRig.parts['dialogue-meow'],
          meowBubbleAlpha,
          0,
          (1 - meowBubbleAlpha) * 7 - meowOpen * 2.5,
          0.92 + meowBubbleAlpha * 0.08
        );
        setDialogueState(
          lunaRig.parts['dialogue-zzz'],
          zzzBubbleAlpha,
          Math.sin(sec * 2.5) * 2,
          (1 - zzzBubbleAlpha) * 8 - (isPurring ? ((nowTs - lunaRig.actionStart) / lunaRig.actionDuration) * 6 : 0),
          0.92 + zzzBubbleAlpha * 0.08
        );

        updateAnimalBlink(lunaRig, nowTs);
      }

      // Helper: Mobile Clover (Meadow Bunny — exact scenic_rails.php artwork) happily nibbling a carrot beside the search bar
      function animateCloverGnawRig(cloverRig, nowTs, sec) {
        if (cloverRig.actionType === 'gnawing' && nowTs - cloverRig.actionStart >= cloverRig.actionDuration) {
          cloverRig.actionType = 'pause';
          cloverRig.actionStart = nowTs;
          cloverRig.actionDuration = 1050;
        } else if (cloverRig.actionType !== 'gnawing' && nowTs - cloverRig.actionStart >= cloverRig.actionDuration) {
          cloverRig.actionType = 'gnawing';
          cloverRig.actionStart = nowTs;
          cloverRig.actionDuration = 2400;
        }

        var isNibbling = cloverRig.actionType === 'gnawing';
        var bunnyBreathe = Math.sin(sec * 2.9 + 1.7);
        var gElapsed = nowTs - cloverRig.actionStart;

        var burstBlend = 0;
        if (isNibbling) {
          var fadeIn = Math.min(1, gElapsed / 220);
          var fadeOut = Math.min(1, (cloverRig.actionDuration - gElapsed) / 220);
          burstBlend = Math.max(0, Math.min(fadeIn, fadeOut));
        }

        // Rhythmic happy carrot nibble (~3.4 nibbles/sec)
        var biteWave = isNibbling ? ((Math.sin(gElapsed * 0.0215) + 1) * 0.5) * burstBlend : 0;
        var jawOpen = isNibbling ? (1 - biteWave) * burstBlend : 0;

        // Gentle upright breathing torso (exact scenic_rails.php Clover motion)
        setSvgPartTransform(
          cloverRig.parts['chest'],
          0,
          -bunnyBreathe * 1.8,
          0,
          1 + bunnyBreathe * 0.036,
          1 + bunnyBreathe * 0.044
        );

        // Fluffy cottontail happy subtle wiggle
        if (cloverRig.parts['tail']) {
          setSvgPartTransform(
            cloverRig.parts['tail'],
            0,
            isNibbling ? Math.sin(sec * 13) * 1.4 * burstBlend : -bunnyBreathe * 1.0,
            isNibbling ? Math.sin(sec * 13) * 4.5 * burstBlend : 0,
            1,
            1
          );
        }

        // Upright head with a natural gentle downward nibble bob (stays 100% seated on torso)
        var headBobY = -bunnyBreathe * 1.5 + burstBlend * 2.0 + biteWave * 2.4;
        var headRot = isNibbling ? (Math.sin(gElapsed * 0.0105) * 2.4 * burstBlend) : (Math.sin(sec * 1.5) * 1.5);
        setSvgPartTransform(cloverRig.parts['head'], 0, headBobY, headRot, 1, 1);

        // Independent tall pink-lined ears bobbing naturally
        var earL = Math.sin(sec * 2.1) * 7.0 + Math.cos(sec * 3.4) * 3.0 - biteWave * 6.5;
        var earR = Math.cos(sec * 1.85 + 0.9) * 8.0 + Math.sin(sec * 2.9) * 3.0 + biteWave * 7.0;
        setSvgPartTransform(cloverRig.parts['ear-l'], 0, 0, earL, 1, 1);
        setSvgPartTransform(cloverRig.parts['ear-r'], 0, 0, earR, 1, 1);

        // Crisp toggle between resting smile and open nibbling mouth + white bunny buck tooth
        var showNibbleMouth = isNibbling && burstBlend > 0.22;
        if (cloverRig.parts['mouth-closed']) {
          cloverRig.parts['mouth-closed'].setAttribute('opacity', showNibbleMouth ? '0' : '1');
        }
        if (cloverRig.parts['mouth-gnaw']) {
          cloverRig.parts['mouth-gnaw'].setAttribute('opacity', showNibbleMouth ? '1' : '0');
        }
        if (cloverRig.parts['mouth-cavity']) {
          setSvgPartTransform(
            cloverRig.parts['mouth-cavity'],
            0,
            jawOpen * 1.4,
            0,
            0.90 + jawOpen * 0.16,
            0.46 + jawOpen * 0.66
          );
        }
        if (cloverRig.parts['tooth']) {
          setSvgPartTransform(
            cloverRig.parts['tooth'],
            0,
            biteWave * 1.8,
            0,
            1,
            0.94 + jawOpen * 0.16
          );
        }

        // Carrot held in front paws lifting slightly into each nibble
        if (cloverRig.parts['carrot']) {
          var carrotY = -bunnyBreathe * 1.4 - burstBlend * 4.0 + biteWave * 2.2;
          var carrotRot = burstBlend * -3.5 + biteWave * 4.5;
          setSvgPartTransform(cloverRig.parts['carrot'], 0, carrotY, carrotRot, 1, 1);
        }

        // Rapid bunny nose & whisker twitching
        var noseTwitch = Math.sin(sec * 13.5) * (isNibbling ? 0.95 : Math.max(0, Math.sin(sec * 2.2)));
        setSvgPartTransform(
          cloverRig.parts['nose'],
          0,
          noseTwitch * -1.6 - jawOpen * 0.9,
          noseTwitch * 1.6,
          1 + Math.abs(noseTwitch) * 0.06,
          1 - Math.abs(noseTwitch) * 0.04
        );

        updateAnimalBlink(cloverRig, nowTs);
      }

      // 4. CAT: LUNA (Silver Tabby — Meowing & Purring with Temporary "Meow~" & "Purr… zzz" Indicators)
      var luna = rigs['luna'];
      if (luna) {
        animateLunaRig(luna, now, t);
      }

      // Mobile Homepage Companions (390 x 844 / <= 480px: Luna at Reviews + Clover gnawing Search)
      if (mobileCompanionMq && mobileCompanionMq.matches) {
        if (mobileRigs['luna']) {
          animateLunaRig(mobileRigs['luna'], now, t);
        }
        if (mobileRigs['clover']) {
          animateCloverGnawRig(mobileRigs['clover'], now, t);
        }
      }

      // 5. BUNNY: CLOVER (Meadow Bunny — Independently Articulated Living Ears, Twitching Nose & Breathing)
      var clover = rigs['clover'];
      if (clover) {
        updateWoodlandFoliage(clover, t);

        if (clover.actionType === 'idle' && now >= clover.nextActionAt) {
          var r = Math.random();
          clover.actionType = r < 0.36 ? 'perk-left' : (r < 0.72 ? 'perk-right' : 'perk-both');
          clover.actionStart = now;
          clover.actionDuration = 950 + Math.random() * 650;
        } else if (clover.actionType !== 'idle' && now - clover.actionStart >= clover.actionDuration) {
          clover.actionType = 'idle';
          clover.nextActionAt = now + 1600 + Math.random() * 2200;
        }

        // Gentle breathing chest
        var bunnyBreathe = Math.sin(t * 2.9 + 1.7);
        setSvgPartTransform(clover.parts['chest'], 0, -bunnyBreathe * 2.0, 0, 1 + bunnyBreathe * 0.038, 1 + bunnyBreathe * 0.046);
        setSvgPartTransform(clover.parts['head'], 0, -bunnyBreathe * 1.6, Math.sin(t * 1.5) * 1.4, 1, 1);

        // Independent Left & Right Ear rotations + sudden ear perks/twitches
        var earLeftAngle = Math.sin(t * 2.1) * 7.5 + Math.cos(t * 3.4) * 3.5;
        var earRightAngle = Math.cos(t * 1.85 + 0.9) * 8.5 + Math.sin(t * 2.9) * 3.5;

        if (clover.actionType !== 'idle') {
          var cElapsed = now - clover.actionStart;
          var perkPulse = Math.sin((cElapsed / clover.actionDuration) * Math.PI) * (1 + 0.35 * Math.sin(cElapsed * 0.028));
          if (clover.actionType === 'perk-left' || clover.actionType === 'perk-both') {
            earLeftAngle -= perkPulse * 18;
          }
          if (clover.actionType === 'perk-right' || clover.actionType === 'perk-both') {
            earRightAngle += perkPulse * 20;
          }
        }

        setSvgPartTransform(clover.parts['ear-l'], 0, 0, earLeftAngle, 1, 1);
        setSvgPartTransform(clover.parts['ear-r'], 0, 0, earRightAngle, 1, 1);

        // Rapid bunny nose & whisker twitching
        var noseTwitch = Math.sin(t * 11.5) * Math.max(0, Math.sin(t * 2.2));
        setSvgPartTransform(
          clover.parts['nose'],
          0,
          noseTwitch * -2.2,
          noseTwitch * 1.8,
          1 + Math.abs(noseTwitch) * 0.08,
          1 - Math.abs(noseTwitch) * 0.05
        );

        updateAnimalBlink(clover, now);
      }

      // 6. BIRD: PIP (Woodland Songbird — Split Upper/Lower Beak Chirping, Wing Flutter & Temporary "Chirp! ♫")
      var pip = rigs['pip'];
      if (pip) {
        updateWoodlandFoliage(pip, t);

        if (pip.actionType === 'idle' && now >= pip.nextActionAt) {
          pip.actionType = 'chirping';
          pip.actionStart = now;
          pip.actionDuration = 1380;
        } else if (pip.actionType === 'chirping' && now - pip.actionStart >= pip.actionDuration) {
          pip.actionType = 'idle';
          pip.nextActionAt = now + 3100 + Math.random() * 2700;
        }

        var pipBreathe = Math.sin(t * 3.4 + 0.4);
        setSvgPartTransform(pip.parts['chest'], 0, 0, 0, 1 + pipBreathe * 0.035, 1 + pipBreathe * 0.04);

        var chirpOpen = 0;
        var chirpBubbleAlpha = 0;
        var bodyY = -pipBreathe * 1.4;
        var bodyRot = Math.sin(t * 1.8) * 1.6;
        var headRot = Math.sin(t * 2.3) * 2.8;
        var wingRot = Math.sin(t * 2.6) * 2.5;
        var wingSy = 1;

        if (pip.actionType === 'chirping') {
          var pElapsed = now - pip.actionStart;
          // Three crisp chirps ("Chirp-chirp-chirp!")
          var c1 = pElapsed >= 50 && pElapsed <= 330 ? Math.sin(((pElapsed - 50) / 280) * Math.PI) : 0;
          var c2 = pElapsed >= 410 && pElapsed <= 700 ? Math.sin(((pElapsed - 410) / 290) * Math.PI) : 0;
          var c3 = pElapsed >= 780 && pElapsed <= 1080 ? Math.sin(((pElapsed - 780) / 300) * Math.PI) : 0;
          chirpOpen = Math.max(c1, c2, c3);

          bodyY -= chirpOpen * 6.0;
          bodyRot -= chirpOpen * 4.5;
          headRot += chirpOpen * 5.5;
          wingRot = -chirpOpen * 18;
          wingSy = 1 + chirpOpen * 0.08;

          var cIn = Math.min(1, pElapsed / 170);
          var cOut = Math.min(1, (pip.actionDuration - pElapsed) / 230);
          chirpBubbleAlpha = Math.max(0, Math.min(cIn, cOut));
        }

        setSvgPartTransform(pip.parts['body'], 0, bodyY, bodyRot, 1, 1);
        setSvgPartTransform(pip.parts['head'], 0, 0, headRot, 1, 1);
        setSvgPartTransform(pip.parts['wing'], 0, 0, wingRot, 1, wingSy);
        setSvgPartTransform(pip.parts['tail'], 0, 0, Math.sin(t * 3.1) * 4 + chirpOpen * 6, 1, 1);

        // Visibly open and close upper and lower beak mandibles
        setSvgPartTransform(pip.parts['beak-top'], 0, 0, chirpOpen * 16, 1, 1);
        setSvgPartTransform(pip.parts['beak-bottom'], 0, 0, -chirpOpen * 20, 1, 1);
        if (pip.parts['beak-cavity']) {
          pip.parts['beak-cavity'].setAttribute('opacity', chirpOpen > 0.15 ? '1' : '0');
        }

        setDialogueState(
          pip.parts['dialogue-chirp'],
          chirpBubbleAlpha,
          0,
          (1 - chirpBubbleAlpha) * 7 - chirpOpen * 3,
          0.92 + chirpBubbleAlpha * 0.08 + chirpOpen * 0.04
        );

        updateAnimalBlink(pip, now);
      }

      window.requestAnimationFrame(tickPetWorld);
    }

    window.requestAnimationFrame(tickPetWorld);

    // Subtle GPU Depth Parallax on Scroll for the 2 Active Animals on the Page
    var rafPending = false;
    var updateScrollParallax = function () {
      rafPending = false;
      var scrollY = window.scrollY || window.pageYOffset || 0;
      var canopyOffset = Math.sin(scrollY * 0.008) * 3.5;
      var charOffset = Math.cos(scrollY * 0.006) * 2.5;
      var underOffset = Math.sin(scrollY * 0.007 + 1.2) * -3;

      sceneFigures.forEach(function (fig) {
        if (!fig.classList.contains('is-active')) return;
        var canopy = fig.querySelector('.scenic-canopy');
        var character = fig.querySelector('.scenic-character');
        var undergrowth = fig.querySelector('.scenic-undergrowth');
        if (canopy) {
          canopy.style.transform = 'translate3d(0, ' + canopyOffset.toFixed(2) + 'px, 0) scale(1)';
        }
        if (character) {
          character.style.transform = 'translate3d(0, ' + charOffset.toFixed(2) + 'px, 0) scale(1)';
        }
        if (undergrowth) {
          undergrowth.style.transform = 'translate3d(0, ' + underOffset.toFixed(2) + 'px, 0) scale(1)';
        }
      });
    };

    window.addEventListener(
      'scroll',
      function () {
        if (!rafPending) {
          rafPending = true;
          window.requestAnimationFrame(updateScrollParallax);
        }
      },
      { passive: true }
    );
  }

  // ---- Pet Store Business Manager Interactions ----

  // 1. Stock Adjustment Return Reason & Inspection Toggle
  document.querySelectorAll('.stock-adj-form').forEach(function (form) {
    var reasonSelect = form.querySelector('.inc-reason-select');
    var inspectionWrap = form.querySelector('.return-inspection-wrap');
    var inspectionCheck = form.querySelector('.inspection-checkbox');
    var submitBtn = form.querySelector('button[type="submit"]');
    if (!reasonSelect || !inspectionWrap) return;

    function syncInspection() {
      var val = reasonSelect.value;
      var isReturn = val.indexOf('Returned product') !== -1;
      if (isReturn) {
        inspectionWrap.style.display = 'block';
        if (inspectionCheck && !inspectionCheck.checked) {
          submitBtn.disabled = true;
        } else {
          submitBtn.disabled = false;
        }
      } else {
        inspectionWrap.style.display = 'none';
        submitBtn.disabled = false;
      }
    }

    reasonSelect.addEventListener('change', syncInspection);
    if (inspectionCheck) {
      inspectionCheck.addEventListener('change', syncInspection);
    }
  });

  // 2. Operating Hours Day Toggle & Custom Time Picker Sync
  function syncCustomTimePicker(picker) {
    var hrSel = picker.querySelector('.time-hour');
    var minSel = picker.querySelector('.time-minute');
    var ampmSel = picker.querySelector('.time-ampm');
    var hiddenInp = picker.querySelector('.time-hidden-val');
    if (!hrSel || !minSel || !ampmSel || !hiddenInp) return;

    var h = parseInt(hrSel.value, 10);
    var m = minSel.value;
    var ap = ampmSel.value;

    if (ap === 'PM' && h < 12) h += 12;
    if (ap === 'AM' && h === 12) h = 0;

    var formatted = (h < 10 ? '0' : '') + h + ':' + m + ':00';
    hiddenInp.value = formatted;
  }

  document.querySelectorAll('.custom-time-picker').forEach(function (picker) {
    picker.querySelectorAll('.time-select').forEach(function (sel) {
      sel.addEventListener('change', function () {
        syncCustomTimePicker(picker);
      });
    });
  });

  document.querySelectorAll('.day-open-toggle').forEach(function (chk) {
    chk.addEventListener('change', function () {
      var day = chk.getAttribute('data-day');
      var label = chk.closest('label').querySelector('.day-status-label');
      var pickers = document.querySelectorAll('.custom-time-picker[data-day="' + day + '"]');
      if (chk.checked) {
        if (label) label.textContent = 'Open';
        pickers.forEach(function (p) {
          p.classList.remove('is-disabled');
          p.querySelectorAll('select').forEach(function (s) { s.disabled = false; });
          var hidden = p.querySelector('.time-hidden-val');
          if (hidden) hidden.disabled = false;
        });
      } else {
        if (label) label.textContent = 'Closed';
        pickers.forEach(function (p) {
          p.classList.add('is-disabled');
          p.querySelectorAll('select').forEach(function (s) { s.disabled = true; });
          var hidden = p.querySelector('.time-hidden-val');
          if (hidden) hidden.disabled = true;
        });
      }
    });
  });

  // 3. Multi-Step Purchase Order Modal
  var poModal = document.getElementById('po-create-modal');
  if (poModal) {
    var poStep1 = document.getElementById('po-step-1');
    var poStep2 = document.getElementById('po-step-2');
    var step1Ind = document.getElementById('step-1-indicator');
    var step2Ind = document.getElementById('step-2-indicator');
    var addProdBtn = document.getElementById('po-add-product-btn');
    var itemsContainer = document.getElementById('po-items-container');
    var continueBtn = document.getElementById('po-step-1-continue-btn');
    var backBtn = document.getElementById('po-step-2-back-btn');
    var confirmBtn = document.getElementById('po-confirm-order-btn');
    var supplierNameInput = document.getElementById('po-supplier-name');
    var contactPersonInput = document.getElementById('po-contact-person');
    var supplierEmailInput = document.getElementById('po-supplier-email');
    var supplierPhoneInput = document.getElementById('po-supplier-phone');
    var optTpl = document.getElementById('po-product-options-template');

    var poItemIndex = 0;

    function checkStep1Validity() {
      var itemRows = itemsContainer.querySelectorAll('.po-item-row');
      var hasValidProduct = false;
      itemRows.forEach(function (row) {
        var sel = row.querySelector('select');
        var qty = row.querySelector('input[type="number"]');
        if (sel && sel.value !== '' && qty && parseInt(qty.value, 10) > 0) {
          hasValidProduct = true;
        }
      });
      if (continueBtn) {
        continueBtn.disabled = !hasValidProduct;
      }
    }

    function checkStep2Validity() {
      var nameVal = supplierNameInput ? supplierNameInput.value.trim() : '';
      var contactVal = contactPersonInput ? contactPersonInput.value.trim() : '';
      var emailVal = supplierEmailInput ? supplierEmailInput.value.trim() : '';
      var phoneVal = supplierPhoneInput ? supplierPhoneInput.value.trim() : '';

      var hasContactMethod = emailVal.length > 0 || phoneVal.length > 0;
      var isValid = nameVal.length > 0 && contactVal.length > 0 && hasContactMethod;
      if (confirmBtn) {
        confirmBtn.disabled = !isValid;
      }
    }

    if (addProdBtn && optTpl) {
      addProdBtn.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'po-item-row row';
        row.style.gap = '0.75rem';
        row.style.marginBottom = '0.75rem';
        row.style.alignItems = 'center';

        row.innerHTML =
          '<div style="flex: 2;">' +
          '<select name="items[' + poItemIndex + '][business_product_id]" required style="width: 100%;">' +
          optTpl.innerHTML +
          '</select>' +
          '</div>' +
          '<div style="width: 130px;">' +
          '<input type="number" name="items[' + poItemIndex + '][quantity_ordered]" min="1" value="10" required style="width: 100%;" placeholder="Quantity">' +
          '</div>' +
          '<button type="button" class="btn ghost btn-sm po-remove-row-btn" style="color: var(--danger, #dc2626);" title="Remove row">&times;</button>';

        itemsContainer.appendChild(row);
        poItemIndex++;

        row.querySelector('select').addEventListener('change', checkStep1Validity);
        row.querySelector('input[type="number"]').addEventListener('input', checkStep1Validity);
        row.querySelector('.po-remove-row-btn').addEventListener('click', function () {
          row.remove();
          checkStep1Validity();
        });

        checkStep1Validity();
      });
    }

    if (continueBtn) {
      continueBtn.addEventListener('click', function () {
        poStep1.style.display = 'none';
        poStep2.style.display = 'block';
        if (step1Ind) step1Ind.style.color = 'var(--muted, #9ca3af)';
        if (step2Ind) step2Ind.style.color = 'var(--primary, #0284c7)';
        checkStep2Validity();
      });
    }

    if (backBtn) {
      backBtn.addEventListener('click', function () {
        poStep2.style.display = 'none';
        poStep1.style.display = 'block';
        if (step1Ind) step1Ind.style.color = 'var(--primary, #0284c7)';
        if (step2Ind) step2Ind.style.color = 'var(--muted, #9ca3af)';
      });
    }

    [supplierNameInput, contactPersonInput, supplierEmailInput, supplierPhoneInput].forEach(function (inp) {
      if (inp) {
        inp.addEventListener('input', checkStep2Validity);
      }
    });
  }

  // 4. Payment Settings E-Wallet OTP Workflow (GCash & Maya)
  function initEWalletOtp(service) {
    var mobileInput = document.getElementById(service + '-mobile');
    var sendBtn = document.getElementById(service + '-send-otp-btn');
    var otpInput = document.getElementById(service + '-otp');
    var verifyBtn = document.getElementById(service + '-verify-otp-btn');
    var confirmBtn = document.getElementById(service + '-confirm-btn');
    var isVerifiedInput = document.getElementById(service + '-is-verified');
    var noticeEl = document.getElementById(service + '-otp-notice');
    var statusEl = document.getElementById(service + '-verify-status');
    var badgeEl = document.getElementById(service + '-badge');

    if (!mobileInput || !sendBtn) return;

    var csrfToken = '';
    var csrfInput = document.querySelector('input[name="csrf_token"]') || document.querySelector('input[name="_csrf"]');
    if (csrfInput) csrfToken = csrfInput.value;

    function checkPhone() {
      var val = mobileInput.value.replace(/[^0-9]/g, '');
      sendBtn.disabled = !(val.length >= 10 && val.length <= 12);
    }
    mobileInput.addEventListener('input', checkPhone);
    checkPhone();

    sendBtn.addEventListener('click', function () {
      sendBtn.disabled = true;
      sendBtn.textContent = 'Sending...';
      if (noticeEl) noticeEl.textContent = 'Contacting SMS gateway verification service...';

      var body = new URLSearchParams();
      body.append('service_type', service);
      body.append('mobile_number', mobileInput.value.trim());
      body.append('csrf_token', csrfToken);

      fetch('/manager/settings/send-otp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          sendBtn.textContent = 'Send OTP';
          sendBtn.disabled = false;
          if (data.ok) {
            if (noticeEl) {
              noticeEl.style.color = 'var(--success, #16a34a)';
              noticeEl.textContent = data.message;
            }
            if (otpInput) {
              otpInput.disabled = false;
              otpInput.focus();
            }
          } else {
            if (noticeEl) {
              noticeEl.style.color = 'var(--danger, #dc2626)';
              noticeEl.textContent = data.message || 'Failed to dispatch verification code.';
            }
          }
        })
        .catch(function () {
          sendBtn.textContent = 'Send OTP';
          sendBtn.disabled = false;
          if (noticeEl) {
            noticeEl.style.color = 'var(--danger, #dc2626)';
            noticeEl.textContent = 'Network error while contacting verification service.';
          }
        });
    });

    if (otpInput && verifyBtn) {
      otpInput.addEventListener('input', function () {
        var v = otpInput.value.trim();
        verifyBtn.disabled = !(v.length === 6 && /^\d+$/.test(v));
      });

      verifyBtn.addEventListener('click', function () {
        verifyBtn.disabled = true;
        verifyBtn.textContent = 'Verifying...';

        var body = new URLSearchParams();
        body.append('service_type', service);
        body.append('mobile_number', mobileInput.value.trim());
        body.append('otp', otpInput.value.trim());
        body.append('csrf_token', csrfToken);

        fetch('/manager/settings/verify-otp', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            verifyBtn.textContent = 'Verify OTP';
            if (data.ok) {
              if (statusEl) {
                statusEl.style.color = 'var(--success, #16a34a)';
                statusEl.textContent = 'Verified: ' + data.message;
              }
              if (badgeEl) {
                badgeEl.className = 'status ok';
                badgeEl.textContent = 'Verified';
              }
              if (isVerifiedInput) isVerifiedInput.value = '1';
              if (confirmBtn) confirmBtn.disabled = false;
            } else {
              verifyBtn.disabled = false;
              if (statusEl) {
                statusEl.style.color = 'var(--danger, #dc2626)';
                statusEl.textContent = data.message || 'OTP verification failed.';
              }
            }
          })
          .catch(function () {
            verifyBtn.textContent = 'Verify OTP';
            verifyBtn.disabled = false;
            if (statusEl) {
              statusEl.style.color = 'var(--danger, #dc2626)';
              statusEl.textContent = 'Verification server error.';
            }
          });
      });
    }
  }

  initEWalletOtp('gcash');
  initEWalletOtp('maya');

  // ==========================================
  // Grooming Weekly Calendar & Speech-Bubble Dialogue
  // ==========================================
  var monthPicker = document.getElementById('grooming-cal-month-picker');
  if (monthPicker) {
    monthPicker.addEventListener('change', function () {
      var val = (monthPicker.value || '').trim();
      if (/^\d{4}-\d{2}$/.test(val)) {
        var url = new URL(window.location.href);
        url.searchParams.set('start', val + '-01');
        url.searchParams.delete('month');
        url.searchParams.delete('select');
        window.location.href = url.toString();
      }
    });
  }

  var calBodyWrap = document.getElementById('grooming-cal-body-wrap');
  if (calBodyWrap) {
    function closeAllSpeechBubbles() {
      calBodyWrap.querySelectorAll('.grooming-speech-bubble').forEach(function (b) {
        b.classList.remove('is-open');
        b.setAttribute('hidden', 'hidden');
      });
      calBodyWrap.querySelectorAll('.grooming-cal-appt-block').forEach(function (blk) {
        blk.classList.remove('is-selected');
        blk.setAttribute('aria-expanded', 'false');
      });
    }

    function openSpeechBubbleForBlock(block) {
      if (!block) return;
      var apptId = block.getAttribute('data-appt-id');
      var bubble = document.getElementById('grooming-speech-bubble-' + apptId);
      if (!bubble) return;

      closeAllSpeechBubbles();

      block.classList.add('is-selected');
      block.setAttribute('aria-expanded', 'true');
      bubble.removeAttribute('hidden');
      bubble.classList.add('is-open');

      var wrapRect = calBodyWrap.getBoundingClientRect();
      var blockRect = block.getBoundingClientRect();
      var dayIdx = parseInt(block.getAttribute('data-day-index') || '0', 10);

      var relTop = blockRect.top - wrapRect.top;
      var relLeft = blockRect.left - wrapRect.left;
      var relRight = blockRect.right - wrapRect.left;

      var bubbleWidth = bubble.offsetWidth || 360;
      var bubbleTop = Math.max(8, relTop - 10);
      var tailOffsetTop = Math.max(16, Math.min(48, (blockRect.top - wrapRect.top) - bubbleTop + 12));

      var tailEl = bubble.querySelector('.grooming-speech-bubble-tail');
      if (tailEl) {
        tailEl.style.top = tailOffsetTop + 'px';
      }

      if (dayIdx >= 4 && (relLeft - bubbleWidth - 14) >= 8) {
        bubble.classList.remove('tail-left');
        bubble.classList.add('tail-right');
        bubble.style.left = Math.max(8, relLeft - bubbleWidth - 12) + 'px';
      } else {
        bubble.classList.remove('tail-right');
        bubble.classList.add('tail-left');
        var targetLeft = relRight + 12;
        if (targetLeft + bubbleWidth > wrapRect.width - 8) {
          targetLeft = Math.max(8, wrapRect.width - bubbleWidth - 8);
        }
        bubble.style.left = targetLeft + 'px';
      }
      bubble.style.top = bubbleTop + 'px';
    }

    calBodyWrap.addEventListener('click', function (ev) {
      var closeBtn = ev.target.closest('[data-close-bubble]');
      if (closeBtn) {
        ev.preventDefault();
        closeAllSpeechBubbles();
        return;
      }

      var apptBlock = ev.target.closest('.grooming-cal-appt-block');
      if (apptBlock) {
        ev.preventDefault();
        if (apptBlock.classList.contains('is-selected')) {
          closeAllSpeechBubbles();
        } else {
          openSpeechBubbleForBlock(apptBlock);
        }
        return;
      }

      if (!ev.target.closest('.grooming-speech-bubble')) {
        closeAllSpeechBubbles();
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') {
        closeAllSpeechBubbles();
      }
    });

    // Position pre-selected appointment bubble on initial load
    var initialSelectedBlock = calBodyWrap.querySelector('.grooming-cal-appt-block.is-selected');
    if (initialSelectedBlock) {
      openSpeechBubbleForBlock(initialSelectedBlock);
    }
  }

  // ==========================================
  // Temporary Inventory Access Key (Generate / View / Live Expiry)
  // ==========================================
  var genInvKeyForm = document.getElementById('generate-inv-key-form');
  if (genInvKeyForm) {
    genInvKeyForm.addEventListener('submit', function (ev) {
      if (genInvKeyForm._submitting) {
        ev.preventDefault();
        return;
      }
      genInvKeyForm._submitting = true;
      var btn = document.getElementById('generate-inv-key-btn');
      if (btn) {
        btn.disabled = true;
      }
    });
  }

  var copyKeyBtn = document.getElementById('copy-inv-key-btn');
  if (copyKeyBtn) {
    copyKeyBtn.addEventListener('click', function () {
      var keyText = copyKeyBtn.getAttribute('data-copy-key') || '';
      if (!keyText) return;
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(keyText).then(function () {
          copyKeyBtn.textContent = 'Copied!';
          setTimeout(function () {
            copyKeyBtn.textContent = 'Copy Key';
          }, 1600);
        }).catch(function () { });
      }
    });
  }

  var viewInvKeyBtn = document.getElementById('view-inv-key-btn');
  var invKeyCountdown = document.getElementById('inv-key-countdown');
  if (viewInvKeyBtn) {
    var initSecs = parseInt(
      (invKeyCountdown && invKeyCountdown.getAttribute('data-seconds-remaining')) ||
      viewInvKeyBtn.getAttribute('data-key-seconds') ||
      '0',
      10
    );
    if (!isNaN(initSecs) && initSecs > 0) {
      var startedAtMs = Date.now();
      var expiryTimer = setInterval(function () {
        var elapsed = Math.floor((Date.now() - startedAtMs) / 1000);
        var rem = initSecs - elapsed;
        if (rem <= 0) {
          clearInterval(expiryTimer);
          window.location.reload();
          return;
        }
        if (invKeyCountdown) {
          var mins = Math.floor(rem / 60);
          var secs = rem % 60;
          invKeyCountdown.textContent = mins > 0 ? (mins + 'm ' + secs + 's') : (secs + 's');
        }
      }, 1000);
    }
  }

  // ---- Administrator Profile Dropdown & Dark Mode Toggle ----
  function initAdminProfileDropdown() {
    var toggleBtn = document.getElementById('admin-profile-toggle');
    var dropdown = document.getElementById('admin-profile-dropdown');
    var darkModeBtn = document.getElementById('admin-dark-mode-toggle');
    var switchBtn = document.getElementById('profile-switch-btn');
    var switchMenu = document.getElementById('profile-switcher-menu');

    function closeSwitcherMenu() {
      if (switchMenu && switchBtn) {
        switchMenu.setAttribute('hidden', '');
        switchBtn.setAttribute('aria-expanded', 'false');
      }
      var parentBtns = document.querySelectorAll('.profile-switcher-parent-btn');
      parentBtns.forEach(function (btn) {
        btn.setAttribute('aria-expanded', 'false');
        var tid = btn.getAttribute('data-target');
        var u = document.getElementById('switcher-units-' + tid);
        if (u) u.setAttribute('hidden', '');
      });
    }

    if (toggleBtn && dropdown) {
      toggleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = !dropdown.hasAttribute('hidden');
        if (isOpen) {
          dropdown.setAttribute('hidden', '');
          toggleBtn.setAttribute('aria-expanded', 'false');
          closeSwitcherMenu();
        } else {
          dropdown.removeAttribute('hidden');
          toggleBtn.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && !toggleBtn.contains(e.target)) {
          dropdown.setAttribute('hidden', '');
          toggleBtn.setAttribute('aria-expanded', 'false');
          closeSwitcherMenu();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !dropdown.hasAttribute('hidden')) {
          dropdown.setAttribute('hidden', '');
          toggleBtn.setAttribute('aria-expanded', 'false');
          closeSwitcherMenu();
          toggleBtn.focus();
        }
      });
    }

    if (switchBtn && switchMenu) {
      switchBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var isMenuOpen = !switchMenu.hasAttribute('hidden');
        if (isMenuOpen) {
          switchMenu.setAttribute('hidden', '');
          switchBtn.setAttribute('aria-expanded', 'false');
        } else {
          switchMenu.removeAttribute('hidden');
          switchBtn.setAttribute('aria-expanded', 'true');
        }
      });
    }

    var parentBtns = document.querySelectorAll('.profile-switcher-parent-btn');
    parentBtns.forEach(function (pBtn) {
      pBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var targetId = pBtn.getAttribute('data-target');
        var unitsMenu = document.getElementById('switcher-units-' + targetId);
        if (!unitsMenu) return;

        var isExpanded = pBtn.getAttribute('aria-expanded') === 'true';
        if (isExpanded) {
          unitsMenu.setAttribute('hidden', '');
          pBtn.setAttribute('aria-expanded', 'false');
        } else {
          parentBtns.forEach(function (otherBtn) {
            if (otherBtn !== pBtn) {
              otherBtn.setAttribute('aria-expanded', 'false');
              var otherTarget = otherBtn.getAttribute('data-target');
              var otherUnits = document.getElementById('switcher-units-' + otherTarget);
              if (otherUnits) otherUnits.setAttribute('hidden', '');
            }
          });
          unitsMenu.removeAttribute('hidden');
          pBtn.setAttribute('aria-expanded', 'true');
        }
      });
    });

    if (darkModeBtn) {
      darkModeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (isDark) {
          document.documentElement.removeAttribute('data-theme');
          try { localStorage.setItem('vetgrpet_theme', 'light'); } catch (err) { }
        } else {
          document.documentElement.setAttribute('data-theme', 'dark');
          try { localStorage.setItem('vetgrpet_theme', 'dark'); } catch (err) { }
        }
      });
    }
  }

  initAdminProfileDropdown();

  // =========================================================================
  // Guest & Customer Products Page — Business Unit Modal, Add-to-Cart & Checkout
  // =========================================================================
  var productsRoot = document.querySelector('[data-products-root]');
  if (productsRoot) {
    var isCustomerSignedIn = productsRoot.getAttribute('data-signed-in') === '1';
    var signinModal = document.getElementById('guest-checkout-signin-modal');
    var signinTitleEl = signinModal ? signinModal.querySelector('[data-signin-modal-title]') : null;
    var signinMessageEl = signinModal ? signinModal.querySelector('[data-signin-modal-message]') : null;
    var signinProceedLink = signinModal ? signinModal.querySelector('[data-signin-proceed-link]') : null;

    function openDialogModal(dlg, triggerEl) {
      if (!dlg || typeof dlg.showModal !== 'function') return;
      if (dlg.open) return;
      dlg._triggerBtn = triggerEl || null;
      document.body.classList.add('has-modal-open');
      dlg.showModal();
      var closeBtn = dlg.querySelector('[data-modal-close]');
      if (closeBtn && typeof closeBtn.focus === 'function') {
        setTimeout(function () { closeBtn.focus(); }, 40);
      }
    }

    function openUnitModal(modalId, triggerEl, focusProductId) {
      var dlg = modalId ? document.getElementById(modalId) : null;
      if (!dlg) return;
      openDialogModal(dlg, triggerEl);
      if (focusProductId) {
        var prodCard = dlg.querySelector('[data-modal-product="' + focusProductId + '"]');
        if (prodCard && typeof prodCard.scrollIntoView === 'function') {
          setTimeout(function () {
            prodCard.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'nearest' });
          }, 60);
        }
      }
    }

    function openGuestSigninPrompt(options) {
      if (!signinModal) return;
      if (signinTitleEl) {
        signinTitleEl.textContent = options.title || 'Sign in required';
      }
      if (signinMessageEl) {
        signinMessageEl.textContent = '';
        if (options.mode === 'add') {
          signinMessageEl.appendChild(document.createTextNode('Please sign in first to add '));
          var strongProd = document.createElement('strong');
          strongProd.textContent = options.productName || 'this item';
          signinMessageEl.appendChild(strongProd);
          signinMessageEl.appendChild(document.createTextNode(' to your '));
          var strongUnitAdd = document.createElement('strong');
          strongUnitAdd.textContent = options.unitName || 'business unit';
          signinMessageEl.appendChild(strongUnitAdd);
          signinMessageEl.appendChild(document.createTextNode(' cart.'));
        } else {
          signinMessageEl.appendChild(document.createTextNode('Please sign in to proceed to checkout for '));
          var strongUnit = document.createElement('strong');
          strongUnit.textContent = options.unitName || 'this business unit';
          signinMessageEl.appendChild(strongUnit);
          signinMessageEl.appendChild(document.createTextNode(' (' + (options.countText || '0 items') + '). Your cart items will be preserved when you sign in.'));
        }
      }
      if (signinProceedLink) {
        signinProceedLink.setAttribute('href', options.signinHref || '/login');
      }
      openDialogModal(signinModal, options.triggerEl || null);
    }

    function updateUnitCheckoutControls(unitId, count) {
      var safeCount = Math.max(0, parseInt(count, 10) || 0);
      productsRoot.querySelectorAll('[data-unit-checkout="' + unitId + '"]').forEach(function (btn) {
        btn.setAttribute('data-unit-count', String(safeCount));
        btn.textContent = 'Checkout (' + safeCount + ')';
        if (safeCount > 0) {
          btn.removeAttribute('disabled');
          btn.disabled = false;
        } else {
          btn.setAttribute('disabled', 'disabled');
          btn.disabled = true;
        }
      });

      var summaryEl = productsRoot.querySelector('[data-unit-modal-cart-summary="' + unitId + '"]');
      if (summaryEl) {
        var unitBtn = productsRoot.querySelector('[data-unit-checkout="' + unitId + '"]');
        var unitLabel = unitBtn ? (unitBtn.getAttribute('data-unit-name') || 'unit') : 'unit';
        summaryEl.textContent = safeCount + ' item' + (safeCount === 1 ? '' : 's') + ' in ' + unitLabel + ' cart';
      }
    }

    function showUnitFeedback(unitId, message, isError) {
      var box = productsRoot.querySelector('[data-unit-modal-feedback="' + unitId + '"]');
      if (!box) return;
      box.textContent = message;
      box.classList.remove('flash-info', 'flash-success', 'flash-error');
      box.classList.add(isError ? 'flash-error' : 'flash-success');
      box.removeAttribute('hidden');
    }

    // Clicking a business card opens that unit's product modal
    productsRoot.querySelectorAll('[data-unit-card]').forEach(function (card) {
      card.addEventListener('click', function () {
        var modalId = card.getAttribute('data-unit-modal-open');
        var browseBtn = card.querySelector('.unit-card-browse-btn') || card;
        openUnitModal(modalId, browseBtn);
      });
    });

    // Clicking "Browse products" or product "View details" opens the corresponding business unit modal
    productsRoot.querySelectorAll('[data-unit-modal-open]').forEach(function (btn) {
      if (btn.hasAttribute('data-unit-card')) return;
      btn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var modalId = btn.getAttribute('data-unit-modal-open');
        var focusProductId = btn.getAttribute('data-focus-product');
        openUnitModal(modalId, btn, focusProductId);
      });
    });

    // Checkout (n) button click handling inside unit product modals
    productsRoot.querySelectorAll('[data-unit-checkout]').forEach(function (btn) {
      btn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var unitId = parseInt(btn.getAttribute('data-unit-checkout') || '0', 10);
        var count = parseInt(btn.getAttribute('data-unit-count') || '0', 10);
        if (!unitId || count <= 0 || btn.disabled) {
          return;
        }

        if (isCustomerSignedIn) {
          window.location.href = '/checkout/' + unitId;
          return;
        }

        var parentModal = btn.closest('dialog.modal');
        if (parentModal && parentModal.open) {
          parentModal._triggerBtn = null;
          parentModal.close();
        }

        var unitName = btn.getAttribute('data-unit-name') || 'this business unit';
        var cardBrowseBtn = productsRoot.querySelector('[data-unit-card="' + unitId + '"] .unit-card-browse-btn');
        openGuestSigninPrompt({
          mode: 'checkout',
          title: 'Sign in required for checkout',
          unitName: unitName,
          countText: count + ' item' + (count === 1 ? '' : 's'),
          signinHref: '/checkout/' + unitId,
          triggerEl: cardBrowseBtn || btn
        });
      });
    });

    // Add-to-Cart inside modals and product grid
    productsRoot.querySelectorAll('[data-unit-add-cart-form]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (form._submitting) return;

        var unitId = parseInt(form.getAttribute('data-unit-id') || '0', 10);
        var submitBtn = form.querySelector('button[type="submit"]');
        var qtyInput = form.querySelector('input[name="quantity"]');
        if (!unitId || !submitBtn || submitBtn.disabled) return;

        if (!isCustomerSignedIn) {
          var parentModal = form.closest('dialog.modal');
          if (parentModal && parentModal.open) {
            parentModal._triggerBtn = null;
            parentModal.close();
          }
          var cardBrowseBtn = productsRoot.querySelector('[data-unit-card="' + unitId + '"] .unit-card-browse-btn');
          openGuestSigninPrompt({
            mode: 'add',
            title: 'Sign in required',
            productName: form.getAttribute('data-product-name') || 'this product',
            unitName: form.getAttribute('data-unit-name') || 'business unit',
            signinHref: '/login',
            triggerEl: cardBrowseBtn || submitBtn
          });
          return;
        }

        var qtyVal = qtyInput ? parseInt(qtyInput.value || '1', 10) : 1;
        if (!qtyVal || qtyVal < 1 || qtyVal > 99) {
          showUnitFeedback(unitId, 'Enter a quantity between 1 and 99.', true);
          if (qtyInput && qtyInput.type !== 'hidden') qtyInput.focus();
          return;
        }

        form._submitting = true;
        submitBtn.disabled = true;

        var body = new URLSearchParams(new FormData(form));
        fetch(form.getAttribute('action'), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        })
          .then(function (resp) {
            return resp.json().catch(function () {
              return { ok: false, error: 'Unable to update cart right now. Please try again.' };
            });
          })
          .then(function (data) {
            form._submitting = false;
            submitBtn.disabled = false;

            if (data && typeof data.csrf_token === 'string' && data.csrf_token !== '') {
              productsRoot.querySelectorAll('input[name="csrf_token"]').forEach(function (inp) {
                inp.value = data.csrf_token;
              });
            }

            if (data && data.ok) {
              var nextCount = typeof data.count === 'number' ? data.count : 0;
              updateUnitCheckoutControls(unitId, nextCount);
              var prodName = form.getAttribute('data-product-name') || 'Item';
              showUnitFeedback(unitId, 'Added ' + prodName + ' to your cart.', false);
              if (qtyInput && qtyInput.type !== 'hidden') qtyInput.value = '1';
              return;
            }

            var errMsg = (data && data.error) ? data.error : 'Unable to add item to cart.';
            showUnitFeedback(unitId, errMsg, true);
          })
          .catch(function () {
            form._submitting = false;
            submitBtn.disabled = false;
            showUnitFeedback(unitId, 'Network error while updating your cart. Please try again.', true);
          });
      });
    });
  }

  // ---- Homepage: How PawCariño Works Scrolling Slideshow Walkthrough
  (function initHowItWorksWalkthrough() {
    var root = document.querySelector('[data-how-walkthrough]');
    if (!root) return;

    var scrollContainer = root.querySelector('[data-how-scroll]');
    var progressEl = root.querySelector('[data-how-progress]');
    var stepButtons = Array.prototype.slice.call(root.querySelectorAll('[data-how-step]'));
    var headlines = Array.prototype.slice.call(root.querySelectorAll('[data-how-headline]'));
    var scenes = Array.prototype.slice.call(root.querySelectorAll('[data-how-scene]'));
    var counterEl = root.querySelector('[data-how-counter]');
    var ctaEl = root.querySelector('.home-how-cta');
    var totalSteps = scenes.length;
    if (!scrollContainer || totalSteps !== 5 || headlines.length !== 5 || stepButtons.length !== 5) return;

    var AUTO_ADVANCE_IDLE_MS = 4000;
    var SLIDE_TWEEN_DURATION_MS = 640;
    var SNAP_DURATION_MS = 340;
    var SCROLL_SETTLE_DELAY_MS = 210;
    var SMOOTH_DAMPING = 11.5;
    var SCENE_TRAVEL_PCT = 105;
    var HEADLINE_TRAVEL_PCT = 48;
    var EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
    var REVEAL_EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';
    var supportsWAAPI = typeof Element !== 'undefined' && typeof Element.prototype.animate === 'function';

    var motionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var isReduced = motionQuery ? motionQuery.matches : reducedMotion;
    var activeIndex = 0;
    var lastPlayedSceneIdx = -1;
    var renderedProgress = 0;
    var targetProgress = 0;
    var runToken = 0;
    var stepTimers = [];
    var activeAnims = [];
    var idleTimerId = null;
    var scrollSettleTimerId = null;
    var smoothRafId = null;
    var smoothLastTs = 0;
    var tweenRafId = null;
    var isProgrammaticTween = false;
    var isAutoTween = false;
    var isUserScrolling = false;
    var isSyncingScroll = false;
    var syncClearTimerId = null;
    var lastInputWasKeyboard = false;
    var isKeyboardFocused = false;
    var isCtaHovered = false;
    var lastPointerX = null;
    var lastPointerY = null;
    var lastPointerMoveMark = 0;

    function checkInViewport() {
      var rect = root.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight || 800;
      if (rect.height <= 0) return false;
      var visiblePx = Math.min(rect.bottom, vh) - Math.max(rect.top, 0);
      return visiblePx >= Math.min(rect.height * 0.12, 80);
    }

    var isSectionInView = checkInViewport();

    function getMaxScroll() {
      if (window.innerWidth <= 900) return 0;
      return Math.max(0, scrollContainer.scrollHeight - scrollContainer.clientHeight);
    }

    function setScrollTopSilent(topPx) {
      var maxScroll = getMaxScroll();
      if (maxScroll <= 0) return;
      var clamped = Math.max(0, Math.min(maxScroll, topPx));
      if (Math.abs(scrollContainer.scrollTop - clamped) < 0.5) return;
      isSyncingScroll = true;
      scrollContainer.scrollTop = clamped;
      if (syncClearTimerId !== null) clearTimeout(syncClearTimerId);
      syncClearTimerId = setTimeout(function () {
        syncClearTimerId = null;
        isSyncingScroll = false;
      }, 60);
    }

    function clearScrollSettleTimer() {
      if (scrollSettleTimerId !== null) {
        clearTimeout(scrollSettleTimerId);
        scrollSettleTimerId = null;
      }
    }

    function stopSmoothLoop() {
      if (smoothRafId !== null) {
        cancelAnimationFrame(smoothRafId);
        smoothRafId = null;
      }
      smoothLastTs = 0;
    }

    function cancelProgrammaticTween() {
      if (tweenRafId !== null) {
        cancelAnimationFrame(tweenRafId);
        tweenRafId = null;
      }
      isProgrammaticTween = false;
      isAutoTween = false;
    }

    function clearStepTimers() {
      for (var i = 0; i < stepTimers.length; i++) {
        clearTimeout(stepTimers[i]);
      }
      stepTimers = [];
    }

    function scheduleStep(token, fn, delay) {
      var id = setTimeout(function () {
        if (token !== runToken) return;
        fn();
      }, delay);
      stepTimers.push(id);
      return id;
    }

    function cancelActiveAnims() {
      for (var i = 0; i < activeAnims.length; i++) {
        try {
          activeAnims[i].cancel();
        } catch (err) {
          // ignore already-finished animations
        }
      }
      activeAnims = [];
    }

    function runAnim(el, keyframes, options, onDone) {
      if (!el || isReduced || !supportsWAAPI) {
        if (typeof onDone === 'function') onDone();
        return null;
      }
      var anim = el.animate(keyframes, options);
      activeAnims.push(anim);
      anim.onfinish = function () {
        var idx = activeAnims.indexOf(anim);
        if (idx !== -1) activeAnims.splice(idx, 1);
        if (typeof onDone === 'function') onDone();
        try {
          anim.cancel();
        } catch (err) {
          // ignore
        }
      };
      return anim;
    }

    function clearIdleTimer() {
      if (idleTimerId !== null) {
        clearTimeout(idleTimerId);
        idleTimerId = null;
      }
    }

    function canRunAutoAdvance() {
      return !isReduced &&
        isSectionInView &&
        !document.hidden &&
        !isKeyboardFocused &&
        !isCtaHovered &&
        !isProgrammaticTween &&
        !isUserScrolling;
    }

    function syncStepIndicators(idx, skipProgress) {
      for (var i = 0; i < stepButtons.length; i++) {
        var active = i === idx;
        var done = i < idx;
        stepButtons[i].classList.toggle('is-active', active);
        stepButtons[i].classList.toggle('is-done', done);
        stepButtons[i].setAttribute('aria-pressed', active ? 'true' : 'false');
        if (active) {
          stepButtons[i].setAttribute('aria-current', 'step');
        } else {
          stepButtons[i].removeAttribute('aria-current');
        }
      }
      if (progressEl && !skipProgress) {
        progressEl.style.transform = 'scaleX(' + ((idx + 1) / totalSteps).toFixed(4) + ')';
      }
      if (counterEl) {
        var nextLabel = 'Step ' + (idx + 1) + ' of ' + totalSteps;
        if (isReduced) {
          counterEl.textContent = nextLabel;
        } else {
          animateTextSwap(counterEl, nextLabel);
        }
      }
    }

    function setSubview(sceneEl, viewName, animate) {
      var views = Array.prototype.slice.call(sceneEl.querySelectorAll('[data-how-view]'));
      var prevView = null;
      var nextView = null;

      for (var i = 0; i < views.length; i++) {
        if (views[i].classList.contains('is-visible')) prevView = views[i];
        if (views[i].getAttribute('data-how-view') === viewName) nextView = views[i];
        views[i].classList.remove('is-exiting');
      }

      if (!nextView) return;
      if (!animate || isReduced || !supportsWAAPI || prevView === nextView) {
        for (var j = 0; j < views.length; j++) {
          views[j].classList.toggle('is-visible', views[j] === nextView);
        }
        return;
      }

      if (prevView) {
        prevView.classList.remove('is-visible');
        prevView.classList.add('is-exiting');
        runAnim(
          prevView,
          [
            { opacity: 1, transform: 'translate3d(0, 0, 0) scale(1)' },
            { opacity: 0, transform: 'translate3d(0, -10px, 0) scale(0.975)' }
          ],
          { duration: 180, easing: EASE_OUT, fill: 'forwards' },
          function () {
            prevView.classList.remove('is-exiting');
          }
        );
      }

      nextView.classList.add('is-visible');
      runAnim(
        nextView,
        [
          { opacity: 0, transform: 'translate3d(0, 20px, 0)' },
          { opacity: 1, transform: 'translate3d(0, 0, 0)' }
        ],
        { duration: 360, delay: 90, easing: REVEAL_EASE, fill: 'both' }
      );
    }

    function hideCursor(sceneEl) {
      var cursor = sceneEl.querySelector('[data-how-cursor]');
      if (!cursor) return;
      cursor.classList.remove('is-visible', 'is-clicking');
    }

    function placeCursorStart(sceneEl) {
      var frame = sceneEl.querySelector('[data-how-frame]');
      var cursor = sceneEl.querySelector('[data-how-cursor]');
      if (!frame || !cursor) return;
      var rect = frame.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      var x = rect.width * 0.78;
      var y = rect.height * 0.74;
      cursor.style.transition = 'none';
      cursor.style.transform = 'translate3d(' + x.toFixed(1) + 'px, ' + y.toFixed(1) + 'px, 0)';
      cursor.getBoundingClientRect();
      cursor.style.transition = '';
    }

    function moveCursorTo(sceneEl, targetKey) {
      var frame = sceneEl.querySelector('[data-how-frame]');
      var cursor = sceneEl.querySelector('[data-how-cursor]');
      var target = sceneEl.querySelector('[data-how-target="' + targetKey + '"]');
      if (!frame || !cursor || !target) return;
      var frameRect = frame.getBoundingClientRect();
      var targetRect = target.getBoundingClientRect();
      if (!frameRect.width || !targetRect.width) return;
      var x = (targetRect.left - frameRect.left) + (targetRect.width * 0.52);
      var y = (targetRect.top - frameRect.top) + (targetRect.height * 0.56);
      cursor.style.transform = 'translate3d(' + x.toFixed(1) + 'px, ' + y.toFixed(1) + 'px, 0)';
      cursor.classList.add('is-visible');
    }

    function clickTarget(token, sceneEl, targetKey, onDone) {
      var cursor = sceneEl.querySelector('[data-how-cursor]');
      var target = sceneEl.querySelector('[data-how-target="' + targetKey + '"]');
      if (cursor) cursor.classList.add('is-clicking');
      if (target) target.classList.add('is-pressed');
      scheduleStep(token, function () {
        if (cursor) cursor.classList.remove('is-clicking');
        if (target) target.classList.remove('is-pressed');
        if (typeof onDone === 'function') onDone();
      }, 150);
    }

    function resetScene(idx) {
      var sceneEl = scenes[idx];
      if (!sceneEl) return;
      hideCursor(sceneEl);

      sceneEl.querySelectorAll('.is-selected, .is-pressed, .is-added, .is-updated, .is-exiting').forEach(function (el) {
        el.classList.remove('is-selected', 'is-pressed', 'is-added', 'is-updated', 'is-exiting');
      });

      if (idx === 0) {
        setSubview(sceneEl, 'auth-email', false);
      } else if (idx === 1) {
        setSubview(sceneEl, 'pets-list', false);
        var weightEl = sceneEl.querySelector('[data-how-pet-weight]');
        if (weightEl) weightEl.textContent = '26.5';
      } else if (idx === 2) {
        setSubview(sceneEl, 'catalog', false);
        var cartCount = sceneEl.querySelector('[data-how-cart-count]');
        if (cartCount) cartCount.textContent = '0';
      } else if (idx === 3) {
        setSubview(sceneEl, 'menu', false);
      } else if (idx === 4) {
        setSubview(sceneEl, 'builder', false);
        var gCount = sceneEl.querySelector('[data-how-groom-count]');
        var gTotal = sceneEl.querySelector('[data-how-groom-total]');
        if (gCount) gCount.textContent = '0 services';
        if (gTotal) gTotal.textContent = '$0.00';
      }
    }

    function cleanupInactiveScenes(keepIdx) {
      for (var i = 0; i < totalSteps; i++) {
        if (i !== keepIdx) {
          headlines[i].classList.remove('is-active', 'is-exiting');
          headlines[i].setAttribute('aria-hidden', 'true');
          scenes[i].classList.remove('is-active', 'is-exiting');
          resetScene(i);
        }
      }
    }

    // Horizontal scrolling slideshow:
    // Scroll down (p increases): outgoing moves LEFT (offset 0 -> -1), incoming enters from RIGHT (offset +1 -> 0)
    // Scroll up   (p decreases): outgoing moves RIGHT (offset 0 -> +1), incoming enters from LEFT (offset -1 -> 0)
    function applySlideFrame(i, offset) {
      var absOff = Math.abs(offset);
      var scene = scenes[i];
      var head = headlines[i];

      if (absOff >= 0.999) {
        scene.style.opacity = '0';
        scene.style.visibility = 'hidden';
        scene.style.transform = 'translate3d(' + (offset > 0 ? SCENE_TRAVEL_PCT : -SCENE_TRAVEL_PCT) + '%, 0, 0) scale(0.96)';
        scene.classList.remove('is-active', 'is-exiting');

        head.style.opacity = '0';
        head.style.visibility = 'hidden';
        head.style.transform = 'translate3d(' + (offset > 0 ? HEADLINE_TRAVEL_PCT : -HEADLINE_TRAVEL_PCT) + '%, 0, 0)';
        head.classList.remove('is-active', 'is-exiting');
        head.setAttribute('aria-hidden', 'true');
        return;
      }

      var smooth = 1 - (absOff * absOff * (3 - 2 * absOff));
      var sceneX = offset * SCENE_TRAVEL_PCT;
      var sceneScale = 0.96 + (0.04 * smooth);
      var sceneOpacity = Math.pow(smooth, 1.15);

      scene.style.visibility = 'visible';
      scene.style.opacity = sceneOpacity.toFixed(4);
      scene.style.transform = 'translate3d(' + sceneX.toFixed(2) + '%, 0, 0) scale(' + sceneScale.toFixed(4) + ')';
      scene.classList.toggle('is-active', absOff < 0.5);

      var headX = offset * HEADLINE_TRAVEL_PCT;
      var headOpacity = Math.pow(smooth, 1.35);
      head.style.visibility = 'visible';
      head.style.opacity = headOpacity.toFixed(4);
      head.style.transform = 'translate3d(' + headX.toFixed(2) + '%, 0, 0)';
      if (absOff < 0.5) {
        head.classList.add('is-active');
        head.removeAttribute('aria-hidden');
      } else {
        head.classList.remove('is-active');
        head.setAttribute('aria-hidden', 'true');
      }
    }

    function renderSlideshow(p) {
      var clamped = Math.max(0, Math.min(totalSteps - 1, p));
      for (var i = 0; i < totalSteps; i++) {
        applySlideFrame(i, i - clamped);
      }
      if (progressEl) {
        progressEl.style.transform = 'scaleX(' + ((clamped + 1) / totalSteps).toFixed(4) + ')';
      }
      var nearest = Math.max(0, Math.min(totalSteps - 1, Math.round(clamped)));
      if (nearest !== activeIndex) {
        activeIndex = nearest;
        syncStepIndicators(activeIndex, true);
      }
    }

    function renderDirectPair(fromIdx, toIdx, easedT, dir) {
      for (var i = 0; i < totalSteps; i++) {
        if (i === fromIdx) {
          applySlideFrame(i, -dir * easedT);
        } else if (i === toIdx) {
          applySlideFrame(i, dir * (1 - easedT));
        } else {
          applySlideFrame(i, 2);
        }
      }
      if (progressEl) {
        var fromScale = (fromIdx + 1) / totalSteps;
        var toScale = (toIdx + 1) / totalSteps;
        var curScale = fromScale + (toScale - fromScale) * easedT;
        progressEl.style.transform = 'scaleX(' + curScale.toFixed(4) + ')';
      }
      var visualIdx = easedT >= 0.5 ? toIdx : fromIdx;
      if (visualIdx !== activeIndex) {
        activeIndex = visualIdx;
        syncStepIndicators(activeIndex, true);
      }
    }

    function showStaticState(idx) {
      runToken++;
      clearStepTimers();
      clearScrollSettleTimer();
      stopSmoothLoop();
      cancelProgrammaticTween();
      cancelActiveAnims();
      isUserScrolling = false;
      var targetIdx = typeof idx === 'number' ? idx : activeIndex;
      targetIdx = Math.max(0, Math.min(totalSteps - 1, targetIdx));
      activeIndex = targetIdx;
      renderedProgress = targetIdx;
      targetProgress = targetIdx;
      lastPlayedSceneIdx = -1;
      syncStepIndicators(targetIdx, false);
      for (var i = 0; i < totalSteps; i++) {
        resetScene(i);
        headlines[i].style.opacity = '';
        headlines[i].style.visibility = '';
        headlines[i].style.transform = '';
        scenes[i].style.opacity = '';
        scenes[i].style.visibility = '';
        scenes[i].style.transform = '';
        headlines[i].classList.remove('is-exiting');
        scenes[i].classList.remove('is-exiting');
        if (i === targetIdx) {
          headlines[i].classList.add('is-active');
          headlines[i].removeAttribute('aria-hidden');
          scenes[i].classList.add('is-active');
        } else {
          headlines[i].classList.remove('is-active');
          headlines[i].setAttribute('aria-hidden', 'true');
          scenes[i].classList.remove('is-active');
        }
      }
      var maxScroll = getMaxScroll();
      if (maxScroll > 0) {
        setScrollTopSilent((targetIdx / (totalSteps - 1)) * maxScroll);
      }
    }

    function runScene(idx) {
      var token = ++runToken;
      clearStepTimers();
      lastPlayedSceneIdx = idx;
      if (isReduced || !isSectionInView || document.hidden) return;

      var sceneEl = scenes[idx];
      resetScene(idx);
      placeCursorStart(sceneEl);

      if (idx === 0) {
        // Step 1: Get Started — Email highlight -> Continue -> Password -> Account Active
        scheduleStep(token, function () {
          var emailInput = sceneEl.querySelector('[data-how-auth-email]');
          if (emailInput) emailInput.classList.add('is-updated');
          moveCursorTo(sceneEl, 'auth-continue');
        }, 300);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'auth-continue', function () {
            setSubview(sceneEl, 'auth-password', true);
          });
        }, 900);

        scheduleStep(token, function () {
          var passInput = sceneEl.querySelector('[data-how-auth-pass]');
          if (passInput) passInput.classList.add('is-updated');
          moveCursorTo(sceneEl, 'auth-signin');
        }, 1680);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'auth-signin', function () {
            setSubview(sceneEl, 'done', true);
            hideCursor(sceneEl);
          });
        }, 2380);
      } else if (idx === 1) {
        // Step 2: Your Pets' Information — My Pets -> Edit Profile -> Saved
        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'pet-edit');
        }, 300);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'pet-edit', function () {
            var petCard = sceneEl.querySelector('[data-how-pet="1"]');
            if (petCard) petCard.classList.add('is-selected');
          });
        }, 900);

        scheduleStep(token, function () {
          setSubview(sceneEl, 'pets-edit', true);
        }, 1240);

        scheduleStep(token, function () {
          var weightEl = sceneEl.querySelector('[data-how-pet-weight]');
          if (weightEl) {
            weightEl.textContent = '27.0';
            weightEl.classList.add('is-updated');
          }
          moveCursorTo(sceneEl, 'pet-save');
        }, 1820);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'pet-save', function () {
            setSubview(sceneEl, 'done', true);
            hideCursor(sceneEl);
          });
        }, 2520);
      } else if (idx === 2) {
        // Step 3: Shop for Your Pets — Browse -> Add to Cart -> Checkout -> Order Confirmed
        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'store-add');
        }, 280);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'store-add', function () {
            var prodCard = sceneEl.querySelector('[data-how-product="1"]');
            var addBtn = sceneEl.querySelector('[data-how-target="store-add"]');
            var cartPill = sceneEl.querySelector('[data-how-target="store-cart"]');
            var cartCount = sceneEl.querySelector('[data-how-cart-count]');
            if (prodCard) prodCard.classList.add('is-selected');
            if (addBtn) addBtn.classList.add('is-added');
            if (cartPill) cartPill.classList.add('is-updated');
            if (cartCount) cartCount.textContent = '1';
          });
        }, 840);

        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'store-cart');
        }, 1340);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'store-cart', function () {
            setSubview(sceneEl, 'checkout', true);
          });
        }, 1860);

        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'store-place');
        }, 2380);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'store-place', function () {
            setSubview(sceneEl, 'done', true);
            hideCursor(sceneEl);
          });
        }, 2960);
      } else if (idx === 3) {
        // Step 4: Veterinary Care — Service -> Date & Time -> Request Submitted
        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'vet-service');
        }, 280);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'vet-service', function () {
            var svc = sceneEl.querySelector('[data-how-target="vet-service"]');
            if (svc) svc.classList.add('is-selected');
          });
        }, 800);

        scheduleStep(token, function () {
          setSubview(sceneEl, 'schedule', true);
        }, 1120);

        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'vet-date');
        }, 1480);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'vet-date', function () {
            var d = sceneEl.querySelector('[data-how-target="vet-date"]');
            if (d) d.classList.add('is-selected');
          });
        }, 1900);

        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'vet-time');
        }, 2120);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'vet-time', function () {
            var t = sceneEl.querySelector('[data-how-target="vet-time"]');
            if (t) t.classList.add('is-selected');
          });
        }, 2540);

        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'vet-submit');
        }, 2760);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'vet-submit', function () {
            setSubview(sceneEl, 'done', true);
            hideCursor(sceneEl);
          });
        }, 3220);
      } else {
        // Step 5: Grooming Services — Multi-Service + Slot -> Request Sent
        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'groom-svc-1');
        }, 280);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'groom-svc-1', function () {
            var s1 = sceneEl.querySelector('[data-how-target="groom-svc-1"]');
            var gCount = sceneEl.querySelector('[data-how-groom-count]');
            var gTotal = sceneEl.querySelector('[data-how-groom-total]');
            if (s1) s1.classList.add('is-selected');
            if (gCount) gCount.textContent = '1 service';
            if (gTotal) gTotal.textContent = '$55.00';
          });
        }, 800);

        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'groom-svc-2');
        }, 1140);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'groom-svc-2', function () {
            var s2 = sceneEl.querySelector('[data-how-target="groom-svc-2"]');
            var gCount = sceneEl.querySelector('[data-how-groom-count]');
            var gTotal = sceneEl.querySelector('[data-how-groom-total]');
            if (s2) s2.classList.add('is-selected');
            if (gCount) gCount.textContent = '2 services';
            if (gTotal) gTotal.textContent = '$63.00';
          });
        }, 1640);

        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'groom-slot');
        }, 1960);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'groom-slot', function () {
            var slot = sceneEl.querySelector('[data-how-target="groom-slot"]');
            if (slot) slot.classList.add('is-selected');
          });
        }, 2420);

        scheduleStep(token, function () {
          moveCursorTo(sceneEl, 'groom-submit');
        }, 2680);

        scheduleStep(token, function () {
          clickTarget(token, sceneEl, 'groom-submit', function () {
            setSubview(sceneEl, 'done', true);
            hideCursor(sceneEl);
          });
        }, 3180);
      }
    }

    function easeInOutCubic(t) {
      return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }

    function easeOutCubic(t) {
      return 1 - Math.pow(1 - t, 3);
    }

    function scheduleIdleAutoAdvance() {
      clearIdleTimer();
      if (!canRunAutoAdvance()) return;
      idleTimerId = setTimeout(function () {
        idleTimerId = null;
        if (!canRunAutoAdvance()) return;
        var nextIdx = (activeIndex + 1) % totalSteps;
        transitionToStep(nextIdx, true);
      }, AUTO_ADVANCE_IDLE_MS);
    }

    function resetIdleCountdown() {
      clearIdleTimer();
      scheduleIdleAutoAdvance();
    }

    function finishStepLanding(targetIdx) {
      activeIndex = targetIdx;
      renderedProgress = targetIdx;
      targetProgress = targetIdx;
      isProgrammaticTween = false;
      isAutoTween = false;
      renderSlideshow(targetIdx);
      syncStepIndicators(targetIdx, false);
      var maxScroll = getMaxScroll();
      if (maxScroll > 0) {
        setScrollTopSilent((targetIdx / (totalSteps - 1)) * maxScroll);
      }
      runScene(targetIdx);
      scheduleIdleAutoAdvance();
    }

    function transitionToStep(targetIdx, fromAuto) {
      if (targetIdx < 0 || targetIdx >= totalSteps) return;
      clearIdleTimer();
      clearScrollSettleTimer();
      stopSmoothLoop();
      cancelProgrammaticTween();

      if (isReduced) {
        showStaticState(targetIdx);
        return;
      }

      var fromIdx = activeIndex;
      if (fromIdx === targetIdx && Math.abs(renderedProgress - targetIdx) < 0.01) {
        runScene(targetIdx);
        scheduleIdleAutoAdvance();
        return;
      }

      runToken++;
      clearStepTimers();
      cancelActiveAnims();
      resetScene(targetIdx);

      var dir = (fromIdx === totalSteps - 1 && targetIdx === 0)
        ? 1
        : (targetIdx >= fromIdx ? 1 : -1);
      var maxScroll = getMaxScroll();
      var startScroll = scrollContainer.scrollTop;
      var endScroll = maxScroll > 0 ? (targetIdx / (totalSteps - 1)) * maxScroll : 0;
      var startTime = performance.now();

      isProgrammaticTween = true;
      isAutoTween = Boolean(fromAuto);

      function stepTween(now) {
        var raw = Math.min(1, (now - startTime) / SLIDE_TWEEN_DURATION_MS);
        var eased = easeInOutCubic(raw);

        renderDirectPair(fromIdx, targetIdx, eased, dir);
        if (maxScroll > 0 && !(fromIdx === totalSteps - 1 && targetIdx === 0)) {
          setScrollTopSilent(startScroll + (endScroll - startScroll) * eased);
        }

        if (raw < 1) {
          tweenRafId = requestAnimationFrame(stepTween);
        } else {
          tweenRafId = null;
          finishStepLanding(targetIdx);
        }
      }

      tweenRafId = requestAnimationFrame(stepTween);
    }

    function snapScrollToNearestStep() {
      var snappedIdx = Math.max(0, Math.min(totalSteps - 1, Math.round(targetProgress)));
      var maxScroll = getMaxScroll();

      if (isReduced || maxScroll <= 0) {
        isUserScrolling = false;
        if (isReduced) {
          showStaticState(snappedIdx);
        } else {
          finishStepLanding(snappedIdx);
        }
        return;
      }

      var startScroll = scrollContainer.scrollTop;
      var endScroll = (snappedIdx / (totalSteps - 1)) * maxScroll;

      if (Math.abs(endScroll - startScroll) < 2 && Math.abs(renderedProgress - snappedIdx) < 0.015) {
        isUserScrolling = false;
        targetProgress = snappedIdx;
        renderedProgress = snappedIdx;
        renderSlideshow(snappedIdx);
        if (lastPlayedSceneIdx !== snappedIdx) {
          runScene(snappedIdx);
        }
        scheduleIdleAutoAdvance();
        return;
      }

      stopSmoothLoop();
      cancelProgrammaticTween();
      isProgrammaticTween = true;
      isAutoTween = false;
      var startTime = performance.now();
      var startP = renderedProgress;

      function snapFrame(now) {
        var raw = Math.min(1, (now - startTime) / SNAP_DURATION_MS);
        var eased = easeOutCubic(raw);
        var p = startP + (snappedIdx - startP) * eased;
        renderedProgress = p;
        targetProgress = p;
        renderSlideshow(p);
        setScrollTopSilent(startScroll + (endScroll - startScroll) * eased);

        if (raw < 1) {
          tweenRafId = requestAnimationFrame(snapFrame);
        } else {
          tweenRafId = null;
          isUserScrolling = false;
          isProgrammaticTween = false;
          renderedProgress = snappedIdx;
          targetProgress = snappedIdx;
          renderSlideshow(snappedIdx);
          syncStepIndicators(snappedIdx, false);
          if (lastPlayedSceneIdx !== snappedIdx) {
            runScene(snappedIdx);
          }
          scheduleIdleAutoAdvance();
        }
      }

      tweenRafId = requestAnimationFrame(snapFrame);
    }

    function ensureSmoothLoop() {
      if (smoothRafId !== null) return;
      smoothLastTs = performance.now();

      function tick(now) {
        var dt = Math.min(0.05, Math.max(0.001, (now - smoothLastTs) / 1000));
        smoothLastTs = now;
        var alpha = 1 - Math.exp(-dt * SMOOTH_DAMPING);
        var diff = targetProgress - renderedProgress;

        if (Math.abs(diff) < 0.0008) {
          renderedProgress = targetProgress;
          renderSlideshow(renderedProgress);
          smoothRafId = null;
          return;
        }

        renderedProgress += diff * alpha;
        renderSlideshow(renderedProgress);
        smoothRafId = requestAnimationFrame(tick);
      }

      smoothRafId = requestAnimationFrame(tick);
    }

    function onContainerScroll() {
      if (isSyncingScroll) return;
      var maxScroll = getMaxScroll();
      if (maxScroll <= 0) return;

      // Manual scroll immediately takes priority over any running automatic or snap transition
      cancelProgrammaticTween();
      clearIdleTimer();
      isUserScrolling = true;

      var nextProgress = (scrollContainer.scrollTop / maxScroll) * (totalSteps - 1);
      targetProgress = Math.max(0, Math.min(totalSteps - 1, nextProgress));

      if (isReduced) {
        var nearest = Math.max(0, Math.min(totalSteps - 1, Math.round(targetProgress)));
        if (nearest !== activeIndex) {
          showStaticState(nearest);
        }
      } else {
        ensureSmoothLoop();
      }

      clearScrollSettleTimer();
      scrollSettleTimerId = setTimeout(function () {
        scrollSettleTimerId = null;
        snapScrollToNearestStep();
      }, SCROLL_SETTLE_DELAY_MS);
    }

    function pauseWalkthrough() {
      runToken++;
      clearStepTimers();
      clearIdleTimer();
      clearScrollSettleTimer();
      stopSmoothLoop();
      cancelProgrammaticTween();
      cancelActiveAnims();
      isUserScrolling = false;
      lastPlayedSceneIdx = -1;
      renderedProgress = activeIndex;
      targetProgress = activeIndex;
      if (isReduced) {
        showStaticState(activeIndex);
      } else {
        renderSlideshow(activeIndex);
      }
    }

    // Track input modality so mouse clicks on step buttons do not trap keyboard focus state
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Tab' || e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') {
        lastInputWasKeyboard = true;
      }
    }, true);

    document.addEventListener('pointerdown', function () {
      lastInputWasKeyboard = false;
      if (isKeyboardFocused) {
        isKeyboardFocused = false;
        scheduleIdleAutoAdvance();
      }
    }, true);

    // Native desktop scroll drives the smooth scrolling slideshow; wheel instantly preempts any auto tween
    scrollContainer.addEventListener('wheel', function () {
      isSyncingScroll = false;
      if (syncClearTimerId !== null) {
        clearTimeout(syncClearTimerId);
        syncClearTimerId = null;
      }
      if (isProgrammaticTween) {
        cancelProgrammaticTween();
      }
      clearIdleTimer();
    }, { passive: true });

    scrollContainer.addEventListener('scroll', onContainerScroll, { passive: true });

    // Direct step clicks & keyboard navigation (Arrow keys / Home / End)
    stepButtons.forEach(function (btn, idx) {
      btn.addEventListener('click', function () {
        lastInputWasKeyboard = false;
        isKeyboardFocused = false;
        transitionToStep(idx, false);
      });

      btn.addEventListener('keydown', function (e) {
        var next = null;
        if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
          next = (idx + 1) % totalSteps;
        } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
          next = (idx + totalSteps - 1) % totalSteps;
        } else if (e.key === 'Home') {
          next = 0;
        } else if (e.key === 'End') {
          next = totalSteps - 1;
        }
        if (next !== null) {
          e.preventDefault();
          lastInputWasKeyboard = true;
          isKeyboardFocused = true;
          stepButtons[next].focus();
          transitionToStep(next, false);
        }
      });
    });

    // Real pointer movement (>= 4px) resets the 4-second inactivity countdown; stationary cursor stays idle
    root.addEventListener('pointermove', function (e) {
      if (lastPointerX !== null && lastPointerY !== null) {
        var dx = Math.abs(e.clientX - lastPointerX);
        var dy = Math.abs(e.clientY - lastPointerY);
        if (dx < 4 && dy < 4) return;
      }
      lastPointerX = e.clientX;
      lastPointerY = e.clientY;
      var now = Date.now();
      if (now - lastPointerMoveMark < 220) return;
      lastPointerMoveMark = now;
      if (!isProgrammaticTween && !isUserScrolling) {
        resetIdleCountdown();
      }
    }, { passive: true });

    root.addEventListener('touchstart', function () {
      if (!isProgrammaticTween) {
        resetIdleCountdown();
      }
    }, { passive: true });

    // Pause auto-advance while hovering directly over the Get Started CTA
    if (ctaEl) {
      ctaEl.addEventListener('mouseenter', function () {
        isCtaHovered = true;
        clearIdleTimer();
      });
      ctaEl.addEventListener('mouseleave', function () {
        isCtaHovered = false;
        scheduleIdleAutoAdvance();
      });
    }

    // Pause while genuine keyboard focus is inside the walkthrough
    root.addEventListener('focusin', function (e) {
      var isKb = lastInputWasKeyboard;
      if (!isKb && e.target && typeof e.target.matches === 'function') {
        try {
          isKb = e.target.matches(':focus-visible');
        } catch (err) {
          isKb = false;
        }
      }
      if (isKb) {
        isKeyboardFocused = true;
        clearIdleTimer();
      }
    });

    root.addEventListener('focusout', function (e) {
      if (!root.contains(e.relatedTarget) && isKeyboardFocused) {
        isKeyboardFocused = false;
        scheduleIdleAutoAdvance();
      }
    });

    // Pause when section leaves viewport; resume + start 4s idle countdown when visible
    function handleVisibilityState(inView) {
      if (inView === isSectionInView) return;
      isSectionInView = inView;
      if (isSectionInView) {
        if (!isReduced && !document.hidden && !isProgrammaticTween && !isUserScrolling) {
          renderSlideshow(activeIndex);
          runScene(activeIndex);
          scheduleIdleAutoAdvance();
        }
      } else {
        pauseWalkthrough();
      }
    }

    if (typeof IntersectionObserver === 'function') {
      var io = new IntersectionObserver(function (entries) {
        for (var i = 0; i < entries.length; i++) {
          if (entries[i].target === root) {
            handleVisibilityState(entries[i].isIntersecting && entries[i].intersectionRatio >= 0.08);
          }
        }
      }, { threshold: [0, 0.08, 0.25] });
      io.observe(root);
    }

    // Sync scroll position on viewport resize (desktop <-> mobile)
    window.addEventListener('resize', function () {
      var maxScroll = getMaxScroll();
      if (maxScroll > 0 && !isUserScrolling && !isProgrammaticTween) {
        setScrollTopSilent((activeIndex / (totalSteps - 1)) * maxScroll);
      }
    }, { passive: true });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        pauseWalkthrough();
      } else {
        isSectionInView = checkInViewport();
        if (isSectionInView && !isReduced && !isProgrammaticTween) {
          renderSlideshow(activeIndex);
          runScene(activeIndex);
          scheduleIdleAutoAdvance();
        }
      }
    });

    if (motionQuery && typeof motionQuery.addEventListener === 'function') {
      motionQuery.addEventListener('change', function (e) {
        isReduced = e.matches;
        if (isReduced) {
          pauseWalkthrough();
          showStaticState(activeIndex);
        } else if (isSectionInView && !document.hidden) {
          renderSlideshow(activeIndex);
          runScene(activeIndex);
          scheduleIdleAutoAdvance();
        }
      });
    }

    syncStepIndicators(0, false);
    if (isReduced) {
      showStaticState(0);
    } else {
      renderSlideshow(0);
      if (isSectionInView && !document.hidden) {
        runScene(0);
        scheduleIdleAutoAdvance();
      }
    }
  })();
})();
