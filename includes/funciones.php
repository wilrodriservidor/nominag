
<?php
// incluye la conexión para algunas consultas si es necesario
// require_once __DIR__ . '/../config/db.php';

/**
 * Calcula el valor de la hora ordinaria basado en la jornada 2026
 */
function calcularValorHora($salario, $jornada_semanal = 44) {
    // En Colombia, el factor mensual para 44h es aprox 220h al mes
    $factor_mensual = ($jornada_semanal / 6) * 30; 
    return $salario / $factor_mensual;
}

/**
 * Proporciona un valor mensual a días trabajados (Para auxilios)
 */
function proporcionarValor($valor_mensual, $dias_trabajados) {
    return ($valor_mensual / 30) * $dias_trabajados;
}