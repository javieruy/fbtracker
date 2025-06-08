<?php
/**
 * Página principal del Facebook Tracker
 * 
 * Dashboard principal con selección de cuentas y vista general
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
                    $result = $syncService->syncAccountCampaigns($accountId, true, 7);
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
        // Obtener resumen de cuentas
        $pageData['accounts'] = $db->fetchAll("
            SELECT * FROM account_summary 
            ORDER BY total_spend_today DESC
        ");
        
        // Obtener última sincronización
        $pageData['lastSync'] = $syncService->getLastSyncStats();
        
        // Estadísticas generales
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
            // Obtener información de la cuenta
            $pageData['account'] = $db->fetchOne("
                SELECT * FROM ad_accounts WHERE id = ?
            ", [$accountId]);
            
            // Obtener campañas con métricas
            $pageData['campaigns'] = $db->fetchAll("
                SELECT 
                    c.*,
                    fc.spend as spend_today,
                    fc.impressions as impressions_today,
                    fc.clicks as clicks_today,
                    fc.cpm,
                    fc.cpc,
                    COALESCE(conv.conversions_today, 0) as conversions_today,
                    COALESCE(conv.revenue_today, 0) as revenue_today
                FROM campaigns c
                LEFT JOIN facebook_costs fc ON c.id = fc.entity_id 
                    AND fc.entity_type = 'campaign' 
                    AND fc.date = CURDATE()
                LEFT JOIN (
                    SELECT 
                        campaign_id,
                        COUNT(*) as conversions_today,
                        SUM(revenue) as revenue_today
                    FROM conversions
                    WHERE DATE(conversion_date) = CURDATE()
                    GROUP BY campaign_id
                ) conv ON c.id = conv.campaign_id
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
            // Obtener información de la campaña
            $pageData['campaign'] = $db->fetchOne("
                SELECT c.*, aa.name as account_name, aa.currency
                FROM campaigns c
                JOIN ad_accounts aa ON c.account_id = aa.id
                WHERE c.id = ?
            ", [$campaignId]);
            
            // Determinar el rango de fechas según el filtro
            switch ($dateFilter) {
                case 'yesterday':
                    $dateCondition = "fc.date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                    break;
                case 'last_7_days':
                    $dateCondition = "fc.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND fc.date <= CURDATE()";
                    break;
                case 'last_30_days':
                    $dateCondition = "fc.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND fc.date <= CURDATE()";
                    break;
                case 'custom':
                    if ($startDate && $endDate) {
                        $dateCondition = "fc.date >= '$startDate' AND fc.date <= '$endDate'";
                    } else {
                        $dateCondition = "fc.date = CURDATE()";
                    }
                    break;
                case 'today':
                default:
                    $dateCondition = "fc.date = CURDATE()";
                    break;
            }
            
            // Obtener ads con rendimiento agregado según el filtro de fecha
            $pageData['ads'] = $db->fetchAll("
                SELECT 
                    a.id,
                    a.name,
                    a.status,
                    ads.name as adset_name,
                    COALESCE(SUM(fc.spend), 0) as total_spend,
                    COALESCE(SUM(fc.impressions), 0) as total_impressions,
                    COALESCE(SUM(fc.clicks), 0) as total_clicks,
                    CASE 
                        WHEN SUM(fc.impressions) > 0 
                        THEN (SUM(fc.spend) / SUM(fc.impressions)) * 1000 
                        ELSE 0 
                    END as cpm,
                    CASE 
                        WHEN SUM(fc.clicks) > 0 
                        THEN SUM(fc.spend) / SUM(fc.clicks) 
                        ELSE 0 
                    END as cpc,
                    COALESCE(COUNT(DISTINCT ch.checkout_id), 0) as checkouts,
                    COALESCE(COUNT(DISTINCT co.conversion_id), 0) as conversions,
                    COALESCE(SUM(co.revenue), 0) as revenue
                FROM ads a
                JOIN adsets ads ON a.adset_id = ads.id
                LEFT JOIN facebook_costs fc ON a.id = fc.entity_id 
                    AND fc.entity_type = 'ad' 
                    AND $dateCondition
                LEFT JOIN checkouts ch ON a.id = ch.ad_id 
                    AND DATE(ch.checkout_date) >= (
                        CASE 
                            WHEN '$dateFilter' = 'yesterday' THEN DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                            WHEN '$dateFilter' = 'last_7_days' THEN DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                            WHEN '$dateFilter' = 'last_30_days' THEN DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                            WHEN '$dateFilter' = 'custom' AND '$startDate' != '' THEN '$startDate'
                            ELSE CURDATE()
                        END
                    )
                    AND DATE(ch.checkout_date) <= (
                        CASE 
                            WHEN '$dateFilter' = 'yesterday' THEN DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                            WHEN '$dateFilter' = 'custom' AND '$endDate' != '' THEN '$endDate'
                            ELSE CURDATE()
                        END
                    )
                LEFT JOIN conversions co ON a.id = co.ad_id 
                    AND DATE(co.conversion_date) >= (
                        CASE 
                            WHEN '$dateFilter' = 'yesterday' THEN DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                            WHEN '$dateFilter' = 'last_7_days' THEN DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                            WHEN '$dateFilter' = 'last_30_days' THEN DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                            WHEN '$dateFilter' = 'custom' AND '$startDate' != '' THEN '$startDate'
                            ELSE CURDATE()
                        END
                    )
                    AND DATE(co.conversion_date) <= (
                        CASE 
                            WHEN '$dateFilter' = 'yesterday' THEN DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                            WHEN '$dateFilter' = 'custom' AND '$endDate' != '' THEN '$endDate'
                            ELSE CURDATE()
                        END
                    )
                WHERE a.campaign_id = ?
                GROUP BY a.id, a.name, a.status, ads.name
                ORDER BY COALESCE(SUM(fc.spend), 0) DESC
            ", [$campaignId]);
            
            // Guardar filtros actuales
            $pageData['currentFilter'] = $dateFilter;
            $pageData['startDate'] = $startDate;
            $pageData['endDate'] = $endDate;
        }
        break;
}

// Función helper para formatear números
function formatNumber($number, $decimals = 0) {
    return number_format($number, $decimals, '.', ',');
}

// Función helper para formatear moneda
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
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .header {
            background: #1877f2;
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 500;
        }
        
        .alert {
            padding: 12px 16px;
            margin: 20px 0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .card h2 {
            font-size: 20px;
            margin-bottom: 16px;
            color: #1a1a1a;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 6px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #1877f2;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 4px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            background: #1877f2;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #166fe5;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .sync-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 12px;
            background: #e3f2fd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-paused { background: #fff3cd; color: #856404; }
        .status-archived { background: #f8d7da; color: #721c24; }
        
        .breadcrumb {
            margin: 20px 0;
            font-size: 14px;
        }
        
        .breadcrumb a {
            color: #1877f2;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #1877f2;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .metric-trend {
            font-size: 12px;
            margin-left: 8px;
        }
        
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
            <!-- Dashboard Principal -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $pageData['stats']['total_accounts'] ?? 0; ?></div>
                    <div class="stat-label">Ad Accounts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $pageData['stats']['total_campaigns'] ?? 0; ?></div>
                    <div class="stat-label">Total Campaigns</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $pageData['stats']['mapped_campaigns'] ?? 0; ?></div>
                    <div class="stat-label">Mapped to Voluum</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo formatCurrency($pageData['stats']['total_spend_today'] ?? 0); ?></div>
                    <div class="stat-label">Spend Today</div>
                </div>
            </div>
            
            <!-- Siempre mostrar el área de sincronización -->
            <div class="sync-info">
                <span>
                    <?php if ($pageData['lastSync']): ?>
                        Last sync: <?php echo date('M d, Y H:i', strtotime($pageData['lastSync']['date'])); ?>
                        (<?php echo $pageData['lastSync']['records_processed']; ?> records)
                    <?php else: ?>
                        No previous sync found
                    <?php endif; ?>
                </span>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="sync_accounts">
                    <button type="submit" class="btn btn-sm">Sync All Accounts</button>
                </form>
            </div>
            
            <div class="card">
                <h2>Ad Accounts</h2>
                <?php if (empty($pageData['accounts'])): ?>
                    <div class="empty-state">
                        <p>No accounts found. Click the "Sync All Accounts" button above to fetch from Facebook.</p>
                        <form method="POST" style="margin-top: 20px;">
                            <input type="hidden" name="action" value="sync_accounts">
                            <button type="submit" class="btn">Sync All Accounts Now</button>
                        </form>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Account Name</th>
                                <th>Status</th>
                                <th>Campaigns</th>
                                <th>Spend Today</th>
                                <th>Last Sync</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pageData['accounts'] as $account): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($account['account_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($account['account_id']); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-active">Active</span>
                                    </td>
                                    <td>
                                        <?php echo $account['total_campaigns']; ?>
                                        <?php if ($account['mapped_campaigns'] > 0): ?>
                                            <small>(<?php echo $account['mapped_campaigns']; ?> mapped)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($account['total_spend_today'] ?? 0, $account['currency']); ?></td>
                                    <td>
                                        <?php if ($account['last_synced_at']): ?>
                                            <?php echo date('H:i', strtotime($account['last_synced_at'])); ?>
                                        <?php else: ?>
                                            Never
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="?action=campaigns&account_id=<?php echo urlencode($account['account_id']); ?>" 
                                           class="btn btn-sm">View Campaigns</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
        <?php elseif ($action === 'campaigns' && isset($pageData['account'])): ?>
            <!-- Vista de Campañas -->
            <div class="breadcrumb">
                <a href="index.php">Dashboard</a> / 
                <?php echo htmlspecialchars($pageData['account']['name']); ?>
            </div>
            
            <div class="sync-info">
                <span>
                    Account: <?php echo htmlspecialchars($pageData['account']['name']); ?>
                    (<?php echo count($pageData['campaigns']); ?> campaigns)
                </span>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="sync_campaigns">
                    <input type="hidden" name="account_id" value="<?php echo htmlspecialchars($pageData['account']['id']); ?>">
                    <button type="submit" class="btn btn-sm">Sync Campaigns</button>
                </form>
            </div>
            
            <div class="card">
                <h2>Campaigns</h2>
                <?php if (empty($pageData['campaigns'])): ?>
                    <div class="empty-state">
                        <p>No campaigns found. Click "Sync Campaigns" to fetch from Facebook.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Campaign Name</th>
                                <th>Status</th>
                                <th>Voluum</th>
                                <th>Spend Today</th>
                                <th>Clicks</th>
                                <th>CPC</th>
                                <th>Conversions</th>
                                <th>Revenue</th>
                                <th>ROI</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pageData['campaigns'] as $campaign): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($campaign['name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($campaign['id']); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($campaign['status']); ?>">
                                            <?php echo htmlspecialchars($campaign['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($campaign['is_mapped']): ?>
                                            <span class="status-badge status-active">Mapped</span>
                                        <?php else: ?>
                                            <span class="status-badge">Not Mapped</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($campaign['spend_today'] ?? 0); ?></td>
                                    <td><?php echo formatNumber($campaign['clicks_today'] ?? 0); ?></td>
                                    <td><?php echo $campaign['cpc'] ? formatCurrency($campaign['cpc']) : '-'; ?></td>
                                    <td><?php echo formatNumber($campaign['conversions_today']); ?></td>
                                    <td><?php echo formatCurrency($campaign['revenue_today']); ?></td>
                                    <td>
                                        <?php 
                                        if ($campaign['spend_today'] > 0) {
                                            $roi = (($campaign['revenue_today'] - $campaign['spend_today']) / $campaign['spend_today']) * 100;
                                            $class = $roi >= 0 ? 'trend-up' : 'trend-down';
                                            echo '<span class="' . $class . '">' . number_format($roi, 1) . '%</span>';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a href="?action=campaign_details&campaign_id=<?php echo urlencode($campaign['id']); ?>" 
                                           class="btn btn-sm">Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
        <?php elseif ($action === 'campaign_details' && isset($pageData['campaign'])): ?>
            <!-- Detalles de Campaña -->
            <div class="breadcrumb">
                <a href="index.php">Dashboard</a> / 
                <a href="?action=campaigns&account_id=<?php echo urlencode($pageData['campaign']['account_id']); ?>">
                    <?php echo htmlspecialchars($pageData['campaign']['account_name']); ?>
                </a> / 
                <?php echo htmlspecialchars($pageData['campaign']['name']); ?>
            </div>
            
            <div class="card">
                <h2><?php echo htmlspecialchars($pageData['campaign']['name']); ?></h2>
                
                <!-- Filtros de fecha -->
                <div style="background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px;">
                    <form method="GET" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="action" value="campaign_details">
                        <input type="hidden" name="campaign_id" value="<?php echo htmlspecialchars($campaignId); ?>">
                        
                        <div>
                            <label style="margin-right: 10px;">
                                <input type="radio" name="date_filter" value="today" 
                                    <?php echo ($pageData['currentFilter'] === 'today') ? 'checked' : ''; ?>>
                                Today
                            </label>
                            <label style="margin-right: 10px;">
                                <input type="radio" name="date_filter" value="yesterday" 
                                    <?php echo ($pageData['currentFilter'] === 'yesterday') ? 'checked' : ''; ?>>
                                Yesterday
                            </label>
                            <label style="margin-right: 10px;">
                                <input type="radio" name="date_filter" value="last_7_days" 
                                    <?php echo ($pageData['currentFilter'] === 'last_7_days') ? 'checked' : ''; ?>>
                                Last 7 days
                            </label>
                            <label style="margin-right: 10px;">
                                <input type="radio" name="date_filter" value="last_30_days" 
                                    <?php echo ($pageData['currentFilter'] === 'last_30_days') ? 'checked' : ''; ?>>
                                Last 30 days
                            </label>
                            <label style="margin-right: 10px;">
                                <input type="radio" name="date_filter" value="custom" 
                                    <?php echo ($pageData['currentFilter'] === 'custom') ? 'checked' : ''; ?>>
                                Custom
                            </label>
                        </div>
                        
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="date" name="start_date" id="start_date" 
                                value="<?php echo htmlspecialchars($pageData['startDate']); ?>"
                                style="padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                            <span>to</span>
                            <input type="date" name="end_date" id="end_date" 
                                value="<?php echo htmlspecialchars($pageData['endDate']); ?>"
                                style="padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                        </div>
                        
                        <button type="submit" class="btn btn-sm">Apply Filter</button>
                    </form>
                </div>
                
                <h3 style="margin-top: 20px;">Ads Performance</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Ad Name</th>
                            <th>AdSet</th>
                            <th>Status</th>
                            <th>Spend</th>
                            <th>Impressions</th>
                            <th>Clicks</th>
                            <th>CPM</th>
                            <th>CPC</th>
                            <th>Checkouts</th>
                            <th>Conversions</th>
                            <th>Revenue</th>
                            <th>ROI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($pageData['ads'])): ?>
                            <?php foreach ($pageData['ads'] as $ad): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($ad['name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($ad['id']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($ad['adset_name']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($ad['status']); ?>">
                                            <?php echo htmlspecialchars($ad['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatCurrency($ad['total_spend'], $pageData['campaign']['currency']); ?></td>
                                    <td><?php echo formatNumber($ad['total_impressions']); ?></td>
                                    <td><?php echo formatNumber($ad['total_clicks']); ?></td>
                                    <td><?php echo $ad['cpm'] > 0 ? formatCurrency($ad['cpm'], $pageData['campaign']['currency']) : '-'; ?></td>
                                    <td><?php echo $ad['cpc'] > 0 ? formatCurrency($ad['cpc'], $pageData['campaign']['currency']) : '-'; ?></td>
                                    <td><?php echo formatNumber($ad['checkouts']); ?></td>
                                    <td><?php echo formatNumber($ad['conversions']); ?></td>
                                    <td><?php echo formatCurrency($ad['revenue'], $pageData['campaign']['currency']); ?></td>
                                    <td>
                                        <?php 
                                        if ($ad['total_spend'] > 0) {
                                            $roi = (($ad['revenue'] - $ad['total_spend']) / $ad['total_spend']) * 100;
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
                            <tr>
                                <td colspan="12" style="text-align: center; color: #666;">No ads found for this campaign</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
        <?php endif; ?>
    </main>
    
    <script>
        // Auto-refresh cada 5 minutos
        setTimeout(function() {
            location.reload();
        }, 300000);
        
        // Habilitar calendarios solo cuando se selecciona "Custom"
        document.addEventListener('DOMContentLoaded', function() {
            const radioButtons = document.querySelectorAll('input[name="date_filter"]');
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');
            
            function toggleDateInputs() {
                const isCustom = document.querySelector('input[name="date_filter"]:checked')?.value === 'custom';
                if (startDate && endDate) {
                    startDate.disabled = !isCustom;
                    endDate.disabled = !isCustom;
                    startDate.style.opacity = isCustom ? '1' : '0.5';
                    endDate.style.opacity = isCustom ? '1' : '0.5';
                }
            }
            
            radioButtons.forEach(radio => {
                radio.addEventListener('change', toggleDateInputs);
            });
            
            // Estado inicial
            toggleDateInputs();
        });
    </script>
</body>
</html>