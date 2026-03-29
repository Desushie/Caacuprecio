<?php
session_start();
unset($_SESSION['user']);
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Cerraste sesión correctamente.'];
header('Location: login.php');
exit;
