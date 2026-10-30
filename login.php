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

function auth_render_nav(string $active = 'login'): void
{
    // Login a pantalla completa: sin navbar.
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

      function paintThemeCore(theme) {
        const isDark = theme !== 'light';
        root.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');
        root.classList.toggle('theme-dark', isDark);
        root.classList.toggle('theme-light', !isDark);
        body.classList.toggle('theme-dark', isDark);
        body.classList.toggle('theme-light', !isDark);
        body.classList.add('auth-page');
      }

      function paintThemeChrome(theme) {
        const isDark = theme !== 'light';
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
      }

      function supportsColorTween() {
        try {
          return CSS && CSS.registerProperty && CSS.supports('(color: color-mix(in srgb, red, blue))');
        } catch (e) {
          return false;
        }
      }

      function applyTheme(theme, animate) {
        const run = function() {
          paintThemeCore(theme);
          paintThemeChrome(theme);
        };
        if (!animate) {
          run();
          return;
        }
        if (document.startViewTransition) {
          root.classList.add('theme-vt-active');
          const vt = document.startViewTransition(function() { paintThemeCore(theme); });
          Promise.resolve(vt.finished).catch(function() {}).then(function() {
            root.classList.remove('theme-vt-active');
            paintThemeChrome(theme);
          });
          return;
        }
        if (supportsColorTween()) {
          paintThemeCore(theme);
          requestAnimationFrame(function() { paintThemeChrome(theme); });
          return;
        }
        run();
      }

      const savedTheme = localStorage.getItem(storageKey) || 'dark';
      applyTheme(savedTheme, false);
      requestAnimationFrame(function() { body.classList.add('theme-animated'); });

      toggleButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
          const next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
          localStorage.setItem(storageKey, next);
          applyTheme(next, true);
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

function auth_find_user_by_email(PDO $pdo, string $email)
{
    $stmt = $pdo->prepare('SELECT idusuario, usu_nombre, usu_email, usu_contra, usu_tipo, tiendas_idtiendas FROM usuario WHERE usu_email = :email LIMIT 1');
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
        'tiendas_idtiendas' => (int) $user['tiendas_idtiendas'],
    ];
}

if (!empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$pdo = db();
$errors = [];
$email = trim($_POST['email'] ?? '');
$redirectTo = safe_redirect_path((string) ($_GET['next'] ?? ($_GET['redirect'] ?? ($_POST['redirect'] ?? 'index.php'))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '') {
        $errors[] = 'Ingresa tu correo.';
    }

    if ($password === '') {
        $errors[] = 'Ingresa tu contraseña.';
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
        'quote' => 'Compara ' . $fmtCount($authStats['productos']) . ' productos en ' . $fmtCount($authStats['tiendas']) . ' tiendas de Caacupé.',
        'name' => 'Catálogo actual',
        'role' => 'Tiendas locales',
    ];
}

if ($authStats['bajas'] > 0) {
    $visualSlides[] = [
        'tags' => ['Novedad', 'Precios'],
        'quote' => 'Hay ' . $fmtCount($authStats['bajas']) . ' productos con el precio más bajo que antes.',
        'name' => 'Bajas de precio',
        'role' => 'Historial reciente',
    ];
}

$visualSlides[] = [
    'tags' => ['Comparar', 'Mejor precio'],
    'quote' => 'Mismo producto, distintas tiendas: compara precio y stock juntos.',
    'name' => 'Caacuprecio',
    'role' => 'Comparador de precios',
];

$visualSlides[] = [
    'tags' => ['Favoritos', 'Tu cuenta'],
    'quote' => 'Guarda lo que te interesa y vuelve cuando decidas comprar.',
    'name' => 'Tu cuenta',
    'role' => 'Favoritos y seguimiento',
];

if ($authStats['categorias'] > 0 && count($visualSlides) < 4) {
    $visualSlides[] = [
        'tags' => ['Categorías', 'Explorar'],
        'quote' => 'Explora el catálogo en ' . $fmtCount($authStats['categorias']) . ' categorías y encuentra más rápido.',
        'name' => 'Por rubro',
        'role' => 'Navegación del catálogo',
    ];
}

auth_render_head('Iniciar sesión');
auth_render_nav('login');

$backHref = 'index.php';
if ($redirectTo !== '' && !preg_match('#(^|/)login\.php(\?|$)#i', $redirectTo)) {
    $backHref = $redirectTo;
}

$authMode = 'login';
$loginEmail = $email;
$loginErrors = $errors;
$regNombre = '';
$regEmail = '';
$regErrors = [];
$regStartStep = 1;
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

<a class="auth-back" href="<?= e($backHref) ?>">
  <?= lucide_icon('arrow-left', '', 18) ?>
  <span>Volver</span>
</a>

<section class="hero auth-hero" aria-labelledby="auth-title-login">
  <div class="container">
    <div class="auth-board" data-auth-board data-auth-mode="login">
      <?php require __DIR__ . '/auth_panels.php'; ?>
      <?php require __DIR__ . '/auth_visual.php'; ?>
    </div>
  </div>
</section>

<script src="./js/auth-board.js?v=<?= filemtime(__DIR__ . '/js/auth-board.js') ?>"></script>
<?php auth_render_footer(); ?>
