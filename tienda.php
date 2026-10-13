<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('ID de tienda inválido.');
}

$q = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['orden'] ?? 'recientes');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$pdo = db();

function store_sort_sql(string $sort, bool $hasSearch): string
{
    $allowed = [
        'recientes',
        'precio_asc',
        'precio_desc',
        'nombre_asc',
        'nombre_desc',
    ];

    if (!in_array($sort, $allowed, true)) {
        $sort = 'recientes';
    }

    $sql = match ($sort) {
        'precio_asc' => 'p.pro_precio ASC, p.pro_nombre ASC',
        'precio_desc' => 'p.pro_precio DESC, p.pro_nombre ASC',
        'nombre_asc' => 'p.pro_nombre ASC',
        'nombre_desc' => 'p.pro_nombre DESC',
        default => $hasSearch
            ? 'CASE WHEN p.pro_nombre LIKE :q_sort_nombre THEN 0 WHEN p.pro_marca LIKE :q_sort_marca THEN 1 ELSE 2 END, p.pro_fecha_scraping DESC, p.pro_nombre ASC'
            : 'p.pro_fecha_scraping DESC, p.pro_nombre ASC',
    };

    return '(p.pro_precio IS NULL OR p.pro_precio <= 0), ' . $sql;
}

function h(?string $value): string
{
    return e((string) ($value ?? ''));
}

function build_store_page_url(int $storeId, array $extra = []): string
{
    $params = array_merge([
        'id' => $storeId,
    ], $extra);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return 'tienda.php?' . http_build_query($params);
}

function parse_horarios(?string $horarios): array
{
    if (empty($horarios)) {
        return [];
    }

    $lines = array_filter(array_map('trim', explode("\n", $horarios)));
    $parsed = [];

    foreach ($lines as $line) {
        if (empty($line)) {
            continue;
        }

        // Try to match pattern like "Lunes: 9:00 - 18:00" or "Lunes a Viernes: 9:00 - 18:00"
        if (preg_match('/^(.+?):\s*(.+)$/', $line, $matches)) {
            $day = trim($matches[1]);
            $time = trim($matches[2]);
            
            // Try to separate day name from number (e.g., "Lunes 7" -> "Lunes", "7")
            if (preg_match('/^(Lunes|Martes|Miércoles|Jueves|Viernes|Sábado|Domingo|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\s*(\d*)$/i', $day, $dayParts)) {
                $dayName = trim($dayParts[1]);
                $dayNumber = trim($dayParts[2] ?? '');
                
                $parsed[] = [
                    'day' => $dayName,
                    'day_number' => $dayNumber,
                    'time' => $time,
                    'icon' => get_day_icon($dayName),
                ];
            } else {
                $parsed[] = [
                    'day' => $day,
                    'day_number' => '',
                    'time' => $time,
                    'icon' => get_day_icon($day),
                ];
            }
        } elseif (preg_match('/^(Lunes|Martes|Miércoles|Miercoles|Jueves|Viernes|Sábado|Sabado|Domingo|Feriados?)\s+(.+)$/iu', $line, $matches)) {
            $parsed[] = [
                'day' => trim($matches[1]),
                'day_number' => '',
                'time' => trim($matches[2]),
                'icon' => get_day_icon($matches[1]),
            ];
        } else {
            // If no pattern matches, treat as a note
            $parsed[] = [
                'day' => '',
                'day_number' => '',
                'time' => $line,
                'icon' => 'info',
            ];
        }
    }

    return $parsed;
}

function get_day_icon(string $day): string
{
    $dayLower = mb_strtolower($day);
    
    if (strpos($dayLower, 'lunes') !== false || strpos($dayLower, 'monday') !== false) {
        return 'calendar';
    }
    if (strpos($dayLower, 'martes') !== false || strpos($dayLower, 'tuesday') !== false) {
        return 'calendar';
    }
    if (strpos($dayLower, 'miércoles') !== false || strpos($dayLower, 'miercoles') !== false || strpos($dayLower, 'wednesday') !== false) {
        return 'calendar';
    }
    if (strpos($dayLower, 'jueves') !== false || strpos($dayLower, 'thursday') !== false) {
        return 'calendar';
    }
    if (strpos($dayLower, 'viernes') !== false || strpos($dayLower, 'friday') !== false) {
        return 'calendar';
    }
    if (strpos($dayLower, 'sábado') !== false || strpos($dayLower, 'sabado') !== false || strpos($dayLower, 'saturday') !== false) {
        return 'calendar';
    }
    if (strpos($dayLower, 'domingo') !== false || strpos($dayLower, 'sunday') !== false) {
        return 'calendar';
    }
    if (strpos($dayLower, 'festivo') !== false || strpos($dayLower, 'feriado') !== false || strpos($dayLower, 'holiday') !== false) {
        return 'alert-circle';
    }
    
    return 'clock';
}

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

function store_user_is_admin(): bool
{
    if (function_exists('is_admin')) {
        try {
            return (bool) is_admin();
        } catch (Throwable $e) {
        }
    }

    $checks = [
        $_SESSION['usuario']['usu_tipo'] ?? null,
        $_SESSION['user']['usu_tipo'] ?? null,
        $_SESSION['auth']['usu_tipo'] ?? null,
        $_SESSION['usuario']['tipo'] ?? null,
        $_SESSION['user']['tipo'] ?? null,
        $_SESSION['usu_tipo'] ?? null,
        $_SESSION['tipo'] ?? null,
    ];

    foreach ($checks as $value) {
        if ((string) $value === '1' || (string) $value === 'admin') {
            return true;
        }
    }

    return false;
}

$storeStmt = $pdo->prepare("
    SELECT
        t.*,
        COUNT(p.idproductos) AS total_productos,
        MAX(p.pro_fecha_scraping) AS ultima_actualizacion,
        (
            SELECT COUNT(*)
            FROM tienda_reviews tr
            WHERE tr.tiendas_idtiendas = t.idtiendas AND tr.rev_activo = 1
        ) AS total_reviews,
        (
            SELECT ROUND(AVG(tr.rev_puntaje), 1)
            FROM tienda_reviews tr
            WHERE tr.tiendas_idtiendas = t.idtiendas AND tr.rev_activo = 1
        ) AS rating_promedio
    FROM tiendas t
    LEFT JOIN productos p ON p.tiendas_idtiendas = t.idtiendas AND p.pro_activo = 1
    WHERE t.idtiendas = :id
    GROUP BY
        t.idtiendas,
        t.tie_nombre,
        t.tie_descripcion,
        t.tie_logo,
        t.tie_ubicacion,
        t.tie_url,
        t.tie_telefono,
        t.tie_email,
        t.tie_contacto,
        t.tie_horarios
    LIMIT 1
");
$storeStmt->execute([':id' => $id]);
$store = $storeStmt->fetch();

if (!$store) {
    http_response_code(404);
    die('Tienda no encontrada.');
}

$isAdmin = store_user_is_admin();
$reviewSuccess = isset($_GET['review_saved']) && $_GET['review_saved'] === '1';
$reviewDeleted = isset($_GET['review_deleted']) && $_GET['review_deleted'] === '1';
$reviewReported = isset($_GET['review_reported']) && $_GET['review_reported'] === '1';
$reviewError = '';
$reportError = '';
$reviewModals = [];

const REVIEW_MIN_FILL_SECONDS = 3;
const REVIEW_COOLDOWN_SECONDS = 60;
const REVIEW_MAX_PER_HOUR = 5;

if (empty($_SESSION['review_csrf'])) {
    $_SESSION['review_csrf'] = bin2hex(random_bytes(32));
}
$reviewCsrfToken = (string) $_SESSION['review_csrf'];

function review_rate_limit_error(): string
{
    $now = time();
    $history = array_values(array_filter(
        (array) ($_SESSION['review_history'] ?? []),
        static fn($ts) => is_int($ts) && $ts > $now - 3600
    ));
    $_SESSION['review_history'] = $history;

    if ($history && $now - max($history) < REVIEW_COOLDOWN_SECONDS) {
        return 'Espera un momento antes de enviar otra reseña.';
    }
    if (count($history) >= REVIEW_MAX_PER_HOUR) {
        return 'Alcanzaste el límite de reseñas por hora. Intenta más tarde.';
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_review') {
    if (!$isAdmin) {
        http_response_code(403);
        die('No autorizado.');
    }

    $reviewId = (int) ($_POST['review_id'] ?? 0);
    if ($reviewId > 0) {
        $deleteReview = $pdo->prepare("
            UPDATE tienda_reviews
            SET rev_activo = 0
            WHERE idreview = :review_id AND tiendas_idtiendas = :store_id
            LIMIT 1
        ");
        $deleteReview->execute([
            ':review_id' => $reviewId,
            ':store_id' => $id,
        ]);
    }

    header('Location: ' . build_store_page_url($id, [
        'q' => $q,
        'orden' => $sort,
        'page' => $page,
        'review_deleted' => 1,
    ]) . '#reviews');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'report_review') {
    $reviewId = (int) ($_POST['review_id'] ?? 0);
    $reportName = trim((string) ($_POST['rep_nombre'] ?? ''));
    $reportEmail = trim((string) ($_POST['rep_email'] ?? ''));
    $reportReason = trim((string) ($_POST['rep_motivo'] ?? ''));
    $reportDetail = trim((string) ($_POST['rep_detalle'] ?? ''));

    $allowedReasons = [
        'spam',
        'ofensivo',
        'informacion_falsa',
        'lenguaje_inapropiado',
        'otro',
    ];

    $sessionId = session_id();
    $ipAddress = client_ip_address();

    $captchaError = consent_error() ?: turnstile_error();

    if ($captchaError !== '') {
        $reportError = $captchaError;
    } elseif ($reviewId <= 0) {
        $reportError = 'La reseña seleccionada no es válida.';
    } elseif (!in_array($reportReason, $allowedReasons, true)) {
        $reportError = 'Selecciona un motivo válido.';
    } else {
        $reviewExistsStmt = $pdo->prepare("
            SELECT idreview
            FROM tienda_reviews
            WHERE idreview = :review_id
              AND tiendas_idtiendas = :store_id
              AND rev_activo = 1
            LIMIT 1
        ");
        $reviewExistsStmt->execute([
            ':review_id' => $reviewId,
            ':store_id' => $id,
        ]);

        if (!$reviewExistsStmt->fetchColumn()) {
            $reportError = 'No se encontró la reseña.';
        } else {
            $duplicateSql = "
                SELECT idreporte
                FROM tienda_review_reportes
                WHERE reviews_idreview = :review_id
            ";

            $duplicateParams = [
                ':review_id' => $reviewId,
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
                    $reportError = 'Ya reportaste esta reseña anteriormente.';
                }
            }

            if ($reportError === '') {
                $insertReport = $pdo->prepare("
                    INSERT INTO tienda_review_reportes (
                        reviews_idreview,
                        rep_nombre,
                        rep_email,
                        rep_motivo,
                        rep_detalle,
                        rep_ip,
                        rep_session_id,
                        rep_estado,
                        rep_fecha
                    ) VALUES (
                        :review_id,
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
                    ':review_id' => $reviewId,
                    ':nombre' => $reportName !== '' ? mb_substr($reportName, 0, 120) : null,
                    ':email' => $reportEmail !== '' ? mb_substr($reportEmail, 0, 150) : null,
                    ':motivo' => $reportReason,
                    ':detalle' => $reportDetail !== '' ? mb_substr($reportDetail, 0, 1000) : null,
                    ':ip' => $ipAddress !== '' ? $ipAddress : null,
                    ':session_id' => $sessionId !== '' ? $sessionId : null,
                ]);

                header('Location: ' . build_store_page_url($id, [
                    'q' => $q,
                    'orden' => $sort,
                    'page' => $page,
                    'review_reported' => 1,
                ]) . '#reviews');
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_review') {
    $reviewName = trim((string) ($_POST['rev_nombre'] ?? ''));
    $reviewComment = trim((string) ($_POST['rev_comentario'] ?? ''));
    $reviewScore = (int) ($_POST['rev_puntaje'] ?? 0);
    $postedToken = (string) ($_POST['review_token'] ?? '');
    $honeypot = trim((string) ($_POST['rev_website'] ?? ''));
    $formStartedAt = (int) ($_POST['rev_started'] ?? 0);

    if ($honeypot !== '' || $formStartedAt <= 0 || time() - $formStartedAt < REVIEW_MIN_FILL_SECONDS) {
        header('Location: ' . build_store_page_url($id, ['review_saved' => 1]) . '#reviews');
        exit;
    }

    $rateLimitError = review_rate_limit_error();

    if ($postedToken === '' || !hash_equals($reviewCsrfToken, $postedToken)) {
        $reviewError = 'La sesión expiró. Recarga la página e intenta nuevamente.';
    } elseif ($rateLimitError !== '') {
        $reviewError = $rateLimitError;
    } elseif (($consentError = consent_error()) !== '') {
        $reviewError = $consentError;
    } elseif (($captchaError = turnstile_error()) !== '') {
        $reviewError = $captchaError;
    } elseif ($reviewName === '') {
        $reviewError = 'Ingresa tu nombre para dejar una reseña.';
    } elseif ($reviewScore < 1 || $reviewScore > 5) {
        $reviewError = 'Selecciona una puntuación válida entre 1 y 5 estrellas.';
    } elseif ($reviewComment === '') {
        $reviewError = 'Escribe un comentario para la reseña.';
    } else {
        $insertReview = $pdo->prepare("
            INSERT INTO tienda_reviews (
                tiendas_idtiendas,
                rev_nombre,
                rev_puntaje,
                rev_comentario,
                rev_activo,
                rev_fecha
            ) VALUES (
                :tienda,
                :nombre,
                :puntaje,
                :comentario,
                1,
                NOW()
            )
        ");
        $insertReview->execute([
            ':tienda' => $id,
            ':nombre' => mb_substr($reviewName, 0, 120),
            ':puntaje' => $reviewScore,
            ':comentario' => mb_substr($reviewComment, 0, 1000),
        ]);

        $_SESSION['review_history'][] = time();

        header('Location: ' . build_store_page_url($id, ['review_saved' => 1]) . '#reviews');
        exit;
    }
}

$mapEmbedUrl = '';
$mapOpenUrl = '';
if (!empty($store['tie_ubicacion'])) {
    $rawLocation = trim((string) $store['tie_ubicacion']);

    $isUrl = preg_match('~^https?://~i', $rawLocation) === 1;
    $isCoords = preg_match('~^\s*-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?\s*$~', $rawLocation) === 1;

    $mapOpenUrl = $isUrl
        ? $rawLocation
        : 'https://www.google.com/maps?q=' . rawurlencode($rawLocation);

    if ($isCoords) {
        $mapEmbedUrl = 'https://www.google.com/maps?q=' . rawurlencode($rawLocation) . '&output=embed';
    } else {
        $mapQueryParts = array_filter([
            trim((string) ($store['tie_nombre'] ?? '')),
            trim((string) ($store['tie_descripcion'] ?? '')),
        ]);

        $mapQuery = trim(implode(' ', $mapQueryParts));
        if ($mapQuery === '') {
            $mapQuery = $rawLocation;
        }

        $mapEmbedUrl = 'https://www.google.com/maps?q=' . rawurlencode($mapQuery) . '&output=embed';
    }
}

$where = ['p.tiendas_idtiendas = :id', 'p.pro_activo = 1'];
$baseParams = [':id' => $id];
$hasSearch = $q !== '';

$where = ['p.tiendas_idtiendas = :id', 'p.pro_activo = 1'];
$baseParams = [':id' => $id];
$hasSearch = $q !== '';

if ($hasSearch) {
    $where[] = '(p.pro_nombre LIKE :q_nombre OR p.pro_descripcion LIKE :q_descripcion OR p.pro_marca LIKE :q_marca)';
    $searchTerm = '%' . $q . '%';
    $baseParams[':q_nombre'] = $searchTerm;
    $baseParams[':q_descripcion'] = $searchTerm;
    $baseParams[':q_marca'] = $searchTerm;
}
$whereSql = implode(' AND ', $where);
$orderSql = store_sort_sql($sort, $hasSearch);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM productos p
    WHERE {$whereSql}
");
$countStmt->execute($baseParams);
$totalProductsFiltered = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProductsFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$productParams = $baseParams;
if ($hasSearch) {
    $productParams[':q_sort_nombre'] = '%' . $q . '%';
    $productParams[':q_sort_marca'] = '%' . $q . '%';
}

$productStmt = $pdo->prepare("
    SELECT
        p.idproductos,
        p.pro_nombre,
        p.pro_descripcion,
        p.pro_marca,
        p.pro_precio,
        p.pro_precio_anterior,
        p.pro_imagen,
        p.pro_en_stock,
        p.pro_fecha_scraping,
        c.cat_nombre
    FROM productos p
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT {$perPage} OFFSET {$offset}
");
$productStmt->execute($productParams);
$products = $productStmt->fetchAll();

$categoryBreakdown = $pdo->prepare("
    SELECT COALESCE(c.cat_nombre, 'Sin categoría') AS cat_nombre, COUNT(*) AS total
    FROM productos p
    LEFT JOIN categorias c ON c.idcategorias = p.categorias_idcategorias
    WHERE p.tiendas_idtiendas = :id AND p.pro_activo = 1
    GROUP BY COALESCE(c.cat_nombre, 'Sin categoría')
    ORDER BY total DESC, cat_nombre ASC
    LIMIT 6
");
$categoryBreakdown->execute([':id' => $id]);
$categories = $categoryBreakdown->fetchAll();

$featuredStmt = $pdo->prepare("
    SELECT
        p.idproductos,
        p.pro_nombre,
        p.pro_precio,
        p.pro_precio_anterior,
        p.pro_imagen,
        ROUND((1 - p.pro_precio / p.pro_precio_anterior) * 100) AS descuento
    FROM productos p
    WHERE p.tiendas_idtiendas = :id
      AND p.pro_activo = 1
      AND p.pro_precio > 0
      AND p.pro_precio_anterior > p.pro_precio
      AND p.pro_precio / p.pro_precio_anterior BETWEEN 0.30 AND 0.97
    ORDER BY p.pro_en_stock DESC, descuento DESC, p.pro_fecha_scraping DESC
    LIMIT 3
");
$featuredStmt->execute([':id' => $id]);
$featuredProducts = $featuredStmt->fetchAll();
$featuredAreDeals = (bool) $featuredProducts;

if (!$featuredProducts) {
    $featuredStmt = $pdo->prepare("
        SELECT
            p.idproductos,
            p.pro_nombre,
            p.pro_precio,
            NULL AS pro_precio_anterior,
            p.pro_imagen,
            0 AS descuento
        FROM productos p
        WHERE p.tiendas_idtiendas = :id
          AND p.pro_activo = 1
          AND p.pro_precio > 0
          AND p.pro_en_stock = 1
          AND p.pro_imagen IS NOT NULL AND p.pro_imagen <> ''
        ORDER BY p.pro_fecha_scraping DESC, p.idproductos DESC
        LIMIT 3
    ");
    $featuredStmt->execute([':id' => $id]);
    $featuredProducts = $featuredStmt->fetchAll();
}

$reviewsStmt = $pdo->prepare("
    SELECT idreview, rev_nombre, rev_puntaje, rev_comentario, rev_fecha
    FROM tienda_reviews
    WHERE tiendas_idtiendas = :id AND rev_activo = 1
    ORDER BY rev_fecha DESC, idreview DESC
    LIMIT 100
");
$reviewsStmt->execute([':id' => $id]);
$reviews = $reviewsStmt->fetchAll();

$ratingBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$ratingBreakdownStmt = $pdo->prepare("
    SELECT rev_puntaje, COUNT(*) AS total
    FROM tienda_reviews
    WHERE tiendas_idtiendas = :id AND rev_activo = 1
    GROUP BY rev_puntaje
");
$ratingBreakdownStmt->execute([':id' => $id]);
foreach ($ratingBreakdownStmt->fetchAll() as $ratingRow) {
    $ratingScore = (int) ($ratingRow['rev_puntaje'] ?? 0);
    if (isset($ratingBreakdown[$ratingScore])) {
        $ratingBreakdown[$ratingScore] = (int) ($ratingRow['total'] ?? 0);
    }
}
$ratingBreakdownMax = max($ratingBreakdown);
$ratingBarLabels = [
    5 => 'Cinco',
    4 => 'Cuatro',
    3 => 'Tres',
    2 => 'Dos',
    1 => 'Una',
];

render_head($store['tie_nombre']);
render_navbar('tienda');
?>

<style>
/* ── Tienda (alineada al detalle de producto) ─────────────── */
.ts-page {
  --ts-accent: #8ba4c4;
  --ts-accent-warm: #c9a87a;
  --ts-accent-rose: #c48b8e;
  --ts-accent-sage: #8fad97;
  --ts-accent-lilac: #a89bc4;
  --ts-accent-teal: #7eb8b0;
  --ts-soft: #8ba4c4;
  --ts-soft-warm: #c9a87a;
  --ts-soft-rose: #c48b8e;
  --ts-soft-sage: #8fad97;
  --ts-star: var(--accent);
  padding: 1.15rem 0 3.5rem;
}

body.theme-light .ts-page {
  --ts-accent: #3f6597;
  --ts-accent-warm: #93672a;
  --ts-accent-rose: #9c4f54;
  --ts-accent-sage: #467356;
  --ts-accent-lilac: #6a5a98;
  --ts-accent-teal: #2f7d73;
  --ts-star: #e39404;
}

/* Los íconos toman el color de su contenedor o de --icon-c (nunca el negro genérico del tema claro). */
.ts-page svg.lucide,
body.theme-light .ts-page svg.lucide {
  color: var(--icon-c, currentColor) !important;
  stroke: currentColor !important;
}

.ts-page svg.lucide *,
body.theme-light .ts-page svg.lucide * {
  color: inherit !important;
  stroke: currentColor !important;
}

.ts-page .pd-crumb-link svg.lucide {
  --icon-c: var(--brand-2);
}

/* ── Paneles base ── */
.ts-panel {
  --lane-accent: var(--ts-accent);
  --lane-soft: var(--ts-soft);
  position: relative;
  display: flex;
  flex-direction: column;
  min-width: 0;
  background: var(--bg-card);
  border: 1px solid var(--border-soft);
  border-radius: 1.15rem;
  overflow: hidden;
}

.ts-panel::before {
  content: "";
  position: absolute;
  inset: 0 auto auto 0;
  width: 100%;
  height: 3px;
  background: color-mix(in srgb, var(--lane-accent) 38%, var(--bg-card));
  z-index: 2;
}

.ts-panel[data-tone="warm"] { --lane-accent: var(--ts-accent-warm); --lane-soft: var(--ts-soft-warm); }
.ts-panel[data-tone="rose"] { --lane-accent: var(--ts-accent-rose); --lane-soft: var(--ts-soft-rose); }
.ts-panel[data-tone="sage"] { --lane-accent: var(--ts-accent-sage); --lane-soft: var(--ts-soft-sage); }

.ts-mark {
  position: absolute;
  right: -0.25rem;
  top: -0.6rem;
  color: var(--lane-accent);
  opacity: 0.08;
  pointer-events: none;
}

.ts-mark svg.lucide {
  width: 5rem;
  height: 5rem;
}

.ts-kicker,
.ts-overline {
  margin: 0;
  color: var(--brand-2);
  text-transform: uppercase;
  letter-spacing: var(--ls-overline);
  font-size: var(--fs-overline);
  font-weight: var(--fw-semibold);
  line-height: 1.3;
}

.ts-overline {
  color: color-mix(in srgb, var(--text-soft) 75%, transparent);
  font-size: 0.68rem;
  font-weight: var(--fw-semibold);
  letter-spacing: 0.04em;
}

/* ── Board: resumen + columna lateral ── */
.ts-board {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(18.5rem, 0.8fr);
  gap: 0.85rem;
  align-items: stretch;
  margin-bottom: 1.15rem;
}

.ts-summary {
  gap: 0.95rem;
  padding: 1.15rem 1.15rem 1rem;
}

.ts-summary > *:not(.ts-mark) {
  position: relative;
  z-index: 1;
}

.ts-summary-top {
  display: grid;
  grid-template-columns: 5.5rem minmax(0, 1fr) auto;
  gap: 1rem;
  align-items: center;
}

.ts-summary-tools {
  display: flex;
  align-self: start;
}

.ts-logo {
  width: 5.5rem;
  height: 5.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.55rem;
  border-radius: 1rem;
  border: 1px solid var(--border-soft);
  background: #ffffff;
  overflow: hidden;
}

.ts-logo img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.ts-logo.is-initials {
  background: color-mix(in srgb, var(--lane-accent) 12%, var(--bg-elevated));
  border-color: color-mix(in srgb, var(--lane-accent) 28%, var(--border-soft));
  color: var(--lane-accent);
  font-size: 1.6rem;
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
}

.ts-identity {
  display: grid;
  gap: 0.3rem;
  min-width: 0;
}

.ts-title {
  margin: 0;
  font-size: clamp(1.4rem, 2.2vw, 1.85rem);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: 1.15;
  color: var(--text-main);
  overflow-wrap: anywhere;
}

.ts-rating-inline {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem 0.45rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
}

.ts-rating-inline strong {
  color: var(--text-main);
  font-weight: var(--fw-bold);
  font-variant-numeric: tabular-nums;
}

.ts-rating-inline a {
  color: inherit;
  text-decoration: none;
  border-bottom: 1px dashed color-mix(in srgb, var(--text-soft) 50%, transparent);
}

.ts-rating-inline a:hover,
.ts-rating-inline a:focus-visible {
  color: var(--brand-2);
  border-bottom-color: currentColor;
}

.ts-dot {
  color: color-mix(in srgb, var(--text-soft) 55%, transparent);
}

.ts-stars {
  color: var(--ts-star);
  letter-spacing: 0.06em;
  line-height: 1;
}

.ts-stars .is-empty {
  color: color-mix(in srgb, var(--ts-star) 30%, transparent);
}

.ts-lead {
  margin: 0;
  max-width: 46rem;
  color: var(--text-soft);
  font-size: var(--fs-small);
  line-height: 1.6;
  letter-spacing: var(--ls-body);
  white-space: pre-line;
}

/* Descripción + categorías */
.ts-about {
  display: grid;
  gap: 0.8rem;
  padding: 0.85rem 0.95rem;
  background: var(--bg-elevated);
  border: 1px solid var(--border-soft);
  border-radius: 0.85rem;
}

.ts-about .ts-cats {
  padding-top: 0.75rem;
  border-top: 1px solid var(--border-soft);
}

.ts-about .ts-chip {
  background: var(--bg-card);
}

/* Categorías */
.ts-cats {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: start;
  gap: 0.45rem 0.85rem;
}

.ts-cats > .ts-overline {
  line-height: 1.6rem;
}

.ts-cat-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.ts-chip {
  --chip: var(--ts-accent);
  --chip-ink: color-mix(in srgb, var(--chip) 80%, var(--text-soft));
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  min-height: 1.6rem;
  padding: 0.1rem 0.5rem;
  border: 1px solid var(--border-soft);
  border-radius: 999px;
  background: color-mix(in srgb, var(--bg-elevated) 60%, transparent);
  color: var(--text-soft);
  font-size: 0.75rem;
  font-weight: var(--fw-medium);
  line-height: 1.2;
  white-space: nowrap;
  transition: border-color 0.2s ease, color 0.2s ease;
}

.ts-chip:hover {
  border-color: color-mix(in srgb, var(--chip) 35%, var(--border-soft));
  color: var(--chip-ink);
}

.ts-chip svg.lucide {
  --icon-c: var(--chip-ink);
  width: 0.8rem;
  height: 0.8rem;
  flex-shrink: 0;
}

.ts-chip-name {
  color: inherit;
  font-weight: var(--fw-medium);
}

.ts-chip-count {
  padding-left: 0.35rem;
  border-left: 1px solid var(--border-soft);
  color: color-mix(in srgb, var(--text-soft) 75%, transparent);
  font-size: 0.68rem;
  font-weight: var(--fw-medium);
  font-variant-numeric: tabular-nums;
}

.ts-chip[data-tone="2"] { --chip: var(--ts-accent-warm); }
.ts-chip[data-tone="3"] { --chip: var(--ts-accent-rose); }
.ts-chip[data-tone="4"] { --chip: var(--ts-accent-sage); }
.ts-chip[data-tone="5"] { --chip: var(--ts-accent-lilac); }
.ts-chip[data-tone="6"] { --chip: var(--ts-accent-teal); }

/* Ofertas destacadas */
.ts-featured {
  --featured-accent: var(--ts-accent-rose);
  display: grid;
  gap: 0.55rem;
  margin-top: auto;
  padding-top: 0.85rem;
  border-top: 1px dashed var(--border-soft);
}

.ts-featured-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
}

.ts-featured-more {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  color: var(--text-soft);
  font-size: var(--fs-overline);
  font-weight: var(--fw-semibold);
  text-decoration: none;
  transition: color 0.2s ease;
}

.ts-featured-more svg.lucide {
  width: 0.8rem;
  height: 0.8rem;
  transition: transform 0.2s ease;
}

.ts-featured-more:hover,
.ts-featured-more:focus-visible {
  color: var(--brand-2);
}

.ts-featured-more:hover svg.lucide,
.ts-featured-more:focus-visible svg.lucide {
  transform: translateX(2px);
}

.ts-featured-list {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
}

.ts-featured-item {
  display: grid;
  grid-template-rows: auto 1fr;
  min-width: 0;
  border: 1px solid var(--border-soft);
  border-radius: 0.85rem;
  background: color-mix(in srgb, var(--bg-elevated) 70%, transparent);
  color: var(--text-main);
  text-decoration: none;
  overflow: hidden;
  transition: border-color 0.2s ease, transform 0.2s ease;
}

.ts-featured-item:hover,
.ts-featured-item:focus-visible {
  color: var(--text-main);
  border-color: color-mix(in srgb, var(--featured-accent) 40%, var(--border-soft));
  transform: translateY(-2px);
}

.ts-featured-media {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 6.25rem;
  padding: 0.55rem;
  background: #ffffff;
  border-bottom: 1px solid var(--border-soft);
  color: var(--text-soft);
}

.ts-featured-media img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  transition: transform 0.25s ease;
}

.ts-featured-item:hover .ts-featured-media img {
  transform: scale(1.04);
}

.ts-featured-badge {
  position: absolute;
  top: 0.4rem;
  left: 0.4rem;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--featured-accent) 16%, #ffffff);
  border: 1px solid color-mix(in srgb, var(--featured-accent) 30%, transparent);
  color: var(--featured-accent);
  font-size: 0.66rem;
  font-weight: var(--fw-bold);
  font-variant-numeric: tabular-nums;
  line-height: 1.3;
}

.ts-featured-copy {
  display: grid;
  align-content: space-between;
  gap: 0.3rem;
  padding: 0.5rem 0.6rem 0.55rem;
  min-width: 0;
}

.ts-featured-name {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
  line-height: 1.3;
}

.ts-featured-prices {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.1rem 0.4rem;
  font-variant-numeric: tabular-nums;
}

.ts-featured-prices strong {
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-bold);
}

.ts-featured-prices s {
  color: var(--text-soft);
  font-size: 0.7rem;
}

/* Estadísticas */
.ts-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  margin: 0;
}

.ts-stat {
  --stat-accent: var(--ts-accent);
  position: relative;
  min-width: 0;
  padding: 0.7rem 2.4rem 0.7rem 0.85rem;
  background: var(--bg-elevated);
  border: 1px solid var(--border-soft);
  border-radius: 0.85rem;
  overflow: hidden;
  transition: border-color 0.2s ease;
}

.ts-stat:hover {
  border-color: color-mix(in srgb, var(--stat-accent) 35%, var(--border-soft));
}

.ts-stat[data-tone="warm"] { --stat-accent: var(--ts-accent-warm); }
.ts-stat[data-tone="sage"] { --stat-accent: var(--ts-accent-sage); }

.ts-stat-icon {
  position: absolute;
  right: 0.6rem;
  bottom: 0.55rem;
  display: inline-flex;
  color: var(--stat-accent);
  opacity: 0.45;
  pointer-events: none;
}

.ts-stat-icon svg.lucide {
  width: 1.25rem;
  height: 1.25rem;
}

.ts-stat dt {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.68rem;
  font-weight: var(--fw-semibold);
  letter-spacing: var(--ls-overline);
  text-transform: uppercase;
  line-height: 1.2;
}

.ts-stat dd {
  margin: 0.1rem 0 0;
  color: var(--text-main);
  font-size: 1.05rem;
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ts-stat dd small {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: var(--fw-semibold);
}

/* ── Columna lateral ── */
.ts-side {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  min-width: 0;
  min-height: 0;
}

.ts-side > .ts-info-panel:only-child {
  flex: 1 1 auto;
}

.ts-panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  padding: 0.85rem 0.9rem 0.7rem;
  border-bottom: 1px solid var(--border-soft);
}

.ts-panel-title {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
}

.ts-panel-icon {
  width: 1.9rem;
  height: 1.9rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border-radius: 0.6rem;
  border: 1px solid color-mix(in srgb, var(--lane-soft) 22%, transparent);
  background: color-mix(in srgb, var(--lane-soft) 14%, transparent);
  color: var(--lane-soft);
}

.ts-panel-icon svg.lucide {
  width: 0.95rem;
  height: 0.95rem;
}

.ts-link-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  min-height: 1.85rem;
  padding: 0 0.6rem;
  border: 1px solid color-mix(in srgb, var(--brand) 32%, var(--border-soft));
  border-radius: 0.55rem;
  background: color-mix(in srgb, var(--brand) 8%, transparent);
  color: var(--brand-2);
  font-size: var(--fs-overline);
  font-weight: var(--fw-semibold);
  text-decoration: none;
  white-space: nowrap;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.ts-link-chip:hover,
.ts-link-chip:focus-visible {
  color: var(--brand-2);
  border-color: color-mix(in srgb, var(--brand) 55%, var(--border-soft));
  background: color-mix(in srgb, var(--brand) 15%, transparent);
}

.ts-link-chip svg.lucide {
  width: 0.85rem;
  height: 0.85rem;
}

/* Tabs Contacto / Horario */
.ts-tablist {
  position: relative;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.3rem;
  margin: 0.95rem 0.9rem 0;
  padding: 0.3rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.85rem;
  background: color-mix(in srgb, var(--bg-elevated) 88%, transparent);
}

.ts-tab-thumb {
  position: absolute;
  top: 0.3rem;
  left: 0.3rem;
  z-index: 0;
  width: calc((100% - 0.9rem) / 2);
  height: calc(100% - 0.6rem);
  border: 1px solid color-mix(in srgb, var(--lane-accent) 26%, transparent);
  border-radius: 0.65rem;
  box-sizing: border-box;
  background: color-mix(in srgb, var(--lane-accent) 14%, var(--bg-elevated));
  transform: translate3d(0, 0, 0);
  transition:
    transform 0.55s cubic-bezier(0.33, 1, 0.32, 1),
    width 0.55s cubic-bezier(0.33, 1, 0.32, 1),
    top 0.55s cubic-bezier(0.33, 1, 0.32, 1);
  pointer-events: none;
  will-change: transform, width;
}

@media (prefers-reduced-motion: reduce) {
  .ts-tab-thumb {
    transition: none;
  }
}

.ts-tab-btn {
  position: relative;
  z-index: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  min-height: 2.2rem;
  padding: 0.4rem 0.7rem;
  border: 1px solid transparent;
  border-radius: 0.65rem;
  background: transparent;
  color: var(--text-soft);
  font-family: inherit;
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  transition: color 0.2s ease;
}

.ts-tab-btn svg.lucide {
  width: 0.95rem;
  height: 0.95rem;
}

.ts-tab-btn:hover,
.ts-tab-btn:focus-visible {
  color: var(--lane-accent);
}

.ts-tab-btn.active {
  color: var(--lane-accent);
}

.ts-tabs-content {
  --ts-hours-row-h: 2.44rem;
  --ts-hours-gap: 0.4rem;
  --ts-hours-visible: 5;
  flex: 0 0 auto;
  box-sizing: border-box;
  height: calc(
    0.75rem
    + var(--ts-hours-visible) * var(--ts-hours-row-h)
    + var(--ts-hours-visible) * var(--ts-hours-gap)
  );
  padding: 0.75rem 0.35rem 0.9rem 0.9rem;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-gutter: stable;
  --ts-scroll-thumb: color-mix(in srgb, var(--lane-accent) 28%, transparent);
  --ts-scroll-thumb-hover: color-mix(in srgb, var(--lane-accent) 55%, transparent);
}

@supports not selector(::-webkit-scrollbar) {
  .ts-tabs-content {
    scrollbar-width: thin;
    scrollbar-color: var(--ts-scroll-thumb) transparent;
  }
}

.ts-tabs-content::-webkit-scrollbar {
  width: 10px;
}

.ts-tabs-content::-webkit-scrollbar-track {
  background: transparent;
  margin-block: 0.6rem;
}

.ts-tabs-content::-webkit-scrollbar-thumb {
  border: 3px solid transparent;
  border-radius: 999px;
  background-color: var(--ts-scroll-thumb);
  background-clip: padding-box;
  transition: background-color 0.2s ease;
}

.ts-tabs-content:hover::-webkit-scrollbar-thumb {
  background-color: var(--ts-scroll-thumb-hover);
}

.ts-tabs-content::-webkit-scrollbar-thumb:active {
  background-color: var(--lane-accent);
}

.ts-tabs-content::-webkit-scrollbar-button,
.ts-tabs-content::-webkit-scrollbar-corner {
  display: none;
  width: 0;
  height: 0;
}

.ts-tab-pane {
  display: none;
}

.ts-tab-pane.active {
  display: grid;
  gap: 0.4rem;
}

.ts-tabs-content:has(#horario.active) {
  padding-bottom: var(--ts-hours-gap);
  scroll-snap-type: y mandatory;
  scroll-padding-block: 0.75rem var(--ts-hours-gap);
}

.ts-contact-row {
  --row-accent: var(--ts-accent);
  display: grid;
  grid-template-columns: 2rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0.55rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.7rem;
  background: color-mix(in srgb, var(--bg-elevated) 70%, transparent);
  color: var(--text-main);
  text-decoration: none;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.ts-contact-row[data-kind="phone"] { --row-accent: var(--ts-accent-sage); }
.ts-contact-row[data-kind="mail"] { --row-accent: var(--ts-accent-rose); }
.ts-contact-row[data-kind="web"] { --row-accent: var(--ts-accent-warm); }

a.ts-contact-row:hover,
a.ts-contact-row:focus-visible {
  color: var(--text-main);
  border-color: color-mix(in srgb, var(--row-accent) 40%, var(--border-soft));
  background: color-mix(in srgb, var(--row-accent) 7%, var(--bg-elevated));
}

.ts-contact-icon {
  width: 2rem;
  height: 2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.6rem;
  background: color-mix(in srgb, var(--row-accent) 14%, transparent);
  color: var(--row-accent);
}

.ts-contact-icon svg.lucide {
  width: 0.95rem;
  height: 0.95rem;
}

.ts-contact-copy {
  display: grid;
  gap: 0.05rem;
  min-width: 0;
}

.ts-contact-label {
  color: var(--text-soft);
  font-size: 0.65rem;
  font-weight: var(--fw-semibold);
  letter-spacing: var(--ls-overline);
  text-transform: uppercase;
  line-height: 1.2;
}

.ts-contact-value {
  color: var(--text-main);
  font-size: var(--fs-caption);
  font-weight: var(--fw-medium);
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ts-contact-go {
  color: color-mix(in srgb, var(--row-accent) 80%, var(--text-soft));
  opacity: 0.6;
  transition: opacity 0.2s ease;
}

.ts-contact-go svg.lucide {
  width: 0.85rem;
  height: 0.85rem;
}

a.ts-contact-row:hover .ts-contact-go,
a.ts-contact-row:focus-visible .ts-contact-go {
  opacity: 1;
}

.ts-hours-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.3rem 0.65rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.65rem;
  background: color-mix(in srgb, var(--bg-elevated) 70%, transparent);
  font-size: var(--fs-caption);
  line-height: 1.25;
  box-sizing: border-box;
  min-height: var(--ts-hours-row-h, auto);
  scroll-snap-align: start;
}

.ts-hours-day {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--text-main);
  font-weight: var(--fw-semibold);
}

.ts-hours-time {
  color: var(--text-soft);
  font-weight: var(--fw-medium);
  font-variant-numeric: tabular-nums;
  text-align: right;
}

.ts-hours-row.is-note {
  justify-content: flex-start;
  color: var(--text-soft);
}

.ts-hours-row.is-today {
  border-color: color-mix(in srgb, var(--lane-accent) 45%, var(--border-soft));
  background: color-mix(in srgb, var(--lane-accent) 10%, var(--bg-elevated));
}

.ts-hours-row.is-today .ts-hours-time {
  color: var(--text-main);
}

.ts-today-badge {
  padding: 0.05rem 0.4rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--lane-accent) 20%, transparent);
  color: var(--lane-accent);
  font-size: 0.6rem;
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-overline);
  text-transform: uppercase;
}

.ts-empty-inline {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0;
  padding: 0.75rem;
  border: 1px dashed var(--border-soft);
  border-radius: 0.7rem;
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.ts-empty-inline svg.lucide {
  --icon-c: var(--lane-accent);
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
}

/* Mapa */
.ts-map-panel {
  flex: 1 1 auto;
  min-height: 15rem;
}

.ts-map-wrap {
  position: relative;
  flex: 1 1 auto;
  min-height: 10rem;
  margin: 0.7rem 0.9rem 0.9rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.85rem;
  overflow: hidden;
  background: var(--bg-elevated);
}

.ts-map-wrap iframe {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
  display: block;
}

/* ── Lanes: catálogo y reseñas ── */
.ts-content {
  display: flex;
  flex-direction: column;
  gap: 1.15rem;
}

.ts-lane {
  padding: 1.15rem 1.15rem 1.2rem;
  scroll-margin-top: 5.5rem;
}

.ts-lane > *:not(.ts-mark) {
  position: relative;
  z-index: 1;
}

.ts-lane-head {
  display: grid;
  grid-template-columns: 2.55rem minmax(0, 1fr) auto;
  gap: 0.75rem;
  align-items: center;
  margin-bottom: 1rem;
  padding-bottom: 0.95rem;
  border-bottom: 1px solid var(--border-soft);
}

.ts-lane-icon {
  width: 2.55rem;
  height: 2.55rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.85rem;
  border: 1px solid color-mix(in srgb, var(--lane-soft) 22%, transparent);
  background: color-mix(in srgb, var(--lane-soft) 14%, transparent);
  color: var(--lane-soft);
}

.ts-lane-icon svg.lucide {
  width: 1.15rem;
  height: 1.15rem;
}

.ts-lane-title {
  margin: 0;
  font-size: var(--fs-h4);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: 1.25;
  color: var(--text-main);
}

.ts-lane-caption {
  margin: 0.15rem 0 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: 1.35;
}

.ts-count {
  display: inline-flex;
  align-items: center;
  min-height: 1.85rem;
  padding: 0.2rem 0.65rem;
  border: 1px solid color-mix(in srgb, var(--accent) 55%, var(--border-soft));
  border-radius: 0.55rem;
  background: color-mix(in srgb, var(--accent) 18%, var(--bg-elevated));
  color: color-mix(in srgb, var(--accent) 72%, var(--text-main));
  font-size: var(--fs-overline);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-overline);
  text-transform: uppercase;
  white-space: nowrap;
}

body.theme-light .ts-count {
  color: #8a5a03;
}

/* Toolbar de búsqueda */
.ts-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(12rem, 1fr) auto;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.ts-toolbar:has(.ts-toolbar-clear) {
  grid-template-columns: minmax(0, 1.6fr) minmax(12rem, 1fr) auto auto;
}

.ts-field {
  position: relative;
  display: block;
  margin: 0;
  min-width: 0;
}

.ts-field > svg.lucide,
.ts-field > i {
  position: absolute;
  top: 50%;
  left: 0.75rem;
  width: 1rem;
  height: 1rem;
  transform: translateY(-50%);
  --icon-c: var(--brand-2);
  pointer-events: none;
  z-index: 1;
}

.ts-field > svg.lucide.lucide-chevron-down,
.ts-field > i[data-lucide="chevron-down"] {
  left: auto;
  right: 0.75rem;
  --icon-c: var(--text-soft);
}

.ts-page .ts-field .form-control,
.ts-page .ts-field .form-select {
  width: 100%;
  min-height: 2.5rem;
  padding: 0.45rem 0.8rem 0.45rem 2.3rem;
  border: 1px solid var(--border-soft) !important;
  border-radius: 0.7rem;
  background-color: var(--bg-card) !important;
  background-image: none !important;
  color: var(--text-main) !important;
  font-size: var(--fs-small);
  box-shadow: none !important;
  appearance: none;
  -webkit-appearance: none;
  transition: border-color 0.2s ease;
}

.ts-page .ts-field .form-select {
  padding-right: 2.3rem;
  cursor: pointer;
}

.ts-page .ts-field--search .form-control {
  padding-right: 2.4rem;
}

.ts-field--search input[type="search"]::-webkit-search-cancel-button,
.ts-field--search input[type="search"]::-webkit-search-decoration {
  -webkit-appearance: none;
  appearance: none;
  display: none;
}

.ts-field-clear {
  position: absolute;
  top: 50%;
  right: 0.5rem;
  z-index: 1;
  display: inline-grid;
  place-items: center;
  width: 1.5rem;
  height: 1.5rem;
  padding: 0;
  border: 0;
  border-radius: 999px;
  background: color-mix(in srgb, var(--text-soft) 14%, transparent);
  --icon-c: var(--text-soft);
  color: var(--text-soft);
  transform: translateY(-50%);
  cursor: pointer;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.ts-field-clear[hidden] {
  display: none;
}

.ts-field-clear:hover,
.ts-field-clear:focus-visible {
  background: color-mix(in srgb, var(--brand) 16%, transparent);
  --icon-c: var(--brand);
  color: var(--brand);
  outline: none;
}

.ts-field-clear svg.lucide {
  width: 0.85rem;
  height: 0.85rem;
}

.ts-page .ts-field .form-control:focus,
.ts-page .ts-field .form-select:focus {
  border-color: var(--brand) !important;
  outline: none;
}

.ts-page .ts-field .form-control::placeholder {
  color: var(--text-soft);
  opacity: 0.7;
}

.ts-page .ts-field .form-select option {
  color: var(--text-main);
  background-color: var(--bg-elevated);
}

.ts-toolbar .btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  min-height: 2.5rem;
  padding-inline: 1.1rem;
  border-radius: 0.7rem;
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  box-shadow: none;
}

.ts-toolbar .btn svg.lucide {
  width: 0.95rem;
  height: 0.95rem;
}

.ts-toolbar-clear {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  min-height: 2.5rem;
  padding: 0 0.85rem;
  border: 1px solid color-mix(in srgb, var(--ts-accent-rose) 32%, var(--border-soft));
  border-radius: 0.7rem;
  background: color-mix(in srgb, var(--ts-accent-rose) 8%, transparent);
  color: var(--ts-accent-rose);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
  text-decoration: none;
  white-space: nowrap;
}

.ts-toolbar-clear:hover,
.ts-toolbar-clear:focus-visible {
  color: var(--ts-accent-rose);
  background: color-mix(in srgb, var(--ts-accent-rose) 15%, transparent);
}

.ts-toolbar-clear svg.lucide {
  width: 0.9rem;
  height: 0.9rem;
}

/* Grilla de productos */
.ts-products-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.85rem;
}

body.theme-light .ts-page .sr-card-cta:hover,
body.theme-light .ts-page .sr-card-cta:focus-visible,
body.theme-light .ts-page .sr-card-cta:hover svg.lucide,
body.theme-light .ts-page .sr-card-cta:focus-visible svg.lucide {
  color: var(--auth-blue) !important;
  stroke: currentColor !important;
}

.ts-page .products-pager {
  margin-top: 1.1rem;
  background: color-mix(in srgb, var(--bg-elevated) 88%, transparent);
}

.ts-page .products-pager-btn {
  color: var(--brand-2);
}

.ts-page .products-pager-btn:hover,
.ts-page .products-pager-btn:focus-visible {
  color: var(--brand-2);
  background: color-mix(in srgb, var(--brand) 10%, var(--bg-elevated));
}

.ts-empty {
  display: grid;
  justify-items: center;
  gap: 0.55rem;
  padding: 2.25rem 1rem;
  border: 1px dashed var(--border-soft);
  border-radius: 0.95rem;
  color: var(--text-soft);
  font-size: var(--fs-small);
  text-align: center;
}

.ts-empty-icon {
  width: 3rem;
  height: 3rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.9rem;
  background: color-mix(in srgb, var(--lane-accent) 13%, transparent);
  color: var(--lane-accent);
}

.ts-empty-icon svg.lucide {
  width: 1.35rem;
  height: 1.35rem;
}

.ts-empty strong {
  color: var(--text-main);
  font-weight: var(--fw-semibold);
}

/* ── Reseñas ── */
.ts-toast-stack {
  position: fixed;
  top: 5rem;
  right: 1.25rem;
  z-index: 2100;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.6rem;
  pointer-events: none;
}

.ts-toast {
  --ts-toast-bg: #2e9d5b;
  --ts-toast-fg: #fff;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  max-width: min(23rem, calc(100vw - 2.5rem));
  padding: 0.6rem 0.5rem 0.6rem 0.8rem;
  border-radius: var(--radius-control);
  background: var(--ts-toast-bg);
  color: var(--ts-toast-fg);
  font-size: var(--fs-small);
  font-weight: var(--fw-medium);
  line-height: var(--lh-small);
  letter-spacing: var(--ls-body);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
  pointer-events: auto;
  opacity: 0;
  transform: translateY(-0.6rem);
  transition: opacity 0.25s ease, transform 0.25s ease;
}

.ts-toast.is-visible {
  opacity: 1;
  transform: none;
}

.ts-toast.is-leaving {
  opacity: 0;
  transform: translateX(1rem);
}

.ts-toast--warning {
  --ts-toast-bg: var(--auth-amber);
  --ts-toast-fg: #2a1d00;
}

.ts-toast--danger {
  --ts-toast-bg: #d14f4f;
}

.ts-toast-icon,
.ts-toast-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.ts-toast-text {
  flex: 1;
  min-width: 0;
}

.ts-toast-close {
  width: 1.65rem;
  height: 1.65rem;
  padding: 0;
  border: 0;
  border-radius: 0.45rem;
  background: transparent;
  color: inherit;
  opacity: 0.75;
  cursor: pointer;
  transition: opacity 0.2s ease, background-color 0.2s ease;
}

.ts-toast-close:hover,
.ts-toast-close:focus-visible {
  opacity: 1;
  background: rgba(255, 255, 255, 0.18);
  outline: none;
}

.ts-toast-icon svg.lucide,
.ts-toast-icon svg.lucide *,
.ts-toast-close svg.lucide,
.ts-toast-close svg.lucide *,
body.theme-light .ts-toast-icon svg.lucide,
body.theme-light .ts-toast-icon svg.lucide *,
body.theme-light .ts-toast-close svg.lucide,
body.theme-light .ts-toast-close svg.lucide * {
  color: var(--ts-toast-fg) !important;
  stroke: var(--ts-toast-fg) !important;
}

@media (max-width: 575.98px) {
  .ts-toast-stack {
    top: 4.5rem;
    right: 0.75rem;
    left: 0.75rem;
    align-items: stretch;
  }

  .ts-toast {
    max-width: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .ts-toast,
  .ts-toast.is-leaving {
    transform: none;
    transition: none;
  }
}

.ts-reviews-top {
  display: grid;
  grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
  gap: 0.85rem;
  margin-bottom: 2.25rem;
}

.ts-subcard {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  min-width: 0;
  padding: 1rem 1.05rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.95rem;
  background: var(--bg-elevated);
}

.ts-subcard-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-body);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: 1.25;
}

.ts-subcard-head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid var(--border-soft);
}

.ts-subcard-head-icon {
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.7rem;
  border: 1px solid color-mix(in srgb, var(--ts-soft) 22%, transparent);
  background: color-mix(in srgb, var(--ts-soft) 14%, transparent);
  --icon-c: var(--ts-soft);
  color: var(--ts-soft);
}

.ts-subcard-head-icon svg.lucide {
  width: 1.1rem;
  height: 1.1rem;
}

.ts-subcard-head-text {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.ts-subcard-subtitle {
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: 1.4;
}

.ts-rating-layout {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  flex: 1;
}

.ts-rating-score {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.45rem;
  padding: 1rem;
  border: 1px solid color-mix(in srgb, var(--ts-star) 28%, var(--border-soft));
  border-radius: 0.85rem;
  background: color-mix(in srgb, var(--ts-star) 8%, transparent);
  text-align: center;
}

.ts-rating-score-meta {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  min-width: 0;
}

.ts-rating-score-value {
  margin: 0;
  color: var(--text-main);
  font-size: 2.35rem;
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-display);
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.ts-rating-score .ts-stars {
  font-size: 0.95rem;
}

.ts-rating-score-count {
  margin: 0;
  color: var(--text-soft);
  font-size: var(--fs-overline);
  font-weight: var(--fw-semibold);
  white-space: nowrap;
}

.ts-rating-bars {
  display: grid;
  gap: 0.55rem;
  min-width: 0;
}

.ts-rating-bar {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) 2rem;
  align-items: center;
  gap: 0.75rem;
}

.ts-rating-bar-label {
  display: inline-flex;
  align-items: center;
  color: var(--ts-star);
  font-size: 0.85rem;
  letter-spacing: 0.06em;
  line-height: 1;
  white-space: nowrap;
}

.ts-rating-bar-label .is-empty {
  color: color-mix(in srgb, var(--ts-star) 30%, transparent);
}

.ts-rating-bar-track {
  height: 0.5rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--text-soft) 16%, transparent);
  overflow: hidden;
}

.ts-rating-bar-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--ts-star);
}

.ts-rating-bar-count {
  color: var(--text-main);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
  text-align: right;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}

/* Formulario de reseña */
.ts-review-form {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  flex: 1;
  scroll-margin-top: 6rem;
}

.ts-review-form-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 0.7rem;
}

.ts-review-field {
  display: grid;
  gap: 0.3rem;
  min-width: 0;
}

.ts-review-field--grow {
  flex: 1;
  grid-template-rows: auto minmax(0, 1fr);
}

.ts-review-field-label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.ts-review-form .form-label {
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-caption);
  font-weight: var(--fw-semibold);
}

.ts-req {
  color: var(--danger);
}

.ts-review-select {
  position: relative;
}

.ts-review-select > svg.lucide,
.ts-review-select > i {
  position: absolute;
  top: 50%;
  right: 0.7rem;
  width: 1rem;
  height: 1rem;
  transform: translateY(-50%);
  --icon-c: var(--text-soft);
  pointer-events: none;
}

.ts-review-char-count {
  color: var(--text-soft);
  font-size: var(--fs-overline);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  transition: color 0.2s ease;
}

.ts-review-char-count.is-near {
  color: var(--ts-accent-warm);
  font-weight: var(--fw-semibold);
}

.ts-page .ts-review-form .form-control,
.ts-page .ts-review-form .form-select {
  width: 100%;
  min-height: 2.45rem;
  padding: 0.45rem 0.75rem;
  border: 1px solid var(--border-soft) !important;
  border-radius: 0.65rem;
  background-color: var(--bg-card) !important;
  background-image: none !important;
  color: var(--text-main) !important;
  font-size: var(--fs-small);
  box-shadow: none !important;
  appearance: none;
  -webkit-appearance: none;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.ts-page .ts-review-form .form-select {
  padding-right: 2.2rem;
  cursor: pointer;
}

.ts-page .ts-review-form .form-select:invalid {
  color: var(--text-soft) !important;
}

.ts-page .ts-review-form .form-select option {
  color: var(--text-main);
  background-color: var(--bg-elevated);
}

.ts-page .ts-review-form .form-control:focus,
.ts-page .ts-review-form .form-select:focus {
  border-color: var(--brand) !important;
  outline: none;
}

body.theme-light .ts-page .ts-review-form .form-control,
body.theme-light .ts-page .ts-review-form .form-select {
  border-color: #dde3ec !important;
  background-color: #f4f6fa !important;
}

body.theme-light .ts-page .ts-review-form .form-control:hover,
body.theme-light .ts-page .ts-review-form .form-select:hover {
  border-color: #c9d2df !important;
}

body.theme-light .ts-page .ts-review-form .form-control:focus,
body.theme-light .ts-page .ts-review-form .form-select:focus {
  border-color: var(--brand) !important;
  background-color: #fff !important;
}

.ts-page .ts-review-form .form-control::placeholder {
  color: var(--text-soft);
  opacity: 0.7;
}

.ts-page .ts-review-form textarea.form-control {
  height: 100%;
  min-height: 5rem;
  resize: none;
  line-height: 1.5;
}

.ts-review-form .btn-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  align-self: flex-end;
  min-height: 2.4rem;
  padding-inline: 1.15rem;
  border-radius: 0.65rem;
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  box-shadow: none;
}

.ts-review-form .btn-primary svg.lucide {
  width: 0.95rem;
  height: 0.95rem;
}

/* Carrusel */
.ts-reviews-carousel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid var(--border-soft);
}

.ts-subcard-head--flush {
  min-width: 0;
  padding-bottom: 0;
  border-bottom: 0;
}

.ts-reviews-carousel-nav {
  display: inline-flex;
  gap: 0.35rem;
}

.ts-reviews-carousel-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  padding: 0;
  border: 1px solid var(--border-soft);
  border-radius: 0.6rem;
  background: var(--bg-elevated);
  color: var(--brand-2);
  cursor: pointer;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.ts-reviews-carousel-btn:hover:not(:disabled),
.ts-reviews-carousel-btn:focus-visible {
  border-color: var(--border-strong);
  background: color-mix(in srgb, var(--brand) 10%, var(--bg-elevated));
}

.ts-reviews-carousel-btn:disabled {
  opacity: 0.35;
  cursor: default;
}

.ts-reviews-carousel-btn svg.lucide {
  width: 1rem;
  height: 1rem;
}

.ts-reviews-track {
  --ts-review-gap: 0.85rem;
  display: flex;
  gap: var(--ts-review-gap);
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  scroll-behavior: smooth;
  scrollbar-width: none;
}

.ts-reviews-track::-webkit-scrollbar {
  display: none;
}

.ts-review-item {
  flex: 0 0 calc((100% - (2 * var(--ts-review-gap))) / 3);
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  min-width: 0;
  height: 12.5rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.95rem;
  background: var(--bg-elevated);
  scroll-snap-align: start;
  transition: border-color 0.2s ease;
}

.ts-review-item:hover {
  border-color: color-mix(in srgb, var(--lane-accent) 35%, var(--border-soft));
}

.ts-review-item-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.ts-review-item .ts-stars {
  font-size: 1rem;
}

.ts-review-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}

.ts-review-action-btn {
  --action: var(--ts-accent-warm);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  padding: 0;
  border: 1px solid color-mix(in srgb, var(--action) 30%, var(--border-soft));
  border-radius: 0.5rem;
  background: color-mix(in srgb, var(--action) 8%, transparent);
  color: var(--action);
  cursor: pointer;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.ts-review-action-delete {
  --action: var(--danger);
}

.ts-review-action-btn:hover,
.ts-review-action-btn:focus-visible {
  border-color: color-mix(in srgb, var(--action) 55%, var(--border-soft));
  background: color-mix(in srgb, var(--action) 16%, transparent);
}

.ts-review-action-btn svg.lucide {
  width: 0.8rem;
  height: 0.8rem;
}

.ts-review-body {
  flex: 1 1 auto;
  margin: 0;
  min-height: 0;
  color: var(--text-soft);
  font-size: var(--fs-small);
  line-height: 1.55;
  white-space: pre-line;
  overflow-wrap: anywhere;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.ts-review-item-foot {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding-top: 0.6rem;
  border-top: 1px solid var(--border-soft);
  min-width: 0;
}

.ts-review-avatar {
  width: 2.2rem;
  height: 2.2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border-radius: 0.7rem;
  font-size: 0.9rem;
  font-weight: var(--fw-bold);
  line-height: 1;
  user-select: none;
}

.ts-review-item-info {
  display: grid;
  gap: 0.1rem;
  min-width: 0;
}

.ts-review-author {
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
  line-height: 1.25;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ts-review-date {
  color: var(--text-soft);
  font-size: 0.68rem;
  line-height: 1.2;
}

/* Modal */
.modal { z-index: 2000 !important; }
.modal-backdrop { z-index: 1990 !important; }

.report-review-dialog {
  --rr-gap: clamp(0.75rem, 4vh, 2.5rem);
  width: calc(100% - 1.5rem);
  max-width: 34rem;
  min-height: calc(100% - var(--rr-gap) * 2);
  margin: var(--rr-gap) auto;
}

.delete-review-dialog {
  max-width: 28rem;
}

.report-review-modal {
  background: var(--bg-card-strong);
  border: 1px solid var(--border-soft);
  border-radius: 1.35rem;
  color: var(--text-main);
  box-shadow: var(--shadow-soft);
  backdrop-filter: blur(18px);
  overflow: hidden;
}

body.theme-light .report-review-modal {
  box-shadow:
    0 18px 48px rgba(28, 26, 34, 0.12),
    0 2px 8px rgba(28, 26, 34, 0.04);
}

.report-review-modal form {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.report-review-modal .modal-header {
  align-items: flex-start;
  gap: 0.85rem;
  padding: 1.1rem 1.35rem 0.9rem;
  border-bottom: 1px solid var(--border-soft);
  background: transparent;
}

.report-review-heading {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  min-width: 0;
  flex: 1;
}

.report-review-icon {
  width: 2.4rem;
  height: 2.4rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border-radius: 0.85rem;
  border: 1px solid color-mix(in srgb, var(--danger) 28%, var(--border-soft));
  background: color-mix(in srgb, var(--danger) 12%, transparent);
  color: var(--danger);
}

.report-review-icon svg.lucide,
.report-review-icon svg.lucide *,
body.theme-light .report-review-icon svg.lucide,
body.theme-light .report-review-icon svg.lucide * {
  color: var(--danger) !important;
  stroke: var(--danger) !important;
}

.report-review-icon--brand {
  border-color: color-mix(in srgb, var(--brand-text) 35%, var(--border-soft));
  background: color-mix(in srgb, var(--brand-text) 10%, transparent);
  color: var(--brand-text);
}

.report-review-icon--brand svg.lucide,
.report-review-icon--brand svg.lucide *,
body.theme-light .report-review-icon--brand svg.lucide,
body.theme-light .report-review-icon--brand svg.lucide * {
  color: var(--brand-text) !important;
  stroke: var(--brand-text) !important;
}

#reviewConfirmModal .ts-review-guidelines {
  margin: 0 0 1rem;
  font-size: var(--fs-small);
}

#reviewConfirmModal .consent-check {
  margin: 0;
}

.report-review-modal .consent-check {
  align-items: flex-start;
  gap: 0.6rem;
  margin: 0;
  padding: 0.6rem 0.8rem;
  border: 1px solid color-mix(in srgb, var(--brand) 22%, var(--border-soft));
  border-radius: 0.8rem;
  background: color-mix(in srgb, var(--brand) 6%, transparent);
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.report-review-modal .consent-check:has(.form-check-input:checked) {
  border-color: color-mix(in srgb, var(--brand) 45%, var(--border-soft));
  background: color-mix(in srgb, var(--brand) 10%, transparent);
}

.report-review-modal .consent-check .form-check-input {
  float: none;
  flex: 0 0 auto;
  margin: 0.15rem 0 0;
  border-color: color-mix(in srgb, var(--brand) 45%, var(--border-soft));
  background-color: transparent;
  box-shadow: none;
  cursor: pointer;
}

.report-review-modal .consent-check .form-check-input:checked {
  border-color: var(--brand);
  background-color: var(--brand);
}

.report-review-modal .consent-check .form-check-input:focus {
  box-shadow: none;
}

.report-review-modal .consent-check .form-check-input:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: 2px;
}

.report-review-modal .consent-check .form-check-label {
  color: var(--text-main);
  font-size: var(--fs-small);
  line-height: var(--lh-small);
  cursor: pointer;
}

.report-review-modal .consent-check a {
  color: var(--brand-text);
  font-weight: var(--fw-medium);
  text-decoration: underline;
  text-decoration-color: color-mix(in srgb, currentColor 40%, transparent);
  text-decoration-thickness: 1px;
  text-underline-offset: 0.2em;
  transition: color 0.2s ease, text-decoration-color 0.2s ease;
}

.report-review-modal .consent-check a:hover,
.report-review-modal .consent-check a:focus-visible {
  color: var(--brand-2);
  text-decoration-color: currentColor;
}

.report-review-heading-text {
  min-width: 0;
}

.report-review-modal .modal-title {
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-h4);
  font-weight: var(--fw-bold);
  letter-spacing: var(--ls-heading);
  line-height: var(--lh-heading);
}

.report-review-lead {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: var(--fs-caption);
  line-height: var(--lh-small);
}

.report-review-modal .btn-close {
  flex: 0 0 auto;
  margin: 0;
  box-shadow: none;
  opacity: 0.65;
  filter: invert(1) grayscale(1) brightness(2);
}

body.theme-light .report-review-modal .btn-close {
  filter: none;
}

.report-review-modal .btn-close:hover,
.report-review-modal .btn-close:focus-visible {
  opacity: 1;
  box-shadow: none;
  outline: 2px solid color-mix(in srgb, var(--brand) 45%, transparent);
  outline-offset: 2px;
}

.report-review-modal .modal-body {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding: 1rem 1.35rem;
}

.report-review-quote {
  display: grid;
  gap: 0.2rem;
  padding: 0.7rem 0.9rem;
  border: 1px solid var(--border-soft);
  border-radius: 0.9rem;
}

.report-review-quote-stars {
  color: var(--accent);
  font-size: 0.85rem;
  letter-spacing: 0.06em;
  line-height: 1;
}

.report-review-quote-stars .is-empty {
  color: color-mix(in srgb, var(--accent) 30%, transparent);
}

.report-review-quote-text {
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-small);
  line-height: var(--lh-small);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.report-review-quote-meta {
  color: var(--text-soft);
  font-size: var(--fs-caption);
}

.report-review-field {
  display: grid;
  gap: 0.35rem;
  min-width: 0;
}

.report-review-field-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

.report-review-label {
  display: inline-flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin: 0;
  color: var(--text-main);
  font-size: var(--fs-small);
  font-weight: var(--fw-semibold);
}

.report-review-req {
  color: var(--danger);
}

.report-review-count {
  color: var(--text-soft);
  font-size: var(--fs-caption);
  font-weight: var(--fw-regular);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.report-review-count.is-near {
  color: var(--accent);
}

.report-review-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 0.85rem;
}

.report-review-select {
  position: relative;
}

.report-review-modal .form-control,
.report-review-modal .form-select,
body.theme-light .report-review-modal .form-control,
body.theme-light .report-review-modal .form-select,
body:not(.theme-light) .report-review-modal .form-control,
body:not(.theme-light) .report-review-modal .form-select {
  width: 100%;
  min-height: 2.6rem;
  padding: 0.5rem 0.85rem;
  border: 1px solid var(--border-soft) !important;
  border-radius: var(--radius-control);
  background-color: rgba(255, 255, 255, 0.04) !important;
  background-image: none !important;
  color: var(--text-main) !important;
  font-size: var(--fs-small);
  line-height: var(--lh-small);
  box-shadow: none !important;
  appearance: none;
  -webkit-appearance: none;
  transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
}

body.theme-light .report-review-modal .form-control,
body.theme-light .report-review-modal .form-select {
  border-color: color-mix(in srgb, var(--border-soft) 80%, rgba(73, 124, 191, 0.18)) !important;
  background-color: color-mix(in srgb, var(--auth-mist) 70%, #fff) !important;
}

.report-review-modal .form-control:focus,
.report-review-modal .form-select:focus,
body.theme-light .report-review-modal .form-control:focus,
body.theme-light .report-review-modal .form-select:focus,
body:not(.theme-light) .report-review-modal .form-control:focus,
body:not(.theme-light) .report-review-modal .form-select:focus {
  border-color: color-mix(in srgb, var(--brand) 55%, var(--border-soft)) !important;
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 16%, transparent) !important;
}

body.theme-light .report-review-modal .form-control:focus,
body.theme-light .report-review-modal .form-select:focus {
  background-color: #fff !important;
}

.report-review-modal .form-select {
  padding-right: 2.5rem;
  cursor: pointer;
}

.report-review-modal .form-select:invalid {
  color: var(--text-soft) !important;
}

.report-review-modal .form-select option {
  color: var(--text-main);
  background-color: var(--bg-elevated);
}

.report-review-modal .form-control::placeholder {
  color: var(--text-soft);
  opacity: 0.85;
}

.report-review-modal textarea.form-control {
  min-height: 4.75rem;
  resize: vertical;
}

.report-review-select > svg.lucide,
.report-review-select > i,
body.theme-light .report-review-select > svg.lucide {
  position: absolute;
  top: 50%;
  right: 0.9rem;
  width: 1rem;
  height: 1rem;
  transform: translateY(-50%);
  color: var(--text-soft) !important;
  stroke: currentColor !important;
  pointer-events: none;
}

.report-review-modal .modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.65rem;
  padding: 0.85rem 1.35rem 1.1rem;
  border-top: 1px solid var(--border-soft);
  background: transparent;
}

.report-review-modal .modal-footer .btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  min-width: 7.25rem;
  box-shadow: none;
}

.report-review-modal .modal-footer .btn svg.lucide,
body.theme-light .report-review-modal .modal-footer .btn svg.lucide,
body.theme-light .report-review-modal .modal-footer .btn svg.lucide * {
  width: 0.9rem;
  height: 0.9rem;
  color: currentColor !important;
  stroke: currentColor !important;
}

/* ── Responsive ── */
@media (max-width: 1199.98px) {
  .ts-products-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 991.98px) {
  .ts-board {
    grid-template-columns: 1fr;
  }

  .ts-map-panel {
    min-height: 0;
  }

  .ts-map-wrap {
    flex: none;
    height: 14rem;
  }

  .ts-reviews-top {
    grid-template-columns: 1fr;
  }

  .ts-review-item {
    flex-basis: calc((100% - var(--ts-review-gap)) / 2);
  }
}

@media (max-width: 767.98px) {
  .ts-products-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .ts-toolbar,
  .ts-toolbar:has(.ts-toolbar-clear) {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .ts-toolbar .ts-field--search {
    grid-column: 1 / -1;
  }

  .ts-review-item {
    flex-basis: 100%;
  }
}

@media (max-width: 575.98px) {
  .ts-cats {
    grid-template-columns: 1fr;
  }

  .ts-featured-list {
    grid-template-columns: none;
    grid-auto-flow: column;
    grid-auto-columns: minmax(9.5rem, 62%);
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
    padding-bottom: 0.1rem;
  }

  .ts-featured-list::-webkit-scrollbar {
    display: none;
  }

  .ts-featured-item {
    scroll-snap-align: start;
  }

  .ts-summary-top {
    grid-template-columns: 4.25rem minmax(0, 1fr);
    gap: 0.8rem;
  }

  .ts-summary-tools {
    grid-column: 1 / -1;
  }

  .ts-logo {
    width: 4.25rem;
    height: 4.25rem;
  }

  .ts-stats {
    grid-template-columns: 1fr;
  }

  .ts-lane-head {
    grid-template-columns: 2.55rem minmax(0, 1fr);
  }

  .ts-lane-head .ts-count {
    grid-column: 1 / -1;
    justify-self: start;
  }

  .ts-rating-layout,
  .ts-review-form-grid,
  .report-review-row {
    grid-template-columns: 1fr;
  }

  .report-review-modal .modal-footer {
    flex-direction: column-reverse;
  }

  .report-review-modal .modal-footer .btn {
    width: 100%;
  }
}

@media (max-width: 479.98px) {
  .ts-products-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<?php
  $rating = (float) ($store['rating_promedio'] ?? 0);
  $roundedRating = max(0, min(5, (int) round($rating)));
  $reviewTotal = (int) ($store['total_reviews'] ?? 0);
  $reviewCountLabel = $reviewTotal === 1
      ? '1 reseña'
      : number_format($reviewTotal, 0, ',', '.') . ' reseñas';
  $productTotal = (int) ($store['total_productos'] ?? 0);
  $lastUpdate = (string) ($store['ultima_actualizacion'] ?? '');
  $lastUpdateExact = $lastUpdate !== '' ? date('d/m/Y H:i', strtotime($lastUpdate)) : 'Sin datos';

  $formatFreshness = static function (?string $date): string {
      if ($date === null || trim($date) === '') {
          return 'Sin fecha';
      }
      $ts = strtotime($date);
      if (!$ts) {
          return 'Sin fecha';
      }
      $diff = time() - $ts;
      if ($diff < 3600) {
          return 'Hace minutos';
      }
      if ($diff < 86400) {
          $hours = max(1, (int) floor($diff / 3600));
          return 'Hace ' . $hours . ' h';
      }
      if ($diff < 86400 * 7) {
          $days = max(1, (int) floor($diff / 86400));
          return 'Hace ' . $days . ' d';
      }
      return date('d/m/Y', $ts);
  };

  $normalizeDay = static function (string $day): string {
      return strtr(mb_strtolower(trim($day), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
  };
  $todayName = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'][(int) date('w')];

  $hasContact = !empty($store['tie_contacto']) || !empty($store['tie_telefono']) || !empty($store['tie_email']) || !empty($store['tie_url']);
  $horariosParsed = parse_horarios($store['tie_horarios'] ?? null);
  $siteHost = !empty($store['tie_url']) ? (string) (parse_url((string) $store['tie_url'], PHP_URL_HOST) ?: $store['tie_url']) : '';
?>

<div class="site-bg" aria-hidden="true">
  <span class="bg-orb orb-1"></span>
  <span class="bg-orb orb-2"></span>
  <span class="bg-orb orb-3"></span>
  <span class="bg-grid"></span>
</div>

<section class="ts-page" aria-labelledby="ts-store-title">
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
          <a href="index.php#tiendas" class="pd-crumb-link">Tiendas</a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <span class="pd-crumb-current"><?= h($store['tie_nombre']) ?></span>
        </li>
      </ol>
    </nav>

    <div class="ts-board">
      <article class="ts-panel ts-summary">
        <div class="ts-summary-top">
          <div class="ts-logo<?= empty($store['tie_logo']) ? ' is-initials' : '' ?>">
            <?php if (!empty($store['tie_logo'])): ?>
              <img src="<?= h($store['tie_logo']) ?>" alt="<?= h($store['tie_nombre']) ?>">
            <?php else: ?>
              <?= h(mb_strtoupper(mb_substr((string) $store['tie_nombre'], 0, 2))) ?>
            <?php endif; ?>
          </div>
          <div class="ts-identity">
            <p class="ts-kicker">Tienda</p>
            <h1 id="ts-store-title" class="ts-title"><?= h($store['tie_nombre']) ?></h1>
            <div class="ts-rating-inline">
              <span class="ts-stars" aria-hidden="true"><span class="is-filled"><?= str_repeat('★', $roundedRating) ?></span><span class="is-empty"><?= str_repeat('★', 5 - $roundedRating) ?></span></span>
              <strong><?= number_format($rating, 1, ',', '.') ?></strong>
              <span class="ts-dot" aria-hidden="true">·</span>
              <a href="#reviews"><?= h($reviewCountLabel) ?></a>
            </div>
          </div>
          <div class="ts-summary-tools">
            <?php if (!empty($store['tie_url'])): ?>
              <a href="<?= h($store['tie_url']) ?>" target="_blank" rel="noopener noreferrer" class="ts-link-chip">
                Ir al sitio <?= lucide_icon('arrow-up-right', '', 13) ?>
              </a>
            <?php endif; ?>
          </div>
        </div>

        <div class="ts-about">
          <?php if (!empty($store['tie_descripcion'])): ?>
            <p class="ts-lead"><?= h($store['tie_descripcion']) ?></p>
            <p class="ts-lead-source">Descripción publicada por la tienda; Caacuprecio no la verifica.</p>
          <?php else: ?>
            <p class="ts-lead">Catálogo de productos de esta tienda, tomado de su sitio web.</p>
          <?php endif; ?>

          <?php if ($categories): ?>
            <div class="ts-cats">
              <p class="ts-overline">Categorías</p>
              <div class="ts-cat-list">
                <?php foreach ($categories as $catIndex => $category): ?>
                  <span class="ts-chip" data-tone="<?= ($catIndex % 6) + 1 ?>">
                    <?= category_lucide_icon((string) $category['cat_nombre'], '', 13) ?>
                    <span class="ts-chip-name"><?= h($category['cat_nombre']) ?></span>
                    <span class="ts-chip-count"><?= (int) $category['total'] ?></span>
                  </span>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <dl class="ts-stats">
          <div class="ts-stat">
            <span class="ts-stat-icon" aria-hidden="true"><?= lucide_icon('package', '', 16) ?></span>
            <div>
              <dt>Productos</dt>
              <dd><?= number_format($productTotal, 0, ',', '.') ?></dd>
            </div>
          </div>
          <div class="ts-stat" data-tone="warm">
            <span class="ts-stat-icon" aria-hidden="true"><?= lucide_icon('star', '', 16) ?></span>
            <div>
              <dt>Calificación</dt>
              <dd><?= number_format($rating, 1, ',', '.') ?> <small>/ 5</small></dd>
            </div>
          </div>
          <div class="ts-stat" data-tone="sage">
            <span class="ts-stat-icon" aria-hidden="true"><?= lucide_icon('refresh-cw', '', 16) ?></span>
            <div>
              <dt>Actualizado</dt>
              <dd title="<?= h($lastUpdateExact) ?>"><?= h($lastUpdate !== '' ? $formatFreshness($lastUpdate) : 'Sin datos') ?></dd>
            </div>
          </div>
        </dl>

        <?php if ($featuredProducts): ?>
          <section class="ts-featured" aria-labelledby="ts-featured-title">
            <div class="ts-featured-head">
              <p id="ts-featured-title" class="ts-overline"><?= $featuredAreDeals ? 'Ofertas destacadas' : 'Recién actualizados' ?></p>
              <a href="#productos" class="ts-featured-more">Ver catálogo <?= lucide_icon('arrow-right', '', 13) ?></a>
            </div>
            <div class="ts-featured-list">
              <?php foreach ($featuredProducts as $featured): ?>
                <?php
                  $featuredName = (string) ($featured['pro_nombre'] ?? 'Producto');
                  $featuredImg = image_url($featured['pro_imagen'] ?? null, $featuredName);
                  $featuredDiscount = (int) ($featured['descuento'] ?? 0);
                ?>
                <a class="ts-featured-item" href="producto.php?id=<?= (int) $featured['idproductos'] ?>" title="<?= h($featuredName) ?>">
                  <span class="ts-featured-media">
                    <?php if ($featuredImg !== ''): ?>
                      <img
                        src="<?= h($featuredImg) ?>"
                        alt=""
                        loading="lazy"
                        onerror="this.onerror=null;if(window.applyProductImageFallback){applyProductImageFallback(this);}else{this.style.display='none';}"
                      >
                    <?php else: ?>
                      <?= lucide_icon('image', '', 20) ?>
                    <?php endif; ?>
                    <?php if ($featuredDiscount > 0): ?>
                      <span class="ts-featured-badge">−<?= $featuredDiscount ?>%</span>
                    <?php endif; ?>
                  </span>
                  <span class="ts-featured-copy">
                    <span class="ts-featured-name"><?= h($featuredName) ?></span>
                    <span class="ts-featured-prices">
                      <strong><?= gs_precio($featured['pro_precio']) ?></strong>
                      <?php if ($featuredDiscount > 0 && !empty($featured['pro_precio_anterior'])): ?>
                        <s><?= gs($featured['pro_precio_anterior']) ?></s>
                      <?php endif; ?>
                    </span>
                  </span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      </article>

      <aside class="ts-side" aria-label="Información de la tienda">
        <div class="ts-panel ts-info-panel" data-tone="sage">
          <div class="ts-tablist" role="tablist" aria-label="Información de contacto y horarios">
            <span class="ts-tab-thumb" data-ts-tab-thumb aria-hidden="true"></span>
            <button type="button" class="ts-tab-btn active" data-tab="contacto" role="tab" id="ts-tab-contacto" aria-controls="contacto" aria-selected="true">
              <?= lucide_icon('contact', '', 15) ?> Contacto
            </button>
            <button type="button" class="ts-tab-btn" data-tab="horario" role="tab" id="ts-tab-horario" aria-controls="horario" aria-selected="false" tabindex="-1">
              <?= lucide_icon('clock', '', 15) ?> Horario
            </button>
          </div>

          <div class="ts-tabs-content">
            <div class="ts-tab-pane active" id="contacto" role="tabpanel" aria-labelledby="ts-tab-contacto">
              <?php if ($hasContact): ?>
                <?php if (!empty($store['tie_contacto'])): ?>
                  <div class="ts-contact-row" data-kind="person">
                    <span class="ts-contact-icon" aria-hidden="true"><?= lucide_icon('user-round', '', 16) ?></span>
                    <span class="ts-contact-copy">
                      <span class="ts-contact-label">Contacto</span>
                      <span class="ts-contact-value"><?= h($store['tie_contacto']) ?></span>
                    </span>
                  </div>
                <?php endif; ?>
                <?php if (!empty($store['tie_telefono'])): ?>
                  <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', (string) $store['tie_telefono'])) ?>" class="ts-contact-row" data-kind="phone">
                    <span class="ts-contact-icon" aria-hidden="true"><?= lucide_icon('phone', '', 16) ?></span>
                    <span class="ts-contact-copy">
                      <span class="ts-contact-label">Teléfono</span>
                      <span class="ts-contact-value"><?= h($store['tie_telefono']) ?></span>
                    </span>
                    <span class="ts-contact-go" aria-hidden="true"><?= lucide_icon('arrow-up-right', '', 14) ?></span>
                  </a>
                <?php endif; ?>
                <?php if (!empty($store['tie_email'])): ?>
                  <a href="mailto:<?= h($store['tie_email']) ?>" class="ts-contact-row" data-kind="mail">
                    <span class="ts-contact-icon" aria-hidden="true"><?= lucide_icon('mail', '', 16) ?></span>
                    <span class="ts-contact-copy">
                      <span class="ts-contact-label">Correo</span>
                      <span class="ts-contact-value"><?= h($store['tie_email']) ?></span>
                    </span>
                    <span class="ts-contact-go" aria-hidden="true"><?= lucide_icon('arrow-up-right', '', 14) ?></span>
                  </a>
                <?php endif; ?>
                <?php if (!empty($store['tie_url'])): ?>
                  <a href="<?= h($store['tie_url']) ?>" target="_blank" rel="noopener noreferrer" class="ts-contact-row" data-kind="web">
                    <span class="ts-contact-icon" aria-hidden="true"><?= lucide_icon('globe', '', 16) ?></span>
                    <span class="ts-contact-copy">
                      <span class="ts-contact-label">Sitio web</span>
                      <span class="ts-contact-value"><?= h($siteHost) ?></span>
                    </span>
                    <span class="ts-contact-go" aria-hidden="true"><?= lucide_icon('arrow-up-right', '', 14) ?></span>
                  </a>
                <?php endif; ?>
              <?php else: ?>
                <p class="ts-empty-inline"><?= lucide_icon('info', '', 16) ?> Sin información de contacto.</p>
              <?php endif; ?>
            </div>

            <div class="ts-tab-pane" id="horario" role="tabpanel" aria-labelledby="ts-tab-horario">
              <?php if ($horariosParsed): ?>
                <?php foreach ($horariosParsed as $horario): ?>
                  <?php if (empty($horario['day'])): ?>
                    <div class="ts-hours-row is-note"><?= h($horario['time']) ?></div>
                  <?php else: ?>
                    <?php $isToday = $normalizeDay((string) $horario['day']) === $todayName; ?>
                    <div class="ts-hours-row<?= $isToday ? ' is-today' : '' ?>">
                      <span class="ts-hours-day">
                        <?= h($horario['day']) ?><?= !empty($horario['day_number']) ? ' ' . h($horario['day_number']) : '' ?>
                        <?php if ($isToday): ?><span class="ts-today-badge">Hoy</span><?php endif; ?>
                      </span>
                      <span class="ts-hours-time"><?= h($horario['time']) ?></span>
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="ts-empty-inline"><?= lucide_icon('info', '', 16) ?> Sin información de horarios.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($mapEmbedUrl !== ''): ?>
          <div class="ts-panel ts-map-panel" data-tone="rose">
            <div class="ts-panel-head">
              <h2 class="ts-panel-title">
                <span class="ts-panel-icon" aria-hidden="true"><?= lucide_icon('map-pin', '', 15) ?></span>
                Ubicación
              </h2>
              <a href="<?= h($mapOpenUrl) ?>" target="_blank" rel="noopener noreferrer" class="ts-link-chip">
                Abrir en Maps <?= lucide_icon('arrow-up-right', '', 13) ?>
              </a>
            </div>
            <div class="ts-map-wrap">
              <iframe src="<?= h($mapEmbedUrl) ?>" title="Ubicación de <?= h($store['tie_nombre']) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
            </div>
          </div>
        <?php endif; ?>
      </aside>
    </div>

    <div class="ts-content">

      <section class="ts-panel ts-lane" id="productos" aria-labelledby="ts-products-title">
        <span class="ts-mark" aria-hidden="true"><?= lucide_icon('package', '', 88) ?></span>

        <div class="ts-lane-head">
          <span class="ts-lane-icon" aria-hidden="true"><?= lucide_icon('package', '', 20) ?></span>
          <div>
            <h2 id="ts-products-title" class="ts-lane-title">Productos de <?= h($store['tie_nombre']) ?></h2>
            <p class="ts-lane-caption">
              <?= $hasSearch ? 'Resultados para “' . h($q) . '”.' : 'Explora el catálogo disponible de esta tienda.' ?>
            </p>
          </div>
          <span class="ts-count"><?= number_format($totalProductsFiltered, 0, ',', '.') ?> resultado<?= $totalProductsFiltered === 1 ? '' : 's' ?></span>
        </div>

        <form method="get" action="tienda.php#productos" class="ts-toolbar" role="search">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <label class="ts-field ts-field--search">
            <?= lucide_icon('search', '', 16) ?>
            <input type="search" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Buscar por nombre o marca…" aria-label="Buscar en la tienda" data-search-input>
            <button type="button" class="ts-field-clear" aria-label="Borrar búsqueda" data-search-clear <?= $q === '' ? 'hidden' : '' ?>>
              <?= lucide_icon('x', '', 14) ?>
            </button>
          </label>
          <label class="ts-field ts-field--select">
            <?= lucide_icon('arrow-up-down', '', 16) ?>
            <select class="form-select" name="orden" aria-label="Ordenar productos">
              <?php foreach (active_sort_options() as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?= lucide_icon('chevron-down', '', 16) ?>
          </label>
          <button type="submit" class="btn btn-primary">
            <?= lucide_icon('search', '', 15) ?> Buscar
          </button>
          <?php if ($hasSearch): ?>
            <a class="ts-toolbar-clear" href="<?= h(build_store_page_url($id, ['orden' => $sort === 'recientes' ? '' : $sort])) ?>#productos">
              <?= lucide_icon('x', '', 14) ?> Limpiar
            </a>
          <?php endif; ?>
        </form>

        <?php if ($products): ?>
          <?php
            $tileWashColors = [
              '#8ba4c4',
              '#c9a87a',
              '#c48b8e',
              '#8fad97',
              '#a89bc4',
              '#7eb8b0',
              '#c4a88b',
              '#9bb0c9',
            ];
            mt_srand(crc32('store-tile|' . $id . '|' . $page . '|' . $sort . '|' . $q));
            for ($i = count($tileWashColors) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$tileWashColors[$i], $tileWashColors[$j]] = [$tileWashColors[$j], $tileWashColors[$i]];
            }
            mt_srand();
          ?>
          <div class="ts-products-grid">
            <?php foreach ($products as $index => $product): ?>
              <?php
                $productName = (string) ($product['pro_nombre'] ?? 'Producto');
                $productUrl = 'producto.php?id=' . (int) $product['idproductos'];
                $inStock = ((int) ($product['pro_en_stock'] ?? 0)) === 1;
                $tileWash = $tileWashColors[$index % count($tileWashColors)];
                $catalogNumber = $offset + (int) $index + 1;
                $scrapedAt = (string) ($product['pro_fecha_scraping'] ?? '');
                $scrapedTs = $scrapedAt !== '' ? strtotime($scrapedAt) : false;
                $scrapedExact = $scrapedTs ? date('d/m/Y H:i', $scrapedTs) : 'Sin fecha';
                $cardImg = image_url($product['pro_imagen'] ?? null, $productName);
              ?>
              <article
                class="sr-card clickable-card"
                data-tone="<?= $inStock ? 'stock' : 'wait' ?>"
                style="--tile-wash: <?= h($tileWash) ?>;"
                data-card-href="<?= h($productUrl) ?>"
                role="link"
                tabindex="0"
                aria-label="Ver detalle de <?= h($productName) ?>"
              >
                <a class="sr-card-media" href="<?= h($productUrl) ?>">
                  <?php if ($cardImg !== ''): ?>
                    <img
                      src="<?= h($cardImg) ?>"
                      alt="<?= h($productName) ?>"
                      loading="lazy"
                      onerror="this.onerror=null;if(window.applyProductImageFallback){applyProductImageFallback(this);}else{this.style.display='none';}"
                    >
                  <?php else: ?>
                    <span class="img-fallback" role="img" aria-label="<?= h($productName) ?>">
                      <?= lucide_icon('image', '', 48) ?>
                    </span>
                  <?php endif; ?>
                  <span class="sr-card-index"><?= str_pad((string) $catalogNumber, 2, '0', STR_PAD_LEFT) ?></span>
                  <span class="sr-card-flag <?= $inStock ? 'is-stock' : 'is-wait' ?>"><?= $inStock ? 'Stock' : 'Consultar' ?></span>
                </a>

                <div class="sr-card-body">
                  <div class="sr-card-tags">
                    <span class="sr-card-cat"><?= h($product['cat_nombre'] ?? 'Sin categoría') ?></span>
                    <?php if (!empty($product['pro_marca'])): ?>
                      <span class="sr-card-brand"><?= h($product['pro_marca']) ?></span>
                    <?php endif; ?>
                  </div>

                  <h3 class="sr-card-name">
                    <a href="<?= h($productUrl) ?>"><?= h($productName) ?></a>
                  </h3>

                  <?php if (!empty($product['pro_descripcion'])): ?>
                    <p class="sr-card-desc"><?= h($product['pro_descripcion']) ?></p>
                  <?php endif; ?>

                  <div class="sr-card-priceblock">
                    <div>
                      <p class="sr-card-from">Precio</p>
                      <p class="sr-card-price"><?= gs_precio($product['pro_precio']) ?></p>
                    </div>
                  </div>

                  <div class="sr-card-foot">
                    <span class="sr-card-fresh" title="<?= h($scrapedExact) ?>">
                      <?= lucide_icon('clock', '', 13) ?>
                      <?= h($formatFreshness($scrapedAt)) ?>
                    </span>
                    <a class="sr-card-cta" href="<?= h($productUrl) ?>">
                      Ver detalle
                      <?= lucide_icon('arrow-right', '', 14) ?>
                    </a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="ts-empty">
            <span class="ts-empty-icon" aria-hidden="true"><?= lucide_icon('search-x', '', 22) ?></span>
            <strong>No se encontraron productos</strong>
            <span>Prueba con otro término o cambia el orden.</span>
          </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
          <?php
            $visiblePages = 5;
            $half = (int) floor($visiblePages / 2);
            $startPage = max(1, $page - $half);
            $endPage = min($totalPages, $startPage + $visiblePages - 1);
            if (($endPage - $startPage + 1) < $visiblePages) {
                $startPage = max(1, $endPage - $visiblePages + 1);
            }
            $pagerUrl = static fn (int $target): string => build_store_page_url($id, ['q' => $q, 'orden' => $sort, 'page' => $target]) . '#productos';
          ?>
          <nav class="products-pager" aria-label="Paginación de productos de tienda">
            <p class="products-pager-status">Página <?= number_format($page, 0, ',', '.') ?> de <?= number_format($totalPages, 0, ',', '.') ?></p>
            <div class="products-pager-controls">
              <?php if ($page > 1): ?>
                <a class="products-pager-btn" href="<?= h($pagerUrl(1)) ?>" aria-label="Primera página" title="Ir al inicio"><?= lucide_icon('chevrons-left', '', 16) ?></a>
                <a class="products-pager-btn" href="<?= h($pagerUrl($page - 1)) ?>" aria-label="Página anterior" title="Página anterior"><?= lucide_icon('chevron-left', '', 16) ?></a>
              <?php else: ?>
                <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevrons-left', '', 16) ?></span>
                <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevron-left', '', 16) ?></span>
              <?php endif; ?>

              <div class="products-pager-pages">
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                  <a
                    class="products-pager-num<?= $i === $page ? ' is-active' : '' ?>"
                    href="<?= h($pagerUrl($i)) ?>"
                    <?= $i === $page ? 'aria-current="page"' : '' ?>
                  ><?= $i ?></a>
                <?php endfor; ?>
              </div>

              <?php if ($page < $totalPages): ?>
                <a class="products-pager-btn" href="<?= h($pagerUrl($page + 1)) ?>" aria-label="Página siguiente" title="Página siguiente"><?= lucide_icon('chevron-right', '', 16) ?></a>
                <a class="products-pager-btn" href="<?= h($pagerUrl($totalPages)) ?>" aria-label="Última página" title="Ir al final"><?= lucide_icon('chevrons-right', '', 16) ?></a>
              <?php else: ?>
                <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevron-right', '', 16) ?></span>
                <span class="products-pager-btn is-disabled" aria-hidden="true"><?= lucide_icon('chevrons-right', '', 16) ?></span>
              <?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>
      </section>

      <section class="ts-panel ts-lane" id="reviews" data-tone="warm" aria-labelledby="ts-reviews-title">
        <span class="ts-mark" aria-hidden="true"><?= lucide_icon('message-square-quote', '', 88) ?></span>

        <div class="ts-lane-head">
          <span class="ts-lane-icon" aria-hidden="true"><?= lucide_icon('message-square-quote', '', 20) ?></span>
          <div>
            <h2 id="ts-reviews-title" class="ts-lane-title">Reseñas</h2>
            <p class="ts-lane-caption">Lo que dicen los usuarios sobre esta tienda.</p>
          </div>
          <span class="ts-count"><?= h($reviewCountLabel) ?></span>
        </div>

        <?php
          $reviewToasts = [];
          if ($reviewSuccess) {
              $reviewToasts[] = ['success', 'circle-check', 'Tu reseña se guardó correctamente.'];
          }
          if ($reviewDeleted) {
              $reviewToasts[] = ['success', 'circle-check', 'La reseña fue eliminada correctamente.'];
          }
          if ($reviewReported) {
              $reviewToasts[] = ['warning', 'flag', 'La reseña fue reportada correctamente.'];
          }
          if ($reviewError !== '') {
              $reviewToasts[] = ['danger', 'circle-alert', $reviewError];
          }
          if ($reportError !== '') {
              $reviewToasts[] = ['danger', 'circle-alert', $reportError];
          }
        ?>

        <div class="ts-reviews-top">
          <div class="ts-subcard">
            <div class="ts-subcard-head">
              <div class="ts-subcard-head-text">
                <h3 class="ts-subcard-title">Calificación general</h3>
                <p class="ts-subcard-subtitle">Promedio y distribución de las reseñas.</p>
              </div>
            </div>
            <div class="ts-rating-layout">
              <div class="ts-rating-score">
                <p class="ts-rating-score-value"><?= number_format($rating, 1, ',', '.') ?></p>
                <div class="ts-rating-score-meta">
                  <span class="ts-stars" aria-label="Promedio <?= number_format($rating, 1, ',', '.') ?> de 5">
                    <span class="is-filled"><?= str_repeat('★', $roundedRating) ?></span><span class="is-empty"><?= str_repeat('★', 5 - $roundedRating) ?></span>
                  </span>
                  <p class="ts-rating-score-count"><?= h($reviewCountLabel) ?></p>
                </div>
              </div>
              <div class="ts-rating-bars">
                <?php foreach ($ratingBarLabels as $barScore => $barLabel): ?>
                  <?php
                    $barCount = (int) ($ratingBreakdown[$barScore] ?? 0);
                    $barPercent = ($barCount > 0 && $ratingBreakdownMax > 0)
                        ? max(4, (int) round(($barCount / $ratingBreakdownMax) * 100))
                        : 0;
                  ?>
                  <div class="ts-rating-bar">
                    <span class="ts-rating-bar-label" aria-hidden="true">
                      <span class="is-filled"><?= str_repeat('★', (int) $barScore) ?></span><span class="is-empty"><?= str_repeat('★', 5 - (int) $barScore) ?></span>
                    </span>
                    <div
                      class="ts-rating-bar-track"
                      role="meter"
                      aria-label="<?= $barScore === 1 ? 'Una estrella' : h($barLabel) . ' estrellas' ?>: <?= number_format($barCount, 0, ',', '.') ?>"
                      aria-valuemin="0"
                      aria-valuemax="<?= max(1, (int) $ratingBreakdownMax) ?>"
                      aria-valuenow="<?= $barCount ?>"
                    >
                      <span class="ts-rating-bar-fill" style="width: <?= $barPercent ?>%"></span>
                    </div>
                    <span class="ts-rating-bar-count"><?= number_format($barCount, 0, ',', '.') ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="ts-subcard">
            <div class="ts-subcard-head">
              <div class="ts-subcard-head-text">
                <h3 class="ts-subcard-title">Escribir una reseña</h3>
                <p class="ts-subcard-subtitle">Cuenta tu experiencia y ayuda a otros compradores.</p>
              </div>
            </div>
            <form method="post" class="ts-review-form" id="ts-review-form" novalidate>
              <input type="hidden" name="action" value="save_review">
              <input type="hidden" name="review_token" value="<?= h($reviewCsrfToken) ?>">
              <input type="hidden" name="rev_started" value="<?= time() ?>">
              <div style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                <label for="rev_website">No completar este campo</label>
                <input type="text" id="rev_website" name="rev_website" tabindex="-1" autocomplete="off">
              </div>
              <div class="ts-review-form-grid">
                <div class="ts-review-field">
                  <label class="form-label" for="rev_puntaje">Tu puntuación <span class="ts-req" aria-hidden="true">*</span></label>
                  <div class="ts-review-select">
                    <select id="rev_puntaje" name="rev_puntaje" class="form-select" required>
                      <option value="" <?= (string) ($_POST['rev_puntaje'] ?? '') === '' ? 'selected' : '' ?> disabled>Selecciona una puntuación</option>
                      <?php for ($i = 5; $i >= 1; $i--): ?>
                        <option value="<?= $i ?>" <?= (string) ($_POST['rev_puntaje'] ?? '') === (string) $i ? 'selected' : '' ?>>
                          <?= str_repeat('★', $i) ?> · <?= $i ?> <?= $i === 1 ? 'estrella' : 'estrellas' ?>
                        </option>
                      <?php endfor; ?>
                    </select>
                    <?= lucide_icon('chevron-down', '', 16) ?>
                  </div>
                </div>
                <div class="ts-review-field">
                  <label class="form-label" for="rev_nombre">Tu nombre <span class="ts-req" aria-hidden="true">*</span></label>
                  <input
                    type="text"
                    id="rev_nombre"
                    name="rev_nombre"
                    class="form-control"
                    maxlength="120"
                    placeholder="Ej. María G."
                    value="<?= h($_POST['rev_nombre'] ?? '') ?>"
                    required
                    autocomplete="name"
                  >
                </div>
              </div>
              <div class="ts-review-field ts-review-field--grow">
                <div class="ts-review-field-label">
                  <label class="form-label" for="rev_comentario">Comentario <span class="ts-req" aria-hidden="true">*</span></label>
                  <span class="ts-review-char-count" id="rev_comentario_count" aria-live="polite">0 / 1000</span>
                </div>
                <textarea
                  id="rev_comentario"
                  name="rev_comentario"
                  class="form-control"
                  rows="3"
                  maxlength="1000"
                  placeholder="Escribe tu experiencia con esta tienda…"
                  required
                ><?= h($_POST['rev_comentario'] ?? '') ?></textarea>
              </div>
              <?= turnstile_widget() ?>
              <button type="submit" class="btn btn-primary">
                <?= lucide_icon('send', '', 15) ?>
                Enviar reseña
              </button>
            </form>
          </div>
        </div>

        <div class="ts-reviews-carousel" id="store-reviews-list-col">
          <div class="ts-reviews-carousel-head">
            <div class="ts-subcard-head ts-subcard-head--flush">
              <span class="ts-subcard-head-icon" aria-hidden="true"><?= lucide_icon('messages-square', '', 18) ?></span>
              <div class="ts-subcard-head-text">
                <h3 class="ts-subcard-title">Reseñas recientes</h3>
                <p class="ts-subcard-subtitle">Opiniones de usuarios del sitio. Caacuprecio no verifica que hayan comprado en la tienda.</p>
              </div>
            </div>
            <?php if (count($reviews) > 1): ?>
              <div class="ts-reviews-carousel-nav">
                <button type="button" class="ts-reviews-carousel-btn" id="reviews-carousel-prev" aria-label="Reseñas anteriores">
                  <?= lucide_icon('chevron-left', '', 16) ?>
                </button>
                <button type="button" class="ts-reviews-carousel-btn" id="reviews-carousel-next" aria-label="Reseñas siguientes">
                  <?= lucide_icon('chevron-right', '', 16) ?>
                </button>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($reviews): ?>
            <div class="ts-reviews-track" id="reviews-track" tabindex="0">
              <?php
              $avatarPalettes = [
                ['bg' => '#dbe6f5', 'color' => '#32498c'],
                ['bg' => '#f6e7cf', 'color' => '#93672a'],
                ['bg' => '#dcebe0', 'color' => '#467356'],
                ['bg' => '#f3dfe0', 'color' => '#9c4f54'],
                ['bg' => '#e6e1f2', 'color' => '#6a5a98'],
                ['bg' => '#d8ece9', 'color' => '#2f7d73'],
              ];
              ?>
              <?php foreach ($reviews as $review): ?>
                <?php
                  $palette = $avatarPalettes[abs(crc32((string) ($review['rev_nombre'] ?? ''))) % count($avatarPalettes)];
                  $reviewScore = max(0, min(5, (int) $review['rev_puntaje']));
                  $reviewDate = date('d/m/Y', strtotime((string) $review['rev_fecha']));
                ?>
                <article class="ts-review-item" data-review-item>
                  <div class="ts-review-item-head">
                    <span class="ts-stars" aria-label="Calificación: <?= $reviewScore ?> de 5">
                      <span class="is-filled"><?= str_repeat('★', $reviewScore) ?></span><span class="is-empty"><?= str_repeat('★', 5 - $reviewScore) ?></span>
                    </span>
                    <div class="ts-review-actions">
                      <button
                        type="button"
                        class="ts-review-action-btn ts-review-action-report"
                        data-bs-toggle="modal"
                        data-bs-target="#reportReviewModal<?= (int) $review['idreview'] ?>"
                        title="Reportar"
                        aria-label="Reportar reseña"
                      >
                        <?= lucide_icon('flag', '', 12) ?>
                      </button>
                      <?php if ($isAdmin): ?>
                        <button
                          type="button"
                          class="ts-review-action-btn ts-review-action-delete"
                          data-bs-toggle="modal"
                          data-bs-target="#deleteReviewModal<?= (int) $review['idreview'] ?>"
                          title="Eliminar"
                          aria-label="Eliminar reseña"
                        >
                          <?= lucide_icon('trash-2', '', 12) ?>
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>
                  <p class="ts-review-body"><?= h($review['rev_comentario']) ?></p>
                  <div class="ts-review-item-foot">
                    <span class="ts-review-avatar" style="background-color: <?= $palette['bg'] ?>; color: <?= $palette['color'] ?>;" aria-hidden="true">
                      <?= h(mb_strtoupper(mb_substr(trim((string) ($review['rev_nombre'] ?? '?')), 0, 1, 'UTF-8'), 'UTF-8')) ?>
                    </span>
                    <div class="ts-review-item-info">
                      <span class="ts-review-author"><?= h($review['rev_nombre']) ?></span>
                      <span class="ts-review-date"><?= h($reviewDate) ?></span>
                    </div>
                  </div>
                </article>

                <?php ob_start(); ?>
                <div class="modal fade" id="reportReviewModal<?= (int) $review['idreview'] ?>" tabindex="-1" aria-labelledby="reportReviewTitle<?= (int) $review['idreview'] ?>" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered report-review-dialog">
                    <div class="modal-content report-review-modal">
                      <form method="post">
                        <div class="modal-header">
                          <div class="report-review-heading">
                            <span class="report-review-icon" aria-hidden="true"><?= lucide_icon('flag', '', 18) ?></span>
                            <div class="report-review-heading-text">
                              <h5 class="modal-title" id="reportReviewTitle<?= (int) $review['idreview'] ?>">Reportar reseña</h5>
                              <p class="report-review-lead">Indica el motivo para que podamos revisar esta reseña.</p>
                            </div>
                          </div>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="action" value="report_review">
                          <input type="hidden" name="review_id" value="<?= (int) $review['idreview'] ?>">

                          <div class="report-review-quote">
                            <span class="report-review-quote-stars" aria-label="Calificación: <?= $reviewScore ?> de 5">
                              <span class="is-filled"><?= str_repeat('★', $reviewScore) ?></span><span class="is-empty"><?= str_repeat('★', 5 - $reviewScore) ?></span>
                            </span>
                            <p class="report-review-quote-text"><?= h($review['rev_comentario']) ?></p>
                            <span class="report-review-quote-meta"><?= h($review['rev_nombre']) ?> · <?= h($reviewDate) ?></span>
                          </div>

                          <div class="report-review-field">
                            <label class="report-review-label" for="repMotivo<?= (int) $review['idreview'] ?>">
                              Motivo <span class="report-review-req" aria-hidden="true">*</span>
                            </label>
                            <div class="report-review-select">
                              <select name="rep_motivo" id="repMotivo<?= (int) $review['idreview'] ?>" class="form-select" required>
                                <option value="" selected disabled>Selecciona un motivo</option>
                                <option value="spam">Spam</option>
                                <option value="ofensivo">Contenido ofensivo</option>
                                <option value="informacion_falsa">Información falsa</option>
                                <option value="lenguaje_inapropiado">Lenguaje inapropiado</option>
                                <option value="otro">Otro motivo</option>
                              </select>
                              <?= lucide_icon('chevron-down', '', 16) ?>
                            </div>
                          </div>

                          <div class="report-review-row">
                            <div class="report-review-field">
                              <label class="report-review-label" for="repNombre<?= (int) $review['idreview'] ?>">
                                Tu nombre
                              </label>
                              <input type="text" name="rep_nombre" id="repNombre<?= (int) $review['idreview'] ?>" class="form-control" maxlength="120" placeholder="Nombre y apellido" autocomplete="name">
                            </div>
                            <div class="report-review-field">
                              <label class="report-review-label" for="repEmail<?= (int) $review['idreview'] ?>">
                                Correo electrónico
                              </label>
                              <input type="email" name="rep_email" id="repEmail<?= (int) $review['idreview'] ?>" class="form-control" maxlength="150" placeholder="correo@ejemplo.com" autocomplete="email">
                            </div>
                          </div>

                          <div class="report-review-field">
                            <div class="report-review-field-head">
                              <label class="report-review-label" for="repDetalle<?= (int) $review['idreview'] ?>">
                                Detalle
                              </label>
                              <span class="report-review-count" data-report-count>0 / 1000</span>
                            </div>
                            <textarea
                              name="rep_detalle"
                              id="repDetalle<?= (int) $review['idreview'] ?>"
                              class="form-control"
                              rows="3"
                              maxlength="1000"
                              placeholder="Describe qué ocurre con esta reseña"
                              data-report-detail
                            ></textarea>
                          </div>
                          <?= consent_field('repConsent' . (int) $review['idreview']) ?>
                          <?= turnstile_widget() ?>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                          <button type="submit" class="btn btn-danger">
                            <?= lucide_icon('send', '', 15) ?>
                            Enviar reporte
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>

                <?php if ($isAdmin): ?>
                <div class="modal fade" id="deleteReviewModal<?= (int) $review['idreview'] ?>" tabindex="-1" aria-labelledby="deleteReviewTitle<?= (int) $review['idreview'] ?>" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered report-review-dialog delete-review-dialog">
                    <div class="modal-content report-review-modal">
                      <form method="post">
                        <div class="modal-header">
                          <div class="report-review-heading">
                            <span class="report-review-icon" aria-hidden="true"><?= lucide_icon('trash-2', '', 18) ?></span>
                            <div class="report-review-heading-text">
                              <h5 class="modal-title" id="deleteReviewTitle<?= (int) $review['idreview'] ?>">Eliminar reseña</h5>
                              <p class="report-review-lead">Esta acción no se puede deshacer.</p>
                            </div>
                          </div>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="action" value="delete_review">
                          <input type="hidden" name="review_id" value="<?= (int) $review['idreview'] ?>">

                          <div class="report-review-quote">
                            <span class="report-review-quote-stars" aria-label="Calificación: <?= $reviewScore ?> de 5">
                              <span class="is-filled"><?= str_repeat('★', $reviewScore) ?></span><span class="is-empty"><?= str_repeat('★', 5 - $reviewScore) ?></span>
                            </span>
                            <p class="report-review-quote-text"><?= h($review['rev_comentario']) ?></p>
                            <span class="report-review-quote-meta"><?= h($review['rev_nombre']) ?> · <?= h($reviewDate) ?></span>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                          <button type="submit" class="btn btn-danger">
                            <?= lucide_icon('trash-2', '', 15) ?>
                            Eliminar
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
                <?php $reviewModals[] = ob_get_clean(); ?>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="ts-empty">
              <span class="ts-empty-icon" aria-hidden="true"><?= lucide_icon('message-square-dashed', '', 22) ?></span>
              <strong>Todavía no hay reseñas</strong>
              <span>Sé el primero en contar tu experiencia con esta tienda.</span>
            </div>
          <?php endif; ?>
        </div>
      </section>

    </div>

  </div>
</section>

<?php if ($reviewModals): ?>
  <?= implode("\n", $reviewModals) ?>
<?php endif; ?>

<div class="modal fade" id="reviewConfirmModal" tabindex="-1" aria-labelledby="reviewConfirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered report-review-dialog">
    <div class="modal-content report-review-modal">
      <div class="modal-header">
        <div class="report-review-heading">
          <span class="report-review-icon report-review-icon--brand" aria-hidden="true"><?= lucide_icon('shield-check', '', 18) ?></span>
          <div class="report-review-heading-text">
            <h5 class="modal-title" id="reviewConfirmTitle">Antes de publicar tu reseña</h5>
            <p class="report-review-lead">Revisa las condiciones y confirma para enviarla.</p>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="ts-review-guidelines">Publica solo tu experiencia real con esta tienda. Tu nombre y comentario serán públicos; no incluyas datos personales. No se permiten reseñas pagadas, falsas ni escritas por la propia tienda o su competencia: las eliminamos al detectarlas.</p>
        <?= str_replace('<input ', '<input form="ts-review-form" ', consent_field('rev_consent')) ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary" form="ts-review-form" id="reviewConfirmSubmit" data-review-confirm disabled>
          <?= lucide_icon('send', '', 15) ?>
          Publicar reseña
        </button>
      </div>
    </div>
  </div>
</div>

<?php if ($reviewToasts): ?>
  <div class="ts-toast-stack">
    <?php foreach ($reviewToasts as [$toastType, $toastIcon, $toastText]): ?>
      <div
        class="ts-toast ts-toast--<?= h($toastType) ?>"
        role="<?= $toastType === 'danger' ? 'alert' : 'status' ?>"
        aria-live="<?= $toastType === 'danger' ? 'assertive' : 'polite' ?>"
        aria-atomic="true"
        data-ts-toast
        data-ts-toast-delay="<?= $toastType === 'danger' ? 7000 : 4500 ?>"
      >
        <span class="ts-toast-icon" aria-hidden="true"><?= lucide_icon($toastIcon, '', 18) ?></span>
        <span class="ts-toast-text"><?= h($toastText) ?></span>
        <button type="button" class="ts-toast-close" aria-label="Cerrar" data-ts-toast-close><?= lucide_icon('x', '', 15) ?></button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-ts-toast]').forEach(function (toast) {
    const delay = parseInt(toast.dataset.tsToastDelay, 10) || 4500;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let timer = null;

    const remove = function () {
      const stack = toast.parentElement;
      toast.remove();
      if (stack && !stack.children.length) stack.remove();
    };
    const dismiss = function () {
      window.clearTimeout(timer);
      if (toast.classList.contains('is-leaving')) return;
      toast.classList.add('is-leaving');
      if (reducedMotion) {
        remove();
      } else {
        toast.addEventListener('transitionend', remove, { once: true });
      }
    };
    const schedule = function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(dismiss, delay);
    };

    toast.querySelector('[data-ts-toast-close]')?.addEventListener('click', dismiss);
    toast.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
    toast.addEventListener('mouseleave', schedule);

    requestAnimationFrame(function () {
      requestAnimationFrame(function () { toast.classList.add('is-visible'); });
    });
    schedule();
  });

  const tabBtns = Array.from(document.querySelectorAll('.ts-tab-btn'));
  const tabPanes = document.querySelectorAll('.ts-tab-pane');
  const tabList = document.querySelector('.ts-tablist');
  const tabThumb = document.querySelector('[data-ts-tab-thumb]');

  const moveThumb = (btn) => {
    if (!tabList || !tabThumb || !btn) return;
    const listRect = tabList.getBoundingClientRect();
    const btnRect = btn.getBoundingClientRect();
    tabThumb.style.width = btnRect.width + 'px';
    tabThumb.style.height = btnRect.height + 'px';
    tabThumb.style.left = '0';
    tabThumb.style.top = (btnRect.top - listRect.top) + 'px';
    tabThumb.style.transform = 'translate3d(' + (btnRect.left - listRect.left) + 'px, 0, 0)';
  };

  const activate = (btn, focus) => {
    tabBtns.forEach((b) => {
      const isActive = b === btn;
      b.classList.toggle('active', isActive);
      b.setAttribute('aria-selected', isActive ? 'true' : 'false');
      b.tabIndex = isActive ? 0 : -1;
    });
    tabPanes.forEach((p) => p.classList.toggle('active', p.id === btn.dataset.tab));
    moveThumb(btn);
    if (focus) btn.focus();
  };

  moveThumb(tabBtns.find((b) => b.classList.contains('active')) || tabBtns[0]);
  window.addEventListener('resize', () => {
    moveThumb(tabBtns.find((b) => b.classList.contains('active')));
  });

  tabBtns.forEach((btn, index) => {
    btn.addEventListener('click', () => activate(btn, false));
    btn.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
      event.preventDefault();
      const offset = event.key === 'ArrowRight' ? 1 : -1;
      activate(tabBtns[(index + offset + tabBtns.length) % tabBtns.length], true);
    });
  });
});

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

(function () {
  const input = document.querySelector('[data-search-input]');
  const clear = document.querySelector('[data-search-clear]');
  if (!input || !clear) return;

  const sync = () => { clear.hidden = input.value === ''; };

  input.addEventListener('input', sync);
  clear.addEventListener('click', (event) => {
    event.preventDefault();
    input.value = '';
    sync();
    input.focus();
  });
})();

(function () {
  const field = document.getElementById('rev_comentario');
  const count = document.getElementById('rev_comentario_count');
  if (!field || !count) return;

  const update = () => {
    const length = field.value.length;
    count.textContent = length + ' / 1000';
    count.classList.toggle('is-near', length >= 850);
  };

  field.addEventListener('input', update);
  update();
})();

(function () {
  const form = document.getElementById('ts-review-form');
  const modalEl = document.getElementById('reviewConfirmModal');
  const consent = document.getElementById('rev_consent');
  const confirmBtn = document.getElementById('reviewConfirmSubmit');
  if (!form || !modalEl || !consent || !confirmBtn) return;

  const syncConfirm = () => { confirmBtn.disabled = !consent.checked; };
  consent.addEventListener('change', syncConfirm);
  syncConfirm();

  form.addEventListener('submit', (event) => {
    if (event.submitter === confirmBtn) return;
    if (!window.bootstrap?.Modal) return;

    event.preventDefault();
    const invalid = Array.from(form.elements).find((el) => el !== consent && el.willValidate && !el.checkValidity());
    if (invalid) {
      invalid.reportValidity();
      return;
    }
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  });
})();

(function () {
  document.querySelectorAll('[data-report-detail]').forEach((field) => {
    const count = field.closest('.report-review-field')?.querySelector('[data-report-count]');
    if (!count) return;

    const update = () => {
      const length = field.value.length;
      count.textContent = length + ' / 1000';
      count.classList.toggle('is-near', length >= 850);
    };

    field.addEventListener('input', update);
    update();
  });
})();

(function () {
  const track = document.getElementById('reviews-track');
  const prevBtn = document.getElementById('reviews-carousel-prev');
  const nextBtn = document.getElementById('reviews-carousel-next');
  if (!track || !prevBtn || !nextBtn) return;

  const step = () => {
    const card = track.querySelector('.ts-review-item');
    if (!card) return track.clientWidth;
    const styles = getComputedStyle(track);
    const gap = parseFloat(styles.columnGap || styles.gap) || 0;
    return card.getBoundingClientRect().width + gap;
  };

  const update = () => {
    const maxScroll = track.scrollWidth - track.clientWidth;
    prevBtn.disabled = track.scrollLeft <= 2;
    nextBtn.disabled = track.scrollLeft >= maxScroll - 2;
  };

  prevBtn.addEventListener('click', () => {
    track.scrollBy({ left: -step(), behavior: 'smooth' });
  });
  nextBtn.addEventListener('click', () => {
    track.scrollBy({ left: step(), behavior: 'smooth' });
  });
  track.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  update();
})();
</script>

<?php render_footer(); ?>
