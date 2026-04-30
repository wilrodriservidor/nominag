<?php
/**
 * ARCHIVO DE CONFIGURACIÓN DINÁMICA POR PERIODOS
 * Solución al Error SQLSTATE[42S22]: Column not found
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config/db.php';

$mensaje = "";

/**
 * 1. REPARACIÓN Y NORMALIZACIÓN DE LA TABLA
 * Forzamos que la tabla tenga los nombres estándar que usaremos en el sistema.
 */
try {
    // Verificar si la tabla existe, si no, crearla
    $pdo->exec("CREATE TABLE IF NOT EXISTS config_ley (id INT AUTO_INCREMENT PRIMARY KEY)");
    
    // Lista de columnas necesarias y sus tipos
    $columnas_necesarias = [
        'anio' => "VARCHAR(20) DEFAULT '2026'",
        'salario_minimo' => "DECIMAL(15,2) DEFAULT 1300000",
        'auxilio_transporte' => "DECIMAL(15,2) DEFAULT 162000",
        'uvt_valor' => "DECIMAL(15,2) DEFAULT 47065",
        'salud_empleado' => "DECIMAL(5,2) DEFAULT 4.00",
        'pension_empleado' => "DECIMAL(5,2) DEFAULT 4.00",
        'recargo_nocturno' => "DECIMAL(5,2) DEFAULT 35.00",
        'recargo_festivo' => "DECIMAL(5,2) DEFAULT 75.00",
        'recargo_festivo_nocturno' => "DECIMAL(5,2) DEFAULT 110.00",
        'activa' => "TINYINT(1) DEFAULT 0"
    ];

    // Obtener columnas actuales para no duplicar ni errar
    $rs = $pdo->query("SHOW COLUMNS FROM config_ley");
    $columnas_existentes = $rs->fetchAll(PDO::FETCH_COLUMN);

    foreach ($columnas_necesarias as $col => $definicion) {
        if (!in_array($col, $columnas_existentes)) {
            $pdo->exec("ALTER TABLE config_ley ADD COLUMN $col $definicion");
        }
    }
} catch (Exception $e) {
    $mensaje = "<div class='bg-orange-100 p-4 mb-4 text-orange-700 rounded-xl border border-orange-200'>Nota: Se intentó normalizar la estructura de la tabla.</div>";
}

// 2. PROCESAR ACCIONES DE VIGENCIA (GUARDAR / EDITAR)
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

        // Si esta se activa, desactivamos el resto
        if ($es_activa == 1) {
            $pdo->exec("UPDATE config_ley SET activa = 0");
        }

        if (!empty($_POST['vigencia_id'])) {
            $sql = "UPDATE config_ley SET 
                    anio=?, salario_minimo=?, auxilio_transporte=?, uvt_valor=?, 
                    salud_empleado=?, pension_empleado=?, recargo_nocturno=?, 
                    recargo_festivo=?, recargo_festivo_nocturno=?, activa=? 
                    WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$anio, $smlv, $aux_t, $uvt, $salud, $pension, $rn, $rf, $rfn, $es_activa, $_POST['vigencia_id']]);
        } else {
            $sql = "INSERT INTO config_ley (anio, salario_minimo, auxilio_transporte, uvt_valor, salud_empleado, pension_empleado, recargo_nocturno, recargo_festivo, recargo_festivo_nocturno, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$anio, $smlv, $aux_t, $uvt, $salud, $pension, $rn, $rf, $rfn, $es_activa]);
        }
        $mensaje = "<div class='bg-emerald-50 border border-emerald-200 text-emerald-700 p-4 mb-6 rounded-2xl shadow-sm flex items-center gap-3'><i class='fas fa-check-circle'></i> Configuración actualizada correctamente.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-50 border border-red-200 text-red-700 p-4 mb-6 rounded-2xl shadow-sm'><b>Error de Base de Datos:</b> " . $e->getMessage() . "</div>";
    }
}

// 3. ACTUALIZAR DATOS DE EMPLEADO
if (isset($_POST['editar_empleado'])) {
    try {
        $pdo->beginTransaction();
        $stmt1 = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
        $stmt1->execute([$_POST['nombre'], $_POST['cedula'], $_POST['empleado_id']]);
        
        $stmt2 = $pdo->prepare("UPDATE contratos SET salario_base = ?, aux_movilizacion_mensual = ?, aux_mov_nocturno_mensual = ? WHERE empleado_id = ?");
        $stmt2->execute([$_POST['salario'], $_POST['aux_mov'], $_POST['aux_mov_noc'], $_POST['empleado_id']]);
        
        $pdo->commit();
        $mensaje = "<div class='bg-indigo-50 border border-indigo-200 text-indigo-700 p-4 mb-6 rounded-2xl shadow-sm'>Perfil de empleado actualizado con éxito.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='bg-red-50 p-4 mb-6 text-red-700 rounded-2xl border border-red-200'>Error: " . $e->getMessage() . "</div>";
    }
}

// Cargar Datos para la vista
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
    <title>Configuración de Nómina</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    </style>
</head>
<body class="pb-20">

    <nav class="bg-white/80 backdrop-blur-md border-b sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 h-20 flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div class="bg-indigo-600 w-12 h-12 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-indigo-100">
                    <i class="fas fa-cogs text-xl"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-slate-900 leading-tight">Panel de Configuración</h1>
                    <p class="text-xs text-slate-500 font-medium">Gestión de periodos y empleados</p>
                </div>
            </div>
            <a href="index.php" class="bg-slate-100 text-slate-600 px-5 py-2.5 rounded-2xl text-sm font-bold hover:bg-slate-200 transition">
                <i class="fas fa-home mr-2"></i> Inicio
            </a>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 mt-10">
        
        <?php echo $mensaje; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
            
            <!-- VIGENCIAS -->
            <div class="lg:col-span-4 space-y-8">
                <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-8 border-b border-slate-100 flex justify-between items-center">
                        <h2 class="font-bold text-slate-800 tracking-tight">Periodos de Ley</h2>
                        <button onclick="abrirModalVigencia()" class="bg-indigo-600 text-white p-2 rounded-xl hover:bg-indigo-700 transition">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    
                    <div class="p-6 space-y-4">
                        <?php foreach($vigencias as $v): ?>
                        <div class="group relative p-5 rounded-3xl border transition-all <?= $v['activa'] ? 'border-indigo-600 bg-indigo-50/30' : 'border-slate-100 bg-white hover:border-slate-300' ?>">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-black text-xl text-slate-900"><?= htmlspecialchars($v['anio']) ?></span>
                                        <?php if($v['activa']): ?>
                                            <span class="bg-indigo-600 text-white text-[8px] font-black px-2 py-0.5 rounded-full uppercase">Activo</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-1 font-medium italic">SMLV: $<?= number_format($v['salario_minimo'], 0) ?></p>
                                </div>
                                <button onclick='abrirModalVigencia(<?= json_encode($v) ?>)' class="w-10 h-10 rounded-xl bg-white shadow-sm border border-slate-100 flex items-center justify-center text-slate-400 hover:text-indigo-600 hover:border-indigo-200 transition">
                                    <i class="fas fa-edit text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- TABLA EMPLEADOS -->
            <div class="lg:col-span-8">
                <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-8 border-b border-slate-100">
                        <h2 class="font-bold text-slate-800 tracking-tight">Personal & Salarios</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50/80 text-[10px] font-black uppercase text-slate-400 tracking-widest">
                                <tr>
                                    <th class="px-8 py-5">Colaborador</th>
                                    <th class="px-8 py-5 text-right">Base Salarial</th>
                                    <th class="px-8 py-5">Auxilios Extra</th>
                                    <th class="px-8 py-5 text-center">Gestión</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach($empleados as $e): ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="px-8 py-6">
                                        <div class="font-bold text-slate-900"><?= $e['nombre_completo'] ?></div>
                                        <div class="text-[11px] text-slate-400 font-medium">CC: <?= $e['cedula'] ?></div>
                                    </td>
                                    <td class="px-8 py-6 text-right">
                                        <span class="font-black text-indigo-600">$<?= number_format($e['salario_base'], 0, ',', '.') ?></span>
                                    </td>
                                    <td class="px-8 py-6">
                                        <div class="space-y-1">
                                            <div class="text-[10px] font-bold text-slate-500 uppercase"><i class="fas fa-truck mr-1 text-indigo-300"></i> Mov: $<?= number_format($e['aux_movilizacion_mensual'], 0) ?></div>
                                            <div class="text-[10px] font-bold text-slate-500 uppercase"><i class="fas fa-moon mr-1 text-indigo-300"></i> Noc: $<?= number_format($e['aux_mov_nocturno_mensual'], 0) ?></div>
                                        </div>
                                    </td>
                                    <td class="px-8 py-6 text-center">
                                        <button onclick='abrirModalEmp(<?= json_encode($e) ?>)' class="bg-white border border-slate-200 text-slate-400 hover:text-indigo-600 hover:border-indigo-600 w-10 h-10 rounded-xl transition flex items-center justify-center mx-auto shadow-sm">
                                            <i class="fas fa-user-edit text-sm"></i>
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

    <!-- MODALES (Ocultos por defecto) -->
    <div id="modalVigencia" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-[3rem] shadow-2xl w-full max-w-xl overflow-hidden border border-white/20">
            <form action="" method="POST">
                <div class="p-8 bg-indigo-600 text-white flex justify-between items-center">
                    <h3 class="font-black text-xl" id="v_titulo">Periodo Fiscal</h3>
                    <button type="button" onclick="cerrarModal('modalVigencia')" class="hover:rotate-90 transition duration-300"><i class="fas fa-times text-2xl"></i></button>
                </div>
                <div class="p-10 space-y-8">
                    <input type="hidden" name="vigencia_id" id="v_id">
                    
                    <div class="grid grid-cols-2 gap-6">
                        <div class="col-span-2 flex gap-4 items-end">
                            <div class="flex-1">
                                <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2">Nombre Vigencia (Ej: 2026-A)</label>
                                <input type="text" name="anio_nombre" id="v_anio" required class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 outline-none focus:ring-4 focus:ring-indigo-100 font-bold">
                            </div>
                            <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 flex items-center gap-3">
                                <span class="text-[10px] font-black text-slate-500 uppercase">Activa</span>
                                <input type="checkbox" name="activa" id="v_activa" class="w-6 h-6 accent-indigo-600">
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2">Salario Mínimo (SMLV)</label>
                            <input type="number" name="salario_minimo" id="v_smlv" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 font-black text-indigo-600">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-3 ml-2">Auxilio Transporte</label>
                            <input type="number" name="auxilio_transporte" id="v_aux" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 font-bold">
                        </div>
                    </div>

                    <div class="bg-indigo-50/50 p-8 rounded-[2rem] border border-indigo-100">
                        <h4 class="text-[10px] font-black text-indigo-400 uppercase mb-6 flex items-center gap-2">
                            <i class="fas fa-percent"></i> Recargos de Ley (Reforma 2026)
                        </h4>
                        <div class="grid grid-cols-3 gap-6">
                            <div>
                                <label class="block text-[9px] font-black text-slate-500 mb-2">Nocturno %</label>
                                <input type="number" step="0.01" name="rec_noc" id="v_rn" class="w-full bg-white border border-indigo-200 rounded-xl px-4 py-3 text-sm font-black text-indigo-700">
                            </div>
                            <div>
                                <label class="block text-[9px] font-black text-slate-500 mb-2">Festivo %</label>
                                <input type="number" step="0.01" name="rec_fes" id="v_rf" class="w-full bg-white border border-indigo-200 rounded-xl px-4 py-3 text-sm font-black text-indigo-700">
                            </div>
                            <div>
                                <label class="block text-[9px] font-black text-slate-500 mb-2">Fes. Noc %</label>
                                <input type="number" step="0.01" name="rec_fes_noc" id="v_rfn" class="w-full bg-white border border-indigo-200 rounded-xl px-4 py-3 text-sm font-black text-indigo-700">
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="uvt_valor" value="47065">
                    <input type="hidden" name="salud_empleado" value="4">
                    <input type="hidden" name="pension_empleado" value="4">

                    <button type="submit" name="accion_vigencia" class="w-full bg-indigo-600 text-white py-5 rounded-[1.5rem] font-black text-lg shadow-xl shadow-indigo-100 hover:bg-indigo-700 hover:-translate-y-1 transition active:scale-95">
                        Confirmar Parámetros
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EMPLEADO -->
    <div id="modalEmp" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-[3rem] shadow-2xl w-full max-w-lg overflow-hidden border border-white/20">
            <form action="" method="POST">
                <div class="p-8 bg-slate-800 text-white flex justify-between items-center">
                    <h3 class="font-black text-xl">Perfil del Empleado</h3>
                    <button type="button" onclick="cerrarModal('modalEmp')" class="hover:opacity-50 transition"><i class="fas fa-times text-2xl"></i></button>
                </div>
                <div class="p-10 space-y-6">
                    <input type="hidden" name="empleado_id" id="e_id">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2">Nombre Completo</label>
                            <input type="text" name="nombre" id="e_nombre" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 outline-none focus:ring-4 focus:ring-slate-100 font-bold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-400 uppercase mb-2 ml-2">Cédula</label>
                            <input type="text" name="cedula" id="e_cedula" class="w-full bg-slate-50 border border-slate-200 rounded-2xl px-6 py-4 font-bold">
                        </div>
                        
                        <div class="pt-6">
                            <div class="bg-slate-50 p-8 rounded-[2rem] border border-slate-100 space-y-6">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 mb-2 uppercase">Salario Base Pactado</label>
                                    <input type="number" name="salario" id="e_salario" class="w-full bg-white border border-slate-200 rounded-xl px-5 py-4 font-black text-indigo-600 text-lg">
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-[9px] font-black text-slate-400 mb-2 uppercase">Aux. Movilidad</label>
                                        <input type="number" name="aux_mov" id="e_aux_mov" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold">
                                    </div>
                                    <div>
                                        <label class="block text-[9px] font-black text-slate-400 mb-2 uppercase">Aux. Nocturno</label>
                                        <input type="number" name="aux_mov_noc" id="e_aux_noc" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm font-bold">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="editar_empleado" class="w-full bg-slate-900 text-white py-5 rounded-[1.5rem] font-black text-lg shadow-xl hover:bg-black transition active:scale-95">
                        Actualizar Datos
                    </button>
                </div>
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
            
            const modal = document.getElementById('modalVigencia');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function abrirModalEmp(e) {
            document.getElementById('e_id').value = e.id;
            document.getElementById('e_nombre').value = e.nombre_completo;
            document.getElementById('e_cedula').value = e.cedula;
            document.getElementById('e_salario').value = e.salario_base;
            document.getElementById('e_aux_mov').value = e.aux_movilizacion_mensual || 0;
            document.getElementById('e_aux_noc').value = e.aux_mov_nocturno_mensual || 0;
            
            const modal = document.getElementById('modalEmp');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function cerrarModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }
    </script>

</body>
</html>
