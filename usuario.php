<?php
require_once __DIR__ . '/config.php';

// Forzar que solo usuarios autenticados ingresen
require_login();

$pdo = db();
$userId = current_user_id();

$success = '';
$error = '';

// =========================================================================
// ACCIÓN: ELIMINAR CUENTA (PROCESAMIENTO POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    try {
        // Borrar el registro del usuario de la base de datos
        $stmtDelete = $pdo->prepare('DELETE FROM usuario WHERE idusuario = :id');
        $stmtDelete->execute([':id' => $userId]);

        // Destruir la sesión completamente
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        // Redirigir a la página de login con bandera de éxito
        header('Location: login.php?account_deleted=1');
        exit;
    } catch (PDOException $e) {
        $error = 'No se pudo eliminar la cuenta. Es posible que existan dependencias en el sistema.';
    }
}

// =========================================================================
// ACCIÓN: ACTUALIZAR DATOS DE PERFIL
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $nombre = trim($_POST['usu_nombre'] ?? '');
    $email = trim($_POST['usu_email'] ?? '');

    if ($nombre === '' || $email === '') {
        $error = 'Por favor, completa todos los campos requeridos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, ingresa un correo electrónico válido.';
    } else {
        // Verificar si el correo electrónico ya está registrado por otro ID de usuario
        $stmtEmail = $pdo->prepare('SELECT COUNT(*) FROM usuario WHERE usu_email = :email AND idusuario <> :id');
        $stmtEmail->execute([':email' => $email, ':id' => $userId]);
        
        if ($stmtEmail->fetchColumn() > 0) {
            $error = 'Este correo electrónico ya está registrado por otra cuenta.';
        } else {
            // Ejecutar la actualización en la base de datos
            $update = $pdo->prepare('UPDATE usuario SET usu_nombre = :nombre, usu_email = :email WHERE idusuario = :id');
            $update->execute([
                ':nombre' => $nombre,
                ':email'  => $email,
                ':id'     => $userId
            ]);

            // Actualizar la sesión activa
            $_SESSION['user']['usu_nombre'] = $nombre;
            $_SESSION['user']['usu_email'] = $email;

            $success = 'Tus datos de perfil se actualizaron correctamente.';
        }
    }
}

// 1. Traer datos frescos del usuario desde la base de datos para la vista
$stmt = $pdo->prepare('SELECT idusuario, usu_nombre, usu_email FROM usuario WHERE idusuario = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$userDb = $stmt->fetch();

if (!$userDb) {
    header('Location: logout.php');
    exit;
}

render_head('Mi Cuenta');
render_navbar('usuario'); 
?>

<style>
  .glass-card {
    background: rgba(24, 28, 36, 0.65) !important;
    backdrop-filter: blur(16px) saturate(120%);
    -webkit-backdrop-filter: blur(16px) saturate(120%);
    border: 1px solid rgba(255, 255, 255, 0.07) !important;
    box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.6) !important;
  }
  .glass-modal-content {
    background: rgba(18, 22, 30, 0.8) !important;
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1px solid rgba(220, 53, 69, 0.2) !important;
    box-shadow: 0 16px 50px 0 rgba(0, 0, 0, 0.8) !important;
  }
  .input-group-glass-text {
    background: rgba(255, 255, 255, 0.03) !important;
    border-color: rgba(255, 255, 255, 0.08) !important;
  }
  .form-control-glass {
    background: rgba(255, 255, 255, 0.02) !important;
    border-color: rgba(255, 255, 255, 0.08) !important;
    color: #fff !important;
  }
  .form-control-glass:focus {
    background: rgba(255, 255, 255, 0.05) !important;
    border-color: var(--bs-primary) !important;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15) !important;
  }
</style>

<div class="container my-5 pt-4">
  <div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
      
      <?php if ($success !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
          <i class="bi bi-check-circle-fill me-2"></i><?= e($success) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="card rounded-4 border-0 shadow-soft animate-reveal-up" style="background: var(--bg-card); color: var(--text-main); transition: background 0.3s ease, color 0.3s ease;">
        <div class="card-body p-4 p-sm-5">
          
          <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle mb-3" style="width: 70px; height: 70px;">
              <i class="bi bi-person-gear display-6"></i>
            </div>
            <h4 class="mb-1">Configuración de la Cuenta</h4>
            <p class="text-body-secondary small">Administrá tu información personal de acceso</p>
          </div>

          <form method="post" action="usuario.php" novalidate>
            <div class="mb-3">
              <label class="form-label text-light small fw-medium">Nombre completo</label>
              <div class="input-group">
                <span class="input-group-text input-group-glass-text text-body-secondary"><i class="bi bi-person"></i></span>
                <input type="text" name="usu_nombre" class="form-control form-control-glass" value="<?= e($userDb['usu_nombre']) ?>" maxlength="45" required>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label text-light small fw-medium">Correo electrónico</label>
              <div class="input-group">
                <span class="input-group-text input-group-glass-text text-body-secondary"><i class="bi bi-envelope"></i></span>
                <input type="email" name="usu_email" class="form-control form-control-glass" value="<?= e($userDb['usu_email']) ?>" maxlength="120" required>
              </div>
            </div>

            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary rounded-pill py-2.5 fw-medium shadow-sm">
                <i class="bi bi-hdd me-2"></i>Guardar cambios
              </button>
              
              <div class="auth-divider my-2"><span>Opciones de seguridad</span></div>
              
              <a href="olvide_password.php?email=<?= urlencode($userDb['usu_email']) ?>" class="btn btn-outline-warning rounded-pill py-2.5 fw-medium">
                <i class="bi bi-shield-lock me-2"></i>Resetear contraseña
              </a>
            </div>
          </form>

          <div class="text-center mt-4 pt-3 border-top border-secondary border-opacity-10">
            <button type="button" class="btn btn-link btn-sm text-danger text-opacity-75 text-decoration-none fw-medium dynamic-danger-btn" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
              <i class="bi bi-trash3 me-1"></i>Eliminar mi cuenta permanentemente
            </button>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 border-0" style="background: var(--bg-card-strong); color: var(--text-main); box-shadow: var(--shadow-soft); transition: background 0.3s ease, color 0.3s ease;">
      
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-danger d-flex align-items-center" id="deleteAccountModalLabel">
          <i class="bi bi-exclamation-octagon-fill me-2 fs-4"></i> ¿Estás completamente seguro?
        </h5>
        <button type="button" class="btn-close" style="filter: var(--theme-close-filter, invert(1) grayscale(1) brightness(2)); transition: filter 0.3s ease;" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      
      <div class="modal-body py-3">
        <p class="mb-2">Estás a punto de eliminar de forma **definitiva** tu cuenta en Caacuprecio.</p>
        <p class="small mb-0" style="color: var(--text-soft); transition: color 0.3s ease;">Esta acción no se puede deshacer. Perderás tus configuraciones de inmediato y se cerrará tu sesión.</p>
      </div>
      
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-1.5 small" style="color: var(--text-main); border-color: var(--border-soft); transition: all 0.3s ease;" data-bs-dismiss="modal">Cancelar</button>
        <form method="post" action="usuario.php" class="d-inline">
          <input type="hidden" name="action" value="delete_account">
          <button type="submit" class="btn btn-danger rounded-pill px-4 py-1.5 fw-medium shadow-sm">
            Sí, eliminar cuenta
          </button>
        </form>
      </div>

    </div>
  </div>
</div>
      
    </div>
  </div>
</div>

<?php 
render_footer(); 
?>