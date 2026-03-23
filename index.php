<?php
require_once __DIR__ . '/config.php';

$q = trim($_GET['q'] ?? '');
$categoriaId = (int) ($_GET['categoria'] ?? 0);
$sort = $_GET['orden'] ?? 'recientes';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$pdo = db();

$stats = [
    'tiendas' => (int) $pdo->query('SELECT COUNT(*) FROM tiendas')->fetchColumn(),
    'categorias' => (int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn(),
    'productos' => (int) $pdo->query('SELECT COUNT(*) FROM productos WHERE pro_activo = 1')->fetchColumn(),
];

$categorias = $pdo->query('SELECT idcategorias, cat_nombre FROM categorias ORDER BY cat_nombre ASC')->fetchAll();

$where = ['p.pro_activo = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(p.pro_nombre LIKE :q OR p.pro_descripcion LIKE :q OR t.tie_nombre LIKE :q OR c.cat_nombre LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($categoriaId > 0) {
    $where[] = 'p.categorias_idcategorias = :categoria';
    $params[':categoria'] = $categoriaId;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM productos p\n    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas\n    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias\n    WHERE {$whereSql}\n");
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "\n    SELECT\n        p.idproductos,\n        p.pro_nombre,\n        p.pro_descripcion,\n        p.pro_precio,\n        p.pro_precio_anterior,\n        p.pro_imagen,\n        p.pro_url,\n        p.pro_en_stock,\n        p.pro_fecha_scraping,\n        t.idtiendas,\n        t.tie_nombre,\n        c.cat_nombre\n    FROM productos p\n    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas\n    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias\n    WHERE {$whereSql}\n    ORDER BY " . sort_sql($sort) . "\n    LIMIT :limit OFFSET :offset\n";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

$stores = $pdo->query("\n    SELECT\n        t.idtiendas,\n        t.tie_nombre,\n        t.tie_descripcion,\n        t.tie_logo,\n        t.tie_ubicacion,\n        t.tie_url,\n        COUNT(p.idproductos) AS total_productos,\n        MIN(p.pro_precio) AS precio_minimo\n    FROM tiendas t\n    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas AND p.pro_activo = 1\n    GROUP BY t.idtiendas, t.tie_nombre, t.tie_descripcion, t.tie_logo, t.tie_ubicacion, t.tie_url\n    ORDER BY total_productos DESC, t.tie_nombre ASC\n    LIMIT 8\n")->fetchAll();

render_head('Inicio');
render_navbar('home');
?>
<section class="hero">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-7">
        <span class="badge rounded-pill custom-badge px-3 py-2 mb-3">
          <i class="bi bi-stars me-1"></i>Home lista para PHP + MySQL
        </span>
        <h1 class="display-5 fw-bold mb-3">Encontrá el mejor precio antes de comprar</h1>
        <p class="lead text-body-secondary mb-4">Esta portada consulta categorías, tiendas y productos desde tu base <strong>Caacuprecio</strong>, usando tu esquema actual de MySQL con tablas relacionadas para catálogo e historial. fileciteturn9file9turn9file13</p>

        <div class="row g-3">
          <div class="col-sm-4">
            <div class="stats-card p-3 h-100">
              <div class="d-flex align-items-center gap-3">
                <div class="icon-wrap"><i class="bi bi-shop"></i></div>
                <div>
                  <div class="fw-bold fs-5"><?= number_format($stats['tiendas'], 0, ',', '.') ?></div>
                  <div class="text-body-secondary small">Tiendas</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="stats-card p-3 h-100">
              <div class="d-flex align-items-center gap-3">
                <div class="icon-wrap"><i class="bi bi-grid"></i></div>
                <div>
                  <div class="fw-bold fs-5"><?= number_format($stats['categorias'], 0, ',', '.') ?></div>
                  <div class="text-body-secondary small">Categorías</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-sm-4">
            <div class="stats-card p-3 h-100">
              <div class="d-flex align-items-center gap-3">
                <div class="icon-wrap"><i class="bi bi-tags"></i></div>
                <div>
                  <div class="fw-bold fs-5"><?= number_format($stats['productos'], 0, ',', '.') ?></div>
                  <div class="text-body-secondary small">Productos activos</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="hero-visual p-4 p-lg-5 h-100">
          <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
              <small class="text-white-50">Inspiración visual</small>
              <h3 class="h4 fw-bold mb-0">Bootstrap + SQL</h3>
            </div>
            <span class="badge bg-warning text-dark rounded-pill px-3 py-2">Dark default</span>
          </div>
          <div class="mini-stat mb-3">
            <div class="d-flex justify-content-between mb-2"><span class="text-white-50">Consultas</span><strong>PDO</strong></div>
            <div class="progress" style="height:8px"><div class="progress-bar" style="width:88%"></div></div>
          </div>
          <div class="mini-stat mb-3">
            <div class="d-flex justify-content-between mb-2"><span class="text-white-50">Frontend</span><strong>Bootstrap 5</strong></div>
            <div class="progress bg-light-subtle" style="height:8px"><div class="progress-bar bg-warning" style="width:74%"></div></div>
          </div>
          <div class="mini-stat">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="fw-semibold">Tema persistente</div>
                <small class="text-white-50">Guardado en localStorage</small>
              </div>
              <i class="bi bi-moon-stars fs-3"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="container sticky-search">
  <div class="search-bar p-3 p-lg-2">
    <form class="row g-2 align-items-center" method="get" action="index.php">
      <div class="col-lg-5">
        <input type="text" name="q" class="form-control" placeholder="Buscá por producto, tienda o categoría" value="<?= e($q) ?>">
      </div>
      <div class="col-sm-6 col-lg-3">
        <select name="categoria" class="form-select">
          <option value="0">Todas las categorías</option>
          <?php foreach ($categorias as $categoria): ?>
            <option value="<?= (int) $categoria['idcategorias'] ?>" <?= $categoriaId === (int) $categoria['idcategorias'] ? 'selected' : '' ?>>
              <?= e($categoria['cat_nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-lg-3">
        <select name="orden" class="form-select">
          <?php foreach (active_sort_options() as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-1 d-grid">
        <button class="btn btn-primary btn-lg rounded-4" type="submit"><i class="bi bi-search"></i></button>
      </div>
    </form>
  </div>
</div>

<main>
  <section id="categorias" class="py-5">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end gap-3 mb-4 flex-wrap">
        <div>
          <h2 class="section-title mb-2">Categorías</h2>
          <p class="section-subtitle mb-0">Estas categorías salen de la tabla <code>categorias</code> definida en tu SQL. fileciteturn9file9</p>
        </div>
      </div>
      <div class="row g-3">
        <?php if ($categorias): ?>
          <?php foreach ($categorias as $categoria): ?>
            <div class="col-6 col-md-4 col-xl-2">
              <a class="category-pill" href="index.php?categoria=<?= (int) $categoria['idcategorias'] ?>#productos">
                <span class="icon-wrap"><i class="bi bi-grid"></i></span>
                <span><?= e($categoria['cat_nombre']) ?></span>
              </a>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="col-12"><div class="empty-state">No hay categorías cargadas.</div></div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="productos" class="pb-5">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end gap-3 mb-4 flex-wrap">
        <div>
          <h2 class="section-title mb-2">Productos</h2>
          <p class="section-subtitle mb-0">Cada fila viene de la tabla <code>productos</code> y está asociada a una tienda y una categoría por claves foráneas. fileciteturn9file0turn9file9</p>
        </div>
        <div class="small text-body-secondary"><?= number_format($totalProducts, 0, ',', '.') ?> resultado(s)</div>
      </div>

      <div class="row g-4">
        <?php if ($products): ?>
          <?php foreach ($products as $product): ?>
            <?php
            $currentPrice = (float) $product['pro_precio'];
            $oldPrice = $product['pro_precio_anterior'] !== null ? (float) $product['pro_precio_anterior'] : null;
            $discount = ($oldPrice && $oldPrice > $currentPrice) ? (int) round((($oldPrice - $currentPrice) / $oldPrice) * 100) : 0;
            ?>
            <div class="col-md-6 col-xl-3">
              <article class="custom-card h-100 p-3">
                <a href="producto.php?id=<?= (int) $product['idproductos'] ?>" class="text-decoration-none text-reset">
                  <img class="offer-thumb mb-3" src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>" alt="<?= e($product['pro_nombre']) ?>">
                </a>
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                  <span class="badge soft-badge"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                  <?php if ($discount > 0): ?><span class="price-badge">-<?= $discount ?>%</span><?php endif; ?>
                </div>
                <h3 class="h6 fw-bold mb-1"><a href="producto.php?id=<?= (int) $product['idproductos'] ?>" class="text-decoration-none text-reset stretched-link-sibling"><?= e($product['pro_nombre']) ?></a></h3>
                <p class="text-body-secondary small mb-3 line-clamp-2"><?= e($product['pro_descripcion'] ?: 'Sin descripción disponible.') ?></p>
                <div class="d-flex align-items-end justify-content-between mb-3 gap-2">
                  <div>
                    <div class="price-now"><?= gs($currentPrice) ?></div>
                    <?php if ($oldPrice): ?><div class="price-old"><?= gs($oldPrice) ?></div><?php endif; ?>
                  </div>
                  <div class="text-end small text-body-secondary">
                    <div><span class="mini-badge <?= e(stock_badge_class($product['pro_en_stock'])) ?>"><?= e(stock_label($product['pro_en_stock'])) ?></span></div>
                    <div><?= e(date('d/m/Y', strtotime((string) $product['pro_fecha_scraping']))) ?></div>
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-center border-top pt-3 gap-2">
                  <a class="small text-body-secondary text-decoration-none position-relative z-2" href="tienda.php?id=<?= (int) $product['idtiendas'] ?>"><i class="bi bi-shop me-1"></i><?= e($product['tie_nombre']) ?></a>
                  <a href="producto.php?id=<?= (int) $product['idproductos'] ?>" class="btn btn-sm btn-primary rounded-pill px-3 position-relative z-2">Ver detalle</a>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="col-12"><div class="empty-state">No se encontraron productos con los filtros actuales.</div></div>
        <?php endif; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="mt-4" aria-label="Paginación">
          <ul class="pagination justify-content-center flex-wrap gap-2">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= e(http_build_query(['q' => $q, 'categoria' => $categoriaId, 'orden' => $sort, 'page' => $i])) ?>#productos"><?= $i ?></a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </section>

  <section id="tiendas" class="pb-5">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end gap-3 mb-4 flex-wrap">
        <div>
          <h2 class="section-title mb-2">Tiendas</h2>
          <p class="section-subtitle mb-0">El directorio se arma desde la tabla <code>tiendas</code>, enlazada con <code>productos</code>. fileciteturn9file9</p>
        </div>
      </div>
      <div class="row g-4">
        <?php if ($stores): ?>
          <?php foreach ($stores as $store): ?>
            <div class="col-sm-6 col-lg-3">
              <div class="store-card p-4 h-100">
                <div class="store-logo d-flex align-items-center justify-content-center mb-3 store-logo-box">
                  <?php if (!empty($store['tie_logo'])): ?>
                    <img src="<?= e($store['tie_logo']) ?>" alt="<?= e($store['tie_nombre']) ?>" class="img-fluid rounded-3 store-logo-img">
                  <?php else: ?>
                    <span class="fw-bold fs-4"><?= e(mb_strtoupper(mb_substr($store['tie_nombre'], 0, 2))) ?></span>
                  <?php endif; ?>
                </div>
                <h3 class="h6 fw-bold mb-1"><?= e($store['tie_nombre']) ?></h3>
                <p class="text-body-secondary small mb-3"><?= e($store['tie_ubicacion'] ?: ($store['tie_descripcion'] ?: 'Sin descripción')) ?></p>
                <div class="d-flex justify-content-between small text-body-secondary mb-3">
                  <span><?= number_format((int) $store['total_productos'], 0, ',', '.') ?> productos</span>
                  <span><?= gs($store['precio_minimo']) ?></span>
                </div>
                <a href="tienda.php?id=<?= (int) $store['idtiendas'] ?>" class="btn btn-outline-primary rounded-pill w-100">Ver tienda</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="col-12"><div class="empty-state">No hay tiendas cargadas.</div></div>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>
<?php render_footer(); ?>
