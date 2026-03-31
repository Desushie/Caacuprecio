<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

$stats = [
    'productos' => (int) $pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn(),
    'tiendas' => (int) $pdo->query('SELECT COUNT(*) FROM tiendas')->fetchColumn(),
    'categorias' => (int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn(),
    'favoritos' => (int) $pdo->query('SELECT COUNT(*) FROM favoritos')->fetchColumn(),
];

$latestProducts = $pdo->query("
    SELECT p.idproductos, p.pro_nombre, p.pro_precio, p.pro_imagen, p.pro_fecha_scraping, t.tie_nombre
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    ORDER BY p.pro_fecha_scraping DESC, p.idproductos DESC
    LIMIT 6
")->fetchAll();

$latestStores = $pdo->query("
    SELECT t.idtiendas, t.tie_nombre, t.tie_logo, t.tie_ubicacion, COUNT(p.idproductos) AS total_productos
    FROM tiendas t
    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas
    GROUP BY t.idtiendas, t.tie_nombre, t.tie_logo, t.tie_ubicacion
    ORDER BY t.idtiendas DESC
    LIMIT 6
")->fetchAll();

render_head('Panel administrador');
?>
<link rel="stylesheet" href="./css/admin.css">
<?php render_navbar('admin'); ?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="admin-shell">
  <div class="container">
    <div class="admin-hero p-4 p-lg-5 mb-4">
      <div class="row g-4 align-items-center">
        <div class="col-lg-8 position-relative z-1">
          <div class="admin-kicker mb-2">Panel</div>
          <h1 class="display-6 fw-bold mb-3">Gestión del Sistema</h1>
          <p class="text-body-secondary mb-4">
            Administrá productos, tiendas y contenido de forma rápida y organizada desde un solo lugar.
          </p>
          <div class="d-flex flex-wrap gap-3">
            <a href="admin_productos.php" class="btn btn-primary rounded-pill px-4">
              <i class="bi bi-box-seam me-2"></i>Gestionar productos
            </a>
            <a href="admin_tiendas.php" class="btn btn-outline-primary rounded-pill px-4">
              <i class="bi bi-shop me-2"></i>Gestionar tiendas
            </a>
            <a href="admin_scraper.php" class="btn btn-outline-primary rounded-pill px-4">
              <i class="bi bi-terminal me-2"></i>Importar datos
            </a>
            <a href="analytics.php" class="btn btn-outline-primary rounded-pill px-4">
              <i class="bi bi-bar-chart-line me-2"></i>Analíticas
            </a>
          </div>
        </div>
        <div class="col-lg-4 position-relative z-1">
          <div class="admin-side-list">
            <div class="admin-side-item">
              <strong>Gestión rápida</strong>
              <span class="text-body-secondary small">Editá productos, precios y datos en segundos.</span>
            </div>
            <div class="admin-side-item">
              <strong>Interfaz optimizada</strong>
              <span class="text-body-secondary small">Buscá, filtrá y administrá sin complicaciones.</span>
            </div>
            <div class="admin-side-item">
              <strong>Integrado al sitio</strong>
              <span class="text-body-secondary small">Todo el contenido del sitio en un solo panel.</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Productos</div>
          <div class="admin-stat-value"><?= number_format($stats['productos'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Tiendas</div>
          <div class="admin-stat-value"><?= number_format($stats['tiendas'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Categorías</div>
          <div class="admin-stat-value"><?= number_format($stats['categorias'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Favoritos</div>
          <div class="admin-stat-value"><?= number_format($stats['favoritos'], 0, ',', '.') ?></div>
        </div>
      </div>
    </div>

    <div class="row g-4 align-items-stretch">
      <div class="col-lg-8">
        <div class="admin-panel p-4 h-100">
          <div class="admin-toolbar mb-3">
            <div>
              <div class="admin-kicker">Actividad</div>
              <h2 class="h4 fw-bold mb-0">Productos actualizados recientemente</h2>
            </div>
            <a href="admin_productos.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Ver todos</a>
          </div>

          <?php if ($latestProducts): ?>
            <div class="admin-dashboard-list">
              <?php foreach ($latestProducts as $item): ?>
                <div class="admin-dashboard-row">
                  <div class="admin-dashboard-media">
                    <img src="<?= e(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>" alt="<?= e($item['pro_nombre']) ?>" class="admin-dashboard-thumb">
                  </div>
                  <div class="admin-dashboard-main">
                    <div class="admin-dashboard-title"><?= e($item['pro_nombre']) ?></div>
                    <div class="admin-dashboard-meta">
                      <span><i class="bi bi-shop me-1"></i><?= e($item['tie_nombre']) ?></span>
                      <span><i class="bi bi-clock me-1"></i><?= e(date('d/m/Y H:i', strtotime((string) $item['pro_fecha_scraping']))) ?></span>
                    </div>
                  </div>
                  <div class="admin-dashboard-side">
                    <div class="admin-dashboard-price"><?= gs($item['pro_precio']) ?></div>
                    <a href="admin_productos.php?edit=<?= (int) $item['idproductos'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 mt-2">Editar</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="admin-empty">Aún no hay productos recientes.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="admin-panel p-4 h-100">
          <div class="admin-toolbar mb-3">
            <div>
              <div class="admin-kicker">Tiendas</div>
              <h2 class="h4 fw-bold mb-0">Tiendas agregadas recientemente</h2>
            </div>
            <a href="admin_tiendas.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Ver todas</a>
          </div>

          <?php if ($latestStores): ?>
            <div class="admin-store-list">
              <?php foreach ($latestStores as $store): ?>
                <div class="admin-store-row">
                  <div class="admin-store-media">
                    <img src="<?= e($store['tie_logo'] ?: image_url('', $store['tie_nombre'])) ?>" alt="<?= e($store['tie_nombre']) ?>" class="admin-store-thumb">
                  </div>
                  <div class="admin-store-main">
                    <div class="admin-dashboard-title"><?= e($store['tie_nombre']) ?></div>
                    <div class="admin-dashboard-meta">
                      <span><?= e($store['tie_ubicacion'] ?: 'Ubicación no disponible') ?></span>
                    </div>
                    <div class="small text-body-secondary mt-1"><?= number_format((int) $store['total_productos'], 0, ',', '.') ?> productos</div>
                  </div>
                  <div class="admin-store-action">
                    <a href="admin_tiendas.php?edit=<?= (int) $store['idtiendas'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">Editar</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="admin-empty">Aún no hay tiendas registradas.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<?php render_footer(); ?>
