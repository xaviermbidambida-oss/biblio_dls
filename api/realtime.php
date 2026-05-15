<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║   DIGITAL LIBRARY — api/realtime.php v4.0                   ║
 * ║   Données 100% temps réel depuis MySQL                      ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Sécurité ─────────────────────────────────────────────────
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once ABSPATH . 'includes/config.php';
require_once ABSPATH . 'includes/auth.php';

// Vérifier requête AJAX
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit(json_encode(['error' => 'Accès refusé']));
}

// Vérifier session
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Non authentifié']));
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';

// Headers JSON + cache désactivé
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

$type = $_GET['type'] ?? 'stats';

// ── Connexion BD ──────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = DB_HOST ?? 'localhost';
        $db   = DB_NAME ?? 'digital_library';
        $user = DB_USER ?? 'root';
        $pass = DB_PASS ?? '';
        $pdo = new PDO(
            "mysql:host=$host;dbname=$db;charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]
        );
    }
    return $pdo;
}

// ── Helpers ───────────────────────────────────────────────────
function safeQuery(string $sql, array $params = []): array {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[RT] Query error: ' . $e->getMessage());
        return [];
    }
}

function safeQueryOne(string $sql, array $params = []): array {
    $rows = safeQuery($sql, $params);
    return $rows[0] ?? [];
}

function formatFCFA(float $amount): string {
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function timeAgo(string $datetime): string {
    $ts   = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60)       return 'À l\'instant';
    if ($diff < 3600)     return floor($diff / 60) . ' min';
    if ($diff < 86400)    return floor($diff / 3600) . 'h';
    if ($diff < 604800)   return floor($diff / 86400) . 'j';
    return date('d/m/Y', $ts);
}

// ════════════════════════════════════════════════════════════
// HANDLER : stats
// ════════════════════════════════════════════════════════════
function handleStats(int $userId, string $role): array {
    if ($role === 'admin') {
        return handleAdminStats();
    } elseif ($role === 'journaliste') {
        return handleJournalisteStats($userId);
    } else {
        return handleLecteurStats($userId);
    }
}

function handleAdminStats(): array {
    // 1. Total utilisateurs
    $users = safeQueryOne("SELECT COUNT(*) AS total FROM users WHERE statut != 'bloque'");
    
    // 2. Actifs aujourd'hui (dernière connexion dans les 24h)
    $active = safeQueryOne("
        SELECT COUNT(*) AS total FROM users 
        WHERE last_login >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    
    // 3. Total livres disponibles
    $books = safeQueryOne("SELECT COUNT(*) AS total FROM livres WHERE statut = 'disponible'");
    
    // 4. Total ventes confirmées
    $sales = safeQueryOne("SELECT COUNT(*) AS total FROM achats WHERE statut = 'confirme'");
    
    // 5. Revenus du mois courant
    $revenue = safeQueryOne("
        SELECT COALESCE(SUM(montant), 0) AS total 
        FROM achats 
        WHERE statut = 'confirme' 
          AND MONTH(created_at) = MONTH(NOW()) 
          AND YEAR(created_at) = YEAR(NOW())
    ");
    
    // 6. Revenus mois précédent (pour variation)
    $revPrev = safeQueryOne("
        SELECT COALESCE(SUM(montant), 0) AS total 
        FROM achats 
        WHERE statut = 'confirme' 
          AND MONTH(created_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
          AND YEAR(created_at)  = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))
    ");
    
    // 7. Nouvelles inscriptions ce mois
    $newUsers = safeQueryOne("
        SELECT COUNT(*) AS total FROM users 
        WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())
    ");
    
    // 8. Nouveaux livres cette semaine
    $newBooks = safeQueryOne("
        SELECT COUNT(*) AS total FROM livres 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    
    // 9. Total catégories
    $cats = safeQueryOne("SELECT COUNT(*) AS total FROM categories");
    
    // 10. Ventes vs mois précédent (variation %)
    $salesMonth = safeQueryOne("
        SELECT COUNT(*) AS total FROM achats 
        WHERE statut = 'confirme' 
          AND MONTH(created_at) = MONTH(NOW()) 
          AND YEAR(created_at)  = YEAR(NOW())
    ");
    $salesPrev = safeQueryOne("
        SELECT COUNT(*) AS total FROM achats 
        WHERE statut = 'confirme' 
          AND MONTH(created_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
          AND YEAR(created_at)  = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))
    ");
    
    $revCur  = (float)($revenue['total']  ?? 0);
    $revPrev = (float)($revPrev['total']  ?? 0);
    $revVar  = $revPrev > 0 ? round((($revCur - $revPrev) / $revPrev) * 100, 1) : 0;
    
    $salesCur  = (int)($salesMonth['total'] ?? 0);
    $salesPrv  = (int)($salesPrev['total']  ?? 0);
    $salesVar  = $salesPrv > 0 ? round((($salesCur - $salesPrv) / $salesPrv) * 100, 1) : 0;

    return [
        'success'          => true,
        'role'             => 'admin',
        'total_users'      => (int)($users['total']    ?? 0),
        'active_today'     => (int)($active['total']   ?? 0),
        'total_books'      => (int)($books['total']    ?? 0),
        'total_sales'      => (int)($sales['total']    ?? 0),
        'revenue_month'    => formatFCFA($revCur),
        'rev_raw'          => $revCur,
        'rev_variation'    => $revVar,
        'sales_variation'  => $salesVar,
        'new_users_month'  => (int)($newUsers['total'] ?? 0),
        'new_books_week'   => (int)($newBooks['total'] ?? 0),
        'categories'       => (int)($cats['total']     ?? 0),
        'timestamp'        => time(),
    ];
}

function handleJournalisteStats(int $userId): array {
    $total = safeQueryOne("SELECT COUNT(*) AS total FROM livres WHERE ajoute_par = ?", [$userId]);
    $pub   = safeQueryOne("SELECT COUNT(*) AS total FROM livres WHERE ajoute_par = ? AND statut = 'disponible'", [$userId]);
    $arch  = safeQueryOne("SELECT COUNT(*) AS total FROM livres WHERE ajoute_par = ? AND statut = 'archive'", [$userId]);
    $ventes= safeQueryOne("
        SELECT COUNT(*) AS total FROM achats a
        JOIN livres l ON l.id = a.livre_id
        WHERE l.ajoute_par = ? AND a.statut = 'confirme'
    ", [$userId]);

    return [
        'success'   => true,
        'role'      => 'journaliste',
        'total'     => (int)($total['total']  ?? 0),
        'published' => (int)($pub['total']    ?? 0),
        'draft'     => (int)($arch['total']   ?? 0),
        'views'     => (int)($ventes['total'] ?? 0),
        'timestamp' => time(),
    ];
}

function handleLecteurStats(int $userId): array {
    $bought = safeQueryOne("SELECT COUNT(*) AS total, COALESCE(SUM(montant),0) AS spent FROM achats WHERE user_id = ? AND statut = 'confirme'", [$userId]);
    $bonus  = safeQueryOne("SELECT bonus_restant FROM user_bonus WHERE user_id = ?", [$userId]);
    $catalog= safeQueryOne("SELECT COUNT(*) AS total FROM livres WHERE statut = 'disponible'");

    return [
        'success'         => true,
        'role'            => 'lecteur',
        'books_purchased' => (int)($bought['total']    ?? 0),
        'total_spent'     => (float)($bought['spent']  ?? 0),
        'bonus_count'     => (int)($bonus['bonus_restant'] ?? 0),
        'catalog_count'   => (int)($catalog['total']   ?? 0),
        'timestamp'       => time(),
    ];
}

// ════════════════════════════════════════════════════════════
// HANDLER : activity (admin uniquement)
// ════════════════════════════════════════════════════════════
function handleActivity(): array {
    $rows = safeQuery("
        SELECT 
            'achat' AS type,
            CONCAT(u.prenom, ' ', u.nom, ' a acheté « ', l.titre, ' »') AS msg,
            a.created_at,
            a.montant
        FROM achats a
        JOIN users  u ON u.id = a.user_id
        JOIN livres l ON l.id = a.livre_id
        WHERE a.statut = 'confirme'
        
        UNION ALL
        
        SELECT 
            'user' AS type,
            CONCAT('Nouvel utilisateur : ', prenom, ' ', nom, ' (', role, ')') AS msg,
            created_at,
            0 AS montant
        FROM users
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        
        UNION ALL
        
        SELECT 
            'livre' AS type,
            CONCAT('Nouveau livre ajouté : « ', titre, ' »') AS msg,
            created_at,
            0 AS montant
        FROM livres
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        
        ORDER BY created_at DESC
        LIMIT 10
    ");

    $icons  = ['achat' => '💰', 'user' => '👤', 'livre' => '📚'];
    $colors = ['achat' => '#10d9a0', 'user' => '#60c8ff', 'livre' => '#8b5cf6'];

    $activity = array_map(function($r) use ($icons, $colors) {
        return [
            'type'     => $r['type'],
            'msg'      => $r['msg'],
            'time_ago' => timeAgo($r['created_at']),
            'icon'     => $icons[$r['type']] ?? '•',
            'color'    => $colors[$r['type']] ?? '#fff',
            'montant'  => (float)$r['montant'],
        ];
    }, $rows);

    return ['success' => true, 'activity' => $activity];
}

// ════════════════════════════════════════════════════════════
// HANDLER : notifications
// ════════════════════════════════════════════════════════════
function handleNotifications(int $userId, string $role): array {
    // Pour l'admin : toutes les notifs système
    if ($role === 'admin') {
        $rows = safeQuery("
            SELECT 
                n.id, n.type, n.message, n.is_read,
                n.created_at,
                CASE n.type
                    WHEN 'achat'  THEN 'rgba(16,217,160,.08)'
                    WHEN 'user'   THEN 'rgba(96,200,255,.08)'
                    WHEN 'livre'  THEN 'rgba(139,92,246,.08)'
                    ELSE 'rgba(96,200,255,.08)'
                END AS bg
            FROM notifications n
            ORDER BY n.created_at DESC
            LIMIT 15
        ");
    } else {
        $rows = safeQuery("
            SELECT n.id, n.type, n.message, n.is_read, n.created_at,
                   'rgba(96,200,255,.08)' AS bg
            FROM notifications n
            WHERE n.user_id = ? OR n.user_id IS NULL
            ORDER BY n.created_at DESC
            LIMIT 10
        ", [$userId]);
    }

    $unread = 0;
    $notifs = array_map(function($n) use (&$unread) {
        if (!$n['is_read']) $unread++;
        return [
            'id'       => (int)$n['id'],
            'type'     => $n['type'] ?? 'info',
            'message'  => $n['message'],
            'is_read'  => (bool)$n['is_read'],
            'time_ago' => timeAgo($n['created_at']),
            'bg'       => $n['bg'],
        ];
    }, $rows);

    return [
        'success'       => true,
        'notifications' => $notifs,
        'unread_count'  => $unread,
    ];
}

// ════════════════════════════════════════════════════════════
// HANDLER : chart (7 derniers jours, admin)
// ════════════════════════════════════════════════════════════
function handleChart(): array {
    $rows = safeQuery("
        SELECT 
            DATE(created_at) AS jour,
            COUNT(*) AS ventes,
            COALESCE(SUM(montant), 0) AS revenus
        FROM achats
        WHERE statut = 'confirme'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)
        ORDER BY jour ASC
    ");

    // Remplir les jours manquants
    $byDate = [];
    foreach ($rows as $r) {
        $byDate[$r['jour']] = $r;
    }

    $labels_fr = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
    $chart = [];
    for ($i = 6; $i >= 0; $i--) {
        $date    = date('Y-m-d', strtotime("-{$i} day"));
        $dayNum  = (int)date('w', strtotime($date));
        $label   = $labels_fr[$dayNum];
        $chart[] = [
            'label'   => $label,
            'date'    => $date,
            'ventes'  => (int)($byDate[$date]['ventes']  ?? 0),
            'revenus' => (float)($byDate[$date]['revenus'] ?? 0),
        ];
    }

    return ['success' => true, 'chart' => $chart];
}

// ════════════════════════════════════════════════════════════
// HANDLER : users table refresh (admin)
// ════════════════════════════════════════════════════════════
function handleUsers(): array {
    $rows = safeQuery("
        SELECT 
            u.id, u.nom, u.prenom, u.email, u.role, u.statut,
            u.created_at,
            COUNT(a.id) AS nb_achats
        FROM users u
        LEFT JOIN achats a ON a.user_id = u.id AND a.statut = 'confirme'
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT 10
    ");

    return ['success' => true, 'users' => $rows];
}

// ════════════════════════════════════════════════════════════
// ROUTER
// ════════════════════════════════════════════════════════════
try {
    switch ($type) {
        case 'stats':
            echo json_encode(handleStats($userId, $userRole));
            break;

        case 'activity':
            if ($userRole !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Accès refusé']);
                break;
            }
            echo json_encode(handleActivity());
            break;

        case 'notifications':
            echo json_encode(handleNotifications($userId, $userRole));
            break;

        case 'chart':
            if ($userRole !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Accès refusé']);
                break;
            }
            echo json_encode(handleChart());
            break;

        case 'users':
            if ($userRole !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Accès refusé']);
                break;
            }
            echo json_encode(handleUsers());
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Type inconnu: ' . htmlspecialchars($type)]);
    }
} catch (Throwable $e) {
    error_log('[RT] Fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur interne']);
}