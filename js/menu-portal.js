// Menús "⋯" de las tarjetas del panel: mientras están abiertos se montan en <body>,
// porque las tarjetas (overflow: hidden) y los paneles (backdrop-filter) los recortan.
(function () {
  const INHERITED_VARS = ['--rp-bg', '--rp-line', '--rp-tile', '--s-c', '--av-c'];
  const ITEM_SELECTOR = '.dropdown-item:not(.disabled):not(:disabled)';

  function menuFor(toggle) {
    const next = toggle.nextElementSibling;
    return next && next.classList.contains('rp-menu-list') ? next : null;
  }

  function onMenuKeydown(event) {
    const menu = event.currentTarget;
    const toggle = menu._rpToggle;
    if (!toggle) { return; }

    if (event.key === 'Escape') {
      event.stopPropagation();
      const instance = window.bootstrap && window.bootstrap.Dropdown.getInstance(toggle);
      if (instance) { instance.hide(); }
      toggle.focus();
      return;
    }

    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') { return; }
    event.preventDefault();
    event.stopPropagation();

    const items = Array.from(menu.querySelectorAll(ITEM_SELECTOR));
    if (!items.length) { return; }
    const current = items.indexOf(document.activeElement);
    const step = event.key === 'ArrowDown' ? 1 : -1;
    const nextIndex = current === -1 ? 0 : (current + step + items.length) % items.length;
    items[nextIndex].focus();
  }

  document.addEventListener('show.bs.dropdown', function (event) {
    const toggle = event.target;
    if (event.defaultPrevented || !toggle.closest('.rp-menu')) { return; }

    const menu = menuFor(toggle);
    if (!menu) { return; }

    const styles = getComputedStyle(toggle);
    INHERITED_VARS.forEach(function (name) {
      const value = styles.getPropertyValue(name).trim();
      if (value) { menu.style.setProperty(name, value); }
    });

    menu._rpToggle = toggle;
    menu.addEventListener('keydown', onMenuKeydown);
    document.body.appendChild(menu);
  });

  document.addEventListener('hidden.bs.dropdown', function (event) {
    const toggle = event.target;
    if (!toggle.closest('.rp-menu')) { return; }

    const menu = Array.from(document.body.children).find(function (el) { return el._rpToggle === toggle; });
    if (!menu) { return; }

    menu.removeEventListener('keydown', onMenuKeydown);
    INHERITED_VARS.forEach(function (name) { menu.style.removeProperty(name); });
    delete menu._rpToggle;
    toggle.insertAdjacentElement('afterend', menu);
  });
})();
