<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('ID de tienda inválido.');
}

$q = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['orden'] ?? 'recientes');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$pdo = db();

function store_sort_sql(string $sort, bool $hasSearch): string
{
    $allowed = [
        'recientes',
        'precio_asc',
        'precio_desc',
        'nombre_asc',
        'nombre_desc',
    ];

    if (!in_array($sort, $allowed, true)) {
        $sort = 'recientes';
    }

    return match ($sort) {
        'precio_asc' => 'p.pro_precio ASC, p.pro_nombre ASC',
        'precio_desc' => 'p.pro_precio DESC, p.pro_nombre ASC',
        'nombre_asc' => 'p.pro_nombre ASC',
        'nombre_desc' => 'p.pro_nombre DESC',
        default => $hasSearch
            ? 'CASE WHEN p.pro_nombre LIKE :q_sort THEN 0 WHEN p.pro_marca LIKE :q_sort THEN 1 ELSE 2 END, p.pro_fecha_scraping DESC, p.pro_nombre ASC'
            : 'p.pro_fecha_scraping DESC, p.pro_nombre ASC',
    };
}

function h(?string $value): string
{
    return e((string) ($value ?? ''));
}

function build_store_page_url(int $storeId, array $extra = []): string
{
    $params = array_merge([
        'id' => $storeId,
    ], $extra);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return 'tienda.php?' . http_build_query($params);
}

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

function store_user_is_admin(): bool
{
    if (function_exists('is_admin')) {
        try {
            return (bool) is_admin();
        } catch (Throwable $e) {
        }
    }

    $checks = [
        $_SESSION['usuario']['usu_tipo'] ?? null,
        $_SESSION['user']['usu_tipo'] ?? null,
        $_SESSION['auth']['usu_tipo'] ?? null,
        $_SESSION['usuario']['tipo'] ?? null,
        $_SESSION['user']['tipo'] ?? null,
        $_SESSION['usu_tipo'] ?? null,
        $_SESSION['tipo'] ?? null,
    ];

    foreach ($checks as $value) {
        if ((string) $value === '1' || (string) $value === 'admin') {
            return true;
        }
    }

    return false;
}

$storeStmt = $pdo->prepare("
    SELECT
        t.*,
        COUNT(p.idproductos) AS total_productos,
        MAX(p.pro_fecha_scraping) AS ultima_actualizacion,
        (
            SELECT COUNT(*)
            FROM tienda_reviews tr
            WHERE tr.tiendas_idtiendas = t.idtiendas AND tr.rev_activo = 1
        ) AS total_reviews,
        (
            SELECT ROUND(AVG(tr.rev_puntaje), 1)
            FROM tienda_reviews tr
            WHERE tr.tiendas_idtiendas = t.idtiendas AND tr.rev_activo = 1
        ) AS rating_promedio
    FROM tiendas t
    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas AND p.pro_activo = 1
    WHERE t.idtiendas = :id
    GROUP BY
        t.idtiendas,
        t.tie_nombre,
        t.tie_descripcion,
        t.tie_logo,
        t.tie_ubicacion,
        t.tie_url,
        t.tie_telefono,
        t.tie_email,
        t.tie_contacto,
        t.tie_horarios
    LIMIT 1
");
$storeStmt->execute([':id' => $id]);
$store = $storeStmt->fetch();

if (!$store) {
    http_response_code(404);
    die('Tienda no encontrada.');
}

$isAdmin = store_user_is_admin();
$reviewSuccess = isset($_GET['review_saved']) && $_GET['review_saved'] === '1';
$reviewDeleted = isset($_GET['review_deleted']) && $_GET['review_deleted'] === '1';
$reviewReported = isset($_GET['review_reported']) && $_GET['review_reported'] === '1';
$reviewError = '';
$reportError = '';
$reviewModals = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_review') {
    if (!$isAdmin) {
        http_response_code(403);
        die('No autorizado.');
    }

    $reviewId = (int) ($_POST['review_id'] ?? 0);
    if ($reviewId > 0) {
        $deleteReview = $pdo->prepare("
            UPDATE tienda_reviews
            SET rev_activo = 0
            WHERE idreview = :review_id AND tiendas_idtiendas = :store_id
            LIMIT 1
        ");
        $deleteReview->execute([
            ':review_id' => $reviewId,
            ':store_id' => $id,
        ]);
    }

    header('Location: ' . build_store_page_url($id, [
        'q' => $q,
        'orden' => $sort,
        'page' => $page,
        'review_deleted' => 1,
    ]) . '#reviews');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'report_review') {
    $reviewId = (int) ($_POST['review_id'] ?? 0);
    $reportName = trim((string) ($_POST['rep_nombre'] ?? ''));
    $reportEmail = trim((string) ($_POST['rep_email'] ?? ''));
    $reportReason = trim((string) ($_POST['rep_motivo'] ?? ''));
    $reportDetail = trim((string) ($_POST['rep_detalle'] ?? ''));

    $allowedReasons = [
        'spam',
        'ofensivo',
        'informacion_falsa',
        'lenguaje_inapropiado',
        'otro',
    ];

    $sessionId = session_id();
    $ipAddress = client_ip_address();

    if ($reviewId <= 0) {
        $reportError = 'La reseña seleccionada no es válida.';
    } elseif (!in_array($reportReason, $allowedReasons, true)) {
        $reportError = 'Seleccioná un motivo válido.';
    } else {
        $reviewExistsStmt = $pdo->prepare("
            SELECT idreview
            FROM tienda_reviews
            WHERE idreview = :review_id
              AND tiendas_idtiendas = :store_id
              AND rev_activo = 1
            LIMIT 1
        ");
        $reviewExistsStmt->execute([
            ':review_id' => $reviewId,
            ':store_id' => $id,
        ]);

        if (!$reviewExistsStmt->fetchColumn()) {
            $reportError = 'No se encontró la reseña.';
        } else {
            $duplicateSql = "
                SELECT idreporte
                FROM tienda_review_reportes
                WHERE reviews_idreview = :review_id
            ";

            $duplicateParams = [
                ':review_id' => $reviewId,
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
                    $reportError = 'Ya reportaste esta reseña anteriormente.';
                }
            }

            if ($reportError === '') {
                $insertReport = $pdo->prepare("
                    INSERT INTO tienda_review_reportes (
                        reviews_idreview,
                        rep_nombre,
                        rep_email,
                        rep_motivo,
                        rep_detalle,
                        rep_ip,
                        rep_session_id,
                        rep_estado,
                        rep_fecha
                    ) VALUES (
                        :review_id,
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
                    ':review_id' => $reviewId,
                    ':nombre' => $reportName !== '' ? mb_substr($reportName, 0, 120) : null,
                    ':email' => $reportEmail !== '' ? mb_substr($reportEmail, 0, 150) : null,
                    ':motivo' => $reportReason,
                    ':detalle' => $reportDetail !== '' ? mb_substr($reportDetail, 0, 1000) : null,
                    ':ip' => $ipAddress !== '' ? $ipAddress : null,
                    ':session_id' => $sessionId !== '' ? $sessionId : null,
                ]);

                header('Location: ' . build_store_page_url($id, [
                    'q' => $q,
                    'orden' => $sort,
                    'page' => $page,
                    'review_reported' => 1,
                ]) . '#reviews');
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_review') {
    $reviewName = trim((string) ($_POST['rev_nombre'] ?? ''));
    $reviewComment = trim((string) ($_POST['rev_comentario'] ?? ''));
    $reviewScore = (int) ($_POST['rev_puntaje'] ?? 0);

    if ($reviewName === '') {
        $reviewError = 'Ingresá tu nombre para dejar una reseña.';
    } elseif ($reviewScore < 1 || $reviewScore > 5) {
        $reviewError = 'Seleccioná una puntuación válida entre 1 y 5 estrellas.';
    } elseif ($reviewComment === '') {
        $reviewError = 'Escribí un comentario para la reseña.';
    } else {
        $insertReview = $pdo->prepare("
            INSERT INTO tienda_reviews (
                tiendas_idtiendas,
                rev_nombre,
                rev_puntaje,
                rev_comentario,
                rev_activo,
                rev_fecha
            ) VALUES (
                :tienda,
                :nombre,
                :puntaje,
                :comentario,
                1,
                NOW()
            )
        ");
        $insertReview->execute([
            ':tienda' => $id,
            ':nombre' => mb_substr($reviewName, 0, 120),
            ':puntaje' => $reviewScore,
            ':comentario' => mb_substr($reviewComment, 0, 1000),
        ]);

        header('Location: ' . build_store_page_url($id, ['review_saved' => 1]) . '#reviews');
        exit;
    }
}

$mapEmbedUrl = '';
$mapOpenUrl = '';
if (!empty($store['tie_ubicacion'])) {
    $rawLocation = trim((string) $store['tie_ubicacion']);

    $isUrl = preg_match('~^https?://~i', $rawLocation) === 1;
    $isCoords = preg_match('~^\s*-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?\s*$~', $rawLocation) === 1;

    $mapOpenUrl = $isUrl
        ? $rawLocation
        : 'https://www.google.com/maps?q=' . rawurlencode($rawLocation);

    if ($isCoords) {
        $mapEmbedUrl = 'https://www.google.com/maps?q=' . rawurlencode($rawLocation) . '&output=embed';
    } else {
        $mapQueryParts = array_filter([
            trim((string) ($store['tie_nombre'] ?? '')),
            trim((string) ($store['tie_descripcion'] ?? '')),
        ]);

        $mapQuery = trim(implode(' ', $mapQueryParts));
        if ($mapQuery === '') {
            $mapQuery = $rawLocation;
        }

        $mapEmbedUrl = 'https://www.google.com/maps?q=' . rawurlencode($mapQuery) . '&output=embed';
    }
}

$where = ['p.tiendas_idtiendas = :id', 'p.pro_activo = 1'];
$baseParams = [':id' => $id];
$hasSearch = $q !== '';

if ($hasSearch) {
    $where[] = '(p.pro_nombre LIKE :q_filter OR p.pro_descripcion LIKE :q_filter OR p.pro_marca LIKE :q_filter)';
    $baseParams[':q_filter'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);
$orderSql = store_sort_sql($sort, $hasSearch);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM productos p
    WHERE {$whereSql}
");
$countStmt->execute($baseParams);
$totalProductsFiltered = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProductsFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$productParams = $baseParams;
if ($hasSearch && str_contains($orderSql, ':q_sort')) {
    $productParams[':q_sort'] = '%' . $q . '%';
}

$productStmt = $pdo->prepare("
    SELECT
        p.idproductos,
        p.pro_nombre,
        p.pro_descripcion,
        p.pro_marca,
        p.pro_precio,
        p.pro_precio_anterior,
        p.pro_imagen,
        p.pro_en_stock,
        p.pro_fecha_scraping,
        c.cat_nombre
    FROM productos p
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT {$perPage} OFFSET {$offset}
");
$productStmt->execute($productParams);
$products = $productStmt->fetchAll();

$categoryBreakdown = $pdo->prepare("
    SELECT COALESCE(c.cat_nombre, 'Sin categoría') AS cat_nombre, COUNT(*) AS total
    FROM productos p
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE p.tiendas_idtiendas = :id AND p.pro_activo = 1
    GROUP BY COALESCE(c.cat_nombre, 'Sin categoría')
    ORDER BY total DESC, cat_nombre ASC
    LIMIT 6
");
$categoryBreakdown->execute([':id' => $id]);
$categories = $categoryBreakdown->fetchAll();

$reviewsStmt = $pdo->prepare("
    SELECT idreview, rev_nombre, rev_puntaje, rev_comentario, rev_fecha
    FROM tienda_reviews
    WHERE tiendas_idtiendas = :id AND rev_activo = 1
    ORDER BY rev_fecha DESC, idreview DESC
    LIMIT 12
");
$reviewsStmt->execute([':id' => $id]);
$reviews = $reviewsStmt->fetchAll();

render_head($store['tie_nombre']);
render_navbar('tienda');
?>

<style>
.modal {
  z-index: 2000 !important;
}

.modal-backdrop {
  z-index: 1990 !important;
}

.site-bg,
.bg-orb,
.bg-grid {
  pointer-events: none;
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
        <li class="breadcrumb-item"><a href="index.php#tiendas">Tiendas</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= h($store['tie_nombre']) ?></li>
      </ol>
    </nav>

    <div class="store-hero glass-card p-4 p-lg-5 mb-4">
      <div class="row g-4 align-items-start">
        <div class="col-lg-8">
          <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <div class="store-logo d-flex align-items-center justify-content-center store-detail-logo">
              <?php if (!empty($store['tie_logo'])): ?>
                <img src="<?= h($store['tie_logo']) ?>" alt="<?= h($store['tie_nombre']) ?>" class="img-fluid rounded-3 store-logo-img">
              <?php else: ?>
                <span class="fw-bold fs-2"><?= h(mb_strtoupper(mb_substr((string) $store['tie_nombre'], 0, 2))) ?></span>
              <?php endif; ?>
            </div>

            <div class="flex-grow-1">
              <span class="badge soft-badge mb-2">Tienda</span>
              <h1 class="display-6 fw-bold mb-2"><?= h($store['tie_nombre']) ?></h1>

              <div class="d-flex flex-wrap gap-3 small text-body-secondary mb-3">
                <span>
                  <i class="bi bi-star-fill text-warning me-1"></i>
                  <?= number_format((float) ($store['rating_promedio'] ?? 0), 1, ',', '.') ?> / 5
                </span>
                <span>
                  <i class="bi bi-chat-left-text me-1"></i>
                  <?= number_format((int) ($store['total_reviews'] ?? 0), 0, ',', '.') ?> reseña(s)
                </span>
              </div>

              <div class="mini-row glass-soft">
                <div class="small text-body-secondary mb-1">Descripción</div>
                <div class="store-description-full" style="white-space: pre-line; line-height: 1.65;">
                  <?= h($store['tie_descripcion'] ?: 'Catálogo disponible y actualizado dentro de la plataforma.') ?>
                </div>
              </div>
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <?php if ($mapOpenUrl !== ''): ?>
              <a href="<?= h($mapOpenUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary rounded-pill px-4">
                <i class="bi bi-geo-alt-fill me-2"></i>Ver ubicación
              </a>
            <?php endif; ?>

            <?php if (!empty($store['tie_url'])): ?>
              <a href="<?= h($store['tie_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary rounded-pill px-4">
                <i class="bi bi-globe2 me-2"></i>Ir al sitio
              </a>
            <?php endif; ?>

            <a href="index.php#tiendas" class="btn btn-outline-secondary rounded-pill px-4">Volver</a>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="row g-3">
            <div class="col-6 col-lg-12 col-xl-6">
              <div class="stats-card p-3 h-100">
                <div class="small text-body-secondary mb-1">Productos</div>
                <div class="fw-bold fs-4"><?= number_format((int) $store['total_productos'], 0, ',', '.') ?></div>
              </div>
            </div>

            <div class="col-6 col-lg-12 col-xl-6">
              <div class="stats-card p-3 h-100">
                <div class="small text-body-secondary mb-1">Reseñas</div>
                <div class="fw-bold fs-4"><?= number_format((int) ($store['total_reviews'] ?? 0), 0, ',', '.') ?></div>
              </div>
            </div>

            <div class="col-12">
              <div class="stats-card p-3 h-100">
                <div class="small text-body-secondary mb-1">Última actualización</div>
                <div class="fw-bold small">
                  <?= $store['ultima_actualizacion'] ? h(date('d/m/Y H:i', strtotime((string) $store['ultima_actualizacion']))) : 'Sin datos' ?>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="stats-card p-3 h-100">
                <div class="small text-body-secondary mb-1">Horarios</div>

                <?php if (!empty($store['tie_horarios'])): ?>
                  <div class="small" style="white-space: pre-line; line-height: 1.5;">
                    <?= h($store['tie_horarios']) ?>
                  </div>
                <?php else: ?>
                  <div class="small text-body-secondary">No disponible</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-3">
        <div class="detail-card glass-card p-4 mb-4">
          <h2 class="h5 fw-bold mb-3">Buscar en la tienda</h2>
          <form method="get" action="tienda.php" class="d-grid gap-3">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Nombre, descripción o marca">
            <select class="form-select" name="orden">
              <?php foreach (active_sort_options() as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary rounded-pill">Aplicar</button>
          </form>
        </div>

        <div class="detail-card glass-card p-4 mb-4">
          <h2 class="h5 fw-bold mb-3">Contacto</h2>
          <div class="d-grid gap-2 small">
            <?php if (!empty($store['tie_contacto'])): ?>
              <div><i class="bi bi-person-badge me-2"></i><?= h($store['tie_contacto']) ?></div>
            <?php endif; ?>
            <?php if (!empty($store['tie_telefono'])): ?>
              <div><i class="bi bi-telephone me-2"></i><?= h($store['tie_telefono']) ?></div>
            <?php endif; ?>
            <?php if (!empty($store['tie_email'])): ?>
              <div><i class="bi bi-envelope me-2"></i><?= h($store['tie_email']) ?></div>
            <?php endif; ?>
            <?php if (!empty($store['tie_url'])): ?>
              <div class="text-break"><i class="bi bi-globe2 me-2"></i><?= h($store['tie_url']) ?></div>
            <?php endif; ?>
            <?php if (empty($store['tie_contacto']) && empty($store['tie_telefono']) && empty($store['tie_email']) && empty($store['tie_url'])): ?>
              <div class="empty-state small">Todavía no se cargó información de contacto.</div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($mapEmbedUrl !== ''): ?>
          <div class="detail-card glass-card p-3 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="h5 fw-bold mb-0">Ubicación</h2>
              <a href="<?= h($mapOpenUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary rounded-pill">
                <i class="bi bi-geo-alt-fill me-1"></i>Abrir</a>
            </div>

            <div class="ratio ratio-4x3 rounded-4 overflow-hidden border">
              <iframe
                src="<?= h($mapEmbedUrl) ?>"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                style="border:0;"
                allowfullscreen>
              </iframe>
            </div>
          </div>
        <?php endif; ?>

        <div class="detail-card glass-card p-4 mb-4">
          <h2 class="h5 fw-bold mb-3">Categorías frecuentes</h2>
          <div class="d-grid gap-2">
            <?php if ($categories): ?>
              <?php foreach ($categories as $category): ?>
                <div class="d-flex justify-content-between align-items-center mini-row glass-soft">
                  <span><?= h($category['cat_nombre']) ?></span>
                  <span class="mini-badge badge-neutral"><?= (int) $category['total'] ?></span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state small">Todavía no hay productos categorizados.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-9">
        <div class="detail-card glass-card p-4 mb-4">
          <div class="d-flex justify-content-between align-items-end gap-3 mb-4 flex-wrap">
            <div>
              <h2 class="h4 fw-bold mb-1">Productos de <?= h($store['tie_nombre']) ?></h2>
              <p class="text-body-secondary mb-0">Explorá el catálogo disponible de esta tienda.</p>
            </div>
            <div class="small text-body-secondary">
              <?= number_format($totalProductsFiltered, 0, ',', '.') ?> resultado(s) · Página <?= $page ?> de <?= $totalPages ?>
            </div>
          </div>

          <div class="row g-3">
            <?php if ($products): ?>
              <?php foreach ($products as $product): ?>
                <div class="col-md-6">
                  <article class="related-card related-card-lg fancy-hover h-100">
                    <img src="<?= h(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>" alt="<?= h($product['pro_nombre']) ?>" class="related-thumb related-thumb-lg">

                    <div class="flex-grow-1">
                      <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <span class="badge soft-badge"><?= h($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                        <span class="mini-badge <?= h(stock_badge_class($product['pro_en_stock'])) ?>">
                          <?= h(stock_label($product['pro_en_stock'])) ?>
                        </span>
                      </div>

                      <h3 class="h6 fw-bold mb-2 line-clamp-2"><?= h($product['pro_nombre']) ?></h3>

                      <p class="text-body-secondary small mb-2 line-clamp-2">
                        <?= h($product['pro_descripcion'] ?: 'Sin descripción.') ?>
                      </p>

                      <?php if (!empty($product['pro_marca'])): ?>
                        <div class="small text-body-secondary mb-3">Marca: <strong><?= h($product['pro_marca']) ?></strong></div>
                      <?php endif; ?>

                      <div class="d-flex justify-content-between align-items-end gap-2 flex-wrap">
                        <div>
                          <div class="price-now"><?= gs($product['pro_precio']) ?></div>
                          <?php if ($product['pro_precio_anterior'] !== null): ?>
                            <div class="price-old"><?= gs($product['pro_precio_anterior']) ?></div>
                          <?php endif; ?>
                        </div>

                        <a href="producto.php?id=<?= (int) $product['idproductos'] ?>" class="btn btn-sm btn-primary rounded-pill px-3">
                          Ver detalle
                        </a>
                      </div>
                    </div>
                  </article>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="col-12">
                <div class="empty-state">No se encontraron productos para esta tienda con esos filtros.</div>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($totalPages > 1): ?>
            <nav class="mt-4" aria-label="Paginación de productos de tienda">
              <ul class="pagination justify-content-center flex-wrap gap-2 mb-0">
                <?php $prevDisabled = $page <= 1; ?>
                <li class="page-item <?= $prevDisabled ? 'disabled' : '' ?>">
                  <a class="page-link rounded-pill" href="<?= $prevDisabled ? '#' : h(build_store_page_url($id, ['q' => $q, 'orden' => $sort, 'page' => $page - 1])) ?>">Anterior</a>
                </li>

                <?php
                  $startPage = max(1, $page - 2);
                  $endPage = min($totalPages, $page + 2);
                  if ($startPage > 1) {
                      $startPage = 1;
                      $endPage = min($totalPages, 5);
                  }
                  if ($endPage - $startPage < 4) {
                      $startPage = max(1, $endPage - 4);
                  }
                ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                  <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link rounded-pill" href="<?= h(build_store_page_url($id, ['q' => $q, 'orden' => $sort, 'page' => $i])) ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>

                <?php $nextDisabled = $page >= $totalPages; ?>
                <li class="page-item <?= $nextDisabled ? 'disabled' : '' ?>">
                  <a class="page-link rounded-pill" href="<?= $nextDisabled ? '#' : h(build_store_page_url($id, ['q' => $q, 'orden' => $sort, 'page' => $page + 1])) ?>">Siguiente</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        </div>

        <div class="detail-card glass-card p-4 mb-4" id="reviews">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="h4 fw-bold mb-0">Reseñas</h2>
            <div class="small text-body-secondary">
              <?= number_format((float) ($store['rating_promedio'] ?? 0), 1, ',', '.') ?> / 5 · <?= number_format((int) ($store['total_reviews'] ?? 0), 0, ',', '.') ?> reseña(s)
            </div>
          </div>

          <?php if ($reviewSuccess): ?>
            <div class="alert alert-success">Tu reseña se guardó correctamente.</div>
          <?php endif; ?>

          <?php if ($reviewDeleted): ?>
            <div class="alert alert-success">La reseña fue eliminada correctamente.</div>
          <?php endif; ?>

          <?php if ($reviewReported): ?>
            <div class="alert alert-warning">La reseña fue reportada correctamente.</div>
          <?php endif; ?>

          <?php if ($reviewError !== ''): ?>
            <div class="alert alert-danger"><?= h($reviewError) ?></div>
          <?php endif; ?>

          <?php if ($reportError !== ''): ?>
            <div class="alert alert-danger"><?= h($reportError) ?></div>
          <?php endif; ?>

          <form method="post" class="row g-3 mb-4">
            <input type="hidden" name="action" value="save_review">
            <div class="col-md-4">
              <label class="form-label">Tu nombre</label>
              <input type="text" name="rev_nombre" class="form-control" maxlength="120" value="<?= h($_POST['rev_nombre'] ?? '') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Estrellas</label>
              <select name="rev_puntaje" class="form-select" required>
                <option value="">Elegir</option>
                <?php for ($i = 5; $i >= 1; $i--): ?>
                  <option value="<?= $i ?>" <?= (string) ($_POST['rev_puntaje'] ?? '') === (string) $i ? 'selected' : '' ?>><?= $i ?> estrella(s)</option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Comentario</label>
              <textarea name="rev_comentario" class="form-control" rows="4" maxlength="1000" required><?= h($_POST['rev_comentario'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary rounded-pill px-4">Enviar reseña</button>
            </div>
          </form>

          <div class="d-grid gap-3">
            <?php if ($reviews): ?>
              <?php foreach ($reviews as $review): ?>
                <article class="mini-row glass-soft p-3">
                  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-2">
                    <div>
                      <div class="fw-semibold"><?= h($review['rev_nombre']) ?></div>
                      <div class="small text-body-secondary"><?= h(date('d/m/Y H:i', strtotime((string) $review['rev_fecha']))) ?></div>
                    </div>

                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <div class="small fw-semibold text-warning">
                        <?= str_repeat('★', (int) $review['rev_puntaje']) ?><?= str_repeat('☆', max(0, 5 - (int) $review['rev_puntaje'])) ?>
                      </div>
                    </div>
                  </div>

                  <div style="white-space: pre-line; line-height: 1.6;">
                    <?= h($review['rev_comentario']) ?>
                  </div>

                  <div class="d-flex align-items-center gap-2 flex-wrap mt-3">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-danger rounded-pill"
                      data-bs-toggle="modal"
                      data-bs-target="#reportReviewModal<?= (int) $review['idreview'] ?>"
                    >
                      <i class="bi bi-flag me-1"></i>Reportar
                    </button>

                    <?php if ($isAdmin): ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta reseña?');">
                        <input type="hidden" name="action" value="delete_review">
                        <input type="hidden" name="review_id" value="<?= (int) $review['idreview'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill">
                          Eliminar
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </article>

                <?php ob_start(); ?>
                <div class="modal fade" id="reportReviewModal<?= (int) $review['idreview'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered modal-sm">
                    <div class="modal-content border-0 shadow-lg">
                      <form method="post">
                        <div class="modal-header">
                          <h5 class="modal-title">Reportar reseña</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>

                        <div class="modal-body">
                          <input type="hidden" name="action" value="report_review">
                          <input type="hidden" name="review_id" value="<?= (int) $review['idreview'] ?>">

                          <div class="mb-3">
                            <label class="form-label">Motivo</label>
                            <select name="rep_motivo" class="form-select" required>
                              <option value="">Elegir</option>
                              <option value="spam">Spam</option>
                              <option value="ofensivo">Ofensivo</option>
                              <option value="informacion_falsa">Información falsa</option>
                              <option value="lenguaje_inapropiado">Lenguaje inapropiado</option>
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
                <?php $reviewModals[] = ob_get_clean(); ?>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">Todavía no hay reseñas activas para esta tienda.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if ($reviewModals): ?>
  <?= implode("\n", $reviewModals) ?>
<?php endif; ?>

<?php render_footer(); ?>
