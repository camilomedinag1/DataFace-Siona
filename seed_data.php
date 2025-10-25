<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = db_connect();

// Recrear tablas asegurando compatibilidad de FK
// Desactivar FKs durante recreación
$mysqli->query("SET FOREIGN_KEY_CHECKS=0");
$mysqli->query("DROP TABLE IF EXISTS registros_asistencia");
$mysqli->query("DROP TABLE IF EXISTS empleados");
$mysqli->query("DROP TABLE IF EXISTS usuarios_sistema");

$mysqli->query("CREATE TABLE IF NOT EXISTS empleados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  documento VARCHAR(40) NOT NULL UNIQUE,
  cargo VARCHAR(80) NOT NULL,
  telefono VARCHAR(32) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$mysqli->query("CREATE TABLE IF NOT EXISTS registros_asistencia (
  id_registro INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT(11) NOT NULL,
  id_dispositivo VARCHAR(50) NOT NULL,
  tipo_evento ENUM('entrada','salida') NOT NULL,
  fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
  validado_biometricamente TINYINT(1) DEFAULT 1,
  observaciones TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$mysqli->query("SET FOREIGN_KEY_CHECKS=1");

// Semillas de empleados
$nombres = [
  'Ana', 'Luis', 'María', 'Carlos', 'Diana', 'Jorge', 'Valentina', 'Andrés', 'Paula', 'Santiago',
  'Laura', 'Felipe', 'Camila', 'Sebastián', 'Natalia', 'Ricardo', 'Daniela', 'Juan', 'Carolina', 'Miguel'
];
$apellidos = ['García','Rodríguez','Martínez','López','González','Hernández','Pérez','Sánchez','Ramírez','Torres'];
$cargos = ['Analista de Datos','Desarrollador','Científico de Datos','Ingeniero de IA','MLOps','QA','Product Designer','Scrum Master'];

// Tablas nuevas, no es necesario borrar, pero se mantiene flujo claro

$empleadoIds = [];
$stmtEmp = $mysqli->prepare("INSERT INTO empleados (nombre, documento, cargo, telefono) VALUES (?,?,?,?)");
for ($i = 0; $i < 10; $i++) {
    $nombre = $nombres[array_rand($nombres)] . ' ' . $apellidos[array_rand($apellidos)];
    $documento = (string)random_int(10000000, 99999999);
    $cargo = $cargos[array_rand($cargos)];
    $telefono = '+57 ' . random_int(3000000000, 3999999999);
    $stmtEmp->bind_param('ssss', $nombre, $documento, $cargo, $telefono);
    $stmtEmp->execute();
    $empleadoIds[] = $stmtEmp->insert_id;
}
$stmtEmp->close();

// Asistencias: por cada empleado, dos filas (entrada y salida) mismo día, 8h diferencia
$stmtEntrada = $mysqli->prepare("INSERT INTO registros_asistencia (id_empleado, id_dispositivo, tipo_evento, fecha_hora, validado_biometricamente, observaciones) VALUES (?,?,?,?,?,?)");
$stmtSalida  = $mysqli->prepare("INSERT INTO registros_asistencia (id_empleado, id_dispositivo, tipo_evento, fecha_hora, validado_biometricamente, observaciones) VALUES (?,?,?,?,?,?)");
foreach ($empleadoIds as $empleadoId) {
    $daysAgo = random_int(0, 20);
    $fecha = (new DateTime("today"))->modify("-{$daysAgo} day");
    // Salida entre 17:00 y 19:00
    $salidaHora = random_int(17, 19);
    $salidaMin = random_int(0, 59);
    $dtSalida = (clone $fecha)->setTime($salidaHora, $salidaMin, 0);
    $dtEntrada = (clone $dtSalida)->modify('-8 hours');

    $disp = 'DISP-' . str_pad((string)random_int(1, 99), 2, '0', STR_PAD_LEFT);
    $obs = null;
    $val = 1;

    $tipoE = 'entrada'; $fhE = $dtEntrada->format('Y-m-d H:i:s');
    $stmtEntrada->bind_param('isssis', $empleadoId, $disp, $tipoE, $fhE, $val, $obs);
    $stmtEntrada->execute();

    $tipoS = 'salida'; $fhS = $dtSalida->format('Y-m-d H:i:s');
    $stmtSalida->bind_param('isssis', $empleadoId, $disp, $tipoS, $fhS, $val, $obs);
    $stmtSalida->execute();
}

$stmtEntrada->close();
$stmtSalida->close();

echo "Seed completado: 10 empleados y 20 registros en registros_asistencia (entrada/salida).\n";

// Crear usuarios para login
$mysqli->query("CREATE TABLE IF NOT EXISTS usuarios_sistema (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nombre VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Limpiar e insertar dos usuarios demo
$mysqli->query("DELETE FROM usuarios_sistema");
$stmtUser = $mysqli->prepare("INSERT INTO usuarios_sistema (usuario, password_hash, nombre) VALUES (?,?,?)");
$u1 = 'admin';
$p1 = password_hash('admin123', PASSWORD_DEFAULT);
$n1 = 'Administrador';
$stmtUser->bind_param('sss', $u1, $p1, $n1);
$stmtUser->execute();

$u2 = 'demo';
$p2 = password_hash('demo123', PASSWORD_DEFAULT);
$n2 = 'Usuario Demo';
$stmtUser->bind_param('sss', $u2, $p2, $n2);
$stmtUser->execute();
$stmtUser->close();

echo "Usuarios creados: admin/admin123 y demo/demo123\n";


