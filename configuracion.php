<?php
// Desactivar visualización de errores para que no rompan el HTML en producción
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config/db.php';

$mensaje = "";

/**
 * FUNCIÓN DE AUTO-REPARACIÓN
 * Crea las tablas si no existen para evitar el Fatal Error
 */
function asegurarEstructura($pdo) {
    try {
        // Crear tabla de configuración de ley si no existe
        $pdo->exec("CREATE TABLE IF NOT EXISTS config_ley (
            id INT AUTO_INCREMENT PRIMARY KEY,
            anio INT NOT NULL,
            smlv DECIMAL(15,2) NOT NULL,
            aux_transporte DECIMAL(15,2) DEFAULT 0,
            salud_empleado DECIMAL(5,2) DEFAULT 4.00,
            pension_empleado DECIMAL(5,2) DEFAULT 4.00,
            recargo_nocturno DECIMAL(5,2) DEFAULT 35.00,
            recargo_festivo DECIMAL(5,2) DEFAULT 75.00,
            recargo_festivo_nocturno DECIMAL(5,2) DEFAULT 110.00
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Asegurar que exista al menos un registro inicial
        $check = $pdo->query("SELECT COUNT(*) FROM config_ley")->fetchColumn();
        if ($check == 0) {
            $pdo->exec("INSERT INTO config_ley (anio, smlv, aux_transporte) VALUES (".date('Y').", 1300000, 162000)");
        }

        // Crear tabla de festivos
        $pdo->exec("CREATE TABLE IF NOT EXISTS festivos (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            fecha DATE UNIQUE, 
            descripcion VARCHAR(100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    } catch (Exception $e) {
        return "Error al inicializar tablas: " . $e->getMessage();
    }
}

// Ejecutar aseguramiento de tablas antes de cualquier consulta
asegurarEstructura($pdo);

/**
 * FUNCIÓN PARA DETECTAR COLUMNAS REALES
 */
function obtenerColumnaAnio($pdo) {
    try {
        $rs = $pdo->query("SHOW COLUMNS FROM config_ley");
        $columnas = $rs->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('anio', $columnas)) return 'anio';
        if (in_array('vigencia', $columnas)) return 'vigencia';
        return 'id';
    } catch (Exception $e) {
        return 'id';
    }
}

$col_anio = obtenerColumnaAnio($pdo);

// --- LÓGICA DE REPARACIÓN MANUAL (Botón Sincronizar) ---
if (isset($_POST['reparar_db'])) {
    try {
        $pdo->exec("ALTER TABLE config_ley ADD COLUMN IF NOT EXISTS recargo_nocturno DECIMAL(5,2) DEFAULT 35.00");
        $pdo->exec("ALTER TABLE config_ley ADD COLUMN IF NOT EXISTS recargo_festivo DECIMAL(5,2) DEFAULT 75.00");
        $pdo->exec("ALTER TABLE config_ley ADD COLUMN IF NOT EXISTS recargo_festivo_nocturno DECIMAL(5,2) DEFAULT 110.00");
        $mensaje = "Estructura sincronizada correctamente.";
    } catch (Exception $e) { $mensaje = "Aviso: " . $e->getMessage(); }
}

// --- CARGA DE CSV ---
if (isset($_FILES['csv_festivos']) && $_FILES['csv_festivos']['size'] > 0) {
    $handle = fopen($_FILES['csv_festivos']['tmp_name'], "r");
    fgetcsv($handle); // saltar cabecera
    $stmt = $pdo->prepare("INSERT IGNORE INTO festivos (descripcion, fecha) VALUES (?, ?)");
    while (($datos = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($datos) >= 2) $stmt->execute([trim($datos[0]), trim($datos[1])]);
    }
    fclose($handle);
    $mensaje = "Festivos importados correctamente.";
}

// --- ELIMINAR FESTIVO ---
if (isset($_GET['del_festivo'])) {
    $stmt = $pdo->prepare("DELETE FROM festivos WHERE id = ?");
    $stmt->execute([(int)$_GET['del_festivo']]);
    header("Location: configuracion.php?msg=deleted");
    exit;
}
if(isset($_GET['msg']) && $_GET['msg'] == 'deleted') $mensaje = "Festivo eliminado.";

// --- AGREGAR FESTIVO INDIVIDUAL ---
if (isset($_POST['agregar_festivo'])) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO festivos (fecha, descripcion) VALUES (?, ?)");
    $stmt->execute([$_POST['fecha_festivo'], $_POST['desc_festivo']]);
    $mensaje = "Festivo agregado.";
}

// --- GUARDAR PARÁMETROS LEY ---
if (isset($_POST['guardar_ley'])) {
    $stmt = $pdo->prepare("INSERT INTO config_ley ($col_anio, smlv, aux_transporte, salud_empleado, pension_empleado, recargo_nocturno, recargo_festivo, recargo_festivo_nocturno) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['anio_val'], $_POST['smlv'], $_POST['aux_t'], $_POST['salud'], $_POST['pension'], $_POST['r_noc'], $_POST['r_fes'], $_POST['r_fes_noc']]);
    $mensaje = "Parámetros actualizados correctamente.";
}

// --- ACCIONES DE EMPLEADO (CREAR Y EDITAR) ---
if (isset($_POST['accion_empleado'])) {
    if ($_POST['accion_empleado'] == 'crear') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO empleados (cedula, nombre_completo) VALUES (?, ?)");
            $stmt->execute([$_POST['cedula'], $_POST['nombre']]);
            $emp_id = $pdo->lastInsertId();
            
            // Verificamos si la tabla contratos existe antes de insertar
            $pdo->exec("CREATE TABLE IF NOT EXISTS contratos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                empleado_id INT,
                salario_base DECIMAL(15,2),
                aux_movilizacion DECIMAL(15,2) DEFAULT 0,
                aux_mov_nocturno DECIMAL(15,2) DEFAULT 0,
                fecha_inicio DATE,
                activo TINYINT DEFAULT 1
            )");

            $stmt_c = $pdo->prepare("INSERT INTO contratos (empleado_id, salario_base, aux_movilizacion, aux_mov_nocturno, fecha_inicio, activo) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt_c->execute([$emp_id, $_POST['salario'], $_POST['aux_mov'] ?? 0, $_POST['aux_mov_noct'] ?? 0, date('Y-m-d')]);
            $pdo->commit();
            $mensaje = "Empleado registrado con éxito.";
        } catch (Exception $e) { $pdo->rollBack(); $mensaje = "Error: " . $e->getMessage(); }
    } 
    elseif ($_POST['accion_empleado'] == 'editar') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE empleados SET cedula = ?, nombre_completo = ? WHERE id = ?");
            $stmt->execute([$_POST['cedula'], $_POST['nombre'], $_POST['id_empleado']]);
            
            $stmt_c = $pdo->prepare("UPDATE contratos SET salario_base = ?, aux_movilizacion = ?, aux_mov_nocturno = ? WHERE empleado_id = ? AND activo = 1");
            $stmt_c->execute([$_POST['salario'], $_POST['aux_mov'], $_POST['aux_mov_noct'], $_POST['id_empleado']]);
            
            $pdo->commit();
            $mensaje = "Datos de empleado actualizados.";
        } catch (Exception $e) { $pdo->rollBack(); $mensaje = "Error al editar: " . $e->getMessage(); }
    }
}

// Carga final de datos para la vista
$empleados = $pdo->query("SELECT e.*, c.salario_base, c.aux_movilizacion, c.aux_mov_nocturno FROM empleados e LEFT JOIN contratos c ON e.id = c.empleado_id WHERE c.activo = 1 OR c.activo IS NULL ORDER BY e.nombre_completo ASC")->fetchAll();
$ley_actual = $pdo->query("SELECT * FROM config_ley ORDER BY $col_anio DESC LIMIT 1")->fetch();
$festivos = $pdo->query("SELECT * FROM festivos ORDER BY fecha ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Nómina</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .modal { transition: opacity 0.25s ease; }
        body.modal-active { overflow: hidden; }
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen pb-12">

    <nav class="bg-slate-900 p-4 shadow-xl text-white mb-6">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-xl font-bold text-indigo-400">NOMINA <span class="text-white font-light">| Configuración</span></h1>
            <div class="flex space-x-4">
                <a href="asistencia.php" class="hover:text-indigo-400">Asistencia</a>
                <a href="nomina.php" class="hover:text-indigo-400">Nómina</a>
                <a href="index.php" class="bg-indigo-600 px-4 py-2 rounded-lg text-sm font-bold">Inicio</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4">
        <?php if($mensaje): ?>
            <div class="bg-emerald-100 border-l-4 border-emerald-500 p-4 mb-6 shadow-sm flex justify-between items-center">
                <span class="text-emerald-800 font-medium"><i class="fas fa-check-circle mr-2"></i> <?= htmlspecialchars($mensaje) ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 text-2xl">&times;</button>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- PANEL LEGAL Y FESTIVOS -->
            <div class="space-y-6">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="bg-slate-800 p-4 text-white font-bold">
                        <i class="fas fa-percent mr-2 text-indigo-400"></i> Parámetros Legales
                    </div>
                    <form method="POST" class="p-4 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase">Año</label>
                                <input type="number" name="anio_val" value="<?= $ley_actual[$col_anio] ?? date('Y') ?>" class="w-full bg-slate-50 border rounded p-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase">SMLV</label>
                                <input type="number" name="smlv" value="<?= $ley_actual['smlv'] ?? 0 ?>" class="w-full bg-slate-50 border rounded p-2 text-sm">
                            </div>
                        </div>
                        <div class="space-y-2 border-t pt-2">
                            <div class="flex justify-between text-xs items-center text-slate-600">
                                <span>Recargo Nocturno (%)</span>
                                <input type="number" step="0.1" name="r_noc" value="<?= $ley_actual['recargo_nocturno'] ?? 35 ?>" class="w-20 border rounded p-1 text-right bg-slate-50">
                            </div>
                            <div class="flex justify-between text-xs items-center text-slate-600">
                                <span>Recargo Festivo (%)</span>
                                <input type="number" step="0.1" name="r_fes" value="<?= $ley_actual['recargo_festivo'] ?? 75 ?>" class="w-20 border rounded p-1 text-right bg-slate-50">
                            </div>
                            <div class="flex justify-between text-xs items-center text-slate-600">
                                <span>Festivo Nocturno (%)</span>
                                <input type="number" step="0.1" name="r_fes_noc" value="<?= $ley_actual['recargo_festivo_nocturno'] ?? 110 ?>" class="w-20 border rounded p-1 text-right bg-slate-50">
                            </div>
                        </div>
                        <input type="hidden" name="salud" value="<?= $ley_actual['salud_empleado'] ?? 4 ?>">
                        <input type="hidden" name="pension" value="<?= $ley_actual['pension_empleado'] ?? 4 ?>">
                        <input type="hidden" name="aux_t" value="<?= $ley_actual['aux_transporte'] ?? 0 ?>">
                        <button type="submit" name="guardar_ley" class="w-full bg-indigo-600 text-white py-2 rounded-lg font-bold hover:bg-indigo-700 transition shadow-lg shadow-indigo-200">
                            Actualizar Ley
                        </button>
                    </form>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="bg-indigo-600 p-4 text-white font-bold flex justify-between items-center">
                        <span><i class="fas fa-calendar-alt mr-2"></i> Festivos</span>
                        <form method="POST" onsubmit="return confirm('¿Sincronizar base de datos?')">
                            <button type="submit" name="reparar_db" class="text-[9px] bg-white/20 px-2 py-1 rounded hover:bg-white/30 uppercase">Sincronizar</button>
                        </form>
                    </div>
                    
                    <div class="p-4 bg-indigo-50 border-b">
                        <form method="POST" enctype="multipart/form-data" class="space-y-2">
                            <label class="text-[10px] font-bold text-indigo-400 uppercase block">Carga Masiva (CSV)</label>
                            <div class="flex items-center space-x-2">
                                <input type="file" name="csv_festivos" accept=".csv" class="text-[10px] flex-1">
                                <button type="submit" class="bg-indigo-600 text-white px-2 py-1 rounded text-[10px] font-bold uppercase">Subir</button>
                            </div>
                        </form>
                    </div>

                    <div class="max-h-80 overflow-y-auto divide-y divide-slate-50">
                        <?php foreach($festivos as $f): ?>
                        <div class="flex justify-between items-center p-3 hover:bg-slate-50">
                            <div class="text-sm">
                                <div class="font-bold text-slate-700"><?= $f['fecha'] ?></div>
                                <div class="text-[11px] text-slate-500 uppercase"><?= htmlspecialchars($f['descripcion']) ?></div>
                            </div>
                            <a href="configuracion.php?del_festivo=<?= $f['id'] ?>" 
                               onclick="return confirm('¿Eliminar festivo?')"
                               class="text-slate-300 hover:text-red-500 p-2">
                                <i class="fas fa-trash-alt"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- COLUMNA EMPLEADOS -->
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-4 bg-slate-50 border-b font-bold text-slate-700">
                        <i class="fas fa-user-plus mr-2 text-indigo-500"></i> Registro de Empleado
                    </div>
                    <form method="POST" class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <input type="hidden" name="accion_empleado" value="crear">
                        <div class="md:col-span-2">
                            <label class="text-[10px] font-bold text-slate-400 uppercase">Nombre Completo</label>
                            <input type="text" name="nombre" class="w-full border rounded p-2 text-sm" required>
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase">Cédula</label>
                            <input type="text" name="cedula" class="w-full border rounded p-2 text-sm" required>
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-400 uppercase">Salario Base</label>
                            <input type="number" name="salario" class="w-full border rounded p-2 text-sm" required>
                        </div>
                        <div class="lg:col-span-4 flex justify-end">
                            <button type="submit" class="bg-slate-900 text-white px-6 py-2 rounded-lg font-bold hover:bg-black transition">
                                <i class="fas fa-save mr-2"></i> Guardar Nuevo
                            </button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 text-[10px] uppercase font-bold text-slate-400 border-b">
                            <tr>
                                <th class="px-6 py-3">Colaborador</th>
                                <th class="px-6 py-3">Salario</th>
                                <th class="px-6 py-3 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($empleados as $e): ?>
                            <tr class="text-sm hover:bg-slate-50 transition">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-slate-700"><?= htmlspecialchars($e['nombre_completo']) ?></div>
                                    <div class="text-[10px] text-slate-400">CC: <?= $e['cedula'] ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium">$<?= number_format($e['salario_base'], 0) ?></div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick='abrirModalEditar(<?= json_encode($e) ?>)' 
                                            class="text-indigo-600 hover:bg-indigo-100 w-8 h-8 rounded-full inline-flex items-center justify-center">
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

    <!-- MODAL EDICIÓN -->
    <div id="modalEditar" class="opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50 modal">
        <div class="modal-overlay absolute w-full h-full bg-slate-900 opacity-50"></div>
        <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded-xl shadow-2xl z-50 overflow-y-auto">
            <div class="modal-content py-6 text-left px-8">
                <div class="flex justify-between items-center pb-3 border-b">
                    <p class="text-xl font-bold">Editar Empleado</p>
                    <div class="modal-close cursor-pointer" onclick="cerrarModal()">
                        <i class="fas fa-times text-slate-400"></i>
                    </div>
                </div>
                <form method="POST" class="mt-6 space-y-4">
                    <input type="hidden" name="accion_empleado" value="editar">
                    <input type="hidden" name="id_empleado" id="edit_id">
                    
                    <input type="text" name="nombre" id="edit_nombre" placeholder="Nombre" class="w-full border rounded p-3 text-sm" required>
                    <input type="text" name="cedula" id="edit_cedula" placeholder="Cédula" class="w-full border rounded p-3 text-sm" required>
                    <input type="number" name="salario" id="edit_salario" placeholder="Salario" class="w-full border rounded p-3 text-sm" required>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <input type="number" name="aux_mov" id="edit_aux_mov" placeholder="Aux. Mov" class="border rounded p-3 text-sm">
                        <input type="number" name="aux_mov_noct" id="edit_aux_mov_noct" placeholder="Aux. Noct" class="border rounded p-3 text-sm">
                    </div>

                    <div class="flex justify-end pt-6 space-x-3">
                        <button type="button" onclick="cerrarModal()" class="px-4 py-2 text-slate-400 font-bold">Cerrar</button>
                        <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg font-bold">Actualizar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function abrirModalEditar(e) {
            document.getElementById('edit_id').value = e.id;
            document.getElementById('edit_nombre').value = e.nombre_completo;
            document.getElementById('edit_cedula').value = e.cedula;
            document.getElementById('edit_salario').value = e.salario_base;
            document.getElementById('edit_aux_mov').value = e.aux_movilizacion || 0;
            document.getElementById('edit_aux_mov_noct').value = e.aux_mov_nocturno || 0;

            const modal = document.getElementById('modalEditar');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            document.body.classList.add('modal-active');
        }

        function cerrarModal() {
            const modal = document.getElementById('modalEditar');
            modal.classList.add('opacity-0', 'pointer-events-none');
            document.body.classList.remove('modal-active');
        }
    </script>
</body>
</html>
