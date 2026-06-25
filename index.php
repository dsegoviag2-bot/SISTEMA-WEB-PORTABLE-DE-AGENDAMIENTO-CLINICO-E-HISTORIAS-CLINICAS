<?php
// index.php
require_once 'db_config.php';
session_start();

$msg = "";
if (isset($_SESSION['msg'])) {
    $msg = $_SESSION['msg'];
    unset($_SESSION['msg']);
}

$route = isset($_GET['route']) ? $_GET['route'] : 'dashboard';

// Función Helper Segura para guardar en formato Capitalize (Ej: "Juan Carlos")
function formatCapitalize($text) {
    return ucwords(strtolower(trim($text)));
}

// Autenticación Directa de Strings (admin / 1234)
if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND password = ?");
    $stmt->execute([trim($_POST['user']), trim($_POST['pass'])]);
    $user = $stmt->fetch();
    
    if ($user) {
        $_SESSION['usuario'] = $user['usuario'];
        header("Location: index.php?route=dashboard");
        exit;
    } else {
        $msg = "<p class='error-txt'>❌ Credenciales incorrectas. Intente de nuevo.</p>";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['usuario'])) { $route = 'login'; }

$mostrar_formulario_paciente_nuevo = false;
$temp_busqueda = ""; $temp_especialidad = ""; $temp_fecha = ""; $temp_hora = "";

$clonado = null;
if (isset($_GET['clonar_desde_cita']) && isset($_SESSION['usuario'])) {
    $stmtC = $pdo->prepare("SELECT h.* FROM historiales_clinicos h WHERE h.paciente_id = ? ORDER BY h.id DESC LIMIT 1");
    $stmtC->execute([$_GET['paciente_id']]);
    $clonado = $stmtC->fetch(PDO::FETCH_ASSOC);
    if ($clonado) {
        $clonado['detalle_json'] = json_decode($clonado['detalle_specifico'] ?? '{}', true);
    }
}

// Paso 1: Encolar Cita Ordinaria
if (isset($_POST['agendar']) && isset($_SESSION['usuario'])) {
    $busqueda = formatCapitalize($_POST['paciente_buscar']);
    
    $stmt = $pdo->prepare("SELECT id FROM pacientes WHERE (nombres || ' ' || apellidos) = ? OR ci = ?");
    $stmt->execute([$busqueda, $busqueda]);
    $paciente = $stmt->fetch();
    
    if ($paciente) {
        $stmt = $pdo->prepare("INSERT INTO citas (paciente_id, especialidad, fecha, hora, estado) VALUES (?, ?, ?, ?, 'Pendiente')");
        $stmt->execute([$paciente['id'], $_POST['especialidad'], $_POST['fecha'], $_POST['hora']]);
        $_SESSION['msg'] = "<p class='success-msg'>✅ Cita insertada con éxito en la cola FIFO.</p>";
        header("Location: index.php?route=dashboard");
        exit;
    } else {
        $mostrar_formulario_paciente_nuevo = true;
        $temp_busqueda = $busqueda;
        $temp_especialidad = $_POST['especialidad'];
        $temp_fecha = $_POST['fecha'];
        $temp_hora = $_POST['hora'];
        $msg = "<p class='warning-msg' style='background:#fff3cd; color:#856404; padding:12px; border-radius:5px;'>⚠️ Paciente no registrado. Complete la Ficha Obligatoria subsanando los nombres y apellidos.</p>";
    }
}

// Paso 2: Registro de Paciente Nuevo (Capitalize + Transacción + Interceptación de CI Duplicada)
if (isset($_POST['registrar_completo']) && isset($_SESSION['usuario'])) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO pacientes (nombres, apellidos, ci, telefono, fecha_nacimiento, alergias) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            formatCapitalize($_POST['nuevo_nombres']), 
            formatCapitalize($_POST['nuevo_apellidos']), 
            trim($_POST['nuevo_ci']),
            trim($_POST['nuevo_telefono']), 
            $_POST['nuevo_fecha_nacimiento'], 
            formatCapitalize($_POST['nuevo_alergias'])
        ]);
        $paciente_id = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("INSERT INTO citas (paciente_id, especialidad, fecha, hora, estado) VALUES (?, ?, ?, ?, 'Pendiente')");
        $stmt->execute([$paciente_id, $_POST['temp_especialidad'], $_POST['temp_fecha'], $_POST['temp_hora']]);
        
        $pdo->commit();
        $_SESSION['msg'] = "<p class='success-msg'>✅ Ficha de datos personales creada y cita guardada en la cola FIFO.</p>";
        header("Location: index.php?route=dashboard");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        
        if ($e->getCode() == 23000 || strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
            $mostrar_formulario_paciente_nuevo = true;
            $temp_especialidad = $_POST['temp_especialidad'];
            $temp_fecha = $_POST['temp_fecha'];
            $temp_hora = $_POST['temp_hora'];
            $msg = "<p class='error-txt' style='background:#fef2f2; color:var(--danger); padding:12px; border-radius:5px; border:1px solid #fca5a5;'>⚠️ ERROR: La Cédula de Identidad (CI) <strong>" . htmlspecialchars($_POST['nuevo_ci']) . "</strong> ya le pertenece a otro paciente. Corríjala para poder guardar.</p>";
        } else {
            $_SESSION['msg'] = "<p class='error-msg'>Inconsistencia inesperada: " . $e->getMessage() . "</p>";
            header("Location: index.php?route=dashboard");
            exit;
        }
    }
}

// Procesamiento de Consulta con Cierre de Dictamen Clínico
if (isset($_POST['actualizar_estatus']) && isset($_SESSION['usuario'])) {
    $cita_id = $_POST['cita_id'];
    $nuevo_estado = $_POST['estado_cambio'];
    $paciente_id = $_POST['paciente_id'];
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE citas SET estado = ? WHERE id = ?");
        $stmt->execute([$nuevo_estado, $cita_id]);
        
        if ($nuevo_estado === 'Cumplida') {
            $json_data = [];
            if (isset($_POST['esp']) && is_array($_POST['esp'])) {
                foreach ($_POST['esp'] as $key => $val) { 
                    $json_data[$key] = formatCapitalize($val); 
                }
            }
            $serialized_json = json_encode($json_data, JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("INSERT INTO historiales_clinicos (paciente_id, cita_id, observaciones, sintomas, diagnostico, recetas, detalle_specifico) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $paciente_id, $cita_id, 
                formatCapitalize($_POST['observaciones']), 
                formatCapitalize($_POST['sintomas']),
                formatCapitalize($_POST['diagnostico']), 
                formatCapitalize($_POST['recetas']), 
                $serialized_json
            ]);
        }
        $pdo->commit();
        $_SESSION['msg'] = "<p class='success-msg'>Cita procesada. Registro archivado con éxito.</p>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['msg'] = "<p class='error-msg'>Fallo en dictamen: " . $e->getMessage() . "</p>";
    }
    header("Location: index.php?route=dashboard");
    exit;
}

// INTERCEPTOR EXCLUSIVO DE IMPRESIÓN LIMPIA Y DESCARGA EN PDF
if ($route === 'imprimir' && isset($_GET['id_historial']) && isset($_SESSION['usuario'])): 
    $stmt = $pdo->prepare("SELECT h.*, p.*, c.especialidad, c.fecha as fecha_cita FROM historiales_clinicos h JOIN pacientes p ON h.paciente_id = p.id JOIN citas c ON h.cita_id = c.id WHERE h.id = ?");
    $stmt->execute([$_GET['id_historial']]);
    $print = $stmt->fetch(PDO::FETCH_ASSOC);
    if($print):
        $json_detalles = json_decode($print['detalle_specifico'] ?? '{}', true);
        $esp_activa = $print['especialidad'];
?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Imprimir Dictamen - <?= htmlspecialchars($print['ci']) ?></title>
        <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #222; margin: 30px; background: #fff; line-height: 1.5; }
            .print-container { max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #eaeaea; border-radius: 8px; }
            .print-header { text-align: center; margin-bottom: 25px; border-bottom: 3px solid #0f4c81; padding-bottom: 15px; }
            .print-header h2 { margin: 0; color: #0f4c81; letter-spacing: 1px; font-size: 1.7rem; }
            .print-header p { margin: 5px 0 0 0; color: #666; font-size: 0.95rem; }
            .data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .data-table td { padding: 8px 5px; border-bottom: 1px solid #eee; font-size: 0.95rem; }
            .section-title { color: #0f4c81; border-bottom: 1px solid #0f4c81; margin-top: 25px; margin-bottom: 10px; padding-bottom: 4px; font-size: 1.2rem; }
            .print-specialty-box { background: #f4f7f6; padding: 15px; margin: 15px 0; border-left: 4px solid #2a9d8f; border-radius: 4px; }
            .print-specialty-box h4 { margin: 0 0 10px 0; color: #2a9d8f; }
            .print-specialty-box p { margin: 5px 0; font-size: 0.95rem; }
            .print-prescription { margin-top: 30px; border: 2px dashed #000; padding: 20px; background: #fff; border-radius: 4px; }
            .print-prescription h3 { margin-top: 0; color: #000; border-bottom: 1px solid #000; padding-bottom: 5px; font-size: 1.3rem; }
            @media print {
                body { margin: 0; padding: 0; }
                .print-container { border: none; max-width: 100%; padding: 0; }
                @page { size: auto; margin: 15mm; }
            }
        </style>
    </head>
    <body>
        <div class="print-container">
            <div class="print-header">
                <h2>DICTAMEN CLÍNICO PROFESIONAL</h2>
                <p>Consultorio Médico Portable Pro Local</p>
            </div>
            <table class="data-table">
                <tr>
                    <td><strong>Paciente:</strong> <?= htmlspecialchars($print['nombres']." ".$print['apellidos']) ?></td>
                    <td><strong>Identificación/CI:</strong> <?= htmlspecialchars($print['ci']) ?></td>
                </tr>
                <tr>
                    <td><strong>Fecha de Consulta:</strong> <?= htmlspecialchars($print['fecha_cita']) ?></td>
                    <td><strong>Especialidad:</strong> <?= htmlspecialchars($esp_activa) ?></td>
                </tr>
            </table>
            <div class="section-title">Anamnesis y Hallazgos Clínicos</div>
            <p><strong>Síntomas Reportados:</strong> <?= htmlspecialchars($print['sintomas']) ?></p>
            <p><strong>Observaciones / Examen Físico:</strong> <?= htmlspecialchars($print['observaciones']) ?></p>
            <p><strong>Diagnóstico Médico Definitivo:</strong> <?= htmlspecialchars($print['diagnostico']) ?></p>
            <div class="print-specialty-box">
                <h4>Métricas Estructuradas de <?= htmlspecialchars($esp_activa) ?></h4>
                <?php if ($esp_activa === 'Medicina General'): ?>
                    <p>• <strong>Presión Arterial:</strong> <?= htmlspecialchars($json_detalles['presion_arterial'] ?? 'N/R') ?></p>
                    <p>• <strong>Temperatura (°C):</strong> <?= htmlspecialchars($json_detalles['temperatura'] ?? 'N/R') ?></p>
                    <p>• <strong>Frecuencia Cardíaca (LPM):</strong> <?= htmlspecialchars($json_detalles['frecuencia_cardiaca'] ?? 'N/R') ?></p>
                <?php elseif ($esp_activa === 'Pediatría'): ?>
                    <p>• <strong>Peso (Kg):</strong> <?= htmlspecialchars($json_detalles['peso'] ?? 'N/R') ?></p>
                    <p>• <strong>Talla (cm):</strong> <?= htmlspecialchars($json_detalles['talla'] ?? 'N/R') ?></p>
                    <p>• <strong>Percentil:</strong> <?= htmlspecialchars($json_detalles['percentil'] ?? 'N/R') ?></p>
                <?php elseif ($esp_activa === 'Cardiología'): ?>
                    <p>• <strong>Hallazgos del ECG:</strong> <?= htmlspecialchars($json_detalles['hallazgos_ecg'] ?? 'N/R') ?></p>
                    <p>• <strong>Factores de Riesgo Cardiovascular:</strong> <?= htmlspecialchars($json_detalles['factores_riesgo'] ?? 'N/R') ?></p>
                <?php elseif ($esp_activa === 'Oftalmología'): ?>
                    <p>• <strong>Ojo Izquierdo:</strong> <?= htmlspecialchars($json_detalles['ojo_izquierdo'] ?? 'N/R') ?></p>
                    <p>• <strong>Ojo Derecho:</strong> <?= htmlspecialchars($json_detalles['ojo_derecho'] ?? 'N/R') ?></p>
                    <p>• <strong>Presión Intraocular (PIO):</strong> <?= htmlspecialchars($json_detalles['presion_intraocular'] ?? 'N/R') ?></p>
                <?php endif; ?>
            </div>
            <div class="print-prescription">
                <h3>💊 RECETA MÉDICA E INDICACIONES</h3>
                <p style="white-space:pre-wrap; font-family:monospace; font-size:1rem; margin:0;"><?= htmlspecialchars($print['recetas']) ?></p>
            </div>
        </div>
        <script>window.onload = function() { window.print(); }</script>
    </body>
    </html>
<?php exit; endif; endif; ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consultorio Médico Modular Pro</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php if ($route === 'login'): ?>
    <div class="login-wrapper">
        <div class="login-split-left"></div>
        <div class="login-split-right">
            <div class="login-box-container">
                <div class="meta-logo"><div class="medical-cross-logo"><div class="cross-h"></div><div class="cross-v"></div><div class="cross-center"></div></div><h1>MetaHospitalFP</h1></div>
                <?= $msg ?>
                <form method="POST">
                    <div class="input-icon-group"><span class="input-icon">👤</span><input type="text" name="user" placeholder="Usuario" required></div>
                    <div class="input-icon-group"><span class="input-icon">🔒</span><input type="password" name="pass" placeholder="Contraseña" required></div>
                    <button type="submit" name="login" class="btn-meta-submit">Iniciar sesión</button>
                </form>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="container hide-on-print">
        <div class="header">
            <span>👨‍⚕️ Profesional Activo: <strong><?= htmlspecialchars($_SESSION['usuario']) ?></strong></span>
            <nav class="main-nav">
                <a href="?route=dashboard" class="<?= $route === 'dashboard' ? 'active-tab' : '' ?>">📋 Cola de Citas (FIFO)</a>
                <a href="?route=pacientes" class="<?= $route === 'pacientes' ? 'active-tab' : '' ?>">👥 Archivo de Pacientes</a>
            </nav>
            <a href="?logout=1" class="logout">Desconectar</a>
        </div>
        
        <?= $msg ?>

        <?php if ($route === 'dashboard'): ?>
            <div class="layout-grid">
                <div>
                    <div class="card" style="<?= $mostrar_formulario_paciente_nuevo ? 'display:none;' : '' ?>">
                        <h3>📅 Agendar Cita Médica</h3>
                        <form method="POST">
                            <label>Buscar Paciente (Nombre o CI):</label>
                            <input type="text" name="paciente_buscar" placeholder="Cédula o Nombres..." required>
                            <label>Especialidad Médica:</label>
                            <select name="especialidad">
                                <option value="Medicina General">Medicina General</option>
                                <option value="Pediatría">Pediatría</option>
                                <option value="Cardiología">Cardiología</option>
                                <option value="Oftalmología">Oftalmología</option>
                            </select>
                            <div class="form-row">
                                <div><label>Fecha:</label><input type="date" name="fecha" required></div>
                                <div><label>Hora:</label><input type="time" name="hora" required></div>
                            </div>
                            <button type="submit" name="agendar" class="btn-primary">Validar y Encolar</button>
                        </form>
                    </div>

                    <?php if ($mostrar_formulario_paciente_nuevo): ?>
                        <div class="card card-warning-border">
                            <h3>📝 Registro de Ficha Obligatoria (Paciente Nuevo)</h3>
                            <form method="POST" id="form-paciente-nuevo" onsubmit="return validarFormularioPaciente(event)">
                                <input type="hidden" name="temp_especialidad" value="<?= htmlspecialchars($temp_especialidad) ?>">
                                <input type="hidden" name="temp_fecha" value="<?= htmlspecialchars($temp_fecha) ?>">
                                <input type="hidden" name="temp_hora" value="<?= htmlspecialchars($temp_hora) ?>">
                                <div class="form-row">
                                    <div>
                                        <label>Nombres (Exactamente 2):</label>
                                        <input type="text" id="nuevo_nombres" name="nuevo_nombres" required pattern="^[A-Za-záéíóúÁÉÍÓÚñÑüÜ]+\s+[A-Za-záéíóúÁÉÍÓÚñÑüÜ]+$">
                                    </div>
                                    <div>
                                        <label>Apellidos (Exactamente 2):</label>
                                        <input type="text" id="nuevo_apellidos" name="nuevo_apellidos" required pattern="^[A-Za-záéíóúÁÉÍÓÚñÑüÜ]+\s+[A-Za-záéíóúÁÉÍÓÚñÑüÜ]+$">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div><label>Cédula (CI):</label><input type="text" name="nuevo_ci" required></div>
                                    <div><label>Teléfono:</label><input type="text" name="nuevo_telefono" required></div>
                                </div>
                                <label>Fecha de Nacimiento:</label><input type="date" name="nuevo_fecha_nacimiento" required>
                                <label>🚨 Alergias:</label><textarea name="nuevo_alergias" required></textarea>
                                <button type="submit" name="registrar_completo" class="btn-save">Guardar Ficha e Insertar Cita</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>⏳ Cola FIFO de Citas Pendientes</h3>
                    <table class="table-styled">
                        <thead><tr><th>Orden</th><th>Paciente</th><th>Bloque Horario</th><th>Especialidad</th><th>Acción</th></tr></thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT c.*, p.nombres, p.apellidos FROM citas c JOIN pacientes p ON c.paciente_id = p.id WHERE c.estado = 'Pendiente' ORDER BY c.fecha ASC, c.hora ASC");
                            $counter = 1; $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            if (count($citas) == 0): ?>
                                <tr><td colspan="5" style="text-align:center; color:#777;">No hay registros pendientes.</td></tr>
                            <?php else: foreach ($citas as $row): ?>
                                <tr>
                                    <td><strong>#<?= $counter++ ?></strong></td>
                                    <td><?= htmlspecialchars($row['nombres']." ".$row['apellidos']) ?></td>
                                    <td><span class="badge-time"><?= htmlspecialchars($row['fecha']." | ".$row['hora']) ?></span></td>
                                    <td><?= htmlspecialchars($row['especialidad']) ?></td>
                                    <td><a href="?route=dashboard&ver_detalle=<?= $row['id'] ?>#detalle" class="btn-action">Atender Paciente</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (isset($_GET['ver_detalle'])): 
                $stmt = $pdo->prepare("SELECT c.*, p.nombres, p.apellidos, p.ci, p.alergias FROM citas c JOIN pacientes p ON c.paciente_id = p.id WHERE c.id = ?");
                $stmt->execute([$_GET['ver_detalle']]);
                $cita_activa = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cita_activa):
            ?>
                <div id="detalle" class="card card-detalle" style="border-top:4px solid var(--primary); margin-top:20px;">
                    <form method="POST">
                        <input type="hidden" name="cita_id" value="<?= $cita_activa['id'] ?>">
                        <input type="hidden" name="paciente_id" value="<?= $cita_activa['paciente_id'] ?>">
                        <h3>📋 Panel Clínico de Dictamen: <?= htmlspecialchars($cita_activa['nombres']." ".$cita_activa['apellidos']) ?></h3>
                        <label>Estatus de Cita:</label>
                        <select name="estado_cambio" id="estado_cambio" onchange="toggleEspecialidadForm(this.value, '<?= $cita_activa['especialidad'] ?>')">
                            <option value="Pendiente">Pendiente</option>
                            <option value="Cancelada">Cancelada</option>
                            <option value="No Presentado">No Presentado</option>
                            <option value="Cumplida">Cumplida (Abrir Dictamen)</option>
                        </select>
                        <div id="formulario_clinico_base" style="display:none; margin-top:15px;">
                            <label>Síntomas:</label><textarea name="sintomas"></textarea>
                            <label>Observaciones:</label><textarea name="observaciones"></textarea>
                            <label>Diagnóstico:</label><input type="text" name="diagnostico">
                            
                            <div id="fields_medicina_general" class="specialty-box" style="display:none;">
                                <label>Presión Arterial:</label><input type="text" name="esp[presion_arterial]">
                            </div>
                            <label>Receta Médica:</label><textarea name="recetas"></textarea>
                        </div>
                        <button type="submit" name="actualizar_estatus" class="btn-save" style="margin-top:15px;">Procesar y Archivar</button>
                    </form>
                </div>
                <script>
                    function toggleEspecialidadForm(status, esp) {
                        document.getElementById('formulario_clinico_base').style.display = (status === 'Cumplida') ? 'block' : 'none';
                        if(status === 'Cumplida' && esp === 'Medicina General') document.getElementById('fields_medicina_general').style.display = 'block';
                    }
                </script>
            <?php endif; endif; ?>

        <?php elseif ($route === 'pacientes'): ?>
            <div class="card">
                <h3>👥 Archivo Clínico Digital Cohesivo</h3>
                <div class="layout-grid-pacientes">
                    <div class="lista-box">
                        <table class="table-styled">
                            <thead><tr><th>Paciente</th><th>Identificación/CI</th><th>Acción</th></tr></thead>
                            <tbody>
                                <?php
                                $stmtP = $pdo->query("SELECT * FROM pacientes ORDER BY apellidos ASC");
                                while($p = $stmtP->fetch(PDO::FETCH_ASSOC)): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($p['apellidos'].", ".$p['nombres']) ?></strong></td>
                                    <td><?= htmlspecialchars($p['ci']) ?></td>
                                    <td><a href="?route=pacientes&id_expediente=<?= $p['id'] ?>" class="btn-action">📂 Desplegar Expediente</a></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="expediente-box">
                        <?php if (isset($_GET['id_expediente'])): 
                            $stmt = $pdo->prepare("SELECT * FROM pacientes WHERE id = ?");
                            $stmt->execute([$_GET['id_expediente']]);
                            $p_sel = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($p_sel): 
                                $fecha_nac = new DateTime($p_sel['fecha_nacimiento']);
                                $hoy = new DateTime();
                                $edad = $hoy->diff($fecha_nac)->y;
                            ?>
                            <div class="card-filiacion" style="background:#f8fafc; padding:20px; border-radius:8px; margin-bottom:20px; border-left:5px solid var(--primary); border: 1px solid #e2e8f0;">
                                <h4 style="margin-top:0; color:var(--primary);">🗂️ Ficha de Identificación Personal Completa</h4>
                                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size:0.95rem;">
                                    <div><p><strong>👤 Nombres:</strong> <?= htmlspecialchars($p_sel['nombres']) ?></p></div>
                                    <div><p><strong>👤 Apellidos:</strong> <?= htmlspecialchars($p_sel['apellidos']) ?></p></div>
                                    <div><p><strong>🪪 Cédula (CI):</strong> <?= htmlspecialchars($p_sel['ci']) ?></p></div>
                                    <div><p><strong>📞 Teléfono:</strong> <?= htmlspecialchars($p_sel['telefono'] ?? 'N/R') ?></p></div>
                                    <div><p><strong>📅 Nacimiento:</strong> <?= htmlspecialchars($p_sel['fecha_nacimiento']) ?></p></div>
                                    <div><p><strong>⏳ Edad Cronológica:</strong> <?= $edad ?> años</p></div>
                                </div>
                                <p style="margin-top:10px; background:#ffebeb; padding:8px; border-radius:4px; color:#c92a2a;"><strong>🚨 Alergias:</strong> <?= htmlspecialchars($p_sel['alergias']) ?></p>
                            </div>
                            
                            <h4 style="color:var(--dark); margin-bottom:12px;">📜 Historial Clínico Cronológico de Consultas</h4>
                            <div class="timeline-vertical">
                                <?php
                                // CONSULTA UNIFICADA: Muestra todas las citas pasadas incluyendo Canceladas o No Presentadas
                                $stmtH = $pdo->prepare("SELECT c.fecha, c.especialidad, c.estado, h.id as historial_id, h.diagnostico, h.sintomas FROM citas c LEFT JOIN historiales_clinicos h ON c.id = h.cita_id WHERE c.paciente_id = ? ORDER BY c.id DESC");
                                $stmtH->execute([$p_sel['id']]);
                                $tiene_historial = false;
                                while($h = $stmtH->fetch(PDO::FETCH_ASSOC)): 
                                    if($h['estado'] === 'Pendiente') continue;
                                    $tiene_historial = true;
                                    
                                    // Asignación de estilos dinámicos para la etiqueta de ESTATUS
                                    $bg_status = "#6c757d"; // Gris por defecto
                                    if ($h['estado'] === 'Cumplida') $bg_status = "#28a745";       // Verde
                                    if ($h['estado'] === 'Cancelada') $bg_status = "#dc3545";      // Rojo
                                    if ($h['estado'] === 'No Presentado') $bg_status = "#ffc107";  // Amarillo/Naranja
                                ?>
                                    <div class="timeline-item-block" style="border:1px solid #e2e8f0; padding:15px; border-radius:6px; margin-bottom:15px; background:#fff; box-shadow: 0 2px 4px rgba(0,0,0,0.01);">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap: wrap; gap: 10px;">
                                            <h5 style="margin:0; font-size:1rem; color:var(--primary);">
                                                ⚕️ Consulta de <?= htmlspecialchars($h['especialidad']) ?> (<?= $h['fecha'] ?>)
                                            </h5>
                                            
                                            <div style="display: flex; gap: 8px; align-items: center;">
                                                <span style="background: <?= $bg_status ?>; color: #fff; padding: 4px 10px; font-size: 0.78rem; font-weight: bold; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">
                                                    <?= htmlspecialchars($h['estado']) ?>
                                                </span>
                                                <?php if($h['estado'] === 'Cumplida' && !empty($h['historial_id'])): ?>
                                                    <a href="?route=imprimir&id_historial=<?= $h['historial_id'] ?>" target="_blank" class="btn-action" style="background:#0f4c81; color:white; border-radius:4px; padding:3px 8px; font-size: 0.85rem;">🖨️ Imprimir / PDF</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($h['estado'] === 'Cumplida'): ?>
                                            <p style="margin:4px 0; font-size:0.9rem;"><strong>Diagnóstico:</strong> <?= htmlspecialchars($h['diagnostico'] ?? 'N/R') ?></p>
                                            <p style="margin:4px 0; font-size:0.9rem; color:#555;"><strong>Síntomas:</strong> <?= htmlspecialchars($h['sintomas'] ?? 'N/R') ?></p>
                                        <?php else: ?>
                                            <p style="margin:4px 0; font-size:0.9rem; color:#777; font-style: italic;">No se generó dictamen médico clínico debido a que el estatus de la cita se archivó como finalización no presencial.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile; 
                                if (!$tiene_historial): ?>
                                    <p style="color:#777; font-style:italic; font-size:0.9rem;">El paciente aún no registra antecedentes o citas procesadas en el sistema.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; else: ?>
                            <div class="no-selection-message" style="background:#f1f5f9; border:2px dashed #cbd5e1; padding:40px; text-align:center; border-radius:8px; color:#64748b;">
                                <p style="margin:0; font-size:1.1rem;">👈 Seleccione un paciente de la lista para auditar sus datos personales y expediente completo.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</body>
</html>