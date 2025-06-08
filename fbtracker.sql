-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 08, 2025 at 02:20 PM
-- Server version: 10.5.29-MariaDB-ubu2004
-- PHP Version: 8.1.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fbtracker`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`fbtrackeruser`@`%` PROCEDURE `get_account_roi_summary` (IN `p_account_id` VARCHAR(50), IN `p_start_date` DATE, IN `p_end_date` DATE)   BEGIN
    SELECT 
        aa.name as account_name,
        COUNT(DISTINCT c.id) as total_campaigns,
        SUM(fc.spend) as total_spend,
        SUM(fc.impressions) as total_impressions,
        SUM(fc.clicks) as total_clicks,
        COUNT(DISTINCT co.conversion_id) as total_conversions,
        COALESCE(SUM(co.revenue), 0) as total_revenue,
        COALESCE(SUM(co.revenue), 0) - SUM(fc.spend) as profit,
        CASE 
            WHEN SUM(fc.spend) > 0 
            THEN ((COALESCE(SUM(co.revenue), 0) - SUM(fc.spend)) / SUM(fc.spend)) * 100
            ELSE 0 
        END as roi_percentage
    FROM ad_accounts aa
    JOIN campaigns c ON aa.id = c.account_id
    JOIN facebook_costs fc ON c.id = fc.entity_id AND fc.entity_type = 'campaign'
    LEFT JOIN conversions co ON c.id = co.campaign_id 
        AND DATE(co.conversion_date) BETWEEN p_start_date AND p_end_date
    WHERE aa.id = p_account_id
        AND fc.date BETWEEN p_start_date AND p_end_date
    GROUP BY aa.name;
END$$

CREATE DEFINER=`fbtrackeruser`@`%` PROCEDURE `get_costs_by_date_range` (IN `p_entity_id` VARCHAR(50), IN `p_entity_type` ENUM('campaign','adset','ad'), IN `p_start_date` DATE, IN `p_end_date` DATE)   BEGIN
    SELECT 
        date,
        spend,
        impressions,
        clicks,
        cpm,
        cpc
    FROM facebook_costs
    WHERE entity_id = p_entity_id 
        AND entity_type = p_entity_type
        AND date BETWEEN p_start_date AND p_end_date
    ORDER BY date DESC;
END$$

CREATE DEFINER=`fbtrackeruser`@`%` PROCEDURE `get_roi_summary` (IN `p_start_date` DATE, IN `p_end_date` DATE, IN `p_entity_type` VARCHAR(20))   BEGIN
    IF p_entity_type = 'campaign' THEN
        SELECT 
            c.id,
            c.name,
            SUM(fc.spend) as total_spend,
            SUM(fc.impressions) as total_impressions,
            SUM(fc.clicks) as total_clicks,
            COUNT(DISTINCT co.conversion_id) as total_conversions,
            COALESCE(SUM(co.revenue), 0) as total_revenue,
            COALESCE(SUM(co.revenue), 0) - SUM(fc.spend) as profit,
            CASE 
                WHEN SUM(fc.spend) > 0 
                THEN ((COALESCE(SUM(co.revenue), 0) - SUM(fc.spend)) / SUM(fc.spend)) * 100
                ELSE 0 
            END as roi_percentage
        FROM campaigns c
        JOIN facebook_costs fc ON c.id = fc.entity_id AND fc.entity_type = 'campaign'
        LEFT JOIN conversions co ON c.id = co.campaign_id 
            AND DATE(co.conversion_date) BETWEEN p_start_date AND p_end_date
        WHERE fc.date BETWEEN p_start_date AND p_end_date
        GROUP BY c.id, c.name
        HAVING SUM(fc.spend) > 0
        ORDER BY roi_percentage DESC;
    END IF;
END$$

CREATE DEFINER=`fbtrackeruser`@`%` PROCEDURE `map_facebook_voluum_campaign` (IN `p_facebook_campaign_id` VARCHAR(50), IN `p_voluum_campaign_id` VARCHAR(100), IN `p_created_by` VARCHAR(100))   BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Insertar o actualizar el mapeo
    INSERT INTO campaign_mappings (
        facebook_campaign_id, 
        voluum_campaign_id, 
        created_by
    ) VALUES (
        p_facebook_campaign_id, 
        p_voluum_campaign_id, 
        p_created_by
    )
    ON DUPLICATE KEY UPDATE
        voluum_campaign_id = VALUES(voluum_campaign_id),
        mapping_status = 'active',
        updated_at = CURRENT_TIMESTAMP;
    
    -- Actualizar la tabla campaigns
    UPDATE campaigns 
    SET voluum_campaign_id = p_voluum_campaign_id,
        is_mapped = TRUE,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_facebook_campaign_id;
    
    COMMIT;
    
    SELECT 'Campaign mapped successfully' as message;
END$$

CREATE DEFINER=`fbtrackeruser`@`%` PROCEDURE `process_voluum_conversions` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_conversion_id VARCHAR(100);
    DECLARE v_voluum_campaign_id VARCHAR(100);
    DECLARE v_voluum_ad_id VARCHAR(100);
    DECLARE v_facebook_campaign_id VARCHAR(50);
    DECLARE v_facebook_ad_id VARCHAR(50);
    DECLARE v_facebook_adset_id VARCHAR(50);
    
    DECLARE cur CURSOR FOR 
        SELECT id, voluum_campaign_id, voluum_ad_id
        FROM voluum_raw_conversions
        WHERE processed = FALSE
        ORDER BY conversion_timestamp;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_conversion_id, v_voluum_campaign_id, v_voluum_ad_id;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Buscar el mapeo de campaña
        SELECT facebook_campaign_id INTO v_facebook_campaign_id
        FROM campaign_mappings
        WHERE voluum_campaign_id = v_voluum_campaign_id
        AND mapping_status = 'active'
        LIMIT 1;
        
        IF v_facebook_campaign_id IS NOT NULL THEN
            -- Buscar el ad de Facebook basado en el voluum_ad_id
            SELECT id, adset_id INTO v_facebook_ad_id, v_facebook_adset_id
            FROM ads
            WHERE campaign_id = v_facebook_campaign_id
            AND voluum_ad_id = v_voluum_ad_id
            LIMIT 1;
            
            -- Insertar la conversión con los IDs de Facebook
            INSERT INTO conversions (
                conversion_id,
                voluum_click_id,
                ad_id,
                adset_id,
                campaign_id,
                voluum_campaign_id,
                voluum_ad_id,
                revenue,
                conversion_date,
                payout
            )
            SELECT 
                voluum_conversion_id,
                click_id,
                v_facebook_ad_id,
                v_facebook_adset_id,
                v_facebook_campaign_id,
                voluum_campaign_id,
                voluum_ad_id,
                revenue,
                conversion_timestamp,
                payout
            FROM voluum_raw_conversions
            WHERE id = v_conversion_id;
            
            -- Marcar como procesada
            UPDATE voluum_raw_conversions
            SET processed = TRUE,
                processed_at = CURRENT_TIMESTAMP
            WHERE id = v_conversion_id;
        END IF;
    END LOOP;
    
    CLOSE cur;
END$$

CREATE DEFINER=`fbtrackeruser`@`%` PROCEDURE `upsert_ad_account` (IN `p_id` VARCHAR(50), IN `p_name` VARCHAR(255), IN `p_currency` VARCHAR(3), IN `p_timezone_name` VARCHAR(100), IN `p_timezone_offset_hours` INT, IN `p_account_status` INT, IN `p_business_name` VARCHAR(255), IN `p_business_id` VARCHAR(50), IN `p_amount_spent` DECIMAL(12,2), IN `p_balance` DECIMAL(12,2))   BEGIN
    INSERT INTO ad_accounts (
        id, name, currency, timezone_name, timezone_offset_hours,
        account_status, business_name, business_id, amount_spent, balance,
        last_synced_at
    ) VALUES (
        p_id, p_name, p_currency, p_timezone_name, p_timezone_offset_hours,
        p_account_status, p_business_name, p_business_id, p_amount_spent, p_balance,
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        currency = VALUES(currency),
        timezone_name = VALUES(timezone_name),
        timezone_offset_hours = VALUES(timezone_offset_hours),
        account_status = VALUES(account_status),
        business_name = VALUES(business_name),
        business_id = VALUES(business_id),
        amount_spent = VALUES(amount_spent),
        balance = VALUES(balance),
        last_synced_at = NOW(),
        updated_at = CURRENT_TIMESTAMP;
END$$

CREATE DEFINER=`fbtrackeruser`@`%` PROCEDURE `upsert_facebook_cost` (IN `p_entity_id` VARCHAR(50), IN `p_entity_type` ENUM('campaign','adset','ad'), IN `p_spend` DECIMAL(10,2), IN `p_impressions` INT UNSIGNED, IN `p_clicks` INT UNSIGNED, IN `p_date` DATE)   BEGIN
    DECLARE v_cpm DECIMAL(8, 2) DEFAULT NULL;
    DECLARE v_cpc DECIMAL(8, 2) DEFAULT NULL;
    
    -- Calcular CPM si hay impresiones
    IF p_impressions > 0 THEN
        SET v_cpm = (p_spend / p_impressions) * 1000;
    END IF;
    
    -- Calcular CPC si hay clicks
    IF p_clicks > 0 THEN
        SET v_cpc = p_spend / p_clicks;
    END IF;
    
    -- INSERT ... ON DUPLICATE KEY UPDATE para MySQL
    INSERT INTO facebook_costs (
        entity_id, entity_type, spend, impressions, clicks, 
        cpm, cpc, date
    ) VALUES (
        p_entity_id, p_entity_type, p_spend, p_impressions, p_clicks,
        v_cpm, v_cpc, p_date
    )
    ON DUPLICATE KEY UPDATE
        spend = VALUES(spend),
        impressions = VALUES(impressions),
        clicks = VALUES(clicks),
        cpm = VALUES(cpm),
        cpc = VALUES(cpc),
        updated_at = CURRENT_TIMESTAMP;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `account_summary`
-- (See below for the actual view)
--
CREATE TABLE `account_summary` (
`account_id` varchar(50)
,`account_name` varchar(255)
,`currency` varchar(3)
,`account_status` int(11)
,`total_campaigns` bigint(21)
,`mapped_campaigns` bigint(21)
,`total_adsets` bigint(21)
,`total_ads` bigint(21)
,`total_spend_today` decimal(32,2)
,`last_synced_at` timestamp
,`updated_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `ads`
--

CREATE TABLE `ads` (
  `id` varchar(50) NOT NULL,
  `adset_id` varchar(50) NOT NULL,
  `campaign_id` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `voluum_ad_id` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ads`
--

INSERT INTO `ads` (`id`, `adset_id`, `campaign_id`, `name`, `voluum_ad_id`, `status`, `created_at`, `updated_at`) VALUES
('120217274349170185', '120217274349150185', '120217274349160185', 'Wellamoon Testing 05_ADV3', NULL, 'ACTIVE', '2025-06-06 00:55:07', '2025-06-06 01:25:24'),
('120217382155830185', '120217382155840185', '120217274349160185', 'Wellamoon Testing IMG001_ADV3', NULL, 'ACTIVE', '2025-06-06 00:55:07', '2025-06-06 01:25:24'),
('120217382348510185', '120217382348520185', '120217274349160185', 'Wellamoon Testing IMG003_ADV3', NULL, 'ACTIVE', '2025-06-06 00:55:06', '2025-06-06 01:25:23'),
('120218011716440185', '120218011716430185', '120217274349160185', 'Wellamoon Testing 05_ADV4', NULL, 'ACTIVE', '2025-06-06 00:55:05', '2025-06-06 01:25:23'),
('120218012394940185', '120218012394950185', '120217274349160185', 'Wellamoon Testing 05_ADV5', NULL, 'ACTIVE', '2025-06-06 00:55:05', '2025-06-06 01:25:23'),
('120218013123970185', '120218013123980185', '120217274349160185', 'Wellamoon Testing 05_ADV6', NULL, 'ACTIVE', '2025-06-06 00:55:04', '2025-06-06 01:25:22'),
('120218208572870185', '120218208572860185', '120217274349160185', 'Wellamoon Testing IMG003_ADV4', NULL, 'ACTIVE', '2025-06-06 00:55:03', '2025-06-06 01:25:22'),
('120218209793840185', '120218209793850185', '120217274349160185', 'Wellamoon Testing IMG003_ADV5', NULL, 'ACTIVE', '2025-06-06 00:55:03', '2025-06-06 01:25:21'),
('120218210593880185', '120218210593890185', '120217274349160185', 'Wellamoon Testing IMG003_ADV6', NULL, 'ACTIVE', '2025-06-06 00:55:03', '2025-06-06 01:25:21'),
('120219270573600185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-1 | TP-4 | TT-7 | LP-8', NULL, 'ACTIVE', '2025-06-06 00:54:54', '2025-06-06 01:25:12'),
('120219281847500185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-3 | TP-4 | TT-7 | LP-8 | Ad3', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219281847510185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-2 | TP-5 | TT-7 | LP-8 | Ad5', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219281847520185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-1 | TP-6 | TT-7 | LP-8 | Ad7', NULL, 'ACTIVE', '2025-06-06 00:54:54', '2025-06-06 01:25:12'),
('120219281847530185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-3 | TP-6 | TT-7 | LP-8 | Ad9', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219281847600185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-2 | TP-4 | TT-7 | LP-8 | Ad2', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219281847610185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-1 | TP-5 | TT-7 | LP-8 | Ad4', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219281847620185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-3 | TP-5 | TT-7 | LP-8 | Ad6', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219281847630185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-2 | TP-6 | TT-7 | LP-8 | Ad8', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:12'),
('120219283683450185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-1 | TP-4 | TT-8 | LP-8 | Ad12', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:12'),
('120219283683460185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-2 | TP-4 | TT-8 | LP-8 | Ad11', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219283683470185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-1 | TP-4 | TT-8 | LP-8 | Ad10', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:12'),
('120219283907680185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-3 | TP-5 | TT-8 | LP-8 | Ad15', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:12'),
('120219283907690185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-1 | TP-5 | TT-8 | LP-8 | Ad13', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219283907700185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | OFF | CR-2 | TP-5 | TT-8 | LP-8 | Ad14', NULL, 'PAUSED', '2025-06-06 00:54:53', '2025-06-06 01:25:11'),
('120219284028810185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-1 | TP-6 | TT-8 | LP-8 | Ad16', NULL, 'ACTIVE', '2025-06-06 00:54:53', '2025-06-06 01:25:12'),
('120219284028820185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-2 | TP-6 | TT-8 | LP-8 | Ad17', NULL, 'ACTIVE', '2025-06-06 00:54:54', '2025-06-06 01:25:12'),
('120219284028830185', '120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON | ON | CR-3 | TP-6 | TT-8 | LP-8 | Ad18', NULL, 'ACTIVE', '2025-06-06 00:54:54', '2025-06-06 01:25:12'),
('120224733492390185', '120224733492230185', '120224733492130185', 'ADSET001-AD001', NULL, 'PAUSED', '2025-06-06 00:54:48', '2025-06-08 04:54:51'),
('120224734451530185', '120224734451540185', '120224733492130185', 'ADSET002-AD001', NULL, 'ACTIVE', '2025-06-06 00:54:47', '2025-06-08 04:54:51'),
('120224734772330185', '120224734772340185', '120224733492130185', 'ADSET003-AD001', NULL, 'PAUSED', '2025-06-06 00:54:47', '2025-06-08 04:54:51'),
('120225109422680185', '120225109422690185', '120224733492130185', 'ADSET004-AD001', NULL, 'ACTIVE', '2025-06-07 12:36:36', '2025-06-08 04:54:50'),
('120225109797510185', '120225109797500185', '120224733492130185', 'ADSET005-AD001', NULL, 'ACTIVE', '2025-06-07 12:36:35', '2025-06-08 04:54:50'),
('120225110852740185', '120225110852750185', '120224733492130185', 'ADSET006-AD001', NULL, 'ACTIVE', '2025-06-07 12:36:35', '2025-06-08 04:54:50'),
('120225209969300185', '120225209969310185', '120224733492130185', 'ADSET007-AD001', NULL, 'ACTIVE', '2025-06-08 04:54:49', '2025-06-08 04:54:49'),
('120225211297410185', '120225209969310185', '120224733492130185', 'ADSET007-AD002', NULL, 'ACTIVE', '2025-06-08 04:54:49', '2025-06-08 04:54:49'),
('120225211430310185', '120225209969310185', '120224733492130185', 'ADSET007-AD003', NULL, 'ACTIVE', '2025-06-08 04:54:49', '2025-06-08 04:54:49');

-- --------------------------------------------------------

--
-- Table structure for table `adsets`
--

CREATE TABLE `adsets` (
  `id` varchar(50) NOT NULL,
  `campaign_id` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `adsets`
--

INSERT INTO `adsets` (`id`, `campaign_id`, `name`, `status`, `created_at`, `updated_at`) VALUES
('120217274349150185', '120217274349160185', 'Wellamoon Testing 05_ADV3', 'PAUSED', '2025-06-06 00:55:07', '2025-06-06 01:25:24'),
('120217382155840185', '120217274349160185', 'Wellamoon Testing IMG001_ADV3', 'PAUSED', '2025-06-06 00:55:06', '2025-06-06 01:25:23'),
('120217382348520185', '120217274349160185', 'Wellamoon Testing IMG003_ADV3', 'ACTIVE', '2025-06-06 00:55:05', '2025-06-06 01:25:23'),
('120218011716430185', '120217274349160185', 'Wellamoon Testing 05_ADV4', 'PAUSED', '2025-06-06 00:55:05', '2025-06-06 01:25:23'),
('120218012394950185', '120217274349160185', 'Wellamoon Testing 05_ADV5', 'PAUSED', '2025-06-06 00:55:04', '2025-06-06 01:25:22'),
('120218013123980185', '120217274349160185', 'Wellamoon Testing 05_ADV6', 'PAUSED', '2025-06-06 00:55:03', '2025-06-06 01:25:22'),
('120218208572860185', '120217274349160185', 'Wellamoon Testing IMG003_ADV4', 'ACTIVE', '2025-06-06 00:55:03', '2025-06-06 01:25:21'),
('120218209793850185', '120217274349160185', 'Wellamoon Testing IMG003_ADV5', 'ACTIVE', '2025-06-06 00:55:03', '2025-06-06 01:25:21'),
('120218210593890185', '120217274349160185', 'Wellamoon Testing IMG003_ADV6', 'PAUSED', '2025-06-06 00:55:02', '2025-06-06 01:25:20'),
('120219270573580185', '120219270573570185', 'SANDBOX | WELLAMOON | Abierta | Adset1 | TMV | ON', 'ACTIVE', '2025-06-06 00:54:52', '2025-06-06 01:25:11'),
('120224733492230185', '120224733492130185', 'ADSET 001', 'PAUSED', '2025-06-06 00:54:47', '2025-06-08 04:54:51'),
('120224734451540185', '120224733492130185', 'ADSET 002', 'ACTIVE', '2025-06-06 00:54:47', '2025-06-08 04:54:51'),
('120224734772340185', '120224733492130185', 'ADSET 003', 'PAUSED', '2025-06-06 00:54:46', '2025-06-08 04:54:50'),
('120225109422690185', '120224733492130185', 'ADSET 004', 'PAUSED', '2025-06-07 12:36:35', '2025-06-08 04:54:50'),
('120225109797500185', '120224733492130185', 'ADSET 005', 'ACTIVE', '2025-06-07 12:36:35', '2025-06-08 04:54:50'),
('120225110852750185', '120224733492130185', 'ADSET 006', 'PAUSED', '2025-06-07 12:36:34', '2025-06-08 04:54:49'),
('120225209969310185', '120224733492130185', 'ADSET 007', 'PAUSED', '2025-06-08 04:54:49', '2025-06-08 04:54:49');

-- --------------------------------------------------------

--
-- Table structure for table `ad_accounts`
--

CREATE TABLE `ad_accounts` (
  `id` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `currency` varchar(3) DEFAULT NULL,
  `timezone_name` varchar(100) DEFAULT NULL,
  `timezone_offset_hours` int(11) DEFAULT NULL,
  `account_status` int(11) DEFAULT NULL,
  `business_name` varchar(255) DEFAULT NULL,
  `business_id` varchar(50) DEFAULT NULL,
  `amount_spent` decimal(12,2) DEFAULT 0.00,
  `balance` decimal(12,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ad_accounts`
--

INSERT INTO `ad_accounts` (`id`, `name`, `currency`, `timezone_name`, `timezone_offset_hours`, `account_status`, `business_name`, `business_id`, `amount_spent`, `balance`, `is_active`, `last_synced_at`, `created_at`, `updated_at`) VALUES
('act_1214313446330764', 'JavierAp Add Account 4', 'USD', 'America/Montevideo', -3, 1, 'Javier Apaulaza', NULL, 170841.00, 2295.00, 1, '2025-06-06 01:24:43', '2025-06-06 00:50:02', '2025-06-06 01:24:43'),
('act_1370588717662780', ' JavierAp Add Account 3', 'USD', 'America/Montevideo', -3, 1, 'Javier Apaulaza', NULL, 498114.00, 21714.00, 1, '2025-06-06 01:24:43', '2025-06-06 00:50:02', '2025-06-06 01:24:43'),
('act_1378540443110986', 'BLUEBERRY $ for (qualitasinteractive.com) 82', 'USD', 'America/New_York', -4, 1, '', NULL, 125306.00, 0.00, 1, '2025-06-06 01:24:43', '2025-06-06 00:50:02', '2025-06-06 01:24:43'),
('act_223217352222205', 'Javier Apaulaza', 'USD', 'America/Montevideo', -3, 1, '', NULL, 0.00, 0.00, 1, '2025-06-06 01:24:43', '2025-06-06 00:50:02', '2025-06-06 01:24:43'),
('act_24174913148764727', 'JavierAp Add Account 5', 'USD', 'America/Montevideo', -3, 1, '', NULL, 0.00, 0.00, 1, '2025-06-06 01:24:44', '2025-06-06 00:50:02', '2025-06-06 01:24:44'),
('act_3593657684101868', 'Add Account 1 Javier Comp 2 BM', 'USD', 'America/Montevideo', -3, 1, 'Javier Apaulaza', NULL, 11927.00, 0.00, 1, '2025-06-06 01:24:44', '2025-06-06 00:50:02', '2025-06-06 01:24:44'),
('act_368576981173158', 'JavierAp Add Account', 'USD', 'America/Montevideo', -3, 1, 'LewAR', NULL, 833267.00, 0.00, 1, '2025-06-06 01:24:43', '2025-06-06 00:50:02', '2025-06-06 01:24:43'),
('act_741300161237395', 'JavierAp Add Account 2', 'USD', 'America/Montevideo', -3, 1, 'Javier Apaulaza Avegno', NULL, 286557.00, 0.00, 1, '2025-06-06 01:24:43', '2025-06-06 00:50:02', '2025-06-06 01:24:43');

-- --------------------------------------------------------

--
-- Stand-in structure for view `ad_performance_summary`
-- (See below for the actual view)
--
CREATE TABLE `ad_performance_summary` (
`ad_id` varchar(50)
,`ad_name` varchar(255)
,`voluum_ad_id` varchar(100)
,`adset_name` varchar(255)
,`campaign_name` varchar(255)
,`voluum_campaign_id` varchar(100)
,`date` date
,`spend` decimal(10,2)
,`impressions` int(10) unsigned
,`clicks` int(10) unsigned
,`cpm` decimal(8,2)
,`cpc` decimal(8,2)
,`checkouts` bigint(21)
,`conversions` bigint(21)
,`revenue` decimal(32,2)
,`profit` decimal(33,2)
,`roi_percentage` decimal(42,6)
);

-- --------------------------------------------------------

--
-- Table structure for table `campaigns`
--

CREATE TABLE `campaigns` (
  `id` varchar(50) NOT NULL,
  `account_id` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `voluum_campaign_id` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_time` timestamp NULL DEFAULT NULL,
  `is_mapped` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `campaigns`
--

INSERT INTO `campaigns` (`id`, `account_id`, `name`, `voluum_campaign_id`, `status`, `created_time`, `is_mapped`, `created_at`, `updated_at`) VALUES
('120217273798940185', 'act_1370588717662780', 'Wellamoon Testing ADV 2', NULL, 'PAUSED', '2025-03-23 23:08:12', 0, '2025-06-06 00:55:15', '2025-06-06 23:35:27'),
('120217274349160185', 'act_1370588717662780', 'Wellamoon Testing ADV 3-4-5-6', NULL, 'PAUSED', '2025-03-23 23:29:35', 0, '2025-06-06 00:55:02', '2025-06-06 23:35:29'),
('120219270573570185', 'act_1370588717662780', 'SANDBOX | WELLAMOON | Abierta', NULL, 'PAUSED', '2025-04-06 21:08:54', 0, '2025-06-06 00:54:52', '2025-06-06 23:35:30'),
('120224733492130185', 'act_1370588717662780', 'BarxBuddy US LP 1', '9a647aa3-181a-4a68-b668-9724ba76797d', 'ACTIVE', '2025-06-01 22:31:38', 1, '2025-06-06 00:54:46', '2025-06-06 23:35:31');

-- --------------------------------------------------------

--
-- Table structure for table `campaign_mappings`
--

CREATE TABLE `campaign_mappings` (
  `id` bigint(20) NOT NULL,
  `facebook_campaign_id` varchar(50) NOT NULL,
  `voluum_campaign_id` varchar(100) NOT NULL,
  `mapping_status` enum('active','inactive','pending') DEFAULT 'active',
  `created_by` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `campaign_mappings`
--

INSERT INTO `campaign_mappings` (`id`, `facebook_campaign_id`, `voluum_campaign_id`, `mapping_status`, `created_by`, `notes`, `created_at`, `updated_at`) VALUES
(1, '120224733492130185', '9a647aa3-181a-4a68-b668-9724ba76797d', 'active', NULL, NULL, '2025-06-06 03:43:13', '2025-06-06 03:43:13');

-- --------------------------------------------------------

--
-- Stand-in structure for view `campaign_mapping_status`
-- (See below for the actual view)
--
CREATE TABLE `campaign_mapping_status` (
`facebook_campaign_id` varchar(50)
,`account_id` varchar(50)
,`account_name` varchar(255)
,`campaign_name` varchar(255)
,`voluum_campaign_id` varchar(100)
,`is_mapped` tinyint(1)
,`mapping_status` enum('active','inactive','pending')
,`total_ads` bigint(21)
,`mapped_ads` bigint(21)
,`created_at` timestamp
,`mapping_updated_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `checkouts`
--

CREATE TABLE `checkouts` (
  `id` bigint(20) NOT NULL,
  `checkout_id` varchar(100) NOT NULL,
  `voluum_click_id` varchar(100) DEFAULT NULL,
  `ad_id` varchar(50) DEFAULT NULL,
  `adset_id` varchar(50) DEFAULT NULL,
  `campaign_id` varchar(50) DEFAULT NULL,
  `voluum_campaign_id` varchar(100) DEFAULT NULL,
  `voluum_ad_id` varchar(100) DEFAULT NULL,
  `checkout_amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'USD',
  `checkout_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `visitor_id` varchar(100) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `device_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conversions`
--

CREATE TABLE `conversions` (
  `id` bigint(20) NOT NULL,
  `conversion_id` varchar(100) NOT NULL,
  `checkout_id` varchar(100) DEFAULT NULL,
  `voluum_click_id` varchar(100) DEFAULT NULL,
  `ad_id` varchar(50) DEFAULT NULL,
  `adset_id` varchar(50) DEFAULT NULL,
  `campaign_id` varchar(50) DEFAULT NULL,
  `voluum_campaign_id` varchar(100) DEFAULT NULL,
  `voluum_ad_id` varchar(100) DEFAULT NULL,
  `revenue` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'USD',
  `conversion_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `conversion_type` varchar(50) DEFAULT NULL,
  `payout` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `daily_campaign_summary`
-- (See below for the actual view)
--
CREATE TABLE `daily_campaign_summary` (
`account_id` varchar(50)
,`account_name` varchar(255)
,`campaign_id` varchar(50)
,`campaign_name` varchar(255)
,`voluum_campaign_id` varchar(100)
,`date` date
,`total_spend` decimal(10,2)
,`total_impressions` int(10) unsigned
,`total_clicks` int(10) unsigned
,`total_checkouts` bigint(21)
,`total_conversions` bigint(21)
,`total_revenue` decimal(32,2)
,`profit` decimal(33,2)
,`roi_percentage` decimal(42,6)
);

-- --------------------------------------------------------

--
-- Table structure for table `facebook_costs`
--

CREATE TABLE `facebook_costs` (
  `id` bigint(20) NOT NULL,
  `entity_id` varchar(50) NOT NULL,
  `entity_type` enum('campaign','adset','ad') NOT NULL,
  `spend` decimal(10,2) NOT NULL DEFAULT 0.00,
  `impressions` int(10) UNSIGNED DEFAULT 0,
  `clicks` int(10) UNSIGNED DEFAULT 0,
  `cpm` decimal(8,2) DEFAULT NULL,
  `cpc` decimal(8,2) DEFAULT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `facebook_costs`
--

INSERT INTO `facebook_costs` (`id`, `entity_id`, `entity_type`, `spend`, `impressions`, `clicks`, `cpm`, `cpc`, `date`, `created_at`, `updated_at`) VALUES
(1, '120224733492130185', 'campaign', 52.87, 684, 43, 77.30, 1.23, '2025-06-02', '2025-06-06 18:34:17', '2025-06-08 04:54:52'),
(2, '120224733492130185', 'campaign', 56.44, 780, 36, 72.36, 1.57, '2025-06-03', '2025-06-06 18:34:17', '2025-06-08 04:54:52'),
(3, '120224733492130185', 'campaign', 66.61, 986, 48, 67.56, 1.39, '2025-06-04', '2025-06-06 18:34:17', '2025-06-08 04:54:52'),
(4, '120224733492130185', 'campaign', 43.23, 672, 43, 64.33, 1.01, '2025-06-05', '2025-06-06 18:34:17', '2025-06-08 04:54:52'),
(5, '120224733492130185', 'campaign', 41.22, 521, 30, 79.12, 1.37, '2025-06-06', '2025-06-06 18:34:17', '2025-06-08 04:54:52'),
(6, '120224733492230185', 'adset', 17.19, 233, 20, 73.78, 0.86, '2025-06-02', '2025-06-06 18:34:17', '2025-06-08 04:54:53'),
(7, '120224733492230185', 'adset', 18.18, 313, 8, 58.08, 2.27, '2025-06-03', '2025-06-06 18:34:17', '2025-06-08 04:54:53'),
(8, '120224733492230185', 'adset', 21.77, 345, 15, 63.10, 1.45, '2025-06-04', '2025-06-06 18:34:17', '2025-06-08 04:54:53'),
(9, '120224733492390185', 'ad', 17.19, 233, 20, 73.78, 0.86, '2025-06-02', '2025-06-06 18:34:18', '2025-06-08 04:54:53'),
(10, '120224733492390185', 'ad', 18.18, 313, 8, 58.08, 2.27, '2025-06-03', '2025-06-06 18:34:18', '2025-06-08 04:54:53'),
(11, '120224733492390185', 'ad', 21.77, 345, 15, 63.10, 1.45, '2025-06-04', '2025-06-06 18:34:18', '2025-06-08 04:54:53'),
(12, '120224734451540185', 'adset', 18.49, 193, 13, 95.80, 1.42, '2025-06-02', '2025-06-06 18:34:19', '2025-06-08 04:54:54'),
(13, '120224734451540185', 'adset', 18.67, 228, 16, 81.89, 1.17, '2025-06-03', '2025-06-06 18:34:19', '2025-06-08 04:54:54'),
(14, '120224734451540185', 'adset', 22.71, 327, 21, 69.45, 1.08, '2025-06-04', '2025-06-06 18:34:19', '2025-06-08 04:54:54'),
(15, '120224734451540185', 'adset', 20.53, 368, 22, 55.79, 0.93, '2025-06-05', '2025-06-06 18:34:19', '2025-06-08 04:54:54'),
(16, '120224734451540185', 'adset', 24.96, 298, 17, 83.76, 1.47, '2025-06-06', '2025-06-06 18:34:19', '2025-06-08 04:54:54'),
(17, '120224734451530185', 'ad', 18.49, 193, 13, 95.80, 1.42, '2025-06-02', '2025-06-06 18:34:19', '2025-06-08 04:54:55'),
(18, '120224734451530185', 'ad', 18.67, 228, 16, 81.89, 1.17, '2025-06-03', '2025-06-06 18:34:19', '2025-06-08 04:54:55'),
(19, '120224734451530185', 'ad', 22.71, 327, 21, 69.45, 1.08, '2025-06-04', '2025-06-06 18:34:19', '2025-06-08 04:54:55'),
(20, '120224734451530185', 'ad', 20.53, 368, 22, 55.79, 0.93, '2025-06-05', '2025-06-06 18:34:19', '2025-06-08 04:54:55'),
(21, '120224734451530185', 'ad', 24.96, 298, 17, 83.76, 1.47, '2025-06-06', '2025-06-06 18:34:19', '2025-06-08 04:54:55'),
(22, '120224734772340185', 'adset', 17.19, 258, 10, 66.63, 1.72, '2025-06-02', '2025-06-06 18:34:20', '2025-06-08 04:54:55'),
(23, '120224734772340185', 'adset', 19.59, 239, 12, 81.97, 1.63, '2025-06-03', '2025-06-06 18:34:20', '2025-06-08 04:54:55'),
(24, '120224734772340185', 'adset', 22.13, 314, 12, 70.48, 1.84, '2025-06-04', '2025-06-06 18:34:20', '2025-06-08 04:54:55'),
(25, '120224734772340185', 'adset', 22.70, 304, 21, 74.67, 1.08, '2025-06-05', '2025-06-06 18:34:20', '2025-06-08 04:54:55'),
(26, '120224734772340185', 'adset', 16.26, 223, 13, 72.91, 1.25, '2025-06-06', '2025-06-06 18:34:20', '2025-06-08 04:54:55'),
(27, '120224734772330185', 'ad', 17.19, 258, 10, 66.63, 1.72, '2025-06-02', '2025-06-06 18:34:20', '2025-06-08 04:54:56'),
(28, '120224734772330185', 'ad', 19.59, 239, 12, 81.97, 1.63, '2025-06-03', '2025-06-06 18:34:20', '2025-06-08 04:54:56'),
(29, '120224734772330185', 'ad', 22.13, 314, 12, 70.48, 1.84, '2025-06-04', '2025-06-06 18:34:21', '2025-06-08 04:54:56'),
(30, '120224734772330185', 'ad', 22.70, 304, 21, 74.67, 1.08, '2025-06-05', '2025-06-06 18:34:21', '2025-06-08 04:54:56'),
(31, '120224734772330185', 'ad', 16.26, 223, 13, 72.91, 1.25, '2025-06-06', '2025-06-06 18:34:21', '2025-06-08 04:54:56'),
(99, '120224733492130185', 'campaign', 57.96, 826, 74, 70.17, 0.78, '2025-06-07', '2025-06-07 12:36:38', '2025-06-08 04:54:52'),
(111, '120224734451540185', 'adset', 11.03, 213, 12, 51.78, 0.92, '2025-06-07', '2025-06-07 12:36:40', '2025-06-08 04:54:54'),
(117, '120224734451530185', 'ad', 11.03, 213, 12, 51.78, 0.92, '2025-06-07', '2025-06-07 12:36:41', '2025-06-08 04:54:55'),
(128, '120225109422690185', 'adset', 13.08, 109, 5, 120.00, 2.62, '2025-06-07', '2025-06-07 12:36:42', '2025-06-08 04:54:56'),
(129, '120225109422680185', 'ad', 13.08, 109, 5, 120.00, 2.62, '2025-06-07', '2025-06-07 12:36:43', '2025-06-08 04:54:57'),
(130, '120225109797500185', 'adset', 19.44, 347, 48, 56.02, 0.41, '2025-06-07', '2025-06-07 12:36:43', '2025-06-08 04:54:57'),
(131, '120225109797510185', 'ad', 19.44, 347, 48, 56.02, 0.41, '2025-06-07', '2025-06-07 12:36:44', '2025-06-08 04:54:58'),
(132, '120225110852750185', 'adset', 14.41, 157, 9, 91.78, 1.60, '2025-06-07', '2025-06-07 12:36:44', '2025-06-08 04:54:58'),
(133, '120225110852740185', 'ad', 14.41, 157, 9, 91.78, 1.60, '2025-06-07', '2025-06-07 12:36:45', '2025-06-08 04:54:59'),
(140, '120224733492130185', 'campaign', 4.23, 112, 13, 37.77, 0.33, '2025-06-08', '2025-06-08 04:54:52', '2025-06-08 04:54:52'),
(153, '120224734451540185', 'adset', 1.52, 42, 2, 36.19, 0.76, '2025-06-08', '2025-06-08 04:54:54', '2025-06-08 04:54:54'),
(160, '120224734451530185', 'ad', 1.52, 42, 2, 36.19, 0.76, '2025-06-08', '2025-06-08 04:54:55', '2025-06-08 04:54:55'),
(174, '120225109797500185', 'adset', 2.71, 70, 11, 38.71, 0.25, '2025-06-08', '2025-06-08 04:54:57', '2025-06-08 04:54:57'),
(176, '120225109797510185', 'ad', 2.71, 70, 11, 38.71, 0.25, '2025-06-08', '2025-06-08 04:54:58', '2025-06-08 04:54:58');

-- --------------------------------------------------------

--
-- Table structure for table `sync_logs`
--

CREATE TABLE `sync_logs` (
  `id` bigint(20) NOT NULL,
  `sync_type` enum('facebook','voluum') NOT NULL,
  `sync_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `records_processed` int(11) DEFAULT 0,
  `records_created` int(11) DEFAULT 0,
  `records_updated` int(11) DEFAULT 0,
  `status` enum('running','completed','failed') NOT NULL,
  `error_message` text DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sync_logs`
--

INSERT INTO `sync_logs` (`id`, `sync_type`, `sync_date`, `records_processed`, `records_created`, `records_updated`, `status`, `error_message`, `started_at`, `completed_at`) VALUES
(1, 'facebook', '2025-06-06 00:50:02', 8, 8, 0, 'completed', NULL, '2025-06-05 21:50:01', '2025-06-05 21:50:02'),
(2, 'facebook', '2025-06-06 00:54:34', 8, 8, 0, 'completed', NULL, '2025-06-05 21:54:33', '2025-06-05 21:54:34'),
(3, 'facebook', '2025-06-06 00:55:19', 73, 73, 0, 'failed', 'User request limit reached', '2025-06-05 21:54:45', '2025-06-05 21:55:19'),
(4, 'facebook', '2025-06-06 01:24:44', 8, 8, 0, 'completed', NULL, '2025-06-05 22:24:42', '2025-06-05 22:24:44'),
(5, 'facebook', '2025-06-06 01:25:36', 73, 73, 0, 'failed', 'User request limit reached', '2025-06-05 22:25:04', '2025-06-05 22:25:36'),
(6, 'facebook', '2025-06-06 03:12:02', 13, 13, 0, 'completed', NULL, '2025-06-06 00:11:53', '2025-06-06 00:12:02'),
(7, 'facebook', '2025-06-06 03:12:45', 13, 13, 0, 'completed', NULL, '2025-06-06 00:12:37', '2025-06-06 00:12:45'),
(8, 'facebook', '2025-06-06 03:23:51', 13, 13, 0, 'completed', NULL, '2025-06-06 00:23:46', '2025-06-06 00:23:51');

-- --------------------------------------------------------

--
-- Table structure for table `voluum_conversions`
--

CREATE TABLE `voluum_conversions` (
  `id` bigint(20) NOT NULL,
  `ad_id` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `checkouts` int(11) NOT NULL DEFAULT 0,
  `conversions` int(11) NOT NULL DEFAULT 0,
  `revenue` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `voluum_conversions`
--

INSERT INTO `voluum_conversions` (`id`, `ad_id`, `date`, `checkouts`, `conversions`, `revenue`, `updated_at`) VALUES
(7, '{{ad.id}}', '2025-06-02', 1, 0, 0.0000, '2025-06-06 15:26:21'),
(8, '120224734451530185', '2025-06-03', 1, 0, 0.0000, '2025-06-06 15:26:21'),
(9, '120224733492390185', '2025-06-04', 2, 0, 0.0000, '2025-06-06 15:26:22'),
(10, '120224734451530185', '2025-06-04', 3, 0, 0.0000, '2025-06-06 15:26:22'),
(11, '120224734772330185', '2025-06-04', 1, 0, 0.0000, '2025-06-06 15:26:22'),
(12, '120224733492390185', '2025-06-05', 2, 0, 0.0000, '2025-06-06 15:26:23'),
(13, '120224734451530185', '2025-06-05', 3, 0, 0.0000, '2025-06-06 15:26:23'),
(14, '120224734772330185', '2025-06-05', 4, 0, 0.0000, '2025-06-06 15:26:23'),
(15, '{{ad.id}}', '2025-06-05', 1, 0, 0.0000, '2025-06-06 15:26:23'),
(16, '120224734451530185', '2025-06-06', 3, 0, 0.0000, '2025-06-07 12:38:41'),
(17, '120224734772330185', '2025-06-06', 3, 0, 0.0000, '2025-06-07 12:38:41'),
(30, '120224733492390185', '2025-06-02', 1, 0, 0.0000, '2025-06-06 15:47:20'),
(31, '120224734451530185', '2025-06-02', 1, 1, 42.0000, '2025-06-06 15:47:20'),
(32, '120224734772330185', '2025-06-02', 5, 0, 0.0000, '2025-06-06 15:47:20'),
(62, '120224734451530185', '2025-06-07', 3, 0, 0.0000, '2025-06-08 04:54:42'),
(78, '120225109797510185', '2025-06-07', 1, 0, 0.0000, '2025-06-08 04:54:42'),
(79, '{{ad.id}}', '2025-06-07', 1, 0, 0.0000, '2025-06-08 04:54:42'),
(80, '120224734451530185', '2025-06-08', 1, 0, 0.0000, '2025-06-08 04:54:42'),
(81, '120225109797510185', '2025-06-08', 1, 0, 0.0000, '2025-06-08 04:54:42');

-- --------------------------------------------------------

--
-- Table structure for table `voluum_raw_conversions`
--

CREATE TABLE `voluum_raw_conversions` (
  `id` bigint(20) NOT NULL,
  `voluum_conversion_id` varchar(100) NOT NULL,
  `voluum_campaign_id` varchar(100) NOT NULL,
  `voluum_ad_id` varchar(100) DEFAULT NULL,
  `click_id` varchar(100) DEFAULT NULL,
  `revenue` decimal(10,2) NOT NULL,
  `payout` decimal(10,2) DEFAULT NULL,
  `conversion_timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `country` varchar(2) DEFAULT NULL,
  `device` varchar(50) DEFAULT NULL,
  `raw_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_data`)),
  `processed` tinyint(1) DEFAULT 0,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ads`
--
ALTER TABLE `ads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_adset` (`adset_id`),
  ADD KEY `idx_campaign` (`campaign_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_voluum_id` (`voluum_ad_id`),
  ADD KEY `idx_ads_full` (`id`,`adset_id`,`campaign_id`);

--
-- Indexes for table `adsets`
--
ALTER TABLE `adsets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_campaign` (`campaign_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_adsets_full` (`id`,`campaign_id`);

--
-- Indexes for table `ad_accounts`
--
ALTER TABLE `ad_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_business` (`business_id`),
  ADD KEY `idx_last_synced` (`last_synced_at`);

--
-- Indexes for table `campaigns`
--
ALTER TABLE `campaigns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_updated` (`updated_at`),
  ADD KEY `idx_voluum_id` (`voluum_campaign_id`),
  ADD KEY `idx_is_mapped` (`is_mapped`),
  ADD KEY `idx_account` (`account_id`);

--
-- Indexes for table `campaign_mappings`
--
ALTER TABLE `campaign_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_facebook_campaign` (`facebook_campaign_id`),
  ADD UNIQUE KEY `unique_voluum_campaign` (`voluum_campaign_id`),
  ADD KEY `idx_status` (`mapping_status`);

--
-- Indexes for table `checkouts`
--
ALTER TABLE `checkouts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `checkout_id` (`checkout_id`),
  ADD KEY `adset_id` (`adset_id`),
  ADD KEY `idx_checkout_date` (`checkout_date`),
  ADD KEY `idx_campaign` (`campaign_id`),
  ADD KEY `idx_ad` (`ad_id`),
  ADD KEY `idx_voluum_campaign` (`voluum_campaign_id`),
  ADD KEY `idx_voluum_ad` (`voluum_ad_id`),
  ADD KEY `idx_checkouts_date_campaign` (`checkout_date`,`campaign_id`);

--
-- Indexes for table `conversions`
--
ALTER TABLE `conversions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `conversion_id` (`conversion_id`),
  ADD KEY `ad_id` (`ad_id`),
  ADD KEY `adset_id` (`adset_id`),
  ADD KEY `idx_conversion_date` (`conversion_date`),
  ADD KEY `idx_checkout` (`checkout_id`),
  ADD KEY `idx_campaign` (`campaign_id`),
  ADD KEY `idx_voluum_campaign` (`voluum_campaign_id`),
  ADD KEY `idx_voluum_ad` (`voluum_ad_id`),
  ADD KEY `idx_conversions_date_campaign` (`conversion_date`,`campaign_id`);

--
-- Indexes for table `facebook_costs`
--
ALTER TABLE `facebook_costs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_entity_date` (`entity_id`,`entity_type`,`date`),
  ADD KEY `idx_entity` (`entity_id`,`entity_type`),
  ADD KEY `idx_date` (`date`),
  ADD KEY `idx_date_range` (`date`,`entity_id`,`entity_type`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_facebook_costs_date_entity` (`date`,`entity_type`,`entity_id`);

--
-- Indexes for table `sync_logs`
--
ALTER TABLE `sync_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sync_type_date` (`sync_type`,`sync_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `voluum_conversions`
--
ALTER TABLE `voluum_conversions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ad_id_date` (`ad_id`,`date`);

--
-- Indexes for table `voluum_raw_conversions`
--
ALTER TABLE `voluum_raw_conversions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `voluum_conversion_id` (`voluum_conversion_id`),
  ADD KEY `idx_campaign` (`voluum_campaign_id`),
  ADD KEY `idx_processed` (`processed`),
  ADD KEY `idx_conversion_timestamp` (`conversion_timestamp`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `campaign_mappings`
--
ALTER TABLE `campaign_mappings`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `checkouts`
--
ALTER TABLE `checkouts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conversions`
--
ALTER TABLE `conversions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `facebook_costs`
--
ALTER TABLE `facebook_costs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=179;

--
-- AUTO_INCREMENT for table `sync_logs`
--
ALTER TABLE `sync_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `voluum_conversions`
--
ALTER TABLE `voluum_conversions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=177;

--
-- AUTO_INCREMENT for table `voluum_raw_conversions`
--
ALTER TABLE `voluum_raw_conversions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `account_summary`
--
DROP TABLE IF EXISTS `account_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`fbtrackeruser`@`%` SQL SECURITY DEFINER VIEW `account_summary`  AS SELECT `aa`.`id` AS `account_id`, `aa`.`name` AS `account_name`, `aa`.`currency` AS `currency`, `aa`.`account_status` AS `account_status`, count(distinct `c`.`id`) AS `total_campaigns`, count(distinct case when `c`.`is_mapped` = 1 then `c`.`id` end) AS `mapped_campaigns`, count(distinct `ads`.`id`) AS `total_adsets`, count(distinct `a`.`id`) AS `total_ads`, coalesce(sum(`fc`.`spend`),0) AS `total_spend_today`, `aa`.`last_synced_at` AS `last_synced_at`, `aa`.`updated_at` AS `updated_at` FROM ((((`ad_accounts` `aa` left join `campaigns` `c` on(`aa`.`id` = `c`.`account_id`)) left join `adsets` `ads` on(`c`.`id` = `ads`.`campaign_id`)) left join `ads` `a` on(`ads`.`id` = `a`.`adset_id`)) left join `facebook_costs` `fc` on(`c`.`id` = `fc`.`entity_id` and `fc`.`entity_type` = 'campaign' and `fc`.`date` = curdate())) WHERE `aa`.`is_active` = 1 GROUP BY `aa`.`id`, `aa`.`name`, `aa`.`currency`, `aa`.`account_status`, `aa`.`last_synced_at`, `aa`.`updated_at` ;

-- --------------------------------------------------------

--
-- Structure for view `ad_performance_summary`
--
DROP TABLE IF EXISTS `ad_performance_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`fbtrackeruser`@`%` SQL SECURITY DEFINER VIEW `ad_performance_summary`  AS SELECT `a`.`id` AS `ad_id`, `a`.`name` AS `ad_name`, `a`.`voluum_ad_id` AS `voluum_ad_id`, `ads`.`name` AS `adset_name`, `c`.`name` AS `campaign_name`, `c`.`voluum_campaign_id` AS `voluum_campaign_id`, `fc`.`date` AS `date`, `fc`.`spend` AS `spend`, `fc`.`impressions` AS `impressions`, `fc`.`clicks` AS `clicks`, `fc`.`cpm` AS `cpm`, `fc`.`cpc` AS `cpc`, count(distinct `ch`.`checkout_id`) AS `checkouts`, count(distinct `co`.`conversion_id`) AS `conversions`, coalesce(sum(`co`.`revenue`),0) AS `revenue`, coalesce(sum(`co`.`revenue`),0) - `fc`.`spend` AS `profit`, CASE WHEN `fc`.`spend` > 0 THEN (coalesce(sum(`co`.`revenue`),0) - `fc`.`spend`) / `fc`.`spend` * 100 ELSE 0 END AS `roi_percentage` FROM (((((`ads` `a` join `adsets` `ads` on(`a`.`adset_id` = `ads`.`id`)) join `campaigns` `c` on(`a`.`campaign_id` = `c`.`id`)) join `facebook_costs` `fc` on(`a`.`id` = `fc`.`entity_id` and `fc`.`entity_type` = 'ad')) left join `checkouts` `ch` on(`a`.`id` = `ch`.`ad_id` and cast(`ch`.`checkout_date` as date) = `fc`.`date`)) left join `conversions` `co` on(`a`.`id` = `co`.`ad_id` and cast(`co`.`conversion_date` as date) = `fc`.`date`)) GROUP BY `a`.`id`, `a`.`name`, `a`.`voluum_ad_id`, `ads`.`name`, `c`.`name`, `c`.`voluum_campaign_id`, `fc`.`date`, `fc`.`spend`, `fc`.`impressions`, `fc`.`clicks`, `fc`.`cpm`, `fc`.`cpc` ;

-- --------------------------------------------------------

--
-- Structure for view `campaign_mapping_status`
--
DROP TABLE IF EXISTS `campaign_mapping_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`fbtrackeruser`@`%` SQL SECURITY DEFINER VIEW `campaign_mapping_status`  AS SELECT `c`.`id` AS `facebook_campaign_id`, `c`.`account_id` AS `account_id`, `aa`.`name` AS `account_name`, `c`.`name` AS `campaign_name`, `c`.`voluum_campaign_id` AS `voluum_campaign_id`, `c`.`is_mapped` AS `is_mapped`, `cm`.`mapping_status` AS `mapping_status`, count(distinct `a`.`id`) AS `total_ads`, count(distinct case when `a`.`voluum_ad_id` is not null then `a`.`id` end) AS `mapped_ads`, `c`.`created_at` AS `created_at`, `cm`.`updated_at` AS `mapping_updated_at` FROM (((`campaigns` `c` left join `ad_accounts` `aa` on(`c`.`account_id` = `aa`.`id`)) left join `campaign_mappings` `cm` on(`c`.`id` = `cm`.`facebook_campaign_id`)) left join `ads` `a` on(`c`.`id` = `a`.`campaign_id`)) GROUP BY `c`.`id`, `c`.`account_id`, `aa`.`name`, `c`.`name`, `c`.`voluum_campaign_id`, `c`.`is_mapped`, `cm`.`mapping_status`, `c`.`created_at`, `cm`.`updated_at` ;

-- --------------------------------------------------------

--
-- Structure for view `daily_campaign_summary`
--
DROP TABLE IF EXISTS `daily_campaign_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`fbtrackeruser`@`%` SQL SECURITY DEFINER VIEW `daily_campaign_summary`  AS SELECT `c`.`account_id` AS `account_id`, `aa`.`name` AS `account_name`, `c`.`id` AS `campaign_id`, `c`.`name` AS `campaign_name`, `c`.`voluum_campaign_id` AS `voluum_campaign_id`, `fc`.`date` AS `date`, `fc`.`spend` AS `total_spend`, `fc`.`impressions` AS `total_impressions`, `fc`.`clicks` AS `total_clicks`, count(distinct `ch`.`checkout_id`) AS `total_checkouts`, count(distinct `co`.`conversion_id`) AS `total_conversions`, coalesce(sum(`co`.`revenue`),0) AS `total_revenue`, coalesce(sum(`co`.`revenue`),0) - `fc`.`spend` AS `profit`, CASE WHEN `fc`.`spend` > 0 THEN (coalesce(sum(`co`.`revenue`),0) - `fc`.`spend`) / `fc`.`spend` * 100 ELSE 0 END AS `roi_percentage` FROM ((((`campaigns` `c` left join `ad_accounts` `aa` on(`c`.`account_id` = `aa`.`id`)) join `facebook_costs` `fc` on(`c`.`id` = `fc`.`entity_id` and `fc`.`entity_type` = 'campaign')) left join `checkouts` `ch` on(`c`.`id` = `ch`.`campaign_id` and cast(`ch`.`checkout_date` as date) = `fc`.`date`)) left join `conversions` `co` on(`c`.`id` = `co`.`campaign_id` and cast(`co`.`conversion_date` as date) = `fc`.`date`)) WHERE `c`.`is_mapped` = 1 GROUP BY `c`.`account_id`, `aa`.`name`, `c`.`id`, `c`.`name`, `c`.`voluum_campaign_id`, `fc`.`date`, `fc`.`spend`, `fc`.`impressions`, `fc`.`clicks` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ads`
--
ALTER TABLE `ads`
  ADD CONSTRAINT `ads_ibfk_1` FOREIGN KEY (`adset_id`) REFERENCES `adsets` (`id`),
  ADD CONSTRAINT `ads_ibfk_2` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`);

--
-- Constraints for table `adsets`
--
ALTER TABLE `adsets`
  ADD CONSTRAINT `adsets_ibfk_1` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`);

--
-- Constraints for table `campaign_mappings`
--
ALTER TABLE `campaign_mappings`
  ADD CONSTRAINT `campaign_mappings_ibfk_1` FOREIGN KEY (`facebook_campaign_id`) REFERENCES `campaigns` (`id`);

--
-- Constraints for table `checkouts`
--
ALTER TABLE `checkouts`
  ADD CONSTRAINT `checkouts_ibfk_1` FOREIGN KEY (`ad_id`) REFERENCES `ads` (`id`),
  ADD CONSTRAINT `checkouts_ibfk_2` FOREIGN KEY (`adset_id`) REFERENCES `adsets` (`id`),
  ADD CONSTRAINT `checkouts_ibfk_3` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`);

--
-- Constraints for table `conversions`
--
ALTER TABLE `conversions`
  ADD CONSTRAINT `conversions_ibfk_1` FOREIGN KEY (`checkout_id`) REFERENCES `checkouts` (`checkout_id`),
  ADD CONSTRAINT `conversions_ibfk_2` FOREIGN KEY (`ad_id`) REFERENCES `ads` (`id`),
  ADD CONSTRAINT `conversions_ibfk_3` FOREIGN KEY (`adset_id`) REFERENCES `adsets` (`id`),
  ADD CONSTRAINT `conversions_ibfk_4` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
