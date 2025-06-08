<?php
/**
 * Servicio de sincronización (Versión Final con llamada a Voluum corregida)
 */
class SyncService {
    private $db;
    private $fbApi;
    private $logger;
    private $syncLogId;
    private $stats = [
        'accounts_synced' => 0,
        'campaigns_synced' => 0,
        'adsets_synced' => 0,
        'ads_synced' => 0,
        'costs_synced' => 0,
        'errors' => 0
    ];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->fbApi = new FacebookAPI();
        $this->logger = Logger::getInstance();
    }
    
    public function syncAllAccounts() {
        $this->startSyncLog('facebook');
        try {
            $accounts = $this->fbApi->getAdAccounts();
            foreach ($accounts as $account) { $this->syncAccount($account); }
            $this->completeSyncLog('completed');
            return ['success' => true, 'stats' => $this->stats];
        } catch (Exception $e) {
            $this->logger->error('Sync all accounts failed', ['error' => $e->getMessage()]);
            $this->completeSyncLog('failed', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(),'stats' => $this->stats];
        }
    }
    
    public function syncAccount($accountData) {
        try {
            $accountId = $accountData['id'];
            $this->db->callProcedure('upsert_ad_account', [ $accountId, $accountData['name'] ?? 'Unknown', $accountData['currency'] ?? 'USD', $accountData['timezone_name'] ?? 'UTC', $accountData['timezone_offset_hours_utc'] ?? 0, $accountData['account_status'] ?? 0, $accountData['business_name'] ?? null, $accountData['business_id'] ?? null, floatval($accountData['amount_spent'] ?? 0), floatval($accountData['balance'] ?? 0) ]);
            $this->stats['accounts_synced']++;
        } catch (Exception $e) {
            $this->stats['errors']++; throw $e;
        }
    }
    
    public function syncAccountCampaigns($accountId, $syncCosts = true, $daysBack = 30) {
        $this->startSyncLog('facebook');
        try {
            $campaigns = $this->fbApi->getCampaigns($accountId);
            foreach ($campaigns as $campaign) {
                $this->syncCampaign($campaign, $accountId);
                if ($syncCosts) {
                    $this->syncCampaignStructure($campaign['id']);
                    $this->syncCampaignCosts($campaign['id'], $daysBack);
                }
            }
            $this->completeSyncLog('completed');
            return ['success' => true, 'stats' => $this->stats];
        } catch (Exception $e) {
            $this->logger->error('Sync account campaigns failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);
            $this->completeSyncLog('failed', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(),'stats' => $this->stats];
        }
    }
    
    private function syncCampaign($campaignData, $accountId) {
        try {
            $sql = "INSERT INTO campaigns (id, account_id, name, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), name = VALUES(name), status = VALUES(status), updated_at = CURRENT_TIMESTAMP";
            $this->db->query($sql, [$campaignData['id'], $accountId, $campaignData['name'], $campaignData['status']]);
        } catch (Exception $e) { $this->stats['errors']++; throw $e; }
    }
    
    public function syncCampaignStructure($campaignId) {
        try {
            $adsets = $this->fbApi->getAdSets($campaignId);
            foreach ($adsets as $adset) {
                $this->syncAdSet($adset);
                $ads = $this->fbApi->getAds($adset['id']);
                foreach ($ads as $ad) { $this->syncAd($ad); }
            }
        } catch (Exception $e) { throw $e; }
    }
    
    private function syncAdSet($adsetData) {
        try {
            $sql = "INSERT INTO adsets (id, campaign_id, name, status) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), updated_at = CURRENT_TIMESTAMP";
            $this->db->query($sql, [$adsetData['id'], $adsetData['campaign_id'], $adsetData['name'], $adsetData['status']]);
        } catch (Exception $e) { $this->stats['errors']++; throw $e; }
    }
    
    private function syncAd($adData) {
        try {
            $sql = "INSERT INTO ads (id, adset_id, campaign_id, name, status) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status), updated_at = CURRENT_TIMESTAMP";
            $this->db->query($sql, [$adData['id'], $adData['adset_id'], $adData['campaign_id'], $adData['name'], $adData['status']]);
        } catch (Exception $e) { $this->stats['errors']++; throw $e; }
    }
    
    /**
 * Sincronizar costos de campaña - VERSIÓN ACTUALIZADA
 * Reemplazar este método completo por esta nueva versión
 */
public function syncCampaignCosts($campaignId, $daysBack = 30) {
    try {
        $this->logger->info('Starting campaign costs sync', [
            'campaign_id' => $campaignId,
            'days_back' => $daysBack
        ]);
        
        // Si son más de 30 días, usar chunks para evitar timeouts
        if ($daysBack > 30) {
            $this->logger->info('Using chunked sync for large date range', [
                'days_back' => $daysBack
            ]);
            return $this->syncCampaignCostsWithChunks($campaignId, $daysBack, 7);
        }
        
        // Para 30 días o menos, usar método directo
        $endDate = date('Y-m-d'); 
        $startDate = date('Y-m-d', strtotime("-$daysBack days"));
        
        $this->logger->info('Using direct sync for standard date range', [
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        return $this->syncCampaignCostsDateRange($campaignId, $startDate, $endDate);
        
    } catch (Exception $e) { 
        $this->logger->error('Campaign costs sync failed', [
            'campaign_id' => $campaignId,
            'days_back' => $daysBack,
            'error' => $e->getMessage()
        ]);
        throw $e; 
    }
}
    
    private function saveCostData($entityId, $entityType, $insightData) {
        try {
            $spend = floatval($insightData['spend'] ?? 0); $impressions = intval($insightData['impressions'] ?? 0);
            $clicks = intval($insightData['clicks'] ?? 0); $date = $insightData['date_start'] ?? date('Y-m-d');
            if ($spend > 0 || $impressions > 0 || $clicks > 0) {
                $this->db->callProcedure('upsert_facebook_cost', [$entityId, $entityType, $spend, $impressions, $clicks, $date]);
                $this->stats['costs_synced']++;
            }
        } catch (Exception $e) {
            $this->stats['errors']++; $this->logger->error('Failed to save cost data', ['entity_id' => $entityId, 'entity_type' => $entityType, 'error' => $e->getMessage()]);
        }
    }
    
    private function startSyncLog($type) {
        $this->syncLogId = $this->db->insert('sync_logs', ['sync_type' => $type, 'sync_date' => date('Y-m-d H:i:s'), 'status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);
    }
    
    private function completeSyncLog($status, $errorMessage = null) {
        if (!$this->syncLogId) return;
        $totalProcessed = array_sum($this->stats);
        $this->db->update('sync_logs', ['records_processed' => $totalProcessed, 'records_created' => $totalProcessed - $this->stats['errors'], 'records_updated' => 0, 'status' => $status, 'error_message' => $errorMessage, 'completed_at' => date('Y-m-d H:i:s')], ['id' => $this->syncLogId]);
    }
    
    public function getLastSyncStats() {
        $lastSync = $this->db->getLastSync('facebook'); if (!$lastSync) { return null; }
        $duration = strtotime($lastSync['completed_at']) - strtotime($lastSync['started_at']);
        return ['date' => $lastSync['sync_date'], 'duration_seconds' => $duration, 'records_processed' => $lastSync['records_processed'], 'status' => $lastSync['status'], 'error' => $lastSync['error_message']];
    }
    
    // En classes/SyncService.php

    public function syncVoluumDataForCampaign($campaignId, $daysBack = 30) {
        $campaignData = $this->db->fetchOne("SELECT voluum_campaign_id, aa.currency FROM campaigns c JOIN ad_accounts aa ON c.account_id = aa.id WHERE c.id = ?", [$campaignId]);
        if (empty($campaignData) || empty($campaignData['voluum_campaign_id'])) {
            return ['success' => true, 'message' => 'Esta campaña no está mapeada a Voluum.'];
        }
        $voluumCampaignId = $campaignData['voluum_campaign_id'];
        $currency = $campaignData['currency'] ?? 'USD';
        
        $voluumApi = new VoluumAPI();
        
        try {
            $this->logger->info('Starting Voluum sync for campaign', ['fb_campaign_id' => $campaignId, 'days_back' => $daysBack]);
            
            $startDate = new DateTime("-$daysBack days", new DateTimeZone('UTC'));
            $endDate = new DateTime('now', new DateTimeZone('UTC'));
            $datePeriod = new DatePeriod($startDate, new DateInterval('P1D'), (clone $endDate)->modify('+1 day'));

            $totalSynced = 0;
            foreach ($datePeriod as $dateObject) {
                $currentDateForDb = $dateObject->format('Y-m-d');
                $from = $dateObject->format('Y-m-d\T00:00:00.000\Z');
                $to = (clone $dateObject)->modify('+1 day')->format('Y-m-d\T00:00:00.000\Z');
                
                $dailyReport = $voluumApi->getReportByAdForDate($voluumCampaignId, $from, $to, $currency);

                if(!empty($dailyReport)) {
                    // Ya no necesitamos la línea DELETE, la consulta UPSERT se encarga de todo.
                    
                    foreach ($dailyReport as $row) {
                        if (isset($row['customVariable1']) && !empty($row['customVariable1'])) {
                            $adId = $row['customVariable1'];
                            $conversions = $row['conversions'] ?? 0;
                            $revenue = $row['revenue'] ?? 0.0;
                            $checkouts = $row['customConversions7'] ?? 0;

                            if ($conversions > 0 || $revenue > 0 || $checkouts > 0) {
                                // --- CONSULTA SQL CORREGIDA ---
                                $sql = "INSERT INTO voluum_conversions (ad_id, `date`, conversions, revenue, checkouts) VALUES (?, ?, ?, ?, ?)
                                        ON DUPLICATE KEY UPDATE 
                                            conversions = VALUES(conversions), 
                                            revenue = VALUES(revenue), 
                                            checkouts = VALUES(checkouts)";
                                            
                                $this->db->query($sql, [$adId, $currentDateForDb, $conversions, $revenue, $checkouts]);
                                $totalSynced++;
                            }
                        }
                    }
                }
                usleep(200000); 
            }
            
            return ['success' => true, 'message' => "Synced $totalSynced total ad-day records from Voluum."];

        } catch (Exception $e) {
            $this->logger->error('Voluum sync failed', ['voluum_campaign_id' => $voluumCampaignId, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

/**
 * Sincronizar costos de campaña por chunks para evitar timeouts
 */
public function syncCampaignCostsWithChunks($campaignId, $daysBack = 30, $chunkSizeDays = 7) {
    try {
        $this->logger->info('Starting chunked campaign costs sync', [
            'campaign_id' => $campaignId,
            'days_back' => $daysBack,
            'chunk_size' => $chunkSizeDays
        ]);
        
        $totalSynced = 0;
        $chunks = ceil($daysBack / $chunkSizeDays);
        
        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $chunkStart = $chunk * $chunkSizeDays;
            $chunkEnd = min(($chunk + 1) * $chunkSizeDays - 1, $daysBack - 1);
            
            $startDate = date('Y-m-d', strtotime("-{$chunkEnd} days"));
            $endDate = date('Y-m-d', strtotime("-{$chunkStart} days"));
            
            $this->logger->debug('Processing chunk', [
                'chunk' => $chunk + 1,
                'total_chunks' => $chunks,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            
            // Sincronizar este chunk
            $chunkSynced = $this->syncCampaignCostsDateRange($campaignId, $startDate, $endDate);
            $totalSynced += $chunkSynced;
            
            // Pequeña pausa entre chunks para no sobrecargar la API
            if ($chunk < $chunks - 1) {
                sleep(2);
            }
        }
        
        $this->logger->info('Chunked sync completed', [
            'campaign_id' => $campaignId,
            'total_records_synced' => $totalSynced,
            'chunks_processed' => $chunks
        ]);
        
        return $totalSynced;
        
    } catch (Exception $e) {
        $this->logger->error('Chunked sync failed', [
            'campaign_id' => $campaignId,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Sincronizar costos para un rango de fechas específico
 */
public function syncCampaignCostsDateRange($campaignId, $startDate, $endDate) {
    try {
        $recordsSynced = 0;
        
        $this->logger->debug('Syncing costs for date range', [
            'campaign_id' => $campaignId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        // Sincronizar costos de campaña
        $campaignInsights = $this->fbApi->getInsightsByDateRange($campaignId, 'campaign', $startDate, $endDate);
        foreach ($campaignInsights as $insight) { 
            $this->saveCostData($campaignId, 'campaign', $insight);
            $recordsSynced++;
        }
        
        // Sincronizar costos de adsets
        $adsets = $this->db->fetchAll("SELECT id FROM adsets WHERE campaign_id = ?", [$campaignId]);
        foreach ($adsets as $adset) {
            try {
                $adsetInsights = $this->fbApi->getInsightsByDateRange($adset['id'], 'adset', $startDate, $endDate);
                foreach ($adsetInsights as $insight) { 
                    $this->saveCostData($adset['id'], 'adset', $insight);
                    $recordsSynced++;
                }
                
                // Sincronizar costos de ads
                $ads = $this->db->fetchAll("SELECT id FROM ads WHERE adset_id = ?", [$adset['id']]);
                foreach ($ads as $ad) {
                    try {
                        $adInsights = $this->fbApi->getInsightsByDateRange($ad['id'], 'ad', $startDate, $endDate);
                        foreach ($adInsights as $insight) { 
                            $this->saveCostData($ad['id'], 'ad', $insight);
                            $recordsSynced++;
                        }
                    } catch (Exception $e) { 
                        $this->logger->warning('Failed to sync ad insights', [
                            'ad_id' => $ad['id'], 
                            'date_range' => "{$startDate} to {$endDate}",
                            'error' => $e->getMessage()
                        ]); 
                    }
                }
            } catch (Exception $e) { 
                $this->logger->warning('Failed to sync adset insights', [
                    'adset_id' => $adset['id'],
                    'date_range' => "{$startDate} to {$endDate}", 
                    'error' => $e->getMessage()
                ]); 
            }
        }
        
        $this->logger->debug('Date range sync completed', [
            'campaign_id' => $campaignId,
            'date_range' => "{$startDate} to {$endDate}",
            'records_synced' => $recordsSynced
        ]);
        
        return $recordsSynced;
        
    } catch (Exception $e) { 
        $this->logger->error('Date range sync failed', [
            'campaign_id' => $campaignId,
            'date_range' => "{$startDate} to {$endDate}",
            'error' => $e->getMessage()
        ]);
        throw $e; 
    }
}


/**
 * Sincronización completa de Voluum con chunks para evitar timeouts
 */
public function syncVoluumDataForCampaignFull($campaignId, $daysBack = 365) {
    $campaignData = $this->db->fetchOne("
        SELECT voluum_campaign_id, aa.currency, c.name as campaign_name 
        FROM campaigns c 
        JOIN ad_accounts aa ON c.account_id = aa.id 
        WHERE c.id = ?
    ", [$campaignId]);
    
    if (empty($campaignData) || empty($campaignData['voluum_campaign_id'])) {
        return [
            'success' => false, 
            'error' => 'Esta campaña no está mapeada a Voluum.'
        ];
    }
    
    $voluumCampaignId = $campaignData['voluum_campaign_id'];
    $currency = $campaignData['currency'] ?? 'USD';
    $campaignName = $campaignData['campaign_name'];
    
    // Limitar a máximo 365 días (1 año)
    $daysBack = min($daysBack, 365);
    
    $voluumApi = new VoluumAPI();
    
    try {
        $this->logger->info('Starting full Voluum sync with chunks', [
            'fb_campaign_id' => $campaignId,
            'fb_campaign_name' => $campaignName,
            'voluum_campaign_id' => $voluumCampaignId,
            'days_back' => $daysBack
        ]);
        
        $totalSynced = 0;
        $chunkSize = 30; // 30 días por chunk para evitar timeouts
        $chunks = ceil($daysBack / $chunkSize);
        
        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $chunkStart = $chunk * $chunkSize;
            $chunkEnd = min(($chunk + 1) * $chunkSize - 1, $daysBack - 1);
            
            $startDate = new DateTime("-{$chunkEnd} days", new DateTimeZone('UTC'));
            $endDate = new DateTime("-{$chunkStart} days", new DateTimeZone('UTC'));
            
            $this->logger->info('Processing Voluum chunk', [
                'chunk' => $chunk + 1,
                'total_chunks' => $chunks,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]);
            
            // Sincronizar este chunk
            $chunkSynced = $this->syncVoluumDateRange(
                $voluumCampaignId, 
                $startDate, 
                $endDate, 
                $currency
            );
            
            $totalSynced += $chunkSynced;
            
            $this->logger->debug('Voluum chunk completed', [
                'chunk' => $chunk + 1,
                'records_synced' => $chunkSynced,
                'total_so_far' => $totalSynced
            ]);
            
            // Pausa entre chunks para no sobrecargar la API
            if ($chunk < $chunks - 1) {
                sleep(2);
            }
        }
        
        $this->logger->info('Full Voluum sync completed', [
            'campaign_name' => $campaignName,
            'total_synced' => $totalSynced,
            'chunks_processed' => $chunks,
            'days_synced' => $daysBack
        ]);
        
        return [
            'success' => true,
            'message' => "Synced {$totalSynced} ad-day records from Voluum across {$chunks} chunks ({$daysBack} days)",
            'stats' => [
                'total_synced' => $totalSynced,
                'chunks_processed' => $chunks,
                'days_synced' => $daysBack
            ]
        ];
        
    } catch (Exception $e) {
        $this->logger->error('Full Voluum sync failed', [
            'campaign_id' => $campaignId,
            'voluum_campaign_id' => $voluumCampaignId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Método helper para sincronizar un rango de fechas específico de Voluum
 */
private function syncVoluumDateRange($voluumCampaignId, DateTime $startDate, DateTime $endDate, $currency = 'USD') {
    $voluumApi = new VoluumAPI();
    $totalSynced = 0;
    
    // Iterar día por día dentro del rango
    $datePeriod = new DatePeriod($startDate, new DateInterval('P1D'), (clone $endDate)->modify('+1 day'));
    
    foreach ($datePeriod as $dateObject) {
        $currentDateForDb = $dateObject->format('Y-m-d');
        $from = $dateObject->format('Y-m-d\T00:00:00.000\Z');
        $to = (clone $dateObject)->modify('+1 day')->format('Y-m-d\T00:00:00.000\Z');
        
        try {
            $dailyReport = $voluumApi->getReportByAdForDate($voluumCampaignId, $from, $to, $currency);

            if (!empty($dailyReport)) {
                foreach ($dailyReport as $row) {
                    if (isset($row['customVariable1']) && !empty($row['customVariable1'])) {
                        $adId = $row['customVariable1'];
                        
                        // Validar que no sea un placeholder
                        if ($adId === '{{ad.id}}' || strpos($adId, '{{') !== false || empty(trim($adId))) {
                            continue;
                        }
                        
                        $conversions = intval($row['conversions'] ?? 0);
                        $revenue = floatval($row['revenue'] ?? 0.0);
                        $checkouts = intval($row['customConversions7'] ?? 0);

                        if ($conversions > 0 || $revenue > 0 || $checkouts > 0) {
                            $sql = "
                                INSERT INTO voluum_conversions (ad_id, `date`, conversions, revenue, checkouts) 
                                VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE 
                                    conversions = VALUES(conversions), 
                                    revenue = VALUES(revenue), 
                                    checkouts = VALUES(checkouts)
                            ";
                            
                            $this->db->query($sql, [$adId, $currentDateForDb, $conversions, $revenue, $checkouts]);
                            $totalSynced++;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->warning('Failed to sync Voluum data for date', [
                'date' => $currentDateForDb,
                'voluum_campaign_id' => $voluumCampaignId,
                'error' => $e->getMessage()
            ]);
        }
        
        // Pequeña pausa entre días
        usleep(200000); // 200ms
    }
    
    return $totalSynced;
}

}