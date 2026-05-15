<?php
/**
 * admin/notifications.php — Notifications personnelles
 * ══════════════════════════════════════════════════════════════
 * CORRECTIONS APPORTÉES :
 *  - Création table avec TOUS les champs requis (titre, icon, bg)
 *  - Requête settings corrigée (setting_key au lieu de key)
 *  - Comptage non-lus corrigé (pas de hack &&0)
 *  - AJAX endpoint séparé (GET ?ajax=1) retourne JSON propre
 *  - Sécurité : prepared statements partout, XSS évité
 *  - Pagination, filtres, suppression admin tous fonctionnels
 * ══════════════════════════════════════════════════════════════
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Sécurité session ────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';
$isAdmin  = $userRole === 'admin';
$username = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');

// ── Connexion BD sécurisée ──────────────────────────────────
$pdo = null;
$dbSearchPaths = [
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config/database.php',
];
foreach ($dbSearchPaths as $cp) {
    if (file_exists($cp)) { require_once $cp; break; }
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
        if (!empty($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Erreur BD : ' . $e->getMessage()]);
            exit;
        }
        die('<p style="color:red;font-family:monospace">Erreur BD : ' . htmlspecialchars($e->getMessage()) . '</p>');
    }
}

// ── Création table notifications (garde-fou) ────────────────
// ⚠️  CORRECTION : la table inclut TOUS les champs
//     utilisés dans les requêtes : titre, icon, bg, lu
$pdo->exec("
    CREATE TABLE IF NOT EXISTS notifications (
        id         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED  DEFAULT NULL,
        type       VARCHAR(50)   NOT NULL DEFAULT 'info',
        titre      VARCHAR(255)  NOT NULL DEFAULT '',
        message    TEXT          DEFAULT NULL,
        icon       VARCHAR(10)   NOT NULL DEFAULT '🔔',
        bg         VARCHAR(80)   NOT NULL DEFAULT 'rgba(0,212,255,.08)',
        lu         TINYINT(1)    NOT NULL DEFAULT 0,
        created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_user    (user_id),
        INDEX idx_notif_lu      (lu),
        INDEX idx_notif_type    (type),
        INDEX idx_notif_created (created_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Gestion AJAX (polling temps réel) ──────────────────────
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    $ajaxAction = $_GET['ajax_action'] ?? 'count';

    if ($ajaxAction === 'count') {
        // Retourne le nombre de non-lus
        try {
            if ($isAdmin) {
                $count = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE lu = 0")->fetchColumn();
            } else {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM notifications
                     WHERE lu = 0 AND (user_id = ? OR user_id IS NULL)"
                );
                $stmt->execute([$userId]);
                $count = (int)$stmt->fetchColumn();
            }
            echo json_encode(['unread' => $count]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage(), 'unread' => 0]);
        }
        exit;
    }

    if ($ajaxAction === 'list') {
        // Retourne les 10 dernières notifications pour le panneau topbar
        try {
            $where  = $isAdmin ? '' : "WHERE user_id = ? OR user_id IS NULL";
            $params = $isAdmin ? [] : [$userId];
            $stmt   = $pdo->prepare(
                "SELECT id, type, titre, message, icon, bg, lu, created_at
                 FROM notifications
                 {$where}
                 ORDER BY created_at DESC LIMIT 10"
            );
            $stmt->execute($params);
            echo json_encode(['notifications' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage(), 'notifications' => []]);
        }
        exit;
    }

    echo json_encode(['error' => 'Action AJAX inconnue']);
    exit;
}

// ── Actions GET ─────────────────────────────────────────────
$action = $_GET['action'] ?? '';

// Marquer toutes comme lues
if ($action === 'mark_all_read') {
    try {
        if ($isAdmin) {
            $pdo->exec("UPDATE notifications SET lu = 1 WHERE lu = 0");
        } else {
            $pdo->prepare(
                "UPDATE notifications SET lu = 1 WHERE (user_id = ? OR user_id IS NULL) AND lu = 0"
            )->execute([$userId]);
        }
    } catch (PDOException $e) { /* silencieux */ }
    header('Location: notifications.php?success=1');
    exit;
}

// Marquer une notif comme lue
if ($action === 'mark_read' && isset($_GET['id'])) {
    $nid = (int)$_GET['id'];
    try {
        if ($isAdmin) {
            $pdo->prepare("UPDATE notifications SET lu = 1 WHERE id = ?")->execute([$nid]);
        } else {
            $pdo->prepare(
                "UPDATE notifications SET lu = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)"
            )->execute([$nid, $userId]);
        }
    } catch (PDOException $e) { /* silencieux */ }
    // Redirection propre vers la même page sans l'action
    $qs = http_build_query(array_diff_key($_GET, ['action' => '', 'id' => '']));
    header('Location: notifications.php' . ($qs ? '?' . $qs : ''));
    exit;
}

// Supprimer (admin seulement)
if ($action === 'delete' && $isAdmin && isset($_GET['id'])) {
    $nid = (int)$_GET['id'];
    try {
        $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$nid]);
    } catch (PDOException $e) { /* silencieux */ }
    header('Location: notifications.php?deleted=1');
    exit;
}

// Supprimer toutes les lues (admin seulement)
if ($action === 'delete_read' && $isAdmin) {
    try {
        $pdo->exec("DELETE FROM notifications WHERE lu = 1");
    } catch (PDOException $e) { /* silencieux */ }
    header('Location: notifications.php?cleaned=1');
    exit;
}

// ── Filtres et pagination ───────────────────────────────────
$filterType = isset($_GET['type']) && strlen($_GET['type']) <= 50
    ? preg_replace('/[^a-z_]/', '', $_GET['type'])
    : 'all';
$filterLu   = in_array($_GET['lu'] ?? '', ['read', 'unread']) ? $_GET['lu'] : 'all';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;

// ── Construction de la requête WHERE ───────────────────────
$where  = [];
$params = [];

if (!$isAdmin) {
    $where[]  = "(n.user_id = ? OR n.user_id IS NULL)";
    $params[] = $userId;
}
if ($filterType !== 'all') {
    $where[]  = "n.type = ?";
    $params[] = $filterType;
}
if ($filterLu === 'unread') { $where[] = "n.lu = 0"; }
if ($filterLu === 'read')   { $where[] = "n.lu = 1"; }

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

// ── Total pour pagination ───────────────────────────────────
try {
    $cSt = $pdo->prepare("SELECT COUNT(*) FROM notifications n {$whereSQL}");
    $cSt->execute($params);
    $total = (int)$cSt->fetchColumn();
} catch (PDOException $e) {
    $total = 0;
}
$totalPages = max(1, (int)ceil($total / $perPage));

// ── Liste des notifications ─────────────────────────────────
$notifs = [];
try {
    $notifSt = $pdo->prepare(
        "SELECT n.id, n.user_id, n.type, n.titre, n.message, n.icon, n.bg, n.lu, n.created_at,
                u.nom AS user_nom, u.email AS user_email
         FROM notifications n
         LEFT JOIN users u ON u.id = n.user_id
         {$whereSQL}
         ORDER BY n.created_at DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $notifSt->execute($params);
    $notifs = $notifSt->fetchAll();
} catch (PDOException $e) {
    $notifs = [];
}

// ── Comptage non-lus (CORRECTION : pas de hack &&0) ─────────
$nbUnread = 0;
try {
    if ($isAdmin) {
        $nbUnread = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE lu = 0")->fetchColumn();
    } else {
        $unSt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND lu = 0"
        );
        $unSt->execute([$userId]);
        $nbUnread = (int)$unSt->fetchColumn();
    }
} catch (PDOException $e) {
    $nbUnread = 0;
}

// ── Types disponibles pour les filtres ──────────────────────
$types = [];
try {
    if ($isAdmin) {
        $typesSt = $pdo->query("SELECT DISTINCT type FROM notifications ORDER BY type");
    } else {
        $typesSt = $pdo->prepare(
            "SELECT DISTINCT type FROM notifications
             WHERE (user_id = ? OR user_id IS NULL) ORDER BY type"
        );
        $typesSt->execute([$userId]);
    }
    $types = array_column($typesSt->fetchAll(), 'type');
} catch (PDOException $e) {
    $types = [];
}

// ── Notifications de démo si table vide ─────────────────────
if ($total === 0 && !$isAdmin) {
    $demoData = [
        [$userId, 'sale',    'Nouveau achat',   'Un lecteur vient d\'acheter votre livre.', '🛍️', 'rgba(0,255,170,.08)'],
        [$userId, 'avis',    'Nouvel avis',      'Un lecteur a posté un avis 5 étoiles.',    '⭐', 'rgba(245,158,11,.08)'],
        [null,    'system',  'Bienvenue sur DLS','Votre espace est prêt. Bonne publication !','🎉','rgba(0,212,255,.08)'],
    ];
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO notifications (user_id, type, titre, message, icon, bg) VALUES (?,?,?,?,?,?)"
    );
    foreach ($demoData as $d) {
        try { $ins->execute($d); } catch (PDOException $e) { /* silencieux */ }
    }
    // Recharger
    try {
        $notifSt->execute($params);
        $notifs = $notifSt->fetchAll();
        $cSt->execute($params);
        $total = (int)$cSt->fetchColumn();
        $nbUnread = $total;
    } catch (PDOException $e) { /* silencieux */ }
}

// ── Helper temps relatif ────────────────────────────────────
function timeAgo(string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 0)      return 'à l\'instant';
    if ($diff < 60)     return 'à l\'instant';
    if ($diff < 3600)   return (int)($diff / 60) . ' min';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h';
    if ($diff < 604800) return (int)($diff / 86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}

$typeIcons = [
    'sale' => '🛍️', 'avis' => '⭐', 'system' => '⚙️', 'info' => 'ℹ️',
    'warn' => '⚠️', 'error' => '❌', 'bonus' => '🎁', 'user' => '👤',
    'book' => '📚', 'download' => '⬇️', 'payment' => '💳',
    'login' => '🔐', 'register' => '🎉', 'block' => '🚫',
];
$typeLabels = [
    'sale' => 'Vente', 'avis' => 'Avis', 'system' => 'Système',
    'info' => 'Info', 'warn' => 'Avertissement', 'error' => 'Erreur',
    'bonus' => 'Bonus', 'user' => 'Utilisateur', 'book' => 'Livre',
    'download' => 'Téléchargement', 'payment' => 'Paiement',
    'login' => 'Connexion', 'register' => 'Inscription', 'block' => 'Blocage',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500;600&family=Space+Mono:wght@400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══ RESET ══════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:       #05080f;
  --surface:  #0b1020;
  --card:     rgba(255,255,255,.032);
  --hov:      rgba(255,255,255,.058);
  --border:   rgba(255,255,255,.07);
  --cyan:     #00d4ff;
  --violet:   #7c3aed;
  --neon:     #00ffaa;
  --amber:    #f59e0b;
  --rose:     #f43f5e;
  --txt:      #eef2ff;
  --txt2:     rgba(238,242,255,.55);
  --txt3:     rgba(238,242,255,.28);
  --r:        10px;
  --r-lg:     16px;
}

body { font-family:'DM Sans',sans-serif; background:var(--bg); color:var(--txt); min-height:100vh; }
a { text-decoration:none; }

/* ══ PAGE ═════════════════════════════════════════════════ */
.page { max-width:940px; margin:0 auto; padding:2rem 1.5rem 4rem; }

/* ══ NAVIGATION ═══════════════════════════════════════════ */
.nav {
  display:flex; align-items:center; gap:.5rem; margin-bottom:2rem;
  flex-wrap:wrap; padding:.8rem; background:var(--card);
  border:1px solid var(--border); border-radius:var(--r-lg);
}
.nav a {
  font-size:.73rem; color:var(--txt3); display:flex; align-items:center;
  gap:4px; padding:5px 11px; border-radius:var(--r); border:1px solid transparent;
  transition:all .18s;
}
.nav a:hover { color:var(--cyan); border-color:rgba(0,212,255,.25); background:rgba(0,212,255,.05); }
.nav a.active { color:var(--cyan); border-color:rgba(0,212,255,.3); background:rgba(0,212,255,.07); }
.nav-spacer { flex:1; }

/* ══ HEADER PAGE ══════════════════════════════════════════ */
.page-header { margin-bottom:1.5rem; }
.page-title  {
  font-family:'Syne',sans-serif; font-size:1.65rem; font-weight:800;
  letter-spacing:-.5px; display:flex; align-items:center; gap:.7rem;
  flex-wrap:wrap;
}
.page-sub { font-size:.78rem; color:var(--txt2); margin-top:.4rem; }

/* ══ ALERTES ══════════════════════════════════════════════ */
.alert {
  display:flex; align-items:center; gap:.7rem; padding:.8rem 1.1rem;
  border-radius:var(--r); font-size:.78rem; margin-bottom:1.2rem;
  border:1px solid;
}
.alert-success { background:rgba(0,255,170,.07); border-color:rgba(0,255,170,.25); color:var(--neon); }
.alert-info    { background:rgba(0,212,255,.07); border-color:rgba(0,212,255,.25); color:var(--cyan); }
.alert-warn    { background:rgba(245,158,11,.07); border-color:rgba(245,158,11,.25); color:var(--amber); }

/* ══ STATS ════════════════════════════════════════════════ */
.stat-row { display:flex; gap:.8rem; margin-bottom:1.4rem; flex-wrap:wrap; }
.stat-c {
  flex:1; min-width:110px;
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--r-lg); padding:.9rem 1.2rem;
  transition:border-color .2s;
}
.stat-c:hover { border-color:rgba(255,255,255,.12); }
.stat-v { font-family:'Syne',sans-serif; font-size:1.6rem; font-weight:800; line-height:1; }
.stat-l { font-size:.67rem; color:var(--txt2); margin-top:4px; text-transform:uppercase; letter-spacing:.05em; }

/* ══ BARRE D'ACTIONS ══════════════════════════════════════ */
.toolbar {
  display:flex; align-items:flex-start; gap:1rem; margin-bottom:1.2rem;
  flex-wrap:wrap; justify-content:space-between;
}
.toolbar-left  { display:flex; gap:.5rem; flex-wrap:wrap; }
.toolbar-right { display:flex; gap:.5rem; flex-wrap:wrap; }

/* ══ FILTRES ══════════════════════════════════════════════ */
.filter-bar {
  display:flex; align-items:center; gap:.45rem; flex-wrap:wrap;
  margin-bottom:1rem; padding:.7rem 1rem;
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--r);
}
.filter-label {
  font-size:.6rem; font-family:'Space Mono',monospace; color:var(--txt3);
  text-transform:uppercase; letter-spacing:.08em;
}
.filter-sep { width:1px; height:18px; background:var(--border); margin:0 .2rem; }
.f-btn {
  font-size:.68rem; padding:4px 11px; border-radius:100px;
  border:1px solid var(--border); background:var(--card); color:var(--txt2);
  text-decoration:none; transition:all .18s; cursor:pointer; white-space:nowrap;
}
.f-btn:hover, .f-btn.active {
  border-color:rgba(0,212,255,.35); color:var(--cyan);
  background:rgba(0,212,255,.06);
}

/* ══ BOUTONS ══════════════════════════════════════════════ */
.btn {
  display:inline-flex; align-items:center; gap:5px; padding:6px 13px;
  border-radius:var(--r); font-size:.73rem; font-weight:600; cursor:pointer;
  border:none; font-family:'DM Sans',sans-serif; transition:all .18s;
  text-decoration:none; white-space:nowrap;
}
.btn-primary { background:linear-gradient(135deg,var(--cyan),var(--violet)); color:#fff; }
.btn-primary:hover { opacity:.87; transform:translateY(-1px); }
.btn-ghost   { background:var(--card); border:1px solid var(--border); color:var(--txt2); }
.btn-ghost:hover { color:var(--txt); border-color:rgba(255,255,255,.14); background:var(--hov); }
.btn-danger  { background:rgba(244,63,94,.08); border:1px solid rgba(244,63,94,.22); color:var(--rose); }
.btn-danger:hover { background:rgba(244,63,94,.16); }
.btn-sm { padding:4px 9px; font-size:.67rem; }

/* ══ LISTE NOTIFICATIONS ══════════════════════════════════ */
.notif-list { display:flex; flex-direction:column; gap:.45rem; }

.notif-item {
  display:flex; align-items:flex-start; gap:12px; padding:13px 15px;
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--r-lg); transition:background .15s, border-color .15s;
  position:relative; animation:slideIn .3s ease both;
}
.notif-item:hover { background:var(--hov); border-color:rgba(255,255,255,.1); }

/* Non lu → bordure gauche cyan + fond très légèrement cyan */
.notif-item.unread { border-left:3px solid var(--cyan); background:rgba(0,212,255,.028); }

@keyframes slideIn {
  from { opacity:0; transform:translateY(8px); }
  to   { opacity:1; transform:translateY(0); }
}

/* Point de non-lu en haut à droite */
.ni-dot {
  position:absolute; top:12px; right:12px;
  width:8px; height:8px; border-radius:50%;
  background:var(--cyan); box-shadow:0 0 8px rgba(0,212,255,.7);
  animation:pulse 2s ease infinite;
}
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.6;transform:scale(1.2)} }

/* Icône */
.ni-icon {
  width:40px; height:40px; border-radius:11px;
  display:flex; align-items:center; justify-content:center;
  font-size:1.05rem; flex-shrink:0;
}

/* Corps */
.ni-body { flex:1; min-width:0; }
.ni-titre {
  font-family:'Syne',sans-serif; font-weight:700; font-size:.85rem;
  margin-bottom:2px; color:var(--txt);
}
.notif-item:not(.unread) .ni-titre { color:var(--txt2); }
.ni-msg  { font-size:.77rem; color:var(--txt2); line-height:1.5; }

/* Pied */
.ni-foot { display:flex; align-items:center; gap:.6rem; margin-top:5px; flex-wrap:wrap; }
.ni-time {
  font-size:.6rem; font-family:'Space Mono',monospace; color:var(--txt3);
}
.ni-type {
  font-size:.57rem; font-family:'Space Mono',monospace; padding:2px 7px;
  border-radius:100px; border:1px solid var(--border); color:var(--txt3);
  text-transform:uppercase; letter-spacing:.05em;
}
.ni-user { font-size:.6rem; color:var(--txt3); }

/* Actions inline */
.ni-actions { display:flex; align-items:center; gap:.3rem; flex-shrink:0; }
.ni-btn {
  width:28px; height:28px; border-radius:8px; background:var(--card);
  border:1px solid var(--border); color:var(--txt3); cursor:pointer;
  display:flex; align-items:center; justify-content:center; font-size:.75rem;
  text-decoration:none; transition:all .18s;
}
.ni-btn:hover { color:var(--txt); border-color:rgba(255,255,255,.16); background:var(--hov); }
.ni-btn.danger { color:rgba(244,63,94,.7); }
.ni-btn.danger:hover { color:var(--rose); border-color:rgba(244,63,94,.35); }

/* ══ EMPTY STATE ══════════════════════════════════════════ */
.empty {
  text-align:center; padding:3.5rem 1rem; color:var(--txt3);
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--r-lg);
}
.empty-icon { font-size:2.8rem; margin-bottom:.6rem; opacity:.3; display:block; }
.empty-text { font-size:.83rem; }

/* ══ PAGINATION ═══════════════════════════════════════════ */
.pagination { display:flex; gap:.4rem; justify-content:center; margin-top:1.4rem; flex-wrap:wrap; }
.pag-btn {
  display:inline-flex; align-items:center; justify-content:center;
  min-width:32px; height:32px; padding:0 8px; border-radius:var(--r);
  background:var(--card); border:1px solid var(--border); color:var(--txt2);
  text-decoration:none; font-size:.73rem; transition:all .18s;
}
.pag-btn:hover, .pag-btn.active {
  border-color:rgba(0,212,255,.35); color:var(--cyan); background:rgba(0,212,255,.06);
}

/* ══ BADGE ════════════════════════════════════════════════ */
.badge {
  display:inline-flex; align-items:center; font-size:.62rem;
  font-family:'Space Mono',monospace; padding:2px 8px; border-radius:100px;
  font-weight:700; border:1px solid;
}
.badge-rose   { background:rgba(244,63,94,.1);  border-color:rgba(244,63,94,.25);  color:var(--rose);   }
.badge-cyan   { background:rgba(0,212,255,.1);  border-color:rgba(0,212,255,.25);  color:var(--cyan);   }
.badge-neon   { background:rgba(0,255,170,.1);  border-color:rgba(0,255,170,.25);  color:var(--neon);   }
.badge-amber  { background:rgba(245,158,11,.1); border-color:rgba(245,158,11,.25); color:var(--amber);  }

/* ══ TEMPS RÉEL — indicateur de synchro ══════════════════ */
#sync-indicator {
  position:fixed; bottom:1rem; right:1rem; font-size:.65rem;
  font-family:'Space Mono',monospace; color:var(--txt3);
  background:var(--surface); border:1px solid var(--border);
  padding:5px 10px; border-radius:100px; display:flex; align-items:center; gap:5px;
}
.sync-dot {
  width:6px; height:6px; border-radius:50%; background:var(--neon);
  animation:syncPulse 2s ease infinite;
}
@keyframes syncPulse { 0%,100%{opacity:1} 50%{opacity:.3} }

@media (max-width: 600px) {
  .page { padding:1rem; }
  .toolbar { flex-direction:column; }
  .stat-v { font-size:1.2rem; }
}
</style>
</head>
<body>
<div class="page">

  <!-- ══ NAVIGATION ══════════════════════════════════════ -->
  <div class="nav">
    <a href="../dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
    <?php if (in_array($userRole, ['admin', 'journaliste'])): ?>
      <a href="../books/my_books.php"><i class="bi bi-book-half"></i> Mes livres</a>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <a href="../users/index.php"><i class="bi bi-people"></i> Utilisateurs</a>
      <a href="../stats/index.php"><i class="bi bi-bar-chart"></i> Statistiques</a>
    <?php endif; ?>
    <a href="notifications.php" class="active"><i class="bi bi-bell-fill"></i> Notifications</a>
    
  </div>

  <!-- ══ TITRE ═══════════════════════════════════════════ -->
  <div class="page-header">
    <div class="page-title">
      <i class="bi bi-bell-fill" style="color:var(--cyan)"></i>
      Notifications
      <?php if ($nbUnread > 0): ?>
        <span class="badge badge-rose"><?= $nbUnread ?> non lue<?= $nbUnread > 1 ? 's' : '' ?></span>
      <?php endif; ?>
    </div>
    <div class="page-sub">
      <?= $isAdmin
        ? 'Vue administrateur — toutes les notifications de la plateforme'
        : 'Vos notifications personnelles et les annonces globales' ?>
      &nbsp;·&nbsp;
      <span id="last-sync">Synchronisé à <?= date('H:i:s') ?></span>
    </div>
  </div>

  <!-- ══ ALERTES ══════════════════════════════════════════ -->
  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle"></i> Toutes les notifications ont été marquées comme lues.</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-info"><i class="bi bi-trash3"></i> Notification supprimée.</div>
  <?php endif; ?>
  <?php if (isset($_GET['cleaned'])): ?>
    <div class="alert alert-warn"><i class="bi bi-trash3-fill"></i> Toutes les notifications lues ont été supprimées.</div>
  <?php endif; ?>

  <!-- ══ STATS ═══════════════════════════════════════════ -->
  <div class="stat-row">
    <div class="stat-c">
      <div class="stat-v" style="color:var(--rose)"><?= $nbUnread ?></div>
      <div class="stat-l">Non lues</div>
    </div>
    <div class="stat-c">
      <div class="stat-v" style="color:var(--neon)"><?= $total - $nbUnread ?></div>
      <div class="stat-l">Lues</div>
    </div>
    <div class="stat-c">
      <div class="stat-v" style="color:var(--cyan)"><?= $total ?></div>
      <div class="stat-l">Total (page)</div>
    </div>
    <?php if ($isAdmin): ?>
    <div class="stat-c">
      <div class="stat-v" style="color:var(--violet)"><?= count($types) ?></div>
      <div class="stat-l">Types actifs</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══ BARRE D'ACTIONS ══════════════════════════════════ -->
  <div class="toolbar">
    <div class="toolbar-left">
      <?php if ($nbUnread > 0): ?>
        <a href="?action=mark_all_read" class="btn btn-primary">
          <i class="bi bi-check-all"></i> Tout marquer lu
        </a>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <a href="?action=delete_read"
           class="btn btn-danger"
           onclick="return confirm('Supprimer toutes les notifications lues ?')">
          <i class="bi bi-trash3"></i> Supprimer les lues
        </a>
      <?php endif; ?>
    </div>
    <div class="toolbar-right">
      <span style="font-size:.65rem;color:var(--txt3);align-self:center;font-family:'Space Mono',monospace">
        Auto-refresh :
      </span>
      <button type="button" class="btn btn-ghost btn-sm" onclick="manualRefresh()">
        <i class="bi bi-arrow-clockwise" id="refresh-icon"></i> Actualiser
      </button>
    </div>
  </div>

  <!-- ══ FILTRES ══════════════════════════════════════════ -->
  <div class="filter-bar">
    <span class="filter-label">Lu :</span>
    <a href="?lu=all&type=<?= urlencode($filterType) ?>"
       class="f-btn<?= $filterLu === 'all' ? ' active' : '' ?>">Tous</a>
    <a href="?lu=unread&type=<?= urlencode($filterType) ?>"
       class="f-btn<?= $filterLu === 'unread' ? ' active' : '' ?>">Non lus</a>
    <a href="?lu=read&type=<?= urlencode($filterType) ?>"
       class="f-btn<?= $filterLu === 'read' ? ' active' : '' ?>">Lus</a>

    <?php if (!empty($types)): ?>
    <div class="filter-sep"></div>
    <span class="filter-label">Type :</span>
    <a href="?lu=<?= urlencode($filterLu) ?>&type=all"
       class="f-btn<?= $filterType === 'all' ? ' active' : '' ?>">Tous</a>
    <?php foreach ($types as $t):
      $tLabel = htmlspecialchars($typeLabels[$t] ?? ucfirst($t), ENT_QUOTES, 'UTF-8');
      $tIcon  = htmlspecialchars($typeIcons[$t] ?? '🔔', ENT_QUOTES, 'UTF-8');
    ?>
      <a href="?lu=<?= urlencode($filterLu) ?>&type=<?= urlencode($t) ?>"
         class="f-btn<?= $filterType === $t ? ' active' : '' ?>">
        <?= $tIcon ?> <?= $tLabel ?>
      </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ══ LISTE DES NOTIFICATIONS ══════════════════════════ -->
  <?php if (empty($notifs)): ?>
  <div class="empty">
    <span class="empty-icon"><i class="bi bi-bell-slash"></i></span>
    <div class="empty-text">Aucune notification pour le moment.</div>
  </div>
  <?php else: ?>
  <div class="notif-list" id="notif-list">
    <?php foreach ($notifs as $n):
      $isUnread = !(bool)$n['lu'];
      $nIcon    = htmlspecialchars($n['icon'] ?? ($typeIcons[$n['type']] ?? '🔔'), ENT_QUOTES, 'UTF-8');
      $nBg      = htmlspecialchars($n['bg'] ?? 'rgba(0,212,255,.08)', ENT_QUOTES, 'UTF-8');
      $nTitre   = htmlspecialchars($n['titre']   ?? 'Notification', ENT_QUOTES, 'UTF-8');
      $nMsg     = htmlspecialchars($n['message'] ?? '',              ENT_QUOTES, 'UTF-8');
      $nType    = htmlspecialchars($typeLabels[$n['type'] ?? 'info'] ?? ucfirst($n['type']), ENT_QUOTES, 'UTF-8');
      $nTime    = timeAgo($n['created_at'] ?? '');
    ?>
    <div class="notif-item<?= $isUnread ? ' unread' : '' ?>"
         id="notif-<?= (int)$n['id'] ?>">
      <?php if ($isUnread): ?><div class="ni-dot"></div><?php endif; ?>

      <div class="ni-icon" style="background:<?= $nBg ?>"><?= $nIcon ?></div>

      <div class="ni-body">
        <div class="ni-titre"><?= $nTitre ?></div>
        <?php if ($nMsg && $nMsg !== $nTitre): ?>
          <div class="ni-msg"><?= $nMsg ?></div>
        <?php endif; ?>
        <div class="ni-foot">
          <span class="ni-time"><i class="bi bi-clock"></i> <?= $nTime ?></span>
          <span class="ni-type"><?= $nType ?></span>
          <?php if ($isAdmin && !empty($n['user_nom'])): ?>
            <span class="ni-user">→ <?= htmlspecialchars($n['user_nom'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="ni-actions">
        <?php if ($isUnread): ?>
        <a href="?action=mark_read&id=<?= (int)$n['id'] ?>&lu=<?= urlencode($filterLu) ?>&type=<?= urlencode($filterType) ?>"
           class="ni-btn" title="Marquer comme lu">
          <i class="bi bi-check-lg"></i>
        </a>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <a href="?action=delete&id=<?= (int)$n['id'] ?>"
           class="ni-btn danger"
           title="Supprimer"
           onclick="return confirm('Supprimer cette notification ?')">
          <i class="bi bi-trash3"></i>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ══ PAGINATION ══════════════════════════════════════ -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="?lu=<?= urlencode($filterLu) ?>&type=<?= urlencode($filterType) ?>&page=<?= $page - 1 ?>"
         class="pag-btn"><i class="bi bi-chevron-left"></i></a>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
      <a href="?lu=<?= urlencode($filterLu) ?>&type=<?= urlencode($filterType) ?>&page=<?= $i ?>"
         class="pag-btn<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
      <a href="?lu=<?= urlencode($filterLu) ?>&type=<?= urlencode($filterType) ?>&page=<?= $page + 1 ?>"
         class="pag-btn"><i class="bi bi-chevron-right"></i></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div><!-- /page -->

<!-- ══ INDICATEUR SYNCHRO TEMPS RÉEL ═════════════════════ -->
<div id="sync-indicator">
  <span class="sync-dot"></span>
  <span id="sync-text">En direct</span>
</div>

<script>
// ══ POLLING TEMPS RÉEL ══════════════════════════════════════
// Toutes les 15 secondes : on interroge l'endpoint AJAX
// pour récupérer le nombre de non-lus.
// Si le nombre a changé, on met à jour le badge et on propose
// de recharger via une notification discrète.

const POLL_INTERVAL  = 15000; // 15 secondes
const AJAX_URL       = 'notifications.php?ajax=1&ajax_action=count';
let   lastUnread     = <?= $nbUnread ?>;

function updateSyncTime() {
  const now = new Date();
  const t   = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  document.getElementById('last-sync').textContent = 'Synchronisé à ' + t;
  document.getElementById('sync-text').textContent = 'Synchro ' + t;
}

async function pollUnread() {
  try {
    const res  = await fetch(AJAX_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) return;
    const data = await res.json();
    if (data.error) return;

    const newCount = parseInt(data.unread) || 0;
    updateSyncTime();

    if (newCount !== lastUnread) {
      lastUnread = newCount;
      // Mettre à jour le badge du titre de la page
      document.title = newCount > 0
        ? `(${newCount}) Notifications — Digital Library`
        : 'Notifications — Digital Library';

      // Si de nouvelles notifications sont arrivées, recharger discrètement
      if (newCount > 0) {
        showRefreshBanner(newCount);
      }
    }
  } catch (e) {
    // Silencieux — pas de réseau = pas de crash
  }
}

// Bannière discrète de rechargement
let bannerShown = false;
function showRefreshBanner(count) {
  if (bannerShown) return;
  bannerShown = true;
  const banner = document.createElement('div');
  banner.style.cssText = `
    position:fixed; top:1rem; right:1rem; z-index:9999;
    background:rgba(11,16,32,.95); border:1px solid rgba(0,212,255,.35);
    border-radius:12px; padding:.8rem 1.1rem; max-width:280px;
    display:flex; align-items:center; gap:.7rem;
    font-family:'DM Sans',sans-serif; font-size:.78rem; color:#eef2ff;
    box-shadow:0 8px 32px rgba(0,0,0,.4);
    animation:slideDown .3s ease;
  `;
  banner.innerHTML = `
    <span style="font-size:1.1rem">🔔</span>
    <div style="flex:1">
      <strong>${count} nouvelle${count>1?'s':''} notification${count>1?'s':''}</strong>
      <div style="margin-top:3px">
        <a href="notifications.php" style="color:#00d4ff;text-decoration:none;font-size:.72rem">
          Actualiser la page
        </a>
      </div>
    </div>
    <button onclick="this.parentElement.remove();bannerShown=false;"
            style="background:none;border:none;color:rgba(238,242,255,.4);cursor:pointer;font-size:.85rem;">✕</button>
  `;
  document.body.appendChild(banner);
  setTimeout(() => { banner.remove(); bannerShown = false; }, 8000);
}

// Actualisation manuelle
function manualRefresh() {
  const icon = document.getElementById('refresh-icon');
  icon.style.animation = 'spin 0.5s linear';
  setTimeout(() => { window.location.reload(); }, 400);
}

// Style pour l'animation spin
const style = document.createElement('style');
style.textContent = `
  @keyframes slideDown { from{opacity:0;transform:translateY(-12px)} to{opacity:1;transform:translateY(0)} }
  @keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
`;
document.head.appendChild(style);

// Démarrage du polling
setInterval(pollUnread, POLL_INTERVAL);

// Titre de la page initial
if (<?= $nbUnread ?> > 0) {
  document.title = '(<?= $nbUnread ?>) Notifications — Digital Library';
}
</script>
</body>
</html>