<?php
require_once __DIR__ . '/config.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_store') {
    $id = (int) ($_POST['idtiendas'] ?? 0);
    $stmt = $pdo->prepare("
        UPDATE tiendas
        SET
            tie_nombre = :nombre,
            tie_descripcion = :descripcion,
            tie_logo = :logo,
            tie_ubicacion = :ubicacion,
            tie_url = :url,
            tie_contacto = :contacto,
            tie_telefono = :telefono,
            tie_email = :email,
            tie_horarios = :horarios
        WHERE idtiendas = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':nombre' => trim((string) ($_POST['tie_nombre'] ?? '')),
        ':descripcion' => trim((string) ($_POST['tie_descripcion'] ?? '')),
        ':logo' => trim((string) ($_POST['tie_logo'] ?? '')),
        ':ubicacion' => trim((string) ($_POST['tie_ubicacion'] ?? '')),
        ':url' => trim((string) ($_POST['tie_url'] ?? '')),
        ':contacto' => trim((string) ($_POST['tie_contacto'] ?? '')),
        ':telefono' => trim((string) ($_POST['tie_telefono'] ?? '')),
        ':email' => trim((string) ($_POST['tie_email'] ?? '')),
        ':horarios' => trim((string) ($_POST['tie_horarios'] ?? '')),
    ]);
    header('Location: admin_tiendas.php?saved=1');
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(
        t.tie_nombre LIKE :q OR
        t.tie_descripcion LIKE :q OR
        t.tie_ubicacion LIKE :q OR
        t.tie_contacto LIKE :q OR
        t.tie_telefono LIKE :q OR
        t.tie_email LIKE :q
    )';
    $params[':q'] = '%' . $q . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT
        t.*,
        COUNT(p.idproductos) AS total_productos,
        MIN(p.pro_precio) AS precio_minimo,
        MAX(p.pro_fecha_scraping) AS ultima_actualizacion,
        (
            SELECT COUNT(*)
            FROM tienda_reviews tr
            WHERE tr.tiendas_idtiendas = t.idtiendas AND tr.rev_activo = 1
        ) AS total_reviews,
        (
            SELECT ROUND(AVG(tr.rev_puntaje), 1)
            FROM tienda_reviews tr
            WHERE tr.tiendas_idtiendas = t.idtiendas AND tr.rev_activo = 1
        ) AS rating_promedio
    FROM tiendas t
    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas
    {$whereSql}
    GROUP BY
        t.idtiendas,
        t.tie_nombre,
        t.tie_descripcion,
        t.tie_logo,
        t.tie_ubicacion,
        t.tie_url,
        t.tie_contacto,
        t.tie_telefono,
        t.tie_email,
        t.tie_horarios
    ORDER BY t.tie_nombre ASC
");
$stmt->execute($params);
$stores = $stmt->fetchAll();

render_head('Administrar tiendas');
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
          <div class="admin-kicker mb-2">Tiendas</div>
          <h1 class="display-6 fw-bold mb-3">Gestión visual de tiendas</h1>
          <p class="text-body-secondary mb-0">
            Editá la información visible, el contacto, los horarios y los datos de cada tienda desde esta sección.
          </p>
        </div>
        <div class="col-lg-4 position-relative z-1 text-lg-end">
          <span class="admin-badge admin-badge-soft"><?= number_format(count($stores), 0, ',', '.') ?> tienda(s)</span>
        </div>
      </div>
    </div>

    <div class="admin-panel p-4 mb-4 admin-filter-bar">
      <form class="row g-3" method="get">
        <div class="col-lg-11">
          <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, descripción, ubicación o contacto" value="<?= e($q) ?>">
        </div>
        <div class="col-lg-1 d-grid">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>
    </div>

    <div class="row g-4">
      <?php if ($stores): ?>
        <?php foreach ($stores as $store): ?>
          <div class="col-md-6 col-xl-4">
            <div class="admin-card p-4 h-100">
              <div class="admin-store-card-head mb-3">
                <img src="<?= e($store['tie_logo'] ?: image_url('', $store['tie_nombre'])) ?>" alt="<?= e($store['tie_nombre']) ?>" class="thumb admin-store-card-thumb">
                <div class="admin-store-card-body">
                  <div class="fw-bold admin-store-card-title"><?= e($store['tie_nombre']) ?></div>
                  <div class="small text-body-secondary admin-store-card-location"><?= e($store['tie_ubicacion'] ?: 'Sin ubicación') ?></div>
                </div>
              </div>

              <p class="text-body-secondary small mb-3 admin-store-card-description"><?= e($store['tie_descripcion'] ?: 'Sin descripción.') ?></p>

              <div class="d-flex justify-content-between small text-body-secondary mb-2">
                <span>Productos</span>
                <strong><?= number_format((int) $store['total_productos'], 0, ',', '.') ?></strong>
              </div>
              <div class="d-flex justify-content-between small text-body-secondary mb-3">
                <span>Reseñas</span>
                <strong><?= number_format((int) ($store['total_reviews'] ?? 0), 0, ',', '.') ?> · <?= number_format((float) ($store['rating_promedio'] ?? 0), 1, ',', '.') ?>★</strong>
              </div>

              <button
                type="button"
                class="btn btn-outline-primary rounded-pill w-100"
                data-bs-toggle="modal"
                data-bs-target="#storeModal<?= (int) $store['idtiendas'] ?>"
              >
                <i class="bi bi-pencil-square me-1"></i>Editar tienda
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="col-12">
          <div class="admin-panel admin-empty">No se encontraron tiendas.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($stores): ?>
  <?php foreach ($stores as $store): ?>
    <div class="modal fade admin-modal" id="storeModal<?= (int) $store['idtiendas'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
          <form method="post" class="h-100 d-flex flex-column">
            <input type="hidden" name="action" value="save_store">
            <input type="hidden" name="idtiendas" value="<?= (int) $store['idtiendas'] ?>">

            <div class="modal-header">
              <div class="pe-3">
                <div class="admin-kicker mb-1">Editar tienda</div>
                <h5 class="modal-title mb-0"><?= e($store['tie_nombre']) ?></h5>
              </div>
              <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body flex-grow-1" style="overflow-y:auto;">
              <div class="row g-4">
                <div class="col-12 col-lg-7">
                  <div class="row g-3">

                    <div class="col-12">
                      <label class="form-label">Nombre</label>
                      <input type="text" name="tie_nombre" class="form-control" value="<?= e($store['tie_nombre']) ?>" required>
                    </div>

                    <div class="col-12">
                      <label class="form-label">Descripción</label>
                      <textarea name="tie_descripcion" class="form-control" rows="4"><?= e($store['tie_descripcion']) ?></textarea>
                    </div>

                    <div class="col-12 col-md-6">
                      <label class="form-label">Logo (URL)</label>
                      <input type="text" name="tie_logo" class="form-control js-image-input" value="<?= e($store['tie_logo']) ?>">
                    </div>

                    <div class="col-12 col-md-6">
                      <label class="form-label">Ubicación</label>
                      <input type="text" name="tie_ubicacion" class="form-control" value="<?= e($store['tie_ubicacion']) ?>">
                    </div>

                    <div class="col-12">
                      <label class="form-label">Sitio web</label>
                      <input type="text" name="tie_url" class="form-control" value="<?= e($store['tie_url']) ?>">
                    </div>

                    <div class="col-12 col-md-6">
                      <label class="form-label">Nombre de contacto</label>
                      <input type="text" name="tie_contacto" class="form-control" value="<?= e($store['tie_contacto'] ?? '') ?>">
                    </div>

                    <div class="col-12 col-md-6">
                      <label class="form-label">Teléfono</label>
                      <input type="text" name="tie_telefono" class="form-control" value="<?= e($store['tie_telefono'] ?? '') ?>">
                    </div>

                    <div class="col-12">
                      <label class="form-label">Correo electrónico</label>
                      <input type="email" name="tie_email" class="form-control" value="<?= e($store['tie_email'] ?? '') ?>">
                    </div>

                    <div class="col-12">
                      <label class="form-label">Horarios</label>
                      <textarea name="tie_horarios" class="form-control" rows="4"><?= e($store['tie_horarios'] ?? '') ?></textarea>
                    </div>

                  </div>
                </div>

                <div class="col-12 col-lg-5">
                  <div class="preview-box mb-3">
                    <img
                      src="<?= e($store['tie_logo'] ?: image_url('', $store['tie_nombre'])) ?>"
                      alt="<?= e($store['tie_nombre']) ?>"
                      class="js-image-preview"
                    >
                  </div>

                  <div class="small text-body-secondary d-grid gap-2">
                    <div><strong>Reseñas:</strong> <?= number_format((int) ($store['total_reviews'] ?? 0), 0, ',', '.') ?></div>
                    <div><strong>Promedio:</strong> <?= number_format((float) ($store['rating_promedio'] ?? 0), 1, ',', '.') ?> / 5</div>
                    <div><strong>Productos:</strong> <?= number_format((int) $store['total_productos'], 0, ',', '.') ?></div>
                  </div>
                </div>

              </div>
            </div>

            <div class="modal-footer" style="position:sticky;bottom:0;background:#fff;">
              <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary rounded-pill px-4">Guardar cambios</button>
            </div>

          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.admin-modal').forEach(function(modal) {
  const input = modal.querySelector('.js-image-input');
  const preview = modal.querySelector('.js-image-preview');
  if (!input || !preview) return;

  input.addEventListener('input', function() {
    const value = input.value.trim();
    if (value) {
      preview.src = value;
    }
  });
});
</script>

<?php render_footer(); ?>
