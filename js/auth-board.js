(function () {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const desktopMq = window.matchMedia('(min-width: 992px)');
  const SWAP_MS = 720;

  function initCarousel() {
    const root = document.querySelector('[data-auth-visual]');
    if (!root) return;

    const slides = Array.from(root.querySelectorAll('[data-auth-slide]'));
    const dots = Array.from(root.querySelectorAll('[data-auth-dot]'));
    const prev = root.querySelector('[data-auth-prev]');
    const next = root.querySelector('[data-auth-next]');
    if (slides.length < 2) return;

    let index = Math.max(0, slides.findIndex(function (slide) {
      return slide.classList.contains('is-active');
    }));
    let timer = null;
    const intervalMs = 5500;
    const swipeThreshold = 42;
    let touchStartX = 0;
    let touchStartY = 0;
    let touchActive = false;

    const isDesktopVisual = function () {
      return desktopMq.matches;
    };

    const syncDots = function () {
      dots.forEach(function (dot, dotIndex) {
        const active = dotIndex === index;
        dot.setAttribute('aria-current', active ? 'true' : 'false');
      });
    };

    const show = function (nextIndex) {
      if (!isDesktopVisual()) return;
      slides[index].classList.remove('is-active');
      slides[index].hidden = true;
      index = (nextIndex + slides.length) % slides.length;
      slides[index].hidden = false;
      slides[index].classList.add('is-active');
      syncDots();
    };

    const stopAuto = function () {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    };

    const startAuto = function () {
      if (reduceMotion || !isDesktopVisual()) return;
      stopAuto();
      timer = setInterval(function () {
        show(index + 1);
      }, intervalMs);
    };

    const mobileHidden = [
      root.querySelector('.auth-visual-stage'),
      root.querySelector('.auth-visual-nav'),
      root.querySelector('.auth-visual-dots'),
    ].filter(Boolean);

    const syncForViewport = function () {
      const desktop = isDesktopVisual();
      if (desktop) root.removeAttribute('aria-hidden');
      else root.setAttribute('aria-hidden', 'true');
      mobileHidden.forEach(function (el) {
        if (desktop) el.removeAttribute('aria-hidden');
        else el.setAttribute('aria-hidden', 'true');
      });

      if (desktop) {
        syncDots();
        startAuto();
        return;
      }
      stopAuto();
    };

    if (prev) {
      prev.addEventListener('click', function () {
        show(index - 1);
        startAuto();
      });
    }

    if (next) {
      next.addEventListener('click', function () {
        show(index + 1);
        startAuto();
      });
    }

    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        const target = parseInt(dot.getAttribute('data-auth-dot') || '0', 10);
        if (Number.isNaN(target) || target === index) return;
        show(target);
        startAuto();
      });
    });

    root.addEventListener('touchstart', function (event) {
      if (!isDesktopVisual()) return;
      if (!event.touches || event.touches.length !== 1) return;
      touchActive = true;
      touchStartX = event.touches[0].clientX;
      touchStartY = event.touches[0].clientY;
      stopAuto();
    }, { passive: true });

    root.addEventListener('touchend', function (event) {
      if (!isDesktopVisual()) return;
      if (!touchActive || !event.changedTouches || !event.changedTouches.length) {
        startAuto();
        return;
      }

      const touch = event.changedTouches[0];
      const dx = touch.clientX - touchStartX;
      const dy = touch.clientY - touchStartY;
      touchActive = false;

      if (Math.abs(dx) >= swipeThreshold && Math.abs(dx) > Math.abs(dy)) {
        show(index + (dx < 0 ? 1 : -1));
      }

      startAuto();
    }, { passive: true });

    root.addEventListener('touchcancel', function () {
      touchActive = false;
      startAuto();
    }, { passive: true });

    root.addEventListener('mouseenter', stopAuto);
    root.addEventListener('mouseleave', startAuto);
    root.addEventListener('focusin', stopAuto);
    root.addEventListener('focusout', function (event) {
      if (!root.contains(event.relatedTarget)) startAuto();
    });

    if (typeof desktopMq.addEventListener === 'function') {
      desktopMq.addEventListener('change', syncForViewport);
    } else if (typeof desktopMq.addListener === 'function') {
      desktopMq.addListener(syncForViewport);
    }

    syncForViewport();
  }

  function initPasswordToggles() {
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
      button.addEventListener('click', function () {
        const target = document.querySelector(button.getAttribute('data-toggle-password'));
        if (!target) return;
        const isPassword = target.getAttribute('type') === 'password';
        target.setAttribute('type', isPassword ? 'text' : 'password');
        button.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
        button.setAttribute('aria-label', isPassword ? 'Ocultar contraseña' : 'Mostrar contraseña');
        button.innerHTML = isPassword
          ? '<i data-lucide="eye-off" width="18" height="18" aria-hidden="true"></i>'
          : '<i data-lucide="eye" width="18" height="18" aria-hidden="true"></i>';
        if (window.refreshLucideIcons) refreshLucideIcons();
      });
    });
  }

  function initRegisterSteps() {
    const form = document.querySelector('[data-auth-steps]');
    if (!form) return;

    const scope = form.closest('[data-auth-panel]') || form;
    const panels = Array.from(form.querySelectorAll('[data-auth-step-panel]'));
    const segments = Array.from(scope.querySelectorAll('[data-auth-step-segment]'));
    const nextBtn = form.querySelector('[data-auth-step-next]');
    const backBtn = form.querySelector('[data-auth-step-back]');
    let step = Math.max(1, parseInt(form.getAttribute('data-auth-step') || '1', 10) || 1);

    const focusFirst = function (panel) {
      const field = panel.querySelector('input:not([type="hidden"]), select, textarea');
      if (field) field.focus({ preventScroll: true });
    };

    const syncRequired = function () {
      panels.forEach(function (panel) {
        const panelStep = parseInt(panel.getAttribute('data-auth-step-panel') || '0', 10);
        const active = panelStep === step;
        panel.querySelectorAll('input, select, textarea').forEach(function (field) {
          if (!field.dataset.authRequired) {
            field.dataset.authRequired = field.required ? '1' : '0';
          }
          field.required = active && field.dataset.authRequired === '1';
        });
      });
    };

    const setStep = function (nextStep, opts) {
      const options = opts || {};
      step = nextStep;
      form.setAttribute('data-auth-step', String(step));

      panels.forEach(function (panel) {
        const panelStep = parseInt(panel.getAttribute('data-auth-step-panel') || '0', 10);
        const active = panelStep === step;
        panel.classList.toggle('is-active', active);
        panel.hidden = !active;
      });

      segments.forEach(function (segment) {
        const segmentStep = parseInt(segment.getAttribute('data-auth-step-segment') || '0', 10);
        segment.classList.toggle('is-active', segmentStep <= step);
      });

      syncRequired();

      const board = form.closest('[data-auth-board]');
      if (board) applyAuthBoardHeight(board);

      if (options.focus === false) return;

      const activePanel = panels.find(function (panel) {
        return parseInt(panel.getAttribute('data-auth-step-panel') || '0', 10) === step;
      });
      if (activePanel) focusFirst(activePanel);
    };

    const validatePanel = function (panel) {
      const fields = Array.from(panel.querySelectorAll('input, select, textarea'));
      for (let i = 0; i < fields.length; i += 1) {
        const field = fields[i];
        if (typeof field.checkValidity === 'function' && !field.checkValidity()) {
          field.reportValidity();
          return false;
        }
      }
      return true;
    };

    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        const panel = panels.find(function (item) {
          return parseInt(item.getAttribute('data-auth-step-panel') || '0', 10) === step;
        });
        if (!panel || !validatePanel(panel)) return;
        setStep(Math.min(step + 1, panels.length));
      });
    }

    if (backBtn) {
      backBtn.addEventListener('click', function () {
        setStep(Math.max(step - 1, 1));
      });
    }

    form.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      if (step >= panels.length) return;
      if (event.target && event.target.tagName === 'TEXTAREA') return;
      event.preventDefault();
      if (nextBtn) nextBtn.click();
    });

    const registerPanel = document.querySelector('[data-auth-panel="register"]');
    const registerVisible = registerPanel && !registerPanel.hidden;
    setStep(step, { focus: !!registerVisible });

    form._authSetStep = setStep;
  }

  function applyAuthBoardHeight(board) {
    if (!board || !board.classList.contains('is-size-locked')) return;

    const stepsForm = board.querySelector('[data-auth-steps]');
    const step = stepsForm
      ? Math.max(1, parseInt(stepsForm.getAttribute('data-auth-step') || '1', 10) || 1)
      : 1;
    const mode = board.getAttribute('data-auth-mode') === 'register' ? 'register' : 'login';
    const usePassword = mode === 'register' && step >= 2;

    const boardH = usePassword
      ? board.style.getPropertyValue('--auth-board-h-password')
      : board.style.getPropertyValue('--auth-board-h-base');
    const formH = usePassword
      ? board.style.getPropertyValue('--auth-form-h-password')
      : board.style.getPropertyValue('--auth-form-h-base');

    if (boardH) board.style.setProperty('--auth-board-h', boardH);
    if (formH) board.style.setProperty('--auth-form-h', formH);
    board.classList.toggle('is-password-step', usePassword);
  }

  function lockAuthBoardSize(board) {
    const formCol = board.querySelector('.auth-board-form');
    const login = board.querySelector('[data-auth-panel="login"]');
    const register = board.querySelector('[data-auth-panel="register"]');
    if (!formCol || !login || !register) return;

    const isDesktop = desktopMq.matches;
    const COLS_LOGIN = 'minmax(18rem, 1.05fr) minmax(16rem, 0.95fr)';
    const COLS_REGISTER = 'minmax(16rem, 0.95fr) minmax(18rem, 1.05fr)';
    const colsLogin = isDesktop ? COLS_LOGIN : '1fr';
    const colsRegister = isDesktop ? COLS_REGISTER : '1fr';

    const prev = {
      mode: board.getAttribute('data-auth-mode'),
      loginHidden: login.hidden,
      registerHidden: register.hidden,
      loginActive: login.classList.contains('is-active'),
      registerActive: register.classList.contains('is-active'),
      wasRegister: board.classList.contains('auth-board--register'),
    };

    board.classList.remove('is-size-locked', 'auth-board--register', 'is-password-step');
    board.style.removeProperty('--auth-board-h');
    board.style.removeProperty('--auth-form-h');
    board.style.removeProperty('--auth-board-h-base');
    board.style.removeProperty('--auth-form-h-base');
    board.style.removeProperty('--auth-board-h-password');
    board.style.removeProperty('--auth-form-h-password');
    board.style.gridTemplateColumns = colsLogin;

    login.hidden = false;
    login.classList.add('is-active');
    register.hidden = true;
    register.classList.remove('is-active');

    void board.offsetWidth;

    let baseBoardH = board.getBoundingClientRect().height;
    let baseFormH = formCol.getBoundingClientRect().height;
    let passwordBoardH = baseBoardH;
    let passwordFormH = baseFormH;

    const steps = Array.prototype.slice.call(register.querySelectorAll('[data-auth-step-panel]'));
    const prevSteps = steps.map(function (s) {
      return { hidden: s.hidden, active: s.classList.contains('is-active') };
    });

    login.hidden = true;
    login.classList.remove('is-active');
    register.hidden = false;
    register.classList.add('is-active');
    board.classList.add('auth-board--register');
    board.style.gridTemplateColumns = colsRegister;

    (steps.length ? steps : [null]).forEach(function (current, index) {
      steps.forEach(function (s) {
        s.hidden = s !== current;
        s.classList.toggle('is-active', s === current);
      });
      void board.offsetWidth;
      const boardH = board.getBoundingClientRect().height;
      const formH = formCol.getBoundingClientRect().height;
      const stepNum = current
        ? parseInt(current.getAttribute('data-auth-step-panel') || String(index + 1), 10)
        : 1;

      if (stepNum <= 1) {
        baseBoardH = Math.max(baseBoardH, boardH);
        baseFormH = Math.max(baseFormH, formH);
      } else {
        passwordBoardH = Math.max(passwordBoardH, boardH);
        passwordFormH = Math.max(passwordFormH, formH);
      }
    });

    passwordBoardH = Math.max(passwordBoardH, baseBoardH);
    passwordFormH = Math.max(passwordFormH, baseFormH);

    steps.forEach(function (s, i) {
      s.hidden = prevSteps[i].hidden;
      s.classList.toggle('is-active', prevSteps[i].active);
    });

    board.style.setProperty('--auth-board-h-base', Math.ceil(baseBoardH) + 'px');
    board.style.setProperty('--auth-form-h-base', Math.ceil(baseFormH) + 'px');
    board.style.setProperty('--auth-board-h-password', Math.ceil(passwordBoardH) + 'px');
    board.style.setProperty('--auth-form-h-password', Math.ceil(passwordFormH) + 'px');
    board.classList.add('is-size-locked');

    login.hidden = prev.loginHidden;
    register.hidden = prev.registerHidden;
    login.classList.toggle('is-active', prev.loginActive);
    register.classList.toggle('is-active', prev.registerActive);
    const restoreRegister = prev.wasRegister || prev.mode === 'register';
    board.classList.toggle('auth-board--register', restoreRegister);
    board.style.gridTemplateColumns = restoreRegister ? colsRegister : colsLogin;

    applyAuthBoardHeight(board);
  }

  function initBoardSwap() {
    const board = document.querySelector('[data-auth-board]');
    if (!board) return;

    const formCol = board.querySelector('.auth-board-form');
    const visual = board.querySelector('.auth-visual-shell');
    const section = document.querySelector('.auth-hero');
    const panels = {
      login: board.querySelector('[data-auth-panel="login"]'),
      register: board.querySelector('[data-auth-panel="register"]'),
    };

    if (!formCol || !visual || !panels.login || !panels.register) return;

    const COLS_LOGIN = 'minmax(18rem, 1.05fr) minmax(16rem, 0.95fr)';
    const COLS_REGISTER = 'minmax(16rem, 0.95fr) minmax(18rem, 1.05fr)';

    const applyColumns = function (mode) {
      if (!desktopMq.matches) {
        board.style.setProperty('grid-template-columns', '1fr');
        return;
      }
      board.style.gridTemplateColumns = mode === 'register' ? COLS_REGISTER : COLS_LOGIN;
    };

    applyColumns(board.getAttribute('data-auth-mode') === 'register' ? 'register' : 'login');
    lockAuthBoardSize(board);

    if (typeof desktopMq.addEventListener === 'function') {
      desktopMq.addEventListener('change', function () {
        applyColumns(board.getAttribute('data-auth-mode') === 'register' ? 'register' : 'login');
        lockAuthBoardSize(board);
      });
    } else if (typeof desktopMq.addListener === 'function') {
      desktopMq.addListener(function () {
        applyColumns(board.getAttribute('data-auth-mode') === 'register' ? 'register' : 'login');
        lockAuthBoardSize(board);
      });
    }

    let resizeTimer = null;
    const scheduleLock = function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () {
        lockAuthBoardSize(board);
      }, 120);
    };
    window.addEventListener('resize', scheduleLock);
    window.addEventListener('load', scheduleLock);
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(scheduleLock);
    }
    if (window.ResizeObserver) {
      let slotMaxH = 0;
      const slotObserver = new ResizeObserver(function (entries) {
        entries.forEach(function (entry) {
          const h = Math.round(entry.contentRect.height);
          if (h > slotMaxH) {
            slotMaxH = h;
            scheduleLock();
          }
        });
      });
      board.querySelectorAll('.cf-turnstile-slot').forEach(function (slot) {
        slotObserver.observe(slot);
      });
    }

    const pageUrl = function (file) {
      try {
        return new URL(file, window.location.href).pathname;
      } catch (err) {
        return file;
      }
    };

    const urls = {
      login: pageUrl('login.php'),
      register: pageUrl('registro.php'),
    };

    const applyMode = function (mode, options) {
      const opts = options || {};
      const nextMode = mode === 'register' ? 'register' : 'login';
      const currentMode = board.getAttribute('data-auth-mode') === 'register' ? 'register' : 'login';
      if (nextMode === currentMode && !opts.force) return;

      const activatePanel = function () {
        Object.keys(panels).forEach(function (key) {
          const active = key === nextMode;
          panels[key].classList.toggle('is-active', active);
          panels[key].hidden = !active;
        });

        board.classList.toggle('auth-board--register', nextMode === 'register');
        board.setAttribute('data-auth-mode', nextMode);
        applyColumns(nextMode);

        const docTitle = panels[nextMode].getAttribute('data-auth-doc-title') || '';
        if (docTitle) {
          const suffix = document.title.includes('|')
            ? document.title.slice(document.title.indexOf('|'))
            : '| Caacuprecio';
          document.title = docTitle + ' ' + suffix.trim();
        }

        if (section) {
          section.setAttribute(
            'aria-labelledby',
            nextMode === 'register' ? 'auth-title-register' : 'auth-title-login'
          );
        }

        if (nextMode === 'register') {
          const stepsForm = panels.register.querySelector('[data-auth-steps]');
          if (stepsForm && typeof stepsForm._authSetStep === 'function') {
            stepsForm._authSetStep(1, { focus: false });
          } else {
            applyAuthBoardHeight(board);
          }
        } else {
          applyAuthBoardHeight(board);
        }

        if (opts.focus === false) return;

        const focusTarget = panels[nextMode].querySelector('input:not([type="hidden"]), select, textarea');
        if (focusTarget) {
          window.setTimeout(function () {
            focusTarget.focus({ preventScroll: true });
          }, 30);
        }
      };

      const syncUrl = function () {
        if (opts.pushState === false) return;
        const nextUrl = urls[nextMode];
        if (!nextUrl) return;
        const state = { authMode: nextMode };
        if (opts.replaceState) {
          history.replaceState(state, '', nextUrl);
        } else if (window.location.pathname !== nextUrl) {
          history.pushState(state, '', nextUrl);
        } else {
          history.replaceState(state, '', nextUrl);
        }
      };

      const canAnimate = !reduceMotion && opts.animate !== false && nextMode !== currentMode;

      if (!canAnimate) {
        activatePanel();
        syncUrl();
        return;
      }

      board.classList.add('is-swapping');

      // FLIP: desktop mueve columnas; mobile mantiene banda arriba y solo revela el form.
      const isDesktop = desktopMq.matches;
      const formFirst = formCol.getBoundingClientRect();
      const visualFirst = visual.getBoundingClientRect();

      activatePanel();
      syncUrl();

      const formLast = formCol.getBoundingClientRect();
      const visualLast = visual.getBoundingClientRect();

      const formDx = formFirst.left - formLast.left;
      const formDy = formFirst.top - formLast.top;
      const visualDx = visualFirst.left - visualLast.left;
      const visualDy = visualFirst.top - visualLast.top;
      const formScale = formFirst.width && formLast.width ? formFirst.width / formLast.width : 1;
      const visualScale = visualFirst.width && visualLast.width ? visualFirst.width / visualLast.width : 1;

      const ease = 'var(--auth-swap-ease, cubic-bezier(0.22, 1, 0.36, 1))';
      const formInvert = isDesktop
        ? 'translate(' + formDx + 'px, ' + formDy + 'px) scaleX(' + formScale + ')'
        : 'translate(' + formDx + 'px, ' + formDy + 'px)';
      const visualInvert = isDesktop
        ? 'translate(' + visualDx + 'px, ' + visualDy + 'px) scaleX(' + visualScale + ')'
        : 'translate(' + visualDx + 'px, ' + visualDy + 'px)';

      formCol.style.transition = 'none';
      visual.style.transition = 'none';
      formCol.style.transformOrigin = isDesktop ? 'left center' : 'center top';
      visual.style.transformOrigin = isDesktop ? 'left center' : 'center top';
      formCol.style.transform = formInvert;
      visual.style.transform = visualInvert;

      void formCol.offsetWidth;

      window.requestAnimationFrame(function () {
        formCol.style.transition = 'transform ' + SWAP_MS + 'ms ' + ease;
        visual.style.transition = 'transform ' + SWAP_MS + 'ms ' + ease;
        formCol.style.transform = '';
        visual.style.transform = '';
      });

      window.setTimeout(function () {
        formCol.style.transition = '';
        visual.style.transition = '';
        formCol.style.transformOrigin = '';
        visual.style.transformOrigin = '';
        board.classList.remove('is-swapping');
      }, SWAP_MS + 40);
    };

    document.addEventListener('click', function (event) {
      const trigger = event.target.closest('[data-auth-swap]');
      if (!trigger || !board.contains(trigger)) return;
      if (board.classList.contains('is-swapping')) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }

      const targetMode = trigger.getAttribute('data-auth-swap') === 'register' ? 'register' : 'login';
      event.preventDefault();
      event.stopPropagation();
      applyMode(targetMode, { animate: true, pushState: true });
    }, true);

    window.addEventListener('popstate', function (event) {
      const modeFromState = event.state && event.state.authMode;
      const modeFromPath = /registro\.php/i.test(window.location.pathname) ? 'register' : 'login';
      applyMode(modeFromState || modeFromPath, { animate: false, pushState: false, focus: false });
    });

    if (!history.state || !history.state.authMode) {
      history.replaceState(
        { authMode: board.getAttribute('data-auth-mode') === 'register' ? 'register' : 'login' },
        '',
        window.location.href
      );
    }
  }

  try { initBoardSwap(); } catch (err) { console.error(err); }
  try { initCarousel(); } catch (err) { console.error(err); }
  try { initPasswordToggles(); } catch (err) { console.error(err); }
  try { initRegisterSteps(); } catch (err) { console.error(err); }
})();
