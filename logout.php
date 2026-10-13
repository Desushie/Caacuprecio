<?php
require_once __DIR__ . '/config.php';
unset($_SESSION['user']);
session_regenerate_id(true);
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Cerraste sesión.'];
header('Location: login.php');
exit;
