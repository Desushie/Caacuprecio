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
    'favoritos' => (int) $pdo->query('SELECT COUNT(*) FROM favoritos')->fetchColumn(),
];

$categorias = $pdo->query('SELECT idcategorias, cat_nombre FROM categorias ORDER BY cat_nombre ASC')->fetchAll();

$where = ['p.pro_activo = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(p.pro_nombre LIKE :q OR p.pro_descripcion LIKE :q OR p.pro_marca LIKE :q OR t.tie_nombre LIKE :q OR c.cat_nombre LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($categoriaId > 0) {
    $where[] = 'p.categorias_idcategorias = :categoria';
    $params[':categoria'] = $categoriaId;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "
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
        t.idtiendas,
        t.tie_nombre,
        c.cat_nombre,
        (
            SELECT COUNT(*)
            FROM historial_precios hp
            WHERE hp.productos_idproductos = p.idproductos
        ) AS total_historial,
        (
            SELECT COUNT(*)
            FROM productos_precios pp
            WHERE pp.productos_idproductos = p.idproductos
              AND pp.prop_estado = 'activo'
        ) AS total_ofertas
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE {$whereSql}
    ORDER BY " . sort_sql($sort) . "
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

$stores = $pdo->query("
    SELECT
        t.idtiendas,
        t.tie_nombre,
        t.tie_descripcion,
        t.tie_logo,
        t.tie_ubicacion,
        t.tie_url,
        COUNT(p.idproductos) AS total_productos,
        MIN(p.pro_precio) AS precio_minimo,
        MAX(p.pro_fecha_scraping) AS ultima_actualizacion
    FROM tiendas t
    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas AND p.pro_activo = 1
    GROUP BY t.idtiendas, t.tie_nombre, t.tie_descripcion, t.tie_logo, t.tie_ubicacion, t.tie_url
    ORDER BY total_productos DESC, t.tie_nombre ASC
    LIMIT 8
")->fetchAll();

$recentLogs = $pdo->query("
    SELECT s.scrape_estado, s.scrape_productos_actualizados, s.scrape_fin, t.tie_nombre
    FROM scrape_logs s
    INNER JOIN tiendas t ON t.idtiendas = s.tiendas_idtiendas
    ORDER BY COALESCE(s.scrape_fin, s.scrape_inicio) DESC
    LIMIT 4
")->fetchAll();

render_head('Inicio');
render_navbar('home');
?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="hero hero-home position-relative overflow-hidden">
  <div class="container position-relative z-1">
    <div class="row g-4 align-items-center">
      <div class="col-lg-7">
        <span class="eyebrow-pill mb-3 d-inline-flex align-items-center gap-2">
          <span class="eyebrow-dot"></span>
          Comparador de precios actualizado con MySQL
        </span>
        <h1 class="display-4 fw-bold mb-3 hero-title">Compará precios de varias tiendas en un solo lugar</h1>
        <p class="lead text-body-secondary mb-4 hero-copy">
          La portada ahora consulta el esquema actual de <strong>caacuprecio</strong>: productos, categorías, tiendas, historial, logs y favoritos,
          con una interfaz más moderna, visual y animada.
        </p>

        <div class="row g-3">
          <div class="col-sm-6 col-xl-3">
            <div class="stats-card p-3 h-100 floating-card">
              <div class="stat-label">Tiendas</div>
              <div class="stat-value"><?= number_format($stats['tiendas'], 0, ',', '.') ?></div>
            </div>
          </div>
          <div class="col-sm-6 col-xl-3">
            <div class="stats-card p-3 h-100 floating-card delay-1">
              <div class="stat-label">Categorías</div>
              <div class="stat-value"><?= number_format($stats['categorias'], 0, ',', '.') ?></div>
            </div>
          </div>
          <div class="col-sm-6 col-xl-3">
            <div class="stats-card p-3 h-100 floating-card delay-2">
              <div class="stat-label">Productos</div>
              <div class="stat-value"><?= number_format($stats['productos'], 0, ',', '.') ?></div>
            </div>
          </div>
          <div class="col-sm-6 col-xl-3">
            <div class="stats-card p-3 h-100 floating-card delay-3">
              <div class="stat-label">Favoritos</div>
              <div class="stat-value"><?= number_format($stats['favoritos'], 0, ',', '.') ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="hero-panel glass-card p-4 p-lg-5 h-100">
          <div class="d-flex justify-content-between align-items-start mb-4 gap-3">
            <div>
              <div class="panel-kicker">Vista general</div>
              <h2 class="h3 fw-bold mb-1">Panel del catálogo</h2>
              <p class="text-body-secondary mb-0">Inspirado en landing pages modernas con brillo, profundidad y movimiento suave.</p>
            </div>
            <span class="pulse-badge">Live</span>
          </div>

          <div class="hero-metric mb-3">
            <span>Productos activos</span>
            <strong><?= number_format($stats['productos'], 0, ',', '.') ?></strong>
          </div>
          <div class="hero-metric mb-3">
            <span>Tiendas indexadas</span>
            <strong><?= number_format($stats['tiendas'], 0, ',', '.') ?></strong>
          </div>
          <div class="hero-metric">
            <span>Categorías disponibles</span>
            <strong><?= number_format($stats['categorias'], 0, ',', '.') ?></strong>
          </div>

          <?php if ($recentLogs): ?>
            <div class="mini-log-list mt-4">
              <?php foreach ($recentLogs as $log): ?>
                <div class="mini-log-item">
                  <div>
                    <div class="fw-semibold"><?= e($log['tie_nombre']) ?></div>
                    <small class="text-body-secondary"><?= e($log['scrape_estado']) ?></small>
                  </div>
                  <div class="text-end">
                    <div class="fw-semibold"><?= (int) $log['scrape_productos_actualizados'] ?></div>
                    <small class="text-body-secondary"><?= !empty($log['scrape_fin']) ? e(date('d/m/Y H:i', strtotime((string) $log['scrape_fin']))) : 'En curso' ?></small>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="container sticky-search">
  <div class="search-bar glass-card p-3 p-lg-2">
    <form class="row g-2 align-items-center" method="get" action="index.php">
      <div class="col-lg-5">
        <input type="text" name="q" class="form-control" placeholder="Buscá por producto, marca, tienda o categoría" value="<?= e($q) ?>">
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
  <section id="categorias" class="py-5 position-relative">
    <div class="container">
      <div class="section-header mb-4">
        <div>
          <div class="section-kicker">Exploración</div>
          <h2 class="section-title mb-2">Categorías</h2>
          <p class="section-subtitle mb-0">Se cargan directamente desde la tabla <code>categorias</code> y enlazan con <code>productos.categorias_idcategorias</code>.</p>
        </div>
      </div>
      <div class="row g-3">
        <?php if ($categorias): ?>
          <?php foreach ($categorias as $categoria): ?>
            <div class="col-6 col-md-4 col-xl-2">
              <a class="category-pill fancy-hover" href="index.php?categoria=<?= (int) $categoria['idcategorias'] ?>#productos">
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

  <section id="productos" class="pb-5 position-relative">
    <div class="container">
      <div class="section-header mb-4 d-flex justify-content-between align-items-end gap-3 flex-wrap">
        <div>
          <div class="section-kicker">Catálogo</div>
          <h2 class="section-title mb-2">Productos</h2>
          <p class="section-subtitle mb-0">Listado principal conectado con <code>productos</code>, <code>tiendas</code>, <code>categorias</code>, <code>historial_precios</code> y <code>productos_precios</code>.</p>
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
              <article class="custom-card product-card h-100 p-3 fancy-hover">
                <a href="producto.php?id=<?= (int) $product['idproductos'] ?>" class="text-decoration-none text-reset d-block">
                  <div class="product-thumb-wrap mb-3">
                    <img class="offer-thumb" src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>" alt="<?= e($product['pro_nombre']) ?>">
                  </div>
                </a>
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                  <span class="badge soft-badge"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                  <?php if ($discount > 0): ?><span class="price-badge">-<?= $discount ?>%</span><?php endif; ?>
                </div>
                <h3 class="h6 fw-bold mb-1"><a href="producto.php?id=<?= (int) $product['idproductos'] ?>" class="text-decoration-none text-reset stretched-link-sibling"><?= e($product['pro_nombre']) ?></a></h3>
                <p class="text-body-secondary small mb-2 line-clamp-2"><?= e($product['pro_descripcion'] ?: 'Sin descripción disponible.') ?></p>
                <?php if (!empty($product['pro_marca'])): ?>
                  <div class="small text-body-secondary mb-3">Marca: <strong><?= e($product['pro_marca']) ?></strong></div>
                <?php endif; ?>
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
                <div class="product-meta d-flex justify-content-between align-items-center gap-2 flex-wrap border-top pt-3">
                  <a class="small text-body-secondary text-decoration-none position-relative z-2" href="tienda.php?id=<?= (int) $product['idtiendas'] ?>"><i class="bi bi-shop me-1"></i><?= e($product['tie_nombre']) ?></a>
                  <div class="small text-body-secondary position-relative z-2">
                    <?= (int) $product['total_historial'] ?> historial · <?= (int) $product['total_ofertas'] ?> ofertas
                  </div>
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

  <section id="tiendas" class="pb-5 position-relative">
    <div class="container">
      <div class="section-header mb-4">
        <div>
          <div class="section-kicker">Directorio</div>
          <h2 class="section-title mb-2">Tiendas</h2>
          <p class="section-subtitle mb-0">Cada tienda está conectada con su catálogo por <code>productos.tiendas_idtiendas</code>.</p>
        </div>
      </div>
      <div class="row g-4">
        <?php if ($stores): ?>
          <?php foreach ($stores as $store): ?>
            <div class="col-sm-6 col-lg-3">
              <div class="store-card p-4 h-100 fancy-hover">
                <div class="store-logo d-flex align-items-center justify-content-center mb-3 store-logo-box">
                  <?php if (!empty($store['tie_logo'])): ?>
                    <img src="<?= e($store['tie_logo']) ?>" alt="<?= e($store['tie_nombre']) ?>" class="img-fluid rounded-3 store-logo-img">
                  <?php else: ?>
                    <span class="fw-bold fs-4"><?= e(mb_strtoupper(mb_substr($store['tie_nombre'], 0, 2))) ?></span>
                  <?php endif; ?>
                </div>
                <h3 class="h6 fw-bold mb-1"><?= e($store['tie_nombre']) ?></h3>
                <p class="text-body-secondary small mb-3 line-clamp-2"><?= e($store['tie_ubicacion'] ?: ($store['tie_descripcion'] ?: 'Sin descripción')) ?></p>
                <div class="d-flex justify-content-between small text-body-secondary mb-3">
                  <span><?= number_format((int) $store['total_productos'], 0, ',', '.') ?> productos</span>
                  <span><?= $store['precio_minimo'] !== null ? gs($store['precio_minimo']) : 'Sin precio' ?></span>
                </div>
                <div class="small text-body-secondary mb-3">
                  <?= $store['ultima_actualizacion'] ? 'Actualizado ' . e(date('d/m/Y H:i', strtotime((string) $store['ultima_actualizacion']))) : 'Sin actualizaciones' ?>
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
