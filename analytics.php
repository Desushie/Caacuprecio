<?php
require_once __DIR__ . '/config.php';
require_admin_or_empresa();

$pdo = db();
$user = current_user();
$isEmpresa = is_empresa();
$myStoreId = $isEmpresa ? (int)($user['tiendas_idtiendas'] ?? 0) : 0;

// CONTROL SEGURO DE FILTRO POR TIENDA
if ($isEmpresa) {
    $storeId = $myStoreId;
} else {
    $storeId = max(0, (int) ($_GET['tienda'] ?? 0));
}

function cp_table_exists(PDO $pdo, string $table): bool
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

$hasProductClicks = cp_table_exists($pdo, 'producto_clicks');
$hasFavoritos = cp_table_exists($pdo, 'favoritos');
$hasReviews = cp_table_exists($pdo, 'tienda_reviews');
$hasBusquedas = cp_table_exists($pdo, 'busquedas');
$hasHistorial = cp_table_exists($pdo, 'historial_precios');

// ==========================================
// PERÍODO ANALIZADO
// ==========================================
$today = new DateTimeImmutable('today');
$parseDate = static function (string $value): ?DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
};

$presets = [7 => '7 días', 30 => '30 días', 90 => '90 días', 365 => '12 meses'];
$from = $parseDate(trim((string) ($_GET['desde'] ?? '')));
$to = $parseDate(trim((string) ($_GET['hasta'] ?? '')));
$range = (int) ($_GET['rango'] ?? 0);

if ($from || $to) {
    $range = 0;
    $to = $to ?? $today;
    $from = $from ?? $to->modify('-29 days');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
} else {
    if (!isset($presets[$range])) {
        $range = 30;
    }
    $to = $today;
    $from = $today->modify('-' . ($range - 1) . ' days');
}

$periodDays = (int) $from->diff($to)->days + 1;
$prevTo = $from->modify('-1 day');
$prevFrom = $prevTo->modify('-' . ($periodDays - 1) . ' days');

$dateFrom = $from->format('Y-m-d');
$dateTo = $to->format('Y-m-d');
$period = [':desde' => $from->format('Y-m-d 00:00:00'), ':hasta' => $to->format('Y-m-d 23:59:59')];
$prevPeriod = [':desde' => $prevFrom->format('Y-m-d 00:00:00'), ':hasta' => $prevTo->format('Y-m-d 23:59:59')];

$monthNames = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$fmtDay = static fn(DateTimeInterface $d): string => $d->format('j') . ' ' . $monthNames[(int) $d->format('n') - 1];
$fmtNum = static fn($value): string => number_format((float) $value, 0, ',', '.');
$fmtPct = static fn(int $part, int $total): int => $total > 0 ? (int) round($part / $total * 100) : 0;
$delta = static function (int $current, int $previous): string {
    if ($previous === 0) {
        return '';
    }
    $pct = (int) round(($current - $previous) / $previous * 100);
    return ($pct > 0 ? '+' : '') . $pct . '%';
};

$scope = $storeId > 0 ? ' AND p.tiendas_idtiendas = :storeId' : '';
$run = static function (string $sql, array $params = []) use ($pdo, $storeId): PDOStatement {
    if ($storeId > 0 && str_contains($sql, ':storeId')) {
        $params[':storeId'] = $storeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
};

// Listado de tiendas auxiliar (solo para combo de Administradores)
$stores = [];
$storeName = '';
if (!$isEmpresa) {
    $stores = $pdo->query('SELECT idtiendas, tie_nombre FROM tiendas ORDER BY tie_nombre ASC')->fetchAll();
    foreach ($stores as $store) {
        if ((int) $store['idtiendas'] === $storeId) {
            $storeName = (string) $store['tie_nombre'];
        }
    }
} else {
    $stmtMyStore = $pdo->prepare('SELECT tie_nombre FROM tiendas WHERE idtiendas = ?');
    $stmtMyStore->execute([$myStoreId]);
    $storeName = $stmtMyStore->fetchColumn() ?: 'Mi Tienda';
}

// ==========================================
// 1. SALUD DEL CATÁLOGO (estado actual)
// ==========================================
$catalog = $run("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(p.pro_en_stock = 1), 0) AS en_stock,
        COALESCE(SUM(p.pro_imagen IS NOT NULL AND p.pro_imagen <> '' AND p.pro_imagen NOT LIKE 'data:image%'), 0) AS con_imagen,
        COALESCE(SUM(p.pro_precio_anterior > p.pro_precio), 0) AS ofertas,
        COALESCE(SUM(p.pro_precio > 0), 0) AS con_precio,
        AVG(NULLIF(p.pro_precio, 0)) AS precio_promedio,
        MAX(p.pro_fecha_scraping) AS ultima
    FROM productos p
    WHERE p.pro_activo = 1 $scope
")->fetch();

$totalProductosActivos = (int) $catalog['total'];
$enStock = (int) $catalog['en_stock'];
$conImagen = (int) $catalog['con_imagen'];
$enOferta = (int) $catalog['ofertas'];
$conPrecio = (int) $catalog['con_precio'];
$promedioPrecio = (float) $catalog['precio_promedio'];
$ultimaActualizacion = $catalog['ultima'] ? new DateTimeImmutable((string) $catalog['ultima']) : null;
$stockPct = $fmtPct($enStock, $totalProductosActivos);

// ==========================================
// 2. CLICKS HACIA TIENDAS
// ==========================================
$clicksSql = "
    SELECT COUNT(*) AS total,
           COUNT(DISTINCT COALESCE(CONCAT('u', pc.usuario_idusuario), pc.session_id, CONCAT('c', pc.idclick))) AS visitantes
    FROM producto_clicks pc
    INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
    WHERE pc.click_fecha BETWEEN :desde AND :hasta $scope
";

$totalClicks = 0;
$prevClicks = 0;
$visitantes = 0;
$clickOrigins = [];
$topClicked = [];

if ($hasProductClicks) {
    $rowClicks = $run($clicksSql, $period)->fetch();
    $totalClicks = (int) $rowClicks['total'];
    $visitantes = (int) $rowClicks['visitantes'];
    $prevClicks = (int) $run($clicksSql, $prevPeriod)->fetch()['total'];

    $clickOrigins = $run("
        SELECT pc.click_origen AS origen, COUNT(*) AS total
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        WHERE pc.click_fecha BETWEEN :desde AND :hasta $scope
        GROUP BY pc.click_origen
        ORDER BY total DESC
    ", $period)->fetchAll();

    $topClicked = $run("
        SELECT p.idproductos, p.pro_nombre, p.pro_imagen, p.pro_precio, p.pro_en_stock, t.tie_nombre, COUNT(*) AS total,
               COUNT(DISTINCT COALESCE(CONCAT('u', pc.usuario_idusuario), pc.session_id, CONCAT('c', pc.idclick))) AS extra
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        WHERE pc.click_fecha BETWEEN :desde AND :hasta $scope
        GROUP BY p.idproductos, p.pro_nombre, p.pro_imagen, p.pro_precio, p.pro_en_stock, t.tie_nombre
        ORDER BY total DESC, p.pro_nombre ASC
        LIMIT 6
    ", $period)->fetchAll();
}

$originNames = [
    'inicio' => 'Inicio',
    'producto' => 'Ficha de producto',
    'buscar' => 'Búsqueda',
    'busqueda' => 'Búsqueda',
    'tienda' => 'Página de tienda',
    'favoritos' => 'Favoritos',
];

// ==========================================
// 3. FAVORITOS
// ==========================================
$favsSql = "
    SELECT COUNT(*)
    FROM favoritos f
    INNER JOIN productos p ON p.idproductos = f.productos_idproductos
    WHERE f.fav_fecha BETWEEN :desde AND :hasta $scope
";

$totalFavs = 0;
$prevFavs = 0;
$favsEnListas = 0;
$topFavorites = [];

if ($hasFavoritos) {
    $totalFavs = (int) $run($favsSql, $period)->fetchColumn();
    $prevFavs = (int) $run($favsSql, $prevPeriod)->fetchColumn();
    $favsEnListas = (int) $run("
        SELECT COUNT(*) FROM favoritos f
        INNER JOIN productos p ON p.idproductos = f.productos_idproductos
        WHERE 1 = 1 $scope
    ")->fetchColumn();

    $topFavorites = $run("
        SELECT p.idproductos, p.pro_nombre, p.pro_imagen, p.pro_precio, p.pro_en_stock, t.tie_nombre, COUNT(*) AS total,
               COALESCE(SUM(f.fav_fecha BETWEEN :desde AND :hasta), 0) AS extra
        FROM favoritos f
        INNER JOIN productos p ON p.idproductos = f.productos_idproductos
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        WHERE 1 = 1 $scope
        GROUP BY p.idproductos, p.pro_nombre, p.pro_imagen, p.pro_precio, p.pro_en_stock, t.tie_nombre
        ORDER BY total DESC, p.pro_nombre ASC
        LIMIT 6
    ", $period)->fetchAll();
}

// ==========================================
// 4. ACTIVIDAD EN EL TIEMPO (clicks + favoritos)
// ==========================================
$granularity = $periodDays <= 62 ? 'day' : ($periodDays <= 400 ? 'week' : 'month');
$granularityLabel = ['day' => 'día', 'week' => 'semana', 'month' => 'mes'][$granularity];
$bucketSql = static function (string $col) use ($granularity): string {
    return match ($granularity) {
        'day' => "DATE($col)",
        'week' => "DATE(DATE_SUB($col, INTERVAL WEEKDAY($col) DAY))",
        default => "DATE_FORMAT($col, '%Y-%m-01')",
    };
};

$buckets = [];
$cursor = match ($granularity) {
    'day' => $from,
    'week' => $from->modify('-' . ((int) $from->format('N') - 1) . ' days'),
    default => $from->modify('first day of this month'),
};
$step = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month'][$granularity];
while ($cursor <= $to) {
    $label = $granularity === 'month'
        ? ucfirst($monthNames[(int) $cursor->format('n') - 1]) . ' ' . $cursor->format('y')
        : $fmtDay($cursor);
    $buckets[$cursor->format('Y-m-d')] = ['label' => $label, 'clicks' => 0, 'favs' => 0];
    $cursor = $cursor->modify($step);
}

if ($hasProductClicks) {
    $series = $run("
        SELECT {$bucketSql('pc.click_fecha')} AS bucket, COUNT(*) AS total
        FROM producto_clicks pc
        INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
        WHERE pc.click_fecha BETWEEN :desde AND :hasta $scope
        GROUP BY bucket
    ", $period)->fetchAll();
    foreach ($series as $row) {
        if (isset($buckets[$row['bucket']])) {
            $buckets[$row['bucket']]['clicks'] = (int) $row['total'];
        }
    }
}

if ($hasFavoritos) {
    $series = $run("
        SELECT {$bucketSql('f.fav_fecha')} AS bucket, COUNT(*) AS total
        FROM favoritos f
        INNER JOIN productos p ON p.idproductos = f.productos_idproductos
        WHERE f.fav_fecha BETWEEN :desde AND :hasta $scope
        GROUP BY bucket
    ", $period)->fetchAll();
    foreach ($series as $row) {
        if (isset($buckets[$row['bucket']])) {
            $buckets[$row['bucket']]['favs'] = (int) $row['total'];
        }
    }
}

$hasActivity = $totalClicks > 0 || $totalFavs > 0;

// ==========================================
// 5. CAMBIOS REALES DE PRECIO
// ==========================================
// Cada scraping guarda una fila: solo cuenta como cambio si difiere del precio anterior del producto
$priceDrops = 0;
$priceRises = 0;
if ($hasHistorial) {
    try {
        $rowHist = $run("
            SELECT COALESCE(SUM(x.diff < 0), 0) AS bajas, COALESCE(SUM(x.diff > 0), 0) AS subas
            FROM (
                SELECT hp.his_fecha,
                       hp.his_precio - LAG(hp.his_precio) OVER (
                           PARTITION BY hp.productos_idproductos ORDER BY hp.his_fecha, hp.idhistorial
                       ) AS diff
                FROM historial_precios hp
                INNER JOIN productos p ON p.idproductos = hp.productos_idproductos
                WHERE hp.his_precio > 0 $scope
            ) x
            WHERE x.diff <> 0 AND x.his_fecha BETWEEN :desde AND :hasta
        ", $period)->fetch();
        $priceDrops = (int) $rowHist['bajas'];
        $priceRises = (int) $rowHist['subas'];
    } catch (Throwable $e) {
        error_log('analytics: no se pudieron calcular cambios de precio: ' . $e->getMessage());
    }
}
$priceChanges = $priceDrops + $priceRises;

// ==========================================
// 6. CATEGORÍAS Y OFERTAS
// ==========================================
$categories = $run("
    SELECT c.cat_nombre, COUNT(*) AS cantidad, COALESCE(SUM(p.pro_precio_anterior > p.pro_precio), 0) AS ofertas
    FROM productos p
    INNER JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE p.pro_activo = 1 $scope
    GROUP BY c.idcategorias, c.cat_nombre
    ORDER BY cantidad DESC
    LIMIT 7
")->fetchAll();

$bestOffers = $run("
    SELECT p.idproductos, p.pro_nombre, p.pro_imagen, p.pro_precio, p.pro_precio_anterior, t.tie_nombre,
           ROUND((1 - p.pro_precio / p.pro_precio_anterior) * 100) AS pct
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    WHERE p.pro_activo = 1
      AND p.pro_precio > 0
      AND p.pro_precio_anterior > p.pro_precio
      AND p.pro_precio >= p.pro_precio_anterior * 0.1
      $scope
    ORDER BY pct DESC, (p.pro_precio_anterior - p.pro_precio) DESC
    LIMIT 5
")->fetchAll();

// ==========================================
// 7. REPUTACIÓN (tienda) o TIENDAS DESTACADAS (global)
// ==========================================
$ratingPromedio = 0.0;
$totalReviews = 0;
$reviewsPeriodo = 0;
$ratingDist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$storeRanking = [];
$activeStores = 0;
$globalRating = 0.0;

if ($storeId > 0) {
    if ($hasReviews) {
        $stmtRev = $pdo->prepare('
            SELECT rev_puntaje, COUNT(*) AS total, COALESCE(SUM(rev_fecha BETWEEN :desde AND :hasta), 0) AS en_periodo
            FROM tienda_reviews
            WHERE tiendas_idtiendas = :storeId AND rev_activo = 1
            GROUP BY rev_puntaje
        ');
        $stmtRev->execute($period + [':storeId' => $storeId]);
        $sumScore = 0;
        foreach ($stmtRev->fetchAll() as $row) {
            $score = max(1, min(5, (int) $row['rev_puntaje']));
            $ratingDist[$score] += (int) $row['total'];
            $totalReviews += (int) $row['total'];
            $reviewsPeriodo += (int) $row['en_periodo'];
            $sumScore += $score * (int) $row['total'];
        }
        $ratingPromedio = $totalReviews > 0 ? round($sumScore / $totalReviews, 1) : 0.0;
    }
} else {
    $activeStores = (int) $pdo->query('SELECT COUNT(DISTINCT tiendas_idtiendas) FROM productos WHERE pro_activo = 1')->fetchColumn();
    if ($hasReviews) {
        $globalRating = round((float) $pdo->query('SELECT AVG(rev_puntaje) FROM tienda_reviews WHERE rev_activo = 1')->fetchColumn(), 1);
    }
    if ($hasProductClicks) {
        $ratingSub = $hasReviews
            ? '(SELECT ROUND(AVG(tr.rev_puntaje), 1) FROM tienda_reviews tr WHERE tr.tiendas_idtiendas = t.idtiendas AND tr.rev_activo = 1)'
            : 'NULL';
        $storeRanking = $run("
            SELECT t.idtiendas, t.tie_nombre, t.tie_logo, COUNT(*) AS total, $ratingSub AS rating
            FROM producto_clicks pc
            INNER JOIN productos p ON p.idproductos = pc.productos_idproductos
            INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
            WHERE pc.click_fecha BETWEEN :desde AND :hasta
            GROUP BY t.idtiendas, t.tie_nombre, t.tie_logo
            ORDER BY total DESC, t.tie_nombre ASC
            LIMIT 5
        ", $period)->fetchAll();
    }
}

// ==========================================
// 8. DEMANDA: TÉRMINOS MÁS BUSCADOS Y SU COBERTURA
// ==========================================
$hasBusquedasDiarias = cp_table_exists($pdo, 'busquedas_diarias');
$demandFrom = $parseDate(trim((string) ($_GET['bq_desde'] ?? ''))) ?? $from;
$demandTo = $parseDate(trim((string) ($_GET['bq_hasta'] ?? ''))) ?? $to;
if ($demandFrom > $demandTo) {
    [$demandFrom, $demandTo] = [$demandTo, $demandFrom];
}
$demandFilters = [
    'todos' => ['label' => 'Todos'],
    'cubre' => ['label' => $storeId > 0 ? 'Cubre la tienda' : 'Con oferta'],
    'oportunidad' => ['label' => 'Oportunidad'],
];
$demandFilter = (string) ($_GET['bq_cob'] ?? 'todos');
if (!isset($demandFilters[$demandFilter])) {
    $demandFilter = 'todos';
}
$demandPerPage = 5;
$demandPage = max(1, (int) ($_GET['bq_pag'] ?? 1));

$searchTerms = [];
$demandCounts = ['todos' => 0, 'cubre' => 0, 'oportunidad' => 0];
if ($hasBusquedasDiarias || $hasBusquedas) {
    // Sin la tabla diaria solo se conoce la última fecha de cada término, no cuántas veces se buscó en el período
    $stmtTerms = $pdo->prepare($hasBusquedasDiarias ? '
        SELECT MAX(bd_termino) AS termino, bd_normalizado AS normalizado, SUM(bd_total) AS total
        FROM busquedas_diarias
        WHERE bd_fecha BETWEEN :desde AND :hasta
        GROUP BY bd_normalizado
        ORDER BY total DESC, termino ASC
        LIMIT 300
    ' : '
        SELECT MAX(bus_termino) AS termino, bus_normalizado AS normalizado, SUM(bus_total) AS total
        FROM busquedas
        WHERE bus_ultima_fecha BETWEEN :desde AND :hasta
        GROUP BY bus_normalizado
        ORDER BY total DESC, termino ASC
        LIMIT 300
    ');
    $stmtTerms->execute([
        ':desde' => $demandFrom->format('Y-m-d 00:00:00'),
        ':hasta' => $demandTo->format('Y-m-d 23:59:59'),
    ]);
    $searchTerms = $stmtTerms->fetchAll();

    foreach ($searchTerms as $i => $term) {
        $words = array_slice(array_filter(preg_split('/\s+/u', (string) $term['normalizado'])), 0, 5);
        $conditions = [];
        $params = [];
        foreach (array_values($words) as $w => $word) {
            $conditions[] = "p.pro_nombre LIKE :w$w";
            $params[":w$w"] = '%' . addcslashes($word, '%_\\') . '%';
        }
        $matches = 0;
        if ($conditions) {
            $matches = (int) $run(
                'SELECT COUNT(*) FROM productos p WHERE p.pro_activo = 1 AND ' . implode(' AND ', $conditions) . $scope,
                $params
            )->fetchColumn();
        }
        $searchTerms[$i]['matches'] = $matches;
        $demandCounts[$matches > 0 ? 'cubre' : 'oportunidad']++;
    }
    $demandCounts['todos'] = count($searchTerms);
}

$demandFiltered = array_values(array_filter($searchTerms, static fn(array $term): bool => match ($demandFilter) {
    'cubre' => $term['matches'] > 0,
    'oportunidad' => $term['matches'] === 0,
    default => true,
}));
$demandTotal = count($demandFiltered);
$demandSearches = array_sum(array_column($demandFiltered, 'total'));
$demandPages = max(1, (int) ceil($demandTotal / $demandPerPage));
$demandPage = min($demandPage, $demandPages);
$demandOffset = ($demandPage - 1) * $demandPerPage;
$topSearches = array_slice($demandFiltered, $demandOffset, $demandPerPage);
$maxSearch = $demandFiltered ? max(array_column($demandFiltered, 'total')) : 1;

$clicksDelta = $delta($totalClicks, $prevClicks);
$favsDelta = $delta($totalFavs, $prevFavs);
$maxClicked = $topClicked ? max(array_column($topClicked, 'total')) : 1;
$maxFav = $topFavorites ? max(array_column($topFavorites, 'total')) : 1;
$catPalette = ['#E8825E', '#F29A3E', '#F5AE4F', '#F7C068', '#F9CF86', '#FADCA5', '#FBE7C4'];
$catSegments = [];
$catTopSum = 0;
$catTopDeals = null;
foreach ($categories as $i => $cat) {
    $count = (int) $cat['cantidad'];
    $deals = (int) $cat['ofertas'];
    $catTopSum += $count;
    if ($deals > 0 && ($catTopDeals === null || $deals > $catTopDeals['deals'])) {
        $catTopDeals = ['name' => $cat['cat_nombre'], 'deals' => $deals];
    }
    $catSegments[] = ['name' => $cat['cat_nombre'], 'count' => $count, 'deals' => $deals, 'color' => $catPalette[$i % count($catPalette)]];
}
$catRest = max(0, $totalProductosActivos - $catTopSum);
if ($categories && $catRest > 0) {
    $catSegments[] = ['name' => 'Resto del catálogo', 'count' => $catRest, 'deals' => null, 'color' => 'var(--an-rest)'];
}
$catBase = max(1, $totalProductosActivos, $catTopSum);

// Ángulo en escala raíz para que las categorías chicas no desaparezcan
$rose = ['size' => 200, 'base' => 92, 'label' => 60];
if ($catSegments) {
    $c = $rose['size'] / 2;
    $pt = static fn(float $r, float $deg): string => sprintf('%.2F %.2F', $c + cos(deg2rad($deg)) * $r, $c + sin(deg2rad($deg)) * $r);
    $single = count($catSegments) === 1;
    $sector = static function (float $a0, float $a1, float $r) use ($pt, $single, $c): string {
        if ($single) {
            return sprintf('M%.2F %.2F a%.2F %.2F 0 1 0 %.2F 0 a%.2F %.2F 0 1 0 %.2F 0 Z', $c - $r, $c, $r, $r, 2 * $r, $r, $r, -2 * $r);
        }
        return sprintf(
            'M%.2F %.2F L%s A%.2F %.2F 0 %d 1 %s Z',
            $c, $c, $pt($r, $a0), $r, $r, ($a1 - $a0) > 180 ? 1 : 0, $pt($r, $a1)
        );
    };

    $roots = array_map(static fn(array $s): float => sqrt($s['count']), $catSegments);
    $cursor = -90.0;
    foreach ($catSegments as $i => &$seg) {
        $angle = $roots[$i] / array_sum($roots) * 360;
        $a0 = $cursor;
        $a1 = $cursor + $angle;
        $cursor = $a1;
        $seg['pct'] = $fmtPct($seg['count'], $catBase);
        $seg['path'] = $sector($a0, $a1, $rose['base']);
        $seg['inside'] = $angle >= 24;
        [$seg['tx'], $seg['ty']] = explode(' ', $pt($rose['label'], ($a0 + $a1) / 2));
        $seg['ink'] = $seg['deals'] === null ? 'var(--text-main)' : ($i < 3 ? '#fff' : '#6B3E12');
    }
    unset($seg);
}
$catTop3Pct = $fmtPct(array_sum(array_slice(array_column($categories, 'cantidad'), 0, 3)), $catBase);
$maxStoreClicks = $storeRanking ? max(array_column($storeRanking, 'total')) : 1;
$maxRatingBucket = max(1, ...array_values($ratingDist));

$scopeLabel = $storeId > 0 ? 'de la tienda' : 'del catálogo';
$presetQuery = static fn(int $days): string => '?' . http_build_query(array_filter([
    'tienda' => $storeId > 0 && !$isEmpresa ? $storeId : null,
    'rango' => $days,
]));

$pageParams = array_filter(
    array_intersect_key($_GET, array_flip(['tienda', 'desde', 'hasta', 'rango'])),
    static fn($value): bool => is_scalar($value) && (string) $value !== ''
);
$demandQuery = static function (array $changes) use ($pageParams, $demandFrom, $demandTo, $demandFilter, $demandPage): string {
    $params = array_merge($pageParams, [
        'bq_desde' => $demandFrom->format('Y-m-d'),
        'bq_hasta' => $demandTo->format('Y-m-d'),
        'bq_cob' => $demandFilter,
        'bq_pag' => $demandPage,
    ], $changes);
    if ($params['bq_cob'] === 'todos') {
        unset($params['bq_cob']);
    }
    if ((int) $params['bq_pag'] <= 1) {
        unset($params['bq_pag']);
    }
    return '?' . http_build_query($params) . '#an-demand';
};
$demandPageLinks = range(max(1, min($demandPage - 1, $demandPages - 2)), min($demandPages, max($demandPage + 1, 3)));

render_head('Métricas Avanzadas y Analytics');
?>
<link rel="stylesheet" href="./css/admin.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php render_navbar('admin'); ?>

<style>
.an-page {
  --an-line: var(--border-soft);
  --an-tint: color-mix(in srgb, var(--brand) 9%, transparent);
  --an-blue: var(--brand-2);
  --an-amber: var(--accent);
  --an-green: var(--success);
  --an-red: var(--danger);
  padding: 1.15rem 0 3.5rem;
}

.an-page svg.lucide,
body.theme-light .an-page svg.lucide {
  color: var(--icon-c, currentColor) !important;
  stroke: currentColor !important;
}

.an-page svg.lucide *,
body.theme-light .an-page svg.lucide * {
  color: inherit !important;
  stroke: currentColor !important;
}

.an-page .pd-crumb-link svg.lucide { --icon-c: var(--brand-2); }

.an-panel {
  position: relative;
  min-width: 0;
  background: var(--bg-card);
  border: 1px solid var(--an-line);
  border-radius: 1.15rem;
}

.an-kicker {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--brand-2);
  text-transform: uppercase;
  letter-spacing: var(--ls-overline);
  font-size: var(--fs-overline);
  font-weight: var(--fw-semibold);
  line-height: 1.3;
}

/* ── Cabecera + filtros ── */
.an-head {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  gap: 1rem 1.5rem;
  align-items: end;
  padding: 1.35rem 1.5rem 0;
  overflow: hidden;
}

.an-head-mark {
  --icon-c: color-mix(in srgb, var(--brand) 16%, transparent);
  position: absolute;
  top: -1.4rem;
  right: -1rem;
  pointer-events: none;
  transform: rotate(-14deg);
}

.an-head-mark svg.lucide {
  width: 7.5rem !important;
  height: 7.5rem !important;
  stroke-width: 1.4;
}

.an-head-copy { position: relative; min-width: 0; }

.an-title {
  margin: 0.15rem 0 0.35rem;
  font-size: min(var(--fs-h1), 1.95rem);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: var(--lh-heading);
  color: var(--text-main);
  white-space: nowrap;
}

.an-lead {
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-small);
  text-wrap: pretty;
}

.an-lead-line {
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.an-lead strong { color: var(--text-main); font-weight: var(--fw-semibold); }

.an-filter {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  flex-wrap: nowrap;
  align-items: flex-end;
  justify-content: flex-end;
  justify-self: end;
  gap: 0.5rem;
}

.an-filter > .an-field { flex: 0 0 auto; }
.an-filter > .an-field:has(> select) { flex: 0 1 11rem; min-width: 8.5rem; }

.an-field { display: grid; gap: 0.3rem; min-width: 0; }

.an-label {
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
}

.an-page .an-input {
  min-height: 2.6rem;
  padding: 0.45rem 0.75rem;
  border: 1px solid var(--an-line) !important;
  border-radius: var(--radius-control);
  background-color: color-mix(in srgb, var(--bg-elevated) 70%, transparent) !important;
  color: var(--text-main) !important;
  font-size: var(--fs-small);
  box-shadow: none !important;
  transition: border-color 0.2s ease;
}

body.theme-light .an-page .an-input {
  border-color: #dde3ec !important;
  background-color: #f4f6fa !important;
}

.an-page .an-input:hover { border-color: var(--border-strong) !important; }
.an-page .an-input:focus { border-color: var(--brand) !important; outline: none; }
.an-page select.an-input { width: 100%; min-width: 0; padding-right: 2.2rem; }

.an-dates {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.an-dates-sep { color: var(--text-soft); }

.an-store-chip {
  --icon-c: var(--brand-2);
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 2.6rem;
  padding: 0.4rem 0.85rem;
  border: 1px dashed var(--an-line);
  border-radius: var(--radius-control);
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
}

.an-page .an-btn {
  --icon-c: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  min-height: 2.6rem;
  padding: 0.45rem 1.05rem;
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  box-shadow: none;
  white-space: nowrap;
}

.an-presets {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem 1rem;
  margin: 0 -1.5rem;
  padding: 0.7rem 1.5rem;
  border-top: 1px dashed var(--an-line);
}

.an-chips { display: flex; flex-wrap: wrap; gap: 0.35rem; }

.an-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.28rem 0.75rem;
  border: 1px solid var(--an-line);
  border-radius: 999px;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
  text-decoration: none;
  transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease;
}

.an-chip:hover,
.an-chip:focus-visible {
  border-color: var(--brand);
  background: var(--an-tint);
  color: var(--brand-2);
}

.an-chip.is-active {
  border-color: var(--brand);
  background: var(--brand);
  color: #fff;
}

.an-updated {
  --icon-c: var(--an-green);
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.an-updated strong { color: var(--text-main); font-weight: var(--fw-semibold); }

/* ── Tira de indicadores ── */
.an-kpis {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  margin-top: 0.85rem;
}

.an-kpi {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
  padding: 1rem 1.15rem 1.05rem;
}

.an-kpi + .an-kpi { border-left: 1px solid var(--an-line); }

.an-kpi-top {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
}

.an-kpi-icon {
  --icon-c: var(--kpi-c, var(--an-blue));
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.6rem;
  background: color-mix(in srgb, var(--kpi-c, var(--an-blue)) 13%, transparent);
}

.an-kpi-value {
  margin-top: 0.35rem;
  color: var(--text-main);
  font-size: var(--fs-stat);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}

.an-kpi-value small {
  margin-left: 0.15rem;
  color: var(--text-soft);
  font-size: 0.55em;
  font-weight: var(--fw-semibold);
}

.an-kpi-meta {
  display: flex;
  flex-wrap: wrap;
  align-content: flex-start;
  align-items: center;
  gap: 0 0.35rem;
  margin-top: auto;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: 1.3;
}

.an-split {
  display: flex;
  height: 0.4rem;
  margin: 0.3rem 0 0.25rem;
  border-radius: 999px;
  overflow: hidden;
  background: color-mix(in srgb, var(--text-soft) 14%, transparent);
}

.an-split span { display: block; height: 100%; }
.an-split .is-drop { background: var(--an-green); }
.an-split .is-rise { background: var(--an-red); }

.an-split-legend { display: flex; gap: 0.75rem; line-height: var(--lh-small); }

.an-split-legend span {
  --icon-c: var(--c);
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
}

.an-split-legend strong { color: var(--text-main); font-weight: var(--fw-semibold); }

.an-stars { display: inline-flex; gap: 0.1rem; }
.an-stars svg.lucide { --icon-c: var(--an-amber); }
.an-stars .is-on svg.lucide { fill: var(--an-amber) !important; }
.an-stars .is-on svg.lucide * { fill: var(--an-amber) !important; }

/* ── Rejilla de paneles ── */
.an-grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 0.85rem;
  margin-top: 0.85rem;
}

.an-activity { grid-column: span 8; }
.an-catalog { grid-column: span 4; }
.an-rank { grid-column: span 7; }
.an-demand { grid-column: span 5; }
.an-cats,
.an-offers,
.an-rep { grid-column: span 4; }

.an-card {
  display: flex;
  flex-direction: column;
  padding: 1.15rem 1.25rem 1.2rem;
}

.an-card-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.6rem 1rem;
  margin-bottom: 1rem;
}

.an-demand .an-card-head,
.an-cats .an-card-head,
.an-offers .an-card-head,
.an-rep .an-card-head {
  padding-bottom: 0.85rem;
  border-bottom: 1px solid var(--an-line);
}

.an-offers .an-card-head:has(+ .an-list),
.an-rep .an-card-head:has(+ .an-list) { margin-bottom: 0.2rem; }

.an-card-title {
  --icon-c: var(--card-c, var(--an-blue));
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-h4);
  font-weight: var(--fw-semibold);
  line-height: var(--lh-heading);
}

.an-card-sub {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
  text-wrap: pretty;
}

.an-empty {
  --icon-c: color-mix(in srgb, var(--brand-2) 70%, var(--text-soft));
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 9rem;
  padding: 1.25rem;
  border: 1px dashed var(--an-line);
  border-radius: 0.9rem;
  color: var(--text-soft);
  font-size: var(--fs-small);
  text-align: center;
}

.an-empty-icon {
  display: grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 0.8rem;
  background: var(--an-tint);
}

/* Actividad */
.an-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.an-legend li {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.3rem 0.7rem;
  border: 1px solid var(--an-line);
  border-radius: 999px;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.an-legend li::before {
  content: "";
  width: 0.9rem;
  height: 0;
  border-top: 2px solid var(--c);
}

.an-legend li.is-dashed::before { border-top-style: dashed; }
.an-legend strong { color: var(--text-main); font-weight: var(--fw-semibold); }

.an-chart {
  position: relative;
  flex: 1 1 auto;
  min-height: 15.5rem;
}

.an-origin {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 0.6rem 1rem;
  margin-top: 1rem;
  padding-top: 0.9rem;
  border-top: 1px dashed var(--an-line);
}

.an-origin-label {
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
  text-transform: uppercase;
  letter-spacing: var(--ls-overline);
}

.an-stack {
  display: flex;
  gap: 2px;
  height: 0.4rem;
  border-radius: 999px;
  overflow: hidden;
}

.an-stack span { display: block; height: 100%; background: var(--c); }

.an-origin-list {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 1.1rem;
  margin: 0;
  padding: 0;
  list-style: none;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.an-origin-list li { display: inline-flex; align-items: center; gap: 0.4rem; }

.an-origin-list li::before {
  content: "";
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 0.2rem;
  background: var(--c);
}

.an-origin-list strong { color: var(--text-main); font-weight: var(--fw-semibold); }

/* Catálogo */
.an-ring-row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.85rem;
  border: 1px solid var(--an-line);
  border-radius: 0.95rem;
  background: color-mix(in srgb, var(--brand) 4%, transparent);
}

.an-ring {
  position: relative;
  flex: 0 0 auto;
  width: 5.25rem;
  height: 5.25rem;
}

.an-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }

.an-ring circle {
  fill: none;
  stroke-width: 5;
}

.an-ring-track { stroke: color-mix(in srgb, var(--text-soft) 16%, transparent); }

.an-ring-fill {
  stroke: var(--an-green);
  stroke-linecap: round;
  stroke-dasharray: var(--pct) 100;
}

.an-ring-value {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  color: var(--text-main);
  font-size: 1.05rem;
  font-weight: var(--fw-bold);
  font-variant-numeric: tabular-nums;
}

.an-ring-copy { display: grid; gap: 0.1rem; min-width: 0; }

.an-ring-copy strong {
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
}

.an-ring-copy span {
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.an-meters {
  display: grid;
  gap: 0.8rem;
  margin: 1rem 0 0;
  padding: 0;
  list-style: none;
}

.an-meter-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.35rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.an-meter-top > span {
  --icon-c: var(--c);
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.an-meter-top strong { color: var(--text-main); font-weight: var(--fw-semibold); font-variant-numeric: tabular-nums; }

.an-bar {
  height: 0.4rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--text-soft) 14%, transparent);
  overflow: hidden;
}

.an-bar > span {
  display: block;
  width: var(--w);
  min-width: 0.4rem;
  height: 100%;
  border-radius: inherit;
  background: var(--c, var(--brand));
}

.an-bar > span[style*="--w: 0%"] { display: none; }

.an-facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.5rem;
  margin: auto 0 0;
  padding-top: 1rem;
}

.an-fact {
  min-width: 0;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--an-line);
  border-radius: 0.75rem;
}

.an-fact dt {
  color: var(--text-soft);
  font-size: 0.6875rem;
  font-weight: var(--fw-semibold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.an-fact dd {
  margin: 0.15rem 0 0;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Ranking de productos */
.an-tabs {
  display: inline-flex;
  padding: 0.2rem;
  border: 1px solid var(--an-line);
  border-radius: 999px;
}

.an-tab {
  --icon-c: currentColor;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.8rem;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.an-tab:hover { color: var(--text-main); }

.an-tab.is-active {
  background: var(--brand);
  color: #fff;
}

.an-list {
  display: grid;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.an-thumb {
  display: block;
  width: 2.75rem;
  height: 2.75rem;
  padding: 0.2rem;
  border: 1px solid var(--an-line);
  border-radius: 0.65rem;
  background: #fff;
  overflow: hidden;
}

.an-thumb img { width: 100%; height: 100%; object-fit: contain; }

.an-item-copy { display: grid; min-width: 0; }

.an-item-name {
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.an-item-name:hover { color: var(--brand-2); }

.an-item-meta {
  --icon-c: var(--text-soft);
  display: flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  overflow: hidden;
  white-space: nowrap;
}

.an-item-meta span { overflow: hidden; text-overflow: ellipsis; }

.an-rank .an-card-head {
  flex-direction: column;
  flex-wrap: nowrap;
  align-items: stretch;
  gap: 0.75rem;
  margin-bottom: 0.85rem;
}

.an-rank .an-card-head > div:first-child {
  min-width: 0;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid var(--an-line);
}

.an-rank .an-card-sub {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.an-rank .an-tabs { align-self: flex-start; }

.an-rank-pane {
  --rank-c: var(--brand);
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 0.75rem;
  min-height: 0;
}

.an-rank-pane.is-favs { --rank-c: color-mix(in srgb, var(--an-red) 72%, var(--text-soft)); }
.an-rank-pane[hidden] { display: none; }

.an-rank-body {
  display: grid;
  flex: 1 1 auto;
  grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.3fr);
  gap: 0.75rem;
  min-height: 0;
}

.an-stock-chip {
  --icon-c: var(--an-red);
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 0.25rem;
  padding: 0.05rem 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--an-red) 13%, transparent);
  color: var(--an-red);
  font-size: 0.6875rem;
  font-weight: var(--fw-semibold);
  line-height: 1.5;
  white-space: nowrap;
}

/* #1 destacado */
.an-top {
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding: 0.75rem 0.85rem 0.9rem;
  border: 1px solid color-mix(in srgb, var(--rank-c) 28%, var(--an-line));
  border-radius: 1rem;
  background: color-mix(in srgb, var(--rank-c) 7%, var(--bg-card));
}

.an-top-badge {
  --icon-c: #fff;
  display: inline-flex;
  align-self: flex-start;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.7rem;
  border-radius: 999px;
  background: var(--rank-c);
  color: #fff;
  font-size: var(--fs-caption);
  font-weight: var(--fw-bold);
  white-space: nowrap;
}

.an-top-media {
  display: block;
  flex: 0 0 auto;
  height: 7.5rem;
  margin: 0.65rem 0 0.85rem;
  padding: 0.6rem;
  border: 1px solid var(--an-line);
  border-radius: 0.85rem;
  background: #fff;
  overflow: hidden;
}

.an-top-media img { display: block; width: 100%; height: 100%; object-fit: contain; }

.an-top-name {
  display: -webkit-box;
  overflow: hidden;
  color: var(--text-main);
  font-size: var(--fs-body, 1rem);
  font-weight: var(--fw-bold);
  line-height: 1.3;
  text-decoration: none;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.an-top-name:hover { color: var(--brand-2); }

.an-top-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
  margin-top: 0.35rem;
  min-width: 0;
}

.an-top-store {
  --icon-c: var(--text-soft);
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  min-width: 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.an-top-store span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.an-top-price {
  margin-top: 0.3rem;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-bold);
  font-variant-numeric: tabular-nums;
}

.an-top-score {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  margin-top: auto;
  padding-top: 0.65rem;
  border-top: 1px dashed color-mix(in srgb, var(--rank-c) 35%, var(--an-line));
  color: var(--rank-c);
}

.an-top-score strong {
  font-size: 2.1rem;
  font-weight: var(--fw-bold);
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.an-top-score span { font-size: var(--fs-small); font-weight: var(--fw-semibold); }

.an-top-facts {
  display: grid;
  gap: 0.3rem;
  margin: 0.55rem 0 0;
  padding: 0;
  list-style: none;
}

.an-top-facts li {
  --icon-c: var(--rank-c);
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.an-top-facts strong { color: var(--text-main); font-weight: var(--fw-bold); }

/* Puestos 2 a 6 */
.an-rank-list {
  display: grid;
  grid-auto-rows: minmax(0, 1fr);
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.an-rank-item {
  display: grid;
  grid-template-columns: 1.1rem 2.6rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.7rem;
  min-height: 3.6rem;
  padding: 0.5rem 0.75rem 0.5rem 0.65rem;
  border: 1px solid var(--an-line);
  border-radius: 0.85rem;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.an-rank-item:not(.is-slot):hover {
  border-color: color-mix(in srgb, var(--rank-c) 35%, var(--an-line));
  background: color-mix(in srgb, var(--rank-c) 4%, transparent);
}

.an-rank-item .an-thumb { width: 2.6rem; height: 2.6rem; }

.an-rank-pos {
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-bold);
  font-variant-numeric: tabular-nums;
  text-align: center;
}

.an-rank-copy { display: grid; gap: 0.2rem; min-width: 0; }

.an-rank-meta {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.an-rank-meta-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.an-rank-copy .an-bar { height: 0.25rem; margin-top: 0.15rem; }
.an-rank-copy .an-bar > span { background: var(--rank-c); }

.an-rank-score {
  display: grid;
  justify-items: end;
  gap: 0.15rem;
  font-variant-numeric: tabular-nums;
}

.an-rank-score strong {
  --icon-c: var(--rank-c);
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-bold);
}

.an-rank-score span { color: var(--text-soft); font-size: var(--fs-caption); }

.an-rank-item.is-slot { border-style: dashed; }
.an-rank-item.is-slot .an-rank-pos { color: var(--text-soft); }
.an-rank-item.is-slot .an-thumb { border-style: dashed; background: transparent; }

.an-rank-slot {
  grid-column: 3 / -1;
  color: var(--text-soft);
  font-size: var(--fs-small);
}

/* Resumen del ranking */
.an-rank-stats {
  display: flex;
  gap: 0.5rem;
  margin: 0;
}

.an-rank-stat {
  --stat-c: var(--rank-c);
  display: flex;
  flex: 1 1 auto;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--an-line);
  border-radius: 0.8rem;
}

.an-rank-stat.is-warn {
  --stat-c: var(--an-amber);
  border-color: color-mix(in srgb, var(--an-amber) 25%, var(--an-line));
  background: color-mix(in srgb, var(--an-amber) 6%, transparent);
}

.an-rank-stat.is-ok { --stat-c: var(--an-green); }

.an-rank-stat-icon {
  --icon-c: var(--stat-c);
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.6rem;
  background: color-mix(in srgb, var(--stat-c) 12%, transparent);
}

.an-rank-stat > div { min-width: 0; }

.an-rank-stat dt,
.an-rank-stat dd {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.35;
}

.an-rank-stat dt {
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-regular, 400);
}

.an-rank-stat dd {
  margin: 0.1rem 0 0;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-regular, 400);
}

.an-rank-stat dd strong { color: var(--text-main); font-weight: var(--fw-bold); }

/* Demanda */
.an-demand { height: 40rem; }
.an-demand .an-card-head > div { flex: 1 1 0; min-width: 0; }
.an-card-sub-line { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-demand .an-card-sub strong { color: var(--an-amber); font-weight: var(--fw-semibold); }
.an-demand .an-card-sub.is-covered strong { color: var(--an-green); }

.an-demand-tools {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.75rem;
  margin: 0 0 0.75rem;
}

.an-demand-dates .an-input {
  flex: 1 1 0;
  min-width: 0;
  min-height: 2.2rem;
  padding: 0.3rem 0.6rem;
  font-size: var(--fs-caption);
}

.an-page .an-icon-btn {
  flex: 0 0 auto;
  width: 2.2rem;
  min-height: 2.2rem;
  padding: 0;
}

.an-seg { display: flex; }
.an-seg .an-tab { position: relative; flex: 1 1 auto; justify-content: center; min-width: 0; padding-inline: 0.55rem; white-space: nowrap; }
.an-seg .an-tab + .an-tab::before {
  content: "";
  position: absolute;
  top: 25%;
  bottom: 25%;
  left: 0;
  border-left: 1px dashed var(--an-line);
  pointer-events: none;
}
.an-seg .an-tab.is-active::before,
.an-seg .an-tab.is-active + .an-tab::before { display: none; }

.an-tab-count {
  padding: 0 0.4rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--text-soft) 16%, transparent);
  font-size: 0.6875rem;
  font-variant-numeric: tabular-nums;
}

.an-tab.is-active .an-tab-count { background: rgb(255 255 255 / 0.22); }
.an-tab.is-opportunity:not(.is-active) .an-tab-count {
  background: color-mix(in srgb, var(--an-amber) 18%, transparent);
  color: var(--an-amber);
}

.an-terms {
  display: grid;
  flex: 1 1 auto;
  align-content: start;
  gap: 0.45rem;
  min-height: 0;
  margin: 0 0 0.85rem;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.an-pager {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  margin-top: auto;
  padding-top: 1rem;
  border-top: 1px solid var(--an-line);
}

.an-pager-info {
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-variant-numeric: tabular-nums;
}

.an-pager-info strong { color: var(--text-main); font-weight: var(--fw-semibold); }

.an-pager-nav { display: flex; gap: 0.25rem; }

.an-page-btn {
  --icon-c: currentColor;
  display: inline-grid;
  place-items: center;
  min-width: 1.75rem;
  height: 1.75rem;
  padding: 0 0.35rem;
  border: 1px solid var(--an-line);
  border-radius: 0.55rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
  font-variant-numeric: tabular-nums;
  text-decoration: none;
  transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease;
}

a.an-page-btn:hover,
a.an-page-btn:focus-visible {
  border-color: var(--brand);
  background: var(--an-tint);
  color: var(--brand-2);
}

.an-page-btn.is-active {
  border-color: var(--brand);
  background: var(--brand);
  color: #fff;
}

.an-page-btn.is-disabled { opacity: 0.4; }

.an-term {
  --c: var(--an-green);
  --bar-c: var(--an-blue);
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  grid-template-areas:
    "rank name status"
    "rank bar  count";
  align-items: center;
  gap: 0.3rem 0.75rem;
  padding: 0.55rem 0.9rem 0.6rem;
  border: 1px solid var(--an-line);
  border-radius: 0.9rem;
  color: var(--text-main);
  line-height: 1.3;
  text-decoration: none;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.an-term.is-gap {
  --c: var(--an-amber);
  --bar-c: var(--an-amber);
  border: 1px dashed color-mix(in srgb, var(--an-amber) 60%, transparent);
}

.an-term:hover,
.an-term:focus-visible {
  border-color: var(--bar-c);
  background: color-mix(in srgb, var(--bar-c) 6%, transparent);
  color: var(--text-main);
}

.an-term-rank {
  grid-area: rank;
  align-self: start;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: 1.55;
  font-variant-numeric: tabular-nums;
}

.an-term-name {
  grid-area: name;
  overflow: hidden;
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.an-term-status {
  --icon-c: var(--c);
  grid-area: status;
  display: inline-flex;
  align-items: center;
  justify-self: end;
  gap: 0.35rem;
  color: var(--c);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
  white-space: nowrap;
}

.an-term .an-bar { grid-area: bar; height: 0.3rem; }
.an-term .an-bar > span { background: var(--bar-c); }

.an-term-count {
  grid-area: count;
  justify-self: end;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.an-callout {
  --icon-c: var(--an-amber);
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0.85rem 0 0;
  padding: 0.65rem 0.85rem;
  border: 1px solid color-mix(in srgb, var(--an-amber) 28%, transparent);
  border-left: 3px solid var(--an-amber);
  border-radius: 0.6rem;
  background: color-mix(in srgb, var(--an-amber) 8%, var(--bg-card));
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.an-callout svg.lucide { flex: 0 0 auto; margin-top: 0.1rem; }
.an-callout strong { color: var(--text-main); }

/* Categorías */
.an-cats {
  --an-rest: color-mix(in srgb, var(--text-soft) 30%, var(--bg-elevated));
  --an-seam: #131217;
}

body.theme-light .an-cats { --an-seam: #fff; }

/* La altura la fijan las tarjetas vecinas; el contenido se adapta a ella */
@media (min-width: 768px) {
  .an-cats { contain: size; }
}

.an-rose {
  display: flex;
  flex: 1 1 auto;
  align-items: stretch;
  gap: 0.6rem;
  min-height: 0;
  margin-bottom: 0.75rem;
}

.an-cats .an-card-head { margin-bottom: 0.75rem; }

.an-rose-chart {
  display: flex;
  flex: 0 0 auto;
  flex-direction: column;
  align-self: center;
  gap: 0.6rem;
  width: clamp(8.5rem, 45%, 11.5rem);
}

.an-rose-chart svg {
  display: block;
  width: 100%;
  height: auto;
  overflow: visible;
  -webkit-tap-highlight-color: transparent;
  user-select: none;
}

.an-rose-chart svg,
.an-rose-chart svg * { outline: none; }

.an-rose-item {
  cursor: pointer;
  transition: opacity 0.2s ease;
}

.an-rose-shape {
  transform-box: view-box;
  transform-origin: 50% 50%;
  pointer-events: none;
  transition: transform 0.22s cubic-bezier(0.3, 0.7, 0.4, 1);
}

.an-rose-hit {
  fill: transparent;
  stroke: transparent;
  stroke-width: 3;
}

/* El trazo con el color de la tarjeta separa las porciones y converge en el centro */
.an-rose-slice {
  fill: var(--c);
  stroke: var(--an-seam);
  stroke-width: 2.5;
  stroke-linejoin: round;
}

.an-rose-pct {
  font-family: inherit;
  font-size: 10px;
  font-weight: var(--fw-semibold);
  font-variant-numeric: tabular-nums;
  text-anchor: middle;
  dominant-baseline: central;
  pointer-events: none;
}

.an-rose.is-focus .an-rose-item { opacity: 0.35; }
.an-rose.is-focus .an-rose-item.is-active { opacity: 1; }
.an-rose-item.is-active .an-rose-shape { transform: scale(1.08); }

.an-rose-total {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 0.3rem;
  padding: 0.3rem 0.6rem;
  border: 1px solid var(--an-line);
  border-radius: 0.6rem;
  background: color-mix(in srgb, var(--text-soft) 6%, transparent);
  white-space: nowrap;
  pointer-events: none;
}

.an-rose-total strong {
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}

.an-rose-total span {
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: 1.2;
}

.an-rose-keys {
  display: grid;
  flex: 1 1 0;
  grid-template-columns: minmax(0, 1fr);
  grid-auto-rows: minmax(0, 1.4rem);
  align-content: space-evenly;
  gap: 0;
  min-width: 0;
  min-height: 0;
  overflow-y: auto;
  scrollbar-width: thin;
  margin: 0 -3px;
  padding: 3px;
  list-style: none;
}

.an-rose-key {
  display: grid;
  grid-template-columns: 0.6rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.35rem;
  width: 100%;
  height: 100%;
  padding: 0 0.3rem;
  line-height: 1.2;
  border: 1px solid transparent;
  border-radius: 0.55rem;
  background: none;
  color: var(--text-main);
  font: inherit;
  font-size: var(--fs-caption);
  text-align: left;
}

.an-rose-key:hover,
.an-rose-key.is-active { border-color: var(--an-line); }

.an-rose-key[aria-pressed="true"] { background: color-mix(in srgb, var(--c) 12%, transparent); }

.an-rose-key:focus-visible {
  outline: 2px solid var(--c);
  outline-offset: 1px;
}

.an-rose-swatch {
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 0.2rem;
  background: var(--c);
}

.an-rose-name {
  min-width: 0;
  overflow: hidden;
  font-weight: var(--fw-medium);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.an-rose-key.is-rest .an-rose-name { color: var(--text-soft); }

.an-rose-share {
  color: var(--text-soft);
  font-variant-numeric: tabular-nums;
}

.an-rose-facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
  margin: auto 0 0;
  padding-top: 0.7rem;
  border-top: 1px dashed var(--an-line);
}

.an-rose-facts > div { min-width: 0; }

.an-rose-facts dt {
  color: var(--text-soft);
  font-size: 0.68rem;
  font-weight: var(--fw-medium);
  letter-spacing: var(--ls-overline);
  text-transform: uppercase;
}

.an-rose-facts dd {
  margin: 0.15rem 0 0;
  overflow: hidden;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Compensa el interlineado del texto sobre la barra */
.an-store .an-item-copy { padding-bottom: 0.3rem; }

.an-cat-top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.3rem;
  font-size: var(--fs-caption);
}

.an-cat-num {
  flex: 0 0 auto;
  color: var(--text-soft);
  font-variant-numeric: tabular-nums;
}

.an-cat-num strong { color: var(--text-main); }

/* Ofertas */
.an-offer {
  display: grid;
  grid-template-columns: 2.75rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.7rem;
  padding: 0.45rem 0.5rem;
  border: 1px solid transparent;
  border-radius: 0.85rem;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.an-offer:hover {
  border-color: var(--an-line);
  background: color-mix(in srgb, var(--brand) 4%, transparent);
}

.an-price {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  font-size: var(--fs-caption);
  white-space: nowrap;
}

.an-price strong { color: var(--text-main); font-weight: var(--fw-bold); }
.an-price s { color: var(--text-soft); }

.an-off {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--success) 16%, transparent);
  color: var(--success);
  font-size: var(--fs-caption);
  font-style: normal;
  font-weight: var(--fw-bold);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

/* Reputación / tiendas */
.an-score {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
}

.an-score-num {
  color: var(--text-main);
  font-size: 2.6rem;
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-display);
  line-height: 1;
}

.an-score-copy { display: grid; gap: 0.25rem; color: var(--text-soft); font-size: var(--fs-caption); }

.an-dist {
  display: grid;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.an-dist li {
  --icon-c: var(--an-amber);
  display: grid;
  grid-template-columns: 2rem minmax(0, 1fr) 2rem;
  align-items: center;
  gap: 0.6rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-variant-numeric: tabular-nums;
}

.an-dist li > span:first-child {
  display: inline-grid;
  grid-template-columns: 0.7em auto;
  align-items: center;
  justify-items: center;
  gap: 0.2rem;
  text-align: center;
}
.an-dist li > span:last-child { text-align: right; }

.an-rep .an-dist,
.an-rep .an-empty { margin-bottom: 1rem; }

.an-rep-foot {
  margin-top: auto;
  padding-top: 1rem;
  border-top: 1px dashed var(--an-line);
}

.an-rep-cta {
  --icon-c: currentColor;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.7rem 1rem;
  border: 1px solid color-mix(in srgb, var(--an-amber) 45%, transparent);
  border-radius: 0.8rem;
  background: color-mix(in srgb, var(--an-amber) 14%, transparent);
  color: color-mix(in srgb, var(--an-amber) 55%, var(--text-main));
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  text-decoration: none;
  transition: background-color 0.2s ease, border-color 0.2s ease;
}

.an-rep-cta:hover,
.an-rep-cta:focus-visible {
  border-color: var(--an-amber);
  background: color-mix(in srgb, var(--an-amber) 22%, transparent);
  color: color-mix(in srgb, var(--an-amber) 55%, var(--text-main));
}

.an-rep-cta svg:last-child { transition: transform 0.2s ease; }
.an-rep-cta:hover svg:last-child { transform: translateX(3px); }

.an-store {
  display: grid;
  grid-template-columns: 2.75rem minmax(0, 1fr);
  align-items: center;
  gap: 0.7rem;
  height: 100%;
  padding: 0 0.5rem;
  border-radius: 0.85rem;
}

/* Filas de altura fija para que los separadores coincidan entre tarjetas */
.an-offers .an-list,
.an-rep .an-list {
  --an-row-gap: 0.4rem;
  grid-auto-rows: 3rem;
  gap: var(--an-row-gap);
}

.an-offers .an-list > li,
.an-rep .an-list > li {
  position: relative;
  min-width: 0;
  border: 1px solid transparent;
}

.an-offers .an-offer { padding-block: 0; }

.an-offers .an-list > li + li::before,
.an-rep .an-list > li + li::before {
  content: "";
  position: absolute;
  top: calc(var(--an-row-gap) / -2 - 1px);
  right: -1px;
  left: -1px;
  border-top: 1px dashed var(--an-line);
  pointer-events: none;
}

.an-store-logo {
  flex: 0 0 auto;
  width: 2.75rem;
  height: 2.75rem;
  padding: 0.2rem;
  border: 1px solid var(--an-line);
  border-radius: 0.65rem;
  background: #fff;
  object-fit: contain;
}

.an-store-rating {
  --icon-c: var(--an-amber);
  display: inline;
  white-space: nowrap;
}

.an-store-rating svg {
  margin-right: 0.2rem;
  vertical-align: -0.15em;
}

/* ── Adaptación ── */
@media (max-width: 1199.98px) {
  .an-head { grid-template-columns: minmax(0, 1fr); }
  .an-filter { justify-self: start; justify-content: flex-start; }
  .an-kpis { grid-template-columns: repeat(6, minmax(0, 1fr)); }
  .an-kpi:nth-child(-n + 3) { grid-column: span 2; }
  .an-kpi:nth-child(n + 4) { grid-column: span 3; border-top: 1px solid var(--an-line); }
  .an-kpi:nth-child(4) { border-left: 0; }

  .an-activity { grid-column: span 7; }
  .an-catalog { grid-column: span 5; }
  .an-rank,
  .an-demand,
  .an-cats,
  .an-offers { grid-column: span 6; }
  .an-rep { grid-column: span 12; }
}

@media (max-width: 991.98px) {
  .an-activity,
  .an-catalog,
  .an-rank,
  .an-demand { grid-column: span 12; }
  .an-demand { height: auto; }
}

@media (max-width: 767.98px) {
  .an-head { padding: 1.15rem 1rem 0; }
  .an-title { font-size: clamp(1.15rem, 5.8vw, 1.7rem); }
  .an-presets { margin: 0 -1rem; padding: 0.7rem 1rem; }
  .an-filter { flex-wrap: wrap; }
  .an-filter > .an-field,
  .an-filter > .an-field:has(> select) { flex: 1 1 100%; min-width: 0; }
  .an-filter,
  .an-field,
  .an-dates { width: 100%; }
  .an-dates .an-input { flex: 1 1 0; min-width: 0; }
  .an-page select.an-input { min-width: 0; width: 100%; }
  .an-page .an-btn { width: 100%; }

  .an-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .an-kpi:nth-child(n) { grid-column: span 1; border-left: 0; border-top: 1px solid var(--an-line); }
  .an-kpi:nth-child(-n + 2) { border-top: 0; }
  .an-kpi:nth-child(even) { border-left: 1px solid var(--an-line); }
  .an-kpi:last-child { grid-column: span 2; }

  .an-cats,
  .an-offers,
  .an-rep { grid-column: span 12; }
  .an-card { padding: 1rem; }
  .an-rank-body { grid-template-columns: minmax(0, 1fr); }
  .an-rank-stats { flex-direction: column; }
  .an-top-score { margin-top: 0.85rem; }
  .an-origin { grid-template-columns: minmax(0, 1fr); }
}

.an-tip {
  position: fixed;
  z-index: 1080;
  max-width: min(18rem, calc(100vw - 1rem));
  padding: 0.4rem 0.6rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.5rem;
  background: var(--bg-elevated);
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
  color: var(--text-main);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
  line-height: 1.35;
  pointer-events: none;
}

.an-tip[hidden] { display: none; }

@media (max-width: 419.98px) {
  .an-rose { flex-direction: column; }
  .an-rose-chart { width: min(100%, 12rem); }
}

@media (prefers-reduced-motion: reduce) {
  .an-chip,
  .an-tab,
  .an-rank-item,
  .an-offer,
  .an-term,
  .an-rose-item,
  .an-rose-shape,
  .an-page-btn { transition: none; }
}
</style>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="an-page" aria-labelledby="an-title">
  <div class="container position-relative z-1">

    <nav aria-label="breadcrumb" class="pd-crumb">
      <ol class="breadcrumb custom-breadcrumb pd-crumb-list mb-0">
        <li class="breadcrumb-item">
          <a href="index.php" class="pd-crumb-link"><?= lucide_icon('home', '', 14) ?><span>Inicio</span></a>
        </li>
        <li class="breadcrumb-item">
          <a href="admin.php" class="pd-crumb-link">Panel</a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current">Métricas</span>
        </li>
      </ol>
    </nav>

    <header class="an-panel an-head">
      <span class="an-head-mark" aria-hidden="true"><?= lucide_icon('chart-pie', '', 120) ?></span>

      <div class="an-head-copy">
        <p class="an-kicker"><?= lucide_icon('chart-column-increasing', '', 14) ?>Business Intelligence</p>
        <h1 id="an-title" class="an-title">
          <?= $storeId > 0 ? 'Rendimiento de ' . e($storeName) : 'Panel general de métricas' ?>
        </h1>
        <p class="an-lead">
          <span class="an-lead-line">Del <strong><?= e($fmtDay($from) . ($from->format('Y') !== $to->format('Y') ? ' ' . $from->format('Y') : '')) ?></strong> al <strong><?= e($fmtDay($to) . ' ' . $to->format('Y')) ?></strong></span>
          <span class="an-lead-line"><?= $periodDays ?> días, comparados con los <?= $periodDays ?> anteriores.</span>
        </p>
      </div>

      <form class="an-filter" method="get">
        <?php if (!$isEmpresa): ?>
          <div class="an-field">
            <label class="an-label" for="an-store">Tienda</label>
            <select id="an-store" name="tienda" class="form-select an-input">
              <option value="0">Todas las tiendas</option>
              <?php foreach ($stores as $store): ?>
                <option value="<?= (int) $store['idtiendas'] ?>" <?= $storeId === (int) $store['idtiendas'] ? 'selected' : '' ?>>
                  <?= e($store['tie_nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="an-field">
            <span class="an-label">Tienda</span>
            <span class="an-store-chip"><?= lucide_icon('store', '', 16) ?><?= e($storeName) ?></span>
          </div>
        <?php endif; ?>

        <div class="an-field">
          <span class="an-label" id="an-dates-label">Período</span>
          <div class="an-dates" role="group" aria-labelledby="an-dates-label">
            <input type="date" name="desde" class="form-control an-input" value="<?= e($dateFrom) ?>" max="<?= e($today->format('Y-m-d')) ?>" aria-label="Desde">
            <span class="an-dates-sep" aria-hidden="true">–</span>
            <input type="date" name="hasta" class="form-control an-input" value="<?= e($dateTo) ?>" max="<?= e($today->format('Y-m-d')) ?>" aria-label="Hasta">
          </div>
        </div>

        <button class="btn btn-primary an-btn" type="submit">
          <?= lucide_icon('list-filter', '', 16) ?>Aplicar
        </button>
      </form>

      <div class="an-presets">
        <nav class="an-chips" aria-label="Rangos rápidos">
          <?php foreach ($presets as $days => $label): ?>
            <a href="<?= e($presetQuery($days)) ?>" class="an-chip<?= $range === $days ? ' is-active' : '' ?>"<?= $range === $days ? ' aria-current="true"' : '' ?>>
              Últimos <?= e($label) ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <?php if ($ultimaActualizacion): ?>
          <p class="an-updated">
            <?= lucide_icon('refresh-cw', '', 14) ?>
            Precios actualizados el <strong><?= e($fmtDay($ultimaActualizacion) . ', ' . $ultimaActualizacion->format('H:i')) ?></strong>
          </p>
        <?php endif; ?>
      </div>
    </header>

    <section class="an-panel an-kpis" aria-label="Indicadores principales">
      <article class="an-kpi" style="--kpi-c: var(--an-blue)">
        <div class="an-kpi-top"><span class="an-kpi-icon"><?= lucide_icon('mouse-pointer-click', '', 17) ?></span>Clicks a tiendas</div>
        <strong class="an-kpi-value"><?= $fmtNum($totalClicks) ?></strong>
        <span class="an-kpi-meta">
          <?= $clicksDelta !== '' ? e($clicksDelta) . ' · ' : '' ?>vs. <?= $fmtNum($prevClicks) ?> antes
        </span>
      </article>

      <article class="an-kpi" style="--kpi-c: var(--an-blue)">
        <div class="an-kpi-top"><span class="an-kpi-icon"><?= lucide_icon('users', '', 17) ?></span>Visitantes únicos</div>
        <strong class="an-kpi-value"><?= $fmtNum($visitantes) ?></strong>
        <span class="an-kpi-meta">
          <?= $visitantes > 0 ? number_format($totalClicks / $visitantes, 1, ',', '.') . ' clicks por visitante' : 'Personas distintas que hicieron click' ?>
        </span>
      </article>

      <article class="an-kpi" style="--kpi-c: var(--an-red)">
        <div class="an-kpi-top"><span class="an-kpi-icon"><?= lucide_icon('heart', '', 17) ?></span>Nuevos favoritos</div>
        <strong class="an-kpi-value"><?= $fmtNum($totalFavs) ?></strong>
        <span class="an-kpi-meta">
          <?= $favsDelta !== '' ? e($favsDelta) . ' · ' : '' ?><?= $fmtNum($favsEnListas) ?> en listas hoy
        </span>
      </article>

      <article class="an-kpi" style="--kpi-c: var(--an-green)">
        <div class="an-kpi-top"><span class="an-kpi-icon"><?= lucide_icon('arrow-down-up', '', 17) ?></span>Cambios de precio</div>
        <strong class="an-kpi-value"><?= $fmtNum($priceChanges) ?></strong>
        <div class="an-split" role="img" aria-label="<?= $priceDrops ?> bajas y <?= $priceRises ?> subas">
          <?php if ($priceChanges > 0): ?>
            <span class="is-drop" style="width: <?= $fmtPct($priceDrops, $priceChanges) ?>%"></span>
            <span class="is-rise" style="width: <?= 100 - $fmtPct($priceDrops, $priceChanges) ?>%"></span>
          <?php endif; ?>
        </div>
        <span class="an-kpi-meta an-split-legend">
          <span style="--c: var(--an-green)"><?= lucide_icon('trending-down', '', 13) ?><strong><?= $fmtNum($priceDrops) ?></strong> bajas</span>
          <span style="--c: var(--an-red)"><?= lucide_icon('trending-up', '', 13) ?><strong><?= $fmtNum($priceRises) ?></strong> subas</span>
        </span>
      </article>

      <?php if ($storeId > 0): ?>
        <article class="an-kpi" style="--kpi-c: var(--an-amber)">
          <div class="an-kpi-top"><span class="an-kpi-icon"><?= lucide_icon('star', '', 17) ?></span>Reputación</div>
          <strong class="an-kpi-value"><?= $totalReviews > 0 ? number_format($ratingPromedio, 1, ',', '.') : '—' ?><small>/ 5</small></strong>
          <span class="an-kpi-meta"><?= $fmtNum($totalReviews) ?> opiniones · <?= $fmtNum($reviewsPeriodo) ?> nuevas</span>
        </article>
      <?php else: ?>
        <article class="an-kpi" style="--kpi-c: var(--an-amber)">
          <div class="an-kpi-top"><span class="an-kpi-icon"><?= lucide_icon('store', '', 17) ?></span>Tiendas activas</div>
          <strong class="an-kpi-value"><?= $fmtNum($activeStores) ?></strong>
          <span class="an-kpi-meta">
            <?= $globalRating > 0 ? number_format($globalRating, 1, ',', '.') . ' ★ de reputación promedio' : 'Aún sin opiniones' ?>
          </span>
        </article>
      <?php endif; ?>
    </section>

    <div class="an-grid">

      <section class="an-panel an-card an-activity" aria-labelledby="an-activity-title">
        <header class="an-card-head">
          <div>
            <h2 id="an-activity-title" class="an-card-title"><?= lucide_icon('activity', '', 18) ?>Actividad del período</h2>
            <p class="an-card-sub">Clicks hacia las tiendas y productos guardados, agrupados por <?= e($granularityLabel) ?>.</p>
          </div>
          <ul class="an-legend">
            <li style="--c: var(--an-blue)">Clicks <strong><?= $fmtNum($totalClicks) ?></strong></li>
            <li class="is-dashed" style="--c: var(--an-amber)">Favoritos <strong><?= $fmtNum($totalFavs) ?></strong></li>
          </ul>
        </header>

        <?php if ($hasActivity): ?>
          <div class="an-chart"><canvas id="anActivity" aria-label="Gráfico de clicks y favoritos por <?= e($granularityLabel) ?>" role="img"></canvas></div>
        <?php else: ?>
          <div class="an-empty">
            <span class="an-empty-icon"><?= lucide_icon('chart-spline', '', 20) ?></span>
            Sin clicks ni favoritos en este período. Prueba con un rango más amplio.
          </div>
        <?php endif; ?>

        <?php if ($clickOrigins): ?>
          <?php $originColors = ['var(--an-blue)', 'var(--an-amber)', 'var(--an-green)', 'var(--an-red)', 'var(--text-soft)']; ?>
          <footer class="an-origin">
            <span class="an-origin-label">Origen del click</span>
            <div class="an-stack" role="img" aria-label="Distribución de clicks por sección de origen">
              <?php foreach ($clickOrigins as $i => $origin): ?>
                <span style="width: <?= max(1, $fmtPct((int) $origin['total'], $totalClicks)) ?>%; --c: <?= $originColors[$i % count($originColors)] ?>"></span>
              <?php endforeach; ?>
            </div>
            <ul class="an-origin-list">
              <?php foreach ($clickOrigins as $i => $origin): ?>
                <li style="--c: <?= $originColors[$i % count($originColors)] ?>">
                  <?= e($originNames[$origin['origen']] ?? ucfirst((string) $origin['origen'])) ?>
                  <strong><?= $fmtNum($origin['total']) ?></strong>
                  <span><?= $fmtPct((int) $origin['total'], $totalClicks) ?>%</span>
                </li>
              <?php endforeach; ?>
            </ul>
          </footer>
        <?php endif; ?>
      </section>

      <section class="an-panel an-card an-catalog" aria-labelledby="an-catalog-title">
        <header class="an-card-head">
          <div>
            <h2 id="an-catalog-title" class="an-card-title" style="--card-c: var(--an-green)"><?= lucide_icon('package-check', '', 18) ?>Salud del catálogo</h2>
            <p class="an-card-sub"><?= $fmtNum($totalProductosActivos) ?> productos activos <?= e($scopeLabel) ?>.</p>
          </div>
        </header>

        <div class="an-ring-row">
          <div class="an-ring" style="--pct: <?= $stockPct ?>">
            <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">
              <circle class="an-ring-track" cx="24" cy="24" r="20" pathLength="100" />
              <?php if ($stockPct > 0): ?>
                <circle class="an-ring-fill" cx="24" cy="24" r="20" pathLength="100" />
              <?php endif; ?>
            </svg>
            <span class="an-ring-value"><?= $stockPct ?>%</span>
          </div>
          <div class="an-ring-copy">
            <strong>Disponibles en stock</strong>
            <span><?= $fmtNum($enStock) ?> de <?= $fmtNum($totalProductosActivos) ?> productos se pueden comprar hoy.</span>
          </div>
        </div>

        <ul class="an-meters">
          <li>
            <div class="an-meter-top">
              <span style="--c: var(--an-blue)"><?= lucide_icon('image', '', 14) ?>Con foto</span>
              <strong><?= $fmtPct($conImagen, $totalProductosActivos) ?>%</strong>
            </div>
            <div class="an-bar"><span style="--w: <?= $fmtPct($conImagen, $totalProductosActivos) ?>%; --c: var(--an-blue)"></span></div>
          </li>
          <li>
            <div class="an-meter-top">
              <span style="--c: var(--an-green)"><?= lucide_icon('tag', '', 14) ?>Con precio publicado</span>
              <strong><?= $fmtPct($conPrecio, $totalProductosActivos) ?>%</strong>
            </div>
            <div class="an-bar"><span style="--w: <?= $fmtPct($conPrecio, $totalProductosActivos) ?>%; --c: var(--an-green)"></span></div>
          </li>
          <li>
            <div class="an-meter-top">
              <span style="--c: var(--an-amber)"><?= lucide_icon('badge-percent', '', 14) ?>En oferta</span>
              <strong><?= $fmtNum($enOferta) ?> · <?= $fmtPct($enOferta, $totalProductosActivos) ?>%</strong>
            </div>
            <div class="an-bar"><span style="--w: <?= $fmtPct($enOferta, $totalProductosActivos) ?>%; --c: var(--an-amber)"></span></div>
          </li>
        </ul>

        <dl class="an-facts">
          <div class="an-fact">
            <dt>Precio promedio</dt>
            <dd><?= $promedioPrecio > 0 ? e(gs(round($promedioPrecio))) : '—' ?></dd>
          </div>
          <div class="an-fact">
            <dt>Sin stock</dt>
            <dd><?= $fmtNum($totalProductosActivos - $enStock) ?> productos</dd>
          </div>
        </dl>
      </section>

      <section id="an-demand" class="an-panel an-card an-demand" aria-labelledby="an-demand-title">
        <header class="an-card-head">
          <div>
            <h2 id="an-demand-title" class="an-card-title" style="--card-c: var(--an-amber)"><?= lucide_icon('search', '', 18) ?>Lo que busca la gente</h2>
            <?php if ($demandFilter === 'oportunidad'): ?>
              <p class="an-card-sub an-card-sub-line" title="Términos buscados sin productos <?= e($scopeLabel) ?>, candidatos para reponer inventario."><strong>Demanda sin cubrir:</strong> búsquedas sin productos <?= e($scopeLabel) ?>.</p>
            <?php elseif ($demandFilter === 'cubre'): ?>
              <p class="an-card-sub an-card-sub-line is-covered" title="Términos buscados que ya tienen productos <?= e($scopeLabel) ?> para mostrar."><strong>Demanda cubierta:</strong> búsquedas con productos <?= e($scopeLabel) ?>.</p>
            <?php elseif ($demandCounts['oportunidad'] > 0): ?>
              <p class="an-card-sub an-card-sub-line" title="Lo más buscado en Caacuprecio y cuántos productos <?= e($scopeLabel) ?> responden a cada búsqueda."><strong><?= $fmtNum($demandCounts['oportunidad']) ?> <?= $demandCounts['oportunidad'] === 1 ? 'búsqueda' : 'búsquedas' ?></strong> sin tus productos; son oportunidades de venta.</p>
            <?php else: ?>
              <p class="an-card-sub an-card-sub-line is-covered" title="Lo más buscado en Caacuprecio y cuántos productos <?= e($scopeLabel) ?> responden a cada búsqueda."><strong>Todas las búsquedas</strong> tienen tus productos.</p>
            <?php endif; ?>
          </div>
        </header>

        <form class="an-demand-tools" method="get" action="#an-demand">
          <?php foreach ($pageParams as $name => $value): ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
          <?php endforeach; ?>
          <input type="hidden" name="bq_cob" value="<?= e($demandFilter) ?>">

          <div class="an-dates an-demand-dates" role="group" aria-label="Período de las búsquedas">
            <input type="date" name="bq_desde" class="form-control an-input" value="<?= e($demandFrom->format('Y-m-d')) ?>" max="<?= e($today->format('Y-m-d')) ?>" aria-label="Búsquedas desde">
            <span class="an-dates-sep" aria-hidden="true">–</span>
            <input type="date" name="bq_hasta" class="form-control an-input" value="<?= e($demandTo->format('Y-m-d')) ?>" max="<?= e($today->format('Y-m-d')) ?>" aria-label="Búsquedas hasta">
            <button type="submit" class="btn btn-primary an-btn an-icon-btn" title="Aplicar período" aria-label="Aplicar período">
              <?= lucide_icon('list-filter', '', 15) ?>
            </button>
          </div>

          <div class="an-tabs an-seg" role="group" aria-label="Filtrar por cobertura">
            <?php foreach ($demandFilters as $key => $filter): ?>
              <button type="submit" name="bq_cob" value="<?= e($key) ?>" class="an-tab<?= $demandFilter === $key ? ' is-active' : '' ?><?= $key === 'oportunidad' ? ' is-opportunity' : '' ?>" aria-pressed="<?= $demandFilter === $key ? 'true' : 'false' ?>">
                <?= e($filter['label']) ?>
                <span class="an-tab-count"><?= $fmtNum($demandCounts[$key]) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </form>

        <?php if ($topSearches): ?>
          <ol class="an-terms" start="<?= $demandOffset + 1 ?>">
            <?php foreach ($topSearches as $i => $term): ?>
              <?php
                $times = (int) $term['total'];
                $matches = (int) $term['matches'];
              ?>
              <li>
                <a href="buscar.php?q=<?= urlencode((string) $term['termino']) ?>" class="an-term <?= $matches > 0 ? 'is-covered' : 'is-gap' ?>" title="Ver resultados de «<?= e($term['termino']) ?>»">
                  <span class="an-term-rank"><?= str_pad((string) ($demandOffset + $i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                  <strong class="an-term-name"><?= e($term['termino']) ?></strong>
                  <span class="an-term-status">
                    <?php if ($matches > 0): ?>
                      <?= lucide_icon('check', '', 13) ?><?= $fmtNum($matches) ?> <?= $matches === 1 ? 'producto' : 'productos' ?>
                    <?php else: ?>
                      <?= lucide_icon('sparkles', '', 13) ?>Oportunidad
                    <?php endif; ?>
                  </span>
                  <span class="an-bar" aria-hidden="true"><span style="--w: <?= $fmtPct($times, (int) $maxSearch) ?>%"></span></span>
                  <span class="an-term-count"><?= $fmtNum($times) ?> <?= $times === 1 ? 'búsqueda' : 'búsquedas' ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php else: ?>
          <div class="an-empty">
            <span class="an-empty-icon"><?= lucide_icon('search', '', 20) ?></span>
            <?php if (!$searchTerms): ?>
              No se registraron búsquedas entre el <?= e($fmtDay($demandFrom)) ?> y el <?= e($fmtDay($demandTo)) ?>.
            <?php elseif ($demandFilter === 'oportunidad'): ?>
              Todos los términos buscados tienen productos <?= e($scopeLabel) ?>.
            <?php else: ?>
              Ningún término buscado tiene productos <?= e($scopeLabel) ?> en este período.
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($searchTerms): ?>
          <footer class="an-pager">
            <span class="an-pager-info">
              <?php if ($demandTotal > 0): ?>
                <?= $demandOffset + 1 ?>–<?= $demandOffset + count($topSearches) ?> de <?= $fmtNum($demandTotal) ?> ·
              <?php endif; ?>
              <strong><?= $fmtNum($demandSearches) ?></strong> búsquedas
            </span>
            <?php if ($demandPages > 1): ?>
              <nav class="an-pager-nav" aria-label="Páginas de términos buscados">
                <?php if ($demandPage > 1): ?>
                  <a href="<?= e($demandQuery(['bq_pag' => $demandPage - 1])) ?>" class="an-page-btn" aria-label="Página anterior"><?= lucide_icon('chevron-left', '', 14) ?></a>
                <?php else: ?>
                  <span class="an-page-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevron-left', '', 14) ?></span>
                <?php endif; ?>
                <?php foreach ($demandPageLinks as $p): ?>
                  <a href="<?= e($demandQuery(['bq_pag' => $p])) ?>" class="an-page-btn<?= $p === $demandPage ? ' is-active' : '' ?>"<?= $p === $demandPage ? ' aria-current="page"' : '' ?>><?= $p ?></a>
                <?php endforeach; ?>
                <?php if ($demandPage < $demandPages): ?>
                  <a href="<?= e($demandQuery(['bq_pag' => $demandPage + 1])) ?>" class="an-page-btn" aria-label="Página siguiente"><?= lucide_icon('chevron-right', '', 14) ?></a>
                <?php else: ?>
                  <span class="an-page-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevron-right', '', 14) ?></span>
                <?php endif; ?>
              </nav>
            <?php endif; ?>
          </footer>
        <?php endif; ?>
      </section>

      <section class="an-panel an-card an-rank" aria-labelledby="an-rank-title">
        <header class="an-card-head">
          <div>
            <h2 id="an-rank-title" class="an-card-title"><?= lucide_icon('trophy', '', 18) ?>Productos que más interesan</h2>
            <p class="an-card-sub">Lo que más miran y guardan tus visitantes, con su peso sobre el total.</p>
          </div>
          <div class="an-tabs" role="tablist" aria-label="Ordenar ranking">
            <button type="button" class="an-tab is-active" role="tab" aria-selected="true" aria-controls="an-pane-clicks" data-an-tab="clicks">
              <?= lucide_icon('mouse-pointer-click', '', 13) ?>Clicks
            </button>
            <button type="button" class="an-tab" role="tab" aria-selected="false" aria-controls="an-pane-favs" data-an-tab="favs">
              <?= lucide_icon('heart', '', 13) ?>Favoritos
            </button>
          </div>
        </header>

        <?php
          $rankPanes = [
              'clicks' => [
                  'items' => $topClicked, 'max' => $maxClicked, 'base' => $totalClicks,
                  'icon' => 'mouse-pointer-click', 'badge' => 'Más clickeado', 'unit' => ['click', 'clicks'], 'verb' => 'clicks', 'share' => 'de los clicks',
                  'extra' => ['users', 'persona distinta', 'personas distintas'],
                  'empty' => 'Aún no hay clicks en este período.',
              ],
              'favs' => [
                  'items' => $topFavorites, 'max' => $maxFav, 'base' => $favsEnListas,
                  'icon' => 'heart', 'badge' => 'Más guardado', 'unit' => ['favorito', 'favoritos'], 'verb' => 'guardados', 'share' => 'de favoritos',
                  'extra' => ['calendar-plus', 'nuevo en el período', 'nuevos en el período'],
                  'empty' => 'Ningún producto fue guardado en favoritos todavía.',
              ],
          ];
          $rankSlots = 6;
        ?>
        <?php foreach ($rankPanes as $key => $pane): ?>
          <div id="an-pane-<?= $key ?>" class="an-rank-pane is-<?= $key ?>" role="tabpanel" data-an-pane="<?= $key ?>"<?= $key !== 'clicks' ? ' hidden' : '' ?>>
            <?php if ($pane['items']): ?>
              <?php
                $items = $pane['items'];
                $lead = $items[0];
                $leadTotal = (int) $lead['total'];
                $leadExtra = (int) $lead['extra'];
                $topSum = array_sum(array_map('intval', array_column($items, 'total')));
                $second = isset($items[1]) ? (int) $items[1]['total'] : 0;
                $outOfStock = count(array_filter($items, static fn($row) => (int) $row['pro_en_stock'] !== 1));
                $leadUrl = 'producto.php?id=' . (int) $lead['idproductos'];
              ?>
              <div class="an-rank-body">
                <article class="an-top">
                  <span class="an-top-badge"><?= lucide_icon('crown', '', 14) ?>#1 · <?= e($pane['badge']) ?></span>
                  <a href="<?= e($leadUrl) ?>" class="an-top-media" tabindex="-1" aria-hidden="true">
                    <img src="<?= e(image_url($lead['pro_imagen'], $lead['pro_nombre'])) ?>" alt="" loading="lazy" decoding="async">
                  </a>
                  <a href="<?= e($leadUrl) ?>" class="an-top-name" title="<?= e($lead['pro_nombre']) ?>"><?= e($lead['pro_nombre']) ?></a>
                  <div class="an-top-meta">
                    <span class="an-top-store"><?= lucide_icon('store', '', 13) ?><span><?= e($lead['tie_nombre']) ?></span></span>
                    <?php if ((int) $lead['pro_en_stock'] !== 1): ?>
                      <span class="an-stock-chip"><?= lucide_icon('package-x', '', 11) ?>Sin stock</span>
                    <?php endif; ?>
                  </div>
                  <strong class="an-top-price"><?= e(gs($lead['pro_precio'])) ?></strong>
                  <div class="an-top-score">
                    <strong><?= $fmtNum($leadTotal) ?></strong>
                    <span><?= $leadTotal === 1 ? $pane['unit'][0] : $pane['unit'][1] ?></span>
                  </div>
                  <ul class="an-top-facts">
                    <li><?= lucide_icon('chart-pie', '', 13) ?><span><strong><?= $fmtPct($leadTotal, (int) $pane['base']) ?>%</strong> del total</span></li>
                    <li><?= lucide_icon($pane['extra'][0], '', 13) ?><span><?= $fmtNum($leadExtra) ?> <?= $leadExtra === 1 ? $pane['extra'][1] : $pane['extra'][2] ?></span></li>
                  </ul>
                </article>

                <ol class="an-rank-list" start="2">
                  <?php for ($pos = 1; $pos < $rankSlots; $pos++): ?>
                    <?php $item = $items[$pos] ?? null; ?>
                    <?php if ($item): ?>
                      <li class="an-rank-item">
                        <span class="an-rank-pos"><?= $pos + 1 ?></span>
                        <a href="producto.php?id=<?= (int) $item['idproductos'] ?>" class="an-thumb" tabindex="-1" aria-hidden="true">
                          <img src="<?= e(image_url($item['pro_imagen'], $item['pro_nombre'])) ?>" alt="" loading="lazy" decoding="async">
                        </a>
                        <div class="an-rank-copy">
                          <a href="producto.php?id=<?= (int) $item['idproductos'] ?>" class="an-item-name" title="<?= e($item['pro_nombre']) ?>"><?= e($item['pro_nombre']) ?></a>
                          <span class="an-rank-meta">
                            <span class="an-rank-meta-text"><?= e($item['tie_nombre']) ?> · <?= e(gs($item['pro_precio'])) ?></span>
                            <?php if ((int) $item['pro_en_stock'] !== 1): ?>
                              <span class="an-stock-chip">Sin stock</span>
                            <?php endif; ?>
                          </span>
                          <div class="an-bar"><span style="--w: <?= $fmtPct((int) $item['total'], (int) $pane['max']) ?>%"></span></div>
                        </div>
                        <div class="an-rank-score">
                          <strong><?= lucide_icon($pane['icon'], '', 13) ?><?= $fmtNum($item['total']) ?></strong>
                          <span><?= $fmtPct((int) $item['total'], (int) $pane['base']) ?>%</span>
                        </div>
                      </li>
                    <?php else: ?>
                      <li class="an-rank-item is-slot">
                        <span class="an-rank-pos"><?= $pos + 1 ?></span>
                        <span class="an-thumb"></span>
                        <span class="an-rank-slot">Puesto disponible</span>
                      </li>
                    <?php endif; ?>
                  <?php endfor; ?>
                </ol>
              </div>

              <dl class="an-rank-stats">
                <div class="an-rank-stat">
                  <span class="an-rank-stat-icon"><?= lucide_icon('chart-pie', '', 15) ?></span>
                  <div>
                    <dt>Peso del top <?= count($items) ?></dt>
                    <dd><strong><?= $fmtPct($topSum, (int) $pane['base']) ?>%</strong> <?= e($pane['share']) ?></dd>
                  </div>
                </div>
                <div class="an-rank-stat">
                  <span class="an-rank-stat-icon"><?= lucide_icon('arrow-left-right', '', 15) ?></span>
                  <div>
                    <dt>Líder frente al #2</dt>
                    <dd>
                      <?php if ($second > 0): ?>
                        <strong><?= number_format($leadTotal / $second, 1, ',', '.') ?>×</strong> más <?= e($pane['verb']) ?>
                      <?php else: ?>
                        <strong>Sin competidor</strong> todavía
                      <?php endif; ?>
                    </dd>
                  </div>
                </div>
                <div class="an-rank-stat<?= $outOfStock > 0 ? ' is-warn' : ' is-ok' ?>">
                  <span class="an-rank-stat-icon"><?= lucide_icon($outOfStock > 0 ? 'triangle-alert' : 'package-check', '', 15) ?></span>
                  <div>
                    <dt>Stock del ranking</dt>
                    <dd>
                      <?php if ($outOfStock > 0): ?>
                        <strong><?= $outOfStock ?> de <?= count($items) ?></strong> sin stock
                      <?php else: ?>
                        <strong>Todo</strong> con stock
                      <?php endif; ?>
                    </dd>
                  </div>
                </div>
              </dl>
            <?php else: ?>
              <div class="an-empty">
                <span class="an-empty-icon"><?= lucide_icon($pane['icon'], '', 20) ?></span>
                <?= e($pane['empty']) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="an-panel an-card an-cats" aria-labelledby="an-cats-title">
        <header class="an-card-head">
          <div>
            <h2 id="an-cats-title" class="an-card-title"><?= lucide_icon('layout-grid', '', 18) ?>Categorías principales</h2>
            <p class="an-card-sub">Peso de cada categoría en el catálogo activo.</p>
          </div>
        </header>

        <?php if ($catSegments): ?>
          <div class="an-rose" data-an-rose>
            <div class="an-rose-chart">
              <svg viewBox="0 0 <?= $rose['size'] ?> <?= $rose['size'] ?>" aria-hidden="true" focusable="false">
                <?php foreach ($catSegments as $i => $seg): ?>
                  <g class="an-rose-item" data-idx="<?= $i ?>" style="--c: <?= $seg['color'] ?>">
                    <g class="an-rose-shape">
                      <path class="an-rose-slice" d="<?= $seg['path'] ?>" />
                      <?php if ($seg['inside']): ?>
                        <text class="an-rose-pct" x="<?= $seg['tx'] ?>" y="<?= $seg['ty'] ?>" style="fill: <?= $seg['ink'] ?>"><?= $seg['pct'] ?>%</text>
                      <?php endif; ?>
                    </g>
                    <path class="an-rose-hit" d="<?= $seg['path'] ?>" />
                  </g>
                <?php endforeach; ?>
              </svg>
              <div class="an-rose-total" aria-hidden="true">
                <strong data-rose-value><?= $fmtNum($catBase) ?></strong>
                <span>productos</span>
              </div>
            </div>

            <ul class="an-rose-keys" aria-label="Categorías">
              <?php foreach ($catSegments as $i => $seg): ?>
                <?php
                  $info = $fmtNum($seg['count']) . ' ' . ($seg['count'] === 1 ? 'producto' : 'productos');
                  if ($seg['deals']) {
                      $info .= ' · ' . $fmtNum($seg['deals']) . ' ' . ($seg['deals'] === 1 ? 'oferta' : 'ofertas');
                  }
                ?>
                <li>
                  <button type="button" class="an-rose-key an-rose-item<?= $seg['deals'] === null ? ' is-rest' : '' ?>" data-idx="<?= $i ?>"
                          data-count="<?= $fmtNum($seg['count']) ?>" aria-pressed="false" aria-description="<?= e($info) ?>"
                          data-an-tip-host style="--c: <?= $seg['color'] ?>">
                    <span class="an-rose-swatch" aria-hidden="true"></span>
                    <span class="an-rose-name" data-an-clip><?= e($seg['name']) ?></span>
                    <span class="an-rose-share"><?= $seg['pct'] ?>%</span>
                  </button>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <dl class="an-rose-facts">
            <div>
              <dt>Top 3 concentran</dt>
              <dd data-an-clip><?= $catTop3Pct ?>% del catálogo</dd>
            </div>
            <div>
              <dt>Más ofertas</dt>
              <dd data-an-clip><?= $catTopDeals ? e($catTopDeals['name']) . ' · ' . $fmtNum($catTopDeals['deals']) : 'Sin ofertas activas' ?></dd>
            </div>
          </dl>
        <?php else: ?>
          <div class="an-empty">
            <span class="an-empty-icon"><?= lucide_icon('layout-grid', '', 20) ?></span>
            No hay categorías con productos activos.
          </div>
        <?php endif; ?>
      </section>

      <section class="an-panel an-card an-offers" aria-labelledby="an-offers-title">
        <header class="an-card-head">
          <div>
            <h2 id="an-offers-title" class="an-card-title" style="--card-c: var(--an-green)"><?= lucide_icon('badge-percent', '', 18) ?>Mayores descuentos</h2>
            <p class="an-card-sub">Ofertas vigentes con mayor rebaja.</p>
          </div>
        </header>

        <?php if ($bestOffers): ?>
          <ul class="an-list">
            <?php foreach ($bestOffers as $offer): ?>
              <li class="an-offer">
                <a href="producto.php?id=<?= (int) $offer['idproductos'] ?>" class="an-thumb" tabindex="-1" aria-hidden="true">
                  <img src="<?= e(image_url($offer['pro_imagen'], $offer['pro_nombre'])) ?>" alt="" loading="lazy" decoding="async">
                </a>
                <div class="an-item-copy">
                  <a href="producto.php?id=<?= (int) $offer['idproductos'] ?>" class="an-item-name" title="<?= e($offer['pro_nombre']) ?>"><?= e($offer['pro_nombre']) ?></a>
                  <span class="an-price">
                    <strong><?= e(gs($offer['pro_precio'])) ?></strong>
                    <s><?= e(gs($offer['pro_precio_anterior'])) ?></s>
                  </span>
                </div>
                <span class="an-off">−<?= (int) $offer['pct'] ?>%</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="an-empty">
            <span class="an-empty-icon"><?= lucide_icon('badge-percent', '', 20) ?></span>
            No hay ofertas activas en este momento.
          </div>
        <?php endif; ?>
      </section>

      <?php if ($storeId > 0): ?>
        <section class="an-panel an-card an-rep" aria-labelledby="an-rep-title">
          <header class="an-card-head">
            <div>
              <h2 id="an-rep-title" class="an-card-title" style="--card-c: var(--an-amber)"><?= lucide_icon('message-square-quote', '', 18) ?>Reputación de la tienda</h2>
              <p class="an-card-sub">Opiniones publicadas por los usuarios.</p>
            </div>
          </header>

          <?php if ($totalReviews > 0): ?>
            <div class="an-score">
              <span class="an-score-num"><?= number_format($ratingPromedio, 1, ',', '.') ?></span>
              <div class="an-score-copy">
                <span class="an-stars" aria-label="<?= number_format($ratingPromedio, 1, ',', '.') ?> de 5 estrellas">
                  <?php for ($s = 1; $s <= 5; $s++): ?>
                    <span class="<?= $s <= round($ratingPromedio) ? 'is-on' : '' ?>"><?= lucide_icon('star', '', 15) ?></span>
                  <?php endfor; ?>
                </span>
                <span><?= $fmtNum($totalReviews) ?> opiniones · <?= $fmtNum($reviewsPeriodo) ?> en el período</span>
              </div>
            </div>
            <ul class="an-dist">
              <?php foreach ($ratingDist as $stars => $count): ?>
                <li>
                  <span><?= $stars ?><?= lucide_icon('star', '', 11) ?></span>
                  <div class="an-bar"><span style="--w: <?= $fmtPct($count, $maxRatingBucket) ?>%; --c: var(--an-amber)"></span></div>
                  <span><?= $fmtNum($count) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="an-empty">
              <span class="an-empty-icon"><?= lucide_icon('message-square-quote', '', 20) ?></span>
              Esta tienda todavía no recibió opiniones.
            </div>
          <?php endif; ?>

          <footer class="an-rep-foot">
            <a href="tienda.php?id=<?= (int) $storeId ?>#reviews" class="an-rep-cta">
              <?= lucide_icon('message-square-text', '', 16) ?>
              <span>Ver reseñas de la tienda</span>
              <?= lucide_icon('arrow-right', '', 16) ?>
            </a>
          </footer>
        </section>
      <?php else: ?>
        <section class="an-panel an-card an-rep" aria-labelledby="an-rep-title">
          <header class="an-card-head">
            <div>
              <h2 id="an-rep-title" class="an-card-title" style="--card-c: var(--an-amber)"><?= lucide_icon('store', '', 18) ?>Tiendas más visitadas</h2>
              <p class="an-card-sub">Tiendas que más clicks recibieron en el período.</p>
            </div>
          </header>

          <?php if ($storeRanking): ?>
            <ul class="an-list">
              <?php foreach ($storeRanking as $store): ?>
                <li class="an-store">
                  <img src="<?= e($store['tie_logo'] ?: image_url('', $store['tie_nombre'])) ?>" alt="" class="an-store-logo" loading="lazy">
                  <div class="an-item-copy">
                    <div class="an-cat-top">
                      <a href="analytics.php?<?= e(http_build_query(['tienda' => (int) $store['idtiendas'], 'desde' => $dateFrom, 'hasta' => $dateTo])) ?>" class="an-item-name" title="Ver métricas de <?= e($store['tie_nombre']) ?>"><?= e($store['tie_nombre']) ?></a>
                      <span class="an-cat-num">
                        <?php if ($store['rating'] !== null): ?>
                          <span class="an-store-rating"><?= lucide_icon('star', '', 12) ?><?= number_format((float) $store['rating'], 1, ',', '.') ?></span> ·
                        <?php endif; ?>
                        <strong><?= $fmtNum($store['total']) ?></strong> clicks
                      </span>
                    </div>
                    <div class="an-bar"><span style="--w: <?= $fmtPct((int) $store['total'], (int) $maxStoreClicks) ?>%"></span></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="an-empty">
              <span class="an-empty-icon"><?= lucide_icon('store', '', 20) ?></span>
              Ninguna tienda recibió clicks en este período.
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

    </div>
  </div>
</section>

<script>
(() => {
  document.querySelectorAll('[data-an-tab]').forEach((tab) => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('[data-an-tab]').forEach((other) => {
        const active = other === tab;
        other.classList.toggle('is-active', active);
        other.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      document.querySelectorAll('[data-an-pane]').forEach((pane) => {
        pane.hidden = pane.dataset.anPane !== tab.dataset.anTab;
      });
    });
  });

  document.querySelectorAll('[data-an-rose]').forEach((rose) => {
    const items = rose.querySelectorAll('.an-rose-item');
    const value = rose.querySelector('[data-rose-value]');
    const keys = rose.querySelectorAll('.an-rose-key');
    const base = value.textContent;
    let pinned = null;

    const select = (idx) => {
      const key = idx === null ? null : rose.querySelector(`.an-rose-key[data-idx="${idx}"]`);
      rose.classList.toggle('is-focus', !!key);
      items.forEach((el) => el.classList.toggle('is-active', !!key && el.dataset.idx === idx));
      value.textContent = key ? key.dataset.count : base;
    };

    const press = (idx) => {
      pinned = pinned === idx ? null : idx;
      keys.forEach((el) => el.setAttribute('aria-pressed', el.dataset.idx === pinned ? 'true' : 'false'));
      select(pinned);
    };

    items.forEach((item) => {
      item.addEventListener('mouseenter', () => select(item.dataset.idx));
      item.addEventListener('mouseleave', () => select(pinned));
      item.addEventListener('focus', () => select(item.dataset.idx));
      item.addEventListener('blur', () => select(pinned));
      item.addEventListener('click', () => press(item.dataset.idx));
    });
    rose.querySelector('.an-rose-chart svg').addEventListener('mousedown', (ev) => ev.preventDefault());
    document.addEventListener('click', (ev) => {
      if (pinned !== null && !rose.contains(ev.target)) press(pinned);
    });
  });

  const tip = document.createElement('div');
  tip.className = 'an-tip';
  tip.setAttribute('role', 'tooltip');
  tip.hidden = true;
  document.body.append(tip);

  const hideTip = () => { tip.hidden = true; };
  const showTip = (host) => {
    const clip = host.matches('[data-an-clip]') ? host : host.querySelector('[data-an-clip]');
    if (!clip || clip.scrollWidth <= clip.clientWidth) return;
    tip.textContent = clip.textContent.trim();
    tip.hidden = false;
    const box = clip.getBoundingClientRect();
    const gap = 8;
    let top = box.top - tip.offsetHeight - gap;
    if (top < gap) top = box.bottom + gap;
    const left = Math.min(Math.max(gap, box.left + box.width / 2 - tip.offsetWidth / 2), window.innerWidth - tip.offsetWidth - gap);
    tip.style.top = `${top}px`;
    tip.style.left = `${left}px`;
  };

  document.querySelectorAll('[data-an-tip-host], [data-an-clip]:not([data-an-tip-host] [data-an-clip])').forEach((host) => {
    host.addEventListener('mouseenter', () => showTip(host));
    host.addEventListener('focus', () => showTip(host));
    host.addEventListener('mouseleave', hideTip);
    host.addEventListener('blur', hideTip);
  });
  window.addEventListener('scroll', hideTip, { passive: true });

  const canvas = document.getElementById('anActivity');
  if (!canvas || !window.Chart) return;

  const series = <?= json_encode(array_values($buckets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const css = (name) => getComputedStyle(document.body).getPropertyValue(name).trim();
  const dense = series.length > 40;

  const chart = new Chart(canvas, {
    type: 'line',
    data: {
      labels: series.map((b) => b.label),
      datasets: [
        { label: 'Clicks', data: series.map((b) => b.clicks), borderWidth: 2, cubicInterpolationMode: 'monotone', fill: false, pointRadius: dense ? 0 : 3, pointHoverRadius: 5 },
        { label: 'Favoritos', data: series.map((b) => b.favs), borderWidth: 2, borderDash: [5, 4], cubicInterpolationMode: 'monotone', fill: false, pointRadius: dense ? 0 : 3, pointHoverRadius: 5 },
      ],
    },
    options: {
      maintainAspectRatio: false,
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: { borderWidth: 1, padding: 10, cornerRadius: 10, boxPadding: 4, usePointStyle: true },
      },
      scales: {
        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 14 } },
        y: { beginAtZero: true, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } },
      },
    },
  });

  const paint = () => {
    const blue = css('--brand-2');
    const amber = css('--accent');
    const soft = css('--text-soft');
    const line = css('--border-soft');
    [blue, amber].forEach((color, i) => {
      const ds = chart.data.datasets[i];
      ds.borderColor = color;
      ds.backgroundColor = color;
      ds.pointBackgroundColor = color;
      ds.pointBorderColor = color;
    });
    chart.options.scales.x.ticks.color = soft;
    chart.options.scales.y.ticks.color = soft;
    chart.options.scales.y.grid.color = line;
    chart.options.scales.x.border = { color: line };
    Object.assign(chart.options.plugins.tooltip, {
      backgroundColor: css('--bg-elevated'),
      borderColor: line,
      titleColor: css('--text-main'),
      bodyColor: soft,
    });
    chart.update('none');
  };

  paint();
  new MutationObserver(paint).observe(document.body, { attributes: true, attributeFilter: ['class'] });
})();
</script>

<?php render_footer(); ?>
