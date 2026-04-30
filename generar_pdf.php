<?php
/**
 * INSTRUCCIONES:
 * 1. Crea una carpeta llamada 'libs' en tu proyecto.
 * 2. Descarga FPDF (fpdf.php) y pégalo dentro de 'libs'.
 */

require('libs/fpdf.php');
require_once 'config/db.php';

// Variables iniciales de la liquidación
$nombre = $cedula = $desde = $hasta = "";
$dias = $salario = $rn = $festivos = $aux_mov = $aux_noc = $aux_trans = $salud = $pension = $neto = 0;

// MODO 1: CONSULTAR DESDE EL HISTORIAL (GET)
if (isset($_GET['id'])) {
    $id_pago = $_GET['id'];
    
    // Consulta ajustada para incluir la cédula
    $stmt = $pdo->prepare("
        SELECT h.*, e.nombre_completo, e.cedula 
        FROM historico_nomina h
        JOIN contratos c ON h.contrato_id = c.id
        JOIN empleados e ON c.empleado_id = e.id
        WHERE h.id = ?
    ");
    $stmt->execute([$id_pago]);
    $pago = $stmt->fetch();

    if ($pago) {
        $nombre     = $pago['nombre_completo'];
        $cedula     = $pago['cedula'];
        $desde      = $pago['periodo_desde'];
        $hasta      = $pago['periodo_hasta'];
        $dias       = $pago['dias_liquidados'];
        $salario    = $pago['valor_salario_pagado'];
        $rn         = $pago['valor_recargos_nocturnos'];
        $festivos   = $pago['valor_recargos_festivos'];
        $aux_trans  = $pago['valor_aux_transporte'];
        $aux_mov    = $pago['valor_aux_movilizacion'];
        $aux_noc    = $pago['valor_aux_mov_nocturno'];
        $salud      = $pago['deduccion_salud'];
        $pension    = $pago['deduccion_pension'];
        $neto       = $pago['neto_pagar'];
    } else {
        die("Error: El registro de pago con ID #$id_pago no existe.");
    }
} 
// MODO 2: GENERACIÓN TEMPORAL (POST desde previsualización)
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre     = $_POST['nombre'] ?? 'N/A';
    $cedula     = $_POST['cedula'] ?? 'N/A';
    $desde      = $_POST['desde'] ?? '';
    $hasta      = $_POST['hasta'] ?? '';
    $dias       = $_POST['dias'] ?? 0;
    $salario    = $_POST['salario'] ?? 0;
    $rn         = $_POST['rn'] ?? 0;
    $festivos   = $_POST['festivos'] ?? 0;
    $aux_mov    = $_POST['aux_mov'] ?? 0;
    $aux_noc    = $_POST['aux_noc'] ?? 0;
    $aux_trans  = $_POST['aux_trans'] ?? 0;
    $salud      = $_POST['salud'] ?? 0;
    $pension    = $_POST['pension'] ?? 0;
    $neto       = $_POST['neto'] ?? 0;
} 
else {
    header("Location: historial_pagos.php");
    exit();
}

/**
 * Clase extendida para el diseño del comprobante
 */
class PDF extends FPDF {
    function Header() {
        // Encabezado estético
        $this->SetFillColor(30, 41, 59); // Indigo Oscuro
        $this->Rect(0, 0, 210, 35, 'F');
        
        $this->SetY(10);
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 10, utf8_decode('ROYAL FILMS S.A.S.'), 0, 1, 'C');
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, utf8_decode('NIT: 800.123.456-7 | Comprobante de Pago'), 0, 1, 'C');
        $this->Ln(15);
    }

    function Footer() {
        $this->SetY(-25);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, utf8_decode('Este documento es un soporte de pago generado automáticamente por Gemini Nómina.'), 0, 1, 'C');
        $this->Cell(0, 5, utf8_decode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

// Configuración del PDF
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(15, 20, 15);

// --- SECCIÓN: INFORMACIÓN DEL EMPLEADO ---
$pdf->SetY(45);
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 242, 245);
$pdf->Cell(0, 8, utf8_decode('  DETALLES DE LA LIQUIDACIÓN'), 0, 1, 'L', true);
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(30, 7, utf8_decode('Empleado:'), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(90, 7, utf8_decode($nombre), 0, 0);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(35, 7, utf8_decode('Identificación:'), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(30, 7, $cedula, 0, 1);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(30, 7, utf8_decode('Periodo:'), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(90, 7, $desde . ' hasta ' . $hasta, 0, 0);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(35, 7, utf8_decode('Días Laborados:'), 0, 0);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(30, 7, $dias, 0, 1);

$pdf->Ln(5);

// --- SECCIÓN: TABLA DE VALORES ---
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(79, 70, 229); // Indigo
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(100, 9, 'DESCRIPCIÓN DEL CONCEPTO', 1, 0, 'C', true);
$pdf->Cell(40, 9, 'DEVENGADOS', 1, 0, 'C', true);
$pdf->Cell(40, 9, 'DEDUCCIONES', 1, 1, 'C', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 9);

// Estructura de conceptos para iterar
$items = [
    ['Sueldo Básico Laborado', $salario, 0],
    ['Recargos Nocturnos', $rn, 0],
    ['Recargos Festivos / Domingos', $festivos, 0],
    ['Auxilio de Movilización', $aux_mov, 0],
    ['Apoyo Movilidad Nocturna', $aux_noc, 0],
    ['Auxilio de Transporte', $aux_trans, 0],
    ['Deducción Salud (4%)', 0, $salud],
    ['Deducción Pensión (4%)', 0, $pension],
];

$total_dev = 0;
$total_ded = 0;

foreach ($items as $item) {
    if ($item[1] > 0 || $item[2] > 0) {
        $pdf->Cell(100, 7, utf8_decode($item[0]), 1, 0, 'L');
        $pdf->Cell(40, 7, ($item[1] > 0 ? '$ ' . number_format($item[1], 0, ',', '.') : ''), 1, 0, 'R');
        $pdf->Cell(40, 7, ($item[2] > 0 ? '$ ' . number_format($item[2], 0, ',', '.') : ''), 1, 1, 'R');
        $total_dev += $item[1];
        $total_ded += $item[2];
    }
}

// Filas vacías para completar el diseño
for($i=0; $i<4; $i++) {
    $pdf->Cell(100, 7, '', 1, 0);
    $pdf->Cell(40, 7, '', 1, 0);
    $pdf->Cell(40, 7, '', 1, 1);
}

// Totales de la tabla
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(100, 8, 'TOTALES', 1, 0, 'R');
$pdf->Cell(40, 8, '$ ' . number_format($total_dev, 0, ',', '.'), 1, 0, 'R');
$pdf->Cell(40, 8, '$ ' . number_format($total_ded, 0, ',', '.'), 1, 1, 'R');

// --- SECCIÓN: GRAN TOTAL ---
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(254, 240, 138); // Amarillo
$pdf->Cell(140, 12, 'NETO A RECIBIR', 1, 0, 'R', true);
$pdf->Cell(40, 12, '$ ' . number_format($neto, 0, ',', '.'), 1, 1, 'R', true);

$pdf->Ln(20);

// --- SECCIÓN: FIRMAS ---
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(80, 0, '', 'T', 0);
$pdf->Cell(20, 0, '', 0, 0);
$pdf->Cell(80, 0, '', 'T', 1);

$pdf->Cell(80, 5, utf8_decode('Firma Autorizada - Royal Films'), 0, 0, 'C');
$pdf->Cell(20, 5, '', 0, 0);
$pdf->Cell(80, 5, utf8_decode('Recibí Conforme (Firma del Empleado)'), 0, 1, 'C');

// Salida del PDF
$pdf->Output('I', 'Comprobante_' . str_replace(' ', '_', $nombre) . '.pdf');
?>