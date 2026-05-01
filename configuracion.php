# CONFIGURACION.PHP COMPLETA

```php
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
