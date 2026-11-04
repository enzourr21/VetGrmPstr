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
      btn.textContent = showing ? 'Show' : 'Hide';
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
    var regEmail = regForm.querySelector('#email');
    var regEmailError = regForm.querySelector('[data-reg-email-error]');
    var step1Actions = regForm.querySelector('[data-reg-step1-actions]');
    var unlock2Btn = regForm.querySelector('[data-reg-unlock-2]');
    var step1Badge = regForm.querySelector('[data-stage1-done]');

    var stage2 = regForm.querySelector('#reg-stage-2');
    var firstName = regForm.querySelector('#first_name');
    var lastName = regForm.querySelector('#last_name');
    var regNameError = regForm.querySelector('[data-reg-name-error]');
    var step2Actions = regForm.querySelector('[data-reg-step2-actions]');
    var unlock3Btn = regForm.querySelector('[data-reg-unlock-3]');
    var step2Badge = regForm.querySelector('[data-stage2-done]');

    var stage3 = regForm.querySelector('#reg-stage-3');
    var phoneInput = regForm.querySelector('#phone');
    var regPhoneError = regForm.querySelector('[data-reg-phone-error]');
    var step3Actions = regForm.querySelector('[data-reg-step3-actions]');
    var unlock4Btn = regForm.querySelector('[data-reg-unlock-4]');
    var step3Badge = regForm.querySelector('[data-stage3-done]');

    var stage4 = regForm.querySelector('#reg-stage-4');
    var pwInput = regForm.querySelector('#password');
    var pwConfirmInput = regForm.querySelector('#password_confirm');
    var ruleLength = regForm.querySelector('[data-pw-rule="length"]');
    var ruleMatch = regForm.querySelector('[data-pw-rule="match"]');
    var ruleMatchText = regForm.querySelector('[data-pw-match-text]');
    var submitBtn = regForm.querySelector('#reg-submit-btn');

    function proceedToStep2() {
      var val = (regEmail.value || '').trim();
      if (!emailPattern.test(val)) {
        showErrorSmooth(regEmailError, 'Enter a valid email address, for example name@example.com.');
        regEmail.focus();
        return false;
      }
      if (regEmailError) regEmailError.setAttribute('hidden', 'hidden');
      if (step1Actions) step1Actions.setAttribute('hidden', 'hidden');
      if (step1Badge) animateStageIn(step1Badge);
      animateStageIn(stage2, '#first_name');
      updateStepTracker(regCard, 2);
      return true;
    }

    function proceedToStep3() {
      var fn = (firstName.value || '').trim();
      var ln = (lastName.value || '').trim();
      if (fn.length < 1 || ln.length < 1) {
        showErrorSmooth(regNameError, 'Please enter both your first name and last name to continue.');
        (fn.length < 1 ? firstName : lastName).focus();
        return false;
      }
      if (regNameError) regNameError.setAttribute('hidden', 'hidden');
      if (step2Actions) step2Actions.setAttribute('hidden', 'hidden');
      if (step2Badge) animateStageIn(step2Badge);
      animateStageIn(stage3, '#phone');
      updateStepTracker(regCard, 3);
      return true;
    }

    function proceedToStep4() {
      var ph = (phoneInput.value || '').trim();
      if (ph !== '' && !phonePattern.test(ph)) {
        showErrorSmooth(regPhoneError, 'Please enter a valid phone number (6–30 digits) or leave optional.');
        phoneInput.focus();
        return false;
      }
      if (regPhoneError) regPhoneError.setAttribute('hidden', 'hidden');
      if (step3Actions) step3Actions.setAttribute('hidden', 'hidden');
      if (step3Badge) animateStageIn(step3Badge);
      animateStageIn(stage4, '#password');
      updateStepTracker(regCard, 4);
      evaluatePasswordState();
      return true;
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

    if (unlock2Btn) {
      unlock2Btn.addEventListener('click', proceedToStep2);
    }
    if (unlock3Btn) {
      unlock3Btn.addEventListener('click', proceedToStep3);
    }
    if (unlock4Btn) {
      unlock4Btn.addEventListener('click', proceedToStep4);
    }

    if (regEmail) {
      regEmail.addEventListener('input', function () {
        if (regEmailError && !regEmailError.hasAttribute('hidden')) {
          regEmailError.setAttribute('hidden', 'hidden');
        }
      });
      regEmail.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && stage2 && stage2.hasAttribute('hidden')) {
          ev.preventDefault();
          proceedToStep2();
        }
      });
    }

    [firstName, lastName].forEach(function (inp) {
      if (!inp) return;
      inp.addEventListener('input', function () {
        if (regNameError && !regNameError.hasAttribute('hidden')) {
          regNameError.setAttribute('hidden', 'hidden');
        }
      });
      inp.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && stage3 && stage3.hasAttribute('hidden')) {
          ev.preventDefault();
          proceedToStep3();
        }
      });
    });

    if (phoneInput) {
      phoneInput.addEventListener('input', function () {
        if (regPhoneError && !regPhoneError.hasAttribute('hidden')) {
          regPhoneError.setAttribute('hidden', 'hidden');
        }
      });
    }

    if (pwInput) pwInput.addEventListener('input', evaluatePasswordState);
    if (pwConfirmInput) pwConfirmInput.addEventListener('input', evaluatePasswordState);
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
    var radios = checkoutForm.querySelectorAll('input[name="fulfillment_method"]');
    var updateFulfillment = function () {
      var selected = checkoutForm.querySelector('input[name="fulfillment_method"]:checked');
      var isDelivery = selected && selected.value === 'delivery';
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
    var clinicalCards = form.querySelectorAll('[data-clinical-role="1"]');
    if (!unitSelect || !posSelect) return;

    function syncUnitOptions() {
      var selectedUnit = unitSelect.value;
      var optgroups = posSelect.querySelectorAll('optgroup[data-unit-group]');
      optgroups.forEach(function (og) {
        var groupUnit = og.getAttribute('data-unit-group');
        var match = !selectedUnit || groupUnit === selectedUnit;
        og.disabled = !match;
        og.hidden = !match;
      });
      var currentOpt = posSelect.options[posSelect.selectedIndex];
      if (currentOpt && currentOpt.parentElement && currentOpt.parentElement.tagName === 'OPTGROUP') {
        if (currentOpt.parentElement.disabled) {
          posSelect.value = '';
        }
      }
      clinicalCards.forEach(function (card) {
        var isVet = !selectedUnit || selectedUnit === '2';
        card.style.opacity = isVet ? '1' : '0.45';
        var cb = card.querySelector('input[type="checkbox"]');
        if (cb && !isVet) {
          cb.checked = false;
        }
      });
    }

    unitSelect.addEventListener('change', syncUnitOptions);
    syncUnitOptions();
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

  // ---- Native <dialog> modals (roster Show All, role picker) -----------
  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var dlgId = btn.getAttribute('data-modal-open');
      var dlg = document.getElementById(dlgId);
      if (dlg && typeof dlg.showModal === 'function') {
        dlg._triggerBtn = btn;
        document.body.classList.add('has-modal-open');
        dlg.showModal();
        var closeBtn = dlg.querySelector('[data-modal-close]');
        if (closeBtn && typeof closeBtn.focus === 'function') {
          setTimeout(function () { closeBtn.focus(); }, 40);
        }
      }
    });
  });

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
    if (!addBtn || !dlg || !chips) return;

    function selected() {
      return Array.prototype.map.call(chips.querySelectorAll('[data-role-chip]'), function (c) {
        return c.getAttribute('data-role-chip');
      });
    }
    function sync() {
      var sel = selected();
      dlg.querySelectorAll('[data-role-option]').forEach(function (o) {
        o.disabled = sel.indexOf(o.getAttribute('data-role-option')) !== -1;
      });
      if (emptyHint) emptyHint.hidden = sel.length > 0;
    }
    function addChip(name) {
      if (selected().indexOf(name) !== -1) return;
      var chip = document.createElement('span');
      chip.className = 'role-chip';
      chip.setAttribute('data-role-chip', name);
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'roles[]';
      input.value = name;
      var label = document.createElement('span');
      label.textContent = name;
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'role-chip-remove';
      rm.setAttribute('data-role-remove', '');
      rm.setAttribute('aria-label', 'Remove role ' + name);
      rm.innerHTML = '&times;';
      chip.appendChild(input);
      chip.appendChild(label);
      chip.appendChild(rm);
      chips.appendChild(chip);
    }

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
        addChip(o.getAttribute('data-role-option'));
        sync();
        dlg.close();
      });
    });
    chips.addEventListener('click', function (ev) {
      var rm = ev.target.closest('[data-role-remove]');
      if (!rm) return;
      var chip = rm.closest('[data-role-chip]');
      if (chip) chip.remove();
      sync();
    });
    sync();
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
    if (card && !ev.target.closest('a, button, input, select, textarea, label, form, .badge, code')) {
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
          if (linkPath === '/workspace' && (pathname.startsWith('/sysadmin') || pathname.startsWith('/manager') || pathname.startsWith('/super-admin') || pathname.startsWith('/workspace'))) {
            return true;
          }
          if (linkPath === '/' && pathname === '/') return true;
          if (linkPath !== '/' && pathname.startsWith(linkPath)) return true;
          return false;
        },
        activeClass: 'is-current'
      },
      {
        container: document.querySelector('.admin-subnav'),
        storageKey: 'vetgrpet_subnav_active_rect',
        isCurrent: function (link, pathname) {
          if (link.classList.contains('is-active')) return true;
          var linkPath = link.getAttribute('href');
          if (!linkPath || linkPath.startsWith('#')) return false;
          linkPath = linkPath.split('?')[0].replace(/\/+$/, '') || '/';
          var cleanCurrent = pathname.replace(/\/+$/, '') || '/';
          return linkPath === cleanCurrent;
        },
        activeClass: 'is-active'
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
    } catch (e) {}

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
        } catch (e) {}

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
          try { sessionStorage.removeItem(cfg.storageKey); } catch (e) {}
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
        } catch (e) {}
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
        } catch (e) {}

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

  // ---- Full-Height Living Woodland & Animated Pet World Engine ----------
  var sceneFigures = document.querySelectorAll('[data-scene-target]');
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

  if (sceneFigures.length > 0) {
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
    sceneFigures.forEach(function (fig, index) {
      var name = fig.getAttribute('data-animal') || ('animal-' + index);
      var parts = {};
      fig.querySelectorAll('[data-part]').forEach(function (p) {
        parts[p.getAttribute('data-part')] = p;
      });
      rigs[name] = {
        el: fig,
        parts: parts,
        phase: index * 1.37,
        // Independent blink state machine for each animal
        blinkStart: 0,
        blinkDuration: 165,
        doubleBlink: false,
        nextBlinkAt: performance.now() + 900 + index * 520 + Math.random() * 1800,
        // Independent behavior action state machine (panting, barking, meowing/purring, ear perks, chirping)
        actionType: 'idle',
        actionStart: 0,
        actionDuration: 0,
        nextActionAt: performance.now() + 700 + index * 480 + Math.random() * 1400,
        cycleCount: 0
      };
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

      // 4. CAT: LUNA (Silver Tabby — Meowing & Purring with Temporary "Meow~" & "Purr… zzz" Indicators)
      var luna = rigs['luna'];
      if (luna) {
        updateWoodlandFoliage(luna, t);

        if (luna.actionType === 'idle' && now >= luna.nextActionAt) {
          luna.cycleCount += 1;
          luna.actionType = luna.cycleCount % 2 === 1 ? 'meowing' : 'purring';
          luna.actionStart = now;
          luna.actionDuration = luna.actionType === 'meowing' ? 1500 : 2400;
        } else if (luna.actionType !== 'idle' && now - luna.actionStart >= luna.actionDuration) {
          luna.actionType = 'idle';
          luna.nextActionAt = now + 3200 + Math.random() * 2800;
        }

        var isMeowing = luna.actionType === 'meowing';
        var isPurring = luna.actionType === 'purring';

        // Continuous swishing ringed tail around (180, 378)
        var tailSwish = Math.sin(t * 2.1 + 0.5) * 14 + Math.sin(t * 0.9) * 4;
        setSvgPartTransform(luna.parts['tail'], 0, 0, tailSwish, 1, 1);

        // Breathing & purring chest/body
        var catBreathe = Math.sin(t * 2.2 + 0.8);
        var purrShimmer = isPurring ? Math.sin(t * 26) * 0.012 : 0;
        setSvgPartTransform(
          luna.parts['chest'],
          0,
          -catBreathe * 2.2,
          0,
          1 + catBreathe * 0.036 + purrShimmer,
          1 + catBreathe * 0.044 + purrShimmer
        );

        // Ear twitches & whisker movement
        var earTwitchL = Math.sin(t * 1.7) > 0.82 ? -8 * Math.sin(t * 9) : Math.sin(t * 1.3) * 2.5;
        var earTwitchR = Math.sin(t * 1.9 + 1.2) > 0.82 ? 8 * Math.sin(t * 9) : -Math.sin(t * 1.4) * 2.5;
        setSvgPartTransform(luna.parts['ear-l'], 0, 0, earTwitchL, 1, 1);
        setSvgPartTransform(luna.parts['ear-r'], 0, 0, earTwitchR, 1, 1);
        setSvgPartTransform(luna.parts['whiskers'], 0, 0, Math.sin(t * 2.6) * 2.2, 1 + Math.sin(t * 3.1) * 0.025, 1);

        var meowOpen = 0;
        var meowBubbleAlpha = 0;
        var zzzBubbleAlpha = 0;
        var catHeadY = -catBreathe * 1.8;
        var catHeadRot = Math.sin(t * 1.1) * 1.5;
        luna.extraEyelidClosure = 0;

        if (isMeowing) {
          var mElapsed = now - luna.actionStart;
          // Smooth opening and closing mouth during "Meow~"
          if (mElapsed >= 80 && mElapsed <= 1150) {
            meowOpen = Math.sin(((mElapsed - 80) / 1070) * Math.PI);
          }
          catHeadY -= meowOpen * 4.5;
          catHeadRot += meowOpen * 3.2;

          var mIn = Math.min(1, mElapsed / 200);
          var mOut = Math.min(1, (luna.actionDuration - mElapsed) / 260);
          meowBubbleAlpha = Math.max(0, Math.min(mIn, mOut));
        } else if (isPurring) {
          var zElapsed = now - luna.actionStart;
          var zIn = Math.min(1, zElapsed / 320);
          var zOut = Math.min(1, (luna.actionDuration - zElapsed) / 360);
          zzzBubbleAlpha = Math.max(0, Math.min(zIn, zOut));
          // Contented resting/purring eyes close softly while "Purr… zzz" floats
          luna.extraEyelidClosure = zzzBubbleAlpha * 0.85;
          catHeadY += zzzBubbleAlpha * 2.2;
          catHeadRot -= zzzBubbleAlpha * 2.0;
        }

        setSvgPartTransform(luna.parts['head'], 0, catHeadY, catHeadRot, 1, 1);

        if (luna.parts['mouth-closed']) {
          luna.parts['mouth-closed'].setAttribute('opacity', meowOpen > 0.15 ? '0' : '1');
        }
        if (luna.parts['mouth-open']) {
          luna.parts['mouth-open'].setAttribute('opacity', meowOpen > 0.15 ? '1' : '0');
          setSvgPartTransform(luna.parts['mouth-open'], 0, meowOpen * 1.2, 0, 0.85 + meowOpen * 0.25, Math.max(0.15, meowOpen * 1.2));
        }

        setDialogueState(
          luna.parts['dialogue-meow'],
          meowBubbleAlpha,
          0,
          (1 - meowBubbleAlpha) * 7 - meowOpen * 2.5,
          0.92 + meowBubbleAlpha * 0.08
        );
        setDialogueState(
          luna.parts['dialogue-zzz'],
          zzzBubbleAlpha,
          Math.sin(t * 2.5) * 2,
          (1 - zzzBubbleAlpha) * 8 - (isPurring ? ((now - luna.actionStart) / luna.actionDuration) * 6 : 0),
          0.92 + zzzBubbleAlpha * 0.08
        );

        updateAnimalBlink(luna, now);
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
})();
