<?php
/**
 * Script de configuración para MySQL en RunCloud
 */

echo "<h1>Configuración MySQL para RunCloud</h1>";

echo "<style>
.success { color: green; background: #e8f5e9; padding: 10px; margin: 5px 0; border-radius: 5px; }
.error { color: red; background: #ffebee; padding: 10px; margin: 5px 0; border-radius: 5px; }
.info { color: blue; background: #e3f2fd; padding: 10px; margin: 5px 0; border-radius: 5px; }
.warning { color: orange; background: #fff3cd; padding: 10px; margin: 5px 0; border-radius: 5px; }
.code { background: #f5f5f5; padding: 5px; font-family: monospace; display: inline-block; }
pre { background: #f5f5f5; padding: 15px; overflow-x: auto; border-radius: 5px; }
.container { max-width: 800px; margin: 0 auto; padding: 20px; }
</style>";

echo "<div class='container'>";

echo "<div class='info'>";
echo "<h2>📌 Información importante sobre RunCloud</h2>";
echo "En RunCloud, las bases de datos MySQL se conectan de forma especial:<br><br>";
echo "1. <strong>Host:</strong> Usa <span class='code'>localhost</span> o <span class='code'>127.0.0.1</span><br>";
echo "2. <strong>Puerto:</strong> Por defecto es <span class='code'>3306</span><br>";
echo "3. <strong>Socket:</strong> RunCloud a veces usa <span class='code'>/var/run/mysqld/mysqld.sock</span><br>";
echo "4. <strong>Usuario:</strong> El que creaste en el panel de RunCloud<br>";
echo "5. <strong>Base de datos:</strong> El nombre exacto que creaste en RunCloud<br>";
echo "</div>";

// Detectar si estamos en RunCloud
echo "<h2>1. Detectando entorno RunCloud</h2>";

$runcloudIndicators = [
    '/etc/runcloud',
    '/opt/RunCloud',
    '/home/runcloud',
    '/RunCloud'
];

$isRunCloud = false;
foreach ($runcloudIndicators as $path) {
    if (file_exists($path)) {
        echo "<div class='success'>✓ RunCloud detectado: $path existe</div>";
        $isRunCloud = true;
        break;
    }
}

if (!$isRunCloud) {
    echo "<div class='warning'>⚠️ No se detectaron rutas típicas de RunCloud, pero continuaremos...</div>";
}

// Verificar configuración PHP
echo "<h2>2. Configuración PHP para MySQL</h2>";

$mysqliSocket = ini_get('mysqli.default_socket');
$pdoSocket = ini_get('pdo_mysql.default_socket');

if ($mysqliSocket) {
    echo "<div class='info'>mysqli.default_socket: <span class='code'>$mysqliSocket</span></div>";
}
if ($pdoSocket) {
    echo "<div class='info'>pdo_mysql.default_socket: <span class='code'>$pdoSocket</span></div>";
}

// Buscar sockets posibles en RunCloud
echo "<h2>3. Buscando sockets MySQL</h2>";

$sockets = [
    '/var/run/mysqld/mysqld.sock',
    '/tmp/mysql.sock',
    '/var/lib/mysql/mysql.sock',
    '/run/mysqld/mysqld.sock'
];

$foundSocket = null;
foreach ($sockets as $socket) {
    if (file_exists($socket)) {
        echo "<div class='success'>✓ Socket encontrado: <span class='code'>$socket</span></div>";
        $foundSocket = $socket;
    }
}

// Configuraciones a probar
echo "<h2>4. Configuraciones recomendadas para RunCloud</h2>";

$configurations = [
    [
        'name' => 'Configuración 1: localhost sin puerto',
        'host' => 'localhost',
        'port' => null,
        'socket' => null
    ],
    [
        'name' => 'Configuración 2: 127.0.0.1 con puerto 3306',
        'host' => '127.0.0.1',
        'port' => 3306,
        'socket' => null
    ],
    [
        'name' => 'Configuración 3: localhost con socket',
        'host' => 'localhost',
        'port' => null,
        'socket' => $foundSocket ?: '/var/run/mysqld/mysqld.sock'
    ]
];

foreach ($configurations as $config) {
    echo "<div class='warning'>";
    echo "<strong>{$config['name']}</strong>";
    echo "<pre>";
    echo "// Prueba esta configuración en config/config.php\n";
    echo "define('DB_CONFIG', [\n";
    echo "    'host' => '{$config['host']}',\n";
    if ($config['port']) {
        echo "    'port' => {$config['port']},\n";
    }
    if ($config['socket']) {
        echo "    'unix_socket' => '{$config['socket']}',\n";
    }
    echo "    'database' => 'fbtracker',  // Sin prefijos en RunCloud\n";
    echo "    'username' => 'fbtrackeruser',  // Sin prefijos en RunCloud\n";
    echo "    'password' => 'tu_password',\n";
    echo "    'charset' => 'utf8mb4'\n";
    echo "]);\n";
    echo "</pre>";
    echo "</div>";
}

// Actualizar Database.php para soportar socket
echo "<h2>5. Actualización necesaria en Database.php</h2>";
echo "<div class='warning'>";
echo "<strong>Si usas socket, actualiza el método connect() en classes/Database.php:</strong>";
echo "<pre>";
echo 'private function connect() {
    try {
        // Verificar si se especificó un socket
        if (isset(DB_CONFIG[\'unix_socket\']) && DB_CONFIG[\'unix_socket\']) {
            $dsn = sprintf(
                \'mysql:unix_socket=%s;dbname=%s;charset=%s\',
                DB_CONFIG[\'unix_socket\'],
                DB_CONFIG[\'database\'],
                DB_CONFIG[\'charset\']
            );
        } else {
            $dsn = sprintf(
                \'mysql:host=%s;port=%d;dbname=%s;charset=%s\',
                DB_CONFIG[\'host\'],
                DB_CONFIG[\'port\'],
                DB_CONFIG[\'database\'],
                DB_CONFIG[\'charset\']
            );
        }
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $this->connection = new PDO(
            $dsn,
            DB_CONFIG[\'username\'],
            DB_CONFIG[\'password\'],
            $options
        );
        
        $this->logger->debug(\'Database connection established\');
        
    } catch (PDOException $e) {
        $this->logger->critical(\'Database connection failed\', [
            \'error\' => $e->getMessage()
        ]);
        throw new Exception(\'Database connection failed: \' . $e->getMessage());
    }
}';
echo "</pre>";
echo "</div>";

// Formulario de prueba
echo "<h2>6. Probar conexión</h2>";
echo "<form method='post' style='background: #f5f5f5; padding: 20px; border-radius: 5px;'>";
echo "<h3>Ingresa tus credenciales de RunCloud:</h3>";
echo "<input type='text' name='host' placeholder='Host (localhost o 127.0.0.1)' value='" . ($_POST['host'] ?? 'localhost') . "' style='width: 100%; margin: 5px 0; padding: 8px;'><br>";
echo "<input type='text' name='username' placeholder='Usuario MySQL' value='" . ($_POST['username'] ?? '') . "' style='width: 100%; margin: 5px 0; padding: 8px;'><br>";
echo "<input type='password' name='password' placeholder='Contraseña' style='width: 100%; margin: 5px 0; padding: 8px;'><br>";
echo "<input type='text' name='database' placeholder='Nombre de base de datos' value='" . ($_POST['database'] ?? '') . "' style='width: 100%; margin: 5px 0; padding: 8px;'><br>";
echo "<label><input type='checkbox' name='use_socket' " . (isset($_POST['use_socket']) ? 'checked' : '') . "> Usar socket en lugar de TCP</label><br><br>";
echo "<button type='submit' style='padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer; border-radius: 5px;'>Probar Conexión</button>";
echo "</form>";

if ($_POST && isset($_POST['username'])) {
    echo "<div style='margin-top: 20px;'>";
    echo "<h3>Resultado de la prueba:</h3>";
    
    try {
        if (isset($_POST['use_socket']) && $_POST['use_socket']) {
            $socket = $foundSocket ?: '/var/run/mysqld/mysqld.sock';
            $dsn = "mysql:unix_socket=$socket;dbname={$_POST['database']};charset=utf8mb4";
            echo "<div class='info'>Probando con socket: $socket</div>";
        } else {
            $dsn = "mysql:host={$_POST['host']};dbname={$_POST['database']};charset=utf8mb4";
            echo "<div class='info'>Probando con host: {$_POST['host']}</div>";
        }
        
        $pdo = new PDO($dsn, $_POST['username'], $_POST['password']);
        
        echo "<div class='success'>✓ ¡Conexión exitosa!</div>";
        
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo "<div class='info'>Versión MySQL: $version</div>";
        
        // Mostrar configuración final
        echo "<div class='success' style='margin-top: 20px;'>";
        echo "<h3>Copia esta configuración en config/config.php:</h3>";
        echo "<pre>";
        echo "define('DB_CONFIG', [\n";
        if (isset($_POST['use_socket']) && $_POST['use_socket']) {
            echo "    'host' => 'localhost',\n";
            echo "    'unix_socket' => '$socket',\n";
        } else {
            echo "    'host' => '{$_POST['host']}',\n";
            echo "    'port' => 3306,\n";
        }
        echo "    'database' => '{$_POST['database']}',\n";
        echo "    'username' => '{$_POST['username']}',\n";
        echo "    'password' => 'tu_password_aqui',\n";
        echo "    'charset' => 'utf8mb4'\n";
        echo "]);\n";
        echo "</pre>";
        echo "</div>";
        
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
        
        // Sugerencias específicas para el error
        if (strpos($e->getMessage(), 'Access denied') !== false) {
            echo "<div class='warning'>Verifica en el panel de RunCloud que:<br>";
            echo "1. El usuario existe<br>";
            echo "2. La contraseña es correcta<br>";
            echo "3. El usuario tiene permisos sobre la base de datos</div>";
        }
    }
    echo "</div>";
}

// Instrucciones para RunCloud
echo "<h2>7. Pasos en el panel de RunCloud</h2>";
echo "<div class='info'>";
echo "<strong>Si aún no has creado la base de datos:</strong><br>";
echo "1. Entra al panel de RunCloud<br>";
echo "2. Ve a tu servidor → Databases<br>";
echo "3. Crea una nueva base de datos llamada <span class='code'>fbtracker</span><br>";
echo "4. Crea un usuario llamado <span class='code'>fbtrackeruser</span><br>";
echo "5. Asigna el usuario a la base de datos con todos los permisos<br>";
echo "6. Guarda la contraseña que uses<br>";
echo "</div>";

echo "</div>"; // container
?>