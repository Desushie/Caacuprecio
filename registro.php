<?php
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
    } else {
        ?>
        <!doctype html>
        <html lang="es" data-bs-theme="dark">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title><?= e($title) ?></title>
          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
          <link rel="stylesheet" href="./css/styles.css">
          <link rel="stylesheet" href="./css/auth.css?v=<?= filemtime(__DIR__ . '/css/auth.css') ?>">
          <script src="https://cdn.jsdelivr.net/npm/lucide@0.511.0/dist/umd/lucide.min.js"></script>
          <script src="./js/icons.js"></script>
        </head>
        <body class="theme-dark auth-page">
        <?php
        return;
    }

    echo '<script>document.body.classList.add("auth-page");</script>';
}

function auth_render_nav(string $active = 'registro'): void
{
    // Registro a pantalla completa: sin navbar.
}

function auth_render_footer(): void
{
    ?>
    <script>
    (function() {
      const root = document.documentElement;
      const body = document.body;
      const storageKey = 'caacuprecio-theme';
      const toggleButtons = [document.getElementById('themeToggle')].filter(Boolean);

      function applyTheme(theme) {
        const isDark = theme !== 'light';
        root.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');
        body.classList.toggle('theme-dark', isDark);
        body.classList.toggle('theme-light', !isDark);
        body.classList.add('auth-page');

        toggleButtons.forEach(function(btn) {
          const host = btn.querySelector('[data-lucide-host]');
          const text = btn.querySelector('span:not([data-lucide-host])');
          if (host && window.setLucideIcon) {
            setLucideIcon(host, isDark ? (host.dataset.iconDark || 'sun') : (host.dataset.iconLight || 'moon'));
          }
          if (text) {
            text.textContent = isDark ? 'Modo claro' : 'Modo oscuro';
          }
        });

        if (window.refreshLucideIcons) {
          refreshLucideIcons();
        }
      }

      const savedTheme = localStorage.getItem(storageKey) || 'dark';
      applyTheme(savedTheme);

      toggleButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
          const next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
          localStorage.setItem(storageKey, next);
          applyTheme(next);
        });
      });
    })();
    </script>
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
        $errors[] = 'Ingresa tu nombre.';
    } elseif (mb_strlen($nombre) > 45) {
        $errors[] = 'El nombre no puede superar 45 caracteres.';
    }

    if ($email === '') {
        $errors[] = 'Ingresa tu correo.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El correo no tiene un formato válido.';
    }

    if ($password === '') {
        $errors[] = 'Ingresa una contraseña.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    }

    if ($password !== $password2) {
        $errors[] = 'Las contraseñas no coinciden.';
    }

    $consentMissing = !consent_accepted();
    if ($consentMissing) {
        $errors[] = 'Debes aceptar los Términos y condiciones y la Política de privacidad para crear la cuenta.';
    }

    if (!$errors && ($captchaError = turnstile_error()) !== '') {
        $errors[] = $captchaError;
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

            auth_flash('success', 'Cuenta creada. Ya puedes entrar.');
            header('Location: login.php');
            exit;
        }
    }
}

$flash = auth_pull_flash();

$authStats = [
    'tiendas' => 0,
    'categorias' => 0,
    'productos' => 0,
    'bajas' => 0,
];

try {
    $authStats['tiendas'] = (int) $pdo->query('SELECT COUNT(*) FROM tiendas')->fetchColumn();
    $authStats['categorias'] = (int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn();
    $authStats['productos'] = (int) $pdo->query('SELECT COUNT(*) FROM productos WHERE pro_activo = 1')->fetchColumn();
} catch (Throwable $e) {
    // El panel visual sigue mostrando novedades genéricas.
}

try {
    $authStats['bajas'] = (int) $pdo->query('
        SELECT COUNT(*)
        FROM productos
        WHERE pro_activo = 1
          AND pro_precio_anterior IS NOT NULL
          AND pro_precio IS NOT NULL
          AND pro_precio < pro_precio_anterior
    ')->fetchColumn();
} catch (Throwable $e) {
    $authStats['bajas'] = 0;
}

$fmtCount = static function (int $n): string {
    return number_format($n, 0, ',', '.');
};

$visualSlides = [];

$visualTagIcons = [
    'Catálogo' => 'layout-grid',
    'Caacupé' => 'map-pin',
    'Novedad' => 'sparkles',
    'Precios' => 'trending-down',
    'Comparar' => 'scale',
    'Mejor precio' => 'badge-percent',
    'Favoritos' => 'heart',
    'Tu cuenta' => 'user-round',
    'Categorías' => 'shapes',
    'Explorar' => 'compass',
];

if ($authStats['productos'] > 0 && $authStats['tiendas'] > 0) {
    $visualSlides[] = [
        'tags' => ['Catálogo', 'Caacupé'],
        'quote' => 'Hoy puedes comparar ' . $fmtCount($authStats['productos']) . ' productos en ' . $fmtCount($authStats['tiendas']) . ' tiendas de la ciudad, en un solo lugar.',
        'name' => 'Catálogo actual',
        'role' => 'Tiendas locales',
    ];
}

if ($authStats['bajas'] > 0) {
    $visualSlides[] = [
        'tags' => ['Novedad', 'Precios'],
        'quote' => 'Hay ' . $fmtCount($authStats['bajas']) . ' productos con el precio más bajo que el registro anterior. Conviene revisar antes de salir.',
        'name' => 'Bajas de precio',
        'role' => 'Historial reciente',
    ];
}

$visualSlides[] = [
    'tags' => ['Comparar', 'Mejor precio'],
    'quote' => 'Mismo producto, distintas tiendas. Mira precio y stock juntos y elige con más claridad.',
    'name' => 'Caacuprecio',
    'role' => 'Comparador de precios',
];

$visualSlides[] = [
    'tags' => ['Favoritos', 'Tu cuenta'],
    'quote' => 'Guarda lo que te interesa y vuelve a tus comparaciones cuando decidas comprar.',
    'name' => 'Tu cuenta',
    'role' => 'Favoritos y seguimiento',
];

if ($authStats['categorias'] > 0 && count($visualSlides) < 4) {
    $visualSlides[] = [
        'tags' => ['Categorías', 'Explorar'],
        'quote' => 'El catálogo está ordenado en ' . $fmtCount($authStats['categorias']) . ' categorías para encontrar más rápido lo que buscas.',
        'name' => 'Por rubro',
        'role' => 'Navegación del catálogo',
    ];
}

auth_render_head('Crear cuenta');
auth_render_nav('registro');

$passwordError = false;
foreach ($errors as $error) {
    $lower = mb_strtolower($error);
    if (str_contains($lower, 'contraseña') || str_contains($lower, 'coinciden')) {
        $passwordError = true;
        break;
    }
}

$authMode = 'register';
$redirectTo = 'index.php';
$loginEmail = '';
$loginErrors = [];
$regNombre = $nombre;
$regEmail = $email;
$regErrors = $errors;
$regStartStep = ($passwordError || ($captchaError ?? '') !== '' || ($consentMissing ?? false)) ? 2 : 1;
?>
<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
</div>

<?php require __DIR__ . '/auth_toast.php'; ?>

<button id="themeToggle" class="auth-theme-btn" type="button" aria-label="Cambiar tema">
  <span data-lucide-host data-icon-dark="sun" data-icon-light="moon"><?= lucide_icon('sun', '', 18) ?></span>
  <span class="auth-theme-btn-label">Modo claro</span>
</button>

<a class="auth-back" href="index.php">
  <?= lucide_icon('arrow-left', '', 18) ?>
  <span>Volver</span>
</a>

<section class="hero auth-hero" aria-labelledby="auth-title-register">
  <div class="container">
    <div class="auth-board auth-board--register" data-auth-board data-auth-mode="register">
      <?php require __DIR__ . '/auth_panels.php'; ?>

      <div
        class="auth-visual-shell"
        data-auth-visual
        aria-label="Novedades de Caacuprecio"
        aria-roledescription="carrusel"
      >
        <aside class="auth-board-visual">
          <div class="auth-visual-wash" aria-hidden="true">
            <span class="auth-shape auth-shape-blob-a"></span>
            <span class="auth-shape auth-shape-blob-b"></span>
            <span class="auth-shape auth-shape-ring"></span>
            <span class="auth-shape auth-shape-pill"></span>
            <span class="auth-shape auth-shape-dot-a"></span>
            <span class="auth-shape auth-shape-dot-b"></span>
            <span class="auth-shape auth-shape-dot-c"></span>
            <span class="auth-shape auth-shape-arc"></span>
            <span class="auth-shape auth-shape-square"></span>
            <span class="auth-shape auth-shape-bar"></span>
            <span class="auth-shape auth-shape-petal"></span>
          </div>

          <div class="auth-visual-stage" aria-live="polite">
            <?php foreach ($visualSlides as $index => $slide): ?>
              <article
                class="auth-visual-slide<?= $index === 0 ? ' is-active' : '' ?>"
                data-auth-slide
                <?= $index === 0 ? '' : 'hidden' ?>
              >
                <div class="auth-visual-tags">
                  <?php foreach ($slide['tags'] as $tag): ?>
                    <span><?= lucide_icon($visualTagIcons[$tag] ?? 'tag', 'auth-visual-tag-icon', 14) ?><?= e($tag) ?></span>
                  <?php endforeach; ?>
                </div>
                <div class="auth-visual-card">
                  <p class="auth-visual-quote"><?= e($slide['quote']) ?></p>
                  <p class="auth-visual-name"><?= e($slide['name']) ?></p>
                  <p class="auth-visual-role"><?= e($slide['role']) ?></p>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </aside>

        <?php if (count($visualSlides) > 1): ?>
          <div class="auth-visual-nav">
            <button type="button" class="auth-visual-nav-btn" data-auth-prev aria-label="Novedad anterior">
              <?= lucide_icon('arrow-left', '', 18) ?>
            </button>
            <button type="button" class="auth-visual-nav-btn" data-auth-next aria-label="Novedad siguiente">
              <?= lucide_icon('arrow-right', '', 18) ?>
            </button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<script src="./js/auth-board.js?v=<?= filemtime(__DIR__ . '/js/auth-board.js') ?>"></script>
<?php auth_render_footer(); ?>
