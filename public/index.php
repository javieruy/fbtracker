<?php
/**
 * Página principal del Facebook Tracker
 * Versión Final y Corregida
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Cargar configuración
require_once '../config/config.php';
require_once '../includes/error_handler.php';

// Iniciar sesión
session_start();

// Verificar configuración
$configErrors = checkConfiguration();
if (!empty($configErrors)) {
    displayError([
        'type' => 'Configuration Error',
        'message' => 'Please check your configuration: ' . implode(', ', $configErrors)
    ]);
}

// Instanciar servicios
$db = Database::getInstance();
$logger = Logger::getInstance();
$fbApi = new FacebookAPI();
$syncService = new SyncService();

// Manejar acciones
$action = $_GET['action'] ?? 'dashboard';
$message = null;
$error = null;

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['action'] ?? '') {
            case 'sync_accounts':
                $result = $syncService->syncAllAccounts();
                if ($result['success']) {
                    $message = sprintf('Synced %d accounts successfully', 
                        $result['stats']['accounts_synced']);
                } else {
                    $error = $result['error'];
                }
                break;
                
            case 'sync_campaigns':
                $accountId = $_POST['account_id'] ?? '';
                if ($accountId) {
                    $result = $syncService->syncAccountCampaigns($accountId, true, 30);
                    if ($result['success']) {
                        $message = sprintf('Synced %d campaigns with costs', 
                            $result['stats']['campaigns_synced']);
                    } else {
                        $error = $result['error'];
                    }
                } else {
                    $error = 'No account selected';
                }
                break;
            
            case 'map_campaign':
                $fbCampaignId = $_POST['facebook_campaign_id'] ?? '';
                $voluumCampaignId = $_POST['voluum_campaign_id'] ?? '';

                if ($fbCampaignId && $voluumCampaignId) {
                    $db->beginTransaction();
                    try {
                        $db->insert('campaign_mappings', [
                            'facebook_campaign_id' => $fbCampaignId,
                            'voluum_campaign_id' => $voluumCampaignId,
                            'mapping_status' => 'active'
                        ]);
                        $db->update('campaigns', 
                            [
                                'is_mapped' => 1,
                                'voluum_campaign_id' => $voluumCampaignId
                            ], 
                            [
                                'id' => $fbCampaignId
                            ]
                        );
                        $db->commit();
                        $message = 'Campaña mapeada correctamente.';
                    } catch (Exception $e) {
                        $db->rollback();
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                            $error = 'Error: Uno de los IDs ya ha sido mapeado previamente.';
                        } else {
                            $error = 'Error al guardar el mapeo: ' . $e->getMessage();
                        }
                    }
                } else {
                    $error = 'Faltan datos para realizar el mapeo.';
                }
                break;
				 case 'sync_single_campaign':
                $campaignId = $_POST['campaign_id'] ?? '';
                if ($campaignId) {
                    // Sincroniza la estructura (adsets/ads) y luego los costos de FB para esta campaña
                    $syncService->syncCampaignStructure($campaignId);
                    $syncService->syncCampaignCosts($campaignId, 30); // Sincroniza los últimos 7 días
                    $message = "Costos de Facebook sincronizados correctamente para la campaña.";
                } else {
                    $error = "No se proveyó ID de campaña para sincronizar costos de FB.";
                }
                break;
				 case 'sync_full_campaign':
                $campaignId = $_POST['campaign_id'] ?? '';
                if ($campaignId) {
                    // Obtener fecha de creación de la campaña
                    $campaignInfo = $db->fetchOne("
                        SELECT created_time, name 
                        FROM campaigns 
                        WHERE id = ?
                    ", [$campaignId]);
                    
                    if ($campaignInfo && !empty($campaignInfo['created_time'])) {
                        $createdDate = date('Y-m-d', strtotime($campaignInfo['created_time']));
                        $today = date('Y-m-d');
                        $daysDiff = (strtotime($today) - strtotime($createdDate)) / (60 * 60 * 24);
                        
                        // Sincronizar estructura primero
                        $syncService->syncCampaignStructure($campaignId);
                        
                        // Sincronizar costos desde la creación (máximo 365 días para evitar problemas)
                        $maxDays = min($daysDiff, 365);
                        $syncService->syncCampaignCosts($campaignId, $maxDays);
                        
                        $message = sprintf(
                            'Sincronización completa exitosa. Campaña "%s" sincronizada desde %s (%d días)', 
                            $campaignInfo['name'],
                            $createdDate,
                            $maxDays
                        );
                        
                    } else {
                        // Si no tenemos fecha de creación, usar Facebook API para obtenerla
                        try {
                            $campaignData = $fbApi->getCampaignDetails($campaignId);
                            
                            if (isset($campaignData['created_time'])) {
                                // Actualizar la fecha en nuestra DB
                                $db->query("UPDATE campaigns SET created_time = ? WHERE id = ?", 
                                    [$campaignData['created_time'], $campaignId]);
                                
                                $createdDate = date('Y-m-d', strtotime($campaignData['created_time']));
                                $daysDiff = (strtotime(date('Y-m-d')) - strtotime($createdDate)) / (60 * 60 * 24);
                                
                                // Sincronizar estructura
                                $syncService->syncCampaignStructure($campaignId);
                                
                                // Sincronizar costos
                                $maxDays = min($daysDiff, 365);
                                $syncService->syncCampaignCosts($campaignId, $maxDays);
                                
                                $message = sprintf(
                                    'Sincronización completa exitosa desde %s (%d días)', 
                                    $createdDate,
                                    $maxDays
                                );
                            } else {
                                $error = 'No se pudo obtener la fecha de creación de la campaña';
                            }
                        } catch (Exception $e) {
                            $error = 'Error al obtener información de la campaña: ' . $e->getMessage();
                        }
                    }
                } else {
                    $error = "No se proveyó ID de campaña para sincronización completa.";
                }
                break;
				

            case 'sync_voluum_campaign':
                $campaignId = $_POST['campaign_id'] ?? '';
                if ($campaignId) {
                    // La llamada ahora pasa un '30' para sincronizar los últimos 30 días
                    $result = $syncService->syncVoluumDataForCampaign($campaignId, 30);
                    
                    if ($result['success']) {
                        $message = $result['message'];
                    } else {
                        $error = $result['error'];
                    }
                } else {
                    $error = 'No campaign ID provided for Voluum sync';
                }
                break;
				 case 'sync_voluum_full':
                $campaignId = $_POST['campaign_id'] ?? '';
                if ($campaignId) {
                    // Obtener fecha de creación de la campaña y verificar mapeo
                    $campaignInfo = $db->fetchOne("
                        SELECT created_time, name, voluum_campaign_id
                        FROM campaigns 
                        WHERE id = ?
                    ", [$campaignId]);
                    
                    if (empty($campaignInfo['voluum_campaign_id'])) {
                        $error = 'Esta campaña no está mapeada a Voluum.';
                    } else {
                        $createdDate = null;
                        $daysToSync = 365; // Máximo 1 año por defecto
                        
                        if (!empty($campaignInfo['created_time'])) {
                            $createdDate = date('Y-m-d', strtotime($campaignInfo['created_time']));
                            $today = date('Y-m-d');
                            $daysDiff = (strtotime($today) - strtotime($createdDate)) / (60 * 60 * 24);
                            
                            // Limitar a máximo 365 días (1 año)
                            $daysToSync = min($daysDiff, 365);
                        } else {
                            // Si no tenemos fecha de creación, usar 365 días
                            $daysToSync = 365;
                            $createdDate = date('Y-m-d', strtotime('-365 days'));
                        }
                        
                        $logger->info('Starting full Voluum sync', [
                            'campaign_id' => $campaignId,
                            'campaign_name' => $campaignInfo['name'],
                            'voluum_campaign_id' => $campaignInfo['voluum_campaign_id'],
                            'days_to_sync' => $daysToSync,
                            'from_date' => $createdDate
                        ]);
                        
                        // Sincronizar con chunks para evitar timeout
                        $result = $syncService->syncVoluumDataForCampaignFull($campaignId, $daysToSync);
                        
                        if ($result['success']) {
                            $message = sprintf(
                                'Sincronización completa de Voluum exitosa. Campaña "%s" sincronizada desde %s (%d días). %s',
                                $campaignInfo['name'],
                                $createdDate,
                                $daysToSync,
                                $result['message']
                            );
                        } else {
                            $error = $result['error'];
                        }
                    }
                } else {
                    $error = "No se proveyó ID de campaña para sincronización completa de Voluum.";
                }
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        logException($e);
    }
}

// Obtener datos según la acción
$pageData = [];

switch ($action) {
    case 'dashboard':
        $pageData['accounts'] = $db->fetchAll("
            SELECT * FROM account_summary 
            ORDER BY total_spend_today DESC
        ");
        
        $pageData['lastSync'] = $syncService->getLastSyncStats();
        
        $pageData['stats'] = $db->fetchOne("
            SELECT 
                COUNT(DISTINCT aa.id) as total_accounts,
                COUNT(DISTINCT c.id) as total_campaigns,
                COUNT(DISTINCT CASE WHEN c.is_mapped = 1 THEN c.id END) as mapped_campaigns,
                COALESCE(SUM(fc.spend), 0) as total_spend_today
            FROM ad_accounts aa
            LEFT JOIN campaigns c ON aa.id = c.account_id
            LEFT JOIN facebook_costs fc ON c.id = fc.entity_id 
                AND fc.entity_type = 'campaign' 
                AND fc.date = CURDATE()
            WHERE aa.is_active = 1
        ");
        break;
        
    case 'campaigns':
        $accountId = $_GET['account_id'] ?? '';
        if ($accountId) {
            $pageData['account'] = $db->fetchOne("SELECT * FROM ad_accounts WHERE id = ?", [$accountId]);
            $pageData['campaigns'] = $db->fetchAll("
                SELECT c.*, fc.spend as spend_today, fc.impressions as impressions_today, fc.clicks as clicks_today, fc.cpm, fc.cpc
                FROM campaigns c
                LEFT JOIN facebook_costs fc ON c.id = fc.entity_id AND fc.entity_type = 'campaign' AND fc.date = CURDATE()
                WHERE c.account_id = ?
                ORDER BY COALESCE(fc.spend, 0) DESC
            ", [$accountId]);
        }
        break;
        
    case 'campaign_details':
        $campaignId = $_GET['campaign_id'] ?? '';
        $dateFilter = $_GET['date_filter'] ?? 'today';
        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';
        
        if ($campaignId) {
            $pageData['campaign'] = $db->fetchOne("
                SELECT c.*, aa.name as account_name, aa.currency, aa.timezone_name
                FROM campaigns c
                JOIN ad_accounts aa ON c.account_id = aa.id
                WHERE c.id = ?
            ", [$campaignId]);
            
            $fbDateCondition = '';
            $voluumDateCondition = '';

            switch ($dateFilter) {
                case 'yesterday':
                    $date = date('Y-m-d', strtotime('-1 day'));
                    $fbDateCondition = "fc.date = '$date'";
                    $voluumDateCondition = "`date` = '$date'";
                    break;
                case 'last_7_days':
                    $start_date_filter = date('Y-m-d', strtotime('-6 days'));
                    $end_date_filter = date('Y-m-d');
                    $fbDateCondition = "fc.date >= '$start_date_filter' AND fc.date <= '$end_date_filter'";
                    $voluumDateCondition = "`date` >= '$start_date_filter' AND `date` <= '$end_date_filter'";
                    break;
                case 'last_30_days':
                    $start_date_filter = date('Y-m-d', strtotime('-29 days'));
                    $end_date_filter = date('Y-m-d');
                    $fbDateCondition = "fc.date >= '$start_date_filter' AND fc.date <= '$end_date_filter'";
                    $voluumDateCondition = "`date` >= '$start_date_filter' AND `date` <= '$end_date_filter'";
                    break;
                case 'custom':
                    if ($startDate && $endDate) {
                        $fbDateCondition = "fc.date >= '$startDate' AND fc.date <= '$endDate'";
                        $voluumDateCondition = "`date` >= '$startDate' AND `date` <= '$endDate'";
                    } else {
                        $date = date('Y-m-d');
                        $fbDateCondition = "fc.date = '$date'";
                        $voluumDateCondition = "`date` = '$date'";
                    }
                    break;
                case 'today':
                default:
                    $date = date('Y-m-d');
                    $fbDateCondition = "fc.date = '$date'";
                    $voluumDateCondition = "`date` = '$date'";
                    break;
            }
            
            $pageData['ads'] = $db->fetchAll("
                SELECT 
                    a.id, a.name, a.status, ads.name as adset_name,
                    COALESCE(spend_data.total_spend, 0) as total_spend,
                    COALESCE(spend_data.total_impressions, 0) as total_impressions,
                    COALESCE(spend_data.total_clicks, 0) as total_clicks,
                    CASE WHEN spend_data.total_impressions > 0 THEN (spend_data.total_spend / spend_data.total_impressions) * 1000 ELSE 0 END as cpm,
                    CASE WHEN spend_data.total_clicks > 0 THEN spend_data.total_spend / spend_data.total_clicks ELSE 0 END as cpc,
                    COALESCE(voluum_data.voluum_conversions, 0) as voluum_conversions,
                    COALESCE(voluum_data.voluum_revenue, 0) as voluum_revenue,
                    COALESCE(voluum_data.voluum_checkouts, 0) as voluum_checkouts -- <-- LÍNEA NUEVA
                FROM ads a
                JOIN adsets ads ON a.adset_id = ads.id
                LEFT JOIN (
                    SELECT entity_id, SUM(spend) as total_spend, SUM(impressions) as total_impressions, SUM(clicks) as total_clicks
                    FROM facebook_costs fc
                    WHERE entity_type = 'ad' AND $fbDateCondition
                    GROUP BY entity_id
                ) AS spend_data ON a.id = spend_data.entity_id
                LEFT JOIN (
                    -- V---- COLUMNA NUEVA AQUÍ ----V
                    SELECT ad_id, SUM(conversions) as voluum_conversions, SUM(revenue) as voluum_revenue, SUM(checkouts) as voluum_checkouts
                    FROM voluum_conversions
                    WHERE $voluumDateCondition
                    GROUP BY ad_id
                ) AS voluum_data ON a.id = voluum_data.ad_id
                WHERE a.campaign_id = ?
                GROUP BY a.id, a.name, a.status, ads.name
                ORDER BY total_spend DESC
            ", [$campaignId]);
            
            $pageData['currentFilter'] = $dateFilter;
            $pageData['startDate'] = $startDate;
            $pageData['endDate'] = $endDate;
        }
        break;
}

function formatNumber($number, $decimals = 0) {
    return number_format($number, $decimals, '.', ',');
}

function formatCurrency($amount, $currency = 'USD') {
    $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . formatNumber($amount, 2);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_CONFIG['name']; ?> - Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .header { background: #1877f2; color: white; padding: 1rem 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .header h1 { font-size: 24px; font-weight: 500; }
        .alert { padding: 12px 16px; margin: 20px 0; border-radius: 6px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        .card h2 { font-size: 20px; margin-bottom: 16px; color: #1a1a1a; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .stat-card { background: #f8f9fa; padding: 16px; border-radius: 6px; text-align: center; }
        .stat-value { font-size: 28px; font-weight: bold; color: #1877f2; }
        .stat-label { font-size: 14px; color: #666; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #e0e0e0; }
        th { background: #f8f9fa; font-weight: 600; color: #666; font-size: 14px; text-transform: uppercase; }
        tr:hover { background: #f8f9fa; }
        .btn { display: inline-block; padding: 8px 16px; background: #1877f2; color: white; text-decoration: none; border-radius: 6px; font-size: 14px; border: none; cursor: pointer; transition: background 0.2s; }
        .btn:hover { background: #166fe5; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .sync-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 12px; background: #e3f2fd; border-radius: 6px; font-size: 14px; }
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-active { background: #d4edda; color: #155724; }
        .status-paused { background: #fff3cd; color: #856404; }
        .status-archived { background: #f8d7da; color: #721c24; }
        .breadcrumb { margin: 20px 0; font-size: 14px; }
        .breadcrumb a { color: #1877f2; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .empty-state { text-align: center; padding: 40px; color: #666; }
        .metric-trend { font-size: 12px; margin-left: 8px; }
        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1><?php echo APP_CONFIG['name']; ?></h1>
        </div>
    </header>
    
    <main class="container" style="margin-top: 20px;">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($action === 'dashboard'): ?>
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo $pageData['stats']['total_accounts'] ?? 0; ?></div><div class="stat-label">Ad Accounts</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo $pageData['stats']['total_campaigns'] ?? 0; ?></div><div class="stat-label">Total Campaigns</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo $pageData['stats']['mapped_campaigns'] ?? 0; ?></div><div class="stat-label">Mapped to Voluum</div></div>
                <div class="stat-card"><div class="stat-value"><?php echo formatCurrency($pageData['stats']['total_spend_today'] ?? 0); ?></div><div class="stat-label">Spend Today</div></div>
            </div>
            
            <div class="sync-info">
                <span>
                    <?php if ($pageData['lastSync']): ?>
                        Last sync: <?php echo date('M d, Y H:i', strtotime($pageData['lastSync']['date'])); ?>
                        (<?php echo $pageData['lastSync']['records_processed']; ?> records)
                    <?php else: ?>
                        No previous sync found
                    <?php endif; ?>
                </span>
                <form method="POST" style="display: inline;"><input type="hidden" name="action" value="sync_accounts"><button type="submit" class="btn btn-sm">Sync All Accounts</button></form>
            </div>
            
            <div class="card">
                <h2>Ad Accounts</h2>
                <?php if (empty($pageData['accounts'])): ?>
                    <div class="empty-state">
                        <p>No accounts found. Click "Sync All Accounts" button above to fetch from Facebook.</p>
                        <form method="POST" style="margin-top: 20px;"><input type="hidden" name="action" value="sync_accounts"><button type="submit" class="btn">Sync All Accounts Now</button></form>
                    </div>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Account Name</th><th>Status</th><th>Campaigns</th><th>Spend Today</th><th>Last Sync</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($pageData['accounts'] as $account): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($account['account_name']); ?></strong><br><small><?php echo htmlspecialchars($account['account_id']); ?></small></td>
                                    <td><span class="status-badge status-active">Active</span></td>
                                    <td>
                                        <?php echo $account['total_campaigns']; ?>
                                        <?php if ($account['mapped_campaigns'] > 0): ?><small>(<?php echo $account['mapped_campaigns']; ?> mapped)</small><?php endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($account['total_spend_today'] ?? 0, $account['currency']); ?></td>
                                    <td><?php if (!empty($account['last_synced_at'])): ?><?php echo date('H:i', strtotime($account['last_synced_at'])); ?><?php else: ?>Never<?php endif; ?></td>
                                    <td><a href="?action=campaigns&account_id=<?php echo urlencode($account['account_id']); ?>" class="btn btn-sm">View Campaigns</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
        <?php elseif ($action === 'campaigns' && isset($pageData['account'])): ?>
            <div class="breadcrumb"><a href="index.php">Dashboard</a> / <?php echo htmlspecialchars($pageData['account']['name']); ?></div>
            <div class="sync-info">
                <span>Account: <?php echo htmlspecialchars($pageData['account']['name']); ?> (<?php echo count($pageData['campaigns']); ?> campaigns)</span>
                <form method="POST" style="display: inline;"><input type="hidden" name="action" value="sync_campaigns"><input type="hidden" name="account_id" value="<?php echo htmlspecialchars($pageData['account']['id']); ?>"><button type="submit" class="btn btn-sm">Sync Campaigns</button></form>
            </div>
            <div class="card">
                <h2>Campaigns</h2>
                <?php if (empty($pageData['campaigns'])): ?>
                    <div class="empty-state"><p>No campaigns found. Click "Sync Campaigns" to fetch from Facebook.</p></div>
                <?php else: ?>
                    <table>
                        <thead><tr><th>Campaign Name</th><th>Status</th><th>Voluum</th><th>Spend Today</th><th>Clicks</th><th>CPC</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($pageData['campaigns'] as $campaign): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($campaign['name']); ?></strong><br><small><?php echo htmlspecialchars($campaign['id']); ?></small></td>
                                    <td><span class="status-badge status-<?php echo strtolower($campaign['status']); ?>"><?php echo htmlspecialchars($campaign['status']); ?></span></td>
                                    <td>
                                        <?php if ($campaign['is_mapped']): ?>
                                            <span class="status-badge status-active">Mapped</span>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-secondary" onclick="openMappingModal('<?php echo htmlspecialchars($campaign['id']); ?>', '<?php echo htmlspecialchars($campaign['name']); ?>')">Mapear</button>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($campaign['spend_today'] ?? 0); ?></td>
                                    <td><?php echo formatNumber($campaign['clicks_today'] ?? 0); ?></td>
                                    <td><?php echo $campaign['cpc'] ? formatCurrency($campaign['cpc']) : '-'; ?></td>
                                    <td><a href="?action=campaign_details&campaign_id=<?php echo urlencode($campaign['id']); ?>" class="btn btn-sm">Details</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
        <?php elseif ($action === 'campaign_details' && isset($pageData['campaign'])): ?>
            <div class="breadcrumb">
                <a href="index.php">Dashboard</a> / 
                <a href="?action=campaigns&account_id=<?php echo urlencode($pageData['campaign']['account_id']); ?>"><?php echo htmlspecialchars($pageData['campaign']['account_name']); ?></a> / 
                <?php echo htmlspecialchars($pageData['campaign']['name']); ?>
            </div>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                    <h2><?php echo htmlspecialchars($pageData['campaign']['name']); ?></h2>
                    <span style="font-size: 14px; color: #666; background: #f0f0f0; padding: 5px 10px; border-radius: 5px; white-space: nowrap;">
                        Ad Account Timezone: <strong><?php echo htmlspecialchars($pageData['campaign']['timezone_name']); ?></strong>
                    </span>
                </div>
                <div style="background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                    
                    <form method="GET" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="action" value="campaign_details">
                        <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaignId); ?>">
                        <div>
                            <label style="margin-right: 10px;"><input type="radio" name="date_filter" value="today" <?php echo ($pageData['currentFilter'] === 'today') ? 'checked' : ''; ?>> Today</label>
                            <label style="margin-right: 10px;"><input type="radio" name="date_filter" value="yesterday" <?php echo ($pageData['currentFilter'] === 'yesterday') ? 'checked' : ''; ?>> Yesterday</label>
                            <label style="margin-right: 10px;"><input type="radio" name="date_filter" value="last_7_days" <?php echo ($pageData['currentFilter'] === 'last_7_days') ? 'checked' : ''; ?>> Last 7 days</label>
                            <label style="margin-right: 10px;"><input type="radio" name="date_filter" value="last_30_days" <?php echo ($pageData['currentFilter'] === 'last_30_days') ? 'checked' : ''; ?>> Last 30 days</label>
                            <label style="margin-right: 10px;"><input type="radio" name="date_filter" value="custom" <?php echo ($pageData['currentFilter'] === 'custom') ? 'checked' : ''; ?>> Custom</label>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($pageData['startDate']); ?>" style="padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                            <span>to</span>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($pageData['endDate']); ?>" style="padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                        <button type="submit" class="btn btn-sm">Apply Filter</button>
                    </form>
                    
                  <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <!-- Botón existente FB (30 días) -->
                        <form method="POST">
                            <input type="hidden" name="action" value="sync_single_campaign">
                            <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaignId); ?>">
                            <button type="submit" class="btn btn-sm btn-secondary">Sync FB Costs (30 days)</button>
                        </form>
                        
                        <!-- Botón FB Full Sync (si ya lo tienes implementado) -->
                        <?php if (method_exists($syncService, 'syncCampaignCostsWithChunks')): ?>
                        <form method="POST" onsubmit="return confirmFullSync()">
                            <input type="hidden" name="action" value="sync_full_campaign">
                            <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaignId); ?>">
                            <button type="submit" class="btn btn-sm" style="background: #e67e22; color: white;">
                                Full Sync FB (Since Creation)
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <!-- Botón existente Voluum (30 días) -->
                        <form method="POST">
                            <input type="hidden" name="action" value="sync_voluum_campaign">
                            <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaignId); ?>">
                            <button type="submit" class="btn btn-sm">Sync Voluum (30 days)</button>
                        </form>
                        
                        <!-- NUEVO: Botón Voluum Full Sync -->
                        <form method="POST" onsubmit="return confirmVoluumFullSync()">
                            <input type="hidden" name="action" value="sync_voluum_full">
                            <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaignId); ?>">
                            <button type="submit" class="btn btn-sm" style="background: #9b59b6; color: white;">
                                Full Sync Voluum (1 Year Max)
                            </button>
                        </form>
                    </div>

                </div>

                <!-- Información adicional sobre la campaña -->
                <?php if (isset($pageData['campaign']['created_time'])): ?>
                <div style="font-size: 12px; color: #666; margin-bottom: 15px;">
                    Campaign created: <?php echo date('M d, Y', strtotime($pageData['campaign']['created_time'])); ?>
                    (<?php 
                        $daysSince = ceil((time() - strtotime($pageData['campaign']['created_time'])) / (60*60*24));
                        echo $daysSince . ' days ago';
                    ?>)
                </div>
                <?php endif; ?>

                <script>
                function confirmFullSync() {
                    <?php if (isset($pageData['campaign']['created_time'])): ?>
                        <?php 
                            $createdDate = date('M d, Y', strtotime($pageData['campaign']['created_time']));
                            $daysSince = ceil((time() - strtotime($pageData['campaign']['created_time'])) / (60*60*24));
                        ?>
                        return confirm('This will sync ALL Facebook costs since campaign creation (<?php echo $createdDate; ?> - <?php echo $daysSince; ?> days).\n\nThis may take several minutes. Continue?');
                    <?php else: ?>
                        return confirm('This will sync ALL Facebook costs since campaign creation.\n\nThis may take several minutes. Continue?');
                    <?php endif; ?>
                }
				function confirmVoluumFullSync() {
    <?php if (isset($pageData['campaign']['created_time'])): ?>
        <?php 
            $createdDate = date('M d, Y', strtotime($pageData['campaign']['created_time']));
            $daysSince = ceil((time() - strtotime($pageData['campaign']['created_time'])) / (60*60*24));
            $maxDays = min($daysSince, 365);
        ?>
        return confirm('This will sync ALL Voluum data since campaign creation (<?php echo $createdDate; ?> - max <?php echo $maxDays; ?> days).\n\nThis process will run in chunks and may take 5-10 minutes. Continue?');
    <?php else: ?>
        return confirm('This will sync ALL Voluum data for the last year (365 days max).\n\nThis process will run in chunks and may take 5-10 minutes. Continue?');
    <?php endif; ?>
}
                </script>
				
             
                
                <h3 style="margin-top: 20px;">Ads Performance</h3>
                <table>
                    <thead>
                        <tr><th>Ad Name</th><th>AdSet</th><th>Status</th><th>Spend</th><th>Clicks</th><th>CPC</th><th>Voluum Checkouts</th><th>Voluum Conv.</th><th>CPA</th><th>Voluum Rev.</th><th>Voluum ROI</th></tr>
                    </thead>

		<tbody>
                        <?php if (!empty($pageData['ads'])): ?>
                            <?php foreach ($pageData['ads'] as $ad): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($ad['name']); ?></strong><br>
                                        
                                    </td>
                                    <td><?php echo htmlspecialchars($ad['adset_name']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($ad['status']); ?>">
                                            <?php echo htmlspecialchars($ad['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatCurrency($ad['total_spend'], $pageData['campaign']['currency']); ?></td>
                                    <td><?php echo formatNumber($ad['total_clicks']); ?></td>
                                    <td><?php echo $ad['cpc'] > 0 ? formatCurrency($ad['cpc'], $pageData['campaign']['currency']) : '-'; ?></td>
                                    <td><?php echo formatNumber($ad['voluum_checkouts']); ?></td>
                                    <td><?php echo formatNumber($ad['voluum_conversions']); ?></td>
                                    
                                    <td>
                                        <?php
                                        $cpa = 0;
                                        // Nos aseguramos de no dividir por cero
                                        if ($ad['voluum_conversions'] > 0) {
                                            $cpa = $ad['total_spend'] / $ad['voluum_conversions'];
                                        }
                                        echo $cpa > 0 ? formatCurrency($cpa, $pageData['campaign']['currency']) : '-';
                                        ?>
                                    </td>
                                    
                                    <td><?php echo formatCurrency($ad['voluum_revenue'], $pageData['campaign']['currency']); ?></td>
                                    <td>
                                        <?php 
                                        if ($ad['total_spend'] > 0) {
                                            $roi = (($ad['voluum_revenue'] - $ad['total_spend']) / $ad['total_spend']) * 100;
                                            $class = $roi >= 0 ? 'trend-up' : 'trend-down';
                                            echo '<span class="' . $class . '">' . number_format($roi, 1) . '%</span>';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11" style="text-align: center; color: #666;">No ads found for this campaign</td></tr>
                        <?php endif; ?>
                    </tbody>
                    



                </table>
            </div>
        <?php endif; ?>
    </main>

    <div id="mappingModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); justify-content: center; align-items: center;">
        <div style="background-color: #fefefe; margin: auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 8px; box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="font-size: 20px;">Mapear Campaña</h2>
                <span onclick="closeMappingModal()" style="color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
            </div>
            <p style="margin-bottom: 10px;">Campaña de Facebook: <strong id="modalFbCampaignName"></strong></p>
            <form method="POST">
                <input type="hidden" name="action" value="map_campaign">
                <input type="hidden" name="facebook_campaign_id" id="modalFbCampaignId">
                <div style="margin-bottom: 15px;">
                    <label for="voluum_campaign_id" style="display: block; margin-bottom: 5px;">ID de Campaña de Voluum:</label>
                    <input type="text" id="voluum_campaign_id" name="voluum_campaign_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <button type="submit" class="btn">Guardar Mapeo</button>
            </form>
        </div>
    </div>
    
    <script>
        setTimeout(function() { location.reload(); }, 300000);
        
        document.addEventListener('DOMContentLoaded', function() {
            const radioButtons = document.querySelectorAll('input[name="date_filter"]');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            function toggleDateInputs() {
                const isCustom = document.querySelector('input[name="date_filter"]:checked')?.value === 'custom';
                if (startDateInput && endDateInput) {
                    startDateInput.disabled = !isCustom;
                    endDateInput.disabled = !isCustom;
                    startDateInput.style.opacity = isCustom ? '1' : '0.5';
                    endDateInput.style.opacity = isCustom ? '1' : '0.5';
                }
            }
            radioButtons.forEach(radio => { radio.addEventListener('change', toggleDateInputs); });
            toggleDateInputs();
        });

        const modal = document.getElementById('mappingModal');
        const modalFbCampaignName = document.getElementById('modalFbCampaignName');
        const modalFbCampaignId = document.getElementById('modalFbCampaignId');
        function openMappingModal(fbCampaignId, fbCampaignName) {
            modalFbCampaignName.textContent = fbCampaignName;
            modalFbCampaignId.value = fbCampaignId;
            modal.style.display = 'flex';
        }
        function closeMappingModal() { modal.style.display = 'none'; }
        window.onclick = function(event) { if (event.target == modal) { closeMappingModal(); } }
    </script>
</body>
</html>