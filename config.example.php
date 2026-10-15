<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');
// Paraguay usa UTC-3 fijo desde 2024; el tzdata de PHP aún aplica UTC-4 en invierno a America/Asuncion.
date_default_timezone_set('Etc/GMT+3');

const DB_HOST = 'localhost';
const DB_NAME = 'caacuprecio';
const DB_USER = 'user';
const DB_PASS = 'pass';
const APP_NAME = 'Caacuprecio';
const DEFAULT_THEME = 'dark';
const APP_URL = 'https://caacuprecio.com';

const RESEND_API_KEY = 're';
const RESEND_FROM = 'Correo no reply';

// Claves de prueba de Cloudflare (siempre aprueban). Reemplazar por las reales en producción.
const TURNSTILE_SITE_KEY = '1x00000000000000000000AA';
const TURNSTILE_SECRET_KEY = '1x0000000000000000000000000000000AA';

// Datos del responsable del sitio (Ley 4868/2013 de Comercio Electrónico). Completar con los datos reales;
// los campos vacíos no se muestran al público y los administradores ven un aviso en las páginas legales.
const BUSINESS_LEGAL_NAME = '';
const BUSINESS_RUC = ''; // Opcional: solo si hay actividad económica registrada en la DNIT.
const BUSINESS_ADDRESS = '';
const BUSINESS_EMAIL = '';
const BUSINESS_PHONE = '';
// Ej.: 'Trabajo de investigación de grado (tesis) – Carrera, Universidad'
const BUSINESS_PROJECT = '';
const LEGAL_LAST_UPDATED = '2026-10-12';


function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function turnstile_enabled(): bool
{
    return TURNSTILE_SITE_KEY !== '' && TURNSTILE_SECRET_KEY !== '';
}

function turnstile_widget(): string
{
    static $scriptsPrinted = false;

    if (!turnstile_enabled()) {
        return '';
    }

    $html = '';
    if (!$scriptsPrinted) {
        $scriptsPrinted = true;
        $html .= '<script src="./js/turnstile.js?v=' . filemtime(__DIR__ . '/js/turnstile.js') . '"></script>';
        $html .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=caacuprecioTurnstileReady" async defer></script>';
    }

    return $html . '<div class="cf-turnstile-slot" data-turnstile data-sitekey="' . e(TURNSTILE_SITE_KEY) . '"></div>';
}

/**
 * Devuelve '' si la verificación es válida o el mensaje de error a mostrar.
 */
function turnstile_error(): string
{
    if (!turnstile_enabled()) {
        return '';
    }

    $token = trim((string) ($_POST['cf-turnstile-response'] ?? ''));
    if ($token === '') {
        return 'Completa la verificación de seguridad antes de enviar.';
    }

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => TURNSTILE_SECRET_KEY,
            'response' => $token,
            'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]),
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = is_string($response) ? json_decode($response, true) : null;
    if (is_array($data) && !empty($data['success'])) {
        return '';
    }

    error_log('Turnstile rechazó la verificación: ' . ($response ?: $curlError));
    return 'No pudimos confirmar la verificación de seguridad. Intenta nuevamente.';
}

const CONSENT_FIELD = 'acepto_privacidad';

/**
 * Casilla obligatoria de aceptación de la política de privacidad (y opcionalmente de los términos).
 */
function consent_field(string $id, bool $withTerms = false): string
{
    $newTab = '<span class="visually-hidden"> (se abre en una pestaña nueva)</span>';
    $privacy = '<a href="privacidad.php" target="_blank" rel="noopener">Política de privacidad' . $newTab . '</a>';
    $text = $withTerms
        ? 'Leí y acepto los <a href="terminos.php" target="_blank" rel="noopener">Términos y condiciones' . $newTab . '</a> y la ' . $privacy . '.'
        : 'Acepto que Caacuprecio trate los datos de este formulario según la ' . $privacy . '.';

    return '<div class="form-check consent-check">'
        . '<input class="form-check-input" type="checkbox" name="' . CONSENT_FIELD . '" id="' . e($id) . '" value="1" required>'
        . '<label class="form-check-label" for="' . e($id) . '">' . $text . '</label>'
        . '</div>';
}

function consent_accepted(): bool
{
    return (string) ($_POST[CONSENT_FIELD] ?? '') === '1';
}

function consent_error(): string
{
    return consent_accepted() ? '' : 'Debes aceptar la Política de privacidad para enviar el formulario.';
}

/**
 * Normaliza un destino de redirección a una ruta relativa del propio sitio.
 */
function safe_redirect_path(string $target, string $fallback = 'index.php'): string
{
    $target = trim($target);
    if ($target === '' || preg_match('/[\r\n\\\\]/', $target) || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) || str_starts_with($target, '//')) {
        return $fallback;
    }

    return $target;
}

function gs(null|float|int|string $value): string
{
    if ($value === null || $value === '') {
        return 'Consultar';
    }

    return 'Gs. ' . number_format((float) $value, 0, ',', '.');
}

/** Precio de un producto: las tiendas que no publican precio quedan guardadas en 0. */
function gs_precio(null|float|int|string $value): string
{
    if ($value === null || $value === '' || (float) $value <= 0) {
        return 'Gs. —';
    }

    return gs($value);
}

function is_admin(): bool
{
    $user = current_user();
    return isset($user['usu_tipo']) && (int)$user['usu_tipo'] === 1;
}

function require_admin(): void
{
    if (!is_admin()) {
        header('Location: index.php');
        exit;
    }
}

// Verifica si el usuario actual tiene el rol de Empresa (tipo 2)
function is_empresa(): bool
{
    $user = current_user();
    return isset($user['usu_tipo']) && (int)$user['usu_tipo'] === 2;
}

// Bloquea el acceso si el usuario no es Administrador ni Empresa
function require_admin_or_empresa(): void
{
    if (!is_admin() && !is_empresa()) {
        header('Location: index.php');
        exit;
    }
}

function image_url(?string $url, string $name = 'Producto'): string
{
    $url = trim((string) $url);

    if ($url !== '' && !str_starts_with($url, 'data:image')) {
        return $url;
    }

    return '';
}

function stock_label(mixed $value): string
{
    return ((int) $value) === 1 ? 'En stock' : 'Consultar stock';
}

function stock_badge_class(mixed $value): string
{
    return ((int) $value) === 1 ? 'badge-stock-ok' : 'badge-stock-no';
}

function page_title(string $title = ''): string
{
    return $title !== '' ? $title . ' | ' . APP_NAME : APP_NAME;
}

function active_sort_options(): array
{
    return [
        'recientes' => 'Más recientes',
        'precio_asc' => 'Menor precio',
        'precio_desc' => 'Mayor precio',
        'nombre_asc' => 'Nombre A-Z',
    ];
}

function sort_sql(string $sort): string
{
    return match ($sort) {
        'precio_asc' => '(p.pro_precio IS NULL OR p.pro_precio <= 0), p.pro_precio ASC, p.idproductos DESC',
        'precio_desc' => '(p.pro_precio IS NULL OR p.pro_precio <= 0), p.pro_precio DESC, p.idproductos DESC',
        'nombre_asc' => '(p.pro_precio IS NULL OR p.pro_precio <= 0), p.pro_nombre ASC, p.idproductos DESC',
        default => '(p.pro_precio IS NULL OR p.pro_precio <= 0), p.pro_fecha_scraping DESC, p.idproductos DESC',
    };
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        $next = $_SERVER['REQUEST_URI'] ?? 'favoritos.php';
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }
}

function current_user_id(): int
{
    $user = current_user();
    return (int) ($user['idusuario'] ?? 0);
}

function normalize_search_term(string $term): string
{
    $term = trim(mb_strtolower($term, 'UTF-8'));
    $term = preg_replace('/\s+/', ' ', $term);
    return $term ?? '';
}

function save_search_term(string $term, ?int $userId = null): void
{
    $term = trim($term);
    if ($term === '' || mb_strlen($term) < 2) {
        return;
    }

    $normalized = normalize_search_term($term);
    if ($normalized === '') {
        return;
    }

    $pdo = db();

    if ($userId !== null && $userId > 0) {
        $select = $pdo->prepare('
            SELECT idbusqueda
            FROM busquedas
            WHERE bus_normalizado = :normalizado
              AND bus_usuario_id = :usuario
            LIMIT 1
        ');
        $select->execute([
            ':normalizado' => $normalized,
            ':usuario' => $userId,
        ]);

        $existingId = $select->fetchColumn();

        if ($existingId) {
            $update = $pdo->prepare('
                UPDATE busquedas
                SET bus_total = bus_total + 1,
                    bus_termino = :termino,
                    bus_ultima_fecha = NOW()
                WHERE idbusqueda = :id
            ');
            $update->execute([
                ':termino' => $term,
                ':id' => $existingId,
            ]);
            return;
        }

        $insert = $pdo->prepare('
            INSERT INTO busquedas (bus_termino, bus_normalizado, bus_total, bus_usuario_id, bus_ultima_fecha)
            VALUES (:termino, :normalizado, 1, :usuario, NOW())
        ');
        $insert->execute([
            ':termino' => $term,
            ':normalizado' => $normalized,
            ':usuario' => $userId,
        ]);
        return;
    }

    $select = $pdo->prepare('
        SELECT idbusqueda
        FROM busquedas
        WHERE bus_normalizado = :normalizado
          AND bus_usuario_id IS NULL
        LIMIT 1
    ');
    $select->execute([
        ':normalizado' => $normalized,
    ]);

    $existingId = $select->fetchColumn();

    if ($existingId) {
        $update = $pdo->prepare('
            UPDATE busquedas
            SET bus_total = bus_total + 1,
                bus_termino = :termino,
                bus_ultima_fecha = NOW()
            WHERE idbusqueda = :id
        ');
        $update->execute([
            ':termino' => $term,
            ':id' => $existingId,
        ]);
        return;
    }

    $insert = $pdo->prepare('
        INSERT INTO busquedas (bus_termino, bus_normalizado, bus_total, bus_usuario_id, bus_ultima_fecha)
        VALUES (:termino, :normalizado, 1, NULL, NOW())
    ');
    $insert->execute([
        ':termino' => $term,
        ':normalizado' => $normalized,
    ]);
}

function get_trending_searches(int $limit = 8): array
{
    $stmt = db()->prepare('
        SELECT bus_termino, SUM(bus_total) AS total
        FROM busquedas
        GROUP BY bus_normalizado, bus_termino
        ORDER BY total DESC, MAX(bus_ultima_fecha) DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_user_search_history(int $userId, int $limit = 6): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = db()->prepare('
        SELECT bus_termino, bus_ultima_fecha, bus_total
        FROM busquedas
        WHERE bus_usuario_id = :usuario
        ORDER BY bus_ultima_fecha DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':usuario', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function is_favorite_product(int $productId, ?int $userId = null): bool
{
    $uid = $userId ?: current_user_id();
    if ($uid <= 0 || $productId <= 0) {
        return false;
    }

    $stmt = db()->prepare('SELECT 1 FROM favoritos WHERE usuario_idusuario = :u AND productos_idproductos = :p LIMIT 1');
    $stmt->execute([
        ':u' => $uid,
        ':p' => $productId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function favorite_count(?int $userId = null): int
{
    $uid = $userId ?: current_user_id();
    if ($uid <= 0) {
        return 0;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM favoritos WHERE usuario_idusuario = :u');
    $stmt->execute([':u' => $uid]);
    return (int) $stmt->fetchColumn();
}

function favorite_toggle_url(int $productId, string $redirect = ''): string
{
    $query = ['product_id' => $productId];
    if ($redirect !== '') {
        $query['redirect'] = $redirect;
    }

    return 'favorito_toggle.php?' . http_build_query($query);
}

function lucide_icon(string $name, string $class = '', int $size = 18): string
{
    $classAttr = $class !== '' ? ' class="' . e($class) . '"' : '';

    return '<i data-lucide="' . e($name) . '"' . $classAttr . ' width="' . $size . '" height="' . $size . '" aria-hidden="true"></i>';
}

/**
 * Logos de marca (Lucide no incluye brand icons). Misma caja/estilo que lucide_icon.
 */
function brand_icon(string $name, string $class = '', int $size = 18): string
{
    $paths = [
        'whatsapp' => 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z',
        'facebook' => 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
        'x' => 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.991ZM17.61 20.644h2.039L6.486 3.24H4.298Z',
        'twitter' => 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.991ZM17.61 20.644h2.039L6.486 3.24H4.298Z',
    ];

    $key = strtolower(trim($name));
    if (!isset($paths[$key])) {
        return lucide_icon('share-2', $class, $size);
    }

    $classes = trim('brand-icon ' . $class);

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="currentColor" class="' . e($classes) . '" aria-hidden="true"><path d="' . $paths[$key] . '"/></svg>';
}

function category_lucide_icon(string $name, string $class = '', int $size = 22): string
{
    $key = mb_strtolower(trim($name), 'UTF-8');
    $icon = 'layout-grid';

    $keywords = [
        'celular' => 'smartphone',
        'smart' => 'smartphone',
        'tablet' => 'tablet',
        'cámara' => 'camera',
        'camara' => 'camera',
        'seguridad' => 'shield-check',
        'audio' => 'headphones',
        'tv' => 'tv',
        'video' => 'tv',
        'gaming' => 'gamepad-2',
        'bebé' => 'baby',
        'bebe' => 'baby',
        'juguete' => 'puzzle',
        'climat' => 'wind',
        'aire libre' => 'trees',
        'deporte' => 'dumbbell',
        'electro' => 'refrigerator',
        'máquina' => 'cog',
        'maquina' => 'cog',
        'herramient' => 'wrench',
        'informát' => 'laptop',
        'informat' => 'laptop',
        'moto' => 'bike',
        'mueble' => 'sofa',
        'hogar' => 'house',
        'oficina' => 'briefcase',
        'outlet' => 'tag',
        'red' => 'wifi',
        'conectiv' => 'wifi',
        'salud' => 'heart-pulse',
        'belleza' => 'sparkles',
        'tecno' => 'cpu',
        'accesorio' => 'watch',
        'producto' => 'package',
    ];

    foreach ($keywords as $needle => $value) {
        if (str_contains($key, $needle)) {
            $icon = $value;
            break;
        }
    }

    return lucide_icon($icon, $class, $size);
}

function render_head(string $title = ''): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<!DOCTYPE html>';
    echo '<html lang="es" data-bs-theme="' . DEFAULT_THEME . '">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e(page_title($title)) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<script src="https://cdn.jsdelivr.net/npm/lucide@0.511.0/dist/umd/lucide.min.js"></script>';
    echo '<script src="./js/icons.js"></script>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="icon" type="image/png" href="./img/icono.png">';
    echo '<link rel="stylesheet" href="./css/styles.css?v=' . filemtime(__DIR__ . '/css/styles.css') . '">';
    echo '<link rel="stylesheet" href="./css/auth.css?v=' . filemtime(__DIR__ . '/css/auth.css') . '">';
    echo '<link rel="stylesheet" href="./css/favoritos_extra.css">';

    echo '</head>';
    echo '<body class="theme-dark">';
}

function render_navbar(string $current = 'home'): void
{
    $homeActive = $current === 'home' ? 'active' : '';
    $storesActive = $current === 'tienda' ? 'active' : '';
    $productActive = $current === 'producto' ? 'active' : '';
    $favActive = $current === 'favoritos' ? 'active' : '';
    $user = current_user();
    $favCount = favorite_count();

    $userMenu = '';
    $mobileAccount = '';

    if ($user) {
        $userName = trim((string) ($user['usu_nombre'] ?? $user['nombre'] ?? '')) ?: 'Mi cuenta';
        $userEmail = trim((string) ($user['usu_email'] ?? ''));
        $userInitial = mb_strtoupper(mb_substr($userName, 0, 1, 'UTF-8'), 'UTF-8');
        $userRole = is_admin() ? 'Administrador' : (is_empresa() ? 'Empresa' : '');
        $canAdmin = is_admin() || is_empresa();
        $userActive = $current === 'usuario' ? ' active' : '';
        $adminActive = $current === 'admin' ? ' active' : '';

        $menuLink = static function (string $href, string $icon, string $label, string $class = '', string $trailing = ''): string {
            return '<li><a class="dropdown-item navbar-user-item' . $class . '" href="' . $href . '">'
                . '<span class="navbar-user-item-icon">' . lucide_icon($icon, '', 16) . '</span>'
                . '<span class="navbar-user-item-label">' . $label . '</span>'
                . $trailing
                . '</a></li>';
        };

        $menuItems = $menuLink('usuario.php', 'circle-user', 'Mi cuenta', $userActive);
        $menuItems .= $menuLink('favoritos.php', 'heart', 'Favoritos', $favActive ? ' active' : '', '<span class="navbar-user-count">' . $favCount . '</span>');
        if ($canAdmin) {
            $menuItems .= $menuLink('./admin.php', 'settings', 'Panel admin', $adminActive);
        }
        $menuFooter = $menuLink('logout.php', 'log-out', 'Cerrar sesión', ' navbar-user-item-danger');

        $userHead = '<span class="navbar-user-avatar" aria-hidden="true">' . e($userInitial) . '</span>'
            . '<span class="navbar-user-meta">'
            . '<span class="navbar-user-name">' . e($userName) . '</span>'
            . ($userEmail !== '' ? '<span class="navbar-user-email">' . e($userEmail) . '</span>' : '')
            . ($userRole !== '' ? '<span class="navbar-user-role">' . e($userRole) . '</span>' : '')
            . '</span>';

        $userMenu = '<div class="dropdown navbar-user d-none d-lg-block">'
            . '<button class="navbar-user-btn' . ($userActive || $adminActive ? ' is-active' : '') . '" type="button" data-bs-toggle="dropdown" data-bs-offset="0,10" aria-expanded="false" aria-label="Menú de cuenta">'
            . '<span class="navbar-user-avatar" aria-hidden="true">' . e($userInitial) . '</span>'
            . '<span class="navbar-user-btn-name">' . e($userName) . '</span>'
            . lucide_icon('chevron-down', 'navbar-user-chevron', 16)
            . '</button>'
            . '<div class="dropdown-menu dropdown-menu-end navbar-user-menu">'
            . '<div class="navbar-user-head">' . $userHead . '</div>'
            . '<ul class="navbar-user-list">' . $menuItems . '</ul>'
            . '<ul class="navbar-user-list navbar-user-footer">' . $menuFooter . '</ul>'
            . '</div>'
            . '</div>';

        $mobileAccount = '<div class="navbar-mobile-account d-lg-none">'
            . '<div class="navbar-user-head">' . $userHead . '</div>'
            . '<ul class="navbar-nav navbar-account">'
            . '<li class="nav-item"><a class="nav-link' . $userActive . '" href="usuario.php">' . lucide_icon('circle-user', 'me-2', 17) . 'Mi cuenta</a></li>'
            . ($canAdmin ? '<li class="nav-item"><a class="nav-link' . $adminActive . '" href="./admin.php">' . lucide_icon('settings', 'me-2', 17) . 'Panel admin</a></li>' : '')
            . '</ul>'
            . '<a class="navbar-cta-btn navbar-cta-btn-ghost" href="logout.php">' . lucide_icon('log-out', '', 17) . 'Cerrar sesión</a>'
            . '</div>';
    } else {
        $loginButton = '<a class="navbar-cta-btn" href="login.php">Ingresar</a>';
        $userMenu = '<div class="navbar-cta d-none d-lg-flex">' . $loginButton . '</div>';
        $mobileAccount = '<div class="navbar-cta d-lg-none">' . $loginButton . '</div>';
    }

    echo '<a class="visually-hidden-focusable skip-link" href="#contenido">Saltar al contenido</a>';
    echo '<nav class="navbar navbar-expand-lg sticky-top navbar-shell navbar-apple">';
    echo '  <div class="container navbar-inner">';
    echo '    <a class="navbar-brand d-flex align-items-center" href="index.php">';
    echo '      <img src="./img/logo_horizontal_dark.webp" data-theme-logo data-light-logo="./img/logo_horizontal.webp" data-dark-logo="./img/logo_horizontal_dark.webp" alt="Caacuprecio" class="site-navbar-logo" width="156" height="52">';
    echo '    </a>';

    echo '    <div class="collapse navbar-collapse" id="mainNav">';
    echo '      <ul class="navbar-nav navbar-links">';
    echo '        <li class="nav-item"><a class="nav-link ' . $homeActive . '" data-nav="home" href="index.php">Inicio</a></li>';
    echo '        <li class="nav-item"><a class="nav-link" data-nav="categorias" href="index.php#categorias">Categorías</a></li>';
    echo '        <li class="nav-item"><a class="nav-link ' . $productActive . '" data-nav="productos" href="index.php#productos">Productos</a></li>';
    echo '        <li class="nav-item"><a class="nav-link ' . $storesActive . '" data-nav="tiendas" href="index.php#tiendas">Tiendas</a></li>';
    if ($user) {
        echo '        <li class="nav-item"><a class="nav-link ' . $favActive . '" data-nav="favoritos" href="favoritos.php">Favoritos';
        echo ' <span class="badge rounded-pill navbar-fav-badge ms-1">' . $favCount . '</span>';
        echo '</a></li>';
    }
    echo '      </ul>';
    echo '      ' . $mobileAccount;
    echo '    </div>';

    echo '    <div class="navbar-toolbar">';
    echo '      ' . $userMenu;
    echo '      <button id="themeToggle" class="navbar-theme-btn navbar-theme-btn-icon d-none d-lg-inline-flex" type="button" aria-label="Cambiar tema" title="Cambiar tema">';
    echo '        <span data-lucide-host data-icon-dark="sun" data-icon-light="moon">' . lucide_icon('sun') . '</span>';
    echo '      </button>';
    echo '      <button id="themeToggleMobile" class="navbar-theme-btn navbar-theme-btn-icon d-lg-none" type="button" aria-label="Cambiar tema">';
    echo '        <span data-lucide-host data-icon-dark="sun" data-icon-light="moon">' . lucide_icon('contrast') . '</span>';
    echo '      </button>';
    echo '      <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Abrir menú">';
    echo '        <span class="navbar-toggler-icon"></span>';
    echo '      </button>';
    echo '    </div>';
    echo '  </div>';
    echo '</nav>';
    echo '<div id="contenido" tabindex="-1"></div>';
    echo '<script>';
    echo '(function(){';
    echo 'var page=' . json_encode($current) . ';';
    echo 'function links(){return document.querySelectorAll(".navbar-links .nav-link[data-nav]");}';
    echo 'function setActive(name){links().forEach(function(link){link.classList.toggle("active",link.getAttribute("data-nav")===name);});}';
    echo 'function sectionFromScroll(){var ids=["categorias","productos","tiendas"];var y=window.scrollY+100;var current="home";ids.forEach(function(id){var el=document.getElementById(id);if(el&&el.offsetTop<=y)current=id;});return current;}';
    echo 'function sync(){';
    echo 'if(page==="favoritos")return setActive("favoritos");';
    echo 'if(page==="usuario")return setActive("usuario");';
    echo 'if(page==="admin")return setActive("admin");';
    echo 'if(page==="producto")return setActive("productos");';
    echo 'if(page==="tienda")return setActive("tiendas");';
    echo 'if(page!=="home")return setActive("");';
    echo 'var hash=(location.hash||"").replace("#","");';
    echo 'if(hash==="categorias"||hash==="productos"||hash==="tiendas")return setActive(hash);';
    echo 'setActive(sectionFromScroll());';
    echo '}';
    echo 'document.addEventListener("click",function(event){var link=event.target.closest(".navbar-links .nav-link[data-nav]");if(!link)return;var name=link.getAttribute("data-nav");if(name==="home"||name==="categorias"||name==="productos"||name==="tiendas"){setActive(name);}});';
    echo 'window.addEventListener("hashchange",sync);';
    echo 'window.addEventListener("scroll",function(){if(page==="home")setActive(sectionFromScroll());},{passive:true});';
    echo 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",sync);}else{sync();}';
    echo 'window.addEventListener("load",sync);';
    echo '})();';
    echo '</script>';
}

function render_footer(): void
{
    $suggestionSaved = isset($_GET['suggestion_saved']) && $_GET['suggestion_saved'] === '1';
    $suggestionError = isset($_GET['suggestion_error']) ? trim((string) $_GET['suggestion_error']) : '';

    $user = current_user();
    $year = date('Y');

    echo '<footer class="footer site-footer">';
    echo '  <div class="container site-footer-inner">';
    echo '    <div class="site-footer-grid">';

    echo '      <div class="site-footer-brand">';
    echo '        <a class="site-footer-logo-link" href="index.php">';
    echo '          <img src="./img/logo_horizontal.webp" data-theme-logo data-light-logo="./img/logo_horizontal.webp" data-dark-logo="./img/logo_horizontal_dark.webp" alt="Caacuprecio" class="site-footer-logo" width="156" height="52">';
    echo '        </a>';
    echo '        <p class="site-footer-tagline">Compara precios entre los comercios de la ciudad y elige la mejor opción antes de salir.</p>';
    echo '        <p class="site-footer-place">';
    echo            lucide_icon('map-pin', 'site-footer-place-icon', 14);
    echo '          <span>Caacupé, Paraguay</span>';
    echo '        </p>';
    if (BUSINESS_EMAIL !== '') {
        echo '        <a class="site-footer-place site-footer-mail" href="mailto:' . e(BUSINESS_EMAIL) . '">';
        echo            lucide_icon('mail', 'site-footer-place-icon', 14);
        echo '          <span>' . e(BUSINESS_EMAIL) . '</span>';
        echo '        </a>';
    }
    echo '      </div>';

    echo '      <nav class="site-footer-col" aria-label="Explorar">';
    echo '        <h6 class="site-footer-heading">Explorar</h6>';
    echo '        <ul class="site-footer-links">';
    echo '          <li><a href="index.php#productos">Productos</a></li>';
    echo '          <li><a href="index.php#tiendas">Tiendas</a></li>';
    echo '          <li><a href="index.php#categorias">Categorías</a></li>';
    echo '          <li><a href="buscar.php">Buscar</a></li>';
    echo '        </ul>';
    echo '      </nav>';

    echo '      <nav class="site-footer-col" aria-label="Cuenta">';
    echo '        <h6 class="site-footer-heading">Cuenta</h6>';
    echo '        <ul class="site-footer-links">';
    if ($user) {
        echo '          <li><a href="usuario.php">Mi cuenta</a></li>';
        echo '          <li><a href="favoritos.php">Mis favoritos</a></li>';
        if (is_admin() || is_empresa()) {
            echo '          <li><a href="admin.php">Panel admin</a></li>';
        }
        echo '          <li><a href="logout.php">Cerrar sesión</a></li>';
    } else {
        echo '          <li><a href="login.php">Ingresar</a></li>';
        echo '          <li><a href="registro.php">Crear cuenta</a></li>';
        echo '          <li><a href="olvide_password.php">Recuperar clave</a></li>';
    }
    echo '        </ul>';
    echo '      </nav>';

    echo '      <nav class="site-footer-col" aria-label="Legal">';
    echo '        <h6 class="site-footer-heading">Legal</h6>';
    echo '        <ul class="site-footer-links">';
    echo '          <li><a href="terminos.php">Términos</a></li>';
    echo '          <li><a href="privacidad.php">Privacidad</a></li>';
    echo '          <li><a href="cookies.php">Cookies</a></li>';
    echo '          <li><a href="reembolsos.php">Reembolsos</a></li>';
    echo '          <li><a href="aviso_legal.php">Aviso legal</a></li>';
    echo '        </ul>';
    echo '      </nav>';

    try {
        $footerStores = db()->query('
            SELECT t.idtiendas, t.tie_nombre
            FROM tiendas t
            WHERE EXISTS (
                SELECT 1 FROM productos p
                WHERE p.tiendas_idtiendas = t.idtiendas AND p.pro_activo = 1
            )
            ORDER BY t.tie_nombre ASC
            LIMIT 5
        ')->fetchAll();
    } catch (Throwable $e) {
        $footerStores = [];
    }
    if ($footerStores) {
        echo '      <nav class="site-footer-col" aria-label="Tiendas">';
        echo '        <h6 class="site-footer-heading">Tiendas</h6>';
        echo '        <ul class="site-footer-links">';
        foreach ($footerStores as $store) {
            echo '          <li><a href="tienda.php?id=' . (int) $store['idtiendas'] . '">' . e($store['tie_nombre']) . '</a></li>';
        }
        echo '        </ul>';
        echo '      </nav>';
    }

    echo '    </div>';

    echo '    <p class="site-footer-note">' . e(APP_NAME) . ' no vende productos. Precios, stock e imágenes provienen de cada tienda, pueden estar desactualizados y no constituyen una oferta propia; confirma siempre antes de comprar. Las marcas pertenecen a sus respectivos titulares.</p>';

    echo '    <p class="site-footer-copy">© ' . e($year) . ' ' . e(APP_NAME) . '</p>';
    echo '  </div>';
    echo '</footer>';

    echo '<button type="button" class="suggestion-fab" data-bs-toggle="modal" data-bs-target="#suggestionModal" aria-label="Enviar sugerencia" aria-describedby="suggestionFabTip">';
    echo     lucide_icon('message-square', 'suggestion-fab-icon', 22);
    echo '  <span class="suggestion-fab-tip" id="suggestionFabTip" role="tooltip">';
    echo '    <strong>Enviar sugerencia</strong>';
    echo '    <span>¿Una idea, un error o una tienda que falta? Cuéntanos.</span>';
    echo '  </span>';
    echo '</button>';

    if ($suggestionSaved) {
        echo '<div class="toast-container position-fixed bottom-0 end-0 p-3 suggestion-toast" style="z-index: 1080;">';
        echo '  <div class="toast show align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">';
        echo '    <div class="d-flex">';
        echo '      <div class="toast-body">Tu sugerencia fue enviada correctamente.</div>';
        echo '      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';
    }

    $suggestionTopics = [
        ['Idea', 'sparkles'],
        ['Error', 'bug'],
        ['Nueva tienda', 'store'],
        ['Precio incorrecto', 'tag'],
        ['Diseño', 'palette'],
    ];

    echo '<div class="modal fade" id="suggestionModal" tabindex="-1" aria-labelledby="suggestionModalLabel" aria-hidden="true">';
    echo '  <div class="modal-dialog modal-dialog-centered sg-dialog">';
    echo '    <form method="post" action="guardar_sugerencia.php" class="modal-content suggestion-modal report-product-modal sg-modal">';
    echo '      <div class="modal-header">';
    echo '        <div class="report-product-heading">';
    echo '          <span class="report-product-icon sg-icon" aria-hidden="true">' . lucide_icon('lightbulb', '', 18) . '</span>';
    echo '          <div class="report-product-heading-text">';
    echo '            <p class="sg-kicker">Buzón de ideas</p>';
    echo '            <h5 class="modal-title" id="suggestionModalLabel">Enviar sugerencia</h5>';
    echo '            <p class="report-product-lead">Leemos cada mensaje para mejorar ' . e(APP_NAME) . '.</p>';
    echo '          </div>';
    echo '        </div>';
    echo '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>';
    echo '      </div>';

    echo '      <div class="modal-body">';

    if ($suggestionError !== '') {
        echo '        <div class="sg-alert" role="alert">' . lucide_icon('circle-alert', '', 16) . '<span>' . e($suggestionError) . '</span></div>';
    }

    echo '        <input type="hidden" name="redirect" value="' . e($_SERVER['REQUEST_URI'] ?? 'index.php') . '">';

    echo '        <div class="sg-field">';
    echo '          <label class="sg-label" for="sug_asunto">¿Sobre qué es?</label>';
    echo '          <div class="sg-chips" role="group" aria-label="Temas sugeridos">';
    foreach ($suggestionTopics as [$topicLabel, $topicIcon]) {
        echo '            <button type="button" class="sg-chip" data-sg-topic="' . e($topicLabel) . '" aria-pressed="false">' . lucide_icon($topicIcon, '', 14) . e($topicLabel) . '</button>';
    }
    echo '          </div>';
    echo '          <input type="text" id="sug_asunto" name="sug_asunto" class="form-control" maxlength="150" placeholder="Elige un tema o escribe el tuyo">';
    echo '        </div>';

    echo '        <div class="sg-field">';
    echo '          <label class="sg-label" for="sug_detalle">Tu sugerencia <span class="sg-req" aria-hidden="true">*</span></label>';
    echo '          <textarea id="sug_detalle" name="sug_detalle" class="form-control" rows="3" maxlength="2000" required aria-describedby="sug_detalle_hint" placeholder="Cuéntanos qué mejorarías, qué falló o qué tienda te gustaría ver…"></textarea>';
    echo '          <div class="sg-field-foot">';
    echo '            <span id="sug_detalle_hint">Mientras más detalles, mejor.</span>';
    echo '            <span class="sg-count" data-sg-count aria-live="polite">0 / 2000</span>';
    echo '          </div>';
    echo '        </div>';

    echo '        <fieldset class="sg-contact">';
    echo '          <legend class="sg-contact-title">' . lucide_icon('mail', '', 14) . '¿Quieres respuesta? <span class="sg-opt">Déjanos tus datos</span></legend>';
    echo '          <div class="report-product-row">';
    echo '            <div class="sg-field">';
    echo '              <label class="visually-hidden" for="sug_nombre">Tu nombre</label>';
    echo '              <input type="text" id="sug_nombre" name="sug_nombre" class="form-control" maxlength="120" autocomplete="name" placeholder="Tu nombre">';
    echo '            </div>';
    echo '            <div class="sg-field">';
    echo '              <label class="visually-hidden" for="sug_email">Correo electrónico</label>';
    echo '              <input type="email" id="sug_email" name="sug_email" class="form-control" maxlength="150" autocomplete="email" placeholder="tu@correo.com">';
    echo '            </div>';
    echo '          </div>';
    echo '        </fieldset>';

    echo '        ' . consent_field('sug_consent');

    $suggestionCaptcha = turnstile_widget();
    if ($suggestionCaptcha !== '') {
        echo '        <div class="sg-captcha">' . $suggestionCaptcha . '</div>';
    }
    echo '      </div>';

    echo '      <div class="modal-footer">';
    echo '        <p class="sg-foot-note">' . lucide_icon('lock', '', 13) . 'No publicamos tus datos.</p>';
    echo '        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>';
    echo '        <button type="submit" class="btn btn-primary">' . lucide_icon('send', '', 16) . 'Enviar</button>';
    echo '      </div>';
    echo '    </form>';
    echo '  </div>';
    echo '</div>';

    echo '<script>';
    echo '(function() {';
    echo '    document.addEventListener("show.bs.modal", function(event) {';
    echo '        if (event.target && event.target.id === "suggestionModal") {';
    echo '            document.body.appendChild(event.target);';
    echo '        }';
    echo '    });';
    echo '    const modal = document.getElementById("suggestionModal");';
    echo '    if (!modal) return;';
    echo '    const subject = modal.querySelector("#sug_asunto");';
    echo '    const detail = modal.querySelector("#sug_detalle");';
    echo '    const counter = modal.querySelector("[data-sg-count]");';
    echo '    const chips = modal.querySelectorAll("[data-sg-topic]");';
    echo '    const syncChips = function() {';
    echo '        chips.forEach(function(chip) { chip.setAttribute("aria-pressed", String(chip.dataset.sgTopic === subject.value.trim())); });';
    echo '    };';
    echo '    chips.forEach(function(chip) {';
    echo '        chip.addEventListener("click", function() {';
    echo '            subject.value = chip.getAttribute("aria-pressed") === "true" ? "" : chip.dataset.sgTopic;';
    echo '            syncChips();';
    echo '            detail.focus();';
    echo '        });';
    echo '    });';
    echo '    subject.addEventListener("input", syncChips);';
    echo '    const syncCount = function() {';
    echo '        counter.textContent = detail.value.length + " / " + detail.maxLength;';
    echo '        counter.classList.toggle("is-near", detail.value.length > detail.maxLength * 0.9);';
    echo '    };';
    echo '    detail.addEventListener("input", syncCount);';
    echo '})();';
    echo '</script>';

    echo '<script>';
    echo '(function(){';
    echo 'const root=document.documentElement;';
    echo 'const body=document.body;';
    echo 'const storageKey="caacuprecio-theme";';
    echo 'const toggleButtons=[document.getElementById("themeToggle"),document.getElementById("themeToggleMobile")].filter(Boolean);';
    echo 'function applyTheme(theme){const isDark=theme!=="light";root.setAttribute("data-bs-theme",isDark?"dark":"light");body.classList.toggle("theme-dark",isDark);body.classList.toggle("theme-light",!isDark);document.querySelectorAll("[data-theme-logo]").forEach((img)=>{const src=isDark?img.dataset.darkLogo:img.dataset.lightLogo;if(src&&img.getAttribute("src")!==src){img.setAttribute("src",src);}});toggleButtons.forEach((btn)=>{const host=btn.querySelector("[data-lucide-host]");const text=btn.querySelector("span:not([data-lucide-host])");if(host&&window.setLucideIcon){setLucideIcon(host,isDark?(host.dataset.iconDark||"sun"):(host.dataset.iconLight||"moon"));}if(text){text.textContent=isDark?"Modo claro":"Modo oscuro";}});if(window.refreshLucideIcons){refreshLucideIcons();}}';
    echo 'const savedTheme=localStorage.getItem(storageKey)||"dark";applyTheme(savedTheme);';
    echo 'toggleButtons.forEach((btn)=>btn.addEventListener("click",function(){const next=root.getAttribute("data-bs-theme")==="dark"?"light":"dark";localStorage.setItem(storageKey,next);applyTheme(next);}));';

    if ($suggestionError !== '') {
        echo 'const modalEl=document.getElementById("suggestionModal"); if(modalEl && window.bootstrap){ new bootstrap.Modal(modalEl).show(); }';
    }

    echo '})();';
    echo '</script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
}
