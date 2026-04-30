<?php
require_once 'config/db.php';
require_once 'includes/funciones.php';

// 1. Insertar Empleado (Si no existe)
try {
    $cedula = "12345678"; // Reemplaza por la real de María
    $nombre = "MARIA DEL PILAR RAMIREZ GUZMAN";
    
    $sql_emp = "INSERT IGNORE INTO empleados (cedula, nombre_completo, fecha_ingreso) VALUES (?, ?, '2025-01-01')";
    $stmt = $pdo->prepare($sql_emp);
    $stmt->execute([$cedula, $nombre]);
    $empleado_id = $pdo->lastInsertId() ?: 1; // Si ya existía, usamos el ID 1

    // 2. Insertar Contrato con condiciones de Dirección y Confianza
    $salario = 1750905;
    $aux_mov = 250000;
    $aux_mov_noc = 350000;

    $sql_con = "INSERT INTO contratos (empleado_id, salario_base, es_direccion_confianza, aux_movilizacion_mensual, aux_mov_nocturno_mensual, fecha_inicio) 
                VALUES (?, ?, 1, ?, ?, '2025-01-01')";
    $stmt_con = $pdo->prepare($sql_con);
    $stmt_con->execute([$empleado_id, $salario, $aux_mov, $aux_mov_noc]);

    echo "Empleado y Contrato de Confianza registrados con éxito para: $nombre";
    echo "<br><a href='index.php'>Volver al inicio</a>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>