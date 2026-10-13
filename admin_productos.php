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

<section class="ad-page ap-page" aria-labelledby="ap-title">
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

    <div class="ad-board">

      <header class="ad-panel ap-hero animate-reveal-up">
        <div class="ap-hero-main">
          <div class="ap-hero-bar">
            <span class="ap-hero-tag">
              <?= lucide_icon('package', '', 14) ?>
              Catálogo
              <span class="ap-hero-role"><?= $isEmpresa ? 'Empresa' : 'Administrador' ?></span>
            </span>
            <span class="ap-sync <?= $syncTone ?>">
              <span class="ad-dot" aria-hidden="true"></span>
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
              <span class="ad-bar is-thin" aria-hidden="true"><span style="width: <?= $share($view['value']) ?>%"></span></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <section class="ad-panel ap-list animate-reveal-up" aria-labelledby="ap-list-title" style="--lane-accent: var(--brand)">
        <header class="ad-panel-head">
          <span class="ad-panel-icon" aria-hidden="true"><?= lucide_icon('list', '', 18) ?></span>
          <div>
            <h2 id="ap-list-title" class="ad-panel-title">Listado de productos</h2>
            <p class="ad-panel-caption">
              <?php if ($totalProducts > 0): ?>
                Mostrando <?= $fmt($fromItem) ?>–<?= $fmt($toItem) ?> de <?= $fmt($totalProducts) ?> · <?= e(AP_ORDENES[$orden]) ?>
              <?php else: ?>
                Ningún producto coincide con la búsqueda
              <?php endif; ?>
            </p>
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

        <?php if ($products): ?>
          <div class="ap-table">
          <div class="ap-cols" aria-hidden="true">
            <span></span>
            <span>Producto</span>
            <span>Precio</span>
            <span>Estado</span>
            <span>Actualizado</span>
            <span>Acciones</span>
          </div>

          <ul class="ap-rows">
            <?php foreach ($products as $product): ?>
              <?php
                $productId = (int) $product['idproductos'];
                $price = ($product['pro_precio'] !== null && (float) $product['pro_precio'] > 0) ? (float) $product['pro_precio'] : null;
                $old = $product['pro_precio_anterior'] !== null ? (float) $product['pro_precio_anterior'] : null;
                $off = ($price !== null && $old !== null && $old > $price) ? (int) round((1 - $price / $old) * 100) : 0;
                $rise = ($price !== null && $old !== null && $old > 0 && $price > $old) ? (int) round(($price / $old - 1) * 100) : 0;
                $isActive = (int) $product['pro_activo'] === 1;
                $inStock = (int) $product['pro_en_stock'] === 1;
                $brand = trim((string) ($product['pro_marca'] ?? ''));
                $category = trim((string) ($product['cat_nombre'] ?? ''));
                $externalUrl = trim((string) ($product['pro_url'] ?? ''));
                $updated = $product['pro_fecha_scraping'] ?? null;
              ?>
              <li class="ap-row <?= $isActive ? '' : 'is-hidden' ?>">
                <span class="ap-thumb">
                  <img
                    src="<?= e(image_url($product['pro_imagen'], $product['pro_nombre'])) ?>"
                    alt=""
                    class="ad-thumb ap-thumb-img"
                    loading="lazy"
                    decoding="async"
                  >
                  <?php if ($off > 0): ?>
                    <span class="ap-thumb-deal">−<?= $off ?>%</span>
                  <?php endif; ?>
                </span>

                <div class="ap-prod">
                  <a href="producto.php?id=<?= $productId ?>" class="ap-name" title="<?= e($product['pro_nombre']) ?>"><?= e($product['pro_nombre']) ?></a>
                  <div class="ap-meta">
                    <span class="ap-meta-id">#<?= $productId ?></span>
                    <span class="ap-meta-item" title="Marca"><?= lucide_icon('tag', '', 12) ?><span><?= e($brand !== '' ? $brand : 'Sin marca') ?></span></span>
                    <?php if (!$isEmpresa): ?>
                      <span class="ap-meta-item" title="Tienda"><?= lucide_icon('store', '', 12) ?><span><?= e($product['tie_nombre']) ?></span></span>
                    <?php endif; ?>
                    <span class="ap-meta-item <?= $category === '' ? 'is-warn' : '' ?>" title="Categoría"><?= lucide_icon('folder', '', 12) ?><span><?= e($category !== '' ? $category : 'Sin categoría') ?></span></span>
                  </div>
                </div>

                <div class="ap-price">
                  <?php if ($price !== null): ?>
                    <strong><?= gs($price) ?></strong>
                  <?php else: ?>
                    <span class="ap-pill is-alt"><?= lucide_icon('banknote-x', '', 12) ?>Sin precio</span>
                  <?php endif; ?>
                  <?php if ($off > 0): ?>
                    <span><s><?= gs($old) ?></s><em class="ad-off">−<?= $off ?>%</em></span>
                  <?php elseif ($rise > 0): ?>
                    <span><s><?= gs($old) ?></s><em class="ad-off is-up">+<?= $rise ?>%</em></span>
                  <?php else: ?>
                    <span class="ap-price-steady">Precio estable</span>
                  <?php endif; ?>
                </div>

                <div class="ap-status">
                  <span class="ap-pill <?= $isActive ? 'is-good' : 'is-bad' ?>">
                    <?= lucide_icon($isActive ? 'eye' : 'eye-off', '', 12) ?><?= $isActive ? 'Publicado' : 'Oculto' ?>
                  </span>
                  <span class="ap-pill <?= $inStock ? 'is-good' : 'is-mid' ?>">
                    <?= lucide_icon($inStock ? 'package-check' : 'package-search', '', 12) ?><?= e(stock_label($product['pro_en_stock'])) ?>
                  </span>
                </div>

                <div class="ap-time">
                  <strong><?= e(ucfirst(ap_time_ago($updated))) ?></strong>
                  <?php if (!empty($updated)): ?>
                    <span><?= e(date('d/m/Y H:i', strtotime((string) $updated))) ?></span>
                  <?php endif; ?>
                </div>

                <div class="ap-actions">
                  <a href="producto.php?id=<?= $productId ?>" class="ad-icon-btn" title="Ver ficha pública" aria-label="Ver ficha de <?= e($product['pro_nombre']) ?>">
                    <?= lucide_icon('eye', '', 15) ?>
                  </a>
                  <?php if ($externalUrl !== ''): ?>
                    <a href="<?= e($externalUrl) ?>" class="ad-icon-btn" target="_blank" rel="noopener" title="Abrir en la web de la tienda" aria-label="Abrir <?= e($product['pro_nombre']) ?> en la web de la tienda">
                      <?= lucide_icon('external-link', '', 15) ?>
                    </a>
                  <?php endif; ?>
                  <button
                    type="button"
                    class="ap-edit"
                    data-bs-toggle="modal"
                    data-bs-target="#productModal<?= $productId ?>"
                    aria-label="Editar <?= e($product['pro_nombre']) ?>"
                  >
                    <?= lucide_icon('square-pen', '', 15) ?><span>Editar</span>
                  </button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          </div>

          <?php if ($totalPages > 1): ?>
            <nav class="ap-pager" aria-label="Paginación de productos">
              <span class="ap-pager-info">Página <strong><?= $fmt($page) ?></strong> de <?= $fmt($totalPages) ?></span>
              <div class="ap-pages">
                <a
                  class="ap-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>"
                  href="<?= e(build_admin_productos_url(['page' => max(1, $page - 1)])) ?>"
                  aria-label="Página anterior"
                  <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                ><?= lucide_icon('chevron-left', '', 15) ?></a>
                <?php foreach ($pageItems as $item): ?>
                  <?php if ($item === null): ?>
                    <span class="ap-page-gap" aria-hidden="true">…</span>
                  <?php else: ?>
                    <a
                      class="ap-page-btn <?= $item === $page ? 'is-current' : '' ?>"
                      href="<?= e(build_admin_productos_url(['page' => $item])) ?>"
                      <?= $item === $page ? 'aria-current="page"' : '' ?>
                    ><?= $fmt($item) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
                <a
                  class="ap-page-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>"
                  href="<?= e(build_admin_productos_url(['page' => min($totalPages, $page + 1)])) ?>"
                  aria-label="Página siguiente"
                  <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                ><?= lucide_icon('chevron-right', '', 15) ?></a>
              </div>
            </nav>
          <?php endif; ?>
        <?php else: ?>
          <div class="ap-empty" role="status">
            <span class="ap-empty-icon"><?= lucide_icon('search-x', '', 20) ?></span>
            <strong class="ap-empty-title">
              <?php if ($q !== ''): ?>
                Sin resultados para <span class="ap-empty-term">“<?= e($q) ?>”</span>
              <?php else: ?>
                No encontramos productos
              <?php endif; ?>
            </strong>
            <p class="ap-empty-text"><?= $activeFilters
              ? 'Revisa la ortografía, prueba con otras palabras o quita algunos filtros para ampliar la búsqueda.'
              : 'Revisa la ortografía o prueba con otras palabras para ampliar la búsqueda.' ?></p>
            <?php if ($activeFilters || $q !== ''): ?>
              <div class="ap-empty-actions">
                <?php if ($activeFilters): ?>
                  <a href="<?= e(admin_productos_url_reset_filters($q)) ?>" class="ap-empty-btn">
                    <?= lucide_icon('rotate-ccw', '', 14) ?>Limpiar filtros
                  </a>
                <?php endif; ?>
                <?php if ($q !== ''): ?>
                  <a href="<?= e(build_admin_productos_url(['q' => '', 'page' => 1])) ?>" class="ap-empty-btn is-ghost">
                    <?= lucide_icon('x', '', 14) ?>Borrar búsqueda
                  </a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>
</section>

<?php if ($modalProducts): ?>
  <?php foreach ($modalProducts as $product): ?>
    <?php
      $mId = (int) $product['idproductos'];
      $mName = (string) $product['pro_nombre'];
      $mBrand = trim((string) ($product['pro_marca'] ?? ''));
      $mCategory = trim((string) ($product['cat_nombre'] ?? ''));
      $mImage = image_url($product['pro_imagen'], $mName);
      $mUrl = trim((string) ($product['pro_url'] ?? ''));
      $mPrice = ($product['pro_precio'] ?? '') !== '' && $product['pro_precio'] !== null ? (string) round((float) $product['pro_precio']) : '';
      $mOld = ($product['pro_precio_anterior'] ?? '') !== '' && $product['pro_precio_anterior'] !== null ? (string) round((float) $product['pro_precio_anterior']) : '';
      $mOff = ($mPrice !== '' && $mOld !== '' && (float) $mOld > (float) $mPrice && (float) $mPrice > 0) ? (int) round((1 - (float) $mPrice / (float) $mOld) * 100) : 0;
      $mActive = (int) $product['pro_activo'] === 1;
      $mStock = (int) $product['pro_en_stock'] === 1;
      $fid = 'pe' . $mId;
    ?>
    <div class="modal fade admin-modal pe-modal" id="productModal<?= $mId ?>" tabindex="-1" aria-labelledby="<?= $fid ?>-title" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="post" class="modal-content pe-content" data-pe-form>
          <input type="hidden" name="action" value="save_product">
          <input type="hidden" name="idproductos" value="<?= $mId ?>">
          <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">

          <div class="modal-header pe-header">
            <div class="pe-heading">
              <span class="pe-heading-icon" aria-hidden="true"><?= lucide_icon('square-pen', '', 18) ?></span>
              <div class="pe-heading-text">
                <span class="pe-kicker">Editar producto <span class="pe-kicker-id">#<?= $mId ?></span></span>
                <h2 class="modal-title pe-title" id="<?= $fid ?>-title" title="<?= e($mName) ?>"><?= e($mName) ?></h2>
              </div>
            </div>
            <button type="button" class="pe-close" data-bs-dismiss="modal" aria-label="Cerrar"><?= lucide_icon('x', '', 18) ?></button>
          </div>

          <div class="modal-body pe-body">
            <div class="pe-layout">
              <div class="pe-main">
                <section class="pe-group is-info">
                  <header class="pe-group-head">
                    <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('file-text', '', 14) ?></span>
                    <div class="pe-group-copy">
                      <h3 class="pe-group-title">Información básica</h3>
                      <p class="pe-group-hint">Lo que ve el usuario en el buscador.</p>
                    </div>
                  </header>
                  <div class="pe-field">
                    <label class="form-label" for="<?= $fid ?>-nombre">Nombre <span class="pe-req" aria-hidden="true">*</span></label>
                    <input type="text" id="<?= $fid ?>-nombre" name="pro_nombre" class="form-control" value="<?= e($mName) ?>" required data-pe-sync="name">
                  </div>
                  <div class="pe-row">
                    <div class="pe-field">
                      <label class="form-label" for="<?= $fid ?>-marca">Marca</label>
                      <input type="text" id="<?= $fid ?>-marca" name="pro_marca" class="form-control" value="<?= e($mBrand) ?>" data-pe-sync="brand">
                    </div>
                    <div class="pe-field">
                      <label class="form-label" for="<?= $fid ?>-cat">Categoría</label>
                      <select id="<?= $fid ?>-cat" name="categorias_idcategorias" class="form-select" data-pe-sync="cat">
                        <option value="">Sin categoría</option>
                        <?php foreach ($categories as $cat): ?>
                          <option value="<?= (int) $cat['idcategorias'] ?>" <?= (int) $product['categorias_idcategorias'] === (int) $cat['idcategorias'] ? 'selected' : '' ?>><?= e($cat['cat_nombre']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="pe-field">
                    <label class="form-label" for="<?= $fid ?>-tienda">Tienda<?php if (!$isEmpresa): ?> <span class="pe-req" aria-hidden="true">*</span><?php endif; ?></label>
                    <?php if ($isEmpresa): ?>
                      <input type="text" id="<?= $fid ?>-tienda" class="form-control" value="<?= e($product['tie_nombre']) ?>" readonly>
                      <input type="hidden" name="tiendas_idtiendas" value="<?= (int) $myStoreId ?>">
                    <?php else: ?>
                      <select id="<?= $fid ?>-tienda" name="tiendas_idtiendas" class="form-select" required>
                        <?php foreach ($stores as $store): ?>
                          <option value="<?= (int) $store['idtiendas'] ?>" <?= (int) $product['tiendas_idtiendas'] === (int) $store['idtiendas'] ? 'selected' : '' ?>><?= e($store['tie_nombre']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>
                  </div>
                  <div class="pe-field is-grow">
                    <label class="form-label" for="<?= $fid ?>-desc">Descripción</label>
                    <textarea id="<?= $fid ?>-desc" name="pro_descripcion" class="form-control" rows="3" placeholder="Características principales del producto"><?= e($product['pro_descripcion']) ?></textarea>
                  </div>
                </section>

                <div class="pe-stack">
                  <section class="pe-group is-price">
                    <header class="pe-group-head">
                      <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('banknote', '', 14) ?></span>
                      <div class="pe-group-copy">
                        <h3 class="pe-group-title">Precio</h3>
                        <p class="pe-group-hint">Si el anterior es mayor, es oferta.</p>
                      </div>
                    </header>
                    <div class="pe-row">
                      <div class="pe-field">
                        <label class="form-label" for="<?= $fid ?>-precio">Precio actual <span class="pe-req" aria-hidden="true">*</span></label>
                        <div class="pe-affix">
                          <span class="pe-affix-tag" aria-hidden="true">Gs.</span>
                          <input type="number" id="<?= $fid ?>-precio" name="pro_precio" class="form-control" value="<?= e($mPrice) ?>" min="0" step="1" inputmode="numeric" placeholder="0" required data-pe-sync="price">
                        </div>
                      </div>
                      <div class="pe-field">
                        <label class="form-label" for="<?= $fid ?>-old">Precio anterior</label>
                        <div class="pe-affix">
                          <span class="pe-affix-tag" aria-hidden="true">Gs.</span>
                          <input type="number" id="<?= $fid ?>-old" name="pro_precio_anterior" class="form-control" value="<?= e($mOld) ?>" min="0" step="1" inputmode="numeric" placeholder="Opcional" title="Si es mayor al precio actual, se muestra como oferta" data-pe-sync="old">
                        </div>
                      </div>
                    </div>
                    <p class="pe-price-note" data-pe-note aria-live="polite" hidden>
                      <?= lucide_icon('info', 'pe-price-note-icon', 15) ?>
                      <span data-pe-note-text></span>
                    </p>
                  </section>

                  <section class="pe-group is-links">
                    <header class="pe-group-head">
                      <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('link', '', 14) ?></span>
                      <div class="pe-group-copy">
                        <h3 class="pe-group-title">Enlaces</h3>
                        <p class="pe-group-hint">Página del producto e imagen.</p>
                      </div>
                    </header>
                    <div class="pe-field">
                      <label class="form-label" for="<?= $fid ?>-url">URL del producto en la tienda</label>
                      <div class="pe-affix">
                        <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('globe', '', 15) ?></span>
                        <input type="url" id="<?= $fid ?>-url" name="pro_url" class="form-control" value="<?= e($mUrl) ?>" placeholder="https://" data-pe-link>
                        <a href="<?= e($mUrl !== '' ? $mUrl : '#') ?>" class="pe-affix-btn" target="_blank" rel="noopener" title="Abrir enlace" aria-label="Abrir enlace del producto en una pestaña nueva" data-pe-open <?= $mUrl === '' ? 'hidden' : '' ?>><?= lucide_icon('external-link', '', 15) ?></a>
                      </div>
                    </div>
                    <div class="pe-field">
                      <label class="form-label" for="<?= $fid ?>-img">URL de la imagen</label>
                      <div class="pe-affix">
                        <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('image', '', 15) ?></span>
                        <input type="text" id="<?= $fid ?>-img" name="pro_imagen" class="form-control" value="<?= e($product['pro_imagen']) ?>" placeholder="https://…/imagen.jpg" data-pe-sync="img" data-pe-link>
                        <a href="<?= e($mImage !== '' ? $mImage : '#') ?>" class="pe-affix-btn" target="_blank" rel="noopener" title="Abrir imagen" aria-label="Abrir imagen en una pestaña nueva" data-pe-open <?= $mImage === '' ? 'hidden' : '' ?>><?= lucide_icon('external-link', '', 15) ?></a>
                      </div>
                    </div>
                  </section>
                </div>
              </div>

              <aside class="pe-aside">
                <span class="pe-aside-label">Vista previa</span>
                  <div class="pe-preview-media <?= $mImage === '' ? 'is-empty' : '' ?>" data-pe-media>
                    <img src="<?= e($mImage) ?>" alt="" data-pe-img>
                    <span class="pe-preview-empty" aria-hidden="true"><?= lucide_icon('image-off', '', 28) ?><span>Sin imagen</span></span>
                    <span class="pe-preview-flag" data-pe-flag <?= $mOff > 0 ? '' : 'hidden' ?>>−<?= $mOff ?>%</span>
                  </div>
                  <div class="pe-preview-body">
                    <span class="pe-preview-tags">
                      <span class="pe-preview-cat" data-pe-out="cat"><?= e($mCategory !== '' ? $mCategory : 'Sin categoría') ?></span>
                      <span class="pe-preview-brand" data-pe-out="brand" <?= $mBrand === '' ? 'hidden' : '' ?>><?= e($mBrand) ?></span>
                    </span>
                    <strong class="pe-preview-name" data-pe-out="name"><?= e($mName) ?></strong>
                    <span class="pe-preview-price">
                      <span data-pe-out="price"><?= $mPrice !== '' && (float) $mPrice > 0 ? gs($mPrice) : 'Sin precio' ?></span>
                      <s data-pe-out="old" <?= $mOff > 0 ? '' : 'hidden' ?>><?= $mOld !== '' ? gs($mOld) : '' ?></s>
                    </span>
                  </div>

                <div class="pe-switches">
                  <label class="pe-switch" for="<?= $fid ?>-activo">
                    <span class="pe-switch-copy">
                      <strong>Publicado</strong>
                      <small>Visible en el buscador</small>
                    </span>
                    <input type="hidden" name="pro_activo" value="0">
                    <input type="checkbox" id="<?= $fid ?>-activo" class="pe-switch-input" name="pro_activo" value="1" role="switch" <?= $mActive ? 'checked' : '' ?>>
                    <span class="pe-switch-track" aria-hidden="true"></span>
                  </label>
                  <label class="pe-switch" for="<?= $fid ?>-stock">
                    <span class="pe-switch-copy">
                      <strong>En stock</strong>
                      <small>Apagado: “Consultar stock”</small>
                    </span>
                    <input type="hidden" name="pro_en_stock" value="0">
                    <input type="checkbox" id="<?= $fid ?>-stock" class="pe-switch-input" name="pro_en_stock" value="1" role="switch" <?= $mStock ? 'checked' : '' ?>>
                    <span class="pe-switch-track" aria-hidden="true"></span>
                  </label>
                </div>

                <a href="producto.php?id=<?= $mId ?>" class="pe-aside-link" target="_blank" rel="noopener">
                  <?= lucide_icon('eye', '', 15) ?><span>Ver ficha pública</span><?= lucide_icon('arrow-up-right', 'pe-aside-link-arrow', 14) ?>
                </a>
              </aside>
            </div>
          </div>

          <div class="modal-footer pe-footer">
            <span class="pe-footer-hint"><span class="pe-req" aria-hidden="true">*</span> Campos obligatorios</span>
            <div class="pe-footer-actions">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary"><?= lucide_icon('check', '', 16) ?>Guardar cambios</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('[data-pe-form]').forEach(function(form) {
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

document.querySelectorAll('.pe-modal').forEach(function(modal) {
  modal.addEventListener('hidden.bs.modal', function() {
    const form = modal.querySelector('[data-pe-form]');
    if (form) form.reset();
  });
});

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
        if (value === '' || value === '0' || (key === 'orden' && value === 'recientes') || (key === 'filas' && value === '<?= AP_FILAS_DEFAULT ?>')) {
          event.formData.delete(key);
        }
      });
    });

    form.addEventListener('change', function(event) {
      if (event.target.matches('select')) {
        form.requestSubmit();
      }
    });

    const rowsSelect = document.querySelector('[data-ap-rows]');
    if (rowsSelect) {
      rowsSelect.addEventListener('change', function() {
        rowsSelect.parentElement.querySelector('.ap-rows-select-value').textContent = rowsSelect.value;
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
