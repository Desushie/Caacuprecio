<?php
require_once __DIR__ . '/config.php';

$id = (int) ($_GET['id'] ?? 0);
$grupo = trim((string) ($_GET['grupo'] ?? ''));
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$pdo = db();
$userId = function_exists('current_user_id') ? current_user_id() : null;
$sessionId = session_id();
$userLogged = function_exists('is_logged_in') && is_logged_in();
$favoritesEnabled = function_exists('is_favorite_product') && function_exists('favorite_toggle_url');

$reportSuccess = isset($_GET['product_reported']) && $_GET['product_reported'] === '1';
$reportError = '';
$productReportModals = [];

function client_ip_address(): string
{
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($keys as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_map('trim', explode(',', $value));
            $value = (string) ($parts[0] ?? '');
        }

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '';
}


$product = null;
$groupName = '';
$groupProductIds = [];

/**
 * Busca producto base por grupo o por id
 */
if ($grupo !== '') {
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            t.idtiendas,
            t.tie_nombre,
            t.tie_descripcion,
            t.tie_logo,
            t.tie_ubicacion,
            t.tie_url,
            c.idcategorias,
            c.cat_nombre,
            c.cat_descripcion
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE p.pro_activo = 1
          AND COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) = :grupo
        ORDER BY p.pro_precio IS NULL, p.pro_precio ASC, p.pro_fecha_scraping DESC, p.idproductos DESC
        LIMIT 1
    ");
    $stmt->execute([':grupo' => $grupo]);
    $product = $stmt->fetch();

    if ($product) {
        $id = (int) $product['idproductos'];
        $groupName = trim((string) ($product['pro_grupo'] ?: $product['pro_nombre']));
    }
}

if (!$product && $id > 0) {
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            t.idtiendas,
            t.tie_nombre,
            t.tie_descripcion,
            t.tie_logo,
            t.tie_ubicacion,
            t.tie_url,
            c.idcategorias,
            c.cat_nombre,
            c.cat_descripcion
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE p.idproductos = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch();

    if ($product) {
        $groupName = trim((string) (($product['pro_grupo'] ?? '') !== '' ? $product['pro_grupo'] : $product['pro_nombre']));
    }
}

if (!$product) {
    http_response_code(404);
    die('Producto no encontrado.');
}

/**
 * IDs del grupo completo
 */
$idsStmt = $pdo->prepare("
    SELECT idproductos
    FROM productos
    WHERE pro_activo = 1
      AND COALESCE(NULLIF(TRIM(pro_grupo), ''), pro_nombre) = :grupo
    ORDER BY pro_precio IS NULL, pro_precio ASC, pro_fecha_scraping DESC
");
$idsStmt->execute([':grupo' => $groupName]);
$groupProductIds = array_map('intval', array_column($idsStmt->fetchAll(), 'idproductos'));

if (!$groupProductIds) {
    $groupProductIds = [(int) $product['idproductos']];
}

$placeholders = implode(',', array_fill(0, count($groupProductIds), '?'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'report_product') {
    $reportProductId = (int) ($_POST['product_id'] ?? 0);
    $reportName = trim((string) ($_POST['rep_nombre'] ?? ''));
    $reportEmail = trim((string) ($_POST['rep_email'] ?? ''));
    $reportReason = trim((string) ($_POST['rep_motivo'] ?? ''));
    $reportDetail = trim((string) ($_POST['rep_detalle'] ?? ''));

    $allowedReasons = [
        'precio_incorrecto',
        'desactualizado',
        'sin_stock',
        'producto_equivocado',
        'informacion_falsa',
        'otro',
    ];

    $ipAddress = client_ip_address();

    if ($reportProductId <= 0) {
        $reportError = 'El producto seleccionado no es válido.';
    } elseif (!in_array($reportReason, $allowedReasons, true)) {
        $reportError = 'Seleccioná un motivo válido.';
    } else {
        $productExistsStmt = $pdo->prepare("
            SELECT idproductos
            FROM productos
            WHERE idproductos = :product_id
              AND pro_activo = 1
            LIMIT 1
        ");
        $productExistsStmt->execute([
            ':product_id' => $reportProductId,
        ]);

        if (!$productExistsStmt->fetchColumn()) {
            $reportError = 'No se encontró el producto.';
        } else {
            $duplicateSql = "
                SELECT idreporte
                FROM producto_reportes
                WHERE productos_idproductos = :product_id
            ";

            $duplicateParams = [
                ':product_id' => $reportProductId,
            ];

            $duplicateConditions = [];

            if ($sessionId !== '') {
                $duplicateConditions[] = 'rep_session_id = :session_id_dup';
                $duplicateParams[':session_id_dup'] = $sessionId;
            }

            if ($ipAddress !== '') {
                $duplicateConditions[] = 'rep_ip = :ip_address_dup';
                $duplicateParams[':ip_address_dup'] = $ipAddress;
            }

            if ($duplicateConditions) {
                $duplicateSql .= " AND (" . implode(' OR ', $duplicateConditions) . ") LIMIT 1";
                $duplicateStmt = $pdo->prepare($duplicateSql);
                $duplicateStmt->execute($duplicateParams);

                if ($duplicateStmt->fetch()) {
                    $reportError = 'Ya reportaste este producto anteriormente.';
                }
            }

            if ($reportError === '') {
                $insertReport = $pdo->prepare("
                    INSERT INTO producto_reportes (
                        productos_idproductos,
                        rep_nombre,
                        rep_email,
                        rep_motivo,
                        rep_detalle,
                        rep_ip,
                        rep_session_id,
                        rep_estado,
                        rep_fecha
                    ) VALUES (
                        :product_id,
                        :nombre,
                        :email,
                        :motivo,
                        :detalle,
                        :ip,
                        :session_id,
                        'pendiente',
                        NOW()
                    )
                ");
                $insertReport->execute([
                    ':product_id' => $reportProductId,
                    ':nombre' => $reportName !== '' ? mb_substr($reportName, 0, 120) : null,
                    ':email' => $reportEmail !== '' ? mb_substr($reportEmail, 0, 150) : null,
                    ':motivo' => $reportReason,
                    ':detalle' => $reportDetail !== '' ? mb_substr($reportDetail, 0, 1000) : null,
                    ':ip' => $ipAddress !== '' ? $ipAddress : null,
                    ':session_id' => $sessionId !== '' ? $sessionId : null,
                ]);

                $redirectParams = [
                    'id' => (int) $product['idproductos'],
                    'product_reported' => 1,
                ];

                if ($grupo !== '') {
                    $redirectParams['grupo'] = $grupo;
                }
                if ($searchTerm !== '') {
                    $redirectParams['q'] = $searchTerm;
                }

                header('Location: producto.php?' . http_build_query($redirectParams));
                exit;
            }
        }
    }
}


/**
 * Tracking de vista
 */
try {
    $stmtTrack = $pdo->prepare("
        INSERT INTO productos_vistos (usuario_idusuario, session_id, productos_idproductos)
        VALUES (:uid, :sid, :pid)
    ");
    $stmtTrack->bindValue(':uid', $userId ?: null, $userId ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmtTrack->bindValue(':sid', $sessionId);
    $stmtTrack->bindValue(':pid', (int) $product['idproductos'], PDO::PARAM_INT);
    $stmtTrack->execute();
} catch (Throwable $e) {
    // ignorar
}

if ($searchTerm !== '') {
    try {
        $stmtClick = $pdo->prepare("
            INSERT INTO busqueda_click_producto (termino, productos_idproductos, usuario_idusuario, session_id)
            VALUES (:term, :pid, :uid, :sid)
        ");
        $stmtClick->bindValue(':term', $searchTerm);
        $stmtClick->bindValue(':pid', (int) $product['idproductos'], PDO::PARAM_INT);
        $stmtClick->bindValue(':uid', $userId ?: null, $userId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmtClick->bindValue(':sid', $sessionId);
        $stmtClick->execute();
    } catch (Throwable $e) {
        // ignorar
    }
}

/**
 * Ofertas del grupo
 */
$offerSql = "
    SELECT
        p.idproductos,
        p.pro_nombre,
        p.pro_descripcion,
        p.pro_marca,
        p.pro_precio,
        p.pro_precio_anterior,
        p.pro_imagen,
        p.pro_url,
        p.pro_en_stock,
        p.pro_fecha_scraping,
        p.pro_modelo,
        p.pro_grupo,
        t.idtiendas,
        t.tie_nombre,
        t.tie_logo,
        t.tie_url
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    WHERE p.idproductos IN ($placeholders)
      AND p.pro_activo = 1
    ORDER BY p.pro_precio IS NULL, p.pro_precio ASC, p.pro_fecha_scraping DESC, p.idproductos DESC
";
$offerStmt = $pdo->prepare($offerSql);
$offerStmt->execute($groupProductIds);
$offersRaw = $offerStmt->fetchAll();

/**
 * Deduplicar ofertas repetidas
 */
$offers = [];
$seenOffers = [];

foreach ($offersRaw as $offer) {
    $key = mb_strtolower(trim((string) ($offer['tie_nombre'] ?? ''))) . '|' .
           trim((string) ($offer['pro_url'] ?? '')) . '|' .
           trim((string) ($offer['pro_nombre'] ?? ''));

    if (isset($seenOffers[$key])) {
        continue;
    }

    $seenOffers[$key] = true;
    $offers[] = $offer;
}

/**
 * Historial mensual combinado del grupo
 * Último precio registrado por mes y por producto/tienda
 */
$historySql = "
    SELECT
        hp.his_precio,
        hp.his_en_stock,
        hp.his_fecha,
        p.idproductos,
        p.pro_nombre,
        t.idtiendas,
        t.tie_nombre,
        DATE_FORMAT(hp.his_fecha, '%m/%Y') AS month_label,
        DATE_FORMAT(hp.his_fecha, '%Y-%m') AS month_sort
    FROM historial_precios hp
    INNER JOIN productos p ON p.idproductos = hp.productos_idproductos
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    INNER JOIN (
        SELECT
            hp2.productos_idproductos,
            DATE_FORMAT(hp2.his_fecha, '%Y-%m') AS month_key,
            MAX(hp2.his_fecha) AS max_fecha
        FROM historial_precios hp2
        WHERE hp2.productos_idproductos IN ($placeholders)
        GROUP BY hp2.productos_idproductos, DATE_FORMAT(hp2.his_fecha, '%Y-%m')
    ) monthly_idx
        ON monthly_idx.productos_idproductos = hp.productos_idproductos
       AND monthly_idx.month_key = DATE_FORMAT(hp.his_fecha, '%Y-%m')
       AND monthly_idx.max_fecha = hp.his_fecha
    WHERE hp.productos_idproductos IN ($placeholders)
    ORDER BY month_sort ASC, t.tie_nombre ASC, hp.his_fecha ASC, hp.idhistorial ASC
";
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute(array_merge($groupProductIds, $groupProductIds));
$historyRows = $historyStmt->fetchAll();

$history = array_reverse(array_slice($historyRows, -20));

$historyStats = [
    'min' => null,
    'max' => null,
    'latest' => null,
    'latestDate' => null,
    'count' => 0,
];

$historyLabels = [];
$historySeries = [];

foreach ($historyRows as $entry) {
    $storeName = trim((string) ($entry['tie_nombre'] ?? 'Sin tienda'));
    $price = isset($entry['his_precio']) ? (float) $entry['his_precio'] : null;
    $monthLabel = trim((string) ($entry['month_label'] ?? ''));
    $monthSort = trim((string) ($entry['month_sort'] ?? ''));

    if ($price === null || $monthLabel === '' || $monthSort === '') {
        continue;
    }

    $historyLabels[$monthSort] = $monthLabel;

    if (!isset($historySeries[$storeName])) {
        $historySeries[$storeName] = [];
    }

    $historySeries[$storeName][$monthSort] = round($price, 2);

    $historyStats['min'] = $historyStats['min'] === null ? $price : min($historyStats['min'], $price);
    $historyStats['max'] = $historyStats['max'] === null ? $price : max($historyStats['max'], $price);
    $historyStats['latest'] = $price;
    $historyStats['latestDate'] = $entry['his_fecha'];
    $historyStats['count']++;
}

ksort($historyLabels);

$historyChartLabels = array_values($historyLabels);
$historyChartDatasets = [];

foreach ($historySeries as $storeName => $pointsByMonth) {
    $datasetData = [];
    foreach ($historyLabels as $monthSort => $monthLabel) {
        $datasetData[] = array_key_exists($monthSort, $pointsByMonth) ? $pointsByMonth[$monthSort] : null;
    }

    $historyChartDatasets[] = [
        'label' => $storeName,
        'data' => $datasetData,
        'tension' => 0.25,
        'fill' => false,
        'spanGaps' => true,
    ];
}

/**
 * Imágenes únicas del grupo para slider
 */
$galleryImages = [];
$seenImages = [];

foreach ($offers as $offer) {
    $img = trim((string) ($offer['pro_imagen'] ?? ''));
    if ($img === '') {
        continue;
    }

    if (isset($seenImages[$img])) {
        continue;
    }

    $seenImages[$img] = true;
    $galleryImages[] = [
        'src' => image_url($img, $offer['pro_nombre'] ?? $groupName),
        'alt' => $offer['pro_nombre'] ?? $groupName,
    ];
}

if (!$galleryImages) {
    $galleryImages[] = [
        'src' => image_url($product['pro_imagen'] ?? null, $groupName),
        'alt' => $groupName,
    ];
}

/**
 * Mejor precio
 */
$minPrice = null;
foreach ($offers as $offer) {
    if ($offer['pro_precio'] !== null) {
        $price = (float) $offer['pro_precio'];
        $minPrice = $minPrice === null ? $price : min($minPrice, $price);
    }
}

$bestOfferUrl = !empty($offers[0]['pro_url']) ? $offers[0]['pro_url'] : ($product['pro_url'] ?? '#');
$bestOfferProductId = !empty($offers[0]['idproductos']) ? (int) $offers[0]['idproductos'] : (int) $product['idproductos'];
$goSource = 'producto';
$goTerm = $searchTerm;

/**
 * Compartir producto
 */
$currentScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$currentRequestUri = $_SERVER['REQUEST_URI'] ?? ('/producto.php?id=' . (int) $product['idproductos']);
$shareUrl = $currentScheme . '://' . $currentHost . $currentRequestUri;
$shareTitle = trim((string) ($groupName ?: $product['pro_nombre']));
$sharePriceText = $minPrice !== null ? gs($minPrice) : 'un excelente precio';
$shareText = 'Mira, encontré este ' . $shareTitle . ' a tan solo ' . $sharePriceText . ' en Caacuprecio!';

$shareWhatsappUrl = 'https://wa.me/?text=' . rawurlencode($shareText . ' ' . $shareUrl);
$shareFacebookUrl = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($shareUrl);
$shareTwitterUrl = 'https://twitter.com/intent/tweet?text=' . rawurlencode($shareText) . '&url=' . rawurlencode($shareUrl);

/**
 * Relacionados
 */
$relatedStmt = $pdo->prepare("
    SELECT idproductos, pro_nombre, pro_precio, pro_imagen, pro_en_stock
    FROM productos
    WHERE tiendas_idtiendas = :tienda
      AND idproductos <> :id
      AND pro_activo = 1
    ORDER BY pro_fecha_scraping DESC
    LIMIT 4
");
$relatedStmt->execute([
    ':tienda' => $product['idtiendas'],
    ':id' => $product['idproductos']
]);
$related = $relatedStmt->fetchAll();

render_head($groupName ?: $product['pro_nombre']);
render_navbar('producto');
?>

<style>
.product-gallery-main {
    position: relative;
    overflow: hidden;
    border-radius: 1rem;
    background: rgba(255,255,255,.03);
    min-height: 380px;
}
.soft-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1.1;
    padding-top: .5rem;
    padding-bottom: .5rem;
}
.gallery-progress {
    position: relative;
    width: 100%;
    height: 6px;
    border-radius: 999px;
    background: rgba(148,163,184,.22);
    overflow: hidden;
    margin-top: 1rem;
}
.gallery-progress-bar {
    width: 0%;
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #3b82f6 0%, #60a5fa 100%);
    transition: width .1s linear;
}
.product-gallery-slide {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 1rem;
    opacity: 0;
    visibility: hidden;
    transform: translateX(24px) scale(.985);
    transition: opacity .45s ease, transform .45s ease, visibility .45s ease;
}
.product-gallery-slide.active {
    opacity: 1;
    visibility: visible;
    transform: translateX(0) scale(1);
    z-index: 1;
}
.product-gallery-slide img {
    max-width: 100%;
    max-height: 380px;
    object-fit: contain;
}
.gallery-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 42px;
    height: 42px;
    border-radius: 999px;
    border: 0;
    background: rgba(15,23,42,.75);
    color: #fff;
    z-index: 2;
}
.gallery-arrow.prev { left: 10px; }
.gallery-arrow.next { right: 10px; }

.gallery-thumbs {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
    margin-top: 1rem;
}
.gallery-thumb {
    width: 72px;
    height: 72px;
    border-radius: .75rem;
    overflow: hidden;
    border: 2px solid transparent;
    background: rgba(255,255,255,.04);
    cursor: pointer;
    padding: 0;
}
.gallery-thumb.active {
    border-color: #3b82f6;
}
.gallery-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.offer-list-item {
    border: 1px solid rgba(148,163,184,.12);
    border-radius: 1rem;
}
.offer-product-thumb {
    width: 72px;
    height: 72px;
    border-radius: .85rem;
    object-fit: cover;
    background: rgba(255,255,255,.04);
}
.offer-original-name {
    font-size: .96rem;
    line-height: 1.35;
}
.offer-meta-small {
    font-size: .85rem;
}
.offer-actions .btn {
    white-space: nowrap;
}

.history-chart-wrap {
    position: relative;
    min-height: 320px;
}
.history-chart-canvas {
    width: 100% !important;
    height: 320px !important;
}
.history-stat-card {
    border: 1px solid rgba(148,163,184,.12);
    border-radius: 1rem;
    background: rgba(255,255,255,.03);
    padding: 1rem;
}
.history-stat-label {
    font-size: .82rem;
    color: var(--bs-secondary-color);
}
.history-stat-value {
    font-size: 1.1rem;
    font-weight: 700;
}

.share-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}
.share-actions .btn i {
    margin-right: .35rem;
}
.share-actions-title {
    font-size: .82rem;
    color: var(--bs-secondary-color);
}

</style>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="page-section py-5 position-relative">
  <div class="container position-relative z-1">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="breadcrumb custom-breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
        <li class="breadcrumb-item"><a href="index.php#productos">Productos</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($groupName ?: $product['pro_nombre']) ?></li>
      </ol>
    </nav>

    <?php if ($reportSuccess): ?>
      <div class="alert alert-warning mb-4">El producto fue reportado correctamente.</div>
    <?php endif; ?>

    <?php if ($reportError !== ''): ?>
      <div class="alert alert-danger mb-4"><?= e($reportError) ?></div>
    <?php endif; ?>

    <div class="row g-4 align-items-start">
      <div class="col-lg-5">
        <div class="detail-card glass-card p-3 p-lg-4 sticky-lg-top detail-sticky">
          <div class="product-gallery-main" id="productGallery">
            <?php foreach ($galleryImages as $idx => $img): ?>
              <div class="product-gallery-slide <?= $idx === 0 ? 'active' : '' ?>">
                <img src="<?= e($img['src']) ?>" alt="<?= e($img['alt']) ?>">
              </div>
            <?php endforeach; ?>

            <?php if (count($galleryImages) > 1): ?>
              <button type="button" class="gallery-arrow prev" id="galleryPrev">
                <i class="bi bi-chevron-left"></i>
              </button>
              <button type="button" class="gallery-arrow next" id="galleryNext">
                <i class="bi bi-chevron-right"></i>
              </button>
            <?php endif; ?>
          </div>

          <?php if (count($galleryImages) > 1): ?>
            <div class="gallery-thumbs" id="galleryThumbs">
              <?php foreach ($galleryImages as $idx => $img): ?>
                <button type="button" class="gallery-thumb <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>">
                  <img src="<?= e($img['src']) ?>" alt="<?= e($img['alt']) ?>">
                </button>
              <?php endforeach; ?>
            </div>
            <div class="gallery-progress" aria-hidden="true">
              <div class="gallery-progress-bar" id="galleryProgressBar"></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="detail-card glass-card p-4 mb-4">
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge soft-badge"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
            <span class="mini-badge <?= e(stock_badge_class($product['pro_en_stock'])) ?>"><?= e(stock_label($product['pro_en_stock'])) ?></span>
            <?php if (!empty($product['pro_marca'])): ?>
              <span class="mini-badge badge-neutral"><?= e($product['pro_marca']) ?></span>
            <?php endif; ?>
            <span class="mini-badge badge-neutral"><?= count($offers) ?> oferta(s)</span>
          </div>

          <h1 class="display-6 fw-bold mb-3"><?= e($groupName ?: $product['pro_nombre']) ?></h1>
          <p class="text-body-secondary mb-4">
            <?= e($product['pro_descripcion'] ?: 'Compará precios y ofertas disponibles para este producto en distintas tiendas.') ?>
          </p>

          <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
            <div>
              <div class="price-caption">Desde</div>
              <div class="price-now detail-price"><?= $minPrice !== null ? gs($minPrice) : 'Consultar' ?></div>
              <?php if ($product['pro_precio_anterior'] !== null): ?>
                <div class="price-old"><?= gs($product['pro_precio_anterior']) ?></div>
              <?php endif; ?>
            </div>
            <div class="text-body-secondary small text-md-end">

              <div>Actualizado: <?= e(date('d/m/Y H:i', strtotime((string) $product['pro_fecha_scraping']))) ?></div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="info-box glass-soft">
                <small class="text-body-secondary d-block mb-1">Mejor tienda base</small>
                <a href="tienda.php?id=<?= (int) $product['idtiendas'] ?>" class="fw-semibold text-decoration-none">
                  <?= e($product['tie_nombre']) ?>
                </a>
              </div>
            </div>
            <div class="col-md-6">
              <div class="info-box glass-soft">
                <small class="text-body-secondary d-block mb-1">Categoría</small>
                <span class="fw-semibold"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
              </div>
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2 mb-3">
            <a
              href="go.php?id=<?= $bestOfferProductId ?>&source=<?= rawurlencode($goSource) ?>&click_type=ir_mejor_oferta<?= $goTerm !== '' ? '&term=' . rawurlencode($goTerm) : '' ?><?= $bestOfferUrl !== '' && $bestOfferUrl !== '#' ? '&target_url=' . rawurlencode($bestOfferUrl) : '' ?>"
              class="btn btn-primary rounded-pill px-4"
              target="_blank"
              rel="noopener"
            >
              Ir a la mejor oferta
            </a>
            <a href="tienda.php?id=<?= (int) $product['idtiendas'] ?>" class="btn btn-outline-primary rounded-pill px-4">
              Ver tienda
            </a>
            <button
              type="button"
              class="btn btn-outline-danger rounded-pill px-4"
              data-bs-toggle="modal"
              data-bs-target="#reportProductModalMain"
            >
              <i class="bi bi-flag me-1"></i>Reportar producto
            </button>
          </div>

          <div>
            <div class="share-actions-title mb-2">Compartir producto</div>
            <div class="share-actions">
              <a
                href="<?= e($shareWhatsappUrl) ?>"
                target="_blank"
                rel="noopener"
                class="btn btn-outline-success rounded-pill"
              >
                <i class="bi bi-whatsapp"></i>WhatsApp
              </a>
              <a
                href="<?= e($shareFacebookUrl) ?>"
                target="_blank"
                rel="noopener"
                class="btn btn-outline-primary rounded-pill"
              >
                <i class="bi bi-facebook text-primary"></i>Facebook
              </a>
              <a
                href="<?= e($shareTwitterUrl) ?>"
                target="_blank"
                rel="noopener"
                class="btn btn-outline-dark text-secondary rounded-pill"
              >
                <i class="bi bi-twitter-x text-secondary"></i>Twitter/X
              </a>
            </div>
          </div>
        </div>

        <div class="detail-card glass-card p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h4 fw-bold mb-0">Ofertas disponibles</h2>
          </div>

          <?php if ($offers): ?>
            <div class="offer-list d-flex flex-column gap-3">
              <?php foreach ($offers as $offer): ?>
                <div class="offer-list-item glass-soft p-3 p-lg-4">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">

                    <div>
                      <img
                        src="<?= e(image_url($offer['pro_imagen'], $offer['pro_nombre'])) ?>"
                        alt="<?= e($offer['pro_nombre']) ?>"
                        class="offer-product-thumb"
                      >
                    </div>

                    <div class="offer-block">
                      <div class="fw-semibold"><?= e($offer['tie_nombre']) ?></div>
                      <div class="small text-body-secondary">
                        <?= !empty($offer['pro_fecha_scraping']) ? e(date('d/m/Y H:i', strtotime((string) $offer['pro_fecha_scraping']))) : 'Sin fecha' ?>
                      </div>
                    </div>

                    <div class="offer-block flex-grow-1">
                      <div class="small text-body-secondary mb-1">Nombre</div>
                      <div class="fw-semibold offer-original-name">
                        <?= e($offer['pro_nombre'] ?: 'Sin nombre') ?>
                      </div>

                      <?php if (!empty($offer['pro_marca'])): ?>
                        <div class="offer-meta-small text-body-secondary mt-1">
                          Marca: <?= e($offer['pro_marca']) ?>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="offer-price text-end">
                      <div class="small text-body-secondary mb-1">Precio</div>
                      <div class="price-now mb-1">
                        <?= $offer['pro_precio'] !== null ? gs($offer['pro_precio']) : 'Sin precio' ?>
                      </div>

                      <?php if ($offer['pro_precio_anterior'] !== null): ?>
                        <div class="price-old"><?= gs($offer['pro_precio_anterior']) ?></div>
                      <?php endif; ?>
                    </div>

                    <div>
                      <div class="small text-body-secondary mb-1">Estado</div>
                      <span class="mini-badge <?= e(stock_badge_class($offer['pro_en_stock'])) ?>">
                        <?= e(stock_label($offer['pro_en_stock'])) ?>
                      </span>
                    </div>

                    <div class="offer-actions d-flex align-items-center gap-2">
                      <?php if ($favoritesEnabled): ?>
                        <?php $offerIsFavorite = $userLogged ? is_favorite_product((int) $offer['idproductos']) : false; ?>
                        <a
                          href="<?= $userLogged ? e(favorite_toggle_url((int) $offer['idproductos'], $_SERVER['REQUEST_URI'])) : 'login.php' ?>"
                          class="btn <?= $userLogged ? ($offerIsFavorite ? 'btn-danger' : 'btn-outline-danger') : 'btn-outline-danger' ?> rounded-pill"
                          title="<?= $userLogged ? ($offerIsFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos') : 'Iniciá sesión para guardar favoritos' ?>"
                        >
                          <i class="bi <?= $userLogged && $offerIsFavorite ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                        </a>
                      <?php endif; ?>

                      <a
                        href="go.php?id=<?= (int) $offer['idproductos'] ?>&source=producto&click_type=ir_oferta<?= $searchTerm !== '' ? '&term=' . rawurlencode($searchTerm) : '' ?><?= !empty($offer['pro_url']) ? '&target_url=' . rawurlencode((string) $offer['pro_url']) : '' ?>"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-outline-primary rounded-pill"
                      >
                        Ver
                      </a>

                      <button
                        type="button"
                        class="btn btn-outline-danger rounded-pill"
                        data-bs-toggle="modal"
                        data-bs-target="#reportProductModal<?= (int) $offer['idproductos'] ?>"
                        title="Reportar producto"
                      >
                        <i class="bi bi-flag"></i>
                      </button>
                    </div>

                  </div>
                </div>

                <?php ob_start(); ?>
                <div class="modal fade" id="reportProductModal<?= (int) $offer['idproductos'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered modal-sm">
                    <div class="modal-content border-0 shadow-lg">
                      <form method="post">
                        <div class="modal-header">
                          <h5 class="modal-title">Reportar producto</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>

                        <div class="modal-body">
                          <input type="hidden" name="action" value="report_product">
                          <input type="hidden" name="product_id" value="<?= (int) $offer['idproductos'] ?>">

                          <div class="small text-body-secondary mb-3">
                            <?= e($offer['tie_nombre']) ?> · <?= e($offer['pro_nombre']) ?>
                          </div>

                          <div class="mb-3">
                            <label class="form-label">Motivo</label>
                            <select name="rep_motivo" class="form-select" required>
                              <option value="">Elegir</option>
                              <option value="precio_incorrecto">Precio incorrecto</option>
                              <option value="desactualizado">Falta actualizar</option>
                              <option value="sin_stock">Sin stock</option>
                              <option value="producto_equivocado">Producto equivocado</option>
                              <option value="informacion_falsa">Información falsa</option>
                              <option value="otro">Otro</option>
                            </select>
                          </div>

                          <div class="mb-3">
                            <label class="form-label">Tu nombre</label>
                            <input type="text" name="rep_nombre" class="form-control" maxlength="120" placeholder="Opcional">
                          </div>

                          <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="rep_email" class="form-control" maxlength="150" placeholder="Opcional">
                          </div>

                          <div class="mb-0">
                            <label class="form-label">Detalle</label>
                            <textarea
                              name="rep_detalle"
                              class="form-control"
                              rows="3"
                              maxlength="1000"
                              placeholder="Opcional"></textarea>
                          </div>
                        </div>

                        <div class="modal-footer">
                          <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                          <button type="submit" class="btn btn-danger rounded-pill">Enviar reporte</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <?php $productReportModals[] = ob_get_clean(); ?>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="empty-state">No hay ofertas disponibles para este grupo todavía.</div>
          <?php endif; ?>
        </div>

        <div class="detail-card glass-card p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h4 fw-bold mb-0">Histórico mensual de precios</h2>
            <span class="text-body-secondary small"><?= (int) ($historyStats['count'] ?? 0) ?> registro(s)</span>
          </div>

          <?php if (!empty($historyChartDatasets)): ?>
            <div class="row g-3 mb-4">
              <div class="col-md-4">
                <div class="history-stat-card h-100">
                  <div class="history-stat-label mb-1">Precio más bajo</div>
                  <div class="history-stat-value"><?= $historyStats['min'] !== null ? gs($historyStats['min']) : 'Sin datos' ?></div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="history-stat-card h-100">
                  <div class="history-stat-label mb-1">Precio más alto</div>
                  <div class="history-stat-value"><?= $historyStats['max'] !== null ? gs($historyStats['max']) : 'Sin datos' ?></div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="history-stat-card h-100">
                  <div class="history-stat-label mb-1">Último precio registrado</div>
                  <div class="history-stat-value"><?= $historyStats['latest'] !== null ? gs($historyStats['latest']) : 'Sin datos' ?></div>
                  <?php if (!empty($historyStats['latestDate'])): ?>
                    <div class="small text-body-secondary mt-1"><?= e(date('d/m/Y H:i', strtotime((string) $historyStats['latestDate']))) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="history-chart-wrap mb-4">
              <canvas id="priceHistoryChart" class="history-chart-canvas"></canvas>
            </div>

            <div class="table-responsive">
              <table class="table align-middle mb-0 custom-table">
                <thead>
                  <tr>
                    <th>Mes</th>
                    <th>Tienda</th>
                    <th>Precio</th>
                    <th>Stock</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($history as $entry): ?>
                    <tr>
                      <td><?= e($entry['month_label'] ?? date('m/Y', strtotime((string) $entry['his_fecha']))) ?></td>
                      <td><?= e($entry['tie_nombre'] ?? 'Sin tienda') ?></td>
                      <td><?= gs($entry['his_precio']) ?></td>
                      <td>
                        <span class="mini-badge <?= e(stock_badge_class($entry['his_en_stock'])) ?>">
                          <?= e(stock_label($entry['his_en_stock'])) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="empty-state">No hay historial de precio para este producto todavía.</div>
          <?php endif; ?>
        </div>

        <div class="detail-card glass-card p-4">
          <h2 class="h4 fw-bold mb-3">Más productos de esta tienda</h2>
          <div class="row g-3">
            <?php if ($related): ?>
              <?php foreach ($related as $item): ?>
                <div class="col-md-6">
                  <a href="producto.php?id=<?= (int) $item['idproductos'] ?>" class="related-card fancy-hover text-decoration-none text-reset d-flex gap-3 align-items-center h-100">
                    <img src="<?= e(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>" alt="<?= e($item['pro_nombre']) ?>" class="related-thumb">
                    <div>
                      <div class="fw-semibold line-clamp-2"><?= e($item['pro_nombre']) ?></div>
                      <div class="text-body-secondary small"><?= gs($item['pro_precio']) ?></div>
                    </div>
                  </a>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="col-12"><div class="empty-state">No hay más productos relacionados para esta tienda.</div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php ob_start(); ?>
<div class="modal fade" id="reportProductModalMain" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Reportar producto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="action" value="report_product">
          <input type="hidden" name="product_id" value="<?= (int) $product['idproductos'] ?>">

          <div class="small text-body-secondary mb-3">
            <?= e($product['tie_nombre']) ?> · <?= e($groupName ?: $product['pro_nombre']) ?>
          </div>

          <div class="mb-3">
            <label class="form-label">Motivo</label>
            <select name="rep_motivo" class="form-select" required>
              <option value="">Elegir</option>
              <option value="precio_incorrecto">Precio incorrecto</option>
              <option value="desactualizado">Falta actualizar</option>
              <option value="sin_stock">Sin stock</option>
              <option value="producto_equivocado">Producto equivocado</option>
              <option value="informacion_falsa">Información falsa</option>
              <option value="otro">Otro</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Tu nombre</label>
            <input type="text" name="rep_nombre" class="form-control" maxlength="120" placeholder="Opcional">
          </div>

          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="rep_email" class="form-control" maxlength="150" placeholder="Opcional">
          </div>

          <div class="mb-0">
            <label class="form-label">Detalle</label>
            <textarea
              name="rep_detalle"
              class="form-control"
              rows="3"
              maxlength="1000"
              placeholder="Opcional"></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger rounded-pill">Enviar reporte</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php $productReportModals[] = ob_get_clean(); ?>

<?php if ($productReportModals): ?>
  <?= implode("\n", $productReportModals) ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.priceHistoryLabels = <?= json_encode($historyChartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.priceHistoryDatasets = <?= json_encode($historyChartDatasets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const slides = Array.from(document.querySelectorAll('.product-gallery-slide'));
  const thumbs = Array.from(document.querySelectorAll('.gallery-thumb'));
  const prevBtn = document.getElementById('galleryPrev');
  const nextBtn = document.getElementById('galleryNext');
  const gallery = document.getElementById('productGallery');
  const progressBar = document.getElementById('galleryProgressBar');
  const autoplayDelay = 4000;
  let current = 0;
  let autoplayTimer = null;
  let progressTimer = null;
  let progress = 0;

  function showSlide(index) {
    if (!slides.length) return;
    current = (index + slides.length) % slides.length;

    slides.forEach((slide, i) => {
      slide.classList.toggle('active', i === current);
    });

    thumbs.forEach((thumb, i) => {
      thumb.classList.toggle('active', i === current);
    });

    if (progressBar) {
      progressBar.style.width = '0%';
    }
  }

  function stopProgress() {
    if (progressTimer) {
      clearInterval(progressTimer);
      progressTimer = null;
    }
  }

  function startProgress() {
    stopProgress();
    progress = 0;
    if (!progressBar) return;

    progressBar.style.width = '0%';
    progressTimer = setInterval(function () {
      progress += 100 / (autoplayDelay / 100);
      progressBar.style.width = Math.min(progress, 100) + '%';
    }, 100);
  }

  function stopAutoplay() {
    if (autoplayTimer) {
      clearInterval(autoplayTimer);
      autoplayTimer = null;
    }
    stopProgress();
  }

  function startAutoplay() {
    if (slides.length <= 1) return;
    stopAutoplay();
    startProgress();
    autoplayTimer = setInterval(function () {
      showSlide(current + 1);
      startProgress();
    }, autoplayDelay);
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      showSlide(current - 1);
      startAutoplay();
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      showSlide(current + 1);
      startAutoplay();
    });
  }

  thumbs.forEach(function (thumb, idx) {
    thumb.addEventListener('click', function () {
      showSlide(idx);
      startAutoplay();
    });
  });

  if (gallery && slides.length > 1) {
    gallery.addEventListener('mouseenter', stopAutoplay);
    gallery.addEventListener('mouseleave', startAutoplay);
  }

  showSlide(0);
  startAutoplay();

  const historyCanvas = document.getElementById('priceHistoryChart');
  if (historyCanvas && window.priceHistoryDatasets && window.priceHistoryDatasets.length && window.Chart) {
    new Chart(historyCanvas, {
      type: 'bar',
      data: {
        labels: Array.isArray(window.priceHistoryLabels) ? window.priceHistoryLabels : [],
        datasets: window.priceHistoryDatasets
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        scales: {
          x: {
            ticks: {
              maxRotation: 0,
              autoSkip: true
            },
            grid: {
              display: false
            }
          },
          y: {
            beginAtZero: false,
            ticks: {
              callback: function(value) {
                try {
                  return 'Gs. ' + Number(value).toLocaleString('es-PY');
                } catch (e) {
                  return value;
                }
              }
            }
          }
        },
        plugins: {
          legend: {
            position: 'bottom'
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                const value = context.parsed.y;
                return context.dataset.label + ': Gs. ' + Number(value).toLocaleString('es-PY');
              }
            }
          }
        }
      }
    });
  }
});
</script>

<?php render_footer(); ?>