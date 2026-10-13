<?php
require_once __DIR__ . '/config.php';
require_admin_or_empresa();

$pdo = db();
$user = current_user();
$isEmpresa = is_empresa();
$myStoreId = $isEmpresa ? (int)($user['tiendas_idtiendas'] ?? 0) : 0;

$tableExists = static function (string $table) use ($pdo): bool {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
        $stmt->execute([':t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
};

// Filtro reutilizable: la empresa solo ve su tienda
$scopeAnd = $isEmpresa ? ' AND p.tiendas_idtiendas = :store_id' : '';
$scopeParams = $isEmpresa ? [':store_id' => $myStoreId] : [];

$runScoped = static function (string $sql) use ($pdo, $scopeParams): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scopeParams);
    return $stmt;
};

// 1. PENDIENTES SEGÚN EL ROL
if ($isEmpresa) {
    $stmtRepProd = $pdo->prepare("
        SELECT COUNT(*) FROM producto_reportes pr
        INNER JOIN productos p ON pr.productos_idproductos = p.idproductos
        WHERE pr.rep_estado = 'pendiente' AND p.tiendas_idtiendas = :store_id
    ");
    $stmtRepProd->execute(['store_id' => $myStoreId]);
    $pendingProductReports = (int)$stmtRepProd->fetchColumn();

    $stmtRepRev = $pdo->prepare("
        SELECT COUNT(*) FROM tienda_review_reportes trr
        INNER JOIN tienda_reviews tr ON trr.reviews_idreview = tr.idreview
        WHERE trr.rep_estado = 'pendiente' AND tr.tiendas_idtiendas = :store_id
    ");
    $stmtRepRev->execute(['store_id' => $myStoreId]);
    $pendingReviewReports = (int)$stmtRepRev->fetchColumn();

    $stmtNewReviews = $pdo->prepare("
        SELECT COUNT(*) FROM tienda_reviews
        WHERE tiendas_idtiendas = :store_id AND rev_activo = 1 AND rev_fecha >= NOW() - INTERVAL 7 DAY
    ");
    $stmtNewReviews->execute(['store_id' => $myStoreId]);
    $newReviews = (int) $stmtNewReviews->fetchColumn();

    $stmtMyStore = $pdo->prepare('SELECT idtiendas, tie_nombre, tie_logo FROM tiendas WHERE idtiendas = :store_id');
    $stmtMyStore->execute(['store_id' => $myStoreId]);
    $myStore = $stmtMyStore->fetch() ?: null;
} else {
    $pendingReviewReports = (int) $pdo->query("SELECT COUNT(*) FROM tienda_review_reportes WHERE rep_estado = 'pendiente'")->fetchColumn();
    $pendingProductReports = (int) $pdo->query("SELECT COUNT(*) FROM producto_reportes WHERE rep_estado = 'pendiente'")->fetchColumn();
    $pendingSuggestions = (int) $pdo->query("SELECT COUNT(*) FROM sugerencias WHERE sug_estado = 'pendiente'")->fetchColumn();
}

// 2. SEMANA POR HORAS: interacciones de los últimos 7 días, día por día y hora por hora
[$todayDate, $nowHour] = (static function () use ($pdo): array {
    $row = $pdo->query('SELECT CURDATE() AS d, HOUR(NOW()) AS h')->fetch();
    return [(string) $row['d'], (int) $row['h']];
})();

$heat = [];
for ($i = 6; $i >= 0; $i--) {
    $heat[date('Y-m-d', strtotime("$todayDate -$i day"))] = array_fill(0, 24, 0);
}

// Fuentes de interacción: tabla, columna de fecha y cómo se nombra el evento
$sources = [
    'views' => ['productos_vistos', 'visto_en', 'visita'],
    'clicks' => ['producto_clicks', 'click_fecha', $isEmpresa ? 'clic a tu web' : 'clic a tienda'],
    'favs' => ['favoritos', 'fav_fecha', 'favorito'],
];
$week = [];
$prevWeekTotal = 0;
$lastTouch = null;
foreach ($sources as $key => [$table, $col, $label]) {
    if (!$tableExists($table)) {
        continue;
    }
    $week[$key] = 0;
    $stmt = $runScoped("
        SELECT DATE(x.$col) AS d, HOUR(x.$col) AS h, COUNT(*) AS total
        FROM $table x
        INNER JOIN productos p ON p.idproductos = x.productos_idproductos
        WHERE x.$col >= CURDATE() - INTERVAL 6 DAY $scopeAnd
        GROUP BY d, h
    ");
    foreach ($stmt as $row) {
        if (isset($heat[$row['d']])) {
            $heat[$row['d']][(int) $row['h']] += (int) $row['total'];
            $week[$key] += (int) $row['total'];
        }
    }
    $row = $runScoped("
        SELECT MAX(x.$col) AS ultima,
               COALESCE(SUM(x.$col >= CURDATE() - INTERVAL 13 DAY AND x.$col < CURDATE() - INTERVAL 6 DAY), 0) AS previa
        FROM $table x
        INNER JOIN productos p ON p.idproductos = x.productos_idproductos
        WHERE 1 = 1 $scopeAnd
    ")->fetch();
    $prevWeekTotal += (int) $row['previa'];
    if ($row['ultima'] && ($lastTouch === null || $row['ultima'] > $lastTouch['at'])) {
        $lastTouch = ['at' => (string) $row['ultima'], 'label' => $label];
    }
}

$weekTotal = array_sum($week);
$dayTotals = array_map('array_sum', $heat);
$hourTotals = array_map(static fn(int $h): int => array_sum(array_column($heat, $h)), range(0, 23));
$bestDay = $weekTotal > 0 ? array_search(max($dayTotals), $dayTotals, true) : null;
$bestHour = $weekTotal > 0 ? (int) array_search(max($hourTotals), $hourTotals, true) : null;
$weekDiff = $weekTotal - $prevWeekTotal;
$quietDay = $weekTotal > 0 ? array_search(min($dayTotals), $dayTotals, true) : null;
$hourPeak = max(1, max($hourTotals));
$lastAge = $lastTouch ? time() - strtotime($lastTouch['at']) : null;
$liveTone = $lastAge === null ? '' : ($lastAge < 3600 ? 'is-live' : ($lastAge < 86400 ? 'is-recent' : ''));

$catalog = $runScoped("
    SELECT COALESCE(SUM(p.pro_activo = 1 AND p.pro_en_stock = 0), 0) AS sin_stock,
           COALESCE(SUM(p.pro_activo = 0), 0) AS ocultos,
           COALESCE(SUM(p.pro_activo = 1 AND p.pro_fecha_scraping < NOW() - INTERVAL 7 DAY), 0) AS viejos
    FROM productos p
    WHERE 1 = 1 $scopeAnd
")->fetch();

$checks = [
    ['href' => 'admin_productos.php?estado=activos&stock=no', 'icon' => 'package-x', 'title' => 'Sin stock confirmado', 'desc' => 'Figuran como «Consultar stock»', 'count' => (int) $catalog['sin_stock'], 'color' => 'var(--accent)'],
    ['href' => $isEmpresa ? 'admin_productos.php' : 'admin_scraper.php', 'icon' => 'history', 'title' => 'Sin actualizar', 'desc' => 'Precios con más de 7 días', 'count' => (int) $catalog['viejos'], 'color' => 'var(--danger)'],
    ['href' => 'admin_productos.php?estado=inactivos', 'icon' => 'eye-off', 'title' => 'Ocultos', 'desc' => 'No aparecen en el sitio', 'count' => (int) $catalog['ocultos'], 'color' => 'var(--text-soft)'],
];

// 3. ÚLTIMAS PRODUCTOS ACTUALIZADOS
$latestProducts = $runScoped("
    SELECT p.idproductos, p.pro_nombre, p.pro_precio, p.pro_precio_anterior, p.pro_imagen,
           p.pro_en_stock, p.pro_activo, p.pro_marca, p.pro_fecha_scraping, t.tie_nombre, c.cat_nombre
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE 1 = 1 $scopeAnd
    ORDER BY p.pro_fecha_scraping DESC, p.idproductos DESC
    LIMIT 7
")->fetchAll();

// 4. MOVIMIENTO DE LA COMUNIDAD: lo que hicieron las personas, no el scraper
$motives = [
    'precio_incorrecto' => 'Precio incorrecto', 'desactualizado' => 'Falta actualizar', 'sin_stock' => 'Sin stock',
    'producto_equivocado' => 'Producto equivocado', 'informacion_falsa' => 'Información falsa', 'otro' => 'Otro motivo',
    'spam' => 'Spam', 'ofensivo' => 'Ofensivo', 'lenguaje_inapropiado' => 'Lenguaje inapropiado',
];
$storeFilter = $isEmpresa ? ' AND t.idtiendas = :store_id' : '';
$feedLimit = 8;

$community = [];
$collect = static function (string $type, string $sql) use ($runScoped, &$community): void {
    foreach ($runScoped($sql)->fetchAll() as $row) {
        $community[] = ['type' => $type] + $row;
    }
};

$collect('fav', "
    SELECT f.fav_fecha AS fecha, u.usu_nombre AS actor, p.pro_nombre AS objeto, p.idproductos AS ref, NULL AS extra
    FROM favoritos f
    INNER JOIN usuario u ON u.idusuario = f.usuario_idusuario
    INNER JOIN productos p ON p.idproductos = f.productos_idproductos
    WHERE 1 = 1 $scopeAnd
    ORDER BY f.fav_fecha DESC LIMIT $feedLimit
");
$collect('review', "
    SELECT r.rev_fecha AS fecha, r.rev_nombre AS actor, t.tie_nombre AS objeto, t.idtiendas AS ref, r.rev_puntaje AS extra
    FROM tienda_reviews r
    INNER JOIN tiendas t ON t.idtiendas = r.tiendas_idtiendas
    WHERE r.rev_activo = 1 $storeFilter
    ORDER BY r.rev_fecha DESC LIMIT $feedLimit
");
$collect('report', "
    SELECT pr.rep_fecha AS fecha, pr.rep_nombre AS actor, p.pro_nombre AS objeto, p.idproductos AS ref, pr.rep_motivo AS extra
    FROM producto_reportes pr
    INNER JOIN productos p ON p.idproductos = pr.productos_idproductos
    WHERE 1 = 1 $scopeAnd
    ORDER BY pr.rep_fecha DESC LIMIT $feedLimit
");
if (!$isEmpresa) {
    $collect('review_report', "
        SELECT trr.rep_fecha AS fecha, trr.rep_nombre AS actor, t.tie_nombre AS objeto, t.idtiendas AS ref, trr.rep_motivo AS extra
        FROM tienda_review_reportes trr
        INNER JOIN tienda_reviews r ON r.idreview = trr.reviews_idreview
        INNER JOIN tiendas t ON t.idtiendas = r.tiendas_idtiendas
        ORDER BY trr.rep_fecha DESC LIMIT $feedLimit
    ");
    $collect('suggestion', "
        SELECT s.sug_fecha AS fecha, s.sug_nombre AS actor, COALESCE(NULLIF(TRIM(s.sug_asunto), ''), LEFT(s.sug_detalle, 120)) AS objeto, s.idsugerencia AS ref, NULL AS extra
        FROM sugerencias s
        ORDER BY s.sug_fecha DESC LIMIT $feedLimit
    ");
}
usort($community, static fn(array $a, array $b): int => strcmp((string) $b['fecha'], (string) $a['fecha']));
$community = array_slice($community, 0, $feedLimit);

// 5. FORMATO
function ad_time_ago(?string $date): string
{
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

$fmt = static fn(int|float $n): string => number_format((float) $n, 0, ',', '.');
$count = static fn(int $n, string $one, string $many): string => '<strong>' . $fmt($n) . '</strong> ' . ($n === 1 ? $one : $many);

$weekParts = [];
if (isset($week['views'])) {
    $weekParts[] = $count($week['views'], 'visita', 'visitas') . ' a productos';
}
if (isset($week['clicks'])) {
    $weekParts[] = $count($week['clicks'], 'clic', 'clics') . ($isEmpresa ? ' hacia tu web' : ' hacia las tiendas');
}
if (isset($week['favs'])) {
    $weekParts[] = $count($week['favs'], 'producto guardado', 'productos guardados') . ' en favoritos';
}
$weekSummary = count($weekParts) > 1
    ? implode(', ', array_slice($weekParts, 0, -1)) . ' y ' . end($weekParts)
    : implode('', $weekParts);

$dayShort = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
$dayLong = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

$hour = (int) date('G');
$greeting = $hour >= 5 && $hour < 12 ? 'Buenos días' : ($hour >= 12 && $hour < 19 ? 'Buenas tardes' : 'Buenas noches');
$greetIcon = $hour >= 5 && $hour < 12 ? 'sunrise' : ($hour >= 12 && $hour < 19 ? 'sun' : 'moon-star');
$firstName = trim(explode(' ', trim((string) ($user['usu_nombre'] ?? '')))[0] ?? '');

if ($isEmpresa) {
    $storeLink = 'tienda.php?id=' . $myStoreId . '#reviews';
    $pendingTotal = $pendingProductReports + $pendingReviewReports + $newReviews;
    $queue = [
        ['href' => 'admin_productos.php', 'icon' => 'triangle-alert', 'title' => 'Reportes de productos', 'desc' => 'En revisión por el equipo', 'count' => $pendingProductReports, 'color' => 'var(--accent)'],
        ['href' => $storeLink, 'icon' => 'flag', 'title' => 'Reseñas reportadas', 'desc' => 'Opiniones en revisión', 'count' => $pendingReviewReports, 'color' => 'var(--danger)'],
        ['href' => $storeLink, 'icon' => 'message-square-text', 'title' => 'Reseñas nuevas', 'desc' => 'Recibidas en los últimos 7 días', 'count' => $newReviews, 'color' => 'var(--brand)', 'unit' => 'nuevas'],
    ];
    $tools = [
        ['href' => 'admin_productos.php', 'icon' => 'package', 'title' => 'Productos', 'desc' => 'Precios, stock e imágenes', 'color' => 'var(--brand)'],
        ['href' => 'analytics.php', 'icon' => 'chart-column', 'title' => 'Analíticas', 'desc' => 'Clics, favoritos y tendencias', 'color' => 'var(--success)'],
        ['href' => 'tienda.php?id=' . $myStoreId, 'icon' => 'store', 'title' => 'Mi tienda', 'desc' => 'Tu página pública', 'color' => 'var(--accent)'],
        ['href' => 'index.php', 'icon' => 'globe', 'title' => 'Ver sitio', 'desc' => 'Como lo ven tus clientes', 'color' => 'var(--accent-2)'],
    ];
} else {
    $pendingTotal = $pendingProductReports + $pendingReviewReports + $pendingSuggestions;
    $queue = [
        ['href' => 'admin_reviews.php', 'icon' => 'flag', 'title' => 'Reseñas reportadas', 'desc' => 'Opiniones señaladas por usuarios', 'count' => $pendingReviewReports, 'color' => 'var(--danger)'],
        ['href' => 'admin_reportes_productos.php', 'icon' => 'triangle-alert', 'title' => 'Reportes de productos', 'desc' => 'Precios o datos incorrectos', 'count' => $pendingProductReports, 'color' => 'var(--accent)'],
        ['href' => 'admin_sugerencias.php', 'icon' => 'lightbulb', 'title' => 'Sugerencias', 'desc' => 'Ideas y feedback sin leer', 'count' => $pendingSuggestions, 'color' => 'var(--brand)'],
    ];
    $tools = [
        ['href' => 'admin_productos.php', 'icon' => 'package', 'title' => 'Productos', 'desc' => 'Catálogo y precios', 'color' => 'var(--brand)'],
        ['href' => 'admin_tiendas.php', 'icon' => 'store', 'title' => 'Tiendas', 'desc' => 'Datos y contacto', 'color' => 'var(--accent)'],
        ['href' => 'admin_usuarios.php', 'icon' => 'users', 'title' => 'Usuarios', 'desc' => 'Roles y accesos', 'color' => 'var(--success)'],
        ['href' => 'admin_scraper.php', 'icon' => 'terminal', 'title' => 'Importar', 'desc' => 'Ejecutar scrapers', 'color' => 'var(--accent-2)'],
        ['href' => 'analytics.php', 'icon' => 'chart-column', 'title' => 'Analíticas', 'desc' => 'Métricas de uso', 'color' => 'var(--brand-2)'],
        ['href' => 'index.php', 'icon' => 'globe', 'title' => 'Ver sitio', 'desc' => 'Vista pública', 'color' => 'var(--danger)'],
    ];
}

$communityTypes = [
    'fav' => ['icon' => 'heart', 'color' => 'var(--accent-2)'],
    'review' => ['icon' => 'star', 'color' => 'var(--accent)'],
    'report' => ['icon' => 'triangle-alert', 'color' => 'var(--danger)'],
    'review_report' => ['icon' => 'flag', 'color' => 'var(--danger)'],
    'suggestion' => ['icon' => 'lightbulb', 'color' => 'var(--brand)'],
];

render_head('Panel de Administración');
?>
<link rel="stylesheet" href="./css/admin.css?v=<?= filemtime(__DIR__ . '/css/admin.css') ?>">
<?php render_navbar('admin'); ?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="ad-page" aria-labelledby="ad-title">
  <div class="container position-relative z-1">
    <nav aria-label="breadcrumb" class="pd-crumb">
      <ol class="breadcrumb custom-breadcrumb pd-crumb-list mb-0">
        <li class="breadcrumb-item">
          <a href="index.php" class="pd-crumb-link">
            <?= lucide_icon('home', '', 14) ?>
            <span>Inicio</span>
          </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current"><?= $isEmpresa ? 'Panel de empresa' : 'Panel' ?></span>
        </li>
      </ol>
    </nav>

    <div class="ad-board">

      <header class="ad-panel ad-intro animate-reveal-up">
        <div class="ad-intro-main">
          <div class="ad-intro-copy">
            <div class="ad-intro-bar">
              <span class="ad-intro-tag">
                <?= lucide_icon($greetIcon, '', 14) ?>
                <?= e($greeting . ($firstName !== '' ? ', ' . $firstName : '')) ?>
                <span class="ad-intro-role"><?= $isEmpresa ? 'Empresa' : 'Administrador' ?></span>
              </span>
              <span class="ad-live <?= $liveTone ?>"<?php if ($lastTouch): ?> title="<?= e(date('d/m/Y H:i', strtotime($lastTouch['at']))) ?>"<?php endif; ?>>
                <span class="ad-dot" aria-hidden="true"></span>
                <?= $lastTouch ? 'Última ' . e($lastTouch['label']) . ' ' . e(ad_time_ago($lastTouch['at'])) : 'Sin interacciones registradas' ?>
              </span>
          </div>

          <div class="ad-id">
            <?php if ($isEmpresa && $myStore): ?>
              <img src="<?= e(image_url($myStore['tie_logo'], $myStore['tie_nombre'])) ?>" alt="" class="ad-id-logo">
            <?php endif; ?>
            <h1 id="ad-title" class="ad-title">
              <?= $isEmpresa ? e($myStore['tie_nombre'] ?? 'Panel de empresa') : 'Panel de control' ?>
            </h1>
          </div>

          <p class="ad-lead">
            <?php if ($weekTotal > 0): ?>
              En los últimos 7 días <?= $isEmpresa ? 'tus productos recibieron' : 'el sitio recibió' ?> <?= $weekSummary ?>.
              <?php if ($prevWeekTotal > 0): ?>
                En total,
                <span class="ad-trend <?= $weekDiff > 0 ? 'is-up' : ($weekDiff < 0 ? 'is-down' : '') ?>" title="Semana anterior: <?= $fmt($prevWeekTotal) ?> interacciones"><?= lucide_icon($weekDiff > 0 ? 'trending-up' : ($weekDiff < 0 ? 'trending-down' : 'equal'), '', 13) ?><?= $weekDiff === 0 ? 'igual' : $fmt(abs($weekDiff)) . ($weekDiff > 0 ? ' más' : ' menos') ?></span> que la semana anterior.
              <?php endif; ?>
            <?php else: ?>
              En los últimos 7 días todavía no hubo visitas, clics ni favoritos.
            <?php endif; ?>
          </p>
          </div>

          <section class="ad-checks" aria-label="Para revisar en el catálogo">
            <ul class="ad-check-list">
              <?php foreach ($checks as $check): ?>
                <li>
                  <a href="<?= e($check['href']) ?>" class="ad-check <?= $check['count'] === 0 ? 'is-clear' : '' ?>" style="--c-c: <?= $check['color'] ?>">
                    <span class="ad-check-top">
                      <span class="ad-check-icon" aria-hidden="true"><?= lucide_icon($check['count'] === 0 ? 'check' : $check['icon'], '', 15) ?></span>
                      <span class="ad-check-count"><?= $fmt($check['count']) ?></span>
                    </span>
                    <strong class="ad-check-title"><?= e($check['title']) ?></strong>
                    <small class="ad-check-desc"><?= e($check['desc']) ?></small>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        </div>

        <aside class="ad-peak" aria-labelledby="ad-peak-title">
          <div class="ad-peak-top">
            <div class="ad-peak-head">
              <span id="ad-peak-title" class="ad-peak-label"><?= lucide_icon('alarm-clock', '', 14) ?>Hora pico de la semana</span>
              <p class="ad-peak-value">
                <?php if ($bestHour !== null): ?>
                  <?= sprintf('%02d', $bestHour) ?><small>:00 h</small>
                <?php else: ?>
                  —
                <?php endif; ?>
              </p>
          </div>

          <div class="ad-peak-bars" role="img" aria-label="Interacciones acumuladas por hora del día">
            <?php foreach ($hourTotals as $h => $value): ?>
              <span class="<?= $h === $bestHour ? 'is-peak' : '' ?><?= $value === 0 ? ' is-zero' : '' ?>" style="--h: <?= $value > 0 ? max(12, (int) round($value / $hourPeak * 100)) : 6 ?>%" title="<?= e(sprintf('%02d:00 · %s %s', $h, $fmt($value), $value === 1 ? 'interacción' : 'interacciones')) ?>"></span>
            <?php endforeach; ?>
          </div>
          <div class="ad-peak-axis" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
          </div>

          <div class="ad-peak-foot">
            <ul class="ad-peak-legend">
              <li style="--l-c: var(--success)">
                <span><i class="ad-peak-ico" aria-hidden="true"><?= lucide_icon('calendar-check', '', 13) ?></i>Mejor día</span>
                <strong><?= $bestDay !== null ? ($bestDay === $todayDate ? 'Hoy' : e(ucfirst($dayLong[(int) date('w', strtotime($bestDay))]))) : '—' ?></strong>
                <small><?= $bestDay !== null ? $fmt($dayTotals[$bestDay]) : '0' ?></small>
              </li>
              <li style="--l-c: var(--accent-2)">
                <span><i class="ad-peak-ico" aria-hidden="true"><?= lucide_icon('moon', '', 13) ?></i>Día más tranquilo</span>
                <strong><?= $quietDay !== null ? ($quietDay === $todayDate ? 'Hoy' : e(ucfirst($dayLong[(int) date('w', strtotime($quietDay))]))) : '—' ?></strong>
                <small><?= $quietDay !== null ? $fmt($dayTotals[$quietDay]) : '0' ?></small>
              </li>
            </ul>

            <p class="ad-peak-note">
              <?= lucide_icon('lightbulb', '', 15) ?>
              <?php if ($bestHour === null): ?>
                <span>Sin datos para sugerir un horario</span>
              <?php else: ?>
                <span><?= $isEmpresa ? 'Publica tus ofertas' : 'Importa precios' ?> antes de las <strong><?= sprintf('%02d', $bestHour) ?> h</strong></span>
              <?php endif; ?>
            </p>
          </div>
        </aside>
      </header>

      <div class="ad-grid">

        <section class="ad-panel ad-people animate-reveal-up" aria-labelledby="ad-people-title" style="--lane-accent: var(--success)">
          <header class="ad-panel-head">
            <span class="ad-panel-icon" aria-hidden="true"><?= lucide_icon('users-round', '', 18) ?></span>
            <div>
              <h2 id="ad-people-title" class="ad-panel-title"><?= $isEmpresa ? 'Tus clientes' : 'Movimiento de la comunidad' ?></h2>
              <p class="ad-panel-caption"><?= $isEmpresa ? 'Favoritos, reseñas y reportes sobre tu tienda' : 'Favoritos, reseñas, reportes y sugerencias' ?></p>
            </div>
          </header>

          <?php if ($community): ?>
            <ol class="ad-trail" style="--trail-rows: <?= $feedLimit ?>">
              <?php foreach ($community as $ev): ?>
                <?php
                  $type = $communityTypes[$ev['type']];
                  $actor = trim((string) ($ev['actor'] ?? ''));
                  $actor = $actor !== '' ? explode(' ', $actor)[0] : 'Alguien';
                  $object = trim((string) $ev['objeto']);
                  $motive = $motives[$ev['extra'] ?? ''] ?? null;
                  [$verb, $href] = match ($ev['type']) {
                      'fav' => ['guardó en favoritos', 'producto.php?id=' . (int) $ev['ref']],
                      'review' => ['calificó la tienda', 'tienda.php?id=' . (int) $ev['ref'] . '#reviews'],
                      'report' => ['reportó un producto', $isEmpresa ? 'producto.php?id=' . (int) $ev['ref'] : 'admin_reportes_productos.php?q=' . urlencode($object)],
                      'review_report' => ['reportó una reseña de', 'admin_reviews.php'],
                      'suggestion' => ['envió una sugerencia', 'admin_sugerencias.php'],
                  };
                  if ($isEmpresa && $ev['type'] !== 'review') {
                      $actor = $ev['type'] === 'fav' ? 'Un cliente' : 'Un usuario';
                  }
                ?>
                <li>
                  <a href="<?= e($href) ?>" class="ad-trail-row" style="--e-c: <?= $type['color'] ?>">
                    <span class="ad-trail-icon" aria-hidden="true"><?= lucide_icon($type['icon'], '', 14) ?></span>
                    <span class="ad-trail-copy">
                      <span class="ad-trail-line"><strong><?= e($actor) ?></strong> <?= e($verb) ?></span>
                      <span class="ad-trail-object" title="<?= e($object) ?>"><?= e($object) ?></span>
                    </span>
                    <span class="ad-trail-side">
                      <?php if ($ev['type'] === 'review'): ?>
                        <span class="ad-trail-tag is-stars"><?= lucide_icon('star', '', 11) ?><?= (int) $ev['extra'] ?></span>
                      <?php elseif ($motive !== null): ?>
                        <span class="ad-trail-tag"><?= e($motive) ?></span>
                      <?php endif; ?>
                      <time datetime="<?= e(date('c', strtotime((string) $ev['fecha']))) ?>" title="<?= e(date('d/m/Y H:i', strtotime((string) $ev['fecha']))) ?>"><?= e(ad_time_ago($ev['fecha'])) ?></time>
                    </span>
                    <?= lucide_icon('chevron-right', 'ad-trail-arrow', 15) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php else: ?>
            <div class="ad-empty">
              <?= lucide_icon('users-round', '', 22) ?>
              <strong>Todavía sin movimiento</strong>
              <span>Cuando alguien guarde, califique o reporte, lo vas a ver aquí.</span>
            </div>
          <?php endif; ?>
        </section>

        <section class="ad-panel ad-side ad-queue animate-reveal-up" aria-labelledby="ad-queue-title" style="--lane-accent: var(--danger)">
          <header class="ad-panel-head">
            <span class="ad-panel-icon" aria-hidden="true"><?= lucide_icon('inbox', '', 18) ?></span>
            <div>
              <h2 id="ad-queue-title" class="ad-panel-title"><?= $isEmpresa ? 'Avisos de tu tienda' : 'Bandeja de moderación' ?></h2>
              <p class="ad-panel-caption">
                <?php if ($pendingTotal > 0): ?>
                  <?= $isEmpresa ? 'Novedades que conviene mirar' : 'Elementos esperando tu revisión' ?>
                <?php else: ?>
                  <?= $isEmpresa ? 'Sin novedades por ahora' : 'Todo al día, no hay pendientes' ?>
                <?php endif; ?>
              </p>
            </div>
            <strong class="ad-queue-total"><?= $fmt($pendingTotal) ?></strong>
          </header>

          <div class="ad-stack" aria-hidden="true">
            <?php if ($pendingTotal > 0): ?>
              <?php foreach ($queue as $q): ?>
                <?php if ($q['count'] > 0): ?>
                  <span style="flex-grow: <?= (int) $q['count'] ?>; background: <?= $q['color'] ?>"></span>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="is-clear"></span>
            <?php endif; ?>
          </div>

          <ul class="ad-queue-list">
            <?php foreach ($queue as $q): ?>
              <li>
                <a href="<?= e($q['href']) ?>" class="ad-queue-item" style="--q-c: <?= $q['color'] ?>">
                  <span class="ad-queue-icon" aria-hidden="true"><?= lucide_icon($q['icon'], '', 16) ?></span>
                  <span class="ad-queue-copy">
                    <strong><?= e($q['title']) ?></strong>
                    <span><?= e($q['desc']) ?></span>
                  </span>
                  <span class="ad-count <?= $q['count'] === 0 ? 'is-zero' : '' ?>">
                    <?= $fmt($q['count']) ?><span class="visually-hidden"> <?= $q['unit'] ?? 'pendientes' ?></span>
                  </span>
                  <?= lucide_icon('chevron-right', 'ad-queue-arrow', 16) ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>

        <nav class="ad-panel ad-side ad-launch animate-reveal-up" aria-labelledby="ad-launch-title" style="--lane-accent: var(--accent)">
          <header class="ad-panel-head">
            <span class="ad-panel-icon" aria-hidden="true"><?= lucide_icon('zap', '', 18) ?></span>
            <div>
              <h2 id="ad-launch-title" class="ad-panel-title">Accesos rápidos</h2>
              <p class="ad-panel-caption">Herramientas de gestión</p>
            </div>
          </header>
          <ul class="ad-tools <?= count($tools) <= 4 ? 'is-compact' : '' ?>">
            <?php foreach ($tools as $tool): ?>
              <li>
                <a href="<?= e($tool['href']) ?>" class="ad-tool" style="--t-c: <?= $tool['color'] ?>">
                  <span class="ad-tool-icon" aria-hidden="true"><?= lucide_icon($tool['icon'], '', 17) ?></span>
                  <span class="ad-tool-copy">
                    <strong><?= e($tool['title']) ?></strong>
                    <span><?= e($tool['desc']) ?></span>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </nav>

        <section class="ad-panel ad-activity animate-reveal-up" aria-labelledby="ad-activity-title" style="--lane-accent: var(--brand)">
          <header class="ad-panel-head">
            <span class="ad-panel-icon" aria-hidden="true"><?= lucide_icon('activity', '', 18) ?></span>
            <div>
              <h2 id="ad-activity-title" class="ad-panel-title">Actividad reciente</h2>
              <p class="ad-panel-caption"><?= $isEmpresa ? 'Tus productos actualizados por última vez' : 'Últimos productos actualizados en el catálogo' ?></p>
            </div>
            <a href="admin_productos.php" class="ad-panel-link">Ver todos<?= lucide_icon('arrow-right', '', 14) ?></a>
          </header>

          <?php if ($latestProducts): ?>
            <ul class="ad-feed <?= $isEmpresa ? 'is-own' : '' ?>">
              <?php foreach ($latestProducts as $item): ?>
                <?php
                  $price = $item['pro_precio'] !== null ? (float) $item['pro_precio'] : null;
                  $old = $item['pro_precio_anterior'] !== null ? (float) $item['pro_precio_anterior'] : null;
                  $off = ($price !== null && $old !== null && $old > $price && $old > 0) ? (int) round((1 - $price / $old) * 100) : 0;
                  $rise = ($price !== null && $old !== null && $old > 0 && $price > $old) ? (int) round(($price / $old - 1) * 100) : 0;
                  $inStock = (int) $item['pro_en_stock'] === 1;
                  $isHidden = (int) $item['pro_activo'] !== 1;
                  $brand = trim((string) ($item['pro_marca'] ?? ''));
                  $category = trim((string) ($item['cat_nombre'] ?? ''));
                  $updatedTs = strtotime((string) $item['pro_fecha_scraping']);
                ?>
                <li class="ad-feed-row">
                  <img src="<?= e(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>" alt="" class="ad-thumb" loading="lazy" decoding="async">
                  <div class="ad-feed-main">
                    <a href="producto.php?id=<?= (int) $item['idproductos'] ?>" class="ad-feed-name" title="<?= e($item['pro_nombre']) ?>"><?= e($item['pro_nombre']) ?></a>
                    <ul class="ad-feed-meta">
                      <li title="Categoría"><?= lucide_icon('folder', '', 12) ?><span><?= $category !== '' ? e($category) : 'Sin categoría' ?></span></li>
                      <?php if ($brand !== '' && mb_strtolower($brand) !== 'genérico' && mb_strtolower($brand) !== 'generico'): ?>
                        <li title="Marca"><?= lucide_icon('tag', '', 12) ?><span><?= e($brand) ?></span></li>
                      <?php endif; ?>
                    </ul>
                  </div>
                  <div class="ad-feed-facts">
                    <?php if (!$isEmpresa): ?>
                      <div class="ad-feed-field is-store">
                        <small>Tienda</small>
                        <span title="<?= e($item['tie_nombre']) ?>"><?= lucide_icon('store', '', 13) ?><span><?= e($item['tie_nombre']) ?></span></span>
                      </div>
                    <?php endif; ?>
                    <div class="ad-feed-field">
                      <small>Disponibilidad</small>
                      <?php if ($isHidden): ?>
                        <span class="ad-pill is-warn"><?= lucide_icon('eye-off', '', 12) ?>Oculto</span>
                      <?php else: ?>
                        <span class="ad-pill <?= $inStock ? 'is-in' : '' ?>"><span class="ad-stock <?= $inStock ? 'is-in' : '' ?>" aria-hidden="true"></span><?= e(stock_label($item['pro_en_stock'])) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="ad-feed-field">
                      <small>Actualizado</small>
                      <?php if ($updatedTs): ?>
                        <time datetime="<?= e(date('c', $updatedTs)) ?>" title="<?= e(date('d/m/Y H:i', $updatedTs)) ?>"><?= lucide_icon('clock-3', '', 13) ?><span><?= e(ucfirst(ad_time_ago($item['pro_fecha_scraping']))) ?> <small><?= date('d/m', $updatedTs) ?></small></span></time>
                      <?php else: ?>
                        <span><?= lucide_icon('clock-3', '', 13) ?><span>Sin fecha</span></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="ad-feed-price">
                    <strong><?= gs($item['pro_precio']) ?></strong>
                    <?php if ($off > 0): ?>
                      <span class="ad-feed-change is-down" title="Antes: <?= e(gs($old)) ?>"><?= lucide_icon('arrow-down', '', 11) ?>Bajó <?= gs($old - $price) ?></span>
                    <?php elseif ($rise > 0): ?>
                      <span class="ad-feed-change is-up" title="Antes: <?= e(gs($old)) ?>"><?= lucide_icon('arrow-up', '', 11) ?>Subió <?= gs($price - $old) ?></span>
                    <?php elseif ($price === null): ?>
                      <span class="ad-feed-change">Sin precio publicado</span>
                    <?php else: ?>
                      <span class="ad-feed-change">Sin cambio de precio</span>
                    <?php endif; ?>
                  </div>
                  <a href="admin_productos.php?edit=<?= (int) $item['idproductos'] ?>" class="ad-icon-btn" title="Editar producto" aria-label="Editar <?= e($item['pro_nombre']) ?>">
                    <?= lucide_icon('square-pen', '', 15) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="ad-empty">
              <?= lucide_icon('package-open', '', 22) ?>
              <strong>Sin productos todavía</strong>
              <span><?= $isEmpresa ? 'Cuando cargues productos, vas a ver aquí sus últimas actualizaciones.' : 'Importa datos para empezar a poblar el catálogo.' ?></span>
            </div>
          <?php endif; ?>
        </section>

      </div>
    </div>
  </div>
</section>
<?php render_footer(); ?>
