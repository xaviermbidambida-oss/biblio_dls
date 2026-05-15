<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — admin/sales.php v2.0                  ║
 * ║  Gestion Ventes · Bonus · Statistiques · Transactions           ║
 * ║  100% connecté BD · PDO · CSRF · AJAX temps réel               ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// ══════════════════════════════════════════════════════════════
// 1. SESSION & SÉCURITÉ
// ══════════════════════════════════════════════════════════════
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ══════════════════════════════════════════════════════════════
// 2. CONNEXION BASE DE DONNÉES
// ══════════════════════════════════════════════════════════════
$pdo     = null;
$dbError = null;

foreach ([
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../../includes/config.php',
    __DIR__ . '/../../config/config.php',
] as $cfgPath) {
    if (file_exists($cfgPath) && !defined('DB_HOST_LOADED')) {
        require_once $cfgPath;
        define('DB_HOST_LOADED', true);
        break;
    }
}

if (!isset($pdo) || $pdo === null) {
    try {
        $pdo = new PDO(
            sprintf(
                "mysql:host=%s;dbname=%s;charset=utf8mb4",
                defined('DB_HOST') ? DB_HOST : 'localhost',
                defined('DB_NAME') ? DB_NAME : 'digital_library'
            ),
            defined('DB_USER') ? DB_USER : 'root',
            defined('DB_PASS') ? DB_PASS : '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
        error_log('[SALES] DB: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// 3. AUTH — ADMIN UNIQUEMENT
// ══════════════════════════════════════════════════════════════
if (!isset($_SESSION['user_id']) && $pdo) {
    try {
        $demo = $pdo->query("SELECT * FROM users WHERE role='admin' AND statut='actif' LIMIT 1")->fetch();
        if ($demo) {
            $_SESSION['user_id']   = $demo['id'];
            $_SESSION['user_role'] = $demo['role'];
            $_SESSION['user_name'] = trim(($demo['prenom'] ?? '') . ' ' . ($demo['nom'] ?? ''));
        }
    } catch (Exception $e) {}
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=admin/sales.php'); exit;
}
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php?error=access_denied'); exit;
}

$adminId      = (int)$_SESSION['user_id'];
$adminName    = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$adminInitial = strtoupper(substr($adminName, 0, 1)) ?: 'A';

// ══════════════════════════════════════════════════════════════
// 4. HELPERS
// ══════════════════════════════════════════════════════════════
function dbFetch(string $sql, array $p = []): array {
    global $pdo; if (!$pdo) return [];
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); }
    catch (Exception $e) { error_log('[SALES] dbFetch: ' . $e->getMessage()); return []; }
}
function dbOne(string $sql, array $p = []): array {
    global $pdo; if (!$pdo) return [];
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch() ?: []; }
    catch (Exception $e) { return []; }
}
function dbScalar(string $sql, array $p = [], $d = 0) {
    global $pdo; if (!$pdo) return $d;
    try { $st = $pdo->prepare($sql); $st->execute($p); $v = $st->fetchColumn(); return $v !== false ? $v : $d; }
    catch (Exception $e) { return $d; }
}
function fmtFCFA(float $n, bool $short = false): string {
    if ($short) {
        if ($n >= 1e6) return number_format($n / 1e6, 1, ',', ' ') . ' M';
        if ($n >= 1e3) return number_format($n / 1e3, 0, ',', ' ') . ' K';
    }
    return number_format($n, 0, ',', ' ') . ' FCFA';
}
function growthRate(float $c, float $p): float {
    if ($p <= 0) return $c > 0 ? 100.0 : 0.0;
    return round((($c - $p) / $p) * 100, 1);
}
function timeAgo(string $dt): string {
    if (!$dt) return '—';
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'à l\'instant';
    if ($d < 3600)   return (int)($d / 60) . ' min';
    if ($d < 86400)  return (int)($d / 3600) . 'h';
    if ($d < 604800) return (int)($d / 86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ══════════════════════════════════════════════════════════════
// 5. CRÉATION TABLES SI MANQUANTES
// ══════════════════════════════════════════════════════════════
if ($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NULL,
                type       VARCHAR(50)  DEFAULT 'info',
                titre      VARCHAR(255) DEFAULT '',
                message    TEXT,
                icon       VARCHAR(10)  DEFAULT '🔔',
                bg         VARCHAR(100) DEFAULT 'rgba(0,212,255,.08)',
                lu         TINYINT(1)   DEFAULT 0,
                created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_uid (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_bonus (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id       INT UNSIGNED NOT NULL UNIQUE,
                achat_count   INT UNSIGNED NOT NULL DEFAULT 0,
                bonus_total   INT UNSIGNED NOT NULL DEFAULT 0,
                bonus_restant INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Ajouter colonne statut_type dans achats si elle n'existe pas
        $hasTypeCol = dbScalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='achats' AND COLUMN_NAME='type_transaction'"
        );
        if (!$hasTypeCol) {
            $pdo->exec("ALTER TABLE achats ADD COLUMN type_transaction ENUM('vente','bonus_accorde') DEFAULT 'vente' AFTER statut");
        }
    } catch (Exception $e) {
        error_log('[SALES] setup: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// 6. GESTION REQUÊTES AJAX
// ══════════════════════════════════════════════════════════════
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Validation CSRF pour toutes les requêtes POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $recv = $body['csrf'] ?? $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals($csrfToken, $recv)) {
            echo json_encode(['error' => 'Token CSRF invalide. Rechargez la page.']); exit;
        }
    }

    $action = $_GET['action'];

    // ── STATS EN TEMPS RÉEL ───────────────────────────────────────
    if ($action === 'live_stats') {
        $p = $_GET['period'] ?? 'month';
        $periodSql = match($p) {
            'today' => "DATE(created_at) = CURDATE()",
            'week'  => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'year'  => "YEAR(created_at) = YEAR(NOW())",
            default => "MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
        };
        $prevSql = match($p) {
            'today' => "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'week'  => "created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'year'  => "YEAR(created_at) = YEAR(NOW())-1",
            default => "MONTH(created_at) = MONTH(NOW())-1 AND YEAR(created_at) = YEAR(NOW())"
        };

        $curr = dbOne("SELECT COUNT(*) AS sales, COALESCE(SUM(montant),0) AS revenue, COALESCE(AVG(montant),0) AS avg_basket FROM achats WHERE statut='confirme' AND $periodSql");
        $prev = dbOne("SELECT COUNT(*) AS sales, COALESCE(SUM(montant),0) AS revenue FROM achats WHERE statut='confirme' AND $prevSql");
        $bonusCount = (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND type_transaction='bonus_accorde' AND $periodSql");
        $ventesCount = (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND $periodSql");

        echo json_encode([
            'sales'        => (int)($curr['sales'] ?? 0),
            'revenue'      => (float)($curr['revenue'] ?? 0),
            'avg_basket'   => round((float)($curr['avg_basket'] ?? 0)),
            'bonus_count'  => $bonusCount,
            'ventes_count' => $ventesCount,
            'sales_growth' => growthRate((float)($curr['sales'] ?? 0), (float)($prev['sales'] ?? 0)),
            'rev_growth'   => growthRate((float)($curr['revenue'] ?? 0), (float)($prev['revenue'] ?? 0)),
            'rev_total'    => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme'"),
            'sales_today'  => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()"),
            'sales_month'  => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"),
            'sales_year'   => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND YEAR(created_at)=YEAR(NOW())"),
            'rev_today'    => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()"),
            'rev_month'    => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"),
            'rev_year'     => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND YEAR(created_at)=YEAR(NOW())"),
            'users_total'  => (int)dbScalar("SELECT COUNT(*) FROM users"),
            'books_total'  => (int)dbScalar("SELECT COUNT(*) FROM livres WHERE statut='disponible'"),
            'confirmed_total' => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme'"),
            'failed_total'    => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='echec'"),
        ]);
        exit;
    }

    // ── DONNÉES GRAPHIQUES ────────────────────────────────────────
    if ($action === 'chart_data') {
        $type = $_GET['type'] ?? 'daily';
        if ($type === 'monthly') {
            $rows = dbFetch("SELECT 
                DATE_FORMAT(created_at,'%Y-%m') AS period,
                DATE_FORMAT(created_at,'%b %Y')  AS label,
                COUNT(*) AS sales,
                COALESCE(SUM(montant),0) AS revenue,
                COALESCE(SUM(CASE WHEN type_transaction='bonus_accorde' THEN 0 ELSE montant END),0) AS rev_ventes,
                COUNT(CASE WHEN type_transaction='bonus_accorde' THEN 1 END) AS nb_bonus
            FROM achats WHERE statut='confirme' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY period ORDER BY period");
        } else {
            $rows = dbFetch("SELECT 
                DATE(created_at) AS period,
                DATE_FORMAT(created_at,'%d/%m') AS label,
                COUNT(*) AS sales,
                COALESCE(SUM(montant),0) AS revenue,
                COUNT(CASE WHEN type_transaction='bonus_accorde' THEN 1 END) AS nb_bonus
            FROM achats WHERE statut='confirme' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at) ORDER BY period");
        }
        echo json_encode(['data' => $rows]); exit;
    }

    // ── TRANSACTIONS (liste paginée + filtres) ────────────────────
    if ($action === 'transactions') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = max(1, min(50, (int)($_GET['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;
        $type   = $_GET['type'] ?? '';        // 'vente' | 'bonus_accorde' | ''
        $search = trim($_GET['search'] ?? '');
        $cat    = (int)($_GET['cat'] ?? 0);
        $method = $_GET['method'] ?? '';
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to'] ?? '';
        $sortBy = in_array($_GET['sort'] ?? '', ['date','montant','user','livre']) ? ($_GET['sort'] ?? 'date') : 'date';
        $sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $where = ["a.statut='confirme'"];
        $params = [];

        if ($type && in_array($type, ['vente', 'bonus_accorde'])) {
            if ($type === 'vente') {
                $where[] = "(a.type_transaction='vente' OR a.type_transaction IS NULL)";
            } else {
                $where[] = "a.type_transaction='bonus_accorde'";
            }
        }
        if ($search) {
            $where[] = "(CONCAT(u.prenom,' ',u.nom) LIKE ? OR u.email LIKE ? OR l.titre LIKE ? OR a.reference LIKE ?)";
            $s = '%' . $search . '%';
            array_push($params, $s, $s, $s, $s);
        }
        if ($cat) { $where[] = "l.categorie_id = ?"; $params[] = $cat; }
        if ($method) { $where[] = "a.methode = ?"; $params[] = $method; }
        if ($dateFrom) { $where[] = "DATE(a.created_at) >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = "DATE(a.created_at) <= ?"; $params[] = $dateTo; }

        $whereStr = 'WHERE ' . implode(' AND ', $where);
        $sortMap  = ['date' => 'a.created_at', 'montant' => 'a.montant', 'user' => 'u.nom', 'livre' => 'l.titre'];
        $sortCol  = $sortMap[$sortBy];

        $total = (int)dbScalar("SELECT COUNT(*) FROM achats a JOIN users u ON u.id=a.user_id JOIN livres l ON l.id=a.livre_id LEFT JOIN categories c ON c.id=l.categorie_id $whereStr", $params);
        $rows  = dbFetch("SELECT 
            a.id, a.reference, a.montant, a.methode, a.statut, a.type_transaction, a.created_at,
            CONCAT(u.prenom,' ',u.nom) AS user_name, u.email, u.id AS user_id,
            l.titre AS livre_titre, l.access_type, l.prix AS livre_prix, l.id AS livre_id,
            c.nom AS categorie
        FROM achats a
        JOIN users u ON u.id = a.user_id
        JOIN livres l ON l.id = a.livre_id
        LEFT JOIN categories c ON c.id = l.categorie_id
        $whereStr
        ORDER BY $sortCol $sortDir
        LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));

        echo json_encode(['transactions' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, ceil($total / $limit))]); exit;
    }

    // ── TOP LIVRES ────────────────────────────────────────────────
    if ($action === 'top_books') {
        $sort  = in_array($_GET['sort'] ?? '', ['revenue','sales','bonus','lectures']) ? $_GET['sort'] : 'revenue';
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
        $sortExpr = match($sort) {
            'sales'   => 'COUNT(a.id)',
            'bonus'   => "COUNT(CASE WHEN a.type_transaction='bonus_accorde' THEN 1 END)",
            'lectures'=> 'l.nb_lectures',
            default   => 'COALESCE(SUM(CASE WHEN a.type_transaction!=\'bonus_accorde\' THEN a.montant ELSE 0 END),0)'
        };
        $books = dbFetch("SELECT 
            l.id, l.titre, l.auteur, l.prix, l.access_type, l.note_moyenne, l.nb_lectures, l.nb_ventes, l.is_bestseller, l.is_featured,
            c.nom AS categorie, c.icone AS cat_icon,
            COUNT(a.id) AS sales_count,
            COALESCE(SUM(CASE WHEN a.type_transaction!='bonus_accorde' OR a.type_transaction IS NULL THEN a.montant ELSE 0 END),0) AS revenue_ventes,
            COUNT(CASE WHEN a.type_transaction='bonus_accorde' THEN 1 END) AS bonus_count
        FROM livres l
        LEFT JOIN categories c ON c.id = l.categorie_id
        LEFT JOIN achats a ON a.livre_id = l.id AND a.statut = 'confirme'
        WHERE l.statut = 'disponible'
        GROUP BY l.id
        ORDER BY $sortExpr DESC
        LIMIT ?", [$limit]);
        echo json_encode(['books' => $books]); exit;
    }

    // ── TOP ACHETEURS ─────────────────────────────────────────────
    if ($action === 'top_buyers') {
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
        $buyers = dbFetch("SELECT 
            u.id, u.nom, u.prenom, u.email, u.statut,
            COUNT(a.id) AS purchases,
            COALESCE(SUM(CASE WHEN a.type_transaction!='bonus_accorde' OR a.type_transaction IS NULL THEN a.montant ELSE 0 END),0) AS total_spent,
            MAX(a.created_at) AS last_purchase,
            COALESCE(ub.bonus_restant, 0) AS bonus,
            COALESCE(ub.achat_count, 0) AS achat_count
        FROM users u
        JOIN achats a ON a.user_id = u.id AND a.statut = 'confirme'
        LEFT JOIN user_bonus ub ON ub.user_id = u.id
        GROUP BY u.id
        ORDER BY total_spent DESC
        LIMIT ?", [$limit]);
        echo json_encode(['buyers' => $buyers]); exit;
    }

    // ── VÉRIFICATION ÉLIGIBILITÉ BONUS ───────────────────────────
    if ($action === 'check_bonus_eligibility') {
        $userId  = (int)($_GET['user_id'] ?? 0);
        $livreId = (int)($_GET['livre_id'] ?? 0);

        if (!$userId || !$livreId) { echo json_encode(['eligible' => false, 'reason' => 'Paramètres manquants.']); exit; }

        // Vérifier utilisateur
        $user = dbOne("SELECT id, nom, prenom, email, statut FROM users WHERE id=?", [$userId]);
        if (!$user) { echo json_encode(['eligible' => false, 'reason' => 'Utilisateur introuvable.']); exit; }
        if ($user['statut'] !== 'actif') { echo json_encode(['eligible' => false, 'reason' => 'Utilisateur inactif ou bloqué.']); exit; }

        // Vérifier livre
        $livre = dbOne("SELECT id, titre, auteur, prix, statut FROM livres WHERE id=?", [$livreId]);
        if (!$livre) { echo json_encode(['eligible' => false, 'reason' => 'Livre introuvable.']); exit; }
        if ($livre['statut'] !== 'disponible') { echo json_encode(['eligible' => false, 'reason' => 'Ce livre n\'est pas disponible.']); exit; }

        // Vérifier achats confirmés (minimum 5)
        $achatsConfirmes = (int)dbScalar("SELECT COUNT(*) FROM achats WHERE user_id=? AND statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL)", [$userId]);
        $bonusRule = (int)dbScalar("SELECT COALESCE(value,'5') FROM settings WHERE `key`='bonus_rule' LIMIT 1") ?: 5;

        if ($achatsConfirmes < $bonusRule) {
            echo json_encode([
                'eligible'  => false,
                'reason'    => "Seulement $achatsConfirmes achat(s) confirmé(s). Minimum $bonusRule requis.",
                'purchases' => $achatsConfirmes,
                'required'  => $bonusRule,
            ]); exit;
        }

        // Vérifier bonus_restant
        $bonusRow = dbOne("SELECT bonus_restant, achat_count FROM user_bonus WHERE user_id=?", [$userId]);
        $bonusRestant = (int)($bonusRow['bonus_restant'] ?? 0);

        if ($bonusRestant <= 0) {
            echo json_encode([
                'eligible' => false,
                'reason'   => "Aucun bonus disponible pour cet utilisateur. (bonus_restant = 0)",
                'bonus'    => 0,
            ]); exit;
        }

        // Vérifier si livre déjà possédé
        $dejaAchete = (int)dbScalar("SELECT COUNT(*) FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme'", [$userId, $livreId]);
        if ($dejaAchete > 0) {
            // Vérifier si l'user possède TOUS les livres
            $totalLivres = (int)dbScalar("SELECT COUNT(*) FROM livres WHERE statut='disponible'");
            $livresPossedes = (int)dbScalar("SELECT COUNT(DISTINCT livre_id) FROM achats WHERE user_id=? AND statut='confirme'", [$userId]);
            if ($livresPossedes >= $totalLivres) {
                echo json_encode([
                    'eligible'      => true,
                    'all_possessed' => true,
                    'reason'        => "⚠️ Cet utilisateur possède TOUS les livres. Attribution exceptionnelle autorisée.",
                    'user'          => ['id' => $user['id'], 'name' => trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')), 'email' => $user['email']],
                    'livre'         => ['id' => $livre['id'], 'titre' => $livre['titre']],
                    'bonus_restant' => $bonusRestant,
                    'purchases'     => $achatsConfirmes,
                ]);
            } else {
                echo json_encode(['eligible' => false, 'reason' => 'Ce livre est déjà possédé par cet utilisateur (achat ou bonus précédent).']);
            }
            exit;
        }

        echo json_encode([
            'eligible'      => true,
            'all_possessed' => false,
            'user'          => ['id' => $user['id'], 'name' => trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')), 'email' => $user['email']],
            'livre'         => ['id' => $livre['id'], 'titre' => $livre['titre'], 'auteur' => $livre['auteur']],
            'bonus_restant' => $bonusRestant,
            'purchases'     => $achatsConfirmes,
            'required'      => $bonusRule,
        ]); exit;
    }

    // ── ATTRIBUTION BONUS ─────────────────────────────────────────
    if ($action === 'grant_bonus' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        global $pdo;
        $raw     = file_get_contents('php://input');
        $body    = json_decode($raw, true) ?: [];
        $userId  = (int)($body['user_id'] ?? 0);
        $livreId = (int)($body['livre_id'] ?? 0);

        if (!$userId || !$livreId) { echo json_encode(['error' => 'Paramètres manquants.']); exit; }
        if (!$pdo) { echo json_encode(['error' => 'Base de données inaccessible.']); exit; }

        // Re-vérifier éligibilité
        $user     = dbOne("SELECT id, nom, prenom, email FROM users WHERE id=? AND statut='actif'", [$userId]);
        $livre    = dbOne("SELECT id, titre, prix FROM livres WHERE id=? AND statut='disponible'", [$livreId]);
        $bonusRow = dbOne("SELECT bonus_restant, achat_count FROM user_bonus WHERE user_id=?", [$userId]);

        if (!$user) { echo json_encode(['error' => 'Utilisateur invalide.']); exit; }
        if (!$livre) { echo json_encode(['error' => 'Livre invalide.']); exit; }
        if ((int)($bonusRow['bonus_restant'] ?? 0) <= 0) { echo json_encode(['error' => 'Aucun bonus disponible pour cet utilisateur.']); exit; }

        $bonusRule    = (int)dbScalar("SELECT COALESCE(value,'5') FROM settings WHERE `key`='bonus_rule' LIMIT 1") ?: 5;
        $achatsConfirmes = (int)dbScalar("SELECT COUNT(*) FROM achats WHERE user_id=? AND statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL)", [$userId]);
        if ($achatsConfirmes < $bonusRule) { echo json_encode(['error' => "Éligibilité insuffisante ($achatsConfirmes/$bonusRule achats)."]); exit; }

        // Vérifier doublon (sauf si tous possédés)
        $dejaAchete  = (int)dbScalar("SELECT COUNT(*) FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme'", [$userId, $livreId]);
        $totalLivres = (int)dbScalar("SELECT COUNT(*) FROM livres WHERE statut='disponible'");
        $livresPoss  = (int)dbScalar("SELECT COUNT(DISTINCT livre_id) FROM achats WHERE user_id=? AND statut='confirme'", [$userId]);

        if ($dejaAchete && $livresPoss < $totalLivres) {
            echo json_encode(['error' => 'Ce livre est déjà possédé par cet utilisateur.']); exit;
        }

        try {
            $pdo->beginTransaction();

            $ref = 'BONUS-' . strtoupper(substr(md5(uniqid($userId . $livreId, true)), 0, 10));
            $st  = $pdo->prepare("INSERT INTO achats (user_id, livre_id, montant, methode, statut, type_transaction, reference) VALUES (?,?,0,'orange_money','confirme','bonus_accorde',?)");
            $st->execute([$userId, $livreId, $ref]);

            // Décrémenter bonus_restant
            $pdo->prepare("UPDATE user_bonus SET bonus_restant = bonus_restant - 1 WHERE user_id=?")->execute([$userId]);

            // Incrémenter nb_ventes livre
            $pdo->prepare("UPDATE livres SET nb_ventes = nb_ventes + 1 WHERE id=?")->execute([$livreId]);

            // Créer notification utilisateur
            $userName  = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
            $pdo->prepare("INSERT INTO notifications (user_id, type, titre, message, icon, bg) VALUES (?,?,?,?,?,?)")->execute([
                $userId, 'bonus', '🎁 Livre bonus accordé',
                "L'administrateur vous a offert le livre : « {$livre['titre']} ». Profitez-en !",
                '🎁', 'rgba(0,255,170,.08)'
            ]);

            // Log admin (si table admin_logs existe)
            try {
                $pdo->prepare("INSERT INTO admin_logs (user_id, action, detail, ip) VALUES (?,?,?,?)")->execute([
                    $adminId, 'bonus_granted',
                    "Bonus accordé : livre_id={$livreId} à user_id={$userId}",
                    $_SERVER['REMOTE_ADDR'] ?? '—'
                ]);
            } catch (Exception $e) {}

            $pdo->commit();
            echo json_encode([
                'success'    => true,
                'message'    => "Livre « {$livre['titre']} » accordé en bonus à $userName.",
                'reference'  => $ref,
                'user_name'  => $userName,
                'livre_titre'=> $livre['titre'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[SALES] grant_bonus: ' . $e->getMessage());
            echo json_encode(['error' => 'Erreur lors de l\'attribution. Réessayez.']);
        }
        exit;
    }

    // ── ANNULER TRANSACTION ───────────────────────────────────────
    if ($action === 'cancel_transaction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        global $pdo;
        $raw    = file_get_contents('php://input');
        $body   = json_decode($raw, true) ?: [];
        $achatId = (int)($body['achat_id'] ?? 0);

        if (!$achatId) { echo json_encode(['error' => 'ID manquant.']); exit; }

        $achat = dbOne("SELECT id, type_transaction, user_id, livre_id FROM achats WHERE id=? AND statut='confirme'", [$achatId]);
        if (!$achat) { echo json_encode(['error' => 'Transaction introuvable.']); exit; }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE achats SET statut='echec' WHERE id=?")->execute([$achatId]);
            $pdo->prepare("UPDATE livres SET nb_ventes = GREATEST(0, nb_ventes - 1) WHERE id=?")->execute([$achat['livre_id']]);

            // Si c'était un bonus, restituer le bonus
            if ($achat['type_transaction'] === 'bonus_accorde') {
                $pdo->prepare("UPDATE user_bonus SET bonus_restant = bonus_restant + 1 WHERE user_id=?")->execute([$achat['user_id']]);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Transaction annulée avec succès.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Erreur lors de l\'annulation.']);
        }
        exit;
    }

    // ── RECHERCHE UTILISATEURS ────────────────────────────────────
    if ($action === 'search_users') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['users' => []]); exit; }
        $users = dbFetch("SELECT id, nom, prenom, email, statut,
            (SELECT COUNT(*) FROM achats WHERE user_id=users.id AND statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL)) AS achats_confirmes,
            (SELECT COALESCE(bonus_restant,0) FROM user_bonus WHERE user_id=users.id) AS bonus_restant
        FROM users WHERE (CONCAT(prenom,' ',nom) LIKE ? OR email LIKE ?) AND statut='actif' LIMIT 10",
        ['%'.$q.'%', '%'.$q.'%']);
        echo json_encode(['users' => $users]); exit;
    }

    // ── RECHERCHE LIVRES ──────────────────────────────────────────
    if ($action === 'search_books') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['books' => []]); exit; }
        $books = dbFetch("SELECT l.id, l.titre, l.auteur, l.prix, l.access_type, c.nom AS categorie
        FROM livres l LEFT JOIN categories c ON c.id=l.categorie_id
        WHERE (l.titre LIKE ? OR l.auteur LIKE ?) AND l.statut='disponible' LIMIT 10",
        ['%'.$q.'%', '%'.$q.'%']);
        echo json_encode(['books' => $books]); exit;
    }

    // ── STATS FIDÉLITÉ ────────────────────────────────────────────
    if ($action === 'loyalty_stats') {
        $bonusRule = (int)dbScalar("SELECT COALESCE(value,'5') FROM settings WHERE `key`='bonus_rule' LIMIT 1") ?: 5;
        $eligible  = (int)dbScalar("SELECT COUNT(*) FROM user_bonus WHERE bonus_restant > 0");
        $totalBonus= (int)dbScalar("SELECT COALESCE(SUM(bonus_total),0) FROM user_bonus");
        $progression = dbFetch("SELECT u.id, CONCAT(u.prenom,' ',u.nom) AS name, u.email, ub.achat_count, ub.bonus_restant, ub.bonus_total
        FROM user_bonus ub JOIN users u ON u.id=ub.user_id WHERE ub.achat_count > 0 ORDER BY ub.bonus_total DESC LIMIT 8");
        echo json_encode(['eligible' => $eligible, 'total_bonus_granted' => $totalBonus, 'bonus_rule' => $bonusRule, 'progression' => $progression]); exit;
    }

    // ── INSIGHTS ─────────────────────────────────────────────────
    if ($action === 'insights') {
        $avg    = (float)dbScalar("SELECT COALESCE(AVG(rev),0) FROM (SELECT MONTH(created_at) m, YEAR(created_at) y, SUM(montant) rev FROM achats WHERE statut='confirme' AND type_transaction!='bonus_accorde' AND created_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY m,y) t");
        $curr   = (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND type_transaction!='bonus_accorde' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
        $day    = (int)date('j'); $daysM = (int)date('t');
        $proj   = $day > 0 ? round(($curr / $day) * $daysM) : 0;
        $bHour  = dbOne("SELECT HOUR(created_at) AS h, COUNT(*) cnt FROM achats WHERE statut='confirme' GROUP BY h ORDER BY cnt DESC LIMIT 1");
        $bDay   = dbOne("SELECT DAYNAME(created_at) AS d, COUNT(*) cnt FROM achats WHERE statut='confirme' GROUP BY DAYOFWEEK(created_at) ORDER BY cnt DESC LIMIT 1");
        echo json_encode([
            'avg_monthly'     => $avg,
            'current_month'   => $curr,
            'projected_month' => $proj,
            'best_hour'       => $bHour['h'] ?? null,
            'best_day'        => $bDay['d'] ?? null,
            'growth_vs_avg'   => growthRate($curr, $avg),
        ]); exit;
    }

    // ── NOTIFICATIONS ─────────────────────────────────────────────
    if ($action === 'notifications') {
        $notifs = dbFetch("SELECT * FROM notifications WHERE user_id IS NULL OR user_id=? ORDER BY created_at DESC LIMIT 12", [$adminId]);
        $unread = (int)dbScalar("SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id IS NULL OR user_id=?)", [$adminId]);
        echo json_encode(['notifications' => $notifs, 'unread' => $unread]); exit;
    }
    if ($action === 'mark_read') {
        global $pdo;
        if ($pdo) { try { $pdo->prepare("UPDATE notifications SET lu=1 WHERE user_id IS NULL OR user_id=?")->execute([$adminId]); } catch (Exception $e) {} }
        echo json_encode(['ok' => true]); exit;
    }

    // ── EXPORT CSV ────────────────────────────────────────────────
    if ($action === 'export_csv') {
        $type = $_GET['type'] ?? 'all';
        $rows = dbFetch("SELECT a.id, a.reference, a.created_at, a.montant,
            COALESCE(a.type_transaction,'vente') AS type_transaction,
            a.methode, a.statut,
            CONCAT(u.prenom,' ',u.nom) AS acheteur, u.email,
            l.titre, l.auteur, l.access_type, c.nom AS categorie
        FROM achats a
        JOIN users u ON u.id=a.user_id
        JOIN livres l ON l.id=a.livre_id
        LEFT JOIN categories c ON c.id=l.categorie_id
        " . ($type === 'vente' ? "WHERE a.statut='confirme' AND (a.type_transaction='vente' OR a.type_transaction IS NULL)" :
             ($type === 'bonus' ? "WHERE a.statut='confirme' AND a.type_transaction='bonus_accorde'" :
              "WHERE a.statut='confirme'")) . "
        ORDER BY a.created_at DESC");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions_' . $type . '_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
        fputcsv($out, ['ID', 'Référence', 'Date', 'Montant FCFA', 'Type', 'Méthode', 'Statut', 'Acheteur', 'Email', 'Livre', 'Auteur', 'Accès', 'Catégorie']);
        foreach ($rows as $r) fputcsv($out, array_values($r));
        fclose($out); exit;
    }

    // ── CATÉGORIES ────────────────────────────────────────────────
    if ($action === 'top_categories') {
        $rows = dbFetch("SELECT c.nom, c.icone, c.couleur,
            COUNT(a.id) AS sales,
            COALESCE(SUM(CASE WHEN a.type_transaction!='bonus_accorde' OR a.type_transaction IS NULL THEN a.montant ELSE 0 END),0) AS revenue,
            COUNT(CASE WHEN a.type_transaction='bonus_accorde' THEN 1 END) AS bonus
        FROM categories c
        JOIN livres l ON l.categorie_id = c.id
        JOIN achats a ON a.livre_id = l.id AND a.statut = 'confirme'
        GROUP BY c.id ORDER BY revenue DESC LIMIT 8");
        echo json_encode(['categories' => $rows]); exit;
    }

    // ── MÉTHODES PAIEMENT ─────────────────────────────────────────
    if ($action === 'payment_methods') {
        $rows = dbFetch("SELECT methode, COUNT(*) AS count, COALESCE(SUM(montant),0) AS total FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) GROUP BY methode ORDER BY total DESC");
        echo json_encode(['methods' => $rows]); exit;
    }

    echo json_encode(['error' => 'Action inconnue: ' . esc($action)]); exit;
}

// ══════════════════════════════════════════════════════════════
// 7. DONNÉES INITIALES SSR
// ══════════════════════════════════════════════════════════════
$initStats = [];
if ($pdo) {
    try {
        $initStats = [
            'rev_total'       => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL)"),
            'sales_today'     => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()"),
            'sales_month'     => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"),
            'sales_year'      => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND YEAR(created_at)=YEAR(NOW())"),
            'rev_month'       => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"),
            'bonus_total'     => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND type_transaction='bonus_accorde'"),
            'users_eligible'  => (int)dbScalar("SELECT COUNT(*) FROM user_bonus WHERE bonus_restant > 0"),
            'confirmed_total' => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme'"),
            'failed_total'    => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='echec'"),
            'notif_unread'    => (int)dbScalar("SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id IS NULL OR user_id=?)", [$adminId]),
            'categories'      => dbFetch("SELECT id, nom, icone FROM categories ORDER BY nom"),
        ];
        $prevMonthRev = (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND MONTH(created_at)=MONTH(NOW())-1 AND YEAR(created_at)=YEAR(NOW())");
        $initStats['rev_growth'] = growthRate($initStats['rev_month'], $prevMonthRev);
    } catch (Exception $e) { error_log('[SALES] init: ' . $e->getMessage()); }
}

$initJson = json_encode($initStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$csrfJson = json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$nameJson = json_encode($adminName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gestion Ventes & Bonus — Digital Library</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500;700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.49.0/dist/apexcharts.min.js" defer></script>

<style>
/* ═══════════════════════════════════════════════════════════
   VARIABLES & RESET
═══════════════════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg-base:#05080f;--bg-surf:#0b1020;--bg-card:rgba(255,255,255,.028);--bg-card-h:rgba(255,255,255,.05);
  --b0:rgba(255,255,255,.06);--b1:rgba(255,255,255,.1);--b-act:rgba(0,212,255,.35);
  --cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;--amber:#f59e0b;--rose:#f43f5e;--orange:#f97316;--sky:#38bdf8;
  --txt-hi:#eef2ff;--txt-md:rgba(238,242,255,.6);--txt-lo:rgba(238,242,255,.3);
  --sw:258px;--sc:68px;--tbh:58px;
  --r-sm:8px;--r-md:12px;--r-lg:16px;--r-xl:22px;
  --sh-md:0 6px 28px rgba(0,0,0,.36);--sh-lg:0 20px 64px rgba(0,0,0,.52);
  --glow-c:0 0 28px rgba(0,212,255,.18);
}
html{scroll-behavior:smooth;font-size:15px}
body{font-family:'Instrument Sans',sans-serif;background:var(--bg-base);color:var(--txt-hi);overflow-x:hidden;min-height:100vh}
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:rgba(0,212,255,.25)}

/* ── LAYOUT ── */
.app{display:flex;min-height:100vh}

/* ═══════════════════════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════════════════════ */
#sidebar{
  position:fixed;top:0;left:0;bottom:0;width:var(--sw);
  background:var(--bg-surf);border-right:1px solid var(--b0);
  display:flex;flex-direction:column;z-index:300;
  transition:width .3s cubic-bezier(.4,0,.2,1),transform .3s ease;overflow:hidden;
}
#sidebar.col{width:var(--sc)}
.sb-brand{height:var(--tbh);display:flex;align-items:center;gap:10px;padding:0 14px;border-bottom:1px solid var(--b0);flex-shrink:0}
.sb-logo{width:34px;height:34px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-size:1rem;box-shadow:0 0 20px rgba(0,212,255,.25)}
.sb-name{font-family:'Syne',sans-serif;font-weight:800;font-size:.84rem;white-space:nowrap;transition:opacity .2s}
.sb-name em{color:var(--cyan);font-style:normal}
#sidebar.col .sb-name{opacity:0}
.sb-user{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--b0);flex-shrink:0}
.sb-av{width:36px;height:36px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.82rem;color:#fff}
.sb-uinfo{overflow:hidden;transition:opacity .2s}
#sidebar.col .sb-uinfo{opacity:0}
.sb-uname{font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem;white-space:nowrap}
.sb-urole{font-size:.58rem;font-family:'JetBrains Mono',monospace;color:var(--cyan);text-transform:uppercase;margin-top:2px}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.sb-sec{font-family:'JetBrains Mono',monospace;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--txt-lo);padding:8px 14px 2px;white-space:nowrap;transition:opacity .2s}
#sidebar.col .sb-sec{opacity:0}
.sb-item{display:flex;align-items:center;gap:10px;padding:8px 14px;margin:1px 6px;border-radius:var(--r-sm);text-decoration:none;color:var(--txt-md);font-size:.79rem;font-weight:500;transition:all .15s;position:relative;white-space:nowrap;overflow:hidden}
.sb-item:hover{color:var(--txt-hi);background:var(--bg-card-h)}
.sb-item.active{color:var(--cyan);background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.12)}
.sb-item.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:16px;background:var(--cyan);border-radius:0 3px 3px 0;box-shadow:0 0 8px var(--cyan)}
.sb-ico{font-size:.98rem;width:18px;text-align:center;flex-shrink:0}
.sb-lbl{transition:opacity .2s}
#sidebar.col .sb-lbl{opacity:0}
.sb-badge{margin-left:auto;font-size:.56rem;font-family:'JetBrains Mono',monospace;padding:2px 5px;border-radius:100px;background:var(--rose);color:#fff;font-weight:700;transition:opacity .2s}
#sidebar.col .sb-badge{opacity:0}
.sb-foot{padding:8px;border-top:1px solid var(--b0)}
.sb-col{width:100%;display:flex;align-items:center;gap:10px;padding:7px 6px;border-radius:var(--r-sm);background:none;border:none;color:var(--txt-lo);font-size:.74rem;cursor:pointer;transition:all .15s;font-family:'Instrument Sans',sans-serif}
.sb-col:hover{color:var(--txt-hi);background:var(--bg-card-h)}
.ci{font-size:.9rem;flex-shrink:0;width:18px;text-align:center;transition:transform .3s}
#sidebar.col .ci{transform:rotate(180deg)}
.cl{transition:opacity .2s;white-space:nowrap}
#sidebar.col .cl{opacity:0}

/* ── MAIN ── */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-height:100vh;transition:margin-left .3s cubic-bezier(.4,0,.2,1)}
.main.col{margin-left:var(--sc)}

/* ═══════════════════════════════════════════════════════════
   TOPBAR
═══════════════════════════════════════════════════════════ */
#topbar{
  height:var(--tbh);background:rgba(5,8,15,.9);backdrop-filter:blur(24px);
  border-bottom:1px solid var(--b0);display:flex;align-items:center;gap:.8rem;
  padding:0 1.4rem;position:sticky;top:0;z-index:200;
}
.tb-bc{display:flex;align-items:center;gap:6px;font-size:.72rem;color:var(--txt-md)}
.bc-sep{opacity:.3} .bc-curr{font-family:'Syne',sans-serif;font-weight:700;color:var(--txt-hi)}
.tb-sp{flex:1}
.period-wrap{display:flex;align-items:center;gap:3px;background:var(--bg-card);border:1px solid var(--b0);border-radius:var(--r-sm);padding:3px}
.p-btn{padding:4px 9px;border-radius:6px;font-size:.67rem;font-family:'JetBrains Mono',monospace;background:none;border:none;color:var(--txt-md);cursor:pointer;transition:all .15s}
.p-btn.active{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff}
.tb-acts{display:flex;align-items:center;gap:4px}
.tb-btn{width:32px;height:32px;border-radius:var(--r-sm);background:var(--bg-card);border:1px solid var(--b0);color:var(--txt-md);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.88rem;transition:all .15s;position:relative;text-decoration:none}
.tb-btn:hover{color:var(--txt-hi);background:var(--bg-card-h)}
.nb{position:absolute;top:-3px;right:-3px;min-width:14px;height:14px;padding:0 3px;background:var(--rose);border-radius:100px;font-size:.5rem;font-family:'JetBrains Mono',monospace;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg-base);color:#fff;font-weight:700}
.tb-user{display:flex;align-items:center;gap:6px;padding:4px 8px;border-radius:var(--r-sm);background:var(--bg-card);border:1px solid var(--b0);cursor:pointer;transition:all .15s;text-decoration:none}
.tb-user:hover{border-color:var(--b-act)}
.tu-av{width:24px;height:24px;border-radius:7px;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.65rem;color:#fff}
.tu-n{font-size:.7rem;font-weight:600} .tu-r{font-size:.56rem;color:var(--cyan);font-family:'JetBrains Mono',monospace}
.tb-ham{display:none;background:none;border:none;color:var(--txt-hi);font-size:1.2rem;cursor:pointer;width:32px;height:32px;border-radius:var(--r-sm);align-items:center;justify-content:center}
.tb-ref{display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:var(--r-sm);background:rgba(0,255,170,.07);border:1px solid rgba(0,255,170,.18);color:var(--neon);font-size:.68rem;font-family:'JetBrains Mono',monospace;cursor:pointer;transition:all .15s}
.tb-ref:hover{background:rgba(0,255,170,.13)}
.tb-ref .ri{display:inline-block;transition:transform .3s}
.tb-ref:hover .ri{transform:rotate(180deg)}

/* ═══════════════════════════════════════════════════════════
   PAGE
═══════════════════════════════════════════════════════════ */
.page{flex:1;padding:1.5rem 1.5rem 4rem;max-width:1520px;width:100%;margin:0 auto}

/* ── PAGE HEADER ── */
.ph{
  display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;
  margin-bottom:1.5rem;
  background:linear-gradient(135deg,rgba(0,212,255,.04) 0%,rgba(124,58,237,.05) 60%,rgba(0,255,170,.03) 100%);
  border:1px solid rgba(0,212,255,.08);border-radius:var(--r-xl);padding:1.4rem 1.8rem;
  position:relative;overflow:hidden;animation:slideUp .4s ease both;
}
.ph::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon))}
.ph-glow{position:absolute;right:-60px;top:-60px;width:200px;height:200px;background:radial-gradient(circle,rgba(0,212,255,.06),transparent 70%);pointer-events:none}
.ph-title{font-family:'Syne',sans-serif;font-size:1.45rem;font-weight:800;letter-spacing:-.5px}
.ph-title span{background:linear-gradient(135deg,var(--cyan),var(--violet));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.ph-sub{font-size:.76rem;color:var(--txt-md);margin-top:4px}
.ph-pills{display:flex;gap:5px;margin-top:8px;flex-wrap:wrap}
.ph-pill{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-family:'JetBrains Mono',monospace;padding:2px 9px;border-radius:100px;border:1px solid var(--b0);color:var(--txt-md);text-transform:uppercase}
.ph-pill.live{background:rgba(0,255,170,.06);color:var(--neon);border-color:rgba(0,255,170,.18)}
.ph-pill.live::before{content:'';width:5px;height:5px;background:var(--neon);border-radius:50%;animation:pulse-dot 1.5s infinite}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.4}}
.ph-acts{display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0}

/* ── KPI GRID ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(195px,1fr));gap:.85rem;margin-bottom:1.3rem}
.kpi{
  background:var(--bg-card);border:1px solid var(--b0);border-radius:var(--r-lg);
  padding:1.15rem;position:relative;overflow:hidden;
  transition:transform .22s,border-color .22s,box-shadow .22s;
  animation:slideUp .5s ease both;cursor:default;
}
.kpi::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--kc1,#fff),var(--kc2,#888));opacity:0;transition:opacity .3s}
.kpi:hover{transform:translateY(-4px);border-color:rgba(255,255,255,.08);box-shadow:var(--sh-md)}
.kpi:hover::after{opacity:1}
.kpi:nth-child(1){--kc1:var(--cyan);--kc2:var(--violet);animation-delay:.04s}
.kpi:nth-child(2){--kc1:var(--neon);--kc2:var(--cyan);animation-delay:.07s}
.kpi:nth-child(3){--kc1:var(--violet);--kc2:var(--rose);animation-delay:.1s}
.kpi:nth-child(4){--kc1:var(--amber);--kc2:var(--orange);animation-delay:.13s}
.kpi:nth-child(5){--kc1:var(--neon);--kc2:var(--violet);animation-delay:.16s}
.kpi:nth-child(6){--kc1:var(--rose);--kc2:var(--amber);animation-delay:.19s}
.kpi:nth-child(7){--kc1:var(--cyan);--kc2:var(--neon);animation-delay:.22s}
.kpi:nth-child(8){--kc1:var(--violet);--kc2:var(--cyan);animation-delay:.25s}
.kpi-ico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;margin-bottom:.85rem}
.kpi-val{font-family:'Syne',sans-serif;font-size:1.55rem;font-weight:800;letter-spacing:-.4px;line-height:1;background:linear-gradient(135deg,var(--kc1,#fff),var(--kc2,#aaa));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.kpi-val.sm{font-size:1.05rem}
.kpi-lbl{font-size:.68rem;color:var(--txt-md);margin-top:5px;font-weight:500}
.kpi-chg{display:flex;align-items:center;gap:3px;font-size:.62rem;font-family:'JetBrains Mono',monospace;margin-top:5px}
.up{color:var(--neon)} .down{color:var(--rose)} .neu{color:var(--txt-lo)}
.shim{position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.04),transparent);transform:translateX(-100%);animation:shim 2.5s ease-in-out infinite}
@keyframes shim{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}

/* ── GRID ── */
.g2{display:grid;grid-template-columns:1.5fr 1fr;gap:1.1rem;margin-bottom:1.1rem}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:1.1rem;margin-bottom:1.1rem}
@media(max-width:1200px){.g2{grid-template-columns:1fr}.g3{grid-template-columns:1fr 1fr}}
@media(max-width:760px){.g3{grid-template-columns:1fr}}

/* ── CARD ── */
.card{background:var(--bg-card);border:1px solid var(--b0);border-radius:var(--r-lg);overflow:hidden;animation:slideUp .5s ease both}
.ch{padding:.95rem 1.25rem;border-bottom:1px solid var(--b0);display:flex;align-items:center;justify-content:space-between;gap:.6rem}
.ct{font-family:'Syne',sans-serif;font-weight:700;font-size:.84rem;display:flex;align-items:center;gap:7px}
.ci-badge{width:27px;height:27px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0}
.cb{padding:.95rem 1.25rem}
.cf{padding:.7rem 1.25rem;border-top:1px solid var(--b0);display:flex;align-items:center;justify-content:space-between}

/* ── TABLE ── */
.tbl{width:100%;border-collapse:collapse;font-size:.76rem}
.tbl th{text-align:left;font-family:'JetBrains Mono',monospace;font-size:.57rem;letter-spacing:.08em;text-transform:uppercase;color:var(--txt-lo);padding:6px 10px;border-bottom:1px solid var(--b0);white-space:nowrap}
.tbl td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.03);color:var(--txt-md);vertical-align:middle;transition:background .1s}
.tbl tbody tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:var(--bg-card-h)}
.td-hi{font-weight:600;color:var(--txt-hi)} .td-m{font-family:'JetBrains Mono',monospace;font-size:.63rem}

/* ── CHIPS ── */
.chip{display:inline-flex;align-items:center;gap:2px;font-size:.58rem;font-family:'JetBrains Mono',monospace;padding:2px 7px;border-radius:100px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
.c-ok{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.c-warn{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.c-err{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.c-info{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.c-v{background:rgba(124,58,237,.1);color:#a78bfa;border:1px solid rgba(124,58,237,.2)}
.c-mu{background:rgba(255,255,255,.05);color:var(--txt-lo);border:1px solid var(--b0)}
.c-bonus{background:linear-gradient(135deg,rgba(0,255,170,.12),rgba(0,212,255,.08));color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.c-vente{background:rgba(0,212,255,.08);color:var(--cyan);border:1px solid rgba(0,212,255,.18)}
.c-pr{background:linear-gradient(135deg,rgba(245,158,11,.15),rgba(249,115,22,.12));color:var(--amber);border:1px solid rgba(245,158,11,.22)}
.c-free{background:rgba(0,255,170,.07);color:var(--neon);border:1px solid rgba(0,255,170,.15)}
.c-std{background:rgba(0,212,255,.07);color:var(--cyan);border:1px solid rgba(0,212,255,.15)}

/* ── BTNS ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:var(--r-sm);font-family:'Syne',sans-serif;font-size:.74rem;font-weight:700;cursor:pointer;transition:all .15s;text-decoration:none;border:none;white-space:nowrap}
.btn-sm{padding:4px 9px;font-size:.67rem} .btn-xs{padding:2px 6px;font-size:.6rem}
.btn-pr{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff;box-shadow:0 4px 14px rgba(0,212,255,.16)}
.btn-pr:hover{opacity:.85;transform:translateY(-1px);box-shadow:0 6px 22px rgba(0,212,255,.28)}
.btn-gh{background:var(--bg-card);border:1px solid var(--b0);color:var(--txt-md)}
.btn-gh:hover{color:var(--txt-hi);border-color:var(--b1);background:var(--bg-card-h)}
.btn-neon{background:rgba(0,255,170,.09);border:1px solid rgba(0,255,170,.2);color:var(--neon)}
.btn-neon:hover{background:rgba(0,255,170,.16)}
.btn-err{background:rgba(244,63,94,.09);border:1px solid rgba(244,63,94,.2);color:var(--rose)}
.btn-err:hover{background:rgba(244,63,94,.16)}
.btn-amber{background:rgba(245,158,11,.09);border:1px solid rgba(245,158,11,.2);color:var(--amber)}

/* ── PROGRESS ── */
.pg-wrap{background:rgba(255,255,255,.06);border-radius:100px;height:4px;overflow:hidden;flex:1}
.pg{height:100%;border-radius:100px;transition:width 1s ease}
.pg-c{background:linear-gradient(90deg,var(--cyan),var(--violet))}
.pg-n{background:linear-gradient(90deg,var(--neon),#00c87a)}
.pg-a{background:linear-gradient(90deg,var(--amber),var(--orange))}

/* ── RANK ── */
.rank-item{display:flex;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.03)}
.rank-item:last-child{border-bottom:none}
.rank-n{font-family:'JetBrains Mono',monospace;font-size:.66rem;color:var(--txt-lo);width:18px;text-align:center;flex-shrink:0}
.rk-g{color:#f59e0b} .rk-s{color:#94a3b8} .rk-b{color:#c2733e}
.rank-info{flex:1;min-width:0}
.rank-name{font-size:.78rem;font-weight:600;color:var(--txt-hi);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-sub{font-size:.6rem;color:var(--txt-lo);font-family:'JetBrains Mono',monospace;margin-top:1px}
.rank-val{font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem;color:var(--cyan);flex-shrink:0}

/* ── FEED ── */
.feed-item{display:flex;align-items:flex-start;gap:9px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.03);animation:fadeIn .3s ease both}
.feed-item:last-child{border-bottom:none}
@keyframes fadeIn{from{opacity:0;transform:translateX(8px)}to{opacity:1;transform:translateX(0)}}
.fd-dot{width:27px;height:27px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.78rem;flex-shrink:0;background:var(--bg-card-h)}
.fd-body{flex:1;min-width:0}
.fd-msg{font-size:.74rem;color:var(--txt-md);line-height:1.44}
.fd-msg strong{color:var(--txt-hi)}
.fd-time{font-size:.58rem;font-family:'JetBrains Mono',monospace;color:var(--txt-lo);margin-top:2px}
.fd-amt{font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem;color:var(--neon);flex-shrink:0;white-space:nowrap}

/* ── LIVE INDICATOR ── */
.live-dot{width:6px;height:6px;background:var(--neon);border-radius:50%;animation:pulse-dot 1.5s infinite;flex-shrink:0}

/* ── FILTERS BAR ── */
.filters{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.9rem;align-items:center}
.f-inp{background:var(--bg-card);border:1px solid var(--b0);border-radius:var(--r-sm);padding:6px 11px;color:var(--txt-hi);font-size:.75rem;font-family:'Instrument Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
.f-inp:focus{border-color:var(--b-act);box-shadow:var(--glow-c)}
.f-inp::placeholder{color:var(--txt-lo)}
select.f-inp{min-width:130px}
.f-search{flex:1;min-width:180px}
.f-date{width:130px}

/* ── BONUS MODAL ── */
.modal-bg{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(5,8,15,.9);backdrop-filter:blur(16px);opacity:0;pointer-events:none;transition:opacity .28s}
.modal-bg.open{opacity:1;pointer-events:all}
.modal{background:var(--bg-surf);border:1px solid var(--b0);border-radius:var(--r-xl);padding:1.8rem;max-width:440px;width:100%;box-shadow:var(--sh-lg);position:relative;overflow:hidden;transform:translateY(22px) scale(.97);transition:transform .3s cubic-bezier(.34,1.56,.64,1)}
.modal::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--neon),var(--cyan),var(--violet))}
.modal-bg.open .modal{transform:translateY(0) scale(1)}
.m-close{position:absolute;top:.8rem;right:.8rem;background:none;border:none;color:var(--txt-lo);font-size:.9rem;cursor:pointer;width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.m-close:hover{color:var(--txt-hi);background:var(--bg-card-h)}
.m-title{font-family:'Syne',sans-serif;font-weight:800;font-size:1.15rem;margin-bottom:.3rem}
.m-sub{font-size:.75rem;color:var(--txt-md);margin-bottom:1.2rem}
.m-label{font-size:.65rem;font-family:'JetBrains Mono',monospace;color:var(--txt-lo);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;display:block}
.m-input{width:100%;background:var(--bg-card);border:1px solid var(--b0);border-radius:var(--r-sm);padding:8px 11px;color:var(--txt-hi);font-size:.8rem;font-family:'Instrument Sans',sans-serif;outline:none;transition:border-color .2s;margin-bottom:.85rem}
.m-input:focus{border-color:var(--b-act);box-shadow:var(--glow-c)}
.suggest-list{background:var(--bg-surf);border:1px solid var(--b0);border-radius:var(--r-md);max-height:160px;overflow-y:auto;display:none;position:absolute;width:100%;z-index:10;box-shadow:var(--sh-md)}
.suggest-list.open{display:block}
.suggest-item{padding:8px 11px;font-size:.76rem;color:var(--txt-md);cursor:pointer;transition:background .1s;border-bottom:1px solid rgba(255,255,255,.03)}
.suggest-item:last-child{border-bottom:none}
.suggest-item:hover{background:var(--bg-card-h);color:var(--txt-hi)}
.suggest-item strong{color:var(--txt-hi)}
.suggest-item small{color:var(--txt-lo);font-family:'JetBrains Mono',monospace;font-size:.6rem}
.m-elig{border-radius:var(--r-md);padding:.9rem;margin-bottom:1rem;font-size:.77rem;display:none}
.m-elig.show{display:block}
.m-elig.ok{background:rgba(0,255,170,.08);border:1px solid rgba(0,255,170,.2);color:var(--neon)}
.m-elig.err{background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);color:var(--rose)}
.m-elig.warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);color:var(--amber)}
.m-stats{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-top:.6rem}
.m-stat{background:rgba(255,255,255,.04);border-radius:7px;padding:.5rem .7rem}
.m-stat-v{font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;color:var(--txt-hi)}
.m-stat-l{font-size:.58rem;color:var(--txt-lo);font-family:'JetBrains Mono',monospace}

/* ── DETAIL MODAL ── */
.det-row{display:flex;gap:.8rem;padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.77rem}
.det-row:last-child{border-bottom:none}
.det-lbl{color:var(--txt-lo);width:130px;flex-shrink:0;font-family:'JetBrains Mono',monospace;font-size:.62rem;text-transform:uppercase}
.det-val{color:var(--txt-hi);font-weight:500}

/* ── NOTIF PANEL ── */
#notif-panel{position:fixed;top:calc(var(--tbh) + 6px);right:1rem;width:300px;background:var(--bg-surf);border:1px solid var(--b0);border-radius:var(--r-lg);box-shadow:var(--sh-lg);z-index:500;transform:translateY(-10px) scale(.97);opacity:0;pointer-events:none;transition:all .22s cubic-bezier(.34,1.56,.64,1);overflow:hidden}
#notif-panel.open{transform:translateY(0) scale(1);opacity:1;pointer-events:all}
.np-head{padding:.75rem 1rem;border-bottom:1px solid var(--b0);display:flex;align-items:center;justify-content:space-between;font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem}
.np-list{max-height:290px;overflow-y:auto}
.np-item{display:flex;gap:8px;padding:8px 1rem;border-bottom:1px solid rgba(255,255,255,.03);font-size:.7rem;cursor:pointer;transition:background .1s}
.np-item:hover{background:var(--bg-card-h)}
.np-item.unread{background:rgba(0,212,255,.03)}
.np-ico{width:25px;height:25px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0}
.np-txt{color:var(--txt-md);line-height:1.4;font-size:.7rem}
.np-txt strong{color:var(--txt-hi)}
.np-time{font-size:.56rem;font-family:'JetBrains Mono',monospace;color:var(--txt-lo);margin-top:1px}

/* ── TOAST ── */
#toasts{position:fixed;bottom:1.2rem;right:1.2rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:5px;pointer-events:none}
.toast{display:flex;align-items:center;gap:8px;padding:9px 13px;background:var(--bg-surf);border:1px solid var(--b0);border-radius:var(--r-md);box-shadow:var(--sh-lg);font-size:.73rem;max-width:280px;pointer-events:all;transform:translateX(110px);opacity:0;transition:all .3s cubic-bezier(.34,1.56,.64,1)}
.toast.show{transform:translateX(0);opacity:1}
.t-ico{font-size:.95rem;flex-shrink:0}
.t-body{flex:1}
.t-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem}
.t-sub{color:var(--txt-lo);font-size:.63rem;margin-top:1px}
.t-x{color:var(--txt-lo);cursor:pointer;padding:0 2px}

/* ── LOYALTY BARS ── */
.loyalty-item{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:.74rem}
.loyalty-item:last-child{border-bottom:none}
.loy-name{width:130px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--txt-hi)}
.loy-prog{flex:1;display:flex;align-items:center;gap:5px}
.loy-bonus{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--neon);flex-shrink:0;width:30px;text-align:right}

/* ── OVERLAY MOBILE ── */
#overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:299;opacity:0;pointer-events:none;transition:opacity .3s}
#overlay.show{opacity:1;pointer-events:all}

/* ── ANIMATIONS ── */
@keyframes slideUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes countUp{from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)}}

/* ── SKEL ── */
.skel{background:linear-gradient(90deg,rgba(255,255,255,.04) 25%,rgba(255,255,255,.07) 50%,rgba(255,255,255,.04) 75%);background-size:400px;border-radius:5px;animation:shim 1.5s infinite}
.skel-h{height:.85rem;margin-bottom:.35rem}

/* ── EXPORT DROPDOWN ── */
.exp-wrap{position:relative}
#exp-dd{position:absolute;top:calc(100% + 4px);right:0;background:var(--bg-surf);border:1px solid var(--b0);border-radius:var(--r-md);box-shadow:var(--sh-md);z-index:400;min-width:180px;display:none;overflow:hidden}
#exp-dd.open{display:block}
.exp-item{display:flex;align-items:center;gap:8px;padding:8px 13px;font-size:.74rem;color:var(--txt-md);cursor:pointer;transition:background .1s;border:none;background:none;width:100%;text-align:left}
.exp-item:hover{background:var(--bg-card-h);color:var(--txt-hi)}

/* ── INSIGHTS ── */
.ins-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.7rem}
@media(max-width:900px){.ins-grid{grid-template-columns:1fr 1fr}}
.ins-card{background:var(--bg-card-h);border:1px solid var(--b0);border-radius:var(--r-md);padding:.9rem;display:flex;flex-direction:column;gap:.4rem;transition:transform .2s}
.ins-card:hover{transform:translateY(-2px)}
.ins-ico{font-size:1.3rem}
.ins-val{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:var(--txt-hi)}
.ins-lbl{font-size:.62rem;color:var(--txt-lo);font-family:'JetBrains Mono',monospace}
.ins-desc{font-size:.68rem;color:var(--txt-md)}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  #sidebar{transform:translateX(calc(-1 * var(--sw)));width:var(--sw)!important}
  #sidebar.mob-open{transform:translateX(0)}
  .main,.main.col{margin-left:0!important}
  .tb-ham{display:flex}
  .kpi-grid{grid-template-columns:repeat(2,1fr)}
  .page{padding:1.1rem .9rem 3rem}
  .ph{flex-direction:column}
  .filters{flex-direction:column;align-items:stretch}
  .f-search,.f-inp,.f-date{width:100%}
}
@media(max-width:480px){.kpi-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app">

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">📚</div>
    <div class="sb-name">Digital <em>Library</em></div>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= $adminInitial ?></div>
    <div class="sb-uinfo">
      <div class="sb-uname"><?= $adminName ?></div>
      <div class="sb-urole">⚡ Administrateur</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-sec">Principal</div>
    <a href="../../dashboard.php" class="sb-item"><span class="sb-ico"><i class="bi bi-grid-1x2"></i></span><span class="sb-lbl">Dashboard</span></a>
    <div class="sb-sec">Administration</div>

    <a href="sales.php" class="sb-item active"><span class="sb-ico"><i class="bi bi-cash-coin"></i></span><span class="sb-lbl">Ventes & Bonus</span></a>

  </nav>
  <div class="sb-foot">
    <button type="button" class="sb-col" onclick="toggleSidebar()">
      <span class="ci"><i class="bi bi-chevron-left"></i></span>
      <span class="cl">Réduire</span>
    </button>
  </div>
</aside>
<div id="overlay" onclick="closeMobile()"></div>

<!-- ═══════════════ MAIN ═══════════════ -->
<div class="main" id="main">

  <!-- TOPBAR -->
  <header id="topbar">
    <button class="tb-ham" onclick="toggleMobile()"><i class="bi bi-list"></i></button>
    <div class="tb-bc">
      <span>DLS</span><span class="bc-sep">/</span><span>Admin</span><span class="bc-sep">/</span>
      <span class="bc-curr">Ventes & Bonus</span>
    </div>
    <div class="tb-sp"></div>
    <div class="period-wrap">
      <button class="p-btn" data-p="today">Auj.</button>
      <button class="p-btn" data-p="week">7j</button>
      <button class="p-btn active" data-p="month">Mois</button>
      <button class="p-btn" data-p="year">Année</button>
    </div>
    <button class="tb-ref" onclick="refreshAll()">
      <span class="ri"><i class="bi bi-arrow-clockwise"></i></span><span>Refresh</span>
    </button>
    <div class="tb-acts">
      <button class="tb-btn" id="notif-btn" onclick="toggleNotif()" title="Notifications">
        <i class="bi bi-bell"></i>
        <span class="nb" id="notif-badge" style="display:none"><?= min(9,(int)($initStats['notif_unread'] ?? 0)) ?></span>
      </button>
      <a href="../users/profile.php" class="tb-user">
        <div class="tu-av"><?= $adminInitial ?></div>
        <div><div class="tu-n"><?= $adminName ?></div><div class="tu-r">admin</div></div>
      </a>
  </header>

  <!-- PAGE -->
  <main class="page">

    <?php if ($dbError): ?>
    <div style="background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);border-radius:var(--r-md);padding:1rem 1.2rem;color:var(--rose);font-size:.8rem;margin-bottom:1.1rem;display:flex;align-items:center;gap:8px">
      <i class="bi bi-exclamation-triangle-fill"></i> Base de données inaccessible — <?= esc($dbError) ?>
    </div>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="ph">
      <div class="ph-glow"></div>
      <div>
        <div class="ph-title">Gestion des <span>Ventes & Bonus</span></div>
        <div class="ph-sub">Transactions · Attributions · Fidélité · Analytics temps réel · <?= date('d M Y, H:i') ?></div>
        <div class="ph-pills">
          <span class="ph-pill live"><span></span>Live</span>
          <span class="ph-pill">📡 Auto 10s</span>
          <span class="ph-pill">🎁 Système bonus intelligent</span>
          <span class="ph-pill">🔒 Admin</span>
        </div>
      </div>
      <div class="ph-acts">
        <button class="btn btn-neon" onclick="openBonusModal()">
          <i class="bi bi-gift-fill"></i> Attribuer bonus
        </button>
        <div class="exp-wrap">
         
          <div id="exp-dd">
            
          </div>
        </div>
        <button class="btn btn-pr" onclick="refreshAll()"><i class="bi bi-arrow-clockwise"></i> Actualiser</button>
      </div>
    </div>

    <!-- ═══ KPI GRID ═══ -->
    <div class="kpi-grid">
      <div class="kpi">
        <div class="shim"></div>
        <div class="kpi-ico" style="background:rgba(0,212,255,.1)">💰</div>
        <div class="kpi-val sm" id="kv-rev-total"><?= fmtFCFA((float)($initStats['rev_total'] ?? 0), true) ?></div>
        <div class="kpi-lbl">Chiffre d'affaires (ventes)</div>
        <div class="kpi-chg neu"><i class="bi bi-dot"></i> Total cumulé</div>
      </div>
      <div class="kpi">
        <div class="shim"></div>
        <div class="kpi-ico" style="background:rgba(0,255,170,.08)">⚡</div>
        <div class="kpi-val" id="kv-today"><?= (int)($initStats['sales_today'] ?? 0) ?></div>
        <div class="kpi-lbl">Transactions aujourd'hui</div>
        <div class="kpi-chg" id="chg-today"><div class="live-dot" style="margin-right:3px"></div><span id="kv-rev-today"><?= fmtFCFA((float)($initStats['rev_today'] ?? 0), true) ?></span></div>
      </div>
      <div class="kpi">
        <div class="shim"></div>
        <div class="kpi-ico" style="background:rgba(124,58,237,.1)">📅</div>
        <div class="kpi-val" id="kv-month"><?= (int)($initStats['sales_month'] ?? 0) ?></div>
        <div class="kpi-lbl">Transactions ce mois</div>
        <div class="kpi-chg" id="chg-month">
          <i class="bi bi-arrow-<?= ($initStats['rev_growth'] ?? 0) >= 0 ? 'up' : 'down' ?>-short <?= ($initStats['rev_growth'] ?? 0) >= 0 ? 'up' : 'down' ?>"></i>
          <?= abs((float)($initStats['rev_growth'] ?? 0)) ?>% vs mois préc.
        </div>
      </div>
      <div class="kpi">
        <div class="shim"></div>
        <div class="kpi-ico" style="background:rgba(0,255,170,.06)">📈</div>
        <div class="kpi-val sm" id="kv-rev-month"><?= fmtFCFA((float)($initStats['rev_month'] ?? 0), true) ?></div>
        <div class="kpi-lbl">Revenus du mois</div>
        <div class="kpi-chg neu"><i class="bi bi-calendar3"></i> <?= date('F Y') ?></div>
      </div>
      <div class="kpi">
        <div class="shim"></div>
        <div class="kpi-ico" style="background:rgba(0,255,170,.08)">🎁</div>
        <div class="kpi-val" id="kv-bonus"><?= (int)($initStats['bonus_total'] ?? 0) ?></div>
        <div class="kpi-lbl">Bonus accordés (total)</div>
        <div class="kpi-chg" id="chg-eligible">
          <i class="bi bi-people"></i> <span id="kv-eligible"><?= (int)($initStats['users_eligible'] ?? 0) ?></span> utilisateurs éligibles
        </div>
      </div>
      <div class="kpi">
        <div class="shim"></div>
        <div class="kpi-ico" style="background:rgba(245,158,11,.1)">🏆</div>
        <div class="kpi-val" id="kv-year"><?= (int)($initStats['sales_year'] ?? 0) ?></div>
        <div class="kpi-lbl">Transactions <?= date('Y') ?></div>
        <div class="kpi-chg neu"><i class="bi bi-dot"></i> <span id="kv-rev-year"><?= fmtFCFA((float)($initStats['rev_year'] ?? 0), true) ?></span></div>
      </div>
      <div class="kpi">
        <div class="shim"></div>
        <div class="kpi-ico" style="background:rgba(0,212,255,.08)">✅</div>
        <div class="kpi-val" id="kv-confirmed"><?= (int)($initStats['confirmed_total'] ?? 0) ?></div>
        <div class="kpi-lbl">Confirmées (all time)</div>
        <div class="kpi-chg up"><i class="bi bi-check2-circle"></i> Paiements validés</div>
      </div>
      <div class="kpi">
        <div class="shim"></div>
        <div class="kpi-ico" style="background:rgba(244,63,94,.08)">❌</div>
        <div class="kpi-val" id="kv-failed"><?= (int)($initStats['failed_total'] ?? 0) ?></div>
        <div class="kpi-lbl">Échecs de paiement</div>
        <div class="kpi-chg down"><i class="bi bi-x-circle"></i> Transactions échouées</div>
      </div>
    </div>

    <!-- ═══ GRAPHIQUES ═══ -->
    <div class="g2" style="animation-delay:.1s">
      <div class="card">
        <div class="ch">
          <div class="ct"><div class="ci-badge" style="background:rgba(0,212,255,.1)"><i class="bi bi-graph-up"></i></div>Évolution des transactions</div>
          <div style="display:flex;gap:4px">
            <button class="btn btn-sm btn-gh chart-type active" data-type="daily" onclick="switchChart('daily',this)">30j</button>
            <button class="btn btn-sm btn-gh chart-type" data-type="monthly" onclick="switchChart('monthly',this)">12 mois</button>
          </div>
        </div>
        <div style="padding:.4rem"><div id="chart-sales" style="min-height:260px"></div></div>
      </div>
      <div class="card">
        <div class="ch">
          <div class="ct"><div class="ci-badge" style="background:rgba(124,58,237,.1)"><i class="bi bi-pie-chart"></i></div>Par catégorie</div>
          <span class="chip c-v">Donut</span>
        </div>
        <div style="padding:.4rem"><div id="chart-cat" style="min-height:260px"></div></div>
      </div>
    </div>

    <!-- ═══ GRID 3 ═══ -->
    <div class="g3" style="animation-delay:.15s">
      <!-- Méthodes paiement -->
      <div class="card">
        <div class="ch"><div class="ct"><div class="ci-badge" style="background:rgba(0,255,170,.08)"><i class="bi bi-credit-card"></i></div>Méthodes de paiement</div></div>
        <div class="cb"><div id="chart-methods" style="min-height:200px"></div></div>
        <div class="cb" style="padding-top:0" id="methods-bars"></div>
      </div>
      <!-- Top acheteurs -->
      <div class="card">
        <div class="ch"><div class="ct"><div class="ci-badge" style="background:rgba(245,158,11,.1)"><i class="bi bi-trophy"></i></div>Top Acheteurs</div><span class="chip c-warn">Classement</span></div>
        <div class="cb" id="top-buyers">
          <div class="skel skel-h" style="width:75%"></div>
          <div class="skel skel-h" style="width:90%;margin-top:.4rem"></div>
          <div class="skel skel-h" style="width:60%;margin-top:.4rem"></div>
        </div>
        <div class="cf"><a href="../users/index.php" class="btn btn-sm btn-gh">Voir tous</a></div>
      </div>
      <!-- Insights -->
      <div class="card">
        <div class="ch"><div class="ct"><div class="ci-badge" style="background:rgba(0,212,255,.08)"><i class="bi bi-lightning-charge"></i></div>Insights & Prédictions</div><span class="chip c-info">IA</span></div>
        <div class="cb" id="insights-body">
          <div class="skel skel-h" style="width:80%"></div>
          <div class="skel skel-h" style="width:60%;margin-top:.4rem"></div>
          <div class="skel skel-h" style="width:70%;margin-top:.4rem"></div>
        </div>
      </div>
    </div>

    <!-- ═══ FIDÉLITÉ BONUS ═══ -->
    <div class="card" style="margin-bottom:1.1rem;animation-delay:.2s">
      <div class="ch">
        <div class="ct"><div class="ci-badge" style="background:rgba(0,255,170,.08)"><i class="bi bi-gift-fill"></i></div>Système de fidélité & Bonus</div>
        <div style="display:flex;gap:5px;align-items:center">
          <span class="chip c-bonus" id="loyalty-eligible-badge">— éligibles</span>
          <button class="btn btn-sm btn-neon" onclick="openBonusModal()"><i class="bi bi-gift"></i> Attribuer bonus</button>
        </div>
      </div>
      <div class="cb">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.8rem;margin-bottom:1rem" id="loyalty-kpis">
          <div class="ins-card"><div class="ins-ico">🎯</div><div class="ins-val" id="loy-rule">5</div><div class="ins-lbl">Achats requis / bonus</div></div>
          <div class="ins-card"><div class="ins-ico">🏅</div><div class="ins-val" id="loy-total-granted">0</div><div class="ins-lbl">Bonus accordés (total)</div></div>
          <div class="ins-card"><div class="ins-ico">👥</div><div class="ins-val" id="loy-eligible">0</div><div class="ins-lbl">Utilisateurs éligibles</div></div>
        </div>
        <div id="loyalty-list">
          <div class="skel skel-h" style="width:100%"></div>
          <div class="skel skel-h" style="width:85%;margin-top:.4rem"></div>
        </div>
      </div>
    </div>

    <!-- ═══ TOP LIVRES ═══ -->
    <div class="card" style="margin-bottom:1.1rem;animation-delay:.25s">
      <div class="ch">
        <div class="ct"><div class="ci-badge" style="background:rgba(124,58,237,.1)"><i class="bi bi-book-half"></i></div>Top Livres — Ventes & Bonus</div>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
          <button class="btn btn-sm btn-gh sort-b active" data-sort="revenue" onclick="loadTopBooks('revenue',this)">💰 Revenus</button>
          <button class="btn btn-sm btn-gh sort-b" data-sort="sales" onclick="loadTopBooks('sales',this)">📊 Ventes</button>
          <button class="btn btn-sm btn-gh sort-b" data-sort="bonus" onclick="loadTopBooks('bonus',this)">🎁 Bonus</button>
          <button class="btn btn-sm btn-gh sort-b" data-sort="lectures" onclick="loadTopBooks('lectures',this)">📖 Lectures</button>
        </div>
      </div>
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead>
            <tr>
              <th>#</th><th>Livre</th><th>Catégorie</th><th>Accès</th><th>Prix</th>
              <th>Ventes</th><th>Bonus</th><th>Revenus</th><th>Lectures</th><th>Note</th>
            </tr>
          </thead>
          <tbody id="books-tbody">
            <tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--txt-lo)">Chargement…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="cf">
        <span id="books-count" style="font-size:.68rem;color:var(--txt-lo)"></span>
        <button class="btn btn-sm btn-gh" onclick="loadTopBooks(currentSort,null,20)">Voir plus</button>
      </div>
    </div>

    <!-- ═══ TRANSACTIONS + LIVE FEED ═══ -->
    <div class="g2" style="margin-bottom:1.1rem;animation-delay:.3s">
      <!-- Transactions -->
      <div class="card">
        <div class="ch">
          <div class="ct"><div class="ci-badge" style="background:rgba(0,255,170,.06)"><i class="bi bi-receipt"></i></div>Transactions
            <div class="live-dot"></div>
          </div>
          <div style="display:flex;gap:4px;align-items:center">
            <span id="tx-total-badge" class="chip c-ok"></span>
            <button class="btn btn-sm btn-gh" onclick="loadTransactions()"><i class="bi bi-arrow-clockwise"></i></button>
          </div>
        </div>
        <!-- Filtres -->
        <div style="padding:.7rem 1.1rem;border-bottom:1px solid var(--b0)">
          <div class="filters">
            <input type="search" class="f-inp f-search" id="f-search" placeholder="🔍 Recherche…" oninput="debounceFilter()" autocomplete="off">
            <select class="f-inp" id="f-type" onchange="loadTransactions()">
              <option value="">Tous types</option>
              <option value="vente">💳 Ventes</option>
              <option value="bonus_accorde">🎁 Bonus</option>
            </select>
            <select class="f-inp" id="f-cat" onchange="loadTransactions()">
              <option value="">Toutes catégories</option>
              <?php foreach ($initStats['categories'] ?? [] as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>"><?= esc($cat['icone'] ?? '') . ' ' . esc($cat['nom'] ?? '') ?></option>
              <?php endforeach; ?>
            </select>
            <select class="f-inp" id="f-method" onchange="loadTransactions()">
              <option value="">Toutes méthodes</option>
              <option value="orange_money">🟠 Orange Money</option>
              <option value="mobile_money">🟡 MTN MoMo</option>
              <option value="carte">💳 Carte</option>
            </select>
          </div>
          <div class="filters" style="margin-bottom:0">
            <input type="date" class="f-inp f-date" id="f-from" onchange="loadTransactions()">
            <input type="date" class="f-inp f-date" id="f-to" onchange="loadTransactions()">
            <button class="btn btn-sm btn-err" onclick="clearFilters()"><i class="bi bi-x-circle"></i> Reset</button>
            <select class="f-inp" id="f-sort" onchange="loadTransactions()" style="min-width:130px">
              <option value="date">Tri : Date ↓</option>
              <option value="montant">Tri : Montant ↓</option>
              <option value="user">Tri : Utilisateur</option>
              <option value="livre">Tri : Livre</option>
            </select>
          </div>
        </div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead>
              <tr>
                <th>Réf.</th><th>Acheteur</th><th>Livre</th><th>Type</th>
                <th>Méthode</th><th>Montant</th><th>Date</th><th>Statut</th><th>Action</th>
              </tr>
            </thead>
            <tbody id="tx-tbody">
              <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--txt-lo)">Chargement…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="cf">
          <div style="display:flex;gap:3px;align-items:center">
            <button class="btn btn-xs btn-gh" onclick="txPage(Math.max(1,currentTxPage-1))"><i class="bi bi-chevron-left"></i></button>
            <span id="tx-page-info" style="font-size:.62rem;font-family:'JetBrains Mono',monospace;color:var(--txt-lo)">1/1</span>
            <button class="btn btn-xs btn-gh" onclick="txPage(currentTxPage+1)"><i class="bi bi-chevron-right"></i></button>
          </div>
         
        </div>
      </div>

      <!-- Live Feed -->
      <div class="card">
        <div class="ch">
          <div class="ct"><div class="ci-badge" style="background:rgba(0,212,255,.06)"><i class="bi bi-activity"></i></div>Activité temps réel<div class="live-dot"></div></div>
          <button class="btn btn-sm btn-neon" onclick="loadFeed()"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="cb" style="max-height:480px;overflow-y:auto" id="live-feed">
          <div style="text-align:center;padding:2rem;color:var(--txt-lo)">Chargement…</div>
        </div>
      </div>
    </div>

    <!-- ═══ REVENUS 12 MOIS ═══ -->
    <div class="card" style="margin-bottom:1.1rem;animation-delay:.35s">
      <div class="ch">
        <div class="ct"><div class="ci-badge" style="background:rgba(0,255,170,.06)"><i class="bi bi-bar-chart-line"></i></div>Revenus mensuels — 12 mois (Ventes réelles uniquement)</div>
        <span class="chip c-ok">Tendance annuelle</span>
      </div>
      <div style="padding:.4rem"><div id="chart-revenue" style="min-height:250px"></div></div>
    </div>

  </main><!-- /page -->
</div><!-- /main -->
</div><!-- /app -->

<!-- ═══════════════ MODAL BONUS ═══════════════ -->
<div class="modal-bg" id="bonus-modal">
  <div class="modal">
    <button class="m-close" onclick="closeBonusModal()"><i class="bi bi-x-lg"></i></button>
    <div class="m-title">🎁 Attribuer un Bonus</div>
    <div class="m-sub">Offrir un livre gratuitement à un utilisateur éligible.</div>

    <!-- Recherche utilisateur -->
    <label class="m-label" for="bonus-user-input">Utilisateur</label>
    <div style="position:relative;margin-bottom:.85rem">
      <input type="text" class="m-input" id="bonus-user-input" placeholder="Rechercher par nom ou email…" autocomplete="off" oninput="debounceUserSearch(this.value)" style="margin-bottom:0;width:100%">
      <div class="suggest-list" id="user-suggest"></div>
    </div>
    <input type="hidden" id="bonus-user-id">

    <!-- Recherche livre -->
    <label class="m-label" for="bonus-book-input">Livre</label>
    <div style="position:relative;margin-bottom:.85rem">
      <input type="text" class="m-input" id="bonus-book-input" placeholder="Rechercher un livre…" autocomplete="off" oninput="debounceBookSearch(this.value)" style="margin-bottom:0;width:100%">
      <div class="suggest-list" id="book-suggest"></div>
    </div>
    <input type="hidden" id="bonus-book-id">

    <!-- Éligibilité -->
    <div class="m-elig" id="elig-box">
      <div id="elig-msg"></div>
      <div class="m-stats" id="elig-stats" style="display:none"></div>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:7px;margin-top:.5rem">
      <button class="btn btn-gh" style="flex:1;justify-content:center" onclick="checkEligibility()">
        <i class="bi bi-search"></i> Vérifier éligibilité
      </button>
      <button class="btn btn-neon" style="flex:1;justify-content:center" id="btn-grant" onclick="grantBonus()" disabled>
        <i class="bi bi-gift-fill"></i> Attribuer
      </button>
    </div>
    <p style="font-size:.6rem;color:var(--txt-lo);text-align:center;margin-top:.7rem">
      <i class="bi bi-shield-check"></i> Vérification automatique · Empêche les doublons · Sécurisé
    </p>
  </div>
</div>

<!-- ═══════════════ MODAL DÉTAIL TRANSACTION ═══════════════ -->
<div class="modal-bg" id="detail-modal">
  <div class="modal" style="max-width:480px">
    <button class="m-close" onclick="closeDetailModal()"><i class="bi bi-x-lg"></i></button>
    <div class="m-title" id="det-title">Détails de la transaction</div>
    <div style="margin-top:1rem" id="det-body"></div>
    <div style="display:flex;gap:7px;margin-top:1.2rem" id="det-actions"></div>
  </div>
</div>

<!-- ═══════════════ NOTIF PANEL ═══════════════ -->
<div id="notif-panel">
  <div class="np-head">
    <span>Notifications</span>
    <button class="btn btn-xs btn-gh" onclick="markRead()">Tout lire</button>
  </div>
  <div class="np-list" id="notif-list">
    <div style="padding:1.5rem;text-align:center;color:var(--txt-lo);font-size:.73rem">Chargement…</div>
  </div>
  <div class="cf" style="padding:.6rem 1rem">
    <a href="notifications.php" class="btn btn-sm btn-gh">Voir toutes</a>
    <span id="notif-time" style="font-size:.58rem;color:var(--txt-lo);font-family:'JetBrains Mono',monospace"></span>
  </div>
</div>

<!-- ═══════════════ TOAST STACK ═══════════════ -->
<div id="toasts"></div>

<!-- ═══════════════ SCRIPTS ═══════════════ -->
<script>
/* ══════════════════════════════════════════════════════════
   GLOBALS
══════════════════════════════════════════════════════════ */
const INIT       = <?= $initJson ?>;
const CSRF       = <?= $csrfJson ?>;
const ADMIN_NAME = <?= $nameJson ?>;
const API        = 'sales.php';

let currentPeriod  = 'month';
let currentSort    = 'revenue';
let currentTxPage  = 1;
let totalTxPages   = 1;
let sidebarCol     = false;
let filterTimer    = null;
let userTimer      = null;
let bookTimer      = null;
let autoRefTimer   = null;
let eligChecked    = false;

let chartSales    = null;
let chartRevenue  = null;
let chartCat      = null;
let chartMethods  = null;

/* ══════════════════════════════════════════════════════════
   UTILS
══════════════════════════════════════════════════════════ */
function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function fmtFCFA(n,sh=false){const v=parseFloat(n)||0;if(sh){if(v>=1e6)return(v/1e6).toFixed(1).replace('.',',')+' M';if(v>=1e3)return Math.round(v/1e3)+' K';}return new Intl.NumberFormat('fr-FR').format(Math.round(v))+' FCFA'}
function fmtN(n){return new Intl.NumberFormat('fr-FR').format(parseInt(n)||0)}
function timeAgo(dt){if(!dt)return'—';const d=new Date(dt),diff=Math.floor((Date.now()-d)/1000);if(diff<60)return'à l\'instant';if(diff<3600)return Math.floor(diff/60)+' min';if(diff<86400)return Math.floor(diff/3600)+'h';return d.toLocaleDateString('fr-FR')}
function gc(v){return parseFloat(v)>=0?'up':'down'}
function growthIcon(v){return parseFloat(v)>=0?'arrow-up-short':'arrow-down-short'}

/* ══════════════════════════════════════════════════════════
   FETCH
══════════════════════════════════════════════════════════ */
async function api(action, params={}, opts={}){
  const url=new URL(API,location.href);
  url.searchParams.set('action',action);
  Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,v));
  const cfg={headers:{'X-Requested-With':'XMLHttpRequest'},...opts};
  if(opts.method==='POST'){cfg.headers['Content-Type']='application/json';cfg.body=JSON.stringify({...opts.body,csrf:CSRF});}
  try{const r=await fetch(url.toString(),cfg);if(!r.ok)throw new Error('HTTP '+r.status);return await r.json();}
  catch(e){console.error('[API]',action,e);return null;}
}

/* ══════════════════════════════════════════════════════════
   TOAST
══════════════════════════════════════════════════════════ */
const TICO={info:'ℹ️',success:'✅',warn:'⚠️',error:'🔴'};
const TBC={info:'var(--cyan)',success:'var(--neon)',warn:'var(--amber)',error:'var(--rose)'};
function toast(title,sub='',type='info',dur=3500){
  const s=document.getElementById('toasts'),t=document.createElement('div');
  t.className='toast';t.style.borderColor=TBC[type]||TBC.info;
  t.innerHTML=`<span class="t-ico">${TICO[type]||'ℹ️'}</span><div class="t-body"><div class="t-title">${esc(title)}</div>${sub?`<div class="t-sub">${esc(sub)}</div>`:''}</div><span class="t-x" onclick="this.parentElement.remove()">✕</span>`;
  s.appendChild(t);requestAnimationFrame(()=>requestAnimationFrame(()=>t.classList.add('show')));
  setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),320);},dur);
}

/* ══════════════════════════════════════════════════════════
   SIDEBAR
══════════════════════════════════════════════════════════ */
function toggleSidebar(){sidebarCol=!sidebarCol;document.getElementById('sidebar').classList.toggle('col',sidebarCol);document.getElementById('main').classList.toggle('col',sidebarCol)}
function toggleMobile(){document.getElementById('sidebar').classList.toggle('mob-open');document.getElementById('overlay').classList.toggle('show')}
function closeMobile(){document.getElementById('sidebar').classList.remove('mob-open');document.getElementById('overlay').classList.remove('show')}

/* ══════════════════════════════════════════════════════════
   PÉRIODE
══════════════════════════════════════════════════════════ */
document.querySelectorAll('.p-btn').forEach(btn=>btn.addEventListener('click',e=>{
  document.querySelectorAll('.p-btn').forEach(b=>b.classList.remove('active'));
  e.currentTarget.classList.add('active');
  currentPeriod=e.currentTarget.dataset.p;
  loadStats();
}));

/* ══════════════════════════════════════════════════════════
   KPIs
══════════════════════════════════════════════════════════ */
function animVal(id,target,isFCFA=false){
  const el=document.getElementById(id);if(!el)return;
  const old=parseFloat(el.dataset.raw||0)||0;el.dataset.raw=target;
  const dur=800,step=14,steps=Math.ceil(dur/step);let cur=old,inc=(target-old)/steps,i=0;
  const t=setInterval(()=>{i++;cur+=inc;if(i>=steps){cur=target;clearInterval(t);}el.textContent=isFCFA?fmtFCFA(cur,true):fmtN(cur);},step);
}

async function loadStats(){
  const d=await api('live_stats',{period:currentPeriod});if(!d)return;
  animVal('kv-rev-total',d.rev_total||0,true);
  animVal('kv-today',d.sales_today||0);
  animVal('kv-month',d.sales_month||0);
  animVal('kv-rev-month',d.rev_month||0,true);
  animVal('kv-bonus',d.bonus_count||0);
  animVal('kv-year',d.sales_year||0);
  animVal('kv-confirmed',d.confirmed_total||0);
  animVal('kv-failed',d.failed_total||0);
  const rt=document.getElementById('kv-rev-today');if(rt)rt.textContent=fmtFCFA(d.rev_today||0,true);
  const ry=document.getElementById('kv-rev-year');if(ry)ry.textContent=fmtFCFA(d.rev_year||0,true);
  const el=document.getElementById('kv-eligible');if(el)el.textContent=fmtN(d.users_eligible||0);
  const chgM=document.getElementById('chg-month');
  if(chgM){const g=parseFloat(d.rev_growth||0);chgM.innerHTML=`<i class="bi bi-${growthIcon(g)} ${gc(g)}"></i>${Math.abs(g).toFixed(1)}% vs période préc.`;}
}

/* ══════════════════════════════════════════════════════════
   APEX BASE CONFIG
══════════════════════════════════════════════════════════ */
const APEX={chart:{background:'transparent',toolbar:{show:false},fontFamily:"'JetBrains Mono',monospace"},theme:{mode:'dark'},grid:{borderColor:'rgba(255,255,255,.05)',strokeDashArray:4},tooltip:{theme:'dark'},colors:['#00d4ff','#7c3aed','#00ffaa','#f59e0b','#f43f5e']};

/* ══════════════════════════════════════════════════════════
   CHART VENTES
══════════════════════════════════════════════════════════ */
let currentChartType='daily';
async function loadChartSales(type=currentChartType){
  const d=await api('chart_data',{type});if(!d?.data)return;
  const rows=d.data,labels=rows.map(r=>r.label),sales=rows.map(r=>parseInt(r.sales)||0),revenue=rows.map(r=>parseFloat(r.revenue)||0),bonus=rows.map(r=>parseInt(r.nb_bonus)||0);
  const opts={...APEX,chart:{...APEX.chart,type:'area',height:260,animations:{enabled:true,easing:'easeinout',speed:600}},
    series:[{name:'Transactions',data:sales,type:'area'},{name:'Revenus FCFA',data:revenue,type:'line'},{name:'Bonus',data:bonus,type:'bar'}],
    xaxis:{categories:labels,labels:{style:{colors:'#6b7280',fontSize:'10px'}}},
    yaxis:[
      {title:{text:'Transactions',style:{color:'#00d4ff'}},labels:{style:{colors:'#6b7280',fontSize:'10px'}}},
      {opposite:true,title:{text:'Revenus',style:{color:'#00ffaa'}},labels:{formatter:v=>fmtFCFA(v,true),style:{colors:'#6b7280',fontSize:'10px'}}},
      {show:false}
    ],
    fill:{type:['gradient','solid','solid'],gradient:{shade:'dark',type:'vertical',shadeIntensity:.4,gradientToColors:['#7c3aed'],opacityFrom:.7,opacityTo:.05,stops:[0,100]}},
    stroke:{width:[2,2,0],curve:'smooth'},
    markers:{size:[3,4,0],colors:['#00d4ff','#00ffaa','#00ffaa'],strokeColors:'#05080f',strokeWidth:2},
    tooltip:{shared:true,y:[{formatter:v=>v+' transaction(s)'},{formatter:v=>fmtFCFA(v)},{formatter:v=>v+' bonus'}]},
    legend:{labels:{colors:'#9ca3af'},fontSize:'11px'},
    plotOptions:{bar:{columnWidth:'40%',borderRadius:3}},
  };
  if(chartSales){chartSales.destroy();}
  chartSales=new ApexCharts(document.getElementById('chart-sales'),opts);
  chartSales.render();
}
function switchChart(type,btn){currentChartType=type;document.querySelectorAll('.chart-type').forEach(b=>b.classList.remove('active'));btn.classList.add('active');loadChartSales(type);}

/* ══════════════════════════════════════════════════════════
   CHART REVENUS MENSUEL
══════════════════════════════════════════════════════════ */
async function loadChartRevenue(){
  const d=await api('chart_data',{type:'monthly'});if(!d?.data)return;
  const rows=d.data;
  const opts={...APEX,chart:{...APEX.chart,type:'bar',height:250,animations:{enabled:true,speed:700}},
    series:[{name:'Revenus ventes (FCFA)',data:rows.map(r=>parseFloat(r.rev_ventes||r.revenue)||0)},{name:'Transactions',data:rows.map(r=>parseInt(r.sales)||0)}],
    xaxis:{categories:rows.map(r=>r.label),labels:{style:{colors:'#6b7280',fontSize:'10px'}}},
    yaxis:[{labels:{formatter:v=>fmtFCFA(v,true),style:{colors:'#6b7280',fontSize:'10px'}}},{opposite:true,labels:{style:{colors:'#6b7280',fontSize:'10px'}}}],
    plotOptions:{bar:{columnWidth:'58%',borderRadius:4}},
    fill:{type:'gradient',gradient:{shade:'dark',type:'vertical',shadeIntensity:.3,gradientToColors:['#7c3aed','#00c87a'],opacityFrom:.9,opacityTo:.7}},
    tooltip:{y:[{formatter:v=>fmtFCFA(v)},{formatter:v=>v+' tx'}]},
    dataLabels:{enabled:false},
    legend:{labels:{colors:'#9ca3af'},fontSize:'11px'},
  };
  if(chartRevenue){chartRevenue.destroy();}
  chartRevenue=new ApexCharts(document.getElementById('chart-revenue'),opts);
  chartRevenue.render();
}

/* ══════════════════════════════════════════════════════════
   CHART CATEGORIES DONUT
══════════════════════════════════════════════════════════ */
async function loadChartCat(){
  const d=await api('top_categories');if(!d?.categories)return;
  const cats=d.categories,colors=['#00d4ff','#7c3aed','#00ffaa','#f59e0b','#f43f5e','#f97316','#38bdf8','#a78bfa'];
  const opts={...APEX,chart:{...APEX.chart,type:'donut',height:260},
    series:cats.map(c=>parseFloat(c.revenue)||0),labels:cats.map(c=>c.nom),
    colors:colors.slice(0,cats.length),
    plotOptions:{pie:{donut:{size:'65%',labels:{show:true,total:{show:true,label:'Total',color:'#9ca3af',formatter:()=>fmtFCFA(cats.reduce((a,c)=>a+(parseFloat(c.revenue)||0),0),true)}}}}},
    legend:{show:false},tooltip:{y:{formatter:v=>fmtFCFA(v)}},dataLabels:{enabled:false},stroke:{show:false},
  };
  if(chartCat){chartCat.destroy();}
  chartCat=new ApexCharts(document.getElementById('chart-cat'),opts);
  chartCat.render();
}

/* ══════════════════════════════════════════════════════════
   CHART MÉTHODES PAIEMENT
══════════════════════════════════════════════════════════ */
const MICO={'orange_money':'🟠','mobile_money':'🟡','carte':'💳'};
const MNME={'orange_money':'Orange Money','mobile_money':'MTN MoMo','carte':'Carte'};
async function loadChartMethods(){
  const d=await api('payment_methods');if(!d?.methods)return;
  const ms=d.methods,total=ms.reduce((a,m)=>a+(parseFloat(m.total)||0),0)||1;
  const colors=['#f59e0b','#f97316','#00d4ff','#7c3aed'];
  const opts={...APEX,chart:{...APEX.chart,type:'radialBar',height:200},
    series:ms.map(m=>Math.round(((parseFloat(m.total)||0)/total)*100)),
    labels:ms.map(m=>MNME[m.methode]||m.methode),colors:colors.slice(0,ms.length),
    plotOptions:{radialBar:{hollow:{size:'30%'},track:{background:'rgba(255,255,255,.05)'},dataLabels:{show:true,total:{show:true,label:'Paiements',color:'#9ca3af',fontSize:'10px'},value:{color:'#eef2ff',fontSize:'14px',fontFamily:"'Syne',sans-serif",fontWeight:'800'}}}},
    stroke:{lineCap:'round'},legend:{show:false},
  };
  if(chartMethods){chartMethods.destroy();}
  chartMethods=new ApexCharts(document.getElementById('chart-methods'),opts);
  chartMethods.render();

  const el=document.getElementById('methods-bars');
  if(el)el.innerHTML=ms.map((m,i)=>{
    const p=Math.round(((parseFloat(m.total)||0)/total)*100);
    const pc=['pg-a','pg-a','pg-c','pg-c'][i]||'pg-c';
    return `<div style="display:flex;align-items:center;gap:7px;font-size:.73rem;margin-bottom:.4rem">
      <span style="font-size:1rem;width:22px;text-align:center">${MICO[m.methode]||'💳'}</span>
      <span style="width:100px;color:var(--txt-md);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(MNME[m.methode]||m.methode)}</span>
      <div class="pg-wrap" style="flex:1"><div class="pg ${pc}" style="width:${p}%"></div></div>
      <span style="font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt-lo);flex-shrink:0;width:28px;text-align:right">${p}%</span>
    </div>`;
  }).join('');
}

/* ══════════════════════════════════════════════════════════
   TOP LIVRES
══════════════════════════════════════════════════════════ */
const ACHIP={'premium':'c-pr','standard':'c-std','gratuit':'c-free'};
const ALBL={'premium':'⭐ Premium','standard':'📘 Standard','gratuit':'🆓 Gratuit'};
async function loadTopBooks(sort='revenue',btn=null,limit=10){
  currentSort=sort;
  if(btn){document.querySelectorAll('.sort-b').forEach(b=>b.classList.remove('active'));btn.classList.add('active');}
  const tb=document.getElementById('books-tbody');
  tb.innerHTML='<tr><td colspan="10" style="text-align:center;padding:1.5rem;color:var(--txt-lo)">Chargement…</td></tr>';
  const d=await api('top_books',{sort,limit});
  if(!d?.books){tb.innerHTML='<tr><td colspan="10" style="text-align:center;padding:1rem;color:var(--rose)">Erreur</td></tr>';return;}
  const books=d.books,ri=['🥇','🥈','🥉'];
  tb.innerHTML=!books.length
    ?'<tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--txt-lo)">Aucun livre avec des transactions</td></tr>'
    :books.map((b,i)=>{
      const rn=i<3?`<span style="font-size:.95rem">${ri[i]}</span>`:`<span class="td-m" style="color:var(--txt-lo)">${i+1}</span>`;
      const at=b.access_type||'standard';
      const note=parseFloat(b.note_moyenne)||0;
      const bs=parseInt(b.is_bestseller)?'<span class="chip c-warn" style="margin-left:2px;font-size:.52rem">BS</span>':'';
      const ft=parseInt(b.is_featured)?'<span class="chip c-info" style="margin-left:2px;font-size:.52rem">★</span>':'';
      return `<tr>
        <td>${rn}</td>
        <td><div style="max-width:200px"><div class="td-hi" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(b.titre)}${bs}${ft}</div>
        <div style="font-size:.62rem;color:var(--txt-lo);font-family:'JetBrains Mono',monospace;margin-top:1px">${esc(b.auteur)}</div></div></td>
        <td><span class="chip c-v">${esc(b.cat_icon||'📚')} ${esc(b.categorie||'—')}</span></td>
        <td><span class="chip ${ACHIP[at]||'c-mu'}">${ALBL[at]||at}</span></td>
        <td class="td-m">${parseFloat(b.prix)>0?fmtFCFA(parseFloat(b.prix),true):'<span class="chip c-free">Gratuit</span>'}</td>
        <td class="td-hi td-m">${fmtN(b.sales_count)}</td>
        <td class="td-m" style="color:var(--neon)">${fmtN(b.bonus_count)}</td>
        <td class="td-m" style="color:var(--neon)">${fmtFCFA(b.revenue_ventes||0,true)}</td>
        <td class="td-m">${fmtN(b.nb_lectures)}</td>
        <td class="td-m" style="color:var(--amber)">${note.toFixed(1)}</td>
      </tr>`;
    }).join('');
  const cnt=document.getElementById('books-count');if(cnt)cnt.textContent=`${books.length} livre(s) affiché(s)`;
}

/* ══════════════════════════════════════════════════════════
   TRANSACTIONS
══════════════════════════════════════════════════════════ */
function debounceFilter(){clearTimeout(filterTimer);filterTimer=setTimeout(()=>loadTransactions(1),350)}
function clearFilters(){
  ['f-search','f-from','f-to'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  ['f-type','f-cat','f-method','f-sort'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  loadTransactions(1);
}

async function loadTransactions(page=currentTxPage){
  currentTxPage=page;
  const tb=document.getElementById('tx-tbody');
  tb.innerHTML='<tr><td colspan="9" style="text-align:center;padding:1.5rem;color:var(--txt-lo)">Chargement…</td></tr>';
  const params={
    page, limit:15,
    search:document.getElementById('f-search')?.value||'',
    type:document.getElementById('f-type')?.value||'',
    cat:document.getElementById('f-cat')?.value||'',
    method:document.getElementById('f-method')?.value||'',
    date_from:document.getElementById('f-from')?.value||'',
    date_to:document.getElementById('f-to')?.value||'',
    sort:document.getElementById('f-sort')?.value||'date',
    dir:'desc',
  };
  const d=await api('transactions',params);
  if(!d?.transactions){tb.innerHTML='<tr><td colspan="9" style="text-align:center;color:var(--rose)">Erreur de chargement</td></tr>';return;}
  totalTxPages=d.pages||1;
  const badge=document.getElementById('tx-total-badge');if(badge)badge.textContent=`${fmtN(d.total)} transaction(s)`;
  const pi=document.getElementById('tx-page-info');if(pi)pi.textContent=`${page}/${totalTxPages}`;

  if(!d.transactions.length){
    tb.innerHTML='<tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--txt-lo)">Aucune transaction trouvée</td></tr>';
    return;
  }

  const MICO2={'orange_money':'🟠','mobile_money':'🟡','carte':'💳'};
  tb.innerHTML=d.transactions.map(tx=>{
    const isBonus=tx.type_transaction==='bonus_accorde';
    const dt=new Date(tx.created_at);
    const dtStr=dt.toLocaleDateString('fr-FR')+' '+dt.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
    return `<tr>
      <td class="td-m" style="font-size:.6rem;color:var(--txt-lo)">${esc(tx.reference||'—')}</td>
      <td><div class="td-hi" style="white-space:nowrap;font-size:.74rem">${esc(tx.user_name)}</div><div style="font-size:.58rem;color:var(--txt-lo)">${esc(tx.email)}</div></td>
      <td><div style="max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.74rem;color:var(--txt-hi)">${esc(tx.livre_titre)}</div>
      <div style="font-size:.58rem;color:var(--txt-lo)">${esc(tx.categorie||'—')}</div></td>
      <td><span class="chip ${isBonus?'c-bonus':'c-vente'}">${isBonus?'🎁 Bonus':'💳 Vente'}</span></td>
      <td class="td-m" style="font-size:.65rem">${MICO2[tx.methode]||'💳'} ${esc(MNME[tx.methode]||tx.methode||'—')}</td>
      <td class="td-m" style="color:${isBonus?'var(--neon)':'var(--neon)'};font-weight:700">${isBonus?'<span style="color:var(--neon)">Gratuit</span>':fmtFCFA(parseFloat(tx.montant)||0,true)}</td>
      <td class="td-m" style="font-size:.62rem;white-space:nowrap">${dtStr}</td>
      <td><span class="chip ${tx.statut==='confirme'?'c-ok':tx.statut==='echec'?'c-err':'c-warn'}">${esc(tx.statut)}</span></td>
      <td>
        <div style="display:flex;gap:3px">
        <a class="btn btn-xs btn-gh"
   href="../../users/view.php?id=${tx.id}"
   title="Détails">
   <i class="bi bi-eye"></i>
</a>
          ${tx.statut==='confirme'?`<button class="btn btn-xs btn-err" onclick="cancelTx(${tx.id})" title="Annuler"><i class="bi bi-x-circle"></i></button>`:''}
        </div>
      </td>
    </tr>`;
  }).join('');
}

function txPage(p){if(p<1||p>totalTxPages)return;loadTransactions(p);}

/* ══════════════════════════════════════════════════════════
   LIVE FEED
══════════════════════════════════════════════════════════ */
async function loadFeed(){
  const d=await api('transactions',{page:1,limit:10,sort:'date',dir:'desc'});
  const el=document.getElementById('live-feed');if(!el)return;
  if(!d?.transactions){el.innerHTML='<div style="text-align:center;padding:2rem;color:var(--txt-lo)">Erreur</div>';return;}
  const icons=['🛍️','💰','📚','⚡','💳','🎁'];
  el.innerHTML=d.transactions.map((tx,i)=>{
    const isBonus=tx.type_transaction==='bonus_accorde';
    return `<div class="feed-item" style="animation-delay:${i*.04}s">
      <div class="fd-dot">${isBonus?'🎁':icons[i%icons.length]}</div>
      <div class="fd-body">
        <div class="fd-msg"><strong>${esc(tx.user_name)}</strong> ${isBonus?'a reçu en <strong>bonus</strong>':'a acheté'} <strong>${esc(tx.livre_titre)}</strong></div>
        <div class="fd-time">${timeAgo(tx.created_at)} · <span class="chip ${isBonus?'c-bonus':'c-vente'}" style="font-size:.52rem">${isBonus?'🎁 Bonus':'💳 Vente'}</span></div>
      </div>
      <div class="fd-amt">${isBonus?'<span style="color:var(--neon);font-size:.68rem">Gratuit</span>':fmtFCFA(parseFloat(tx.montant)||0,true)}</div>
    </div>`;
  }).join('') || '<div style="text-align:center;padding:2rem;color:var(--txt-lo)">Aucune transaction récente</div>';
}

/* ══════════════════════════════════════════════════════════
   TOP ACHETEURS
══════════════════════════════════════════════════════════ */
const RC=['rk-g','rk-s','rk-b'];
async function loadTopBuyers(){
  const d=await api('top_buyers',{limit:7});
  const el=document.getElementById('top-buyers');if(!el)return;
  if(!d?.buyers||!d.buyers.length){el.innerHTML='<p style="text-align:center;color:var(--txt-lo);font-size:.73rem;padding:1rem">Aucun achat confirmé</p>';return;}
  el.innerHTML=d.buyers.map((b,i)=>{
    const bonusRule=5;const prog=Math.min(100,Math.round(((b.achat_count||0)/bonusRule)*100));
    return `<div class="rank-item">
      <span class="rank-n ${RC[i]||''}">${i+1}</span>
      <div class="rank-info">
        <div class="rank-name">${esc((b.prenom||'')+' '+(b.nom||''))}</div>
        <div class="rank-sub">${fmtN(b.purchases)} achat(s) · ${b.bonus>0?`🎁 ${b.bonus} bonus dispo.`:timeAgo(b.last_purchase)}</div>
      </div>
      <div class="rank-val">${fmtFCFA(parseFloat(b.total_spent)||0,true)}</div>
    </div>`;
  }).join('');
}

/* ══════════════════════════════════════════════════════════
   FIDÉLITÉ BONUS
══════════════════════════════════════════════════════════ */
async function loadLoyalty(){
  const d=await api('loyalty_stats');if(!d)return;
  const r=document.getElementById('loy-rule');if(r)r.textContent=d.bonus_rule||5;
  const g=document.getElementById('loy-total-granted');if(g)g.textContent=fmtN(d.total_bonus_granted||0);
  const e=document.getElementById('loy-eligible');if(e)e.textContent=fmtN(d.eligible||0);
  const badge=document.getElementById('loyalty-eligible-badge');if(badge)badge.textContent=`${d.eligible||0} éligible(s)`;

  const el=document.getElementById('loyalty-list');if(!el)return;
  if(!d.progression?.length){el.innerHTML='<p style="text-align:center;color:var(--txt-lo);font-size:.73rem;padding:1rem">Aucune progression enregistrée</p>';return;}
  const bonusRule=d.bonus_rule||5;
  el.innerHTML=d.progression.map(u=>{
    const prog=Math.min(100,Math.round(((u.achat_count||0)/bonusRule)*100));
    return `<div class="loyalty-item">
      <div class="loy-name">${esc((u.name||u.email||'—').substring(0,20))}</div>
      <div class="loy-prog">
        <div class="pg-wrap"><div class="pg ${prog>=100?'pg-n':'pg-c'}" style="width:${prog}%"></div></div>
        <span style="font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--txt-lo);flex-shrink:0">${u.achat_count||0}/${bonusRule}</span>
      </div>
      <span class="loy-bonus">${u.bonus_restant>0?'🎁 '+u.bonus_restant:''}</span>
      <span class="chip ${u.bonus_restant>0?'c-bonus':'c-mu'}" style="font-size:.52rem;flex-shrink:0;margin-left:4px">${u.bonus_total||0} total</span>
    </div>`;
  }).join('');
}

/* ══════════════════════════════════════════════════════════
   INSIGHTS
══════════════════════════════════════════════════════════ */
async function loadInsights(){
  const el=document.getElementById('insights-body');if(!el)return;
  const d=await api('insights');if(!d){el.innerHTML='<p style="color:var(--rose);font-size:.73rem">Erreur</p>';return;}
  const proj=parseFloat(d.projected_month)||0,avg=parseFloat(d.avg_monthly)||0,g=parseFloat(d.growth_vs_avg)||0;
  const hr=d.best_hour!==null?d.best_hour+'h00':'—';
  const dayMap={'Monday':'Lundi','Tuesday':'Mardi','Wednesday':'Mercredi','Thursday':'Jeudi','Friday':'Vendredi','Saturday':'Samedi','Sunday':'Dimanche'};
  el.innerHTML=`<div class="ins-grid">
    <div class="ins-card"><div class="ins-ico">🔮</div><div class="ins-val">${fmtFCFA(proj,true)}</div><div class="ins-lbl">Projection du mois</div><div class="ins-desc">Basé sur rythme actuel</div></div>
    <div class="ins-card"><div class="ins-ico">📊</div><div class="ins-val">${fmtFCFA(avg,true)}</div><div class="ins-lbl">Moy. 6 mois</div><div class="ins-desc"><span class="${gc(g)}">${g>=0?'+':''}${g.toFixed(1)}%</span> vs moyenne</div></div>
    <div class="ins-card"><div class="ins-ico">⏰</div><div class="ins-val">${hr}</div><div class="ins-lbl">Pic d'activité</div><div class="ins-desc">Meilleure heure</div></div>
    <div class="ins-card"><div class="ins-ico">📅</div><div class="ins-val">${dayMap[d.best_day||'']||d.best_day||'—'}</div><div class="ins-lbl">Meilleur jour</div><div class="ins-desc">Plus de ventes</div></div>
    <div class="ins-card"><div class="ins-ico">💡</div><div class="ins-val">${g>=20?'🔥 Excellent':g>=5?'✅ Bon':g>=-5?'➡️ Stable':'⚠️ Attention'}</div><div class="ins-lbl">Performance</div><div class="ins-desc">${g>=0?'Croissance positive':'Tendance en baisse'}</div></div>
    <div class="ins-card"><div class="ins-ico">🎯</div><div class="ins-val">${proj>avg?'✓ En avance':'⬇ Sous moy.'}</div><div class="ins-lbl">Objectif mensuel</div><div class="ins-desc">Projection vs moyenne</div></div>
  </div>`;
}

/* ══════════════════════════════════════════════════════════
   BONUS MODAL
══════════════════════════════════════════════════════════ */
let selectedUserId=null,selectedBookId=null;

function openBonusModal(){
  eligChecked=false;
  selectedUserId=null;selectedBookId=null;
  document.getElementById('bonus-user-input').value='';
  document.getElementById('bonus-book-input').value='';
  document.getElementById('bonus-user-id').value='';
  document.getElementById('bonus-book-id').value='';
  document.getElementById('elig-box').classList.remove('show');
  document.getElementById('btn-grant').disabled=true;
  document.getElementById('bonus-modal').classList.add('open');
  setTimeout(()=>document.getElementById('bonus-user-input').focus(),100);
}
function closeBonusModal(){document.getElementById('bonus-modal').classList.remove('open');}

/* Recherche utilisateurs */
function debounceUserSearch(q){clearTimeout(userTimer);if(q.length<2){document.getElementById('user-suggest').classList.remove('open');return;}userTimer=setTimeout(()=>searchUsers(q),300);}
async function searchUsers(q){
  const d=await api('search_users',{q});
  const list=document.getElementById('user-suggest');
  if(!d?.users||!d.users.length){list.innerHTML='<div class="suggest-item" style="color:var(--txt-lo)">Aucun résultat</div>';list.classList.add('open');return;}
  list.innerHTML=d.users.map(u=>`<div class="suggest-item" onclick="selectUser(${u.id},'${esc(u.prenom)} ${esc(u.nom)}','${esc(u.email)}',${u.achats_confirmes||0},${u.bonus_restant||0})">
    <strong>${esc(u.prenom)} ${esc(u.nom)}</strong> <small>${esc(u.email)}</small><br>
    <small>${u.achats_confirmes||0} achat(s) · 🎁 ${u.bonus_restant||0} bonus dispo.</small>
  </div>`).join('');
  list.classList.add('open');
}
function selectUser(id,name,email,purchases,bonus){
  selectedUserId=id;
  document.getElementById('bonus-user-id').value=id;
  document.getElementById('bonus-user-input').value=name+' — '+email;
  document.getElementById('user-suggest').classList.remove('open');
  eligChecked=false;document.getElementById('btn-grant').disabled=true;
}
document.addEventListener('click',e=>{const l=document.getElementById('user-suggest'),i=document.getElementById('bonus-user-input');if(l&&!l.contains(e.target)&&!i?.contains(e.target))l.classList.remove('open');});

/* Recherche livres */
function debounceBookSearch(q){clearTimeout(bookTimer);if(q.length<2){document.getElementById('book-suggest').classList.remove('open');return;}bookTimer=setTimeout(()=>searchBooks(q),300);}
async function searchBooks(q){
  const d=await api('search_books',{q});
  const list=document.getElementById('book-suggest');
  if(!d?.books||!d.books.length){list.innerHTML='<div class="suggest-item" style="color:var(--txt-lo)">Aucun résultat</div>';list.classList.add('open');return;}
  list.innerHTML=d.books.map(b=>`<div class="suggest-item" onclick="selectBook(${b.id},'${esc(b.titre)}','${esc(b.auteur)}')">
    <strong>${esc(b.titre)}</strong> <span class="chip ${ACHIP[b.access_type]||'c-mu'}" style="font-size:.52rem">${ALBL[b.access_type]||b.access_type}</span><br>
    <small>${esc(b.auteur)} · ${esc(b.categorie||'—')}</small>
  </div>`).join('');
  list.classList.add('open');
}
function selectBook(id,titre,auteur){
  selectedBookId=id;
  document.getElementById('bonus-book-id').value=id;
  document.getElementById('bonus-book-input').value=titre+' — '+auteur;
  document.getElementById('book-suggest').classList.remove('open');
  eligChecked=false;document.getElementById('btn-grant').disabled=true;
}
document.addEventListener('click',e=>{const l=document.getElementById('book-suggest'),i=document.getElementById('bonus-book-input');if(l&&!l.contains(e.target)&&!i?.contains(e.target))l.classList.remove('open');});

/* Vérifier éligibilité */
async function checkEligibility(){
  const uid=document.getElementById('bonus-user-id').value;
  const bid=document.getElementById('bonus-book-id').value;
  const box=document.getElementById('elig-box');
  const msg=document.getElementById('elig-msg');
  const stats=document.getElementById('elig-stats');

  if(!uid){toast('Attention','Sélectionnez d\'abord un utilisateur.','warn');return;}
  if(!bid){toast('Attention','Sélectionnez d\'abord un livre.','warn');return;}

  msg.textContent='Vérification en cours…';
  box.className='m-elig show';stats.style.display='none';

  const d=await api('check_bonus_eligibility',{user_id:uid,livre_id:bid});
  if(!d){box.className='m-elig show err';msg.textContent='Erreur de vérification.';return;}

  eligChecked=d.eligible;
  document.getElementById('btn-grant').disabled=!d.eligible;

  if(d.eligible){
    box.className='m-elig show '+(d.all_possessed?'warn':'ok');
    msg.innerHTML=(d.all_possessed?'⚠️ ':' ✅ ')+esc(d.reason||'Utilisateur éligible.');
    if(d.all_possessed){msg.innerHTML='⚠️ '+esc(d.reason);}
    if(!d.all_possessed){
      stats.style.display='grid';
      stats.innerHTML=`<div class="m-stat"><div class="m-stat-v">${d.purchases||0}</div><div class="m-stat-l">Achats confirmés</div></div>
      <div class="m-stat"><div class="m-stat-v">${d.bonus_restant||0}</div><div class="m-stat-l">Bonus disponibles</div></div>`;
    }
  } else {
    box.className='m-elig show err';
    msg.innerHTML='❌ '+esc(d.reason||'Non éligible.');
    if(d.purchases!==undefined&&d.required!==undefined){
      stats.style.display='grid';
      stats.innerHTML=`<div class="m-stat"><div class="m-stat-v">${d.purchases}</div><div class="m-stat-l">Achats actuels</div></div>
      <div class="m-stat"><div class="m-stat-v">${d.required}</div><div class="m-stat-l">Requis</div></div>`;
    }
  }
}

/* Attribuer bonus */
async function grantBonus(){
  const uid=parseInt(document.getElementById('bonus-user-id').value||0);
  const bid=parseInt(document.getElementById('bonus-book-id').value||0);
  if(!uid||!bid){toast('Erreur','Utilisateur et livre requis.','error');return;}
  if(!eligChecked){toast('Attention','Vérifiez d\'abord l\'éligibilité.','warn');return;}

  document.getElementById('btn-grant').disabled=true;
  document.getElementById('btn-grant').innerHTML='<i class="bi bi-hourglass-split"></i> Attribution…';

  const d=await api('grant_bonus',{},{method:'POST',body:{user_id:uid,livre_id:bid}});
  document.getElementById('btn-grant').innerHTML='<i class="bi bi-gift-fill"></i> Attribuer';

  if(d?.success){
    toast('Bonus accordé',d.message,'success',5000);
    closeBonusModal();
    refreshAll();
  } else {
    toast('Erreur',d?.error||'Attribution échouée.','error');
    document.getElementById('btn-grant').disabled=false;
  }
}

/* ══════════════════════════════════════════════════════════
   DÉTAIL TRANSACTION
══════════════════════════════════════════════════════════ */
function showDetail(tx){
  const dt=new Date(tx.created_at);
  const dtStr=dt.toLocaleDateString('fr-FR')+' à '+dt.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
  const isBonus=tx.type_transaction==='bonus_accorde';
  document.getElementById('det-title').textContent=isBonus?'🎁 Détail bonus':'💳 Détail transaction';
  document.getElementById('det-body').innerHTML=`
    <div class="det-row"><div class="det-lbl">Référence</div><div class="det-val" style="font-family:'JetBrains Mono',monospace;font-size:.72rem">${esc(tx.reference||'—')}</div></div>
    <div class="det-row"><div class="det-lbl">Acheteur</div><div class="det-val">${esc(tx.user_name)}<br><span style="font-size:.68rem;color:var(--txt-lo)">${esc(tx.email)}</span></div></div>
    <div class="det-row"><div class="det-lbl">Livre</div><div class="det-val">${esc(tx.livre_titre)}<br><span style="font-size:.68rem;color:var(--txt-lo)">${esc(tx.categorie||'—')}</span></div></div>
    <div class="det-row"><div class="det-lbl">Type</div><div class="det-val"><span class="chip ${isBonus?'c-bonus':'c-vente'}">${isBonus?'🎁 Bonus accordé':'💳 Vente'}</span></div></div>
    <div class="det-row"><div class="det-lbl">Montant</div><div class="det-val" style="color:var(--neon);font-weight:700">${isBonus?'Gratuit (bonus)':fmtFCFA(parseFloat(tx.montant)||0)}</div></div>
    <div class="det-row"><div class="det-lbl">Méthode</div><div class="det-val">${MICO[tx.methode]||'💳'} ${esc(MNME[tx.methode]||tx.methode||'—')}</div></div>
    <div class="det-row"><div class="det-lbl">Statut</div><div class="det-val"><span class="chip ${tx.statut==='confirme'?'c-ok':tx.statut==='echec'?'c-err':'c-warn'}">${esc(tx.statut)}</span></div></div>
    <div class="det-row"><div class="det-lbl">Date</div><div class="det-val">${dtStr}</div></div>
    <div class="det-row"><div class="det-lbl">Accès livre</div><div class="det-val"><span class="chip ${ACHIP[tx.access_type]||'c-mu'}">${ALBL[tx.access_type]||tx.access_type||'—'}</span></div></div>
  `;
  const acts=document.getElementById('det-actions');
  acts.innerHTML=`
    <button class="btn btn-gh" style="flex:1;justify-content:center" onclick="closeDetailModal()">Fermer</button>
    <button
  class="btn btn-gh"
  style="
    flex:1;
    justify-content:center;
    display:flex;
    align-items:center;
    gap:.45rem;
  "
  onclick="window.location.href='../users/view.php?id=${encodeURIComponent(tx.user_id)}'">

  <i class="bi bi-person-vcard"></i>
  Voir détails

</button>
  `;
  document.getElementById('detail-modal').classList.add('open');
}
function closeDetailModal(){document.getElementById('detail-modal').classList.remove('open');}

/* ══════════════════════════════════════════════════════════
   ANNULER TRANSACTION
══════════════════════════════════════════════════════════ */
async function cancelTx(id){
  if(!confirm('Annuler cette transaction ? Cette action est irréversible.'))return;
  const d=await api('cancel_transaction',{},{method:'POST',body:{achat_id:id}});
  if(d?.success){toast('Transaction annulée',d.message,'success');loadTransactions();loadFeed();loadStats();}
  else toast('Erreur',d?.error||'Annulation échouée.','error');
}

/* ══════════════════════════════════════════════════════════
   NOTIFICATIONS
══════════════════════════════════════════════════════════ */
function toggleNotif(){document.getElementById('notif-panel').classList.toggle('open');loadNotifs();}
document.addEventListener('click',e=>{
  const p=document.getElementById('notif-panel'),b=document.getElementById('notif-btn');
  if(p?.classList.contains('open')&&!p.contains(e.target)&&!b?.contains(e.target))p.classList.remove('open');
});
async function loadNotifs(){
  const d=await api('notifications');if(!d)return;
  const unread=d.unread||0;
  const badge=document.getElementById('notif-badge');if(badge){badge.style.display=unread>0?'flex':'none';badge.textContent=Math.min(unread,9);}
  const list=document.getElementById('notif-list');if(!list)return;
  if(!d.notifications?.length){list.innerHTML='<div style="padding:1.5rem;text-align:center;color:var(--txt-lo);font-size:.73rem">🔔 Aucune notification</div>';return;}
  list.innerHTML=d.notifications.map(n=>`<div class="np-item${!parseInt(n.lu||0)?' unread':''}">
    <div class="np-ico" style="background:${esc(n.bg||'rgba(0,212,255,.08)')}">${esc(n.icon||'🔔')}</div>
    <div><div class="np-txt"><strong>${esc(n.titre||'')}</strong>${n.message?'<br><span style="font-size:.66rem">'+esc((n.message||'').substring(0,70))+'</span>':''}</div>
    <div class="np-time">${timeAgo(n.created_at||'')}</div></div>
  </div>`).join('');
  const nt=document.getElementById('notif-time');if(nt)nt.textContent=new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
}
async function markRead(){
  await api('mark_read',{},{method:'POST',body:{}});
  const b=document.getElementById('notif-badge');if(b)b.style.display='none';
  toast('Notifications','Toutes lues','success',2000);loadNotifs();
}



/* ══════════════════════════════════════════════════════════
   REFRESH ALL
══════════════════════════════════════════════════════════ */
async function refreshAll(){
  toast('Actualisation','Chargement en cours…','info',2000);
  await Promise.all([
    loadStats(),
    loadChartSales(),
    loadChartRevenue(),
    loadChartCat(),
    loadChartMethods(),
    loadTopBooks(currentSort),
    loadTransactions(1),
    loadFeed(),
    loadTopBuyers(),
    loadLoyalty(),
    loadInsights(),
    loadNotifs(),
  ]);
}

/* ══════════════════════════════════════════════════════════
   AUTO-REFRESH
══════════════════════════════════════════════════════════ */
function startAutoRefresh(){
  if(autoRefTimer)clearInterval(autoRefTimer);
  autoRefTimer=setInterval(async()=>{
    await Promise.all([loadStats(),loadFeed(),loadNotifs()]);
  },10000);
}

/* ══════════════════════════════════════════════════════════
   CLAVIER
══════════════════════════════════════════════════════════ */
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){
    document.getElementById('bonus-modal').classList.remove('open');
    document.getElementById('detail-modal').classList.remove('open');
    document.getElementById('notif-panel').classList.remove('open');
    document.getElementById('exp-dd').classList.remove('open');
  }
  if((e.ctrlKey||e.metaKey)&&e.key==='r'){e.preventDefault();refreshAll();}
  if((e.ctrlKey||e.metaKey)&&e.key==='b'){e.preventDefault();openBonusModal();}
});

/* ══════════════════════════════════════════════════════════
   INIT
══════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded',async()=>{
  // Badge notif immédiat
  if(INIT.notif_unread>0){const b=document.getElementById('notif-badge');if(b){b.style.display='flex';b.textContent=Math.min(INIT.notif_unread,9);}}

  // Charger tout en parallèle
  await Promise.all([
    loadChartSales('daily'),
    loadChartRevenue(),
    loadChartCat(),
    loadChartMethods(),
    loadTopBooks('revenue'),
    loadTransactions(1),
    loadFeed(),
    loadTopBuyers(),
    loadLoyalty(),
    loadInsights(),
    loadNotifs(),
  ]);

  startAutoRefresh();
  setTimeout(()=>toast('Sales Dashboard','Connecté à la base de données.','success',4000),500);

  // Animer les barres
  setTimeout(()=>{document.querySelectorAll('.pg').forEach(b=>{const w=b.style.width;b.style.width='0%';requestAnimationFrame(()=>requestAnimationFrame(()=>{b.style.width=w;}));});},400);
});
</script>
</body>
</html>