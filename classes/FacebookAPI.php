<?php
/**
 * Cliente para Facebook Marketing API (Versión corregida)
 */
class FacebookAPI {
    private $accessToken;
    private $apiVersion;
    private $baseUrl;
    private $logger;
    
    public function __construct() {
        $this->accessToken = FB_CONFIG['access_token'];
        $this->apiVersion = FB_CONFIG['api_version'];
        $this->baseUrl = FB_CONFIG['api_base_url'] . $this->apiVersion;
        $this->logger = Logger::getInstance();
    }
    
    private function makeRequest($url, array $params = []) {
        // Si la URL no es completa, la construimos.
        if (strpos($url, 'https://') !== 0) {
            $url = $this->baseUrl . $url;
        }

        // Añadir el token a los parámetros
        $params['access_token'] = $this->accessToken;

        // Construir la URL final con todos los parámetros
        $url .= '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $error = $responseData['error'] ?? ['message' => 'Unknown API error'];
            throw new FacebookAPIException($error['message'], $httpCode, $error);
        }
        
        return $responseData;
    }
    
    private function getAllPages($endpoint, array $params = []) {
        $allData = [];
        
        try {
            // Primera llamada
            $response = $this->makeRequest($endpoint, $params);
            if (!empty($response['data'])) {
                $allData = array_merge($allData, $response['data']);
            }
            
            // Bucle para las páginas siguientes
            while (isset($response['paging']['next'])) {
                $nextUrl = $response['paging']['next'];
                // La URL de 'next' es completa, así que la pasamos directamente
                $response = $this->makeRequest($nextUrl);
                if (!empty($response['data'])) {
                    $allData = array_merge($allData, $response['data']);
                } else {
                    // Salir del bucle si no hay más datos
                    break;
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to get all pages from Facebook', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            // Devolver los datos que se hayan podido obtener antes del error
            return $allData;
        }
        
        return $allData;
    }
    
    public function getAdAccounts() {
        $fields = ['id', 'name', 'currency', 'timezone_name', 'timezone_offset_hours_utc', 'account_status', 'business_name', 'amount_spent', 'balance'];
        return $this->getAllPages('/me/adaccounts', ['fields' => implode(',', $fields)]);
    }
    
    public function getCampaigns($accountId, $includeDeleted = false) {
        $fields = ['id', 'name', 'status', 'objective', 'created_time', 'updated_time'];
        $params = ['fields' => implode(',', $fields), 'limit' => 100];
        if (!$includeDeleted) {
            $params['filtering'] = json_encode([['field' => 'effective_status', 'operator' => 'IN', 'value' => ['ACTIVE', 'PAUSED']]]);
        }
        return $this->getAllPages("/act_{$accountId}/campaigns", $params);
    }
    
    public function getAdSets($campaignId) {
        $fields = ['id', 'name', 'status', 'campaign_id'];
        return $this->getAllPages("/{$campaignId}/adsets", ['fields' => implode(',', $fields), 'limit' => 100]);
    }
    
    public function getAds($adsetId) {
        $fields = ['id', 'name', 'status', 'adset_id', 'campaign_id'];
        return $this->getAllPages("/{$adsetId}/ads", ['fields' => implode(',', $fields), 'limit' => 100]);
    }
    
    public function getInsights($objectId, $level, $dateRange) {
        $params = [
            'fields' => 'spend,impressions,clicks',
            'level' => $level,
            'time_increment' => 1
        ];
        
        if (is_array($dateRange)) {
            $params['time_range'] = json_encode([
                'since' => $dateRange['since'],
                'until' => $dateRange['until']
            ]);
        } else {
            $params['date_preset'] = $dateRange;
        }
        
        $response = $this->makeRequest("/{$objectId}/insights", $params);
        return $response['data'] ?? [];
    }

    public function getInsightsByDateRange($objectId, $level, $startDate, $endDate) {
        return $this->getInsights($objectId, $level, [
            'since' => $startDate,
            'until' => $endDate
        ]);
    }
    
    /**
     * NUEVO: Obtener información detallada de una campaña incluyendo fecha de creación
     */
    public function getCampaignDetails($campaignId) {
        $fields = ['id', 'name', 'status', 'objective', 'created_time', 'updated_time', 'start_time', 'stop_time'];
        $response = $this->makeRequest("/{$campaignId}", ['fields' => implode(',', $fields)]);
        return $response;
    }
}

if (!class_exists('FacebookAPIException')) {
    class FacebookAPIException extends Exception {
        private $errorData;
        
        public function __construct($message, $code = 0, $errorData = []) {
            parent::__construct($message, $code);
            $this->errorData = $errorData;
        }
        
        public function getErrorData() {
            return $this->errorData;
        }
    }
}
?>