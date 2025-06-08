# Facebook Tracker System

Sistema de tracking para campañas de Facebook con integración preparada para Voluum.

## Estructura del Proyecto

```
fbtracker/
├── config/
│   ├── config.php.example    # Configuración de ejemplo
│   └── config.php           # Tu configuración (no subir a git)
├── classes/
│   ├── Database.php         # Manejo de base de datos
│   ├── Logger.php           # Sistema de logging
│   ├── FacebookAPI.php      # Cliente API de Facebook
│   └── SyncService.php      # Servicio de sincronización
├── includes/
│   └── error_handler.php    # Manejador global de errores
├── public/
│   └── index.php           # Punto de entrada principal
├── logs/                   # Logs del sistema (se crea automáticamente)
├── cache/                  # Cache temporal (se crea automáticamente)
└── templates/              # Plantillas HTML (futuro)
```

## Instalación

### 1. Requisitos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Extensiones PHP: `curl`, `json`, `pdo`, `pdo_mysql`
- Servidor web (Apache/Nginx)

### 2. Configuración de Base de Datos

1. Ejecuta el script SQL para crear la base de datos (del documento anterior)
2. Ejecuta el script de actualización para agregar la tabla de cuentas

### 3. Configuración del Sistema

1. Copia `config/config.php.example` a `config/config.php`
2. Edita `config/config.php` con tus credenciales:

```php
// Base de datos
define('DB_CONFIG', [
    'host' => 'localhost',
    'database' => 'fbtracker',
    'username' => 'tu_usuario',
    'password' => 'tu_password'
]);

// Facebook API
define('FB_CONFIG', [
    'app_id' => 'TU_APP_ID',
    'app_secret' => 'TU_APP_SECRET',
    'access_token' => 'TU_ACCESS_TOKEN'
]);
```

### 4. Configuración del Servidor Web

#### Apache (.htaccess)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ public/index.php?url=$1 [QSA,L]
```

#### Nginx

```nginx
location / {
    try_files $uri $uri/ /public/index.php?$query_string;
}
```

### 5. Permisos de Directorios

```bash
chmod 755 logs/
chmod 755 cache/
```

## Uso del Sistema

### Dashboard Principal

1. Accede a `http://tu-dominio.com/fbtracker/public/`
2. Verás el dashboard con las cuentas publicitarias

### Sincronización Manual

1. **Sincronizar Cuentas**: Click en "Sync All Accounts"
2. **Sincronizar Campañas**: Entra a una cuenta y click en "Sync Campaigns"

### Ver Métricas

1. Click en "View Campaigns" para ver las campañas de una cuenta
2. Click en "Details" para ver métricas diarias y ads

## Sistema de Logging

Los logs se guardan en el directorio `logs/` con el formato:

- `YYYY-MM-DD.log` - Log general del día
- `errors_YYYY-MM-DD.log` - Solo errores y críticos

### Niveles de Log

- **DEBUG**: Información detallada (solo en modo desarrollo)
- **INFO**: Eventos informativos
- **WARNING**: Situaciones anormales pero no críticas
- **ERROR**: Errores que requieren atención
- **CRITICAL**: Errores críticos del sistema

### Ver Logs en Tiempo Real

```bash
tail -f logs/$(date +%Y-%m-%d).log
```

## Manejo de Errores

El sistema captura automáticamente todos los errores y:

1. Los registra en el log
2. Muestra mensajes amigables al usuario
3. En modo desarrollo, muestra detalles completos

### Errores Comunes

**"Configuration Error"**
- Verifica que hayas configurado correctamente `config/config.php`
- Asegúrate de que las credenciales de Facebook sean válidas

**"Database connection failed"**
- Verifica las credenciales de MySQL
- Asegúrate de que el servidor MySQL esté activo
- Verifica que el usuario tenga permisos sobre la base de datos

**"Facebook API Error"**
- Token expirado: Genera un nuevo token
- Límite de rate: Espera unos minutos antes de reintentar
- Permisos insuficientes: Verifica que el token tenga `ads_read` y `read_insights`

## API Endpoints (para desarrollo futuro)

El sistema está preparado para agregar endpoints API:

```php
// Ejemplo de endpoint para obtener campañas
// api/campaigns.php
require_once '../config/config.php';
require_once '../includes/error_handler.php';

// Verificar autenticación API
$apiKey = $_GET['api_key'] ?? '';
if ($apiKey !== 'tu_api_key_secreta') {
    jsonError('Invalid API key', 401);
}

// Obtener campañas
$db = Database::getInstance();
$campaigns = $db->fetchAll("SELECT * FROM campaigns WHERE account_id = ?", [$_GET['account_id']]);

jsonSuccess($campaigns);
```

## Cronjob para Sincronización Automática

Crea un archivo `cron/sync.php`:

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/error_handler.php';

$logger = Logger::getInstance();
$logger->info('Starting cron sync');

try {
    $syncService = new SyncService();
    
    // Sincronizar todas las cuentas
    $result = $syncService->syncAllAccounts();
    
    if ($result['success']) {
        // Sincronizar campañas de cada cuenta
        $db = Database::getInstance();
        $accounts = $db->fetchAll("SELECT id FROM ad_accounts WHERE is_active = 1");
        
        foreach ($accounts as $account) {
            $syncService->syncAccountCampaigns($account['id'], true, 1); // Solo último día
        }
        
        $logger->info('Cron sync completed successfully');
    } else {
        $logger->error('Cron sync failed', ['error' => $result['error']]);
    }
    
} catch (Exception $e) {
    $logger->logException($e);
}
```

Agregar al crontab:
```bash
*/30 * * * * /usr/bin/php /ruta/a/fbtracker/cron/sync.php >> /ruta/a/fbtracker/logs/cron.log 2>&1
```

## Integración con Voluum (Preparada)

El sistema está preparado para integrar con Voluum. Cuando tengas la API de Voluum:

1. Agrega las credenciales en `config/config.php`:
```php
define('VOLUUM_CONFIG', [
    'api_key' => 'TU_VOLUUM_API_KEY',
    'api_url' => 'https://api.voluum.com/',
    'workspace_id' => 'TU_WORKSPACE_ID'
]);
```

2. Crea la clase `VoluumAPI.php` similar a `FacebookAPI.php`

3. Extiende `SyncService.php` para sincronizar conversiones

## Troubleshooting

### Verificar Estado del Sistema

1. **Verificar Token de Facebook**:
   ```
   http://tu-dominio.com/fbtracker/public/test-facebook.php
   ```

2. **Ver Logs Recientes**:
   ```
   http://tu-dominio.com/fbtracker/public/view-logs.php
   ```

3. **Limpiar Cache**:
   ```bash
   rm -rf cache/*
   ```

### Performance

Para mejorar el rendimiento con muchas campañas:

1. **Índices adicionales**:
```sql
CREATE INDEX idx_facebook_costs_entity_date 
ON facebook_costs(entity_id, entity_type, date);
```

2. **Particionamiento** (para tablas muy grandes):
```sql
ALTER TABLE facebook_costs 
PARTITION BY RANGE (TO_DAYS(date)) (
    PARTITION p_2025_01 VALUES LESS THAN (TO_DAYS('2025-02-01')),
    PARTITION p_2025_02 VALUES LESS THAN (TO_DAYS('2025-03-01')),
    -- más particiones...
);
```

## Seguridad

### Recomendaciones

1. **Nunca subas `config/config.php` a Git**
2. **Usa HTTPS en producción**
3. **Limita el acceso por IP si es posible**
4. **Rota los tokens de acceso regularmente**
5. **Implementa autenticación de usuarios**

### Ejemplo de autenticación básica:

```php
// includes/auth.php
session_start();

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function checkPermission($permission) {
    // Implementar lógica de permisos
    return true;
}
```

## Desarrollo Futuro

### Características Planeadas

1. **Dashboard con gráficos**: Integrar Chart.js para visualizaciones
2. **Alertas automáticas**: Notificar cuando el ROI baje de cierto umbral
3. **API REST completa**: Para integrar con otras herramientas
4. **Multi-usuario**: Sistema de usuarios y permisos
5. **Exportación de datos**: CSV, Excel, PDF
6. **Webhooks**: Notificar cambios a sistemas externos

### Estructura para Multi-usuario

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    role ENUM('admin', 'manager', 'viewer') DEFAULT 'viewer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_accounts (
    user_id INT,
    account_id VARCHAR(50),
    permissions JSON,
    PRIMARY KEY (user_id, account_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (account_id) REFERENCES ad_accounts(id)
);
```

## Soporte

Para problemas o preguntas:

1. Revisa los logs en `logs/`
2. Verifica la configuración en `config/config.php`
3. Asegúrate de que los permisos de Facebook sean correctos
4. Verifica la conectividad con la base de datos

## Licencia

Este proyecto es privado. No distribuir sin autorización.