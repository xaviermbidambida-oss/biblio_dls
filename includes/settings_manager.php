<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — includes/settings_manager.php  v3.0         ║
 * ║  Gestionnaire centralisé des paramètres                        ║
 * ║  Auto-injecté via includes/config.php dans TOUS les fichiers   ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * CHANGEMENTS v3.0 :
 *  - Bug fix : $this_color inexistant dans injectHead() → corrigé
 *  - Bug fix : self:: dans heredoc → corrigé avec variables locales
 *  - Bug fix : currencySymbol_static() était private → rendu public
 *  - Ajout : injectHead() fonctionnel et complet
 *  - Ajout : saveSetting() pour usage serveur-side
 *  - Ajout : invalidation cache auto après saveSetting()
 *  - Ajout : support timezone dans les settings
 */

if (!defined('DLS_CONFIG_LOADED')) {
    // Sécurité : ce fichier ne doit être chargé que via config.php
    // mais on permet l'inclusion directe pour les tests
    if (session_status() === PHP_SESSION_NONE) session_start();
}

// Éviter la redéclaration de la classe
if (class_exists('AppSettings')) return;

class AppSettings
{
    // ── Cache statique (une seule requête BD par cycle PHP) ───────────
    private static ?array $cache = null;

    // ── Valeurs par défaut (utilisées si la BD est inaccessible) ─────
    public static array $defaults = [
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
    ];

    /** TTL du cache session en secondes (5 min) */
    private static int $cacheTTL = 300;

    // ═══════════════════════════════════════════════════════════════════
    // LECTURE DES PARAMÈTRES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Retourne TOUS les paramètres.
     * Ordre de priorité : cache statique → cache session → BD → defaults
     */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;

        // Cache session encore valide ?
        $sk = '_dls_settings';
        $st = '_dls_settings_ts';
        if (
            isset($_SESSION[$sk], $_SESSION[$st]) &&
            (time() - (int)$_SESSION[$st]) < self::$cacheTTL
        ) {
            self::$cache = $_SESSION[$sk];
            return self::$cache;
        }

        // Charger depuis la BD
        $loaded = self::$defaults;
        try {
            $rows   = self::getDB()->query("SELECT `key`, `value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $loaded = array_merge(self::$defaults, $rows);
        } catch (Throwable $e) {
            error_log('[AppSettings] Cannot load settings from DB: ' . $e->getMessage());
        }

        self::$cache       = $loaded;
        $_SESSION[$sk]     = $loaded;
        $_SESSION[$st]     = time();

        return self::$cache;
    }

    /**
     * Récupère la valeur d'un paramètre.
     *
     * @param string $key      Clé du paramètre
     * @param mixed  $default  Valeur de repli (null = utilise les defaults internes)
     */
    public static function get(string $key, $default = null)
    {
        $settings = self::all();
        return $settings[$key] ?? ($default ?? self::$defaults[$key] ?? null);
    }

    /**
     * Vide le cache (statique + session).
     * À appeler après toute modification dans la table `settings`.
     */
    public static function clearCache(): void
    {
        self::$cache = null;
        unset($_SESSION['_dls_settings'], $_SESSION['_dls_settings_ts']);
    }

    /**
     * Sauvegarde un paramètre en BD et vide le cache.
     * Utilisable côté serveur (par ex. dans un script de migration).
     */
    public static function saveSetting(string $key, string $value): bool
    {
        // Vérifier que la clé est autorisée
        if (!array_key_exists($key, self::$defaults)) return false;

        try {
            $sql  = "INSERT INTO settings (`key`, `value`, updated_at)
                     VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()";
            self::getDB()->prepare($sql)->execute([$key, $value]);
            self::clearCache();
            return true;
        } catch (Throwable $e) {
            error_log('[AppSettings] saveSetting failed: ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // FORMATAGE — DATES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Formate une date MySQL selon le paramètre `date_format`.
     */
    public static function fmtDate(?string $date, string $fallback = '—'): string
    {
        if (!$date) return $fallback;
        try {
            return (new DateTime($date))->format(
                self::dateFormatToPhp(self::get('date_format', 'DD/MM/YYYY'))
            );
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * Formate une date + heure.
     */
    public static function fmtDateTime(?string $date, string $fallback = '—'): string
    {
        if (!$date) return $fallback;
        try {
            return (new DateTime($date))->format(
                self::dateFormatToPhp(self::get('date_format', 'DD/MM/YYYY')) . ' H:i'
            );
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** Convertit le format DLS en format PHP */
    private static function dateFormatToPhp(string $fmt): string
    {
        return match ($fmt) {
            'MM/DD/YYYY' => 'm/d/Y',
            'YYYY-MM-DD' => 'Y-m-d',
            default      => 'd/m/Y',
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // FORMATAGE — MONNAIE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Formate un montant avec la devise configurée.
     *
     * @param float $amount
     * @param bool  $compact  true → "1.2K FCFA" au lieu de "1 200 FCFA"
     */
    public static function fmtMoney(float $amount, bool $compact = false): string
    {
        $currency = self::get('currency', 'FCFA');
        if ($compact && $amount >= 1_000_000) {
            $val = round($amount / 1_000_000, 1) . 'M';
        } elseif ($compact && $amount >= 1_000) {
            $val = round($amount / 1_000, 1) . 'K';
        } else {
            $val = number_format($amount, 0, ',', ' ');
        }

        return match ($currency) {
            'EUR'   => $val . ' €',
            'USD'   => '$' . $val,
            'XOF'   => $val . ' XOF',
            default => $val . ' FCFA',
        };
    }

    /** Retourne uniquement le symbole de la devise */
    public static function currencySymbol(): string
    {
        return match (self::get('currency', 'FCFA')) {
            'EUR'   => '€',
            'USD'   => '$',
            'XOF'   => 'XOF',
            default => 'FCFA',
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // ACCESSEURS RAPIDES
    // ═══════════════════════════════════════════════════════════════════

    public static function lang(): string        { return self::get('language', 'fr') === 'en' ? 'en' : 'fr'; }
    public static function theme(): string       { return self::get('theme', 'dark'); }
    public static function perPage(): int        { return max(1, (int)self::get('pagination', '20')); }
    public static function bonusRule(): int      { return max(1, (int)self::get('bonus_rule', '5')); }
    public static function maxDownloads(): int   { return max(1, (int)self::get('max_downloads', '3')); }
    public static function defaultAccess(): string { return self::get('default_access', 'paid'); }
    public static function twoFaEnabled(): bool  { return self::get('two_fa', '0') === '1'; }
    public static function notifEnabled(): bool  { return self::get('notif_enabled', '1') === '1'; }
    public static function notifSales(): bool    { return self::notifEnabled() && self::get('notif_sales', '1') === '1'; }
    public static function notifBonus(): bool    { return self::notifEnabled() && self::get('notif_bonus', '1') === '1'; }
    public static function notifUsers(): bool    { return self::notifEnabled() && self::get('notif_users', '1') === '1'; }

    public static function primaryColor(): string
    {
        $color = self::get('primary_color', '#00d4ff');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#00d4ff';
    }

    public static function siteName(): string
    {
        return htmlspecialchars(self::get('site_name', 'Digital Library'), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Détermine si un livre est accessible gratuitement selon les paramètres.
     */
    public static function isBookFree(float $prix, float $note = 5.0): bool
    {
        if ($prix === 0.0)                    return true;
        if ($note <= 2.0)                     return true;
        if (self::defaultAccess() === 'free') return true;
        return false;
    }

    // ═══════════════════════════════════════════════════════════════════
    // LANGUE & TRADUCTIONS
    // ═══════════════════════════════════════════════════════════════════

    public static function t(string $key): string
    {
        static $translations = null;
        if ($translations === null) {
            $translations = [
                'fr' => [
                    'home'           => 'Accueil',
                    'explore'        => 'Explorer',
                    'categories'     => 'Catégories',
                    'trends'         => 'Tendances',
                    'ai_recs'        => 'Recommandations IA',
                    'login'          => 'Connexion',
                    'logout'         => 'Déconnexion',
                    'register'       => "S'inscrire",
                    'dashboard'      => 'Dashboard',
                    'settings'       => 'Paramètres',
                    'users'          => 'Utilisateurs',
                    'books'          => 'Livres',
                    'sales'          => 'Ventes',
                    'stats'          => 'Statistiques',
                    'notifications'  => 'Notifications',
                    'free'           => 'Gratuit',
                    'paid'           => 'Payant',
                    'available'      => 'Disponible',
                    'total'          => 'Total',
                    'per_page'       => 'par page',
                    'bonus_rule_txt' => 'achats pour 1 livre offert',
                    'save'           => 'Enregistrer',
                    'cancel'         => 'Annuler',
                    'delete'         => 'Supprimer',
                    'edit'           => 'Modifier',
                    'add'            => 'Ajouter',
                    'search'         => 'Rechercher',
                    'no_results'     => 'Aucun résultat',
                    'loading'        => 'Chargement...',
                    'error'          => 'Erreur',
                    'success'        => 'Succès',
                    'general'        => 'Général',
                    'appearance'     => 'Apparence',
                    'language'       => 'Langue',
                    'security'       => 'Sécurité',
                    'system'         => 'Système',
                    'library'        => 'Bibliothèque',
                ],
                'en' => [
                    'home'           => 'Home',
                    'explore'        => 'Explore',
                    'categories'     => 'Categories',
                    'trends'         => 'Trends',
                    'ai_recs'        => 'AI Recommendations',
                    'login'          => 'Login',
                    'logout'         => 'Logout',
                    'register'       => 'Sign Up',
                    'dashboard'      => 'Dashboard',
                    'settings'       => 'Settings',
                    'users'          => 'Users',
                    'books'          => 'Books',
                    'sales'          => 'Sales',
                    'stats'          => 'Statistics',
                    'notifications'  => 'Notifications',
                    'free'           => 'Free',
                    'paid'           => 'Paid',
                    'available'      => 'Available',
                    'total'          => 'Total',
                    'per_page'       => 'per page',
                    'bonus_rule_txt' => 'purchases for 1 free book',
                    'save'           => 'Save',
                    'cancel'         => 'Cancel',
                    'delete'         => 'Delete',
                    'edit'           => 'Edit',
                    'add'            => 'Add',
                    'search'         => 'Search',
                    'no_results'     => 'No results',
                    'loading'        => 'Loading...',
                    'error'          => 'Error',
                    'success'        => 'Success',
                    'general'        => 'General',
                    'appearance'     => 'Appearance',
                    'language'       => 'Language',
                    'security'       => 'Security',
                    'system'         => 'System',
                    'library'        => 'Library',
                ],
            ];
        }
        $lang = self::lang();
        return $translations[$lang][$key] ?? $translations['fr'][$key] ?? $key;
    }

    // ═══════════════════════════════════════════════════════════════════
    // INJECTION HTML (head)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Génère les CSS variables selon le thème + couleur principale.
     */
    public static function getCssVariables(): string
    {
        $color = self::primaryColor();
        $theme = self::theme();
        $rgb   = self::hexToRgb($color);

        $themeVars = match ($theme) {
            'light'  => "
    --bg-base:     #f4f6fb;
    --bg-surface:  #ffffff;
    --bg-card:     rgba(0,0,0,0.028);
    --bg-card-hov: rgba(0,0,0,0.055);
    --border:      rgba(0,0,0,0.08);
    --text-primary:   #0f172a;
    --text-secondary: rgba(15,23,42,0.6);
    --text-muted:     rgba(15,23,42,0.35);",
            'blue'   => "
    --bg-base:     #0a1628;
    --bg-surface:  #0d1f3c;
    --bg-card:     rgba(255,255,255,0.04);
    --bg-card-hov: rgba(255,255,255,0.07);
    --border:      rgba(56,139,253,0.18);
    --text-primary:   #e6f0ff;
    --text-secondary: rgba(230,240,255,0.55);
    --text-muted:     rgba(230,240,255,0.28);",
            'purple' => "
    --bg-base:     #0c0818;
    --bg-surface:  #130e24;
    --bg-card:     rgba(255,255,255,0.035);
    --bg-card-hov: rgba(255,255,255,0.065);
    --border:      rgba(167,139,250,0.16);
    --text-primary:   #f5f0ff;
    --text-secondary: rgba(245,240,255,0.55);
    --text-muted:     rgba(245,240,255,0.28);",
            'minimal'=> "
    --bg-base:     #000000;
    --bg-surface:  #0a0a0a;
    --bg-card:     rgba(255,255,255,0.025);
    --bg-card-hov: rgba(255,255,255,0.05);
    --border:      rgba(255,255,255,0.06);
    --text-primary:   #f8f8f8;
    --text-secondary: rgba(248,248,248,0.5);
    --text-muted:     rgba(248,248,248,0.24);",
            default  => "
    --bg-base:     #05080f;
    --bg-surface:  #0b1020;
    --bg-card:     rgba(255,255,255,0.032);
    --bg-card-hov: rgba(255,255,255,0.058);
    --border:      rgba(255,255,255,0.072);
    --text-primary:   #eef2ff;
    --text-secondary: rgba(238,242,255,0.56);
    --text-muted:     rgba(238,242,255,0.28);",
        };

        return ":root {{$themeVars}
    /* Couleur principale (settings) */
    --primary:        {$color};
    --primary-rgb:    {$rgb};
    --primary-glow:   rgba({$rgb},0.18);
    --primary-border: rgba({$rgb},0.38);
    --primary-bg:     rgba({$rgb},0.08);
    /* Alias historiques */
    --cyan:           {$color};
    --border-act:     rgba({$rgb},0.38);
    /* Constantes */
    --violet: #7c3aed;
    --neon:   #00ffaa;
    --amber:  #f59e0b;
    --rose:   #f43f5e;
    --gold:   #e8c97d;
}
[data-theme=\"{$theme}\"] {{$themeVars}
}";
    }

    /**
     * Retourne le bloc <style> CSS vars à mettre dans le <head>.
     * Usage : echo AppSettings::injectCss();
     */
    public static function injectCss(): string
    {
        return '<style id="dls-settings-vars">' . "\n" . self::getCssVariables() . "\n</style>\n";
    }

    /**
     * Retourne le bloc complet à injecter dans le <head>.
     * Inclut : title, meta lang, favicon, CSS vars, JS window.DLS_SETTINGS
     *
     * Usage : echo AppSettings::injectHead('Tableau de bord');
     */
    public static function injectHead(string $pageTitle = ''): string
    {
        // Variables locales pour éviter les problèmes d'interpolation
        $siteName  = self::siteName();
        $lang      = self::lang();
        $theme     = self::theme();
        $color     = self::primaryColor();
        $logo      = self::get('site_logo', '');
        $currency  = self::currencySymbol();
        $perPage   = self::perPage();
        $bonusRule = self::bonusRule();
        $notif     = self::get('notif_enabled', '1');
        $twoFa     = self::get('two_fa', '0');
        $title     = $pageTitle
            ? htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' — ' . $siteName
            : $siteName;
        $css       = self::getCssVariables();

        $faviconTag = '';
        if ($logo) {
            $faviconTag = '<link rel="icon" href="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        // Sérialiser proprement pour le JS
        $jsSettings = json_encode([
            'theme'        => $theme,
            'primaryColor' => $color,
            'lang'         => $lang,
            'siteName'     => self::get('site_name', 'Digital Library'),
            'currency'     => $currency,
            'perPage'      => $perPage,
            'bonusRule'    => $bonusRule,
            'notifEnabled' => (bool)(int)$notif,
            'twoFa'        => (bool)(int)$twoFa,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
<!-- ══ DLS AppSettings ══ -->
<meta http-equiv="content-language" content="{$lang}">
{$faviconTag}<title>{$title}</title>
<style id="dls-settings-vars">
{$css}
</style>
<script>window.DLS_SETTINGS={$jsSettings};</script>
<!-- ══ / DLS AppSettings ══ -->

HTML;
    }

    /**
     * Attributs HTML pour la balise <html>.
     * Usage : <html <?= AppSettings::htmlAttrs() ?>>
     */
    public static function htmlAttrs(): string
    {
        return 'lang="' . self::lang() . '" data-theme="' . self::theme() . '"';
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS INTERNES
    // ═══════════════════════════════════════════════════════════════════

    /** Convertit une couleur #rrggbb en "r,g,b" pour rgba() */
    private static function hexToRgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '0,212,255';
        return implode(',', [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ]);
    }

    /** Récupère la connexion PDO (getDB() ou global $pdo) */
    private static function getDB(): PDO
    {
        if (function_exists('getDB')) return getDB();
        global $pdo;
        if ($pdo instanceof PDO) return $pdo;
        throw new RuntimeException('[AppSettings] Aucune connexion BD disponible.');
    }
}

// ═══════════════════════════════════════════════════════════════════════
// FONCTIONS GLOBALES — Raccourcis rétro-compatibles
// ═══════════════════════════════════════════════════════════════════════

if (!function_exists('dls_date')) {
    function dls_date(?string $date, string $fallback = '—'): string {
        return AppSettings::fmtDate($date, $fallback);
    }
}
if (!function_exists('dls_money')) {
    function dls_money(float $amount, bool $compact = false): string {
        return AppSettings::fmtMoney($amount, $compact);
    }
}
if (!function_exists('dls_per_page')) {
    function dls_per_page(): int { return AppSettings::perPage(); }
}
if (!function_exists('dls_clear_settings_cache')) {
    function dls_clear_settings_cache(): void { AppSettings::clearCache(); }
}
if (!function_exists('__')) {
    function __(string $key): string { return AppSettings::t($key); }
}
if (!function_exists('dls_paginate')) {
    function dls_paginate(int $page = 1): array {
        $limit = AppSettings::perPage();
        return [$limit, ($page - 1) * $limit];
    }
}