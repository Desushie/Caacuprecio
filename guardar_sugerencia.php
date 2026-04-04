<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
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

$redirect = trim((string) ($_POST['redirect'] ?? 'index.php'));
if ($redirect === '' || str_contains($redirect, "\n") || str_contains($redirect, "\r")) {
    $redirect = 'index.php';
}

$nombre = trim((string) ($_POST['sug_nombre'] ?? ''));
$email = trim((string) ($_POST['sug_email'] ?? ''));
$asunto = trim((string) ($_POST['sug_asunto'] ?? ''));
$detalle = trim((string) ($_POST['sug_detalle'] ?? ''));

if ($detalle === '') {
    $separator = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $separator . 'suggestion_error=' . rawurlencode('Escribí una sugerencia antes de enviar.'));
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $separator = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $separator . 'suggestion_error=' . rawurlencode('Ingresá un email válido o dejalo vacío.'));
    exit;
}

$pdo = db();

$stmt = $pdo->prepare("
    INSERT INTO sugerencias (
        sug_nombre,
        sug_email,
        sug_asunto,
        sug_detalle,
        sug_ip,
        sug_session_id,
        sug_estado,
        sug_fecha
    ) VALUES (
        :nombre,
        :email,
        :asunto,
        :detalle,
        :ip,
        :session_id,
        'pendiente',
        NOW()
    )
");

$stmt->execute([
    ':nombre' => $nombre !== '' ? mb_substr($nombre, 0, 120) : null,
    ':email' => $email !== '' ? mb_substr($email, 0, 150) : null,
    ':asunto' => $asunto !== '' ? mb_substr($asunto, 0, 150) : null,
    ':detalle' => mb_substr($detalle, 0, 2000),
    ':ip' => ($ip = client_ip_address()) !== '' ? $ip : null,
    ':session_id' => ($sid = session_id()) !== '' ? $sid : null,
]);

$separator = str_contains($redirect, '?') ? '&' : '?';
header('Location: ' . $redirect . $separator . 'suggestion_saved=1');
exit;