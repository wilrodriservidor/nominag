<?php
require_once 'config/db.php';
require_once 'includes/funciones.php';

$liquidacion = null;
$mensaje_status = "";

// 1. OBTENER PARÁMETROS DE LEY (Con manejo de errores para columnas dinámicas)
try {
    // Intentamos detectar si la tabla se llama 'config_ley' (nueva) o 'parametros_ley' (antigua)
    $tabla_config = 'config_ley';
    $check_tabla = $pdo->query("SHOW TABLES LIKE 'config_ley'")->rowCount();
    if ($check_tabla == 0) { $tabla_config = 'parametros_ley'; }

    // Consultamos la configuración activa
    $stmt_ley = $pdo->query("SELECT * FROM $tabla_config WHERE activa = 1 LIMIT 1");
    $config_ley = $stmt_ley->fetch();

    // Mapeo de nombres por si las columnas se llaman diferente (SMLV vs salario_minimo)
    $smlv = $config_ley['salario_minimo'] ?? $config_ley['valor_smlv'] ?? 1300000;
    $aux_transporte_ley = $config_ley['auxilio_transporte'] ?? $config_ley['subsidio_transporte'] ?? 162000;
    $porc_salud = $config_ley['salud_empleado'] ?? 4;
    $porc_pension = $config_ley['pension_empleado'] ?? 4;
    
    // Porcentajes de recargos 2026
    $p_rn = $config_ley['recargo_nocturno'] ?? 35;
    $p_rf = $config_ley['recargo_festivo'] ?? 75;
    $p_rfn = $config_ley['recargo_festivo_nocturno'] ?? 110;

} catch (Exception $e) {
    die("Error crítico de configuración: " . $e->getMessage());
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
        // Sumar horas desde asistencia_diaria
        $stmt_asistencia = $pdo->prepare("
            SELECT SUM(horas_diurnas) as hd, SUM(horas_nocturnas) as hn, SUM(horas_festivas) as hf 
            FROM asistencia_diaria 
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
        ");
        $stmt_asistencia->execute([$emp_id, $desde, $hasta]);
        $asistencia = $stmt_asistencia->fetch();

        // Cálculo de Valores
        $salario_base = $contrato['salario_base'];
        $valor_hora = $salario_base / 240; // Estándar 240h/mes

        // Recargos (Usando los porcentajes de la DB)
        $v_rn = $asistencia['hn'] * ($valor_hora * ($p_rn / 100));
        $v_rf = $asistencia['hf'] * ($valor_hora * ($p_rf / 100));
        
        // Auxilio de Transporte (Proporcional a 15 días si es quincenal)
        $dias_liq = 15; // O calcular diferencia de fechas
        $v_aux_trans = ($salario_base <= ($smlv * 2)) ? ($aux_transporte_ley / 30 * $dias_liq) : 0;

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
            'aux_mov' => $contrato['aux_movilizacion_mensual'] / 2,
            'aux_noc' => $contrato['aux_mov_nocturno_mensual'] / 2,
            'salud' => $v_salud,
            'pension' => $v_pension,
            'neto' => ($salario_base / 2) + $v_rn + $v_rf + $v_aux_trans + ($contrato['aux_movilizacion_mensual'] / 2) + ($contrato['aux_mov_nocturno_mensual'] / 2) - $v_salud - $v_pension
        ];
    }
}

// Cargar empleados para el selector
$empleados = $pdo->query("SELECT id, nombre_completo FROM empleados ORDER BY nombre_completo ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar Nómina - Sistema 2026</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-slate-50 min-h-screen pb-20">

    <div class="max-w-6xl mx-auto px-6 py-10">
        <div class="flex justify-between items-center mb-10">
            <div>
                <h1 class="text-3xl font-black text-slate-900 tracking-tight">Liquidador de Nómina</h1>
                <p class="text-slate-500 font-medium">Periodo Activo: <span class="text-indigo-600"><?= $config_ley['anio'] ?? '2026' ?></span></p>
            </div>
            <a href="index.php" class="bg-white border px-6 py-3 rounded-2xl font-bold text-slate-600 hover:bg-slate-50 transition shadow-sm">
                <i class="fas fa-arrow-left mr-2"></i> Volver
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- FORMULARIO DE FILTRO -->
            <div class="lg:col-span-1">
                <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-200">
                    <form action="" method="POST" class="space-y-6">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2">Seleccionar Colaborador</label>
                            <select name="empleado_id" required class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 outline-none focus:ring-4 focus:ring-indigo-100 font-bold text-slate-700 appearance-none">
                                <option value="">Elija un empleado...</option>
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

                        <button type="submit" class="w-full bg-indigo-600 text-white py-5 rounded-2xl font-black text-lg shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition active:scale-95">
                            Calcular Liquidación
                        </button>
                    </form>
                </div>
            </div>

            <!-- RESULTADO DE LIQUIDACIÓN -->
            <div class="lg:col-span-2">
                <?php if ($liquidacion): ?>
                    <div class="bg-white rounded-[2.5rem] shadow-xl border border-slate-200 overflow-hidden animate-in fade-in zoom-in duration-300">
                        <div class="bg-slate-900 p-8 text-white flex justify-between items-center">
                            <div>
                                <h3 class="text-xl font-black"><?= $liquidacion['nombre'] ?></h3>
                                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-1">Resumen de Pago Quincenal</p>
                            </div>
                            <div class="text-right">
                                <div class="text-slate-400 text-[10px] font-black uppercase">Total a Pagar</div>
                                <div class="text-3xl font-black text-emerald-400">$<?= number_format($liquidacion['neto'], 0, ',', '.') ?></div>
                            </div>
                        </div>

                        <div class="p-10">
                            <div class="grid grid-cols-2 gap-10">
                                <!-- DEVENGADOS -->
                                <div class="space-y-6">
                                    <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b pb-2">Conceptos Devengados (+)</h4>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm font-bold text-slate-600">Sueldo Básico (Quincena)</span>
                                        <span class="font-black text-slate-900">$<?= number_format($liquidacion['salario_quincena'], 0) ?></span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm font-bold text-slate-600">Recargos Nocturnos</span>
                                        <span class="font-black text-slate-900">$<?= number_format($liquidacion['rn_val'], 0) ?></span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm font-bold text-slate-600">Auxilio Transporte</span>
                                        <span class="font-black text-slate-900">$<?= number_format($liquidacion['aux_trans'], 0) ?></span>
                                    </div>
                                    <div class="bg-indigo-50 p-4 rounded-2xl space-y-2">
                                        <div class="flex justify-between text-xs">
                                            <span class="text-indigo-400 font-bold">Aux. Movilidad</span>
                                            <span class="font-black text-indigo-600">$<?= number_format($liquidacion['aux_mov'], 0) ?></span>
                                        </div>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-indigo-400 font-bold">Aux. Nocturnidad</span>
                                            <span class="font-black text-indigo-600">$<?= number_format($liquidacion['aux_noc'], 0) ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- DEDUCCIONES -->
                                <div class="space-y-6">
                                    <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b pb-2">Deducciones (-)</h4>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm font-bold text-slate-600">Aporte Salud (<?= $porc_salud ?>%)</span>
                                        <span class="font-black text-red-500">-$<?= number_format($liquidacion['salud'], 0) ?></span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-sm font-bold text-slate-600">Aporte Pensión (<?= $porc_pension ?>%)</span>
                                        <span class="font-black text-red-500">-$<?= number_format($liquidacion['pension'], 0) ?></span>
                                    </div>
                                </div>
                            </div>

                            <form action="guardar_nomina.php" method="POST" class="mt-12">
                                <input type="hidden" name="accion" value="guardar">
                                <input type="hidden" name="contrato_id" value="<?= $liquidacion['contrato_id'] ?>">
                                <input type="hidden" name="periodo_desde" value="<?= $desde ?>">
                                <input type="hidden" name="periodo_hasta" value="<?= $hasta ?>">
                                <input type="hidden" name="neto_pagar" value="<?= $liquidacion['neto'] ?>">
                                
                                <button type="submit" class="w-full bg-emerald-500 text-white py-5 rounded-2xl font-black text-xl shadow-xl shadow-emerald-100 hover:bg-emerald-600 transition flex items-center justify-center gap-3">
                                    <i class="fas fa-save"></i> Guardar en Historial y Finalizar
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="h-full flex flex-col items-center justify-center bg-white rounded-[2.5rem] border-2 border-dashed border-slate-200 p-20 text-center">
                        <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mb-6">
                            <i class="fas fa-calculator text-3xl text-slate-200"></i>
                        </div>
                        <h3 class="text-xl font-bold text-slate-400">Listo para liquidar</h3>
                        <p class="text-slate-400 text-sm max-w-xs mt-2 font-medium">Seleccione un colaborador y el rango de fechas para ver el desglose de ley.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
