<?php
require_once 'config/db.php';
require_once 'includes/funciones.php';

$liquidacion = null;
$mensaje_status = "";

// Mostrar mensaje de éxito si viene de guardar_nomina.php
if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $mensaje_status = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4 shadow-sm'>
                        <i class='fas fa-check-circle mr-2'></i> ¡Nómina guardada exitosamente en el histórico!
                      </div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empleado_id'])) {
    $emp_id = $_POST['empleado_id'];
    $desde  = $_POST['fecha_desde'];
    $hasta  = $_POST['fecha_hasta'];

    // 1. Obtener datos del Contrato y Empleado con JOIN
    $stmt_con = $pdo->prepare("
        SELECT c.*, e.nombre_completo 
        FROM contratos c 
        JOIN empleados e ON c.empleado_id = e.id 
        WHERE c.empleado_id = ? AND c.activo = 1 LIMIT 1
    ");
    $stmt_con->execute([$emp_id]);
    $contrato = $stmt_con->fetch();

    $stmt_ley = $pdo->query("SELECT * FROM config_ley WHERE fecha_fin IS NULL LIMIT 1");
    $ley = $stmt_ley->fetch();

    if ($contrato && $ley) {
        // 2. Sumar Asistencia del periodo (Solo lo no procesado)
        $stmt_asist = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT fecha) as dias_laborados,
                SUM(horas_diurnas) as total_diurnas,
                SUM(horas_nocturnas) as total_nocturnas,
                SUM(CASE WHEN es_festivo = 1 THEN horas_diurnas ELSE 0 END) as festivas_diurnas,
                SUM(CASE WHEN es_festivo = 1 THEN horas_nocturnas ELSE 0 END) as festivas_nocturnas
            FROM asistencia_diaria 
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ? AND procesado_en_nomina = 0
        ");
        $stmt_asist->execute([$emp_id, $desde, $hasta]);
        $asistencia = $stmt_asist->fetch();

        // 3. CÁLCULOS MONETARIOS
        $valor_hora = calcularValorHora($contrato['salario_base'], $ley['jornada_semanal_horas']);
        
        $dias_periodo = (strtotime($hasta) - strtotime($desde)) / (60 * 60 * 24) + 1;
        $salario_periodo = ($contrato['salario_base'] / 30) * $dias_periodo;

        $valor_rn = ($asistencia['total_nocturnas'] ?? 0) * $valor_hora * 0.35;
        $valor_festivos = (($asistencia['festivas_diurnas'] ?? 0) * $valor_hora * 0.75) + (($asistencia['festivas_nocturnas'] ?? 0) * $valor_hora * 1.10);

        $pago_aux_mov = proporcionarValor($contrato['aux_movilizacion_mensual'], $asistencia['dias_laborados'] ?? 0);
        $pago_aux_noc = proporcionarValor($contrato['aux_mov_nocturno_mensual'], $asistencia['dias_laborados'] ?? 0);
        
        // Validación de Auxilio de Transporte (2 SMMLV)
        $pago_aux_trans = ($salario_periodo <= ($ley['smmlv'] * 2)) ? proporcionarValor($ley['aux_transporte_ley'], $dias_periodo) : 0;

        $base_ss = $salario_periodo + $valor_rn + $valor_festivos;
        $deduc_salud = $base_ss * $ley['porc_salud_trabajador'];
        $deduc_pension = $base_ss * $ley['porc_pension_trabajador'];

        $neto = ($base_ss + $pago_aux_mov + $pago_aux_noc + $pago_aux_trans) - ($deduc_salud + $deduc_pension);

        $liquidacion = [
            'nombre' => $contrato['nombre_completo'],
            'contrato_id' => $contrato['id'],
            'dias' => $asistencia['dias_laborados'] ?? 0,
            'salario' => $salario_periodo,
            'rn' => $valor_rn,
            'festivos' => $valor_festivos,
            'aux_mov' => $pago_aux_mov,
            'aux_noc' => $pago_aux_noc,
            'aux_trans' => $pago_aux_trans,
            'salud' => $deduc_salud,
            'pension' => $deduc_pension,
            'neto' => $neto
        ];
    }
}

$empleados = $pdo->query("SELECT id, nombre_completo FROM empleados")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nómina Royal Films</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body class="bg-gray-50 font-sans min-h-screen">

    <nav class="bg-indigo-900 p-4 shadow-lg text-white no-print">
        <div class="container mx-auto flex justify-between items-center text-white">
            <h1 class="text-xl font-bold"><i class="fas fa-file-invoice-dollar mr-2"></i> Generador de Nómina</h1>
            <div class="space-x-4 flex items-center">
                <a href="asistencia.php" class="text-white text-sm no-underline hover:text-indigo-200">Asistencia</a>
                <a href="historial_pagos.php" class="text-white text-sm no-underline hover:text-indigo-200">Historial</a>
                <a href="index.php" class="bg-indigo-700 px-4 py-2 rounded-lg text-sm text-white no-underline">Inicio</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto p-4 md:p-8">
        <?= $mensaje_status ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- FORMULARIO -->
            <div class="lg:col-span-1 no-print">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                    <h2 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Parámetros</h2>
                    <form method="POST" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Empleado</label>
                            <select name="empleado_id" class="w-full p-2 border rounded-lg bg-gray-50">
                                <?php foreach($empleados as $e): ?>
                                    <option value="<?= $e['id'] ?>" <?= (isset($emp_id) && $emp_id == $e['id']) ? 'selected' : '' ?>>
                                        <?= $e['nombre_completo'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Desde</label>
                                <input type="date" name="fecha_desde" value="<?= $desde ?? '' ?>" required class="w-full p-2 border rounded-lg text-sm bg-gray-50">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Hasta</label>
                                <input type="date" name="fecha_hasta" value="<?= $hasta ?? '' ?>" required class="w-full p-2 border rounded-lg text-sm bg-gray-50">
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3 rounded-lg hover:bg-indigo-700 transition">
                            <i class="fas fa-calculator mr-2"></i> Calcular Liquidación
                        </button>
                    </form>
                </div>
            </div>

            <!-- RESULTADO -->
            <div class="lg:col-span-2">
                <?php if ($liquidacion): ?>
                    <div class="bg-white rounded-2xl shadow-xl border overflow-hidden">
                        <div class="bg-indigo-600 p-6 text-white flex justify-between items-center">
                            <div>
                                <h2 class="text-2xl font-black uppercase"><?= $liquidacion['nombre'] ?></h2>
                                <p class="text-indigo-200 text-xs italic">Periodo: <?= $desde ?> al <?= $hasta ?></p>
                            </div>
                            <div class="text-right">
                                <span class="text-3xl font-black"><?= $liquidacion['dias'] ?></span>
                                <p class="text-[10px] text-indigo-100 uppercase font-bold">Días Laborados</p>
                            </div>
                        </div>

                        <div class="p-6 space-y-4">
                            <!-- Devengados -->
                            <div class="bg-gray-50 p-4 rounded-xl">
                                <p class="text-xs font-black text-gray-400 uppercase mb-3 tracking-widest">Devengados y Recargos</p>
                                <div class="space-y-2">
                                    <div class="flex justify-between"><span>Sueldo Ordinario</span><span class="font-bold">$<?= number_format($liquidacion['salario'], 0) ?></span></div>
                                    <div class="flex justify-between text-indigo-600"><span>Recargos Nocturnos</span><span class="font-bold">$<?= number_format($liquidacion['rn'], 0) ?></span></div>
                                    <div class="flex justify-between text-orange-600"><span>Recargos Festivos/Dom</span><span class="font-bold">$<?= number_format($liquidacion['festivos'], 0) ?></span></div>
                                </div>
                            </div>

                            <!-- Auxilios -->
                            <div class="bg-blue-50 p-4 rounded-xl text-blue-900">
                                <p class="text-xs font-black text-blue-400 uppercase mb-3 tracking-widest">Auxilios y Transporte</p>
                                <div class="space-y-2">
                                    <div class="flex justify-between"><span>Auxilio de Movilización</span><span class="font-bold">$<?= number_format($liquidacion['aux_mov'], 0) ?></span></div>
                                    <div class="flex justify-between"><span>Auxilio Mov. Nocturna</span><span class="font-bold">$<?= number_format($liquidacion['aux_noc'], 0) ?></span></div>
                                    <div class="flex justify-between border-t border-blue-200 pt-1 font-semibold"><span>Auxilio Transporte (Ley)</span><span>$<?= number_format($liquidacion['aux_trans'], 0) ?></span></div>
                                </div>
                            </div>

                            <!-- Deducciones -->
                            <div class="bg-red-50 p-4 rounded-xl text-red-900 border-l-4 border-red-400">
                                <p class="text-xs font-black text-red-400 uppercase mb-3 tracking-widest">Deducciones</p>
                                <div class="space-y-2">
                                    <div class="flex justify-between"><span>Salud (4%)</span><span class="font-bold">-$<?= number_format($liquidacion['salud'], 0) ?></span></div>
                                    <div class="flex justify-between"><span>Pensión (4%)</span><span class="font-bold">-$<?= number_format($liquidacion['pension'], 0) ?></span></div>
                                </div>
                            </div>

                            <!-- TOTAL -->
                            <div class="flex justify-between items-center bg-gray-900 text-white p-6 rounded-xl mt-6 shadow-inner">
                                <span class="font-bold uppercase tracking-widest text-gray-400">Neto a Transferir</span>
                                <span class="text-3xl font-black text-yellow-400">$<?= number_format($liquidacion['neto'], 0, ',', '.') ?></span>
                            </div>

                            <!-- ACCIONES -->
                            <div class="mt-6 flex flex-wrap gap-4 no-print">
                                <!-- Botón Imprimir (Nativo) -->
                                <button onclick="window.print()" class="flex-1 min-w-[120px] bg-gray-100 text-gray-600 py-3 rounded-xl font-bold hover:bg-gray-200 border transition">
                                    <i class="fas fa-print mr-2"></i> Imprimir
                                </button>

                                <!-- Botón Generar PDF (Vía Script Externo) -->
                                <form method="POST" action="generar_pdf.php" target="_blank" class="flex-1 min-w-[120px]">
                                    <?php foreach ($liquidacion as $key => $val): ?>
                                        <input type="hidden" name="<?= $key ?>" value="<?= $val ?>">
                                    <?php endforeach; ?>
                                    <input type="hidden" name="desde" value="<?= $desde ?>">
                                    <input type="hidden" name="hasta" value="<?= $hasta ?>">
                                    <button type="submit" class="w-full bg-red-600 text-white py-3 rounded-xl font-bold hover:bg-red-700 shadow-md transition">
                                        <i class="fas fa-file-pdf mr-2"></i> Exportar PDF
                                    </button>
                                </form>
                                
                                <form method="POST" action="guardar_nomina.php" class="w-full">
                                    <input type="hidden" name="accion" value="guardar">
                                    <input type="hidden" name="contrato_id" value="<?= $liquidacion['contrato_id'] ?>">
                                    <input type="hidden" name="periodo_desde" value="<?= $desde ?>">
                                    <input type="hidden" name="periodo_hasta" value="<?= $hasta ?>">
                                    <input type="hidden" name="dias_liquidados" value="<?= $liquidacion['dias'] ?>">
                                    <input type="hidden" name="salario_pagado" value="<?= $liquidacion['salario'] ?>">
                                    <input type="hidden" name="recargos_nocturnos" value="<?= $liquidacion['rn'] ?>">
                                    <input type="hidden" name="recargos_festivos" value="<?= $liquidacion['festivos'] ?>">
                                    <input type="hidden" name="aux_transporte" value="<?= $liquidacion['aux_trans'] ?>">
                                    <input type="hidden" name="aux_movilizacion" value="<?= $liquidacion['aux_mov'] ?>">
                                    <input type="hidden" name="aux_mov_nocturno" value="<?= $liquidacion['aux_noc'] ?>">
                                    <input type="hidden" name="deduccion_salud" value="<?= $liquidacion['salud'] ?>">
                                    <input type="hidden" name="deduccion_pension" value="<?= $liquidacion['pension'] ?>">
                                    <input type="hidden" name="neto_pagar" value="<?= $liquidacion['neto'] ?>">
                                    
                                    <button type="submit" class="w-full bg-green-600 text-white py-4 rounded-xl font-bold hover:bg-green-700 shadow-lg transition">
                                        <i class="fas fa-check-circle mr-2"></i> Confirmar y Guardar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-indigo-50 border-2 border-dashed border-indigo-200 rounded-2xl p-16 text-center">
                        <i class="fas fa-receipt text-5xl text-indigo-200 mb-4 block"></i>
                        <p class="text-indigo-400 font-medium">Selecciona un empleado y rango de fechas para generar la liquidación.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</body>
</html>