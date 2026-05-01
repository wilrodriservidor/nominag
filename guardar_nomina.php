<?php
// Configurar la zona horaria para Colombia
date_default_timezone_set('America/Bogota');
require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. Mapeo de datos (Asegúrate de que coincidan con los 'name' del formulario)
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

        // 2. Snapshot Obligatorio (Si este campo falta o es NULL, la BD rechaza el registro)
        $snapshot = json_encode([
            "fecha_pago" => date('Y-m-d H:i:s'),
            "nota" => "Liquidación generada vía Web",
            "version" => "1.0"
        ]);

        // 3. SQL de Inserción (Usando los nombres de columna reales de tu tabla)
        $sql = "INSERT INTO historico_nomina (
                    contrato_id, periodo_desde, periodo_hasta, dias_liquidados, 
                    valor_salario_pagado, valor_recargos_nocturnos, valor_recargos_festivos, 
                    valor_aux_transporte, valor_aux_movilizacion, valor_aux_mov_nocturno, 
                    deduccion_salud, deduccion_pension, neto_pagar, json_snapshot_ley
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $contrato_id, $periodo_desde, $periodo_hasta, $dias_liquidados,
            $salario_pagado, $recargos_nocturnos, $recargos_festivos,
            $aux_transporte, $aux_movilizacion, $aux_mov_nocturno,
            $deduccion_salud, $deduccion_pension, $neto_pagar, $snapshot
        ]);

        // 4. Marcar asistencia como procesada
        $update = $pdo->prepare("
            UPDATE asistencia_diaria 
            SET procesado_en_nomina = 1 
            WHERE empleado_id = (SELECT empleado_id FROM contratos WHERE id = ?) 
            AND fecha BETWEEN ? AND ?
        ");
        $update->execute([$contrato_id, $periodo_desde, $periodo_hasta]);

        // 5. Redirección exitosa
        header("Location: nomina.php?status=success");
        exit();

    } catch (Exception $e) {
        // Si falla, te mostrará el error exacto en lugar de la página en blanco
        die("Error al guardar en la base de datos: " . $e->getMessage());
    }
}
