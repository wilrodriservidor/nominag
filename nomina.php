<?php
// Configurar la zona horaria para Colombia
date_default_timezone_set('America/Bogota');

require_once 'config/db.php';
require_once 'includes/funciones.php';

$liquidacion = null;
$mensaje_status = "";

// 1. OBTENER PARÁMETROS DE LEY DINÁMICOS
try {
    $stmt_ley = $pdo->query("SELECT * FROM config_ley WHERE fecha_fin IS NULL LIMIT 1");
    $ley = $stmt_ley->fetch();

    $smlv = $ley['smmlv'] ?? 1750905;
    $aux_transporte_ley = $ley['aux_transporte_ley'] ?? 249095;
    $p_rn = $ley['recargo_nocturno'] ?? 35;
    $p_rf = $ley['recargo_festivo'] ?? 75;
    $p_rfn = $ley['recargo_festivo_nocturno'] ?? 110;
} catch (Exception $e) {
    $smlv = 1750905; $aux_transporte_ley = 249095; $p_rn = 35; $p_rf = 75; $p_rfn = 110;
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
        WHERE e.id = ? AND c.activo = 1 LIMIT 1
    ");
    $stmt_con->execute([$emp_id]);
    $contrato = $stmt_con->fetch();

    if ($contrato) {
        // Consulta de asistencia: Capturamos horas para recargos
        $stmt_asist = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT fecha) as dias_asistidos,
                SUM(horas_nocturnas) as total_hn,
                SUM(CASE WHEN es_festivo = 1 OR DAYOFWEEK(fecha) = 1 THEN horas_diurnas ELSE 0 END) as festivas_diurnas,
                SUM(CASE WHEN es_festivo = 1 OR DAYOFWEEK(fecha) = 1 THEN horas_nocturnas ELSE 0 END) as festivas_nocturnas
            FROM asistencia_diaria 
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ? AND procesado_en_nomina = 0
        ");
        $stmt_asist->execute([$emp_id, $desde, $hasta]);
        $asistencia = $stmt_asist->fetch();

        $salario_base = $contrato['salario_base'];
        $jornada_semanal = $ley['jornada_semanal_horas'] ?? 44;
        $valor_hora = $salario_base / (($jornada_semanal / 6) * 30); 

        $dias_periodo = (strtotime($hasta) - strtotime($desde)) / (60 * 60 * 24) + 1;
        $salario_quincena = ($salario_base / 30) * $dias_periodo;

        // LÓGICA DE DIRECCIÓN Y CONFIANZA:
        // Solo se pagan RECARGOS sobre las horas trabajadas.
        // El recargo nocturno es (valor_hora * %recargo). No se paga la hora ordinaria otra vez.
        $v_rn = ($asistencia['total_hn'] ?? 0) * ($valor_hora * ($p_rn / 100));
        
        // Recargos Festivos/Dominicales: 
        // Si trabajó en festivo, se paga el recargo (75% o lo parametrizado).
        $v_rf_diurno = ($asistencia['festivas_diurnas'] ?? 0) * ($valor_hora * ($p_rf / 100));
        $v_rf_nocturno = ($asistencia['festivas_nocturnas'] ?? 0) * ($valor_hora * ($p_rfn / 100));
        $total_recargos_festivos = $v_rf_diurno + $v_rf_nocturno;

        // Auxilios (Basados en el periodo de 15 días para coincidir con tu pago real)
        $v_aux_trans = ($salario_base <= ($smlv * 2)) ? proporcionarValor($aux_transporte_ley, $dias_periodo) : 0;
        $aux_mov = proporcionarValor($contrato['aux_movilizacion_mensual'], $dias_periodo);
        $aux_noc = proporcionarValor($contrato['aux_mov_nocturno_mensual'], $dias_periodo);

        // Seguridad Social
        $base_ss = $salario_quincena + $v_rn + $total_recargos_festivos;
        $v_salud = $base_ss * ($ley['porc_salud_trabajador'] ?? 0.04);
        $v_pension = $base_ss * ($ley['porc_pension_trabajador'] ?? 0.04);

        $liquidacion = [
            'nombre' => $contrato['nombre_completo'],
            'contrato_id' => $contrato['id'],
            'salario_quincena' => $salario_quincena,
            'rn_val' => $v_rn,
            'rf_val' => $total_recargos_festivos,
            'aux_trans' => $v_aux_trans,
            'aux_mov' => $aux_mov,
            'aux_noc' => $aux_noc,
            'salud' => $v_salud,
            'pension' => $v_pension,
            'neto' => ($base_ss + $v_aux_trans + $aux_mov + $aux_noc) - ($v_salud + $v_pension)
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
    <title>Sistema de Nómina - Liquidación</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-6xl mx-auto py-10 px-4">
        
        <header class="flex justify-between items-center mb-8 bg-white p-6 rounded-3xl shadow-sm border border-slate-200">
            <div>
                <h1 class="text-2xl font-black text-slate-800 flex items-center gap-3">
                    <i class="fas fa-wallet text-indigo-600"></i> Liquidación Quincenal
                </h1>
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mt-1">Parámetros según Configuración de Ley</p>
            </div>
            <a href="index.php" class="text-slate-500 hover:text-indigo-600 font-bold text-sm transition">
                <i class="fas fa-arrow-left mr-1"></i> Volver
            </a>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-4">
                <div class="bg-white p-8 rounded-[2rem] shadow-xl border border-slate-200">
                    <form action="" method="POST" class="space-y-6">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Empleado</label>
                            <select name="empleado_id" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-4 font-bold text-slate-700 outline-none focus:border-indigo-500 transition-all appearance-none">
                                <option value="">Seleccione...</option>
                                <?php foreach($empleados as $e): ?>
                                    <option value="<?= $e['id'] ?>" <?= (isset($emp_id) && $emp_id == $e['id']) ? 'selected' : '' ?>><?= $e['nombre_completo'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Desde</label>
                                <input type="date" name="fecha_desde" value="<?= $desde ?? date('Y-m-16') ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 font-bold text-slate-700 text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Hasta</label>
                                <input type="date" name="fecha_hasta" value="<?= $hasta ?? date('Y-m-30') ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 font-bold text-slate-700 text-sm">
                            </div>
                        </div>

                        <button type="submit" class="w-full bg-indigo-600 text-white py-5 rounded-2xl font-black text-lg hover:bg-indigo-700 shadow-xl shadow-indigo-100 transition flex items-center justify-center gap-3">
                            <i class="fas fa-calculator"></i> Calcular Liquidación
                        </button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-8">
                <?php if ($liquidacion): ?>
                    <div class="bg-white rounded-[2.5rem] shadow-2xl border border-slate-200 overflow-hidden">
                        <div class="bg-slate-900 p-8 text-white relative">
                            <div class="flex justify-between items-start relative z-10">
                                <div>
                                    <h2 class="text-3xl font-black uppercase italic"><?= $liquidacion['nombre'] ?></h2>
                                    <span class="bg-amber-500/20 text-amber-300 px-3 py-1 rounded-full text-[10px] font-bold border border-amber-500/30">DIRECCIÓN Y CONFIANZA</span>
                                </div>
                                <div class="text-right">
                                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Neto a Pagar</p>
                                    <p class="text-5xl font-black text-emerald-400">$<?= number_format($liquidacion['neto'], 0, ',', '.') ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-10">
                            <div class="space-y-4">
                                <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] border-b pb-3 mb-6">Ingresos</h3>
                                <div class="flex justify-between items-center bg-slate-50 p-4 rounded-2xl">
                                    <span class="text-slate-500 font-bold text-sm">Sueldo Base Quincenal</span>
                                    <span class="font-black text-slate-800">$<?= number_format($liquidacion['salario_quincena'], 0, ',', '.') ?></span>
                                </div>
                                <div class="flex justify-between items-center px-4">
                                    <span class="text-slate-400 text-sm font-medium">Recargos Nocturnos</span>
                                    <span class="font-bold text-slate-700">$<?= number_format($liquidacion['rn_val'], 0, ',', '.') ?></span>
                                </div>
                                <div class="flex justify-between items-center px-4">
                                    <span class="text-slate-400 text-sm font-medium">Recargos Festivos/Dom</span>
                                    <span class="font-bold text-slate-700">$<?= number_format($liquidacion['rf_val'], 0, ',', '.') ?></span>
                                </div>
                                <div class="flex justify-between items-center px-4">
                                    <span class="text-slate-400 text-sm font-medium">Auxilio Transporte</span>
                                    <span class="font-bold text-slate-700">$<?= number_format($liquidacion['aux_trans'], 0, ',', '.') ?></span>
                                </div>
                                <div class="bg-indigo-50/50 p-4 rounded-2xl border border-indigo-100">
                                    <p class="text-[10px] font-black text-indigo-400 uppercase mb-2 italic">Auxilios de Movilidad (15 días)</p>
                                    <div class="flex justify-between text-xs text-indigo-700 font-bold mb-1">
                                        <span>General</span>
                                        <span>$<?= number_format($liquidacion['aux_mov'], 0, ',', '.') ?></span>
                                    </div>
                                    <div class="flex justify-between text-xs text-indigo-700 font-bold">
                                        <span>Nocturno</span>
                                        <span>$<?= number_format($liquidacion['aux_noc'], 0, ',', '.') ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] border-b pb-3 mb-6">Deducciones</h3>
                                <div class="flex justify-between items-center p-4">
                                    <span class="text-slate-500 font-bold text-sm">Salud (4%)</span>
                                    <span class="font-black text-red-500">-$<?= number_format($liquidacion['salud'], 0, ',', '.') ?></span>
                                </div>
                                <div class="flex justify-between items-center p-4">
                                    <span class="text-slate-500 font-bold text-sm">Pensión (4%)</span>
                                    <span class="font-black text-red-500">-$<?= number_format($liquidacion['pension'], 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="p-8 bg-slate-50 border-t">
                            <form action="guardar_nomina.php" method="POST">
                                <input type="hidden" name="contrato_id" value="<?= $liquidacion['contrato_id'] ?>">
                                <input type="hidden" name="neto_pagar" value="<?= $liquidacion['neto'] ?>">
                                <button type="submit" class="w-full bg-emerald-500 text-white py-6 rounded-[2rem] font-black text-xl shadow-2xl hover:bg-emerald-600 transition flex items-center justify-center gap-4 group">
                                    <i class="fas fa-check-circle text-2xl group-hover:scale-125 transition"></i> 
                                    CONFIRMAR PAGO
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
