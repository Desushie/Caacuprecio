<?php
require_once __DIR__ . '/config.php';
require_admin(); // Solo administradores globales pueden gestionar usuarios

$pdo = db();
$error = '';
$success = '';
$me = (int) (current_user()['idusuario'] ?? 0);

const AU_ROLES = [
    'admin' => ['tipo' => 1, 'label' => 'Administradores', 'single' => 'Administrador', 'icon' => 'shield-check', 'perm' => 'Todo el panel', 'perm_icon' => 'layout-dashboard', 'tone' => 'var(--accent)'],
    'empresa' => ['tipo' => 2, 'label' => 'Encargados', 'single' => 'Encargado', 'icon' => 'store', 'perm' => 'Solo su tienda', 'perm_icon' => 'lock-keyhole', 'tone' => 'var(--brand)'],
    'usuario' => ['tipo' => 0, 'label' => 'Compradores', 'single' => 'Comprador', 'icon' => 'user-round', 'perm' => 'Favoritos e historial', 'perm_icon' => 'heart', 'tone' => 'var(--accent-2)'],
];
const AU_ESTADOS = [
    'sin_tienda' => ['label' => 'Encargados sin tienda', 'chip' => 'Sin tienda', 'icon' => 'unplug', 'hint' => 'No pueden gestionar productos'],
    'reset' => ['label' => 'Recuperando contraseña', 'chip' => 'Recuperando contraseña', 'icon' => 'key-round', 'hint' => 'Enlace vigente sin usar'],
    'inactivos' => ['label' => 'Sin actividad', 'chip' => 'Sin actividad', 'icon' => 'moon', 'hint' => 'Nunca buscaron, vieron ni guardaron'],
];
const AU_ORDENES = [
    'recientes' => ['label' => 'Recientes', 'icon' => 'clock'],
    'nombre' => ['label' => 'Nombre', 'icon' => 'arrow-down-a-z'],
    'actividad' => ['label' => 'Actividad', 'icon' => 'activity'],
];
const AU_FILAS = [15, 30, 50, 100];
const AU_FILAS_DEFAULT = 15;

function au_role_key(int $tipo): string {
    return match ($tipo) {
        1 => 'admin',
        2 => 'empresa',
        default => 'usuario',
    };
}

function au_url(array $state, array $overrides = []): string {
    $params = array_merge($state, $overrides);
    if (($params['orden'] ?? '') === 'recientes') {
        $params['orden'] = '';
    }
    if ((int) ($params['filas'] ?? 0) === AU_FILAS_DEFAULT) {
        $params['filas'] = '';
    }
    if ((int) ($params['page'] ?? 0) <= 1) {
        $params['page'] = '';
    }
    $query = http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null));
    return 'admin_usuarios.php' . ($query !== '' ? '?' . $query : '');
}

function au_ago(?string $date): ?string {
    $ts = $date ? strtotime($date) : false;
    if ($ts === false) {
        return null;
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'ahora';
    }
    if ($diff < 3600) {
        return 'hace ' . intdiv($diff, 60) . ' min';
    }
    if ($diff < 86400) {
        return 'hace ' . intdiv($diff, 3600) . ' h';
    }
    $days = intdiv($diff, 86400);
    if ($days === 1) {
        return 'ayer';
    }
    return $days < 30 ? 'hace ' . $days . ' días' : date('d/m/Y', $ts);
}

$q = trim((string) ($_GET['q'] ?? ''));
$rol = array_key_exists((string) ($_GET['rol'] ?? ''), AU_ROLES) ? (string) $_GET['rol'] : '';
$estado = array_key_exists((string) ($_GET['estado'] ?? ''), AU_ESTADOS) ? (string) $_GET['estado'] : '';
$orden = array_key_exists((string) ($_GET['orden'] ?? ''), AU_ORDENES) ? (string) $_GET['orden'] : 'recientes';
$filas = (int) ($_GET['filas'] ?? AU_FILAS_DEFAULT);
if (!in_array($filas, AU_FILAS, true)) {
    $filas = AU_FILAS_DEFAULT;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$state = ['q' => $q, 'rol' => $rol, 'estado' => $estado, 'orden' => $orden, 'filas' => $filas];

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
                $stmt = $pdo->prepare("UPDATE usuario SET usu_nombre = :nombre, usu_email = :email, usu_tipo = :tipo, tiendas_idtiendas = :tienda WHERE idusuario = :id");
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':email'  => $email,
                    ':tipo'   => $tipo,
                    ':tienda' => $tienda_id,
                    ':id'     => $id
                ]);
                $success = 'Usuario actualizado correctamente.';
            } catch (PDOException $e) {
                $error = 'Error al actualizar el usuario: ' . $e->getMessage();
            }
        }
    }

    // ACCIÓN: ELIMINAR USUARIO
    if ($action === 'delete_user') {
        $id = (int)($_POST['idusuario'] ?? 0);

        if ($me === $id) {
            $error = 'No puedes eliminar tu propia cuenta de administrador en sesión.';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM usuario WHERE idusuario = :id");
                $stmt->execute([':id' => $id]);
                $success = 'Usuario eliminado de forma permanente.';
            } catch (PDOException $e) {
                $error = 'No se pudo eliminar al usuario (puede tener datos vinculados): ' . $e->getMessage();
            }
        }
    }
}

// 2. CONSULTAS DE DATOS (GET)
$allUsers = $pdo->query("
    SELECT
        u.idusuario, u.usu_nombre, u.usu_email, u.usu_tipo, u.tiendas_idtiendas,
        t.tie_nombre,
        (u.usu_reset_token IS NOT NULL AND u.usu_reset_expira > NOW()) AS reset_activo
    FROM usuario u
    LEFT JOIN tiendas t ON t.idtiendas = u.tiendas_idtiendas
    ORDER BY u.idusuario DESC
")->fetchAll();

$tiendas = $pdo->query("SELECT idtiendas, tie_nombre FROM tiendas ORDER BY tie_nombre ASC")->fetchAll();

$favs = $pdo->query("
    SELECT usuario_idusuario, COUNT(*) FROM favoritos GROUP BY usuario_idusuario
")->fetchAll(PDO::FETCH_KEY_PAIR);

$views = $pdo->query("
    SELECT usuario_idusuario, COUNT(*)
    FROM productos_vistos
    WHERE usuario_idusuario IS NOT NULL AND visto_en >= NOW() - INTERVAL 30 DAY
    GROUP BY usuario_idusuario
")->fetchAll(PDO::FETCH_KEY_PAIR);

$lastSeen = $pdo->query("
    SELECT uid, MAX(fecha)
    FROM (
        SELECT usuario_idusuario AS uid, MAX(visto_en) AS fecha FROM productos_vistos WHERE usuario_idusuario IS NOT NULL GROUP BY usuario_idusuario
        UNION ALL
        SELECT usuario_idusuario, MAX(click_fecha) FROM producto_clicks WHERE usuario_idusuario IS NOT NULL GROUP BY usuario_idusuario
        UNION ALL
        SELECT usuario_idusuario, MAX(fav_fecha) FROM favoritos GROUP BY usuario_idusuario
        UNION ALL
        SELECT bus_usuario_id, MAX(bus_ultima_fecha) FROM busquedas WHERE bus_usuario_id IS NOT NULL GROUP BY bus_usuario_id
    ) actividad
    GROUP BY uid
")->fetchAll(PDO::FETCH_KEY_PAIR);

$overview = [
    'roles' => array_fill_keys(array_keys(AU_ROLES), 0),
    'estados' => array_fill_keys(array_keys(AU_ESTADOS), 0),
    'activos' => 0,
];
$storesWithManager = [];
$activeSince = time() - 30 * 86400;

foreach ($allUsers as &$user) {
    $id = (int) $user['idusuario'];
    $user['rol'] = au_role_key((int) $user['usu_tipo']);
    $user['favs'] = (int) ($favs[$id] ?? 0);
    $user['vistas'] = (int) ($views[$id] ?? 0);
    $user['ultima'] = $lastSeen[$id] ?? null;
    $user['activo'] = $user['ultima'] !== null && strtotime($user['ultima']) >= $activeSince;
    $user['estados'] = array_keys(array_filter([
        'sin_tienda' => $user['rol'] === 'empresa' && empty($user['tie_nombre']),
        'reset' => (bool) $user['reset_activo'],
        'inactivos' => $user['ultima'] === null,
    ]));

    $overview['roles'][$user['rol']]++;
    $overview['activos'] += $user['activo'] ? 1 : 0;
    foreach ($user['estados'] as $flag) {
        $overview['estados'][$flag]++;
    }
    if ($user['rol'] === 'empresa' && !empty($user['tie_nombre'])) {
        $storesWithManager[(int) $user['tiendas_idtiendas']] = true;
    }
}
unset($user);

$userTotal = count($allUsers);
$activePct = $userTotal > 0 ? (int) round($overview['activos'] / $userTotal * 100) : 0;
$orphanStores = array_values(array_filter($tiendas, static fn($t) => !isset($storesWithManager[(int) $t['idtiendas']])));
$fmt = static fn(int|float $n): string => number_format($n, 0, ',', '.');

$usuarios = array_values(array_filter($allUsers, static function (array $user) use ($q, $rol, $estado): bool {
    if ($rol !== '' && $user['rol'] !== $rol) {
        return false;
    }
    if ($estado !== '' && !in_array($estado, $user['estados'], true)) {
        return false;
    }
    if ($q === '') {
        return true;
    }
    foreach (['usu_nombre', 'usu_email', 'tie_nombre'] as $col) {
        if (mb_stripos((string) ($user[$col] ?? ''), $q) !== false) {
            return true;
        }
    }
    return false;
}));

usort($usuarios, static function (array $a, array $b) use ($orden): int {
    return match ($orden) {
        'nombre' => strcasecmp($a['usu_nombre'], $b['usu_nombre']),
        'actividad' => [(string) $b['ultima'], $b['favs']] <=> [(string) $a['ultima'], $a['favs']],
        default => (int) $b['idusuario'] <=> (int) $a['idusuario'],
    };
});

$resultTotal = count($usuarios);
$totalPages = max(1, (int) ceil($resultTotal / $filas));
$page = min($page, $totalPages);
$offset = ($page - 1) * $filas;
$fromItem = $resultTotal > 0 ? $offset + 1 : 0;
$toItem = min($offset + $filas, $resultTotal);
$usuarios = array_slice($usuarios, $offset, $filas);
$returnUrl = au_url($state, ['page' => $page]);

$pageItems = [];
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1) {
        $pageItems[] = $i;
    } elseif (end($pageItems) !== null) {
        $pageItems[] = null;
    }
}

render_head('Gestión de Usuarios');
?>
<link rel="stylesheet" href="./css/admin.css?v=<?= filemtime(__DIR__ . '/css/admin.css') ?>">
<?php render_navbar('admin'); ?>

<?php
$flash = $error !== ''
    ? ['type' => 'danger', 'message' => $error]
    : ($success !== '' ? ['type' => 'success', 'message' => $success] : null);
require __DIR__ . '/auth_toast.php';
?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="dash-page au-page" aria-labelledby="au-title">
  <div class="container position-relative z-1">
    <nav aria-label="breadcrumb" class="pd-crumb">
      <ol class="breadcrumb custom-breadcrumb pd-crumb-list mb-0">
        <li class="breadcrumb-item">
          <a href="index.php" class="pd-crumb-link">
            <?= lucide_icon('home', '', 14) ?>
            <span>Inicio</span>
          </a>
        </li>
        <li class="breadcrumb-item">
          <a href="admin.php" class="pd-crumb-link"><span>Panel</span></a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current">Usuarios</span>
        </li>
      </ol>
    </nav>

    <div class="dash-board">

      <header class="dash-panel au-gate animate-reveal-up">
        <div class="au-gate-main">
          <div class="au-head">
            <div class="au-head-copy">
              <div class="au-head-kicker">
                <span class="au-tag"><?= lucide_icon('key-round', '', 14) ?>Control de accesos</span>
                <p class="au-total">
                  <?= lucide_icon('users-round', '', 14) ?>
                  <strong><?= $fmt($userTotal) ?></strong>
                  <span><?= $userTotal === 1 ? 'cuenta registrada' : 'cuentas registradas' ?></span>
                </p>
              </div>
              <h1 id="au-title" class="au-title">Usuarios</h1>
            </div>
          </div>

          <p class="au-lead">
            Cada cuenta es una credencial: revisa quién entra al panel, a qué tienda responde cada encargado
            y quién dejó de usar Caacuprecio. Toca una credencial para ver solo ese grupo.
          </p>

          <ul class="au-keys">
            <?php $keyNo = 0; ?>
            <?php foreach (AU_ROLES as $key => $role): ?>
              <?php
                $keyNo++;
                $count = $overview['roles'][$key];
                $share = $userTotal > 0 ? (int) round($count / $userTotal * 100) : 0;
                $isActive = $rol === $key;
              ?>
              <li>
                <a
                  href="<?= e(au_url($state, ['rol' => $isActive ? '' : $key])) ?>"
                  class="au-key <?= $isActive ? 'is-active' : '' ?>"
                  style="--k-c: <?= $role['tone'] ?>"
                  title="<?= $isActive ? 'Quitar filtro' : 'Ver solo ' . e(mb_strtolower($role['label'])) ?>"
                  <?= $isActive ? 'aria-current="true"' : '' ?>
                >
                  <span class="au-key-slot" aria-hidden="true"></span>
                  <span class="au-key-top">
                    <span class="au-key-icon" aria-hidden="true"><?= lucide_icon($role['icon'], '', 18) ?></span>
                    <span class="au-key-no" aria-hidden="true"><?= sprintf('%02d', $keyNo) ?></span>
                  </span>
                  <span class="au-key-count"><?= $fmt($count) ?></span>
                  <span class="au-key-name"><?= e($role['label']) ?></span>
                  <span class="au-key-perm"><?= lucide_icon($role['perm_icon'], '', 13) ?><?= e($role['perm']) ?></span>
                  <span class="au-key-foot">
                    <span class="au-key-meter" aria-hidden="true"><span style="width: <?= $share ?>%"></span></span>
                    <span class="au-key-share"><?= $share ?>%</span>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <aside class="au-watch" aria-labelledby="au-watch-title">
          <div class="au-pulse">
            <span class="au-pulse-label"><?= lucide_icon('activity', '', 14) ?>Activos · 30 días</span>
            <p class="au-pulse-value"><?= $activePct ?><small>%</small></p>
            <div class="au-pulse-meter" role="meter" aria-label="Cuentas activas en los últimos 30 días" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $activePct ?>">
              <span style="width: <?= $activePct ?>%"></span>
            </div>
            <p class="au-pulse-caption">
              <?= $fmt($overview['activos']) ?> <?= $overview['activos'] === 1 ? 'cuenta buscó, vio o guardó' : 'cuentas buscaron, vieron o guardaron' ?> productos
            </p>
          </div>

          <h2 id="au-watch-title" class="au-watch-title">Para revisar</h2>
          <ul class="au-flags">
            <?php foreach (AU_ESTADOS as $key => $flag): ?>
              <?php $count = $overview['estados'][$key]; $isActive = $estado === $key; ?>
              <li>
                <?php if ($count === 0): ?>
                  <span class="au-flag is-ok">
                    <span class="au-flag-icon" aria-hidden="true"><?= lucide_icon('check', '', 15) ?></span>
                    <span class="au-flag-copy">
                      <span class="au-flag-name"><?= e($flag['label']) ?></span>
                      <span class="au-flag-hint">Nada pendiente</span>
                    </span>
                  </span>
                <?php else: ?>
                  <a
                    href="<?= e(au_url($state, ['estado' => $isActive ? '' : $key])) ?>"
                    class="au-flag <?= $isActive ? 'is-active' : '' ?>"
                    title="<?= $isActive ? 'Quitar filtro' : 'Filtrar el listado' ?>"
                    <?= $isActive ? 'aria-current="true"' : '' ?>
                  >
                    <span class="au-flag-icon" aria-hidden="true"><?= lucide_icon($flag['icon'], '', 15) ?></span>
                    <span class="au-flag-copy">
                      <span class="au-flag-name"><?= e($flag['label']) ?></span>
                      <span class="au-flag-hint"><?= e($flag['hint']) ?></span>
                    </span>
                    <span class="au-flag-count"><?= $fmt($count) ?></span>
                    <?= lucide_icon($isActive ? 'x' : 'arrow-right', 'au-flag-arrow', 14) ?>
                  </a>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
            <li>
              <?php if (!$orphanStores): ?>
                <span class="au-flag is-ok">
                  <span class="au-flag-icon" aria-hidden="true"><?= lucide_icon('check', '', 15) ?></span>
                  <span class="au-flag-copy">
                    <span class="au-flag-name">Tiendas sin encargado</span>
                    <span class="au-flag-hint">Todas tienen una cuenta</span>
                  </span>
                </span>
              <?php else: ?>
                <a href="admin_tiendas.php" class="au-flag" title="Ir a tiendas">
                  <span class="au-flag-icon" aria-hidden="true"><?= lucide_icon('store', '', 15) ?></span>
                  <span class="au-flag-copy">
                    <span class="au-flag-name">Tiendas sin encargado</span>
                    <span class="au-flag-hint" title="<?= e(implode(', ', array_column($orphanStores, 'tie_nombre'))) ?>"><?= e(implode(', ', array_column($orphanStores, 'tie_nombre'))) ?></span>
                  </span>
                  <span class="au-flag-count"><?= $fmt(count($orphanStores)) ?></span>
                  <?= lucide_icon('arrow-up-right', 'au-flag-arrow', 14) ?>
                </a>
              <?php endif; ?>
            </li>
          </ul>
        </aside>
      </header>

      <section class="dash-panel au-list animate-reveal-up" aria-labelledby="au-list-title" style="--lane-accent: var(--brand)">
        <header class="dash-panel-head">
          <span class="dash-panel-icon" aria-hidden="true"><?= lucide_icon('users-round', '', 18) ?></span>
          <div class="au-list-heading">
            <div class="au-list-heading-top">
              <div class="au-list-heading-copy">
                <h2 id="au-list-title" class="dash-panel-title">Cuentas</h2>
                <p class="dash-panel-caption">
                  <?php if ($usuarios): ?>
                    <?php if ($totalPages > 1): ?>
                      <?= $fmt($fromItem) ?>–<?= $fmt($toItem) ?> de <?= $fmt($resultTotal) ?> resultados · por <?= e(mb_strtolower(AU_ORDENES[$orden]['label'])) ?>
                    <?php else: ?>
                      <?= $fmt($resultTotal) ?> <?= $resultTotal === 1 ? 'resultado' : 'resultados' ?> · por <?= e(mb_strtolower(AU_ORDENES[$orden]['label'])) ?>
                    <?php endif; ?>
                  <?php else: ?>
                    Ninguna cuenta coincide
                  <?php endif; ?>
                </p>
              </div>
              <?php
                $auRowsForm = static function (string $slot) use ($q, $rol, $estado, $orden, $filas): void {
              ?>
              <form method="get" class="au-rows-form is-<?= e($slot) ?>">
                <?php foreach (['q' => $q, 'rol' => $rol, 'estado' => $estado, 'orden' => $orden !== 'recientes' ? $orden : ''] as $name => $value): ?>
                  <?php if ($value !== ''): ?><input type="hidden" name="<?= $name ?>" value="<?= e($value) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <label class="ap-rows-select <?= $filas !== AU_FILAS_DEFAULT ? 'is-set' : '' ?>" title="Filas por página">
                  <?= lucide_icon('rows-3', 'ap-rows-select-icon', 15) ?>
                  <span class="ap-rows-select-label">Filas</span>
                  <span class="ap-rows-select-value" aria-hidden="true"><?= $filas ?></span>
                  <select name="filas" class="ap-rows-select-input" aria-label="Filas por página" data-au-rows>
                    <?php foreach (AU_FILAS as $option): ?>
                      <option value="<?= $option ?>" <?= $option === $filas ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?= lucide_icon('chevron-down', 'ap-rows-select-caret', 13) ?>
                </label>
                <noscript><button type="submit" class="at-search-go" aria-label="Aplicar"><?= lucide_icon('arrow-right', '', 15) ?></button></noscript>
              </form>
              <?php
                };
                $auRowsForm('head');
              ?>
            </div>
          </div>

          <form method="get" class="at-search" role="search">
            <?php if ($rol !== ''): ?><input type="hidden" name="rol" value="<?= e($rol) ?>"><?php endif; ?>
            <?php if ($estado !== ''): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
            <?php if ($orden !== 'recientes'): ?><input type="hidden" name="orden" value="<?= e($orden) ?>"><?php endif; ?>
            <?php if ($filas !== AU_FILAS_DEFAULT): ?><input type="hidden" name="filas" value="<?= $filas ?>"><?php endif; ?>
            <?= lucide_icon('search', 'at-search-icon', 16) ?>
            <label for="au-q" class="visually-hidden">Buscar usuarios</label>
            <input type="search" id="au-q" name="q" class="at-search-input" placeholder="Nombre, correo o tienda…" value="<?= e($q) ?>" autocomplete="off">
            <?php if ($q !== ''): ?>
              <a href="<?= e(au_url($state, ['q' => ''])) ?>" class="at-search-clear" aria-label="Borrar búsqueda" title="Borrar búsqueda"><?= lucide_icon('x', '', 14) ?></a>
            <?php endif; ?>
            <button type="submit" class="at-search-go" aria-label="Buscar"><?= lucide_icon('arrow-right', '', 15) ?></button>
          </form>
        </header>

        <div class="at-bar">
          <nav class="at-sort" aria-label="Ordenar cuentas">
            <?php foreach (AU_ORDENES as $key => $option): ?>
              <a
                href="<?= e(au_url($state, ['orden' => $key])) ?>"
                class="at-sort-item <?= $orden === $key ? 'is-active' : '' ?>"
                <?= $orden === $key ? 'aria-current="true"' : '' ?>
              >
                <?= lucide_icon($option['icon'], '', 14) ?><?= e($option['label']) ?>
              </a>
            <?php endforeach; ?>
          </nav>

          <div class="au-bar-end">
            <?php if ($rol !== '' || $estado !== ''): ?>
              <div class="au-chips">
                <?php if ($rol !== ''): ?>
                  <a href="<?= e(au_url($state, ['rol' => ''])) ?>" class="at-chip au-chip-role" style="--k-c: <?= AU_ROLES[$rol]['tone'] ?>" title="Quitar filtro">
                    <?= lucide_icon(AU_ROLES[$rol]['icon'], '', 13) ?><?= e(AU_ROLES[$rol]['label']) ?><?= lucide_icon('x', '', 13) ?>
                  </a>
                <?php endif; ?>
                <?php if ($estado !== ''): ?>
                  <a href="<?= e(au_url($state, ['estado' => ''])) ?>" class="at-chip" title="Quitar filtro">
                    <?= lucide_icon(AU_ESTADOS[$estado]['icon'], '', 13) ?><?= e(AU_ESTADOS[$estado]['chip']) ?><?= lucide_icon('x', '', 13) ?>
                  </a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php $auRowsForm('bar'); ?>
          </div>
        </div>

        <?php if ($usuarios): ?>
          <div class="au-table">
          <div class="au-cols" aria-hidden="true">
            <span>Cuenta</span>
            <span>Rol</span>
            <span>Tienda</span>
            <span>Actividad</span>
            <span></span>
          </div>
          <ul class="au-rows">
            <?php foreach ($usuarios as $user): ?>
              <?php
                $userId = (int) $user['idusuario'];
                $role = AU_ROLES[$user['rol']];
                $isMe = $userId === $me;
                $words = preg_split('/\s+/u', trim($user['usu_nombre'])) ?: [];
                $initials = mb_strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
                $ago = au_ago($user['ultima']);
              ?>
              <li class="au-row" style="--k-c: <?= $role['tone'] ?>">
                <div class="au-who">
                  <span class="au-avatar <?= $user['activo'] ? 'is-live' : '' ?>" title="<?= $user['activo'] ? 'Activo en los últimos 30 días' : 'Sin actividad reciente' ?>">
                    <?= e($initials !== '' ? $initials : '?') ?>
                  </span>
                  <div class="au-who-copy">
                    <p class="au-name">
                      <span title="<?= e($user['usu_nombre']) ?>"><?= e($user['usu_nombre']) ?></span>
                      <?php if ($isMe): ?><em class="au-me">Tú</em><?php endif; ?>
                      <?php if ($user['reset_activo']): ?>
                        <em class="au-reset" title="Tiene un enlace de recuperación de contraseña vigente"><?= lucide_icon('key-round', '', 12) ?>Recuperando</em>
                      <?php endif; ?>
                    </p>
                    <a href="mailto:<?= e($user['usu_email']) ?>" class="au-mail" title="<?= e($user['usu_email']) ?>"><?= e($user['usu_email']) ?></a>
                  </div>
                </div>

                <div class="au-corner">
                  <div class="au-role">
                    <span class="au-role-pill"><?= lucide_icon($role['icon'], '', 13) ?><?= e($role['single']) ?></span>
                  </div>
                  <div class="au-store">
                    <?php if ($user['rol'] === 'empresa' && !empty($user['tie_nombre'])): ?>
                      <a href="tienda.php?id=<?= (int) $user['tiendas_idtiendas'] ?>" class="au-store-link" target="_blank" rel="noopener" title="Ver página de la tienda">
                        <?= lucide_icon('store', '', 15) ?><span><?= e($user['tie_nombre']) ?></span>
                      </a>
                    <?php elseif ($user['rol'] === 'empresa'): ?>
                      <button type="button" class="au-store-missing" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $userId ?>">
                        <?= lucide_icon('unplug', '', 15) ?><span>Asignar tienda</span>
                      </button>
                    <?php else: ?>
                      <span class="au-store-none">—</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="au-activity">
                  <?php if ($ago !== null): ?>
                    <span class="au-seen"><?= lucide_icon('clock', '', 14) ?><?= e($ago) ?></span>
                    <span class="au-metrics">
                      <span title="Productos en favoritos"><?= lucide_icon('heart', '', 13) ?><?= $fmt($user['favs']) ?></span>
                      <span title="Productos vistos en 30 días"><?= lucide_icon('eye', '', 13) ?><?= $fmt($user['vistas']) ?></span>
                    </span>
                  <?php else: ?>
                    <span class="au-seen is-never"><?= lucide_icon('moon', '', 14) ?>Sin actividad</span>
                  <?php endif; ?>
                </div>

                <div class="au-actions">
                  <button type="button" class="au-act" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $userId ?>" aria-label="Editar a <?= e($user['usu_nombre']) ?>" title="Editar">
                    <?= lucide_icon('square-pen', '', 16) ?>
                  </button>
                  <?php if ($isMe): ?>
                    <span class="au-act is-locked" title="No puedes eliminar tu propia cuenta"><?= lucide_icon('lock', '', 16) ?></span>
                  <?php else: ?>
                    <button
                      type="button"
                      class="au-act is-danger"
                      data-bs-toggle="modal"
                      data-bs-target="#deleteUserModal"
                      data-id="<?= $userId ?>"
                      data-name="<?= e($user['usu_nombre']) ?>"
                      data-email="<?= e($user['usu_email']) ?>"
                      data-initials="<?= e($initials !== '' ? $initials : '?') ?>"
                      data-tone="<?= e($role['tone']) ?>"
                      data-role-key="<?= e($user['rol']) ?>"
                      data-favs="<?= $user['favs'] ?>"
                      data-store="<?= $user['rol'] === 'empresa' ? e((string) ($user['tie_nombre'] ?? '')) : '' ?>"
                      aria-label="Eliminar a <?= e($user['usu_nombre']) ?>"
                      title="Eliminar"
                    >
                      <?= lucide_icon('trash-2', '', 16) ?>
                    </button>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          </div>

          <?php if ($totalPages > 1): ?>
            <nav class="ap-pager" aria-label="Paginación de cuentas">
              <span class="ap-pager-info">Página <strong><?= $fmt($page) ?></strong> de <?= $fmt($totalPages) ?></span>
              <div class="ap-pages">
                <a
                  class="ap-page-btn <?= $page <= 1 ? 'is-disabled' : '' ?>"
                  href="<?= e(au_url($state, ['page' => max(1, $page - 1)])) ?>"
                  aria-label="Página anterior"
                  <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                ><?= lucide_icon('chevron-left', '', 15) ?></a>
                <?php foreach ($pageItems as $item): ?>
                  <?php if ($item === null): ?>
                    <span class="ap-page-gap" aria-hidden="true">…</span>
                  <?php else: ?>
                    <a
                      class="ap-page-btn <?= $item === $page ? 'is-current' : '' ?>"
                      href="<?= e(au_url($state, ['page' => $item])) ?>"
                      <?= $item === $page ? 'aria-current="page"' : '' ?>
                    ><?= $fmt($item) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
                <a
                  class="ap-page-btn <?= $page >= $totalPages ? 'is-disabled' : '' ?>"
                  href="<?= e(au_url($state, ['page' => min($totalPages, $page + 1)])) ?>"
                  aria-label="Página siguiente"
                  <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                ><?= lucide_icon('chevron-right', '', 15) ?></a>
              </div>
            </nav>
          <?php endif; ?>
        <?php else: ?>
          <div class="at-empty">
            <span class="at-empty-icon" aria-hidden="true"><?= lucide_icon('user-round-x', '', 26) ?></span>
            <p class="at-empty-title">No hay cuentas para mostrar</p>
            <p class="at-empty-text">
              <?= $q !== '' ? 'Nada coincide con “' . e($q) . '”.' : 'Ninguna cuenta cumple los filtros activos.' ?>
            </p>
            <a href="admin_usuarios.php" class="at-empty-action"><?= lucide_icon('rotate-ccw', '', 14) ?>Ver todas las cuentas</a>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>
</section>

<?php if (!empty($usuarios)): ?>
  <?php foreach ($usuarios as $user): ?>
    <?php
      $mId = (int) $user['idusuario'];
      $fid = 'au-edit-' . $mId;
      $mRole = $user['rol'];
      $mIsMe = $mId === $me;
      $mWords = preg_split('/\s+/u', trim($user['usu_nombre'])) ?: [];
      $mInitials = mb_strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice($mWords, 0, 2))));
      $mStoreId = (int) ($user['tiendas_idtiendas'] ?? 0);
    ?>
    <div class="modal fade admin-modal pe-modal au-modal" id="editUserModal<?= $mId ?>" tabindex="-1" aria-labelledby="<?= $fid ?>-title" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form action="<?= e($returnUrl) ?>" method="POST" class="modal-content pe-content" data-au-edit style="--k-c: <?= AU_ROLES[$mRole]['tone'] ?>">
          <input type="hidden" name="action" value="save_user">
          <input type="hidden" name="idusuario" value="<?= $mId ?>">

          <div class="modal-header pe-header">
            <div class="pe-heading">
              <span class="au-avatar au-modal-avatar <?= $user['activo'] ? 'is-live' : '' ?>" aria-hidden="true"><?= e($mInitials !== '' ? $mInitials : '?') ?></span>
              <div class="pe-heading-text">
                <span class="pe-kicker">Editar acceso <span class="pe-kicker-id">#<?= $mId ?></span><?php if ($mIsMe): ?> <em class="au-me">Tú</em><?php endif; ?></span>
                <h2 class="modal-title pe-title" id="<?= $fid ?>-title" title="<?= e($user['usu_nombre']) ?>"><?= e($user['usu_nombre']) ?></h2>
              </div>
            </div>
            <button type="button" class="pe-close" data-bs-dismiss="modal" aria-label="Cerrar"><?= lucide_icon('x', '', 18) ?></button>
          </div>

          <div class="modal-body pe-body au-modal-body">
            <section class="pe-group is-info">
              <header class="pe-group-head">
                <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('id-card', '', 14) ?></span>
                <div class="pe-group-copy">
                  <h3 class="pe-group-title">Datos de la cuenta</h3>
                  <p class="pe-group-hint">Cómo se identifica y dónde recibe los correos.</p>
                </div>
              </header>
              <div class="pe-row">
                <div class="pe-field">
                  <label class="form-label" for="<?= $fid ?>-nombre">Nombre <span class="pe-req" aria-hidden="true">*</span></label>
                  <div class="pe-affix">
                    <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('user-round', '', 15) ?></span>
                    <input type="text" id="<?= $fid ?>-nombre" name="usu_nombre" class="form-control" value="<?= e($user['usu_nombre']) ?>" required autocomplete="off">
                  </div>
                </div>
                <div class="pe-field">
                  <label class="form-label" for="<?= $fid ?>-mail">Correo <span class="pe-req" aria-hidden="true">*</span></label>
                  <div class="pe-affix">
                    <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('mail', '', 15) ?></span>
                    <input type="email" id="<?= $fid ?>-mail" name="usu_email" class="form-control" value="<?= e($user['usu_email']) ?>" required autocomplete="off">
                  </div>
                </div>
              </div>
            </section>

            <fieldset class="pe-group is-role">
              <legend class="visually-hidden">Rol de la cuenta</legend>
              <header class="pe-group-head">
                <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('key-round', '', 14) ?></span>
                <div class="pe-group-copy">
                  <h3 class="pe-group-title">Rol y permisos</h3>
                  <p class="pe-group-hint">Define qué puede ver y gestionar en Caacuprecio.</p>
                </div>
              </header>

              <div class="au-roles">
                <?php foreach (['usuario', 'empresa', 'admin'] as $key): ?>
                  <?php $opt = AU_ROLES[$key]; ?>
                  <label class="au-role-opt" style="--k-c: <?= $opt['tone'] ?>">
                    <input type="radio" class="au-role-input" name="usu_tipo" value="<?= $opt['tipo'] ?>" <?= $mRole === $key ? 'checked' : '' ?> data-au-role="<?= $key ?>" required>
                    <span class="au-role-opt-icon" aria-hidden="true"><?= lucide_icon($opt['icon'], '', 16) ?></span>
                    <span class="au-role-opt-copy">
                      <strong><?= e($opt['single']) ?></strong>
                      <small><?= e($opt['perm']) ?></small>
                    </span>
                    <span class="au-role-opt-check" aria-hidden="true"><?= lucide_icon('check', '', 12) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>

              <div class="pe-field au-store-field" data-au-store <?= $mRole === 'empresa' ? '' : 'hidden' ?>>
                <label class="form-label" for="<?= $fid ?>-tienda">Tienda que gestiona</label>
                <div class="pe-affix">
                  <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('store', '', 15) ?></span>
                  <select id="<?= $fid ?>-tienda" name="tiendas_idtiendas" class="form-select">
                    <option value="">Sin tienda asignada</option>
                    <?php foreach ($tiendas as $tienda): ?>
                      <option value="<?= (int) $tienda['idtiendas'] ?>" <?= $mStoreId === (int) $tienda['idtiendas'] ? 'selected' : '' ?>><?= e($tienda['tie_nombre']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <p class="pe-price-note is-warn" data-au-store-warn <?= $mStoreId > 0 ? 'hidden' : '' ?>>
                  <?= lucide_icon('unplug', 'pe-price-note-icon', 16) ?>
                  <span>Sin tienda, el encargado no podrá gestionar productos ni ver estadísticas.</span>
                </p>
              </div>

              <?php if ($mIsMe): ?>
                <p class="pe-price-note is-warn" data-au-self-warn hidden>
                  <?= lucide_icon('triangle-alert', 'pe-price-note-icon', 16) ?>
                  <span>Estás editando tu propia cuenta: si dejas de ser administrador perderás el acceso al panel.</span>
                </p>
              <?php endif; ?>
            </fieldset>
          </div>

          <div class="modal-footer pe-footer">
            <span class="pe-footer-hint"><span class="pe-req" aria-hidden="true">*</span> Campos obligatorios</span>
            <div class="pe-footer-actions">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary"><?= lucide_icon('check', '', 16) ?>Guardar cambios</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="au-delete-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form action="<?= e($returnUrl) ?>" method="POST" class="modal-content suggestion-modal report-product-modal dash-confirm">
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="idusuario" value="" data-au-del="id">

        <div class="modal-header">
          <div class="report-product-heading">
            <span class="report-product-icon" aria-hidden="true"><?= lucide_icon('trash-2', '', 18) ?></span>
            <div class="report-product-heading-text">
              <p class="dash-confirm-kicker">Zona sensible</p>
              <h5 class="modal-title" id="au-delete-title">¿Eliminar esta cuenta?</h5>
              <p class="report-product-lead">Esta acción es definitiva y no se puede deshacer.</p>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <div class="dash-confirm-item" data-au-del="card">
            <span class="au-avatar dash-confirm-avatar" data-au-del="initials" aria-hidden="true">?</span>
            <div class="dash-confirm-copy">
              <p class="dash-confirm-name" data-au-del="name"></p>
              <div class="dash-confirm-meta">
                <span><?= lucide_icon('mail', '', 13) ?><span data-au-del="email"></span></span>
              </div>
            </div>
            <?php foreach (AU_ROLES as $roleKey => $roleData): ?>
              <span class="au-role-pill dash-confirm-pill" data-au-del-role="<?= e($roleKey) ?>" hidden><?= lucide_icon($roleData['icon'], '', 13) ?><?= e($roleData['single']) ?></span>
            <?php endforeach; ?>
          </div>

          <ul class="dash-confirm-list">
            <li>
              <?= lucide_icon('log-in', '', 16) ?>
              <span>Ya no podrá <strong>iniciar sesión</strong> en Caacuprecio.</span>
            </li>
            <li data-au-del="favs-row">
              <?= lucide_icon('heart-off', '', 16) ?>
              <span data-au-del="favs"></span>
            </li>
            <li data-au-del="store-row">
              <?= lucide_icon('store', '', 16) ?>
              <span data-au-del="store"></span>
            </li>
          </ul>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger"><?= lucide_icon('trash-2', '', 16) ?>Eliminar cuenta</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>


<script>
document.querySelectorAll('[data-au-rows]').forEach(function(rowsSelect) {
  rowsSelect.addEventListener('change', function() {
    const value = rowsSelect.parentElement.querySelector('.ap-rows-select-value');
    if (value) value.textContent = rowsSelect.value;
    rowsSelect.form.requestSubmit();
  });
});

document.querySelectorAll('[data-au-edit]').forEach(function(form) {
  const store = form.querySelector('[data-au-store]');
  const storeSelect = store.querySelector('select');
  const storeWarn = form.querySelector('[data-au-store-warn]');
  const selfWarn = form.querySelector('[data-au-self-warn]');

  const sync = function() {
    const checked = form.querySelector('[data-au-role]:checked');
    const role = checked ? checked.dataset.auRole : '';
    store.hidden = role !== 'empresa';
    storeWarn.hidden = storeSelect.value !== '';
    if (selfWarn) selfWarn.hidden = role === 'admin';
    if (checked) form.style.setProperty('--k-c', checked.closest('.au-role-opt').style.getPropertyValue('--k-c'));
  };

  form.addEventListener('change', sync);
  form.closest('.modal').addEventListener('hidden.bs.modal', function() {
    form.reset();
    sync();
  });
});

const deleteModal = document.getElementById('deleteUserModal');
if (deleteModal) {
  const field = function(name) { return deleteModal.querySelector('[data-au-del="' + name + '"]'); };

  deleteModal.addEventListener('show.bs.modal', function(event) {
    const d = event.relatedTarget ? event.relatedTarget.dataset : {};
    const favs = parseInt(d.favs || '0', 10);

    field('id').value = d.id || '';
    field('card').style.setProperty('--k-c', d.tone || 'var(--brand)');
    field('initials').textContent = d.initials || '?';
    field('name').textContent = d.name || '';
    field('email').textContent = d.email || '';
    deleteModal.querySelectorAll('[data-au-del-role]').forEach(function(pill) {
      pill.hidden = pill.dataset.auDelRole !== d.roleKey;
    });
    field('favs').innerHTML = favs === 1
      ? 'Pierde <strong>1 producto</strong> guardado en favoritos.'
      : 'Pierde <strong>' + favs.toLocaleString('es-PY') + ' productos</strong> guardados en favoritos.';
    field('favs-row').hidden = favs === 0;
    field('store').replaceChildren('La tienda ', Object.assign(document.createElement('strong'), { textContent: d.store || '' }), ' queda sin encargado.');
    field('store-row').hidden = !d.store;
  });
}
</script>

<?php 
render_footer(); 
?>
