<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

const RP_ESTADOS = [
    'pendiente' => ['label' => 'Por revisar', 'single' => 'Pendiente', 'icon' => 'clock', 'tone' => 'var(--accent)', 'hint' => 'Esperan verificación'],
    'revisado' => ['label' => 'Revisados', 'single' => 'Revisado', 'icon' => 'circle-check', 'tone' => 'var(--success)', 'hint' => 'Confirmados y atendidos'],
    'descartado' => ['label' => 'Descartados', 'single' => 'Descartado', 'icon' => 'circle-x', 'tone' => 'var(--text-soft)', 'hint' => 'Sin fundamento'],
    'todos' => ['label' => 'Todos', 'single' => 'Todos', 'icon' => 'list', 'tone' => 'var(--brand)', 'hint' => 'Historial completo'],
];
const RP_MOTIVOS = [
    'precio_incorrecto' => ['label' => 'Precio incorrecto', 'icon' => 'circle-dollar-sign', 'tone' => 'var(--danger)'],
    'desactualizado' => ['label' => 'Falta actualizar', 'icon' => 'history', 'tone' => 'var(--accent)'],
    'sin_stock' => ['label' => 'Sin stock', 'icon' => 'package-x', 'tone' => 'var(--accent-2)'],
    'producto_equivocado' => ['label' => 'Producto equivocado', 'icon' => 'replace', 'tone' => 'var(--brand)'],
    'informacion_falsa' => ['label' => 'Información falsa', 'icon' => 'badge-alert', 'tone' => 'var(--brand-2)'],
    'otro' => ['label' => 'Otro motivo', 'icon' => 'circle-help', 'tone' => 'var(--text-soft)'],
];
const RP_CASO_ICONOS = [
    'pendiente' => 'clock-alert',
    'revisado' => 'badge-check',
    'descartado' => 'circle-slash',
];
const RP_ORDENES = [
    'mas_reportadas' => ['label' => 'Más reportados', 'icon' => 'arrow-down-wide-narrow'],
    'mas_recientes' => ['label' => 'Recientes', 'icon' => 'clock'],
    'menos_reportadas' => ['label' => 'Menos reportados', 'icon' => 'arrow-up-narrow-wide'],
    'tienda_asc' => ['label' => 'Tienda', 'icon' => 'arrow-down-a-z'],
    'estado' => ['label' => 'Estado', 'icon' => 'list-checks'],
];
const RP_MINIMOS = [1, 2, 3, 5, 10];
const RP_AVISOS = [
    'mark_reviewed' => 'Alerta marcada como revisada.',
    'mark_discarded' => 'Alerta descartada.',
    'mark_all_reviewed' => 'Todas las alertas del producto quedaron revisadas.',
    'mark_all_discarded' => 'Se descartaron todas las alertas del producto.',
    'hide_product' => 'El producto se retiró del catálogo y sus alertas quedaron revisadas.',
    'restore_product' => 'El producto vuelve a mostrarse en el catálogo.',
];

$status = array_key_exists((string) ($_GET['estado'] ?? ''), RP_ESTADOS) ? (string) $_GET['estado'] : 'pendiente';
$q = trim((string) ($_GET['q'] ?? ''));
$minReports = max(1, (int) ($_GET['min_reportes'] ?? 1));
$order = array_key_exists((string) ($_GET['orden'] ?? ''), RP_ORDENES) ? (string) $_GET['orden'] : 'mas_reportadas';
$motivo = array_key_exists((string) ($_GET['motivo'] ?? ''), RP_MOTIVOS) ? (string) $_GET['motivo'] : '';
$refreshedOnly = ($_GET['actualizados'] ?? '') === '1';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;
$state = [
    'estado' => $status,
    'q' => $q,
    'min_reportes' => $minReports,
    'orden' => $order,
    'motivo' => $motivo,
    'actualizados' => $refreshedOnly ? '1' : '',
];

function h(?string $value): string
{
    return e((string) ($value ?? ''));
}

function rp_url(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);
    if ((int) ($params['page'] ?? 1) <= 1) {
        $params['page'] = '';
    }
    if (($params['estado'] ?? '') === 'pendiente') {
        $params['estado'] = '';
    }
    if (($params['orden'] ?? '') === 'mas_reportadas') {
        $params['orden'] = '';
    }
    if ((int) ($params['min_reportes'] ?? 1) <= 1) {
        $params['min_reportes'] = '';
    }
    $query = http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null));
    return 'admin_reportes_productos.php' . ($query !== '' ? '?' . $query : '');
}

function rp_ago(?string $date): string
{
    $ts = $date ? strtotime($date) : false;
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'ahora';
    }
    if ($diff < 3600) {
        return 'hace ' . intdiv($diff, 60) . ' min';
    }
    if ($diff < 86400) {
        return 'hace ' . intdiv($diff, 3600) . ' h';
    }
    $days = intdiv($diff, 86400);
    if ($days === 1) {
        return 'ayer';
    }
    return $days < 30 ? 'hace ' . $days . ' días' : date('d/m/Y', $ts);
}

function rp_date(?string $date): string
{
    $ts = $date ? strtotime($date) : false;
    return $ts === false ? '' : date('d/m/Y H:i', $ts);
}

function rp_state_word(string $state, int $count): string
{
    $word = mb_strtolower(RP_ESTADOS[$state]['single']);
    if (str_ends_with($word, 'o')) {
        $word = mb_substr($word, 0, -1) . 'a';
    }
    return $count === 1 ? $word : $word . 's';
}

function rp_motivo_key(string $reason): string
{
    return array_key_exists($reason, RP_MOTIVOS) ? $reason : 'otro';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $productId = (int) ($_POST['product_id'] ?? 0);
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $done = '';

    if ($action === 'mark_reviewed' && $reportId > 0) {
        $stmt = $pdo->prepare("
            UPDATE producto_reportes
            SET rep_estado = 'revisado'
            WHERE idreporte = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $reportId]);
        $done = $action;
    } elseif ($action === 'mark_discarded' && $reportId > 0) {
        $stmt = $pdo->prepare("
            UPDATE producto_reportes
            SET rep_estado = 'descartado'
            WHERE idreporte = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $reportId]);
        $done = $action;
    } elseif ($action === 'mark_all_reviewed' && $productId > 0) {
        $stmt = $pdo->prepare("
            UPDATE producto_reportes
            SET rep_estado = 'revisado'
            WHERE productos_idproductos = :product_id
        ");
        $stmt->execute([':product_id' => $productId]);
        $done = $action;
    } elseif ($action === 'mark_all_discarded' && $productId > 0) {
        $stmt = $pdo->prepare("
            UPDATE producto_reportes
            SET rep_estado = 'descartado'
            WHERE productos_idproductos = :product_id
        ");
        $stmt->execute([':product_id' => $productId]);
        $done = $action;
    } elseif ($action === 'hide_product' && $productId > 0) {
        $stmt = $pdo->prepare("
            UPDATE productos
            SET pro_activo = 0
            WHERE idproductos = :product_id
            LIMIT 1
        ");
        $stmt->execute([':product_id' => $productId]);

        $stmt2 = $pdo->prepare("
            UPDATE producto_reportes
            SET rep_estado = 'revisado'
            WHERE productos_idproductos = :product_id
        ");
        $stmt2->execute([':product_id' => $productId]);
        $done = $action;
    } elseif ($action === 'restore_product' && $productId > 0) {
        $stmt = $pdo->prepare("
            UPDATE productos
            SET pro_activo = 1
            WHERE idproductos = :product_id
            LIMIT 1
        ");
        $stmt->execute([':product_id' => $productId]);
        $done = $action;
    }

    header('Location: ' . rp_url($state, ['ok' => $done, 'page' => $page]));
    exit;
}

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total_reportes,
        COUNT(DISTINCT productos_idproductos) AS total_productos_reportados,
        SUM(CASE WHEN rep_estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN rep_estado = 'revisado' THEN 1 ELSE 0 END) AS revisados,
        SUM(CASE WHEN rep_estado = 'descartado' THEN 1 ELSE 0 END) AS descartados,
        MIN(CASE WHEN rep_estado = 'pendiente' THEN rep_fecha END) AS pendiente_mas_antiguo
    FROM producto_reportes
")->fetch();

$hiddenProducts = (int) $pdo->query("
    SELECT COUNT(DISTINCT p.idproductos)
    FROM productos p
    INNER JOIN producto_reportes pr ON pr.productos_idproductos = p.idproductos
    WHERE p.pro_activo = 0
")->fetchColumn();

$refreshedPending = (int) $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT p.idproductos
        FROM producto_reportes pr
        INNER JOIN productos p ON p.idproductos = pr.productos_idproductos
        WHERE pr.rep_estado = 'pendiente'
        GROUP BY p.idproductos, p.pro_fecha_scraping
        HAVING p.pro_fecha_scraping > MAX(pr.rep_fecha)
    ) AS refreshed
")->fetchColumn();

$topStore = $pdo->query("
    SELECT t.tie_nombre, COUNT(*) AS total
    FROM producto_reportes pr
    INNER JOIN productos p ON p.idproductos = pr.productos_idproductos
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    WHERE pr.rep_estado = 'pendiente'
    GROUP BY t.idtiendas, t.tie_nombre
    ORDER BY total DESC, t.tie_nombre ASC
    LIMIT 1
")->fetch() ?: null;

$reasonTotals = array_fill_keys(array_keys(RP_MOTIVOS), ['total' => 0, 'pendientes' => 0]);
$reasonRows = $pdo->query("
    SELECT rep_motivo, COUNT(*) AS total, SUM(CASE WHEN rep_estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes
    FROM producto_reportes
    GROUP BY rep_motivo
")->fetchAll();
foreach ($reasonRows as $row) {
    $key = rp_motivo_key((string) $row['rep_motivo']);
    $reasonTotals[$key]['total'] += (int) $row['total'];
    $reasonTotals[$key]['pendientes'] += (int) $row['pendientes'];
}
$where = [];
$params = [];

if ($status !== 'todos') {
    $where[] = "pr.rep_estado = :estado";
    $params[':estado'] = $status;
}

if ($motivo === 'otro') {
    $known = implode(', ', array_map(static fn($k) => $pdo->quote($k), array_keys(RP_MOTIVOS)));
    $where[] = "(pr.rep_motivo = 'otro' OR pr.rep_motivo NOT IN ({$known}))";
} elseif ($motivo !== '') {
    $where[] = "pr.rep_motivo = :motivo";
    $params[':motivo'] = $motivo;
}

if ($q !== '') {
    $searchColumns = [
        't.tie_nombre', 'p.pro_nombre', 'p.pro_descripcion', 'p.pro_marca',
        'pr.rep_nombre', 'pr.rep_email', 'pr.rep_motivo', 'pr.rep_detalle',
        'pr.rep_ip', 'pr.rep_session_id',
    ];
    $searchConds = [];
    foreach ($searchColumns as $i => $column) {
        // Native prepares don't allow reusing a named placeholder.
        $searchConds[] = "{$column} LIKE :q{$i}";
        $params[":q{$i}"] = '%' . $q . '%';
    }
    $where[] = '(' . implode(' OR ', $searchConds) . ')';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$having = "HAVING COUNT(pr.idreporte) >= :min_reportes";
if ($refreshedOnly) {
    $having .= " AND p.pro_fecha_scraping > MAX(pr.rep_fecha)";
}

$orderSql = match ($order) {
    'mas_recientes' => 'ultima_fecha_reporte DESC, cantidad_reportes DESC, p.idproductos DESC',
    'menos_reportadas' => 'cantidad_reportes ASC, ultima_fecha_reporte DESC, p.idproductos DESC',
    'tienda_asc' => 't.tie_nombre ASC, cantidad_reportes DESC, ultima_fecha_reporte DESC',
    'estado' => "CASE
        WHEN SUM(CASE WHEN pr.rep_estado = 'pendiente' THEN 1 ELSE 0 END) > 0 THEN 0
        WHEN SUM(CASE WHEN pr.rep_estado = 'revisado' THEN 1 ELSE 0 END) > 0 THEN 1
        ELSE 2
    END ASC, cantidad_reportes DESC, ultima_fecha_reporte DESC",
    default => 'cantidad_reportes DESC, ultima_fecha_reporte DESC, p.idproductos DESC',
};

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
        p.pro_activo,
        t.idtiendas,
        t.tie_nombre,
        COUNT(pr.idreporte) AS cantidad_reportes,
        MAX(pr.rep_fecha) AS ultima_fecha_reporte,
        SUM(CASE WHEN pr.rep_estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes_count,
        SUM(CASE WHEN pr.rep_estado = 'revisado' THEN 1 ELSE 0 END) AS revisados_count,
        SUM(CASE WHEN pr.rep_estado = 'descartado' THEN 1 ELSE 0 END) AS descartados_count,
        GROUP_CONCAT(pr.idreporte ORDER BY pr.rep_fecha DESC SEPARATOR ',') AS report_ids
    FROM producto_reportes pr
    INNER JOIN productos p ON p.idproductos = pr.productos_idproductos
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    {$whereSql}
    GROUP BY
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
        p.pro_activo,
        t.idtiendas,
        t.tie_nombre
    {$having}
    ORDER BY {$orderSql}
";

$params[':min_reportes'] = $minReports;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$productGroups = $stmt->fetchAll();

$resultTotal = count($productGroups);
$totalPages = max(1, (int) ceil($resultTotal / $perPage));
$page = min($page, $totalPages);
$productGroups = array_slice($productGroups, ($page - 1) * $perPage, $perPage);

$pageItems = [];
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1) {
        $pageItems[] = $i;
    } elseif (end($pageItems) !== null) {
        $pageItems[] = null;
    }
}

$allReportIds = [];
foreach ($productGroups as $group) {
    if (!empty($group['report_ids'])) {
        foreach (explode(',', (string) $group['report_ids']) as $idValue) {
            $idValue = (int) $idValue;
            if ($idValue > 0) {
                $allReportIds[] = $idValue;
            }
        }
    }
}

$reportsByProduct = [];
if ($allReportIds) {
    $placeholders = implode(',', array_fill(0, count($allReportIds), '?'));
    $detailStmt = $pdo->prepare("
        SELECT pr.*
        FROM producto_reportes pr
        WHERE pr.idreporte IN ($placeholders)
        ORDER BY pr.rep_fecha DESC, pr.idreporte DESC
    ");
    $detailStmt->execute($allReportIds);

    foreach ($detailStmt->fetchAll() as $report) {
        $productKey = (int) $report['productos_idproductos'];
        $reportsByProduct[$productKey][] = $report;
    }
}

$fmt = static fn(int|float $n): string => number_format($n, 0, ',', '.');

$stateCounts = [
    'pendiente' => (int) ($stats['pendientes'] ?? 0),
    'revisado' => (int) ($stats['revisados'] ?? 0),
    'descartado' => (int) ($stats['descartados'] ?? 0),
    'todos' => (int) ($stats['total_reportes'] ?? 0),
];
$reportTotal = $stateCounts['todos'];
$productTotal = (int) ($stats['total_productos_reportados'] ?? 0);
$sharePct = static fn(int $n): int => $reportTotal > 0 ? (int) round($n / $reportTotal * 100) : 0;

$minOptions = RP_MINIMOS;
if (!in_array($minReports, $minOptions, true)) {
    $minOptions[] = $minReports;
    sort($minOptions);
}

$ok = (string) ($_GET['ok'] ?? '');
$flash = isset(RP_AVISOS[$ok]) ? ['type' => 'success', 'message' => RP_AVISOS[$ok]] : null;

render_head('Gestión de productos reportados');
?>
<link rel="stylesheet" href="./css/admin.css?v=<?= filemtime(__DIR__ . '/css/admin.css') ?>">
<?php render_navbar('admin'); ?>

<?php require __DIR__ . '/auth_toast.php'; ?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="ad-page rp-page" aria-labelledby="rp-title">
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
          <a href="admin.php" class="pd-crumb-link"><span>Panel</span></a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current">Productos reportados</span>
        </li>
      </ol>
    </nav>

    <div class="ad-board">

      <header class="ad-panel rp-desk animate-reveal-up">
        <div class="rp-desk-top">
          <div class="rp-intro">
            <div class="rp-intro-head">
              <div class="rp-intro-copy">
                <span class="rp-tag"><?= lucide_icon('package-search', '', 14) ?>Control de calidad del catálogo</span>
                <h1 id="rp-title" class="rp-title">Productos reportados</h1>
              </div>
            </div>
            <p class="rp-lead">
              Los usuarios avisan cuando el precio, el stock o la ficha no coinciden con la tienda.
              Contrasta cada alerta con la fuente original y decide si el producto sigue publicado.
            </p>

            <section class="rp-causes" aria-labelledby="rp-causes-title">
              <header class="rp-causes-head">
                <h2 id="rp-causes-title" class="rp-causes-title"><?= lucide_icon('flag', '', 14) ?>Qué denuncian los usuarios</h2>
                <span class="rp-causes-hint"><?= lucide_icon('mouse-pointer-click', '', 13) ?>Clic para filtrar la cola</span>
              </header>

              <ul class="rp-causes-list">
                <?php foreach (RP_MOTIVOS as $key => $reason): ?>
                  <?php
                    $data = $reasonTotals[$key];
                    $isActive = $motivo === $key;
                    $share = $sharePct($data['total']);
                  ?>
                  <li>
                    <a
                      href="<?= e(rp_url($state, ['motivo' => $isActive ? '' : $key])) ?>"
                      class="rp-cause <?= $isActive ? 'is-active' : '' ?> <?= $data['total'] === 0 ? 'is-empty' : '' ?>"
                      style="--m-c: <?= $reason['tone'] ?>; --m-w: <?= $share ?>%"
                      title="<?= e($reason['label']) ?> · <?= $data['pendientes'] > 0 ? $fmt($data['pendientes']) . ' sin revisar' : 'nada pendiente' ?><?= $isActive ? ' · clic para quitar el filtro' : '' ?>"
                      <?= $isActive ? 'aria-current="true"' : '' ?>
                    >
                      <span class="rp-cause-icon" aria-hidden="true"><?= lucide_icon($reason['icon'], '', 16) ?></span>
                      <span class="rp-cause-copy">
                        <span class="rp-cause-name"><?= e($reason['label']) ?></span>
                        <span class="rp-cause-meta"><?= $share ?>% del total</span>
                      </span>
                      <span class="rp-cause-count"><?= $fmt($data['total']) ?></span>
                      <span class="rp-cause-meter" aria-hidden="true"></span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </section>
          </div>

          <aside class="rp-watch" aria-labelledby="rp-watch-title">
            <h2 id="rp-watch-title" class="rp-watch-title"><?= lucide_icon('radar', '', 14) ?>Señales a vigilar</h2>

            <ul class="rp-watch-list">
              <li>
                <?php if ($stats['pendiente_mas_antiguo']): ?>
                  <a href="<?= e(rp_url($state, ['estado' => 'pendiente', 'orden' => 'mas_recientes'])) ?>" class="rp-signal is-link is-warn" title="<?= h(rp_date($stats['pendiente_mas_antiguo'])) ?>">
                    <span class="rp-signal-icon" aria-hidden="true"><?= lucide_icon('timer', '', 17) ?></span>
                    <span class="rp-signal-copy">
                      <span class="rp-signal-label">Alerta más antigua sin revisar</span>
                      <strong>Espera desde <?= h(rp_ago($stats['pendiente_mas_antiguo'])) ?></strong>
                    </span>
                    <?= lucide_icon('chevron-right', 'rp-signal-go', 16) ?>
                  </a>
                <?php else: ?>
                  <span class="rp-signal is-ok">
                    <span class="rp-signal-icon" aria-hidden="true"><?= lucide_icon('shield-check', '', 17) ?></span>
                    <span class="rp-signal-copy">
                      <span class="rp-signal-label">Alerta más antigua sin revisar</span>
                      <strong>Bandeja al día</strong>
                    </span>
                  </span>
                <?php endif; ?>
              </li>
              <li>
                <?php if ($refreshedPending > 0): ?>
                  <a href="<?= e(rp_url($state, ['actualizados' => '1', 'estado' => 'pendiente'])) ?>" class="rp-signal is-link is-fresh" title="Ver los productos que el scraper actualizó después de la alerta">
                    <span class="rp-signal-icon" aria-hidden="true"><?= lucide_icon('refresh-cw', '', 17) ?></span>
                    <span class="rp-signal-copy">
                      <span class="rp-signal-label">Actualizados tras la alerta</span>
                      <strong>Listos para verificar</strong>
                    </span>
                    <span class="rp-signal-badge"><?= $fmt($refreshedPending) ?></span>
                  </a>
                <?php else: ?>
                  <span class="rp-signal">
                    <span class="rp-signal-icon" aria-hidden="true"><?= lucide_icon('refresh-cw-off', '', 17) ?></span>
                    <span class="rp-signal-copy">
                      <span class="rp-signal-label">Actualizados tras la alerta</span>
                      <strong>Ninguno todavía</strong>
                    </span>
                  </span>
                <?php endif; ?>
              </li>
              <li>
                <?php if ($topStore): ?>
                  <a href="<?= e(rp_url($state, ['q' => $topStore['tie_nombre'], 'estado' => 'pendiente'])) ?>" class="rp-signal is-link is-danger" title="Ver sus productos con alertas abiertas">
                    <span class="rp-signal-icon" aria-hidden="true"><?= lucide_icon('store', '', 17) ?></span>
                    <span class="rp-signal-copy">
                      <span class="rp-signal-label">Tienda con más alertas abiertas</span>
                      <strong title="<?= h($topStore['tie_nombre']) ?>"><?= h($topStore['tie_nombre']) ?></strong>
                    </span>
                    <span class="rp-signal-badge"><?= $fmt((int) $topStore['total']) ?></span>
                  </a>
                <?php else: ?>
                  <span class="rp-signal">
                    <span class="rp-signal-icon" aria-hidden="true"><?= lucide_icon('store', '', 17) ?></span>
                    <span class="rp-signal-copy">
                      <span class="rp-signal-label">Tienda con más alertas abiertas</span>
                      <strong>Ninguna</strong>
                    </span>
                  </span>
                <?php endif; ?>
              </li>
              <li>
                <span class="rp-signal <?= $hiddenProducts > 0 ? 'is-danger' : '' ?>">
                  <span class="rp-signal-icon" aria-hidden="true"><?= lucide_icon('eye-off', '', 17) ?></span>
                  <span class="rp-signal-copy">
                    <span class="rp-signal-label">Retirados del catálogo</span>
                    <strong><?= $fmt($hiddenProducts) ?> de <?= $fmt($productTotal) ?> <?= $productTotal === 1 ? 'producto reportado' : 'productos reportados' ?></strong>
                  </span>
                </span>
              </li>
            </ul>

            <p class="rp-watch-foot">
              <?= lucide_icon('info', '', 13) ?>
              <span>Actualizado a las <time datetime="<?= date('c') ?>"><?= date('H:i') ?> h</time></span>
            </p>
          </aside>
        </div>
      </header>

      <section class="ad-panel rp-queue animate-reveal-up" aria-labelledby="rp-list-title" style="--lane-accent: <?= RP_ESTADOS[$status]['tone'] ?>">
        <header class="ad-panel-head rp-queue-head">
          <span class="ad-panel-icon" aria-hidden="true"><?= lucide_icon(RP_ESTADOS[$status]['icon'], '', 18) ?></span>
          <div>
            <h2 id="rp-list-title" class="ad-panel-title">Fichas en revisión</h2>
            <p class="ad-panel-caption">
              <?php if ($productGroups): ?>
                <?= $fmt($resultTotal) ?> <?= $resultTotal === 1 ? 'producto' : 'productos' ?> · por <?= e(mb_strtolower(RP_ORDENES[$order]['label'])) ?>
              <?php else: ?>
                Ningún producto coincide
              <?php endif; ?>
            </p>
          </div>

          <form method="get" class="at-search" role="search">
            <?php foreach (['estado' => $status !== 'pendiente' ? $status : '', 'orden' => $order !== 'mas_reportadas' ? $order : '', 'motivo' => $motivo, 'actualizados' => $state['actualizados'], 'min_reportes' => $minReports > 1 ? (string) $minReports : ''] as $name => $value): ?>
              <?php if ($value !== ''): ?><input type="hidden" name="<?= $name ?>" value="<?= e($value) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <?= lucide_icon('search', 'at-search-icon', 16) ?>
            <label for="rp-q" class="visually-hidden">Buscar en las alertas</label>
            <input type="search" id="rp-q" name="q" class="at-search-input" placeholder="Producto, tienda, marca, reportante…" value="<?= e($q) ?>" autocomplete="off">
            <?php if ($q !== ''): ?>
              <a href="<?= e(rp_url($state, ['q' => ''])) ?>" class="at-search-clear" aria-label="Borrar búsqueda" title="Borrar búsqueda"><?= lucide_icon('x', '', 14) ?></a>
            <?php endif; ?>
            <button type="submit" class="at-search-go" aria-label="Buscar"><?= lucide_icon('arrow-right', '', 15) ?></button>
          </form>
        </header>

        <nav class="rp-states" aria-label="Estado de las alertas">
          <?php foreach (RP_ESTADOS as $key => $estado): ?>
            <a
              href="<?= e(rp_url($state, ['estado' => $key])) ?>"
              class="rp-state <?= $status === $key ? 'is-active' : '' ?>"
              style="--s-c: <?= $estado['tone'] ?>"
              <?= $status === $key ? 'aria-current="page"' : '' ?>
            >
              <span class="rp-state-icon" aria-hidden="true"><?= lucide_icon($estado['icon'], '', 16) ?></span>
              <span class="rp-state-copy">
                <span class="rp-state-name"><?= e($estado['label']) ?></span>
                <span class="rp-state-hint"><?= e($estado['hint']) ?></span>
              </span>
              <span class="rp-state-count"><?= $fmt($stateCounts[$key]) ?></span>
            </a>
          <?php endforeach; ?>
        </nav>

        <div class="rp-sheet">
          <div class="at-bar">
            <nav class="at-sort" aria-label="Ordenar productos">
              <?php foreach (RP_ORDENES as $key => $option): ?>
                <a
                  href="<?= e(rp_url($state, ['orden' => $key])) ?>"
                  class="at-sort-item <?= $order === $key ? 'is-active' : '' ?>"
                  <?= $order === $key ? 'aria-current="true"' : '' ?>
                >
                  <?= lucide_icon($option['icon'], '', 14) ?><?= e($option['label']) ?>
                </a>
              <?php endforeach; ?>
            </nav>

            <div class="rp-bar-end">
              <?php if ($refreshedOnly): ?>
                <a href="<?= e(rp_url($state, ['actualizados' => ''])) ?>" class="at-chip rp-chip" style="--m-c: var(--success)" title="Quitar filtro">
                  <?= lucide_icon('refresh-cw', '', 13) ?>Actualizados tras la alerta<?= lucide_icon('x', '', 13) ?>
                </a>
              <?php endif; ?>
              <?php if ($motivo !== ''): ?>
                <a href="<?= e(rp_url($state, ['motivo' => ''])) ?>" class="at-chip rp-chip" style="--m-c: <?= RP_MOTIVOS[$motivo]['tone'] ?>" title="Quitar filtro">
                  <?= lucide_icon(RP_MOTIVOS[$motivo]['icon'], '', 13) ?><?= e(RP_MOTIVOS[$motivo]['label']) ?><?= lucide_icon('x', '', 13) ?>
                </a>
              <?php endif; ?>

              <form method="get" class="rp-min-form">
                <?php foreach (['estado' => $status !== 'pendiente' ? $status : '', 'q' => $q, 'orden' => $order !== 'mas_reportadas' ? $order : '', 'motivo' => $motivo, 'actualizados' => $state['actualizados']] as $name => $value): ?>
                  <?php if ($value !== ''): ?><input type="hidden" name="<?= $name ?>" value="<?= e($value) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <label class="ap-rows-select <?= $minReports > 1 ? 'is-set' : '' ?>" title="Mínimo de alertas por producto">
                  <?= lucide_icon('flag', 'ap-rows-select-icon', 15) ?>
                  <span class="ap-rows-select-label">Mín. alertas</span>
                  <span class="ap-rows-select-value" aria-hidden="true"><?= $minReports ?></span>
                  <select name="min_reportes" class="ap-rows-select-input" aria-label="Mínimo de alertas por producto" data-rp-min>
                    <?php foreach ($minOptions as $option): ?>
                      <option value="<?= $option ?>" <?= $option === $minReports ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?= lucide_icon('chevron-down', 'ap-rows-select-caret', 13) ?>
                </label>
                <noscript><button type="submit" class="at-search-go" aria-label="Aplicar"><?= lucide_icon('arrow-right', '', 15) ?></button></noscript>
              </form>
            </div>
          </div>

          <?php if ($productGroups): ?>
            <ul class="rp-items">
              <?php foreach ($productGroups as $item): ?>
                <?php
                  $productId = (int) $item['idproductos'];
                  $isVisible = (int) $item['pro_activo'] === 1;
                  $inStock = (int) $item['pro_en_stock'] === 1;
                  $reportList = $reportsByProduct[$productId] ?? [];
                  $caseCounts = [
                      'pendiente' => (int) $item['pendientes_count'],
                      'revisado' => (int) $item['revisados_count'],
                      'descartado' => (int) $item['descartados_count'],
                  ];
                  $caseTotal = (int) $item['cantidad_reportes'];
                  $caseState = $caseCounts['pendiente'] > 0 ? 'pendiente' : ($caseCounts['revisado'] > 0 ? 'revisado' : 'descartado');
                  $caseLabel = match ($caseState) {
                      'pendiente' => $fmt($caseCounts['pendiente']) . ' por revisar',
                      'revisado' => 'Atendido',
                      default => 'Descartado',
                  };
                  $caseReasons = [];
                  foreach ($reportList as $report) {
                      $key = rp_motivo_key((string) $report['rep_motivo']);
                      $caseReasons[$key] = ($caseReasons[$key] ?? 0) + 1;
                  }
                  arsort($caseReasons);

                  $image = image_url($item['pro_imagen'], (string) $item['pro_nombre']);
                  $price = (float) $item['pro_precio'];
                  $previous = (float) ($item['pro_precio_anterior'] ?? 0);
                  $delta = $previous > 0 && $price > 0 && abs($previous - $price) >= 1 ? (int) round(($price - $previous) / $previous * 100) : null;
                  $scrapedTs = strtotime((string) $item['pro_fecha_scraping']) ?: 0;
                  $isRefreshed = $scrapedTs > (strtotime((string) $item['ultima_fecha_reporte']) ?: PHP_INT_MAX);
                  $sourceUrl = trim((string) ($item['pro_url'] ?? ''));
                ?>
                <li class="rp-item ar-card <?= $isVisible ? '' : 'is-off' ?>" style="--s-c: <?= RP_ESTADOS[$caseState]['tone'] ?>">
                  <header class="ar-review-head">
                    <a href="producto.php?id=<?= $productId ?>" class="rp-shot" target="_blank" rel="noopener" aria-label="Abrir la ficha pública de <?= h($item['pro_nombre']) ?>">
                      <?= lucide_icon('image-off', '', 18) ?>
                      <?php if ($image !== ''): ?>
                        <img src="<?= h($image) ?>" alt="" loading="lazy" data-no-fallback="true" onerror="this.remove()">
                      <?php endif; ?>
                    </a>
                    <div class="ar-review-who">
                      <h3 class="ar-review-name rp-product-name" title="<?= h($item['pro_nombre']) ?>"><?= h($item['pro_nombre']) ?></h3>
                      <div class="ar-review-meta">
                        <a href="tienda.php?id=<?= (int) $item['idtiendas'] ?>" class="ar-review-store" target="_blank" rel="noopener" title="Abrir la tienda"><?= lucide_icon('store', '', 13) ?><span><?= h($item['tie_nombre']) ?></span></a>
                        <span title="ID del producto"><?= lucide_icon('hash', '', 12) ?><?= $productId ?></span>
                      </div>
                    </div>
                    <div class="ar-card-side">
                      <?php if (!$isVisible): ?>
                        <span class="rp-off-tag"><?= lucide_icon('eye-off', '', 12) ?>Retirado</span>
                      <?php endif; ?>
                      <div class="dropdown rp-menu">
                        <button type="button" class="rp-menu-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Más acciones para <?= h($item['pro_nombre']) ?>">
                          <?= lucide_icon('ellipsis', '', 16) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end rp-menu-list">
                          <li><a class="dropdown-item" href="producto.php?id=<?= $productId ?>" target="_blank" rel="noopener"><?= lucide_icon('file-text', '', 15) ?>Ver ficha pública</a></li>
                          <?php if ($sourceUrl !== ''): ?>
                            <li><a class="dropdown-item" href="<?= h($sourceUrl) ?>" target="_blank" rel="noopener noreferrer"><?= lucide_icon('external-link', '', 15) ?>Ver en la tienda</a></li>
                          <?php endif; ?>
                          <li><a class="dropdown-item" href="admin_productos.php?edit=<?= $productId ?>"><?= lucide_icon('pencil', '', 15) ?>Editar ficha</a></li>
                          <li><hr class="dropdown-divider"></li>
                          <?php if ($caseCounts['pendiente'] > 0): ?>
                            <li>
                              <form method="post">
                                <input type="hidden" name="product_id" value="<?= $productId ?>">
                                <input type="hidden" name="action" value="mark_all_reviewed">
                                <button type="submit" class="dropdown-item"><?= lucide_icon('circle-check', '', 15) ?><?= $caseTotal === 1 ? 'Marcar como revisada' : 'Revisar todas' ?></button>
                              </form>
                            </li>
                          <?php endif; ?>
                          <li>
                            <form method="post">
                              <input type="hidden" name="product_id" value="<?= $productId ?>">
                              <input type="hidden" name="action" value="mark_all_discarded">
                              <button type="submit" class="dropdown-item"><?= lucide_icon('archive-x', '', 15) ?>Descartar <?= $caseTotal === 1 ? 'la alerta' : 'todas' ?></button>
                            </form>
                          </li>
                          <li>
                            <?php if ($isVisible): ?>
                              <button
                                type="button"
                                class="dropdown-item is-danger"
                                data-bs-toggle="modal"
                                data-bs-target="#rpHideModal"
                                data-id="<?= $productId ?>"
                                data-name="<?= h($item['pro_nombre']) ?>"
                                data-store="<?= h($item['tie_nombre']) ?>"
                                data-price="<?= h(gs($item['pro_precio'])) ?>"
                                data-image="<?= h($image) ?>"
                                data-reports="<?= $caseTotal ?>"
                              ><?= lucide_icon('eye-off', '', 15) ?>Retirar producto</button>
                            <?php else: ?>
                              <form method="post">
                                <input type="hidden" name="product_id" value="<?= $productId ?>">
                                <input type="hidden" name="action" value="restore_product">
                                <button type="submit" class="dropdown-item"><?= lucide_icon('eye', '', 15) ?>Volver a publicar</button>
                              </form>
                            <?php endif; ?>
                          </li>
                        </ul>
                      </div>
                    </div>
                  </header>

                  <div class="ar-card-row rp-price-row">
                    <p class="rp-price">
                      <?= gs($item['pro_precio']) ?>
                      <?php if ($delta !== null): ?>
                        <em class="<?= $delta < 0 ? 'is-down' : '' ?>" title="Antes <?= h(gs($previous)) ?>"><?= ($delta > 0 ? '+' : '') . $delta ?>%</em>
                      <?php endif; ?>
                    </p>
                    <span class="rp-stock <?= $inStock ? 'is-ok' : 'is-bad' ?>"><?= lucide_icon($inStock ? 'package-check' : 'package-x', '', 13) ?><?= $inStock ? 'Disponible' : 'Agotado' ?></span>
                  </div>

                  <dl class="rp-specs">
                    <div>
                      <dt>Marca</dt>
                      <dd title="<?= h($item['pro_marca'] ?: 'Sin marca') ?>"><?= h($item['pro_marca'] ?: 'Sin marca') ?></dd>
                    </div>
                    <div>
                      <dt>Actualizado</dt>
                      <dd class="<?= $isRefreshed ? 'is-ok' : 'is-warn' ?>" title="Última lectura del scraper: <?= h(rp_date($item['pro_fecha_scraping']) ?: 'sin fecha') ?><?= $isRefreshed ? ' (después de la alerta)' : ' (antes de la alerta)' ?>"><?= lucide_icon($isRefreshed ? 'refresh-cw' : 'refresh-cw-off', '', 12) ?><?= h(rp_ago($item['pro_fecha_scraping'])) ?></dd>
                    </div>
                  </dl>

                  <?php if ($caseReasons): ?>
                    <div class="rp-reported">
                      <span class="rp-reported-label">Reportado como</span>
                      <ul class="ar-tags" aria-label="Motivos denunciados">
                        <?php foreach ($caseReasons as $key => $count): ?>
                          <li style="--m-c: <?= RP_MOTIVOS[$key]['tone'] ?>"><?= lucide_icon(RP_MOTIVOS[$key]['icon'], '', 12) ?><?= e(RP_MOTIVOS[$key]['label']) ?><?php if ($count > 1): ?><em>×<?= $count ?></em><?php endif; ?></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>

                  <footer class="rp-card-foot ar-card-foot">
                    <span class="rp-case" title="Última alerta: <?= h(rp_date($item['ultima_fecha_reporte'])) ?>">
                      <strong><?= lucide_icon(RP_CASO_ICONOS[$caseState], '', 13) ?><?= e($caseLabel) ?></strong>
                      <small>Última alerta <?= h(rp_ago($item['ultima_fecha_reporte'])) ?></small>
                    </span>
                    <button
                      type="button"
                      class="rp-btn <?= $caseCounts['pendiente'] > 0 ? 'is-alert' : '' ?>"
                      data-bs-toggle="modal"
                      data-bs-target="#rpAlertsModal"
                      data-template="rp-alerts-<?= $productId ?>"
                      data-id="<?= $productId ?>"
                      data-summary="<?= h($fmt($caseTotal) . ($caseTotal === 1 ? ' alerta' : ' alertas') . ' · ' . mb_strtolower($caseLabel)) ?>"
                      data-pending="<?= $caseCounts['pendiente'] ?>"
                    >
                      <?= lucide_icon('message-square', '', 15) ?><?= $caseTotal === 1 ? 'Ver alerta' : 'Ver ' . $fmt($caseTotal) . ' alertas' ?>
                    </button>
                  </footer>

                  <template id="rp-alerts-<?= $productId ?>">
                    <article class="ar-review is-full" aria-label="Producto #<?= $productId ?>">
                      <header class="ar-review-head">
                        <span class="rp-shot" aria-hidden="true">
                          <?= lucide_icon('image-off', '', 18) ?>
                          <?php if ($image !== ''): ?>
                            <img src="<?= h($image) ?>" alt="" data-no-fallback="true" onerror="this.remove()">
                          <?php endif; ?>
                        </span>
                        <div class="ar-review-who">
                          <p class="ar-review-name rp-product-name"><?= h($item['pro_nombre']) ?></p>
                          <p class="rp-price"<?= $delta !== null ? ' title="Antes ' . h(gs($previous)) . '"' : '' ?>>
                            <?= gs($item['pro_precio']) ?>
                            <?php if ($delta !== null): ?>
                              <em class="<?= $delta < 0 ? 'is-down' : '' ?>"><?= ($delta > 0 ? '+' : '') . $delta ?>%</em>
                            <?php endif; ?>
                          </p>
                          <div class="ar-review-meta">
                            <a href="tienda.php?id=<?= (int) $item['idtiendas'] ?>" class="ar-review-store" target="_blank" rel="noopener" title="Abrir la tienda"><?= lucide_icon('store', '', 13) ?><span><?= h($item['tie_nombre']) ?></span></a>
                            <span title="ID del producto"><?= lucide_icon('hash', '', 12) ?><?= $productId ?></span>
                            <?php if (!$isVisible): ?>
                              <span class="rp-off-tag"><?= lucide_icon('eye-off', '', 12) ?>Retirado</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </header>

                      <div class="rp-full-foot">
                        <ul class="rp-chips" aria-label="Datos de la ficha">
                          <li class="<?= $inStock ? 'is-ok' : 'is-bad' ?>" title="Stock"><?= lucide_icon($inStock ? 'package-check' : 'package-x', '', 13) ?><?= $inStock ? 'Disponible' : 'Agotado' ?></li>
                          <li title="Marca: <?= h($item['pro_marca'] ?: 'sin marca') ?>"><?= lucide_icon('tag', '', 13) ?><?= h($item['pro_marca'] ?: 'Sin marca') ?></li>
                          <li class="<?= $isRefreshed ? 'is-ok' : 'is-warn' ?>" title="Última lectura del scraper: <?= h(rp_date($item['pro_fecha_scraping']) ?: 'sin fecha') ?> · <?= $isRefreshed ? 'renovada después de la alerta' : 'sin renovar desde la alerta' ?>"><?= lucide_icon($isRefreshed ? 'refresh-cw' : 'refresh-cw-off', '', 13) ?><?= h(rp_ago($item['pro_fecha_scraping'])) ?></li>
                        </ul>
                        <div class="rp-verify">
                          <a href="producto.php?id=<?= $productId ?>" target="_blank" rel="noopener" title="Ver ficha pública" aria-label="Ver ficha pública"><?= lucide_icon('file-text', '', 15) ?></a>
                          <?php if ($sourceUrl !== ''): ?>
                            <a href="<?= h($sourceUrl) ?>" target="_blank" rel="noopener noreferrer" title="Comparar con la tienda de origen" aria-label="Comparar con la tienda de origen"><?= lucide_icon('external-link', '', 15) ?></a>
                          <?php endif; ?>
                          <a href="admin_productos.php?edit=<?= $productId ?>" title="Editar ficha" aria-label="Editar ficha"><?= lucide_icon('pencil', '', 15) ?></a>
                        </div>
                      </div>
                    </article>
                    <p class="ar-log-title"><?= lucide_icon('flag', '', 13) ?><?= count($reportList) === 1 ? 'Alerta recibida' : 'Alertas recibidas' ?><span><?= $fmt(count($reportList)) ?></span></p>
                    <?php if ($reportList): ?>
                      <ol class="rp-log">
                        <?php foreach ($reportList as $report): ?>
                          <?php
                            $reportId = (int) $report['idreporte'];
                            $reason = RP_MOTIVOS[rp_motivo_key((string) $report['rep_motivo'])];
                            $reportState = array_key_exists((string) $report['rep_estado'], RP_ESTADOS) ? (string) $report['rep_estado'] : 'pendiente';
                            $session = (string) ($report['rep_session_id'] ?? '');
                            $detail = trim((string) ($report['rep_detalle'] ?? ''));
                          ?>
                          <li class="rp-entry <?= $reportState === 'pendiente' ? '' : 'is-closed' ?>" style="--m-c: <?= $reason['tone'] ?>; --s-c: <?= RP_ESTADOS[$reportState]['tone'] ?>">
                            <header class="rp-entry-head">
                              <span class="rp-entry-node" aria-hidden="true"><?= lucide_icon($reason['icon'], '', 16) ?></span>
                              <div class="rp-entry-title">
                                <strong><?= e($reason['label']) ?></strong>
                                <time class="rp-entry-time" datetime="<?= h(date('c', strtotime((string) $report['rep_fecha']) ?: time())) ?>" title="<?= h(rp_date($report['rep_fecha'])) ?>"><?= h(rp_ago($report['rep_fecha'])) ?></time>
                              </div>
                              <div class="rp-entry-side">
                                <span class="rp-pill"><?= lucide_icon($reportState === 'pendiente' ? 'hourglass' : RP_CASO_ICONOS[$reportState], '', 12) ?><?= e(RP_ESTADOS[$reportState]['single']) ?></span>
                                <?php if ($reportState !== 'revisado'): ?>
                                  <form method="post">
                                    <input type="hidden" name="report_id" value="<?= $reportId ?>">
                                    <input type="hidden" name="action" value="mark_reviewed">
                                    <button type="submit" class="rp-act is-ok" aria-label="Marcar alerta #<?= $reportId ?> como revisada" title="Marcar como revisada"><?= lucide_icon('check', '', 14) ?></button>
                                  </form>
                                <?php endif; ?>
                                <?php if ($reportState !== 'descartado'): ?>
                                  <form method="post">
                                    <input type="hidden" name="report_id" value="<?= $reportId ?>">
                                    <input type="hidden" name="action" value="mark_discarded">
                                    <button type="submit" class="rp-act is-no" aria-label="Descartar alerta #<?= $reportId ?>" title="Descartar"><?= lucide_icon('x', '', 14) ?></button>
                                  </form>
                                <?php endif; ?>
                              </div>
                            </header>

                            <p class="rp-entry-text <?= $detail === '' ? 'is-empty' : '' ?>"><?= $detail !== '' ? h($detail) : 'El usuario no dejó comentario.' ?></p>

                            <footer class="rp-entry-foot">
                              <ul class="rp-entry-meta">
                                <li title="Reportante"><?= lucide_icon('user-round', '', 12) ?><?= h($report['rep_nombre'] ?: 'Anónimo') ?></li>
                                <?php if (!empty($report['rep_email'])): ?>
                                  <li><a href="mailto:<?= h($report['rep_email']) ?>" title="Escribir al reportante"><?= lucide_icon('mail', '', 12) ?><?= h($report['rep_email']) ?></a></li>
                                <?php endif; ?>
                                <li title="Dirección IP"><?= lucide_icon('globe', '', 12) ?><code><?= h($report['rep_ip'] ?: 'Sin IP') ?></code></li>
                                <?php if ($session !== ''): ?>
                                  <li title="Sesión: <?= h($session) ?>"><?= lucide_icon('fingerprint', '', 12) ?><code><?= h(mb_strlen($session) > 10 ? mb_substr($session, 0, 10) . '…' : $session) ?></code></li>
                                <?php endif; ?>
                                <li title="Número de alerta"><?= lucide_icon('hash', '', 12) ?><?= $reportId ?></li>
                              </ul>
                            </footer>
                          </li>
                        <?php endforeach; ?>
                      </ol>
                    <?php else: ?>
                      <p class="rp-case-empty"><?= lucide_icon('file-search', '', 16) ?>No hay más detalles disponibles para este producto.</p>
                    <?php endif; ?>
                  </template>
                </li>
              <?php endforeach; ?>
            </ul>

            <?php if ($totalPages > 1): ?>
              <nav class="ap-pager rp-pager" aria-label="Paginación de productos reportados">
                <span class="ap-pager-info">Página <strong><?= $fmt($page) ?></strong> de <?= $fmt($totalPages) ?></span>
                <div class="ap-pages">
                  <a
                    class="ap-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>"
                    href="<?= e(rp_url($state, ['page' => max(1, $page - 1)])) ?>"
                    aria-label="Página anterior"
                    <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                  ><?= lucide_icon('chevron-left', '', 15) ?></a>
                  <?php foreach ($pageItems as $pageItem): ?>
                    <?php if ($pageItem === null): ?>
                      <span class="ap-page-gap" aria-hidden="true">…</span>
                    <?php else: ?>
                      <a
                        class="ap-page-btn <?= $pageItem === $page ? 'is-current' : '' ?>"
                        href="<?= e(rp_url($state, ['page' => $pageItem])) ?>"
                        <?= $pageItem === $page ? 'aria-current="page"' : '' ?>
                      ><?= $fmt($pageItem) ?></a>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <a
                    class="ap-page-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>"
                    href="<?= e(rp_url($state, ['page' => min($totalPages, $page + 1)])) ?>"
                    aria-label="Página siguiente"
                    <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                  ><?= lucide_icon('chevron-right', '', 15) ?></a>
                </div>
              </nav>
            <?php endif; ?>
          <?php else: ?>
            <?php $isClean = $q === '' && $motivo === '' && !$refreshedOnly && $minReports === 1; ?>
            <div class="rp-empty">
              <span class="rp-empty-icon" aria-hidden="true"><?= lucide_icon($reportTotal === 0 ? 'package-search' : ($status === 'pendiente' && $isClean ? 'shield-check' : 'search-x'), '', 20) ?></span>
              <?php if ($reportTotal === 0): ?>
                <p class="rp-empty-text">Todavía nadie reportó un producto.</p>
              <?php elseif ($status === 'pendiente' && $isClean): ?>
                <p class="rp-empty-text">Catálogo verificado: todos los productos reportados ya tienen una decisión.</p>
                <a href="<?= e(rp_url($state, ['estado' => 'todos'])) ?>" class="rp-empty-action"><?= lucide_icon('layers', '', 14) ?>Ver el historial completo</a>
              <?php else: ?>
                <p class="rp-empty-text"><?= $q !== '' ? 'Ningún producto coincide con “' . e($q) . '”.' : 'Ningún producto cumple los filtros activos.' ?></p>
                <a href="admin_reportes_productos.php" class="rp-empty-action"><?= lucide_icon('rotate-ccw', '', 14) ?>Quitar filtros</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

    </div>
  </div>
</section>

<div class="modal fade" id="rpAlertsModal" tabindex="-1" aria-labelledby="rp-alerts-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content suggestion-modal report-product-modal ad-confirm rp-alerts-modal ar-reports-modal">
      <div class="modal-header">
        <div class="report-product-heading">
          <span class="report-product-icon" aria-hidden="true"><?= lucide_icon('message-square-warning', '', 18) ?></span>
          <div class="report-product-heading-text">
            <p class="ad-confirm-kicker">Moderación del catálogo</p>
            <h5 class="modal-title" id="rp-alerts-title">Alertas del producto</h5>
            <p class="report-product-lead" data-rp-alerts="summary"></p>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div data-rp-alerts="list"></div>
      </div>

      <div class="modal-footer rp-alerts-foot">
        <form method="post">
          <input type="hidden" name="product_id" value="" data-rp-alerts="id">
          <input type="hidden" name="action" value="mark_all_discarded">
          <button type="submit" class="btn btn-outline-secondary"><?= lucide_icon('archive-x', '', 16) ?>Descartar todas</button>
        </form>
        <form method="post" data-rp-alerts="review">
          <input type="hidden" name="product_id" value="" data-rp-alerts="id">
          <input type="hidden" name="action" value="mark_all_reviewed">
          <button type="submit" class="btn btn-success"><?= lucide_icon('circle-check', '', 16) ?>Revisar todas</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="rpHideModal" tabindex="-1" aria-labelledby="rp-hide-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content suggestion-modal report-product-modal ad-confirm">
      <input type="hidden" name="action" value="hide_product">
      <input type="hidden" name="product_id" value="" data-rp-hide="id">

      <div class="modal-header">
        <div class="report-product-heading">
          <span class="report-product-icon" aria-hidden="true"><?= lucide_icon('eye-off', '', 18) ?></span>
          <div class="report-product-heading-text">
            <p class="ad-confirm-kicker">Moderación del catálogo</p>
            <h5 class="modal-title" id="rp-hide-title">¿Retirar este producto?</h5>
            <p class="report-product-lead">Esta acción se puede deshacer.</p>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div class="ad-confirm-item">
          <span class="ad-confirm-media" data-rp-hide="shot" aria-hidden="true">
            <?= lucide_icon('image-off', '', 22) ?>
          </span>
          <div class="ad-confirm-copy">
            <p class="ad-confirm-name" data-rp-hide="name"></p>
            <div class="ad-confirm-meta">
              <strong class="ad-confirm-price" data-rp-hide="price"></strong>
              <span><?= lucide_icon('store', '', 13) ?><span data-rp-hide="store"></span></span>
              <span><?= lucide_icon('hash', '', 13) ?><span data-rp-hide="label"></span></span>
            </div>
          </div>
        </div>

        <ul class="ad-confirm-list">
          <li>
            <?= lucide_icon('search-x', '', 16) ?>
            <span>Deja de aparecer en <strong>búsquedas y comparaciones</strong>.</span>
          </li>
          <li>
            <?= lucide_icon('check-check', '', 16) ?>
            <span data-rp-hide="reports"></span>
          </li>
          <li>
            <?= lucide_icon('eye', '', 16) ?>
            <span>Puedes <strong>volver a publicarlo</strong> cuando quieras.</span>
          </li>
        </ul>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger"><?= lucide_icon('eye-off', '', 16) ?>Retirar producto</button>
      </div>
    </form>
  </div>
</div>

<script src="./js/menu-portal.js?v=<?= filemtime(__DIR__ . '/js/menu-portal.js') ?>"></script>
<script>
const minSelect = document.querySelector('[data-rp-min]');
if (minSelect) {
  minSelect.addEventListener('change', function() {
    minSelect.parentElement.querySelector('.ap-rows-select-value').textContent = minSelect.value;
    minSelect.form.requestSubmit();
  });
}

const alertsModal = document.getElementById('rpAlertsModal');
if (alertsModal) {
  const slot = function(name) { return alertsModal.querySelector('[data-rp-alerts="' + name + '"]'); };

  alertsModal.addEventListener('show.bs.modal', function(event) {
    const d = event.relatedTarget ? event.relatedTarget.dataset : {};
    const template = document.getElementById(d.template || '');

    slot('summary').textContent = d.summary || '';
    alertsModal.querySelectorAll('[data-rp-alerts="id"]').forEach(function(input) { input.value = d.id || ''; });
    slot('review').hidden = parseInt(d.pending || '0', 10) === 0;

    slot('list').replaceChildren(template ? template.content.cloneNode(true) : '');

    if (typeof window.refreshLucideIcons === 'function') {
      window.refreshLucideIcons();
    }
  });
}

const hideModal = document.getElementById('rpHideModal');
if (hideModal) {
  const field = function(name) { return hideModal.querySelector('[data-rp-hide="' + name + '"]'); };

  hideModal.addEventListener('show.bs.modal', function(event) {
    const d = event.relatedTarget ? event.relatedTarget.dataset : {};
    const reports = parseInt(d.reports || '0', 10);
    const shot = field('shot');

    field('id').value = d.id || '';
    field('label').textContent = d.id || '';
    field('name').textContent = d.name || '';
    field('price').textContent = d.price || '';
    field('price').hidden = !d.price;
    field('store').textContent = d.store || '';
    field('reports').innerHTML = reports === 1
      ? 'Su <strong>alerta</strong> queda marcada como revisada.'
      : 'Sus <strong>' + reports.toLocaleString('es-PY') + ' alertas</strong> quedan marcadas como revisadas.';

    shot.classList.remove('has-img');
    shot.querySelectorAll('img').forEach(function(img) { img.remove(); });
    if (d.image) {
      const img = new Image();
      img.alt = '';
      img.dataset.noFallback = 'true';
      img.onload = function() { shot.classList.add('has-img'); };
      img.onerror = function() { img.remove(); };
      img.src = d.image;
      shot.appendChild(img);
    }
  });
}
</script>

<?php render_footer(); ?>
