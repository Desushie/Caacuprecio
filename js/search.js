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

    const suggestHost = input.closest('.search-dock-field, .search-input-col, .search-autocomplete-wrap') || input.parentElement;

    function openSuggest() {
      suggestBox.classList.remove('d-none');
      suggestBox.classList.add('is-open');
      if (suggestHost) suggestHost.classList.add('is-suggesting');
      input.setAttribute('aria-expanded', 'true');
    }

    function closeSuggest() {
      suggestBox.classList.remove('is-open');
      suggestBox.classList.add('d-none');
      if (suggestHost) suggestHost.classList.remove('is-suggesting');
      input.setAttribute('aria-expanded', 'false');
    }

    let suggestTimer = null;
    let liveTimer = null;
    let lastQuery = '';
    let requestId = 0;
    let liveRequestId = 0;
    let lastLiveScrollQuery = '';
    const livePanel = liveBox ? liveBox.closest('[data-live-panel]') : null;
    const liveSection = liveBox
      ? (liveBox.closest('#home-live-section') || document.getElementById('home-live-section'))
      : null;
    const liveTileWash = ['#8ba4c4', '#c9a87a', '#c48b8e', '#8fad97', '#a89bc4', '#7eb8b0', '#c4a88b', '#9bb0c9'];

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
        closeSuggest();
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
      closeSuggest();

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

    function setLiveSectionActive(isActive) {
      if (!liveSection) return;

      liveSection.classList.toggle('is-active', isActive);
      if (isActive) {
        liveSection.removeAttribute('hidden');
      } else {
        liveSection.setAttribute('hidden', '');
        lastLiveScrollQuery = '';
      }
    }

    function scrollToLiveSection(q) {
      if (!liveSection || !q) return;

      const normalized = q.trim().toLowerCase();
      if (!normalized || normalized === lastLiveScrollQuery) return;

      const rect = liveSection.getBoundingClientRect();
      const offset = 96;
      const alreadyInView = rect.top >= offset && rect.top <= window.innerHeight * 0.42;
      if (alreadyInView) {
        lastLiveScrollQuery = normalized;
        return;
      }

      lastLiveScrollQuery = normalized;
      liveSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function loadLiveResults(q) {
      if (!liveBox) return;

      const query = (q || '').trim();
      if (!query) {
        setLiveSectionActive(false);
        return;
      }

      setLiveSectionActive(true);

      const currentId = ++liveRequestId;
      const params = new URLSearchParams({
        mode: 'live',
        q: query,
        categoria: category ? category.value : '0',
        orden: sort ? sort.value : 'recientes',
        limit: '8'
      });

      if (stateBox) {
        stateBox.textContent = 'Buscando...';
      }

      setLiveLoading(true);
      requestAnimationFrame(() => scrollToLiveSection(query));

      try {
        const res = await fetch(`${endpoint}?${params.toString()}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (currentId !== liveRequestId) return;
        renderLiveResults(data, query);
      } catch (err) {
        console.error('Error búsqueda en vivo', err);
        if (currentId === liveRequestId) setLiveLoading(false);
        if (stateBox) {
          stateBox.textContent = 'No se pudo actualizar la búsqueda en vivo.';
        }
      }
    }

    if (liveBox && input.value.trim()) {
      loadLiveResults(input.value.trim());
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
          html += itemRow('history', item.label, item.meta);
        });
      }

      if (trending.length) {
        html += sectionTitle('Más buscados');
        trending.forEach(item => {
          html += itemRow('flame', item.label, item.meta);
        });
      }

      if (q) {
        html += `<button type="button" class="search-suggest-submit" data-search-value="${escapeHtml(q)}" data-search-action="submit">
          <span class="search-suggest-icon" aria-hidden="true"><i data-lucide="search"></i></span>
          <span class="search-suggest-copy">
            <span class="search-suggest-label">Buscar “${escapeHtml(q)}”</span>
            <span class="search-suggest-meta">Ver todos los resultados</span>
          </span>
          <span class="search-suggest-go" aria-hidden="true"><i data-lucide="arrow-right"></i></span>
        </button>`;
      }

      if (!html) {
        html = '<div class="search-suggest-empty">Empieza a escribir para ver sugerencias.</div>';
      }

      suggestBox.innerHTML = html;
      openSuggest();
      if (window.refreshLucideIcons) refreshLucideIcons();
    }

    function setLiveLoading(isLoading) {
      if (!livePanel) return;

      livePanel.classList.toggle('is-loading', isLoading);
      livePanel.setAttribute('aria-busy', isLoading ? 'true' : 'false');

      if (isLoading && !liveBox.querySelector('.sr-card')) {
        liveBox.innerHTML = Array.from({ length: 4 }, () => `
          <div class="hl-skeleton" aria-hidden="true">
            <span class="hl-skeleton-media"></span>
            <span class="hl-skeleton-line is-short"></span>
            <span class="hl-skeleton-line"></span>
            <span class="hl-skeleton-line is-price"></span>
          </div>
        `).join('');
      }
    }

    function renderLiveResults(data, q) {
      if (!liveBox) return;

      const items = Array.isArray(data.items) ? data.items : [];

      if (livePanel) {
        renderLiveBoard(items, q);
        return;
      }

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

    function renderLiveBoard(items, q) {
      const searchUrl = 'buscar.php' + (q ? `?q=${encodeURIComponent(q)}` : '');
      const prices = items.map(item => Number(item.precio_raw) || 0).filter(price => price > 0);
      const offers = items.reduce((total, item) => total + (Number(item.ofertas) || 0), 0);
      const inStock = items.filter(item => item.en_stock).length;

      setLiveStat('results', String(items.length));
      setLiveStat('price', prices.length ? formatGs(Math.min(...prices)) : '—');
      setLiveStat('offers', formatNumber(offers));
      setLiveStat('stock', String(inStock));
      const queryLabel = livePanel.querySelector('[data-live-query]');
      if (queryLabel) queryLabel.textContent = q ? `“${q}”` : 'tu búsqueda';

      const allLink = livePanel.querySelector('[data-live-all]');
      if (allLink) allLink.setAttribute('href', searchUrl);

      setLiveLoading(false);
      livePanel.classList.toggle('is-empty', !items.length);

      if (!items.length) {
        liveBox.innerHTML = `
          <div class="hl-empty">
            <span class="hl-empty-icon" aria-hidden="true"><i data-lucide="search-x"></i></span>
            <div class="hl-empty-copy">
              <h3 class="hl-empty-title">Sin coincidencias para “${escapeHtml(q)}”</h3>
              <p>Revisá la ortografía o probá con una marca, una categoría o el nombre de una tienda.</p>
            </div>
            <a class="hl-empty-link" href="${escapeHtml(searchUrl)}">
              Buscar en todo el catálogo
              <i data-lucide="arrow-right"></i>
            </a>
          </div>
        `;
        if (stateBox) stateBox.textContent = 'Sin coincidencias.';
        if (window.refreshLucideIcons) refreshLucideIcons();
        return;
      }

      liveBox.innerHTML = items.map((item, index) => {
        const name = item.nombre || 'Producto';
        const url = escapeHtml(appendQueryToUrl(item.url || '#', q));
        const offersCount = Number(item.ofertas) || 0;
        const stock = Boolean(item.en_stock);
        const media = item.imagen
          ? `<img src="${escapeHtml(item.imagen)}" alt="${escapeHtml(name)}" loading="lazy" onerror="this.onerror=null;if(window.applyProductImageFallback){applyProductImageFallback(this);}else{this.style.display='none';}">`
          : `<span class="img-fallback" role="img" aria-label="${escapeHtml(name)}"><i data-lucide="image"></i></span>`;

        return `
          <article
            class="sr-card clickable-card hl-card"
            data-tone="${stock ? 'stock' : 'wait'}"
            style="--tile-wash: ${liveTileWash[index % liveTileWash.length]}; --hl-delay: ${index * 40}ms;"
            data-card-href="${url}"
            role="link"
            tabindex="0"
            aria-label="Comparar ${escapeHtml(name)}"
          >
            <a class="sr-card-media" href="${url}">
              ${media}
              <span class="sr-card-index">${String(index + 1).padStart(2, '0')}</span>
              <span class="sr-card-flag ${stock ? 'is-stock' : 'is-wait'}">${stock ? 'Stock' : 'Consultar'}</span>
            </a>
            <div class="sr-card-body">
              <div class="sr-card-tags">
                <span class="sr-card-cat">${escapeHtml(item.categoria || 'Sin categoría')}</span>
                ${item.marca ? `<span class="sr-card-brand">${escapeHtml(item.marca)}</span>` : ''}
              </div>
              <h3 class="sr-card-name"><a href="${url}">${highlightMatch(name, q)}</a></h3>
              <div class="sr-card-priceblock">
                <div>
                  <p class="sr-card-from">Desde</p>
                  <p class="sr-card-price">${escapeHtml(item.precio_fmt || item.precio || '')}</p>
                </div>
              </div>
              <div class="sr-card-foot">
                <span class="sr-card-fresh">
                  <i data-lucide="store"></i>
                  ${offersCount} oferta${offersCount === 1 ? '' : 's'}
                </span>
                <a class="sr-card-cta" href="${url}">
                  Comparar
                  <i data-lucide="arrow-right"></i>
                </a>
              </div>
            </div>
          </article>
        `;
      }).join('');

      if (stateBox) stateBox.textContent = `${items.length} resultado(s) actualizados.`;
      if (window.refreshLucideIcons) refreshLucideIcons();
    }

    function setLiveStat(key, value) {
      const node = livePanel.querySelector(`[data-live-stat="${key}"]`);
      if (node) node.textContent = value;
    }

    function formatNumber(value) {
      return String(Math.round(value)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function formatGs(value) {
      return 'Gs. ' + formatNumber(value);
    }

    function highlightMatch(text, q) {
      const needle = (q || '').trim();
      if (needle.length < 2) return escapeHtml(text);

      const pattern = new RegExp(`(${needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
      return String(text).split(pattern).map((part, i) => (
        i % 2 ? `<mark class="hl-mark">${escapeHtml(part)}</mark>` : escapeHtml(part)
      )).join('');
    }

    function itemRow(icon, label, meta) {
      const tone = icon === 'flame' ? 'hot' : (icon === 'history' ? 'history' : 'hit');
      return `
        <button type="button" class="search-suggest-item" data-tone="${tone}" data-search-value="${escapeHtml(label || '')}" data-search-action="submit">
          <span class="search-suggest-icon" aria-hidden="true"><i data-lucide="${escapeHtml(icon)}"></i></span>
          <span class="search-suggest-copy">
            <span class="search-suggest-label">${escapeHtml(label || '')}</span>
            ${meta ? `<span class="search-suggest-meta">${escapeHtml(meta)}</span>` : ''}
          </span>
          <span class="search-suggest-go" aria-hidden="true"><i data-lucide="corner-down-left"></i></span>
        </button>
      `;
    }

    function sectionTitle(text) {
      return `<div class="search-suggest-section"><span>${escapeHtml(text)}</span></div>`;
    }

    function iconByType(type) {
      switch (type) {
        case 'producto': return 'package';
        case 'marca': return 'bookmark';
        case 'categoria': return 'layout-grid';
        case 'tienda': return 'store';
        case 'popular':
        case 'trending': return 'flame';
        case 'history': return 'history';
        default: return 'search';
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