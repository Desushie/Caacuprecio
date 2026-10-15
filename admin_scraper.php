<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

/*
|--------------------------------------------------------------------------
| Configuración de scrapers
|--------------------------------------------------------------------------
| Reemplaza las rutas placeholder por tus scripts reales.
| Esta versión NO ejecuta el scraper directamente.
| Solo crea jobs en la tabla scraper_jobs para que un worker externo los procese.
*/
$scraperJobs = [
    'all' => [
        'label' => 'Ejecutar scraper completo',
        'path'  => __DIR__ . '/py/run_all.py',
        'type'  => 'principal',
    ],
    'computex' => [
        'label' => 'Computex',
        'path'  => __DIR__ . '/py/run_computex.py',
        'type'  => 'tienda',
    ],
    'inverfin' => [
        'label' => 'Inverfin',
        'path'  => __DIR__ . '/py/run_inverfin.py',
        'type'  => 'tienda',
    ],
    'fulloffice' => [
        'label' => 'Full Office',
        'path'  => __DIR__ . '/py/run_fulloffice.py',
        'type'  => 'tienda',
    ],
];

$storeJobs = array_filter($scraperJobs, fn($job) => $job['type'] === 'tienda');

const SX_FILAS = [10, 25, 50];
const SX_FILAS_DEFAULT = 10;
const SX_PULSE_SLOTS = 12;
const SX_LOG_TAIL = 8000;
const SX_DAYS = 30;

$historyFilters = [
    'todas'     => ['label' => 'Todas', 'statuses' => []],
    'activas'   => ['label' => 'Activas', 'statuses' => ['pending', 'running']],
    'done'      => ['label' => 'Completadas', 'statuses' => ['done']],
    'error'     => ['label' => 'Con error', 'statuses' => ['error']],
    'cancelled' => ['label' => 'Canceladas', 'statuses' => ['cancelled']],
];

$estado = (string) ($_GET['estado'] ?? 'todas');
if (!isset($historyFilters[$estado])) {
    $estado = 'todas';
}

$filas = (int) ($_GET['filas'] ?? SX_FILAS_DEFAULT);
if (!in_array($filas, SX_FILAS, true)) {
    $filas = SX_FILAS_DEFAULT;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

function sx_url(string $estado, int $filas = SX_FILAS_DEFAULT, int $page = 1): string
{
    $query = http_build_query(array_filter([
        'estado' => $estado !== 'todas' ? $estado : null,
        'filas'  => $filas !== SX_FILAS_DEFAULT ? $filas : null,
        'page'   => $page > 1 ? $page : null,
    ]));
    return 'admin_scraper.php' . ($query !== '' ? '?' . $query : '');
}

function sx_ts(?string $date): ?int
{
    $ts = $date ? strtotime($date) : false;
    return $ts ?: null;
}

function sx_ago(?int $ts, int $now): string
{
    if ($ts === null) {
        return '—';
    }
    $diff = max(0, $now - $ts);
    if ($diff < 60) {
        return 'justo ahora';
    }
    if ($diff < 3600) {
        return 'hace ' . (int) floor($diff / 60) . ' min';
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

function sx_duration(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return '—';
    }
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        return (int) floor($seconds / 60) . ' min';
    }
    if ($seconds < 86400) {
        $minutes = (int) floor(($seconds % 3600) / 60);
        return (int) floor($seconds / 3600) . ' h' . ($minutes > 0 ? ' ' . $minutes . ' min' : '');
    }
    $hours = (int) floor(($seconds % 86400) / 3600);
    return (int) floor($seconds / 86400) . ' d' . ($hours > 0 ? ' ' . $hours . ' h' : '');
}

function sx_status(string $status): array
{
    return match ($status) {
        'pending'   => ['label' => 'En espera', 'icon' => 'clock-3', 'tone' => 'is-wait'],
        'running'   => ['label' => 'Ejecutando', 'icon' => 'loader-circle', 'tone' => 'is-run'],
        'done'      => ['label' => 'Completado', 'icon' => 'circle-check', 'tone' => 'is-done'],
        'error'     => ['label' => 'Error', 'icon' => 'circle-x', 'tone' => 'is-error'],
        'cancelled' => ['label' => 'Cancelado', 'icon' => 'ban', 'tone' => 'is-off'],
        default     => ['label' => ucfirst($status), 'icon' => 'circle-help', 'tone' => 'is-off'],
    };
}

function sx_initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    if (count($words) > 1) {
        return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
    }
    return mb_convert_case(mb_substr($name, 0, 2), MB_CASE_TITLE);
}

/*
|--------------------------------------------------------------------------
| Verificar existencia de tabla scraper_jobs
|--------------------------------------------------------------------------
*/
$tableExists = false;
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'scraper_jobs'");
    $tableExists = (bool) $checkTable->fetchColumn();
} catch (Throwable $e) {
    $tableExists = false;
}

/*
|--------------------------------------------------------------------------
| Acciones (POST → redirección para que recargar no repita la acción)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $flash = null;

    if ($action === 'queue_scraper') {
        $jobKey = trim((string) ($_POST['job'] ?? ''));

        if (!isset($scraperJobs[$jobKey])) {
            $flash = ['type' => 'danger', 'message' => 'No se encontró el scraper solicitado.'];
        } else {
            $job = $scraperJobs[$jobKey];
            $jobName = $jobKey === 'all' ? 'La actualización completa' : $job['label'];

            $activeStmt = $pdo->prepare("
                SELECT id FROM scraper_jobs
                WHERE job_key = :job_key AND status IN ('pending', 'running')
                ORDER BY id DESC
                LIMIT 1
            ");
            $activeStmt->execute([':job_key' => $jobKey]);
            $activeId = (int) $activeStmt->fetchColumn();

            if ($activeId > 0) {
                $flash = [
                    'type' => 'warning',
                    'title' => 'Ya está en curso.',
                    'message' => $jobName . ' sigue activa (#' . $activeId . ').',
                ];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO scraper_jobs (
                        job_key,
                        job_label,
                        command_path,
                        status,
                        created_at
                    ) VALUES (
                        :job_key,
                        :job_label,
                        :command_path,
                        'pending',
                        NOW()
                    )
                ");

                $stmt->execute([
                    ':job_key' => $jobKey,
                    ':job_label' => $job['label'],
                    ':command_path' => $job['path'],
                ]);

                $flash = [
                    'type' => 'success',
                    'title' => 'En cola.',
                    'message' => ($jobKey === 'all' ? 'La actualización completa' : 'La actualización de ' . $job['label']) . ' empezará en cuanto el proceso esté libre.',
                ];
            }
        }
    }

    if ($action === 'cancel_job') {
        $jobId = (int) ($_POST['job_id'] ?? 0);

        if ($jobId > 0) {
            $stmt = $pdo->prepare("
                UPDATE scraper_jobs
                SET status = 'cancelled', finished_at = NOW()
                WHERE id = :id
                  AND status IN ('pending', 'running')
            ");
            $stmt->execute([':id' => $jobId]);

            $flash = $stmt->rowCount() > 0
                ? ['type' => 'warning', 'title' => 'Detenida.', 'message' => 'La tarea #' . $jobId . ' fue cancelada.']
                : ['type' => 'danger', 'message' => 'No se pudo cancelar la tarea o ya terminó.'];
        }
    }

    $_SESSION['sx_flash'] = $flash;
    $back = (string) ($_POST['estado'] ?? 'todas');
    $backFilas = (int) ($_POST['filas'] ?? SX_FILAS_DEFAULT);
    header('Location: ' . sx_url(
        isset($historyFilters[$back]) ? $back : 'todas',
        in_array($backFilas, SX_FILAS, true) ? $backFilas : SX_FILAS_DEFAULT,
        max(1, (int) ($_POST['page'] ?? 1))
    ));
    exit;
}

$flash = $_SESSION['sx_flash'] ?? null;
unset($_SESSION['sx_flash']);

/*
|--------------------------------------------------------------------------
| Datos del panel
|--------------------------------------------------------------------------
*/
$now = time();
$activeJobs = [];
$activeByKey = [];
$runsByKey = [];
$lastDoneByKey = [];
$durationsByKey = [];
$month = ['total' => 0, 'done' => 0, 'error' => 0, 'avg' => null];
$today = ['done' => 0, 'error' => 0];
$statusCounts = array_fill_keys(['pending', 'running', 'done', 'error', 'cancelled'], 0);
$jobHistory = [];
$daily = [];

if ($tableExists) {
    $now = sx_ts((string) $pdo->query('SELECT NOW()')->fetchColumn()) ?? time();

    $activeJobs = $pdo->query("
        SELECT id, job_key, job_label, status, created_at, started_at
        FROM scraper_jobs
        WHERE status IN ('pending', 'running')
        ORDER BY status = 'running' DESC, id ASC
    ")->fetchAll();

    foreach ($activeJobs as $job) {
        $activeByKey[$job['job_key']] ??= $job;
    }

    $recent = $pdo->query("
        SELECT job_key, status, started_at, finished_at
        FROM scraper_jobs
        ORDER BY id DESC
        LIMIT 300
    ")->fetchAll();

    foreach ($recent as $run) {
        $key = (string) $run['job_key'];
        if (count($runsByKey[$key] ?? []) < SX_PULSE_SLOTS) {
            $runsByKey[$key][] = $run;
        }
        if ($run['status'] === 'done') {
            $lastDoneByKey[$key] ??= $run;
            $start = sx_ts($run['started_at']);
            $end = sx_ts($run['finished_at']);
            if ($start !== null && $end !== null && $end >= $start) {
                $durationsByKey[$key][] = $end - $start;
            }
        }
    }

    $monthRow = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'done') AS done,
            SUM(status = 'error') AS error,
            AVG(CASE WHEN status = 'done' THEN TIMESTAMPDIFF(SECOND, started_at, finished_at) END) AS avg_seconds
        FROM scraper_jobs
        WHERE created_at >= NOW() - INTERVAL 30 DAY
    ")->fetch();
    $month = [
        'total' => (int) $monthRow['total'],
        'done'  => (int) $monthRow['done'],
        'error' => (int) $monthRow['error'],
        'avg'   => $monthRow['avg_seconds'] !== null ? (int) round((float) $monthRow['avg_seconds']) : null,
    ];

    $todayRow = $pdo->query("
        SELECT SUM(status = 'done') AS done, SUM(status = 'error') AS error
        FROM scraper_jobs
        WHERE finished_at >= CURDATE()
    ")->fetch();
    $today = ['done' => (int) $todayRow['done'], 'error' => (int) $todayRow['error']];

    $dailyRows = $pdo->query("
        SELECT
            DATE(created_at) AS day,
            COUNT(*) AS total,
            SUM(status = 'done') AS done,
            SUM(status = 'error') AS error,
            SUM(status IN ('pending', 'running')) AS active
        FROM scraper_jobs
        WHERE created_at >= CURDATE() - INTERVAL " . (SX_DAYS - 1) . " DAY
        GROUP BY DATE(created_at)
    ")->fetchAll();
    foreach ($dailyRows as $row) {
        $daily[$row['day']] = $row;
    }

    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM scraper_jobs GROUP BY status') as $row) {
        $statusCounts[$row['status']] = (int) $row['n'];
    }
}

$filterCounts = [
    'todas'     => array_sum($statusCounts),
    'activas'   => $statusCounts['pending'] + $statusCounts['running'],
    'done'      => $statusCounts['done'],
    'error'     => $statusCounts['error'],
    'cancelled' => $statusCounts['cancelled'],
];

$historyTotal = $filterCounts[$estado];
$totalPages = max(1, (int) ceil($historyTotal / $filas));
$page = min($page, $totalPages);
$offset = ($page - 1) * $filas;
$fromItem = $historyTotal > 0 ? $offset + 1 : 0;
$toItem = min($offset + $filas, $historyTotal);

$pageItems = [];
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1) {
        $pageItems[] = $i;
    } elseif (end($pageItems) !== null) {
        $pageItems[] = null;
    }
}

$returnFields = '<input type="hidden" name="estado" value="' . e($estado) . '">'
    . '<input type="hidden" name="filas" value="' . $filas . '">'
    . '<input type="hidden" name="page" value="' . $page . '">';

if ($tableExists) {
    $filterStatuses = $historyFilters[$estado]['statuses'];
    $historyWhere = $filterStatuses
        ? "WHERE status IN ('" . implode("','", $filterStatuses) . "')"
        : '';

    $jobHistory = $pdo->query("
        SELECT
            id, job_key, job_label, command_path, status,
            created_at, started_at, finished_at,
            RIGHT(output, " . SX_LOG_TAIL . ") AS output_tail,
            CHAR_LENGTH(output) AS output_len
        FROM scraper_jobs
        $historyWhere
        ORDER BY id DESC
        LIMIT $filas OFFSET $offset
    ")->fetchAll();
}

/*
| Frescura del catálogo: productos activos con precio leído en las últimas 24 h.
| Los que la tienda deja de publicar los desactiva el pipeline al cerrar una
| corrida completa, así que no quedan pendientes para siempre.
*/
$catalog = ['total' => 0, 'fresh' => 0];
$storeCatalog = [];
try {
    $storeStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(pro_fecha_scraping >= :fresh_from), 0) AS fresh,
            MAX(pro_fecha_scraping) AS ultima
        FROM productos
        WHERE tiendas_idtiendas = :id
          AND pro_activo = 1
    ");

    foreach ($pdo->query('SELECT idtiendas, tie_nombre FROM tiendas') as $tienda) {
        $key = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $tienda['tie_nombre']));

        $storeStmt->execute([
            ':id'         => (int) $tienda['idtiendas'],
            ':fresh_from' => date('Y-m-d H:i:s', $now - 86400),
        ]);
        $storeCatalog[$key] = $storeStmt->fetch();
    }

    /* Solo cuentan las tiendas que tienen scraper; las demás nunca se actualizan */
    foreach (array_intersect_key($storeCatalog, $storeJobs) as $row) {
        $catalog['total'] += (int) $row['total'];
        $catalog['fresh'] += (int) $row['fresh'];
    }
} catch (Throwable $e) {
    $storeCatalog = [];
}

$freshPct = $catalog['total'] > 0 ? (int) round($catalog['fresh'] * 100 / $catalog['total']) : 0;
$freshTone = $freshPct >= 80 ? 'is-good' : ($freshPct >= 40 ? 'is-mid' : 'is-low');

$fmt = fn(int $n): string => number_format($n, 0, ',', '.');

/* Estado en vivo para la barra superior */
$running = null;
$pending = [];
foreach ($activeJobs as $job) {
    if ($job['status'] === 'running' && $running === null) {
        $running = $job;
    } elseif ($job['status'] === 'pending') {
        $pending[] = $job;
    }
}

$jobName = static function (array $job) use ($scraperJobs): string {
    $key = (string) ($job['job_key'] ?? '');
    if ($key === 'all') {
        return 'Actualización completa';
    }
    return isset($scraperJobs[$key]) ? $scraperJobs[$key]['label'] : (string) $job['job_label'];
};

/* Estado de la cola en el título de la pestaña: se ve sin volver a la página */
$tabTitle = 'Scrapers';
if ($running) {
    $tabTitle = '● ' . $jobName($running) . ($pending ? ' (+' . count($pending) . ' en espera)' : '') . ' · Scrapers';
} elseif ($pending) {
    $tabTitle = '◌ ' . count($pending) . ' en espera · Scrapers';
}

$successPct = ($month['done'] + $month['error']) > 0
    ? (int) round($month['done'] * 100 / ($month['done'] + $month['error']))
    : null;

$todayFinished = $today['done'] + $today['error'];

/* Tarea en curso: estimación según lo que tardó antes esa misma tarea */
$current = null;
if ($running) {
    $startTs = sx_ts($running['started_at']) ?? $now;
    $elapsed = max(0, $now - $startTs);
    // Las corridas de pocos segundos suelen ser fallos inmediatos y falsean la estimación
    $past = array_filter($durationsByKey[(string) $running['job_key']] ?? [], fn($s) => $s >= 30);
    $expected = $past ? (int) round(array_sum($past) / count($past)) : null;

    $current = [
        'id'       => (int) $running['id'],
        'name'     => $jobName($running),
        'start'    => date('H:i', $startTs),
        'elapsed'  => $elapsed,
        'expected' => $expected,
        'pct'      => $expected ? min(99, (int) round($elapsed * 100 / max(1, $expected))) : null,
        'late'     => $expected !== null && $elapsed > $expected,
    ];
}

/* Actividad diaria de los últimos días, del más viejo al de hoy */
$days = [];
for ($d = SX_DAYS - 1; $d >= 0; $d--) {
    $dayTs = strtotime('-' . $d . ' days', $now);
    $row = $daily[date('Y-m-d', $dayTs)] ?? null;
    $total = $row ? (int) $row['total'] : 0;
    $done = $row ? (int) $row['done'] : 0;
    $error = $row ? (int) $row['error'] : 0;
    $active = $row ? (int) $row['active'] : 0;

    $tone = match (true) {
        $total === 0                => '',
        $active > 0                 => 'is-run',
        $error > 0 && $done > 0     => 'is-mid',
        $error > 0                  => 'is-error',
        $done > 0                   => 'is-done',
        default                     => 'is-off',
    };

    $parts = [];
    if ($done) $parts[] = $done . ($done === 1 ? ' completada' : ' completadas');
    if ($error) $parts[] = $error . ' con error';
    if ($active) $parts[] = $active . ' activa' . ($active === 1 ? '' : 's');
    $cancelled = $total - $done - $error - $active;
    if ($cancelled > 0) $parts[] = $cancelled . ($cancelled === 1 ? ' cancelada' : ' canceladas');

    $days[] = [
        'tone'  => $tone,
        'today' => $d === 0,
        'title' => date('d/m', $dayTs) . ': ' . ($parts ? implode(', ', $parts) : 'sin ejecuciones'),
    ];
}

/* Actualización completa */
$fullActive = $activeByKey['all'] ?? null;
$fullLast = $runsByKey['all'][0] ?? null;
if ($fullLast && in_array($fullLast['status'], ['pending', 'running'], true)) {
    $fullLast = null;
    foreach ($runsByKey['all'] as $run) {
        if (!in_array($run['status'], ['pending', 'running'], true)) {
            $fullLast = $run;
            break;
        }
    }
}

$fullLastEnd = $fullLast ? sx_ts($fullLast['finished_at']) : null;
$fullLastStart = $fullLast ? sx_ts($fullLast['started_at']) : null;
$fullLastTook = $fullLastEnd !== null && $fullLastStart !== null && $fullLastEnd >= $fullLastStart
    ? $fullLastEnd - $fullLastStart
    : null;

/* Tarjetas por tienda */
$storePalette = ['var(--brand)', 'var(--accent)', 'var(--accent-2)', 'var(--success)', 'var(--brand-2)'];
$stores = [];
$i = 0;
foreach ($storeJobs as $key => $job) {
    $info = $storeCatalog[$key] ?? null;
    $lastTs = $info ? sx_ts($info['ultima']) : null;
    $hours = $lastTs !== null ? ($now - $lastTs) / 3600 : null;
    $runs = array_reverse($runsByKey[$key] ?? []);
    $finished = array_filter($runs, fn($r) => in_array($r['status'], ['done', 'error'], true));
    $ok = count(array_filter($finished, fn($r) => $r['status'] === 'done'));
    $durations = $durationsByKey[$key] ?? [];

    $stores[$key] = [
        'label'    => $job['label'],
        'color'    => $storePalette[$i++ % count($storePalette)],
        'initials' => sx_initials($job['label']),
        'products' => $info ? (int) $info['total'] : null,
        'fresh'    => $info ? (int) $info['fresh'] : 0,
        'lastTs'   => $lastTs,
        'tone'     => $hours === null ? 'is-low' : ($hours <= 24 ? 'is-good' : ($hours <= 72 ? 'is-mid' : 'is-low')),
        'runs'     => $runs,
        'ok'       => $ok,
        'finished' => count($finished),
        'lastDone' => isset($lastDoneByKey[$key]) ? sx_ts($lastDoneByKey[$key]['finished_at']) : null,
        'avg'      => $durations ? (int) round(array_sum($durations) / count($durations)) : null,
        'active'   => $activeByKey[$key] ?? null,
    ];
}

$hasActive = (bool) $activeJobs;

/* Con el proceso libre se sugiere la tienda con precios más viejos */
$stalest = null;
foreach ($stores as $key => $store) {
    if ($store['lastTs'] !== null && ($stalest === null || $store['lastTs'] < $stalest['lastTs'])) {
        $stalest = $store + ['key' => $key];
    }
}
if ($stalest && $now - $stalest['lastTs'] < 86400) {
    $stalest = null;
}

render_head($tabTitle);
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

<section class="dash-page sx-page" aria-labelledby="sx-title">
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
          <span class="pd-crumb-current">Scrapers</span>
        </li>
      </ol>
    </nav>

    <div class="dash-board">

      <header class="dash-panel sx-console animate-reveal-up">
        <div class="sx-console-main">
          <div class="sx-head">
            <div class="sx-head-copy">
              <p class="sx-eyebrow"><?= lucide_icon('list-ordered', '', 14) ?>Cola de trabajo</p>
              <h1 id="sx-title" class="sx-title">Actualizar precios</h1>
            </div>
            <div class="sx-console-status">
              <?php if ($hasActive): ?>
                <span class="sx-refresh" data-sx-refresh title="La página se recarga sola mientras haya tareas activas">
                  <span class="sx-refresh-icon" data-sx-refresh-icon aria-hidden="true"><?= lucide_icon('refresh-cw', '', 13) ?></span>
                  <span class="sx-refresh-text" data-sx-refresh-text>Recarga en 20 s</span>
                  <button type="button" class="sx-refresh-btn" data-sx-refresh-toggle aria-label="Pausar recarga automática" title="Pausar recarga automática">
                    <span data-sx-refresh-btn-icon aria-hidden="true"><?= lucide_icon('pause', '', 13) ?></span>
                  </button>
                  <span class="visually-hidden" aria-live="polite" data-sx-refresh-announce></span>
                </span>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!$tableExists): ?>
            <p class="sx-alert">
              <?= lucide_icon('database-zap', '', 16) ?>
              <span><strong>No se encontró la tabla de tareas.</strong> Las actualizaciones quedan deshabilitadas hasta que el administrador del sistema la configure.</span>
            </p>
          <?php endif; ?>

          <section class="sx-now <?= $current ? 'is-run' : ($pending ? 'is-wait' : 'is-idle') ?>" aria-labelledby="sx-now-label">
            <h2 id="sx-now-label" class="sx-now-tab">Ahora</h2>

            <div class="sx-now-body">
              <?php if ($current): ?>
                <div class="sx-now-main">
                  <span class="sx-now-icon sx-spin" aria-hidden="true"><?= lucide_icon('loader-circle', '', 18) ?></span>
                  <div class="sx-now-copy">
                    <strong class="sx-now-title"><?= e($current['name']) ?> <small>#<?= $current['id'] ?></small></strong>
                    <span class="sx-now-meta">Empezó a las <?= e($current['start']) ?> · lleva <?= e(sx_duration($current['elapsed'])) ?></span>
                  </div>
                  <span class="sx-now-eta <?= $current['late'] ? 'is-late' : '' ?>">
                    <?php if ($current['expected'] === null): ?>
                      Sin referencia previa
                    <?php elseif ($current['late']): ?>
                      <?= lucide_icon('snail', '', 13) ?>Más lenta que lo habitual (≈ <?= e(sx_duration($current['expected'])) ?>)
                    <?php else: ?>
                      Faltan ≈ <?= e(sx_duration($current['expected'] - $current['elapsed'])) ?>
                    <?php endif; ?>
                  </span>
                </div>
                <div
                  class="sx-progress <?= $current['pct'] === null || $current['late'] ? 'is-loose' : '' ?>"
                  role="progressbar"
                  aria-label="Avance estimado"
                  <?php if ($current['pct'] !== null && !$current['late']): ?>
                    aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $current['pct'] ?>"
                  <?php endif; ?>
                >
                  <span style="width: <?= $current['pct'] !== null && !$current['late'] ? $current['pct'] : 100 ?>%"></span>
                </div>
              <?php elseif ($pending): ?>
                <?php
                  $oldestWait = $now - (sx_ts($pending[0]['created_at']) ?? $now);
                  $queueStuck = $oldestWait > 120;
                ?>
                <div class="sx-now-main">
                  <span class="sx-now-icon" aria-hidden="true"><?= lucide_icon($queueStuck ? 'plug-zap' : 'hourglass', '', 18) ?></span>
                  <div class="sx-now-copy">
                    <strong class="sx-now-title"><?= $queueStuck ? 'La cola está detenida' : 'Preparando la siguiente tarea' ?></strong>
                    <span class="sx-now-meta">
                      <?php if ($queueStuck): ?>
                        La primera tarea lleva <?= e(sx_duration($oldestWait)) ?> esperando. Revisa que el proceso de fondo esté encendido.
                      <?php else: ?>
                        Está en cola hace <?= e(sx_duration($oldestWait)) ?> y debería empezar en unos segundos.
                      <?php endif; ?>
                    </span>
                  </div>
                </div>
              <?php else: ?>
                <div class="sx-now-main">
                  <span class="sx-now-icon" aria-hidden="true"><?= lucide_icon('coffee', '', 18) ?></span>
                  <div class="sx-now-copy">
                    <strong class="sx-now-title">Proceso libre</strong>
                    <span class="sx-now-meta">
                      <?php if ($stalest): ?>
                        <?= e($stalest['label']) ?> es la tienda con los precios más antiguos.
                      <?php elseif (!$tableExists): ?>
                        La cola no está disponible.
                      <?php else: ?>
                        Todas las tiendas se leyeron en las últimas 24 h.
                      <?php endif; ?>
                    </span>
                  </div>
                  <?php if ($stalest && $tableExists): ?>
                    <form method="post" class="m-0">
                      <input type="hidden" name="action" value="queue_scraper">
                      <input type="hidden" name="job" value="<?= e($stalest['key']) ?>">
                      <?= $returnFields ?>
                      <button type="submit" class="sx-btn is-sm"><?= lucide_icon('refresh-cw', '', 13) ?>Empezar por <?= e($stalest['label']) ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <ol class="sx-queue" aria-label="Orden de la cola">
                <?php foreach (array_slice($pending, 0, 3) as $pos => $job): ?>
                  <li class="sx-queue-item">
                    <span class="sx-queue-pos"><?= $pos + 1 ?></span>
                    <span class="sx-queue-name"><?= e($jobName($job)) ?></span>
                    <span class="sx-queue-wait"><?= e(sx_duration($now - (sx_ts($job['created_at']) ?? $now))) ?></span>
                  </li>
                <?php endforeach; ?>
                <?php if (count($pending) > 3): ?>
                  <li class="sx-queue-more">+<?= count($pending) - 3 ?> más</li>
                <?php endif; ?>
                <?php if (!$pending): ?>
                  <li class="sx-queue-empty">
                    <?= lucide_icon('inbox', '', 13) ?>
                    <?= $current ? 'No hay más tareas en cola' : 'Cola vacía' ?>
                  </li>
                <?php endif; ?>
              </ol>
            </div>
          </section>

          <footer class="sx-month">
            <div class="sx-month-head">
              <span class="sx-month-title">Últimos <?= SX_DAYS ?> días</span>
              <span class="sx-month-stats">
                <span class="sx-month-today">
                  Hoy <b><?= $fmt($todayFinished) ?></b> <?= $todayFinished === 1 ? 'terminada' : 'terminadas' ?>
                  <?php if ($today['error'] > 0): ?>
                    <em class="sx-month-err"><?= lucide_icon('triangle-alert', '', 12) ?><?= (int) $today['error'] ?> con error</em>
                  <?php endif; ?>
                </span>
                <?php if ($successPct !== null): ?>
                  <span><b><?= $successPct ?>%</b> éxito</span>
                <?php endif; ?>
                <?php if ($month['avg'] !== null): ?>
                  <span><b><?= e(sx_duration($month['avg'])) ?></b> promedio</span>
                <?php endif; ?>
                <span><b><?= $fmt($month['total']) ?></b> <?= $month['total'] === 1 ? 'ejecución' : 'ejecuciones' ?></span>
              </span>
            </div>
            <ol class="sx-days" aria-label="Actividad por día">
              <?php foreach ($days as $day): ?>
                <li class="<?= trim($day['tone'] . ($day['today'] ? ' is-today' : '')) ?>" title="<?= e($day['title']) ?>">
                  <span class="visually-hidden"><?= e($day['title']) ?></span>
                </li>
              <?php endforeach; ?>
            </ol>
            <div class="sx-days-axis" aria-hidden="true">
              <span><?= e(date('d/m', strtotime('-' . (SX_DAYS - 1) . ' days', $now))) ?></span>
              <span class="sx-days-legend">
                <i class="is-done"></i>Bien
                <i class="is-mid"></i>Mixto
                <i class="is-error"></i>Error
                <i class="is-off"></i>Cancelado
              </span>
              <span>Hoy</span>
            </div>
          </footer>
        </div>

        <aside class="sx-launch <?= $freshTone ?>" aria-labelledby="sx-launch-title">
          <div class="sx-launch-head">
            <span class="sx-launch-icon" aria-hidden="true"><?= lucide_icon('refresh-ccw-dot', '', 20) ?></span>
            <div>
              <h2 id="sx-launch-title" class="sx-launch-title">Actualización completa</h2>
              <p class="sx-launch-sub">Las <?= count($storeJobs) ?> tiendas en una sola tarea</p>
            </div>
          </div>

          <div class="sx-fresh">
            <div class="sx-fresh-top">
              <span class="sx-fresh-value"><?= $freshPct ?><small>%</small></span>
              <span class="sx-fresh-label">del catálogo con precio de las últimas 24 h</span>
            </div>
            <div
              class="sx-fresh-bar"
              role="meter"
              aria-label="Precios al día"
              aria-valuemin="0"
              aria-valuemax="100"
              aria-valuenow="<?= $freshPct ?>"
            >
              <?php foreach ($stores as $store): ?>
                <?php if ($store['products']): ?>
                  <span
                    style="flex-grow: <?= $store['products'] ?>; --s-c: <?= $store['color'] ?>; --s-fill: <?= round($store['fresh'] * 100 / $store['products']) ?>%"
                    title="<?= e($store['label']) ?>: <?= $fmt($store['fresh']) ?> de <?= $fmt($store['products']) ?> al día"
                  ></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <p class="sx-fresh-note">
              <strong><?= $fmt(max(0, $catalog['total'] - $catalog['fresh'])) ?></strong> de <?= $fmt($catalog['total']) ?> productos esperan una lectura nueva.
            </p>
          </div>

          <?php $st = $fullLast ? sx_status((string) $fullLast['status']) : null; ?>
          <p class="sx-launch-last <?= $st['tone'] ?? 'is-off' ?>">
            <span class="sx-launch-last-icon" aria-hidden="true"><?= lucide_icon($st['icon'] ?? 'circle-dashed', '', 14) ?></span>
            <span class="sx-launch-last-text">
              <?php if ($st): ?>
                Última: <b><?= e(mb_strtolower($st['label'])) ?></b> <?= e(sx_ago($fullLastEnd, $now)) ?>
              <?php else: ?>
                Todavía no se ejecutó
              <?php endif; ?>
            </span>
            <?php if ($st && $fullLastTook !== null): ?>
              <span class="sx-launch-last-took" title="Duración"><?= lucide_icon('timer', '', 13) ?><?= e(sx_duration($fullLastTook)) ?></span>
            <?php endif; ?>
          </p>

          <?php if ($fullActive): ?>
            <div class="sx-launch-busy">
              <span class="sx-launch-state <?= sx_status((string) $fullActive['status'])['tone'] ?>">
                <span class="<?= $fullActive['status'] === 'running' ? 'sx-spin' : '' ?>" aria-hidden="true">
                  <?= lucide_icon(sx_status((string) $fullActive['status'])['icon'], '', 16) ?>
                </span>
                <?= $fullActive['status'] === 'running' ? 'En marcha' : 'Esperando turno' ?>
                <small>#<?= (int) $fullActive['id'] ?></small>
              </span>
              <form method="post" class="m-0">
                <input type="hidden" name="action" value="cancel_job">
                <input type="hidden" name="job_id" value="<?= (int) $fullActive['id'] ?>">
                <?= $returnFields ?>
                <button type="submit" class="sx-btn is-stop"><?= lucide_icon('square', '', 14) ?>Detener</button>
              </form>
            </div>
          <?php else: ?>
            <form method="post" class="m-0">
              <input type="hidden" name="action" value="queue_scraper">
              <input type="hidden" name="job" value="all">
              <?= $returnFields ?>
              <button type="submit" class="sx-btn is-primary is-block" <?= $tableExists ? '' : 'disabled' ?>>
                <?= lucide_icon('play', '', 16) ?>Iniciar actualización completa
              </button>
            </form>
          <?php endif; ?>
        </aside>
      </header>

      <section class="dash-panel sx-stores animate-reveal-up" aria-labelledby="sx-stores-title" style="--lane-accent: var(--accent)">
        <header class="dash-panel-head">
          <span class="dash-panel-icon" aria-hidden="true"><?= lucide_icon('store', '', 18) ?></span>
          <div>
            <h2 id="sx-stores-title" class="dash-panel-title">Por tienda</h2>
            <p class="dash-panel-caption">
              <?php if ($fullActive): ?>
                <?= lucide_icon('info', '', 13) ?>La actualización completa en curso ya incluye estas tiendas.
              <?php else: ?>
                Actualiza una tienda específica sin esperar la actualización completa.
              <?php endif; ?>
            </p>
          </div>
          <a href="admin_tiendas.php" class="dash-panel-link">Gestionar tiendas<?= lucide_icon('arrow-right', '', 14) ?></a>
        </header>

        <ul class="sx-store-grid">
          <?php foreach ($stores as $key => $store): ?>
            <li class="sx-store <?= $store['active'] ? 'is-busy' : '' ?>" style="--s-c: <?= $store['color'] ?>">
              <div class="sx-store-top">
                <span class="sx-store-avatar" aria-hidden="true"><?= e($store['initials']) ?></span>
                <div class="sx-store-id">
                  <strong class="sx-store-name"><?= e($store['label']) ?></strong>
                  <span class="sx-store-count">
                    <?= $store['products'] !== null ? $fmt($store['products']) . ' productos' : 'Sin datos de catálogo' ?>
                  </span>
                </div>
                <span class="sx-fresh-chip <?= $store['tone'] ?>" title="Última lectura de precios">
                  <span class="dash-dot" aria-hidden="true"></span>
                  <?= $store['lastTs'] !== null ? e(sx_ago($store['lastTs'], $now)) : 'Sin leer' ?>
                </span>
              </div>

              <div class="sx-pulse">
                <div class="sx-pulse-head">
                  <span>Últimas ejecuciones</span>
                  <?php if ($store['finished'] > 0): ?>
                    <b><?= $store['ok'] ?>/<?= $store['finished'] ?> bien</b>
                  <?php endif; ?>
                </div>
                <div class="sx-pulse-strip" aria-hidden="true">
                  <?php for ($s = 0; $s < SX_PULSE_SLOTS - count($store['runs']); $s++): ?>
                    <span></span>
                  <?php endfor; ?>
                  <?php foreach ($store['runs'] as $run): ?>
                    <?php $st = sx_status((string) $run['status']); ?>
                    <span class="<?= $st['tone'] ?>" title="<?= e($st['label']) ?>"></span>
                  <?php endforeach; ?>
                </div>
              </div>

              <dl class="sx-store-meta">
                <div>
                  <dt>Último éxito</dt>
                  <dd><?= e(sx_ago($store['lastDone'], $now)) ?></dd>
                </div>
                <div>
                  <dt>Tarda</dt>
                  <dd><?= $store['avg'] !== null ? '≈ ' . e(sx_duration($store['avg'])) : '—' ?></dd>
                </div>
                <div>
                  <dt>Al día</dt>
                  <dd><?= $store['products'] ? round($store['fresh'] * 100 / $store['products']) . '%' : '—' ?></dd>
                </div>
              </dl>

              <form method="post" class="sx-store-action">
                <?= $returnFields ?>
                <?php if ($store['active']): ?>
                  <?php $st = sx_status((string) $store['active']['status']); ?>
                  <input type="hidden" name="action" value="cancel_job">
                  <input type="hidden" name="job_id" value="<?= (int) $store['active']['id'] ?>">
                  <span class="sx-store-state <?= $st['tone'] ?>">
                    <span class="<?= $store['active']['status'] === 'running' ? 'sx-spin' : '' ?>" aria-hidden="true"><?= lucide_icon($st['icon'], '', 14) ?></span>
                    <?= e($st['label']) ?>
                  </span>
                  <button type="submit" class="sx-btn is-stop is-sm" aria-label="Detener <?= e($store['label']) ?>">
                    <?= lucide_icon('square', '', 13) ?>Detener
                  </button>
                <?php else: ?>
                  <input type="hidden" name="action" value="queue_scraper">
                  <input type="hidden" name="job" value="<?= e($key) ?>">
                  <button type="submit" class="sx-btn is-block" <?= $tableExists ? '' : 'disabled' ?>>
                    <?= lucide_icon('refresh-cw', '', 14) ?>Actualizar <?= e($store['label']) ?>
                  </button>
                <?php endif; ?>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="dash-panel sx-history animate-reveal-up" aria-labelledby="sx-history-title" style="--lane-accent: var(--brand)">
        <header class="dash-panel-head">
          <span class="dash-panel-icon" aria-hidden="true"><?= lucide_icon('history', '', 18) ?></span>
          <div>
            <h2 id="sx-history-title" class="dash-panel-title">Historial</h2>
            <p class="dash-panel-caption">
              <?php if ($historyTotal > 0): ?>
                <?= $fmt($fromItem) ?>–<?= $fmt($toItem) ?> de <?= $fmt($historyTotal) ?> tareas · abre el registro para ver qué devolvió cada una
              <?php else: ?>
                Abre el registro de una tarea para ver qué devolvió
              <?php endif; ?>
            </p>
          </div>
        </header>

        <?php if ($tableExists): ?>
          <div class="sx-history-bar">
            <nav class="sx-chips" aria-label="Filtrar historial">
              <?php foreach ($historyFilters as $fKey => $filter): ?>
                <a
                  href="<?= e(sx_url($fKey, $filas)) ?>"
                  class="sx-filter <?= $estado === $fKey ? 'is-active' : '' ?>"
                  <?= $estado === $fKey ? 'aria-current="true"' : '' ?>
                >
                  <?= e($filter['label']) ?>
                  <span class="sx-filter-count"><?= $fmt($filterCounts[$fKey]) ?></span>
                </a>
              <?php endforeach; ?>
            </nav>

            <form method="get" class="sx-rows-form">
              <?php if ($estado !== 'todas'): ?>
                <input type="hidden" name="estado" value="<?= e($estado) ?>">
              <?php endif; ?>
              <label class="ap-rows-select <?= $filas !== SX_FILAS_DEFAULT ? 'is-set' : '' ?>" title="Filas por página">
                <?= lucide_icon('rows-3', 'ap-rows-select-icon', 15) ?>
                <span class="ap-rows-select-label">Filas</span>
                <span class="ap-rows-select-value" aria-hidden="true"><?= $filas ?></span>
                <select name="filas" class="ap-rows-select-input" aria-label="Filas por página" data-sx-rows>
                  <?php foreach (SX_FILAS as $option): ?>
                    <option value="<?= $option ?>" <?= $option === $filas ? 'selected' : '' ?>><?= $option ?></option>
                  <?php endforeach; ?>
                </select>
                <?= lucide_icon('chevron-down', 'ap-rows-select-caret', 13) ?>
              </label>
              <noscript><button type="submit" class="sx-btn is-sm">Aplicar</button></noscript>
            </form>
          </div>
        <?php endif; ?>

        <?php if ($jobHistory): ?>
          <div class="sx-table">
            <div class="sx-cols" aria-hidden="true">
              <span>Tarea</span>
              <span>Estado</span>
              <span>Espera</span>
              <span>Duración</span>
              <span>Finalizó</span>
              <span></span>
            </div>

            <ul class="sx-rows">
              <?php foreach ($jobHistory as $job): ?>
                <?php
                  $st = sx_status((string) $job['status']);
                  $created = sx_ts($job['created_at']);
                  $started = sx_ts($job['started_at']);
                  $finished = sx_ts($job['finished_at']);
                  $isActive = in_array($job['status'], ['pending', 'running'], true);
                  $waitEnd = $started ?? ($job['status'] === 'pending' ? $now : null);
                  $wait = $created !== null && $waitEnd !== null ? $waitEnd - $created : null;
                  $runEnd = $finished ?? ($job['status'] === 'running' ? $now : null);
                  $duration = $started !== null && $runEnd !== null ? $runEnd - $started : null;
                  $hasLog = (int) $job['output_len'] > 0;
                  $logId = 'sx-log-' . (int) $job['id'];
                ?>
                <li class="sx-item">
                  <div class="sx-row <?= $st['tone'] ?>">
                    <div class="sx-task">
                      <span class="sx-task-icon <?= $job['status'] === 'running' ? 'sx-spin' : '' ?>" aria-hidden="true"><?= lucide_icon($st['icon'], '', 16) ?></span>
                      <div class="sx-task-copy">
                        <span class="sx-task-name"><?= e($jobName($job)) ?></span>
                        <span class="sx-task-meta">
                          <span class="sx-task-id">#<?= (int) $job['id'] ?></span>
                          <span title="<?= e((string) $job['created_at']) ?>">Creada <?= e(sx_ago($created, $now)) ?></span>
                        </span>
                      </div>
                    </div>
                    <span class="sx-cell-status"><span class="sx-chip <?= $st['tone'] ?>"><?= e($st['label']) ?></span></span>
                    <span class="sx-cell" data-label="Espera"><?= e(sx_duration($wait)) ?></span>
                    <span class="sx-cell" data-label="Duración">
                      <?= e(sx_duration($duration)) ?><?php if ($job['status'] === 'running'): ?><small> y sigue</small><?php endif; ?>
                    </span>
                    <span class="sx-cell" data-label="Finalizó" title="<?= e((string) ($job['finished_at'] ?? '')) ?>">
                      <?= $finished !== null ? e(date('d/m H:i', $finished)) : '—' ?>
                    </span>
                    <div class="sx-actions">
                      <?php if ($hasLog): ?>
                        <button
                          type="button"
                          class="sx-log-toggle"
                          aria-expanded="false"
                          aria-controls="<?= $logId ?>"
                          data-sx-log
                        >
                          <?= lucide_icon('terminal', '', 14) ?><span>Registro</span><?= lucide_icon('chevron-down', 'sx-log-caret', 14) ?>
                        </button>
                      <?php endif; ?>
                      <?php if ($isActive): ?>
                        <form method="post" class="m-0">
                          <input type="hidden" name="action" value="cancel_job">
                          <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                          <?= $returnFields ?>
                          <button type="submit" class="sx-btn is-stop is-sm"><?= lucide_icon('square', '', 13) ?>Detener</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>

                  <?php if ($hasLog): ?>
                    <div class="sx-log" id="<?= $logId ?>" hidden>
                      <div class="sx-log-bar">
                        <span><?= lucide_icon('file-code-2', '', 13) ?><?= e(basename((string) $job['command_path'])) ?></span>
                        <?php if ((int) $job['output_len'] > SX_LOG_TAIL): ?>
                          <span>Últimos <?= $fmt(SX_LOG_TAIL) ?> de <?= $fmt((int) $job['output_len']) ?> caracteres</span>
                        <?php endif; ?>
                      </div>
                      <pre class="sx-log-output"><?= e((string) $job['output_tail']) ?></pre>
                    </div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <?php if ($totalPages > 1): ?>
            <nav class="ap-pager" aria-label="Paginación del historial">
              <span class="ap-pager-info">Página <strong><?= $fmt($page) ?></strong> de <?= $fmt($totalPages) ?></span>
              <div class="ap-pages">
                <a
                  class="ap-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>"
                  href="<?= e(sx_url($estado, $filas, max(1, $page - 1))) ?>"
                  aria-label="Página anterior"
                  <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                ><?= lucide_icon('chevron-left', '', 15) ?></a>
                <?php foreach ($pageItems as $item): ?>
                  <?php if ($item === null): ?>
                    <span class="ap-page-gap" aria-hidden="true">…</span>
                  <?php else: ?>
                    <a
                      class="ap-page-btn <?= $item === $page ? 'is-current' : '' ?>"
                      href="<?= e(sx_url($estado, $filas, $item)) ?>"
                      <?= $item === $page ? 'aria-current="page"' : '' ?>
                    ><?= $fmt($item) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
                <a
                  class="ap-page-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>"
                  href="<?= e(sx_url($estado, $filas, min($totalPages, $page + 1))) ?>"
                  aria-label="Página siguiente"
                  <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                ><?= lucide_icon('chevron-right', '', 15) ?></a>
              </div>
            </nav>
          <?php endif; ?>
        <?php elseif ($tableExists): ?>
          <div class="dash-empty">
            <?= lucide_icon($estado === 'todas' ? 'inbox' : 'filter-x', '', 28) ?>
            <strong><?= $estado === 'todas' ? 'Todavía no hay tareas' : 'Nada con este filtro' ?></strong>
            <span>
              <?php if ($estado === 'todas'): ?>
                Inicia una actualización y su avance aparecerá aquí.
              <?php else: ?>
                <a href="<?= e(sx_url('todas', $filas)) ?>">Ver todas las tareas</a>
              <?php endif; ?>
            </span>
          </div>
        <?php else: ?>
          <div class="dash-empty">
            <?= lucide_icon('database-zap', '', 28) ?>
            <strong>Historial no disponible</strong>
            <span>Falta la tabla de tareas en la base de datos.</span>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</section>

<script>
(function() {
  document.querySelectorAll('[data-sx-log]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const log = document.getElementById(btn.getAttribute('aria-controls'));
      const open = btn.getAttribute('aria-expanded') !== 'true';
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      log.hidden = !open;
      if (open) {
        const pre = log.querySelector('pre');
        pre.scrollTop = pre.scrollHeight;
      }
    });
  });

  const rowsSelect = document.querySelector('[data-sx-rows]');
  if (rowsSelect) {
    rowsSelect.addEventListener('change', function() {
      rowsSelect.parentElement.querySelector('.ap-rows-select-value').textContent = rowsSelect.value;
      rowsSelect.form.requestSubmit();
    });
  }

  <?php if ($hasActive): ?>
  const INTERVAL = 20;
  const RESUME_GRACE = 5;
  const box = document.querySelector('[data-sx-refresh]');
  const text = box.querySelector('[data-sx-refresh-text]');
  const icon = box.querySelector('[data-sx-refresh-icon]');
  const toggle = box.querySelector('[data-sx-refresh-toggle]');
  const toggleIcon = box.querySelector('[data-sx-refresh-btn-icon]');
  const announce = box.querySelector('[data-sx-refresh-announce]');

  const reasons = {
    manual: 'la pausaste manualmente',
    log: 'hay un registro abierto',
    selection: 'hay texto seleccionado'
  };

  let remaining = INTERVAL;
  let manual = false;
  let lastReason = null;

  const currentReason = function() {
    if (manual) return 'manual';
    if (document.querySelector('[data-sx-log][aria-expanded="true"]')) return 'log';
    if (String(window.getSelection()).trim().length > 0) return 'selection';
    return null;
  };

  const setIcon = function(host, name) {
    if (window.setLucideIcon && host.dataset.icon !== name) {
      host.dataset.icon = name;
      window.setLucideIcon(host, name);
    }
  };

  const render = function(reason) {
    const paused = reason !== null;
    box.classList.toggle('is-paused', paused);
    text.textContent = paused ? 'Recarga pausada: ' + reasons[reason] : 'Recarga en ' + remaining + ' s';
    setIcon(icon, paused ? 'circle-pause' : 'refresh-cw');
    setIcon(toggleIcon, manual ? 'play' : 'pause');
    const label = manual ? 'Reanudar recarga automática' : 'Pausar recarga automática';
    toggle.setAttribute('aria-label', label);
    toggle.title = label;

    if (reason !== lastReason) {
      announce.textContent = paused
        ? 'Recarga automática pausada porque ' + reasons[reason] + '.'
        : 'Recarga automática reanudada.';
      if (lastReason !== null && !paused) {
        remaining = Math.max(remaining, RESUME_GRACE);
        text.textContent = 'Recarga en ' + remaining + ' s';
      }
      lastReason = reason;
    }
  };

  const tick = function() {
    const reason = currentReason();
    if (reason === null && lastReason === null) {
      remaining--;
      if (remaining <= 0) {
        text.textContent = 'Recargando…';
        window.location.reload();
        return;
      }
    }
    render(reason);
  };

  toggle.addEventListener('click', function() {
    manual = !manual;
    render(currentReason());
  });

  document.addEventListener('selectionchange', function() { render(currentReason()); });
  document.querySelectorAll('[data-sx-log]').forEach(function(btn) {
    btn.addEventListener('click', function() { render(currentReason()); });
  });

  render(currentReason());
  setInterval(tick, 1000);
  <?php endif; ?>
})();
</script>

<?php render_footer(); ?>
