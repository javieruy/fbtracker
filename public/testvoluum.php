<?php
// testvoluum.php - Herramienta de Prueba de URLs para Voluum

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Cargar la configuración para que la clase VoluumAPI funcione
require_once '../config/config.php';

$url_to_test = $_GET['url_to_test'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Herramienta de Prueba de URLs de Voluum</title>
    <style>
        body { font-family: sans-serif; margin: 2em; }
        textarea { width: 100%; height: 150px; margin-bottom: 1em; }
        .result { border: 1px solid #ccc; padding: 1em; margin-top: 1em; background: #f9f9f9; }
        .success { border-color: green; }
        .error { border-color: red; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Herramienta de Prueba de URLs de Voluum</h1>
    <p>Pega la URL completa que quieres probar en la caja de texto y haz clic en "Probar URL".</p>
    
    <form method="GET" action="">
        <label for="url_to_test">URL a Probar:</label><br>
        <textarea id="url_to_test" name="url_to_test"><?php echo htmlspecialchars($url_to_test); ?></textarea><br>
        <button type="submit">Probar URL</button>
    </form>

    <?php if (!empty($url_to_test)): ?>
        <div class="result">
            <h3>Resultado de la Prueba:</h3>
            <?php
            try {
                // Instanciamos la API para usar su método de autenticación y petición
                $voluumApi = new VoluumAPI();
                // Llamamos directamente al método makeRequest, que ahora espera una URL completa
                $data = $voluumApi->makeRequestForTest($url_to_test);
                
                echo "<h4 style='color:green;'>¡ÉXITO! La URL funciona.</h4>";
                echo "<p>Respuesta de la API:</p>";
                echo "<pre class='success'>";
                print_r($data);
                echo "</pre>";

            } catch (Exception $e) {
                echo "<h4 style='color:red;'>FALLO. La URL no funciona.</h4>";
                echo "<p><strong>Mensaje de error:</strong></p>";
                echo "<pre class='error'>" . htmlspecialchars($e->getMessage()) . "</pre>";
            }
            ?>
        </div>
    <?php endif; ?>
</body>
</html>