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

const AT_CAMPOS = [
    'logo' => ['col' => 'tie_logo', 'label' => 'Logo', 'icon' => 'image'],
    'ubicacion' => ['col' => 'tie_ubicacion', 'label' => 'Ubicación', 'icon' => 'map-pin'],
    'url' => ['col' => 'tie_url', 'label' => 'Sitio web', 'icon' => 'globe'],
    'telefono' => ['col' => 'tie_telefono', 'label' => 'Teléfono', 'icon' => 'phone'],
    'email' => ['col' => 'tie_email', 'label' => 'Correo', 'icon' => 'mail'],
    'horarios' => ['col' => 'tie_horarios', 'label' => 'Horarios', 'icon' => 'clock'],
];
const AT_ORDENES = [
    'nombre' => ['label' => 'Nombre', 'icon' => 'arrow-down-a-z'],
    'productos' => ['label' => 'Productos', 'icon' => 'package'],
    'clics' => ['label' => 'Clics', 'icon' => 'mouse-pointer-click'],
    'reputacion' => ['label' => 'Reputación', 'icon' => 'star'],
];
const AT_DIAS = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];
const AT_DIAS_CORTOS = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

function at_url(array $state, array $overrides = []): string {
    $params = array_merge($state, $overrides);
    if (($params['orden'] ?? '') === 'nombre') {
        $params['orden'] = '';
    }
    $query = http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null));
    return 'admin_tiendas.php' . ($query !== '' ? '?' . $query : '');
}

function at_hm(int $minutes): string {
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}

// Convierte el texto libre de horarios ("Lunes 8:30 a.m. - 12:30 p.m., 1:30 - 6 p.m.") en tramos por día, en minutos
function at_parse_horarios(?string $text): array {
    $toMinutes = static function (int $hour, int $minute, string $meridiem): int {
        if ($meridiem === 'p' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'a' && $hour === 12) {
            $hour = 0;
        }
        return $hour * 60 + $minute;
    };

    $days = [];
    $text = str_replace(["\u{202F}", "\u{00A0}"], ' ', (string) $text);

    foreach (preg_split('/\R/u', $text) as $line) {
        $plain = strtr(mb_strtolower(trim($line)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        if (!preg_match('/^(lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b(.*)$/u', $plain, $m)) {
            continue;
        }

        preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*([ap])?\.?\s*m?\.?\s*[-–]\s*(\d{1,2})(?::(\d{2}))?\s*([ap])?/u', $m[2], $found, PREG_SET_ORDER);

        $ranges = [];
        foreach ($found as $r) {
            $endMeridiem = $r[6] ?? '';
            $end = $toMinutes((int) $r[4], (int) ($r[5] ?? 0), $endMeridiem);
            $startMeridiem = $r[3] ?? '';
            // "1:30 - 6 p.m." hereda la tarde del cierre; "11 - 2 p.m." vuelve a la mañana
            $start = $toMinutes((int) $r[1], (int) ($r[2] ?? 0), $startMeridiem !== '' ? $startMeridiem : $endMeridiem);
            if ($startMeridiem === '' && $start > $end) {
                $start = $toMinutes((int) $r[1], (int) ($r[2] ?? 0), 'a');
            }
            if ($end > $start) {
                $ranges[] = [$start, $end];
            }
        }
        $days[AT_DIAS[$m[1]]] = $ranges;
    }

    return $days;
}

function at_store_status(array $days): array {
    if (!$days) {
        return ['open' => null, 'tone' => 'is-off', 'state' => 'Sin horario', 'detail' => '', 'today' => null];
    }

    $dow = (int) date('N');
    $now = (int) date('G') * 60 + (int) date('i');
    $today = $days[$dow] ?? [];
    $todayLabel = $today ? implode(' · ', array_map(static fn($r) => at_hm($r[0]) . '–' . at_hm($r[1]), $today)) : 'Cerrado';

    foreach ($today as [$start, $end]) {
        if ($now >= $start && $now < $end) {
            return ['open' => true, 'tone' => 'is-good', 'state' => 'Abierta', 'detail' => 'hasta las ' . at_hm($end), 'today' => $todayLabel];
        }
    }
    foreach ($today as [$start]) {
        if ($start > $now) {
            return ['open' => false, 'tone' => 'is-mid', 'state' => 'Cerrada', 'detail' => 'abre hoy ' . at_hm($start), 'today' => $todayLabel];
        }
    }
    for ($i = 1; $i <= 7; $i++) {
        $day = ($dow - 1 + $i) % 7 + 1;
        if (!empty($days[$day])) {
            $when = $i === 1 ? 'mañana' : 'el ' . AT_DIAS_CORTOS[$day];
            return ['open' => false, 'tone' => 'is-low', 'state' => 'Cerrada', 'detail' => 'abre ' . $when . ' ' . at_hm($days[$day][0][0]), 'today' => $todayLabel];
        }
    }

    return ['open' => false, 'tone' => 'is-low', 'state' => 'Cerrada', 'detail' => '', 'today' => $todayLabel];
}

$q = trim((string) ($_GET['q'] ?? ''));
$falta = array_key_exists((string) ($_GET['falta'] ?? ''), AT_CAMPOS) ? (string) $_GET['falta'] : '';
$orden = array_key_exists((string) ($_GET['orden'] ?? ''), AT_ORDENES) ? (string) $_GET['orden'] : 'nombre';
$state = ['q' => $q, 'falta' => $falta, 'orden' => $orden];

$allStores = $pdo->query("
    SELECT
        t.*,
        COUNT(p.idproductos) AS total_productos,
        COALESCE(SUM(p.pro_activo = 1), 0) AS publicados,
        COALESCE(SUM(p.pro_activo = 1 AND p.pro_precio_anterior > p.pro_precio), 0) AS ofertas
    FROM tiendas t
    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas
    GROUP BY t.idtiendas
    ORDER BY t.tie_nombre ASC
")->fetchAll();

$reviews = $pdo->query("
    SELECT tiendas_idtiendas, COUNT(*) AS total, AVG(rev_puntaje) AS promedio
    FROM tienda_reviews
    WHERE rev_activo = 1
    GROUP BY tiendas_idtiendas
")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$clicks = $pdo->query("
    SELECT
        p.tiendas_idtiendas,
        SUM(c.click_fecha >= NOW() - INTERVAL 30 DAY) AS actual,
        SUM(c.click_fecha < NOW() - INTERVAL 30 DAY) AS previo
    FROM producto_clicks c
    INNER JOIN productos p ON p.idproductos = c.productos_idproductos
    WHERE c.click_fecha >= NOW() - INTERVAL 60 DAY
    GROUP BY p.tiendas_idtiendas
")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$accounts = $pdo->query("
    SELECT tiendas_idtiendas, GROUP_CONCAT(usu_nombre ORDER BY usu_nombre SEPARATOR ', ') AS nombres
    FROM usuario
    WHERE usu_tipo = 2 AND tiendas_idtiendas IS NOT NULL
    GROUP BY tiendas_idtiendas
")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$overview = [
    'publicados' => 0,
    'productos' => 0,
    'clics' => 0,
    'clics_previo' => 0,
    'reviews' => 0,
    'puntaje' => 0.0,
    'abiertas' => 0,
    'con_horario' => 0,
    'con_cuenta' => 0,
    'completas' => 0,
    'datos' => 0,
];
$gaps = array_fill_keys(array_keys(AT_CAMPOS), 0);

foreach ($allStores as &$store) {
    $id = (int) $store['idtiendas'];
    $store['reviews'] = (int) ($reviews[$id]['total'] ?? 0);
    $store['rating'] = $store['reviews'] > 0 ? (float) $reviews[$id]['promedio'] : null;
    $store['clics'] = (int) ($clicks[$id]['actual'] ?? 0);
    $store['cuenta'] = $accounts[$id]['nombres'] ?? null;
    $store['status'] = at_store_status(at_parse_horarios($store['tie_horarios'] ?? ''));
    $store['faltan'] = [];
    foreach (AT_CAMPOS as $key => $field) {
        if (trim((string) ($store[$field['col']] ?? '')) === '') {
            $store['faltan'][] = $key;
            $gaps[$key]++;
        }
    }

    $overview['publicados'] += (int) $store['publicados'];
    $overview['productos'] += (int) $store['total_productos'];
    $overview['clics'] += $store['clics'];
    $overview['clics_previo'] += (int) ($clicks[$id]['previo'] ?? 0);
    $overview['reviews'] += $store['reviews'];
    $overview['puntaje'] += (float) $store['rating'] * $store['reviews'];
    $overview['abiertas'] += $store['status']['open'] === true ? 1 : 0;
    $overview['con_horario'] += $store['status']['open'] !== null ? 1 : 0;
    $overview['con_cuenta'] += $store['cuenta'] !== null ? 1 : 0;
    $overview['completas'] += $store['faltan'] ? 0 : 1;
    $overview['datos'] += count(AT_CAMPOS) - count($store['faltan']);
}
unset($store);

$storeTotal = count($allStores);
$fieldTotal = $storeTotal * count(AT_CAMPOS);
$profilePct = $fieldTotal > 0 ? (int) round($overview['datos'] / $fieldTotal * 100) : 0;
$ratingAvg = $overview['reviews'] > 0 ? $overview['puntaje'] / $overview['reviews'] : null;
$clicksDelta = $overview['clics_previo'] > 0
    ? (int) round(($overview['clics'] - $overview['clics_previo']) / $overview['clics_previo'] * 100)
    : null;
$fmt = static fn(int|float $n): string => number_format($n, 0, ',', '.');

$stores = array_values(array_filter($allStores, static function (array $store) use ($q, $falta): bool {
    if ($falta !== '' && !in_array($falta, $store['faltan'], true)) {
        return false;
    }
    if ($q === '') {
        return true;
    }
    foreach (['tie_nombre', 'tie_descripcion', 'tie_ubicacion', 'tie_url', 'tie_contacto', 'tie_telefono', 'tie_email'] as $col) {
        if (mb_stripos((string) ($store[$col] ?? ''), $q) !== false) {
            return true;
        }
    }
    return false;
}));

usort($stores, static function (array $a, array $b) use ($orden): int {
    $diff = match ($orden) {
        'productos' => (int) $b['publicados'] <=> (int) $a['publicados'],
        'clics' => $b['clics'] <=> $a['clics'],
        'reputacion' => [(float) $b['rating'], $b['reviews']] <=> [(float) $a['rating'], $a['reviews']],
        default => 0,
    };
    return $diff !== 0 ? $diff : strcasecmp($a['tie_nombre'], $b['tie_nombre']);
});

render_head('Administrar tiendas');
?>
<link rel="stylesheet" href="./css/admin.css?v=<?= filemtime(__DIR__ . '/css/admin.css') ?>">
<?php render_navbar('admin'); ?>

<?php
$flash = isset($_GET['saved']) ? ['type' => 'success', 'title' => 'Cambios guardados.', 'message' => 'La ficha de la tienda se actualizó correctamente.'] : null;
require __DIR__ . '/auth_toast.php';
?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="dash-page at-page" aria-labelledby="at-title">
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
          <span class="pd-crumb-current">Tiendas</span>
        </li>
      </ol>
    </nav>

    <div class="dash-board">

      <header class="dash-panel at-front animate-reveal-up">
        <div class="at-front-main">
          <div class="at-kicker">
            <span class="at-tag"><?= lucide_icon('store', '', 14) ?>Directorio comercial</span>
            <span class="at-status <?= $overview['abiertas'] > 0 ? 'is-open' : '' ?>" role="status">
              <span class="at-status-dot" aria-hidden="true"></span>
              <strong><?= $fmt($overview['abiertas']) ?></strong>/<?= $fmt($overview['con_horario']) ?>
              <?= $overview['abiertas'] === 1 ? 'abierta' : 'abiertas' ?> ahora
            </span>
          </div>

          <h1 id="at-title" class="at-title">Tiendas</h1>
          <p class="at-lead">
            <?= $fmt($storeTotal) ?> <?= $storeTotal === 1 ? 'comercio publica' : 'comercios publican' ?> precios en Caacuprecio.
            Mantén al día su vitrina: logo, contacto y horarios son lo primero que mira un comprador.
          </p>

          <dl class="at-ledger">
            <div class="at-ledger-item" style="--l-c: var(--brand)">
              <dt><?= lucide_icon('package-check', '', 15) ?>Publicados</dt>
              <dd><?= $fmt($overview['publicados']) ?><small>de <?= $fmt($overview['productos']) ?></small></dd>
            </div>
            <div class="at-ledger-item" style="--l-c: var(--accent-2)">
              <dt><?= lucide_icon('mouse-pointer-click', '', 15) ?>Clics · 30 días</dt>
              <dd>
                <?= $fmt($overview['clics']) ?>
                <?php if ($clicksDelta !== null): ?>
                  <em class="at-delta <?= $clicksDelta >= 0 ? 'is-up' : 'is-down' ?>" title="Frente a los 30 días anteriores">
                    <?= lucide_icon($clicksDelta >= 0 ? 'trending-up' : 'trending-down', '', 13) ?><?= ($clicksDelta > 0 ? '+' : '') . $clicksDelta ?>%
                  </em>
                <?php endif; ?>
              </dd>
            </div>
            <div class="at-ledger-item" style="--l-c: var(--accent)">
              <dt><?= lucide_icon('star', '', 15) ?>Reputación</dt>
              <dd>
                <?= $ratingAvg !== null ? number_format($ratingAvg, 1, ',', '.') : '—' ?>
                <small><?= $fmt($overview['reviews']) ?> <?= $overview['reviews'] === 1 ? 'reseña' : 'reseñas' ?></small>
              </dd>
            </div>
            <div class="at-ledger-item" style="--l-c: var(--success)">
              <dt><?= lucide_icon('user-round-check', '', 15) ?>Con cuenta</dt>
              <dd><?= $fmt($overview['con_cuenta']) ?><small>de <?= $fmt($storeTotal) ?> tiendas</small></dd>
            </div>
          </dl>
        </div>

        <aside class="at-check" aria-labelledby="at-check-title">
          <div class="at-check-head">
            <div>
              <h2 id="at-check-title" class="at-check-title">Perfiles completos</h2>
              <p class="at-check-caption"><?= $fmt($overview['datos']) ?> de <?= $fmt($fieldTotal) ?> datos cargados</p>
            </div>
            <p class="at-check-value"><?= $fmt($overview['completas']) ?><small>/<?= $fmt($storeTotal) ?></small></p>
          </div>

          <div class="at-check-meter" role="meter" aria-label="Datos de perfil cargados" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $profilePct ?>">
            <span style="width: <?= $profilePct ?>%"></span>
          </div>

          <ul class="at-fields">
            <?php foreach (AT_CAMPOS as $key => $field): ?>
              <?php $missing = $gaps[$key]; $isActive = $falta === $key; ?>
              <li>
                <?php if ($missing === 0): ?>
                  <span class="at-field is-done">
                    <span class="at-field-icon" aria-hidden="true"><?= lucide_icon($field['icon'], '', 15) ?></span>
                    <span class="at-field-name"><?= e($field['label']) ?></span>
                    <span class="at-field-count"><?= lucide_icon('check', '', 12) ?>Completo</span>
                  </span>
                <?php else: ?>
                  <a
                    href="<?= e(at_url($state, ['falta' => $isActive ? '' : $key])) ?>"
                    class="at-field <?= $isActive ? 'is-active' : '' ?>"
                    title="<?= $isActive ? 'Quitar filtro' : 'Ver tiendas sin ' . e(mb_strtolower($field['label'])) ?>"
                    <?= $isActive ? 'aria-current="true"' : '' ?>
                  >
                    <span class="at-field-icon" aria-hidden="true"><?= lucide_icon($field['icon'], '', 15) ?></span>
                    <span class="at-field-name"><?= e($field['label']) ?></span>
                    <span class="at-field-count">Falta en <?= $fmt($missing) ?></span>
                    <?= lucide_icon($isActive ? 'x' : 'arrow-right', 'at-field-arrow', 14) ?>
                  </a>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </aside>
      </header>

      <section class="dash-panel at-list animate-reveal-up" aria-labelledby="at-list-title" style="--lane-accent: var(--brand)">
        <header class="dash-panel-head">
          <span class="dash-panel-icon" aria-hidden="true"><?= lucide_icon('layout-grid', '', 18) ?></span>
          <div>
            <h2 id="at-list-title" class="dash-panel-title">Fichas de tienda</h2>
            <p class="dash-panel-caption">
              <?php if ($stores): ?>
                <?= $fmt(count($stores)) ?> de <?= $fmt($storeTotal) ?> · ordenadas por <?= e(mb_strtolower(AT_ORDENES[$orden]['label'])) ?>
              <?php else: ?>
                Ninguna tienda coincide con la búsqueda
              <?php endif; ?>
            </p>
          </div>

          <form method="get" class="at-search" role="search">
            <?php if ($falta !== ''): ?><input type="hidden" name="falta" value="<?= e($falta) ?>"><?php endif; ?>
            <?php if ($orden !== 'nombre'): ?><input type="hidden" name="orden" value="<?= e($orden) ?>"><?php endif; ?>
            <?= lucide_icon('search', 'at-search-icon', 16) ?>
            <label for="at-q" class="visually-hidden">Buscar tiendas</label>
            <input type="search" id="at-q" name="q" class="at-search-input" placeholder="Nombre, contacto, correo…" value="<?= e($q) ?>" autocomplete="off">
            <?php if ($q !== ''): ?>
              <a href="<?= e(at_url($state, ['q' => ''])) ?>" class="at-search-clear" aria-label="Borrar búsqueda" title="Borrar búsqueda"><?= lucide_icon('x', '', 14) ?></a>
            <?php endif; ?>
            <button type="submit" class="at-search-go" aria-label="Buscar"><?= lucide_icon('arrow-right', '', 15) ?></button>
          </form>
        </header>

        <div class="at-bar">
          <nav class="at-sort" aria-label="Ordenar tiendas">
            <?php foreach (AT_ORDENES as $key => $option): ?>
              <a
                href="<?= e(at_url($state, ['orden' => $key])) ?>"
                class="at-sort-item <?= $orden === $key ? 'is-active' : '' ?>"
                <?= $orden === $key ? 'aria-current="true"' : '' ?>
              >
                <?= lucide_icon($option['icon'], '', 14) ?><?= e($option['label']) ?>
              </a>
            <?php endforeach; ?>
          </nav>

          <?php if ($falta !== ''): ?>
            <a href="<?= e(at_url($state, ['falta' => ''])) ?>" class="at-chip" title="Quitar filtro">
              <?= lucide_icon(AT_CAMPOS[$falta]['icon'], '', 13) ?>
              Sin <?= e(mb_strtolower(AT_CAMPOS[$falta]['label'])) ?>
              <?= lucide_icon('x', '', 13) ?>
            </a>
          <?php endif; ?>
        </div>

        <?php if ($stores): ?>
          <ul class="at-grid">
            <?php foreach ($stores as $store): ?>
              <?php
                $storeId = (int) $store['idtiendas'];
                $status = $store['status'];
                $filled = count(AT_CAMPOS) - count($store['faltan']);
                $logo = trim((string) ($store['tie_logo'] ?? ''));
                $web = trim((string) ($store['tie_url'] ?? ''));
                $phone = trim((string) ($store['tie_telefono'] ?? ''));
                $mail = trim((string) ($store['tie_email'] ?? ''));
                $place = trim((string) ($store['tie_ubicacion'] ?? ''));
                $webHref = $web !== '' ? (preg_match('~^https?://~i', $web) ? $web : 'https://' . $web) : '';
                $webHost = $webHref !== '' ? preg_replace('/^www\./i', '', (string) parse_url($webHref, PHP_URL_HOST)) : '';
                $contacts = [
                    ['icon' => 'phone', 'value' => $phone, 'href' => $phone !== '' ? 'tel:' . preg_replace('/[^\d+]/', '', $phone) : '', 'missing' => 'Sin teléfono', 'external' => false],
                    ['icon' => 'mail', 'value' => $mail, 'href' => $mail !== '' ? 'mailto:' . $mail : '', 'missing' => 'Sin correo', 'external' => false],
                    ['icon' => 'globe', 'value' => $webHost ?: $web, 'href' => $webHref, 'missing' => 'Sin sitio web', 'external' => true],
                    ['icon' => 'map-pin', 'value' => 'Ver en el mapa', 'href' => $place !== '' ? 'https://www.google.com/maps?q=' . rawurlencode($place) : '', 'missing' => 'Sin ubicación', 'external' => true],
                ];
                $words = preg_split('/\s+/u', trim($store['tie_nombre'])) ?: [];
                $initials = mb_strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
              ?>
              <li class="at-store">
                <div class="at-store-head">
                  <span class="at-logo <?= $logo === '' ? 'is-empty' : '' ?>">
                    <?php if ($logo !== ''): ?>
                      <img src="<?= e($logo) ?>" alt="" loading="lazy" decoding="async">
                    <?php else: ?>
                      <span class="at-logo-initials"><?= e($initials) ?></span>
                    <?php endif; ?>
                  </span>
                  <div class="at-store-id">
                    <h3 class="at-store-name" title="<?= e($store['tie_nombre']) ?>"><?= e($store['tie_nombre']) ?></h3>
                    <p class="at-store-status">
                      <span class="at-pill <?= $status['tone'] ?>"><span class="dash-dot" aria-hidden="true"></span><?= e($status['state']) ?></span>
                      <?php if ($status['detail'] !== ''): ?><span><?= e($status['detail']) ?></span><?php endif; ?>
                    </p>
                  </div>
                  <span
                    class="at-profile <?= $store['faltan'] ? 'is-gap' : '' ?>"
                    title="<?= $store['faltan'] ? 'Falta: ' . e(implode(', ', array_map(static fn($k) => mb_strtolower(AT_CAMPOS[$k]['label']), $store['faltan']))) : 'Perfil completo' ?>"
                  >
                    <?= lucide_icon($store['faltan'] ? 'circle-alert' : 'circle-check', '', 13) ?>Perfil <?= $filled ?>/<?= count(AT_CAMPOS) ?>
                  </span>
                </div>

                <dl class="at-store-stats">
                  <div>
                    <dt>Productos</dt>
                    <dd><?= $fmt((int) $store['publicados']) ?></dd>
                  </div>
                  <div>
                    <dt>Clics (30 días)</dt>
                    <dd><?= $fmt($store['clics']) ?></dd>
                  </div>
                  <div>
                    <dt>Valoración</dt>
                    <dd>
                      <?php if ($store['rating'] !== null): ?>
                        <?= number_format($store['rating'], 1, ',', '.') ?><small>/5</small>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </dd>
                  </div>
                </dl>

                <ul class="at-contact">
                  <li>
                    <?= lucide_icon('clock', '', 15) ?>
                    <?php if ($status['today'] !== null): ?>
                      <span><span class="at-contact-key">Hoy</span> <?= e($status['today']) ?></span>
                    <?php else: ?>
                      <span class="is-missing">Sin horario</span>
                    <?php endif; ?>
                  </li>
                  <?php foreach ($contacts as $contact): ?>
                    <li>
                      <?= lucide_icon($contact['icon'], '', 15) ?>
                      <?php if ($contact['href'] !== ''): ?>
                        <a href="<?= e($contact['href']) ?>" <?= $contact['external'] ? 'target="_blank" rel="noopener"' : '' ?>><?= e($contact['value']) ?></a>
                      <?php else: ?>
                        <span class="is-missing"><?= e($contact['missing']) ?></span>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>

                <footer class="at-store-foot">
                  <a href="tienda.php?id=<?= $storeId ?>" class="at-view" target="_blank" rel="noopener">
                    <?= lucide_icon('external-link', '', 15) ?>Ver página
                  </a>
                  <button type="button" class="at-edit" data-bs-toggle="modal" data-bs-target="#storeModal<?= $storeId ?>">
                    <?= lucide_icon('square-pen', '', 15) ?>Editar tienda
                  </button>
                </footer>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="at-empty">
            <span class="at-empty-icon" aria-hidden="true"><?= lucide_icon('store', '', 26) ?></span>
            <p class="at-empty-title">No hay tiendas para mostrar</p>
            <p class="at-empty-text">Prueba con otro término o quita los filtros activos.</p>
            <a href="admin_tiendas.php" class="at-empty-action"><?= lucide_icon('rotate-ccw', '', 14) ?>Ver todas las tiendas</a>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>
</section>

<?php if ($stores): ?>
  <?php foreach ($stores as $store): ?>
    <?php
      $mId = (int) $store['idtiendas'];
      $fid = 'ts' . $mId;
      $mName = (string) $store['tie_nombre'];
      $mLogo = trim((string) ($store['tie_logo'] ?? ''));
      $mPlace = trim((string) ($store['tie_ubicacion'] ?? ''));
      $mStatus = $store['status'];
      $mFilled = count(AT_CAMPOS) - count($store['faltan']);
      $mWords = preg_split('/\s+/u', trim($mName)) ?: [];
      $mInitials = mb_strtoupper(implode('', array_map(static fn($w) => mb_substr($w, 0, 1), array_slice($mWords, 0, 2))));
    ?>
    <div class="modal fade admin-modal pe-modal ts-modal" id="storeModal<?= $mId ?>" tabindex="-1" aria-labelledby="<?= $fid ?>-title" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="post" class="modal-content pe-content" data-ts-form>
          <input type="hidden" name="action" value="save_store">
          <input type="hidden" name="idtiendas" value="<?= $mId ?>">

          <div class="modal-header pe-header">
            <div class="pe-heading">
              <span class="pe-heading-icon" aria-hidden="true"><?= lucide_icon('store', '', 18) ?></span>
              <div class="pe-heading-text">
                <span class="pe-kicker">Editar tienda <span class="pe-kicker-id">#<?= $mId ?></span></span>
                <h2 class="modal-title pe-title" id="<?= $fid ?>-title" title="<?= e($mName) ?>"><?= e($mName) ?></h2>
              </div>
            </div>
            <button type="button" class="pe-close" data-bs-dismiss="modal" aria-label="Cerrar"><?= lucide_icon('x', '', 18) ?></button>
          </div>

          <div class="modal-body pe-body">
            <div class="pe-layout">
              <div class="pe-main">
                <section class="pe-group is-info">
                  <header class="pe-group-head">
                    <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('id-card', '', 14) ?></span>
                    <div class="pe-group-copy">
                      <h3 class="pe-group-title">Identidad</h3>
                      <p class="pe-group-hint">Cómo se presenta y dónde está el local.</p>
                    </div>
                  </header>
                  <div class="pe-field">
                    <label class="form-label" for="<?= $fid ?>-nombre">Nombre <span class="pe-req" aria-hidden="true">*</span></label>
                    <input type="text" id="<?= $fid ?>-nombre" name="tie_nombre" class="form-control" value="<?= e($mName) ?>" required data-ts-sync="name">
                  </div>
                  <div class="pe-field">
                    <label class="form-label" for="<?= $fid ?>-ubic">Ubicación</label>
                    <div class="pe-affix">
                      <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('map-pin', '', 15) ?></span>
                      <input type="text" id="<?= $fid ?>-ubic" name="tie_ubicacion" class="form-control" value="<?= e($mPlace) ?>" placeholder="Calle, número y ciudad" data-ts-sync="place" data-ts-field>
                    </div>
                  </div>
                  <div class="pe-field">
                    <label class="form-label" for="<?= $fid ?>-logo">URL del logo</label>
                    <div class="pe-affix">
                      <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('image', '', 15) ?></span>
                      <input type="text" id="<?= $fid ?>-logo" name="tie_logo" class="form-control" value="<?= e($mLogo) ?>" placeholder="https://…/logo.png" inputmode="url" data-ts-sync="logo" data-ts-field>
                    </div>
                  </div>
                  <div class="pe-field is-grow">
                    <label class="form-label" for="<?= $fid ?>-desc">Descripción</label>
                    <textarea id="<?= $fid ?>-desc" name="tie_descripcion" class="form-control" rows="3" placeholder="Qué vende, desde cuándo, qué la distingue…"><?= e($store['tie_descripcion']) ?></textarea>
                  </div>
                </section>

                <div class="pe-stack">
                  <section class="pe-group is-contact">
                    <header class="pe-group-head">
                      <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('contact-round', '', 14) ?></span>
                      <div class="pe-group-copy">
                        <h3 class="pe-group-title">Contacto</h3>
                        <p class="pe-group-hint">Canales para que el comprador consulte.</p>
                      </div>
                    </header>
                    <div class="pe-row">
                      <div class="pe-field">
                        <label class="form-label" for="<?= $fid ?>-contacto">Persona de contacto</label>
                        <input type="text" id="<?= $fid ?>-contacto" name="tie_contacto" class="form-control" value="<?= e($store['tie_contacto'] ?? '') ?>" placeholder="Nombre y apellido">
                      </div>
                      <div class="pe-field">
                        <label class="form-label" for="<?= $fid ?>-tel">Teléfono</label>
                        <div class="pe-affix">
                          <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('phone', '', 15) ?></span>
                          <input type="tel" id="<?= $fid ?>-tel" name="tie_telefono" class="form-control" value="<?= e($store['tie_telefono'] ?? '') ?>" placeholder="0981 123 456" data-ts-field>
                        </div>
                      </div>
                    </div>
                    <div class="pe-row">
                      <div class="pe-field">
                        <label class="form-label" for="<?= $fid ?>-mail">Correo</label>
                        <div class="pe-affix">
                          <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('mail', '', 15) ?></span>
                          <input type="email" id="<?= $fid ?>-mail" name="tie_email" class="form-control" value="<?= e($store['tie_email'] ?? '') ?>" placeholder="ventas@tienda.com" data-ts-field>
                        </div>
                      </div>
                      <div class="pe-field">
                        <label class="form-label" for="<?= $fid ?>-web">Sitio web</label>
                        <div class="pe-affix">
                          <span class="pe-affix-tag" aria-hidden="true"><?= lucide_icon('globe', '', 15) ?></span>
                          <input type="text" id="<?= $fid ?>-web" name="tie_url" class="form-control" value="<?= e($store['tie_url']) ?>" placeholder="tienda.com.py" inputmode="url" data-ts-field>
                        </div>
                      </div>
                    </div>
                  </section>

                  <section class="pe-group is-hours">
                    <header class="pe-group-head">
                      <span class="pe-group-icon" aria-hidden="true"><?= lucide_icon('clock', '', 14) ?></span>
                      <div class="pe-group-copy">
                        <h3 class="pe-group-title">Horarios de atención</h3>
                        <p class="pe-group-hint">Un día por línea; “Abierta/Cerrada” se calcula solo.</p>
                      </div>
                    </header>
                    <div class="pe-field is-grow">
                      <label class="form-label" for="<?= $fid ?>-horas">Horarios</label>
                      <textarea id="<?= $fid ?>-horas" name="tie_horarios" class="form-control" rows="3" placeholder="Lunes 8:00 a.m. - 6:00 p.m.&#10;Sábado 8:00 a.m. - 12:00 p.m.&#10;Domingo Cerrado" data-ts-sync="hours" data-ts-field><?= e($store['tie_horarios'] ?? '') ?></textarea>
                    </div>
                  </section>
                </div>
              </div>

              <aside class="pe-aside">
                <span class="pe-aside-label">Vista previa</span>

                <div class="pe-preview-media ts-logo <?= $mLogo === '' ? 'is-empty' : '' ?>" data-ts-media>
                  <img src="<?= e($mLogo) ?>" alt="" data-ts-img>
                  <span class="pe-preview-empty" aria-hidden="true">
                    <span class="ts-initials" data-ts-out="initials"><?= e($mInitials) ?></span>
                    <span>Sin logo</span>
                  </span>
                </div>

                <div class="pe-preview-body">
                  <strong class="pe-preview-name" data-ts-out="name"><?= e($mName) ?></strong>
                  <span class="ts-place <?= $mPlace === '' ? 'is-missing' : '' ?>" data-ts-out="place"><?= lucide_icon('map-pin', '', 13) ?><span><?= e($mPlace !== '' ? $mPlace : 'Sin ubicación') ?></span></span>
                  <span class="ts-status" aria-live="polite">
                    <span class="at-pill <?= $mStatus['tone'] ?>" data-ts-out="pill"><span class="dash-dot" aria-hidden="true"></span><span data-ts-out="state"><?= e($mStatus['state']) ?></span></span>
                    <span data-ts-out="detail" <?= $mStatus['detail'] === '' ? 'hidden' : '' ?>><?= e($mStatus['detail']) ?></span>
                  </span>
                </div>

                <dl class="ts-stats">
                  <div>
                    <dt>Productos</dt>
                    <dd><?= $fmt((int) $store['publicados']) ?></dd>
                  </div>
                  <div>
                    <dt>Reseñas</dt>
                    <dd><?= $fmt($store['reviews']) ?></dd>
                  </div>
                  <div>
                    <dt>Puntaje</dt>
                    <dd><?= $store['rating'] !== null ? number_format($store['rating'], 1, ',', '.') . '<small>/5</small>' : '—' ?></dd>
                  </div>
                </dl>

                <div class="ts-profile" data-ts-profile>
                  <span class="ts-profile-head">
                    <span>Perfil</span>
                    <strong><span data-ts-out="filled"><?= $mFilled ?></span>/<?= count(AT_CAMPOS) ?></strong>
                  </span>
                  <span class="ts-profile-meter" aria-hidden="true"><span data-ts-out="meter" style="width: <?= round($mFilled / count(AT_CAMPOS) * 100) ?>%"></span></span>
                </div>

                <a href="tienda.php?id=<?= $mId ?>" class="pe-aside-link" target="_blank" rel="noopener">
                  <?= lucide_icon('eye', '', 15) ?><span>Ver página pública</span><?= lucide_icon('arrow-up-right', 'pe-aside-link-arrow', 14) ?>
                </a>
              </aside>
            </div>
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
<?php endif; ?>

<script>
// Réplica de at_parse_horarios() y at_store_status() para recalcular el estado mientras se edita
const tsHours = (function() {
  const DAYS = { lunes: 1, martes: 2, miercoles: 3, jueves: 4, viernes: 5, sabado: 6, domingo: 7 };
  const SHORT = { 1: 'lun', 2: 'mar', 3: 'mié', 4: 'jue', 5: 'vie', 6: 'sáb', 7: 'dom' };
  const hm = function(m) { return Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0'); };
  const toMinutes = function(hour, minute, meridiem) {
    if (meridiem === 'p' && hour < 12) hour += 12;
    else if (meridiem === 'a' && hour === 12) hour = 0;
    return hour * 60 + minute;
  };

  const parse = function(text) {
    const days = {};
    text.replace(/[\u202F\u00A0]/g, ' ').split(/\r?\n/).forEach(function(line) {
      const plain = line.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      const m = plain.match(/^(lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b(.*)$/);
      if (!m) return;
      const ranges = [];
      const re = /(\d{1,2})(?::(\d{2}))?\s*([ap])?\.?\s*m?\.?\s*[-–]\s*(\d{1,2})(?::(\d{2}))?\s*([ap])?/g;
      let r;
      while ((r = re.exec(m[2])) !== null) {
        const endMer = r[6] || '';
        const end = toMinutes(+r[4], +(r[5] || 0), endMer);
        const startMer = r[3] || '';
        let start = toMinutes(+r[1], +(r[2] || 0), startMer || endMer);
        if (startMer === '' && start > end) start = toMinutes(+r[1], +(r[2] || 0), 'a');
        if (end > start) ranges.push([start, end]);
      }
      days[DAYS[m[1]]] = ranges;
    });
    return days;
  };

  const status = function(days) {
    if (Object.keys(days).length === 0) return { tone: 'is-off', state: 'Sin horario', detail: '' };
    const date = new Date();
    const dow = date.getDay() || 7;
    const now = date.getHours() * 60 + date.getMinutes();
    const today = days[dow] || [];
    for (const [start, end] of today) {
      if (now >= start && now < end) return { tone: 'is-good', state: 'Abierta', detail: 'hasta las ' + hm(end) };
    }
    for (const [start] of today) {
      if (start > now) return { tone: 'is-mid', state: 'Cerrada', detail: 'abre hoy ' + hm(start) };
    }
    for (let i = 1; i <= 7; i++) {
      const day = (dow - 1 + i) % 7 + 1;
      if (days[day] && days[day].length) {
        const when = i === 1 ? 'mañana' : 'el ' + SHORT[day];
        return { tone: 'is-low', state: 'Cerrada', detail: 'abre ' + when + ' ' + hm(days[day][0][0]) };
      }
    }
    return { tone: 'is-low', state: 'Cerrada', detail: '' };
  };

  return function(text) { return status(parse(text)); };
})();

document.querySelectorAll('[data-ts-form]').forEach(function(form) {
  const field = function(key) { return form.querySelector('[data-ts-sync="' + key + '"]'); };
  const out = function(key) { return form.querySelector('[data-ts-out="' + key + '"]'); };
  const media = form.querySelector('[data-ts-media]');
  const img = form.querySelector('[data-ts-img]');
  const tracked = form.querySelectorAll('[data-ts-field]');
  const profile = form.querySelector('[data-ts-profile]');

  img.addEventListener('load', function() { media.classList.remove('is-empty'); });
  img.addEventListener('error', function() { media.classList.add('is-empty'); });
  if (img.getAttribute('src') && img.complete && img.naturalWidth === 0) {
    media.classList.add('is-empty');
  }

  const syncLogo = function() {
    const value = field('logo').value.trim();
    if (value === '' || value.startsWith('data:image')) {
      img.removeAttribute('src');
      media.classList.add('is-empty');
    } else if (img.getAttribute('src') !== value) {
      img.src = value;
    }
  };

  const syncName = function() {
    const name = field('name').value.trim();
    out('name').textContent = name || 'Sin nombre';
    out('initials').textContent = name.split(/\s+/).slice(0, 2).map(function(w) { return w.charAt(0); }).join('').toUpperCase() || '?';
  };

  const syncPlace = function() {
    const place = field('place').value.trim();
    out('place').lastElementChild.textContent = place || 'Sin ubicación';
    out('place').classList.toggle('is-missing', place === '');
  };

  const syncProfile = function() {
    let filled = 0;
    tracked.forEach(function(input) { if (input.value.trim() !== '') filled++; });
    out('filled').textContent = filled;
    out('meter').style.width = Math.round(filled / tracked.length * 100) + '%';
    profile.classList.toggle('is-complete', filled === tracked.length);
  };

  const syncHours = function() {
    const result = tsHours(field('hours').value);
    out('pill').className = 'at-pill ' + result.tone;
    out('state').textContent = result.state;
    out('detail').textContent = result.detail;
    out('detail').hidden = result.detail === '';
  };

  let logoTimer;
  field('logo').addEventListener('input', function() {
    clearTimeout(logoTimer);
    logoTimer = setTimeout(syncLogo, 300);
  });
  field('name').addEventListener('input', syncName);
  field('place').addEventListener('input', syncPlace);
  field('hours').addEventListener('input', syncHours);

  form.closest('.modal').addEventListener('hidden.bs.modal', function() {
    clearTimeout(logoTimer);
    form.reset();
    syncLogo();
    syncName();
    syncPlace();
    syncHours();
    syncProfile();
  });
  tracked.forEach(function(input) { input.addEventListener('input', syncProfile); });
  syncProfile();
});
</script>

<?php render_footer(); ?>
