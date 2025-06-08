<?php
/**
 * Cliente para Facebook Marketing API
 * 
 * Maneja todas las interacciones con la API de Facebook
 * con manejo de errores, reintentos y logging
 */

class FacebookAPI {
    private $accessToken;
    private $apiVersion;
    private $baseUrl;
    private $logger;
    private $maxRetries = 3;
    private $retryDelay = 1; // segundos
    
    public function __construct() {
        $this->accessToken = FB_CONFIG['access_token'];
        $this->apiVersion = FB_CONFIG['api_version'];
        $this->baseUrl = FB_CONFIG['api_base_url'] . $this->apiVersion;
        $this->logger = Logger::getInstance();
    }
    
    /**
     * Realizar petición a la API
     */
    private function makeRequest($endpoint, array $params = [], $method = 'GET') {
        $params['access_token'] = $this->accessToken;
        
        $url = $this->baseUrl . $endpoint;
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            $startTime = microtime(true);
            
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER => ['Accept: application/json']
                ]);
                
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $duration = microtime(true) - $startTime;
                curl_close($ch);
                
                $responseData = json_decode($response, true);
                
                // Log API call
                $this->logger->logApiCall('Facebook', $endpoint, 
                    ['method' => $method, 'params' => $this->sanitizeParams($params)], 
                    ['code' => $httpCode, 'data' => $responseData], 
                    $duration
                );
                
                // Handle errors
                if ($httpCode >= 400) {
                    $error = $responseData['error'] ?? ['message' => 'Unknown error'];
                    
                    // Check if it's a rate limit error
                    if ($httpCode === 429 || 
                        (isset($error['code']) && in_array($error['code'], [4, 17, 32, 613]))) {
                        
                        $this->logger->warning('Facebook API rate limit hit', [
                            'endpoint' => $endpoint,
                            'attempt' => $attempt
                        ]);
                        
                        if ($attempt < $this->maxRetries) {
                            sleep($this->retryDelay * $attempt);
                            continue;
                        }
                    }
                    
                    throw new FacebookAPIException(
                        $error['message'] ?? 'API Error',
                        $httpCode,
                        $error
                    );
                }
                
                return $responseData;
                
            } catch (Exception $e) {
                $lastError = $e;
                $this->logger->error('Facebook API request failed', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay * $attempt);
                    continue;
                }
            }
        }
        
        throw $lastError ?: new Exception('Max retries exceeded');
    }
    
    /**
     * Sanitizar parámetros para logging (ocultar tokens)
     */
    private function sanitizeParams($params) {
        $sanitized = $params;
        if (isset($sanitized['access_token'])) {
            $sanitized['access_token'] = substr($sanitized['access_token'], 0, 10) . '...';
        }
        return $sanitized;
    }
    
    /**
     * Obtener todas las páginas de resultados
     */
    private function getAllPages($endpoint, array $params = []) {
        $allData = [];
        $params['limit'] = $params['limit'] ?? 100;
        
        do {
            $response = $this->makeRequest($endpoint, $params);
            
            if (isset($response['data'])) {
                $allData = array_merge($allData, $response['data']);
            }
            
            // Check for next page
            $nextUrl = $response['paging']['next'] ?? null;
            if ($nextUrl) {
                // Extract cursor for next request
                parse_str(parse_url($nextUrl, PHP_URL_QUERY), $nextParams);
                $params['after'] = $nextParams['after'] ?? null;
            }
            
        } while ($nextUrl && count($allData) < 10000); // Safety limit
        
        return $allData;
    }
    
    /**
     * Obtener cuentas publicitarias
     */
    public function getAdAccounts() {
        $this->logger->info('Fetching ad accounts');
        
        $fields = [
            'id',
            'name',
            'currency',
            'timezone_name',
            'timezone_offset_hours_utc',
            'account_status',
            'business_name',
            'amount_spent',
            'balance'
        ];
        
        $params = [
            'fields' => implode(',', $fields)
        ];
        
        $accounts = $this->getAllPages('/me/adaccounts', $params);
        
        $this->logger->info('Ad accounts fetched', [
            'count' => count($accounts)
        ]);
        
        return $accounts;
    }
    
    /**
     * Obtener campañas de una cuenta
     */
    public function getCampaigns($accountId, $includeDeleted = false) {
        $this->logger->info('Fetching campaigns', ['account_id' => $accountId]);
        
        $fields = [
            'id',
            'name',
            'status',
            'objective',
            'created_time',
            'updated_time',
            'daily_budget',
            'lifetime_budget'
        ];
        
        $params = [
            'fields' => implode(',', $fields),
            'limit' => 500
        ];
        
        if (!$includeDeleted) {
            $params['filtering'] = json_encode([
                ['field' => 'effective_status', 'operator' => 'IN', 
                 'value' => ['ACTIVE', 'PAUSED', 'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED']]
            ]);
        }
        
        $campaigns = $this->getAllPages("/$accountId/campaigns", $params);
        
        $this->logger->info('Campaigns fetched', [
            'account_id' => $accountId,
            'count' => count($campaigns)
        ]);
        
        return $campaigns;
    }
    
    /**
     * Obtener adsets de una campaña
     */
    public function getAdSets($campaignId) {
        $this->logger->debug('Fetching adsets', ['campaign_id' => $campaignId]);
        
        $fields = [
            'id',
            'name',
            'status',
            'campaign_id',
            'created_time',
            'updated_time'
        ];
        
        $params = [
            'fields' => implode(',', $fields),
            'limit' => 500
        ];
        
        return $this->getAllPages("/$campaignId/adsets", $params);
    }
    
    /**
     * Obtener ads de un adset
     */
    public function getAds($adsetId) {
        $this->logger->debug('Fetching ads', ['adset_id' => $adsetId]);
        
        $fields = [
            'id',
            'name',
            'status',
            'adset_id',
            'campaign_id',
            'created_time',
            'updated_time'
        ];
        
        $params = [
            'fields' => implode(',', $fields),
            'limit' => 500
        ];
        
        return $this->getAllPages("/$adsetId/ads", $params);
    }
    
    /**
     * Obtener insights (métricas) para un objeto
     */
    public function getInsights($objectId, $level, $dateRange) {
        $this->logger->debug('Fetching insights', [
            'object_id' => $objectId,
            'level' => $level,
            'date_range' => $dateRange
        ]);
        
        $params = [
            'fields' => 'spend,impressions,clicks,cpm,cpc,ctr',
            'level' => $level,
            'time_increment' => 1 // Daily breakdown
        ];
        
        // Handle date range
        if (is_array($dateRange)) {
            $params['time_range'] = json_encode([
                'since' => $dateRange['since'],
                'until' => $dateRange['until']
            ]);
        } else {
            $params['date_preset'] = $dateRange; // e.g., 'today', 'yesterday', 'last_7d'
        }
        
        $insights = $this->makeRequest("/$objectId/insights", $params);
        
        return $insights['data'] ?? [];
    }
    
    /**
     * Obtener insights por rango de fechas
     */
    public function getInsightsByDateRange($objectId, $level, $startDate, $endDate) {
        return $this->getInsights($objectId, $level, [
            'since' => $startDate,
            'until' => $endDate
        ]);
    }
    
    /**
     * Verificar salud del token
     */
    public function verifyToken() {
        try {
            $response = $this->makeRequest('/me', ['fields' => 'id,name']);
            
            $this->logger->info('Token verified', [
                'user_id' => $response['id'] ?? 'unknown',
                'name' => $response['name'] ?? 'unknown'
            ]);
            
            return [
                'valid' => true,
                'user' => $response
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Token verification failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtener permisos del token
     */
    public function getTokenPermissions() {
        try {
            $response = $this->makeRequest('/me/permissions');
            
            $permissions = [];
            foreach ($response['data'] ?? [] as $perm) {
                if ($perm['status'] === 'granted') {
                    $permissions[] = $perm['permission'];
                }
            }
            
            return $permissions;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to get token permissions', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Batch request para múltiples llamadas
     */
    public function batchRequest(array $requests) {
        $batch = [];
        
        foreach ($requests as $key => $request) {
            $batch[] = [
                'method' => $request['method'] ?? 'GET',
                'relative_url' => $request['url'],
                'body' => $request['params'] ?? null
            ];
        }
        
        $params = [
            'batch' => json_encode($batch)
        ];
        
        $responses = $this->makeRequest('/', $params, 'POST');
        
        $results = [];
        foreach ($responses as $index => $response) {
            $key = array_keys($requests)[$index];
            $results[$key] = json_decode($response['body'], true);
        }
        
        return $results;
    }
}

/**
 * Exception específica para errores de Facebook API
 */
class FacebookAPIException extends Exception {
    private $errorData;
    
    public function __construct($message, $code = 0, $errorData = []) {
        parent::__construct($message, $code);
        $this->errorData = $errorData;
    }
    
    public function getErrorData() {
        return $this->errorData;
    }
    
    public function getErrorType() {
        return $this->errorData['type'] ?? 'unknown';
    }
    
    public function getErrorCode() {
        return $this->errorData['code'] ?? 0;
    }
    
    public function isRateLimit() {
        $code = $this->getErrorCode();
        return in_array($code, [4, 17, 32, 613]);
    }
    
    public function isAuthError() {
        $code = $this->getErrorCode();
        return in_array($code, [102, 190]);
    }
}