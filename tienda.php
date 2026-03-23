<?php
require_once __DIR__ . '/config.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('ID de tienda inválido.');
}

$q = trim($_GET['q'] ?? '');
$sort = $_GET['orden'] ?? 'recientes';

$pdo = db();

$storeStmt = $pdo->prepare("\n    SELECT\n        t.*,\n        COUNT(p.idproductos) AS total_productos,\n        MIN(p.pro_precio) AS precio_minimo,\n        MAX(p.pro_fecha_scraping) AS ultima_actualizacion\n    FROM tiendas t\n    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas AND p.pro_activo = 1\n    WHERE t.idtiendas = :id\n    GROUP BY t.idtiendas, t.tie_nombre, t.tie_descripcion, t.tie_logo, t.tie_ubicacion, t.tie_url\n    LIMIT 1\n");
$storeStmt->execute([':id' => $id]);
$store = $storeStmt->fetch();

if (!$store) {
    http_response_code(404);
    die('Tienda no encontrada.');
}

$where = ['p.tiendas_idtiendas = :id', 'p.pro_activo = 1'];
$params = [':id' => $id];
if ($q !== '') {
    $where[] = '(p.pro_nombre LIKE :q OR p.pro_descripcion LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = implode(' AND ', $where);

$productStmt = $pdo->prepare("\n    SELECT\n        p.idproductos, p.pro_nombre, p.pro_descripcion, p.pro_precio, p.pro_precio_anterior,\n        p.pro_imagen, p.pro_en_stock, p.pro_fecha_scraping, c.cat_nombre\n    FROM productos p\n    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias\n    WHERE {$whereSql}\n    ORDER BY " . sort_sql($sort) . "\n");
$productStmt->execute($params);
$products = $productStmt->fetchAll();

$categoryBreakdown = $pdo->prepare("\n    SELECT c.cat_nombre, COUNT(*) AS total\n    FROM productos p\n    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias\n    WHERE p.tiendas_idtiendas = :id AND p.pro_activo = 1\n    GROUP BY c.cat_nombre\n    ORDER BY total DESC, c.cat_nombre ASC\n    LIMIT 6\n");
$categoryBreakdown->execute([':id' => $id]);
$categories = $categoryBreakdown->fetchAll();

render_head($store['tie_nombre']);
render_navbar('tienda');
?>
<section class="page-section py-5">
  <div class="container">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="breadcrumb custom-breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">Inicio</a></li>
        <li class="breadcrumb-item"><a href="index.php#tiendas">Tiendas</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($store['tie_nombre']) ?></li>
      </ol>
    </nav>

    <div class="store-hero detail-card p-4 p-lg-5 mb-4">
      <div class="row g-4 align-items-center">
        <div class="col-lg-8">
          <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <div class="store-logo d-flex align-items-center justify-content-center store-detail-logo">
              <?php if (!empty($store['tie_logo'])): ?>
                <img src="<?= e($store['tie_logo']) ?>" alt="<?= e($store['tie_nombre']) ?>" class="img-fluid rounded-3 store-logo-img">
              <?php else: ?>
                <span class="fw-bold fs-2"><?= e(mb_strtoupper(mb_substr($store['tie_nombre'], 0, 2))) ?></span>
              <?php endif; ?>
            </div>
            <div>
              <span class="badge soft-badge mb-2">Tienda</span>
              <h1 class="display-6 fw-bold mb-1"><?= e($store['tie_nombre']) ?></h1>
              <p class="text-body-secondary mb-0"><?= e($store['tie_ubicacion'] ?: ($store['tie_descripcion'] ?: 'Sin información adicional.')) ?></p>
            </div>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <?php if (!empty($store['tie_url'])): ?>
              <a href="<?= e($store['tie_url']) ?>" target="_blank" rel="noopener" class="btn btn-primary rounded-pill px-4">Visitar sitio</a>
            <?php endif; ?>
            <a href="index.php#tiendas" class="btn btn-outline-primary rounded-pill px-4">Volver al directorio</a>
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
                <div class="small text-body-secondary mb-1">Desde</div>
                <div class="fw-bold fs-4"><?= gs($store['precio_minimo']) ?></div>
              </div>
            </div>
            <div class="col-12">
              <div class="stats-card p-3 h-100">
                <div class="small text-body-secondary mb-1">Última actualización</div>
                <div class="fw-bold"><?= $store['ultima_actualizacion'] ? e(date('d/m/Y H:i', strtotime((string) $store['ultima_actualizacion']))) : 'Sin datos' ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-3">
        <div class="detail-card p-4 mb-4">
          <h2 class="h5 fw-bold mb-3">Buscar en la tienda</h2>
          <form method="get" action="tienda.php" class="d-grid gap-3">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Nombre del producto">
            <select class="form-select" name="orden">
              <?php foreach (active_sort_options() as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary rounded-pill">Aplicar</button>
          </form>
        </div>

        <div class="detail-card p-4">
          <h2 class="h5 fw-bold mb-3">Categorías frecuentes</h2>
          <div class="d-grid gap-2">
            <?php if ($categories): ?>
              <?php foreach ($categories as $category): ?>
                <div class="d-flex justify-content-between align-items-center mini-row">
                  <span><?= e($category['cat_nombre'] ?? 'Sin categoría') ?></span>
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
        <div class="detail-card p-4">
          <div class="d-flex justify-content-between align-items-end gap-3 mb-4 flex-wrap">
            <div>
              <h2 class="h4 fw-bold mb-1">Productos de <?= e($store['tie_nombre']) ?></h2>
              <p class="text-body-secondary mb-0">Listado desde la tabla <code>productos</code> filtrada por <code>tiendas_idtiendas</code>. fileciteturn9file9</p>
            </div>
            <div class="small text-body-secondary"><?= number_format(count($products), 0, ',', '.') ?> resultado(s)</div>
          </div>

          <div class="row g-3">
            <?php if ($products): ?>
              <?php foreach ($products as $product): ?>
                <div class="col-md-6">
                  <article class="related-card related-card-lg h-100">
                    <img src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>" alt="<?= e($product['pro_nombre']) ?>" class="related-thumb related-thumb-lg">
                    <div class="flex-grow-1">
                      <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <span class="badge soft-badge"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                        <span class="mini-badge <?= e(stock_badge_class($product['pro_en_stock'])) ?>"><?= e(stock_label($product['pro_en_stock'])) ?></span>
                      </div>
                      <h3 class="h6 fw-bold mb-2 line-clamp-2"><?= e($product['pro_nombre']) ?></h3>
                      <p class="text-body-secondary small mb-3 line-clamp-2"><?= e($product['pro_descripcion'] ?: 'Sin descripción.') ?></p>
                      <div class="d-flex justify-content-between align-items-end gap-2 flex-wrap">
                        <div>
                          <div class="price-now"><?= gs($product['pro_precio']) ?></div>
                          <?php if ($product['pro_precio_anterior'] !== null): ?><div class="price-old"><?= gs($product['pro_precio_anterior']) ?></div><?php endif; ?>
                        </div>
                        <a href="producto.php?id=<?= (int) $product['idproductos'] ?>" class="btn btn-sm btn-primary rounded-pill px-3">Ver detalle</a>
                      </div>
                    </div>
                  </article>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="col-12"><div class="empty-state">No se encontraron productos para esta tienda con esos filtros.</div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php render_footer(); ?>
