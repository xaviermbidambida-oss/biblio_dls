<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — includes/NotificationService.php                 ║
 * ║  Version FINALE unifiée — remplace NotificationHelper.php,                 ║
 * ║  notification_service.php (v3) et NotificationService.php (v5)             ║
 * ║                                                                            ║
 * ║  COLONNES UTILISÉES (cohérentes avec notifications_schema_final.sql) :     ║
 * ║    lu · titre · message · icon · bg · type · role_cible · priorite         ║
 * ║    lien · related_id · related_type · user_id · created_at                 ║
 * ║                                                                            ║
 * ║  UTILISATION :                                                             ║
 * ║    require_once __DIR__ . '/NotificationService.php';                      ║
 * ║    $ns = new NotificationService($pdo);                                    ║
 * ║                                                                            ║
 * ║    // Inscription                                                          ║
 * ║    $ns->onUserRegistered($userId, 'Jean', 'Dupont', 'jean@mail.cm');       ║
 * ║                                                                            ║
 * ║    // Achat (si trigger SQL non disponible)                                ║
 * ║    $ns->onPurchaseConfirmed($userId, $livreId, 3500, 'REF-001');           ║
 * ║                                                                            ║
 * ║    // Lecture AJAX (endpoint polling)                                      ║
 * ║    $ns->getUnreadCount($userId, $isAdmin);                                 ║
 * ║    $ns->getForDropdown($userId, $isAdmin, 10);                             ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

declare(strict_types=1);

class NotificationService
{
    private PDO $pdo;

    // ── Types de notifications ─────────────────────────────────────────────────
    public const TYPE_SALE      = 'sale';
    public const TYPE_BONUS     = 'bonus';
    public const TYPE_USER      = 'user';
    public const TYPE_BOOK      = 'book';
    public const TYPE_DOWNLOAD  = 'download';
    public const TYPE_PAYMENT   = 'payment';
    public const TYPE_LOGIN     = 'login';
    public const TYPE_REGISTER  = 'register';
    public const TYPE_BLOCK     = 'block';
    public const TYPE_SYSTEM    = 'system';
    public const TYPE_WARN      = 'warn';
    public const TYPE_ERROR     = 'error';
    public const TYPE_SECURITY  = 'security';
    public const TYPE_READING   = 'reading';
    public const TYPE_FAVORI    = 'favori';
    public const TYPE_AVIS      = 'avis';
    public const TYPE_EXPORT    = 'export';
    public const TYPE_PAYMENT_OK = 'payment';
    public const TYPE_INFO      = 'info';

    // ── Priorités ──────────────────────────────────────────────────────────────
    public const PRIO_LOW      = 'low';
    public const PRIO_MEDIUM   = 'medium';
    public const PRIO_HIGH     = 'high';
    public const PRIO_CRITICAL = 'critical';

    // ── Rôles cibles ───────────────────────────────────────────────────────────
    public const ROLE_ALL         = 'all';
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_JOURNALISTE = 'journaliste';
    public const ROLE_LECTEUR     = 'lecteur';

    // ── Palette icône + bg par type (fallback si non fourni) ──────────────────
    private const META = [
        'sale'      => ['icon' => '💰', 'bg' => 'rgba(0,255,170,.08)'],
        'bonus'     => ['icon' => '🎁', 'bg' => 'rgba(0,255,170,.08)'],
        'user'      => ['icon' => '👤', 'bg' => 'rgba(0,212,255,.08)'],
        'book'      => ['icon' => '📚', 'bg' => 'rgba(124,58,237,.08)'],
        'download'  => ['icon' => '⬇️', 'bg' => 'rgba(0,212,255,.06)'],
        'payment'   => ['icon' => '💳', 'bg' => 'rgba(0,255,170,.10)'],
        'login'     => ['icon' => '🔐', 'bg' => 'rgba(0,212,255,.06)'],
        'register'  => ['icon' => '🎉', 'bg' => 'rgba(0,255,170,.06)'],
        'block'     => ['icon' => '🚫', 'bg' => 'rgba(244,63,94,.08)'],
        'system'    => ['icon' => '⚙️',  'bg' => 'rgba(124,58,237,.08)'],
        'warn'      => ['icon' => '⚠️',  'bg' => 'rgba(245,158,11,.10)'],
        'error'     => ['icon' => '🔴', 'bg' => 'rgba(244,63,94,.08)'],
        'security'  => ['icon' => '🛡️', 'bg' => 'rgba(244,63,94,.08)'],
        'reading'   => ['icon' => '📖', 'bg' => 'rgba(0,212,255,.08)'],
        'favori'    => ['icon' => '❤️', 'bg' => 'rgba(244,63,94,.06)'],
        'avis'      => ['icon' => '⭐', 'bg' => 'rgba(245,158,11,.08)'],
        'export'    => ['icon' => '📤', 'bg' => 'rgba(124,58,237,.06)'],
        'info'      => ['icon' => 'ℹ️',  'bg' => 'rgba(0,212,255,.08)'],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // CONSTRUCTEUR
    // ─────────────────────────────────────────────────────────────────────────

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableReady();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INFRASTRUCTURE — Vérification de la table
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Vérifie que la table notifications existe avec les bonnes colonnes.
     * Si elle n'existe pas encore (dev / première exécution), la crée.
     * Si elle existe avec les ANCIENNES colonnes (is_read/title), migre proprement.
     */
    private function ensureTableReady(): void
    {
        try {
            // Créer si absente
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id           INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
                    user_id      INT UNSIGNED   NULL,
                    type         VARCHAR(50)    NOT NULL DEFAULT 'info',
                    role_cible   ENUM('all','admin','journaliste','lecteur') NOT NULL DEFAULT 'all',
                    titre        VARCHAR(255)   NOT NULL DEFAULT '',
                    message      TEXT           NOT NULL,
                    icon         VARCHAR(10)    NOT NULL DEFAULT '🔔',
                    bg           VARCHAR(80)    NOT NULL DEFAULT 'rgba(0,212,255,.08)',
                    priorite     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
                    lien         VARCHAR(512)   DEFAULT NULL,
                    related_id   INT UNSIGNED   DEFAULT NULL,
                    related_type VARCHAR(30)    DEFAULT NULL,
                    lu           TINYINT(1)     NOT NULL DEFAULT 0,
                    created_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    lu_at        TIMESTAMP      NULL,
                    INDEX idx_notif_user     (user_id),
                    INDEX idx_notif_lu       (lu),
                    INDEX idx_notif_type     (type),
                    INDEX idx_notif_priorite (priorite),
                    INDEX idx_notif_role     (role_cible),
                    INDEX idx_notif_created  (created_at DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Récupérer les colonnes existantes
            $stmt = $this->pdo->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'"
            );
            $cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');

            // ── Migration : ancienne colonne title → titre ─────────────────
            if (in_array('title', $cols) && !in_array('titre', $cols)) {
                $this->pdo->exec(
                    "ALTER TABLE notifications
                     ADD COLUMN titre VARCHAR(255) NOT NULL DEFAULT '' AFTER type"
                );
                $this->pdo->exec(
                    "UPDATE notifications SET titre = title WHERE titre = ''"
                );
            }

            // ── Migration : ancienne colonne is_read → lu ──────────────────
            if (in_array('is_read', $cols) && !in_array('lu', $cols)) {
                $this->pdo->exec(
                    "ALTER TABLE notifications
                     ADD COLUMN lu TINYINT(1) NOT NULL DEFAULT 0 AFTER bg"
                );
                $this->pdo->exec(
                    "UPDATE notifications SET lu = is_read"
                );
            }

            // ── Ajouter colonnes manquantes si table existante incomplète ──
            $toAdd = [
                'role_cible'   => "ALTER TABLE notifications ADD COLUMN role_cible ENUM('all','admin','journaliste','lecteur') NOT NULL DEFAULT 'all' AFTER type",
                'priorite'     => "ALTER TABLE notifications ADD COLUMN priorite ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium' AFTER bg",
                'lien'         => "ALTER TABLE notifications ADD COLUMN lien VARCHAR(512) DEFAULT NULL AFTER priorite",
                'related_id'   => "ALTER TABLE notifications ADD COLUMN related_id INT UNSIGNED DEFAULT NULL AFTER lien",
                'related_type' => "ALTER TABLE notifications ADD COLUMN related_type VARCHAR(30) DEFAULT NULL AFTER related_id",
                'lu_at'        => "ALTER TABLE notifications ADD COLUMN lu_at TIMESTAMP NULL AFTER lu",
            ];

            foreach ($toAdd as $col => $sql) {
                if (!in_array($col, $cols)) {
                    $this->pdo->exec($sql);
                }
            }

        } catch (PDOException $e) {
            error_log('[NotificationService] ensureTableReady: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTHODE CENTRALE — create()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crée une notification en base de données.
     *
     * @param array{
     *   user_id     : int|null,
     *   type        : string,
     *   role_cible  : string,
     *   titre       : string,
     *   message     : string,
     *   priorite    : string,
     *   icon        : string,
     *   bg          : string,
     *   lien        : string|null,
     *   related_id  : int|null,
     *   related_type: string|null,
     * } $data
     *
     * @return int ID inséré (0 en cas d'erreur)
     */
    public function create(array $data): int
    {
        try {
            $type = $data['type'] ?? self::TYPE_INFO;
            $meta = self::META[$type] ?? self::META['info'];

            $stmt = $this->pdo->prepare("
                INSERT INTO notifications
                    (user_id, type, role_cible, titre, message, icon, bg,
                     priorite, lien, related_id, related_type, lu)
                VALUES
                    (:user_id, :type, :role_cible, :titre, :message, :icon, :bg,
                     :priorite, :lien, :related_id, :related_type, 0)
            ");

            $stmt->execute([
                ':user_id'      => isset($data['user_id']) ? (int)$data['user_id'] : null,
                ':type'         => $type,
                ':role_cible'   => $data['role_cible']   ?? self::ROLE_ALL,
                ':titre'        => mb_substr($data['titre']   ?? '', 0, 255),
                ':message'      => $data['message'] ?? '',
                ':icon'         => mb_substr($data['icon'] ?? $meta['icon'], 0, 10),
                ':bg'           => $data['bg']       ?? $meta['bg'],
                ':priorite'     => $data['priorite'] ?? self::PRIO_MEDIUM,
                ':lien'         => $data['lien']         ?? null,
                ':related_id'   => isset($data['related_id']) ? (int)$data['related_id'] : null,
                ':related_type' => $data['related_type'] ?? null,
            ]);

            $id = (int)$this->pdo->lastInsertId();
            $this->audit($id, 'created');
            return $id;

        } catch (PDOException $e) {
            error_log('[NotificationService] create(): ' . $e->getMessage());
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUDIT
    // ─────────────────────────────────────────────────────────────────────────

    private function audit(int $notifId, string $action, ?int $actorId = null): void
    {
        try {
            // Table audit optionnelle — silencieux si absente
            $this->pdo->prepare("
                INSERT IGNORE INTO notification_audit (notification_id, action, actor_id)
                VALUES (?, ?, ?)
            ")->execute([$notifId, $action, $actorId]);
        } catch (PDOException $e) {
            // Silencieux : la table audit est optionnelle
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC A — ÉVÉNEMENTS UTILISATEURS
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inscription d'un nouvel utilisateur.
     * → Notif de bienvenue à l'utilisateur + alerte admin
     */
    public function onUserRegistered(int $userId, string $prenom, string $nom, string $email): void
    {
        // Bienvenue à l'utilisateur
        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_REGISTER,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '🎉 Bienvenue sur Digital Library !',
            'message'    => "Bonjour {$prenom} ! Votre compte est actif. Explorez notre catalogue et commencez à lire.",
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../books/index.php',
            'related_id' => $userId,
            'related_type' => 'user',
        ]);

        // Alerte admin
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_REGISTER,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🆕 Nouvel utilisateur inscrit',
            'message'    => "{$prenom} {$nom} ({$email}) vient de créer un compte.",
            'priorite'   => self::PRIO_MEDIUM,
            'lien'       => '../users/index.php?id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Connexion d'un utilisateur.
     * → Alerte admin (faible priorité)
     */
    public function onUserLogin(int $userId, string $nom, string $ip = '', string $role = 'lecteur'): void
    {
        $detail = $ip ? " depuis {$ip}" : '';
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_LOGIN,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🔐 Connexion utilisateur',
            'message'    => "{$nom} ({$role}) vient de se connecter{$detail}.",
            'priorite'   => self::PRIO_LOW,
            'lien'       => '../users/index.php?id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Déconnexion d'un utilisateur.
     */
    public function onUserLogout(int $userId, string $nom): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_LOGIN,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🚪 Déconnexion',
            'message'    => "{$nom} s'est déconnecté.",
            'priorite'   => self::PRIO_LOW,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Blocage d'un utilisateur par l'admin.
     * → Alerte admin + notification à l'utilisateur bloqué
     */
    public function onUserBlocked(int $userId, string $nom, int $adminId, string $raison = ''): void
    {
        // Notification admin
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_BLOCK,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🚫 Utilisateur bloqué',
            'message'    => "Le compte de {$nom} (#{$userId}) a été bloqué par l'admin #{$adminId}."
                            . ($raison ? " Raison : {$raison}" : ''),
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../users/index.php?id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);

        // Notification à l'utilisateur bloqué
        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_BLOCK,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '⛔ Compte suspendu',
            'message'    => 'Votre compte a été temporairement suspendu.'
                            . ($raison ? " Raison : {$raison}." : '')
                            . ' Contactez l\'administration pour plus d\'informations.',
            'priorite'   => self::PRIO_CRITICAL,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Déblocage d'un utilisateur.
     */
    public function onUserUnblocked(int $userId, string $nom, int $adminId): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_BLOCK,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '✅ Utilisateur débloqué',
            'message'    => "Le compte de {$nom} (#{$userId}) a été réactivé par l'admin #{$adminId}.",
            'priorite'   => self::PRIO_MEDIUM,
            'lien'       => '../users/index.php?id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);

        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_BLOCK,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '✅ Compte réactivé',
            'message'    => 'Votre compte a été réactivé. Vous pouvez de nouveau vous connecter.',
            'priorite'   => self::PRIO_HIGH,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Changement de rôle utilisateur.
     */
    public function onRoleChanged(int $userId, string $nom, string $ancienRole, string $nouveauRole, int $adminId): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_USER,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🔄 Rôle modifié',
            'message'    => "{$nom} : rôle changé de {$ancienRole} → {$nouveauRole} par admin #{$adminId}.",
            'priorite'   => self::PRIO_MEDIUM,
            'lien'       => '../users/index.php?id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);

        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_USER,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '✨ Votre rôle a changé',
            'message'    => "Votre accès a été mis à jour. Vous êtes désormais : {$nouveauRole}.",
            'priorite'   => self::PRIO_HIGH,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Nouveau journaliste enregistré.
     * → Alerte admin spécifique
     */
    public function onNewJournalist(int $userId, string $prenom, string $nom, string $email): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_USER,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '📰 Nouveau journaliste inscrit',
            'message'    => "{$prenom} {$nom} ({$email}) a rejoint la plateforme en tant que journaliste.",
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../users/index.php?id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Tentatives de connexion échouées répétées (alerte sécurité).
     */
    public function onLoginFailed(int $userId, string $nom, string $ip, int $nbTentatives): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_SECURITY,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🔴 Tentatives de connexion suspectes',
            'message'    => "{$nbTentatives} tentatives échouées pour {$nom} depuis {$ip}. Compte potentiellement ciblé.",
            'priorite'   => self::PRIO_CRITICAL,
            'lien'       => '../users/index.php?id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Activité suspecte détectée.
     */
    public function onSuspiciousActivity(int $userId, string $nom, string $action, string $ip = ''): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_SECURITY,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🚨 Activité suspecte',
            'message'    => "{$nom} — Action : {$action}" . ($ip ? " depuis IP {$ip}" : '') . '.',
            'priorite'   => self::PRIO_CRITICAL,
            'lien'       => '../users/index.php?id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC B — ÉVÉNEMENTS LIVRES
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nouveau livre ajouté au catalogue.
     */
    public function onBookAdded(int $livreId, string $titre, string $auteur, int $adminId): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_BOOK,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '📚 Nouveau livre ajouté',
            'message'    => "« {$titre} » de {$auteur} est maintenant disponible dans le catalogue (admin #{$adminId}).",
            'priorite'   => self::PRIO_MEDIUM,
            'lien'       => '../books/index.php?id=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    /**
     * Livre modifié.
     */
    public function onBookUpdated(int $livreId, string $titre, int $adminId): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_BOOK,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '✏️ Livre modifié',
            'message'    => "« {$titre} » a été mis à jour par l'admin #{$adminId}.",
            'priorite'   => self::PRIO_LOW,
            'lien'       => '../books/index.php?id=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    /**
     * Livre supprimé du catalogue.
     */
    public function onBookDeleted(int $livreId, string $titre, int $adminId): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_BOOK,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🗑️ Livre supprimé',
            'message'    => "« {$titre} » (#{$livreId}) a été retiré du catalogue par l'admin #{$adminId}.",
            'priorite'   => self::PRIO_HIGH,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    /**
     * Stock faible détecté (≤ seuil).
     */
    public function onLowStock(int $livreId, string $titre, int $stock, int $seuil = 5): void
    {
        // Vérifier qu'on n'a pas déjà notifié aujourd'hui pour ce livre
        if ($this->alreadyNotifiedToday($livreId, 'livre', 'warn', 'Stock faible')) {
            return;
        }

        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_WARN,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '⚠️ Stock faible',
            'message'    => "« {$titre} » n'a plus que {$stock} exemplaire(s) disponible(s) (seuil : {$seuil}).",
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../books/edit.php?id=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    /**
     * Rupture de stock.
     */
    public function onOutOfStock(int $livreId, string $titre): void
    {
        if ($this->alreadyNotifiedToday($livreId, 'livre', 'error', 'Rupture')) {
            return;
        }

        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_ERROR,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '📦 Rupture de stock',
            'message'    => "« {$titre} » est en rupture de stock. Action requise immédiatement.",
            'priorite'   => self::PRIO_CRITICAL,
            'lien'       => '../books/edit.php?id=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    /**
     * Publication d'un livre par un journaliste validée.
     */
    public function onBookPublished(int $livreId, string $titre, int $journalisteId, string $journalisteNom): void
    {
        // Notifier le journaliste
        $this->create([
            'user_id'    => $journalisteId,
            'type'       => self::TYPE_BOOK,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '✅ Publication validée',
            'message'    => "Votre livre « {$titre} » a été validé et est désormais disponible dans le catalogue.",
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../books/index.php?id=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);

        // Notifier l'admin
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_BOOK,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '📰 Nouvelle publication validée',
            'message'    => "« {$titre} » de {$journalisteNom} est maintenant publié.",
            'priorite'   => self::PRIO_MEDIUM,
            'lien'       => '../books/index.php?id=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    /**
     * Mise en favori d'un livre.
     * Note : le trigger SQL le fait automatiquement.
     * Appeler cette méthode UNIQUEMENT si le trigger est désactivé.
     */
    public function onFavoriAdded(int $userId, string $userNom, int $livreId, string $livreTitre): void
    {
        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_FAVORI,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '❤️ Ajouté aux favoris',
            'message'    => "« {$livreTitre} » a été ajouté à vos favoris.",
            'priorite'   => self::PRIO_LOW,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    /**
     * Commentaire/avis soumis.
     * Note : le trigger SQL le fait automatiquement.
     * Appeler cette méthode UNIQUEMENT si le trigger est désactivé.
     */
    public function onAvisSubmitted(int $userId, string $userNom, int $livreId, string $livreTitre, int $note): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_AVIS,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '⭐ Nouvel avis soumis',
            'message'    => "{$userNom} a laissé un avis {$note}/5 sur « {$livreTitre} ».",
            'priorite'   => self::PRIO_MEDIUM,
            'lien'       => '../admin/avis.php?livre=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC C — ÉVÉNEMENTS ACHATS & PAIEMENTS
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Achat confirmé.
     * Note : le trigger SQL trg_achat_apres_insert gère cela automatiquement.
     * N'appeler cette méthode QUE si l'achat est traité en PHP sans passer par
     * un INSERT dans la table achats (ex: paiement externe webhook).
     */
    public function onPurchaseConfirmed(int $userId, int $livreId, float $montant, string $reference): void
    {
        $livre = $this->fetchLivre($livreId);
        $user  = $this->fetchUser($userId);
        $titre = $livre['titre'] ?? "Livre #{$livreId}";
        $nom   = $this->formatName($user);

        // Notification acheteur
        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_SALE,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '✅ Achat confirmé !',
            'message'    => "Votre achat de « {$titre} » est confirmé. Référence : {$reference}",
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../books/reader.php?id=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);

        // Notification admin
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_SALE,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '💰 Nouvelle vente',
            'message'    => "{$nom} a acheté « {$titre} » · " . number_format($montant, 0, ',', ' ') . " FCFA. Réf : {$reference}",
            'priorite'   => self::PRIO_MEDIUM,
            'lien'       => '../admin/sales.php',
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);

        // Gros achat
        if ($montant > 5000) {
            $this->create([
                'user_id'    => null,
                'type'       => self::TYPE_SALE,
                'role_cible' => self::ROLE_ADMIN,
                'titre'      => '💎 Gros achat détecté',
                'message'    => "{$nom} a dépensé " . number_format($montant, 0, ',', ' ') . " FCFA sur « {$titre} ».",
                'priorite'   => self::PRIO_HIGH,
                'related_id' => $livreId,
                'related_type' => 'livre',
            ]);
        }

        // Vérifier bonus
        $this->checkAndNotifyBonus($userId);
    }

    /**
     * Paiement Mobile Money / Orange Money validé.
     */
    public function onPaymentValidated(int $userId, string $reference, float $montant, string $methode): void
    {
        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_PAYMENT,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '💳 Paiement confirmé',
            'message'    => "Votre paiement de " . number_format($montant, 0, ',', ' ') . " FCFA via {$methode} a été validé. Référence : {$reference}",
            'priorite'   => self::PRIO_HIGH,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);

        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_PAYMENT,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '💳 Paiement reçu',
            'message'    => "Paiement de " . number_format($montant, 0, ',', ' ') . " FCFA via {$methode}. Réf : {$reference}",
            'priorite'   => self::PRIO_MEDIUM,
            'lien'       => '../admin/payments.php',
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Paiement échoué.
     */
    public function onPaymentFailed(int $userId, int $livreId, string $methode, string $raison = ''): void
    {
        $livre = $this->fetchLivre($livreId);
        $titre = $livre['titre'] ?? "Livre #{$livreId}";
        $user  = $this->fetchUser($userId);
        $nom   = $this->formatName($user);

        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_ERROR,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '❌ Paiement refusé',
            'message'    => "Votre paiement {$methode} pour « {$titre} » n'a pas abouti."
                            . ($raison ? " Raison : {$raison}." : '')
                            . ' Veuillez réessayer.',
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../books/index.php?id=' . $livreId,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);

        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_ERROR,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '❌ Paiement échoué',
            'message'    => "{$nom} — Échec paiement {$methode} pour « {$titre} »."
                            . ($raison ? " Raison : {$raison}" : ''),
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../admin/payments.php',
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC D — SYSTÈME BONUS (logique complète et corrigée)
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Vérifie si un utilisateur est éligible au bonus et notifie si oui.
     * Appelé après chaque achat confirmé.
     *
     * Règle : 1 bonus tous les N achats payants (N défini dans settings.bonus_rule).
     * Le trigger SQL trg_achat_apres_insert gère déjà cela automatiquement.
     * Cette méthode sert de vérification PHP complémentaire (webhook, import, etc.)
     */
    public function checkAndNotifyBonus(int $userId): void
    {
        try {
            // Lire la règle bonus depuis settings (corrigé : setting_key)
            $stmtRule = $this->pdo->query(
                "SELECT CAST(COALESCE(setting_value, '5') AS UNSIGNED)
                 FROM settings WHERE setting_key = 'bonus_rule' LIMIT 1"
            );
            $bonusRule = (int)($stmtRule ? $stmtRule->fetchColumn() : 5);

            // Nombre d'achats payants confirmés
            $stmtAchats = $this->pdo->prepare(
                "SELECT COUNT(*) FROM achats
                 WHERE user_id = ? AND statut = 'confirme'
                 AND methode != 'bonus' AND montant > 0"
            );
            $stmtAchats->execute([$userId]);
            $nbAchats = (int)$stmtAchats->fetchColumn();

            // Nombre de bonus déjà reçus
            $stmtBonus = $this->pdo->prepare(
                "SELECT COALESCE(bonus_total, 0) FROM user_bonus WHERE user_id = ?"
            );
            $stmtBonus->execute([$userId]);
            $bonusTotal = (int)$stmtBonus->fetchColumn();

            // Prochain seuil bonus
            $prochainSeuil = ($bonusTotal + 1) * $bonusRule;

            if ($nbAchats >= $prochainSeuil) {
                $this->notifyBonusEligible($userId, $nbAchats, $bonusRule);
            }

            // Lecteur très actif → notif admin aux jalons
            if (in_array($nbAchats, [10, 20, 50, 100], true)) {
                $user = $this->fetchUser($userId);
                $nom  = $this->formatName($user);
                $this->create([
                    'user_id'    => null,
                    'type'       => self::TYPE_USER,
                    'role_cible' => self::ROLE_ADMIN,
                    'titre'      => '⚡ Lecteur très actif',
                    'message'    => "{$nom} a atteint {$nbAchats} achats. Envisagez un programme fidélité renforcé.",
                    'priorite'   => self::PRIO_MEDIUM,
                    'lien'       => '../users/index.php?id=' . $userId,
                    'related_id' => $userId,
                    'related_type' => 'user',
                ]);
            }

        } catch (PDOException $e) {
            error_log('[NotificationService] checkAndNotifyBonus: ' . $e->getMessage());
        }
    }

    /**
     * Notifie l'admin qu'un utilisateur est éligible au bonus.
     * Anti-doublon : pas de nouvelle notif si déjà envoyée dans les 24h.
     */
    public function notifyBonusEligible(int $userId, int $nbAchats, int $bonusRule): void
    {
        // Anti-doublon 24h
        if ($this->alreadyNotifiedRecently($userId, 'user', 'bonus', 'éligible au bonus', 24)) {
            return;
        }

        $user = $this->fetchUser($userId);
        $nom  = $this->formatName($user);

        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_BONUS,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🎁 Utilisateur éligible au bonus',
            'message'    => "{$nom} a effectué {$nbAchats} achats et mérite un livre gratuit (règle : {$bonusRule} achats).",
            'priorite'   => self::PRIO_HIGH,
            'lien'       => '../users/index.php?action=bonus&id=' . $userId,
            'related_id' => $userId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Attribue un bonus manuellement (appelé par l'admin depuis l'interface).
     * Délègue à la procédure stockée sp_attribuer_bonus si disponible,
     * sinon exécute la logique PHP complète.
     *
     * @return array{success: bool, message: string}
     */
    public function grantBonus(int $userId, int $livreId, int $adminId): array
    {
        try {
            // Tenter via procédure stockée (définie dans le schema SQL)
            $stmt = $this->pdo->prepare("CALL sp_attribuer_bonus(?, ?, ?)");
            $stmt->execute([$userId, $livreId, $adminId]);

            $livre = $this->fetchLivre($livreId);
            $user  = $this->fetchUser($userId);

            return [
                'success' => true,
                'message' => 'Bonus accordé à ' . $this->formatName($user)
                             . ' : « ' . ($livre['titre'] ?? "Livre #{$livreId}") . ' »',
            ];

        } catch (PDOException $e) {
            // Si la procédure n'existe pas, exécuter la logique PHP
            if (strpos($e->getMessage(), 'PROCEDURE') !== false) {
                return $this->grantBonusFallback($userId, $livreId, $adminId);
            }
            error_log('[NotificationService] grantBonus: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur : ' . $e->getMessage()];
        }
    }

    /**
     * Fallback PHP si la procédure stockée n'existe pas.
     */
    private function grantBonusFallback(int $userId, int $livreId, int $adminId): array
    {
        try {
            $livre = $this->fetchLivre($livreId);
            $user  = $this->fetchUser($userId);
            $titre = $livre['titre'] ?? "Livre #{$livreId}";
            $nom   = $this->formatName($user);
            $ref   = 'BONUS-' . time() . '-' . $userId . '-' . $livreId;

            // Insérer l'achat bonus
            $this->pdo->prepare(
                "INSERT IGNORE INTO achats (user_id, livre_id, montant, methode, statut, reference)
                 VALUES (?, ?, 0, 'bonus', 'confirme', ?)"
            )->execute([$userId, $livreId, $ref]);

            // Décrémenter bonus_restant
            $this->pdo->prepare(
                "UPDATE user_bonus SET bonus_restant = GREATEST(0, bonus_restant - 1) WHERE user_id = ?"
            )->execute([$userId]);

            // Historique
            $this->pdo->prepare(
                "INSERT INTO bonus_history (user_id, livre_id, type, detail)
                 VALUES (?, ?, 'utilise', ?)"
            )->execute([$userId, $livreId, "Attribué par admin #{$adminId}"]);

            // Notifications
            $this->create([
                'user_id'    => $userId,
                'type'       => self::TYPE_BONUS,
                'role_cible' => self::ROLE_ALL,
                'titre'      => '🎁 Vous avez reçu un cadeau !',
                'message'    => "Félicitations ! « {$titre} » vous est offert pour votre fidélité. Bonne lecture !",
                'priorite'   => self::PRIO_CRITICAL,
                'lien'       => '../books/reader.php?id=' . $livreId,
                'related_id' => $livreId,
                'related_type' => 'livre',
            ]);

            $this->create([
                'user_id'    => null,
                'type'       => self::TYPE_BONUS,
                'role_cible' => self::ROLE_ADMIN,
                'titre'      => '✅ Bonus attribué',
                'message'    => "Admin #{$adminId} a attribué « {$titre} » à {$nom}.",
                'priorite'   => self::PRIO_LOW,
                'related_id' => $userId,
                'related_type' => 'user',
            ]);

            return ['success' => true, 'message' => "Bonus accordé à {$nom} : « {$titre} »"];

        } catch (PDOException $e) {
            error_log('[NotificationService] grantBonusFallback: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC E — ÉVÉNEMENTS LECTURE & PROGRESSION
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Progression de lecture mise à jour.
     * Notifie uniquement aux jalons significatifs (25%, 50%, 75%, 100%).
     */
    public function onReadingProgress(int $userId, int $livreId, float $pourcentage, float $ancienPct = 0): void
    {
        $jalons = [25, 50, 75, 100];

        foreach ($jalons as $jalon) {
            if ($ancienPct < $jalon && $pourcentage >= $jalon) {
                $livre = $this->fetchLivre($livreId);
                $titre = $livre['titre'] ?? "Livre #{$livreId}";

                if ($jalon === 100) {
                    $this->create([
                        'user_id'    => $userId,
                        'type'       => self::TYPE_READING,
                        'role_cible' => self::ROLE_ALL,
                        'titre'      => '🏆 Livre terminé !',
                        'message'    => "Bravo ! Vous avez terminé « {$titre} ». Partagez votre avis !",
                        'priorite'   => self::PRIO_MEDIUM,
                        'lien'       => '../books/avis.php?id=' . $livreId,
                        'related_id' => $livreId,
                        'related_type' => 'livre',
                    ]);
                } else {
                    $this->create([
                        'user_id'    => $userId,
                        'type'       => self::TYPE_READING,
                        'role_cible' => self::ROLE_ALL,
                        'titre'      => "📖 {$jalon}% de « {$titre} »",
                        'message'    => "Vous êtes à {$jalon}% de « {$titre} ». Continuez !",
                        'priorite'   => self::PRIO_LOW,
                        'lien'       => '../books/reader.php?id=' . $livreId,
                        'related_id' => $livreId,
                        'related_type' => 'livre',
                    ]);
                }
                break; // Un seul jalon par appel
            }
        }
    }

    /**
     * Livre téléchargé.
     * Note : le trigger SQL gère cela automatiquement.
     */
    public function onDownload(int $userId, string $userNom, int $livreId, string $livreTitre): void
    {
        $this->create([
            'user_id'    => $userId,
            'type'       => self::TYPE_DOWNLOAD,
            'role_cible' => self::ROLE_ALL,
            'titre'      => '⬇️ Téléchargement PDF',
            'message'    => "Le PDF de « {$livreTitre} » a été téléchargé.",
            'priorite'   => self::PRIO_LOW,
            'related_id' => $livreId,
            'related_type' => 'livre',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC F — ÉVÉNEMENTS SYSTÈME & ADMIN
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Erreur système critique.
     */
    public function onSystemError(string $contexte, string $message): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_ERROR,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '🔴 Erreur système',
            'message'    => "[{$contexte}] " . mb_substr($message, 0, 500),
            'priorite'   => self::PRIO_CRITICAL,
        ]);
        error_log("[DLS SYSTEM ERROR] [{$contexte}] {$message}");
    }

    /**
     * Alerte serveur / maintenance.
     */
    public function onMaintenanceAlert(string $titre, string $message, string $priorite = self::PRIO_HIGH): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_SYSTEM,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => $titre,
            'message'    => $message,
            'priorite'   => $priorite,
        ]);
    }

    /**
     * Export/rapport généré.
     */
    public function onReportExported(int $adminId, string $typeRapport, string $fichier = ''): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_EXPORT,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '📤 Rapport exporté',
            'message'    => "Admin #{$adminId} a généré un export : {$typeRapport}."
                            . ($fichier ? " Fichier : {$fichier}" : ''),
            'priorite'   => self::PRIO_LOW,
            'related_id' => $adminId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Action admin générique (blocage, suppression, changement critique).
     */
    public function onAdminAction(int $adminId, string $action, string $detail = ''): void
    {
        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_SYSTEM,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '⚙️ Action administrateur',
            'message'    => "Admin #{$adminId} — {$action}" . ($detail ? " : {$detail}" : ''),
            'priorite'   => self::PRIO_MEDIUM,
            'related_id' => $adminId,
            'related_type' => 'user',
        ]);
    }

    /**
     * Sauvegarde système effectuée.
     */
    public function onBackupDone(string $fichier, int $tailleOctets): void
    {
        $taille = $tailleOctets > 1048576
            ? round($tailleOctets / 1048576, 1) . ' Mo'
            : round($tailleOctets / 1024, 1) . ' Ko';

        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_SYSTEM,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '💾 Sauvegarde effectuée',
            'message'    => "Sauvegarde « {$fichier} » ({$taille}) créée avec succès.",
            'priorite'   => self::PRIO_LOW,
        ]);
    }

    /**
     * Pic d'activité détecté (> seuil achats / heure).
     */
    public function onHighActivity(int $nbActions, string $periode = '1 heure'): void
    {
        if ($this->alreadyNotifiedRecently(0, 'system', 'system', 'Forte activité', 1)) {
            return;
        }

        $this->create([
            'user_id'    => null,
            'type'       => self::TYPE_SYSTEM,
            'role_cible' => self::ROLE_ADMIN,
            'titre'      => '📈 Forte activité détectée',
            'message'    => "{$nbActions} actions enregistrées en {$periode}. Pic de trafic en cours.",
            'priorite'   => self::PRIO_MEDIUM,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC G — AUTO-SCAN (détection proactive depuis la base)
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scan automatique de la base.
     * À appeler depuis le dashboard admin ou un cron PHP.
     * Détecte : stocks faibles, utilisateurs éligibles au bonus,
     *           livres populaires, forte activité achats.
     */
    public function autoScan(): array
    {
        $rapport = [];

        try {
            // ── 1. Livres en rupture de stock ──────────────────────────────
            $ruptures = $this->pdo->query(
                "SELECT id, titre FROM livres WHERE stock = 0 AND statut = 'disponible'"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($ruptures as $l) {
                $this->onOutOfStock((int)$l['id'], $l['titre']);
            }
            if ($ruptures) {
                $rapport[] = count($ruptures) . ' livre(s) en rupture notifié(s)';
            }

            // ── 2. Livres stock faible (1–5 ex.) ──────────────────────────
            $faibles = $this->pdo->query(
                "SELECT id, titre, stock FROM livres
                 WHERE stock > 0 AND stock <= 5 AND statut = 'disponible'"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($faibles as $l) {
                $this->onLowStock((int)$l['id'], $l['titre'], (int)$l['stock']);
            }
            if ($faibles) {
                $rapport[] = count($faibles) . ' livre(s) à stock faible notifié(s)';
            }

            // ── 3. Utilisateurs éligibles bonus ────────────────────────────
            $stmtRule = $this->pdo->query(
                "SELECT CAST(COALESCE(setting_value,'5') AS UNSIGNED)
                 FROM settings WHERE setting_key = 'bonus_rule' LIMIT 1"
            );
            $bonusRule = (int)($stmtRule ? $stmtRule->fetchColumn() : 5);

            $eligibles = $this->pdo->query("
                SELECT u.id,
                       CONCAT(u.prenom, ' ', u.nom) AS nom,
                       COUNT(a.id)                  AS nb_achats,
                       COALESCE(ub.bonus_total, 0)  AS nb_bonus
                FROM users u
                JOIN achats a
                     ON a.user_id = u.id
                     AND a.statut = 'confirme'
                     AND a.montant > 0
                     AND a.methode != 'bonus'
                LEFT JOIN user_bonus ub ON ub.user_id = u.id
                GROUP BY u.id, u.prenom, u.nom, ub.bonus_total
                HAVING nb_achats >= (nb_bonus + 1) * {$bonusRule}
            ")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($eligibles as $e) {
                $this->notifyBonusEligible((int)$e['id'], (int)$e['nb_achats'], $bonusRule);
            }
            if ($eligibles) {
                $rapport[] = count($eligibles) . ' utilisateur(s) éligible(s) au bonus';
            }

            // ── 4. Forte activité achats dernière heure ────────────────────
            $stmtAct = $this->pdo->query(
                "SELECT COUNT(*) FROM achats
                 WHERE statut = 'confirme'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $nbRecents = (int)$stmtAct->fetchColumn();

            if ($nbRecents > 10) {
                $this->onHighActivity($nbRecents);
                $rapport[] = "Pic de trafic : {$nbRecents} achats/heure";
            }

        } catch (PDOException $e) {
            error_log('[NotificationService] autoScan: ' . $e->getMessage());
            $rapport[] = 'Erreur scan : ' . $e->getMessage();
        }

        return $rapport;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC H — LECTURE (endpoints AJAX + page notifications)
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nombre de notifications non lues.
     * Utilisé par le polling AJAX toutes les 15 secondes.
     *
     * @param int  $userId    ID de l'utilisateur connecté
     * @param bool $isAdmin   true = compte toutes les notifs admin
     * @param string $role    Rôle de l'utilisateur ('lecteur','journaliste','admin')
     */
    public function getUnreadCount(int $userId, bool $isAdmin = false, string $role = 'lecteur'): int
    {
        try {
            if ($isAdmin) {
                return (int)$this->pdo->query(
                    "SELECT COUNT(*) FROM notifications WHERE lu = 0"
                )->fetchColumn();
            }

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM notifications
                 WHERE lu = 0
                 AND (
                     user_id = :uid
                     OR (user_id IS NULL AND role_cible IN ('all', :role))
                 )"
            );
            $stmt->execute([':uid' => $userId, ':role' => $role]);
            return (int)$stmt->fetchColumn();

        } catch (PDOException $e) {
            error_log('[NotificationService] getUnreadCount: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retourne les N dernières notifications pour le dropdown topbar.
     * Appelé par l'endpoint AJAX ?ajax=1&ajax_action=list
     */
    public function getForDropdown(int $userId, bool $isAdmin = false, string $role = 'lecteur', int $limit = 10): array
    {
        try {
            if ($isAdmin) {
                $stmt = $this->pdo->prepare(
                    "SELECT n.*, u.nom AS user_nom, u.prenom AS user_prenom
                     FROM notifications n
                     LEFT JOIN users u ON u.id = n.user_id
                     ORDER BY n.created_at DESC
                     LIMIT :lim"
                );
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT n.*, u.nom AS user_nom, u.prenom AS user_prenom
                     FROM notifications n
                     LEFT JOIN users u ON u.id = n.user_id
                     WHERE n.user_id = :uid
                        OR (n.user_id IS NULL AND n.role_cible IN ('all', :role))
                     ORDER BY n.created_at DESC
                     LIMIT :lim"
                );
                $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':role', $role);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
            }

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log('[NotificationService] getForDropdown: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère les notifications avec filtres + pagination.
     * Utilisé par la page notifications.php
     *
     * @return array{items: array, total: int, pages: int, current: int, per_page: int}
     */
    public function getPaginated(array $filtres = [], int $page = 1, int $parPage = 20, bool $isAdmin = false, int $userId = 0, string $role = 'lecteur'): array
    {
        try {
            $where  = [];
            $params = [];

            // Restriction rôle (non-admin : ses propres notifs + globales)
            if (!$isAdmin) {
                $where[]           = "(n.user_id = :uid OR (n.user_id IS NULL AND n.role_cible IN ('all', :role)))";
                $params[':uid']    = $userId;
                $params[':role']   = $role;
            }

            // Filtre type
            if (!empty($filtres['type']) && $filtres['type'] !== 'all') {
                $where[]           = "n.type = :type";
                $params[':type']   = $filtres['type'];
            }

            // Filtre lu/non-lu
            if (isset($filtres['lu']) && in_array($filtres['lu'], ['0', '1'], true)) {
                $where[]           = "n.lu = :lu";
                $params[':lu']     = (int)$filtres['lu'];
            }

            // Filtre priorité
            if (!empty($filtres['priorite'])) {
                $where[]               = "n.priorite = :priorite";
                $params[':priorite']   = $filtres['priorite'];
            }

            // Filtre recherche textuelle
            if (!empty($filtres['search'])) {
                $where[]               = "(n.titre LIKE :search OR n.message LIKE :search)";
                $params[':search']     = '%' . $filtres['search'] . '%';
            }

            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $offset   = max(0, ($page - 1) * $parPage);

            // Total
            $stmtCount = $this->pdo->prepare(
                "SELECT COUNT(*) FROM notifications n {$whereSQL}"
            );
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            // Données
            $stmtData = $this->pdo->prepare(
                "SELECT n.*,
                        u.nom    AS user_nom,
                        u.prenom AS user_prenom,
                        u.email  AS user_email,
                        u.role   AS user_role
                 FROM notifications n
                 LEFT JOIN users u ON u.id = n.user_id
                 {$whereSQL}
                 ORDER BY
                     CASE n.priorite
                         WHEN 'critical' THEN 1
                         WHEN 'high'     THEN 2
                         WHEN 'medium'   THEN 3
                         WHEN 'low'      THEN 4
                     END ASC,
                     n.created_at DESC
                 LIMIT :lim OFFSET :off"
            );

            foreach ($params as $k => $v) {
                $stmtData->bindValue($k, $v);
            }
            $stmtData->bindValue(':lim', $parPage, PDO::PARAM_INT);
            $stmtData->bindValue(':off', $offset,  PDO::PARAM_INT);
            $stmtData->execute();

            return [
                'items'    => $stmtData->fetchAll(PDO::FETCH_ASSOC),
                'total'    => $total,
                'pages'    => max(1, (int)ceil($total / $parPage)),
                'current'  => $page,
                'per_page' => $parPage,
            ];

        } catch (PDOException $e) {
            error_log('[NotificationService] getPaginated: ' . $e->getMessage());
            return ['items' => [], 'total' => 0, 'pages' => 1, 'current' => 1, 'per_page' => $parPage];
        }
    }

    /**
     * Types distincts présents en base (pour les filtres).
     */
    public function getTypes(int $userId = 0, bool $isAdmin = false, string $role = 'lecteur'): array
    {
        try {
            if ($isAdmin) {
                return array_column(
                    $this->pdo->query(
                        "SELECT DISTINCT type FROM notifications ORDER BY type"
                    )->fetchAll(PDO::FETCH_ASSOC),
                    'type'
                );
            }

            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT type FROM notifications
                 WHERE user_id = :uid
                    OR (user_id IS NULL AND role_cible IN ('all', :role))
                 ORDER BY type"
            );
            $stmt->execute([':uid' => $userId, ':role' => $role]);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'type');

        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Analytics notifications (pour le dashboard admin).
     */
    public function getAnalytics(): array
    {
        try {
            return [
                'total'           => (int)$this->pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn(),
                'non_lues'        => (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE lu = 0")->fetchColumn(),
                'critiques'       => (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE priorite = 'critical' AND lu = 0")->fetchColumn(),
                'aujourd_hui'     => $this->scalarQ("SELECT COUNT(*) FROM notifications WHERE DATE(created_at) = CURDATE()"),
                'cette_semaine'   => $this->scalarQ("SELECT COUNT(*) FROM notifications WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
                'bonus_en_attente'=> $this->scalarQ("SELECT COUNT(*) FROM notifications WHERE type = 'bonus' AND lu = 0 AND user_id IS NULL"),
                'par_type'        => $this->pdo->query(
                    "SELECT type, COUNT(*) AS total, SUM(lu = 0) AS non_lues
                     FROM notifications GROUP BY type ORDER BY total DESC"
                )->fetchAll(PDO::FETCH_ASSOC),
                'par_priorite'    => $this->pdo->query(
                    "SELECT priorite, COUNT(*) AS total, SUM(lu = 0) AS non_lues
                     FROM notifications GROUP BY priorite ORDER BY FIELD(priorite,'critical','high','medium','low')"
                )->fetchAll(PDO::FETCH_ASSOC),
            ];
        } catch (PDOException $e) {
            error_log('[NotificationService] getAnalytics: ' . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  BLOC I — ACTIONS (marquer lu, supprimer, bulk)
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Marquer une notification comme lue.
     */
    public function markRead(int $id, int $userId, bool $isAdmin = false): bool
    {
        try {
            if ($isAdmin) {
                $stmt = $this->pdo->prepare(
                    "UPDATE notifications SET lu = 1, lu_at = NOW() WHERE id = ?"
                );
                $ok = $stmt->execute([$id]);
            } else {
                $stmt = $this->pdo->prepare(
                    "UPDATE notifications SET lu = 1, lu_at = NOW()
                     WHERE id = ? AND (user_id = ? OR user_id IS NULL)"
                );
                $ok = $stmt->execute([$id, $userId]);
            }
            if ($ok) {
                $this->audit($id, 'read', $userId);
            }
            return $ok;
        } catch (PDOException $e) {
            error_log('[NotificationService] markRead: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Tout marquer comme lu.
     * Retourne le nombre de notifications mises à jour.
     */
    public function markAllRead(int $userId, bool $isAdmin = false, string $role = 'lecteur'): int
    {
        try {
            if ($isAdmin) {
                $nb = $this->pdo->exec(
                    "UPDATE notifications SET lu = 1, lu_at = NOW() WHERE lu = 0"
                );
            } else {
                $stmt = $this->pdo->prepare(
                    "UPDATE notifications SET lu = 1, lu_at = NOW()
                     WHERE lu = 0
                     AND (user_id = :uid OR (user_id IS NULL AND role_cible IN ('all', :role)))"
                );
                $stmt->execute([':uid' => $userId, ':role' => $role]);
                $nb = $stmt->rowCount();
            }
            return (int)$nb;
        } catch (PDOException $e) {
            error_log('[NotificationService] markAllRead: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Supprimer une notification (admin seulement).
     */
    public function delete(int $id): bool
    {
        try {
            return $this->pdo->prepare(
                "DELETE FROM notifications WHERE id = ?"
            )->execute([$id]);
        } catch (PDOException $e) {
            error_log('[NotificationService] delete: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer toutes les notifications lues (admin seulement).
     * Retourne le nombre de lignes supprimées.
     */
    public function deleteAllRead(): int
    {
        try {
            return (int)$this->pdo->exec(
                "DELETE FROM notifications WHERE lu = 1"
            );
        } catch (PDOException $e) {
            error_log('[NotificationService] deleteAllRead: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Actions en masse sur une liste d'IDs.
     *
     * @param int[]  $ids    Liste d'IDs à traiter
     * @param string $action 'read' | 'unread' | 'delete'
     */
    public function bulkAction(array $ids, string $action): int
    {
        if (empty($ids)) {
            return 0;
        }

        // Sécuriser les IDs (injection impossible grâce à intval)
        $ids     = array_map('intval', $ids);
        $inClause = implode(',', $ids);

        try {
            return match ($action) {
                'read'   => (int)$this->pdo->exec(
                    "UPDATE notifications SET lu = 1, lu_at = NOW() WHERE id IN ({$inClause})"
                ),
                'unread' => (int)$this->pdo->exec(
                    "UPDATE notifications SET lu = 0, lu_at = NULL WHERE id IN ({$inClause})"
                ),
                'delete' => (int)$this->pdo->exec(
                    "DELETE FROM notifications WHERE id IN ({$inClause})"
                ),
                default  => 0,
            };
        } catch (PDOException $e) {
            error_log('[NotificationService] bulkAction: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Nettoyer les anciennes notifications lues (> $jours jours).
     */
    public function cleanup(int $jours = 30): int
    {
        try {
            $nb = (int)$this->pdo->prepare(
                "DELETE FROM notifications WHERE lu = 1 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            )->execute([$jours]) ? $this->pdo->prepare(
                "SELECT ROW_COUNT()"
            )->execute() : 0;

            // Plus propre : utiliser exec avec date calculée
            $date = date('Y-m-d H:i:s', strtotime("-{$jours} days"));
            $stmt = $this->pdo->prepare(
                "DELETE FROM notifications WHERE lu = 1 AND created_at < ?"
            );
            $stmt->execute([$date]);
            $nb = $stmt->rowCount();

            if ($nb > 0) {
                $this->create([
                    'user_id'    => null,
                    'type'       => self::TYPE_SYSTEM,
                    'role_cible' => self::ROLE_ADMIN,
                    'titre'      => '🧹 Nettoyage automatique',
                    'message'    => "{$nb} notification(s) lue(s) de plus de {$jours} jour(s) supprimée(s).",
                    'priorite'   => self::PRIO_LOW,
                ]);
            }

            return $nb;
        } catch (PDOException $e) {
            error_log('[NotificationService] cleanup: ' . $e->getMessage());
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════
    //  HELPERS STATIQUES — Labels, icônes, classes CSS
    // ══════════════════════════════════════════════════════════════════════════
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne l'icône associée à un type de notification.
     */
    public static function getIcon(string $type): string
    {
        return self::META[$type]['icon'] ?? '🔔';
    }

    /**
     * Retourne la couleur de fond CSS associée à un type.
     */
    public static function getBg(string $type): string
    {
        return self::META[$type]['bg'] ?? 'rgba(0,212,255,.08)';
    }

    /**
     * Libellé lisible d'un type.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'sale'     => 'Vente',
            'bonus'    => 'Bonus',
            'user'     => 'Utilisateur',
            'book'     => 'Livre',
            'download' => 'Téléchargement',
            'payment'  => 'Paiement',
            'login'    => 'Connexion',
            'register' => 'Inscription',
            'block'    => 'Blocage',
            'system'   => 'Système',
            'warn'     => 'Avertissement',
            'error'    => 'Erreur',
            'security' => 'Sécurité',
            'reading'  => 'Lecture',
            'favori'   => 'Favoris',
            'avis'     => 'Avis',
            'export'   => 'Export',
            'info'     => 'Information',
            default    => ucfirst($type),
        };
    }

    /**
     * Classe CSS Bootstrap pour le badge de priorité.
     */
    public static function prioriteClass(string $priorite): string
    {
        return match ($priorite) {
            'critical' => 'badge-rose',
            'high'     => 'badge-amber',
            'medium'   => 'badge-cyan',
            'low'      => 'badge-muted',
            default    => 'badge-muted',
        };
    }

    /**
     * Temps relatif lisible ("il y a 3 min", "hier", etc.)
     */
    public static function timeAgo(string $datetime): string
    {
        if (!$datetime) {
            return '—';
        }

        $diff = time() - strtotime($datetime);

        if ($diff < 0) {
            return 'à l\'instant';
        }
        if ($diff < 60) {
            return 'à l\'instant';
        }
        if ($diff < 3600) {
            $m = (int)($diff / 60);
            return $m . ' min';
        }
        if ($diff < 86400) {
            $h = (int)($diff / 3600);
            return $h . 'h';
        }
        if ($diff < 172800) {
            return 'hier';
        }
        if ($diff < 604800) {
            return (int)($diff / 86400) . 'j';
        }

        return date('d/m/Y', strtotime($datetime));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchUser(int $id): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, nom, prenom, email, role FROM users WHERE id = ?"
            );
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    private function fetchLivre(int $id): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, titre, auteur, prix, stock, ajoute_par FROM livres WHERE id = ?"
            );
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    private function formatName(array $user): string
    {
        if (empty($user)) {
            return 'Utilisateur inconnu';
        }
        return trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) ?: "User #{$user['id']}";
    }

    private function scalarQ(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Vérifie si une notification similaire a déjà été envoyée aujourd'hui.
     * Evite les doublons pour les alertes récurrentes (stock faible, etc.)
     */
    private function alreadyNotifiedToday(int $relatedId, string $relatedType, string $type, string $titrePortion): bool
    {
        return $this->alreadyNotifiedRecently($relatedId, $relatedType, $type, $titrePortion, 24);
    }

    /**
     * Vérifie si une notification similaire a été envoyée dans les N dernières heures.
     */
    private function alreadyNotifiedRecently(int $relatedId, string $relatedType, string $type, string $titrePortion, int $heures): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM notifications
                 WHERE type = ?
                 AND related_id = ?
                 AND related_type = ?
                 AND titre LIKE ?
                 AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)"
            );
            $stmt->execute([$type, $relatedId, $relatedType, "%{$titrePortion}%", $heures]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false; // En cas d'erreur, on laisse passer
        }
    }
}