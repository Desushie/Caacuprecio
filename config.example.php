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
    $privacy = '<a href="privacidad.php" target="_blank" rel="noopener">política de privacidad</a>';
    $newTabNote = $withTerms
        ? '<span class="visually-hidden"> Los enlaces se abren en una pestaña nueva.</span>'
        : '<span class="visually-hidden"> Se abre en una pestaña nueva.</span>';
    $text = $withTerms
        ? 'Acepto los <a href="terminos.php" target="_blank" rel="noopener">términos</a> y la ' . $privacy . '.' . $newTabNote
        : 'Acepto que Caacuprecio trate los datos de este formulario según la ' . $privacy . '.' . $newTabNote;

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
        'recientes' => 'Recientes',
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
    // Tema antes del paint para evitar FOUC al cargar
    echo '<script>(function(){try{var t=localStorage.getItem("caacuprecio-theme")||"' . e(DEFAULT_THEME) . '";var d=t!=="light";document.documentElement.setAttribute("data-bs-theme",d?"dark":"light");document.documentElement.classList.add(d?"theme-dark":"theme-light");}catch(e){}})();</script>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<script src="https://cdn.jsdelivr.net/npm/lucide@0.511.0/dist/umd/lucide.min.js"></script>';
    echo '<script src="./js/icons.js?v=' . filemtime(__DIR__ . '/js/icons.js') . '"></script>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    echo '<link rel="icon" type="image/png" href="./img/icono.png">';
    echo '<link rel="stylesheet" href="./css/styles.css?v=' . filemtime(__DIR__ . '/css/styles.css') . '">';
    echo '<link rel="stylesheet" href="./css/auth.css?v=' . filemtime(__DIR__ . '/css/auth.css') . '">';
    echo '<link rel="stylesheet" href="./css/favoritos_extra.css">';

    echo '</head>';
    echo '<body class="theme-dark">';
    echo '<script>document.body.className=document.documentElement.classList.contains("theme-light")?"theme-light":"theme-dark";</script>';
}

function render_navbar(string $current = 'home'): void
{
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

        $mobileAccount = '<div class="navbar-mobile-account" role="navigation" aria-label="Menú de cuenta">'
            . '<div class="navbar-user-head">' . $userHead . '</div>'
            . '<ul class="navbar-user-list">' . $menuItems . '</ul>'
            . '<ul class="navbar-user-list navbar-user-footer">' . $menuFooter . '</ul>'
            . '</div>';
    } else {
        $userMenu = '<div class="navbar-cta"><a class="navbar-cta-btn" href="login.php">Ingresar</a></div>';
    }

    $hasSearch = should_render_global_search();
    $innerClass = 'container navbar-inner' . ($hasSearch ? ' has-search' : '') . ($mobileAccount !== '' ? ' has-account-menu' : '');

    echo '<a class="visually-hidden-focusable skip-link" href="#contenido">Saltar al contenido</a>';
    echo '<nav class="navbar sticky-top navbar-shell navbar-apple" aria-label="Principal">';
    echo '  <div class="' . $innerClass . '">';

    echo '    <a class="navbar-brand" href="index.php">';
    echo '      <img src="./img/logo_horizontal_dark.webp" data-theme-logo data-light-logo="./img/logo_horizontal.webp" data-dark-logo="./img/logo_horizontal_dark.webp" alt="Caacuprecio" class="site-navbar-logo" width="156" height="52">';
    echo '    </a>';

    if ($hasSearch) {
        echo '    <div class="navbar-search-host" id="navbar-search-host">';
        echo '      <div class="navbar-search" id="global-search-fixed" data-global-search>';
        render_global_search_dock();
        echo '      </div>';
        echo '    </div>';
    }

    echo '    <div class="navbar-toolbar">';
    echo '      ' . $userMenu;
    echo '      <button id="themeToggle" class="navbar-theme-btn navbar-theme-btn-icon" type="button" aria-label="Cambiar tema" title="Cambiar tema">';
    echo '        <span data-lucide-host data-icon-dark="sun" data-icon-light="moon">' . lucide_icon('sun') . '</span>';
    echo '      </button>';
    if ($mobileAccount !== '') {
        echo '      <button class="navbar-toggler border-0 shadow-none d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Abrir menú de cuenta">';
        echo '        <span class="navbar-toggler-icon"></span>';
        echo '      </button>';
    }
    echo '    </div>';

    if ($mobileAccount !== '') {
        echo '    <div class="collapse navbar-collapse navbar-mobile-panel d-lg-none" id="mainNav">';
        echo '      ' . $mobileAccount;
        echo '    </div>';
    }

    echo '  </div>';
    echo '</nav>';
    if ($mobileAccount !== '') {
        echo '<div class="navbar-menu-backdrop" id="navbarMenuBackdrop" aria-hidden="true"></div>';
    }
    echo '<div id="contenido" tabindex="-1"></div>';
}

function should_render_global_search(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') {
        return false;
    }

    if (str_starts_with($script, 'admin')) {
        return false;
    }

    return !in_array($script, [
        'login.php',
        'registro.php',
        'olvide_password.php',
        'reset_password.php',
        'analytics.php',
        'favoritos_print.php',
        'favoritos_api.php',
        'buscar_api.php',
        'event.php',
        'go.php',
        'tracking.php',
        'logout.php',
        'guardar_sugerencia.php',
        'favorito_toggle.php',
        'cron_queue_run_all.php',
    ], true);
}

function render_global_search_dock(): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $q = trim((string) ($_GET['q'] ?? ''));
    $categoriaId = (int) ($_GET['categoria'] ?? 0);
    $sort = (string) ($_GET['orden'] ?? 'recientes');
    if (!array_key_exists($sort, active_sort_options())) {
        $sort = 'recientes';
    }

    $tiendaId = (int) ($_GET['tienda'] ?? 0);
    $marca = trim((string) ($_GET['marca'] ?? ''));
    $precioMin = trim((string) ($_GET['precio_min'] ?? ''));
    $precioMax = trim((string) ($_GET['precio_max'] ?? ''));

    try {
        $categorias = db()->query('SELECT idcategorias, cat_nombre FROM categorias ORDER BY cat_nombre ASC')->fetchAll();
    } catch (Throwable $e) {
        $categorias = [];
    }

    $liveAttrs = '';
    if ($script === 'index.php') {
        $liveAttrs = ' data-live-target="#home-live-results" data-count-target="#home-live-count" data-state-target="#home-live-state"';
    } elseif ($script === 'buscar.php') {
        $liveAttrs = ' data-live-target="#results-live-results" data-count-target="#results-live-count" data-state-target="#results-live-state"';
    }

    $context = match ($script) {
        'buscar.php' => 'results',
        'producto.php' => 'product',
        'tienda.php' => 'store',
        'favoritos.php' => 'favorites',
        default => 'global',
    };

    echo '<div class="global-search-dock navbar-search-dock"';
    echo '  data-search-root';
    echo '  data-search-endpoint="buscar_api.php"';
    echo '  data-search-history-endpoint="guardar_busqueda.php"';
    echo '  data-search-results-page="buscar.php"';
    echo '  data-search-mode="pro"';
    echo '  data-search-context="' . e($context) . '"' . $liveAttrs . '>';
    echo '  <form class="js-smart-search-form search-dock-form navbar-search-form" method="get" action="buscar.php" autocomplete="off">';

    if ($script === 'buscar.php') {
        echo '    <input type="hidden" name="tienda" value="' . $tiendaId . '" data-search-filter="tienda">';
        echo '    <input type="hidden" name="marca" value="' . e($marca) . '" data-search-filter="marca">';
        echo '    <input type="hidden" name="precio_min" value="' . e($precioMin) . '" data-search-filter="precio_min">';
        echo '    <input type="hidden" name="precio_max" value="' . e($precioMax) . '" data-search-filter="precio_max">';
    }

    echo '    <div class="search-dock-row navbar-search-row">';
    echo '      <div class="search-dock-filters navbar-search-filters">';
    echo '        <div class="search-category-col search-dock-select-wrap">';
    echo '          <span class="search-dock-select-icon" aria-hidden="true">' . lucide_icon('layout-grid', '', 15) . '</span>';
    echo '          <select name="categoria" class="form-select js-smart-search-category search-dock-select" data-search-category data-select-tooltip aria-label="Categoría" title="Todas las categorías">';
    echo '            <option value="0" title="Todas las categorías" data-full-label="Todas las categorías">Categoría</option>';
    foreach ($categorias as $categoria) {
        $id = (int) $categoria['idcategorias'];
        $name = (string) $categoria['cat_nombre'];
        $selected = $categoriaId === $id ? ' selected' : '';
        echo '            <option value="' . $id . '" title="' . e($name) . '" data-full-label="' . e($name) . '"' . $selected . '>' . e($name) . '</option>';
    }
    echo '          </select>';
    echo '        </div>';
    echo '        <div class="search-order-col search-dock-select-wrap">';
    echo '          <span class="search-dock-select-icon" aria-hidden="true">' . lucide_icon('arrow-up-down', '', 15) . '</span>';
    echo '          <select name="orden" class="form-select search-dock-select" data-search-order data-search-sort data-select-tooltip aria-label="Orden">';
    foreach (active_sort_options() as $value => $label) {
        $selected = $sort === $value ? ' selected' : '';
        echo '            <option value="' . e($value) . '" title="' . e($label) . '" data-full-label="' . e($label) . '"' . $selected . '>' . e($label) . '</option>';
    }
    echo '          </select>';
    echo '        </div>';
    echo '      </div>';

    echo '      <div class="search-input-col search-dock-field">';
    echo '        <span class="search-dock-icon" aria-hidden="true">' . lucide_icon('search', '', 18) . '</span>';
    echo '        <label for="global-search-input" class="visually-hidden">Buscar productos</label>';
    echo '        <input';
    echo '          id="global-search-input"';
    echo '          type="search"';
    echo '          name="q"';
    echo '          class="form-control js-smart-search-input search-dock-input"';
    echo '          placeholder="Producto, marca o tienda"';
    echo '          value="' . e($q) . '"';
    echo '          autocomplete="off"';
    echo '          enterkeyhint="search"';
    echo '          data-search-input';
    echo '          data-live-delay="320"';
    echo '          data-min-length="2"';
    echo '          aria-autocomplete="list"';
    echo '          aria-expanded="false"';
    echo '          aria-controls="global-search-suggestions"';
    echo '        >';
    echo '        <div';
    echo '          id="global-search-suggestions"';
    echo '          class="search-suggest-dropdown search-suggestions-panel"';
    echo '          data-search-dropdown';
    echo '          data-search-suggest';
    echo '          data-search-suggestions';
    echo '          role="listbox"';
    echo '          aria-label="Sugerencias de búsqueda"';
    echo '        ></div>';
    echo '      </div>';
    echo '    </div>';
    echo '  </form>';
    echo '</div>';
    echo '<script>';
    echo '(function(){';
    echo 'var root=document.getElementById("global-search-fixed");';
    echo 'var host=document.getElementById("navbar-search-host");';
    echo 'if(!root)return;';
    echo 'root.querySelectorAll("[data-select-tooltip]").forEach(function(select){';
    echo 'function syncTitle(){';
    echo 'var opt=select.options[select.selectedIndex];';
    echo 'var label=(opt&&(opt.getAttribute("data-full-label")||opt.title||opt.textContent))||"";';
    echo 'select.title=label;';
    echo 'select.setAttribute("aria-description",label);';
    echo '}';
    echo 'select.addEventListener("change",syncTitle);';
    echo 'syncTitle();';
    echo '});';
    echo 'if(!host)return;';
    echo 'var mq=window.matchMedia("(max-width: 991.98px)");';
    echo 'var dockSpace="--mobile-search-dock-space";';
    echo 'function setDockSpace(){';
    echo 'if(!root.classList.contains("is-mobile-bottom-dock")){';
    echo 'document.documentElement.style.removeProperty(dockSpace);';
    echo 'return;';
    echo '}';
    echo 'document.documentElement.style.setProperty(dockSpace,root.offsetHeight+"px");';
    echo '}';
    echo 'function syncMobileDock(){';
    echo 'if(mq.matches){';
    echo 'if(root.parentElement!==document.body)document.body.appendChild(root);';
    echo 'root.classList.add("is-mobile-bottom-dock");';
    echo 'document.documentElement.classList.add("has-mobile-search-dock");';
    echo '}else{';
    echo 'if(root.parentElement!==host)host.appendChild(root);';
    echo 'root.classList.remove("is-mobile-bottom-dock");';
    echo 'document.documentElement.classList.remove("has-mobile-search-dock");';
    echo '}';
    echo 'setDockSpace();';
    echo '}';
    echo 'syncMobileDock();';
    echo 'if(mq.addEventListener)mq.addEventListener("change",syncMobileDock);';
    echo 'else if(mq.addListener)mq.addListener(syncMobileDock);';
    echo 'if(typeof ResizeObserver!=="undefined"){';
    echo 'new ResizeObserver(setDockSpace).observe(root);';
    echo '}else{';
    echo 'window.addEventListener("resize",setDockSpace,{passive:true});';
    echo '}';
    echo '})();';
    echo '</script>';
}

function render_footer(): void
{
    $suggestionSaved = isset($_GET['suggestion_saved']) && $_GET['suggestion_saved'] === '1';
    $suggestionError = isset($_GET['suggestion_error']) ? trim((string) $_GET['suggestion_error']) : '';

    $user = current_user();
    $year = date('Y');

    echo '<div class="site-footer-push" aria-hidden="true"></div>';
    echo '<footer class="footer site-footer">';
    echo '  <div class="container site-footer-inner">';
    echo '    <div class="site-footer-grid">';

    echo '      <div class="site-footer-brand">';
    echo '        <a class="site-footer-logo-link" href="index.php">';
    echo '          <img src="./img/logo_horizontal.webp" data-theme-logo data-light-logo="./img/logo_horizontal.webp" data-dark-logo="./img/logo_horizontal_dark.webp" alt="Caacuprecio" class="site-footer-logo" width="156" height="52">';
    echo '        </a>';
    echo '        <p class="site-footer-tagline">Compara precios entre los comercios de la ciudad y elige la mejor opción antes de salir.</p>';
    echo '        <div class="site-footer-meta">';
    echo '          <p class="site-footer-place">';
    echo              lucide_icon('map-pin', 'site-footer-place-icon', 14);
    echo '            <span>Caacupé, Paraguay</span>';
    echo '          </p>';
    if (BUSINESS_EMAIL !== '') {
        echo '          <a class="site-footer-place site-footer-mail" href="mailto:' . e(BUSINESS_EMAIL) . '">';
        echo              lucide_icon('mail', 'site-footer-place-icon', 14);
        echo '            <span>' . e(BUSINESS_EMAIL) . '</span>';
        echo '          </a>';
    }
    echo '        </div>';
    echo '      </div>';

    echo '      <div class="site-footer-navs">';

    echo '        <nav class="site-footer-col" aria-label="Explorar">';
    echo '          <h6 class="site-footer-heading">Explorar</h6>';
    echo '          <ul class="site-footer-links">';
    echo '            <li><a href="index.php#productos">Productos</a></li>';
    echo '            <li><a href="index.php#tiendas">Tiendas</a></li>';
    echo '            <li><a href="index.php#categorias">Categorías</a></li>';
    echo '            <li><a href="buscar.php">Buscar</a></li>';
    echo '          </ul>';
    echo '        </nav>';

    echo '        <nav class="site-footer-col" aria-label="Cuenta">';
    echo '          <h6 class="site-footer-heading">Cuenta</h6>';
    echo '          <ul class="site-footer-links">';
    if ($user) {
        echo '            <li><a href="usuario.php">Mi cuenta</a></li>';
        echo '            <li><a href="favoritos.php">Mis favoritos</a></li>';
        if (is_admin() || is_empresa()) {
            echo '            <li><a href="admin.php">Panel admin</a></li>';
        }
        echo '            <li><a href="logout.php">Cerrar sesión</a></li>';
    } else {
        echo '            <li><a href="login.php">Ingresar</a></li>';
        echo '            <li><a href="registro.php">Crear cuenta</a></li>';
        echo '            <li><a href="olvide_password.php">Recuperar clave</a></li>';
    }
    echo '          </ul>';
    echo '        </nav>';

    echo '        <nav class="site-footer-col" aria-label="Legal">';
    echo '          <h6 class="site-footer-heading">Legal</h6>';
    echo '          <ul class="site-footer-links">';
    echo '            <li><a href="terminos.php">Términos</a></li>';
    echo '            <li><a href="privacidad.php">Privacidad</a></li>';
    echo '            <li><a href="cookies.php">Cookies</a></li>';
    echo '            <li><a href="reembolsos.php">Reembolsos</a></li>';
    echo '            <li><a href="aviso_legal.php">Aviso legal</a></li>';
    echo '          </ul>';
    echo '        </nav>';

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
        echo '        <nav class="site-footer-col" aria-label="Tiendas">';
        echo '          <h6 class="site-footer-heading">Tiendas</h6>';
        echo '          <ul class="site-footer-links">';
        foreach ($footerStores as $store) {
            echo '            <li><a href="tienda.php?id=' . (int) $store['idtiendas'] . '">' . e($store['tie_nombre']) . '</a></li>';
        }
        echo '          </ul>';
        echo '        </nav>';
    }

    echo '      </div>';
    echo '    </div>';

    echo '    <div class="site-footer-bottom">';
    echo '      <p class="site-footer-note">' . e(APP_NAME) . ' no vende productos. Precios, stock e imágenes provienen de cada tienda, pueden estar desactualizados y no constituyen una oferta propia; confirma siempre antes de comprar. Las marcas pertenecen a sus respectivos titulares.</p>';
    echo '      <p class="site-footer-copy">© ' . e($year) . ' ' . e(APP_NAME) . '</p>';
    echo '    </div>';
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
    echo '    const modal = document.getElementById("suggestionModal");';
    echo '    if (!modal) return;';
    // Igual que deleteAccountModal: modal + backdrop como hijos directos de body
    echo '    if (modal.parentElement !== document.body) { document.body.appendChild(modal); }';
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
    echo 'const toggleButtons=[document.getElementById("themeToggle")].filter(Boolean);';
    echo 'function paintThemeCore(theme){const isDark=theme!=="light";root.setAttribute("data-bs-theme",isDark?"dark":"light");root.classList.toggle("theme-dark",isDark);root.classList.toggle("theme-light",!isDark);body.classList.toggle("theme-dark",isDark);body.classList.toggle("theme-light",!isDark);}';
    echo 'function paintThemeChrome(theme){const isDark=theme!=="light";document.querySelectorAll("[data-theme-logo]").forEach((img)=>{const src=isDark?img.dataset.darkLogo:img.dataset.lightLogo;if(src&&img.getAttribute("src")!==src){img.setAttribute("src",src);}});toggleButtons.forEach((btn)=>{const host=btn.querySelector("[data-lucide-host]");const text=btn.querySelector("span:not([data-lucide-host])");if(host&&window.setLucideIcon){setLucideIcon(host,isDark?(host.dataset.iconDark||"sun"):(host.dataset.iconLight||"moon"));}if(text){text.textContent=isDark?"Modo claro":"Modo oscuro";}});}';
    echo 'function supportsColorTween(){try{return CSS&&CSS.registerProperty&&CSS.supports("(color: color-mix(in srgb, red, blue))");}catch(e){return false;}}';
    // View Transitions primero: en index hay cientos de tiles y el tween de variables laguea
    echo 'function applyTheme(theme,animate){const run=function(){paintThemeCore(theme);paintThemeChrome(theme);};if(!animate){run();return;}if(document.startViewTransition){root.classList.add("theme-vt-active");const vt=document.startViewTransition(function(){paintThemeCore(theme);});Promise.resolve(vt.finished).catch(function(){}).then(function(){root.classList.remove("theme-vt-active");paintThemeChrome(theme);});return;}if(supportsColorTween()){paintThemeCore(theme);requestAnimationFrame(function(){paintThemeChrome(theme);});return;}run();}';
    echo 'const savedTheme=localStorage.getItem(storageKey)||"dark";applyTheme(savedTheme,false);';
    echo 'requestAnimationFrame(function(){body.classList.add("theme-animated");});';
    echo 'toggleButtons.forEach((btn)=>btn.addEventListener("click",function(){const next=root.getAttribute("data-bs-theme")==="dark"?"light":"dark";localStorage.setItem(storageKey,next);applyTheme(next,true);}));';

    if ($suggestionError !== '') {
        echo 'const modalEl=document.getElementById("suggestionModal"); if(modalEl && window.bootstrap){ new bootstrap.Modal(modalEl).show(); }';
    }

    echo '})();';
    echo '</script>';
    if (should_render_global_search()) {
        $searchJs = __DIR__ . '/js/search.js';
        $searchVer = is_file($searchJs) ? (string) filemtime($searchJs) : '1';
        echo '<script src="./js/search.js?v=' . e($searchVer) . '"></script>';
    }
    $favoritesJs = __DIR__ . '/js/favorites.js';
    if (is_file($favoritesJs)) {
        echo '<script src="./js/favorites.js?v=' . e((string) filemtime($favoritesJs)) . '"></script>';
    }
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '<script>(function(){var nav=document.getElementById("mainNav");var backdrop=document.getElementById("navbarMenuBackdrop");if(!nav||!backdrop)return;var sync=function(open){document.body.classList.toggle("navbar-menu-open",open);backdrop.setAttribute("aria-hidden",open?"false":"true");};nav.addEventListener("show.bs.collapse",function(){sync(true);});nav.addEventListener("hide.bs.collapse",function(){sync(false);});backdrop.addEventListener("click",function(){var inst=window.bootstrap&&bootstrap.Collapse.getInstance(nav);if(inst)inst.hide();});})();</script>';
    echo '</body></html>';
}
