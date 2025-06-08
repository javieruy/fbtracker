<?php
/**
 * Script de diagnóstico para verificar datos de campaña
 */

require_once '../config/config.php';
require_once '../includes/error_handler.php';

$db = Database::getInstance();
$campaignId = $_GET['campaign_id'] ?? '120224733492130185';

echo "<h1>Diagnóstico de Campaña: $campaignId</h1>";

echo "<style>
    table { border-collapse: collapse; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #f5f5f5; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
</style>";

// 1. Verificar que la campaña existe
echo "<h2>1. Información de la Campaña</h2>";
$campaign = $db->fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
if ($campaign) {
    echo "<p class='success'>✓ Campaña encontrada</p>";
    echo "<table>";
    foreach ($campaign as $key => $value) {
        echo "<tr><th>$key</th><td>$value</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ Campaña no encontrada</p>";
    exit;
}

// 2. Verificar AdSets
echo "<h2>2. AdSets de la Campaña</h2>";
$adsets = $db->fetchAll("SELECT * FROM adsets WHERE campaign_id = ?", [$campaignId]);
echo "<p>Total AdSets: " . count($adsets) . "</p>";
if (!empty($adsets)) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Name</th><th>Status</th></tr>";
    foreach ($adsets as $adset) {
        echo "<tr><td>{$adset['id']}</td><td>{$adset['name']}</td><td>{$adset['status']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>⚠ No se encontraron AdSets</p>";
}

// 3. Verificar Ads
echo "<h2>3. Ads de la Campaña</h2>";
$ads = $db->fetchAll("SELECT * FROM ads WHERE campaign_id = ?", [$campaignId]);
echo "<p>Total Ads: " . count($ads) . "</p>";
if (!empty($ads)) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Name</th><th>Status</th><th>AdSet ID</th></tr>";
    foreach ($ads as $ad) {
        echo "<tr><td>{$ad['id']}</td><td>{$ad['name']}</td><td>{$ad['status']}</td><td>{$ad['adset_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>⚠ No se encontraron Ads</p>";
}

// 4. Verificar datos de costos para la campaña
echo "<h2>4. Datos de Costos - Nivel Campaña</h2>";
$campaignCosts = $db->fetchAll("
    SELECT * FROM facebook_costs 
    WHERE entity_id = ? AND entity_type = 'campaign' 
    ORDER BY date DESC 
    LIMIT 10
", [$campaignId]);

echo "<p>Registros encontrados: " . count($campaignCosts) . "</p>";
if (!empty($campaignCosts)) {
    echo "<table>";
    echo "<tr><th>Date</th><th>Spend</th><th>Impressions</th><th>Clicks</th><th>CPM</th><th>CPC</th></tr>";
    foreach ($campaignCosts as $cost) {
        echo "<tr>";
        echo "<td>{$cost['date']}</td>";
        echo "<td>\${$cost['spend']}</td>";
        echo "<td>{$cost['impressions']}</td>";
        echo "<td>{$cost['clicks']}</td>";
        echo "<td>\${$cost['cpm']}</td>";
        echo "<td>\${$cost['cpc']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No hay datos de costos para la campaña</p>";
}

// 5. Verificar datos de costos para AdSets
echo "<h2>5. Datos de Costos - Nivel AdSet</h2>";
$adsetCosts = $db->fetchAll("
    SELECT 
        fc.*,
        ads.name as adset_name
    FROM facebook_costs fc
    JOIN adsets ads ON fc.entity_id = ads.id
    WHERE ads.campaign_id = ? AND fc.entity_type = 'adset'
    ORDER BY fc.date DESC
    LIMIT 10
", [$campaignId]);

echo "<p>Registros encontrados: " . count($adsetCosts) . "</p>";
if (!empty($adsetCosts)) {
    echo "<table>";
    echo "<tr><th>AdSet</th><th>Date</th><th>Spend</th><th>Impressions</th><th>Clicks</th></tr>";
    foreach ($adsetCosts as $cost) {
        echo "<tr>";
        echo "<td>{$cost['adset_name']}</td>";
        echo "<td>{$cost['date']}</td>";
        echo "<td>\${$cost['spend']}</td>";
        echo "<td>{$cost['impressions']}</td>";
        echo "<td>{$cost['clicks']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No hay datos de costos para los AdSets</p>";
}

// 6. Verificar datos de costos para Ads
echo "<h2>6. Datos de Costos - Nivel Ad</h2>";
$adCosts = $db->fetchAll("
    SELECT 
        fc.*,
        a.name as ad_name
    FROM facebook_costs fc
    JOIN ads a ON fc.entity_id = a.id
    WHERE a.campaign_id = ? AND fc.entity_type = 'ad'
    ORDER BY fc.date DESC
    LIMIT 20
", [$campaignId]);

echo "<p>Registros encontrados: " . count($adCosts) . "</p>";
if (!empty($adCosts)) {
    echo "<table>";
    echo "<tr><th>Ad</th><th>Date</th><th>Spend</th><th>Impressions</th><th>Clicks</th><th>CPM</th><th>CPC</th></tr>";
    foreach ($adCosts as $cost) {
        echo "<tr>";
        echo "<td>" . substr($cost['ad_name'], 0, 50) . "...</td>";
        echo "<td>{$cost['date']}</td>";
        echo "<td>\${$cost['spend']}</td>";
        echo "<td>{$cost['impressions']}</td>";
        echo "<td>{$cost['clicks']}</td>";
        echo "<td>" . ($cost['cpm'] ? "\${$cost['cpm']}" : '-') . "</td>";
        echo "<td>" . ($cost['cpc'] ? "\${$cost['cpc']}" : '-') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No hay datos de costos para los Ads</p>";
}

// 7. Verificar sincronización
echo "<h2>7. Última Sincronización</h2>";
$lastSync = $db->fetchOne("
    SELECT * FROM sync_logs 
    WHERE sync_type = 'facebook' AND status = 'completed'
    ORDER BY completed_at DESC 
    LIMIT 1
");

if ($lastSync) {
    echo "<p>Última sincronización: {$lastSync['completed_at']}</p>";
    echo "<p>Registros procesados: {$lastSync['records_processed']}</p>";
} else {
    echo "<p class='warning'>⚠ No se encontraron sincronizaciones completadas</p>";
}

// 8. Resumen y Soluciones
echo "<h2>8. Resumen y Soluciones</h2>";

$hasAds = count($ads) > 0;
$hasAdCosts = count($adCosts) > 0;
$hasCampaignCosts = count($campaignCosts) > 0;

if (!$hasAds) {
    echo "<p class='error'>❌ <strong>Problema:</strong> No hay ads en la base de datos</p>";
    echo "<p><strong>Solución:</strong> Necesitas sincronizar la estructura completa de la campaña.</p>";
} elseif (!$hasAdCosts && !$hasCampaignCosts) {
    echo "<p class='error'>❌ <strong>Problema:</strong> No hay datos de costos en ningún nivel</p>";
    echo "<p><strong>Solución:</strong> La sincronización no está obteniendo los costos. Verifica que:</p>";
    echo "<ul>";
    echo "<li>La campaña tenga actividad (gastos) en Facebook</li>";
    echo "<li>El token tenga permisos de 'read_insights'</li>";
    echo "<li>La sincronización se esté ejecutando correctamente</li>";
    echo "</ul>";
} elseif ($hasCampaignCosts && !$hasAdCosts) {
    echo "<p class='warning'>⚠ <strong>Problema:</strong> Hay datos a nivel campaña pero no a nivel ad</p>";
    echo "<p><strong>Solución:</strong> La sincronización no está bajando hasta el nivel de ads. Necesitas una sincronización completa.</p>";
} else {
    echo "<p class='success'>✓ Los datos parecen estar correctos</p>";
}

echo "<h3>Acciones Recomendadas:</h3>";
echo "<ol>";
echo "<li><strong>Re-sincronizar la campaña completa:</strong><br>";
echo "Vuelve al dashboard y haz click en 'Sync Campaigns' para esta cuenta.</li>";
echo "<li><strong>Verificar en Facebook:</strong><br>";
echo "Asegúrate de que la campaña tenga gastos/actividad en el periodo que estás consultando.</li>";
echo "<li><strong>Revisar el código de sincronización:</strong><br>";
echo "El servicio de sincronización debe obtener datos para todos los niveles (campaign, adset, ad).</li>";
echo "</ol>";

// Mostrar query de prueba
echo "<h3>Query de diagnóstico para copiar:</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>";
echo "-- Verificar datos de ads para hoy
SELECT 
    a.name as ad_name,
    fc.*
FROM ads a
LEFT JOIN facebook_costs fc ON a.id = fc.entity_id 
    AND fc.entity_type = 'ad' 
    AND fc.date = CURDATE()
WHERE a.campaign_id = '$campaignId'
LIMIT 10;";
echo "</pre>";
?>
