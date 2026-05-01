<?php
/**
 * ARCHIVO: guardar_nomina.php
 * OBJETIVO: Procesar el guardado de la liquidación en el histórico y marcar asistencia como procesada.
 */

// Configurar la zona horaria para Colombia
date_default_timezone_set('America/Bogota');

require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verificamos que se haya enviado la acción de guardar
    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar') {
        try {
            // 1. Recolección de datos del formulario (coincidiendo con los 'name' de nomina.php)
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

            // 2. Creación del Snapshot (Requerido por la tabla historico_nomina)
            $snapshot = json_encode([
                "fecha_proceso" => date('Y-m-d H:i:s'),
                "nota" => "Liquidación automatizada v2.0 - Cargo de Confianza",
                "ip" => $_SERVER['REMOTE_ADDR']
            ]);

            // 3. Preparar el SQL de Inserción
            // Nota: Se usan los nombres de columna exactos de tu tabla: valor_salario_pagado, etc.
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

            // 4. Marcar asistencia diaria como 'Procesada'
            // Esto evita que las mismas horas se cobren dos veces en el futuro
            $update_sql = "UPDATE asistencia_diaria 
                           SET procesado_en_nomina = 1 
                           WHERE empleado_id = (SELECT empleado_id FROM contratos WHERE id = ?) 
                           AND fecha BETWEEN ? AND ?";
            
            $stmt_update = $pdo->prepare($update_sql);
            $stmt_update->execute([$contrato_id, $periodo_desde, $periodo_hasta]);

            // 5. Redirección con éxito
            header("Location: nomina.php?status=success");
            exit();

        } catch (Exception $e) {
            // Si hay un error, lo mostramos para evitar la pantalla en blanco
            echo "<div style='font-family:sans-serif; padding:20px; border:2px solid red; border-radius:10px; background:#fff5f5;'>";
            echo "<h2 style='color:red;'>Error al procesar el pago</h2>";
            echo "<p>Detalle técnico: " . $e->getMessage() . "</p>";
            echo "<a href='nomina.php'>Volver a intentar</a>";
            echo "</div>";
            exit();
        }
    }
}

// Si se intenta acceder por URL sin enviar datos
header("Location: nomina.php");
exit();
