<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — Ma Bibliothèque v2.0 FIXED        ║
 * ║  books/my_library.php                                        ║
 * ║  ✅ Erreur JSON corrigée · PDO sécurisé · AJAX propre        ║
 * ║  ✅ Favoris · Collections · Stats · Bonus · PDF.js           ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * CORRECTION PRINCIPALE :
 * - ob_start() dès le début pour capturer tout output parasite
 * - AJAX handler isolé avec ob_clean() avant json_encode
 * - Séparation claire AJAX vs HTML
 * - Suppression de tout echo/print avant le header JSON
 * - Gestion des erreurs PHP (notices/warnings) avant output
 */

// ── Supprimer TOUT output parasite avant JSON ─────────────────
ob_start();

// ── Désactiver affichage d'erreurs (log seulement) ────────────
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// ── Session ───────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Connexion PDO ─────────────────────────────────────────────
$pdo = null;

$configPaths = [
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/../config/database.php',
];

foreach ($configPaths as $_cfgPath) {
    if (file_exists($_cfgPath) && !defined('DB_HOST_LOADED')) {
        require_once $_cfgPath;
        define('DB_HOST_LOADED', true);
        break;
    }
}

if (!isset($pdo) || $pdo === null) {
    $_dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
    $_dbName = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $_dbUser = defined('DB_USER') ? DB_USER : 'root';
    $_dbPass = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$_dbHost};dbname={$_dbName};charset=utf8mb4",
            $_dbUser,
            $_dbPass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        error_log('[DLS-Library] PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth guard ────────────────────────────────────────────────
$isLoggedIn = isset($_SESSION['user_id']);

// ══════════════════════════════════════════════════════════════
//  AJAX HANDLER — doit être traité AVANT tout output HTML
//  La clé est ob_clean() + json_encode + exit immédiat
// ══════════════════════════════════════════════════════════════
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax && isset($_GET['action'])) {

    // Vider tout output parasite accumulé
    ob_clean();

    // Forcer Content-Type JSON avant TOUT
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    // Wrapper global pour garantir JSON même en cas d'exception non catchée
    set_exception_handler(function (Throwable $e) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    });

    // Auth check pour AJAX
    if (!$isLoggedIn) {
        echo json_encode(['success' => false, 'error' => 'Session expirée', 'redirect' => '../login.php']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $action = trim($_GET['action'] ?? '');
    $csrf   = $_POST['csrf'] ?? $_GET['csrf'] ?? '';

    // Validation CSRF
    if (!isset($_SESSION['csrf_lib']) || !hash_equals($_SESSION['csrf_lib'], $csrf)) {
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']);
        exit;
    }

    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Base de données inaccessible']);
        exit;
    }

    try {
        switch ($action) {

            // ── Toggle favori ──────────────────────────────────
            case 'toggle_favorite':
                $livreId = (int)($_POST['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('ID livre manquant');

                // Vérifier accès (acheté ou gratuit)
                $stmtOwn = $pdo->prepare(
                    "SELECT 1 FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme'
                     UNION
                     SELECT 1 FROM livres WHERE id=? AND (access_type='gratuit' OR prix=0)
                     LIMIT 1"
                );
                $stmtOwn->execute([$userId, $livreId, $livreId]);
                if (!$stmtOwn->fetch()) throw new Exception('Livre non accessible');

                $stmtEx = $pdo->prepare("SELECT id FROM favorites WHERE user_id=? AND livre_id=?");
                $stmtEx->execute([$userId, $livreId]);
                $exists = $stmtEx->fetch();

                if ($exists) {
                    $pdo->prepare("DELETE FROM favorites WHERE user_id=? AND livre_id=?")->execute([$userId, $livreId]);
                    echo json_encode(['success' => true, 'action' => 'removed', 'msg' => 'Retiré des favoris']);
                } else {
                    $pdo->prepare("INSERT IGNORE INTO favorites (user_id, livre_id) VALUES (?,?)")->execute([$userId, $livreId]);
                    echo json_encode(['success' => true, 'action' => 'added', 'msg' => 'Ajouté aux favoris ⭐']);
                }
                break;

            // ── Sauvegarder avis / note ───────────────────────
            case 'save_review':
                $livreId  = (int)($_POST['livre_id'] ?? 0);
                $note     = min(5, max(1, (int)($_POST['note'] ?? 0)));
                $comment  = trim(substr($_POST['commentaire'] ?? '', 0, 1000));
                if (!$livreId || !$note) throw new Exception('Données manquantes');

                $pdo->prepare(
                    "INSERT INTO avis (user_id, livre_id, note, commentaire, statut)
                     VALUES (?,?,?,?,'publie')
                     ON DUPLICATE KEY UPDATE note=VALUES(note), commentaire=VALUES(commentaire)"
                )->execute([$userId, $livreId, $note, $comment]);

                // Recalcul note moyenne
                $pdo->prepare(
                    "UPDATE livres
                     SET note_moyenne = (SELECT AVG(note) FROM avis WHERE livre_id=? AND statut='publie'),
                         nb_etoiles   = (SELECT COUNT(*) FROM avis WHERE livre_id=? AND statut='publie')
                     WHERE id=?"
                )->execute([$livreId, $livreId, $livreId]);

                echo json_encode(['success' => true, 'msg' => 'Avis enregistré ✅']);
                break;

            // ── Sauvegarder progression lecture ───────────────
            case 'save_progress':
                $livreId    = (int)($_POST['livre_id'] ?? 0);
                $page       = max(1, (int)($_POST['page'] ?? 1));
                $totalPages = (int)($_POST['total_pages'] ?? 0);
                $pct        = $totalPages > 0 ? min(100, round($page / $totalPages * 100, 2)) : 0;
                $statut     = $pct >= 99 ? 'termine' : 'en_cours';
                if (!$livreId) throw new Exception('ID livre manquant');

                $pdo->prepare(
                    "INSERT INTO lecture_progression
                         (user_id, livre_id, page_actuelle, pourcentage, total_pages, statut, date_debut)
                     VALUES (?,?,?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE
                         page_actuelle = VALUES(page_actuelle),
                         pourcentage   = VALUES(pourcentage),
                         total_pages   = VALUES(total_pages),
                         statut        = VALUES(statut),
                         date_fin      = IF(VALUES(statut)='termine', NOW(), date_fin)"
                )->execute([$userId, $livreId, $page, $pct, $totalPages, $statut]);

                echo json_encode(['success' => true, 'pct' => $pct, 'statut' => $statut]);
                break;

            // ── Créer collection ──────────────────────────────
            case 'create_collection':
                $nom    = trim(substr($_POST['nom'] ?? '', 0, 255));
                $desc   = trim(substr($_POST['description'] ?? '', 0, 500));
                $colour = substr($_POST['couleur'] ?? '#4a9eff', 0, 20);
                if (!$nom) throw new Exception('Nom de collection requis');

                $pdo->prepare(
                    "INSERT INTO user_collections (user_id, nom, description, couleur) VALUES (?,?,?,?)"
                )->execute([$userId, $nom, $desc, $colour]);

                $newId = (int)$pdo->lastInsertId();
                echo json_encode(['success' => true, 'id' => $newId, 'nom' => $nom, 'couleur' => $colour]);
                break;

            // ── Supprimer collection ──────────────────────────
            case 'delete_collection':
                $colId = (int)($_POST['collection_id'] ?? 0);
                if (!$colId) throw new Exception('ID manquant');

                $stmtOwn = $pdo->prepare("SELECT id FROM user_collections WHERE id=? AND user_id=?");
                $stmtOwn->execute([$colId, $userId]);
                if (!$stmtOwn->fetch()) throw new Exception('Non autorisé');

                $pdo->prepare("DELETE FROM user_collections WHERE id=? AND user_id=?")->execute([$colId, $userId]);
                echo json_encode(['success' => true, 'msg' => 'Collection supprimée']);
                break;

            // ── Toggle livre dans collection ──────────────────
            case 'toggle_collection_book':
                $colId   = (int)($_POST['collection_id'] ?? 0);
                $livreId = (int)($_POST['livre_id'] ?? 0);
                if (!$colId || !$livreId) throw new Exception('IDs manquants');

                $stmtOwn = $pdo->prepare("SELECT id FROM user_collections WHERE id=? AND user_id=?");
                $stmtOwn->execute([$colId, $userId]);
                if (!$stmtOwn->fetch()) throw new Exception('Non autorisé');

                $stmtEx = $pdo->prepare("SELECT id FROM collection_books WHERE collection_id=? AND livre_id=?");
                $stmtEx->execute([$colId, $livreId]);

                if ($stmtEx->fetch()) {
                    $pdo->prepare("DELETE FROM collection_books WHERE collection_id=? AND livre_id=?")->execute([$colId, $livreId]);
                    echo json_encode(['success' => true, 'action' => 'removed']);
                } else {
                    $pdo->prepare("INSERT IGNORE INTO collection_books (collection_id, livre_id) VALUES (?,?)")->execute([$colId, $livreId]);
                    echo json_encode(['success' => true, 'action' => 'added']);
                }
                break;

            // ── Marquer notification lue ──────────────────────
            case 'mark_notif_read':
                $nId = (int)($_POST['notif_id'] ?? 0);
                if ($nId) {
                    $pdo->prepare(
                        "UPDATE notifications SET lu=1, is_read=1
                         WHERE id=? AND (user_id=? OR user_id IS NULL)"
                    )->execute([$nId, $userId]);
                }
                echo json_encode(['success' => true]);
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Action inconnue : ' . htmlspecialchars($action)]);
        }
    } catch (Throwable $e) {
        error_log('[DLS-Library] AJAX error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    exit; // IMPÉRATIF — stopper tout output HTML après AJAX
}

// ══════════════════════════════════════════════════════════════
//  PAGE HTML — Auth guard (seulement pour les requêtes non-AJAX)
// ══════════════════════════════════════════════════════════════
if (!$isLoggedIn) {
    ob_end_clean();
    header('Location: ../login.php?redirect=books/my_library.php');
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$userRole  = $_SESSION['user_role'] ?? 'lecteur';
$username  = htmlspecialchars($_SESSION['user_name']  ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$firstName = htmlspecialchars(explode(' ', trim($username))[0] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$avatar    = strtoupper(substr($username, 0, 1)) ?: 'U';

// ── Helpers ───────────────────────────────────────────────────
function fmtFCFA(float $n): string {
    return number_format($n, 0, ',', ' ') . ' FCFA';
}

function safeEcho(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function timeAgoLib(?string $d): string {
    if (!$d) return '—';
    $diff = time() - strtotime($d);
    if ($diff < 0)      return 'à l\'instant';
    if ($diff < 60)     return 'à l\'instant';
    if ($diff < 3600)   return (int)($diff / 60) . ' min';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h';
    if ($diff < 604800) return (int)($diff / 86400) . 'j';
    return date('d/m/Y', strtotime($d));
}

function csrfLib(): string {
    if (empty($_SESSION['csrf_lib'])) {
        $_SESSION['csrf_lib'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_lib'];
}

// ── Création des tables manquantes ───────────────────────────
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fav (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_collections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            nom VARCHAR(255) NOT NULL,
            description TEXT NULL,
            couleur VARCHAR(20) DEFAULT '#4a9eff',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS collection_books (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            collection_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_col_livre (collection_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS avis (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            note TINYINT UNSIGNED DEFAULT 0,
            commentaire TEXT,
            statut ENUM('publie','en_attente','refuse') DEFAULT 'publie',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_livre (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS reading_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            duree_minutes INT UNSIGNED DEFAULT 0,
            pages_lues INT UNSIGNED DEFAULT 0,
            session_date DATE NOT NULL DEFAULT (CURRENT_DATE),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Colonnes optionnelles sur lecture_progression
        foreach ([
            "ALTER TABLE lecture_progression ADD COLUMN IF NOT EXISTS statut ENUM('en_cours','termine') NOT NULL DEFAULT 'en_cours'",
            "ALTER TABLE lecture_progression ADD COLUMN IF NOT EXISTS total_pages INT UNSIGNED DEFAULT 0",
            "ALTER TABLE lecture_progression ADD COLUMN IF NOT EXISTS temps_lecture_minutes INT UNSIGNED DEFAULT 0",
            "ALTER TABLE lecture_progression ADD COLUMN IF NOT EXISTS date_debut TIMESTAMP NULL",
            "ALTER TABLE lecture_progression ADD COLUMN IF NOT EXISTS date_fin TIMESTAMP NULL",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }

        // Colonnes notifications
        foreach ([
            "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS titre VARCHAR(255) NULL",
            "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS icon VARCHAR(10) DEFAULT '🔔'",
            "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS bg VARCHAR(80) DEFAULT 'rgba(0,212,255,.08)'",
            "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS lu TINYINT(1) DEFAULT 0",
            "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_read TINYINT(1) DEFAULT 0",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }

        // Colonnes livres
        foreach ([
            "ALTER TABLE livres ADD COLUMN IF NOT EXISTS nb_etoiles INT UNSIGNED DEFAULT 0",
            "ALTER TABLE livres ADD COLUMN IF NOT EXISTS access_type ENUM('premium','standard','gratuit') DEFAULT 'standard'",
            "ALTER TABLE livres ADD COLUMN IF NOT EXISTS is_featured TINYINT(1) DEFAULT 0",
            "ALTER TABLE livres ADD COLUMN IF NOT EXISTS is_bestseller TINYINT(1) DEFAULT 0",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) {}
        }

    } catch (Throwable $e) {
        error_log('[DLS-Library] Schema: ' . $e->getMessage());
    }
}

// ── Chargement des données ────────────────────────────────────
$library = [
    'purchased'    => [],
    'in_progress'  => [],
    'finished'     => [],
    'favorites'    => [],
    'collections'  => [],
    'downloads'    => [],
    'notifications'=> [],
    'stats'        => [],
    'bonus'        => ['bonus_restant' => 0, 'achat_count' => 0, 'bonus_total' => 0],
    'recommendations' => [],
    'activity_chart'  => [],
    'top_auteurs'     => [],
    'notif_count'     => 0,
];
$errors = [];

if ($pdo) {
    try {
        // Livres achetés + progression + favoris + avis
        $stmt = $pdo->prepare("
            SELECT
                l.id, l.titre, l.auteur, l.prix, l.access_type,
                l.note_moyenne, l.nb_etoiles, l.pages, l.fichier_pdf,
                l.is_featured, l.is_bestseller, l.annee_parution,
                c.nom   AS categorie_nom,
                c.icone AS categorie_icone,
                c.couleur AS categorie_couleur,
                a.montant AS prix_paye,
                a.created_at AS date_achat,
                a.methode,
                lp.page_actuelle,
                lp.pourcentage,
                lp.statut AS lect_statut,
                lp.temps_lecture_minutes,
                lp.updated_at AS derniere_lecture,
                ud.count AS nb_telechargements,
                f.id   AS is_favorite,
                av.note AS ma_note,
                av.commentaire AS mon_avis
            FROM achats a
            JOIN livres l ON l.id = a.livre_id
            LEFT JOIN categories c           ON c.id = l.categorie_id
            LEFT JOIN lecture_progression lp ON lp.user_id = a.user_id AND lp.livre_id = l.id
            LEFT JOIN user_downloads ud      ON ud.user_id = a.user_id AND ud.livre_id = l.id
            LEFT JOIN favorites f            ON f.user_id  = a.user_id AND f.livre_id  = l.id
            LEFT JOIN avis av               ON av.user_id = a.user_id AND av.livre_id  = l.id
            WHERE a.user_id = ? AND a.statut = 'confirme'
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$userId]);
        $library['purchased'] = $stmt->fetchAll();

        // Catégories
        $library['in_progress'] = array_values(array_filter($library['purchased'], fn($b) => ($b['lect_statut'] ?? '') === 'en_cours' && ((float)($b['pourcentage'] ?? 0)) > 0));
        $library['finished']    = array_values(array_filter($library['purchased'], fn($b) => ($b['lect_statut'] ?? '') === 'termine'));
        $library['favorites']   = array_values(array_filter($library['purchased'], fn($b) => !empty($b['is_favorite'])));

        // Stats personnelles
        $stmtSt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT a.livre_id)                                        AS livres_achetes,
                COALESCE(SUM(a.montant), 0)                                       AS total_depense,
                COUNT(CASE WHEN lp.statut='termine' THEN 1 END)                   AS livres_termines,
                COUNT(CASE WHEN lp.statut='en_cours' AND lp.pourcentage>0 THEN 1 END) AS en_cours,
                COALESCE(SUM(lp.temps_lecture_minutes), 0)                        AS temps_lecture_total,
                COUNT(DISTINCT ud.livre_id)                                       AS livres_telecharges
            FROM achats a
            LEFT JOIN lecture_progression lp ON lp.user_id=a.user_id AND lp.livre_id=a.livre_id
            LEFT JOIN user_downloads ud      ON ud.user_id=a.user_id
            WHERE a.user_id=? AND a.statut='confirme'
        ");
        $stmtSt->execute([$userId]);
        $library['stats'] = $stmtSt->fetch() ?: [];

        // Auteurs favoris
        $stmtAut = $pdo->prepare("
            SELECT l.auteur, COUNT(*) AS nb
            FROM achats a JOIN livres l ON l.id=a.livre_id
            WHERE a.user_id=? AND a.statut='confirme'
            GROUP BY l.auteur ORDER BY nb DESC LIMIT 3
        ");
        $stmtAut->execute([$userId]);
        $library['top_auteurs'] = $stmtAut->fetchAll();

        // Bonus
        try {
            $stmtBon = $pdo->prepare("SELECT * FROM user_bonus WHERE user_id=?");
            $stmtBon->execute([$userId]);
            $library['bonus'] = $stmtBon->fetch() ?: ['bonus_restant' => 0, 'achat_count' => 0, 'bonus_total' => 0];
        } catch (Exception $e) {
            $library['bonus'] = ['bonus_restant' => 0, 'achat_count' => 0, 'bonus_total' => 0];
        }

        // Collections
        $stmtCol = $pdo->prepare("
            SELECT uc.*, COUNT(cb.livre_id) AS nb_livres
            FROM user_collections uc
            LEFT JOIN collection_books cb ON cb.collection_id = uc.id
            WHERE uc.user_id=?
            GROUP BY uc.id ORDER BY uc.created_at DESC
        ");
        $stmtCol->execute([$userId]);
        $library['collections'] = $stmtCol->fetchAll();

        foreach ($library['collections'] as &$col) {
            $stmtBk = $pdo->prepare("
                SELECT l.id, l.titre, l.auteur, c.nom AS cat_nom
                FROM collection_books cb
                JOIN livres l ON l.id = cb.livre_id
                LEFT JOIN categories c ON c.id = l.categorie_id
                WHERE cb.collection_id=? LIMIT 6
            ");
            $stmtBk->execute([$col['id']]);
            $col['books'] = $stmtBk->fetchAll();
        }
        unset($col);

        // Téléchargements
        $stmtDl = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, ud.count AS nb_dl, ud.last_dl_at
            FROM user_downloads ud
            JOIN livres l ON l.id = ud.livre_id
            WHERE ud.user_id=? ORDER BY ud.last_dl_at DESC LIMIT 10
        ");
        $stmtDl->execute([$userId]);
        $library['downloads'] = $stmtDl->fetchAll();

        // Notifications — compatible avec les deux schémas (lu ou is_read)
        try {
            $stmtNotif = $pdo->prepare("
                SELECT *,
                       COALESCE(lu, is_read, 0) AS _lu_unified,
                       COALESCE(titre, title, '') AS _titre_unified
                FROM notifications
                WHERE user_id=? OR user_id IS NULL
                ORDER BY created_at DESC LIMIT 8
            ");
            $stmtNotif->execute([$userId]);
            $library['notifications'] = $stmtNotif->fetchAll();

            $stmtNc = $pdo->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE COALESCE(lu, is_read, 0)=0
                  AND (user_id=? OR user_id IS NULL)
            ");
            $stmtNc->execute([$userId]);
            $library['notif_count'] = (int)$stmtNc->fetchColumn();
        } catch (Exception $e) {
            $library['notifications'] = [];
            $library['notif_count']   = 0;
        }

        // Recommandations
        $purchasedIds  = array_column($library['purchased'], 'id');
        $excludeClause = $purchasedIds
            ? 'AND l.id NOT IN (' . implode(',', array_map('intval', $purchasedIds)) . ')'
            : '';

        $stmtCatFav = $pdo->prepare("
            SELECT l.categorie_id
            FROM achats a JOIN livres l ON l.id=a.livre_id
            WHERE a.user_id=? AND a.statut='confirme' AND l.categorie_id IS NOT NULL
            GROUP BY l.categorie_id ORDER BY COUNT(*) DESC LIMIT 3
        ");
        $stmtCatFav->execute([$userId]);
        $favCatIds  = array_column($stmtCatFav->fetchAll(), 'categorie_id');
        $catClause  = $favCatIds
            ? 'AND l.categorie_id IN (' . implode(',', array_map('intval', $favCatIds)) . ')'
            : '';

        $stmtRec = $pdo->query("
            SELECT l.id, l.titre, l.auteur, l.prix, l.access_type, l.note_moyenne,
                   c.nom AS categorie_nom, c.icone AS categorie_icone
            FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE l.statut='disponible' {$excludeClause} {$catClause}
            ORDER BY l.note_moyenne DESC, l.nb_ventes DESC LIMIT 8
        ");
        $library['recommendations'] = $stmtRec ? $stmtRec->fetchAll() : [];

        // Activité lecture 7 jours
        $stmtAct = $pdo->prepare("
            SELECT session_date, SUM(duree_minutes) AS minutes, SUM(pages_lues) AS pages
            FROM reading_sessions
            WHERE user_id=? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY session_date ORDER BY session_date
        ");
        $stmtAct->execute([$userId]);
        $rawActivity = $stmtAct->fetchAll();

        $dayNames = ['Mon' => 'Lun', 'Tue' => 'Mar', 'Wed' => 'Mer', 'Thu' => 'Jeu',
                     'Fri' => 'Ven', 'Sat' => 'Sam', 'Sun' => 'Dim'];

        $library['activity_chart'] = [];
        for ($i = 6; $i >= 0; $i--) {
            $d   = date('Y-m-d', strtotime("-{$i} days"));
            $lbl = $dayNames[date('D', strtotime($d))] ?? date('D', strtotime($d));
            $min = 0;
            foreach ($rawActivity as $row) {
                if ($row['session_date'] === $d) { $min = (int)$row['minutes']; break; }
            }
            $library['activity_chart'][] = ['label' => $lbl, 'minutes' => $min, 'date' => $d];
        }

    } catch (Throwable $e) {
        error_log('[DLS-Library] Data load: ' . $e->getMessage());
        $errors[] = $e->getMessage();
    }
}

// ── Variables de template ─────────────────────────────────────
$csrf        = csrfLib();
$stats       = $library['stats']    ?? [];
$purchased   = $library['purchased'] ?? [];
$inProgress  = $library['in_progress'] ?? [];
$finished    = $library['finished']    ?? [];
$favorites   = $library['favorites']   ?? [];
$collections = $library['collections'] ?? [];
$downloads   = $library['downloads']   ?? [];
$notifCount  = (int)($library['notif_count'] ?? 0);
$bonus       = $library['bonus']       ?? [];
$recs        = $library['recommendations'] ?? [];
$actChart    = $library['activity_chart']  ?? [];
$topAuthors  = $library['top_auteurs']     ?? [];

$bookEmojis = ['📖','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌊','⚡','🔮','🗺️','🏛️','🎯'];

// ── Purger le buffer et démarrer le HTML ──────────────────────
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ma Bibliothèque — Digital Library</title>
<meta name="description" content="Votre bibliothèque personnelle numérique">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ═══════════════════════════════════════════════
   RESET & VARIABLES
═══════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg-base:    #06090f;
  --bg-surface: #0c1221;
  --bg-card:    rgba(255,255,255,.032);
  --bg-card-hov:rgba(255,255,255,.058);
  --border:     rgba(255,255,255,.072);
  --border-act: rgba(0,212,255,.38);
  --cyan:   #00d4ff; --violet: #7c3aed; --neon: #00ffaa;
  --amber:  #f59e0b; --rose:   #f43f5e; --gold: #fbbf24;
  --orange: #f97316;
  --text-primary:   #eef2ff;
  --text-secondary: rgba(238,242,255,.56);
  --text-muted:     rgba(238,242,255,.28);
  --sidebar-w: 258px; --topbar-h: 60px;
  --glow-cyan: 0 0 28px rgba(0,212,255,.18);
  --shadow-card: 0 4px 24px rgba(0,0,0,.32);
  --shadow-lg:   0 20px 60px rgba(0,0,0,.5);
  --r-sm:6px; --r-md:12px; --r-lg:18px; --r-xl:26px; --r-2xl:34px;
}
html { scroll-behavior:smooth; }
body { font-family:'DM Sans',sans-serif; background:var(--bg-base); color:var(--text-primary); overflow-x:hidden; min-height:100vh; }
::-webkit-scrollbar { width:3px; height:3px; }
::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:4px; }

/* ── LAYOUT ── */
.app { display:flex; min-height:100vh; }

/* ── SIDEBAR ── */
#sidebar {
  position:fixed; top:0; left:0; bottom:0; width:var(--sidebar-w);
  background:var(--bg-surface); border-right:1px solid var(--border);
  display:flex; flex-direction:column; z-index:200; overflow:hidden;
  transition:transform .3s cubic-bezier(.4,0,.2,1);
}
.sb-brand {
  height:var(--topbar-h); display:flex; align-items:center; gap:10px;
  padding:0 18px; border-bottom:1px solid var(--border); flex-shrink:0;
}
.sb-logo {
  width:36px; height:36px; border-radius:11px; flex-shrink:0;
  background:linear-gradient(135deg,var(--cyan),var(--violet));
  display:flex; align-items:center; justify-content:center;
  font-size:1.1rem; box-shadow:var(--glow-cyan);
}
.sb-name { font-family:'Syne',sans-serif; font-weight:800; font-size:.88rem; }
.sb-name em { color:var(--cyan); font-style:normal; }
.sb-user {
  display:flex; align-items:center; gap:10px; padding:13px 18px;
  border-bottom:1px solid var(--border); flex-shrink:0;
}
.sb-av {
  width:38px; height:38px; border-radius:12px; flex-shrink:0;
  background:linear-gradient(135deg,var(--neon),var(--cyan));
  display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-weight:800; font-size:.9rem; color:#000;
}
.sb-uname { font-family:'Syne',sans-serif; font-weight:700; font-size:.83rem; }
.sb-urole { font-size:.6rem; color:var(--neon); font-family:'Space Mono',monospace; margin-top:2px; }
.sb-nav { flex:1; overflow-y:auto; padding:8px 0; }
.sb-sect {
  font-family:'Space Mono',monospace; font-size:.58rem; letter-spacing:.12em;
  text-transform:uppercase; color:var(--text-muted); padding:10px 18px 3px;
}
.sb-item {
  display:flex; align-items:center; gap:10px; padding:9px 18px; margin:1px 8px;
  border-radius:var(--r-sm); text-decoration:none; color:var(--text-secondary);
  font-size:.82rem; font-weight:500; transition:all .15s; position:relative; cursor:pointer;
}
.sb-item:hover { color:var(--text-primary); background:var(--bg-card-hov); }
.sb-item.active {
  color:var(--neon); background:rgba(0,255,170,.07);
  border:1px solid rgba(0,255,170,.1);
}
.sb-item.active::before {
  content:''; position:absolute; left:0; top:50%; transform:translateY(-50%);
  width:3px; height:16px; background:var(--neon); border-radius:0 3px 3px 0;
}
.sb-badge {
  margin-left:auto; font-size:.58rem; font-family:'Space Mono',monospace;
  padding:2px 6px; border-radius:100px; background:var(--neon); color:#000; font-weight:700;
}
.sb-footer { padding:10px; border-top:1px solid var(--border); }

/* ── MAIN ── */
.main { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; }

/* ── TOPBAR ── */
#topbar {
  height:var(--topbar-h); background:rgba(6,9,15,.88); backdrop-filter:blur(22px);
  border-bottom:1px solid var(--border); display:flex; align-items:center;
  gap:1rem; padding:0 1.6rem; position:sticky; top:0; z-index:100;
}
.tb-path { font-size:.78rem; color:var(--text-secondary); display:flex; align-items:center; gap:6px; }
.tb-sep  { opacity:.3; }
.tb-curr { font-family:'Syne',sans-serif; font-weight:700; color:var(--text-primary); }
.tb-spacer { flex:1; }
.tb-search-wrap { position:relative; }
.tb-search {
  display:flex; align-items:center; gap:7px; background:var(--bg-card);
  border:1px solid var(--border); border-radius:var(--r-sm);
  padding:6px 12px; width:230px; transition:border-color .2s,box-shadow .2s;
}
.tb-search:focus-within { border-color:var(--border-act); box-shadow:var(--glow-cyan); }
.tb-search input { background:none; border:none; outline:none; color:var(--text-primary); font-size:.78rem; font-family:'DM Sans',sans-serif; width:100%; }
.tb-search input::placeholder { color:var(--text-muted); }
.tb-acts { display:flex; align-items:center; gap:5px; }
.tb-btn {
  width:34px; height:34px; border-radius:var(--r-sm); background:var(--bg-card);
  border:1px solid var(--border); color:var(--text-secondary);
  display:flex; align-items:center; justify-content:center; cursor:pointer;
  font-size:.95rem; transition:all .15s; text-decoration:none; position:relative;
}
.tb-btn:hover { color:var(--text-primary); background:var(--bg-card-hov); }
.n-badge {
  position:absolute; top:-3px; right:-3px; min-width:15px; height:15px; padding:0 3px;
  background:var(--rose); border-radius:50%; font-size:.5rem; font-weight:700;
  font-family:'Space Mono',monospace; display:flex; align-items:center;
  justify-content:center; border:2px solid var(--bg-base); color:#fff;
}
.tb-ham {
  display:none; background:none; border:none; color:var(--text-primary);
  font-size:1.3rem; cursor:pointer; width:34px; height:34px;
  align-items:center; justify-content:center; border-radius:var(--r-sm);
}

/* ── PAGE ── */
.page { padding:1.8rem 2rem 5rem; max-width:1440px; width:100%; margin:0 auto; }

/* ── HERO ── */
.hero {
  background:linear-gradient(135deg,rgba(0,212,255,.06),rgba(124,58,237,.08),rgba(0,255,170,.05));
  border:1px solid rgba(0,212,255,.1); border-radius:var(--r-2xl);
  padding:2rem 2.4rem; margin-bottom:2rem; position:relative; overflow:hidden;
  display:flex; align-items:center; justify-content:space-between; gap:1rem;
  animation:fadeUp .4s ease both;
}
.hero::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon));
}
.hero::after {
  content:''; position:absolute; right:-60px; top:-60px; width:240px; height:240px;
  background:radial-gradient(circle,rgba(0,212,255,.07) 0%,transparent 65%);
  pointer-events:none;
}
.hero-title { font-family:'Syne',sans-serif; font-size:1.5rem; font-weight:800; margin-bottom:4px; }
.hero-sub   { font-size:.83rem; color:var(--text-secondary); line-height:1.5; }
.hero-pills { display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; }
.pill {
  display:inline-flex; align-items:center; gap:4px; font-family:'Space Mono',monospace;
  font-size:.6rem; padding:3px 10px; border-radius:100px;
  text-transform:uppercase; font-weight:700;
}
.pill-neon   { background:rgba(0,255,170,.1);  color:var(--neon);   border:1px solid rgba(0,255,170,.2); }
.pill-cyan   { background:rgba(0,212,255,.1);  color:var(--cyan);   border:1px solid rgba(0,212,255,.2); }
.pill-violet { background:rgba(124,58,237,.1); color:#a78bfa;       border:1px solid rgba(124,58,237,.2); }
.pill-amber  { background:rgba(245,158,11,.1); color:var(--amber);  border:1px solid rgba(245,158,11,.2); }
.hero-cta { display:flex; gap:8px; flex-wrap:wrap; }

/* ── TABS ── */
.tabs {
  display:flex; gap:2px; background:var(--bg-surface); border:1px solid var(--border);
  border-radius:var(--r-lg); padding:5px; margin-bottom:1.8rem; overflow-x:auto;
  scrollbar-width:none;
}
.tabs::-webkit-scrollbar { display:none; }
.tab-btn {
  display:flex; align-items:center; gap:7px; padding:8px 14px;
  border-radius:var(--r-md); font-family:'Syne',sans-serif; font-size:.77rem;
  font-weight:700; cursor:pointer; background:none; border:none;
  color:var(--text-muted); white-space:nowrap; transition:all .18s;
}
.tab-btn:hover  { color:var(--text-secondary); background:var(--bg-card-hov); }
.tab-btn.active { color:var(--text-primary); background:rgba(0,212,255,.1); border:1px solid rgba(0,212,255,.18); }
.tab-count {
  font-size:.6rem; font-family:'Space Mono',monospace; padding:2px 6px;
  border-radius:100px; background:rgba(255,255,255,.08); color:var(--text-muted);
}
.tab-btn.active .tab-count { background:rgba(0,212,255,.15); color:var(--cyan); }

/* ── PANELS ── */
.tab-panel         { display:none; }
.tab-panel.active  { display:block; animation:fadeUp .3s ease both; }

/* ── STATS GRID ── */
.stats-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(165px,1fr)); gap:1rem; margin-bottom:2rem; }
.sc {
  background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-md);
  padding:1.2rem; transition:all .22s; position:relative; overflow:hidden;
  animation:fadeUp .5s ease both;
}
.sc::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,var(--ac1,#fff),var(--ac2,#888));
  opacity:0; transition:opacity .3s;
}
.sc:hover { transform:translateY(-4px); box-shadow:var(--shadow-card); }
.sc:hover::before { opacity:1; }
.sc:nth-child(1){--ac1:var(--neon);  --ac2:var(--cyan);   animation-delay:.05s}
.sc:nth-child(2){--ac1:var(--cyan);  --ac2:var(--violet); animation-delay:.1s}
.sc:nth-child(3){--ac1:var(--amber); --ac2:var(--orange); animation-delay:.15s}
.sc:nth-child(4){--ac1:var(--violet);--ac2:var(--rose);   animation-delay:.2s}
.sc:nth-child(5){--ac1:var(--rose);  --ac2:var(--amber);  animation-delay:.25s}
.sc:nth-child(6){--ac1:var(--neon);  --ac2:var(--amber);  animation-delay:.3s}
.sc-icon { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; margin-bottom:.9rem; }
.sc-val  {
  font-family:'Syne',sans-serif; font-size:1.7rem; font-weight:800; letter-spacing:-.5px;
  background:linear-gradient(135deg,var(--ac1,#fff),var(--ac2,#aaa));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.sc-lbl { font-size:.72rem; color:var(--text-secondary); margin-top:4px; font-weight:500; }

/* ── BOOKS GRID ── */
.books-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(188px,1fr)); gap:1.2rem; }
.book-card {
  background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-lg);
  overflow:hidden; transition:all .22s cubic-bezier(.4,0,.2,1);
  position:relative; animation:fadeUp .5s ease both;
}
.book-card:hover {
  transform:translateY(-8px) scale(1.01); border-color:rgba(0,212,255,.25);
  box-shadow:0 20px 50px rgba(0,0,0,.45);
}
.book-cover {
  height:130px; display:flex; align-items:center; justify-content:center;
  font-size:2.8rem; position:relative;
  background:linear-gradient(135deg,rgba(12,18,33,.9),rgba(124,58,237,.2)); overflow:hidden;
}
.book-cover::after {
  content:''; position:absolute; inset:0;
  background:linear-gradient(to bottom,transparent 40%,var(--bg-surface) 100%);
}
.cover-badge {
  position:absolute; top:8px; right:8px; z-index:2; font-size:.55rem;
  font-family:'Space Mono',monospace; padding:2px 7px; border-radius:100px; font-weight:700;
}
.badge-premium { background:linear-gradient(135deg,#7c3aed,#a78bfa); color:#fff; }
.badge-free    { background:rgba(0,255,170,.2); color:var(--neon); border:1px solid rgba(0,255,170,.3); }
.badge-best    { background:linear-gradient(135deg,var(--amber),var(--orange)); color:#000; }
.badge-done    { background:linear-gradient(135deg,var(--neon),#00a882); color:#000; }
.book-body     { padding:12px; }
.book-cat      { font-family:'Space Mono',monospace; font-size:.56rem; color:var(--cyan); text-transform:uppercase; letter-spacing:.06em; }
.book-title    { font-family:'Syne',sans-serif; font-size:.85rem; font-weight:700; margin:4px 0 3px; line-height:1.25; }
.book-author   { font-size:.7rem; color:var(--text-secondary); }
.book-prog     { padding:0 12px 8px; }
.prog-row      { display:flex; align-items:center; gap:8px; margin-bottom:4px; }
.prog-bar      { flex:1; height:4px; background:rgba(255,255,255,.06); border-radius:100px; overflow:hidden; }
.prog-fill     { height:100%; border-radius:100px; background:linear-gradient(90deg,var(--cyan),var(--violet)); transition:width 1.2s ease; box-shadow:0 0 8px rgba(0,212,255,.3); }
.prog-fill.green { background:linear-gradient(90deg,var(--neon),#00a882); }
.prog-pct      { font-family:'Space Mono',monospace; font-size:.6rem; color:var(--text-muted); flex-shrink:0; }
.book-footer   {
  padding:9px 12px; border-top:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between; gap:4px;
}
.book-actions  { display:flex; gap:3px; }
.ic-btn {
  width:28px; height:28px; border-radius:8px; background:var(--bg-card-hov);
  border:1px solid var(--border); color:var(--text-secondary);
  display:flex; align-items:center; justify-content:center; cursor:pointer;
  font-size:.75rem; transition:all .15s; text-decoration:none;
}
.ic-btn:hover { color:var(--text-primary); background:rgba(0,212,255,.08); border-color:rgba(0,212,255,.2); }
.ic-btn.fav-active { color:var(--amber); background:rgba(245,158,11,.08); border-color:rgba(245,158,11,.25); }

/* ── STARS ── */
.stars   { display:flex; gap:1px; }
.star    { font-size:.8rem; cursor:pointer; color:rgba(255,255,255,.15); transition:color .15s; }
.star.filled, .star:hover { color:var(--amber); }

/* ── CARD ── */
.card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; margin-bottom:1.5rem; animation:fadeUp .5s ease both; }
.card-header { padding:1.2rem 1.5rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:1rem; }
.card-title  { font-family:'Syne',sans-serif; font-weight:700; font-size:.92rem; display:flex; align-items:center; gap:8px; }
.c-icon      { width:30px; height:30px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.88rem; }
.card-body   { padding:1.2rem 1.5rem; }
.card-footer { padding:.9rem 1.5rem; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }

/* ── BUTTONS ── */
.btn {
  display:inline-flex; align-items:center; gap:6px; padding:8px 16px;
  border-radius:var(--r-sm); font-family:'Syne',sans-serif; font-size:.78rem;
  font-weight:700; cursor:pointer; transition:all .18s; text-decoration:none; border:none; white-space:nowrap;
}
.btn-sm { padding:5px 11px; font-size:.72rem; }
.btn-xs { padding:3px 8px;  font-size:.66rem; }
.btn-primary { background:linear-gradient(135deg,var(--cyan),var(--violet)); color:#fff; box-shadow:0 4px 14px rgba(0,212,255,.18); }
.btn-primary:hover { opacity:.88; transform:translateY(-1px); box-shadow:0 6px 22px rgba(0,212,255,.3); }
.btn-ghost   { background:var(--bg-card); border:1px solid var(--border); color:var(--text-secondary); }
.btn-ghost:hover  { color:var(--text-primary); border-color:rgba(255,255,255,.14); background:var(--bg-card-hov); }
.btn-neon    { background:rgba(0,255,170,.1); border:1px solid rgba(0,255,170,.25); color:var(--neon); }
.btn-neon:hover   { background:rgba(0,255,170,.18); }
.btn-danger  { background:rgba(244,63,94,.1); border:1px solid rgba(244,63,94,.22); color:var(--rose); }
.btn-danger:hover { background:rgba(244,63,94,.18); }

/* ── EMPTY STATE ── */
.empty-state       { text-align:center; padding:3rem 2rem; }
.empty-icon        { font-size:3.5rem; margin-bottom:.8rem; opacity:.6; }
.empty-title       { font-family:'Syne',sans-serif; font-size:1.1rem; font-weight:700; margin-bottom:.4rem; }
.empty-sub         { font-size:.82rem; color:var(--text-muted); margin-bottom:1.2rem; }

/* ── LIST ITEMS ── */
.lib-item          { display:flex; align-items:center; gap:14px; padding:12px 0; border-bottom:1px solid rgba(255,255,255,.04); }
.lib-item:last-child { border-bottom:none; }
.li-emoji          { font-size:1.9rem; flex-shrink:0; width:44px; text-align:center; }
.li-info           { flex:1; min-width:0; }
.li-title          { font-family:'Syne',sans-serif; font-weight:700; font-size:.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.li-meta           { font-size:.67rem; color:var(--text-muted); font-family:'Space Mono',monospace; margin-top:2px; }
.li-prog           { display:flex; align-items:center; gap:7px; margin-top:5px; }

/* ── PROGRESS RING ── */
.ring-wrap  { position:relative; width:52px; height:52px; flex-shrink:0; }
.ring-svg   { transform:rotate(-90deg); }
.ring-track { fill:none; stroke:rgba(255,255,255,.06); stroke-width:4; }
.ring-fill  { fill:none; stroke:var(--cyan); stroke-width:4; stroke-linecap:round; transition:stroke-dashoffset 1.2s ease; }
.ring-fill.done { stroke:var(--neon); }
.ring-label { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-family:'Space Mono',monospace; font-size:.58rem; font-weight:700; }

/* ── COLLECTIONS ── */
.col-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:1.2rem; }
.col-card {
  background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-lg);
  overflow:hidden; transition:all .2s; animation:fadeUp .5s ease both;
}
.col-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-card); }
.col-header     { padding:14px 16px; border-bottom:1px solid var(--border); position:relative; }
.col-color-bar  { position:absolute; top:0; left:0; right:0; height:3px; }
.col-name       { font-family:'Syne',sans-serif; font-weight:700; font-size:.9rem; margin-top:4px; }
.col-desc       { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
.col-body       { padding:12px 16px; }
.col-books-preview { display:flex; gap:5px; flex-wrap:wrap; margin-top:8px; }
.col-book-chip  {
  font-size:.62rem; padding:3px 8px; border-radius:100px;
  background:var(--bg-card-hov); border:1px solid var(--border);
  color:var(--text-muted); white-space:nowrap; overflow:hidden;
  max-width:120px; text-overflow:ellipsis;
}
.col-footer { padding:9px 16px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }

/* ── CHART BARS ── */
.chart-bars { display:flex; align-items:flex-end; gap:6px; height:90px; padding:6px 0; }
.cb-wrap    { flex:1; display:flex; flex-direction:column; align-items:center; gap:5px; }
.cb {
  width:100%; border-radius:5px 5px 0 0; min-height:4px;
  background:linear-gradient(to top,var(--cyan),rgba(0,212,255,.25));
  transition:height 1s ease; position:relative; cursor:pointer;
}
.cb:hover { filter:brightness(1.3); }
.cb-tt {
  position:absolute; bottom:calc(100% + 4px); left:50%; transform:translateX(-50%);
  font-family:'Space Mono',monospace; font-size:.55rem; color:var(--text-primary);
  background:var(--bg-surface); border:1px solid var(--border);
  padding:2px 6px; border-radius:4px; opacity:0; transition:opacity .18s;
  pointer-events:none; white-space:nowrap;
}
.cb:hover .cb-tt { opacity:1; }
.cb-lbl { font-family:'Space Mono',monospace; font-size:.55rem; color:var(--text-muted); }

/* ── NOTIFICATIONS ── */
#notif-panel {
  position:fixed; top:var(--topbar-h); right:1rem; width:310px;
  background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-lg);
  box-shadow:var(--shadow-lg); z-index:500;
  transform:translateY(-10px) scale(.97); opacity:0; pointer-events:none;
  transition:all .22s cubic-bezier(.34,1.56,.64,1); overflow:hidden;
}
#notif-panel.open { transform:translateY(6px) scale(1); opacity:1; pointer-events:all; }
.np-hd   { padding:.9rem 1.1rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; font-family:'Syne',sans-serif; font-weight:700; font-size:.85rem; }
.np-list { max-height:320px; overflow-y:auto; }
.np-item { display:flex; gap:10px; padding:10px 1.1rem; border-bottom:1px solid rgba(255,255,255,.04); cursor:pointer; transition:background .12s; font-size:.76rem; }
.np-item:hover  { background:var(--bg-card-hov); }
.np-icon        { width:30px; height:30px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.np-txt         { color:var(--text-secondary); line-height:1.45; }
.np-time        { font-size:.6rem; font-family:'Space Mono',monospace; color:var(--text-muted); margin-top:2px; }
.np-unread      { background:rgba(0,212,255,.03); }

/* ── MODALS ── */
.modal-overlay { position:fixed; inset:0; background:rgba(6,9,15,.88); backdrop-filter:blur(14px); z-index:800; display:flex; align-items:center; justify-content:center; padding:1rem; opacity:0; pointer-events:none; transition:opacity .25s; }
.modal-overlay.open { opacity:1; pointer-events:all; }
.modal-box {
  background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-xl);
  padding:2rem; max-width:500px; width:100%; box-shadow:var(--shadow-lg);
  transform:translateY(20px); transition:transform .3s cubic-bezier(.34,1.56,.64,1);
  position:relative; overflow:hidden; max-height:90vh; overflow-y:auto;
}
.modal-overlay.open .modal-box { transform:translateY(0); }
.modal-box::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon)); }
.modal-title { font-family:'Syne',sans-serif; font-weight:800; font-size:1.1rem; margin-bottom:1rem; }
.modal-close { position:absolute; top:1rem; right:1rem; background:none; border:none; color:var(--text-muted); font-size:1rem; cursor:pointer; width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.modal-close:hover { color:var(--text-primary); background:var(--bg-card-hov); }
.form-label  { font-size:.68rem; font-family:'Space Mono',monospace; color:var(--text-muted); letter-spacing:.05em; text-transform:uppercase; display:block; margin-bottom:5px; }
.form-input  { width:100%; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-sm); padding:9px 13px; color:var(--text-primary); font-size:.83rem; font-family:'DM Sans',sans-serif; outline:none; margin-bottom:1rem; transition:border-color .2s; }
.form-input:focus { border-color:var(--border-act); box-shadow:var(--glow-cyan); }
.form-textarea { resize:vertical; min-height:80px; }
.color-picker-row { display:flex; gap:7px; margin-bottom:1rem; flex-wrap:wrap; }
.color-dot { width:26px; height:26px; border-radius:50%; cursor:pointer; border:2px solid transparent; transition:all .15s; }
.color-dot.selected { border-color:var(--text-primary); transform:scale(1.15); }

/* ── PDF READER ── */
#pdf-modal { z-index:900; }
#pdf-container { background:#111; border-radius:var(--r-lg); overflow:hidden; position:relative; min-height:500px; display:flex; flex-direction:column; }
#pdf-toolbar    { background:var(--bg-surface); border-bottom:1px solid var(--border); padding:10px 14px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
#pdf-canvas-wrap{ flex:1; overflow:auto; display:flex; justify-content:center; padding:1rem; background:#1a1a2e; }
#pdf-canvas     { max-width:100%; box-shadow:0 4px 20px rgba(0,0,0,.5); }
#pdf-page-info  { font-family:'Space Mono',monospace; font-size:.72rem; color:var(--text-muted); white-space:nowrap; }
#pdf-loading    { position:absolute; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.7); font-family:'Space Mono',monospace; font-size:.8rem; color:var(--text-muted); flex-direction:column; gap:.7rem; }
.pdf-spinner    { width:36px; height:36px; border:3px solid rgba(0,212,255,.2); border-top-color:var(--cyan); border-radius:50%; animation:spin 1s linear infinite; }
#pdf-progress-bar { height:3px; background:linear-gradient(90deg,var(--cyan),var(--violet)); transition:width .5s ease; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ── BONUS ── */
.bonus-card { background:linear-gradient(135deg,rgba(124,58,237,.1),rgba(0,212,255,.06)); border:1px solid rgba(124,58,237,.2); border-radius:var(--r-lg); padding:1.4rem; position:relative; overflow:hidden; }
.bonus-glow { position:absolute; top:-40px; right:-40px; width:150px; height:150px; background:radial-gradient(circle,rgba(124,58,237,.15) 0%,transparent 70%); pointer-events:none; }
.bonus-title { font-family:'Syne',sans-serif; font-weight:800; font-size:1rem; display:flex; align-items:center; gap:8px; margin-bottom:.5rem; }
.bonus-prog-wrap { background:rgba(255,255,255,.06); border-radius:100px; height:8px; overflow:hidden; margin:10px 0; }
.bonus-prog { height:100%; border-radius:100px; background:linear-gradient(90deg,var(--violet),var(--cyan)); transition:width 1.5s ease; box-shadow:0 0 12px rgba(124,58,237,.4); }

/* ── CHIP ── */
.chip { display:inline-flex; align-items:center; gap:3px; font-size:.6rem; font-family:'Space Mono',monospace; padding:2px 8px; border-radius:100px; font-weight:700; text-transform:uppercase; }
.chip-neon   { background:rgba(0,255,170,.1);  color:var(--neon);  border:1px solid rgba(0,255,170,.2); }
.chip-cyan   { background:rgba(0,212,255,.1);  color:var(--cyan);  border:1px solid rgba(0,212,255,.2); }
.chip-amber  { background:rgba(245,158,11,.1); color:var(--amber); border:1px solid rgba(245,158,11,.2); }
.chip-violet { background:rgba(124,58,237,.1); color:#a78bfa;      border:1px solid rgba(124,58,237,.2); }
.chip-rose   { background:rgba(244,63,94,.1);  color:var(--rose);  border:1px solid rgba(244,63,94,.2); }
.chip-muted  { background:rgba(255,255,255,.05); color:var(--text-muted); border:1px solid var(--border); }

/* ── TOAST ── */
#toast-stack { position:fixed; bottom:1.4rem; right:1.4rem; z-index:9999; display:flex; flex-direction:column-reverse; gap:7px; pointer-events:none; }
.toast { display:flex; align-items:center; gap:9px; padding:10px 14px; background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-md); box-shadow:var(--shadow-lg); font-size:.78rem; max-width:300px; pointer-events:all; transform:translateX(120px); opacity:0; transition:all .35s cubic-bezier(.34,1.56,.64,1); }
.toast.show { transform:translateX(0); opacity:1; }
.t-title { font-family:'Syne',sans-serif; font-weight:700; }
.t-sub   { color:var(--text-muted); font-size:.68rem; }

/* ── FILTER BUTTONS ── */
.filter-btn.active { background:rgba(0,212,255,.1); border-color:rgba(0,212,255,.3); color:var(--cyan); }

/* ── SIDEBAR OVERLAY MOBILE ── */
#sidebar-overlay { position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:199; opacity:0; pointer-events:none; transition:opacity .3s; }
#sidebar-overlay.show { opacity:1; pointer-events:all; }

/* ── ANIMATIONS ── */
@keyframes fadeUp { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }

/* ── RESPONSIVE ── */
@media(max-width:900px) {
  #sidebar { transform:translateX(-100%); }
  #sidebar.mobile-open { transform:translateX(0); }
  .main { margin-left:0 !important; }
  .tb-ham { display:flex; }
  .tb-search { width:160px; }
}
@media(max-width:600px) {
  .page { padding:1.2rem 1rem 4rem; }
  .hero { flex-direction:column; align-items:flex-start; }
  .stats-grid { grid-template-columns:1fr 1fr; }
  .books-grid { grid-template-columns:1fr 1fr; }
}
</style>
</head>
<body>

<div class="app">

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside id="sidebar" role="navigation" aria-label="Navigation bibliothèque">
  <div class="sb-brand">
    <div class="sb-logo">📚</div>
    <div class="sb-name">Digital <em>Library</em></div>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= $avatar ?></div>
    <div>
      <div class="sb-uname"><?= $username ?></div>
      <div class="sb-urole">📖 Ma bibliothèque</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-sect">Compte</div>
    <a class="sb-item" href="../dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>

    <div class="sb-sect">Ma bibliothèque</div>
    <a class="sb-item active" href="#" onclick="switchTab('all');highlightSidebarItem(this);return false;">
      <i class="bi bi-book-half"></i> Tous les livres
      <?php if (count($purchased)): ?><span class="sb-badge"><?= count($purchased) ?></span><?php endif; ?>
    </a>
    <a class="sb-item" href="#" onclick="switchTab('reading');highlightSidebarItem(this);return false;">
      <i class="bi bi-hourglass-split"></i> En cours
      <?php if (count($inProgress)): ?><span class="sb-badge" style="background:var(--cyan);color:#000"><?= count($inProgress) ?></span><?php endif; ?>
    </a>
    <a class="sb-item" href="#" onclick="switchTab('finished');highlightSidebarItem(this);return false;">
      <i class="bi bi-check-circle"></i> Terminés
      <?php if (count($finished)): ?><span class="sb-badge" style="background:var(--neon);color:#000"><?= count($finished) ?></span><?php endif; ?>
    </a>
    <a class="sb-item" href="#" onclick="switchTab('favorites');highlightSidebarItem(this);return false;">
      <i class="bi bi-star-fill"></i> Favoris
      <?php if (count($favorites)): ?><span class="sb-badge" style="background:var(--amber);color:#000"><?= count($favorites) ?></span><?php endif; ?>
    </a>

    <div class="sb-sect">Organisation</div>
    <a class="sb-item" href="#" onclick="switchTab('collections');highlightSidebarItem(this);return false;">
      <i class="bi bi-collection"></i> Collections
      <?php if (count($collections)): ?><span class="sb-badge" style="background:var(--violet);color:#fff"><?= count($collections) ?></span><?php endif; ?>
    </a>
    <a class="sb-item" href="#" onclick="switchTab('downloads');highlightSidebarItem(this);return false;">
      <i class="bi bi-cloud-download"></i> Téléchargements
    </a>

    <div class="sb-sect">Découverte</div>
    <a class="sb-item" href="#" onclick="switchTab('recommend');highlightSidebarItem(this);return false;">
      <i class="bi bi-stars"></i> Recommandations
    </a>

    <div class="sb-sect">Suivi</div>
    <a class="sb-item" href="#" onclick="switchTab('stats');highlightSidebarItem(this);return false;">
      <i class="bi bi-bar-chart-line"></i> Statistiques
    </a>
    <a class="sb-item" href="#" onclick="switchTab('bonus');highlightSidebarItem(this);return false;">
      <i class="bi bi-gift"></i> Bonus
      <?php if ((int)($bonus['bonus_restant'] ?? 0) > 0): ?>
        <span class="sb-badge" style="background:var(--gold);color:#000"><?= (int)$bonus['bonus_restant'] ?></span>
      <?php endif; ?>
    </a>
    <a class="sb-item" href="../books/index.php"><i class="bi bi-compass"></i> Explorer</a>
    <a class="sb-item" href="../logout.php"><i class="bi bi-box-arrow-left"></i> Déconnexion</a>
  </nav>
</aside>
<div id="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<!-- ═══════════════ MAIN ═══════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header id="topbar">
    <button class="tb-ham" onclick="toggleMobileSidebar()" type="button"><i class="bi bi-list"></i></button>
    <div class="tb-path">
      <span>DLS</span><span class="tb-sep">/</span>
      <a href="../books/index.php" style="color:var(--text-secondary);text-decoration:none">Livres</a>
      <span class="tb-sep">/</span>
      <span class="tb-curr">Ma Bibliothèque</span>
    </div>
    <div class="tb-spacer"></div>
    <div class="tb-search-wrap">
      <div class="tb-search">
        <i class="bi bi-search" style="color:var(--text-muted);font-size:.8rem"></i>
        <input type="search" id="lib-search" placeholder="Chercher dans ma bibliothèque…" autocomplete="off" oninput="filterBooks(this.value)">
      </div>
    </div>
    <div class="tb-acts">
      <button class="tb-btn" id="notif-btn" onclick="toggleNotifPanel()" type="button" aria-label="Notifications">
        <i class="bi bi-bell"></i>
        <?php if ($notifCount > 0): ?>
          <span class="n-badge"><?= min($notifCount, 9) ?></span>
        <?php endif; ?>
      </button>
      <a class="tb-btn" href="../users/profile.php" title="Mon profil">
        <div style="width:22px;height:22px;border-radius:7px;background:linear-gradient(135deg,var(--neon),var(--cyan));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.68rem;color:#000"><?= $avatar ?></div>
      </a>
    </div>
  </header>

  <!-- PAGE -->
  <main class="page" role="main">

    <?php if (!$pdo): ?>
    <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:var(--r-md);padding:1rem 1.4rem;color:var(--amber);font-size:.85rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px">
      <i class="bi bi-exclamation-triangle-fill"></i> Base de données inaccessible. Vérifiez votre configuration.
    </div>
    <?php endif; ?>

    <!-- HERO -->
    <div class="hero">
      <div>
        <div class="hero-title">Ma Bibliothèque 📚</div>
        <div class="hero-sub">
          <?php if (count($purchased) > 0): ?>
            <strong><?= count($purchased) ?></strong> livre<?= count($purchased) > 1 ? 's' : '' ?> dans votre collection
            <?php if (count($inProgress)): ?> · <strong><?= count($inProgress) ?></strong> en cours<?php endif; ?>
            <?php if (count($finished)):   ?> · <strong><?= count($finished) ?></strong> terminé<?= count($finished) > 1 ? 's' : '' ?><?php endif; ?>
          <?php else: ?>
            Votre bibliothèque personnelle numérique vous attend. Commencez votre aventure littéraire !
          <?php endif; ?>
        </div>
        <div class="hero-pills">
          <?php if (count($purchased)): ?><span class="pill pill-neon"><i class="bi bi-book"></i> <?= count($purchased) ?> acheté<?= count($purchased) > 1 ? 's' : '' ?></span><?php endif; ?>
          <?php if (count($favorites)): ?><span class="pill pill-amber"><i class="bi bi-star-fill"></i> <?= count($favorites) ?> favori<?= count($favorites) > 1 ? 's' : '' ?></span><?php endif; ?>
          <?php if ((int)($stats['livres_termines'] ?? 0)): ?><span class="pill pill-cyan"><i class="bi bi-check2-circle"></i> <?= (int)$stats['livres_termines'] ?> terminé<?= (int)$stats['livres_termines'] > 1 ? 's' : '' ?></span><?php endif; ?>
          <?php if ((int)($bonus['bonus_restant'] ?? 0) > 0): ?><span class="pill pill-violet"><i class="bi bi-gift-fill"></i> <?= (int)$bonus['bonus_restant'] ?> bonus</span><?php endif; ?>
        </div>
      </div>
      <div class="hero-cta">
        <a href="../books/index.php" class="btn btn-primary"><i class="bi bi-compass"></i> Explorer</a>
        <button class="btn btn-ghost" onclick="openModal('collection-modal')" type="button"><i class="bi bi-plus-circle"></i> Collection</button>
      </div>
    </div>

    <!-- TABS -->
    <div class="tabs" role="tablist">
      <button class="tab-btn active" onclick="switchTab('all')"         data-tab="all">       <i class="bi bi-grid-3x3-gap"></i> Tous         <span class="tab-count"><?= count($purchased) ?></span></button>
      <button class="tab-btn"        onclick="switchTab('reading')"     data-tab="reading">   <i class="bi bi-hourglass-split"></i> En cours  <span class="tab-count"><?= count($inProgress) ?></span></button>
      <button class="tab-btn"        onclick="switchTab('finished')"    data-tab="finished">  <i class="bi bi-check-circle"></i> Terminés     <span class="tab-count"><?= count($finished) ?></span></button>
      <button class="tab-btn"        onclick="switchTab('favorites')"   data-tab="favorites"> <i class="bi bi-star-fill"></i> Favoris         <span class="tab-count"><?= count($favorites) ?></span></button>
      <button class="tab-btn"        onclick="switchTab('collections')" data-tab="collections"><i class="bi bi-collection"></i> Collections   <span class="tab-count"><?= count($collections) ?></span></button>
      <button class="tab-btn"        onclick="switchTab('downloads')"   data-tab="downloads"> <i class="bi bi-cloud-arrow-down"></i> DL</button>
      <button class="tab-btn"        onclick="switchTab('recommend')"   data-tab="recommend"> <i class="bi bi-stars"></i> Pour vous</button>
      <button class="tab-btn"        onclick="switchTab('stats')"       data-tab="stats">     <i class="bi bi-bar-chart-line"></i> Stats</button>
      <button class="tab-btn"        onclick="switchTab('bonus')"       data-tab="bonus">
        <i class="bi bi-gift"></i> Bonus
        <?php if ((int)($bonus['bonus_restant'] ?? 0) > 0): ?><span class="tab-count" style="background:rgba(245,158,11,.2);color:var(--amber)"><?= (int)$bonus['bonus_restant'] ?></span><?php endif; ?>
      </button>
    </div>

    <!-- ══════ PANEL : TOUS ══════ -->
    <div class="tab-panel active" id="panel-all">
      <?php if (empty($purchased)): ?>
      <div class="empty-state">
        <div class="empty-icon">📚</div>
        <div class="empty-title">Votre bibliothèque est vide</div>
        <div class="empty-sub">Achetez ou accédez à des livres gratuits du catalogue.</div>
        <a href="../books/index.php" class="btn btn-primary">Explorer le catalogue</a>
      </div>
      <?php else: ?>
      <!-- Filtres & Tri -->
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:1.2rem;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm filter-btn active" onclick="filterByStatus('all',this)"           type="button">Tous</button>
        <button class="btn btn-ghost btn-sm filter-btn"        onclick="filterByStatus('en_cours',this)"       type="button">En cours</button>
        <button class="btn btn-ghost btn-sm filter-btn"        onclick="filterByStatus('termine',this)"        type="button">Terminés</button>
        <button class="btn btn-ghost btn-sm filter-btn"        onclick="filterByStatus('non_commence',this)"   type="button">Non commencés</button>
        <div style="margin-left:auto">
          <select id="sort-select" class="form-input" style="width:160px;margin:0;font-size:.75rem;padding:5px 10px" onchange="sortBooks(this.value)">
            <option value="recent">Plus récents</option>
            <option value="title">Titre A–Z</option>
            <option value="progress">Progression</option>
          </select>
        </div>
      </div>
      <div class="books-grid" id="books-grid-all">
        <?php foreach ($purchased as $i => $book):
          $pct     = (float)($book['pourcentage'] ?? 0);
          $done    = ($book['lect_statut'] ?? '') === 'termine';
          $started = $pct > 0;
          $isFav   = !empty($book['is_favorite']);
          $emoji   = $bookEmojis[$i % count($bookEmojis)];
          $catName = safeEcho($book['categorie_nom'] ?? 'Général');
          $priceF  = fmtFCFA((float)($book['prix_paye'] ?? $book['prix'] ?? 0));
          $pdfPath = !empty($book['fichier_pdf']) ? '../' . safeEcho($book['fichier_pdf']) : '';
        ?>
        <div class="book-card"
             data-status="<?= $done ? 'termine' : ($started ? 'en_cours' : 'non_commence') ?>"
             data-title="<?= strtolower(safeEcho($book['titre'] ?? '')) ?>"
             data-author="<?= strtolower(safeEcho($book['auteur'] ?? '')) ?>"
             data-pct="<?= $pct ?>">
          <div class="book-cover">
            <?php if (!empty($book['is_bestseller'])): ?><span class="cover-badge badge-best">Bestseller</span>
            <?php elseif ($done):                        ?><span class="cover-badge badge-done">✓ Lu</span>
            <?php elseif (!empty($book['is_featured'])): ?><span class="cover-badge badge-premium">★ Premium</span>
            <?php endif; ?>
            <span style="z-index:1"><?= $emoji ?></span>
          </div>
          <div class="book-body">
            <div class="book-cat"><?= $catName ?></div>
            <div class="book-title"><?= safeEcho($book['titre'] ?? '') ?></div>
            <div class="book-author"><?= safeEcho($book['auteur'] ?? '') ?></div>
          </div>
          <?php if ($started): ?>
          <div class="book-prog">
            <div class="prog-row">
              <div class="prog-bar"><div class="prog-fill <?= $done ? 'green' : '' ?>" style="width:<?= $pct ?>%"></div></div>
              <span class="prog-pct"><?= $done ? '✓' : round($pct) . '%' ?></span>
            </div>
          </div>
          <?php endif; ?>
          <div class="book-footer">
            <span style="font-size:.65rem;color:var(--text-muted);font-family:'Space Mono',monospace"><?= $priceF ?></span>
            <div class="book-actions">
              <button class="ic-btn <?= $isFav ? 'fav-active' : '' ?> fav-btn"
                      data-livre-id="<?= (int)$book['id'] ?>"
                      onclick="toggleFav(this,<?= (int)$book['id'] ?>)" type="button" title="Favori">
                <i class="bi bi-<?= $isFav ? 'star-fill' : 'star' ?>"></i>
              </button>
              <?php if ($pdfPath): ?>
              <a class="ic-btn" href="#" onclick="downloadBook(<?= (int)$book['id'] ?>,<?= json_encode($pdfPath, JSON_HEX_TAG) ?>);return false;" title="Télécharger">
                <i class="bi bi-cloud-download"></i>
              </a>
              <button class="ic-btn" style="background:linear-gradient(135deg,rgba(0,212,255,.15),rgba(124,58,237,.15));color:var(--cyan)"
                      onclick="openPdfReader(<?= json_encode($pdfPath, JSON_HEX_TAG) ?>,<?= (int)$book['id'] ?>,<?= (int)($book['page_actuelle'] ?? 1) ?>,<?= json_encode($book['titre'] ?? '', JSON_HEX_TAG) ?>)"
                      type="button" title="Lire">
                <i class="bi bi-book-fill"></i>
              </button>
              <?php else: ?>
              <a class="ic-btn" style="color:var(--cyan)" href="../books/read.php?id=<?= (int)$book['id'] ?>" title="Lire">
                <i class="bi bi-book-fill"></i>
              </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════ PANEL : EN COURS ══════ -->
    <div class="tab-panel" id="panel-reading">
      <?php if (empty($inProgress)): ?>
      <div class="empty-state">
        <div class="empty-icon">⏳</div>
        <div class="empty-title">Aucune lecture en cours</div>
        <div class="empty-sub">Commencez à lire un livre pour suivre votre progression.</div>
        <button class="btn btn-primary" onclick="switchTab('all')" type="button">Voir mes livres</button>
      </div>
      <?php else: ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title"><div class="c-icon" style="background:rgba(0,212,255,.1)">⏳</div>Lectures en cours</div>
          <span style="font-size:.75rem;color:var(--text-muted)"><?= count($inProgress) ?> livre<?= count($inProgress) > 1 ? 's' : '' ?></span>
        </div>
        <div class="card-body">
          <?php foreach ($inProgress as $i => $book):
            $pct     = (float)($book['pourcentage'] ?? 0);
            $emoji   = $bookEmojis[$i % count($bookEmojis)];
            $pages   = (int)($book['page_actuelle'] ?? 1);
            $total   = (int)($book['pages'] ?? 0);
            $pdfPath = !empty($book['fichier_pdf']) ? '../' . safeEcho($book['fichier_pdf']) : '';
            $circ    = 2 * 3.14159 * 22;
            $offset  = $circ - ($pct / 100) * $circ;
          ?>
          <div class="lib-item">
            <div class="ring-wrap">
              <svg class="ring-svg" width="52" height="52" viewBox="0 0 52 52">
                <circle class="ring-track" cx="26" cy="26" r="22"/>
                <circle class="ring-fill" cx="26" cy="26" r="22"
                        stroke-dasharray="<?= round($circ, 2) ?>"
                        stroke-dashoffset="<?= round($offset, 2) ?>"/>
              </svg>
              <div class="ring-label"><?= round($pct) ?>%</div>
            </div>
            <div class="li-info">
              <div class="li-title"><?= safeEcho($book['titre'] ?? '') ?></div>
              <div class="li-meta">
                <?= safeEcho($book['auteur'] ?? '') ?>
                <?php if (!empty($book['derniere_lecture'])): ?> · <?= timeAgoLib($book['derniere_lecture']) ?><?php endif; ?>
                <?php if ($total > 0): ?> · Page <?= $pages ?>/<?= $total ?><?php endif; ?>
              </div>
              <div class="li-prog">
                <div class="prog-bar" style="flex:1"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
                <span class="prog-pct"><?= round($pct) ?>%</span>
              </div>
            </div>
            <?php if ($pdfPath): ?>
            <button class="btn btn-primary btn-sm"
                    onclick="openPdfReader(<?= json_encode($pdfPath, JSON_HEX_TAG) ?>,<?= (int)$book['id'] ?>,<?= $pages ?>,<?= json_encode($book['titre'] ?? '', JSON_HEX_TAG) ?>)"
                    type="button">
              <i class="bi bi-play-fill"></i> Reprendre
            </button>
            <?php else: ?>
            <a class="btn btn-primary btn-sm" href="../books/read.php?id=<?= (int)$book['id'] ?>">
              <i class="bi bi-play-fill"></i> Reprendre
            </a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════ PANEL : TERMINÉS ══════ -->
    <div class="tab-panel" id="panel-finished">
      <?php if (empty($finished)): ?>
      <div class="empty-state">
        <div class="empty-icon">🏆</div>
        <div class="empty-title">Aucun livre terminé</div>
        <div class="empty-sub">Terminez votre première lecture !</div>
      </div>
      <?php else: ?>
      <div class="books-grid">
        <?php foreach ($finished as $i => $book):
          $emoji   = $bookEmojis[$i % count($bookEmojis)];
          $myNote  = (int)($book['ma_note'] ?? 0);
          $pdfPath = !empty($book['fichier_pdf']) ? '../' . safeEcho($book['fichier_pdf']) : '';
        ?>
        <div class="book-card">
          <div class="book-cover">
            <span class="cover-badge badge-done">✓ Lu</span>
            <span style="z-index:1"><?= $emoji ?></span>
          </div>
          <div class="book-body">
            <div class="book-cat"><?= safeEcho($book['categorie_nom'] ?? 'Général') ?></div>
            <div class="book-title"><?= safeEcho($book['titre'] ?? '') ?></div>
            <div class="book-author"><?= safeEcho($book['auteur'] ?? '') ?></div>
            <div class="stars" style="margin-top:6px">
              <?php for ($s = 1; $s <= 5; $s++): ?>
              <span class="star <?= $s <= $myNote ? 'filled' : '' ?>"
                    onclick="quickRate(<?= (int)$book['id'] ?>,<?= $s ?>,this.parentElement)">★</span>
              <?php endfor; ?>
            </div>
          </div>
          <div class="book-footer">
            <span class="chip chip-neon">✓ Terminé</span>
            <div class="book-actions">
              <button class="ic-btn <?= !empty($book['is_favorite']) ? 'fav-active' : '' ?> fav-btn"
                      data-livre-id="<?= (int)$book['id'] ?>"
                      onclick="toggleFav(this,<?= (int)$book['id'] ?>)" type="button">
                <i class="bi bi-<?= !empty($book['is_favorite']) ? 'star-fill' : 'star' ?>"></i>
              </button>
              <?php if ($pdfPath): ?>
              <button class="ic-btn" onclick="openPdfReader(<?= json_encode($pdfPath, JSON_HEX_TAG) ?>,<?= (int)$book['id'] ?>,1,<?= json_encode($book['titre'] ?? '', JSON_HEX_TAG) ?>)" type="button" title="Relire">
                <i class="bi bi-arrow-counterclockwise"></i>
              </button>
              <?php else: ?>
              <a class="ic-btn" href="../books/read.php?id=<?= (int)$book['id'] ?>"><i class="bi bi-arrow-counterclockwise"></i></a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════ PANEL : FAVORIS ══════ -->
    <div class="tab-panel" id="panel-favorites">
      <?php if (empty($favorites)): ?>
      <div class="empty-state">
        <div class="empty-icon">⭐</div>
        <div class="empty-title">Aucun favori</div>
        <div class="empty-sub">Cliquez sur l'étoile ⭐ pour ajouter des favoris.</div>
      </div>
      <?php else: ?>
      <div class="books-grid">
        <?php foreach ($favorites as $i => $book):
          $pct     = (float)($book['pourcentage'] ?? 0);
          $emoji   = $bookEmojis[$i % count($bookEmojis)];
          $pdfPath = !empty($book['fichier_pdf']) ? '../' . safeEcho($book['fichier_pdf']) : '';
        ?>
        <div class="book-card">
          <div class="book-cover">
            <span class="cover-badge badge-best">⭐ Favori</span>
            <span style="z-index:1"><?= $emoji ?></span>
          </div>
          <div class="book-body">
            <div class="book-cat"><?= safeEcho($book['categorie_nom'] ?? 'Général') ?></div>
            <div class="book-title"><?= safeEcho($book['titre'] ?? '') ?></div>
            <div class="book-author"><?= safeEcho($book['auteur'] ?? '') ?></div>
          </div>
          <?php if ($pct > 0): ?>
          <div class="book-prog">
            <div class="prog-row">
              <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
              <span class="prog-pct"><?= round($pct) ?>%</span>
            </div>
          </div>
          <?php endif; ?>
          <div class="book-footer">
            <span class="chip chip-amber">⭐ Favori</span>
            <div class="book-actions">
              <button class="ic-btn fav-active fav-btn" data-livre-id="<?= (int)$book['id'] ?>" onclick="toggleFav(this,<?= (int)$book['id'] ?>)" type="button">
                <i class="bi bi-star-fill"></i>
              </button>
              <?php if ($pdfPath): ?>
              <button class="ic-btn" style="color:var(--cyan)"
                      onclick="openPdfReader(<?= json_encode($pdfPath, JSON_HEX_TAG) ?>,<?= (int)$book['id'] ?>,<?= (int)($book['page_actuelle'] ?? 1) ?>,<?= json_encode($book['titre'] ?? '', JSON_HEX_TAG) ?>)"
                      type="button"><i class="bi bi-book-fill"></i></button>
              <?php else: ?>
              <a class="ic-btn" style="color:var(--cyan)" href="../books/read.php?id=<?= (int)$book['id'] ?>"><i class="bi bi-book-fill"></i></a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════ PANEL : COLLECTIONS ══════ -->
    <div class="tab-panel" id="panel-collections">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem">
        <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700">Mes Collections</h2>
        <button class="btn btn-primary btn-sm" onclick="openModal('collection-modal')" type="button">
          <i class="bi bi-plus-circle"></i> Nouvelle collection
        </button>
      </div>
      <?php if (empty($collections)): ?>
      <div class="empty-state">
        <div class="empty-icon">📂</div>
        <div class="empty-title">Aucune collection</div>
        <div class="empty-sub">Organisez vos livres en collections thématiques.</div>
        <button class="btn btn-primary" onclick="openModal('collection-modal')" type="button">Créer une collection</button>
      </div>
      <?php else: ?>
      <div class="col-grid">
        <?php foreach ($collections as $col): ?>
        <div class="col-card">
          <div class="col-header">
            <div class="col-color-bar" style="background:<?= safeEcho($col['couleur'] ?? '#4a9eff') ?>"></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:4px">
              <div class="col-name"><?= safeEcho($col['nom'] ?? '') ?></div>
              <span class="chip chip-muted"><?= (int)($col['nb_livres'] ?? 0) ?> livre<?= (int)($col['nb_livres'] ?? 0) > 1 ? 's' : '' ?></span>
            </div>
            <?php if (!empty($col['description'])): ?><div class="col-desc"><?= safeEcho($col['description']) ?></div><?php endif; ?>
          </div>
          <div class="col-body">
            <div class="col-books-preview">
              <?php if (!empty($col['books'])): ?>
                <?php foreach (array_slice($col['books'], 0, 4) as $bk): ?>
                <span class="col-book-chip"><?= safeEcho($bk['titre'] ?? '') ?></span>
                <?php endforeach; ?>
                <?php if (count($col['books']) > 4): ?><span class="col-book-chip">+<?= count($col['books']) - 4 ?></span><?php endif; ?>
              <?php else: ?>
              <span style="font-size:.72rem;color:var(--text-muted)">Aucun livre · Ajoutez-en ci-dessous</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-footer">
            <span style="font-size:.65rem;color:var(--text-muted);font-family:'Space Mono',monospace">
              <?= date('d/m/Y', strtotime($col['created_at'] ?? 'now')) ?>
            </span>
            <div style="display:flex;gap:4px">
              <button class="ic-btn" onclick="addBookToCollection(<?= (int)$col['id'] ?>)" type="button" title="Ajouter un livre"><i class="bi bi-plus"></i></button>
              <button class="ic-btn" onclick="deleteCollection(<?= (int)$col['id'] ?>)" type="button" title="Supprimer">
                <i class="bi bi-trash3" style="color:var(--rose)"></i>
              </button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════ PANEL : TÉLÉCHARGEMENTS ══════ -->
    <div class="tab-panel" id="panel-downloads">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><div class="c-icon" style="background:rgba(0,212,255,.1)">⬇️</div>Historique des téléchargements</div>
          <span style="font-size:.75rem;color:var(--text-muted)"><?= count($downloads) ?> enregistrement<?= count($downloads) > 1 ? 's' : '' ?></span>
        </div>
        <div class="card-body">
          <?php if (empty($downloads)): ?>
          <div class="empty-state" style="padding:1.5rem">
            <div class="empty-icon" style="font-size:2.5rem">📥</div>
            <div class="empty-title">Aucun téléchargement</div>
            <div class="empty-sub">Téléchargez des PDFs depuis vos livres achetés.</div>
          </div>
          <?php else: foreach ($downloads as $i => $dl): ?>
          <div class="lib-item">
            <div class="li-emoji"><?= $bookEmojis[$i % count($bookEmojis)] ?></div>
            <div class="li-info">
              <div class="li-title"><?= safeEcho($dl['titre'] ?? '') ?></div>
              <div class="li-meta"><?= safeEcho($dl['auteur'] ?? '') ?> · <?= (int)$dl['nb_dl'] ?> DL · Dernier : <?= timeAgoLib($dl['last_dl_at'] ?? '') ?></div>
            </div>
            <span class="chip chip-cyan"><?= (int)$dl['nb_dl'] ?>×</span>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════ PANEL : RECOMMANDATIONS ══════ -->
    <div class="tab-panel" id="panel-recommend">
      <?php if (!empty($topAuthors)): ?>
      <div style="margin-bottom:1.2rem;font-size:.8rem;color:var(--text-secondary)">
        Basé sur vos auteurs favoris :
        <?php foreach ($topAuthors as $ta): ?><span class="chip chip-cyan" style="margin-left:4px"><?= safeEcho($ta['auteur']) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if (empty($recs)): ?>
      <div class="empty-state">
        <div class="empty-icon">🤖</div>
        <div class="empty-title">Pas encore de recommandations</div>
        <div class="empty-sub">Lisez quelques livres pour obtenir des recommandations personnalisées.</div>
        <a href="../books/index.php" class="btn btn-primary">Explorer le catalogue</a>
      </div>
      <?php else: ?>
      <div class="books-grid">
        <?php foreach ($recs as $i => $rec):
          $emoji  = $bookEmojis[($i + 7) % count($bookEmojis)];
          $match  = min(99, 97 - $i * 4);
          $isFree = (float)($rec['prix'] ?? 0) == 0 || ($rec['access_type'] ?? '') === 'gratuit';
        ?>
        <div class="book-card" style="animation-delay:<?= $i * .05 ?>s">
          <div class="book-cover">
            <?php if (($rec['access_type'] ?? '') === 'premium'): ?><span class="cover-badge badge-premium">Premium</span>
            <?php elseif ($isFree):                                 ?><span class="cover-badge badge-free">Gratuit</span>
            <?php endif; ?>
            <span style="z-index:1"><?= $emoji ?></span>
          </div>
          <div class="book-body">
            <div class="book-cat"><?= safeEcho($rec['categorie_nom'] ?? 'Général') ?></div>
            <div class="book-title"><?= safeEcho($rec['titre'] ?? '') ?></div>
            <div class="book-author"><?= safeEcho($rec['auteur'] ?? '') ?></div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:6px">
              <div class="prog-bar" style="flex:1;height:3px"><div class="prog-fill" style="width:<?= $match ?>%"></div></div>
              <span style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--cyan)"><?= $match ?>%</span>
            </div>
            <div style="font-size:.62rem;color:var(--text-muted);margin-top:3px">Compatibilité</div>
          </div>
          <div class="book-footer">
            <span class="chip <?= $isFree ? 'chip-neon' : 'chip-muted' ?>">
              <?= $isFree ? 'Gratuit' : fmtFCFA((float)$rec['prix']) ?>
            </span>
            <a class="btn btn-primary btn-xs" href="../books/view.php?id=<?= (int)$rec['id'] ?>">
              <i class="bi bi-eye"></i> Voir
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════ PANEL : STATISTIQUES ══════ -->
    <div class="tab-panel" id="panel-stats">
      <div class="stats-grid">
        <div class="sc"><div class="sc-icon" style="background:rgba(0,255,170,.08)">📚</div><div class="sc-val"><?= (int)($stats['livres_achetes'] ?? 0) ?></div><div class="sc-lbl">Livres achetés</div></div>
        <div class="sc"><div class="sc-icon" style="background:rgba(0,212,255,.08)">💰</div><div class="sc-val" style="font-size:1rem"><?= fmtFCFA((float)($stats['total_depense'] ?? 0)) ?></div><div class="sc-lbl">Total investi</div></div>
        <div class="sc"><div class="sc-icon" style="background:rgba(245,158,11,.08)">⏱️</div><div class="sc-val"><?= (int)($stats['temps_lecture_total'] ?? 0) ?>m</div><div class="sc-lbl">Minutes de lecture</div></div>
        <div class="sc"><div class="sc-icon" style="background:rgba(124,58,237,.08)">✅</div><div class="sc-val"><?= (int)($stats['livres_termines'] ?? 0) ?></div><div class="sc-lbl">Terminés</div></div>
        <div class="sc"><div class="sc-icon" style="background:rgba(244,63,94,.08)">⭐</div><div class="sc-val"><?= count($favorites) ?></div><div class="sc-lbl">Favoris</div></div>
        <div class="sc"><div class="sc-icon" style="background:rgba(0,255,170,.06)">📥</div><div class="sc-val"><?= (int)($stats['livres_telecharges'] ?? 0) ?></div><div class="sc-lbl">Téléchargés</div></div>
      </div>

      <!-- Activité 7 jours -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><div class="c-icon" style="background:rgba(0,212,255,.1)">📈</div>Activité — 7 derniers jours</div>
        </div>
        <div class="card-body">
          <?php
          $maxMin   = max(1, ...array_map(fn($d) => (int)$d['minutes'], $actChart) ?: [1]);
          $totalMin = array_sum(array_column($actChart, 'minutes'));
          ?>
          <div class="chart-bars">
            <?php foreach ($actChart as $day):
              $h = max(4, round((int)($day['minutes'] ?? 0) / $maxMin * 80));
            ?>
            <div class="cb-wrap">
              <div class="cb" style="height:<?= $h ?>px">
                <span class="cb-tt"><?= (int)$day['minutes'] ?>min</span>
              </div>
              <div class="cb-lbl"><?= safeEcho($day['label']) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;justify-content:space-between;margin-top:.8rem;font-size:.72rem;color:var(--text-muted)">
            <span>Total : <strong style="color:var(--neon)"><?= $totalMin ?>min</strong></span>
            <span>Moy./jour : <strong style="color:var(--cyan)"><?= round($totalMin / 7, 1) ?>min</strong></span>
          </div>
        </div>
      </div>

      <?php if (!empty($topAuthors)): ?>
      <div class="card">
        <div class="card-header"><div class="card-title"><div class="c-icon" style="background:rgba(245,158,11,.1)">✍️</div>Auteurs les plus lus</div></div>
        <div class="card-body">
          <?php foreach ($topAuthors as $ta): ?>
          <div class="lib-item">
            <div class="li-emoji" style="font-size:1.4rem">✍️</div>
            <div class="li-info">
              <div class="li-title"><?= safeEcho($ta['auteur']) ?></div>
              <div class="li-meta"><?= (int)$ta['nb'] ?> livre<?= (int)$ta['nb'] > 1 ? 's' : '' ?></div>
            </div>
            <div class="prog-bar" style="width:80px"><div class="prog-fill" style="width:<?= min(100, round((int)$ta['nb'] / max(1, count($purchased)) * 300)) ?>%"></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════ PANEL : BONUS ══════ -->
    <div class="tab-panel" id="panel-bonus">
      <?php
      $bonusRule  = 5;
      $achatCount = (int)($bonus['achat_count']  ?? 0);
      $bonusTotal = (int)($bonus['bonus_total']   ?? 0);
      $bonusLeft  = (int)($bonus['bonus_restant'] ?? 0);
      $progPct    = $bonusRule > 0 ? min(100, round($achatCount / $bonusRule * 100)) : 0;
      $remaining  = max(0, $bonusRule - $achatCount);
      ?>
      <div class="bonus-card" style="margin-bottom:1.5rem">
        <div class="bonus-glow"></div>
        <div class="bonus-title">🎁 Programme Fidélité</div>
        <div style="font-size:.82rem;color:var(--text-secondary)">Achetez des livres et gagnez des récompenses !</div>
        <div class="bonus-prog-wrap"><div class="bonus-prog" style="width:<?= $progPct ?>%"></div></div>
        <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--text-muted);font-family:'Space Mono',monospace">
          <span><?= $achatCount ?>/<?= $bonusRule ?> achats</span>
          <span><?= $progPct ?>% vers prochain bonus</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-top:1.2rem">
          <div style="text-align:center;background:rgba(0,0,0,.2);border-radius:var(--r-md);padding:1rem">
            <div style="font-size:1.6rem;font-family:'Syne',sans-serif;font-weight:800;color:var(--gold)"><?= $bonusLeft ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)">Disponibles</div>
          </div>
          <div style="text-align:center;background:rgba(0,0,0,.2);border-radius:var(--r-md);padding:1rem">
            <div style="font-size:1.6rem;font-family:'Syne',sans-serif;font-weight:800;color:var(--violet)"><?= $bonusTotal ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)">Total gagnés</div>
          </div>
          <div style="text-align:center;background:rgba(0,0,0,.2);border-radius:var(--r-md);padding:1rem">
            <div style="font-size:1.6rem;font-family:'Syne',sans-serif;font-weight:800;color:var(--cyan)"><?= $remaining ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)">Achats restants</div>
          </div>
        </div>
        <?php if ($bonusLeft > 0): ?>
        <div style="margin-top:1.2rem;padding:1rem;background:rgba(0,255,170,.05);border:1px solid rgba(0,255,170,.15);border-radius:var(--r-md)">
          <div style="font-family:'Syne',sans-serif;font-weight:700;color:var(--neon);margin-bottom:6px">🎉 <?= $bonusLeft ?> livre<?= $bonusLeft > 1 ? 's' : '' ?> gratuit<?= $bonusLeft > 1 ? 's' : '' ?> disponible<?= $bonusLeft > 1 ? 's' : '' ?> !</div>
          <a href="../books/index.php?bonus=1" class="btn btn-neon btn-sm"><i class="bi bi-gift-fill"></i> Utiliser mes bonus</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </main>
</div><!-- /main -->
</div><!-- /app -->

<!-- ═══════════ NOTIFICATIONS PANEL ═══════════ -->
<div id="notif-panel" role="dialog" aria-label="Notifications">
  <div class="np-hd">
    <span>Notifications</span>
    <?php if ($notifCount > 0): ?><span class="chip chip-rose"><?= $notifCount ?></span><?php endif; ?>
  </div>
  <div class="np-list">
    <?php if (empty($library['notifications'])): ?>
    <div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:.8rem">
      <div style="font-size:1.8rem;margin-bottom:.4rem">🔔</div>Aucune notification
    </div>
    <?php else:
      foreach ($library['notifications'] as $n):
        $unread = !((bool)($n['_lu_unified'] ?? $n['lu'] ?? $n['is_read'] ?? false));
        $icon   = safeEcho($n['icon'] ?? '🔔');
        $bg     = safeEcho($n['bg']   ?? 'rgba(0,212,255,.08)');
        $title  = safeEcho($n['_titre_unified'] ?? $n['titre'] ?? $n['title'] ?? '');
        $msg    = safeEcho(mb_substr($n['message'] ?? '', 0, 80));
    ?>
    <div class="np-item <?= $unread ? 'np-unread' : '' ?>" onclick="markNotifRead(<?= (int)$n['id'] ?>,this)">
      <div class="np-icon" style="background:<?= $bg ?>"><?= $icon ?></div>
      <div>
        <?php if ($title): ?><div class="np-txt" style="font-weight:600;color:var(--text-primary)"><?= $title ?></div><?php endif; ?>
        <div class="np-txt"><?= $msg ?><?= mb_strlen($n['message'] ?? '') > 80 ? '…' : '' ?></div>
        <div class="np-time"><?= timeAgoLib($n['created_at'] ?? '') ?></div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <div style="padding:.8rem 1.1rem;border-top:1px solid var(--border);display:flex;gap:6px">
    <a href="../admin/notifications.php" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center">Tout voir</a>
  </div>
</div>

<!-- ═══════════ MODAL : COLLECTION ═══════════ -->
<div class="modal-overlay" id="collection-modal" onclick="if(event.target===this)closeModal('collection-modal')">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('collection-modal')" type="button"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">📂 Nouvelle Collection</div>
    <label class="form-label" for="col-name">Nom *</label>
    <input type="text" class="form-input" id="col-name" placeholder="Ex: Sci-Fi préférée…" maxlength="100">
    <label class="form-label" for="col-desc">Description</label>
    <textarea class="form-input form-textarea" id="col-desc" placeholder="Décrivez votre collection…" maxlength="300"></textarea>
    <label class="form-label">Couleur</label>
    <div class="color-picker-row">
      <?php foreach (['#4a9eff','#7c3aed','#00d4ff','#00ffaa','#f59e0b','#f43f5e','#f97316','#06b6d4','#8b5cf6','#10b981'] as $ci => $color): ?>
      <div class="color-dot <?= $ci === 0 ? 'selected' : '' ?>" style="background:<?= $color ?>" data-color="<?= $color ?>" onclick="selectColor(this)" title="<?= $color ?>"></div>
      <?php endforeach; ?>
    </div>
    <input type="hidden" id="col-color" value="#4a9eff">
    <button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:.5rem" onclick="createCollection()" type="button">
      <i class="bi bi-plus-circle"></i> Créer
    </button>
  </div>
</div>

<!-- ═══════════ MODAL : NOTER ═══════════ -->
<div class="modal-overlay" id="review-modal" onclick="if(event.target===this)closeModal('review-modal')">
  <div class="modal-box" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('review-modal')" type="button"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">⭐ Évaluer ce livre</div>
    <div id="review-book-name" style="font-size:.82rem;color:var(--text-secondary);margin-bottom:1.2rem"></div>
    <input type="hidden" id="review-livre-id" value="">
    <label class="form-label">Votre note</label>
    <div class="stars" id="review-stars" style="font-size:1.8rem;gap:4px;margin-bottom:1rem">
      <?php for ($s = 1; $s <= 5; $s++): ?>
      <span class="star" onclick="setReviewStar(<?= $s ?>)">★</span>
      <?php endfor; ?>
    </div>
    <input type="hidden" id="review-note" value="0">
    <label class="form-label" for="review-comment">Commentaire</label>
    <textarea class="form-input form-textarea" id="review-comment" placeholder="Votre avis…" maxlength="500"></textarea>
    <button class="btn btn-primary" style="width:100%;justify-content:center" onclick="submitReview()" type="button">
      <i class="bi bi-send"></i> Envoyer
    </button>
  </div>
</div>

<!-- ═══════════ MODAL : PDF READER ═══════════ -->
<div class="modal-overlay" id="pdf-modal" onclick="if(event.target===this)closePdfReader()" style="align-items:stretch;padding:.5rem">
  <div class="modal-box" style="max-width:900px;width:100%;max-height:95vh;padding:0;border-radius:var(--r-xl);overflow:hidden;display:flex;flex-direction:column">
    <div id="pdf-container">
      <div id="pdf-toolbar">
        <button class="btn btn-ghost btn-sm" onclick="closePdfReader()" type="button"><i class="bi bi-x-lg"></i></button>
        <div id="pdf-title" style="font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem;flex:1"></div>
        <button class="btn btn-ghost btn-sm" onclick="pdfPrevPage()" id="btn-prev" type="button"><i class="bi bi-chevron-left"></i></button>
        <div id="pdf-page-info">— / —</div>
        <button class="btn btn-ghost btn-sm" onclick="pdfNextPage()" id="btn-next" type="button"><i class="bi bi-chevron-right"></i></button>
        <button class="btn btn-ghost btn-sm" onclick="pdfZoom(-1)" type="button"><i class="bi bi-zoom-out"></i></button>
        <span id="pdf-zoom-lbl" style="font-family:'Space Mono',monospace;font-size:.7rem;color:var(--text-muted);min-width:40px;text-align:center">100%</span>
        <button class="btn btn-ghost btn-sm" onclick="pdfZoom(1)" type="button"><i class="bi bi-zoom-in"></i></button>
        <button class="btn btn-ghost btn-sm" onclick="togglePdfFullscreen()" type="button"><i class="bi bi-fullscreen"></i></button>
      </div>
      <div id="pdf-progress-bar" style="width:0%;height:3px"></div>
      <div id="pdf-canvas-wrap"><canvas id="pdf-canvas"></canvas></div>
      <div id="pdf-loading"><div class="pdf-spinner"></div><span>Chargement…</span></div>
    </div>
  </div>
</div>

<!-- ═══════════ MODAL : AJOUTER LIVRE → COLLECTION ═══════════ -->
<div class="modal-overlay" id="add-to-col-modal" onclick="if(event.target===this)closeModal('add-to-col-modal')">
  <div class="modal-box" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('add-to-col-modal')" type="button"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">📚 Ajouter un livre</div>
    <input type="hidden" id="atc-col-id" value="">
    <label class="form-label">Sélectionner un livre</label>
    <select class="form-input" id="atc-livre-select">
      <option value="">— Choisir —</option>
      <?php foreach ($purchased as $b): ?>
      <option value="<?= (int)$b['id'] ?>"><?= safeEcho($b['titre'] ?? '') ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" style="width:100%;justify-content:center" onclick="submitAddToCollection()" type="button">
      <i class="bi bi-plus"></i> Ajouter
    </button>
  </div>
</div>

<!-- ═══════════ TOAST STACK ═══════════ -->
<div id="toast-stack" role="region" aria-live="assertive"></div>

<!-- ═══════════ SCRIPTS ═══════════ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
/* ──────────────────────────────────────────────
   GLOBALS
────────────────────────────────────────────── */
const CSRF    = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const API_URL = (function(){
  const u = new URL(window.location.href);
  u.search = '';
  return u.toString();
})();

/* ──────────────────────────────────────────────
   AJAX — CORRIGÉ : vérifie Content-Type avant parse JSON
────────────────────────────────────────────── */
async function ajaxPost(action, data = {}) {
  data.csrf = CSRF;
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));

  const res = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
    method:  'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body:    fd,
  });

  if (!res.ok) throw new Error('HTTP ' + res.status);

  // Vérifier Content-Type avant de parser
  const ct = res.headers.get('content-type') || '';
  if (!ct.includes('application/json')) {
    const txt = await res.text();
    console.error('[DLS AJAX] Réponse non-JSON:', txt.substring(0, 300));
    throw new Error('Réponse non-JSON du serveur. Vérifiez les logs PHP.');
  }

  return res.json();
}

/* ──────────────────────────────────────────────
   TOAST
────────────────────────────────────────────── */
const TOAST_ICONS  = { info:'ℹ️', success:'✅', warn:'⚠️', error:'❌' };
const TOAST_COLORS = { info:'var(--cyan)', success:'var(--neon)', warn:'var(--amber)', error:'var(--rose)' };

function showToast(title, sub = '', type = 'info', dur = 3500) {
  const stack = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = TOAST_COLORS[type] || TOAST_COLORS.info;
  t.innerHTML = `
    <span>${TOAST_ICONS[type] || 'ℹ️'}</span>
    <div style="flex:1">
      <div class="t-title">${esc(title)}</div>
      ${sub ? `<div class="t-sub">${esc(sub)}</div>` : ''}
    </div>
    <span style="cursor:pointer;color:var(--text-muted)" onclick="this.parentElement.remove()">✕</span>`;
  stack.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, dur);
}

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ──────────────────────────────────────────────
   SIDEBAR
────────────────────────────────────────────── */
function toggleMobileSidebar() {
  document.getElementById('sidebar').classList.toggle('mobile-open');
  document.getElementById('sidebar-overlay').classList.toggle('show');
}
function closeMobileSidebar() {
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('sidebar-overlay').classList.remove('show');
}
function highlightSidebarItem(el) {
  document.querySelectorAll('.sb-item').forEach(i => i.classList.remove('active'));
  el.classList.add('active');
}

/* ──────────────────────────────────────────────
   TABS
────────────────────────────────────────────── */
function switchTab(tab) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));

  const panel = document.getElementById('panel-' + tab);
  if (panel) {
    panel.classList.add('active');
    animateProgressBars(panel);
    animateProgressRings();
  }

  document.querySelectorAll(`[data-tab="${tab}"]`).forEach(b => b.classList.add('active'));
}

function animateProgressBars(container) {
  (container || document).querySelectorAll('.prog-fill').forEach(b => {
    const w = b.style.width;
    b.style.transition = 'none';
    b.style.width = '0%';
    requestAnimationFrame(() => requestAnimationFrame(() => {
      b.style.transition = '';
      b.style.width = w;
    }));
  });
}

function animateProgressRings() {
  document.querySelectorAll('.ring-fill').forEach(ring => {
    const da = parseFloat(ring.getAttribute('stroke-dasharray'));
    const target = parseFloat(ring.getAttribute('stroke-dashoffset'));
    ring.style.transition = 'none';
    ring.style.strokeDashoffset = da;
    requestAnimationFrame(() => requestAnimationFrame(() => {
      ring.style.transition = 'stroke-dashoffset 1.2s ease';
      ring.style.strokeDashoffset = target;
    }));
  });
}

/* ──────────────────────────────────────────────
   FILTER & SORT
────────────────────────────────────────────── */
function filterBooks(val) {
  const q = val.trim().toLowerCase();
  document.querySelectorAll('#books-grid-all .book-card').forEach(card => {
    const match = !q || (card.dataset.title || '').includes(q) || (card.dataset.author || '').includes(q);
    card.style.display = match ? '' : 'none';
  });
}

function filterByStatus(status, btn) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#books-grid-all .book-card').forEach(card => {
    card.style.display = (status === 'all' || card.dataset.status === status) ? '' : 'none';
  });
}

function sortBooks(by) {
  const grid = document.getElementById('books-grid-all');
  if (!grid) return;
  const cards = [...grid.children];
  cards.sort((a, b) => {
    if (by === 'title')    return (a.dataset.title || '').localeCompare(b.dataset.title || '');
    if (by === 'progress') return parseFloat(b.dataset.pct || 0) - parseFloat(a.dataset.pct || 0);
    return 0;
  });
  cards.forEach(c => grid.appendChild(c));
}



/* ──────────────────────────────────────────────
   NOTATION RAPIDE
────────────────────────────────────────────── */
async function quickRate(livreId, note, starsEl) {
  starsEl.querySelectorAll('.star').forEach((s, i) => s.classList.toggle('filled', i < note));
  try {
    await ajaxPost('save_review', { livre_id: livreId, note });
    showToast('Note enregistrée', `${note}/5 ⭐`, 'success', 2000);
  } catch(e) {
    showToast('Erreur', e.message, 'error');
  }
}

/* ──────────────────────────────────────────────
   REVIEW MODAL
────────────────────────────────────────────── */
let _reviewStar = 0;
function openReviewModal(livreId, titre, existingNote) {
  document.getElementById('review-livre-id').value = livreId;
  document.getElementById('review-book-name').textContent = titre;
  _reviewStar = parseInt(existingNote) || 0;
  document.getElementById('review-note').value = _reviewStar;
  updateReviewStars(_reviewStar);
  openModal('review-modal');
}
function setReviewStar(n) {
  _reviewStar = n;
  document.getElementById('review-note').value = n;
  updateReviewStars(n);
}
function updateReviewStars(n) {
  document.querySelectorAll('#review-stars .star').forEach((s, i) => s.classList.toggle('filled', i < n));
}
async function submitReview() {
  const livreId = document.getElementById('review-livre-id').value;
  const note    = parseInt(document.getElementById('review-note').value) || _reviewStar;
  const comment = document.getElementById('review-comment').value.trim();
  if (!note) { showToast('Erreur', 'Sélectionnez une note', 'warn'); return; }
  try {
    const data = await ajaxPost('save_review', { livre_id: livreId, note, commentaire: comment });
    if (data.success) { showToast('Avis enregistré ✅', `${note}/5`, 'success'); closeModal('review-modal'); }
    else showToast('Erreur', data.error || '', 'error');
  } catch(e) { showToast('Erreur', e.message, 'error'); }
}

/* ──────────────────────────────────────────────
   COLLECTIONS
────────────────────────────────────────────── */
function selectColor(el) {
  document.querySelectorAll('.color-dot').forEach(d => d.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('col-color').value = el.dataset.color;
}

async function createCollection() {
  const nom  = document.getElementById('col-name').value.trim();
  const desc = document.getElementById('col-desc').value.trim();
  const col  = document.getElementById('col-color').value;
  if (!nom) { showToast('Erreur', 'Le nom est obligatoire', 'warn'); return; }
  try {
    const data = await ajaxPost('create_collection', { nom, description: desc, couleur: col });
    if (data.success) {
      showToast('Collection créée ✅', nom, 'success');
      closeModal('collection-modal');
      setTimeout(() => location.reload(), 1200);
    } else showToast('Erreur', data.error || '', 'error');
  } catch(e) { showToast('Erreur', e.message, 'error'); }
}

async function deleteCollection(id) {
  if (!confirm('Supprimer cette collection ? Les livres ne seront pas supprimés.')) return;
  try {
    const data = await ajaxPost('delete_collection', { collection_id: id });
    if (data.success) {
      showToast('Collection supprimée', '', 'success');
      setTimeout(() => location.reload(), 900);
    } else showToast('Erreur', data.error || '', 'error');
  } catch(e) { showToast('Erreur', e.message, 'error'); }
}

function addBookToCollection(colId) {
  document.getElementById('atc-col-id').value = colId;
  openModal('add-to-col-modal');
}

async function submitAddToCollection() {
  const colId   = document.getElementById('atc-col-id').value;
  const livreId = document.getElementById('atc-livre-select').value;
  if (!livreId) { showToast('Erreur', 'Sélectionnez un livre', 'warn'); return; }
  try {
    const data = await ajaxPost('toggle_collection_book', { collection_id: colId, livre_id: livreId });
    if (data.success) {
      showToast(data.action === 'added' ? 'Livre ajouté ✅' : 'Livre retiré', '', 'success');
      closeModal('add-to-col-modal');
    } else showToast('Erreur', data.error || '', 'error');
  } catch(e) { showToast('Erreur', e.message, 'error'); }
}

/* ──────────────────────────────────────────────
   DOWNLOAD
────────────────────────────────────────────── */
async function downloadBook(livreId, pdfPath) {
  showToast('Téléchargement', 'Démarrage…', 'info', 3000);
  try {
    await fetch('../api/log_download.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ livre_id: livreId, csrf: CSRF }),
    });
  } catch(e) {}
  const a = document.createElement('a');
  a.href = pdfPath;
  a.download = '';
  a.click();
}

/* ──────────────────────────────────────────────
   NOTIFICATIONS
────────────────────────────────────────────── */
function toggleNotifPanel() {
  document.getElementById('notif-panel').classList.toggle('open');
}
document.addEventListener('click', e => {
  const p = document.getElementById('notif-panel');
  const b = document.getElementById('notif-btn');
  if (p?.classList.contains('open') && !p.contains(e.target) && !b?.contains(e.target)) {
    p.classList.remove('open');
  }
});

async function markNotifRead(id, el) {
  el.classList.remove('np-unread');
  const badge = document.querySelector('.n-badge');
  if (badge) {
    const n = parseInt(badge.textContent) - 1;
    if (n <= 0) badge.remove(); else badge.textContent = n;
  }
  try { await ajaxPost('mark_notif_read', { notif_id: id }); } catch(e) {}
}

/* ──────────────────────────────────────────────
   MODALS
────────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    if (pdfDoc) closePdfReader();
  }
});

/* ══════════════════════════════════════════════
   PDF.JS READER
══════════════════════════════════════════════ */
if (typeof pdfjsLib !== 'undefined') {
  pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

let pdfDoc       = null;
let pdfPage      = 1;
let pdfTotal     = 0;
let pdfScale     = 1.2;
let pdfLivreId   = null;
let pdfSaveTimer = null;
let pdfRendering = false;

function openPdfReader(path, livreId, startPage, titre) {
  pdfLivreId = livreId;
  pdfPage    = parseInt(startPage) || 1;
  document.getElementById('pdf-title').textContent = titre || 'Lecture';
  openModal('pdf-modal');
  loadPdf(path);
}

function closePdfReader() {
  if (pdfDoc && pdfLivreId) savePdfProgress();
  pdfDoc = null;
  pdfRendering = false;
  closeModal('pdf-modal');
}

async function loadPdf(url) {
  if (typeof pdfjsLib === 'undefined') {
    showToast('Erreur', 'PDF.js indisponible', 'error', 5000);
    closeModal('pdf-modal');
    return;
  }
  showPdfLoading(true);
  try {
    pdfDoc   = await pdfjsLib.getDocument({ url }).promise;
    pdfTotal = pdfDoc.numPages;
    await renderPdfPage(pdfPage);
  } catch(e) {
    showToast('Erreur PDF', e.message, 'error', 5000);
    showPdfLoading(false);
    closeModal('pdf-modal');
  }
}

async function renderPdfPage(num) {
  if (pdfRendering) return;
  pdfRendering = true;
  showPdfLoading(true);
  try {
    const page   = await pdfDoc.getPage(num);
    const vp     = page.getViewport({ scale: pdfScale });
    const canvas = document.getElementById('pdf-canvas');
    const ctx    = canvas.getContext('2d');
    canvas.width  = vp.width;
    canvas.height = vp.height;
    await page.render({ canvasContext: ctx, viewport: vp }).promise;
    pdfPage = num;

    document.getElementById('pdf-page-info').textContent = `${num} / ${pdfTotal}`;
    document.getElementById('btn-prev').disabled = num <= 1;
    document.getElementById('btn-next').disabled = num >= pdfTotal;
    document.getElementById('pdf-zoom-lbl').textContent = Math.round(pdfScale * 100) + '%';

    const pct = pdfTotal > 0 ? Math.round(num / pdfTotal * 100) : 0;
    document.getElementById('pdf-progress-bar').style.width = pct + '%';

    clearTimeout(pdfSaveTimer);
    pdfSaveTimer = setTimeout(savePdfProgress, 3000);
  } finally {
    pdfRendering = false;
    showPdfLoading(false);
  }
}

function pdfPrevPage() { if (pdfPage > 1 && !pdfRendering) renderPdfPage(pdfPage - 1); }
function pdfNextPage() { if (pdfPage < pdfTotal && !pdfRendering) renderPdfPage(pdfPage + 1); }
function pdfZoom(dir)  { pdfScale = Math.max(.5, Math.min(3, pdfScale + dir * .2)); if (pdfDoc) renderPdfPage(pdfPage); }

function showPdfLoading(show) {
  const el = document.getElementById('pdf-loading');
  const cv = document.getElementById('pdf-canvas-wrap');
  if (el) el.style.display = show ? 'flex' : 'none';
  if (cv) cv.style.opacity = show ? '.3' : '1';
}

function togglePdfFullscreen() {
  const el = document.getElementById('pdf-modal');
  if (!document.fullscreenElement) el?.requestFullscreen?.();
  else document.exitFullscreen?.();
}

async function savePdfProgress() {
  if (!pdfLivreId || !pdfDoc) return;
  try {
    await ajaxPost('save_progress', {
      livre_id:    pdfLivreId,
      page:        pdfPage,
      total_pages: pdfTotal,
    });
  } catch(e) {}
}

// Navigation clavier dans le PDF
document.addEventListener('keydown', e => {
  if (!pdfDoc || !document.getElementById('pdf-modal')?.classList.contains('open')) return;
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); pdfNextPage(); }
  if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   { e.preventDefault(); pdfPrevPage(); }
  if (e.key === '+') pdfZoom(1);
  if (e.key === '-') pdfZoom(-1);
});

/* ──────────────────────────────────────────────
   INIT
────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  // Animer les barres de progression au chargement
  setTimeout(() => {
    animateProgressBars(document.getElementById('panel-all'));
    animateProgressRings();
  }, 300);

  // Toast de bienvenue
  const nb = <?= count($purchased) ?>;
  setTimeout(() => {
    if (nb > 0) showToast('Ma Bibliothèque', `${nb} livre${nb > 1 ? 's' : ''} dans votre collection`, 'success', 3500);
    else showToast('Bienvenue 📚', 'Explorez le catalogue pour commencer', 'info', 3500);
  }, 600);

  // Hover sur les étoiles
  document.querySelectorAll('.stars').forEach(row => {
    const stars = row.querySelectorAll('.star');
    stars.forEach((star, idx) => {
      star.addEventListener('mouseenter', () => {
        stars.forEach((s, i) => s.classList.toggle('filled', i <= idx));
      });
      star.addEventListener('mouseleave', () => {
        const savedNote = parseInt(row.dataset.note || 0);
        stars.forEach((s, i) => s.classList.toggle('filled', i < savedNote));
      });
    });
  });
});
</script>
</body>
</html>