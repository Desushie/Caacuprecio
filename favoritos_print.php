<?php
require_once __DIR__ . '/config.php';

require_login();

$pdo = db();
$user = current_user();
$userId = current_user_id();

$qtyInput = $_POST['qty'] ?? [];
$qtyMap = [];
foreach ($qtyInput as $productId => $qty) {
    $qtyMap[(int) $productId] = max(1, min(999, (int) $qty));
}

$stmt = $pdo->prepare("
    SELECT
        p.idproductos,
        p.pro_nombre,
        p.pro_precio,
        p.pro_imagen,
        p.pro_fecha_scraping,
        t.tie_nombre,
        c.cat_nombre
    FROM favoritos f
    INNER JOIN productos p ON p.idproductos = f.productos_idproductos
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE f.usuario_idusuario = :uid
      AND p.pro_activo = 1
    ORDER BY p.pro_nombre ASC
");
$stmt->execute([':uid' => $userId]);
$items = $stmt->fetchAll();

$totalItems = count($items);
$totalQty = 0;
$subtotal = 0.0;

foreach ($items as &$item) {
    $itemId = (int) $item['idproductos'];
    $qty = $qtyMap[$itemId] ?? 1;
    $item['qty'] = $qty;
    $item['line_total'] = (float) $item['pro_precio'] * $qty;
    $item['img_url'] = image_url($item['pro_imagen'], (string) $item['pro_nombre']);
    $subtotal += $item['line_total'];
    $totalQty += $qty;
}
unset($item);

$clientName = (string) ($user['usu_nombre'] ?? 'Usuario');
$printedAt = date('d/m/Y H:i');

$thumbFallbackSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(page_title('Favoritos para imprimir')) ?></title>
  <link rel="icon" type="image/png" href="./img/icono.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --font-sans: Poppins, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      --brand: #3f6ca8;
      --brand-strong: #4372B3;
      --brand-deep: #32498C;
      --accent: #F29F05;
      --bg: #ffffff;
      --bg-soft: #F3F5F8;
      --text: #1c1a22;
      --text-soft: #6b6578;
      --border: rgba(28, 26, 34, 0.11);
      --border-strong: rgba(50, 73, 140, 0.24);
      --radius: 0.7rem;
      --page-gutter: clamp(1rem, 2.8vw, 1.75rem);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: var(--font-sans);
      color: var(--text);
      background: var(--bg-soft);
      font-size: 0.9375rem;
      line-height: 1.5;
      letter-spacing: -0.011em;
      -webkit-font-smoothing: antialiased;
    }

    .sheet {
      max-width: 68rem;
      margin: 0 auto;
      padding: var(--page-gutter);
    }

    .doc {
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: calc(var(--radius) + 0.15rem);
      overflow: hidden;
    }

    .actions {
      display: flex;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 0.6rem;
      margin-bottom: 1rem;
    }

    .btn {
      appearance: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      min-height: 2.55rem;
      padding: 0.55rem 1.15rem;
      border: 1px solid var(--border-strong);
      border-radius: var(--radius);
      background: var(--bg);
      color: var(--brand-deep);
      font: inherit;
      font-size: 0.875rem;
      font-weight: 600;
      letter-spacing: -0.01em;
      cursor: pointer;
      text-decoration: none;
    }

    .btn:hover,
    .btn:focus-visible {
      border-color: var(--brand);
      color: var(--brand-deep);
      outline: none;
    }

    .btn-primary {
      background: var(--brand-strong);
      border-color: var(--brand-strong);
      color: #fff;
    }

    .btn-primary:hover,
    .btn-primary:focus-visible {
      background: var(--brand-deep);
      border-color: var(--brand-deep);
      color: #fff;
    }

    .doc-header {
      display: grid;
      grid-template-columns: minmax(0, 1.25fr) minmax(16.5rem, 0.95fr);
      gap: 1.25rem 2rem;
      padding: 1.35rem 1.5rem 1.25rem;
      border-bottom: 1px solid var(--border);
      align-items: start;
    }

    .brand-mark {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 0.85rem;
    }

    .brand-badge {
      width: 3.15rem;
      height: 3.15rem;
      flex: 0 0 3.15rem;
      display: block;
      border-radius: var(--radius);
      object-fit: contain;
      background: var(--bg-soft);
      border: 1px solid var(--border);
    }

    .brand-name {
      margin: 0;
      font-size: 1.35rem;
      font-weight: 700;
      letter-spacing: -0.03em;
      color: var(--brand-deep);
      line-height: 1.15;
    }

    .brand-tag {
      margin: 0.1rem 0 0;
      font-size: 0.75rem;
      font-weight: 500;
      color: var(--text-soft);
      letter-spacing: 0.02em;
    }

    .doc-title {
      margin: 0 0 0.35rem;
      font-size: 1.05rem;
      font-weight: 600;
      letter-spacing: -0.02em;
      color: var(--text);
      line-height: 1.3;
    }

    .doc-lead {
      margin: 0;
      color: var(--text-soft);
      font-size: 0.875rem;
      max-width: 36rem;
    }

    .meta-panel {
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: var(--bg-soft);
      padding: 0.85rem 1rem;
    }

    .meta-list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 0.55rem;
    }

    .meta-list li {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      gap: 0.65rem;
      align-items: baseline;
      font-size: 0.8125rem;
    }

    .meta-list .label {
      color: var(--text-soft);
      font-weight: 500;
      white-space: nowrap;
    }

    .meta-list .value {
      color: var(--text);
      font-weight: 600;
      text-align: right;
      white-space: nowrap;
    }

    .meta-list .value.is-total {
      color: var(--brand-deep);
      font-size: 0.9375rem;
    }

    .summary {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      border-bottom: 1px solid var(--border);
    }

    .summary-item {
      padding: 1rem 1.35rem;
      border-right: 1px solid var(--border);
      background: var(--bg);
    }

    .summary-item:last-child {
      border-right: none;
    }

    .summary-item .label {
      display: block;
      margin-bottom: 0.3rem;
      font-size: 0.75rem;
      font-weight: 500;
      color: var(--text-soft);
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    .summary-item .value {
      display: block;
      font-size: 1.2rem;
      font-weight: 700;
      letter-spacing: -0.025em;
      color: var(--text);
      line-height: 1.2;
    }

    .summary-item.is-total .value {
      color: var(--brand-deep);
    }

    .table-wrap {
      padding: 0;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 40rem;
    }

    thead th {
      background: var(--bg-soft);
      border-bottom: 1px solid var(--border-strong);
      padding: 0.8rem 1.1rem;
      text-align: left;
      font-size: 0.72rem;
      font-weight: 600;
      color: var(--brand-deep);
      letter-spacing: 0.045em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    tbody td,
    tfoot td {
      border-bottom: 1px solid var(--border);
      padding: 0.95rem 1.1rem;
      vertical-align: middle;
      font-size: 0.875rem;
    }

    tbody tr:last-child td {
      border-bottom: none;
    }

    .product {
      display: flex;
      gap: 0.9rem;
      align-items: flex-start;
      min-width: 0;
    }

    .thumb-wrap {
      position: relative;
      width: 3.5rem;
      height: 3.5rem;
      flex: 0 0 3.5rem;
      border: 1px solid var(--border);
      border-radius: 0.55rem;
      background: var(--bg-soft);
      overflow: hidden;
    }

    .thumb-wrap img {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      z-index: 1;
      background: var(--bg);
    }

    .img-fallback {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #94a3b8;
      background: #ffffff;
      z-index: 0;
    }

    .img-fallback[hidden] {
      display: none !important;
    }

    .img-fallback svg {
      width: 1.55rem;
      height: 1.55rem;
    }

    .name {
      font-weight: 600;
      font-size: 0.9rem;
      letter-spacing: -0.015em;
      line-height: 1.35;
      margin: 0 0 0.3rem;
      color: var(--text);
    }

    .meta-line {
      color: var(--text-soft);
      font-size: 0.75rem;
      line-height: 1.45;
    }

    .store-chip {
      display: inline-flex;
      align-items: center;
      max-width: 100%;
      padding: 0.28rem 0.6rem;
      border: 1px solid var(--border);
      border-radius: 0.45rem;
      background: var(--bg-soft);
      color: var(--text);
      font-size: 0.8125rem;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .num {
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
    }

    .text-end { text-align: right; }
    .text-center { text-align: center; }

    tfoot td {
      background: var(--bg-soft);
      border-bottom: none;
      border-top: 1px solid var(--border-strong);
      font-weight: 700;
      color: var(--brand-deep);
      padding-top: 1rem;
      padding-bottom: 1rem;
    }

    .doc-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
      padding: 0.95rem 1.35rem 1.15rem;
      border-top: 1px solid var(--border);
      color: var(--text-soft);
      font-size: 0.75rem;
    }

    .doc-footer-brand {
      display: inline-flex;
      align-items: center;
      gap: 0.2rem;
      color: var(--text-soft);
    }

    .doc-footer-logo {
      display: block;
      height: 1.65rem;
      width: auto;
      margin-left: -0.15rem;
    }

    .empty {
      margin: 1.5rem;
      padding: 2rem 1.25rem;
      text-align: center;
      border: 1px dashed var(--border-strong);
      border-radius: var(--radius);
      color: var(--text-soft);
      background: var(--bg-soft);
    }

    .empty strong {
      display: block;
      margin-bottom: 0.35rem;
      color: var(--text);
      font-size: 1rem;
    }

    @media screen and (max-width: 760px) {
      .doc-header {
        grid-template-columns: 1fr;
      }

      .meta-panel {
        max-width: none;
      }

      .meta-list li {
        grid-template-columns: 1fr auto;
      }

      .summary {
        grid-template-columns: 1fr;
      }

      .summary-item {
        border-right: none;
        border-bottom: 1px solid var(--border);
      }

      .summary-item:last-child {
        border-bottom: none;
      }
    }

    @media print {
      body {
        background: #fff !important;
        padding: 0;
        color: #1c1a22;
      }

      .sheet {
        max-width: none;
        padding: 0;
      }

      .actions {
        display: none !important;
      }

      .doc {
        border: none;
        border-radius: 0;
        overflow: visible;
      }

      .doc-header {
        display: grid !important;
        grid-template-columns: minmax(0, 1.25fr) minmax(16rem, 0.9fr) !important;
        gap: 1rem 1.5rem;
        padding: 0 0 0.9rem;
        border-bottom: 1px solid rgba(28, 26, 34, 0.14);
        align-items: start;
      }

      .meta-list .value {
        white-space: nowrap;
      }

      .brand-mark {
        margin-bottom: 0.65rem;
      }

      .brand-badge {
        width: 2.75rem;
        height: 2.75rem;
        flex-basis: 2.75rem;
      }

      .meta-panel {
        background: #f3f5f8 !important;
        border: 1px solid rgba(28, 26, 34, 0.14);
      }

      .summary {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        border-bottom: 1px solid rgba(28, 26, 34, 0.14);
      }

      .summary-item {
        border-right: 1px solid rgba(28, 26, 34, 0.14) !important;
        border-bottom: none !important;
        padding: 0.75rem 1rem;
        background: #fff !important;
      }

      .summary-item:last-child {
        border-right: none !important;
      }

      .table-wrap {
        overflow: visible;
      }

      table {
        min-width: 0 !important;
        width: 100%;
      }

      thead th {
        background: #f3f5f8 !important;
      }

      tbody td,
      thead th,
      tfoot td {
        padding: 0.7rem 0.75rem;
      }

      .thumb-wrap {
        width: 2.75rem;
        height: 2.75rem;
        flex-basis: 2.75rem;
      }

      .store-chip {
        background: #f3f5f8 !important;
        border: 1px solid rgba(28, 26, 34, 0.14);
      }

      tfoot td {
        background: #f3f5f8 !important;
      }

      .doc-footer {
        padding: 0.75rem 0 0;
        border-top: 1px solid rgba(28, 26, 34, 0.14);
      }

      thead th,
      tfoot td,
      .summary-item,
      .meta-panel,
      .thumb-wrap,
      .store-chip,
      .img-fallback,
      .brand-badge,
      .doc-footer-logo {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
      }

      tbody tr,
      .doc-header,
      .summary {
        break-inside: avoid;
      }

      @page {
        size: A4 portrait;
        margin: 12mm;
      }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="actions">
      <button type="button" class="btn" onclick="window.close()">Cerrar</button>
      <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir / Guardar PDF</button>
    </div>

    <article class="doc">
      <header class="doc-header">
        <div>
          <div class="brand-mark">
            <img class="brand-badge" src="./img/icono.png" alt="" width="50" height="50" decoding="async">
            <div>
              <p class="brand-name">Caacuprecio</p>
              <p class="brand-tag">Comparador de precios</p>
            </div>
          </div>
          <h1 class="doc-title">Lista de favoritos</h1>
          <p class="doc-lead">Resumen para comparar y cotizar productos guardados.</p>
        </div>

        <aside class="meta-panel" aria-label="Datos del documento">
          <ul class="meta-list">
            <li>
              <span class="label">Cliente</span>
              <span class="value"><?= e($clientName) ?></span>
            </li>
            <li>
              <span class="label">Fecha</span>
              <span class="value"><?= e($printedAt) ?></span>
            </li>
            <li>
              <span class="label">Total estimado</span>
              <span class="value is-total"><?= gs($subtotal) ?></span>
            </li>
          </ul>
        </aside>
      </header>

      <section class="summary" aria-label="Resumen">
        <div class="summary-item">
          <span class="label">Productos</span>
          <span class="value"><?= number_format($totalItems, 0, ',', '.') ?></span>
        </div>
        <div class="summary-item">
          <span class="label">Cantidades</span>
          <span class="value"><?= number_format($totalQty, 0, ',', '.') ?></span>
        </div>
        <div class="summary-item is-total">
          <span class="label">Total</span>
          <span class="value"><?= gs($subtotal) ?></span>
        </div>
      </section>

      <?php if ($items): ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th scope="col">Producto</th>
                <th scope="col">Tienda</th>
                <th scope="col" class="text-end">Precio</th>
                <th scope="col" class="text-center">Cantidad</th>
                <th scope="col" class="text-end">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <?php
                  $productName = (string) $item['pro_nombre'];
                  $imgUrl = (string) ($item['img_url'] ?? '');
                  $scrapedAt = $item['pro_fecha_scraping']
                    ? date('d/m/Y H:i', strtotime((string) $item['pro_fecha_scraping']))
                    : '—';
                ?>
                <tr>
                  <td>
                    <div class="product">
                      <div class="thumb-wrap<?= $imgUrl === '' ? ' is-fallback' : '' ?>">
                        <span class="img-fallback" role="img" aria-label="Sin imagen"<?= $imgUrl !== '' ? ' hidden' : '' ?>>
                          <?= $thumbFallbackSvg ?>
                        </span>
                        <?php if ($imgUrl !== ''): ?>
                          <img
                            src="<?= e($imgUrl) ?>"
                            alt=""
                            loading="lazy"
                            decoding="async"
                            data-print-thumb
                          >
                        <?php endif; ?>
                      </div>
                      <div>
                        <p class="name"><?= e($productName) ?></p>
                        <div class="meta-line"><?= e($item['cat_nombre'] ?? 'Sin categoría') ?></div>
                        <div class="meta-line">Actualizado: <?= e($scrapedAt) ?></div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="store-chip"><?= e($item['tie_nombre']) ?></span>
                  </td>
                  <td class="text-end num"><?= gs($item['pro_precio']) ?></td>
                  <td class="text-center num"><?= number_format((int) $item['qty'], 0, ',', '.') ?></td>
                  <td class="text-end num"><?= gs($item['line_total']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="text-end">Total general</td>
                <td class="text-center num"><?= number_format($totalQty, 0, ',', '.') ?></td>
                <td class="text-end num"><?= gs($subtotal) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php else: ?>
        <div class="empty">
          <strong>Sin productos para imprimir</strong>
          No hay favoritos activos en tu lista.
        </div>
      <?php endif; ?>

      <footer class="doc-footer">
        <span class="doc-footer-brand">
          Generado desde
          <img class="doc-footer-logo" src="./img/logo_horizontal.webp" alt="Caacuprecio" width="140" height="26" decoding="async">
        </span>
        <span><?= e($printedAt) ?> · <?= number_format($totalItems, 0, ',', '.') ?> producto<?= $totalItems === 1 ? '' : 's' ?></span>
      </footer>
    </article>
  </div>

  <script>
    (function () {
      function showFallback(img) {
        if (!img || img.dataset.fallbackApplied === '1') return;
        img.dataset.fallbackApplied = '1';
        var wrap = img.parentElement;
        var fallback = wrap ? wrap.querySelector('.img-fallback') : null;
        img.remove();
        if (wrap) wrap.classList.add('is-fallback');
        if (fallback) {
          fallback.hidden = false;
          fallback.removeAttribute('hidden');
        }
      }

      document.querySelectorAll('img[data-print-thumb]').forEach(function (img) {
        var src = (img.getAttribute('src') || '').trim();
        if (!src) {
          showFallback(img);
          return;
        }
        img.addEventListener('error', function () {
          showFallback(img);
        });
        if (img.complete && img.naturalWidth === 0) {
          showFallback(img);
        }
      });
    })();
  </script>
</body>
</html>
