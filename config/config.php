<?php
/**
 * Configuración centralizada del sistema de tracking
 * 
 * Este archivo contiene todas las configuraciones del sistema
 * Copiar a config.php y ajustar los valores
 */

// Modo de desarrollo (true muestra errores detallados)
define('DEV_MODE', true);

// Configuración de errores
if (DEV_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
}

// Configuración de zona horaria
date_default_timezone_set('America/Montevideo');

// Configuración de base de datos
define('DB_CONFIG', [
    'host' => '78.46.162.144',
    'port' => 3306,
    'database' => 'fbtracker',
    'username' => 'fbtrackeruser',
    'password' => '#5OUQuMW-UcN}S4_,+YTFadw%wzfWXT#',
    'charset' => 'utf8mb4'
]);

// Configuración de Facebook API
define('FB_CONFIG', [
    
    'access_token' => 'EAATjAbHDtXEBOZCY0cSSBFcsmGtCO1f3OivY7VbC8dPsb3UZAtK5NKmJAK49MuiNFZA9T1scIlBJBq73a4W6ZC9ZA2Khi09TH4TXp64SQPvKv28ogHVFeuZBxTDLQqlNuQTdeznw21XHpG1dYmLzt9tSfT878Jka6lJx7ijFdXkhBuylVaoF0yggPOXt0NpjEeLF8ZD',
    'api_version' => 'v18.0',
    'api_base_url' => 'https://graph.facebook.com/'
]);



// Configuración de logs
define('LOG_CONFIG', [
    'path' => __DIR__ . '/../logs/',
    'max_file_size' => 10485760, // 10MB
    'max_files' => 10,
    'levels' => ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL']
]);

// Configuración de sesión
define('SESSION_CONFIG', [
    'name' => 'fbtracker_session',
    'lifetime' => 7200, // 2 horas
    'path' => '/',
    'secure' => false, // Cambiar a true si usas HTTPS
    'httponly' => true
]);

// Configuración de la aplicación
define('APP_CONFIG', [
    'name' => 'FB Tracker System',
    'version' => '1.0.0',
    'base_url' => 'http://localhost/fbtracker/', // Ajustar según tu dominio
    'assets_version' => '1.0.0', // Para cache busting de CSS/JS
    'items_per_page' => 50,
    'sync_batch_size' => 100
]);

// Rutas de la aplicación
define('APP_PATHS', [
    'root' => dirname(__DIR__),
    'config' => __DIR__,
    'classes' => dirname(__DIR__) . '/classes',
    'includes' => dirname(__DIR__) . '/includes',
    'templates' => dirname(__DIR__) . '/templates',
    'logs' => dirname(__DIR__) . '/logs',
    'cache' => dirname(__DIR__) . '/cache',
    'public' => dirname(__DIR__) . '/public'
]);


define('VOLUUM_CONFIG', [
    'access_key_id' => 'c2b9d14f-b590-4f30-8dbe-7cf34ea37493',
    'secret_access_key' => 'rUJuQgbuM7JgZeErlVNiseVgIJwrBtr5KdcQ',
    'api_url' => 'https://panel-api2.voluum.com',
   'workspace_id' => '1ac31d3f-283c-4769-834f-253acee4afec' 
]);


// Autoloader para las clases
spl_autoload_register(function ($class) {
    $file = APP_PATHS['classes'] . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Crear directorios necesarios si no existen
foreach (['logs', 'cache'] as $dir) {
    $path = APP_PATHS[$dir];
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

// Función helper para verificar configuración
function checkConfiguration() {
    $errors = [];
    
    // Verificar configuración de Facebook
    if (FB_CONFIG['access_token'] === 'TU_ACCESS_TOKEN') {
        $errors[] = 'Facebook Access Token no configurado';
    }
    
    // Verificar conexión a base de datos
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d', DB_CONFIG['host'], DB_CONFIG['port']),
            DB_CONFIG['username'],
            DB_CONFIG['password']
        );
        $pdo = null;
    } catch (PDOException $e) {
        $errors[] = 'No se puede conectar a la base de datos: ' . $e->getMessage();
    }
    
    // Verificar permisos de escritura en logs
    if (!is_writable(APP_PATHS['logs'])) {
        $errors[] = 'El directorio de logs no tiene permisos de escritura';
    }
    
    return $errors;
}
?>