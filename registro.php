<?php
session_start();
require_once __DIR__ . '/config.php';

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function auth_render_head(string $title): void
{
    if (function_exists('render_head')) {
        render_head($title);
        return;
    }
    ?>
    <!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= e($title) ?></title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <link rel="stylesheet" href="./css/styles.css">
      <link rel="stylesheet" href="./css/auth.css">
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    </head>
    <body>
    <?php
}

function auth_render_nav(string $active = 'registro'): void
{
    if (function_exists('render_navbar')) {
        render_navbar($active);
        return;
    }
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark navbar-shell sticky-top">
      <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="index.php">
          <span class="brand-badge">CP</span>
          <span>Caacuprecio</span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
          <a class="btn btn-outline-primary rounded-pill px-4" href="login.php">Ingresar</a>
        </div>
      </div>
    </nav>
    <?php
}

function auth_render_footer(): void
{
    if (function_exists('render_footer')) {
        render_footer();
        return;
    }
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}

function auth_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function auth_pull_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

if (!empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$pdo = db();
$errors = [];
$nombre = trim($_POST['nombre'] ?? '');
$email = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');

    if ($nombre === '') {
        $errors[] = 'Ingresá tu nombre.';
    } elseif (mb_strlen($nombre) > 45) {
        $errors[] = 'El nombre no puede superar 45 caracteres.';
    }

    if ($email === '') {
        $errors[] = 'Ingresá tu correo.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El correo no tiene un formato válido.';
    }

    if ($password === '') {
        $errors[] = 'Ingresá una contraseña.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    }

    if ($password !== $password2) {
        $errors[] = 'Las contraseñas no coinciden.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT idusuario FROM usuario WHERE usu_email = :email LIMIT 1');
        $check->execute([':email' => $email]);

        if ($check->fetch()) {
            $errors[] = 'Ya existe una cuenta con ese correo.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO usuario (usu_nombre, usu_email, usu_contra, usu_tipo) VALUES (:nombre, :email, :contra, :tipo)');
            $stmt->execute([
                ':nombre' => $nombre,
                ':email' => $email,
                ':contra' => $hash,
                ':tipo' => 0,
            ]);

            auth_flash('success', 'Tu cuenta fue creada correctamente. Ya podés iniciar sesión.');
            header('Location: login.php');
            exit;
        }
    }
}

$flash = auth_pull_flash();
auth_render_head('Crear cuenta');
auth_render_nav('registro');
?>
<section class="auth-shell position-relative overflow-hidden">
  <div class="auth-orb orb-1"></div>
  <div class="auth-orb orb-2"></div>
  <div class="auth-grid"></div>
  <div class="container py-5 auth-content">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6 order-lg-2">
        <div class="hero-visual p-4 p-lg-5 h-100 auth-showcase">
          <span class="badge rounded-pill custom-badge px-3 py-2 mb-3">
            <i class="bi bi-person-plus me-1"></i> Nueva cuenta
          </span>
          <h1 class="display-6 fw-bold mb-3">Creá tu cuenta</h1>
          <p class="text-white-50 mb-4">
            Registrate para guardar productos, comparar opciones y preparar listas personalizadas cuando necesites cotizar.
          </p>
          <div class="d-grid gap-3">
            <div class="mini-stat">
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-white-50">Favoritos</span>
                <strong>Guardado rápido</strong>
              </div>
            </div>
            <div class="mini-stat">
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-white-50">Comparación</span>
                <strong>Más ordenada</strong>
              </div>
            </div>
            <div class="mini-stat">
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-white-50">Seguridad</span>
                <strong>Contraseña cifrada</strong>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6 order-lg-1">
        <div class="detail-card p-4 p-lg-5 auth-card">
          <div class="d-flex align-items-center gap-3 mb-4">
            <div class="icon-wrap"><i class="bi bi-person-badge"></i></div>
            <div>
              <div class="small text-body-secondary">Registro</div>
              <h2 class="h3 fw-bold mb-0">Crear cuenta</h2>
            </div>
          </div>

          <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> rounded-4 mb-4"><?= e($flash['message']) ?></div>
          <?php endif; ?>

          <?php if ($errors): ?>
            <div class="alert alert-danger rounded-4 mb-4">
              <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                  <li><?= e($error) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form method="post" action="registro.php" class="row g-3">
            <div class="col-12">
              <label for="nombre" class="form-label">Nombre</label>
              <input type="text" class="form-control rounded-4" id="nombre" name="nombre" maxlength="45" value="<?= e($nombre) ?>" autocomplete="name" required>
            </div>
            <div class="col-12">
              <label for="email" class="form-label">Correo electrónico</label>
              <input type="email" class="form-control rounded-4" id="email" name="email" maxlength="120" value="<?= e($email) ?>" autocomplete="email" required>
            </div>
            <div class="col-md-6">
              <label for="password" class="form-label">Contraseña</label>
              <div class="input-group auth-input-group">
                <input type="password" class="form-control rounded-start-4" id="password" name="password" autocomplete="new-password" required>
                <button class="btn btn-outline-primary rounded-end-4" type="button" data-toggle-password="#password">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            <div class="col-md-6">
              <label for="password_confirm" class="form-label">Confirmar contraseña</label>
              <div class="input-group auth-input-group">
                <input type="password" class="form-control rounded-start-4" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
                <button class="btn btn-outline-primary rounded-end-4" type="button" data-toggle-password="#password_confirm">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            <div class="col-12">
              <div class="info-box small text-body-secondary">
                Usá una contraseña de al menos 8 caracteres para proteger mejor tu cuenta.
              </div>
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-primary btn-lg rounded-4" type="submit">
                <i class="bi bi-check2-circle me-2"></i>Crear cuenta
              </button>
            </div>
          </form>

          <div class="auth-divider my-4"><span>o</span></div>

          <div class="d-flex flex-column flex-sm-row gap-3 justify-content-between align-items-sm-center">
            <div class="text-body-secondary">¿Ya tenés cuenta?</div>
            <a href="login.php" class="btn btn-outline-primary rounded-pill px-4">Ir al login</a>
          </div>
        </div>
      </div>
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
<?php auth_render_footer(); ?>
