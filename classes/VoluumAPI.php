<?php
/**
 * VoluumAPI.php - Versión mejorada con auto-renovación de token
 */
class VoluumAPI {
    private $accessKeyId;
    private $secretAccessKey;
    private $apiUrl;
    private $logger;
    private $sessionToken = null;
    private $tokenCacheFile;
    private $maxRetries = 2; // Máximo 2 intentos con token refresh

    public function __construct() {
        $this->accessKeyId = VOLUUM_CONFIG['access_key_id'];
        $this->secretAccessKey = VOLUUM_CONFIG['secret_access_key'];
        $this->apiUrl = VOLUUM_CONFIG['api_url'];
        $this->logger = Logger::getInstance();
        $this->tokenCacheFile = APP_PATHS['cache'] . '/voluum_token.json';
    }

    private function getSessionToken($forceRefresh = false) {
        // Si forzamos refresh, borrar cache
        if ($forceRefresh) {
            $this->clearTokenCache();
        }
        
        // Verificar cache solo si no forzamos refresh
        if (!$forceRefresh && file_exists($this->tokenCacheFile)) {
            $tokenData = json_decode(file_get_contents($this->tokenCacheFile), true);
            if (isset($tokenData['token']) && (time() < $tokenData['expires_at'])) {
                $this->sessionToken = $tokenData['token'];
                $this->logger->debug('Using cached Voluum token');
                return $this->sessionToken;
            }
        }
        
        // Obtener nuevo token
        $this->logger->info('Requesting new Voluum token', ['force_refresh' => $forceRefresh]);
        
        $payload = ['accessId' => $this->accessKeyId, 'accessKey' => $this->secretAccessKey];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.voluum.com/auth/access/session',
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch); 
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        if ($httpCode === 200 && isset($responseData['token'])) {
            $this->sessionToken = $responseData['token'];
            
            // Guardar en cache (válido por 23 horas)
            $cacheData = [
                'token' => $this->sessionToken, 
                'expires_at' => time() + (23 * 60 * 60),
                'created_at' => time()
            ];
            file_put_contents($this->tokenCacheFile, json_encode($cacheData));
            
            $this->logger->info('New Voluum token obtained and cached');
            return $this->sessionToken;
        }
        
        $errorDetails = json_encode($responseData);
        $this->logger->error('Voluum authentication failed', [
            'http_code' => $httpCode,
            'response' => $responseData,
            'force_refresh' => $forceRefresh
        ]);
        
        throw new Exception("Voluum authentication failed with code {$httpCode}: {$errorDetails}");
    }

    /**
     * MEJORADO: makeRequest con auto-retry en 401
     */
    public function makeRequest($fullUrl, $attempt = 1) {
        if ($this->sessionToken === null) {
            $this->getSessionToken();
        }
        
        $headers = [
            "Accept: application/json", 
            "cwauth-token: {$this->sessionToken}"
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fullUrl, 
            CURLOPT_RETURNTRANSFER => true, 
            CURLOPT_HTTPGET => true, 
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch); 
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        // MEJORADO: Manejo inteligente del 401
        if ($httpCode === 401) {
            $this->logger->warning('Voluum API returned 401 - token expired or invalid', [
                'url' => $fullUrl,
                'attempt' => $attempt,
                'max_retries' => $this->maxRetries
            ]);
            
            // Si no hemos agotado los intentos, renovar token y reintentar
            if ($attempt <= $this->maxRetries) {
                $this->logger->info('Attempting to refresh Voluum token and retry', [
                    'attempt' => $attempt
                ]);
                
                // Forzar renovación del token
                $this->getSessionToken(true);
                
                // Reintentar con el nuevo token
                return $this->makeRequest($fullUrl, $attempt + 1);
            } else {
                $this->logger->error('Max retries exceeded for Voluum API request', [
                    'url' => $fullUrl,
                    'attempts' => $attempt
                ]);
            }
        }
        
        if ($httpCode >= 400) {
            $errorMessage = $responseData['message'] ?? ($responseData['error']['message'] ?? 'Unknown Error');
            
            $this->logger->error('Voluum API request failed', [
                'url' => $fullUrl,
                'http_code' => $httpCode,
                'response' => $responseData,
                'attempt' => $attempt
            ]);
            
            throw new Exception("Voluum API request failed with code {$httpCode}: {$errorMessage}");
        }
        
        // Log successful request si fue retry
        if ($attempt > 1) {
            $this->logger->info('Voluum API request succeeded after retry', [
                'url' => $fullUrl,
                'successful_attempt' => $attempt
            ]);
        }
        
        return $responseData;
    }
    
    /**
     * NUEVO: Limpiar cache de token manualmente
     */
    public function clearTokenCache() {
        if (file_exists($this->tokenCacheFile)) {
            unlink($this->tokenCacheFile);
            $this->logger->info('Voluum token cache cleared');
        }
        $this->sessionToken = null;
    }
    
    /**
     * NUEVO: Verificar estado del token
     */
    public function getTokenStatus() {
        if (!file_exists($this->tokenCacheFile)) {
            return ['status' => 'no_cache', 'message' => 'No token cache found'];
        }
        
        $tokenData = json_decode(file_get_contents($this->tokenCacheFile), true);
        
        if (!isset($tokenData['token']) || !isset($tokenData['expires_at'])) {
            return ['status' => 'invalid_cache', 'message' => 'Invalid cache format'];
        }
        
        $now = time();
        $expiresAt = $tokenData['expires_at'];
        $timeLeft = $expiresAt - $now;
        
        if ($timeLeft <= 0) {
            return [
                'status' => 'expired', 
                'message' => 'Token expired',
                'expired_since' => abs($timeLeft) . ' seconds ago'
            ];
        }
        
        return [
            'status' => 'valid',
            'message' => 'Token is valid',
            'expires_in' => $timeLeft . ' seconds',
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'created_at' => isset($tokenData['created_at']) ? date('Y-m-d H:i:s', $tokenData['created_at']) : 'Unknown'
        ];
    }
    
    // Resto de métodos igual...
    public function getReportByAdForDate($voluumCampaignId, $from, $to, $currency = 'USD') {
        $this->logger->info('Fetching daily report from Voluum', [
            'voluum_campaign_id' => $voluumCampaignId, 
            'from' => $from, 
            'to' => $to
        ]);

        $params = [
            'from' => $from,
            'to' => $to,
            'tz' => 'Etc/GMT',
            'currency' => $currency,
            'groupBy' => 'custom-variable-1',
            'filter1' => 'campaign',
            'filter1Value' => $voluumCampaignId,
            'reportType' => 'table',
            'conversionTimeMode' => 'VISIT',
            'include' => 'ACTIVE',
            'limit' => 10000
        ];
        
        $queryString = http_build_query($params);
        
        $columns = ['conversions', 'revenue', 'customConversions7'];
        foreach($columns as $col) {
            $queryString .= '&column=' . urlencode($col);
        }

        $fullUrl = $this->apiUrl . '/report?' . $queryString;
        
        $report = $this->makeRequest($fullUrl);
        return $report['rows'] ?? [];
    }
}
?>