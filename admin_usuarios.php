<?php
require_once __DIR__ . '/config.php';
require_admin(); // Solo administradores globales pueden gestionar usuarios

$pdo = db();
$error = '';
$success = '';

// 1. PROCESAR ACCIONES (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ACCIÓN: GUARDAR / EDITAR USUARIO
    if ($action === 'save_user') {
        $id = (int)($_POST['idusuario'] ?? 0);
        $nombre = trim((string)($_POST['usu_nombre'] ?? ''));
        $email = trim((string)($_POST['usu_email'] ?? ''));
        $tipo = (int)($_POST['usu_tipo'] ?? 0);
        $tienda_id = ($tipo === 2 && ($_POST['tiendas_idtiendas'] ?? '') !== '') ? (int)$_POST['tiendas_idtiendas'] : null;

        if ($nombre === '' || $email === '') {
            $error = 'El nombre y el correo electrónico son obligatorios.';
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE usuario 
                        SET usu_nombre = :nombre, 
                            usu_email = :email, 
                            usu_tipo = :tipo, 
                            tiendas_idtiendas = :tienda 
                        WHERE idusuario = :id
                    ");
                    $stmt->execute([
                        'nombre' => $nombre,
                        'email' => $email,
                        'tipo' => $tipo,
                        'tienda' => $tienda_id,
                        'id' => $id
                    ]);
                    $success = 'Usuario actualizado correctamente.';
                }
            } catch (PDOException $e) {
                $error = 'Error al guardar en la base de datos: ' . $e->getMessage();
            }
        }
    }

    // ACCIÓN: ELIMINAR USUARIO
    if ($action === 'delete_user') {
        $id = (int)($_POST['idusuario'] ?? 0);
        $currentUser = current_user();

        if (isset($currentUser['idusuario']) && (int)$currentUser['idusuario'] === $id) {
            $error = 'No puedes eliminar tu propia cuenta de administrador en sesión.';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM usuario WHERE idusuario = :id");
                $stmt->execute(['id' => $id]);
                $success = 'Usuario eliminado de forma permanente.';
            } catch (PDOException $e) {
                $error = 'No se pudo eliminar al usuario (puede tener datos vinculados): ' . $e->getMessage();
            }
        }
    }
}

// 2. CONSULTAS DE DATOS (GET)
$usuarios = $pdo->query("
    SELECT u.*, t.tie_nombre 
    FROM usuario u 
    LEFT JOIN tiendas t ON u.tiendas_idtiendas = t.idtiendas 
    ORDER BY u.idusuario DESC
")->fetchAll();

$tiendas = $pdo->query("SELECT idtiendas, tie_nombre FROM tiendas ORDER BY tie_nombre ASC")->fetchAll();

render_head('Gestión de Usuarios');
?>
<link rel="stylesheet" href="./css/admin.css">
<?php render_navbar('admin'); ?>

<section class="admin-shell">
  <div class="container py-4">
    
    <?php if ($error !== ''): ?>
      <div class="alert alert-danger alert-dismissible fade show rounded-pill px-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= e($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <div class="alert alert-success alert-dismissible fade show rounded-pill px-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?= e($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h2 class="h3 mb-1">Gestión de Usuarios</h2>
        <p class="text-body-secondary small mb-0">Modifica roles, asigna empresas o remueve accesos a la plataforma.</p>
      </div>
    </div>

    <div class="card admin-hero p-0 border-0 shadow-sm overflow-hidden mb-4">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 text-start" style="color: var(--text-main);">
          <thead class="table-dark" style="background-color: var(--bg-elevated);">
            <tr>
              <th class="ps-4">ID</th>
              <th>Nombre</th>
              <th>Correo Electrónico</th>
              <th>Rol / Perfil</th>
              <th>Tienda Vinculada</th>
              <th class="pe-4 text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($usuarios)): ?>
              <?php foreach ($usuarios as $user): ?>
                <tr>
                  <td class="ps-4 fw-bold text-body-secondary"><?= (int)$user['idusuario'] ?></td>
                  <td>
                    <div class="fw-semibold"><?= e($user['usu_nombre']) ?></div>
                  </td>
                  <td><?= e($user['usu_email']) ?></td>
                  <td>
                    <?php if ((int)$user['usu_tipo'] === 1): ?>
                      <span class="badge bg-danger rounded-pill px-3">Administrador</span>
                    <?php elseif ((int)$user['usu_tipo'] === 2): ?>
                      <span class="badge bg-primary rounded-pill px-3">Empresa</span>
                    <?php else: ?>
                      <span class="badge bg-secondary rounded-pill px-3">Usuario</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ((int)$user['usu_tipo'] === 2 && !empty($user['tie_nombre'])): ?>
                      <span class="text-info"><i class="bi bi-shop me-1"></i> <?= e($user['tie_nombre']) ?></span>
                    <?php elseif ((int)$user['usu_tipo'] === 2): ?>
                      <span class="text-warning small"><i class="bi bi-exclamation-circle me-1"></i> Sin tienda asignada</span>
                    <?php else: ?>
                      <span class="text-muted small">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td class="pe-4 text-end">
                    <div class="d-inline-flex gap-2">
                      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editUserModal<?= (int)$user['idusuario'] ?>">
                        <i class="bi bi-pencil-fill me-1"></i> Editar
                      </button>

                      <form action="admin_usuarios.php" method="POST" onsubmit="return confirm('¿Estás completamente seguro de que deseas eliminar a <?= e($user['usu_nombre']) ?>? Esta acción no se puede deshacer.');">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="idusuario" value="<?= (int)$user['idusuario'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                          <i class="bi bi-trash3-fill"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="text-center py-5 text-muted">No se encontraron usuarios registrados en la plataforma.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</section> <?php if (!empty($usuarios)): ?>
  <?php foreach ($usuarios as $user): ?>
    <div class="modal fade admin-modal" id="editUserModal<?= (int)$user['idusuario'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-start" style="background: var(--bg-card-strong); border: 1px solid var(--border-soft);">
          <div class="modal-header border-bottom border-light-subtle">
            <h5 class="modal-title"><i class="bi bi-person-gear me-2 text-primary"></i>Editar Usuario #<?= (int)$user['idusuario'] ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="admin_usuarios.php" method="POST">
            <div class="modal-body py-4">
              <input type="hidden" name="action" value="save_user">
              <input type="hidden" name="idusuario" value="<?= (int)$user['idusuario'] ?>">

              <div class="mb-3">
                <label class="form-label small text-body-secondary fw-semibold">Nombre del Usuario</label>
                <input type="text" name="usu_nombre" class="form-control" value="<?= e($user['usu_nombre']) ?>" required>
              </div>

              <div class="mb-3">
                <label class="form-label small text-body-secondary fw-semibold">Correo Electrónico</label>
                <input type="email" name="usu_email" class="form-control" value="<?= e($user['usu_email']) ?>" required>
              </div>

              <div class="mb-3">
                <label class="form-label small text-body-secondary fw-semibold">Rol del Sistema</label>
                <select name="usu_tipo" class="form-select js-role-select" data-user-id="<?= (int)$user['idusuario'] ?>" required>
                  <option value="0" <?= (int)$user['usu_tipo'] === 0 ? 'selected' : '' ?>>Usuario Regular</option>
                  <option value="2" <?= (int)$user['usu_tipo'] === 2 ? 'selected' : '' ?>>Empresa / Encargado</option>
                  <option value="1" <?= (int)$user['usu_tipo'] === 1 ? 'selected' : '' ?>>Administrador Global</option>
                </select>
              </div>

              <div class="mb-2 js-store-wrapper-<?= (int)$user['idusuario'] ?>" style="<?= (int)$user['usu_tipo'] === 2 ? '' : 'display: none;' ?>">
                <label class="form-label small text-body-secondary fw-semibold text-info"><i class="bi bi-shop me-1"></i>Asignar a Tienda/Empresa</label>
                <select name="tiendas_idtiendas" class="form-select border-info-subtle">
                  <option value="">-- Seleccionar Tienda --</option>
                  <?php foreach ($tiendas as $tienda): ?>
                    <option value="<?= (int)$tienda['idtiendas'] ?>" <?= (int)$user['tiendas_idtiendas'] === (int)$tienda['idtiendas'] ? 'selected' : '' ?>>
                      <?= e($tienda['tie_nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="small text-muted mt-1">El usuario solo podrá gestionar los productos y ver estadísticas de la tienda seleccionada.</div>
              </div>

            </div>
            <div class="modal-footer border-top border-light-subtle">
              <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary rounded-pill px-4">Guardar Cambios</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>


<script>
document.querySelectorAll('.js-role-select').forEach(function(selectEl) {
  selectEl.addEventListener('change', function() {
    const userId = this.getAttribute('data-user-id');
    const storeWrapper = document.querySelector('.js-store-wrapper-' + userId);
    if (!storeWrapper) return;

    if (this.value === "2") {
      storeWrapper.style.display = "block";
    } else {
      storeWrapper.style.display = "none";
    }
  });
});
</script>

<?php 
render_footer(); 
?>