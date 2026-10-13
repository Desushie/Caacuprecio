(function () {
  var FALLBACK_ICON = 'image';

  function refreshLucideIcons() {
    if (!window.lucide || typeof window.lucide.createIcons !== 'function') {
      return;
    }

    window.lucide.createIcons({
      attrs: {
        'stroke-width': 1.75
      }
    });
  }

  function setLucideIcon(container, name) {
    if (!container || !name) {
      return;
    }

    container.innerHTML = '';
    const icon = document.createElement('i');
    icon.setAttribute('data-lucide', name);
    icon.setAttribute('aria-hidden', 'true');
    container.appendChild(icon);
    refreshLucideIcons();
  }

  function shouldSkipImageFallback(img) {
    if (!img || img.dataset.fallbackApplied === '1' || img.dataset.noFallback === 'true') {
      return true;
    }

    if (img.hasAttribute('data-theme-logo') || img.classList.contains('site-navbar-logo')) {
      return true;
    }

    if (img.closest('.navbar-brand, .site-footer-brand')) {
      return true;
    }

    return false;
  }

  function applyProductImageFallback(img) {
    if (shouldSkipImageFallback(img)) {
      return;
    }

    img.dataset.fallbackApplied = '1';

    var wrap = document.createElement('span');
    wrap.className = 'img-fallback' + (img.className ? ' ' + img.className : '');
    wrap.setAttribute('role', 'img');
    wrap.setAttribute('aria-label', img.getAttribute('alt') || 'Sin imagen');

    var icon = document.createElement('i');
    icon.setAttribute('data-lucide', FALLBACK_ICON);
    icon.setAttribute('aria-hidden', 'true');
    icon.style.color = '#94a3b8';
    wrap.appendChild(icon);

    img.replaceWith(wrap);
    refreshLucideIcons();

    var svg = wrap.querySelector('svg');
    if (svg) {
      svg.style.color = '#94a3b8';
      svg.setAttribute('stroke', '#94a3b8');
      svg.querySelectorAll('[stroke]').forEach(function (node) {
        if (node.getAttribute('stroke') === 'currentColor') {
          node.setAttribute('stroke', '#94a3b8');
        }
      });
    }
  }

  function hydrateEmptyImages(root) {
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('img').forEach(function (img) {
      var src = (img.getAttribute('src') || '').trim();
      if (!src) {
        applyProductImageFallback(img);
      }
    });
  }

  document.addEventListener('error', function (event) {
    var target = event.target;
    if (target && target.tagName === 'IMG') {
      applyProductImageFallback(target);
    }
  }, true);

  window.refreshLucideIcons = refreshLucideIcons;
  window.setLucideIcon = setLucideIcon;
  window.applyProductImageFallback = applyProductImageFallback;

  document.addEventListener('DOMContentLoaded', function () {
    hydrateEmptyImages(document);
    refreshLucideIcons();
  });
})();
