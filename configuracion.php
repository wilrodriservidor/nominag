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
 * Evita Error 500 si la estructura varía entre servidores.
 */
function obtenerEstructuraLey($pdo) {
    $tablas = ['config_ley', 'parametros_ley'];
    foreach ($tablas as $t) {
        $check = $pdo->query("SHOW TABLES LIKE '$t'")->rowCount();
        if ($check > 0) {
            $rs = $pdo->query("SHOW COLUMNS FROM $t");
            return ['tabla' => $t, 'columnas' => $rs->fetchAll(PDO::FETCH_COLUMN)];
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
            $pdo->exec("ALTER TABLE $tabla_ley ADD COLUMN IF NOT EXISTS recargo_nocturno DECIMAL(5,2) DEFAULT 35.00");
            $pdo->exec("ALTER TABLE $tabla_ley ADD COLUMN IF NOT EXISTS recargo_festivo DECIMAL(5,2) DEFAULT 75.00");
            $mensaje = "<div class='bg-amber-100 text-amber-700 p-4 rounded-xl mb-6 border border-amber-200'>Estructura de tabla $tabla_ley actualizada.</div>";
        }
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6'>Error al reparar: " . $e->getMessage() . "</div>";
    }
}

// --- LÓGICA DE CREACIÓN DE EMPLEADO ---
if (isset($_POST['crear_empleado'])) {
    try {
        $pdo->beginTransaction();
        $stmt_emp = $pdo->prepare("INSERT INTO empleados (cedula, nombre_completo, fecha_ingreso) VALUES (?, ?, ?)");
        $stmt_emp->execute([$_POST['cedula'], $_POST['nombre_completo'], $_POST['fecha_ingreso']]);
        $empleado_id = $pdo->lastInsertId();

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
        $mensaje = "<div class='bg-emerald-100 text-emerald-700 p-4 rounded-xl mb-6 shadow-sm border border-emerald-200'>¡Empleado y contrato creados con éxito!</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6'>Error: " . $e->getMessage() . "</div>";
    }
}

// --- LÓGICA DE EDICIÓN DE EMPLEADO ---
if (isset($_POST['editar_empleado'])) {
    try {
        $pdo->beginTransaction();
        $stmt_u_emp = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
        $stmt_u_emp->execute([$_POST['nombre_completo'], $_POST['cedula'], $_POST['id']]);

        $stmt_u_con = $pdo->prepare("
            UPDATE contratos 
            SET salario_base = ?, aux_movilizacion_mensual = ?, aux_mov_nocturno_mensual = ? 
            WHERE empleado_id = ? AND activo = 1
        ");
        $stmt_u_con->execute([
            $_POST['salario_base'],
            $_POST['aux_movilizacion_mensual'],
            $_POST['aux_mov_nocturno_mensual'],
            $_POST['id']
        ]);
        $pdo->commit();
        $mensaje = "<div class='bg-blue-100 text-blue-700 p-4 rounded-xl mb-6 shadow-sm border border-blue-200'>Información actualizada correctamente.</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6'>Error al editar: " . $e->getMessage() . "</div>";
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
        $stmt->execute([$_POST['smlv'], $_POST['sub_trans'], $_POST['p_rn'], $_POST['p_rf']]);
        $mensaje = "<div class='bg-indigo-100 text-indigo-700 p-4 rounded-xl mb-6 border border-indigo-200 shadow-sm'>Parámetros actualizados exitosamente.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6'>Error al guardar ley: " . $e->getMessage() . "</div>";
    }
}

// Carga de datos para las tablas
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
    <title>Gestión de Nómina - Configuración</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .modal-active { overflow: hidden; }
        .glass-panel { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(10px); }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-800 min-h-screen">

    <div class="max-w-7xl mx-auto px-4 py-10">
        
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-10">
            <div>
                <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">Configuración del Sistema</h1>
                <p class="text-slate-500 mt-1 italic">Gestión de talento humano y parámetros legales 2026.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <form action="" method="POST" onsubmit="return confirm('¿Reparar columnas faltantes en la BD?')">
                    <button type="submit" name="reparar_db" class="bg-amber-50 text-amber-600 border border-amber-200 px-4 py-2.5 rounded-xl font-bold hover:bg-amber-100 transition flex items-center gap-2">
                        <i class="fas fa-tools text-sm"></i> REPARAR BD
                    </button>
                </form>
                <button onclick="document.getElementById('modalCrear').style.display='flex'" class="bg-indigo-600 text-white px-6 py-2.5 rounded-xl font-bold shadow-lg shadow-indigo-200 hover:bg-indigo-700 transition flex items-center gap-2">
                    <i class="fas fa-plus"></i> NUEVO EMPLEADO
                </button>
                <a href="index.php" class="bg-white border border-slate-200 px-6 py-2.5 rounded-xl font-bold hover:bg-slate-50 transition">
                    VOLVER
                </a>
            </div>
        </div>

        <?= $mensaje ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- Listado de Empleados -->
            <div class="lg:col-span-8 space-y-6">
                <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                        <h3 class="font-bold text-lg flex items-center gap-2">
                            <i class="fas fa-users text-indigo-500"></i> Nómina Activa
                        </h3>
                        <span class="bg-indigo-100 text-indigo-700 text-xs px-3 py-1 rounded-full font-bold"><?= count($empleados) ?> Registrados</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[11px] uppercase tracking-wider text-slate-400 font-bold bg-slate-50/80">
                                    <th class="px-6 py-4">Empleado</th>
                                    <th class="px-6 py-4 text-center">Salario Base</th>
                                    <th class="px-6 py-4 text-center">Aux. Movilidad</th>
                                    <th class="px-6 py-4 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach($empleados as $emp): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors group">
                                    <td class="px-6 py-5">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold">
                                                <?= substr($emp['nombre_completo'], 0, 1) ?>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-900 leading-none"><?= $emp['nombre_completo'] ?></p>
                                                <p class="text-xs text-slate-400 mt-1"><?= $emp['cedula'] ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-5 text-center font-medium">$<?= number_format($emp['salario_base'], 0) ?></td>
                                    <td class="px-6 py-5 text-center">
                                        <span class="text-slate-500 text-xs">$<?= number_format($emp['aux_movilizacion_mensual'], 0) ?></span>
                                    </td>
                                    <td class="px-6 py-5 text-right">
                                        <button onclick='abrirModalEditar(<?= json_encode($emp) ?>)' class="w-9 h-9 rounded-lg bg-slate-100 text-slate-600 hover:bg-indigo-600 hover:text-white transition-all flex items-center justify-center inline-flex">
                                            <i class="fas fa-edit text-sm"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Panel Lateral de Ley -->
            <div class="lg:col-span-4">
                <div class="bg-slate-900 text-white rounded-3xl p-8 shadow-2xl relative overflow-hidden sticky top-8">
                    <i class="fas fa-balance-scale absolute -right-6 -top-6 text-9xl text-white/5"></i>
                    
                    <h3 class="text-xl font-bold mb-6 flex items-center gap-2 relative z-10">
                        <i class="fas fa-gavel text-indigo-400"></i> Variables de Ley
                    </h3>

                    <?php if($config_ley): ?>
                    <form action="" method="POST" class="space-y-5 relative z-10">
                        <div>
                            <label class="text-[10px] uppercase font-bold text-slate-400 tracking-widest block mb-2">Salario Mínimo (SMLV)</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">$</span>
                                <input type="number" name="smlv" value="<?= $config_ley['valor_smlv'] ?>" class="w-full bg-slate-800 border-none rounded-2xl py-3 pl-8 pr-4 focus:ring-2 focus:ring-indigo-500 font-bold">
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] uppercase font-bold text-slate-400 tracking-widest block mb-2">Auxilio Transporte</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 font-bold">$</span>
                                <input type="number" name="sub_trans" value="<?= $config_ley['subsidio_transporte'] ?>" class="w-full bg-slate-800 border-none rounded-2xl py-3 pl-8 pr-4 focus:ring-2 focus:ring-indigo-500 font-bold">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] uppercase font-bold text-slate-400 tracking-widest block mb-2">% Rec. Nocturno</label>
                                <input type="text" name="p_rn" value="<?= $config_ley['recargo_nocturno'] ?? 35 ?>" class="w-full bg-slate-800 border-none rounded-2xl py-3 px-4 focus:ring-2 focus:ring-indigo-500 font-bold text-center">
                            </div>
                            <div>
                                <label class="text-[10px] uppercase font-bold text-slate-400 tracking-widest block mb-2">% Rec. Festivo</label>
                                <input type="text" name="p_rf" value="<?= $config_ley['recargo_festivo'] ?? 75 ?>" class="w-full bg-slate-800 border-none rounded-2xl py-3 px-4 focus:ring-2 focus:ring-indigo-500 font-bold text-center">
                            </div>
                        </div>

                        <button type="submit" name="actualizar_ley" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white py-4 rounded-2xl font-bold transition-all shadow-lg shadow-indigo-900/50 flex items-center justify-center gap-2">
                            <i class="fas fa-save"></i> ACTUALIZAR PARÁMETROS
                        </button>
                    </form>
                    <?php else: ?>
                        <div class="bg-red-500/10 border border-red-500/20 p-4 rounded-2xl text-red-400 text-sm">
                            <i class="fas fa-exclamation-triangle mr-2"></i> Error: Tabla de parámetros no encontrada. Use el botón Reparar BD.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL CREAR -->
    <div id="modalCrear" style="display:none" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white rounded-[32px] shadow-2xl max-w-xl w-full overflow-hidden animate-in fade-in zoom-in duration-200">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-extrabold text-slate-900">Alta de Colaborador</h2>
                    <button onclick="document.getElementById('modalCrear').style.display='none'" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times text-xl"></i></button>
                </div>
                <form action="" method="POST" class="space-y-4">
                    <input type="hidden" name="crear_empleado" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-400 uppercase ml-1">Nombre Completo</label>
                            <input type="text" name="nombre_completo" class="w-full border-slate-200 border rounded-xl p-3 focus:ring-2 focus:ring-indigo-500" required>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-400 uppercase ml-1">Cédula / ID</label>
                            <input type="text" name="cedula" class="w-full border-slate-200 border rounded-xl p-3 focus:ring-2 focus:ring-indigo-500" required>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-400 uppercase ml-1">Salario Mensual</label>
                            <input type="number" name="salario_base" class="w-full border-slate-200 border rounded-xl p-3 focus:ring-2 focus:ring-indigo-500" required>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-400 uppercase ml-1">Fecha de Ingreso</label>
                            <input type="date" name="fecha_ingreso" value="<?= date('Y-m-d') ?>" class="w-full border-slate-200 border rounded-xl p-3 focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                    <div class="bg-indigo-50 p-4 rounded-2xl space-y-3 border border-indigo-100 mt-4">
                        <p class="text-[10px] font-black text-indigo-400 uppercase tracking-widest">Compensaciones Extra (Mensual)</p>
                        <div class="grid grid-cols-2 gap-4">
                            <input type="number" name="aux_movilizacion_mensual" placeholder="Aux. Movilidad" class="w-full bg-white border-none rounded-lg p-2.5 text-sm shadow-inner">
                            <input type="number" name="aux_mov_nocturno_mensual" placeholder="Aux. Nocturno" class="w-full bg-white border-none rounded-lg p-2.5 text-sm shadow-inner">
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2">
                        <input type="checkbox" name="es_direccion_confianza" id="confianza" class="w-5 h-5 accent-indigo-600">
                        <label for="confianza" class="text-sm font-bold text-slate-600">Es Personal de Dirección y Confianza</label>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-2xl font-bold mt-6 shadow-lg shadow-indigo-100">REGISTRAR EN EL SISTEMA</button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR -->
    <div id="modalEditar" style="display:none" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 items-center justify-center p-4">
        <div class="bg-white rounded-[32px] shadow-2xl max-w-xl w-full overflow-hidden">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-extrabold text-slate-900">Editar Colaborador</h2>
                    <button onclick="document.getElementById('modalEditar').style.display='none'" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times text-xl"></i></button>
                </div>
                <form action="" method="POST" class="space-y-4">
                    <input type="hidden" name="editar_empleado" value="1">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-400 uppercase ml-1">Nombre</label>
                            <input type="text" name="nombre_completo" id="edit_nombre" class="w-full border-slate-200 border rounded-xl p-3">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-400 uppercase ml-1">Cédula</label>
                            <input type="text" name="cedula" id="edit_cedula" class="w-full border-slate-200 border rounded-xl p-3">
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold text-slate-400 uppercase ml-1">Salario Base Actual</label>
                        <input type="number" name="salario_base" id="edit_salario" class="w-full border-slate-200 border rounded-xl p-3">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-400 uppercase ml-1">Aux. Movilidad</label>
                            <input type="number" name="aux_movilizacion_mensual" id="edit_aux_mov" class="w-full border-slate-200 border rounded-xl p-3">
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold text-slate-400 uppercase ml-1">Aux. Nocturno</label>
                            <input type="number" name="aux_mov_nocturno_mensual" id="edit_aux_noc" class="w-full border-slate-200 border rounded-xl p-3">
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-slate-900 text-white py-4 rounded-2xl font-bold mt-6 shadow-lg">GUARDAR CAMBIOS</button>
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
            
            document.getElementById('modalEditar').style.display = 'flex';
        }
    </script>
</body>
</html>
