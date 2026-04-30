<?php
require_once 'config/db.php';
require_once 'includes/funciones.php';

$liquidacion = null;
$mensaje_status = "";

// 1. OBTENER PARÁMETROS DE LEY
try {
    // Detectamos la tabla de configuración disponible
    $tabla_config = 'config_ley';
    $check_tabla = $pdo->query("SHOW TABLES LIKE 'config_ley'")->rowCount();
    if ($check_tabla == 0) { $tabla_config = 'parametros_ley'; }

    $stmt_ley = $pdo->query("SELECT * FROM $tabla_config WHERE activa = 1 OR 1=1 LIMIT 1");
    $config_ley = $stmt_ley->fetch();

    $smlv = $config_ley['salario_minimo'] ?? $config_ley['valor_smlv'] ?? 1300000;
    $aux_transporte_ley = $config_ley['auxilio_transporte'] ?? $config_ley['subsidio_transporte'] ?? 162000;
    $porc_salud = $config_ley['salud_empleado'] ?? 4;
    $porc_pension = $config_ley['pension_empleado'] ?? 4;
    
    $p_rn = $config_ley['recargo_nocturno'] ?? 35;
    $p_rf = $config_ley['recargo_festivo'] ?? 75;

} catch (Exception $e) {
    die("Error en configuración: " . $e->getMessage());
}

// 2. PROCESAR LIQUIDACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empleado_id'])) {
    $emp_id = $_POST['empleado_id'];
    $desde  = $_POST['fecha_desde'];
    $hasta  = $_POST['fecha_hasta'];

    $stmt_con = $pdo->prepare("
        SELECT c.*, e.nombre_completo 
        FROM contratos c 
        JOIN empleados e ON c.empleado_id = e.id 
        WHERE c.empleado_id = ? AND c.activo = 1 LIMIT 1
    ");
    $stmt_con->execute([$emp_id]);
    $contrato = $stmt_con->fetch();

    if ($contrato) {
        // CORRECCIÓN DEFINITIVA: Usando los nombres de columna reales de tu SQL
        // Columnas en tu DB: horas_diurnas, horas_nocturnas, horas_festivas
        $stmt_asistencia = $pdo->prepare("
            SELECT 
                SUM(horas_diurnas) as hd, 
                SUM(horas_nocturnas) as hn, 
                SUM(horas_festivas) as hf 
            FROM asistencia_diaria 
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
        ");
        $stmt_asistencia->execute([$emp_id, $desde, $hasta]);
        $asistencia = $stmt_asistencia->fetch();

        $salario_base = $contrato['salario_base'];
        $valor_hora = $salario_base / 240; 

        // Valores de recargos
        $v_rn = ($asistencia['hn'] ?? 0) * ($valor_hora * ($p_rn / 100));
        $v_rf = ($asistencia['hf'] ?? 0) * ($valor_hora * ($p_rf / 100));
        
        // Auxilio de transporte proporcional (15 días por defecto para quincena)
        $v_aux_trans = ($salario_base <= ($smlv * 2)) ? ($aux_transporte_ley / 30 * 15) : 0;

        // Deducciones
        $v_salud = ($salario_base + $v_rn + $v_rf) * ($porc_salud / 100);
        $v_pension = ($salario_base + $v_rn + $v_rf) * ($porc_pension / 100);

        $liquidacion = [
            'nombre' => $contrato['nombre_completo'],
            'contrato_id' => $contrato['id'],
            'salario_quincena' => $salario_base / 2,
            'rn_val' => $v_rn,
            'rf_val' => $v_rf,
            'aux_trans' => $v_aux_trans,
            'aux_mov' => ($contrato['aux_movilizacion_mensual'] ?? 0) / 2,
            'aux_noc' => ($contrato['aux_mov_nocturno_mensual'] ?? 0) / 2,
            'salud' => $v_salud,
            'pension' => $v_pension,
            'neto' => ($salario_base / 2) + $v_rn + $v_rf + $v_aux_trans + (($contrato['aux_movilizacion_mensual'] ?? 0) / 2) + (($contrato['aux_mov_nocturno_mensual'] ?? 0) / 2) - $v_salud - $v_pension
        ];
    }
}

$empleados = $pdo->query("SELECT id, nombre_completo FROM empleados ORDER BY nombre_completo ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Liquidador de Nómina - Versión Estable</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen pb-20">

    <div class="max-w-6xl mx-auto px-6 py-10">
        <div class="flex justify-between items-center mb-10">
            <div>
                <h1 class="text-3xl font-black text-slate-900">Nómina Operativa</h1>
                <p class="text-slate-500 font-medium">Basado en registros de asistencia reales.</p>
            </div>
            <a href="index.php" class="bg-white border px-6 py-3 rounded-2xl font-bold text-slate-600 hover:bg-slate-50 transition shadow-sm">
                <i class="fas fa-home mr-2"></i> Inicio
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-200">
                    <form action="" method="POST" class="space-y-6">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2">Empleado</label>
                            <select name="empleado_id" required class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 font-bold text-slate-700 outline-none focus:ring-4 focus:ring-indigo-50 transition">
                                <option value="">Seleccione...</option>
                                <?php foreach($empleados as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= (isset($emp_id) && $emp_id == $emp['id']) ? 'selected' : '' ?>><?= $emp['nombre_completo'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2">Desde</label>
                                <input type="date" name="fecha_desde" value="<?= $desde ?? date('Y-m-01') ?>" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 font-bold">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2">Hasta</label>
                                <input type="date" name="fecha_hasta" value="<?= $hasta ?? date('Y-m-15') ?>" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 font-bold">
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-5 rounded-2xl font-black text-lg shadow-lg hover:bg-indigo-700 transition active:scale-95">
                            Calcular Nómina
                        </button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-2">
                <?php if ($liquidacion): ?>
                    <div class="bg-white rounded-[2.5rem] shadow-xl border border-slate-200 overflow-hidden">
                        <div class="bg-slate-900 p-8 text-white flex justify-between items-center">
                            <div>
                                <h3 class="text-xl font-black"><?= $liquidacion['nombre'] ?></h3>
                                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-1">Liquidación Generada</p>
                            </div>
                            <div class="text-right">
                                <div class="text-slate-400 text-[10px] font-black uppercase">Neto a Recibir</div>
                                <div class="text-3xl font-black text-emerald-400">$<?= number_format($liquidacion['neto'], 0, ',', '.') ?></div>
                            </div>
                        </div>

                        <div class="p-10">
                            <div class="grid grid-cols-2 gap-10">
                                <div class="space-y-4">
                                    <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b pb-2">Devengados</h4>
                                    <div class="flex justify-between text-sm"><span class="text-slate-600">Sueldo Quincena</span><span class="font-bold">$<?= number_format($liquidacion['salario_quincena'], 0) ?></span></div>
                                    <div class="flex justify-between text-sm"><span class="text-slate-600">Recargos Nocturnos</span><span class="font-bold">$<?= number_format($liquidacion['rn_val'], 0) ?></span></div>
                                    <div class="flex justify-between text-sm"><span class="text-slate-600">Recargos Festivos</span><span class="font-bold">$<?= number_format($liquidacion['rf_val'], 0) ?></span></div>
                                    <div class="flex justify-between text-sm"><span class="text-slate-600">Auxilio Transporte</span><span class="font-bold">$<?= number_format($liquidacion['aux_trans'], 0) ?></span></div>
                                    <div class="bg-slate-50 p-4 rounded-xl space-y-2 mt-4">
                                        <div class="flex justify-between text-xs text-slate-500"><span>Aux. Movilidad</span><span>$<?= number_format($liquidacion['aux_mov'], 0) ?></span></div>
                                        <div class="flex justify-between text-xs text-slate-500"><span>Aux. Nocturnidad</span><span>$<?= number_format($liquidacion['aux_noc'], 0) ?></span></div>
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b pb-2">Deducciones</h4>
                                    <div class="flex justify-between text-sm"><span class="text-slate-600">Salud (<?= $porc_salud ?>%)</span><span class="font-bold text-red-500">-$<?= number_format($liquidacion['salud'], 0) ?></span></div>
                                    <div class="flex justify-between text-sm"><span class="text-slate-600">Pensión (<?= $porc_pension ?>%)</span><span class="font-bold text-red-500">-$<?= number_format($liquidacion['pension'], 0) ?></span></div>
                                </div>
                            </div>

                            <form action="guardar_nomina.php" method="POST" class="mt-10">
                                <input type="hidden" name="accion" value="guardar">
                                <input type="hidden" name="contrato_id" value="<?= $liquidacion['contrato_id'] ?>">
                                <input type="hidden" name="periodo_desde" value="<?= $desde ?>">
                                <input type="hidden" name="periodo_hasta" value="<?= $hasta ?>">
                                <input type="hidden" name="dias_liquidados" value="15">
                                <input type="hidden" name="salario_pagado" value="<?= $liquidacion['salario_quincena'] ?>">
                                <input type="hidden" name="recargos_nocturnos" value="<?= $liquidacion['rn_val'] ?>">
                                <input type="hidden" name="recargos_festivos" value="<?= $liquidacion['rf_val'] ?>">
                                <input type="hidden" name="aux_transporte" value="<?= $liquidacion['aux_trans'] ?>">
                                <input type="hidden" name="aux_movilizacion" value="<?= $liquidacion['aux_mov'] ?>">
                                <input type="hidden" name="aux_mov_nocturno" value="<?= $liquidacion['aux_noc'] ?>">
                                <input type="hidden" name="deduccion_salud" value="<?= $liquidacion['salud'] ?>">
                                <input type="hidden" name="deduccion_pension" value="<?= $liquidacion['pension'] ?>">
                                <input type="hidden" name="neto_pagar" value="<?= $liquidacion['neto'] ?>">
                                
                                <button type="submit" class="w-full bg-emerald-500 text-white py-5 rounded-2xl font-black text-xl shadow-lg hover:bg-emerald-600 transition flex items-center justify-center gap-3">
                                    <i class="fas fa-check-double"></i> Confirmar y Guardar Pago
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="h-full flex flex-col items-center justify-center bg-white rounded-[2.5rem] border-2 border-dashed border-slate-200 p-20 text-center text-slate-300">
                        <i class="fas fa-calculator text-4xl mb-4"></i>
                        <p class="font-bold uppercase text-[10px] tracking-widest">Esperando cálculo</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
