<?php
/**
 * Cliente para la API de Voluum (Versión Final Definitiva)
 */
class VoluumAPI {
    private $accessKeyId;
    private $secretAccessKey;
    private $apiUrl;
    private $logger;
    private $sessionToken = null;
    private $tokenCacheFile;

    public function __construct() {
        $this->accessKeyId = VOLUUM_CONFIG['access_key_id'];
        $this->secretAccessKey = VOLUUM_CONFIG['secret_access_key'];
        $this->apiUrl = VOLUUM_CONFIG['api_url'];
        $this->logger = Logger::getInstance();
        $this->tokenCacheFile = APP_PATHS['cache'] . '/voluum_token.json';
    }

    private function getSessionToken() {
        if (file_exists($this->tokenCacheFile)) {
            $tokenData = json_decode(file_get_contents($this->tokenCacheFile), true);
            if (isset($tokenData['token']) && (time() < $tokenData['expires_at'])) {
                $this->sessionToken = $tokenData['token'];
                return $this->sessionToken;
            }
        }
        $this->logger->info('Requesting new Voluum session token.');
        $payload = ['accessId' => $this->accessKeyId, 'accessKey' => $this->secretAccessKey];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.voluum.com/auth/access/session',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $responseData = json_decode($response, true);
        if ($httpCode === 200 && isset($responseData['token'])) {
            $this->sessionToken = $responseData['token'];
            $cacheData = ['token' => $this->sessionToken, 'expires_at' => time() + (23 * 60 * 60)];
            file_put_contents($this->tokenCacheFile, json_encode($cacheData));
            return $this->sessionToken;
        }
        $errorDetails = json_encode($responseData);
        throw new Exception("Voluum authentication failed with code {$httpCode}: {$errorDetails}. Please double-check your API keys.");
    }

    private function makeRequest($fullUrl) {
        if ($this->sessionToken === null) {
            $this->getSessionToken();
        }
        $headers = ["Accept: application/json", "cwauth-token: {$this->sessionToken}"];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $responseData = json_decode($response, true);
        if ($httpCode >= 400) {
            $errorMessage = $responseData['message'] ?? ($responseData['error']['message'] ?? 'Unknown Error');
            throw new Exception("Voluum API request failed with code {$httpCode}: {$errorMessage}. URL Solicitada: " . $fullUrl);
        }
        return $responseData;
    }
    
    /**
     * Obtiene el reporte de un día, agrupado por Ad ID.
     * Nombre del método corregido y unificado.
     */
    public function getReportByAdForDate($voluumCampaignId, $from, $to, $currency = 'USD') {
        $this->logger->info('Fetching daily report from Voluum', ['voluum_campaign_id' => $voluumCampaignId, 'from' => $from, 'to' => $to]);

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
        $columns = ['conversions', 'revenue'];
        foreach($columns as $col) {
            $queryString .= '&column=' . urlencode($col);
        }

        $fullUrl = $this->apiUrl . '/report?' . $queryString;
        
        $report = $this->makeRequest($fullUrl);
        return $report['rows'] ?? [];
    }

	 /**
     * Método público para usar en testvoluum.php.
     * Es un alias del método privado makeRequest.
     */
    public function makeRequestForTest($fullUrl) {
        return $this->makeRequest($fullUrl);
    }
}