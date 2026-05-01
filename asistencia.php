<?php
// Configurar la zona horaria para Colombia
date_default_timezone_set('America/Bogota');

require_once 'config/db.php';

// 1. FUNCIÓN DE CÁLCULO (LA LÓGICA DE 2026 - Corte 7:00 PM)
function procesarTurno($entrada, $salida, $descanso = 1, $inicio_nocturno = 19) {
    $ini = new DateTime($entrada);
    $fin = new DateTime($salida);
    
    // Si por error la salida es menor a la entrada, no calculamos
    if ($fin < $ini) return ['diurnas' => 0, 'nocturnas' => 0];

    $diurnas_brutas = 0; 
    $nocturnas_brutas = 0;
    $puntero = clone $ini;

    while ($puntero < $fin) {
        $hora = (int)$puntero->format('H');
        // Regla Colombia 2026: 7:00 PM (19) a 6:00 AM es nocturno
        if ($hora >= $inicio_nocturno || $hora < 6) {
            $nocturnas_brutas += 0.25;
        } else {
            $diurnas_brutas += 0.25;
        }
        $puntero->modify('+15 minutes');
    }

    // Restamos el descanso (prioridad en horas diurnas)
    $d_final = $diurnas_brutas;
    $n_final = $nocturnas_brutas;

    if ($descanso > 0) {
        if ($d_final >= $descanso) {
            $d_final -= $descanso;
        } else {
            $sobrante = $descanso - $d_final;
            $d_final = 0;
            $n_final = max(0, $n_final - $sobrante);
        }
    }

    return ['diurnas' => $d_final, 'nocturnas' => $n_final];
}

/**
 * Verifica si una fecha es domingo o está en la tabla de festivos
 */
function esDiaEspecial($fecha, $pdo) {
    $dia_semana = date('N', strtotime($fecha));
    if ($dia_semana == 7) return true; // Domingo

    $stmt = $pdo->prepare("SELECT * FROM festivos WHERE fecha = ?");
    $stmt->execute([$fecha]);
    return $stmt->fetch() ? true : false;
}

// 2. PROCESAR ELIMINACIÓN
if (isset($_GET['eliminar_id'])) {
    $stmt = $pdo->prepare("DELETE FROM asistencia_diaria WHERE id = ?");
    $stmt->execute([$_GET['eliminar_id']]);
    header("Location: asistencia.php?msg=Registro eliminado");
    exit;
}

// 3. PROCESAR EL FORMULARIO
$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['registrar'])) {
    $empleado_id = $_POST['empleado_id'];
    $fecha_registro = $_POST['fecha_entrada']; // Se usa la fecha de entrada como referencia del registro
    $hora_entrada_full = $_POST['fecha_entrada'] . ' ' . $_POST['hora_entrada'];
    $hora_salida_full = $_POST['fecha_salida'] . ' ' . $_POST['hora_salida'];
    $horas_descanso = floatval($_POST['horas_descanso'] ?? 1);

    $calculos = procesarTurno($hora_entrada_full, $hora_salida_full, $horas_descanso);

    $sql = "INSERT INTO asistencia_diaria (empleado_id, fecha, hora_entrada, hora_salida, horas_diurnas, horas_nocturnas) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $empleado_id, 
        $fecha_registro, 
        $hora_entrada_full, 
        $hora_salida_full, 
        $calculos['diurnas'], 
        $calculos['nocturnas']
    ]);

    $mensaje = "Asistencia registrada correctamente.";
}

// 4. DATOS PARA LA VISTA
$empleados = $pdo->query("SELECT id, nombre_completo FROM empleados ORDER BY nombre_completo ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filtros
$mes_filtro = isset($_GET['mes']) ? $_GET['mes'] : date('m');
$anio_filtro = isset($_GET['anio']) ? $_GET['anio'] : date('Y');

$sql_registros = "SELECT a.*, e.nombre_completo 
                 FROM asistencia_diaria a 
                 JOIN empleados e ON a.empleado_id = e.id 
                 WHERE MONTH(a.fecha) = ? AND YEAR(a.fecha) = ?
                 ORDER BY a.fecha DESC, a.id DESC";
$stmt_reg = $pdo->prepare($sql_registros);
$stmt_reg->execute([$mes_filtro, $anio_filtro]);
$registros = $stmt_reg->fetchAll(PDO::FETCH_ASSOC);

$nombres_meses = [
    '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril', 
    '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto', 
    '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Asistencia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100 p-4 md:p-6">

    <div class="max-w-7xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-xl md:text-2xl font-bold text-gray-800">Control de Asistencia</h1>
            <a href="index.php" class="bg-gray-600 text-white px-3 py-2 rounded-lg hover:bg-gray-700 text-sm">
                <i class="fas fa-home mr-1"></i>Inicio
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            
            <!-- Formulario de Registro -->
            <div class="lg:col-span-1 bg-white p-5 rounded-xl shadow-md h-fit">
                <h2 class="text-md font-bold mb-4 border-b pb-2 text-gray-700 uppercase tracking-wider">Nuevo Registro</h2>
                
                <?php if ($mensaje): ?>
                    <div class="bg-green-100 text-green-700 p-3 rounded-lg mb-4 text-xs font-bold">
                        <?= $mensaje ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold mb-1 text-gray-500 uppercase">Empleado:</label>
                        <select name="empleado_id" required class="w-full border rounded-lg p-2 text-sm bg-gray-50">
                            <option value="">Seleccione...</option>
                            <?php foreach ($empleados as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre_completo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="p-3 bg-blue-50 rounded-lg space-y-3 border border-blue-100">
                        <p class="text-[10px] font-black text-blue-400 uppercase tracking-tighter">Entrada de Turno</p>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="date" name="fecha_entrada" value="<?= date('Y-m-d') ?>" required class="border rounded p-2 text-xs">
                            <input type="time" name="hora_entrada" required class="border rounded p-2 text-xs">
                        </div>
                    </div>

                    <div class="p-3 bg-indigo-50 rounded-lg space-y-3 border border-indigo-100">
                        <p class="text-[10px] font-black text-indigo-400 uppercase tracking-tighter">Salida de Turno</p>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="date" name="fecha_salida" value="<?= date('Y-m-d') ?>" required class="border rounded p-2 text-xs">
                            <input type="time" name="hora_salida" required class="border rounded p-2 text-xs">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1 text-gray-500 uppercase">Horas Descanso:</label>
                        <input type="number" step="0.5" name="horas_descanso" value="1" required class="w-full border rounded-lg p-2 text-sm font-bold text-center bg-gray-50">
                    </div>

                    <button type="submit" name="registrar" class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg hover:bg-blue-700 transition uppercase text-xs tracking-widest">
                        Registrar Turno
                    </button>
                </form>
            </div>

            <!-- Listado de Asistencia -->
            <div class="lg:col-span-3">
                <div class="bg-white p-4 rounded-xl shadow-md mb-4 flex flex-col md:flex-row justify-between items-center gap-4">
                    <h3 class="font-bold text-gray-700 uppercase text-xs tracking-widest">Historial Mensual</h3>
                    <form method="GET" class="flex gap-2 items-center w-full md:w-auto">
                        <select name="mes" class="flex-1 md:flex-none border rounded px-2 py-1 text-xs">
                            <?php foreach($nombres_meses as $num => $nombre): ?>
                                <option value="<?= $num ?>" <?= $num == $mes_filtro ? 'selected' : '' ?>><?= $nombre ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="anio" class="flex-1 md:flex-none border rounded px-2 py-1 text-xs">
                            <?php for($a = 2025; $a <= 2027; $a++): ?>
                                <option value="<?= $a ?>" <?= $a == $anio_filtro ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-1 rounded text-xs font-bold uppercase">Filtrar</button>
                    </form>
                </div>

                <!-- CONTENEDOR CON DESPLAZAMIENTO PARA MÓVIL -->
                <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[600px] text-left">
                            <thead class="bg-gray-800 text-white text-[10px] uppercase tracking-wider">
                                <tr>
                                    <th class="px-4 py-4">Empleado</th>
                                    <th class="px-4 py-4 text-center">Entrada / Salida</th>
                                    <th class="px-4 py-4 text-center">Diurnas</th>
                                    <th class="px-4 py-4 text-center">Nocturnas</th>
                                    <th class="px-4 py-4 text-center">Estado</th>
                                    <th class="px-4 py-4 text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm divide-y divide-gray-100">
                                <?php foreach ($registros as $r): 
                                    $es_festivo = esDiaEspecial($r['fecha'], $pdo);
                                    $f_e = date('d/m H:i', strtotime($r['hora_entrada']));
                                    $f_s = date('d/m H:i', strtotime($r['hora_salida']));
                                ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-4 py-4">
                                            <div class="font-bold text-gray-800 uppercase text-xs"><?= htmlspecialchars($r['nombre_completo']) ?></div>
                                        </td>
                                        <td class="px-4 py-4 text-center">
                                            <div class="text-[10px] font-medium text-gray-500">
                                                <span class="text-blue-600"><?= $f_e ?></span> 
                                                <i class="fas fa-long-arrow-alt-right mx-1"></i> 
                                                <span class="text-indigo-600"><?= $f_s ?></span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-center font-black text-blue-600"><?= number_format($r['horas_diurnas'], 1) ?></td>
                                        <td class="px-4 py-4 text-center font-black text-indigo-600"><?= number_format($r['horas_nocturnas'], 1) ?></td>
                                        <td class="px-4 py-4 text-center">
                                            <?php if($es_festivo): ?>
                                                <span class="px-2 py-0.5 bg-red-100 text-red-600 rounded text-[9px] font-black uppercase">Festivo/Dom</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 bg-gray-100 text-gray-400 rounded text-[9px] font-bold uppercase">Ordinario</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4 text-center">
                                            <a href="asistencia.php?eliminar_id=<?= $r['id'] ?>" 
                                               onclick="return confirm('¿Eliminar este registro?')" 
                                               class="text-red-400 hover:text-red-600 p-2">
                                               <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if(empty($registros)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-12 text-gray-400 italic text-xs">Sin registros en el periodo seleccionado.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-4 flex items-start gap-2 bg-yellow-50 p-3 rounded-lg border border-yellow-100">
                    <i class="fas fa-mobile-alt text-yellow-600 mt-1"></i>
                    <p class="text-[10px] text-yellow-700 leading-tight">
                        <b>Modo Móvil:</b> Si no visualiza toda la información, deslice la tabla hacia la izquierda. 
                        El sistema ahora requiere <b>Fecha de Entrada</b> y <b>Fecha de Salida</b> para calcular correctamente turnos que terminan al día siguiente.
                    </p>
                </div>
            </div>

        </div>
    </div>

</body>
</html>
