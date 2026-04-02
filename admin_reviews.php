<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

$status = trim((string) ($_GET['estado'] ?? 'pendiente'));
$q = trim((string) ($_GET['q'] ?? ''));
$minReports = max(1, (int) ($_GET['min_reportes'] ?? 1));
$order = trim((string) ($_GET['orden'] ?? 'mas_reportadas'));

$allowedStatuses = ['pendiente', 'revisado', 'descartado', 'todos'];
$allowedOrders = ['mas_reportadas', 'mas_recientes', 'menos_reportadas', 'tienda_asc', 'estado'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'pendiente';
}
if (!in_array($order, $allowedOrders, true)) {
    $order = 'mas_reportadas';
}

function h(?string $value): string
{
    return e((string) ($value ?? ''));
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'pendiente' => 'bg-warning text-dark',
        'revisado' => 'bg-success',
        'descartado' => 'bg-secondary',
        default => 'bg-light text-dark',
    };
}

function report_reason_label(string $reason): string
{
    return match ($reason) {
        'spam' => 'Spam',
        'ofensivo' => 'Ofensivo',
        'informacion_falsa' => 'Información falsa',
        'lenguaje_inapropiado' => 'Lenguaje inapropiado',
        'otro' => 'Otro',
        default => ucfirst(str_replace('_', ' ', $reason)),
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $reviewId = (int) ($_POST['review_id'] ?? 0);
    $reportId = (int) ($_POST['report_id'] ?? 0);

    if ($action === 'mark_reviewed' && $reportId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'revisado'
            WHERE idreporte = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $reportId]);
    } elseif ($action === 'mark_discarded' && $reportId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'descartado'
            WHERE idreporte = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $reportId]);
    } elseif ($action === 'mark_all_reviewed' && $reviewId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'revisado'
            WHERE reviews_idreview = :review_id
        ");
        $stmt->execute([':review_id' => $reviewId]);
    } elseif ($action === 'mark_all_discarded' && $reviewId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_review_reportes
            SET rep_estado = 'descartado'
            WHERE reviews_idreview = :review_id
        ");
        $stmt->execute([':review_id' => $reviewId]);
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
    } elseif ($action === 'restore_review' && $reviewId > 0) {
        $stmt = $pdo->prepare("
            UPDATE tienda_reviews
            SET rev_activo = 1
            WHERE idreview = :review_id
            LIMIT 1
        ");
        $stmt->execute([':review_id' => $reviewId]);
    }

    $redirectParams = [
        'estado' => $status,
        'q' => $q,
        'min_reportes' => $minReports,
        'orden' => $order,
    ];
    header('Location: admin_reviews.php?' . http_build_query($redirectParams));
    exit;
}

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total_reportes,
        COUNT(DISTINCT reviews_idreview) AS total_reviews_reportadas,
        SUM(CASE WHEN rep_estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN rep_estado = 'revisado' THEN 1 ELSE 0 END) AS revisados,
        SUM(CASE WHEN rep_estado = 'descartado' THEN 1 ELSE 0 END) AS descartados
    FROM tienda_review_reportes
")->fetch();

$where = [];
$params = [];

if ($status !== 'todos') {
    $where[] = "rr.rep_estado = :estado";
    $params[':estado'] = $status;
}

if ($q !== '') {
    $where[] = "(
        t.tie_nombre LIKE :q
        OR tr.rev_nombre LIKE :q
        OR tr.rev_comentario LIKE :q
        OR rr.rep_nombre LIKE :q
        OR rr.rep_email LIKE :q
        OR rr.rep_motivo LIKE :q
        OR rr.rep_detalle LIKE :q
        OR rr.rep_ip LIKE :q
        OR rr.rep_session_id LIKE :q
    )";
    $params[':q'] = '%' . $q . '%';
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

render_head('Gestión de reseñas reportadas');
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
      <div class="row g-4 align-items-center">
        <div class="col-lg-8 position-relative z-1">
          <div class="admin-kicker mb-2">Moderación</div>
          <h1 class="display-6 fw-bold mb-3">Gestión de reseñas reportadas</h1>
          <p class="text-body-secondary mb-0">
            Administrá las reseñas reportadas por usuarios, revisá su contenido y decidí si deben mantenerse visibles dentro del sitio.
          </p>
        </div>
        <div class="col-lg-4 position-relative z-1 text-lg-end">
          <span class="badge bg-dark-subtle text-dark fs-6 px-3 py-2 rounded-pill">
            <?= number_format((int) ($stats['total_reportes'] ?? 0), 0, ',', '.') ?> reportes
          </span>
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Reseñas reportadas</div>
          <div class="admin-stat-value"><?= number_format((int) ($stats['total_reviews_reportadas'] ?? 0), 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Por revisar</div>
          <div class="admin-stat-value"><?= number_format((int) ($stats['pendientes'] ?? 0), 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Revisados</div>
          <div class="admin-stat-value"><?= number_format((int) ($stats['revisados'] ?? 0), 0, ',', '.') ?></div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="admin-panel admin-stat p-4 h-100">
          <div class="admin-stat-label">Descartados</div>
          <div class="admin-stat-value"><?= number_format((int) ($stats['descartados'] ?? 0), 0, ',', '.') ?></div>
        </div>
      </div>
    </div>

    <div class="admin-panel p-4 mb-4">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-lg-4">
          <label class="form-label">Buscar</label>
          <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Tienda, reseña, usuario o detalle...">
        </div>
        <div class="col-md-3 col-lg-2">
          <label class="form-label">Estado</label>
          <select name="estado" class="form-select">
            <option value="pendiente" <?= $status === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
            <option value="revisado" <?= $status === 'revisado' ? 'selected' : '' ?>>Revisado</option>
            <option value="descartado" <?= $status === 'descartado' ? 'selected' : '' ?>>Descartado</option>
            <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
          </select>
        </div>
        <div class="col-md-3 col-lg-2">
          <label class="form-label">Mínimo de reportes</label>
          <input type="number" min="1" name="min_reportes" class="form-control" value="<?= (int) $minReports ?>">
        </div>
        <div class="col-md-3 col-lg-2">
          <label class="form-label">Ordenar</label>
          <select name="orden" class="form-select">
            <option value="mas_reportadas" <?= $order === 'mas_reportadas' ? 'selected' : '' ?>>Más reportadas</option>
            <option value="mas_recientes" <?= $order === 'mas_recientes' ? 'selected' : '' ?>>Más recientes</option>
            <option value="menos_reportadas" <?= $order === 'menos_reportadas' ? 'selected' : '' ?>>Menos reportadas</option>
            <option value="tienda_asc" <?= $order === 'tienda_asc' ? 'selected' : '' ?>>Tienda A-Z</option>
            <option value="estado" <?= $order === 'estado' ? 'selected' : '' ?>>Estado</option>
          </select>
        </div>
        <div class="col-md-3 col-lg-2">
          <button type="submit" class="btn btn-primary w-100">Filtrar</button>
        </div>
      </form>
    </div>

    <div class="d-grid gap-4">
      <?php if ($reviewGroups): ?>
        <?php foreach ($reviewGroups as $item): ?>
          <?php
            $reviewId = (int) $item['idreview'];
            $detailId = 'reviewReports' . $reviewId;
            $reviewStatusText = (int) $item['rev_activo'] === 1 ? 'Visible' : 'Oculta';
            $reviewStatusBadge = (int) $item['rev_activo'] === 1 ? 'bg-success' : 'bg-secondary';
            $reportList = $reportsByReview[$reviewId] ?? [];
          ?>
          <div class="admin-panel p-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
              <div>
                <div class="small text-body-secondary mb-1">
                  Tienda: <strong><?= h($item['tie_nombre']) ?></strong>
                </div>
                <h2 class="h4 fw-bold mb-1">Reseña #<?= $reviewId ?></h2>
                <div class="small text-body-secondary">
                  Publicada: <?= h(date('d/m/Y H:i', strtotime((string) $item['rev_fecha']))) ?>
                </div>
              </div>

              <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="badge <?= $reviewStatusBadge ?>"><?= $reviewStatusText ?></span>
                <?php if ((int) $item['pendientes_count'] > 0): ?>
                  <span class="badge bg-warning text-dark"><?= (int) $item['pendientes_count'] ?> pendiente(s)</span>
                <?php endif; ?>
                <?php if ((int) $item['revisados_count'] > 0): ?>
                  <span class="badge bg-success"><?= (int) $item['revisados_count'] ?> revisado(s)</span>
                <?php endif; ?>
                <?php if ((int) $item['descartados_count'] > 0): ?>
                  <span class="badge bg-secondary"><?= (int) $item['descartados_count'] ?> descartado(s)</span>
                <?php endif; ?>
                <span class="badge bg-danger"><?= (int) $item['cantidad_reportes'] ?> reporte(s)</span>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-lg-6">
                <div class="border rounded-4 p-3 h-100">
                  <div class="small text-body-secondary mb-2">Contenido de la reseña</div>
                  <div class="fw-semibold mb-1"><?= h($item['rev_nombre']) ?></div>
                  <div class="text-warning small mb-2">
                    <?= str_repeat('★', (int) $item['rev_puntaje']) ?><?= str_repeat('☆', max(0, 5 - (int) $item['rev_puntaje'])) ?>
                  </div>
                  <div style="white-space: pre-line; line-height: 1.6;"><?= h($item['rev_comentario']) ?></div>
                </div>
              </div>

              <div class="col-lg-6">
                <div class="border rounded-4 p-3 h-100">
                  <div class="small text-body-secondary mb-2">Resumen de reportes</div>
                  <div class="mb-2"><strong>Total:</strong> <?= (int) $item['cantidad_reportes'] ?></div>
                  <div class="mb-2"><strong>Último reporte:</strong> <?= h(date('d/m/Y H:i', strtotime((string) $item['ultima_fecha_reporte']))) ?></div>
                  <div class="mb-2"><strong>Por revisar:</strong> <?= (int) $item['pendientes_count'] ?></div>
                  <div class="mb-2"><strong>Revisados:</strong> <?= (int) $item['revisados_count'] ?></div>
                  <div class="mb-0"><strong>Descartados:</strong> <?= (int) $item['descartados_count'] ?></div>
                </div>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
              <form method="post" class="d-inline">
                <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                <input type="hidden" name="action" value="mark_all_reviewed">
                <button type="submit" class="btn btn-outline-success rounded-pill">Marcar todo revisado</button>
              </form>

              <form method="post" class="d-inline">
                <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                <input type="hidden" name="action" value="mark_all_discarded">
                <button type="submit" class="btn btn-outline-secondary rounded-pill">Descartar todos</button>
              </form>

              <?php if ((int) $item['rev_activo'] === 1): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('¿Ocultar esta review?');">
                  <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                  <input type="hidden" name="action" value="hide_review">
                  <button type="submit" class="btn btn-danger rounded-pill">Ocultar reseña</button>
                </form>
              <?php else: ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                  <input type="hidden" name="action" value="restore_review">
                  <button type="submit" class="btn btn-outline-primary rounded-pill">Restaurar reseña</button>
                </form>
              <?php endif; ?>

              <button class="btn btn-outline-dark rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($detailId) ?>" aria-expanded="false" aria-controls="<?= h($detailId) ?>">
                Ver detalle de reportes
              </button>
            </div>

            <div class="collapse mt-4" id="<?= h($detailId) ?>">
              <div class="d-grid gap-3">
                <?php if ($reportList): ?>
                  <?php foreach ($reportList as $report): ?>
                    <div class="border rounded-4 p-3">
                      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-2">
                        <div>
                          <div class="fw-semibold">Reporte #<?= (int) $report['idreporte'] ?></div>
                          <div class="small text-body-secondary"><?= h(date('d/m/Y H:i', strtotime((string) $report['rep_fecha']))) ?></div>
                        </div>
                        <span class="badge <?= status_badge_class((string) $report['rep_estado']) ?>">
                          <?= h(ucfirst((string) $report['rep_estado'])) ?>
                        </span>
                      </div>

                      <div class="row g-3">
                        <div class="col-lg-6">
                          <div><strong>Reportante:</strong> <?= h($report['rep_nombre'] ?: 'Anónimo') ?></div>
                          <div><strong>Email:</strong> <?= h($report['rep_email'] ?: 'No indicado') ?></div>
                          <div><strong>Motivo:</strong> <?= h(report_reason_label((string) $report['rep_motivo'])) ?></div>
                        </div>
                        <div class="col-lg-6">
                          <div><strong>IP:</strong> <?= h($report['rep_ip'] ?: 'No disponible') ?></div>
                          <div><strong>Session ID:</strong> <span class="text-break"><?= h($report['rep_session_id'] ?: 'No disponible') ?></span></div>
                        </div>
                        <div class="col-12">
                          <strong>Detalle:</strong><br>
                          <div style="white-space: pre-line; line-height: 1.6;"><?= nl2br(h($report['rep_detalle'] ?: 'Sin detalle adicional')) ?></div>
                        </div>
                      </div>

                      <div class="d-flex flex-wrap gap-2 mt-3">
                        <form method="post" class="d-inline">
                          <input type="hidden" name="report_id" value="<?= (int) $report['idreporte'] ?>">
                          <input type="hidden" name="action" value="mark_reviewed">
                          <button type="submit" class="btn btn-sm btn-outline-success rounded-pill">Marcar revisado</button>
                        </form>

                        <form method="post" class="d-inline">
                          <input type="hidden" name="report_id" value="<?= (int) $report['idreporte'] ?>">
                          <input type="hidden" name="action" value="mark_discarded">
                          <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill">Descartar</button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="admin-empty">No hay más detalles disponibles para esta reseña.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="admin-panel p-4">
          <div class="admin-empty">No se encontraron reseñas reportadas con esos filtros.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php render_footer(); ?>
