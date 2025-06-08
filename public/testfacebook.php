<?php
// test_proceso_completo.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Cargamos la configuración y la NUEVA clase de prueba
require_once '../config/config.php';
require_once APP_PATHS['classes'] . '/FacebookAPITest.php'; // <-- Usamos la nueva clase

echo "<h1>Prueba Completa de Sincronización de Costos de FB (Modo Seguro)</h1>";
echo "<p>Este script utiliza una clase auxiliar <strong>FacebookAPITest.php</strong> y no modifica tus archivos principales.</p><hr>";

// --- Parámetros de la Prueba ---
$adId = '120224734772330185';
$daysBack = 30;
// --------------------------------

$startDate = date('Y-m-d', strtotime('-' . ($daysBack - 1) . ' days'));
$endDate = date('Y-m-d');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Prueba Completa de Sincronización de Costos de FB</title>
    <style>
        body { font-family: sans-serif; margin: 2em; line-height: 1.6; }
        .step { border: 1px solid #ccc; padding: 1em; margin-top: 1em; background: #f9f9f9; }
        h1, h2, h3 { border-bottom: 1px solid #eee; padding-bottom: 5px; }
        pre { white-space: pre-wrap; word-wrap: break-word; background: #eee; padding: 10px; }
    </style>
</head>
<body>
    <h1>Prueba Completa de Sincronización de Costos de FB (Modo Seguro)</h1>
    <p>Este script simula el proceso completo para el Ad ID: <strong><?php echo htmlspecialchars($adId); ?></strong> para los últimos <strong><?php echo $daysBack; ?></strong> días.</p>

    <div class="step">
        <h2>Paso A: Obtener Datos Crudos de Facebook</h2>
        <?php
        $fbApi = new FacebookAPITest(); // <-- Usamos la nueva clase
        $insightsData = [];
        try {
            $insightsData = $fbApi->getInsightsByDateRange($adId, 'ad', $startDate, $endDate);
            echo "<p style='color:green;'><b>Éxito:</b> Se obtuvieron " . count($insightsData) . " registros diarios de la API de Facebook.</p>";
            echo "<pre>";
            print_r($insightsData);
            echo "</pre>";
        } catch (Exception $e) {
            echo "<p style='color:red;'><b>Error:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>

    <div class="step">
        <h2>Paso B: Simular Guardado en la Base de Datos</h2>
        <?php
        if (!empty($insightsData)) {
            $db = Database::getInstance();
            $recordsSaved = 0;
            echo "<p>Guardando/Actualizando " . count($insightsData) . " registros en la tabla <code>facebook_costs</code>...</p>";
            foreach ($insightsData as $insight) {
                try {
                    $spend = floatval($insight['spend'] ?? 0);
                    $impressions = intval($insight['impressions'] ?? 0);
                    $clicks = intval($insight['clicks'] ?? 0);
                    $date = $insight['date_start'];
                    
                    $db->callProcedure('upsert_facebook_cost', [$adId, 'ad', $spend, $impressions, $clicks, $date]);
                    $recordsSaved++;
                } catch (Exception $e) {
                    echo "<p style='color:red;'><b>Error al guardar el registro para la fecha {$date}:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
                }
            }
            echo "<p style='color:green;'><b>Éxito:</b> Se procesaron y guardaron {$recordsSaved} registros en la base de datos.</p>";
        } else {
            echo "<p>No hay datos para guardar.</p>";
        }
        ?>
    </div>

    <div class="step">
        <h2>Paso C: Simular Lectura y Suma de `index.php`</h2>
        <?php
        if (!empty($insightsData)) {
            $start_date_filter = date('Y-m-d', strtotime('-29 days'));
            $end_date_filter = date('Y-m-d');
            $fbDateCondition = "fc.date >= '$start_date_filter' AND fc.date <= '$end_date_filter'";
            
            $sql = "
                SELECT 
                    entity_id, 
                    SUM(spend) as total_spend_calculated,
                    COUNT(*) as total_records_summed
                FROM facebook_costs fc
                WHERE 
                    entity_type = 'ad' AND 
                    entity_id = ? AND
                    $fbDateCondition
                GROUP BY entity_id
            ";

            try {
                $calculatedData = $db->fetchOne($sql, [$adId]);
                echo "<p>Ejecutando la consulta de suma que usa el dashboard...</p>";
                echo "<p style='color:green;'><b>Éxito:</b> La consulta se ejecutó.</p>";
                echo "<h3>Resultado Calculado:</h3>";
                echo "<pre>";
                print_r($calculatedData);
                echo "</pre>";

                $manualSum = 0;
                foreach($insightsData as $insight) {
                    $manualSum += floatval($insight['spend'] ?? 0);
                }
                echo "<h3>Comparación:</h3>";
                echo "<ul>";
                echo "<li><b>Suma Manual del Paso A (Datos de API):</b> " . $manualSum . "</li>";
                echo "<li><b>Suma de la Base de Datos del Paso C:</b> " . ($calculatedData['total_spend_calculated'] ?? 0) . "</li>";
                echo "</ul>";

            } catch (Exception $e) {
                echo "<p style='color:red;'><b>Error al ejecutar la consulta de suma:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            echo "<p>No hay datos para calcular.</p>";
        }
        ?>
    </div>
</body>
</html>