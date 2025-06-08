<?php
/**
 * Script de sincronización manual para debug
 */

require_once '../config/config.php';
require_once '../includes/error_handler.php';

$campaignId = $_GET['campaign_id'] ?? '120224733492130185';

echo "<h1>Sincronización Manual de Campaña: $campaignId</h1>";

$db = Database::getInstance();
$fbApi = new FacebookAPI();
$syncService = new SyncService();

// Activar modo debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>1. Sincronizando estructura de campaña...</h2>";
try {
    $syncService->syncCampaignStructure($campaignId);
    echo "<p style='color: green;'>✓ Estructura sincronizada</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "<h2>2. Sincronizando costos...</h2>";
try {
    // Sincronizar últimos 7 días
    $syncService->syncCampaignCosts($campaignId, 7);
    echo "<p style='color: green;'>✓ Costos sincronizados</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
}

echo "<h2>3. Verificando resultados...</h2>";

// Verificar ads
$ads = $db->fetchAll("SELECT id, name FROM ads WHERE campaign_id = ?", [$campaignId]);
echo "<p>Total ads encontrados: " . count($ads) . "</p>";

// Verificar costos de ads
$adCosts = $db->fetchAll("
    SELECT 
        a.name,
        fc.date,
        fc.spend,
        fc.impressions,
        fc.clicks
    FROM ads a
    JOIN facebook_costs fc ON a.id = fc.entity_id AND fc.entity_type = 'ad'
    WHERE a.campaign_id = ?
    ORDER BY fc.date DESC
    LIMIT 20
", [$campaignId]);

echo "<p>Registros de costos encontrados: " . count($adCosts) . "</p>";

if (!empty($adCosts)) {
    echo "<table border='1' style='margin-top: 20px;'>";
    echo "<tr><th>Ad</th><th>Date</th><th>Spend</th><th>Impressions</th><th>Clicks</th></tr>";
    foreach ($adCosts as $cost) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($cost['name']) . "</td>";
        echo "<td>{$cost['date']}</td>";
        echo "<td>\${$cost['spend']}</td>";
        echo "<td>{$cost['impressions']}</td>";
        echo "<td>{$cost['clicks']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<h2>4. Probando API directamente...</h2>";

// Tomar el primer ad y probar la API directamente
if (!empty($ads)) {
    $testAdId = $ads[0]['id'];
    echo "<p>Probando con ad ID: $testAdId</p>";
    
    try {
        $insights = $fbApi->getInsightsByDateRange(
            $testAdId,
            'ad',
            date('Y-m-d', strtotime('-7 days')),
            date('Y-m-d')
        );
        
        echo "<p>Respuesta de la API:</p>";
        echo "<pre>" . print_r($insights, true) . "</pre>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error al obtener insights: " . $e->getMessage() . "</p>";
    }
}

echo "<hr>";
echo "<p><a href='index.php?action=campaign_details&campaign_id=$campaignId'>Volver a detalles de campaña</a></p>";
?>