<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

const SG_ESTADOS = [
    'pendiente' => ['label' => 'Sin leer', 'single' => 'Sin leer', 'icon' => 'mail', 'tone' => 'var(--accent)', 'hint' => 'Recién llegadas al buzón'],
    'leido' => ['label' => 'Leídas', 'single' => 'Leída', 'icon' => 'mail-open', 'tone' => 'var(--success)', 'hint' => 'Ideas ya consideradas'],
    'archivado' => ['label' => 'Archivadas', 'single' => 'Archivada', 'icon' => 'archive', 'tone' => 'var(--text-soft)', 'hint' => 'Fuera de la bandeja'],
    'todos' => ['label' => 'Todas', 'single' => 'Todas', 'icon' => 'inbox', 'tone' => 'var(--brand)', 'hint' => 'Historial completo'],
];
const SG_ORDENES = [
    'recientes' => ['label' => 'Recientes', 'icon' => 'clock'],
    'antiguas' => ['label' => 'Más antiguas', 'icon' => 'history'],
    'remitente' => ['label' => 'Remitente', 'icon' => 'arrow-down-a-z'],
];
const SG_ACCIONES = [
    'mark_read' => 'leido',
    'archive' => 'archivado',
    'mark_pending' => 'pendiente',
];
const SG_AVISOS = [
    'mark_read' => 'Sugerencia marcada como leída.',
    'archive' => 'Sugerencia archivada.',
    'mark_pending' => 'La sugerencia vuelve a la bandeja de sin leer.',
    'delete' => 'Sugerencia eliminada definitivamente.',
];
const SG_TONOS = ['var(--brand)', 'var(--accent)', 'var(--success)', 'var(--danger)', 'var(--accent-2)', '#8a7fc4', '#4f9fa3', '#c4894f'];
const SG_DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const SG_INICIALES = ['D', 'L', 'M', 'X', 'J', 'V', 'S'];

$status = array_key_exists((string) ($_GET['estado'] ?? ''), SG_ESTADOS) ? (string) $_GET['estado'] : 'pendiente';
$q = trim((string) ($_GET['q'] ?? ''));
$order = array_key_exists((string) ($_GET['orden'] ?? ''), SG_ORDENES) ? (string) $_GET['orden'] : 'recientes';
$withEmail = ($_GET['contacto'] ?? '') === '1';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;
$state = [
    'estado' => $status,
    'q' => $q,
    'orden' => $order,
    'contacto' => $withEmail ? '1' : '',
];

function sg_url(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);
    if ((int) ($params['page'] ?? 1) <= 1) {
        $params['page'] = '';
    }
    if (($params['estado'] ?? '') === 'pendiente') {
        $params['estado'] = '';
    }
    if (($params['orden'] ?? '') === 'recientes') {
        $params['orden'] = '';
    }
    $query = http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null));
    return 'admin_sugerencias.php' . ($query !== '' ? '?' . $query : '');
}

function sg_ago(?string $date): string
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

function sg_date(?string $date): string
{
    $ts = $date ? strtotime($date) : false;
    return $ts === false ? '' : date('d/m/Y H:i', $ts);
}

function sg_tone(string $key): string
{
    return SG_TONOS[crc32(mb_strtolower(trim($key))) % count(SG_TONOS)];
}

function sg_initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $letters = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $letters .= mb_substr($word, 0, 1);
    }
    return mb_strtoupper($letters);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $id = (int) ($_POST['id'] ?? 0);
    $done = '';

    if ($id > 0 && $action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM sugerencias WHERE idsugerencia = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $done = $action;
    } elseif ($id > 0 && isset(SG_ACCIONES[$action])) {
        $stmt = $pdo->prepare("
            UPDATE sugerencias
            SET sug_estado = :estado
            WHERE idsugerencia = :id
            LIMIT 1
        ");
        $stmt->execute([
            ':estado' => SG_ACCIONES[$action],
            ':id' => $id,
        ]);
        $done = $action;
    }

    header('Location: ' . sg_url($state, ['ok' => $done, 'page' => $page]));
    exit;
}

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN sug_estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN sug_estado = 'leido' THEN 1 ELSE 0 END) AS leidas,
        SUM(CASE WHEN sug_estado = 'archivado' THEN 1 ELSE 0 END) AS archivadas,
        SUM(CASE WHEN sug_email IS NOT NULL AND sug_email <> '' THEN 1 ELSE 0 END) AS con_email,
        SUM(CASE WHEN sug_nombre IS NOT NULL AND sug_nombre <> '' THEN 1 ELSE 0 END) AS firmadas,
        MIN(CASE WHEN sug_estado = 'pendiente' THEN sug_fecha END) AS pendiente_mas_antigua,
        MAX(sug_fecha) AS ultima
    FROM sugerencias
")->fetch();

$flowCounts = [];
foreach ($pdo->query("
    SELECT DATE(sug_fecha) AS dia, COUNT(*) AS total
    FROM sugerencias
    WHERE sug_fecha >= CURDATE() - INTERVAL 13 DAY
    GROUP BY DATE(sug_fecha)
")->fetchAll() as $row) {
    $flowCounts[(string) $row['dia']] = (int) $row['total'];
}

$previousFlow = (int) $pdo->query("
    SELECT COUNT(*)
    FROM sugerencias
    WHERE sug_fecha >= CURDATE() - INTERVAL 27 DAY
      AND sug_fecha < CURDATE() - INTERVAL 13 DAY
")->fetchColumn();

$flowDays = [];
for ($i = 13; $i >= 0; $i--) {
    $ts = strtotime("-{$i} days");
    $key = date('Y-m-d', $ts);
    $flowDays[] = [
        'count' => $flowCounts[$key] ?? 0,
        'weekday' => (int) date('w', $ts),
        'date' => date('d/m', $ts),
        'today' => $i === 0,
    ];
}
$flowTotal = array_sum(array_column($flowDays, 'count'));
$flowMax = max(1, ...array_column($flowDays, 'count'));
$flowDelta = $previousFlow > 0 ? (int) round(($flowTotal - $previousFlow) / $previousFlow * 100) : null;

$where = [];
$params = [];

if ($status !== 'todos') {
    $where[] = 'sug_estado = :estado';
    $params[':estado'] = $status;
}

if ($withEmail) {
    $where[] = "sug_email IS NOT NULL AND sug_email <> ''";
}

if ($q !== '') {
    $searchConds = [];
    foreach (['sug_nombre', 'sug_email', 'sug_asunto', 'sug_detalle'] as $i => $column) {
        // Native prepares don't allow reusing a named placeholder.
        $searchConds[] = "{$column} LIKE :q{$i}";
        $params[":q{$i}"] = '%' . $q . '%';
    }
    $where[] = '(' . implode(' OR ', $searchConds) . ')';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderSql = match ($order) {
    'antiguas' => 'sug_fecha ASC, idsugerencia ASC',
    'remitente' => "(sug_nombre IS NULL OR sug_nombre = '') ASC, sug_nombre ASC, sug_fecha DESC",
    default => 'sug_fecha DESC, idsugerencia DESC',
};

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM sugerencias {$whereSql}");
$countStmt->execute($params);
$resultTotal = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($resultTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT *
    FROM sugerencias
    {$whereSql}
    ORDER BY {$orderSql}
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$suggestions = $stmt->fetchAll();

$pageItems = [];
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1) {
        $pageItems[] = $i;
    } elseif (end($pageItems) !== null) {
        $pageItems[] = null;
    }
}

$fmt = static fn(int|float $n): string => number_format($n, 0, ',', '.');

$stateCounts = [
    'pendiente' => (int) ($stats['pendientes'] ?? 0),
    'leido' => (int) ($stats['leidas'] ?? 0),
    'archivado' => (int) ($stats['archivadas'] ?? 0),
    'todos' => (int) ($stats['total'] ?? 0),
];
$total = $stateCounts['todos'];
$withEmailTotal = (int) ($stats['con_email'] ?? 0);
$signedTotal = (int) ($stats['firmadas'] ?? 0);
$pct = static fn(int $n): int => $total > 0 ? (int) round($n / $total * 100) : 0;

$ok = (string) ($_GET['ok'] ?? '');
$flash = isset(SG_AVISOS[$ok]) ? ['type' => 'success', 'message' => SG_AVISOS[$ok]] : null;

render_head('Sugerencias');
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

<section class="dash-page sg-page" aria-labelledby="sg-title">
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
          <span class="pd-crumb-current">Sugerencias</span>
        </li>
      </ol>
    </nav>

    <div class="dash-board">

      <header class="dash-panel sg-desk animate-reveal-up">
        <div class="sg-desk-top">
          <div class="sg-intro">
            <div class="sg-intro-head">
              <div class="sg-intro-copy">
                <span class="sg-tag"><?= lucide_icon('inbox', '', 14) ?>Buzón de la comunidad</span>
                <h1 id="sg-title" class="sg-title">Sugerencias de usuarios</h1>
              </div>
            </div>
            <p class="sg-lead">
              Ideas, mejoras y errores que la comunidad envía desde el pie de página.
              Léelas, responde a quien dejó su email y archiva lo que ya está resuelto.
            </p>

            <section class="sg-flow" aria-labelledby="sg-flow-title">
              <header class="sg-flow-head">
                <h2 id="sg-flow-title" class="sg-flow-title"><?= lucide_icon('activity', '', 14) ?>Llegadas de las últimas dos semanas</h2>
                <div class="sg-flow-sum">
                  <strong><?= $fmt($flowTotal) ?></strong>
                  <span><?= $flowTotal === 1 ? 'recibida' : 'recibidas' ?></span>
                  <?php if ($flowDelta !== null): ?>
                    <?php $trend = $flowDelta > 0 ? 'is-up' : ($flowDelta < 0 ? 'is-down' : ''); ?>
                    <span class="sg-trend <?= $trend ?>" title="Quincena anterior: <?= $fmt($previousFlow) ?>">
                      <?= lucide_icon($flowDelta > 0 ? 'trending-up' : ($flowDelta < 0 ? 'trending-down' : 'minus'), '', 13) ?><?= ($flowDelta > 0 ? '+' : '') . $flowDelta ?>%
                    </span>
                  <?php elseif ($flowTotal > 0): ?>
                    <span class="sg-trend is-up" title="La quincena anterior no llegó ninguna"><?= lucide_icon('sparkles', '', 13) ?>Nuevo</span>
                  <?php endif; ?>
                </div>
              </header>

              <ol class="sg-flow-bars">
                <?php foreach ($flowDays as $day): ?>
                  <li
                    class="<?= $day['today'] ? 'is-today' : '' ?> <?= $day['count'] === 0 ? 'is-zero' : '' ?>"
                    style="--h: <?= (int) round($day['count'] / $flowMax * 100) ?>%"
                    title="<?= e(SG_DIAS[$day['weekday']] . ' ' . $day['date']) ?> · <?= $day['count'] === 1 ? '1 sugerencia' : $fmt($day['count']) . ' sugerencias' ?>"
                  >
                    <span class="sg-flow-col">
                      <?php if ($day['count'] > 0): ?><span class="sg-flow-n"><?= $fmt($day['count']) ?></span><?php endif; ?>
                      <span class="sg-flow-bar"></span>
                    </span>
                    <span class="sg-flow-day"><?= $day['today'] ? 'Hoy' : SG_INICIALES[$day['weekday']] ?></span>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
          </div>

          <aside class="sg-stamps" aria-labelledby="sg-stamps-title">
            <header class="sg-stamps-head">
              <div>
                <h2 id="sg-stamps-title" class="sg-stamps-title">Matasellos del buzón</h2>
                <p class="sg-stamps-caption"><?= lucide_icon('clock', '', 12) ?>Sellado hoy a las <?= date('H:i') ?> h</p>
              </div>
            </header>

            <ul class="sg-stamp-grid">
              <li>
                <?php if ($stats['pendiente_mas_antigua']): ?>
                  <a href="<?= e(sg_url($state, ['estado' => 'pendiente', 'orden' => 'antiguas', 'page' => 1])) ?>" class="sg-stamp is-link is-warn" title="Sugerencia sin leer más antigua, recibida el <?= e(sg_date($stats['pendiente_mas_antigua'])) ?> · ver primero las más antiguas">
                    <span class="sg-stamp-label">Más antigua</span>
                    <strong class="sg-stamp-value"><?= e(sg_ago($stats['pendiente_mas_antigua'])) ?></strong>
                    <span class="sg-stamp-note">Sin leer · <?= e(date('d/m · H:i', strtotime((string) $stats['pendiente_mas_antigua']))) ?> h</span>
                    <span class="sg-stamp-art" aria-hidden="true"><?= lucide_icon('hourglass', '', 26) ?></span>
                  </a>
                <?php else: ?>
                  <span class="sg-stamp is-ok">
                    <span class="sg-stamp-label">Más antigua</span>
                    <strong class="sg-stamp-value">Al día</strong>
                    <span class="sg-stamp-note">Nada pendiente de leer</span>
                    <span class="sg-stamp-art" aria-hidden="true"><?= lucide_icon('mail-check', '', 26) ?></span>
                  </span>
                <?php endif; ?>
              </li>
              <li>
                <?php if ($stats['ultima']): ?>
                  <a href="<?= e(sg_url($state, ['estado' => 'todos', 'orden' => 'recientes', 'page' => 1])) ?>" class="sg-stamp is-link is-brand" title="<?= e(sg_date($stats['ultima'])) ?> · ver las más recientes">
                    <span class="sg-stamp-label">Más reciente</span>
                    <strong class="sg-stamp-value"><?= e(sg_ago($stats['ultima'])) ?></strong>
                    <span class="sg-stamp-note"><?= e(date('d/m · H:i', strtotime((string) $stats['ultima']))) ?> h</span>
                    <span class="sg-stamp-art" aria-hidden="true"><?= lucide_icon('send', '', 26) ?></span>
                  </a>
                <?php else: ?>
                  <span class="sg-stamp is-brand">
                    <span class="sg-stamp-label">Más reciente</span>
                    <strong class="sg-stamp-value">Ninguna</strong>
                    <span class="sg-stamp-note">El buzón está vacío</span>
                    <span class="sg-stamp-art" aria-hidden="true"><?= lucide_icon('send', '', 26) ?></span>
                  </span>
                <?php endif; ?>
              </li>
              <li>
                <a href="<?= e(sg_url($state, ['contacto' => $withEmail ? '' : '1', 'page' => 1])) ?>" class="sg-stamp is-link is-fresh <?= $withEmail ? 'is-active' : '' ?>" title="<?= $fmt($withEmailTotal) ?> con email · <?= $withEmail ? 'quitar el filtro' : 'ver solo las que se pueden responder' ?>" <?= $withEmail ? 'aria-current="true"' : '' ?>>
                  <span class="sg-stamp-label">Con email</span>
                  <strong class="sg-stamp-value"><?= $pct($withEmailTotal) ?><small>%</small></strong>
                  <span class="sg-stamp-note"><?= $fmt($withEmailTotal) ?> de <?= $fmt($total) ?> dejaron email</span>
                  <span class="sg-stamp-art" aria-hidden="true"><?= lucide_icon('reply', '', 26) ?></span>
                </a>
              </li>
              <li>
                <span class="sg-stamp is-rose">
                  <span class="sg-stamp-label">Firmadas</span>
                  <strong class="sg-stamp-value"><?= $pct($signedTotal) ?><small>%</small></strong>
                  <span class="sg-stamp-note"><?= $total - $signedTotal === 1 ? '1 anónima' : $fmt($total - $signedTotal) . ' anónimas' ?></span>
                  <span class="sg-stamp-art" aria-hidden="true"><?= lucide_icon('pen-line', '', 26) ?></span>
                </span>
              </li>
            </ul>
          </aside>
        </div>
      </header>

      <section class="dash-panel rp-queue sg-queue animate-reveal-up" aria-labelledby="sg-list-title" style="--lane-accent: <?= SG_ESTADOS[$status]['tone'] ?>">
        <header class="dash-panel-head rp-queue-head">
          <span class="dash-panel-icon" aria-hidden="true"><?= lucide_icon(SG_ESTADOS[$status]['icon'], '', 18) ?></span>
          <div>
            <h2 id="sg-list-title" class="dash-panel-title">Correspondencia</h2>
            <p class="dash-panel-caption">
              <?php if ($suggestions): ?>
                <?= $fmt($resultTotal) ?> <?= $resultTotal === 1 ? 'sugerencia' : 'sugerencias' ?> · <?= e(mb_strtolower(SG_ORDENES[$order]['label'])) ?> primero
              <?php else: ?>
                Ninguna sugerencia coincide
              <?php endif; ?>
            </p>
          </div>

          <form method="get" class="at-search" role="search">
            <?php foreach (['estado' => $status !== 'pendiente' ? $status : '', 'orden' => $order !== 'recientes' ? $order : '', 'contacto' => $state['contacto']] as $name => $value): ?>
              <?php if ($value !== ''): ?><input type="hidden" name="<?= $name ?>" value="<?= e($value) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <?= lucide_icon('search', 'at-search-icon', 16) ?>
            <label for="sg-q" class="visually-hidden">Buscar sugerencias</label>
            <input type="search" id="sg-q" name="q" class="at-search-input" placeholder="Asunto, texto, nombre o email…" value="<?= e($q) ?>" autocomplete="off">
            <?php if ($q !== ''): ?>
              <a href="<?= e(sg_url($state, ['q' => ''])) ?>" class="at-search-clear" aria-label="Borrar búsqueda" title="Borrar búsqueda"><?= lucide_icon('x', '', 14) ?></a>
            <?php endif; ?>
            <button type="submit" class="at-search-go" aria-label="Buscar"><?= lucide_icon('arrow-right', '', 15) ?></button>
          </form>
        </header>

        <nav class="rp-states" aria-label="Estado de las sugerencias">
          <?php foreach (SG_ESTADOS as $key => $estado): ?>
            <a
              href="<?= e(sg_url($state, ['estado' => $key])) ?>"
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
            <nav class="at-sort" aria-label="Ordenar sugerencias">
              <?php foreach (SG_ORDENES as $key => $option): ?>
                <a
                  href="<?= e(sg_url($state, ['orden' => $key])) ?>"
                  class="at-sort-item <?= $order === $key ? 'is-active' : '' ?>"
                  <?= $order === $key ? 'aria-current="true"' : '' ?>
                >
                  <?= lucide_icon($option['icon'], '', 14) ?><?= e($option['label']) ?>
                </a>
              <?php endforeach; ?>
            </nav>

            <?php if ($withEmail): ?>
              <a href="<?= e(sg_url($state, ['contacto' => ''])) ?>" class="at-chip rp-chip" style="--m-c: var(--success)" title="Quitar filtro">
                <?= lucide_icon('at-sign', '', 13) ?>Solo con email<?= lucide_icon('x', '', 13) ?>
              </a>
            <?php endif; ?>
          </div>

          <?php if ($suggestions): ?>
            <ul class="rp-items sg-notes">
              <?php foreach ($suggestions as $item): ?>
                <?php
                  $id = (int) $item['idsugerencia'];
                  $itemState = array_key_exists((string) $item['sug_estado'], SG_ESTADOS) ? (string) $item['sug_estado'] : 'pendiente';
                  $name = trim((string) ($item['sug_nombre'] ?? ''));
                  $email = trim((string) ($item['sug_email'] ?? ''));
                  $subject = trim((string) ($item['sug_asunto'] ?? ''));
                  $detail = trim((string) ($item['sug_detalle'] ?? ''));
                  $session = (string) ($item['sug_session_id'] ?? '');
                  $initials = $name !== '' ? sg_initials($name) : '';
                  $tone = sg_tone($email !== '' ? $email : ($name !== '' ? $name : (string) ($item['sug_session_id'] ?? $id)));
                  $replyUrl = $email !== '' ? 'mailto:' . $email . '?subject=' . rawurlencode('Re: ' . ($subject !== '' ? $subject : 'Tu sugerencia en ' . APP_NAME)) : '';
                  $quick = match ($itemState) {
                      'pendiente' => ['action' => 'mark_read', 'icon' => 'check', 'label' => 'Marcar leída', 'class' => 'is-ok'],
                      'leido' => ['action' => 'archive', 'icon' => 'archive', 'label' => 'Archivar', 'class' => ''],
                      default => ['action' => 'mark_pending', 'icon' => 'archive-restore', 'label' => 'Reabrir', 'class' => ''],
                  };
                ?>
                <li class="rp-item ar-card sg-note is-<?= $itemState ?>" style="--s-c: <?= SG_ESTADOS[$itemState]['tone'] ?>; --av-c: <?= $tone ?>">
                  <header class="ar-review-head">
                    <span class="ar-avatar sg-avatar <?= $initials === '' ? 'is-anon' : '' ?>" style="--av-c: <?= $tone ?>" aria-hidden="true">
                      <?= $initials !== '' ? e($initials) : lucide_icon('user-round', '', 18) ?>
                    </span>
                    <div class="ar-review-who">
                      <p class="ar-review-name"><?= e($name !== '' ? $name : 'Anónimo') ?></p>
                      <div class="ar-review-meta">
                        <?php if ($email !== ''): ?>
                          <a href="<?= e($replyUrl) ?>" class="ar-review-store" title="Responder a <?= e($email) ?>"><?= lucide_icon('at-sign', '', 13) ?><span><?= e($email) ?></span></a>
                        <?php else: ?>
                          <span class="sg-no-mail"><?= lucide_icon('mail-x', '', 13) ?>Sin email</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="ar-card-side">
                      <div class="dropdown rp-menu">
                        <button type="button" class="rp-menu-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Más acciones para la sugerencia #<?= $id ?>">
                          <?= lucide_icon('ellipsis', '', 16) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end rp-menu-list">
                          <li>
                            <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#sgReadModal" data-template="sg-full-<?= $id ?>" data-id="<?= $id ?>" data-state="<?= $itemState ?>" data-reply="<?= e($replyUrl) ?>">
                              <?= lucide_icon('book-open-text', '', 15) ?>Leer completa
                            </button>
                          </li>
                          <?php if ($replyUrl !== ''): ?>
                            <li><a class="dropdown-item" href="<?= e($replyUrl) ?>"><?= lucide_icon('reply', '', 15) ?>Responder por email</a></li>
                          <?php endif; ?>
                          <li><hr class="dropdown-divider"></li>
                          <?php foreach (SG_ACCIONES as $action => $target): ?>
                            <?php if ($target === $itemState) { continue; } ?>
                            <li>
                              <form method="post">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="<?= $action ?>">
                                <button type="submit" class="dropdown-item">
                                  <?= lucide_icon(match ($target) { 'leido' => 'mail-open', 'archivado' => 'archive', default => 'mail' }, '', 15) ?>
                                  <?= match ($target) { 'leido' => 'Marcar como leída', 'archivado' => 'Archivar', default => 'Marcar como sin leer' } ?>
                                </button>
                              </form>
                            </li>
                          <?php endforeach; ?>
                          <li><hr class="dropdown-divider"></li>
                          <li>
                            <button
                              type="button"
                              class="dropdown-item is-danger"
                              data-bs-toggle="modal"
                              data-bs-target="#sgDeleteModal"
                              data-id="<?= $id ?>"
                              data-name="<?= e($name !== '' ? $name : 'Anónimo') ?>"
                              data-initials="<?= e($initials) ?>"
                              data-tone="<?= e($tone) ?>"
                              data-subject="<?= e($subject !== '' ? $subject : 'Sin asunto') ?>"
                              data-date="<?= e(sg_date($item['sug_fecha'])) ?>"
                            ><?= lucide_icon('trash-2', '', 15) ?>Eliminar</button>
                          </li>
                        </ul>
                      </div>
                    </div>
                  </header>

                  <h3 class="sg-note-subject <?= $subject === '' ? 'is-empty' : '' ?>" title="<?= e($subject) ?>"><?= e($subject !== '' ? $subject : 'Sin asunto') ?></h3>
                  <p class="ar-review-text"><?= e($detail) ?></p>

                  <footer class="rp-card-foot ar-card-foot">
                    <span class="rp-case" title="Recibida el <?= e(sg_date($item['sug_fecha'])) ?>">
                      <strong><?= lucide_icon(SG_ESTADOS[$itemState]['icon'], '', 13) ?><?= e(SG_ESTADOS[$itemState]['single']) ?></strong>
                      <small><?= e(sg_ago($item['sug_fecha'])) ?></small>
                    </span>
                    <div class="sg-note-actions">
                      <button
                        type="button"
                        class="rp-btn sg-read-btn"
                        data-bs-toggle="modal"
                        data-bs-target="#sgReadModal"
                        data-template="sg-full-<?= $id ?>"
                        data-id="<?= $id ?>"
                        data-state="<?= $itemState ?>"
                        data-reply="<?= e($replyUrl) ?>"
                        aria-label="Leer la sugerencia completa"
                        title="Leer completa"
                      ><?= lucide_icon('book-open-text', '', 15) ?></button>
                      <form method="post">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="<?= $quick['action'] ?>">
                        <button type="submit" class="rp-btn <?= $quick['class'] ?>"><?= lucide_icon($quick['icon'], '', 15) ?><?= e($quick['label']) ?></button>
                      </form>
                    </div>
                  </footer>

                  <template id="sg-full-<?= $id ?>">
                    <article
                      class="ar-review is-full sg-read"
                      aria-label="Sugerencia #<?= $id ?>"
                      data-title="<?= e($subject !== '' ? $subject : 'Sugerencia sin asunto') ?>"
                      data-lead="Sugerencia #<?= $id ?> · recibida <?= e(sg_ago($item['sug_fecha'])) ?>"
                      data-tone="<?= e($tone) ?>"
                    >
                      <header class="ar-review-head">
                        <span class="ar-avatar sg-avatar <?= $initials === '' ? 'is-anon' : '' ?>" style="--av-c: <?= $tone ?>" aria-hidden="true">
                          <?= $initials !== '' ? e($initials) : lucide_icon('user-round', '', 18) ?>
                        </span>
                        <div class="ar-review-who">
                          <p class="ar-review-name"><?= e($name !== '' ? $name : 'Anónimo') ?></p>
                          <div class="ar-review-meta">
                            <?php if ($email !== ''): ?>
                              <a href="<?= e($replyUrl) ?>" class="ar-review-store" title="Responder"><?= lucide_icon('at-sign', '', 13) ?><span><?= e($email) ?></span></a>
                            <?php else: ?>
                              <span class="sg-no-mail"><?= lucide_icon('mail-x', '', 13) ?>No dejó email</span>
                            <?php endif; ?>
                          </div>
                        </div>
                        <span class="sg-read-state" style="--s-c: <?= SG_ESTADOS[$itemState]['tone'] ?>"><?= lucide_icon(SG_ESTADOS[$itemState]['icon'], '', 13) ?><?= e(SG_ESTADOS[$itemState]['single']) ?></span>
                      </header>
                      <p class="ar-review-text"><?= e($detail) ?></p>
                      <div class="rp-full-foot">
                        <ul class="rp-chips" aria-label="Datos del envío">
                          <li title="Fecha de envío"><?= lucide_icon('calendar', '', 13) ?><?= e(sg_date($item['sug_fecha'])) ?></li>
                          <li title="Dirección IP"><?= lucide_icon('globe', '', 13) ?><?= e($item['sug_ip'] ?: 'Sin IP') ?></li>
                          <?php if ($session !== ''): ?>
                            <li title="Sesión: <?= e($session) ?>"><?= lucide_icon('fingerprint', '', 13) ?><?= e(mb_strlen($session) > 12 ? mb_substr($session, 0, 12) . '…' : $session) ?></li>
                          <?php endif; ?>
                        </ul>
                      </div>
                    </article>
                  </template>
                </li>
              <?php endforeach; ?>
            </ul>

            <?php if ($totalPages > 1): ?>
              <nav class="ap-pager rp-pager" aria-label="Paginación de sugerencias">
                <span class="ap-pager-info">Página <strong><?= $fmt($page) ?></strong> de <?= $fmt($totalPages) ?></span>
                <div class="ap-pages">
                  <a
                    class="ap-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>"
                    href="<?= e(sg_url($state, ['page' => max(1, $page - 1)])) ?>"
                    aria-label="Página anterior"
                    <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                  ><?= lucide_icon('chevron-left', '', 15) ?></a>
                  <?php foreach ($pageItems as $pageItem): ?>
                    <?php if ($pageItem === null): ?>
                      <span class="ap-page-gap" aria-hidden="true">…</span>
                    <?php else: ?>
                      <a
                        class="ap-page-btn <?= $pageItem === $page ? 'is-current' : '' ?>"
                        href="<?= e(sg_url($state, ['page' => $pageItem])) ?>"
                        <?= $pageItem === $page ? 'aria-current="page"' : '' ?>
                      ><?= $fmt($pageItem) ?></a>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <a
                    class="ap-page-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>"
                    href="<?= e(sg_url($state, ['page' => min($totalPages, $page + 1)])) ?>"
                    aria-label="Página siguiente"
                    <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                  ><?= lucide_icon('chevron-right', '', 15) ?></a>
                </div>
              </nav>
            <?php endif; ?>
          <?php else: ?>
            <?php $isClean = $q === '' && !$withEmail; ?>
            <div class="rp-empty">
              <span class="rp-empty-icon" aria-hidden="true"><?= lucide_icon($total === 0 ? 'mailbox' : ($status === 'pendiente' && $isClean ? 'mail-check' : 'search-x'), '', 20) ?></span>
              <?php if ($total === 0): ?>
                <p class="rp-empty-text">El buzón está vacío: todavía nadie envió una sugerencia.</p>
              <?php elseif ($status === 'pendiente' && $isClean): ?>
                <p class="rp-empty-text">Leíste todo el buzón. Las nuevas sugerencias aparecerán aquí.</p>
                <a href="<?= e(sg_url($state, ['estado' => 'todos'])) ?>" class="rp-empty-action"><?= lucide_icon('inbox', '', 14) ?>Ver todas las sugerencias</a>
              <?php elseif ($isClean): ?>
                <p class="rp-empty-text">No hay sugerencias <?= e(mb_strtolower(SG_ESTADOS[$status]['label'])) ?>.</p>
              <?php else: ?>
                <p class="rp-empty-text"><?= $q !== '' ? 'Ninguna sugerencia coincide con “' . e($q) . '”.' : 'Ninguna sugerencia cumple los filtros activos.' ?></p>
                <a href="<?= e(sg_url($state, ['q' => '', 'contacto' => ''])) ?>" class="rp-empty-action"><?= lucide_icon('rotate-ccw', '', 14) ?>Quitar filtros</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

    </div>
  </div>
</section>

<div class="modal fade" id="sgReadModal" tabindex="-1" aria-labelledby="sg-read-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content suggestion-modal report-product-modal dash-confirm rp-alerts-modal ar-reports-modal">
      <div class="modal-header">
        <div class="report-product-heading">
          <span class="report-product-icon sg-read-icon" aria-hidden="true"><?= lucide_icon('mail-open', '', 18) ?></span>
          <div class="report-product-heading-text">
            <p class="dash-confirm-kicker">Buzón de la comunidad</p>
            <h5 class="modal-title" id="sg-read-title" data-sg-read="title">Sugerencia completa</h5>
            <p class="report-product-lead" data-sg-read="lead"></p>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div data-sg-read="body"></div>
      </div>

      <div class="modal-footer rp-alerts-foot">
        <a href="#" class="btn btn-outline-secondary me-auto" data-sg-read="reply"><?= lucide_icon('reply', '', 16) ?>Responder</a>
        <form method="post" data-sg-read="mark_pending">
          <input type="hidden" name="id" value="" data-sg-read="id">
          <input type="hidden" name="action" value="mark_pending">
          <button type="submit" class="btn btn-outline-secondary"><?= lucide_icon('mail', '', 16) ?>Sin leer</button>
        </form>
        <form method="post" data-sg-read="archive">
          <input type="hidden" name="id" value="" data-sg-read="id">
          <input type="hidden" name="action" value="archive">
          <button type="submit" class="btn btn-outline-secondary"><?= lucide_icon('archive', '', 16) ?>Archivar</button>
        </form>
        <form method="post" data-sg-read="mark_read">
          <input type="hidden" name="id" value="" data-sg-read="id">
          <input type="hidden" name="action" value="mark_read">
          <button type="submit" class="btn btn-success"><?= lucide_icon('check', '', 16) ?>Marcar leída</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="sgDeleteModal" tabindex="-1" aria-labelledby="sg-delete-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content suggestion-modal report-product-modal dash-confirm">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="" data-sg-del="id">

      <div class="modal-header">
        <div class="report-product-heading">
          <span class="report-product-icon" aria-hidden="true"><?= lucide_icon('trash-2', '', 18) ?></span>
          <div class="report-product-heading-text">
            <p class="dash-confirm-kicker">Buzón de la comunidad</p>
            <h5 class="modal-title" id="sg-delete-title">¿Eliminar esta sugerencia?</h5>
            <p class="report-product-lead">Esta acción no se puede deshacer.</p>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div class="dash-confirm-item">
          <span class="ar-avatar sg-avatar" data-sg-del="avatar" aria-hidden="true"></span>
          <div class="dash-confirm-copy">
            <p class="dash-confirm-name" data-sg-del="subject"></p>
            <div class="dash-confirm-meta">
              <span><?= lucide_icon('user-round', '', 13) ?><span data-sg-del="name"></span></span>
              <span><?= lucide_icon('calendar', '', 13) ?><span data-sg-del="date"></span></span>
            </div>
          </div>
        </div>

        <ul class="dash-confirm-list">
          <li>
            <?= lucide_icon('eraser', '', 16) ?>
            <span>El mensaje se borra <strong>de forma permanente</strong> del buzón.</span>
          </li>
          <li>
            <?= lucide_icon('archive', '', 16) ?>
            <span>Si solo quieres sacarla de la bandeja, <strong>archívala</strong>.</span>
          </li>
        </ul>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger"><?= lucide_icon('trash-2', '', 16) ?>Eliminar</button>
      </div>
    </form>
  </div>
</div>

<script src="./js/menu-portal.js?v=<?= filemtime(__DIR__ . '/js/menu-portal.js') ?>"></script>
<script>
const readModal = document.getElementById('sgReadModal');
if (readModal) {
  const slot = function(name) { return readModal.querySelector('[data-sg-read="' + name + '"]'); };

  readModal.addEventListener('show.bs.modal', function(event) {
    const d = event.relatedTarget ? event.relatedTarget.dataset : {};
    const template = document.getElementById(d.template || '');
    const targets = { mark_read: 'leido', archive: 'archivado', mark_pending: 'pendiente' };

    slot('body').replaceChildren(template ? template.content.cloneNode(true) : '');
    const letter = slot('body').querySelector('.sg-read');
    const info = letter ? letter.dataset : {};
    slot('title').textContent = info.title || 'Sugerencia completa';
    slot('lead').textContent = info.lead || '';
    readModal.querySelector('.modal-content').style.setProperty('--av-c', info.tone || 'var(--brand)');
    readModal.querySelectorAll('[data-sg-read="id"]').forEach(function(input) { input.value = d.id || ''; });
    Object.keys(targets).forEach(function(action) { slot(action).hidden = d.state === targets[action]; });

    const reply = slot('reply');
    reply.hidden = !d.reply;
    reply.href = d.reply || '#';

    if (typeof window.refreshLucideIcons === 'function') {
      window.refreshLucideIcons();
    }
  });
}

const deleteModal = document.getElementById('sgDeleteModal');
if (deleteModal) {
  const field = function(name) { return deleteModal.querySelector('[data-sg-del="' + name + '"]'); };

  deleteModal.addEventListener('show.bs.modal', function(event) {
    const d = event.relatedTarget ? event.relatedTarget.dataset : {};
    const avatar = field('avatar');

    field('id').value = d.id || '';
    field('subject').textContent = d.subject || '';
    field('name').textContent = d.name || '';
    field('date').textContent = d.date || '';

    avatar.classList.toggle('is-anon', !d.initials);
    avatar.style.setProperty('--av-c', d.tone || 'var(--brand)');
    if (d.initials) {
      avatar.textContent = d.initials;
    } else {
      avatar.innerHTML = '<i data-lucide="user-round" width="18" height="18"></i>';
      if (typeof window.refreshLucideIcons === 'function') {
        window.refreshLucideIcons();
      }
    }
  });
}
</script>

<?php render_footer(); ?>
