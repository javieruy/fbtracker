<?php
/**
 * Script para diagnosticar y encontrar la configuración correcta de MySQL
 */

echo "<h1>Diagnóstico de Conexión MySQL</h1>";

// Diferentes configuraciones para probar
$configurations = [
    ['host' => '78.46.162.144', 'port' => 3306]

];

// Credenciales para probar
$username = 'fbtrackeruser';  // Cambia esto
$password = '#5OUQuMW-UcN}S4_,+YTFadw%wzfWXT#';     // Cambia esto

echo "<style>
.success { color: green; background: #e8f5e9; padding: 10px; margin: 5px 0; }
.error { color: red; background: #ffebee; padding: 10px; margin: 5px 0; }
.info { color: blue; background: #e3f2fd; padding: 10px; margin: 5px 0; }
.code { background: #f5f5f5; padding: 5px; font-family: monospace; }
</style>";

// 1. Verificar si MySQL está corriendo
echo "<h2>1. Verificar servicio MySQL</h2>";

// Intentar con diferentes comandos según el sistema
$commands = [
    'systemctl status mysql',
    'service mysql status',
    'systemctl status mariadb',
    'service mariadb status',
    '/etc/init.d/mysql status',
    'ps aux | grep mysql'
];

$mysqlRunning = false;
foreach ($commands as $cmd) {
    $output = @shell_exec($cmd . ' 2>&1');
    if ($output && (stripos($output, 'running') !== false || stripos($output, 'active') !== false)) {
        echo "<div class='success'>✓ MySQL está ejecutándose</div>";
        echo "<div class='info'>Comando usado: <span class='code'>$cmd</span></div>";
        $mysqlRunning = true;
        break;
    }
}

if (!$mysqlRunning) {
    $output = @shell_exec('ps aux | grep mysql 2>&1');
    if ($output && stripos($output, 'mysql') !== false) {
        echo "<div class='success'>✓ Proceso MySQL encontrado</div>";
    } else {
        echo "<div class='error'>✗ MySQL no parece estar ejecutándose</div>";
        echo "<div class='info'>Intenta iniciar MySQL con uno de estos comandos:<br>";
        echo "<span class='code'>sudo systemctl start mysql</span><br>";
        echo "<span class='code'>sudo service mysql start</span><br>";
        echo "<span class='code'>sudo /etc/init.d/mysql start</span></div>";
    }
}

// 2. Buscar el socket de MySQL
echo "<h2>2. Buscar socket de MySQL</h2>";

$possibleSockets = [
    '/var/run/mysqld/mysqld.sock',
    '/tmp/mysql.sock',
    '/var/lib/mysql/mysql.sock',
    '/var/mysql/mysql.sock',
    '/Applications/MAMP/tmp/mysql/mysql.sock',
    '/opt/lampp/var/mysql/mysql.sock'
];

$foundSocket = null;
foreach ($possibleSockets as $socket) {
    if (file_exists($socket)) {
        echo "<div class='success'>✓ Socket encontrado: <span class='code'>$socket</span></div>";
        $foundSocket = $socket;
        break;
    }
}

if (!$foundSocket) {
    echo "<div class='error'>✗ No se encontró el socket de MySQL</div>";
}

// 3. Buscar puerto de MySQL
echo "<h2>3. Verificar puerto de MySQL</h2>";

$netstatOutput = @shell_exec('netstat -ln | grep -E ":(3306|3307|8889)" 2>&1');
if (!$netstatOutput) {
    $netstatOutput = @shell_exec('ss -ln | grep -E ":(3306|3307|8889)" 2>&1');
}

if ($netstatOutput) {
    echo "<div class='info'>Puertos encontrados:<br><pre>$netstatOutput</pre></div>";
} else {
    echo "<div class='error'>✗ No se pudo verificar los puertos (puede requerir permisos)</div>";
}

// 4. Probar diferentes configuraciones
echo "<h2>4. Probar conexiones</h2>";

$workingConfig = null;

foreach ($configurations as $config) {
    echo "<div style='margin: 10px 0; padding: 10px; border: 1px solid #ddd;'>";
    
    if (isset($config['socket'])) {
        echo "<strong>Probando socket:</strong> {$config['host']} con {$config['socket']}<br>";
        $dsn = "mysql:unix_socket={$config['socket']};charset=utf8mb4";
    } else {
        echo "<strong>Probando:</strong> {$config['host']}:{$config['port']}<br>";
        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
    }
    
    try {
        $pdo = new PDO($dsn, $username, $password);
        echo "<div class='success'>✓ ¡CONEXIÓN EXITOSA!</div>";
        
        // Obtener información adicional
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo "<div class='info'>Versión MySQL: $version</div>";
        
        // Guardar configuración que funciona
        $workingConfig = $config;
        
        // Mostrar la configuración para config.php
        echo "<div class='success'><strong>Usa esta configuración en config.php:</strong><br>";
        echo "<pre>";
        echo "define('DB_CONFIG', [\n";
        if (isset($config['socket'])) {
            echo "    'host' => 'localhost',\n";
            echo "    'port' => 3306,\n";
            echo "    'unix_socket' => '{$config['socket']}',\n";
        } else {
            echo "    'host' => '{$config['host']}',\n";
            echo "    'port' => {$config['port']},\n";
        }
        echo "    'database' => 'fbtracker',\n";
        echo "    'username' => '$username',\n";
        echo "    'password' => 'tu_password',\n";
        echo "    'charset' => 'utf8mb4'\n";
        echo "]);</pre></div>";
        
        break; // Detener al encontrar una que funcione
        
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
    }
    
    echo "</div>";
}

// 5. Si ninguna funcionó, más diagnósticos
if (!$workingConfig) {
    echo "<h2>5. Diagnósticos adicionales</h2>";
    
    // Verificar archivo de configuración de MySQL
    $mycnfLocations = [
        '/etc/mysql/my.cnf',
        '/etc/my.cnf',
        '/usr/local/mysql/my.cnf',
        '~/.my.cnf'
    ];
    
    foreach ($mycnfLocations as $location) {
        $path = expandPath($location);
        if (file_exists($path)) {
            echo "<div class='info'>Archivo de configuración MySQL encontrado: <span class='code'>$path</span></div>";
            
            // Intentar leer bind-address
            $content = @file_get_contents($path);
            if ($content && preg_match('/bind-address\s*=\s*(.+)/', $content, $matches)) {
                echo "<div class='info'>bind-address configurado como: <span class='code'>{$matches[1]}</span></div>";
            }
        }
    }
    
    // Sugerencias finales
    echo "<div class='error'><strong>Posibles soluciones:</strong><br>";
    echo "1. Verifica que el usuario '$username' existe en MySQL<br>";
    echo "2. Verifica la contraseña<br>";
    echo "3. Otorga permisos al usuario:<br>";
    echo "<span class='code'>GRANT ALL ON *.* TO '$username'@'localhost' IDENTIFIED BY 'tu_password';</span><br>";
    echo "<span class='code'>GRANT ALL ON *.* TO '$username'@'127.0.0.1' IDENTIFIED BY 'tu_password';</span><br>";
    echo "<span class='code'>FLUSH PRIVILEGES;</span><br>";
    echo "4. Si usas un panel de control (cPanel, Plesk, etc.), verifica la configuración allí</div>";
}

// 6. Información del sistema
echo "<h2>6. Información del sistema</h2>";
echo "<div class='info'>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Sistema Operativo: " . PHP_OS . "<br>";
echo "Usuario PHP: " . get_current_user() . "<br>";
if (function_exists('posix_getpwuid')) {
    $processUser = posix_getpwuid(posix_geteuid());
    echo "Proceso ejecutándose como: " . $processUser['name'] . "<br>";
}
echo "</div>";

function expandPath($path) {
    if ($path[0] == '~') {
        $path = getenv('HOME') . substr($path, 1);
    }
    return $path;
}

// 7. Formulario para probar con credenciales diferentes
echo "<h2>7. Probar con credenciales diferentes</h2>";
echo "<form method='post' style='background: #f5f5f5; padding: 20px; border-radius: 5px;'>";
echo "<input type='text' name='test_user' placeholder='Usuario MySQL' value='" . htmlspecialchars($_POST['test_user'] ?? $username) . "' style='margin: 5px; padding: 5px;'><br>";
echo "<input type='password' name='test_pass' placeholder='Contraseña' style='margin: 5px; padding: 5px;'><br>";
echo "<input type='text' name='test_host' placeholder='Host (localhost o 127.0.0.1)' value='" . htmlspecialchars($_POST['test_host'] ?? 'localhost') . "' style='margin: 5px; padding: 5px;'><br>";
echo "<input type='text' name='test_port' placeholder='Puerto (3306)' value='" . htmlspecialchars($_POST['test_port'] ?? '3306') . "' style='margin: 5px; padding: 5px;'><br>";
echo "<button type='submit' style='padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer;'>Probar Conexión</button>";
echo "</form>";

if ($_POST) {
    echo "<div style='margin-top: 20px; padding: 20px; background: #f9f9f9;'>";
    echo "<h3>Resultado de la prueba:</h3>";
    
    try {
        $testDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', 
            $_POST['test_host'], 
            $_POST['test_port']
        );
        
        $testPdo = new PDO($testDsn, $_POST['test_user'], $_POST['test_pass']);
        echo "<div class='success'>✓ ¡Conexión exitosa con las credenciales proporcionadas!</div>";
        
    } catch (PDOException $e) {
        echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
    }
    echo "</div>";
}
?>