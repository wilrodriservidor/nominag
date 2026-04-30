<?php
require_once 'config/db.php';

/**
 * CONTROLADOR DE ACCIONES DE NÓMINA
 * Maneja el guardado, eliminación y marcas de procesamiento.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACCIÓN: GUARDAR NUEVA NÓMINA
    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar') {
        try {
            $datos = [
                $_POST['contrato_id'],
                $_POST['periodo_desde'],
                $_POST['periodo_hasta'],
                $_POST['dias_liquidados'],
                $_POST['salario_pagado'],
                $_POST['recargos_nocturnos'],
                $_POST['recargos_festivos'],
                $_POST['aux_transporte'],
                $_POST['aux_movilizacion'],
                $_POST['aux_mov_nocturno'],
                $_POST['deduccion_salud'],
                $_POST['deduccion_pension'],
                $_POST['neto_pagar']
            ];

            $snapshot = json_encode([
                "fecha_proceso" => date('Y-m-d H:i:s'),
                "ip_origen" => $_SERVER['REMOTE_ADDR'],
                "usuario" => "admin"
            ]);

            $sql = "INSERT INTO historico_nomina (
                        contrato_id, periodo_desde, periodo_hasta, dias_liquidados, 
                        valor_salario_pagado, valor_recargos_nocturnos, valor_recargos_festivos, 
                        valor_aux_transporte, valor_aux_movilizacion, valor_aux_mov_nocturno, 
                        deduccion_salud, deduccion_pension, neto_pagar, json_snapshot_ley
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $params = array_merge($datos, [$snapshot]);
            $stmt->execute($params);

            // Marcamos la asistencia como procesada
            $update_asist = $pdo->prepare("UPDATE asistencia_diaria SET procesado_en_nomina = 1 
                                           WHERE empleado_id = (SELECT empleado_id FROM contratos WHERE id = ?) 
                                           AND fecha BETWEEN ? AND ?");
            $update_asist->execute([$_POST['contrato_id'], $_POST['periodo_desde'], $_POST['periodo_hasta']]);

            header("Location: nomina.php?status=success");
            exit();

        } catch (Exception $e) {
            die("Error al guardar: " . $e->getMessage());
        }
    }
}

// ACCIÓN: ELIMINAR NÓMINA (Vía GET para facilitar desde la tabla de historial)
if (isset($_GET['eliminar_id'])) {
    try {
        $id = $_GET['eliminar_id'];

        // 1. Antes de borrar, liberamos la asistencia (la marcamos como NO procesada)
        // para que se pueda volver a liquidar correctamente.
        $stmt_info = $pdo->prepare("SELECT contrato_id, periodo_desde, periodo_hasta FROM historico_nomina WHERE id = ?");
        $stmt_info->execute([$id]);
        $info = $stmt_info->fetch();

        if ($info) {
            $liberar = $pdo->prepare("UPDATE asistencia_diaria SET procesado_en_nomina = 0 
                                     WHERE empleado_id = (SELECT empleado_id FROM contratos WHERE id = ?) 
                                     AND fecha BETWEEN ? AND ?");
            $liberar->execute([$info['contrato_id'], $info['periodo_desde'], $info['periodo_hasta']]);
        }

        // 2. Borramos el registro del histórico
        $sql_del = "DELETE FROM historico_nomina WHERE id = ?";
        $stmt_del = $pdo->prepare($sql_del);
        $stmt_del->execute([$id]);

        header("Location: historial_pagos.php?status=deleted");
        exit();

    } catch (Exception $e) {
        die("Error al eliminar: " . $e->getMessage());
    }
}

// Nota: Para "Editar", en nómina legal se recomienda "Eliminar y Re-liquidar" 
// para asegurar que los cálculos automáticos se mantengan íntegros.
?>