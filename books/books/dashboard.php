<?php
/**
 * DIGITAL LIBRARY SYSTEM — Dashboard FIX FINAL
 */

// ── Session ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Connexion BD ─────────────────────────────────────────
if (!isset($pdo)) {

    foreach ([
        __DIR__ . '/includes/config.php',
        __DIR__ . '/config/config.php',
    ] as $_cfgPath) {
        if (file_exists($_cfgPath)) {
            require_once $_cfgPath;
            break;
        }
    }


require_once __DIR__ . '/../../config/database.php';

function dbCount($sql){
    global $pdo;

    try{
        $stmt = $pdo->query($sql);
        return $stmt->fetchColumn();
    }catch(Exception $e){
        return 0;
    }
}


    if (!isset($pdo)) {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=digital_library;charset=utf8mb4",
            "root",
            "",
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    }
}

// ── Session user check ───────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ── USER DATA ────────────────────────────────────────────
$userId = (int) $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';

$username = $_SESSION['user_name'] ?? 'Utilisateur';
$userEmail = $_SESSION['user_email'] ?? '';

$firstName = explode(' ', trim($username))[0] ?? 'Utilisateur';
$avatar = strtoupper(substr($username, 0, 1) ?: 'U');

// ── ROLE VALIDATION ──────────────────────────────────────
if (!in_array($userRole, ['admin', 'journaliste', 'lecteur'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ── SETTINGS LOADING (IMPORTANT FIX) ────────────────────
$settings = [];

if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        $settings = [];
    }
}

/**
 * timeAgo — Convertit une date en "il y a X"
 */
function timeAgo(string $datetime): string {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 0)      return 'à l\'instant';
    if ($diff < 60)     return 'à l\'instant';
    if ($diff < 3600)   return (int)($diff / 60) . ' min';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h';
    if ($diff < 604800) return (int)($diff / 86400) . 'j';
    return date('d/m/Y', strtotime($datetime));
}

function fmtFCFA(float $n): string {
    return number_format($n, 0, ',', ' ') . ' FCFA';
}

function renderRoleBadge(string $role): string {
    return ['admin' => 'chip-danger', 'journaliste' => 'chip-warn', 'lecteur' => 'chip-info'][$role] ?? 'chip-muted';
}

function renderStatutBadge(string $s): string {
    return $s === 'actif' ? 'chip-success' : ($s === 'bloque' ? 'chip-danger' : 'chip-muted');
}

function getLatestBooks(int $limit = 6): array {
    global $pdo;
    if (!$pdo) return [];
    try {
        $st = $pdo->prepare(
            "SELECT l.id, l.titre, l.auteur, l.prix, l.statut, l.note_moyenne,
                    c.nom AS categorie_nom
             FROM livres l
             LEFT JOIN categories c ON c.id = l.categorie_id
             WHERE l.statut = 'disponible'
             ORDER BY l.created_at DESC LIMIT ?"
        );
        $st->execute([$limit]);
        return $st->fetchAll();
    } catch (Exception $e) { return []; }
}

/**
 * CSRF Token sécurisé
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// ── Données dashboard selon rôle ─────────────────────────────
$data = [];

if ($pdo) {
    try {
        switch ($userRole) {

            // ────────────────── ADMIN ──────────────────
            case 'admin':
                // Stats principales
                $data['stats'] = [
                    'totalUsers'    => dbCount("SELECT COUNT(*) FROM users"),
                    'activeUsers'   => dbCount("SELECT COUNT(*) FROM users WHERE statut='actif'"),
                    'totalBooks'    => dbCount("SELECT COUNT(*) FROM livres WHERE statut='disponible'"),
                    'totalSales'    => dbCount("SELECT COUNT(*) FROM achats WHERE statut='confirme'"),
                    'activeToday'   => dbCount("SELECT COUNT(DISTINCT user_id) FROM achats WHERE DATE(created_at)=CURDATE()"),
                    'newUsersMonth' => dbCount("SELECT COUNT(*) FROM users WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"),
                    'newBooksWeek'  => dbCount("SELECT COUNT(*) FROM livres WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
                ];

                // Revenus
                $revRow = $pdo->query(
                    "SELECT
                       COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) THEN montant END),0) AS mois,
                       COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(NOW())-1 AND YEAR(created_at)=YEAR(NOW()) THEN montant END),0) AS mois_prev
                     FROM achats WHERE statut='confirme'"
                )->fetch();
                $revenueMonth = (float)($revRow['mois'] ?? 0);
                $revPrev      = (float)($revRow['mois_prev'] ?? 0);
                $revVar       = $revPrev > 0 ? round((($revenueMonth - $revPrev) / $revPrev) * 100, 1) : 0;

                $salesRow = $pdo->query(
                    "SELECT
                       COUNT(CASE WHEN MONTH(created_at)=MONTH(NOW()) THEN 1 END) AS mois,
                       COUNT(CASE WHEN MONTH(created_at)=MONTH(NOW())-1 THEN 1 END) AS mois_prev
                     FROM achats WHERE statut='confirme'"
                )->fetch();
                $salesVar = ($salesRow['mois_prev'] ?? 0) > 0
                    ? round(((($salesRow['mois'] ?? 0) - ($salesRow['mois_prev'] ?? 0)) / $salesRow['mois_prev']) * 100, 1)
                    : 0;

                $data['stats']['revenueMonth']  = fmtFCFA($revenueMonth);
                $data['stats']['revVariation']  = $revVar;
                $data['stats']['salesVariation'] = $salesVar;

                // Activité récente (dernières actions)
                $data['activity'] = $pdo->query(
                    "SELECT
                       CONCAT(u.prenom, ' ', u.nom) AS user_name,
                       l.titre AS livre_titre,
                       a.montant,
                       a.created_at,
                       a.methode
                     FROM achats a
                     JOIN users u ON u.id = a.user_id
                     JOIN livres l ON l.id = a.livre_id
                     WHERE a.statut = 'confirme'
                     ORDER BY a.created_at DESC LIMIT 8"
                )->fetchAll();

                // Formater activité
                foreach ($data['activity'] as &$act) {
                    $act['icon']     = '🛍️';
                    $act['color']    = '#00ffaa';
                    $act['msg']      = htmlspecialchars($act['user_name'], ENT_QUOTES, 'UTF-8') .
                                       ' a acheté <strong>' .
                                       htmlspecialchars($act['livre_titre'], ENT_QUOTES, 'UTF-8') .
                                       '</strong> · ' . fmtFCFA((float)$act['montant']);
                    $act['time_ago'] = timeAgo($act['created_at']);
                }
                unset($act);

                // Graphique 7 jours
                $chartRaw = $pdo->query(
                    "SELECT DATE(created_at) AS jour, COUNT(*) AS ventes, SUM(montant) AS revenus
                     FROM achats
                     WHERE statut='confirme' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                     GROUP BY jour ORDER BY jour"
                )->fetchAll();

                $data['chart'] = [];
                for ($i = 6; $i >= 0; $i--) {
                    $d   = date('Y-m-d', strtotime("-{$i} days"));
                    $lbl = date('D', strtotime($d));
                    $fr  = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Jeu','Fri'=>'Ven','Sat'=>'Sam','Sun'=>'Dim'];
                    $lbl = $fr[$lbl] ?? $lbl;
                    $found = 0;
                    foreach ($chartRaw as $r) {
                        if ($r['jour'] === $d) { $found = (int)$r['ventes']; break; }
                    }
                    $data['chart'][] = ['label' => $lbl, 'ventes' => $found];
                }

                // Utilisateurs récents
                $usersRaw = $pdo->query(
                    "SELECT u.id, u.nom, u.prenom, u.email, u.role, u.statut, u.created_at,
                            COUNT(a.id) AS nb_achats
                     FROM users u
                     LEFT JOIN achats a ON a.user_id = u.id AND a.statut='confirme'
                     GROUP BY u.id
                     ORDER BY u.created_at DESC LIMIT 8"
                )->fetchAll();
                $data['users']     = $usersRaw;
                $data['usersMeta'] = ['total' => dbCount("SELECT COUNT(*) FROM users")];

                // Notifications admin
                $data['notifications'] = $pdo->query(
                    "SELECT id, type, titre, message, icon, bg, lu, created_at
                     FROM notifications
                     WHERE user_id IS NULL OR user_id = {$userId}
                     ORDER BY created_at DESC LIMIT 6"
                )->fetchAll();

                $data['notifCount'] = dbCount(
                    "SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id IS NULL OR user_id={$userId})"
                );
                break;

            // ────────────────── JOURNALISTE ──────────────────
            case 'journaliste':
                $st = $pdo->prepare(
                    "SELECT
                       COUNT(*) AS total,
                       SUM(statut='disponible') AS published,
                       SUM(statut='archive') AS draft,
                       COALESCE(SUM(nb_ventes),0) AS views,
                       COALESCE(AVG(NULLIF(note_moyenne,0)),0) AS note_moy
                     FROM livres WHERE ajoute_par=?"
                );
                $st->execute([$userId]);
                $js = $st->fetch() ?: [];

                // Revenus journaliste
                $revSt = $pdo->prepare(
                    "SELECT COALESCE(SUM(a.montant),0) AS revenus
                     FROM achats a JOIN livres l ON l.id=a.livre_id
                     WHERE l.ajoute_par=? AND a.statut='confirme'"
                );
                $revSt->execute([$userId]);
                $revJ = (float)$revSt->fetchColumn();

                // Livres du journaliste
                $lSt = $pdo->prepare(
                    "SELECT l.id, l.titre, l.statut, l.note_moyenne,
                            COUNT(a.id) AS achats_count,
                            COALESCE(SUM(a.montant),0) AS revenus_livre
                     FROM livres l
                     LEFT JOIN achats a ON a.livre_id=l.id AND a.statut='confirme'
                     WHERE l.ajoute_par=?
                     GROUP BY l.id ORDER BY l.created_at DESC LIMIT 10"
                );
                $lSt->execute([$userId]);
                $js['livres'] = $lSt->fetchAll();

                // Données graphique
                $cSt = $pdo->prepare(
                    "SELECT l.titre, COALESCE(COUNT(a.id),0) AS ventes
                     FROM livres l
                     LEFT JOIN achats a ON a.livre_id=l.id AND a.statut='confirme'
                     WHERE l.ajoute_par=?
                     GROUP BY l.id ORDER BY ventes DESC LIMIT 8"
                );
                $cSt->execute([$userId]);
                $js['chartData'] = $cSt->fetchAll();

                // Commentaires récents sur ses livres
                $pdo->exec("CREATE TABLE IF NOT EXISTS avis (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    livre_id INT UNSIGNED NOT NULL,
                    note TINYINT UNSIGNED DEFAULT 0,
                    commentaire TEXT,
                    statut ENUM('publie','en_attente','refuse') DEFAULT 'en_attente',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $comSt = $pdo->prepare(
                    "SELECT av.commentaire, av.note, av.created_at,
                            l.titre AS livre_titre,
                            u.nom, u.prenom
                     FROM avis av
                     JOIN livres l ON l.id=av.livre_id
                     JOIN users u ON u.id=av.user_id
                     WHERE l.ajoute_par=? AND av.statut='publie'
                     ORDER BY av.created_at DESC LIMIT 5"
                );
                $comSt->execute([$userId]);
                $js['comments'] = $comSt->fetchAll();

                // Commentaires en attente
                $pendSt = $pdo->prepare(
                    "SELECT COUNT(*) FROM avis av JOIN livres l ON l.id=av.livre_id
                     WHERE l.ajoute_par=? AND av.statut='en_attente'"
                );
                $pendSt->execute([$userId]);
                $js['commentsPending'] = (int)$pendSt->fetchColumn();
                $js['revenus']         = $revJ;

                $data['stats'] = $js;

                $data['notifications'] = $pdo->prepare(
                    "SELECT id, type, titre, message, icon, bg, lu, created_at
                     FROM notifications WHERE user_id=? OR user_id IS NULL
                     ORDER BY created_at DESC LIMIT 5"
                ) ? (function() use ($pdo, $userId) {
                    $s = $pdo->prepare("SELECT id, type, titre, message, icon, bg, lu, created_at FROM notifications WHERE user_id=? OR user_id IS NULL ORDER BY created_at DESC LIMIT 5");
                    $s->execute([$userId]); return $s->fetchAll();
                })() : [];

                $data['notifCount'] = (function() use ($pdo, $userId) {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id=? OR user_id IS NULL)");
                    $s->execute([$userId]); return (int)$s->fetchColumn();
                })();
                break;

            // ────────────────── LECTEUR ──────────────────
            case 'lecteur':
                // Achats
                $aSt = $pdo->prepare(
                    "SELECT l.id, l.titre, l.auteur, l.prix AS paid_amount,
                            a.created_at AS purchase_date, a.montant
                     FROM achats a JOIN livres l ON l.id=a.livre_id
                     WHERE a.user_id=? AND a.statut='confirme'
                     ORDER BY a.created_at DESC LIMIT 8"
                );
                $aSt->execute([$userId]);
                $history = $aSt->fetchAll();

                $spSt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM achats WHERE user_id=? AND statut='confirme'");
                $spSt->execute([$userId]);
                $totalSpent = (float)$spSt->fetchColumn();

                // Bonus
                $bonSt = $pdo->prepare("SELECT bonus_restant FROM user_bonus WHERE user_id=?");
                $bonSt->execute([$userId]);
                $bonusCount = (int)($bonSt->fetchColumn() ?: 0);

                // Catalogue top livres
                $catSt = $pdo->query(
                    "SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne,
                            c.nom AS categorie_nom
                     FROM livres l
                     LEFT JOIN categories c ON c.id=l.categorie_id
                     WHERE l.statut='disponible'
                     ORDER BY l.nb_ventes DESC LIMIT 8"
                );
                $catalogue = $catSt->fetchAll();

                // Recommandations (livres non achetés, mieux notés)
                $purchasedIds = array_column($history, 'id');
                $excludeSQL   = $purchasedIds ? 'AND l.id NOT IN (' . implode(',', array_map('intval', $purchasedIds)) . ')' : '';
                $recSt = $pdo->query(
                    "SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne,
                            c.nom AS categorie_nom
                     FROM livres l
                     LEFT JOIN categories c ON c.id=l.categorie_id
                     WHERE l.statut='disponible' AND l.note_moyenne>=4.0
                     $excludeSQL
                     ORDER BY l.note_moyenne DESC, l.nb_ventes DESC LIMIT 6"
                );
                $recommendations = $recSt->fetchAll();

                $data['stats'] = [
                    'booksPurchased'  => count($history),
                    'totalSpent'      => $totalSpent,
                    'bonusCount'      => $bonusCount,
                    'history'         => $history,
                    'catalogue'       => $catalogue,
                    'recommendations' => $recommendations,
                ];

                $notifSt = $pdo->prepare(
                    "SELECT id, type, titre, message, icon, bg, lu, created_at
                     FROM notifications WHERE user_id=? OR user_id IS NULL
                     ORDER BY created_at DESC LIMIT 5"
                );
                $notifSt->execute([$userId]);
                $data['notifications'] = $notifSt->fetchAll();

                $ncSt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id=? OR user_id IS NULL)");
                $ncSt->execute([$userId]);
                $data['notifCount'] = (int)$ncSt->fetchColumn();
                break;
        }

    } catch (Throwable $e) {
        error_log('[DLS] Dashboard error: ' . $e->getMessage());
        $data['error'] = 'Erreur de chargement. Veuillez rafraîchir.';
    }
} else {
    $data['error'] = 'Base de données inaccessible. Vérifiez config.php.';
}

// ── Meta rôle ─────────────────────────────────────────────────
$roleIcons  = ['admin' => '⚡', 'journaliste' => '✍️', 'lecteur' => '📖'];
$roleLabels = ['admin' => 'Administrateur', 'journaliste' => 'Journaliste', 'lecteur' => 'Lecteur'];
$roleIcon   = $roleIcons[$userRole]  ?? '👤';
$roleLabel  = $roleLabels[$userRole] ?? 'Utilisateur';
$notifCount = (int)($data['notifCount'] ?? 0);
$csrfToken  = csrfToken();
?>
<!DOCTYPE html>
<html lang="fr" data-role="<?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Digital Library System</title>
<meta name="description" content="Tableau de bord Digital Library System">
    <title>
        <?= htmlspecialchars($settings['site_name'] ?? 'Digital Library') ?>
    </title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══════════════════════════════════════════════════════
   RESET & VARIABLES
══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg-base:#05080f; --bg-surface:#0b1020;
  --bg-card:rgba(255,255,255,.032); --bg-card-hov:rgba(255,255,255,.058);
  --border:rgba(255,255,255,.072); --border-act:rgba(0,212,255,.38);
  --cyan:#00d4ff; --violet:#7c3aed; --neon:#00ffaa;
  --amber:#f59e0b; --rose:#f43f5e; --orange:#f97316;
  --text-primary:#eef2ff; --text-secondary:rgba(238,242,255,.56); --text-muted:rgba(238,242,255,.28);
  --sidebar-w:262px; --sidebar-w-collapsed:70px; --topbar-h:62px;
  --glow-cyan:0 0 28px rgba(0,212,255,.18); --shadow-card:0 4px 24px rgba(0,0,0,.32);
  --shadow-lg:0 24px 64px rgba(0,0,0,.52);
  --r-sm:8px; --r-md:13px; --r-lg:18px; --r-xl:26px;
}
[data-role="admin"]       { --role-c:var(--rose);  --role-bg:rgba(244,63,94,.1); }
[data-role="journaliste"] { --role-c:var(--amber); --role-bg:rgba(245,158,11,.1); }
[data-role="lecteur"]     { --role-c:var(--neon);  --role-bg:rgba(0,255,170,.08); }
html { scroll-behavior:smooth; }
body { font-family:'DM Sans',sans-serif; background:var(--bg-base); color:var(--text-primary); overflow-x:hidden; min-height:100vh; }
::-webkit-scrollbar { width:3px; height:3px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:rgba(255,255,255,.12); border-radius:4px; }
::-webkit-scrollbar-thumb:hover { background:rgba(0,212,255,.3); }
/* ── LAYOUT ── */
.app-wrapper { display:flex; min-height:100vh; }
/* ── SIDEBAR ── */
#sidebar {
  position:fixed; top:0; left:0; bottom:0; width:var(--sidebar-w);
  background:var(--bg-surface); border-right:1px solid var(--border);
  display:flex; flex-direction:column; z-index:200;
  transition:width .3s cubic-bezier(.4,0,.2,1),transform .3s ease; overflow:hidden;
}
#sidebar.collapsed { width:var(--sidebar-w-collapsed); }
.sidebar-brand {
  height:var(--topbar-h); display:flex; align-items:center; gap:11px;
  padding:0 16px; border-bottom:1px solid var(--border); flex-shrink:0;
}
.brand-icon {
  width:36px; height:36px; background:linear-gradient(135deg,var(--cyan),var(--violet));
  border-radius:11px; display:flex; align-items:center; justify-content:center;
  font-size:1.05rem; flex-shrink:0; box-shadow:var(--glow-cyan);
}
.brand-text { font-family:'Syne',sans-serif; font-weight:800; font-size:.9rem;
  letter-spacing:-.3px; white-space:nowrap; overflow:hidden; transition:opacity .2s; }
.brand-text em { color:var(--cyan); font-style:normal; }
#sidebar.collapsed .brand-text { opacity:0; pointer-events:none; }
.sidebar-user {
  display:flex; align-items:center; gap:11px; padding:14px 16px;
  border-bottom:1px solid var(--border); flex-shrink:0;
}
.user-avatar {
  width:38px; height:38px; border-radius:12px;
  background:linear-gradient(135deg,var(--role-c),var(--violet));
  display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-weight:800; font-size:.88rem; color:#fff; flex-shrink:0;
}
.user-info { overflow:hidden; transition:opacity .2s; }
#sidebar.collapsed .user-info { opacity:0; pointer-events:none; }
.user-name { font-family:'Syne',sans-serif; font-weight:700; font-size:.82rem;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.role-pill {
  display:inline-flex; align-items:center; gap:3px;
  font-size:.62rem; font-family:'Space Mono',monospace; letter-spacing:.04em;
  padding:2px 8px; border-radius:100px; background:var(--role-bg); color:var(--role-c);
  border:1px solid rgba(255,255,255,.07); margin-top:4px; text-transform:uppercase;
}
.sidebar-nav { flex:1; overflow-y:auto; padding:10px 0; }
.nav-section {
  font-family:'Space Mono',monospace; font-size:.58rem; letter-spacing:.12em;
  text-transform:uppercase; color:var(--text-muted);
  padding:8px 16px 3px; white-space:nowrap; overflow:hidden; transition:opacity .2s;
}
#sidebar.collapsed .nav-section { opacity:0; }
.nav-item {
  display:flex; align-items:center; gap:11px; padding:9px 16px; margin:2px 8px;
  border-radius:var(--r-sm); cursor:pointer; text-decoration:none;
  color:var(--text-secondary); font-size:.83rem; font-weight:500;
  transition:all .18s ease; position:relative; white-space:nowrap; overflow:hidden;
}
.nav-item:hover { color:var(--text-primary); background:var(--bg-card-hov); }
.nav-item.active { color:var(--role-c); background:var(--role-bg); border:1px solid rgba(255,255,255,.05); }
.nav-item.active::before {
  content:''; position:absolute; left:0; top:50%; transform:translateY(-50%);
  width:3px; height:18px; background:var(--role-c); border-radius:0 3px 3px 0;
  box-shadow:0 0 10px var(--role-c);
}
.nav-icon { font-size:1.05rem; width:20px; text-align:center; flex-shrink:0; transition:transform .18s; }
.nav-item:hover .nav-icon { transform:scale(1.12); }
.nav-label { transition:opacity .2s; }
#sidebar.collapsed .nav-label { opacity:0; }
.nav-badge {
  margin-left:auto; font-size:.6rem; font-family:'Space Mono',monospace;
  padding:2px 6px; border-radius:100px; background:var(--role-c);
  color:#000; font-weight:700; transition:opacity .2s;
}
#sidebar.collapsed .nav-badge { opacity:0; }
.sidebar-footer { padding:10px; border-top:1px solid var(--border); }
.btn-collapse {
  width:100%; display:flex; align-items:center; gap:11px; padding:8px 6px;
  border-radius:var(--r-sm); background:none; border:none; color:var(--text-muted);
  font-size:.8rem; cursor:pointer; transition:all .18s; font-family:'DM Sans',sans-serif;
}
.btn-collapse:hover { color:var(--text-primary); background:var(--bg-card-hov); }
.ci { font-size:.95rem; flex-shrink:0; width:20px; text-align:center; transition:transform .3s; }
#sidebar.collapsed .ci { transform:rotate(180deg); }
.cl { transition:opacity .2s; white-space:nowrap; }
#sidebar.collapsed .cl { opacity:0; }
/* ── MAIN ── */
.main-content {
  margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh;
  transition:margin-left .3s cubic-bezier(.4,0,.2,1);
}
.main-content.collapsed { margin-left:var(--sidebar-w-collapsed); }
/* ── TOPBAR ── */
#topbar {
  height:var(--topbar-h); background:rgba(5,8,15,.85); backdrop-filter:blur(22px);
  border-bottom:1px solid var(--border); display:flex; align-items:center;
  gap:1rem; padding:0 1.4rem; position:sticky; top:0; z-index:100;
}
.tb-breadcrumb { display:flex; align-items:center; gap:7px; font-size:.8rem; color:var(--text-secondary); }
.bc-sep { opacity:.35; }
.bc-curr { font-family:'Syne',sans-serif; font-weight:700; color:var(--text-primary); }
.tb-spacer { flex:1; }
.tb-search-wrap { position:relative; }
.tb-search {
  display:flex; align-items:center; gap:7px; background:var(--bg-card);
  border:1px solid var(--border); border-radius:var(--r-sm); padding:6px 11px; width:220px;
  transition:border-color .2s,box-shadow .2s;
}
.tb-search:focus-within { border-color:var(--border-act); box-shadow:var(--glow-cyan); }
.tb-search input {
  background:none; border:none; outline:none; color:var(--text-primary);
  font-size:.78rem; font-family:'DM Sans',sans-serif; width:100%;
}
.tb-search input::placeholder { color:var(--text-muted); }
.si { color:var(--text-muted); font-size:.82rem; }
#search-results {
  position:absolute; top:calc(100% + 6px); left:0; right:0; min-width:320px;
  background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-md);
  box-shadow:var(--shadow-lg); z-index:500; display:none; overflow:hidden;
}
#search-results.show { display:block; }
.sr-item {
  display:flex; align-items:center; gap:10px; padding:9px 12px;
  border-bottom:1px solid rgba(255,255,255,.04); text-decoration:none;
  color:var(--text-secondary); font-size:.8rem; transition:background .12s;
}
.sr-item:hover { background:var(--bg-card-hov); color:var(--text-primary); }
.sr-item:last-child { border-bottom:none; }
.sr-icon { font-size:1.1rem; flex-shrink:0; }
.sr-title { font-weight:600; color:var(--text-primary); }
.sr-sub { font-size:.65rem; color:var(--text-muted); }
#search-loading,#search-empty { padding:10px 12px; font-size:.78rem; color:var(--text-muted); text-align:center; }
.tb-actions { display:flex; align-items:center; gap:5px; }
.tb-btn {
  width:34px; height:34px; border-radius:var(--r-sm); background:var(--bg-card);
  border:1px solid var(--border); color:var(--text-secondary); display:flex;
  align-items:center; justify-content:center; cursor:pointer; font-size:.95rem;
  transition:all .18s; position:relative; text-decoration:none;
}
.tb-btn:hover { color:var(--text-primary); background:var(--bg-card-hov); }
.notif-badge {
  position:absolute; top:-3px; right:-3px; min-width:15px; height:15px; padding:0 3px;
  background:var(--rose); border-radius:50%; font-size:.52rem; font-weight:700;
  font-family:'Space Mono',monospace; display:flex; align-items:center; justify-content:center;
  border:2px solid var(--bg-base); color:#fff; pointer-events:none;
}
.tb-user {
  display:flex; align-items:center; gap:7px; padding:5px 9px;
  border-radius:var(--r-sm); background:var(--bg-card); border:1px solid var(--border);
  cursor:pointer; transition:all .18s; text-decoration:none;
}
.tb-user:hover { border-color:var(--border-act); }
.tu-av {
  width:26px; height:26px; border-radius:8px;
  background:linear-gradient(135deg,var(--role-c),var(--violet));
  display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-weight:800; font-size:.72rem; color:#fff;
}
.tu-name { font-size:.76rem; font-weight:600; }
.tu-role { font-size:.6rem; color:var(--role-c); font-family:'Space Mono',monospace; }
.tb-ham {
  display:none; background:none; border:none; color:var(--text-primary);
  font-size:1.3rem; cursor:pointer; width:34px; height:34px;
  border-radius:var(--r-sm); align-items:center; justify-content:center;
}
/* ── PAGE CONTENT ── */
.page-content { flex:1; padding:1.8rem 1.8rem 4rem; max-width:1420px; width:100%; margin:0 auto; }
/* ── WELCOME BANNER ── */
.welcome-banner {
  background:linear-gradient(135deg,rgba(0,212,255,.055) 0%,rgba(124,58,237,.07) 50%,rgba(0,255,170,.04) 100%);
  border:1px solid rgba(0,212,255,.1); border-radius:var(--r-xl);
  padding:1.6rem 2rem; display:flex; align-items:center; justify-content:space-between; gap:1rem;
  margin-bottom:1.8rem; position:relative; overflow:hidden; animation:slideUp .4s ease both;
}
.welcome-banner::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon));
}
.welcome-banner::after {
  content:''; position:absolute; right:-50px; top:-50px; width:180px; height:180px;
  background:radial-gradient(circle,rgba(0,212,255,.07) 0%,transparent 70%); pointer-events:none;
}
.wb-title { font-family:'Syne',sans-serif; font-size:1.3rem; font-weight:800; margin-bottom:3px; }
.wb-sub { font-size:.8rem; color:var(--text-secondary); }
.wb-pill {
  display:inline-flex; align-items:center; gap:5px; font-family:'Space Mono',monospace;
  font-size:.65rem; padding:4px 11px; border-radius:100px; background:var(--role-bg);
  color:var(--role-c); border:1px solid rgba(255,255,255,.07); margin-top:7px; text-transform:uppercase;
}
.wb-actions { display:flex; gap:8px; flex-wrap:wrap; }
/* ── STATS GRID ── */
.stats-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(195px,1fr)); gap:.9rem; margin-bottom:1.8rem; }
.stat-card {
  background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-md);
  padding:1.3rem; backdrop-filter:blur(8px); transition:transform .22s,border-color .22s,box-shadow .22s;
  position:relative; overflow:hidden; animation:slideUp .5s ease both;
}
.stat-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,var(--ac1,#fff),var(--ac2,#888)); opacity:0; transition:opacity .3s;
}
.stat-card:hover { transform:translateY(-4px); border-color:rgba(255,255,255,.1); box-shadow:var(--shadow-card); }
.stat-card:hover::before { opacity:1; }
.stat-card:nth-child(1){--ac1:var(--cyan);--ac2:var(--violet);animation-delay:.04s}
.stat-card:nth-child(2){--ac1:var(--violet);--ac2:var(--rose);animation-delay:.08s}
.stat-card:nth-child(3){--ac1:var(--neon);--ac2:var(--cyan);animation-delay:.12s}
.stat-card:nth-child(4){--ac1:var(--amber);--ac2:var(--orange);animation-delay:.16s}
.stat-card:nth-child(5){--ac1:var(--rose);--ac2:var(--amber);animation-delay:.2s}
.stat-card:nth-child(6){--ac1:var(--cyan);--ac2:var(--neon);animation-delay:.24s}
.stat-icon { width:42px; height:42px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.15rem; margin-bottom:.9rem; }
.stat-value {
  font-family:'Syne',sans-serif; font-size:1.75rem; font-weight:800; letter-spacing:-.5px; line-height:1;
  background:linear-gradient(135deg,var(--ac1,#fff),var(--ac2,#aaa));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.stat-label { font-size:.73rem; color:var(--text-secondary); margin-top:5px; font-weight:500; }
.stat-change { margin-top:7px; font-size:.67rem; font-family:'Space Mono',monospace; display:flex; align-items:center; gap:3px; }
.up{color:var(--neon)} .down{color:var(--rose)} .neutral{color:var(--text-muted)}
/* ── GRID 2 colonnes ── */
.dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.3rem; margin-bottom:1.5rem; }
@media(max-width:1100px){.dash-grid{grid-template-columns:1fr}}
/* ── CARD ── */
.card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; animation:slideUp .5s ease both; backdrop-filter:blur(8px); }
.card-header { padding:1.1rem 1.4rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:.8rem; }
.card-title { font-family:'Syne',sans-serif; font-weight:700; font-size:.9rem; display:flex; align-items:center; gap:8px; }
.ct-icon { width:30px; height:30px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.9rem; }
.card-body { padding:1.1rem 1.4rem; }
.card-footer { padding:.9rem 1.4rem; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
/* ── DATA TABLE ── */
.data-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.data-table th {
  text-align:left; font-family:'Space Mono',monospace; font-size:.62rem; letter-spacing:.08em;
  text-transform:uppercase; color:var(--text-muted); padding:7px 11px; border-bottom:1px solid var(--border);
}
.data-table td {
  padding:9px 11px; border-bottom:1px solid rgba(255,255,255,.04);
  vertical-align:middle; color:var(--text-secondary); transition:background .12s;
}
.data-table tr:last-child td { border-bottom:none; }
.data-table tbody tr:hover td { background:var(--bg-card-hov); }
.td-p { font-weight:600; color:var(--text-primary); }
/* ── CHIPS ── */
.chip {
  display:inline-flex; align-items:center; gap:3px; font-size:.62rem;
  font-family:'Space Mono',monospace; padding:2px 8px; border-radius:100px;
  font-weight:700; letter-spacing:.03em; text-transform:uppercase;
}
.chip-success{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.chip-warn{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.chip-danger{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.chip-info{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.chip-violet{background:rgba(124,58,237,.1);color:#a78bfa;border:1px solid rgba(124,58,237,.2)}
.chip-muted{background:rgba(255,255,255,.05);color:var(--text-muted);border:1px solid var(--border)}
/* ── BUTTONS ── */
.btn {
  display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:var(--r-sm);
  font-family:'Syne',sans-serif; font-size:.78rem; font-weight:700; cursor:pointer;
  transition:all .18s ease; text-decoration:none; border:none; white-space:nowrap;
}
.btn-sm { padding:4px 10px; font-size:.7rem; }
.btn-primary{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff;box-shadow:0 4px 14px rgba(0,212,255,.18)}
.btn-primary:hover{opacity:.87;transform:translateY(-1px);box-shadow:0 6px 22px rgba(0,212,255,.32)}
.btn-ghost{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary)}
.btn-ghost:hover{color:var(--text-primary);border-color:rgba(255,255,255,.14);background:var(--bg-card-hov)}
.btn-danger{background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.22);color:var(--rose)}
.btn-danger:hover{background:rgba(244,63,94,.18)}
.btn-success{background:rgba(0,255,170,.1);border:1px solid rgba(0,255,170,.22);color:var(--neon)}
.btn-success:hover{background:rgba(0,255,170,.17)}
/* ── ACTIVITY ── */
.act-list { display:flex; flex-direction:column; }
.act-item { display:flex; align-items:flex-start; gap:11px; padding:10px 0; border-bottom:1px solid rgba(255,255,255,.04); }
.act-item:last-child { border-bottom:none; }
.act-dot { width:30px; height:30px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; background:var(--bg-card-hov); }
.act-msg { font-size:.78rem; color:var(--text-secondary); line-height:1.5; }
.act-msg strong { color:var(--text-primary); font-weight:600; }
.act-time { font-size:.62rem; font-family:'Space Mono',monospace; color:var(--text-muted); margin-top:2px; }
/* ── CHART ── */
.chart-bars { display:flex; align-items:flex-end; gap:5px; height:80px; padding:6px 0; }
.cb-wrap { flex:1; display:flex; flex-direction:column; align-items:center; gap:5px; }
.cb { width:100%; border-radius:4px 4px 0 0; background:linear-gradient(to top,var(--cyan),rgba(0,212,255,.3)); min-height:4px; transition:height .9s ease; position:relative; cursor:pointer; }
.cb:hover { filter:brightness(1.3); }
.cb-tooltip { position:absolute; bottom:calc(100% + 4px); left:50%; transform:translateX(-50%); font-family:'Space Mono',monospace; font-size:.55rem; color:var(--text-muted); white-space:nowrap; background:var(--bg-surface); border:1px solid var(--border); padding:2px 5px; border-radius:4px; opacity:0; transition:opacity .18s; pointer-events:none; }
.cb:hover .cb-tooltip { opacity:1; }
.cb-label { font-family:'Space Mono',monospace; font-size:.55rem; color:var(--text-muted); text-align:center; }
/* ── PROGRESS ── */
.prog-wrap { background:rgba(255,255,255,.06); border-radius:100px; height:5px; overflow:hidden; }
.prog { height:100%; border-radius:100px; background:linear-gradient(90deg,var(--cyan),var(--violet)); transition:width 1s ease; box-shadow:0 0 8px rgba(0,212,255,.35); }
.prog.green { background:linear-gradient(90deg,var(--neon),#00a882); }
/* ── BOOKS GRID ── */
.books-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(165px,1fr)); gap:.9rem; }
.book-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-md); overflow:hidden; transition:transform .22s,border-color .22s,box-shadow .22s; animation:slideUp .5s ease both; }
.book-card:hover { transform:translateY(-5px); border-color:rgba(0,212,255,.22); box-shadow:0 14px 38px rgba(0,0,0,.38); }
.book-cover { height:100px; display:flex; align-items:center; justify-content:center; font-size:2.3rem; position:relative; background:linear-gradient(135deg,rgba(13,16,32,.8),rgba(124,58,237,.18)); }
.book-cover::after { content:''; position:absolute; inset:0; background:linear-gradient(to bottom,transparent 40%,var(--bg-surface)); }
.book-body { padding:9px 11px 10px; }
.book-genre { font-family:'Space Mono',monospace; font-size:.56rem; color:var(--cyan); letter-spacing:.06em; text-transform:uppercase; }
.book-title { font-family:'Syne',sans-serif; font-size:.8rem; font-weight:700; margin:3px 0 2px; line-height:1.2; }
.book-author { font-size:.68rem; color:var(--text-secondary); }
.book-footer { padding:7px 11px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
/* ── READING ITEMS ── */
.reading-item { display:flex; align-items:center; gap:13px; padding:10px 0; border-bottom:1px solid rgba(255,255,255,.04); }
.reading-item:last-child { border-bottom:none; }
.ri-emoji { font-size:1.7rem; flex-shrink:0; }
.ri-info { flex:1; min-width:0; }
.ri-title { font-family:'Syne',sans-serif; font-weight:700; font-size:.83rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ri-date { font-size:.65rem; color:var(--text-muted); font-family:'Space Mono',monospace; margin-top:2px; }
.ri-prog { margin-top:5px; display:flex; align-items:center; gap:7px; }
.ri-pct { font-family:'Space Mono',monospace; font-size:.62rem; color:var(--text-muted); flex-shrink:0; }
/* ── NOTIF PANEL ── */
#notif-panel {
  position:fixed; top:var(--topbar-h); right:1rem; width:300px;
  background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-lg);
  box-shadow:var(--shadow-lg); z-index:500;
  transform:translateY(-10px) scale(.97); opacity:0; pointer-events:none;
  transition:all .22s cubic-bezier(.34,1.56,.64,1); overflow:hidden;
}
#notif-panel.open { transform:translateY(6px) scale(1); opacity:1; pointer-events:all; }
.np-header { padding:.9rem 1.1rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; font-family:'Syne',sans-serif; font-weight:700; font-size:.83rem; }
.np-list { max-height:280px; overflow-y:auto; }
.np-item { display:flex; gap:10px; padding:10px 1.1rem; border-bottom:1px solid rgba(255,255,255,.04); font-size:.76rem; cursor:pointer; transition:background .12s; }
.np-item:hover { background:var(--bg-card-hov); }
.np-icon { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.82rem; flex-shrink:0; }
.np-text { color:var(--text-secondary); line-height:1.45; }
.np-text strong { color:var(--text-primary); }
.np-time { font-size:.6rem; font-family:'Space Mono',monospace; color:var(--text-muted); }
.np-unread { background:rgba(0,212,255,.04); }
/* ── TOAST ── */
#toast-stack { position:fixed; bottom:1.4rem; right:1.4rem; z-index:9000; display:flex; flex-direction:column-reverse; gap:7px; pointer-events:none; }
.toast { display:flex; align-items:center; gap:9px; padding:10px 14px; background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-md); box-shadow:var(--shadow-lg); font-size:.78rem; max-width:290px; pointer-events:all; transform:translateX(110px); opacity:0; transition:all .35s cubic-bezier(.34,1.56,.64,1); }
.toast.show { transform:translateX(0); opacity:1; }
.t-icon { font-size:1.05rem; flex-shrink:0; }
.t-body { flex:1; }
.t-title { font-family:'Syne',sans-serif; font-weight:700; }
.t-sub { color:var(--text-muted); font-size:.7rem; margin-top:1px; }
.t-close { color:var(--text-muted); cursor:pointer; font-size:.78rem; }
/* ── AI PANEL ── */
#ai-panel {
  position:fixed; right:1.4rem; bottom:4.8rem; width:360px; height:540px;
  background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-xl);
  box-shadow:var(--shadow-lg); z-index:800; display:flex; flex-direction:column;
  transform:translateY(28px) scale(.95); opacity:0; pointer-events:none;
  transition:all .32s cubic-bezier(.34,1.56,.64,1); overflow:hidden;
}
#ai-panel.open { transform:translateY(0) scale(1); opacity:1; pointer-events:all; }
.ai-header { padding:.9rem 1.1rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:9px; background:rgba(0,0,0,.18); flex-shrink:0; }
.ai-avatar { width:34px; height:34px; border-radius:11px; background:linear-gradient(135deg,var(--cyan),var(--violet)); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; animation:aiPulse 3s ease-in-out infinite; }
@keyframes aiPulse{0%,100%{box-shadow:0 0 0 0 rgba(0,212,255,.4)}50%{box-shadow:0 0 0 7px rgba(0,212,255,0)}}
.ai-title { font-family:'Syne',sans-serif; font-weight:700; font-size:.87rem; }
.ai-sub { font-size:.62rem; color:var(--neon); font-family:'Space Mono',monospace; }
.ai-actions { margin-left:auto; display:flex; gap:3px; }
.ai-act { width:26px; height:26px; border-radius:7px; background:var(--bg-card); border:1px solid var(--border); color:var(--text-muted); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:.75rem; transition:all .18s; }
.ai-act:hover { color:var(--text-primary); background:var(--bg-card-hov); }
.ai-msgs { flex:1; overflow-y:auto; padding:.9rem; display:flex; flex-direction:column; gap:10px; scroll-behavior:smooth; }
.msg-bubble { max-width:85%; padding:9px 13px; border-radius:13px; font-size:.8rem; line-height:1.55; animation:msgPop .28s cubic-bezier(.34,1.56,.64,1) both; }
@keyframes msgPop{from{transform:scale(.85) translateY(10px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
.msg-bubble.user { align-self:flex-end; background:linear-gradient(135deg,rgba(0,212,255,.2),rgba(124,58,237,.2)); border:1px solid rgba(0,212,255,.2); border-bottom-right-radius:4px; color:var(--text-primary); }
.msg-bubble.bot { align-self:flex-start; background:var(--bg-card); border:1px solid var(--border); border-bottom-left-radius:4px; color:var(--text-secondary); }
.msg-meta { font-size:.58rem; font-family:'Space Mono',monospace; color:var(--text-muted); margin-top:3px; }
.msg-meta.r { text-align:right; }
.typing-dots { align-self:flex-start; background:var(--bg-card); border:1px solid var(--border); border-radius:13px; border-bottom-left-radius:4px; padding:10px 14px; display:flex; gap:4px; align-items:center; }
.td-dot { width:5px; height:5px; background:var(--cyan); border-radius:50%; animation:tb 1.2s ease-in-out infinite; }
.td-dot:nth-child(2){animation-delay:.18s} .td-dot:nth-child(3){animation-delay:.36s}
@keyframes tb{0%,60%,100%{transform:translateY(0);opacity:.5}30%{transform:translateY(-5px);opacity:1}}
.ai-suggs { padding:.4rem .9rem; display:flex; gap:5px; flex-wrap:nowrap; overflow-x:auto; flex-shrink:0; border-top:1px solid rgba(255,255,255,.04); }
.ai-sugg { font-size:.65rem; padding:4px 10px; border-radius:100px; background:var(--bg-card); border:1px solid var(--border); color:var(--text-muted); cursor:pointer; white-space:nowrap; transition:all .18s; font-family:'Space Mono',monospace; }
.ai-sugg:hover { border-color:var(--cyan); color:var(--cyan); background:rgba(0,212,255,.06); }
.ai-input-area { padding:8px 10px; border-top:1px solid var(--border); display:flex; align-items:center; gap:7px; background:rgba(0,0,0,.18); flex-shrink:0; }
#ai-input { flex:1; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-sm); padding:8px 11px; color:var(--text-primary); font-size:.8rem; font-family:'DM Sans',sans-serif; outline:none; transition:border-color .2s,box-shadow .2s; resize:none; min-height:36px; max-height:90px; }
#ai-input:focus { border-color:var(--border-act); box-shadow:var(--glow-cyan); }
#ai-input::placeholder { color:var(--text-muted); }
.ai-send { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--cyan),var(--violet)); border:none; color:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:.88rem; transition:all .18s; flex-shrink:0; }
.ai-send:hover { opacity:.84; transform:scale(1.05); }
#ai-fab { position:fixed; right:1.4rem; bottom:1.4rem; width:52px; height:52px; border-radius:15px; background:linear-gradient(135deg,var(--cyan),var(--violet)); box-shadow:0 8px 30px rgba(0,212,255,.32); display:flex; align-items:center; justify-content:center; font-size:1.35rem; cursor:pointer; z-index:800; transition:all .18s; border:none; color:#fff; animation:fabGlow 3s ease-in-out infinite; }
#ai-fab:hover { transform:scale(1.08); }
@keyframes fabGlow{0%,100%{box-shadow:0 8px 30px rgba(0,212,255,.32)}50%{box-shadow:0 8px 44px rgba(0,212,255,.52)}}
/* ── PAY MODAL ── */
#pay-modal { position:fixed; inset:0; z-index:1000; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(5,8,15,.88); backdrop-filter:blur(14px); opacity:0; pointer-events:none; transition:opacity .3s; }
#pay-modal.open { opacity:1; pointer-events:all; }
.pay-box { background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-xl); padding:1.8rem; max-width:390px; width:100%; box-shadow:var(--shadow-lg); transform:translateY(20px); transition:transform .32s cubic-bezier(.34,1.56,.64,1); position:relative; overflow:hidden; }
.pay-box::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--neon),var(--cyan),var(--violet)); }
#pay-modal.open .pay-box { transform:translateY(0); }
.pay-title { font-family:'Syne',sans-serif; font-weight:800; font-size:1.2rem; margin-bottom:.4rem; }
.pay-methods { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:1rem 0; }
.pay-method { padding:12px; border-radius:var(--r-md); border:2px solid var(--border); background:var(--bg-card); cursor:pointer; text-align:center; transition:all .18s; }
.pay-method:hover,.pay-method.selected { border-color:var(--neon); background:rgba(0,255,170,.05); }
.pm-icon { font-size:1.5rem; margin-bottom:3px; }
.pm-name { font-family:'Syne',sans-serif; font-weight:700; font-size:.72rem; }
.pm-sub { font-size:.6rem; color:var(--text-muted); font-family:'Space Mono',monospace; }
.pay-label { font-size:.68rem; font-family:'Space Mono',monospace; color:var(--text-muted); margin-bottom:5px; display:block; letter-spacing:.05em; text-transform:uppercase; }
.pay-input { width:100%; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-sm); padding:9px 13px; color:var(--text-primary); font-size:.83rem; font-family:'DM Sans',sans-serif; outline:none; margin-bottom:.9rem; transition:border-color .2s; }
.pay-input:focus { border-color:var(--border-act); box-shadow:var(--glow-cyan); }
.pay-amount { font-family:'Syne',sans-serif; font-size:1.55rem; font-weight:800; color:var(--neon); text-align:center; margin:.8rem 0; }
.pay-amount span { font-size:.85rem; color:var(--text-muted); }
/* ── ALERTS ── */
.alert-error { background:rgba(244,63,94,.08); border:1px solid rgba(244,63,94,.2); border-radius:var(--r-md); padding:1rem 1.4rem; color:var(--rose); font-size:.85rem; margin-bottom:1.5rem; display:flex; align-items:center; gap:10px; }
.alert-warn { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); border-radius:var(--r-md); padding:1rem 1.4rem; color:var(--amber); font-size:.85rem; margin-bottom:1.5rem; display:flex; align-items:center; gap:10px; }
/* ── ANIMATIONS ── */
@keyframes slideUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
@keyframes shimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}
/* ── MOBILE ── */
#sidebar-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:199; opacity:0; pointer-events:none; transition:opacity .3s; }
#sidebar-overlay.show { opacity:1; pointer-events:all; }
@media(max-width:768px){
  #sidebar{transform:translateX(calc(-1 * var(--sidebar-w)));width:var(--sidebar-w) !important}
  #sidebar.mobile-open{transform:translateX(0)}
  .main-content,.main-content.collapsed{margin-left:0 !important}
  .tb-ham{display:flex} .tb-search{width:150px}
  .stats-grid{grid-template-columns:1fr 1fr}
  #ai-panel{width:calc(100vw - 2rem);right:1rem}
  .page-content{padding:1.2rem .9rem}
  .welcome-banner{flex-direction:column;align-items:flex-start}
}
@media(max-width:480px){
  .stats-grid{grid-template-columns:1fr} .tb-search{display:none}
  .books-grid{grid-template-columns:1fr 1fr}
}
</style>
<style>
:root{

    --primary:
    <?= htmlspecialchars($settings['primary_color'] ?? '#7c3aed') ?>;

}

:root {
    --primary: <?= htmlspecialchars($settings['primary_color'] ?? '#7c3aed') ?>;
}

</style>

</head>
</head>
<body>

<body class="<?= htmlspecialchars($settings['dashboard_theme'] ?? 'dark') ?>"></body>

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<aside id="sidebar" role="navigation" aria-label="Menu principal">
  <div class="sidebar-brand">
    <div class="brand-icon" aria-hidden="true">📚</div>
    <div class="brand-text">Digital <em>Library</em></div>
  </div>
  <div class="sidebar-user">
    <div class="user-avatar" aria-hidden="true"><?= $avatar ?></div>
    <div class="user-info">
      <div class="user-name"><?= $username ?></div>
      <div class="role-pill"><?= $roleIcon ?> <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Principal</div>
    <a href="dashboard.php" class="nav-item active" aria-current="page">
      <span class="nav-icon"><i class="bi bi-grid-1x2"></i></span>
      <span class="nav-label">Dashboard</span>
    </a>
    <a href="users/profile.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-person"></i></span>
      <span class="nav-label">Mon profil</span>
    </a>

    <?php if ($userRole === 'admin'): ?>
    <div class="nav-section">Administration</div>
    <a href="users/index.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-people"></i></span>
      <span class="nav-label">Utilisateurs</span>
      <?php $uc = (int)($data['stats']['totalUsers'] ?? 0); if ($uc): ?>
        <span class="nav-badge"><?= $uc > 999 ? round($uc/1000,1).'K' : $uc ?></span>
      <?php endif; ?>
    </a>
    <a href="books/index.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-book-half"></i></span>
      <span class="nav-label">Gestion livres</span>
    </a>
    <a href="books/create.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-plus-square"></i></span>
      <span class="nav-label">Ajouter un livre</span>
    </a>
    <a href="admin/sales.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-cash-coin"></i></span>
      <span class="nav-label">Ventes</span>
    </a>
    <a href="admin/categories.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-tags"></i></span>
      <span class="nav-label">Catégories</span>
    </a>
    <a href="stats/index.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-graph-up-arrow"></i></span>
      <span class="nav-label">Statistiques</span>
    </a>
    <a href="admin/settings.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-gear"></i></span>
      <span class="nav-label">Paramètres</span>
    </a>

    <?php elseif ($userRole === 'journaliste'): ?>
    <div class="nav-section">Rédaction</div>
    <a href="books/create.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-plus-square"></i></span>
      <span class="nav-label">Ajouter un livre</span>
    </a>
    <a href="books/my_books.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-book-half"></i></span>
      <span class="nav-label">Mes livres</span>
      <?php $jt = (int)($data['stats']['total'] ?? 0); if ($jt): ?>
        <span class="nav-badge"><?= $jt ?></span>
      <?php endif; ?>
    </a>
    <a href="books/index.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-compass"></i></span>
      <span class="nav-label">Catalogue</span>
    </a>
    <a href="admin/reviews.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-chat-dots"></i></span>
      <span class="nav-label">Avis &amp; commentaires</span>
      <?php $pc = (int)($data['stats']['commentsPending'] ?? 0); if ($pc): ?>
        <span class="nav-badge"><?= $pc ?></span>
      <?php endif; ?>
    </a>
    <div class="nav-section">Analyse</div>
    <a href="stats/journalist.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-bar-chart"></i></span>
      <span class="nav-label">Mes statistiques</span>
    </a>

    <?php elseif ($userRole === 'lecteur'): ?>
    <div class="nav-section">Bibliothèque</div>
    <a href="books/index.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-compass"></i></span>
      <span class="nav-label">Catalogue</span>
    </a>
    <a href="books/my_library.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-bookmark-heart"></i></span>
      <span class="nav-label">Ma bibliothèque</span>
      <?php $bp = (int)($data['stats']['booksPurchased'] ?? 0); if ($bp): ?>
        <span class="nav-badge"><?= $bp ?></span>
      <?php endif; ?>
    </a>
    <a href="books/reading.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-book-fill"></i></span>
      <span class="nav-label">En cours</span>
    </a>
    <div class="nav-section">Personnel</div>
    <a href="users/purchases.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-bag-check"></i></span>
      <span class="nav-label">Mes achats</span>
    </a>
    
    </a>
    <?php endif; ?>

    <div class="nav-section">Support</div>
    <a href="#" class="nav-item" onclick="document.getElementById('ai-panel').classList.toggle('open');return false;">
      <span class="nav-icon"><i class="bi bi-robot"></i></span>
      <span class="nav-label">Assistant IA</span>
    </a>
    <a href="admin/notifications.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-bell"></i></span>
      <span class="nav-label">Notifications</span>
      <?php if ($notifCount): ?>
        <span class="nav-badge"><?= $notifCount ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section">Compte</div>
    <a href="logout1.php" class="nav-item">
      <span class="nav-icon"><i class="bi bi-box-arrow-left"></i></span>
      <span class="nav-label">Déconnexion</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <button type="button" class="btn-collapse" onclick="toggleSidebar()" aria-label="Réduire">
      <span class="ci"><i class="bi bi-chevron-left"></i></span>
      <span class="cl">Réduire</span>
    </button>
  </div>
</aside>

<div id="sidebar-overlay" onclick="closeMobileSidebar()" aria-hidden="true"></div>

<!-- ═══════════════════ MAIN ═══════════════════ -->
<div class="main-content" id="main-content">

  <!-- TOPBAR -->
  <header id="topbar" role="banner">
    <button type="button" class="tb-ham" onclick="toggleMobileSidebar()" aria-label="Menu"><i class="bi bi-list"></i></button>
    <div class="tb-breadcrumb">
      <span>DLS</span><span class="bc-sep">/</span>
      <span class="bc-curr">Dashboard</span>
    </div>
    <div class="tb-spacer"></div>
    <div class="tb-search-wrap">
      <div class="tb-search" role="search">
        <i class="bi bi-search si"></i>
        <input type="search" placeholder="Recherche rapide…" id="search-input"
               autocomplete="off" aria-label="Recherche rapide"
               oninput="debounceSearch(this.value)" onkeydown="handleSearchKey(event)">
      </div>
      <div id="search-results" role="listbox" aria-label="Résultats de recherche"></div>
    </div>
    <div class="tb-actions">
      <button type="button" class="tb-btn" id="notif-btn" onclick="toggleNotifPanel()"
              aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
        <i class="bi bi-bell"></i>
        <?php if ($notifCount > 0): ?>
          <span class="notif-badge"><?= min($notifCount, 9) ?></span>
        <?php endif; ?>
      </button>
      <a href="users/profile.php" class="tb-user" aria-label="Mon profil">
        <div class="tu-av"><?= $avatar ?></div>
        <div>
          <div class="tu-name"><?= $firstName ?></div>
          <div class="tu-role"><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </a>
      <a href="index.php" class="tb-btn" title="Déconnexion" aria-label="Déconnexion">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    </div>
  </header>

  <!-- ═══════ PAGE CONTENT ═══════ -->
  <main class="page-content" role="main">

  <?php if (!$pdo): ?>
  <div class="alert-warn"><i class="bi bi-exclamation-triangle-fill"></i>
    Base de données inaccessible — Vérifiez <code>includes/config.php</code> et que MySQL est démarré.
  </div>
  <?php elseif (isset($data['error'])): ?>
  <div class="alert-error"><i class="bi bi-exclamation-triangle-fill"></i>
    <?= htmlspecialchars($data['error'], ENT_QUOTES, 'UTF-8') ?>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════
       ADMIN DASHBOARD
  ══════════════════════════════════════════ -->
  <?php if ($userRole === 'admin'):
    $s     = $data['stats']    ?? [];
    $chart = $data['chart']    ?? [];
    $users = $data['users']    ?? [];
  ?>

  <div class="welcome-banner">
    <div>
      <div class="wb-title">Bonjour, <?= $firstName ?> ⚡</div>
      <div class="wb-sub">Aperçu en temps réel de votre plateforme • <?= date('d M Y') ?>
        <?php if ($userEmail): ?> — <?= $userEmail ?><?php endif; ?>
      </div>
      <div class="wb-pill"><i class="bi bi-shield-check"></i> Administrateur — Accès total</div>
    </div>
    <div class="wb-actions">
      <a href="books/create.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Ajouter un livre</a>
      <a href="stats/index.php"  class="btn btn-ghost"><i class="bi bi-file-earmark-arrow-down"></i> Rapports</a>
    </div>
  </div>

  <!-- Stats admin -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(0,212,255,.1)">👥</div>
      <div class="stat-value"><?= number_format((int)($s['totalUsers'] ?? 0)) ?></div>
      <div class="stat-label">Utilisateurs inscrits</div>
      <div class="stat-change up"><i class="bi bi-arrow-up-short"></i> +<?= (int)($s['newUsersMonth'] ?? 0) ?> ce mois</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(124,58,237,.1)">📚</div>
      <div class="stat-value"><?= number_format((int)($s['totalBooks'] ?? 0)) ?></div>
      <div class="stat-label">Livres dans le catalogue</div>
      <div class="stat-change up"><i class="bi bi-arrow-up-short"></i> +<?= (int)($s['newBooksWeek'] ?? 0) ?> cette semaine</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(0,255,170,.08)">💰</div>
      <div class="stat-value"><?= number_format((int)($s['totalSales'] ?? 0)) ?></div>
      <div class="stat-label">Ventes confirmées</div>
      <?php $sv = (float)($s['salesVariation'] ?? 0); ?>
      <div class="stat-change <?= $sv >= 0 ? 'up' : 'down' ?>">
        <i class="bi bi-arrow-<?= $sv >= 0 ? 'up' : 'down' ?>-short"></i> <?= abs($sv) ?>% vs mois dernier
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(245,158,11,.1)">🟢</div>
      <div class="stat-value"><?= (int)($s['activeToday'] ?? 0) ?></div>
      <div class="stat-label">Actifs aujourd'hui</div>
      <div class="stat-change neutral"><i class="bi bi-dot"></i> Acheteurs du jour</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(244,63,94,.08)">💳</div>
      <div class="stat-value" style="font-size:1.05rem"><?= htmlspecialchars($s['revenueMonth'] ?? '0 FCFA', ENT_QUOTES, 'UTF-8') ?></div>
      <div class="stat-label">Revenus du mois</div>
      <?php $rv = (float)($s['revVariation'] ?? 0); ?>
      <div class="stat-change <?= $rv >= 0 ? 'up' : 'down' ?>">
        <i class="bi bi-arrow-<?= $rv >= 0 ? 'up' : 'down' ?>-short"></i> <?= abs($rv) ?>% vs mois dernier
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(0,212,255,.06)">🗂️</div>
      <?php



?>
      <div class="stat-value"><?= dbCount("SELECT COUNT(*) FROM categories") ?></div>
      <div class="stat-label">Catégories actives</div>
      <div class="stat-change neutral"><i class="bi bi-dot"></i> Catalogue organisé</div>
    </div>
    
  </div>

  <!-- Grid activité + chart -->
  <div class="dash-grid">
    <div class="card">
      <div class="card-header">
        <div class="card-title"><div class="ct-icon" style="background:rgba(0,212,255,.1)">⚡</div>Activité récente</div>
        <a href="admin/logs.php" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-right"></i> Voir tout</a>
      </div>
      <div class="card-body">
        <div class="act-list">
          <?php if (empty($data['activity'])): ?>
            <p style="color:var(--text-muted);font-size:.8rem;text-align:center;padding:1rem 0">Aucun achat récent</p>
          <?php else: foreach ($data['activity'] as $a): ?>
          <div class="act-item">
            <div class="act-dot" style="color:<?= htmlspecialchars($a['color'] ?? '#fff', ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($a['icon'] ?? '•', ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="flex:1">
              <div class="act-msg"><?= $a['msg'] ?></div>
              <div class="act-time"><?= htmlspecialchars($a['time_ago'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title"><div class="ct-icon" style="background:rgba(0,255,170,.08)">📈</div>Ventes — 7 derniers jours</div>
        <?php
        $chartV = array_column($chart, 'ventes');
        $totalW = !empty($chartV) ? array_sum($chartV) : 0;
        $maxVA  = max(1, ...(array_map('intval', $chartV) ?: [1]));
        $avgD   = $totalW > 0 ? round($totalW / 7, 1) : 0;
        ?>
        <span class="chip chip-success"><?= (int)$totalW ?> ventes</span>
      </div>
      <div class="card-body">
        <div class="chart-bars" role="img" aria-label="Ventes sur 7 jours">
          <?php foreach ($chart as $day):
            $h = max(4, round(((int)($day['ventes'] ?? 0) / $maxVA) * 68));
          ?>
          <div class="cb-wrap">
            <div class="cb" style="height:<?= $h ?>px">
              <span class="cb-tooltip"><?= (int)$day['ventes'] ?> ventes</span>
            </div>
            <div class="cb-label"><?= htmlspecialchars($day['label'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:.8rem;font-size:.7rem;color:var(--text-muted)">
          <span>Total : <strong style="color:var(--neon)"><?= (int)$totalW ?> ventes</strong></span>
          <span>Moy./jour : <strong style="color:var(--cyan)"><?= $avgD ?></strong></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Tableau utilisateurs -->
  <div class="card" style="margin-bottom:1.5rem;animation-delay:.15s">
    <div class="card-header">
      <div class="card-title"><div class="ct-icon" style="background:rgba(124,58,237,.1)">👥</div>Gestion des utilisateurs</div>
      <div style="display:flex;gap:6px">
        <a href="users/index.php" class="btn btn-ghost btn-sm"><i class="bi bi-list"></i> Tous</a>
        <a href="users/create.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> Ajouter</a>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="data-table" aria-label="Liste des utilisateurs">
        <thead>
          <tr>
            <th>#</th><th>Nom</th><th>Email</th><th>Rôle</th>
            <th>Statut</th><th>Inscription</th><th>Achats</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:2rem">Aucun utilisateur</td></tr>
          <?php else: foreach ($users as $u): ?>
          <tr>
            <td class="td-p">#<?= (int)$u['id'] ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:7px">
                <div style="width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.68rem;color:#fff;flex-shrink:0">
                  <?= strtoupper(substr(htmlspecialchars($u['prenom'] ?? '?', ENT_QUOTES, 'UTF-8'), 0, 1)) ?>
                </div>
                <span class="td-p"><?= htmlspecialchars(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
            </td>
            <td><?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="chip <?= renderRoleBadge($u['role'] ?? '') ?>"><?= htmlspecialchars($u['role'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><span class="chip <?= renderStatutBadge($u['statut'] ?? '') ?>"><?= htmlspecialchars($u['statut'] ?? 'actif', ENT_QUOTES, 'UTF-8') ?></span></td>
            <td style="font-family:'Space Mono',monospace;font-size:.68rem">
              <?= !empty($u['created_at']) ? date('d/m/Y', strtotime($u['created_at'])) : '—' ?>
            </td>
            <td class="td-p"><?= (int)($u['nb_achats'] ?? 0) ?></td>
            <td>
              <div style="display:flex;gap:3px">
                <a href="users/edit.php?id=<?= (int)$u['id'] ?>" class="btn btn-ghost btn-sm" aria-label="Modifier"><i class="bi bi-pencil"></i></a>
                <a href="users/view.php?id=<?= (int)$u['id'] ?>" class="btn btn-ghost btn-sm" aria-label="Voir"><i class="bi bi-eye"></i></a>
                <a href="users/delete.php?id=<?= (int)$u['id'] ?>" class="btn btn-danger btn-sm"
                   onclick="return confirm('Supprimer cet utilisateur ?')" aria-label="Supprimer">
                  <i class="bi bi-trash3"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <span style="font-size:.73rem;color:var(--text-muted)">
        <?= count($users) ?> / <?= (int)($data['usersMeta']['total'] ?? 0) ?> utilisateurs
      </span>
      <div style="display:flex;gap:4px">
        <a href="users/index.php?page=1" class="btn btn-primary btn-sm">1</a>
        <a href="users/index.php?page=2" class="btn btn-ghost btn-sm">2</a>
        <a href="users/index.php?page=2" class="btn btn-ghost btn-sm"><i class="bi bi-chevron-right"></i></a>
      </div>
    </div>
  </div>

  <!-- Derniers livres -->
  <div class="card" style="animation-delay:.2s">
    <div class="card-header">
      <div class="card-title"><div class="ct-icon" style="background:rgba(0,212,255,.1)">📚</div>Derniers livres ajoutés</div>
      <a href="books/index.php" class="btn btn-ghost btn-sm">Voir tout <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="card-body">
      <?php
      $recentBooks = getLatestBooks(6);
      $bookEmojis  = ['📖','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌊'];
      if (empty($recentBooks)):
      ?>
      <p style="color:var(--text-muted);font-size:.8rem;text-align:center;padding:1rem 0">Aucun livre dans le catalogue</p>
      <?php else: ?>
      <div class="books-grid">
        <?php foreach ($recentBooks as $i => $book):
          $emoji     = $bookEmojis[$i % count($bookEmojis)];
          $cat       = htmlspecialchars($book['categorie_nom'] ?? 'Général', ENT_QUOTES, 'UTF-8');
          $price     = (float)($book['prix'] ?? 0) > 0 ? fmtFCFA((float)$book['prix']) : 'Gratuit';
          $statusCls = ($book['statut'] ?? '') === 'disponible' ? 'chip-success' : 'chip-muted';
        ?>
        <div class="book-card">
          <div class="book-cover"><?= $emoji ?></div>
          <div class="book-body">
            <div class="book-genre"><?= $cat ?></div>
            <div class="book-title"><?= htmlspecialchars($book['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="book-author"><?= htmlspecialchars($book['auteur'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div class="book-footer">
            <span class="chip <?= $statusCls ?>"><?= htmlspecialchars($price, ENT_QUOTES, 'UTF-8') ?></span>
            <div style="display:flex;gap:3px">
              <a href="books/edit.php?id=<?= (int)$book['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-pencil"></i></a>
              <a href="books/view.php?id=<?= (int)$book['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i></a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══════════════════════════════════════════
       JOURNALISTE DASHBOARD
  ══════════════════════════════════════════ -->
  <?php elseif ($userRole === 'journaliste'):
    $s = $data['stats'] ?? [];
  ?>

  <div class="welcome-banner">
    <div>
      <div class="wb-title">Bienvenue, <?= $firstName ?> ✍️</div>
      <div class="wb-sub">Gérez vos publications • <?= date('d M Y') ?></div>
      <div class="wb-pill"><i class="bi bi-pen"></i> Journaliste — Espace éditorial</div>
    </div>
    <div class="wb-actions">
      <a href="books/create.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Ajouter un livre</a>
      <a href="stats/journalist.php" class="btn btn-ghost"><i class="bi bi-graph-up"></i> Mes stats</a>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(245,158,11,.1)">📝</div>
      <div class="stat-value"><?= (int)($s['total'] ?? 0) ?></div>
      <div class="stat-label">Livres publiés</div>
      <div class="stat-change neutral"><i class="bi bi-dot"></i> Total</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(0,255,170,.08)">✅</div>
      <div class="stat-value"><?= (int)($s['published'] ?? 0) ?></div>
      <div class="stat-label">Disponibles</div>
      <div class="stat-change up"><i class="bi bi-dot"></i> En ligne</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(124,58,237,.1)">📁</div>
      <div class="stat-value"><?= (int)($s['draft'] ?? 0) ?></div>
      <div class="stat-label">Archivés</div>
      <div class="stat-change neutral"><i class="bi bi-dot"></i> Non visibles</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(0,212,255,.1)">🛍️</div>
      <div class="stat-value"><?= (int)($s['views'] ?? 0) ?></div>
      <div class="stat-label">Ventes générées</div>
      <div class="stat-change up"><i class="bi bi-arrow-up-short"></i> Total</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(0,255,170,.06)">💰</div>
      <div class="stat-value" style="font-size:1rem"><?= fmtFCFA((float)($s['revenus'] ?? 0)) ?></div>
      <div class="stat-label">Revenus générés</div>
      <div class="stat-change up"><i class="bi bi-dot"></i> Total confirmé</div>
    </div>
  </div>

  <div class="dash-grid">
    <!-- Mes publications -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><div class="ct-icon" style="background:rgba(245,158,11,.1)">📄</div>Mes publications récentes</div>
        <a href="books/create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> Ajouter</a>
      </div>
      <div style="overflow-x:auto">
        <table class="data-table">
          <thead><tr><th>Titre</th><th>Statut</th><th>Ventes</th><th>Note</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (empty($s['livres'])): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:2rem">Aucun livre publié</td></tr>
            <?php else: foreach ($s['livres'] as $l): ?>
            <tr>
              <td class="td-p" style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= htmlspecialchars($l['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td>
                <?php $ls=$l['statut']??''; $sc=$ls==='disponible'?'chip-success':($ls==='archive'?'chip-muted':'chip-warn'); ?>
                <span class="chip <?= $sc ?>"><?= htmlspecialchars(ucfirst($ls), ENT_QUOTES, 'UTF-8') ?></span>
              </td>
              <td class="td-p"><?= (int)($l['achats_count'] ?? 0) ?></td>
              <td style="font-family:'Space Mono',monospace;font-size:.68rem;color:var(--amber)">
                <?= number_format((float)($l['note_moyenne'] ?? 0), 1) ?>/5
              </td>
              <td>
                <div style="display:flex;gap:3px">
                  <a href="books/edit.php?id=<?= (int)$l['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-pencil"></i></a>
                  <a href="books/view.php?id=<?= (int)$l['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i></a>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        <a href="books/my_books.php" class="btn btn-ghost btn-sm">Voir tous <i class="bi bi-arrow-right"></i></a>
        <a href="books/index.php" class="btn btn-ghost btn-sm"><i class="bi bi-compass"></i> Catalogue</a>
      </div>
    </div>

    <!-- Chart ventes par livre -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><div class="ct-icon" style="background:rgba(245,158,11,.1)">📊</div>Ventes par livre</div>
      </div>
      <div class="card-body">
        <?php
        $chartJ  = $s['chartData'] ?? [];
        $chartJV = array_column($chartJ, 'ventes');
        $maxVJ   = max(1, ...(array_map('intval', $chartJV) ?: [1]));
        ?>
        <?php if (empty($chartJ)): ?>
        <p style="color:var(--text-muted);font-size:.8rem;text-align:center;padding:1.5rem 0">Aucune donnée de vente</p>
        <?php else: ?>
        <div class="chart-bars">
          <?php foreach ($chartJ as $cd):
            $h = max(4, round(((int)($cd['ventes'] ?? 0) / $maxVJ) * 68));
          ?>
          <div class="cb-wrap">
            <div class="cb" style="height:<?= $h ?>px;background:linear-gradient(to top,var(--amber),rgba(245,158,11,.3))">
              <span class="cb-tooltip"><?= (int)$cd['ventes'] ?> ventes</span>
            </div>
            <div class="cb-label" style="max-width:36px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <?= htmlspecialchars(mb_substr($cd['titre'] ?? '', 0, 5), ENT_QUOTES, 'UTF-8') ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:1rem">
          <a href="stats/journalist.php" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center">
            <i class="bi bi-graph-up-arrow"></i> Statistiques détaillées
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Avis récents -->
  <div class="card" style="animation-delay:.15s">
    <div class="card-header">
      <div class="card-title">
        <div class="ct-icon" style="background:rgba(244,63,94,.08)">💬</div>
        Avis récents sur mes livres
        <?php $cp = (int)($s['commentsPending'] ?? 0); if ($cp > 0): ?>
          <span class="chip chip-warn"><?= $cp ?> en attente</span>
        <?php endif; ?>
      </div>
      <a href="admin/reviews.php" class="btn btn-ghost btn-sm">Voir tout</a>
    </div>
    <div class="card-body">
      <?php if (empty($s['comments'])): ?>
      <p style="color:var(--text-muted);font-size:.8rem;text-align:center;padding:1rem 0">Aucun avis pour le moment</p>
      <?php else: foreach ($s['comments'] as $c): ?>
      <div class="act-item">
        <div class="act-dot">💬</div>
        <div style="flex:1">
          <div class="act-msg">
            <strong><?= htmlspecialchars(trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?></strong>
            sur <em><?= htmlspecialchars($c['livre_titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></em>
            <?php if (!empty($c['commentaire'])): ?>
              — <?= htmlspecialchars(mb_substr($c['commentaire'], 0, 80), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($c['commentaire']) > 80 ? '…' : '' ?>
            <?php endif; ?>
            <?php if (!empty($c['note'])): ?>
              <span style="color:var(--amber)">⭐<?= (int)$c['note'] ?>/5</span>
            <?php endif; ?>
          </div>
          <div class="act-time"><?= timeAgo($c['created_at'] ?? '') ?></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ══════════════════════════════════════════
       LECTEUR DASHBOARD
  ══════════════════════════════════════════ -->
  <?php elseif ($userRole === 'lecteur'):
    $s = $data['stats'] ?? [];
    $purchasedIds = array_map('intval', array_column($s['history'] ?? [], 'id'));
  ?>

  <div class="welcome-banner">
    <div>
      <div class="wb-title">Bonjour, <?= $firstName ?> 📖</div>
      <div class="wb-sub">
        <?php $brc = (int)($s['booksPurchased'] ?? 0); ?>
        <?php if ($brc > 0): ?>
          <?= $brc ?> livre<?= $brc > 1 ? 's' : '' ?> acheté<?= $brc > 1 ? 's' : '' ?> • <?= fmtFCFA((float)($s['totalSpent'] ?? 0)) ?> dépensés
        <?php else: ?>
          Bienvenue dans votre bibliothèque numérique !
        <?php endif; ?>
      </div>
      <div class="wb-pill"><i class="bi bi-book-half"></i> Lecteur — Mon espace</div>
    </div>
    <div class="wb-actions">
      <a href="books/index.php" class="btn btn-primary"><i class="bi bi-compass"></i> Explorer</a>
      <?php if ((int)($s['bonusCount'] ?? 0) > 0): ?>
        <a href="users/bonus.php" class="btn btn-success"><i class="bi bi-gift"></i> <?= (int)$s['bonusCount'] ?> bonus</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(0,255,170,.08)">🛍️</div>
      <div class="stat-value"><?= (int)($s['booksPurchased'] ?? 0) ?></div>
      <div class="stat-label">Livres achetés</div>
      <div class="stat-change up"><i class="bi bi-dot"></i> Ma bibliothèque</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(0,212,255,.1)">💰</div>
      <div class="stat-value" style="font-size:1rem"><?= fmtFCFA((float)($s['totalSpent'] ?? 0)) ?></div>
      <div class="stat-label">Dépenses totales</div>
      <div class="stat-change neutral"><i class="bi bi-dot"></i> Tous achats</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(245,158,11,.1)">🎁</div>
      <div class="stat-value"><?= (int)($s['bonusCount'] ?? 0) ?></div>
      <div class="stat-label">Bonus disponibles</div>
      <div class="stat-change <?= (int)($s['bonusCount'] ?? 0) > 0 ? 'up' : 'neutral' ?>">
        <?php if ((int)($s['bonusCount'] ?? 0) > 0): ?><i class="bi bi-gift"></i> À utiliser !
        <?php else: ?><i class="bi bi-dot"></i> Aucun bonus<?php endif; ?>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(124,58,237,.1)">📚</div>
      <div class="stat-value"><?= count($s['catalogue'] ?? []) ?></div>
      <div class="stat-label">Livres disponibles</div>
      <div class="stat-change up"><i class="bi bi-dot"></i> Dans le catalogue</div>
    </div>
  </div>

  <div class="dash-grid">
    <!-- Achats récents -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><div class="ct-icon" style="background:rgba(0,255,170,.08)">📚</div>Mes achats récents</div>
        <a href="users/purchases.php" class="btn btn-ghost btn-sm">Voir tout <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="card-body">
        <?php $rEmojis = ['📖','🌌','🧠','🌿','⚙️','📜','🎭','🔭']; ?>
        <?php if (empty($s['history'])): ?>
        <div style="text-align:center;padding:2rem 1rem">
          <div style="font-size:2.5rem;margin-bottom:.5rem">📖</div>
          <p style="color:var(--text-muted);font-size:.82rem">Aucun achat pour le moment</p>
          <a href="books/index.php" class="btn btn-primary btn-sm" style="margin-top:.8rem">Explorer le catalogue</a>
        </div>
        <?php else: foreach ($s['history'] as $i => $r): ?>
        <div class="reading-item">
          <div class="ri-emoji"><?= $rEmojis[$i % count($rEmojis)] ?></div>
          <div class="ri-info">
            <div class="ri-title"><?= htmlspecialchars($r['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="ri-date">
              <?= !empty($r['purchase_date']) ? date('d/m/Y', strtotime($r['purchase_date'])) : '—' ?>
              · <?= fmtFCFA((float)($r['montant'] ?? 0)) ?>
            </div>
            <div class="ri-prog">
              <div class="prog-wrap" style="flex:1"><div class="prog green" style="width:100%"></div></div>
              <div class="ri-pct">✓ Acheté</div>
            </div>
          </div>
          <a href="books/read.php?id=<?= (int)$r['id'] ?>" class="btn btn-success btn-sm"><i class="bi bi-book"></i></a>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Recommandations -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><div class="ct-icon" style="background:rgba(0,212,255,.1)">🤖</div>Recommandé pour vous</div>
        <span class="chip chip-info">IA • Personnalisé</span>
      </div>
      <div class="card-body">
        <?php if (empty($s['recommendations'])): ?>
        <p style="color:var(--text-muted);font-size:.8rem;text-align:center;padding:1rem 0">Achetez des livres pour des recommandations personnalisées</p>
        <?php else: $recEmojis = ['🔭','🦋','🌊','🎯','⚡','🌿','📜']; foreach ($s['recommendations'] as $i => $r): $match = min(99, 95 - $i * 3); ?>
        <div class="act-item">
          <div class="act-dot" style="font-size:1.3rem"><?= $recEmojis[$i % count($recEmojis)] ?></div>
          <div style="flex:1">
            <div class="act-msg"><strong><?= htmlspecialchars($r['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong> — <?= htmlspecialchars($r['auteur'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div style="display:flex;align-items:center;gap:7px;margin-top:4px">
              <div class="prog-wrap" style="width:70px"><div class="prog" style="width:<?= $match ?>%"></div></div>
              <span class="act-time"><?= $match ?>% match</span>
              <span class="chip chip-violet" style="font-size:.55rem"><?= htmlspecialchars($r['categorie_nom'] ?? 'Général', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
          </div>
          <?php if ((float)($r['prix'] ?? 0) > 0): ?>
          <button type="button" class="btn btn-primary btn-sm"
                  onclick="showPaymentModal(<?= (int)$r['id'] ?>, <?= json_encode($r['titre'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= (float)$r['prix'] ?>)">
            <i class="bi bi-bag"></i>
          </button>
          <?php else: ?>
          <a href="books/read.php?id=<?= (int)$r['id'] ?>" class="btn btn-success btn-sm"><i class="bi bi-book"></i></a>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Catalogue top ventes -->
  <div class="card" style="animation-delay:.18s">
    <div class="card-header">
      <div class="card-title"><div class="ct-icon" style="background:rgba(0,212,255,.1)">🌟</div>Catalogue — Meilleures ventes</div>
      <a href="books/index.php" class="btn btn-ghost btn-sm">Tout voir <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="card-body">
      <?php if (empty($s['catalogue'])): ?>
      <p style="color:var(--text-muted);font-size:.82rem;text-align:center;padding:2rem 0">Catalogue vide</p>
      <?php else:
        $catEmojis = ['📖','🌌','🧠','🌿','⚙️','📜','🎭','🔭'];
      ?>
      <div class="books-grid">
        <?php foreach ($s['catalogue'] as $i => $b):
          $emoji  = $catEmojis[$i % count($catEmojis)];
          $cat    = htmlspecialchars($b['categorie_nom'] ?? 'Général', ENT_QUOTES, 'UTF-8');
          $priceF = (float)($b['prix'] ?? 0) > 0 ? fmtFCFA((float)$b['prix']) : 'Gratuit';
          $bought = in_array((int)$b['id'], $purchasedIds, true);
        ?>
        <div class="book-card">
          <div class="book-cover"><?= $emoji ?></div>
          <div class="book-body">
            <div class="book-genre"><?= $cat ?></div>
            <div class="book-title"><?= htmlspecialchars($b['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="book-author"><?= htmlspecialchars($b['auteur'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div class="book-footer">
            <?php if ($bought): ?>
              <span class="chip chip-success">Acheté</span>
              <a href="books/read.php?id=<?= (int)$b['id'] ?>" class="btn btn-success btn-sm"><i class="bi bi-book"></i></a>
            <?php elseif ((float)($b['prix'] ?? 0) == 0): ?>
              <span class="chip chip-info">Gratuit</span>
              <a href="books/read.php?id=<?= (int)$b['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-book"></i></a>
            <?php else: ?>
              <span class="chip chip-muted" style="font-size:.6rem"><?= htmlspecialchars($priceF, ENT_QUOTES, 'UTF-8') ?></span>
              <button type="button" class="btn btn-primary btn-sm"
                      onclick="showPaymentModal(<?= (int)$b['id'] ?>, <?= json_encode($b['titre'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>, <?= (float)$b['prix'] ?>)">
                <i class="bi bi-bag"></i>
              </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
  </main>
</div><!-- /main-content -->

<!-- ═══════════════════ NOTIFICATIONS PANEL ═══════════════════ -->
<div id="notif-panel" role="dialog" aria-label="Notifications" aria-modal="true">
  <div class="np-header">
    <span>Notifications</span>
    <?php if ($notifCount > 0): ?><span class="chip chip-danger"><?= $notifCount ?></span><?php endif; ?>
  </div>
  <div class="np-list">
    <?php $notifs = $data['notifications'] ?? []; if (empty($notifs)): ?>
    <div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:.8rem">
      <div style="font-size:1.8rem;margin-bottom:.4rem">🔔</div>Aucune notification
    </div>
    <?php else: foreach ($notifs as $n):
      $isUnread = !(bool)($n['lu'] ?? false);
      $icon = htmlspecialchars($n['icon'] ?? '🔔', ENT_QUOTES, 'UTF-8');
      $bg   = htmlspecialchars($n['bg']   ?? 'rgba(0,212,255,.08)', ENT_QUOTES, 'UTF-8');
      $text = htmlspecialchars($n['titre'] ?? $n['message'] ?? 'Notification', ENT_QUOTES, 'UTF-8');
      $time = timeAgo($n['created_at'] ?? '');
    ?>
    <div class="np-item <?= $isUnread ? 'np-unread' : '' ?>">
      <div class="np-icon" style="background:<?= $bg ?>"><?= $icon ?></div>
      <div>
        <div class="np-text"><?= $text ?></div>
        <div class="np-time"><?= $time ?></div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <div class="card-footer" style="padding:.7rem 1.1rem">
    <a href="admin/notifications.php" class="btn btn-ghost btn-sm">Voir toutes</a>
    <a href="admin/notifications.php?action=mark_all_read" class="btn btn-ghost btn-sm">Tout lire</a>
  </div>
</div>

<!-- ═══════════════════ AI CHATBOT ═══════════════════ -->
<div id="ai-panel" role="dialog" aria-label="Assistant IA" aria-modal="true">
  <div class="ai-header">
    <div class="ai-avatar">🤖</div>
    <div>
      <div class="ai-title">Assistant DLS</div>
      <div class="ai-sub">● <?= $pdo ? 'BD connectée' : 'Hors ligne' ?></div>
    </div>
    <div class="ai-actions">
      <button type="button" class="ai-act" onclick="resetAI()" title="Nouvelle conversation"><i class="bi bi-trash3"></i></button>
      <button type="button" class="ai-act" onclick="document.getElementById('ai-panel').classList.remove('open')" title="Fermer"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div class="ai-suggs" role="list">
    <?php
    $suggsMap = [
      'admin'       => ["Combien d'utilisateurs ?", 'Top livres vendus', 'CA du mois', 'Livres par catégorie'],
      'journaliste' => ['Mes livres', 'Mon meilleur livre', 'Mes avis', 'Mes ventes'],
      'lecteur'     => ['Recommande un livre', 'Livres gratuits', 'Mes achats', 'Livres populaires'],
    ];
    foreach ($suggsMap[$userRole] ?? [] as $sg):
    ?>
    <div class="ai-sugg" role="listitem" tabindex="0"
         onclick="sendQuick(<?= json_encode($sg, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)"
         onkeydown="if(event.key==='Enter')sendQuick(<?= json_encode($sg, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)">
      <?= htmlspecialchars($sg, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="ai-msgs" id="ai-msgs" role="log" aria-live="polite">
    <div class="msg-bubble bot">
      Bonjour <?= $firstName ?>&nbsp;! 👋 <?= $pdo ? 'Connecté à votre base de données.' : '⚠️ BD inaccessible.' ?>
      <?php if ($userRole === 'admin'): ?>Demandez des stats, des rapports ou des analyses.
      <?php elseif ($userRole === 'journaliste'): ?>Demandez vos performances ou suggestions.
      <?php else: ?>Demandez des recommandations ou vos achats.<?php endif; ?>
    </div>
    <div class="msg-meta">Assistant IA · <?= date('H:i') ?></div>
  </div>
  <div class="ai-input-area">
    <textarea id="ai-input" placeholder="Posez votre question…" rows="1"
              onkeydown="handleAIKey(event)" oninput="autoResize(this)"></textarea>
    <button type="button" class="ai-send" onclick="sendAIMsg()"><i class="bi bi-send"></i></button>
  </div>
</div>

<button type="button" id="ai-fab" onclick="document.getElementById('ai-panel').classList.toggle('open')"
        aria-label="Ouvrir l'assistant IA">🤖</button>

<!-- ═══════════════════ PAYMENT MODAL ═══════════════════ -->
<div id="pay-modal" role="dialog" aria-modal="true" aria-labelledby="pay-modal-title">
  <div class="pay-box">
    <button type="button" style="position:absolute;top:.8rem;right:.8rem;background:none;border:none;color:var(--text-muted);font-size:.95rem;cursor:pointer"
            onclick="closePayModal()"><i class="bi bi-x-lg"></i></button>
    <div class="pay-title" id="pay-modal-title">💳 Paiement Mobile Money</div>
    <p style="font-size:.78rem;color:var(--text-secondary);margin-top:3px">Livre : <strong id="pay-book">—</strong></p>
    <div class="pay-amount"><span>Total :</span> <span id="pay-amt">0</span>&nbsp;FCFA</div>
    <div class="pay-methods">
      <div class="pay-method selected" id="pm-orange" onclick="selMethod('orange',this)" role="radio" aria-checked="true" tabindex="0">
        <div class="pm-icon">🟠</div><div class="pm-name">Orange Money</div><div class="pm-sub">Orange CM</div>
      </div>
      <div class="pay-method" id="pm-mtn" onclick="selMethod('mtn',this)" role="radio" aria-checked="false" tabindex="0">
        <div class="pm-icon">🟡</div><div class="pm-name">MTN MoMo</div><div class="pm-sub">MTN Cameroun</div>
      </div>
    </div>
    <label class="pay-label" for="pay-phone">Numéro de téléphone</label>
    <input type="tel" class="pay-input" id="pay-phone" placeholder="6XX XXX XXX" maxlength="9" inputmode="numeric">
    <label class="pay-label" for="pay-pin">Code PIN</label>
    <input type="password" class="pay-input" id="pay-pin" placeholder="● ● ● ●" maxlength="4" inputmode="numeric">
    <button type="button" class="btn btn-primary" style="width:100%;justify-content:center;padding:13px" onclick="doPayment()">
      <i class="bi bi-lock-fill"></i> Confirmer le paiement
    </button>
    <p style="font-size:.62rem;color:var(--text-muted);text-align:center;margin-top:8px">
      <i class="bi bi-shield-check"></i> Paiement sécurisé · Mode simulation
    </p>
  </div>
</div>

<div id="toast-stack" role="region" aria-live="assertive"></div>

<!-- ═══════════════════ SCRIPTS ═══════════════════ -->
<script>
const USER_ROLE   = <?= json_encode($userRole,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const USER_NAME   = <?= json_encode($firstName, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const CSRF        = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const AI_ENDPOINT = 'ai/chat.php';
const SEARCH_API  = 'api/search.php';
const STATS_API   = 'api/dashboard_stats.php';

function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}

/* ── SIDEBAR ── */
let sidebarCollapsed = false;
function toggleSidebar(){
  sidebarCollapsed = !sidebarCollapsed;
  document.getElementById('sidebar').classList.toggle('collapsed', sidebarCollapsed);
  document.getElementById('main-content').classList.toggle('collapsed', sidebarCollapsed);
}
function toggleMobileSidebar(){
  document.getElementById('sidebar').classList.toggle('mobile-open');
  document.getElementById('sidebar-overlay').classList.toggle('show');
}
function closeMobileSidebar(){
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('sidebar-overlay').classList.remove('show');
}

/* ── TOAST ── */
const TOAST_ICONS   = {info:'ℹ️',success:'✅',warn:'⚠️',error:'🔴'};
const TOAST_BORDERS = {info:'var(--cyan)',success:'var(--neon)',warn:'var(--amber)',error:'var(--rose)'};
let toastTimer = null;
function showToast(title, sub='', type='info', dur=3500){
  const stack = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.setAttribute('role','status');
  t.style.borderColor = TOAST_BORDERS[type] || TOAST_BORDERS.info;
  t.innerHTML = `<span class="t-icon">${TOAST_ICONS[type]||'ℹ️'}</span>
    <div class="t-body"><div class="t-title">${escHtml(title)}</div>${sub?`<div class="t-sub">${escHtml(sub)}</div>`:''}</div>
    <span class="t-close" onclick="this.parentElement.remove()" tabindex="0" role="button"><i class="bi bi-x"></i></span>`;
  stack.appendChild(t);
  requestAnimationFrame(()=>requestAnimationFrame(()=>t.classList.add('show')));
  setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),380);},dur);
}

/* ── NOTIF PANEL ── */
function toggleNotifPanel(){
  const panel = document.getElementById('notif-panel');
  const btn   = document.getElementById('notif-btn');
  const open  = panel.classList.toggle('open');
  btn.setAttribute('aria-expanded', open.toString());
}
document.addEventListener('click', e=>{
  const p=document.getElementById('notif-panel'), b=document.getElementById('notif-btn');
  if(p?.classList.contains('open')&&!p.contains(e.target)&&!b?.contains(e.target)){
    p.classList.remove('open'); b?.setAttribute('aria-expanded','false');
  }
});

/* ── RECHERCHE ── */
let searchTimer=null, lastQuery='';
function debounceSearch(val){
  clearTimeout(searchTimer);
  const q=val.trim();
  if(q.length<2){hideSearchResults();return;}
  searchTimer=setTimeout(()=>doSearch(q),300);
}
async function doSearch(q){
  if(q===lastQuery)return;
  lastQuery=q;
  const box=document.getElementById('search-results');
  box.innerHTML='<div id="search-loading">🔍 Recherche en cours…</div>';
  box.classList.add('show');
  try{
    const res=await fetch(`${SEARCH_API}?q=${encodeURIComponent(q)}`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    if(!res.ok)throw new Error('HTTP '+res.status);
    const data=await res.json();
    if(!data.results||data.results.length===0){
      box.innerHTML=`<div id="search-empty">Aucun résultat pour « ${escHtml(q)} »</div>`;return;
    }
    box.innerHTML=data.results.map(r=>`
      <a class="sr-item" href="books/view.php?id=${parseInt(r.id)}" role="option">
        <span class="sr-icon">📚</span>
        <div><div class="sr-title">${escHtml(r.titre)}</div>
        <div class="sr-sub">${escHtml(r.auteur)} · ${escHtml(r.categorie||'Général')}</div></div>
      </a>`).join('');
  }catch(err){
    box.innerHTML='<div id="search-empty">Erreur de recherche</div>';
  }
}
function handleSearchKey(e){
  if(e.key==='Enter'){const q=e.target.value.trim();if(q)window.location.href='books/index.php?search='+encodeURIComponent(q);}
  if(e.key==='Escape')hideSearchResults();
}
function hideSearchResults(){
  const box=document.getElementById('search-results');
  box.classList.remove('show'); box.innerHTML=''; lastQuery='';
}
document.addEventListener('click',e=>{
  const sw=document.querySelector('.tb-search-wrap');
  if(sw&&!sw.contains(e.target))hideSearchResults();
});

/* ── PAIEMENT ── */
let payMethod='orange', payBookId=null;
function showPaymentModal(id,name,price){
  payBookId=parseInt(id)||null;
  document.getElementById('pay-book').textContent=name||'Livre';
  document.getElementById('pay-amt').textContent=Number(price||0).toLocaleString('fr-CM');
  document.getElementById('pay-phone').value='';
  document.getElementById('pay-pin').value='';
  selMethod('orange',document.getElementById('pm-orange'));
  document.getElementById('pay-modal').classList.add('open');
  setTimeout(()=>document.getElementById('pay-phone').focus(),120);
}
function closePayModal(){document.getElementById('pay-modal').classList.remove('open');}
function selMethod(m,el){
  payMethod=m;
  document.querySelectorAll('.pay-method').forEach(x=>{x.classList.remove('selected');x.setAttribute('aria-checked','false');});
  el.classList.add('selected'); el.setAttribute('aria-checked','true');
}
function doPayment(){
  const phone=document.getElementById('pay-phone').value.trim();
  const pin=document.getElementById('pay-pin').value.trim();
  if(phone.length<8){showToast('Erreur','Numéro invalide (min 8 chiffres).','error');return;}
  if(pin.length<4){showToast('Erreur','PIN incomplet (4 chiffres).','error');return;}
  closePayModal();
  showToast('Paiement',`Traitement via ${payMethod==='orange'?'Orange Money':'MTN MoMo'}…`,'info',2500);
  setTimeout(()=>{
    if(Math.random()>0.08){
      showToast('Paiement accepté','Achat confirmé ! Accédez à votre livre.','success',6000);
      // Sauvegarder l'achat
      if(payBookId){
        const ref='DLS-'+Date.now().toString(36).toUpperCase();
        fetch('api/save_purchase.php',{method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({livre_id:payBookId,montant:parseInt(document.getElementById('pay-amt').textContent.replace(/\s/g,'')||0),methode:payMethod==='orange'?'orange_money':'mobile_money',reference:ref})}).catch(()=>{});
        setTimeout(()=>{window.location.href='books/read.php?id='+payBookId;},1500);
      }
    }else{
      showToast('Paiement refusé','Solde insuffisant ou PIN incorrect.','error',5000);
    }
  },2600);
}
document.getElementById('pay-modal').addEventListener('click',e=>{if(e.target===e.currentTarget)closePayModal();});

/* ── ESCAPE KEY ── */
document.addEventListener('keydown',e=>{
  if(e.key!=='Escape')return;
  const pm=document.getElementById('pay-modal'), ai=document.getElementById('ai-panel'), np=document.getElementById('notif-panel');
  if(pm?.classList.contains('open')){closePayModal();return;}
  if(ai?.classList.contains('open')){ai.classList.remove('open');return;}
  if(np?.classList.contains('open')){np.classList.remove('open');document.getElementById('notif-btn')?.setAttribute('aria-expanded','false');}
});

/* ── IA CHATBOT ── */
let aiLoading=false;
function addMsg(text,role){
  const msgs=document.getElementById('ai-msgs');
  const t=new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
  const wrap=document.createElement('div');
  if(role==='user'){
    const b=document.createElement('div'); b.className='msg-bubble user'; b.textContent=text;
    const m=document.createElement('div'); m.className='msg-meta r'; m.textContent='Vous · '+t;
    wrap.appendChild(b); wrap.appendChild(m);
  }else{
    wrap.innerHTML=`<div class="msg-bubble bot">${text}</div><div class="msg-meta">Assistant IA · ${escHtml(t)}</div>`;
  }
  msgs.appendChild(wrap); msgs.scrollTop=msgs.scrollHeight;
}
function showTyping(){
  const msgs=document.getElementById('ai-msgs');
  const d=document.createElement('div'); d.id='ai-typing';
  d.innerHTML=`<div class="typing-dots" aria-label="L'assistant écrit…"><div class="td-dot"></div><div class="td-dot"></div><div class="td-dot"></div></div>`;
  msgs.appendChild(d); msgs.scrollTop=msgs.scrollHeight;
}
function hideTyping(){document.getElementById('ai-typing')?.remove();}
async function sendAIMsg(){
  if(aiLoading)return;
  const input=document.getElementById('ai-input');
  const q=input.value.trim(); if(!q)return;
  addMsg(q,'user'); input.value=''; input.style.height='auto';
  aiLoading=true; showTyping();
  try{
    const res=await fetch(AI_ENDPOINT,{
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body:JSON.stringify({question:q,csrf:CSRF,role:USER_ROLE}),
    });
    if(!res.ok)throw new Error('HTTP '+res.status);
    // Vérifier que c'est bien du JSON
    const ct=res.headers.get('content-type')||'';
    if(!ct.includes('json'))throw new Error('Réponse non-JSON du serveur. Vérifiez ai/chat.php');
    const data=await res.json();
    hideTyping();
    if(data.answer)addMsg(data.answer,'bot');
    else if(data.error)addMsg('⚠️ '+escHtml(data.error),'bot');
    else addMsg('Réponse vide du serveur.','bot');
  }catch(err){
    hideTyping(); console.error('[AI]',err);
    addMsg(`⚠️ ${escHtml(err.message)} — Vérifiez que <code>ai/chat.php</code> existe et retourne du JSON.`,'bot');
  }finally{aiLoading=false;}
}
function sendQuick(text){document.getElementById('ai-input').value=text;autoResize(document.getElementById('ai-input'));sendAIMsg();}
function handleAIKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendAIMsg();}}
function autoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,90)+'px';}
function resetAI(){
  const msgs=document.getElementById('ai-msgs');
  msgs.innerHTML='';
  const d=document.createElement('div');
  d.innerHTML=`<div class="msg-bubble bot">Nouvelle conversation. Bonjour ${escHtml(USER_NAME)} ! 🤖</div><div class="msg-meta">Assistant IA · ${escHtml(new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}))}</div>`;
  msgs.appendChild(d);
  showToast('IA','Conversation réinitialisée.','success',2000);
}

/* ── TEMPS RÉEL — Polling stats toutes les 10s ── */
function refreshStats(){
  fetch(STATS_API,{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}})
    .then(r=>{if(!r.ok)throw new Error();return r.json();})
    .then(data=>{
      function animCount(id,newVal){
        const el=document.getElementById(id); if(!el||!newVal)return;
        const old=parseInt((el.textContent||'0').replace(/[\s,]/g,''))||0;
        const diff=newVal-old; if(Math.abs(diff)<1)return;
        const steps=20,st=30; let cur=old,inc=diff/steps;
        const timer=setInterval(()=>{cur+=inc;el.textContent=Math.round(cur).toLocaleString('fr-FR');if(Math.abs(cur-newVal)<Math.abs(inc)){el.textContent=newVal.toLocaleString('fr-FR');clearInterval(timer);}},st);
      }
      if(data.recents?.length){
        const el=document.getElementById('dash-recent-list');
        if(el) el.innerHTML=data.recents.map(r=>`<div style="display:flex;gap:8px;padding:4px 0;border-bottom:1px solid var(--border);font-size:.7rem"><span style="flex:1;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(r.titre||'—')}</span><span style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--neon);flex-shrink:0">${parseInt(r.montant||0).toLocaleString('fr-FR')} F</span></div>`).join('');
      }
    })
    .catch(()=>{});
}
setInterval(refreshStats, 10000);

/* ── INIT ── */
document.addEventListener('DOMContentLoaded',()=>{
  setTimeout(()=>showToast('Bonjour '+USER_NAME+' !','Données chargées depuis la BD.','success',4000),700);
  setTimeout(()=>{
    document.querySelectorAll('.prog').forEach(b=>{
      const w=b.style.width; b.style.width='0%';
      requestAnimationFrame(()=>requestAnimationFrame(()=>{b.style.width=w;}));
    });
  },400);
  const params=new URLSearchParams(window.location.search);
  if(params.get('error')==='access_denied')showToast('Accès refusé','Droits insuffisants.','error');
  if(params.get('success')==='saved')showToast('Enregistré','Modifications sauvegardées.','success');
  if(params.get('success')==='purchase')showToast('Achat confirmé','Le livre est dans votre bibliothèque.','success');
});
</script>
</body>
</html>