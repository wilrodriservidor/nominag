<?php
require_once 'config/db.php';
require_once 'includes/funciones.php';

$liquidacion = null;
$mensaje_status = "";

// 1. OBTENER PARÁMETROS DE LEY (Regla de oro: Revisar nombres exactos de tabla y columnas)
try {
    // Intentar obtener de config_ley (según tu SQL la tabla se llama config_ley)
    $stmt_ley = $pdo->query("SELECT * FROM config_ley WHERE activa = 1 LIMIT 1");
    $config_ley = $stmt_ley->fetch();

    if (!$config_ley) {
        // Fallback si no hay activa o la tabla varía
        $config_ley = [
            'salario_minimo' => 1300000,
            'auxilio_transporte' => 162000,
            'salud_empleado' => 4,
            'pension_empleado' => 4,
            'recargo_nocturno' => 35,
            'recargo_festivo' => 75,
            'recargo_festivo_nocturno' => 110,
            'anio' => 2026
        ];
    }

    $smlv = $config_ley['salario_minimo'];
    $aux_transporte_ley = $config_ley['auxilio_transporte'];
    $porc_salud = $config_ley['salud_empleado'];
    $porc_pension = $config_ley['pension_empleado'];
    $p_rn = $config_ley['recargo_nocturno'];
    $p_rf = $config_ley['recargo_festivo'];
    $p_rfn = $config_ley['recargo_festivo_nocturno'];

} catch (Exception $e) {
    die("Error en configuración de ley: " . $e->getMessage());
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
        // CONSULTA BASADA EN TU SQL REAL (u270613792_nomina_gemini.sql)
        // Columnas exactas: horas_diurnas, horas_nocturnas, recargo_festivo_diurno, recargo_festivo_nocturno
        $stmt_asistencia = $pdo->prepare("
            SELECT 
                SUM(horas_diurnas) as hd, 
                SUM(horas_nocturnas) as hn,
                SUM(recargo_festivo_diurno) as rfd,
                SUM(recargo_festivo_nocturno) as rfn
            FROM asistencia_diaria 
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
        ");
        $stmt_asistencia->execute([$emp_id, $desde, $hasta]);
        $asistencia = $stmt_asistencia->fetch();

        $salario_base = $contrato['salario_base'];
        $valor_hora = $salario_base / 240; 

        // Cálculo de Recargos según tu DB
        $v_rn = ($asistencia['hn'] ?? 0) * ($valor_hora * ($p_rn / 100));
        $v_rfd = ($asistencia['rfd'] ?? 0) * ($valor_hora * ($p_rf / 100));
        $v_rfn = ($asistencia['rfn'] ?? 0) * ($valor_hora * ($p_rfn / 100));
        
        $total_recargos_festivos = $v_rfd + $v_rfn;

        // Auxilio de transporte (Proporcional a la quincena)
        $v_aux_trans = ($salario_base <= ($smlv * 2)) ? ($aux_transporte_ley / 2) : 0;

        // Deducciones (Sobre salario base quincenal + recargos)
        $base_prestacional = ($salario_base / 2) + $v_rn + $total_recargos_festivos;
        $v_salud = $base_prestacional * ($porc_salud / 100);
        $v_pension = $base_prestacional * ($porc_pension / 100);

        $liquidacion = [
            'nombre' => $contrato['nombre_completo'],
            'contrato_id' => $contrato['id'],
            'salario_quincena' => $salario_base / 2,
            'rn_val' => $v_rn,
            'rf_val' => $total_recargos_festivos,
            'aux_trans' => $v_aux_trans,
            'aux_mov' => ($contrato['aux_movilizacion_mensual'] ?? 0) / 2,
            'aux_noc' => ($contrato['aux_mov_nocturno_mensual'] ?? 0) / 2,
            'salud' => $v_salud,
            'pension' => $v_pension,
            'neto' => $base_prestacional + $v_aux_trans + (($contrato['aux_movilizacion_mensual'] ?? 0) / 2) + (($contrato['aux_mov_nocturno_mensual'] ?? 0) / 2) - $v_salud - $v_pension
        ];
    }
}

$empleados = $pdo->query("SELECT id, nombre_completo FROM empleados ORDER BY nombre_completo ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sistema de Nómina - Regla de Oro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen font-sans">
    <div class="max-w-6xl mx-auto py-12 px-6">
        
        <header class="flex justify-between items-center mb-10">
            <div>
                <h1 class="text-3xl font-black text-slate-800">Cálculo de Nómina</h1>
                <p class="text-slate-500 font-bold uppercase text-[10px] tracking-widest mt-1">Conexión verificada: u270613792_nomina_gemini</p>
            </div>
            <a href="index.php" class="bg-white border-2 border-slate-200 px-6 py-2 rounded-xl font-bold text-slate-600 hover:bg-slate-100 transition shadow-sm">
                Volver
            </a>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Sidebar: Filtros -->
            <div class="bg-white p-8 rounded-[2rem] shadow-sm border border-slate-200 h-fit">
                <form action="" method="POST" class="space-y-6">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Colaborador</label>
                        <select name="empleado_id" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-400">
                            <option value="">Seleccione...</option>
                            <?php foreach($empleados as $e): ?>
                                <option value="<?= $e['id'] ?>" <?= (isset($emp_id) && $emp_id == $e['id']) ? 'selected' : '' ?>><?= $e['nombre_completo'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Inicio</label>
                            <input type="date" name="fecha_desde" value="<?= $desde ?? date('Y-m-01') ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-3 py-2 font-bold text-sm">
                        </div>
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Fin</label>
                            <input type="date" name="fecha_hasta" value="<?= $hasta ?? date('Y-m-15') ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-3 py-2 font-bold text-sm">
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-black text-lg hover:bg-indigo-700 shadow-xl shadow-indigo-100 transition transform active:scale-95">
                        Calcular Ahora
                    </button>
                </form>
            </div>

            <!-- Panel de Resultados -->
            <div class="lg:col-span-2">
                <?php if ($liquidacion): ?>
                    <div class="bg-white rounded-[2.5rem] shadow-2xl border border-slate-200 overflow-hidden">
                        <div class="bg-slate-900 p-10 text-white flex justify-between items-center">
                            <div>
                                <h2 class="text-2xl font-black"><?= $liquidacion['nombre'] ?></h2>
                                <p class="text-slate-400 text-xs font-bold uppercase tracking-tighter">Periodo: <?= $desde ?> al <?= $hasta ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-slate-400 text-[10px] font-bold uppercase">Neto a Pagar</p>
                                <p class="text-4xl font-black text-emerald-400">$<?= number_format($liquidacion['neto'], 0, ',', '.') ?></p>
                            </div>
                        </div>

                        <div class="p-10">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
                                <div class="space-y-4">
                                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b pb-2">Devengados (Ingresos)</h3>
                                    <div class="flex justify-between text-sm"><span class="text-slate-500">Sueldo Quincenal</span><span class="font-bold text-slate-800">$<?= number_format($liquidacion['salario_quincena'], 0) ?></span></div>
                                    <div class="flex justify-between text-sm"><span class="text-slate-500">Recargos Nocturnos</span><span class="font-bold text-slate-800">$<?= number_format($liquidacion['rn_val'], 0) ?></span></div>
                                    <div class="flex justify-between text-sm"><span class="text-slate-500">Recargos Festivos</span><span class="font-bold text-slate-800">$<?= number_format($liquidacion['rf_val'], 0) ?></span></div>
                                    <div class="flex justify-between text-sm"><span class="text-slate-500">Auxilio Transporte</span><span class="font-bold text-slate-800">$<?= number_format($liquidacion['aux_trans'], 0) ?></span></div>
                                    <div class="bg-indigo-50 p-4 rounded-2xl mt-4 space-y-2">
                                        <div class="flex justify-between text-xs text-indigo-600 font-bold"><span>Aux. Movilidad</span><span>$<?= number_format($liquidacion['aux_mov'], 0) ?></span></div>
                                        <div class="flex justify-between text-xs text-indigo-600 font-bold"><span>Aux. Nocturnidad</span><span>$<?= number_format($liquidacion['aux_noc'], 0) ?></span></div>
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b pb-2">Deducciones</h3>
                                    <div class="flex justify-between text-sm"><span class="text-slate-500">Salud (<?= $porc_salud ?>%)</span><span class="font-bold text-red-500">-$<?= number_format($liquidacion['salud'], 0) ?></span></div>
                                    <div class="flex justify-between text-sm"><span class="text-slate-500">Pensión (<?= $porc_pension ?>%)</span><span class="font-bold text-red-500">-$<?= number_format($liquidacion['pension'], 0) ?></span></div>
                                </div>
                            </div>

                            <form action="guardar_nomina.php" method="POST" class="mt-12">
                                <input type="hidden" name="accion" value="guardar">
                                <input type="hidden" name="contrato_id" value="<?= $liquidacion['contrato_id'] ?>">
                                <input type="hidden" name="periodo_desde" value="<?= $desde ?>">
                                <input type="hidden" name="periodo_hasta" value="<?= $hasta ?>">
                                <input type="hidden" name="neto_pagar" value="<?= $liquidacion['neto'] ?>">
                                <input type="hidden" name="dias_liquidados" value="15">
                                <input type="hidden" name="salario_pagado" value="<?= $liquidacion['salario_quincena'] ?>">
                                <input type="hidden" name="recargos_nocturnos" value="<?= $liquidacion['rn_val'] ?>">
                                <input type="hidden" name="recargos_festivos" value="<?= $liquidacion['rf_val'] ?>">
                                <input type="hidden" name="aux_transporte" value="<?= $liquidacion['aux_trans'] ?>">
                                <input type="hidden" name="aux_movilizacion" value="<?= $liquidacion['aux_mov'] ?>">
                                <input type="hidden" name="aux_mov_nocturno" value="<?= $liquidacion['aux_noc'] ?>">
                                <input type="hidden" name="deduccion_salud" value="<?= $liquidacion['salud'] ?>">
                                <input type="hidden" name="deduccion_pension" value="<?= $liquidacion['pension'] ?>">
                                
                                <button type="submit" class="w-full bg-emerald-500 text-white py-6 rounded-3xl font-black text-xl shadow-xl hover:bg-emerald-600 transition flex items-center justify-center gap-4">
                                    <i class="fas fa-file-invoice-dollar"></i> CONFIRMAR Y GUARDAR PAGO
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="h-full border-4 border-dashed border-slate-200 rounded-[3rem] flex flex-col items-center justify-center p-20 text-slate-300">
                        <i class="fas fa-file-signature text-6xl mb-6"></i>
                        <p class="font-black uppercase tracking-widest text-sm">Esperando parámetros de búsqueda</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
