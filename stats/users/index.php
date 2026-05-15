<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║   DIGITAL LIBRARY SYSTEM — Comptes Bloqués v1.0                    ║
 * ║   stats/users/index.php?statut=bloque                              ║
 * ║   100% PDO sécurisé · CSRF · XSS · Audit logs · Temps réel        ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */


/**
 * ════════════════════════════════════════════════════════════════
 * BLOC À PLACER EN HAUT DE stats/index.php
 * (avant tout output HTML, avant tout echo)
 * ════════════════════════════════════════════════════════════════
 *
 * Ce bloc gère l'action export_csv et inclut les fichiers exports
 * avec __DIR__ pour éviter tout chemin cassé sous XAMPP Windows.
 */

// ── 1. Constante de sécurité ─────────────────────────────────
//    Empêche l'appel direct aux fichiers exports


declare(strict_types=1);

/* ── Session sécurisée ─────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // true en prod HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}
define('BIBLIO_APP', true);

// ── 2. Dépendances principales ───────────────────────────────
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';  // ← ajuste si besoin

// ── 3. Gestion de l'action export CSV ───────────────────────
//    Doit être AVANT tout output (avant le DOCTYPE HTML)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {

    // Vérification admin (double sécurité)
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        exit('Accès refusé.');
    }

    // Inclusion sécurisée avec __DIR__
    $csvExportFile = __DIR__ . '/exports/csv_export.php';

    if (!file_exists($csvExportFile)) {
        http_response_code(500);
        exit('Erreur : fichier csv_export.php introuvable. Vérifiez que le fichier existe dans stats/exports/');
    }

    require_once $csvExportFile;

    // Lancement de l'export (la fonction appelle exit() en fin)
    exportCSV();
}

// ── 4. Gestion de l'action rapport HTML ─────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'report') {

    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        exit('Accès refusé.');
    }

    $reportFile = __DIR__ . '/exports/report_export.php';

    if (!file_exists($reportFile)) {
        http_response_code(500);
        exit('Erreur : fichier report_export.php introuvable.');
    }

    require_once $reportFile;
    exit(); // Stop après le rapport HTML
}

// ── 5. Suite normale de index.php ────────────────────────────
//    Le reste de ton index.php continue ici normalement...


/*
 * ════════════════════════════════════════════════════════════════
 * BOUTONS À PLACER DANS TON HTML (dans index.php)
 * ════════════════════════════════════════════════════════════════
 *
 * Bouton export CSV :
 *
 *   <a href="?action=export_csv"
 *      class="btn btn-success">
 *       📥 Exporter CSV
 *   </a>
 *
 * Bouton rapport HTML imprimable :
 *
 *   <a href="?action=report"
 *      target="_blank"
 *      class="btn btn-primary">
 *       🖨️ Rapport imprimable
 *   </a>
 *
 * ════════════════════════════════════════════════════════════════
 */



require_once __DIR__ . '/../../config/config.php';
/* ── Session sécurisée ─────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // true en prod HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/* ── Connexion PDO ─────────────────────────────────────────── */
$pdo = null;
$dbError = null;
$configPaths = [
    __DIR__ . '/../../includes/config.php',
    __DIR__ . '/../../config/config.php',
    __DIR__ . '/../../config/database.php',
    __DIR__ . '/../../includes/database.php',
];
foreach ($configPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}
if (!isset($pdo) || !$pdo instanceof PDO) {
    try {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=digital_library;charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

/* ── Auth guard ────────────────────────────────────────────── */
if (!isset($_SESSION['user_id'])) {
    // Mode dev — supprimer en production
    $_SESSION['user_id']   = 1;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_name'] = 'Administrateur';
}
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../../dashboard.php?error=access_denied');
    exit;
}
$adminId   = (int)($_SESSION['user_id'] ?? 1);
$adminName = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$avatar    = strtoupper(substr($adminName, 0, 1)) ?: 'A';

/* ── CSRF ─────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ── Helpers ──────────────────────────────────────────────── */
function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function dbExec(string $sql, array $p = []): bool {
    global $pdo;
    if (!$pdo) return false;
    try { $s = $pdo->prepare($sql); return $s->execute($p); }
    catch (PDOException $ex) { error_log('[Bloqués] ' . $ex->getMessage()); return false; }
}
function dbFetch(string $sql, array $p = []): array {
    global $pdo;
    if (!$pdo) return [];
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
    catch (PDOException $ex) { error_log('[Bloqués] ' . $ex->getMessage()); return []; }
}
function dbVal(string $sql, array $p = [], $def = 0) {
    global $pdo;
    if (!$pdo) return $def;
    try { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); return $r !== false ? $r : $def; }
    catch (PDOException $ex) { error_log('[Bloqués] ' . $ex->getMessage()); return $def; }
}
function fmtFCFA(float $n): string {
    return number_format($n, 0, ',', ' ') . ' FCFA';
}
function timeAgo(string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'à l\'instant';
    if ($diff < 3600)   return (int)($diff / 60) . ' min';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h';
    if ($diff < 604800) return (int)($diff / 86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}
function logAction(string $action, int $targetId, string $detail = ''): void {
    global $pdo, $adminId;
    if (!$pdo) return;
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    dbExec(
        "INSERT INTO admin_logs (user_id, action, detail, ip, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE created_at = NOW()",
        [$adminId, $action . ':' . $targetId, substr($detail, 0, 500), substr($ip, 0, 45), substr($ua, 0, 255)]
    );
}
function addNotification(string $titre, string $message, string $type = 'info', ?int $userId = null): void {
    global $pdo;
    if (!$pdo) return;
    dbExec(
        "INSERT INTO notifications (user_id, type, titre, message, icon, bg, lu, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())",
        [
            $userId,
            $type,
            $titre,
            $message,
            $type === 'success' ? '✅' : ($type === 'warn' ? '⚠️' : ($type === 'danger' ? '🚫' : 'ℹ️')),
            $type === 'success' ? 'rgba(0,255,170,.1)' : ($type === 'danger' ? 'rgba(244,63,94,.1)' : 'rgba(0,212,255,.1)'),
        ]
    );
}

/* ── Migration auto colonnes manquantes ───────────────────── */
if ($pdo) {
    $migrations = [
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS telephone VARCHAR(20) NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked_at TIMESTAMP NULL COMMENT 'Date de blocage'",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS blocked_by INT NULL COMMENT 'Admin ayant bloqué'",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS block_reason VARCHAR(500) NULL COMMENT 'Motif du blocage'",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS block_count INT NOT NULL DEFAULT 0 COMMENT 'Nombre de blocages'",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS risk_level ENUM('faible','moyen','élevé','critique') NOT NULL DEFAULT 'faible' COMMENT 'Niveau de risque'",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS admin_note TEXT NULL COMMENT 'Note admin'",
        "CREATE TABLE IF NOT EXISTS admin_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            action VARCHAR(120) NOT NULL,
            detail TEXT,
            ip VARCHAR(45),
            user_agent VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            type VARCHAR(50) DEFAULT 'info',
            titre VARCHAR(255),
            message TEXT,
            icon VARCHAR(10) DEFAULT '🔔',
            bg VARCHAR(100) DEFAULT 'rgba(0,212,255,.1)',
            lu TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_lu (lu)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* ignore */ }
    }
}

/* ── CSRF check ───────────────────────────────────────────── */
function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/* ══════════════════════════════════════════════════════════
   ACTIONS AJAX / POST
══════════════════════════════════════════════════════════ */
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    if (!verifyCsrf()) {
        echo json_encode(['ok' => false, 'msg' => 'Token CSRF invalide.']);
        exit;
    }

    $act    = trim($_POST['action'] ?? '');
    $uid    = (int)($_POST['user_id'] ?? 0);
    $result = ['ok' => false, 'msg' => 'Action inconnue.'];

    if (!$pdo || !$uid) {
        echo json_encode(['ok' => false, 'msg' => 'Paramètres manquants.']);
        exit;
    }

    // Récupérer l'utilisateur ciblé
    $target = dbFetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$uid]);
    $target = $target[0] ?? null;
    if (!$target) {
        echo json_encode(['ok' => false, 'msg' => 'Utilisateur introuvable.']);
        exit;
    }

    switch ($act) {

        case 'debloquer':
            if (dbExec("UPDATE users SET statut='actif', blocked_at=NULL WHERE id=?", [$uid])) {
                logAction('debloquer', $uid, 'Compte débloqué');
                addNotification('Compte débloqué', e($target['prenom'] . ' ' . $target['nom']) . ' a été débloqué.', 'success');
                addNotification('Votre compte est réactivé', 'Votre accès a été rétabli.', 'success', $uid);
                $result = ['ok' => true, 'msg' => 'Compte débloqué avec succès.'];
            } else { $result['msg'] = 'Erreur lors du déblocage.'; }
            break;

        case 'bloquer_definitif':
            if (dbExec("UPDATE users SET statut='bloque', block_reason=CONCAT(IFNULL(block_reason,''),' [DÉFINITIF]'), risk_level='critique', block_count=block_count+1 WHERE id=?", [$uid])) {
                logAction('bloquer_definitif', $uid);
                addNotification('Blocage définitif', e($target['prenom'] . ' ' . $target['nom']) . ' bloqué définitivement.', 'danger');
                $result = ['ok' => true, 'msg' => 'Blocage définitif appliqué.'];
            } else { $result['msg'] = 'Erreur.'; }
            break;

        case 'supprimer':
            if (dbExec("DELETE FROM users WHERE id=?", [$uid])) {
                logAction('supprimer', $uid, 'Utilisateur supprimé');
                addNotification('Utilisateur supprimé', e($target['prenom'] . ' ' . $target['nom']) . ' supprimé.', 'danger');
                $result = ['ok' => true, 'msg' => 'Utilisateur supprimé.'];
            } else { $result['msg'] = 'Erreur lors de la suppression.'; }
            break;

        case 'reset_password':
            $newPwd = bin2hex(random_bytes(8));
            $hash   = password_hash($newPwd, PASSWORD_BCRYPT, ['cost' => 12]);
            if (dbExec("UPDATE users SET password=? WHERE id=?", [$hash, $uid])) {
                logAction('reset_password', $uid);
                addNotification('Mot de passe réinitialisé', 'Nouveau mot de passe : ' . $newPwd, 'info', $uid);
                $result = ['ok' => true, 'msg' => 'Mot de passe réinitialisé : <strong>' . e($newPwd) . '</strong>'];
            } else { $result['msg'] = 'Erreur.'; }
            break;

        case 'restaurer':
            $note = e(trim($_POST['note'] ?? ''));
            if (dbExec("UPDATE users SET statut='actif', block_reason=NULL, risk_level='faible', blocked_at=NULL WHERE id=?", [$uid])) {
                if ($note) dbExec("UPDATE users SET admin_note=? WHERE id=?", [$note, $uid]);
                logAction('restaurer', $uid, 'Compte restauré');
                addNotification('Compte restauré', e($target['prenom'] . ' ' . $target['nom']) . ' restauré.', 'success');
                addNotification('Compte restauré', 'Votre compte a été entièrement restauré.', 'success', $uid);
                $result = ['ok' => true, 'msg' => 'Compte restauré avec succès.'];
            } else { $result['msg'] = 'Erreur.'; }
            break;

        case 'add_note':
            $note = trim($_POST['note'] ?? '');
            if (strlen($note) > 1000) $note = substr($note, 0, 1000);
            if (dbExec("UPDATE users SET admin_note=? WHERE id=?", [$note, $uid])) {
                logAction('add_note', $uid, 'Note ajoutée');
                $result = ['ok' => true, 'msg' => 'Note enregistrée.'];
            } else { $result['msg'] = 'Erreur.'; }
            break;

        case 'send_notification':
            $msg = trim($_POST['message'] ?? '');
            if (!$msg) { $result['msg'] = 'Message vide.'; break; }
            addNotification('Message admin', e($msg), 'info', $uid);
            logAction('send_notification', $uid, $msg);
            $result = ['ok' => true, 'msg' => 'Notification envoyée.'];
            break;

        case 'change_risk':
            $level = $_POST['level'] ?? 'faible';
            $valid = ['faible', 'moyen', 'élevé', 'critique'];
            if (!in_array($level, $valid, true)) { $result['msg'] = 'Niveau invalide.'; break; }
            if (dbExec("UPDATE users SET risk_level=? WHERE id=?", [$level, $uid])) {
                logAction('change_risk', $uid, $level);
                $result = ['ok' => true, 'msg' => 'Niveau de risque mis à jour.'];
            } else { $result['msg'] = 'Erreur.'; }
            break;

        case 'get_profile':
            $user = dbFetch("SELECT u.*, a.nom AS admin_name
                             FROM users u
                             LEFT JOIN users a ON a.id = u.blocked_by
                             WHERE u.id=? LIMIT 1", [$uid]);
            $user = $user[0] ?? null;
            if (!$user) { $result['msg'] = 'Introuvable.'; break; }
            $achats  = dbFetch("SELECT a.*, l.titre FROM achats a JOIN livres l ON l.id=a.livre_id WHERE a.user_id=? ORDER BY a.created_at DESC LIMIT 10", [$uid]);
            $logs    = dbFetch("SELECT * FROM admin_logs WHERE action LIKE ? ORDER BY created_at DESC LIMIT 20", ['%:' . $uid]);
            $result  = ['ok' => true, 'user' => $user, 'achats' => $achats, 'logs' => $logs];
            break;

        case 'export_csv':
            // handled below via GET
            $result = ['ok' => false, 'msg' => 'Utilisez GET pour export.'];
            break;

        default:
            $result = ['ok' => false, 'msg' => 'Action inconnue : ' . e($act)];
    }

    echo json_encode($result);
    exit;
}

/* ── Export CSV ───────────────────────────────────────────── */
if ($_GET['action'] ?? '' === 'export_csv') {
    $rows = dbFetch("
        SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.role, u.statut,
               u.created_at, u.last_login, u.block_reason, u.blocked_at, u.risk_level,
               COALESCE(SUM(a.montant),0) AS depenses,
               COUNT(a.id) AS nb_achats
        FROM users u
        LEFT JOIN achats a ON a.user_id=u.id AND a.statut='confirme'
        WHERE u.statut='bloque'
        GROUP BY u.id
        ORDER BY u.blocked_at DESC
    ");
    $fn = 'comptes_bloques_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: no-cache');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Nom','Prénom','Email','Téléphone','Rôle','Statut','Inscription','Dernière connexion','Motif blocage','Date blocage','Niveau risque','Dépenses (FCFA)','Achats'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['nom'], $r['prenom'], $r['email'], $r['telephone'] ?? '',
            $r['role'], $r['statut'], $r['created_at'], $r['last_login'] ?? '',
            $r['block_reason'] ?? '', $r['blocked_at'] ?? '', $r['risk_level'] ?? 'faible',
            $r['depenses'], $r['nb_achats'],
        ], ';');
    }
    fclose($out);
    exit;
}

/* ══════════════════════════════════════════════════════════
   DONNÉES — Pagination & filtres
══════════════════════════════════════════════════════════ */
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;
$offset   = ($page - 1) * $perPage;
$search   = trim($_GET['search'] ?? '');
$roleF    = trim($_GET['role'] ?? '');
$riskF    = trim($_GET['risk'] ?? '');
$dateF    = trim($_GET['date'] ?? '');

/* ── Statistiques bloqués ─────────────────────────────────── */
$stats = [
    'total'       => (int)dbVal("SELECT COUNT(*) FROM users WHERE statut='bloque'"),
    'today'       => (int)dbVal("SELECT COUNT(*) FROM users WHERE statut='bloque' AND DATE(blocked_at)=CURDATE()"),
    'month'       => (int)dbVal("SELECT COUNT(*) FROM users WHERE statut='bloque' AND blocked_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"),
    'admins'      => (int)dbVal("SELECT COUNT(*) FROM users WHERE statut='bloque' AND role='admin'"),
    'journos'     => (int)dbVal("SELECT COUNT(*) FROM users WHERE statut='bloque' AND role='journaliste'"),
    'lecteurs'    => (int)dbVal("SELECT COUNT(*) FROM users WHERE statut='bloque' AND role='lecteur'"),
    'last'        => dbFetch("SELECT CONCAT(prenom,' ',nom) AS name FROM users WHERE statut='bloque' ORDER BY blocked_at DESC LIMIT 1")[0]['name'] ?? '—',
    'critique'    => (int)dbVal("SELECT COUNT(*) FROM users WHERE statut='bloque' AND risk_level='critique'"),
    'total_perte' => (float)dbVal("SELECT COALESCE(SUM(a.montant),0) FROM achats a JOIN users u ON u.id=a.user_id WHERE u.statut='bloque' AND a.statut='confirme'"),
];

/* ── Requête principale ───────────────────────────────────── */
$where  = ["u.statut = 'bloque'"];
$params = [];
if ($search) {
    $where[]  = "(u.nom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR u.telephone LIKE ?)";
    $s = '%' . $search . '%';
    $params   = array_merge($params, [$s, $s, $s, $s]);
}
if ($roleF) { $where[] = "u.role = ?"; $params[] = $roleF; }
if ($riskF) { $where[] = "u.risk_level = ?"; $params[] = $riskF; }
if ($dateF) { $where[] = "DATE(u.blocked_at) = ?"; $params[] = $dateF; }
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalRows = (int)dbVal("SELECT COUNT(*) FROM users u $whereSql", $params);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);

$users = dbFetch("
    SELECT u.id, u.nom, u.prenom, u.email, u.telephone, u.role, u.statut,
           u.created_at, u.last_login, u.block_reason, u.blocked_at,
           u.risk_level, u.block_count, u.admin_note,
           adm.nom AS admin_blocked_nom, adm.prenom AS admin_blocked_prenom,
           COALESCE(SUM(a.montant),0)  AS depenses,
           COUNT(a.id)                 AS nb_achats
    FROM users u
    LEFT JOIN users adm ON adm.id = u.blocked_by
    LEFT JOIN achats a  ON a.user_id = u.id AND a.statut = 'confirme'
    $whereSql
    GROUP BY u.id
    ORDER BY u.blocked_at DESC
    LIMIT $perPage OFFSET $offset
", $params);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comptes Bloqués — Digital Library System</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══════════════════════════════════════════════════════════
   RESET & VARIABLES
══════════════════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg-base:#030609;--bg-surface:#080d16;
  --bg-card:rgba(255,255,255,.028);--bg-card-hov:rgba(255,255,255,.052);
  --border:rgba(255,255,255,.065);--border-glow:rgba(0,212,255,.32);
  --cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;
  --amber:#f59e0b;--rose:#f43f5e;--orange:#f97316;--indigo:#6366f1;
  --t1:#eef2ff;--t2:rgba(238,242,255,.58);--t3:rgba(238,242,255,.28);--t4:rgba(238,242,255,.12);
  --glow-c:0 0 32px rgba(0,212,255,.14);
  --shadow:0 4px 28px rgba(0,0,0,.38);--shadow-lg:0 20px 60px rgba(0,0,0,.55);
  --r:10px;--r2:16px;--r3:22px;
  --sidebar-w:240px;--topbar-h:58px;
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg-base);color:var(--t1);min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.028'/%3E%3C/svg%3E");opacity:.4}
::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}

/* ── LAYOUT ── */
.wrapper{display:flex;min-height:100vh;position:relative;z-index:1}

/* ── SIDEBAR ── */
#sidebar{width:var(--sidebar-w);background:var(--bg-surface);border-right:1px solid var(--border);
  position:fixed;top:0;left:0;bottom:0;z-index:200;display:flex;flex-direction:column;
  transition:transform .3s ease;overflow:hidden}
.sb-brand{height:var(--topbar-h);display:flex;align-items:center;gap:10px;padding:0 16px;border-bottom:1px solid var(--border)}
.sb-logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--rose),var(--violet));
  display:flex;align-items:center;justify-content:center;font-size:1rem;box-shadow:0 0 20px rgba(244,63,94,.25)}
.sb-title{font-family:'Syne',sans-serif;font-weight:800;font-size:.88rem;letter-spacing:-.3px}
.sb-title em{color:var(--rose);font-style:normal}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.sb-section{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.12em;text-transform:uppercase;
  color:var(--t3);padding:10px 16px 3px}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 16px;margin:1px 8px;border-radius:var(--r);
  text-decoration:none;color:var(--t2);font-size:.81rem;font-weight:500;transition:all .16s;position:relative}
.sb-item:hover{color:var(--t1);background:var(--bg-card-hov)}
.sb-item.active{color:var(--rose);background:rgba(244,63,94,.07);border:1px solid rgba(244,63,94,.12)}
.sb-item.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);
  width:3px;height:16px;background:var(--rose);border-radius:0 3px 3px 0;box-shadow:0 0 8px var(--rose)}
.sb-icon{font-size:.95rem;width:18px;text-align:center}
.sb-badge{margin-left:auto;font-size:.58rem;font-family:'Space Mono',monospace;padding:2px 6px;
  border-radius:100px;background:var(--rose);color:#fff;font-weight:700}
.sb-footer{padding:8px;border-top:1px solid var(--border)}

/* ── MAIN ── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column}

/* ── TOPBAR ── */
#topbar{height:var(--topbar-h);background:rgba(3,6,9,.88);backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);display:flex;align-items:center;gap:1rem;padding:0 1.5rem;
  position:sticky;top:0;z-index:100}
.tb-bc{font-size:.75rem;color:var(--t2);display:flex;align-items:center;gap:6px}
.tb-bc strong{font-family:'Syne',sans-serif;font-weight:700;color:var(--t1)}
.tb-spacer{flex:1}
.tb-avatar{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,var(--rose),var(--violet));
  display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.72rem;color:#fff}

/* ── SEARCH BAR ── */
.tb-search{display:flex;align-items:center;gap:7px;background:var(--bg-card);border:1px solid var(--border);
  border-radius:var(--r);padding:5px 11px;width:240px;transition:border-color .2s,box-shadow .2s}
.tb-search:focus-within{border-color:var(--border-glow);box-shadow:var(--glow-c)}
.tb-search input{background:none;border:none;outline:none;color:var(--t1);font-size:.78rem;font-family:'DM Sans',sans-serif;width:100%}
.tb-search input::placeholder{color:var(--t3)}

/* ── PAGE ── */
.page{flex:1;padding:1.5rem 1.8rem 5rem;max-width:1600px;width:100%}

/* ── HEADER ── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;animation:fadeUp .4s ease both}
.ph-title{font-family:'Syne',sans-serif;font-size:1.55rem;font-weight:800;letter-spacing:-.4px;display:flex;align-items:center;gap:10px}
.ph-sub{font-size:.78rem;color:var(--t2);margin-top:4px}
.ph-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}

/* ── STATS GRID ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.85rem;margin-bottom:1.5rem}
.kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r2);padding:1.1rem 1.2rem;
  position:relative;overflow:hidden;transition:transform .22s,border-color .22s,box-shadow .22s;animation:fadeUp .5s ease both;cursor:default}
.kpi:hover{transform:translateY(-3px);border-color:rgba(255,255,255,.1);box-shadow:var(--shadow)}
.kpi::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 100% 0,rgba(255,255,255,.03),transparent 60%);pointer-events:none}
.kpi-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;margin-bottom:.7rem}
.kpi-val{font-family:'Syne',sans-serif;font-size:1.55rem;font-weight:800;letter-spacing:-.4px;line-height:1}
.kpi-label{font-size:.7rem;color:var(--t2);margin-top:4px;font-weight:500}
.kpi-sub{margin-top:6px;font-size:.63rem;font-family:'Space Mono',monospace;color:var(--t3)}
.kpi-glow{position:absolute;bottom:-20px;right:-20px;width:70px;height:70px;border-radius:50%;filter:blur(24px);opacity:.18;pointer-events:none}

/* ── FILTERS ── */
.filters-bar{display:flex;flex-wrap:wrap;gap:.7rem;align-items:center;margin-bottom:1.2rem;
  background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r2);padding:.9rem 1.1rem;animation:fadeUp .45s ease both}
.filter-group{display:flex;align-items:center;gap:6px;font-size:.75rem}
.filter-group label{color:var(--t3);font-family:'Space Mono',monospace;font-size:.62rem;text-transform:uppercase;white-space:nowrap}
.filter-select,.filter-input{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--r);
  color:var(--t1);font-size:.76rem;padding:5px 9px;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .18s}
.filter-select:focus,.filter-input:focus{border-color:var(--rose)}
.filter-select{min-width:110px}
.filter-actions{margin-left:auto;display:flex;gap:5px}

/* ── TABLE ── */
.table-wrap{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;animation:fadeUp .5s ease both}
.table-head{padding:.9rem 1.2rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.7rem}
.table-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.87rem;display:flex;align-items:center;gap:7px}
.table-actions{display:flex;gap:5px}
.tbl{width:100%;border-collapse:collapse;font-size:.77rem}
.tbl th{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.08em;text-transform:uppercase;
  color:var(--t3);padding:8px 12px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
.tbl td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.03);color:var(--t2);vertical-align:middle;transition:background .12s}
.tbl tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:var(--bg-card-hov)}
.td-bold{font-weight:600;color:var(--t1)}

/* Avatar */
.av{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-weight:800;font-size:.72rem;color:#fff;flex-shrink:0}

/* Chips */
.chip{display:inline-flex;align-items:center;gap:3px;font-size:.58rem;font-family:'Space Mono',monospace;
  padding:2px 7px;border-radius:100px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
.c-success{background:rgba(0,255,170,.09);color:var(--neon);border:1px solid rgba(0,255,170,.18)}
.c-warn{background:rgba(245,158,11,.09);color:var(--amber);border:1px solid rgba(245,158,11,.18)}
.c-danger{background:rgba(244,63,94,.09);color:var(--rose);border:1px solid rgba(244,63,94,.18)}
.c-info{background:rgba(0,212,255,.09);color:var(--cyan);border:1px solid rgba(0,212,255,.18)}
.c-violet{background:rgba(124,58,237,.09);color:#a78bfa;border:1px solid rgba(124,58,237,.18)}
.c-muted{background:rgba(255,255,255,.05);color:var(--t3);border:1px solid var(--border)}
.c-orange{background:rgba(249,115,22,.09);color:var(--orange);border:1px solid rgba(249,115,22,.18)}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:var(--r);
  font-family:'Syne',sans-serif;font-size:.74rem;font-weight:700;cursor:pointer;
  transition:all .16s;text-decoration:none;border:none;white-space:nowrap}
.btn-sm{padding:4px 8px;font-size:.67rem}
.btn-xs{padding:3px 6px;font-size:.61rem}
.btn-primary{background:linear-gradient(135deg,var(--rose),var(--violet));color:#fff;box-shadow:0 4px 14px rgba(244,63,94,.2)}
.btn-primary:hover{opacity:.86;transform:translateY(-1px)}
.btn-ghost{background:var(--bg-card);border:1px solid var(--border);color:var(--t2)}
.btn-ghost:hover{color:var(--t1);border-color:rgba(255,255,255,.12)}
.btn-success{background:rgba(0,255,170,.08);border:1px solid rgba(0,255,170,.2);color:var(--neon)}
.btn-success:hover{background:rgba(0,255,170,.15)}
.btn-danger{background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);color:var(--rose)}
.btn-danger:hover{background:rgba(244,63,94,.15)}
.btn-warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);color:var(--amber)}
.btn-warn:hover{background:rgba(245,158,11,.15)}
.btn-cyan{background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.2);color:var(--cyan)}
.btn-cyan:hover{background:rgba(0,212,255,.15)}

/* Dropdown actions */
.dd-wrap{position:relative;display:inline-block}
.dd-menu{position:absolute;top:calc(100%+4px);right:0;min-width:200px;background:var(--bg-surface);
  border:1px solid var(--border);border-radius:var(--r2);box-shadow:var(--shadow-lg);z-index:50;
  overflow:hidden;display:none;animation:fadeUp .18s ease}
.dd-menu.open{display:block}
.dd-item{display:flex;align-items:center;gap:8px;padding:8px 14px;font-size:.78rem;color:var(--t2);
  cursor:pointer;transition:background .12s;border:none;background:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif}
.dd-item:hover{background:var(--bg-card-hov);color:var(--t1)}
.dd-item.danger{color:var(--rose)}
.dd-item.danger:hover{background:rgba(244,63,94,.08)}
.dd-item.success{color:var(--neon)}
.dd-item.success:hover{background:rgba(0,255,170,.06)}
.dd-sep{height:1px;background:var(--border);margin:3px 0}

/* Pagination */
.pagination{display:flex;align-items:center;gap:4px;padding:.9rem 1.2rem;border-top:1px solid var(--border);justify-content:space-between}
.pag-info{font-size:.72rem;color:var(--t3);font-family:'Space Mono',monospace}
.pag-btns{display:flex;gap:3px}
.pag-btn{width:30px;height:30px;border-radius:var(--r);display:flex;align-items:center;justify-content:center;
  font-size:.75rem;font-family:'Space Mono',monospace;text-decoration:none;border:1px solid var(--border);
  background:var(--bg-card);color:var(--t2);transition:all .16s}
.pag-btn:hover{color:var(--t1);background:var(--bg-card-hov)}
.pag-btn.active{background:rgba(244,63,94,.12);border-color:rgba(244,63,94,.3);color:var(--rose)}
.pag-btn.disabled{opacity:.3;pointer-events:none}

/* ── MODALS ── */
.modal-overlay{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;
  padding:1rem;background:rgba(3,6,9,.88);backdrop-filter:blur(14px);opacity:0;pointer-events:none;transition:opacity .28s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-box{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--r3);
  padding:1.8rem;max-width:560px;width:100%;box-shadow:var(--shadow-lg);position:relative;overflow:hidden;
  transform:translateY(16px);transition:transform .32s cubic-bezier(.34,1.56,.64,1)}
.modal-overlay.open .modal-box{transform:translateY(0)}
.modal-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--rose),var(--violet))}
.modal-title{font-family:'Syne',sans-serif;font-weight:800;font-size:1.15rem;margin-bottom:.4rem}
.modal-sub{font-size:.78rem;color:var(--t2);margin-bottom:1.2rem}
.modal-close{position:absolute;top:.8rem;right:.8rem;background:none;border:none;color:var(--t3);
  font-size:.95rem;cursor:pointer;transition:color .16s}
.modal-close:hover{color:var(--t1)}
.modal-footer{display:flex;gap:.6rem;justify-content:flex-end;margin-top:1.2rem}
.form-group{margin-bottom:1rem}
.form-label{display:block;font-size:.68rem;font-family:'Space Mono',monospace;text-transform:uppercase;
  letter-spacing:.05em;color:var(--t3);margin-bottom:5px}
.form-input,.form-textarea,.form-select{width:100%;background:var(--bg-card);border:1px solid var(--border);
  border-radius:var(--r);padding:8px 12px;color:var(--t1);font-size:.81rem;font-family:'DM Sans',sans-serif;
  outline:none;transition:border-color .18s,box-shadow .18s}
.form-input:focus,.form-textarea:focus,.form-select:focus{border-color:var(--rose);box-shadow:0 0 0 3px rgba(244,63,94,.08)}
.form-textarea{min-height:90px;resize:vertical}
.form-select option{background:var(--bg-surface);color:var(--t1)}

/* Profile modal big */
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
.profile-box{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r2);padding:1rem}
.pb-label{font-family:'Space Mono',monospace;font-size:.6rem;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);margin-bottom:4px}
.pb-val{font-size:.8rem;color:var(--t1);font-weight:600}

/* Risk badge */
.risk-faible{background:rgba(0,255,170,.09);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.risk-moyen{background:rgba(245,158,11,.09);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.risk-élevé{background:rgba(249,115,22,.09);color:var(--orange);border:1px solid rgba(249,115,22,.2)}
.risk-critique{background:rgba(244,63,94,.09);color:var(--rose);border:1px solid rgba(244,63,94,.2);animation:pulse 1.8s ease-in-out infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(244,63,94,.3)}50%{box-shadow:0 0 0 4px rgba(244,63,94,0)}}

/* Toast */
#toast-stack{position:fixed;bottom:1.2rem;right:1.2rem;z-index:9000;display:flex;flex-direction:column-reverse;gap:6px;pointer-events:none}
.toast{display:flex;align-items:center;gap:8px;padding:10px 13px;background:var(--bg-surface);border:1px solid var(--border);
  border-radius:var(--r2);box-shadow:var(--shadow-lg);font-size:.77rem;max-width:290px;pointer-events:all;
  transform:translateX(110px);opacity:0;transition:all .32s cubic-bezier(.34,1.56,.64,1)}
.toast.show{transform:translateX(0);opacity:1}
.t-title{font-family:'Syne',sans-serif;font-weight:700}
.t-sub{color:var(--t3);font-size:.67rem}

/* Loader */
.loader{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.1);border-top-color:var(--rose);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Live badge */
.live-badge{display:flex;align-items:center;gap:5px;font-family:'Space Mono',monospace;font-size:.6rem;
  color:var(--rose);padding:4px 9px;border:1px solid rgba(244,63,94,.22);border-radius:100px;background:rgba(244,63,94,.06)}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--rose);flex-shrink:0;
  box-shadow:0 0 6px var(--rose);animation:livePulse 1.5s ease-in-out infinite}
@keyframes livePulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.7)}}

/* Empty state */
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4rem 1rem;color:var(--t3)}
.empty-icon{font-size:3rem;margin-bottom:.8rem;opacity:.5}
.empty-title{font-family:'Syne',sans-serif;font-weight:700;font-size:1rem;margin-bottom:.3rem;color:var(--t2)}
.empty-sub{font-size:.78rem}

/* Mobile */
.mob-ham{display:none;background:none;border:none;color:var(--t1);font-size:1.3rem;cursor:pointer}
#mob-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;opacity:0;pointer-events:none;transition:opacity .3s}
#mob-overlay.show{opacity:1;pointer-events:all}
@media(max-width:900px){
  #sidebar{transform:translateX(-100%)}
  #sidebar.open{transform:translateX(0)}
  .main{margin-left:0}
  .mob-ham{display:block}
  .page{padding:1rem .9rem}
  .kpi-grid{grid-template-columns:1fr 1fr}
  .profile-grid{grid-template-columns:1fr}
  .tb-search{width:140px}
}
@media(max-width:480px){
  .kpi-grid{grid-template-columns:1fr}
  .ph-actions .btn-ghost{display:none}
}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<div class="wrapper">

<!-- ══════════ SIDEBAR ══════════ -->
<aside id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">🚫</div>
    <div class="sb-title">DLS <em>Admin</em></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Dashboard</div>
    <a href="../../dashboard.php" class="sb-item"><span class="sb-icon"><i class="bi bi-grid-1x2"></i></span> Dashboard</a>
    
    <a href="?statut=bloque" class="sb-item active">
      <span class="sb-icon"><i class="bi bi-person-x"></i></span> Statut des Comptes
      <span class="sb-badge"><?= $stats['total'] ?></span>
    </a>
   
    
    <div class="sb-section">Analytics</div>
    
    <a href="../../admin/logs.php" class="sb-item"><span class="sb-icon"><i class="bi bi-journal-text"></i></span> Logs admin</a>
    
    
  </nav>
  <div class="sb-footer">
    <div style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--t4);text-align:center;padding:4px">
      DLS Admin · v1.0
    </div>
  </div>
</aside>
<div id="mob-overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('show')"></div>

<!-- ══════════ MAIN ══════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header id="topbar">
    <button class="mob-ham" onclick="document.getElementById('sidebar').classList.add('open');document.getElementById('mob-overlay').classList.add('show')"><i class="bi bi-list"></i></button>
    <div class="tb-bc">
      <span>DLS</span>
      <i class="bi bi-chevron-right" style="font-size:.55rem;opacity:.4"></i>
      <span>Utilisateurs</span>
      <i class="bi bi-chevron-right" style="font-size:.55rem;opacity:.4"></i>
      <strong>Comptes Bloqués</strong>
    </div>
    <div class="tb-spacer"></div>
    <!-- Search inline -->
    <form method="GET" action="" style="display:flex">
      <input type="hidden" name="statut" value="bloque">
      <div class="tb-search">
        <i class="bi bi-search" style="font-size:.8rem;color:var(--t3)"></i>
        <input type="text" name="search" placeholder="Nom, email, tél…"
               value="<?= e($search) ?>" oninput="debounceSearch(this.value)">
      </div>
    </form>
    <div class="live-badge" id="live-badge"><div class="live-dot"></div><span>LIVE</span></div>
    <button onclick="doRefresh()" class="btn btn-ghost btn-sm" type="button"><i class="bi bi-arrow-clockwise"></i></button>
    <div class="tb-avatar"><?= $avatar ?></div>
  </header>

  <!-- PAGE -->
  <div class="page">

    <?php if ($dbError): ?>
    <div style="background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);border-radius:var(--r2);padding:1rem 1.4rem;color:var(--rose);font-size:.82rem;margin-bottom:1.2rem">
      <i class="bi bi-exclamation-triangle-fill"></i> Erreur BD : <?= e($dbError) ?>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="page-header">
      <div>
        <div class="ph-title">
          <span style="font-size:1.4rem">🚫</span>
          Comptes Bloqués
          <span class="chip c-danger" style="font-size:.7rem"><?= $stats['total'] ?> total</span>
        </div>
        <div class="ph-sub">
          Gestion des comptes suspendus · Mis à jour : <span id="last-update">maintenant</span>
          <?php if ($search): ?> · Filtre : <strong style="color:var(--rose)"><?= e($search) ?></strong><?php endif; ?>
        </div>
      </div>
      <div class="ph-actions">
  
        <button onclick="openBulkAction()" class="btn btn-warn" type="button" id="bulk-btn" style="display:none">
          <i class="bi bi-check2-square"></i> Actions groupées
        </button>
        <button onclick="doRefresh()" class="btn btn-primary" type="button">
          <i class="bi bi-arrow-clockwise"></i> Actualiser
        </button>
      </div>
    </div>

    <!-- KPI STATS -->
    <div class="kpi-grid">

      <div class="kpi" style="animation-delay:.04s">
        <div class="kpi-icon" style="background:rgba(244,63,94,.12)">🚫</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--rose),var(--violet));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $stats['total'] ?>
        </div>
        <div class="kpi-label">Total bloqués</div>
        <div class="kpi-sub">Tous rôles confondus</div>
        <div class="kpi-glow" style="background:var(--rose)"></div>
      </div>

      <div class="kpi" style="animation-delay:.07s">
        <div class="kpi-icon" style="background:rgba(244,63,94,.08)">📅</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--rose),var(--amber));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $stats['today'] ?>
        </div>
        <div class="kpi-label">Blocages aujourd'hui</div>
        <div class="kpi-sub">Dans les dernières 24h</div>
        <div class="kpi-glow" style="background:var(--amber)"></div>
      </div>

      <div class="kpi" style="animation-delay:.10s">
        <div class="kpi-icon" style="background:rgba(249,115,22,.1)">🗓️</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--orange),var(--amber));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $stats['month'] ?>
        </div>
        <div class="kpi-label">Blocages ce mois</div>
        <div class="kpi-sub">Depuis le 1er du mois</div>
        <div class="kpi-glow" style="background:var(--orange)"></div>
      </div>

      <div class="kpi" style="animation-delay:.13s">
        <div class="kpi-icon" style="background:rgba(244,63,94,.06)">⚠️</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--rose),#ff0055);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $stats['critique'] ?>
        </div>
        <div class="kpi-label">Risque critique</div>
        <div class="kpi-sub">Blocages définitifs</div>
        <div class="kpi-glow" style="background:var(--rose)"></div>
      </div>

      <div class="kpi" style="animation-delay:.16s">
        <div class="kpi-icon" style="background:rgba(245,158,11,.1)">✍️</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--amber),var(--orange));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $stats['journos'] ?>
        </div>
        <div class="kpi-label">Journalistes</div>
        <div class="kpi-sub">Bloqués</div>
        <div class="kpi-glow" style="background:var(--amber)"></div>
      </div>

      <div class="kpi" style="animation-delay:.19s">
        <div class="kpi-icon" style="background:rgba(0,212,255,.08)">📖</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--cyan),var(--violet));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $stats['lecteurs'] ?>
        </div>
        <div class="kpi-label">Lecteurs</div>
        <div class="kpi-sub">Bloqués</div>
        <div class="kpi-glow" style="background:var(--cyan)"></div>
      </div>

      <div class="kpi" style="animation-delay:.22s">
        <div class="kpi-icon" style="background:rgba(124,58,237,.1)">👤</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--violet),var(--rose));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $stats['last'] !== '—' ? mb_substr($stats['last'], 0, 14) : '—' ?>
        </div>
        <div class="kpi-label">Dernier bloqué</div>
        <div class="kpi-sub">Compte suspendu récemment</div>
        <div class="kpi-glow" style="background:var(--violet)"></div>
      </div>

      <div class="kpi" style="animation-delay:.25s">
        <div class="kpi-icon" style="background:rgba(244,63,94,.06)">💸</div>
        <div class="kpi-val" style="font-size:1rem;background:linear-gradient(135deg,var(--rose),var(--amber));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= number_format($stats['total_perte'], 0, ',', ' ') ?>
        </div>
        <div class="kpi-label">FCFA (achats bloqués)</div>
        <div class="kpi-sub">Revenus de comptes suspendus</div>
        <div class="kpi-glow" style="background:var(--rose)"></div>
      </div>

    </div><!-- /kpi-grid -->

    <!-- FILTERS -->
    <form method="GET" action="" id="filter-form">
      <input type="hidden" name="statut" value="bloque">
      <div class="filters-bar">
        <div class="filter-group">
          <label>Rôle</label>
          <select name="role" class="filter-select" onchange="this.form.submit()">
            <option value="">Tous</option>
            <option value="admin"       <?= $roleF==='admin'?'selected':'' ?>>Admins</option>
            <option value="journaliste" <?= $roleF==='journaliste'?'selected':'' ?>>Journalistes</option>
            <option value="lecteur"     <?= $roleF==='lecteur'?'selected':'' ?>>Lecteurs</option>
          </select>
        </div>
        <div class="filter-group">
          <label>Risque</label>
          <select name="risk" class="filter-select" onchange="this.form.submit()">
            <option value="">Tous</option>
            <option value="faible"    <?= $riskF==='faible'?'selected':'' ?>>Faible</option>
            <option value="moyen"     <?= $riskF==='moyen'?'selected':'' ?>>Moyen</option>
            <option value="élevé"     <?= $riskF==='élevé'?'selected':'' ?>>Élevé</option>
            <option value="critique"  <?= $riskF==='critique'?'selected':'' ?>>Critique</option>
          </select>
        </div>
        <div class="filter-group">
          <label>Date</label>
          <input type="date" name="date" class="filter-input" value="<?= e($dateF) ?>" onchange="this.form.submit()">
        </div>
        <div class="filter-group">
          <label>Recherche</label>
          <input type="text" name="search" class="filter-input" placeholder="Nom / email / tél…"
                 value="<?= e($search) ?>" style="width:180px">
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filtrer</button>
          <a href="?statut=bloque" class="btn btn-ghost btn-sm"><i class="bi bi-x"></i> Reset</a>
        </div>
        <div style="margin-left:auto;font-family:'Space Mono',monospace;font-size:.62rem;color:var(--t3)">
          <?= $totalRows ?> résultat<?= $totalRows > 1 ? 's' : '' ?>
        </div>
      </div>
    </form>

    <!-- TABLE -->
    <div class="table-wrap">
      <div class="table-head">
        <div class="table-title">
          <i class="bi bi-person-x" style="color:var(--rose)"></i>
          Comptes Bloqués
          <span class="chip c-danger"><?= $totalRows ?></span>
        </div>
        <div class="table-actions">
          <label class="btn btn-ghost btn-sm" style="cursor:pointer">
            <input type="checkbox" id="select-all" style="margin-right:4px" onchange="toggleAllSelect(this)">
            Tout sélectionner
          </label>
         
        </div>
      </div>

      <?php if (empty($users)): ?>
      <div class="empty-state">
        <div class="empty-icon">🎉</div>
        <div class="empty-title">Aucun compte bloqué</div>
        <div class="empty-sub">
          <?= $search || $roleF || $riskF ? 'Aucun résultat pour ces filtres.' : 'Tous les comptes sont en règle !' ?>
        </div>
        <?php if ($search || $roleF || $riskF): ?>
        <a href="?statut=bloque" class="btn btn-ghost btn-sm" style="margin-top:1rem">Effacer les filtres</a>
        <?php endif; ?>
      </div>
      <?php else: ?>

      <div style="overflow-x:auto">
        <table class="tbl" id="users-table">
          <thead>
            <tr>
              <th style="width:30px"><input type="checkbox" id="head-check" onchange="toggleAllSelect(this)" style="accent-color:var(--rose)"></th>
              <th>Utilisateur</th>
              <th>Email / Tél</th>
              <th>Rôle</th>
              <th>Blocage</th>
              <th>Motif</th>
              <th>Risque</th>
              <th>Achats</th>
              <th>Dépenses</th>
              <th>Dernière co.</th>
              <th style="text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $roleColors = ['admin'=>'c-danger','journaliste'=>'c-warn','lecteur'=>'c-info'];
            $riskColors = ['faible'=>'risk-faible','moyen'=>'risk-moyen','élevé'=>'risk-élevé','critique'=>'risk-critique'];
            $avatarColors = [
                'linear-gradient(135deg,#00d4ff,#7c3aed)',
                'linear-gradient(135deg,#f43f5e,#7c3aed)',
                'linear-gradient(135deg,#f59e0b,#f97316)',
                'linear-gradient(135deg,#00ffaa,#00d4ff)',
                'linear-gradient(135deg,#6366f1,#f43f5e)',
            ];
            foreach ($users as $i => $u):
                $fullName = trim($u['prenom'] . ' ' . $u['nom']);
                $initials = strtoupper(substr($u['prenom'] ?? '?', 0, 1) . substr($u['nom'] ?? '', 0, 1));
                $avatarBg = $avatarColors[$u['id'] % count($avatarColors)];
                $riskClass = $riskColors[$u['risk_level'] ?? 'faible'] ?? 'risk-faible';
                $roleClass = $roleColors[$u['role'] ?? 'lecteur'] ?? 'c-muted';
                $motif = $u['block_reason'] ? mb_substr($u['block_reason'], 0, 35) : '—';
                $motifFull = $u['block_reason'] ?? '';
                $blockedAt = $u['blocked_at'] ? timeAgo($u['blocked_at']) : '—';
                $lastLogin = $u['last_login'] ? timeAgo($u['last_login']) : '—';
            ?>
            <tr data-uid="<?= (int)$u['id'] ?>" class="user-row">
              <td>
                <input type="checkbox" class="row-check" value="<?= (int)$u['id'] ?>"
                       onchange="updateBulkBtn()" style="accent-color:var(--rose)">
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <div class="av" style="background:<?= $avatarBg ?>"><?= e($initials) ?></div>
                  <div>
                    <div class="td-bold" style="white-space:nowrap"><?= e($fullName) ?></div>
                    <div style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--t3)">#<?= (int)$u['id'] ?> · <?= (int)($u['block_count'] ?? 0) ?>x bloqué</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="td-bold" style="font-size:.75rem;white-space:nowrap"><?= e($u['email']) ?></div>
                <?php if ($u['telephone']): ?>
                <div style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--t3)"><?= e($u['telephone']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="chip <?= $roleClass ?>"><?= e($u['role'] ?? '') ?></span></td>
              <td style="white-space:nowrap">
                <div style="font-size:.74rem;color:var(--rose)"><?= e($blockedAt) ?></div>
                <?php if ($u['blocked_at']): ?>
                <div style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--t3)"><?= date('d/m/Y', strtotime($u['blocked_at'])) ?></div>
                <?php endif; ?>
              </td>
              <td style="max-width:150px">
                <?php if ($motif !== '—'): ?>
                <span title="<?= e($motifFull) ?>" style="font-size:.73rem;color:var(--t2);cursor:help">
                  <?= e($motif) ?><?= strlen($motifFull) > 35 ? '…' : '' ?>
                </span>
                <?php else: ?>
                <span style="color:var(--t4);font-size:.7rem">—</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="chip <?= $riskClass ?>"><?= e($u['risk_level'] ?? 'faible') ?></span>
              </td>
              <td class="td-bold" style="text-align:center"><?= (int)$u['nb_achats'] ?></td>
              <td style="font-family:'Space Mono',monospace;font-size:.68rem;color:var(--amber);white-space:nowrap">
                <?= number_format((float)$u['depenses'], 0, ',', ' ') ?> F
              </td>
              <td style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--t3);white-space:nowrap">
                <?= e($lastLogin) ?>
              </td>
              <td style="text-align:center">
                <div class="dd-wrap">
                  <button class="btn btn-ghost btn-xs" onclick="toggleDropdown(this)"
                          type="button" title="Actions" aria-label="Actions pour <?= e($fullName) ?>">
                    <i class="bi bi-three-dots-vertical"></i>
                  </button>
                  <div class="dd-menu" data-uid="<?= (int)$u['id'] ?>" data-name="<?= e($fullName) ?>">
                    <button class="dd-item success" onclick="doAction('debloquer',<?= (int)$u['id'] ?>,<?= json_encode($fullName, JSON_HEX_TAG) ?>,'Débloquer ce compte ?')">
                      <i class="bi bi-person-check"></i> Débloquer
                    </button>
                    <button class="dd-item" onclick="openProfile(<?= (int)$u['id'] ?>)">
                      <i class="bi bi-person-lines-fill"></i> Voir profil
                    </button>
                    <button class="dd-item" onclick="openHistorique(<?= (int)$u['id'] ?>,<?= json_encode($fullName, JSON_HEX_TAG) ?>)">
                      <i class="bi bi-clock-history"></i> Historique achats
                    </button>
                    <button class="dd-item" onclick="openNote(<?= (int)$u['id'] ?>,<?= json_encode($u['admin_note'] ?? '', JSON_HEX_TAG) ?>)">
                      <i class="bi bi-sticky"></i> Note admin
                    </button>
                    <button class="dd-item" onclick="openNotif(<?= (int)$u['id'] ?>,<?= json_encode($fullName, JSON_HEX_TAG) ?>)">
                      <i class="bi bi-bell"></i> Envoyer notification
                    </button>
                    <button class="dd-item" onclick="openRisk(<?= (int)$u['id'] ?>,<?= json_encode($u['risk_level'] ?? 'faible', JSON_HEX_TAG) ?>)">
                      <i class="bi bi-shield-exclamation"></i> Niveau de risque
                    </button>
                    <div class="dd-sep"></div>
                    <button class="dd-item" onclick="doAction('restaurer',<?= (int)$u['id'] ?>,<?= json_encode($fullName, JSON_HEX_TAG) ?>,'Restaurer et réinitialiser ce compte ?')">
                      <i class="bi bi-arrow-counterclockwise"></i> Restaurer
                    </button>
                    <button class="dd-item" onclick="doAction('reset_password',<?= (int)$u['id'] ?>,<?= json_encode($fullName, JSON_HEX_TAG) ?>,'Réinitialiser le mot de passe ?')">
                      <i class="bi bi-key"></i> Reset mot de passe
                    </button>
                    <div class="dd-sep"></div>
                    <button class="dd-item danger" onclick="doAction('bloquer_definitif',<?= (int)$u['id'] ?>,<?= json_encode($fullName, JSON_HEX_TAG) ?>,'Bloquer définitivement ce compte ? Cette action est sévère.')">
                      <i class="bi bi-slash-circle"></i> Bloquer définitivement
                    </button>
                    <button class="dd-item danger" onclick="doAction('supprimer',<?= (int)$u['id'] ?>,<?= json_encode($fullName, JSON_HEX_TAG) ?>,'ATTENTION : Supprimer définitivement cet utilisateur et toutes ses données ?')">
                      <i class="bi bi-trash3"></i> Supprimer
                    </button>
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- PAGINATION -->
      <div class="pagination">
        <div class="pag-info">
          Page <?= $page ?> / <?= $totalPages ?> · <?= $totalRows ?> résultats
        </div>
        <div class="pag-btns">
          <?php
          $qs = http_build_query(array_filter(['statut'=>'bloque','search'=>$search,'role'=>$roleF,'risk'=>$riskF,'date'=>$dateF]));
          ?>
          <a href="?<?= $qs ?>&page=1" class="pag-btn <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-double-left"></i></a>
          <a href="?<?= $qs ?>&page=<?= max(1,$page-1) ?>" class="pag-btn <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
          <?php
          $start = max(1, $page-2); $end = min($totalPages, $page+2);
          for ($p=$start;$p<=$end;$p++):
          ?>
          <a href="?<?= $qs ?>&page=<?= $p ?>" class="pag-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
          <?php endfor; ?>
          <a href="?<?= $qs ?>&page=<?= min($totalPages,$page+1) ?>" class="pag-btn <?= $page>=$totalPages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
          <a href="?<?= $qs ?>&page=<?= $totalPages ?>" class="pag-btn <?= $page>=$totalPages?'disabled':'' ?>"><i class="bi bi-chevron-double-right"></i></a>
        </div>
      </div>

      <?php endif; ?>
    </div><!-- /table-wrap -->

  </div><!-- /page -->
</div><!-- /main -->
</div><!-- /wrapper -->

<!-- ══════════════════════════════════════════
     MODALS
══════════════════════════════════════════ -->

<!-- Note Admin Modal -->
<div class="modal-overlay" id="modal-note">
  <div class="modal-box" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('modal-note')"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">📝 Note admin</div>
    <div class="modal-sub" id="note-subtitle">Ajouter une note sur cet utilisateur</div>
    <input type="hidden" id="note-uid">
    <div class="form-group">
      <label class="form-label">Note interne (visible uniquement par les admins)</label>
      <textarea class="form-textarea" id="note-content" placeholder="Saisissez votre note…" rows="5"></textarea>
    </div>
    <div class="modal-footer">
      <button onclick="closeModal('modal-note')" class="btn btn-ghost" type="button">Annuler</button>
      <button onclick="saveNote()" class="btn btn-primary" type="button"><i class="bi bi-floppy"></i> Enregistrer</button>
    </div>
  </div>
</div>

<!-- Notification Modal -->
<div class="modal-overlay" id="modal-notif">
  <div class="modal-box" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('modal-notif')"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">🔔 Envoyer une notification</div>
    <div class="modal-sub" id="notif-subtitle">Envoyer un message à cet utilisateur</div>
    <input type="hidden" id="notif-uid">
    <div class="form-group">
      <label class="form-label">Message</label>
      <textarea class="form-textarea" id="notif-msg" placeholder="Votre message…" rows="4"></textarea>
    </div>
    <div class="modal-footer">
      <button onclick="closeModal('modal-notif')" class="btn btn-ghost" type="button">Annuler</button>
      <button onclick="sendNotification()" class="btn btn-cyan" type="button"><i class="bi bi-send"></i> Envoyer</button>
    </div>
  </div>
</div>

<!-- Risk Modal -->
<div class="modal-overlay" id="modal-risk">
  <div class="modal-box" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-risk')"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">⚠️ Niveau de risque</div>
    <div class="modal-sub">Modifier le niveau de risque associé à ce compte</div>
    <input type="hidden" id="risk-uid">
    <div class="form-group">
      <label class="form-label">Niveau</label>
      <select class="form-select" id="risk-level">
        <option value="faible">🟢 Faible</option>
        <option value="moyen">🟡 Moyen</option>
        <option value="élevé">🟠 Élevé</option>
        <option value="critique">🔴 Critique</option>
      </select>
    </div>
    <div class="modal-footer">
      <button onclick="closeModal('modal-risk')" class="btn btn-ghost" type="button">Annuler</button>
      <button onclick="saveRisk()" class="btn btn-warn" type="button"><i class="bi bi-shield-exclamation"></i> Appliquer</button>
    </div>
  </div>
</div>

<!-- Profile Modal -->
<div class="modal-overlay" id="modal-profile" style="align-items:flex-start;padding-top:3rem">
  <div class="modal-box" style="max-width:700px;max-height:80vh;overflow-y:auto">
    <button class="modal-close" onclick="closeModal('modal-profile')"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title" id="profile-title">👤 Profil utilisateur</div>
    <div id="profile-content" style="min-height:200px;display:flex;align-items:center;justify-content:center">
      <div class="loader"></div>
    </div>
  </div>
</div>

<!-- Export Modal -->
<div class="modal-overlay" id="modal-export">
  <div class="modal-box" style="max-width:400px">
    <button class="modal-close" onclick="closeModal('modal-export')"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">📤 Exporter les données</div>
    <div class="modal-sub">Télécharger la liste des comptes bloqués</div>
    <div style="display:flex;flex-direction:column;gap:.7rem;margin-bottom:1.2rem">
      <a href="?action=export_csv<?= $search?'&search='.urlencode($search):'' ?><?= $roleF?'&role='.urlencode($roleF):'' ?>"
         class="btn btn-ghost" style="justify-content:center">
        <i class="bi bi-file-earmark-spreadsheet" style="color:var(--neon)"></i> Export CSV (Excel compatible)
      </a>
      <button onclick="exportPDF()" class="btn btn-ghost" type="button" style="justify-content:center">
        <i class="bi bi-filetype-pdf" style="color:var(--rose)"></i> Imprimer / PDF
      </button>
    </div>
    <p style="font-size:.68rem;color:var(--t3);text-align:center">
      Les filtres actifs sont pris en compte dans l'export.
    </p>
  </div>
</div>

<!-- Bulk Action Modal -->
<div class="modal-overlay" id="modal-bulk">
  <div class="modal-box" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('modal-bulk')"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">⚡ Actions groupées</div>
    <div class="modal-sub"><span id="bulk-count">0</span> utilisateurs sélectionnés</div>
    <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.2rem">
      <button onclick="bulkAction('debloquer')" class="btn btn-success" style="justify-content:center" type="button">
        <i class="bi bi-person-check"></i> Débloquer tous
      </button>
      <button onclick="bulkAction('restaurer')" class="btn btn-ghost" style="justify-content:center" type="button">
        <i class="bi bi-arrow-counterclockwise"></i> Restaurer tous
      </button>
      <button onclick="bulkAction('supprimer')" class="btn btn-danger" style="justify-content:center" type="button">
        <i class="bi bi-trash3"></i> Supprimer tous
      </button>
    </div>
  </div>
</div>

<!-- Toast Stack -->
<div id="toast-stack"></div>

<!-- ══════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════ -->
<script>
const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

/* ── Escape helper ── */
function e(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}

/* ── Toast ── */
const T_ICONS   = {success:'✅',info:'ℹ️',warn:'⚠️',error:'🔴',loader:'⏳'};
const T_BORDERS = {success:'var(--neon)',info:'var(--cyan)',warn:'var(--amber)',error:'var(--rose)',loader:'var(--violet)'};
function toast(title, sub='', type='info', dur=4000){
  const stack = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = T_BORDERS[type]||T_BORDERS.info;
  t.innerHTML = `<span style="font-size:1rem;flex-shrink:0">${T_ICONS[type]||'ℹ️'}</span>
    <div style="flex:1"><div class="t-title">${title}</div>${sub?`<div class="t-sub">${sub}</div>`:''}</div>
    <span onclick="this.parentElement.remove()" style="cursor:pointer;color:var(--t3);font-size:.8rem">✕</span>`;
  stack.appendChild(t);
  requestAnimationFrame(()=>requestAnimationFrame(()=>t.classList.add('show')));
  if(dur>0)setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),380);},dur);
  return t;
}

/* ── Modal helpers ── */
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(m=>{
  m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')})
})
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'))})

/* ── Dropdown menus ── */
function toggleDropdown(btn){
  document.querySelectorAll('.dd-menu.open').forEach(m=>{if(m!==btn.nextElementSibling)m.classList.remove('open')})
  btn.nextElementSibling.classList.toggle('open')
}
document.addEventListener('click',e=>{
  if(!e.target.closest('.dd-wrap'))document.querySelectorAll('.dd-menu.open').forEach(m=>m.classList.remove('open'))
})

/* ── AJAX action ── */
async function doAction(action, uid, name, confirm_msg=''){
  if(confirm_msg){
    if(!confirm(`${confirm_msg}\n\n→ ${name}`))return;
  }
  const body = new FormData();
  body.append('action', action);
  body.append('user_id', uid);
  body.append('csrf_token', CSRF);
  document.querySelectorAll('.dd-menu.open').forEach(m=>m.classList.remove('open'));
  const t = toast('Traitement…','',  'loader', 0);
  try{
    const res = await fetch(window.location.href, {
      method:'POST',
      headers:{'X-Requested-With':'XMLHttpRequest'},
      body
    });
    const data = await res.json();
    t.classList.remove('show');
    setTimeout(()=>t.remove(),400);
    if(data.ok){
      toast('✓ Succès', data.msg, 'success');
      if(['debloquer','supprimer','restaurer','bloquer_definitif'].includes(action)){
        setTimeout(()=>{
          const row = document.querySelector(`tr[data-uid="${uid}"]`);
          if(action==='supprimer'){
            row?.remove();
          }else{
            row?.style&&(row.style.opacity='.4');
            setTimeout(()=>window.location.reload(),1200);
          }
        },600);
      }
    }else{
      toast('Erreur', data.msg, 'error');
    }
  }catch(err){
    t.classList.remove('show'); setTimeout(()=>t.remove(),400);
    toast('Erreur réseau', err.message, 'error');
  }
}

/* ── Profile ── */
async function openProfile(uid){
  document.getElementById('profile-title').textContent = '⏳ Chargement…';
  document.getElementById('profile-content').innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:3rem"><div class="loader"></div></div>';
  openModal('modal-profile');
  const body = new FormData();
  body.append('action','get_profile');
  body.append('user_id',uid);
  body.append('csrf_token',CSRF);
  try{
    const res = await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body});
    const data = await res.json();
    if(!data.ok){document.getElementById('profile-content').innerHTML=`<p style="color:var(--rose);text-align:center">${e(data.msg)}</p>`;return;}
    const u = data.user;
    const achats = data.achats||[];
    document.getElementById('profile-title').innerHTML = `👤 ${e((u.prenom||'')+' '+(u.nom||''))} <span class="chip c-danger" style="margin-left:.4rem">BLOQUÉ</span>`;
    let achatRows = achats.length ? achats.map(a=>`
      <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);font-size:.75rem">
        <span style="color:var(--t2)">${e(a.titre||'—')}</span>
        <span style="font-family:'Space Mono',monospace;color:var(--neon);font-size:.68rem">${Number(a.montant||0).toLocaleString('fr-CM')} F</span>
        <span style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--t3)">${a.created_at?a.created_at.substring(0,10):'—'}</span>
      </div>`).join('') : '<p style="color:var(--t3);font-size:.77rem;text-align:center;padding:.8rem 0">Aucun achat</p>';
    document.getElementById('profile-content').innerHTML = `
      <div class="profile-grid">
        <div class="profile-box"><div class="pb-label">Email</div><div class="pb-val">${e(u.email||'—')}</div></div>
        <div class="profile-box"><div class="pb-label">Téléphone</div><div class="pb-val">${e(u.telephone||'—')}</div></div>
        <div class="profile-box"><div class="pb-label">Rôle</div><div class="pb-val">${e(u.role||'—')}</div></div>
        <div class="profile-box"><div class="pb-label">Niveau de risque</div><div class="pb-val"><span class="chip ${({faible:'risk-faible',moyen:'risk-moyen','élevé':'risk-élevé',critique:'risk-critique'})[u.risk_level||'faible']||'risk-faible'}">${e(u.risk_level||'faible')}</span></div></div>
        <div class="profile-box"><div class="pb-label">Inscrit le</div><div class="pb-val">${u.created_at?u.created_at.substring(0,10):'—'}</div></div>
        <div class="profile-box"><div class="pb-label">Dernière connexion</div><div class="pb-val">${u.last_login?u.last_login.substring(0,16):'Jamais'}</div></div>
        <div class="profile-box" style="grid-column:1/-1"><div class="pb-label">Motif du blocage</div><div class="pb-val" style="color:var(--rose)">${e(u.block_reason||'Non renseigné')}</div></div>
        ${u.admin_note?`<div class="profile-box" style="grid-column:1/-1"><div class="pb-label">Note admin</div><div class="pb-val" style="color:var(--amber)">${e(u.admin_note)}</div></div>`:''}
      </div>
      <div style="font-family:'Space Mono',monospace;font-size:.6rem;text-transform:uppercase;color:var(--t3);margin-bottom:.5rem">Historique achats (10 derniers)</div>
      <div style="max-height:180px;overflow-y:auto;padding-right:4px">${achatRows}</div>
      <div style="display:flex;gap:.6rem;margin-top:1.2rem;flex-wrap:wrap">
        <button onclick="doAction('debloquer',${uid},'${e((u.prenom||'')+' '+(u.nom||''))}',' Débloquer ce compte ?')" class="btn btn-success btn-sm" type="button"><i class="bi bi-person-check"></i> Débloquer</button>
        <button onclick="doAction('reset_password',${uid},'${e((u.prenom||'')+' '+(u.nom||''))}',' Réinitialiser le mot de passe ?')" class="btn btn-cyan btn-sm" type="button"><i class="bi bi-key"></i> Reset mdp</button>
        
        <button onclick="doAction('supprimer',${uid},'${e((u.prenom||'')+' '+(u.nom||''))}',' SUPPRIMER définitivement ?')" class="btn btn-danger btn-sm" type="button"><i class="bi bi-trash3"></i> Supprimer</button>
      </div>`;
  }catch(err){
    document.getElementById('profile-content').innerHTML=`<p style="color:var(--rose);text-align:center">Erreur : ${e(err.message)}</p>`;
  }
}

function openHistorique(uid, name){
  openProfile(uid); // Profile inclut déjà l'historique
  toast('Historique','Profil chargé avec l\'historique des achats.','info');
}

/* ── Note ── */
function openNote(uid, currentNote){
  document.getElementById('note-uid').value=uid;
  document.getElementById('note-content').value=currentNote||'';
  document.getElementById('note-subtitle').textContent='Note pour l\'utilisateur #'+uid;
  openModal('modal-note');
  setTimeout(()=>document.getElementById('note-content').focus(),120);
}
async function saveNote(){
  const uid=document.getElementById('note-uid').value;
  const note=document.getElementById('note-content').value;
  const body=new FormData();
  body.append('action','add_note');body.append('user_id',uid);body.append('note',note);body.append('csrf_token',CSRF);
  const res=await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body});
  const data=await res.json();
  closeModal('modal-note');
  toast(data.ok?'Note enregistrée':'Erreur',data.msg,data.ok?'success':'error');
}

/* ── Notification ── */
function openNotif(uid, name){
  document.getElementById('notif-uid').value=uid;
  document.getElementById('notif-subtitle').textContent='Envoyer un message à '+name;
  document.getElementById('notif-msg').value='';
  openModal('modal-notif');
  setTimeout(()=>document.getElementById('notif-msg').focus(),120);
}
async function sendNotification(){
  const uid=document.getElementById('notif-uid').value;
  const msg=document.getElementById('notif-msg').value;
  if(!msg.trim()){toast('Vide','Saisissez un message.','warn');return;}
  const body=new FormData();
  body.append('action','send_notification');body.append('user_id',uid);body.append('message',msg);body.append('csrf_token',CSRF);
  const res=await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body});
  const data=await res.json();
  closeModal('modal-notif');
  toast(data.ok?'Notification envoyée':'Erreur',data.msg,data.ok?'success':'error');
}

/* ── Risk ── */
function openRisk(uid, current){
  document.getElementById('risk-uid').value=uid;
  document.getElementById('risk-level').value=current||'faible';
  openModal('modal-risk');
}
async function saveRisk(){
  const uid=document.getElementById('risk-uid').value;
  const level=document.getElementById('risk-level').value;
  const body=new FormData();
  body.append('action','change_risk');body.append('user_id',uid);body.append('level',level);body.append('csrf_token',CSRF);
  const res=await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body});
  const data=await res.json();
  closeModal('modal-risk');
  if(data.ok){
    toast('Risque mis à jour','Niveau : '+level,'success');
    setTimeout(()=>window.location.reload(),1200);
  }else{toast('Erreur',data.msg,'error');}
}

/* ── Select all ── */
function toggleAllSelect(cb){
  document.querySelectorAll('.row-check').forEach(c=>c.checked=cb.checked);
  if(document.getElementById('head-check'))document.getElementById('head-check').checked=cb.checked;
  if(document.getElementById('select-all'))document.getElementById('select-all').checked=cb.checked;
  updateBulkBtn();
}
function updateBulkBtn(){
  const checked=document.querySelectorAll('.row-check:checked').length;
  const btn=document.getElementById('bulk-btn');
  if(btn)btn.style.display=checked>0?'inline-flex':'none';
}
function openBulkAction(){
  const checked=[...document.querySelectorAll('.row-check:checked')].map(c=>c.value);
  document.getElementById('bulk-count').textContent=checked.length;
  openModal('modal-bulk');
}
async function bulkAction(action){
  const uids=[...document.querySelectorAll('.row-check:checked')].map(c=>parseInt(c.value));
  if(!uids.length){toast('Vide','Sélectionnez au moins un utilisateur.','warn');return;}
  if(!confirm(`Appliquer « ${action} » à ${uids.length} utilisateur(s) ?`))return;
  closeModal('modal-bulk');
  const t=toast(`Traitement de ${uids.length} comptes…`,'','loader',0);
  let ok=0;
  for(const uid of uids){
    const body=new FormData();
    body.append('action',action);body.append('user_id',uid);body.append('csrf_token',CSRF);
    try{
      const res=await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body});
      const data=await res.json();
      if(data.ok)ok++;
    }catch(e){}
  }
  t.classList.remove('show');setTimeout(()=>t.remove(),400);
  toast(`${ok}/${uids.length} comptes traités`,'','success');
  setTimeout(()=>window.location.reload(),1500);
}

/* ── Export ── */
function openExportModal(){openModal('modal-export')}
function exportPDF(){
  window.print();
  closeModal('modal-export');
}

/* ── Search debounce ── */
let searchTimer;
function debounceSearch(v){
  clearTimeout(searchTimer);
  if(v.length===0){clearTimeout(searchTimer);return;}
  searchTimer=setTimeout(()=>document.getElementById('filter-form').submit(),600);
}

/* ── Live refresh ── */
let elapsed=0;
setInterval(()=>{
  elapsed++;
  const el=document.getElementById('last-update');
  if(el)el.textContent='il y a '+elapsed+'s';
  if(elapsed>=60){elapsed=0;window.location.reload();}
},1000);

function doRefresh(){
  toast('Actualisation…','','info',1500);
  setTimeout(()=>window.location.reload(),600);
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded',()=>{
  setTimeout(()=>{
    const total=<?= $stats['total'] ?>;
    if(total>0){
      toast('Comptes bloqués',`${total} compte${total>1?'s':''} suspendu${total>1?'s':''} · Données en temps réel`,'warn',5000);
    }
    <?php if ($stats['critique'] > 0): ?>
    setTimeout(()=>toast('⚠️ Alerte','<?= $stats['critique'] ?> compte(s) en risque critique !','error',6000),1500);
    <?php endif; ?>
  },700);
});
</script>
</body>
</html>