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
$isSearchPartial = (string) ($_GET['partial'] ?? '') === 'results';

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
    function cp_search_table_exists(PDO $pdo): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'busquedas'");
            $exists = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $exists = false;
        }

        return $exists;
    }
}

if (!function_exists('cp_session_search_history')) {
    function cp_session_search_history(?string $term = null, int $limit = 8): array
    {
        $history = $_SESSION['search_history'] ?? [];
        $history = array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            is_array($history) ? $history : []
        )));

        if ($term !== null && $term !== '') {
            $history = array_values(array_filter(
                $history,
                static fn ($value) => mb_strtolower($value) !== mb_strtolower($term)
            ));
            array_unshift($history, $term);
        }

        $history = array_slice($history, 0, $limit);
        $_SESSION['search_history'] = $history;

        return $history;
    }
}

if (!function_exists('cp_record_search')) {
    function cp_record_search(PDO $pdo, string $term, int $userId = 0): void
    {
        $term = trim($term);
        if ($term === '' || !cp_search_table_exists($pdo)) {
            return;
        }

        try {
            $update = $pdo->prepare("
                UPDATE busquedas
                SET bus_total = COALESCE(bus_total, 0) + 1,
                    bus_ultima_fecha = NOW(),
                    bus_usuario_id = CASE
                        WHEN (bus_usuario_id IS NULL OR bus_usuario_id = 0) AND :usuario_id > 0 THEN :usuario_id
                        ELSE bus_usuario_id
                    END
                WHERE bus_normalizado = :termino
                LIMIT 1
            ");
            $normalizedTerm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? $term), 'UTF-8');
            $update->execute([
                ':termino' => $normalizedTerm,
                ':usuario_id' => $userId,
            ]);

            if ($update->rowCount() === 0) {
                $insert = $pdo->prepare("
                    INSERT INTO busquedas (bus_termino, bus_normalizado, bus_usuario_id, bus_total, bus_ultima_fecha)
                    VALUES (:termino_visible, :termino, :usuario_id, 1, NOW())
                ");
                $insert->execute([
                    ':termino_visible' => $term,
                    ':termino' => $normalizedTerm,
                    ':usuario_id' => $userId > 0 ? $userId : null,
                ]);
            }
        } catch (Throwable $e) {
            // Ignorar si la tabla todavía no existe o tiene otra estructura.
        }

        try {
            $pdo->prepare("
                INSERT INTO busquedas_diarias (bd_normalizado, bd_termino, bd_fecha, bd_total)
                VALUES (:termino, :termino_visible, CURDATE(), 1)
                ON DUPLICATE KEY UPDATE bd_total = bd_total + 1, bd_termino = VALUES(bd_termino)
            ")->execute([
                ':termino' => $normalizedTerm,
                ':termino_visible' => $term,
            ]);
        } catch (Throwable $e) {
            // El conteo diario es opcional: solo alimenta el filtro por fechas de analytics.
        }
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

if (!function_exists('cp_remove_session_search_item')) {
    function cp_remove_session_search_item(string $term): void
    {
        $history = $_SESSION['search_history'] ?? [];
        if (is_array($history)) {
            $_SESSION['search_history'] = array_values(array_filter(
                $history,
                static fn ($value) => mb_strtolower(trim((string) $value), 'UTF-8') !== mb_strtolower(trim($term), 'UTF-8')
            ));
        }
    }
}

if (!function_exists('cp_redirect_without_clear_history')) {
    function cp_redirect_without_clear_history(string $fallback = 'buscar.php'): void
    {
        header('Location: ' . $fallback);
        exit;
    }
}

if (isset($_GET['clear_history']) && (string) $_GET['clear_history'] === '1') {
    cp_clear_session_search_history();
    cp_redirect_without_clear_history('buscar.php');
}

if (isset($_GET['remove_history']) && (string) $_GET['remove_history'] !== '') {
    cp_remove_session_search_item((string) $_GET['remove_history']);
    $params = $_GET;
    unset($params['remove_history']);
    $queryString = http_build_query($params);
    $redirectUrl = 'buscar.php' . ($queryString !== '' ? '?' . $queryString : '');
    header('Location: ' . $redirectUrl);
    exit;
}

if ($isSearchPartial) {
    $recentSearches = [];
    $popularSearches = [];
    $marcas = [];
} elseif ($q !== '') {
    $recentSearches = cp_session_search_history($q, 8);
    cp_record_search($pdo, $q, $currentUserId);
    $popularSearches = cp_get_popular_searches($pdo, 8);
    $marcas = $pdo->query("
        SELECT DISTINCT pro_marca
        FROM productos
        WHERE pro_activo = 1
          AND pro_marca IS NOT NULL
          AND TRIM(pro_marca) <> ''
        ORDER BY pro_marca ASC
    ")->fetchAll();
} else {
    $recentSearches = cp_session_search_history(null, 8);
    $popularSearches = cp_get_popular_searches($pdo, 8);
    $marcas = $pdo->query("
        SELECT DISTINCT pro_marca
        FROM productos
        WHERE pro_activo = 1
          AND pro_marca IS NOT NULL
          AND TRIM(pro_marca) <> ''
        ORDER BY pro_marca ASC
    ")->fetchAll();
}

/* =========================
   WHERE DINÁMICO
   ========================= */
$where = ['p.pro_activo = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(
        p.pro_nombre LIKE :q_nombre
        OR p.pro_descripcion LIKE :q_descripcion
        OR p.pro_marca LIKE :q_marca
        OR t.tie_nombre LIKE :q_tienda
        OR c.cat_nombre LIKE :q_categoria
        OR COALESCE(NULLIF(TRIM(p.pro_grupo), ""), p.pro_nombre) LIKE :q_grupo
    )';

    $likeQ = '%' . $q . '%';
    $params[':q_nombre'] = $likeQ;
    $params[':q_descripcion'] = $likeQ;
    $params[':q_marca'] = $likeQ;
    $params[':q_tienda'] = $likeQ;
    $params[':q_categoria'] = $likeQ;
    $params[':q_grupo'] = $likeQ;
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
    $where[] = 'p.pro_marca = :marca_filtro';
    $params[':marca_filtro'] = $marca;
}

if ($precioMin !== '' && is_numeric($precioMin)) {
    $where[] = 'p.pro_precio >= :precio_min';
    $params[':precio_min'] = (float) $precioMin;
}

if ($precioMax !== '' && is_numeric($precioMax)) {
    $where[] = 'p.pro_precio <= :precio_max';
    $params[':precio_max'] = (float) $precioMax;
}

$whereSql = implode(' AND ', $where);

/* =========================
   CONTEO TOTAL AGRUPADO
   ========================= */
$countSql = "
    SELECT COUNT(*)
    FROM (
        SELECT COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE {$whereSql}
        GROUP BY COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre)
    ) grouped
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);

$totalProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

/* =========================
   ORDEN
   ========================= */
$orderBy = match ($sort) {
    'precio_asc'  => 'MIN(NULLIF(pro_precio, 0)) IS NULL, precio_min ASC, total_ofertas DESC, nombre ASC',
    'precio_desc' => 'MIN(NULLIF(pro_precio, 0)) IS NULL, precio_min DESC, total_ofertas DESC, nombre ASC',
    'nombre_asc'  => 'MIN(NULLIF(pro_precio, 0)) IS NULL, nombre ASC',
    'nombre_desc' => 'MIN(NULLIF(pro_precio, 0)) IS NULL, nombre DESC',
    default       => 'MIN(NULLIF(pro_precio, 0)) IS NULL, precio_min ASC, total_ofertas DESC, nombre ASC',
};

/* =========================
   CONSULTA PRODUCTOS AGRUPADOS
   ========================= */
$sql = "
    SELECT
        grupo,
        MIN(idproductos) AS id_representante,
        MIN(pro_nombre) AS nombre,
        MAX(pro_marca) AS marca,
        MIN(NULLIF(pro_precio, 0)) AS precio_min,
        MAX(pro_imagen) AS imagen,
        MAX(cat_nombre) AS cat_nombre,
        SUM(CASE WHEN pro_en_stock = 1 THEN 1 ELSE 0 END) AS ofertas_stock,
        COUNT(*) AS total_ofertas,
        MAX(pro_fecha_scraping) AS pro_fecha_scraping
    FROM (
        SELECT
            p.idproductos,
            COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo,
            p.pro_nombre,
            p.pro_marca,
            p.pro_precio,
            p.pro_imagen,
            p.pro_en_stock,
            p.pro_fecha_scraping,
            c.cat_nombre
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE {$whereSql}
    ) base
    GROUP BY grupo
    ORDER BY {$orderBy}
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

$currentCategoryName = '';
foreach ($categorias as $categoria) {
    if ((int) $categoria['idcategorias'] === $categoriaId) {
        $currentCategoryName = (string) $categoria['cat_nombre'];
        break;
    }
}

$currentStoreName = '';
foreach ($tiendas as $tienda) {
    if ((int) $tienda['idtiendas'] === $tiendaId) {
        $currentStoreName = (string) $tienda['tie_nombre'];
        break;
    }
}

$hasSideFilters = $categoriaId > 0 || $tiendaId > 0 || $marca !== '' || $precioMin !== '' || $precioMax !== '';
$resultFrom = $totalProducts > 0 ? $offset + 1 : 0;
$resultTo = min($offset + count($products), $totalProducts);
$pageInStock = 0;
$pageOffers = 0;
$pageMinPrice = null;

foreach ($products as $productRow) {
    if ((int) ($productRow['ofertas_stock'] ?? 0) > 0) {
        $pageInStock++;
    }
    $pageOffers += (int) ($productRow['total_ofertas'] ?? 0);
    $priceValue = isset($productRow['precio_min']) ? (float) $productRow['precio_min'] : null;
    if ($priceValue === null || $priceValue <= 0) {
        continue;
    }
    $pageMinPrice = $pageMinPrice === null ? $priceValue : min($pageMinPrice, $priceValue);
}

$pageOutOfStock = max(0, count($products) - $pageInStock);

$buildSearchUrl = static function (array $overrides = []) use ($q, $categoriaId, $tiendaId, $marca, $precioMin, $precioMax, $sort, $page): string {
    $params = array_merge([
        'q' => $q,
        'categoria' => $categoriaId,
        'tienda' => $tiendaId,
        'marca' => $marca,
        'precio_min' => $precioMin,
        'precio_max' => $precioMax,
        'orden' => $sort,
        'page' => $page,
    ], $overrides);

    foreach (['q', 'marca', 'precio_min', 'precio_max'] as $key) {
        if (($params[$key] ?? '') === '') {
            unset($params[$key]);
        }
    }
    if ((int) ($params['categoria'] ?? 0) === 0) {
        unset($params['categoria']);
    }
    if ((int) ($params['tienda'] ?? 0) === 0) {
        unset($params['tienda']);
    }
    if ((int) ($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }

    $query = http_build_query($params);
    return $query !== '' ? '?' . $query : 'buscar.php';
};

$formatFreshness = static function (?string $date): string {
    if ($date === null || trim($date) === '') {
        return 'Sin fecha';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return 'Sin fecha';
    }
    $diff = time() - $ts;
    if ($diff < 3600) {
        return 'Hace minutos';
    }
    if ($diff < 86400) {
        $hours = max(1, (int) floor($diff / 3600));
        return 'Hace ' . $hours . ' h';
    }
    if ($diff < 86400 * 7) {
        $days = max(1, (int) floor($diff / 86400));
        return 'Hace ' . $days . ' d';
    }
    return date('d/m/Y', $ts);
};

if ($isSearchPartial) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
} else {
    render_head('Buscar productos');
    render_navbar('home');
}
?>

<?php if (!$isSearchPartial): ?>
<?php
  $dockPopularSearches = [];
  foreach ($popularSearches as $item) {
      $term = trim((string) ($item['termino'] ?? ''));
      if ($term === '') {
          continue;
      }
      $dockPopularSearches[] = $item;
      if (count($dockPopularSearches) >= 5) {
          break;
      }
  }
?>
<?php if ($dockPopularSearches): ?>
  <div class="container">
    <div class="search-discovery-stack">
      <div class="search-chip-row search-chip-row-hot">
        <span class="search-chip-label search-chip-label-hot"><?= lucide_icon('flame', '', 12) ?>Tendencias</span>
        <div class="search-chip-track">
          <?php foreach ($dockPopularSearches as $item): ?>
            <a
              class="search-chip search-chip-hot"
              href="buscar.php?q=<?= rawurlencode((string) $item['termino']) ?>"
              data-search-chip="popular"
              data-search-term="<?= e((string) $item['termino']) ?>"
            ><?= e((string) $item['termino']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<section class="products-section page-search-results" aria-labelledby="search-results-title" data-search-page>
  <div class="container">
    <header class="sr-head">
      <div class="sr-head-main">
        <p class="products-kicker">Resultados de búsqueda</p>
        <h2 id="search-results-title" class="section-title sr-title">
          <?= $q !== '' ? '“' . e($q) . '”' : 'Catálogo completo' ?>
        </h2>
        <p class="sr-meta" data-sr-slot="meta"><?php if ($totalProducts > 0): ?>Mostrando <?= number_format($resultFrom, 0, ',', '.') ?>–<?= number_format($resultTo, 0, ',', '.') ?> de <?= number_format($totalProducts, 0, ',', '.') ?><?php else: ?>Sin resultados<?php endif; ?></p>
      </div>
    </header>

    <dl class="sr-kpis" aria-label="Indicadores de esta página" data-sr-slot="kpis">
<?php else: ?>
<div data-search-results-partial>
    <p class="sr-meta" data-sr-slot="meta"><?php if ($totalProducts > 0): ?>Mostrando <?= number_format($resultFrom, 0, ',', '.') ?>–<?= number_format($resultTo, 0, ',', '.') ?> de <?= number_format($totalProducts, 0, ',', '.') ?><?php else: ?>Sin resultados<?php endif; ?></p>
    <dl class="sr-kpis" aria-label="Indicadores de esta página" data-sr-slot="kpis">
<?php endif; ?>
      <div class="sr-kpi" data-kpi="results">
        <dt>Resultados</dt>
        <dd><?= number_format($totalProducts, 0, ',', '.') ?></dd>
        <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('layout-grid', '', 28) ?></span>
      </div>

      <div class="sr-kpi" data-kpi="price">
        <dt>Desde</dt>
        <dd><?= $pageMinPrice !== null ? gs($pageMinPrice) : '—' ?></dd>
        <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('tag', '', 28) ?></span>
      </div>

      <div class="sr-kpi" data-kpi="offers">
        <dt>Ofertas</dt>
        <dd><?= number_format($pageOffers, 0, ',', '.') ?></dd>
        <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('layers', '', 28) ?></span>
      </div>

      <div class="sr-kpi" data-kpi="stock">
        <dt>Con stock</dt>
        <dd><?= number_format($pageInStock, 0, ',', '.') ?></dd>
        <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('package', '', 28) ?></span>
      </div>

      <div class="sr-kpi" data-kpi="nostock">
        <dt>Sin stock</dt>
        <dd><?= number_format($pageOutOfStock, 0, ',', '.') ?></dd>
        <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('package-x', '', 28) ?></span>
      </div>
    </dl>

<?php if (!$isSearchPartial): ?>
    <div class="sr-filters<?= $hasSideFilters ? ' has-active-filters' : '' ?>" aria-label="Filtros de búsqueda" data-sr-filters>
      <button
        type="button"
        class="sr-filters-toggle"
        aria-expanded="<?= $hasSideFilters ? 'true' : 'false' ?>"
        aria-controls="sr-filters-panel"
        data-sr-filters-toggle
      >
        <span class="sr-filters-toggle-main">
          <?= lucide_icon('sliders-horizontal', '', 16) ?>
          <span>Filtros</span>
          <?php if ($hasSideFilters): ?>
            <span class="sr-filters-toggle-badge">Activos</span>
          <?php endif; ?>
        </span>
        <span class="sr-filters-toggle-hint" data-sr-filters-hint><?= $hasSideFilters ? 'Ocultar' : 'Mostrar' ?></span>
      </button>

      <div
        id="sr-filters-panel"
        class="sr-filters-panel<?= $hasSideFilters ? ' is-open' : '' ?>"
        data-sr-filters-panel
        <?= $hasSideFilters ? '' : 'hidden' ?>
      >
        <form method="get" action="buscar.php" class="sr-filters-form js-sidebar-filter-form">
          <input type="hidden" name="q" value="<?= e($q) ?>">
          <input type="hidden" name="orden" value="<?= e($sort) ?>">

          <div class="sr-field">
            <label class="sr-field-label" for="search-filter-categoria">Categoría</label>
            <select id="search-filter-categoria" name="categoria" class="form-select search-filter-control">
              <option value="0">Todas</option>
              <?php foreach ($categorias as $categoria): ?>
                <option value="<?= (int) $categoria['idcategorias'] ?>" <?= $categoriaId === (int) $categoria['idcategorias'] ? 'selected' : '' ?>>
                  <?= e($categoria['cat_nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="sr-field">
            <label class="sr-field-label" for="search-filter-tienda">Tienda</label>
            <select id="search-filter-tienda" name="tienda" class="form-select search-filter-control">
              <option value="0">Todas</option>
              <?php foreach ($tiendas as $tienda): ?>
                <option value="<?= (int) $tienda['idtiendas'] ?>" <?= $tiendaId === (int) $tienda['idtiendas'] ? 'selected' : '' ?>>
                  <?= e($tienda['tie_nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="sr-field">
            <label class="sr-field-label" for="search-filter-marca">Marca</label>
            <select id="search-filter-marca" name="marca" class="form-select search-filter-control">
              <option value="">Todas</option>
              <?php foreach ($marcas as $m): ?>
                <option value="<?= e($m['pro_marca']) ?>" <?= $marca === $m['pro_marca'] ? 'selected' : '' ?>>
                  <?= e($m['pro_marca']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="sr-field sr-field-price">
            <span class="sr-field-label">Precio</span>
            <div class="search-filter-price">
              <div>
                <label class="visually-hidden" for="search-filter-precio-min">Precio mínimo</label>
                <input id="search-filter-precio-min" type="number" step="0.01" name="precio_min" class="form-control search-filter-control" value="<?= e($precioMin) ?>" placeholder="Mín." inputmode="decimal">
              </div>
              <span class="search-filter-price-sep" aria-hidden="true">–</span>
              <div>
                <label class="visually-hidden" for="search-filter-precio-max">Precio máximo</label>
                <input id="search-filter-precio-max" type="number" step="0.01" name="precio_max" class="form-control search-filter-control" value="<?= e($precioMax) ?>" placeholder="Máx." inputmode="decimal">
              </div>
            </div>
          </div>

          <div class="sr-field sr-field-actions">
            <span class="sr-field-label sr-field-label-spacer" aria-hidden="true">&nbsp;</span>
            <div class="search-filter-actions">
              <button type="submit" class="search-filter-apply">Aplicar</button>
              <a href="<?= e($buildSearchUrl(['categoria' => 0, 'tienda' => 0, 'marca' => '', 'precio_min' => '', 'precio_max' => '', 'page' => 1])) ?>" class="search-filter-reset">Limpiar</a>
            </div>
          </div>
        </form>

        <?php if ($hasSideFilters): ?>
          <div class="sr-chips products-chips">
            <?php if ($categoriaId > 0): ?>
              <a class="products-chip" href="<?= e($buildSearchUrl(['categoria' => 0, 'page' => 1])) ?>">
                <?= lucide_icon('tag', '', 14) ?>
                <span><?= e($currentCategoryName !== '' ? $currentCategoryName : 'Categoría') ?></span>
                <?= lucide_icon('x', '', 14) ?>
              </a>
            <?php endif; ?>
            <?php if ($tiendaId > 0): ?>
              <a class="products-chip" href="<?= e($buildSearchUrl(['tienda' => 0, 'page' => 1])) ?>">
                <?= lucide_icon('store', '', 14) ?>
                <span><?= e($currentStoreName !== '' ? $currentStoreName : 'Tienda') ?></span>
                <?= lucide_icon('x', '', 14) ?>
              </a>
            <?php endif; ?>
            <?php if ($marca !== ''): ?>
              <a class="products-chip" href="<?= e($buildSearchUrl(['marca' => '', 'page' => 1])) ?>">
                <?= lucide_icon('award', '', 14) ?>
                <span><?= e($marca) ?></span>
                <?= lucide_icon('x', '', 14) ?>
              </a>
            <?php endif; ?>
            <?php if ($precioMin !== '' || $precioMax !== ''): ?>
              <a class="products-chip" href="<?= e($buildSearchUrl(['precio_min' => '', 'precio_max' => '', 'page' => 1])) ?>">
                <?= lucide_icon('circle-dollar-sign', '', 14) ?>
                <span>
                  <?= $precioMin !== '' ? e($precioMin) : '0' ?>
                  –
                  <?= $precioMax !== '' ? e($precioMax) : '∞' ?>
                </span>
                <?= lucide_icon('x', '', 14) ?>
              </a>
            <?php endif; ?>
            <a class="products-clear" href="<?= e($buildSearchUrl(['categoria' => 0, 'tienda' => 0, 'marca' => '', 'precio_min' => '', 'precio_max' => '', 'page' => 1])) ?>">Quitar filtros</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
<?php endif; ?>

    <div class="sr-board" data-sr-slot="board">
      <?php if ($products): ?>
        <?php
          $tileWashPalette = [
            '#8ba4c4',
            '#c9a87a',
            '#c48b8e',
            '#8fad97',
            '#a89bc4',
            '#7eb8b0',
            '#c4a88b',
            '#9bb0c9',
          ];
          $tileWashColors = $tileWashPalette;
          $tileWashSeed = crc32('search-tile|' . $page . '|' . $sort . '|' . $q . '|' . $categoriaId . '|' . $tiendaId . '|' . $marca);
          mt_srand($tileWashSeed);
          for ($i = count($tileWashColors) - 1; $i > 0; $i--) {
              $j = mt_rand(0, $i);
              $swap = $tileWashColors[$i];
              $tileWashColors[$i] = $tileWashColors[$j];
              $tileWashColors[$j] = $swap;
          }
          mt_srand();
        ?>
        <?php foreach ($products as $index => $product): ?>
          <?php
            $productName = (string) ($product['grupo'] ?: $product['nombre'] ?: 'Producto');
            $productUrl = 'producto.php?grupo=' . rawurlencode((string) $product['grupo']) . ($q !== '' ? '&q=' . rawurlencode($q) : '');
            $inStock = ((int) ($product['ofertas_stock'] ?? 0)) > 0;
            $offersCount = (int) ($product['total_ofertas'] ?? 0);
            $tileTone = $inStock ? 'stock' : 'wait';
            $tileWash = $tileWashColors[$index % count($tileWashColors)];
            $catalogNumber = $offset + (int) $index + 1;
            $scrapedAt = (string) ($product['pro_fecha_scraping'] ?? '');
            $scrapedTs = $scrapedAt !== '' ? strtotime($scrapedAt) : false;
            $scrapedExact = $scrapedTs ? date('d/m/Y H:i', $scrapedTs) : 'Sin fecha';
          ?>
          <article
            class="sr-card clickable-card"
            data-tone="<?= e($tileTone) ?>"
            style="--tile-wash: <?= e($tileWash) ?>;"
            data-card-href="<?= e($productUrl) ?>"
            role="link"
            tabindex="0"
            aria-label="Comparar <?= e($productName) ?>"
          >
            <?php $cardImg = image_url($product['imagen'] ?? null, $productName); ?>
            <a class="sr-card-media" href="<?= e($productUrl) ?>">
              <?php if ($cardImg !== ''): ?>
                <img
                  src="<?= e($cardImg) ?>"
                  alt="<?= e($productName) ?>"
                  loading="lazy"
                  onerror="this.onerror=null;if(window.applyProductImageFallback){applyProductImageFallback(this);}else{this.style.display='none';}"
                >
              <?php else: ?>
                <span class="img-fallback" role="img" aria-label="<?= e($productName) ?>">
                  <?= lucide_icon('image', '', 48) ?>
                </span>
              <?php endif; ?>
              <span class="sr-card-index"><?= str_pad((string) $catalogNumber, 2, '0', STR_PAD_LEFT) ?></span>
              <span class="sr-card-flag <?= $inStock ? 'is-stock' : 'is-wait' ?>"><?= $inStock ? 'Stock' : 'Consultar' ?></span>
            </a>

            <div class="sr-card-body">
              <div class="sr-card-tags">
                <span class="sr-card-cat"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                <?php if (!empty($product['marca'])): ?>
                  <span class="sr-card-brand"><?= e($product['marca']) ?></span>
                <?php endif; ?>
              </div>

              <h3 class="sr-card-name">
                <a href="<?= e($productUrl) ?>"><?= e($productName) ?></a>
              </h3>

              <div class="sr-card-priceblock">
                <div>
                  <p class="sr-card-from">Desde</p>
                  <p class="sr-card-price"><?= gs_precio($product['precio_min']) ?></p>
                </div>
                <span class="sr-card-offers" title="<?= e($scrapedExact) ?>">
                  <?= number_format($offersCount, 0, ',', '.') ?> oferta<?= $offersCount === 1 ? '' : 's' ?>
                </span>
              </div>

              <div class="sr-card-foot">
                <span class="sr-card-fresh" title="<?= e($scrapedExact) ?>">
                  <?= lucide_icon('clock', '', 13) ?>
                  <?= e($formatFreshness($scrapedAt)) ?>
                </span>
                <a class="sr-card-cta" href="<?= e($productUrl) ?>">
                  Comparar<?= $offersCount > 1 ? ' · ' . number_format($offersCount, 0, ',', '.') : '' ?>
                  <?= lucide_icon('arrow-right', '', 14) ?>
                </a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="products-empty sr-empty">
          <h3 class="sr-empty-title">
            <?= lucide_icon('circle-x', 'sr-empty-title-icon', 22) ?>
            No hay productos para mostrar
          </h3>
          <p>Prueba otra búsqueda o cambia los filtros actuales.</p>
          <a class="products-empty-link" href="buscar.php">
            Ver catálogo completo
            <?= lucide_icon('arrow-right', '', 15) ?>
          </a>
        </div>
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
      ?>
      <nav class="products-pager sr-pager" aria-label="Paginación de resultados" data-sr-slot="pager">
        <p class="products-pager-status">Página <?= number_format($page, 0, ',', '.') ?> de <?= number_format($totalPages, 0, ',', '.') ?></p>
        <div class="products-pager-controls">
          <?php if ($page > 1): ?>
            <a class="products-pager-btn" href="<?= e($buildSearchUrl(['page' => 1])) ?>" aria-label="Primera página" title="Ir al inicio"><?= lucide_icon('chevrons-left', '', 16) ?></a>
            <a class="products-pager-btn" href="<?= e($buildSearchUrl(['page' => $page - 1])) ?>" aria-label="Página anterior" title="Página anterior"><?= lucide_icon('chevron-left', '', 16) ?></a>
          <?php else: ?>
            <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevrons-left', '', 16) ?></span>
            <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevron-left', '', 16) ?></span>
          <?php endif; ?>

          <div class="products-pager-pages">
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
              <a
                class="products-pager-num<?= $i === $page ? ' is-active' : '' ?>"
                href="<?= e($buildSearchUrl(['page' => $i])) ?>"
                <?= $i === $page ? 'aria-current="page"' : '' ?>
              >
                <?= $i ?>
              </a>
            <?php endfor; ?>
          </div>

          <?php if ($page < $totalPages): ?>
            <a class="products-pager-btn" href="<?= e($buildSearchUrl(['page' => $page + 1])) ?>" aria-label="Página siguiente" title="Página siguiente"><?= lucide_icon('chevron-right', '', 16) ?></a>
            <a class="products-pager-btn" href="<?= e($buildSearchUrl(['page' => $totalPages])) ?>" aria-label="Última página" title="Ir al final"><?= lucide_icon('chevrons-right', '', 16) ?></a>
          <?php else: ?>
            <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevron-right', '', 16) ?></span>
            <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevrons-right', '', 16) ?></span>
          <?php endif; ?>
        </div>
      </nav>
    <?php else: ?>
      <div data-sr-slot="pager" hidden></div>
    <?php endif; ?>
<?php if ($isSearchPartial): ?>
</div>
<?php
    exit;
endif;
?>
  </div>
</section>

<script>
(function () {
  let searchRequest = 0;

  function searchPartialUrl(url) {
    const next = new URL(url, window.location.href);
    next.searchParams.set('partial', 'results');
    next.hash = '';
    return next.toString();
  }

  function publicSearchUrl(url) {
    const next = new URL(url, window.location.href);
    next.searchParams.delete('partial');
    return next.pathname + next.search + next.hash;
  }

  function refreshSearchVisuals(root) {
    if (!root) return;
    if (window.refreshLucideIcons) window.refreshLucideIcons();
    if (window.applyProductImageFallback) {
      root.querySelectorAll('img').forEach(function (img) {
        if (!(img.getAttribute('src') || '').trim()) {
          window.applyProductImageFallback(img);
        }
      });
    }
  }

  function loadSearchResults(url, options) {
    const opts = options || {};
    const pageRoot = document.querySelector('[data-search-page]');
    if (!pageRoot) return Promise.resolve();

    const scrollY = window.scrollY;
    const requestId = ++searchRequest;
    pageRoot.classList.add('is-loading');
    pageRoot.setAttribute('aria-busy', 'true');

    return fetch(searchPartialUrl(url), {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'text/html'
      }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('No se pudo cargar los resultados.');
        return response.text();
      })
      .then(function (html) {
        if (requestId !== searchRequest) return;

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const partial = doc.querySelector('[data-search-results-partial]') || doc.body;

        ['meta', 'kpis', 'board', 'pager'].forEach(function (slot) {
          const next = partial.querySelector('[data-sr-slot="' + slot + '"]');
          const current = pageRoot.querySelector('[data-sr-slot="' + slot + '"]');
          if (!next) return;

          if (current) {
            current.replaceWith(document.importNode(next, true));
            return;
          }

          if (slot === 'pager') {
            const board = pageRoot.querySelector('[data-sr-slot="board"]');
            if (board) board.after(document.importNode(next, true));
          }
        });

        refreshSearchVisuals(pageRoot);

        const publicUrl = publicSearchUrl(url);
        if (opts.replace) {
          history.replaceState({ searchResults: true }, '', publicUrl);
        } else {
          history.pushState({ searchResults: true }, '', publicUrl);
        }

        window.scrollTo(0, scrollY);
      })
      .catch(function () {
        if (requestId !== searchRequest) return;
        window.location.href = publicSearchUrl(url);
      })
      .finally(function () {
        if (requestId !== searchRequest) return;
        const current = document.querySelector('[data-search-page]');
        if (current) {
          current.classList.remove('is-loading');
          current.removeAttribute('aria-busy');
        }
      });
  }

  document.addEventListener('click', function (event) {
    const link = event.target.closest('[data-search-page] .sr-pager a[href]');
    if (!link) return;
    if (event.defaultPrevented) return;
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;

    let targetUrl;
    try {
      targetUrl = new URL(link.href, window.location.href);
    } catch (error) {
      return;
    }

    if (targetUrl.origin !== window.location.origin) return;
    if (targetUrl.searchParams.get('partial')) return;

    const path = targetUrl.pathname.replace(/\/+$/, '');
    const currentPath = window.location.pathname.replace(/\/+$/, '');
    const looksLikeSearch =
      /(?:^|\/)buscar\.php$/i.test(targetUrl.pathname) ||
      path === currentPath;

    if (!looksLikeSearch) return;

    event.preventDefault();
    event.stopPropagation();
    loadSearchResults(targetUrl);
  });

  window.addEventListener('popstate', function () {
    if (!document.querySelector('[data-search-page]')) return;
    loadSearchResults(window.location.href, { replace: true });
  });
})();

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

    const filtersRoot = document.querySelector('[data-sr-filters]');
    const filtersToggle = document.querySelector('[data-sr-filters-toggle]');
    const filtersPanel = document.querySelector('[data-sr-filters-panel]');
    const filtersHint = document.querySelector('[data-sr-filters-hint]');

    if (filtersRoot && filtersToggle && filtersPanel) {
        const desktopQuery = window.matchMedia('(min-width: 768px)');

        const setFiltersOpen = (open) => {
            filtersPanel.classList.toggle('is-open', open);
            filtersPanel.hidden = !open;
            filtersToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (filtersHint) {
                filtersHint.textContent = open ? 'Ocultar' : 'Mostrar';
            }
        };

        const syncFiltersForViewport = () => {
            if (desktopQuery.matches) {
                setFiltersOpen(true);
                return;
            }
            const preferOpen = filtersRoot.classList.contains('has-active-filters')
                || filtersToggle.getAttribute('aria-expanded') === 'true';
            setFiltersOpen(preferOpen);
        };

        filtersToggle.addEventListener('click', function () {
            if (desktopQuery.matches) return;
            setFiltersOpen(filtersPanel.hidden);
        });

        if (typeof desktopQuery.addEventListener === 'function') {
            desktopQuery.addEventListener('change', syncFiltersForViewport);
        } else if (typeof desktopQuery.addListener === 'function') {
            desktopQuery.addListener(syncFiltersForViewport);
        }

        syncFiltersForViewport();
    }
});

document.addEventListener('click', function (event) {
  const card = event.target.closest('.clickable-card[data-card-href]');
  if (!card) return;
  if (event.target.closest('a, button, input, select, textarea, label, [data-no-card-click]')) {
    return;
  }
  window.location.href = card.dataset.cardHref;
});

document.addEventListener('keydown', function (event) {
  if (event.key !== 'Enter' && event.key !== ' ') return;
  const card = event.target.closest('.clickable-card[data-card-href]');
  if (!card || event.target !== card) return;
  event.preventDefault();
  window.location.href = card.dataset.cardHref;
});

</script>
<?php render_footer(); ?>