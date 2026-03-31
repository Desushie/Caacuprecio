document.addEventListener('DOMContentLoaded', () => {
  const roots = document.querySelectorAll('[data-search-root]');
  if (!roots.length) return;

  roots.forEach(initSearch);

  function initSearch(root) {
    const form = root.querySelector('form');
    const input = root.querySelector('[data-search-input]');
    const category = root.querySelector('[data-search-category]');
    const sort = root.querySelector('[data-search-sort], [data-search-order]');
    const suggestBox = root.querySelector('[data-search-suggest], [data-search-suggestions], [data-search-dropdown]');
    const liveBox = document.querySelector(root.dataset.liveTarget || '');
    const countBox = document.querySelector(root.dataset.countTarget || '');
    const stateBox = document.querySelector(root.dataset.stateTarget || '');
    const endpoint = root.dataset.searchEndpoint || 'buscar_api.php';

    if (!form || !input || !suggestBox) return;

    let suggestTimer = null;
    let liveTimer = null;
    let lastQuery = '';
    let requestId = 0;

    input.addEventListener('focus', () => loadSuggestions(input.value.trim()));

    input.addEventListener('input', () => {
      const q = input.value.trim();
      clearTimeout(suggestTimer);
      clearTimeout(liveTimer);

      suggestTimer = setTimeout(() => loadSuggestions(q), 180);

      if (liveBox) {
        liveTimer = setTimeout(() => loadLiveResults(q), Number(input.dataset.liveDelay || 320));
      }
    });

    document.addEventListener('click', (e) => {
      if (!root.contains(e.target)) {
        suggestBox.classList.remove('is-open');
        suggestBox.classList.add('d-none');
      }
    });

    form.addEventListener('submit', () => {
      trackSearch(input.value.trim());
    });

    suggestBox.addEventListener('click', (e) => {
      const item = e.target.closest('[data-search-value]');
      if (!item) return;

      const value = item.getAttribute('data-search-value') || '';
      input.value = value;
      suggestBox.classList.remove('is-open');
      suggestBox.classList.add('d-none');

      if (item.dataset.searchAction === 'submit') {
        trackSearch(value);
        form.submit();
        return;
      }

      clearTimeout(liveTimer);
      liveTimer = setTimeout(() => loadLiveResults(value), 50);
    });

    async function loadSuggestions(q) {
      const currentId = ++requestId;
      const params = new URLSearchParams({
        mode: 'suggest',
        q,
        categoria: category ? category.value : '0',
        orden: sort ? sort.value : 'recientes'
      });

      try {
        const res = await fetch(`${endpoint}?${params.toString()}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (currentId !== requestId) return;
        renderSuggestions(data, q);
      } catch (err) {
        console.error('Error sugerencias', err);
      }
    }

    async function loadLiveResults(q) {
      if (!liveBox) return;

      const currentId = ++requestId;
      const params = new URLSearchParams({
        mode: 'live',
        q,
        categoria: category ? category.value : '0',
        orden: sort ? sort.value : 'recientes',
        limit: '8'
      });

      if (stateBox) {
        stateBox.textContent = q ? 'Buscando...' : 'Mostrando resultados recientes...';
      }

      try {
        const res = await fetch(`${endpoint}?${params.toString()}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (currentId !== requestId) return;
        renderLiveResults(data, q);
      } catch (err) {
        console.error('Error búsqueda en vivo', err);
        if (stateBox) {
          stateBox.textContent = 'No se pudo actualizar la búsqueda en vivo.';
        }
      }
    }

    async function trackSearch(q) {
      q = (q || '').trim();
      if (!q || q === lastQuery) return;
      lastQuery = q;

      try {
        const params = new URLSearchParams({ mode: 'track', q });
        await fetch(`${endpoint}?${params.toString()}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
      } catch (err) {
        console.error('Error guardando historial', err);
      }
    }

    function renderSuggestions(data, q) {
      const suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
      const trending = Array.isArray(data.trending) ? data.trending : [];
      const history = Array.isArray(data.history) ? data.history : [];
      let html = '';

      if (q && suggestions.length) {
        html += sectionTitle('Sugerencias');
        suggestions.forEach(item => {
          html += itemRow(iconByType(item.type), item.label, item.meta);
        });
      }

      if (history.length) {
        html += sectionTitle('Tu historial');
        history.forEach(item => {
          html += itemRow('bi-clock-history', item.label, item.meta);
        });
      }

      if (trending.length) {
        html += sectionTitle('Más buscados');
        trending.forEach(item => {
          html += itemRow('bi-fire', item.label, item.meta);
        });
      }

      if (q) {
        html += `<button type="button" class="search-suggest-submit" data-search-value="${escapeHtml(q)}" data-search-action="submit"><i class="bi bi-search me-2"></i>Buscar "${escapeHtml(q)}"</button>`;
      }

      if (!html) {
        html = '<div class="search-suggest-empty">Empezá a escribir para ver sugerencias.</div>';
      }

      suggestBox.innerHTML = html;
      suggestBox.classList.remove('d-none');
      suggestBox.classList.add('is-open');
    }

    function renderLiveResults(data, q) {
      if (!liveBox) return;

      const items = Array.isArray(data.items) ? data.items : [];

      if (countBox) {
        countBox.textContent = `${items.length} resultado(s) en vista rápida`;
      }

      if (!items.length) {
        liveBox.innerHTML = '<div class="col-12"><div class="empty-state">No se encontraron resultados en vivo.</div></div>';
        if (stateBox) {
          stateBox.textContent = 'Sin coincidencias.';
        }
        return;
      }

      liveBox.innerHTML = items.map(item => `
        <div class="col-md-6 col-xl-3">
          <article class="custom-card product-card h-100 p-3 fancy-hover">
            <div class="product-thumb-wrap mb-3">
              <img src="${escapeHtml(item.imagen || '')}" alt="${escapeHtml(item.nombre || '')}" class="offer-thumb">
            </div>
            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
              <span class="badge rounded-pill ${escapeHtml(item.stock_class || '')}">${escapeHtml(item.stock || '')}</span>
              <small class="text-body-secondary">${escapeHtml(item.tienda || '')}</small>
            </div>
            <h3 class="h6 fw-bold mb-2 line-clamp-2">${escapeHtml(item.nombre || '')}</h3>
            <div class="small text-body-secondary mb-2">${escapeHtml(item.marca || item.categoria || '')}</div>
            <div class="fw-bold fs-5 mb-3">${escapeHtml(item.precio || '')}</div>
            <a href="${appendQueryToUrl(item.url || '#', q)}" class="btn btn-primary w-100 rounded-pill">Ver producto</a>
          </article>
        </div>
      `).join('');

      if (stateBox) {
        stateBox.textContent = 'Resultados actualizados.';
      }
    }

    function itemRow(icon, label, meta) {
      return `
        <button type="button" class="search-suggest-item" data-search-value="${escapeHtml(label || '')}">
          <span class="search-suggest-icon"><i class="bi ${escapeHtml(icon)}"></i></span>
          <span class="search-suggest-copy">
            <strong>${escapeHtml(label || '')}</strong>
            ${meta ? `<small>${escapeHtml(meta)}</small>` : ''}
          </span>
        </button>
      `;
    }

    function sectionTitle(text) {
      return `<div class="search-suggest-section">${escapeHtml(text)}</div>`;
    }

    function iconByType(type) {
      switch (type) {
        case 'producto': return 'bi-box-seam';
        case 'marca': return 'bi-bookmark-star';
        case 'categoria': return 'bi-grid';
        case 'tienda': return 'bi-shop';
        case 'popular':
        case 'trending': return 'bi-fire';
        case 'history': return 'bi-clock-history';
        default: return 'bi-search';
      }
    }

    function appendQueryToUrl(url, q) {
      try {
        const parsed = new URL(url, window.location.href);

        if (q && q.trim()) {
          parsed.searchParams.set('q', q.trim());
        }

        return parsed.toString();
      } catch (err) {
        return url;
      }
    }

    function escapeHtml(value) {
      return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }
  }

  const TRACK_ENDPOINT = 'event.php';

function sendTracking(data) {
  const body = new URLSearchParams({
    action: data.action,
    product_id: data.product_id,
    term: data.term || '',
    source: data.source || '',
    click_type: data.click_type || '',
    target_url: data.target_url || ''
  });

  if (navigator.sendBeacon) {
    const blob = new Blob([body.toString()], {
      type: 'application/x-www-form-urlencoded'
    });
    navigator.sendBeacon(TRACK_ENDPOINT, blob);
  } else {
    fetch(TRACK_ENDPOINT, {
      method: 'POST',
      body,
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      }
    }).catch(() => {});
  }
}

  function getSearchTermFromLinkOrPage(link) {
    try {
      const url = new URL(link.href, window.location.origin);
      const linkTerm = (url.searchParams.get('q') || '').trim();
      if (linkTerm) return linkTerm;
    } catch (err) {}

    try {
      const pageUrl = new URL(window.location.href);
      return (pageUrl.searchParams.get('q') || '').trim();
    } catch (err) {
      return '';
    }
  }

  function getSourceFromLinkOrPage(link) {
    try {
      const url = new URL(link.href, window.location.origin);
      const explicitSource = (url.searchParams.get('src') || '').trim();
      if (explicitSource) return explicitSource;
    } catch (err) {}

    const path = window.location.pathname.toLowerCase();

    if (path.includes('/buscar.php')) return 'buscar';
    if (path.includes('/producto.php')) return 'producto';
    if (path.includes('/index.php')) return 'index';

    const lastSegment = path.split('/').filter(Boolean).pop() || '';
    if (lastSegment === '') return 'index';

    return 'index';
  }

  function getProductId(href) {
    const match = href.match(/[?&]id=(\d+)/);
    return match ? match[1] : null;
  }

  function sendTracking(data) {
    const body = new URLSearchParams({
      action: data.action,
      product_id: data.product_id,
      term: data.term || '',
      source: data.source || ''
    });

    if (navigator.sendBeacon) {
      const blob = new Blob([body.toString()], {
        type: 'application/x-www-form-urlencoded'
      });
      navigator.sendBeacon(TRACK_ENDPOINT, blob);
    } else {
      fetch(TRACK_ENDPOINT, {
        method: 'POST',
        body,
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        }
      }).catch(() => {});
    }
  }
});