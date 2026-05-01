<?php
/**
 * ARCHIVO: nomina.php 
 * REGLA DE ORO: Sincronización total con DB u270613792_nomina_gemini
 */

// 1. CONFIGURACIÓN INICIAL
date_default_timezone_set('America/Bogota');
require_once 'config/db.php';
require_once 'includes/funciones.php';

$liquidacion = null;
$mensaje_status = "";

// 2. CARGAR PARÁMETROS DE LEY DESDE LA BD
try {
    $stmt_ley = $pdo->query("SELECT * FROM parametros_ley LIMIT 1");
    $config_ley = $stmt_ley->fetch();

    $smlv = $config_ley['valor_smlv'] ?? 1300000;
    $aux_transporte_ley = $config_ley['subsidio_transporte'] ?? 162000;
    $p_rn = $config_ley['recargo_nocturno'] ?? 35;
    $p_rf = $config_ley['recargo_festivo'] ?? 75;
    $p_rfn = $p_rf + $p_rn; // Recargo festivo nocturno
} catch (Exception $e) {
    // Valores de respaldo si la tabla falla
    $smlv = 1300000; $aux_transporte_ley = 162000; $p_rn = 35; $p_rf = 75; $p_rfn = 110;
}

// 3. PROCESAR CÁLCULO AL ENVIAR FORMULARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empleado_id'])) {
    $emp_id = $_POST['empleado_id'];
    $desde  = $_POST['fecha_desde'];
    $hasta  = $_POST['fecha_hasta'];

    // Obtener contrato activo
    $stmt_con = $pdo->prepare("
        SELECT c.*, e.nombre_completo 
        FROM contratos c 
        JOIN empleados e ON c.empleado_id = e.id 
        WHERE c.empleado_id = ? AND c.activo = 1 LIMIT 1
    ");
    $stmt_con->execute([$emp_id]);
    $contrato = $stmt_con->fetch();

    if ($contrato) {
        // Consultar asistencia acumulada en el rango
        $stmt_asist = $pdo->prepare("
            SELECT 
                SUM(horas_diurnas) as total_hd, 
                SUM(horas_nocturnas) as total_hn,
                COUNT(DISTINCT fecha) as dias_reales
            FROM asistencia_diaria 
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
        ");
        $stmt_asist->execute([$emp_id, $desde, $hasta]);
        $asist = $stmt_asist->fetch();

        // Lógica de cálculo (Quincenal 15 días)
        $dias_liq = 15;
        $salario_base = $contrato['salario_base'];
        $valor_hora = $salario_base / 240; // 240 horas al mes (estándar CO)

        $salario_quincena = ($salario_base / 30) * $dias_liq;
        
        // Cálculos de recargos
        $rn_val = ($asist['total_hn'] ?? 0) * ($valor_hora * ($p_rn / 100));
        $rf_val = 0; // Aquí podrías sumar festivos si los marcas en asistencia

        // Auxilios (Proporcionales)
        $aux_trans = ($salario_base <= ($smlv * 2)) ? ($aux_transporte_ley / 30) * $dias_liq : 0;
        $aux_mov = ($contrato['aux_movilizacion_mensual'] / 30) * $dias_liq;
        $aux_noc = ($contrato['aux_mov_nocturno_mensual'] / 30) * $dias_liq;

        // Deducciones
        $base_ss = $salario_quincena + $rn_val + $rf_val;
        $salud = $base_ss * 0.04;
        $pension = $base_ss * 0.04;

        $neto = ($base_ss + $aux_trans + $aux_mov + $aux_noc) - ($salud + $pension);

        $liquidacion = [
            'contrato_id' => $contrato['id'],
            'nombre' => $contrato['nombre_completo'],
            'cargo' => $contrato['cargo'],
            'salario_quincena' => $salario_quincena,
            'rn_val' => $rn_val,
            'rf_val' => $rf_val,
            'aux_trans' => $aux_trans,
            'aux_mov' => $aux_mov,
            'aux_noc' => $aux_noc,
            'salud' => $salud,
            'pension' => $pension,
            'neto' => $neto
        ];
    }
}

$empleados = $pdo->query("SELECT id, nombre_completo FROM empleados ORDER BY nombre_completo ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sistema de Nómina - Liquidación</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-slate-100 p-6">

    <div class="max-w-5xl mx-auto">
        <h1 class="text-3xl font-bold text-slate-800 mb-8">Generar Liquidación Quincenal</h1>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <form action="nomina.php" method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">Empleado</label>
                        <select name="empleado_id" class="w-full border p-3 rounded-lg bg-slate-50">
                            <?php foreach($empleados as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= $e['nombre_completo'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">Desde</label>
                        <input type="date" name="fecha_desde" value="2026-04-16" class="w-full border p-2 rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-600 mb-1">Hasta</label>
                        <input type="date" name="fecha_hasta" value="2026-04-30" class="w-full border p-2 rounded-lg">
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold hover:bg-indigo-700 transition">
                        Calcular Nómina
                    </button>
                </form>
            </div>

            <div class="md:col-span-2">
                <?php if ($liquidacion): ?>
                    <div class="bg-white rounded-2xl shadow-xl overflow-hidden border border-slate-200">
                        <div class="bg-slate-800 p-6 text-white flex justify-between items-center">
                            <div>
                                <h2 class="text-xl font-bold"><?= $liquidacion['nombre'] ?></h2>
                                <p class="text-slate-400 text-sm"><?= $liquidacion['cargo'] ?></p>
                            </div>
                            <div class="text-right">
                                <span class="block text-xs uppercase text-slate-400">Neto a Pagar</span>
                                <span class="text-3xl font-black text-green-400">$<?= number_format($liquidacion['neto'], 0, ',', '.') ?></span>
                            </div>
                        </div>

                        <div class="p-6 grid grid-cols-2 gap-8">
                            <div>
                                <h3 class="font-bold border-b pb-2 mb-3 text-slate-500 text-xs uppercase">Devengados</h3>
                                <ul class="space-y-2 text-sm">
                                    <li class="flex justify-between"><span>Sueldo Quincenal:</span> <b>$<?= number_format($liquidacion['salario_quincena'], 0, ',', '.') ?></b></li>
                                    <li class="flex justify-between"><span>Recargos:</span> <b>$<?= number_format($liquidacion['rn_val'] + $liquidacion['rf_val'], 0, ',', '.') ?></b></li>
                                    <li class="flex justify-between"><span>Aux. Transporte:</span> <b>$<?= number_format($liquidacion['aux_trans'], 0, ',', '.') ?></b></li>
                                    <li class="flex justify-between text-indigo-600 font-medium"><span>Otros Auxilios:</span> <span>$<?= number_format($liquidacion['aux_mov'] + $liquidacion['aux_noc'], 0, ',', '.') ?></span></li>
                                </ul>
                            </div>
                            <div>
                                <h3 class="font-bold border-b pb-2 mb-3 text-slate-500 text-xs uppercase">Deducciones</h3>
                                <ul class="space-y-2 text-sm">
                                    <li class="flex justify-between"><span>Salud (4%):</span> <b class="text-red-500">-$<?= number_format($liquidacion['salud'], 0, ',', '.') ?></b></li>
                                    <li class="flex justify-between"><span>Pensión (4%):</span> <b class="text-red-500">-$<?= number_format($liquidacion['pension'], 0, ',', '.') ?></b></li>
                                </ul>
                            </div>
                        </div>

                        <div class="p-6 bg-slate-50 border-t">
                            <form action="guardar_nomina.php" method="POST">
                                <input type="hidden" name="accion" value="guardar">
                                <input type="hidden" name="contrato_id" value="<?= $liquidacion['contrato_id'] ?>">
                                <input type="hidden" name="periodo_desde" value="<?= $desde ?>">
                                <input type="hidden" name="periodo_hasta" value="<?= $hasta ?>">
                                <input type="hidden" name="salario_pagado" value="<?= $liquidacion['salario_quincena'] ?>">
                                <input type="hidden" name="recargos_nocturnos" value="<?= $liquidacion['rn_val'] ?>">
                                <input type="hidden" name="recargos_festivos" value="<?= $liquidacion['rf_val'] ?>">
                                <input type="hidden" name="aux_transporte" value="<?= $liquidacion['aux_trans'] ?>">
                                <input type="hidden" name="aux_movilizacion" value="<?= $liquidacion['aux_mov'] ?>">
                                <input type="hidden" name="aux_mov_nocturno" value="<?= $liquidacion['aux_noc'] ?>">
                                <input type="hidden" name="deduccion_salud" value="<?= $liquidacion['salud'] ?>">
                                <input type="hidden" name="deduccion_pension" value="<?= $liquidacion['pension'] ?>">
                                <input type="hidden" name="neto_pagar" value="<?= $liquidacion['neto'] ?>">
                                
                                <button type="submit" class="w-full bg-green-600 text-white py-4 rounded-xl font-bold hover:bg-green-700 shadow-lg transition">
                                    <i class="fas fa-save mr-2"></i> Confirmar y Guardar Pago
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="h-64 border-4 border-dashed border-slate-200 rounded-2xl flex items-center justify-center text-slate-400">
                        Selecciona un empleado para calcular
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
