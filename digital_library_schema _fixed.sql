-- ============================================================
-- DIGITAL LIBRARY SYSTEM — Schema v4.0 FINAL FIXED
-- CORRECTION : erreur SQLSTATE[42S22] colonne 'key'
-- CAUSE : `key` est un mot réservé MySQL — utiliser des
--         backticks OU renommer la colonne en setting_key
-- ============================================================

CREATE DATABASE IF NOT EXISTS digital_library
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE digital_library;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = '';

-- ────────────────────────────────────────────────────────────
-- TABLE : categories
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  nom        VARCHAR(100)     NOT NULL,
  slug       VARCHAR(100)     NOT NULL UNIQUE,
  icone      VARCHAR(10)      DEFAULT '📚',
  couleur    VARCHAR(20)      DEFAULT '#4a9eff',
  created_at TIMESTAMP        DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO categories (id, nom, slug, icone, couleur) VALUES
  (1,  'Livres',              'livres',   '📘', '#4a9eff'),
  (2,  'Journaux',            'journaux', '📰', '#ff6b6b'),
  (3,  'Récits',              'recits',   '✍️',  '#ffd93d'),
  (4,  'Œuvres',              'oeuvres',  '📖', '#6bcb77'),
  (5,  'Ouvrages',            'ouvrages', '📚', '#a78bfa'),
  (6,  'Science-Fiction',     'sf',       '🌌', '#1a4a7a'),
  (7,  'Philosophie',         'philo',    '🧠', '#4a1a7a'),
  (8,  'Nature',              'nature',   '🌿', '#1a6b3a'),
  (9,  'Technologie',         'tech',     '⚙️',  '#1a5a7a'),
  (10, 'Histoire',            'histoire', '📜', '#7a5a1a');

-- ────────────────────────────────────────────────────────────
-- TABLE : users
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  nom           VARCHAR(150)    NOT NULL,
  prenom        VARCHAR(150)    NOT NULL DEFAULT '',
  email         VARCHAR(255)    NOT NULL UNIQUE,
  password      VARCHAR(255)    NOT NULL,
  telephone     VARCHAR(20)     DEFAULT NULL,
  role          ENUM('lecteur','journaliste','admin') NOT NULL DEFAULT 'lecteur',
  statut        ENUM('actif','inactif','bloque')      NOT NULL DEFAULT 'actif',
  avatar        VARCHAR(255)    DEFAULT NULL,
  last_login    TIMESTAMP       NULL,
  last_ip       VARCHAR(45)     NULL,
  login_count   INT UNSIGNED    NOT NULL DEFAULT 0,
  two_fa_secret VARCHAR(64)     NULL,
  preferences   JSON            NULL,
  created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO users (id, nom, prenom, email, password, role, statut) VALUES
  (1, 'Système',  'Admin',     'admin@digital-library.cm', '$2y$12$placeholder000000000000000000000000000000000000000000AB', 'admin',       'actif'),
  (2, 'Reporter', 'Jean',      'jean@digital-library.cm',  '$2y$12$placeholder000000000000000000000000000000000000000000AB', 'journaliste',  'actif'),
  (3, 'Lecteur',  'Marie',     'marie@digital-library.cm', '$2y$12$placeholder000000000000000000000000000000000000000000AB', 'lecteur',      'actif');

-- ────────────────────────────────────────────────────────────
-- TABLE : livres
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS livres (
  id               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  titre            VARCHAR(255)    NOT NULL,
  auteur           VARCHAR(150)    NOT NULL,
  isbn             VARCHAR(20)     UNIQUE,
  description      TEXT,
  prix             DECIMAL(10,2)   DEFAULT 0.00,
  stock            INT             DEFAULT 100,
  categorie_id     INT UNSIGNED    DEFAULT NULL,
  couverture       VARCHAR(500)    DEFAULT NULL,
  fichier_pdf      VARCHAR(500)    DEFAULT NULL,
  annee_parution   YEAR            DEFAULT NULL,
  editeur          VARCHAR(150)    DEFAULT NULL,
  langue           VARCHAR(50)     DEFAULT 'Français',
  pages            INT             DEFAULT NULL,
  statut           ENUM('disponible','rupture','archive') DEFAULT 'disponible',
  access_type      ENUM('premium','standard','gratuit')   DEFAULT 'standard',
  note_moyenne     DECIMAL(3,2)    DEFAULT 0.00,
  nb_ventes        INT UNSIGNED    DEFAULT 0,
  nb_lectures      INT UNSIGNED    DEFAULT 0,
  nb_telechargements INT UNSIGNED  DEFAULT 0,
  nb_etoiles       INT UNSIGNED    DEFAULT 0,
  is_featured      TINYINT(1)      DEFAULT 0,
  is_bestseller    TINYINT(1)      DEFAULT 0,
  contenu_extrait  MEDIUMTEXT      DEFAULT NULL,
  ajoute_par       INT UNSIGNED    DEFAULT NULL,
  created_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (ajoute_par)   REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Indexes
CREATE INDEX IF NOT EXISTS idx_livres_statut     ON livres (statut);
CREATE INDEX IF NOT EXISTS idx_livres_categorie  ON livres (categorie_id);
CREATE INDEX IF NOT EXISTS idx_livres_prix       ON livres (prix);
CREATE INDEX IF NOT EXISTS idx_livres_note       ON livres (note_moyenne);
CREATE INDEX IF NOT EXISTS idx_livres_featured   ON livres (is_featured);
CREATE INDEX IF NOT EXISTS idx_livres_created    ON livres (created_at DESC);
ALTER TABLE livres ADD FULLTEXT IF NOT EXISTS idx_ft_livres (titre, auteur, description);

-- ────────────────────────────────────────────────────────────
-- TABLE : achats
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS achats (
  id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED    NOT NULL,
  livre_id   INT UNSIGNED    NOT NULL,
  montant    DECIMAL(10,2)   NOT NULL,
  methode    ENUM('orange_money','mobile_money','carte') DEFAULT 'orange_money',
  statut     ENUM('en_attente','confirme','echec')       DEFAULT 'confirme',
  reference  VARCHAR(50)     NOT NULL UNIQUE,
  created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_achats_user    ON achats (user_id);
CREATE INDEX IF NOT EXISTS idx_achats_livre   ON achats (livre_id);
CREATE INDEX IF NOT EXISTS idx_achats_statut  ON achats (statut);
CREATE INDEX IF NOT EXISTS idx_achats_created ON achats (created_at DESC);

-- ────────────────────────────────────────────────────────────
-- TABLE : favoris
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS favoris (
  id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED    NOT NULL,
  livre_id   INT UNSIGNED    NOT NULL,
  created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_favori (user_id, livre_id),
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- TABLE : avis
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS avis (
  id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED    NOT NULL,
  livre_id     INT UNSIGNED    NOT NULL,
  note         TINYINT UNSIGNED DEFAULT 0,
  commentaire  TEXT,
  statut       ENUM('publie','en_attente','refuse') DEFAULT 'en_attente',
  created_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- TABLE : lecture_progression
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lecture_progression (
  id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED    NOT NULL,
  livre_id      INT UNSIGNED    NOT NULL,
  page_actuelle INT             DEFAULT 1,
  pourcentage   DECIMAL(5,2)    DEFAULT 0.00,
  updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_progression (user_id, livre_id),
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- TABLE : user_downloads
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_downloads (
  id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED    NOT NULL,
  livre_id   INT UNSIGNED    NOT NULL,
  count      INT UNSIGNED    NOT NULL DEFAULT 1,
  last_dl_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dl (user_id, livre_id),
  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- TABLE : user_bonus
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_bonus (
  id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED    NOT NULL UNIQUE,
  achat_count   INT UNSIGNED    NOT NULL DEFAULT 0,
  bonus_total   INT UNSIGNED    NOT NULL DEFAULT 0,
  bonus_restant INT UNSIGNED    NOT NULL DEFAULT 0,
  updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- TABLE : settings
-- ⚠️  CORRECTION PRINCIPALE :
--     La colonne s'appelle `setting_key` (pas `key`)
--     `key` est un MOT RÉSERVÉ MySQL → cause l'erreur 1054
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  setting_key   VARCHAR(150)    NOT NULL UNIQUE  COMMENT 'Clé du paramètre (pas key — mot réservé MySQL)',
  setting_value TEXT            DEFAULT NULL,
  updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Paramètres globaux de la plateforme — colonne renommée setting_key';

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('site_name',      'Digital Library'),
  ('site_logo',      ''),
  ('primary_color',  '#00d4ff'),
  ('theme',          'dark'),
  ('language',       'fr'),
  ('currency',       'FCFA'),
  ('date_format',    'DD/MM/YYYY'),
  ('pagination',     '20'),
  ('timezone',       'Africa/Douala'),
  ('notif_enabled',  '1'),
  ('notif_sales',    '1'),
  ('notif_bonus',    '1'),
  ('notif_users',    '1'),
  ('bonus_rule',     '5'),
  ('default_access', 'paid'),
  ('max_downloads',  '3'),
  ('two_fa',         '0'),
  ('dashboard_theme','dark');

-- ────────────────────────────────────────────────────────────
-- TABLE : notifications  ← VERSION COMPLÈTE ET CORRIGÉE
-- Contient TOUS les champs nécessaires à notifications.php
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
  id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED    DEFAULT NULL       COMMENT 'NULL = notification globale pour tous',
  type       VARCHAR(50)     NOT NULL DEFAULT 'info'
               COMMENT 'sale | avis | system | info | warn | error | bonus | user | book | download | payment | login | register | block',
  titre      VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Titre court affiché en gras',
  message    TEXT            DEFAULT NULL        COMMENT 'Détail de la notification',
  icon       VARCHAR(10)     NOT NULL DEFAULT '🔔',
  bg         VARCHAR(80)     NOT NULL DEFAULT 'rgba(0,212,255,.08)',
  lu         TINYINT(1)      NOT NULL DEFAULT 0  COMMENT '0=non lu, 1=lu',
  created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user    (user_id),
  INDEX idx_notif_lu      (lu),
  INDEX idx_notif_type    (type),
  INDEX idx_notif_created (created_at DESC),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Système de notifications — compatible notifications.php';

-- ────────────────────────────────────────────────────────────
-- TABLE : admin_logs
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_logs (
  id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED    NOT NULL,
  action     VARCHAR(80)     NOT NULL,
  detail     TEXT            DEFAULT NULL,
  ip         VARCHAR(45)     DEFAULT NULL,
  user_agent VARCHAR(255)    DEFAULT NULL,
  created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_user    (user_id),
  INDEX idx_log_action  (action),
  INDEX idx_log_created (created_at DESC),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ────────────────────────────────────────────────────────────
-- TABLE : admin_whitelist
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_whitelist (
  id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(255)    NOT NULL UNIQUE,
  note       VARCHAR(255)    DEFAULT NULL,
  created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED    DEFAULT NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO admin_whitelist (email, note)
  VALUES ('admin@digital-library.cm', 'Compte système initial');

-- ────────────────────────────────────────────────────────────
-- VUE : v_settings_active
-- ⚠️  CORRECTION : référence setting_key (pas `key`)
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW v_settings_active AS
SELECT
  MAX(CASE WHEN setting_key = 'site_name'      THEN setting_value END) AS site_name,
  MAX(CASE WHEN setting_key = 'primary_color'  THEN setting_value END) AS primary_color,
  MAX(CASE WHEN setting_key = 'theme'          THEN setting_value END) AS theme,
  MAX(CASE WHEN setting_key = 'language'       THEN setting_value END) AS language,
  MAX(CASE WHEN setting_key = 'currency'       THEN setting_value END) AS currency,
  MAX(CASE WHEN setting_key = 'pagination'     THEN setting_value END) AS pagination,
  MAX(CASE WHEN setting_key = 'timezone'       THEN setting_value END) AS timezone,
  MAX(CASE WHEN setting_key = 'bonus_rule'     THEN setting_value END) AS bonus_rule,
  MAX(CASE WHEN setting_key = 'default_access' THEN setting_value END) AS default_access,
  MAX(CASE WHEN setting_key = 'max_downloads'  THEN setting_value END) AS max_downloads,
  MAX(CASE WHEN setting_key = 'notif_enabled'  THEN setting_value END) AS notif_enabled,
  MAX(CASE WHEN setting_key = 'two_fa'         THEN setting_value END) AS two_fa,
  MAX(CASE WHEN setting_key = 'dashboard_theme' THEN setting_value END) AS dashboard_theme
FROM settings;

-- ────────────────────────────────────────────────────────────
-- VUE : v_catalogue
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW v_catalogue AS
SELECT
  l.id, l.titre, l.auteur, l.isbn,
  SUBSTRING(l.description, 1, 300) AS description_courte,
  l.prix, l.access_type, l.note_moyenne, l.nb_ventes,
  l.nb_lectures, l.nb_telechargements, l.pages,
  l.annee_parution, l.editeur, l.langue, l.couverture,
  l.is_featured, l.is_bestseller, l.statut, l.created_at,
  c.id     AS categorie_id,
  c.nom    AS categorie_nom,
  c.slug   AS categorie_slug,
  c.icone  AS categorie_icone,
  c.couleur AS categorie_couleur
FROM livres l
LEFT JOIN categories c ON c.id = l.categorie_id
WHERE l.statut = 'disponible';

-- ────────────────────────────────────────────────────────────
-- TRIGGER : after_achat_confirmed
-- Met à jour user_bonus après chaque achat confirmé
-- ET insère une notification automatique
-- ⚠️  CORRECTION : utilise setting_key (pas key)
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS after_achat_confirmed;
DELIMITER $$
CREATE TRIGGER after_achat_confirmed
AFTER INSERT ON achats
FOR EACH ROW
BEGIN
  DECLARE bonus_rule_val INT DEFAULT 5;
  DECLARE current_count  INT DEFAULT 0;
  DECLARE book_title     VARCHAR(255) DEFAULT 'Livre';
  DECLARE buyer_name     VARCHAR(255) DEFAULT '';

  IF NEW.statut = 'confirme' THEN
    -- ── 1. Récupérer la règle bonus depuis settings ──────
    -- CORRECTION : setting_key (pas `key`)
    SELECT CAST(setting_value AS UNSIGNED) INTO bonus_rule_val
    FROM settings WHERE setting_key = 'bonus_rule' LIMIT 1;

    -- ── 2. Titre du livre ────────────────────────────────
    SELECT titre INTO book_title FROM livres WHERE id = NEW.livre_id LIMIT 1;

    -- ── 3. Nom de l'acheteur ────────────────────────────
    SELECT CONCAT(prenom, ' ', nom) INTO buyer_name
    FROM users WHERE id = NEW.user_id LIMIT 1;

    -- ── 4. Mettre à jour user_bonus ─────────────────────
    INSERT INTO user_bonus (user_id, achat_count, bonus_total, bonus_restant)
    VALUES (NEW.user_id, 1, 0, 0)
    ON DUPLICATE KEY UPDATE achat_count = achat_count + 1;

    SELECT achat_count INTO current_count
    FROM user_bonus WHERE user_id = NEW.user_id;

    IF current_count >= bonus_rule_val THEN
      UPDATE user_bonus
      SET achat_count   = achat_count - bonus_rule_val,
          bonus_total   = bonus_total + 1,
          bonus_restant = bonus_restant + 1
      WHERE user_id = NEW.user_id;

      -- Notification bonus déclenché
      INSERT INTO notifications (user_id, type, titre, message, icon, bg)
      VALUES (
        NEW.user_id, 'bonus',
        '🎁 Bonus fidélité débloqué !',
        CONCAT('Félicitations ', buyer_name, ' ! Vous avez obtenu un livre gratuit grâce à vos ', bonus_rule_val, ' achats.'),
        '🎁', 'rgba(0,255,170,.08)'
      );

      -- Notification admin globale pour le bonus
      INSERT INTO notifications (user_id, type, titre, message, icon, bg)
      VALUES (
        NULL, 'bonus',
        'Bonus fidélité attribué',
        CONCAT(buyer_name, ' a déclenché un bonus après ', bonus_rule_val, ' achats.'),
        '🎁', 'rgba(0,255,170,.08)'
      );
    END IF;

    -- ── 5. Incrémenter nb_ventes du livre ───────────────
    UPDATE livres SET nb_ventes = nb_ventes + 1 WHERE id = NEW.livre_id;

    -- ── 6. Notification achat → acheteur ────────────────
    INSERT INTO notifications (user_id, type, titre, message, icon, bg)
    VALUES (
      NEW.user_id, 'sale',
      'Achat confirmé',
      CONCAT('Votre achat de « ', book_title, ' » a été confirmé. Référence : ', NEW.reference),
      '🛍️', 'rgba(0,255,170,.08)'
    );

    -- ── 7. Notification achat → admin ───────────────────
    INSERT INTO notifications (user_id, type, titre, message, icon, bg)
    VALUES (
      NULL, 'sale',
      'Nouvelle vente',
      CONCAT(buyer_name, ' a acheté « ', book_title, ' » · ', FORMAT(NEW.montant, 0), ' FCFA via ', NEW.methode),
      '💰', 'rgba(0,255,170,.06)'
    );

    -- ── 8. Notification spéciale : 5e achat ─────────────
    IF current_count = 5 THEN
      INSERT INTO notifications (user_id, type, titre, message, icon, bg)
      VALUES (
        NULL, 'user',
        'Client fidèle — 5 achats',
        CONCAT(buyer_name, ' vient d''atteindre 5 achats confirmés !'),
        '🏆', 'rgba(245,158,11,.08)'
      );
    END IF;
  END IF;
END$$
DELIMITER ;

-- ────────────────────────────────────────────────────────────
-- TRIGGER : after_setting_update
-- ⚠️  CORRECTION : utilise setting_key (pas `key`)
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS after_setting_update;
DELIMITER $$
CREATE TRIGGER after_setting_update
AFTER UPDATE ON settings
FOR EACH ROW
BEGIN
  IF OLD.setting_value <> NEW.setting_value THEN
    INSERT INTO admin_logs (user_id, action, detail, ip)
    VALUES (
      IFNULL(@current_user_id, 1),
      'setting_changed',
      -- CORRECTION : NEW.setting_key (pas NEW.key)
      CONCAT('setting_key=', NEW.setting_key,
             ' | old=', IFNULL(OLD.setting_value, 'NULL'),
             ' | new=', IFNULL(NEW.setting_value, 'NULL')),
      IFNULL(@current_ip, '127.0.0.1')
    );
  END IF;
END$$
DELIMITER ;

-- ────────────────────────────────────────────────────────────
-- TRIGGER : after_download_insert
-- Incrémente nb_telechargements + notification
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS after_download_insert;
DELIMITER $$
CREATE TRIGGER after_download_insert
AFTER INSERT ON user_downloads
FOR EACH ROW
BEGIN
  DECLARE book_title VARCHAR(255) DEFAULT 'Livre';
  SELECT titre INTO book_title FROM livres WHERE id = NEW.livre_id LIMIT 1;
  UPDATE livres SET nb_telechargements = nb_telechargements + 1 WHERE id = NEW.livre_id;
  INSERT INTO notifications (user_id, type, titre, message, icon, bg)
  VALUES (
    NULL, 'download',
    'Nouveau téléchargement',
    CONCAT('« ', book_title, ' » a été téléchargé.'),
    '⬇️', 'rgba(0,212,255,.06)'
  );
END$$
DELIMITER ;

-- ────────────────────────────────────────────────────────────
-- TRIGGER : after_progression_update
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS after_progression_update;
DELIMITER $$
CREATE TRIGGER after_progression_update
AFTER INSERT ON lecture_progression
FOR EACH ROW
BEGIN
  UPDATE livres SET nb_lectures = nb_lectures + 1 WHERE id = NEW.livre_id;
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;

-- ────────────────────────────────────────────────────────────
-- VÉRIFICATION FINALE
-- ────────────────────────────────────────────────────────────
SELECT CONCAT('✅ settings : ', COUNT(*), ' paramètres (colonne setting_key OK)') AS bilan FROM settings
UNION ALL SELECT CONCAT('✅ notifications : table prête') FROM information_schema.tables
  WHERE table_schema = 'digital_library' AND table_name = 'notifications'
UNION ALL SELECT CONCAT('✅ users : ', COUNT(*), ' comptes') FROM users
UNION ALL SELECT CONCAT('✅ categories : ', COUNT(*)) FROM categories;