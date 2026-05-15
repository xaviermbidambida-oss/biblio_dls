-- ╔══════════════════════════════════════════════════════════════╗
-- ║ DIGITAL LIBRARY SYSTEM — FIX NOTIFICATIONS COMPLET          ║
-- ║ Corrige :                                                   ║
-- ║  • Unknown column 'key'                                     ║
-- ║  • Unknown column 'title'                                   ║
-- ║  • Unknown column 'is_read'                                 ║
-- ║  • Problèmes d’index notifications                          ║
-- ║  • Compatibilité ancien/nouveau système                     ║
-- ╚══════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- 1. TABLE SETTINGS
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    setting_group VARCHAR(50) DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Corriger ancienne colonne `key`
SET @key_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'settings'
    AND COLUMN_NAME = 'key'
);

SET @sql_key = IF(
    @key_exists > 0,
    'ALTER TABLE settings CHANGE `key` `setting_key` VARCHAR(100) NOT NULL',
    'SELECT 1'
);

PREPARE stmt_key FROM @sql_key;
EXECUTE stmt_key;
DEALLOCATE PREPARE stmt_key;

-- Corriger ancienne colonne `value`
SET @value_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'settings'
    AND COLUMN_NAME = 'value'
);

SET @sql_value = IF(
    @value_exists > 0,
    'ALTER TABLE settings CHANGE `value` `setting_value` TEXT',
    'SELECT 1'
);

PREPARE stmt_value FROM @sql_value;
EXECUTE stmt_value;
DEALLOCATE PREPARE stmt_value;

-- Ajouter colonnes manquantes
ALTER TABLE settings
ADD COLUMN IF NOT EXISTS setting_group VARCHAR(50) DEFAULT 'general';

ALTER TABLE settings
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE settings
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
ON UPDATE CURRENT_TIMESTAMP;

-- ─────────────────────────────────────────────────────────────
-- 2. TABLE NOTIFICATIONS
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NULL,

    type VARCHAR(50) NOT NULL DEFAULT 'info',

    title VARCHAR(255) NOT NULL DEFAULT '',

    message TEXT NULL,

    icon VARCHAR(10) DEFAULT '🔔',

    is_read TINYINT(1) NOT NULL DEFAULT 0,

    is_archived TINYINT(1) NOT NULL DEFAULT 0,

    bg VARCHAR(80) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    read_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- 3. AJOUTER COLONNES MANQUANTES SI TABLE EXISTE DÉJÀ
-- ─────────────────────────────────────────────────────────────

ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS title VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS message TEXT NULL;

ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS icon VARCHAR(10) DEFAULT '🔔';

ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS is_read TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS bg VARCHAR(80) NULL;

ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS read_at TIMESTAMP NULL;

-- Anciennes colonnes compatibilité
ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS titre VARCHAR(255) NULL;

ALTER TABLE notifications
ADD COLUMN IF NOT EXISTS lu TINYINT(1) NOT NULL DEFAULT 0;

-- ─────────────────────────────────────────────────────────────
-- 4. SYNCHRONISATION ANCIENNES → NOUVELLES COLONNES
-- ─────────────────────────────────────────────────────────────

-- Copier titre → title
UPDATE notifications
SET title = titre
WHERE
    (title IS NULL OR title = '')
    AND titre IS NOT NULL
    AND titre <> '';

-- Copier lu → is_read
UPDATE notifications
SET is_read = lu
WHERE lu IS NOT NULL;

-- ─────────────────────────────────────────────────────────────
-- 5. INDEXES
-- ─────────────────────────────────────────────────────────────

-- Vérifier index user
SET @idx_user = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notifications'
    AND INDEX_NAME = 'idx_notif_user'
);

SET @sql_idx_user = IF(
    @idx_user = 0,
    'ALTER TABLE notifications ADD INDEX idx_notif_user (user_id)',
    'SELECT 1'
);

PREPARE stmt_idx_user FROM @sql_idx_user;
EXECUTE stmt_idx_user;
DEALLOCATE PREPARE stmt_idx_user;

-- Vérifier index type
SET @idx_type = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notifications'
    AND INDEX_NAME = 'idx_notif_type'
);

SET @sql_idx_type = IF(
    @idx_type = 0,
    'ALTER TABLE notifications ADD INDEX idx_notif_type (type)',
    'SELECT 1'
);

PREPARE stmt_idx_type FROM @sql_idx_type;
EXECUTE stmt_idx_type;
DEALLOCATE PREPARE stmt_idx_type;

-- Vérifier index is_read
SET @idx_read = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notifications'
    AND INDEX_NAME = 'idx_notif_read'
);

SET @sql_idx_read = IF(
    @idx_read = 0,
    'ALTER TABLE notifications ADD INDEX idx_notif_read (is_read)',
    'SELECT 1'
);

PREPARE stmt_idx_read FROM @sql_idx_read;
EXECUTE stmt_idx_read;
DEALLOCATE PREPARE stmt_idx_read;

-- Vérifier index created_at
SET @idx_created = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notifications'
    AND INDEX_NAME = 'idx_notif_created'
);

SET @sql_idx_created = IF(
    @idx_created = 0,
    'ALTER TABLE notifications ADD INDEX idx_notif_created (created_at)',
    'SELECT 1'
);

PREPARE stmt_idx_created FROM @sql_idx_created;
EXECUTE stmt_idx_created;
DEALLOCATE PREPARE stmt_idx_created;

-- ─────────────────────────────────────────────────────────────
-- 6. TABLE USER BONUS
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS user_bonus (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL UNIQUE,

    achat_count INT UNSIGNED NOT NULL DEFAULT 0,

    bonus_total INT UNSIGNED NOT NULL DEFAULT 0,

    bonus_restant INT UNSIGNED NOT NULL DEFAULT 0,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- 7. TABLE BONUS HISTORY
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS bonus_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    livre_id INT UNSIGNED NULL,

    type ENUM('gagne','utilise','expire')
        NOT NULL DEFAULT 'gagne',

    detail VARCHAR(255) NULL,

    bonus_avant INT UNSIGNED DEFAULT 0,

    bonus_apres INT UNSIGNED DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- 8. TABLE LECTURE PROGRESSION
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS lecture_progression (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    livre_id INT UNSIGNED NOT NULL,

    page_actuelle INT UNSIGNED DEFAULT 0,

    pourcentage DECIMAL(5,2) DEFAULT 0.00,

    termine TINYINT(1) DEFAULT 0,

    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_livre (user_id, livre_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- 9. TABLE FAVORITES
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS favorites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    livre_id INT UNSIGNED NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_fav (user_id, livre_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- 10. TABLE NOTIFICATION LOG
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS notification_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    notification_id INT UNSIGNED NULL,

    event VARCHAR(50) NOT NULL,

    performed_by INT UNSIGNED NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- 11. SETTINGS PAR DÉFAUT
-- ─────────────────────────────────────────────────────────────

INSERT IGNORE INTO settings
(setting_key, setting_value, setting_group)
VALUES
('site_name', 'Digital Library System', 'general'),
('primary_color', '#7c3aed', 'appearance'),
('dashboard_theme', 'dark', 'appearance'),
('bonus_rule', '5', 'loyalty'),
('notifications_email', '1', 'notifications'),
('max_file_size', '50', 'uploads');

-- ─────────────────────────────────────────────────────────────
-- 12. DONNÉES TEST NOTIFICATIONS
-- ─────────────────────────────────────────────────────────────

INSERT INTO notifications
(user_id, type, title, message, icon)
SELECT * FROM (
    SELECT
        NULL,
        'system_error',
        'Bienvenue sur DLS',
        'Système de notifications opérationnel.',
        '✅'
) AS tmp
WHERE NOT EXISTS (
    SELECT id FROM notifications
    WHERE title = 'Bienvenue sur DLS'
)
LIMIT 1;

INSERT INTO notifications
(user_id, type, title, message, icon)
SELECT * FROM (
    SELECT
        NULL,
        'admin_action',
        'Maintenance planifiée',
        'Une mise à jour aura lieu ce week-end.',
        '🔧'
) AS tmp
WHERE NOT EXISTS (
    SELECT id FROM notifications
    WHERE title = 'Maintenance planifiée'
)
LIMIT 1;

INSERT INTO notifications
(user_id, type, title, message, icon)
SELECT * FROM (
    SELECT
        NULL,
        'security_alert',
        'Connexion depuis un nouvel appareil',
        'Vérifiez vos paramètres de sécurité.',
        '🔐'
) AS tmp
WHERE NOT EXISTS (
    SELECT id FROM notifications
    WHERE title = 'Connexion depuis un nouvel appareil'
)
LIMIT 1;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────
-- FIN
-- ─────────────────────────────────────────────────────────────

SELECT 'MIGRATION TERMINÉE AVEC SUCCÈS ✅' AS status;