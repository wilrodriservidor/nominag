<?php
require_once 'config/db.php';

// Mensajes de estado
$mensaje = "";
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'deleted') {
        $mensaje = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 shadow-sm' role='alert'>
                        <p class='font-bold'>Eliminado</p>
                        <p>El registro de nómina ha sido borrado y las horas de asistencia han sido liberadas.</p>
                    </div>";
    }
}

// Consultar el historial con join para ver nombres de empleados
$sql = "SELECT h.*, e.nombre_completo, e.cedula 
        FROM historico_nomina h
        JOIN contratos c ON h.contrato_id = c.id
        JOIN empleados e ON c.empleado_id = e.id
        ORDER BY h.fecha_pago DESC";

$pagos = $pdo->query($sql)->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Pagos - Gemini</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none; }
            .print-only { display: block; }
            body { background: white; }
            .card { border: none; shadow: none; }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans min-h-screen">

    <nav class="bg-indigo-900 p-4 shadow-lg text-white no-print">
        <div class="container mx-auto flex justify-between items-center text-white">
            <h1 class="text-xl font-bold"><i class="fas fa-history mr-2 text-indigo-300"></i> Historial de Nómina</h1>
            <div class="space-x-4 flex items-center">
                <a href="asistencia.php" class="text-sm text-white no-underline hover:text-indigo-200">Asistencia</a>
                <a href="nomina.php" class="text-sm text-white no-underline hover:text-indigo-200">Generar Nómina</a>
                <a href="index.php" class="bg-indigo-700 px-4 py-2 rounded-lg text-sm text-white no-underline">Inicio</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto p-4 md:p-8">
        
        <?= $mensaje ?>

        <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-200">
            <div class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center no-print">
                <h2 class="text-lg font-bold text-gray-700">Registros de Liquidación</h2>
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total registros: <?= count($pagos) ?></span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-100 text-gray-600 uppercase text-xs font-bold tracking-wider">
                            <th class="px-6 py-4">Empleado / ID</th>
                            <th class="px-6 py-4">Periodo</th>
                            <th class="px-6 py-4">Neto Pagado</th>
                            <th class="px-6 py-4">Fecha Proceso</th>
                            <th class="px-6 py-4 text-center no-print">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 text-sm">
                        <?php foreach ($pagos as $p): ?>
                            <tr class="hover:bg-indigo-50 transition">
                                <td class="px-6 py-4">
                                    <div class="font-bold text-gray-900"><?= $p['nombre_completo'] ?></div>
                                    <div class="text-xs text-gray-500">C.C. <?= $p['cedula'] ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-gray-700 italic">
                                        <?= $p['periodo_desde'] ?> <i class="fas fa-arrow-right mx-1 text-xs text-gray-400"></i> <?= $p['periodo_hasta'] ?>
                                    </div>
                                    <div class="text-xs font-semibold text-indigo-500"><?= $p['dias_liquidados'] ?> días liq.</div>
                                </td>
                                <td class="px-6 py-4 font-mono font-black text-green-700 text-base">
                                    $<?= number_format($p['neto_pagar'], 0, ',', '.') ?>
                                </td>
                                <td class="px-6 py-4 text-gray-500 text-xs">
                                    <?= date('d/m/Y H:i', strtotime($p['fecha_pago'])) ?>
                                </td>
                                <td class="px-6 py-4 text-center space-x-2 no-print">
                                    <!-- Enlace corregido para asegurar que pase el ID correctamente -->
                                    <a href="generar_pdf.php?id=<?= $p['id'] ?>" 
                                       target="_blank" 
                                       class="text-indigo-600 hover:text-indigo-900 bg-indigo-50 p-2 rounded-lg inline-block transition hover:scale-110" 
                                       title="Ver Detalle PDF">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <a href="guardar_nomina.php?eliminar_id=<?= $p['id'] ?>" 
                                       onclick="return confirm('¿Estás seguro? Al borrar esta nómina, los días de asistencia se liberarán para poder liquidarlos de nuevo.')" 
                                       class="text-red-600 hover:text-red-900 bg-red-50 p-2 rounded-lg inline-block transition hover:scale-110" 
                                       title="Eliminar y Liberar Horas">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($pagos)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-gray-400 italic">
                                    <i class="fas fa-folder-open text-4xl mb-3 block opacity-20"></i>
                                    Aún no hay nóminas guardadas en el histórico.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-6 text-xs text-gray-400 text-center no-print">
            <i class="fas fa-info-circle mr-1"></i> Consejo: Si una liquidación quedó mal, elimínala aquí. El sistema pondrá las horas de nuevo en estado "pendiente" automáticamente.
        </p>
    </div>

</body>
</html>