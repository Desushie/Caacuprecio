<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tracking.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = [];

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false && is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (!$data) {
    $data = $_POST;
}

// sendBeacon a veces llega como text/plain y PHP no llena $_POST.
if (!$data && is_string($raw) && $raw !== '') {
    parse_str($raw, $parsed);
    if (is_array($parsed) && $parsed) {
        $data = $parsed;
    }
}

const CP_EVENT_ACTIONS = ['view', 'search_click', 'product_click'];
const CP_EVENT_SOURCES = ['inicio', 'producto', 'buscar', 'busqueda', 'tienda', 'favoritos'];
const CP_EVENT_SOURCE_ALIASES = ['index' => 'inicio'];
const CP_EVENT_CLICK_TYPES = ['ir_oferta', 'ir_mejor_oferta'];

$action = trim((string) ($data['action'] ?? ''));
$action = in_array($action, CP_EVENT_ACTIONS, true) ? $action : '';
$productId = (int) ($data['product_id'] ?? 0);
$term = mb_substr(trim((string) ($data['term'] ?? '')), 0, 255, 'UTF-8');

$source = mb_strtolower(trim((string) ($data['source'] ?? '')), 'UTF-8');
$source = CP_EVENT_SOURCE_ALIASES[$source] ?? $source;
$source = in_array($source, CP_EVENT_SOURCES, true) ? $source : '';

$clickType = mb_strtolower(trim((string) ($data['click_type'] ?? '')), 'UTF-8');
$clickType = in_array($clickType, CP_EVENT_CLICK_TYPES, true) ? $clickType : '';

$targetUrl = trim((string) ($data['target_url'] ?? ''));
if (mb_strlen($targetUrl, 'UTF-8') > 500 || !preg_match('#^https?://#i', $targetUrl)) {
    $targetUrl = '';
}

$pdo = db();
$userId = function_exists('current_user_id') ? current_user_id() : 0;
$sessionId = session_id();

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cp_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = mb_strtolower($table);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
        ");
        $stmt->execute([':table' => $table]);
        $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function cp_insert_product_view(PDO $pdo, int $productId, int $userId = 0, string $sessionId = ''): bool
{
    if ($productId <= 0 || !cp_table_exists($pdo, 'productos_vistos')) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO productos_vistos (usuario_idusuario, session_id, productos_idproductos, visto_en)
            VALUES (:uid, :sid, :pid, NOW())
        ");
        if ($userId > 0) {
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':uid', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':sid', $sessionId !== '' ? $sessionId : null);
        $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (Throwable $e) {
        return false;
    }
}

function cp_insert_search_click(PDO $pdo, string $term, int $productId, int $userId = 0, string $sessionId = ''): bool
{
    if ($productId <= 0 || trim($term) === '' || !cp_table_exists($pdo, 'busqueda_click_producto')) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO busqueda_click_producto (termino, productos_idproductos, usuario_idusuario, session_id, creado_en)
            VALUES (:term, :pid, :uid, :sid, NOW())
        ");
        $stmt->bindValue(':term', $term);
        $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
        if ($userId > 0) {
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':uid', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':sid', $sessionId !== '' ? $sessionId : null);
        return $stmt->execute();
    } catch (Throwable $e) {
        return false;
    }
}

function cp_insert_product_click(
    PDO $pdo,
    int $productId,
    string $source,
    string $clickType,
    string $term = '',
    string $targetUrl = '',
    int $userId = 0,
    string $sessionId = ''
): bool {
    if ($productId <= 0 || $source === '' || $clickType === '' || !cp_table_exists($pdo, 'producto_clicks')) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO producto_clicks (
                productos_idproductos,
                usuario_idusuario,
                session_id,
                click_origen,
                click_tipo,
                click_busqueda,
                click_destino_url,
                click_fecha
            ) VALUES (
                :pid,
                :uid,
                :sid,
                :origen,
                :tipo,
                :busqueda,
                :destino,
                NOW()
            )
        ");

        $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);

        if ($userId > 0) {
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':uid', null, PDO::PARAM_NULL);
        }

        $stmt->bindValue(':sid', $sessionId !== '' ? $sessionId : null);
        $stmt->bindValue(':origen', $source);
        $stmt->bindValue(':tipo', $clickType);
        $stmt->bindValue(':busqueda', $term !== '' ? $term : null, $term !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':destino', $targetUrl !== '' ? $targetUrl : null, $targetUrl !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);

        return $stmt->execute();
    } catch (Throwable $e) {
        return false;
    }
}

if ($action === '' || $productId <= 0) {
    json_out([
        'ok' => false,
        'error' => 'Datos insuficientes.',
    ], 400);
}

$viewTracked = false;
$searchClickTracked = false;
$productClickTracked = false;
$requestedAction = $action;

$allowed = $action === 'product_click'
    ? cp_should_track('clicks', 30)
    : cp_should_track('views', 60);

if (!$allowed) {
    $action = 'ignored';
}

// Historial personal de la sesión aunque el tracking analítico esté bloqueado.
if (
    in_array($requestedAction, ['view', 'search_click'], true)
    && $productId > 0
    && function_exists('cp_remember_viewed_product')
    && !cp_is_bot_request()
) {
    cp_remember_viewed_product($productId);
}

if ($action === 'view') {
    $viewTracked = cp_insert_product_view($pdo, $productId, $userId, $sessionId);
}

if ($action === 'search_click') {
    $viewTracked = cp_insert_product_view($pdo, $productId, $userId, $sessionId);
    if ($term !== '') {
        $searchClickTracked = cp_insert_search_click($pdo, $term, $productId, $userId, $sessionId);
    }
}

if ($action === 'product_click') {
    $productClickTracked = cp_insert_product_click(
        $pdo,
        $productId,
        $source,
        $clickType,
        $term,
        $targetUrl,
        $userId,
        $sessionId
    );
}

json_out([
    'ok' => true,
    'action' => $action,
    'view_tracked' => $viewTracked,
    'search_click_tracked' => $searchClickTracked,
    'product_click_tracked' => $productClickTracked,
    'product_id' => $productId,
    'term' => $term,
    'source' => $source,
    'click_type' => $clickType,
]);