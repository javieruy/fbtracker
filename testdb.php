<?php
/**
 * Script de prueba de conexión a la base de datos
 * 
 * Este script verifica:
 * 1. Conexión al servidor MySQL
 * 2. Acceso a la base de datos
 * 3. Permisos sobre las tablas
 * 4. Configuración del charset
 */

// Intentar cargar la configuración si existe
$configFile = __DIR__ . '/config/config.php';
$useConfig = false;

if (file_exists($configFile)) {
    require_once $configFile;
    $useConfig = true;
    echo "<h2>Usando configuración desde config/config.php</h2>";
} else {
    echo "<h2>Archivo config/config.php no encontrado. Usando valores de prueba.</h2>";
    
    // Configuración manual si no existe el archivo
    define('DB_CONFIG', [
        'host' => '78.46.162.144',
        'port' => 3306,
        'database' => 'fbtracker',
        'username' => 'fbtrackeruser',
        'password' => '#5OUQuMW-UcN}S4_,+YTFadw%wzfWXT#',
        'charset' => 'utf8mb4'
    ]);
}

// Colores para la salida
$styles = "
<style>
    body { 
        font-family: Arial, sans-serif; 
        margin: 40px;
        background: #f5f5f5;
    }
    .container {
        background: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        max-width: 800px;
        margin: 0 auto;
    }
    .success { 
        color: #28a745; 
        background: #d4edda;
        padding: 10px;
        border-radius: 4px;
        margin: 10px 0;
    }
    .error { 
        color: #dc3545; 
        background: #f8d7da;
        padding: 10px;
        border-radius: 4px;
        margin: 10px 0;
    }
    .info { 
        color: #004085; 
        background: #cce5ff;
        padding: 10px;
        border-radius: 4px;
        margin: 10px 0;
    }
    .warning { 
        color: #856404; 
        background: #fff3cd;
        padding: 10px;
        border-radius: 4px;
        margin: 10px 0;
    }
    pre {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        overflow-x: auto;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }
    th, td {
        text-align: left;
        padding: 10px;
        border-bottom: 1px solid #ddd;
    }
    th {
        background: #f8f9fa;
        font-weight: bold;
    }
    .config-form {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 4px;
        margin: 20px 0;
    }
    .config-form input {
        width: 100%;
        padding: 8px;
        margin: 5px 0;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    .config-form button {
        background: #007bff;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }
</style>
";

echo $styles;
echo "<div class='container'>";
echo "<h1>Test de Conexión a Base de Datos</h1>";

// Mostrar configuración actual (ocultando la contraseña)
echo "<div class='info'>";
echo "<strong>Configuración actual:</strong><br>";
echo "Host: " . DB_CONFIG['host'] . "<br>";
echo "Puerto: " . DB_CONFIG['port'] . "<br>";
echo "Base de datos: " . DB_CONFIG['database'] . "<br>";
echo "Usuario: " . DB_CONFIG['username'] . "<br>";
echo "Contraseña: " . str_repeat('*', strlen(DB_CONFIG['password'])) . "<br>";
echo "Charset: " . DB_CONFIG['charset'] . "<br>";
echo "</div>";

// PASO 1: Verificar extensión PDO
echo "<h3>Paso 1: Verificar extensión PDO</h3>";
if (!extension_loaded('pdo')) {
    echo "<div class='error'>❌ La extensión PDO no está instalada. Instálala con: sudo apt-get install php-pdo</div>";
    exit;
} else {
    echo "<div class='success'>✅ Extensión PDO instalada</div>";
}

if (!extension_loaded('pdo_mysql')) {
    echo "<div class='error'>❌ La extensión PDO MySQL no está instalada. Instálala con: sudo apt-get install php-mysql</div>";
    exit;
} else {
    echo "<div class='success'>✅ Extensión PDO MySQL instalada</div>";
}

// PASO 2: Intentar conexión al servidor MySQL (sin seleccionar base de datos)
echo "<h3>Paso 2: Conexión al servidor MySQL</h3>";
try {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', 
        DB_CONFIG['host'], 
        DB_CONFIG['port'],
        DB_CONFIG['charset']
    );
    
    $pdo = new PDO($dsn, DB_CONFIG['username'], DB_CONFIG['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='success'>✅ Conexión al servidor MySQL exitosa</div>";
    
    // Mostrar versión de MySQL
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "<div class='info'>Versión de MySQL: $version</div>";
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Error al conectar al servidor MySQL</div>";
    echo "<div class='error'><strong>Error:</strong> " . $e->getMessage() . "</div>";
    
    // Sugerencias según el error
    if (strpos($e->getMessage(), 'Access denied') !== false) {
        echo "<div class='warning'><strong>Sugerencias:</strong><br>
        - Verifica que el usuario y contraseña sean correctos<br>
        - Asegúrate de que el usuario tenga permisos desde el host actual<br>
        - Prueba ejecutar en MySQL: <code>GRANT ALL ON *.* TO 'fbtrackeruser'@'%' IDENTIFIED BY 'tu_password';</code>
        </div>";
    } elseif (strpos($e->getMessage(), 'Connection refused') !== false || strpos($e->getMessage(), 'No such file') !== false) {
        echo "<div class='warning'><strong>Sugerencias:</strong><br>
        - Verifica que MySQL esté ejecutándose<br>
        - Verifica el host y puerto (¿es localhost o 127.0.0.1?)<br>
        - Si usas MAMP/XAMPP, el puerto podría ser 8889 o 3307<br>
        - Comando para verificar: <code>sudo service mysql status</code>
        </div>";
    }
    exit;
}

// PASO 3: Verificar si la base de datos existe
echo "<h3>Paso 3: Verificar base de datos</h3>";
try {
    $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array(DB_CONFIG['database'], $databases)) {
        echo "<div class='success'>✅ La base de datos '" . DB_CONFIG['database'] . "' existe</div>";
    } else {
        echo "<div class='error'>❌ La base de datos '" . DB_CONFIG['database'] . "' NO existe</div>";
        echo "<div class='warning'>Bases de datos disponibles: " . implode(', ', $databases) . "</div>";
        echo "<div class='info'>Para crearla, ejecuta: <code>CREATE DATABASE " . DB_CONFIG['database'] . ";</code></div>";
        exit;
    }
} catch (PDOException $e) {
    echo "<div class='error'>❌ Error al listar bases de datos: " . $e->getMessage() . "</div>";
}

// PASO 4: Conectar a la base de datos específica
echo "<h3>Paso 4: Conectar a la base de datos</h3>";
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', 
        DB_CONFIG['host'], 
        DB_CONFIG['port'],
        DB_CONFIG['database'],
        DB_CONFIG['charset']
    );
    
    $pdo = new PDO($dsn, DB_CONFIG['username'], DB_CONFIG['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='success'>✅ Conexión a la base de datos exitosa</div>";
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Error al conectar a la base de datos</div>";
    echo "<div class='error'><strong>Error:</strong> " . $e->getMessage() . "</div>";
    exit;
}

// PASO 5: Verificar tablas
echo "<h3>Paso 5: Verificar tablas</h3>";
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "<div class='warning'>⚠️ La base de datos está vacía. Necesitas ejecutar el script SQL de creación de tablas.</div>";
    } else {
        echo "<div class='success'>✅ Se encontraron " . count($tables) . " tablas</div>";
        
        // Verificar tablas principales
        $requiredTables = ['campaigns', 'adsets', 'ads', 'facebook_costs', 'ad_accounts'];
        $missingTables = array_diff($requiredTables, $tables);
        
        if (empty($missingTables)) {
            echo "<div class='success'>✅ Todas las tablas principales existen</div>";
        } else {
            echo "<div class='warning'>⚠️ Faltan las siguientes tablas: " . implode(', ', $missingTables) . "</div>";
        }
        
        // Mostrar todas las tablas
        echo "<table>";
        echo "<tr><th>Tabla</th><th>Registros</th></tr>";
        foreach ($tables as $table) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                echo "<tr><td>$table</td><td>$count</td></tr>";
            } catch (Exception $e) {
                echo "<tr><td>$table</td><td>Error al contar</td></tr>";
            }
        }
        echo "</table>";
    }
} catch (PDOException $e) {
    echo "<div class='error'>❌ Error al verificar tablas: " . $e->getMessage() . "</div>";
}

// PASO 6: Verificar permisos
echo "<h3>Paso 6: Verificar permisos</h3>";
try {
    // Intentar INSERT
    $testTable = 'sync_logs';
    if (in_array($testTable, $tables)) {
        $pdo->beginTransaction();
        
        // Test INSERT
        $stmt = $pdo->prepare("INSERT INTO sync_logs (sync_type, sync_date, status, started_at) VALUES (?, NOW(), ?, NOW())");
        $stmt->execute(['test', 'running']);
        $insertId = $pdo->lastInsertId();
        echo "<div class='success'>✅ Permiso INSERT verificado</div>";
        
        // Test UPDATE
        $stmt = $pdo->prepare("UPDATE sync_logs SET status = ? WHERE id = ?");
        $stmt->execute(['completed', $insertId]);
        echo "<div class='success'>✅ Permiso UPDATE verificado</div>";
        
        // Test SELECT
        $stmt = $pdo->prepare("SELECT * FROM sync_logs WHERE id = ?");
        $stmt->execute([$insertId]);
        $stmt->fetch();
        echo "<div class='success'>✅ Permiso SELECT verificado</div>";
        
        // Test DELETE
        $stmt = $pdo->prepare("DELETE FROM sync_logs WHERE id = ?");
        $stmt->execute([$insertId]);
        echo "<div class='success'>✅ Permiso DELETE verificado</div>";
        
        $pdo->rollback(); // Revertir cambios de prueba
        
    } else {
        echo "<div class='warning'>⚠️ No se pueden verificar permisos porque las tablas no existen</div>";
    }
} catch (PDOException $e) {
    echo "<div class='error'>❌ Error al verificar permisos: " . $e->getMessage() . "</div>";
    $pdo->rollback();
}

echo "<hr style='margin: 30px 0;'>";
echo "<h2>✅ Resumen</h2>";

if (!empty($tables) && empty($missingTables)) {
    echo "<div class='success'><strong>¡Todo está listo!</strong> La conexión a la base de datos funciona correctamente y todas las tablas están creadas.</div>";
    
    if (!$useConfig) {
        echo "<div class='warning'>No olvides crear el archivo <code>config/config.php</code> con esta configuración.</div>";
    }
} else {
    echo "<div class='warning'><strong>Casi listo.</strong> La conexión funciona pero necesitas ejecutar el script SQL para crear las tablas.</div>";
}

// Formulario para probar con diferentes credenciales
echo "<hr style='margin: 30px 0;'>";
echo "<h3>Probar con diferentes credenciales</h3>";
echo "<div class='config-form'>";
echo "<form method='GET'>";
echo "<input type='text' name='host' placeholder='Host' value='" . ($_GET['host'] ?? DB_CONFIG['host']) . "'>";
echo "<input type='text' name='port' placeholder='Puerto' value='" . ($_GET['port'] ?? DB_CONFIG['port']) . "'>";
echo "<input type='text' name='database' placeholder='Base de datos' value='" . ($_GET['database'] ?? DB_CONFIG['database']) . "'>";
echo "<input type='text' name='username' placeholder='Usuario' value='" . ($_GET['username'] ?? DB_CONFIG['username']) . "'>";
echo "<input type='password' name='password' placeholder='Contraseña'>";
echo "<button type='submit'>Probar Conexión</button>";
echo "</form>";
echo "</div>";

// Si se enviaron parámetros por GET, usarlos
if (isset($_GET['host'])) {
    echo "<script>location.reload();</script>";
}

echo "</div>"; // container
?>
