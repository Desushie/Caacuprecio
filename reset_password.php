<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$pdo = db();
$msg = '';
$msgType = '';
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$validToken = false;
$userId = null;

if ($token) {
    $stmt = $pdo->prepare('SELECT idusuario FROM usuario WHERE usu_reset_token = :token AND usu_reset_expira > NOW() LIMIT 1');
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    if ($user) {
        $validToken = true;
        $userId = $user['idusuario'];
    } else {
        $msg = 'El enlace de recuperación es inválido o ha expirado. Por favor, solicita uno nuevo.';
        $msgType = 'danger';
    }
} else {
    $msg = 'No se proporcionó ningún token de recuperación.';
    $msgType = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) {
        $msg = 'La contraseña debe tener al menos 6 caracteres.';
        $msgType = 'danger';
    } elseif ($password !== $password_confirm) {
        $msg = 'Las contraseñas no coinciden.';
        $msgType = 'danger';
    } else {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE usuario SET usu_contra = :hash, usu_reset_token = NULL, usu_reset_expira = NULL WHERE idusuario = :id');
        $update->execute([
            ':hash' => $newHash,
            ':id' => $userId
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Tu contraseña ha sido actualizada con éxito. Ya puedes iniciar sesión.'];
        header('Location: login.php');
        exit;
    }
}

render_head('Restablecer contraseña');
render_navbar('login');
?>

<section class="auth-shell position-relative overflow-hidden" style="min-height: 80vh;">
  <div class="auth-orb orb-2"></div>
  <div class="auth-grid"></div>
  <div class="container py-5 auth-content d-flex justify-content-center align-items-center">
    
    <div class="detail-card p-4 p-lg-5 auth-card" style="max-width: 500px; width: 100%;">
      
      <div class="d-flex align-items-center gap-3 mb-4">
        <div class="icon-wrap"><i class="bi bi-shield-lock"></i></div>
        <div>
          <h2 class="h4 fw-bold mb-0">Nueva contraseña</h2>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="alert alert-<?= e($msgType) ?> rounded-4 mb-4"><?= $msg ?></div>
      <?php endif; ?>

      <?php if ($validToken): ?>
      <form method="post" action="reset_password.php" class="row g-3">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        
        <div class="col-12">
          <label for="password" class="form-label">Nueva Contraseña</label>
          <div class="input-group auth-input-group">
            <input type="password" class="form-control rounded-start-4" id="password" name="password" required minlength="6">
            <button class="btn btn-outline-primary rounded-end-4" type="button" data-toggle-password="#password">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <div class="col-12">
          <label for="password_confirm" class="form-label">Confirmar Nueva Contraseña</label>
          <div class="input-group auth-input-group">
            <input type="password" class="form-control rounded-start-4" id="password_confirm" name="password_confirm" required minlength="6">
            <button class="btn btn-outline-primary rounded-end-4" type="button" data-toggle-password="#password_confirm">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <div class="col-12 d-grid mt-4">
          <button class="btn btn-primary btn-lg rounded-4" type="submit">
            <i class="bi bi-check-circle me-2"></i>Guardar contraseña
          </button>
        </div>
      </form>
      <?php else: ?>
        <div class="text-center mt-4">
          <a href="olvide_password.php" class="btn btn-outline-primary rounded-pill px-4">Solicitar un nuevo enlace</a>
        </div>
      <?php endif; ?>

    </div>
  </div>
</section>

<script>
document.querySelectorAll('[data-toggle-password]').forEach(function(button) {
  button.addEventListener('click', function() {
    const target = document.querySelector(button.getAttribute('data-toggle-password'));
    if (!target) return;
    const isPassword = target.getAttribute('type') === 'password';
    target.setAttribute('type', isPassword ? 'text' : 'password');
    button.innerHTML = isPassword ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
  });
});
</script>

<?php render_footer(); ?>