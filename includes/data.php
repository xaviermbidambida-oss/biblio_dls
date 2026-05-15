<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║         DIGITAL LIBRARY SYSTEM — includes/data.php          ║
 * ║   Fonctions de données réelles connectées MySQL/PDO         ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * ─────────────────────────────────────────────────────────────
 * PDO HELPER
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getPDO')) {
    function getPDO(): PDO {
        return getDB();
    }
}

if (!function_exists('dbExec')) {
    function dbExec(string $sql, array $params = []): bool {
        try {
            $stmt = getPDO()->prepare($sql);
            return $stmt->execute($params);
        } catch (Throwable $e) {
            error_log('[dbExec] ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * TIME AGO
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('timeAgo')) {
    function timeAgo(?string $datetime): string {

        if (!$datetime) {
            return '—';
        }

        $time = strtotime($datetime);

        if (!$time) {
            return '—';
        }

        $diff = time() - $time;

        if ($diff < 60) {
            return 'À l’instant';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . ' min';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . ' h';
        }

        if ($diff < 604800) {
            return floor($diff / 86400) . ' j';
        }

        return date('d/m/Y', $time);
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * NOTIFICATIONS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('insertNotification')) {

    function insertNotification(
        ?int $userId,
        string $type,
        string $message,
        bool $forAdmin = false
    ): void {

        try {

            $pdo = getPDO();

            $stmt = $pdo->prepare("
                INSERT INTO notifications
                (user_id, type, message, lu, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ");

            $stmt->execute([
                $userId,
                $type,
                $message
            ]);

            if ($forAdmin) {

                $admins = dbFetchAll("
                    SELECT id
                    FROM users
                    WHERE role = 'admin'
                ");

                foreach ($admins as $admin) {

                    $stmt->execute([
                        (int)$admin['id'],
                        'admin_' . $type,
                        $message
                    ]);
                }
            }

        } catch (Throwable $e) {

            error_log('[insertNotification] ' . $e->getMessage());
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * ADMIN STATS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getAdminStats')) {

    function getAdminStats(): array {

        try {

            $pdo = getPDO();

            $totalUsers = (int)$pdo
                ->query("SELECT COUNT(*) FROM users")
                ->fetchColumn();

            $activeUsers = (int)$pdo
                ->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE statut = 'actif'
                ")
                ->fetchColumn();

            $totalBooks = (int)$pdo
                ->query("
                    SELECT COUNT(*)
                    FROM livres
                    WHERE statut != 'archive'
                ")
                ->fetchColumn();

            $availableBooks = (int)$pdo
                ->query("
                    SELECT COUNT(*)
                    FROM livres
                    WHERE statut = 'disponible'
                ")
                ->fetchColumn();

            $totalSales = (int)$pdo
                ->query("
                    SELECT COUNT(*)
                    FROM achats
                    WHERE statut = 'confirme'
                ")
                ->fetchColumn();

            $revenue = (float)$pdo
                ->query("
                    SELECT COALESCE(SUM(montant),0)
                    FROM achats
                    WHERE statut = 'confirme'
                ")
                ->fetchColumn();

            $todayUsers = (int)$pdo
                ->query("
                    SELECT COUNT(*)
                    FROM users
                    WHERE DATE(created_at)=CURDATE()
                ")
                ->fetchColumn();

            $weekBooks = (int)$pdo
                ->query("
                    SELECT COUNT(*)
                    FROM livres
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ")
                ->fetchColumn();

            $monthRevenue = (float)$pdo
                ->query("
                    SELECT COALESCE(SUM(montant),0)
                    FROM achats
                    WHERE statut='confirme'
                    AND MONTH(created_at)=MONTH(NOW())
                    AND YEAR(created_at)=YEAR(NOW())
                ")
                ->fetchColumn();

            return [

                'totalUsers'      => $totalUsers,
                'activeUsers'     => $activeUsers,
                'totalBooks'      => $totalBooks,
                'availableBooks'  => $availableBooks,
                'totalSales'      => $totalSales,
                'revenue'         => $revenue,
                'revenueMonth'    => number_format($monthRevenue, 0, ',', ' ') . ' FCFA',
                'newUsersMonth'   => $todayUsers,
                'newBooksWeek'    => $weekBooks,
                'activeToday'     => $activeUsers,
                'salesVariation'  => 0,
                'revVariation'    => 0
            ];

        } catch (Throwable $e) {

            error_log('[getAdminStats] ' . $e->getMessage());

            return [
                'totalUsers'     => 0,
                'activeUsers'    => 0,
                'totalBooks'     => 0,
                'availableBooks' => 0,
                'totalSales'     => 0,
                'revenue'        => 0,
                'revenueMonth'   => '0 FCFA',
                'newUsersMonth'  => 0,
                'newBooksWeek'   => 0,
                'activeToday'    => 0,
                'salesVariation' => 0,
                'revVariation'   => 0
            ];
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * RECENT ACTIVITY
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getRecentActivity')) {

    function getRecentActivity(int $limit = 8): array {

        try {

            $pdo = getPDO();

            $sql = "

                (
                    SELECT
                        'achat' AS type,
                        CONCAT(u.prenom,' ',u.nom,' a acheté \"',l.titre,'\"') AS msg,
                        a.created_at AS ts
                    FROM achats a
                    JOIN users u ON u.id = a.user_id
                    JOIN livres l ON l.id = a.livre_id
                    WHERE a.statut='confirme'
                )

                UNION ALL

                (
                    SELECT
                        'user' AS type,
                        CONCAT('Nouvel utilisateur : ',prenom,' ',nom) AS msg,
                        created_at AS ts
                    FROM users
                )

                UNION ALL

                (
                    SELECT
                        'book' AS type,
                        CONCAT('Livre ajouté : ',titre) AS msg,
                        created_at AS ts
                    FROM livres
                )

                ORDER BY ts DESC
                LIMIT {$limit}
            ";

            $rows = $pdo->query($sql)->fetchAll();

            return array_map(function ($r) {

                return [
                    'type'     => $r['type'],
                    'msg'      => $r['msg'],
                    'time_ago' => timeAgo($r['ts'])
                ];

            }, $rows);

        } catch (Throwable $e) {

            error_log('[getRecentActivity] ' . $e->getMessage());

            return [];
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * SALES CHART
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getSalesChart7Days')) {

    function getSalesChart7Days(): array {

        try {

            $pdo = getPDO();

            $stmt = $pdo->query("
                SELECT
                    DATE(created_at) AS jour,
                    COUNT(*) AS ventes
                FROM achats
                WHERE statut='confirme'
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY DATE(created_at)
                ORDER BY jour ASC
            ");

            return $stmt->fetchAll();

        } catch (Throwable $e) {

            error_log('[getSalesChart7Days] ' . $e->getMessage());

            return [];
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * USERS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getUsers')) {

    function getUsers(int $page = 1, int $perPage = 10): array {

        try {

            $pdo = getPDO();

            $offset = ($page - 1) * $perPage;

            $total = (int)$pdo
                ->query("SELECT COUNT(*) FROM users")
                ->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    prenom,
                    nom,
                    email,
                    role,
                    statut,
                    created_at
                FROM users
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset
            ");

            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();

            return [
                'data'    => $stmt->fetchAll(),
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
                'pages'   => ceil($total / $perPage)
            ];

        } catch (Throwable $e) {

            error_log('[getUsers] ' . $e->getMessage());

            return [
                'data'    => [],
                'total'   => 0,
                'page'    => 1,
                'perPage' => $perPage,
                'pages'   => 0
            ];
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * ADMIN NOTIFICATIONS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getAdminNotifications')) {

    function getAdminNotifications(int $limit = 5): array {

        try {

            $pdo = getPDO();

            $stmt = $pdo->prepare("
                SELECT *
                FROM notifications
                ORDER BY created_at DESC
                LIMIT :lim
            ");

            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);

            $stmt->execute();

            return $stmt->fetchAll();

        } catch (Throwable $e) {

            error_log('[getAdminNotifications] ' . $e->getMessage());

            return [];
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * USER NOTIFICATIONS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getNotifications')) {

    function getNotifications(int $userId, int $limit = 5): array {

        try {

            $pdo = getPDO();

            $stmt = $pdo->prepare("
                SELECT *
                FROM notifications
                WHERE user_id = :uid
                OR user_id IS NULL
                ORDER BY created_at DESC
                LIMIT :lim
            ");

            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);

            $stmt->execute();

            return $stmt->fetchAll();

        } catch (Throwable $e) {

            error_log('[getNotifications] ' . $e->getMessage());

            return [];
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * UNREAD NOTIFICATIONS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getUnreadNotifCount')) {

    function getUnreadNotifCount(int $userId): int {

        try {

            $pdo = getPDO();

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM notifications
                WHERE lu = 0
                AND (user_id = :uid OR user_id IS NULL)
            ");

            $stmt->execute([
                ':uid' => $userId
            ]);

            return (int)$stmt->fetchColumn();

        } catch (Throwable $e) {

            return 0;
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * LATEST BOOKS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getLatestBooks')) {

    function getLatestBooks(int $limit = 6): array {

        try {

            $pdo = getPDO();

            $stmt = $pdo->prepare("
                SELECT
                    l.*,
                    c.nom AS categorie_nom
                FROM livres l
                LEFT JOIN categories c
                ON c.id = l.categorie_id
                ORDER BY l.created_at DESC
                LIMIT :lim
            ");

            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);

            $stmt->execute();

            return $stmt->fetchAll();

        } catch (Throwable $e) {

            error_log('[getLatestBooks] ' . $e->getMessage());

            return [];
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * JOURNALISTE STATS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getJournalisteStats')) {

    function getJournalisteStats(int $userId): array {

        try {

            $pdo = getPDO();

            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(statut='disponible') AS published,
                    SUM(statut='archive') AS draft,
                    COALESCE(SUM(nb_ventes),0) AS views
                FROM livres
                WHERE ajoute_par = :uid
            ");

            $stmt->execute([
                ':uid' => $userId
            ]);

            return $stmt->fetch() ?: [];

        } catch (Throwable $e) {

            error_log('[getJournalisteStats] ' . $e->getMessage());

            return [];
        }
    }
}

/**
 * ─────────────────────────────────────────────────────────────
 * LECTEUR STATS
 * ─────────────────────────────────────────────────────────────
 */

if (!function_exists('getLecteurStats')) {

    function getLecteurStats(int $userId): array {

        try {

            $pdo = getPDO();

            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS booksPurchased,
                    COALESCE(SUM(montant),0) AS totalSpent
                FROM achats
                WHERE user_id = :uid
                AND statut='confirme'
            ");

            $stmt->execute([
                ':uid' => $userId
            ]);

            $stats = $stmt->fetch() ?: [];

            $historyStmt = $pdo->prepare("
                SELECT
                    l.titre,
                    l.auteur,
                    a.montant,
                    a.created_at
                FROM achats a
                JOIN livres l ON l.id = a.livre_id
                WHERE a.user_id = :uid
                ORDER BY a.created_at DESC
                LIMIT 6
            ");

            $historyStmt->execute([
                ':uid' => $userId
            ]);

            $stats['history'] = $historyStmt->fetchAll();

            return $stats;

        } catch (Throwable $e) {

            error_log('[getLecteurStats] ' . $e->getMessage());

            return [
                'booksPurchased' => 0,
                'totalSpent'     => 0,
                'history'        => []
            ];
        }
    }
}