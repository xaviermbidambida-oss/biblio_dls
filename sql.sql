-- ╔══════════════════════════════════════════════════════════════════════════════╗
-- ║  DIGITAL LIBRARY SYSTEM — notifications_schema_final.sql                   ║
-- ║  Version FINALE unifiée — remplace TOUS les anciens schemas                ║
-- ║                                                                            ║
-- ║  DÉCISIONS ARCHITECTURALES :                                               ║
-- ║   • Colonnes retenues : lu, titre, bg  (compatibles notifications.php)     ║
-- ║   • Suppression de is_read / title / is_archived (double système = bugs)   ║
-- ║   • Une seule table notifications — structure fixe et définitive           ║
-- ║   • Triggers couvrent 100% des événements métier                           ║
-- ║   • setting_key (jamais `key` — mot réservé MySQL)                         ║
-- ║   • Exécuter UNE SEULE FOIS sur une base propre, ou en migration           ║
-- ╚══════════════════════════════════════════════════════════════════════════════╝

-- ─────────────────────────────────────────────────────────────────────────────
-- 0. INITIALISATION
-- ─────────────────────────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS digital_library
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE digital_library;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = '';

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. CATÉGORIES
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
    id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    nom        VARCHAR(100)     NOT NULL,
    slug       VARCHAR(100)     NOT NULL UNIQUE,
    icone      VARCHAR(10)      DEFAULT '📚',
    couleur    VARCHAR(20)      DEFAULT '#4a9eff',
    created_at TIMESTAMP        DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categories (id, nom, slug, icone, couleur) VALUES
    (1,  'Livres',              'livres',   '📘', '#4a9eff'),
    (2,  'Journaux',            'journaux', '📰', '#ff6b6b'),
    (3,  'Science-Fiction',     'sf',       '🌌', '#1a4a7a'),
    (4,  'Philosophie',         'philo',    '🧠', '#4a1a7a'),
    (5,  'Nature',              'nature',   '🌿', '#1a6b3a'),
    (6,  'Technologie',         'tech',     '⚙️',  '#1a5a7a'),
    (7,  'Histoire',            'histoire', '📜', '#7a5a1a'),
    (8,  'Littérature',         'lit',      '🎭', '#6b1a3a'),
    (9,  'Sciences',            'sciences', '🔬', '#1a3a7a'),
    (10, 'Économie',            'eco',      '💹', '#3a6b1a'),
    (11, 'Art & Culture',       'art',      '🎨', '#7a1a5a'),
    (12, 'Développement Perso', 'dev',      '🌱', '#1a7a5a');

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. UTILISATEURS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(150)   NOT NULL,
    prenom          VARCHAR(150)   NOT NULL DEFAULT '',
    email           VARCHAR(255)   NOT NULL UNIQUE,
    password        VARCHAR(255)   NOT NULL,
    telephone       VARCHAR(20)    DEFAULT NULL,
    role            ENUM('lecteur','journaliste','admin') NOT NULL DEFAULT 'lecteur',
    statut          ENUM('actif','inactif','bloque')      NOT NULL DEFAULT 'actif',
    avatar          VARCHAR(255)   DEFAULT NULL,
    last_login      TIMESTAMP      NULL,
    last_ip         VARCHAR(45)    NULL,
    login_count     INT UNSIGNED   NOT NULL DEFAULT 0,
    login_fails     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Tentatives échouées consécutives',
    locked_until    TIMESTAMP      NULL COMMENT 'Compte verrouillé jusqu à cette date',
    blocked_at      TIMESTAMP      NULL,
    blocked_by      INT UNSIGNED   NULL,
    block_reason    VARCHAR(500)   NULL,
    block_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    risk_level      ENUM('faible','moyen','eleve','critique') NOT NULL DEFAULT 'faible',
    admin_note      TEXT           NULL,
    preferences     JSON           NULL,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role    (role),
    INDEX idx_users_statut  (statut),
    INDEX idx_users_login   (last_login),
    INDEX idx_users_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. PARAMÈTRES GLOBAUX
--    Colonne : setting_key  (jamais `key` — mot réservé MySQL → erreur 1054)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(150)   NOT NULL UNIQUE
                  COMMENT 'Clé du paramètre — jamais nommé key (mot réservé MySQL)',
    setting_value TEXT           DEFAULT NULL,
    setting_group VARCHAR(50)    NOT NULL DEFAULT 'general',
    updated_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_settings_key   (setting_key),
    INDEX idx_settings_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Paramètres globaux — colonne setting_key (pas key)';

INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES
    ('site_name',          'Digital Library',  'general'),
    ('primary_color',      '#00d4ff',           'appearance'),
    ('dashboard_theme',    'dark',              'appearance'),
    ('currency',           'FCFA',              'general'),
    ('timezone',           'Africa/Douala',     'general'),
    ('language',           'fr',                'general'),
    ('pagination',         '20',                'general'),
    ('bonus_rule',         '5',                 'loyalty'),
    ('max_downloads',      '3',                 'library'),
    ('default_access',     'paid',              'library'),
    ('notif_enabled',      '1',                 'notifications'),
    ('notif_sales',        '1',                 'notifications'),
    ('notif_bonus',        '1',                 'notifications'),
    ('notif_users',        '1',                 'notifications'),
    ('notif_security',     '1',                 'notifications'),
    ('two_fa',             '0',                 'security'),
    ('max_login_attempts', '5',                 'security'),
    ('lockout_minutes',    '30',                'security');

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. LIVRES
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS livres (
    id                  INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    titre               VARCHAR(255)   NOT NULL,
    auteur              VARCHAR(150)   NOT NULL,
    isbn                VARCHAR(20)    UNIQUE,
    description         TEXT,
    prix                DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    stock               INT            NOT NULL DEFAULT 100,
    categorie_id        INT UNSIGNED   DEFAULT NULL,
    couverture          VARCHAR(500)   DEFAULT NULL,
    fichier_pdf         VARCHAR(500)   DEFAULT NULL,
    annee_parution      YEAR           DEFAULT NULL,
    editeur             VARCHAR(150)   DEFAULT NULL,
    langue              VARCHAR(50)    DEFAULT 'Français',
    pages               INT            DEFAULT NULL,
    statut              ENUM('disponible','rupture','archive') NOT NULL DEFAULT 'disponible',
    access_type         ENUM('premium','standard','gratuit')   NOT NULL DEFAULT 'standard',
    note_moyenne        DECIMAL(3,2)   NOT NULL DEFAULT 0.00,
    nb_ventes           INT UNSIGNED   NOT NULL DEFAULT 0,
    nb_lectures         INT UNSIGNED   NOT NULL DEFAULT 0,
    nb_telechargements  INT UNSIGNED   NOT NULL DEFAULT 0,
    nb_etoiles          INT UNSIGNED   NOT NULL DEFAULT 0,
    is_featured         TINYINT(1)     NOT NULL DEFAULT 0,
    is_bestseller       TINYINT(1)     NOT NULL DEFAULT 0,
    contenu_extrait     MEDIUMTEXT     DEFAULT NULL,
    ajoute_par          INT UNSIGNED   DEFAULT NULL,
    created_at          TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (ajoute_par)   REFERENCES users(id)      ON DELETE SET NULL,
    INDEX idx_livres_statut    (statut),
    INDEX idx_livres_access    (access_type),
    INDEX idx_livres_categorie (categorie_id),
    INDEX idx_livres_prix      (prix),
    INDEX idx_livres_note      (note_moyenne DESC),
    INDEX idx_livres_featured  (is_featured),
    INDEX idx_livres_created   (created_at DESC),
    FULLTEXT KEY idx_ft_livres (titre, auteur, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. ACHATS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS achats (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED   NOT NULL,
    livre_id   INT UNSIGNED   NOT NULL,
    montant    DECIMAL(10,2)  NOT NULL,
    methode    ENUM('orange_money','mobile_money','carte','bonus') NOT NULL DEFAULT 'orange_money',
    statut     ENUM('en_attente','confirme','echec')               NOT NULL DEFAULT 'confirme',
    reference  VARCHAR(60)    NOT NULL UNIQUE,
    created_at TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE,
    INDEX idx_achats_user    (user_id),
    INDEX idx_achats_livre   (livre_id),
    INDEX idx_achats_statut  (statut),
    INDEX idx_achats_methode (methode),
    INDEX idx_achats_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. AVIS (commentaires + notes)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS avis (
    id           INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED   NOT NULL,
    livre_id     INT UNSIGNED   NOT NULL,
    note         TINYINT UNSIGNED NOT NULL DEFAULT 5
                 COMMENT 'Note de 1 à 5 étoiles',
    commentaire  TEXT           DEFAULT NULL,
    statut       ENUM('publie','en_attente','refuse') NOT NULL DEFAULT 'en_attente',
    created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE,
    INDEX idx_avis_livre  (livre_id),
    INDEX idx_avis_user   (user_id),
    INDEX idx_avis_statut (statut),
    INDEX idx_avis_note   (note)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. FAVORIS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS favoris (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED   NOT NULL,
    livre_id   INT UNSIGNED   NOT NULL,
    created_at TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_favori (user_id, livre_id),
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 8. PROGRESSION DE LECTURE
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lecture_progression (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED   NOT NULL,
    livre_id      INT UNSIGNED   NOT NULL,
    page_actuelle INT UNSIGNED   NOT NULL DEFAULT 1,
    pourcentage   DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
    termine       TINYINT(1)     NOT NULL DEFAULT 0,
    started_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_progression (user_id, livre_id),
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE,
    INDEX idx_prog_user  (user_id),
    INDEX idx_prog_livre (livre_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 9. TÉLÉCHARGEMENTS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_downloads (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED   NOT NULL,
    livre_id   INT UNSIGNED   NOT NULL,
    count      INT UNSIGNED   NOT NULL DEFAULT 1,
    last_dl_at TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_download (user_id, livre_id),
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 10. SYSTÈME BONUS
--     user_bonus  = compteur courant par utilisateur (1 ligne par user)
--     bonus_history = historique de chaque attribution
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_bonus (
    id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED   NOT NULL UNIQUE,
    achat_count   INT UNSIGNED   NOT NULL DEFAULT 0
                  COMMENT 'Achats depuis le dernier bonus',
    bonus_total   INT UNSIGNED   NOT NULL DEFAULT 0
                  COMMENT 'Total bonus gagnés depuis l inscription',
    bonus_restant INT UNSIGNED   NOT NULL DEFAULT 0
                  COMMENT 'Bonus disponibles à utiliser',
    updated_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bonus_history (
    id          INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED   NOT NULL,
    livre_id    INT UNSIGNED   NULL COMMENT 'Livre obtenu via bonus',
    type        ENUM('gagne','utilise','expire') NOT NULL DEFAULT 'gagne',
    detail      VARCHAR(255)   DEFAULT NULL,
    bonus_avant INT UNSIGNED   NOT NULL DEFAULT 0,
    bonus_apres INT UNSIGNED   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE SET NULL,
    INDEX idx_bonus_user (user_id),
    INDEX idx_bonus_date (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────────────
-- 12. LOGS ADMINISTRATEUR
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_logs (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED   NOT NULL,
    action     VARCHAR(80)    NOT NULL,
    detail     TEXT           DEFAULT NULL,
    ip         VARCHAR(45)    DEFAULT NULL,
    user_agent VARCHAR(255)   DEFAULT NULL,
    created_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_logs_user    (user_id),
    INDEX idx_logs_action  (action),
    INDEX idx_logs_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 13. ═══════════════════════════════════════════════════════════════════════
--     TABLE NOTIFICATIONS — VERSION FINALE ET DÉFINITIVE
--     ════════════════════════════════════════════════════════════════════════
--
--     COLONNES DÉCIDÉES (ne plus changer) :
--       lu         → état lu/non lu          (TINYINT 0/1)
--       titre      → titre court affiché     (VARCHAR 255)
--       message    → corps de la notif       (TEXT)
--       bg         → couleur fond CSS        (VARCHAR 80)
--       icon       → emoji affiché           (VARCHAR 10)
--       type       → catégorie               (VARCHAR 50)
--       role_cible → qui voit cette notif    (ENUM)
--       priorite   → urgence                 (ENUM)
--       lien       → URL d'action optionnelle(VARCHAR 512)
--       related_id → ID de l'entité liée     (INT)
--
--     POURQUOI CES COLONNES :
--       • notifications.php lit lu, titre, bg, icon, type → 100% compatibles
--       • NotificationService.php écrit ces mêmes colonnes → zéro conflit
--       • Suppression de is_read/title/is_archived → fin du double système
-- ─────────────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS notifications;

CREATE TABLE notifications (
    id          INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,

    -- Destinataire (NULL = notification globale visible par tous les admins)
    user_id     INT UNSIGNED   NULL
                COMMENT 'NULL = globale admin | INT = notification personnelle',

    -- Catégorisation
    type        VARCHAR(50)    NOT NULL DEFAULT 'info'
                COMMENT 'sale|bonus|user|book|download|payment|login|register|block|system|warn|error|security|reading|favori',

    -- Ciblage par rôle
    role_cible  ENUM('all','admin','journaliste','lecteur') NOT NULL DEFAULT 'all'
                COMMENT 'Rôle qui voit cette notification',

    -- Contenu
    titre       VARCHAR(255)   NOT NULL DEFAULT ''
                COMMENT 'Titre court affiché en gras dans la liste',
    message     TEXT           NOT NULL
                COMMENT 'Corps complet de la notification',

    -- Visuel
    icon        VARCHAR(10)    NOT NULL DEFAULT '🔔',
    bg          VARCHAR(80)    NOT NULL DEFAULT 'rgba(0,212,255,.08)'
                COMMENT 'Couleur CSS de fond du bloc notification',

    -- Priorité
    priorite    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',

    -- Lien d'action
    lien        VARCHAR(512)   DEFAULT NULL
                COMMENT 'URL vers laquelle pointe le bouton Voir',

    -- Entité liée (livre, user, achat…)
    related_id  INT UNSIGNED   DEFAULT NULL,
    related_type VARCHAR(30)   DEFAULT NULL
                COMMENT 'livre|user|achat|avis|favori…',

    -- État
    lu          TINYINT(1)     NOT NULL DEFAULT 0
                COMMENT '0 = non lu (point bleu affiché) | 1 = lu',

    -- Horodatage
    created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lu_at       TIMESTAMP      NULL
                COMMENT 'Date et heure exacte de lecture',

    -- Index performance
    INDEX idx_notif_user     (user_id),
    INDEX idx_notif_lu       (lu),
    INDEX idx_notif_type     (type),
    INDEX idx_notif_priorite (priorite),
    INDEX idx_notif_role     (role_cible),
    INDEX idx_notif_created  (created_at DESC),
    INDEX idx_notif_related  (related_id, related_type),

    -- Clé étrangère
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Système de notifications unifié — colonnes lu+titre+bg (pas is_read/title)';

-- ─────────────────────────────────────────────────────────────────────────────
-- 14. LOG D'AUDIT DES NOTIFICATIONS
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notification_audit (
    id              INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    notification_id INT UNSIGNED   NOT NULL,
    action          VARCHAR(30)    NOT NULL COMMENT 'created|read|deleted|bulk_read',
    actor_id        INT UNSIGNED   NULL,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_notif (notification_id),
    INDEX idx_audit_actor (actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 15. WHITELIST ADMIN
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_whitelist (
    id         INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255)   NOT NULL UNIQUE,
    note       VARCHAR(255)   DEFAULT NULL,
    created_at TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED   DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 16. IA — Conversations & Messages
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_conversations (
    id          INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED   NULL,
    titre       VARCHAR(255)   DEFAULT NULL,
    is_archived TINYINT(1)     NOT NULL DEFAULT 0,
    created_at  TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_messages (
    id                INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    conversation_id   INT UNSIGNED  NOT NULL,
    role              ENUM('user','assistant','system') NOT NULL,
    contenu           TEXT          NOT NULL,
    tokens_utilises   INT           DEFAULT NULL,
    created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_conv (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 17. VUES OPTIMISÉES
-- ─────────────────────────────────────────────────────────────────────────────

-- Vue catalogue enrichi
CREATE OR REPLACE VIEW v_catalogue AS
SELECT
    l.id,
    l.titre,
    l.auteur,
    l.isbn,
    SUBSTRING(l.description, 1, 300) AS description_courte,
    l.prix,
    l.access_type,
    l.note_moyenne,
    l.nb_ventes,
    l.nb_lectures,
    l.nb_telechargements,
    l.nb_etoiles,
    l.pages,
    l.annee_parution,
    l.editeur,
    l.langue,
    l.couverture,
    l.fichier_pdf,
    l.is_featured,
    l.is_bestseller,
    l.statut,
    l.created_at,
    c.id      AS categorie_id,
    c.nom     AS categorie_nom,
    c.slug    AS categorie_slug,
    c.icone   AS categorie_icone,
    c.couleur AS categorie_couleur
FROM livres l
LEFT JOIN categories c ON c.id = l.categorie_id
WHERE l.statut = 'disponible';

-- Vue statistiques globales dashboard
CREATE OR REPLACE VIEW v_stats_globales AS
SELECT
    (SELECT COUNT(*)                              FROM users   WHERE statut = 'actif')        AS users_actifs,
    (SELECT COUNT(*)                              FROM users   WHERE role = 'journaliste')     AS nb_journalistes,
    (SELECT COUNT(*)                              FROM users   WHERE role = 'lecteur')         AS nb_lecteurs,
    (SELECT COUNT(*)                              FROM users   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_users_7j,
    (SELECT COUNT(*)                              FROM livres  WHERE statut = 'disponible')    AS livres_disponibles,
    (SELECT COUNT(*)                              FROM livres  WHERE access_type = 'premium')  AS livres_premium,
    (SELECT COUNT(*)                              FROM achats  WHERE statut = 'confirme')      AS total_achats,
    (SELECT COALESCE(SUM(montant),0)              FROM achats  WHERE statut = 'confirme')      AS revenu_total,
    (SELECT COUNT(*)                              FROM achats  WHERE statut = 'confirme' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS achats_30j,
    (SELECT COUNT(*)                              FROM notifications WHERE lu = 0)             AS notifs_non_lues,
    (SELECT COUNT(*)                              FROM notifications WHERE priorite = 'critical' AND lu = 0) AS notifs_critiques;

-- Vue notifications enrichie (avec nom utilisateur)
CREATE OR REPLACE VIEW v_notifications AS
SELECT
    n.id,
    n.user_id,
    n.type,
    n.role_cible,
    n.titre,
    n.message,
    n.icon,
    n.bg,
    n.priorite,
    n.lien,
    n.related_id,
    n.related_type,
    n.lu,
    n.created_at,
    n.lu_at,
    u.nom         AS user_nom,
    u.prenom      AS user_prenom,
    u.email       AS user_email,
    u.role        AS user_role
FROM notifications n
LEFT JOIN users u ON u.id = n.user_id;

-- Vue paramètres actifs (lecture rapide par le dashboard)
--   CORRECTION : utilise setting_key (pas `key`)
CREATE OR REPLACE VIEW v_settings AS
SELECT
    MAX(CASE WHEN setting_key = 'site_name'          THEN setting_value END) AS site_name,
    MAX(CASE WHEN setting_key = 'primary_color'      THEN setting_value END) AS primary_color,
    MAX(CASE WHEN setting_key = 'dashboard_theme'    THEN setting_value END) AS dashboard_theme,
    MAX(CASE WHEN setting_key = 'currency'           THEN setting_value END) AS currency,
    MAX(CASE WHEN setting_key = 'language'           THEN setting_value END) AS language,
    MAX(CASE WHEN setting_key = 'timezone'           THEN setting_value END) AS timezone,
    MAX(CASE WHEN setting_key = 'bonus_rule'         THEN setting_value END) AS bonus_rule,
    MAX(CASE WHEN setting_key = 'max_downloads'      THEN setting_value END) AS max_downloads,
    MAX(CASE WHEN setting_key = 'default_access'     THEN setting_value END) AS default_access,
    MAX(CASE WHEN setting_key = 'notif_enabled'      THEN setting_value END) AS notif_enabled,
    MAX(CASE WHEN setting_key = 'two_fa'             THEN setting_value END) AS two_fa,
    MAX(CASE WHEN setting_key = 'max_login_attempts' THEN setting_value END) AS max_login_attempts,
    MAX(CASE WHEN setting_key = 'lockout_minutes'    THEN setting_value END) AS lockout_minutes
FROM settings;

-- ─────────────────────────────────────────────────────────────────────────────
-- 18. TRIGGERS
-- ─────────────────────────────────────────────────────────────────────────────

-- ── TRIGGER 1 : Achat confirmé ────────────────────────────────────────────────
--    Déclenche :
--      • Notification acheteur "Achat confirmé"
--      • Notification admin "Nouvelle vente"
--      • Incrémente nb_ventes du livre
--      • Met à jour user_bonus (achat_count)
--      • Attribue un bonus si multiple de bonus_rule atteint
--      • Notification bonus si déclenché
--      • Notification "Gros achat" si montant > 5000 FCFA
-- ─────────────────────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_achat_apres_insert;
DELIMITER $$
CREATE TRIGGER trg_achat_apres_insert
AFTER INSERT ON achats
FOR EACH ROW
BEGIN
    DECLARE v_bonus_rule    INT     DEFAULT 5;
    DECLARE v_achat_count   INT     DEFAULT 0;
    DECLARE v_bonus_avant   INT     DEFAULT 0;
    DECLARE v_livre_titre   VARCHAR(255) DEFAULT 'Livre inconnu';
    DECLARE v_user_nom      VARCHAR(255) DEFAULT 'Utilisateur';
    DECLARE v_nb_ventes     INT     DEFAULT 0;

    -- Ne traiter que les achats confirmés et non-bonus
    IF NEW.statut = 'confirme' AND NEW.methode != 'bonus' THEN

        -- Récupérer les données nécessaires
        SELECT CAST(COALESCE(setting_value, '5') AS UNSIGNED) INTO v_bonus_rule
        FROM settings WHERE setting_key = 'bonus_rule' LIMIT 1;

        SELECT COALESCE(titre, 'Livre inconnu') INTO v_livre_titre
        FROM livres WHERE id = NEW.livre_id LIMIT 1;

        SELECT COALESCE(CONCAT(prenom, ' ', nom), 'Utilisateur') INTO v_user_nom
        FROM users WHERE id = NEW.user_id LIMIT 1;

        -- ── Notification acheteur ────────────────────────────────────────────
        INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
        VALUES (
            NEW.user_id,
            'sale',
            'all',
            '✅ Achat confirmé !',
            CONCAT('Votre achat de « ', v_livre_titre, ' » est confirmé. Référence : ', NEW.reference),
            '✅',
            'rgba(0,255,170,.08)',
            'high',
            CONCAT('../books/reader.php?id=', NEW.livre_id),
            NEW.livre_id,
            'livre'
        );

        -- ── Notification admin globale ───────────────────────────────────────
        INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
        VALUES (
            NULL,
            'sale',
            'admin',
            '💰 Nouvelle vente',
            CONCAT(v_user_nom, ' a acheté « ', v_livre_titre, ' » · ', FORMAT(NEW.montant, 0), ' FCFA via ', NEW.methode),
            '💰',
            'rgba(0,255,170,.06)',
            'medium',
            '../admin/sales.php',
            NEW.livre_id,
            'livre'
        );

        -- ── Incrémenter nb_ventes ────────────────────────────────────────────
        UPDATE livres SET nb_ventes = nb_ventes + 1 WHERE id = NEW.livre_id;

        -- ── Récupérer nb_ventes pour détection bestseller ────────────────────
        SELECT nb_ventes INTO v_nb_ventes FROM livres WHERE id = NEW.livre_id;

        -- ── Notification bestseller aux jalons ───────────────────────────────
        IF v_nb_ventes IN (10, 25, 50, 100, 250, 500) THEN
            INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
            VALUES (
                NULL,
                'book',
                'admin',
                '🔥 Livre populaire !',
                CONCAT('« ', v_livre_titre, ' » vient d''atteindre ', v_nb_ventes, ' ventes.'),
                '🔥',
                'rgba(245,158,11,.08)',
                'medium',
                CONCAT('../books/index.php?id=', NEW.livre_id),
                NEW.livre_id,
                'livre'
            );
        END IF;

        -- ── Notification gros achat (> 5000 FCFA) ────────────────────────────
        IF NEW.montant > 5000 THEN
            INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, related_id, related_type)
            VALUES (
                NULL,
                'sale',
                'admin',
                '💎 Gros achat détecté',
                CONCAT(v_user_nom, ' a dépensé ', FORMAT(NEW.montant, 0), ' FCFA sur « ', v_livre_titre, ' ».'),
                '💎',
                'rgba(124,58,237,.08)',
                'high',
                NEW.livre_id,
                'livre'
            );
        END IF;

        -- ── Système bonus ────────────────────────────────────────────────────
        -- Insérer ou incrémenter le compteur de l'utilisateur
        INSERT INTO user_bonus (user_id, achat_count, bonus_total, bonus_restant)
        VALUES (NEW.user_id, 1, 0, 0)
        ON DUPLICATE KEY UPDATE achat_count = achat_count + 1;

        -- Lire le compteur après mise à jour
        SELECT achat_count, bonus_restant INTO v_achat_count, v_bonus_avant
        FROM user_bonus WHERE user_id = NEW.user_id;

        -- Bonus déclenché ?
        IF v_achat_count >= v_bonus_rule THEN

            -- Réinitialiser le compteur et incrémenter les bonus
            UPDATE user_bonus
            SET achat_count   = achat_count - v_bonus_rule,
                bonus_total   = bonus_total + 1,
                bonus_restant = bonus_restant + 1
            WHERE user_id = NEW.user_id;

            -- Historique bonus
            INSERT INTO bonus_history (user_id, type, detail, bonus_avant, bonus_apres)
            VALUES (
                NEW.user_id,
                'gagne',
                CONCAT('Bonus après ', v_bonus_rule, ' achats — réf dernier achat : ', NEW.reference),
                v_bonus_avant,
                v_bonus_avant + 1
            );

            -- Notification utilisateur
            INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
            VALUES (
                NEW.user_id,
                'bonus',
                'all',
                '🎁 Bonus fidélité débloqué !',
                CONCAT('Félicitations ', TRIM(v_user_nom), ' ! Vous avez effectué ', v_bonus_rule, ' achats et recevez un livre gratuit. Choisissez-le dans votre espace.'),
                '🎁',
                'rgba(0,255,170,.08)',
                'critical',
                '../books/index.php?bonus=1',
                NEW.user_id,
                'user'
            );

            -- Notification admin
            INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
            VALUES (
                NULL,
                'bonus',
                'admin',
                '🎁 Bonus fidélité à attribuer',
                CONCAT(v_user_nom, ' a atteint ', v_bonus_rule, ' achats. Un livre gratuit lui est dû — attribuez-le depuis le panneau utilisateurs.'),
                '🎁',
                'rgba(0,255,170,.08)',
                'high',
                CONCAT('../users/index.php?action=bonus&id=', NEW.user_id),
                NEW.user_id,
                'user'
            );
        END IF;

    END IF;
END$$
DELIMITER ;

-- ── TRIGGER 2 : Téléchargement ───────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_download_apres_insert;
DELIMITER $$
CREATE TRIGGER trg_download_apres_insert
AFTER INSERT ON user_downloads
FOR EACH ROW
BEGIN
    DECLARE v_livre_titre VARCHAR(255) DEFAULT 'Livre inconnu';
    DECLARE v_user_nom    VARCHAR(255) DEFAULT 'Utilisateur';

    SELECT COALESCE(titre, 'Livre inconnu') INTO v_livre_titre
    FROM livres WHERE id = NEW.livre_id LIMIT 1;

    SELECT COALESCE(CONCAT(prenom, ' ', nom), 'Utilisateur') INTO v_user_nom
    FROM users WHERE id = NEW.user_id LIMIT 1;

    -- Incrémenter nb_telechargements
    UPDATE livres SET nb_telechargements = nb_telechargements + 1 WHERE id = NEW.livre_id;

    -- Notification admin
    INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, related_id, related_type)
    VALUES (
        NULL,
        'download',
        'admin',
        '⬇️ Nouveau téléchargement',
        CONCAT(v_user_nom, ' a téléchargé « ', v_livre_titre, ' ».'),
        '⬇️',
        'rgba(0,212,255,.06)',
        'low',
        NEW.livre_id,
        'livre'
    );
END$$
DELIMITER ;

-- ── TRIGGER 3 : Début de lecture ──────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_lecture_apres_insert;
DELIMITER $$
CREATE TRIGGER trg_lecture_apres_insert
AFTER INSERT ON lecture_progression
FOR EACH ROW
BEGIN
    UPDATE livres SET nb_lectures = nb_lectures + 1 WHERE id = NEW.livre_id;
END$$
DELIMITER ;


-- ── TRIGGER 5 : Ajout aux favoris ────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_favori_apres_insert;
DELIMITER $$
CREATE TRIGGER trg_favori_apres_insert
AFTER INSERT ON favoris
FOR EACH ROW
BEGIN
    DECLARE v_livre_titre VARCHAR(255) DEFAULT 'Livre inconnu';
    DECLARE v_user_nom    VARCHAR(255) DEFAULT 'Utilisateur';

    SELECT COALESCE(titre, 'Livre inconnu') INTO v_livre_titre
    FROM livres WHERE id = NEW.livre_id LIMIT 1;

    SELECT COALESCE(CONCAT(prenom, ' ', nom), 'Utilisateur') INTO v_user_nom
    FROM users WHERE id = NEW.user_id LIMIT 1;

    -- Notification utilisateur
    INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, related_id, related_type)
    VALUES (
        NEW.user_id,
        'favori',
        'all',
        '❤️ Ajouté aux favoris',
        CONCAT('« ', v_livre_titre, ' » a été ajouté à vos favoris.'),
        '❤️',
        'rgba(244,63,94,.06)',
        'low',
        NEW.livre_id,
        'livre'
    );

    -- Notification admin (agrégée — faible priorité)
    INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, related_id, related_type)
    VALUES (
        NULL,
        'favori',
        'admin',
        '❤️ Nouveau favori',
        CONCAT(v_user_nom, ' a mis « ', v_livre_titre, ' » en favori.'),
        '❤️',
        'rgba(244,63,94,.06)',
        'low',
        NEW.livre_id,
        'livre'
    );
END$$
DELIMITER ;

-- ── TRIGGER 6 : Nouvel avis publié ────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_avis_apres_insert;
DELIMITER $$
CREATE TRIGGER trg_avis_apres_insert
AFTER INSERT ON avis
FOR EACH ROW
BEGIN
    DECLARE v_livre_titre VARCHAR(255) DEFAULT 'Livre inconnu';
    DECLARE v_user_nom    VARCHAR(255) DEFAULT 'Utilisateur';
    DECLARE v_auteur_id   INT UNSIGNED DEFAULT NULL;

    SELECT COALESCE(titre, 'Livre inconnu'), ajoute_par
    INTO v_livre_titre, v_auteur_id
    FROM livres WHERE id = NEW.livre_id LIMIT 1;

    SELECT COALESCE(CONCAT(prenom, ' ', nom), 'Utilisateur') INTO v_user_nom
    FROM users WHERE id = NEW.user_id LIMIT 1;

    -- Notification admin
    INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
    VALUES (
        NULL,
        'avis',
        'admin',
        '⭐ Nouvel avis soumis',
        CONCAT(v_user_nom, ' a laissé un avis ', NEW.note, '/5 sur « ', v_livre_titre, ' ». Statut : ', NEW.statut, '.'),
        '⭐',
        'rgba(245,158,11,.08)',
        'medium',
        CONCAT('../admin/avis.php?id=', NEW.id),
        NEW.livre_id,
        'livre'
    );

    -- Si le journaliste est identifié, le notifier
    IF v_auteur_id IS NOT NULL AND v_auteur_id != NEW.user_id THEN
        INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
        VALUES (
            v_auteur_id,
            'avis',
            'all',
            '⭐ Nouvel avis sur votre livre',
            CONCAT('Un lecteur a laissé un avis ', NEW.note, '/5 sur « ', v_livre_titre, ' ».'),
            '⭐',
            'rgba(245,158,11,.08)',
            'medium',
            CONCAT('../books/avis.php?id=', NEW.livre_id),
            NEW.livre_id,
            'livre'
        );
    END IF;
END$$
DELIMITER ;

-- ── TRIGGER 7 : Modification settings ─────────────────────────────────────────
--    CORRECTION : utilise NEW.setting_key (pas NEW.key)
DROP TRIGGER IF EXISTS trg_settings_apres_update;
DELIMITER $$
CREATE TRIGGER trg_settings_apres_update
AFTER UPDATE ON settings
FOR EACH ROW
BEGIN
    IF OLD.setting_value <> NEW.setting_value OR (OLD.setting_value IS NULL AND NEW.setting_value IS NOT NULL) THEN
        INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite)
        VALUES (
            NULL,
            'system',
            'admin',
            '⚙️ Paramètre modifié',
            -- CORRECTION : NEW.setting_key (jamais NEW.key)
            CONCAT('Paramètre « ', NEW.setting_key, ' » modifié : ', COALESCE(OLD.setting_value, 'vide'), ' → ', COALESCE(NEW.setting_value, 'vide')),
            '⚙️',
            'rgba(124,58,237,.08)',
            'low'
        );
    END IF;
END$$
DELIMITER ;

-- ─────────────────────────────────────────────────────────────────────────────
-- 19. PROCÉDURES STOCKÉES UTILITAIRES
-- ─────────────────────────────────────────────────────────────────────────────

-- Procédure : Créer une notification (utilisable depuis PHP sans récrire le SQL)
DROP PROCEDURE IF EXISTS sp_notifier;
DELIMITER $$
CREATE PROCEDURE sp_notifier(
    IN p_user_id     INT UNSIGNED,
    IN p_type        VARCHAR(50),
    IN p_role_cible  VARCHAR(20),
    IN p_titre       VARCHAR(255),
    IN p_message     TEXT,
    IN p_icon        VARCHAR(10),
    IN p_bg          VARCHAR(80),
    IN p_priorite    VARCHAR(10),
    IN p_lien        VARCHAR(512),
    IN p_related_id  INT UNSIGNED,
    IN p_related_type VARCHAR(30)
)
BEGIN
    INSERT INTO notifications
        (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
    VALUES
        (p_user_id, p_type, p_role_cible, p_titre, p_message, p_icon, p_bg, p_priorite, p_lien, p_related_id, p_related_type);
END$$
DELIMITER ;

-- Procédure : Nettoyer les anciennes notifications lues (> 30 jours)
DROP PROCEDURE IF EXISTS sp_nettoyer_notifications;
DELIMITER $$
CREATE PROCEDURE sp_nettoyer_notifications(IN p_jours INT)
BEGIN
    DECLARE v_count INT DEFAULT 0;

    DELETE FROM notifications
    WHERE lu = 1
    AND created_at < DATE_SUB(NOW(), INTERVAL p_jours DAY);

    SET v_count = ROW_COUNT();

    -- Logger le nettoyage
    INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite)
    VALUES (
        NULL, 'system', 'admin',
        '🧹 Nettoyage automatique',
        CONCAT(v_count, ' notification(s) lue(s) de plus de ', p_jours, ' jour(s) supprimée(s).'),
        '🧹', 'rgba(0,212,255,.06)', 'low'
    );
END$$
DELIMITER ;

-- Procédure : Attribuer un bonus manuellement (appelée par l'admin depuis PHP)
DROP PROCEDURE IF EXISTS sp_attribuer_bonus;
DELIMITER $$
CREATE PROCEDURE sp_attribuer_bonus(
    IN p_user_id   INT UNSIGNED,
    IN p_livre_id  INT UNSIGNED,
    IN p_admin_id  INT UNSIGNED
)
BEGIN
    DECLARE v_user_nom    VARCHAR(255) DEFAULT 'Utilisateur';
    DECLARE v_livre_titre VARCHAR(255) DEFAULT 'Livre inconnu';
    DECLARE v_reference   VARCHAR(60);
    DECLARE v_bonus_avant INT DEFAULT 0;

    SELECT COALESCE(CONCAT(prenom, ' ', nom), 'Utilisateur') INTO v_user_nom
    FROM users WHERE id = p_user_id LIMIT 1;

    SELECT COALESCE(titre, 'Livre inconnu') INTO v_livre_titre
    FROM livres WHERE id = p_livre_id LIMIT 1;

    -- Générer référence unique
    SET v_reference = CONCAT('BONUS-', UNIX_TIMESTAMP(), '-', p_user_id, '-', p_livre_id);

    -- Insérer l'achat bonus
    INSERT IGNORE INTO achats (user_id, livre_id, montant, methode, statut, reference)
    VALUES (p_user_id, p_livre_id, 0, 'bonus', 'confirme', v_reference);

    -- Décrémenter bonus_restant
    SELECT COALESCE(bonus_restant, 0) INTO v_bonus_avant FROM user_bonus WHERE user_id = p_user_id;

    UPDATE user_bonus
    SET bonus_restant = GREATEST(0, bonus_restant - 1)
    WHERE user_id = p_user_id;

    -- Historique
    INSERT INTO bonus_history (user_id, livre_id, type, detail, bonus_avant, bonus_apres)
    VALUES (p_user_id, p_livre_id, 'utilise', CONCAT('Attribué par admin #', p_admin_id), v_bonus_avant, GREATEST(0, v_bonus_avant - 1));

    -- Notification utilisateur
    INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, lien, related_id, related_type)
    VALUES (
        p_user_id, 'bonus', 'all',
        '🎁 Vous avez reçu un cadeau !',
        CONCAT('Félicitations ! « ', v_livre_titre, ' » vous est offert pour votre fidélité. Bonne lecture !'),
        '🎁', 'rgba(0,255,170,.08)', 'critical',
        CONCAT('../books/reader.php?id=', p_livre_id),
        p_livre_id, 'livre'
    );

    -- Notification admin confirmation
    INSERT INTO notifications (user_id, type, role_cible, titre, message, icon, bg, priorite, related_id, related_type)
    VALUES (
        NULL, 'bonus', 'admin',
        '✅ Bonus attribué',
        CONCAT('Admin #', p_admin_id, ' a attribué « ', v_livre_titre, ' » à ', v_user_nom, '.'),
        '✅', 'rgba(0,255,170,.06)', 'low',
        p_user_id, 'user'
    );
END$$
DELIMITER ;

-- ─────────────────────────────────────────────────────────────────────────────
-- 20. ÉVÉNEMENTS PLANIFIÉS (si event_scheduler est activé)
-- ─────────────────────────────────────────────────────────────────────────────

-- Activer le scheduler (à décommenter si supporté par la config WAMP/XAMPP)
-- SET GLOBAL event_scheduler = ON;

-- Nettoyage automatique tous les dimanches à 3h
DROP EVENT IF EXISTS evt_nettoyer_notifs;
DELIMITER $$
CREATE EVENT evt_nettoyer_notifs
ON SCHEDULE EVERY 1 WEEK
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL (7 - WEEKDAY(CURRENT_DATE)) DAY + INTERVAL 3 HOUR)
DO
BEGIN
    CALL sp_nettoyer_notifications(30);
END$$
DELIMITER ;

-- ─────────────────────────────────────────────────────────────────────────────
-- 21. RESTAURATION FOREIGN KEYS
-- ─────────────────────────────────────────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- 22. VÉRIFICATION FINALE
-- ─────────────────────────────────────────────────────────────────────────────
SELECT '════════════════════════════════════════════' AS separateur
UNION ALL
SELECT CONCAT('✅ Tables créées       : ',
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'digital_library' AND TABLE_TYPE = 'BASE TABLE'))
UNION ALL
SELECT CONCAT('✅ Vues créées         : ',
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'digital_library' AND TABLE_TYPE = 'VIEW'))
UNION ALL
SELECT CONCAT('✅ Triggers actifs     : ',
    (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'digital_library'))
UNION ALL
SELECT CONCAT('✅ Procédures stockées : ',
    (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'digital_library' AND ROUTINE_TYPE = 'PROCEDURE'))
UNION ALL
SELECT CONCAT('✅ Paramètres settings : ',
    (SELECT COUNT(*) FROM settings))
UNION ALL
SELECT CONCAT('✅ Colonnes notifications : lu ✓ | titre ✓ | bg ✓ | role_cible ✓ | priorite ✓')
UNION ALL
SELECT CONCAT('✅ Colonne setting_key confirmée (pas `key`) — erreur 1054 éliminée')
UNION ALL
SELECT '════════════════════════════════════════════' AS separateur;


DROP TRIGGER IF EXISTS trg_lecture_apres_update;

DELIMITER $$

CREATE TRIGGER trg_lecture_apres_update
AFTER UPDATE ON lecture_progression
FOR EACH ROW
BEGIN
    DECLARE v_livre_titre VARCHAR(255) DEFAULT 'Livre inconnu';
    DECLARE v_user_nom VARCHAR(255) DEFAULT 'Utilisateur';

    -- Déclencher uniquement quand le statut passe à "termine"
    IF NEW.statut = 'termine'
       AND OLD.statut <> 'termine' THEN

        -- Récupérer le titre du livre
        SELECT COALESCE(titre, 'Livre inconnu')
        INTO v_livre_titre
        FROM livres
        WHERE id = NEW.livre_id
        LIMIT 1;

        -- Récupérer le nom utilisateur
        SELECT COALESCE(CONCAT(prenom, ' ', nom), 'Utilisateur')
        INTO v_user_nom
        FROM users
        WHERE id = NEW.user_id
        LIMIT 1;

        -- Notification utilisateur
        INSERT INTO notifications (
            user_id,
            type,
            role_cible,
            titre,
            message,
            icon,
            bg,
            priorite,
            lien,
            related_id,
            related_type
        )
        VALUES (
            NEW.user_id,
            'reading',
            'all',
            '🏆 Livre terminé !',
            CONCAT(
                'Bravo ! Vous avez terminé « ',
                v_livre_titre,
                ' ». Partagez votre avis !'
            ),
            '🏆',
            'rgba(245,158,11,.08)',
            'medium',
            CONCAT('../books/avis.php?id=', NEW.livre_id),
            NEW.livre_id,
            'livre'
        );

        -- Notification admin
        INSERT INTO notifications (
            user_id,
            type,
            role_cible,
            titre,
            message,
            icon,
            bg,
            priorite,
            related_id,
            related_type
        )
        VALUES (
            NULL,
            'reading',
            'admin',
            '📖 Livre terminé',
            CONCAT(
                v_user_nom,
                ' a terminé la lecture de « ',
                v_livre_titre,
                ' ».'
            ),
            '📖',
            'rgba(0,212,255,.06)',
            'low',
            NEW.livre_id,
            'livre'
        );

    END IF;

END$$

DELIMITER ;