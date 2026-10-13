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
        p.pro_descripcion,
        p.pro_precio,
        p.pro_precio_anterior,
        p.pro_imagen,
        p.pro_en_stock,
        p.pro_url,
        p.pro_fecha_scraping,
        COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo,
        t.idtiendas,
        t.tie_nombre,
        c.cat_nombre
    FROM favoritos f
    INNER JOIN productos p ON p.idproductos = f.productos_idproductos
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE f.usuario_idusuario = :uid
      AND p.pro_activo = 1
    ORDER BY p.pro_nombre ASC, t.tie_nombre ASC
");
$stmt->execute([':uid' => $userId]);
$items = $stmt->fetchAll();

/**
 * Mejor precio de cada grupo entre todas las tiendas
 */
$bestByGroup = [];
$groupStores = [];
$groupNames = array_values(array_unique(array_filter(array_map(
    static fn(array $row): string => (string) ($row['grupo'] ?? ''),
    $items
))));

if ($groupNames) {
    $groupPlaceholders = implode(',', array_fill(0, count($groupNames), '?'));
    $bestStmt = $pdo->prepare("
        SELECT
            p.idproductos,
            p.pro_precio,
            t.idtiendas,
            t.tie_nombre,
            COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        WHERE p.pro_activo = 1
          AND p.pro_precio IS NOT NULL
          AND COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) IN ($groupPlaceholders)
        ORDER BY p.pro_precio ASC, p.pro_fecha_scraping DESC
    ");
    $bestStmt->execute($groupNames);

    foreach ($bestStmt->fetchAll() as $offer) {
        $groupKey = (string) $offer['grupo'];
        $groupStores[$groupKey][(int) $offer['idtiendas']] = true;
        if (!isset($bestByGroup[$groupKey])) {
            $bestByGroup[$groupKey] = $offer;
        }
    }
}

$totalItems = count($items);
$subtotal = 0.0;
$totalQty = 0;
$totalSaving = 0.0;
$hasSavings = false;
$possibleSaving = 0.0;
$hasCheaper = false;
$storeTotals = [];

foreach ($items as &$item) {
    $itemId = (int) $item['idproductos'];
    $qty = $qtyMap[$itemId] ?? 1;
    $price = $item['pro_precio'] !== null ? (float) $item['pro_precio'] : null;
    $oldPrice = $item['pro_precio_anterior'] !== null ? (float) $item['pro_precio_anterior'] : null;
    $groupKey = (string) $item['grupo'];
    $best = $bestByGroup[$groupKey] ?? null;

    $item['qty'] = $qty;
    $item['has_price'] = $price !== null;
    $item['line_total'] = ($price ?? 0.0) * $qty;
    $item['old_price'] = ($price !== null && $price > 0 && $oldPrice !== null && $oldPrice > $price) ? $oldPrice : null;
    $item['discount'] = $item['old_price'] !== null ? (int) round((1 - $price / $item['old_price']) * 100) : 0;
    $item['store_count'] = count($groupStores[$groupKey] ?? []);
    $item['cheaper'] = null;
    $item['is_best'] = false;

    if ($price !== null && $best !== null) {
        $bestPrice = (float) $best['pro_precio'];
        if ($bestPrice < $price && (int) $best['idtiendas'] !== (int) $item['idtiendas']) {
            $item['cheaper'] = [
                'id' => (int) $best['idproductos'],
                'price' => $bestPrice,
                'store' => (string) $best['tie_nombre'],
            ];
            $hasCheaper = true;
            $possibleSaving += ($price - $bestPrice) * $qty;
        } elseif ($item['store_count'] > 1) {
            $item['is_best'] = true;
        }
    }

    if ($item['old_price'] !== null) {
        $hasSavings = true;
        $totalSaving += ($item['old_price'] - $price) * $qty;
    }

    $storeId = (int) $item['idtiendas'];
    if (!isset($storeTotals[$storeId])) {
        $storeTotals[$storeId] = [
            'name' => (string) $item['tie_nombre'],
            'qty' => 0,
            'total' => 0.0,
            'items' => [],
        ];
    }
    $storeTotals[$storeId]['qty'] += $qty;
    $storeTotals[$storeId]['total'] += $item['line_total'];
    $storeTotals[$storeId]['items'][] = $item;

    $subtotal += $item['line_total'];
    $totalQty += $qty;
}
unset($item);

$storeGroups = $storeTotals;
uasort($storeGroups, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
uasort($storeTotals, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
$totalStores = count($storeTotals);
$userName = (string) ($user['usu_nombre'] ?? 'Usuario');

render_head('Favoritos');
render_navbar('favoritos');
?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="fav-page page-section" aria-labelledby="fav-title">
  <div class="container position-relative z-1">
    <nav aria-label="breadcrumb" class="pd-crumb">
      <ol class="breadcrumb custom-breadcrumb pd-crumb-list mb-0">
        <li class="breadcrumb-item">
          <a href="index.php" class="pd-crumb-link">
            <?= lucide_icon('home', '', 14) ?>
            <span>Inicio</span>
          </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current">Favoritos</span>
        </li>
      </ol>
    </nav>

    <?php if ($items): ?>
      <form method="post" action="favoritos.php" class="fv-layout" data-fv-form data-fv-user="<?= (int) $userId ?>">
        <section class="fv-planner">
          <header class="fv-planner-head">
            <div class="fv-planner-copy">
              <p class="pd-kicker">Planificador de compra</p>
              <h1 id="fav-title" class="fv-title">Mis favoritos</h1>
              <p class="fv-lead">Marca cada producto cuando lo tengas, filtra por tienda y revisa si hay un precio mejor antes de salir.</p>
            </div>

            <div class="fv-progress is-empty" role="status" aria-live="polite" style="--fv-pct: 0">
              <div class="fv-progress-ring" aria-hidden="true">
                <svg class="fv-ring" viewBox="0 0 48 48" focusable="false">
                  <circle class="fv-ring-track" cx="24" cy="24" r="21" pathLength="100" />
                  <circle class="fv-ring-fill" cx="24" cy="24" r="21" pathLength="100" />
                </svg>
                <span class="fv-ring-icon"><?= lucide_icon('shopping-cart', '', 18) ?></span>
              </div>
              <div class="fv-progress-copy">
                <span class="fv-progress-label">En tu lista</span>
                <strong class="fv-progress-value">
                  <span data-fv-done>0</span><span class="fv-progress-of">de <?= (int) $totalItems ?></span>
                </strong>
              </div>
            </div>
          </header>

          <div class="fv-toolbar">
            <div class="fv-chips-wrap" data-fv-chips-wrap>
              <button type="button" class="fv-chips-nav is-prev" data-fv-chips-nav="-1" aria-label="Ver tiendas anteriores" hidden>
                <?= lucide_icon('chevron-left', '', 16) ?>
              </button>
              <div class="fv-chips" role="tablist" aria-label="Filtrar por tienda" data-fv-chips>
                <button type="button" class="fv-chip is-active" role="tab" aria-selected="true" data-fv-filter="all">
                  Todas <span class="fv-chip-count"><?= (int) $totalItems ?></span>
                </button>
                <?php foreach ($storeGroups as $storeId => $group): ?>
                  <button type="button" class="fv-chip" role="tab" aria-selected="false" data-fv-filter="<?= (int) $storeId ?>">
                    <?= e($group['name']) ?> <span class="fv-chip-count"><?= count($group['items']) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <button type="button" class="fv-chips-nav is-next" data-fv-chips-nav="1" aria-label="Ver más tiendas" hidden>
                <?= lucide_icon('chevron-right', '', 16) ?>
              </button>
            </div>

            <div class="fv-toolbar-row">
              <div class="fv-toolbar-main">
                <div class="fv-search">
                  <?= lucide_icon('search', 'fv-search-icon', 15) ?>
                  <input
                    type="search"
                    class="fv-search-input"
                    placeholder="Buscar en tu lista…"
                    autocomplete="off"
                    aria-label="Buscar productos en tu lista"
                    data-fv-search
                  >
                  <button type="button" class="fv-search-clear" data-fv-search-clear aria-label="Borrar búsqueda" hidden>
                    <?= lucide_icon('x', '', 14) ?>
                  </button>
                </div>

                <button type="button" class="fv-foot-btn fv-clear-btn is-danger" data-bs-toggle="modal" data-bs-target="#fvClearModal">
                  <?= lucide_icon('list-restart', '', 14) ?>Limpiar lista
                </button>
              </div>

              <label class="fv-switch">
                <input type="checkbox" data-fv-hide-done>
                <span class="fv-switch-track" aria-hidden="true"><span class="fv-switch-thumb"></span></span>
                <span>Ocultar marcados</span>
              </label>
            </div>
          </div>

          <div class="fv-cols" aria-hidden="true">
            <span></span>
            <span class="fv-cols-name">Producto</span>
            <span class="is-center">Cantidad</span>
            <span class="is-end">Subtotal</span>
            <span></span>
          </div>

              <ul class="fv-rows">
                <?php foreach ($items as $item): ?>
                  <?php
                    $itemId = (int) $item['idproductos'];
                  ?>
                  <li
                    class="fv-row"
                    data-fv-item="<?= $itemId ?>"
                    data-price="<?= $item['has_price'] ? e((string) (float) $item['pro_precio']) : '' ?>"
                    data-old-price="<?= $item['old_price'] !== null ? e((string) $item['old_price']) : '' ?>"
                    data-best-price="<?= $item['cheaper'] !== null ? e((string) $item['cheaper']['price']) : '' ?>"
                    data-store="<?= (int) $item['idtiendas'] ?>"
                    data-search="<?= e($item['pro_nombre'] . ' ' . $item['tie_nombre'] . ' ' . ($item['cat_nombre'] ?? '')) ?>"
                  >
                    <label class="fv-check" title="Marcar como comprado">
                      <input type="checkbox" class="visually-hidden" data-fv-check aria-label="Marcar <?= e($item['pro_nombre']) ?> como comprado">
                      <span class="fv-check-box" aria-hidden="true"><?= lucide_icon('check', '', 13) ?></span>
                    </label>

                    <a href="producto.php?id=<?= $itemId ?>" class="fv-thumb" tabindex="-1" aria-hidden="true">
                      <img src="<?= e(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>" alt="" loading="lazy" decoding="async">
                      <?php if ($item['discount'] > 0): ?>
                        <span class="fv-thumb-deal">−<?= (int) $item['discount'] ?>%</span>
                      <?php endif; ?>
                    </a>

                    <div class="fv-prod">
                      <div class="fv-prod-top">
                        <a href="tienda.php?id=<?= (int) $item['idtiendas'] ?>" class="fv-prod-store" title="Ver tienda">
                          <?= lucide_icon('store', '', 13) ?><span><?= e($item['tie_nombre']) ?></span>
                        </a>
                        <span aria-hidden="true">·</span>
                        <span class="fv-prod-cat"><?= e($item['cat_nombre'] ?? 'Sin categoría') ?></span>
                      </div>
                      <a href="producto.php?id=<?= $itemId ?>" class="fv-prod-name" title="<?= e($item['pro_nombre']) ?>"><?= e($item['pro_nombre']) ?></a>
                      <?php if ($item['cheaper'] !== null || $item['is_best']): ?>
                        <div class="fv-prod-tags">
                          <?php if ($item['cheaper'] !== null): ?>
                            <a href="producto.php?id=<?= (int) $item['cheaper']['id'] ?>" class="fv-hint is-cheaper" title="Más barato en <?= e($item['cheaper']['store']) ?>">
                              <?= lucide_icon('trending-down', '', 13) ?>
                              <span><strong><?= gs($item['cheaper']['price']) ?></strong> en <?= e($item['cheaper']['store']) ?></span>
                            </a>
                          <?php elseif ($item['is_best']): ?>
                            <span class="fv-hint is-best">
                              <?= lucide_icon('badge-check', '', 13) ?>
                              <span>Menor precio · <?= (int) $item['store_count'] ?> tiendas</span>
                            </span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="fv-row-calc">
                      <div class="fv-qty-cell">
                        <div class="fv-qty" role="group" aria-label="Cantidad de <?= e($item['pro_nombre']) ?>">
                          <button type="button" class="fv-qty-btn" data-fv-step="-1" aria-label="Restar una unidad"<?= $item['qty'] <= 1 ? ' disabled' : '' ?>>
                            <?= lucide_icon('minus', '', 14) ?>
                          </button>
                          <input
                            type="number"
                            min="1"
                            max="999"
                            inputmode="numeric"
                            name="qty[<?= $itemId ?>]"
                            value="<?= (int) $item['qty'] ?>"
                            class="fv-qty-input"
                            aria-label="Cantidad"
                            data-fv-qty
                          >
                          <button type="button" class="fv-qty-btn" data-fv-step="1" aria-label="Sumar una unidad"<?= $item['qty'] >= 999 ? ' disabled' : '' ?>>
                            <?= lucide_icon('plus', '', 14) ?>
                          </button>
                        </div>
                        <?php if ($item['has_price']): ?>
                          <span class="fv-unit"><?= gs_precio($item['pro_precio']) ?> c/u</span>
                        <?php endif; ?>
                      </div>

                      <div class="fv-sub">
                        <span class="fv-cell-label">Subtotal</span>
                        <strong class="fv-sub-value" data-fv-line><?= $item['has_price'] ? gs($item['line_total']) : 'Consultar' ?></strong>
                        <?php if ($item['old_price'] !== null): ?>
                          <s class="fv-sub-old" data-fv-old-line><?= gs($item['old_price'] * $item['qty']) ?></s>
                        <?php endif; ?>
                      </div>
                    </div>

                    <a
                      href="<?= e(favorite_toggle_url($itemId, 'favoritos.php')) ?>"
                      class="fv-remove"
                      title="Quitar de favoritos"
                      aria-label="Quitar <?= e($item['pro_nombre']) ?> de favoritos"
                    >
                      <?= lucide_icon('trash-2', '', 15) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>

          <nav class="fv-pager" aria-label="Paginación de favoritos" data-fv-pager hidden>
            <span class="fv-pager-info" data-fv-pager-info></span>
            <div class="fv-pager-nav">
              <button type="button" class="fv-pager-btn" data-fv-page="prev" aria-label="Página anterior">
                <?= lucide_icon('chevron-left', '', 15) ?>
              </button>
              <div class="fv-pager-pages" data-fv-pager-pages></div>
              <button type="button" class="fv-pager-btn" data-fv-page="next" aria-label="Página siguiente">
                <?= lucide_icon('chevron-right', '', 15) ?>
              </button>
            </div>
          </nav>

          <div class="fv-done-state" data-fv-empty hidden>
            <span class="fv-done-icon"><?= lucide_icon('party-popper', '', 20) ?></span>
            <strong>¡Todo listo!</strong>
            <span>Marcaste todos los productos de esta vista.</span>
          </div>

          <div class="fv-done-state is-search" data-fv-no-results hidden>
            <span class="fv-done-icon"><?= lucide_icon('search-x', '', 20) ?></span>
            <strong>Sin coincidencias</strong>
            <span>No hay productos en tu lista que coincidan con “<span data-fv-no-results-term></span>”.</span>
          </div>

          <footer class="fv-planner-foot">
            <button type="button" class="fv-foot-btn fv-toggle-all" data-fv-toggle-all>
              <span class="fv-toggle-all-on"><?= lucide_icon('list-checks', '', 14) ?>Marcar todo</span>
              <span class="fv-toggle-all-off"><?= lucide_icon('rotate-ccw', '', 14) ?>Desmarcar todo</span>
            </button>
            <button type="button" class="fv-foot-btn is-primary" data-bs-toggle="modal" data-bs-target="#fvAddModal">
              <?= lucide_icon('plus', '', 14) ?>Agregar productos
            </button>
          </footer>
        </section>

        <aside class="fv-ticket" aria-labelledby="fv-ticket-title">
          <div class="fv-ticket-head">
            <span class="pd-lane-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-guarani" aria-hidden="true"><path d="M19 12a7 7 0 1 1-2.05-4.95"/><path d="M19 12h-6"/><path d="M12 2v20"/></svg></span>
            <div>
              <h2 id="fv-ticket-title" class="pd-lane-title">Resumen de compra</h2>
              <p class="pd-lane-caption"><?= e(date('d/m/Y · H:i')) ?></p>
            </div>
          </div>

          <div class="fv-ticket-block">
            <p class="pd-kicker">Por tienda</p>
            <ul class="fv-store-list">
              <?php foreach ($storeTotals as $storeId => $store): ?>
                <li>
                  <span class="fv-store-copy">
                    <a href="tienda.php?id=<?= (int) $storeId ?>" class="fv-store-name"><?= e($store['name']) ?></a>
                    <span class="fv-store-units" data-fv-store-qty="<?= (int) $storeId ?>">
                      <?= (int) $store['qty'] ?> unidad<?= $store['qty'] === 1 ? '' : 'es' ?>
                    </span>
                  </span>
                  <span class="fv-store-total" data-fv-store-total="<?= (int) $storeId ?>"><?= gs($store['total']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <div class="fv-ticket-cut" aria-hidden="true"></div>

          <dl class="fv-ticket-rows">
            <div>
              <dt>Productos</dt>
              <dd><?= number_format($totalItems, 0, ',', '.') ?></dd>
            </div>
            <div>
              <dt>Unidades</dt>
              <dd data-fv-qty-total><?= number_format($totalQty, 0, ',', '.') ?></dd>
            </div>
            <?php if ($hasSavings): ?>
              <div class="is-saving">
                <dt>Ahorro vs. precio anterior</dt>
                <dd data-fv-saving><?= gs($totalSaving) ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($hasCheaper): ?>
              <div class="is-saving">
                <dt>Ahorro posible en otras tiendas</dt>
                <dd data-fv-possible><?= gs($possibleSaving) ?></dd>
              </div>
            <?php endif; ?>
          </dl>

          <div class="fv-total">
            <span class="fv-total-label">Total estimado</span>
            <strong class="fv-total-value" data-fv-total aria-live="polite"><?= gs($subtotal) ?></strong>
          </div>

          <div class="fv-ticket-actions">
            <button type="submit" class="btn btn-outline-primary" data-fv-recalc>
              <?= lucide_icon('calculator', '', 16) ?>Recalcular
            </button>
            <button type="submit" class="btn btn-primary" formaction="favoritos_print.php" formtarget="_blank">
              <?= lucide_icon('printer', '', 16) ?>Imprimir / PDF
            </button>
          </div>

          <p class="fv-ticket-note">
            <?= lucide_icon('info', '', 14) ?>
            <span>Precios según la última actualización de cada tienda; pueden variar al momento de comprar.</span>
          </p>
        </aside>
      </form>
    <?php else: ?>
      <div class="fv-empty animate-reveal-up">
        <span class="fv-empty-mark" aria-hidden="true"><?= lucide_icon('star', '', 120) ?></span>

        <div class="fv-empty-main">
          <p class="pd-kicker">Planificador de compra</p>
          <h1 id="fav-title" class="fv-title">Todavía no tienes <span class="fv-empty-hl">favoritos</span></h1>
          <p class="fv-lead">Guarda productos para armar tu lista de compras y comparar el total entre las tiendas de Caacupé.</p>

          <form action="buscar.php" method="get" class="fv-empty-search" role="search">
            <label class="visually-hidden" for="fv-empty-q">Buscar un producto</label>
            <span class="fv-empty-field">
              <?= lucide_icon('search', '', 17) ?>
              <input type="search" id="fv-empty-q" name="q" class="fv-empty-input" placeholder="¿Qué necesitas comprar?" autocomplete="off" required>
            </span>
            <button type="submit" class="btn btn-primary fv-empty-btn">Buscar</button>
          </form>

          <div class="fv-empty-actions">
            <button type="button" class="fv-empty-link is-strong" data-bs-toggle="modal" data-bs-target="#fvAddModal">
              <?= lucide_icon('heart-plus', '', 16) ?>Agregar desde el catálogo
            </button>
            <span class="fv-empty-sep" aria-hidden="true"></span>
            <a href="index.php#productos" class="fv-empty-link">
              Ver todos los productos<?= lucide_icon('arrow-right', '', 15) ?>
            </a>
          </div>
        </div>

        <figure class="fv-empty-preview" aria-hidden="true">
          <figcaption class="fv-preview-head">
            <span class="fv-preview-title"><?= lucide_icon('list-checks', '', 15) ?>Tu lista</span>
            <span class="fv-preview-count">0 productos</span>
          </figcaption>
          <ul class="fv-preview-rows">
            <li class="is-next">
              <span class="fv-ghost-check"></span>
              <span class="fv-ghost-lines"><span></span><span></span></span>
              <span class="fv-ghost-heart"><?= lucide_icon('heart', '', 14) ?></span>
            </li>
            <li>
              <span class="fv-ghost-check"></span>
              <span class="fv-ghost-lines"><span></span><span></span></span>
              <span class="fv-ghost-price"></span>
            </li>
            <li>
              <span class="fv-ghost-check"></span>
              <span class="fv-ghost-lines"><span></span><span></span></span>
              <span class="fv-ghost-price"></span>
            </li>
          </ul>
          <div class="fv-preview-total">
            <span>Total estimado</span>
            <strong><?= gs(0) ?></strong>
          </div>
        </figure>

        <ol class="fv-steps">
          <li>
            <span class="fv-step-icon"><?= lucide_icon('search', '', 17) ?></span>
            <span class="fv-step-num">Paso 01<span class="fv-step-line" aria-hidden="true"></span></span>
            <span class="fv-step-copy">
              <strong>Busca un producto</strong>
              <span>Explora el catálogo o usa el buscador.</span>
            </span>
          </li>
          <li>
            <span class="fv-step-icon"><?= lucide_icon('heart', '', 17) ?></span>
            <span class="fv-step-num">Paso 02<span class="fv-step-line" aria-hidden="true"></span></span>
            <span class="fv-step-copy">
              <strong>Toca el corazón</strong>
              <span>En la ficha o en cualquier oferta.</span>
            </span>
          </li>
          <li>
            <span class="fv-step-icon"><?= lucide_icon('receipt-text', '', 17) ?></span>
            <span class="fv-step-num">Paso 03</span>
            <span class="fv-step-copy">
              <strong>Planifica tu compra</strong>
              <span>Ajusta cantidades, marca lo comprado e imprime.</span>
            </span>
          </li>
        </ol>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="modal fade" id="fvAddModal" tabindex="-1" aria-labelledby="fvAddModalTitle" aria-hidden="true" data-fv-add>
  <div class="modal-dialog modal-dialog-centered fv-add-dialog">
    <div class="modal-content fv-add-modal">
      <header class="fv-add-head">
        <div class="fv-add-head-top">
          <span class="fv-add-icon" aria-hidden="true"><?= lucide_icon('heart-plus', '', 20) ?></span>
          <div class="fv-add-heading">
            <h2 id="fvAddModalTitle" class="fv-add-title">Agregar productos</h2>
            <p class="fv-add-caption">Toca una tarjeta para sumarla a tu lista. Solo ves lo que aún no tienes.</p>
          </div>
          <button type="button" class="fv-add-close" data-bs-dismiss="modal" aria-label="Cerrar"><?= lucide_icon('x', '', 18) ?></button>
        </div>

        <div class="fv-add-search" data-fv-add-search>
          <?= lucide_icon('search', 'fv-add-search-icon', 17) ?>
          <input
            type="search"
            class="fv-add-input"
            placeholder="Busca por producto, marca o tienda"
            autocomplete="off"
            enterkeyhint="search"
            aria-label="Buscar productos"
            data-fv-add-input
          >
          <span class="fv-add-spinner" data-fv-add-loading hidden aria-hidden="true"></span>
          <button type="button" class="fv-add-clear" data-fv-add-clear hidden aria-label="Borrar búsqueda" title="Borrar búsqueda (Esc)"><?= lucide_icon('x', '', 16) ?></button>
        </div>

        <div class="fv-add-bar">
          <div class="fv-add-tabs" role="tablist" aria-label="Vista">
            <button type="button" class="fv-add-tab is-active" role="tab" aria-selected="true" data-fv-add-view="explore">
              <span data-fv-add-kicker>Bajas de precio</span>
            </button>
            <button type="button" class="fv-add-tab" role="tab" aria-selected="false" data-fv-add-view="selected">
              Seleccionados <span class="fv-add-tab-count" data-fv-add-tab-count>0</span>
            </button>
          </div>
          <span class="fv-add-results-count" data-fv-add-results-count></span>
        </div>
      </header>

      <div class="fv-add-scroll" data-fv-add-scroll>
        <ul class="fv-add-grid" data-fv-add-list aria-live="polite"></ul>
        <div class="fv-add-empty" data-fv-add-empty="explore" hidden>
          <span class="fv-add-empty-icon"><?= lucide_icon('search-x', '', 20) ?></span>
          <strong>Sin resultados</strong>
          <span>Prueba con otra palabra o el nombre de una marca.</span>
        </div>
        <div class="fv-add-empty" data-fv-add-empty="selected" hidden>
          <span class="fv-add-empty-icon"><?= lucide_icon('mouse-pointer-click', '', 20) ?></span>
          <strong>Todavía no elegiste nada</strong>
          <span>Vuelve a la pestaña anterior y toca las tarjetas que quieras sumar.</span>
        </div>
      </div>

      <footer class="fv-add-foot">
        <div class="fv-add-summary">
          <div class="fv-add-avatars is-empty" data-fv-add-avatars></div>
          <div class="fv-add-summary-text">
            <strong data-fv-add-summary-title>Nada seleccionado</strong>
            <span data-fv-add-summary-sub role="status">Toca una tarjeta para elegirla</span>
          </div>
        </div>
        <div class="fv-add-actions">
          <button type="button" class="fv-add-undo is-collapsed" data-fv-add-undo disabled aria-hidden="true"><?= lucide_icon('undo-2', '', 15) ?>Deshacer</button>
          <button type="button" class="fv-add-done" data-fv-add-done>
            <span class="fv-add-done-icons" aria-hidden="true">
              <?= lucide_icon('circle-check', 'fv-add-done-idle', 17) ?>
              <?= lucide_icon('list-plus', 'fv-add-done-add', 17) ?>
            </span>
            <span data-fv-add-done-label>Listo</span>
          </button>
        </div>
      </footer>
    </div>
  </div>
</div>

<?php if ($items): ?>
<div class="modal fade" id="fvClearModal" tabindex="-1" aria-labelledby="fvClearModalTitle" aria-hidden="true" data-fv-clear>
  <div class="modal-dialog modal-dialog-centered fv-clear-dialog">
    <div class="modal-content fv-clear-modal">
      <header class="fv-clear-head">
        <div>
          <h2 id="fvClearModalTitle" class="fv-clear-title">Limpiar lista</h2>
          <p class="fv-clear-caption">Elige qué quieres hacer con tu lista de <?= (int) $totalItems ?> producto<?= $totalItems === 1 ? '' : 's' ?>.</p>
        </div>
        <button type="button" class="fv-add-close" data-bs-dismiss="modal" aria-label="Cerrar"><?= lucide_icon('x', '', 18) ?></button>
      </header>

      <div class="fv-clear-options">
        <button type="button" class="fv-clear-option" data-fv-clear-action="reset">
          <span class="fv-clear-option-icon"><?= lucide_icon('rotate-ccw', '', 18) ?></span>
          <span class="fv-clear-option-copy">
            <strong>Reiniciar progreso</strong>
            <span>Desmarca todos los productos y vuelve las cantidades a 1. Tu lista se mantiene.</span>
          </span>
        </button>

        <button type="button" class="fv-clear-option is-danger" data-fv-clear-action="empty">
          <span class="fv-clear-option-icon"><?= lucide_icon('trash-2', '', 18) ?></span>
          <span class="fv-clear-option-copy">
            <strong>Vaciar lista</strong>
            <span>Quita todos los productos de tus favoritos. Esta acción no se puede deshacer.</span>
          </span>
        </button>
      </div>

      <p class="fv-clear-error" data-fv-clear-error role="alert" hidden></p>

      <footer class="fv-clear-foot">
        <button type="button" class="fv-foot-btn" data-bs-dismiss="modal">Cancelar</button>
      </footer>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.querySelector('[data-fv-add]');
  if (!modal) return;

  const input = modal.querySelector('[data-fv-add-input]');
  const searchBox = modal.querySelector('[data-fv-add-search]');
  const clearBtn = modal.querySelector('[data-fv-add-clear]');
  const list = modal.querySelector('[data-fv-add-list]');
  const scroller = modal.querySelector('[data-fv-add-scroll]');
  const emptyExplore = modal.querySelector('[data-fv-add-empty="explore"]');
  const emptySelected = modal.querySelector('[data-fv-add-empty="selected"]');
  const kicker = modal.querySelector('[data-fv-add-kicker]');
  const loading = modal.querySelector('[data-fv-add-loading]');
  const resultsCount = modal.querySelector('[data-fv-add-results-count]');
  const tabs = Array.from(modal.querySelectorAll('[data-fv-add-view]'));
  const tabCount = modal.querySelector('[data-fv-add-tab-count]');
  const avatars = modal.querySelector('[data-fv-add-avatars]');
  const summaryTitle = modal.querySelector('[data-fv-add-summary-title]');
  const summarySub = modal.querySelector('[data-fv-add-summary-sub]');
  const doneBtn = modal.querySelector('[data-fv-add-done]');
  const doneLabel = modal.querySelector('[data-fv-add-done-label]');
  const undoBtn = modal.querySelector('[data-fv-add-undo]');
  const MAX_AVATARS = 5;
  const selected = new Map();
  const catalog = new Map();
  const pending = new Set();
  let results = [];
  let view = 'explore';
  let errorMessage = '';
  let errorTimer = null;
  let searchTimer = null;
  let requestId = 0;
  let loaded = false;

  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[ch]));

  const refreshIcons = () => {
    if (typeof window.refreshLucideIcons === 'function') window.refreshLucideIcons();
  };

  const formatGs = (value) => 'Gs. ' + Math.round(value).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');

  const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);

  const LEAVE_MS = 220;
  const COLLAPSE_MS = 380;
  let avatarTimer = null;
  let committing = false;
  let committed = false;
  let footerState = '';

  const restartClass = (el, cls) => {
    el.classList.remove(cls);
    void el.offsetWidth;
    el.classList.add(cls);
  };

  const swapText = (el, text, animate) => {
    if (el.textContent === text) return;
    el.textContent = text;
    if (animate) restartClass(el, 'is-swap');
  };

  function avatarNode(item) {
    const id = String(item.id);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'fv-add-avatar is-enter';
    button.dataset.fvAddRemove = id;
    button.title = 'Quitar ' + item.nombre;
    button.setAttribute('aria-label', 'Quitar ' + item.nombre);
    button.innerHTML = `<img src="${escapeHtml(item.imagen)}" alt=""><span class="fv-add-avatar-x" aria-hidden="true"><i data-lucide="x"></i></span>`;
    return button;
  }

  function paintAvatars() {
    avatarTimer = null;
    const items = Array.from(selected.values());
    const shown = items.slice(0, MAX_AVATARS);
    const wanted = new Set(shown.map((item) => String(item.id)));
    const extra = items.length - MAX_AVATARS;
    let more = avatars.querySelector('.is-more');
    let created = false;

    avatars.querySelectorAll('[data-fv-add-remove]').forEach((el) => {
      if (!wanted.has(el.dataset.fvAddRemove)) el.remove();
    });

    shown.forEach((item, index) => {
      const id = String(item.id);
      let el = avatars.querySelector('[data-fv-add-remove="' + id + '"]');
      if (!el) {
        el = avatarNode(item);
        created = true;
      }
      el.classList.toggle('is-busy', pending.has(id));
      const ref = avatars.children[index] || null;
      if (ref !== el) avatars.insertBefore(el, ref);
    });

    if (extra > 0) {
      if (!more) {
        more = document.createElement('span');
        more.className = 'fv-add-avatar is-more is-enter';
      }
      more.classList.remove('is-leaving');
      more.textContent = '+' + extra;
      if (avatars.lastElementChild !== more) avatars.appendChild(more);
    } else if (more) {
      more.remove();
    }

    if (created) refreshIcons();

    const isEmpty = avatars.classList.contains('is-empty');
    if (items.length && isEmpty) setAvatarsOpen(true);
    if (!items.length && !isEmpty) setAvatarsOpen(false);
  }

  function setAvatarsOpen(open) {
    const from = open && avatars.classList.contains('is-empty') ? 0 : avatars.getBoundingClientRect().width;
    avatars.style.width = from + 'px';
    void avatars.offsetWidth;
    avatars.classList.toggle('is-empty', !open);
    avatars.style.width = open ? avatars.scrollWidth + 'px' : '0px';
  }

  avatars.addEventListener('transitionend', (event) => {
    if (event.target === avatars && event.propertyName === 'width' && !avatars.classList.contains('is-empty')) {
      avatars.style.width = '';
    }
  });

  avatars.addEventListener('animationend', (event) => {
    event.target.classList.remove('is-enter');
  });

  function renderAvatars() {
    const keep = new Set(Array.from(selected.keys()).slice(0, MAX_AVATARS));
    const leaving = Array.from(avatars.querySelectorAll('[data-fv-add-remove]:not(.is-leaving)'))
      .filter((el) => !keep.has(el.dataset.fvAddRemove));
    const more = avatars.querySelector('.is-more:not(.is-leaving)');

    if (leaving.length) {
      leaving.forEach((el) => el.classList.add('is-leaving'));
      if (more && selected.size <= MAX_AVATARS) more.classList.add('is-leaving');
      const closing = selected.size === 0;
      if (closing) setAvatarsOpen(false);
      clearTimeout(avatarTimer);
      avatarTimer = setTimeout(paintAvatars, closing ? COLLAPSE_MS : LEAVE_MS);
      return;
    }
    if (avatarTimer) return;

    avatars.querySelectorAll('[data-fv-add-remove]').forEach((el) => {
      el.classList.toggle('is-busy', pending.has(el.dataset.fvAddRemove));
    });
    const current = Array.from(avatars.querySelectorAll('[data-fv-add-remove]')).map((el) => el.dataset.fvAddRemove);
    const wanted = Array.from(keep);
    const extra = selected.size - MAX_AVATARS;
    const shownExtra = more ? more.textContent : '';
    if (current.join() !== wanted.join() || shownExtra !== (extra > 0 ? '+' + extra : '')) paintAvatars();
  }

  function renderFooter() {
    const items = Array.from(selected.values());
    const total = items.reduce((sum, item) => sum + (Number(item.precio_valor) || 0), 0);

    renderAvatars();

    const state = errorMessage ? 'error' : (items.length ? 'items' : 'empty');
    const stateChanged = state !== footerState;
    footerState = state;
    swapText(summaryTitle, items.length ? plural(items.length, 'producto seleccionado', 'productos seleccionados') : 'Nada seleccionado', stateChanged);
    summarySub.classList.toggle('is-error', errorMessage !== '');
    swapText(summarySub, errorMessage || (items.length ? 'Suma ' + formatGs(total) : 'Toca una tarjeta para elegirla'), stateChanged);

    if (tabCount.textContent !== String(items.length)) {
      tabCount.textContent = String(items.length);
      restartClass(tabCount, 'is-bump');
    }
    tabCount.classList.toggle('is-active', items.length > 0);

    const hasItems = items.length > 0;
    undoBtn.classList.toggle('is-collapsed', !hasItems);
    undoBtn.disabled = !hasItems || committing;
    undoBtn.setAttribute('aria-hidden', String(!hasItems));
    swapText(doneLabel, committing ? 'Agregando…' : (hasItems ? 'Agregar a mi lista' : 'Listo'), true);
    doneBtn.classList.toggle('is-ready', hasItems);
    doneBtn.disabled = committing;
  }

  const WASH_PALETTE = ['#8ba4c4', '#c9a87a', '#c48b8e', '#8fad97', '#a89bc4', '#7eb8b0', '#c4a88b', '#9bb0c9'];

  function cardTemplate(item, animate, index) {
    const id = String(item.id);
    const isSelected = selected.has(id);
    const classes = 'fv-add-card' + (isSelected ? ' is-added' : '') + (pending.has(id) ? ' is-busy' : '') + (animate ? ' is-enter' : '');
    const wash = WASH_PALETTE[index % WASH_PALETTE.length];
    return `
      <li class="${classes}" style="--tile-wash: ${wash};">
        <button type="button" class="fv-add-card-hit" data-fv-add-id="${id}" aria-pressed="${isSelected}" aria-label="${isSelected ? 'Quitar' : 'Guardar'} ${escapeHtml(item.nombre)}">
          <span class="fv-add-card-media">
            <img src="${escapeHtml(item.imagen)}" alt="" loading="lazy">
            ${item.descuento > 0 ? `<span class="fv-add-card-deal">−${item.descuento}%</span>` : ''}
            <span class="fv-add-card-heart" aria-hidden="true">
              <i data-lucide="heart" class="fv-add-heart-ico"></i>
              <i data-lucide="x" class="fv-add-heart-x"></i>
            </span>
            <span class="fv-add-card-stock${item.stock ? ' is-in' : ''}">${item.stock ? 'En stock' : 'Consultar stock'}</span>
          </span>
          <span class="fv-add-card-body">
            <span class="fv-add-card-store">
              <i data-lucide="store"></i>
              <span>${escapeHtml(item.tienda)}</span>
            </span>
            <span class="fv-add-card-name" title="${escapeHtml(item.nombre)}">${escapeHtml(item.nombre)}</span>
            <span class="fv-add-card-price">
              <strong>${escapeHtml(item.precio)}</strong>
              ${item.precio_anterior ? `<s>${escapeHtml(item.precio_anterior)}</s>` : ''}
            </span>          </span>
        </button>
      </li>`;
  }

  function renderGrid(animate) {
    const items = view === 'selected' ? Array.from(selected.values()) : results;
    list.classList.toggle('is-review', view === 'selected');
    list.innerHTML = items.map((item, index) => cardTemplate(item, animate, index)).join('');
    emptyExplore.hidden = view !== 'explore' || items.length > 0 || !loading.hidden;
    emptySelected.hidden = view !== 'selected' || items.length > 0;
    resultsCount.textContent = view === 'explore' && items.length ? plural(items.length, 'producto', 'productos') : '';
    refreshIcons();
  }

  function leaveCards(cards) {
    if (!cards.length) return Promise.resolve();
    cards.forEach((card) => card.classList.add('is-leaving'));
    return new Promise((resolve) => setTimeout(resolve, LEAVE_MS));
  }

  function syncCard(productId) {
    const button = list.querySelector('[data-fv-add-id="' + productId + '"]');
    if (!button) return;
    const card = button.closest('.fv-add-card');
    const isSelected = selected.has(productId);

    if (view === 'selected' && !isSelected && !pending.has(productId)) {
      leaveCards([card]).then(() => {
        if (view === 'selected') renderGrid(false);
      });
      return;
    }

    card.classList.toggle('is-added', isSelected);
    card.classList.toggle('is-busy', pending.has(productId));
    button.setAttribute('aria-pressed', String(isSelected));
    button.setAttribute('aria-label', (isSelected ? 'Quitar ' : 'Guardar ') + (catalog.get(productId) || {}).nombre);
  }

  function setView(next) {
    view = next;
    tabs.forEach((tab) => {
      const isActive = tab.dataset.fvAddView === view;
      tab.classList.toggle('is-active', isActive);
      tab.setAttribute('aria-selected', String(isActive));
    });
    renderGrid(true);
    scroller.scrollTop = 0;
  }

  function showError(message) {
    errorMessage = message;
    clearTimeout(errorTimer);
    errorTimer = setTimeout(() => {
      errorMessage = '';
      renderFooter();
    }, 3500);
  }

  function toggleProduct(productId) {
    if (committing) return;
    if (selected.has(productId)) {
      selected.delete(productId);
    } else {
      selected.set(productId, catalog.get(productId));
    }
    syncCard(productId);
    renderFooter();
  }

  function undoAll() {
    const ids = Array.from(selected.keys());
    if (!ids.length || committing) return;
    selected.clear();
    renderFooter();
    if (view === 'selected') {
      leaveCards(Array.from(list.querySelectorAll('.fv-add-card'))).then(() => {
        if (view === 'selected') renderGrid(false);
      });
      return;
    }
    ids.forEach(syncCard);
  }

  function setPending(ids, busy) {
    ids.forEach((id) => (busy ? pending.add(id) : pending.delete(id)));
    ids.forEach(syncCard);
  }

  function commitSelection() {
    const ids = Array.from(selected.keys());
    if (!ids.length) {
      bootstrap.Modal.getOrCreateInstance(modal).hide();
      return;
    }
    if (committing) return;
    committing = true;
    setPending(ids, true);
    renderFooter();

    fetch('favoritos_api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ action: 'add_many', ids: ids.join(',') })
    })
      .then((response) => response.json())
      .then((data) => {
        if (!data || !data.ok) throw Object.assign(new Error((data && data.error) || ''), { fromApi: true });
        committed = true;
        committing = false;
        bootstrap.Modal.getOrCreateInstance(modal).hide();
      })
      .catch((error) => {
        committing = false;
        setPending(ids, false);
        showError((error && error.fromApi && error.message) || 'No se pudieron agregar los productos. Intenta de nuevo.');
        renderFooter();
      });
  }

  function syncSearchBox() {
    const hasValue = input.value !== '';
    searchBox.classList.toggle('has-value', hasValue);
    searchBox.classList.toggle('is-loading', !loading.hidden);
    clearBtn.hidden = !hasValue;
  }

  function clearSearch() {
    input.value = '';
    clearTimeout(searchTimer);
    syncSearchBox();
    search('');
    input.focus();
  }

  function search(term) {
    const current = ++requestId;
    loading.hidden = false;
    syncSearchBox();
    kicker.textContent = term ? 'Resultados' : 'Bajas de precio';
    if (view !== 'explore') setView('explore');

    fetch('favoritos_api.php?action=search&q=' + encodeURIComponent(term), { credentials: 'same-origin' })
      .then((response) => response.json())
      .then((data) => {
        if (current !== requestId) return;
        results = data && data.ok ? data.items : [];
        results.forEach((item) => catalog.set(String(item.id), item));
      })
      .catch(() => {
        if (current === requestId) results = [];
      })
      .finally(() => {
        if (current !== requestId) return;
        loading.hidden = true;
        syncSearchBox();
        if (view === 'explore') {
          renderGrid(true);
          scroller.scrollTop = 0;
        }
      });
  }

  input.addEventListener('input', () => {
    syncSearchBox();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => search(input.value.trim()), 280);
  });

  list.addEventListener('click', (event) => {
    const button = event.target.closest('[data-fv-add-id]');
    if (button) toggleProduct(button.dataset.fvAddId);
  });

  avatars.addEventListener('click', (event) => {
    const button = event.target.closest('[data-fv-add-remove]');
    if (button) toggleProduct(button.dataset.fvAddRemove);
  });

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => setView(tab.dataset.fvAddView));
  });

  tabCount.addEventListener('animationend', () => tabCount.classList.remove('is-bump'));

  undoBtn.addEventListener('click', undoAll);

  clearBtn.addEventListener('click', clearSearch);

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && input.value !== '') {
      event.preventDefault();
      event.stopPropagation();
      clearSearch();
      return;
    }
    if (event.key !== 'Enter') return;
    event.preventDefault();
    clearTimeout(searchTimer);
    search(input.value.trim());
  });

  renderFooter();

  modal.addEventListener('show.bs.modal', () => {
    if (view !== 'explore') setView('explore');
  });

  modal.addEventListener('shown.bs.modal', () => {
    input.focus();
    if (!loaded) {
      loaded = true;
      search('');
    }
  });

  doneBtn.addEventListener('click', commitSelection);

  modal.addEventListener('hide.bs.modal', (event) => {
    if (committing) event.preventDefault();
  });

  modal.addEventListener('hidden.bs.modal', () => {
    if (!committed) {
      undoAll();
      return;
    }
    const form = document.querySelector('[data-fv-form]');
    if (form) {
      form.submit();
    } else {
      window.location.reload();
    }
  });
});
</script>

<?php if ($items): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('[data-fv-form]');
  if (!form) return;

  const items = Array.from(form.querySelectorAll('[data-fv-item]'));
  const chips = Array.from(form.querySelectorAll('[data-fv-filter]'));
  const hideDone = form.querySelector('[data-fv-hide-done]');
  const emptyState = form.querySelector('[data-fv-empty]');
  const toggleAllBtn = form.querySelector('[data-fv-toggle-all]');
  const pager = form.querySelector('[data-fv-pager]');
  const pagerInfo = form.querySelector('[data-fv-pager-info]');
  const pagerPages = form.querySelector('[data-fv-pager-pages]');
  const listTop = form.querySelector('.fv-rows');
  const PAGE_SIZE = 10;
  let currentPage = 1;
  const recalcBtn = form.querySelector('[data-fv-recalc]');
  const storageKey = 'fv-checked-' + (form.dataset.fvUser || '0');
  const MIN_QTY = 1;
  const MAX_QTY = 999;
  let activeStore = 'all';
  const searchInput = form.querySelector('[data-fv-search]');
  const searchClear = form.querySelector('[data-fv-search-clear]');
  const noResults = form.querySelector('[data-fv-no-results]');
  const noResultsTerm = form.querySelector('[data-fv-no-results-term]');
  const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  let searchTerms = [];
  items.forEach((item) => { item.dataset.searchNorm = normalize(item.dataset.search); });

  if (recalcBtn) recalcBtn.hidden = true;

  const chipsWrap = form.querySelector('[data-fv-chips-wrap]');
  const chipsTrack = form.querySelector('[data-fv-chips]');
  if (chipsWrap && chipsTrack) {
    const navButtons = Array.from(chipsWrap.querySelectorAll('[data-fv-chips-nav]'));
    const updateChipsNav = () => {
      const overflowing = chipsTrack.scrollWidth > chipsWrap.clientWidth + 1;
      navButtons.forEach((btn) => { btn.hidden = !overflowing; });
      const maxScroll = chipsTrack.scrollWidth - chipsTrack.clientWidth;
      const hasPrev = overflowing && chipsTrack.scrollLeft > 1;
      const hasNext = overflowing && chipsTrack.scrollLeft < maxScroll - 1;
      chipsWrap.classList.toggle('has-prev', hasPrev);
      chipsWrap.classList.toggle('has-next', hasNext);
      navButtons.forEach((btn) => {
        btn.disabled = btn.dataset.fvChipsNav === '-1' ? !hasPrev : !hasNext;
      });
    };
    navButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const step = Math.max(chipsTrack.clientWidth * 0.7, 120);
        chipsTrack.scrollBy({ left: step * Number(btn.dataset.fvChipsNav), behavior: 'smooth' });
      });
    });
    chipsTrack.addEventListener('scroll', updateChipsNav, { passive: true });
    window.addEventListener('resize', updateChipsNav);
    chips.forEach((chip) => {
      chip.addEventListener('click', () => {
        chip.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
      });
    });
    updateChipsNav();
  }

  const formatNumber = (value) => Math.round(value).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const formatGs = (value) => 'Gs. ' + formatNumber(value);
  const clampQty = (value) => {
    const parsed = parseInt(value, 10);
    if (Number.isNaN(parsed)) return MIN_QTY;
    return Math.min(MAX_QTY, Math.max(MIN_QTY, parsed));
  };
  const setText = (selector, text) => {
    document.querySelectorAll(selector).forEach((el) => { el.textContent = text; });
  };

  const loadChecked = () => {
    try {
      return new Set(JSON.parse(localStorage.getItem(storageKey) || '[]').map(String));
    } catch (e) {
      return new Set();
    }
  };
  const checked = loadChecked();
  const saveChecked = () => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(Array.from(checked)));
    } catch (e) {
      // almacenamiento no disponible
    }
  };

  function updateTotals() {
    let total = 0;
    let qtyTotal = 0;
    let saving = 0;
    let possible = 0;
    const stores = {};

    items.forEach((item) => {
      const input = item.querySelector('[data-fv-qty]');
      const qty = clampQty(input.value);
      const hasPrice = item.dataset.price !== '';
      const price = hasPrice ? parseFloat(item.dataset.price) : 0;
      const oldPrice = item.dataset.oldPrice ? parseFloat(item.dataset.oldPrice) : 0;
      const bestPrice = item.dataset.bestPrice ? parseFloat(item.dataset.bestPrice) : 0;
      const line = price * qty;
      const storeId = item.dataset.store;

      total += line;
      qtyTotal += qty;
      if (hasPrice && oldPrice > price) saving += (oldPrice - price) * qty;
      if (hasPrice && bestPrice > 0 && bestPrice < price) possible += (price - bestPrice) * qty;

      stores[storeId] = stores[storeId] || { total: 0, qty: 0 };
      stores[storeId].total += line;
      stores[storeId].qty += qty;

      const lineEl = item.querySelector('[data-fv-line]');
      if (lineEl && hasPrice) lineEl.textContent = formatGs(line);
      const oldLineEl = item.querySelector('[data-fv-old-line]');
      if (oldLineEl) oldLineEl.textContent = formatGs(oldPrice * qty);
      item.querySelector('[data-fv-step="-1"]').disabled = qty <= MIN_QTY;
      item.querySelector('[data-fv-step="1"]').disabled = qty >= MAX_QTY;
    });

    setText('[data-fv-total]', formatGs(total));
    setText('[data-fv-qty-total]', formatNumber(qtyTotal));
    setText('[data-fv-saving]', formatGs(saving));
    setText('[data-fv-possible]', formatGs(possible));

    Object.keys(stores).forEach((storeId) => {
      setText('[data-fv-store-total="' + storeId + '"]', formatGs(stores[storeId].total));
      setText('[data-fv-store-qty="' + storeId + '"]', stores[storeId].qty + (stores[storeId].qty === 1 ? ' unidad' : ' unidades'));
    });
  }

  function pageList(total, current) {
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
    const pages = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);
    if (start > 2) pages.push('…');
    for (let page = start; page <= end; page += 1) pages.push(page);
    if (end < total - 1) pages.push('…');
    pages.push(total);
    return pages;
  }

  function renderPager(visibleCount, pageCount) {
    if (!pager) return;
    pager.hidden = pageCount <= 1;
    if (pager.hidden) return;

    const from = (currentPage - 1) * PAGE_SIZE + 1;
    const to = Math.min(visibleCount, currentPage * PAGE_SIZE);
    pagerInfo.textContent = from + '–' + to + ' de ' + visibleCount + ' productos';
    pagerPages.innerHTML = pageList(pageCount, currentPage).map((page) => (
      page === '…'
        ? '<span class="fv-pager-gap" aria-hidden="true">…</span>'
        : `<button type="button" class="fv-pager-btn${page === currentPage ? ' is-active' : ''}" data-fv-page="${page}"${page === currentPage ? ' aria-current="page"' : ''}>${page}</button>`
    )).join('');
    pager.querySelector('[data-fv-page="prev"]').disabled = currentPage <= 1;
    pager.querySelector('[data-fv-page="next"]').disabled = currentPage >= pageCount;
  }

  function updateView() {
    let doneCount = 0;
    const visibleItems = [];

    let hiddenBySearch = false;

    items.forEach((item) => {
      const isDone = checked.has(item.dataset.fvItem);
      const matchesStore = activeStore === 'all' || item.dataset.store === activeStore;
      const matchesSearch = matchesTerms(item);

      item.classList.toggle('is-checked', isDone);
      item.querySelector('[data-fv-check]').checked = isDone;
      item.hidden = true;

      if (isDone) doneCount += 1;
      if (matchesStore && !matchesSearch) hiddenBySearch = true;
      if (matchesStore && matchesSearch && !(hideDone && hideDone.checked && isDone)) visibleItems.push(item);
    });

    const visibleCount = visibleItems.length;
    const pageCount = Math.max(1, Math.ceil(visibleCount / PAGE_SIZE));
    currentPage = Math.min(Math.max(1, currentPage), pageCount);
    visibleItems
      .slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)
      .forEach((item) => { item.hidden = false; });
    renderPager(visibleCount, pageCount);

    setText('[data-fv-done]', String(doneCount));
    const progress = form.querySelector('.fv-progress');
    if (progress) {
      const pct = items.length ? Math.round((doneCount / items.length) * 100) : 0;
      progress.style.setProperty('--fv-pct', String(pct));
      progress.classList.toggle('is-empty', pct === 0);
      progress.classList.toggle('is-complete', items.length > 0 && doneCount === items.length);
    }

    const showNoResults = visibleCount === 0 && searchTerms.length > 0 && hiddenBySearch;
    if (noResults) noResults.hidden = !showNoResults;
    if (noResultsTerm) noResultsTerm.textContent = searchInput ? searchInput.value.trim() : '';
    if (emptyState) emptyState.hidden = visibleCount > 0 || showNoResults;

    if (toggleAllBtn) {
      const scope = itemsInStore();
      toggleAllBtn.disabled = scope.length === 0;
      toggleAllBtn.classList.toggle('is-all', scope.length > 0 && scope.every((item) => checked.has(item.dataset.fvItem)));
    }
  }

  function matchesTerms(item) {
    return searchTerms.every((term) => item.dataset.searchNorm.includes(term));
  }

  function itemsInStore() {
    return items.filter((item) => (activeStore === 'all' || item.dataset.store === activeStore) && matchesTerms(item));
  }

  if (searchInput) {
    const applySearch = () => {
      searchTerms = normalize(searchInput.value).split(/\s+/).filter(Boolean);
      if (searchClear) searchClear.hidden = searchInput.value === '';
      currentPage = 1;
      updateView();
    };
    searchInput.addEventListener('input', applySearch);
    searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') event.preventDefault();
      if (event.key === 'Escape' && searchInput.value !== '') {
        event.preventDefault();
        searchInput.value = '';
        applySearch();
      }
    });
    if (searchClear) {
      searchClear.addEventListener('click', () => {
        searchInput.value = '';
        applySearch();
        searchInput.focus();
      });
    }
  }

  const clearModal = document.querySelector('[data-fv-clear]');
  if (clearModal) {
    const clearError = clearModal.querySelector('[data-fv-clear-error]');
    const clearOptions = Array.from(clearModal.querySelectorAll('[data-fv-clear-action]'));
    const hideClearModal = () => {
      if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(clearModal).hide();
    };

    clearModal.addEventListener('show.bs.modal', () => {
      clearError.hidden = true;
      clearOptions.forEach((btn) => { btn.disabled = false; btn.classList.remove('is-loading'); });
    });

    clearOptions.forEach((btn) => {
      btn.addEventListener('click', () => {
        if (btn.dataset.fvClearAction === 'reset') {
          checked.clear();
          saveChecked();
          items.forEach((item) => { item.querySelector('[data-fv-qty]').value = MIN_QTY; });
          if (hideDone) hideDone.checked = false;
          currentPage = 1;
          updateTotals();
          updateView();
          hideClearModal();
          return;
        }

        clearOptions.forEach((other) => { other.disabled = true; });
        btn.classList.add('is-loading');
        clearError.hidden = true;

        fetch('favoritos_api.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({ action: 'clear' })
        })
          .then((response) => response.json())
          .then((data) => {
            if (!data || !data.ok) throw new Error((data && data.error) || '');
            checked.clear();
            saveChecked();
            window.location.href = 'favoritos.php';
          })
          .catch((error) => {
            clearError.textContent = error.message || 'No se pudo vaciar la lista. Intenta de nuevo.';
            clearError.hidden = false;
            clearOptions.forEach((other) => { other.disabled = false; });
            btn.classList.remove('is-loading');
          });
      });
    });
  }

  if (toggleAllBtn) {
    toggleAllBtn.addEventListener('click', () => {
      const markAll = !toggleAllBtn.classList.contains('is-all');
      itemsInStore().forEach((item) => {
        if (markAll) {
          checked.add(item.dataset.fvItem);
        } else {
          checked.delete(item.dataset.fvItem);
        }
      });
      saveChecked();
      updateView();
    });
  }

  items.forEach((item) => {
    const input = item.querySelector('[data-fv-qty]');
    const check = item.querySelector('[data-fv-check]');

    check.addEventListener('change', () => {
      if (check.checked) {
        checked.add(item.dataset.fvItem);
      } else {
        checked.delete(item.dataset.fvItem);
      }
      saveChecked();
      updateView();
    });

    item.querySelectorAll('[data-fv-step]').forEach((btn) => {
      btn.addEventListener('click', () => {
        input.value = clampQty(clampQty(input.value) + parseInt(btn.dataset.fvStep, 10));
        updateTotals();
      });
    });

    input.addEventListener('input', updateTotals);
    input.addEventListener('change', () => {
      input.value = clampQty(input.value);
      updateTotals();
    });
    input.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      input.value = clampQty(input.value);
      updateTotals();
    });
  });

  chips.forEach((chip) => {
    chip.addEventListener('click', () => {
      activeStore = chip.dataset.fvFilter;
      chips.forEach((other) => {
        const isActive = other === chip;
        other.classList.toggle('is-active', isActive);
        other.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
      currentPage = 1;
      updateView();
    });
  });

  if (hideDone) hideDone.addEventListener('change', updateView);

  if (pager) {
    pager.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-fv-page]');
      if (!btn || btn.disabled) return;
      const target = btn.dataset.fvPage;
      if (target === 'prev') {
        currentPage -= 1;
      } else if (target === 'next') {
        currentPage += 1;
      } else {
        currentPage = parseInt(target, 10);
      }
      updateView();
      if (listTop && listTop.getBoundingClientRect().top < 80) {
        listTop.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  }

  updateTotals();
  updateView();
});
</script>
<?php endif; ?>
<?php render_footer(); ?>
