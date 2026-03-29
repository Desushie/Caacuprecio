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

function auth_render_nav(string $active = 'login'): void
{
    if (function_exists('render_navbar')) {
        render_navbar($active);
        return;
    }

    $isLogged = !empty($_SESSION['user']);
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark navbar-shell sticky-top">
      <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="index.php">
          <span class="brand-badge">CP</span>
          <span>Caacuprecio</span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
          <?php if ($isLogged): ?>
            <a class="btn btn-outline-primary rounded-pill px-4" href="logout.php">Salir</a>
          <?php else: ?>
            <a class="btn btn-outline-primary rounded-pill px-4" href="registro.php">Crear cuenta</a>
          <?php endif; ?>
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

function auth_find_user_by_email(PDO $pdo, string $email)
{
    $stmt = $pdo->prepare('SELECT idusuario, usu_nombre, usu_email, usu_contra, usu_tipo FROM usuario WHERE usu_email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function auth_store_user_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'idusuario' => (int) $user['idusuario'],
        'usu_nombre' => $user['usu_nombre'],
        'usu_email' => $user['usu_email'],
        'usu_tipo' => (int) $user['usu_tipo'],
    ];
}

if (!empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$pdo = db();
$errors = [];
$email = trim($_POST['email'] ?? '');
$redirectTo = trim($_GET['next'] ?? ($_GET['redirect'] ?? ($_POST['redirect'] ?? 'index.php')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '') {
        $errors[] = 'Ingresá tu correo.';
    }

    if ($password === '') {
        $errors[] = 'Ingresá tu contraseña.';
    }

    if (!$errors) {
        $user = auth_find_user_by_email($pdo, $email);

        if (!$user) {
            $errors[] = 'No encontramos una cuenta con ese correo.';
        } else {
            $storedHash = (string) $user['usu_contra'];
            $valid = password_verify($password, $storedHash);
            $legacyPlaintext = !$valid && hash_equals($storedHash, $password);

            if (!$valid && !$legacyPlaintext) {
                $errors[] = 'La contraseña no es correcta.';
            } else {
                if ($legacyPlaintext || password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = $pdo->prepare('UPDATE usuario SET usu_contra = :hash WHERE idusuario = :id');
                    $update->execute([
                        ':hash' => $newHash,
                        ':id' => (int) $user['idusuario'],
                    ]);
                    $user['usu_contra'] = $newHash;
                }

                auth_store_user_session($user);
                auth_flash('success', 'Bienvenido de nuevo.');
                header('Location: ' . ($redirectTo !== '' ? $redirectTo : 'index.php'));
                exit;
            }
        }
    }
}

$flash = auth_pull_flash();
auth_render_head('Iniciar sesión');
auth_render_nav('login');
?>
<section class="auth-shell position-relative overflow-hidden">
  <div class="auth-orb orb-1"></div>
  <div class="auth-orb orb-2"></div>
  <div class="auth-grid"></div>
  <div class="container py-5 auth-content">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <div class="hero-visual p-4 p-lg-5 h-100 auth-showcase">
          <span class="badge rounded-pill custom-badge px-3 py-2 mb-3">
            <i class="bi bi-shield-lock me-1"></i> Acceso seguro
          </span>
          <h1 class="display-6 fw-bold mb-3">Entrá a tu cuenta</h1>
          <p class="text-white-50 mb-4">
            Guardá tus favoritos, armá comparativas y seguí de cerca los productos que te interesan desde un solo lugar.
          </p>
          <div class="d-grid gap-3">
            <div class="mini-stat">
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-white-50">Favoritos</span>
                <strong>Siempre a mano</strong>
              </div>
            </div>
            <div class="mini-stat">
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-white-50">Cuenta</span>
                <strong>Acceso personal</strong>
              </div>
            </div>
            <div class="mini-stat">
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-white-50">Seguridad</span>
                <strong>Contraseña protegida</strong>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="detail-card p-4 p-lg-5 auth-card">
          <div class="d-flex align-items-center gap-3 mb-4">
            <div class="icon-wrap"><i class="bi bi-person-circle"></i></div>
            <div>
              <div class="small text-body-secondary">Bienvenido</div>
              <h2 class="h3 fw-bold mb-0">Iniciar sesión</h2>
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

          <form method="post" action="login.php" class="row g-3">
            <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">
            <div class="col-12">
              <label for="email" class="form-label">Correo electrónico</label>
              <input type="email" class="form-control rounded-4" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
            </div>
            <div class="col-12">
              <label for="password" class="form-label">Contraseña</label>
              <div class="input-group auth-input-group">
                <input type="password" class="form-control rounded-start-4" id="password" name="password" autocomplete="current-password" required>
                <button class="btn btn-outline-primary rounded-end-4" type="button" data-toggle-password="#password">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-primary btn-lg rounded-4" type="submit">
                <i class="bi bi-box-arrow-in-right me-2"></i>Entrar
              </button>
            </div>
          </form>

          <div class="auth-divider my-4"><span>o</span></div>

          <div class="d-flex flex-column flex-sm-row gap-3 justify-content-between align-items-sm-center">
            <div class="text-body-secondary">¿Todavía no tenés cuenta?</div>
            <a href="registro.php" class="btn btn-outline-primary rounded-pill px-4">Crear cuenta</a>
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
