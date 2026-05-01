<?php
/**
 * SISTEMA DE GESTIÓN DE NÓMINA - COLOMBIANETWORKS
 * Archivo: configuracion.php
 * Versión: 4.0.0 (Soporte Multi-periodo y Ley Laboral Dinámica)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Conexión a Base de Datos
$db_path = 'config/db.php';
if (!file_exists($db_path)) {
    die("Error crítico: El archivo de configuración de base de datos no existe en $db_path. Por favor, verifique la instalación.");
}
require_once $db_path;

$mensaje = "";

// -------------------------------------------------------------------------
// 2. MOTOR DE COMPATIBILIDAD Y REPARACIÓN (REGLA DE ORO)
// -------------------------------------------------------------------------
if (isset($_POST['reparar_db'])) {
    try {
        $pdo->beginTransaction();
        
        // Tabla de Periodos Legales (Evolución de parametros_ley)
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

        // Tabla de Empleados (Asegurar columnas)
        $pdo->exec("CREATE TABLE IF NOT EXISTS empleados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cedula VARCHAR(20) UNIQUE NOT NULL,
            nombre_completo VARCHAR(150) NOT NULL,
            fecha_ingreso DATE NOT NULL,
            estado TINYINT DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Tabla de Contratos (Relación 1:N para historial de salarios)
        $pdo->exec("CREATE TABLE IF NOT EXISTS contratos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empleado_id INT NOT NULL,
            salario_base DECIMAL(15,2) NOT NULL,
            es_direccion_confianza TINYINT DEFAULT 0,
            aux_movilizacion_mensual DECIMAL(15,2) DEFAULT 0,
            aux_mov_nocturno_mensual DECIMAL(15,2) DEFAULT 0,
            fecha_inicio DATE NOT NULL,
            activo TINYINT DEFAULT 1,
            FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Verificar si existe el periodo actual, si no, crearlo
        $check = $pdo->query("SELECT COUNT(*) FROM configuracion_ley")->fetchColumn();
        if ($check == 0) {
            $pdo->exec("INSERT INTO configuracion_ley (nombre_periodo, fecha_inicio, valor_smlv, subsidio_transporte, horas_semanales) 
                        VALUES ('Vigencia Inicial 2024', '2024-01-01', 1300000, 162000, 47)");
        }

        $pdo->commit();
        $mensaje = "<div class='bg-emerald-100 text-emerald-800 p-5 rounded-2xl mb-6 border border-emerald-200 flex items-center gap-3 animate-bounce'>
                        <i class='fas fa-check-double text-xl'></i>
                        <div>
                            <p class='font-bold'>Sincronización Exitosa</p>
                            <p class='text-xs opacity-80'>Tablas de periodos, empleados y contratos actualizadas correctamente.</p>
                        </div>
                    </div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-800 p-5 rounded-2xl mb-6 border border-red-200'>
                        <p class='font-bold'>Error de Reparación</p>
                        <p class='text-sm'>".$e->getMessage()."</p>
                    </div>";
    }
}

// -------------------------------------------------------------------------
// 3. LÓGICA DE NEGOCIO: EMPLEADOS
// -------------------------------------------------------------------------

// Crear Empleado + Contrato Inicial
if (isset($_POST['accion']) && $_POST['accion'] == 'crear_empleado') {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO empleados (cedula, nombre_completo, fecha_ingreso) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['cedula'], $_POST['nombre_completo'], $_POST['fecha_ingreso']]);
        $emp_id = $pdo->lastInsertId();

        $stmt_c = $pdo->prepare("INSERT INTO contratos (empleado_id, salario_base, es_direccion_confianza, aux_movilizacion_mensual, aux_mov_nocturno_mensual, fecha_inicio, activo) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt_c->execute([
            $emp_id,
            $_POST['salario_base'],
            isset($_POST['confianza']) ? 1 : 0,
            $_POST['aux_mov'] ?: 0,
            $_POST['aux_noc'] ?: 0,
            $_POST['fecha_ingreso']
        ]);

        $pdo->commit();
        $mensaje = "<div class='bg-indigo-100 text-indigo-800 p-4 rounded-2xl mb-6 border border-indigo-200'>Empleado registrado correctamente.</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-800 p-4 rounded-2xl mb-6 border border-red-200'>Error al crear empleado: ".$e->getMessage()."</div>";
    }
}

// Editar Empleado
if (isset($_POST['accion']) && $_POST['accion'] == 'editar_empleado') {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE empleados SET nombre_completo = ?, cedula = ? WHERE id = ?");
        $stmt->execute([$_POST['nombre_completo'], $_POST['cedula'], $_POST['id']]);

        $stmt_c = $pdo->prepare("UPDATE contratos SET salario_base = ?, es_direccion_confianza = ?, aux_movilizacion_mensual = ?, aux_mov_nocturno_mensual = ? WHERE empleado_id = ? AND activo = 1");
        $stmt_c->execute([
            $_POST['salario_base'],
            isset($_POST['confianza']) ? 1 : 0,
            $_POST['aux_mov'],
            $_POST['aux_noc'],
            $_POST['id']
        ]);
        $pdo->commit();
        $mensaje = "<div class='bg-blue-100 text-blue-800 p-4 rounded-2xl mb-6 border border-blue-200'>Cambios guardados para ".$_POST['nombre_completo']."</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-800 p-4 rounded-2xl mb-6'>Error al editar: ".$e->getMessage()."</div>";
    }
}

// -------------------------------------------------------------------------
// 4. LÓGICA DE NEGOCIO: PERIODOS DE LEY
// -------------------------------------------------------------------------
if (isset($_POST['accion']) && $_POST['accion'] == 'guardar_periodo') {
    try {
        $stmt = $pdo->prepare("INSERT INTO configuracion_ley (nombre_periodo, fecha_inicio, fecha_fin, valor_smlv, subsidio_transporte, recargo_nocturno, recargo_festivo, horas_semanales) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['nombre'],
            $_POST['inicio'],
            $_POST['fin'] ?: null,
            $_POST['smlv'],
            $_POST['subsidio'],
            $_POST['nocturno'],
            $_POST['festivo'],
            $_POST['horas']
        ]);
        $mensaje = "<div class='bg-amber-100 text-amber-800 p-4 rounded-2xl mb-6 border border-amber-200'>Nuevo marco legal configurado exitosamente.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='bg-red-100 text-red-800 p-4 rounded-2xl mb-6'>Error en ley: ".$e->getMessage()."</div>";
    }
}

// -------------------------------------------------------------------------
// 5. CARGA DE DATOS PARA VISTA
// -------------------------------------------------------------------------
$empleados = $pdo->query("
    SELECT e.*, c.salario_base, c.es_direccion_confianza, c.aux_movilizacion_mensual, c.aux_mov_nocturno_mensual 
    FROM empleados e 
    LEFT JOIN contratos c ON e.id = c.empleado_id AND c.activo = 1 
    ORDER BY e.nombre_completo ASC
")->fetchAll();

$periodos = $pdo->query("SELECT * FROM configuracion_ley ORDER BY fecha_inicio DESC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración Maestra - Nómina Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@300;500;800&display=swap');
        body { font-family: 'Bricolage Grotesque', sans-serif; }
        .glass { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(12px); }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900 min-h-screen">

    <div class="max-w-7xl mx-auto px-6 py-12">
        
        <!-- HEADER DINÁMICO -->
        <header class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 mb-12">
            <div>
                <h1 class="text-5xl font-extrabold tracking-tight text-slate-950">Configuración <span class="text-indigo-600">Avanzada</span></h1>
                <p class="text-slate-500 mt-2 text-lg font-medium italic">Parámetros de ley y gestión de capital humano.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <form action="" method="POST" onsubmit="return confirm('¿Ejecutar sincronización de esquemas SQL?')">
                    <button type="submit" name="reparar_db" class="bg-white border-2 border-slate-200 px-6 py-3 rounded-2xl font-bold text-slate-600 hover:border-indigo-300 hover:text-indigo-600 transition-all flex items-center gap-2 shadow-sm">
                        <i class="fas fa-database text-sm"></i> Reparar Esquema
                    </button>
                </form>
                <button onclick="document.getElementById('modalPeriodo').style.display='flex'" class="bg-slate-950 text-white px-6 py-3 rounded-2xl font-bold hover:bg-indigo-700 transition-all shadow-xl flex items-center gap-2">
                    <i class="fas fa-calendar-plus text-sm"></i> Nuevo Periodo Ley
                </button>
                <a href="index.php" class="bg-indigo-600 text-white px-8 py-3 rounded-2xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100 flex items-center gap-2">
                    <i class="fas fa-home text-sm"></i> Dashboard
                </a>
            </div>
        </header>

        <?= $mensaje ?>

        <main class="grid grid-cols-1 lg:grid-cols-12 gap-10">
            
            <!-- PANEL DE LEY (HISTORIAL) -->
            <section class="lg:col-span-4 space-y-8">
                <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-8 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                        <h3 class="font-extrabold text-xl flex items-center gap-3">
                            <i class="fas fa-scroll text-indigo-600"></i> Marco Legal
                        </h3>
                    </div>
                    <div class="p-6 space-y-4 max-h-[600px] overflow-y-auto custom-scrollbar">
                        <?php foreach($periodos as $p): ?>
                        <div class="p-6 rounded-3xl border-2 transition-all <?= $p['activo'] ? 'border-indigo-100 bg-indigo-50/20 shadow-sm' : 'border-slate-100 opacity-60' ?>">
                            <div class="flex justify-between items-start">
                                <span class="text-[10px] font-black uppercase tracking-widest text-indigo-400"><?= $p['fecha_inicio'] ?></span>
                                <span class="bg-white px-3 py-1 rounded-full text-[10px] font-extrabold text-slate-500 shadow-sm"><?= $p['horas_semanales'] ?>H</span>
                            </div>
                            <h4 class="font-extrabold text-slate-900 mt-2"><?= htmlspecialchars($p['nombre_periodo']) ?></h4>
                            
                            <div class="grid grid-cols-2 gap-4 mt-4">
                                <div>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase">SMLV</p>
                                    <p class="font-black text-slate-700">$<?= number_format($p['valor_smlv'], 0) ?></p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase">Transporte</p>
                                    <p class="font-black text-slate-700">$<?= number_format($p['subsidio_transporte'], 0) ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- PANEL DE EMPLEADOS -->
            <section class="lg:col-span-8">
                <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden">
                    <div class="p-8 border-b border-slate-100 flex flex-col md:flex-row justify-between items-center gap-4">
                        <h3 class="font-extrabold text-xl flex items-center gap-3">
                            <i class="fas fa-id-card-alt text-indigo-600"></i> Gestión de Personal
                        </h3>
                        <button onclick="document.getElementById('modalEmpleado').style.display='flex'" class="w-full md:w-auto bg-emerald-500 text-white px-6 py-2.5 rounded-2xl font-bold hover:bg-emerald-600 transition-all shadow-lg shadow-emerald-100 flex items-center justify-center gap-2">
                            <i class="fas fa-user-plus text-sm"></i> Nuevo Ingreso
                        </button>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50/50">
                                <tr class="text-[11px] uppercase font-black text-slate-400 tracking-widest">
                                    <th class="px-8 py-5">Colaborador</th>
                                    <th class="px-8 py-5">Salario Base</th>
                                    <th class="px-8 py-5">Contrato</th>
                                    <th class="px-8 py-5 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach($empleados as $emp): ?>
                                <tr class="hover:bg-slate-50/30 transition-all group">
                                    <td class="px-8 py-6">
                                        <div class="flex items-center gap-4">
                                            <div class="w-12 h-12 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center font-black text-lg">
                                                <?= substr($emp['nombre_completo'], 0, 1) ?>
                                            </div>
                                            <div>
                                                <p class="font-extrabold text-slate-900 leading-none"><?= htmlspecialchars($emp['nombre_completo']) ?></p>
                                                <p class="text-xs text-slate-400 mt-2 font-bold tracking-tight"><?= $emp['cedula'] ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-8 py-6">
                                        <p class="font-black text-slate-700">$<?= number_format($emp['salario_base'], 0) ?></p>
                                        <p class="text-[9px] text-indigo-400 font-bold uppercase mt-1">Activo</p>
                                    </td>
                                    <td class="px-8 py-6">
                                        <span class="text-[10px] font-black uppercase px-4 py-1.5 rounded-xl <?= $emp['es_direccion_confianza'] ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500' ?>">
                                            <?= $emp['es_direccion_confianza'] ? 'Dirección/Confianza' : 'Operativo' ?>
                                        </span>
                                    </td>
                                    <td class="px-8 py-6 text-right">
                                        <button onclick='abrirModalEditar(<?= json_encode($emp) ?>)' class="w-10 h-10 rounded-xl bg-slate-50 text-slate-400 hover:bg-indigo-600 hover:text-white transition-all inline-flex items-center justify-center shadow-sm">
                                            <i class="fas fa-user-edit text-sm"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- MODALES (Lógica Avanzada de 400+ líneas) -->
    
    <!-- MODAL EMPLEADO (Crear/Editar) -->
    <div id="modalEmpleado" style="display:none" class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-50 items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-[3rem] shadow-2xl max-w-2xl w-full my-auto overflow-hidden animate-in fade-in zoom-in duration-300">
            <div class="p-10">
                <div class="flex justify-between items-center mb-10">
                    <h2 class="text-3xl font-black text-slate-900" id="tituloModalEmp">Nuevo Colaborador</h2>
                    <button onclick="document.getElementById('modalEmpleado').style.display='none'" class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 hover:bg-red-50 hover:text-red-500 transition-all flex items-center justify-center">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form action="" method="POST" class="space-y-6">
                    <input type="hidden" name="accion" id="emp_accion" value="crear_empleado">
                    <input type="hidden" name="id" id="emp_id">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Nombre Completo</label>
                            <input type="text" name="nombre_completo" id="emp_nombre" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold outline-none focus:border-indigo-400 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Identificación (C.C)</label>
                            <input type="text" name="cedula" id="emp_cedula" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold outline-none focus:border-indigo-400 transition-all">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Salario Mensual</label>
                            <input type="number" name="salario_base" id="emp_salario" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-black text-indigo-600 text-lg outline-none focus:border-indigo-400 transition-all">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest ml-1">Fecha de Inicio</label>
                            <input type="date" name="fecha_ingreso" id="emp_fecha" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold outline-none focus:border-indigo-400 transition-all">
                        </div>
                    </div>

                    <div class="bg-slate-50 p-8 rounded-[2rem] border-2 border-slate-100 space-y-6">
                        <h4 class="text-xs font-black text-slate-400 uppercase tracking-widest border-b border-slate-200 pb-3">Compensaciones Extra & Contrato</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-extrabold text-slate-400 uppercase">Aux. Movilidad Mensual</label>
                                <input type="number" name="aux_mov" id="emp_mov" value="0" class="w-full bg-white border-2 border-slate-200 rounded-xl p-3 font-bold outline-none focus:border-indigo-400 transition-all shadow-sm">
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-extrabold text-slate-400 uppercase">Aux. Nocturno Mensual</label>
                                <input type="number" name="aux_noc" id="emp_noc" value="0" class="w-full bg-white border-2 border-slate-200 rounded-xl p-3 font-bold outline-none focus:border-indigo-400 transition-all shadow-sm">
                            </div>
                        </div>
                        <div class="flex items-center gap-4 p-4 bg-white rounded-2xl shadow-sm border border-slate-100">
                            <input type="checkbox" name="confianza" id="emp_confianza" class="w-6 h-6 accent-indigo-600 rounded-lg cursor-pointer">
                            <label for="emp_confianza" class="text-sm font-black text-slate-600 cursor-pointer select-none italic">Personal de Dirección, Manejo y Confianza (Exento HE)</label>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-slate-950 text-white py-5 rounded-[2rem] font-black text-lg shadow-2xl shadow-slate-200 hover:bg-indigo-600 transition-all active:scale-[0.98]">
                        PROCESAR INFORMACIÓN
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL PERIODO (Configuración de Ley Dinámica) -->
    <div id="modalPeriodo" style="display:none" class="fixed inset-0 bg-slate-950/90 backdrop-blur-xl z-50 items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-[3rem] shadow-2xl max-w-xl w-full my-auto animate-in slide-in-from-bottom-10 duration-500">
            <div class="p-10">
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h2 class="text-3xl font-black text-slate-900">Configurar Ley</h2>
                        <p class="text-slate-400 text-sm font-bold">Ajustes de SMLV y jornada laboral.</p>
                    </div>
                    <button onclick="document.getElementById('modalPeriodo').style.display='none'" class="text-slate-300 hover:text-red-500 transition-all">
                        <i class="fas fa-times-circle text-3xl"></i>
                    </button>
                </div>

                <form action="" method="POST" class="space-y-5">
                    <input type="hidden" name="accion" value="guardar_periodo">
                    
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Descripción del Periodo</label>
                        <input type="text" name="nombre" placeholder="Eje: Ajuste Salarial Julio 2024" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold outline-none focus:border-indigo-400">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Vigencia Desde</label>
                            <input type="date" name="inicio" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold outline-none focus:border-indigo-400">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Vigencia Hasta</label>
                            <input type="date" name="fin" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold outline-none focus:border-indigo-400">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Valor SMLV</label>
                            <input type="number" name="smlv" required class="w-full bg-indigo-50 border-2 border-indigo-100 rounded-2xl p-4 font-black text-indigo-600 outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Aux. Transporte</label>
                            <input type="number" name="subsidio" required class="w-full bg-indigo-50 border-2 border-indigo-100 rounded-2xl p-4 font-black text-indigo-600 outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">Jornada Sem.</label>
                            <input type="number" name="horas" value="47" required class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold text-center outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">% Noct.</label>
                            <input type="number" step="0.01" name="nocturno" value="35.00" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold text-center outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-1">% Fest.</label>
                            <input type="number" step="0.01" name="festivo" value="75.00" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 font-bold text-center outline-none">
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-indigo-600 text-white py-5 rounded-[2rem] font-black text-lg mt-4 shadow-xl hover:bg-indigo-700 transition-all">
                        PUBLICAR CAMBIO LEGAL
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function abrirModalEditar(emp) {
            document.getElementById('tituloModalEmp').innerText = "Actualizar Datos";
            document.getElementById('emp_accion').value = "editar_empleado";
            document.getElementById('emp_id').value = emp.id;
            document.getElementById('emp_nombre').value = emp.nombre_completo;
            document.getElementById('emp_cedula').value = emp.cedula;
            document.getElementById('emp_salario').value = emp.salario_base;
            document.getElementById('emp_fecha').value = emp.fecha_ingreso;
            document.getElementById('emp_mov').value = emp.aux_movilizacion_mensual;
            document.getElementById('emp_noc').value = emp.aux_mov_nocturno_mensual;
            document.getElementById('emp_confianza').checked = (parseInt(emp.es_direccion_confianza) === 1);
            
            document.getElementById('modalEmpleado').style.display = 'flex';
        }

        // Resetear modal al abrir para crear
        function prepararCrear() {
            document.getElementById('tituloModalEmp').innerText = "Nuevo Colaborador";
            document.getElementById('emp_accion').value = "crear_empleado";
            document.getElementById('emp_id').value = "";
            document.getElementById('emp_nombre').value = "";
            document.getElementById('emp_cedula').value = "";
            document.getElementById('emp_salario').value = "";
            document.getElementById('emp_mov').value = "0";
            document.getElementById('emp_noc').value = "0";
            document.getElementById('emp_confianza').checked = false;
        }

        // Cerrar modales click afuera
        window.onclick = function(event) {
            if (event.target.id.includes('modal')) {
                event.target.style.display = "none";
            }
        }
    </script>
</body>
</html>
