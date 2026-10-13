<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

const AR_ESTADOS = [
    'pendiente' => ['label' => 'Por revisar', 'single' => 'Pendiente', 'icon' => 'clock', 'tone' => 'var(--accent)', 'hint' => 'Esperan una decisión'],
    'revisado' => ['label' => 'Revisados', 'single' => 'Revisado', 'icon' => 'circle-check', 'tone' => 'var(--success)', 'hint' => 'Reporte atendido'],
    'descartado' => ['label' => 'Descartados', 'single' => 'Descartado', 'icon' => 'circle-x', 'tone' => 'var(--text-soft)', 'hint' => 'Sin fundamento'],
    'todos' => ['label' => 'Todos', 'single' => 'Todos', 'icon' => 'list', 'tone' => 'var(--brand)', 'hint' => 'Historial completo'],
];
const AR_MOTIVOS = [
    'spam' => ['label' => 'Spam', 'icon' => 'mail-warning', 'tone' => 'var(--accent-2)'],
    'ofensivo' => ['label' => 'Ofensivo', 'icon' => 'angry', 'tone' => 'var(--danger)'],
    'informacion_falsa' => ['label' => 'Información falsa', 'icon' => 'badge-alert', 'tone' => 'var(--accent)'],
    'lenguaje_inapropiado' => ['label' => 'Lenguaje inapropiado', 'icon' => 'message-square-warning', 'tone' => 'var(--brand)'],
    'otro' => ['label' => 'Otro motivo', 'icon' => 'circle-help', 'tone' => 'var(--text-soft)'],
];
const AR_CASO_ICONOS = [
    'pendiente' => 'clock-alert',
    'revisado' => 'badge-check',
    'descartado' => 'circle-slash',
];
const AR_ORDENES = [
    'mas_reportadas' => ['label' => 'Más reportadas', 'icon' => 'arrow-down-wide-narrow'],
    'mas_recientes' => ['label' => 'Recientes', 'icon' => 'clock'],
    'menos_reportadas' => ['label' => 'Menos reportadas', 'icon' => 'arrow-up-narrow-wide'],
    'tienda_asc' => ['label' => 'Tienda', 'icon' => 'arrow-down-a-z'],
    'estado' => ['label' => 'Estado', 'icon' => 'list-checks'],
];
const AR_MINIMOS = [1, 2, 3, 5, 10];
const AR_AVISOS = [
    'mark_reviewed' => 'Reporte marcado como revisado.',
    'mark_discarded' => 'Reporte descartado.',
    'mark_all_reviewed' => 'Todos los reportes de la reseña quedaron revisados.',
    'mark_all_discarded' => 'Se descartaron todos los reportes de la reseña.',
    'hide_review' => 'La reseña quedó oculta y sus reportes se marcaron como revisados.',
    'restore_review' => 'La reseña vuelve a estar visible en la tienda.',
];

$status = array_key_exists((string) ($_GET['estado'] ?? ''), AR_ESTADOS) ? (string) $_GET['estado'] : 'pendiente';
$q = trim((string) ($_GET['q'] ?? ''));
$minReports = max(1, (int) ($_GET['min_reportes'] ?? 1));
$order = array_key_exists((string) ($_GET['orden'] ?? ''), AR_ORDENES) ? (string) $_GET['orden'] : 'mas_reportadas';
$motivo = array_key_exists((string) ($_GET['motivo'] ?? ''), AR_MOTIVOS) ? (string) $_GET['motivo'] : '';
$state = ['estado' => $status, 'q' => $q, 'min_reportes' => $minReports, 'orden' => $order, 'motivo' => $motivo];

function h(?string $value): string
{
    return e((string) ($value ?? ''));
}

function ar_url(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);
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
    return 'admin_reviews.php' . ($query !== '' ? '?' . $query : '');
}

function ar_ago(?string $date): string
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

function ar_date(?string $date): string
{
    $ts = $date ? strtotime($date) : false;
    return $ts === false ? '' : date('d/m/Y H:i', $ts);
}

function ar_motivo_key(string $reason): string
{
    return array_key_exists($reason, AR_MOTIVOS) ? $reason : 'otro';
}

function ar_initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name)) ?: [];
    $initials = mb_strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
    return $initials !== '' ? $initials : '?';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $reviewId = (int) ($_POST['review_id'] ?? 0);
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $done = '';

    if ($action === 'mark_reviewed' && $reportId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'revisado'
            WHERE idreporte = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $reportId]);
        $done = $action;
    } elseif ($action === 'mark_discarded' && $reportId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'descartado'
            WHERE idreporte = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $reportId]);
        $done = $action;
    } elseif ($action === 'mark_all_reviewed' && $reviewId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'revisado'
            WHERE reviews_idreview = :review_id
        ");
        $stmt->execute([':review_id' => $reviewId]);
        $done = $action;
    } elseif ($action === 'mark_all_discarded' && $reviewId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'descartado'
            WHERE reviews_idreview = :review_id
        ");
        $stmt->execute([':review_id' => $reviewId]);
        $done = $action;
    } elseif ($action === 'hide_review' && $reviewId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_reviews
            SET rev_activo = 0
            WHERE idreview = :review_id
            LIMIT 1
        ");
        $stmt->execute([':review_id' => $reviewId]);

        $stmt2 = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'revisado'
            WHERE reviews_idreview = :review_id
        ");
        $stmt2->execute([':review_id' => $reviewId]);
        $done = $action;
    } elseif ($action === 'restore_review' && $reviewId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_reviews
            SET rev_activo = 1
            WHERE idreview = :review_id
            LIMIT 1
        ");
        $stmt->execute([':review_id' => $reviewId]);
        $done = $action;
    }

    header('Location: ' . ar_url($state, ['ok' => $done]));
    exit;
}

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total_reportes,
        COUNT(DISTINCT reviews_idreview) AS total_reviews_reportadas,
        SUM(CASE WHEN rep_estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN rep_estado = 'revisado' THEN 1 ELSE 0 END) AS revisados,
        SUM(CASE WHEN rep_estado = 'descartado' THEN 1 ELSE 0 END) AS descartados,
        MIN(CASE WHEN rep_estado = 'pendiente' THEN rep_fecha END) AS pendiente_mas_antiguo
    FROM tienda_review_reportes
")->fetch();

$hiddenReviews = (int) $pdo->query("
    SELECT COUNT(DISTINCT tr.idreview)
    FROM tienda_reviews tr
    INNER JOIN tienda_review_reportes rr ON rr.reviews_idreview = tr.idreview
    WHERE tr.rev_activo = 0
")->fetchColumn();

$topStore = $pdo->query("
    SELECT t.tie_nombre, COUNT(*) AS total
    FROM tienda_review_reportes rr
    INNER JOIN tienda_reviews tr ON tr.idreview = rr.reviews_idreview
    INNER JOIN tiendas t ON t.idtiendas = tr.tiendas_idtiendas
    WHERE rr.rep_estado = 'pendiente'
    GROUP BY t.idtiendas, t.tie_nombre
    ORDER BY total DESC, t.tie_nombre ASC
    LIMIT 1
")->fetch() ?: null;

$reasonTotals = array_fill_keys(array_keys(AR_MOTIVOS), ['total' => 0, 'pendientes' => 0]);
$reasonRows = $pdo->query("
    SELECT rep_motivo, COUNT(*) AS total, SUM(CASE WHEN rep_estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes
    FROM tienda_review_reportes
    GROUP BY rep_motivo
")->fetchAll();
foreach ($reasonRows as $row) {
    $key = ar_motivo_key((string) $row['rep_motivo']);
    $reasonTotals[$key]['total'] += (int) $row['total'];
    $reasonTotals[$key]['pendientes'] += (int) $row['pendientes'];
}

$where = [];
$params = [];

if ($status !== 'todos') {
    $where[] = "rr.rep_estado = :estado";
    $params[':estado'] = $status;
}

if ($motivo === 'otro') {
    $known = implode(', ', array_map(static fn($k) => $pdo->quote($k), array_keys(AR_MOTIVOS)));
    $where[] = "(rr.rep_motivo = 'otro' OR rr.rep_motivo NOT IN ({$known}))";
} elseif ($motivo !== '') {
    $where[] = "rr.rep_motivo = :motivo";
    $params[':motivo'] = $motivo;
}

if ($q !== '') {
    $searchColumns = [
        't.tie_nombre', 'tr.rev_nombre', 'tr.rev_comentario', 'rr.rep_nombre',
        'rr.rep_email', 'rr.rep_motivo', 'rr.rep_detalle', 'rr.rep_ip', 'rr.rep_session_id',
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

$having = "HAVING COUNT(rr.idreporte) >= :min_reportes";

$orderSql = match ($order) {
    'mas_recientes' => 'ultima_fecha_reporte DESC, cantidad_reportes DESC, tr.idreview DESC',
    'menos_reportadas' => 'cantidad_reportes ASC, ultima_fecha_reporte DESC, tr.idreview DESC',
    'tienda_asc' => 't.tie_nombre ASC, cantidad_reportes DESC, ultima_fecha_reporte DESC',
    'estado' => "CASE
        WHEN SUM(CASE WHEN rr.rep_estado = 'pendiente' THEN 1 ELSE 0 END) > 0 THEN 0
        WHEN SUM(CASE WHEN rr.rep_estado = 'revisado' THEN 1 ELSE 0 END) > 0 THEN 1
        ELSE 2
    END ASC, cantidad_reportes DESC, ultima_fecha_reporte DESC",
    default => 'cantidad_reportes DESC, ultima_fecha_reporte DESC, tr.idreview DESC',
};

$sql = "
    SELECT
        tr.idreview,
        tr.rev_nombre,
        tr.rev_puntaje,
        tr.rev_comentario,
        tr.rev_fecha,
        tr.rev_activo,
        t.idtiendas,
        t.tie_nombre,
        COUNT(rr.idreporte) AS cantidad_reportes,
        MAX(rr.rep_fecha) AS ultima_fecha_reporte,
        SUM(CASE WHEN rr.rep_estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes_count,
        SUM(CASE WHEN rr.rep_estado = 'revisado' THEN 1 ELSE 0 END) AS revisados_count,
        SUM(CASE WHEN rr.rep_estado = 'descartado' THEN 1 ELSE 0 END) AS descartados_count,
        GROUP_CONCAT(rr.idreporte ORDER BY rr.rep_fecha DESC SEPARATOR ',') AS report_ids
    FROM tienda_review_reportes rr
    INNER JOIN tienda_reviews tr ON tr.idreview = rr.reviews_idreview
    INNER JOIN tiendas t ON t.idtiendas = tr.tiendas_idtiendas
    {$whereSql}
    GROUP BY
        tr.idreview,
        tr.rev_nombre,
        tr.rev_puntaje,
        tr.rev_comentario,
        tr.rev_fecha,
        tr.rev_activo,
        t.idtiendas,
        t.tie_nombre
    {$having}
    ORDER BY {$orderSql}
";

$params[':min_reportes'] = $minReports;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviewGroups = $stmt->fetchAll();

$allReportIds = [];
foreach ($reviewGroups as $group) {
    if (!empty($group['report_ids'])) {
        foreach (explode(',', (string) $group['report_ids']) as $idValue) {
            $idValue = (int) $idValue;
            if ($idValue > 0) {
                $allReportIds[] = $idValue;
            }
        }
    }
}

$reportsByReview = [];
if ($allReportIds) {
    $placeholders = implode(',', array_fill(0, count($allReportIds), '?'));
    $detailStmt = $pdo->prepare("
        SELECT
            rr.*,
            tr.idreview,
            t.tie_nombre
        FROM tienda_review_reportes rr
        INNER JOIN tienda_reviews tr ON tr.idreview = rr.reviews_idreview
        INNER JOIN tiendas t ON t.idtiendas = tr.tiendas_idtiendas
        WHERE rr.idreporte IN ($placeholders)
        ORDER BY rr.rep_fecha DESC, rr.idreporte DESC
    ");
    $detailStmt->execute($allReportIds);

    foreach ($detailStmt->fetchAll() as $report) {
        $reviewKey = (int) $report['reviews_idreview'];
        $reportsByReview[$reviewKey][] = $report;
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
$reviewTotal = (int) ($stats['total_reviews_reportadas'] ?? 0);
$sharePct = static fn(int $n): int => $reportTotal > 0 ? (int) round($n / $reportTotal * 100) : 0;
$resolvedPct = $sharePct($stateCounts['revisado'] + $stateCounts['descartado']);
$reasonMax = max(1, ...array_column($reasonTotals, 'total'));
$resultTotal = count($reviewGroups);

$minOptions = AR_MINIMOS;
if (!in_array($minReports, $minOptions, true)) {
    $minOptions[] = $minReports;
    sort($minOptions);
}

$ok = (string) ($_GET['ok'] ?? '');
$flash = isset(AR_AVISOS[$ok]) ? ['type' => 'success', 'message' => AR_AVISOS[$ok]] : null;

render_head('Gestión de reseñas reportadas');
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

<section class="ad-page ar-page" aria-labelledby="ar-title">
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
          <span class="pd-crumb-current">Reseñas reportadas</span>
        </li>
      </ol>
    </nav>

    <div class="ad-board">

      <header class="ad-panel ar-court animate-reveal-up">
        <div class="ar-court-top">
          <div class="ar-brief">
            <div class="ar-brief-head">
              <div class="ar-brief-copy">
                <span class="ar-tag"><?= lucide_icon('message-square-warning', '', 14) ?>Moderación de opiniones</span>
                <h1 id="ar-title" class="ar-title">Reseñas reportadas</h1>
              </div>
            </div>
            <p class="ar-lead">
              Cada reseña denunciada abre un expediente. Lee la opinión, pesa los motivos de quienes la reportaron
              y decide: se queda visible, se oculta de la tienda o los reportes se archivan.
            </p>

            <ul class="ar-facts">
              <li class="ar-fact <?= $stats['pendiente_mas_antiguo'] ? 'is-warn' : 'is-ok' ?>">
                <span class="ar-fact-icon" aria-hidden="true"><?= lucide_icon($stats['pendiente_mas_antiguo'] ? 'timer' : 'check', '', 16) ?></span>
                <span class="ar-fact-copy">
                  <span class="ar-fact-label">Pendiente más antiguo</span>
                  <?php if ($stats['pendiente_mas_antiguo']): ?>
                    <strong title="<?= h(ar_date($stats['pendiente_mas_antiguo'])) ?>">Reportado <?= h(ar_ago($stats['pendiente_mas_antiguo'])) ?></strong>
                  <?php else: ?>
                    <strong>Bandeja al día</strong>
                  <?php endif; ?>
                </span>
              </li>
              <li>
                <?php if ($topStore): ?>
                  <a href="<?= e(ar_url($state, ['q' => $topStore['tie_nombre'], 'estado' => 'pendiente'])) ?>" class="ar-fact is-link" title="Ver sus reseñas pendientes">
                    <span class="ar-fact-icon" aria-hidden="true"><?= lucide_icon('store', '', 16) ?></span>
                    <span class="ar-fact-copy">
                      <span class="ar-fact-label">Tienda más denunciada</span>
                      <strong title="<?= h($topStore['tie_nombre']) ?>"><?= h($topStore['tie_nombre']) ?></strong>
                    </span>
                    <span class="ar-fact-badge"><?= $fmt((int) $topStore['total']) ?></span>
                  </a>
                <?php else: ?>
                  <span class="ar-fact is-ok">
                    <span class="ar-fact-icon" aria-hidden="true"><?= lucide_icon('store', '', 16) ?></span>
                    <span class="ar-fact-copy">
                      <span class="ar-fact-label">Tienda más denunciada</span>
                      <strong>Sin pendientes</strong>
                    </span>
                  </span>
                <?php endif; ?>
              </li>
              <li class="ar-fact">
                <span class="ar-fact-icon" aria-hidden="true"><?= lucide_icon('eye-off', '', 16) ?></span>
                <span class="ar-fact-copy">
                  <span class="ar-fact-label">Ocultas por moderación</span>
                  <strong><?= $fmt($hiddenReviews) ?> de <?= $fmt($reviewTotal) ?> <?= $reviewTotal === 1 ? 'reseña' : 'reseñas' ?></strong>
                </span>
              </li>
            </ul>
          </div>

          <aside class="ar-verdict" aria-labelledby="ar-verdict-title">
            <div class="ar-verdict-head">
              <span id="ar-verdict-title" class="ar-verdict-label"><?= lucide_icon('gavel', '', 14) ?>Reportes resueltos</span>
              <p class="ar-verdict-value"><?= $resolvedPct ?><small>%</small></p>
            </div>
            <div class="ar-balance" role="img" aria-label="<?= $resolvedPct ?>% de los reportes ya tienen una decisión">
              <?php foreach (['revisado', 'descartado', 'pendiente'] as $key): ?>
                <?php if ($stateCounts[$key] > 0): ?>
                  <span style="flex-grow: <?= $stateCounts[$key] ?>; --s-c: <?= AR_ESTADOS[$key]['tone'] ?>"></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <ul class="ar-verdict-legend">
              <?php foreach (['revisado', 'descartado', 'pendiente'] as $key): ?>
                <li style="--s-c: <?= AR_ESTADOS[$key]['tone'] ?>">
                  <span class="ar-dot" aria-hidden="true"></span>
                  <span><?= e(AR_ESTADOS[$key]['label']) ?></span>
                  <strong><?= $sharePct($stateCounts[$key]) ?>%</strong>
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="ar-verdict-note">
              <?= lucide_icon('info', '', 13) ?>
              Ocultar una reseña marca todos sus reportes como revisados.
            </p>
          </aside>
        </div>

        <div class="ar-reasons">
          <h2 class="ar-reasons-title"><?= lucide_icon('flag', '', 14) ?>¿Por qué se denuncia?</h2>
          <ul class="ar-reasons-list">
            <?php foreach (AR_MOTIVOS as $key => $reason): ?>
              <?php
                $data = $reasonTotals[$key];
                $isActive = $motivo === $key;
                $width = (int) round($data['total'] / $reasonMax * 100);
              ?>
              <li>
                <a
                  href="<?= e(ar_url($state, ['motivo' => $isActive ? '' : $key])) ?>"
                  class="ar-reason <?= $isActive ? 'is-active' : '' ?> <?= $data['total'] === 0 ? 'is-empty' : '' ?>"
                  style="--m-c: <?= $reason['tone'] ?>"
                  title="<?= $isActive ? 'Quitar filtro' : 'Ver solo reportes por ' . e(mb_strtolower($reason['label'])) ?>"
                  <?= $isActive ? 'aria-current="true"' : '' ?>
                >
                  <span class="ar-reason-icon" aria-hidden="true"><?= lucide_icon($reason['icon'], '', 16) ?></span>
                  <span class="ar-reason-copy">
                    <span class="ar-reason-name"><?= e($reason['label']) ?></span>
                    <span class="ar-reason-meta">
                      <?php if ($data['pendientes'] > 0): ?>
                        <?= $fmt($data['pendientes']) ?> sin revisar
                      <?php else: ?>
                        Nada pendiente
                      <?php endif; ?>
                    </span>
                  </span>
                  <span class="ar-reason-count"><?= $fmt($data['total']) ?></span>
                  <span class="ar-reason-meter" aria-hidden="true"><span style="width: <?= $width ?>%"></span></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </header>

      <section class="ad-panel ar-docket animate-reveal-up" aria-labelledby="ar-list-title" style="--lane-accent: <?= AR_ESTADOS[$status]['tone'] ?>">
        <header class="ad-panel-head ar-docket-head">
          <span class="ad-panel-icon" aria-hidden="true"><?= lucide_icon('folder-open', '', 18) ?></span>
          <div>
            <h2 id="ar-list-title" class="ad-panel-title">Expedientes</h2>
            <p class="ad-panel-caption">
              <?php if ($reviewGroups): ?>
                <?= $fmt($resultTotal) ?> <?= $resultTotal === 1 ? 'reseña' : 'reseñas' ?> · por <?= e(mb_strtolower(AR_ORDENES[$order]['label'])) ?>
              <?php else: ?>
                Ninguna reseña coincide
              <?php endif; ?>
            </p>
          </div>

          <form method="get" class="at-search" role="search">
            <?php foreach (['estado' => $status !== 'pendiente' ? $status : '', 'orden' => $order !== 'mas_reportadas' ? $order : '', 'motivo' => $motivo, 'min_reportes' => $minReports > 1 ? (string) $minReports : ''] as $name => $value): ?>
              <?php if ($value !== ''): ?><input type="hidden" name="<?= $name ?>" value="<?= e($value) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <?= lucide_icon('search', 'at-search-icon', 16) ?>
            <label for="ar-q" class="visually-hidden">Buscar en los reportes</label>
            <input type="search" id="ar-q" name="q" class="at-search-input" placeholder="Tienda, reseña, reportante, IP…" value="<?= e($q) ?>" autocomplete="off">
            <?php if ($q !== ''): ?>
              <a href="<?= e(ar_url($state, ['q' => ''])) ?>" class="at-search-clear" aria-label="Borrar búsqueda" title="Borrar búsqueda"><?= lucide_icon('x', '', 14) ?></a>
            <?php endif; ?>
            <button type="submit" class="at-search-go" aria-label="Buscar"><?= lucide_icon('arrow-right', '', 15) ?></button>
          </form>
        </header>

        <nav class="ar-tabs" aria-label="Estado de los reportes">
          <?php foreach (AR_ESTADOS as $key => $estado): ?>
            <a
              href="<?= e(ar_url($state, ['estado' => $key])) ?>"
              class="ar-tab <?= $status === $key ? 'is-active' : '' ?>"
              style="--s-c: <?= $estado['tone'] ?>"
              <?= $status === $key ? 'aria-current="page"' : '' ?>
            >
              <span class="ar-tab-icon" aria-hidden="true"><?= lucide_icon($estado['icon'], '', 16) ?></span>
              <span class="ar-tab-copy">
                <span class="ar-tab-name"><?= e($estado['label']) ?></span>
                <span class="ar-tab-hint"><?= e($estado['hint']) ?></span>
              </span>
              <span class="ar-tab-count"><?= $fmt($stateCounts[$key]) ?></span>
            </a>
          <?php endforeach; ?>
        </nav>

        <div class="ar-sheet">
          <div class="at-bar">
            <nav class="at-sort" aria-label="Ordenar reseñas">
              <?php foreach (AR_ORDENES as $key => $option): ?>
                <a
                  href="<?= e(ar_url($state, ['orden' => $key])) ?>"
                  class="at-sort-item <?= $order === $key ? 'is-active' : '' ?>"
                  <?= $order === $key ? 'aria-current="true"' : '' ?>
                >
                  <?= lucide_icon($option['icon'], '', 14) ?><?= e($option['label']) ?>
                </a>
              <?php endforeach; ?>
            </nav>

            <div class="ar-bar-end">
              <?php if ($motivo !== ''): ?>
                <a href="<?= e(ar_url($state, ['motivo' => ''])) ?>" class="at-chip ar-chip" style="--m-c: <?= AR_MOTIVOS[$motivo]['tone'] ?>" title="Quitar filtro">
                  <?= lucide_icon(AR_MOTIVOS[$motivo]['icon'], '', 13) ?><?= e(AR_MOTIVOS[$motivo]['label']) ?><?= lucide_icon('x', '', 13) ?>
                </a>
              <?php endif; ?>

              <form method="get" class="ar-min-form">
                <?php foreach (['estado' => $status !== 'pendiente' ? $status : '', 'q' => $q, 'orden' => $order !== 'mas_reportadas' ? $order : '', 'motivo' => $motivo] as $name => $value): ?>
                  <?php if ($value !== ''): ?><input type="hidden" name="<?= $name ?>" value="<?= e($value) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <label class="ap-rows-select <?= $minReports > 1 ? 'is-set' : '' ?>" title="Mínimo de reportes por reseña">
                  <?= lucide_icon('flag', 'ap-rows-select-icon', 15) ?>
                  <span class="ap-rows-select-label">Mín. reportes</span>
                  <span class="ap-rows-select-value" aria-hidden="true"><?= $minReports ?></span>
                  <select name="min_reportes" class="ap-rows-select-input" aria-label="Mínimo de reportes por reseña" data-ar-min>
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

          <?php if ($reviewGroups): ?>
            <ul class="rp-items">
              <?php foreach ($reviewGroups as $item): ?>
                <?php
                  $reviewId = (int) $item['idreview'];
                  $isVisible = (int) $item['rev_activo'] === 1;
                  $score = max(0, min(5, (int) $item['rev_puntaje']));
                  $reportList = $reportsByReview[$reviewId] ?? [];
                  $caseCounts = [
                      'pendiente' => (int) $item['pendientes_count'],
                      'revisado' => (int) $item['revisados_count'],
                      'descartado' => (int) $item['descartados_count'],
                  ];
                  $caseTotal = (int) $item['cantidad_reportes'];
                  $caseState = $caseCounts['pendiente'] > 0 ? 'pendiente' : ($caseCounts['revisado'] > 0 ? 'revisado' : 'descartado');
                  $caseLabel = match ($caseState) {
                      'pendiente' => $fmt($caseCounts['pendiente']) . ' por revisar',
                      'revisado' => 'Atendida',
                      default => 'Descartada',
                  };
                  $caseReasons = [];
                  foreach ($reportList as $report) {
                      $key = ar_motivo_key((string) $report['rep_motivo']);
                      $caseReasons[$key] = ($caseReasons[$key] ?? 0) + 1;
                  }
                  arsort($caseReasons);
                  $initials = ar_initials((string) $item['rev_nombre']);
                ?>
                <li class="rp-item ar-card <?= $isVisible ? '' : 'is-off' ?>" style="--s-c: <?= AR_ESTADOS[$caseState]['tone'] ?>">
                  <article class="ar-review" aria-label="Reseña #<?= $reviewId ?>">
                    <header class="ar-review-head">
                      <span class="ar-avatar" aria-hidden="true"><?= e($initials) ?></span>
                      <div class="ar-review-who">
                        <h3 class="ar-review-name" title="<?= h($item['rev_nombre']) ?>"><?= h($item['rev_nombre']) ?></h3>
                        <a href="tienda.php?id=<?= (int) $item['idtiendas'] ?>" class="ar-review-store" target="_blank" rel="noopener" title="Abrir la tienda"><?= lucide_icon('store', '', 14) ?><span><?= h($item['tie_nombre']) ?></span></a>
                      </div>
                      <div class="ar-card-side">
                        <?php if (!$isVisible): ?>
                          <span class="rp-off-tag"><?= lucide_icon('eye-off', '', 12) ?>Oculta</span>
                        <?php endif; ?>
                        <div class="dropdown rp-menu">
                          <button type="button" class="rp-menu-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Más acciones para la reseña de <?= h($item['rev_nombre']) ?>">
                            <?= lucide_icon('ellipsis', '', 16) ?>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end rp-menu-list">
                            <li><a class="dropdown-item" href="tienda.php?id=<?= (int) $item['idtiendas'] ?>" target="_blank" rel="noopener"><?= lucide_icon('store', '', 15) ?>Ver en la tienda</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if ($caseCounts['revisado'] < $caseTotal): ?>
                              <li>
                                <form method="post">
                                  <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                                  <input type="hidden" name="action" value="mark_all_reviewed">
                                  <button type="submit" class="dropdown-item"><?= lucide_icon('circle-check', '', 15) ?><?= $caseTotal === 1 ? 'Marcar como revisado' : 'Revisar todos' ?></button>
                                </form>
                              </li>
                            <?php endif; ?>
                            <?php if ($caseCounts['descartado'] < $caseTotal): ?>
                              <li>
                                <form method="post">
                                  <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                                  <input type="hidden" name="action" value="mark_all_discarded">
                                  <button type="submit" class="dropdown-item"><?= lucide_icon('archive-x', '', 15) ?>Descartar <?= $caseTotal === 1 ? 'el reporte' : 'todos' ?></button>
                                </form>
                              </li>
                            <?php endif; ?>
                            <li>
                              <?php if ($isVisible): ?>
                                <form method="post" onsubmit="return confirm('¿Ocultar esta reseña de la página de la tienda?');">
                                  <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                                  <input type="hidden" name="action" value="hide_review">
                                  <button type="submit" class="dropdown-item is-danger"><?= lucide_icon('eye-off', '', 15) ?>Ocultar reseña</button>
                                </form>
                              <?php else: ?>
                                <form method="post">
                                  <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                                  <input type="hidden" name="action" value="restore_review">
                                  <button type="submit" class="dropdown-item"><?= lucide_icon('eye', '', 15) ?>Volver a mostrar</button>
                                </form>
                              <?php endif; ?>
                            </li>
                          </ul>
                        </div>
                      </div>
                    </header>
                    <blockquote class="ar-review-text" title="<?= h($item['rev_comentario']) ?>"><?= h($item['rev_comentario']) ?></blockquote>
                  </article>

                  <div class="ar-card-row">
                    <div class="ar-stars" role="img" aria-label="Puntaje <?= $score ?> de 5">
                      <?php for ($i = 1; $i <= 5; $i++): ?>
                        <?= lucide_icon('star', $i <= $score ? 'is-on' : '', 17) ?>
                      <?php endfor; ?>
                    </div>
                    <?php if ($caseReasons): ?>
                      <?php
                        $mainKey = array_key_first($caseReasons);
                        $moreReasons = array_slice($caseReasons, 1, null, true);
                      ?>
                      <ul class="ar-tags" aria-label="Motivos denunciados">
                        <li style="--m-c: <?= AR_MOTIVOS[$mainKey]['tone'] ?>"><?= lucide_icon(AR_MOTIVOS[$mainKey]['icon'], '', 12) ?><?= e(AR_MOTIVOS[$mainKey]['label']) ?><?php if ($caseReasons[$mainKey] > 1): ?><em>×<?= $caseReasons[$mainKey] ?></em><?php endif; ?></li>
                        <?php if ($moreReasons): ?>
                          <li class="is-more" title="<?= e(implode(', ', array_map(static fn($k, $c) => AR_MOTIVOS[$k]['label'] . ($c > 1 ? ' ×' . $c : ''), array_keys($moreReasons), $moreReasons))) ?>">+<?= count($moreReasons) ?></li>
                        <?php endif; ?>
                      </ul>
                    <?php endif; ?>
                  </div>

                  <footer class="rp-card-foot ar-card-foot">
                    <span class="rp-case" title="Último reporte: <?= h(ar_date($item['ultima_fecha_reporte'])) ?>">
                      <strong><?= lucide_icon(AR_CASO_ICONOS[$caseState], '', 13) ?><?= e($caseLabel) ?></strong>
                      <small>Último reporte <?= h(ar_ago($item['ultima_fecha_reporte'])) ?></small>
                    </span>
                    <button
                      type="button"
                      class="rp-btn <?= $caseCounts['pendiente'] > 0 ? 'is-alert' : '' ?>"
                      data-bs-toggle="modal"
                      data-bs-target="#arReportsModal"
                      data-template="ar-reports-<?= $reviewId ?>"
                      data-id="<?= $reviewId ?>"
                      data-summary="<?= h($fmt($caseTotal) . ($caseTotal === 1 ? ' reporte' : ' reportes') . ' · ' . mb_strtolower($caseLabel)) ?>"
                      data-reviewable="<?= $caseTotal - $caseCounts['revisado'] ?>"
                      data-discardable="<?= $caseTotal - $caseCounts['descartado'] ?>"
                    >
                      <?= lucide_icon('message-square', '', 15) ?><?= $caseTotal === 1 ? 'Ver reporte' : 'Ver ' . $fmt($caseTotal) . ' reportes' ?>
                    </button>
                  </footer>

                  <template id="ar-reports-<?= $reviewId ?>">
                    <article class="ar-review is-full" aria-label="Reseña #<?= $reviewId ?>">
                      <header class="ar-review-head">
                        <span class="ar-avatar" aria-hidden="true"><?= e($initials) ?></span>
                        <div class="ar-review-who">
                          <div class="ar-review-line">
                            <p class="ar-review-name"><?= h($item['rev_nombre']) ?></p>
                            <div class="ar-stars" role="img" aria-label="Puntaje <?= $score ?> de 5">
                              <?php for ($i = 1; $i <= 5; $i++): ?>
                                <?= lucide_icon('star', $i <= $score ? 'is-on' : '', 15) ?>
                              <?php endfor; ?>
                            </div>
                          </div>
                          <div class="ar-review-meta">
                            <a href="tienda.php?id=<?= (int) $item['idtiendas'] ?>" class="ar-review-store" target="_blank" rel="noopener" title="Abrir la tienda"><?= lucide_icon('store', '', 13) ?><span><?= h($item['tie_nombre']) ?></span></a>
                            <span title="<?= h(ar_date($item['rev_fecha'])) ?>"><?= lucide_icon('calendar', '', 12) ?><?= h(date('d/m/Y', strtotime((string) $item['rev_fecha']) ?: time())) ?></span>
                            <span><?= lucide_icon('hash', '', 12) ?><?= $reviewId ?></span>
                          </div>
                        </div>
                      </header>
                      <blockquote class="ar-review-text"><?= h($item['rev_comentario']) ?></blockquote>
                    </article>
                    <p class="ar-log-title"><?= lucide_icon('flag', '', 13) ?><?= count($reportList) === 1 ? 'Reporte recibido' : 'Reportes recibidos' ?><span><?= $fmt(count($reportList)) ?></span></p>
                    <?php if ($reportList): ?>
                      <ol class="rp-log">
                        <?php foreach ($reportList as $report): ?>
                          <?php
                            $reportId = (int) $report['idreporte'];
                            $reason = AR_MOTIVOS[ar_motivo_key((string) $report['rep_motivo'])];
                            $reportState = array_key_exists((string) $report['rep_estado'], AR_ESTADOS) ? (string) $report['rep_estado'] : 'pendiente';
                            $session = (string) ($report['rep_session_id'] ?? '');
                            $detail = trim((string) ($report['rep_detalle'] ?? ''));
                          ?>
                          <li class="rp-entry <?= $reportState === 'pendiente' ? '' : 'is-closed' ?>" style="--m-c: <?= $reason['tone'] ?>; --s-c: <?= AR_ESTADOS[$reportState]['tone'] ?>">
                            <header class="rp-entry-head">
                              <span class="rp-entry-node" aria-hidden="true"><?= lucide_icon($reason['icon'], '', 16) ?></span>
                              <div class="rp-entry-title">
                                <strong><?= e($reason['label']) ?></strong>
                                <time class="rp-entry-time" datetime="<?= h(date('c', strtotime((string) $report['rep_fecha']) ?: time())) ?>" title="<?= h(ar_date($report['rep_fecha'])) ?>"><?= h(ar_ago($report['rep_fecha'])) ?></time>
                              </div>
                              <div class="rp-entry-side">
                                <span class="rp-pill"><?= lucide_icon($reportState === 'pendiente' ? 'hourglass' : AR_CASO_ICONOS[$reportState], '', 12) ?><?= e(AR_ESTADOS[$reportState]['single']) ?></span>
                                <?php if ($reportState !== 'revisado'): ?>
                                  <form method="post">
                                    <input type="hidden" name="report_id" value="<?= $reportId ?>">
                                    <input type="hidden" name="action" value="mark_reviewed">
                                    <button type="submit" class="rp-act is-ok" aria-label="Marcar reporte #<?= $reportId ?> como revisado" title="Marcar como revisado"><?= lucide_icon('check', '', 14) ?></button>
                                  </form>
                                <?php endif; ?>
                                <?php if ($reportState !== 'descartado'): ?>
                                  <form method="post">
                                    <input type="hidden" name="report_id" value="<?= $reportId ?>">
                                    <input type="hidden" name="action" value="mark_discarded">
                                    <button type="submit" class="rp-act is-no" aria-label="Descartar reporte #<?= $reportId ?>" title="Descartar"><?= lucide_icon('x', '', 14) ?></button>
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
                                <li title="Número de reporte"><?= lucide_icon('hash', '', 12) ?><?= $reportId ?></li>
                              </ul>
                            </footer>
                          </li>
                        <?php endforeach; ?>
                      </ol>
                    <?php else: ?>
                      <p class="rp-case-empty"><?= lucide_icon('file-search', '', 16) ?>No hay más detalles disponibles para esta reseña.</p>
                    <?php endif; ?>
                  </template>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <?php $isClean = $q === '' && $motivo === '' && $minReports === 1; ?>
            <div class="ar-empty">
              <span class="ar-empty-icon" aria-hidden="true"><?= lucide_icon($reportTotal === 0 ? 'message-square-quote' : ($status === 'pendiente' && $isClean ? 'shield-check' : 'search-x'), '', 20) ?></span>
              <?php if ($reportTotal === 0): ?>
                <p class="ar-empty-text">Todavía nadie reportó una reseña.</p>
              <?php elseif ($status === 'pendiente' && $isClean): ?>
                <p class="ar-empty-text">Nada por moderar: todas las reseñas reportadas ya tienen una decisión.</p>
                <a href="<?= e(ar_url($state, ['estado' => 'todos'])) ?>" class="ar-empty-action"><?= lucide_icon('layers', '', 14) ?>Ver el historial completo</a>
              <?php else: ?>
                <p class="ar-empty-text"><?= $q !== '' ? 'Ningún expediente coincide con “' . e($q) . '”.' : 'Ningún expediente cumple los filtros activos.' ?></p>
                <a href="admin_reviews.php" class="ar-empty-action"><?= lucide_icon('rotate-ccw', '', 14) ?>Quitar filtros</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

    </div>
  </div>
</section>

<div class="modal fade" id="arReportsModal" tabindex="-1" aria-labelledby="ar-reports-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content suggestion-modal report-product-modal ad-confirm rp-alerts-modal ar-reports-modal">
      <div class="modal-header">
        <div class="report-product-heading">
          <span class="report-product-icon" aria-hidden="true"><?= lucide_icon('message-square-warning', '', 18) ?></span>
          <div class="report-product-heading-text">
            <p class="ad-confirm-kicker">Moderación de opiniones</p>
            <h5 class="modal-title" id="ar-reports-title">Reportes de la reseña</h5>
            <p class="report-product-lead" data-ar-reports="summary"></p>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div data-ar-reports="list"></div>
      </div>

      <div class="modal-footer rp-alerts-foot">
        <form method="post" data-ar-reports="discard">
          <input type="hidden" name="review_id" value="" data-ar-reports="id">
          <input type="hidden" name="action" value="mark_all_discarded">
          <button type="submit" class="btn btn-outline-secondary"><?= lucide_icon('archive-x', '', 16) ?>Descartar todos</button>
        </form>
        <form method="post" data-ar-reports="review">
          <input type="hidden" name="review_id" value="" data-ar-reports="id">
          <input type="hidden" name="action" value="mark_all_reviewed">
          <button type="submit" class="btn btn-success"><?= lucide_icon('circle-check', '', 16) ?>Revisar todos</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="./js/menu-portal.js?v=<?= filemtime(__DIR__ . '/js/menu-portal.js') ?>"></script>
<script>
const minSelect = document.querySelector('[data-ar-min]');
if (minSelect) {
  minSelect.addEventListener('change', function() {
    minSelect.parentElement.querySelector('.ap-rows-select-value').textContent = minSelect.value;
    minSelect.form.requestSubmit();
  });
}

const reportsModal = document.getElementById('arReportsModal');
if (reportsModal) {
  const slot = function(name) { return reportsModal.querySelector('[data-ar-reports="' + name + '"]'); };

  reportsModal.addEventListener('show.bs.modal', function(event) {
    const d = event.relatedTarget ? event.relatedTarget.dataset : {};
    const template = document.getElementById(d.template || '');

    slot('summary').textContent = d.summary || '';
    reportsModal.querySelectorAll('[data-ar-reports="id"]').forEach(function(input) { input.value = d.id || ''; });
    slot('review').hidden = parseInt(d.reviewable || '0', 10) === 0;
    slot('discard').hidden = parseInt(d.discardable || '0', 10) === 0;

    slot('list').replaceChildren(template ? template.content.cloneNode(true) : '');

    if (typeof window.refreshLucideIcons === 'function') {
      window.refreshLucideIcons();
    }
  });
}
</script>

<?php render_footer(); ?>
