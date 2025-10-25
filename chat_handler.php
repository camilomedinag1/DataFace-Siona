<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mensaje = $input['mensaje'] ?? '';

if (empty($mensaje)) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensaje vacío']);
    exit;
}

// Obtener datos de registros_asistencia
$mysqli = db_connect();
$stmt = $mysqli->prepare("
    SELECT 
        e.nombre,
        e.documento,
        e.cargo,
        ra.tipo_evento,
        ra.fecha_hora,
        ra.validado_biometricamente
    FROM registros_asistencia ra
    JOIN empleados e ON e.id = ra.id_empleado
    ORDER BY ra.fecha_hora DESC
    LIMIT 100
");
$stmt->execute();
$registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$mysqli->close();

// Formatear los datos para el prompt
$datosEmpleados = json_encode($registros, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Preparar el prompt
$prompt = "Eres un agente el cual se le pueden hacer preguntas de entradas y salidas de los empleados de la empresa. La llegada tarde es después de las 8:10 AM y la salida es a las 5 PM. La información es la siguiente:\n\n" . $datosEmpleados . "\n\nUsuario pregunta: " . $mensaje;

// Llamar a la API de Python (Gemini)
$url = 'http://localhost:5000/chat';

$data = [
    'mensaje' => $mensaje,
    'datos' => $datosEmpleados
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log("Error Python API: " . $response);
    http_response_code(500);
    echo json_encode(['error' => 'Error al comunicarse con la IA']);
    exit;
}

$responseData = json_decode($response, true);

if (!isset($responseData['respuesta'])) {
    error_log("Respuesta inesperada de Python API: " . $response);
    http_response_code(500);
    echo json_encode(['error' => 'Respuesta inesperada de la IA']);
    exit;
}

$respuestaIA = $responseData['respuesta'];

echo json_encode([
    'respuesta' => $respuestaIA,
    'timestamp' => date('Y-m-d H:i:s')
]);
