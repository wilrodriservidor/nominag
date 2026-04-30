<?php
require_once 'config/db.php';

// 1. FUNCIÓN DE CÁLCULO (LA LÓGICA DE 2026 - Corte 7:00 PM)
function procesarTurno($entrada, $salida, $inicio_nocturno = 19) {
    $ini = new DateTime($entrada);
    $fin = new DateTime($salida);
    if ($fin < $ini) $fin->modify('+1 day');

    $diurnas = 0; $nocturnas = 0;
    $puntero = clone $ini;

    // Iteramos cada 15 minutos para mayor precisión
    while ($puntero < $fin) {
        $hora = (int)$puntero->format('H');
        // Regla Colombia 2026: 7:00 PM (19) a 6:00 AM es nocturno
        if ($hora >= $inicio_nocturno || $hora < 6) {
            $nocturnas += 0.25;
        } else {
            $diurnas += 0.25;
        }
        $puntero->modify('+15 minutes');
    }
    return ['diurnas' => $diurnas, 'nocturnas' => $nocturnas];
}

/**
 * Verifica si una fecha es domingo o está en la tabla de festivos
 */
function esDiaEspecial($fecha, $pdo) {
    $dia_semana = date('N', strtotime($fecha)); // 7 es Domingo
    if ($dia_semana == 7) return true;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM festivos WHERE fecha = ?");
    $stmt->execute([$fecha]);
    return ($stmt->fetchColumn() > 0);
}

// 2. PROCESAR ELIMINACIÓN
$mensaje = "";
if (isset($_GET['eliminar_id'])) {
    $id_a_borrar = $_GET['eliminar_id'];
    $sql_del = "DELETE FROM asistencia_diaria WHERE id = ?";
    $stmt_del = $pdo->prepare($sql_del);
    if ($stmt_del->execute([$id_a_borrar])) {
        $mensaje = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4'>Registro #$id_a_borrar eliminado.</div>";
    }
}

// 3. PROCESAR EL ENVÍO DEL FORMULARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entrada'])) {
    $emp_id = $_POST['empleado_id'];
    $f_entrada = $_POST['entrada'];
    $f_salida = $_POST['salida'];
    
    $calculo = procesarTurno($f_entrada, $f_salida);
    $fecha_base = date('Y-m-d', strtotime($f_entrada));
    $especial = esDiaEspecial($fecha_base, $pdo) ? 1 : 0;

    $sql = "INSERT INTO asistencia_diaria (empleado_id, fecha, hora_entrada, hora_salida, horas_diurnas, horas_nocturnas, es_festivo) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt_ins = $pdo->prepare($sql);
    $stmt_ins->execute([
        $emp_id, 
        $fecha_base, 
        $f_entrada, 
        $f_salida, 
        $calculo['diurnas'], 
        $calculo['nocturnas'],
        $especial
    ]);

    $tipo_dia = $especial ? " (Festivo/Domingo)" : "";
    $mensaje = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4'>Turno guardado{$tipo_dia}: {$calculo['diurnas']}h diurnas y {$calculo['nocturnas']}h nocturnas.</div>";
}

// 4. DATOS PARA LA VISTA
$empleados = $pdo->query("SELECT id, nombre_completo FROM empleados")->fetchAll();
$registros = $pdo->query("SELECT a.*, e.nombre_completo FROM asistencia_diaria a JOIN empleados e ON a.empleado_id = e.id ORDER BY a.id DESC LIMIT 15")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asistencia - Gemini Nómina</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">

    <nav class="bg-blue-800 p-4 shadow-lg">
        <div class="container mx-auto flex justify-between items-center text-white">
            <h1 class="text-xl font-bold"><i class="fas fa-clock mr-2"></i> Gemini Nómina 2026</h1>
            <a href="index.php" class="text-sm bg-blue-700 hover:bg-blue-600 py-2 px-4 rounded transition">Volver</a>
        </div>
    </nav>

    <div class="container mx-auto p-4 md:p-8">
        
        <?php echo $mensaje; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- FORMULARIO DE REGISTRO -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-lg font-semibold mb-4 text-gray-700 border-b pb-2">Registrar Turno</h2>
                    <form method="POST" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Seleccionar Empleado</label>
                            <select name="empleado_id" class="w-full mt-1 p-2 border rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach($empleados as $e): ?>
                                    <option value="<?= $e['id'] ?>"><?= $e['nombre_completo'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Fecha y Hora Entrada</label>
                            <input type="datetime-local" name="entrada" required class="w-full mt-1 p-2 border rounded-md">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-600">Fecha y Hora Salida</label>
                            <input type="datetime-local" name="salida" required class="w-full mt-1 p-2 border rounded-md">
                        </div>
                        
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded transition duration-300">
                            <i class="fas fa-save mr-2"></i> Procesar Turno
                        </button>
                    </form>
                </div>
            </div>

            <!-- TABLA DE RESULTADOS -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-md p-6 overflow-x-auto">
                    <h2 class="text-lg font-semibold mb-4 text-gray-700 border-b pb-2">Historial de Turnos (Auditables)</h2>
                    <table class="min-w-full leading-normal">
                        <thead>
                            <tr class="bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                <th class="px-4 py-3">Empleado</th>
                                <th class="px-4 py-3">Entrada / Salida</th>
                                <th class="px-4 py-3">H. Diurnas</th>
                                <th class="px-4 py-3">H. Nocturnas</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            <?php foreach($registros as $r): ?>
                                <tr class="hover:bg-gray-50 border-b border-gray-200">
                                    <td class="px-4 py-4">
                                        <p class="text-gray-900 font-medium"><?= $r['nombre_completo'] ?></p>
                                        <p class="text-xs text-gray-500"><?= $r['fecha'] ?></p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <p class="text-xs text-blue-700">IN: <?= date('d/m H:i', strtotime($r['hora_entrada'])) ?></p>
                                        <p class="text-xs text-red-700">OUT: <?= date('d/m H:i', strtotime($r['hora_salida'])) ?></p>
                                    </td>
                                    <td class="px-4 py-4 font-bold text-gray-700"><?= number_format($r['horas_diurnas'], 2) ?></td>
                                    <td class="px-4 py-4 font-bold text-indigo-600"><?= number_format($r['horas_nocturnas'], 2) ?></td>
                                    <td class="px-4 py-4">
                                        <?php if($r['es_festivo']): ?>
                                            <span class="px-2 py-1 bg-orange-100 text-orange-700 rounded-full text-xs font-bold">Festivo/Dom</span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs">Ordinario</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <a href="asistencia.php?eliminar_id=<?= $r['id'] ?>" 
                                           onclick="return confirm('¿Está seguro de eliminar este registro?')" 
                                           class="text-red-500 hover:text-red-700">
                                           <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(empty($registros)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-gray-500 italic">No hay registros hoy.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 mt-4"><i class="fas fa-info-circle mr-1"></i> Las horas nocturnas se calculan automáticamente después de las 7:00 PM según Ley 2026. Los domingos y festivos se detectan automáticamente.</p>
            </div>

        </div>
    </div>

</body>
</html>