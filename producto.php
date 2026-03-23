<?php
require_once __DIR__ . '/config.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('ID de producto inválido.');
}

$pdo = db();

$stmt = $pdo->prepare("\n    SELECT\n        p.*,\n        t.idtiendas,\n        t.tie_nombre,\n        t.tie_descripcion,\n        t.tie_logo,\n        t.tie_ubicacion,\n        t.tie_url,\n        c.idcategorias,\n        c.cat_nombre,\n        c.cat_descripcion\n    FROM productos p\n    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas\n    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias\n    WHERE p.idproductos = :id\n    LIMIT 1\n");
$stmt->execute([':id' => $id]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    die('Producto no encontrado.');
}

$historyStmt = $pdo->prepare("\n    SELECT his_precio, his_en_stock, his_fecha\n    FROM historial_precios\n    WHERE productos_idproductos = :id\n    ORDER BY his_fecha DESC\n    LIMIT 12\n");
$historyStmt->execute([':id' => $id]);
$history = $historyStmt->fetchAll();

$relatedStmt = $pdo->prepare("\n    SELECT idproductos, pro_nombre, pro_precio, pro_imagen, pro_en_stock\n    FROM productos\n    WHERE tiendas_idtiendas = :tienda\n      AND idproductos <> :id\n      AND pro_activo = 1\n    ORDER BY pro_fecha_scraping DESC\n    LIMIT 4\n");
$relatedStmt->execute([':tienda' => $product['idtiendas'], ':id' => $id]);
$related = $relatedStmt->fetchAll();

render_head($product['pro_nombre']);
render_navbar('producto');
?>
<section class="page-section py-5">
  <div class="container">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="breadcrumb custom-breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
        <li class="breadcrumb-item"><a href="index.php#productos">Productos</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($product['pro_nombre']) ?></li>
      </ol>
    </nav>

    <div class="row g-4 align-items-start">
      <div class="col-lg-5">
        <div class="detail-card p-3 p-lg-4 sticky-lg-top detail-sticky">
          <img src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>" alt="<?= e($product['pro_nombre']) ?>" class="detail-image w-100">
        </div>
      </div>
      <div class="col-lg-7">
        <div class="detail-card p-4 mb-4">
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge soft-badge"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
            <span class="mini-badge <?= e(stock_badge_class($product['pro_en_stock'])) ?>"><?= e(stock_label($product['pro_en_stock'])) ?></span>
          </div>
          <h1 class="display-6 fw-bold mb-3"><?= e($product['pro_nombre']) ?></h1>
          <p class="text-body-secondary mb-4"><?= e($product['pro_descripcion'] ?: 'Sin descripción disponible para este producto.') ?></p>

          <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
            <div>
              <div class="price-now detail-price"><?= gs($product['pro_precio']) ?></div>
              <?php if ($product['pro_precio_anterior'] !== null): ?>
                <div class="price-old"><?= gs($product['pro_precio_anterior']) ?></div>
              <?php endif; ?>
            </div>
            <div class="text-body-secondary small text-md-end">
              <div>Actualizado: <?= e(date('d/m/Y H:i', strtotime((string) $product['pro_fecha_scraping']))) ?></div>
              <div>Moneda: <?= e($product['pro_moneda']) ?></div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="info-box">
                <small class="text-body-secondary d-block mb-1">Tienda</small>
                <a href="tienda.php?id=<?= (int) $product['idtiendas'] ?>" class="fw-semibold text-decoration-none"><?= e($product['tie_nombre']) ?></a>
              </div>
            </div>
            <div class="col-md-6">
              <div class="info-box">
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

        <div class="detail-card p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h4 fw-bold mb-0">Historial reciente</h2>
            <small class="text-body-secondary">Datos desde <code>historial_precios</code>. fileciteturn9file12turn9file13</small>
          </div>
          <?php if ($history): ?>
            <div class="table-responsive">
              <table class="table table-dark-subtle align-middle mb-0 custom-table">
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

        <div class="detail-card p-4">
          <h2 class="h4 fw-bold mb-3">Más productos de esta tienda</h2>
          <div class="row g-3">
            <?php if ($related): ?>
              <?php foreach ($related as $item): ?>
                <div class="col-md-6">
                  <a href="producto.php?id=<?= (int) $item['idproductos'] ?>" class="related-card text-decoration-none text-reset d-flex gap-3 align-items-center">
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
