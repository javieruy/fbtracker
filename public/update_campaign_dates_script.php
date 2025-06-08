<?php
/**
 * Script para actualizar fechas de creación de campañas existentes
 * Ejecutar desde línea de comandos o navegador web
 * 
 * Uso:
 * - Línea de comandos: php update_campaign_creation_dates.php
 * - Navegador: http://tu-dominio.com/path/update_campaign_creation_dates.php
 */

// Configurar salida para navegador si es necesario
if (isset($_SERVER['HTTP_HOST'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "<pre>";
}

// Cargar configuración
require_once __DIR__ . '/../config/config.php';

try {
    $db = Database::getInstance();
    $fbApi = new FacebookAPI();

    echo "===========================================\n";
    echo "ACTUALIZANDO FECHAS DE CREACIÓN DE CAMPAÑAS\n";
    echo "===========================================\n\n";

    // Obtener campañas sin fecha de creación
    $campaigns = $db->fetchAll("
        SELECT id, name, account_id
        FROM campaigns 
        WHERE created_time IS NULL
        ORDER BY id
    ");

    $totalCampaigns = count($campaigns);
    echo "Campañas encontradas sin fecha de creación: {$totalCampaigns}\n\n";

    if ($totalCampaigns === 0) {
        echo "✅ Todas las campañas ya tienen fecha de creación.\n";
        exit(0);
    }

    $updated = 0;
    $errors = 0;
    $skipped = 0;

    foreach ($campaigns as $index => $campaign) {
        $progress = $index + 1;
        echo "[{$progress}/{$totalCampaigns}] Procesando: {$campaign['name']}\n";
        echo "    ID: {$campaign['id']}\n";
        
        try {
            // Obtener detalles de la campaña desde Facebook
            $details = $fbApi->getCampaignDetails($campaign['id']);
            
            if (isset($details['created_time'])) {
                // Convertir formato de Facebook a formato MySQL
                $fbDateTime = $details['created_time'];
                $mysqlDateTime = date('Y-m-d H:i:s', strtotime($fbDateTime));
                
                // Actualizar en la base de datos
                $db->query("
                    UPDATE campaigns 
                    SET created_time = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ", [$mysqlDateTime, $campaign['id']]);
                
                echo "    ✅ Actualizada: {$mysqlDateTime}\n";
                echo "    📅 Original FB format: {$fbDateTime}\n";
                $updated++;
                
                // Log adicional si hay otros campos útiles
                if (isset($details['start_time'])) {
                    echo "    📅 Start time: " . date('Y-m-d H:i:s', strtotime($details['start_time'])) . "\n";
                }
                
            } else {
                echo "    ⚠️ No se encontró created_time en la respuesta de Facebook\n";
                echo "    📄 Datos disponibles: " . implode(', ', array_keys($details)) . "\n";
                $skipped++;
            }
            
        } catch (FacebookAPIException $e) {
            echo "    ❌ Error de Facebook API: {$e->getMessage()}\n";
            
            // Algunos errores específicos de Facebook
            $errorData = $e->getErrorData();
            if (isset($errorData['code'])) {
                switch ($errorData['code']) {
                    case 100:
                        echo "    💡 La campaña puede haber sido eliminada\n";
                        break;
                    case 190:
                        echo "    🔑 Problema con el token de acceso\n";
                        break;
                    case 17:
                        echo "    ⏰ Rate limit alcanzado, esperando...\n";
                        sleep(5);
                        break;
                }
            }
            $errors++;
            
        } catch (Exception $e) {
            echo "    ❌ Error general: {$e->getMessage()}\n";
            $errors++;
        }
        
        echo "\n";
        
        // Pausa para no sobrecargar la API de Facebook
        if ($progress < $totalCampaigns) {
            usleep(500000); // 0.5 segundos entre requests
        }
        
        // Pausa más larga cada 10 campañas
        if ($progress % 10 === 0) {
            echo "    ⏸️ Pausa de 2 segundos...\n\n";
            sleep(2);
        }
    }

    echo "===========================================\n";
    echo "RESUMEN FINAL\n";
    echo "===========================================\n";
    echo "Total de campañas procesadas: {$totalCampaigns}\n";
    echo "✅ Actualizadas correctamente: {$updated}\n";
    echo "⚠️ Omitidas (sin created_time): {$skipped}\n";
    echo "❌ Errores: {$errors}\n";
    
    if ($updated > 0) {
        echo "\n🎉 Proceso completado exitosamente!\n";
        
        // Verificar resultado
        $campaignsWithDates = $db->fetchValue("
            SELECT COUNT(*) FROM campaigns WHERE created_time IS NOT NULL
        ");
        $campaignsWithoutDates = $db->fetchValue("
            SELECT COUNT(*) FROM campaigns WHERE created_time IS NULL
        ");
        
        echo "\nEstado actual:\n";
        echo "- Campañas con fecha: {$campaignsWithDates}\n";
        echo "- Campañas sin fecha: {$campaignsWithoutDates}\n";
    }
    
    if ($errors > 0) {
        echo "\n⚠️ Algunos errores ocurrieron. Puedes ejecutar el script nuevamente para reintentar.\n";
    }

} catch (Exception $e) {
    echo "\n💥 ERROR CRÍTICO: {$e->getMessage()}\n";
    echo "Archivo: {$e->getFile()}\n";
    echo "Línea: {$e->getLine()}\n";
    
    if (DEV_MODE) {
        echo "\nStack trace:\n{$e->getTraceAsString()}\n";
    }
    
    exit(1);
}

// Cerrar pre si es navegador
if (isset($_SERVER['HTTP_HOST'])) {
    echo "</pre>";
}
?>