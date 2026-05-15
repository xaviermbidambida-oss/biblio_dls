<?php
/**
 * config/AppSettings.php
 * ══════════════════════════════════════════════════════════════
 * Gestionnaire des paramètres globaux depuis la table settings.
 *
 * CORRECTION PRINCIPALE :
 *   La requête originale utilisait SELECT `key`, `value` FROM settings
 *   → `key` est un mot réservé MySQL → SQLSTATE[42S22] Column not found
 *
 *   SOLUTION : la colonne s'appelle setting_key / setting_value
 *   dans le schéma corrigé, et cette classe utilise ces noms.
 *
 * ══════════════════════════════════════════════════════════════
 * USAGE DANS dashboard.php (exemple corrigé) :
 *
 *   require_once __DIR__ . '/config/AppSettings.php';
 *   $settings = AppSettings::all($pdo);
 *   $siteName = AppSettings::get($pdo, 'site_name', 'Digital Library');
 *
 * ══════════════════════════════════════════════════════════════
 */

class AppSettings
{
    /** Cache statique pour éviter plusieurs requêtes par requête HTTP */
    private static ?array $cache = null;

    /**
     * Charge tous les paramètres depuis la base.
     * Retourne un tableau associatif [setting_key => setting_value].
     */
    public static function all(PDO $pdo): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];

        try {
            // ⚠️  CORRECTION : utilise setting_key et setting_value
            //     et NON `key` et `value` (mots réservés MySQL)
            $stmt = $pdo->query(
                "SELECT setting_key, setting_value FROM settings ORDER BY setting_key"
            );
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                self::$cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            // Si la table n'existe pas encore, on renvoie les valeurs par défaut
            error_log('[AppSettings] Erreur chargement settings : ' . $e->getMessage());
            self::$cache = self::defaults();
        }

        return self::$cache;
    }

    /**
     * Récupère un paramètre unique.
     */
    public static function get(PDO $pdo, string $key, string $default = ''): string
    {
        $all = self::all($pdo);
        return $all[$key] ?? $default;
    }

    /**
     * Met à jour ou insère un paramètre.
     * Utilise INSERT ... ON DUPLICATE KEY UPDATE pour l'idempotence.
     */
    public static function set(PDO $pdo, string $key, string $value): bool
    {
        try {
            // Invalider le cache
            self::$cache = null;

            $stmt = $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value)
                 VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = :v2"
            );
            return $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
        } catch (PDOException $e) {
            error_log('[AppSettings] Erreur set() : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Met à jour plusieurs paramètres d'un coup (batch).
     * Utilisé par la page admin/settings.php au moment de la sauvegarde.
     */
    public static function saveBatch(PDO $pdo, array $keyValues): bool
    {
        self::$cache = null;
        $ok = true;
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value)
                 VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = :v2"
            );
            foreach ($keyValues as $k => $v) {
                $ok = $stmt->execute([':k' => (string)$k, ':v' => (string)$v, ':v2' => (string)$v]) && $ok;
            }
        } catch (PDOException $e) {
            error_log('[AppSettings] Erreur saveBatch() : ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }

    /**
     * Valeurs par défaut utilisées si la table est inaccessible.
     */
    public static function defaults(): array
    {
        return [
            'site_name'      => 'Digital Library',
            'site_logo'      => '',
            'primary_color'  => '#00d4ff',
            'theme'          => 'dark',
            'language'       => 'fr',
            'currency'       => 'FCFA',
            'date_format'    => 'DD/MM/YYYY',
            'pagination'     => '20',
            'timezone'       => 'Africa/Douala',
            'notif_enabled'  => '1',
            'notif_sales'    => '1',
            'notif_bonus'    => '1',
            'notif_users'    => '1',
            'bonus_rule'     => '5',
            'default_access' => 'paid',
            'max_downloads'  => '3',
            'two_fa'         => '0',
            'dashboard_theme'=> 'dark',
        ];
    }

    /**
     * Invalide le cache (à appeler après une mise à jour).
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Crée la table settings si elle n'existe pas (garde-fou XAMPP).
     * À appeler une fois au démarrage de l'application.
     */
    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
                setting_key   VARCHAR(150)    NOT NULL UNIQUE,
                setting_value TEXT            DEFAULT NULL,
                updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Insérer les valeurs par défaut
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)"
        );
        foreach (self::defaults() as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }
}


// ══════════════════════════════════════════════════════════════
// CORRECTION DASHBOARD.PHP
// Remplacer ce bloc dans dashboard.php :
//
//   // ❌ AVANT (cause l'erreur) :
//   $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
//   while ($row = $stmt->fetch()) {
//       $settings[$row['setting_key']] = $row['setting_value'];
//   }
//
//   // ✅ APRÈS (utiliser AppSettings) :
//   require_once __DIR__ . '/config/AppSettings.php';
//   AppSettings::ensureTable($pdo);
//   $settings = AppSettings::all($pdo);
//
//   // Puis accéder aux valeurs :
//   $siteName     = $settings['site_name']       ?? 'Digital Library';
//   $primaryColor = $settings['primary_color']    ?? '#00d4ff';
//   $theme        = $settings['dashboard_theme']  ?? 'dark';
// ══════════════════════════════════════════════════════════════