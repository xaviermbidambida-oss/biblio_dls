<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — NotificationService                   ║
 * ║  includes/NotificationService.php                               ║
 * ║  Système EVENT-DRIVEN centralisé                                ║
 * ║  ✅ PDO sécurisé · Zéro "key" column · Types normalisés         ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

if (!defined('DLS_ROOT')) {
    define('DLS_ROOT', dirname(__DIR__));
}

class NotificationService
{
    private PDO $pdo;

    // ── Types officiels ───────────────────────────────────────────
    public const TYPE_BONUS        = 'bonus';
    public const TYPE_ACHAT        = 'achat';
    public const TYPE_LECTURE      = 'lecture';
    public const TYPE_DOWNLOAD     = 'telechargement';
    public const TYPE_FAVORIS      = 'favoris';
    public const TYPE_ADMIN        = 'admin_action';
    public const TYPE_SYSTEM       = 'system_error';
    public const TYPE_SECURITY     = 'security_alert';
    public const TYPE_USER         = 'utilisateur';
    public const TYPE_INFO         = 'info';

    // ── Icônes par type ──────────────────────────────────────────
    private const ICONS = [
        'bonus'          => '🎁',
        'achat'          => '🛍️',
        'lecture'        => '📖',
        'telechargement' => '⬇️',
        'favoris'        => '❤️',
        'admin_action'   => '⚙️',
        'system_error'   => '🔴',
        'security_alert' => '🔐',
        'utilisateur'    => '👤',
        'info'           => 'ℹ️',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    // ──────────────────────────────────────────────────────────────
    // CRÉATION — méthode principale
    // ──────────────────────────────────────────────────────────────

    /**
     * Créer une notification
     *
     * @param  int|null $userId   NULL = globale (tous les utilisateurs)
     * @param  string   $type     Une des constantes TYPE_*
     * @param  string   $title    Titre court
     * @param  string   $message  Corps complet
     * @param  string   $icon     Emoji optionnel (si vide, déduit du type)
     * @return int                ID inséré, 0 si erreur
     */
    public function create(
        ?int   $userId,
        string $type,
        string $title,
        string $message,
        string $icon = ''
    ): int {
        if ($icon === '') {
            $icon = self::ICONS[$type] ?? '🔔';
        }
        try {
            $st = $this->pdo->prepare(
                "INSERT INTO notifications (user_id, type, title, message, icon, is_read, is_archived, created_at)
                 VALUES (:uid, :type, :title, :msg, :icon, 0, 0, NOW())"
            );
            $st->execute([
                ':uid'   => $userId,
                ':type'  => $type,
                ':title' => mb_substr($title,   0, 255),
                ':msg'   => mb_substr($message, 0, 65535),
                ':icon'  => mb_substr($icon,    0, 10),
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('[NotifService] create() error: ' . $e->getMessage());
            return 0;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // ÉVÉNEMENTS MÉTIER
    // ──────────────────────────────────────────────────────────────

    /** Achat d'un livre */
    public function onAchat(int $userId, string $livreTitle, float $montant): void
    {
        $this->create(
            $userId,
            self::TYPE_ACHAT,
            '🛍️ Achat confirmé',
            "Vous avez acheté « {$livreTitle} » pour " . number_format($montant, 0, ',', ' ') . ' FCFA.'
        );
        // Notification admin globale
        $this->create(
            null,
            self::TYPE_ACHAT,
            'Nouvel achat — ' . mb_substr($livreTitle, 0, 40),
            "Un utilisateur (#$userId) a acheté « $livreTitle »."
        );
    }

    /** Bonus utilisateur accordé */
    public function onBonusGranted(int $userId, int $bonusRestant, string $reason = ''): void
    {
        $this->create(
            $userId,
            self::TYPE_BONUS,
            '🎁 Bonus fidélité obtenu !',
            "Félicitations ! Vous avez un livre gratuit disponible. Bonus restants : {$bonusRestant}." .
            ($reason ? " Raison : {$reason}" : '')
        );
        $this->create(
            null,
            self::TYPE_BONUS,
            "Bonus accordé — Utilisateur #{$userId}",
            "L'utilisateur #{$userId} a reçu un bonus fidélité. Solde : {$bonusRestant}."
        );
    }

    /** Bonus utilisé pour obtenir un livre */
    public function onBonusUsed(int $userId, string $livreTitle, int $bonusRestant): void
    {
        $this->create(
            $userId,
            self::TYPE_BONUS,
            '📚 Livre gratuit obtenu !',
            "« {$livreTitle} » a été ajouté à votre bibliothèque via votre bonus fidélité. Bonus restants : {$bonusRestant}."
        );
    }

    /** Lecture commencée */
    public function onLectureStarted(int $userId, string $livreTitle): void
    {
        $this->create(
            $userId,
            self::TYPE_LECTURE,
            '📖 Lecture commencée',
            "Vous avez commencé la lecture de « {$livreTitle} »."
        );
    }

    /** Lecture terminée */
    public function onLectureFinished(int $userId, string $livreTitle): void
    {
        $this->create(
            $userId,
            self::TYPE_LECTURE,
            '🏁 Lecture terminée !',
            "Bravo ! Vous avez terminé « {$livreTitle} »."
        );
    }

    /** Téléchargement PDF */
    public function onDownload(int $userId, string $livreTitle): void
    {
        $this->create(
            $userId,
            self::TYPE_DOWNLOAD,
            '⬇️ Téléchargement PDF',
            "Le PDF de « {$livreTitle} » a été téléchargé."
        );
    }

    /** Ajout aux favoris */
    public function onFavorisAdded(int $userId, string $livreTitle): void
    {
        $this->create(
            $userId,
            self::TYPE_FAVORIS,
            '❤️ Ajouté aux favoris',
            "« {$livreTitle} » a été ajouté à vos favoris."
        );
    }

    /** Suppression des favoris */
    public function onFavorisRemoved(int $userId, string $livreTitle): void
    {
        $this->create(
            $userId,
            self::TYPE_FAVORIS,
            '💔 Retiré des favoris',
            "« {$livreTitle} » a été retiré de vos favoris."
        );
    }

    /** Création d'un utilisateur */
    public function onUserCreated(int $newUserId, string $userName, string $role): void
    {
        $this->create(
            $newUserId,
            self::TYPE_USER,
            '👋 Bienvenue sur DLS !',
            "Votre compte ({$role}) a été créé avec succès. Bienvenue, {$userName} !"
        );
        $this->create(
            null,
            self::TYPE_USER,
            "Nouvel utilisateur créé — #{$newUserId}",
            "Compte créé : {$userName} ({$role})."
        );
    }

    /** Modification d'un utilisateur par l'admin */
    public function onUserEdited(int $targetUserId, string $userName, int $adminId): void
    {
        $this->create(
            $targetUserId,
            self::TYPE_USER,
            '✏️ Profil modifié',
            "Votre profil a été mis à jour par un administrateur."
        );
        $this->create(
            null,
            self::TYPE_ADMIN,
            "Modification utilisateur — #{$targetUserId}",
            "L'admin #{$adminId} a modifié le compte de {$userName}."
        );
    }

    /** Action admin générique */
    public function onAdminAction(int $adminId, string $action, string $detail = ''): void
    {
        $this->create(
            null,
            self::TYPE_ADMIN,
            "⚙️ Action admin — {$action}",
            "Admin #{$adminId} : {$action}." . ($detail ? " Détail : {$detail}" : '')
        );
    }

    /** Erreur système critique */
    public function onSystemError(string $message, string $context = ''): void
    {
        $this->create(
            null,
            self::TYPE_SYSTEM,
            '🔴 Erreur système',
            $message . ($context ? " [Contexte : {$context}]" : '')
        );
        error_log("[DLS SYSTEM ERROR] $message $context");
    }

    /** Alerte sécurité */
    public function onSecurityAlert(int $userId, string $message): void
    {
        $this->create(
            $userId,
            self::TYPE_SECURITY,
            '🔐 Alerte sécurité',
            $message
        );
        $this->create(
            null,
            self::TYPE_SECURITY,
            "Alerte sécurité — Utilisateur #{$userId}",
            $message
        );
    }

    // ──────────────────────────────────────────────────────────────
    // LECTURE
    // ──────────────────────────────────────────────────────────────

    /**
     * Récupérer les notifications avec filtres + pagination
     */
    public function getAll(
        array  $filters = [],
        int    $page    = 1,
        int    $perPage = 20
    ): array {
        $where  = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[]  = "(n.user_id = :uid OR n.user_id IS NULL)";
            $params[':uid'] = (int) $filters['user_id'];
        }
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $where[]  = "n.type = :type";
            $params[':type'] = $filters['type'];
        }
        if (isset($filters['is_read']) && $filters['is_read'] !== 'all') {
            $where[]  = "n.is_read = :read";
            $params[':read'] = (int) $filters['is_read'];
        }
        if (!empty($filters['search'])) {
            $where[]  = "(n.title LIKE :search OR n.message LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $where[]  = "DATE(n.created_at) >= :df";
            $params[':df'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]  = "DATE(n.created_at) <= :dt";
            $params[':dt'] = $filters['date_to'];
        }
        if (isset($filters['is_archived'])) {
            $where[]  = "n.is_archived = :arch";
            $params[':arch'] = (int) $filters['is_archived'];
        } else {
            $where[] = "n.is_archived = 0";
        }

        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : "WHERE n.is_archived = 0";
        $offset   = max(0, ($page - 1) * $perPage);

        try {
            $countSt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM notifications n $whereSQL"
            );
            $countSt->execute($params);
            $total = (int) $countSt->fetchColumn();

            $st = $this->pdo->prepare(
                "SELECT n.*,
                        n.title   AS titre,
                        n.is_read AS lu,
                        u.nom     AS user_nom,
                        u.prenom  AS user_prenom,
                        u.email   AS user_email,
                        u.role    AS user_role
                 FROM notifications n
                 LEFT JOIN users u ON u.id = n.user_id
                 $whereSQL
                 ORDER BY n.created_at DESC
                 LIMIT :limit OFFSET :offset"
            );
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v);
            }
            $st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
            $st->bindValue(':offset', $offset,  PDO::PARAM_INT);
            $st->execute();

            return [
                'items'      => $st->fetchAll(),
                'total'      => $total,
                'pages'      => max(1, (int) ceil($total / $perPage)),
                'per_page'   => $perPage,
                'current'    => $page,
            ];
        } catch (Throwable $e) {
            error_log('[NotifService] getAll() error: ' . $e->getMessage());
            return ['items' => [], 'total' => 0, 'pages' => 1, 'per_page' => $perPage, 'current' => 1];
        }
    }

    /** Nombre de non-lues pour un utilisateur (ou global si admin) */
    public function countUnread(?int $userId = null, bool $adminView = false): int
    {
        try {
            if ($adminView) {
                return (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND is_archived = 0"
                )->fetchColumn();
            }
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM notifications
                 WHERE is_read = 0 AND is_archived = 0
                   AND (user_id = :uid OR user_id IS NULL)"
            );
            $st->execute([':uid' => $userId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Types distincts présents en base */
    public function getTypes(bool $adminView = false, ?int $userId = null): array
    {
        try {
            if ($adminView) {
                return array_column(
                    $this->pdo->query(
                        "SELECT DISTINCT type, COUNT(*) as cnt FROM notifications
                         WHERE is_archived = 0 GROUP BY type ORDER BY cnt DESC"
                    )->fetchAll(),
                    'type'
                );
            }
            $st = $this->pdo->prepare(
                "SELECT DISTINCT type FROM notifications
                 WHERE is_archived = 0 AND (user_id = :uid OR user_id IS NULL)
                 ORDER BY type"
            );
            $st->execute([':uid' => $userId]);
            return array_column($st->fetchAll(), 'type');
        } catch (Throwable $e) {
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────
    // ACTIONS
    // ──────────────────────────────────────────────────────────────

    public function markRead(int $id, ?int $userId = null, bool $adminMode = false): bool
    {
        try {
            if ($adminMode) {
                $st = $this->pdo->prepare(
                    "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?"
                );
                return $st->execute([$id]);
            }
            $st = $this->pdo->prepare(
                "UPDATE notifications SET is_read = 1, read_at = NOW()
                 WHERE id = ? AND (user_id = ? OR user_id IS NULL)"
            );
            return $st->execute([$id, $userId]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function markAllRead(?int $userId = null, bool $adminMode = false): int
    {
        try {
            if ($adminMode) {
                return (int) $this->pdo->exec(
                    "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0"
                );
            }
            $st = $this->pdo->prepare(
                "UPDATE notifications SET is_read = 1, read_at = NOW()
                 WHERE is_read = 0 AND (user_id = ? OR user_id IS NULL)"
            );
            $st->execute([$userId]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function archive(int $id): bool
    {
        try {
            return $this->pdo->prepare(
                "UPDATE notifications SET is_archived = 1 WHERE id = ?"
            )->execute([$id]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            return $this->pdo->prepare(
                "DELETE FROM notifications WHERE id = ?"
            )->execute([$id]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Actions en masse */
    public function bulkAction(array $ids, string $action): int
    {
        if (empty($ids)) return 0;
        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);
        try {
            return match ($action) {
                'read'    => (int) $this->pdo->exec(
                    "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id IN ($in)"
                ),
                'unread'  => (int) $this->pdo->exec(
                    "UPDATE notifications SET is_read = 0, read_at = NULL WHERE id IN ($in)"
                ),
                'archive' => (int) $this->pdo->exec(
                    "UPDATE notifications SET is_archived = 1 WHERE id IN ($in)"
                ),
                'delete'  => (int) $this->pdo->exec(
                    "DELETE FROM notifications WHERE id IN ($in)"
                ),
                default   => 0,
            };
        } catch (Throwable $e) {
            error_log('[NotifService] bulkAction() error: ' . $e->getMessage());
            return 0;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────

    public static function getIcon(string $type): string
    {
        return self::ICONS[$type] ?? '🔔';
    }

    public static function getBadgeClass(string $type): string
    {
        return match ($type) {
            'achat'          => 'badge-neon',
            'bonus'          => 'badge-violet',
            'lecture'        => 'badge-cyan',
            'telechargement' => 'badge-blue',
            'favoris'        => 'badge-rose',
            'admin_action'   => 'badge-amber',
            'system_error'   => 'badge-danger',
            'security_alert' => 'badge-danger',
            'utilisateur'    => 'badge-purple',
            default          => 'badge-muted',
        };
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'achat'          => 'Achat',
            'bonus'          => 'Bonus',
            'lecture'        => 'Lecture',
            'telechargement' => 'Téléchargement',
            'favoris'        => 'Favoris',
            'admin_action'   => 'Admin',
            'system_error'   => 'Erreur',
            'security_alert' => 'Sécurité',
            'utilisateur'    => 'Utilisateur',
            default          => ucfirst($type),
        };
    }

    // ──────────────────────────────────────────────────────────────
    // INIT TABLE (protection contre l'erreur "key")
    // ──────────────────────────────────────────────────────────────
    private function ensureTableExists(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id      INT UNSIGNED NULL,
                    type         VARCHAR(50)  NOT NULL DEFAULT 'info',
                    title        VARCHAR(255) NOT NULL DEFAULT '',
                    message      TEXT,
                    icon         VARCHAR(10)  DEFAULT '🔔',
                    is_read      TINYINT(1)   NOT NULL DEFAULT 0,
                    is_archived  TINYINT(1)   NOT NULL DEFAULT 0,
                    bg           VARCHAR(80)  DEFAULT NULL,
                    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                    read_at      TIMESTAMP    NULL,
                    INDEX idx_user    (user_id),
                    INDEX idx_type    (type),
                    INDEX idx_read    (is_read),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Vérifier si les colonnes "lu" et "titre" existent (anciennes tables)
            // et ajouter des alias si nécessaire — sans utiliser "key"
            $cols = $this->pdo->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'"
            )->fetchAll(PDO::FETCH_COLUMN);

            // Si "title" n'existe pas mais "titre" existe → ajouter "title"
            if (in_array('titre', $cols) && !in_array('title', $cols)) {
                $this->pdo->exec(
                    "ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT '' AFTER type"
                );
                $this->pdo->exec("UPDATE notifications SET title = titre WHERE title = ''");
            }

            // Ajouter is_archived si manquant
            if (!in_array('is_archived', $cols)) {
                $this->pdo->exec(
                    "ALTER TABLE notifications ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0"
                );
            }
            // Ajouter read_at si manquant
            if (!in_array('read_at', $cols)) {
                $this->pdo->exec(
                    "ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL"
                );
            }
            // Ajouter bg si manquant
            if (!in_array('bg', $cols)) {
                $this->pdo->exec(
                    "ALTER TABLE notifications ADD COLUMN bg VARCHAR(80) DEFAULT NULL"
                );
            }

        } catch (Throwable $e) {
            error_log('[NotifService] ensureTableExists() error: ' . $e->getMessage());
        }
    }
}