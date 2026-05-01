<?php
/**
 * SISTEMA DE GESTIÓN DE NÓMINA - COLOMBIANETWORKS
 * Archivo: configuracion.php - VERSIÓN AUTO-REPARABLE
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_path = 'config/db.php';
if (!file_exists($db_path)) {
    die("Error crítico: El archivo config/db.php no existe.");
}
require_once $db_path;

$mensaje = "";

// --- FUNCIÓN DE REPARACIÓN MAESTRA (Ejecutada automáticamente o por botón) ---
function repararEstructura($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS configuracion_ley (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre_periodo VARCHAR(100) NOT NULL,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE NULL,
            valor_smlv DECIMAL(15,2) NOT NULL,
            subsidio_transporte DECIMAL(15,2) NOT NULL,
            recargo_nocturno DECIMAL(5,2) DEFAULT 35.00,
            recargo_festivo DECIMAL(5,2) DEFAULT 75.00,
            horas_semanales INT DEFAULT 47,
            activo TINYINT DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS empleados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cedula VARCHAR(20) UNIQUE NOT NULL,
            nombre_completo VARCHAR(150) NOT NULL,
            fecha_ingreso DATE NOT NULL,
            estado TINYINT DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS contratos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empleado_id INT NOT NULL,
            salario_base DECIMAL(15,2) NOT NULL,
            es_direccion_confianza TINYINT DEFAULT 0,
            aux_movilizacion_mensual DECIMAL(15,2) DEFAULT 0,
            aux_mov_nocturno_mensual DECIMAL(15,2) DEFAULT 0,
            fecha_inicio DATE NOT NULL,
            activo TINYINT DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Insertar periodo inicial si está vacía (Regla de oro: parametrización por ley)
        $check = $pdo->query("SELECT COUNT(*) FROM configuracion_ley")->fetchColumn();
        if ($check == 0) {
            $pdo->exec("INSERT INTO configuracion_ley (nombre_periodo, fecha_inicio, valor_smlv, subsidio_transporte, horas_semanales) 
                        VALUES ('Vigencia Inicial 2024 (Ley 2101)', '2024-01-01', 1300000, 162000, 47)");
        }
        return true;
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// Intentar reparar automáticamente si hay error de tabla no encontrada
if (isset($_POST['reparar_db'])) {
    $res = repararEstructura($pdo);
    if ($res === true) {
        $mensaje = "<div class='bg-emerald-500 text-white p-4 rounded-xl mb-6 shadow-lg'>Estructura de base de datos sincronizada.</div>";
    } else {
        $mensaje = "<div class='bg-red-500 text-white p-4 rounded-xl mb-6'>Error: $res</div>";
    }
}

// --- PROCESAMIENTO DE FORMULARIOS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    try {
        if ($_POST['accion'] == 'crear_empleado' || $_POST['accion'] == 'editar_empleado') {
            $pdo->beginTransaction();
            if ($_POST['accion'] == 'crear_empleado') {
                $stmt = $pdo->prepare("INSERT INTO empleados (cedula, nombre_completo, fecha_ingreso) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['cedula'], $_POST['nombre_completo'], $_POST['fecha_ingreso']]);
                $emp_id = $pdo->lastInsertId();

                $stmt_c = $pdo->prepare("INSERT INTO contratos (empleado_id, salario_base, es_direccion_confianza, aux_movilizacion_mensual, aux_mov_nocturno_mensual, fecha_inicio, activo) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt_c->execute([$emp_id, $_POST['salario_base'], isset($_POST['confianza']) ? 1 : 0, $_POST['aux_mov'] ?: 0, $_POST['aux_noc'] ?: 0, $_POST['fecha_ingreso']]);
            } else {
                $stmt = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
                $stmt->execute([$_POST['nombre_completo'], $_POST['cedula'], $_POST['id']]);
                
                $stmt_c = $pdo->prepare("UPDATE contratos SET salario_base = ?, es_direccion_confianza = ?, aux_movilizacion_mensual = ?, aux_mov_nocturno_mensual = ? WHERE empleado_id = ? AND activo = 1");
                $stmt_c->execute([$_POST['salario_base'], isset($_POST['confianza']) ? 1 : 0, $_POST['aux_mov'], $_POST['aux_noc'], $_POST['id']]);
            }
            $pdo->commit();
            $mensaje = "<div class='bg-indigo-600 text-white p-4 rounded-xl mb-6'>Operación exitosa con el empleado.</div>";
        }

        if ($_POST['accion'] == 'guardar_periodo') {
            $stmt = $pdo->prepare("INSERT INTO configuracion_ley (nombre_periodo, fecha_inicio, fecha_fin, valor_smlv, subsidio_transporte, recargo_nocturno, recargo_festivo, horas_semanales) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['nombre'], $_POST['inicio'], $_POST['fin'] ?: null, $_POST['smlv'], $_POST['subsidio'], $_POST['nocturno'], $_POST['festivo'], $_POST['horas']]);
            $mensaje = "<div class='bg-amber-500 text-white p-4 rounded-xl mb-6'>Nuevo periodo de ley registrado.</div>";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-500 text-white p-4 rounded-xl mb-6'>Error: " . $e->getMessage() . "</div>";
    }
}

// --- CARGA DE DATOS SEGURA ---
$empleados = [];
$periodos = [];

try {
    $empleados = $pdo->query("SELECT e.*, c.salario_base, c.es_direccion_confianza, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual FROM empleados e LEFT JOIN contratos c ON e.id = c.empleado_id AND c.activo = 1 ORDER BY e.nombre_completo ASC")->fetchAll();
    $periodos = $pdo->query("SELECT * FROM configuracion_ley ORDER BY fecha_inicio DESC")->fetchAll();
} catch (Exception $e) {
    // Si falla, intentamos reparar una vez y avisamos
    repararEstructura($pdo);
    $mensaje .= "<div class='bg-orange-500 text-white p-4 rounded-xl mb-6'>Se detectaron tablas faltantes. Se ha intentado una reparación automática. Por favor refresque la página.</div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración Maestra - Resiliente</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f3f4f6; }
    </style>
</head>
<body class="p-4 md:p-10">

    <div class="max-w-7xl mx-auto">
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-4">
            <div>
                <h1 class="text-4xl font-extrabold text-slate-900 tracking-tight">Configuración <span class="text-indigo-600">Legal</span></h1>
                <p class="text-slate-500 font-medium">Gestión de periodos de ley y planta de personal.</p>
            </div>
            <div class="flex gap-2">
                <form method="POST"><button type="submit" name="reparar_db" class="bg-white border border-slate-300 p-3 rounded-xl hover:bg-slate-50 transition-all"><i class="fas fa-tools"></i></button></form>
                <a href="index.php" class="bg-slate-900 text-white px-6 py-3 rounded-xl font-bold hover:bg-slate-800 transition-all">Dashboard</a>
            </div>
        </header>

        <?= $mensaje ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- PERIODOS DE LEY -->
            <div class="lg:col-span-4">
                <div class="bg-white rounded-3xl shadow-sm border border-slate-200 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold">Historial de Ley</h2>
                        <button onclick="document.getElementById('modalLey').style.display='flex'" class="text-indigo-600 font-bold text-sm hover:underline">+ Nuevo</button>
                    </div>
                    <div class="space-y-4">
                        <?php if(empty($periodos)): ?>
                            <p class="text-slate-400 italic text-sm text-center py-10">No hay periodos configurados.</p>
                        <?php endif; ?>
                        <?php foreach($periodos as $p): ?>
                        <div class="p-4 rounded-2xl border border-slate-100 bg-slate-50/50">
                            <div class="flex justify-between text-[10px] font-black text-indigo-500 uppercase mb-2">
                                <span><?= $p['fecha_inicio'] ?></span>
                                <span><?= $p['horas_semanales'] ?> Horas</span>
                            </div>
                            <h4 class="font-bold text-slate-800"><?= htmlspecialchars($p['nombre_periodo']) ?></h4>
                            <div class="flex gap-4 mt-2 text-xs font-semibold text-slate-500">
                                <span>SMLV: $<?= number_format($p['valor_smlv'],0) ?></span>
                                <span>Transp: $<?= number_format($p['subsidio_transporte'],0) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- LISTADO DE EMPLEADOS -->
            <div class="lg:col-span-8">
                <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/30">
                        <h2 class="text-xl font-bold">Empleados y Contratos</h2>
                        <button onclick="prepararCrear()" class="bg-indigo-600 text-white px-5 py-2 rounded-xl font-bold text-sm">Nuevo Ingreso</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50/50 text-[10px] uppercase font-black text-slate-400">
                                <tr>
                                    <th class="px-6 py-4">Empleado</th>
                                    <th class="px-6 py-4">Salario</th>
                                    <th class="px-6 py-4">Tipo</th>
                                    <th class="px-6 py-4"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach($empleados as $e): ?>
                                <tr class="hover:bg-slate-50/50 transition-all">
                                    <td class="px-6 py-4">
                                        <p class="font-bold text-slate-800"><?= htmlspecialchars($e['nombre_completo']) ?></p>
                                        <p class="text-xs text-slate-400 font-medium">C.C. <?= $e['cedula'] ?></p>
                                    </td>
                                    <td class="px-6 py-4 font-bold text-slate-700">$<?= number_format($e['salario_base'],0) ?></td>
                                    <td class="px-6 py-4">
                                        <span class="text-[10px] font-bold uppercase px-3 py-1 rounded-full <?= $e['es_direccion_confianza'] ? 'bg-amber-100 text-amber-600' : 'bg-blue-100 text-blue-600' ?>">
                                            <?= $e['es_direccion_confianza'] ? 'Confianza' : 'Operativo' ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <button onclick='abrirModalEditar(<?= json_encode($e) ?>)' class="text-slate-300 hover:text-indigo-600 transition-all"><i class="fas fa-edit"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL EMPLEADO -->
    <div id="modalEmp" style="display:none" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] w-full max-w-xl p-8 shadow-2xl">
            <h2 id="modalEmpTitulo" class="text-2xl font-black mb-6">Nuevo Empleado</h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="accion" id="formAccion" value="crear_empleado">
                <input type="hidden" name="id" id="formId">
                <div class="grid grid-cols-2 gap-4">
                    <input type="text" name="nombre_completo" id="formNombre" placeholder="Nombre Completo" required class="col-span-2 bg-slate-50 p-4 rounded-2xl border border-slate-200 outline-none focus:ring-2 ring-indigo-500">
                    <input type="text" name="cedula" id="formCedula" placeholder="Cédula" required class="bg-slate-50 p-4 rounded-2xl border border-slate-200 outline-none">
                    <input type="date" name="fecha_ingreso" id="formFecha" required class="bg-slate-50 p-4 rounded-2xl border border-slate-200 outline-none">
                    <input type="number" name="salario_base" id="formSalario" placeholder="Salario Base" required class="col-span-2 bg-indigo-50 p-4 rounded-2xl border border-indigo-100 font-bold text-indigo-700 outline-none">
                </div>
                <div class="bg-slate-50 p-4 rounded-2xl space-y-3">
                    <label class="text-[10px] font-black uppercase text-slate-400">Extras Mensuales Fijos</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="aux_mov" id="formMov" placeholder="Aux. Movilidad" class="p-3 rounded-xl border border-slate-200">
                        <input type="number" name="aux_noc" id="formNoc" placeholder="Aux. Nocturno" class="p-3 rounded-xl border border-slate-200">
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer mt-2">
                        <input type="checkbox" name="confianza" id="formConfianza" class="w-5 h-5 accent-indigo-600">
                        <span class="text-sm font-semibold text-slate-600 italic">Personal de Confianza (Sin HE)</span>
                    </label>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('modalEmp').style.display='none'" class="flex-1 py-4 font-bold text-slate-500">Cancelar</button>
                    <button type="submit" class="flex-[2] bg-indigo-600 text-white py-4 rounded-2xl font-bold shadow-lg shadow-indigo-200">Guardar Información</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL LEY -->
    <div id="modalLey" style="display:none" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-[2.5rem] w-full max-w-lg p-8 shadow-2xl">
            <h2 class="text-2xl font-black mb-6">Nuevo Parámetro de Ley</h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="accion" value="guardar_periodo">
                <input type="text" name="nombre" placeholder="Descripción (Eje: Cambio Julio 2024)" required class="w-full bg-slate-50 p-4 rounded-2xl border border-slate-200 outline-none">
                <div class="grid grid-cols-2 gap-4">
                    <input type="date" name="inicio" required class="bg-slate-50 p-4 rounded-2xl border border-slate-200 outline-none">
                    <input type="date" name="fin" class="bg-slate-50 p-4 rounded-2xl border border-slate-200 outline-none">
                    <input type="number" name="smlv" placeholder="SMLV" required class="bg-indigo-50 p-4 rounded-2xl border border-indigo-100 font-bold text-indigo-700 outline-none">
                    <input type="number" name="subsidio" placeholder="Aux. Transporte" required class="bg-indigo-50 p-4 rounded-2xl border border-indigo-100 font-bold text-indigo-700 outline-none">
                    <input type="number" name="horas" value="47" placeholder="Horas Sem." required class="bg-slate-50 p-4 rounded-2xl border border-slate-200 outline-none">
                    <input type="number" step="0.01" name="nocturno" value="35.00" class="bg-slate-50 p-4 rounded-2xl border border-slate-200 outline-none">
                </div>
                <div class="flex gap-3 mt-4">
                    <button type="button" onclick="document.getElementById('modalLey').style.display='none'" class="flex-1 py-4 font-bold text-slate-500">Cerrar</button>
                    <button type="submit" class="flex-[2] bg-slate-900 text-white py-4 rounded-2xl font-bold">Aplicar Parámetros</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalEditar(e) {
            document.getElementById('modalEmpTitulo').innerText = "Editar Colaborador";
            document.getElementById('formAccion').value = "editar_empleado";
            document.getElementById('formId').value = e.id;
            document.getElementById('formNombre').value = e.nombre_completo;
            document.getElementById('formCedula').value = e.cedula;
            document.getElementById('formFecha').value = e.fecha_ingreso;
            document.getElementById('formSalario').value = e.salario_base;
            document.getElementById('formMov').value = e.aux_movilizacion_mensual;
            document.getElementById('formNoc').value = e.aux_mov_nocturno_mensual;
            document.getElementById('formConfianza').checked = (parseInt(e.es_direccion_confianza) === 1);
            document.getElementById('modalEmp').style.display = 'flex';
        }

        function prepararCrear() {
            document.getElementById('modalEmpTitulo').innerText = "Nuevo Empleado";
            document.getElementById('formAccion').value = "crear_empleado";
            document.getElementById('formId').value = "";
            document.getElementById('formNombre').value = "";
            document.getElementById('formCedula').value = "";
            document.getElementById('formFecha').value = "";
            document.getElementById('formSalario').value = "";
            document.getElementById('formMov').value = "0";
            document.getElementById('formNoc').value = "0";
            document.getElementById('formConfianza').checked = false;
            document.getElementById('modalEmp').style.display = 'flex';
        }
    </script>
</body>
</html>
