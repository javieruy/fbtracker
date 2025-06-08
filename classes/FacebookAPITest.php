<?php
/**
 * Clase AUXILIAR solo para pruebas de la API de Facebook
 */
class FacebookAPITest {
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
    
    private function makeRequest($endpoint, array $params = [], $method = 'GET') {
        $params['access_token'] = $this->accessToken;
        $url = $this->baseUrl . $endpoint;
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $error = $responseData['error'] ?? ['message' => 'Unknown API error'];
            throw new Exception($error['message'], $httpCode);
        }
        
        return $responseData;
    }
    
    /**
     * Este es el método con la lógica corregida que queremos probar.
     * Incluye time_increment=1 para forzar el desglose diario.
     */
    public function getInsights($objectId, $level, $dateRange) {
        $params = [
            'fields' => 'spend,impressions,clicks',
            'level' => $level,
            'time_increment' => 1 // El parámetro clave para el desglose diario
        ];
        
        if (is_array($dateRange)) {
            $params['time_range'] = json_encode([
                'since' => $dateRange['since'],
                'until' => $dateRange['until']
            ]);
        } else {
            $params['date_preset'] = $dateRange;
        }
        
        $insights = $this->makeRequest("/$objectId/insights", $params);
        return $insights['data'] ?? [];
    }
    
    public function getInsightsByDateRange($objectId, $level, $startDate, $endDate) {
        return $this->getInsights($objectId, $level, [
            'since' => $startDate,
            'until' => $endDate
        ]);
    }
}