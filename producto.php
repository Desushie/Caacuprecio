<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tracking.php';

$id = (int) ($_GET['id'] ?? 0);
$grupo = trim((string) ($_GET['grupo'] ?? ''));
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$pdo = db();
$userId = function_exists('current_user_id') ? current_user_id() : null;
$sessionId = session_id();
$userLogged = function_exists('is_logged_in') && is_logged_in();
$favoritesEnabled = function_exists('is_favorite_product') && function_exists('favorite_toggle_url');

$reportSuccess = isset($_GET['product_reported']) && $_GET['product_reported'] === '1';
$reportError = '';
$productReportModals = [];

function client_ip_address(): string
{
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($keys as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_map('trim', explode(',', $value));
            $value = (string) ($parts[0] ?? '');
        }

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '';
}


$product = null;
$groupName = '';
$groupProductIds = [];

/**
 * Busca producto base por grupo o por id
 */
if ($grupo !== '') {
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            t.idtiendas,
            t.tie_nombre,
            t.tie_descripcion,
            t.tie_logo,
            t.tie_ubicacion,
            t.tie_url,
            c.idcategorias,
            c.cat_nombre,
            c.cat_descripcion
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE p.pro_activo = 1
          AND COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) = :grupo
        ORDER BY p.pro_precio IS NULL, p.pro_precio ASC, p.pro_fecha_scraping DESC, p.idproductos DESC
        LIMIT 1
    ");
    $stmt->execute([':grupo' => $grupo]);
    $product = $stmt->fetch();

    if ($product) {
        $id = (int) $product['idproductos'];
        $groupName = trim((string) ($product['pro_grupo'] ?: $product['pro_nombre']));
    }
}

if (!$product && $id > 0) {
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            t.idtiendas,
            t.tie_nombre,
            t.tie_descripcion,
            t.tie_logo,
            t.tie_ubicacion,
            t.tie_url,
            c.idcategorias,
            c.cat_nombre,
            c.cat_descripcion
        FROM productos p
        INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
        LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE p.idproductos = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch();

    if ($product) {
        $groupName = trim((string) (($product['pro_grupo'] ?? '') !== '' ? $product['pro_grupo'] : $product['pro_nombre']));
    }
}

if (!$product) {
    http_response_code(404);
    die('Producto no encontrado.');
}

/**
 * IDs del grupo completo
 */
$idsStmt = $pdo->prepare("
    SELECT idproductos
    FROM productos
    WHERE pro_activo = 1
      AND COALESCE(NULLIF(TRIM(pro_grupo), ''), pro_nombre) = :grupo
    ORDER BY pro_precio IS NULL, pro_precio ASC, pro_fecha_scraping DESC
");
$idsStmt->execute([':grupo' => $groupName]);
$groupProductIds = array_map('intval', array_column($idsStmt->fetchAll(), 'idproductos'));

if (!$groupProductIds) {
    $groupProductIds = [(int) $product['idproductos']];
}

$placeholders = implode(',', array_fill(0, count($groupProductIds), '?'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'report_product') {
    $reportProductId = (int) ($_POST['product_id'] ?? 0);
    $reportName = trim((string) ($_POST['rep_nombre'] ?? ''));
    $reportEmail = trim((string) ($_POST['rep_email'] ?? ''));
    $reportReason = trim((string) ($_POST['rep_motivo'] ?? ''));
    $reportDetail = trim((string) ($_POST['rep_detalle'] ?? ''));

    $allowedReasons = [
        'precio_incorrecto',
        'desactualizado',
        'sin_stock',
        'producto_equivocado',
        'informacion_falsa',
        'otro',
    ];

    $ipAddress = client_ip_address();

    $captchaError = consent_error() ?: turnstile_error();

    if ($captchaError !== '') {
        $reportError = $captchaError;
    } elseif ($reportProductId <= 0) {
        $reportError = 'El producto seleccionado no es válido.';
    } elseif (!in_array($reportReason, $allowedReasons, true)) {
        $reportError = 'Selecciona un motivo válido.';
    } else {
        $productExistsStmt = $pdo->prepare("
            SELECT idproductos
            FROM productos
            WHERE idproductos = :product_id
              AND pro_activo = 1
            LIMIT 1
        ");
        $productExistsStmt->execute([
            ':product_id' => $reportProductId,
        ]);

        if (!$productExistsStmt->fetchColumn()) {
            $reportError = 'No se encontró el producto.';
        } else {
            $duplicateSql = "
                SELECT idreporte
                FROM producto_reportes
                WHERE productos_idproductos = :product_id
            ";

            $duplicateParams = [
                ':product_id' => $reportProductId,
            ];

            $duplicateConditions = [];

            if ($sessionId !== '') {
                $duplicateConditions[] = 'rep_session_id = :session_id_dup';
                $duplicateParams[':session_id_dup'] = $sessionId;
            }

            if ($ipAddress !== '') {
                $duplicateConditions[] = 'rep_ip = :ip_address_dup';
                $duplicateParams[':ip_address_dup'] = $ipAddress;
            }

            if ($duplicateConditions) {
                $duplicateSql .= " AND (" . implode(' OR ', $duplicateConditions) . ") LIMIT 1";
                $duplicateStmt = $pdo->prepare($duplicateSql);
                $duplicateStmt->execute($duplicateParams);

                if ($duplicateStmt->fetch()) {
                    $reportError = 'Ya reportaste este producto anteriormente.';
                }
            }

            if ($reportError === '') {
                $insertReport = $pdo->prepare("
                    INSERT INTO producto_reportes (
                        productos_idproductos,
                        rep_nombre,
                        rep_email,
                        rep_motivo,
                        rep_detalle,
                        rep_ip,
                        rep_session_id,
                        rep_estado,
                        rep_fecha
                    ) VALUES (
                        :product_id,
                        :nombre,
                        :email,
                        :motivo,
                        :detalle,
                        :ip,
                        :session_id,
                        'pendiente',
                        NOW()
                    )
                ");
                $insertReport->execute([
                    ':product_id' => $reportProductId,
                    ':nombre' => $reportName !== '' ? mb_substr($reportName, 0, 120) : null,
                    ':email' => $reportEmail !== '' ? mb_substr($reportEmail, 0, 150) : null,
                    ':motivo' => $reportReason,
                    ':detalle' => $reportDetail !== '' ? mb_substr($reportDetail, 0, 1000) : null,
                    ':ip' => $ipAddress !== '' ? $ipAddress : null,
                    ':session_id' => $sessionId !== '' ? $sessionId : null,
                ]);

                $redirectParams = [
                    'id' => (int) $product['idproductos'],
                    'product_reported' => 1,
                ];

                if ($grupo !== '') {
                    $redirectParams['grupo'] = $grupo;
                }
                if ($searchTerm !== '') {
                    $redirectParams['q'] = $searchTerm;
                }

                header('Location: producto.php?' . http_build_query($redirectParams));
                exit;
            }
        }
    }
}


/**
 * Tracking de vista: con cookie de sesión se registra acá; en la primera visita
 * (sin cookie) lo hace el navegador vía event.php, así los bots sin JS no cuentan.
 */
$trackViewInBrowser = !cp_is_bot_request() && !cp_has_session_cookie() && !cp_privacy_signal();
$trackViewOnServer = cp_should_track('views', 60);

if ($trackViewOnServer) {
    try {
        $stmtTrack = $pdo->prepare("
            INSERT INTO productos_vistos (usuario_idusuario, session_id, productos_idproductos)
            VALUES (:uid, :sid, :pid)
        ");
        $stmtTrack->bindValue(':uid', $userId ?: null, $userId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmtTrack->bindValue(':sid', $sessionId);
        $stmtTrack->bindValue(':pid', (int) $product['idproductos'], PDO::PARAM_INT);
        $stmtTrack->execute();
    } catch (Throwable $e) {
        // ignorar
    }
}

if ($trackViewOnServer && $searchTerm !== '') {
    try {
        $stmtClick = $pdo->prepare("
            INSERT INTO busqueda_click_producto (termino, productos_idproductos, usuario_idusuario, session_id)
            VALUES (:term, :pid, :uid, :sid)
        ");
        $stmtClick->bindValue(':term', $searchTerm);
        $stmtClick->bindValue(':pid', (int) $product['idproductos'], PDO::PARAM_INT);
        $stmtClick->bindValue(':uid', $userId ?: null, $userId ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmtClick->bindValue(':sid', $sessionId);
        $stmtClick->execute();
    } catch (Throwable $e) {
        // ignorar
    }
}

/**
 * Ofertas del grupo
 */
$offerSql = "
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
        p.pro_modelo,
        p.pro_grupo,
        t.idtiendas,
        t.tie_nombre,
        t.tie_logo,
        t.tie_url
    FROM productos p
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    WHERE p.idproductos IN ($placeholders)
      AND p.pro_activo = 1
    ORDER BY p.pro_precio IS NULL, p.pro_precio ASC, p.pro_fecha_scraping DESC, p.idproductos DESC
";
$offerStmt = $pdo->prepare($offerSql);
$offerStmt->execute($groupProductIds);
$offersRaw = $offerStmt->fetchAll();

/**
 * Deduplicar ofertas repetidas
 */
$offers = [];
$seenOffers = [];

foreach ($offersRaw as $offer) {
    $key = mb_strtolower(trim((string) ($offer['tie_nombre'] ?? ''))) . '|' .
           trim((string) ($offer['pro_url'] ?? '')) . '|' .
           trim((string) ($offer['pro_nombre'] ?? ''));

    if (isset($seenOffers[$key])) {
        continue;
    }

    $seenOffers[$key] = true;
    $offers[] = $offer;
}

/**
 * Historial mensual combinado del grupo
 * Último precio registrado por mes y por producto/tienda
 */
$historySql = "
    SELECT
        hp.his_precio,
        hp.his_en_stock,
        hp.his_fecha,
        p.idproductos,
        p.pro_nombre,
        t.idtiendas,
        t.tie_nombre,
        DATE_FORMAT(hp.his_fecha, '%m/%Y') AS month_label,
        DATE_FORMAT(hp.his_fecha, '%Y-%m') AS month_sort
    FROM historial_precios hp
    INNER JOIN productos p ON p.idproductos = hp.productos_idproductos
    INNER JOIN tiendas t ON t.idtiendas = p.tiendas_idtiendas
    INNER JOIN (
        SELECT
            hp2.productos_idproductos,
            DATE_FORMAT(hp2.his_fecha, '%Y-%m') AS month_key,
            MAX(hp2.his_fecha) AS max_fecha
        FROM historial_precios hp2
        WHERE hp2.productos_idproductos IN ($placeholders)
        GROUP BY hp2.productos_idproductos, DATE_FORMAT(hp2.his_fecha, '%Y-%m')
    ) monthly_idx
        ON monthly_idx.productos_idproductos = hp.productos_idproductos
       AND monthly_idx.month_key = DATE_FORMAT(hp.his_fecha, '%Y-%m')
       AND monthly_idx.max_fecha = hp.his_fecha
    WHERE hp.productos_idproductos IN ($placeholders)
    ORDER BY month_sort ASC, t.tie_nombre ASC, hp.his_fecha ASC, hp.idhistorial ASC
";
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute(array_merge($groupProductIds, $groupProductIds));
$historyRowsRaw = $historyStmt->fetchAll();

/**
 * Conservar un único registro por tienda y mes:
 * el menor precio encontrado entre todas las ofertas del grupo.
 * Si el mismo precio mínimo aparece varias veces, se conserva el registro más reciente.
 */
$historyByStoreMonth = [];

foreach ($historyRowsRaw as $entry) {
    $storeId = (int) ($entry['idtiendas'] ?? 0);
    $monthSort = trim((string) ($entry['month_sort'] ?? ''));
    $price = isset($entry['his_precio']) ? (float) $entry['his_precio'] : null;

    if ($storeId <= 0 || $monthSort === '' || $price === null) {
        continue;
    }

    $key = $storeId . '|' . $monthSort;

    if (!isset($historyByStoreMonth[$key])) {
        $historyByStoreMonth[$key] = $entry;
        continue;
    }

    $savedPrice = (float) $historyByStoreMonth[$key]['his_precio'];
    $savedDate = strtotime((string) $historyByStoreMonth[$key]['his_fecha']) ?: 0;
    $entryDate = strtotime((string) $entry['his_fecha']) ?: 0;

    if ($price < $savedPrice || ($price === $savedPrice && $entryDate > $savedDate)) {
        $historyByStoreMonth[$key] = $entry;
    }
}

$historyRows = array_values($historyByStoreMonth);

usort($historyRows, static function (array $a, array $b): int {
    $monthCompare = strcmp((string) ($b['month_sort'] ?? ''), (string) ($a['month_sort'] ?? ''));
    if ($monthCompare !== 0) {
        return $monthCompare;
    }

    $storeCompare = strcasecmp((string) ($a['tie_nombre'] ?? ''), (string) ($b['tie_nombre'] ?? ''));
    if ($storeCompare !== 0) {
        return $storeCompare;
    }

    return strcmp((string) ($b['his_fecha'] ?? ''), (string) ($a['his_fecha'] ?? ''));
});

/*
 * Tabla y gráfico usan el mismo criterio: un registro por tienda y mes
 * (el menor precio; si empatan, el más reciente).
 * La UI agrupa por mes para que no se confunda con ofertas duplicadas de la misma tienda.
 */
$history = $historyRows;
$historyGrouped = [];

foreach ($historyRows as $entry) {
    $monthSort = trim((string) ($entry['month_sort'] ?? ''));
    $monthLabel = trim((string) ($entry['month_label'] ?? ''));

    if ($monthSort === '' || $monthLabel === '') {
        continue;
    }

    if (!isset($historyGrouped[$monthSort])) {
        $historyGrouped[$monthSort] = [
            'label' => $monthLabel,
            'entries' => [],
        ];
    }

    $historyGrouped[$monthSort]['entries'][] = $entry;
}

foreach ($historyGrouped as &$historyMonthGroup) {
    usort($historyMonthGroup['entries'], static function (array $a, array $b): int {
        $priceCompare = ((float) ($a['his_precio'] ?? 0)) <=> ((float) ($b['his_precio'] ?? 0));
        if ($priceCompare !== 0) {
            return $priceCompare;
        }

        return strcasecmp((string) ($a['tie_nombre'] ?? ''), (string) ($b['tie_nombre'] ?? ''));
    });
}
unset($historyMonthGroup);

$historyMonthCount = count($historyGrouped);
$historyStoreCount = count(array_unique(array_map(
    static fn(array $row): int => (int) ($row['idtiendas'] ?? 0),
    $historyRows
)));

$historyStats = [
    'min' => null,
    'max' => null,
    'latest' => null,
    'latestDate' => null,
    'count' => 0,
];

$historyLabels = [];
$historySeries = [];

foreach (array_reverse($historyRows) as $entry) {
    $storeName = trim((string) ($entry['tie_nombre'] ?? 'Sin tienda'));
    $productName = trim((string) ($entry['pro_nombre'] ?? ''));
    $price = isset($entry['his_precio']) ? (float) $entry['his_precio'] : null;
    $monthLabel = trim((string) ($entry['month_label'] ?? ''));
    $monthSort = trim((string) ($entry['month_sort'] ?? ''));

    if ($storeName === '') {
        $storeName = 'Sin tienda';
    }

    if ($price === null || $monthLabel === '' || $monthSort === '') {
        continue;
    }

    $historyLabels[$monthSort] = $monthLabel;

    if (!isset($historySeries[$storeName])) {
        $historySeries[$storeName] = [
            'prices' => [],
            'products' => [],
        ];
    }

    $historySeries[$storeName]['prices'][$monthSort] = round($price, 2);
    $historySeries[$storeName]['products'][$monthSort] = $productName !== '' ? $productName : 'Sin nombre';

    $historyStats['min'] = $historyStats['min'] === null ? $price : min($historyStats['min'], $price);
    $historyStats['max'] = $historyStats['max'] === null ? $price : max($historyStats['max'], $price);
    $historyStats['latest'] = $price;
    $historyStats['latestDate'] = $entry['his_fecha'];
    $historyStats['count']++;
}

ksort($historyLabels);

$historyChartLabels = array_values($historyLabels);
$historyChartDatasets = [];
$historyGroupBase = trim((string) ($groupName ?: ($product['pro_nombre'] ?? '')));

$historyProductShort = static function (string $productName, string $groupBase, int $maxLen = 36) : string {
    $productName = trim($productName);
    $groupBase = trim($groupBase);

    if ($productName === '' || $productName === 'Sin nombre') {
        return '';
    }

    $short = $productName;

    if ($groupBase !== '') {
        $prefixLen = function_exists('mb_strlen') ? mb_strlen($groupBase) : strlen($groupBase);
        $startsWithGroup = function_exists('mb_stripos')
            ? mb_stripos($productName, $groupBase) === 0
            : stripos($productName, $groupBase) === 0;

        if ($startsWithGroup && $prefixLen > 0) {
            $rest = function_exists('mb_substr')
                ? mb_substr($productName, $prefixLen)
                : substr($productName, $prefixLen);
            $rest = trim($rest, " \t-–—|/·.,");
            if ($rest !== '') {
                $short = $rest;
            }
        }
    }

    $len = function_exists('mb_strlen') ? mb_strlen($short) : strlen($short);
    if ($len > $maxLen) {
        $short = (function_exists('mb_substr') ? mb_substr($short, 0, $maxLen - 1) : substr($short, 0, $maxLen - 1)) . '…';
    }

    return $short;
};

foreach ($historySeries as $storeName => $series) {
    $datasetData = [];
    $productByPoint = [];
    $productShortByPoint = [];

    foreach ($historyLabels as $monthSort => $monthLabel) {
        if (array_key_exists($monthSort, $series['prices'])) {
            $productName = (string) ($series['products'][$monthSort] ?? 'Sin nombre');
            $datasetData[] = $series['prices'][$monthSort];
            $productByPoint[] = $productName;
            $productShortByPoint[] = $historyProductShort($productName, $historyGroupBase);
        } else {
            $datasetData[] = null;
            $productByPoint[] = null;
            $productShortByPoint[] = null;
        }
    }

    $historyChartDatasets[] = [
        'label' => $storeName,
        'storeLabel' => $storeName,
        'productByPoint' => $productByPoint,
        'productShortByPoint' => $productShortByPoint,
        'data' => $datasetData,
        'tension' => 0.25,
        'fill' => false,
        'spanGaps' => true,
    ];
}

/**
 * Imágenes únicas del grupo para slider
 */
$galleryImages = [];
$seenImages = [];

foreach ($offers as $offer) {
    $img = trim((string) ($offer['pro_imagen'] ?? ''));
    if ($img === '') {
        continue;
    }

    if (isset($seenImages[$img])) {
        continue;
    }

    $seenImages[$img] = true;
    $galleryImages[] = [
        'src' => image_url($img, $offer['pro_nombre'] ?? $groupName),
        'alt' => $offer['pro_nombre'] ?? $groupName,
    ];
}

if (!$galleryImages) {
    $galleryImages[] = [
        'src' => image_url($product['pro_imagen'] ?? null, $groupName),
        'alt' => $groupName,
    ];
}

/**
 * Mejor precio
 */
$minPrice = null;
foreach ($offers as $offer) {
    if ($offer['pro_precio'] !== null && (float) $offer['pro_precio'] > 0) {
        $price = (float) $offer['pro_precio'];
        $minPrice = $minPrice === null ? $price : min($minPrice, $price);
    }
}

$bestOfferUrl = !empty($offers[0]['pro_url']) ? $offers[0]['pro_url'] : ($product['pro_url'] ?? '#');
$bestOfferProductId = !empty($offers[0]['idproductos']) ? (int) $offers[0]['idproductos'] : (int) $product['idproductos'];
$goSource = 'producto';
$goTerm = $searchTerm;

/**
 * Compartir producto
 */
$currentScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$currentRequestUri = $_SERVER['REQUEST_URI'] ?? ('/producto.php?id=' . (int) $product['idproductos']);
$shareUrl = $currentScheme . '://' . $currentHost . $currentRequestUri;
$shareTitle = trim((string) ($groupName ?: $product['pro_nombre']));
$sharePriceText = $minPrice !== null ? gs($minPrice) : 'un excelente precio';
$shareText = 'Mira, encontré este ' . $shareTitle . ' a tan solo ' . $sharePriceText . ' en Caacuprecio!';

$shareWhatsappUrl = 'https://wa.me/?text=' . rawurlencode($shareText . ' ' . $shareUrl);
$shareFacebookUrl = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($shareUrl);
$shareTwitterUrl = 'https://twitter.com/intent/tweet?text=' . rawurlencode($shareText) . '&url=' . rawurlencode($shareUrl);

/**
 * Productos similares agrupados por pro_grupo (misma categoría / marca)
 */
$similarCategoryId = (int) ($product['categorias_idcategorias'] ?? 0);
$similarBrand = trim((string) ($product['pro_marca'] ?? ''));
$similarPrice = $product['pro_precio'] !== null ? (float) $product['pro_precio'] : 0.0;
$similar = [];

if ($similarCategoryId > 0 || $similarBrand !== '') {
    $similarStmt = $pdo->prepare("
        SELECT
            grupo,
            MIN(idproductos) AS idproductos,
            MIN(pro_nombre) AS pro_nombre,
            MIN(pro_precio) AS pro_precio,
            SUBSTRING_INDEX(
                GROUP_CONCAT(
                    IF(
                        pro_imagen IS NULL
                        OR TRIM(pro_imagen) = ''
                        OR pro_imagen LIKE 'data:image%',
                        NULL,
                        pro_imagen
                    )
                    ORDER BY
                        CASE WHEN pro_imagen LIKE 'https://%' THEN 0 ELSE 1 END,
                        pro_precio IS NULL,
                        pro_precio ASC,
                        idproductos ASC
                    SEPARATOR '<|>'
                ),
                '<|>',
                1
            ) AS pro_imagen
        FROM (
            SELECT
                p.idproductos,
                COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo,
                p.pro_nombre,
                p.pro_precio,
                p.pro_imagen,
                p.categorias_idcategorias,
                p.pro_marca,
                p.pro_fecha_scraping
            FROM productos p
            WHERE p.pro_activo = 1
              AND COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) <> :grupo
              AND (
                (:cat > 0 AND p.categorias_idcategorias = :cat_match)
                OR (:marca <> '' AND p.pro_marca = :marca_match)
              )
        ) base
        GROUP BY grupo
        ORDER BY
          MIN(CASE WHEN categorias_idcategorias = :cat_rank THEN 0 ELSE 1 END),
          MIN(CASE WHEN :marca_rank <> '' AND pro_marca = :marca_rank_match THEN 0 ELSE 1 END),
          ABS(MIN(COALESCE(pro_precio, 0)) - :precio),
          MAX(pro_fecha_scraping) DESC
        LIMIT 5
    ");
    $similarStmt->execute([
        ':grupo' => $groupName,
        ':cat' => $similarCategoryId,
        ':cat_match' => $similarCategoryId,
        ':cat_rank' => $similarCategoryId,
        ':marca' => $similarBrand,
        ':marca_match' => $similarBrand,
        ':marca_rank' => $similarBrand,
        ':marca_rank_match' => $similarBrand,
        ':precio' => $similarPrice,
    ]);
    $similar = $similarStmt->fetchAll();
}

/**
 * Más productos de la misma tienda, agrupados por pro_grupo (carrusel)
 */
$storeProductsStmt = $pdo->prepare("
    SELECT
        grupo,
        CAST(SUBSTRING_INDEX(
            GROUP_CONCAT(
                idproductos
                ORDER BY
                    pro_precio IS NULL,
                    pro_precio ASC,
                    idproductos ASC
                SEPARATOR '<|>'
            ),
            '<|>',
            1
        ) AS UNSIGNED) AS idproductos,
        SUBSTRING_INDEX(
            GROUP_CONCAT(
                pro_nombre
                ORDER BY
                    pro_precio IS NULL,
                    pro_precio ASC,
                    idproductos ASC
                SEPARATOR '<|>'
            ),
            '<|>',
            1
        ) AS pro_nombre,
        MIN(pro_precio) AS pro_precio,
        SUBSTRING_INDEX(
            GROUP_CONCAT(
                IFNULL(pro_marca, '')
                ORDER BY
                    pro_precio IS NULL,
                    pro_precio ASC,
                    idproductos ASC
                SEPARATOR '<|>'
            ),
            '<|>',
            1
        ) AS pro_marca,
        SUBSTRING_INDEX(
            GROUP_CONCAT(
                IFNULL(cat_nombre, '')
                ORDER BY
                    pro_precio IS NULL,
                    pro_precio ASC,
                    idproductos ASC
                SEPARATOR '<|>'
            ),
            '<|>',
            1
        ) AS cat_nombre,
        CAST(SUBSTRING_INDEX(
            GROUP_CONCAT(
                pro_en_stock
                ORDER BY
                    pro_precio IS NULL,
                    pro_precio ASC,
                    idproductos ASC
                SEPARATOR '<|>'
            ),
            '<|>',
            1
        ) AS UNSIGNED) AS pro_en_stock,
        SUBSTRING_INDEX(
            GROUP_CONCAT(
                IF(
                    pro_imagen IS NULL
                    OR TRIM(pro_imagen) = ''
                    OR pro_imagen LIKE 'data:image%',
                    NULL,
                    pro_imagen
                )
                ORDER BY
                    CASE WHEN pro_imagen LIKE 'https://%' THEN 0 ELSE 1 END,
                    pro_precio IS NULL,
                    pro_precio ASC,
                    idproductos ASC
                SEPARATOR '<|>'
            ),
            '<|>',
            1
        ) AS pro_imagen
    FROM (
        SELECT
            p.idproductos,
            COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) AS grupo,
            p.pro_nombre,
            p.pro_precio,
            p.pro_imagen,
            p.pro_marca,
            p.pro_en_stock,
            p.pro_fecha_scraping,
            c.cat_nombre
        FROM productos p
        LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
        WHERE p.tiendas_idtiendas = :tienda
          AND p.pro_activo = 1
          AND COALESCE(NULLIF(TRIM(p.pro_grupo), ''), p.pro_nombre) <> :grupo
    ) base
    GROUP BY grupo
    ORDER BY MAX(pro_fecha_scraping) DESC
    LIMIT 12
");
$storeProductsStmt->execute([
    ':tienda' => $product['idtiendas'],
    ':grupo' => $groupName,
]);
$storeProducts = $storeProductsStmt->fetchAll();
$similarCaption = trim((string) ($product['cat_nombre'] ?? ''));
if ($similarCaption === '' && $similarBrand !== '') {
    $similarCaption = $similarBrand;
}
if ($similarCaption === '') {
    $similarCaption = 'Basado en lo que estás viendo';
}

render_head($groupName ?: $product['pro_nombre']);
render_navbar('producto');
?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="product-detail page-section" aria-labelledby="product-detail-title">
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
          <a href="index.php#productos" class="pd-crumb-link">Productos</a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current"><?= e($groupName ?: $product['pro_nombre']) ?></span>
        </li>
      </ol>
    </nav>

    <?php
    $toasts = [];
    if ($reportSuccess) {
        $toasts[] = ['type' => 'success', 'message' => 'El producto fue reportado correctamente.'];
    }
    if ($reportError !== '') {
        $toasts[] = ['type' => 'danger', 'message' => $reportError];
    }
    require __DIR__ . '/auth_toast.php';
    ?>

    <div class="pd-board">
      <div class="pd-media-panel">
        <div class="product-gallery-main" id="productGallery">
          <?php foreach ($galleryImages as $idx => $img): ?>
            <div class="product-gallery-slide <?= $idx === 0 ? 'active' : '' ?>">
              <img src="<?= e($img['src']) ?>" alt="<?= e($img['alt']) ?>">
            </div>
          <?php endforeach; ?>

          <?php if (count($galleryImages) > 1): ?>
            <button type="button" class="gallery-arrow prev" id="galleryPrev" aria-label="Imagen anterior">
              <?= lucide_icon('chevron-left', '', 20) ?>
            </button>
            <button type="button" class="gallery-arrow next" id="galleryNext" aria-label="Imagen siguiente">
              <?= lucide_icon('chevron-right', '', 20) ?>
            </button>
          <?php endif; ?>
        </div>

        <?php if (count($galleryImages) > 1): ?>
          <div class="gallery-thumbs-wrap" id="galleryThumbsWrap">
            <button type="button" class="gallery-thumbs-arrow prev" id="galleryThumbsPrev" aria-label="Deslizar miniaturas a la izquierda" hidden>
              <?= lucide_icon('chevron-left', '', 16) ?>
            </button>
            <div class="gallery-thumbs" id="galleryThumbs">
              <?php foreach ($galleryImages as $idx => $img): ?>
                <button type="button" class="gallery-thumb <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>" aria-label="Ver imagen <?= $idx + 1 ?>">
                  <img src="<?= e($img['src']) ?>" alt="<?= e($img['alt']) ?>">
                </button>
              <?php endforeach; ?>
            </div>
            <button type="button" class="gallery-thumbs-arrow next" id="galleryThumbsNext" aria-label="Deslizar miniaturas a la derecha" hidden>
              <?= lucide_icon('chevron-right', '', 16) ?>
            </button>
          </div>
          <div class="gallery-progress" aria-hidden="true">
            <div class="gallery-progress-bar" id="galleryProgressBar"></div>
          </div>
        <?php endif; ?>
      </div>

      <div class="pd-summary-panel">
        <span class="pd-mark" aria-hidden="true"><?= lucide_icon('scale', '', 88) ?></span>

        <div class="pd-summary-top">
          <div class="pd-tags">
            <span class="pd-tag"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
            <span class="pd-tag <?= (int) $product['pro_en_stock'] === 1 ? 'is-stock' : 'is-wait' ?>">
              <?= e(stock_label($product['pro_en_stock'])) ?>
            </span>
            <?php if (!empty($product['pro_marca'])): ?>
              <span class="pd-tag is-muted"><?= e($product['pro_marca']) ?></span>
            <?php endif; ?>
            <span class="pd-tag is-muted"><?= count($offers) ?> oferta<?= count($offers) === 1 ? '' : 's' ?></span>
          </div>

          <h1 id="product-detail-title" class="pd-title"><?= e($groupName ?: $product['pro_nombre']) ?></h1>

          <p class="pd-lead">
            <?= e($product['pro_descripcion'] ?: 'Compara precios y ofertas disponibles para este producto en distintas tiendas.') ?>
          </p>
        </div>

        <div class="pd-summary-foot">
          <div class="pd-price-row">
            <div class="pd-price-main">
              <p class="pd-price-label">Desde</p>
              <p class="pd-price"><?= gs_precio($minPrice) ?></p>
            </div>
            <div class="pd-meta-inline">
              <a href="tienda.php?id=<?= (int) $product['idtiendas'] ?>" class="pd-meta-link">
                <?= lucide_icon('store', '', 14) ?>
                <span><?= e($product['tie_nombre']) ?></span>
              </a>
              <span class="pd-meta-sep" aria-hidden="true">·</span>
              <span class="pd-meta-item"><?= e($product['cat_nombre'] ?? 'Sin categoría') ?></span>
              <span class="pd-meta-sep" aria-hidden="true">·</span>
              <span class="pd-meta-item pd-updated">
                <?= lucide_icon('clock', '', 14) ?>
                <span><?= e(date('d/m/Y H:i', strtotime((string) $product['pro_fecha_scraping']))) ?></span>
              </span>
            </div>
          </div>

          <div class="pd-actions">
            <a
              href="go.php?id=<?= $bestOfferProductId ?>&source=<?= rawurlencode($goSource) ?>&click_type=ir_mejor_oferta<?= $goTerm !== '' ? '&term=' . rawurlencode($goTerm) : '' ?><?= $bestOfferUrl !== '' && $bestOfferUrl !== '#' ? '&target_url=' . rawurlencode($bestOfferUrl) : '' ?>"
              class="btn btn-primary pd-cta"
              target="_blank"
              rel="noopener nofollow"
            >
              Ir a la mejor oferta <?= lucide_icon('arrow-up-right', '', 16) ?>
            </a>
            <a href="tienda.php?id=<?= (int) $product['idtiendas'] ?>" class="btn btn-outline-primary">
              Ver tienda
            </a>
            <button
              type="button"
              class="btn btn-outline-danger"
              data-bs-toggle="modal"
              data-bs-target="#reportProductModalMain"
              title="Reportar producto"
            >
              <?= lucide_icon('flag', '', 16) ?>
              <span>Reportar</span>
            </button>
            <div class="pd-share" aria-label="Compartir producto">
              <a href="<?= e($shareWhatsappUrl) ?>" target="_blank" rel="noopener" class="pd-share-link is-whatsapp" title="WhatsApp">
                <?= brand_icon('whatsapp', '', 15) ?>
              </a>
              <a href="<?= e($shareFacebookUrl) ?>" target="_blank" rel="noopener" class="pd-share-link is-facebook" title="Facebook">
                <?= brand_icon('facebook', '', 15) ?>
              </a>
              <a href="<?= e($shareTwitterUrl) ?>" target="_blank" rel="noopener" class="pd-share-link is-x" title="Twitter/X">
                <?= brand_icon('x', '', 15) ?>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php
      $offerPageSize = 5;
      $offerTotal = count($offers);
      $offerPages = (int) max(1, (int) ceil($offerTotal / $offerPageSize));
    ?>
    <section
      class="pd-offers<?= $offerTotal > $offerPageSize ? ' has-pager' : '' ?>"
      aria-labelledby="pd-offers-title"
      data-offers-pager
      data-page-size="<?= (int) $offerPageSize ?>"
    >
      <div class="pd-section-head">
        <div>
          <p class="pd-kicker">Comparación</p>
          <h2 id="pd-offers-title" class="pd-lane-title">Ofertas disponibles</h2>
          <p class="pd-lane-caption">Mismo producto en distintas tiendas. Elige la mejor opción antes de salir.</p>
        </div>
        <div class="pd-section-tools">
          <span class="pd-section-count"><?= (int) $offerTotal ?> oferta<?= $offerTotal === 1 ? '' : 's' ?></span>
        </div>
      </div>

      <?php if ($offers): ?>
        <div class="pd-offer-list">
          <?php foreach ($offers as $offerIndex => $offer): ?>
            <?php
              $offerIsFavorite = ($favoritesEnabled && $userLogged) ? is_favorite_product((int) $offer['idproductos']) : false;
              $offerInStock = (int) ($offer['pro_en_stock'] ?? 0) === 1;
            ?>
            <article
              class="pd-offer<?= $offerIndex === 0 ? ' is-best' : '' ?><?= $offerIndex < $offerPageSize ? ' is-visible' : '' ?>"
              data-offer-row
            >
              <div class="pd-offer-media">
                <img
                  src="<?= e(image_url($offer['pro_imagen'], $offer['pro_nombre'])) ?>"
                  alt="<?= e($offer['pro_nombre']) ?>"
                >
                <span class="pd-offer-rank"><?= str_pad((string) ($offerIndex + 1), 2, '0', STR_PAD_LEFT) ?></span>
              </div>

              <div class="pd-offer-store">
                <span class="pd-offer-store-name"><?= e($offer['tie_nombre']) ?></span>
                <span class="pd-offer-store-date">
                  <?= !empty($offer['pro_fecha_scraping']) ? e(date('d/m/Y H:i', strtotime((string) $offer['pro_fecha_scraping']))) : 'Sin fecha' ?>
                </span>
              </div>

              <div class="pd-offer-copy">
                <span class="pd-offer-name"><?= e($offer['pro_nombre'] ?: 'Sin nombre') ?></span>
                <?php if (!empty($offer['pro_marca'])): ?>
                  <span class="pd-offer-brand"><?= e($offer['pro_marca']) ?></span>
                <?php endif; ?>
              </div>

              <div class="pd-offer-priceblock">
                <div class="pd-offer-price-top">
                  <span class="pd-offer-price-label">Precio</span>
                  <span class="pd-offer-stock <?= $offerInStock ? 'is-stock' : 'is-wait' ?>">
                    <?= e($offerInStock ? 'Stock' : 'Consultar') ?>
                  </span>
                </div>
                <strong class="pd-offer-price"><?= gs_precio($offer['pro_precio']) ?></strong>
              </div>

              <div class="pd-offer-actions">
                <?php if ($favoritesEnabled): ?>
                  <a
                    href="<?= $userLogged ? e(favorite_toggle_url((int) $offer['idproductos'], $_SERVER['REQUEST_URI'])) : 'login.php' ?>"
                    class="pd-offer-icon is-fav<?= $userLogged && $offerIsFavorite ? ' is-on' : '' ?>"
                    title="<?= $userLogged ? ($offerIsFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos') : 'Inicia sesión para guardar favoritos' ?>"
                    aria-label="<?= $userLogged ? ($offerIsFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos') : 'Inicia sesión para guardar favoritos' ?>"
                  >
                    <?= lucide_icon('heart', $userLogged && $offerIsFavorite ? 'lucide-fill' : '', 15) ?>
                  </a>
                <?php endif; ?>

                <a
                  href="go.php?id=<?= (int) $offer['idproductos'] ?>&source=producto&click_type=ir_oferta<?= $searchTerm !== '' ? '&term=' . rawurlencode($searchTerm) : '' ?><?= !empty($offer['pro_url']) ? '&target_url=' . rawurlencode((string) $offer['pro_url']) : '' ?>"
                  target="_blank"
                  rel="noopener nofollow"
                  class="pd-offer-cta"
                >
                  Ver <?= lucide_icon('arrow-up-right', '', 14) ?>
                </a>

                <button
                  type="button"
                  class="pd-offer-icon is-report"
                  data-bs-toggle="modal"
                  data-bs-target="#reportProductModal<?= (int) $offer['idproductos'] ?>"
                  title="Reportar producto"
                  aria-label="Reportar producto"
                >
                  <?= lucide_icon('flag', '', 15) ?>
                </button>
              </div>
            </article>

            <?php ob_start(); ?>
            <div class="modal fade" id="reportProductModal<?= (int) $offer['idproductos'] ?>" tabindex="-1" aria-labelledby="reportProductModalLabel<?= (int) $offer['idproductos'] ?>" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered report-product-dialog">
                <div class="modal-content suggestion-modal report-product-modal">
                  <form method="post" class="report-product-form">
                    <div class="modal-header">
                      <div class="report-product-heading">
                        <span class="report-product-icon" aria-hidden="true"><?= lucide_icon('flag', '', 18) ?></span>
                        <div class="report-product-heading-text">
                          <h5 class="modal-title" id="reportProductModalLabel<?= (int) $offer['idproductos'] ?>">Reportar producto</h5>
                          <p class="report-product-lead">Ayúdanos a mantener precios e información al día.</p>
                        </div>
                      </div>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <div class="modal-body">
                      <input type="hidden" name="action" value="report_product">
                      <input type="hidden" name="product_id" value="<?= (int) $offer['idproductos'] ?>">

                      <div class="report-product-context" title="<?= e($offer['tie_nombre'] . ' · ' . $offer['pro_nombre']) ?>">
                        <span class="report-product-store"><?= e($offer['tie_nombre']) ?></span>
                        <span class="report-product-name"><?= e($offer['pro_nombre']) ?></span>
                      </div>

                      <div class="report-product-field">
                        <label class="form-label" for="repMotivo<?= (int) $offer['idproductos'] ?>">
                          Motivo <span class="report-product-req" aria-hidden="true">*</span>
                        </label>
                        <select name="rep_motivo" id="repMotivo<?= (int) $offer['idproductos'] ?>" class="form-select" required>
                          <option value="">Seleccionar un motivo</option>
                          <option value="precio_incorrecto">Precio incorrecto</option>
                          <option value="desactualizado">Falta actualizar</option>
                          <option value="sin_stock">Sin stock</option>
                          <option value="producto_equivocado">Producto equivocado</option>
                          <option value="informacion_falsa">Información falsa</option>
                          <option value="otro">Otro</option>
                        </select>
                      </div>

                      <div class="report-product-row">
                        <div class="report-product-field">
                          <label class="form-label" for="repNombre<?= (int) $offer['idproductos'] ?>">
                            Tu nombre
                          </label>
                          <input type="text" name="rep_nombre" id="repNombre<?= (int) $offer['idproductos'] ?>" class="form-control" maxlength="120" placeholder="Nombre completo" autocomplete="name">
                        </div>

                        <div class="report-product-field">
                          <label class="form-label" for="repEmail<?= (int) $offer['idproductos'] ?>">
                            Email
                          </label>
                          <input type="email" name="rep_email" id="repEmail<?= (int) $offer['idproductos'] ?>" class="form-control" maxlength="150" placeholder="correo@ejemplo.com" autocomplete="email">
                        </div>
                      </div>

                      <div class="report-product-field mb-0">
                        <label class="form-label" for="repDetalle<?= (int) $offer['idproductos'] ?>">
                          Detalle
                        </label>
                        <textarea
                          name="rep_detalle"
                          id="repDetalle<?= (int) $offer['idproductos'] ?>"
                          class="form-control"
                          rows="3"
                          maxlength="1000"
                          placeholder="Descripción del problema (precio, stock, foto…)"></textarea>
                      </div>
                      <?= consent_field('repConsent' . (int) $offer['idproductos']) ?>
                      <?= turnstile_widget() ?>
                    </div>

                    <div class="modal-footer">
                      <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancelar</button>
                      <button type="submit" class="btn btn-danger px-4">
                        <?= lucide_icon('send', 'report-product-send-icon', 15) ?>Enviar reporte
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php $productReportModals[] = ob_get_clean(); ?>
          <?php endforeach; ?>
        </div>

        <?php if ($offerTotal > $offerPageSize): ?>
          <?php
            $offerVisiblePages = min(5, $offerPages);
          ?>
          <nav class="products-pager pd-offers-pager" aria-label="Paginación de ofertas">
            <p class="products-pager-status" data-offers-status>
              Página <span data-offers-current>1</span> de <?= number_format($offerPages, 0, ',', '.') ?>
            </p>
            <div class="products-pager-controls">
              <button type="button" class="products-pager-btn is-disabled" data-offers-first aria-label="Primera página" title="Ir al inicio">
                <?= lucide_icon('chevrons-left', '', 16) ?>
              </button>
              <button type="button" class="products-pager-btn is-disabled" data-offers-prev aria-label="Página anterior" title="Página anterior">
                <?= lucide_icon('chevron-left', '', 16) ?>
              </button>

              <div class="products-pager-pages" data-offers-pages>
                <?php for ($i = 1; $i <= $offerVisiblePages; $i++): ?>
                  <button
                    type="button"
                    class="products-pager-num<?= $i === 1 ? ' is-active' : '' ?>"
                    data-offers-page="<?= (int) $i ?>"
                    <?= $i === 1 ? 'aria-current="page"' : '' ?>
                  >
                    <?= (int) $i ?>
                  </button>
                <?php endfor; ?>
              </div>

              <button type="button" class="products-pager-btn<?= $offerPages <= 1 ? ' is-disabled' : '' ?>" data-offers-next aria-label="Página siguiente" title="Página siguiente">
                <?= lucide_icon('chevron-right', '', 16) ?>
              </button>
              <button type="button" class="products-pager-btn<?= $offerPages <= 1 ? ' is-disabled' : '' ?>" data-offers-last aria-label="Última página" title="Ir al final">
                <?= lucide_icon('chevrons-right', '', 16) ?>
              </button>
            </div>
          </nav>
        <?php endif; ?>
      <?php else: ?>
        <div class="pd-empty">No hay ofertas disponibles para este grupo todavía.</div>
      <?php endif; ?>
    </section>

    <div class="pd-secondary">
      <section class="pd-lane" data-tone="history" aria-labelledby="pd-history-title">
        <span class="pd-lane-mark" aria-hidden="true"><?= lucide_icon('trending-up', '', 88) ?></span>
        <div class="pd-lane-head">
          <span class="pd-lane-icon"><?= lucide_icon('trending-up', '', 20) ?></span>
          <div>
            <h2 id="pd-history-title" class="pd-lane-title">Histórico de precios</h2>
            <p class="pd-lane-caption">
              Mejor precio por tienda
              <?php if ($historyMonthCount > 0): ?>
                · <?= (int) $historyMonthCount ?> mes<?= $historyMonthCount === 1 ? '' : 'es' ?>
              <?php endif; ?>
              <?php if ($historyStoreCount > 0): ?>
                · <?= (int) $historyStoreCount ?> tienda<?= $historyStoreCount === 1 ? '' : 's' ?>
              <?php endif; ?>
            </p>
          </div>
        </div>

        <?php if (!empty($historyChartDatasets)): ?>
          <dl class="pd-history-stats">
            <div>
              <dt>Mínimo</dt>
              <dd><?= $historyStats['min'] !== null ? gs($historyStats['min']) : 'Sin datos' ?></dd>
            </div>
            <div>
              <dt>Máximo</dt>
              <dd><?= $historyStats['max'] !== null ? gs($historyStats['max']) : 'Sin datos' ?></dd>
            </div>
            <div>
              <dt>Último</dt>
              <dd><?= $historyStats['latest'] !== null ? gs($historyStats['latest']) : 'Sin datos' ?></dd>
            </div>
          </dl>

          <div class="pd-history-tabs" data-history-tabs>
            <div class="pd-history-tablist" role="tablist" aria-label="Vista del historial">
              <span class="pd-history-tab-thumb" data-history-tab-thumb aria-hidden="true"></span>
              <button
                type="button"
                class="pd-history-tab is-active"
                id="pd-history-tab-chart"
                role="tab"
                aria-selected="true"
                aria-controls="pd-history-panel-chart"
                data-history-tab="chart"
              >
                <?= lucide_icon('trending-up', '', 16) ?>
                <span>Gráfico</span>
              </button>
              <button
                type="button"
                class="pd-history-tab"
                id="pd-history-tab-table"
                role="tab"
                aria-selected="false"
                aria-controls="pd-history-panel-table"
                data-history-tab="table"
                tabindex="-1"
              >
                <?= lucide_icon('list', '', 16) ?>
                <span>Tabla</span>
              </button>
            </div>

            <div class="pd-history-stage">
              <div
                class="pd-history-panel is-active"
                id="pd-history-panel-chart"
                role="tabpanel"
                aria-labelledby="pd-history-tab-chart"
                data-history-panel="chart"
              >
                <div class="history-chart-wrap">
                  <canvas id="priceHistoryChart" class="history-chart-canvas"></canvas>
                </div>
              </div>

              <div
                class="pd-history-panel"
                id="pd-history-panel-table"
                role="tabpanel"
                aria-labelledby="pd-history-tab-table"
                data-history-panel="table"
                aria-hidden="true"
              >
                <?php
                  $historyPageSize = 5;
                  $historyTotalRows = count($history);
                  $historyPages = max(1, (int) ceil($historyTotalRows / $historyPageSize));
                ?>
                <div class="pd-history-table-panel" data-history-table-panel data-history-pages="<?= (int) $historyPages ?>">
                  <div class="pd-history-table-wrap">
                    <table class="pd-history-table">
                      <thead>
                        <tr>
                          <th scope="col">Mes</th>
                          <th scope="col">Tienda</th>
                          <th scope="col">Producto</th>
                          <th scope="col">Precio</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($history as $historyIndex => $entry): ?>
                          <?php
                            $historyProductName = trim((string) ($entry['pro_nombre'] ?? ''));
                            $historyRowPage = (int) floor($historyIndex / $historyPageSize) + 1;
                          ?>
                          <tr
                            data-history-row-page="<?= (int) $historyRowPage ?>"
                            <?= $historyRowPage > 1 ? 'hidden' : '' ?>
                          >
                            <td><?= e($entry['month_label'] ?? date('m/Y', strtotime((string) $entry['his_fecha']))) ?></td>
                            <td><?= e($entry['tie_nombre'] ?? 'Sin tienda') ?></td>
                            <td title="<?= e($historyProductName !== '' ? $historyProductName : 'Sin nombre') ?>">
                              <?= e($historyProductName !== '' ? $historyProductName : 'Sin nombre') ?>
                            </td>
                            <td><?= gs($entry['his_precio']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

                  <?php if ($historyPages > 1): ?>
                    <nav class="pd-history-pager" data-history-pager aria-label="Paginación del historial">
                      <p class="pd-history-pager-status">
                        Página <span data-history-page-current>1</span> de <?= (int) $historyPages ?>
                      </p>
                      <div class="pd-history-pager-controls">
                        <button type="button" class="pd-history-pager-btn is-disabled" data-history-page-prev aria-label="Página anterior" title="Página anterior">
                          <?= lucide_icon('chevron-left', '', 16) ?>
                        </button>
                        <div class="pd-history-pager-pages" data-history-page-nums>
                          <?php for ($historyPageNum = 1; $historyPageNum <= $historyPages; $historyPageNum++): ?>
                            <button
                              type="button"
                              class="pd-history-pager-num<?= $historyPageNum === 1 ? ' is-active' : '' ?>"
                              data-history-page="<?= (int) $historyPageNum ?>"
                              <?= $historyPageNum === 1 ? 'aria-current="page"' : '' ?>
                            >
                              <?= (int) $historyPageNum ?>
                            </button>
                          <?php endfor; ?>
                        </div>
                        <button type="button" class="pd-history-pager-btn" data-history-page-next aria-label="Página siguiente" title="Página siguiente">
                          <?= lucide_icon('chevron-right', '', 16) ?>
                        </button>
                      </div>
                    </nav>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="pd-empty">No hay historial de precio para este producto todavía.</div>
        <?php endif; ?>
      </section>

      <section class="pd-lane" data-tone="related" aria-labelledby="pd-related-title">
        <span class="pd-lane-mark" aria-hidden="true"><?= lucide_icon('sparkles', '', 88) ?></span>
        <div class="pd-lane-head">
          <span class="pd-lane-icon"><?= lucide_icon('sparkles', '', 20) ?></span>
          <div>
            <h2 id="pd-related-title" class="pd-lane-title">Productos similares</h2>
            <p class="pd-lane-caption"><?= e($similarCaption) ?></p>
          </div>
        </div>

        <?php if ($similar): ?>
          <div class="pd-related-list">
            <?php foreach ($similar as $relIndex => $item): ?>
              <?php
                $similarGroup = trim((string) ($item['grupo'] ?? ''));
                $similarName = $similarGroup !== '' ? $similarGroup : (string) $item['pro_nombre'];
                $similarUrl = $similarGroup !== ''
                  ? 'producto.php?grupo=' . rawurlencode($similarGroup)
                  : 'producto.php?id=' . (int) $item['idproductos'];
              ?>
              <a href="<?= e($similarUrl) ?>" class="pd-related-item">
                <span class="pd-related-media">
                  <img src="<?= e(image_url($item['pro_imagen'], $similarName)) ?>" alt="<?= e($similarName) ?>">
                </span>
                <span class="pd-related-copy">
                  <span class="pd-related-rank"><?= str_pad((string) ($relIndex + 1), 2, '0', STR_PAD_LEFT) ?></span>
                  <span class="pd-related-name"><?= e($similarName) ?></span>
                  <span class="pd-related-price"><?= gs_precio($item['pro_precio']) ?></span>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="pd-empty">No encontramos productos similares por ahora.</div>
        <?php endif; ?>
      </section>
    </div>

    <section class="pd-lane pd-store-lane" data-tone="store" aria-labelledby="pd-store-title">
      <span class="pd-lane-mark" aria-hidden="true"><?= lucide_icon('store', '', 88) ?></span>
      <div class="pd-lane-head pd-store-head">
        <div class="pd-store-head-copy">
          <span class="pd-lane-icon"><?= lucide_icon('store', '', 20) ?></span>
          <div>
            <h2 id="pd-store-title" class="pd-lane-title">Más de esta tienda</h2>
            <p class="pd-lane-caption">
              Otros productos de <strong><?= e($product['tie_nombre']) ?></strong>
              para seguir explorando su catálogo y comparar precios sin salir de esta ficha.
            </p>
          </div>
        </div>
        <?php if (count($storeProducts) > 1): ?>
          <div class="pd-store-nav" role="group" aria-label="Controles del carrusel">
            <button type="button" class="pd-store-nav-btn" data-store-prev aria-label="Anterior">
              <?= lucide_icon('chevron-left', '', 18) ?>
            </button>
            <button type="button" class="pd-store-nav-btn" data-store-pause aria-label="Pausar desplazamiento automático" aria-pressed="false">
              <?= lucide_icon('pause', '', 16) ?>
            </button>
            <button type="button" class="pd-store-nav-btn" data-store-next aria-label="Siguiente">
              <?= lucide_icon('chevron-right', '', 18) ?>
            </button>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($storeProducts): ?>
        <div class="pd-store-carousel" data-store-carousel>
          <div class="pd-store-viewport" data-store-viewport tabindex="0">
            <div
              class="pd-store-track"
              data-store-track
              role="list"
              aria-label="Productos de <?= e($product['tie_nombre']) ?>"
            >
              <?php foreach ($storeProducts as $storeIndex => $item): ?>
                <?php
                  $storeGroup = trim((string) ($item['grupo'] ?? ''));
                  $storeName = trim((string) ($item['pro_nombre'] ?? ''));
                  if ($storeName === '') {
                    $storeName = $storeGroup !== '' ? $storeGroup : 'Producto';
                  }
                  $storeBrand = trim((string) ($item['pro_marca'] ?? ''));
                  $storeInStock = (int) ($item['pro_en_stock'] ?? 0) === 1;
                  $storeUrl = $storeGroup !== ''
                    ? 'producto.php?grupo=' . rawurlencode($storeGroup)
                    : 'producto.php?id=' . (int) $item['idproductos'];
                ?>
                <a
                  href="<?= e($storeUrl) ?>"
                  class="pd-store-card"
                  role="listitem"
                  data-tone="<?= $storeInStock ? 'stock' : 'wait' ?>"
                  aria-label="<?= e($storeName) ?> — <?= e(gs_precio($item['pro_precio'])) ?>"
                >
                  <span class="pd-store-media">
                    <span class="pd-store-rank" aria-hidden="true"><?= str_pad((string) ($storeIndex + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="pd-store-stock <?= $storeInStock ? 'is-stock' : 'is-wait' ?>" title="<?= e($storeInStock ? 'En stock' : 'Consultar stock') ?>">
                      <?= e($storeInStock ? 'Stock' : 'Consultar') ?>
                    </span>
                    <img src="<?= e(image_url($item['pro_imagen'], $storeName)) ?>" alt="" loading="lazy" decoding="async">
                  </span>
                  <span class="pd-store-copy">
                    <?php if ($storeBrand !== ''): ?>
                      <span class="pd-store-tags" title="<?= e($storeBrand) ?>">
                        <span class="pd-store-brand"><?= e($storeBrand) ?></span>
                      </span>
                    <?php endif; ?>
                    <span class="pd-store-name" title="<?= e($storeName) ?>"><?= e($storeName) ?></span>
                    <span class="pd-store-foot">
                      <span class="pd-store-price"><?= gs_precio($item['pro_precio']) ?></span>
                      <span class="pd-store-cta">
                        Ver oferta
                        <?= lucide_icon('arrow-right', '', 14) ?>
                      </span>
                    </span>
                  </span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="pd-empty">No hay más productos de esta tienda por ahora.</div>
      <?php endif; ?>
    </section>
  </div>
</section>

<?php ob_start(); ?>
<div class="modal fade" id="reportProductModalMain" tabindex="-1" aria-labelledby="reportProductModalLabelMain" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered report-product-dialog">
    <div class="modal-content suggestion-modal report-product-modal">
      <form method="post" class="report-product-form">
        <div class="modal-header">
          <div class="report-product-heading">
            <span class="report-product-icon" aria-hidden="true"><?= lucide_icon('flag', '', 18) ?></span>
            <div class="report-product-heading-text">
              <h5 class="modal-title" id="reportProductModalLabelMain">Reportar producto</h5>
              <p class="report-product-lead">Ayúdanos a mantener precios e información al día.</p>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="action" value="report_product">
          <input type="hidden" name="product_id" value="<?= (int) $product['idproductos'] ?>">

          <div class="report-product-context" title="<?= e($product['tie_nombre'] . ' · ' . ($groupName ?: $product['pro_nombre'])) ?>">
            <span class="report-product-store"><?= e($product['tie_nombre']) ?></span>
            <span class="report-product-name"><?= e($groupName ?: $product['pro_nombre']) ?></span>
          </div>

          <div class="report-product-field">
            <label class="form-label" for="repMotivoMain">
              Motivo <span class="report-product-req" aria-hidden="true">*</span>
            </label>
            <select name="rep_motivo" id="repMotivoMain" class="form-select" required>
              <option value="">Seleccionar un motivo</option>
              <option value="precio_incorrecto">Precio incorrecto</option>
              <option value="desactualizado">Falta actualizar</option>
              <option value="sin_stock">Sin stock</option>
              <option value="producto_equivocado">Producto equivocado</option>
              <option value="informacion_falsa">Información falsa</option>
              <option value="otro">Otro</option>
            </select>
          </div>

          <div class="report-product-row">
            <div class="report-product-field">
              <label class="form-label" for="repNombreMain">
                Tu nombre
              </label>
              <input type="text" name="rep_nombre" id="repNombreMain" class="form-control" maxlength="120" placeholder="Nombre completo" autocomplete="name">
            </div>

            <div class="report-product-field">
              <label class="form-label" for="repEmailMain">
                Email
              </label>
              <input type="email" name="rep_email" id="repEmailMain" class="form-control" maxlength="150" placeholder="correo@ejemplo.com" autocomplete="email">
            </div>
          </div>

          <div class="report-product-field mb-0">
            <label class="form-label" for="repDetalleMain">
              Detalle
            </label>
            <textarea
              name="rep_detalle"
              id="repDetalleMain"
              class="form-control"
              rows="3"
              maxlength="1000"
              placeholder="Descripción del problema (precio, stock, foto…)"></textarea>
          </div>
          <?= consent_field('repConsentMain') ?>
          <?= turnstile_widget() ?>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger px-4">
            <?= lucide_icon('send', 'report-product-send-icon', 15) ?>Enviar reporte
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php $productReportModals[] = ob_get_clean(); ?>

<?php if ($productReportModals): ?>
  <?= implode("\n", $productReportModals) ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.priceHistoryLabels = <?= json_encode($historyChartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.priceHistoryDatasets = <?= json_encode($historyChartDatasets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const offersPager = document.querySelector('[data-offers-pager]');
  if (offersPager) {
    const offerRows = Array.from(offersPager.querySelectorAll('[data-offer-row]'));
    const offerPageSize = Math.max(1, parseInt(offersPager.getAttribute('data-page-size') || '5', 10));
    const offerTotalPages = Math.max(1, Math.ceil(offerRows.length / offerPageSize));
    const offerFirstBtn = offersPager.querySelector('[data-offers-first]');
    const offerPrevBtn = offersPager.querySelector('[data-offers-prev]');
    const offerNextBtn = offersPager.querySelector('[data-offers-next]');
    const offerLastBtn = offersPager.querySelector('[data-offers-last]');
    const offerStatus = offersPager.querySelector('[data-offers-status]');
    const offerCurrent = offersPager.querySelector('[data-offers-current]');
    const offerPagesWrap = offersPager.querySelector('[data-offers-pages]');
    const offerVisibleWindow = 5;
    let offerPage = 0;

    const setBtnDisabled = (btn, disabled) => {
      if (!btn) return;
      btn.classList.toggle('is-disabled', disabled);
      btn.disabled = disabled;
    };

    const renderOfferPageButtons = () => {
      if (!offerPagesWrap) return;

      const half = Math.floor(offerVisibleWindow / 2);
      let startPage = Math.max(0, offerPage - half);
      let endPage = Math.min(offerTotalPages - 1, startPage + offerVisibleWindow - 1);
      startPage = Math.max(0, endPage - offerVisibleWindow + 1);

      offerPagesWrap.innerHTML = '';
      for (let i = startPage; i <= endPage; i += 1) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'products-pager-num' + (i === offerPage ? ' is-active' : '');
        btn.setAttribute('data-offers-page', String(i + 1));
        btn.textContent = String(i + 1);
        if (i === offerPage) {
          btn.setAttribute('aria-current', 'page');
        }
        offerPagesWrap.appendChild(btn);
      }
    };

    const renderOffersPage = () => {
      const start = offerPage * offerPageSize;
      offerRows.forEach((row, index) => {
        row.classList.toggle('is-visible', index >= start && index < start + offerPageSize);
      });

      if (offerCurrent) {
        offerCurrent.textContent = String(offerPage + 1);
      }
      if (offerStatus) {
        offerStatus.setAttribute('aria-label', 'Página ' + (offerPage + 1) + ' de ' + offerTotalPages);
      }

      setBtnDisabled(offerFirstBtn, offerPage <= 0);
      setBtnDisabled(offerPrevBtn, offerPage <= 0);
      setBtnDisabled(offerNextBtn, offerPage >= offerTotalPages - 1);
      setBtnDisabled(offerLastBtn, offerPage >= offerTotalPages - 1);
      renderOfferPageButtons();
    };

    const goToOffersPage = (nextPage) => {
      offerPage = Math.max(0, Math.min(offerTotalPages - 1, nextPage));
      renderOffersPage();
    };

    if (offerFirstBtn) {
      offerFirstBtn.addEventListener('click', () => goToOffersPage(0));
    }
    if (offerPrevBtn) {
      offerPrevBtn.addEventListener('click', () => goToOffersPage(offerPage - 1));
    }
    if (offerNextBtn) {
      offerNextBtn.addEventListener('click', () => goToOffersPage(offerPage + 1));
    }
    if (offerLastBtn) {
      offerLastBtn.addEventListener('click', () => goToOffersPage(offerTotalPages - 1));
    }

    if (offerPagesWrap) {
      offerPagesWrap.addEventListener('click', (event) => {
        const target = event.target.closest('[data-offers-page]');
        if (!target || !offerPagesWrap.contains(target)) return;
        const pageNum = parseInt(target.getAttribute('data-offers-page') || '1', 10);
        if (!Number.isNaN(pageNum)) {
          goToOffersPage(pageNum - 1);
        }
      });
    }

    renderOffersPage();
  }

  const slides = Array.from(document.querySelectorAll('.product-gallery-slide'));
  const thumbs = Array.from(document.querySelectorAll('.gallery-thumb'));
  const prevBtn = document.getElementById('galleryPrev');
  const nextBtn = document.getElementById('galleryNext');
  const gallery = document.getElementById('productGallery');
  const progressBar = document.getElementById('galleryProgressBar');
  const thumbsTrack = document.getElementById('galleryThumbs');
  const thumbsWrap = document.getElementById('galleryThumbsWrap');
  const thumbsPrevBtn = document.getElementById('galleryThumbsPrev');
  const thumbsNextBtn = document.getElementById('galleryThumbsNext');
  const autoplayDelay = 4000;
  let current = 0;
  let autoplayTimer = null;
  let progressTimer = null;
  let progress = 0;

  function updateThumbsNav() {
    if (!thumbsTrack || !thumbsWrap || !thumbsPrevBtn || !thumbsNextBtn) return;

    const maxScroll = thumbsTrack.scrollWidth - thumbsTrack.clientWidth;
    const hasOverflow = maxScroll > 2;
    thumbsWrap.classList.toggle('is-overflow', hasOverflow);

    thumbsPrevBtn.hidden = !hasOverflow;
    thumbsNextBtn.hidden = !hasOverflow;

    if (!hasOverflow) return;

    const atStart = thumbsTrack.scrollLeft <= 2;
    const atEnd = thumbsTrack.scrollLeft >= maxScroll - 2;
    thumbsPrevBtn.disabled = atStart;
    thumbsNextBtn.disabled = atEnd;
  }

  function scrollThumbsBy(direction) {
    if (!thumbsTrack) return;
    const thumb = thumbs[0];
    const styles = window.getComputedStyle(thumbsTrack);
    const gap = parseFloat(styles.columnGap || styles.gap) || 0;
    const thumbWidth = thumb ? thumb.getBoundingClientRect().width : 50;
    const step = (thumbWidth + gap) * 6;
    thumbsTrack.scrollBy({ left: direction * step, behavior: 'smooth' });
  }

  function showSlide(index) {
    if (!slides.length) return;
    current = (index + slides.length) % slides.length;

    slides.forEach((slide, i) => {
      slide.classList.toggle('active', i === current);
    });

    thumbs.forEach((thumb, i) => {
      thumb.classList.toggle('active', i === current);
    });

    if (progressBar) {
      progressBar.style.width = '0%';
    }
  }

  function stopProgress() {
    if (progressTimer) {
      clearInterval(progressTimer);
      progressTimer = null;
    }
  }

  function startProgress() {
    stopProgress();
    progress = 0;
    if (!progressBar) return;

    progressBar.style.width = '0%';
    progressTimer = setInterval(function () {
      progress += 100 / (autoplayDelay / 100);
      progressBar.style.width = Math.min(progress, 100) + '%';
    }, 100);
  }

  function stopAutoplay() {
    if (autoplayTimer) {
      clearInterval(autoplayTimer);
      autoplayTimer = null;
    }
    stopProgress();
  }

  function startAutoplay() {
    if (slides.length <= 1) return;
    stopAutoplay();
    startProgress();
    autoplayTimer = setInterval(function () {
      showSlide(current + 1);
      startProgress();
    }, autoplayDelay);
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', function () {
      showSlide(current - 1);
      startAutoplay();
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function () {
      showSlide(current + 1);
      startAutoplay();
    });
  }

  if (thumbsPrevBtn) {
    thumbsPrevBtn.addEventListener('click', function () {
      scrollThumbsBy(-1);
    });
  }

  if (thumbsNextBtn) {
    thumbsNextBtn.addEventListener('click', function () {
      scrollThumbsBy(1);
    });
  }

  if (thumbsTrack) {
    thumbsTrack.addEventListener('scroll', updateThumbsNav, { passive: true });
    window.addEventListener('resize', updateThumbsNav);
  }

  thumbs.forEach(function (thumb, idx) {
    thumb.addEventListener('click', function () {
      showSlide(idx);
      startAutoplay();
    });
  });

  if (gallery && slides.length > 1) {
    gallery.addEventListener('mouseenter', stopAutoplay);
    gallery.addEventListener('mouseleave', startAutoplay);
  }

  showSlide(0);
  updateThumbsNav();
  window.addEventListener('load', updateThumbsNav);
  startAutoplay();

  const historyCanvas = document.getElementById('priceHistoryChart');
  let priceHistoryChart = null;
  const historyChartFont = 'Poppins, system-ui, -apple-system, Segoe UI, Roboto, sans-serif';

  function isLightTheme() {
    return document.body.classList.contains('theme-light') ||
           document.documentElement.getAttribute('data-bs-theme') === 'light';
  }

  function historyThemeColors() {
    if (isLightTheme()) {
      return {
        text: 'rgba(15, 23, 42, 0.72)',
        legend: 'rgba(15, 23, 42, 0.88)',
        grid: 'rgba(15, 23, 42, 0.08)',
        tooltipBg: 'rgba(255, 255, 255, 0.98)',
        tooltipText: '#0f172a',
        tooltipBorder: 'rgba(15, 23, 42, 0.12)'
      };
    }

    return {
      text: 'rgba(226, 232, 240, 0.78)',
      legend: 'rgba(241, 245, 249, 0.90)',
      grid: 'rgba(255, 255, 255, 0.08)',
      tooltipBg: 'rgba(15, 23, 42, 0.97)',
      tooltipText: '#f8fafc',
      tooltipBorder: 'rgba(255, 255, 255, 0.12)'
    };
  }

  var historyPalette = [
    '#497cbf',
    '#c48b8e',
    '#c9a06a',
    '#5b9a8b',
    '#7d6fb3',
    '#c17a5a'
  ];

  function styleHistoryDataset(dataset, index) {
    var color = historyPalette[index % historyPalette.length];
    dataset.type = 'line';
    dataset.tension = 0.35;
    dataset.borderWidth = 2.5;
    dataset.borderColor = color;
    dataset.backgroundColor = color;
    dataset.pointRadius = 4;
    dataset.pointHoverRadius = 6;
    dataset.pointHitRadius = 10;
    dataset.pointStyle = 'circle';
    dataset.pointBorderWidth = 0;
    dataset.pointBackgroundColor = color;
    dataset.pointBorderColor = color;
    dataset.pointHoverBackgroundColor = color;
    dataset.pointHoverBorderColor = color;
    dataset.pointHoverBorderWidth = 0;
    dataset.spanGaps = true;
  }

  function applyHistoryChartTheme(chart) {
    if (!chart) return;

    const colors = historyThemeColors();

    chart.options.scales.x.ticks.color = colors.text;
    chart.options.scales.y.ticks.color = colors.text;
    chart.options.scales.y.grid.color = colors.grid;
    chart.options.plugins.legend.labels.color = colors.legend;

    chart.options.plugins.tooltip.backgroundColor = colors.tooltipBg;
    chart.options.plugins.tooltip.titleColor = colors.tooltipText;
    chart.options.plugins.tooltip.bodyColor = colors.tooltipText;
    chart.options.plugins.tooltip.borderColor = colors.tooltipBorder;
    chart.options.plugins.tooltip.borderWidth = 1;

    chart.update('none');
  }

  if (historyCanvas && window.priceHistoryDatasets && window.priceHistoryDatasets.length && window.Chart) {
    const colors = historyThemeColors();

    if (Chart.defaults && Chart.defaults.font) {
      Chart.defaults.font.family = historyChartFont;
    }

    window.priceHistoryDatasets.forEach(styleHistoryDataset);

    priceHistoryChart = new Chart(historyCanvas, {
      type: 'line',
      data: {
        labels: Array.isArray(window.priceHistoryLabels) ? window.priceHistoryLabels : [],
        datasets: window.priceHistoryDatasets
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        scales: {
          x: {
            ticks: {
              maxRotation: 0,
              autoSkip: true,
              color: colors.text,
              font: { family: historyChartFont, size: 11 }
            },
            grid: {
              display: false
            },
            border: {
              display: false
            }
          },
          y: {
            beginAtZero: false,
            ticks: {
              color: colors.text,
              font: { family: historyChartFont, size: 11 },
              padding: 6,
              callback: function(value) {
                try {
                  return 'Gs. ' + Number(value).toLocaleString('es-PY');
                } catch (e) {
                  return value;
                }
              }
            },
            grid: {
              color: colors.grid,
              drawTicks: false
            },
            border: {
              display: false,
              dash: [4, 4]
            }
          }
        },
        plugins: {
          legend: {
            position: 'bottom',
            align: 'center',
            labels: {
              color: colors.legend,
              font: { family: historyChartFont, size: 12, weight: '500' },
              usePointStyle: true,
              pointStyle: 'circle',
              boxWidth: 8,
              boxHeight: 8,
              boxPadding: 14,
              padding: 18
            }
          },
          tooltip: {
            padding: 12,
            borderRadius: 10,
            backgroundColor: colors.tooltipBg,
            titleColor: colors.tooltipText,
            bodyColor: colors.tooltipText,
            borderColor: colors.tooltipBorder,
            borderWidth: 1,
            displayColors: true,
            usePointStyle: true,
            boxPadding: 8,
            titleFont: { weight: '600', family: historyChartFont, size: 12 },
            bodyFont: { family: historyChartFont, size: 12 },
            footerFont: { family: historyChartFont, size: 11 },
            callbacks: {
              label: function(context) {
                if (context.parsed.y === null || context.parsed.y === undefined) {
                  return null;
                }

                var store = context.dataset.storeLabel || context.dataset.label || 'Tienda';
                var price = 'Gs. ' + Number(context.parsed.y).toLocaleString('es-PY');
                return ' ' + store + ': ' + price;
              },
              afterLabel: function(context) {
                var shorts = Array.isArray(context.dataset.productShortByPoint)
                  ? context.dataset.productShortByPoint
                  : [];
                var products = Array.isArray(context.dataset.productByPoint)
                  ? context.dataset.productByPoint
                  : [];
                var shortName = shorts[context.dataIndex] || '';
                var fullName = products[context.dataIndex] || '';
                var hint = shortName || fullName;

                if (!hint) {
                  return null;
                }

                if (hint.length > 42) {
                  hint = hint.slice(0, 41) + '…';
                }

                return ' ' + hint;
              }
            }
          }
        }
      }
    });

    const themeObserver = new MutationObserver(function () {
      applyHistoryChartTheme(priceHistoryChart);
    });

    themeObserver.observe(document.body, {
      attributes: true,
      attributeFilter: ['class']
    });

    themeObserver.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-bs-theme']
    });
  }

  const historyTabsRoot = document.querySelector('[data-history-tabs]');
  if (historyTabsRoot) {
    const historyTablist = historyTabsRoot.querySelector('.pd-history-tablist');
    const historyTabThumb = historyTabsRoot.querySelector('[data-history-tab-thumb]');
    const historyTabs = Array.from(historyTabsRoot.querySelectorAll('[data-history-tab]'));
    const historyPanels = Array.from(historyTabsRoot.querySelectorAll('[data-history-panel]'));
    const historyTablePanel = historyTabsRoot.querySelector('[data-history-table-panel]');
    const historyRows = Array.from(historyTabsRoot.querySelectorAll('[data-history-row-page]'));
    const historyPageCurrent = historyTabsRoot.querySelector('[data-history-page-current]');
    const historyPagePrev = historyTabsRoot.querySelector('[data-history-page-prev]');
    const historyPageNext = historyTabsRoot.querySelector('[data-history-page-next]');
    const historyPageNums = Array.from(historyTabsRoot.querySelectorAll('[data-history-page]'));
    const historyTotalPages = historyTablePanel
      ? Math.max(1, parseInt(historyTablePanel.getAttribute('data-history-pages') || '1', 10) || 1)
      : 1;
    let historyCurrentPage = 1;

    function moveHistoryTabThumb(activeTab) {
      if (!historyTablist || !historyTabThumb || !activeTab) return;

      const listRect = historyTablist.getBoundingClientRect();
      const tabRect = activeTab.getBoundingClientRect();
      const offsetLeft = tabRect.left - listRect.left;
      const offsetTop = tabRect.top - listRect.top;

      historyTabThumb.style.width = tabRect.width + 'px';
      historyTabThumb.style.height = tabRect.height + 'px';
      historyTabThumb.style.left = '0';
      historyTabThumb.style.top = offsetTop + 'px';
      historyTabThumb.style.transform = 'translate3d(' + offsetLeft + 'px, 0, 0)';
    }

    function setHistoryTablePage(page) {
      historyCurrentPage = Math.min(historyTotalPages, Math.max(1, page));

      historyRows.forEach(function (row) {
        const rowPage = parseInt(row.getAttribute('data-history-row-page') || '1', 10) || 1;
        if (rowPage === historyCurrentPage) {
          row.removeAttribute('hidden');
        } else {
          row.setAttribute('hidden', '');
        }
      });

      if (historyPageCurrent) {
        historyPageCurrent.textContent = String(historyCurrentPage);
      }

      historyPageNums.forEach(function (btn) {
        const pageNum = parseInt(btn.getAttribute('data-history-page') || '1', 10) || 1;
        const isActive = pageNum === historyCurrentPage;
        btn.classList.toggle('is-active', isActive);
        if (isActive) {
          btn.setAttribute('aria-current', 'page');
        } else {
          btn.removeAttribute('aria-current');
        }
      });

      if (historyPagePrev) {
        const atStart = historyCurrentPage <= 1;
        historyPagePrev.classList.toggle('is-disabled', atStart);
        historyPagePrev.disabled = atStart;
      }

      if (historyPageNext) {
        const atEnd = historyCurrentPage >= historyTotalPages;
        historyPageNext.classList.toggle('is-disabled', atEnd);
        historyPageNext.disabled = atEnd;
      }
    }

    function activateHistoryTab(tabName) {
      var activeTab = null;

      historyTabs.forEach(function (tab) {
        const isActive = tab.getAttribute('data-history-tab') === tabName;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.tabIndex = isActive ? 0 : -1;
        if (isActive) activeTab = tab;
      });

      historyPanels.forEach(function (panel) {
        const isActive = panel.getAttribute('data-history-panel') === tabName;
        panel.classList.toggle('is-active', isActive);
        panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
      });

      moveHistoryTabThumb(activeTab);

      if (tabName === 'chart' && priceHistoryChart) {
        window.requestAnimationFrame(function () {
          priceHistoryChart.resize();
        });
      }
    }

    historyTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        activateHistoryTab(tab.getAttribute('data-history-tab'));
      });

      tab.addEventListener('keydown', function (event) {
        const currentIndex = historyTabs.indexOf(tab);
        if (currentIndex < 0) return;

        let nextIndex = currentIndex;
        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
          nextIndex = (currentIndex + 1) % historyTabs.length;
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
          nextIndex = (currentIndex - 1 + historyTabs.length) % historyTabs.length;
        } else if (event.key === 'Home') {
          nextIndex = 0;
        } else if (event.key === 'End') {
          nextIndex = historyTabs.length - 1;
        } else {
          return;
        }

        event.preventDefault();
        historyTabs[nextIndex].focus();
        activateHistoryTab(historyTabs[nextIndex].getAttribute('data-history-tab'));
      });
    });

    if (historyPagePrev) {
      historyPagePrev.addEventListener('click', function () {
        setHistoryTablePage(historyCurrentPage - 1);
      });
    }

    if (historyPageNext) {
      historyPageNext.addEventListener('click', function () {
        setHistoryTablePage(historyCurrentPage + 1);
      });
    }

    historyPageNums.forEach(function (btn) {
      btn.addEventListener('click', function () {
        const pageNum = parseInt(btn.getAttribute('data-history-page') || '1', 10) || 1;
        setHistoryTablePage(pageNum);
      });
    });

    if (historyTotalPages > 1) {
      setHistoryTablePage(1);
    }

    var initialHistoryTab = historyTabsRoot.querySelector('.pd-history-tab.is-active') || historyTabs[0];
    if (initialHistoryTab) {
      moveHistoryTabThumb(initialHistoryTab);
    }

    window.addEventListener('resize', function () {
      var active = historyTabsRoot.querySelector('.pd-history-tab.is-active');
      if (active) moveHistoryTabThumb(active);
    });
  }

  const storeCarousel = document.querySelector('[data-store-carousel]');
  const storeViewport = document.querySelector('[data-store-viewport]');
  const storeTrack = document.querySelector('[data-store-track]');
  const storePrevBtn = document.querySelector('[data-store-prev]');
  const storeNextBtn = document.querySelector('[data-store-next]');
  const storePauseBtn = document.querySelector('[data-store-pause]');

  if (storeCarousel && storeViewport && storeTrack) {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const originalCards = Array.from(storeTrack.querySelectorAll('.pd-store-card'));
    const STEP_DURATION = 1100;
    const STEP_PAUSE = 3200;
    const STORE_GAP = 13;
    const STORE_CARD_W = window.matchMedia('(max-width: 991.98px)').matches ? 170 : 200;
    let loopWidth = 0;
    let canLoop = false;
    let stepSize = STORE_CARD_W + STORE_GAP;
    let autoplay = !reduceMotion && originalCards.length > 1;
    let hoverPaused = false;
    let focusPaused = false;
    let pointerPaused = false;
    let animating = false;
    let resumeTimer = null;
    let stepTimer = null;
    let animRaf = 0;

    function cardWidthPx() {
      const card = storeTrack.querySelector('.pd-store-card');
      if (card) {
        const w = Math.round(card.getBoundingClientRect().width);
        if (w > 0) return w;
      }
      return window.matchMedia('(max-width: 991.98px)').matches ? 170 : 200;
    }

    function fitVisibleCards() {
      storeViewport.style.width = '100%';

      const available = Math.floor(storeCarousel.getBoundingClientRect().width);
      if (available <= 0) return;

      const width = cardWidthPx();
      const count = Math.max(1, Math.floor((available + STORE_GAP) / (width + STORE_GAP)));
      const used = count * width + Math.max(0, count - 1) * STORE_GAP;

      stepSize = width + STORE_GAP;
      storeViewport.style.width = used + 'px';
      storeViewport.style.maxWidth = '100%';
    }

    function cardStep() {
      const cards = storeTrack.querySelectorAll('.pd-store-card');
      if (cards.length >= 2) {
        const delta = cards[1].offsetLeft - cards[0].offsetLeft;
        if (delta > 0) {
          stepSize = delta;
          return delta;
        }
      }
      return stepSize > 0 ? stepSize : cardWidthPx() + STORE_GAP;
    }

    function currentCardIndex() {
      const step = cardStep();
      if (step <= 0) return 0;
      return Math.round(storeViewport.scrollLeft / step);
    }

    function snapToIndex(index) {
      const step = cardStep();
      if (step <= 0) return;
      storeViewport.scrollLeft = Math.max(0, index) * step;
      normalizeScroll();
      storeViewport.scrollLeft = Math.round(storeViewport.scrollLeft / step) * step;
    }

    function measureLoopWidth() {
      if (originalCards.length === 0) return 0;
      const pivot = storeTrack.children[originalCards.length];
      if (pivot) return pivot.offsetLeft;
      return originalCards.length * cardStep();
    }

    function ensureLoopClones() {
      storeTrack.querySelectorAll('[data-store-clone]').forEach(function (node) {
        node.remove();
      });

      fitVisibleCards();

      const overflows = storeTrack.scrollWidth > storeViewport.clientWidth + 1;
      if (!overflows || originalCards.length < 2) {
        canLoop = false;
        loopWidth = 0;
        return;
      }

      originalCards.forEach(function (card) {
        const clone = card.cloneNode(true);
        clone.setAttribute('data-store-clone', '');
        clone.setAttribute('aria-hidden', 'true');
        clone.tabIndex = -1;
        storeTrack.appendChild(clone);
      });

      loopWidth = measureLoopWidth();
      canLoop = loopWidth > 0;
      stepSize = cardStep();
    }

    function normalizeScroll() {
      if (!canLoop || loopWidth <= 0) return;
      while (storeViewport.scrollLeft >= loopWidth) {
        storeViewport.scrollLeft -= loopWidth;
      }
      while (storeViewport.scrollLeft < 0) {
        storeViewport.scrollLeft += loopWidth;
      }
    }

    function isAutoPlaying() {
      return autoplay && canLoop && !hoverPaused && !focusPaused && !pointerPaused && !animating;
    }

    function syncPauseButton() {
      if (!storePauseBtn) return;
      const show = !reduceMotion && canLoop && originalCards.length > 1;
      storePauseBtn.hidden = !show;
      if (!show) return;

      const paused = !autoplay;
      storePauseBtn.classList.toggle('is-paused', paused);
      storePauseBtn.setAttribute('aria-pressed', paused ? 'true' : 'false');
      storePauseBtn.setAttribute(
        'aria-label',
        paused ? 'Reanudar desplazamiento automático' : 'Pausar desplazamiento automático'
      );
      storePauseBtn.innerHTML = '';
      const icon = document.createElement('i');
      icon.setAttribute('data-lucide', paused ? 'play' : 'pause');
      icon.setAttribute('aria-hidden', 'true');
      storePauseBtn.appendChild(icon);
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons({
          nodes: [icon],
          attrs: { 'stroke-width': 1.75 }
        });
      }
    }

    function updateStoreNav() {
      if (canLoop) {
        if (storePrevBtn) storePrevBtn.disabled = false;
        if (storeNextBtn) storeNextBtn.disabled = false;
        return;
      }

      const maxScroll = storeViewport.scrollWidth - storeViewport.clientWidth;
      const atStart = storeViewport.scrollLeft <= 4;
      const atEnd = storeViewport.scrollLeft >= maxScroll - 4;

      if (storePrevBtn) storePrevBtn.disabled = atStart || maxScroll <= 0;
      if (storeNextBtn) storeNextBtn.disabled = atEnd || maxScroll <= 0;
    }

    function easeInOut(t) {
      return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
    }

    function animateTo(target, duration) {
      return new Promise(function (resolve) {
        if (animRaf) {
          window.cancelAnimationFrame(animRaf);
          animRaf = 0;
        }

        const start = storeViewport.scrollLeft;
        const distance = target - start;

        if (reduceMotion || duration <= 0 || Math.abs(distance) < 0.5) {
          storeViewport.scrollLeft = target;
          normalizeScroll();
          snapToIndex(currentCardIndex());
          updateStoreNav();
          resolve();
          return;
        }

        animating = true;
        const startTs = performance.now();

        function frame(ts) {
          const t = Math.min(1, (ts - startTs) / duration);
          storeViewport.scrollLeft = start + distance * easeInOut(t);
          if (t < 1) {
            animRaf = window.requestAnimationFrame(frame);
            return;
          }
          animRaf = 0;
          storeViewport.scrollLeft = target;
          normalizeScroll();
          snapToIndex(currentCardIndex());
          updateStoreNav();
          animating = false;
          resolve();
        }

        animRaf = window.requestAnimationFrame(frame);
      });
    }

    function scrollStoreBy(direction) {
      const step = cardStep();
      const index = currentCardIndex();
      return animateTo((index + direction) * step, reduceMotion ? 0 : STEP_DURATION);
    }

    function clearStepTimer() {
      if (stepTimer) {
        clearTimeout(stepTimer);
        stepTimer = null;
      }
    }

    function scheduleNextStep(delay) {
      clearStepTimer();
      if (!autoplay || !canLoop || reduceMotion) return;
      stepTimer = window.setTimeout(runAutoStep, delay);
    }

    function runAutoStep() {
      if (!isAutoPlaying()) {
        scheduleNextStep(600);
        return;
      }

      scrollStoreBy(1).then(function () {
        scheduleNextStep(STEP_PAUSE);
      });
    }

    function softPause(ms) {
      pointerPaused = true;
      clearStepTimer();
      if (resumeTimer) clearTimeout(resumeTimer);
      resumeTimer = setTimeout(function () {
        pointerPaused = false;
        scheduleNextStep(STEP_PAUSE);
      }, ms);
    }

    function rebuild() {
      clearStepTimer();
      if (animRaf) {
        window.cancelAnimationFrame(animRaf);
        animRaf = 0;
      }
      animating = false;

      const prevIndex = currentCardIndex();
      ensureLoopClones();
      snapToIndex(prevIndex);
      updateStoreNav();
      syncPauseButton();
      if (autoplay && canLoop) scheduleNextStep(STEP_PAUSE);
    }

    if (storePrevBtn) {
      storePrevBtn.addEventListener('click', function () {
        softPause(STEP_PAUSE + STEP_DURATION);
        scrollStoreBy(-1);
      });
    }

    if (storeNextBtn) {
      storeNextBtn.addEventListener('click', function () {
        softPause(STEP_PAUSE + STEP_DURATION);
        scrollStoreBy(1);
      });
    }

    if (storePauseBtn) {
      storePauseBtn.addEventListener('click', function () {
        if (storePauseBtn.hidden) return;
        autoplay = !autoplay;
        syncPauseButton();
        if (autoplay) {
          scheduleNextStep(900);
        } else {
          clearStepTimer();
        }
      });
    }

    storeCarousel.addEventListener('mouseenter', function () {
      hoverPaused = true;
      clearStepTimer();
    });
    storeCarousel.addEventListener('mouseleave', function () {
      hoverPaused = false;
      scheduleNextStep(STEP_PAUSE);
    });
    storeCarousel.addEventListener('focusin', function () {
      focusPaused = true;
      clearStepTimer();
    });
    storeCarousel.addEventListener('focusout', function () {
      window.setTimeout(function () {
        focusPaused = storeCarousel.contains(document.activeElement);
        if (!focusPaused) scheduleNextStep(STEP_PAUSE);
      }, 0);
    });
    storeViewport.addEventListener('pointerdown', function () {
      softPause(STEP_PAUSE + STEP_DURATION);
    });
    storeViewport.addEventListener('scroll', updateStoreNav, { passive: true });

    let resizeTimer = null;
    window.addEventListener('resize', function () {
      if (resizeTimer) clearTimeout(resizeTimer);
      resizeTimer = setTimeout(rebuild, 150);
    });

    window.addEventListener('load', rebuild);
    rebuild();
  }
});
</script>

<?php if ($trackViewInBrowser): ?>
<script>
(function () {
  const body = new URLSearchParams(<?= json_encode([
      'action' => $searchTerm !== '' ? 'search_click' : 'view',
      'product_id' => (int) $product['idproductos'],
      'term' => $searchTerm,
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);
  if (navigator.sendBeacon) {
    navigator.sendBeacon('event.php', body);
  } else {
    fetch('event.php', { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(() => {});
  }
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>