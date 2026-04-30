<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config/db.php';

$mensaje = "";

// 1. PROCESAR ACTUALIZACIÓN DE PARÁMETROS DE LEY Y RECARGOS
if (isset($_POST['guardar_ley'])) {
    try {
        $sql = "UPDATE config_ley SET 
                salario_minimo = ?, 
                auxilio_transporte = ?, 
                uvt_valor = ?, 
                salud_empleado = ?, 
                pension_empleado = ?, 
                recargo_nocturno = ?, 
                recargo_festivo = ?, 
                recargo_festivo_nocturno = ?
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['salario_minimo'],
            $_POST['auxilio_transporte'],
            $_POST['uvt_valor'],
            $_POST['salud_empleado'],
            $_POST['pension_empleado'],
            $_POST['recargo_nocturno'],
            $_POST['recargo_festivo'],
            $_POST['recargo_festivo_nocturno'],
            $_POST['config_id']
        ]);
        $mensaje = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>Parámetros legales actualizados.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Error: " . $e->getMessage() . "</div>";
    }
}

// 2. PROCESAR ACTUALIZACIÓN DE EMPLEADO/CONTRATO
if (isset($_POST['editar_empleado'])) {
    try {
        $pdo->beginTransaction();
        
        // Actualizar datos básicos
        $stmt1 = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
        $stmt1->execute([$_POST['nombre'], $_POST['cedula'], $_POST['empleado_id']]);
        
        // Actualizar contrato (Salario y Auxilios)
        $stmt2 = $pdo->prepare("UPDATE contratos SET salario_base = ?, aux_movilizacion_mensual = ?, aux_mov_nocturno_mensual = ? WHERE empleado_id = ?");
        $stmt2->execute([$_POST['salario'], $_POST['aux_mov'], $_POST['aux_mov_noc'], $_POST['empleado_id']]);
        
        $pdo->commit();
        $mensaje = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>Datos de empleado actualizados correctamente.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Error: " . $e->getMessage() . "</div>";
    }
}

// Cargar Datos Actuales
$config = $pdo->query("SELECT * FROM config_ley LIMIT 1")->fetch();
$empleados = $pdo->query("SELECT e.*, c.salario_base, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual 
                          FROM empleados e 
                          LEFT JOIN contratos c ON e.id = c.empleado_id")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Sistema - Nómina PHP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    </style>
</head>
<body class="pb-12">

    <!-- Barra de Navegación -->
    <nav class="bg-white border-b border-slate-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-4">
                    <div class="bg-indigo-600 p-2 rounded-xl text-white">
                        <i class="fas fa-cogs fa-fw"></i>
                    </div>
                    <span class="text-xl font-bold text-slate-800 tracking-tight">Configuración del Sistema</span>
                </div>
                <div class="flex items-center">
                    <a href="index.php" class="text-slate-500 hover:text-indigo-600 font-medium text-sm transition">
                        <i class="fas fa-arrow-left mr-2"></i> Volver al Dashboard
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8">
        
        <?php echo $mensaje; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- COLUMNA IZQUIERDA: PARÁMETROS LEGALES Y RECARGOS -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 bg-slate-50">
                        <h2 class="font-bold text-slate-800 flex items-center gap-2">
                            <i class="fas fa-balance-scale text-indigo-500"></i> Parámetros de Ley (<?= $config['anio'] ?? '2026' ?>)
                        </h2>
                    </div>
                    <form action="" method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="config_id" value="<?= $config['id'] ?>">
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Salario Mínimo (SMLV)</label>
                            <input type="number" name="salario_minimo" value="<?= $config['salario_minimo'] ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Auxilio Transporte</label>
                            <input type="number" name="auxilio_transporte" value="<?= $config['auxilio_transporte'] ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">% Salud Empleado</label>
                                <input type="number" step="0.01" name="salud_empleado" value="<?= $config['salud_empleado'] ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">% Pensión Empleado</label>
                                <input type="number" step="0.01" name="pension_empleado" value="<?= $config['pension_empleado'] ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-100">
                            <h3 class="text-xs font-black text-indigo-600 uppercase mb-3 tracking-widest">Factores de Recargo (%)</h3>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-slate-600">Nocturno (RN)</span>
                                    <input type="number" step="0.01" name="recargo_nocturno" value="<?= $config['recargo_nocturno'] ?>" class="w-20 bg-slate-50 border border-slate-200 rounded px-2 py-1 text-right text-sm">
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-slate-600">Festivo Diurno</span>
                                    <input type="number" step="0.01" name="recargo_festivo" value="<?= $config['recargo_festivo'] ?>" class="w-20 bg-slate-50 border border-slate-200 rounded px-2 py-1 text-right text-sm">
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-slate-600">Festivo Nocturno</span>
                                    <input type="number" step="0.01" name="recargo_festivo_nocturno" value="<?= $config['recargo_festivo_nocturno'] ?>" class="w-20 bg-slate-50 border border-slate-200 rounded px-2 py-1 text-right text-sm">
                                </div>
                            </div>
                        </div>

                        <div class="pt-2">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Valor UVT</label>
                            <input type="number" name="uvt_valor" value="<?= $config['uvt_valor'] ?>" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>

                        <button type="submit" name="guardar_ley" class="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold text-sm shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition">
                            Guardar Parametrización
                        </button>
                    </form>
                </div>
            </div>

            <!-- COLUMNA DERECHA: GESTIÓN DE EMPLEADOS -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                        <h2 class="font-bold text-slate-800 flex items-center gap-2">
                            <i class="fas fa-users text-indigo-500"></i> Personal y Contratos
                        </h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50">
                                    <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Empleado</th>
                                    <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Salario Base</th>
                                    <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Auxilios</th>
                                    <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($empleados as $emp): ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-slate-800 text-sm"><?= $emp['nombre_completo'] ?></div>
                                        <div class="text-xs text-slate-400">CC: <?= $emp['cedula'] ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-slate-600">
                                        $<?= number_format($emp['salario_base'], 0, ',', '.') ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-[10px] bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full inline-block mb-1">Mov: $<?= number_format($emp['aux_movilizacion_mensual'], 0) ?></div>
                                        <div class="text-[10px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full inline-block">Noc: $<?= number_format($emp['aux_mov_nocturno_mensual'], 0) ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <button onclick='abrirModal(<?= json_encode($emp) ?>)' class="text-indigo-600 hover:bg-indigo-50 p-2 rounded-lg transition">
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

        </div>
    </div>

    <!-- MODAL DE EDICIÓN DE EMPLEADO -->
    <div id="modalEmpleado" class="fixed inset-0 z-50 flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="cerrarModal()"></div>
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg mx-4 z-10 overflow-hidden transform scale-95 transition-transform duration-300" id="modalContent">
            <div class="p-6 border-b border-slate-100 bg-indigo-600 text-white flex justify-between items-center">
                <h3 class="font-bold">Editar Perfil y Contrato</h3>
                <button onclick="cerrarModal()" class="text-white/80 hover:text-white"><i class="fas fa-times"></i></button>
            </div>
            <form action="" method="POST" class="p-8 space-y-5">
                <input type="hidden" name="empleado_id" id="m_id">
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-1">Nombre Completo</label>
                        <input type="text" name="nombre" id="m_nombre" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-1">Identificación (CC)</label>
                        <input type="text" name="cedula" id="m_cedula" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase mb-1">Salario Base Mensual</label>
                        <input type="number" name="salario" id="m_salario" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-indigo-600">
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-100">
                    <p class="text-xs font-bold text-slate-400 uppercase mb-3">Auxilios Mensuales (No Salariales)</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 mb-1">Auxilio Movilidad</label>
                            <input type="number" name="aux_mov" id="m_aux_mov" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 mb-1">Auxilio Mov. Nocturna</label>
                            <input type="number" name="aux_mov_noc" id="m_aux_noc" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                <button type="submit" name="editar_empleado" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold shadow-xl shadow-indigo-100 hover:bg-indigo-700 transition mt-4">
                    Actualizar Información
                </button>
            </form>
        </div>
    </div>

    <script>
        function abrirModal(emp) {
            document.getElementById('m_id').value = emp.id;
            document.getElementById('m_nombre').value = emp.nombre_completo;
            document.getElementById('m_cedula').value = emp.cedula;
            document.getElementById('m_salario').value = emp.salario_base;
            document.getElementById('m_aux_mov').value = emp.aux_movilizacion_mensual || 0;
            document.getElementById('m_aux_noc').value = emp.aux_mov_nocturno_mensual || 0;

            const modal = document.getElementById('modalEmpleado');
            const content = document.getElementById('modalContent');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            content.classList.remove('scale-95');
            content.classList.add('scale-100');
        }

        function cerrarModal() {
            const modal = document.getElementById('modalEmpleado');
            const content = document.getElementById('modalContent');
            modal.classList.add('opacity-0', 'pointer-events-none');
            content.classList.remove('scale-100');
            content.classList.add('scale-95');
        }
    </script>

</body>
</html>
