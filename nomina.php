<?php
// Configurar la zona horaria para Colombia
date_default_timezone_set('America/Bogota');

require_once 'config/db.php';
require_once 'includes/funciones.php';

$liquidacion = null;
$mensaje_status = "";

// 1. OBTENER PARÁMETROS DE LEY TOTALMENTE DINÁMICOS
try {
    // Consultamos la tabla de configuración de ley (SMMLV, Aux Transporte, % Recargos)
    $stmt_ley = $pdo->query("SELECT * FROM config_ley WHERE fecha_fin IS NULL LIMIT 1");
    $ley = $stmt_ley->fetch();

    if (!$ley) {
        throw new Exception("No se encontró configuración de ley activa.");
    }
} catch (Exception $e) {
    die("Error crítico: " . $e->getMessage());
}

// 2. PROCESAR LIQUIDACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empleado_id'])) {
    $emp_id = $_POST['empleado_id'];
    $desde  = $_POST['fecha_desde'];
    $hasta  = $_POST['fecha_hasta'];

    // CORRECCIÓN: Se asegura traer 'cargo' y todos los campos de contratos
    $stmt_con = $pdo->prepare("
        SELECT c.*, e.nombre_completo 
        FROM contratos c 
        JOIN empleados e ON c.empleado_id = e.id 
        WHERE e.id = ? AND c.activo = 1 LIMIT 1
    ");
    $stmt_con->execute([$emp_id]);
    $contrato = $stmt_con->fetch();

    if ($contrato) {
        // Consulta de asistencia con detección automática de Domingos (DAYOFWEEK 1)
        $stmt_asist = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT fecha) as dias_asistidos,
                SUM(horas_nocturnas) as total_hn,
                SUM(CASE WHEN es_festivo = 1 OR DAYOFWEEK(fecha) = 1 THEN horas_diurnas ELSE 0 END) as festivas_diurnas,
                SUM(CASE WHEN es_festivo = 1 OR DAYOFWEEK(fecha) = 1 THEN horas_nocturnas ELSE 0 END) as festivas_nocturnas
            FROM asistencia_diaria 
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ? AND (procesado_en_nomina = 0 OR procesado_en_nomina IS NULL)
        ");
        $stmt_asist->execute([$emp_id, $desde, $hasta]);
        $asistencia = $stmt_asist->fetch();

        // Parámetros calculados desde la BD
        $salario_base = $contrato['salario_base'];
        $jornada = $ley['jornada_semanal_horas']; 
        $valor_hora = $salario_base / (($jornada / 6) * 30); 

        $dias_periodo = (strtotime($hasta) - strtotime($desde)) / (60 * 60 * 24) + 1;
        $salario_quincena = ($salario_base / 30) * $dias_periodo;

        // --- CÁLCULO DE RECARGOS (DIRECCIÓN Y CONFIANZA) ---
        $porc_rn  = $ley['recargo_nocturno'] / 100;
        $porc_rf  = $ley['recargo_festivo'] / 100;
        $porc_rfn = $ley['recargo_festivo_nocturno'] / 100;

        $v_rn = ($asistencia['total_hn'] ?? 0) * ($valor_hora * $porc_rn);
        $v_rf_diurno = ($asistencia['festivas_diurnas'] ?? 0) * ($valor_hora * $porc_rf);
        $v_rf_nocturno = ($asistencia['festivas_nocturnas'] ?? 0) * ($valor_hora * $porc_rfn);
        $total_recargos = $v_rn + $v_rf_diurno + $v_rf_nocturno;

        // --- AUXILIOS Y PRESTACIONES ---
        $v_aux_trans = ($salario_base <= ($ley['smmlv'] * 2)) ? proporcionarValor($ley['aux_transporte_ley'], $dias_periodo) : 0;
        $aux_mov = proporcionarValor($contrato['aux_movilizacion_mensual'], $dias_periodo);
        $aux_noc = proporcionarValor($contrato['aux_mov_nocturno_mensual'], $dias_periodo);

        // Seguridad Social
        $base_ss = $salario_quincena + $total_recargos;
        $v_salud = $base_ss * $ley['porc_salud_trabajador'];
        $v_pension = $base_ss * $ley['porc_pension_trabajador'];

        // Identificar si es cargo de confianza para la vista (evita el Warning)
        $cargo_actual = $contrato['cargo'] ?? 'OPERATIVO';
        $es_confianza = (strpos(strtoupper($cargo_actual), 'DIRECCION') !== false || strpos(strtoupper($cargo_actual), 'ADMIN') !== false);

        $liquidacion = [
            'nombre' => $contrato['nombre_completo'],
            'contrato_id' => $contrato['id'],
            'es_confianza' => $es_confianza,
            'salario_quincena' => $salario_quincena,
            'recargos_totales' => $total_recargos,
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
    <title>Nómina Parametrizada</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-6xl mx-auto py-10 px-4">
        
        <header class="flex justify-between items-center mb-8 bg-white p-6 rounded-3xl shadow-sm border border-slate-200">
            <div>
                <h1 class="text-2xl font-black text-slate-800 italic uppercase">Cálculo de Nómina</h1>
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Sincronizado con Base de Datos 2026</p>
            </div>
            <a href="index.php" class="text-slate-500 hover:text-indigo-600 font-bold text-sm"> <i class="fas fa-home mr-1"></i> Inicio</a>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-4">
                <div class="bg-white p-8 rounded-[2rem] shadow-xl border border-slate-200">
                    <form action="" method="POST" class="space-y-6">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 mb-2 block">Colaborador</label>
                            <select name="empleado_id" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-4 font-bold text-slate-700 outline-none focus:border-indigo-500 transition-all">
                                <option value="">Seleccionar...</option>
                                <?php foreach($empleados as $e): ?>
                                    <option value="<?= $e['id'] ?>" <?= (isset($emp_id) && $emp_id == $e['id']) ? 'selected' : '' ?>><?= $e['nombre_completo'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <input type="date" name="fecha_desde" value="<?= $desde ?? '2026-04-16' ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 font-bold text-slate-700 text-sm">
                            <input type="date" name="fecha_hasta" value="<?= $hasta ?? '2026-04-30' ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3 font-bold text-slate-700 text-sm">
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-5 rounded-2xl font-black text-lg hover:bg-indigo-700 shadow-xl transition active:scale-95">Procesar</button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-8">
                <?php if ($liquidacion): ?>
                    <div class="bg-white rounded-[2.5rem] shadow-2xl border border-slate-200 overflow-hidden">
                        <div class="bg-slate-900 p-8 text-white">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h2 class="text-3xl font-black uppercase italic"><?= $liquidacion['nombre'] ?></h2>
                                    <?php if($liquidacion['es_confianza']): ?>
                                        <span class="text-amber-400 text-[10px] font-black border border-amber-400/30 px-2 py-1 rounded italic mt-2 inline-block tracking-tighter">CARGO DE DIRECCIÓN Y CONFIANZA</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right">
                                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Neto Quincenal</p>
                                    <p class="text-5xl font-black text-emerald-400">$<?= number_format($liquidacion['neto'], 0, ',', '.') ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="p-8 grid grid-cols-1 md:grid-cols-2 gap-10">
                            <div class="space-y-4">
                                <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] border-b pb-3 mb-6">Ingresos</h3>
                                <div class="flex justify-between items-center bg-slate-50 p-4 rounded-2xl">
                                    <span class="text-slate-500 font-bold text-sm">Sueldo Base</span>
                                    <span class="font-black text-slate-800">$<?= number_format($liquidacion['salario_quincena'], 0, ',', '.') ?></span>
                                </div>
                                <div class="flex justify-between px-4">
                                    <span class="text-slate-400 text-sm font-medium">Recargos (Noc/Dom)</span>
                                    <span class="font-bold text-slate-700">$<?= number_format($liquidacion['recargos_totales'], 0, ',', '.') ?></span>
                                </div>
                                <div class="flex justify-between px-4">
                                    <span class="text-slate-400 text-sm font-medium">Aux. Transporte</span>
                                    <span class="font-bold text-slate-700">$<?= number_format($liquidacion['aux_trans'], 0, ',', '.') ?></span>
                                </div>
                                <div class="bg-indigo-50/50 p-4 rounded-2xl border border-indigo-100">
                                    <div class="flex justify-between text-xs text-indigo-700 font-bold">
                                        <span>Auxilio Movilidad</span>
                                        <span>$<?= number_format($liquidacion['aux_mov'], 0, ',', '.') ?></span>
                                    </div>
                                    <div class="flex justify-between text-xs text-indigo-700 font-bold mt-1">
                                        <span>Auxilio Nocturno</span>
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

                        <div class="p-8 bg-slate-50 border-t flex gap-4">
                            <form action="guardar_nomina.php" method="POST" class="flex-1">
                                <input type="hidden" name="contrato_id" value="<?= $liquidacion['contrato_id'] ?>">
                                <input type="hidden" name="neto_pagar" value="<?= $liquidacion['neto'] ?>">
                                <button type="submit" class="w-full bg-emerald-500 text-white py-4 rounded-2xl font-black text-lg shadow-xl hover:bg-emerald-600 transition flex items-center justify-center gap-3">
                                    <i class="fas fa-check-double"></i> FINALIZAR PAGO
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
