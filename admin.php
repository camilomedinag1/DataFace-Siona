<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';

// Redirigir si no ha iniciado sesión (simple)
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = db_connect();
$today = (new DateTime('today'))->format('Y-m-d');

// Métricas hoy
$ingresosHoy = (function(mysqli $db, string $today): int {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM registros_asistencia WHERE tipo_evento='entrada' AND DATE(fecha_hora)=? ");
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
})($db, $today);

$tardeHoy = (function(mysqli $db, string $today): int {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM registros_asistencia WHERE tipo_evento='entrada' AND DATE(fecha_hora)=? AND TIME(fecha_hora)>'08:10:00'");
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
})($db, $today);

$enPuestoHoy = (function(mysqli $db, string $today): int {
    $sql = "SELECT COUNT(*) c FROM (
              SELECT id_empleado, MAX(fecha_hora) last_entrada
              FROM registros_asistencia
              WHERE tipo_evento='entrada' AND DATE(fecha_hora)=?
              GROUP BY id_empleado
            ) le
            WHERE NOT EXISTS (
              SELECT 1 FROM registros_asistencia r2
              WHERE r2.id_empleado=le.id_empleado AND r2.tipo_evento='salida'
                AND DATE(r2.fecha_hora)=? AND r2.fecha_hora>le.last_entrada
            )";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ss', $today, $today);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
})($db, $today);

// Métrica: llegadas tarde del mes
$monthStart = (new DateTime('first day of this month'))->format('Y-m-d');
$nextMonth = (new DateTime('first day of next month'))->format('Y-m-d');
$tardesMes = (function(mysqli $db, string $start, string $next): int {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM registros_asistencia WHERE tipo_evento='entrada' AND fecha_hora>=? AND fecha_hora<? AND TIME(fecha_hora)>'08:10:00'");
    $stmt->bind_param('ss', $start, $next);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
})($db, $monthStart, $nextMonth);

// Series: ingresos por día del mes
$ingresosPorDia = (function(mysqli $db, string $start, string $next): array {
    $stmt = $db->prepare("SELECT DATE(fecha_hora) d, COUNT(*) c FROM registros_asistencia WHERE tipo_evento='entrada' AND fecha_hora>=? AND fecha_hora<? GROUP BY d ORDER BY d");
    $stmt->bind_param('ss', $start, $next);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
})($db, $monthStart, $nextMonth);

// Series: llegadas tarde por día del mes
$tardesPorDia = (function(mysqli $db, string $start, string $next): array {
    $stmt = $db->prepare("SELECT DATE(fecha_hora) d, COUNT(*) c FROM registros_asistencia WHERE tipo_evento='entrada' AND fecha_hora>=? AND fecha_hora<? AND TIME(fecha_hora)>'08:10:00' GROUP BY d ORDER BY d");
    $stmt->bind_param('ss', $start, $next);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
})($db, $monthStart, $nextMonth);

// Filtro de tabla (rango de fechas)
$startParam = $_GET['desde'] ?? $monthStart;
$endParam = $_GET['hasta'] ?? $today;

// Funcionalidad de búsqueda de usuarios
$searchTerm = $_GET['search'] ?? '';
$selectedUserId = $_GET['user_id'] ?? null;
$users = [];
$userDetails = null;
$workStats = null;

// Buscar usuarios si hay término de búsqueda
if ($searchTerm !== '') {
    $stmt = $db->prepare("SELECT id, nombre, documento, cargo, telefono FROM empleados WHERE nombre LIKE ? OR documento LIKE ? ORDER BY nombre");
    $searchPattern = "%{$searchTerm}%";
    $stmt->bind_param('ss', $searchPattern, $searchPattern);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Obtener detalles del usuario seleccionado
if ($selectedUserId) {
    // Datos básicos del empleado
    $stmt = $db->prepare("SELECT * FROM empleados WHERE id = ?");
    $stmt->bind_param('i', $selectedUserId);
    $stmt->execute();
    $userDetails = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($userDetails) {
        // Calcular estadísticas del mes actual
        $monthStart = (new DateTime('first day of this month'))->format('Y-m-d');
        $nextMonth = (new DateTime('first day of next month'))->format('Y-m-d');
        
        // Días laborados del mes
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT DATE(fecha_hora)) as dias_laborados
            FROM registros_asistencia 
            WHERE id_empleado = ? AND fecha_hora >= ? AND fecha_hora < ?
        ");
        $stmt->bind_param('iss', $selectedUserId, $monthStart, $nextMonth);
        $stmt->execute();
        $diasLaborados = $stmt->get_result()->fetch_assoc()['dias_laborados'] ?? 0;
        $stmt->close();
        
        // Llegadas tardes del mes (después de 8:10 AM)
        $stmt = $db->prepare("
            SELECT COUNT(*) as llegadas_tardes
            FROM registros_asistencia 
            WHERE id_empleado = ? AND tipo_evento = 'entrada' 
            AND fecha_hora >= ? AND fecha_hora < ? 
            AND TIME(fecha_hora) > '08:10:00'
        ");
        $stmt->bind_param('iss', $selectedUserId, $monthStart, $nextMonth);
        $stmt->execute();
        $llegadasTardes = $stmt->get_result()->fetch_assoc()['llegadas_tardes'] ?? 0;
        $stmt->close();
        
        // Calcular horas trabajadas del mes
        $stmt = $db->prepare("
            SELECT 
                DATE(fecha_hora) as fecha,
                MIN(CASE WHEN tipo_evento = 'entrada' THEN fecha_hora END) as entrada,
                MAX(CASE WHEN tipo_evento = 'salida' THEN fecha_hora END) as salida
            FROM registros_asistencia 
            WHERE id_empleado = ? AND fecha_hora >= ? AND fecha_hora < ?
            GROUP BY DATE(fecha_hora)
            ORDER BY fecha
        ");
        $stmt->bind_param('iss', $selectedUserId, $monthStart, $nextMonth);
        $stmt->execute();
        $dailyRecords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Calcular total de horas trabajadas
        $totalHoras = 0;
        $totalMinutos = 0;
        
        foreach ($dailyRecords as $record) {
            if ($record['entrada'] && $record['salida']) {
                $entrada = new DateTime($record['entrada']);
                $salida = new DateTime($record['salida']);
                $diff = $entrada->diff($salida);
                $totalMinutos += ($diff->h * 60) + $diff->i;
            }
        }
        
        $totalHoras = floor($totalMinutos / 60);
        $minutosRestantes = $totalMinutos % 60;
        
        $workStats = [
            'dias_laborados' => $diasLaborados,
            'llegadas_tardes' => $llegadasTardes,
            'horas_trabajadas' => $totalHoras,
            'minutos_trabajados' => $minutosRestantes,
            'registros_diarios' => $dailyRecords
        ];
    }
}

// Tabla resumen entrada/salida por empleado y día
$tabla = (function(mysqli $db, string $start, string $end): array {
    $sql = "SELECT e.nombre, e.documento, ra.id_empleado, DATE(ra.fecha_hora) fecha,
                   MIN(CASE WHEN ra.tipo_evento='entrada' THEN ra.fecha_hora END) entrada,
                   MAX(CASE WHEN ra.tipo_evento='salida' THEN ra.fecha_hora END) salida
            FROM registros_asistencia ra
            JOIN empleados e ON e.id = ra.id_empleado
            WHERE DATE(ra.fecha_hora) BETWEEN ? AND ?
            GROUP BY ra.id_empleado, DATE(ra.fecha_hora)
            ORDER BY fecha DESC, e.nombre ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
})($db, $startParam, $endParam);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel — Data Synergy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="admin-body">
    <header class="site-header">
        <div class="container">
            <a class="brand" href="/index.html">
                <img src="/siomalogo.png" alt="Logo" style="height: 40px;">
            </a>
            <nav class="nav">
                <a href="/logout.php">Cerrar sesión</a>
            </nav>
        </div>
    </header>
    <main class="section">
        <div class="container">
            <h1 style="color:#fff">Panel administrativo</h1>

            <section class="metrics-grid">
                <article class="metric-card">
                    <h3>Ingresos hoy</h3>
                    <div class="metric-value"><?php echo (int)$ingresosHoy; ?></div>
                </article>
                <article class="metric-card">
                    <h3>Empleados tarde (hoy)</h3>
                    <div class="metric-value"><?php echo (int)$tardeHoy; ?></div>
                </article>
                <article class="metric-card">
                    <h3>Empleados en puesto</h3>
                    <div class="metric-value"><?php echo (int)$enPuestoHoy; ?></div>
                </article>
                <article class="metric-card">
                    <h3>Tardes este mes</h3>
                    <div class="metric-value"><?php echo (int)$tardesMes; ?></div>
                </article>
            </section>

            <!-- Buscador de Usuarios -->
            <section class="card">
                <h3>Buscar Empleado</h3>
                <form method="get" class="search-form">
                    <div class="form-group">
                        <label for="search">Buscar por nombre o documento:</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                               placeholder="Ingrese nombre o documento del empleado..." required>
                        <button type="submit" class="btn btn-primary">Buscar</button>
                    </div>
                </form>
                
                <?php if (!empty($users)): ?>
                    <div class="search-results">
                        <h4>Resultados de la búsqueda:</h4>
                        <div class="users-list">
                            <?php foreach ($users as $user): ?>
                                <div class="user-card">
                                    <div class="user-info">
                                        <h5><?php echo htmlspecialchars($user['nombre']); ?></h5>
                                        <p><strong>Documento:</strong> <?php echo htmlspecialchars($user['documento']); ?></p>
                                        <p><strong>Cargo:</strong> <?php echo htmlspecialchars($user['cargo']); ?></p>
                                        <?php if ($user['telefono']): ?>
                                            <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($user['telefono']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <a href="?user_id=<?php echo $user['id']; ?>" class="btn btn-outline">Ver Detalles</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($searchTerm !== ''): ?>
                    <p>No se encontraron empleados con el término de búsqueda "<?php echo htmlspecialchars($searchTerm); ?>"</p>
                <?php endif; ?>
            </section>

            <!-- Chat con IA -->
            <section class="card">
                <h3>💬 Asistente IA - Consultas sobre Entradas y Salidas</h3>
                <div class="chat-container">
                    <div id="chatMessages" class="chat-messages">
                        <div class="chat-message assistant">
                            <div class="message-content">
                                <p>Hola! Soy tu asistente IA especializado en consultas sobre entradas y salidas de empleados. Puedes preguntarme sobre:</p>
                                <ul>
                                    <li>Registros de asistencia</li>
                                    <li>Llegadas tardes (después de 8:10 AM)</li>
                                    <li>Horarios de entrada y salida</li>
                                    <li>Estadísticas de empleados</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="chat-input-container">
                        <form id="chatForm" class="chat-form">
                            <input type="text" id="chatInput" placeholder="Escribe tu pregunta..." required>
                            <button type="submit" class="btn btn-primary">Enviar</button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Detalles del usuario seleccionado -->
            <?php if ($userDetails && $workStats): ?>
                <section class="card">
                    <h3>Detalles del Empleado</h3>
                    
                    <!-- Información básica -->
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars($userDetails['nombre']); ?></h4>
                        <div class="details-grid">
                            <div class="detail-item">
                                <strong>Documento:</strong> <?php echo htmlspecialchars($userDetails['documento']); ?>
                            </div>
                            <div class="detail-item">
                                <strong>Cargo:</strong> <?php echo htmlspecialchars($userDetails['cargo']); ?>
                            </div>
                            <?php if ($userDetails['telefono']): ?>
                                <div class="detail-item">
                                    <strong>Teléfono:</strong> <?php echo htmlspecialchars($userDetails['telefono']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Estadísticas del mes -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h5>Días Laborados (Mes)</h5>
                            <div class="stat-value"><?php echo $workStats['dias_laborados']; ?></div>
                        </div>
                        <div class="stat-card">
                            <h5>Horas Trabajadas (Mes)</h5>
                            <div class="stat-value"><?php echo $workStats['horas_trabajadas']; ?>h <?php echo $workStats['minutos_trabajados']; ?>m</div>
                        </div>
                        <div class="stat-card">
                            <h5>Llegadas Tardes (Mes)</h5>
                            <div class="stat-value"><?php echo $workStats['llegadas_tardes']; ?></div>
                        </div>
                    </div>
                    
                    <!-- Registros diarios del mes -->
                    <div class="daily-records">
                        <h4>Registros Diarios del Mes</h4>
                        <div class="table-scroll">
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Entrada</th>
                                        <th>Salida</th>
                                        <th>Horas Trabajadas</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($workStats['registros_diarios'] as $record): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($record['fecha'])); ?></td>
                                            <td><?php echo $record['entrada'] ? date('H:i', strtotime($record['entrada'])) : '-'; ?></td>
                                            <td><?php echo $record['salida'] ? date('H:i', strtotime($record['salida'])) : '-'; ?></td>
                                            <td>
                                                <?php 
                                                if ($record['entrada'] && $record['salida']) {
                                                    $entrada = new DateTime($record['entrada']);
                                                    $salida = new DateTime($record['salida']);
                                                    $diff = $entrada->diff($salida);
                                                    echo $diff->h . 'h ' . $diff->i . 'm';
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($record['entrada'] && $record['salida']) {
                                                    echo '<span class="status-complete">Completo</span>';
                                                } elseif ($record['entrada']) {
                                                    echo '<span class="status-incomplete">Sin salida</span>';
                                                } else {
                                                    echo '<span class="status-absent">Sin registro</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="charts-grid">
                <article class="card chart-card">
                    <h3>Ingresos por día (mes)</h3>
                    <canvas id="chartIngresos"></canvas>
                </article>
                <article class="card chart-card">
                    <h3>Llegadas tarde por día (mes)</h3>
                    <canvas id="chartTardes"></canvas>
                </article>
            </section>

            <section class="card">
                <div class="filter-bar">
                    <form method="get" class="filter-form">
                        <label>Desde
                            <input type="date" name="desde" value="<?php echo htmlspecialchars($startParam); ?>">
                        </label>
                        <label>Hasta
                            <input type="date" name="hasta" value="<?php echo htmlspecialchars($endParam); ?>">
                        </label>
                        <button class="btn btn-primary" type="submit">Filtrar</button>
                        <button class="btn btn-outline" type="button" id="btnCsv">Exportar CSV</button>
                    </form>
                </div>
                <div class="table-scroll">
                    <table id="tablaAsistencia">
                        <thead>
                            <tr>
                                <th>Empleado</th>
                                <th>Documento</th>
                                <th>Fecha</th>
                                <th>Entrada</th>
                                <th>Salida</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tabla as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['nombre']); ?></td>
                                <td><?php echo htmlspecialchars($row['documento']); ?></td>
                                <td><?php echo htmlspecialchars($row['fecha']); ?></td>
                                <td><?php echo htmlspecialchars($row['entrada'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['salida'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>

    <script>
    const ingresosData = <?php echo json_encode($ingresosPorDia ?? [], JSON_THROW_ON_ERROR); ?>;
    const tardesData = <?php echo json_encode($tardesPorDia ?? [], JSON_THROW_ON_ERROR); ?>;
    const labels1 = ingresosData.map(r => r.d);
    const values1 = ingresosData.map(r => Number(r.c));
    const labels2 = tardesData.map(r => r.d);
    const values2 = tardesData.map(r => Number(r.c));

    const ctx1 = document.getElementById('chartIngresos').getContext('2d');
    new Chart(ctx1, { 
        type: 'bar', 
        data: { 
            labels: labels1, 
            datasets: [{ 
                label: 'Ingresos', 
                data: values1, 
                backgroundColor: '#1f6aa5' 
            }] 
        }, 
        options: { 
            responsive: true, 
            scales: { 
                y: { 
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        callback: function(value) {
                            return Number.isInteger(value) ? value : null;
                        }
                    }
                } 
            } 
        } 
    });

    const ctx2 = document.getElementById('chartTardes').getContext('2d');
    new Chart(ctx2, { type: 'line', data: { labels: labels2, datasets: [{ label: 'Tardes', data: values2, borderColor: '#e67e22', backgroundColor: 'rgba(230,126,34,0.2)' }] }, options: { responsive: true, scales: { y: { beginAtZero: true } } } });

    document.getElementById('btnCsv')?.addEventListener('click', () => {
        const rows = [...document.querySelectorAll('#tablaAsistencia tr')].map(tr => [...tr.children].map(td => '"' + (td.textContent || '').replaceAll('"','""') + '"'));
        const csv = rows.map(r => r.join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'asistencia.csv'; a.click();
        URL.revokeObjectURL(url);
    });

    // Funcionalidad del chat con IA
    const chatForm = document.getElementById('chatForm');
    const chatInput = document.getElementById('chatInput');
    const chatMessages = document.getElementById('chatMessages');

    chatForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const mensaje = chatInput.value.trim();
        if (!mensaje) return;
        
        // Agregar mensaje del usuario
        addMessage('user', mensaje);
        chatInput.value = '';
        
        // Mostrar indicador de carga
        const loadingId = 'loading-' + Date.now();
        addMessage('assistant', 'Pensando...', loadingId);
        
        try {
            const response = await fetch('/chat_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ mensaje })
            });
            
            const data = await response.json();
            
            // Remover indicador de carga
            const loadingElement = document.getElementById(loadingId);
            if (loadingElement) {
                loadingElement.remove();
            }
            
            if (data.error) {
                addMessage('assistant', 'Error: ' + data.error);
            } else {
                addMessage('assistant', data.respuesta);
            }
        } catch (error) {
            const loadingElement = document.getElementById(loadingId);
            if (loadingElement) {
                loadingElement.remove();
            }
            addMessage('assistant', 'Error al comunicarse con el servidor');
        }
    });
    
    function addMessage(type, content, id = null) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `chat-message ${type}`;
        if (id) messageDiv.id = id;
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        
        // Formatear el contenido como HTML si es texto plano
        contentDiv.innerHTML = formatMessage(content);
        
        messageDiv.appendChild(contentDiv);
        chatMessages.appendChild(messageDiv);
        
        // Scroll al final
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    function formatMessage(text) {
        // Convertir saltos de línea en <br>
        return text.replace(/\n/g, '<br>');
    }
    </script>
    
    <style>
        .search-form {
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            gap: 10px;
            align-items: end;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        
        .form-group input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .users-list {
            display: grid;
            gap: 15px;
            margin-top: 20px;
        }
        
        .user-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
        }
        
        .user-info h5 {
            margin: 0 0 5px 0;
            color: #333;
        }
        
        .user-info p {
            margin: 2px 0;
            color: #666;
            font-size: 14px;
        }
        
        .user-details {
            margin-bottom: 30px;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .detail-item {
            padding: 10px;
            background: #f5f5f5;
            border-radius: 4px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat-card {
            text-align: center;
            padding: 20px;
            background: #1f6aa5;
            color: white;
            border-radius: 8px;
        }
        
        .stat-card h5 {
            margin: 0 0 10px 0;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
        }
        
        .daily-records {
            margin-top: 30px;
        }
        
        .records-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .records-table th,
        .records-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .records-table th {
            background: #f5f5f5;
            font-weight: 600;
        }
        
        .status-complete {
            color: #28a745;
            font-weight: bold;
        }
        
        .status-incomplete {
            color: #ffc107;
            font-weight: bold;
        }
        
        .status-absent {
            color: #dc3545;
            font-weight: bold;
        }
        
        /* Estilos del chat */
        .chat-container {
            display: flex;
            flex-direction: column;
            height: 500px;
            background: #f9f9f9;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .chat-message {
            display: flex;
            max-width: 80%;
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .chat-message.user {
            align-self: flex-end;
            justify-content: flex-end;
        }
        
        .chat-message.assistant {
            align-self: flex-start;
            justify-content: flex-start;
        }
        
        .message-content {
            padding: 12px 16px;
            border-radius: 12px;
            word-wrap: break-word;
        }
        
        .chat-message.user .message-content {
            background: #1f6aa5;
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .chat-message.assistant .message-content {
            background: white;
            color: #333;
            border: 1px solid #ddd;
            border-bottom-left-radius: 4px;
        }
        
        .chat-message.assistant .message-content ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .chat-message.assistant .message-content li {
            margin: 5px 0;
        }
        
        .chat-input-container {
            padding: 15px;
            background: white;
            border-top: 1px solid #ddd;
        }
        
        .chat-form {
            display: flex;
            gap: 10px;
        }
        
        .chat-form input {
            flex: 1;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .chat-form input:focus {
            outline: none;
            border-color: #1f6aa5;
        }
        
        .chat-form button {
            padding: 12px 24px;
            border-radius: 8px;
        }
    </style>
</body>
</html>


