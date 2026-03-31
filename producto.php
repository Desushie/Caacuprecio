<?php
require_once __DIR__ . '/config.php';

$id = (int) ($_GET['id'] ?? 0);
$grupo = trim((string) ($_GET['grupo'] ?? ''));
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$pdo = db();
$userId = function_exists('current_user_id') ? current_user_id() : null;
$sessionId = session_id();

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
 * Historial combinado del grupo
 */
$historySql = "
    SELECT his_precio, his_en_stock, his_fecha
    FROM historial_precios
    WHERE productos_idproductos IN ($placeholders)
    ORDER BY his_fecha DESC
    LIMIT 12
";
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($groupProductIds);
$history = $historyStmt->fetchAll();

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
.product-gallery-slide {
    display: none;
    text-align: center;
    padding: 1rem;
}
.product-gallery-slide.active {
    display: block;
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

          <div class="d-flex flex-wrap gap-2">
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

                    <div class="offer-actions">
                      <a
                        href="go.php?id=<?= (int) $offer['idproductos'] ?>&source=producto&click_type=ir_oferta<?= $searchTerm !== '' ? '&term=' . rawurlencode($searchTerm) : '' ?><?= !empty($offer['pro_url']) ? '&target_url=' . rawurlencode((string) $offer['pro_url']) : '' ?>"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-outline-primary rounded-pill w-100"
                      >
                        Ver
                      </a>
                    </div>

                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="empty-state">No hay ofertas disponibles para este grupo todavía.</div>
          <?php endif; ?>
        </div>

        <div class="detail-card glass-card p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h4 fw-bold mb-0">Historial reciente</h2>
          </div>

          <?php if ($history): ?>
            <div class="table-responsive">
              <table class="table align-middle mb-0 custom-table">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Precio</th>
                    <th>Stock</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($history as $entry): ?>
                    <tr>
                      <td><?= e(date('d/m/Y H:i', strtotime((string) $entry['his_fecha']))) ?></td>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
  const slides = Array.from(document.querySelectorAll('.product-gallery-slide'));
  const thumbs = Array.from(document.querySelectorAll('.gallery-thumb'));
  const prevBtn = document.getElementById('galleryPrev');
  const nextBtn = document.getElementById('galleryNext');

  if (!slides.length) return;

  let current = 0;

  function showSlide(index) {
    current = (index + slides.length) % slides.length;

    slides.forEach((slide, i) => {
      slide.classList.toggle('active', i === current);
    });

    thumbs.forEach((thumb, i) => {
      thumb.classList.toggle('active', i === current);
    });
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', () => showSlide(current - 1));
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', () => showSlide(current + 1));
  }

  thumbs.forEach((thumb, idx) => {
    thumb.addEventListener('click', () => showSlide(idx));
  });

  showSlide(0);
});
</script>

<?php render_footer(); ?>