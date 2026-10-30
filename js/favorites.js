(function () {
  'use strict';

  if (window.__caacuprecioFavToggleBound) return;
  window.__caacuprecioFavToggleBound = true;

  var favBusy = new Set();

  function paintFavoriteButtons(productId, isFavorite) {
    var id = String(productId);
    document.querySelectorAll('[data-fav-toggle][data-product-id="' + id + '"]').forEach(function (btn) {
      btn.classList.toggle('is-on', isFavorite);
      btn.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
      var label = isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos';
      btn.setAttribute('title', label);
      btn.setAttribute('aria-label', label);
      var icon = btn.querySelector('svg.lucide, i[data-lucide]');
      if (icon) {
        icon.classList.toggle('lucide-fill', isFavorite);
      }
    });
  }

  function setNavbarFavCount(count) {
    if (typeof count !== 'number' || !Number.isFinite(count)) return;
    document.querySelectorAll('.navbar-user-count').forEach(function (el) {
      el.textContent = String(Math.max(0, Math.round(count)));
    });
  }

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-fav-toggle][data-product-id]');
    if (!btn) return;

    event.preventDefault();
    event.stopPropagation();

    var productId = parseInt(btn.getAttribute('data-product-id') || '0', 10);
    if (!productId || favBusy.has(productId)) return;

    var wantFavorite = !btn.classList.contains('is-on');
    favBusy.add(productId);
    btn.classList.add('is-busy');
    paintFavoriteButtons(productId, wantFavorite);

    fetch('favoritos_api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({
        action: 'set',
        product_id: String(productId),
        favorite: wantFavorite ? '1' : '0'
      })
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || !result.data.ok) {
          throw new Error((result.data && result.data.error) || 'No se pudo actualizar el favorito.');
        }
        paintFavoriteButtons(productId, Boolean(result.data.favorite));
        if (typeof result.data.count === 'number') {
          setNavbarFavCount(result.data.count);
        }
      })
      .catch(function () {
        paintFavoriteButtons(productId, !wantFavorite);
      })
      .finally(function () {
        favBusy.delete(productId);
        document.querySelectorAll('[data-fav-toggle][data-product-id="' + productId + '"]').forEach(function (el) {
          el.classList.remove('is-busy');
        });
      });
  });
})();
