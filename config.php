<?php
declare(strict_types=1);

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_USER = getenv('DB_USER') ?: 'super';
$DB_PASS = getenv('DB_PASS') ?: '12345';
$DB_NAME = getenv('DB_NAME') ?: 'reconocimiento_biometrico';

function db_connect(): mysqli {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME;
    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($mysqli->connect_errno) {
        http_response_code(500);
        exit('Error de conexión a la base de datos');
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function sanitize(string $v): string {
    return trim($v);
}


