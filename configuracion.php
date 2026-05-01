<?php
// 1. Configuración de errores para diagnóstico
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2. Intentar cargar la base de datos
$db_path = 'config/db.php';
if (!file_exists($db_path)) {
    die("Error crítico: No se encuentra el archivo de conexión en $db_path");
}
require_once $db_path;

$mensaje = "";

/**
 * FUNCIÓN PARA DETECTAR EL NOMBRE DE LA TABLA Y COLUMNAS REALES
 * Evita Error 500 si la estructura varía entre servidores o versiones.
 */
function obtenerEstructuraLey($pdo) {
    $tablas = ['config_ley', 'parametros_ley'];
    foreach ($tablas as $t) {
        $check = $pdo->query("SHOW TABLES LIKE '$t'")->rowCount();
        if ($check > 0) {
            $rs = $pdo->query("SHOW COLUMNS FROM $t");
            return [
                'tabla' => $t, 
                'columnas' => $rs->fetchAll(PDO::FETCH_COLUMN)
            ];
        }
    }
    return null;
}

$estructura = obtenerEstructuraLey($pdo);
$tabla_ley = $estructura['tabla'] ?? null;

// --- LÓGICA DE REPARACIÓN DE EMERGENCIA ---
if (isset($_POST['reparar_db'])) {
    try {
        if ($tabla_ley) {
            // Asegurar que existan las columnas de recargos para evitar errores en nómina.php
            $pdo->exec("ALTER TABLE $tabla_ley ADD COLUMN IF NOT EXISTS recargo_nocturno DECIMAL(5,2) DEFAULT 35.00");
            $pdo->exec("ALTER TABLE $tabla_ley ADD COLUMN IF NOT EXISTS recargo_festivo DECIMAL(5,2) DEFAULT 75.00");
            $mensaje = "<div class='bg-amber-100 text-amber-700 p-4 rounded-xl mb-6 border border-amber-200 shadow-sm flex items-center gap-3'>
                            <i class='fas fa-check-circle'></i>
                            <span>Estructura de tabla <b>$tabla_ley</b> actualizada con columnas de recargo.</span>
                        </div>";
        }
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6 border border-red-200'>Error al reparar: " . $e->getMessage() . "</div>";
    }
}

// --- LÓGICA DE CREACIÓN DE EMPLEADO ---
if (isset($_POST['crear_empleado'])) {
    try {
        $pdo->beginTransaction();
        
        // Insertar en empleados
        $stmt_emp = $pdo->prepare("INSERT INTO empleados (cedula, nombre_completo, fecha_ingreso) VALUES (?, ?, ?)");
        $stmt_emp->execute([
            $_POST['cedula'], 
            $_POST['nombre_completo'], 
            $_POST['fecha_ingreso']
        ]);
        $empleado_id = $pdo->lastInsertId();

        // Insertar contrato activo
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
            $_POST['aux_movilizacion_mensual'] ?: 0,
            $_POST['aux_mov_nocturno_mensual'] ?: 0,
            $_POST['fecha_ingreso']
        ]);

        $pdo->commit();
        $mensaje = "<div class='bg-emerald-100 text-emerald-700 p-4 rounded-xl mb-6 shadow-sm border border-emerald-200 flex items-center gap-3'>
                        <i class='fas fa-user-plus'></i>
                        <span>¡Empleado y contrato vinculados exitosamente!</span>
                    </div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6 border border-red-200'>Error: " . $e->getMessage() . "</div>";
    }
}

// --- LÓGICA DE EDICIÓN DE EMPLEADO ---
if (isset($_POST['editar_empleado'])) {
    try {
        $pdo->beginTransaction();
        
        // Actualizar datos básicos
        $stmt_u_emp = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
        $stmt_u_emp->execute([$_POST['nombre_completo'], $_POST['cedula'], $_POST['id']]);

        // Actualizar contrato activo (asumiendo que solo hay uno activo por empleado)
        $stmt_u_con = $pdo->prepare("
            UPDATE contratos 
            SET salario_base = ?, 
                aux_movilizacion_mensual = ?, 
                aux_mov_nocturno_mensual = ?,
                es_direccion_confianza = ?
            WHERE empleado_id = ? AND activo = 1
        ");
        $stmt_u_con->execute([
            $_POST['salario_base'],
            $_POST['aux_movilizacion_mensual'],
            $_POST['aux_mov_nocturno_mensual'],
            isset($_POST['es_direccion_confianza']) ? 1 : 0,
            $_POST['id']
        ]);

        $pdo->commit();
        $mensaje = "<div class='bg-blue-100 text-blue-700 p-4 rounded-xl mb-6 shadow-sm border border-blue-200 flex items-center gap-3'>
                        <i class='fas fa-sync'></i>
                        <span>Información de <b>" . htmlspecialchars($_POST['nombre_completo']) . "</b> actualizada.</span>
                    </div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6 border border-red-200'>Error al editar: " . $e->getMessage() . "</div>";
    }
}

// --- ACTUALIZAR PARÁMETROS DE LEY ---
if (isset($_POST['actualizar_ley']) && $tabla_ley) {
    try {
        $sql = "UPDATE $tabla_ley SET 
                valor_smlv = ?, 
                subsidio_transporte = ?, 
                recargo_nocturno = ?, 
                recargo_festivo = ? 
                WHERE id = 1 OR 1=1 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['smlv'], 
            $_POST['sub_trans'], 
            $_POST['p_rn'], 
            $_POST['p_rf']
        ]);
        $mensaje = "<div class='bg-indigo-100 text-indigo-700 p-4 rounded-xl mb-6 border border-indigo-200 shadow-sm flex items-center gap-3'>
                        <i class='fas fa-save'></i>
                        <span>Parámetros legales para el periodo fiscal actualizados.</span>
                    </div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6 border border-red-200'>Error al guardar ley: " . $e->getMessage() . "</div>";
    }
}

// Carga de datos
$empleados = $pdo->query("
    SELECT e.*, c.salario_base, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual, c.es_direccion_confianza 
    FROM empleados e
    LEFT JOIN contratos c ON e.id = c.empleado_id AND c.activo = 1
    ORDER BY e.nombre_completo ASC")->fetchAll();

$config_ley = ($tabla_ley) ? $pdo->query("SELECT * FROM $tabla_ley LIMIT 1")->fetch() : null;
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
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .modal-blur { backdrop-filter: blur(8px); }
    </style>
</head>
<body class="bg-[#f1f5f9] text-slate-800 min-h-screen">

    <div class="max-w-7xl mx-auto px-6 py-12">
        
        <!-- Encabezado con Acciones -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 mb-12">
            <div>
                <h1 class="text-5xl font-extrabold text-slate-900 tracking-tight">Panel de Control</h1>
                <p class="text-slate-500 mt-2 text-lg">Administre el personal y los parámetros globales de ley.</p>
            </div>
            <div class="flex flex-wrap gap-4">
                <form action="" method="POST" onsubmit="return confirm('¿Desea validar y reparar la estructura de la base de datos?')">
                    <button type="submit" name="reparar_db" class="bg-amber-50 text-amber-700 border border-amber-200 px-5 py-3 rounded-2xl font-bold hover:bg-amber-100 transition-all flex items-center gap-2">
                        <i class="fas fa-hammer text-sm"></i> REPARAR TABLAS
                    </button>
                </form>
                <button onclick="document.getElementById('modalCrear').style.display='flex'" class="bg-indigo-600 text-white px-8 py-3 rounded-2xl font-extrabold shadow-xl shadow-indigo-200 hover:bg-indigo-700 transition-all flex items-center gap-2">
                    <i class="fas fa-plus"></i> AGREGAR EMPLEADO
                </button>
                <a href="index.php" class="bg-white border border-slate-200 px-8 py-3 rounded-2xl font-extrabold hover:bg-slate-50 transition-all shadow-sm">
                    VOLVER
                </a>
            </div>
        </div>

        <?= $mensaje ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">
            
            <!-- Listado Principal -->
            <div class="lg:col-span-8">
                <div class="bg-white rounded-[2rem] shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-8 border-b border-slate-100 bg-white flex justify-between items-center">
                        <h3 class="font-extrabold text-xl text-slate-900 flex items-center gap-3">
                            <span class="w-2 h-8 bg-indigo-600 rounded-full"></span>
                            Personal de la Empresa
                        </h3>
                        <span class="bg-slate-100 text-slate-600 text-xs px-4 py-1.5 rounded-full font-bold uppercase tracking-widest">
                            <?= count($empleados) ?> registros
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="text-[11px] uppercase tracking-[0.15em] text-slate-400 font-black bg-slate-50/50">
                                    <th class="px-8 py-5">Colaborador</th>
                                    <th class="px-8 py-5 text-center">Salario Base</th>
                                    <th class="px-8 py-5 text-center">Tipo</th>
                                    <th class="px-8 py-5 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach($empleados as $emp): ?>
                                <tr class="hover:bg-slate-50/50 transition-all group">
                                    <td class="px-8 py-6">
                                        <div class="flex items-center gap-4">
                                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center font-bold text-lg shadow-lg shadow-indigo-100">
                                                <?= substr($emp['nombre_completo'], 0, 1) ?>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-900 text-base leading-none"><?= htmlspecialchars($emp['nombre_completo']) ?></p>
                                                <p class="text-xs text-slate-400 mt-1.5 font-medium tracking-tight">ID: <?= htmlspecialchars($emp['cedula']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-8 py-6 text-center font-bold text-slate-700">
                                        $<?= number_format($emp['salario_base'], 0) ?>
                                    </td>
                                    <td class="px-8 py-6 text-center">
                                        <?php if($emp['es_direccion_confianza']): ?>
                                            <span class="text-[10px] bg-amber-100 text-amber-700 px-3 py-1 rounded-lg font-black uppercase">Confianza</span>
                                        <?php else: ?>
                                            <span class="text-[10px] bg-slate-100 text-slate-500 px-3 py-1 rounded-lg font-black uppercase">Operativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-8 py-6 text-right">
                                        <button onclick='abrirModalEditar(<?= json_encode($emp) ?>)' class="w-10 h-10 rounded-xl bg-slate-100 text-slate-500 hover:bg-indigo-600 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                            <i class="fas fa-pen-nib"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Panel de Configuración de Ley -->
            <div class="lg:col-span-4">
                <div class="bg-slate-900 text-white rounded-[2.5rem] p-10 shadow-2xl relative overflow-hidden sticky top-8 border-t border-slate-700">
                    <div class="absolute -right-12 -bottom-12 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl"></div>
                    
                    <h3 class="text-2xl font-extrabold mb-8 flex items-center gap-3 relative z-10">
                        <i class="fas fa-balance-scale-right text-indigo-400"></i> Parámetros de Ley
                    </h3>

                    <?php if($config_ley): ?>
                    <form action="" method="POST" class="space-y-6 relative z-10">
                        <div class="space-y-2">
                            <label class="text-[10px] uppercase font-black text-slate-500 tracking-[0.2em] block">SMLV Vigente</label>
                            <div class="relative group">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">$</span>
                                <input type="number" name="smlv" value="<?= $config_ley['valor_smlv'] ?>" class="w-full bg-slate-800 border-2 border-transparent focus:border-indigo-500 rounded-2xl py-4 pl-9 pr-4 transition-all font-bold text-lg outline-none">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="text-[10px] uppercase font-black text-slate-500 tracking-[0.2em] block">Auxilio Transporte</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">$</span>
                                <input type="number" name="sub_trans" value="<?= $config_ley['subsidio_transporte'] ?>" class="w-full bg-slate-800 border-2 border-transparent focus:border-indigo-500 rounded-2xl py-4 pl-9 pr-4 transition-all font-bold text-lg outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-5 pt-2">
                            <div class="space-y-2">
                                <label class="text-[10px] uppercase font-black text-slate-500 tracking-[0.2em] block text-center">% Nocturno</label>
                                <input type="text" name="p_rn" value="<?= $config_ley['recargo_nocturno'] ?? 35 ?>" class="w-full bg-slate-800 border-2 border-transparent focus:border-indigo-500 rounded-2xl py-4 px-4 transition-all font-black text-center text-indigo-300 outline-none">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] uppercase font-black text-slate-500 tracking-[0.2em] block text-center">% Festivo</label>
                                <input type="text" name="p_rf" value="<?= $config_ley['recargo_festivo'] ?? 75 ?>" class="w-full bg-slate-800 border-2 border-transparent focus:border-indigo-500 rounded-2xl py-4 px-4 transition-all font-black text-center text-indigo-300 outline-none">
                            </div>
                        </div>

                        <button type="submit" name="actualizar_ley" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white py-5 rounded-2xl font-black transition-all shadow-xl shadow-indigo-900/40 flex items-center justify-center gap-3 mt-4 active:scale-[0.98]">
                            <i class="fas fa-check-circle"></i> GUARDAR CAMBIOS
                        </button>
                    </form>
                    <?php else: ?>
                        <div class="bg-red-500/10 border border-red-500/20 p-6 rounded-[2rem] text-red-400 text-sm text-center">
                            <i class="fas fa-exclamation-triangle text-3xl mb-3 block"></i>
                            <p class="font-bold">Tabla de configuración no detectada.</p>
                            <p class="mt-2 opacity-70">Utilice el botón de "Reparar Tablas" para inicializar el sistema.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR -->
    <div id="modalCrear" style="display:none" class="fixed inset-0 bg-slate-900/80 modal-blur z-50 items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] shadow-2xl max-w-2xl w-full overflow-hidden">
            <div class="p-10">
                <div class="flex justify-between items-center mb-8">
                    <h2 class="text-3xl font-black text-slate-900 tracking-tight">Alta de Personal</h2>
                    <button onclick="document.getElementById('modalCrear').style.display='none'" class="w-10 h-10 rounded-full bg-slate-100 text-slate-400 hover:text-slate-600 flex items-center justify-center transition-all">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form action="" method="POST" class="space-y-6">
                    <input type="hidden" name="crear_empleado" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Nombre Completo</label>
                            <input type="text" name="nombre_completo" class="w-full border-slate-200 border-2 rounded-2xl p-4 focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition-all font-bold" required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Documento de Identidad</label>
                            <input type="text" name="cedula" class="w-full border-slate-200 border-2 rounded-2xl p-4 focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition-all font-bold" required>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Salario Mensual Base</label>
                            <input type="number" name="salario_base" class="w-full border-slate-200 border-2 rounded-2xl p-4 focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition-all font-bold" required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Fecha Vinculación</label>
                            <input type="date" name="fecha_ingreso" value="<?= date('Y-m-d') ?>" class="w-full border-slate-200 border-2 rounded-2xl p-4 focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition-all font-bold">
                        </div>
                    </div>
                    <div class="bg-indigo-50/50 p-8 rounded-3xl space-y-5 border-2 border-indigo-100/50">
                        <p class="text-[11px] font-black text-indigo-500 uppercase tracking-[0.2em]">Compensaciones Mensuales Adicionales</p>
                        <div class="grid grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-bold text-indigo-400 uppercase">Aux. Movilidad</label>
                                <input type="number" name="aux_movilizacion_mensual" class="w-full bg-white border-2 border-indigo-100 rounded-xl p-3 shadow-sm outline-none font-bold">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-bold text-indigo-400 uppercase">Aux. Nocturno</label>
                                <input type="number" name="aux_mov_nocturno_mensual" class="w-full bg-white border-2 border-indigo-100 rounded-xl p-3 shadow-sm outline-none font-bold">
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 bg-slate-50 p-4 rounded-2xl border-2 border-slate-100">
                        <input type="checkbox" name="es_direccion_confianza" id="confianza" class="w-6 h-6 accent-indigo-600 rounded-lg cursor-pointer">
                        <label for="confianza" class="text-sm font-extrabold text-slate-600 cursor-pointer select-none">Personal de Dirección, Manejo y Confianza</label>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white py-5 rounded-[1.5rem] font-black text-lg mt-4 shadow-2xl shadow-indigo-200 hover:bg-indigo-700 transition-all transform active:scale-[0.99]">
                        FINALIZAR REGISTRO
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR -->
    <div id="modalEditar" style="display:none" class="fixed inset-0 bg-slate-900/80 modal-blur z-50 items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] shadow-2xl max-w-2xl w-full overflow-hidden">
            <div class="p-10">
                <div class="flex justify-between items-center mb-8">
                    <h2 class="text-3xl font-black text-slate-900 tracking-tight text-indigo-600">Actualizar Datos</h2>
                    <button onclick="document.getElementById('modalEditar').style.display='none'" class="w-10 h-10 rounded-full bg-slate-100 text-slate-400 hover:text-slate-600 flex items-center justify-center transition-all">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form action="" method="POST" class="space-y-6">
                    <input type="hidden" name="editar_empleado" value="1">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Nombre</label>
                            <input type="text" name="nombre_completo" id="edit_nombre" class="w-full border-slate-200 border-2 rounded-2xl p-4 font-bold outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Documento</label>
                            <input type="text" name="cedula" id="edit_cedula" class="w-full border-slate-200 border-2 rounded-2xl p-4 font-bold outline-none">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1 text-center block">Salario Mensual Pactado</label>
                        <input type="number" name="salario_base" id="edit_salario" class="w-full border-slate-200 border-2 rounded-2xl p-4 font-black text-center text-2xl text-indigo-600 outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Aux. Movilidad</label>
                            <input type="number" name="aux_movilizacion_mensual" id="edit_aux_mov" class="w-full border-slate-200 border-2 rounded-2xl p-4 font-bold outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Aux. Nocturno</label>
                            <input type="number" name="aux_mov_nocturno_mensual" id="edit_aux_noc" class="w-full border-slate-200 border-2 rounded-2xl p-4 font-bold outline-none">
                        </div>
                    </div>

                    <div class="flex items-center gap-4 bg-amber-50 p-4 rounded-2xl border-2 border-amber-100">
                        <input type="checkbox" name="es_direccion_confianza" id="edit_confianza" class="w-6 h-6 accent-amber-500 rounded-lg cursor-pointer">
                        <label for="edit_confianza" class="text-sm font-extrabold text-amber-700 cursor-pointer select-none">Personal de Dirección, Manejo y Confianza</label>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white py-5 rounded-[1.5rem] font-black text-lg mt-4 shadow-xl hover:bg-black transition-all">
                        CONFIRMAR ACTUALIZACIÓN
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function abrirModalEditar(emp) {
            document.getElementById('edit_id').value = emp.id;
            document.getElementById('edit_nombre').value = emp.nombre_completo;
            document.getElementById('edit_cedula').value = emp.cedula;
            document.getElementById('edit_salario').value = emp.salario_base;
            document.getElementById('edit_aux_mov').value = emp.aux_movilizacion_mensual;
            document.getElementById('edit_aux_noc').value = emp.aux_mov_nocturno_mensual;
            document.getElementById('edit_confianza').checked = (parseInt(emp.es_direccion_confianza) === 1);
            
            document.getElementById('modalEditar').style.display = 'flex';
        }

        // Cerrar modales al hacer clic fuera del contenido
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-blur')) {
                event.target.style.display = "none";
            }
        }
    </script>
</body>
</html>
