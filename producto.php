<?php
require_once __DIR__ . '/config.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('ID de producto inválido.');
}

$pdo = db();

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

if (!$product) {
    http_response_code(404);
    die('Producto no encontrado.');
}

$historyStmt = $pdo->prepare("
    SELECT his_precio, his_en_stock, his_fecha
    FROM historial_precios
    WHERE productos_idproductos = :id
    ORDER BY his_fecha DESC
    LIMIT 12
");
$historyStmt->execute([':id' => $id]);
$history = $historyStmt->fetchAll();

$offerStmt = $pdo->prepare("
    SELECT
        pp.precio,
        pp.precio_anterior,
        pp.proprecio_url,
        pp.proprecio_imagen,
        pp.proprecio_stock,
        pp.proprecio_fecha_actualizacion,
        t.idtiendas,
        t.tie_nombre,
        t.tie_logo,
        t.tie_url
    FROM productos_precios pp
    INNER JOIN tiendas t ON t.idtiendas = pp.tiendas_idtiendas
    WHERE pp.productos_idproductos = :id
      AND pp.prop_estado = 'activo'
    ORDER BY pp.precio IS NULL, pp.precio ASC, pp.proprecio_fecha_actualizacion DESC
    LIMIT 8
");
$offerStmt->execute([':id' => $id]);
$offers = $offerStmt->fetchAll();

$relatedStmt = $pdo->prepare("
    SELECT idproductos, pro_nombre, pro_precio, pro_imagen, pro_en_stock
    FROM productos
    WHERE tiendas_idtiendas = :tienda
      AND idproductos <> :id
      AND pro_activo = 1
    ORDER BY pro_fecha_scraping DESC
    LIMIT 4
");
$relatedStmt->execute([':tienda' => $product['idtiendas'], ':id' => $id]);
$related = $relatedStmt->fetchAll();

$minPrice = $product['pro_precio'] !== null ? (float) $product['pro_precio'] : null;
foreach ($offers as $offer) {
    if ($offer['precio'] !== null) {
        $offerPrice = (float) $offer['precio'];
        $minPrice = $minPrice === null ? $offerPrice : min($minPrice, $offerPrice);
    }
}

render_head($product['pro_nombre']);
render_navbar('producto');
?>

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
        <li class="breadcrumb-item active" aria-current="page"><?= e($product['pro_nombre']) ?></li>
      </ol>
    </nav>

    <div class="row g-4 align-items-start">
      <div class="col-lg-5">
        <div class="detail-card glass-card p-3 p-lg-4 sticky-lg-top detail-sticky">
          <div class="detail-image-wrap">
            <img src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>" alt="<?= e($product['pro_nombre']) ?>" class="detail-image w-100">
          </div>
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
          </div>

          <h1 class="display-6 fw-bold mb-3"><?= e($product['pro_nombre']) ?></h1>
          <p class="text-body-secondary mb-4"><?= e($product['pro_descripcion'] ?: 'Sin descripción disponible para este producto.') ?></p>

          <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
            <div>
              <div class="price-caption">Precio principal</div>
              <div class="price-now detail-price"><?= gs($product['pro_precio']) ?></div>
              <?php if ($product['pro_precio_anterior'] !== null): ?>
                <div class="price-old"><?= gs($product['pro_precio_anterior']) ?></div>
              <?php endif; ?>
              <?php if ($minPrice !== null && $minPrice < (float) $product['pro_precio']): ?>
                <div class="best-price-note mt-2">Mejor precio encontrado desde <?= gs($minPrice) ?></div>
              <?php endif; ?>
            </div>
            <div class="text-body-secondary small text-md-end">
              <div>Actualizado: <?= e(date('d/m/Y H:i', strtotime((string) $product['pro_fecha_scraping']))) ?></div>
              <div>Tienda base: <?= e($product['tie_nombre']) ?></div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="info-box glass-soft">
                <small class="text-body-secondary d-block mb-1">Tienda</small>
                <a href="tienda.php?id=<?= (int) $product['idtiendas'] ?>" class="fw-semibold text-decoration-none"><?= e($product['tie_nombre']) ?></a>
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
            <a href="<?= e($product['pro_url'] ?: '#') ?>" class="btn btn-primary rounded-pill px-4" target="_blank" rel="noopener">Ir a la oferta</a>
            <a href="tienda.php?id=<?= (int) $product['idtiendas'] ?>" class="btn btn-outline-primary rounded-pill px-4">Ver tienda</a>
          </div>
        </div>

        <div class="detail-card glass-card p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h4 fw-bold mb-0">Comparar precios</h2>
            <small class="text-body-secondary">Tabla conectada con <code>productos_precios</code></small>
          </div>

          <?php if ($offers): ?>
            <div class="row g-3">
              <?php foreach ($offers as $offer): ?>
                <div class="col-md-6">
                  <div class="comparison-card p-3 h-100">
                    <div class="d-flex gap-3 align-items-center mb-3">
                      <div class="store-logo d-flex align-items-center justify-content-center mini-store-logo">
                        <?php if (!empty($offer['tie_logo'])): ?>
                          <img src="<?= e($offer['tie_logo']) ?>" alt="<?= e($offer['tie_nombre']) ?>" class="img-fluid rounded-3 store-logo-img">
                        <?php else: ?>
                          <span class="fw-bold"><?= e(mb_strtoupper(mb_substr($offer['tie_nombre'], 0, 2))) ?></span>
                        <?php endif; ?>
                      </div>
                      <div>
                        <div class="fw-semibold"><?= e($offer['tie_nombre']) ?></div>
                        <div class="small text-body-secondary"><?= !empty($offer['proprecio_fecha_actualizacion']) ? e(date('d/m/Y H:i', strtotime((string) $offer['proprecio_fecha_actualizacion']))) : 'Sin fecha' ?></div>
                      </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-end gap-2 mb-3">
                      <div>
                        <div class="price-now"><?= $offer['precio'] !== null ? gs($offer['precio']) : 'Sin precio' ?></div>
                        <?php if ($offer['precio_anterior'] !== null): ?><div class="price-old"><?= gs($offer['precio_anterior']) ?></div><?php endif; ?>
                      </div>
                      <span class="mini-badge badge-neutral"><?= e($offer['proprecio_stock'] ?: 'Sin dato stock') ?></span>
                    </div>
                    <a href="<?= e($offer['proprecio_url'] ?: '#') ?>" target="_blank" rel="noopener" class="btn btn-outline-primary rounded-pill w-100">Ver oferta</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="empty-state">Este producto todavía no tiene ofertas cargadas en <code>productos_precios</code>.</div>
          <?php endif; ?>
        </div>

        <div class="detail-card glass-card p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h4 fw-bold mb-0">Historial reciente</h2>
            <small class="text-body-secondary">Últimos registros de <code>historial_precios</code></small>
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
                      <td><span class="mini-badge <?= e(stock_badge_class($entry['his_en_stock'])) ?>"><?= e(stock_label($entry['his_en_stock'])) ?></span></td>
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

<?php render_footer(); ?>
