<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.php');
    exit;
}

$username = sanitize($_POST['username'] ?? '');
$password = sanitize($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header('Location: /login.php');
    exit;
}

$mysqli = db_connect();

// Suponiendo que la tabla usuarios_sistema tiene columnas: usuario, password_hash (BCrypt)
$stmt = $mysqli->prepare('SELECT id, usuario, password_hash FROM usuarios_sistema WHERE usuario = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    exit('Error interno');
}
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user && password_verify($password, $user['password_hash'])) {
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['usuario'];
    header('Location: /admin.php');
    exit;
}

// Login fallido - mostrar mensaje de error
$_SESSION['login_error'] = 'Usuario o contraseña incorrectos';
header('Location: /login.php');
exit;
