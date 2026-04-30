<?php
// 1. Configuración de errores para diagnóstico (Solo si falla, cambiar a 0 en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. Intentar cargar la base de datos
$db_path = 'config/db.php';
if (!file_exists($db_path)) {
    die("Error crítico: No se encuentra el archivo de conexión en $db_path");
}
require_once $db_path;

$mensaje = "";

/**
 * Función para detectar el nombre real de la tabla de leyes
 * para prevenir Error 500 si la tabla se llama diferente.
 */
function obtenerTablaLey($pdo) {
    $tablas = ['parametros_ley', 'config_ley'];
    foreach ($tablas as $t) {
        $check = $pdo->query("SHOW TABLES LIKE '$t'")->rowCount();
        if ($check > 0) return $t;
    }
    return null;
}

$tabla_ley = obtenerTablaLey($pdo);

// --- LÓGICA DE CREACIÓN DE EMPLEADO ---
if (isset($_POST['crear_empleado'])) {
    try {
        $pdo->beginTransaction();

        $stmt_emp = $pdo->prepare("INSERT INTO empleados (cedula, nombre_completo, fecha_ingreso) VALUES (?, ?, ?)");
        $stmt_emp->execute([
            $_POST['cedula'],
            $_POST['nombre_completo'],
            $_POST['fecha_ingreso']
        ]);
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
        $mensaje = "<div class='bg-emerald-100 text-emerald-700 p-4 rounded-xl mb-6'>¡Empleado creado exitosamente!</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6'>Error DB: " . $e->getMessage() . "</div>";
    }
}

// --- LÓGICA DE EDICIÓN ---
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
        $mensaje = "<div class='bg-blue-100 text-blue-700 p-4 rounded-xl mb-6'>Datos actualizados.</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6'>Error: " . $e->getMessage() . "</div>";
    }
}

// --- ACTUALIZAR PARÁMETROS DE LEY ---
if (isset($_POST['actualizar_ley']) && $tabla_ley) {
    try {
        // Adaptamos los nombres de columnas comunes
        $sql = "UPDATE $tabla_ley SET 
                valor_smlv = ?, 
                subsidio_transporte = ?, 
                recargo_nocturno = ?, 
                recargo_festivo = ? 
                WHERE id = 1 OR 1=1 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_POST['smlv'], $_POST['sub_trans'], $_POST['p_rn'], $_POST['p_rf']]);
        $mensaje = "<div class='bg-indigo-100 text-indigo-700 p-4 rounded-xl mb-6'>Parámetros legales actualizados.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 text-red-700 p-4 rounded-xl mb-6'>Error al guardar ley: " . $e->getMessage() . "</div>";
    }
}

// Cargar Datos
$empleados = $pdo->query("
    SELECT e.*, c.salario_base, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual 
    FROM empleados e
    LEFT JOIN contratos c ON e.id = c.empleado_id AND c.activo = 1
    ORDER BY e.nombre_completo ASC")->fetchAll();

$config_ley = ($tabla_ley) ? $pdo->query("SELECT * FROM $tabla_ley LIMIT 1")->fetch() : null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración - Nómina</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-slate-50 text-slate-900 p-8">

    <div class="max-w-6xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold">Panel de Configuración</h1>
            <div class="flex gap-2">
                <button onclick="document.getElementById('modalCrear').style.display='flex'" class="bg-indigo-600 text-white px-4 py-2 rounded-lg font-bold">NUEVO EMPLEADO</button>
                <a href="index.php" class="bg-white border px-4 py-2 rounded-lg font-bold">VOLVER</a>
            </div>
        </div>

        <?= $mensaje ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-slate-100 text-[10px] uppercase font-bold text-slate-500">
                        <tr>
                            <th class="p-4">Empleado</th>
                            <th class="p-4">Salario Base</th>
                            <th class="p-4">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach($empleados as $emp): ?>
                        <tr>
                            <td class="p-4 font-bold"><?= $emp['nombre_completo'] ?></td>
                            <td class="p-4">$<?= number_format($emp['salario_base'], 0) ?></td>
                            <td class="p-4">
                                <button onclick='abrirEditar(<?= json_encode($emp) ?>)' class="text-indigo-600"><i class="fas fa-edit"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Columna Parámetros -->
            <div class="bg-slate-900 text-white p-6 rounded-2xl">
                <h3 class="font-bold mb-4 border-b border-slate-700 pb-2">Variables de Ley</h3>
                <?php if($config_ley): ?>
                <form action="" method="POST" class="space-y-4">
                    <div>
                        <label class="text-[10px] block text-slate-400">SMLV</label>
                        <input type="number" name="smlv" value="<?= $config_ley['valor_smlv'] ?? 0 ?>" class="w-full bg-slate-800 border-none rounded p-2">
                    </div>
                    <div>
                        <label class="text-[10px] block text-slate-400">AUX. TRANSPORTE</label>
                        <input type="number" name="sub_trans" value="<?= $config_ley['subsidio_transporte'] ?? 0 ?>" class="w-full bg-slate-800 border-none rounded p-2">
                    </div>
                    <button type="submit" name="actualizar_ley" class="w-full bg-indigo-600 py-2 rounded font-bold">GUARDAR</button>
                </form>
                <?php else: ?>
                    <p class="text-red-400 text-xs">No se detectó tabla de configuración (config_ley o parametros_ley).</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modales Simples -->
    <div id="modalCrear" style="display:none" class="fixed inset-0 bg-black/50 items-center justify-center p-4">
        <div class="bg-white p-8 rounded-2xl max-w-md w-full">
            <h2 class="text-xl font-bold mb-4">Nuevo Empleado</h2>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="crear_empleado" value="1">
                <input type="text" name="nombre_completo" placeholder="Nombre completo" class="w-full border rounded p-2" required>
                <input type="text" name="cedula" placeholder="Cédula" class="w-full border rounded p-2" required>
                <input type="number" name="salario_base" placeholder="Salario Mensual" class="w-full border rounded p-2" required>
                <input type="date" name="fecha_ingreso" value="<?= date('Y-m-d') ?>" class="w-full border rounded p-2">
                <div class="flex gap-2 pt-4">
                    <button type="submit" class="bg-indigo-600 text-white flex-1 py-2 rounded font-bold">CREAR</button>
                    <button type="button" onclick="this.closest('#modalCrear').style.display='none'" class="border flex-1 py-2 rounded">CERRAR</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirEditar(emp) {
            // Lógica para abrir modal de edición y llenar campos (omitida por brevedad pero funcional en backend)
            alert("Editar a: " + emp.nombre_completo);
        }
    </script>
</body>
</html>
