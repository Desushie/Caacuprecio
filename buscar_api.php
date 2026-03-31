<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
$categoriaId = (int) ($_GET['categoria'] ?? $_POST['categoria'] ?? 0);
$sort = (string) ($_GET['orden'] ?? $_POST['orden'] ?? 'recientes');
$mode = trim((string) ($_GET['mode'] ?? $_POST['mode'] ?? 'suggest'));
$limit = max(1, min(12, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 8)));

$pdo = db();
$userId = function_exists('current_user_id') ? current_user_id() : 0;

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

function cp_normalize_term(string $term): string
{
    $term = trim(mb_strtolower($term, 'UTF-8'));
    $term = preg_replace('/\s+/u', ' ', $term) ?? $term;
    return $term;
}

function cp_session_search_history(?string $term = null, int $limit = 8): array
{
    $history = $_SESSION['search_history'] ?? [];
    $history = array_values(array_filter(array_map(
        static fn ($value) => trim((string) $value),
        is_array($history) ? $history : []
    )));

    if ($term !== null && $term !== '') {
        $history = array_values(array_filter(
            $history,
            static fn ($value) => mb_strtolower($value, 'UTF-8') !== mb_strtolower($term, 'UTF-8')
        ));
        array_unshift($history, $term);
    }

    $history = array_slice($history, 0, $limit);
    $_SESSION['search_history'] = $history;

    return $history;
}

function cp_record_search(PDO $pdo, string $term, int $userId = 0): array
{
    $term = trim($term);
    if ($term === '') {
        return [false, 'Término vacío'];
    }

    cp_session_search_history($term, 8);

    if (!cp_table_exists($pdo, 'busquedas')) {
        return [false, 'La tabla busquedas no existe o no se detecta'];
    }

    $normalized = cp_normalize_term($term);

    try {
        $update = $pdo->prepare("
            UPDATE busquedas
            SET bus_total = bus_total + 1,
                bus_ultima_fecha = NOW(),
                bus_usuario_id = CASE
                    WHEN (bus_usuario_id IS NULL OR bus_usuario_id = 0) AND :uid_check > 0 THEN :uid_set
                    ELSE bus_usuario_id
                END
            WHERE bus_normalizado = :normalizado
            LIMIT 1
        ");
        $update->execute([
            ':uid_check' => $userId,
            ':uid_set' => $userId,
            ':normalizado' => $normalized,
        ]);

        if ($update->rowCount() === 0) {
            $insert = $pdo->prepare("
                INSERT INTO busquedas (
                    bus_termino,
                    bus_normalizado,
                    bus_total,
                    bus_usuario_id,
                    bus_ultima_fecha
                )
                VALUES (
                    :termino,
                    :normalizado,
                    1,
                    :uid,
                    NOW()
                )
            ");

            $insert->bindValue(':termino', $term);
            $insert->bindValue(':normalizado', $normalized);

            if ($userId > 0) {
                $insert->bindValue(':uid', $userId, PDO::PARAM_INT);
            } else {
                $insert->bindValue(':uid', null, PDO::PARAM_NULL);
            }

            $insert->execute();
        }

        return [true, null];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

function cp_get_trending_searches(PDO $pdo, int $limit = 6): array
{
    if (!cp_table_exists($pdo, 'busquedas')) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT bus_termino, bus_total, bus_ultima_fecha
            FROM busquedas
            WHERE bus_termino IS NOT NULL
              AND TRIM(bus_termino) <> ''
            ORDER BY bus_total DESC, bus_ultima_fecha DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

if ($mode === 'track') {
    [$ok, $error] = cp_record_search($pdo, $q, $userId);

    json_out([
        'ok' => $ok,
        'tracked' => $ok,
        'query' => $q,
        'error' => $error,
    ]);
}

if ($mode === 'suggest') {
    $suggestions = [];
    $seen = [];

    if ($q !== '') {
        $like = '%' . $q . '%';

        try {
            $stmt = $pdo->prepare("
                (
                    SELECT
                        p.pro_nombre AS label,
                        'producto' AS type,
                        COALESCE(p.pro_marca, '') AS meta
                    FROM productos p
                    WHERE p.pro_activo = 1
                      AND p.pro_nombre LIKE :q1
                    GROUP BY p.pro_nombre, p.pro_marca
                    ORDER BY MAX(p.pro_fecha_scraping) DESC
                    LIMIT 5
                )
                UNION
                (
                    SELECT
                        p.pro_marca AS label,
                        'marca' AS type,
                        'Marca' AS meta
                    FROM productos p
                    WHERE p.pro_activo = 1
                      AND p.pro_marca IS NOT NULL
                      AND TRIM(p.pro_marca) <> ''
                      AND p.pro_marca LIKE :q2
                    GROUP BY p.pro_marca
                    ORDER BY COUNT(*) DESC
                    LIMIT 4
                )
                UNION
                (
                    SELECT
                        c.cat_nombre AS label,
                        'categoria' AS type,
                        'Categoría' AS meta
                    FROM categorias c
                    WHERE c.cat_nombre LIKE :q3
                    LIMIT 4
                )
                UNION
                (
                    SELECT
                        t.tie_nombre AS label,
                        'tienda' AS type,
                        'Tienda' AS meta
                    FROM tiendas t
                    WHERE t.tie_nombre LIKE :q4
                    LIMIT 4
                )
                LIMIT 12
            ");
            $stmt->execute([
                ':q1' => $like,
                ':q2' => $like,
                ':q3' => $like,
                ':q4' => $like,
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = mb_strtolower(trim((string) $row['label']), 'UTF-8');
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $suggestions[] = [
                    'label' => $row['label'],
                    'type' => $row['type'],
                    'meta' => $row['meta'],
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $trending = array_map(static function (array $item): array {
        return [
            'label' => $item['bus_termino'],
            'type' => 'trending',
            'meta' => ((int) $item['bus_total']) . ' búsquedas',
        ];
    }, cp_get_trending_searches($pdo, 6));

    $history = [];
    foreach (cp_session_search_history(null, 6) as $term) {
        $history[] = [
            'label' => (string) $term,
            'type' => 'history',
            'meta' => 'Reciente',
        ];
    }

    json_out([
        'ok' => true,
        'query' => $q,
        'suggestions' => $suggestions,
        'trending' => $trending,
        'history' => $history,
    ]);
}

if ($mode === 'live') {
    $where = ['p.pro_activo = 1', 'COALESCE(NULLIF(TRIM(p.pro_grupo), ""), p.pro_nombre) <> ""'];
    $params = [];

    if ($q !== '') {
        $where[] = '(
            p.pro_nombre LIKE :q_nombre
            OR p.pro_descripcion LIKE :q_descripcion
            OR p.pro_marca LIKE :q_marca
            OR t.tie_nombre LIKE :q_tienda
            OR c.cat_nombre LIKE :q_categoria
            OR p.pro_grupo LIKE :q_grupo
        )';

        $likeQ = '%' . $q . '%';
        $params[':q_nombre'] = $likeQ;
        $params[':q_descripcion'] = $likeQ;
        $params[':q_marca'] = $likeQ;
        $params[':q_tienda'] = $likeQ;
        $params[':q_categoria'] = $likeQ;
        $params[':q_grupo'] = $likeQ;
    }

    if ($categoriaId > 0) {
        $where[] = 'p.categorias_idcategorias = :categoria';
        $params[':categoria'] = $categoriaId;
    }

    $whereSql = implode(' AND ', $where);

    $orderBy = match ($sort) {
        'precio_asc'  => 'precio_min ASC, total_ofertas DESC, nombre ASC',
        'precio_desc' => 'precio_min DESC, total_ofertas DESC, nombre ASC',
        'nombre_asc'  => 'nombre ASC',
        'nombre_desc' => 'nombre DESC',
        default       => 'total_ofertas DESC, precio_min ASC, nombre ASC',
    };

    $sql = "
        SELECT
            grupo,
            MIN(idproductos) AS id_representante,
            MIN(pro_nombre) AS nombre,
            MAX(pro_marca) AS marca,
            MIN(pro_precio) AS precio_min,
            MAX(pro_imagen) AS imagen,
            MAX(cat_nombre) AS categoria,
            SUM(CASE WHEN pro_en_stock = 1 THEN 1 ELSE 0 END) AS ofertas_stock,
            COUNT(*) AS total_ofertas
        FROM (
            SELECT
                p.idproductos,
                COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo,
                p.pro_nombre,
                p.pro_marca,
                p.pro_precio,
                p.pro_imagen,
                p.pro_en_stock,
                c.cat_nombre
            FROM productos p
            INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
            LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
            WHERE {$whereSql}
        ) base
        GROUP BY grupo
        ORDER BY {$orderBy}
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $enStock = ((int) $product['ofertas_stock']) > 0 ? 1 : 0;
        $totalOfertas = (int) $product['total_ofertas'];
        $nombreBase = trim((string) ($product['grupo'] ?: $product['nombre']));

        $items[] = [
            'id' => (int) $product['id_representante'],
            'grupo' => $product['grupo'],
            'nombre' => $nombreBase,
            'marca' => $product['marca'],
            'categoria' => $product['categoria'],
            'precio' => 'Desde ' . gs($product['precio_min']),
            'precio_raw' => (float) $product['precio_min'],
            'imagen' => image_url($product['imagen'], $nombreBase),
            'stock' => $enStock ? 'Disponible' : 'Sin stock',
            'stock_class' => stock_badge_class($enStock),
            'tienda' => $totalOfertas . ' oferta' . ($totalOfertas !== 1 ? 's' : ''),
            'url' => 'producto.php?grupo=' . urlencode($product['grupo']),
            'ofertas' => $totalOfertas,
        ];
    }

    json_out([
        'ok' => true,
        'query' => $q,
        'items' => $items,
    ]);
}

json_out([
    'ok' => false,
    'error' => 'Modo no válido.',
], 400);
