<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tracking.php';

$q = trim($_GET['q'] ?? '');
$categoriaId = (int) ($_GET['categoria'] ?? 0);
$sort = $_GET['orden'] ?? 'recientes';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 6;
$offset = ($page - 1) * $perPage;

$pdo = db();

$userLogged = function_exists('is_logged_in') && is_logged_in();
$favoritesEnabled = function_exists('is_favorite_product') && function_exists('favorite_toggle_url');
$currentUserId = function_exists('current_user_id') ? current_user_id() : 0;
$currentSessionId = session_id();

$stats = [
    'tiendas' => (int) $pdo->query('SELECT COUNT(*) FROM tiendas')->fetchColumn(),
    'categorias' => (int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn(),
    'productos' => (int) $pdo->query('SELECT COUNT(*) FROM productos WHERE pro_activo = 1')->fetchColumn(),
    'favoritos' => ($userLogged && function_exists('favorite_count')) ? favorite_count() : 0,
    'ofertas' => 0,
];

try {
    $stats['ofertas'] = (int) $pdo->query('
        SELECT COUNT(*)
        FROM productos
        WHERE pro_activo = 1
          AND pro_precio > 0
          AND pro_precio_anterior > pro_precio
    ')->fetchColumn();
} catch (Throwable $e) {
    $stats['ofertas'] = 0;
}

try {
    $categorias = $pdo->query('
        SELECT
            c.idcategorias,
            c.cat_nombre,
            COUNT(p.idproductos) AS total_productos
        FROM categorias c
        LEFT JOIN productos p
            ON p.categorias_idcategorias = c.idcategorias
           AND p.pro_activo = 1
        GROUP BY c.idcategorias, c.cat_nombre
        ORDER BY total_productos DESC, c.cat_nombre ASC
    ')->fetchAll();
} catch (Throwable $e) {
    $categorias = $pdo->query('SELECT idcategorias, cat_nombre FROM categorias ORDER BY cat_nombre ASC')->fetchAll();
}

$recentSearches = [];

if (!function_exists('cp_table_exists')) {
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

        // Algunos hosts restringen information_schema; SHOW TABLES es más fiable.
        if (!$cache[$key]) {
            try {
                $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table);
                $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($safe));
                $cache[$key] = (bool) $stmt?->fetchColumn();
            } catch (Throwable $e) {
                $cache[$key] = false;
            }
        }

        return $cache[$key];
    }
}

if (!function_exists('cp_search_table_exists')) {
    function cp_search_table_exists(PDO $pdo): bool
    {
        return cp_table_exists($pdo, 'busquedas');
    }
}

if (!function_exists('cp_get_popular_searches')) {
    function cp_get_popular_searches(PDO $pdo, int $limit = 8): array
    {
        if (!cp_search_table_exists($pdo)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT bus_termino AS termino, bus_total AS total, bus_ultima_fecha AS ultima_busqueda
                FROM busquedas
                WHERE bus_termino IS NOT NULL
                  AND TRIM(bus_termino) <> ''
                ORDER BY bus_total DESC, bus_ultima_fecha DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cp_clear_session_search_history')) {
    function cp_clear_session_search_history(): void
    {
        unset($_SESSION['search_history']);
    }
}

if (!function_exists('cp_remove_session_search_item')) {
    function cp_remove_session_search_item(string $term): void
    {
        $history = $_SESSION['search_history'] ?? [];
        if (is_array($history)) {
            $_SESSION['search_history'] = array_values(array_filter(
                $history,
                static fn ($value) => mb_strtolower(trim((string) $value), 'UTF-8') !== mb_strtolower(trim($term), 'UTF-8')
            ));
        }
    }
}

if (!function_exists('cp_redirect_without_clear_history')) {
    function cp_redirect_without_clear_history(string $fallback): void
    {
        $params = $_GET;
        unset($params['clear_history']);
        $target = $fallback;
        if (!empty($params)) {
            $target .= '?' . http_build_query($params);
        }
        header('Location: ' . $target);
        exit;
    }
}

if (!function_exists('cp_fetch_products_by_ids')) {
    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    function cp_fetch_products_by_ids(PDO $pdo, array $ids, int $limit = 8): array
    {
        $limit = max(1, (int) $limit);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        $ids = array_slice($ids, 0, $limit);

        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $orderCases = [];
        foreach ($ids as $i => $id) {
            $orderCases[] = 'WHEN ' . (int) $id . ' THEN ' . $i;
        }
        $orderSql = implode(' ', $orderCases);

        try {
            $stmt = $pdo->prepare("
                SELECT
                    p.idproductos,
                    p.pro_nombre,
                    p.pro_marca,
                    p.pro_precio,
                    p.pro_imagen,
                    p.pro_en_stock,
                    t.idtiendas,
                    t.tie_nombre,
                    1 AS total_vistas
                FROM productos p
                INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                WHERE p.pro_activo = 1
                  AND p.idproductos IN ({$placeholders})
                ORDER BY CASE p.idproductos {$orderSql} ELSE 999 END
                LIMIT {$limit}
            ");
            $stmt->execute($ids);

            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cp_get_recently_viewed_products')) {
    function cp_get_recently_viewed_products(PDO $pdo, int $userId = 0, string $sessionId = '', int $limit = 8): array
    {
        $limit = max(1, (int) $limit);
        $items = [];
        $seen = [];

        $pushRows = static function (array $rows) use (&$items, &$seen, $limit): void {
            foreach ($rows as $row) {
                if (count($items) >= $limit) {
                    break;
                }
                $id = (int) ($row['idproductos'] ?? 0);
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if (!isset($row['total_vistas'])) {
                    $row['total_vistas'] = 1;
                }
                $items[] = $row;
            }
        };

        // 1) Historial de sesión (fiable aunque el tracking analítico esté bloqueado).
        if (function_exists('cp_session_viewed_product_ids')) {
            $pushRows(cp_fetch_products_by_ids($pdo, cp_session_viewed_product_ids($limit), $limit));
        }

        // 2) Tabla productos_vistos (usuario y/o cookie de sesión).
        if (count($items) < $limit && cp_table_exists($pdo, 'productos_vistos')) {
            try {
                if ($userId > 0 && $sessionId !== '') {
                    $sql = "
                        SELECT
                            p.idproductos,
                            p.pro_nombre,
                            p.pro_marca,
                            p.pro_precio,
                            p.pro_imagen,
                            p.pro_en_stock,
                            t.idtiendas,
                            t.tie_nombre,
                            MAX(pv.visto_en) AS ultima_vista,
                            COUNT(*) AS total_vistas
                        FROM productos_vistos pv
                        INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
                        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                        WHERE p.pro_activo = 1
                          AND (pv.usuario_idusuario = :uid OR pv.session_id = :sid)
                        GROUP BY
                            p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                            p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                        ORDER BY ultima_vista DESC
                        LIMIT {$limit}
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                    $stmt->bindValue(':sid', $sessionId);
                } elseif ($userId > 0) {
                    $sql = "
                        SELECT
                            p.idproductos,
                            p.pro_nombre,
                            p.pro_marca,
                            p.pro_precio,
                            p.pro_imagen,
                            p.pro_en_stock,
                            t.idtiendas,
                            t.tie_nombre,
                            MAX(pv.visto_en) AS ultima_vista,
                            COUNT(*) AS total_vistas
                        FROM productos_vistos pv
                        INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
                        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                        WHERE p.pro_activo = 1
                          AND pv.usuario_idusuario = :uid
                        GROUP BY
                            p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                            p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                        ORDER BY ultima_vista DESC
                        LIMIT {$limit}
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                } elseif ($sessionId !== '') {
                    $sql = "
                        SELECT
                            p.idproductos,
                            p.pro_nombre,
                            p.pro_marca,
                            p.pro_precio,
                            p.pro_imagen,
                            p.pro_en_stock,
                            t.idtiendas,
                            t.tie_nombre,
                            MAX(pv.visto_en) AS ultima_vista,
                            COUNT(*) AS total_vistas
                        FROM productos_vistos pv
                        INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
                        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                        WHERE p.pro_activo = 1
                          AND pv.session_id = :sid
                        GROUP BY
                            p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                            p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                        ORDER BY ultima_vista DESC
                        LIMIT {$limit}
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':sid', $sessionId);
                } else {
                    $stmt = null;
                }

                if ($stmt) {
                    $stmt->execute();
                    $pushRows($stmt->fetchAll() ?: []);
                }
            } catch (Throwable $e) {
                // Seguir con clics de búsqueda / historial de términos.
            }
        }

        // 3) Productos abiertos desde una búsqueda (busqueda_click_producto).
        if (count($items) < $limit && cp_table_exists($pdo, 'busqueda_click_producto')) {
            try {
                if ($userId > 0 && $sessionId !== '') {
                    $sql = "
                        SELECT
                            p.idproductos,
                            p.pro_nombre,
                            p.pro_marca,
                            p.pro_precio,
                            p.pro_imagen,
                            p.pro_en_stock,
                            t.idtiendas,
                            t.tie_nombre,
                            MAX(bcp.creado_en) AS ultima_vista,
                            COUNT(*) AS total_vistas
                        FROM busqueda_click_producto bcp
                        INNER JOIN productos p ON p.idproductos = bcp.productos_idproductos
                        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                        WHERE p.pro_activo = 1
                          AND (bcp.usuario_idusuario = :uid OR bcp.session_id = :sid)
                        GROUP BY
                            p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                            p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                        ORDER BY ultima_vista DESC
                        LIMIT {$limit}
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                    $stmt->bindValue(':sid', $sessionId);
                } elseif ($userId > 0) {
                    $sql = "
                        SELECT
                            p.idproductos,
                            p.pro_nombre,
                            p.pro_marca,
                            p.pro_precio,
                            p.pro_imagen,
                            p.pro_en_stock,
                            t.idtiendas,
                            t.tie_nombre,
                            MAX(bcp.creado_en) AS ultima_vista,
                            COUNT(*) AS total_vistas
                        FROM busqueda_click_producto bcp
                        INNER JOIN productos p ON p.idproductos = bcp.productos_idproductos
                        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                        WHERE p.pro_activo = 1
                          AND bcp.usuario_idusuario = :uid
                        GROUP BY
                            p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                            p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                        ORDER BY ultima_vista DESC
                        LIMIT {$limit}
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                } elseif ($sessionId !== '') {
                    $sql = "
                        SELECT
                            p.idproductos,
                            p.pro_nombre,
                            p.pro_marca,
                            p.pro_precio,
                            p.pro_imagen,
                            p.pro_en_stock,
                            t.idtiendas,
                            t.tie_nombre,
                            MAX(bcp.creado_en) AS ultima_vista,
                            COUNT(*) AS total_vistas
                        FROM busqueda_click_producto bcp
                        INNER JOIN productos p ON p.idproductos = bcp.productos_idproductos
                        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                        WHERE p.pro_activo = 1
                          AND bcp.session_id = :sid
                        GROUP BY
                            p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                            p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                        ORDER BY ultima_vista DESC
                        LIMIT {$limit}
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':sid', $sessionId);
                } else {
                    $stmt = null;
                }

                if ($stmt) {
                    $stmt->execute();
                    $pushRows($stmt->fetchAll() ?: []);
                }
            } catch (Throwable $e) {
                // Continuar con términos de búsqueda de la sesión.
            }
        }

        // 4) Último recurso: términos buscados en esta sesión → un producto representante.
        if (count($items) < $limit && function_exists('cp_find_product_for_search_term')) {
            $terms = array_values(array_filter(array_map(
                static fn($value) => trim((string) $value),
                (array) ($_SESSION['search_history'] ?? [])
            )));

            foreach ($terms as $term) {
                if (count($items) >= $limit) {
                    break;
                }
                $row = cp_find_product_for_search_term($pdo, $term);
                if ($row) {
                    $row['total_vistas'] = 1;
                    $pushRows([$row]);
                }
            }
        }

        return array_slice($items, 0, $limit);
    }
}

if (!function_exists('cp_get_trending_products')) {
    function cp_get_trending_products(PDO $pdo, int $days = 7, int $limit = 8): array
    {
        if (!cp_table_exists($pdo, 'productos_vistos')) {
            return [];
        }

        try {
            $days = max(1, (int) $days);
            $limit = max(1, (int) $limit);

            $sql = "
                SELECT
                    p.idproductos,
                    p.pro_nombre,
                    p.pro_marca,
                    p.pro_precio,
                    p.pro_imagen,
                    p.pro_en_stock,
                    t.idtiendas,
                    t.tie_nombre,
                    COUNT(*) AS total_vistas
                FROM productos_vistos pv
                INNER JOIN productos p ON p.idproductos = pv.productos_idproductos
                INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                WHERE p.pro_activo = 1
                  AND pv.visto_en >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                GROUP BY
                    p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                    p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                ORDER BY total_vistas DESC, MAX(pv.visto_en) DESC
                LIMIT {$limit}
            ";

            return $pdo->query($sql)->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cp_find_product_for_search_term')) {
    function cp_find_product_for_search_term(PDO $pdo, string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term, 'UTF-8') < 2) {
            return [];
        }

        $like = '%' . $term . '%';
        $starts = $term . '%';

        try {
            $stmt = $pdo->prepare("
                SELECT
                    p.idproductos,
                    p.pro_nombre,
                    p.pro_marca,
                    p.pro_precio,
                    p.pro_imagen,
                    p.pro_en_stock,
                    t.idtiendas,
                    t.tie_nombre
                FROM productos p
                INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
                WHERE p.pro_activo = 1
                  AND (
                    p.pro_nombre LIKE :like_nombre
                    OR p.pro_marca LIKE :like_marca
                    OR t.tie_nombre LIKE :like_tienda
                    OR c.cat_nombre LIKE :like_categoria
                  )
                ORDER BY
                  CASE
                    WHEN p.pro_nombre LIKE :starts_nombre THEN 0
                    WHEN p.pro_nombre LIKE :like_nombre_rank THEN 1
                    WHEN p.pro_marca LIKE :like_marca_rank THEN 2
                    ELSE 3
                  END,
                  p.pro_fecha_scraping DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':like_nombre' => $like,
                ':like_marca' => $like,
                ':like_tienda' => $like,
                ':like_categoria' => $like,
                ':starts_nombre' => $starts,
                ':like_nombre_rank' => $like,
                ':like_marca_rank' => $like,
            ]);

            $row = $stmt->fetch();
            return is_array($row) ? $row : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cp_get_most_searched_products')) {
    function cp_get_most_searched_products(PDO $pdo, int $days = 30, int $limit = 8, array $sessionTerms = []): array
    {
        $limit = max(1, (int) $limit);
        $days = max(1, (int) $days);
        $items = [];
        $seen = [];

        if (cp_table_exists($pdo, 'busqueda_click_producto')) {
            try {
                $sql = "
                    SELECT
                        p.idproductos,
                        p.pro_nombre,
                        p.pro_marca,
                        p.pro_precio,
                        p.pro_imagen,
                        p.pro_en_stock,
                        t.idtiendas,
                        t.tie_nombre,
                        COUNT(*) AS total_clicks
                    FROM busqueda_click_producto bcp
                    INNER JOIN productos p ON p.idproductos = bcp.productos_idproductos
                    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                    WHERE p.pro_activo = 1
                      AND bcp.creado_en >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                    GROUP BY
                        p.idproductos, p.pro_nombre, p.pro_marca, p.pro_precio,
                        p.pro_imagen, p.pro_en_stock, t.idtiendas, t.tie_nombre
                    ORDER BY total_clicks DESC, MAX(bcp.creado_en) DESC
                    LIMIT {$limit}
                ";

                foreach ($pdo->query($sql)->fetchAll() as $row) {
                    $id = (int) ($row['idproductos'] ?? 0);
                    if ($id <= 0 || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $items[] = $row;
                }
            } catch (Throwable $e) {
                // Seguir con los términos buscados.
            }
        }

        if (count($items) >= $limit) {
            return array_slice($items, 0, $limit);
        }

        $terms = [];

        if (function_exists('cp_get_popular_searches')) {
            foreach (cp_get_popular_searches($pdo, 20) as $row) {
                $term = trim((string) ($row['termino'] ?? ''));
                if ($term === '') {
                    continue;
                }
                $key = mb_strtolower($term, 'UTF-8');
                $terms[$key] = [
                    'term' => $term,
                    'total' => max(1, (int) ($row['total'] ?? 1)),
                ];
            }
        }

        foreach ($sessionTerms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $key = mb_strtolower($term, 'UTF-8');
            if (!isset($terms[$key])) {
                $terms[$key] = [
                    'term' => $term,
                    'total' => 1,
                ];
            }
        }

        uasort($terms, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        foreach ($terms as $entry) {
            $product = cp_find_product_for_search_term($pdo, (string) $entry['term']);
            if (!$product) {
                continue;
            }

            $id = (int) ($product['idproductos'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $product['total_clicks'] = max(1, (int) $entry['total']);
            $items[] = $product;

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }
}

if (!function_exists('cp_store_tone')) {
    function cp_store_tone(string $name): string
    {
        $key = mb_strtolower(trim($name), 'UTF-8');

        if (str_contains($key, 'inverfin')) {
            return 'inverfin';
        }

        if (str_contains($key, 'full office') || str_contains($key, 'fulloffice')) {
            return 'office';
        }

        if (str_contains($key, 'computex')) {
            return 'computex';
        }

        if (str_contains($key, 'gonzalito')) {
            return 'gonzalito';
        }

        if (str_contains($key, 'comfort')) {
            return 'comfort';
        }

        if (str_contains($key, 'chacomer')) {
            return 'chacomer';
        }

        if (str_contains($key, 'bristol')) {
            return 'bristol';
        }

        if (str_contains($key, 'alex')) {
            return 'alex';
        }

        return 'other';
    }
}

if (!function_exists('cp_get_best_price_gaps')) {
    function cp_get_best_price_gaps(PDO $pdo, int $limit = 6): array
    {
        $limit = max(1, $limit);

        try {
            $stmt = $pdo->query("
                SELECT
                    p.idproductos,
                    p.pro_nombre,
                    p.pro_marca,
                    p.pro_precio,
                    p.pro_imagen,
                    p.pro_en_stock,
                    COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo,
                    t.idtiendas,
                    t.tie_nombre
                FROM productos p
                INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                INNER JOIN (
                    SELECT COALESCE(NULLIF(TRIM(p2.pro_grupo), ''), p2.pro_nombre) AS grupo
                    FROM productos p2
                    WHERE p2.pro_activo = 1
                      AND p2.pro_precio IS NOT NULL
                      AND p2.pro_precio > 0
                    GROUP BY COALESCE(NULLIF(TRIM(p2.pro_grupo), ''), p2.pro_nombre)
                    HAVING COUNT(DISTINCT p2.tiendas_idtiendas) >= 2
                ) candidates
                    ON candidates.grupo = COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre)
                WHERE p.pro_activo = 1
                  AND p.pro_precio IS NOT NULL
                  AND p.pro_precio > 0
                ORDER BY p.pro_precio ASC, p.idproductos ASC
            ");
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $bestByGroupStore = [];

        foreach ($rows as $row) {
            $grupo = trim((string) ($row['grupo'] ?? ''));
            $storeId = (int) ($row['idtiendas'] ?? 0);
            $price = isset($row['pro_precio']) ? (float) $row['pro_precio'] : 0.0;

            if ($grupo === '' || $storeId <= 0 || $price <= 0) {
                continue;
            }

            $key = $grupo . "\n" . $storeId;
            if (isset($bestByGroupStore[$key])) {
                continue;
            }

            $bestByGroupStore[$key] = [
                'grupo' => $grupo,
                'idproductos' => (int) ($row['idproductos'] ?? 0),
                'pro_nombre' => (string) ($row['pro_nombre'] ?? 'Producto'),
                'pro_marca' => trim((string) ($row['pro_marca'] ?? '')),
                'pro_precio' => $price,
                'pro_imagen' => $row['pro_imagen'] ?? null,
                'pro_en_stock' => (int) ($row['pro_en_stock'] ?? 0),
                'idtiendas' => $storeId,
                'tie_nombre' => (string) ($row['tie_nombre'] ?? 'Tienda'),
                'tone' => cp_store_tone((string) ($row['tie_nombre'] ?? '')),
            ];
        }

        $byGroup = [];
        foreach ($bestByGroupStore as $offer) {
            $byGroup[$offer['grupo']][] = $offer;
        }

        $gaps = [];

        foreach ($byGroup as $grupo => $offers) {
            if (count($offers) < 2) {
                continue;
            }

            usort($offers, static function (array $a, array $b): int {
                return $a['pro_precio'] <=> $b['pro_precio'];
            });

            $cheap = $offers[0];
            $expensive = $offers[count($offers) - 1];
            $saving = max(0, $expensive['pro_precio'] - $cheap['pro_precio']);
            $percent = $expensive['pro_precio'] > 0
                ? (int) round(($saving / $expensive['pro_precio']) * 100)
                : 0;

            $gaps[] = [
                'grupo' => $grupo,
                'idproductos' => $cheap['idproductos'],
                'pro_nombre' => $cheap['pro_nombre'],
                'pro_marca' => $cheap['pro_marca'],
                'pro_imagen' => $cheap['pro_imagen'],
                'pro_en_stock' => $cheap['pro_en_stock'],
                'precio_min' => $cheap['pro_precio'],
                'precio_max' => $expensive['pro_precio'],
                'ahorro' => $saving,
                'porcentaje' => $percent,
                'tienda_barata' => $cheap['tie_nombre'],
                'id_tienda_barata' => $cheap['idtiendas'],
                'tienda_cara' => $expensive['tie_nombre'],
                'id_tienda_cara' => $expensive['idtiendas'],
                'total_tiendas' => count($offers),
                'ofertas' => $offers,
            ];
        }

        usort($gaps, static function (array $a, array $b): int {
            if ($a['total_tiendas'] !== $b['total_tiendas']) {
                return $b['total_tiendas'] <=> $a['total_tiendas'];
            }

            if ($a['ahorro'] === $b['ahorro']) {
                return strcasecmp((string) $a['pro_nombre'], (string) $b['pro_nombre']);
            }

            return $b['ahorro'] <=> $a['ahorro'];
        });

        return array_slice($gaps, 0, $limit);
    }
}

if (!function_exists('cp_get_active_offers')) {
    function cp_get_active_offers(PDO $pdo, int $limit = 18): array
    {
        $limit = max(1, $limit);

        try {
            $stmt = $pdo->prepare("
                SELECT
                    p.idproductos,
                    p.pro_nombre,
                    p.pro_marca,
                    p.pro_imagen,
                    p.pro_precio,
                    p.pro_precio_anterior,
                    p.pro_en_stock,
                    p.pro_url,
                    COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo,
                    t.idtiendas,
                    t.tie_nombre,
                    c.cat_nombre,
                    ROUND((1 - p.pro_precio / p.pro_precio_anterior) * 100) AS pct,
                    (p.pro_precio_anterior - p.pro_precio) AS ahorro
                FROM productos p
                INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
                LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
                WHERE p.pro_activo = 1
                  AND p.pro_precio > 0
                  AND p.pro_precio_anterior > p.pro_precio
                  AND p.pro_precio >= p.pro_precio_anterior * 0.1
                ORDER BY pct DESC, ahorro DESC, p.pro_nombre ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $offers = [];
        foreach ($rows as $row) {
            $price = (float) ($row['pro_precio'] ?? 0);
            $oldPrice = (float) ($row['pro_precio_anterior'] ?? 0);
            if ($price <= 0 || $oldPrice <= $price) {
                continue;
            }

            $pct = (int) ($row['pct'] ?? 0);
            $saving = (float) ($row['ahorro'] ?? max(0, $oldPrice - $price));
            if ($pct <= 0) {
                $pct = (int) round((($oldPrice - $price) / $oldPrice) * 100);
            }

            $offers[] = [
                'idproductos' => (int) ($row['idproductos'] ?? 0),
                'pro_nombre' => (string) ($row['pro_nombre'] ?? 'Producto'),
                'pro_marca' => trim((string) ($row['pro_marca'] ?? '')),
                'pro_imagen' => $row['pro_imagen'] ?? null,
                'pro_precio' => $price,
                'pro_precio_anterior' => $oldPrice,
                'pro_en_stock' => (int) ($row['pro_en_stock'] ?? 0),
                'pro_url' => trim((string) ($row['pro_url'] ?? '')),
                'grupo' => (string) ($row['grupo'] ?? $row['pro_nombre'] ?? 'Producto'),
                'idtiendas' => (int) ($row['idtiendas'] ?? 0),
                'tie_nombre' => (string) ($row['tie_nombre'] ?? 'Tienda'),
                'cat_nombre' => trim((string) ($row['cat_nombre'] ?? '')),
                'pct' => max(1, $pct),
                'ahorro' => $saving,
                'tone' => function_exists('cp_store_tone')
                    ? cp_store_tone((string) ($row['tie_nombre'] ?? ''))
                    : 'other',
            ];
        }

        return $offers;
    }
}

if (isset($_GET['clear_history']) && (string) $_GET['clear_history'] === '1') {
    cp_clear_session_search_history();
    cp_redirect_without_clear_history('index.php');
}

if (isset($_GET['remove_history']) && (string) $_GET['remove_history'] !== '') {
    cp_remove_session_search_item((string) $_GET['remove_history']);
    $params = $_GET;
    unset($params['remove_history']);
    $queryString = http_build_query($params);
    $redirectUrl = 'index.php' . ($queryString !== '' ? '?' . $queryString : '');
    header('Location: ' . $redirectUrl);
    exit;
}

$recentSearches = array_values(array_slice(array_filter(array_map(
    static fn ($value) => trim((string) $value),
    $_SESSION['search_history'] ?? []
)), 0, 8));

$where = ['p.pro_activo = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(p.pro_nombre LIKE :q0 OR p.pro_descripcion LIKE :q1 OR p.pro_marca LIKE :q2 OR t.tie_nombre LIKE :q3 OR c.cat_nombre LIKE :q4)';
    for ($i = 0; $i < 5; $i++) {
        $params[":q{$i}"] = '%' . $q . '%';
    }
}

if ($categoriaId > 0) {
    $where[] = 'p.categorias_idcategorias = :categoria';
    $params[':categoria'] = $categoriaId;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
        p.idproductos,
        p.pro_nombre,
        p.pro_descripcion,
        p.pro_marca,
        p.pro_precio,
        p.pro_precio_anterior,
        p.pro_imagen,
        p.pro_url,
        p.pro_en_stock,
        p.pro_fecha_scraping,
        t.idtiendas,
        t.tie_nombre,
        c.cat_nombre,
        (
            SELECT COUNT(*)
            FROM historial_precios hp
            WHERE hp.productos_idproductos = p.idproductos
        ) AS total_historial,
        (
            SELECT COUNT(*)
            FROM productos_precios pp
            WHERE pp.productos_idproductos = p.idproductos
              AND pp.prop_estado = 'activo'
        ) AS total_ofertas
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE {$whereSql}
    ORDER BY " . sort_sql($sort) . "
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

$isProductsPartial = (string) ($_GET['partial'] ?? '') === 'products';

if ($isProductsPartial) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
} else {
$featuredStoreKeys = ['inverfin', 'computex', 'full office'];
$featuredStoreMeta = [
    'inverfin' => ['tone' => 'inverfin', 'focus' => 'Tecnología', 'icon' => 'cpu'],
    'computex' => ['tone' => 'computex', 'focus' => 'Informática', 'icon' => 'laptop'],
    'full office' => ['tone' => 'office', 'focus' => 'Oficina', 'icon' => 'briefcase'],
];

$stores = $pdo->query("
    SELECT
        t.idtiendas,
        t.tie_nombre,
        t.tie_descripcion,
        t.tie_logo,
        t.tie_ubicacion,
        t.tie_url,
        COUNT(p.idproductos) AS total_productos,
        MAX(p.pro_fecha_scraping) AS ultima_actualizacion
    FROM tiendas t
    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas AND p.pro_activo = 1
    WHERE
        LOWER(t.tie_nombre) LIKE '%inverfin%'
        OR LOWER(t.tie_nombre) LIKE '%computex%'
        OR LOWER(t.tie_nombre) LIKE '%full office%'
    GROUP BY t.idtiendas, t.tie_nombre, t.tie_descripcion, t.tie_logo, t.tie_ubicacion, t.tie_url
")->fetchAll();

$sortedStores = [];
foreach ($featuredStoreKeys as $featuredKey) {
    foreach ($stores as $store) {
        $nameKey = mb_strtolower(trim((string) ($store['tie_nombre'] ?? '')), 'UTF-8');
        if ($nameKey === $featuredKey || str_contains($nameKey, $featuredKey)) {
            $meta = $featuredStoreMeta[$featuredKey];
            $store['_tone'] = $meta['tone'];
            $store['_focus'] = $meta['focus'];
            $store['_icon'] = $meta['icon'];
            $sortedStores[] = $store;
            break;
        }
    }
}
$stores = $sortedStores;

$recentlyViewed = cp_get_recently_viewed_products($pdo, $currentUserId, $currentSessionId, 5);
$trendingProducts = cp_get_trending_products($pdo, 7, 5);
$mostSearchedProducts = cp_get_most_searched_products($pdo, 30, 5, $recentSearches);
$bestPriceGaps = cp_get_best_price_gaps($pdo, 5000);
$activeOffers = cp_get_active_offers($pdo, 24);

render_head('Inicio');
render_navbar('home');

$activityProductUrl = static function (int $productId) use ($q): string {
    return 'producto.php?id=' . $productId . ($q !== '' ? '&q=' . rawurlencode($q) : '');
};

$renderActivityItems = static function (array $items, string $metricKey, string $metricLabel, string $metricSingular, string $emptyMessage) use ($activityProductUrl): void {
    if (!$items) {
        echo '<div class="activity-empty">';
        echo lucide_icon('inbox', 'activity-empty-icon', 22);
        echo '<p>' . e($emptyMessage) . '</p>';
        echo '</div>';
        return;
    }

    $items = array_values(array_slice($items, 0, 5));
    $featured = array_shift($items);
    $featuredId = (int) ($featured['idproductos'] ?? 0);
    $featuredName = (string) ($featured['pro_nombre'] ?? 'Producto');
    $featuredStore = (string) ($featured['tie_nombre'] ?? 'Catálogo');
    $featuredMetric = (int) ($featured[$metricKey] ?? 0);

    echo '<a class="activity-featured" href="' . e($activityProductUrl($featuredId)) . '">';
    echo '  <span class="activity-featured-media">';
    echo '    <img src="' . e(image_url($featured['pro_imagen'] ?? null, $featuredName)) . '" alt="' . e($featuredName) . '">';
    echo '    <span class="activity-featured-rank">01</span>';
    echo '  </span>';
    echo '  <span class="activity-featured-copy">';
    echo '    <span class="activity-store">' . e($featuredStore) . '</span>';
    echo '    <span class="activity-name">' . e($featuredName) . '</span>';
    echo '    <span class="activity-featured-meta">';
    echo '      <span class="activity-price">' . gs_precio($featured['pro_precio'] ?? null) . '</span>';
    echo '      <span class="activity-metric">';
    if ($featuredMetric > 0) {
        echo number_format($featuredMetric, 0, ',', '.') . ' ' . e($featuredMetric === 1 ? $metricSingular : $metricLabel);
    } else {
        echo '&nbsp;';
    }
    echo '</span>';
    echo '    </span>';
    echo '  </span>';
    echo '</a>';

    if (!$items) {
        return;
    }

    echo '<ol class="activity-list">';
    $rank = 2;
    foreach ($items as $item) {
        $productId = (int) ($item['idproductos'] ?? 0);
        $name = (string) ($item['pro_nombre'] ?? 'Producto');
        $store = (string) ($item['tie_nombre'] ?? 'Catálogo');
        $metricValue = (int) ($item[$metricKey] ?? 0);

        echo '<li>';
        echo '  <a class="activity-item" href="' . e($activityProductUrl($productId)) . '">';
        echo '    <span class="activity-rank">' . str_pad((string) $rank, 2, '0', STR_PAD_LEFT) . '</span>';
        echo '    <img class="activity-item-thumb" src="' . e(image_url($item['pro_imagen'] ?? null, $name)) . '" alt="' . e($name) . '">';
        echo '    <span class="activity-item-copy">';
        echo '      <span class="activity-name">' . e($name) . '</span>';
        echo '      <span class="activity-store">' . e($store) . '</span>';
        echo '    </span>';
        echo '    <span class="activity-item-aside">';
        echo '      <span class="activity-price">' . gs_precio($item['pro_precio'] ?? null) . '</span>';
        if ($metricValue > 0) {
            echo '<span class="activity-metric">' . number_format($metricValue, 0, ',', '.') . ' ' . e($metricValue === 1 ? $metricSingular : $metricLabel) . '</span>';
        }
        echo '    </span>';
        echo '  </a>';
        echo '</li>';
        $rank++;
    }
    echo '</ol>';
};
} // fin else (!$isProductsPartial) — consultas y chrome del home completo

if (!$isProductsPartial):
?>

<section
  id="home-live-section"
  class="home-live position-relative<?= $q !== '' ? ' is-active' : '' ?>"
  aria-labelledby="home-live-title"
  <?= $q === '' ? 'hidden' : '' ?>
>
  <div class="container">
    <div class="hl-panel" data-live-panel aria-busy="false">
      <header class="hl-head">
        <div class="hl-head-main">
          <p class="products-kicker hl-kicker">
            <span class="hl-pulse" aria-hidden="true"></span>
            Vista rápida en vivo
          </p>
          <h2 id="home-live-title" class="section-title hl-title">
            Resultados para <span class="hl-query" data-live-query>“<?= e($q) ?>”</span>
          </h2>
        </div>
        <a class="hl-all" href="buscar.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>" data-live-all>
          Ver todos
          <?= lucide_icon('arrow-right', '', 15) ?>
        </a>
      </header>

      <dl class="sr-kpis" aria-label="Resumen de la vista rápida">
        <div class="sr-kpi" data-kpi="results">
          <dt>Resultados</dt>
          <dd id="home-live-count" data-live-stat="results">0</dd>
          <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('layout-grid', '', 28) ?></span>
        </div>

        <div class="sr-kpi" data-kpi="price">
          <dt>Desde</dt>
          <dd data-live-stat="price">—</dd>
          <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('tag', '', 28) ?></span>
        </div>

        <div class="sr-kpi" data-kpi="offers">
          <dt>Ofertas</dt>
          <dd data-live-stat="offers">0</dd>
          <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('layers', '', 28) ?></span>
        </div>

        <div class="sr-kpi" data-kpi="stock">
          <dt>Con stock</dt>
          <dd data-live-stat="stock">0</dd>
          <span class="sr-kpi-icon" aria-hidden="true"><?= lucide_icon('package', '', 28) ?></span>
        </div>
      </dl>

      <p id="home-live-state" class="visually-hidden" role="status" aria-live="polite"></p>

      <div class="hl-board" id="home-live-results" data-live-layout="board"></div>
    </div>
  </div>
</section>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="hero hero-home position-relative overflow-hidden">
  <div class="hero-band">
    <div class="hero-main">
      <p class="hero-kicker">Comparador de precios · Caacupé</p>

      <h1 class="hero-title">
        Mismo producto,
        <span class="hero-title-accent">distintas tiendas.</span>
      </h1>

      <p class="hero-lead">
        Busca lo que necesitas, compara valores entre comercios de la ciudad y guarda tus preferidos para cuando decidas salir.
      </p>
    </div>

    <figure class="hero-sim" aria-hidden="true">
      <ol class="hero-sim-steps">
        <li class="is-step-1"><span>1</span>Busca</li>
        <li class="is-step-2"><span>2</span>Compara</li>
        <li class="is-step-3"><span>3</span>Elige</li>
      </ol>

      <div class="hero-sim-search">
        <span class="hero-sim-ico"><?= lucide_icon('search', '', 15) ?></span>
        <span class="hero-sim-query">Auriculares bluetooth</span>
        <span class="hero-sim-caret"></span>
      </div>

      <ol class="hero-sim-offers">
        <?php foreach ([
            ['Tienda A', 150000, 'Mejor precio', 'is-best'],
            ['Tienda B', 168000, '+12%', ''],
            ['Tienda C', 186000, '+24%', ''],
        ] as $i => [$simStore, $simPrice, $simTag, $simClass]): ?>
          <li class="<?= $simClass ?>" style="--sim-delay: <?= $i * 0.18 ?>s">
            <span class="hero-sim-store"><span class="hero-sim-ico"><?= lucide_icon('store', '', 14) ?></span><?= $simStore ?></span>
            <span class="hero-sim-price"><?= gs($simPrice) ?></span>
            <span class="hero-sim-tag"><?php if ($simClass): ?><?= lucide_icon('badge-check', '', 13) ?><?php endif; ?><?= $simTag ?></span>
          </li>
        <?php endforeach; ?>
      </ol>

      <figcaption class="hero-sim-foot">
        <span class="hero-sim-saved"><span class="hero-sim-ico"><?= lucide_icon('heart', '', 14) ?></span>Guardado en favoritos</span>
      </figcaption>
    </figure>

    <div class="hero-actions">
      <?php if ($userLogged): ?>
        <a href="favoritos.php" class="hero-link is-strong">
          <?= lucide_icon('heart', '', 16) ?>Mis favoritos
        </a>
      <?php else: ?>
        <a href="#categorias" class="hero-link is-strong">
          <?= lucide_icon('layout-grid', '', 16) ?>Ver categorías
        </a>
      <?php endif; ?>
      <a href="buscar.php" class="hero-link">
        Buscar en el catálogo<?= lucide_icon('arrow-right', '', 15) ?>
      </a>
    </div>

    <dl class="hero-facts">
      <?php foreach ([
          ['store', 'Tiendas', $stats['tiendas'], 'comercios locales', null],
          ['package', 'Productos', $stats['productos'], 'publicados', '#productos'],
          ['layout-grid', 'Categorías', $stats['categorias'], 'para explorar', '#categorias'],
          ['badge-percent', 'Ofertas', $stats['ofertas'], 'con precio rebajado', $activeOffers ? '#ofertas' : null],
      ] as [$factIcon, $factLabel, $factValue, $factCaption, $factHref]): ?>
        <div<?= $factHref ? ' class="is-linked"' : '' ?>>
          <?php if ($factHref): ?>
            <a class="hero-fact-link" href="<?= e($factHref) ?>" aria-label="Ir a <?= e($factLabel) ?>"></a>
          <?php endif; ?>
          <dt><span class="hero-fact-icon"><?= lucide_icon($factIcon, '', 15) ?></span><?= e($factLabel) ?></dt>
          <dd><?= number_format((float) $factValue, 0, ',', '.') ?><small><?= e($factCaption) ?></small></dd>
        </div>
      <?php endforeach; ?>
    </dl>
  </div>
</section>

<main>
  <section id="categorias" class="categories-section">
    <div class="container">
      <?php if ($categorias): ?>
        <?php
          $categoryPageSize = 5;
          $categoryTotal = count($categorias);
          $categoryPages = (int) max(1, ceil($categoryTotal / $categoryPageSize));
          $categoryPage = 0;
          if ($categoriaId > 0) {
              foreach ($categorias as $index => $categoria) {
                  if ((int) $categoria['idcategorias'] === $categoriaId) {
                      $categoryPage = (int) floor($index / $categoryPageSize);
                      break;
                  }
              }
          }
          $categoryPageStart = $categoryPage * $categoryPageSize;
          $categoryBentoLayouts = ['left', 'right', 'top', 'center', 'bottom'];
          $categoryBentoLayout = $categoryBentoLayouts[$categoryPage % count($categoryBentoLayouts)];

          $categoryCountLabel = static function (array $categoria): string {
              $count = (int) ($categoria['total_productos'] ?? 0);
              if ($count <= 0) {
                  return '';
              }

              return number_format($count, 0, ',', '.') . ' ' . ($count === 1 ? 'producto' : 'productos');
          };

          $categoryIconKey = static function (string $name): string {
              $key = mb_strtolower(trim($name), 'UTF-8');
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
                      return $value;
                  }
              }

              return 'layout-grid';
          };
        ?>
        <div class="categories-pager" data-categories-pager data-page-size="<?= (int) $categoryPageSize ?>" data-autoplay-ms="6200">
          <div class="section-header categories-header">
            <div>
              <p class="categories-kicker">Explorar por rubro</p>
              <h2 class="section-title mb-1">Categorías</h2>
              <p class="section-subtitle mb-0">Selecciona una categoría para ver los productos disponibles.</p>
            </div>
            <div class="categories-toolbar">
              <?php if ($categoriaId > 0): ?>
                <a class="categories-clear" href="index.php#categorias">Ver todas</a>
              <?php endif; ?>
              <?php if ($categoryPages > 1): ?>
                <div class="categories-nav" role="group" aria-label="Navegar categorías">
                  <button type="button" class="categories-nav-btn" data-categories-prev aria-label="Categorías anteriores">
                    <?= lucide_icon('chevron-left', '', 18) ?>
                  </button>
                  <p class="categories-nav-status" data-categories-status aria-live="polite" aria-label="Página <?= (int) ($categoryPage + 1) ?> de <?= (int) $categoryPages ?>">
                    <span class="categories-nav-current" data-categories-current><?= (int) ($categoryPage + 1) ?></span>
                    <span class="categories-nav-total">de <?= (int) $categoryPages ?></span>
                  </p>
                  <button type="button" class="categories-nav-btn" data-categories-next aria-label="Categorías siguientes">
                    <?= lucide_icon('chevron-right', '', 18) ?>
                  </button>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="categories-bento-stage">
          <ul class="categories-bento" data-bento-layout="<?= e($categoryBentoLayout) ?>">
            <?php foreach ($categorias as $index => $categoria): ?>
              <?php
                $id = (int) $categoria['idcategorias'];
                $name = (string) $categoria['cat_nombre'];
                $iconKey = $categoryIconKey($name);
                $meta = $categoryCountLabel($categoria);
                $isActive = $categoriaId === $id;
                $slotOffset = ($index - $categoryPageStart + $categoryTotal) % $categoryTotal;
                $isVisible = $slotOffset < $categoryPageSize;
                $slot = $isVisible ? $slotOffset : -1;
                $isFeatured = $slot === 0;
              ?>
              <li
                data-category-slide
                class="<?= $isVisible ? 'is-visible' : '' ?><?= $isFeatured ? ' is-featured' : '' ?><?= $slot >= 0 ? ' is-slot-' . $slot : '' ?>"
              >
                <a
                  class="category-card<?= $isActive ? ' is-active' : '' ?>"
                  href="index.php?categoria=<?= $id ?>#productos"
                  data-icon="<?= e($iconKey) ?>"
                >
                  <span class="category-card-watermark" aria-hidden="true"><?= category_lucide_icon($name, '', 88) ?></span>
                  <span class="category-card-icon"><?= category_lucide_icon($name, '', 20) ?></span>
                  <span class="category-card-copy">
                    <span class="category-card-name"><?= e($name) ?></span>
                    <?php if ($meta !== ''): ?>
                      <span class="category-card-meta"><?= e($meta) ?></span>
                    <?php endif; ?>
                    <span class="category-card-cta">Ver productos <?= lucide_icon('arrow-right', '', 16) ?></span>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
          </div>
        </div>
      <?php else: ?>
        <div class="section-header categories-header">
          <div>
            <p class="categories-kicker">Explorar por rubro</p>
            <h2 class="section-title mb-1">Categorías</h2>
            <p class="section-subtitle mb-0">Selecciona una categoría para ver los productos disponibles.</p>
          </div>
        </div>
        <div class="empty-state">No hay categorías disponibles por ahora.</div>
      <?php endif; ?>
    </div>
  </section>

  <section class="activity-section" id="actividad" aria-labelledby="activity-title">
    <div class="container">
      <div class="section-header activity-header">
        <div>
          <p class="activity-kicker">Tu recorrido</p>
          <h2 id="activity-title" class="section-title mb-1">Tu actividad reciente</h2>
          <p class="section-subtitle mb-0">Revisa lo que consultaste y las tendencias de esta semana.</p>
        </div>
      </div>

      <div class="activity-board">
        <article class="activity-lane" data-tone="recent">
          <header class="activity-lane-head">
            <span class="activity-lane-mark" aria-hidden="true"><?= lucide_icon('history', '', 88) ?></span>
            <span class="activity-lane-icon"><?= lucide_icon('history', '', 20) ?></span>
            <div class="activity-lane-copy">
              <h3 class="activity-lane-title">Vistos recientemente</h3>
              <p class="activity-lane-caption">Tu historial de consultas</p>
            </div>
            <span class="activity-lane-count"><?= count($recentlyViewed) ?></span>
          </header>
          <?php $renderActivityItems($recentlyViewed, 'total_vistas', 'vistas', 'vista', 'Todavía no hay productos vistos.'); ?>
        </article>

        <article class="activity-lane" data-tone="trend">
          <header class="activity-lane-head">
            <span class="activity-lane-mark" aria-hidden="true"><?= lucide_icon('trending-up', '', 88) ?></span>
            <span class="activity-lane-icon"><?= lucide_icon('trending-up', '', 20) ?></span>
            <div class="activity-lane-copy">
              <h3 class="activity-lane-title">Tendencias</h3>
              <p class="activity-lane-caption">Lo más visto esta semana</p>
            </div>
            <span class="activity-lane-count"><?= count($trendingProducts) ?></span>
          </header>
          <?php $renderActivityItems($trendingProducts, 'total_vistas', 'vistas', 'vista', 'Aún no hay tendencias para mostrar.'); ?>
        </article>

        <article class="activity-lane" data-tone="search">
          <header class="activity-lane-head">
            <span class="activity-lane-mark" aria-hidden="true"><?= lucide_icon('search', '', 88) ?></span>
            <span class="activity-lane-icon"><?= lucide_icon('search', '', 20) ?></span>
            <div class="activity-lane-copy">
              <h3 class="activity-lane-title">Los más buscados</h3>
              <p class="activity-lane-caption">Las consultas más frecuentes</p>
            </div>
            <span class="activity-lane-count"><?= count($mostSearchedProducts) ?></span>
          </header>
          <?php $renderActivityItems($mostSearchedProducts, 'total_clicks', 'búsquedas', 'búsqueda', 'Todavía no hay búsquedas registradas.'); ?>
        </article>
      </div>
    </div>
  </section>

<?php
endif; // !$isProductsPartial — hero, categorías y actividad

      $currentCategoryName = '';
      if ($categoriaId > 0) {
          foreach ($categorias as $categoria) {
              if ((int) ($categoria['idcategorias'] ?? 0) === $categoriaId) {
                  $currentCategoryName = (string) ($categoria['cat_nombre'] ?? '');
                  break;
              }
          }
      }

      $resultFrom = $totalProducts > 0 ? $offset + 1 : 0;
      $resultTo = $totalProducts > 0 ? min($offset + count($products), $totalProducts) : 0;
      $pageMinPrice = null;
      $pageMaxPrice = null;
      $pageDeals = 0;
      $pageInStock = 0;

      foreach ($products as $productRow) {
          $priceValue = (float) $productRow['pro_precio'];
          if ($priceValue > 0) {
              $pageMinPrice = $pageMinPrice === null ? $priceValue : min($pageMinPrice, $priceValue);
              $pageMaxPrice = $pageMaxPrice === null ? $priceValue : max($pageMaxPrice, $priceValue);
          }

          $oldPriceValue = $productRow['pro_precio_anterior'] !== null ? (float) $productRow['pro_precio_anterior'] : null;
          if ($priceValue > 0 && $oldPriceValue && $oldPriceValue > $priceValue) {
              $pageDeals++;
          }

          if ((int) $productRow['pro_en_stock'] === 1) {
              $pageInStock++;
          }
      }

      $buildCatalogUrl = static function (array $overrides = []) use ($q, $categoriaId, $sort, $page): string {
          $query = [
              'q' => array_key_exists('q', $overrides) ? $overrides['q'] : $q,
              'categoria' => array_key_exists('categoria', $overrides) ? $overrides['categoria'] : $categoriaId,
              'orden' => array_key_exists('orden', $overrides) ? $overrides['orden'] : $sort,
              'page' => array_key_exists('page', $overrides) ? $overrides['page'] : $page,
          ];

          if (trim((string) $query['q']) === '') {
              unset($query['q']);
          }
          if ((int) $query['categoria'] <= 0) {
              unset($query['categoria']);
          }
          if (($query['orden'] ?? '') === 'recientes' || ($query['orden'] ?? '') === '') {
              unset($query['orden']);
          }
          if ((int) ($query['page'] ?? 1) <= 1) {
              unset($query['page']);
          }

          $qs = http_build_query($query);
          return 'index.php' . ($qs !== '' ? '?' . $qs : '') . '#productos';
      };

      $formatFreshness = static function (?string $date): string {
          if ($date === null || trim($date) === '') {
              return 'Sin fecha';
          }

          $ts = strtotime($date);
          if ($ts === false) {
              return 'Sin fecha';
          }

          $diff = time() - $ts;
          if ($diff < 0) {
              $diff = 0;
          }
          if ($diff < 3600) {
              return 'Hace minutos';
          }
          if ($diff < 86400) {
              return 'Hoy';
          }
          if ($diff < 172800) {
              return 'Ayer';
          }

          $days = (int) floor($diff / 86400);
          if ($days < 7) {
              return 'Hace ' . $days . ' días';
          }

          return date('d/m/Y', $ts);
      };

      $hasFilters = $q !== '' || $categoriaId > 0;
      $favoriteRedirect = $buildCatalogUrl();

if (!$isProductsPartial):
?>
  <section id="productos" class="products-section" aria-labelledby="products-title">
    <div class="container">
      <div class="section-header products-header">
        <div>
          <p class="products-kicker">Comparar precios</p>
          <h2 id="products-title" class="section-title mb-1">Productos disponibles</h2>
          <p class="section-subtitle mb-0">Explora el catálogo completo y ordénalo por precio o por nombre.</p>
        </div>
      </div>
<?php endif; ?>

      <div class="products-layout" data-products-catalog>
        <aside class="products-rail" aria-label="Resumen y orden del catálogo">
          <div class="products-rail-head">
            <span class="products-rail-icon"><?= lucide_icon('list-filter', '', 20) ?></span>
            <div>
              <p class="products-rail-title">Catálogo</p>
              <p class="products-rail-caption">
                <?php if ($totalProducts > 0): ?>
                  Mostrando <?= number_format($resultFrom, 0, ',', '.') ?>–<?= number_format($resultTo, 0, ',', '.') ?> de <?= number_format($totalProducts, 0, ',', '.') ?>
                <?php else: ?>
                  Sin resultados para esta búsqueda
                <?php endif; ?>
              </p>
            </div>
          </div>

          <?php if ($products): ?>
            <dl class="products-insight">
              <div>
                <dt>Rango en esta página</dt>
                <dd><?= $pageMinPrice !== null ? gs($pageMinPrice) : '—' ?><?= $pageMaxPrice !== null && $pageMaxPrice !== $pageMinPrice ? ' – ' . gs($pageMaxPrice) : '' ?></dd>
              </div>
              <div>
                <dt>Con baja de precio</dt>
                <dd><?= number_format($pageDeals, 0, ',', '.') ?></dd>
              </div>
              <div>
                <dt>En stock</dt>
                <dd><?= number_format($pageInStock, 0, ',', '.') ?> / <?= number_format(count($products), 0, ',', '.') ?></dd>
              </div>
            </dl>
          <?php endif; ?>

          <nav class="products-sort" aria-label="Ordenar productos">
            <p class="products-rail-label">Ordenar</p>
            <div class="products-sort-list">
              <?php foreach (active_sort_options() as $value => $label): ?>
                <?php if ($value === 'recientes') continue; ?>
                <a
                  class="products-sort-link<?= $sort === $value ? ' is-active' : '' ?>"
                  href="<?= e($buildCatalogUrl(['orden' => $value, 'page' => 1])) ?>"
                  <?= $sort === $value ? 'aria-current="true"' : '' ?>
                >
                  <?= e($label) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </nav>

          <?php if ($hasFilters): ?>
            <div class="products-filters">
              <p class="products-rail-label">Filtros activos</p>
              <div class="products-chips">
                <?php if ($q !== ''): ?>
                  <a class="products-chip" href="<?= e($buildCatalogUrl(['q' => '', 'page' => 1])) ?>">
                    <?= lucide_icon('search', '', 14) ?>
                    <span><?= e($q) ?></span>
                    <?= lucide_icon('x', '', 14) ?>
                  </a>
                <?php endif; ?>
                <?php if ($categoriaId > 0): ?>
                  <a class="products-chip" href="<?= e($buildCatalogUrl(['categoria' => 0, 'page' => 1])) ?>">
                    <?= lucide_icon('tag', '', 14) ?>
                    <span><?= e($currentCategoryName !== '' ? $currentCategoryName : 'Categoría') ?></span>
                    <?= lucide_icon('x', '', 14) ?>
                  </a>
                <?php endif; ?>
              </div>
              <a class="products-clear" href="<?= e($buildCatalogUrl(['q' => '', 'categoria' => 0, 'page' => 1])) ?>">Quitar filtros</a>
            </div>
          <?php endif; ?>

          <p class="products-hint"><?= lucide_icon('info', '', 14) ?><span>Precio de la tienda indicada. Entra para ver ofertas e historial.</span></p>
        </aside>

        <div class="products-stage">
        <div class="products-board-slot">
        <div class="products-board">
          <?php if ($products): ?>
            <?php
              $tileWashPalette = [
                '#8ba4c4',
                '#c9a87a',
                '#c48b8e',
                '#8fad97',
                '#a89bc4',
                '#7eb8b0',
                '#c4a88b',
                '#9bb0c9',
              ];
              $tileWashColors = $tileWashPalette;
              $tileWashSeed = crc32('tile-wash|' . $page . '|' . $sort . '|' . $q . '|' . $categoriaId);
              mt_srand($tileWashSeed);
              for ($i = count($tileWashColors) - 1; $i > 0; $i--) {
                  $j = mt_rand(0, $i);
                  $swap = $tileWashColors[$i];
                  $tileWashColors[$i] = $tileWashColors[$j];
                  $tileWashColors[$j] = $swap;
              }
              mt_srand();
            ?>
            <?php foreach ($products as $index => $product): ?>
              <?php
                $productId = (int) $product['idproductos'];
                $productName = (string) $product['pro_nombre'];
                $productUrl = 'producto.php?id=' . $productId . ($q !== '' ? '&q=' . rawurlencode($q) : '');
                $currentPrice = (float) $product['pro_precio'];
                $oldPrice = $product['pro_precio_anterior'] !== null ? (float) $product['pro_precio_anterior'] : null;
                $discount = ($currentPrice > 0 && $oldPrice && $oldPrice > $currentPrice) ? (int) round((($oldPrice - $currentPrice) / $oldPrice) * 100) : 0;
                $savedAmount = ($currentPrice > 0 && $oldPrice && $oldPrice > $currentPrice) ? ($oldPrice - $currentPrice) : 0;
                $isFavorite = ($favoritesEnabled && $userLogged) ? is_favorite_product($productId) : false;
                $inStock = (int) $product['pro_en_stock'] === 1;
                $offersCount = (int) $product['total_ofertas'];
                $tileTone = $discount > 0 ? 'deal' : ($inStock ? 'stock' : 'wait');
                $tileWash = $tileWashColors[$index % count($tileWashColors)];
                $catalogNumber = $offset + (int) $index + 1;
                $offerUrl = trim((string) ($product['pro_url'] ?? ''));
                $scrapedAt = (string) ($product['pro_fecha_scraping'] ?? '');
                $scrapedTs = $scrapedAt !== '' ? strtotime($scrapedAt) : false;
                $scrapedExact = $scrapedTs ? date('d/m/Y H:i', $scrapedTs) : 'Sin fecha';
              ?>
              <article
                class="product-tile clickable-card"
                data-tone="<?= e($tileTone) ?>"
                style="--tile-wash: <?= e($tileWash) ?>;"
                data-card-href="<?= e($productUrl) ?>"
                role="link"
                tabindex="0"
                aria-label="Comparar <?= e($productName) ?>"
              >
                <div class="product-tile-visual">
                  <a class="product-tile-media" href="<?= e($productUrl) ?>">
                    <img src="<?= e(image_url($product['pro_imagen'], $productName)) ?>" alt="<?= e($productName) ?>">
                    <span class="product-tile-index"><?= str_pad((string) $catalogNumber, 2, '0', STR_PAD_LEFT) ?></span>
                  </a>
                  <?php if ($favoritesEnabled && $userLogged): ?>
                    <a
                      class="product-tile-fav<?= $isFavorite ? ' is-on' : '' ?>"
                      href="<?= e(favorite_toggle_url($productId, $favoriteRedirect)) ?>"
                      data-fav-toggle
                      data-product-id="<?= (int) $productId ?>"
                      title="<?= $isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
                      aria-label="<?= $isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
                      aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
                    >
                      <?= lucide_icon('heart', $isFavorite ? 'lucide-fill' : '', 16) ?>
                    </a>
                  <?php else: ?>
                    <a class="product-tile-fav" href="login.php" title="Inicia sesión para guardar favoritos" aria-label="Inicia sesión para guardar favoritos">
                      <?= lucide_icon('heart', '', 16) ?>
                    </a>
                  <?php endif; ?>
                  <?php if ($discount > 0): ?>
                    <div class="product-tile-flags">
                      <span class="product-tile-flag is-deal">-<?= $discount ?>%</span>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="product-tile-body">
                  <div class="product-tile-top">
                    <div class="product-tile-tags">
                      <span class="product-tile-cat"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                      <?php if (!empty($product['pro_marca'])): ?>
                        <span class="product-tile-brand"><?= e($product['pro_marca']) ?></span>
                      <?php endif; ?>
                    </div>
                    <h3 class="product-tile-name">
                      <a href="<?= e($productUrl) ?>"><?= e($productName) ?></a>
                    </h3>
                  </div>

                  <div class="product-tile-foot">
                    <div class="product-tile-priceblock">
                      <div class="product-tile-cost">
                        <p class="price-now product-tile-price"><?= gs_precio($currentPrice) ?></p>
                        <?php if ($discount > 0): ?>
                          <p class="product-tile-save">
                            <span class="price-old"><?= gs($oldPrice) ?></span>
                            <span>Bajó <?= gs($savedAmount) ?></span>
                          </p>
                        <?php endif; ?>
                      </div>
                      <span class="product-tile-flag <?= $inStock ? 'is-stock' : 'is-wait' ?>" title="<?= e(stock_label($product['pro_en_stock'])) ?>"><?= e($inStock ? 'Stock' : 'Consultar') ?></span>
                    </div>

                    <div class="product-tile-facts">
                      <a class="product-tile-fact" href="tienda.php?id=<?= (int) $product['idtiendas'] ?>">
                        <?= lucide_icon('store', '', 14) ?>
                        <span><?= e($product['tie_nombre']) ?></span>
                      </a>
                      <span class="product-tile-fact" title="<?= e($scrapedExact) ?>">
                        <?= lucide_icon('clock', '', 14) ?>
                        <span><?= e($formatFreshness($scrapedAt)) ?></span>
                      </span>
                    </div>

                    <div class="product-tile-actions">
                      <a class="product-tile-cta" href="<?= e($productUrl) ?>" title="<?= $offersCount === 1 ? 'Ver detalle y comparar precios' : 'Comparar ' . number_format($offersCount, 0, ',', '.') . ' ofertas' ?>">
                        Comparar<?= $offersCount > 1 ? ' · ' . number_format($offersCount, 0, ',', '.') : '' ?> <?= lucide_icon('arrow-right', '', 15) ?>
                      </a>
                      <?php if ($offerUrl !== ''): ?>
                        <a
                          class="product-tile-external"
                          href="<?= e('go.php?' . http_build_query([
                              'id' => $productId,
                              'source' => 'inicio',
                              'click_type' => 'ir_oferta',
                              'term' => $q,
                              'target_url' => $offerUrl,
                          ])) ?>"
                          target="_blank"
                          rel="noopener noreferrer nofollow"
                        >
                          Ver oferta <?= lucide_icon('external-link', '', 14) ?>
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="products-empty">
              <?= lucide_icon('package-search', 'products-empty-icon', 28) ?>
              <h3>No hay productos para mostrar</h3>
              <p>Prueba otra categoría, cambia el orden o quita los filtros para ver el catálogo completo.</p>
              <a class="products-empty-link" href="<?= e($buildCatalogUrl(['q' => '', 'categoria' => 0, 'page' => 1])) ?>">Ver todo el catálogo</a>
            </div>
          <?php endif; ?>
        </div>
        </div>
        <?php if ($totalPages > 1): ?>
          <?php
            $visiblePages = 5;
            $half = (int) floor($visiblePages / 2);
            $startPage = max(1, $page - $half);
            $endPage = min($totalPages, $startPage + $visiblePages - 1);

            if (($endPage - $startPage + 1) < $visiblePages) {
                $startPage = max(1, $endPage - $visiblePages + 1);
            }
          ?>
          <nav class="products-pager" aria-label="Paginación de productos">
            <p class="products-pager-status">Página <?= number_format($page, 0, ',', '.') ?> de <?= number_format($totalPages, 0, ',', '.') ?></p>
            <div class="products-pager-controls">
              <?php if ($page > 1): ?>
                <a class="products-pager-btn" href="<?= e($buildCatalogUrl(['page' => 1])) ?>" aria-label="Primera página" title="Ir al inicio"><?= lucide_icon('chevrons-left', '', 16) ?></a>
                <a class="products-pager-btn" href="<?= e($buildCatalogUrl(['page' => $page - 1])) ?>" aria-label="Página anterior" title="Página anterior"><?= lucide_icon('chevron-left', '', 16) ?></a>
              <?php else: ?>
                <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevrons-left', '', 16) ?></span>
                <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevron-left', '', 16) ?></span>
              <?php endif; ?>

              <div class="products-pager-pages">
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                  <a
                    class="products-pager-num<?= $i === $page ? ' is-active' : '' ?>"
                    href="<?= e($buildCatalogUrl(['page' => $i])) ?>"
                    <?= $i === $page ? 'aria-current="page"' : '' ?>
                  >
                    <?= $i ?>
                  </a>
                <?php endfor; ?>
              </div>

              <?php if ($page < $totalPages): ?>
                <a class="products-pager-btn" href="<?= e($buildCatalogUrl(['page' => $page + 1])) ?>" aria-label="Página siguiente" title="Página siguiente"><?= lucide_icon('chevron-right', '', 16) ?></a>
                <a class="products-pager-btn" href="<?= e($buildCatalogUrl(['page' => $totalPages])) ?>" aria-label="Última página" title="Ir al final"><?= lucide_icon('chevrons-right', '', 16) ?></a>
              <?php else: ?>
                <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevron-right', '', 16) ?></span>
                <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevrons-right', '', 16) ?></span>
              <?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>
        </div>
      </div>
<?php
if ($isProductsPartial) {
    exit;
}
?>
    </div>
  </section>

  <?php if ($bestPriceGaps): ?>
  <?php
    $dealPageSize = 6;
    $dealPages = (int) max(1, ceil(count($bestPriceGaps) / $dealPageSize));
    $dealFavoriteRedirect = 'index.php#conviene';
  ?>
  <section
    class="deals-section"
    id="conviene"
    aria-labelledby="deals-title"
  >
    <div class="container">
      <div class="deals-pager" data-deals-pager data-page-size="<?= (int) $dealPageSize ?>">
        <div class="section-header deals-header">
          <div>
            <p class="deals-kicker">Comparar ofertas</p>
            <h2 id="deals-title" class="section-title mb-1">Elige el mejor precio</h2>
            <p class="section-subtitle mb-0">Productos con la mayor diferencia de precio entre una tienda y otra.</p>
          </div>
          <?php if ($dealPages > 1): ?>
            <div class="deals-nav" role="group" aria-label="Navegar ofertas">
              <button type="button" class="deals-nav-btn" data-deals-prev aria-label="Productos anteriores">
                <?= lucide_icon('chevron-left', '', 18) ?>
              </button>
              <button type="button" class="deals-nav-btn" data-deals-next aria-label="Productos siguientes">
                <?= lucide_icon('chevron-right', '', 18) ?>
              </button>
              <p class="deals-nav-status" data-deals-status aria-live="polite" aria-label="Página 1 de <?= (int) $dealPages ?>">
                <span class="deals-nav-current" data-deals-current>1</span>
                <span class="deals-nav-total">de <?= (int) $dealPages ?></span>
              </p>
            </div>
          <?php endif; ?>
        </div>

        <div class="deals-board">
          <div class="deals-carousel" data-deals-carousel>
            <button
              type="button"
              class="deals-carousel-btn deals-carousel-btn--prev"
              data-deals-carousel-prev
              aria-label="Productos anteriores"
              hidden
            >
              <?= lucide_icon('chevron-left', '', 18) ?>
            </button>
            <div class="deals-list" role="list" data-deals-track>
              <?php foreach ($bestPriceGaps as $index => $deal): ?>
                <?php
                  $dealId = (int) ($deal['idproductos'] ?? 0);
                  $dealName = (string) ($deal['pro_nombre'] ?? 'Producto');
                  $dealGroup = (string) ($deal['grupo'] ?? $dealName);
                  $dealUrl = 'producto.php?grupo=' . rawurlencode($dealGroup);
                  if ($q !== '') {
                      $dealUrl .= '&q=' . rawurlencode($q);
                  }
                  $dealInStock = (int) ($deal['pro_en_stock'] ?? 0) === 1;
                  $isDealFavorite = ($favoritesEnabled && $userLogged && $dealId > 0)
                      ? is_favorite_product($dealId)
                      : false;
                  $dealOffers = [];
                  foreach ($deal['ofertas'] as $offer) {
                      $offerPrice = is_array($offer) ? (float) ($offer['pro_precio'] ?? 0) : 0.0;
                      if ($offerPrice <= 0) {
                          continue;
                      }

                      $storeName = (string) ($offer['tie_nombre'] ?? 'Tienda');
                      $dealOffers[] = [
                          'label' => $storeName,
                          'tone' => (string) ($offer['tone'] ?? cp_store_tone($storeName)),
                          'price' => $offerPrice,
                      ];
                  }

                  $storeCount = count($dealOffers);
                  $cheapOffer = $dealOffers[0] ?? null;
                  $expensiveOffer = $dealOffers[$storeCount - 1] ?? null;
                  $saving = (float) ($deal['ahorro'] ?? 0);
                  $storeCountLabel = $storeCount === 1
                      ? 'En 1 tienda'
                      : 'En ' . number_format($storeCount, 0, ',', '.') . ' tiendas';
                ?>
                <article
                  class="deals-card clickable-card<?= $index < $dealPageSize ? ' is-visible' : '' ?>"
                  data-deal-row
                  data-card-href="<?= e($dealUrl) ?>"
                  role="listitem"
                  tabindex="0"
                  aria-label="Comparar <?= e($dealName) ?> en <?= e(implode(', ', array_map(static fn (array $row): string => (string) $row['label'], $dealOffers))) ?>"
                >
                  <span class="deals-card-main">
                    <span class="deals-card-media">
                      <img src="<?= e(image_url($deal['pro_imagen'] ?? null, $dealName)) ?>" alt="">
                      <span
                        class="deals-card-flag <?= $dealInStock ? 'is-stock' : 'is-wait' ?>"
                        title="<?= e(stock_label($deal['pro_en_stock'] ?? 0)) ?>"
                      ><?= e($dealInStock ? 'Stock' : 'Consultar') ?></span>
                      <?php if ($favoritesEnabled && $userLogged && $dealId > 0): ?>
                        <a
                          class="deals-card-fav<?= $isDealFavorite ? ' is-on' : '' ?>"
                          href="<?= e(favorite_toggle_url($dealId, $dealFavoriteRedirect)) ?>"
                          data-fav-toggle
                          data-product-id="<?= (int) $dealId ?>"
                          title="<?= $isDealFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
                          aria-label="<?= $isDealFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
                          aria-pressed="<?= $isDealFavorite ? 'true' : 'false' ?>"
                        >
                          <?= lucide_icon('heart', $isDealFavorite ? 'lucide-fill' : '', 16) ?>
                        </a>
                      <?php else: ?>
                        <a class="deals-card-fav" href="login.php" title="Inicia sesión para guardar favoritos" aria-label="Inicia sesión para guardar favoritos">
                          <?= lucide_icon('heart', '', 16) ?>
                        </a>
                      <?php endif; ?>
                    </span>
                    <span class="deals-card-copy">
                      <?php if (!empty($deal['pro_marca'])): ?>
                        <span class="deals-card-brand"><?= e($deal['pro_marca']) ?></span>
                      <?php endif; ?>
                      <span class="deals-card-name" title="<?= e($dealName) ?>"><?= e($dealName) ?></span>
                      <span class="deals-card-meta">
                        <span class="deals-card-count"><?= e($storeCountLabel) ?></span>
                        <span class="deals-card-sep" aria-hidden="true">·</span>
                        <span class="deals-card-save">
                          <?php if ($saving > 0): ?>
                            Diferencia <?= gs($saving) ?>
                          <?php else: ?>
                            Mismo precio
                          <?php endif; ?>
                          <?= lucide_icon('arrow-right', '', 16) ?>
                        </span>
                      </span>
                    </span>
                  </span>
                  <?php if ($cheapOffer && $expensiveOffer): ?>
                    <span class="deals-card-prices">
                      <span class="deals-card-price is-best" data-tone="<?= e($cheapOffer['tone']) ?>">
                        <em>Menor precio</em>
                        <strong><?= gs($cheapOffer['price']) ?></strong>
                        <span><?= e($cheapOffer['label']) ?></span>
                      </span>
                      <span class="deals-card-price is-high" data-tone="<?= e($expensiveOffer['tone']) ?>">
                        <em>Otra oferta</em>
                        <strong><?= gs($expensiveOffer['price']) ?></strong>
                        <span><?= e($expensiveOffer['label']) ?></span>
                      </span>
                    </span>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
            <button
              type="button"
              class="deals-carousel-btn deals-carousel-btn--next"
              data-deals-carousel-next
              aria-label="Productos siguientes"
              hidden
            >
              <?= lucide_icon('chevron-right', '', 18) ?>
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($activeOffers): ?>
  <?php
    $offerPageSize = 3;
    $offerPages = (int) max(1, ceil(count($activeOffers) / $offerPageSize));
    $offerFavoriteRedirect = 'index.php#ofertas';
  ?>
  <section
    class="offers-section"
    id="ofertas"
    aria-labelledby="offers-title"
  >
    <div class="container">
      <div class="offers-board" data-offers-pager data-page-size="<?= (int) $offerPageSize ?>">
        <div class="offers-intro">
          <div class="offers-intro-copy">
            <p class="offers-kicker">Precio rebajado</p>
            <h2 id="offers-title" class="section-title">Ofertas disponibles</h2>
            <p class="section-subtitle">Descuentos reales frente al precio anterior de cada tienda.</p>
          </div>
          <?php if ($offerPages > 1): ?>
            <div class="offers-nav" role="group" aria-label="Navegar ofertas">
              <button type="button" class="offers-nav-btn" data-offers-prev aria-label="Ofertas anteriores">
                <?= lucide_icon('chevron-left', '', 18) ?>
              </button>
              <button type="button" class="offers-nav-btn" data-offers-next aria-label="Ofertas siguientes">
                <?= lucide_icon('chevron-right', '', 18) ?>
              </button>
              <p class="offers-nav-status" data-offers-status aria-live="polite" aria-label="Página 1 de <?= (int) $offerPages ?>">
                <span class="offers-nav-current" data-offers-current>1</span>
                <span class="offers-nav-total">de <?= (int) $offerPages ?></span>
              </p>
            </div>
          <?php endif; ?>
        </div>

        <div class="offers-carousel" data-offers-carousel>
          <button
            type="button"
            class="offers-carousel-btn offers-carousel-btn--prev"
            data-offers-carousel-prev
            aria-label="Ofertas anteriores"
            hidden
          >
            <?= lucide_icon('chevron-left', '', 18) ?>
          </button>
          <div class="offers-rail" role="list" data-offers-track>
            <?php
              $offerWashPalette = [
                '#a8c9f0',
                '#b5d4f5',
                '#9bc0ec',
                '#c2daf7',
                '#8eb8e8',
                '#aecff2',
                '#bcd9f5',
                '#94bce6',
              ];
              $offerWashColors = $offerWashPalette;
              $offerWashSeed = crc32('offer-wash|' . count($activeOffers) . '|' . ($activeOffers[0]['idproductos'] ?? 0));
              mt_srand($offerWashSeed);
              for ($i = count($offerWashColors) - 1; $i > 0; $i--) {
                  $j = mt_rand(0, $i);
                  $swap = $offerWashColors[$i];
                  $offerWashColors[$i] = $offerWashColors[$j];
                  $offerWashColors[$j] = $swap;
              }
              mt_srand();
            ?>
            <?php foreach ($activeOffers as $index => $offer): ?>
              <?php
                $offerId = (int) ($offer['idproductos'] ?? 0);
                $offerName = (string) ($offer['pro_nombre'] ?? 'Producto');
                $offerUrl = 'producto.php?id=' . $offerId . ($q !== '' ? '&q=' . rawurlencode($q) : '');
                $offerStore = (string) ($offer['tie_nombre'] ?? 'Tienda');
                $offerBrand = trim((string) ($offer['pro_marca'] ?? ''));
                $offerDescParts = array_values(array_filter([
                    $offerBrand !== '' ? $offerBrand : null,
                    $offerStore !== '' ? $offerStore : null,
                ]));
                $offerDesc = implode(' · ', $offerDescParts);
                if ($offerDesc === '') {
                    $offerDesc = 'En oferta';
                }
                $offerInStock = (int) ($offer['pro_en_stock'] ?? 0) === 1;
                $offerExternal = trim((string) ($offer['pro_url'] ?? ''));
                $offerCtaHref = $offerExternal !== ''
                    ? ('go.php?' . http_build_query([
                        'id' => $offerId,
                        'source' => 'inicio',
                        'click_type' => 'ir_oferta',
                        'term' => $q,
                        'target_url' => $offerExternal,
                    ]))
                    : $offerUrl;
                $offerCtaExternal = $offerExternal !== '';
                $isOfferFavorite = ($favoritesEnabled && $userLogged) ? is_favorite_product($offerId) : false;
                $offerVisible = $index < $offerPageSize;
                $offerWash = $offerWashColors[$index % count($offerWashColors)];
              ?>
              <article
                class="offers-card clickable-card<?= $offerVisible ? ' is-visible' : '' ?>"
                data-offer-row
                style="--tile-wash: <?= e($offerWash) ?>;"
                data-card-href="<?= e($offerUrl) ?>"
                role="listitem"
                tabindex="0"
                aria-label="<?= e($offerName) ?>, −<?= (int) $offer['pct'] ?> por ciento en <?= e($offerStore) ?>"
              >
                <div class="offers-card-visual">
                  <div class="offers-card-media">
                    <img
                      src="<?= e(image_url($offer['pro_imagen'] ?? null, $offerName)) ?>"
                      alt="<?= e($offerName) ?>"
                      loading="lazy"
                      decoding="async"
                      onerror="this.onerror=null;if(window.applyProductImageFallback){applyProductImageFallback(this);}else{this.style.display='none';}"
                    >
                  </div>
                  <div class="offers-card-flags">
                    <span class="offers-card-flag is-deal">−<?= (int) $offer['pct'] ?>%</span>
                  </div>
                </div>
                <?php if ($favoritesEnabled && $userLogged): ?>
                  <a
                    class="offers-card-fav<?= $isOfferFavorite ? ' is-on' : '' ?>"
                    href="<?= e(favorite_toggle_url($offerId, $offerFavoriteRedirect)) ?>"
                    data-fav-toggle
                    data-product-id="<?= (int) $offerId ?>"
                    title="<?= $isOfferFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
                    aria-label="<?= $isOfferFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
                    aria-pressed="<?= $isOfferFavorite ? 'true' : 'false' ?>"
                  >
                    <?= lucide_icon('heart', $isOfferFavorite ? 'lucide-fill' : '', 16) ?>
                  </a>
                <?php else: ?>
                  <a class="offers-card-fav" href="login.php" title="Inicia sesión para guardar favoritos" aria-label="Inicia sesión para guardar favoritos">
                    <?= lucide_icon('heart', '', 16) ?>
                  </a>
                <?php endif; ?>

                <div class="offers-card-body">
                  <div class="offers-card-top">
                    <div class="offers-card-tags">
                      <?php if ($offerBrand !== ''): ?>
                        <span class="offers-card-brand"><?= e($offerBrand) ?></span>
                      <?php endif; ?>
                      <span class="offers-card-store"><?= e($offerStore) ?></span>
                    </div>
                    <h3 class="offers-card-name"><?= e($offerName) ?></h3>
                  </div>

                  <div class="offers-card-foot">
                    <div class="offers-card-priceblock">
                      <div class="offers-card-cost">
                        <p class="offers-card-price-now"><?= gs($offer['pro_precio']) ?></p>
                        <p class="offers-card-price-old"><?= gs($offer['pro_precio_anterior']) ?></p>
                        <p class="offers-card-save">Ahorro <?= gs($offer['ahorro']) ?></p>
                      </div>
                      <span class="offers-card-flag <?= $offerInStock ? 'is-stock' : 'is-wait' ?>"><?= e($offerInStock ? 'Stock' : 'Consultar') ?></span>
                    </div>

                    <div class="offers-card-actions">
                      <a
                        class="offers-card-cta"
                        href="<?= e($offerCtaHref) ?>"
                        <?php if ($offerCtaExternal): ?>
                          target="_blank"
                          rel="noopener noreferrer nofollow"
                        <?php endif; ?>
                      >
                        Ver oferta <?= lucide_icon($offerCtaExternal ? 'external-link' : 'arrow-right', '', 15) ?>
                      </a>
                    </div>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <button
            type="button"
            class="offers-carousel-btn offers-carousel-btn--next"
            data-offers-carousel-next
            aria-label="Ofertas siguientes"
            hidden
          >
            <?= lucide_icon('chevron-right', '', 18) ?>
          </button>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section id="tiendas" class="stores-section" aria-labelledby="stores-title">
    <div class="container">
      <div class="section-header stores-header">
        <div>
          <p class="stores-kicker">Tiendas participantes</p>
          <h2 id="stores-title" class="section-title mb-1">Tiendas</h2>
          <p class="section-subtitle mb-0">Catálogos de cada tienda reunidos para comparar precios en un solo lugar.</p>
        </div>
      </div>

      <?php if ($stores): ?>
        <div class="stores-carousel" data-stores-carousel>
          <button
            type="button"
            class="stores-carousel-btn stores-carousel-btn--prev"
            data-stores-prev
            aria-label="Tiendas anteriores"
            hidden
          >
            <?= lucide_icon('chevron-left', '', 18) ?>
          </button>
          <div class="stores-board" role="region" aria-label="Tiendas participantes" data-stores-track>
            <?php foreach ($stores as $index => $store): ?>
              <?php
                $storeId = (int) $store['idtiendas'];
                $storeName = (string) ($store['tie_nombre'] ?? 'Tienda');
                $storeUrl = 'tienda.php?id=' . $storeId;
                $storeTone = (string) ($store['_tone'] ?? 'inverfin');
                $storeFocus = (string) ($store['_focus'] ?? 'Catálogo');
                $storeIcon = (string) ($store['_icon'] ?? 'store');
                $storeDescription = trim((string) ($store['tie_descripcion'] ?? ''));
                $productCount = (int) ($store['total_productos'] ?? 0);
                $updatedAt = (string) ($store['ultima_actualizacion'] ?? '');
                $updatedLabel = isset($formatFreshness) ? $formatFreshness($updatedAt) : ($updatedAt !== '' ? date('d/m/Y', strtotime($updatedAt)) : 'Sin fecha');
                $updatedExact = $updatedAt !== '' ? date('d/m/Y H:i', strtotime($updatedAt)) : 'Sin actualizaciones recientes';
                $storeLocationRaw = trim((string) ($store['tie_ubicacion'] ?? ''));
                $storeLocationUrl = '';
                $storeLocationLabel = '';

                if ($storeLocationRaw !== '') {
                    $isLocationUrl = preg_match('~^https?://~i', $storeLocationRaw) === 1;
                    $isCoordinates = preg_match('~^-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?$~', $storeLocationRaw) === 1;
                    $storeLocationUrl = $isLocationUrl
                        ? $storeLocationRaw
                        : 'https://www.google.com/maps?q=' . rawurlencode($storeLocationRaw);
                    $storeLocationLabel = ($isLocationUrl || $isCoordinates) ? 'Ver mapa' : $storeLocationRaw;
                }
              ?>
              <article
                class="store-panel clickable-card"
                data-tone="<?= e($storeTone) ?>"
                data-card-href="<?= e($storeUrl) ?>"
                role="link"
                tabindex="0"
                aria-label="Ver catálogo de <?= e($storeName) ?>"
              >
                <span class="store-panel-mark" aria-hidden="true"><?= lucide_icon($storeIcon, '', 88) ?></span>
                <header class="store-panel-head">
                  <div class="store-panel-logo<?= empty($store['tie_logo']) ? ' is-fallback' : '' ?>">
                    <?php if (!empty($store['tie_logo'])): ?>
                      <img
                        src="<?= e($store['tie_logo']) ?>"
                        alt=""
                        class="store-panel-logo-img"
                        loading="lazy"
                        decoding="async"
                        data-no-fallback="true"
                        onerror="this.onerror=null;var p=this.parentElement;this.remove();if(!p)return;p.classList.add('is-fallback');var f=p.querySelector('.store-panel-fallback');if(f){f.hidden=false;f.removeAttribute('aria-hidden');}"
                      >
                      <span class="store-panel-fallback" hidden aria-hidden="true">
                        <?= lucide_icon('image', '', 56) ?>
                      </span>
                    <?php else: ?>
                      <span class="store-panel-fallback" role="img" aria-label="<?= e($storeName) ?>">
                        <?= lucide_icon('image', '', 56) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </header>

                <div class="store-panel-copy">
                  <div class="store-panel-identity">
                    <span class="store-panel-index"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <p class="store-panel-focus"><?= e($storeFocus) ?></p>
                  </div>
                  <h3 class="store-panel-name"><?= e($storeName) ?></h3>
                  <?php if ($storeDescription !== ''): ?>
                    <p class="store-panel-desc"><?= e($storeDescription) ?></p>
                  <?php endif; ?>
                </div>

                <dl class="store-panel-facts">
                  <div>
                    <dt>Productos</dt>
                    <dd><?= number_format($productCount, 0, ',', '.') ?></dd>
                  </div>
                  <div>
                    <dt>Actualizado</dt>
                    <dd title="<?= e($updatedExact) ?>"><?= e($updatedLabel) ?></dd>
                  </div>
                </dl>

                <footer class="store-panel-foot">
                  <?php if ($storeLocationUrl !== ''): ?>
                    <a
                      class="store-panel-place"
                      href="<?= e($storeLocationUrl) ?>"
                      target="_blank"
                      rel="noopener noreferrer"
                      title="<?= e($storeLocationLabel) ?>"
                    >
                      <?= lucide_icon('map-pin', '', 14) ?>
                      <span><?= e($storeLocationLabel) ?></span>
                    </a>
                  <?php else: ?>
                    <span class="store-panel-place is-empty">Caacupé</span>
                  <?php endif; ?>
                  <a class="store-panel-cta" href="<?= e($storeUrl) ?>">
                    Ver catálogo <?= lucide_icon('arrow-right', '', 15) ?>
                  </a>
                </footer>
              </article>
            <?php endforeach; ?>
          </div>
          <button
            type="button"
            class="stores-carousel-btn stores-carousel-btn--next"
            data-stores-next
            aria-label="Tiendas siguientes"
            hidden
          >
            <?= lucide_icon('chevron-right', '', 18) ?>
          </button>
        </div>
      <?php else: ?>
        <div class="stores-empty">
          <?= lucide_icon('store', 'stores-empty-icon', 28) ?>
          <h3>No hay tiendas para mostrar</h3>
          <p>Todavía no están cargados los catálogos de Inverfin, Computex y Full Office.</p>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<script>
/* Fuerza los saltos internos sin animación, incluso si otro CSS activa smooth scroll. */
document.documentElement.style.setProperty('scroll-behavior', 'auto', 'important');

(function () {
  let catalogRequest = 0;

  function catalogPartialUrl(url) {
    const next = new URL(url, window.location.href);
    next.searchParams.set('partial', 'products');
    next.hash = '';
    return next.toString();
  }

  function publicCatalogUrl(url) {
    const next = new URL(url, window.location.href);
    next.searchParams.delete('partial');
    if (!next.hash) next.hash = 'productos';
    return next.pathname + next.search + next.hash;
  }

  function loadCatalog(url, options) {
    const opts = options || {};
    const layout = document.querySelector('[data-products-catalog]');
    if (!layout) return Promise.resolve();

    const requestId = ++catalogRequest;
    layout.classList.add('is-loading');
    layout.setAttribute('aria-busy', 'true');

    return fetch(catalogPartialUrl(url), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('No se pudo cargar el catálogo.');
        return response.text();
      })
      .then(function (html) {
        if (requestId !== catalogRequest) return;

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const next = doc.querySelector('[data-products-catalog]') || doc.body.firstElementChild;
        if (!next || !next.classList || !next.classList.contains('products-layout')) {
          throw new Error('Respuesta de catálogo inválida.');
        }

        layout.replaceWith(next);
        if (window.refreshLucideIcons) window.refreshLucideIcons();
        if (window.applyProductImageFallback) {
          next.querySelectorAll('img').forEach(function (img) {
            if (!(img.getAttribute('src') || '').trim()) {
              window.applyProductImageFallback(img);
            }
          });
        }

        const publicUrl = publicCatalogUrl(url);
        if (opts.replace) {
          history.replaceState({ catalog: true }, '', publicUrl);
        } else {
          history.pushState({ catalog: true }, '', publicUrl);
        }
      })
      .catch(function () {
        if (requestId !== catalogRequest) return;
        window.location.href = publicCatalogUrl(url);
      })
      .finally(function () {
        if (requestId !== catalogRequest) return;
        const current = document.querySelector('[data-products-catalog]');
        if (current) {
          current.classList.remove('is-loading');
          current.removeAttribute('aria-busy');
        }
      });
  }

  document.addEventListener('click', function (event) {
    const link = event.target.closest('[data-products-catalog] a[href]');
    if (!link) return;
    if (link.hasAttribute('data-fav-toggle')) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;

    let targetUrl;
    try {
      targetUrl = new URL(link.href, window.location.href);
    } catch (error) {
      return;
    }

    if (targetUrl.origin !== window.location.origin) return;
    if (targetUrl.searchParams.get('partial')) return;

    const path = targetUrl.pathname.replace(/\/+$/, '');
    const currentPath = window.location.pathname.replace(/\/+$/, '');
    const looksLikeHome =
      /(?:^|\/)index\.php$/i.test(targetUrl.pathname) ||
      path === currentPath ||
      path === currentPath.replace(/\/index\.php$/i, '');

    if (!looksLikeHome) return;

    event.preventDefault();
    event.stopPropagation();
    loadCatalog(targetUrl);
  });

  window.addEventListener('popstate', function () {
    if (!document.querySelector('[data-products-catalog]')) return;
    const url = new URL(window.location.href);
    loadCatalog(url, { replace: true });
  });
})();

document.addEventListener('click', function (event) {
  const card = event.target.closest('.clickable-card[data-card-href]');
  if (!card) return;

  if (event.target.closest('a, button, input, select, textarea, label, [data-no-card-click]')) {
    return;
  }

  window.location.href = card.dataset.cardHref;
});

document.addEventListener('keydown', function (event) {
  if (event.key !== 'Enter' && event.key !== ' ') return;

  const card = event.target.closest('.clickable-card[data-card-href]');
  if (!card || event.target !== card) return;

  event.preventDefault();
  window.location.href = card.dataset.cardHref;
});

document.addEventListener('click', function(event) {
  const link = event.target.closest('a[href*="#"]');
  if (!link) return;

  let targetUrl;
  try {
    targetUrl = new URL(link.href, window.location.href);
  } catch (error) {
    return;
  }

  const sameDocument =
    targetUrl.origin === window.location.origin &&
    targetUrl.pathname === window.location.pathname &&
    targetUrl.search === window.location.search;

  if (!sameDocument || !targetUrl.hash || targetUrl.hash === '#') return;

  const targetId = decodeURIComponent(targetUrl.hash.slice(1));
  const target = document.getElementById(targetId);
  if (!target) return;

  event.preventDefault();
  target.scrollIntoView({ behavior: 'auto', block: 'start' });
  history.pushState(null, '', targetUrl.hash);
}, true);

document.addEventListener('DOMContentLoaded', function() {
  // Control de la sección en vivo (Para index.php)
  const searchInput = document.getElementById('global-search-input');
  const liveSection = document.getElementById('home-live-section');
  if (searchInput && liveSection) {
    const toggleLiveSection = () => {
      if (searchInput.value.trim() === '') {
        liveSection.style.setProperty('display', 'none', 'important');
      } else {
        liveSection.style.display = 'block';
      }
    };
    toggleLiveSection();
    searchInput.addEventListener('input', toggleLiveSection);
  }

  const storesCarousel = document.querySelector('[data-stores-carousel]');
  if (storesCarousel) {
    const track = storesCarousel.querySelector('[data-stores-track]');
    const prevBtn = storesCarousel.querySelector('[data-stores-prev]');
    const nextBtn = storesCarousel.querySelector('[data-stores-next]');
    const originalCards = track
      ? Array.from(track.querySelectorAll('.store-panel')).filter(function (card) {
          return !card.hasAttribute('data-stores-clone');
        })
      : [];
    const carouselMq = window.matchMedia('(max-width: 991.98px)');
    let loopWidth = 0;
    let canLoop = false;
    let normalizing = false;
    let settleTimer = 0;

    const clearClones = () => {
      if (!track) return;
      track.querySelectorAll('[data-stores-clone]').forEach(function (node) {
        node.remove();
      });
    };

    const cloneCard = (card) => {
      const clone = card.cloneNode(true);
      clone.setAttribute('data-stores-clone', '');
      clone.setAttribute('aria-hidden', 'true');
      clone.tabIndex = -1;
      clone.querySelectorAll('a, button, [tabindex]').forEach(function (el) {
        el.setAttribute('tabindex', '-1');
      });
      return clone;
    };

    const getStep = () => {
      if (originalCards.length >= 2) {
        const delta = originalCards[1].offsetLeft - originalCards[0].offsetLeft;
        if (delta > 0) return delta;
      }
      const card = originalCards[0];
      if (!card) return Math.max(240, track.clientWidth * 0.8);
      const styles = window.getComputedStyle(track);
      const gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
      return card.getBoundingClientRect().width + gap;
    };

    const updateStoresNav = () => {
      if (!track || !prevBtn || !nextBtn) return;
      const canScroll = canLoop || track.scrollWidth > track.clientWidth + 4;
      storesCarousel.classList.toggle('is-scrollable', canScroll);
      prevBtn.hidden = !canScroll;
      nextBtn.hidden = !canScroll;
      prevBtn.disabled = !canScroll;
      nextBtn.disabled = !canScroll;
    };

    const normalizeScroll = () => {
      if (!canLoop || loopWidth <= 0 || normalizing) return;

      const left = track.scrollLeft;
      if (left >= loopWidth * 2 - 0.5 || left < loopWidth - 0.5) {
        normalizing = true;
        const prevSnap = track.style.scrollSnapType;
        track.style.scrollSnapType = 'none';
        while (track.scrollLeft >= loopWidth * 2) {
          track.scrollLeft -= loopWidth;
        }
        while (track.scrollLeft < loopWidth) {
          track.scrollLeft += loopWidth;
        }
        requestAnimationFrame(function () {
          track.style.scrollSnapType = prevSnap;
          normalizing = false;
        });
      }
    };

    const setupLoop = () => {
      if (!track) return;
      clearClones();
      loopWidth = 0;
      canLoop = false;
      track.scrollLeft = 0;

      if (!carouselMq.matches || originalCards.length < 2) {
        updateStoresNav();
        return;
      }

      const overflows = track.scrollWidth > track.clientWidth + 4;
      if (!overflows) {
        updateStoresNav();
        return;
      }

      const before = document.createDocumentFragment();
      const after = document.createDocumentFragment();
      originalCards.forEach(function (card) {
        before.appendChild(cloneCard(card));
        after.appendChild(cloneCard(card));
      });
      track.insertBefore(before, track.firstChild);
      track.appendChild(after);

      loopWidth = originalCards[0].offsetLeft;
      canLoop = loopWidth > 0;
      if (canLoop) {
        track.scrollLeft = loopWidth;
      }
      updateStoresNav();
    };

    const goStores = (direction) => {
      if (!track || (!canLoop && track.scrollWidth <= track.clientWidth + 4)) return;
      const step = getStep();
      if (step <= 0) return;
      track.scrollBy({ left: direction * step, behavior: 'smooth' });
    };

    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        goStores(-1);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        goStores(1);
      });
    }

    track.addEventListener(
      'scroll',
      function () {
        if (normalizing) return;
        updateStoresNav();
        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(normalizeScroll, 80);
      },
      { passive: true }
    );

    track.addEventListener('scrollend', normalizeScroll);

    let rebuildTimer = 0;
    let setupBusy = false;
    const scheduleSetup = () => {
      window.clearTimeout(rebuildTimer);
      rebuildTimer = window.setTimeout(function () {
        if (setupBusy) return;
        setupBusy = true;
        setupLoop();
        setupBusy = false;
      }, 120);
    };

    if (typeof carouselMq.addEventListener === 'function') {
      carouselMq.addEventListener('change', scheduleSetup);
    } else if (typeof carouselMq.addListener === 'function') {
      carouselMq.addListener(scheduleSetup);
    }

    window.addEventListener('resize', scheduleSetup);
    setupLoop();
  }

  const dealsPager = document.querySelector('[data-deals-pager]');
  if (dealsPager) {
    const dealsCarousel = dealsPager.querySelector('[data-deals-carousel]');
    const track = dealsPager.querySelector('[data-deals-track]');
    const originalCards = track
      ? Array.from(track.querySelectorAll('[data-deal-row]')).filter(function (card) {
          return !card.hasAttribute('data-deals-clone');
        })
      : [];
    const pageSize = Math.max(1, parseInt(dealsPager.getAttribute('data-page-size') || '5', 10));
    const totalPages = Math.max(1, Math.ceil(originalCards.length / pageSize));
    const prevBtn = dealsPager.querySelector('[data-deals-prev]');
    const nextBtn = dealsPager.querySelector('[data-deals-next]');
    const carouselPrevBtn = dealsPager.querySelector('[data-deals-carousel-prev]');
    const carouselNextBtn = dealsPager.querySelector('[data-deals-carousel-next]');
    const status = dealsPager.querySelector('[data-deals-status]');
    const current = dealsPager.querySelector('[data-deals-current]');
    const carouselMq = window.matchMedia('(max-width: 1099.98px)');
    let page = 0;
    let loopWidth = 0;
    let canLoop = false;
    let normalizing = false;
    let settleTimer = 0;
    let rebuildTimer = 0;
    let setupBusy = false;

    const clearClones = () => {
      if (!track) return;
      track.querySelectorAll('[data-deals-clone]').forEach(function (node) {
        node.remove();
      });
    };

    const cloneCard = (card) => {
      const clone = card.cloneNode(true);
      clone.setAttribute('data-deals-clone', '');
      clone.setAttribute('aria-hidden', 'true');
      clone.classList.add('is-visible');
      clone.tabIndex = -1;
      clone.querySelectorAll('a, button, [tabindex]').forEach(function (el) {
        el.setAttribute('tabindex', '-1');
      });
      return clone;
    };

    const getStep = () => {
      if (originalCards.length >= 2) {
        const delta = originalCards[1].offsetLeft - originalCards[0].offsetLeft;
        if (delta > 0) return delta;
      }
      const card = originalCards[0];
      if (!card || !track) return Math.max(240, 280);
      const styles = window.getComputedStyle(track);
      const gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
      return card.getBoundingClientRect().width + gap;
    };

    const updateCarouselNav = () => {
      if (!dealsCarousel || !carouselPrevBtn || !carouselNextBtn || !track) return;
      const canScroll = canLoop || track.scrollWidth > track.clientWidth + 4;
      dealsCarousel.classList.toggle('is-scrollable', canScroll);
      carouselPrevBtn.hidden = !canScroll;
      carouselNextBtn.hidden = !canScroll;
      carouselPrevBtn.disabled = !canScroll;
      carouselNextBtn.disabled = !canScroll;
    };

    const normalizeScroll = () => {
      if (!track || !canLoop || loopWidth <= 0 || normalizing) return;
      const left = track.scrollLeft;
      if (left >= loopWidth * 2 - 0.5 || left < loopWidth - 0.5) {
        normalizing = true;
        const prevSnap = track.style.scrollSnapType;
        track.style.scrollSnapType = 'none';
        while (track.scrollLeft >= loopWidth * 2) {
          track.scrollLeft -= loopWidth;
        }
        while (track.scrollLeft < loopWidth) {
          track.scrollLeft += loopWidth;
        }
        requestAnimationFrame(function () {
          track.style.scrollSnapType = prevSnap;
          normalizing = false;
        });
      }
    };

    const renderDealsPage = () => {
      const start = page * pageSize;
      originalCards.forEach(function (row, index) {
        row.classList.toggle('is-visible', index >= start && index < start + pageSize);
      });
      if (current) {
        current.textContent = String(page + 1);
      }
      if (status) {
        status.setAttribute('aria-label', 'Página ' + (page + 1) + ' de ' + totalPages);
      }
    };

    const goToDealsPage = (nextPage) => {
      page = (nextPage + totalPages) % totalPages;
      renderDealsPage();
    };

    const setupDealsMode = () => {
      if (!track || !dealsCarousel) return;

      clearClones();
      loopWidth = 0;
      canLoop = false;
      track.scrollLeft = 0;

      if (!carouselMq.matches) {
        dealsPager.classList.remove('is-deals-carousel');
        dealsCarousel.classList.remove('is-carousel', 'is-scrollable');
        if (carouselPrevBtn) {
          carouselPrevBtn.hidden = true;
          carouselPrevBtn.disabled = true;
        }
        if (carouselNextBtn) {
          carouselNextBtn.hidden = true;
          carouselNextBtn.disabled = true;
        }
        renderDealsPage();
        return;
      }

      dealsPager.classList.add('is-deals-carousel');
      dealsCarousel.classList.add('is-carousel');
      originalCards.forEach(function (card) {
        card.classList.add('is-visible');
      });

      if (originalCards.length < 2) {
        updateCarouselNav();
        return;
      }

      const overflows = track.scrollWidth > track.clientWidth + 4;
      if (!overflows) {
        updateCarouselNav();
        return;
      }

      const before = document.createDocumentFragment();
      const after = document.createDocumentFragment();
      originalCards.forEach(function (card) {
        before.appendChild(cloneCard(card));
        after.appendChild(cloneCard(card));
      });
      track.insertBefore(before, track.firstChild);
      track.appendChild(after);

      loopWidth = originalCards[0].offsetLeft;
      canLoop = loopWidth > 0;
      if (canLoop) {
        track.scrollLeft = loopWidth;
      }
      updateCarouselNav();
    };

    const goDealsCarousel = (direction) => {
      if (!track || (!canLoop && track.scrollWidth <= track.clientWidth + 4)) return;
      const step = getStep();
      if (step <= 0) return;
      track.scrollBy({ left: direction * step, behavior: 'smooth' });
    };

    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        goToDealsPage(page - 1);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        goToDealsPage(page + 1);
      });
    }
    if (carouselPrevBtn) {
      carouselPrevBtn.addEventListener('click', function () {
        goDealsCarousel(-1);
      });
    }
    if (carouselNextBtn) {
      carouselNextBtn.addEventListener('click', function () {
        goDealsCarousel(1);
      });
    }

    if (track) {
      track.addEventListener(
        'scroll',
        function () {
          if (normalizing || !carouselMq.matches) return;
          updateCarouselNav();
          window.clearTimeout(settleTimer);
          settleTimer = window.setTimeout(normalizeScroll, 80);
        },
        { passive: true }
      );
      track.addEventListener('scrollend', function () {
        if (carouselMq.matches) normalizeScroll();
      });
    }

    const scheduleSetup = () => {
      window.clearTimeout(rebuildTimer);
      rebuildTimer = window.setTimeout(function () {
        if (setupBusy) return;
        setupBusy = true;
        setupDealsMode();
        setupBusy = false;
      }, 120);
    };

    if (typeof carouselMq.addEventListener === 'function') {
      carouselMq.addEventListener('change', scheduleSetup);
    } else if (typeof carouselMq.addListener === 'function') {
      carouselMq.addListener(scheduleSetup);
    }

    window.addEventListener('resize', scheduleSetup);
    setupDealsMode();
  }

  const offersPager = document.querySelector('[data-offers-pager]');
  if (offersPager) {
    const offersCarousel = offersPager.querySelector('[data-offers-carousel]');
    const track = offersPager.querySelector('[data-offers-track]');
    const originalCards = track
      ? Array.from(track.querySelectorAll('[data-offer-row]')).filter(function (card) {
          return !card.hasAttribute('data-offers-clone');
        })
      : [];
    const pageSize = Math.max(1, parseInt(offersPager.getAttribute('data-page-size') || '3', 10));
    const totalPages = Math.max(1, Math.ceil(originalCards.length / pageSize));
    const prevBtn = offersPager.querySelector('[data-offers-prev]');
    const nextBtn = offersPager.querySelector('[data-offers-next]');
    const carouselPrevBtn = offersPager.querySelector('[data-offers-carousel-prev]');
    const carouselNextBtn = offersPager.querySelector('[data-offers-carousel-next]');
    const status = offersPager.querySelector('[data-offers-status]');
    const current = offersPager.querySelector('[data-offers-current]');
    const carouselMq = window.matchMedia('(max-width: 900px)');
    let page = 0;
    let loopWidth = 0;
    let canLoop = false;
    let normalizing = false;
    let settleTimer = 0;
    let rebuildTimer = 0;
    let setupBusy = false;

    const clearClones = () => {
      if (!track) return;
      track.querySelectorAll('[data-offers-clone]').forEach(function (node) {
        node.remove();
      });
    };

    const cloneCard = (card) => {
      const clone = card.cloneNode(true);
      clone.setAttribute('data-offers-clone', '');
      clone.setAttribute('aria-hidden', 'true');
      clone.classList.add('is-visible');
      clone.tabIndex = -1;
      clone.querySelectorAll('a, button, [tabindex]').forEach(function (el) {
        el.setAttribute('tabindex', '-1');
      });
      return clone;
    };

    const getStep = () => {
      if (originalCards.length >= 2) {
        const delta = originalCards[1].offsetLeft - originalCards[0].offsetLeft;
        if (delta > 0) return delta;
      }
      const card = originalCards[0];
      if (!card || !track) return Math.max(240, 280);
      const styles = window.getComputedStyle(track);
      const gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
      return card.getBoundingClientRect().width + gap;
    };

    const updateCarouselNav = () => {
      if (!offersCarousel || !carouselPrevBtn || !carouselNextBtn || !track) return;
      const canScroll = canLoop || track.scrollWidth > track.clientWidth + 4;
      offersCarousel.classList.toggle('is-scrollable', canScroll);
      carouselPrevBtn.hidden = !canScroll;
      carouselNextBtn.hidden = !canScroll;
      carouselPrevBtn.disabled = !canScroll;
      carouselNextBtn.disabled = !canScroll;
    };

    const normalizeScroll = () => {
      if (!track || !canLoop || loopWidth <= 0 || normalizing) return;
      const left = track.scrollLeft;
      if (left >= loopWidth * 2 - 0.5 || left < loopWidth - 0.5) {
        normalizing = true;
        const prevSnap = track.style.scrollSnapType;
        track.style.scrollSnapType = 'none';
        while (track.scrollLeft >= loopWidth * 2) {
          track.scrollLeft -= loopWidth;
        }
        while (track.scrollLeft < loopWidth) {
          track.scrollLeft += loopWidth;
        }
        requestAnimationFrame(function () {
          track.style.scrollSnapType = prevSnap;
          normalizing = false;
        });
      }
    };

    const renderOffersPage = () => {
      const start = page * pageSize;
      originalCards.forEach(function (row, index) {
        row.classList.toggle('is-visible', index >= start && index < start + pageSize);
      });
      if (current) {
        current.textContent = String(page + 1);
      }
      if (status) {
        status.setAttribute('aria-label', 'Página ' + (page + 1) + ' de ' + totalPages);
      }
    };

    const goToOffersPage = (nextPage) => {
      page = (nextPage + totalPages) % totalPages;
      renderOffersPage();
    };

    const setupOffersMode = () => {
      if (!track || !offersCarousel) return;

      clearClones();
      loopWidth = 0;
      canLoop = false;
      track.scrollLeft = 0;

      if (!carouselMq.matches) {
        offersPager.classList.remove('is-offers-carousel');
        offersCarousel.classList.remove('is-carousel', 'is-scrollable');
        if (carouselPrevBtn) {
          carouselPrevBtn.hidden = true;
          carouselPrevBtn.disabled = true;
        }
        if (carouselNextBtn) {
          carouselNextBtn.hidden = true;
          carouselNextBtn.disabled = true;
        }
        renderOffersPage();
        return;
      }

      offersPager.classList.add('is-offers-carousel');
      offersCarousel.classList.add('is-carousel');
      originalCards.forEach(function (card) {
        card.classList.add('is-visible');
      });

      if (originalCards.length < 2) {
        updateCarouselNav();
        return;
      }

      const overflows = track.scrollWidth > track.clientWidth + 4;
      if (!overflows) {
        updateCarouselNav();
        return;
      }

      const before = document.createDocumentFragment();
      const after = document.createDocumentFragment();
      originalCards.forEach(function (card) {
        before.appendChild(cloneCard(card));
        after.appendChild(cloneCard(card));
      });
      track.insertBefore(before, track.firstChild);
      track.appendChild(after);

      loopWidth = originalCards[0].offsetLeft;
      canLoop = loopWidth > 0;
      if (canLoop) {
        track.scrollLeft = loopWidth;
      }
      updateCarouselNav();
    };

    const goOffersCarousel = (direction) => {
      if (!track || (!canLoop && track.scrollWidth <= track.clientWidth + 4)) return;
      const step = getStep();
      if (step <= 0) return;
      track.scrollBy({ left: direction * step, behavior: 'smooth' });
    };

    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        goToOffersPage(page - 1);
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        goToOffersPage(page + 1);
      });
    }
    if (carouselPrevBtn) {
      carouselPrevBtn.addEventListener('click', function () {
        goOffersCarousel(-1);
      });
    }
    if (carouselNextBtn) {
      carouselNextBtn.addEventListener('click', function () {
        goOffersCarousel(1);
      });
    }

    if (track) {
      track.addEventListener(
        'scroll',
        function () {
          if (normalizing || !carouselMq.matches) return;
          updateCarouselNav();
          window.clearTimeout(settleTimer);
          settleTimer = window.setTimeout(normalizeScroll, 80);
        },
        { passive: true }
      );
      track.addEventListener('scrollend', function () {
        if (carouselMq.matches) normalizeScroll();
      });
    }

    const scheduleSetup = () => {
      window.clearTimeout(rebuildTimer);
      rebuildTimer = window.setTimeout(function () {
        if (setupBusy) return;
        setupBusy = true;
        setupOffersMode();
        setupBusy = false;
      }, 120);
    };

    if (typeof carouselMq.addEventListener === 'function') {
      carouselMq.addEventListener('change', scheduleSetup);
    } else if (typeof carouselMq.addListener === 'function') {
      carouselMq.addListener(scheduleSetup);
    }

    window.addEventListener('resize', scheduleSetup);
    setupOffersMode();
  }

  const categoriesPager = document.querySelector('[data-categories-pager]');
  if (categoriesPager) {
    const bento = categoriesPager.querySelector('.categories-bento');
    const slides = Array.from(categoriesPager.querySelectorAll('[data-category-slide]'));
    const pageSize = Math.max(1, parseInt(categoriesPager.getAttribute('data-page-size') || '5', 10));
    const totalPages = Math.max(1, Math.ceil(slides.length / pageSize));
    const prevBtn = categoriesPager.querySelector('[data-categories-prev]');
    const nextBtn = categoriesPager.querySelector('[data-categories-next]');
    const status = categoriesPager.querySelector('[data-categories-status]');
    const current = categoriesPager.querySelector('[data-categories-current]');
    const autoplayMs = Math.max(2500, parseInt(categoriesPager.getAttribute('data-autoplay-ms') || '6200', 10));
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const exitMs = 180;
    const enterMs = 820;
    const bentoLayouts = ['left', 'right', 'top', 'center', 'bottom'];
    const slotCount = 5;
    const stage = bento ? bento.parentElement : null;
    let page = Math.max(0, slides.findIndex((slide) => slide.classList.contains('is-featured')));
    page = page < 0 ? 0 : Math.floor(page / pageSize);
    let autoplayTimer = null;
    let swapTimer = null;
    let paused = false;
    let inView = true;
    let isAnimating = false;
    let pendingPage = null;

    const updateStatus = () => {
      if (current) {
        current.textContent = String(page + 1);
      }
      if (status) {
        status.setAttribute('aria-label', 'Página ' + (page + 1) + ' de ' + totalPages);
      }
    };

    const applyBentoLayout = () => {
      if (!bento) {
        return;
      }
      bento.setAttribute('data-bento-layout', bentoLayouts[page % bentoLayouts.length]);
    };

    const clearAnimState = (slide) => {
      slide.classList.remove('is-entering', 'is-exiting');
      slide.style.removeProperty('--stagger');
    };

    const syncSlideSlot = (slide, slot, visible) => {
      slide.classList.toggle('is-visible', visible);
      slide.classList.toggle('is-featured', visible && slot === 0);
      for (let i = 0; i < slotCount; i += 1) {
        slide.classList.toggle('is-slot-' + i, visible && slot === i);
      }
    };

    const showPage = (entering) => {
      const start = page * pageSize;
      applyBentoLayout();
      slides.forEach((slide, index) => {
        const slot = (index - start + slides.length) % slides.length;
        const visible = slot < pageSize;
        clearAnimState(slide);
        syncSlideSlot(slide, slot, visible);
        if (visible && entering) {
          slide.classList.add('is-entering');
          slide.style.setProperty('--stagger', (slot * 75) + 'ms');
        }
      });
      updateStatus();
    };

    const finishSwap = () => {
      window.clearTimeout(swapTimer);
      swapTimer = null;
      slides.forEach(clearAnimState);
      if (bento) {
        bento.classList.remove('is-animating');
      }
      if (stage) {
        stage.style.removeProperty('min-height');
      }
      isAnimating = false;
      if (pendingPage !== null && pendingPage !== page) {
        const nextPage = pendingPage;
        pendingPage = null;
        goToPage(nextPage, true);
      }
    };

    const renderCategoriesPage = (animate) => {
      const shouldAnimate = Boolean(animate && !prefersReducedMotion && bento);
      const currentVisible = slides.filter((slide) => slide.classList.contains('is-visible'));

      if (!shouldAnimate) {
        slides.forEach(clearAnimState);
        if (bento) {
          bento.classList.remove('is-animating');
        }
        if (stage) {
          stage.style.removeProperty('min-height');
        }
        showPage(false);
        isAnimating = false;
        return;
      }

      isAnimating = true;
      window.clearTimeout(swapTimer);
      bento.classList.add('is-animating');
      if (stage) {
        stage.style.minHeight = Math.round(bento.getBoundingClientRect().height) + 'px';
      }

      currentVisible.forEach((slide) => {
        slide.classList.remove('is-entering');
        slide.classList.add('is-exiting');
      });

      swapTimer = window.setTimeout(() => {
        currentVisible.forEach(clearAnimState);
        showPage(true);
        swapTimer = window.setTimeout(finishSwap, enterMs);
      }, currentVisible.length ? exitMs : 0);
    };

    const goToPage = (nextPage, animate) => {
      const resolved = (nextPage + totalPages) % totalPages;
      if (isAnimating) {
        pendingPage = resolved;
        return;
      }
      if (resolved === page) {
        return;
      }
      page = resolved;
      renderCategoriesPage(animate !== false);
    };

    const stopAutoplay = () => {
      if (autoplayTimer) {
        window.clearInterval(autoplayTimer);
        autoplayTimer = null;
      }
    };

    const startAutoplay = () => {
      stopAutoplay();
      if (paused || !inView || prefersReducedMotion || totalPages < 2 || document.hidden) {
        return;
      }
      autoplayTimer = window.setInterval(() => {
        goToPage(page + 1, true);
      }, autoplayMs);
    };

    if (prevBtn) {
      prevBtn.addEventListener('click', function() {
        goToPage(page - 1, true);
        startAutoplay();
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function() {
        goToPage(page + 1, true);
        startAutoplay();
      });
    }

    if (totalPages > 1 && !prefersReducedMotion) {
      categoriesPager.addEventListener('mouseenter', function() {
        paused = true;
        stopAutoplay();
      });
      categoriesPager.addEventListener('mouseleave', function() {
        paused = false;
        startAutoplay();
      });
      categoriesPager.addEventListener('focusin', function() {
        paused = true;
        stopAutoplay();
      });
      categoriesPager.addEventListener('focusout', function(event) {
        if (!categoriesPager.contains(event.relatedTarget)) {
          paused = false;
          startAutoplay();
        }
      });
      document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
          stopAutoplay();
        } else {
          startAutoplay();
        }
      });
      if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
          inView = Boolean(entries[0] && entries[0].isIntersecting);
          if (inView) {
            startAutoplay();
          } else {
            stopAutoplay();
          }
        }, { threshold: 0.25 });
        observer.observe(categoriesPager);
      } else {
        startAutoplay();
      }
    }
  }

});
</script>
<?php render_footer(); ?>
