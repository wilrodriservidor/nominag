<?php
require_once 'config/db.php';
require_once 'includes/funciones.php';

$liquidacion = null;
$mensaje_status = "";

// 1. OBTENER PARÁMETROS DE LEY (Regla de oro: Tabla parametros_ley según index.php)
try {
    $stmt_ley = $pdo->query("SELECT * FROM parametros_ley LIMIT 1");
    $config_ley = $stmt_ley->fetch();

    $smlv = $config_ley['valor_smlv'] ?? 1300000;
    $aux_transporte_ley = $config_ley['subsidio_transporte'] ?? 162000;
    $p_rn = $config_ley['recargo_nocturno'] ?? 35;
    $p_rf = $config_ley['recargo_festivo'] ?? 75;
    // Recargo festivo nocturno suele ser la suma de ambos (110%)
    $p_rfn = $p_rf + $p_rn;

} catch (Exception $e) {
    $smlv = 1300000; $aux_transporte_ley = 162000; $p_rn = 35; $p_rf = 75; $p_rfn = 110;
}

// 2. PROCESAR LIQUIDACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empleado_id'])) {
    $emp_id = $_POST['empleado_id'];
    $desde  = $_POST['fecha_desde'];
    $hasta  = $_POST['fecha_hasta'];

    // Obtener contrato activo y datos del empleado
    $stmt_con = $pdo->prepare("
        SELECT c.*, e.nombre_completo 
        FROM contratos c 
        JOIN empleados e ON c.empleado_id = e.id 
        WHERE e.id = ? AND c.activo = 1 LIMIT 1
    ");
    $stmt_con->execute([$emp_id]);
    $contrato = $stmt_con->fetch();

    if ($contrato) {
        // CORRECCIÓN REGLA DE ORO: Usamos las columnas reales del SQL (horas_diurnas, horas_nocturnas, horas_festivas, horas_festivas_nocturnas)
        $stmt_asistencia = $pdo->prepare("
            SELECT 
                SUM(horas_diurnas) as hd_normal, 
                SUM(horas_nocturnas) as hn_normal,
                SUM(horas_festivas) as hd_festiva,
                SUM(horas_festivas_nocturnas) as hn_festiva
            FROM asistencia_diaria 
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
        ");
        $stmt_asistencia->execute([$emp_id, $desde, $hasta]);
        $asistencia = $stmt_asistencia->fetch();

        $salario_base = $contrato['salario_base'];
        $valor_hora = $salario_base / 240; 

        // Cálculo de Recargos Económicos
        // Nocturno normal (35%)
        $v_rn = ($asistencia['hn_normal'] ?? 0) * ($valor_hora * ($p_rn / 100));
        
        // Festivo Diurno (75%)
        $v_rfd = ($asistencia['hd_festiva'] ?? 0) * ($valor_hora * ($p_rf / 100));
        
        // Festivo Nocturno (110% o suma de recargos)
        $v_rfn = ($asistencia['hn_festiva'] ?? 0) * ($valor_hora * ($p_rfn / 100));
        
        $total_festivos = $v_rfd + $v_rfn;

        // Auxilio de transporte (Si gana menos o igual a 2 SMLV)
        $v_aux_trans = ($salario_base <= ($smlv * 2)) ? ($aux_transporte_ley / 2) : 0;

        // Base para Seguridad Social (Salario de la quincena + Recargos)
        $salario_quincena = $salario_base / 2;
        $base_prestacional = $salario_quincena + $v_rn + $total_festivos;
        
        $v_salud = $base_prestacional * 0.04;
        $v_pension = $base_prestacional * 0.04;

        // Beneficios adicionales del contrato
        $aux_mov = ($contrato['aux_movilizacion_mensual'] ?? 0) / 2;
        $aux_noc = ($contrato['aux_mov_nocturno_mensual'] ?? 0) / 2;

        $liquidacion = [
            'nombre' => $contrato['nombre_completo'],
            'contrato_id' => $contrato['id'],
            'salario_quincena' => $salario_quincena,
            'rn_val' => $v_rn,
            'rf_val' => $total_festivos,
            'aux_trans' => $v_aux_trans,
            'aux_mov' => $aux_mov,
            'aux_noc' => $aux_noc,
            'salud' => $v_salud,
            'pension' => $v_pension,
            'neto' => ($base_prestacional + $v_aux_trans + $aux_mov + $aux_noc) - ($v_salud + $v_pension)
        ];
    }
}

$empleados = $pdo->query("SELECT id, nombre_completo FROM empleados ORDER BY nombre_completo ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Nómina Profesional 2026</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-6xl mx-auto py-10 px-4">
        
        <header class="flex justify-between items-center mb-8 bg-white p-6 rounded-3xl shadow-sm border border-slate-200">
            <div>
                <h1 class="text-2xl font-black text-slate-800 flex items-center gap-3">
                    <i class="fas fa-wallet text-indigo-600"></i> Liquidación de Nómina
                </h1>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-1">Gestión Administrativa v2.0</p>
            </div>
            <a href="index.php" class="text-slate-500 hover:text-indigo-600 font-bold text-sm transition">
                <i class="fas fa-arrow-left mr-1"></i> Panel Principal
            </a>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Selector de Empleado -->
            <div class="lg:col-span-4">
                <div class="bg-white p-8 rounded-[2rem] shadow-xl border border-slate-200">
                    <form action="" method="POST" class="space-y-6">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Seleccionar Colaborador</label>
                            <select name="empleado_id" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-4 font-bold text-slate-700 outline-none focus:border-indigo-500 transition-all appearance-none">
                                <option value="">Elija un empleado...</option>
                                <?php foreach($empleados as $e): ?>
                                    <option value="<?= $e['id'] ?>" <?= (isset($emp_id) && $emp_id == $e['id']) ? 'selected' : '' ?>><?= $e['nombre_completo'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Desde</label>
                                <input type="date" name="fecha_desde" value="<?= $desde ?? date('Y-m-01') ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 font-bold text-slate-700 text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Hasta</label>
                                <input type="date" name="fecha_hasta" value="<?= $hasta ?? date('Y-m-15') ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 font-bold text-slate-700 text-sm">
                            </div>
                        </div>

                        <button type="submit" class="w-full bg-indigo-600 text-white py-5 rounded-2xl font-black text-lg hover:bg-indigo-700 shadow-xl shadow-indigo-100 transition transform active:scale-95 flex items-center justify-center gap-3">
                            <i class="fas fa-calculator"></i> Calcular Quincena
                        </button>
                    </form>
                </div>
            </div>

            <!-- Visualización de la Colilla -->
            <div class="lg:col-span-8">
                <?php if ($liquidacion): ?>
                    <div class="bg-white rounded-[2.5rem] shadow-2xl border border-slate-200 overflow-hidden animate-fade-in-up">
                        <div class="bg-slate-900 p-8 text-white relative">
                            <div class="flex justify-between items-start relative z-10">
                                <div>
                                    <h2 class="text-3xl font-black uppercase italic"><?= $liquidacion['nombre'] ?></h2>
                                    <div class="flex gap-4 mt-2">
                                        <span class="bg-indigo-500/20 text-indigo-300 px-3 py-1 rounded-full text-[10px] font-bold border border-indigo-500/30">ID CONTRATO: #<?= $liquidacion['contrato_id'] ?></span>
                                        <span class="bg-emerald-500/20 text-emerald-300 px-3 py-1 rounded-full text-[10px] font-bold border border-emerald-500/30">QUINCENA ACTIVA</span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Total Neto a Recibir</p>
                                    <p class="text-5xl font-black text-emerald-400">$<?= number_format($liquidacion['neto'], 0, ',', '.') ?></p>
                                </div>
                            </div>
                            <i class="fas fa-file-invoice-dollar absolute -right-6 -bottom-6 text-9xl text-white/5"></i>
                        </div>

                        <div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-10">
                            <!-- Devengados -->
                            <div class="space-y-4">
                                <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] border-b border-slate-100 pb-3 mb-6">Ingresos / Devengados</h3>
                                <div class="flex justify-between items-center bg-slate-50 p-4 rounded-2xl">
                                    <span class="text-slate-500 font-bold text-sm">Sueldo Quincenal</span>
                                    <span class="font-black text-slate-800">$<?= number_format($liquidacion['salario_quincena'], 0) ?></span>
                                </div>
                                <div class="flex justify-between items-center px-4">
                                    <span class="text-slate-400 text-sm font-medium">Recargos Nocturnos</span>
                                    <span class="font-bold text-slate-700">$<?= number_format($liquidacion['rn_val'], 0) ?></span>
                                </div>
                                <div class="flex justify-between items-center px-4">
                                    <span class="text-slate-400 text-sm font-medium">Recargos Festivos</span>
                                    <span class="font-bold text-slate-700">$<?= number_format($liquidacion['rf_val'], 0) ?></span>
                                </div>
                                <div class="flex justify-between items-center px-4">
                                    <span class="text-slate-400 text-sm font-medium">Auxilio de Transporte</span>
                                    <span class="font-bold text-slate-700">$<?= number_format($liquidacion['aux_trans'], 0) ?></span>
                                </div>
                                <div class="bg-indigo-50/50 p-4 rounded-2xl space-y-2 border border-indigo-100">
                                    <div class="flex justify-between text-xs text-indigo-700 font-bold">
                                        <span>Aux. Movilidad Mensual (50%)</span>
                                        <span>$<?= number_format($liquidacion['aux_mov'], 0) ?></span>
                                    </div>
                                    <div class="flex justify-between text-xs text-indigo-700 font-bold">
                                        <span>Aux. Mov. Nocturno (50%)</span>
                                        <span>$<?= number_format($liquidacion['aux_noc'], 0) ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Deducciones -->
                            <div class="space-y-4">
                                <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] border-b border-slate-100 pb-3 mb-6">Retenciones / Deducciones</h3>
                                <div class="flex justify-between items-center p-4">
                                    <span class="text-slate-500 font-bold text-sm">Aporte Salud (4%)</span>
                                    <span class="font-black text-red-500">-$<?= number_format($liquidacion['salud'], 0) ?></span>
                                </div>
                                <div class="flex justify-between items-center p-4">
                                    <span class="text-slate-500 font-bold text-sm">Aporte Pensión (4%)</span>
                                    <span class="font-black text-red-500">-$<?= number_format($liquidacion['pension'], 0) ?></span>
                                </div>
                                <div class="mt-10 p-6 bg-slate-900 rounded-3xl text-white">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Resumen del Pago</p>
                                    <div class="flex justify-between items-baseline">
                                        <span class="text-xs">Total Bruto</span>
                                        <span class="text-xl font-bold">$<?= number_format($liquidacion['salario_quincena'] + $liquidacion['rn_val'] + $liquidacion['rf_val'] + $liquidacion['aux_trans'] + $liquidacion['aux_mov'] + $liquidacion['aux_noc'], 0) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Botón Guardar -->
                        <div class="p-8 bg-slate-50 border-t border-slate-100">
                            <form action="guardar_nomina.php" method="POST">
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
                                
                                <button type="submit" class="w-full bg-emerald-500 text-white py-6 rounded-[2rem] font-black text-xl shadow-2xl hover:bg-emerald-600 transition flex items-center justify-center gap-4 group">
                                    <i class="fas fa-check-circle text-2xl group-hover:scale-125 transition"></i> 
                                    CONFIRMAR Y FINALIZAR PAGO
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="h-full min-h-[400px] border-4 border-dashed border-slate-200 rounded-[3rem] flex flex-col items-center justify-center p-12 text-slate-300">
                        <div class="bg-slate-200/50 w-24 h-24 rounded-full flex items-center justify-center mb-6">
                            <i class="fas fa-file-invoice text-4xl"></i>
                        </div>
                        <h3 class="font-black uppercase tracking-widest text-sm mb-2 text-slate-400">Panel de Vista Previa</h3>
                        <p class="text-center text-slate-400 text-xs max-w-[280px]">Seleccione los parámetros a la izquierda para generar la colilla de pago automática.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
