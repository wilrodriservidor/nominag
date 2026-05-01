<?php
/**
 * ARCHIVO: guardar_nomina.php
 * OBJETIVO: Procesar el guardado de la liquidación y actualizar la asistencia.
 * REGLA DE ORO: Sincronización total con la base de datos u270613792_nomina_gemini.
 */

// Configurar la zona horaria para Colombia
date_default_timezone_set('America/Bogota');

require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACCIÓN: GUARDAR NUEVA NÓMINA
    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar') {
        try {
            // 1. Recolección de datos desde el formulario de nomina.php
            $contrato_id        = $_POST['contrato_id'];
            $periodo_desde      = $_POST['periodo_desde'];
            $periodo_hasta      = $_POST['periodo_hasta'];
            $dias_liquidados    = $_POST['dias_liquidados'] ?? 15;
            $salario_pagado     = $_POST['salario_pagado'];
            $recargos_nocturnos = $_POST['recargos_nocturnos'] ?? 0;
            $recargos_festivos  = $_POST['recargos_festivos'] ?? 0;
            $aux_transporte     = $_POST['aux_transporte'] ?? 0;
            $aux_movilizacion   = $_POST['aux_movilizacion'] ?? 0;
            $aux_mov_nocturno   = $_POST['aux_mov_nocturno'] ?? 0;
            $deduccion_salud    = $_POST['deduccion_salud'] ?? 0;
            $deduccion_pension  = $_POST['deduccion_pension'] ?? 0;
            $neto_pagar         = $_POST['neto_pagar'];

            // 2. Creación del Snapshot JSON (Obligatorio en tu BD)
            $snapshot = json_encode([
                "fecha_proceso" => date('Y-m-d H:i:s'),
                "nota" => "Liquidación procesada - Sistema 2026",
                "metodo" => "Automático via nomina.php"
            ]);

            // 3. Preparar el SQL de Inserción con los nombres de columna reales de tu SQL
            $sql = "INSERT INTO historico_nomina (
                        contrato_id, 
                        periodo_desde, 
                        periodo_hasta, 
                        dias_liquidados, 
                        valor_salario_pagado, 
                        valor_recargos_nocturnos, 
                        valor_recargos_festivos, 
                        valor_aux_transporte, 
                        valor_aux_movilizacion, 
                        valor_aux_mov_nocturno, 
                        deduccion_salud, 
                        deduccion_pension, 
                        neto_pagar, 
                        json_snapshot_ley
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            
            $stmt->execute([
                $contrato_id,
                $periodo_desde,
                $periodo_hasta,
                $dias_liquidados,
                $salario_pagado,
                $recargos_nocturnos,
                $recargos_festivos,
                $aux_transporte,
                $aux_movilizacion,
                $aux_mov_nocturno,
                $deduccion_salud,
                $deduccion_pension,
                $neto_pagar,
                $snapshot
            ]);

            // 4. Marcar asistencia diaria como 'Procesada' (UPDATE)
            // Esto asegura que estas horas no se vuelvan a cobrar.
            $update_sql = "UPDATE asistencia_diaria 
                           SET procesado_en_nomina = 1 
                           WHERE empleado_id = (SELECT empleado_id FROM contratos WHERE id = ?) 
                           AND fecha BETWEEN ? AND ?";
            
            $stmt_update = $pdo->prepare($update_sql);
            $stmt_update->execute([$contrato_id, $periodo_desde, $periodo_hasta]);

            // 5. Redireccionar de vuelta a nomina.php con mensaje de éxito
            header("Location: nomina.php?status=success");
            exit();

        } catch (Exception $e) {
            // Si hay un error, lo mostramos para depurar (esto evita la página en blanco)
            die("<h3>Error al guardar la nómina:</h3>" . $e->getMessage());
        }
    }
}

// ACCIÓN: ELIMINAR NÓMINA (Soportado por tu tabla de historial)
if (isset($_GET['eliminar_id'])) {
    try {
        $id = $_GET['eliminar_id'];

        // Liberar las horas de asistencia antes de borrar el registro
        $stmt_info = $pdo->prepare("SELECT contrato_id, periodo_desde, periodo_hasta FROM historico_nomina WHERE id = ?");
        $stmt_info->execute([$id]);
        $info = $stmt_info->fetch();

        if ($info) {
            $liberar = $pdo->prepare("UPDATE asistencia_diaria SET procesado_en_nomina = 0 
                                     WHERE empleado_id = (SELECT empleado_id FROM contratos WHERE id = ?) 
                                     AND fecha BETWEEN ? AND ?");
            $liberar->execute([$info['contrato_id'], $info['periodo_desde'], $info['periodo_hasta']]);
        }

        $stmt_del = $pdo->prepare("DELETE FROM historico_nomina WHERE id = ?");
        $stmt_del->execute([$id]);

        header("Location: historial_pagos.php?status=deleted");
        exit();

    } catch (Exception $e) {
        die("Error al eliminar: " . $e->getMessage());
    }
}

// Fallback: Si se accede sin POST ni GET válido
header("Location: nomina.php");
exit();
