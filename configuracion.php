<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config/db.php';

$mensaje = "";

// --- LÓGICA DE CREACIÓN DE EMPLEADO Y CONTRATO ---
if (isset($_POST['crear_empleado'])) {
    try {
        $pdo->beginTransaction();

        // 1. Insertar en tabla 'empleados'
        $stmt_emp = $pdo->prepare("INSERT INTO empleados (cedula, nombre_completo, fecha_ingreso) VALUES (?, ?, ?)");
        $stmt_emp->execute([
            $_POST['cedula'],
            $_POST['nombre_completo'],
            $_POST['fecha_ingreso']
        ]);
        $empleado_id = $pdo->lastInsertId();

        // 2. Insertar en tabla 'contratos' (Siguiendo campos reales de la BD)
        // REGLA DE ORO: Se almacenan valores MENSUALES. El sistema de nómina dividirá por quincena automáticamente.
        $stmt_con = $pdo->prepare("
            INSERT INTO contratos (
                empleado_id, salario_base, es_direccion_confianza, 
                aux_movilizacion_mensual, aux_mov_nocturno_mensual, 
                fecha_inicio, activo
            ) VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt_con->execute([
            $empleado_id,
            $_POST['salario_base'],
            isset($_POST['es_direccion_confianza']) ? 1 : 0,
            $_POST['aux_movilizacion_mensual'] ?? 0,
            $_POST['aux_mov_nocturno_mensual'] ?? 0,
            $_POST['fecha_ingreso']
        ]);

        $pdo->commit();
        $mensaje = "<div class='bg-emerald-100 text-emerald-700 p-4 rounded-xl mb-6 border border-emerald-200'>¡Empleado creado! Los auxilios se parametrizaron mensualmente para división automática.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6 border border-red-200'>Error: " . $e->getMessage() . "</div>";
    }
}

// --- LÓGICA DE EDICIÓN ---
if (isset($_POST['editar_empleado'])) {
    try {
        $pdo->beginTransaction();
        
        // Actualizar Empleado
        $stmt_u_emp = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
        $stmt_u_emp->execute([$_POST['nombre_completo'], $_POST['cedula'], $_POST['id']]);

        // Actualizar Contrato
        $stmt_u_con = $pdo->prepare("
            UPDATE contratos 
            SET salario_base = ?, 
                aux_movilizacion_mensual = ?, 
                aux_mov_nocturno_mensual = ? 
            WHERE empleado_id = ? AND activo = 1
        ");
        $stmt_u_con->execute([
            $_POST['salario_base'],
            $_POST['aux_movilizacion_mensual'],
            $_POST['aux_mov_nocturno_mensual'],
            $_POST['id']
        ]);

        $pdo->commit();
        $mensaje = "<div class='bg-blue-100 text-blue-700 p-4 rounded-xl mb-6 border border-blue-200'>Cambios guardados correctamente.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6 border border-red-200'>Error al actualizar: " . $e->getMessage() . "</div>";
    }
}

// --- ACTUALIZAR PARÁMETROS DE LEY ---
if (isset($_POST['actualizar_ley'])) {
    try {
        $stmt_ley = $pdo->prepare("UPDATE parametros_ley SET valor_smlv = ?, subsidio_transporte = ?, recargo_nocturno = ?, recargo_festivo = ? WHERE id = 1");
        $stmt_ley->execute([$_POST['smlv'], $_POST['sub_trans'], $_POST['p_rn'], $_POST['p_rf']]);
        $mensaje = "<div class='bg-indigo-100 text-indigo-700 p-4 rounded-xl mb-6 border border-indigo-200'>Parámetros legales actualizados para 2026.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6 border border-red-200'>Error: " . $e->getMessage() . "</div>";
    }
}

// Consultar empleados con sus contratos activos
$query = "
    SELECT e.*, c.salario_base, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual, c.es_direccion_confianza 
    FROM empleados e
    LEFT JOIN contratos c ON e.id = c.empleado_id AND c.activo = 1
    ORDER BY e.nombre_completo ASC";
$empleados = $pdo->query($query)->fetchAll();

$config_ley = $pdo->query("SELECT * FROM parametros_ley LIMIT 1")->fetch();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Nómina Gemini</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .modal { transition: all 0.3s ease; }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900">

    <div class="max-w-7xl mx-auto px-4 py-12">
        <!-- HEADER -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-4">
            <div>
                <h1 class="text-4xl font-extrabold tracking-tight text-slate-900">Configuración</h1>
                <p class="text-slate-500 font-medium">Gestione el personal y los parámetros de ley para las quincenas.</p>
            </div>
            <div class="flex gap-3">
                <button onclick="abrirModalCrear()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-3 rounded-2xl shadow-lg shadow-indigo-100 transition-all flex items-center gap-2">
                    <i class="fas fa-user-plus"></i> NUEVO EMPLEADO
                </button>
                <a href="index.php" class="bg-white border border-slate-200 text-slate-600 font-bold px-6 py-3 rounded-2xl hover:bg-slate-50 transition-all">
                    <i class="fas fa-arrow-left mr-2"></i> VOLVER
                </a>
            </div>
        </div>

        <?= $mensaje ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- LISTADO DE EMPLEADOS -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-[2rem] shadow-sm border border-slate-200 overflow-hidden">
                    <div class="px-8 py-6 border-b border-slate-100 flex justify-between items-center">
                        <h3 class="font-bold text-slate-800">Personal Registrado</h3>
                        <span class="text-xs font-bold text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full uppercase tracking-wider"><?= count($empleados) ?> Colaboradores</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th class="px-8 py-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest">Colaborador</th>
                                    <th class="px-8 py-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest">Compensación Mensual</th>
                                    <th class="px-8 py-4 text-[11px] font-bold text-slate-400 uppercase tracking-widest">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach($empleados as $emp): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-8 py-5">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold text-sm">
                                                <?= strtoupper(substr($emp['nombre_completo'], 0, 2)) ?>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-800 text-sm"><?= $emp['nombre_completo'] ?></p>
                                                <p class="text-xs text-slate-400 font-medium">CC: <?= $emp['cedula'] ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-8 py-5">
                                        <p class="font-bold text-slate-700 text-sm">$<?= number_format($emp['salario_base'], 0) ?></p>
                                        <p class="text-[10px] text-emerald-600 font-bold uppercase">+ Auxilios Movilidad</p>
                                    </td>
                                    <td class="px-8 py-5">
                                        <button onclick='abrirModalEditar(<?= json_encode($emp) ?>)' class="w-9 h-9 rounded-xl bg-slate-100 text-slate-600 hover:bg-indigo-600 hover:text-white transition-all flex items-center justify-center shadow-sm">
                                            <i class="fas fa-pen text-xs"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- PARÁMETROS LEGALES -->
            <div class="space-y-6">
                <div class="bg-slate-900 rounded-[2rem] p-8 shadow-xl border border-slate-800">
                    <div class="flex items-center gap-3 mb-8">
                        <div class="w-10 h-10 rounded-xl bg-indigo-500/20 flex items-center justify-center text-indigo-400">
                            <i class="fas fa-gavel"></i>
                        </div>
                        <h3 class="font-bold text-white">Variables de Ley 2026</h3>
                    </div>
                    <form action="" method="POST" class="space-y-5">
                        <div class="space-y-2">
                            <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Salario Mínimo Mensual</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">$</span>
                                <input type="number" name="smlv" value="<?= $config_ley['valor_smlv'] ?>" class="w-full bg-slate-800 border border-slate-700 rounded-xl py-3 pl-8 pr-4 text-white font-bold focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">Auxilio de Transporte</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">$</span>
                                <input type="number" name="sub_trans" value="<?= $config_ley['subsidio_transporte'] ?>" class="w-full bg-slate-800 border border-slate-700 rounded-xl py-3 pl-8 pr-4 text-white font-bold focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">% Rec. Nocturno</label>
                                <input type="number" step="0.01" name="p_rn" value="<?= $config_ley['recargo_nocturno'] ?>" class="w-full bg-slate-800 border border-slate-700 rounded-xl py-3 px-4 text-white font-bold focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest ml-1">% Rec. Festivo</label>
                                <input type="number" step="0.01" name="p_rf" value="<?= $config_ley['recargo_festivo'] ?>" class="w-full bg-slate-800 border border-slate-700 rounded-xl py-3 px-4 text-white font-bold focus:ring-2 focus:ring-indigo-500 outline-none transition-all">
                            </div>
                        </div>
                        <button type="submit" name="actualizar_ley" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-4 rounded-2xl shadow-lg shadow-indigo-900/20 transition-all mt-4">
                            GUARDAR PARÁMETROS
                        </button>
                    </form>
                </div>

                <div class="bg-indigo-50 rounded-[2rem] p-6 border border-indigo-100">
                    <p class="text-xs text-indigo-700 leading-relaxed">
                        <i class="fas fa-info-circle mr-1"></i> <b>Nota Importante:</b> Al definir los auxilios mensuales, el sistema de nómina calcula automáticamente la parte proporcional a los 15 días de la quincena.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR -->
    <div id="modalCrear" class="modal fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm opacity-0 pointer-events-none p-4">
        <div class="bg-white w-full max-w-2xl rounded-[2.5rem] shadow-2xl overflow-hidden translate-y-4 transition-transform duration-300">
            <div class="px-10 py-8 border-b border-slate-100 flex justify-between items-center bg-indigo-600 text-white">
                <div>
                    <h3 class="text-2xl font-extrabold uppercase tracking-tight">Nuevo Ingreso</h3>
                    <p class="text-indigo-100 text-[10px] font-bold uppercase tracking-widest mt-1">Parametrización Contractual Mensual</p>
                </div>
                <button onclick="cerrarModal('modalCrear')" class="text-white/50 hover:text-white transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <form action="" method="POST" class="p-10">
                <input type="hidden" name="crear_empleado" value="1">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Columna 1 -->
                    <div class="space-y-5">
                        <h4 class="text-indigo-600 font-bold text-xs uppercase tracking-widest border-b pb-2">Identificación</h4>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Nombre Completo</label>
                            <input type="text" name="nombre_completo" required placeholder="Ej: Juan Pérez" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Número de Cédula</label>
                            <input type="text" name="cedula" required placeholder="00000000" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Fecha de Ingreso</label>
                            <input type="date" name="fecha_ingreso" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                    </div>
                    <!-- Columna 2 -->
                    <div class="space-y-5">
                        <h4 class="text-emerald-600 font-bold text-xs uppercase tracking-widest border-b pb-2">Compensación Mensual</h4>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Salario Base (100%)</label>
                            <input type="number" name="salario_base" required value="1300000" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Auxilio Mov. Diurno (Mes)</label>
                            <input type="number" name="aux_movilizacion_mensual" value="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Auxilio Mov. Nocturno (Mes)</label>
                            <input type="number" name="aux_mov_nocturno_mensual" value="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                        <div class="flex items-center gap-3 pt-2">
                            <input type="checkbox" name="es_direccion_confianza" id="chk_dir" class="w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <label for="chk_dir" class="text-xs font-bold text-slate-600 uppercase">Cargo de Confianza</label>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-10">
                    <button type="button" onclick="cerrarModal('modalCrear')" class="px-6 py-3 font-bold text-slate-400 hover:text-slate-600 transition-colors">DESCARTAR</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-10 py-3 rounded-2xl shadow-xl shadow-indigo-100 transition-all">REGISTRAR EMPLEADO</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR -->
    <div id="modalEditar" class="modal fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm opacity-0 pointer-events-none p-4">
        <div class="bg-white w-full max-w-xl rounded-[2.5rem] shadow-2xl overflow-hidden translate-y-4 transition-transform duration-300">
            <div class="px-8 py-6 border-b border-slate-100 bg-slate-900 text-white">
                <h3 class="text-xl font-bold uppercase tracking-tight" id="edit_title">Ajustar Parámetros</h3>
            </div>
            <form action="" method="POST" class="p-8">
                <input type="hidden" name="editar_empleado" value="1">
                <input type="hidden" name="id" id="edit_id">
                <div class="space-y-5">
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Nombre Completo</label>
                        <input type="text" name="nombre_completo" id="edit_nombre" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Cédula</label>
                            <input type="text" name="cedula" id="edit_cedula" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Salario Base (Mes)</label>
                            <input type="number" name="salario_base" id="edit_salario" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Auxilio Mov. Diurno</label>
                            <input type="number" name="aux_movilizacion_mensual" id="edit_aux_mov" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase block mb-1">Auxilio Mov. Nocturno</label>
                            <input type="number" name="aux_mov_nocturno_mensual" id="edit_aux_mov_noc" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 focus:border-indigo-500 outline-none transition-all">
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-8 pt-6 border-t">
                    <button type="button" onclick="cerrarModal('modalEditar')" class="px-6 py-3 font-bold text-slate-400">CANCELAR</button>
                    <button type="submit" class="bg-slate-900 text-white font-bold px-10 py-3 rounded-2xl shadow-xl hover:bg-black transition-all">ACTUALIZAR DATOS</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalCrear() {
            const modal = document.getElementById('modalCrear');
            const inner = modal.querySelector('div');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            inner.classList.remove('translate-y-4');
        }

        function abrirModalEditar(emp) {
            document.getElementById('edit_id').value = emp.id;
            document.getElementById('edit_nombre').value = emp.nombre_completo;
            document.getElementById('edit_cedula').value = emp.cedula;
            document.getElementById('edit_salario').value = emp.salario_base;
            document.getElementById('edit_aux_mov').value = emp.aux_movilizacion_mensual || 0;
            document.getElementById('edit_aux_mov_noc').value = emp.aux_mov_nocturno_mensual || 0;

            const modal = document.getElementById('modalEditar');
            const inner = modal.querySelector('div');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            inner.classList.remove('translate-y-4');
        }

        function cerrarModal(id) {
            const modal = document.getElementById(id);
            const inner = modal.querySelector('div');
            modal.classList.add('opacity-0', 'pointer-events-none');
            inner.classList.add('translate-y-4');
        }
    </script>
</body>
</html>
