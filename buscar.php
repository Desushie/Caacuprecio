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

if ($q !== '') {
    $recentSearches = cp_session_search_history($q, 8);
    cp_record_search($pdo, $q, $currentUserId);
} else {
    $recentSearches = cp_session_search_history(null, 8);
}

$popularSearches = cp_get_popular_searches($pdo, 8);

$marcas = $pdo->query("
    SELECT DISTINCT pro_marca
    FROM productos
    WHERE pro_activo = 1
      AND pro_marca IS NOT NULL
      AND TRIM(pro_marca) <> ''
    ORDER BY pro_marca ASC
")->fetchAll();

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
    'precio_asc'  => 'precio_min ASC, total_ofertas DESC, nombre ASC',
    'precio_desc' => 'precio_min DESC, total_ofertas DESC, nombre ASC',
    'nombre_asc'  => 'nombre ASC',
    'nombre_desc' => 'nombre DESC',
    default       => 'precio_min ASC, total_ofertas DESC, nombre ASC',
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
        MIN(pro_precio) AS precio_min,
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

render_head('Buscar productos');
render_navbar('home');
?>

<div class="search-sticky-bar">
  <div class="container">
    <div
      class="search-bar glass-card p-3 p-lg-3"
      data-search-root
      data-search-endpoint="buscar_api.php"
      data-search-results-page="buscar.php"
      data-search-history-endpoint="guardar_busqueda.php"
      data-search-mode="pro"
      data-search-context="home"
      data-live-target="#results-live-results"
      data-count-target="#results-live-count"
      data-state-target="#results-live-state"
    >
      <form class="row g-2 align-items-center js-smart-search-form" method="get" action="buscar.php" autocomplete="off">
        <input type="hidden" name="tienda" value="<?= (int) $tiendaId ?>" data-search-filter="tienda">
        <input type="hidden" name="marca" value="<?= e($marca) ?>" data-search-filter="marca">
        <input type="hidden" name="precio_min" value="<?= e($precioMin) ?>" data-search-filter="precio_min">
        <input type="hidden" name="precio_max" value="<?= e($precioMax) ?>" data-search-filter="precio_max">

        <div class="col-lg-5 position-relative">
          <label for="global-search-results" class="visually-hidden">Buscar productos</label>
          <input
            id="global-search-results"
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
            aria-controls="global-search-results-suggestions"
          >
          <div
            id="global-search-results-suggestions"
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
                  <span class="search-chip-label">
                    <i class="bi bi-clock-history me-1"></i>Historial
                  </span>

                  <?php foreach ($recentSearches as $term): ?>
                    <a
                      class="search-chip"
                      href="buscar.php?q=<?= rawurlencode($term) ?>"
                      data-search-chip="history"
                      data-search-term="<?= e($term) ?>"
                    ><?= e($term) ?></a>
                  <?php endforeach; ?>

                  <a class="search-chip search-chip-clear search-chip-clear-danger"
                    href="buscar.php?clear_history=1"
                    title="Limpiar historial">
                    <i class="bi bi-trash3 me-1"></i>Limpiar todo
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
        <div class="col-12">
        </div>
        </div>
      </form>
    </div>
  </div>
</div>

<section class="page-section page-search-results py-5">
  <div class="container">
    <div class="row g-4">

      <aside class="col-lg-3">
        <div class="glass-card p-4 search-filters-sidebar">
          <h3 class="h5 fw-bold mb-3">Filtros</h3>

          <form method="get" action="buscar.php" class="d-grid gap-3">
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <input type="hidden" name="orden" value="<?= e($sort) ?>">

            <div>
              <label class="form-label">Categoría</label>
              <select name="categoria" class="form-select">
                <option value="0">Todas</option>
                <?php foreach ($categorias as $categoria): ?>
                  <option value="<?= (int) $categoria['idcategorias'] ?>" <?= $categoriaId === (int) $categoria['idcategorias'] ? 'selected' : '' ?>>
                    <?= e($categoria['cat_nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Tienda</label>
              <select name="tienda" class="form-select">
                <option value="0">Todas</option>
                <?php foreach ($tiendas as $tienda): ?>
                  <option value="<?= (int) $tienda['idtiendas'] ?>" <?= $tiendaId === (int) $tienda['idtiendas'] ? 'selected' : '' ?>>
                    <?= e($tienda['tie_nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Marca</label>
              <select name="marca" class="form-select">
                <option value="">Todas</option>
                <?php foreach ($marcas as $m): ?>
                  <option value="<?= e($m['pro_marca']) ?>" <?= $marca === $m['pro_marca'] ? 'selected' : '' ?>>
                    <?= e($m['pro_marca']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Precio mín.</label>
                <input type="number" step="0.01" name="precio_min" class="form-control" value="<?= e($precioMin) ?>" placeholder="0">
              </div>
              <div class="col-6">
                <label class="form-label">Precio máx.</label>
                <input type="number" step="0.01" name="precio_max" class="form-control" value="<?= e($precioMax) ?>" placeholder="9999999">
              </div>
            </div>

            <button type="submit" class="btn btn-primary rounded-pill">Aplicar filtros</button>

            <a href="buscar.php?q=<?= rawurlencode($q) ?>&orden=<?= rawurlencode($sort) ?>" class="btn btn-outline-secondary rounded-pill">
              Limpiar filtros
            </a>
          </form>
        </div>
      </aside>

      <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-end gap-3 mb-4 flex-wrap">
          <div>
            <h2 class="mb-2">
              <?= $q !== '' ? 'Resultados para: "' . e($q) . '"' : 'Todos los productos' ?>
            </h2>
          </div>
          <div class="small text-body-secondary">
            <?= number_format($totalProducts, 0, ',', '.') ?> resultado(s)
          </div>
        </div>

        <div class="row g-4">
          <?php if ($products): ?>
            <?php foreach ($products as $product): ?>
              <div class="col-md-6 col-xl-3">
                <article class="custom-card product-card h-100 p-3 fancy-hover">
                  <a href="producto.php?grupo=<?= rawurlencode((string) $product['grupo']) ?>&q=<?= rawurlencode($q) ?>" class="text-decoration-none text-reset d-block">
                    <div class="product-thumb-wrap mb-3">
                      <img class="offer-thumb" src="<?= e(image_url($product['imagen'] ?? null, $product['nombre'] ?? 'Producto')) ?>" alt="<?= e($product['nombre'] ?? 'Producto') ?>">
                    </div>
                  </a>

                  <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <span class="badge soft-badge"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                    <span class="mini-badge <?= ((int) ($product['ofertas_stock'] ?? 0)) > 0 ? 'badge-stock-ok' : 'badge-stock-no' ?>">
                      <?= ((int) ($product['ofertas_stock'] ?? 0)) > 0 ? 'En stock' : 'Sin stock' ?>
                    </span>
                  </div>

                  <h3 class="h6 fw-bold mb-2">
                    <a href="producto.php?grupo=<?= rawurlencode((string) $product['grupo']) ?>&q=<?= rawurlencode($q) ?>" class="text-decoration-none text-reset stretched-link-sibling">
                      <?= e($product['grupo'] ?: $product['nombre']) ?>
                    </a>
                  </h3>

                  <?php if (!empty($product['marca'])): ?>
                    <div class="small text-body-secondary mb-3">Marca: <strong><?= e($product['marca']) ?></strong></div>
                  <?php endif; ?>

                  <div class="d-flex align-items-end justify-content-between mb-3 gap-2">
                    <div>
                      <div class="price-now"><?= gs($product['precio_min']) ?></div>
                      <div class="small text-body-secondary">Desde</div>
                    </div>
                    <div class="text-end small text-body-secondary">
                      <div><?= (int) ($product['total_ofertas'] ?? 0) ?> oferta(s)</div>
                      <div><?= !empty($product['pro_fecha_scraping']) ? e(date('d/m/Y', strtotime((string) $product['pro_fecha_scraping']))) : '' ?></div>
                    </div>
                  </div>

                  <div class="product-meta d-flex justify-content-between align-items-center gap-2 flex-wrap border-top pt-3">
                    <div class="small text-body-secondary position-relative z-2">
                      Mejor precio agrupado
                    </div>
                    <a href="producto.php?grupo=<?= rawurlencode((string) $product['grupo']) ?>&q=<?= rawurlencode($q) ?>" class="btn btn-sm btn-outline-primary rounded-pill position-relative z-2">
                      Ver ofertas
                    </a>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="col-12">
              <div class="empty-state">No se encontraron productos con los filtros actuales.</div>
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

          $buildUrl = function (int $p) use ($q, $categoriaId, $tiendaId, $marca, $precioMin, $precioMax, $sort): string {
              return '?' . http_build_query([
                  'q' => $q,
                  'categoria' => $categoriaId,
                  'tienda' => $tiendaId,
                  'marca' => $marca,
                  'precio_min' => $precioMin,
                  'precio_max' => $precioMax,
                  'orden' => $sort,
                  'page' => $p
              ]);
          };
          ?>

          <nav class="mt-4" aria-label="Paginación">
            <ul class="pagination justify-content-center flex-wrap gap-2">
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link rounded-pill" href="<?= $page > 1 ? e($buildUrl(1)) : '#' ?>" title="Ir al inicio">«</a>
              </li>

              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link rounded-pill" href="<?= $page > 1 ? e($buildUrl($page - 1)) : '#' ?>" title="Página anterior">‹</a>
              </li>

              <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                  <a class="page-link rounded-pill" href="<?= e($buildUrl($i)) ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>

              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link rounded-pill" href="<?= $page < $totalPages ? e($buildUrl($page + 1)) : '#' ?>" title="Página siguiente">›</a>
              </li>

              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link rounded-pill" href="<?= $page < $totalPages ? e($buildUrl($totalPages)) : '#' ?>" title="Ir al final">»</a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>
      </div>

    </div>
  </div>
</section>

<script src="./js/search.js"></script>
<?php render_footer(); ?>