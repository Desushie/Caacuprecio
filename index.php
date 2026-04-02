<?php
//Prueba de commit
require_once __DIR__ . '/config.php';

$q = trim($_GET['q'] ?? '');
$categoriaId = (int) ($_GET['categoria'] ?? 0);
$sort = $_GET['orden'] ?? 'recientes';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$pdo = db();

$userLogged = function_exists('is_logged_in') && is_logged_in();
$favoritesEnabled = function_exists('is_favorite_product') && function_exists('favorite_toggle_url');
$currentUserId = function_exists('current_user_id') ? current_user_id() : 0;
$currentSessionId = session_id();

$stats = [
    'tiendas' => (int) $pdo->query('SELECT COUNT(*) FROM tiendas')->fetchColumn(),
    'categorias' => (int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn(),
    'productos' => (int) $pdo->query('SELECT COUNT(*) FROM productos WHERE pro_activo = 1')->fetchColumn(),
    'favoritos' => (int) $pdo->query('SELECT COUNT(*) FROM favoritos')->fetchColumn(),
];

$categorias = $pdo->query('SELECT idcategorias, cat_nombre FROM categorias ORDER BY cat_nombre ASC')->fetchAll();

$popularSearches = [];
$recentSearches = [];

if (!function_exists('cp_table_exists')) {
    function cp_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        $key = mb_strtolower($table);

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = :table
            ");
            $stmt->execute([':table' => $table]);
            $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

if (!function_exists('cp_search_table_exists')) {
    function cp_search_table_exists(PDO $pdo): bool
    {
        return cp_table_exists($pdo, 'busquedas');
    }
}

if (!function_exists('cp_get_popular_searches')) {
    function cp_get_popular_searches(PDO $pdo, int $limit = 8): array
    {
        if (!cp_search_table_exists($pdo)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT bus_termino AS termino, bus_total AS total, bus_ultima_fecha AS ultima_busqueda
                FROM busquedas
                WHERE bus_termino IS NOT NULL
                  AND TRIM(bus_termino) <> ''
                ORDER BY bus_total DESC, bus_ultima_fecha DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cp_clear_session_search_history')) {
    function cp_clear_session_search_history(): void
    {
        unset($_SESSION['search_history']);
    }
}

if (!function_exists('cp_redirect_without_clear_history')) {
    function cp_redirect_without_clear_history(string $fallback): void
    {
        $params = $_GET;
        unset($params['clear_history']);
        $target = $fallback;
        if (!empty($params)) {
            $target .= '?' . http_build_query($params);
        }
        header('Location: ' . $target);
        exit;
    }
}

if (!function_exists('cp_get_recently_viewed_products')) {
    function cp_get_recently_viewed_products(PDO $pdo, int $userId = 0, string $sessionId = '', int $limit = 8): array
    {
        if (!cp_table_exists($pdo, 'productos_vistos')) {
            return [];
        }

        try {
            if ($userId > 0 && $sessionId !== '') {
                $sql = "
                    SELECT
                        p.idproductos,
                        p.pro_nombre,
                        p.pro_marca,
                        p.pro_precio,
                        p.pro_imagen,
                        p.pro_en_stock,
                        t.idtiendas,
                        t.tie_nombre,
                        MAX(pv.visto_en) AS ultima_vista,
                        COUNT(*) AS total_vistas
                    FROM productos_vistos pv
                    INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
                    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                    WHERE p.pro_activo = 1
                      AND (pv.usuario_idusuario = :uid OR pv.session_id = :sid)
                    GROUP BY
                        p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                        p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                    ORDER BY ultima_vista DESC
                    LIMIT :limit
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':sid', $sessionId);
            } elseif ($userId > 0) {
                $sql = "
                    SELECT
                        p.idproductos,
                        p.pro_nombre,
                        p.pro_marca,
                        p.pro_precio,
                        p.pro_imagen,
                        p.pro_en_stock,
                        t.idtiendas,
                        t.tie_nombre,
                        MAX(pv.visto_en) AS ultima_vista,
                        COUNT(*) AS total_vistas
                    FROM productos_vistos pv
                    INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
                    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                    WHERE p.pro_activo = 1
                      AND pv.usuario_idusuario = :uid
                    GROUP BY
                        p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                        p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                    ORDER BY ultima_vista DESC
                    LIMIT :limit
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            } elseif ($sessionId !== '') {
                $sql = "
                    SELECT
                        p.idproductos,
                        p.pro_nombre,
                        p.pro_marca,
                        p.pro_precio,
                        p.pro_imagen,
                        p.pro_en_stock,
                        t.idtiendas,
                        t.tie_nombre,
                        MAX(pv.visto_en) AS ultima_vista,
                        COUNT(*) AS total_vistas
                    FROM productos_vistos pv
                    INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
                    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                    WHERE p.pro_activo = 1
                      AND pv.session_id = :sid
                    GROUP BY
                        p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                        p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                    ORDER BY ultima_vista DESC
                    LIMIT :limit
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':sid', $sessionId);
            } else {
                return [];
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cp_get_trending_products')) {
    function cp_get_trending_products(PDO $pdo, int $days = 7, int $limit = 8): array
    {
        if (!cp_table_exists($pdo, 'productos_vistos')) {
            return [];
        }

        try {
            $days = max(1, (int) $days);
            $limit = max(1, (int) $limit);

            $sql = "
                SELECT
                    p.idproductos,
                    p.pro_nombre,
                    p.pro_marca,
                    p.pro_precio,
                    p.pro_imagen,
                    p.pro_en_stock,
                    t.idtiendas,
                    t.tie_nombre,
                    COUNT(*) AS total_vistas
                FROM productos_vistos pv
                INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
                INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                WHERE p.pro_activo = 1
                  AND pv.visto_en >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                GROUP BY
                    p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                    p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                ORDER BY total_vistas DESC, MAX(pv.visto_en) DESC
                LIMIT {$limit}
            ";

            return $pdo->query($sql)->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cp_get_most_searched_products')) {
    function cp_get_most_searched_products(PDO $pdo, int $days = 30, int $limit = 8): array
    {
        if (!cp_table_exists($pdo, 'busqueda_click_producto')) {
            return [];
        }

        try {
            $days = max(1, (int) $days);
            $limit = max(1, (int) $limit);

            $sql = "
                SELECT
                    p.idproductos,
                    p.pro_nombre,
                    p.pro_marca,
                    p.pro_precio,
                    p.pro_imagen,
                    p.pro_en_stock,
                    t.idtiendas,
                    t.tie_nombre,
                    COUNT(*) AS total_clicks
                FROM busqueda_click_producto bcp
                INNER JOIN productos p ON p.idproductos = bcp.productos_idproductos
                INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                WHERE p.pro_activo = 1
                  AND bcp.creado_en >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                GROUP BY
                    p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                    p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                ORDER BY total_clicks DESC, MAX(bcp.creado_en) DESC
                LIMIT {$limit}
            ";

            return $pdo->query($sql)->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (isset($_GET['clear_history']) && (string) $_GET['clear_history'] === '1') {
    cp_clear_session_search_history();
    cp_redirect_without_clear_history('index.php');
}

$recentSearches = array_values(array_slice(array_filter(array_map(
    static fn ($value) => trim((string) $value),
    $_SESSION['search_history'] ?? []
)), 0, 8));

$popularSearches = cp_get_popular_searches($pdo, 8);

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
        MAX(p.pro_fecha_scraping) AS ultima_actualizacion
    FROM tiendas t
    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas AND p.pro_activo = 1
    GROUP BY t.idtiendas, t.tie_nombre, t.tie_descripcion, t.tie_logo, t.tie_ubicacion, t.tie_url
    ORDER BY total_productos DESC, t.tie_nombre ASC
    LIMIT 8
")->fetchAll();

$recentlyViewed = cp_get_recently_viewed_products($pdo, $currentUserId, $currentSessionId, 8);
$trendingProducts = cp_get_trending_products($pdo, 7, 8);
$mostSearchedProducts = cp_get_most_searched_products($pdo, 30, 8);

if (!$mostSearchedProducts) {
    $mostSearchedProducts = $trendingProducts;
}

render_head('Inicio');
render_navbar('home');

$renderAnalyticsCards = static function (array $items, string $metricKey, string $emptyMessage) use ($favoritesEnabled, $userLogged, $q): void {
    if (!$items) {
        echo '<div class="col-12"><div class="empty-state">' . e($emptyMessage) . '</div></div>';
        return;
    }

    foreach ($items as $item) {
        $productId = (int) ($item['idproductos'] ?? 0);
        $isFavorite = ($favoritesEnabled && $userLogged && function_exists('is_favorite_product')) ? is_favorite_product($productId) : false;
        $metricValue = (int) ($item[$metricKey] ?? 0);

        echo '<div class="col-md-6 col-xl-3">';
        echo '  <article class="custom-card product-card h-100 p-3 fancy-hover">';
        echo '    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">';
        echo '      <span class="mini-badge badge-neutral">' . e($item['tie_nombre'] ?? 'Catálogo') . '</span>';
        if ($metricValue > 0) {
            echo '  <span class="price-badge">' . number_format($metricValue, 0, ',', '.') . '</span>';
        }
        echo '    </div>';
        echo '    <a href="producto.php?id=' . $productId . ($q !== '' ? '&q=' . rawurlencode($q) : '') . '" class="text-decoration-none text-reset d-block">';
        echo '      <div class="product-thumb-wrap mb-3">';
        echo '        <img class="offer-thumb" src="' . e(image_url($item['pro_imagen'] ?? null, $item['pro_nombre'] ?? 'Producto')) . '" alt="' . e($item['pro_nombre'] ?? 'Producto') . '">';
        echo '      </div>';
        echo '    </a>';
        echo '    <h3 class="h6 fw-bold mb-1"><a href="producto.php?id=' . $productId . ($q !== '' ? '&q=' . rawurlencode($q) : '') . '" class="text-decoration-none text-reset stretched-link-sibling">' . e($item['pro_nombre'] ?? 'Producto') . '</a></h3>';
        if (!empty($item['pro_marca'])) {
            echo '<div class="small text-body-secondary mb-2">Marca: <strong>' . e($item['pro_marca']) . '</strong></div>';
        } else {
            echo '<div class="small text-body-secondary mb-2">Sin marca informada</div>';
        }
        echo '    <div class="d-flex align-items-end justify-content-between mb-3 gap-2">';
        echo '      <div><div class="price-now">' . gs($item['pro_precio'] ?? null) . '</div></div>';
        echo '      <div class="text-end small text-body-secondary">';
        echo '        <div><span class="mini-badge ' . e(stock_badge_class($item['pro_en_stock'] ?? 0)) . '">' . e(stock_label($item['pro_en_stock'] ?? 0)) . '</span></div>';
        if (!empty($item['ultima_vista'])) {
            echo '    <div>' . e(date('d/m/Y H:i', strtotime((string) $item['ultima_vista']))) . '</div>';
        }
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="product-meta d-flex justify-content-between align-items-center gap-2 flex-wrap border-top pt-3">';
        echo '      <a class="small text-body-secondary text-decoration-none position-relative z-2" href="tienda.php?id=' . (int) ($item['idtiendas'] ?? 0) . '"><i class="bi bi-shop me-1"></i>' . e($item['tie_nombre'] ?? 'Tienda') . '</a>';
        if ($favoritesEnabled && $userLogged && $productId > 0) {
            echo '  <a href="' . e(favorite_toggle_url($productId, $_SERVER['REQUEST_URI'])) . '" class="btn btn-sm ' . ($isFavorite ? 'btn-danger' : 'btn-outline-danger') . ' rounded-pill position-relative z-2" title="' . ($isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos') . '"><i class="bi ' . ($isFavorite ? 'bi-heart-fill' : 'bi-heart') . '"></i></a>';
        }
        echo '    </div>';
        echo '  </article>';
        echo '</div>';
    }
};
?>

<div class="search-sticky-bar">
  <div class="container">
    <div
      class="search-bar glass-card p-3 p-lg-3"
      data-search-root
      data-search-endpoint="buscar_api.php"
      data-search-history-endpoint="guardar_busqueda.php"
      data-search-results-page="buscar.php"
      data-search-mode="pro"
      data-search-context="home"
      data-live-target="#home-live-results"
      data-count-target="#home-live-count"
      data-state-target="#home-live-state"
    >
      <form class="row g-2 align-items-center js-smart-search-form" method="get" action="buscar.php" autocomplete="off">
        <div class="col-lg-5 position-relative">
          <label for="global-search-home" class="visually-hidden">Buscar productos</label>
          <input
            id="global-search-home"
            type="text"
            name="q"
            class="form-control js-smart-search-input"
            placeholder="Buscá por producto, marca, tienda o categoría"
            value="<?= e($q) ?>"
            autocomplete="off"
            data-search-input
            data-live-delay="320"
            data-min-length="2"
            aria-autocomplete="list"
            aria-expanded="false"
            aria-controls="global-search-home-suggestions"
          >
          <div
            id="global-search-home-suggestions"
            class="search-suggest-dropdown search-suggestions-panel"
            data-search-dropdown
            data-search-suggest
            data-search-suggestions
            role="listbox"
            aria-label="Sugerencias de búsqueda"
          ></div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <select name="categoria" class="form-select js-smart-search-category" data-search-category>
            <option value="0">Todas las categorías</option>
            <?php foreach ($categorias as $categoria): ?>
              <option value="<?= (int) $categoria['idcategorias'] ?>" <?= $categoriaId === (int) $categoria['idcategorias'] ? 'selected' : '' ?>>
                <?= e($categoria['cat_nombre']) ?>
              </option>
            <?php endforeach; ?>
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
            <div class="search-discovery-stack d-flex flex-column gap-2 pt-2">
              <?php if ($recentSearches): ?>
                <div class="search-chip-row">
                  <span class="search-chip-label"><i class="bi bi-clock-history me-1"></i>Historial</span>
                  <?php foreach ($recentSearches as $term): ?>
                    <a
                      class="search-chip"
                      href="buscar.php?q=<?= rawurlencode($term) ?>"
                      data-search-chip="history"
                      data-search-term="<?= e($term) ?>"
                    ><?= e($term) ?></a>
                  <?php endforeach; ?>
                  <a class="search-chip search-chip-clear search-chip-clear-danger" href="index.php?clear_history=1" title="Limpiar historial">
                    <i class="bi bi-trash3 me-1"></i>Limpiar historial
                  </a>
                </div>
              <?php endif; ?>

              <?php if ($popularSearches): ?>
                <div class="search-chip-row">
                  <span class="search-chip-label"><i class="bi bi-fire me-1"></i>Más buscados</span>
                  <?php foreach ($popularSearches as $item): ?>
                    <a
                      class="search-chip search-chip-hot"
                      href="buscar.php?q=<?= rawurlencode((string) $item['termino']) ?>"
                      data-search-chip="popular"
                      data-search-term="<?= e((string) $item['termino']) ?>"
                    >
                      <?= e((string) $item['termino']) ?>
                      <small class="search-chip-hot-count"><?= number_format((int) ($item['total'] ?? 0), 0, ',', '.') ?></small>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<section id="home-live-section" class="pb-4 position-relative">
  <div class="container">
    <div class="glass-card p-4 live-search-panel">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
          <h2 class="h5 fw-bold mb-1">Resultados en tiempo real</h2>
          <p class="text-body-secondary mb-0">Escribí lo que buscás y encontrá productos al instante.</p>
        </div>
        <div class="small text-body-secondary" id="home-live-count">0 resultado(s) en vista rápida</div>
      </div>
      <div class="small text-body-secondary mb-3" id="home-live-state">Empezá a escribir para buscar.</div>
      <div class="row g-4" id="home-live-results"></div>
    </div>
  </div>
</section>

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
          Compará precios fácilmente en múltiples tiendas
        </span>
        <h1 class="display-4 fw-bold mb-3 hero-title">Encontrá el mejor precio en segundos</h1>
        <p class="lead text-body-secondary mb-4 hero-copy">
          Buscá productos, compará precios actualizados y elegí la mejor opción sin perder tiempo.
          Guardá tus favoritos y seguí las ofertas en un solo lugar.
        </p>

        <div class="d-flex flex-wrap gap-3 mb-4">
          <a href="#productos" class="btn btn-primary btn-lg rounded-pill px-4">
            <i class="bi bi-search me-2"></i>Explorar productos
          </a>
          <a href="favoritos.php" class="btn btn-outline-primary btn-lg rounded-pill px-4">
            <i class="bi bi-heart me-2"></i>Ver favoritos
          </a>
        </div>

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
              <div class="panel-kicker">Resumen del momento</div>
              <h2 class="h3 fw-bold mb-1">Movimiento reciente</h2>
              <p class="text-body-secondary mb-0">Las últimas actualizaciones del catálogo para que sepas qué tiendas tuvieron cambios recientes.</p>
            </div>
            <span class="pulse-badge">Activo</span>
          </div>

          <div class="hero-metric mb-3">
            <span>Productos disponibles</span>
            <strong><?= number_format($stats['productos'], 0, ',', '.') ?></strong>
          </div>
          <div class="hero-metric mb-3">
            <span>Tiendas en seguimiento</span>
            <strong><?= number_format($stats['tiendas'], 0, ',', '.') ?></strong>
          </div>
          <div class="hero-metric">
            <span>Guardados por usuarios</span>
            <strong><?= number_format($stats['favoritos'], 0, ',', '.') ?></strong>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<main>
  <section class="py-5 position-relative">
    <div class="container">
      <div class="section-header mb-4">
        <div>
          <div class="section-kicker">Para vos</div>
          <h2 class="section-title mb-2">Lo que estuviste viendo</h2>
          <p class="section-subtitle mb-0">Accedé rápido a los productos que viste recientemente y descubrí tendencias.</p>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-12">
          <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-end gap-3 flex-wrap mb-4">
              <div>
                <div class="section-kicker">Recientes</div>
                <h3 class="h4 fw-bold mb-1">Productos vistos recientemente</h3>
                <p class="text-body-secondary mb-0">Basado en tu actividad reciente.</p>
              </div>
            </div>
            <div class="row g-4">
              <?php $renderAnalyticsCards($recentlyViewed, 'total_vistas', 'No hay datos disponibles por el momento.'); ?>
            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-end gap-3 flex-wrap mb-4">
              <div>
                <div class="section-kicker">Trending</div>
                <h3 class="h4 fw-bold mb-1">Ranking por tendencia</h3>
                <p class="text-body-secondary mb-0">Los productos más populares del momento.</p>
              </div>
            </div>
            <div class="row g-4">
              <?php $renderAnalyticsCards($trendingProducts, 'total_vistas', 'No hay datos disponibles por el momento.'); ?>
            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="glass-card p-4">
            <div class="d-flex justify-content-between align-items-end gap-3 flex-wrap mb-4">
              <div>
                <div class="section-kicker">Ranking</div>
                <h3 class="h4 fw-bold mb-1">Productos más buscados</h3>
                <p class="text-body-secondary mb-0">Los productos más buscados por los usuarios.</p>
              </div>
            </div>
            <div class="row g-4">
              <?php $renderAnalyticsCards($mostSearchedProducts, 'total_clicks', 'No hay datos disponibles por el momento.'); ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="categorias" class="py-5 position-relative">
    <div class="container">
      <div class="section-header mb-4">
        <div>
          <div class="section-kicker">Exploración</div>
          <h2 class="section-title mb-2">Comprá por categoría</h2>
          <p class="section-subtitle mb-0">Explorá por categorías y encontrá lo que necesitás más rápido.</p>
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
          <div class="col-12"><div class="empty-state">No hay categorías disponibles por ahora.</div></div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="productos" class="pb-5 position-relative">
    <div class="container">
      <div class="section-header mb-4 d-flex justify-content-between align-items-end gap-3 flex-wrap">
        <div>
          <div class="section-kicker">Catálogo</div>
          <h2 class="section-title mb-2">Productos disponibles</h2>
          <p class="section-subtitle mb-0">Compará precios entre tiendas y elegí la mejor opción.</p>
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
            $isFavorite = ($favoritesEnabled && $userLogged) ? is_favorite_product((int) $product['idproductos']) : false;
            ?>
            <div class="col-md-6 col-xl-3">
              <article class="custom-card product-card h-100 p-3 fancy-hover">
                <div class="d-flex justify-content-end mb-2">
                  <?php if ($favoritesEnabled && $userLogged): ?>
                    <a
                      href="<?= e(favorite_toggle_url((int) $product['idproductos'], $_SERVER['REQUEST_URI'] . '#productos')) ?>"
                      class="btn btn-sm <?= $isFavorite ? 'btn-danger' : 'btn-outline-danger' ?> rounded-pill position-relative z-2"
                      title="<?= $isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
                    >
                      <i class="bi <?= $isFavorite ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                    </a>
                  <?php else: ?>
                    <a href="login.php" class="btn btn-sm btn-outline-danger rounded-pill position-relative z-2" title="Iniciá sesión para guardar favoritos">
                      <i class="bi bi-heart"></i>
                    </a>
                  <?php endif; ?>
                </div>

                <a href="producto.php?id=<?= (int) $product['idproductos'] ?>&q=<?= rawurlencode($q) ?>" class="text-decoration-none text-reset d-block">
                  <div class="product-thumb-wrap mb-3">
                    <img class="offer-thumb" src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>" alt="<?= e($product['pro_nombre']) ?>">
                  </div>
                </a>

                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                  <span class="badge soft-badge"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                  <?php if ($discount > 0): ?><span class="price-badge">-<?= $discount ?>%</span><?php endif; ?>
                </div>

                <h3 class="h6 fw-bold mb-1">
                  <a href="producto.php?id=<?= (int) $product['idproductos'] ?>&q=<?= rawurlencode($q) ?>" class="text-decoration-none text-reset stretched-link-sibling">
                    <?= e($product['pro_nombre']) ?>
                  </a>
                </h3>

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
                  <a class="small text-body-secondary text-decoration-none position-relative z-2" href="tienda.php?id=<?= (int) $product['idtiendas'] ?>">
                    <i class="bi bi-shop me-1"></i><?= e($product['tie_nombre']) ?>
                  </a>
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
        <?php
        $visiblePages = 5;
        $half = (int) floor($visiblePages / 2);

        $startPage = max(1, $page - $half);
        $endPage = min($totalPages, $startPage + $visiblePages - 1);

        if (($endPage - $startPage + 1) < $visiblePages) {
            $startPage = max(1, $endPage - $visiblePages + 1);
        }

        $buildPageUrl = function (int $targetPage) use ($q, $categoriaId, $sort): string {
            return '?' . http_build_query([
                'q' => $q,
                'categoria' => $categoriaId,
                'orden' => $sort,
                'page' => $targetPage
            ]) . '#productos';
        };
        ?>

        <nav class="mt-4" aria-label="Paginación">
          <ul class="pagination justify-content-center flex-wrap gap-2">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link rounded-pill" href="<?= $page > 1 ? e($buildPageUrl(1)) : '#' ?>" aria-label="Primera página" title="Ir al inicio">
                <i class="bi bi-chevron-double-left"></i>
              </a>
            </li>

            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link rounded-pill" href="<?= $page > 1 ? e($buildPageUrl($page - 1)) : '#' ?>" aria-label="Anterior" title="Página anterior">
                <i class="bi bi-chevron-left"></i>
              </a>
            </li>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link rounded-pill" href="<?= e($buildPageUrl($i)) ?>">
                  <?= $i ?>
                </a>
              </li>
            <?php endfor; ?>

            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link rounded-pill" href="<?= $page < $totalPages ? e($buildPageUrl($page + 1)) : '#' ?>" aria-label="Siguiente" title="Página siguiente">
                <i class="bi bi-chevron-right"></i>
              </a>
            </li>

            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link rounded-pill" href="<?= $page < $totalPages ? e($buildPageUrl($totalPages)) : '#' ?>" aria-label="Última página" title="Ir al final">
                <i class="bi bi-chevron-double-right"></i>
              </a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </section>

  <section id="tiendas" class="pb-5 position-relative">
    <div class="container">
      <div class="section-header mb-4">
        <div>
          <div class="section-kicker">Tiendas</div>
          <h2 class="section-title mb-2">Explorá por tienda</h2>
          <p class="section-subtitle mb-0">Accedé al catálogo de cada tienda y compará sus precios.</p>
        </div>
      </div>
      <div class="row g-4">
        <?php if ($stores): ?>
          <?php foreach ($stores as $store): ?>
            <?php
            $storeLocationRaw = trim((string) ($store['tie_ubicacion'] ?? ''));
            $storeLocationUrl = '';

            if ($storeLocationRaw !== '') {
                $isLocationUrl = preg_match('~^https?://~i', $storeLocationRaw) === 1;
                $storeLocationUrl = $isLocationUrl
                    ? $storeLocationRaw
                    : 'https://www.google.com/maps?q=' . rawurlencode($storeLocationRaw);
            }
            ?>
            <div class="col-sm-6 col-lg-3">
              <div class="store-card p-4 h-100 fancy-hover">
                <div class="store-logo d-flex align-items-center justify-content-center mb-3 store-logo-box">
                  <?php if (!empty($store['tie_logo'])): ?>
                    <img src="<?= e($store['tie_logo']) ?>" alt="<?= e($store['tie_nombre']) ?>" class="img-fluid rounded-3 store-logo-img">
                  <?php else: ?>
                    <span class="fw-bold fs-4"><?= e(mb_strtoupper(mb_substr($store['tie_nombre'], 0, 2))) ?></span>
                  <?php endif; ?>
                </div>

                <h3 class="h6 fw-bold mb-2"><?= e($store['tie_nombre']) ?></h3>

                <p class="text-body-secondary small mb-3 line-clamp-2">
                  <?= e($store['tie_descripcion'] ?: 'Próximamente más información de esta tienda.') ?>
                </p>

                <?php if ($storeLocationUrl !== ''): ?>
                  <div class="mb-3">
                    <a
                      href="<?= e($storeLocationUrl) ?>"
                      class="btn btn-outline-primary rounded-pill w-100"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <i class="bi bi-geo-alt-fill me-2"></i>Ver ubicación
                    </a>
                  </div>
                <?php endif; ?>

                <div class="small text-body-secondary mb-3">
                  <?= number_format((int) $store['total_productos'], 0, ',', '.') ?> productos
                </div>

                <div class="small text-body-secondary mb-3">
                  <?= $store['ultima_actualizacion'] ? 'Actualizado ' . e(date('d/m/Y H:i', strtotime((string) $store['ultima_actualizacion']))) : 'Sin actualizaciones recientes' ?>
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

<script src="./js/search.js"></script>
<?php render_footer(); ?>
