<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'config/db.php';

$mensaje = "";

/**
 * =========================================================
 * SINCRONIZAR BASE DE DATOS
 * =========================================================
 */
if (isset($_POST['reparar_db'])) {

    try {

        $columnasLey = $pdo->query("SHOW COLUMNS FROM config_ley")
            ->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('recargo_nocturno', $columnasLey)) {
            $pdo->exec("ALTER TABLE config_ley ADD recargo_nocturno DECIMAL(5,2) DEFAULT 35.00");
        }

        if (!in_array('recargo_festivo', $columnasLey)) {
            $pdo->exec("ALTER TABLE config_ley ADD recargo_festivo DECIMAL(5,2) DEFAULT 75.00");
        }

        if (!in_array('recargo_festivo_nocturno', $columnasLey)) {
            $pdo->exec("ALTER TABLE config_ley ADD recargo_festivo_nocturno DECIMAL(5,2) DEFAULT 110.00");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS festivos (
                fecha DATE PRIMARY KEY,
                descripcion VARCHAR(100)
            )
        ");

        $mensaje = "Base de datos sincronizada.";

    } catch (Exception $e) {

        $mensaje = "Error sincronizando: " . $e->getMessage();
    }
}

/**
 * =========================================================
 * CARGA MASIVA CSV FESTIVOS
 * =========================================================
 */
if (isset($_FILES['csv_festivos']) && $_FILES['csv_festivos']['size'] > 0) {

    try {

        $handle = fopen($_FILES['csv_festivos']['tmp_name'], "r");

        fgetcsv($handle);

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO festivos (fecha, descripcion)
            VALUES (?, ?)
        ");

        while (($datos = fgetcsv($handle, 1000, ",")) !== FALSE) {

            if (count($datos) >= 2) {

                $descripcion = trim($datos[0]);
                $fecha = trim($datos[1]);

                $stmt->execute([$fecha, $descripcion]);
            }
        }

        fclose($handle);

        $mensaje = "Festivos importados correctamente.";

    } catch (Exception $e) {

        $mensaje = "Error importando CSV.";
    }
}

/**
 * =========================================================
 * ELIMINAR FESTIVO
 * =========================================================
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
 * =========================================================
 * AGREGAR FESTIVO MANUAL
 * =========================================================
 */
if (isset($_POST['agregar_festivo'])) {

    try {

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO festivos (fecha, descripcion)
            VALUES (?, ?)
        ");

        $stmt->execute([
            $_POST['fecha_festivo'],
            $_POST['desc_festivo']
        ]);

        $mensaje = "Festivo agregado.";

    } catch (Exception $e) {

        $mensaje = "Error agregando festivo.";
    }
}

/**
 * =========================================================
 * GUARDAR CONFIG LEY
 * =========================================================
 */
if (isset($_POST['guardar_ley'])) {

    try {

        $stmt = $pdo->prepare("
            INSERT INTO config_ley (
                fecha_inicio,
                fecha_fin,
                smmlv,
                aux_transporte_ley,
                hora_inicio_nocturna,
                hora_fin_nocturna,
                porc_salud_trabajador,
                porc_pension_trabajador,
                jornada_semanal_horas,
                recargo_nocturno,
                recargo_festivo,
                recargo_festivo_nocturno
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

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
 * =========================================================
 * CREAR EMPLEADO
 * =========================================================
 */
if (isset($_POST['accion_empleado']) && $_POST['accion_empleado'] == 'crear') {

    try {

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO empleados (
                cedula,
                nombre_completo,
                fecha_ingreso,
                estado
            ) VALUES (?, ?, ?, 'Activo')
        ");

        $stmt->execute([
            $_POST['cedula'],
            $_POST['nombre'],
            $_POST['fecha_ingreso']
        ]);

        $empleado_id = $pdo->lastInsertId();

        $stmtContrato = $pdo->prepare("
            INSERT INTO contratos (
                empleado_id,
                salario_base,
                es_direccion_confianza,
                aux_movilizacion,
                aux_mov_nocturno,
                fecha_inicio,
                activo
            ) VALUES (?, ?, ?, ?, ?, ?, 1)
        ");

        $stmtContrato->execute([
            $empleado_id,
            $_POST['salario'],
            isset($_POST['direccion_confianza']) ? 1 : 0,
            $_POST['aux_mov'],
            $_POST['aux_mov_noct'],
            $_POST['fecha_ingreso']
        ]);

        $pdo->commit();

        $mensaje = "Empleado registrado.";

    } catch (Exception $e) {

        $pdo->rollBack();

        $mensaje = "Error registrando empleado: " . $e->getMessage();
    }
}

/**
 * =========================================================
 * EDITAR EMPLEADO
 * =========================================================
 */
if (isset($_POST['accion_empleado']) && $_POST['accion_empleado'] == 'editar') {

    try {

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE empleados
            SET
                cedula = ?,
                nombre_completo = ?,
                fecha_ingreso = ?,
                estado = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $_POST['cedula'],
            $_POST['nombre'],
            $_POST['fecha_ingreso'],
            $_POST['estado'],
            $_POST['id_empleado']
        ]);

        $stmtContrato = $pdo->prepare("
            UPDATE contratos
            SET
                salario_base = ?,
                es_direccion_confianza = ?,
                aux_movilizacion = ?,
                aux_mov_nocturno = ?
            WHERE empleado_id = ?
            AND activo = 1
            LIMIT 1
        ");

        $stmtContrato->execute([
            $_POST['salario'],
            isset($_POST['direccion_confianza']) ? 1 : 0,
            $_POST['aux_mov'],
            $_POST['aux_mov_noct'],
            $_POST['id_empleado']
        ]);

        $pdo->commit();

        $mensaje = "Empleado actualizado.";

    } catch (Exception $e) {

        $pdo->rollBack();

        $mensaje = "Error actualizando empleado.";
    }
}

/**
 * =========================================================
 * CONSULTAS
 * =========================================================
 */
$ley_actual = $pdo->query("
    SELECT *
    FROM config_ley
    ORDER BY fecha_inicio DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$empleados = $pdo->query("
    SELECT
        e.*,
        c.salario_base,
        c.es_direccion_confianza,
        c.aux_movilizacion,
        c.aux_mov_nocturno,
        c.fecha_inicio AS fecha_inicio_contrato
    FROM empleados e
    LEFT JOIN contratos c
        ON e.id = c.empleado_id
    WHERE c.activo = 1
    ORDER BY e.nombre_completo ASC
")->fetchAll(PDO::FETCH_ASSOC);

$festivos = $pdo->query("
    SELECT *
    FROM festivos
    ORDER BY fecha ASC
")->fetchAll(PDO::FETCH_ASSOC);

$conceptos = $pdo->query("
    SELECT *
    FROM conceptos_recargos
    ORDER BY codigo ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
```

# HTML COMPLETO PARA LOS MÓDULOS DE CONFIGURACIÓN

```php
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Nómina</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        .modal {
            transition: opacity .25s ease;
        }

        body.modal-active {
            overflow: hidden;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen pb-12">

<nav class="bg-slate-900 p-4 shadow-xl text-white mb-6">

    <div class="container mx-auto flex justify-between items-center">

        <h1 class="text-xl font-bold text-indigo-400">
            NOMINA
            <span class="text-white font-light">| Configuración</span>
        </h1>

        <div class="flex space-x-4">
            <a href="asistencia.php" class="hover:text-indigo-400">Asistencia</a>
            <a href="nomina.php" class="hover:text-indigo-400">Nómina</a>
            <a href="index.php"
               class="bg-indigo-600 px-4 py-2 rounded-lg text-sm font-bold">
                Inicio
            </a>
        </div>

    </div>

</nav>

<div class="container mx-auto px-4">

    <?php if($mensaje): ?>

        <div class="bg-emerald-100 border-l-4 border-emerald-500 p-4 mb-6 shadow-sm flex justify-between items-center">
            <span class="text-emerald-800 font-medium">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($mensaje) ?>
            </span>

            <button onclick="this.parentElement.remove()"
                    class="text-emerald-400 text-2xl">
                &times;
            </button>
        </div>

    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <div class="space-y-6">

            <!-- PARAMETROS LEGALES -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">

                <div class="bg-slate-800 p-4 text-white font-bold">
                    <i class="fas fa-percent mr-2 text-indigo-400"></i>
                    Parámetros Legales
                </div>

                <form method="POST" class="p-4 space-y-4">

                    <div class="grid grid-cols-2 gap-4">

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase">
                                Fecha Inicio
                            </label>

                            <input type="date"
                                   name="fecha_inicio"
                                   value="<?= $ley_actual['fecha_inicio'] ?? date('Y-m-d') ?>"
                                   class="w-full bg-slate-50 border rounded p-2 text-sm">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase">
                                Fecha Fin
                            </label>

                            <input type="date"
                                   name="fecha_fin"
                                   value="<?= $ley_actual['fecha_fin'] ?? '' ?>"
                                   class="w-full bg-slate-50 border rounded p-2 text-sm">
                        </div>

                    </div>

                    <div class="grid grid-cols-2 gap-4">

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase">
                                SMMLV
                            </label>

                            <input type="number"
                                   name="smmlv"
                                   value="<?= $ley_actual['smmlv'] ?? 0 ?>"
                                   class="w-full bg-slate-50 border rounded p-2 text-sm">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase">
                                Aux Transporte
                            </label>

                            <input type="number"
                                   name="aux_transporte_ley"
                                   value="<?= $ley_actual['aux_transporte_ley'] ?? 0 ?>"
                                   class="w-full bg-slate-50 border rounded p-2 text-sm">
                        </div>

                    </div>

                    <div class="grid grid-cols-2 gap-4">

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase">
                                Inicio Nocturna
                            </label>

                            <input type="time"
                                   name="hora_inicio_nocturna"
                                   value="<?= $ley_actual['hora_inicio_nocturna'] ?? '19:00:00' ?>"
                                   class="w-full bg-slate-50 border rounded p-2 text-sm">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase">
                                Fin Nocturna
                            </label>

                            <input type="time"
                                   name="hora_fin_nocturna"
                                   value="<?= $ley_actual['hora_fin_nocturna'] ?? '06:00:00' ?>"
                                   class="w-full bg-slate-50 border rounded p-2 text-sm">
                        </div>

                    </div>

                    <div class="grid grid-cols-2 gap-4">

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase">
                                % Salud
                            </label>

                            <input type="number"
                                   step="0.0001"
                                   name="porc_salud_trabajador"
                                   value="<?= $ley_actual['porc_salud_trabajador'] ?? 0.04 ?>"
                                   class="w-full bg-slate-50 border rounded p-2 text-sm">
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase">
                                % Pensión
                            </label>

                            <input type="number"
                                   step="0.0001"
                                   name="porc_pension_trabajador"
                                   value="<?= $ley_actual['porc_pension_trabajador'] ?? 0.04 ?>"
                                   class="w-full bg-slate-50 border rounded p-2 text-sm">
                        </div>

                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase">
                            Jornada Semanal
                        </label>

                        <input type="number"
                               name="jornada_semanal_horas"
                               value="<?= $ley_actual['jornada_semanal_horas'] ?? 44 ?>"
                               class="w-full bg-slate-50 border rounded p-2 text-sm">
                    </div>

                    <div class="space-y-2 border-t pt-3">

                        <div class="flex justify-between items-center text-xs">
                            <span>Recargo Nocturno (%)</span>
                            <input type="number"
                                   step="0.01"
                                   name="recargo_nocturno"
                                   value="<?= $ley_actual['recargo_nocturno'] ?? 35 ?>"
                                   class="w-24 border rounded p-1 text-right bg-slate-50">
                        </div>

                        <div class="flex justify-between items-center text-xs">
                            <span>Recargo Festivo (%)</span>
                            <input type="number"
                                   step="0.01"
                                   name="recargo_festivo"
                                   value="<?= $ley_actual['recargo_festivo'] ?? 75 ?>"
                                   class="w-24 border rounded p-1 text-right bg-slate-50">
                        </div>

                        <div class="flex justify-between items-center text-xs">
                            <span>Festivo Nocturno (%)</span>
                            <input type="number"
                                   step="0.01"
                                   name="recargo_festivo_nocturno"
                                   value="<?= $ley_actual['recargo_festivo_nocturno'] ?? 110 ?>"
                                   class="w-24 border rounded p-1 text-right bg-slate-50">
                        </div>

                    </div>

                    <button type="submit"
                            name="guardar_ley"
                            class="w-full bg-indigo-600 text-white py-2 rounded-lg font-bold hover:bg-indigo-700 transition">
                        Guardar Configuración
                    </button>

                </form>

            </div>

            <!-- FESTIVOS -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">

                <div class="bg-indigo-600 p-4 text-white font-bold flex justify-between items-center">

                    <span>
                        <i class="fas fa-calendar-alt mr-2"></i>
                        Festivos
                    </span>

                    <form method="POST">
                        <button type="submit"
                                name="reparar_db"
                                class="text-[10px] bg-white/20 px-2 py-1 rounded hover:bg-white/30 uppercase">
                            Sincronizar
                        </button>
                    </form>

                </div>

                <div class="p-4 border-b bg-indigo-50">

                    <form method="POST" enctype="multipart/form-data" class="space-y-2">

                        <label class="text-[10px] font-bold text-indigo-500 uppercase block">
                            Carga Masiva CSV
                        </label>

                        <div class="flex items-center space-x-2">

                            <input type="file"
                                   name="csv_festivos"
                                   accept=".csv"
                                   class="text-[10px] flex-1">

                            <button type="submit"
                                    class="bg-indigo-600 text-white px-3 py-1 rounded text-[10px] font-bold uppercase">
                                Subir
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>

</div>

</body>
</html>
