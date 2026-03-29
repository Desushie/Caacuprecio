<?php
require_once __DIR__ . '/config.php';

require_login();

$productId = (int) ($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
$redirect = trim((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? ''));

if ($productId <= 0) {
    http_response_code(400);
    die('Producto inválido.');
}

$pdo = db();
$userId = current_user_id();

if ($userId <= 0) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* Validar que el usuario realmente exista en la tabla usuario */
$userStmt = $pdo->prepare('SELECT idusuario FROM usuario WHERE idusuario = :id LIMIT 1');
$userStmt->execute([':id' => $userId]);
$realUserId = (int) ($userStmt->fetchColumn() ?: 0);

if ($realUserId <= 0) {
    session_destroy();
    header('Location: login.php');
    exit;
}

/* Validar producto */
$productStmt = $pdo->prepare('SELECT idproductos FROM productos WHERE idproductos = :id LIMIT 1');
$productStmt->execute([':id' => $productId]);

if (!$productStmt->fetch()) {
    http_response_code(404);
    die('Producto no encontrado.');
}

/* Verificar si ya existe en favoritos */
$existsStmt = $pdo->prepare('
    SELECT 1
    FROM favoritos
    WHERE usuario_idusuario = :u
      AND productos_idproductos = :p
    LIMIT 1
');
$existsStmt->execute([
    ':u' => $realUserId,
    ':p' => $productId,
]);

if ($existsStmt->fetchColumn()) {
    $deleteStmt = $pdo->prepare('
        DELETE FROM favoritos
        WHERE usuario_idusuario = :u
          AND productos_idproductos = :p
    ');
    $deleteStmt->execute([
        ':u' => $realUserId,
        ':p' => $productId,
    ]);
} else {
    $insertStmt = $pdo->prepare('
        INSERT INTO favoritos (usuario_idusuario, productos_idproductos)
        VALUES (:u, :p)
    ');
    $insertStmt->execute([
        ':u' => $realUserId,
        ':p' => $productId,
    ]);
}

if ($redirect === '') {
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'favoritos.php';
}

header('Location: ' . $redirect);
exit;
