<?php
/**
 * Manejador global de errores para la aplicación
 * 
 * Captura todos los errores y excepciones y los formatea
 * de manera amigable para el usuario
 */

// Registrar manejadores personalizados
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');
register_shutdown_function('customShutdownHandler');

/**
 * Manejador personalizado de errores PHP
 */
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    // Ignorar errores suprimidos con @
    if (error_reporting() === 0) {
        return false;
    }
    
    $errorTypes = [
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated'
    ];
    
    $type = $errorTypes[$errno] ?? 'Unknown Error';
    
    // Log del error
    Logger::getInstance()->error("PHP $type", [
        'message' => $errstr,
        'file' => $errfile,
        'line' => $errline,
        'type' => $errno
    ]);
    
    // En desarrollo, mostrar el error
    if (DEV_MODE) {
        displayError([
            'type' => "PHP $type",
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline
        ]);
    }
    
    // Detener ejecución en errores fatales
    if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        exit(1);
    }
    
    return true;
}

/**
 * Manejador personalizado de excepciones no capturadas
 */
function customExceptionHandler($exception) {
    // Log de la excepción
    Logger::getInstance()->logException($exception);
    
    // Preparar información del error
    $error = [
        'type' => get_class($exception),
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine()
    ];
    
    // En desarrollo, incluir trace
    if (DEV_MODE) {
        $error['trace'] = $exception->getTraceAsString();
    }
    
    displayError($error);
}

/**
 * Manejador de shutdown para errores fatales
 */
function customShutdownHandler() {
    $error = error_get_last();
    
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        customErrorHandler($error['type'], $error['message'], $error['file'], $error['line']);
    }
}

/**
 * Mostrar error de manera amigable
 */
function displayError($error) {
    // Si es una petición AJAX, devolver JSON
    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        http_response_code(500);
        
        $response = [
            'success' => false,
            'error' => $error['message']
        ];
        
        if (DEV_MODE) {
            $response['debug'] = $error;
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Si no, mostrar página de error
    http_response_code(500);
    
    // En producción, mostrar error genérico
    if (!DEV_MODE) {
        include APP_PATHS['templates'] . '/error_500.php';
        exit;
    }
    
    // En desarrollo, mostrar detalles
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error - <?php echo htmlspecialchars($error['type']); ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 20px;
                background: #f5f5f5;
            }
            .error-container {
                max-width: 1000px;
                margin: 0 auto;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            .error-header {
                background: #dc3545;
                color: white;
                padding: 20px;
            }
            .error-header h1 {
                margin: 0;
                font-size: 24px;
            }
            .error-content {
                padding: 20px;
            }
            .error-message {
                font-size: 18px;
                color: #333;
                margin-bottom: 20px;
                padding: 15px;
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                border-radius: 4px;
            }
            .error-details {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 4px;
                font-family: monospace;
                font-size: 14px;
                overflow-x: auto;
            }
            .error-file {
                color: #6c757d;
                margin-bottom: 10px;
            }
            .error-trace {
                margin-top: 20px;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 4px;
                font-family: monospace;
                font-size: 12px;
                white-space: pre-wrap;
                overflow-x: auto;
            }
            .back-link {
                display: inline-block;
                margin-top: 20px;
                padding: 10px 20px;
                background: #007bff;
                color: white;
                text-decoration: none;
                border-radius: 4px;
            }
            .back-link:hover {
                background: #0056b3;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-header">
                <h1><?php echo htmlspecialchars($error['type']); ?></h1>
            </div>
            <div class="error-content">
                <div class="error-message">
                    <?php echo htmlspecialchars($error['message']); ?>
                </div>
                
                <?php if (isset($error['file']) && isset($error['line'])): ?>
                <div class="error-details">
                    <div class="error-file">
                        <strong>File:</strong> <?php echo htmlspecialchars($error['file']); ?>
                    </div>
                    <div class="error-line">
                        <strong>Line:</strong> <?php echo htmlspecialchars($error['line']); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($error['trace'])): ?>
                <div class="error-trace">
                    <strong>Stack Trace:</strong><br>
                    <?php echo htmlspecialchars($error['trace']); ?>
                </div>
                <?php endif; ?>
                
                <a href="javascript:history.back()" class="back-link">← Go Back</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Verificar si es una petición AJAX
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Helper para manejar errores en bloques try-catch
 */
function handleError($error, $userMessage = null) {
    if ($error instanceof Exception) {
        Logger::getInstance()->logException($error);
        $message = $userMessage ?: $error->getMessage();
    } else {
        Logger::getInstance()->error($error);
        $message = $userMessage ?: $error;
    }
    
    if (isAjaxRequest()) {
        jsonError($message);
    } else {
        $_SESSION['error'] = $message;
        header('Location: ' . $_SERVER['HTTP_REFERER'] ?? '/');
        exit;
    }
}

/**
 * Respuesta JSON de error
 */
function jsonError($message, $code = 500) {
    http_response_code($code);
    header('Content-Type: application/json');
    
    $response = [
        'success' => false,
        'error' => $message
    ];
    
    if (DEV_MODE && $message instanceof Exception) {
        $response['debug'] = [
            'file' => $message->getFile(),
            'line' => $message->getLine(),
            'trace' => $message->getTraceAsString()
        ];
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Respuesta JSON de éxito
 */
function jsonSuccess($data = null, $message = null) {
    header('Content-Type: application/json');
    
    $response = ['success' => true];
    
    if ($message) {
        $response['message'] = $message;
    }
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}
?>