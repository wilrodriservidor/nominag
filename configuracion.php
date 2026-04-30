<?php
/**
 * ARCHIVO DE CONFIGURACIÓN DINÁMICA POR PERIODOS
 * Adaptado para Reforma Laboral (Múltiples vigencias por año)
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config/db.php';

$mensaje = "";

// 1. LÓGICA DE REPARACIÓN DE TABLA (Asegurar que existan las columnas necesarias)
try {
    $pdo->exec("ALTER TABLE config_ley ADD COLUMN IF NOT EXISTS anio VARCHAR(20)");
    $pdo->exec("ALTER TABLE config_ley ADD COLUMN IF NOT EXISTS activa TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE config_ley ADD COLUMN IF NOT EXISTS recargo_nocturno DECIMAL(5,2) DEFAULT 35.00");
    $pdo->exec("ALTER TABLE config_ley ADD COLUMN IF NOT EXISTS recargo_festivo DECIMAL(5,2) DEFAULT 75.00");
    $pdo->exec("ALTER TABLE config_ley ADD COLUMN IF NOT EXISTS recargo_festivo_nocturno DECIMAL(5,2) DEFAULT 110.00");
} catch (Exception $e) { /* Columnas ya existen o error menor */ }

// 2. PROCESAR ACCIONES DE VIGENCIA
if (isset($_POST['accion_vigencia'])) {
    try {
        $anio = $_POST['anio_nombre'];
        $smlv = $_POST['salario_minimo'];
        $aux_t = $_POST['auxilio_transporte'];
        $uvt = $_POST['uvt_valor'];
        $salud = $_POST['salud_empleado'];
        $pension = $_POST['pension_empleado'];
        $rn = $_POST['rec_noc'];
        $rf = $_POST['rec_fes'];
        $rfn = $_POST['rec_fes_noc'];
        $es_activa = isset($_POST['activa']) ? 1 : 0;

        if ($es_activa == 1) {
            $pdo->exec("UPDATE config_ley SET activa = 0");
        }

        if (!empty($_POST['vigencia_id'])) {
            // Actualizar
            $sql = "UPDATE config_ley SET anio=?, salario_minimo=?, auxilio_transporte=?, uvt_valor=?, salud_empleado=?, pension_empleado=?, recargo_nocturno=?, recargo_festivo=?, recargo_festivo_nocturno=?, activa=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$anio, $smlv, $aux_t, $uvt, $salud, $pension, $rn, $rf, $rfn, $es_activa, $_POST['vigencia_id']]);
        } else {
            // Insertar Nueva
            $sql = "INSERT INTO config_ley (anio, salario_minimo, auxilio_transporte, uvt_valor, salud_empleado, pension_empleado, recargo_nocturno, recargo_festivo, recargo_festivo_nocturno, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$anio, $smlv, $aux_t, $uvt, $salud, $pension, $rn, $rf, $rfn, $es_activa]);
        }
        $mensaje = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded shadow-sm'>Vigencia guardada con éxito.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded shadow-sm'>Error: " . $e->getMessage() . "</div>";
    }
}

// 3. ACTUALIZAR EMPLEADO
if (isset($_POST['editar_empleado'])) {
    try {
        $pdo->beginTransaction();
        $stmt1 = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
        $stmt1->execute([$_POST['nombre'], $_POST['cedula'], $_POST['empleado_id']]);
        
        $stmt2 = $pdo->prepare("UPDATE contratos SET salario_base = ?, aux_movilizacion_mensual = ?, aux_mov_nocturno_mensual = ? WHERE empleado_id = ?");
        $stmt2->execute([$_POST['salario'], $_POST['aux_mov'], $_POST['aux_mov_noc'], $_POST['empleado_id']]);
        
        $pdo->commit();
        $mensaje = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded shadow-sm'>Datos de empleado actualizados.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Error: " . $e->getMessage() . "</div>";
    }
}

// Cargar Datos
$vigencias = $pdo->query("SELECT * FROM config_ley ORDER BY id DESC")->fetchAll();
$empleados = $pdo->query("SELECT e.*, c.salario_base, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual 
                          FROM empleados e 
                          LEFT JOIN contratos c ON e.id = c.empleado_id")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Sistema de Nómina</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    </style>
</head>
<body class="antialiased text-slate-800 pb-20">

    <!-- Navbar -->
    <nav class="bg-white/80 backdrop-blur-md border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 h-16 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="bg-indigo-600 p-2 rounded-xl text-white shadow-lg shadow-indigo-200">
                    <i class="fas fa-sliders-h fa-fw"></i>
                </div>
                <h1 class="text-xl font-bold tracking-tight">Parametrización Legal</h1>
            </div>
            <a href="index.php" class="text-sm font-semibold text-slate-500 hover:text-indigo-600 transition flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Panel Principal
            </a>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 mt-8">
        
        <?php echo $mensaje; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- SECCIÓN DE VIGENCIAS (REFORMA) -->
            <div class="lg:col-span-4 space-y-6">
                <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                        <h2 class="font-bold flex items-center gap-2 text-slate-700">
                            <i class="fas fa-calendar-check text-indigo-500"></i> Vigencias de Ley
                        </h2>
                        <button onclick="abrirModalVigencia()" class="bg-indigo-600 text-white text-xs px-3 py-1.5 rounded-lg font-bold hover:bg-indigo-700 transition">
                            <i class="fas fa-plus mr-1"></i> Nueva
                        </button>
                    </div>
                    
                    <div class="p-4 space-y-3">
                        <?php foreach($vigencias as $v): ?>
                        <div class="p-4 rounded-2xl border transition-all <?= $v['activa'] ? 'border-indigo-500 bg-indigo-50/40 ring-1 ring-indigo-500' : 'border-slate-100 bg-white hover:border-slate-300' ?>">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-black text-lg text-slate-800"><?= $v['anio'] ?></span>
                                        <?php if($v['activa']): ?>
                                            <span class="text-[9px] bg-indigo-600 text-white px-2 py-0.5 rounded-full uppercase font-black tracking-tighter">Vigencia Activa</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[10px] text-slate-500 mt-1 font-medium">
                                        SMLV: $<?= number_format($v['salario_minimo'], 0) ?> | Transp: $<?= number_format($v['auxilio_transporte'], 0) ?>
                                    </div>
                                    <div class="flex gap-2 mt-2">
                                        <span class="text-[9px] px-2 py-0.5 bg-slate-100 rounded-md text-slate-600 font-bold">RN: <?= $v['recargo_nocturno'] ?>%</span>
                                        <span class="text-[9px] px-2 py-0.5 bg-slate-100 rounded-md text-slate-600 font-bold">Fes: <?= $v['recargo_festivo'] ?>%</span>
                                    </div>
                                </div>
                                <button onclick='abrirModalVigencia(<?= json_encode($v) ?>)' class="text-slate-400 hover:text-indigo-600 transition p-1">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN DE EMPLEADOS -->
            <div class="lg:col-span-8">
                <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 bg-slate-50/50">
                        <h2 class="font-bold text-slate-700 flex items-center gap-2">
                            <i class="fas fa-user-tie text-indigo-500"></i> Configuración por Empleado
                        </h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50/50 text-[10px] font-black uppercase text-slate-400 tracking-widest border-b border-slate-100">
                                <tr>
                                    <th class="px-6 py-4">Colaborador</th>
                                    <th class="px-6 py-4 text-right">Salario Base</th>
                                    <th class="px-6 py-4">Auxilios Mensuales</th>
                                    <th class="px-6 py-4 text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach($empleados as $e): ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-slate-800"><?= $e['nombre_completo'] ?></div>
                                        <div class="text-xs text-slate-400">CC: <?= $e['cedula'] ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-right font-bold text-indigo-600 text-sm">
                                        $<?= number_format($e['salario_base'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-col gap-1">
                                            <span class="text-[10px] font-bold text-slate-500"><i class="fas fa-car mr-1 opacity-50"></i> Mov: $<?= number_format($e['aux_movilizacion_mensual'], 0) ?></span>
                                            <span class="text-[10px] font-bold text-slate-500"><i class="fas fa-moon mr-1 opacity-50"></i> Noc: $<?= number_format($e['aux_mov_nocturno_mensual'], 0) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <button onclick='abrirModalEmp(<?= json_encode($e) ?>)' class="bg-slate-100 text-slate-600 hover:bg-indigo-600 hover:text-white w-9 h-9 rounded-xl transition flex items-center justify-center mx-auto shadow-sm">
                                            <i class="fas fa-pencil-alt text-sm"></i>
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
    </main>

    <!-- MODAL VIGENCIA -->
    <div id="modalVigencia" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-xl overflow-hidden border border-white/20 transform transition-all">
            <form action="" method="POST">
                <div class="p-6 bg-indigo-600 text-white flex justify-between items-center">
                    <h3 class="font-bold text-lg" id="v_titulo">Nueva Vigencia</h3>
                    <button type="button" onclick="cerrarModal('modalVigencia')" class="hover:rotate-90 transition duration-300"><i class="fas fa-times"></i></button>
                </div>
                <div class="p-8 grid grid-cols-2 gap-6">
                    <input type="hidden" name="vigencia_id" id="v_id">
                    <div class="col-span-2 flex gap-4 items-end">
                        <div class="flex-1">
                            <label class="block text-xs font-black text-slate-400 uppercase mb-2">Nombre del Periodo (Ej: 2026-1)</label>
                            <input type="text" name="anio_nombre" id="v_anio" required placeholder="2026-2" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3 outline-none focus:ring-4 focus:ring-indigo-100 transition">
                        </div>
                        <div class="flex items-center gap-3 bg-slate-50 p-3 rounded-2xl border border-slate-200 mb-0.5">
                            <label class="text-xs font-black text-slate-500 uppercase">Activa</label>
                            <input type="checkbox" name="activa" id="v_activa" class="w-5 h-5 accent-indigo-600">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase mb-2">SMLV Vigente</label>
                        <input type="number" name="salario_minimo" id="v_smlv" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3 text-indigo-600 font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase mb-2">Auxilio Transporte</label>
                        <input type="number" name="auxilio_transporte" id="v_aux" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-5 py-3">
                    </div>

                    <div class="col-span-2 p-5 bg-indigo-50/50 rounded-[1.5rem] border border-indigo-100 grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-bold text-indigo-400 uppercase mb-1">Rec. Nocturno %</label>
                            <input type="number" step="0.01" name="rec_noc" id="v_rn" class="w-full bg-white border border-indigo-100 rounded-xl px-3 py-2 text-sm font-bold text-indigo-700">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-indigo-400 uppercase mb-1">Festivo Diurno %</label>
                            <input type="number" step="0.01" name="rec_fes" id="v_rf" class="w-full bg-white border border-indigo-100 rounded-xl px-3 py-2 text-sm font-bold text-indigo-700">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-indigo-400 uppercase mb-1">Festivo Noc %</label>
                            <input type="number" step="0.01" name="rec_fes_noc" id="v_rfn" class="w-full bg-white border border-indigo-100 rounded-xl px-3 py-2 text-sm font-bold text-indigo-700">
                        </div>
                        <input type="hidden" name="uvt_valor" id="v_uvt" value="47065">
                        <input type="hidden" name="salud_empleado" id="v_salud" value="4">
                        <input type="hidden" name="pension_empleado" id="v_pension" value="4">
                    </div>

                    <button type="submit" name="accion_vigencia" class="col-span-2 bg-indigo-600 text-white py-4 rounded-2xl font-bold text-lg shadow-xl shadow-indigo-100 hover:bg-indigo-700 hover:-translate-y-1 transition active:scale-95">
                        Guardar Parametrización
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EMPLEADO -->
    <div id="modalEmp" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-lg overflow-hidden border border-white/20">
            <form action="" method="POST" class="p-8 space-y-6">
                <div class="flex justify-between items-center mb-2">
                    <h3 class="font-bold text-xl text-slate-800">Perfil y Contrato</h3>
                    <button type="button" onclick="cerrarModal('modalEmp')" class="text-slate-400"><i class="fas fa-times"></i></button>
                </div>
                <input type="hidden" name="empleado_id" id="e_id">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase mb-2 ml-1">Nombre Completo</label>
                        <input type="text" name="nombre" id="e_nombre" class="w-full border-slate-200 bg-slate-50 border rounded-2xl px-5 py-3 outline-none focus:ring-4 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase mb-2 ml-1">Documento de Identidad</label>
                        <input type="text" name="cedula" id="e_cedula" class="w-full border-slate-200 bg-slate-50 border rounded-2xl px-5 py-3 outline-none focus:ring-4 focus:ring-indigo-100">
                    </div>
                    <div class="pt-4">
                        <label class="block text-xs font-black text-indigo-400 uppercase mb-3 ml-1">Condiciones Económicas</label>
                        <div class="bg-indigo-50/50 p-5 rounded-3xl border border-indigo-100 space-y-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1">Salario Base Mensual</label>
                                <input type="number" name="salario" id="e_salario" class="w-full bg-white border border-indigo-200 rounded-xl px-4 py-2 font-bold text-indigo-600">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 mb-1">Aux. Movilidad</label>
                                    <input type="number" name="aux_mov" id="e_aux_mov" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-slate-500 mb-1">Aux. Nocturno</label>
                                    <input type="number" name="aux_mov_noc" id="e_aux_noc" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2 text-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" name="editar_empleado" class="w-full bg-slate-800 text-white py-4 rounded-2xl font-bold hover:bg-slate-900 transition shadow-lg">Actualizar Colaborador</button>
            </form>
        </div>
    </div>

    <script>
        function abrirModalVigencia(v = null) {
            document.getElementById('v_id').value = v ? v.id : '';
            document.getElementById('v_anio').value = v ? v.anio : '';
            document.getElementById('v_smlv').value = v ? v.salario_minimo : 1300000;
            document.getElementById('v_aux').value = v ? v.auxilio_transporte : 162000;
            document.getElementById('v_rn').value = v ? v.recargo_nocturno : 35;
            document.getElementById('v_rf').value = v ? v.recargo_festivo : 75;
            document.getElementById('v_rfn').value = v ? v.recargo_festivo_nocturno : 110;
            document.getElementById('v_activa').checked = v ? (v.activa == 1) : false;
            document.getElementById('v_titulo').innerText = v ? 'Editar Vigencia ' + v.anio : 'Nueva Vigencia Laboral';
            
            document.getElementById('modalVigencia').classList.remove('hidden');
            document.getElementById('modalVigencia').classList.add('flex');
        }

        function abrirModalEmp(e) {
            document.getElementById('e_id').value = e.id;
            document.getElementById('e_nombre').value = e.nombre_completo;
            document.getElementById('e_cedula').value = e.cedula;
            document.getElementById('e_salario').value = e.salario_base;
            document.getElementById('e_aux_mov').value = e.aux_movilizacion_mensual || 0;
            document.getElementById('e_aux_noc').value = e.aux_mov_nocturno_mensual || 0;
            
            document.getElementById('modalEmp').classList.remove('hidden');
            document.getElementById('modalEmp').classList.add('flex');
        }

        function cerrarModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }
    </script>

</body>
</html>
