(function () {
  // Los widgets dentro de modales o paneles ocultos se renderizan recién al mostrarse,
  // para no cargar decenas de iframes (uno por reseña) al abrir la página.
  function isVisible(el) {
    return el.offsetParent !== null && !el.closest('.modal:not(.show)');
  }

  function renderPending() {
    if (!window.turnstile) {
      return;
    }

    var theme = document.documentElement.getAttribute('data-bs-theme') === 'light' ? 'light' : 'dark';

    document.querySelectorAll('[data-turnstile]:not([data-turnstile-rendered])').forEach(function (el) {
      if (!isVisible(el)) {
        return;
      }
      el.setAttribute('data-turnstile-rendered', '1');
      window.turnstile.render(el, {
        sitekey: el.getAttribute('data-sitekey'),
        theme: theme,
        language: 'es',
        appearance: 'interaction-only',
        callback: notifyLayout,
        'before-interactive-callback': notifyLayout,
        'after-interactive-callback': notifyLayout,
      });
      notifyLayout();
    });
  }

  // auth-board.js fija la altura del panel en "resize"; se recalcula cuando el widget cambia de tamaño.
  function notifyLayout() {
    setTimeout(function () {
      window.dispatchEvent(new Event('resize'));
    }, 300);
  }

  function renderSoon() {
    setTimeout(renderPending, 60);
    setTimeout(renderPending, 450);
  }

  window.caacuprecioTurnstileReady = renderPending;

  document.addEventListener('shown.bs.modal', renderPending);
  document.addEventListener('click', renderSoon);
  document.addEventListener('DOMContentLoaded', renderPending);
})();
