<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — admin/logs.php v1.0                          ║
 * ║  Centre de surveillance & d'audit ultra-moderne                  ║
 * ║  100% fonctionnel · PDO sécurisé · AJAX temps réel              ║
 * ║  Dark mode · Glassmorphism · Export CSV/PDF/Excel                ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// ── Session ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Config BD ────────────────────────────────────────────────
foreach ([
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../../includes/config.php',
] as $_cfgPath) {
    if (file_exists($_cfgPath) && !defined('DB_HOST_LOADED')) {
        require_once $_cfgPath;
        define('DB_HOST_LOADED', true);
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
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        error_log('[DLS-LOGS] PDO connection failed: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth guard ───────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); exit;
}
$userRole = $_SESSION['user_role'] ?? 'lecteur';
if ($userRole !== 'admin') {
    header('Location: ../dashboard.php?error=access_denied'); exit;
}
$userId   = (int)$_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$avatar   = strtoupper(substr($username, 0, 1)) ?: 'A';

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Créer la table admin_logs si absente ─────────────────────
if ($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_logs (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                action     VARCHAR(150) NOT NULL,
                module     VARCHAR(100) DEFAULT NULL,
                detail     TEXT         DEFAULT NULL,
                ip         VARCHAR(45)  DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                severity   ENUM('info','warning','danger','critical') DEFAULT 'info',
                created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user     (user_id),
                INDEX idx_action   (action),
                INDEX idx_created  (created_at),
                INDEX idx_severity (severity),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Ajouter colonnes manquantes si table plus ancienne
        $cols = $pdo->query("SHOW COLUMNS FROM admin_logs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('module',   $cols)) $pdo->exec("ALTER TABLE admin_logs ADD COLUMN module VARCHAR(100) DEFAULT NULL AFTER action");
        if (!in_array('severity', $cols)) $pdo->exec("ALTER TABLE admin_logs ADD COLUMN severity ENUM('info','warning','danger','critical') DEFAULT 'info'");
    } catch (Throwable $e) {
        error_log('[DLS-LOGS] Table setup error: ' . $e->getMessage());
    }
}

// ── Fonction log automatique ─────────────────────────────────
function writeLog(PDO $pdo, int $uid, string $action, string $module = '', string $detail = '', string $severity = 'info'): void {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        $ip = filter_var(explode(',', $ip)[0], FILTER_VALIDATE_IP) ?: '0.0.0.0';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $st = $pdo->prepare(
            "INSERT INTO admin_logs (user_id,action,module,detail,ip,user_agent,severity)
             VALUES (?,?,?,?,?,?,?)"
        );
        $st->execute([$uid, $action, $module ?: null, $detail ?: null, $ip, $ua, $severity]);
    } catch (Throwable $e) {
        error_log('[DLS-LOGS] writeLog failed: ' . $e->getMessage());
    }
}

// ── Logger la visite admin ────────────────────────────────────
if ($pdo) {
    writeLog($pdo, $userId, 'admin_page_view', 'logs', 'Accès à admin/logs.php', 'info');
}

// ══════════════════════════════════════════════════════════════
// ── HANDLERS AJAX ─────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_REQUEST['ajax_action'] ?? '';

    // Valider CSRF pour les actions destructives
    $csrfActions = ['delete_log', 'delete_all', 'delete_filtered', 'mark_critical'];
    if (in_array($action, $csrfActions, true)) {
        $tok = $_POST['csrf'] ?? $_SERVER['HTTP_X_Csrf_Token'] ?? '';
        if (!hash_equals($csrfToken, $tok)) {
            echo json_encode(['ok' => false, 'error' => 'CSRF invalide']);
            exit;
        }
    }

    // ── Fetch logs ──────────────────────────────────────────
    if ($action === 'fetch_logs') {
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = 25;
        $offset   = ($page - 1) * $perPage;
        $severity = $_GET['severity'] ?? '';
        $module   = $_GET['module']   ?? '';
        $period   = $_GET['period']   ?? '';
        $search   = trim($_GET['search'] ?? '');
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to']   ?? '';

        $where = ['1=1'];
        $params = [];

        if ($severity && in_array($severity, ['info','warning','danger','critical'])) {
            $where[]  = 'al.severity = ?'; $params[] = $severity;
        }
        if ($module) {
            $where[]  = 'al.module = ?'; $params[] = $module;
        }
        if ($period === 'today') {
            $where[] = 'DATE(al.created_at) = CURDATE()';
        } elseif ($period === 'week') {
            $where[] = 'al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        } elseif ($period === 'month') {
            $where[] = 'al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        if ($dateFrom) {
            $where[] = 'DATE(al.created_at) >= ?'; $params[] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = 'DATE(al.created_at) <= ?'; $params[] = $dateTo;
        }
        if ($search) {
            $where[] = '(al.action LIKE ? OR al.detail LIKE ? OR al.ip LIKE ? OR u.nom LIKE ? OR u.prenom LIKE ?)';
            $s = '%' . $search . '%';
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
        }

        $whereSQL = implode(' AND ', $where);

        try {
            $countSt = $pdo->prepare("SELECT COUNT(*) FROM admin_logs al LEFT JOIN users u ON u.id=al.user_id WHERE $whereSQL");
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            $dataSt = $pdo->prepare("
                SELECT al.id, al.action, al.module, al.detail, al.ip, al.user_agent,
                       al.severity, al.created_at,
                       CONCAT(u.prenom,' ',u.nom) AS user_name, u.role AS user_role
                FROM admin_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE $whereSQL
                ORDER BY al.created_at DESC
                LIMIT $perPage OFFSET $offset
            ");
            $dataSt->execute($params);
            $logs = $dataSt->fetchAll();

            echo json_encode([
                'ok'        => true,
                'logs'      => $logs,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'pages'     => (int)ceil($total / $perPage),
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Stats temps réel ────────────────────────────────────
    if ($action === 'fetch_stats') {
        try {
            $stats = [
                'total'        => (int)$pdo->query("SELECT COUNT(*) FROM admin_logs")->fetchColumn(),
                'today'        => (int)$pdo->query("SELECT COUNT(*) FROM admin_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
                'critical'     => (int)$pdo->query("SELECT COUNT(*) FROM admin_logs WHERE severity='critical'")->fetchColumn(),
                'warnings'     => (int)$pdo->query("SELECT COUNT(*) FROM admin_logs WHERE severity='warning' AND DATE(created_at)=CURDATE()")->fetchColumn(),
                'danger'       => (int)$pdo->query("SELECT COUNT(*) FROM admin_logs WHERE severity='danger' AND DATE(created_at)=CURDATE()")->fetchColumn(),
                'connections'  => (int)$pdo->query("SELECT COUNT(*) FROM admin_logs WHERE action='admin_login' AND DATE(created_at)=CURDATE()")->fetchColumn(),
                'last_hour'    => (int)$pdo->query("SELECT COUNT(*) FROM admin_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn(),
            ];

            // Activité par heure (24h)
            $hourlyRaw = $pdo->query("
                SELECT HOUR(created_at) AS h, COUNT(*) AS n
                FROM admin_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY HOUR(created_at)
                ORDER BY h
            ")->fetchAll();
            $hourly = array_fill(0, 24, 0);
            foreach ($hourlyRaw as $r) $hourly[(int)$r['h']] = (int)$r['n'];
            $stats['hourly'] = $hourly;

            // Répartition sévérité
            $sevRaw = $pdo->query("SELECT severity, COUNT(*) AS n FROM admin_logs GROUP BY severity")->fetchAll();
            $sev = ['info'=>0,'warning'=>0,'danger'=>0,'critical'=>0];
            foreach ($sevRaw as $r) $sev[$r['severity']] = (int)$r['n'];
            $stats['severity_dist'] = $sev;

            // Top modules
            $modRaw = $pdo->query("SELECT module, COUNT(*) AS n FROM admin_logs WHERE module IS NOT NULL GROUP BY module ORDER BY n DESC LIMIT 6")->fetchAll();
            $stats['top_modules'] = $modRaw;

            // Top users actifs
            $topUsersRaw = $pdo->query("
                SELECT CONCAT(u.prenom,' ',u.nom) AS name, COUNT(*) AS n
                FROM admin_logs al
                JOIN users u ON u.id=al.user_id
                GROUP BY al.user_id ORDER BY n DESC LIMIT 5
            ")->fetchAll();
            $stats['top_users'] = $topUsersRaw;

            echo json_encode(['ok'=>true,'stats'=>$stats]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Supprimer un log ─────────────────────────────────────
    if ($action === 'delete_log') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID manquant']); exit; }
        try {
            $pdo->prepare("DELETE FROM admin_logs WHERE id=?")->execute([$id]);
            writeLog($pdo, $userId, 'delete_log', 'logs', "Suppression log #$id", 'warning');
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ── Nettoyage complet ────────────────────────────────────
    if ($action === 'delete_all') {
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM admin_logs")->fetchColumn();
            $pdo->exec("TRUNCATE TABLE admin_logs");
            writeLog($pdo, $userId, 'truncate_logs', 'logs', "Nettoyage: $count logs supprimés", 'critical');
            echo json_encode(['ok'=>true,'count'=>$count]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ── Export CSV ───────────────────────────────────────────
    if ($action === 'export_csv') {
        try {
            $rows = $pdo->query("
                SELECT al.id, CONCAT(u.prenom,' ',u.nom) AS utilisateur, u.role,
                       al.action, al.module, al.detail, al.ip, al.user_agent,
                       al.severity, al.created_at
                FROM admin_logs al
                LEFT JOIN users u ON u.id=al.user_id
                ORDER BY al.created_at DESC LIMIT 10000
            ")->fetchAll();

            $csv  = "\xEF\xBB\xBF"; // BOM UTF-8
            $csv .= "ID,Utilisateur,Rôle,Action,Module,Détail,IP,User-Agent,Sévérité,Date\r\n";
            foreach ($rows as $r) {
                $csv .= implode(',', array_map(function($v) {
                    return '"' . str_replace('"', '""', $r[$v] ?? '') . '"';
                }, ['id','utilisateur','role','action','module','detail','ip','user_agent','severity','created_at'])) . "\r\n";
            }
            writeLog($pdo, $userId, 'export_logs_csv', 'logs', count($rows).' lignes exportées', 'info');
            echo json_encode(['ok'=>true,'csv'=>base64_encode($csv),'count'=>count($rows)]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }

    // ── Modules disponibles ──────────────────────────────────
    if ($action === 'fetch_modules') {
        try {
            $mods = $pdo->query("SELECT DISTINCT module FROM admin_logs WHERE module IS NOT NULL ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['ok'=>true,'modules'=>$mods]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false,'modules',[]]); }
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Action inconnue']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit & Logs — Digital Library Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ════════════════════════════════════════════════
   RESET & CSS VARIABLES
════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg-base:     #04060e;
  --bg-surface:  #080c1a;
  --bg-card:     rgba(255,255,255,.032);
  --bg-hover:    rgba(255,255,255,.058);
  --border:      rgba(255,255,255,.07);
  --border-glow: rgba(0,200,255,.4);

  --cyan:   #00d4ff;
  --violet: #7c3aed;
  --neon:   #00ffaa;
  --amber:  #f59e0b;
  --rose:   #f43f5e;
  --orange: #f97316;
  --sky:    #38bdf8;

  --sev-info:     #00d4ff;
  --sev-warning:  #f59e0b;
  --sev-danger:   #f97316;
  --sev-critical: #f43f5e;

  --text-1: #eef2ff;
  --text-2: rgba(238,242,255,.58);
  --text-3: rgba(238,242,255,.28);

  --sidebar-w: 260px;
  --topbar-h:  62px;
  --r-sm: 8px;
  --r-md: 13px;
  --r-lg: 18px;
  --r-xl: 26px;

  --glow-cyan: 0 0 28px rgba(0,212,255,.18);
  --shadow-lg: 0 24px 64px rgba(0,0,0,.5);
  --shadow-card: 0 4px 24px rgba(0,0,0,.3);
}

html { scroll-behavior: smooth; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg-base);
  color: var(--text-1);
  overflow-x: hidden;
  min-height: 100vh;
}
/* Scanline overlay */
body::before {
  content: '';
  position: fixed; inset: 0;
  background: repeating-linear-gradient(
    0deg, transparent, transparent 2px,
    rgba(0,0,0,.015) 2px, rgba(0,0,0,.015) 4px
  );
  pointer-events: none; z-index: 9999;
}

::-webkit-scrollbar { width: 3px; height: 3px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(0,212,255,.2); border-radius: 4px; }

/* ── LAYOUT ── */
.app { display: flex; min-height: 100vh; }

/* ═══════ SIDEBAR ═══════ */
#sidebar {
  position: fixed; top: 0; left: 0; bottom: 0;
  width: var(--sidebar-w);
  background: var(--bg-surface);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  z-index: 200; overflow: hidden;
  transition: width .3s cubic-bezier(.4,0,.2,1);
}
#sidebar.collapsed { width: 66px; }

.sb-brand {
  height: var(--topbar-h);
  display: flex; align-items: center; gap: 11px;
  padding: 0 16px; border-bottom: 1px solid var(--border);
  flex-shrink: 0; position: relative; overflow: hidden;
}
.sb-brand::after {
  content: '';
  position: absolute; bottom: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, var(--cyan), var(--violet));
}
.brand-ico {
  width: 36px; height: 36px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--cyan), var(--violet));
  border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem; box-shadow: var(--glow-cyan);
}
.brand-txt {
  font-family: 'Syne', sans-serif; font-weight: 800; font-size: .88rem;
  white-space: nowrap; overflow: hidden;
  transition: opacity .2s;
}
.brand-txt em { color: var(--cyan); font-style: normal; }
#sidebar.collapsed .brand-txt { opacity: 0; pointer-events: none; }

.sb-user {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 15px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.sb-av {
  width: 36px; height: 36px; border-radius: 11px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--rose), var(--violet));
  display: flex; align-items: center; justify-content: center;
  font-family: 'Syne', sans-serif; font-weight: 800; font-size: .82rem;
}
.sb-uinfo { overflow: hidden; transition: opacity .2s; }
#sidebar.collapsed .sb-uinfo { opacity: 0; pointer-events: none; }
.sb-uname {
  font-family: 'Syne', sans-serif; font-weight: 700; font-size: .8rem;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sb-urole {
  font-family: 'Space Mono', monospace; font-size: .58rem;
  color: var(--rose); letter-spacing: .04em; text-transform: uppercase; margin-top: 2px;
}

.sb-nav { flex: 1; overflow-y: auto; padding: 8px 0; }
.sb-section {
  font-family: 'Space Mono', monospace; font-size: .56rem;
  letter-spacing: .12em; text-transform: uppercase;
  color: var(--text-3); padding: 8px 16px 3px;
  white-space: nowrap; overflow: hidden;
  transition: opacity .2s;
}
#sidebar.collapsed .sb-section { opacity: 0; }

.nav-lnk {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 14px; margin: 2px 7px; border-radius: var(--r-sm);
  text-decoration: none; color: var(--text-2); font-size: .82rem; font-weight: 500;
  transition: all .18s; white-space: nowrap; overflow: hidden; position: relative;
}
.nav-lnk:hover { color: var(--text-1); background: var(--bg-hover); }
.nav-lnk.active {
  color: var(--rose); background: rgba(244,63,94,.08);
  border: 1px solid rgba(244,63,94,.12);
}
.nav-lnk.active::before {
  content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
  width: 3px; height: 16px; background: var(--rose); border-radius: 0 3px 3px 0;
  box-shadow: 0 0 10px var(--rose);
}
.nav-ico { font-size: 1rem; width: 20px; text-align: center; flex-shrink: 0; }
.nav-lbl { transition: opacity .2s; }
#sidebar.collapsed .nav-lbl { opacity: 0; }

.sb-foot {
  padding: 10px; border-top: 1px solid var(--border); flex-shrink: 0;
}
.collapse-btn {
  width: 100%; display: flex; align-items: center; gap: 10px;
  padding: 8px; border-radius: var(--r-sm);
  background: none; border: none; color: var(--text-3);
  cursor: pointer; font-size: .78rem; font-family: 'DM Sans', sans-serif;
  transition: all .18s;
}
.collapse-btn:hover { color: var(--text-1); background: var(--bg-hover); }
.collapse-ico { font-size: .9rem; flex-shrink: 0; transition: transform .3s; }
#sidebar.collapsed .collapse-ico { transform: rotate(180deg); }
.collapse-lbl { transition: opacity .2s; white-space: nowrap; }
#sidebar.collapsed .collapse-lbl { opacity: 0; }

/* ═══════ MAIN ═══════ */
.main {
  margin-left: var(--sidebar-w);
  flex: 1; display: flex; flex-direction: column;
  min-height: 100vh;
  transition: margin-left .3s cubic-bezier(.4,0,.2,1);
}
.main.collapsed { margin-left: 66px; }

/* ═══════ TOPBAR ═══════ */
#topbar {
  height: var(--topbar-h);
  background: rgba(4,6,14,.9);
  backdrop-filter: blur(24px);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 1rem;
  padding: 0 1.6rem;
  position: sticky; top: 0; z-index: 100;
}
.topbar-breadcrumb {
  display: flex; align-items: center; gap: 6px;
  font-size: .78rem; color: var(--text-3);
}
.bc-sep { opacity: .3; }
.bc-curr {
  font-family: 'Syne', sans-serif; font-weight: 700;
  color: var(--text-1);
}
.tb-spacer { flex: 1; }

/* Live indicator */
.live-dot {
  display: flex; align-items: center; gap: 6px;
  font-family: 'Space Mono', monospace; font-size: .62rem;
  color: var(--neon); padding: 4px 10px;
  background: rgba(0,255,170,.06);
  border: 1px solid rgba(0,255,170,.15);
  border-radius: 100px;
}
.live-pulse {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--neon);
  box-shadow: 0 0 8px var(--neon);
  animation: livePulse 1.6s ease-in-out infinite;
}
@keyframes livePulse {
  0%,100% { opacity: 1; transform: scale(1); }
  50% { opacity: .4; transform: scale(.7); }
}

.tb-btn {
  width: 34px; height: 34px; border-radius: var(--r-sm);
  background: var(--bg-card); border: 1px solid var(--border);
  color: var(--text-2); display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: .92rem; text-decoration: none;
  transition: all .18s;
}
.tb-btn:hover { color: var(--text-1); background: var(--bg-hover); border-color: rgba(255,255,255,.12); }
.ham { display: none; }

/* ═══════ PAGE CONTENT ═══════ */
.page { flex: 1; padding: 1.8rem 1.8rem 5rem; max-width: 1600px; margin: 0 auto; width: 100%; }

/* ── Page header ── */
.pg-header {
  display: flex; align-items: flex-end; justify-content: space-between;
  flex-wrap: wrap; gap: 1rem; margin-bottom: 1.8rem;
}
.pg-title-wrap {}
.pg-eyebrow {
  font-family: 'Space Mono', monospace; font-size: .58rem;
  letter-spacing: .18em; text-transform: uppercase;
  color: var(--rose); margin-bottom: 5px;
}
.pg-title {
  font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.85rem;
  letter-spacing: -.5px; line-height: 1;
  background: linear-gradient(135deg, #fff 30%, rgba(255,255,255,.5));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.pg-sub { font-size: .78rem; color: var(--text-2); margin-top: 6px; }

.pg-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ═══════ STAT CARDS ═══════ */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: .9rem; margin-bottom: 1.6rem;
}
.stat-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1.3rem;
  backdrop-filter: blur(10px);
  position: relative; overflow: hidden;
  transition: transform .22s, border-color .22s, box-shadow .22s;
  animation: fadeUp .5s ease both;
}
.stat-card::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, var(--c1, #fff), var(--c2, #888));
  opacity: 0; transition: opacity .3s;
}
.stat-card:hover { transform: translateY(-4px); border-color: rgba(255,255,255,.1); }
.stat-card:hover::before { opacity: 1; }

.stat-card:nth-child(1) { --c1: var(--cyan); --c2: var(--violet); animation-delay:.04s }
.stat-card:nth-child(2) { --c1: var(--neon); --c2: var(--cyan);   animation-delay:.08s }
.stat-card:nth-child(3) { --c1: var(--rose); --c2: var(--orange); animation-delay:.12s }
.stat-card:nth-child(4) { --c1: var(--amber);--c2: var(--orange); animation-delay:.16s }
.stat-card:nth-child(5) { --c1: var(--orange);--c2:var(--rose);   animation-delay:.20s }
.stat-card:nth-child(6) { --c1: var(--violet);--c2:var(--sky);    animation-delay:.24s }

.sc-ico {
  width: 40px; height: 40px; border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; margin-bottom: .9rem;
  background: rgba(255,255,255,.05);
}
.sc-val {
  font-family: 'Syne', sans-serif; font-size: 1.9rem; font-weight: 800;
  letter-spacing: -.5px; line-height: 1;
  background: linear-gradient(135deg, var(--c1, #fff), var(--c2, #aaa));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.sc-label { font-size: .72rem; color: var(--text-2); margin-top: 5px; font-weight: 500; }
.sc-meta {
  margin-top: 7px; font-size: .63rem; font-family: 'Space Mono', monospace;
  display: flex; align-items: center; gap: 3px;
}
.c-neon   { color: var(--neon); }
.c-rose   { color: var(--rose); }
.c-amber  { color: var(--amber); }
.c-muted  { color: var(--text-3); }

/* ═══════ CHARTS ROW ═══════ */
.charts-row {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 1rem; margin-bottom: 1.4rem;
}
@media(max-width:1100px){.charts-row{grid-template-columns:1fr}}

/* ═══════ CARDS ═══════ */
.card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
  backdrop-filter: blur(10px);
  animation: fadeUp .5s ease both;
}
.card-hd {
  padding: 1rem 1.3rem;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between; gap: .8rem;
}
.card-title {
  font-family: 'Syne', sans-serif; font-weight: 700; font-size: .88rem;
  display: flex; align-items: center; gap: 8px;
}
.ct-dot {
  width: 28px; height: 28px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: .82rem;
}
.card-bd { padding: 1.1rem 1.3rem; }
.card-ft {
  padding: .8rem 1.3rem;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}

/* ═══════ FILTERS BAR ═══════ */
.filters-bar {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1.1rem 1.3rem;
  margin-bottom: 1rem;
  display: flex; flex-wrap: wrap; gap: .7rem;
  align-items: center;
  backdrop-filter: blur(10px);
  animation: fadeUp .45s ease both;
}

.search-box {
  display: flex; align-items: center; gap: 7px;
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border); border-radius: var(--r-sm);
  padding: 7px 12px; flex: 1; min-width: 180px;
  transition: border-color .2s, box-shadow .2s;
}
.search-box:focus-within { border-color: var(--border-glow); box-shadow: var(--glow-cyan); }
.search-box input {
  background: none; border: none; outline: none;
  color: var(--text-1); font-size: .8rem;
  font-family: 'DM Sans', sans-serif; width: 100%;
}
.search-box input::placeholder { color: var(--text-3); }

.sel {
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border); border-radius: var(--r-sm);
  color: var(--text-1); padding: 7px 10px; font-size: .78rem;
  font-family: 'DM Sans', sans-serif; outline: none; cursor: pointer;
  transition: border-color .2s;
}
.sel:focus { border-color: var(--border-glow); }
.sel option { background: #0b1020; color: var(--text-1); }

.date-input {
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border); border-radius: var(--r-sm);
  color: var(--text-1); padding: 7px 10px; font-size: .78rem;
  font-family: 'DM Sans', sans-serif; outline: none;
  transition: border-color .2s;
}
.date-input:focus { border-color: var(--border-glow); }

/* ═══════ SEVERITY TABS ═══════ */
.sev-tabs {
  display: flex; gap: 5px;
  background: rgba(255,255,255,.03);
  border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 4px;
}
.sev-tab {
  display: flex; align-items: center; gap: 5px;
  padding: 5px 12px; border-radius: var(--r-sm);
  cursor: pointer; font-size: .72rem;
  font-family: 'Space Mono', monospace;
  color: var(--text-3); transition: all .18s;
  border: none; background: none;
  white-space: nowrap;
}
.sev-tab:hover { color: var(--text-1); background: var(--bg-hover); }
.sev-tab.active { color: #000; font-weight: 700; }
.sev-tab[data-sev=""]       .sev-dot { background: var(--text-3); }
.sev-tab[data-sev="info"]   .sev-dot { background: var(--sev-info); }
.sev-tab[data-sev="warning"].sev-dot { background: var(--sev-warning); }
.sev-tab[data-sev="danger"] .sev-dot { background: var(--sev-danger); }
.sev-tab[data-sev="critical"].sev-dot { background: var(--sev-critical); }

.sev-tab.active[data-sev=""]        { background: rgba(255,255,255,.12); color: #fff; }
.sev-tab.active[data-sev="info"]    { background: var(--sev-info);     color: #000; }
.sev-tab.active[data-sev="warning"] { background: var(--sev-warning);  color: #000; }
.sev-tab.active[data-sev="danger"]  { background: var(--sev-danger);   color: #fff; }
.sev-tab.active[data-sev="critical"]{ background: var(--sev-critical); color: #fff; }

.sev-dot {
  width: 6px; height: 6px; border-radius: 50%;
}

/* ═══════ LOGS TABLE ═══════ */
.tbl-wrap { overflow-x: auto; }
.logs-table {
  width: 100%; border-collapse: collapse;
  font-size: .78rem;
}
.logs-table th {
  text-align: left;
  font-family: 'Space Mono', monospace; font-size: .6rem;
  letter-spacing: .1em; text-transform: uppercase;
  color: var(--text-3); padding: 7px 10px;
  border-bottom: 1px solid var(--border);
  white-space: nowrap; cursor: pointer;
  transition: color .15s;
  user-select: none;
}
.logs-table th:hover { color: var(--text-1); }
.logs-table td {
  padding: 9px 10px;
  border-bottom: 1px solid rgba(255,255,255,.04);
  vertical-align: middle; color: var(--text-2);
  transition: background .12s;
}
.logs-table tr:last-child td { border-bottom: none; }
.logs-table tbody tr:hover td { background: rgba(255,255,255,.025); }
.logs-table tbody tr.expanding td { background: rgba(0,212,255,.04); }

.td-id {
  font-family: 'Space Mono', monospace; font-size: .65rem;
  color: var(--text-3);
}
.td-user {
  display: flex; align-items: center; gap: 7px;
}
.user-av-sm {
  width: 26px; height: 26px; border-radius: 7px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--cyan), var(--violet));
  display: flex; align-items: center; justify-content: center;
  font-family: 'Syne', sans-serif; font-weight: 800; font-size: .62rem; color: #fff;
}
.td-action {
  font-family: 'Space Mono', monospace; font-size: .7rem;
  color: var(--text-1); font-weight: 700;
}
.td-module {
  font-family: 'Space Mono', monospace; font-size: .65rem;
  color: var(--sky);
}
.td-detail {
  max-width: 200px; overflow: hidden; text-overflow: ellipsis;
  white-space: nowrap; color: var(--text-2); font-size: .75rem;
  cursor: pointer;
}
.td-ip {
  font-family: 'Space Mono', monospace; font-size: .65rem;
  color: var(--text-3);
}
.td-ua {
  max-width: 120px; overflow: hidden; text-overflow: ellipsis;
  white-space: nowrap; font-size: .65rem; color: var(--text-3);
}
.td-date {
  font-family: 'Space Mono', monospace; font-size: .65rem;
  color: var(--text-3); white-space: nowrap;
}
.td-actions { display: flex; gap: 4px; }

/* ── Row detail expand ── */
.row-detail {
  background: rgba(0,212,255,.025) !important;
  border-top: none !important;
}
.detail-expand {
  padding: 10px 14px 12px !important;
}
.detail-content {
  font-family: 'Space Mono', monospace; font-size: .7rem;
  color: var(--text-2); line-height: 1.7;
  background: rgba(0,0,0,.3);
  border: 1px solid rgba(0,212,255,.12);
  border-radius: var(--r-sm);
  padding: 10px 14px;
  word-break: break-all;
}

/* ═══════ SEVERITY BADGES ═══════ */
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-family: 'Space Mono', monospace; font-size: .6rem; font-weight: 700;
  letter-spacing: .04em; text-transform: uppercase;
  padding: 2px 8px; border-radius: 100px;
}
.badge-info     { background: rgba(0,212,255,.12); color: var(--sev-info);     border: 1px solid rgba(0,212,255,.25); }
.badge-warning  { background: rgba(245,158,11,.12); color: var(--sev-warning); border: 1px solid rgba(245,158,11,.25); }
.badge-danger   { background: rgba(249,115,22,.12); color: var(--sev-danger);  border: 1px solid rgba(249,115,22,.25); }
.badge-critical {
  background: rgba(244,63,94,.14); color: var(--sev-critical);
  border: 1px solid rgba(244,63,94,.3);
  animation: criticalPulse 2s ease-in-out infinite;
}
@keyframes criticalPulse {
  0%,100% { box-shadow: none; }
  50% { box-shadow: 0 0 8px rgba(244,63,94,.4); }
}

/* ═══════ PAGINATION ═══════ */
.pagination {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  padding: .9rem 1.3rem; flex-wrap: wrap;
}
.pg-btn {
  min-width: 32px; height: 32px; padding: 0 8px; border-radius: var(--r-sm);
  background: var(--bg-card); border: 1px solid var(--border);
  color: var(--text-2); font-family: 'Space Mono', monospace; font-size: .7rem;
  cursor: pointer; transition: all .18s; display: flex; align-items: center; justify-content: center;
}
.pg-btn:hover { color: var(--text-1); background: var(--bg-hover); }
.pg-btn.active { background: rgba(0,212,255,.15); border-color: rgba(0,212,255,.4); color: var(--cyan); }
.pg-btn:disabled { opacity: .35; cursor: not-allowed; }
.pg-info {
  font-family: 'Space Mono', monospace; font-size: .62rem;
  color: var(--text-3); padding: 0 8px;
}

/* ═══════ SHIMMER LOADING ═══════ */
.shimmer {
  background: linear-gradient(90deg,
    rgba(255,255,255,.04) 0%,
    rgba(255,255,255,.08) 40%,
    rgba(255,255,255,.04) 80%
  );
  background-size: 400px 100%;
  animation: shimmer 1.4s linear infinite;
  border-radius: 4px;
}
@keyframes shimmer {
  0%   { background-position: -400px 0; }
  100% { background-position: 400px 0; }
}
.shimmer-row td::after {
  content: '';
  display: block; height: 14px; width: 80%;
  background: linear-gradient(90deg,rgba(255,255,255,.04) 0%,rgba(255,255,255,.08) 40%,rgba(255,255,255,.04) 80%);
  background-size: 400px 100%;
  animation: shimmer 1.4s linear infinite;
  border-radius: 3px;
}

/* ═══════ BUTTONS ═══════ */
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: var(--r-sm);
  font-family: 'Syne', sans-serif; font-size: .76rem; font-weight: 700;
  cursor: pointer; transition: all .18s; text-decoration: none;
  border: none; white-space: nowrap;
}
.btn-sm { padding: 4px 10px; font-size: .68rem; }
.btn-primary {
  background: linear-gradient(135deg, var(--cyan), var(--violet));
  color: #fff; box-shadow: 0 4px 14px rgba(0,212,255,.18);
}
.btn-primary:hover { opacity: .85; transform: translateY(-1px); box-shadow: 0 6px 22px rgba(0,212,255,.3); }
.btn-ghost {
  background: var(--bg-card); border: 1px solid var(--border); color: var(--text-2);
}
.btn-ghost:hover { color: var(--text-1); background: var(--bg-hover); border-color: rgba(255,255,255,.13); }
.btn-danger {
  background: rgba(244,63,94,.1); border: 1px solid rgba(244,63,94,.22); color: var(--rose);
}
.btn-danger:hover { background: rgba(244,63,94,.18); }
.btn-warn {
  background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.22); color: var(--amber);
}
.btn-warn:hover { background: rgba(245,158,11,.18); }
.btn-neon {
  background: rgba(0,255,170,.08); border: 1px solid rgba(0,255,170,.22); color: var(--neon);
}
.btn-neon:hover { background: rgba(0,255,170,.15); }

/* ═══════ CHARTS ═══════ */
.chart-wrap { position: relative; height: 180px; }
.chart-mini-wrap { position: relative; height: 140px; margin-top: .6rem; }

/* ═══════ MODULE PILLS ═══════ */
.mod-list { display: flex; flex-direction: column; gap: 7px; }
.mod-item { display: flex; align-items: center; gap: 9px; }
.mod-name {
  font-family: 'Space Mono', monospace; font-size: .68rem;
  color: var(--text-2); flex: 1; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis;
}
.mod-bar-wrap {
  width: 90px; height: 4px;
  background: rgba(255,255,255,.06); border-radius: 100px; overflow: hidden;
}
.mod-bar { height: 100%; border-radius: 100px; background: linear-gradient(90deg, var(--cyan), var(--violet)); transition: width .9s ease; }
.mod-count { font-family: 'Space Mono', monospace; font-size: .6rem; color: var(--text-3); width: 30px; text-align: right; }

/* ═══════ TOAST ═══════ */
#toast-stack {
  position: fixed; bottom: 1.4rem; right: 1.4rem;
  z-index: 9000; display: flex; flex-direction: column-reverse; gap: 7px;
  pointer-events: none;
}
.toast {
  display: flex; align-items: center; gap: 9px;
  padding: 10px 14px; border-radius: var(--r-md);
  background: var(--bg-surface); border: 1px solid var(--border);
  box-shadow: var(--shadow-lg); font-size: .78rem;
  max-width: 280px; pointer-events: all;
  transform: translateX(110px); opacity: 0;
  transition: all .35s cubic-bezier(.34,1.56,.64,1);
}
.toast.show { transform: translateX(0); opacity: 1; }
.ti { font-size: 1rem; flex-shrink: 0; }
.tb { flex: 1; }
.tt { font-family: 'Syne', sans-serif; font-weight: 700; font-size: .78rem; }
.ts { color: var(--text-3); font-size: .68rem; margin-top: 1px; }
.tc { color: var(--text-3); cursor: pointer; font-size: .75rem; }

/* ═══════ MODAL ═══════ */
.modal-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(4,6,14,.9); backdrop-filter: blur(16px);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
  opacity: 0; pointer-events: none; transition: opacity .28s;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal-box {
  background: var(--bg-surface); border: 1px solid var(--border);
  border-radius: var(--r-xl); padding: 2rem; max-width: 420px; width: 100%;
  box-shadow: var(--shadow-lg); position: relative; overflow: hidden;
  transform: translateY(20px) scale(.97);
  transition: transform .32s cubic-bezier(.34,1.56,.64,1);
}
.modal-overlay.open .modal-box { transform: translateY(0) scale(1); }
.modal-box::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, var(--rose), var(--violet));
}
.modal-title { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.1rem; margin-bottom: .5rem; }
.modal-sub { font-size: .8rem; color: var(--text-2); line-height: 1.55; }
.modal-actions { display: flex; gap: 8px; margin-top: 1.4rem; justify-content: flex-end; }

/* ═══════ ACTIVITY FEED ═══════ */
.feed-wrap { display: flex; flex-direction: column; gap: 0; }
.feed-item {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.04);
  animation: fadeUp .3s ease both;
}
.feed-item:last-child { border-bottom: none; }
.feed-ico {
  width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: .82rem; background: var(--bg-hover);
}
.feed-txt { font-size: .77rem; color: var(--text-2); line-height: 1.5; flex: 1; }
.feed-txt strong { color: var(--text-1); }
.feed-time { font-family: 'Space Mono', monospace; font-size: .6rem; color: var(--text-3); margin-top: 2px; }

/* ═══════ HEATMAP ═══════ */
.heatmap {
  display: grid; grid-template-columns: repeat(24, 1fr);
  gap: 3px; padding: .3rem 0;
}
.hm-cell {
  height: 24px; border-radius: 4px;
  background: rgba(0,212,255,.05);
  transition: background .3s, transform .2s;
  cursor: pointer; position: relative;
}
.hm-cell:hover { transform: scale(1.3); z-index: 10; }
.hm-cell[data-level="1"] { background: rgba(0,212,255,.15); }
.hm-cell[data-level="2"] { background: rgba(0,212,255,.3); }
.hm-cell[data-level="3"] { background: rgba(0,212,255,.5); }
.hm-cell[data-level="4"] { background: rgba(0,212,255,.75); }
.hm-cell[data-level="5"] { background: var(--cyan); }
.hm-labels { display: grid; grid-template-columns: repeat(24, 1fr); gap: 3px; margin-top: 3px; }
.hm-lbl { font-family: 'Space Mono', monospace; font-size: .45rem; color: var(--text-3); text-align: center; }

/* ═══════ EMPTY STATE ═══════ */
.empty-state {
  text-align: center; padding: 3rem 1rem;
}
.empty-icon { font-size: 3rem; margin-bottom: .7rem; opacity: .4; }
.empty-msg { font-size: .82rem; color: var(--text-3); }

/* ═══════ MOBILE ═══════ */
#sidebar-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.65);
  z-index: 199; opacity: 0; pointer-events: none; transition: opacity .3s;
}
#sidebar-overlay.show { opacity: 1; pointer-events: all; }

@media(max-width:768px){
  #sidebar { transform: translateX(calc(-1 * var(--sidebar-w))); width: var(--sidebar-w) !important; }
  #sidebar.mob-open { transform: translateX(0); }
  .main,.main.collapsed { margin-left: 0 !important; }
  .ham { display: flex !important; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
  .page { padding: 1rem .9rem 4rem; }
  .pg-header { flex-direction: column; align-items: flex-start; }
  .charts-row { grid-template-columns: 1fr; }
}
@media(max-width:480px){
  .stats-grid { grid-template-columns: 1fr; }
  .sev-tabs { flex-wrap: wrap; }
}

/* ═══════ ANIMATIONS ═══════ */
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes slideIn {
  from { opacity: 0; transform: translateX(-12px); }
  to   { opacity: 1; transform: translateX(0); }
}

/* Table row animation */
.log-row { animation: fadeUp .3s ease both; }

/* Glow on critical rows */
.row-critical td { border-left: 2px solid var(--rose) !important; }
.row-critical:hover td { background: rgba(244,63,94,.04) !important; }

/* Export dropdown */
.export-dropdown { position: relative; display: inline-flex; }
.export-menu {
  position: absolute; top: calc(100% + 6px); right: 0;
  background: var(--bg-surface); border: 1px solid var(--border);
  border-radius: var(--r-md); padding: 5px;
  min-width: 150px; z-index: 200;
  box-shadow: var(--shadow-lg);
  transform: translateY(-6px) scale(.97); opacity: 0; pointer-events: none;
  transition: all .2s cubic-bezier(.34,1.56,.64,1);
}
.export-menu.open { transform: translateY(0) scale(1); opacity: 1; pointer-events: all; }
.export-item {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 10px; border-radius: var(--r-sm);
  cursor: pointer; font-size: .78rem; color: var(--text-2);
  transition: all .15s;
}
.export-item:hover { background: var(--bg-hover); color: var(--text-1); }
</style>
</head>
<body>

<!-- ═══════════════════════════════════════ SIDEBAR ════════════════════════════════════ -->
<aside id="sidebar">
  <div class="sb-brand">
    <div class="brand-ico">📚</div>
    <div class="brand-txt">Digital <em>Library</em></div>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= $avatar ?></div>
    <div class="sb-uinfo">
      <div class="sb-uname"><?= $username ?></div>
      <div class="sb-urole">⚡ Administrateur</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Principal</div>
    <a href="../dashboard.php" class="nav-lnk">
      <span class="nav-ico"><i class="bi bi-grid-1x2"></i></span>
      <span class="nav-lbl">Dashboard</span>
    </a>

    <a href="logs.php" class="nav-lnk active" aria-current="page">
      <span class="nav-ico"><i class="bi bi-journal-text"></i></span>
      <span class="nav-lbl">Audit & Logs</span>
    </a>

  </nav>
  <div class="sb-foot">
    <button class="collapse-btn" onclick="toggleSidebar()">
      <span class="collapse-ico"><i class="bi bi-chevron-left"></i></span>
      <span class="collapse-lbl">Réduire</span>
    </button>
  </div>
</aside>

<div id="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<!-- ═══════════════════════════════════════ MAIN ════════════════════════════════════ -->
<div class="main" id="main">

  <!-- TOPBAR -->
  <header id="topbar">
    <button class="tb-btn ham" onclick="toggleMobileSidebar()"><i class="bi bi-list"></i></button>
    <div class="topbar-breadcrumb">
      <span>Admin</span><span class="bc-sep">/</span>
      <span class="bc-curr">Audit & Logs</span>
    </div>
    <div class="tb-spacer"></div>
    <div class="live-dot">
      <div class="live-pulse"></div>
      <span>LIVE</span>
    </div>
    <a href="../users/profile.php" class="tb-btn" title="Profil"><i class="bi bi-person"></i></a>
    <a href="../logout.php" class="tb-btn" title="Déconnexion"><i class="bi bi-box-arrow-right"></i></a>
  </header>

  <!-- ═══ PAGE CONTENT ═══ -->
  <main class="page">

    <!-- PAGE HEADER -->
    <div class="pg-header">
      <div class="pg-title-wrap">
        <div class="pg-eyebrow">⚡ Centre de surveillance</div>
        <h1 class="pg-title">Audit & Logs</h1>
        <p class="pg-sub" id="pg-sub-date">Chargement des données en cours…</p>
      </div>
      <div class="pg-actions">
        <!-- Export -->
        <div class="export-dropdown">
          <button class="btn btn-ghost" onclick="toggleExportMenu()" id="export-btn">
            <i class="bi bi-download"></i> Exporter
            <i class="bi bi-chevron-down" style="font-size:.7rem"></i>
          </button>
          <div class="export-menu" id="export-menu">
            <div class="export-item" onclick="exportCSV()">
              <i class="bi bi-filetype-csv"></i> CSV
            </div>
            <div class="export-item" onclick="exportExcel()">
              <i class="bi bi-file-earmark-spreadsheet"></i> Excel (.xls)
            </div>
           
          </div>
        </div>
        <button class="btn btn-warn" onclick="showClearModal()">
          <i class="bi bi-trash3"></i> Nettoyer
        </button>
        <button class="btn btn-primary" onclick="refreshAll()">
          <i class="bi bi-arrow-clockwise" id="refresh-ico"></i> Actualiser
        </button>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stats-grid" id="stats-grid">
      <!-- Remplies par JS -->
      <?php for ($i=0;$i<6;$i++): ?>
      <div class="stat-card">
        <div class="sc-ico shimmer" style="margin-bottom:.9rem"></div>
        <div class="shimmer" style="height:28px;width:70%;border-radius:4px;margin-bottom:6px"></div>
        <div class="shimmer" style="height:12px;width:55%;border-radius:4px"></div>
      </div>
      <?php endfor; ?>
    </div>

    <!-- CHARTS ROW -->
    <div class="charts-row" style="animation-delay:.1s">
      <!-- Activité 24h -->
      <div class="card">
        <div class="card-hd">
          <div class="card-title">
            <div class="ct-dot" style="background:rgba(0,212,255,.1)"><i class="bi bi-activity"></i></div>
            Activité — 24 dernières heures
          </div>
          <span id="last-hour-badge" class="badge badge-info">— logs / heure</span>
        </div>
        <div class="card-bd">
          <!-- Heatmap hourly -->
          <div style="margin-bottom:.8rem">
            <div style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--text-3);margin-bottom:5px">HEATMAP HORAIRE</div>
            <div class="heatmap" id="heatmap-grid">
              <?php for($i=0;$i<24;$i++): ?><div class="hm-cell" data-hour="<?=$i?>" data-level="0" title="<?=$i?>h : 0 événements"></div><?php endfor; ?>
            </div>
            <div class="hm-labels">
              <?php for($i=0;$i<24;$i++): ?><div class="hm-lbl"><?=$i?></div><?php endfor; ?>
            </div>
          </div>
          <!-- Chart activity line -->
          <div class="chart-mini-wrap">
            <canvas id="activityChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Severity donut + modules -->
      <div class="card">
        <div class="card-hd">
          <div class="card-title">
            <div class="ct-dot" style="background:rgba(244,63,94,.1)"><i class="bi bi-pie-chart"></i></div>
            Répartition & Modules
          </div>
        </div>
        <div class="card-bd">
          <div style="height:120px;position:relative;margin-bottom:1rem">
            <canvas id="severityChart"></canvas>
          </div>
          <div style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--text-3);margin-bottom:8px;letter-spacing:.1em">TOP MODULES</div>
          <div class="mod-list" id="mod-list">
            <div class="shimmer" style="height:16px;border-radius:3px"></div>
            <div class="shimmer" style="height:16px;border-radius:3px;margin-top:5px"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- FILTERS BAR -->
    <div class="filters-bar">
      <div class="search-box">
        <i class="bi bi-search" style="color:var(--text-3);font-size:.82rem"></i>
        <input type="search" id="search-input"
               placeholder="Rechercher action, IP, utilisateur, détail…"
               autocomplete="off"
               oninput="debounce(loadLogs, 350)()">
      </div>
      <select class="sel" id="filter-module" onchange="loadLogs()">
        <option value="">Tous les modules</option>
      </select>
      <select class="sel" id="filter-period" onchange="loadLogs()">
        <option value="">Toute période</option>
        <option value="today">Aujourd'hui</option>
        <option value="week">7 derniers jours</option>
        <option value="month">30 derniers jours</option>
      </select>
      <input type="date" class="date-input" id="filter-from" onchange="loadLogs()" title="Date début">
      <input type="date" class="date-input" id="filter-to" onchange="loadLogs()" title="Date fin">
      <button class="btn btn-ghost btn-sm" onclick="resetFilters()">
        <i class="bi bi-x-circle"></i> Reset
      </button>
    </div>

    <!-- SEVERITY TABS -->
    <div style="margin-bottom:.9rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <div class="sev-tabs">
        <button class="sev-tab active" data-sev="" onclick="setSeverity(this)">
          <span class="sev-dot"></span> Tous
        </button>
        <button class="sev-tab" data-sev="info" onclick="setSeverity(this)">
          <span class="sev-dot"></span> Info
        </button>
        <button class="sev-tab" data-sev="warning" onclick="setSeverity(this)">
          <span class="sev-dot"></span> Warning
        </button>
        <button class="sev-tab" data-sev="danger" onclick="setSeverity(this)">
          <span class="sev-dot"></span> Danger
        </button>
        <button class="sev-tab" data-sev="critical" onclick="setSeverity(this)">
          <span class="sev-dot"></span> Critical
        </button>
      </div>
      <div id="result-count" style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--text-3)">
        Chargement…
      </div>
    </div>

    <!-- LOGS TABLE -->
    <div class="card" style="animation-delay:.2s">
      <div class="card-hd">
        <div class="card-title">
          <div class="ct-dot" style="background:rgba(0,212,255,.08)"><i class="bi bi-table"></i></div>
          Journal des événements
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <span id="auto-refresh-badge" class="badge badge-info" style="cursor:pointer" onclick="toggleAutoRefresh()">
            <i class="bi bi-arrow-repeat"></i> AUTO
          </span>
          <button class="btn btn-ghost btn-sm" onclick="loadLogs()" id="table-refresh-btn">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
        </div>
      </div>
      <div class="tbl-wrap">
        <table class="logs-table">
          <thead>
            <tr>
              <th onclick="sortBy('id')">#<i class="bi bi-chevron-expand" style="font-size:.55rem;margin-left:2px"></i></th>
              <th>Utilisateur</th>
              <th onclick="sortBy('action')">Action</th>
              <th>Module</th>
              <th>Détail</th>
              <th onclick="sortBy('ip')">IP</th>
              <th>UA</th>
              <th onclick="sortBy('severity')">Sévérité</th>
              <th onclick="sortBy('created_at')">Date / Heure</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="logs-tbody">
            <!-- Shimmer rows -->
            <?php for($i=0;$i<10;$i++): ?>
            <tr class="shimmer-row"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <div id="pagination-wrap" class="pagination"></div>
    </div>

  </main>
</div><!-- /main -->

<!-- ═══════════════════ MODAL CONFIRM CLEAR ═══════════════════ -->
<div class="modal-overlay" id="clear-modal">
  <div class="modal-box">
    <div class="modal-title">⚠️ Nettoyer tous les logs ?</div>
    <div class="modal-sub">
      Cette action va supprimer <strong id="clear-count">tous les</strong> logs du journal.
      Cette opération est <strong>irréversible</strong> et sera elle-même enregistrée.
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('clear-modal')">Annuler</button>
      <button class="btn btn-danger" onclick="clearAllLogs()">
        <i class="bi bi-trash3"></i> Confirmer la suppression
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════ MODAL LOG DETAIL ═══════════════════ -->
<div class="modal-overlay" id="detail-modal">
  <div class="modal-box" style="max-width:520px">
    <button onclick="closeModal('detail-modal')" style="position:absolute;top:.9rem;right:.9rem;background:none;border:none;color:var(--text-3);cursor:pointer;font-size:.95rem"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title" id="dm-title">Détail du log</div>
    <div id="dm-content" style="margin-top:.8rem"></div>
  </div>
</div>

<!-- ═══════════════════ TOAST STACK ═══════════════════ -->
<div id="toast-stack"></div>

<!-- ═══════════════════════ JAVASCRIPT ═══════════════════════ -->
<script>
const CSRF  = <?= json_encode($csrfToken) ?>;
const SELF  = 'logs.php';

// ── State ─────────────────────────────────────────────────────
let currentPage     = 1;
let currentSeverity = '';
let currentSort     = 'created_at';
let currentOrder    = 'desc';
let autoRefresh     = true;
let autoTimer       = null;
let actChart        = null;
let sevChart        = null;
let lastStatsData   = null;

// ── Utils ─────────────────────────────────────────────────────
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function debounce(fn, ms){ let t; return (...a) => { clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; }
function fmtDate(d){
  if(!d) return '—';
  const dt = new Date(d.replace(' ','T'));
  return isNaN(dt) ? d : dt.toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'2-digit'})
    + ' <span style="color:var(--text-3)">' + dt.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'}) + '</span>';
}
function timeAgo(d){
  if(!d) return '—';
  const s = Math.floor((Date.now()-new Date(d.replace(' ','T')))/1000);
  if(s<60)  return s+'s';
  if(s<3600) return Math.floor(s/60)+'m';
  if(s<86400) return Math.floor(s/3600)+'h';
  return Math.floor(s/86400)+'j';
}

// ── Toast ─────────────────────────────────────────────────────
const T_ICONS = {info:'ℹ️',success:'✅',warn:'⚠️',error:'🔴'};
const T_BORDS = {info:'var(--cyan)',success:'var(--neon)',warn:'var(--amber)',error:'var(--rose)'};
function toast(title,sub='',type='info',dur=3500){
  const stack = document.getElementById('toast-stack');
  const el = document.createElement('div');
  el.className = 'toast';
  el.style.borderColor = T_BORDS[type]||T_BORDS.info;
  el.innerHTML = `<span class="ti">${T_ICONS[type]||'ℹ️'}</span>
    <div class="tb"><div class="tt">${esc(title)}</div>${sub?`<div class="ts">${esc(sub)}</div>`:''}</div>
    <span class="tc" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></span>`;
  stack.appendChild(el);
  requestAnimationFrame(()=>requestAnimationFrame(()=>el.classList.add('show')));
  setTimeout(()=>{el.classList.remove('show');setTimeout(()=>el.remove(),380);},dur);
}

// ── Sidebar ───────────────────────────────────────────────────
let sbCollapsed = false;
function toggleSidebar(){
  sbCollapsed = !sbCollapsed;
  document.getElementById('sidebar').classList.toggle('collapsed',sbCollapsed);
  document.getElementById('main').classList.toggle('collapsed',sbCollapsed);
}
function toggleMobileSidebar(){
  document.getElementById('sidebar').classList.toggle('mob-open');
  document.getElementById('sidebar-overlay').classList.toggle('show');
}
function closeMobileSidebar(){
  document.getElementById('sidebar').classList.remove('mob-open');
  document.getElementById('sidebar-overlay').classList.remove('show');
}

// ── Modals ────────────────────────────────────────────────────
function showModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open')); });

// ── Export menu ───────────────────────────────────────────────
function toggleExportMenu(){
  document.getElementById('export-menu').classList.toggle('open');
}
document.addEventListener('click',e=>{
  const wrap = document.querySelector('.export-dropdown');
  if(wrap && !wrap.contains(e.target)) document.getElementById('export-menu').classList.remove('open');
});

// ── Severity filter ───────────────────────────────────────────
function setSeverity(btn){
  document.querySelectorAll('.sev-tab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  currentSeverity = btn.dataset.sev;
  currentPage = 1;
  loadLogs();
}

// ── Sort ──────────────────────────────────────────────────────
function sortBy(col){
  if(currentSort===col) currentOrder = currentOrder==='desc'?'asc':'desc';
  else { currentSort=col; currentOrder='desc'; }
  loadLogs();
}

// ── Reset filters ─────────────────────────────────────────────
function resetFilters(){
  document.getElementById('search-input').value='';
  document.getElementById('filter-module').value='';
  document.getElementById('filter-period').value='';
  document.getElementById('filter-from').value='';
  document.getElementById('filter-to').value='';
  currentSeverity='';
  currentPage=1;
  document.querySelectorAll('.sev-tab').forEach(b=>b.classList.remove('active'));
  document.querySelector('.sev-tab[data-sev=""]').classList.add('active');
  loadLogs();
}

// ── Build URL params ──────────────────────────────────────────
function buildParams(){
  const p = new URLSearchParams();
  p.set('ajax_action','fetch_logs');
  p.set('page', currentPage);
  p.set('severity', currentSeverity);
  p.set('module', document.getElementById('filter-module').value);
  p.set('period', document.getElementById('filter-period').value);
  p.set('search', document.getElementById('search-input').value.trim());
  p.set('date_from', document.getElementById('filter-from').value);
  p.set('date_to', document.getElementById('filter-to').value);
  return p;
}

// ── Severity helpers ──────────────────────────────────────────
function sevBadge(s){
  const map={info:'badge-info',warning:'badge-warning',danger:'badge-danger',critical:'badge-critical'};
  const icons={info:'<i class="bi bi-info-circle"></i>',warning:'<i class="bi bi-exclamation-triangle"></i>',danger:'<i class="bi bi-x-octagon"></i>',critical:'<i class="bi bi-radioactive"></i>'};
  return `<span class="badge ${map[s]||'badge-info'}">${icons[s]||''} ${esc(s)}</span>`;
}

function actionIcon(action){
  const map={
    'admin_login':'🔐','admin_logout':'🚪','admin_page_view':'👁️',
    'create_book':'📗','delete_book':'🗑️','edit_book':'✏️','update_book':'✏️',
    'create_user':'👤','delete_user':'🗑️','edit_user':'✏️','change_role':'🔄',
    'purchase':'💳','download':'📥','reading':'📖',
    'setting_changed':'⚙️','export':'📤',
    'delete_log':'🗑️','truncate_logs':'⚠️',
    'sql_error':'🔴','system_error':'🔴',
    'suspicious_activity':'🚨',
  };
  return map[action] || '⚡';
}

function getUABadge(ua){
  if(!ua) return '<span style="color:var(--text-3)">—</span>';
  const u = ua.toLowerCase();
  let icon = '🌐';
  if(u.includes('chrome'))  icon='🟡';
  if(u.includes('firefox'))  icon='🟠';
  if(u.includes('safari') && !u.includes('chrome')) icon='🔵';
  if(u.includes('edge'))    icon='🔷';
  if(u.includes('mobile')||u.includes('android')) icon='📱';
  return `<span title="${esc(ua)}">${icon}</span>`;
}

// ── Load Logs ─────────────────────────────────────────────────
async function loadLogs(){
  const tbody = document.getElementById('logs-tbody');
  tbody.innerHTML = Array(8).fill(0).map(()=>`<tr class="shimmer-row"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>`).join('');

  try{
    const res = await fetch(`${SELF}?${buildParams()}`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();
    if(!data.ok) throw new Error(data.error||'Erreur serveur');

    renderLogs(data.logs, tbody);
    renderPagination(data.page, data.pages, data.total);

    const total = data.total.toLocaleString('fr-FR');
    document.getElementById('result-count').textContent = `${total} résultat${data.total!==1?'s':''} trouvé${data.total!==1?'s':''}`;

    // Update pg-sub
    const now = new Date();
    document.getElementById('pg-sub-date').textContent =
      `Dernière mise à jour : ${now.toLocaleTimeString('fr-FR')} · ${now.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long',year:'numeric'})}`;

  }catch(e){
    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:var(--rose);padding:2rem;font-size:.8rem"><i class="bi bi-exclamation-circle"></i> Erreur: ${esc(e.message)}</td></tr>`;
    toast('Erreur de chargement', e.message, 'error');
  }
}

function renderLogs(logs, tbody){
  if(!logs||!logs.length){
    tbody.innerHTML = `<tr><td colspan="10">
      <div class="empty-state">
        <div class="empty-icon">📋</div>
        <div class="empty-msg">Aucun log trouvé pour ces critères.</div>
      </div>
    </td></tr>`;
    return;
  }

  tbody.innerHTML = logs.map((l,i) => {
    const cls = l.severity==='critical' ? 'log-row row-critical' : 'log-row';
    const delay = Math.min(i*0.025, 0.3);
    return `<tr class="${cls}" style="animation-delay:${delay}s">
      <td class="td-id">#${esc(l.id)}</td>
      <td>
        <div class="td-user">
          <div class="user-av-sm">${esc((l.user_name||'?').charAt(0).toUpperCase())}</div>
          <div>
            <div style="color:var(--text-1);font-size:.78rem;font-weight:600">${esc(l.user_name||'—')}</div>
            <div style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--text-3)">${esc(l.user_role||'')}</div>
          </div>
        </div>
      </td>
      <td><div class="td-action">${actionIcon(l.action)} ${esc(l.action)}</div></td>
      <td><span class="td-module">${l.module?esc(l.module):'<span style="color:var(--text-3)">—</span>'}</span></td>
      <td>
        <div class="td-detail" onclick="expandDetail(${l.id})"
             title="${esc(l.detail||'')}">
          ${l.detail ? esc(l.detail.substring(0,60))+(l.detail.length>60?'…':'') : '<span style="color:var(--text-3)">—</span>'}
        </div>
      </td>
      <td class="td-ip">${esc(l.ip||'—')}</td>
      <td class="td-ua">${getUABadge(l.user_agent)}</td>
      <td>${sevBadge(l.severity||'info')}</td>
      <td class="td-date">${fmtDate(l.created_at)}</td>
      <td>
        <div class="td-actions">

          <button class="btn btn-danger btn-sm" onclick="deleteLog(${esc(l.id)})" title="Supprimer"><i class="bi bi-trash3"></i></button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

// ── Pagination ────────────────────────────────────────────────
function renderPagination(page, pages, total){
  const wrap = document.getElementById('pagination-wrap');
  if(pages<=1){ wrap.innerHTML=''; return; }

  let html = '';
  html += `<button class="pg-btn" onclick="goPage(${page-1})" ${page<=1?'disabled':''}>
    <i class="bi bi-chevron-left"></i></button>`;

  const range = [];
  range.push(1);
  if(page>3) range.push('…');
  for(let i=Math.max(2,page-1);i<=Math.min(pages-1,page+1);i++) range.push(i);
  if(page<pages-2) range.push('…');
  if(pages>1) range.push(pages);

  range.forEach(p=>{
    if(p==='…') html+=`<span class="pg-info">…</span>`;
    else html+=`<button class="pg-btn${p===page?' active':''}" onclick="goPage(${p})">${p}</button>`;
  });

  html += `<button class="pg-btn" onclick="goPage(${page+1})" ${page>=pages?'disabled':''}>
    <i class="bi bi-chevron-right"></i></button>`;
  html += `<span class="pg-info">${total.toLocaleString('fr-FR')} entrées</span>`;
  wrap.innerHTML = html;
}

function goPage(p){ currentPage=p; loadLogs(); window.scrollTo({top:400,behavior:'smooth'}); }

// ── Log detail modal ──────────────────────────────────────────
function showLogDetail(log){
  document.getElementById('dm-title').innerHTML = `${actionIcon(log.action)} Log #${esc(log.id)} — ${esc(log.action)}`;
  const rows = [
    ['Utilisateur', esc(log.user_name||'—') + ' <small style="color:var(--text-3)">'+esc(log.user_role||'')+'</small>'],
    ['Action',      `<span style="font-family:\'Space Mono\',monospace;color:var(--cyan)">${esc(log.action)}</span>`],
    ['Module',      log.module ? `<span style="color:var(--sky)">${esc(log.module)}</span>` : '—'],
    ['Sévérité',    sevBadge(log.severity||'info')],
    ['IP',          `<code style="font-size:.72rem;color:var(--amber)">${esc(log.ip||'—')}</code>`],
    ['User Agent',  `<small style="word-break:break-all;font-size:.7rem;color:var(--text-3)">${esc(log.user_agent||'—')}</small>`],
    ['Date',        fmtDate(log.created_at)],
    ['Détail',      `<div style="font-family:\'Space Mono\',monospace;font-size:.72rem;background:rgba(0,0,0,.3);padding:8px 12px;border-radius:8px;word-break:break-all;color:var(--text-2);border:1px solid rgba(0,212,255,.1)">${esc(log.detail||'(aucun détail)')}</div>`],
  ];
  document.getElementById('dm-content').innerHTML = rows.map(([k,v])=>`
    <div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);align-items:flex-start">
      <div style="font-family:\'Space Mono\',monospace;font-size:.6rem;color:var(--text-3);width:90px;flex-shrink:0;padding-top:2px;text-transform:uppercase;letter-spacing:.06em">${k}</div>
      <div style="flex:1;font-size:.78rem">${v}</div>
    </div>`).join('');
  showModal('detail-modal');
}

// ── Delete log ────────────────────────────────────────────────
async function deleteLog(id){
  if(!confirm(`Supprimer le log #${id} ?`)) return;
  try{
    const fd = new FormData();
    fd.append('ajax_action','delete_log'); fd.append('id',id); fd.append('csrf',CSRF);
    const res = await fetch(SELF,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const data = await res.json();
    if(data.ok){ toast('Log supprimé',`#${id} retiré du journal.`,'success'); loadLogs(); }
    else toast('Erreur',data.error,'error');
  }catch(e){ toast('Erreur réseau',e.message,'error'); }
}

// ── Expand detail in table ─────────────────────────────────────
function expandDetail(id){
  const row = document.querySelector(`button[onclick*="showLogDetail"]`)?.closest('tr');
  // On utilise le modal à la place
}

// ── Clear all logs ────────────────────────────────────────────
function showClearModal(){
  if(lastStatsData) document.getElementById('clear-count').textContent = lastStatsData.total.toLocaleString('fr-FR');
  showModal('clear-modal');
}
async function clearAllLogs(){
  closeModal('clear-modal');
  try{
    const fd = new FormData();
    fd.append('ajax_action','delete_all'); fd.append('csrf',CSRF);
    const res = await fetch(SELF,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const data = await res.json();
    if(data.ok){
      toast('Nettoyage effectué',`${data.count.toLocaleString('fr-FR')} logs supprimés.`,'warn',5000);
      loadLogs(); loadStats();
    } else toast('Erreur',data.error,'error');
  }catch(e){ toast('Erreur réseau',e.message,'error'); }
}

// ── Load Stats ────────────────────────────────────────────────
async function loadStats(){
  try{
    const res = await fetch(`${SELF}?ajax_action=fetch_stats`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data = await res.json();
    if(!data.ok) throw new Error(data.error);
    const s = data.stats;
    lastStatsData = s;

    // Render stat cards
    const cards = [
      { ico:'📋', val:s.total.toLocaleString('fr-FR'),     label:'Total logs',         meta:`<i class="bi bi-dot"></i> Depuis le début`,     cls:'c-muted' },
      { ico:'⚡', val:s.today.toLocaleString('fr-FR'),      label:"Logs aujourd'hui",   meta:`<i class="bi bi-arrow-up-short"></i> +${s.last_hour} / heure`,  cls:'c-neon' },
      { ico:'🚨', val:s.critical.toLocaleString('fr-FR'),   label:'Erreurs critiques',  meta:`<i class="bi bi-radioactive"></i> Toutes périodes`, cls:'c-rose' },
      { ico:'⚠️', val:s.warnings.toLocaleString('fr-FR'),   label:"Warnings auj.",      meta:`<i class="bi bi-exclamation-triangle"></i> Aujourd'hui`, cls:'c-amber' },
      { ico:'🔥', val:s.danger.toLocaleString('fr-FR'),     label:"Dangers auj.",       meta:`<i class="bi bi-x-octagon"></i> Aujourd'hui`,   cls:'c-rose' },
      { ico:'🔐', val:s.connections.toLocaleString('fr-FR'),label:"Connexions admin",   meta:`<i class="bi bi-check-circle"></i> Aujourd'hui`, cls:'c-neon' },
    ];

    document.getElementById('stats-grid').innerHTML = cards.map((c,i)=>`
      <div class="stat-card" style="animation-delay:${0.04*(i+1)}s">
        <div class="sc-ico">${c.ico}</div>
        <div class="sc-val">${c.val}</div>
        <div class="sc-label">${c.label}</div>
        <div class="sc-meta ${c.cls}">${c.meta}</div>
      </div>`).join('');

    // Last hour badge
    document.getElementById('last-hour-badge').textContent = s.last_hour+' / heure';

    // Heatmap
    const maxH = Math.max(1,...s.hourly);
    s.hourly.forEach((n,h)=>{
      const cell = document.querySelector(`.hm-cell[data-hour="${h}"]`);
      if(!cell) return;
      const lvl = n===0?0:n<maxH*.2?1:n<maxH*.4?2:n<maxH*.6?3:n<maxH*.8?4:5;
      cell.dataset.level = lvl;
      cell.title = `${h}h00 : ${n} événement${n!==1?'s':''}`;
    });

    // Activity chart
    const labels = Array.from({length:24},(_,i)=>`${i}h`);
    if(actChart){
      actChart.data.datasets[0].data = s.hourly;
      actChart.update('none');
    } else {
      actChart = new Chart(document.getElementById('activityChart').getContext('2d'),{
        type:'line',
        data:{
          labels,
          datasets:[{
            data: s.hourly,
            borderColor: 'rgba(0,212,255,.8)',
            backgroundColor: 'rgba(0,212,255,.06)',
            borderWidth: 2, pointRadius: 2,
            pointBackgroundColor: 'var(--cyan)',
            fill: true, tension: .4,
          }]
        },
        options:{
          responsive:true, maintainAspectRatio:false,
          plugins:{legend:{display:false},tooltip:{
            backgroundColor:'rgba(8,12,26,.9)',
            titleColor:'#eef2ff', bodyColor:'rgba(238,242,255,.7)',
            borderColor:'rgba(0,212,255,.2)', borderWidth:1,
            callbacks:{label:ctx=>`  ${ctx.raw} événements`}
          }},
          scales:{
            x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'rgba(238,242,255,.28)',font:{family:'Space Mono',size:10}}},
            y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'rgba(238,242,255,.28)',font:{family:'Space Mono',size:10}},beginAtZero:true},
          }
        }
      });
    }

    // Severity donut
    const sevCols=['rgba(0,212,255,.8)','rgba(245,158,11,.8)','rgba(249,115,22,.8)','rgba(244,63,94,.8)'];
    const sevData=[s.severity_dist.info,s.severity_dist.warning,s.severity_dist.danger,s.severity_dist.critical];
    if(sevChart){
      sevChart.data.datasets[0].data = sevData;
      sevChart.update('none');
    } else {
      sevChart = new Chart(document.getElementById('severityChart').getContext('2d'),{
        type:'doughnut',
        data:{
          labels:['Info','Warning','Danger','Critical'],
          datasets:[{data:sevData,backgroundColor:sevCols,borderColor:'rgba(8,12,26,.9)',borderWidth:3}]
        },
        options:{
          responsive:true, maintainAspectRatio:false, cutout:'72%',
          plugins:{
            legend:{
              position:'right', labels:{color:'rgba(238,242,255,.58)',font:{size:10,family:'Space Mono'},boxWidth:10,padding:10}
            },
            tooltip:{
              backgroundColor:'rgba(8,12,26,.9)',
              titleColor:'#eef2ff', bodyColor:'rgba(238,242,255,.7)',
              borderColor:'rgba(255,255,255,.1)', borderWidth:1,
            }
          }
        }
      });
    }

    // Top modules
    const mods = s.top_modules;
    const maxMod = Math.max(1,...mods.map(m=>parseInt(m.n)));
    document.getElementById('mod-list').innerHTML = mods.length
      ? mods.map(m=>`
        <div class="mod-item">
          <div class="mod-name">${esc(m.module||'(aucun)')}</div>
          <div class="mod-bar-wrap"><div class="mod-bar" style="width:${Math.round(parseInt(m.n)/maxMod*100)}%"></div></div>
          <div class="mod-count">${m.n}</div>
        </div>`).join('')
      : '<div style="color:var(--text-3);font-size:.75rem">Aucun module enregistré</div>';

  }catch(e){
    console.error('[Stats]',e);
    toast('Stats inaccessibles', e.message,'warn');
  }
}

// ── Load modules (pour le select) ─────────────────────────────
async function loadModules(){
  try{
    const res = await fetch(`${SELF}?ajax_action=fetch_modules`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data = await res.json();
    if(!data.ok) return;
    const sel = document.getElementById('filter-module');
    data.modules.forEach(m=>{
      const o = document.createElement('option');
      o.value=m; o.textContent=m;
      sel.appendChild(o);
    });
  }catch(e){}
}

// ── Auto-refresh ──────────────────────────────────────────────
function toggleAutoRefresh(){
  autoRefresh = !autoRefresh;
  const badge = document.getElementById('auto-refresh-badge');
  if(autoRefresh){
    badge.textContent=''; badge.innerHTML='<i class="bi bi-arrow-repeat"></i> AUTO';
    badge.className='badge badge-info';
    startAutoRefresh();
    toast('Auto-refresh activé','Mise à jour toutes les 10s','info',2000);
  } else {
    clearInterval(autoTimer);
    badge.textContent=''; badge.innerHTML='<i class="bi bi-pause"></i> PAUSE';
    badge.className='badge badge-warning';
    toast('Auto-refresh suspendu','','warn',2000);
  }
}

function startAutoRefresh(){
  clearInterval(autoTimer);
  if(!autoRefresh) return;
  autoTimer = setInterval(()=>{
    loadStats();
    // Refresh silencieux de la table si on est sur page 1
    if(currentPage===1) loadLogs();
  }, 10000);
}

// ── Refresh all ───────────────────────────────────────────────
function refreshAll(){
  const ico = document.getElementById('refresh-ico');
  ico.style.animation='spin .6s linear infinite';
  ico.style.display='inline-block';
  Promise.all([loadStats(), loadLogs()]).then(()=>{
    ico.style.animation='';
    toast('Actualisé','Données rechargées','success',2000);
  });
}

// ─── EXPORTS ──────────────────────────────────────────────────
async function exportCSV(){
  document.getElementById('export-menu').classList.remove('open');
  toast('Export CSV','Génération en cours…','info',2000);
  try{
    const res = await fetch(`${SELF}?ajax_action=export_csv`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data = await res.json();
    if(!data.ok) throw new Error(data.error);
    const bytes = atob(data.csv);
    const blob = new Blob([bytes],{type:'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href=url; a.download=`logs_${new Date().toISOString().slice(0,10)}.csv`;
    a.click(); URL.revokeObjectURL(url);
    toast('Export terminé',`${data.count.toLocaleString('fr-FR')} lignes exportées`,'success');
  }catch(e){ toast('Export échoué',e.message,'error'); }
}

function exportExcel(){
  // Excel = CSV avec extension .xls (compatible Excel)
  document.getElementById('export-menu').classList.remove('open');
  toast('Export Excel','Génération en cours…','info',2000);
  fetch(`${SELF}?ajax_action=export_csv`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(data=>{
      if(!data.ok) throw new Error(data.error);
      const bytes = atob(data.csv);
      const blob = new Blob([bytes],{type:'application/vnd.ms-excel;charset=utf-8;'});
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href=url; a.download=`logs_${new Date().toISOString().slice(0,10)}.xls`;
      a.click(); URL.revokeObjectURL(url);
      toast('Excel exporté',`${data.count.toLocaleString('fr-FR')} lignes`,'success');
    }).catch(e=>toast('Export échoué',e.message,'error'));
}

function exportPDF(){
  document.getElementById('export-menu').classList.remove('open');
  toast('Export PDF','Génération du rapport…','info',2000);
  // Générer un rapport HTML mis en page, puis print-to-PDF via window.print()
  fetch(`${SELF}?ajax_action=export_csv`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(data=>{
      if(!data.ok) throw new Error(data.error);
      const csv = atob(data.csv);
      const rows = csv.split('\n').filter(Boolean);
      const headers = rows[0].split(',').map(h=>h.replace(/^"|"$/g,''));
      const body = rows.slice(1).map(r=>{
        const cells = r.match(/("([^"]|"")*"|[^,]*)(,|$)/g)||[];
        return cells.map(c=>c.replace(/^"|"$|,$/g,'').replace(/""/g,'"'));
      });
      const win = window.open('','_blank');
      win.document.write(`<!DOCTYPE html><html lang="fr"><head>
        <meta charset="UTF-8"><title>Rapport Logs — Digital Library</title>
        <style>
          body{font-family:Arial,sans-serif;font-size:10px;color:#111;margin:20px}
          h1{font-size:16px;margin-bottom:4px}
          .meta{color:#666;font-size:9px;margin-bottom:14px}
          table{border-collapse:collapse;width:100%}
          th{background:#111;color:#fff;padding:4px 6px;font-size:9px;text-align:left}
          td{padding:3px 6px;border-bottom:1px solid #eee;font-size:9px}
          tr:nth-child(even)td{background:#f9f9f9}
          @media print{@page{size:A4 landscape;margin:10mm}}
        </style>
      </head><body>
        <h1>📋 Rapport Journal Système — Digital Library</h1>
        <div class="meta">Généré le ${new Date().toLocaleString('fr-FR')} · ${data.count.toLocaleString('fr-FR')} entrées</div>
        <table>
          <thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead>
          <tbody>${body.map(r=>`<tr>${r.map(c=>`<td>${c||'—'}</td>`).join('')}</tr>`).join('')}</tbody>
        </table>
        <script>window.onload=()=>{window.print();}<\/script>
      </body></html>`);
      win.document.close();
      toast('PDF prêt','Dialogue d\'impression ouvert','success');
    }).catch(e=>toast('Export échoué',e.message,'error'));
}

// ── CSS spin animation ─────────────────────────────────────────
const spinStyle = document.createElement('style');
spinStyle.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(spinStyle);

// ── INIT ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', ()=>{
  loadStats();
  loadLogs();
  loadModules();
  startAutoRefresh();

  setTimeout(()=>{
    toast('Centre de surveillance','Connecté · Données chargées en temps réel','success',4000);
  }, 800);
});
</script>
</body>
</html>