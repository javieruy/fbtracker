<?php
/**
 * Script de prueba para verificar conexión con Facebook Marketing API
 * 
 * Este script NO requiere base de datos
 * Solo verifica que puedes acceder a la API correctamente
 */

// ===== CONFIGURACIÓN - CAMBIA ESTOS VALORES =====
$ACCESS_TOKEN = "EAATjAbHDtXEBOZCY0cSSBFcsmGtCO1f3OivY7VbC8dPsb3UZAtK5NKmJAK49MuiNFZA9T1scIlBJBq73a4W6ZC9ZA2Khi09TH4TXp64SQPvKv28ogHVFeuZBxTDLQqlNuQTdeznw21XHpG1dYmLzt9tSfT878Jka6lJx7ijFdXkhBuylVaoF0yggPOXt0NpjEeLF8ZD";
$AD_ACCOUNT_ID = "act_XXXXXXXXXX"; // Opcional por ahora
// ================================================

// Configuración de la API
$API_VERSION = "v18.0";
$BASE_URL = "https://graph.facebook.com/" . $API_VERSION;

// Colores para la consola (si ejecutas desde CLI)
$IS_CLI = (php_sapi_name() === 'cli');

function printSuccess($message) {
    global $IS_CLI;
    if ($IS_CLI) {
        echo "\033[32m✓ $message\033[0m\n";
    } else {
        echo "<div style='color: green;'>✓ $message</div>";
    }
}

function printError($message) {
    global $IS_CLI;
    if ($IS_CLI) {
        echo "\033[31m✗ $message\033[0m\n";
    } else {
        echo "<div style='color: red;'>✗ $message</div>";
    }
}

function printInfo($message) {
    global $IS_CLI;
    if ($IS_CLI) {
        echo "\033[34mℹ $message\033[0m\n";
    } else {
        echo "<div style='color: blue;'>ℹ $message</div>";
    }
}

function printJson($data) {
    global $IS_CLI;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($IS_CLI) {
        echo $json . "\n";
    } else {
        echo "<pre style='background: #f0f0f0; padding: 10px; border-radius: 5px;'>" . htmlspecialchars($json) . "</pre>";
    }
}

// Si no es CLI, agregar HTML
if (!$IS_CLI) {
    echo "<!DOCTYPE html>
<html>
<head>
    <title>Test de Facebook API</title>
    <meta charset='UTF-8'>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 40px; 
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        h2 { color: #555; margin-top: 30px; }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            background: #fafafa;
            border-radius: 5px;
        }
    </style>
</head>
<body>
<div class='container'>
<h1>🔧 Test de Conexión con Facebook Marketing API</h1>
";
}

// Verificar que el token está configurado
if ($ACCESS_TOKEN === "TU_ACCESS_TOKEN_AQUI") {
    printError("ERROR: Debes configurar tu ACCESS_TOKEN en el script");
    if (!$IS_CLI) {
        echo "<p>Edita el archivo y reemplaza 'TU_ACCESS_TOKEN_AQUI' con tu token real.</p>";
        echo "</div></body></html>";
    }
    exit(1);
}

printInfo("Iniciando pruebas de conexión con Facebook API...\n");

// TEST 1: Verificar el token de acceso
if (!$IS_CLI) echo "<div class='test-section'>";
echo $IS_CLI ? "\n=== TEST 1: Verificar Token de Acceso ===\n" : "<h2>TEST 1: Verificar Token de Acceso</h2>";

$url = $BASE_URL . "/me?access_token=" . $ACCESS_TOKEN;
$response = @file_get_contents($url);

if ($response === false) {
    printError("No se pudo conectar con la API de Facebook");
    printInfo("Verifica tu token de acceso");
} else {
    $data = json_decode($response, true);
    if (isset($data['error'])) {
        printError("Error de autenticación: " . $data['error']['message']);
    } else {
        printSuccess("Token válido!");
        printInfo("Usuario/App ID: " . ($data['id'] ?? 'No disponible'));
        printInfo("Nombre: " . ($data['name'] ?? 'No disponible'));
    }
}
if (!$IS_CLI) echo "</div>";

// TEST 2: Obtener cuentas publicitarias
if (!$IS_CLI) echo "<div class='test-section'>";
echo $IS_CLI ? "\n=== TEST 2: Obtener Cuentas Publicitarias ===\n" : "<h2>TEST 2: Obtener Cuentas Publicitarias</h2>";

$url = $BASE_URL . "/me/adaccounts?fields=id,name,account_status,currency&access_token=" . $ACCESS_TOKEN;
$response = @file_get_contents($url);

if ($response === false) {
    printError("No se pudieron obtener las cuentas publicitarias");
} else {
    $data = json_decode($response, true);
    if (isset($data['error'])) {
        printError("Error: " . $data['error']['message']);
        printInfo("Asegúrate de que tu token tiene el permiso 'ads_read'");
    } else if (isset($data['data']) && count($data['data']) > 0) {
        printSuccess("Se encontraron " . count($data['data']) . " cuenta(s) publicitaria(s):");
        
        foreach ($data['data'] as $account) {
            echo $IS_CLI ? "\n" : "<div style='margin: 10px 0; padding: 10px; background: #e8f4f8; border-radius: 5px;'>";
            printInfo("Cuenta: " . $account['name']);
            printInfo("ID: " . $account['id']);
            printInfo("Estado: " . $account['account_status']);
            printInfo("Moneda: " . $account['currency']);
            echo $IS_CLI ? "" : "</div>";
        }
        
        // Guardar el primer account ID para las siguientes pruebas
        $AD_ACCOUNT_ID = $data['data'][0]['id'];
        printInfo("\nUsando cuenta: " . $AD_ACCOUNT_ID . " para las siguientes pruebas");
    } else {
        printError("No se encontraron cuentas publicitarias");
        printInfo("Verifica que tienes acceso a al menos una cuenta publicitaria");
    }
}
if (!$IS_CLI) echo "</div>";

// TEST 3: Obtener campañas (si tenemos un account ID)
if ($AD_ACCOUNT_ID && $AD_ACCOUNT_ID !== "act_XXXXXXXXXX") {
    if (!$IS_CLI) echo "<div class='test-section'>";
    echo $IS_CLI ? "\n=== TEST 3: Obtener Campañas ===\n" : "<h2>TEST 3: Obtener Campañas</h2>";
    
    $url = $BASE_URL . "/" . $AD_ACCOUNT_ID . "/campaigns?fields=id,name,status,objective&limit=5&access_token=" . $ACCESS_TOKEN;
    $response = @file_get_contents($url);
    
    if ($response === false) {
        printError("No se pudieron obtener las campañas");
    } else {
        $data = json_decode($response, true);
        if (isset($data['error'])) {
            printError("Error: " . $data['error']['message']);
        } else if (isset($data['data']) && count($data['data']) > 0) {
            printSuccess("Se encontraron campañas:");
            
            foreach ($data['data'] as $campaign) {
                echo $IS_CLI ? "\n" : "<div style='margin: 10px 0;'>";
                printInfo("Campaña: " . $campaign['name']);
                printInfo("ID: " . $campaign['id']);
                printInfo("Estado: " . $campaign['status']);
                printInfo("Objetivo: " . ($campaign['objective'] ?? 'No especificado'));
                echo $IS_CLI ? "" : "</div>";
            }
            
            // TEST 4: Obtener insights de la primera campaña
            $campaignId = $data['data'][0]['id'];
            
            if (!$IS_CLI) echo "</div><div class='test-section'>";
            echo $IS_CLI ? "\n=== TEST 4: Obtener Costos (Insights) ===\n" : "<h2>TEST 4: Obtener Costos (Insights)</h2>";
            
            $url = $BASE_URL . "/" . $campaignId . "/insights?fields=spend,impressions,clicks,cpm,cpc&date_preset=today&access_token=" . $ACCESS_TOKEN;
            $response = @file_get_contents($url);
            
            if ($response === false) {
                printError("No se pudieron obtener los insights");
            } else {
                $insightData = json_decode($response, true);
                if (isset($insightData['error'])) {
                    printError("Error: " . $insightData['error']['message']);
                    printInfo("Asegúrate de que tu token tiene el permiso 'read_insights'");
                } else if (isset($insightData['data']) && count($insightData['data']) > 0) {
                    printSuccess("Datos de costo obtenidos correctamente:");
                    printJson($insightData['data'][0]);
                } else {
                    printInfo("No hay datos de insights para hoy (la campaña podría no tener actividad)");
                }
            }
            
        } else {
            printInfo("No se encontraron campañas activas");
        }
    }
    if (!$IS_CLI) echo "</div>";
}

// TEST 5: Verificar permisos del token
if (!$IS_CLI) echo "<div class='test-section'>";
echo $IS_CLI ? "\n=== TEST 5: Verificar Permisos del Token ===\n" : "<h2>TEST 5: Verificar Permisos del Token</h2>";

$url = $BASE_URL . "/me/permissions?access_token=" . $ACCESS_TOKEN;
$response = @file_get_contents($url);

if ($response !== false) {
    $data = json_decode($response, true);
    if (isset($data['data'])) {
        printInfo("Permisos otorgados:");
        $requiredPermissions = ['ads_read', 'read_insights', 'business_management'];
        $grantedPermissions = [];
        
        foreach ($data['data'] as $permission) {
            if ($permission['status'] === 'granted') {
                $grantedPermissions[] = $permission['permission'];
                $symbol = in_array($permission['permission'], $requiredPermissions) ? "✓" : "-";
                printInfo($symbol . " " . $permission['permission']);
            }
        }
        
        // Verificar permisos requeridos
        echo $IS_CLI ? "\n" : "<br>";
        foreach ($requiredPermissions as $required) {
            if (in_array($required, $grantedPermissions)) {
                printSuccess("Permiso requerido '$required' está otorgado");
            } else {
                printError("Permiso requerido '$required' NO está otorgado");
            }
        }
    }
}
if (!$IS_CLI) echo "</div>";

// Resumen final
if (!$IS_CLI) echo "<div class='test-section' style='background: #e8f4f8;'>";
echo $IS_CLI ? "\n=== RESUMEN ===\n" : "<h2>📊 RESUMEN</h2>";

printInfo("Pruebas completadas!");
printInfo("Si todos los tests pasaron, tu configuración está lista para usar.");
printInfo("Si algún test falló, revisa los mensajes de error arriba.");

if (!$IS_CLI) {
    echo "
    <h3>Próximos pasos:</h3>
    <ol>
        <li>Si no tienes los permisos necesarios, genera un nuevo token con los permisos correctos</li>
        <li>Guarda el ID de tu cuenta publicitaria (act_XXXXX) para usarlo en el script principal</li>
        <li>Configura la base de datos y ejecuta el script de sincronización</li>
    </ol>
    </div>
    </div>
    </body>
    </html>";
}
?>