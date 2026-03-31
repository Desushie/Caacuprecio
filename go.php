<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$productId = (int) ($_GET['id'] ?? 0);
$source = trim((string) ($_GET['source'] ?? 'producto'));
$clickType = trim((string) ($_GET['click_type'] ?? 'ir_oferta'));
$term = trim((string) ($_GET['term'] ?? ''));
$targetUrl = trim((string) ($_GET['target_url'] ?? ''));

if ($productId <= 0) {
    http_response_code(400);
    exit('ID de producto inválido.');
}

$pdo = db();
$userId = function_exists('current_user_id') ? (int) current_user_id() : 0;
$sessionId = session_id();

/**
 * Verificar si existe tabla (versión CORRECTA)
 */
function cp_go_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = mb_strtolower($table, 'UTF-8');

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

/**
 * Obtener producto
 */
$stmt = $pdo->prepare("
    SELECT idproductos, pro_url
    FROM productos
    WHERE idproductos = :id
      AND pro_activo = 1
    LIMIT 1
");
$stmt->execute([':id' => $productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    exit('Producto no encontrado.');
}

/**
 * URL destino
 */
$redirectUrl = $targetUrl !== '' ? $targetUrl : trim((string) ($product['pro_url'] ?? ''));

if ($redirectUrl === '') {
    http_response_code(404);
    exit('No hay URL disponible para este producto.');
}

/**
 * TRACKING CLICK (AHORA SÍ FUNCIONA)
 */
if (cp_go_table_exists($pdo, 'producto_clicks')) {
    try {
        $track = $pdo->prepare("
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

        $track->bindValue(':pid', (int) $product['idproductos'], PDO::PARAM_INT);

        if ($userId > 0) {
            $track->bindValue(':uid', $userId, PDO::PARAM_INT);
        } else {
            $track->bindValue(':uid', null, PDO::PARAM_NULL);
        }

        if ($sessionId !== '') {
            $track->bindValue(':sid', $sessionId, PDO::PARAM_STR);
        } else {
            $track->bindValue(':sid', null, PDO::PARAM_NULL);
        }

        $track->bindValue(':origen', $source !== '' ? $source : 'producto', PDO::PARAM_STR);
        $track->bindValue(':tipo', $clickType !== '' ? $clickType : 'ir_oferta', PDO::PARAM_STR);

        if ($term !== '') {
            $track->bindValue(':busqueda', $term, PDO::PARAM_STR);
        } else {
            $track->bindValue(':busqueda', null, PDO::PARAM_NULL);
        }

        $track->bindValue(':destino', $redirectUrl, PDO::PARAM_STR);

        $track->execute();
    } catch (Throwable $e) {
        // No romper la navegación si falla tracking
    }
}

/**
 * Redirección
 */
header('Location: ' . $redirectUrl, true, 302);
exit;