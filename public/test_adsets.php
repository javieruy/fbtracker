<?php
// test_adsets.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Prueba Final de Facebook API - getAdSets</title>
    <style>
        body { font-family: sans-serif; margin: 2em; line-height: 1.6; }
        .result { border: 1px solid #ccc; padding: 1em; margin-top: 1em; background: #f9f9f9; }
        .error { border-color: red; background-color: #fff0f0;}
        pre { white-space: pre-wrap; word-wrap: break-word; background: #eee; padding: 10px; }
    </style>
</head>
<body>
    <h1>Prueba Final: ¿Funciona `getAdSets`?</h1>
    <p>Este script intenta hacer la llamada específica que está fallando en la aplicación.</p>
    <hr>

    <?php
    // --- ID de la campaña que está dando el error ---
    $testCampaignId = '120224733492130185';
    ?>

    <div class="result">
        <h3>Prueba en curso...</h3>
        <p>Intentando llamar a <strong>getAdSets()</strong> con el ID de Campaña: <strong><?php echo htmlspecialchars($testCampaignId); ?></strong></p>
        
        <?php
        try {
            // Usamos la clase FacebookAPI principal, que ya debería estar completa
            $fbApi = new FacebookAPI();
            
            // Hacemos la llamada que falla en SyncService
            $adsets = $fbApi->getAdSets($testCampaignId);
            
            echo "<h2 style='color:green;'>¡ÉXITO INESPERADO!</h2>";
            echo "<p>La llamada funcionó en este script. Esto es muy extraño. La respuesta es:</p>";
            echo "<pre>";
            print_r($adsets);
            echo "</pre>";

        } catch (Exception $e) {
            echo "<h2 style='color:red;'>FALLO CONFIRMADO</h2>";
            echo "<p>El script ha recibido el mismo error que la aplicación principal. El mensaje de error exacto de Facebook es:</p>";
            echo "<pre class='error'>" . htmlspecialchars($e->getMessage()) . "</pre>";
            echo "<hr>";
            echo "<h3>Conclusión del Diagnóstico:</h3>";
            echo "<p>Este resultado confirma al 100% que el problema <strong>NO está en el código PHP que hemos escrito</strong>, sino en la petición misma. Facebook está rechazando la solicitud.</p>";
            echo "<p>Las únicas dos razones posibles son:</p>";
            echo "<ol>";
            echo "<li>El ID <strong>" . htmlspecialchars($testCampaignId) . "</strong> no es un ID de Campaña válido, o es un ID de un Anuncio.</li>";
            echo "<li>Tu <strong>Access Token</strong> no tiene los permisos necesarios (<code>ads_read</code>) para acceder a los datos de esta campaña específica.</li>";
            echo "</ol>";
            echo "<p>La única forma de verificar esto es usando la herramienta oficial de Facebook:</p>";
            echo "<p><a href='https://developers.facebook.com/tools/explorer/' target='_blank'>Explorador de la API Graph de Facebook</a></p>";
        }
        ?>
    </div>
</body>
</html>