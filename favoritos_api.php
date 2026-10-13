<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function fav_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = current_user_id();
if ($userId <= 0) {
    fav_json(['ok' => false, 'error' => 'Inicia sesión para usar tus favoritos.'], 401);
}

$pdo = db();
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'set' || $action === 'add_many' || $action === 'remove_many' || $action === 'clear') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        fav_json(['ok' => false, 'error' => 'Solicitud no válida.'], 400);
    }
}

if ($action === 'set') {
    $productId = (int) ($_POST['product_id'] ?? 0);
    $wantFavorite = ($_POST['favorite'] ?? '') === '1';
    $params = [':u' => $userId, ':p' => $productId];

    if ($wantFavorite) {
        $productStmt = $pdo->prepare('SELECT idproductos FROM productos WHERE idproductos = :id AND pro_activo = 1 LIMIT 1');
        $productStmt->execute([':id' => $productId]);
        if (!$productStmt->fetchColumn()) {
            fav_json(['ok' => false, 'error' => 'Producto no encontrado.'], 404);
        }

        $existsStmt = $pdo->prepare('SELECT 1 FROM favoritos WHERE usuario_idusuario = :u AND productos_idproductos = :p LIMIT 1');
        $existsStmt->execute($params);
        if (!$existsStmt->fetchColumn()) {
            $pdo->prepare('INSERT INTO favoritos (usuario_idusuario, productos_idproductos) VALUES (:u, :p)')->execute($params);
        }
    } else {
        $pdo->prepare('DELETE FROM favoritos WHERE usuario_idusuario = :u AND productos_idproductos = :p')->execute($params);
    }

    fav_json(['ok' => true, 'favorite' => $wantFavorite]);
}

if ($action === 'add_many') {
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))),
        static fn (int $id): bool => $id > 0
    )));
    $ids = array_slice($ids, 0, 200);
    $added = [];

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT p.idproductos
            FROM productos p
            LEFT JOIN favoritos f
                ON f.productos_idproductos = p.idproductos
               AND f.usuario_idusuario = ?
            WHERE p.pro_activo = 1
              AND f.productos_idproductos IS NULL
              AND p.idproductos IN ($placeholders)
        ");
        $stmt->execute(array_merge([$userId], $ids));
        $added = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($added) {
            $insert = $pdo->prepare('INSERT INTO favoritos (usuario_idusuario, productos_idproductos) VALUES (:u, :p)');
            $pdo->beginTransaction();
            foreach ($added as $productId) {
                $insert->execute([':u' => $userId, ':p' => $productId]);
            }
            $pdo->commit();
        }
    }

    fav_json(['ok' => true, 'added' => $added]);
}

if ($action === 'remove_many') {
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))),
        static fn (int $id): bool => $id > 0
    )));
    $ids = array_slice($ids, 0, 200);

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM favoritos WHERE usuario_idusuario = ? AND productos_idproductos IN ($placeholders)");
        $stmt->execute(array_merge([$userId], $ids));
    }

    fav_json(['ok' => true, 'removed' => $ids]);
}

if ($action === 'clear') {
    $stmt = $pdo->prepare('DELETE FROM favoritos WHERE usuario_idusuario = :u');
    $stmt->execute([':u' => $userId]);

    fav_json(['ok' => true, 'removed' => $stmt->rowCount()]);
}

if ($action === 'search') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = 15;
    $poolSize = $limit * 15;

    $where = ['p.pro_activo = 1', 'p.pro_precio IS NOT NULL', 'f.productos_idproductos IS NULL'];
    $params = [':uid' => $userId];

    if ($q !== '') {
        $terms = array_slice(array_filter(preg_split('/\s+/u', $q) ?: []), 0, 5);
        foreach ($terms as $index => $term) {
            $key = ':t' . $index;
            $where[] = "(p.pro_nombre LIKE {$key}a OR p.pro_marca LIKE {$key}b OR t.tie_nombre LIKE {$key}c)";
            $params[$key . 'a'] = '%' . $term . '%';
            $params[$key . 'b'] = '%' . $term . '%';
            $params[$key . 'c'] = '%' . $term . '%';
        }
        $orderBy = 'p.pro_en_stock DESC, p.pro_precio ASC';
    } else {
        $where[] = 'p.pro_precio > 0 AND p.pro_precio_anterior IS NOT NULL AND p.pro_precio_anterior > p.pro_precio';
        $orderBy = 'p.pro_fecha_scraping DESC';
    }

    $sql = '
        SELECT
            p.idproductos,
            p.pro_nombre,
            p.pro_precio,
            p.pro_precio_anterior,
            p.pro_imagen,
            p.pro_en_stock,
            t.tie_nombre
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        LEFT JOIN favoritos f
            ON f.productos_idproductos = p.idproductos
           AND f.usuario_idusuario = :uid
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . $orderBy . '
        LIMIT ' . $poolSize;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Round-robin por tienda para que una sola no acapare los resultados.
    $byStore = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byStore[$row['tie_nombre']][] = $row;
    }
    $rows = [];
    while (count($rows) < $limit && $byStore) {
        foreach ($byStore as $store => &$queue) {
            $rows[] = array_shift($queue);
            if (!$queue) {
                unset($byStore[$store]);
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        unset($queue);
    }

    $items = array_map(static function (array $row): array {
        $price = (float) $row['pro_precio'];
        $oldPrice = $row['pro_precio_anterior'] !== null ? (float) $row['pro_precio_anterior'] : null;

        return [
            'id' => (int) $row['idproductos'],
            'nombre' => (string) $row['pro_nombre'],
            'tienda' => (string) $row['tie_nombre'],
            'precio' => gs_precio($price),
            'precio_valor' => $price,
            'precio_anterior' => ($price > 0 && $oldPrice !== null && $oldPrice > $price) ? gs($oldPrice) : null,
            'descuento' => ($price > 0 && $oldPrice !== null && $oldPrice > $price) ? (int) round((1 - $price / $oldPrice) * 100) : 0,
            'imagen' => image_url($row['pro_imagen'], (string) $row['pro_nombre']),
            'stock' => (int) $row['pro_en_stock'] === 1,
        ];
    }, $rows);

    fav_json(['ok' => true, 'query' => $q, 'items' => $items]);
}

fav_json(['ok' => false, 'error' => 'Acción no válida.'], 400);
