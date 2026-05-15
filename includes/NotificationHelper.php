<?php
/**
 * includes/NotificationHelper.php
 * ══════════════════════════════════════════════════════════════
 * Système centralisé de notifications — Digital Library
 *
 * USAGE :
 *   require_once __DIR__ . '/../includes/NotificationHelper.php';
 *   NotificationHelper::send($pdo, 'sale', 'Nouvelle vente', 'Détail…');
 *   NotificationHelper::userRegistered($pdo, $userId, 'Jean Dupont');
 *   NotificationHelper::bookAdded($pdo, $adminId, 'Dune', 'Frank Herbert');
 *
 * STRUCTURE DE LA TABLE notifications attendue :
 *   id, user_id, type, titre, message, icon, bg, lu, created_at
 * ══════════════════════════════════════════════════════════════
 */

class NotificationHelper
{
    // ── Palette icône/bg par type ────────────────────────────
    private const TYPES = [
        'sale'     => ['icon' => '💰', 'bg' => 'rgba(0,255,170,.08)'],
        'avis'     => ['icon' => '⭐', 'bg' => 'rgba(245,158,11,.08)'],
        'system'   => ['icon' => '⚙️', 'bg' => 'rgba(124,58,237,.08)'],
        'info'     => ['icon' => 'ℹ️', 'bg' => 'rgba(0,212,255,.08)'],
        'warn'     => ['icon' => '⚠️', 'bg' => 'rgba(245,158,11,.1)'],
        'error'    => ['icon' => '❌', 'bg' => 'rgba(244,63,94,.08)'],
        'bonus'    => ['icon' => '🎁', 'bg' => 'rgba(0,255,170,.08)'],
        'user'     => ['icon' => '👤', 'bg' => 'rgba(0,212,255,.08)'],
        'book'     => ['icon' => '📚', 'bg' => 'rgba(124,58,237,.08)'],
        'download' => ['icon' => '⬇️', 'bg' => 'rgba(0,212,255,.06)'],
        'payment'  => ['icon' => '💳', 'bg' => 'rgba(0,255,170,.1)'],
        'login'    => ['icon' => '🔐', 'bg' => 'rgba(0,212,255,.06)'],
        'register' => ['icon' => '🎉', 'bg' => 'rgba(0,255,170,.06)'],
        'block'    => ['icon' => '🚫', 'bg' => 'rgba(244,63,94,.08)'],
    ];

    /**
     * Envoie une notification générique.
     *
     * @param PDO        $pdo
     * @param string     $type    Type parmi TYPES
     * @param string     $titre   Titre court
     * @param string     $message Détail
     * @param int|null   $userId  NULL = notification globale (visible par l'admin)
     * @param string|null $icon   Emoji personnalisé (facultatif)
     * @param string|null $bg     Couleur de fond CSS (facultatif)
     * @return bool
     */
    public static function send(
        PDO    $pdo,
        string $type,
        string $titre,
        string $message,
        ?int   $userId = null,
        ?string $icon  = null,
        ?string $bg    = null
    ): bool {
        try {
            $meta = self::TYPES[$type] ?? self::TYPES['info'];
            $stmt = $pdo->prepare(
                "INSERT INTO notifications
                    (user_id, type, titre, message, icon, bg, lu)
                 VALUES
                    (:user_id, :type, :titre, :message, :icon, :bg, 0)"
            );
            return $stmt->execute([
                ':user_id' => $userId,
                ':type'    => $type,
                ':titre'   => mb_substr($titre,   0, 255),
                ':message' => mb_substr($message, 0, 65535),
                ':icon'    => $icon ?? $meta['icon'],
                ':bg'      => $bg   ?? $meta['bg'],
            ]);
        } catch (PDOException $e) {
            error_log('[NotificationHelper] send() error: ' . $e->getMessage());
            return false;
        }
    }

    // ── Raccourcis sémantiques ───────────────────────────────

    /**
     * Déclenché lors de la création d'un compte utilisateur.
     */
    public static function userRegistered(PDO $pdo, int $newUserId, string $name): void
    {
        // Notification personnelle de bienvenue
        self::send(
            $pdo, 'register',
            'Bienvenue sur Digital Library !',
            "Votre compte a été créé avec succès. Explorez notre catalogue dès maintenant.",
            $newUserId
        );
        // Notification admin globale
        self::send(
            $pdo, 'register',
            'Nouvel utilisateur inscrit',
            "« {$name} » vient de créer un compte.",
            null
        );
    }

    /**
     * Déclenché lors d'une connexion utilisateur.
     */
    public static function userLogin(PDO $pdo, int $userId, string $name, string $ip = ''): void
    {
        self::send(
            $pdo, 'login',
            'Nouvelle connexion',
            "Connexion de « {$name} »" . ($ip ? " depuis {$ip}" : '') . '.',
            null
        );
    }

    /**
     * Déclenché lors d'un ajout de livre.
     */
    public static function bookAdded(PDO $pdo, ?int $adminId, string $titre, string $auteur): void
    {
        self::send(
            $pdo, 'book',
            'Nouveau livre ajouté',
            "« {$titre} » de {$auteur} a été ajouté au catalogue.",
            null
        );
    }

    /**
     * Déclenché lors d'une modification de livre.
     */
    public static function bookUpdated(PDO $pdo, ?int $adminId, string $titre): void
    {
        self::send(
            $pdo, 'book',
            'Livre modifié',
            "Le livre « {$titre} » a été mis à jour.",
            null
        );
    }

    /**
     * Déclenché lors de la suppression d'un livre.
     */
    public static function bookDeleted(PDO $pdo, ?int $adminId, string $titre): void
    {
        self::send(
            $pdo, 'warn',
            'Livre supprimé',
            "Le livre « {$titre} » a été supprimé du catalogue.",
            null
        );
    }

    /**
     * Déclenché lors d'un achat confirmé.
     * Note : le trigger SQL fait déjà cela automatiquement.
     * Utiliser cette méthode si l'achat est traité en PHP sans passer par le trigger.
     */
    public static function purchase(
        PDO    $pdo,
        int    $userId,
        string $userName,
        string $bookTitle,
        float  $amount,
        string $reference
    ): void {
        self::send(
            $pdo, 'sale',
            'Achat confirmé',
            "Votre achat de « {$bookTitle} » a été confirmé. Réf : {$reference}",
            $userId
        );
        self::send(
            $pdo, 'sale',
            'Nouvelle vente',
            "{$userName} a acheté « {$bookTitle} » · " . number_format($amount, 0, ',', ' ') . ' FCFA',
            null
        );
    }

    /**
     * Déclenché lorsqu'un paiement est validé.
     */
    public static function paymentValidated(PDO $pdo, int $userId, string $reference, float $amount): void
    {
        self::send(
            $pdo, 'payment',
            'Paiement validé',
            "Paiement de " . number_format($amount, 0, ',', ' ') . " FCFA confirmé. Réf : {$reference}",
            $userId
        );
    }

    /**
     * Déclenché lorsqu'un bonus est attribué.
     * Note : le trigger SQL le fait automatiquement.
     */
    public static function bonusEarned(PDO $pdo, int $userId, string $userName, int $bonusCount): void
    {
        self::send(
            $pdo, 'bonus',
            '🎁 Bonus fidélité !',
            "Vous avez obtenu un livre gratuit grâce à vos achats.",
            $userId
        );
        self::send(
            $pdo, 'bonus',
            'Bonus attribué',
            "{$userName} vient de recevoir un bonus fidélité ({$bonusCount}e).",
            null
        );
    }

    /**
     * Déclenché lorsqu'un favori est ajouté.
     */
    public static function favoriAdded(PDO $pdo, ?int $adminId, string $userName, string $bookTitle): void
    {
        self::send(
            $pdo, 'info',
            'Nouveau favori',
            "{$userName} a ajouté « {$bookTitle} » à ses favoris.",
            null
        );
    }

    /**
     * Déclenché lors d'un téléchargement.
     * Note : le trigger SQL le fait automatiquement.
     */
    public static function download(PDO $pdo, string $userName, string $bookTitle): void
    {
        self::send(
            $pdo, 'download',
            'Nouveau téléchargement',
            "{$userName} a téléchargé « {$bookTitle} ».",
            null
        );
    }

    /**
     * Déclenché lors d'un blocage ou déblocage d'utilisateur.
     */
    public static function userBlocked(PDO $pdo, string $targetName, bool $blocked): void
    {
        $action = $blocked ? 'bloqué' : 'débloqué';
        self::send(
            $pdo, 'block',
            "Utilisateur {$action}",
            "Le compte de « {$targetName} » a été {$action}.",
            null
        );
    }

    /**
     * Déclenché lors d'une erreur critique applicative.
     */
    public static function systemError(PDO $pdo, string $context, string $message): void
    {
        self::send(
            $pdo, 'error',
            "Erreur système : {$context}",
            mb_substr($message, 0, 500),
            null
        );
    }

    /**
     * Déclenché lors d'un événement système quelconque.
     */
    public static function systemEvent(PDO $pdo, string $titre, string $message): void
    {
        self::send($pdo, 'system', $titre, $message, null);
    }

    /**
     * Retourne le nombre de notifications non lues pour un utilisateur.
     */
    public static function countUnread(PDO $pdo, int $userId, bool $isAdmin = false): int
    {
        try {
            if ($isAdmin) {
                return (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE lu = 0")->fetchColumn();
            }
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM notifications
                 WHERE lu = 0 AND (user_id = :uid OR user_id IS NULL)"
            );
            $stmt->execute([':uid' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Marque toutes les notifications d'un utilisateur comme lues.
     */
    public static function markAllRead(PDO $pdo, int $userId, bool $isAdmin = false): void
    {
        try {
            if ($isAdmin) {
                $pdo->exec("UPDATE notifications SET lu = 1 WHERE lu = 0");
            } else {
                $pdo->prepare(
                    "UPDATE notifications SET lu = 1 WHERE (user_id = ? OR user_id IS NULL) AND lu = 0"
                )->execute([$userId]);
            }
        } catch (PDOException $e) {
            error_log('[NotificationHelper] markAllRead() error: ' . $e->getMessage());
        }
    }

    /**
     * Crée la table notifications si elle n'existe pas (garde-fou).
     */
    public static function ensureTable(PDO $pdo): void
    {
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
                INDEX idx_notif_created (created_at DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}