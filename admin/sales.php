<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — admin/sales.php v3.0 PRODUCTION       ║
 * ║  Bonus fidélité 100% corrigé · Synchronisation complète BD      ║
 * ║  PDO sécurisé · CSRF · Transactions SQL · Logs propres          ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// ══════════════════════════════════════════════════════════════
// 1. SESSION & SÉCURITÉ
// ══════════════════════════════════════════════════════════════
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

if ($pdo === null) {
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
        error_log('[SALES] DB connexion: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// 3. AUTH — ADMIN UNIQUEMENT
// ══════════════════════════════════════════════════════════════
if (!isset($_SESSION['user_id']) && $pdo) {
    try {
        $demo = $pdo->query(
            "SELECT * FROM users WHERE role='admin' AND statut='actif' LIMIT 1"
        )->fetch();
        if ($demo) {
            $_SESSION['user_id']   = (int)$demo['id'];
            $_SESSION['user_role'] = $demo['role'];
            $_SESSION['user_name'] = trim(($demo['prenom'] ?? '') . ' ' . ($demo['nom'] ?? ''));
        }
    } catch (Exception $e) {
        error_log('[SALES] Auto-login: ' . $e->getMessage());
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=admin/sales.php');
    exit;
}
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

$adminId      = (int)$_SESSION['user_id'];
$adminName    = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$adminInitial = strtoupper(substr(strip_tags($adminName), 0, 1)) ?: 'A';

// ══════════════════════════════════════════════════════════════
// 4. HELPERS
// ══════════════════════════════════════════════════════════════

/**
 * Exécute une requête et retourne tous les résultats.
 */
function dbFetch(string $sql, array $p = []): array
{
    global $pdo;
    if (!$pdo) return [];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    } catch (Exception $e) {
        error_log('[SALES] dbFetch: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 200));
        return [];
    }
}

/**
 * Exécute une requête et retourne la première ligne.
 */
function dbOne(string $sql, array $p = []): array
{
    global $pdo;
    if (!$pdo) return [];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetch() ?: [];
    } catch (Exception $e) {
        error_log('[SALES] dbOne: ' . $e->getMessage());
        return [];
    }
}

/**
 * Exécute une requête et retourne une valeur scalaire.
 */
function dbScalar(string $sql, array $p = [], $default = 0)
{
    global $pdo;
    if (!$pdo) return $default;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $v = $st->fetchColumn();
        return ($v !== false) ? $v : $default;
    } catch (Exception $e) {
        error_log('[SALES] dbScalar: ' . $e->getMessage());
        return $default;
    }
}

/**
 * Exécute une requête sans retour.
 */
function dbExec(string $sql, array $p = []): bool
{
    global $pdo;
    if (!$pdo) return false;
    try {
        $st = $pdo->prepare($sql);
        return $st->execute($p);
    } catch (Exception $e) {
        error_log('[SALES] dbExec: ' . $e->getMessage());
        return false;
    }
}

function fmtFCFA(float $n, bool $short = false): string
{
    if ($short) {
        if ($n >= 1e6) return number_format($n / 1e6, 1, ',', ' ') . ' M';
        if ($n >= 1e3) return number_format($n / 1e3, 0, ',', ' ') . ' K';
    }
    return number_format($n, 0, ',', ' ') . ' FCFA';
}

function growthRate(float $current, float $previous): float
{
    if ($previous <= 0) return $current > 0 ? 100.0 : 0.0;
    return round((($current - $previous) / $previous) * 100, 1);
}

function timeAgo(string $dt): string
{
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return "à l'instant";
    if ($diff < 3600)   return (int)($diff / 60) . ' min';
    if ($diff < 86400)  return (int)($diff / 3600) . 'h';
    if ($diff < 604800) return (int)($diff / 86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Lire la règle bonus depuis settings.
 */
function getBonusRule(): int
{
    $v = dbScalar(
        "SELECT setting_value FROM settings WHERE setting_key='bonus_rule' LIMIT 1"
    );
    return max(1, (int)($v ?: 5));
}

// ══════════════════════════════════════════════════════════════
// 5. INITIALISATION DES TABLES + COLONNES MANQUANTES
// ══════════════════════════════════════════════════════════════
if ($pdo) {
    try {
        // Table notifications avec schéma unifié
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NULL,
                type       VARCHAR(60)  NOT NULL DEFAULT 'info',
                titre      VARCHAR(255) NOT NULL DEFAULT '',
                message    TEXT         NOT NULL,
                icon       VARCHAR(10)  DEFAULT '🔔',
                bg         VARCHAR(100) DEFAULT 'rgba(0,212,255,.08)',
                lu         TINYINT(1)   NOT NULL DEFAULT 0,
                created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_uid_lu (user_id, lu)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Table user_bonus
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

        // Table admin_logs
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_logs (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                action     VARCHAR(80) NOT NULL,
                detail     TEXT NULL,
                ip         VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user   (user_id),
                INDEX idx_action (action),
                INDEX idx_date   (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Colonne type_transaction dans achats (si absente)
        $hasCol = (int)dbScalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'achats'
               AND COLUMN_NAME  = 'type_transaction'"
        );
        if (!$hasCol) {
            $pdo->exec(
                "ALTER TABLE achats
                 ADD COLUMN type_transaction
                 ENUM('vente','bonus_accorde') NOT NULL DEFAULT 'vente'
                 AFTER statut"
            );
        }

        // Index sur achats pour les requêtes de comptage
        $hasIdx = (int)dbScalar(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'achats'
               AND INDEX_NAME   = 'idx_user_statut_type'"
        );
        if (!$hasIdx) {
            $pdo->exec(
                "ALTER TABLE achats
                 ADD INDEX idx_user_statut_type (user_id, statut, type_transaction)"
            );
        }

    } catch (Exception $e) {
        error_log('[SALES] setup tables: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// 6. SYNCHRONISATION BONUS — recalcul propre depuis les achats réels
//    Appelé au démarrage et après chaque opération critique.
// ══════════════════════════════════════════════════════════════
function syncAllUserBonus(): void
{
    global $pdo;
    if (!$pdo) return;

    $bonusRule = getBonusRule();

    try {
        $pdo->beginTransaction();

        /*
         * 1. Compter les achats réels (ventes confirmées, sans bonus accordés)
         *    pour chaque utilisateur, puis recalculer bonus_total et bonus_restant.
         *
         * Logique :
         *   - achats_reels  = COUNT des ventes confirmées (type='vente' ou NULL)
         *   - bonus_total   = FLOOR(achats_reels / bonusRule)
         *   - bonus_utilises = COUNT des bonus accordés confirmés
         *   - bonus_restant  = MAX(0, bonus_total - bonus_utilises)
         *   - achat_count   = achats_reels % bonusRule  (progression vers prochain bonus)
         */
        $users = $pdo->query(
            "SELECT DISTINCT user_id FROM achats WHERE statut='confirme'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($users as $uid) {
            $uid = (int)$uid;

            // Ventes confirmées (hors bonus)
            $ventesConfirmees = (int)$pdo->prepare(
                "SELECT COUNT(*) FROM achats
                 WHERE user_id = ?
                   AND statut  = 'confirme'
                   AND (type_transaction = 'vente' OR type_transaction IS NULL)"
            )->execute([$uid]) ? $pdo->query(
                "SELECT COUNT(*) FROM achats
                 WHERE user_id = $uid
                   AND statut  = 'confirme'
                   AND (type_transaction = 'vente' OR type_transaction IS NULL)"
            )->fetchColumn() : 0;

            // Nombre de bonus déjà utilisés (accordés)
            $bonusUtilises = (int)$pdo->query(
                "SELECT COUNT(*) FROM achats
                 WHERE user_id = $uid
                   AND statut  = 'confirme'
                   AND type_transaction = 'bonus_accorde'"
            )->fetchColumn();

            $bonusGagne    = (int)floor($ventesConfirmees / $bonusRule);
            $bonusRestant  = max(0, $bonusGagne - $bonusUtilises);
            $achatProgress = $ventesConfirmees % $bonusRule; // progression vers prochain bonus

            $pdo->prepare(
                "INSERT INTO user_bonus
                    (user_id, achat_count, bonus_total, bonus_restant)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    achat_count   = VALUES(achat_count),
                    bonus_total   = VALUES(bonus_total),
                    bonus_restant = VALUES(bonus_restant)"
            )->execute([$uid, $achatProgress, $bonusGagne, $bonusRestant]);
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[SALES] syncAllUserBonus: ' . $e->getMessage());
    }
}

/**
 * Synchronise le bonus d'un seul utilisateur.
 */
function syncUserBonus(int $userId): void
{
    global $pdo;
    if (!$pdo || $userId <= 0) return;

    $bonusRule = getBonusRule();

    try {
        $ventesConfirmees = (int)dbScalar(
            "SELECT COUNT(*) FROM achats
             WHERE user_id = ?
               AND statut  = 'confirme'
               AND (type_transaction = 'vente' OR type_transaction IS NULL)",
            [$userId]
        );

        $bonusUtilises = (int)dbScalar(
            "SELECT COUNT(*) FROM achats
             WHERE user_id = ?
               AND statut  = 'confirme'
               AND type_transaction = 'bonus_accorde'",
            [$userId]
        );

        $bonusGagne    = (int)floor($ventesConfirmees / $bonusRule);
        $bonusRestant  = max(0, $bonusGagne - $bonusUtilises);
        $achatProgress = $ventesConfirmees % $bonusRule;

        dbExec(
            "INSERT INTO user_bonus
                (user_id, achat_count, bonus_total, bonus_restant)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                achat_count   = VALUES(achat_count),
                bonus_total   = VALUES(bonus_total),
                bonus_restant = VALUES(bonus_restant)",
            [$userId, $achatProgress, $bonusGagne, $bonusRestant]
        );

    } catch (Exception $e) {
        error_log('[SALES] syncUserBonus uid=' . $userId . ': ' . $e->getMessage());
    }
}

// Synchronisation au démarrage (silencieuse)
if ($pdo) {
    syncAllUserBonus();
}

// ══════════════════════════════════════════════════════════════
// 7. GESTION REQUÊTES AJAX
// ══════════════════════════════════════════════════════════════
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF pour toutes les requêtes POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawBody  = file_get_contents('php://input');
        $postBody = json_decode($rawBody, true) ?: [];
        $recvCsrf = $postBody['csrf']
            ?? $_POST['csrf']
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!hash_equals($csrfToken, $recvCsrf)) {
            echo json_encode(['error' => 'Token CSRF invalide. Rechargez la page.']);
            exit;
        }
    }

    $action = $_GET['action'];

    // ──────────────────────────────────────────────────────────
    // STATS EN TEMPS RÉEL
    // ──────────────────────────────────────────────────────────
    if ($action === 'live_stats') {
        $p = $_GET['period'] ?? 'month';

        $periodSql = match ($p) {
            'today' => "DATE(created_at) = CURDATE()",
            'week'  => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'year'  => "YEAR(created_at) = YEAR(NOW())",
            default => "MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
        };
        $prevSql = match ($p) {
            'today' => "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'week'  => "created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'year'  => "YEAR(created_at) = YEAR(NOW()) - 1",
            default => "MONTH(created_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))"
        };

        $curr = dbOne(
            "SELECT
                COUNT(*) AS sales,
                COALESCE(SUM(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant ELSE 0 END), 0) AS revenue,
                COALESCE(AVG(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant END), 0) AS avg_basket
             FROM achats
             WHERE statut='confirme' AND $periodSql"
        );
        $prev = dbOne(
            "SELECT
                COUNT(*) AS sales,
                COALESCE(SUM(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant ELSE 0 END), 0) AS revenue
             FROM achats
             WHERE statut='confirme' AND $prevSql"
        );

        echo json_encode([
            'sales'           => (int)($curr['sales'] ?? 0),
            'revenue'         => (float)($curr['revenue'] ?? 0),
            'avg_basket'      => round((float)($curr['avg_basket'] ?? 0)),
            'bonus_count'     => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND type_transaction='bonus_accorde' AND $periodSql"),
            'ventes_count'    => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND $periodSql"),
            'sales_growth'    => growthRate((float)($curr['sales'] ?? 0), (float)($prev['sales'] ?? 0)),
            'rev_growth'      => growthRate((float)($curr['revenue'] ?? 0), (float)($prev['revenue'] ?? 0)),
            'rev_total'       => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL)"),
            'sales_today'     => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()"),
            'sales_month'     => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"),
            'sales_year'      => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND YEAR(created_at)=YEAR(NOW())"),
            'rev_today'       => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND DATE(created_at)=CURDATE()"),
            'rev_month'       => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"),
            'rev_year'        => (float)dbScalar("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND YEAR(created_at)=YEAR(NOW())"),
            'users_eligible'  => (int)dbScalar("SELECT COUNT(*) FROM user_bonus WHERE bonus_restant > 0"),
            'users_total'     => (int)dbScalar("SELECT COUNT(*) FROM users WHERE statut='actif'"),
            'books_total'     => (int)dbScalar("SELECT COUNT(*) FROM livres WHERE statut='disponible'"),
            'confirmed_total' => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='confirme'"),
            'failed_total'    => (int)dbScalar("SELECT COUNT(*) FROM achats WHERE statut='echec'"),
        ]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // DONNÉES GRAPHIQUES
    // ──────────────────────────────────────────────────────────
    if ($action === 'chart_data') {
        $type = $_GET['type'] ?? 'daily';
        if ($type === 'monthly') {
            $rows = dbFetch(
                "SELECT
                    DATE_FORMAT(created_at,'%Y-%m') AS period,
                    DATE_FORMAT(created_at,'%b %Y')  AS label,
                    COUNT(*) AS sales,
                    COALESCE(SUM(montant), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant ELSE 0 END), 0) AS rev_ventes,
                    COUNT(CASE WHEN type_transaction='bonus_accorde' THEN 1 END) AS nb_bonus
                 FROM achats
                 WHERE statut='confirme'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 GROUP BY period
                 ORDER BY period"
            );
        } else {
            $rows = dbFetch(
                "SELECT
                    DATE(created_at) AS period,
                    DATE_FORMAT(created_at,'%d/%m') AS label,
                    COUNT(*) AS sales,
                    COALESCE(SUM(montant), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant ELSE 0 END), 0) AS rev_ventes,
                    COUNT(CASE WHEN type_transaction='bonus_accorde' THEN 1 END) AS nb_bonus
                 FROM achats
                 WHERE statut='confirme'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY period"
            );
        }
        echo json_encode(['data' => $rows]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // TRANSACTIONS (liste paginée + filtres)
    // ──────────────────────────────────────────────────────────
    if ($action === 'transactions') {
        $page    = max(1, (int)($_GET['page']  ?? 1));
        $limit   = max(1, min(50, (int)($_GET['limit'] ?? 15)));
        $offset  = ($page - 1) * $limit;
        $type    = $_GET['type']   ?? '';
        $search  = trim($_GET['search']  ?? '');
        $cat     = (int)($_GET['cat']    ?? 0);
        $method  = $_GET['method'] ?? '';
        $dateFrom= $_GET['date_from'] ?? '';
        $dateTo  = $_GET['date_to']   ?? '';
        $sortBy  = in_array($_GET['sort'] ?? '', ['date','montant','user','livre'])
                   ? ($_GET['sort'] ?? 'date') : 'date';
        $sortDir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $where  = ["a.statut='confirme'"];
        $params = [];

        if ($type === 'vente') {
            $where[] = "(a.type_transaction='vente' OR a.type_transaction IS NULL)";
        } elseif ($type === 'bonus_accorde') {
            $where[] = "a.type_transaction='bonus_accorde'";
        }

        if ($search !== '') {
            $where[] = "(CONCAT(u.prenom,' ',u.nom) LIKE ? OR u.email LIKE ? OR l.titre LIKE ? OR a.reference LIKE ?)";
            $s = '%' . $search . '%';
            array_push($params, $s, $s, $s, $s);
        }
        if ($cat > 0) {
            $where[] = "l.categorie_id = ?";
            $params[] = $cat;
        }
        if ($method !== '') {
            $where[] = "a.methode = ?";
            $params[] = $method;
        }
        if ($dateFrom !== '') {
            $where[] = "DATE(a.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "DATE(a.created_at) <= ?";
            $params[] = $dateTo;
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);
        $sortMap  = [
            'date'    => 'a.created_at',
            'montant' => 'a.montant',
            'user'    => 'u.nom',
            'livre'   => 'l.titre',
        ];
        $sortCol = $sortMap[$sortBy];

        $baseSql = "FROM achats a
                    JOIN users u  ON u.id  = a.user_id
                    JOIN livres l ON l.id  = a.livre_id
                    LEFT JOIN categories c ON c.id = l.categorie_id
                    $whereStr";

        $total = (int)dbScalar("SELECT COUNT(*) $baseSql", $params);
        $rows  = dbFetch(
            "SELECT
                a.id, a.reference, a.montant, a.methode, a.statut,
                COALESCE(a.type_transaction,'vente') AS type_transaction,
                a.created_at,
                CONCAT(u.prenom,' ',u.nom) AS user_name,
                u.email, u.id AS user_id,
                l.titre AS livre_titre, l.access_type,
                l.prix AS livre_prix, l.id AS livre_id,
                c.nom AS categorie
             $baseSql
             ORDER BY $sortCol $sortDir
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        echo json_encode([
            'transactions' => $rows,
            'total'        => $total,
            'page'         => $page,
            'pages'        => max(1, (int)ceil($total / $limit)),
        ]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // TOP LIVRES
    // ──────────────────────────────────────────────────────────
    if ($action === 'top_books') {
        $sort  = in_array($_GET['sort'] ?? '', ['revenue','sales','bonus','lectures'])
                 ? ($_GET['sort'] ?? 'revenue') : 'revenue';
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));

        $sortExpr = match ($sort) {
            'sales'    => 'COUNT(a.id)',
            'bonus'    => "COUNT(CASE WHEN a.type_transaction='bonus_accorde' THEN 1 END)",
            'lectures' => 'l.nb_lectures',
            default    => "COALESCE(SUM(CASE WHEN a.type_transaction='vente' OR a.type_transaction IS NULL THEN a.montant ELSE 0 END), 0)"
        };

        $books = dbFetch(
            "SELECT
                l.id, l.titre, l.auteur, l.prix, l.access_type,
                l.note_moyenne, l.nb_lectures, l.nb_ventes,
                l.is_bestseller, l.is_featured,
                c.nom AS categorie, c.icone AS cat_icon,
                COUNT(a.id) AS sales_count,
                COALESCE(SUM(CASE WHEN a.type_transaction='vente' OR a.type_transaction IS NULL THEN a.montant ELSE 0 END), 0) AS revenue_ventes,
                COUNT(CASE WHEN a.type_transaction='bonus_accorde' THEN 1 END) AS bonus_count
             FROM livres l
             LEFT JOIN categories c ON c.id = l.categorie_id
             LEFT JOIN achats a ON a.livre_id = l.id AND a.statut = 'confirme'
             WHERE l.statut = 'disponible'
             GROUP BY l.id
             ORDER BY $sortExpr DESC
             LIMIT ?",
            [$limit]
        );
        echo json_encode(['books' => $books]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // TOP ACHETEURS
    // ──────────────────────────────────────────────────────────
    if ($action === 'top_buyers') {
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));

        $buyers = dbFetch(
            "SELECT
                u.id, u.nom, u.prenom, u.email, u.statut,
                COUNT(CASE WHEN a.type_transaction='vente' OR a.type_transaction IS NULL THEN 1 END) AS purchases,
                COALESCE(SUM(CASE WHEN a.type_transaction='vente' OR a.type_transaction IS NULL THEN a.montant ELSE 0 END), 0) AS total_spent,
                MAX(a.created_at) AS last_purchase,
                COALESCE(ub.bonus_restant, 0) AS bonus,
                COALESCE(ub.achat_count, 0)   AS achat_count,
                COALESCE(ub.bonus_total, 0)    AS bonus_total
             FROM users u
             JOIN achats a ON a.user_id = u.id AND a.statut = 'confirme'
             LEFT JOIN user_bonus ub ON ub.user_id = u.id
             GROUP BY u.id
             ORDER BY total_spent DESC
             LIMIT ?",
            [$limit]
        );
        echo json_encode(['buyers' => $buyers]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // RECHERCHE UTILISATEURS (pour modal bonus)
    // ──────────────────────────────────────────────────────────
    if ($action === 'search_users') {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) {
            echo json_encode(['users' => []]);
            exit;
        }

        $bonusRule = getBonusRule();

        $users = dbFetch(
            "SELECT
                u.id, u.nom, u.prenom, u.email, u.statut,
                (SELECT COUNT(*) FROM achats a2
                 WHERE a2.user_id = u.id
                   AND a2.statut  = 'confirme'
                   AND (a2.type_transaction = 'vente' OR a2.type_transaction IS NULL)
                ) AS achats_confirmes,
                COALESCE(ub.bonus_restant, 0) AS bonus_restant,
                COALESCE(ub.bonus_total, 0)   AS bonus_total,
                COALESCE(ub.achat_count, 0)   AS achat_progress
             FROM users u
             LEFT JOIN user_bonus ub ON ub.user_id = u.id
             WHERE (CONCAT(u.prenom,' ',u.nom) LIKE ? OR u.email LIKE ?)
               AND u.statut = 'actif'
             ORDER BY u.nom, u.prenom
             LIMIT 10",
            ['%' . $q . '%', '%' . $q . '%']
        );

        echo json_encode(['users' => $users, 'bonus_rule' => $bonusRule]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // RECHERCHE LIVRES (pour modal bonus)
    // ──────────────────────────────────────────────────────────
    if ($action === 'search_books') {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) {
            echo json_encode(['books' => []]);
            exit;
        }

        $books = dbFetch(
            "SELECT l.id, l.titre, l.auteur, l.prix, l.access_type, c.nom AS categorie
             FROM livres l
             LEFT JOIN categories c ON c.id = l.categorie_id
             WHERE (l.titre LIKE ? OR l.auteur LIKE ?)
               AND l.statut = 'disponible'
             ORDER BY l.titre
             LIMIT 10",
            ['%' . $q . '%', '%' . $q . '%']
        );
        echo json_encode(['books' => $books]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // VÉRIFICATION ÉLIGIBILITÉ BONUS — 100% depuis la base réelle
    // ──────────────────────────────────────────────────────────
    if ($action === 'check_bonus_eligibility') {
        $userId  = (int)($_GET['user_id']  ?? 0);
        $livreId = (int)($_GET['livre_id'] ?? 0);

        if (!$userId || !$livreId) {
            echo json_encode(['eligible' => false, 'reason' => 'Paramètres manquants.']);
            exit;
        }

        // Vérifier utilisateur
        $user = dbOne(
            "SELECT id, nom, prenom, email, statut FROM users WHERE id = ?",
            [$userId]
        );
        if (!$user) {
            echo json_encode(['eligible' => false, 'reason' => 'Utilisateur introuvable.']);
            exit;
        }
        if ($user['statut'] !== 'actif') {
            echo json_encode(['eligible' => false, 'reason' => 'Cet utilisateur est inactif ou bloqué.']);
            exit;
        }

        // Vérifier livre
        $livre = dbOne(
            "SELECT id, titre, auteur, prix, statut FROM livres WHERE id = ? AND statut = 'disponible'",
            [$livreId]
        );
        if (!$livre) {
            echo json_encode(['eligible' => false, 'reason' => 'Livre introuvable ou indisponible.']);
            exit;
        }

        // Synchroniser bonus avant de vérifier (données fraîches)
        syncUserBonus($userId);

        $bonusRule = getBonusRule();

        // Compter achats réels confirmés (hors bonus)
        $achatsConfirmes = (int)dbScalar(
            "SELECT COUNT(*) FROM achats
             WHERE user_id = ?
               AND statut  = 'confirme'
               AND (type_transaction = 'vente' OR type_transaction IS NULL)",
            [$userId]
        );

        if ($achatsConfirmes < $bonusRule) {
            echo json_encode([
                'eligible'  => false,
                'reason'    => "Seulement $achatsConfirmes achat(s) confirmé(s) sur $bonusRule requis.",
                'purchases' => $achatsConfirmes,
                'required'  => $bonusRule,
            ]);
            exit;
        }

        // Lire les bonus après synchronisation
        $bonusRow     = dbOne("SELECT bonus_restant, achat_count, bonus_total FROM user_bonus WHERE user_id = ?", [$userId]);
        $bonusRestant = (int)($bonusRow['bonus_restant'] ?? 0);

        if ($bonusRestant <= 0) {
            // Calcul du prochain bonus
            $achatProgress = (int)($bonusRow['achat_count'] ?? ($achatsConfirmes % $bonusRule));
            $restant       = $bonusRule - $achatProgress;
            echo json_encode([
                'eligible'      => false,
                'reason'        => "Aucun bonus disponible. Il faut encore $restant achat(s) pour débloquer un bonus.",
                'bonus'         => 0,
                'achats'        => $achatsConfirmes,
                'progress'      => $achatProgress,
                'next_bonus_in' => $restant,
            ]);
            exit;
        }

        // Vérifier si le livre est déjà possédé par cet utilisateur
        $dejaAchete = (int)dbScalar(
            "SELECT COUNT(*) FROM achats
             WHERE user_id  = ?
               AND livre_id = ?
               AND statut   = 'confirme'",
            [$userId, $livreId]
        );

        if ($dejaAchete > 0) {
            // Vérifier si l'utilisateur possède TOUS les livres disponibles
            $totalLivres   = (int)dbScalar("SELECT COUNT(*) FROM livres WHERE statut='disponible'");
            $livresPossedes = (int)dbScalar(
                "SELECT COUNT(DISTINCT livre_id) FROM achats WHERE user_id = ? AND statut = 'confirme'",
                [$userId]
            );

            if ($livresPossedes >= $totalLivres) {
                // Cas exceptionnel : utilisateur possède tout
                $userName = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
                echo json_encode([
                    'eligible'      => true,
                    'all_possessed' => true,
                    'reason'        => "⚠️ Cet utilisateur possède déjà TOUS les livres disponibles. Attribution exceptionnelle autorisée.",
                    'user'          => ['id' => $user['id'], 'name' => $userName, 'email' => $user['email']],
                    'livre'         => ['id' => $livre['id'], 'titre' => $livre['titre']],
                    'bonus_restant' => $bonusRestant,
                    'purchases'     => $achatsConfirmes,
                ]);
            } else {
                echo json_encode([
                    'eligible' => false,
                    'reason'   => "Ce livre est déjà dans la bibliothèque de cet utilisateur (acheté ou reçu en bonus).",
                ]);
            }
            exit;
        }

        // Tout est OK
        $userName = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
        echo json_encode([
            'eligible'      => true,
            'all_possessed' => false,
            'user'          => [
                'id'    => $user['id'],
                'name'  => $userName,
                'email' => $user['email'],
            ],
            'livre'         => [
                'id'     => $livre['id'],
                'titre'  => $livre['titre'],
                'auteur' => $livre['auteur'],
            ],
            'bonus_restant' => $bonusRestant,
            'purchases'     => $achatsConfirmes,
            'required'      => $bonusRule,
            'achat_progress'=> (int)($bonusRow['achat_count'] ?? 0),
        ]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // ATTRIBUTION BONUS — transaction atomique complète
    // ──────────────────────────────────────────────────────────
    if ($action === 'grant_bonus' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawBody  = file_get_contents('php://input');
        $postBody = json_decode($rawBody, true) ?: [];
        $userId   = (int)($postBody['user_id']  ?? 0);
        $livreId  = (int)($postBody['livre_id'] ?? 0);

        if (!$userId || !$livreId) {
            echo json_encode(['error' => 'Paramètres manquants.']);
            exit;
        }
        if (!$pdo) {
            echo json_encode(['error' => 'Base de données inaccessible.']);
            exit;
        }

        // Synchroniser avant attribution (données fraîches garanties)
        syncUserBonus($userId);

        // Re-vérification complète
        $user  = dbOne("SELECT id, nom, prenom, email FROM users WHERE id = ? AND statut = 'actif'", [$userId]);
        $livre = dbOne("SELECT id, titre, prix FROM livres WHERE id = ? AND statut = 'disponible'", [$livreId]);

        if (!$user) {
            echo json_encode(['error' => 'Utilisateur invalide ou inactif.']);
            exit;
        }
        if (!$livre) {
            echo json_encode(['error' => 'Livre invalide ou indisponible.']);
            exit;
        }

        $bonusRow     = dbOne("SELECT bonus_restant, achat_count, bonus_total FROM user_bonus WHERE user_id = ?", [$userId]);
        $bonusRestant = (int)($bonusRow['bonus_restant'] ?? 0);

        if ($bonusRestant <= 0) {
            echo json_encode(['error' => 'Aucun bonus disponible pour cet utilisateur.']);
            exit;
        }

        $bonusRule       = getBonusRule();
        $achatsConfirmes = (int)dbScalar(
            "SELECT COUNT(*) FROM achats
             WHERE user_id = ?
               AND statut  = 'confirme'
               AND (type_transaction = 'vente' OR type_transaction IS NULL)",
            [$userId]
        );

        if ($achatsConfirmes < $bonusRule) {
            echo json_encode(['error' => "Éligibilité insuffisante ($achatsConfirmes/$bonusRule achats confirmés)."]);
            exit;
        }

        // Vérifier doublon (hors cas tous-possédés)
        $dejaAchete    = (int)dbScalar(
            "SELECT COUNT(*) FROM achats WHERE user_id = ? AND livre_id = ? AND statut = 'confirme'",
            [$userId, $livreId]
        );
        $totalLivres   = (int)dbScalar("SELECT COUNT(*) FROM livres WHERE statut='disponible'");
        $livresPossedes = (int)dbScalar(
            "SELECT COUNT(DISTINCT livre_id) FROM achats WHERE user_id = ? AND statut = 'confirme'",
            [$userId]
        );

        if ($dejaAchete > 0 && $livresPossedes < $totalLivres) {
            echo json_encode(['error' => 'Ce livre est déjà possédé par cet utilisateur.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Générer référence unique
            $ref = 'BONUS-' . strtoupper(
                substr(md5(uniqid((string)($userId * $livreId), true)), 0, 10)
            );

            // Insérer l'achat bonus
            $pdo->prepare(
                "INSERT INTO achats
                    (user_id, livre_id, montant, methode, statut, type_transaction, reference)
                 VALUES (?, ?, 0, 'orange_money', 'confirme', 'bonus_accorde', ?)"
            )->execute([$userId, $livreId, $ref]);

            // Décrémenter bonus_restant (jamais négatif)
            $pdo->prepare(
                "UPDATE user_bonus
                 SET bonus_restant = GREATEST(0, bonus_restant - 1)
                 WHERE user_id = ?"
            )->execute([$userId]);

            // Incrémenter nb_ventes du livre
            $pdo->prepare(
                "UPDATE livres SET nb_ventes = nb_ventes + 1 WHERE id = ?"
            )->execute([$livreId]);

            // Notification utilisateur
            $userName = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
            $pdo->prepare(
                "INSERT INTO notifications
                    (user_id, type, titre, message, icon, bg)
                 VALUES (?, 'bonus', '🎁 Livre bonus accordé', ?, '🎁', 'rgba(0,255,170,.08)')"
            )->execute([
                $userId,
                "L'administrateur vous a offert le livre : « {$livre['titre']} ». Profitez bien de votre lecture !"
            ]);

            // Log admin
            try {
                $pdo->prepare(
                    "INSERT INTO admin_logs (user_id, action, detail, ip)
                     VALUES (?, 'bonus_granted', ?, ?)"
                )->execute([
                    $adminId,
                    "Bonus accordé : livre_id={$livreId} ('{$livre['titre']}') à user_id={$userId} ($userName)",
                    $_SERVER['REMOTE_ADDR'] ?? '—',
                ]);
            } catch (Exception $e) {
                // Log non bloquant
                error_log('[SALES] admin_logs: ' . $e->getMessage());
            }

            $pdo->commit();

            // Re-synchroniser après attribution
            syncUserBonus($userId);

            $newBonus = dbOne("SELECT bonus_restant FROM user_bonus WHERE user_id = ?", [$userId]);

            echo json_encode([
                'success'       => true,
                'message'       => "Livre « {$livre['titre']} » accordé en bonus à $userName.",
                'reference'     => $ref,
                'user_name'     => $userName,
                'livre_titre'   => $livre['titre'],
                'bonus_restant' => (int)($newBonus['bonus_restant'] ?? 0),
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[SALES] grant_bonus: ' . $e->getMessage());
            echo json_encode(['error' => "Erreur lors de l'attribution. Réessayez."]);
        }
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // ANNULER TRANSACTION
    // ──────────────────────────────────────────────────────────
    if ($action === 'cancel_transaction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawBody  = file_get_contents('php://input');
        $postBody = json_decode($rawBody, true) ?: [];
        $achatId  = (int)($postBody['achat_id'] ?? 0);

        if (!$achatId) {
            echo json_encode(['error' => 'ID de transaction manquant.']);
            exit;
        }

        $achat = dbOne(
            "SELECT id, type_transaction, user_id, livre_id, montant
             FROM achats WHERE id = ? AND statut = 'confirme'",
            [$achatId]
        );
        if (!$achat) {
            echo json_encode(['error' => 'Transaction introuvable ou déjà annulée.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE achats SET statut = 'echec' WHERE id = ?")->execute([$achatId]);
            $pdo->prepare("UPDATE livres SET nb_ventes = GREATEST(0, nb_ventes - 1) WHERE id = ?")->execute([$achat['livre_id']]);

            $pdo->commit();

            // Resynchroniser le bonus de l'utilisateur après annulation
            syncUserBonus((int)$achat['user_id']);

            echo json_encode(['success' => true, 'message' => 'Transaction annulée avec succès. Bonus resynchronisés.']);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[SALES] cancel_transaction: ' . $e->getMessage());
            echo json_encode(['error' => "Erreur lors de l'annulation."]);
        }
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // STATS FIDÉLITÉ
    // ──────────────────────────────────────────────────────────
    if ($action === 'loyalty_stats') {
        // Re-sync avant affichage
        syncAllUserBonus();

        $bonusRule = getBonusRule();

        $eligible   = (int)dbScalar("SELECT COUNT(*) FROM user_bonus WHERE bonus_restant > 0");
        $totalBonus = (int)dbScalar("SELECT COALESCE(SUM(bonus_total), 0) FROM user_bonus");

        $progression = dbFetch(
            "SELECT
                u.id,
                CONCAT(u.prenom,' ',u.nom) AS name,
                u.email,
                COALESCE(ub.achat_count, 0)   AS achat_count,
                COALESCE(ub.bonus_restant, 0)  AS bonus_restant,
                COALESCE(ub.bonus_total, 0)    AS bonus_total,
                (SELECT COUNT(*) FROM achats a2
                 WHERE a2.user_id = u.id
                   AND a2.statut  = 'confirme'
                   AND (a2.type_transaction = 'vente' OR a2.type_transaction IS NULL)
                ) AS total_achats
             FROM user_bonus ub
             JOIN users u ON u.id = ub.user_id
             WHERE ub.bonus_total > 0 OR ub.bonus_restant > 0
             ORDER BY ub.bonus_total DESC
             LIMIT 10"
        );

        echo json_encode([
            'eligible'            => $eligible,
            'total_bonus_granted' => $totalBonus,
            'bonus_rule'          => $bonusRule,
            'progression'         => $progression,
        ]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // INSIGHTS
    // ──────────────────────────────────────────────────────────
    if ($action === 'insights') {
        $avg  = (float)dbScalar(
            "SELECT COALESCE(AVG(rev), 0)
             FROM (
                SELECT MONTH(created_at) m, YEAR(created_at) y,
                       SUM(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant ELSE 0 END) AS rev
                FROM achats
                WHERE statut = 'confirme'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY m, y
             ) t"
        );
        $curr = (float)dbScalar(
            "SELECT COALESCE(SUM(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant ELSE 0 END), 0)
             FROM achats
             WHERE statut = 'confirme'
               AND MONTH(created_at) = MONTH(NOW())
               AND YEAR(created_at)  = YEAR(NOW())"
        );
        $day  = (int)date('j');
        $daysM = (int)date('t');
        $proj = $day > 0 ? round(($curr / $day) * $daysM) : 0;

        $bHour = dbOne(
            "SELECT HOUR(created_at) AS h, COUNT(*) AS cnt
             FROM achats WHERE statut='confirme'
             GROUP BY h ORDER BY cnt DESC LIMIT 1"
        );
        $bDay = dbOne(
            "SELECT DAYNAME(created_at) AS d, COUNT(*) AS cnt
             FROM achats WHERE statut='confirme'
             GROUP BY DAYOFWEEK(created_at) ORDER BY cnt DESC LIMIT 1"
        );

        echo json_encode([
            'avg_monthly'     => $avg,
            'current_month'   => $curr,
            'projected_month' => $proj,
            'best_hour'       => $bHour['h'] ?? null,
            'best_day'        => $bDay['d']  ?? null,
            'growth_vs_avg'   => growthRate($curr, $avg),
        ]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // NOTIFICATIONS
    // ──────────────────────────────────────────────────────────
    if ($action === 'notifications') {
        $notifs = dbFetch(
            "SELECT * FROM notifications
             WHERE user_id IS NULL OR user_id = ?
             ORDER BY created_at DESC LIMIT 12",
            [$adminId]
        );
        $unread = (int)dbScalar(
            "SELECT COUNT(*) FROM notifications WHERE lu = 0 AND (user_id IS NULL OR user_id = ?)",
            [$adminId]
        );
        echo json_encode(['notifications' => $notifs, 'unread' => $unread]);
        exit;
    }

    if ($action === 'mark_read') {
        dbExec(
            "UPDATE notifications SET lu = 1 WHERE user_id IS NULL OR user_id = ?",
            [$adminId]
        );
        echo json_encode(['ok' => true]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // TOP CATÉGORIES
    // ──────────────────────────────────────────────────────────
    if ($action === 'top_categories') {
        $rows = dbFetch(
            "SELECT
                c.nom, c.icone, c.couleur,
                COUNT(a.id) AS sales,
                COALESCE(SUM(CASE WHEN a.type_transaction='vente' OR a.type_transaction IS NULL THEN a.montant ELSE 0 END), 0) AS revenue,
                COUNT(CASE WHEN a.type_transaction='bonus_accorde' THEN 1 END) AS bonus
             FROM categories c
             JOIN livres l  ON l.categorie_id = c.id
             JOIN achats a  ON a.livre_id = l.id AND a.statut = 'confirme'
             GROUP BY c.id
             ORDER BY revenue DESC
             LIMIT 8"
        );
        echo json_encode(['categories' => $rows]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // MÉTHODES DE PAIEMENT
    // ──────────────────────────────────────────────────────────
    if ($action === 'payment_methods') {
        $rows = dbFetch(
            "SELECT methode,
                    COUNT(*) AS count,
                    COALESCE(SUM(montant), 0) AS total
             FROM achats
             WHERE statut = 'confirme'
               AND (type_transaction = 'vente' OR type_transaction IS NULL)
             GROUP BY methode
             ORDER BY total DESC"
        );
        echo json_encode(['methods' => $rows]);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // EXPORT CSV
    // ──────────────────────────────────────────────────────────
    if ($action === 'export_csv') {
        $type = $_GET['type'] ?? 'all';

        $extraWhere = match ($type) {
            'vente' => "AND (a.type_transaction='vente' OR a.type_transaction IS NULL)",
            'bonus' => "AND a.type_transaction='bonus_accorde'",
            default => ''
        };

        $rows = dbFetch(
            "SELECT
                a.id, a.reference, a.created_at, a.montant,
                COALESCE(a.type_transaction,'vente') AS type_transaction,
                a.methode, a.statut,
                CONCAT(u.prenom,' ',u.nom) AS acheteur,
                u.email,
                l.titre, l.auteur, l.access_type,
                c.nom AS categorie
             FROM achats a
             JOIN users u  ON u.id  = a.user_id
             JOIN livres l ON l.id  = a.livre_id
             LEFT JOIN categories c ON c.id = l.categorie_id
             WHERE a.statut = 'confirme' $extraWhere
             ORDER BY a.created_at DESC"
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions_' . $type . '_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
        fputcsv($out, ['ID','Référence','Date','Montant FCFA','Type','Méthode','Statut','Acheteur','Email','Livre','Auteur','Accès','Catégorie']);
        foreach ($rows as $r) {
            fputcsv($out, array_values($r));
        }
        fclose($out);
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // RESYNC MANUEL (déclenché depuis l'interface)
    // ──────────────────────────────────────────────────────────
    if ($action === 'sync_bonus') {
        syncAllUserBonus();
        $eligible = (int)dbScalar("SELECT COUNT(*) FROM user_bonus WHERE bonus_restant > 0");
        echo json_encode([
            'success'  => true,
            'eligible' => $eligible,
            'message'  => "Synchronisation terminée. $eligible utilisateur(s) éligible(s) au bonus.",
        ]);
        exit;
    }

    echo json_encode(['error' => 'Action inconnue : ' . esc($action)]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// 8. DONNÉES INITIALES SSR (rendu côté serveur)
// ══════════════════════════════════════════════════════════════
$initStats = [];
if ($pdo) {
    try {
        $prevMonthRev = (float)dbScalar(
            "SELECT COALESCE(SUM(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant ELSE 0 END), 0)
             FROM achats
             WHERE statut = 'confirme'
               AND MONTH(created_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
               AND YEAR(created_at)  = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))"
        );
        $revMonth = (float)dbScalar(
            "SELECT COALESCE(SUM(CASE WHEN type_transaction='vente' OR type_transaction IS NULL THEN montant ELSE 0 END), 0)
             FROM achats
             WHERE statut = 'confirme'
               AND MONTH(created_at) = MONTH(NOW())
               AND YEAR(created_at)  = YEAR(NOW())"
        );

        $initStats = [
            'rev_total'       => (float)dbScalar(
                "SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL)"
            ),
            'sales_today'     => (int)dbScalar(
                "SELECT COUNT(*) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()"
            ),
            'sales_month'     => (int)dbScalar(
                "SELECT COUNT(*) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"
            ),
            'sales_year'      => (int)dbScalar(
                "SELECT COUNT(*) FROM achats WHERE statut='confirme' AND YEAR(created_at)=YEAR(NOW())"
            ),
            'rev_month'       => $revMonth,
            'rev_growth'      => growthRate($revMonth, $prevMonthRev),
            'rev_today'       => (float)dbScalar(
                "SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND DATE(created_at)=CURDATE()"
            ),
            'rev_year'        => (float)dbScalar(
                "SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND (type_transaction='vente' OR type_transaction IS NULL) AND YEAR(created_at)=YEAR(NOW())"
            ),
            'bonus_total'     => (int)dbScalar(
                "SELECT COUNT(*) FROM achats WHERE statut='confirme' AND type_transaction='bonus_accorde'"
            ),
            'users_eligible'  => (int)dbScalar(
                "SELECT COUNT(*) FROM user_bonus WHERE bonus_restant > 0"
            ),
            'confirmed_total' => (int)dbScalar(
                "SELECT COUNT(*) FROM achats WHERE statut='confirme'"
            ),
            'failed_total'    => (int)dbScalar(
                "SELECT COUNT(*) FROM achats WHERE statut='echec'"
            ),
            'notif_unread'    => (int)dbScalar(
                "SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id IS NULL OR user_id=?)",
                [$adminId]
            ),
            'bonus_rule'      => getBonusRule(),
            'categories'      => dbFetch("SELECT id, nom, icone FROM categories ORDER BY nom"),
        ];
    } catch (Exception $e) {
        error_log('[SALES] initStats: ' . $e->getMessage());
    }
}

$initJson = json_encode($initStats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$csrfJson = json_encode($csrfToken);
$nameJson = json_encode($adminName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Ventes & Bonus — Digital Library Admin</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500;700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.49.0/dist/apexcharts.min.js" defer></script>

<style>
/* ═══════════════════════════════════════════════
   DESIGN SYSTEM
═══════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#05080f;--bg-s:#0b1020;--bg-c:rgba(255,255,255,.028);--bg-ch:rgba(255,255,255,.05);
  --b0:rgba(255,255,255,.06);--b1:rgba(255,255,255,.1);--b-a:rgba(0,212,255,.35);
  --c:hsl(195,100%,50%);--v:#7c3aed;--g:#00ffaa;--am:#f59e0b;--ro:#f43f5e;--or:#f97316;--sk:#38bdf8;
  --t1:#eef2ff;--t2:rgba(238,242,255,.6);--t3:rgba(238,242,255,.3);
  --sw:256px;--sc:64px;--th:58px;
  --r1:8px;--r2:12px;--r3:16px;--r4:22px;
  --sh:0 6px 28px rgba(0,0,0,.36);--shl:0 20px 64px rgba(0,0,0,.52);
  --glow:0 0 28px rgba(0,212,255,.18);
}
html{scroll-behavior:smooth;font-size:15px}
body{font-family:'Instrument Sans',sans-serif;background:var(--bg);color:var(--t1);overflow-x:hidden;min-height:100vh}
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}
.app{display:flex;min-height:100vh}

/* SIDEBAR */
#sb{position:fixed;top:0;left:0;bottom:0;width:var(--sw);background:var(--bg-s);border-right:1px solid var(--b0);display:flex;flex-direction:column;z-index:300;transition:width .3s cubic-bezier(.4,0,.2,1);overflow:hidden}
#sb.col{width:var(--sc)}
.sb-brand{height:var(--th);display:flex;align-items:center;gap:10px;padding:0 14px;border-bottom:1px solid var(--b0);flex-shrink:0}
.sb-logo{width:34px;height:34px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,var(--c),var(--v));display:flex;align-items:center;justify-content:center;font-size:1rem;box-shadow:0 0 20px rgba(0,212,255,.25)}
.sb-nm{font-family:'Syne',sans-serif;font-weight:800;font-size:.84rem;white-space:nowrap;transition:opacity .2s}
.sb-nm em{color:var(--c);font-style:normal}
#sb.col .sb-nm{opacity:0}
.sb-user{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--b0);flex-shrink:0}
.sb-av{width:36px;height:36px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,var(--c),var(--v));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.82rem}
.sb-ui{overflow:hidden;transition:opacity .2s}
#sb.col .sb-ui{opacity:0}
.sb-un{font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem;white-space:nowrap}
.sb-ur{font-size:.58rem;font-family:'JetBrains Mono',monospace;color:var(--c);text-transform:uppercase;margin-top:2px}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.sb-sec{font-family:'JetBrains Mono',monospace;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);padding:8px 14px 2px;white-space:nowrap;transition:opacity .2s}
#sb.col .sb-sec{opacity:0}
.sb-item{display:flex;align-items:center;gap:10px;padding:8px 14px;margin:1px 6px;border-radius:var(--r1);text-decoration:none;color:var(--t2);font-size:.79rem;font-weight:500;transition:all .15s;position:relative;white-space:nowrap;overflow:hidden}
.sb-item:hover{color:var(--t1);background:var(--bg-ch)}
.sb-item.active{color:var(--c);background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.12)}
.sb-item.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:16px;background:var(--c);border-radius:0 3px 3px 0;box-shadow:0 0 8px var(--c)}
.sb-ico{font-size:.98rem;width:18px;text-align:center;flex-shrink:0}
.sb-lbl{transition:opacity .2s}
#sb.col .sb-lbl{opacity:0}
.sb-foot{padding:8px;border-top:1px solid var(--b0)}
.sb-col-btn{width:100%;display:flex;align-items:center;gap:10px;padding:7px 6px;border-radius:var(--r1);background:none;border:none;color:var(--t3);font-size:.74rem;cursor:pointer;transition:all .15s;font-family:'Instrument Sans',sans-serif}
.sb-col-btn:hover{color:var(--t1);background:var(--bg-ch)}
.ci{font-size:.9rem;flex-shrink:0;width:18px;text-align:center;transition:transform .3s}
#sb.col .ci{transform:rotate(180deg)}
.cl{transition:opacity .2s;white-space:nowrap}
#sb.col .cl{opacity:0}

/* MAIN */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-height:100vh;transition:margin-left .3s cubic-bezier(.4,0,.2,1)}
.main.col{margin-left:var(--sc)}

/* TOPBAR */
#tb{height:var(--th);background:rgba(5,8,15,.92);backdrop-filter:blur(24px);border-bottom:1px solid var(--b0);display:flex;align-items:center;gap:.8rem;padding:0 1.4rem;position:sticky;top:0;z-index:200}
.bc{display:flex;align-items:center;gap:6px;font-size:.72rem;color:var(--t2)}
.bc-s{opacity:.3}.bc-c{font-family:'Syne',sans-serif;font-weight:700;color:var(--t1)}
.sp{flex:1}
.pw{display:flex;align-items:center;gap:3px;background:var(--bg-c);border:1px solid var(--b0);border-radius:var(--r1);padding:3px}
.pb{padding:4px 9px;border-radius:6px;font-size:.67rem;font-family:'JetBrains Mono',monospace;background:none;border:none;color:var(--t2);cursor:pointer;transition:all .15s}
.pb.active{background:linear-gradient(135deg,var(--c),var(--v));color:#fff}
.tb-acts{display:flex;align-items:center;gap:4px}
.tb-btn{width:32px;height:32px;border-radius:var(--r1);background:var(--bg-c);border:1px solid var(--b0);color:var(--t2);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.88rem;transition:all .15s;position:relative;text-decoration:none}
.tb-btn:hover{color:var(--t1);background:var(--bg-ch)}
.nb-dot{position:absolute;top:-3px;right:-3px;min-width:14px;height:14px;padding:0 3px;background:var(--ro);border-radius:100px;font-size:.5rem;font-family:'JetBrains Mono',monospace;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);color:#fff;font-weight:700}
.tb-user{display:flex;align-items:center;gap:6px;padding:4px 8px;border-radius:var(--r1);background:var(--bg-c);border:1px solid var(--b0);cursor:pointer;transition:all .15s;text-decoration:none}
.tb-user:hover{border-color:var(--b-a)}
.tu-av{width:24px;height:24px;border-radius:7px;background:linear-gradient(135deg,var(--c),var(--v));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.65rem}
.tu-n{font-size:.7rem;font-weight:600}.tu-r{font-size:.56rem;color:var(--c);font-family:'JetBrains Mono',monospace}
.tb-ham{display:none;background:none;border:none;color:var(--t1);font-size:1.2rem;cursor:pointer;width:32px;height:32px;border-radius:var(--r1);align-items:center;justify-content:center}
.rf-btn{display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:var(--r1);background:rgba(0,255,170,.07);border:1px solid rgba(0,255,170,.18);color:var(--g);font-size:.68rem;font-family:'JetBrains Mono',monospace;cursor:pointer;transition:all .15s}
.rf-btn:hover{background:rgba(0,255,170,.13)}
.rf-btn .ri{display:inline-block;transition:transform .3s}
.rf-btn:hover .ri{transform:rotate(180deg)}

/* PAGE */
.page{flex:1;padding:1.5rem 1.5rem 4rem;max-width:1520px;width:100%;margin:0 auto}

/* PAGE HEADER */
.ph{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;background:linear-gradient(135deg,rgba(0,212,255,.04),rgba(124,58,237,.05),rgba(0,255,170,.03));border:1px solid rgba(0,212,255,.08);border-radius:var(--r4);padding:1.4rem 1.8rem;position:relative;overflow:hidden;animation:slideUp .4s ease both}
.ph::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--c),var(--v),var(--g))}
.ph-glow{position:absolute;right:-60px;top:-60px;width:200px;height:200px;background:radial-gradient(circle,rgba(0,212,255,.06),transparent 70%);pointer-events:none}
.ph-title{font-family:'Syne',sans-serif;font-size:1.45rem;font-weight:800;letter-spacing:-.5px}
.ph-title span{background:linear-gradient(135deg,var(--c),var(--v));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.ph-sub{font-size:.76rem;color:var(--t2);margin-top:4px}
.ph-pills{display:flex;gap:5px;margin-top:8px;flex-wrap:wrap}
.pp{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-family:'JetBrains Mono',monospace;padding:2px 9px;border-radius:100px;border:1px solid var(--b0);color:var(--t2);text-transform:uppercase}
.pp.live{background:rgba(0,255,170,.06);color:var(--g);border-color:rgba(0,255,170,.18)}
.pp.live::before{content:'';width:5px;height:5px;background:var(--g);border-radius:50%;animation:pdot 1.5s infinite}
@keyframes pdot{0%,100%{opacity:1}50%{opacity:.4}}
.ph-acts{display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0}

/* KPI GRID */
.kg{display:grid;grid-template-columns:repeat(auto-fill,minmax(195px,1fr));gap:.85rem;margin-bottom:1.3rem}
.kpi{background:var(--bg-c);border:1px solid var(--b0);border-radius:var(--r3);padding:1.15rem;position:relative;overflow:hidden;transition:transform .22s,border-color .22s,box-shadow .22s;animation:slideUp .5s ease both;cursor:default}
.kpi::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--k1,#fff),var(--k2,#888));opacity:0;transition:opacity .3s}
.kpi:hover{transform:translateY(-4px);border-color:rgba(255,255,255,.08);box-shadow:var(--sh)}
.kpi:hover::after{opacity:1}
.kpi:nth-child(1){--k1:var(--c);--k2:var(--v);animation-delay:.04s}
.kpi:nth-child(2){--k1:var(--g);--k2:var(--c);animation-delay:.07s}
.kpi:nth-child(3){--k1:var(--v);--k2:var(--ro);animation-delay:.1s}
.kpi:nth-child(4){--k1:var(--am);--k2:var(--or);animation-delay:.13s}
.kpi:nth-child(5){--k1:var(--g);--k2:var(--v);animation-delay:.16s}
.kpi:nth-child(6){--k1:var(--ro);--k2:var(--am);animation-delay:.19s}
.kpi:nth-child(7){--k1:var(--c);--k2:var(--g);animation-delay:.22s}
.kpi:nth-child(8){--k1:var(--v);--k2:var(--c);animation-delay:.25s}
.kico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;margin-bottom:.85rem}
.kval{font-family:'Syne',sans-serif;font-size:1.55rem;font-weight:800;letter-spacing:-.4px;line-height:1;background:linear-gradient(135deg,var(--k1,#fff),var(--k2,#aaa));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.kval.sm{font-size:1.05rem}
.klbl{font-size:.68rem;color:var(--t2);margin-top:5px;font-weight:500}
.kchg{display:flex;align-items:center;gap:3px;font-size:.62rem;font-family:'JetBrains Mono',monospace;margin-top:5px}
.up{color:var(--g)}.dn{color:var(--ro)}.neu{color:var(--t3)}
.shim{position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.04),transparent);transform:translateX(-100%);animation:shim 2.5s ease-in-out infinite}
@keyframes shim{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}

/* GRIDS */
.g2{display:grid;grid-template-columns:1.5fr 1fr;gap:1.1rem;margin-bottom:1.1rem}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:1.1rem;margin-bottom:1.1rem}
@media(max-width:1200px){.g2{grid-template-columns:1fr}.g3{grid-template-columns:1fr 1fr}}
@media(max-width:760px){.g3{grid-template-columns:1fr}}

/* CARD */
.card{background:var(--bg-c);border:1px solid var(--b0);border-radius:var(--r3);overflow:hidden;animation:slideUp .5s ease both}
.ch{padding:.95rem 1.25rem;border-bottom:1px solid var(--b0);display:flex;align-items:center;justify-content:space-between;gap:.6rem}
.ct{font-family:'Syne',sans-serif;font-weight:700;font-size:.84rem;display:flex;align-items:center;gap:7px}
.cib{width:27px;height:27px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0}
.cb{padding:.95rem 1.25rem}
.cf{padding:.7rem 1.25rem;border-top:1px solid var(--b0);display:flex;align-items:center;justify-content:space-between}

/* TABLE */
.tbl{width:100%;border-collapse:collapse;font-size:.76rem}
.tbl th{text-align:left;font-family:'JetBrains Mono',monospace;font-size:.57rem;letter-spacing:.08em;text-transform:uppercase;color:var(--t3);padding:6px 10px;border-bottom:1px solid var(--b0);white-space:nowrap}
.tbl td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.03);color:var(--t2);vertical-align:middle;transition:background .1s}
.tbl tbody tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:var(--bg-ch)}
.td-hi{font-weight:600;color:var(--t1)}.td-m{font-family:'JetBrains Mono',monospace;font-size:.63rem}

/* CHIPS */
.chip{display:inline-flex;align-items:center;gap:2px;font-size:.58rem;font-family:'JetBrains Mono',monospace;padding:2px 7px;border-radius:100px;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
.c-ok{background:rgba(0,255,170,.1);color:var(--g);border:1px solid rgba(0,255,170,.2)}
.c-warn{background:rgba(245,158,11,.1);color:var(--am);border:1px solid rgba(245,158,11,.2)}
.c-err{background:rgba(244,63,94,.1);color:var(--ro);border:1px solid rgba(244,63,94,.2)}
.c-info{background:rgba(0,212,255,.1);color:var(--c);border:1px solid rgba(0,212,255,.2)}
.c-v{background:rgba(124,58,237,.1);color:#a78bfa;border:1px solid rgba(124,58,237,.2)}
.c-mu{background:rgba(255,255,255,.05);color:var(--t3);border:1px solid var(--b0)}
.c-bonus{background:linear-gradient(135deg,rgba(0,255,170,.12),rgba(0,212,255,.08));color:var(--g);border:1px solid rgba(0,255,170,.2)}
.c-vente{background:rgba(0,212,255,.08);color:var(--c);border:1px solid rgba(0,212,255,.18)}
.c-pr{background:linear-gradient(135deg,rgba(245,158,11,.15),rgba(249,115,22,.12));color:var(--am);border:1px solid rgba(245,158,11,.22)}
.c-free{background:rgba(0,255,170,.07);color:var(--g);border:1px solid rgba(0,255,170,.15)}
.c-std{background:rgba(0,212,255,.07);color:var(--c);border:1px solid rgba(0,212,255,.15)}

/* BTNS */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:var(--r1);font-family:'Syne',sans-serif;font-size:.74rem;font-weight:700;cursor:pointer;transition:all .15s;text-decoration:none;border:none;white-space:nowrap}
.btn-sm{padding:4px 9px;font-size:.67rem}.btn-xs{padding:2px 6px;font-size:.6rem}
.btn-pr{background:linear-gradient(135deg,var(--c),var(--v));color:#fff;box-shadow:0 4px 14px rgba(0,212,255,.16)}
.btn-pr:hover{opacity:.85;transform:translateY(-1px);box-shadow:0 6px 22px rgba(0,212,255,.28)}
.btn-gh{background:var(--bg-c);border:1px solid var(--b0);color:var(--t2)}
.btn-gh:hover{color:var(--t1);border-color:var(--b1);background:var(--bg-ch)}
.btn-g{background:rgba(0,255,170,.09);border:1px solid rgba(0,255,170,.2);color:var(--g)}
.btn-g:hover{background:rgba(0,255,170,.16)}
.btn-e{background:rgba(244,63,94,.09);border:1px solid rgba(244,63,94,.2);color:var(--ro)}
.btn-e:hover{background:rgba(244,63,94,.16)}
.btn-a{background:rgba(245,158,11,.09);border:1px solid rgba(245,158,11,.2);color:var(--am)}

/* PROGRESS */
.pgw{background:rgba(255,255,255,.06);border-radius:100px;height:4px;overflow:hidden;flex:1}
.pg{height:100%;border-radius:100px;transition:width 1s ease}
.pg-c{background:linear-gradient(90deg,var(--c),var(--v))}
.pg-g{background:linear-gradient(90deg,var(--g),#00c87a)}
.pg-a{background:linear-gradient(90deg,var(--am),var(--or))}

/* LIVE DOT */
.ldot{width:6px;height:6px;background:var(--g);border-radius:50%;animation:pdot 1.5s infinite;flex-shrink:0}

/* FILTERS */
.filters{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.9rem;align-items:center}
.f-inp{background:var(--bg-c);border:1px solid var(--b0);border-radius:var(--r1);padding:6px 11px;color:var(--t1);font-size:.75rem;font-family:'Instrument Sans',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
.f-inp:focus{border-color:var(--b-a);box-shadow:var(--glow)}
.f-inp::placeholder{color:var(--t3)}
select.f-inp{min-width:130px}
.f-search{flex:1;min-width:180px}
.f-date{width:130px}

/* MODAL */
.mbg{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(5,8,15,.92);backdrop-filter:blur(16px);opacity:0;pointer-events:none;transition:opacity .28s}
.mbg.open{opacity:1;pointer-events:all}
.modal{background:var(--bg-s);border:1px solid var(--b0);border-radius:var(--r4);padding:1.8rem;max-width:460px;width:100%;box-shadow:var(--shl);position:relative;overflow:hidden;transform:translateY(22px) scale(.97);transition:transform .3s cubic-bezier(.34,1.56,.64,1)}
.modal::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--g),var(--c),var(--v))}
.mbg.open .modal{transform:translateY(0) scale(1)}
.m-x{position:absolute;top:.8rem;right:.8rem;background:none;border:none;color:var(--t3);font-size:.9rem;cursor:pointer;width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.m-x:hover{color:var(--t1);background:var(--bg-ch)}
.m-title{font-family:'Syne',sans-serif;font-weight:800;font-size:1.15rem;margin-bottom:.3rem}
.m-sub{font-size:.75rem;color:var(--t2);margin-bottom:1.2rem}
.m-lbl{font-size:.65rem;font-family:'JetBrains Mono',monospace;color:var(--t3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;display:block}
.m-inp{width:100%;background:var(--bg-c);border:1px solid var(--b0);border-radius:var(--r1);padding:8px 11px;color:var(--t1);font-size:.8rem;font-family:'Instrument Sans',sans-serif;outline:none;transition:border-color .2s;margin-bottom:.85rem}
.m-inp:focus{border-color:var(--b-a);box-shadow:var(--glow)}
.sl{background:var(--bg-s);border:1px solid var(--b0);border-radius:var(--r2);max-height:165px;overflow-y:auto;display:none;position:absolute;width:100%;z-index:10;box-shadow:var(--sh)}
.sl.open{display:block}
.si{padding:8px 11px;font-size:.76rem;color:var(--t2);cursor:pointer;transition:background .1s;border-bottom:1px solid rgba(255,255,255,.03)}
.si:last-child{border-bottom:none}
.si:hover{background:var(--bg-ch);color:var(--t1)}
.si strong{color:var(--t1)}
.si small{color:var(--t3);font-family:'JetBrains Mono',monospace;font-size:.6rem}
.elig{border-radius:var(--r2);padding:.9rem;margin-bottom:1rem;font-size:.77rem;display:none}
.elig.show{display:block}
.elig.ok{background:rgba(0,255,170,.08);border:1px solid rgba(0,255,170,.2);color:var(--g)}
.elig.err{background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);color:var(--ro)}
.elig.warn{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);color:var(--am)}
.m-stats{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-top:.7rem}
.m-stat{background:rgba(255,255,255,.04);border-radius:7px;padding:.5rem .7rem}
.m-sv{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;color:var(--t1)}
.m-sl{font-size:.58rem;color:var(--t3);font-family:'JetBrains Mono',monospace}

/* RANK */
.ri{display:flex;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.03)}
.ri:last-child{border-bottom:none}
.rn{font-family:'JetBrains Mono',monospace;font-size:.66rem;color:var(--t3);width:18px;text-align:center;flex-shrink:0}
.rg{color:#f59e0b}.rs{color:#94a3b8}.rb{color:#c2733e}
.rnfo{flex:1;min-width:0}
.rname{font-size:.78rem;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rsub{font-size:.6rem;color:var(--t3);font-family:'JetBrains Mono',monospace;margin-top:1px}
.rval{font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem;color:var(--c);flex-shrink:0}

/* FEED */
.fi{display:flex;align-items:flex-start;gap:9px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.03);animation:fadeIn .3s ease both}
.fi:last-child{border-bottom:none}
@keyframes fadeIn{from{opacity:0;transform:translateX(8px)}to{opacity:1;transform:translateX(0)}}
.fd{width:27px;height:27px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.78rem;flex-shrink:0;background:var(--bg-ch)}
.fb{flex:1;min-width:0}
.fm{font-size:.74rem;color:var(--t2);line-height:1.44}
.fm strong{color:var(--t1)}
.ft{font-size:.58rem;font-family:'JetBrains Mono',monospace;color:var(--t3);margin-top:2px}
.famt{font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem;color:var(--g);flex-shrink:0;white-space:nowrap}

/* LOYALTY */
.lyi{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:.74rem}
.lyi:last-child{border-bottom:none}
.lyn{width:130px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--t1)}
.lyp{flex:1;display:flex;align-items:center;gap:5px}
.lyb{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--g);flex-shrink:0;width:30px;text-align:right}

/* INSIGHTS */
.ing{display:grid;grid-template-columns:repeat(3,1fr);gap:.7rem}
@media(max-width:900px){.ing{grid-template-columns:1fr 1fr}}
.inc{background:var(--bg-ch);border:1px solid var(--b0);border-radius:var(--r2);padding:.9rem;display:flex;flex-direction:column;gap:.4rem;transition:transform .2s}
.inc:hover{transform:translateY(-2px)}
.inco{font-size:1.3rem}
.inv{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:var(--t1)}
.inl{font-size:.62rem;color:var(--t3);font-family:'JetBrains Mono',monospace}
.ind{font-size:.68rem;color:var(--t2)}

/* NOTIF PANEL */
#np{position:fixed;top:calc(var(--th) + 6px);right:1rem;width:300px;background:var(--bg-s);border:1px solid var(--b0);border-radius:var(--r3);box-shadow:var(--shl);z-index:500;transform:translateY(-10px) scale(.97);opacity:0;pointer-events:none;transition:all .22s cubic-bezier(.34,1.56,.64,1);overflow:hidden}
#np.open{transform:translateY(0) scale(1);opacity:1;pointer-events:all}
.nph{padding:.75rem 1rem;border-bottom:1px solid var(--b0);display:flex;align-items:center;justify-content:space-between;font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem}
.npl{max-height:290px;overflow-y:auto}
.npi{display:flex;gap:8px;padding:8px 1rem;border-bottom:1px solid rgba(255,255,255,.03);font-size:.7rem;cursor:pointer;transition:background .1s}
.npi:hover{background:var(--bg-ch)}
.npi.unread{background:rgba(0,212,255,.03)}
.npic{width:25px;height:25px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0}
.npt{color:var(--t2);line-height:1.4;font-size:.7rem}
.npt strong{color:var(--t1)}
.nptime{font-size:.56rem;font-family:'JetBrains Mono',monospace;color:var(--t3);margin-top:1px}

/* TOASTS */
#toasts{position:fixed;bottom:1.2rem;right:1.2rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:5px;pointer-events:none}
.toast{display:flex;align-items:center;gap:8px;padding:9px 13px;background:var(--bg-s);border:1px solid var(--b0);border-radius:var(--r2);box-shadow:var(--shl);font-size:.73rem;max-width:300px;pointer-events:all;transform:translateX(110px);opacity:0;transition:all .3s cubic-bezier(.34,1.56,.64,1)}
.toast.show{transform:translateX(0);opacity:1}
.tico{font-size:.95rem;flex-shrink:0}
.tbody{flex:1}
.ttitle{font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem}
.tsub{color:var(--t3);font-size:.63rem;margin-top:1px}
.tx{color:var(--t3);cursor:pointer;padding:0 2px}

/* OVERLAY MOBILE */
#overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:299;opacity:0;pointer-events:none;transition:opacity .3s}
#overlay.show{opacity:1;pointer-events:all}

/* ANIM */
@keyframes slideUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.skel{background:linear-gradient(90deg,rgba(255,255,255,.04) 25%,rgba(255,255,255,.07) 50%,rgba(255,255,255,.04) 75%);background-size:400px;border-radius:5px;animation:shim 1.5s infinite}
.skel-h{height:.85rem;margin-bottom:.35rem}

/* SYNC BANNER */
#sync-banner{display:none;align-items:center;gap:8px;padding:7px 1.25rem;background:rgba(0,255,170,.06);border-bottom:1px solid rgba(0,255,170,.15);font-size:.72rem;color:var(--g)}
#sync-banner.show{display:flex}

/* RESPONSIVE */
@media(max-width:900px){
  #sb{transform:translateX(calc(-1 * var(--sw)));width:var(--sw)!important}
  #sb.mob-open{transform:translateX(0)}
  .main,.main.col{margin-left:0!important}
  .tb-ham{display:flex}
  .kg{grid-template-columns:repeat(2,1fr)}
  .page{padding:1.1rem .9rem 3rem}
  .ph{flex-direction:column}
  .filters{flex-direction:column;align-items:stretch}
  .f-search,.f-inp,.f-date{width:100%}
}
@media(max-width:480px){.kg{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app">

<!-- ═══ SIDEBAR ═══ -->
<aside id="sb">
  <div class="sb-brand">
    <div class="sb-logo">📚</div>
    <div class="sb-nm">Digital <em>Library</em></div>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= $adminInitial ?></div>
    <div class="sb-ui">
      <div class="sb-un"><?= $adminName ?></div>
      <div class="sb-ur">⚡ Administrateur</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-sec">Principal</div>
    <a href="../dashboard.php" class="sb-item"><span class="sb-ico"><i class="bi bi-grid-1x2"></i></span><span class="sb-lbl">Dashboard</span></a>
    <div class="sb-sec">Administration</div>
    <a href="sales.php" class="sb-item active"><span class="sb-ico"><i class="bi bi-cash-coin"></i></span><span class="sb-lbl">Ventes &amp; Bonus</span></a>
    <a href="users.php" class="sb-item"><span class="sb-ico"><i class="bi bi-people"></i></span><span class="sb-lbl">Utilisateurs</span></a>
    <a href="books.php" class="sb-item"><span class="sb-ico"><i class="bi bi-book"></i></span><span class="sb-lbl">Livres</span></a>
  </nav>
  <div class="sb-foot">
    <button type="button" class="sb-col-btn" onclick="toggleSidebar()">
      <span class="ci"><i class="bi bi-chevron-left"></i></span>
      <span class="cl">Réduire</span>
    </button>
  </div>
</aside>
<div id="overlay" onclick="closeMobile()"></div>

<!-- ═══ MAIN ═══ -->
<div class="main" id="main">

  <!-- TOPBAR -->
  <header id="tb">
    <button class="tb-ham" onclick="toggleMobile()"><i class="bi bi-list"></i></button>
    <div class="bc">
      <span>DLS</span><span class="bc-s">/</span><span>Admin</span><span class="bc-s">/</span>
      <span class="bc-c">Ventes &amp; Bonus</span>
    </div>
    <div class="sp"></div>
    <div class="pw">
      <button class="pb" data-p="today">Auj.</button>
      <button class="pb" data-p="week">7j</button>
      <button class="pb active" data-p="month">Mois</button>
      <button class="pb" data-p="year">Année</button>
    </div>
    <button class="rf-btn" onclick="refreshAll()">
      <span class="ri"><i class="bi bi-arrow-clockwise"></i></span>
      <span>Refresh</span>
    </button>
    <div class="tb-acts">
      <button class="tb-btn" id="notif-btn" onclick="toggleNotif()" title="Notifications">
        <i class="bi bi-bell"></i>
        <span class="nb-dot" id="notif-badge" style="display:none"><?= min(9, (int)($initStats['notif_unread'] ?? 0)) ?></span>
      </button>
      <a href="../users/profile.php" class="tb-user">
        <div class="tu-av"><?= $adminInitial ?></div>
        <div><div class="tu-n"><?= $adminName ?></div><div class="tu-r">admin</div></div>
      </a>
    </div>
  </header>

  <!-- SYNC BANNER -->
  <div id="sync-banner">
    <i class="bi bi-arrow-repeat" style="animation:spin .8s linear infinite"></i>
    <span id="sync-msg">Synchronisation des bonus en cours…</span>
  </div>
  <style>@keyframes spin{to{transform:rotate(360deg)}}</style>

  <!-- PAGE -->
  <main class="page">

    <?php if ($dbError): ?>
    <div style="background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);border-radius:var(--r2);padding:1rem 1.2rem;color:var(--ro);font-size:.8rem;margin-bottom:1.1rem;display:flex;align-items:center;gap:8px">
      <i class="bi bi-exclamation-triangle-fill"></i>
      Base de données inaccessible — <?= esc($dbError) ?>
    </div>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="ph">
      <div class="ph-glow"></div>
      <div>
        <div class="ph-title">Gestion des <span>Ventes &amp; Bonus</span></div>
        <div class="ph-sub">Transactions · Fidélité · Statistiques temps réel · <?= date('d M Y, H:i') ?></div>
        <div class="ph-pills">
          <span class="pp live">Live</span>
          <span class="pp">📡 Auto 15s</span>
          <span class="pp">🎁 Bonus tous les <?= (int)($initStats['bonus_rule'] ?? 5) ?> achats</span>
          <span class="pp">🔒 Admin</span>
        </div>
      </div>
      <div class="ph-acts">
        <button class="btn btn-a" onclick="triggerSync()" title="Recalculer tous les bonus depuis les achats réels">
          <i class="bi bi-arrow-repeat"></i> Sync bonus
        </button>
        <button class="btn btn-g" onclick="openBonusModal()">
          <i class="bi bi-gift-fill"></i> Attribuer bonus
        </button>
        <button class="btn btn-pr" onclick="refreshAll()">
          <i class="bi bi-arrow-clockwise"></i> Actualiser
        </button>
      </div>
    </div>

    <!-- KPI GRID -->
    <div class="kg">
      <div class="kpi"><div class="shim"></div>
        <div class="kico" style="background:rgba(0,212,255,.1)">💰</div>
        <div class="kval sm" id="kv-rev-total"><?= fmtFCFA((float)($initStats['rev_total'] ?? 0), true) ?></div>
        <div class="klbl">Chiffre d'affaires total (ventes)</div>
        <div class="kchg neu"><i class="bi bi-dot"></i> Cumulé depuis le début</div>
      </div>
      <div class="kpi"><div class="shim"></div>
        <div class="kico" style="background:rgba(0,255,170,.08)">⚡</div>
        <div class="kval" id="kv-today"><?= (int)($initStats['sales_today'] ?? 0) ?></div>
        <div class="klbl">Transactions aujourd'hui</div>
        <div class="kchg"><div class="ldot" style="margin-right:3px"></div><span id="kv-rev-today"><?= fmtFCFA((float)($initStats['rev_today'] ?? 0), true) ?></span></div>
      </div>
      <div class="kpi"><div class="shim"></div>
        <div class="kico" style="background:rgba(124,58,237,.1)">📅</div>
        <div class="kval" id="kv-month"><?= (int)($initStats['sales_month'] ?? 0) ?></div>
        <div class="klbl">Transactions ce mois</div>
        <div class="kchg" id="chg-month">
          <i class="bi bi-arrow-<?= ($initStats['rev_growth'] ?? 0) >= 0 ? 'up' : 'down' ?>-short <?= ($initStats['rev_growth'] ?? 0) >= 0 ? 'up' : 'dn' ?>"></i>
          <?= abs((float)($initStats['rev_growth'] ?? 0)) ?>% vs mois préc.
        </div>
      </div>
      <div class="kpi"><div class="shim"></div>
        <div class="kico" style="background:rgba(0,255,170,.06)">📈</div>
        <div class="kval sm" id="kv-rev-month"><?= fmtFCFA((float)($initStats['rev_month'] ?? 0), true) ?></div>
        <div class="klbl">Revenus du mois</div>
        <div class="kchg neu"><i class="bi bi-calendar3"></i> <?= date('F Y') ?></div>
      </div>
      <div class="kpi"><div class="shim"></div>
        <div class="kico" style="background:rgba(0,255,170,.08)">🎁</div>
        <div class="kval" id="kv-bonus"><?= (int)($initStats['bonus_total'] ?? 0) ?></div>
        <div class="klbl">Bonus accordés (total)</div>
        <div class="kchg"><i class="bi bi-people"></i> <span id="kv-eligible"><?= (int)($initStats['users_eligible'] ?? 0) ?></span> utilisateurs éligibles</div>
      </div>
      <div class="kpi"><div class="shim"></div>
        <div class="kico" style="background:rgba(245,158,11,.1)">🏆</div>
        <div class="kval" id="kv-year"><?= (int)($initStats['sales_year'] ?? 0) ?></div>
        <div class="klbl">Transactions <?= date('Y') ?></div>
        <div class="kchg neu"><i class="bi bi-dot"></i> <span id="kv-rev-year"><?= fmtFCFA((float)($initStats['rev_year'] ?? 0), true) ?></span></div>
      </div>
      <div class="kpi"><div class="shim"></div>
        <div class="kico" style="background:rgba(0,212,255,.08)">✅</div>
        <div class="kval" id="kv-confirmed"><?= (int)($initStats['confirmed_total'] ?? 0) ?></div>
        <div class="klbl">Confirmées (all time)</div>
        <div class="kchg up"><i class="bi bi-check2-circle"></i> Paiements validés</div>
      </div>
      <div class="kpi"><div class="shim"></div>
        <div class="kico" style="background:rgba(244,63,94,.08)">❌</div>
        <div class="kval" id="kv-failed"><?= (int)($initStats['failed_total'] ?? 0) ?></div>
        <div class="klbl">Échecs de paiement</div>
        <div class="kchg dn"><i class="bi bi-x-circle"></i> Transactions échouées</div>
      </div>
    </div>

    <!-- GRAPHIQUES -->
    <div class="g2" style="animation-delay:.1s">
      <div class="card">
        <div class="ch">
          <div class="ct"><div class="cib" style="background:rgba(0,212,255,.1)"><i class="bi bi-graph-up"></i></div>Évolution des transactions</div>
          <div style="display:flex;gap:4px">
            <button class="btn btn-sm btn-gh chart-type-btn active" data-type="daily" onclick="switchChart('daily',this)">30j</button>
            <button class="btn btn-sm btn-gh chart-type-btn" data-type="monthly" onclick="switchChart('monthly',this)">12 mois</button>
          </div>
        </div>
        <div style="padding:.4rem"><div id="chart-sales" style="min-height:260px"></div></div>
      </div>
      <div class="card">
        <div class="ch">
          <div class="ct"><div class="cib" style="background:rgba(124,58,237,.1)"><i class="bi bi-pie-chart"></i></div>Par catégorie</div>
          <span class="chip c-v">Donut</span>
        </div>
        <div style="padding:.4rem"><div id="chart-cat" style="min-height:260px"></div></div>
      </div>
    </div>

    <!-- GRID 3 -->
    <div class="g3" style="animation-delay:.15s">
      <div class="card">
        <div class="ch"><div class="ct"><div class="cib" style="background:rgba(0,255,170,.08)"><i class="bi bi-credit-card"></i></div>Méthodes de paiement</div></div>
        <div class="cb"><div id="chart-methods" style="min-height:200px"></div></div>
        <div class="cb" style="padding-top:0" id="methods-bars"></div>
      </div>
      <div class="card">
        <div class="ch"><div class="ct"><div class="cib" style="background:rgba(245,158,11,.1)"><i class="bi bi-trophy"></i></div>Top Acheteurs</div><span class="chip c-warn">Classement</span></div>
        <div class="cb" id="top-buyers">
          <div class="skel skel-h" style="width:75%"></div>
          <div class="skel skel-h" style="width:90%;margin-top:.4rem"></div>
          <div class="skel skel-h" style="width:60%;margin-top:.4rem"></div>
        </div>
        <div class="cf"><a href="users.php" class="btn btn-sm btn-gh">Voir tous</a></div>
      </div>
      <div class="card">
        <div class="ch"><div class="ct"><div class="cib" style="background:rgba(0,212,255,.08)"><i class="bi bi-lightning-charge"></i></div>Insights &amp; Prédictions</div><span class="chip c-info">IA</span></div>
        <div class="cb" id="insights-body">
          <div class="skel skel-h" style="width:80%"></div>
          <div class="skel skel-h" style="width:60%;margin-top:.4rem"></div>
        </div>
      </div>
    </div>

    <!-- FIDÉLITÉ BONUS -->
    <div class="card" style="margin-bottom:1.1rem;animation-delay:.2s">
      <div class="ch">
        <div class="ct"><div class="cib" style="background:rgba(0,255,170,.08)"><i class="bi bi-gift-fill"></i></div>Système de fidélité &amp; Bonus</div>
        <div style="display:flex;gap:5px;align-items:center">
          <span class="chip c-bonus" id="loyalty-badge">— éligibles</span>
          <button class="btn btn-sm btn-a" onclick="triggerSync()" title="Recalculer depuis les achats réels"><i class="bi bi-arrow-repeat"></i> Sync</button>
          <button class="btn btn-sm btn-g" onclick="openBonusModal()"><i class="bi bi-gift"></i> Attribuer bonus</button>
        </div>
      </div>
      <div class="cb">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.8rem;margin-bottom:1rem">
          <div class="inc"><div class="inco">🎯</div><div class="inv" id="loy-rule"><?= (int)($initStats['bonus_rule'] ?? 5) ?></div><div class="inl">Achats requis / bonus</div></div>
          <div class="inc"><div class="inco">🏅</div><div class="inv" id="loy-total">0</div><div class="inl">Bonus accordés (total)</div></div>
          <div class="inc"><div class="inco">👥</div><div class="inv" id="loy-elig">0</div><div class="inl">Utilisateurs éligibles</div></div>
        </div>
        <div id="loyalty-list">
          <div class="skel skel-h" style="width:100%"></div>
          <div class="skel skel-h" style="width:85%;margin-top:.4rem"></div>
        </div>
      </div>
    </div>

    <!-- TOP LIVRES -->
    <div class="card" style="margin-bottom:1.1rem;animation-delay:.25s">
      <div class="ch">
        <div class="ct"><div class="cib" style="background:rgba(124,58,237,.1)"><i class="bi bi-book-half"></i></div>Top Livres — Ventes &amp; Bonus</div>
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
            <tr><th>#</th><th>Livre</th><th>Catégorie</th><th>Accès</th><th>Prix</th><th>Ventes</th><th>Bonus</th><th>Revenus</th><th>Lectures</th><th>Note</th></tr>
          </thead>
          <tbody id="books-tbody">
            <tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--t3)">Chargement…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="cf">
        <span id="books-count" style="font-size:.68rem;color:var(--t3)"></span>
        <button class="btn btn-sm btn-gh" onclick="loadTopBooks(currentSort, null, 20)">Voir plus</button>
      </div>
    </div>

    <!-- TRANSACTIONS + LIVE FEED -->
    <div class="g2" style="margin-bottom:1.1rem;animation-delay:.3s">
      <div class="card">
        <div class="ch">
          <div class="ct"><div class="cib" style="background:rgba(0,255,170,.06)"><i class="bi bi-receipt"></i></div>Transactions<div class="ldot"></div></div>
          <div style="display:flex;gap:4px;align-items:center">
            <span id="tx-total-badge" class="chip c-ok"></span>
            <button class="btn btn-sm btn-gh" onclick="loadTransactions()"><i class="bi bi-arrow-clockwise"></i></button>
          </div>
        </div>
        <!-- Filtres -->
        <div style="padding:.7rem 1.1rem;border-bottom:1px solid var(--b0)">
          <div class="filters">
            <input type="search" class="f-inp f-search" id="f-search" placeholder="🔍 Nom, email, livre, référence…" oninput="debounceFilter()" autocomplete="off">
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
            <button class="btn btn-sm btn-e" onclick="clearFilters()"><i class="bi bi-x-circle"></i> Reset</button>
            <select class="f-inp" id="f-sort" onchange="loadTransactions()" style="min-width:130px">
              <option value="date">Tri : Date ↓</option>
              <option value="montant">Tri : Montant ↓</option>
              <option value="user">Tri : Utilisateur</option>
              <option value="livre">Tri : Livre</option>
            </select>
            <a class="btn btn-sm btn-gh" href="?action=export_csv&type=all" target="_blank"><i class="bi bi-download"></i> CSV</a>
          </div>
        </div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead>
              <tr><th>Réf.</th><th>Acheteur</th><th>Livre</th><th>Type</th><th>Méthode</th><th>Montant</th><th>Date</th><th>Statut</th><th>Action</th></tr>
            </thead>
            <tbody id="tx-tbody">
              <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--t3)">Chargement…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="cf">
          <div style="display:flex;gap:3px;align-items:center">
            <button class="btn btn-xs btn-gh" onclick="txPage(Math.max(1,currentTxPage-1))"><i class="bi bi-chevron-left"></i></button>
            <span id="tx-page-info" style="font-size:.62rem;font-family:'JetBrains Mono',monospace;color:var(--t3)">1/1</span>
            <button class="btn btn-xs btn-gh" onclick="txPage(currentTxPage+1)"><i class="bi bi-chevron-right"></i></button>
          </div>
          <span id="tx-total-label" style="font-size:.66rem;color:var(--t3)"></span>
        </div>
      </div>

      <!-- Live Feed -->
      <div class="card">
        <div class="ch">
          <div class="ct"><div class="cib" style="background:rgba(0,212,255,.06)"><i class="bi bi-activity"></i></div>Activité récente<div class="ldot"></div></div>
          <button class="btn btn-sm btn-g" onclick="loadFeed()"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="cb" style="max-height:480px;overflow-y:auto" id="live-feed">
          <div style="text-align:center;padding:2rem;color:var(--t3)">Chargement…</div>
        </div>
      </div>
    </div>

    <!-- REVENUS 12 MOIS -->
    <div class="card" style="margin-bottom:1.1rem;animation-delay:.35s">
      <div class="ch">
        <div class="ct"><div class="cib" style="background:rgba(0,255,170,.06)"><i class="bi bi-bar-chart-line"></i></div>Revenus mensuels — 12 mois (ventes réelles uniquement)</div>
        <span class="chip c-ok">Tendance annuelle</span>
      </div>
      <div style="padding:.4rem"><div id="chart-revenue" style="min-height:250px"></div></div>
    </div>

  </main>
</div><!-- /main -->
</div><!-- /app -->

<!-- ═══ MODAL BONUS ═══ -->
<div class="mbg" id="bonus-modal">
  <div class="modal">
    <button class="m-x" onclick="closeBonusModal()"><i class="bi bi-x-lg"></i></button>
    <div class="m-title">🎁 Attribuer un Bonus</div>
    <div class="m-sub">Offrir un livre gratuitement à un utilisateur éligible (tous les <?= (int)($initStats['bonus_rule'] ?? 5) ?> achats confirmés).</div>

    <label class="m-lbl" for="bonus-user-input">Utilisateur</label>
    <div style="position:relative;margin-bottom:.85rem">
      <input type="text" class="m-inp" id="bonus-user-input"
             placeholder="Rechercher par nom ou email…"
             autocomplete="off"
             oninput="debounceUserSearch(this.value)"
             style="margin-bottom:0;width:100%">
      <div class="sl" id="user-suggest"></div>
    </div>
    <input type="hidden" id="bonus-user-id">
    <div id="user-info-bar" style="display:none;background:rgba(0,212,255,.05);border:1px solid rgba(0,212,255,.12);border-radius:var(--r1);padding:6px 10px;margin-bottom:.85rem;font-size:.72rem;color:var(--c)"></div>

    <label class="m-lbl" for="bonus-book-input">Livre à offrir</label>
    <div style="position:relative;margin-bottom:.85rem">
      <input type="text" class="m-inp" id="bonus-book-input"
             placeholder="Rechercher un livre…"
             autocomplete="off"
             oninput="debounceBookSearch(this.value)"
             style="margin-bottom:0;width:100%">
      <div class="sl" id="book-suggest"></div>
    </div>
    <input type="hidden" id="bonus-book-id">

    <div class="elig" id="elig-box">
      <div id="elig-msg"></div>
      <div class="m-stats" id="elig-stats" style="display:none"></div>
    </div>

    <div style="display:flex;gap:7px;margin-top:.5rem">
      <button class="btn btn-gh" style="flex:1;justify-content:center" onclick="checkEligibility()">
        <i class="bi bi-search"></i> Vérifier éligibilité
      </button>
      <button class="btn btn-g" style="flex:1;justify-content:center" id="btn-grant" onclick="grantBonus()" disabled>
        <i class="bi bi-gift-fill"></i> Attribuer
      </button>
    </div>
    <p style="font-size:.6rem;color:var(--t3);text-align:center;margin-top:.7rem">
      <i class="bi bi-shield-check"></i> Vérification depuis la BD · Anti-doublon · Transaction atomique
    </p>
  </div>
</div>

<!-- ═══ MODAL DÉTAIL TRANSACTION ═══ -->
<div class="mbg" id="detail-modal">
  <div class="modal" style="max-width:480px">
    <button class="m-x" onclick="closeDetail()"><i class="bi bi-x-lg"></i></button>
    <div class="m-title" id="det-title">Détails</div>
    <div style="margin-top:1rem" id="det-body"></div>
    <div style="display:flex;gap:7px;margin-top:1.2rem" id="det-acts"></div>
  </div>
</div>

<!-- ═══ NOTIF PANEL ═══ -->
<div id="np">
  <div class="nph">
    <span>Notifications</span>
    <button class="btn btn-xs btn-gh" onclick="markRead()">Tout lire</button>
  </div>
  <div class="npl" id="notif-list">
    <div style="padding:1.5rem;text-align:center;color:var(--t3);font-size:.73rem">Chargement…</div>
  </div>
  <div class="cf" style="padding:.6rem 1rem">
    <a href="notifications.php" class="btn btn-sm btn-gh">Voir toutes</a>
    <span id="notif-time" style="font-size:.58rem;color:var(--t3);font-family:'JetBrains Mono',monospace"></span>
  </div>
</div>

<!-- ═══ TOASTS ═══ -->
<div id="toasts"></div>

<!-- ═══ SCRIPTS ═══ -->
<script>
/* ══════════════════════════════════════════════
   GLOBALS
══════════════════════════════════════════════ */
const INIT  = <?= $initJson ?>;
const CSRF  = <?= $csrfJson ?>;
const ANAME = <?= $nameJson ?>;
const API   = 'sales.php';

let currentPeriod   = 'month';
let currentSort     = 'revenue';
let currentTxPage   = 1;
let totalTxPages    = 1;
let sidebarCol      = false;
let filterTimer     = null;
let userTimer       = null;
let bookTimer       = null;
let autoRefTimer    = null;
let eligChecked     = false;
let currentChartType = 'daily';

let chartSales   = null;
let chartRevenue = null;
let chartCat     = null;
let chartMethods = null;

/* ══════════════════════════════════════════════
   UTILS
══════════════════════════════════════════════ */
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function fmtFCFA(n, sh = false) {
  const v = parseFloat(n) || 0;
  if (sh) {
    if (v >= 1e6) return (v / 1e6).toFixed(1).replace('.', ',') + ' M';
    if (v >= 1e3) return Math.round(v / 1e3) + ' K';
  }
  return new Intl.NumberFormat('fr-FR').format(Math.round(v)) + ' FCFA';
}
function fmtN(n) { return new Intl.NumberFormat('fr-FR').format(parseInt(n) || 0); }
function timeAgo(dt) {
  if (!dt) return '—';
  const diff = Math.floor((Date.now() - new Date(dt)) / 1000);
  if (diff < 60) return "à l'instant";
  if (diff < 3600) return Math.floor(diff / 60) + ' min';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h';
  return new Date(dt).toLocaleDateString('fr-FR');
}
function gc(v) { return parseFloat(v) >= 0 ? 'up' : 'dn'; }
function gi(v) { return parseFloat(v) >= 0 ? 'arrow-up-short' : 'arrow-down-short'; }

/* ══════════════════════════════════════════════
   FETCH HELPER
══════════════════════════════════════════════ */
async function api(action, params = {}, opts = {}) {
  const url = new URL(API, location.href);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  const cfg = { headers: { 'X-Requested-With': 'XMLHttpRequest' }, ...opts };
  if (opts.method === 'POST') {
    cfg.headers['Content-Type'] = 'application/json';
    cfg.body = JSON.stringify({ ...opts.body, csrf: CSRF });
  }
  try {
    const r = await fetch(url.toString(), cfg);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return await r.json();
  } catch (e) {
    console.error('[API]', action, e);
    return null;
  }
}

/* ══════════════════════════════════════════════
   TOAST
══════════════════════════════════════════════ */
const TICO = { info:'ℹ️', success:'✅', warn:'⚠️', error:'🔴' };
const TBC  = { info:'var(--c)', success:'var(--g)', warn:'var(--am)', error:'var(--ro)' };
function toast(title, sub = '', type = 'info', dur = 3500) {
  const s = document.getElementById('toasts');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = TBC[type] || TBC.info;
  t.innerHTML = `<span class="tico">${TICO[type]||'ℹ️'}</span>
    <div class="tbody"><div class="ttitle">${esc(title)}</div>${sub ? `<div class="tsub">${esc(sub)}</div>` : ''}</div>
    <span class="tx" onclick="this.parentElement.remove()">✕</span>`;
  s.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 320); }, dur);
}

/* ══════════════════════════════════════════════
   SIDEBAR
══════════════════════════════════════════════ */
function toggleSidebar() {
  sidebarCol = !sidebarCol;
  document.getElementById('sb').classList.toggle('col', sidebarCol);
  document.getElementById('main').classList.toggle('col', sidebarCol);
}
function toggleMobile() {
  document.getElementById('sb').classList.toggle('mob-open');
  document.getElementById('overlay').classList.toggle('show');
}
function closeMobile() {
  document.getElementById('sb').classList.remove('mob-open');
  document.getElementById('overlay').classList.remove('show');
}

/* ══════════════════════════════════════════════
   PÉRIODE
══════════════════════════════════════════════ */
document.querySelectorAll('.pb').forEach(btn => btn.addEventListener('click', e => {
  document.querySelectorAll('.pb').forEach(b => b.classList.remove('active'));
  e.currentTarget.classList.add('active');
  currentPeriod = e.currentTarget.dataset.p;
  loadStats();
}));

/* ══════════════════════════════════════════════
   KPI ANIMATION
══════════════════════════════════════════════ */
function animVal(id, target, isFCFA = false) {
  const el = document.getElementById(id);
  if (!el) return;
  const old = parseFloat(el.dataset.raw || 0) || 0;
  el.dataset.raw = target;
  const dur = 700, step = 14, steps = Math.ceil(dur / step);
  let cur = old, inc = (target - old) / steps, i = 0;
  const t = setInterval(() => {
    i++;
    cur += inc;
    if (i >= steps) { cur = target; clearInterval(t); }
    el.textContent = isFCFA ? fmtFCFA(cur, true) : fmtN(cur);
  }, step);
}

async function loadStats() {
  const d = await api('live_stats', { period: currentPeriod });
  if (!d) return;
  animVal('kv-rev-total', d.rev_total || 0, true);
  animVal('kv-today', d.sales_today || 0);
  animVal('kv-month', d.sales_month || 0);
  animVal('kv-rev-month', d.rev_month || 0, true);
  animVal('kv-bonus', d.bonus_count || 0);
  animVal('kv-year', d.sales_year || 0);
  animVal('kv-confirmed', d.confirmed_total || 0);
  animVal('kv-failed', d.failed_total || 0);
  const rt = document.getElementById('kv-rev-today'); if (rt) rt.textContent = fmtFCFA(d.rev_today || 0, true);
  const ry = document.getElementById('kv-rev-year');  if (ry) ry.textContent = fmtFCFA(d.rev_year || 0, true);
  const el = document.getElementById('kv-eligible');  if (el) el.textContent = fmtN(d.users_eligible || 0);
  const chgM = document.getElementById('chg-month');
  if (chgM) {
    const g = parseFloat(d.rev_growth || 0);
    chgM.innerHTML = `<i class="bi bi-${gi(g)} ${gc(g)}"></i>${Math.abs(g).toFixed(1)}% vs période préc.`;
  }
}

/* ══════════════════════════════════════════════
   APEX CONFIG BASE
══════════════════════════════════════════════ */
const APEX = {
  chart: { background: 'transparent', toolbar: { show: false }, fontFamily: "'JetBrains Mono',monospace" },
  theme: { mode: 'dark' },
  grid: { borderColor: 'rgba(255,255,255,.05)', strokeDashArray: 4 },
  tooltip: { theme: 'dark' },
  colors: ['#00d4ff','#7c3aed','#00ffaa','#f59e0b','#f43f5e']
};

/* ══════════════════════════════════════════════
   CHART VENTES
══════════════════════════════════════════════ */
async function loadChartSales(type = currentChartType) {
  currentChartType = type;
  const d = await api('chart_data', { type });
  if (!d?.data) return;
  const rows = d.data;
  const labels  = rows.map(r => r.label);
  const sales   = rows.map(r => parseInt(r.sales)   || 0);
  const revenue = rows.map(r => parseFloat(r.rev_ventes || r.revenue) || 0);
  const bonus   = rows.map(r => parseInt(r.nb_bonus) || 0);

  const opts = { ...APEX,
    chart: { ...APEX.chart, type: 'area', height: 260, animations: { enabled: true, easing: 'easeinout', speed: 600 } },
    series: [
      { name: 'Transactions', data: sales, type: 'area' },
      { name: 'Revenus (FCFA)', data: revenue, type: 'line' },
      { name: 'Bonus', data: bonus, type: 'bar' }
    ],
    xaxis: { categories: labels, labels: { style: { colors: '#6b7280', fontSize: '10px' } } },
    yaxis: [
      { title: { text: 'Transactions', style: { color: '#00d4ff' } }, labels: { style: { colors: '#6b7280', fontSize: '10px' } } },
      { opposite: true, title: { text: 'Revenus', style: { color: '#00ffaa' } }, labels: { formatter: v => fmtFCFA(v, true), style: { colors: '#6b7280', fontSize: '10px' } } },
      { show: false }
    ],
    fill: { type: ['gradient','solid','solid'], gradient: { shade: 'dark', type: 'vertical', shadeIntensity: .4, gradientToColors: ['#7c3aed'], opacityFrom: .7, opacityTo: .05, stops: [0,100] } },
    stroke: { width: [2,2,0], curve: 'smooth' },
    markers: { size: [3,4,0], colors: ['#00d4ff','#00ffaa','#00ffaa'], strokeColors: '#05080f', strokeWidth: 2 },
    tooltip: { shared: true, y: [{ formatter: v => v + ' transaction(s)' }, { formatter: v => fmtFCFA(v) }, { formatter: v => v + ' bonus' }] },
    legend: { labels: { colors: '#9ca3af' }, fontSize: '11px' },
    plotOptions: { bar: { columnWidth: '40%', borderRadius: 3 } },
    dataLabels: { enabled: false },
  };
  if (chartSales) chartSales.destroy();
  chartSales = new ApexCharts(document.getElementById('chart-sales'), opts);
  chartSales.render();
}
function switchChart(type, btn) {
  document.querySelectorAll('.chart-type-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadChartSales(type);
}

/* ══════════════════════════════════════════════
   CHART REVENUS MENSUEL
══════════════════════════════════════════════ */
async function loadChartRevenue() {
  const d = await api('chart_data', { type: 'monthly' });
  if (!d?.data) return;
  const rows = d.data;
  const opts = { ...APEX,
    chart: { ...APEX.chart, type: 'bar', height: 250, animations: { enabled: true, speed: 700 } },
    series: [
      { name: 'Revenus ventes (FCFA)', data: rows.map(r => parseFloat(r.rev_ventes || r.revenue) || 0) },
      { name: 'Transactions', data: rows.map(r => parseInt(r.sales) || 0) }
    ],
    xaxis: { categories: rows.map(r => r.label), labels: { style: { colors: '#6b7280', fontSize: '10px' } } },
    yaxis: [
      { labels: { formatter: v => fmtFCFA(v, true), style: { colors: '#6b7280', fontSize: '10px' } } },
      { opposite: true, labels: { style: { colors: '#6b7280', fontSize: '10px' } } }
    ],
    plotOptions: { bar: { columnWidth: '58%', borderRadius: 4 } },
    fill: { type: 'gradient', gradient: { shade: 'dark', type: 'vertical', shadeIntensity: .3, gradientToColors: ['#7c3aed','#00c87a'], opacityFrom: .9, opacityTo: .7 } },
    tooltip: { y: [{ formatter: v => fmtFCFA(v) }, { formatter: v => v + ' tx' }] },
    dataLabels: { enabled: false },
    legend: { labels: { colors: '#9ca3af' }, fontSize: '11px' },
  };
  if (chartRevenue) chartRevenue.destroy();
  chartRevenue = new ApexCharts(document.getElementById('chart-revenue'), opts);
  chartRevenue.render();
}

/* ══════════════════════════════════════════════
   CHART CAT DONUT
══════════════════════════════════════════════ */
async function loadChartCat() {
  const d = await api('top_categories');
  if (!d?.categories || !d.categories.length) return;
  const cats = d.categories;
  const colors = ['#00d4ff','#7c3aed','#00ffaa','#f59e0b','#f43f5e','#f97316','#38bdf8','#a78bfa'];
  const totalRev = cats.reduce((a, c) => a + (parseFloat(c.revenue) || 0), 0);
  const opts = { ...APEX,
    chart: { ...APEX.chart, type: 'donut', height: 260 },
    series: cats.map(c => parseFloat(c.revenue) || 0),
    labels: cats.map(c => c.nom),
    colors: colors.slice(0, cats.length),
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', color: '#9ca3af', formatter: () => fmtFCFA(totalRev, true) } } } } },
    legend: { show: false },
    tooltip: { y: { formatter: v => fmtFCFA(v) } },
    dataLabels: { enabled: false },
    stroke: { show: false },
  };
  if (chartCat) chartCat.destroy();
  chartCat = new ApexCharts(document.getElementById('chart-cat'), opts);
  chartCat.render();
}

/* ══════════════════════════════════════════════
   CHART MÉTHODES
══════════════════════════════════════════════ */
const MICO = { orange_money:'🟠', mobile_money:'🟡', carte:'💳' };
const MNME = { orange_money:'Orange Money', mobile_money:'MTN MoMo', carte:'Carte' };
async function loadChartMethods() {
  const d = await api('payment_methods');
  if (!d?.methods || !d.methods.length) return;
  const ms = d.methods;
  const total = ms.reduce((a, m) => a + (parseFloat(m.total) || 0), 0) || 1;
  const colors = ['#f59e0b','#f97316','#00d4ff','#7c3aed'];
  const opts = { ...APEX,
    chart: { ...APEX.chart, type: 'radialBar', height: 200 },
    series: ms.map(m => Math.round(((parseFloat(m.total) || 0) / total) * 100)),
    labels: ms.map(m => MNME[m.methode] || m.methode),
    colors: colors.slice(0, ms.length),
    plotOptions: { radialBar: { hollow: { size: '30%' }, track: { background: 'rgba(255,255,255,.05)' }, dataLabels: { show: true, total: { show: true, label: 'Paiements', color: '#9ca3af', fontSize: '10px' }, value: { color: '#eef2ff', fontSize: '14px', fontFamily: "'Syne',sans-serif", fontWeight: '800' } } } },
    stroke: { lineCap: 'round' },
    legend: { show: false },
  };
  if (chartMethods) chartMethods.destroy();
  chartMethods = new ApexCharts(document.getElementById('chart-methods'), opts);
  chartMethods.render();

  const el = document.getElementById('methods-bars');
  if (el) el.innerHTML = ms.map((m, i) => {
    const p = Math.round(((parseFloat(m.total) || 0) / total) * 100);
    return `<div style="display:flex;align-items:center;gap:7px;font-size:.73rem;margin-bottom:.4rem">
      <span style="font-size:1rem;width:22px;text-align:center">${MICO[m.methode]||'💳'}</span>
      <span style="width:100px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(MNME[m.methode]||m.methode)}</span>
      <div class="pgw" style="flex:1"><div class="pg pg-a" style="width:${p}%"></div></div>
      <span style="font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--t3);flex-shrink:0;width:32px;text-align:right">${p}%</span>
    </div>`;
  }).join('');
}

/* ══════════════════════════════════════════════
   TOP LIVRES
══════════════════════════════════════════════ */
const ACHIP = { premium:'c-pr', standard:'c-std', gratuit:'c-free' };
const ALBL  = { premium:'⭐ Premium', standard:'📘 Standard', gratuit:'🆓 Gratuit' };
async function loadTopBooks(sort = 'revenue', btn = null, limit = 10) {
  currentSort = sort;
  if (btn) {
    document.querySelectorAll('.sort-b').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }
  const tb = document.getElementById('books-tbody');
  tb.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:1.5rem;color:var(--t3)">Chargement…</td></tr>';
  const d = await api('top_books', { sort, limit });
  if (!d?.books) { tb.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--ro)">Erreur</td></tr>'; return; }
  const ri = ['🥇','🥈','🥉'];
  tb.innerHTML = !d.books.length
    ? '<tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--t3)">Aucun livre avec des transactions</td></tr>'
    : d.books.map((b, i) => {
        const rn = i < 3 ? `<span style="font-size:.95rem">${ri[i]}</span>` : `<span class="td-m" style="color:var(--t3)">${i+1}</span>`;
        const at = b.access_type || 'standard';
        const note = parseFloat(b.note_moyenne) || 0;
        const bs = parseInt(b.is_bestseller) ? '<span class="chip c-warn" style="font-size:.5rem;margin-left:2px">BS</span>' : '';
        return `<tr>
          <td>${rn}</td>
          <td><div style="max-width:190px"><div class="td-hi" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(b.titre)}${bs}</div>
          <div style="font-size:.6rem;color:var(--t3);font-family:'JetBrains Mono',monospace;margin-top:1px">${esc(b.auteur)}</div></div></td>
          <td><span class="chip c-v">${esc(b.cat_icon||'📚')} ${esc(b.categorie||'—')}</span></td>
          <td><span class="chip ${ACHIP[at]||'c-mu'}">${ALBL[at]||at}</span></td>
          <td class="td-m">${parseFloat(b.prix) > 0 ? fmtFCFA(parseFloat(b.prix), true) : '<span class="chip c-free">Gratuit</span>'}</td>
          <td class="td-hi td-m">${fmtN(b.sales_count)}</td>
          <td class="td-m" style="color:var(--g)">${fmtN(b.bonus_count)}</td>
          <td class="td-m" style="color:var(--g)">${fmtFCFA(b.revenue_ventes||0, true)}</td>
          <td class="td-m">${fmtN(b.nb_lectures)}</td>
          <td class="td-m" style="color:var(--am)">${note.toFixed(1)}</td>
        </tr>`;
      }).join('');
  const cnt = document.getElementById('books-count');
  if (cnt) cnt.textContent = `${d.books.length} livre(s) affiché(s)`;
}

/* ══════════════════════════════════════════════
   TRANSACTIONS
══════════════════════════════════════════════ */
function debounceFilter() { clearTimeout(filterTimer); filterTimer = setTimeout(() => loadTransactions(1), 350); }
function clearFilters() {
  ['f-search','f-from','f-to'].forEach(id => { const e = document.getElementById(id); if (e) e.value = ''; });
  ['f-type','f-cat','f-method','f-sort'].forEach(id => { const e = document.getElementById(id); if (e) e.value = ''; });
  loadTransactions(1);
}

async function loadTransactions(page = currentTxPage) {
  currentTxPage = page;
  const tb = document.getElementById('tx-tbody');
  tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:1.5rem;color:var(--t3)">Chargement…</td></tr>';
  const d = await api('transactions', {
    page, limit: 15,
    search:    document.getElementById('f-search')?.value  || '',
    type:      document.getElementById('f-type')?.value    || '',
    cat:       document.getElementById('f-cat')?.value     || '',
    method:    document.getElementById('f-method')?.value  || '',
    date_from: document.getElementById('f-from')?.value    || '',
    date_to:   document.getElementById('f-to')?.value      || '',
    sort:      document.getElementById('f-sort')?.value    || 'date',
    dir:       'desc',
  });
  if (!d?.transactions) { tb.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--ro)">Erreur de chargement</td></tr>'; return; }
  totalTxPages = d.pages || 1;
  const badge = document.getElementById('tx-total-badge');
  if (badge) badge.textContent = fmtN(d.total) + ' transaction(s)';
  const lbl = document.getElementById('tx-total-label');
  if (lbl) lbl.textContent = `Page ${page}/${totalTxPages}`;
  const pi = document.getElementById('tx-page-info');
  if (pi) pi.textContent = `${page}/${totalTxPages}`;

  if (!d.transactions.length) {
    tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--t3)">Aucune transaction trouvée</td></tr>';
    return;
  }
  tb.innerHTML = d.transactions.map(tx => {
    const isBonus = tx.type_transaction === 'bonus_accorde';
    const dt = new Date(tx.created_at);
    const dtStr = dt.toLocaleDateString('fr-FR') + ' ' + dt.toLocaleTimeString('fr-FR', { hour:'2-digit', minute:'2-digit' });
    return `<tr>
      <td class="td-m" style="font-size:.58rem;color:var(--t3)">${esc(tx.reference||'—')}</td>
      <td><div class="td-hi" style="white-space:nowrap;font-size:.74rem">${esc(tx.user_name)}</div>
          <div style="font-size:.56rem;color:var(--t3)">${esc(tx.email)}</div></td>
      <td><div style="max-width:145px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.74rem;color:var(--t1)">${esc(tx.livre_titre)}</div>
          <div style="font-size:.56rem;color:var(--t3)">${esc(tx.categorie||'—')}</div></td>
      <td><span class="chip ${isBonus?'c-bonus':'c-vente'}">${isBonus?'🎁 Bonus':'💳 Vente'}</span></td>
      <td class="td-m" style="font-size:.65rem">${MICO[tx.methode]||'💳'} ${esc(MNME[tx.methode]||tx.methode||'—')}</td>
      <td class="td-m" style="color:var(--g);font-weight:700">${isBonus ? '<span style="font-size:.65rem">Gratuit</span>' : fmtFCFA(parseFloat(tx.montant)||0, true)}</td>
      <td class="td-m" style="font-size:.62rem;white-space:nowrap">${dtStr}</td>
      <td><span class="chip ${tx.statut==='confirme'?'c-ok':tx.statut==='echec'?'c-err':'c-warn'}">${esc(tx.statut)}</span></td>
      <td>
        <div style="display:flex;gap:3px">
          <button class="btn btn-xs btn-gh" onclick="showDetail(${JSON.stringify(tx).replace(/"/g,'&quot;')})" title="Détails"><i class="bi bi-eye"></i></button>
          ${tx.statut==='confirme' ? `<button class="btn btn-xs btn-e" onclick="cancelTx(${tx.id})" title="Annuler"><i class="bi bi-x-circle"></i></button>` : ''}
        </div>
      </td>
    </tr>`;
  }).join('');
}

function txPage(p) { if (p < 1 || p > totalTxPages) return; loadTransactions(p); }

/* ══════════════════════════════════════════════
   LIVE FEED
══════════════════════════════════════════════ */
async function loadFeed() {
  const d = await api('transactions', { page: 1, limit: 10, sort: 'date', dir: 'desc' });
  const el = document.getElementById('live-feed');
  if (!el) return;
  if (!d?.transactions) { el.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--t3)">Erreur</div>'; return; }
  const icons = ['🛍️','💰','📚','⚡','💳','🎁'];
  el.innerHTML = d.transactions.map((tx, i) => {
    const isBonus = tx.type_transaction === 'bonus_accorde';
    return `<div class="fi" style="animation-delay:${i*.04}s">
      <div class="fd">${isBonus?'🎁':icons[i%icons.length]}</div>
      <div class="fb">
        <div class="fm"><strong>${esc(tx.user_name)}</strong> ${isBonus?'a reçu en <strong>bonus</strong>':'a acheté'} <strong>${esc(tx.livre_titre)}</strong></div>
        <div class="ft">${timeAgo(tx.created_at)} · <span class="chip ${isBonus?'c-bonus':'c-vente'}" style="font-size:.5rem">${isBonus?'🎁 Bonus':'💳 Vente'}</span></div>
      </div>
      <div class="famt">${isBonus ? '<span style="color:var(--g);font-size:.68rem">Gratuit</span>' : fmtFCFA(parseFloat(tx.montant)||0, true)}</div>
    </div>`;
  }).join('') || '<div style="text-align:center;padding:2rem;color:var(--t3)">Aucune transaction récente</div>';
}

/* ══════════════════════════════════════════════
   TOP ACHETEURS
══════════════════════════════════════════════ */
const RC = ['rg','rs','rb'];
async function loadTopBuyers() {
  const d = await api('top_buyers', { limit: 7 });
  const el = document.getElementById('top-buyers');
  if (!el) return;
  if (!d?.buyers || !d.buyers.length) {
    el.innerHTML = '<p style="text-align:center;color:var(--t3);font-size:.73rem;padding:1rem">Aucun achat confirmé</p>';
    return;
  }
  const bonusRule = INIT.bonus_rule || 5;
  el.innerHTML = d.buyers.map((b, i) => `<div class="ri">
    <span class="rn ${RC[i]||''}">${i+1}</span>
    <div class="rnfo">
      <div class="rname">${esc((b.prenom||'') + ' ' + (b.nom||''))}</div>
      <div class="rsub">${fmtN(b.purchases)} achat(s) · ${b.bonus > 0 ? '🎁 ' + b.bonus + ' bonus dispo.' : timeAgo(b.last_purchase)}</div>
    </div>
    <div class="rval">${fmtFCFA(parseFloat(b.total_spent)||0, true)}</div>
  </div>`).join('');
}

/* ══════════════════════════════════════════════
   FIDÉLITÉ BONUS
══════════════════════════════════════════════ */
async function loadLoyalty() {
  const d = await api('loyalty_stats');
  if (!d) return;
  const bonusRule = d.bonus_rule || 5;
  const r = document.getElementById('loy-rule');  if (r) r.textContent = bonusRule;
  const g = document.getElementById('loy-total'); if (g) g.textContent = fmtN(d.total_bonus_granted || 0);
  const e = document.getElementById('loy-elig');  if (e) e.textContent = fmtN(d.eligible || 0);
  const badge = document.getElementById('loyalty-badge');
  if (badge) badge.textContent = `${d.eligible || 0} éligible(s)`;

  const el = document.getElementById('loyalty-list');
  if (!el) return;
  if (!d.progression?.length) {
    el.innerHTML = '<p style="text-align:center;color:var(--t3);font-size:.73rem;padding:1rem">Aucune progression enregistrée</p>';
    return;
  }
  el.innerHTML = d.progression.map(u => {
    const achatsReels = parseInt(u.total_achats) || 0;
    const progress = parseInt(u.achat_count) || (achatsReels % bonusRule);
    const pct = Math.min(100, Math.round((progress / bonusRule) * 100));
    return `<div class="lyi">
      <div class="lyn" title="${esc(u.email)}">${esc((u.name || u.email || '—').substring(0, 22))}</div>
      <div class="lyp">
        <div class="pgw"><div class="pg ${pct >= 100 ? 'pg-g' : 'pg-c'}" style="width:${pct}%"></div></div>
        <span style="font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--t3);flex-shrink:0">${progress}/${bonusRule}</span>
      </div>
      <span class="lyb">${parseInt(u.bonus_restant) > 0 ? '🎁 ' + u.bonus_restant : ''}</span>
      <span class="chip ${parseInt(u.bonus_restant) > 0 ? 'c-bonus' : 'c-mu'}" style="font-size:.5rem;flex-shrink:0;margin-left:4px">${u.bonus_total||0} total</span>
    </div>`;
  }).join('');
}

/* ══════════════════════════════════════════════
   INSIGHTS
══════════════════════════════════════════════ */
async function loadInsights() {
  const el = document.getElementById('insights-body');
  if (!el) return;
  const d = await api('insights');
  if (!d) { el.innerHTML = '<p style="color:var(--ro);font-size:.73rem">Erreur</p>'; return; }
  const g = parseFloat(d.growth_vs_avg || 0);
  const dayMap = { Monday:'Lundi',Tuesday:'Mardi',Wednesday:'Mercredi',Thursday:'Jeudi',Friday:'Vendredi',Saturday:'Samedi',Sunday:'Dimanche' };
  el.innerHTML = `<div class="ing">
    <div class="inc"><div class="inco">🔮</div><div class="inv">${fmtFCFA(d.projected_month||0, true)}</div><div class="inl">Projection du mois</div><div class="ind">Basé sur le rythme actuel</div></div>
    <div class="inc"><div class="inco">📊</div><div class="inv">${fmtFCFA(d.avg_monthly||0, true)}</div><div class="inl">Moy. 6 mois</div><div class="ind"><span class="${gc(g)}">${g >= 0 ? '+' : ''}${g.toFixed(1)}%</span> vs moyenne</div></div>
    <div class="inc"><div class="inco">⏰</div><div class="inv">${d.best_hour !== null ? d.best_hour + 'h00' : '—'}</div><div class="inl">Pic d'activité</div><div class="ind">Meilleure heure</div></div>
    <div class="inc"><div class="inco">📅</div><div class="inv">${dayMap[d.best_day||''] || d.best_day || '—'}</div><div class="inl">Meilleur jour</div><div class="ind">Plus de ventes</div></div>
    <div class="inc"><div class="inco">💡</div><div class="inv">${g >= 20 ? '🔥 Excellent' : g >= 5 ? '✅ Bon' : g >= -5 ? '➡️ Stable' : '⚠️ Attention'}</div><div class="inl">Performance</div><div class="ind">${g >= 0 ? 'Croissance positive' : 'Tendance en baisse'}</div></div>
    <div class="inc"><div class="inco">🎯</div><div class="inv">${parseFloat(d.projected_month||0) > parseFloat(d.avg_monthly||0) ? '✓ En avance' : '⬇ Sous moy.'}</div><div class="inl">Objectif</div><div class="ind">Projection vs moyenne</div></div>
  </div>`;
}

/* ══════════════════════════════════════════════
   MODAL BONUS — 100% connecté BD
══════════════════════════════════════════════ */
let selectedUserId   = null;
let selectedBookId   = null;
let selectedUserData = null;

function openBonusModal() {
  eligChecked      = false;
  selectedUserId   = null;
  selectedBookId   = null;
  selectedUserData = null;
  document.getElementById('bonus-user-input').value = '';
  document.getElementById('bonus-book-input').value = '';
  document.getElementById('bonus-user-id').value    = '';
  document.getElementById('bonus-book-id').value    = '';
  document.getElementById('user-info-bar').style.display = 'none';
  document.getElementById('elig-box').className = 'elig';
  document.getElementById('btn-grant').disabled = true;
  document.getElementById('bonus-modal').classList.add('open');
  setTimeout(() => document.getElementById('bonus-user-input').focus(), 100);
}
function closeBonusModal() { document.getElementById('bonus-modal').classList.remove('open'); }

/* Recherche utilisateurs */
function debounceUserSearch(q) {
  clearTimeout(userTimer);
  const list = document.getElementById('user-suggest');
  if (q.length < 2) { list.classList.remove('open'); return; }
  userTimer = setTimeout(() => searchUsers(q), 280);
}
async function searchUsers(q) {
  const d = await api('search_users', { q });
  const list = document.getElementById('user-suggest');
  if (!d?.users) { list.innerHTML = '<div class="si" style="color:var(--t3)">Erreur de connexion</div>'; list.classList.add('open'); return; }
  if (!d.users.length) { list.innerHTML = '<div class="si" style="color:var(--t3)">Aucun utilisateur actif trouvé</div>'; list.classList.add('open'); return; }
  const bonusRule = d.bonus_rule || INIT.bonus_rule || 5;
  list.innerHTML = d.users.map(u => {
    const ok = parseInt(u.achats_confirmes) >= bonusRule && parseInt(u.bonus_restant) > 0;
    const prog = parseInt(u.achat_progress) || (parseInt(u.achats_confirmes) % bonusRule);
    return `<div class="si" onclick="selectUser(${u.id}, '${esc(u.prenom)} ${esc(u.nom)}', '${esc(u.email)}', ${u.achats_confirmes||0}, ${u.bonus_restant||0})">
      <strong>${esc(u.prenom)} ${esc(u.nom)}</strong>
      <span style="float:right"><span class="chip ${ok?'c-bonus':'c-mu'}" style="font-size:.5rem">${ok?'🎁 '+u.bonus_restant+' bonus':''+u.achats_confirmes+' achats'}</span></span><br>
      <small>${esc(u.email)} · ${u.achats_confirmes||0} achat(s) confirmé(s)</small>
    </div>`;
  }).join('');
  list.classList.add('open');
}
function selectUser(id, name, email, purchases, bonus) {
  selectedUserId   = id;
  selectedUserData = { id, name, email, purchases, bonus };
  document.getElementById('bonus-user-id').value    = id;
  document.getElementById('bonus-user-input').value = name + ' — ' + email;
  document.getElementById('user-suggest').classList.remove('open');
  eligChecked = false;
  document.getElementById('btn-grant').disabled = true;
  document.getElementById('elig-box').className = 'elig';
  // Afficher infos utilisateur
  const bar = document.getElementById('user-info-bar');
  bar.style.display = 'block';
  bar.innerHTML = `<i class="bi bi-person-check"></i> <strong>${esc(name)}</strong> · ${purchases} achat(s) · ${bonus > 0 ? '🎁 ' + bonus + ' bonus disponible(s)' : 'Aucun bonus disponible'}`;
}
document.addEventListener('click', e => {
  const l = document.getElementById('user-suggest'), i = document.getElementById('bonus-user-input');
  if (l && !l.contains(e.target) && !i?.contains(e.target)) l.classList.remove('open');
});

/* Recherche livres */
function debounceBookSearch(q) {
  clearTimeout(bookTimer);
  const list = document.getElementById('book-suggest');
  if (q.length < 2) { list.classList.remove('open'); return; }
  bookTimer = setTimeout(() => searchBooks(q), 280);
}
async function searchBooks(q) {
  const d = await api('search_books', { q });
  const list = document.getElementById('book-suggest');
  if (!d?.books) { list.innerHTML = '<div class="si" style="color:var(--t3)">Erreur</div>'; list.classList.add('open'); return; }
  if (!d.books.length) { list.innerHTML = '<div class="si" style="color:var(--t3)">Aucun livre trouvé</div>'; list.classList.add('open'); return; }
  list.innerHTML = d.books.map(b => `<div class="si" onclick="selectBook(${b.id}, '${esc(b.titre)}', '${esc(b.auteur)}')">
    <strong>${esc(b.titre)}</strong> <span class="chip ${ACHIP[b.access_type]||'c-mu'}" style="font-size:.5rem">${ALBL[b.access_type]||b.access_type}</span><br>
    <small>${esc(b.auteur)} · ${esc(b.categorie||'—')}</small>
  </div>`).join('');
  list.classList.add('open');
}
function selectBook(id, titre, auteur) {
  selectedBookId = id;
  document.getElementById('bonus-book-id').value    = id;
  document.getElementById('bonus-book-input').value = titre + ' — ' + auteur;
  document.getElementById('book-suggest').classList.remove('open');
  eligChecked = false;
  document.getElementById('btn-grant').disabled = true;
  document.getElementById('elig-box').className = 'elig';
}
document.addEventListener('click', e => {
  const l = document.getElementById('book-suggest'), i = document.getElementById('bonus-book-input');
  if (l && !l.contains(e.target) && !i?.contains(e.target)) l.classList.remove('open');
});

/* Vérifier éligibilité — depuis la BD réelle */
async function checkEligibility() {
  const uid = document.getElementById('bonus-user-id').value;
  const bid = document.getElementById('bonus-book-id').value;
  const box = document.getElementById('elig-box');
  const msg = document.getElementById('elig-msg');
  const stats = document.getElementById('elig-stats');

  if (!uid) { toast('Attention', "Sélectionnez d'abord un utilisateur.", 'warn'); return; }
  if (!bid) { toast('Attention', "Sélectionnez d'abord un livre.", 'warn'); return; }

  box.className = 'elig show';
  msg.innerHTML = '<i class="bi bi-hourglass-split"></i> Vérification en cours depuis la base de données…';
  if (stats) stats.style.display = 'none';

  const d = await api('check_bonus_eligibility', { user_id: uid, livre_id: bid });

  if (!d) {
    box.className = 'elig show err';
    msg.innerHTML = '❌ Erreur de connexion à la base de données.';
    eligChecked = false;
    document.getElementById('btn-grant').disabled = true;
    return;
  }

  eligChecked = d.eligible;
  document.getElementById('btn-grant').disabled = !d.eligible;

  if (d.eligible) {
    box.className = 'elig show ' + (d.all_possessed ? 'warn' : 'ok');
    msg.innerHTML = (d.all_possessed ? '⚠️ ' : '✅ ') + esc(d.reason || 'Utilisateur éligible au bonus.');
    if (!d.all_possessed && stats) {
      stats.style.display = 'grid';
      stats.innerHTML = `
        <div class="m-stat"><div class="m-sv">${d.purchases || 0}</div><div class="m-sl">Achats confirmés</div></div>
        <div class="m-stat"><div class="m-sv">${d.bonus_restant || 0}</div><div class="m-sl">Bonus disponibles</div></div>
        <div class="m-stat"><div class="m-sv">${d.achat_progress || 0}/${d.required || INIT.bonus_rule || 5}</div><div class="m-sl">Progression</div></div>
        <div class="m-stat"><div class="m-sv" style="color:var(--g)">${esc(d.livre?.titre || '—')}</div><div class="m-sl">Livre sélectionné</div></div>`;
    }
  } else {
    box.className = 'elig show err';
    msg.innerHTML = '❌ ' + esc(d.reason || 'Non éligible.');
    if (stats && (d.purchases !== undefined || d.next_bonus_in !== undefined)) {
      stats.style.display = 'grid';
      stats.innerHTML = `
        <div class="m-stat"><div class="m-sv">${d.purchases !== undefined ? d.purchases : '—'}</div><div class="m-sl">Achats actuels</div></div>
        <div class="m-stat"><div class="m-sv">${d.required || d.next_bonus_in ? (d.required || '—') : '0'}</div><div class="m-sl">Requis</div></div>
        ${d.next_bonus_in !== undefined ? `<div class="m-stat"><div class="m-sv">${d.next_bonus_in}</div><div class="m-sl">Achats manquants</div></div>` : ''}
        ${d.bonus !== undefined ? `<div class="m-stat"><div class="m-sv">${d.bonus}</div><div class="m-sl">Bonus restants</div></div>` : ''}`;
    }
  }
}

/* Attribuer bonus */
async function grantBonus() {
  const uid = parseInt(document.getElementById('bonus-user-id').value || 0);
  const bid = parseInt(document.getElementById('bonus-book-id').value || 0);
  if (!uid || !bid) { toast('Erreur', 'Utilisateur et livre requis.', 'error'); return; }
  if (!eligChecked) { toast('Attention', "Vérifiez d'abord l'éligibilité.", 'warn'); return; }

  const btn = document.getElementById('btn-grant');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Attribution…';

  const d = await api('grant_bonus', {}, { method: 'POST', body: { user_id: uid, livre_id: bid } });
  btn.innerHTML = '<i class="bi bi-gift-fill"></i> Attribuer';

  if (d?.success) {
    toast('🎁 Bonus accordé !', d.message, 'success', 6000);
    closeBonusModal();
    eligChecked = false;
    await Promise.all([refreshAll()]);
  } else {
    toast('Erreur', d?.error || "Attribution échouée.", 'error');
    btn.disabled = false;
    // Réinitialiser la vérification en cas d'erreur
    eligChecked = false;
    document.getElementById('btn-grant').disabled = true;
    document.getElementById('elig-box').className = 'elig show err';
    document.getElementById('elig-msg').innerHTML = '❌ ' + esc(d?.error || "Erreur. Revérifiez l'éligibilité.");
  }
}

/* ══════════════════════════════════════════════
   SYNC BONUS MANUEL
══════════════════════════════════════════════ */
async function triggerSync() {
  const banner = document.getElementById('sync-banner');
  const msg    = document.getElementById('sync-msg');
  if (banner) { banner.classList.add('show'); }
  if (msg) msg.textContent = 'Recalcul des bonus depuis les achats réels…';
  toast('Synchronisation', 'Recalcul en cours…', 'info', 2000);

  const d = await api('sync_bonus', {}, { method: 'POST', body: {} });

  if (banner) setTimeout(() => banner.classList.remove('show'), 2000);
  if (d?.success) {
    toast('Synchronisation OK', d.message, 'success', 5000);
    await Promise.all([loadStats(), loadLoyalty()]);
  } else {
    toast('Erreur sync', d?.error || 'Erreur.', 'error');
  }
}

/* ══════════════════════════════════════════════
   DÉTAIL TRANSACTION
══════════════════════════════════════════════ */
function showDetail(tx) {
  const isBonus = tx.type_transaction === 'bonus_accorde';
  const dt = new Date(tx.created_at);
  const dtStr = dt.toLocaleDateString('fr-FR') + ' à ' + dt.toLocaleTimeString('fr-FR', { hour:'2-digit', minute:'2-digit' });
  document.getElementById('det-title').textContent = isBonus ? '🎁 Détail bonus' : '💳 Détail transaction';
  document.getElementById('det-body').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:0">
      ${row('Référence', `<span style="font-family:monospace;font-size:.72rem">${esc(tx.reference||'—')}</span>`)}
      ${row('Acheteur', `${esc(tx.user_name)}<br><span style="font-size:.68rem;color:var(--t3)">${esc(tx.email)}</span>`)}
      ${row('Livre', `${esc(tx.livre_titre)}<br><span style="font-size:.68rem;color:var(--t3)">${esc(tx.categorie||'—')}</span>`)}
      ${row('Type', `<span class="chip ${isBonus?'c-bonus':'c-vente'}">${isBonus?'🎁 Bonus accordé':'💳 Vente'}</span>`)}
      ${row('Montant', `<span style="color:var(--g);font-weight:700">${isBonus ? 'Gratuit (bonus)' : fmtFCFA(parseFloat(tx.montant)||0)}</span>`)}
      ${row('Méthode', `${MICO[tx.methode]||'💳'} ${esc(MNME[tx.methode]||tx.methode||'—')}`)}
      ${row('Statut', `<span class="chip ${tx.statut==='confirme'?'c-ok':tx.statut==='echec'?'c-err':'c-warn'}">${esc(tx.statut)}</span>`)}
      ${row('Date', dtStr)}
      ${row('Accès livre', `<span class="chip ${ACHIP[tx.access_type]||'c-mu'}">${ALBL[tx.access_type]||tx.access_type||'—'}</span>`)}
    </div>`;
  document.getElementById('det-acts').innerHTML = `
    <button class="btn btn-gh" style="flex:1;justify-content:center" onclick="closeDetail()">Fermer</button>
    <a class="btn btn-gh" style="flex:1;justify-content:center" href="../users/view.php?id=${encodeURIComponent(tx.user_id)}">
      <i class="bi bi-person-vcard"></i> Profil utilisateur
    </a>`;
  document.getElementById('detail-modal').classList.add('open');
}
function row(lbl, val) {
  return `<div style="display:flex;gap:.8rem;padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.77rem">
    <div style="color:var(--t3);width:130px;flex-shrink:0;font-family:'JetBrains Mono',monospace;font-size:.62rem;text-transform:uppercase">${lbl}</div>
    <div style="color:var(--t1);font-weight:500">${val}</div>
  </div>`;
}
function closeDetail() { document.getElementById('detail-modal').classList.remove('open'); }

/* ══════════════════════════════════════════════
   ANNULER TRANSACTION
══════════════════════════════════════════════ */
async function cancelTx(id) {
  if (!confirm('Annuler cette transaction ? Les bonus seront resynchronisés automatiquement.')) return;
  const d = await api('cancel_transaction', {}, { method: 'POST', body: { achat_id: id } });
  if (d?.success) {
    toast('Transaction annulée', d.message, 'success');
    await Promise.all([loadTransactions(), loadFeed(), loadStats(), loadLoyalty()]);
  } else {
    toast('Erreur', d?.error || "Annulation échouée.", 'error');
  }
}

/* ══════════════════════════════════════════════
   NOTIFICATIONS
══════════════════════════════════════════════ */
function toggleNotif() {
  document.getElementById('np').classList.toggle('open');
  loadNotifs();
}
document.addEventListener('click', e => {
  const p = document.getElementById('np'), b = document.getElementById('notif-btn');
  if (p?.classList.contains('open') && !p.contains(e.target) && !b?.contains(e.target)) p.classList.remove('open');
});
async function loadNotifs() {
  const d = await api('notifications');
  if (!d) return;
  const unread = d.unread || 0;
  const badge = document.getElementById('notif-badge');
  if (badge) { badge.style.display = unread > 0 ? 'flex' : 'none'; badge.textContent = Math.min(unread, 9); }
  const list = document.getElementById('notif-list');
  if (!list) return;
  if (!d.notifications?.length) {
    list.innerHTML = '<div style="padding:1.5rem;text-align:center;color:var(--t3);font-size:.73rem">🔔 Aucune notification</div>';
    return;
  }
  list.innerHTML = d.notifications.map(n => `<div class="npi${!parseInt(n.lu||0) ? ' unread' : ''}">
    <div class="npic" style="background:${esc(n.bg||'rgba(0,212,255,.08)')}">${esc(n.icon||'🔔')}</div>
    <div>
      <div class="npt"><strong>${esc(n.titre||'')}</strong>${n.message ? '<br><span style="font-size:.66rem">' + esc((n.message||'').substring(0, 72)) + '</span>' : ''}</div>
      <div class="nptime">${timeAgo(n.created_at||'')}</div>
    </div>
  </div>`).join('');
  const nt = document.getElementById('notif-time');
  if (nt) nt.textContent = new Date().toLocaleTimeString('fr-FR', { hour:'2-digit', minute:'2-digit' });
}
async function markRead() {
  await api('mark_read', {}, { method: 'POST', body: {} });
  const b = document.getElementById('notif-badge'); if (b) b.style.display = 'none';
  toast('Notifications', 'Toutes marquées comme lues', 'success', 2000);
  loadNotifs();
}

/* ══════════════════════════════════════════════
   REFRESH ALL
══════════════════════════════════════════════ */
async function refreshAll() {
  await Promise.all([
    loadStats(),
    loadChartSales(currentChartType),
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

/* ══════════════════════════════════════════════
   AUTO-REFRESH
══════════════════════════════════════════════ */
function startAutoRefresh() {
  if (autoRefTimer) clearInterval(autoRefTimer);
  autoRefTimer = setInterval(async () => {
    await Promise.all([loadStats(), loadFeed(), loadNotifs()]);
  }, 15000);
}

/* ══════════════════════════════════════════════
   CLAVIER
══════════════════════════════════════════════ */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    ['bonus-modal','detail-modal'].forEach(id => document.getElementById(id)?.classList.remove('open'));
    document.getElementById('np')?.classList.remove('open');
  }
  if ((e.ctrlKey || e.metaKey) && e.key === 'r') { e.preventDefault(); refreshAll(); }
  if ((e.ctrlKey || e.metaKey) && e.key === 'b') { e.preventDefault(); openBonusModal(); }
});

/* ══════════════════════════════════════════════
   INIT
══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', async () => {
  if (INIT.notif_unread > 0) {
    const b = document.getElementById('notif-badge');
    if (b) { b.style.display = 'flex'; b.textContent = Math.min(INIT.notif_unread, 9); }
  }

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

  // Animer les barres de progression
  setTimeout(() => {
    document.querySelectorAll('.pg').forEach(b => {
      const w = b.style.width;
      b.style.width = '0%';
      requestAnimationFrame(() => requestAnimationFrame(() => { b.style.width = w; }));
    });
  }, 400);

  setTimeout(() => toast('Sales Dashboard', 'Connecté · Bonus synchronisés.', 'success', 4000), 600);
});
</script>
</body>
</html>