<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

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

$action = trim((string) ($data['action'] ?? ''));
$productId = (int) ($data['product_id'] ?? 0);
$term = trim((string) ($data['term'] ?? ''));

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
        $stmt = $pdo->prepare("SHOW TABLES LIKE :table");
        $stmt->execute([':table' => $table]);
        $cache[$key] = (bool) $stmt->fetchColumn();
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

if ($action === '' || $productId <= 0) {
    json_out([
        'ok' => false,
        'error' => 'Datos insuficientes.',
    ], 400);
}

$viewTracked = false;
$clickTracked = false;

if ($action === 'view' || $action === 'search_click') {
    $viewTracked = cp_insert_product_view($pdo, $productId, $userId, $sessionId);
}

if ($action === 'search_click' && $term !== '') {
    $clickTracked = cp_insert_search_click($pdo, $term, $productId, $userId, $sessionId);
}

json_out([
    'ok' => true,
    'action' => $action,
    'view_tracked' => $viewTracked,
    'click_tracked' => $clickTracked,
    'product_id' => $productId,
    'term' => $term,
]);
