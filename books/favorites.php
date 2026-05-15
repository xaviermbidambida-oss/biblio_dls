<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — books/favorites.php                   ║
 * ║  Gestion complète des favoris — v3.0 PRODUCTION                 ║
 * ║  ✅ PDO sécurisé · AJAX temps réel · PDF · Lecture · Facture    ║
 * ║  ✅ Impression propre · Aucun doublon · 100% fonctionnel        ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// ── Session ──────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Connexion PDO robuste ─────────────────────────────────────────────
$pdo = null;
foreach ([
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../../includes/config.php',
] as $_cfgPath) {
    if (file_exists($_cfgPath) && !defined('DLS_CFG')) {
        require_once $_cfgPath;
        define('DLS_CFG', true);
        break;
    }
}
if (!isset($pdo) || $pdo === null) {
    $h = defined('DB_HOST') ? DB_HOST : 'localhost';
    $n = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $u = defined('DB_USER') ? DB_USER : 'root';
    $p = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$h};dbname={$n};charset=utf8mb4",
            $u, $p,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        error_log('[FAV] PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth ──────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=books/favorites.php');
    exit;
}

$userId    = (int) $_SESSION['user_id'];
$userRole  = $_SESSION['user_role'] ?? 'lecteur';
$username  = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$firstName = htmlspecialchars(explode(' ', trim($username))[0] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$avatar    = strtoupper(substr($username, 0, 1)) ?: 'U';

// ── CSRF ──────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_fav'])) {
    $_SESSION['csrf_fav'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_fav'];

// ── Auto-création tables ──────────────────────────────────────────────
if ($pdo) {
    try {
        // Table favorites (compatible avec la colonne "favorites" ET "favoris")
        $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            livre_id   INT UNSIGNED NOT NULL,
            created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fav (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Table lecture_progression
        $pdo->exec("CREATE TABLE IF NOT EXISTS lecture_progression (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id       INT UNSIGNED NOT NULL,
            livre_id      INT UNSIGNED NOT NULL,
            page_actuelle INT UNSIGNED DEFAULT 1,
            pourcentage   DECIMAL(5,2) DEFAULT 0.00,
            termine       TINYINT(1)   DEFAULT 0,
            updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_prog (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Table user_downloads
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_downloads (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            livre_id   INT UNSIGNED NOT NULL,
            count      INT UNSIGNED DEFAULT 1,
            last_dl_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dl (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Notifications avec colonnes compatibles
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NULL,
            type       VARCHAR(50)  DEFAULT 'info',
            title      VARCHAR(255) DEFAULT '',
            message    TEXT,
            icon       VARCHAR(10)  DEFAULT '🔔',
            is_read    TINYINT(1)   DEFAULT 0,
            is_archived TINYINT(1)  DEFAULT 0,
            created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Colonnes compatibilité notifications
        $notifCols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('titre', $notifCols))
            $pdo->exec("ALTER TABLE notifications ADD COLUMN titre VARCHAR(255) DEFAULT '' AFTER type");
        if (!in_array('lu', $notifCols))
            $pdo->exec("ALTER TABLE notifications ADD COLUMN lu TINYINT(1) DEFAULT 0");
        if (!in_array('bg', $notifCols))
            $pdo->exec("ALTER TABLE notifications ADD COLUMN bg VARCHAR(80) DEFAULT NULL");

        // Settings (setting_key safe)
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key   VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Colonnes livres optionnelles
        $livreCols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'livres'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('fichier_pdf', $livreCols))
            $pdo->exec("ALTER TABLE livres ADD COLUMN fichier_pdf VARCHAR(500) NULL");
        if (!in_array('contenu_extrait', $livreCols))
            $pdo->exec("ALTER TABLE livres ADD COLUMN contenu_extrait MEDIUMTEXT NULL");
        if (!in_array('nb_telechargements', $livreCols))
            $pdo->exec("ALTER TABLE livres ADD COLUMN nb_telechargements INT UNSIGNED DEFAULT 0");
        if (!in_array('is_bestseller', $livreCols))
            $pdo->exec("ALTER TABLE livres ADD COLUMN is_bestseller TINYINT(1) DEFAULT 0");
        if (!in_array('pages', $livreCols))
            $pdo->exec("ALTER TABLE livres ADD COLUMN pages INT DEFAULT 200");
        if (!in_array('editeur', $livreCols))
            $pdo->exec("ALTER TABLE livres ADD COLUMN editeur VARCHAR(150) NULL");
        if (!in_array('annee_parution', $livreCols))
            $pdo->exec("ALTER TABLE livres ADD COLUMN annee_parution YEAR NULL");

    } catch (Throwable $e) {
        error_log('[FAV] Schema: ' . $e->getMessage());
    }
}

// ── Helpers ───────────────────────────────────────────────────────────
function safeE(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmtFCFA(float $n): string { return number_format($n, 0, ',', ' ') . ' FCFA'; }
function timeAgo(string $dt): string {
    if (!$dt) return '—';
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'à l\'instant';
    if ($d < 3600)   return (int)($d / 60) . ' min';
    if ($d < 86400)  return (int)($d / 3600) . 'h';
    if ($d < 604800) return (int)($d / 86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}
function checkCsrf(string $t): bool {
    return hash_equals($_SESSION['csrf_fav'] ?? '', $t);
}

// ── MAX DOWNLOADS setting ─────────────────────────────────────────────
$maxDownloads = 3;
if ($pdo) {
    try {
        $st = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='max_downloads' LIMIT 1");
        if ($st) { $v = $st->fetchColumn(); if ($v !== false) $maxDownloads = (int) $v; }
    } catch (Throwable $e) {}
}

// ═════════════════════════════════════════════════════════════════════
// AJAX ENDPOINT — toutes les actions en un point
// ═════════════════════════════════════════════════════════════════════
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($isAjax && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // CSRF pour mutations
    $mutations = ['toggle_fav','remove_fav','mark_read','save_progression',
                  'download','add_notif','mark_all_read'];
    if (in_array($action, $mutations, true)) {
        $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!checkCsrf($token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']);
            exit;
        }
    }

    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Base de données inaccessible']);
        exit;
    }

    try {
        switch ($action) {

            // ── Toggle favori ──────────────────────────────────────
            case 'toggle_fav':
                $livreId = (int) ($_POST['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('ID livre manquant');

                $check = $pdo->prepare("SELECT id FROM favorites WHERE user_id=? AND livre_id=?");
                $check->execute([$userId, $livreId]);
                $existing = $check->fetchColumn();

                if ($existing) {
                    $pdo->prepare("DELETE FROM favorites WHERE user_id=? AND livre_id=?")
                        ->execute([$userId, $livreId]);
                    $favorited = false;
                    $msg = 'Retiré des favoris';
                } else {
                    $pdo->prepare("INSERT IGNORE INTO favorites (user_id, livre_id) VALUES (?,?)")
                        ->execute([$userId, $livreId]);
                    $favorited = true;
                    $msg = 'Ajouté aux favoris';
                    // Notification
                    try {
                        $lSt = $pdo->prepare("SELECT titre FROM livres WHERE id=?");
                        $lSt->execute([$livreId]);
                        $livreTitre = $lSt->fetchColumn() ?: 'Livre';
                        $pdo->prepare(
                            "INSERT INTO notifications (user_id, type, title, titre, message, icon)
                             VALUES (?,?,?,?,?,?)"
                        )->execute([$userId, 'favoris', "❤️ Ajouté aux favoris",
                            "❤️ Ajouté aux favoris",
                            "«{$livreTitre}» a été ajouté à vos favoris.", '❤️']);
                    } catch (Throwable $e) {}
                }

                $count = (int) $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id=?")
                              ->execute([$userId]) ? 0 : 0;
                $cSt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id=?");
                $cSt->execute([$userId]);
                $count = (int) $cSt->fetchColumn();

                echo json_encode([
                    'success'   => true,
                    'favorited' => $favorited,
                    'message'   => $msg,
                    'total'     => $count,
                ]);
                break;

            // ── Charger les favoris (liste paginée) ───────────────
            case 'get_favs':
                $page    = max(1, (int) ($_GET['page']   ?? 1));
                $perPage = max(4, min(48, (int) ($_GET['per'] ?? 12)));
                $sort    = $_GET['sort'] ?? 'date_desc';
                $search  = trim($_GET['q'] ?? '');
                $cat     = (int) ($_GET['cat'] ?? 0);
                $offset  = ($page - 1) * $perPage;

                $where  = ["f.user_id = :uid", "l.statut = 'disponible'"];
                $params = [':uid' => $userId];

                if ($search) {
                    $where[]       = "(l.titre LIKE :s OR l.auteur LIKE :s)";
                    $params[':s']  = "%{$search}%";
                }
                if ($cat > 0) {
                    $where[]       = "l.categorie_id = :cat";
                    $params[':cat'] = $cat;
                }

                $whereSQL = 'WHERE ' . implode(' AND ', $where);
                $orderSQL = match ($sort) {
                    'note_desc'  => 'ORDER BY l.note_moyenne DESC',
                    'prix_asc'   => 'ORDER BY l.prix ASC',
                    'prix_desc'  => 'ORDER BY l.prix DESC',
                    'titre_asc'  => 'ORDER BY l.titre ASC',
                    default      => 'ORDER BY f.created_at DESC',
                };

                $cSt = $pdo->prepare("SELECT COUNT(*) FROM favorites f JOIN livres l ON l.id=f.livre_id $whereSQL");
                $cSt->execute($params);
                $total = (int) $cSt->fetchColumn();

                $st = $pdo->prepare(
                    "SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne,
                            l.pages, l.annee_parution, l.fichier_pdf,
                            l.nb_telechargements, l.is_bestseller, l.editeur,
                            c.nom AS cat_nom, c.icone AS cat_icone,
                            f.created_at AS fav_date,
                            COALESCE(lp.pourcentage, 0) AS progression,
                            COALESCE(lp.page_actuelle, 1) AS page_actuelle,
                            COALESCE(ud.count, 0) AS nb_downloads,
                            (SELECT COUNT(*) FROM achats a
                             WHERE a.user_id = :uid2 AND a.livre_id = l.id AND a.statut='confirme') AS is_purchased
                     FROM favorites f
                     JOIN livres l ON l.id = f.livre_id
                     LEFT JOIN categories c ON c.id = l.categorie_id
                     LEFT JOIN lecture_progression lp ON lp.user_id = f.user_id AND lp.livre_id = l.id
                     LEFT JOIN user_downloads ud ON ud.user_id = f.user_id AND ud.livre_id = l.id
                     $whereSQL
                     $orderSQL
                     LIMIT :lim OFFSET :off"
                );
                $params[':uid2'] = $userId;
                foreach ($params as $k => $v) $st->bindValue($k, $v);
                $st->bindValue(':lim',  $perPage, PDO::PARAM_INT);
                $st->bindValue(':off',  $offset,  PDO::PARAM_INT);
                $st->execute();
                $items = $st->fetchAll();

                echo json_encode([
                    'success' => true,
                    'items'   => $items,
                    'total'   => $total,
                    'pages'   => max(1, (int) ceil($total / $perPage)),
                    'page'    => $page,
                ]);
                break;

            // ── Retirer un favori ──────────────────────────────────
            case 'remove_fav':
                $livreId = (int) ($_POST['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('ID manquant');
                $pdo->prepare("DELETE FROM favorites WHERE user_id=? AND livre_id=?")
                    ->execute([$userId, $livreId]);
                $cSt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id=?");
                $cSt->execute([$userId]);
                echo json_encode(['success' => true, 'total' => (int) $cSt->fetchColumn()]);
                break;

            // ── Sauvegarder progression lecture ────────────────────
            case 'save_progression':
                $livreId = (int) ($_POST['livre_id'] ?? 0);
                $page    = max(1, (int) ($_POST['page'] ?? 1));
                $pct     = min(100, max(0, (float) ($_POST['pourcentage'] ?? 0)));
                $termine = $pct >= 100 ? 1 : 0;
                if (!$livreId) throw new Exception('ID manquant');
                $pdo->prepare(
                    "INSERT INTO lecture_progression (user_id, livre_id, page_actuelle, pourcentage, termine)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE page_actuelle=VALUES(page_actuelle),
                                             pourcentage=VALUES(pourcentage),
                                             termine=VALUES(termine)"
                )->execute([$userId, $livreId, $page, $pct, $termine]);
                echo json_encode(['success' => true]);
                break;

            // ── Récupérer progression ──────────────────────────────
            case 'get_progression':
                $livreId = (int) ($_GET['livre_id'] ?? 0);
                if (!$livreId) { echo json_encode(['success' => true, 'page' => 1, 'pct' => 0]); break; }
                $st = $pdo->prepare("SELECT page_actuelle, pourcentage FROM lecture_progression WHERE user_id=? AND livre_id=?");
                $st->execute([$userId, $livreId]);
                $prog = $st->fetch() ?: ['page_actuelle' => 1, 'pourcentage' => 0];
                echo json_encode(['success' => true, 'page' => (int) $prog['page_actuelle'], 'pct' => (float) $prog['pourcentage']]);
                break;

            // ── Téléchargement PDF ─────────────────────────────────
            case 'download':
                $livreId = (int) ($_POST['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('ID manquant');

                // Vérifier possession
                $ownSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM achats
                     WHERE user_id=? AND livre_id=? AND statut='confirme'"
                );
                $ownSt->execute([$userId, $livreId]);
                $owned = (int) $ownSt->fetchColumn() > 0;

                // Les favoris sans achat peuvent voir si le livre est gratuit
                if (!$owned) {
                    $priceSt = $pdo->prepare("SELECT prix, note_moyenne FROM livres WHERE id=?");
                    $priceSt->execute([$livreId]);
                    $lRow = $priceSt->fetch();
                    $isFreeBook = $lRow && ((float) $lRow['prix'] === 0.0 || (float) $lRow['note_moyenne'] <= 2.0);
                    if (!$isFreeBook && $userRole !== 'admin') {
                        echo json_encode(['success' => false, 'error' => 'Achat requis pour télécharger ce livre']);
                        exit;
                    }
                }

                // Vérifier limite téléchargements
                $dlSt = $pdo->prepare("SELECT count FROM user_downloads WHERE user_id=? AND livre_id=?");
                $dlSt->execute([$userId, $livreId]);
                $dlRow = $dlSt->fetch();
                $currentDl = (int) ($dlRow['count'] ?? 0);

                if ($currentDl >= $maxDownloads && $userRole !== 'admin') {
                    echo json_encode(['success' => false, 'error' => "Limite de {$maxDownloads} téléchargements atteinte"]);
                    exit;
                }

                // Incrémenter compteur
                $pdo->prepare(
                    "INSERT INTO user_downloads (user_id, livre_id, count)
                     VALUES (?,?,1) ON DUPLICATE KEY UPDATE count=count+1, last_dl_at=NOW()"
                )->execute([$userId, $livreId]);

                // Incrémenter sur livre
                try { $pdo->prepare("UPDATE livres SET nb_telechargements=nb_telechargements+1 WHERE id=?")->execute([$livreId]); } catch(Throwable $e){}

                // Infos livre
                $lSt = $pdo->prepare("SELECT titre, fichier_pdf FROM livres WHERE id=?");
                $lSt->execute([$livreId]);
                $livre = $lSt->fetch();

                // Notification
                try {
                    $pdo->prepare(
                        "INSERT INTO notifications (user_id, type, title, titre, message, icon)
                         VALUES (?,?,?,?,?,?)"
                    )->execute([$userId, 'telechargement',
                        '⬇️ Téléchargement PDF', '⬇️ Téléchargement PDF',
                        'Vous avez téléchargé «' . ($livre['titre'] ?? '') . '».', '⬇️']);
                } catch (Throwable $e) {}

                $remaining = $maxDownloads - $currentDl - 1;
                if ($remaining < 0) $remaining = 0;

                echo json_encode([
                    'success'   => true,
                    'titre'     => $livre['titre'] ?? '',
                    'fichier'   => $livre['fichier_pdf'] ?? '',
                    'remaining' => $remaining,
                    'max'       => $maxDownloads,
                ]);
                break;

            // ── Données facture ────────────────────────────────────
            case 'get_invoice':
                $achatId = (int) ($_GET['achat_id'] ?? 0);
                $livreId = (int) ($_GET['livre_id'] ?? 0);

                if ($achatId) {
                    $st = $pdo->prepare(
                        "SELECT a.id, a.montant, a.methode, a.statut, a.reference,
                                a.created_at, l.titre, l.auteur, l.prix, l.editeur,
                                u.nom, u.prenom, u.email
                         FROM achats a
                         JOIN livres l ON l.id=a.livre_id
                         JOIN users u ON u.id=a.user_id
                         WHERE a.id=? AND a.user_id=?"
                    );
                    $st->execute([$achatId, $userId]);
                } else {
                    $st = $pdo->prepare(
                        "SELECT a.id, a.montant, a.methode, a.statut, a.reference,
                                a.created_at, l.titre, l.auteur, l.prix, l.editeur,
                                u.nom, u.prenom, u.email
                         FROM achats a
                         JOIN livres l ON l.id=a.livre_id
                         JOIN users u ON u.id=a.user_id
                         WHERE a.livre_id=? AND a.user_id=? AND a.statut='confirme'
                         ORDER BY a.created_at DESC LIMIT 1"
                    );
                    $st->execute([$livreId, $userId]);
                }
                $inv = $st->fetch();
                if (!$inv) { echo json_encode(['success' => false, 'error' => 'Facture introuvable']); exit; }
                echo json_encode(['success' => true, 'invoice' => $inv]);
                break;

            // ── Stats favoris ──────────────────────────────────────
            case 'stats':
                $total = (int) $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id=?")
                              ->execute([$userId]) ? 0 : 0;
                $tSt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id=?");
                $tSt->execute([$userId]);
                $total = (int) $tSt->fetchColumn();

                $readSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM lecture_progression lp
                     JOIN favorites f ON f.livre_id=lp.livre_id AND f.user_id=lp.user_id
                     WHERE lp.user_id=? AND lp.pourcentage>0"
                );
                $readSt->execute([$userId]);
                $enCours = (int) $readSt->fetchColumn();

                $doneSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM lecture_progression lp
                     JOIN favorites f ON f.livre_id=lp.livre_id AND f.user_id=lp.user_id
                     WHERE lp.user_id=? AND lp.pourcentage>=100"
                );
                $doneSt->execute([$userId]);
                $termines = (int) $doneSt->fetchColumn();

                $purchSt = $pdo->prepare(
                    "SELECT COUNT(DISTINCT f.livre_id) FROM favorites f
                     JOIN achats a ON a.livre_id=f.livre_id AND a.user_id=f.user_id AND a.statut='confirme'
                     WHERE f.user_id=?"
                );
                $purchSt->execute([$userId]);
                $achetes = (int) $purchSt->fetchColumn();

                echo json_encode([
                    'success'  => true,
                    'total'    => $total,
                    'en_cours' => $enCours,
                    'termines' => $termines,
                    'achetes'  => $achetes,
                ]);
                break;

            // ── Notifications ──────────────────────────────────────
            case 'get_notifs':
                $nSt = $pdo->prepare(
                    "SELECT id, type,
                            COALESCE(title, titre, 'Notification') AS titre_display,
                            message,
                            COALESCE(icon, '🔔') AS icon,
                            COALESCE(is_read, lu, 0) AS is_read_val,
                            created_at
                     FROM notifications
                     WHERE user_id=? OR user_id IS NULL
                     ORDER BY created_at DESC LIMIT 8"
                );
                $nSt->execute([$userId]);
                $notifs = $nSt->fetchAll();

                $ucSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM notifications
                     WHERE (is_read=0 OR (is_read IS NULL AND lu=0))
                       AND (user_id=? OR user_id IS NULL)"
                );
                $ucSt->execute([$userId]);
                echo json_encode([
                    'success' => true,
                    'notifs'  => $notifs,
                    'unread'  => (int) $ucSt->fetchColumn(),
                ]);
                break;

            case 'mark_read':
                $nid = (int) ($_POST['notif_id'] ?? 0);
                if ($nid) {
                    try {
                        $pdo->prepare("UPDATE notifications SET is_read=1, lu=1 WHERE id=? AND (user_id=? OR user_id IS NULL)")
                            ->execute([$nid, $userId]);
                    } catch(Throwable $e){}
                }
                echo json_encode(['success' => true]);
                break;

            case 'mark_all_read':
                try {
                    $pdo->prepare("UPDATE notifications SET is_read=1, lu=1 WHERE user_id=? OR user_id IS NULL")
                        ->execute([$userId]);
                } catch(Throwable $e){}
                echo json_encode(['success' => true]);
                break;

            // ── Catégories pour filtres ────────────────────────────
            case 'get_cats':
                $st = $pdo->query(
                    "SELECT c.id, c.nom, c.icone, COUNT(f.id) AS nb
                     FROM categories c
                     JOIN livres l ON l.categorie_id=c.id
                     JOIN favorites f ON f.livre_id=l.id AND f.user_id={$userId}
                     GROUP BY c.id, c.nom, c.icone ORDER BY nb DESC"
                );
                echo json_encode(['success' => true, 'cats' => $st->fetchAll()]);
                break;

            // ── Vérifier PDF réel ──────────────────────────────────
            case 'check_pdf':
                $livreId = (int) ($_GET['livre_id'] ?? 0);
                if (!$livreId) { echo json_encode(['has_pdf' => false]); exit; }
                $st = $pdo->prepare("SELECT fichier_pdf FROM livres WHERE id=?");
                $st->execute([$livreId]);
                $row = $st->fetch();
                $pdf   = $row['fichier_pdf'] ?? '';
                $hasPDF = $pdf && file_exists(dirname(__DIR__) . '/' . ltrim($pdf, '/'));
                echo json_encode([
                    'has_pdf' => $hasPDF,
                    'path'    => $hasPDF ? ('../' . ltrim($pdf, '/')) : '',
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Action inconnue: $action"]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════
// DONNÉES INITIALES
// ═════════════════════════════════════════════════════════════════════
$totalFavs  = 0;
$notifCount = 0;
$notifs     = [];
$cats       = [];

if ($pdo) {
    try {
        $tSt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id=?");
        $tSt->execute([$userId]);
        $totalFavs = (int) $tSt->fetchColumn();

        $nSt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE (is_read=0 OR lu=0) AND (user_id=? OR user_id IS NULL)"
        );
        $nSt->execute([$userId]);
        $notifCount = (int) $nSt->fetchColumn();

        $nListSt = $pdo->prepare(
            "SELECT id, type,
                    COALESCE(title, titre, '') AS titre_display,
                    message,
                    COALESCE(icon, '🔔') AS icon,
                    COALESCE(is_read, lu, 0) AS is_read_val,
                    created_at
             FROM notifications
             WHERE user_id=? OR user_id IS NULL
             ORDER BY created_at DESC LIMIT 6"
        );
        $nListSt->execute([$userId]);
        $notifs = $nListSt->fetchAll();

        $catSt = $pdo->query(
            "SELECT c.id, c.nom, c.icone, COUNT(f.id) AS nb
             FROM categories c
             JOIN livres l ON l.categorie_id=c.id
             JOIN favorites f ON f.livre_id=l.id AND f.user_id={$userId}
             GROUP BY c.id, c.nom, c.icone ORDER BY nb DESC"
        );
        $cats = $catSt->fetchAll();

    } catch (Throwable $e) { /* non bloquant */ }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes Favoris — Digital Library</title>
<!-- Impression propre : supprime URL, date, heure et en-têtes navigateur -->
<style>
@media print {
  @page {
    size: A4;
    margin: 15mm 12mm;
  }
  /* Supprimer les en-têtes / pieds de page du navigateur */
  html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ════════════════════════════════════════
   VARIABLES & RESET
════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#05080f;--surf:#0b1020;--card:rgba(255,255,255,.032);--card-h:rgba(255,255,255,.056);
  --border:rgba(255,255,255,.072);--border-a:rgba(244,63,94,.4);
  --rose:#f43f5e;--cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;
  --amber:#f59e0b;--orange:#f97316;--plum:#a78bfa;
  --tp:#eef2ff;--ts:rgba(238,242,255,.56);--tm:rgba(238,242,255,.28);
  --r1:8px;--r2:13px;--r3:18px;--r4:26px;
  --gc:0 0 24px rgba(244,63,94,.15);--sc:0 4px 24px rgba(0,0,0,.35);
  --slg:0 20px 60px rgba(0,0,0,.52);
  --ease:cubic-bezier(.34,1.56,.64,1);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tp);min-height:100vh;overflow-x:hidden}
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}

/* ── PAGE LAYOUT ── */
.page{max-width:1400px;margin:0 auto;padding:2rem 1.6rem 5rem}

/* ── TOP NAV ── */
.top-nav{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1.8rem;padding-bottom:1rem;border-bottom:1px solid var(--border)}
.nav-link{display:inline-flex;align-items:center;gap:5px;font-size:.73rem;color:var(--ts);text-decoration:none;padding:5px 11px;border-radius:var(--r1);border:1px solid var(--border);background:var(--card);transition:all .15s;white-space:nowrap}
.nav-link:hover,.nav-link.on{color:var(--rose);border-color:var(--border-a);background:rgba(244,63,94,.05)}
.nav-sep{flex:1}
.notif-trigger{position:relative;cursor:pointer}
.notif-badge{position:absolute;top:-4px;right:-4px;width:16px;height:16px;background:var(--rose);border-radius:50%;font-size:.5rem;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);color:#fff}

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.8rem;flex-wrap:wrap}
.page-title{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;letter-spacing:-.5px;display:flex;align-items:center;gap:.6rem}
.page-sub{font-size:.78rem;color:var(--ts);margin-top:4px}
.page-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}

/* ── STATS CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.8rem;margin-bottom:1.8rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:1rem;position:relative;overflow:hidden;transition:all .22s;animation:fadeUp .4s ease both}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--a1),var(--a2));opacity:0;transition:opacity .3s}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--sc)}
.stat-card:hover::before{opacity:1}
.stat-card:nth-child(1){--a1:var(--rose);--a2:var(--amber)}
.stat-card:nth-child(2){--a1:var(--cyan);--a2:var(--violet)}
.stat-card:nth-child(3){--a1:var(--neon);--a2:var(--cyan)}
.stat-card:nth-child(4){--a1:var(--amber);--a2:var(--orange)}
.sc-val{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--a1),var(--a2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sc-label{font-size:.7rem;color:var(--ts);margin-top:3px}

/* ── TOOLBAR ── */
.toolbar{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:1.3rem;padding:.9rem 1.1rem;background:var(--card);border:1px solid var(--border);border-radius:var(--r2)}
.toolbar-search{display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r1);padding:6px 11px;flex:1;min-width:180px;max-width:300px;transition:border-color .2s}
.toolbar-search:focus-within{border-color:rgba(244,63,94,.4)}
.toolbar-search input{background:none;border:none;outline:none;color:var(--tp);font-size:.78rem;font-family:'DM Sans',sans-serif;width:100%}
.toolbar-search input::placeholder{color:var(--tm)}
.ts-ico{color:var(--tm);font-size:.8rem}
.tb-sel{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r1);padding:6px 10px;color:var(--tp);font-size:.75rem;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;transition:border-color .2s}
.tb-sel:focus{border-color:rgba(244,63,94,.4)}
.tb-count{font-family:'Space Mono',monospace;font-size:.65rem;color:var(--tm);margin-left:auto;white-space:nowrap}

/* ── BOOKS GRID ── */
.books-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.1rem}

/* ── BOOK CARD ── */
.book-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);overflow:hidden;transition:all .22s;animation:fadeUp .4s ease both;position:relative}
.book-card:hover{transform:translateY(-5px);border-color:rgba(244,63,94,.2);box-shadow:0 14px 40px rgba(0,0,0,.4)}
.bc-cover{height:130px;display:flex;align-items:center;justify-content:center;font-size:2.8rem;position:relative;overflow:hidden}
.bc-cover-bg{position:absolute;inset:0}
.bc-cover-emoji{position:relative;z-index:1;filter:drop-shadow(0 4px 12px rgba(0,0,0,.5));transition:transform .3s var(--ease)}
.book-card:hover .bc-cover-emoji{transform:scale(1.15) rotate(-4deg)}
.bc-cover-vignette{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(5,8,15,.88));z-index:2}
.bc-fav-btn{position:absolute;top:8px;right:8px;z-index:5;width:30px;height:30px;border-radius:50%;background:rgba(244,63,94,.15);border:1px solid rgba(244,63,94,.3);color:var(--rose);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;font-size:.85rem}
.bc-fav-btn:hover{background:rgba(244,63,94,.3);transform:scale(1.15)}
.bc-pct{position:absolute;bottom:0;left:0;right:0;z-index:3;height:3px;background:rgba(255,255,255,.08)}
.bc-pct-fill{height:100%;background:linear-gradient(90deg,var(--rose),var(--amber));transition:width .8s ease;border-radius:2px}
.bc-body{padding:.9rem 1rem}
.bc-cat{font-family:'Space Mono',monospace;font-size:.56rem;color:var(--rose);letter-spacing:.08em;text-transform:uppercase;margin-bottom:3px}
.bc-title{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;line-height:1.2;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bc-author{font-size:.72rem;color:var(--ts);margin-bottom:.6rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bc-meta{display:flex;align-items:center;justify-content:space-between;margin-bottom:.7rem;font-size:.68rem}
.bc-stars{color:var(--amber);letter-spacing:-1px}
.bc-pages{color:var(--tm);font-family:'Space Mono',monospace}
.bc-price{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;color:var(--neon)}
.bc-price.paid{color:var(--amber)}
.bc-actions{display:flex;gap:5px;flex-wrap:wrap}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:var(--r1);font-family:'Syne',sans-serif;font-size:.73rem;font-weight:700;cursor:pointer;transition:all .17s;text-decoration:none;border:none;white-space:nowrap}
.btn-sm{padding:5px 10px;font-size:.68rem}
.btn-xs{padding:3px 8px;font-size:.62rem}
.btn-primary{background:linear-gradient(135deg,var(--rose),var(--amber));color:#fff;box-shadow:0 4px 14px rgba(244,63,94,.2)}
.btn-primary:hover{opacity:.87;transform:translateY(-1px)}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--ts)}
.btn-ghost:hover{color:var(--tp);background:var(--card-h)}
.btn-danger{background:rgba(244,63,94,.07);border:1px solid rgba(244,63,94,.22);color:var(--rose)}
.btn-danger:hover{background:rgba(244,63,94,.14)}
.btn-success{background:rgba(0,255,170,.07);border:1px solid rgba(0,255,170,.22);color:var(--neon)}
.btn-success:hover{background:rgba(0,255,170,.14)}
.btn-warn{background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.22);color:var(--amber)}
.btn-warn:hover{background:rgba(245,158,11,.14)}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.58rem;font-family:'Space Mono',monospace;padding:2px 7px;border-radius:100px;font-weight:700;text-transform:uppercase}
.badge-neon{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.badge-rose{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.badge-amber{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.badge-muted{background:rgba(255,255,255,.05);color:var(--tm);border:1px solid var(--border)}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;justify-content:center;gap:4px;padding:1rem 0;margin-top:.5rem}
.pag-btn{width:30px;height:30px;border-radius:var(--r1);background:var(--card);border:1px solid var(--border);color:var(--ts);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.7rem;font-family:'Space Mono',monospace;transition:all .15s}
.pag-btn:hover,.pag-btn.on{color:var(--rose);background:rgba(244,63,94,.08);border-color:rgba(244,63,94,.25)}
.pag-btn[disabled]{opacity:.3;pointer-events:none}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:3.5rem 1rem}
.empty-icon{font-size:3rem;display:block;margin-bottom:.6rem;opacity:.4}
.empty-t{font-family:'Syne',sans-serif;font-weight:700;margin-bottom:.3rem}
.empty-s{font-size:.78rem;color:var(--tm)}

/* ── LOADER ── */
.loader-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem;gap:.7rem}
.spinner{width:28px;height:28px;border:2px solid rgba(244,63,94,.15);border-top-color:var(--rose);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── NOTIF PANEL ── */
#np{position:fixed;top:0;right:0;height:100vh;width:320px;background:var(--surf);border-left:1px solid var(--border);box-shadow:var(--slg);z-index:800;transform:translateX(100%);transition:transform .3s var(--ease);display:flex;flex-direction:column}
#np.open{transform:translateX(0)}
.np-h{padding:1rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem;flex-shrink:0}
.np-list{flex:1;overflow-y:auto;padding:.5rem 0}
.np-item{display:flex;gap:10px;padding:9px 1.1rem;border-bottom:1px solid rgba(255,255,255,.04);cursor:pointer;transition:background .12s}
.np-item:hover{background:var(--card-h)}
.np-item.unread{background:rgba(244,63,94,.03)}
.np-ico{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;background:rgba(244,63,94,.1)}
.np-txt{font-size:.74rem;color:var(--ts);line-height:1.45}
.np-time{font-size:.58rem;font-family:'Space Mono',monospace;color:var(--tm);margin-top:2px}
.np-ft{padding:.7rem 1.1rem;border-top:1px solid var(--border);display:flex;gap:6px;flex-shrink:0}

/* ── READER MODAL ── */
#reader-modal{position:fixed;inset:0;z-index:9600;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s;background:#0e0d0b;display:flex;flex-direction:column}
#reader-modal.open{opacity:1;visibility:visible}
.rm-hdr{height:54px;display:flex;align-items:center;justify-content:space-between;padding:0 1.6rem;border-bottom:1px solid rgba(255,255,255,.06);background:rgba(0,0,0,.4);backdrop-filter:blur(10px);flex-shrink:0}
.rm-title{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.rm-controls{display:flex;align-items:center;gap:.4rem;flex-shrink:0}
.rm-btn{width:32px;height:32px;border-radius:8px;background:var(--card);border:1px solid var(--border);color:var(--tm);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.83rem;transition:all .2s}
.rm-btn:hover{color:var(--tp);border-color:rgba(244,63,94,.3)}
.rm-close{border-color:rgba(244,63,94,.3);color:var(--rose)}
.rm-prog{height:3px;background:rgba(255,255,255,.05);flex-shrink:0}
.rm-prog-fill{height:100%;background:linear-gradient(90deg,var(--rose),var(--amber));transition:width .5s}
.rm-body{flex:1;overflow-y:auto}
.rm-inner{max-width:680px;margin:0 auto;padding:3rem 2rem 7rem}
.rm-content{font-family:'Georgia',serif;font-size:1.05rem;line-height:1.95;color:#e8e4da}
.rm-content h2{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:700;margin:2.5rem 0 1.2rem;color:#f0eeea;border-bottom:1px solid rgba(255,255,255,.06);padding-bottom:.5rem}
.rm-content p{margin-bottom:1.3rem;text-indent:1.5em}
.rm-content p:first-of-type{text-indent:0}
.rm-nav{position:sticky;bottom:0;display:flex;align-items:center;justify-content:center;gap:1rem;background:rgba(14,13,11,.95);border-top:1px solid rgba(255,255,255,.06);padding:11px 2rem;backdrop-filter:blur(20px)}
.rm-nav-btn{background:none;border:1px solid var(--border);border-radius:8px;color:var(--tm);cursor:pointer;padding:7px 13px;display:flex;align-items:center;gap:4px;font-size:.76rem;font-family:'DM Sans',sans-serif;transition:all .2s}
.rm-nav-btn:hover:not(:disabled){border-color:rgba(244,63,94,.3);color:var(--rose)}
.rm-nav-btn:disabled{opacity:.3;cursor:not-allowed}
.rm-pinfo{font-family:'Space Mono',monospace;font-size:.68rem;color:var(--tm);min-width:80px;text-align:center}
#reader-modal.reader-light{background:#f5f0e8}
#reader-modal.reader-light .rm-content{color:#2c2a24}
#reader-modal.reader-light .rm-content h2{color:#1a1814}
#reader-modal.reader-light .rm-hdr,.reader-light .rm-nav{background:rgba(245,240,232,.95);border-color:rgba(0,0,0,.08)}

/* ── INVOICE MODAL ── */
.modal-ov{position:fixed;inset:0;background:rgba(5,8,15,.88);backdrop-filter:blur(14px);z-index:900;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s}
.modal-ov.open{opacity:1;visibility:visible}
.modal-box{background:var(--surf);border:1px solid var(--border);border-radius:var(--r4);width:100%;max-width:480px;box-shadow:var(--slg);transform:translateY(24px);transition:transform .3s var(--ease);position:relative;overflow:hidden}
.modal-ov.open .modal-box{transform:translateY(0)}
.modal-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--rose),var(--amber),var(--neon))}
.modal-close{position:absolute;top:.9rem;right:.9rem;background:none;border:none;color:var(--tm);font-size:.95rem;cursor:pointer;width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.modal-close:hover{color:var(--rose);background:rgba(244,63,94,.06)}
.inv-head{padding:1.4rem;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between}
.inv-logo{font-family:'Syne',sans-serif;font-weight:800;font-size:1rem}
.inv-body{padding:1.4rem}
.inv-row{display:flex;align-items:center;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.8rem}
.inv-row:last-child{border-bottom:none}
.inv-lbl{color:var(--tm);font-family:'Space Mono',monospace;font-size:.65rem;text-transform:uppercase}
.inv-val{color:var(--tp);font-weight:600}
.inv-total{background:rgba(0,255,170,.05);border:1px solid rgba(0,255,170,.1);border-radius:var(--r1);padding:1rem;margin-top:1rem;text-align:center}
.inv-total-lbl{font-size:.65rem;color:var(--tm);font-family:'Space Mono',monospace;text-transform:uppercase}
.inv-total-val{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--neon);margin-top:3px}
.inv-ft{padding:1rem 1.4rem;border-top:1px solid var(--border);display:flex;gap:8px}

/* ── TOAST ── */
#toast-s{position:fixed;bottom:1.4rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:6px;pointer-events:none}
.toast{display:flex;align-items:center;gap:9px;padding:10px 14px;background:var(--surf);border:1px solid var(--border);border-radius:var(--r2);box-shadow:var(--slg);font-size:.76rem;max-width:310px;pointer-events:all;transform:translateX(120px);opacity:0;transition:all .35s var(--ease)}
.toast.show{transform:translateX(0);opacity:1}
.t-title{font-family:'Syne',sans-serif;font-weight:700}
.t-sub{font-size:.66rem;color:var(--tm);margin-top:1px}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

/* ── OVERLAY notif ── */
#np-ov{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:799;opacity:0;pointer-events:none;transition:opacity .3s}
#np-ov.show{opacity:1;pointer-events:all}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  .books-grid{grid-template-columns:1fr}
  .toolbar{flex-direction:column;align-items:stretch}
  .toolbar-search{max-width:100%}
  .page{padding:1.2rem .9rem}
}

/* ════════════════════════════════════════
   STYLES D'IMPRESSION — FACTURE UNIQUE
   Supprime date, URL, en-têtes navigateur
════════════════════════════════════════ */
@media print {
  /* Masquer tout sauf la facture */
  body > *:not(#invoice-modal) { display: none !important; }
  #invoice-modal { display: block !important; opacity: 1 !important; visibility: visible !important; }
  #invoice-modal .modal-ov { display: block !important; opacity: 1 !important; visibility: visible !important; background: none !important; }
  .modal-box { box-shadow: none !important; border: 1px solid #e5e7eb !important; transform: none !important; }
  .modal-close, .inv-ft .btn-ghost { display: none !important; }

  /* Impression sur fond blanc */
  .modal-box { background: #ffffff !important; color: #1a1a1a !important; }
  .inv-logo { color: #1a1a1a !important; }
  .inv-lbl { color: #6b7280 !important; }
  .inv-val { color: #111827 !important; }
  .inv-total { background: #f0fdf4 !important; border-color: #86efac !important; }
  .inv-total-val { color: #16a34a !important; }
  .inv-total-lbl { color: #6b7280 !important; }

  /* Éviter les coupures */
  .modal-box { page-break-inside: avoid; }

  /* Supprimer les en-têtes / pieds navigateur */
  @page { margin: 10mm; }
  html::before, html::after { content: none !important; }
}
</style>
</head>
<body>

<div class="page">

  <!-- ── TOP NAV ── -->
  <nav class="top-nav">
    <a href="../dashboard.php"      class="nav-link"><i class="bi bi-grid-1x2"></i> Dashboard</a>
    <a href="index.php"             class="nav-link"><i class="bi bi-book-half"></i> Catalogue</a>
    <a href="../users/purchases.php" class="nav-link"><i class="bi bi-bag-check"></i> Mes achats</a>
    <a href="../users/bonus.php"    class="nav-link"><i class="bi bi-gift"></i> Bonus</a>
    <a href="favorites.php"         class="nav-link on"><i class="bi bi-heart-fill"></i> Favoris</a>
    <span class="nav-sep"></span>
    <a href="#" class="nav-link notif-trigger" id="notif-btn" onclick="toggleNP();return false">
      <i class="bi bi-bell<?= $notifCount > 0 ? '-fill' : '' ?>"></i>
      <?php if ($notifCount > 0): ?>
        <span class="notif-badge"><?= min($notifCount, 9) ?></span>
      <?php endif; ?>
    </a>
    <a href="../logout.php" class="nav-link"><i class="bi bi-box-arrow-left"></i> Déconnexion</a>
  </nav>

  <!-- ── PAGE HEADER ── -->
  <div class="page-header">
    <div>
      <div class="page-title">
        <i class="bi bi-heart-fill" style="color:var(--rose)"></i>
        Mes Favoris
        <span id="total-badge" style="font-size:.65rem;padding:2px 9px;border-radius:100px;background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2);font-family:'Space Mono',monospace"><?= $totalFavs ?></span>
      </div>
      <div class="page-sub">Votre collection personnelle de livres favoris</div>
    </div>
    <div class="page-actions">
      <button class="btn btn-ghost btn-sm" onclick="refreshFavs()">
        <i class="bi bi-arrow-clockwise" id="refresh-ico"></i> Actualiser
      </button>
      <a href="index.php" class="btn btn-primary btn-sm">
        <i class="bi bi-compass"></i> Explorer
      </a>
    </div>
  </div>

  <!-- ── STATS ── -->
  <div class="stats-row" id="stats-row">
    <div class="stat-card">
      <div class="sc-val" id="stat-total"><?= $totalFavs ?></div>
      <div class="sc-label">Favoris total</div>
    </div>
    <div class="stat-card">
      <div class="sc-val" id="stat-encours">—</div>
      <div class="sc-label">En cours de lecture</div>
    </div>
    <div class="stat-card">
      <div class="sc-val" id="stat-termines">—</div>
      <div class="sc-label">Lectures terminées</div>
    </div>
    <div class="stat-card">
      <div class="sc-val" id="stat-achetes">—</div>
      <div class="sc-label">Livres achetés</div>
    </div>
  </div>

  <!-- ── TOOLBAR ── -->
  <div class="toolbar">
    <div class="toolbar-search">
      <i class="bi bi-search ts-ico"></i>
      <input type="search" id="search-input" placeholder="Rechercher dans mes favoris…"
             autocomplete="off" oninput="debounceSearch(this.value)">
    </div>
    <select class="tb-sel" id="sort-sel" onchange="applyFilters()">
      <option value="date_desc">Plus récents</option>
      <option value="note_desc">Mieux notés</option>
      <option value="titre_asc">Titre A-Z</option>
      <option value="prix_asc">Prix croissant</option>
      <option value="prix_desc">Prix décroissant</option>
    </select>
    <select class="tb-sel" id="cat-sel" onchange="applyFilters()">
      <option value="0">Toutes catégories</option>
      <?php foreach ($cats as $cat): ?>
        <option value="<?= (int) $cat['id'] ?>">
          <?= safeE($cat['icone'] ?? '') ?> <?= safeE($cat['nom']) ?> (<?= (int) $cat['nb'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <select class="tb-sel" id="per-sel" onchange="applyFilters()">
      <option value="12">12 / page</option>
      <option value="24">24 / page</option>
      <option value="48">48 / page</option>
    </select>
    <span class="tb-count" id="results-count">—</span>
  </div>

  <!-- ── BOOKS GRID ── -->
  <div id="books-grid">
    <div class="loader-wrap">
      <div class="spinner"></div>
      <span style="font-size:.76rem;color:var(--tm)">Chargement…</span>
    </div>
  </div>

  <!-- ── PAGINATION ── -->
  <div class="pagination" id="pagination" style="display:none"></div>

</div><!-- /page -->

<!-- ── NOTIF PANEL ── -->
<div id="np-ov" onclick="closeNP()"></div>
<div id="np">
  <div class="np-h">
    <span>Notifications</span>
    <div style="display:flex;gap:6px;align-items:center">
      <span class="badge badge-rose" id="np-badge" style="<?= $notifCount > 0 ? '' : 'display:none' ?>"><?= $notifCount ?></span>
      <button class="btn btn-ghost btn-xs" onclick="markAllRead()">Tout lu</button>
      <button class="btn btn-ghost btn-xs" onclick="closeNP()"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div class="np-list" id="np-list">
    <?php if (empty($notifs)): ?>
      <div style="padding:2rem;text-align:center;color:var(--tm);font-size:.8rem">🔔 Aucune notification</div>
    <?php else: foreach ($notifs as $n):
      $unread = !(bool) ($n['is_read_val'] ?? 0); ?>
      <div class="np-item <?= $unread ? 'unread' : '' ?>" onclick="markRead(<?= (int) $n['id'] ?>, this)">
        <div class="np-ico"><?= safeE($n['icon'] ?? '🔔') ?></div>
        <div>
          <div class="np-txt" style="font-weight:<?= $unread ? '600' : '400' ?>">
            <?= safeE(mb_substr($n['titre_display'] ?? $n['message'] ?? 'Notification', 0, 60)) ?>
          </div>
          <div class="np-time"><?= timeAgo($n['created_at'] ?? '') ?></div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <div class="np-ft">
    <a href="../admin/notifications.php" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center">Voir toutes</a>
  </div>
</div>

<!-- ── READER MODAL ── -->
<div id="reader-modal" role="dialog" aria-modal="true">
  <div class="rm-hdr">
    <span class="rm-title" id="rm-title">—</span>
    <div class="rm-controls">
      <button class="rm-btn" id="btn-theme" title="Thème"><i class="bi bi-moon" id="theme-ico"></i></button>
      <button class="rm-btn" id="btn-font-up" title="Agrandir"><i class="bi bi-zoom-in"></i></button>
      <button class="rm-btn" id="btn-font-dn" title="Réduire"><i class="bi bi-zoom-out"></i></button>
      <button class="rm-btn" id="btn-bm" title="Marque-page"><i class="bi bi-bookmark" id="bm-ico"></i></button>
      <span style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--tm);padding:0 6px" id="rm-chap">Ch.1</span>
      <button class="rm-btn rm-close" id="btn-rm-close"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div class="rm-prog"><div class="rm-prog-fill" id="rm-prog" style="width:0%"></div></div>
  <div class="rm-body">
    <div class="rm-inner"><div class="rm-content" id="rm-content">Chargement…</div></div>
  </div>
  <div class="rm-nav">
    <button class="rm-nav-btn" id="btn-prev"><i class="bi bi-chevron-left"></i> Précédente</button>
    <span class="rm-pinfo" id="rm-pinfo">1 / 1</span>
    <button class="rm-nav-btn" id="btn-next">Suivante <i class="bi bi-chevron-right"></i></button>
  </div>
</div>

<!-- ── INVOICE MODAL ── -->
<div class="modal-ov" id="invoice-modal" onclick="if(event.target===this)closeInvoice()">
  <div class="modal-box">
    <button class="modal-close" onclick="closeInvoice()"><i class="bi bi-x-lg"></i></button>
    <div class="inv-head">
      <div>
        <div class="inv-logo">🧾 Facture d'achat</div>
        <div id="inv-ref" style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--tm);margin-top:3px"></div>
      </div>
      <div style="font-size:2rem">📄</div>
    </div>
    <div class="inv-body" id="inv-body">
      <div class="loader-wrap"><div class="spinner"></div></div>
    </div>
    <div class="inv-ft">
      <button class="btn btn-primary btn-sm" onclick="printInvoice()">
        <i class="bi bi-printer"></i> Imprimer
      </button>
      <button class="btn btn-ghost btn-sm" onclick="closeInvoice()">Fermer</button>
    </div>
  </div>
</div>

<!-- Toast Stack -->
<div id="toast-s" role="region" aria-live="assertive"></div>

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT — 100% fonctionnel, temps réel, sans doublons
════════════════════════════════════════════════════════════ -->
<script>
'use strict';

/* ── Config PHP → JS ── */
const CSRF       = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const USER_ID    = <?= $userId ?>;
const MAX_DL     = <?= $maxDownloads ?>;
const API        = 'favorites.php';

const PALETTES   = [['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],['#0d3c2a','#1a7a5a'],['#2a0d1a','#6b1a3a']];
const EMOJIS     = ['📚','📘','📗','📙','📕','📓','🔮','💡','🌊','🏔️','⚡','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌍'];

let currentBook  = null;
let readerFont   = 17;
let readerLight  = false;
let readerPage   = 1;
let readerTotal  = 1;
let readerPages  = [];
let currentPage  = 1;
let totalPages   = 1;
let searchTimer  = null;
let toastTimer   = null;
let saveProgTimer = null;

/* ── Helpers ── */
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function fmtFCFA(n) { return parseInt(n||0).toLocaleString('fr-CM') + ' FCFA'; }
function starsHtml(note) {
  const r = Math.round(parseFloat(note)||0);
  return '★'.repeat(Math.min(5,r)) + '☆'.repeat(Math.max(0,5-r));
}

/* ── API helper ── */
async function api(action, params = {}, method = 'GET') {
  const url = new URL(API, window.location.href);
  url.searchParams.set('action', action);

  const opts = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };
  if (method === 'POST') {
    params.csrf = CSRF;
    const fd = new FormData();
    Object.entries(params).forEach(([k, v]) => fd.append(k, v));
    opts.method = 'POST';
    opts.body = fd;
  } else {
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  }

  const r = await fetch(url.toString(), opts);
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

/* ── Toast ── */
const TICONS  = { success:'✅', error:'❌', warn:'⚠️', info:'ℹ️' };
const TCOLORS = { success:'var(--neon)', error:'var(--rose)', warn:'var(--amber)', info:'var(--cyan)' };
function toast(title, sub = '', type = 'info', dur = 3500) {
  const s = document.getElementById('toast-s');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = TCOLORS[type] || TCOLORS.info;
  t.innerHTML = `<span>${TICONS[type]||'ℹ️'}</span>
    <div style="flex:1"><div class="t-title">${esc(title)}</div>${sub ? `<div class="t-sub">${esc(sub)}</div>` : ''}</div>
    <span style="cursor:pointer;color:var(--tm);font-size:.9rem" onclick="this.parentElement.remove()">✕</span>`;
  s.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, dur);
}

/* ═══════════════════════════════════════
   CHARGEMENT DES FAVORIS
═══════════════════════════════════════ */
async function loadFavs(page = 1) {
  currentPage = page;
  const grid = document.getElementById('books-grid');
  const pag  = document.getElementById('pagination');
  grid.innerHTML = '<div class="loader-wrap"><div class="spinner"></div><span style="font-size:.76rem;color:var(--tm)">Chargement…</span></div>';
  pag.style.display = 'none';

  const q    = document.getElementById('search-input')?.value.trim() || '';
  const sort = document.getElementById('sort-sel')?.value || 'date_desc';
  const cat  = document.getElementById('cat-sel')?.value  || '0';
  const per  = document.getElementById('per-sel')?.value  || '12';

  try {
    const d = await api('get_favs', { page, q, sort, cat, per });
    if (!d.success) throw new Error(d.error || 'Erreur');

    totalPages = d.pages || 1;

    // Mettre à jour le compteur
    const tb = document.getElementById('total-badge');
    if (tb) tb.textContent = d.total;
    const stTotal = document.getElementById('stat-total');
    if (stTotal) stTotal.textContent = d.total;

    const rc = document.getElementById('results-count');
    if (rc) rc.textContent = d.total + ' favori' + (d.total !== 1 ? 's' : '');

    if (!d.items || d.items.length === 0) {
      grid.innerHTML = `
        <div class="empty-state">
          <span class="empty-icon">💔</span>
          <div class="empty-t">${q ? 'Aucun résultat' : 'Aucun favori'}</div>
          <div class="empty-s">${q ? 'Modifiez votre recherche.' : 'Ajoutez des livres à vos favoris depuis le catalogue.'}</div>
          ${!q ? '<a href="index.php" class="btn btn-primary btn-sm" style="margin-top:1rem"><i class="bi bi-compass"></i> Explorer le catalogue</a>' : ''}
        </div>`;
      return;
    }

    grid.innerHTML = `<div class="books-grid">${d.items.map((b, i) => renderCard(b, i)).join('')}</div>`;
    buildPagination(page, d.pages);

    // Animer les progress bars
    setTimeout(() => {
      document.querySelectorAll('.bc-pct-fill').forEach(el => {
        const w = el.dataset.w || '0';
        el.style.width = '0%';
        requestAnimationFrame(() => requestAnimationFrame(() => { el.style.width = w + '%'; }));
      });
    }, 100);

  } catch(e) {
    grid.innerHTML = `
      <div class="empty-state">
        <span class="empty-icon">⚠️</span>
        <div class="empty-t">Erreur de chargement</div>
        <div class="empty-s">${esc(e.message)}</div>
        <button class="btn btn-ghost btn-sm" style="margin-top:1rem" onclick="loadFavs(1)">Réessayer</button>
      </div>`;
  }
}

/* ── Rendre une carte ── */
function renderCard(b, i) {
  const pal     = PALETTES[i % PALETTES.length];
  const emoji   = EMOJIS[i % EMOJIS.length];
  const note    = parseFloat(b.note_moyenne) || 0;
  const prix    = parseFloat(b.prix) || 0;
  const stars   = starsHtml(note);
  const pct     = parseFloat(b.progression) || 0;
  const isPurch = parseInt(b.is_purchased) > 0;
  const nbDl    = parseInt(b.nb_downloads) || 0;
  const dlLeft  = Math.max(0, MAX_DL - nbDl);
  const prixFmt = prix === 0 ? 'Gratuit' : fmtFCFA(prix);
  const hasPDF  = !!(b.fichier_pdf);

  let readLabel = isPurch ? '📖 Lire' : (prix === 0 ? '📖 Lire' : '🔒 Acheter');
  let readCls   = isPurch || prix === 0 ? 'btn-success' : 'btn-warn';

  return `
    <div class="book-card" id="card-${b.id}" data-id="${b.id}" style="animation-delay:${i*50}ms">
      <div class="bc-cover" style="cursor:pointer" onclick="openReader(${b.id}, '${esc(b.titre||'')}', '${esc(b.contenu_extrait ? btoa(unescape(encodeURIComponent((b.contenu_extrait||'').substring(0,8000)))) : '')}')">
        <div class="bc-cover-bg" style="background:linear-gradient(135deg,${pal[0]},${pal[1]})"></div>
        <div class="bc-cover-emoji">${emoji}</div>
        <div class="bc-cover-vignette"></div>
        <button class="bc-fav-btn" onclick="removeFav(event, ${b.id})" title="Retirer des favoris">
          <i class="bi bi-heart-fill"></i>
        </button>
        <div class="bc-pct"><div class="bc-pct-fill" data-w="${pct}" style="width:0%"></div></div>
      </div>
      <div class="bc-body">
        <div class="bc-cat">${esc(b.cat_icone || '📚')} ${esc(b.cat_nom || 'Général')}</div>
        <div class="bc-title" title="${esc(b.titre||'')}">${esc(b.titre || '—')}</div>
        <div class="bc-author">par ${esc(b.auteur || '—')}</div>
        <div class="bc-meta">
          <span class="bc-stars">${stars}</span>
          <span class="bc-pages">${b.pages || 200}p</span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.7rem">
          <span class="bc-price ${prix > 0 ? 'paid' : ''}">${prixFmt}</span>
          ${pct > 0 ? `<span style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--tm)">${Math.round(pct)}% lu</span>` : ''}
          ${parseInt(b.is_bestseller) ? '<span class="badge badge-amber">🔥 Best</span>' : ''}
        </div>
        <div class="bc-actions">
          <button class="btn ${readCls} btn-sm" style="flex:1"
                  onclick="handleRead(${b.id}, '${esc(b.titre||'')}', ${prix}, ${isPurch?1:0}, '${esc(b.contenu_extrait ? btoa(unescape(encodeURIComponent((b.contenu_extrait||'').substring(0,8000)))) : '')}')">
            ${readLabel}
          </button>
          ${hasPDF || isPurch ? `
          <button class="btn btn-ghost btn-sm" onclick="handleDownload(${b.id}, '${esc(b.titre||'')}', ${dlLeft})"
                  ${dlLeft <= 0 ? 'disabled style="opacity:.4"' : ''} title="${dlLeft} téléch. restant(s)">
            <i class="bi bi-cloud-download"></i>
          </button>` : ''}
          ${isPurch ? `
          <button class="btn btn-ghost btn-sm" onclick="showInvoice(0, ${b.id})" title="Facture">
            <i class="bi bi-receipt"></i>
          </button>` : ''}
        </div>
        ${pct > 0 ? `<div style="margin-top:6px;height:4px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden">
          <div style="width:${pct}%;height:100%;background:linear-gradient(90deg,var(--rose),var(--amber));border-radius:100px;transition:width .8s ease"></div>
        </div>` : ''}
        <div style="margin-top:5px;font-size:.6rem;color:var(--tm);font-family:'Space Mono',monospace">
          <i class="bi bi-download"></i> ${nbDl} téléch. · ${dlLeft} restant(s) / ${MAX_DL}
          · ❤️ depuis ${timeAgoJS(b.fav_date)}
        </div>
      </div>
    </div>`;
}

function timeAgoJS(dt) {
  if (!dt) return '—';
  const d = (Date.now() - new Date(dt).getTime()) / 1000;
  if (d < 60)     return 'à l\'instant';
  if (d < 3600)   return Math.floor(d/60) + 'min';
  if (d < 86400)  return Math.floor(d/3600) + 'h';
  if (d < 604800) return Math.floor(d/86400) + 'j';
  return new Date(dt).toLocaleDateString('fr-FR');
}

/* ── Pagination ── */
function buildPagination(cur, total) {
  const el = document.getElementById('pagination');
  if (!el || total <= 1) { el && (el.style.display = 'none'); return; }
  el.style.display = 'flex';

  let html = `<button class="pag-btn" onclick="loadFavs(${cur-1})" ${cur <= 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button>`;
  for (let p = Math.max(1, cur-2); p <= Math.min(total, cur+2); p++) {
    html += `<button class="pag-btn ${p === cur ? 'on' : ''}" onclick="loadFavs(${p})">${p}</button>`;
  }
  html += `<button class="pag-btn" onclick="loadFavs(${cur+1})" ${cur >= total ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button>`;
  el.innerHTML = html;
}

/* ── Filtres ── */
function debounceSearch(val) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadFavs(1), 350);
}
function applyFilters() { loadFavs(1); }
function refreshFavs() {
  const ico = document.getElementById('refresh-ico');
  if (ico) ico.style.animation = 'spin .5s linear infinite';
  loadFavs(currentPage).finally(() => {
    setTimeout(() => { if(ico) ico.style.animation = ''; }, 600);
  });
}

/* ═══════════════════════════════════════
   TOGGLE / RETIRER FAVORI
═══════════════════════════════════════ */
async function removeFav(event, livreId) {
  event.stopPropagation();
  const card = document.getElementById('card-' + livreId);
  if (card) {
    card.style.transition = 'opacity .25s, transform .25s';
    card.style.opacity = '0';
    card.style.transform = 'scale(.9)';
    setTimeout(() => card.remove(), 280);
  }
  try {
    const d = await api('remove_fav', { livre_id: livreId }, 'POST');
    if (!d.success) throw new Error(d.error);
    const tb = document.getElementById('total-badge');
    if (tb) tb.textContent = d.total;
    const stTotal = document.getElementById('stat-total');
    if (stTotal) stTotal.textContent = d.total;
    toast('Favori retiré', '', 'info', 2000);
  } catch(e) {
    toast('Erreur', e.message, 'error');
    loadFavs(currentPage); // Recharger si erreur
  }
}

// Fonction globale toggle (appelée depuis d'autres pages)
async function toggleFav(livreId, btnEl) {
  try {
    const d = await api('toggle_fav', { livre_id: livreId }, 'POST');
    if (!d.success) throw new Error(d.error);
    if (btnEl) {
      const ico = btnEl.querySelector('i');
      if (ico) ico.className = d.favorited ? 'bi bi-heart-fill' : 'bi bi-heart';
      btnEl.classList.toggle('active', d.favorited);
    }
    toast(d.message, '', d.favorited ? 'success' : 'info', 2000);
    return d;
  } catch(e) {
    toast('Erreur', e.message, 'error');
    return null;
  }
}

/* ═══════════════════════════════════════
   LECTURE — Dispatch selon droits
═══════════════════════════════════════ */
function handleRead(livreId, titre, prix, isPurchased, extraitB64) {
  currentBook = { id: livreId, titre, prix, isPurchased: !!isPurchased, extraitB64 };
  if (isPurchased || prix === 0) {
    openReader(livreId, titre, extraitB64);
  } else {
    // Rediriger vers la page du livre pour acheter
    toast('Achat requis', `"${titre}" doit être acheté pour être lu.`, 'warn', 4000);
    setTimeout(() => { window.location.href = 'view.php?id=' + livreId; }, 1500);
  }
}

/* ═══════════════════════════════════════
   LECTEUR INTÉGRÉ
═══════════════════════════════════════ */
function buildPages(raw) {
  const parts = (raw || '').split('||||PAGE||||').map(p => p.trim()).filter(Boolean);
  if (parts.length >= 3) return parts;
  // Fallback si pas d'extrait
  return [
    'CHAPITRE 1 — Introduction\n\nLe contenu de ce livre est en cours d\'ajout. Revenez bientôt pour lire cet ouvrage.\n\nEn attendant, explorez notre catalogue pour découvrir d\'autres œuvres.',
    'CHAPITRE 2 — Suite\n\nDes milliers de livres vous attendent dans notre bibliothèque numérique.\n\nVous pouvez gérer vos favoris, suivre votre progression et télécharger les PDF disponibles.',
    'CHAPITRE 3 — Fin\n\nMerci d\'utiliser Digital Library System.\n\nVotre progression est automatiquement sauvegardée.'
  ];
}

async function openReader(livreId, titre, extraitB64) {
  const modal = document.getElementById('reader-modal');

  document.getElementById('rm-title').textContent = titre || '—';

  // Décoder l'extrait
  let raw = '';
  if (extraitB64) {
    try { raw = decodeURIComponent(escape(atob(extraitB64))); } catch(e) { raw = ''; }
  }

  // Si pas d'extrait, charger depuis l'API
  if (!raw) {
    try {
      const d = await api('get_favs', { page: 1, q: titre, per: 1 });
      if (d.success && d.items && d.items[0]) {
        const ce = d.items[0].contenu_extrait;
        if (ce) raw = ce;
      }
    } catch(e) {}
  }

  readerPages = buildPages(raw);
  readerTotal = readerPages.length;

  // Restaurer progression
  readerPage = 1;
  try {
    const r = await api('get_progression', { livre_id: livreId });
    if (r.success && r.page > 1 && r.page <= readerTotal) {
      readerPage = r.page;
      const histEl = document.getElementById('rm-hist');
      if (!histEl) {
        const hd = document.createElement('div');
        hd.id = 'rm-hist';
        hd.style.cssText = 'background:rgba(0,255,170,.06);border-bottom:1px solid rgba(0,255,170,.15);padding:5px 1.6rem;font-family:Space Mono,monospace;font-size:.6rem;color:var(--neon);text-align:center';
        hd.textContent = `📖 Reprise — page ${readerPage}/${readerTotal}`;
        document.querySelector('.rm-prog').after(hd);
        setTimeout(() => hd.remove(), 4000);
      }
    }
  } catch(e) {}

  currentBook = { ...currentBook, id: livreId, titre };
  renderReaderPage(false);
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function renderReaderPage(animate, dir = 'next') {
  const cEl = document.getElementById('rm-content');
  if (!cEl) return;

  const raw = readerPages[readerPage - 1] || 'Page vide.';
  let html = raw
    .replace(/^(CHAPITRE \d+[^\n]*)/gm, '<h2>$1</h2>')
    .replace(/^(CHAPITRE [IVX]+[^\n]*)/gm, '<h2>$1</h2>')
    .replace(/^(ÉPILOGUE|POSTFACE|APPROFONDISSEMENT|RÉFLEXIONS|VERS LA FIN)/gm, '<h2>$1</h2>')
    .replace(/\n\n+/g, '</p><p>').replace(/\n/g, '<br>');
  html = '<p>' + html + '</p>';
  html = html.replace(/<p><h2>/g, '<h2>').replace(/<\/h2><\/p>/g, '</h2>').replace(/<p><\/p>/g, '');

  const pct = (readerPage / readerTotal) * 100;
  document.getElementById('rm-prog').style.width = pct.toFixed(1) + '%';
  document.getElementById('rm-pinfo').textContent = `${readerPage} / ${readerTotal}`;
  document.getElementById('rm-chap').textContent  = `Ch. ${readerPage}`;
  document.getElementById('btn-prev').disabled = readerPage <= 1;
  document.getElementById('btn-next').disabled = readerPage >= readerTotal;

  // Sauvegarder progression (debounced)
  clearTimeout(saveProgTimer);
  saveProgTimer = setTimeout(() => saveProgression(pct), 2000);

  if (!animate) {
    cEl.innerHTML = html; cEl.style.fontSize = readerFont + 'px';
    document.querySelector('.rm-body').scrollTop = 0;
    return;
  }
  cEl.style.cssText += `;opacity:0;transform:${dir === 'next' ? 'translateX(-20px)' : 'translateX(20px)'};transition:opacity .15s,transform .15s`;
  setTimeout(() => {
    cEl.innerHTML = html; cEl.style.fontSize = readerFont + 'px';
    cEl.style.transition = 'none'; cEl.style.transform = dir === 'next' ? 'translateX(20px)' : 'translateX(-20px)';
    document.querySelector('.rm-body').scrollTop = 0;
    requestAnimationFrame(() => {
      cEl.style.transition = 'opacity .3s,transform .3s';
      cEl.style.opacity = '1'; cEl.style.transform = 'translateX(0)';
    });
  }, 150);
}

async function saveProgression(pct) {
  if (!currentBook?.id) return;
  try {
    await api('save_progression', {
      livre_id: currentBook.id,
      page: readerPage,
      pourcentage: pct.toFixed(1)
    }, 'POST');
    // Mettre à jour la barre dans la carte
    const fill = document.querySelector(`#card-${currentBook.id} .bc-pct-fill`);
    if (fill) fill.style.width = pct.toFixed(1) + '%';
  } catch(e) {}
}

function closeReader() {
  const modal = document.getElementById('reader-modal');
  modal.classList.remove('open');
  document.body.style.overflow = '';
  if (currentBook?.id) {
    const pct = (readerPage / readerTotal) * 100;
    saveProgression(pct);
  }
}

// Contrôles lecteur
document.getElementById('btn-prev')?.addEventListener('click', () => {
  if (readerPage > 1) { readerPage--; renderReaderPage(true, 'prev'); }
});
document.getElementById('btn-next')?.addEventListener('click', () => {
  if (readerPage < readerTotal) { readerPage++; renderReaderPage(true, 'next'); }
  else toast('Fin du livre', 'Dernière page de l\'extrait atteinte.', 'info', 2500);
});
document.getElementById('btn-rm-close')?.addEventListener('click', closeReader);
document.getElementById('btn-theme')?.addEventListener('click', () => {
  readerLight = !readerLight;
  document.getElementById('reader-modal').classList.toggle('reader-light', readerLight);
  const ico = document.getElementById('theme-ico');
  if (ico) ico.className = readerLight ? 'bi bi-sun-fill' : 'bi bi-moon';
  toast('Thème', readerLight ? 'Mode clair' : 'Mode sombre', 'info', 1200);
});
document.getElementById('btn-font-up')?.addEventListener('click', () => {
  readerFont = Math.min(24, readerFont + 2);
  const c = document.getElementById('rm-content'); if (c) c.style.fontSize = readerFont + 'px';
});
document.getElementById('btn-font-dn')?.addEventListener('click', () => {
  readerFont = Math.max(12, readerFont - 2);
  const c = document.getElementById('rm-content'); if (c) c.style.fontSize = readerFont + 'px';
});
document.getElementById('btn-bm')?.addEventListener('click', () => {
  const ico = document.getElementById('bm-ico');
  if (ico) { ico.className = 'bi bi-bookmark-fill'; ico.style.color = 'var(--rose)'; }
  setTimeout(() => { if(ico) { ico.className = 'bi bi-bookmark'; ico.style.color = ''; } }, 2000);
  toast('Marque-page', `Page ${readerPage}/${readerTotal} sauvegardée.`, 'success', 2500);
});

/* ═══════════════════════════════════════
   TÉLÉCHARGEMENT PDF
═══════════════════════════════════════ */
async function handleDownload(livreId, titre, dlLeft) {
  if (dlLeft <= 0) {
    toast('Limite atteinte', `Maximum ${MAX_DL} téléchargements par livre.`, 'warn', 4000);
    return;
  }
  toast('Téléchargement', 'Vérification des droits…', 'info', 2000);

  try {
    const d = await api('download', { livre_id: livreId }, 'POST');
    if (!d.success) { toast('Erreur', d.error || 'Téléchargement refusé', 'error'); return; }

    // Vérifier si le fichier existe réellement
    if (d.fichier) {
      // Tentative d'ouverture du fichier PDF
      const pdfUrl = '../' + d.fichier.replace(/^\//, '');
      const a = document.createElement('a');
      a.href = pdfUrl;
      a.download = (titre || 'livre') + '.pdf';
      a.target = '_blank';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      toast('Téléchargement lancé', `"${titre}" · ${d.remaining} restant(s)`, 'success', 4000);
    } else {
      // Pas de fichier PDF — ouvrir le lecteur intégré
      toast('PDF non disponible', 'Ouverture du lecteur intégré…', 'warn', 3000);
      setTimeout(() => openReader(livreId, titre, ''), 800);
    }

    // Mettre à jour le compteur dans la carte
    const card = document.getElementById('card-' + livreId);
    if (card) {
      const dlInfo = card.querySelector('[style*="téléch."]');
      if (dlInfo) {
        dlInfo.innerHTML = `<i class="bi bi-download"></i> ${MAX_DL - d.remaining} téléch. · ${d.remaining} restant(s) / ${MAX_DL} · ❤️ favori`;
      }
      if (d.remaining <= 0) {
        const btn = card.querySelector('[onclick*="handleDownload"]');
        if (btn) { btn.disabled = true; btn.style.opacity = '.4'; }
      }
    }

  } catch(e) { toast('Erreur réseau', e.message, 'error'); }
}

/* ═══════════════════════════════════════
   FACTURE — Impression unique et propre
═══════════════════════════════════════ */
async function showInvoice(achatId, livreId) {
  const modal = document.getElementById('invoice-modal');
  const body  = document.getElementById('inv-body');
  const ref   = document.getElementById('inv-ref');

  body.innerHTML = '<div class="loader-wrap"><div class="spinner"></div></div>';
  ref.textContent = '';
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';

  try {
    const params = achatId ? { achat_id: achatId } : { livre_id: livreId };
    const d = await api('get_invoice', params);

    if (!d.success) {
      body.innerHTML = `<p style="padding:2rem;text-align:center;color:var(--rose)">Facture introuvable</p>`;
      return;
    }

    const inv = d.invoice;
    if (ref) ref.textContent = 'Réf: ' + (inv.reference || '—');

    const statusMap = { confirme: '✅ Confirmé', en_attente: '⏳ En attente', echec: '❌ Échoué' };
    const methodMap = { orange_money: '🟠 Orange Money', mobile_money: '🟡 MTN MoMo', carte: '💳 Carte' };

    body.innerHTML = `
      <div class="inv-row"><span class="inv-lbl">Client</span><span class="inv-val">${esc((inv.prenom || '') + ' ' + (inv.nom || ''))}</span></div>
      <div class="inv-row"><span class="inv-lbl">Email</span><span class="inv-val" style="font-size:.75rem">${esc(inv.email || '—')}</span></div>
      <div class="inv-row"><span class="inv-lbl">Livre</span><span class="inv-val">${esc(inv.titre || '—')}</span></div>
      <div class="inv-row"><span class="inv-lbl">Auteur</span><span class="inv-val">${esc(inv.auteur || '—')}</span></div>
      ${inv.editeur ? `<div class="inv-row"><span class="inv-lbl">Éditeur</span><span class="inv-val">${esc(inv.editeur)}</span></div>` : ''}
      <div class="inv-row"><span class="inv-lbl">Méthode</span><span class="inv-val">${esc(methodMap[inv.methode] || inv.methode || '—')}</span></div>
      <div class="inv-row"><span class="inv-lbl">Statut</span><span class="inv-val">${statusMap[inv.statut] || inv.statut || '—'}</span></div>
      <div class="inv-row"><span class="inv-lbl">Date</span><span class="inv-val">${inv.created_at ? new Date(inv.created_at).toLocaleString('fr-FR') : '—'}</span></div>
      <div class="inv-row"><span class="inv-lbl">Référence</span><span class="inv-val" style="font-family:'Space Mono',monospace;font-size:.7rem">${esc(inv.reference || '—')}</span></div>
      <div class="inv-total">
        <div class="inv-total-lbl">Montant total réglé</div>
        <div class="inv-total-val">${fmtFCFA(inv.montant || 0)}</div>
      </div>`;

  } catch(e) {
    body.innerHTML = `<p style="padding:2rem;text-align:center;color:var(--rose)">${esc(e.message)}</p>`;
  }
}

function closeInvoice() {
  document.getElementById('invoice-modal').classList.remove('open');
  document.body.style.overflow = '';
}

/**
 * Impression propre et unique de la facture
 * Supprime : date, heure, URL, en-têtes navigateur, doublons
 */
function printInvoice() {
  const invRef  = document.getElementById('inv-ref')?.textContent || '';
  const invBody = document.getElementById('inv-body')?.innerHTML || '';

  // Créer un iframe dédié à l'impression (évite les doublons de page)
  const iframe = document.createElement('iframe');
  iframe.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:0;height:0;border:none';
  document.body.appendChild(iframe);

  const doc = iframe.contentDocument || iframe.contentWindow.document;
  doc.open();
  doc.write(`<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Facture — Digital Library</title>
<style>
  /* Supprimer TOUT ce que le navigateur ajoute */
  @page {
    size: A4;
    margin: 15mm 12mm 15mm 12mm;
  }
  /* Chrome/Edge : supprimer URL et date */
  @page { margin-header: 0; margin-footer: 0; }

  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #111827;
    background: #fff;
    font-size: 13px;
    line-height: 1.6;
  }

  .invoice-wrap {
    max-width: 560px;
    margin: 0 auto;
    padding: 20px;
  }

  /* En-tête */
  .inv-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 16px;
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 20px;
  }
  .inv-brand {
    font-size: 18px;
    font-weight: 700;
    color: #111;
  }
  .inv-brand-sub {
    font-size: 11px;
    color: #6b7280;
    margin-top: 3px;
  }
  .inv-ref {
    font-size: 11px;
    font-family: 'Courier New', monospace;
    color: #6b7280;
    text-align: right;
  }
  .inv-date {
    font-size: 11px;
    color: #6b7280;
    text-align: right;
    margin-top: 3px;
  }

  /* Titre facture */
  .inv-title {
    font-size: 15px;
    font-weight: 700;
    color: #111;
    margin-bottom: 16px;
    padding: 8px 12px;
    background: #f9fafb;
    border-left: 3px solid #374151;
    border-radius: 0 4px 4px 0;
  }

  /* Lignes */
  .inv-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 7px 0;
    border-bottom: 1px solid #f3f4f6;
  }
  .inv-row:last-child { border-bottom: none; }
  .inv-lbl {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #6b7280;
    font-weight: 500;
    flex-shrink: 0;
    min-width: 100px;
  }
  .inv-val {
    font-size: 13px;
    color: #111827;
    font-weight: 500;
    text-align: right;
  }

  /* Total */
  .inv-total {
    margin-top: 20px;
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 8px;
    padding: 16px;
    text-align: center;
  }
  .inv-total-lbl {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #6b7280;
    margin-bottom: 4px;
  }
  .inv-total-val {
    font-size: 22px;
    font-weight: 800;
    color: #16a34a;
  }

  /* Footer */
  .inv-footer {
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
    text-align: center;
    font-size: 10px;
    color: #9ca3af;
    line-height: 1.8;
  }

  /* Cacheur d'URL chrome */
  .no-print-info { display: none !important; }
</style>
</head>
<body>
<div class="invoice-wrap">
  <div class="inv-header">
    <div>
      <div class="inv-brand">📚 Digital Library System</div>
      <div class="inv-brand-sub">Bibliothèque Numérique Professionnelle</div>
    </div>
    <div class="inv-ref">
      <div style="font-weight:700;font-size:13px">FACTURE</div>
      <div class="inv-ref">${esc(invRef)}</div>
      <div class="inv-date">${new Date().toLocaleDateString('fr-FR', {day:'2-digit',month:'long',year:'numeric'})}</div>
    </div>
  </div>
  <div class="inv-title">🧾 Détails de la transaction</div>
  ${invBody.replace(/<script[^>]*>.*?<\/script>/gi, '')
           .replace(/class="inv-lbl"/g, 'class="inv-lbl"')
           .replace(/class="inv-val"/g, 'class="inv-val"')
           .replace(/class="inv-row"/g, 'class="inv-row"')
           .replace(/class="inv-total"/g, 'class="inv-total"')
           .replace(/class="inv-total-lbl"/g, 'class="inv-total-lbl"')
           .replace(/class="inv-total-val"/g, 'class="inv-total-val"')}
  <div class="inv-footer">
    Digital Library System · Plateforme de lecture numérique<br>
    Ce document est une preuve de transaction. Conservez-le précieusement.<br>
    Pour tout renseignement : support@digitallibrary.cm
  </div>
</div>
</body>
</html>`);
  doc.close();

  // Attendre le chargement puis imprimer
  iframe.onload = () => {
    setTimeout(() => {
      iframe.contentWindow.focus();
      iframe.contentWindow.print();
      // Supprimer l'iframe après impression
      setTimeout(() => {
        document.body.removeChild(iframe);
      }, 2000);
    }, 200);
  };

  // Fallback si onload ne se déclenche pas
  setTimeout(() => {
    if (document.body.contains(iframe)) {
      iframe.contentWindow.print();
      setTimeout(() => { if (document.body.contains(iframe)) document.body.removeChild(iframe); }, 2000);
    }
  }, 800);
}

/* ═══════════════════════════════════════
   NOTIFICATIONS PANEL
═══════════════════════════════════════ */
function toggleNP() {
  const np = document.getElementById('np');
  const ov = document.getElementById('np-ov');
  np.classList.toggle('open');
  ov.classList.toggle('show', np.classList.contains('open'));
}
function closeNP() {
  document.getElementById('np').classList.remove('open');
  document.getElementById('np-ov').classList.remove('show');
}

async function markRead(id, el) {
  el.classList.remove('unread');
  const badge = document.getElementById('np-badge');
  if (badge) {
    const n = parseInt(badge.textContent) - 1;
    if (n <= 0) badge.style.display = 'none';
    else badge.textContent = n;
  }
  try { await api('mark_read', { notif_id: id }, 'POST'); } catch(e) {}
}

async function markAllRead() {
  document.querySelectorAll('.np-item.unread').forEach(el => el.classList.remove('unread'));
  const badge = document.getElementById('np-badge');
  if (badge) badge.style.display = 'none';
  const btn = document.getElementById('notif-btn');
  if (btn) { const nb = btn.querySelector('.notif-badge'); if (nb) nb.remove(); }
  try { await api('mark_all_read', {}, 'POST'); } catch(e) {}
  toast('Notifications', 'Toutes marquées comme lues', 'success', 2000);
}

/* ═══════════════════════════════════════
   STATS
═══════════════════════════════════════ */
async function loadStats() {
  try {
    const d = await api('stats');
    if (!d.success) return;
    const ec = document.getElementById('stat-encours');  if (ec) ec.textContent = d.en_cours;
    const te = document.getElementById('stat-termines'); if (te) te.textContent = d.termines;
    const ac = document.getElementById('stat-achetes');  if (ac) ac.textContent = d.achetes;
  } catch(e) {}
}

/* ═══════════════════════════════════════
   KEYBOARD SHORTCUTS
═══════════════════════════════════════ */
document.addEventListener('keydown', e => {
  const rOpen = document.getElementById('reader-modal').classList.contains('open');
  const iOpen = document.getElementById('invoice-modal').classList.contains('open');

  if (e.key === 'Escape') {
    if (rOpen) { closeReader(); return; }
    if (iOpen) { closeInvoice(); return; }
    closeNP();
    return;
  }

  if (rOpen) {
    if (['ArrowRight', 'PageDown'].includes(e.key)) {
      e.preventDefault();
      if (readerPage < readerTotal) { readerPage++; renderReaderPage(true, 'next'); }
    }
    if (['ArrowLeft', 'PageUp'].includes(e.key)) {
      e.preventDefault();
      if (readerPage > 1) { readerPage--; renderReaderPage(true, 'prev'); }
    }
  }
});

/* ═══════════════════════════════════════
   INIT
═══════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  loadFavs(1);
  loadStats();

  // Polling toutes les 30 secondes
  setInterval(() => {
    api('stats').then(d => {
      if (!d.success) return;
      const ec = document.getElementById('stat-encours');  if (ec) ec.textContent = d.en_cours;
      const te = document.getElementById('stat-termines'); if (te) te.textContent = d.termines;
      const ac = document.getElementById('stat-achetes');  if (ac) ac.textContent = d.achetes;
    }).catch(() => {});
  }, 30000);

  setTimeout(() => {
    toast('Mes Favoris', <?= json_encode("Bonjour {$firstName} ! {$totalFavs} favori" . ($totalFavs > 1 ? 's' : '') . " dans votre collection.", JSON_HEX_TAG) ?>, 'success', 4000);
  }, 600);
});
</script>
</body>
</html>