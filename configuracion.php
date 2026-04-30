<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config/db.php';

$mensaje = "";

// 1. PROCESAR CREACIÓN/EDICIÓN DE VIGENCIA (AÑO LABORAL)
if (isset($_POST['guardar_vigencia'])) {
    try {
        if (!empty($_POST['vigencia_id'])) {
            // Actualizar existente
            $sql = "UPDATE config_ley SET 
                    anio = ?, salario_minimo = ?, auxilio_transporte = ?, uvt_valor = ?, 
                    salud_empleado = ?, pension_empleado = ?, recargo_nocturno = ?, 
                    recargo_festivo = ?, recargo_festivo_nocturno = ?, activa = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            // Si marcamos esta como activa, primero desactivamos las demás
            if ($_POST['activa'] == 1) $pdo->exec("UPDATE config_ley SET activa = 0");
            
            $stmt->execute([
                $_POST['anio_nombre'], $_POST['salario_minimo'], $_POST['auxilio_transporte'], 
                $_POST['uvt_valor'], $_POST['salud_empleado'], $_POST['pension_empleado'], 
                $_POST['recargo_nocturno'], $_POST['recargo_festivo'], $_POST['recargo_festivo_nocturno'],
                $_POST['activa'], $_POST['vigencia_id']
            ]);
        } else {
            // Crear nueva
            if ($_POST['activa'] == 1) $pdo->exec("UPDATE config_ley SET activa = 0");
            $sql = "INSERT INTO config_ley (anio, salario_minimo, auxilio_transporte, uvt_valor, salud_empleado, pension_empleado, recargo_nocturno, recargo_festivo, recargo_festivo_nocturno, activa) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['anio_nombre'], $_POST['salario_minimo'], $_POST['auxilio_transporte'], 
                $_POST['uvt_valor'], $_POST['salud_empleado'], $_POST['pension_empleado'], 
                $_POST['recargo_nocturno'], $_POST['recargo_festivo'], $_POST['recargo_festivo_nocturno'],
                $_POST['activa']
            ]);
        }
        $mensaje = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>Configuración de vigencia guardada.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Error: " . $e->getMessage() . "</div>";
    }
}

// 2. PROCESAR ACTUALIZACIÓN DE EMPLEADO
if (isset($_POST['editar_empleado'])) {
    try {
        $pdo->beginTransaction();
        $stmt1 = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
        $stmt1->execute([$_POST['nombre'], $_POST['cedula'], $_POST['empleado_id']]);
        $stmt2 = $pdo->prepare("UPDATE contratos SET salario_base = ?, aux_movilizacion_mensual = ?, aux_mov_nocturno_mensual = ? WHERE empleado_id = ?");
        $stmt2->execute([$_POST['salario'], $_POST['aux_mov'], $_POST['aux_mov_noc'], $_POST['empleado_id']]);
        $pdo->commit();
        $mensaje = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>Empleado actualizado correctamente.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Error: " . $e->getMessage() . "</div>";
    }
}

// Cargar Datos
$vigencias = $pdo->query("SELECT * FROM config_ley ORDER BY anio DESC")->fetchAll();
$empleados = $pdo->query("SELECT e.*, c.salario_base, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual 
                          FROM empleados e 
                          LEFT JOIN contratos c ON e.id = c.empleado_id")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración Periodos y Nómina</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    </style>
</head>
<body class="pb-12">

    <nav class="bg-white border-b border-slate-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 h-16 flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div class="bg-indigo-600 p-2 rounded-xl text-white"><i class="fas fa-sliders-h fa-fw"></i></div>
                <span class="text-xl font-bold text-slate-800">Parametrización del Sistema</span>
            </div>
            <a href="index.php" class="text-slate-500 hover:text-indigo-600 font-medium text-sm">
                <i class="fas fa-arrow-left mr-2"></i> Dashboard
            </a>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 mt-8">
        <?php echo $mensaje; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- GESTIÓN DE VIGENCIAS (REFORMA LABORAL) -->
            <div class="lg:col-span-4 space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                        <h2 class="font-bold text-slate-800 italic">Vigencias (Ej: 2026-1)</h2>
                        <button onclick="abrirModalVigencia()" class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded-lg font-bold hover:bg-indigo-700">
                            <i class="fas fa-plus mr-1"></i> Nueva
                        </button>
                    </div>
                    <div class="p-4 space-y-3">
                        <?php foreach($vigencias as $v): ?>
                        <div class="p-4 rounded-xl border <?= $v['activa'] ? 'border-indigo-500 bg-indigo-50/30' : 'border-slate-100 bg-white' ?> flex justify-between items-center">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-800"><?= $v['anio'] ?></span>
                                    <?php if($v['activa']): ?>
                                        <span class="text-[9px] bg-indigo-600 text-white px-2 py-0.5 rounded-full uppercase font-black">Activa</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[10px] text-slate-400 mt-1">SMLV: $<?= number_format($v['salario_minimo'], 0) ?> | RN: <?= $v['recargo_nocturno'] ?>%</div>
                            </div>
                            <button onclick='abrirModalVigencia(<?= json_encode($v) ?>)' class="text-slate-400 hover:text-indigo-600 p-2">
                                <i class="fas fa-pen text-sm"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- TABLA DE EMPLEADOS -->
            <div class="lg:col-span-8">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100">
                        <h2 class="font-bold text-slate-800">Personal y Auxilios Contractuales</h2>
                    </div>
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px]">
                            <tr>
                                <th class="px-6 py-4 tracking-wider">Colaborador</th>
                                <th class="px-6 py-4 tracking-wider">Salario</th>
                                <th class="px-6 py-4 tracking-wider">Aux. Movilidad</th>
                                <th class="px-6 py-4 tracking-wider text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($empleados as $emp): ?>
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-slate-800"><?= $emp['nombre_completo'] ?></div>
                                    <div class="text-xs text-slate-400"><?= $emp['cedula'] ?></div>
                                </td>
                                <td class="px-6 py-4 font-medium text-slate-600">
                                    $<?= number_format($emp['salario_base'], 0, ',', '.') ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-indigo-600 font-bold text-xs">$<?= number_format($emp['aux_movilizacion_mensual'], 0) ?></div>
                                    <div class="text-[10px] text-slate-400">Noc: $<?= number_format($emp['aux_mov_nocturno_mensual'], 0) ?></div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick='abrirModalEmp(<?= json_encode($emp) ?>)' class="text-indigo-600 bg-indigo-50 w-8 h-8 rounded-lg hover:bg-indigo-100 transition inline-flex items-center justify-center">
                                        <i class="fas fa-user-edit"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- MODAL VIGENCIA (AÑO/PERIODO) -->
    <div id="modalVigencia" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden">
            <form action="" method="POST">
                <div class="p-6 bg-slate-800 text-white flex justify-between items-center">
                    <h3 class="font-bold" id="v_titulo">Nueva Vigencia Laboral</h3>
                    <button type="button" onclick="cerrarModal('modalVigencia')" class="text-white/50 hover:text-white"><i class="fas fa-times"></i></button>
                </div>
                <div class="p-8 grid grid-cols-2 gap-6">
                    <input type="hidden" name="vigencia_id" id="v_id">
                    <div class="col-span-2 flex gap-4">
                        <div class="flex-1">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Nombre Periodo (Ej: 2026-2)</label>
                            <input type="text" name="anio_nombre" id="v_anio" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="w-32">
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">¿Activa?</label>
                            <select name="activa" id="v_activa" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none">
                                <option value="0">No</option>
                                <option value="1">Sí</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">SMLV Vigente</label>
                        <input type="number" name="salario_minimo" id="v_smlv" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 outline-none">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase mb-1">Auxilio Transporte</label>
                        <input type="number" name="auxilio_transporte" id="v_aux" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 outline-none">
                    </div>
                    <div class="col-span-2 grid grid-cols-3 gap-4 p-4 bg-indigo-50/50 rounded-2xl border border-indigo-100">
                        <div>
                            <label class="block text-[9px] font-bold text-indigo-400 mb-1">Rec. Nocturno (%)</label>
                            <input type="number" step="0.01" name="recargo_nocturno" id="v_rn" class="w-full bg-white border border-indigo-200 rounded-lg px-3 py-1.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-[9px] font-bold text-indigo-400 mb-1">Festivo Diurno (%)</label>
                            <input type="number" step="0.01" name="recargo_festivo" id="v_fd" class="w-full bg-white border border-indigo-200 rounded-lg px-3 py-1.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-[9px] font-bold text-indigo-400 mb-1">Festivo Noc (%)</label>
                            <input type="number" step="0.01" name="recargo_festivo_nocturno" id="v_fn" class="w-full bg-white border border-indigo-200 rounded-lg px-3 py-1.5 text-sm">
                        </div>
                    </div>
                    <div class="col-span-2">
                        <button type="submit" name="guardar_vigencia" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-xl shadow-indigo-100 hover:bg-indigo-700 transition">
                            Confirmar Parametrización de Periodo
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EMPLEADO -->
    <div id="modalEmp" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden">
            <form action="" method="POST" class="p-8 space-y-5">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-slate-800">Contrato de Colaborador</h3>
                    <button type="button" onclick="cerrarModal('modalEmp')" class="text-slate-400"><i class="fas fa-times"></i></button>
                </div>
                <input type="hidden" name="empleado_id" id="e_id">
                <input type="text" name="nombre" id="e_nombre" placeholder="Nombre" class="w-full border rounded-xl px-4 py-3">
                <input type="text" name="cedula" id="e_cedula" placeholder="Cédula" class="w-full border rounded-xl px-4 py-3">
                <div class="p-4 bg-slate-50 rounded-2xl space-y-4">
                    <label class="block text-xs font-bold text-slate-500 uppercase">Remuneración y Auxilios</label>
                    <input type="number" name="salario" id="e_salario" placeholder="Salario Base" class="w-full border rounded-xl px-4 py-3 font-bold text-indigo-600">
                    <div class="grid grid-cols-2 gap-4">
                        <input type="number" name="aux_mov" id="e_aux_mov" placeholder="Aux Movilidad" class="w-full border rounded-xl px-4 py-3 text-sm">
                        <input type="number" name="aux_mov_noc" id="e_aux_noc" placeholder="Aux Noc" class="w-full border rounded-xl px-4 py-3 text-sm">
                    </div>
                </div>
                <button type="submit" name="editar_empleado" class="w-full bg-slate-800 text-white py-4 rounded-xl font-bold">Guardar Cambios</button>
            </form>
        </div>
    </div>

    <script>
        function abrirModalVigencia(v = null) {
            document.getElementById('v_id').value = v ? v.id : '';
            document.getElementById('v_anio').value = v ? v.anio : '';
            document.getElementById('v_smlv').value = v ? v.salario_minimo : 1300000;
            document.getElementById('v_aux').value = v ? v.auxilio_transporte : 162000;
            document.getElementById('v_uvt').value = v ? v.uvt_valor : 47065;
            document.getElementById('v_rn').value = v ? v.recargo_nocturno : 35;
            document.getElementById('v_fd').value = v ? v.recargo_festivo : 75;
            document.getElementById('v_fn').value = v ? v.recargo_festivo_nocturno : 110;
            document.getElementById('v_activa').value = v ? v.activa : 0;
            document.getElementById('v_titulo').innerText = v ? 'Editar Vigencia' : 'Nueva Vigencia Laboral';
            document.getElementById('modalVigencia').style.display = 'flex';
        }

        function abrirModalEmp(e) {
            document.getElementById('e_id').value = e.id;
            document.getElementById('e_nombre').value = e.nombre_completo;
            document.getElementById('e_cedula').value = e.cedula;
            document.getElementById('e_salario').value = e.salario_base;
            document.getElementById('e_aux_mov').value = e.aux_movilizacion_mensual;
            document.getElementById('e_aux_noc').value = e.aux_mov_nocturno_mensual;
            document.getElementById('modalEmp').style.display = 'flex';
        }

        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }
    </script>
</body>
</html>
