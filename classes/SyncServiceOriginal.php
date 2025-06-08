<?php
/**
 * Servicio de sincronización con Facebook
 * 
 * Maneja la sincronización de cuentas, campañas y costos
 * con la base de datos local
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
    
    /**
     * Sincronizar todas las cuentas
     */
    public function syncAllAccounts() {
        $this->startSyncLog('facebook');
        
        try {
            $accounts = $this->fbApi->getAdAccounts();
            
            foreach ($accounts as $account) {
                $this->syncAccount($account);
            }
            
            $this->completeSyncLog('completed');
            
            return [
                'success' => true,
                'stats' => $this->stats
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Sync all accounts failed', [
                'error' => $e->getMessage()
            ]);
            
            $this->completeSyncLog('failed', $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats
            ];
        }
    }
    
    /**
     * Sincronizar una cuenta específica
     */
    public function syncAccount($accountData) {
        try {
            // Prepare account data
            $accountId = $accountData['id'];
            
            $this->db->callProcedure('upsert_ad_account', [
                $accountId,
                $accountData['name'] ?? 'Unknown',
                $accountData['currency'] ?? 'USD',
                $accountData['timezone_name'] ?? 'UTC',
                $accountData['timezone_offset_hours_utc'] ?? 0,
                $accountData['account_status'] ?? 0,
                $accountData['business_name'] ?? null,
                $accountData['business_id'] ?? null,
                floatval($accountData['amount_spent'] ?? 0),
                floatval($accountData['balance'] ?? 0)
            ]);
            
            $this->stats['accounts_synced']++;
            
            $this->logger->info('Account synced', [
                'account_id' => $accountId,
                'name' => $accountData['name']
            ]);
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Failed to sync account', [
                'account_id' => $accountData['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Sincronizar campañas de una cuenta
     */
    public function syncAccountCampaigns($accountId, $syncCosts = true, $daysBack = 7) {
        $this->startSyncLog('facebook');
        
        try {
            // Get campaigns
            $campaigns = $this->fbApi->getCampaigns($accountId);
            
            $this->logger->info('Starting campaign sync', [
                'account_id' => $accountId,
                'campaigns_found' => count($campaigns),
                'sync_costs' => $syncCosts,
                'days_back' => $daysBack
            ]);
            
            foreach ($campaigns as $campaign) {
                $this->syncCampaign($campaign, $accountId);
                
                if ($syncCosts) {
                    // IMPORTANTE: Sincronizar estructura completa (adsets y ads)
                    $this->syncCampaignStructure($campaign['id']);
                    
                    // Luego sincronizar costos para todos los niveles
                    $this->syncCampaignCosts($campaign['id'], $daysBack);
                }
            }
            
            $this->completeSyncLog('completed');
            
            return [
                'success' => true,
                'stats' => $this->stats
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Sync account campaigns failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage()
            ]);
            
            $this->completeSyncLog('failed', $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats
            ];
        }
    }
    
    /**
     * Sincronizar una campaña
     */
    private function syncCampaign($campaignData, $accountId) {
        try {
            $sql = "INSERT INTO campaigns (id, account_id, name, status) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        account_id = VALUES(account_id),
                        name = VALUES(name),
                        status = VALUES(status),
                        updated_at = CURRENT_TIMESTAMP";
            
            $this->db->query($sql, [
                $campaignData['id'],
                $accountId,
                $campaignData['name'],
                $campaignData['status']
            ]);
            
            $this->stats['campaigns_synced']++;
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            throw $e;
        }
    }
    
    /**
     * Sincronizar estructura completa de una campaña (adsets y ads)
     */
    public function syncCampaignStructure($campaignId) {
        try {
            // Get adsets
            $adsets = $this->fbApi->getAdSets($campaignId);
            
            foreach ($adsets as $adset) {
                $this->syncAdSet($adset);
                
                // Get ads
                $ads = $this->fbApi->getAds($adset['id']);
                
                foreach ($ads as $ad) {
                    $this->syncAd($ad);
                }
            }
            
        } catch (Exception $e) {
            $this->logger->error('Failed to sync campaign structure', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Sincronizar un adset
     */
    private function syncAdSet($adsetData) {
        try {
            $sql = "INSERT INTO adsets (id, campaign_id, name, status) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        status = VALUES(status),
                        updated_at = CURRENT_TIMESTAMP";
            
            $this->db->query($sql, [
                $adsetData['id'],
                $adsetData['campaign_id'],
                $adsetData['name'],
                $adsetData['status']
            ]);
            
            $this->stats['adsets_synced']++;
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            throw $e;
        }
    }
    
    /**
     * Sincronizar un ad
     */
    private function syncAd($adData) {
        try {
            $sql = "INSERT INTO ads (id, adset_id, campaign_id, name, status) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        status = VALUES(status),
                        updated_at = CURRENT_TIMESTAMP";
            
            $this->db->query($sql, [
                $adData['id'],
                $adData['adset_id'],
                $adData['campaign_id'],
                $adData['name'],
                $adData['status']
            ]);
            
            $this->stats['ads_synced']++;
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            throw $e;
        }
    }
    
    /**
     * Sincronizar costos de una campaña
     */
    public function syncCampaignCosts($campaignId, $daysBack = 7) {
        try {
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime("-$daysBack days"));
            
            $this->logger->info('Syncing campaign costs', [
                'campaign_id' => $campaignId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            
            // 1. Get campaign insights
            $campaignInsights = $this->fbApi->getInsightsByDateRange(
                $campaignId,
                'campaign',
                $startDate,
                $endDate
            );
            
            foreach ($campaignInsights as $insight) {
                $this->saveCostData($campaignId, 'campaign', $insight);
            }
            
            $this->logger->info('Campaign level costs synced', [
                'campaign_id' => $campaignId,
                'insights_count' => count($campaignInsights)
            ]);
            
            // 2. Get ALL adsets for this campaign (no limit)
            $adsets = $this->db->fetchAll(
                "SELECT id FROM adsets WHERE campaign_id = ?",
                [$campaignId]
            );
            
            $this->logger->info('Syncing adset costs', [
                'campaign_id' => $campaignId,
                'adsets_count' => count($adsets)
            ]);
            
            foreach ($adsets as $adset) {
                try {
                    $adsetInsights = $this->fbApi->getInsightsByDateRange(
                        $adset['id'],
                        'adset',
                        $startDate,
                        $endDate
                    );
                    
                    foreach ($adsetInsights as $insight) {
                        $this->saveCostData($adset['id'], 'adset', $insight);
                    }
                    
                    // 3. Get ALL ads for this adset
                    $ads = $this->db->fetchAll(
                        "SELECT id FROM ads WHERE adset_id = ?",
                        [$adset['id']]
                    );
                    
                    $this->logger->info('Syncing ad costs for adset', [
                        'adset_id' => $adset['id'],
                        'ads_count' => count($ads)
                    ]);
                    
                    foreach ($ads as $ad) {
                        try {
                            $adInsights = $this->fbApi->getInsightsByDateRange(
                                $ad['id'],
                                'ad',
                                $startDate,
                                $endDate
                            );
                            
                            foreach ($adInsights as $insight) {
                                $this->saveCostData($ad['id'], 'ad', $insight);
                            }
                            
                        } catch (Exception $e) {
                            $this->logger->warning('Failed to sync ad insights', [
                                'ad_id' => $ad['id'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                } catch (Exception $e) {
                    $this->logger->warning('Failed to sync adset insights', [
                        'adset_id' => $adset['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $this->logger->info('Campaign costs sync completed', [
                'campaign_id' => $campaignId,
                'costs_synced' => $this->stats['costs_synced']
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to sync campaign costs', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Guardar datos de costo
     */
    private function saveCostData($entityId, $entityType, $insightData) {
        try {
            // Verificar que tenemos datos válidos
            $spend = floatval($insightData['spend'] ?? 0);
            $impressions = intval($insightData['impressions'] ?? 0);
            $clicks = intval($insightData['clicks'] ?? 0);
            $date = $insightData['date_start'] ?? date('Y-m-d');
            
            // Solo guardar si hay alguna actividad
            if ($spend > 0 || $impressions > 0 || $clicks > 0) {
                $this->db->callProcedure('upsert_facebook_cost', [
                    $entityId,
                    $entityType,
                    $spend,
                    $impressions,
                    $clicks,
                    $date
                ]);
                
                $this->stats['costs_synced']++;
                
                $this->logger->debug('Cost data saved', [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'date' => $date,
                    'spend' => $spend,
                    'impressions' => $impressions,
                    'clicks' => $clicks
                ]);
            } else {
                $this->logger->debug('Skipping zero cost data', [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'date' => $date
                ]);
            }
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Failed to save cost data', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Iniciar log de sincronización
     */
    private function startSyncLog($type) {
        $this->syncLogId = $this->db->insert('sync_logs', [
            'sync_type' => $type,
            'sync_date' => date('Y-m-d H:i:s'),
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s')
        ]);
        
        $this->logger->info('Sync started', [
            'type' => $type,
            'log_id' => $this->syncLogId
        ]);
    }
    
    /**
     * Completar log de sincronización
     */
    private function completeSyncLog($status, $errorMessage = null) {
        if (!$this->syncLogId) return;
        
        $totalProcessed = array_sum($this->stats);
        
        $this->db->update('sync_logs', [
            'records_processed' => $totalProcessed,
            'records_created' => $totalProcessed - $this->stats['errors'],
            'records_updated' => 0,
            'status' => $status,
            'error_message' => $errorMessage,
            'completed_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $this->syncLogId
        ]);
        
        $this->logger->info('Sync completed', [
            'log_id' => $this->syncLogId,
            'status' => $status,
            'stats' => $this->stats
        ]);
    }
    
    /**
     * Obtener estadísticas de la última sincronización
     */
    public function getLastSyncStats() {
        $lastSync = $this->db->getLastSync('facebook');
        
        if (!$lastSync) {
            return null;
        }
        
        $duration = strtotime($lastSync['completed_at']) - strtotime($lastSync['started_at']);
        
        return [
            'date' => $lastSync['sync_date'],
            'duration_seconds' => $duration,
            'records_processed' => $lastSync['records_processed'],
            'status' => $lastSync['status'],
            'error' => $lastSync['error_message']
        ];
    }
}
?>