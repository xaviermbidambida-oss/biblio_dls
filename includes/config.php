<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — includes/config.php  v4.0 FINAL             ║
 * ║  Bootstrap global — point d'entrée unique pour tous les         ║
 * ║  fichiers PHP du projet                                         ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * Chaque page PHP doit commencer par :
 *   require_once __DIR__ . '/../includes/config.php';
 *
 * Ce fichier gère automatiquement :
 *   1.  Session sécurisée
 *   2.  Constantes DB + clé API Anthropic
 *   3.  Connexion PDO singleton
 *   4.  Chargement AppSettings
 *   5.  Helpers globaux (e(), csrf, flash, etc.)
 *   6.  Authentification & rôles
 *   7.  Raccourcis Settings
 *   8.  Constantes dérivées
 */

declare(strict_types=1);

/* ── Empêcher l'inclusion multiple ─────────────────────────────── */
if (defined('DLS_CONFIG_LOADED')) return;
define('DLS_CONFIG_LOADED', true);

/* ═══════════════════════════════════════════════════════════════════
   1. SESSION SÉCURISÉE
   ═══════════════════════════════════════════════════════════════════ */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ═══════════════════════════════════════════════════════════════════
   2. CONSTANTES — BASE DE DONNÉES & ANTHROPIC API
   ═══════════════════════════════════════════════════════════════════ */

/* ── Paramètres MySQL ───────────────────────────────────────────── */
if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    'digital_library');
if (!defined('DB_USER'))    define('DB_USER',    'root');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

/* ── Clé API Anthropic (Claude) ─────────────────────────────────── */
/*
 * COMMENT CONFIGURER :
 * Option A (recommandé en prod) : variable d'environnement
 *   export ANTHROPIC_API_KEY="sk-ant-..."
 * Option B : définir directement ci-dessous (ne jamais committer)
 *   define('ANTHROPIC_API_KEY', 'sk-ant-...');
 * Option C : fichier .env chargé au préalable
 */
if (!defined('ANTHROPIC_API_KEY')) {
    // Chercher dans les variables d'environnement en priorité
    $apiKeyEnv = getenv('ANTHROPIC_API_KEY') ?: ($_ENV['ANTHROPIC_API_KEY'] ?? '');
    define('ANTHROPIC_API_KEY', $apiKeyEnv);
    unset($apiKeyEnv);
}

/* ── Modèle Claude et paramètres par défaut ─────────────────────── */
if (!defined('ANTHROPIC_MODEL'))      define('ANTHROPIC_MODEL',     'claude-sonnet-4-20250514');
if (!defined('ANTHROPIC_MAX_TOKENS')) define('ANTHROPIC_MAX_TOKENS', 800);
if (!defined('ANTHROPIC_TIMEOUT'))    define('ANTHROPIC_TIMEOUT',    30);
if (!defined('ANTHROPIC_VERSION'))    define('ANTHROPIC_VERSION',   '2023-06-01');

/* ── Chemin racine de l'application ────────────────────────────── */
if (!defined('DLS_ROOT')) {
    define('DLS_ROOT', dirname(__DIR__));
}

/* ═══════════════════════════════════════════════════════════════════
   3. CONNEXION PDO SINGLETON
   ═══════════════════════════════════════════════════════════════════ */
if (!function_exists('getDB')) {

    /**
     * Retourne la connexion PDO singleton (créée à la première demande).
     * Lance une erreur HTTP 503 si la base est inaccessible.
     */
    function getDB(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=%s',
                    DB_HOST, DB_NAME, DB_CHARSET
                );
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]);
            } catch (PDOException $e) {
                error_log('[DLS] DB connection failed: ' . $e->getMessage());
                http_response_code(503);
                die(json_encode([
                    'error'  => 'Base de données temporairement indisponible.',
                    'answer' => null,
                ]));
            }
        }
        return $pdo;
    }
}

/* Alias $pdo global pour la rétrocompatibilité (certains fichiers utilisent $pdo directement) */
if (!isset($GLOBALS['_dls_pdo_exposed'])) {
    $GLOBALS['_dls_pdo_exposed'] = true;
    try {
        $pdo = getDB();
    } catch (Throwable $e) {
        $pdo = null;
        error_log('[DLS] getDB() global alias failed: ' . $e->getMessage());
    }
}

/* ═══════════════════════════════════════════════════════════════════
   4. CHARGEMENT DU SETTINGS MANAGER
   ═══════════════════════════════════════════════════════════════════ */
$_settingsManagerPath = __DIR__ . '/settings_manager.php';
if (file_exists($_settingsManagerPath)) {
    require_once $_settingsManagerPath;
} else {
    /* ── Fallback minimal si settings_manager.php est absent ──── */
    if (!class_exists('AppSettings')) {

        class AppSettings
        {
            /** @var array<string,string> Valeurs par défaut */
            private static array $cache = [];

            private static array $defaults = [
                'site_name'     => 'Digital Library',
                'primary_color' => '#00d4ff',
                'theme'         => 'dark',
                'language'      => 'fr',
                'currency'      => 'FCFA',
                'date_format'   => 'DD/MM/YYYY',
                'pagination'    => '20',
                'bonus_rule'    => '5',
                'default_access'=> 'paid',
                'max_downloads' => '3',
                'notif_enabled' => '1',
                'notif_sales'   => '1',
                'notif_bonus'   => '1',
                'notif_users'   => '1',
                'two_fa'        => '0',
                'site_logo'     => '',
                'timezone'      => 'Africa/Douala',
            ];

            /** Charge les settings depuis la BD (avec cache statique) */
            private static function load(): void
            {
                if (!empty(self::$cache)) return;
                self::$cache = self::$defaults; // valeurs par défaut
                try {
                    $rows = getDB()
                        ->query("SELECT setting_key, setting_value FROM settings")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
                    self::$cache = array_merge(self::$cache, $rows);
                } catch (Throwable) {
                    // BD indispo → on garde les défauts
                }
            }

            public static function get(string $k, $default = null): mixed
            {
                self::load();
                return self::$cache[$k] ?? $default;
            }

            public static function all(): array     { self::load(); return self::$cache; }
            public static function lang(): string   { return (string)(self::get('language', 'fr')); }
            public static function theme(): string  { return (string)(self::get('theme', 'dark')); }
            public static function primaryColor(): string { return (string)(self::get('primary_color', '#00d4ff')); }
            public static function siteName(): string     { return (string)(self::get('site_name', 'Digital Library')); }
            public static function perPage(): int         { return (int)(self::get('pagination', 20)); }
            public static function bonusRule(): int       { return (int)(self::get('bonus_rule', 5)); }
            public static function maxDownloads(): int    { return (int)(self::get('max_downloads', 3)); }
            public static function notifEnabled(): bool   { return (bool)(int)(self::get('notif_enabled', '1')); }
            public static function notifSales(): bool     { return (bool)(int)(self::get('notif_sales', '1')); }
            public static function notifBonus(): bool     { return (bool)(int)(self::get('notif_bonus', '1')); }
            public static function notifUsers(): bool     { return (bool)(int)(self::get('notif_users', '1')); }
            public static function defaultAccess(): string { return (string)(self::get('default_access', 'paid')); }
            public static function twoFaEnabled(): bool   { return (bool)(int)(self::get('two_fa', '0')); }
            public static function currencySymbol(): string { return (string)(self::get('currency', 'FCFA')); }

            public static function fmtDate(?string $d, string $fb = '—'): string
            {
                return $d ? date('d/m/Y', strtotime($d)) : $fb;
            }

            public static function fmtDateTime(?string $d, string $fb = '—'): string
            {
                return $d ? date('d/m/Y H:i', strtotime($d)) : $fb;
            }

            public static function fmtMoney(float $a, bool $compact = false): string
            {
                $currency = self::currencySymbol();
                if ($compact && $a >= 1_000_000) {
                    return number_format($a / 1_000_000, 1, ',', ' ') . 'M ' . $currency;
                }
                return number_format($a, 0, ',', ' ') . ' ' . $currency;
            }

            public static function clearCache(): void { self::$cache = []; }

            public static function htmlAttrs(): string
            {
                $lang  = htmlspecialchars(self::lang(), ENT_QUOTES);
                $theme = htmlspecialchars(self::theme(), ENT_QUOTES);
                return "lang=\"{$lang}\" data-theme=\"{$theme}\"";
            }

            public static function injectCss(): string
            {
                $color = htmlspecialchars(self::primaryColor(), ENT_QUOTES);
                return "<style>:root{--primary:{$color};}</style>";
            }

            /** Traduction basique (à étendre) */
            public static function t(string $key): string
            {
                $translations = [
                    'save'   => self::lang() === 'fr' ? 'Enregistrer' : 'Save',
                    'cancel' => self::lang() === 'fr' ? 'Annuler'     : 'Cancel',
                    'delete' => self::lang() === 'fr' ? 'Supprimer'   : 'Delete',
                    'edit'   => self::lang() === 'fr' ? 'Modifier'    : 'Edit',
                    'search' => self::lang() === 'fr' ? 'Rechercher'  : 'Search',
                ];
                return $translations[$key] ?? $key;
            }
        }
    }
}
unset($_settingsManagerPath);

/* ═══════════════════════════════════════════════════════════════════
   5. TIMEZONE & LOCALE
   ═══════════════════════════════════════════════════════════════════ */
$_tz = AppSettings::get('timezone', 'Africa/Douala');
date_default_timezone_set(
    in_array($_tz, timezone_identifiers_list(), true) ? $_tz : 'Africa/Douala'
);
unset($_tz);

if (AppSettings::lang() === 'en') {
    setlocale(LC_TIME, 'en_US.UTF-8', 'en_US', 'C');
} else {
    setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'C');
}

/* ═══════════════════════════════════════════════════════════════════
   6. AUTHENTIFICATION & RÔLES
   ═══════════════════════════════════════════════════════════════════ */

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(): void
    {
        if (!isLoggedIn()) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: /login.php?redirect=' . $redirect);
            exit;
        }
    }
}

if (!function_exists('requireRole')) {
    function requireRole(string ...$roles): void
    {
        requireLogin();
        $userRole = $_SESSION['user_role'] ?? 'lecteur';
        if (!in_array($userRole, $roles, true)) {
            http_response_code(403);
            header('Location: /dashboard.php?error=access_denied');
            exit;
        }
    }
}

if (!function_exists('currentUser')) {
    /**
     * Retourne l'utilisateur connecté depuis la BD (cache statique).
     */
    function currentUser(): ?array
    {
        if (!isLoggedIn()) return null;
        static $cachedUser = null;
        if ($cachedUser !== null) return $cachedUser;
        try {
            $stmt = getDB()->prepare(
                "SELECT id, nom, prenom, email, role, statut, avatar, created_at
                 FROM users WHERE id = ? AND statut = 'actif' LIMIT 1"
            );
            $stmt->execute([(int)$_SESSION['user_id']]);
            $cachedUser = $stmt->fetch() ?: null;
        } catch (Throwable) {
            $cachedUser = null;
        }
        return $cachedUser;
    }
}

if (!function_exists('isAdminWhitelisted')) {
    function isAdminWhitelisted(string $email): bool
    {
        try {
            $stmt = getDB()->prepare(
                "SELECT id FROM admin_whitelist WHERE email = ? LIMIT 1"
            );
            $stmt->execute([strtolower(trim($email))]);
            return (bool)$stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('detectUserRole')) {
    function detectUserRole(string $email): string
    {
        $email = strtolower(trim($email));
        if (preg_match('/^admin\.[a-z0-9.]+@adminsopecam\.com$/', $email)) {
            return isAdminWhitelisted($email) ? 'admin' : 'lecteur';
        }
        if (preg_match('/^journaliste\.[a-z0-9.]+@sopecam\.com$/', $email)) {
            return 'journaliste';
        }
        return 'lecteur';
    }
}

/* ═══════════════════════════════════════════════════════════════════
   7. FLASH MESSAGES
   ═══════════════════════════════════════════════════════════════════ */

if (!function_exists('setFlash')) {
    function setFlash(string $type, string $msg): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
    }
}

if (!function_exists('getFlash')) {
    /** @return array[] [{type, msg}] */
    function getFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}

if (!function_exists('hasFlash')) {
    function hasFlash(): bool
    {
        return !empty($_SESSION['_flash']);
    }
}

/* ═══════════════════════════════════════════════════════════════════
   8. SÉCURITÉ — CSRF
   ═══════════════════════════════════════════════════════════════════ */

if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCsrf')) {
    function verifyCsrf(string $token): bool
    {
        return !empty($_SESSION['csrf_token']) &&
               hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('csrfField')) {
    /** Retourne un <input> CSRF caché prêt à l'emploi */
    function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
    }
}

/* ═══════════════════════════════════════════════════════════════════
   9. HELPERS HTML & VALIDATION
   ═══════════════════════════════════════════════════════════════════ */

if (!function_exists('e')) {
    /** Échappe pour l'affichage HTML (XSS prevention) */
    function e(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('intval_safe')) {
    function intval_safe(mixed $value, int $min = 1, int $default = 1): int
    {
        $v = (int)$value;
        return ($v >= $min) ? $v : $default;
    }
}

if (!function_exists('isValidPhone')) {
    function isValidPhone(string $phone): bool
    {
        $clean = preg_replace('/[\s\-\.\(\)]/', '', $phone);
        return (bool)preg_match('/^(\+237)?6\d{8}$/', $clean);
    }
}

if (!function_exists('isValidEmail')) {
    function isValidEmail(string $email): bool
    {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

/* ═══════════════════════════════════════════════════════════════════
   10. HELPERS BD — REQUÊTES UTILITAIRES
   ═══════════════════════════════════════════════════════════════════ */

if (!function_exists('dbCount')) {
    function dbCount(string $sql, array $params = []): int
    {
        try {
            $stmt = getDB()->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}

if (!function_exists('dbFetch')) {
    function dbFetch(string $sql, array $params = []): ?array
    {
        try {
            $stmt = getDB()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('dbFetchAll')) {
    function dbFetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = getDB()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('dbExecute')) {
    /**
     * Exécute une requête INSERT/UPDATE/DELETE.
     * Retourne le nombre de lignes affectées ou -1 en cas d'erreur.
     */
    function dbExecute(string $sql, array $params = []): int
    {
        try {
            $stmt = getDB()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[DLS] dbExecute error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return -1;
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════
   11. RACCOURCIS SETTINGS (compatibilité fichiers existants)
   ═══════════════════════════════════════════════════════════════════ */

if (!function_exists('dls_date')) {
    function dls_date(?string $date, string $fallback = '—'): string
    {
        return AppSettings::fmtDate($date, $fallback);
    }
}

if (!function_exists('dls_money')) {
    function dls_money(float $amount, bool $compact = false): string
    {
        return AppSettings::fmtMoney($amount, $compact);
    }
}

if (!function_exists('dls_per_page')) {
    function dls_per_page(): int
    {
        return AppSettings::perPage();
    }
}

if (!function_exists('dls_clear_settings_cache')) {
    function dls_clear_settings_cache(): void
    {
        AppSettings::clearCache();
    }
}

if (!function_exists('__')) {
    function __(string $key): string
    {
        return AppSettings::t($key);
    }
}

if (!function_exists('dls_paginate')) {
    /**
     * Retourne [limit, offset] pour une pagination.
     * Usage : [$limit, $offset] = dls_paginate($_GET['page'] ?? 1);
     */
    function dls_paginate(int $page = 1): array
    {
        $limit  = AppSettings::perPage();
        $page   = max(1, $page);
        $offset = ($page - 1) * $limit;
        return [$limit, $offset];
    }
}

/**
 * Vérifie si la clé API Anthropic est configurée.
 * Utilisé par chat.php pour choisir le mode (API / fallback local).
 */
if (!function_exists('hasAnthropicKey')) {
    function hasAnthropicKey(): bool
    {
        return defined('ANTHROPIC_API_KEY') && strlen(ANTHROPIC_API_KEY) > 20;
    }
}

/* ═══════════════════════════════════════════════════════════════════
   12. CONSTANTES DÉRIVÉES DES SETTINGS
   ═══════════════════════════════════════════════════════════════════ */
if (!defined('DLS_SITE_NAME'))  define('DLS_SITE_NAME',  AppSettings::get('site_name',  'Digital Library'));
if (!defined('DLS_LANG'))       define('DLS_LANG',        AppSettings::lang());
if (!defined('DLS_THEME'))      define('DLS_THEME',       AppSettings::theme());
if (!defined('DLS_PRIMARY'))    define('DLS_PRIMARY',     AppSettings::primaryColor());
if (!defined('DLS_CURRENCY'))   define('DLS_CURRENCY',    AppSettings::get('currency', 'FCFA'));
if (!defined('DLS_PER_PAGE'))   define('DLS_PER_PAGE',    AppSettings::perPage());
if (!defined('DLS_BONUS_RULE')) define('DLS_BONUS_RULE',  AppSettings::bonusRule());
if (!defined('DLS_MAX_DL'))     define('DLS_MAX_DL',      AppSettings::maxDownloads());
if (!defined('DLS_DEF_ACCESS')) define('DLS_DEF_ACCESS',  AppSettings::defaultAccess());
if (!defined('DLS_2FA'))        define('DLS_2FA',         AppSettings::twoFaEnabled());
if (!defined('DLS_AI_ENABLED')) define('DLS_AI_ENABLED',  hasAnthropicKey());