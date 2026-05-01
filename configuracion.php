<?php
error_reporting(E_ALL);
ini_set('display_errors', 1); // Cambiado a 1 para desarrollo, volver a 0 en producción

require_once 'config/db.php';
// Asegurar que PDO lance excepciones
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mensaje = "";

/**
 * SINCRONIZAR BASE DE DATOS
 */
if (isset($_POST['reparar_db'])) {
    try {
        $columnasLey = $pdo->query("SHOW COLUMNS FROM config_ley")->fetchAll(PDO::FETCH_COLUMN);

        $nuevasColumnas = [
            'recargo_nocturno' => "DECIMAL(5,2) DEFAULT 35.00",
            'recargo_festivo' => "DECIMAL(5,2) DEFAULT 75.00",
            'recargo_festivo_nocturno' => "DECIMAL(5,2) DEFAULT 110.00"
        ];

        foreach ($nuevasColumnas as $col => $def) {
            if (!in_array($col, $columnasLey)) {
                $pdo->exec("ALTER TABLE config_ley ADD $col $def");
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS festivos (
            fecha DATE PRIMARY KEY,
            descripcion VARCHAR(100)
        )");

        $mensaje = "Base de datos sincronizada.";
    } catch (Exception $e) {
        $mensaje = "Error sincronizando: " . $e->getMessage();
    }
}

/**
 * CARGA MASIVA CSV FESTIVOS
 */
if (isset($_FILES['csv_festivos']) && $_FILES['csv_festivos']['size'] > 0) {
    try {
        $handle = fopen($_FILES['csv_festivos']['tmp_name'], "r");
        fgetcsv($handle); // Saltar cabecera

        $stmt = $pdo->prepare("INSERT IGNORE INTO festivos (fecha, descripcion) VALUES (?, ?)");

        while (($datos = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($datos) >= 2) {
                // Ajustado según orden común de CSV: suele ser Fecha, Descripción
                $fecha = trim($datos[0]);
                $descripcion = trim($datos[1]);
                
                // Validación básica de fecha para evitar errores de SQL
                if (strtotime($fecha)) {
                    $stmt->execute([$fecha, $descripcion]);
                }
            }
        }
        fclose($handle);
        $mensaje = "Festivos importados correctamente.";
    } catch (Exception $e) {
        $mensaje = "Error importando CSV: " . $e->getMessage();
    }
}

/**
 * ELIMINAR FESTIVO
 */
if (isset($_GET['del_festivo'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM festivos WHERE fecha = ?");
        $stmt->execute([$_GET['del_festivo']]);
        header("Location: configuracion.php?msg=deleted");
        exit;
    } catch (Exception $e) {
        $mensaje = "Error eliminando festivo.";
    }
}

if (isset($_GET['msg']) && $_GET['msg'] == 'deleted') {
    $mensaje = "Festivo eliminado.";
}

/**
 * AGREGAR FESTIVO MANUAL
 */
if (isset($_POST['agregar_festivo'])) {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO festivos (fecha, descripcion) VALUES (?, ?)");
        $stmt->execute([$_POST['fecha_festivo'], $_POST['desc_festivo']]);
        $mensaje = "Festivo agregado.";
    } catch (Exception $e) {
        $mensaje = "Error agregando festivo.";
    }
}

/**
 * GUARDAR CONFIG LEY
 */
if (isset($_POST['guardar_ley'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO config_ley (
            fecha_inicio, fecha_fin, smmlv, aux_transporte_ley, 
            hora_inicio_nocturna, hora_fin_nocturna, porc_salud_trabajador, 
            porc_pension_trabajador, jornada_semanal_horas, recargo_nocturno, 
            recargo_festivo, recargo_festivo_nocturno
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $_POST['fecha_inicio'],
            !empty($_POST['fecha_fin']) ? $_POST['fecha_fin'] : null,
            $_POST['smmlv'],
            $_POST['aux_transporte_ley'],
            $_POST['hora_inicio_nocturna'],
            $_POST['hora_fin_nocturna'],
            $_POST['porc_salud_trabajador'],
            $_POST['porc_pension_trabajador'],
            $_POST['jornada_semanal_horas'],
            $_POST['recargo_nocturno'],
            $_POST['recargo_festivo'],
            $_POST['recargo_festivo_nocturno']
        ]);
        $mensaje = "Parámetros legales actualizados.";
    } catch (Exception $e) {
        $mensaje = "Error guardando ley: " . $e->getMessage();
    }
}

/**
 * CREAR / EDITAR EMPLEADO
 */
if (isset($_POST['accion_empleado'])) {
    try {
        $pdo->beginTransaction();
        if ($_POST['accion_empleado'] == 'crear') {
            $stmt = $pdo->prepare("INSERT INTO empleados (cedula, nombre_completo, fecha_ingreso, estado) VALUES (?, ?, ?, 'Activo')");
            $stmt->execute([$_POST['cedula'], $_POST['nombre'], $_POST['fecha_ingreso']]);
            $empleado_id = $pdo->lastInsertId();

            $stmtContrato = $pdo->prepare("INSERT INTO contratos (empleado_id, salario_base, es_direccion_confianza, aux_movilizacion, aux_mov_nocturno, fecha_inicio, activo) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $stmtContrato->execute([$empleado_id, $_POST['salario'], isset($_POST['direccion_confianza']) ? 1 : 0, $_POST['aux_mov'], $_POST['aux_mov_noct'], $_POST['fecha_ingreso']]);
            $mensaje = "Empleado registrado.";
        } else {
            $stmt = $pdo->prepare("UPDATE empleados SET cedula = ?, nombre_completo = ?, fecha_ingreso = ?, estado = ? WHERE id = ?");
            $stmt->execute([$_POST['cedula'], $_POST['nombre'], $_POST['fecha_ingreso'], $_POST['estado'], $_POST['id_empleado']]);

            $stmtContrato = $pdo->prepare("UPDATE contratos SET salario_base = ?, es_direccion_confianza = ?, aux_movilizacion = ?, aux_mov_nocturno = ? WHERE empleado_id = ? AND activo = 1");
            $stmtContrato->execute([$_POST['salario'], isset($_POST['direccion_confianza']) ? 1 : 0, $_POST['aux_mov'], $_POST['aux_mov_noct'], $_POST['id_empleado']]);
            $mensaje = "Empleado actualizado.";
        }
        $pdo->commit();
    } catch (Exception $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "Error en empleado: " . $e->getMessage();
    }
}

// Consultas para la vista
$ley_actual = $pdo->query("SELECT * FROM config_ley ORDER BY fecha_inicio DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$festivos = $pdo->query("SELECT * FROM festivos ORDER BY fecha ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Nómina</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            <span class="text-emerald-800 font-medium"><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($mensaje) ?></span>
            <button onclick="this.parentElement.remove()" class="text-emerald-400 text-2xl">&times;</button>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- FORMULARIO PARÁMETROS LEGALES -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="bg-slate-800 p-4 text-white font-bold"><i class="fas fa-percent mr-2 text-indigo-400"></i>Parámetros Legales</div>
            <form method="POST" class="p-4 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase">Fecha Inicio</label>
                        <input type="date" name="fecha_inicio" value="<?= $ley_actual['fecha_inicio'] ?? date('Y-m-d') ?>" class="w-full bg-slate-50 border rounded p-2 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase">Fecha Fin</label>
                        <input type="date" name="fecha_fin" value="<?= $ley_actual['fecha_fin'] ?? '' ?>" class="w-full bg-slate-50 border rounded p-2 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase">SMMLV</label>
                        <input type="number" name="smmlv" value="<?= $ley_actual['smmlv'] ?? 0 ?>" class="w-full bg-slate-50 border rounded p-2 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase">Aux Transporte</label>
                        <input type="number" name="aux_transporte_ley" value="<?= $ley_actual['aux_transporte_ley'] ?? 0 ?>" class="w-full bg-slate-50 border rounded p-2 text-sm" required>
                    </div>
                </div>
                <!-- Otros campos del formulario... -->
                <button type="submit" name="guardar_ley" class="w-full bg-indigo-600 text-white py-2 rounded-lg font-bold hover:bg-indigo-700 transition">Guardar Configuración</button>
            </form>
        </div>

        <!-- SECCIÓN FESTIVOS -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="bg-indigo-600 p-4 text-white font-bold flex justify-between items-center">
                    <span><i class="fas fa-calendar-alt mr-2"></i>Festivos Registrados</span>
                    <form method="POST"><button type="submit" name="reparar_db" class="text-[10px] bg-white/20 px-2 py-1 rounded hover:bg-white/30 uppercase">Sincronizar DB</button></form>
                </div>
                
                <div class="p-4 bg-indigo-50 border-b flex flex-wrap gap-4">
                    <form method="POST" enctype="multipart/form-data" class="flex items-center gap-2">
                        <input type="file" name="csv_festivos" accept=".csv" class="text-xs" required>
                        <button type="submit" class="bg-indigo-600 text-white px-3 py-1 rounded text-[10px] font-bold uppercase">Subir CSV</button>
                    </form>
                    <form method="POST" class="flex items-center gap-2 flex-1">
                        <input type="date" name="fecha_festivo" class="border rounded p-1 text-xs" required>
                        <input type="text" name="desc_festivo" placeholder="Descripción" class="border rounded p-1 text-xs flex-1" required>
                        <button type="submit" name="agregar_festivo" class="bg-emerald-600 text-white px-3 py-1 rounded text-[10px] font-bold uppercase">Agregar</button>
                    </form>
                </div>

                <div class="max-h-96 overflow-y-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-100 sticky top-0">
                            <tr>
                                <th class="p-3">Fecha</th>
                                <th class="p-3">Descripción</th>
                                <th class="p-3 text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($festivos as $f): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3 font-mono"><?= $f['fecha'] ?></td>
                                <td class="p-3"><?= htmlspecialchars($f['descripcion']) ?></td>
                                <td class="p-3 text-center">
                                    <a href="?del_festivo=<?= $f['fecha'] ?>" onclick="return confirm('¿Eliminar festivo?')" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></a>
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
</body>
</html>
