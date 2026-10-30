<?php
require_once __DIR__ . '/config.php';
require_admin_or_empresa();

$pdo = db();
$user = current_user();
$isEmpresa = is_empresa();
$myStoreId = $isEmpresa ? (int)($user['tiendas_idtiendas'] ?? 0) : 0;

const AP_ESTADOS = ['activos' => 'Publicados', 'inactivos' => 'Ocultos'];
const AP_STOCKS = ['si' => 'En stock', 'no' => 'Consultar stock'];
const AP_VISTAS = [
    'ofertas' => 'En oferta',
    'sin_foto' => 'Sin foto',
    'sin_categoria' => 'Sin categoría',
    'sin_precio' => 'Sin precio',
];
const AP_ORDENES = [
    'recientes' => 'Más recientes',
    'precio_asc' => 'Precio: menor a mayor',
    'precio_desc' => 'Precio: mayor a menor',
    'descuento' => 'Mayor descuento',
    'nombre' => 'Nombre (A-Z)',
];
const AP_FILAS = [10, 25, 50, 100];
const AP_FILAS_DEFAULT = 25;

// Normaliza los filtros admitidos desde cualquier origen (GET o la consulta de retorno del POST)
function admin_productos_filters(array $source): array {
    global $isEmpresa;

    $pick = static function (string $key, array $allowed) use ($source): string {
        $value = (string) ($source[$key] ?? '');
        return array_key_exists($value, $allowed) ? $value : '';
    };

    $orden = $pick('orden', AP_ORDENES);
    $filas = (int) ($source['filas'] ?? 0);

    return [
        'q' => trim((string) ($source['q'] ?? '')),
        'tienda' => $isEmpresa ? 0 : max(0, (int) ($source['tienda'] ?? 0)),
        'categoria' => max(0, (int) ($source['categoria'] ?? 0)),
        'estado' => $pick('estado', AP_ESTADOS),
        'stock' => $pick('stock', AP_STOCKS),
        'vista' => $pick('vista', AP_VISTAS),
        'orden' => $orden === 'recientes' ? '' : $orden,
        'filas' => in_array($filas, AP_FILAS, true) && $filas !== AP_FILAS_DEFAULT ? $filas : 0,
        'page' => max(1, (int) ($source['page'] ?? 1)),
    ];
}

function admin_productos_query(array $params): string {
    $params = array_filter($params, static function ($value, $key) {
        if ($key === 'page') {
            return (int) $value > 1;
        }
        return $value !== '' && $value !== null && $value !== 0 && $value !== '0';
    }, ARRAY_FILTER_USE_BOTH);

    return http_build_query($params);
}

function build_admin_productos_url(array $overrides = []): string {
    $query = admin_productos_query(array_merge(admin_productos_filters($_GET), $overrides));
    return 'admin_productos.php' . ($query !== '' ? '?' . $query : '');
}

function admin_productos_url_reset_filters(string $q): string {
    $query = admin_productos_query(['q' => $q, 'filas' => admin_productos_filters($_GET)['filas']]);
    return 'admin_productos.php' . ($query !== '' ? '?' . $query : '');
}

function ap_time_ago(?string $date): string {
    $ts = $date ? strtotime($date) : false;
    if (!$ts) {
        return 'Sin fecha';
    }
    $diff = max(0, time() - $ts);
    if ($diff < 3600) {
        return 'hace ' . max(1, (int) floor($diff / 60)) . ' min';
    }
    if ($diff < 86400) {
        return 'hace ' . (int) floor($diff / 3600) . ' h';
    }
    if ($diff < 86400 * 7) {
        $days = (int) floor($diff / 86400);
        return 'hace ' . $days . ($days === 1 ? ' día' : ' días');
    }
    return date('d/m/Y', $ts);
}

// PROCESAMIENTO DEL FORMULARIO (GUARDAR / EDITAR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_product') {
    $id = (int) ($_POST['idproductos'] ?? 0);
    $tiendaIdInput = (int) ($_POST['tiendas_idtiendas'] ?? 0);

    if ($isEmpresa) {
        $tiendaIdInput = $myStoreId;

        if ($id > 0) {
            $check = $pdo->prepare("SELECT tiendas_idtiendas FROM productos WHERE idproductos = ?");
            $check->execute([$id]);
            $ownerStoreId = (int) $check->fetchColumn();

            if ($ownerStoreId !== $myStoreId) {
                header("Location: admin_productos.php?error=no_autorizado");
                exit;
            }
        }
    }

    $stmt = $pdo->prepare("
        UPDATE productos
        SET
            pro_nombre = :nombre,
            pro_descripcion = :descripcion,
            pro_marca = :marca,
            pro_precio = :precio,
            pro_precio_anterior = :precio_anterior,
            pro_imagen = :imagen,
            pro_url = :url,
            pro_en_stock = :stock,
            pro_activo = :activo,
            tiendas_idtiendas = :tienda,
            categorias_idcategorias = :categoria
        WHERE idproductos = :id
    ");

    $stmt->execute([
        'id' => $id,
        'nombre' => trim((string) ($_POST['pro_nombre'] ?? '')),
        'descripcion' => trim((string) ($_POST['pro_descripcion'] ?? '')),
        'marca' => trim((string) ($_POST['pro_marca'] ?? '')),
        'precio' => (float) ($_POST['pro_precio'] ?? 0),
        'precio_anterior' => ($_POST['pro_precio_anterior'] ?? '') !== ''
            ? (float) $_POST['pro_precio_anterior']
            : null,
        'imagen' => trim((string) ($_POST['pro_imagen'] ?? '')),
        'url' => trim((string) ($_POST['pro_url'] ?? '')),
        'stock' => (int) ($_POST['pro_en_stock'] ?? 0),
        'activo' => (int) ($_POST['pro_activo'] ?? 1),
        'tienda' => $tiendaIdInput,
        'categoria' => ($_POST['categorias_idcategorias'] ?? '') !== ''
            ? (int) $_POST['categorias_idcategorias']
            : null,
    ]);

    parse_str((string) ($_POST['return_query'] ?? ''), $returnParams);
    $redirectQuery = admin_productos_query(admin_productos_filters($returnParams) + ['saved' => 1]);

    header('Location: admin_productos.php?' . $redirectQuery);
    exit;
}

// LÓGICA DE FILTRADO Y BUSQUEDA
$filters = admin_productos_filters($_GET);
$q = $filters['q'];
$categoriaId = $filters['categoria'];
$estado = $filters['estado'];
$stockFilter = $filters['stock'];
$vista = $filters['vista'];
$orden = $filters['orden'] !== '' ? $filters['orden'] : 'recientes';

$page = $filters['page'];
$perPage = $filters['filas'] ?: AP_FILAS_DEFAULT;
$offset = ($page - 1) * $perPage;

$noPhotoSql = "(p.pro_imagen IS NULL OR p.pro_imagen = '' OR p.pro_imagen LIKE 'data:image%')";

$whereClauses = [];
$params = [];

if ($q !== '') {
    $whereClauses[] = '(p.pro_nombre LIKE :q_nombre OR p.pro_descripcion LIKE :q_descripcion OR p.pro_marca LIKE :q_marca)';
    $like = '%' . $q . '%';
    $params['q_nombre'] = $like;
    $params['q_descripcion'] = $like;
    $params['q_marca'] = $like;
}

// SOLUCIÓN AQUÍ: Forzado estricto de la cláusula WHERE según el rol
if ($isEmpresa) {
    // Si es empresa, ignoramos el GET de la URL y filtramos de forma obligatoria por su ID de tienda
    $whereClauses[] = 'p.tiendas_idtiendas = :tienda';
    $params['tienda'] = $myStoreId;
} else {
    // Si es administrador global, permitimos filtrar por la tienda elegida en el combo (si seleccionó una)
    $tiendaSelect = $filters['tienda'];
    if ($tiendaSelect > 0) {
        $whereClauses[] = 'p.tiendas_idtiendas = :tienda';
        $params['tienda'] = $tiendaSelect;
    }
}

if ($categoriaId > 0) {
    $whereClauses[] = 'p.categorias_idcategorias = :categoria';
    $params['categoria'] = $categoriaId;
}

if ($estado === 'activos') {
    $whereClauses[] = 'p.pro_activo = 1';
} elseif ($estado === 'inactivos') {
    $whereClauses[] = 'p.pro_activo = 0';
}

if ($stockFilter === 'si') {
    $whereClauses[] = 'p.pro_en_stock = 1';
} elseif ($stockFilter === 'no') {
    $whereClauses[] = '(p.pro_en_stock IS NULL OR p.pro_en_stock <> 1)';
}

if ($vista === 'ofertas') {
    $whereClauses[] = 'p.pro_precio_anterior > p.pro_precio';
} elseif ($vista === 'sin_foto') {
    $whereClauses[] = $noPhotoSql;
} elseif ($vista === 'sin_categoria') {
    $whereClauses[] = 'p.categorias_idcategorias IS NULL';
} elseif ($vista === 'sin_precio') {
    $whereClauses[] = '(p.pro_precio IS NULL OR p.pro_precio <= 0)';
}

$orderSql = match ($orden) {
    'precio_asc' => 'p.pro_precio IS NULL, p.pro_precio ASC, p.idproductos DESC',
    'precio_desc' => 'p.pro_precio DESC, p.idproductos DESC',
    'descuento' => '(CASE WHEN p.pro_precio_anterior > p.pro_precio AND p.pro_precio_anterior > 0
                     THEN (p.pro_precio_anterior - p.pro_precio) / p.pro_precio_anterior ELSE 0 END) DESC,
                     p.pro_fecha_scraping DESC',
    'nombre' => 'p.pro_nombre ASC, p.idproductos DESC',
    default => 'p.pro_fecha_scraping DESC, p.idproductos DESC',
};

$whereSql = $whereClauses ? implode(' AND ', $whereClauses) : '1=1';

// Contamos los registros totales bajo las condiciones del rol
$countSql = "SELECT COUNT(*) FROM productos p WHERE {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalProducts / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Consulta de productos
$sql = "
    SELECT
        p.*,
        t.tie_nombre,
        c.cat_nombre
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    if (is_int($value)) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

// Listados auxiliares para combos
$stores = $pdo->query('SELECT idtiendas, tie_nombre FROM tiendas ORDER BY tie_nombre ASC')->fetchAll();
$categories = $pdo->query('SELECT idcategorias, cat_nombre FROM categorias ORDER BY cat_nombre ASC')->fetchAll();

$fromItem = $totalProducts > 0 ? ($offset + 1) : 0;
$toItem = min($offset + $perPage, $totalProducts);

$storeNames = array_column($stores, 'tie_nombre', 'idtiendas');
$categoryNames = array_column($categories, 'cat_nombre', 'idcategorias');

// Resumen del catálogo (por tienda: la propia para empresa, la elegida para el admin)
$scopeStoreId = $isEmpresa ? $myStoreId : $filters['tienda'];
$scopeName = $scopeStoreId > 0 ? ($storeNames[$scopeStoreId] ?? '') : '';
$overviewStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(p.pro_activo = 1), 0) AS activos,
        COALESCE(SUM(p.pro_en_stock = 1), 0) AS en_stock,
        COALESCE(SUM(p.pro_precio_anterior > p.pro_precio), 0) AS ofertas,
        COALESCE(SUM(NOT {$noPhotoSql}), 0) AS con_imagen,
        COALESCE(SUM(p.categorias_idcategorias IS NOT NULL), 0) AS con_categoria,
        COALESCE(SUM(p.pro_precio > 0), 0) AS con_precio,
        COALESCE(SUM(p.categorias_idcategorias IS NOT NULL AND p.pro_precio > 0 AND NOT {$noPhotoSql}), 0) AS completas,
        COUNT(DISTINCT p.categorias_idcategorias) AS categorias,
        COUNT(DISTINCT p.tiendas_idtiendas) AS tiendas,
        AVG(NULLIF(p.pro_precio, 0)) AS precio_medio,
        MAX(p.pro_fecha_scraping) AS ultima
    FROM productos p
    " . ($scopeStoreId > 0 ? 'WHERE p.tiendas_idtiendas = :scope_store' : '') . "
");
$overviewStmt->execute($scopeStoreId > 0 ? ['scope_store' => $scopeStoreId] : []);
$overview = $overviewStmt->fetch();

$catalogTotal = (int) $overview['total'];
$fmt = static fn(int|float $n): string => number_format((float) $n, 0, ',', '.');
$share = static fn(int $part): int => $catalogTotal > 0 ? (int) floor($part / $catalogTotal * 100) : 0;

$completeness = $share((int) $overview['completas']);
$scoreTone = $completeness >= 90 ? 'is-good' : ($completeness >= 60 ? 'is-mid' : 'is-low');
$checks = [
    ['title' => 'Foto', 'icon' => 'image', 'ok' => (int) $overview['con_imagen'], 'vista' => 'sin_foto', 'missing' => 'sin foto'],
    ['title' => 'Categoría', 'icon' => 'folder', 'ok' => (int) $overview['con_categoria'], 'vista' => 'sin_categoria', 'missing' => 'sin categoría'],
    ['title' => 'Precio', 'icon' => 'banknote', 'ok' => (int) $overview['con_precio'], 'vista' => 'sin_precio', 'missing' => 'sin precio'],
];

$lastSyncTs = $overview['ultima'] ? strtotime((string) $overview['ultima']) : false;
$syncHours = $lastSyncTs ? (time() - $lastSyncTs) / 3600 : null;
$syncTone = $syncHours === null ? 'is-low' : ($syncHours <= 24 ? 'is-good' : ($syncHours <= 72 ? 'is-mid' : 'is-low'));
$syncAgo = $lastSyncTs ? ap_time_ago((string) $overview['ultima']) : '';
$syncLabel = $syncAgo === '' ? 'Sin sincronizar' : 'Actualizado ' . (str_starts_with($syncAgo, 'hace') ? $syncAgo : 'el ' . $syncAgo);

$incomplete = max(0, $catalogTotal - (int) $overview['completas']);
$barcodeBars = 36;
$barcodeOn = (int) round($completeness / 100 * $barcodeBars);

if ($isEmpresa) {
    $heroAction = ['href' => 'tienda.php?id=' . $myStoreId, 'icon' => 'external-link', 'label' => 'Ver mi tienda', 'external' => true];
} elseif ($scopeStoreId > 0) {
    $heroAction = ['href' => 'tienda.php?id=' . $scopeStoreId, 'icon' => 'external-link', 'label' => 'Ver tienda', 'external' => true];
} else {
    $heroAction = ['href' => 'admin_scraper.php', 'icon' => 'refresh-cw', 'label' => 'Actualizar precios', 'external' => false];
}

// Composición del catálogo: por tienda (vista global) o por categoría (una sola tienda)
$mixByStore = $scopeStoreId === 0;
if ($mixByStore) {
    $mixRows = $pdo->query("
        SELECT p.tiendas_idtiendas AS id, t.tie_nombre AS nombre, COUNT(*) AS n
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        GROUP BY p.tiendas_idtiendas, t.tie_nombre
        ORDER BY n DESC
    ")->fetchAll();
} else {
    $mixStmt = $pdo->prepare("
        SELECT p.categorias_idcategorias AS id, c.cat_nombre AS nombre, COUNT(*) AS n
        FROM productos p
        INNER JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE p.tiendas_idtiendas = :scope_store
        GROUP BY p.categorias_idcategorias, c.cat_nombre
        ORDER BY n DESC
    ");
    $mixStmt->execute(['scope_store' => $scopeStoreId]);
    $mixRows = $mixStmt->fetchAll();
}

$mixTotal = (int) array_sum(array_column($mixRows, 'n'));
$mixColors = ['var(--brand)', 'var(--accent)', 'var(--success)', 'var(--accent-2)'];
$mixItems = [];
foreach (array_slice($mixRows, 0, count($mixColors)) as $i => $row) {
    $mixItems[] = [
        'label' => (string) $row['nombre'],
        'value' => (int) $row['n'],
        'color' => $mixColors[$i],
        'href' => build_admin_productos_url([($mixByStore ? 'tienda' : 'categoria') => (int) $row['id'], 'page' => 1]),
        'active' => $mixByStore ? $filters['tienda'] === (int) $row['id'] : $categoriaId === (int) $row['id'],
    ];
}
$mixRest = $mixTotal - (int) array_sum(array_column($mixItems, 'value'));
if ($mixRest > 0) {
    $mixItems[] = [
        'label' => $mixByStore ? 'Otras tiendas' : 'Otras categorías',
        'value' => $mixRest,
        'color' => 'color-mix(in srgb, var(--text-soft) 45%, transparent)',
        'href' => null,
        'active' => false,
    ];
}
$mixPct = static fn(int $n): float => $mixTotal > 0 ? round($n / $mixTotal * 100, 1) : 0;

$quickViews = [
    ['label' => 'Todos', 'icon' => 'layers', 'value' => $catalogTotal, 'color' => 'var(--brand)',
     'set' => [], 'active' => $estado === '' && $stockFilter === '' && $vista === ''],
    ['label' => 'Publicados', 'icon' => 'eye', 'value' => (int) $overview['activos'], 'color' => 'var(--success)',
     'set' => ['estado' => 'activos'], 'active' => $estado === 'activos'],
    ['label' => 'Ocultos', 'icon' => 'eye-off', 'value' => $catalogTotal - (int) $overview['activos'], 'color' => 'var(--danger)',
     'set' => ['estado' => 'inactivos'], 'active' => $estado === 'inactivos'],
    ['label' => 'Consultar stock', 'icon' => 'package-x', 'value' => $catalogTotal - (int) $overview['en_stock'], 'color' => 'var(--accent)',
     'set' => ['stock' => 'no'], 'active' => $stockFilter === 'no'],
    ['label' => 'En oferta', 'icon' => 'tag', 'value' => (int) $overview['ofertas'], 'color' => 'var(--accent-2)',
     'set' => ['vista' => 'ofertas'], 'active' => $vista === 'ofertas'],
];

$activeFilters = count(array_filter([
    !$isEmpresa && $filters['tienda'] > 0,
    $categoriaId > 0,
    $estado !== '',
    $stockFilter !== '',
    $vista !== '',
    $orden !== 'recientes',
]));

$selectFields = [];
if (!$isEmpresa) {
    $selectFields[] = ['name' => 'tienda', 'label' => 'Tienda', 'icon' => 'store', 'grow' => 1, 'value' => (string) $filters['tienda'],
                       'default' => '0', 'options' => ['0' => 'Todas'] + $storeNames];
}
$selectFields[] = ['name' => 'categoria', 'label' => 'Categoría', 'icon' => 'folder', 'grow' => 1.25, 'value' => (string) $categoriaId,
                   'default' => '0', 'options' => ['0' => 'Todas'] + $categoryNames];
$selectFields[] = ['name' => 'estado', 'label' => 'Visibilidad', 'icon' => 'eye', 'grow' => 1.05, 'value' => $estado,
                   'default' => '', 'options' => ['' => 'Todas'] + AP_ESTADOS];
$selectFields[] = ['name' => 'stock', 'label' => 'Stock', 'icon' => 'package', 'grow' => 0.9, 'value' => $stockFilter,
                   'default' => '', 'options' => ['' => 'Todo'] + AP_STOCKS];
$selectFields[] = ['name' => 'vista', 'label' => 'Revisión', 'icon' => 'scan-search', 'grow' => 0.95, 'value' => $vista,
                   'default' => '', 'options' => ['' => 'Sin filtro'] + AP_VISTAS];
$selectFields[] = ['name' => 'orden', 'label' => 'Orden', 'icon' => 'arrow-down-up', 'grow' => 1.25, 'value' => $orden,
                   'default' => 'recientes', 'options' => AP_ORDENES];

$pageItems = [];
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1) {
        $pageItems[] = $i;
    } elseif (end($pageItems) !== null) {
        $pageItems[] = null;
    }
}

$returnQuery = admin_productos_query(array_merge($filters, ['page' => $page]));

// Enlaces «Editar» desde el panel: abre el modal aunque el producto no esté en esta página
$editId = (int) ($_GET['edit'] ?? 0);
$modalProducts = $products;
if ($editId > 0 && !in_array($editId, array_map('intval', array_column($products, 'idproductos')), true)) {
    $editStmt = $pdo->prepare("
        SELECT p.*, t.tie_nombre, c.cat_nombre
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE p.idproductos = :id" . ($isEmpresa ? ' AND p.tiendas_idtiendas = :store' : '')
    );
    $editStmt->execute(['id' => $editId] + ($isEmpresa ? ['store' => $myStoreId] : []));
    $editProduct = $editStmt->fetch();
    if ($editProduct) {
        $modalProducts[] = $editProduct;
    } else {
        $editId = 0;
    }
}

$isListPartial = (string) ($_GET['partial'] ?? '') === 'list';

if ($isListPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div data-ap-list-partial>';
    require __DIR__ . '/admin_productos_list.php';
    echo '</div>';
    exit;
}

render_head('Administrar productos');
?>
<link rel="stylesheet" href="./css/admin.css?v=<?= filemtime(__DIR__ . '/css/admin.css') ?>">
<?php render_navbar('admin'); ?>

<?php
$flash = null;
if (($_GET['error'] ?? '') === 'no_autorizado') {
    $flash = ['type' => 'danger', 'icon' => 'shield-alert', 'title' => 'Sin permisos.', 'message' => 'No puedes modificar un producto de otra tienda.'];
} elseif (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'title' => 'Cambios guardados.', 'message' => 'El producto se actualizó correctamente.'];
}
require __DIR__ . '/auth_toast.php';
?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="dash-page ap-page" aria-labelledby="ap-title">
  <div class="container position-relative z-1">
    <nav aria-label="breadcrumb" class="pd-crumb">
      <ol class="breadcrumb custom-breadcrumb pd-crumb-list mb-0">
        <li class="breadcrumb-item">
          <a href="index.php" class="pd-crumb-link">
            <?= lucide_icon('home', '', 14) ?>
            <span>Inicio</span>
          </a>
        </li>
        <li class="breadcrumb-item">
          <a href="admin.php" class="pd-crumb-link">
            <span><?= $isEmpresa ? 'Panel de empresa' : 'Panel' ?></span>
          </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current">Productos</span>
        </li>
      </ol>
    </nav>

    <div class="dash-board">

      <header class="dash-panel ap-hero animate-reveal-up">
        <div class="ap-hero-main">
          <div class="ap-hero-bar">
            <span class="ap-hero-tag">
              <?= lucide_icon('package', '', 14) ?>
              Catálogo
              <span class="ap-hero-role"><?= $isEmpresa ? 'Empresa' : 'Administrador' ?></span>
            </span>
            <span class="ap-sync <?= $syncTone ?>">
              <span class="dash-dot" aria-hidden="true"></span>
              <?= e($syncLabel) ?>
            </span>
          </div>

          <h1 id="ap-title" class="ap-hero-title"><?= $isEmpresa ? 'Mis productos' : 'Gestión de productos' ?></h1>

          <p class="ap-hero-scope">
            <?= lucide_icon('store', '', 15) ?>
            <?php if ($scopeName !== ''): ?>
              <strong><?= e($scopeName) ?></strong>
            <?php else: ?>
              <strong><?= $isEmpresa ? 'Tu tienda' : 'Todas las tiendas' ?></strong>
              <?php if (!$isEmpresa): ?>
                <span><?= $fmt((int) $overview['tiendas']) ?> con productos cargados</span>
              <?php endif; ?>
            <?php endif; ?>
          </p>

          <?php if ($mixItems): ?>
            <section class="ap-mix" aria-labelledby="ap-mix-title">
              <div class="ap-mix-head">
                <h2 id="ap-mix-title" class="ap-mix-title"><?= $mixByStore ? 'Reparto por tienda' : 'Reparto por categoría' ?></h2>
                <span class="ap-mix-hint">Toca para filtrar</span>
              </div>

              <div class="ap-mix-bar" aria-hidden="true">
                <?php foreach ($mixItems as $item): ?>
                  <span style="flex-grow: <?= $item['value'] ?>; --m-c: <?= $item['color'] ?>"></span>
                <?php endforeach; ?>
              </div>

              <div class="ap-mix-carousel" data-ap-carousel>
                <button type="button" class="ap-mix-nav" data-dir="-1" aria-label="Ver anteriores" hidden>
                  <?= lucide_icon('chevron-left', '', 16) ?>
                </button>

                <ul class="ap-mix-legend">
                  <?php foreach ($mixItems as $item): ?>
                    <li>
                      <?php if ($item['href'] !== null): ?>
                        <a
                          href="<?= e($item['href']) ?>"
                          class="ap-mix-item <?= $item['active'] ? 'is-active' : '' ?>"
                          style="--m-c: <?= $item['color'] ?>"
                          <?= $item['active'] ? 'aria-current="true"' : '' ?>
                        >
                      <?php else: ?>
                        <span class="ap-mix-item" style="--m-c: <?= $item['color'] ?>">
                      <?php endif; ?>
                          <span class="ap-mix-swatch" aria-hidden="true"></span>
                          <span class="ap-mix-name"><?= e($item['label']) ?></span>
                          <span class="ap-mix-pct"><?= number_format($mixPct($item['value']), $mixPct($item['value']) < 10 ? 1 : 0, ',', '.') ?>%</span>
                      <?php if ($item['href'] !== null): ?>
                        </a>
                      <?php else: ?>
                        </span>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>

                <button type="button" class="ap-mix-nav" data-dir="1" aria-label="Ver siguientes" hidden>
                  <?= lucide_icon('chevron-right', '', 16) ?>
                </button>
              </div>
            </section>
          <?php endif; ?>

          <div class="ap-hero-foot">
            <dl class="ap-specs">
              <div class="ap-spec">
                <dt>Categorías en uso</dt>
                <dd><?= $fmt((int) $overview['categorias']) ?><small>/<?= $fmt(count($categories)) ?></small></dd>
              </div>
              <div class="ap-spec">
                <dt>Precio promedio</dt>
                <dd><?= $overview['precio_medio'] !== null ? gs(round((float) $overview['precio_medio'])) : '—' ?></dd>
              </div>
            </dl>

            <a
              href="<?= e($heroAction['href']) ?>"
              class="ap-hero-action"
              <?= $heroAction['external'] ? 'target="_blank" rel="noopener"' : '' ?>
            >
              <?= lucide_icon($heroAction['icon'], '', 15) ?>
              <span><?= e($heroAction['label']) ?></span>
            </a>
          </div>
        </div>

        <section class="ap-stub <?= $scoreTone ?>" aria-labelledby="ap-score-title">
          <span class="ap-stub-hole" aria-hidden="true"></span>

          <div class="ap-stub-head">
            <h2 id="ap-score-title" class="ap-stub-title">Fichas completas</h2>
            <p class="ap-stub-value"><?= $completeness ?><small>%</small></p>
          </div>

          <div
            class="ap-barcode"
            role="meter"
            aria-label="Fichas completas"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="<?= $completeness ?>"
          >
            <?php for ($i = 0; $i < $barcodeBars; $i++): ?>
              <span<?= $i < $barcodeOn ? ' class="is-on"' : '' ?>></span>
            <?php endfor; ?>
          </div>

          <p class="ap-stub-note">
            <?php if ($incomplete > 0): ?>
              <strong><?= $fmt($incomplete) ?></strong> <?= $incomplete === 1 ? 'ficha necesita' : 'fichas necesitan' ?> datos para verse mejor en las búsquedas.
            <?php else: ?>
              Todas las fichas tienen foto, categoría y precio.
            <?php endif; ?>
          </p>

          <ul class="ap-gaps">
            <?php foreach ($checks as $check): ?>
              <?php
                $gap = max(0, $catalogTotal - $check['ok']);
                $isActive = $vista === $check['vista'];
              ?>
              <li>
                <?php if ($gap === 0): ?>
                  <span class="ap-gap is-done">
                    <span class="ap-gap-icon" aria-hidden="true"><?= lucide_icon('check', '', 13) ?></span>
                    <span class="ap-gap-name"><?= e($check['title']) ?></span>
                    <span class="ap-gap-count">Completo</span>
                  </span>
                <?php else: ?>
                  <a
                    href="<?= e(build_admin_productos_url(['vista' => $isActive ? '' : $check['vista'], 'estado' => '', 'stock' => '', 'page' => 1])) ?>"
                    class="ap-gap <?= $isActive ? 'is-active' : '' ?>"
                    <?= $isActive ? 'aria-current="true"' : '' ?>
                  >
                    <span class="ap-gap-icon" aria-hidden="true"><?= lucide_icon($check['icon'], '', 13) ?></span>
                    <span class="ap-gap-name"><?= e($check['title']) ?></span>
                    <span class="ap-gap-count"><?= $fmt($gap) ?> <?= e($check['missing']) ?></span>
                    <?= lucide_icon($isActive ? 'x' : 'arrow-right', 'ap-gap-arrow', 13) ?>
                  </a>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      </header>

      <ul class="ap-views animate-reveal-up" aria-label="Vistas rápidas del catálogo">
        <?php foreach ($quickViews as $view): ?>
          <?php $href = build_admin_productos_url(array_merge(['estado' => '', 'stock' => '', 'vista' => '', 'page' => 1], $view['set'])); ?>
          <li>
            <a
              href="<?= e($href) ?>"
              class="ap-view <?= $view['active'] ? 'is-active' : '' ?>"
              style="--v-c: <?= $view['color'] ?>"
              <?= $view['active'] ? 'aria-current="true"' : '' ?>
            >
              <span class="ap-view-top">
                <span class="ap-view-icon" aria-hidden="true"><?= lucide_icon($view['icon'], '', 16) ?></span>
                <span class="ap-view-label" title="<?= e($view['label']) ?>"><?= e($view['label']) ?></span>
              </span>
              <strong class="ap-view-value"><?= $fmt($view['value']) ?></strong>
              <span class="ap-view-share"><?= $share($view['value']) ?>% del catálogo</span>
              <span class="dash-bar is-thin" aria-hidden="true"><span style="width: <?= $share($view['value']) ?>%"></span></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <section class="dash-panel ap-list animate-reveal-up" data-ap-list aria-labelledby="ap-list-title" style="--lane-accent: var(--brand)">
        <header class="dash-panel-head">
          <span class="dash-panel-icon" aria-hidden="true"><?= lucide_icon('list', '', 18) ?></span>
          <div>
            <h2 id="ap-list-title" class="dash-panel-title">Listado de productos</h2>
            <?php $apListParts = ['caption']; require __DIR__ . '/admin_productos_list.php'; ?>
          </div>
          <div class="ap-tools">
            <?php if ($activeFilters): ?>
              <a href="<?= e(admin_productos_url_reset_filters($q)) ?>" class="ap-reset"><?= lucide_icon('rotate-ccw', '', 13) ?>Limpiar filtros</a>
            <?php endif; ?>
            <div class="ap-search" role="search">
              <?= lucide_icon('search', 'ap-search-icon', 16) ?>
              <label for="ap-q" class="visually-hidden">Buscar productos</label>
              <input
                type="search"
                id="ap-q"
                name="q"
                form="ap-filters"
                class="ap-search-input"
                placeholder="Buscar por nombre o marca…"
                value="<?= e($q) ?>"
                autocomplete="off"
              >
              <?php if ($q !== ''): ?>
                <a href="<?= e(build_admin_productos_url(['q' => '', 'page' => 1])) ?>" class="ap-search-clear" title="Borrar búsqueda" aria-label="Borrar búsqueda">
                  <?= lucide_icon('x', '', 14) ?>
                </a>
              <?php endif; ?>
              <button type="submit" form="ap-filters" class="ap-search-go" aria-label="Buscar">
                <?= lucide_icon('arrow-right', '', 15) ?>
              </button>
            </div>
            <label class="ap-rows-select <?= $perPage !== AP_FILAS_DEFAULT ? 'is-set' : '' ?>" title="Filas por página">
              <?= lucide_icon('rows-3', 'ap-rows-select-icon', 15) ?>
              <span class="ap-rows-select-label">Filas</span>
              <span class="ap-rows-select-value" aria-hidden="true"><?= $perPage ?></span>
              <select name="filas" form="ap-filters" class="ap-rows-select-input" aria-label="Filas por página" data-ap-rows>
                <?php foreach (AP_FILAS as $option): ?>
                  <option value="<?= $option ?>" <?= $option === $perPage ? 'selected' : '' ?>><?= $option ?></option>
                <?php endforeach; ?>
              </select>
              <?= lucide_icon('chevron-down', 'ap-rows-select-caret', 13) ?>
            </label>
          </div>
        </header>

        <form method="get" id="ap-filters" class="ap-filters" data-ap-filters aria-label="Filtros del listado">
          <div class="ap-selects" style="--ap-track: <?= e(implode(' ', array_map(fn($f) => 'minmax(0, ' . $f['grow'] . 'fr)', $selectFields))) ?>">
            <?php foreach ($selectFields as $field): ?>
              <?php $isSet = $field['value'] !== $field['default']; ?>
              <label class="ap-select <?= $isSet ? 'is-set' : '' ?>">
                <span class="ap-select-label" aria-hidden="true"><?= lucide_icon($field['icon'], '', 16) ?></span>
                <span class="ap-select-name" aria-hidden="true"><?= e($field['label']) ?></span>
                <select name="<?= e($field['name']) ?>" class="ap-select-input" aria-label="<?= e($field['label']) ?>" title="<?= e($field['label']) ?>">
                  <?php foreach ($field['options'] as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>" <?= (string) $value === $field['value'] ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= lucide_icon('chevron-down', 'ap-select-caret', 14) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </form>

        <?php $apListParts = ['results']; require __DIR__ . '/admin_productos_list.php'; ?>
      </section>

    </div>
  </div>
</section>

<?php $apListParts = ['modals']; require __DIR__ . '/admin_productos_list.php'; ?>

<script>
function initProductEditForms(root) {
  const scope = root && root.querySelectorAll ? root : document;
  scope.querySelectorAll('[data-pe-form]').forEach(function(form) {
    if (form.dataset.peReady === '1') return;
    form.dataset.peReady = '1';

    const field = function(key) { return form.querySelector('[data-pe-sync="' + key + '"]'); };
    const out = function(key) { return form.querySelector('[data-pe-out="' + key + '"]'); };
    const gs = function(n) { return 'Gs. ' + Math.round(n).toLocaleString('es-PY'); };

    const media = form.querySelector('[data-pe-media]');
    const img = form.querySelector('[data-pe-img]');
    const flag = form.querySelector('[data-pe-flag]');
    const note = form.querySelector('[data-pe-note]');
    const noteText = note.querySelector('[data-pe-note-text]');

    img.addEventListener('load', function() { media.classList.remove('is-empty'); });
    img.addEventListener('error', function() { media.classList.add('is-empty'); });
    if (img.getAttribute('src') && img.complete && img.naturalWidth === 0) {
      media.classList.add('is-empty');
    }

    const syncImage = function() {
      const value = field('img').value.trim();
      if (value === '' || value.startsWith('data:image')) {
        img.removeAttribute('src');
        media.classList.add('is-empty');
      } else if (img.getAttribute('src') !== value) {
        img.src = value;
      }
    };

    const syncText = function() {
      const name = field('name').value.trim();
      const brand = field('brand').value.trim();
      const cat = field('cat');
      out('name').textContent = name || 'Sin nombre';
      out('brand').textContent = brand;
      out('brand').hidden = brand === '';
      out('cat').textContent = cat.value ? cat.options[cat.selectedIndex].text : 'Sin categoría';
    };

    const syncPrice = function() {
      const price = parseFloat(field('price').value);
      const old = parseFloat(field('old').value);
      const hasPrice = price > 0;
      const off = hasPrice && old > price ? Math.round((1 - price / old) * 100) : 0;

      out('price').textContent = hasPrice ? gs(price) : 'Sin precio';
      out('old').textContent = off > 0 ? gs(old) : '';
      out('old').hidden = off <= 0;
      flag.textContent = '−' + off + '%';
      flag.hidden = off <= 0;

      let message = '';
      note.classList.remove('is-good', 'is-warn');
      if (off > 0) {
        note.classList.add('is-good');
        message = 'Oferta del ' + off + '%: el usuario ahorra ' + gs(old - price) + '.';
      } else if (hasPrice && old > 0 && old < price) {
        note.classList.add('is-warn');
        message = 'Precio anterior menor: no se muestra como oferta.';
      }
      noteText.textContent = message;
      note.hidden = message === '';
    };

    const links = Array.from(form.querySelectorAll('[data-pe-link]')).map(function(input) {
      return { input: input, open: input.parentElement.querySelector('[data-pe-open]') };
    });
    const syncUrl = function() {
      links.forEach(function(link) {
        const value = link.input.value.trim();
        link.open.hidden = !/^https?:\/\//i.test(value);
        link.open.href = value || '#';
      });
    };

    field('img').addEventListener('input', syncImage);
    ['name', 'brand'].forEach(function(key) { field(key).addEventListener('input', syncText); });
    field('cat').addEventListener('change', syncText);
    ['price', 'old'].forEach(function(key) { field(key).addEventListener('input', syncPrice); });
    links.forEach(function(link) { link.input.addEventListener('input', syncUrl); });
    form.addEventListener('reset', function() {
      setTimeout(function() { syncImage(); syncText(); syncPrice(); syncUrl(); });
    });

    syncPrice();
    syncUrl();
  });

  scope.querySelectorAll('.pe-modal').forEach(function(modal) {
    if (modal.dataset.peModalReady === '1') return;
    modal.dataset.peModalReady = '1';
    modal.addEventListener('hidden.bs.modal', function() {
      const form = modal.querySelector('[data-pe-form]');
      if (form) form.reset();
    });
  });
}

initProductEditForms(document);

document.querySelectorAll('[data-ap-carousel]').forEach(function(carousel) {
  const track = carousel.querySelector('.ap-mix-legend');
  const prev = carousel.querySelector('[data-dir="-1"]');
  const next = carousel.querySelector('[data-dir="1"]');
  const items = Array.from(track.children);

  const update = function() {
    const overflow = track.scrollWidth - track.clientWidth > 1;
    prev.hidden = !overflow;
    next.hidden = !overflow;
    prev.disabled = track.scrollLeft <= 1;
    next.disabled = track.scrollLeft >= track.scrollWidth - track.clientWidth - 1;
  };

  // Avanza hasta la primera etiqueta que no se ve completa, para no dejar ninguna cortada
  const step = function(dir) {
    const view = track.getBoundingClientRect();
    if (dir > 0) {
      const target = items.find(function(li) { return li.getBoundingClientRect().right > view.right + 1; });
      if (target) track.scrollLeft += target.getBoundingClientRect().left - view.left;
    } else {
      const target = items.slice().reverse().find(function(li) { return li.getBoundingClientRect().left < view.left - 1; });
      if (target) track.scrollLeft -= view.right - target.getBoundingClientRect().right;
    }
  };

  prev.addEventListener('click', function() { step(-1); });
  next.addEventListener('click', function() { step(1); });
  track.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  window.addEventListener('load', update);
  if (document.fonts) {
    document.fonts.ready.then(update);
  }
  update();

  const active = track.querySelector('.is-active');
  if (active) {
    const view = track.getBoundingClientRect();
    const box = active.getBoundingClientRect();
    if (box.right > view.right) {
      track.style.scrollBehavior = 'auto';
      track.scrollLeft += box.right - view.right;
      track.style.scrollBehavior = '';
    }
  }
});

(function() {
  let listRequest = 0;

  function listPartialUrl(url) {
    const next = new URL(url, window.location.href);
    next.searchParams.set('partial', 'list');
    next.hash = '';
    return next.toString();
  }

  function publicListUrl(url) {
    const next = new URL(url, window.location.href);
    next.searchParams.delete('partial');
    return next.pathname + next.search + next.hash;
  }

  function refreshListVisuals(root) {
    if (!root) return;
    if (window.refreshLucideIcons) window.refreshLucideIcons();
    if (window.applyProductImageFallback) {
      root.querySelectorAll('img').forEach(function(img) {
        if (!(img.getAttribute('src') || '').trim()) {
          window.applyProductImageFallback(img);
        }
      });
    }
  }

  function loadProductList(url, options) {
    const opts = options || {};
    const list = document.querySelector('[data-ap-list]');
    if (!list) return Promise.resolve();

    const scrollY = window.scrollY;
    const requestId = ++listRequest;
    list.classList.add('is-loading');
    list.setAttribute('aria-busy', 'true');

    return fetch(listPartialUrl(url), {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'text/html'
      }
    })
      .then(function(response) {
        if (!response.ok) throw new Error('No se pudo cargar el listado.');
        return response.text();
      })
      .then(function(html) {
        if (requestId !== listRequest) return;

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const partial = doc.querySelector('[data-ap-list-partial]') || doc.body;

        ['caption', 'results'].forEach(function(slot) {
          const next = partial.querySelector('[data-ap-slot="' + slot + '"]');
          const current = document.querySelector('[data-ap-slot="' + slot + '"]');
          if (!next || !current) return;
          current.replaceWith(document.importNode(next, true));
        });

        const nextModals = partial.querySelector('[data-ap-slot="modals"]');
        let currentModals = document.querySelector('[data-ap-slot="modals"]');
        if (nextModals) {
          const imported = document.importNode(nextModals, true);
          if (currentModals) {
            currentModals.replaceWith(imported);
          } else {
            list.after(imported);
          }
          currentModals = imported;
          initProductEditForms(currentModals);
        }

        const pageRoot = document.querySelector('[data-ap-list]');
        refreshListVisuals(pageRoot);
        if (currentModals) refreshListVisuals(currentModals);

        const publicUrl = publicListUrl(url);
        if (opts.replace) {
          history.replaceState({ adminProductos: true }, '', publicUrl);
        } else {
          history.pushState({ adminProductos: true }, '', publicUrl);
        }

        window.scrollTo(0, scrollY);
      })
      .catch(function() {
        if (requestId !== listRequest) return;
        window.location.href = publicListUrl(url);
      })
      .finally(function() {
        if (requestId !== listRequest) return;
        const current = document.querySelector('[data-ap-list]');
        if (current) {
          current.classList.remove('is-loading');
          current.removeAttribute('aria-busy');
        }
      });
  }

  const FILAS_DEFAULT = '<?= AP_FILAS_DEFAULT ?>';
  const FILTER_DEFAULTS = {
    tienda: '0',
    categoria: '0',
    estado: '',
    stock: '',
    vista: '',
    orden: 'recientes',
    filas: FILAS_DEFAULT
  };

  function isAdminProductosUrl(url) {
    try {
      const next = new URL(url, window.location.href);
      return next.origin === window.location.origin && /\/admin_productos\.php$/i.test(next.pathname);
    } catch (error) {
      return false;
    }
  }

  function buildListUrlFromForm(form) {
    const data = new FormData(form);
    const params = new URLSearchParams();
    data.forEach(function(value, key) {
      const text = String(value);
      if (text === '' || text === '0') return;
      if (key === 'orden' && text === 'recientes') return;
      if (key === 'filas' && text === FILAS_DEFAULT) return;
      if (key === 'page') return;
      params.set(key, text);
    });
    const query = params.toString();
    return 'admin_productos.php' + (query ? '?' + query : '');
  }

  function buildResetFiltersUrl() {
    const params = new URLSearchParams();
    const q = document.getElementById('ap-q');
    const rows = document.querySelector('[data-ap-rows]');
    if (q && q.value.trim() !== '') params.set('q', q.value.trim());
    if (rows && rows.value !== FILAS_DEFAULT) params.set('filas', rows.value);
    const query = params.toString();
    return 'admin_productos.php' + (query ? '?' + query : '');
  }

  function updateSearchClear() {
    const search = document.querySelector('.ap-search');
    const q = document.getElementById('ap-q');
    if (!search || !q) return;

    let clear = search.querySelector('.ap-search-clear');
    const term = q.value.trim();
    if (term !== '') {
      const href = buildListUrlFromForm(document.querySelector('[data-ap-filters]'));
      const next = new URL(href, window.location.href);
      next.searchParams.delete('q');
      if (!clear) {
        clear = document.createElement('a');
        clear.className = 'ap-search-clear';
        clear.title = 'Borrar búsqueda';
        clear.setAttribute('aria-label', 'Borrar búsqueda');
        clear.innerHTML = <?= json_encode(lucide_icon('x', '', 14)) ?>;
        const go = search.querySelector('.ap-search-go');
        search.insertBefore(clear, go || null);
        if (window.refreshLucideIcons) window.refreshLucideIcons();
      }
      clear.href = next.pathname + next.search;
    } else if (clear) {
      clear.remove();
    }
  }

  function updateFilterChrome() {
    const form = document.querySelector('[data-ap-filters]');
    const tools = document.querySelector('.ap-tools');
    if (!form || !tools) return;

    let active = false;
    form.querySelectorAll('select[name]').forEach(function(select) {
      const def = FILTER_DEFAULTS[select.name];
      const isSet = def !== undefined ? select.value !== def : select.value !== '' && select.value !== '0';
      const box = select.closest('.ap-select');
      if (box) box.classList.toggle('is-set', isSet);
      if (isSet) active = true;
    });

    const rows = document.querySelector('[data-ap-rows]');
    if (rows) {
      const box = rows.closest('.ap-rows-select');
      if (box) box.classList.toggle('is-set', rows.value !== FILAS_DEFAULT);
      const value = box && box.querySelector('.ap-rows-select-value');
      if (value) value.textContent = rows.value;
    }

    let reset = tools.querySelector('.ap-reset');
    if (active) {
      if (!reset) {
        reset = document.createElement('a');
        reset.className = 'ap-reset';
        reset.innerHTML = <?= json_encode(lucide_icon('rotate-ccw', '', 13) . 'Limpiar filtros') ?>;
        const search = tools.querySelector('.ap-search');
        tools.insertBefore(reset, search || tools.firstChild);
        if (window.refreshLucideIcons) window.refreshLucideIcons();
      }
      reset.href = buildResetFiltersUrl();
    } else if (reset) {
      reset.remove();
    }

    updateSearchClear();
  }

  function syncFiltersFromUrl(url) {
    const form = document.querySelector('[data-ap-filters]');
    if (!form) return;

    const next = new URL(url, window.location.href);
    const read = function(name, fallback) {
      return next.searchParams.has(name) ? next.searchParams.get(name) : fallback;
    };

    Object.keys(FILTER_DEFAULTS).forEach(function(name) {
      if (name === 'filas') return;
      const select = form.querySelector('select[name="' + name + '"]');
      if (select) select.value = read(name, FILTER_DEFAULTS[name]);
    });

    const rows = document.querySelector('[data-ap-rows]');
    if (rows) rows.value = read('filas', FILAS_DEFAULT);

    const q = document.getElementById('ap-q');
    if (q) q.value = read('q', '');

    updateFilterChrome();
  }

  function navigateProductList(url, options) {
    const opts = options || {};
    return loadProductList(url, opts).then(function() {
      if (opts.syncForm) syncFiltersFromUrl(url);
      else updateFilterChrome();
    });
  }

  document.addEventListener('click', function(event) {
    if (event.defaultPrevented) return;
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const pagerLink = event.target.closest('[data-ap-list] .ap-pager a.ap-page-btn[href]');
    if (pagerLink) {
      if (pagerLink.target === '_blank' || pagerLink.hasAttribute('download')) return;
      if (pagerLink.classList.contains('is-disabled') || pagerLink.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
        return;
      }
      if (!isAdminProductosUrl(pagerLink.href)) return;
      event.preventDefault();
      navigateProductList(pagerLink.href);
      return;
    }

    const filterLink = event.target.closest(
      '.ap-tools .ap-reset[href], .ap-search-clear[href], [data-ap-list] .ap-empty-btn[href]'
    );
    if (filterLink) {
      if (filterLink.target === '_blank' || filterLink.hasAttribute('download')) return;
      if (!isAdminProductosUrl(filterLink.href)) return;
      event.preventDefault();
      navigateProductList(filterLink.href, { syncForm: true });
    }
  });

  window.addEventListener('popstate', function() {
    if (!document.querySelector('[data-ap-list]')) return;
    navigateProductList(window.location.href, { replace: true, syncForm: true });
  });

  const form = document.querySelector('[data-ap-filters]');
  if (form) {
    const fitSelectLabels = function() {
      form.querySelectorAll('.ap-select').forEach(function(box) {
        const label = box.querySelector('.ap-select-label');
        box.style.setProperty('--label-w', (label.offsetLeft + label.offsetWidth + 8) + 'px');
      });
    };
    fitSelectLabels();
    window.addEventListener('resize', fitSelectLabels);
    window.addEventListener('load', fitSelectLabels);
    if (document.fonts) {
      document.fonts.ready.then(fitSelectLabels);
    }

    form.addEventListener('formdata', function(event) {
      Array.from(event.formData.entries()).forEach(function([key, value]) {
        if (value === '' || value === '0' || (key === 'orden' && value === 'recientes') || (key === 'filas' && value === FILAS_DEFAULT)) {
          event.formData.delete(key);
        }
      });
    });

    form.addEventListener('submit', function(event) {
      event.preventDefault();
      const url = buildListUrlFromForm(form);
      updateFilterChrome();
      navigateProductList(url);
    });

    form.addEventListener('change', function(event) {
      if (event.target.matches('select')) {
        updateFilterChrome();
        form.requestSubmit();
      }
    });

    const rowsSelect = document.querySelector('[data-ap-rows]');
    if (rowsSelect) {
      rowsSelect.addEventListener('change', function() {
        updateFilterChrome();
        form.requestSubmit();
      });
    }
  }

  const searchInput = document.getElementById('ap-q');
  if (searchInput) {
    searchInput.closest('.ap-search').addEventListener('click', function(event) {
      if (!event.target.closest('a, button')) {
        searchInput.focus();
      }
    });

    document.addEventListener('keydown', function(event) {
      const typing = event.target.closest('input, textarea, select, [contenteditable="true"]');
      if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        searchInput.focus();
        searchInput.select();
      }
    });
  }

  const editId = <?= (int) $editId ?>;
  if (editId > 0) {
    window.addEventListener('load', function() {
      const modal = document.getElementById('productModal' + editId);
      if (modal && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
      }
    });
  }
})();
</script>

<?php render_footer(); ?>
