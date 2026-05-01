<?php
// Configurar la zona horaria para Colombia
date_default_timezone_set('America/Bogota');

// Incluir configuración de base de datos
require_once 'config/db.php';

// Inicialización de variables para evitar errores de "Undefined variable"
$total_empleados = 0;
$asistencias_hoy = 0;
$total_pagado_mes = 0;
$proximo_festivo = null;
$ultimos_pagos = [];
$config_ley = [];
$conexion_estado = "Desconectado";

try {
    if ($pdo) {
        $conexion_estado = "Conexión exitosa a la base de datos";
        
        // 1. Cargar Parámetros de Ley
        $stmt_ley = $pdo->query("SELECT * FROM parametros_ley LIMIT 1");
        $config_ley = $stmt_ley->fetch();

        // 2. Total Empleados
        $total_empleados = $pdo->query("SELECT COUNT(*) FROM empleados")->fetchColumn();

        // 3. Asistencias de hoy
        $hoy = date('Y-m-d');
        $asistencias_hoy = $pdo->query("SELECT COUNT(*) FROM asistencia_diaria WHERE fecha = '$hoy'")->fetchColumn();

        // 4. Total pagado este mes
        $mes_actual = date('m');
        $anio_actual = date('Y');
        $total_pagado_mes = $pdo->query("SELECT SUM(total_pagado) FROM historico_nomina WHERE MONTH(fecha_pago) = '$mes_actual' AND YEAR(fecha_pago) = '$anio_actual'")->fetchColumn() ?: 0;

        // 5. Próximo Festivo
        $proximo_festivo = $pdo->query("SELECT descripcion, fecha FROM festivos WHERE fecha >= '$hoy' ORDER BY fecha ASC LIMIT 1")->fetch();

        // 6. Últimos movimientos
        $ultimos_pagos = $pdo->query("SELECT h.*, e.nombre_completo FROM historico_nomina h 
                                     JOIN contratos c ON h.contrato_id = c.id 
                                     JOIN empleados e ON c.empleado_id = e.id 
                                     ORDER BY h.id DESC LIMIT 5")->fetchAll();
    }
} catch (Exception $e) {
    // Silencioso o manejar log interno
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Nómina</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .stat-card:hover { transform: translateY(-5px); transition: all 0.3s ease; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">

    <nav class="bg-slate-900 text-white p-4 shadow-lg">
        <div class="container mx-auto flex justify-between items-center">
            <div class="flex items-center space-x-2">
                <div class="bg-indigo-600 p-2 rounded-lg">
                    <i class="fas fa-calculator text-xl"></i>
                </div>
                <h1 class="text-xl font-bold tracking-tight">NOMINA<span class="text-indigo-400">PRO</span></h1>
            </div>
            <div class="hidden md:flex space-x-6 text-sm font-medium">
                <span class="text-slate-400"><i class="far fa-calendar-alt mr-2"></i> <?= date('d M, Y') ?></span>
                <span class="text-slate-400"><i class="far fa-clock mr-2"></i> <span id="clock">--:--</span></span>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        
        <!-- SECCIÓN DE BIENVENIDA Y ESTADO -->
        <div class="flex flex-col md:flex-row justify-between items-start mb-8 gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Panel de Control General</h2>
                <p class="text-slate-500 text-sm">Resumen operativo del sistema de gestión de personal.</p>
            </div>
            
            <!-- INFORMACIÓN DEL SISTEMA SOLICITADA -->
            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-xs min-w-[300px]">
                <div class="flex items-center text-emerald-600 mb-2 font-bold">
                    <i class="fas fa-database mr-2"></i> <?= $conexion_estado ?>
                </div>
                <div class="space-y-1 text-slate-600">
                    <p class="font-semibold text-slate-800 border-b pb-1 mb-1 italic">Parámetros de Ley cargados:</p>
                    <div class="flex justify-between">
                        <span>SMMLV:</span>
                        <span class="font-mono">$<?= number_format($config_ley['smmlv'] ?? 1750905, 0) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span>Aux. Transporte:</span>
                        <span class="font-mono">$<?= number_format($config_ley['auxilio_transporte'] ?? 249095, 0) ?></span>
                    </div>
                    <div class="flex justify-between border-t pt-1">
                        <span>Inicio Nocturno:</span>
                        <span class="font-mono text-indigo-600"><?= $config_ley['hora_inicio_nocturno'] ?? '19:00:00' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- INDICADORES RÁPIDOS -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
            <div class="stat-card bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Empleados</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-1"><?= $total_empleados ?></h3>
                    </div>
                    <div class="bg-blue-50 p-3 rounded-xl text-blue-600">
                        <i class="fas fa-users text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Asistencias Hoy</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-1"><?= $asistencias_hoy ?></h3>
                    </div>
                    <div class="bg-emerald-50 p-3 rounded-xl text-emerald-600">
                        <i class="fas fa-user-check text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Pagado (Mes)</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-1">$<?= number_format($total_pagado_mes, 0) ?></h3>
                    </div>
                    <div class="bg-amber-50 p-3 rounded-xl text-amber-600">
                        <i class="fas fa-money-bill-wave text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Próximo Festivo</p>
                        <h3 class="text-lg font-bold text-slate-800 mt-1 truncate max-w-[150px]"><?= $proximo_festivo['descripcion'] ?? 'Sin festivos' ?></h3>
                        <p class="text-[10px] text-slate-400"><?= $proximo_festivo['fecha'] ?? '--' ?></p>
                    </div>
                    <div class="bg-indigo-50 p-3 rounded-xl text-indigo-600">
                        <i class="fas fa-calendar-star text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- ACCESOS DIRECTOS -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 space-y-8">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                    <div class="p-6 border-b border-slate-50">
                        <h3 class="font-bold text-slate-800">Módulos del Sistema</h3>
                    </div>
                    <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <a href="asistencia.php" class="flex items-center p-4 rounded-xl border border-slate-100 hover:bg-slate-50 transition group">
                            <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-lg flex items-center justify-center mr-4 group-hover:bg-blue-600 group-hover:text-white transition"><i class="fas fa-clock"></i></div>
                            <div>
                                <h4 class="font-bold text-slate-700">Asistencia</h4>
                                <p class="text-xs text-slate-400">Control de entradas y salidas.</p>
                            </div>
                        </a>
                        <a href="nomina.php" class="flex items-center p-4 rounded-xl border border-slate-100 hover:bg-slate-50 transition group">
                            <div class="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-lg flex items-center justify-center mr-4 group-hover:bg-emerald-600 group-hover:text-white transition"><i class="fas fa-file-invoice-dollar"></i></div>
                            <div>
                                <h4 class="font-bold text-slate-700">Nómina</h4>
                                <p class="text-xs text-slate-400">Procesar liquidaciones mensuales.</p>
                            </div>
                        </a>
                        <a href="historial_pagos.php" class="flex items-center p-4 rounded-xl border border-slate-100 hover:bg-slate-50 transition group">
                            <div class="w-12 h-12 bg-amber-100 text-amber-600 rounded-lg flex items-center justify-center mr-4 group-hover:bg-amber-600 group-hover:text-white transition"><i class="fas fa-history"></i></div>
                            <div>
                                <h4 class="font-bold text-slate-700">Historial</h4>
                                <p class="text-xs text-slate-400">Archivos de pagos generados.</p>
                            </div>
                        </a>
                        <a href="configuracion.php" class="flex items-center p-4 rounded-xl border border-slate-100 hover:bg-slate-50 transition group">
                            <div class="w-12 h-12 bg-slate-100 text-slate-600 rounded-lg flex items-center justify-center mr-4 group-hover:bg-slate-800 group-hover:text-white transition"><i class="fas fa-cogs"></i></div>
                            <div>
                                <h4 class="font-bold text-slate-700">Configuración</h4>
                                <p class="text-xs text-slate-400">Personal y parámetros legales.</p>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- ÚLTIMOS PAGOS (CORREGIDO FOREACH) -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                    <div class="p-6 border-b border-slate-50">
                        <h3 class="font-bold text-slate-800">Últimas Liquidaciones</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 text-[10px] uppercase text-slate-400 font-bold">
                                <tr>
                                    <th class="px-6 py-3">Empleado</th>
                                    <th class="px-6 py-3">Fecha</th>
                                    <th class="px-6 py-3 text-right">Monto</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php if (!empty($ultimos_pagos)): ?>
                                    <?php foreach($ultimos_pagos as $pago): ?>
                                    <tr class="text-sm">
                                        <td class="px-6 py-4 font-medium text-slate-700"><?= htmlspecialchars($pago['nombre_completo']) ?></td>
                                        <td class="px-6 py-4 text-slate-500"><?= $pago['fecha_pago'] ?></td>
                                        <td class="px-6 py-4 text-right font-bold text-emerald-600">$<?= number_format($pago['total_pagado'], 0) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="p-6 text-center text-slate-400 italic">No hay registros recientes.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-8">
                <div class="bg-indigo-900 text-white rounded-2xl p-6 shadow-xl relative overflow-hidden">
                    <div class="relative z-10">
                        <h3 class="text-lg font-bold mb-2">Estado Activo</h3>
                        <p class="text-indigo-200 text-xs leading-relaxed mb-4">
                            Sistema monitoreando registros de entrada. Recuerde verificar las horas extra.
                        </p>
                        <div class="flex items-center space-x-2 text-emerald-400">
                            <span class="flex h-2 w-2 rounded-full bg-emerald-400 animate-ping"></span>
                            <span class="text-[10px] font-bold uppercase tracking-widest">En Línea</span>
                        </div>
                    </div>
                    <i class="fas fa-shield-alt absolute -right-4 -bottom-4 text-8xl text-white/10"></i>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const time = now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
            document.getElementById('clock').textContent = time;
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>
