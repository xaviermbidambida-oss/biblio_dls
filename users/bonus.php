<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — Mes Bonus v1.0                        ║
 * ║  users/bonus.php                                                 ║
 * ║  Espace fidélité · Système bonus · Livres gratuits               ║
 * ║  ✅ PDO sécurisé · AJAX intégré · CSRF protégé · Temps réel     ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// ── Session ──────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Connexion PDO ─────────────────────────────────────────────────────
$pdo = null;
foreach ([
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/includes/config.php',
] as $_cfgPath) {
    if (file_exists($_cfgPath) && !defined('DLS_CFG_LOADED')) {
        require_once $_cfgPath;
        define('DLS_CFG_LOADED', true);
        break;
    }
}
if (!isset($pdo) || $pdo === null) {
    $_h = defined('DB_HOST') ? DB_HOST : 'localhost';
    $_n = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $_u = defined('DB_USER') ? DB_USER : 'root';
    $_p = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$_h};dbname={$_n};charset=utf8mb4",
            $_u, $_p,
            [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES   => false]
        );
    } catch (PDOException $e) {
        error_log('[DLS-Bonus] PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth guard ────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=users/bonus.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';
$username = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$firstName = htmlspecialchars(explode(' ', trim($username))[0] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$avatar   = strtoupper(substr($username, 0, 1)) ?: 'U';

// ── Helpers ───────────────────────────────────────────────────────────
function fmtFCFA(float $n): string { return number_format($n, 0, ',', ' ') . ' FCFA'; }
function safeE(string $s): string  { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function timeAgo(string $d): string {
    if (!$d) return '—';
    $diff = time() - strtotime($d);
    if ($diff < 60)     return 'À l\'instant';
    if ($diff < 3600)   return (int)($diff/60) . ' min';
    if ($diff < 86400)  return (int)($diff/3600) . 'h';
    if ($diff < 604800) return (int)($diff/86400) . 'j';
    return date('d/m/Y', strtotime($d));
}
function csrf(): string {
    if (empty($_SESSION['csrf_bonus'])) $_SESSION['csrf_bonus'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_bonus'];
}

// ── Auto-création tables manquantes ───────────────────────────────────
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_bonus (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL UNIQUE,
            achat_count INT UNSIGNED NOT NULL DEFAULT 0,
            bonus_total INT UNSIGNED NOT NULL DEFAULT 0,
            bonus_restant INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS bonus_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED,
            type ENUM('gagne','utilise','expire') NOT NULL DEFAULT 'gagne',
            detail VARCHAR(255),
            bonus_avant INT UNSIGNED DEFAULT 0,
            bonus_apres INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            type VARCHAR(50),
            titre VARCHAR(255),
            message TEXT,
            icon VARCHAR(10) DEFAULT '🔔',
            lu TINYINT DEFAULT 0,
            is_read TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // S'assurer que user_bonus existe pour cet utilisateur
        $pdo->prepare("INSERT IGNORE INTO user_bonus (user_id) VALUES (?)")->execute([$userId]);

    } catch (Throwable $e) {
        error_log('[DLS-Bonus] Schema: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════
// AJAX HANDLER ── Toutes les actions en un seul endpoint
// ══════════════════════════════════════════════════════════════════════
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $csrf   = $_POST['csrf'] ?? '';

    if (!hash_equals($_SESSION['csrf_bonus'] ?? '', $csrf)) {
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']); exit;
    }
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Base de données inaccessible']); exit;
    }

    try {
        switch ($action) {

            // ── Réclamer un livre bonus ──────────────────────────────
            case 'claim_bonus':
                $livreId = (int)($_POST['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('ID du livre manquant');

                $pdo->beginTransaction();

                // Vérifier bonus disponible
                $bonusSt = $pdo->prepare("SELECT * FROM user_bonus WHERE user_id=? FOR UPDATE");
                $bonusSt->execute([$userId]);
                $bonus = $bonusSt->fetch();
                if (!$bonus || (int)$bonus['bonus_restant'] < 1) {
                    $pdo->rollBack();
                    throw new Exception('Vous n\'avez aucun bonus disponible.');
                }

                // Vérifier le livre
                $livreSt = $pdo->prepare("SELECT * FROM livres WHERE id=? AND statut='disponible'");
                $livreSt->execute([$livreId]);
                $livre = $livreSt->fetch();
                if (!$livre) {
                    $pdo->rollBack();
                    throw new Exception('Ce livre n\'est pas disponible.');
                }

                // Vérifier déjà acheté
                $dejaSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme'"
                );
                $dejaSt->execute([$userId, $livreId]);
                if ($dejaSt->fetchColumn() > 0) {
                    $pdo->rollBack();
                    throw new Exception('Ce livre est déjà dans votre bibliothèque.');
                }

                // Vérifier déjà reçu en bonus
                $dejaBonusSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM bonus_history WHERE user_id=? AND livre_id=? AND type='utilise'"
                );
                $dejaBonusSt->execute([$userId, $livreId]);
                if ($dejaBonusSt->fetchColumn() > 0) {
                    $pdo->rollBack();
                    throw new Exception('Vous avez déjà obtenu ce livre via un bonus.');
                }

                // Créer l'achat gratuit
                $ref = 'BONUS-' . strtoupper(base_convert(time(), 10, 36)) . '-' . strtoupper(substr(uniqid(), -4));
                $pdo->prepare(
                    "INSERT INTO achats (user_id, livre_id, montant, methode, statut, reference)
                     VALUES (?, ?, 0, 'orange_money', 'confirme', ?)"
                )->execute([$userId, $livreId, $ref]);

                // Décrémenter le bonus
                $avant = (int)$bonus['bonus_restant'];
                $pdo->prepare(
                    "UPDATE user_bonus SET bonus_restant=bonus_restant-1 WHERE user_id=?"
                )->execute([$userId]);

                // Historique
                $pdo->prepare(
                    "INSERT INTO bonus_history (user_id, livre_id, type, detail, bonus_avant, bonus_apres)
                     VALUES (?, ?, 'utilise', ?, ?, ?)"
                )->execute([$userId, $livreId, 'Livre obtenu via bonus fidélité', $avant, $avant - 1]);

                // Notification
                $pdo->prepare(
                    "INSERT INTO notifications (user_id, type, titre, message, icon)
                     VALUES (?, 'bonus_utilise', '🎁 Livre bonus obtenu !', ?, '🎁')"
                )->execute([$userId, 'Vous avez obtenu «' . ($livre['titre'] ?? '') . '» gratuitement via votre bonus fidélité.']);

                $pdo->commit();

                // Récupérer le nouveau solde
                $newBonus = $pdo->prepare("SELECT bonus_restant, bonus_total, achat_count FROM user_bonus WHERE user_id=?");
                $newBonus->execute([$userId]);
                $nb = $newBonus->fetch();

                echo json_encode([
                    'success'       => true,
                    'msg'           => '🎉 «' . ($livre['titre'] ?? '') . '» ajouté à votre bibliothèque !',
                    'bonus_restant' => (int)($nb['bonus_restant'] ?? 0),
                    'bonus_total'   => (int)($nb['bonus_total'] ?? 0),
                ]);
                break;

            // ── Récupérer les livres éligibles ──────────────────────
            case 'get_eligible_books':
                $page  = max(1, (int)($_POST['page'] ?? 1));
                $limit = 12;
                $offset= ($page - 1) * $limit;
                $q     = trim($_POST['q'] ?? '');
                $cat   = (int)($_POST['cat'] ?? 0);
                $sort  = $_POST['sort'] ?? 'note';

                $where  = ["l.statut='disponible'"];
                $params = [];

                // Exclure livres déjà possédés
                $where[] = "l.id NOT IN (SELECT livre_id FROM achats WHERE user_id=? AND statut='confirme')";
                $params[] = $userId;

                // Exclure livres déjà reçus en bonus
                $where[] = "l.id NOT IN (SELECT livre_id FROM bonus_history WHERE user_id=? AND type='utilise' AND livre_id IS NOT NULL)";
                $params[] = $userId;

                if ($q) {
                    $where[] = "(l.titre LIKE ? OR l.auteur LIKE ?)";
                    $params[] = "%$q%";
                    $params[] = "%$q%";
                }
                if ($cat > 0) {
                    $where[] = "l.categorie_id=?";
                    $params[] = $cat;
                }

                $whereSQL = 'WHERE ' . implode(' AND ', $where);
                $orderSQL = match($sort) {
                    'prix_h'  => 'ORDER BY l.prix DESC',
                    'prix_b'  => 'ORDER BY l.prix ASC',
                    'titre'   => 'ORDER BY l.titre ASC',
                    'recent'  => 'ORDER BY l.created_at DESC',
                    default   => 'ORDER BY l.note_moyenne DESC',
                };

                // Count
                $countSt = $pdo->prepare("SELECT COUNT(*) FROM livres l $whereSQL");
                $countSt->execute($params);
                $total = (int)$countSt->fetchColumn();

                // Fetch
                $bookSt = $pdo->prepare(
                    "SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.pages,
                            l.annee_parution, l.is_featured, l.is_bestseller,
                            c.nom AS cat_nom, c.icone AS cat_icone
                     FROM livres l
                     LEFT JOIN categories c ON c.id=l.categorie_id
                     $whereSQL
                     $orderSQL
                     LIMIT ? OFFSET ?"
                );
                $bookSt->execute(array_merge($params, [$limit, $offset]));
                $books = $bookSt->fetchAll();

                // Vérifier si l'utilisateur possède TOUS les livres
                $totalLivres = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
                $possedeLivres = (int)$pdo->prepare("SELECT COUNT(*) FROM achats WHERE user_id=? AND statut='confirme'")->execute([$userId]) ? 0 : 0;
                $stPos = $pdo->prepare("SELECT COUNT(DISTINCT livre_id) FROM achats WHERE user_id=? AND statut='confirme'");
                $stPos->execute([$userId]);
                $possedeLivres = (int)$stPos->fetchColumn();

                echo json_encode([
                    'success'         => true,
                    'books'           => $books,
                    'total'           => $total,
                    'pages'           => max(1, ceil($total / $limit)),
                    'all_possessed'   => ($possedeLivres >= $totalLivres && $totalLivres > 0),
                ]);
                break;

            // ── Historique des bonus ─────────────────────────────────
            case 'get_history':
                $page   = max(1, (int)($_POST['page'] ?? 1));
                $limit  = 10;
                $offset = ($page - 1) * $limit;

                $histSt = $pdo->prepare(
                    "SELECT bh.id, bh.type, bh.detail, bh.bonus_avant, bh.bonus_apres, bh.created_at,
                            l.id AS livre_id, l.titre, l.auteur, l.prix, l.note_moyenne,
                            c.nom AS cat_nom, c.icone AS cat_icone
                     FROM bonus_history bh
                     LEFT JOIN livres l ON l.id=bh.livre_id
                     LEFT JOIN categories c ON c.id=l.categorie_id
                     WHERE bh.user_id=?
                     ORDER BY bh.created_at DESC
                     LIMIT ? OFFSET ?"
                );
                $histSt->execute([$userId, $limit, $offset]);
                $history = $histSt->fetchAll();

                $countSt = $pdo->prepare("SELECT COUNT(*) FROM bonus_history WHERE user_id=?");
                $countSt->execute([$userId]);
                $total = (int)$countSt->fetchColumn();

                echo json_encode([
                    'success' => true,
                    'history' => $history,
                    'total'   => $total,
                    'pages'   => max(1, ceil($total / $limit)),
                ]);
                break;

            // ── Livres obtenus via bonus ─────────────────────────────
            case 'get_bonus_books':
                $bonusBooksSt = $pdo->prepare(
                    "SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.pages,
                            l.fichier_pdf, l.annee_parution,
                            c.nom AS cat_nom, c.icone AS cat_icone,
                            bh.created_at AS date_obtenu,
                            lp.pourcentage, lp.page_actuelle,
                            ud.count AS nb_dl
                     FROM bonus_history bh
                     JOIN livres l ON l.id=bh.livre_id
                     LEFT JOIN categories c ON c.id=l.categorie_id
                     LEFT JOIN lecture_progression lp ON lp.user_id=bh.user_id AND lp.livre_id=l.id
                     LEFT JOIN user_downloads ud ON ud.user_id=bh.user_id AND ud.livre_id=l.id
                     WHERE bh.user_id=? AND bh.type='utilise' AND bh.livre_id IS NOT NULL
                     ORDER BY bh.created_at DESC"
                );
                $bonusBooksSt->execute([$userId]);
                echo json_encode(['success' => true, 'books' => $bonusBooksSt->fetchAll()]);
                break;

            // ── Statistiques fidélité ────────────────────────────────
            case 'get_stats':
                // Achats par mois (12 derniers mois)
                $monthSt = $pdo->prepare(
                    "SELECT YEAR(created_at) y, MONTH(created_at) m, COUNT(*) nb
                     FROM achats
                     WHERE user_id=? AND statut='confirme'
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                     GROUP BY YEAR(created_at), MONTH(created_at)
                     ORDER BY y, m"
                );
                $monthSt->execute([$userId]);
                $monthData = $monthSt->fetchAll();

                // Bonus par mois
                $bonMonSt = $pdo->prepare(
                    "SELECT YEAR(created_at) y, MONTH(created_at) m, COUNT(*) nb
                     FROM bonus_history
                     WHERE user_id=? AND type='utilise'
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                     GROUP BY YEAR(created_at), MONTH(created_at)
                     ORDER BY y, m"
                );
                $bonMonSt->execute([$userId]);
                $bonusMonthData = $bonMonSt->fetchAll();

                // Top catégories (via achats)
                $catSt = $pdo->prepare(
                    "SELECT c.nom, c.icone, COUNT(*) nb
                     FROM achats a
                     JOIN livres l ON l.id=a.livre_id
                     LEFT JOIN categories c ON c.id=l.categorie_id
                     WHERE a.user_id=? AND a.statut='confirme'
                     GROUP BY c.id ORDER BY nb DESC LIMIT 5"
                );
                $catSt->execute([$userId]);
                $topCats = $catSt->fetchAll();

                echo json_encode([
                    'success'         => true,
                    'monthly_achats'  => $monthData,
                    'monthly_bonus'   => $bonusMonthData,
                    'top_cats'        => $topCats,
                ]);
                break;

            // ── Notifications ────────────────────────────────────────
            case 'get_notifs':
                $notifSt = $pdo->prepare(
                    "SELECT * FROM notifications
                     WHERE user_id=? OR user_id IS NULL
                     ORDER BY created_at DESC LIMIT 8"
                );
                $notifSt->execute([$userId]);
                $notifs = $notifSt->fetchAll();
                $unreadSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM notifications
                     WHERE (lu=0 OR is_read=0) AND (user_id=? OR user_id IS NULL)"
                );
                $unreadSt->execute([$userId]);
                echo json_encode([
                    'success'      => true,
                    'notifs'       => $notifs,
                    'unread_count' => (int)$unreadSt->fetchColumn(),
                ]);
                break;

            case 'mark_notif_read':
                $nid = (int)($_POST['notif_id'] ?? 0);
                if ($nid) {
                    $pdo->prepare("UPDATE notifications SET lu=1, is_read=1 WHERE id=? AND (user_id=? OR user_id IS NULL)")
                        ->execute([$nid, $userId]);
                }
                echo json_encode(['success' => true]);
                break;

            case 'mark_all_read':
                $pdo->prepare("UPDATE notifications SET lu=1, is_read=1 WHERE user_id=? OR user_id IS NULL")
                    ->execute([$userId]);
                echo json_encode(['success' => true]);
                break;

            // ── Actualiser les stats live ────────────────────────────
            case 'refresh':
                $bonusSt = $pdo->prepare("SELECT * FROM user_bonus WHERE user_id=?");
                $bonusSt->execute([$userId]);
                $bonus = $bonusSt->fetch() ?: ['achat_count'=>0,'bonus_total'=>0,'bonus_restant'=>0];

                $achatsSt = $pdo->prepare("SELECT COUNT(*) FROM achats WHERE user_id=? AND statut='confirme'");
                $achatsSt->execute([$userId]);
                $totalAchats = (int)$achatsSt->fetchColumn();

                $bonusBooksSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM bonus_history WHERE user_id=? AND type='utilise'"
                );
                $bonusBooksSt->execute([$userId]);
                $bonusLivres = (int)$bonusBooksSt->fetchColumn();

                echo json_encode([
                    'success'       => true,
                    'achat_count'   => (int)$bonus['achat_count'],
                    'bonus_total'   => (int)$bonus['bonus_total'],
                    'bonus_restant' => (int)$bonus['bonus_restant'],
                    'total_achats'  => $totalAchats,
                    'bonus_livres'  => $bonusLivres,
                ]);
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Action inconnue']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════
// CHARGEMENT DES DONNÉES INITIALES
// ══════════════════════════════════════════════════════════════════════
$bonus          = ['achat_count' => 0, 'bonus_total' => 0, 'bonus_restant' => 0];
$totalAchats    = 0;
$bonusLivres    = 0;
$notifications  = [];
$notifCount     = 0;
$categories     = [];
$BONUS_RULE     = 5;
$recentBonusBooks = [];

if ($pdo) {
    try {
        // Bonus rule depuis settings
        $rSt = $pdo->query("SELECT `value` FROM settings WHERE `key`='bonus_rule' LIMIT 1");
        $r   = $rSt ? $rSt->fetch() : null;
        if ($r) $BONUS_RULE = max(1, (int)$r['value']);

        // Données bonus
        $bonusSt = $pdo->prepare("SELECT * FROM user_bonus WHERE user_id=?");
        $bonusSt->execute([$userId]);
        $bonusDb = $bonusSt->fetch();
        if ($bonusDb) $bonus = $bonusDb;

        // Total achats
        $stA = $pdo->prepare("SELECT COUNT(*) FROM achats WHERE user_id=? AND statut='confirme'");
        $stA->execute([$userId]);
        $totalAchats = (int)$stA->fetchColumn();

        // Livres bonus obtenus
        $stBL = $pdo->prepare("SELECT COUNT(*) FROM bonus_history WHERE user_id=? AND type='utilise'");
        $stBL->execute([$userId]);
        $bonusLivres = (int)$stBL->fetchColumn();

        // Catégories pour filtres
        $categories = $pdo->query(
            "SELECT c.id, c.nom, c.icone, COUNT(l.id) AS nb
             FROM categories c
             LEFT JOIN livres l ON l.categorie_id=c.id AND l.statut='disponible'
             GROUP BY c.id, c.nom, c.icone ORDER BY c.nom"
        )->fetchAll();

        // Notifications
        $notifSt = $pdo->prepare(
            "SELECT * FROM notifications WHERE user_id=? OR user_id IS NULL
             ORDER BY created_at DESC LIMIT 6"
        );
        $notifSt->execute([$userId]);
        $notifications = $notifSt->fetchAll();

        $ncSt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE (lu=0 OR is_read=0) AND (user_id=? OR user_id IS NULL)"
        );
        $ncSt->execute([$userId]);
        $notifCount = (int)$ncSt->fetchColumn();

        // Livres bonus récents (3 derniers)
        $recentSt = $pdo->prepare(
            "SELECT l.id, l.titre, l.auteur, l.note_moyenne, c.icone AS cat_icone, bh.created_at
             FROM bonus_history bh
             JOIN livres l ON l.id=bh.livre_id
             LEFT JOIN categories c ON c.id=l.categorie_id
             WHERE bh.user_id=? AND bh.type='utilise' AND bh.livre_id IS NOT NULL
             ORDER BY bh.created_at DESC LIMIT 3"
        );
        $recentSt->execute([$userId]);
        $recentBonusBooks = $recentSt->fetchAll();

    } catch (Throwable $e) {
        error_log('[DLS-Bonus] Data: ' . $e->getMessage());
    }
}

// Calculs fidélité
$achatCount   = (int)$bonus['achat_count'];
$bonusTotal   = (int)$bonus['bonus_total'];
$bonusRestant = (int)$bonus['bonus_restant'];
$progPct      = min(100, round($achatCount / $BONUS_RULE * 100));
$achatsNext   = max(0, $BONUS_RULE - $achatCount);
$level        = $bonusTotal >= 20 ? 'Platine' : ($bonusTotal >= 10 ? 'Or' : ($bonusTotal >= 5 ? 'Argent' : 'Bronze'));
$levelColor   = $bonusTotal >= 20 ? '#e0e0e0' : ($bonusTotal >= 10 ? '#fbbf24' : ($bonusTotal >= 5 ? '#9ca3af' : '#d97706'));
$levelEmoji   = $bonusTotal >= 20 ? '💎' : ($bonusTotal >= 10 ? '🥇' : ($bonusTotal >= 5 ? '🥈' : '🥉'));

$csrfToken = csrf();

// Emojis livres
$bookEmojis = ['📚','📘','📗','📙','📕','📓','🔮','💡','🌊','🏔️','⚡','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌍'];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes Bonus — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══════════════════════════════════════════════
   RESET & VARIABLES
══════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#06090f;--surf:#0c1221;--card:rgba(255,255,255,.03);--card-hov:rgba(255,255,255,.056);
  --border:rgba(255,255,255,.07);--border-act:rgba(0,212,255,.38);
  --cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;--amber:#f59e0b;
  --rose:#f43f5e;--orange:#f97316;--gold:#fbbf24;--plum:#a78bfa;
  --tp:#eef2ff;--ts:rgba(238,242,255,.56);--tm:rgba(238,242,255,.28);
  --sw:256px;--th:60px;
  --r1:8px;--r2:13px;--r3:18px;--r4:26px;
  --gc:0 0 28px rgba(0,212,255,.18);
  --sc:0 4px 24px rgba(0,0,0,.35);
  --slg:0 20px 60px rgba(0,0,0,.52);
  --ease:cubic-bezier(.34,1.56,.64,1);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tp);overflow-x:hidden;min-height:100vh}
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}

/* ── LAYOUT ── */
.app{display:flex;min-height:100vh}

/* ── SIDEBAR ── */
#sb{position:fixed;top:0;left:0;bottom:0;width:var(--sw);background:var(--surf);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:200;overflow:hidden;transition:transform .3s ease}
.sb-brand{height:var(--th);display:flex;align-items:center;gap:10px;padding:0 18px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-logo{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:var(--gc);flex-shrink:0}
.sb-name{font-family:'Syne',sans-serif;font-weight:800;font-size:.88rem}
.sb-name em{color:var(--cyan);font-style:normal}
.sb-user{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-av{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--neon),var(--cyan));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.9rem;color:#000;flex-shrink:0}
.sb-un{font-family:'Syne',sans-serif;font-weight:700;font-size:.83rem}
.sb-ur{font-size:.6rem;color:var(--violet);font-family:'Space Mono',monospace;margin-top:2px}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.sb-sect{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;color:var(--tm);padding:10px 18px 3px}
.sb-a{display:flex;align-items:center;gap:10px;padding:9px 18px;margin:1px 8px;border-radius:var(--r1);text-decoration:none;color:var(--ts);font-size:.82rem;font-weight:500;transition:all .15s;position:relative;cursor:pointer}
.sb-a:hover{color:var(--tp);background:var(--card-hov)}
.sb-a.on{color:var(--violet);background:rgba(124,58,237,.07);border:1px solid rgba(124,58,237,.15)}
.sb-a.on::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:16px;background:var(--violet);border-radius:0 3px 3px 0}
.sb-badge{margin-left:auto;font-size:.58rem;font-family:'Space Mono',monospace;padding:2px 6px;border-radius:100px;background:var(--violet);color:#fff;font-weight:700}
.sb-badge.pulse{animation:badgePulse 2s ease-in-out infinite}
@keyframes badgePulse{0%,100%{box-shadow:0 0 0 0 rgba(124,58,237,.4)}50%{box-shadow:0 0 0 6px rgba(124,58,237,0)}}
.sb-foot{padding:10px;border-top:1px solid var(--border);flex-shrink:0}
.sb-logout{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:var(--r1);color:var(--ts);text-decoration:none;font-size:.78rem;transition:all .15s}
.sb-logout:hover{color:var(--rose);background:rgba(244,63,94,.06)}

/* ── MAIN ── */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-height:100vh}

/* ── TOPBAR ── */
#topbar{height:var(--th);background:rgba(6,9,15,.9);backdrop-filter:blur(22px);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:1rem;padding:0 1.6rem;position:sticky;top:0;z-index:100}
.tb-path{font-size:.78rem;color:var(--ts);display:flex;align-items:center;gap:6px}
.tb-path a{color:var(--ts);text-decoration:none;transition:color .15s}
.tb-path a:hover{color:var(--tp)}
.tb-curr{font-family:'Syne',sans-serif;font-weight:700;color:var(--tp)}
.tb-sp{flex:1}
.tb-btn{width:34px;height:34px;border-radius:var(--r1);background:var(--card);border:1px solid var(--border);color:var(--ts);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.95rem;transition:all .15s;text-decoration:none;position:relative}
.tb-btn:hover{color:var(--tp);background:var(--card-hov)}
.nb{position:absolute;top:-3px;right:-3px;min-width:15px;height:15px;padding:0 3px;background:var(--rose);border-radius:50%;font-size:.5rem;font-weight:700;font-family:'Space Mono',monospace;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);color:#fff}
.tb-ham{display:none;background:none;border:none;color:var(--tp);font-size:1.3rem;cursor:pointer;width:34px;height:34px;align-items:center;justify-content:center;border-radius:var(--r1)}
.tb-chip{display:flex;align-items:center;gap:7px;padding:4px 10px 4px 4px;background:var(--card);border:1px solid var(--border);border-radius:100px;text-decoration:none;color:var(--tp);font-size:.75rem;font-weight:600}
.tb-chip-av{width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,var(--neon),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:.68rem;color:#000;font-weight:800;flex-shrink:0}

/* ── PAGE ── */
.page{padding:1.8rem 2rem 5rem;max-width:1440px;width:100%;margin:0 auto}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes shimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
@keyframes floatUp{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
@keyframes gradShift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes ringPulse{0%,100%{box-shadow:0 0 0 0 rgba(124,58,237,.4),0 0 20px rgba(124,58,237,.2)}50%{box-shadow:0 0 0 12px rgba(124,58,237,0),0 0 30px rgba(124,58,237,.3)}}
@keyframes countUp{from{opacity:0;transform:scale(.8)}to{opacity:1;transform:scale(1)}}

/* ── HERO BANNER ── */
.hero{background:linear-gradient(135deg,rgba(124,58,237,.08),rgba(0,212,255,.06),rgba(0,255,170,.04));border:1px solid rgba(124,58,237,.15);border-radius:var(--r4);padding:2.2rem 2.6rem;margin-bottom:2rem;position:relative;overflow:hidden;animation:fadeUp .4s ease both}
.hero::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--violet),var(--cyan),var(--neon));background-size:200%;animation:gradShift 4s ease infinite}
.hero-orb{position:absolute;border-radius:50%;filter:blur(60px);pointer-events:none}
.hero-orb-1{width:300px;height:300px;background:rgba(124,58,237,.08);top:-80px;right:-60px}
.hero-orb-2{width:200px;height:200px;background:rgba(0,212,255,.06);bottom:-60px;left:20%}
.hero-row{display:flex;align-items:center;gap:2rem;flex-wrap:wrap;position:relative;z-index:1}
.hero-badge-wrap{flex-shrink:0}
.hero-badge{width:100px;height:100px;border-radius:24px;background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(0,212,255,.1));border:1px solid rgba(124,58,237,.3);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;animation:ringPulse 3s ease-in-out infinite;position:relative}
.hero-badge-emoji{font-size:2.8rem;animation:floatUp 4s ease-in-out infinite}
.hero-badge-level{font-family:'Space Mono',monospace;font-size:.55rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase}
.hero-info{flex:1;min-width:200px}
.hero-title{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;letter-spacing:-.5px;margin-bottom:6px}
.hero-sub{font-size:.85rem;color:var(--ts);line-height:1.5;margin-bottom:12px}
.hero-pills{display:flex;gap:6px;flex-wrap:wrap}
.pill{display:inline-flex;align-items:center;gap:4px;font-family:'Space Mono',monospace;font-size:.6rem;padding:3px 10px;border-radius:100px;text-transform:uppercase;font-weight:700}
.p-violet{background:rgba(124,58,237,.1);color:var(--plum);border:1px solid rgba(124,58,237,.2)}
.p-cyan{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.p-neon{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.p-amber{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.p-rose{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.hero-right{flex-shrink:0;text-align:right}

/* ── PROGRESS FIDÉLITÉ ── */
.fidelity-bar-wrap{background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.15);border-radius:var(--r3);padding:1.4rem;margin-bottom:2rem;animation:fadeUp .5s ease both}
.fid-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem}
.fid-label{font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:8px}
.fid-sub{font-size:.72rem;color:var(--ts)}
.fid-right{text-align:right}
.fid-count{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;color:var(--violet)}
.fid-total{font-size:.65rem;color:var(--tm);font-family:'Space Mono',monospace}
.prog-track{height:10px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden;position:relative;margin-bottom:.7rem}
.prog-fill{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--violet),var(--cyan),var(--neon));background-size:200%;animation:gradShift 3s ease infinite;transition:width 1.5s var(--ease);position:relative}
.prog-fill::after{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);background-size:200%;animation:shimmer 2s linear infinite}
.fid-steps{display:flex;align-items:center;justify-content:space-between}
.fid-step{display:flex;flex-direction:column;align-items:center;gap:2px;position:relative}
.fid-step-dot{width:10px;height:10px;border-radius:50%;border:2px solid rgba(255,255,255,.15);background:var(--bg);transition:all .3s}
.fid-step-dot.done{background:var(--violet);border-color:var(--violet);box-shadow:0 0 8px rgba(124,58,237,.4)}
.fid-step-lbl{font-family:'Space Mono',monospace;font-size:.52rem;color:var(--tm)}

/* ── STATS GRID ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:1.3rem;position:relative;overflow:hidden;transition:all .22s;animation:fadeUp .5s ease both;cursor:default}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--a1,#fff),var(--a2,#888));opacity:0;transition:opacity .3s}
.stat-card:hover{transform:translateY(-4px);border-color:rgba(255,255,255,.1);box-shadow:var(--sc)}
.stat-card:hover::before{opacity:1}
.stat-card:nth-child(1){--a1:var(--violet);--a2:var(--cyan);animation-delay:.05s}
.stat-card:nth-child(2){--a1:var(--neon);--a2:var(--cyan);animation-delay:.1s}
.stat-card:nth-child(3){--a1:var(--amber);--a2:var(--orange);animation-delay:.15s}
.stat-card:nth-child(4){--a1:var(--cyan);--a2:var(--violet);animation-delay:.2s}
.stat-card:nth-child(5){--a1:var(--rose);--a2:var(--amber);animation-delay:.25s}
.stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.9rem}
.stat-val{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--a1,#fff),var(--a2,#aaa));-webkit-background-clip:text;-webkit-text-fill-color:transparent;animation:countUp .6s var(--ease) both}
.stat-label{font-size:.72rem;color:var(--ts);margin-top:4px;font-weight:500}
.stat-sub{font-size:.62rem;color:var(--tm);margin-top:3px;font-family:'Space Mono',monospace}

/* ── TABS ── */
.tabs-wrap{margin-bottom:1.5rem;animation:fadeUp .5s ease .2s both}
.tabs{display:flex;gap:4px;background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:4px;width:fit-content}
.tab{display:flex;align-items:center;gap:7px;padding:8px 18px;border-radius:var(--r1);font-family:'Syne',sans-serif;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .2s;color:var(--ts);border:none;background:none;white-space:nowrap}
.tab:hover{color:var(--tp)}
.tab.on{background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(0,212,255,.1));color:var(--tp);border:1px solid rgba(124,58,237,.25);box-shadow:0 2px 12px rgba(124,58,237,.15)}
.tab-badge{font-size:.55rem;font-family:'Space Mono',monospace;padding:1px 6px;border-radius:100px;background:rgba(124,58,237,.2);color:var(--plum)}

/* ── TAB PANELS ── */
.tab-panel{display:none}
.tab-panel.on{display:block}

/* ── CARD GENERAL ── */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);overflow:hidden;animation:fadeUp .5s ease both}
.card-h{padding:1.2rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.card-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:8px}
.card-body{padding:1.4rem 1.5rem}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--r1);font-family:'Syne',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;border:none;white-space:nowrap}
.btn-sm{padding:5px 11px;font-size:.72rem}
.btn-xs{padding:3px 8px;font-size:.65rem}
.btn-primary{background:linear-gradient(135deg,var(--violet),var(--cyan));color:#fff;box-shadow:0 4px 14px rgba(124,58,237,.25)}
.btn-primary:hover{opacity:.88;transform:translateY(-1px)}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--ts)}
.btn-ghost:hover{color:var(--tp);background:var(--card-hov)}
.btn-neon{background:rgba(0,255,170,.08);border:1px solid rgba(0,255,170,.2);color:var(--neon)}
.btn-neon:hover{background:rgba(0,255,170,.15)}
.btn-amber{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);color:var(--amber)}
.btn-amber:hover{background:rgba(245,158,11,.15)}
.btn-violet{background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.25);color:var(--plum)}
.btn-violet:hover{background:rgba(124,58,237,.2)}
.btn-disabled{opacity:.4;cursor:not-allowed;pointer-events:none}
.btn[disabled]{opacity:.4;cursor:not-allowed}

/* ── CHIPS / BADGES ── */
.chip{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-family:'Space Mono',monospace;padding:2px 8px;border-radius:100px;font-weight:700;text-transform:uppercase}
.chip-violet{background:rgba(124,58,237,.1);color:var(--plum);border:1px solid rgba(124,58,237,.2)}
.chip-neon{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.chip-amber{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.chip-cyan{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.chip-rose{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.chip-muted{background:rgba(255,255,255,.05);color:var(--tm);border:1px solid var(--border)}

/* ── LIVRES BONUS GRID ── */
.books-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1.1rem}
.book-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;transition:all .22s;animation:fadeUp .4s ease both;cursor:pointer;position:relative}
.book-card:hover{transform:translateY(-6px);border-color:rgba(124,58,237,.25);box-shadow:0 16px 40px rgba(0,0,0,.4)}
.book-cover{height:140px;display:flex;align-items:center;justify-content:center;font-size:3rem;position:relative;overflow:hidden}
.book-cover-bg{position:absolute;inset:0;background:linear-gradient(135deg,var(--bc1,#1a0d3c),var(--bc2,#4a1a7a))}
.book-cover-emoji{position:relative;z-index:1;filter:drop-shadow(0 4px 12px rgba(0,0,0,.4));transition:transform .3s var(--ease)}
.book-card:hover .book-cover-emoji{transform:scale(1.2) rotate(-5deg)}
.book-cover-vignette{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(6,9,15,.85));z-index:2}
.book-corner{position:absolute;top:8px;right:8px;z-index:3}
.book-body{padding:.85rem .9rem .9rem}
.book-genre{font-family:'Space Mono',monospace;font-size:.55rem;color:var(--plum);letter-spacing:.08em;text-transform:uppercase;margin-bottom:3px}
.book-title{font-family:'Syne',sans-serif;font-size:.82rem;font-weight:700;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.book-author{font-size:.68rem;color:var(--ts);margin-bottom:.6rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.book-meta{display:flex;align-items:center;justify-content:space-between;font-size:.65rem;color:var(--tm)}
.book-stars{color:var(--amber);font-size:.6rem;letter-spacing:-1px}
.book-actions{display:flex;gap:5px;margin-top:.7rem}
.bk-btn{flex:1;padding:5px 0;border-radius:7px;border:1px solid rgba(124,58,237,.25);background:rgba(124,58,237,.06);color:var(--plum);font-size:.66rem;font-weight:700;cursor:pointer;transition:all .18s;font-family:'Syne',sans-serif}
.bk-btn:hover{background:rgba(124,58,237,.15);border-color:rgba(124,58,237,.4)}
.bk-btn.success{border-color:rgba(0,255,170,.25);background:rgba(0,255,170,.06);color:var(--neon)}
.bk-btn.success:hover{background:rgba(0,255,170,.15)}

/* ── FILTERS BAR ── */
.filter-bar{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:1.2rem;background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:.9rem 1.2rem}
.f-label{font-family:'Space Mono',monospace;font-size:.58rem;color:var(--tm);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap}
.f-select{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r1);padding:5px 9px;color:var(--tp);font-size:.75rem;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;transition:border-color .2s}
.f-select:focus{border-color:var(--border-act)}
.f-search{display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r1);padding:5px 10px;flex:1;min-width:160px;max-width:240px;transition:border-color .2s}
.f-search:focus-within{border-color:rgba(124,58,237,.4);box-shadow:0 0 0 3px rgba(124,58,237,.08)}
.f-search input{background:none;border:none;outline:none;color:var(--tp);font-size:.75rem;font-family:'DM Sans',sans-serif;width:100%}
.f-search input::placeholder{color:var(--tm)}
.f-count{font-family:'Space Mono',monospace;font-size:.65rem;color:var(--tm);margin-left:auto;white-space:nowrap}

/* ── PAGINATION ── */
.pag{display:flex;align-items:center;justify-content:center;gap:5px;margin-top:1.5rem}
.pag-btn{width:32px;height:32px;border-radius:var(--r1);background:var(--card);border:1px solid var(--border);color:var(--ts);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.72rem;transition:all .15s;font-family:'Space Mono',monospace}
.pag-btn:hover,.pag-btn.on{color:var(--tp);background:rgba(124,58,237,.1);border-color:rgba(124,58,237,.25)}
.pag-btn.on{color:var(--plum);font-weight:700}

/* ── HISTORIQUE TIMELINE ── */
.timeline{display:flex;flex-direction:column;gap:.9rem}
.tl-item{display:flex;gap:14px;animation:fadeUp .4s ease both}
.tl-dot{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.88rem;flex-shrink:0;margin-top:2px;position:relative}
.tl-dot::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);width:1px;height:calc(100% + 8px);background:var(--border)}
.tl-item:last-child .tl-dot::after{display:none}
.tl-body{flex:1;background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:1rem 1.2rem;transition:border-color .2s}
.tl-body:hover{border-color:rgba(124,58,237,.2)}
.tl-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:6px}
.tl-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem}
.tl-time{font-family:'Space Mono',monospace;font-size:.6rem;color:var(--tm);flex-shrink:0}
.tl-detail{font-size:.75rem;color:var(--ts);line-height:1.5}
.tl-footer{display:flex;align-items:center;gap:6px;margin-top:.6rem;flex-wrap:wrap}

/* ── SIDEBAR DROITE ── */
.two-col{display:grid;grid-template-columns:1fr 300px;gap:1.4rem;align-items:start}
@media(max-width:1100px){.two-col{grid-template-columns:1fr}}
.side-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);overflow:hidden;margin-bottom:1.2rem}
.side-h{padding:.9rem 1.2rem;border-bottom:1px solid var(--border);font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem;display:flex;align-items:center;gap:7px}
.side-body{padding:1rem 1.2rem}
.li-item{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.li-item:last-child{border-bottom:none}
.li-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.li-info{flex:1;min-width:0}
.li-t{font-size:.78rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.li-s{font-size:.64rem;color:var(--tm);font-family:'Space Mono',monospace;margin-top:2px}

/* ── GRAPHIQUE SIMPLE ── */
.chart-bars{display:flex;align-items:flex-end;gap:3px;height:70px;padding:4px 0}
.bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px}
.bar{width:100%;border-radius:3px 3px 0 0;background:linear-gradient(to top,var(--violet),rgba(124,58,237,.3));min-height:3px;transition:height 1s var(--ease);cursor:pointer;position:relative}
.bar:hover{filter:brightness(1.4)}
.bar-tt{position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);background:var(--surf);border:1px solid var(--border);border-radius:4px;padding:2px 6px;font-family:'Space Mono',monospace;font-size:.52rem;white-space:nowrap;opacity:0;transition:opacity .2s;pointer-events:none;color:var(--tp)}
.bar:hover .bar-tt{opacity:1}
.bar-lbl{font-family:'Space Mono',monospace;font-size:.5rem;color:var(--tm)}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:3rem 1.5rem}
.empty-icon{font-size:3.5rem;margin-bottom:.8rem;opacity:.5;display:block}
.empty-t{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:700;margin-bottom:.4rem}
.empty-s{font-size:.8rem;color:var(--tm);margin-bottom:1.2rem;line-height:1.6}

/* ── NOTIF PANEL ── */
#np{position:fixed;top:var(--th);right:1rem;width:308px;background:var(--surf);border:1px solid var(--border);border-radius:var(--r3);box-shadow:var(--slg);z-index:500;transform:translateY(-10px) scale(.97);opacity:0;pointer-events:none;transition:all .22s var(--ease);overflow:hidden}
#np.open{transform:translateY(6px) scale(1);opacity:1;pointer-events:all}
.np-h{padding:.9rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem}
.np-list{max-height:320px;overflow-y:auto}
.np-item{display:flex;gap:10px;padding:10px 1.1rem;border-bottom:1px solid rgba(255,255,255,.04);cursor:pointer;transition:background .12s}
.np-item:hover{background:var(--card-hov)}
.np-unread{background:rgba(124,58,237,.04)}
.np-ico{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;background:rgba(124,58,237,.1)}
.np-txt{font-size:.74rem;color:var(--ts);line-height:1.45}
.np-time{font-size:.58rem;font-family:'Space Mono',monospace;color:var(--tm);margin-top:2px}

/* ── MODAL ── */
.modal-ov{position:fixed;inset:0;background:rgba(6,9,15,.9);backdrop-filter:blur(16px);z-index:800;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-ov.open{opacity:1;pointer-events:all}
.modal-box{background:var(--surf);border:1px solid var(--border);border-radius:var(--r4);padding:2rem;max-width:520px;width:100%;box-shadow:var(--slg);transform:translateY(24px);transition:transform .3s var(--ease);position:relative;overflow:hidden;max-height:90vh;overflow-y:auto}
.modal-ov.open .modal-box{transform:translateY(0)}
.modal-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--violet),var(--cyan),var(--neon))}
.modal-t{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;margin-bottom:1.2rem}
.modal-close{position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--tm);font-size:1rem;cursor:pointer;width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.modal-close:hover{color:var(--tp);background:var(--card-hov)}

/* ── TOAST ── */
#toast-s{position:fixed;bottom:1.4rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:7px;pointer-events:none}
.toast{display:flex;align-items:center;gap:9px;padding:10px 14px;background:var(--surf);border:1px solid var(--border);border-radius:var(--r2);box-shadow:var(--slg);font-size:.78rem;max-width:310px;pointer-events:all;transform:translateX(130px);opacity:0;transition:all .38s var(--ease)}
.toast.show{transform:translateX(0);opacity:1}
.t-title{font-family:'Syne',sans-serif;font-weight:700}
.t-sub{color:var(--tm);font-size:.67rem;margin-top:2px}

/* ── LOADER ── */
.loader{width:26px;height:26px;border:2px solid rgba(124,58,237,.2);border-top-color:var(--violet);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto}

/* ── SIDEBAR OVERLAY MOBILE ── */
#sb-ov{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:199;opacity:0;pointer-events:none;transition:opacity .3s}
#sb-ov.show{opacity:1;pointer-events:all}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  #sb{transform:translateX(-100%)}
  #sb.open{transform:translateX(0)}
  .main{margin-left:0!important}
  .tb-ham{display:flex}
  .page{padding:1.2rem 1rem 4rem}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .two-col{grid-template-columns:1fr}
  .hero-row{flex-direction:column;gap:1rem}
  .hero-right{text-align:left}
}
@media(max-width:500px){
  .tabs{flex-wrap:wrap}
  .books-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>
<div class="app">

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside id="sb">
  <div class="sb-brand">
    <div class="sb-logo">📚</div>
    <div class="sb-name">Digital <em>Library</em></div>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= $avatar ?></div>
    <div>
      <div class="sb-un"><?= $username ?></div>
      <div class="sb-ur"><?= $levelEmoji ?> Niveau <?= $level ?></div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-sect">Navigation</div>
    <a class="sb-a" href="../dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>

    <a class="sb-a on" href="bonus.php">
      <i class="bi bi-gift-fill"></i> Mes Bonus
      <?php if ($bonusRestant > 0): ?>
        <span class="sb-badge pulse"><?= $bonusRestant ?></span>
      <?php endif; ?>
    </a>
</aside>
<div id="sb-ov" onclick="closeSB()"></div>

<!-- ══════════════ MAIN ══════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header id="topbar">
    <button class="tb-ham" onclick="openSB()" type="button" style="display:flex"><i class="bi bi-list"></i></button>
    <div class="tb-path">
      <a href="../dashboard.php">DLS</a>
      <span style="opacity:.3">/</span>
      <span class="tb-curr">Mes Bonus</span>
    </div>
    <div class="tb-sp"></div>
    <div style="display:flex;align-items:center;gap:5px">
      <button class="tb-btn" id="np-btn" onclick="toggleNP()" type="button">
        <i class="bi bi-bell"></i>
        <span class="nb" id="notif-badge" style="<?= $notifCount > 0 ? '' : 'display:none' ?>"><?= min($notifCount, 9) ?></span>
      </button>
      <button class="tb-btn" onclick="refreshAll()" type="button" title="Actualiser">
        <i class="bi bi-arrow-clockwise" id="refresh-ico"></i>
      </button>
      <a class="tb-chip" href="profile.php">
        <div class="tb-chip-av"><?= $avatar ?></div>
        <span><?= $firstName ?></span>
      </a>
    </div>
  </header>

  <!-- PAGE -->
  <main class="page">

    <!-- ── HERO ── -->
    <div class="hero">
      <div class="hero-orb hero-orb-1"></div>
      <div class="hero-orb hero-orb-2"></div>
      <div class="hero-row">
        <div class="hero-badge-wrap">
          <div class="hero-badge">
            <div class="hero-badge-emoji"><?= $levelEmoji ?></div>
            <div class="hero-badge-level" style="color:<?= $levelColor ?>"><?= $level ?></div>
          </div>
        </div>
        <div class="hero-info">
          <div class="hero-title">Programme Fidélité 🎁</div>
          <div class="hero-sub">
            <?php if ($bonusRestant > 0): ?>
              🎉 Vous avez <strong><?= $bonusRestant ?> livre<?= $bonusRestant > 1 ? 's' : '' ?> gratuit<?= $bonusRestant > 1 ? 's' : '' ?></strong> à réclamer !
            <?php else: ?>
              Plus que <strong><?= $achatsNext ?></strong> achat<?= $achatsNext > 1 ? 's' : '' ?> pour votre prochain livre gratuit
            <?php endif; ?>
          </div>
          <div class="hero-pills">
            <span class="pill p-violet"><i class="bi bi-star-fill"></i> <?= $level ?></span>
            <span class="pill p-cyan"><i class="bi bi-bag-check-fill"></i> <?= $totalAchats ?> achat<?= $totalAchats > 1 ? 's' : '' ?></span>
            <span class="pill p-neon"><i class="bi bi-gift-fill"></i> <?= $bonusTotal ?> bonus gagnés</span>
            <?php if ($bonusLivres > 0): ?>
              <span class="pill p-amber"><i class="bi bi-book-fill"></i> <?= $bonusLivres ?> livres gratuits</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="hero-right">
          <?php if ($bonusRestant > 0): ?>
            <button class="btn btn-primary" onclick="switchTab('books')">
              <i class="bi bi-gift-fill"></i> Réclamer mes livres
            </button>
          <?php else: ?>
            <a href="../books/index.php" class="btn btn-ghost">
              <i class="bi bi-compass"></i> Explorer le catalogue
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── BARRE DE PROGRESSION FIDÉLITÉ ── -->
    <div class="fidelity-bar-wrap">
      <div class="fid-row">
        <div>
          <div class="fid-label"><span><?= $levelEmoji ?></span> Progression fidélité</div>
          <div class="fid-sub">Tous les <?= $BONUS_RULE ?> achats confirmés → 1 livre gratuit</div>
        </div>
        <div class="fid-right">
          <div class="fid-count" id="fid-count"><?= $achatCount ?>/<?= $BONUS_RULE ?></div>
          <div class="fid-total"><?= $bonusTotal ?> bonus gagnés au total</div>
        </div>
      </div>
      <div class="prog-track">
        <div class="prog-fill" id="prog-fill" style="width:<?= $progPct ?>%"></div>
      </div>
      <div class="fid-steps">
        <?php for ($i = 1; $i <= $BONUS_RULE; $i++): ?>
        <div class="fid-step">
          <div class="fid-step-dot <?= $i <= $achatCount ? 'done' : '' ?>"></div>
          <div class="fid-step-lbl"><?= $i ?></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <!-- ── STATS CARTES ── -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(124,58,237,.08)">🛍️</div>
        <div class="stat-val" id="st-achats"><?= $totalAchats ?></div>
        <div class="stat-label">Total achats</div>
        <div class="stat-sub">Confirmés</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(0,255,170,.08)">🎁</div>
        <div class="stat-val" id="st-bonus"><?= $bonusTotal ?></div>
        <div class="stat-label">Bonus gagnés</div>
        <div class="stat-sub">Au total</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(0,212,255,.08)">⚡</div>
        <div class="stat-val" id="st-restant"><?= $bonusRestant ?></div>
        <div class="stat-label">Bonus disponibles</div>
        <div class="stat-sub">À utiliser</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(245,158,11,.08)">📚</div>
        <div class="stat-val" id="st-livres"><?= $bonusLivres ?></div>
        <div class="stat-label">Livres gratuits obtenus</div>
        <div class="stat-sub">Via bonus</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(244,63,94,.08)">🔄</div>
        <div class="stat-val"><?= $achatCount ?>/<?= $BONUS_RULE ?></div>
        <div class="stat-label">Achats en cours</div>
        <div class="stat-sub">Vers prochain bonus</div>
      </div>
    </div>

    <!-- ── TABS ── -->
    <div class="tabs-wrap">
      <div class="tabs">
        <button class="tab on" id="tab-books" onclick="switchTab('books')" type="button">
          <i class="bi bi-book-fill"></i> Livres disponibles
          <?php if ($bonusRestant > 0): ?><span class="tab-badge"><?= $bonusRestant ?></span><?php endif; ?>
        </button>
        <button class="tab" id="tab-obtained" onclick="switchTab('obtained')" type="button">
          <i class="bi bi-check2-circle"></i> Livres obtenus
          <?php if ($bonusLivres > 0): ?><span class="tab-badge"><?= $bonusLivres ?></span><?php endif; ?>
        </button>
        <button class="tab" id="tab-history" onclick="switchTab('history')" type="button">
          <i class="bi bi-clock-history"></i> Historique
        </button>
        <button class="tab" id="tab-stats" onclick="switchTab('stats')" type="button">
          <i class="bi bi-bar-chart-line"></i> Statistiques
        </button>
      </div>
    </div>

    <!-- ══════ TAB : LIVRES DISPONIBLES ══════ -->
    <div class="tab-panel on" id="panel-books">
      <div class="two-col">
        <div>
          <!-- Filtre -->
          <div class="filter-bar">
            <span class="f-label">Filtrer :</span>
            <div class="f-search">
              <i class="bi bi-search" style="color:var(--tm);font-size:.78rem"></i>
              <input type="text" id="books-search" placeholder="Titre, auteur…" autocomplete="off">
            </div>
            <select id="books-cat" class="f-select">
              <option value="0">Toutes catégories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>"><?= safeE($cat['icone'] ?? '') ?> <?= safeE($cat['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="books-sort" class="f-select">
              <option value="note">⭐ Mieux notés</option>
              <option value="recent">🕒 Récents</option>
              <option value="titre">🔤 Titre A-Z</option>
              <option value="prix_h">💰 Prix ↓</option>
              <option value="prix_b">💰 Prix ↑</option>
            </select>
            <span class="f-count" id="books-count">—</span>
          </div>

          <!-- Alerte bonus dispo -->
          <?php if ($bonusRestant > 0): ?>
          <div style="background:linear-gradient(135deg,rgba(124,58,237,.1),rgba(0,212,255,.06));border:1px solid rgba(124,58,237,.2);border-radius:var(--r2);padding:1rem 1.2rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:12px">
            <span style="font-size:1.8rem">🎉</span>
            <div>
              <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem">Vous avez <?= $bonusRestant ?> bonus à utiliser !</div>
              <div style="font-size:.75rem;color:var(--ts);margin-top:2px">Choisissez un livre ci-dessous et cliquez sur "Réclamer"</div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Grille livres -->
          <div id="books-grid" class="books-grid">
            <div style="grid-column:1/-1;text-align:center;padding:2rem">
              <div class="loader"></div>
              <div style="margin-top:.8rem;font-size:.78rem;color:var(--tm)">Chargement des livres…</div>
            </div>
          </div>
          <div class="pag" id="books-pag" style="display:none"></div>
        </div>

        <!-- Sidebar droite -->
        <div>
          <!-- Règle bonus -->
          <div class="side-card">
            <div class="side-h"><i class="bi bi-info-circle" style="color:var(--cyan)"></i> Comment ça marche ?</div>
            <div class="side-body">
              <div style="font-size:.78rem;color:var(--ts);line-height:1.65">
                <div style="margin-bottom:.7rem">🛍️ Achetez <strong><?= $BONUS_RULE ?> livres</strong> pour gagner <strong>1 livre gratuit</strong></div>
                <div style="margin-bottom:.7rem">🎁 Choisissez n'importe quel livre disponible</div>
                <div style="margin-bottom:.7rem">✅ Le livre est ajouté instantanément à votre bibliothèque</div>
                <div>♻️ Répétez pour accumuler plus de bonus</div>
              </div>
              <div style="margin-top:1rem;background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.15);border-radius:var(--r1);padding:.8rem;text-align:center">
                <div style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--tm);margin-bottom:4px">STATUT ACTUEL</div>
                <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:1.3rem;color:var(--violet)" id="side-restant"><?= $bonusRestant ?></div>
                <div style="font-size:.72rem;color:var(--ts)">bonus disponible<?= $bonusRestant > 1 ? 's' : '' ?></div>
              </div>
            </div>
          </div>

          <!-- Livres récents obtenus -->
          <?php if (!empty($recentBonusBooks)): ?>
          <div class="side-card">
            <div class="side-h"><i class="bi bi-clock" style="color:var(--amber)"></i> Derniers obtenus</div>
            <div class="side-body">
              <?php foreach ($recentBonusBooks as $rb): ?>
              <div class="li-item">
                <div class="li-icon" style="background:rgba(245,158,11,.08)"><?= safeE($rb['cat_icone'] ?? '📚') ?></div>
                <div class="li-info">
                  <div class="li-t"><?= safeE($rb['titre'] ?? '') ?></div>
                  <div class="li-s"><?= safeE($rb['auteur'] ?? '') ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Niveaux -->
          <div class="side-card">
            <div class="side-h"><i class="bi bi-trophy" style="color:var(--gold)"></i> Niveaux fidélité</div>
            <div class="side-body">
              <?php
              $lvls = [
                ['🥉','Bronze','0–4 bonus','#d97706',0],
                ['🥈','Argent','5–9 bonus','#9ca3af',5],
                ['🥇','Or','10–19 bonus','#fbbf24',10],
                ['💎','Platine','20+ bonus','#e0e0e0',20],
              ];
              foreach ($lvls as $l): $active = $bonusTotal >= $l[4] && ($l[4] === 20 || $bonusTotal < ($l[4] === 0 ? 5 : ($l[4] === 5 ? 10 : ($l[4] === 10 ? 20 : 9999)))); ?>
              <div class="li-item" style="<?= $active ? 'background:rgba(124,58,237,.05);border-radius:8px;padding:7px 8px;margin:0 -8px' : '' ?>">
                <div class="li-icon" style="font-size:1.1rem;background:transparent"><?= $l[0] ?></div>
                <div class="li-info">
                  <div class="li-t" style="color:<?= $l[3] ?>"><?= $l[1] ?></div>
                  <div class="li-s"><?= $l[2] ?></div>
                </div>
                <?php if ($active): ?><span class="chip chip-violet">Actuel</span><?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════ TAB : LIVRES OBTENUS ══════ -->
    <div class="tab-panel" id="panel-obtained">
      <div id="obtained-content">
        <div style="text-align:center;padding:2rem"><div class="loader"></div></div>
      </div>
    </div>

    <!-- ══════ TAB : HISTORIQUE ══════ -->
    <div class="tab-panel" id="panel-history">
      <div id="history-content">
        <div style="text-align:center;padding:2rem"><div class="loader"></div></div>
      </div>
      <div class="pag" id="history-pag" style="display:none;margin-top:1.5rem"></div>
    </div>

    <!-- ══════ TAB : STATISTIQUES ══════ -->
    <div class="tab-panel" id="panel-stats">
      <div id="stats-content">
        <div style="text-align:center;padding:2rem"><div class="loader"></div></div>
      </div>
    </div>

  </main>
</div><!-- /main -->
</div><!-- /app -->

<!-- ══════════════ NOTIFICATIONS PANEL ══════════════ -->
<div id="np">
  <div class="np-h">
    <span>Notifications</span>
    <div style="display:flex;gap:6px;align-items:center">
      <?php if ($notifCount > 0): ?><span class="chip chip-rose"><?= $notifCount ?></span><?php endif; ?>
      <button class="btn btn-ghost btn-xs" onclick="markAllRead()" type="button">Tout lu</button>
    </div>
  </div>
  <div class="np-list" id="np-list">
    <?php if (empty($notifications)): ?>
    <div style="padding:1.5rem;text-align:center;color:var(--tm);font-size:.8rem">🔔 Aucune notification</div>
    <?php else: foreach ($notifications as $n):
      $unread = !(bool)($n['lu'] ?? $n['is_read'] ?? false);
    ?>
    <div class="np-item <?= $unread ? 'np-unread' : '' ?>" onclick="markRead(<?= (int)$n['id'] ?>, this)">
      <div class="np-ico"><?= safeE($n['icon'] ?? '🔔') ?></div>
      <div>
        <?php if (!empty($n['titre'])): ?>
          <div class="np-txt" style="font-weight:600;color:var(--tp);font-size:.75rem"><?= safeE($n['titre']) ?></div>
        <?php endif; ?>
        <div class="np-txt"><?= safeE(mb_substr($n['message'] ?? '', 0, 80)) ?><?= mb_strlen($n['message'] ?? '') > 80 ? '…' : '' ?></div>
        <div class="np-time"><?= timeAgo($n['created_at'] ?? '') ?></div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <div style="padding:.7rem 1.1rem;border-top:1px solid var(--border)">
    <a href="../dashboard.php" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center">Voir tout</a>
  </div>
</div>

<!-- ══════════════ MODAL : CONFIRMATION BONUS ══════════════ -->
<div class="modal-ov" id="confirm-modal" onclick="if(event.target===this)closeModal('confirm-modal')">
  <div class="modal-box" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('confirm-modal')" type="button"><i class="bi bi-x-lg"></i></button>
    <div class="modal-t">🎁 Réclamer ce livre</div>
    <div id="confirm-body">
      <div style="display:flex;gap:14px;align-items:center;background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.15);border-radius:var(--r2);padding:1rem;margin-bottom:1.2rem">
        <div id="confirm-emoji" style="font-size:2.5rem;flex-shrink:0">📚</div>
        <div>
          <div id="confirm-title" style="font-family:'Syne',sans-serif;font-weight:700;font-size:.92rem;margin-bottom:3px">—</div>
          <div id="confirm-author" style="font-size:.75rem;color:var(--ts)">—</div>
          <div style="margin-top:6px"><span class="chip chip-neon">GRATUIT via bonus</span></div>
        </div>
      </div>
      <div style="font-size:.8rem;color:var(--ts);line-height:1.6;margin-bottom:1.4rem">
        En réclamant ce livre, <strong>1 bonus fidélité</strong> sera utilisé.<br>
        Le livre sera instantanément ajouté à votre bibliothèque.
      </div>
      <div style="display:flex;gap:.6rem">
        <button class="btn btn-ghost" style="flex:1;justify-content:center" onclick="closeModal('confirm-modal')" type="button">Annuler</button>
        <button class="btn btn-primary" style="flex:1;justify-content:center" id="confirm-claim-btn" type="button"><i class="bi bi-gift-fill"></i> Confirmer</button>
      </div>
    </div>
    <div id="confirm-loading" style="display:none;text-align:center;padding:2rem">
      <div class="loader" style="margin-bottom:.8rem"></div>
      <div style="font-size:.8rem;color:var(--tm)">Traitement en cours…</div>
    </div>
    <div id="confirm-success" style="display:none;text-align:center;padding:1.5rem 0">
      <div style="font-size:3rem;margin-bottom:.8rem">🎉</div>
      <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;color:var(--neon);margin-bottom:.4rem">Livre obtenu !</div>
      <div id="confirm-success-msg" style="font-size:.8rem;color:var(--ts);margin-bottom:1.2rem"></div>
      <button class="btn btn-neon" onclick="closeModal('confirm-modal');switchTab('obtained')" type="button"><i class="bi bi-book-fill"></i> Voir mes livres</button>
    </div>
  </div>
</div>

<!-- TOAST STACK -->
<div id="toast-s" role="region" aria-live="assertive"></div>

<!-- ══════════════ SCRIPTS ══════════════ -->
<script>
/* ── Globals ── */
const CSRF       = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const API        = window.location.href.split('?')[0];
const BONUS_REST = <?= $bonusRestant ?>;
const BONUS_RULE = <?= $BONUS_RULE ?>;
const FNAME      = <?= json_encode($firstName, JSON_HEX_TAG) ?>;

let currentConfirmBook = null;
let booksPage    = 1;
let histPage     = 1;
let statsLoaded  = false;
let obtainedLoaded = false;
let booksLoaded  = false;
let searchTimer  = null;

function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}

/* ── API ── */
async function api(action, data = {}) {
  data.csrf = CSRF;
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: fd,
  });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

/* ── Toast ── */
const TICONS = { success:'✅', error:'❌', warn:'⚠️', info:'ℹ️' };
const TCOLORS = { success:'var(--neon)', error:'var(--rose)', warn:'var(--amber)', info:'var(--cyan)' };
function toast(title, sub = '', type = 'info', dur = 3800) {
  const s = document.getElementById('toast-s');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = TCOLORS[type] || TCOLORS.info;
  t.innerHTML = `<span>${TICONS[type]||'ℹ️'}</span><div style="flex:1"><div class="t-title">${esc(title)}</div>${sub ? `<div class="t-sub">${esc(sub)}</div>` : ''}</div><span style="cursor:pointer;color:var(--tm);font-size:.88rem" onclick="this.parentElement.remove()">✕</span>`;
  s.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, dur);
}

/* ── Sidebar ── */
function openSB()  { document.getElementById('sb').classList.add('open'); document.getElementById('sb-ov').classList.add('show'); }
function closeSB() { document.getElementById('sb').classList.remove('open'); document.getElementById('sb-ov').classList.remove('show'); }

/* ── Modal ── */
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-ov.open').forEach(m => m.classList.remove('open')); });

/* ── Notif Panel ── */
function toggleNP() { document.getElementById('np')?.classList.toggle('open'); }
document.addEventListener('click', e => {
  const np = document.getElementById('np'), btn = document.getElementById('np-btn');
  if (np?.classList.contains('open') && !np.contains(e.target) && !btn?.contains(e.target)) np.classList.remove('open');
});

async function markRead(id, el) {
  el.classList.remove('np-unread');
  const badge = document.getElementById('notif-badge');
  if (badge) { const n = parseInt(badge.textContent) - 1; if (n <= 0) badge.style.display = 'none'; else badge.textContent = n; }
  try { await api('mark_notif_read', { notif_id: id }); } catch(e) {}
}

async function markAllRead() {
  try {
    await api('mark_all_read', {});
    document.querySelectorAll('.np-unread').forEach(el => el.classList.remove('np-unread'));
    const badge = document.getElementById('notif-badge');
    if (badge) badge.style.display = 'none';
    toast('Notifications', 'Tout marqué comme lu', 'success', 2000);
  } catch(e) {}
}

/* ═══════════════════════════════
   TABS
═══════════════════════════════ */
function switchTab(tab) {
  ['books','obtained','history','stats'].forEach(t => {
    document.getElementById('tab-' + t)?.classList.remove('on');
    document.getElementById('panel-' + t)?.classList.remove('on');
  });
  document.getElementById('tab-' + tab)?.classList.add('on');
  document.getElementById('panel-' + tab)?.classList.add('on');

  if (tab === 'books'    && !booksLoaded)    loadBooks(1);
  if (tab === 'obtained' && !obtainedLoaded) loadObtained();
  if (tab === 'history'  && histPage === 1 ) loadHistory(1);
  if (tab === 'stats'    && !statsLoaded)    loadStats();
}

/* ═══════════════════════════════
   TAB: LIVRES DISPONIBLES
═══════════════════════════════ */
const PALETTES = [['#1a0d3c','#4a1a7a'],['#0d1f3c','#1a4a7a'],['#0d3020','#1a6b3a'],['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a']];
const EMOJIS   = ['📚','📘','📗','📙','📕','📓','🔮','💡','🌊','🏔️','⚡','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌍'];

async function loadBooks(page = 1) {
  booksPage = page;
  booksLoaded = true;
  const grid = document.getElementById('books-grid');
  const q    = document.getElementById('books-search')?.value.trim() || '';
  const cat  = document.getElementById('books-cat')?.value || '0';
  const sort = document.getElementById('books-sort')?.value || 'note';

  grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2rem"><div class="loader"></div></div>';

  try {
    const d = await api('get_eligible_books', { page, q, cat, sort });
    if (!d.success) throw new Error(d.error || 'Erreur');

    document.getElementById('books-count').textContent = d.total + ' titre' + (d.total !== 1 ? 's' : '');

    if (d.all_possessed && d.total === 0) {
      grid.innerHTML = `<div style="grid-column:1/-1" class="empty"><span class="empty-icon">🏆</span><div class="empty-t">Vous possédez déjà tous les livres !</div><div class="empty-s">Félicitations ! Votre bibliothèque est complète.</div></div>`;
      return;
    }
    if (!d.books || d.books.length === 0) {
      grid.innerHTML = `<div style="grid-column:1/-1" class="empty"><span class="empty-icon">🔍</span><div class="empty-t">Aucun livre disponible</div><div class="empty-s">Modifiez vos filtres ou revenez plus tard.</div></div>`;
      document.getElementById('books-pag').style.display = 'none';
      return;
    }

    const bonusRestantNow = parseInt(document.getElementById('side-restant')?.textContent || '0');

    grid.innerHTML = d.books.map((b, i) => {
      const pal  = PALETTES[i % PALETTES.length];
      const em   = EMOJIS[i % EMOJIS.length];
      const note = parseFloat(b.note_moyenne || 0);
      const prix = parseFloat(b.prix || 0);
      const stars = '★'.repeat(Math.round(note)) + '☆'.repeat(5 - Math.round(note));
      const canClaim = bonusRestantNow > 0;
      return `
        <div class="book-card" data-id="${b.id}" data-emoji="${em}" data-titre="${esc(b.titre||'')}" data-auteur="${esc(b.auteur||'')}">
          <div class="book-cover">
            <div class="book-cover-bg" style="--bc1:${pal[0]};--bc2:${pal[1]}"></div>
            <div class="book-cover-emoji">${em}</div>
            <div class="book-cover-vignette"></div>
            <div class="book-corner">
              ${prix > 3500 ? '<span class="chip chip-amber" style="font-size:.5rem">PREMIUM</span>' : (prix === 0 ? '<span class="chip chip-neon" style="font-size:.5rem">GRATUIT</span>' : '')}
            </div>
          </div>
          <div class="book-body">
            <div class="book-genre">${esc(b.cat_icone||'📚')} ${esc(b.cat_nom||'Général')}</div>
            <div class="book-title">${esc(b.titre||'—')}</div>
            <div class="book-author">par ${esc(b.auteur||'—')}</div>
            <div class="book-meta">
              <span class="book-stars">${stars}</span>
              <span>${b.pages || 200}p</span>
            </div>
            <div class="book-actions">
              <button class="bk-btn${canClaim ? '' : ' btn-disabled'}"
                      onclick="openConfirm(${b.id}, '${esc(b.titre||'')}', '${esc(b.auteur||'')}', '${em}')"
                      ${canClaim ? '' : 'disabled'}
                      type="button">
                <i class="bi bi-gift-fill"></i> Réclamer
              </button>
            </div>
          </div>
        </div>`;
    }).join('');

    // Pagination
    buildPag('books-pag', page, d.pages, p => loadBooks(p));

  } catch(e) {
    grid.innerHTML = `<div style="grid-column:1/-1" class="empty"><span class="empty-icon">⚠️</span><div class="empty-t">Erreur</div><div class="empty-s">${esc(e.message)}</div><button class="btn btn-ghost" onclick="loadBooks(1)">Réessayer</button></div>`;
  }
}

// Filtres en temps réel
['books-search','books-cat','books-sort'].forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('change', () => loadBooks(1));
  if (id === 'books-search') el.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadBooks(1), 300); });
});

/* ═══════════════════════════════
   RÉCLAMER UN BONUS
═══════════════════════════════ */
function openConfirm(livreId, titre, auteur, emoji) {
  currentConfirmBook = { id: livreId, titre, auteur, emoji };
  document.getElementById('confirm-emoji').textContent  = emoji;
  document.getElementById('confirm-title').textContent  = titre;
  document.getElementById('confirm-author').textContent = 'par ' + auteur;
  document.getElementById('confirm-body').style.display    = 'block';
  document.getElementById('confirm-loading').style.display = 'none';
  document.getElementById('confirm-success').style.display = 'none';
  document.getElementById('confirm-claim-btn').onclick = doClaim;
  openModal('confirm-modal');
}

async function doClaim() {
  if (!currentConfirmBook) return;
  document.getElementById('confirm-body').style.display    = 'none';
  document.getElementById('confirm-loading').style.display = 'block';

  try {
    const d = await api('claim_bonus', { livre_id: currentConfirmBook.id });
    if (!d.success) throw new Error(d.error || 'Erreur');

    // Succès
    document.getElementById('confirm-loading').style.display = 'none';
    document.getElementById('confirm-success').style.display = 'block';
    document.getElementById('confirm-success-msg').textContent = d.msg || 'Livre ajouté à votre bibliothèque !';

    // Mettre à jour les compteurs
    updateCounters(d.bonus_restant, d.bonus_total);
    toast('🎉 Félicitations !', d.msg || 'Livre obtenu via bonus', 'success', 5000);

    // Réinitialiser les onglets pour forcer un rechargement
    obtainedLoaded = false;
    histPage = 1;

    // Recharger la liste (après fermeture)
    setTimeout(() => { booksLoaded = false; loadBooks(1); }, 800);

  } catch(e) {
    document.getElementById('confirm-loading').style.display = 'none';
    document.getElementById('confirm-body').style.display    = 'block';
    toast('Erreur', e.message, 'error', 5000);
  }
}

function updateCounters(restant, total) {
  // Stats cards
  const stR = document.getElementById('st-restant'); if (stR) stR.textContent = restant;
  const stB = document.getElementById('st-bonus');   if (stB) stB.textContent = total;
  const sideR = document.getElementById('side-restant'); if (sideR) sideR.textContent = restant;
  // Badge sidebar
  const sbBadge = document.querySelector('#sb .sb-badge');
  if (sbBadge) { if (restant > 0) { sbBadge.textContent = restant; sbBadge.style.display = 'inline'; } else { sbBadge.style.display = 'none'; } }
}

/* ═══════════════════════════════
   TAB: LIVRES OBTENUS
═══════════════════════════════ */
async function loadObtained() {
  obtainedLoaded = true;
  const el = document.getElementById('obtained-content');
  el.innerHTML = '<div style="text-align:center;padding:2rem"><div class="loader"></div></div>';

  try {
    const d = await api('get_bonus_books', {});
    if (!d.success) throw new Error(d.error || 'Erreur');

    if (!d.books || d.books.length === 0) {
      el.innerHTML = `
        <div class="empty">
          <span class="empty-icon">📚</span>
          <div class="empty-t">Aucun livre obtenu via bonus</div>
          <div class="empty-s">Accumulez des achats et utilisez vos bonus pour obtenir des livres gratuits !</div>
          <button class="btn btn-primary" onclick="switchTab('books')"><i class="bi bi-gift-fill"></i> Utiliser mes bonus</button>
        </div>`;
      document.getElementById('st-livres').textContent = '0';
      return;
    }

    document.getElementById('st-livres').textContent = d.books.length;

    el.innerHTML = `<div class="books-grid">${d.books.map((b, i) => {
      const pal  = PALETTES[i % PALETTES.length];
      const em   = EMOJIS[i % EMOJIS.length];
      const note = parseFloat(b.note_moyenne || 0);
      const stars = '★'.repeat(Math.round(note)) + '☆'.repeat(5 - Math.round(note));
      const pct  = parseFloat(b.pourcentage || 0);
      const date = b.date_obtenu ? new Date(b.date_obtenu).toLocaleDateString('fr-FR') : '—';
      return `
        <div class="book-card" style="animation-delay:${i * 0.04}s">
          <div class="book-cover">
            <div class="book-cover-bg" style="--bc1:${pal[0]};--bc2:${pal[1]}"></div>
            <div class="book-cover-emoji">${em}</div>
            <div class="book-cover-vignette"></div>
            <div class="book-corner"><span class="chip chip-neon" style="font-size:.5rem">BONUS</span></div>
          </div>
          <div class="book-body">
            <div class="book-genre">${esc(b.cat_icone||'📚')} ${esc(b.cat_nom||'Général')}</div>
            <div class="book-title">${esc(b.titre||'—')}</div>
            <div class="book-author">par ${esc(b.auteur||'—')}</div>
            <div class="book-meta">
              <span class="book-stars">${stars}</span>
              <span style="font-size:.6rem;color:var(--tm)">${date}</span>
            </div>
            ${pct > 0 ? `<div style="margin-top:6px"><div style="height:3px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden"><div style="width:${pct}%;height:100%;background:linear-gradient(90deg,var(--violet),var(--cyan));border-radius:100px"></div></div><div style="font-size:.6rem;color:var(--tm);margin-top:2px">${Math.round(pct)}% lu</div></div>` : ''}
            <div class="book-actions">
              <a href="../books/read.php?id=${b.id}" class="bk-btn success" style="text-align:center;text-decoration:none">
                <i class="bi bi-book-open-fill"></i> ${pct > 0 ? 'Reprendre' : 'Lire'}
              </a>
              ${b.fichier_pdf ? `<button class="bk-btn" style="flex:0 0 34px;padding:0" onclick="toast('Téléchargement','Démarrage…','info',2000)" type="button"><i class="bi bi-cloud-download"></i></button>` : ''}
            </div>
          </div>
        </div>`;
    }).join('')}</div>`;

  } catch(e) {
    el.innerHTML = `<div class="empty"><span class="empty-icon">⚠️</span><div class="empty-t">Erreur</div><div class="empty-s">${esc(e.message)}</div><button class="btn btn-ghost" onclick="loadObtained()">Réessayer</button></div>`;
  }
}

/* ═══════════════════════════════
   TAB: HISTORIQUE
═══════════════════════════════ */
async function loadHistory(page = 1) {
  histPage = page;
  const el   = document.getElementById('history-content');
  const pagEl = document.getElementById('history-pag');
  el.innerHTML = '<div style="text-align:center;padding:2rem"><div class="loader"></div></div>';
  pagEl.style.display = 'none';

  try {
    const d = await api('get_history', { page });
    if (!d.success) throw new Error(d.error || 'Erreur');

    if (!d.history || d.history.length === 0) {
      el.innerHTML = `
        <div class="empty">
          <span class="empty-icon">🕰️</span>
          <div class="empty-t">Aucun historique</div>
          <div class="empty-s">Vos bonus gagnés et utilisés apparaîtront ici.</div>
        </div>`;
      return;
    }

    const typeInfo = {
      gagne:  { color:'var(--neon)',   icon:'🎁', label:'Bonus gagné',   chip:'chip-neon',   bg:'rgba(0,255,170,.08)' },
      utilise:{ color:'var(--violet)', icon:'📚', label:'Bonus utilisé', chip:'chip-violet', bg:'rgba(124,58,237,.08)' },
      expire: { color:'var(--rose)',   icon:'⏰', label:'Bonus expiré',  chip:'chip-rose',   bg:'rgba(244,63,94,.08)' },
    };

    el.innerHTML = `<div class="timeline">${d.history.map((h, i) => {
      const ti   = typeInfo[h.type] || typeInfo.gagne;
      const date = h.created_at ? new Date(h.created_at).toLocaleDateString('fr-FR', {day:'2-digit',month:'long',year:'numeric'}) : '—';
      const time = h.created_at ? new Date(h.created_at).toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'}) : '';
      return `
        <div class="tl-item" style="animation-delay:${i * 0.06}s">
          <div class="tl-dot" style="background:${ti.bg};border:1px solid ${ti.color}22">${ti.icon}</div>
          <div class="tl-body">
            <div class="tl-header">
              <div class="tl-title">${esc(h.titre || h.detail || ti.label)}</div>
              <div class="tl-time">${date} ${time}</div>
            </div>
            ${h.auteur ? `<div class="tl-detail">par ${esc(h.auteur)} · ${esc(h.cat_nom||'Général')}</div>` : `<div class="tl-detail">${esc(h.detail || '')}</div>`}
            <div class="tl-footer">
              <span class="chip ${ti.chip}">${ti.label}</span>
              ${h.bonus_avant !== null && h.bonus_apres !== null ? `
                <span class="chip chip-muted">${h.bonus_avant} → ${h.bonus_apres} bonus</span>
              ` : ''}
              ${h.cat_nom ? `<span class="chip chip-cyan">${esc(h.cat_icone||'')} ${esc(h.cat_nom)}</span>` : ''}
            </div>
          </div>
        </div>`;
    }).join('')}</div>`;

    buildPag('history-pag', page, d.pages, p => loadHistory(p));
    pagEl.style.display = d.pages > 1 ? 'flex' : 'none';

  } catch(e) {
    el.innerHTML = `<div class="empty"><span class="empty-icon">⚠️</span><div class="empty-t">Erreur</div><div class="empty-s">${esc(e.message)}</div><button class="btn btn-ghost" onclick="loadHistory(1)">Réessayer</button></div>`;
  }
}

/* ═══════════════════════════════
   TAB: STATISTIQUES
═══════════════════════════════ */
async function loadStats() {
  statsLoaded = true;
  const el = document.getElementById('stats-content');
  el.innerHTML = '<div style="text-align:center;padding:2rem"><div class="loader"></div></div>';

  try {
    const d = await api('get_stats', {});
    if (!d.success) throw new Error(d.error || 'Erreur');

    const frMois = ['','Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
    // Préparer 12 mois
    const months12 = [];
    for (let i = 11; i >= 0; i--) {
      const ts = new Date(); ts.setDate(1); ts.setMonth(ts.getMonth() - i);
      months12.push({ m: ts.getMonth() + 1, y: ts.getFullYear(), label: frMois[ts.getMonth() + 1], nb_a: 0, nb_b: 0 });
    }
    (d.monthly_achats || []).forEach(r => {
      const slot = months12.find(s => s.m === parseInt(r.m) && s.y === parseInt(r.y));
      if (slot) slot.nb_a = parseInt(r.nb || 0);
    });
    (d.monthly_bonus || []).forEach(r => {
      const slot = months12.find(s => s.m === parseInt(r.m) && s.y === parseInt(r.y));
      if (slot) slot.nb_b = parseInt(r.nb || 0);
    });

    const maxA = Math.max(1, ...months12.map(m => m.nb_a));
    const maxB = Math.max(1, ...months12.map(m => m.nb_b));

    const topCatsHtml = (d.top_cats || []).length > 0 ? `
      <div class="side-card" style="margin-bottom:0">
        <div class="side-h"><i class="bi bi-tags" style="color:var(--amber)"></i> Catégories préférées</div>
        <div class="side-body">
          ${(d.top_cats || []).map(c => `
            <div class="li-item">
              <div class="li-icon">${esc(c.icone||'📚')}</div>
              <div class="li-info">
                <div class="li-t">${esc(c.nom||'Général')}</div>
                <div class="li-s">${c.nb} livre${c.nb > 1 ? 's' : ''}</div>
              </div>
              <div style="width:50px;height:4px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden">
                <div style="width:${Math.round(parseInt(c.nb) / Math.max(1, ...d.top_cats.map(x => parseInt(x.nb))) * 100)}%;height:100%;background:linear-gradient(90deg,var(--violet),var(--cyan));border-radius:100px"></div>
              </div>
            </div>`).join('')}
        </div>
      </div>` : '';

    el.innerHTML = `
      <div class="two-col">
        <div>
          <div class="card" style="margin-bottom:1.2rem">
            <div class="card-h">
              <div class="card-title"><i class="bi bi-bar-chart-line" style="color:var(--violet)"></i> Achats par mois (12 mois)</div>
            </div>
            <div class="card-body">
              <div class="chart-bars">
                ${months12.map(m => `
                  <div class="bar-wrap">
                    <div class="bar" style="height:${Math.max(4, Math.round(m.nb_a / maxA * 64))}px">
                      <span class="bar-tt">${m.nb_a} achat${m.nb_a > 1 ? 's' : ''}</span>
                    </div>
                    <div class="bar-lbl">${m.label}</div>
                  </div>`).join('')}
              </div>
              <div style="display:flex;justify-content:space-between;margin-top:.8rem;font-size:.68rem;color:var(--tm)">
                <span>Total : <strong style="color:var(--violet)">${months12.reduce((s,m)=>s+m.nb_a,0)} achats</strong></span>
                <span>Moy. : <strong style="color:var(--cyan)">${(months12.reduce((s,m)=>s+m.nb_a,0)/12).toFixed(1)}/mois</strong></span>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-h">
              <div class="card-title"><i class="bi bi-gift" style="color:var(--neon)"></i> Bonus utilisés par mois</div>
            </div>
            <div class="card-body">
              <div class="chart-bars">
                ${months12.map(m => `
                  <div class="bar-wrap">
                    <div class="bar" style="height:${Math.max(4, Math.round(m.nb_b / maxB * 64))}px;background:linear-gradient(to top,var(--neon),rgba(0,255,170,.2))">
                      <span class="bar-tt">${m.nb_b} bonus</span>
                    </div>
                    <div class="bar-lbl">${m.label}</div>
                  </div>`).join('')}
              </div>
              <div style="display:flex;justify-content:space-between;margin-top:.8rem;font-size:.68rem;color:var(--tm)">
                <span>Total utilisés : <strong style="color:var(--neon)">${months12.reduce((s,m)=>s+m.nb_b,0)}</strong></span>
              </div>
            </div>
          </div>
        </div>

        <div>
          <!-- Récap fidélité -->
          <div class="side-card" style="margin-bottom:1.2rem">
            <div class="side-h"><i class="bi bi-trophy" style="color:var(--gold)"></i> Récapitulatif</div>
            <div class="side-body">
              ${[
                ['🛍️','Total achats',    document.getElementById('st-achats')?.textContent||'0', 'var(--violet)'],
                ['🎁','Bonus gagnés',    document.getElementById('st-bonus')?.textContent||'0',   'var(--neon)'],
                ['⚡','Bonus disponibles',document.getElementById('st-restant')?.textContent||'0','var(--cyan)'],
                ['📚','Livres gratuits', document.getElementById('st-livres')?.textContent||'0',   'var(--amber)'],
              ].map(([ico,lbl,val,col]) => `
                <div class="li-item">
                  <div class="li-icon" style="font-size:1rem;background:transparent">${ico}</div>
                  <div class="li-info"><div class="li-t">${lbl}</div></div>
                  <div style="font-family:'Syne',sans-serif;font-weight:800;color:${col}">${val}</div>
                </div>`).join('')}
            </div>
          </div>
          ${topCatsHtml}
        </div>
      </div>`;

  } catch(e) {
    el.innerHTML = `<div class="empty"><span class="empty-icon">⚠️</span><div class="empty-t">Erreur</div><div class="empty-s">${esc(e.message)}</div><button class="btn btn-ghost" onclick="statsLoaded=false;loadStats()">Réessayer</button></div>`;
  }
}

/* ═══════════════════════════════
   PAGINATION
═══════════════════════════════ */
function buildPag(id, cur, total, cb) {
  const el = document.getElementById(id);
  if (!el) return;
  if (total <= 1) { el.style.display = 'none'; return; }
  el.style.display = 'flex';
  let html = '';
  const prev = cur > 1;
  const next = cur < total;
  html += `<div class="pag-btn" onclick="${prev ? `(${cb.toString()})(${cur-1})` : ''}" style="${prev ? '' : 'opacity:.3;pointer-events:none'}"><i class="bi bi-chevron-left"></i></div>`;
  for (let p = Math.max(1, cur - 2); p <= Math.min(total, cur + 2); p++) {
    html += `<div class="pag-btn ${p === cur ? 'on' : ''}" onclick="(${cb.toString()})(${p})">${p}</div>`;
  }
  html += `<div class="pag-btn" onclick="${next ? `(${cb.toString()})(${cur+1})` : ''}" style="${next ? '' : 'opacity:.3;pointer-events:none'}"><i class="bi bi-chevron-right"></i></div>`;
  el.innerHTML = html;
}

/* ═══════════════════════════════
   REFRESH GLOBAL
═══════════════════════════════ */
async function refreshAll() {
  const ico = document.getElementById('refresh-ico');
  if (ico) ico.style.animation = 'spin .6s linear infinite';
  try {
    const d = await api('refresh', {});
    if (d.success) {
      document.getElementById('st-achats').textContent  = d.total_achats;
      document.getElementById('st-bonus').textContent   = d.bonus_total;
      document.getElementById('st-restant').textContent = d.bonus_restant;
      document.getElementById('st-livres').textContent  = d.bonus_livres;
      document.getElementById('fid-count').textContent  = d.achat_count + '/' + BONUS_RULE;
      document.getElementById('side-restant').textContent = d.bonus_restant;

      const pct = Math.min(100, Math.round(d.achat_count / BONUS_RULE * 100));
      const pfill = document.getElementById('prog-fill');
      if (pfill) pfill.style.width = pct + '%';

      // Mettre à jour les steps
      document.querySelectorAll('.fid-step-dot').forEach((dot, i) => {
        dot.classList.toggle('done', i < d.achat_count);
      });

      // Notifs
      const notifD = await api('get_notifs', {});
      if (notifD.success) {
        const badge = document.getElementById('notif-badge');
        if (badge) {
          if (notifD.unread_count > 0) { badge.textContent = Math.min(notifD.unread_count, 9); badge.style.display = 'flex'; }
          else badge.style.display = 'none';
        }
      }

      toast('Actualisé', 'Données mises à jour', 'success', 2000);
    }
  } catch(e) {}
  if (ico) ico.style.animation = '';
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', () => {
  // Charger l'onglet actif par défaut
  loadBooks(1);

  // Animation barres de progression
  setTimeout(() => {
    const pf = document.getElementById('prog-fill');
    if (pf) { const w = pf.style.width; pf.style.width = '0%'; requestAnimationFrame(() => requestAnimationFrame(() => { pf.style.width = w; })); }
  }, 300);

  // Toast bienvenue
  setTimeout(() => {
    if (BONUS_REST > 0) {
      toast(`🎉 ${FNAME}, vous avez ${BONUS_REST} bonus !`, 'Choisissez un livre gratuit maintenant', 'success', 5000);
    } else {
      toast(`👋 Bonjour ${FNAME}`, 'Votre tableau de bord fidélité', 'info', 3000);
    }
  }, 700);

  // Polling toutes les 30s
  setInterval(refreshAll, 30000);
});

// Ajouter CSS animation spin via JS
const _sty = document.createElement('style');
_sty.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(_sty);
</script>
</body>
</html>