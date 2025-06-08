<?php

class FacebookAPI {
    protected $accessToken;

    public function __construct() {
        $this->accessToken = FB_CONFIG['access_token'] ?? null;
    }

    public function call($endpoint, $params = []) {
        $url = "https://graph.facebook.com/v17.0/" . ltrim($endpoint, '/');
        $params['access_token'] = $this->accessToken;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode !== 200) {
            throw new Exception("Facebook API error: " . ($data['error']['message'] ?? 'Unknown error'));
        }
        return $data;
    }

    public function getAdAccounts() {
        $fields = 'id,name,account_status,currency,timezone_name,timezone_offset_hours_utc,business,id,amount_spent,balance';
        $result = $this->call('/me/adaccounts', ['fields' => $fields]);
        return $result['data'] ?? [];
    }

    public function getCampaigns($accountId) {
        return $this->call("$accountId/campaigns", ['fields' => 'id,name,status'])['data'] ?? [];
    }

    public function getAdSets($campaignId) {
        return $this->call("$campaignId/adsets", ['fields' => 'id,name,status,campaign_id'])['data'] ?? [];
    }

    public function getAds($adsetId) {
        return $this->call("$adsetId/ads", ['fields' => 'id,name,status,adset_id,campaign_id'])['data'] ?? [];
    }

    public function getInsightsByDateRange($entityId, $level, $startDate, $endDate) {
        $params = [
            'level' => $level,
            'time_range' => json_encode(['since' => $startDate, 'until' => $endDate]),
            'fields' => 'spend,impressions,clicks,date_start,date_stop'
        ];
        $response = $this->call("$entityId/insights", $params);
        return $response['data'] ?? [];
    }
}
