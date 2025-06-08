<?php
/**
 * Script para diagnosticar por qué no se actualiza el status
 */

require_once '../config/config.php';

$campaignId = '120224733492130185'; // Tu campaña de ejemplo
$db = Database::getInstance();
$fbApi = new FacebookAPI();

echo "<h2>Diagnóstico de Sincronización de Status</h2>\n";

try {
    echo "<h3>1. Status actual en nuestra DB:</h3>\n";
    $currentAds = $db->fetchAll("
        SELECT a.id, a.name, a.status, a.updated_at
        FROM ads a 
        WHERE a.campaign_id = ? 
        ORDER BY a.updated_at DESC
    ", [$campaignId]);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Ad ID</th><th>Name</th><th>Status en DB</th><th>Última actualización</th></tr>";
    foreach ($currentAds as $ad) {
        echo "<tr>";
        echo "<td>{$ad['id']}</td>";
        echo "<td>" . substr($ad['name'], 0, 30) . "...</td>";
        echo "<td><strong>{$ad['status']}</strong></td>";
        echo "<td>{$ad['updated_at']}</td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    echo "<h3>2. Status actual en Facebook API:</h3>\n";
    
    // Obtener adsets de la campaña
    $adsets = $fbApi->getAdSets($campaignId);
    
    echo "<h4>AdSets:</h4>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>AdSet ID</th><th>Name</th><th>Status en FB</th></tr>";
    foreach ($adsets as $adset) {
        echo "<tr>";
        echo "<td>{$adset['id']}</td>";
        echo "<td>" . substr($adset['name'], 0, 30) . "...</td>";
        echo "<td><strong>{$adset['status']}</strong></td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    echo "<h4>Ads:</h4>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Ad ID</th><th>Name</th><th>Status en FB</th><th>Status en DB</th><th>¿Coincide?</th></tr>";
    
    foreach ($adsets as $adset) {
        $ads = $fbApi->getAds($adset['id']);
        foreach ($ads as $ad) {
            $dbStatus = $db->fetchValue("SELECT status FROM ads WHERE id = ?", [$ad['id']]);
            $matches = ($ad['status'] === $dbStatus) ? "✓" : "❌";
            
            echo "<tr>";
            echo "<td>{$ad['id']}</td>";
            echo "<td>" . substr($ad['name'], 0, 30) . "...</td>";
            echo "<td><strong>{$ad['status']}</strong></td>";
            echo "<td><strong>{$dbStatus}</strong></td>";
            echo "<td>{$matches}</td>";
            echo "</tr>";
        }
    }
    echo "</table><br>";
    
    echo "<h3>3. Test de actualización manual:</h3>\n";
    
    // Tomar el primer ad y hacer sync manual
    if (!empty($adsets)) {
        $firstAdset = $adsets[0];
        $ads = $fbApi->getAds($firstAdset['id']);
        
        if (!empty($ads)) {
            $testAd = $ads[0];
            
            echo "Probando actualización del ad: {$testAd['id']}<br>";
            echo "Status en FB: <strong>{$testAd['status']}</strong><br>";
            
            // Status actual en DB antes de actualizar
            $beforeStatus = $db->fetchValue("SELECT status FROM ads WHERE id = ?", [$testAd['id']]);
            echo "Status en DB (antes): <strong>{$beforeStatus}</strong><br>";
            
            // Hacer la actualización
            $sql = "UPDATE ads SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $result = $db->query($sql, [$testAd['status'], $testAd['id']]);
            
            echo "Filas afectadas: {$result->rowCount()}<br>";
            
            // Verificar después
            $afterStatus = $db->fetchValue("SELECT status FROM ads WHERE id = ?", [$testAd['id']]);
            echo "Status en DB (después): <strong>{$afterStatus}</strong><br>";
            
            if ($afterStatus === $testAd['status']) {
                echo "✅ Actualización exitosa<br>";
            } else {
                echo "❌ La actualización falló<br>";
            }
        }
    }
    
    echo "<h3>4. Verificar logs de sincronización:</h3>\n";
    $lastSync = $db->fetchOne("
        SELECT * FROM sync_logs 
        WHERE sync_type = 'facebook' 
        ORDER BY completed_at DESC 
        LIMIT 1
    ");
    
    if ($lastSync) {
        echo "<pre>";
        echo "Última sincronización:\n";
        echo "- Fecha: {$lastSync['sync_date']}\n";
        echo "- Status: {$lastSync['status']}\n";
        echo "- Registros procesados: {$lastSync['records_processed']}\n";
        echo "- Error: " . ($lastSync['error_message'] ?: 'Ninguno') . "\n";
        echo "</pre>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "Trace: <pre>" . $e->getTraceAsString() . "</pre>";
}
?>