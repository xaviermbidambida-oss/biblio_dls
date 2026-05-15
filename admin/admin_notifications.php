<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — Admin Notifications v3.0              ║
 * ║  admin/notifications.php                                        ║
 * ║  Interface SaaS · Temps réel · Bulk actions · CSRF sécurisé     ║
 * ║  ✅ ZÉRO colonne "key" · PDO préparé · Filtres AJAX complets    ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// ── Session ──────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Connexion PDO ─────────────────────────────────────────────────────
$pdo = null;
foreach ([
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config.php',
] as $_cfgPath) {
    if (file_exists($_cfgPath)) {
        require_once $_cfgPath;
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
        die('<p style="color:#f43f5e;font-family:monospace;padding:2rem">❌ Erreur BD : ' . htmlspecialchars($e->getMessage()) . '</p>');
    }
}

// ── Auth ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
$userId    = (int) $_SESSION['user_id'];
$userRole  = $_SESSION['user_role'] ?? 'lecteur';
$username  = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$isAdmin   = ($userRole === 'admin');

// ── NotificationService ───────────────────────────────────────────────
// Inline pour ne pas dépendre d'un autoloader
// (la version standalone est dans NotificationService.php)
$nsPath = __DIR__ . '/../includes/NotificationService.php';
if (!class_exists('NotificationService') && file_exists($nsPath)) {
    require_once $nsPath;
}

// ── Créer la table si elle n'existe pas (correction erreur "key") ─────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NULL,
            type        VARCHAR(50)  NOT NULL DEFAULT 'info',
            title       VARCHAR(255) NOT NULL DEFAULT '',
            message     TEXT,
            icon        VARCHAR(10)  DEFAULT '🔔',
            is_read     TINYINT(1)   NOT NULL DEFAULT 0,
            is_archived TINYINT(1)   NOT NULL DEFAULT 0,
            bg          VARCHAR(80)  DEFAULT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            read_at     TIMESTAMP    NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Vérifier et ajouter les colonnes manquantes (compatibilité tables existantes)
    $cols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('is_archived', $cols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array('read_at', $cols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL");
    }
    if (!in_array('title', $cols) && in_array('titre', $cols)) {
        // Ancienne table avec "titre" → ajouter "title" et synchroniser
        $pdo->exec("ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT '' AFTER type");
        $pdo->exec("UPDATE notifications SET title = titre WHERE title = ''");
    }
    if (!in_array('title', $cols) && !in_array('titre', $cols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT '' AFTER type");
    }
    // Normaliser "lu" → "is_read" si besoin
    if (in_array('lu', $cols) && !in_array('is_read', $cols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE notifications SET is_read = lu");
    }

} catch (Throwable $e) {
    // Non bloquant — la table existe probablement déjà
    error_log('[DLS-Notif] schema check: ' . $e->getMessage());
}

// ── Helpers ───────────────────────────────────────────────────────────
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function timeAgo(string $dt): string {
    if (!$dt) return '—';
    $d = time() - strtotime($dt);
    if ($d < 0)      return 'à l\'instant';
    if ($d < 60)     return 'à l\'instant';
    if ($d < 3600)   return (int)($d / 60) . ' min';
    if ($d < 86400)  return (int)($d / 3600) . 'h';
    if ($d < 604800) return (int)($d / 86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}
function csrfToken(): string {
    if (empty($_SESSION['csrf_notif'])) {
        $_SESSION['csrf_notif'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_notif'];
}
function checkCsrf(string $token): bool {
    return hash_equals($_SESSION['csrf_notif'] ?? '', $token);
}

const TYPE_META = [
    'achat'          => ['label' => 'Achat',           'icon' => '🛍️', 'badge' => 'badge-neon',    'dot' => '#00ffaa'],
    'bonus'          => ['label' => 'Bonus',           'icon' => '🎁', 'badge' => 'badge-violet',  'dot' => '#a78bfa'],
    'lecture'        => ['label' => 'Lecture',         'icon' => '📖', 'badge' => 'badge-cyan',    'dot' => '#00d4ff'],
    'telechargement' => ['label' => 'Téléchargement',  'icon' => '⬇️', 'badge' => 'badge-blue',    'dot' => '#60a5fa'],
    'favoris'        => ['label' => 'Favoris',         'icon' => '❤️', 'badge' => 'badge-rose',    'dot' => '#f43f5e'],
    'admin_action'   => ['label' => 'Admin',           'icon' => '⚙️', 'badge' => 'badge-amber',   'dot' => '#f59e0b'],
    'system_error'   => ['label' => 'Erreur système',  'icon' => '🔴', 'badge' => 'badge-danger',  'dot' => '#f43f5e'],
    'security_alert' => ['label' => 'Sécurité',        'icon' => '🔐', 'badge' => 'badge-danger',  'dot' => '#ef4444'],
    'utilisateur'    => ['label' => 'Utilisateur',     'icon' => '👤', 'badge' => 'badge-purple',  'dot' => '#c084fc'],
    'info'           => ['label' => 'Info',             'icon' => 'ℹ️', 'badge' => 'badge-muted',   'dot' => '#94a3b8'],
];

function getMeta(string $type): array {
    return TYPE_META[$type] ?? ['label' => ucfirst($type), 'icon' => '🔔', 'badge' => 'badge-muted', 'dot' => '#94a3b8'];
}

// ═════════════════════════════════════════════════════════════════════
// AJAX ENDPOINT
// ═════════════════════════════════════════════════════════════════════
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       || (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($isAjax && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    // CSRF check pour actions mutatives
    $mutatif = ['mark_read','mark_unread','delete','archive','bulk','mark_all_read','create_test'];
    if (in_array($action, $mutatif, true)) {
        $token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
        if (!checkCsrf($token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']);
            exit;
        }
    }

    try {
        switch ($action) {

            // ── Charger les notifications (filtres + pagination) ──────
            case 'load':
                $page    = max(1, (int) ($_GET['page']    ?? 1));
                $perPage = max(5, min(50, (int) ($_GET['per_page'] ?? 20)));
                $type    = trim($_GET['type']    ?? 'all');
                $status  = trim($_GET['status']  ?? 'all');
                $uid     = (int) ($_GET['uid']    ?? 0);
                $search  = trim($_GET['search']  ?? '');
                $df      = trim($_GET['date_from'] ?? '');
                $dt      = trim($_GET['date_to']   ?? '');

                $where  = ["n.is_archived = 0"];
                $params = [];

                if (!$isAdmin) {
                    $where[]          = "(n.user_id = :uid OR n.user_id IS NULL)";
                    $params[':uid']   = $userId;
                }
                if ($type !== 'all') {
                    $where[]          = "n.type = :type";
                    $params[':type']  = $type;
                }
                if ($status === 'unread') {
                    $where[]          = "n.is_read = 0";
                } elseif ($status === 'read') {
                    $where[]          = "n.is_read = 1";
                }
                if ($uid > 0 && $isAdmin) {
                    $where[]          = "n.user_id = :fuid";
                    $params[':fuid']  = $uid;
                }
                if ($search !== '') {
                    $where[]          = "(n.title LIKE :s OR n.message LIKE :s)";
                    $params[':s']     = "%{$search}%";
                }
                if ($df) {
                    $where[]          = "DATE(n.created_at) >= :df";
                    $params[':df']    = $df;
                }
                if ($dt) {
                    $where[]          = "DATE(n.created_at) <= :dt";
                    $params[':dt']    = $dt;
                }

                $whereSQL = 'WHERE ' . implode(' AND ', $where);
                $offset   = ($page - 1) * $perPage;

                // Total
                $cSt = $pdo->prepare("SELECT COUNT(*) FROM notifications n $whereSQL");
                $cSt->execute($params);
                $total = (int) $cSt->fetchColumn();

                // Données
                $st = $pdo->prepare(
                    "SELECT n.id, n.user_id, n.type,
                            COALESCE(n.title, '') AS title,
                            COALESCE(n.message, '') AS message,
                            n.icon, n.is_read, n.is_archived, n.created_at, n.read_at,
                            u.nom AS user_nom, u.prenom AS user_prenom,
                            u.email AS user_email, u.role AS user_role
                     FROM notifications n
                     LEFT JOIN users u ON u.id = n.user_id
                     $whereSQL
                     ORDER BY n.created_at DESC
                     LIMIT :lim OFFSET :off"
                );
                foreach ($params as $k => $v) $st->bindValue($k, $v);
                $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
                $st->bindValue(':off', $offset,  PDO::PARAM_INT);
                $st->execute();
                $rows = $st->fetchAll();

                // Stats non-lues
                $unreadQ = $isAdmin
                    ? "SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND is_archived = 0"
                    : "SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND is_archived = 0 AND (user_id = {$userId} OR user_id IS NULL)";
                $unread = (int) $pdo->query($unreadQ)->fetchColumn();

                echo json_encode([
                    'success'     => true,
                    'items'       => $rows,
                    'total'       => $total,
                    'pages'       => max(1, (int) ceil($total / $perPage)),
                    'current'     => $page,
                    'unread'      => $unread,
                    'per_page'    => $perPage,
                ]);
                break;

            // ── Stats résumé ─────────────────────────────────────────
            case 'stats':
                $baseQ = $isAdmin
                    ? "SELECT COUNT(*) FROM notifications"
                    : "SELECT COUNT(*) FROM notifications WHERE user_id = {$userId} OR user_id IS NULL";

                $total   = (int) $pdo->query("$baseQ")->fetchColumn();
                $unread  = (int) $pdo->query("$baseQ AND is_read = 0 AND is_archived = 0")->fetchColumn();
                $read    = (int) $pdo->query("$baseQ AND is_read = 1")->fetchColumn();
                $archive = (int) $pdo->query("$baseQ AND is_archived = 1")->fetchColumn();

                // Par type
                $typeSt = $isAdmin
                    ? $pdo->query(
                        "SELECT type, COUNT(*) cnt, SUM(is_read = 0) AS unread_cnt
                         FROM notifications WHERE is_archived = 0 GROUP BY type ORDER BY cnt DESC"
                      )
                    : $pdo->prepare(
                        "SELECT type, COUNT(*) cnt, SUM(is_read = 0) AS unread_cnt
                         FROM notifications WHERE is_archived = 0
                           AND (user_id = ? OR user_id IS NULL)
                         GROUP BY type ORDER BY cnt DESC"
                      );
                if (!$isAdmin) $typeSt->execute([$userId]);
                $byType = $typeSt->fetchAll();

                // Activité 7 jours
                $actSt = $isAdmin
                    ? $pdo->query(
                        "SELECT DATE(created_at) AS jour, COUNT(*) AS nb
                         FROM notifications WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         GROUP BY jour ORDER BY jour"
                      )
                    : $pdo->prepare(
                        "SELECT DATE(created_at) AS jour, COUNT(*) AS nb
                         FROM notifications WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                           AND (user_id = ? OR user_id IS NULL)
                         GROUP BY jour ORDER BY jour"
                      );
                if (!$isAdmin) $actSt->execute([$userId]);
                $activity = $actSt->fetchAll();

                echo json_encode([
                    'success'  => true,
                    'total'    => $total,
                    'unread'   => $unread,
                    'read'     => $read,
                    'archived' => $archive,
                    'by_type'  => $byType,
                    'activity' => $activity,
                ]);
                break;

            // ── Marquer lu ───────────────────────────────────────────
            case 'mark_read':
                $id = (int) ($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID manquant');
                if ($isAdmin) {
                    $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?")
                        ->execute([$id]);
                } else {
                    $pdo->prepare(
                        "UPDATE notifications SET is_read = 1, read_at = NOW()
                         WHERE id = ? AND (user_id = ? OR user_id IS NULL)"
                    )->execute([$id, $userId]);
                }
                echo json_encode(['success' => true]);
                break;

            // ── Marquer non-lu ───────────────────────────────────────
            case 'mark_unread':
                $id = (int) ($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID manquant');
                $pdo->prepare("UPDATE notifications SET is_read = 0, read_at = NULL WHERE id = ?")
                    ->execute([$id]);
                echo json_encode(['success' => true]);
                break;

            // ── Tout marquer lu ──────────────────────────────────────
            case 'mark_all_read':
                if ($isAdmin) {
                    $affected = $pdo->exec("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
                } else {
                    $st = $pdo->prepare(
                        "UPDATE notifications SET is_read = 1, read_at = NOW()
                         WHERE is_read = 0 AND (user_id = ? OR user_id IS NULL)"
                    );
                    $st->execute([$userId]);
                    $affected = $st->rowCount();
                }
                echo json_encode(['success' => true, 'affected' => $affected]);
                break;

            // ── Archiver ─────────────────────────────────────────────
            case 'archive':
                if (!$isAdmin) { echo json_encode(['success' => false, 'error' => 'Accès refusé']); exit; }
                $id = (int) ($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID manquant');
                $pdo->prepare("UPDATE notifications SET is_archived = 1 WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true]);
                break;

            // ── Supprimer ────────────────────────────────────────────
            case 'delete':
                if (!$isAdmin) { echo json_encode(['success' => false, 'error' => 'Accès refusé']); exit; }
                $id = (int) ($_POST['id'] ?? 0);
                if (!$id) throw new Exception('ID manquant');
                $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true]);
                break;

            // ── Actions en masse ─────────────────────────────────────
            case 'bulk':
                if (!$isAdmin) { echo json_encode(['success' => false, 'error' => 'Accès refusé']); exit; }
                $ids    = array_map('intval', (array) ($_POST['ids'] ?? []));
                $act    = trim($_POST['bulk_action'] ?? '');
                if (empty($ids)) throw new Exception('Aucun élément sélectionné');
                $in     = implode(',', $ids);
                $aff    = match ($act) {
                    'read'    => $pdo->exec("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id IN ($in)"),
                    'unread'  => $pdo->exec("UPDATE notifications SET is_read = 0, read_at = NULL WHERE id IN ($in)"),
                    'archive' => $pdo->exec("UPDATE notifications SET is_archived = 1 WHERE id IN ($in)"),
                    'delete'  => $pdo->exec("DELETE FROM notifications WHERE id IN ($in)"),
                    default   => throw new Exception("Action inconnue: $act"),
                };
                echo json_encode(['success' => true, 'affected' => (int) $aff]);
                break;

            // ── Types distincts (pour les filtres) ───────────────────
            case 'get_types':
                $q = $isAdmin
                    ? $pdo->query("SELECT DISTINCT type FROM notifications WHERE is_archived = 0 ORDER BY type")
                    : $pdo->prepare("SELECT DISTINCT type FROM notifications WHERE is_archived = 0 AND (user_id = ? OR user_id IS NULL) ORDER BY type");
                if (!$isAdmin) $q->execute([$userId]);
                echo json_encode(['success' => true, 'types' => array_column($q->fetchAll(), 'type')]);
                break;

            // ── Utilisateurs pour le filtre (admin seulement) ────────
            case 'get_users':
                if (!$isAdmin) { echo json_encode(['success' => false]); exit; }
                $search = '%' . trim($_GET['q'] ?? '') . '%';
                $st = $pdo->prepare(
                    "SELECT id, nom, prenom, email FROM users
                     WHERE (nom LIKE ? OR prenom LIKE ? OR email LIKE ?) AND statut = 'actif'
                     ORDER BY nom LIMIT 20"
                );
                $st->execute([$search, $search, $search]);
                echo json_encode(['success' => true, 'users' => $st->fetchAll()]);
                break;

            // ── Créer une notification de test ───────────────────────
            case 'create_test':
                if (!$isAdmin) { echo json_encode(['success' => false, 'error' => 'Accès refusé']); exit; }
                $type    = trim($_POST['type']    ?? 'info');
                $title   = trim($_POST['title']   ?? 'Test notification');
                $message = trim($_POST['message'] ?? 'Ceci est une notification de test.');
                $uid     = ($_POST['uid'] ?? '') === '' ? null : (int) $_POST['uid'];

                if (!array_key_exists($type, TYPE_META)) $type = 'info';
                $icon = TYPE_META[$type]['icon'];

                $st = $pdo->prepare(
                    "INSERT INTO notifications (user_id, type, title, message, icon, is_read, is_archived)
                     VALUES (?, ?, ?, ?, ?, 0, 0)"
                );
                $st->execute([$uid, $type, $title, $message, $icon]);
                echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
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
// DONNÉES INITIALES (rendu HTML)
// ═════════════════════════════════════════════════════════════════════
$csrfToken = csrfToken();

// Statistiques initiales
try {
    $totalNotifs = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_archived = 0")->fetchColumn();
    $unreadCount = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND is_archived = 0")->fetchColumn();
    $readCount   = $totalNotifs - $unreadCount;
    $archCount   = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_archived = 1")->fetchColumn();

    $typesRaw = $pdo->query(
        "SELECT type, COUNT(*) cnt FROM notifications WHERE is_archived = 0 GROUP BY type ORDER BY cnt DESC"
    )->fetchAll();

    $usersRaw = $pdo->query(
        "SELECT u.id, u.nom, u.prenom, u.email FROM users u
         INNER JOIN notifications n ON n.user_id = u.id
         GROUP BY u.id ORDER BY u.nom LIMIT 100"
    )->fetchAll();

} catch (Throwable $e) {
    $totalNotifs = $unreadCount = $readCount = $archCount = 0;
    $typesRaw = $usersRaw = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — Digital Library System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══════════════════════════════════════════════
   VARIABLES & RESET
══════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#05080f;--surf:#0b1020;--card:rgba(255,255,255,.032);--card-hov:rgba(255,255,255,.055);
  --border:rgba(255,255,255,.072);--border-act:rgba(0,212,255,.4);
  --cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;--amber:#f59e0b;
  --rose:#f43f5e;--orange:#f97316;--plum:#a78bfa;--blue:#60a5fa;
  --tp:#eef2ff;--ts:rgba(238,242,255,.56);--tm:rgba(238,242,255,.28);
  --r1:8px;--r2:13px;--r3:18px;--r4:26px;
  --gc:0 0 24px rgba(0,212,255,.16);
  --sc:0 4px 24px rgba(0,0,0,.32);
  --slg:0 20px 60px rgba(0,0,0,.52);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tp);min-height:100vh;overflow-x:hidden}
::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}

/* ── PAGE LAYOUT ── */
.page-wrap{max-width:1400px;margin:0 auto;padding:2rem 1.6rem 5rem}

/* ── TOP NAV ── */
.top-nav{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1.8rem;padding-bottom:1rem;border-bottom:1px solid var(--border)}
.nav-link{display:inline-flex;align-items:center;gap:5px;font-size:.73rem;color:var(--ts);text-decoration:none;padding:5px 11px;border-radius:var(--r1);border:1px solid var(--border);background:var(--card);transition:all .15s;white-space:nowrap}
.nav-link:hover,.nav-link.on{color:var(--cyan);border-color:rgba(0,212,255,.3);background:rgba(0,212,255,.05)}
.nav-sep{flex:1}

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.8rem;flex-wrap:wrap}
.page-title-wrap{}
.page-title{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;letter-spacing:-.5px;display:flex;align-items:center;gap:.6rem;margin-bottom:4px}
.page-sub{font-size:.78rem;color:var(--ts)}
.page-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}

/* ── STATS CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.8rem;margin-bottom:1.8rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:1.1rem 1.2rem;position:relative;overflow:hidden;transition:all .22s;animation:fadeUp .4s ease both}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--a1,#fff),var(--a2,#888));opacity:0;transition:opacity .3s}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--sc)}
.stat-card:hover::before{opacity:1}
.stat-card:nth-child(1){--a1:var(--cyan);--a2:var(--violet);animation-delay:.04s}
.stat-card:nth-child(2){--a1:var(--rose);--a2:var(--amber);animation-delay:.08s}
.stat-card:nth-child(3){--a1:var(--neon);--a2:var(--cyan);animation-delay:.12s}
.stat-card:nth-child(4){--a1:var(--amber);--a2:var(--orange);animation-delay:.16s}
.stat-card:nth-child(5){--a1:var(--violet);--a2:var(--plum);animation-delay:.20s}
.sc-val{font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--a1,#fff),var(--a2,#aaa));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sc-label{font-size:.7rem;color:var(--ts);margin-top:3px;font-weight:500}

/* ── MAIN GRID ── */
.main-grid{display:grid;grid-template-columns:260px 1fr;gap:1.2rem;align-items:start}
@media(max-width:1024px){.main-grid{grid-template-columns:1fr}}

/* ── SIDEBAR FILTRES ── */
.filter-panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);overflow:hidden;position:sticky;top:1rem;animation:fadeUp .4s ease .1s both}
.fp-header{padding:.9rem 1.1rem;border-bottom:1px solid var(--border);font-family:'Syne',sans-serif;font-weight:700;font-size:.83rem;display:flex;align-items:center;gap:7px}
.fp-body{padding:1rem 1.1rem}
.fp-sect{font-family:'Space Mono',monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:.1em;color:var(--tm);margin-bottom:6px;margin-top:.9rem}
.fp-sect:first-child{margin-top:0}
.filter-group{display:flex;flex-direction:column;gap:4px;margin-bottom:.4rem}
.f-btn{display:flex;align-items:center;justify-content:space-between;padding:7px 11px;border-radius:var(--r1);border:1px solid transparent;background:transparent;color:var(--ts);font-size:.78rem;cursor:pointer;transition:all .15s;width:100%;text-align:left;font-family:'DM Sans',sans-serif}
.f-btn:hover{color:var(--tp);background:var(--card-hov)}
.f-btn.on{color:var(--cyan);background:rgba(0,212,255,.07);border-color:rgba(0,212,255,.2)}
.f-btn-lbl{display:flex;align-items:center;gap:6px}
.f-cnt{font-family:'Space Mono',monospace;font-size:.58rem;padding:1px 6px;border-radius:100px;background:rgba(255,255,255,.06);color:var(--tm)}
.fp-input{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r1);padding:7px 10px;color:var(--tp);font-size:.75rem;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.fp-input:focus{border-color:rgba(0,212,255,.4)}
.fp-select{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r1);padding:7px 10px;color:var(--tp);font-size:.75rem;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;transition:border-color .2s}
.fp-select:focus{border-color:rgba(0,212,255,.4)}
.date-row{display:grid;grid-template-columns:1fr 1fr;gap:5px}

/* ── CONTENU PRINCIPAL ── */
.content-panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);overflow:hidden;animation:fadeUp .4s ease .05s both}
.cp-header{padding:1rem 1.3rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}
.cp-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.88rem;flex:1}
.cp-toolbar{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}

/* ── BULK ACTIONS BAR ── */
#bulk-bar{background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.2);border-radius:var(--r2);padding:.7rem 1rem;margin:0 1rem .8rem;display:none;align-items:center;gap:.6rem;flex-wrap:wrap}
#bulk-bar.show{display:flex}
.bulk-count{font-family:'Space Mono',monospace;font-size:.68rem;color:var(--cyan);flex:1}

/* ── SEARCH BAR ── */
.search-bar{display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r1);padding:6px 11px;margin:0 1rem .8rem;transition:border-color .2s}
.search-bar:focus-within{border-color:rgba(0,212,255,.4);box-shadow:0 0 0 3px rgba(0,212,255,.07)}
.search-bar input{background:none;border:none;outline:none;color:var(--tp);font-size:.78rem;font-family:'DM Sans',sans-serif;width:100%}
.search-bar input::placeholder{color:var(--tm)}

/* ── NOTIFICATION LIST ── */
.notif-list{padding:0 .8rem}

/* ── NOTIFICATION ITEM ── */
.notif-item{display:flex;align-items:flex-start;gap:12px;padding:12px 8px;border-bottom:1px solid rgba(255,255,255,.04);border-radius:var(--r1);transition:all .15s;position:relative;cursor:default}
.notif-item:last-child{border-bottom:none}
.notif-item:hover{background:var(--card-hov)}
.notif-item.unread{background:rgba(0,212,255,.025)}
.notif-item.unread .ni-title{color:var(--tp)}
.ni-check{width:16px;height:16px;border:2px solid var(--border);border-radius:4px;cursor:pointer;flex-shrink:0;margin-top:2px;transition:all .15s;display:flex;align-items:center;justify-content:center}
.ni-check.checked{background:var(--cyan);border-color:var(--cyan)}
.ni-check.checked::after{content:'✓';font-size:.6rem;color:#000;font-weight:700}
.unread-dot{position:absolute;left:-2px;top:14px;width:6px;height:6px;border-radius:50%;background:var(--cyan);box-shadow:0 0 6px var(--cyan)}
.ni-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0;background:rgba(255,255,255,.04)}
.ni-body{flex:1;min-width:0}
.ni-title{font-weight:600;font-size:.82rem;color:var(--ts);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ni-msg{font-size:.74rem;color:var(--tm);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.ni-foot{display:flex;align-items:center;gap:.6rem;margin-top:5px;flex-wrap:wrap}
.ni-time{font-size:.6rem;font-family:'Space Mono',monospace;color:var(--tm)}
.ni-user{font-size:.62rem;color:var(--ts)}
.ni-actions{display:flex;align-items:center;gap:3px;flex-shrink:0;opacity:0;transition:opacity .15s}
.notif-item:hover .ni-actions{opacity:1}
.ni-btn{width:26px;height:26px;border-radius:7px;background:var(--card);border:1px solid var(--border);color:var(--tm);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.7rem;transition:all .15s}
.ni-btn:hover{color:var(--tp);border-color:rgba(255,255,255,.15)}
.ni-btn.danger:hover{color:var(--rose);border-color:rgba(244,63,94,.3);background:rgba(244,63,94,.06)}
.ni-btn.warn:hover{color:var(--amber);border-color:rgba(245,158,11,.3)}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:3px;font-size:.57rem;font-family:'Space Mono',monospace;padding:2px 7px;border-radius:100px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.badge-neon{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.badge-violet{background:rgba(124,58,237,.1);color:var(--plum);border:1px solid rgba(124,58,237,.2)}
.badge-cyan{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.badge-blue{background:rgba(96,165,250,.1);color:var(--blue);border:1px solid rgba(96,165,250,.2)}
.badge-rose{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.badge-amber{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.badge-danger{background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2)}
.badge-purple{background:rgba(192,132,252,.1);color:#c084fc;border:1px solid rgba(192,132,252,.2)}
.badge-muted{background:rgba(255,255,255,.05);color:var(--tm);border:1px solid var(--border)}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--r1);font-family:'Syne',sans-serif;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .17s;text-decoration:none;border:none;white-space:nowrap}
.btn-sm{padding:5px 10px;font-size:.7rem}
.btn-xs{padding:3px 8px;font-size:.64rem}
.btn-primary{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff;box-shadow:0 4px 14px rgba(0,212,255,.18)}
.btn-primary:hover{opacity:.87;transform:translateY(-1px)}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--ts)}
.btn-ghost:hover{color:var(--tp);background:var(--card-hov)}
.btn-danger{background:rgba(244,63,94,.07);border:1px solid rgba(244,63,94,.22);color:var(--rose)}
.btn-danger:hover{background:rgba(244,63,94,.15)}
.btn-success{background:rgba(0,255,170,.07);border:1px solid rgba(0,255,170,.22);color:var(--neon)}
.btn-success:hover{background:rgba(0,255,170,.14)}
.btn-amber{background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.22);color:var(--amber)}
.btn-amber:hover{background:rgba(245,158,11,.14)}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;justify-content:center;gap:4px;padding:1rem;border-top:1px solid var(--border)}
.pag-btn{width:30px;height:30px;border-radius:var(--r1);background:var(--card);border:1px solid var(--border);color:var(--ts);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.7rem;font-family:'Space Mono',monospace;transition:all .15s}
.pag-btn:hover,.pag-btn.on{color:var(--tp);background:rgba(0,212,255,.08);border-color:rgba(0,212,255,.25)}
.pag-btn.on{color:var(--cyan);font-weight:700}
.pag-btn[disabled]{opacity:.3;pointer-events:none}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:3.5rem 1rem}
.empty-icon{font-size:3rem;display:block;margin-bottom:.6rem;opacity:.4}
.empty-t{font-family:'Syne',sans-serif;font-weight:700;margin-bottom:.3rem}
.empty-s{font-size:.78rem;color:var(--tm)}

/* ── LOADER ── */
.loader-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem;gap:.7rem}
.spinner{width:28px;height:28px;border:2px solid rgba(0,212,255,.15);border-top-color:var(--cyan);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── TOAST ── */
#toast-stack{position:fixed;bottom:1.4rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:6px;pointer-events:none}
.toast{display:flex;align-items:center;gap:9px;padding:10px 14px;background:var(--surf);border:1px solid var(--border);border-radius:var(--r2);box-shadow:var(--slg);font-size:.76rem;max-width:300px;pointer-events:all;transform:translateX(120px);opacity:0;transition:all .35s cubic-bezier(.34,1.56,.64,1)}
.toast.show{transform:translateX(0);opacity:1}
.t-ico{font-size:1rem;flex-shrink:0}
.t-body{flex:1}
.t-title{font-family:'Syne',sans-serif;font-weight:700}
.t-sub{font-size:.66rem;color:var(--tm);margin-top:1px}

/* ── MODAL ── */
.modal-ov{position:fixed;inset:0;background:rgba(5,8,15,.88);backdrop-filter:blur(14px);z-index:800;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-ov.open{opacity:1;pointer-events:all}
.modal-box{background:var(--surf);border:1px solid var(--border);border-radius:var(--r4);padding:1.8rem;max-width:480px;width:100%;box-shadow:var(--slg);transform:translateY(20px);transition:transform .3s cubic-bezier(.34,1.56,.64,1);position:relative;overflow:hidden}
.modal-ov.open .modal-box{transform:translateY(0)}
.modal-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon))}
.modal-title{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;margin-bottom:1.2rem}
.modal-close{position:absolute;top:.9rem;right:.9rem;background:none;border:none;color:var(--tm);font-size:.95rem;cursor:pointer;width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.modal-close:hover{color:var(--tp);background:var(--card-hov)}
.modal-field{margin-bottom:.9rem}
.modal-label{display:block;font-family:'Space Mono',monospace;font-size:.6rem;text-transform:uppercase;letter-spacing:.08em;color:var(--tm);margin-bottom:5px}
.modal-input,.modal-select,.modal-textarea{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r1);padding:8px 11px;color:var(--tp);font-size:.8rem;font-family:'DM Sans',sans-serif;outline:none;transition:border-color .2s}
.modal-input:focus,.modal-select:focus,.modal-textarea:focus{border-color:rgba(0,212,255,.4)}
.modal-textarea{resize:vertical;min-height:80px}

/* ── REALTIME INDICATOR ── */
.rt-dot{width:7px;height:7px;border-radius:50%;background:var(--neon);box-shadow:0 0 8px var(--neon);animation:rtPulse 2s ease-in-out infinite;display:inline-block}
@keyframes rtPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}

/* ── HEADER LIVE INDICATOR ── */
.live-badge{display:inline-flex;align-items:center;gap:5px;font-family:'Space Mono',monospace;font-size:.6rem;padding:3px 9px;border-radius:100px;background:rgba(0,255,170,.06);color:var(--neon);border:1px solid rgba(0,255,170,.18)}

/* ── PROGRESS MINI ── */
.type-row{display:flex;align-items:center;gap:.5rem;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.type-row:last-child{border-bottom:none}
.type-name{font-size:.72rem;color:var(--ts);flex:1}
.type-bar{flex:2;height:4px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden}
.type-fill{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--violet),var(--cyan));transition:width 1s ease}
.type-num{font-family:'Space Mono',monospace;font-size:.6rem;color:var(--tm);flex-shrink:0;min-width:25px;text-align:right}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  .main-grid{grid-template-columns:1fr}
  .filter-panel{position:static}
  .page-wrap{padding:1.2rem .9rem}
  .stats-row{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:480px){
  .stats-row{grid-template-columns:1fr 1fr}
  .page-title{font-size:1.2rem}
}
</style>
</head>
<body>
<div class="page-wrap">

  <!-- ── TOP NAV ── -->
  <nav class="top-nav">
    <a href="../dashboard.php" class="nav-link"><i class="bi bi-grid-1x2"></i> Dashboard</a>
    <?php if ($isAdmin): ?>
    <a href="../users/index.php"   class="nav-link"><i class="bi bi-people"></i> Utilisateurs</a>
    <a href="../books/index.php"   class="nav-link"><i class="bi bi-book-half"></i> Livres</a>
    <a href="../admin/settings.php" class="nav-link"><i class="bi bi-gear"></i> Paramètres</a>
    <?php else: ?>
    <a href="../books/index.php"   class="nav-link"><i class="bi bi-compass"></i> Catalogue</a>
    <?php endif; ?>
    <a href="notifications.php" class="nav-link on"><i class="bi bi-bell-fill"></i> Notifications</a>
    <span class="nav-sep"></span>
    <a href="../logout.php" class="nav-link"><i class="bi bi-box-arrow-left"></i> Déconnexion</a>
  </nav>

  <!-- ── PAGE HEADER ── -->
  <div class="page-header">
    <div class="page-title-wrap">
      <div class="page-title">
        <i class="bi bi-bell-fill" style="color:var(--cyan)"></i>
        Notifications
        <?php if ($unreadCount > 0): ?>
          <span style="font-size:.62rem;padding:2px 9px;border-radius:100px;background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2);font-family:'Space Mono',monospace" id="header-unread-badge"><?= $unreadCount ?></span>
        <?php endif; ?>
        <span class="live-badge"><span class="rt-dot"></span> LIVE</span>
      </div>
      <div class="page-sub">
        <?= $isAdmin
            ? 'Vue administrateur — toutes les notifications de la plateforme'
            : 'Vos notifications personnelles et les annonces globales' ?>
      </div>
    </div>
    <div class="page-actions">
      <?php if ($unreadCount > 0): ?>
        <button class="btn btn-success btn-sm" onclick="markAllRead()">
          <i class="bi bi-check-all"></i> Tout marquer lu
        </button>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <button class="btn btn-ghost btn-sm" onclick="openCreateModal()">
          <i class="bi bi-plus-circle"></i> Créer
        </button>
      <?php endif; ?>
      <button class="btn btn-ghost btn-sm" onclick="refreshNotifs()" id="refresh-btn">
        <i class="bi bi-arrow-clockwise" id="refresh-ico"></i> Actualiser
      </button>
    </div>
  </div>

  <!-- ── STATS CARDS ── -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="sc-val" id="st-total"><?= $totalNotifs ?></div>
      <div class="sc-label">Total</div>
    </div>
    <div class="stat-card">
      <div class="sc-val" id="st-unread"><?= $unreadCount ?></div>
      <div class="sc-label">Non lues</div>
    </div>
    <div class="stat-card">
      <div class="sc-val" id="st-read"><?= $readCount ?></div>
      <div class="sc-label">Lues</div>
    </div>
    <div class="stat-card">
      <div class="sc-val" id="st-arch"><?= $archCount ?></div>
      <div class="sc-label">Archivées</div>
    </div>
    <?php if ($isAdmin): ?>
    <div class="stat-card">
      <div class="sc-val" id="st-users"><?= count($usersRaw) ?></div>
      <div class="sc-label">Utilisateurs notifiés</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── MAIN GRID ── -->
  <div class="main-grid">

    <!-- ═══ SIDEBAR FILTRES ═══ -->
    <aside class="filter-panel">
      <div class="fp-header"><i class="bi bi-funnel" style="color:var(--cyan)"></i> Filtres</div>
      <div class="fp-body">

        <div class="fp-sect">Statut</div>
        <div class="filter-group">
          <button class="f-btn on" data-filter="status" data-value="all" onclick="setFilter('status','all',this)">
            <span class="f-btn-lbl"><i class="bi bi-circle"></i> Tous</span>
            <span class="f-cnt" id="cnt-all"><?= $totalNotifs ?></span>
          </button>
          <button class="f-btn" data-filter="status" data-value="unread" onclick="setFilter('status','unread',this)">
            <span class="f-btn-lbl"><i class="bi bi-circle-fill" style="color:var(--cyan);font-size:.65rem"></i> Non lus</span>
            <span class="f-cnt" id="cnt-unread"><?= $unreadCount ?></span>
          </button>
          <button class="f-btn" data-filter="status" data-value="read" onclick="setFilter('status','read',this)">
            <span class="f-btn-lbl"><i class="bi bi-check-circle" style="color:var(--neon)"></i> Lus</span>
            <span class="f-cnt" id="cnt-read"><?= $readCount ?></span>
          </button>
        </div>

        <div class="fp-sect">Type</div>
        <div class="filter-group" id="type-filters">
          <button class="f-btn on" data-filter="type" data-value="all" onclick="setFilter('type','all',this)">
            <span class="f-btn-lbl">🔔 Tous les types</span>
          </button>
          <?php foreach ($typesRaw as $t):
            $meta = getMeta($t['type']);
          ?>
          <button class="f-btn" data-filter="type" data-value="<?= e($t['type']) ?>" onclick="setFilter('type','<?= e($t['type']) ?>',this)">
            <span class="f-btn-lbl"><?= $meta['icon'] ?> <?= e($meta['label']) ?></span>
            <span class="f-cnt"><?= (int)$t['cnt'] ?></span>
          </button>
          <?php endforeach; ?>
        </div>

        <?php if ($isAdmin): ?>
        <div class="fp-sect">Utilisateur</div>
        <select class="fp-select" id="filter-uid" onchange="setFilter('uid', this.value)">
          <option value="0">Tous les utilisateurs</option>
          <?php foreach ($usersRaw as $u): ?>
            <option value="<?= (int)$u['id'] ?>">
              <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <div class="fp-sect">Période</div>
        <div class="date-row">
          <input type="date" class="fp-input" id="filter-from" placeholder="Du" onchange="applyFilters()">
          <input type="date" class="fp-input" id="filter-to"   placeholder="Au" onchange="applyFilters()">
        </div>

        <div class="fp-sect" style="margin-top:1.2rem">Types par volume</div>
        <div id="type-bars" style="margin-top:4px">
          <?php
          $maxCnt = max(1, ...array_column($typesRaw, 'cnt'));
          foreach (array_slice($typesRaw, 0, 6) as $t):
            $meta = getMeta($t['type']);
            $pct  = round((int)$t['cnt'] / $maxCnt * 100);
          ?>
          <div class="type-row">
            <span class="type-name"><?= $meta['icon'] ?> <?= e($meta['label']) ?></span>
            <div class="type-bar"><div class="type-fill" style="width:<?= $pct ?>%"></div></div>
            <span class="type-num"><?= (int)$t['cnt'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:1.1rem">
          <button class="btn btn-ghost" style="width:100%;justify-content:center" onclick="resetFilters()">
            <i class="bi bi-x-circle"></i> Réinitialiser
          </button>
        </div>

      </div>
    </aside>

    <!-- ═══ CONTENU PRINCIPAL ═══ -->
    <div>
      <div class="content-panel">
        <div class="cp-header">
          <span class="cp-title">
            Notifications
            <span class="badge badge-muted" id="cp-total-badge">…</span>
          </span>
          <div class="cp-toolbar">
            <?php if ($isAdmin): ?>
            <select class="fp-select" style="width:auto;font-size:.72rem" id="bulk-select-action">
              <option value="">Actions groupées…</option>
              <option value="read">✅ Marquer lus</option>
              <option value="unread">⭕ Marquer non lus</option>
              <option value="archive">📁 Archiver</option>
              <option value="delete">🗑️ Supprimer</option>
            </select>
            <button class="btn btn-ghost btn-sm" onclick="applyBulk()" id="bulk-apply-btn" style="display:none">
              Appliquer
            </button>
            <?php endif; ?>
            <select class="fp-select" style="width:auto;font-size:.72rem" id="per-page-select" onchange="applyFilters()">
              <option value="20">20 / page</option>
              <option value="50">50 / page</option>
              <option value="100">100 / page</option>
            </select>
          </div>
        </div>

        <!-- Search -->
        <div class="search-bar">
          <i class="bi bi-search" style="color:var(--tm);font-size:.8rem"></i>
          <input type="search" id="search-input" placeholder="Recherche dans les notifications…" autocomplete="off"
                 oninput="debounceSearch(this.value)">
          <span id="search-clear" style="cursor:pointer;color:var(--tm);font-size:.8rem;display:none" onclick="clearSearch()">
            <i class="bi bi-x"></i>
          </span>
        </div>

        <!-- Bulk bar -->
        <div id="bulk-bar">
          <span class="bulk-count" id="bulk-count-label">0 sélectionné(s)</span>
          <button class="btn btn-xs btn-success" onclick="selectAll()">Tout sélectionner</button>
          <button class="btn btn-xs btn-ghost"   onclick="deselectAll()">Désélectionner</button>
        </div>

        <!-- Liste -->
        <div class="notif-list" id="notif-list">
          <div class="loader-wrap">
            <div class="spinner"></div>
            <span style="font-size:.76rem;color:var(--tm)">Chargement…</span>
          </div>
        </div>

        <!-- Pagination -->
        <div class="pagination" id="pagination" style="display:none"></div>

      </div>
    </div>

  </div><!-- /main-grid -->

</div><!-- /page-wrap -->

<!-- ═══ MODAL : CRÉER NOTIFICATION ═══ -->
<div class="modal-ov" id="create-modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">➕ Créer une notification</div>
    <div class="modal-field">
      <label class="modal-label">Type</label>
      <select class="modal-select" id="m-type">
        <?php foreach (TYPE_META as $k => $v): ?>
          <option value="<?= e($k) ?>"><?= $v['icon'] ?> <?= e($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="modal-field">
      <label class="modal-label">Titre</label>
      <input type="text" class="modal-input" id="m-title" placeholder="Titre court…" maxlength="255">
    </div>
    <div class="modal-field">
      <label class="modal-label">Message</label>
      <textarea class="modal-textarea" id="m-message" placeholder="Corps de la notification…"></textarea>
    </div>
    <div class="modal-field">
      <label class="modal-label">Destinataire</label>
      <select class="modal-select" id="m-uid">
        <option value="">Globale (tous les utilisateurs)</option>
        <?php foreach ($usersRaw as $u): ?>
          <option value="<?= (int)$u['id'] ?>">
            #<?= (int)$u['id'] ?> — <?= e(trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''))) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:.6rem;margin-top:1.3rem">
      <button class="btn btn-ghost" style="flex:1;justify-content:center" onclick="closeModal()">Annuler</button>
      <button class="btn btn-primary" style="flex:1;justify-content:center" onclick="submitCreate()">
        <i class="bi bi-send"></i> Envoyer
      </button>
    </div>
  </div>
</div>

<!-- Toast Stack -->
<div id="toast-stack"></div>

<!-- ═══════════════════════════════════════════════
     JAVASCRIPT — Logique complète AJAX temps réel
═══════════════════════════════════════════════ -->
<script>
/* ── Globals ── */
const CSRF    = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const IS_ADMIN = <?= json_encode($isAdmin) ?>;
const API_URL  = 'notifications.php';

let currentPage    = 1;
let currentFilters = { type: 'all', status: 'all', uid: '0', search: '', date_from: '', date_to: '' };
let selectedIds    = new Set();
let searchTimer    = null;
let refreshTimer   = null;
let lastTotal      = 0;

/* ── Escape HTML ── */
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

/* ── Toast ── */
const TICONS  = { success:'✅', error:'❌', warn:'⚠️', info:'ℹ️' };
const TCOLORS = { success:'var(--neon)', error:'var(--rose)', warn:'var(--amber)', info:'var(--cyan)' };
function toast(title, sub = '', type = 'info', dur = 3500) {
  const s = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = TCOLORS[type] || TCOLORS.info;
  t.innerHTML = `<span class="t-ico">${TICONS[type]||'ℹ️'}</span>
    <div class="t-body"><div class="t-title">${esc(title)}</div>${sub?`<div class="t-sub">${esc(sub)}</div>`:''}</div>
    <span style="cursor:pointer;color:var(--tm)" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></span>`;
  s.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, dur);
}

/* ── AJAX helper ── */
async function ajax(action, params = {}, method = 'GET') {
  const url = new URL(API_URL, window.location.href);
  url.searchParams.set('action', action);

  const opts = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };

  if (method === 'POST') {
    params.csrf = CSRF;
    const fd = new FormData();
    Object.entries(params).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach(vi => fd.append(k + '[]', vi));
      else fd.append(k, v);
    });
    opts.method = 'POST';
    opts.body   = fd;
  } else {
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  }

  const r = await fetch(url.toString(), opts);
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

/* ═══════════════════════════════════════
   CHARGEMENT PRINCIPAL
═══════════════════════════════════════ */
async function loadNotifs(page = 1) {
  currentPage = page;
  const list  = document.getElementById('notif-list');
  const pag   = document.getElementById('pagination');

  list.innerHTML = '<div class="loader-wrap"><div class="spinner"></div><span style="font-size:.76rem;color:var(--tm)">Chargement…</span></div>';
  pag.style.display = 'none';

  try {
    const d = await ajax('load', {
      page,
      per_page : document.getElementById('per-page-select').value,
      type     : currentFilters.type,
      status   : currentFilters.status,
      uid      : currentFilters.uid,
      search   : currentFilters.search,
      date_from: currentFilters.date_from,
      date_to  : currentFilters.date_to,
    });

    if (!d.success) throw new Error(d.error || 'Erreur');

    // Mettre à jour les compteurs en header
    updateCounters(d.unread, null);
    lastTotal = d.total;

    document.getElementById('cp-total-badge').textContent = d.total + ' notif' + (d.total > 1 ? 's' : '');

    if (d.items.length === 0) {
      list.innerHTML = `
        <div class="empty-state">
          <span class="empty-icon">🔕</span>
          <div class="empty-t">Aucune notification</div>
          <div class="empty-s">Aucun résultat pour ces critères.</div>
        </div>`;
      return;
    }

    list.innerHTML = d.items.map(n => renderItem(n)).join('');
    buildPagination(page, d.pages);

  } catch(e) {
    list.innerHTML = `
      <div class="empty-state">
        <span class="empty-icon">⚠️</span>
        <div class="empty-t">Erreur de chargement</div>
        <div class="empty-s">${esc(e.message)}</div>
        <button class="btn btn-ghost btn-sm" style="margin-top:.8rem" onclick="loadNotifs(${page})">Réessayer</button>
      </div>`;
  }
}

/* ── Rendre un item de notification ── */
function renderItem(n) {
  const meta    = TYPE_META_JS[n.type] || { label: n.type, icon: '🔔', badge: 'badge-muted', dot: '#94a3b8' };
  const unread  = !parseInt(n.is_read);
  const dt      = n.created_at ? new Date(n.created_at) : null;
  const timeStr = dt ? timeAgo(dt) : '—';
  const fullDt  = dt ? dt.toLocaleString('fr-FR') : '';
  const userName = n.user_nom ? `${esc(n.user_prenom || '')} ${esc(n.user_nom || '')}`.trim() : null;
  const checked  = selectedIds.has(parseInt(n.id));

  return `
    <div class="notif-item${unread ? ' unread' : ''}" data-id="${n.id}" id="ni-${n.id}">
      ${unread ? '<div class="unread-dot"></div>' : ''}
      ${IS_ADMIN ? `
        <div class="ni-check${checked ? ' checked' : ''}"
             onclick="toggleSelect(${n.id}, this)"
             title="Sélectionner"></div>
      ` : ''}
      <div class="ni-icon">${esc(n.icon || meta.icon)}</div>
      <div class="ni-body">
        <div class="ni-title">${esc(n.title || '(sans titre)')}</div>
        ${n.message ? `<div class="ni-msg">${esc(n.message)}</div>` : ''}
        <div class="ni-foot">
          <span class="ni-time" title="${esc(fullDt)}">${esc(timeStr)}</span>
          <span class="badge ${meta.badge}">${esc(meta.label)}</span>
          ${userName ? `<span class="ni-user"><i class="bi bi-person" style="font-size:.58rem"></i> ${esc(userName)}</span>` : '<span class="ni-user" style="font-size:.6rem;color:var(--tm)">Global</span>'}
        </div>
      </div>
      <div class="ni-actions">
        ${unread
          ? `<button class="ni-btn" onclick="doMarkRead(${n.id})" title="Marquer lu"><i class="bi bi-check-lg"></i></button>`
          : `<button class="ni-btn warn" onclick="doMarkUnread(${n.id})" title="Marquer non lu"><i class="bi bi-circle"></i></button>`
        }
        ${IS_ADMIN ? `
          <button class="ni-btn warn"   onclick="doArchive(${n.id})"  title="Archiver"><i class="bi bi-archive"></i></button>
          <button class="ni-btn danger" onclick="doDelete(${n.id})"   title="Supprimer"
                  data-title="${esc(n.title)}"><i class="bi bi-trash3"></i></button>
        ` : ''}
      </div>
    </div>`;
}

/* ── Formatage temps ── */
function timeAgo(dt) {
  const diff = (Date.now() - dt.getTime()) / 1000;
  if (diff < 60)     return 'à l\'instant';
  if (diff < 3600)   return Math.floor(diff / 60) + ' min';
  if (diff < 86400)  return Math.floor(diff / 3600) + 'h';
  if (diff < 604800) return Math.floor(diff / 86400) + 'j';
  return dt.toLocaleDateString('fr-FR');
}

/* ── Métadonnées types (côté JS) ── */
const TYPE_META_JS = <?= json_encode(array_map(fn($v) => [
    'label' => $v['label'],
    'icon'  => $v['icon'],
    'badge' => $v['badge'],
    'dot'   => $v['dot'],
], TYPE_META), JSON_HEX_TAG) ?>;

/* ═══════════════════════════════════════
   FILTRES
═══════════════════════════════════════ */
function setFilter(key, value, btn = null) {
  currentFilters[key] = value;

  if (btn) {
    const group = btn.closest('.filter-group') || document.getElementById('type-filters');
    group?.querySelectorAll('.f-btn[data-filter="' + key + '"]')
          .forEach(b => b.classList.toggle('on', b === btn));
  }
  applyFilters();
}

function applyFilters() {
  currentFilters.date_from = document.getElementById('filter-from')?.value || '';
  currentFilters.date_to   = document.getElementById('filter-to')?.value   || '';
  if (document.getElementById('filter-uid')) {
    currentFilters.uid = document.getElementById('filter-uid').value || '0';
  }
  loadNotifs(1);
}

function resetFilters() {
  currentFilters = { type: 'all', status: 'all', uid: '0', search: '', date_from: '', date_to: '' };
  document.getElementById('search-input').value   = '';
  document.getElementById('filter-from').value    = '';
  document.getElementById('filter-to').value      = '';
  if (document.getElementById('filter-uid')) document.getElementById('filter-uid').value = '0';
  document.querySelectorAll('.f-btn').forEach(b => b.classList.remove('on'));
  document.querySelectorAll('.f-btn[data-value="all"]').forEach(b => b.classList.add('on'));
  loadNotifs(1);
  toast('Filtres', 'Réinitialisés', 'info', 2000);
}

/* ── Recherche avec debounce ── */
function debounceSearch(val) {
  clearTimeout(searchTimer);
  const q = val.trim();
  document.getElementById('search-clear').style.display = q ? 'inline' : 'none';
  searchTimer = setTimeout(() => {
    currentFilters.search = q;
    loadNotifs(1);
  }, 350);
}
function clearSearch() {
  document.getElementById('search-input').value = '';
  document.getElementById('search-clear').style.display = 'none';
  currentFilters.search = '';
  loadNotifs(1);
}

/* ═══════════════════════════════════════
   ACTIONS INDIVIDUELLES
═══════════════════════════════════════ */
async function doMarkRead(id) {
  try {
    const d = await ajax('mark_read', { id }, 'POST');
    if (!d.success) throw new Error(d.error);
    const item = document.getElementById('ni-' + id);
    if (item) {
      item.classList.remove('unread');
      item.querySelector('.unread-dot')?.remove();
      const btn = item.querySelector('.ni-btn');
      if (btn) btn.outerHTML = `<button class="ni-btn warn" onclick="doMarkUnread(${id})" title="Marquer non lu"><i class="bi bi-circle"></i></button>`;
    }
    updateCounters(null, -1);
    toast('Lu', 'Notification marquée comme lue', 'success', 2000);
  } catch(e) { toast('Erreur', e.message, 'error'); }
}

async function doMarkUnread(id) {
  try {
    const d = await ajax('mark_unread', { id }, 'POST');
    if (!d.success) throw new Error(d.error);
    const item = document.getElementById('ni-' + id);
    if (item) {
      item.classList.add('unread');
      const btn = item.querySelector('.ni-btn.warn');
      if (btn) btn.outerHTML = `<button class="ni-btn" onclick="doMarkRead(${id})" title="Marquer lu"><i class="bi bi-check-lg"></i></button>`;
    }
    updateCounters(null, 1);
  } catch(e) { toast('Erreur', e.message, 'error'); }
}

async function doArchive(id) {
  try {
    const d = await ajax('archive', { id }, 'POST');
    if (!d.success) throw new Error(d.error);
    document.getElementById('ni-' + id)?.remove();
    toast('Archivé', 'Notification archivée', 'warn', 2500);
    updateCounters(null, null, +1);
  } catch(e) { toast('Erreur', e.message, 'error'); }
}

async function doDelete(id) {
  const btn   = document.querySelector(`[data-id="${id}"] .ni-btn.danger`);
  const title = btn?.dataset.title || 'cette notification';
  if (!confirm(`Supprimer "${title}" ?`)) return;
  try {
    const d = await ajax('delete', { id }, 'POST');
    if (!d.success) throw new Error(d.error);
    const el = document.getElementById('ni-' + id);
    if (el) { el.style.opacity = '0'; el.style.transform = 'translateX(20px)'; el.style.transition = 'all .25s'; setTimeout(() => el.remove(), 260); }
    toast('Supprimé', 'Notification supprimée', 'success', 2500);
    lastTotal--;
    document.getElementById('cp-total-badge').textContent = lastTotal + ' notif' + (lastTotal > 1 ? 's' : '');
  } catch(e) { toast('Erreur', e.message, 'error'); }
}

async function markAllRead() {
  try {
    const d = await ajax('mark_all_read', {}, 'POST');
    if (!d.success) throw new Error(d.error);
    toast('Tout lu', `${d.affected} notification(s) marquée(s)`, 'success', 3000);
    loadNotifs(currentPage);
    updateCounters(0, null);
  } catch(e) { toast('Erreur', e.message, 'error'); }
}

/* ═══════════════════════════════════════
   SÉLECTION & BULK ACTIONS
═══════════════════════════════════════ */
function toggleSelect(id, el) {
  id = parseInt(id);
  if (selectedIds.has(id)) { selectedIds.delete(id); el.classList.remove('checked'); }
  else                     { selectedIds.add(id);    el.classList.add('checked'); }
  updateBulkBar();
}
function selectAll() {
  document.querySelectorAll('.ni-check').forEach(el => {
    const id = parseInt(el.closest('.notif-item').dataset.id);
    selectedIds.add(id); el.classList.add('checked');
  });
  updateBulkBar();
}
function deselectAll() {
  selectedIds.clear();
  document.querySelectorAll('.ni-check.checked').forEach(el => el.classList.remove('checked'));
  updateBulkBar();
}
function updateBulkBar() {
  const bar   = document.getElementById('bulk-bar');
  const label = document.getElementById('bulk-count-label');
  const applyBtn = document.getElementById('bulk-apply-btn');
  if (selectedIds.size > 0) {
    bar.classList.add('show');
    label.textContent = selectedIds.size + ' sélectionné(s)';
    if (applyBtn) applyBtn.style.display = 'inline-flex';
  } else {
    bar.classList.remove('show');
    if (applyBtn) applyBtn.style.display = 'none';
  }
}
async function applyBulk() {
  const action = document.getElementById('bulk-select-action').value;
  if (!action) { toast('Action groupée', 'Choisissez une action', 'warn'); return; }
  if (selectedIds.size === 0) { toast('Action groupée', 'Sélectionnez des éléments', 'warn'); return; }
  if (action === 'delete' && !confirm(`Supprimer ${selectedIds.size} notification(s) ?`)) return;
  try {
    const d = await ajax('bulk', { ids: [...selectedIds], bulk_action: action }, 'POST');
    if (!d.success) throw new Error(d.error);
    toast('Succès', `${d.affected} élément(s) traité(s)`, 'success', 3000);
    selectedIds.clear();
    loadNotifs(currentPage);
  } catch(e) { toast('Erreur', e.message, 'error'); }
}

/* ═══════════════════════════════════════
   MODAL CRÉER
═══════════════════════════════════════ */
function openCreateModal() {
  document.getElementById('create-modal').classList.add('open');
  document.getElementById('m-title').focus();
}
function closeModal() {
  document.getElementById('create-modal').classList.remove('open');
}
async function submitCreate() {
  const type    = document.getElementById('m-type').value;
  const title   = document.getElementById('m-title').value.trim();
  const message = document.getElementById('m-message').value.trim();
  const uid     = document.getElementById('m-uid').value;
  if (!title)   { toast('Champ requis', 'Saisissez un titre', 'warn'); return; }
  if (!message) { toast('Champ requis', 'Saisissez un message', 'warn'); return; }
  try {
    const d = await ajax('create_test', { type, title, message, uid }, 'POST');
    if (!d.success) throw new Error(d.error);
    closeModal();
    toast('Créé', 'Notification envoyée (#' + d.id + ')', 'success', 3000);
    loadNotifs(1);
  } catch(e) { toast('Erreur', e.message, 'error'); }
}

/* ═══════════════════════════════════════
   PAGINATION
═══════════════════════════════════════ */
function buildPagination(cur, total) {
  const el = document.getElementById('pagination');
  if (total <= 1) { el.style.display = 'none'; return; }
  el.style.display = 'flex';
  let html = `<button class="pag-btn" onclick="loadNotifs(${cur - 1})" ${cur <= 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button>`;
  const start = Math.max(1, cur - 2);
  const end   = Math.min(total, cur + 2);
  if (start > 1)  html += `<button class="pag-btn" onclick="loadNotifs(1)">1</button><span style="color:var(--tm);font-size:.7rem;padding:0 3px">…</span>`;
  for (let p = start; p <= end; p++) {
    html += `<button class="pag-btn${p === cur ? ' on' : ''}" onclick="loadNotifs(${p})">${p}</button>`;
  }
  if (end < total) html += `<span style="color:var(--tm);font-size:.7rem;padding:0 3px">…</span><button class="pag-btn" onclick="loadNotifs(${total})">${total}</button>`;
  html += `<button class="pag-btn" onclick="loadNotifs(${cur + 1})" ${cur >= total ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button>`;
  el.innerHTML = html;
}

/* ═══════════════════════════════════════
   COMPTEURS HEADER
═══════════════════════════════════════ */
function updateCounters(unreadAbs = null, unreadDelta = null, archDelta = null) {
  const unreadEl = document.getElementById('st-unread');
  const readEl   = document.getElementById('st-read');
  const badge    = document.getElementById('header-unread-badge');

  if (unreadAbs !== null && unreadEl) {
    unreadEl.textContent = unreadAbs;
    if (badge) badge.textContent = unreadAbs;
    if (badge) badge.style.display = unreadAbs > 0 ? 'inline' : 'none';
  } else if (unreadDelta !== null && unreadEl) {
    const cur = parseInt(unreadEl.textContent || '0');
    const nv  = Math.max(0, cur + unreadDelta);
    unreadEl.textContent = nv;
    if (badge) { badge.textContent = nv; badge.style.display = nv > 0 ? 'inline' : 'none'; }
    if (readEl) readEl.textContent = Math.max(0, parseInt(readEl.textContent || '0') - unreadDelta);
  }
  if (archDelta !== null) {
    const archEl = document.getElementById('st-arch');
    if (archEl) archEl.textContent = Math.max(0, parseInt(archEl.textContent || '0') + archDelta);
  }
}

/* ═══════════════════════════════════════
   REFRESH (temps réel — polling 15s)
═══════════════════════════════════════ */
async function refreshNotifs() {
  const ico = document.getElementById('refresh-ico');
  if (ico) ico.style.animation = 'spin .5s linear infinite';
  await loadNotifs(currentPage);
  // Mettre à jour les stats
  try {
    const d = await ajax('stats');
    if (d.success) {
      document.getElementById('st-total').textContent  = d.total;
      document.getElementById('st-unread').textContent = d.unread;
      document.getElementById('st-read').textContent   = d.read;
      document.getElementById('st-arch').textContent   = d.archived;
      document.getElementById('cnt-all').textContent   = d.total;
      document.getElementById('cnt-unread').textContent = d.unread;
      document.getElementById('cnt-read').textContent  = d.read;
    }
  } catch(e) {}
  if (ico) ico.style.animation = '';
}

/* ── Polling toutes les 15 secondes ── */
function startPolling() {
  refreshTimer = setInterval(async () => {
    try {
      const d = await ajax('stats');
      if (!d.success) return;
      const prevUnread = parseInt(document.getElementById('st-unread').textContent || '0');
      document.getElementById('st-total').textContent   = d.total;
      document.getElementById('st-unread').textContent  = d.unread;
      document.getElementById('st-read').textContent    = d.read;
      document.getElementById('cnt-all').textContent    = d.total;
      document.getElementById('cnt-unread').textContent = d.unread;
      // Nouvelles notifs détectées
      if (d.unread > prevUnread) {
        toast('🔔 Nouvelles notifications', `${d.unread - prevUnread} nouvelle(s)`, 'info', 4000);
        loadNotifs(currentPage); // Recharger la liste
      }
    } catch(e) {}
  }, 15000);
}

/* ═══════════════════════════════════════
   INIT
═══════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  loadNotifs(1);
  startPolling();

  // Bulk: afficher bouton appliquer quand action choisie
  document.getElementById('bulk-select-action')?.addEventListener('change', function() {
    const btn = document.getElementById('bulk-apply-btn');
    if (btn) btn.style.display = (this.value && selectedIds.size > 0) ? 'inline-flex' : 'none';
  });

  // Fermer modal avec Escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
  });

  // Toast de bienvenue
  setTimeout(() => {
    const unread = parseInt(document.getElementById('st-unread').textContent || '0');
    if (unread > 0) {
      toast('Notifications', `${unread} non lue${unread > 1 ? 's' : ''}`, 'info', 3500);
    }
  }, 600);
});
</script>
</body>
</html>