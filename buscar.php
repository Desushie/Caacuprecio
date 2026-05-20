<?php
require_once __DIR__ . '/config.php';

$q = trim($_GET['q'] ?? '');
$categoriaId = (int) ($_GET['categoria'] ?? 0);
$tiendaId = (int) ($_GET['tienda'] ?? 0);
$marca = trim($_GET['marca'] ?? '');
$precioMin = trim($_GET['precio_min'] ?? '');
$precioMax = trim($_GET['precio_max'] ?? '');
$sort = $_GET['orden'] ?? 'recientes';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$pdo = db();

$userLogged = function_exists('is_logged_in') && is_logged_in();
$favoritesEnabled = function_exists('is_favorite_product') && function_exists('favorite_toggle_url');

$categorias = $pdo->query('SELECT idcategorias, cat_nombre FROM categorias ORDER BY cat_nombre ASC')->fetchAll();

$tiendas = $pdo->query("
    SELECT idtiendas, tie_nombre
    FROM tiendas
    ORDER BY tie_nombre ASC
")->fetchAll();

$popularSearches = [];
$recentSearches = [];
$currentUserId = function_exists('current_user_id') ? current_user_id() : 0;

if (!function_exists('cp_search_table_exists')) {
    function cp_search_table_exists(PDO $pdo, string $table): bool {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table");
            $stmt->execute([':table' => $table]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (cp_search_table_exists($pdo, 'busquedas_populares')) {
    $popularSearches = $pdo->query("SELECT termino, total FROM busquedas_populares ORDER BY total DESC LIMIT 5")->fetchAll();
} elseif (cp_search_table_exists($pdo, 'busquedas')) {
    $popularSearches = $pdo->query("SELECT bus_termino AS termino, COUNT(*) AS total FROM busquedas GROUP BY bus_termino ORDER BY total DESC LIMIT 5")->fetchAll();
}

if (function_exists('get_recent_searches')) {
    $recentSearches = get_recent_searches();
} else {
    $recentSearches = $_SESSION['recent_searches'] ?? [];
}

if (isset($_GET['clear_history']) && $_GET['clear_history'] === '1') {
    if (function_exists('clear_recent_searches')) {
        clear_recent_searches();
    } else {
        $_SESSION['recent_searches'] = [];
    }
    header('Location: buscar.php' . ($q !== '' ? '?q=' . urlencode($q) : ''));
    exit;
}

if ($q !== '' && function_exists('track_search_term')) {
    track_search_term($q);
}

// CONSTRUCCIÓN DE LA CONSULTA CON FILTROS
$where = ['p.pro_activo = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(p.pro_nombre LIKE :q OR p.pro_descripcion LIKE :q OR p.pro_marca LIKE :q OR p.pro_modelo LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($categoriaId > 0) {
    $where[] = 'p.categorias_idcategorias = :categoria';
    $params[':categoria'] = $categoriaId;
}
if ($tiendaId > 0) {
    $where[] = 'p.tiendas_idtiendas = :tienda';
    $params[':tienda'] = $tiendaId;
}
if ($marca !== '') {
    $where[] = 'p.pro_marca = :marca';
    $params[':marca'] = $marca;
}
if ($precioMin !== '') {
    $where[] = 'p.pro_precio >= :precio_min';
    $params[':precio_min'] = (float) $precioMin;
}
if ($precioMax !== '') {
    $where[] = 'p.pro_precio <= :precio_max';
    $params[':precio_max'] = (float) $precioMax;
}

$whereSql = implode(' AND ', $where);

// Marcas disponibles para el filtro lateral
$marcasStmt = $pdo->prepare("SELECT DISTINCT pro_marca FROM productos p WHERE $whereSql AND pro_marca IS NOT NULL AND pro_marca <> '' ORDER BY pro_marca ASC");
$marcasStmt->execute($params);
$marcasDisponibles = array_column($marcasStmt->fetchAll(), 'pro_marca');

$orderBy = 'p.pro_fecha_scraping DESC';
if ($sort === 'precio_asc') {
    $orderBy = 'p.pro_precio ASC';
} elseif ($sort === 'precio_desc') {
    $orderBy = 'p.pro_precio DESC';
} elseif ($sort === 'nombre_asc') {
    $orderBy = 'p.pro_nombre ASC';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM productos p WHERE $whereSql");
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);

$productsStmt = $pdo->prepare("
    SELECT p.*, t.tie_nombre, t.tie_logo, c.cat_nombre
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE $whereSql
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");
foreach ($params as $key => $val) {
    $productsStmt->bindValue($key, $val);
}
$productsStmt->execute();
$products = $productsStmt->fetchAll();

$buildUrl = function($targetPage) use ($q, $categoriaId, $tiendaId, $marca, $precioMin, $precioMax, $sort) {
    return 'buscar.php?' . http_build_query([
        'q' => $q,
        'categoria' => $categoriaId,
        'tienda' => $tiendaId,
        'marca' => $marca,
        'precio_min' => $precioMin,
        'precio_max' => $precioMax,
        'orden' => $sort,
        'page' => $targetPage
    ]);
};

render_head('Buscar Productos');
render_navbar('buscar');
?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<style>
  .search-sticky-container {
    position: -webkit-sticky;
    position: sticky;
    top: 0;
    z-index: 1020;
    background: var(--bg-main);
    padding-top: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-soft);
    transition: all 0.3s ease;
  }
</style>

<div class="search-sticky-container">
  <div class="container">
    <form class="js-smart-search-form position-relative w-100 row g-2 align-items-center" action="buscar.php" method="GET">
      
      <div class="col-lg-5">
        <div class="input-group">
          <span class="input-group-text bg-transparent border-end-0 text-body-secondary"><i class="bi bi-search"></i></span>
          <input type="search" name="q" class="form-control border-start-0" placeholder="¿Qué estás buscando hoy?" value="<?= e($q) ?>" autocomplete="off">
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <select name="categoria" class="form-select">
          <option value="0">Todas las categorías</option>
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int) $cat['idcategorias'] ?>" <?= $categoriaId === (int) $cat['idcategorias'] ? 'selected' : '' ?>><?= e($cat['cat_nombre']) ?></option>
          <?php endphp ?>
        </select>
      </div>

      <div class="col-sm-6 col-lg-3">
        <select name="orden" class="form-select" data-search-order data-search-sort>
          <?php foreach (active_sort_options() as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-lg-1 d-grid">
        <button class="btn btn-primary btn-lg rounded-4" type="submit" aria-label="Buscar">
          <i class="bi bi-search"></i>
        </button> 
      </div>

      <?php if ($recentSearches || $popularSearches): ?>
        <div class="col-12">
          <div class="search-discovery-stack d-flex flex-column gap-2 pt-2 pb-4">
            <?php if ($recentSearches): ?>
              <div class="search-chip-row">
                <span class="search-chip-label">
                  <i class="bi bi-clock-history me-1"></i>Historial
                </span>
                <?php foreach ($recentSearches as $term): ?>
                  <a class="search-chip" href="buscar.php?q=<?= rawurlencode($term) ?>" data-search-chip="history" data-search-term="<?= e($term) ?>"><?= e($term) ?></a>
                <?php endforeach; ?>
                <a class="search-chip search-chip-clear search-chip-clear-danger" href="buscar.php?clear_history=1" title="Limpiar historial">
                  <i class="bi bi-trash3 me-1"></i>Limpiar todo
                </a>
              </div>
            <?php endif; ?>

            <?php if ($popularSearches): ?>
              <div class="search-chip-row">
                <span class="search-chip-label"><i class="bi bi-fire me-1"></i>Más buscados</span>
                <?php foreach ($popularSearches as $item): ?>
                  <a class="search-chip search-chip-hot" href="buscar.php?q=<?= rawurlencode((string) $item['termino']) ?>" data-search-chip="popular" data-search-term="<?= e((string) $item['termino']) ?>">
                    <?= e((string) $item['termino']) ?>
                    <small class="search-chip-hot-count"><?= number_format((int) ($item['total'] ?? 0), 0, ',', '.') ?></small>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="position-absolute bottom-0" style="right: 10px !important; left: auto !important; bottom: 6px !important; z-index: 1030;">
        <button type="button" id="toggle-sticky-btn" class="btn btn-sm btn-primary rounded-circle shadow-sm d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;" title="Fijar / Desplazar barra">
          <i class="bi bi-pin-angle-fill" style="font-size: 0.85rem;"></i>
        </button>
      </div>

    </form>
  </div>
</div>

<section class="page-section py-5 position-relative">
  <div class="container position-relative z-1">
    <div class="row g-4">
      
      <div class="col-lg-3">
        <div class="filter-sidebar glass-card p-4 rounded-4 position-sticky" style="top: 100px;">
          <h5 class="fw-bold mb-4 text-white d-flex align-items-center">
            <i class="bi bi-sliders2 me-2 text-primary"></i> Filtros avanzados
          </h5>
          
          <form class="js-sidebar-filter-form d-flex flex-column gap-4" action="buscar.php" method="GET">
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <input type="hidden" name="categoria" value="<?= $categoriaId ?>">
            <input type="hidden" name="orden" value="<?= e($sort) ?>">

            <div>
              <label class="form-label text-body-secondary small fw-semibold text-uppercase mb-2">Tienda</label>
              <select name="tienda" class="form-select rounded-3">
                <option value="0">Todas las tiendas</option>
                <?php foreach ($tiendas as $tie): ?>
                  <option value="<?= (int) $tie['idtiendas'] ?>" <?= $tiendaId === (int) $tie['idtiendas'] ? 'selected' : '' ?>><?= e($tie['tie_nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label text-body-secondary small fw-semibold text-uppercase mb-2">Rango de Precios</label>
              <div class="d-flex gap-2 align-items-center">
                <input type="number" name="precio_min" class="form-control rounded-3" placeholder="Mín" value="<?= e($precioMin) ?>" min="0">
                <span class="text-body-secondary small">-</span>
                <input type="number" name="precio_max" class="form-control rounded-3" placeholder="Máx" value="<?= e($precioMax) ?>" min="0">
              </div>
            </div>

            <?php if (!empty($marcasDisponibles)): ?>
              <div>
                <label class="form-label text-body-secondary small fw-semibold text-uppercase mb-2">Marca</label>
                <select name="marca" class="form-select rounded-3">
                  <option value="">Todas las marcas</option>
                  <?php foreach ($marcasDisponibles as $m): ?>
                    <option value="<?= e($m) ?>" <?= $marca === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <div class="d-grid gap-2 pt-2">
              <button type="submit" class="btn btn-primary rounded-pill fw-medium">Aplicar filtros</button>
              <?php if ($tiendaId > 0 || $marca !== '' || $precioMin !== '' || $precioMax !== ''): ?>
                <a href="buscar.php?q=<?= urlencode($q) ?>&categoria=<?= $categoriaId ?>&orden=<?= urlencode($sort) ?>" class="btn btn-outline-secondary rounded-pill fw-medium btn-sm">Limpiar filtros</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
          <div>
            <h2 class="h4 fw-bold mb-1 text-white">
              <?php if ($q !== ''): ?>
                Resultados para "<?= e($q) ?>"
              <?php else: ?>
                Explorando productos
              <?php endif; ?>
            </h2>
            <p class="text-body-secondary small mb-0">Se encontraron <?= number_format($totalProducts, 0, ',', '.') ?> productos</p>
          </div>
        </div>

        <?php if ($products): ?>
          <div class="row g-3">
            <?php foreach ($products as $item): ?>
              <div class="col-sm-6 col-md-4">
                <div class="product-card glass-card h-100 rounded-4 overflow-hidden position-relative fancy-hover">
                  
                  <?php if ($favoritesEnabled): ?>
                    <?php $isFav = $userLogged ? is_favorite_product((int)$item['idproductos']) : false; ?>
                    <a href="<?= $userLogged ? e(favorite_toggle_url((int)$item['idproductos'], $_SERVER['REQUEST_URI'])) : 'login.php' ?>" 
                       class="favorite-badge btn btn-sm <?= $userLogged && $isFav ? 'btn-danger' : 'btn-dark bg-opacity-50' ?> rounded-circle position-absolute top-0 end-0 m-3 z-1"
                       title="<?= $userLogged && $isFav ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>">
                      <i class="bi <?= $userLogged && $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                    </a>
                  <?php endif; ?>

                  <div class="product-card-img-wrap position-relative p-3 text-center bg-white bg-opacity-5">
                    <img src="<?= e(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>" alt="<?= e($item['pro_nombre']) ?>" class="img-fluid product-card-thumb">
                    <span class="store-tag badge bg-dark bg-opacity-75 text-white position-absolute bottom-0 start-0 m-3 px-2 py-1 small rounded-pill">
                      <?= e($item['tie_nombre']) ?>
                    </span>
                  </div>

                  <div class="product-card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                      <span class="text-primary small fw-semibold text-uppercase d-block mb-1"><?= e($item['cat_nombre'] ?? 'General') ?></span>
                      <h3 class="h6 text-white fw-bold product-card-title mb-2">
                        <a href="producto.php?<?= !empty($item['pro_grupo']) ? 'grupo=' . urlencode(trim($item['pro_grupo'])) : 'id=' . (int)$item['idproductos'] ?>&q=<?= urlencode($q) ?>" class="text-reset text-decoration-none card-stretched-link">
                          <?= e($item['pro_nombre']) ?>
                        </a>
                      </h3>
                      <?php if (!empty($item['pro_marca'])): ?>
                        <span class="badge badge-neutral mb-3"><?= e($item['pro_marca']) ?></span>
                      <?php endif; ?>
                    </div>
                    
                    <div class="pt-3 border-top border-secondary border-opacity-10 d-flex justify-content-between align-items-center">
                      <div>
                        <span class="price-caption d-block small text-body-secondary">Precio actual</span>
                        <span class="price-now fw-bold text-white fs-5"><?= gs($item['pro_precio']) ?></span>
                      </div>
                      <span class="mini-badge <?= e(stock_badge_class($item['pro_en_stock'])) ?>">
                        <?= e(stock_label($item['pro_en_stock'])) ?>
                      </span>
                    </div>
                  </div>

                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($totalPages > 1): ?>
            <nav aria-label="Navegación de resultados" class="mt-5">
              <ul class="pagination justify-content-center custom-pagination gap-1">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                  <a class="page-link rounded-pill" href="<?= $page > 1 ? e($buildUrl(1)) : '#' ?>" title="Ir al inicio">«</a>
                </li>
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                  <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                    <a class="page-link rounded-circle" href="<?= e($buildUrl($i)) ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                  <a class="page-link rounded-pill" href="<?= $page < $totalPages ? e($buildUrl($totalPages)) : '#' ?>" title="Ir al final">»</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>

        <?php else: ?>
          <div class="empty-state py-5 glass-card rounded-4 text-center">
            <i class="bi bi-search display-4 text-body-secondary mb-3 d-block"></i>
            <h4 class="text-white fw-bold">No se encontraron productos</h4>
            <p class="text-body-secondary mb-0">Probá modificando los criterios de búsqueda o limpiando los filtros avanzados.</p>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const topForm = document.querySelector('.js-smart-search-form');
    const sideForm = document.querySelector('.js-sidebar-filter-form');

    if (topForm && sideForm) {
        const syncInputs = (sourceForm, targetForm) => {
            return function(e) {
                const name = e.target.name;
                if (name) {
                    const targetInput = targetForm.querySelector(`[name="${name}"]`);
                    if (targetInput) {
                        targetInput.value = e.target.value;
                    }
                }
            };
        };

        topForm.addEventListener('input', syncInputs(topForm, sideForm));
        topForm.addEventListener('change', syncInputs(topForm, sideForm));
        sideForm.addEventListener('input', syncInputs(sideForm, topForm));
        sideForm.addEventListener('change', syncInputs(sideForm, topForm));
    }
});

document.addEventListener('DOMContentLoaded', function() {
  const stickyContainer = document.querySelector('.search-sticky-container');
  const toggleBtn = document.getElementById('toggle-sticky-btn');
  
  if (stickyContainer && toggleBtn) {
    const icon = toggleBtn.querySelector('i');
    
    toggleBtn.addEventListener('click', function() {
      const isDetached = stickyContainer.classList.toggle('position-relative');
      
      if (isDetached) {
        stickyContainer.style.position = 'relative';
        icon.className = 'bi bi-pin-angle';
        toggleBtn.classList.replace('btn-primary', 'btn-outline-secondary');
      } else {
        stickyContainer.style.position = 'sticky';
        icon.className = 'bi bi-pin-angle-fill';
        toggleBtn.classList.replace('btn-outline-secondary', 'btn-primary');
      }
    });
  }
});
</script>

<?php 
render_footer(); 
?>