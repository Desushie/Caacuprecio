<?php
declare(strict_types=1);

/**
 * Indica si la petición actual parece venir de una persona usando un navegador.
 */
function cp_is_bot_request(): bool
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    return $ua === '' || (bool) preg_match(
        '/bot|crawl|spider|slurp|scan|sqlmap|nikto|acunetix|zap|nuclei|curl|wget|python|httpclient|java\/|go-http|libwww|headless|phantom|preview|facebookexternalhit|monitor/i',
        $ua
    );
}

/**
 * El navegador ya traía la cookie de sesión, es decir, visitó el sitio antes.
 */
function cp_has_session_cookie(): bool
{
    return isset($_COOKIE[session_name()]);
}

/**
 * Limita cuántos eventos de un tipo puede registrar una sesión dentro de una ventana de tiempo.
 */
function cp_within_rate(string $bucket, int $max, int $windowSeconds): bool
{
    $key = 'cp_rate_' . $bucket;
    $now = time();
    $recent = array_filter(
        (array) ($_SESSION[$key] ?? []),
        static fn($ts): bool => is_int($ts) && $ts > $now - $windowSeconds
    );
    $recent[] = $now;
    $_SESSION[$key] = array_values($recent);

    return count($recent) <= $max;
}

/**
 * El navegador pidió no ser rastreado (Global Privacy Control o Do Not Track); la política de privacidad lo promete.
 */
function cp_privacy_signal(): bool
{
    return ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1' || ($_SERVER['HTTP_DNT'] ?? '') === '1';
}

/**
 * Solo se registran eventos de navegadores con cookie de sesión, que no se identifican
 * como bots, que no piden privacidad y que no superan un ritmo humano.
 */
function cp_should_track(string $bucket, int $max, int $windowSeconds = 600): bool
{
    return cp_has_session_cookie()
        && !cp_privacy_signal()
        && !cp_is_bot_request()
        && cp_within_rate($bucket, $max, $windowSeconds);
}

/**
 * Historial personal de productos vistos (UX de sesión).
 * Independiente del tracking analítico: así “Vistos recientemente” sigue
 * funcionando aunque DNT/GPC bloquee el insert en productos_vistos.
 */
function cp_remember_viewed_product(int $productId, int $limit = 20): void
{
    if ($productId <= 0) {
        return;
    }

    $limit = max(1, $limit);
    $history = array_values(array_filter(
        array_map('intval', (array) ($_SESSION['recently_viewed'] ?? [])),
        static fn(int $id): bool => $id > 0 && $id !== $productId
    ));

    array_unshift($history, $productId);
    $_SESSION['recently_viewed'] = array_slice($history, 0, $limit);
}

/**
 * @return list<int>
 */
function cp_session_viewed_product_ids(int $limit = 8): array
{
    $limit = max(1, $limit);
    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_SESSION['recently_viewed'] ?? [])),
        static fn(int $id): bool => $id > 0
    )));

    return array_slice($ids, 0, $limit);
}
