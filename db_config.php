<?php
// db_config.php
$base_dir = __DIR__;
$db_path = $base_dir . DIRECTORY_SEPARATOR . 'citas_medicas.db';

try {
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // 1. AUDITORÍA Y COMPROBACIÓN ESTRUCTURAL DE LA TABLA 'CITAS'
    $checkCitas = $pdo->query("PRAGMA table_info(citas)")->fetchAll(PDO::FETCH_ASSOC);
    $necesita_migracion_citas = false;
    
    if (!empty($checkCitas)) {
        foreach ($checkCitas as $column) {
            // Si la tabla citas antigua posee 'paciente_nombre', requiere corrección inmediata
            if ($column['name'] === 'paciente_nombre') {
                $necesita_migracion_citas = true;
                break;
            }
        }
    }

    // 2. REESTRUCTURACIÓN ANATÓMICA COHESIVA (SI PROCEDE)
    if ($necesita_migracion_citas) {
        // Desactivamos temporalmente las llaves foráneas para reconstruir las tablas sin bloqueos
        $pdo->exec("PRAGMA foreign_keys = OFF;");
        
        $pdo->exec("DROP TABLE IF EXISTS historiales_clinicos;");
        $pdo->exec("DROP TABLE IF EXISTS citas;");
        $pdo->exec("DROP TABLE IF EXISTS pacientes;");
        
        $pdo->exec("PRAGMA foreign_keys = ON;");
    }

    // 3. CREACIÓN / ASEGURAMIENTO DEL ESQUEMA CORRECTO REQUERIDO POR EL PROGRAMA
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pacientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombres TEXT NOT NULL,
        apellidos TEXT NOT NULL,
        ci TEXT UNIQUE NOT NULL,
        telefono TEXT,
        fecha_nacimiento DATE NOT NULL,
        alergias TEXT NOT NULL
    )");

    // Aquí se genera la estructura idónea vinculada relacionalmente
    $pdo->exec("CREATE TABLE IF NOT EXISTS citas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        paciente_id INTEGER NOT NULL,
        especialidad TEXT NOT NULL,
        fecha DATE NOT NULL,
        hora TEXT NOT NULL,
        estado TEXT DEFAULT 'Pendiente',
        FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS historiales_clinicos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        paciente_id INTEGER NOT NULL,
        cita_id INTEGER UNIQUE NOT NULL,
        observaciones TEXT,
        sintomas TEXT,
        diagnostico TEXT,
        recetas TEXT,
        detalle_specifico TEXT, 
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
        FOREIGN KEY (cita_id) REFERENCES citas(id) ON DELETE CASCADE
    )");

    // 4. VERIFICACIÓN Y CREACIÓN DE CUENTA DE ACCESO ADMINISTRATIVA
    $checkAdmin = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE usuario = 'admin'")->fetchColumn();
    if ($checkAdmin == 0) {
        $pdo->exec("INSERT INTO usuarios (usuario, password) VALUES ('admin', '1234')");
    }

} catch (PDOException $e) {
    die("Error crítico de control relacional: " . $e->getMessage());
}
?>