<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

$status = trim((string) ($_GET['estado'] ?? ''));
$allowedStatuses = ['pendiente', 'leido', 'archivado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0 && in_array($action, ['mark_read', 'archive', 'mark_pending', 'delete'], true)) {
        if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM sugerencias WHERE idsugerencia = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
        } else {
            $newStatus = match ($action) {
                'mark_read' => 'leido',
                'archive' => 'archivado',
                default => 'pendiente',
            };

            $stmt = $pdo->prepare("
                UPDATE sugerencias
                SET sug_estado = :estado
                WHERE idsugerencia = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':estado' => $newStatus,
                ':id' => $id,
            ]);
        }
    }

    $redirect = 'admin_sugerencias.php';
    if ($status !== '' && in_array($status, $allowedStatuses, true)) {
        $redirect .= '?estado=' . rawurlencode($status);
    }

    header('Location: ' . $redirect);
    exit;
}

$where = '';
$params = [];

if (in_array($status, $allowedStatuses, true)) {
    $where = 'WHERE sug_estado = :estado';
    $params[':estado'] = $status;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM sugerencias
    {$where}
    ORDER BY
        CASE sug_estado
            WHEN 'pendiente' THEN 0
            WHEN 'leido' THEN 1
            ELSE 2
        END,
        sug_fecha DESC,
        idsugerencia DESC
");
$stmt->execute($params);
$suggestions = $stmt->fetchAll();

$stats = [
    'pendiente' => (int) $pdo->query("SELECT COUNT(*) FROM sugerencias WHERE sug_estado = 'pendiente'")->fetchColumn(),
    'leido' => (int) $pdo->query("SELECT COUNT(*) FROM sugerencias WHERE sug_estado = 'leido'")->fetchColumn(),
    'archivado' => (int) $pdo->query("SELECT COUNT(*) FROM sugerencias WHERE sug_estado = 'archivado'")->fetchColumn(),
    'total' => (int) $pdo->query("SELECT COUNT(*) FROM sugerencias")->fetchColumn(),
];

render_head('Sugerencias');
?>
<link rel="stylesheet" href="./css/admin.css">
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
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <div class="admin-kicker mb-2">Feedback</div>
          <h1 class="display-6 fw-bold mb-2">Sugerencias de usuarios</h1>
          <p class="text-body-secondary mb-0">Revisá ideas, mejoras y reportes generales enviados desde el footer.</p>
        </div>
        <a href="admin.php" class="btn btn-outline-primary rounded-pill px-4">
          <i class="bi bi-arrow-left me-2 z-5"></i>Volver al panel
        </a>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Pendientes</div>
          <div class="admin-stat-value"><?= number_format($stats['pendiente'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Leídas</div>
          <div class="admin-stat-value"><?= number_format($stats['leido'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Archivadas</div>
          <div class="admin-stat-value"><?= number_format($stats['archivado'], 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Total</div>
          <div class="admin-stat-value"><?= number_format($stats['total'], 0, ',', '.') ?></div>
        </div>
      </div>
    </div>

    <div class="admin-table-card p-4">
      <div class="admin-toolbar mb-3">
        <div>
          <div class="admin-kicker">Listado</div>
          <h2 class="h4 fw-bold mb-0">Sugerencias recibidas</h2>
        </div>

        <form method="get" class="d-flex gap-2 flex-wrap">
          <select name="estado" class="form-select">
            <option value="">Todos los estados</option>
            <option value="pendiente" <?= $status === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
            <option value="leido" <?= $status === 'leido' ? 'selected' : '' ?>>Leído</option>
            <option value="archivado" <?= $status === 'archivado' ? 'selected' : '' ?>>Archivado</option>
          </select>
          <button type="submit" class="btn btn-primary rounded-pill px-4">Filtrar</button>
          <a href="admin_sugerencias.php" class="btn btn-outline-secondary rounded-pill px-4">Limpiar</a>
        </form>
      </div>

      <?php if ($suggestions): ?>
        <div class="table-responsive">
          <table class="table admin-table align-middle">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Usuario</th>
                <th>Asunto</th>
                <th>Sugerencia</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($suggestions as $item): ?>
                <tr>
                  <td><?= e(date('d/m/Y H:i', strtotime((string) $item['sug_fecha']))) ?></td>
                  <td>
                    <div class="title"><?= e($item['sug_nombre'] ?: 'Anónimo') ?></div>
                    <div class="subtitle"><?= e($item['sug_email'] ?: 'Sin email') ?></div>
                  </td>
                  <td><?= e($item['sug_asunto'] ?: 'Sin asunto') ?></td>
                  <td style="min-width: 320px; white-space: pre-line;"><?= e($item['sug_detalle']) ?></td>
                  <td>
                    <?php
                    $badgeClass = match ($item['sug_estado']) {
                        'pendiente' => 'bg-warning text-dark',
                        'leido' => 'bg-info text-dark',
                        default => 'bg-secondary',
                    };
                    ?>
                    <span class="badge rounded-pill <?= $badgeClass ?>">
                      <?= e(ucfirst((string) $item['sug_estado'])) ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="d-flex gap-2 justify-content-end flex-wrap">
                      <form method="post">
                        <input type="hidden" name="id" value="<?= (int) $item['idsugerencia'] ?>">
                        <input type="hidden" name="action" value="mark_pending">
                        <button type="submit" class="btn btn-sm btn-outline-warning rounded-pill">Pendiente</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="id" value="<?= (int) $item['idsugerencia'] ?>">
                        <input type="hidden" name="action" value="mark_read">
                        <button type="submit" class="btn btn-sm btn-outline-info rounded-pill">Leído</button>
                      </form>

                      <form method="post">
                        <input type="hidden" name="id" value="<?= (int) $item['idsugerencia'] ?>">
                        <input type="hidden" name="action" value="archive">
                        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill">Archivar</button>
                      </form>

                      <form method="post" onsubmit="return confirm('¿Eliminar esta sugerencia?');">
                        <input type="hidden" name="id" value="<?= (int) $item['idsugerencia'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill">Eliminar</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="admin-empty">Todavía no hay sugerencias registradas.</div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php render_footer(); ?>