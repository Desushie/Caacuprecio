<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

/*
|--------------------------------------------------------------------------
| Configuración de scrapers
|--------------------------------------------------------------------------
| Reemplazá las rutas placeholder por tus scripts reales.
| Esta versión NO ejecuta el scraper directamente.
| Solo crea jobs en la tabla scraper_jobs para que un worker externo los procese.
*/
$scraperJobs = [
    'all' => [
        'label' => 'Ejecutar scraper completo',
        'path'  => __DIR__ . '/py/run_all.py',
        'type'  => 'principal',
    ],
    'alex' => [
        'label' => 'Alex',
        'path'  => __DIR__ . '/py/run_alex.py',
        'type'  => 'tienda',
    ],
    'bristol' => [
        'label' => 'Bristol',
        'path'  => __DIR__ . '/py/run_bristol.py',
        'type'  => 'tienda',
    ],
    'chacomer' => [
        'label' => 'Chacomer',
        'path'  => __DIR__ . '/py/run_chacomer.py',
        'type'  => 'tienda',
    ],
    'comfort_house' => [
        'label' => 'Comfort House',
        'path'  => __DIR__ . '/py/run_comfort_house.py',
        'type'  => 'tienda',
    ],
    'computex' => [
        'label' => 'Computex',
        'path'  => __DIR__ . '/py/run_computex.py',
        'type'  => 'tienda',
    ],
    'gonzalito' => [
        'label' => 'Tienda Gonzalito',
        'path'  => __DIR__ . '/py/run_gonzalito.py',
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

$flash = null;

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
| Acciones
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'queue_scraper') {
        $jobKey = trim((string) ($_POST['job'] ?? ''));

        if (!isset($scraperJobs[$jobKey])) {
            $flash = ['type' => 'danger', 'message' => 'No se encontró el scraper solicitado.'];
        } else {
            $job = $scraperJobs[$jobKey];

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
                'message' => 'El scraper "' . $job['label'] . '" fue enviado a la cola.',
            ];
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

            if ($stmt->rowCount() > 0) {
                $flash = [
                    'type' => 'warning',
                    'message' => 'El job fue marcado como cancelado.',
                ];
            } else {
                $flash = [
                    'type' => 'danger',
                    'message' => 'No se pudo cancelar el job o ya terminó.',
                ];
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Datos del panel
|--------------------------------------------------------------------------
*/
$stats = [
    'jobs_total' => count($scraperJobs),
    'jobs_tiendas' => count(array_filter($scraperJobs, fn($job) => $job['type'] === 'tienda')),
    'pendientes' => 0,
    'ejecutando' => 0,
];

$jobHistory = [];
$lastOutput = null;

if ($tableExists) {
    $stats['pendientes'] = (int) $pdo->query("SELECT COUNT(*) FROM scraper_jobs WHERE status = 'pending'")->fetchColumn();
    $stats['ejecutando'] = (int) $pdo->query("SELECT COUNT(*) FROM scraper_jobs WHERE status = 'running'")->fetchColumn();

    $jobHistory = $pdo->query("
        SELECT id, job_key, job_label, command_path, status, output, created_at, started_at, finished_at
        FROM scraper_jobs
        ORDER BY id DESC
        LIMIT 12
    ")->fetchAll();

    $outputStmt = $pdo->query("
        SELECT id, job_label, command_path, status, output, created_at, started_at, finished_at
        FROM scraper_jobs
        WHERE output IS NOT NULL AND output <> ''
        ORDER BY id DESC
        LIMIT 1
    ");
    $lastOutput = $outputStmt->fetch();
}

function badge_class_for_status(string $status): string
{
    return match ($status) {
        'pending' => 'text-bg-warning',
        'running' => 'text-bg-primary',
        'done' => 'text-bg-success',
        'error' => 'text-bg-danger',
        'cancelled' => 'text-bg-secondary',
        default => 'text-bg-dark',
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Pendiente',
        'running' => 'Ejecutando',
        'done' => 'Completado',
        'error' => 'Error',
        'cancelled' => 'Cancelado',
        default => ucfirst($status),
    };
}

render_head('Scraper');
?>
<link rel="stylesheet" href="./css/admin.css">

<style>
.admin-scraper-card {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 1rem;
  min-height: 100%;
  padding: 1rem 1.1rem;
  border-radius: 1rem;
  border: 1px solid var(--border-soft);
  background: rgba(255,255,255,.03);
}
.admin-log-output {
  background: rgba(2, 6, 23, .72);
  color: #dbeafe;
  border: 1px solid rgba(148, 163, 184, .18);
  border-radius: 1rem;
  padding: 1rem 1.1rem;
  max-height: 360px;
  overflow: auto;
  font-size: .88rem;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
}
body.theme-light .admin-log-output {
  background: rgba(241, 245, 249, .95);
  color: #0f172a;
  border-color: rgba(148, 163, 184, .28);
}
body.theme-light .admin-scraper-card {
  background: rgba(255,255,255,.58);
}
</style>

<?php render_navbar('admin'); ?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="admin-shell">
  <div class="container">
    <div class="admin-hero p-4 p-lg-5 mb-4">
      <div class="row g-4 align-items-center">
        <div class="col-lg-8 position-relative z-1">
          <div class="admin-kicker mb-2">Actualización de datos</div>
          <h1 class="display-6 fw-bold mb-3">Gestión de actualizaciones</h1>
          <p class="text-body-secondary mb-4">
            Ejecutá actualizaciones de productos y precios desde distintas tiendas de forma segura y organizada.
          </p>
          <div class="d-flex flex-wrap gap-3">
            <a href="admin.php" class="btn btn-outline-primary rounded-pill px-4">
              <i class="bi bi-arrow-left me-2"></i>Volver al panel
            </a>
            <a href="admin_productos.php" class="btn btn-primary rounded-pill px-4">
              <i class="bi bi-box-seam me-2"></i>Productos
            </a>
            <a href="admin_tiendas.php" class="btn btn-outline-primary rounded-pill px-4">
              <i class="bi bi-shop me-2"></i>Tiendas
            </a>
          </div>
        </div>
        <div class="col-lg-4 position-relative z-1">
          <div class="admin-side-list">
            <div class="admin-side-item">
              <strong>Actualizaciones sin interrupciones</strong>
              <span class="text-body-secondary small">El sistema procesa las actualizaciones sin afectar el uso del sitio.</span>
            </div>
            <div class="admin-side-item">
              <strong>Control de procesos</strong>
              <span class="text-body-secondary small">Podés iniciar varias actualizaciones y ver su estado en tiempo real.</span>
            </div>
            <div class="admin-side-item">
              <strong>Optimizado para rendimiento</strong>
              <span class="text-body-secondary small">Diseñado para manejar grandes volúmenes de datos de forma eficiente.</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$tableExists): ?>
      <div class="alert alert-warning rounded-4 mb-4">
        No se encontró la configuración necesaria para procesar actualizaciones.
        Contactá al administrador del sistema.
      </div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="alert alert-<?= e($flash['type']) ?> rounded-4 mb-4"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Actualizaciones</div>
          <div class="admin-stat-value"><?= number_format($stats['jobs_total'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Por tienda</div>
          <div class="admin-stat-value"><?= number_format($stats['jobs_tiendas'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">En espera</div>
          <div class="admin-stat-value"><?= number_format($stats['pendientes'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">En proceso</div>
          <div class="admin-stat-value"><?= number_format($stats['ejecutando'], 0, ',', '.') ?></div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="admin-panel p-4 p-lg-5 h-100">
          <div class="admin-kicker mb-2">Principal</div>
          <h2 class="h4 fw-bold mb-2">Actualización completa</h2>
          <p class="text-body-secondary mb-4">
            Actualiza todos los productos y precios desde las tiendas disponibles.
          </p>

          <div class="admin-side-item mb-4">
            <strong><?= e($scraperJobs['all']['label']) ?></strong>
            <span class="text-body-secondary small"><?= e($scraperJobs['all']['path']) ?></span>
          </div>

          <form method="post" class="d-grid gap-2">
            <input type="hidden" name="action" value="queue_scraper">
            <input type="hidden" name="job" value="all">
            <button type="submit" class="btn btn-primary rounded-pill px-4 py-3" <?= $tableExists ? '' : 'disabled' ?>>
              <i class="bi bi-plus-circle me-2"></i>Iniciar actualización completa
            </button>
          </form>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="admin-panel p-4 p-lg-5 h-100">
          <div class="admin-toolbar mb-4">
            <div>
              <div class="admin-kicker">Por tienda</div>
              <h2 class="h4 fw-bold mb-0">Actualizaciones individuales</h2>
            </div>
          </div>

          <div class="row g-3">
            <?php foreach ($scraperJobs as $key => $job): ?>
              <?php if ($job['type'] !== 'tienda') continue; ?>
              <div class="col-sm-6 col-xl-4">
                <form method="post" class="h-100">
                  <input type="hidden" name="action" value="queue_scraper">
                  <input type="hidden" name="job" value="<?= e($key) ?>">

                  <div class="admin-scraper-card h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                      <span class="admin-shortcut-icon"><i class="bi bi-terminal"></i></span>
                      <strong class="d-block"><?= e($job['label']) ?></strong>
                    </div>

                    <div class="d-grid">
                      <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3" <?= $tableExists ? '' : 'disabled' ?>>
                        Actualizar
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="admin-panel p-4 p-lg-5 mt-4">
      <div class="admin-toolbar mb-4">
        <div>
          <div class="admin-kicker">Historial</div>
          <h2 class="h4 fw-bold mb-0">Historial de actualizaciones</h2>
        </div>
      </div>

      <?php if ($tableExists && $jobHistory): ?>
        <div class="table-responsive">
          <table class="table admin-table align-middle mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>Proceso</th>
                <th>Estado</th>
                <th>Creado</th>
                <th>Inicio</th>
                <th>Finalizado</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($jobHistory as $job): ?>
                <tr>
                  <td>#<?= (int) $job['id'] ?></td>
                  <td>
                    <div class="title"><?= e($job['job_label']) ?></div>
                    <div class="subtitle"><?= e($job['command_path']) ?></div>
                  </td>
                  <td>
                    <span class="badge <?= e(badge_class_for_status((string) $job['status'])) ?>">
                      <?= e(status_label((string) $job['status'])) ?>
                    </span>
                  </td>
                  <td><?= e((string) $job['created_at']) ?></td>
                  <td><?= e((string) ($job['started_at'] ?? '')) ?></td>
                  <td><?= e((string) ($job['finished_at'] ?? '')) ?></td>
                  <td>
                    <?php if (in_array($job['status'], ['pending', 'running'], true)): ?>
                      <form method="post" class="m-0">
                        <input type="hidden" name="action" value="cancel_job">
                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">Detener</button>
                      </form>
                    <?php else: ?>
                      <span class="text-body-secondary small">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php elseif ($tableExists): ?>
        <div class="admin-empty">Aún no hay actualizaciones registradas.</div>
      <?php endif; ?>
    </div>

    <?php if ($tableExists && $lastOutput): ?>
      <div class="admin-panel p-4 p-lg-5 mt-4">
        <div class="admin-kicker mb-2">Resultado</div>
        <h2 class="h4 fw-bold mb-2"><?= e((string) $lastOutput['job_label']) ?></h2>
        <div class="small text-body-secondary mb-3"><?= e((string) $lastOutput['command_path']) ?></div>
        <pre class="admin-log-output mb-0"><?= e((string) $lastOutput['output']) ?></pre>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php render_footer(); ?>
