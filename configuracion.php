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
        $mensaje = "<div class='bg-emerald-100 text-emerald-700 p-4 rounded-xl mb-6 border border-emerald-200'>¡Empleado y Contrato creados exitosamente!</div>";
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

        // Actualizar Contrato (Campos: salario_base, aux_movilizacion_mensual, aux_mov_nocturno_mensual)
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
        $mensaje = "<div class='bg-blue-100 text-blue-700 p-4 rounded-xl mb-6 border border-blue-200'>Datos actualizados correctamente.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6 border border-red-200'>Error al actualizar: " . $e->getMessage() . "</div>";
    }
}

// Consultar empleados con sus contratos activos
$query = "
    SELECT e.*, c.salario_base, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual, c.es_direccion_confianza 
    FROM empleados e
    LEFT JOIN contratos c ON e.id = c.empleado_id AND c.activo = 1
    ORDER BY e.nombre_completo ASC";
$empleados = $pdo->query($query)->fetchAll();

// Parámetros de Ley
$config_ley = $pdo->query("SELECT * FROM parametros_ley LIMIT 1")->fetch();
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
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .modal { transition: opacity 0.25s ease; }
        body.modal-active { overflow: hidden; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">

    <div class="max-w-6xl mx-auto py-10 px-4">
        
        <header class="flex justify-between items-center mb-10 bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-200">
            <div>
                <h1 class="text-3xl font-black text-slate-800">Panel de Configuración</h1>
                <p class="text-slate-400 font-bold text-xs uppercase tracking-widest mt-1">Gestión de Personal y Parámetros Legales</p>
            </div>
            <div class="flex gap-4">
                <button onclick="abrirModalCrear()" class="bg-indigo-600 text-white px-6 py-3 rounded-2xl font-black text-sm hover:bg-indigo-700 shadow-lg shadow-indigo-100 transition">
                    <i class="fas fa-plus mr-2"></i> NUEVO EMPLEADO
                </button>
                <a href="index.php" class="bg-slate-100 text-slate-600 px-6 py-3 rounded-2xl font-black text-sm hover:bg-slate-200 transition">
                    <i class="fas fa-home mr-2"></i> INICIO
                </a>
            </div>
        </header>

        <?= $mensaje ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- LISTADO DE EMPLEADOS -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white rounded-[2rem] shadow-xl border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                        <h2 class="font-black text-slate-700 uppercase tracking-tighter">Nómina de Colaboradores</h2>
                        <span class="bg-slate-100 text-slate-500 text-[10px] font-black px-3 py-1 rounded-full uppercase"><?= count($empleados) ?> Activos</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50">
                                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase">Colaborador</th>
                                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase">Cédula</th>
                                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase">Salario Base</th>
                                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($empleados as $emp): ?>
                                <tr class="border-b border-slate-50 hover:bg-slate-50 transition">
                                    <td class="p-4">
                                        <p class="font-black text-slate-700 text-sm"><?= $emp['nombre_completo'] ?></p>
                                        <p class="text-[10px] text-slate-400">Ingreso: <?= $emp['fecha_ingreso'] ?></p>
                                    </td>
                                    <td class="p-4 font-bold text-slate-500 text-sm"><?= $emp['cedula'] ?></td>
                                    <td class="p-4 font-black text-indigo-600 text-sm">$<?= number_format($emp['salario_base'], 0) ?></td>
                                    <td class="p-4">
                                        <button onclick='abrirModalEditar(<?= json_encode($emp) ?>)' class="text-indigo-600 hover:bg-indigo-50 p-2 rounded-lg transition">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- PARÁMETROS DE LEY -->
            <div class="lg:col-span-1">
                <div class="bg-slate-900 text-white rounded-[2rem] p-8 shadow-2xl sticky top-8">
                    <h2 class="text-xl font-black mb-6 flex items-center gap-3">
                        <i class="fas fa-balance-scale text-indigo-400"></i> Parámetros 2026
                    </h2>
                    <form action="" method="POST" class="space-y-6">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase block mb-2">Salario Mínimo (SMLV)</label>
                            <input type="number" name="smlv" value="<?= $config_ley['valor_smlv'] ?>" class="w-full bg-white/10 border border-white/10 rounded-xl px-4 py-3 font-bold text-white outline-none focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase block mb-2">Subsidio Transporte</label>
                            <input type="number" name="sub_trans" value="<?= $config_ley['subsidio_transporte'] ?>" class="w-full bg-white/10 border border-white/10 rounded-xl px-4 py-3 font-bold text-white outline-none focus:border-indigo-500">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase block mb-2">% Recargo Noct</label>
                                <input type="number" step="0.01" name="p_rn" value="<?= $config_ley['recargo_nocturno'] ?>" class="w-full bg-white/10 border border-white/10 rounded-xl px-4 py-3 font-bold text-white outline-none focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase block mb-2">% Recargo Fest</label>
                                <input type="number" step="0.01" name="p_rf" value="<?= $config_ley['recargo_festivo'] ?>" class="w-full bg-white/10 border border-white/10 rounded-xl px-4 py-3 font-bold text-white outline-none focus:border-indigo-500">
                            </div>
                        </div>
                        <button type="submit" name="actualizar_ley" class="w-full bg-indigo-500 py-4 rounded-xl font-black hover:bg-indigo-400 transition">ACTUALIZAR LEY</button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- MODAL CREAR EMPLEADO -->
    <div id="modalCrear" class="modal fixed inset-0 z-50 flex items-center justify-center opacity-0 pointer-events-none p-4">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="cerrarModal('modalCrear')"></div>
        <div class="bg-white w-full max-w-2xl rounded-[2.5rem] shadow-2xl relative overflow-hidden animate-fade-in">
            <div class="bg-indigo-600 p-8 text-white">
                <h3 class="text-2xl font-black uppercase">Nuevo Colaborador</h3>
                <p class="text-indigo-100 text-xs font-bold tracking-widest mt-1">REGISTRO DE EMPLEADO Y CONTRATO INICIAL</p>
            </div>
            <form action="" method="POST" class="p-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                <input type="hidden" name="crear_empleado" value="1">
                
                <div class="space-y-4">
                    <h4 class="text-indigo-600 font-black text-[10px] uppercase tracking-tighter border-b pb-2">Datos Personales</h4>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Nombre Completo</label>
                        <input type="text" name="nombre_completo" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Documento de Identidad</label>
                        <input type="text" name="cedula" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Fecha de Ingreso</label>
                        <input type="date" name="fecha_ingreso" value="<?= date('Y-m-d') ?>" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                </div>

                <div class="space-y-4">
                    <h4 class="text-emerald-600 font-black text-[10px] uppercase tracking-tighter border-b pb-2">Condiciones Contractuales</h4>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Salario Base Mensual</label>
                        <input type="number" name="salario_base" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Auxilio Movilidad Diurno</label>
                        <input type="number" name="aux_movilizacion_mensual" value="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Auxilio Movilidad Nocturno</label>
                        <input type="number" name="aux_mov_nocturno_mensual" value="0" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                    <div class="flex items-center gap-3 pt-2">
                        <input type="checkbox" name="es_direccion_confianza" id="crear_dir" class="w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <label for="crear_dir" class="text-xs font-bold text-slate-600 uppercase">Cargo de Dirección/Confianza</label>
                    </div>
                </div>

                <div class="md:col-span-2 pt-4 border-t flex justify-end gap-4">
                    <button type="button" onclick="cerrarModal('modalCrear')" class="px-6 py-3 font-black text-slate-400 text-sm uppercase">Cancelar</button>
                    <button type="submit" class="bg-indigo-600 text-white px-10 py-3 rounded-2xl font-black shadow-xl shadow-indigo-100 hover:bg-indigo-700 transition">CREAR COLABORADOR</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR EMPLEADO -->
    <div id="modalEditar" class="modal fixed inset-0 z-50 flex items-center justify-center opacity-0 pointer-events-none p-4">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="cerrarModal('modalEditar')"></div>
        <div class="bg-white w-full max-w-2xl rounded-[2.5rem] shadow-2xl relative overflow-hidden">
            <div class="bg-slate-900 p-8 text-white">
                <h3 class="text-2xl font-black uppercase" id="edit_title">Editar Colaborador</h3>
                <p class="text-slate-400 text-xs font-bold tracking-widest mt-1">ACTUALIZACIÓN DE PARÁMETROS CONTRACTUALES</p>
            </div>
            <form action="" method="POST" class="p-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                <input type="hidden" name="editar_empleado" value="1">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="space-y-4">
                    <h4 class="text-indigo-600 font-black text-[10px] uppercase tracking-tighter border-b pb-2">Información Básica</h4>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Nombre Completo</label>
                        <input type="text" name="nombre_completo" id="edit_nombre" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Cédula</label>
                        <input type="text" name="cedula" id="edit_cedula" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                </div>

                <div class="space-y-4">
                    <h4 class="text-emerald-600 font-black text-[10px] uppercase tracking-tighter border-b pb-2">Compensación</h4>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Salario Mensual</label>
                        <input type="number" name="salario_base" id="edit_salario" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Auxilio Movilidad</label>
                        <input type="number" name="aux_movilizacion_mensual" id="edit_aux_mov" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase mb-1 block">Aux. Movilidad Nocturno</label>
                        <input type="number" name="aux_mov_nocturno_mensual" id="edit_aux_mov_noc" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 font-bold text-slate-700 outline-none focus:border-indigo-500">
                    </div>
                </div>

                <div class="md:col-span-2 pt-4 border-t flex justify-end gap-4">
                    <button type="button" onclick="cerrarModal('modalEditar')" class="px-6 py-3 font-black text-slate-400 text-sm uppercase">Cerrar</button>
                    <button type="submit" class="bg-slate-900 text-white px-10 py-3 rounded-2xl font-black shadow-xl hover:bg-black transition">GUARDAR CAMBIOS</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalCrear() {
            const modal = document.getElementById('modalCrear');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            document.body.classList.add('modal-active');
        }

        function abrirModalEditar(emp) {
            document.getElementById('edit_id').value = emp.id;
            document.getElementById('edit_nombre').value = emp.nombre_completo;
            document.getElementById('edit_cedula').value = emp.cedula;
            document.getElementById('edit_salario').value = emp.salario_base;
            document.getElementById('edit_aux_mov').value = emp.aux_movilizacion_mensual || 0;
            document.getElementById('edit_aux_mov_noc').value = emp.aux_mov_nocturno_mensual || 0;

            const modal = document.getElementById('modalEditar');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            document.body.classList.add('modal-active');
        }

        function cerrarModal(id) {
            const modal = document.getElementById(id);
            modal.classList.add('opacity-0', 'pointer-events-none');
            document.body.classList.remove('modal-active');
        }
    </script>
</body>
</html>
