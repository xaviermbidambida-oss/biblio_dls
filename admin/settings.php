<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — admin/settings.php  v3.0 PRODUCTION     ║
 * ║  Synchronisation temps réel → dashboard.php via BD          ║
 * ║  Colonnes unifiées : setting_key / setting_value            ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Session ──────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Connexion BD (chemin corrigé) ────────────────────────────────
if (!isset($pdo) || $pdo === null) {
    foreach ([
        __DIR__ . '/../includes/config.php',
        __DIR__ . '/../config/config.php',
        __DIR__ . '/../../includes/config.php',
        __DIR__ . '/../../config/config.php',
    ] as $cfgPath) {
        if (file_exists($cfgPath)) {
            require_once $cfgPath;
            break;
        }
    }
}

// Fallback PDO direct
if (!isset($pdo) || $pdo === null) {
    try {
        $pdo = new PDO(
            'mysql:host=' . (defined('DB_HOST') ? DB_HOST : 'localhost')
            . ';dbname=' . (defined('DB_NAME') ? DB_NAME : 'digital_library')
            . ';charset=utf8mb4',
            defined('DB_USER') ? DB_USER : 'root',
            defined('DB_PASS') ? DB_PASS : '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        error_log('[Settings] PDO fail: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── S'assurer que la table settings a les bonnes colonnes ────────
if ($pdo) {
    try {
        // Créer / compléter la table si nécessaire
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setting_key   VARCHAR(150) NOT NULL UNIQUE,
                setting_value TEXT         NULL,
                updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Valeurs par défaut garanties
        $defaults = [
            ['site_name',       'Digital Library Platform'],
            ['primary_color',   '#00d4ff'],
            ['theme',           'dark'],
            ['language',        'fr'],
            ['currency',        'FCFA'],
            ['date_format',     'DD/MM/YYYY'],
            ['pagination',      '20'],
            ['timezone',        'Africa/Douala'],
            ['notif_enabled',   '1'],
            ['notif_sales',     '1'],
            ['notif_bonus',     '1'],
            ['notif_users',     '1'],
            ['bonus_rule',      '5'],
            ['default_access',  'paid'],
            ['max_downloads',   '3'],
            ['two_fa',          '0'],
            ['site_logo',       ''],
        ];
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)"
        );
        foreach ($defaults as [$k, $v]) {
            $ins->execute([$k, $v]);
        }
    } catch (Throwable $e) {
        error_log('[Settings] init: ' . $e->getMessage());
    }
}

// ── CSRF Token ───────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// ── Guard admin ──────────────────────────────────────────────────
// Demo session (retirer en production)
if (!isset($_SESSION['user_id']) && $pdo) {
    try {
        $u = $pdo->query(
            "SELECT * FROM users WHERE role='admin' AND statut='actif' LIMIT 1"
        )->fetch();
        if ($u) {
            $_SESSION['user_id']     = $u['id'];
            $_SESSION['user_role']   = $u['role'];
            $_SESSION['user_email']  = $u['email'];
            $_SESSION['user_name']   = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));
            $_SESSION['user_prenom'] = $u['prenom'] ?? '';
        }
    } catch (Throwable $e) {}
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; $_SESSION['user_role'] = 'admin';
    $_SESSION['user_name'] = 'Admin Système'; $_SESSION['user_prenom'] = 'Admin';
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$username  = htmlspecialchars($_SESSION['user_name']   ?? 'Admin', ENT_QUOTES, 'UTF-8');
$firstName = htmlspecialchars($_SESSION['user_prenom'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$userEmail = htmlspecialchars($_SESSION['user_email']  ?? '',       ENT_QUOTES, 'UTF-8');
$avatar    = strtoupper(substr($username, 0, 1)) ?: 'A';
$csrfToken = csrfToken();

// ── Helpers ──────────────────────────────────────────────────────
function timeAgo(string $dt): string {
    if (!$dt) return '—';
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'à l\'instant';
    if ($d < 3600)   return (int)($d/60).' min';
    if ($d < 86400)  return (int)($d/3600).'h';
    if ($d < 604800) return (int)($d/86400).'j';
    return date('d/m/Y', strtotime($dt));
}

// ── Lecture settings (colonnes correctes) ────────────────────────
function getSettings(): array {
    global $pdo;
    $defaults = [
        'site_name'      => 'Digital Library Platform',
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
    if (!$pdo) return $defaults;
    try {
        // Utilise les VRAIES colonnes de la table
        $rows = $pdo->query(
            "SELECT setting_key, setting_value FROM settings"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_merge($defaults, $rows);
    } catch (Throwable $e) {
        error_log('[Settings] getSettings: ' . $e->getMessage());
        return $defaults;
    }
}

// ── Stats pour l'en-tête ─────────────────────────────────────────
function getStats(): array {
    global $pdo;
    $s = ['settings' => 0, 'last_mod' => null, 'users' => 0, 'books' => 0];
    if (!$pdo) return $s;
    try {
        $r = $pdo->query("SELECT COUNT(*) c, MAX(updated_at) m FROM settings")->fetch();
        $s['settings'] = (int)($r['c'] ?? 0);
        $s['last_mod']  = $r['m'] ?? null;
        $s['users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE statut='actif'")->fetchColumn();
        $s['books'] = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
    } catch (Throwable $e) {}
    return $s;
}

// ── Logs admin ───────────────────────────────────────────────────
function getAdminLogs(int $n = 12): array {
    global $pdo;
    if (!$pdo) return [];
    try {
        $st = $pdo->prepare("
            SELECT al.action, al.detail, al.ip, al.created_at,
                   CONCAT(COALESCE(u.prenom,''), ' ', COALESCE(u.nom,'')) AS user_name
            FROM admin_logs al
            LEFT JOIN users u ON u.id = al.user_id
            ORDER BY al.created_at DESC LIMIT ?
        ");
        $st->execute([$n]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ── Notifications ────────────────────────────────────────────────
function getNotifications(): array {
    global $pdo, $userId;
    if (!$pdo) return [];
    try {
        $st = $pdo->prepare(
            "SELECT id, type, titre, message, icon, bg, lu, created_at
             FROM notifications
             WHERE user_id IS NULL OR user_id = ?
             ORDER BY created_at DESC LIMIT 8"
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function getNotifCount(): int {
    global $pdo, $userId;
    if (!$pdo) return 0;
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id IS NULL OR user_id=?)"
        );
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// ── Écriture log + notification ──────────────────────────────────
function writeLog(string $action, string $detail = ''): void {
    global $pdo, $userId;
    if (!$pdo) return;
    try {
        $pdo->prepare(
            "INSERT INTO admin_logs (user_id, action, detail, ip, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([
            $userId, $action, $detail,
            $_SERVER['REMOTE_ADDR']    ?? '0.0.0.0',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        try {
            $pdo->prepare(
                "INSERT INTO admin_logs (user_id, action, ip, created_at) VALUES (?, ?, ?, NOW())"
            )->execute([$userId, $action, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
        } catch (Throwable $e2) {}
    }
}

function writeNotif(string $titre, string $msg, string $type = 'info', string $icon = '⚙️'): void {
    global $pdo;
    if (!$pdo) return;
    $bgMap = [
        'info'    => 'rgba(0,212,255,.1)',
        'success' => 'rgba(0,255,170,.1)',
        'warning' => 'rgba(245,158,11,.1)',
        'danger'  => 'rgba(244,63,94,.1)',
    ];
    try {
        $pdo->prepare(
            "INSERT INTO notifications (type, titre, message, icon, bg, lu, created_at)
             VALUES (?,?,?,?,?,0,NOW())"
        )->execute([$type, $titre, $msg, $icon, $bgMap[$type] ?? $bgMap['info']]);
    } catch (Throwable $e) {}
}

// ════════════════════════════════════════════════════════════════
// ── AJAX — toutes les actions POST ──────────────────────────────
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF check
    $inputCsrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($csrfToken, (string)$inputCsrf)) {
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide. Rechargez la page.']);
        exit;
    }

    $action = trim($_POST['action'] ?? 'save_setting');

    // ── Sauvegarder un paramètre ─────────────────────────────────
    if ($action === 'save_setting') {
        $key   = trim($_POST['key']   ?? '');
        $value = trim($_POST['value'] ?? '');

        $allowed = [
            'site_name', 'site_logo', 'primary_color',
            'theme', 'language', 'currency', 'date_format',
            'pagination', 'timezone',
            'notif_enabled', 'notif_sales', 'notif_bonus', 'notif_users',
            'bonus_rule', 'default_access', 'max_downloads', 'two_fa',
        ];

        if (!in_array($key, $allowed, true)) {
            echo json_encode(['success' => false, 'error' => "Clé '{$key}' non autorisée."]);
            exit;
        }

        // Validation
        $errors = [];
        switch ($key) {
            case 'pagination':
                if (!in_array($value, ['10','20','50','100'], true))
                    $errors[] = 'Pagination invalide.';
                break;
            case 'bonus_rule': case 'max_downloads':
                if (!ctype_digit($value) || (int)$value < 1 || (int)$value > 1000)
                    $errors[] = "Valeur numérique invalide pour {$key}.";
                break;
            case 'notif_enabled': case 'notif_sales': case 'notif_bonus':
            case 'notif_users':   case 'two_fa':
                if (!in_array($value, ['0','1'], true))
                    $errors[] = 'Valeur booléenne invalide.';
                break;
            case 'primary_color':
                if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $value))
                    $errors[] = 'Couleur HEX invalide.';
                break;
            case 'theme':
                if (!in_array($value, ['dark','light','blue','purple','minimal'], true))
                    $errors[] = 'Thème inconnu.';
                break;
            case 'language':
                if (!in_array($value, ['fr','en'], true))
                    $errors[] = 'Langue non supportée.';
                break;
            case 'currency':
                if (!in_array($value, ['FCFA','EUR','USD','XOF','GBP'], true))
                    $errors[] = 'Devise invalide.';
                break;
            case 'default_access':
                if (!in_array($value, ['paid','free'], true))
                    $errors[] = 'Accès invalide.';
                break;
            case 'site_name':
                if (strlen($value) > 120)
                    $errors[] = 'Nom trop long (max 120 car.).';
                break;
        }

        if ($errors) {
            echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
            exit;
        }

        if ($pdo) {
            try {
                // Ancienne valeur pour le log
                $old = null;
                try {
                    $st = $pdo->prepare(
                        "SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1"
                    );
                    $st->execute([$key]);
                    $old = $st->fetchColumn();
                } catch (Throwable $e) {}

                // ── UPSERT — synchronise la BD (source de vérité du dashboard) ──
                $pdo->prepare("
                    INSERT INTO settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        setting_value = VALUES(setting_value),
                        updated_at    = NOW()
                ")->execute([$key, $value]);

                // Log
                writeLog('setting_changed', "{$key}: {$old} → {$value}");

                // Notification pour les clés importantes
                $notifKeys = ['theme','primary_color','two_fa','notif_enabled','site_name','language'];
                if (in_array($key, $notifKeys, true)) {
                    $labels = [
                        'theme'         => 'Thème modifié',
                        'primary_color' => 'Couleur principale modifiée',
                        'two_fa'        => $value === '1' ? '2FA activé' : '2FA désactivé',
                        'notif_enabled' => $value === '1' ? 'Notifications activées' : 'Notifications désactivées',
                        'site_name'     => 'Nom du site modifié',
                        'language'      => 'Langue modifiée',
                    ];
                    $icons = [
                        'theme' => '🌓','primary_color' => '🎨','two_fa' => '🔐',
                        'notif_enabled' => '🔔','site_name' => '✏️','language' => '🌍',
                    ];
                    writeNotif(
                        $labels[$key] ?? 'Paramètre modifié',
                        "Précédent : {$old} → Nouveau : {$value}",
                        'info',
                        $icons[$key] ?? '⚙️'
                    );
                }

                // Réponse avec TOUTES les valeurs nécessaires au dashboard
                // pour mise à jour immédiate côté client
                echo json_encode([
                    'success'    => true,
                    'key'        => $key,
                    'value'      => $value,
                    'old'        => $old,
                    // Payload complet pour que dashboard.php puisse
                    // tout appliquer sans recharger la page
                    'apply' => [
                        'css_var'   => $key === 'primary_color' ? $value : null,
                        'theme'     => $key === 'theme' ? $value : null,
                        'title'     => $key === 'site_name' ? $value : null,
                        'reload'    => in_array($key, ['language'], true),
                    ],
                ]);
            } catch (Throwable $e) {
                error_log('[Settings] save: ' . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Erreur BD : ' . $e->getMessage()]);
            }
        } else {
            // Démo sans BD : on stocke en session pour cohérence
            $_SESSION['demo_settings'][$key] = $value;
            echo json_encode(['success' => true, 'key' => $key, 'value' => $value, 'demo' => true]);
        }
        exit;
    }

    // ── Lire TOUS les settings (endpoint de synchronisation) ─────
    // Dashboard.php appelle cet endpoint toutes les 5 secondes
    if ($action === 'get_all_settings') {
        $s = getSettings();
        echo json_encode([
            'success'  => true,
            'settings' => $s,
            'ts'       => time(), // timestamp pour détecter les changements
        ]);
        exit;
    }

    // ── Changer mot de passe ──────────────────────────────────────
    if ($action === 'change_password') {
        $cur = $_POST['current_password'] ?? '';
        $nw  = $_POST['new_password']     ?? '';
        $con = $_POST['confirm_password'] ?? '';

        if (!$cur || !$nw || !$con) {
            echo json_encode(['success' => false, 'error' => 'Tous les champs sont requis.']); exit;
        }
        if ($nw !== $con) {
            echo json_encode(['success' => false, 'error' => 'Les mots de passe ne correspondent pas.']); exit;
        }
        if (strlen($nw) < 8 || !preg_match('/[A-Z]/', $nw) || !preg_match('/[0-9]/', $nw)) {
            echo json_encode(['success' => false, 'error' => 'Min. 8 car., une majuscule et un chiffre.']); exit;
        }

        if (!$pdo) {
            echo json_encode(['success' => true, 'message' => 'Mot de passe modifié (démo).']); exit;
        }

        try {
            $st = $pdo->prepare("SELECT password FROM users WHERE id=?");
            $st->execute([$userId]);
            $u = $st->fetch();

            if (!$u || !password_verify($cur, $u['password'])) {
                echo json_encode(['success' => false, 'error' => 'Mot de passe actuel incorrect.']); exit;
            }

            $hash = password_hash($nw, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $userId]);
            writeLog('password_changed', 'Mot de passe admin modifié');
            writeNotif('Mot de passe modifié', 'Le mot de passe admin a été changé.', 'warning', '🔑');
            echo json_encode(['success' => true, 'message' => 'Mot de passe modifié avec succès.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Erreur BD : ' . $e->getMessage()]);
        }
        exit;
    }

    // ── Marquer notifications lues ────────────────────────────────
    if ($action === 'mark_notif_read') {
        $nid = (int)($_POST['notif_id'] ?? 0);
        if ($pdo) {
            try {
                if ($nid > 0) {
                    $pdo->prepare("UPDATE notifications SET lu=1 WHERE id=?")->execute([$nid]);
                } else {
                    $pdo->prepare(
                        "UPDATE notifications SET lu=1 WHERE user_id IS NULL OR user_id=?"
                    )->execute([$userId]);
                }
                echo json_encode(['success' => true, 'count' => getNotifCount()]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => true, 'count' => 0]);
        }
        exit;
    }

    // ── Stats polling ─────────────────────────────────────────────
    if ($action === 'get_stats') {
        echo json_encode([
            'success'     => true,
            'stats'       => getStats(),
            'notif_count' => getNotifCount(),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Action inconnue.']);
    exit;
}

// ── Données page ─────────────────────────────────────────────────
$settings      = getSettings();
$statsData     = getStats();
$adminLogs     = getAdminLogs(12);
$notifications = getNotifications();
$notifCount    = getNotifCount();
$primaryColor  = htmlspecialchars($settings['primary_color'], ENT_QUOTES, 'UTF-8');
$currentTheme  = htmlspecialchars($settings['theme'],          ENT_QUOTES, 'UTF-8');
$lang          = $settings['language'] === 'en' ? 'en' : 'fr';

$t = $lang === 'en' ? [
    'settings'      => 'Settings',      'general'       => 'General',
    'appearance'    => 'Appearance',    'language_tab'  => 'Language',
    'security'      => 'Security',      'system'        => 'System',
    'notifications' => 'Notifications', 'library'       => 'Library',
    'save'          => 'Save',          'dashboard'     => 'Dashboard',
    'logs'          => 'Admin Logs',
] : [
    'settings'      => 'Paramètres',    'general'       => 'Général',
    'appearance'    => 'Apparence',     'language_tab'  => 'Langue',
    'security'      => 'Sécurité',      'system'        => 'Système',
    'notifications' => 'Notifications', 'library'       => 'Bibliothèque',
    'save'          => 'Enregistrer',   'dashboard'     => 'Dashboard',
    'logs'          => 'Journaux admin',
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" data-theme="<?= $currentTheme ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t['settings']) ?> — <?= htmlspecialchars($settings['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ═══════════════════════════════════════════════════════
   VARIABLES — injectées depuis la BD
═══════════════════════════════════════════════════════ */
:root {
  --primary: <?= $primaryColor ?>;
  --bg-base:      #05080f;
  --bg-surface:   #0b1020;
  --bg-card:      rgba(255,255,255,.032);
  --bg-card-hov:  rgba(255,255,255,.055);
  --border:       rgba(255,255,255,.07);
  --border-act:   color-mix(in srgb, var(--primary) 50%, transparent);
  --text-1:       #eef2ff;
  --text-2:       rgba(238,242,255,.56);
  --text-3:       rgba(238,242,255,.28);
  --neon:         #00ffaa;
  --amber:        #f59e0b;
  --rose:         #f43f5e;
  --violet:       #7c3aed;
  --sidebar-w:    256px;
  --topbar-h:     62px;
  --r-sm: 8px; --r-md: 13px; --r-lg: 18px;
  --shadow-sm:    0 2px 12px rgba(0,0,0,.28);
  --shadow-md:    0 8px 32px rgba(0,0,0,.38);
  --shadow-lg:    0 20px 60px rgba(0,0,0,.52);
  --glow:         0 0 24px color-mix(in srgb, var(--primary) 30%, transparent);
  --tr:           all .2s cubic-bezier(.4,0,.2,1);
}

/* ── Thèmes (synchronisés avec dashboard) ── */
[data-theme="light"] {
  --bg-base:#f1f5fb; --bg-surface:#ffffff;
  --bg-card:rgba(0,0,0,.028); --bg-card-hov:rgba(0,0,0,.052);
  --border:rgba(0,0,0,.08);
  --text-1:#0f172a; --text-2:rgba(15,23,42,.6); --text-3:rgba(15,23,42,.35);
}
[data-theme="blue"] {
  --bg-base:#06111f; --bg-surface:#0a1a30;
  --bg-card:rgba(56,139,253,.06); --bg-card-hov:rgba(56,139,253,.1);
  --border:rgba(56,139,253,.14);
  --text-1:#ddeeff; --text-2:rgba(221,238,255,.55); --text-3:rgba(221,238,255,.28);
  --primary:#388bfd;
}
[data-theme="purple"] {
  --bg-base:#0b0717; --bg-surface:#130e24;
  --bg-card:rgba(167,139,250,.05); --bg-card-hov:rgba(167,139,250,.09);
  --border:rgba(167,139,250,.14);
  --text-1:#f5f0ff; --text-2:rgba(245,240,255,.55); --text-3:rgba(245,240,255,.28);
  --primary:#a78bfa;
}
[data-theme="minimal"] {
  --bg-base:#000; --bg-surface:#0a0a0a;
  --bg-card:rgba(255,255,255,.025); --bg-card-hov:rgba(255,255,255,.048);
  --border:rgba(255,255,255,.06);
  --text-1:#f8f8f8; --text-2:rgba(248,248,248,.5); --text-3:rgba(248,248,248,.24);
  --primary:#e0e0e0;
}

*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body {
  font-family:'DM Sans',sans-serif;
  background:var(--bg-base); color:var(--text-1);
  min-height:100vh; overflow-x:hidden;
  transition:background .35s ease, color .35s ease;
}
::-webkit-scrollbar { width:3px; height:3px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:4px; }

/* ── LAYOUT ── */
.app { display:flex; min-height:100vh; }

/* ── SIDEBAR ── */
#sidebar {
  position:fixed; top:0; left:0; bottom:0; width:var(--sidebar-w);
  background:var(--bg-surface); border-right:1px solid var(--border);
  display:flex; flex-direction:column; z-index:200;
  transition:transform .3s ease; overflow:hidden;
}
.sb-brand {
  height:var(--topbar-h); display:flex; align-items:center; gap:11px;
  padding:0 20px; border-bottom:1px solid var(--border); flex-shrink:0;
}
.sb-brand-icon {
  width:36px; height:36px;
  background:linear-gradient(135deg, var(--primary), var(--violet));
  border-radius:11px; display:flex; align-items:center; justify-content:center;
  font-size:1rem; flex-shrink:0; box-shadow:var(--glow);
}
.sb-brand-name { font-family:'Syne',sans-serif; font-weight:800; font-size:.92rem; letter-spacing:-.3px; white-space:nowrap; }
.sb-brand-name em { color:var(--primary); font-style:normal; }
.sb-section { font-family:'Space Mono',monospace; font-size:.58rem; letter-spacing:.12em; text-transform:uppercase; color:var(--text-3); padding:16px 20px 5px; }
.sb-link {
  display:flex; align-items:center; gap:11px; padding:9px 14px; margin:2px 8px;
  border-radius:var(--r-sm); text-decoration:none; color:var(--text-2);
  font-size:.83rem; font-weight:500; transition:var(--tr); position:relative;
  border:1px solid transparent;
}
.sb-link:hover { color:var(--text-1); background:var(--bg-card-hov); }
.sb-link.active {
  color:var(--primary);
  background:color-mix(in srgb, var(--primary) 8%, transparent);
  border-color:color-mix(in srgb, var(--primary) 15%, transparent);
}
.sb-link.active::before {
  content:''; position:absolute; left:-1px; top:50%; transform:translateY(-50%);
  width:3px; height:20px; background:var(--primary); border-radius:0 3px 3px 0;
  box-shadow:0 0 10px var(--primary);
}
.sb-icon { font-size:1rem; width:20px; text-align:center; flex-shrink:0; }
.sb-badge { margin-left:auto; font-size:.58rem; font-family:'Space Mono',monospace; padding:1px 6px; border-radius:100px; background:var(--rose); color:#fff; font-weight:700; }
.sb-footer { margin-top:auto; padding:12px; border-top:1px solid var(--border); }
.sb-user { display:flex; align-items:center; gap:10px; padding:9px 10px; border-radius:var(--r-sm); background:var(--bg-card); border:1px solid var(--border); }
.sb-avatar { width:34px; height:34px; border-radius:10px; flex-shrink:0; background:linear-gradient(135deg, var(--primary), var(--violet)); display:flex; align-items:center; justify-content:center; font-family:'Syne',sans-serif; font-weight:800; font-size:.8rem; color:#fff; }
.sb-user-name { font-size:.8rem; font-weight:600; }
.sb-user-role { font-size:.6rem; color:var(--primary); font-family:'Space Mono',monospace; }

/* ── MAIN ── */
.main { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh; }

/* ── TOPBAR ── */
.topbar {
  height:var(--topbar-h); position:sticky; top:0; z-index:100;
  background:color-mix(in srgb, var(--bg-base) 88%, transparent);
  backdrop-filter:blur(20px); border-bottom:1px solid var(--border);
  display:flex; align-items:center; gap:.8rem; padding:0 1.5rem;
}
.tb-ham { display:none; background:none; border:none; color:var(--text-1); font-size:1.3rem; cursor:pointer; width:34px; height:34px; border-radius:var(--r-sm); align-items:center; justify-content:center; }
.tb-bc { display:flex; align-items:center; gap:7px; font-size:.78rem; color:var(--text-2); }
.bc-sep { opacity:.3; }
.bc-curr { font-family:'Syne',sans-serif; font-weight:700; color:var(--text-1); }
.tb-space { flex:1; }
.tb-actions { display:flex; align-items:center; gap:6px; }
.tb-btn {
  width:34px; height:34px; border-radius:var(--r-sm); background:var(--bg-card);
  border:1px solid var(--border); color:var(--text-2); display:flex; align-items:center;
  justify-content:center; cursor:pointer; font-size:.95rem; transition:var(--tr);
  position:relative; text-decoration:none;
}
.tb-btn:hover { color:var(--text-1); background:var(--bg-card-hov); }
.notif-badge {
  position:absolute; top:-3px; right:-3px; min-width:15px; height:15px; padding:0 3px;
  background:var(--rose); border-radius:50%; font-size:.52rem; font-weight:700;
  font-family:'Space Mono',monospace; display:flex; align-items:center;
  justify-content:center; border:2px solid var(--bg-base); color:#fff;
}
.tb-user { display:flex; align-items:center; gap:8px; padding:5px 10px; border-radius:var(--r-sm); background:var(--bg-card); border:1px solid var(--border); text-decoration:none; transition:var(--tr); }
.tb-user:hover { border-color:var(--border-act); }
.tu-av { width:26px; height:26px; border-radius:8px; background:linear-gradient(135deg, var(--primary), var(--violet)); display:flex; align-items:center; justify-content:center; font-family:'Syne',sans-serif; font-weight:800; font-size:.7rem; color:#fff; }
.tu-name { font-size:.75rem; font-weight:600; }
.tu-role { font-size:.6rem; color:var(--primary); font-family:'Space Mono',monospace; }

/* ── DB STATUS ── */
.db-status { display:flex; align-items:center; gap:5px; font-size:.65rem; font-family:'Space Mono',monospace; padding:3px 10px; border-radius:100px; }
.db-status.ok { background:rgba(0,255,170,.1); color:var(--neon); border:1px solid rgba(0,255,170,.2); }
.db-status.err { background:rgba(244,63,94,.1); color:var(--rose); border:1px solid rgba(244,63,94,.2); }
.db-dot { width:6px; height:6px; border-radius:50%; }
.db-dot.ok  { background:var(--neon); box-shadow:0 0 6px var(--neon); animation:dbPulse 2s ease infinite; }
.db-dot.err { background:var(--rose); }
@keyframes dbPulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── PAGE ── */
.page { flex:1; padding:2rem 2rem 5rem; max-width:1100px; width:100%; margin:0 auto; }

.page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:2rem; padding-bottom:1.5rem; border-bottom:1px solid var(--border); animation:fadeUp .4s ease both; }
.ph-eyebrow { font-family:'Space Mono',monospace; font-size:.62rem; letter-spacing:.1em; text-transform:uppercase; color:var(--primary); margin-bottom:5px; display:flex; align-items:center; gap:6px; }
.ph-eyebrow::before { content:''; width:24px; height:2px; background:var(--primary); border-radius:2px; }
.ph-title { font-family:'Syne',sans-serif; font-size:1.8rem; font-weight:800; letter-spacing:-.5px; }
.ph-sub { font-size:.83rem; color:var(--text-2); margin-top:4px; }
.ph-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:10px; }
.ph-pill { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:100px; font-size:.62rem; font-family:'Space Mono',monospace; text-transform:uppercase; letter-spacing:.04em; }
.ph-pill.blue { background:color-mix(in srgb, var(--primary) 12%, transparent); color:var(--primary); border:1px solid color-mix(in srgb, var(--primary) 20%, transparent); }
.ph-pill.green { background:rgba(0,255,170,.1); color:var(--neon); border:1px solid rgba(0,255,170,.2); }
.pulse-dot { width:7px; height:7px; border-radius:50%; background:var(--neon); box-shadow:0 0 8px var(--neon); animation:pulse 2s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── STATS MINI ── */
.stats-mini { display:grid; grid-template-columns:repeat(4,1fr); gap:.8rem; margin-bottom:1.8rem; }
.stat-mini { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-md); padding:1rem 1.2rem; position:relative; overflow:hidden; transition:transform .22s; animation:fadeUp .5s ease both; }
.stat-mini::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; background:linear-gradient(90deg,var(--c1,var(--primary)),var(--c2,var(--violet))); opacity:0; transition:opacity .3s; }
.stat-mini:hover { transform:translateY(-3px); }
.stat-mini:hover::before { opacity:1; }
.stat-mini:nth-child(1){--c1:var(--primary);--c2:var(--violet);animation-delay:.05s}
.stat-mini:nth-child(2){--c1:var(--violet);--c2:var(--rose);animation-delay:.1s}
.stat-mini:nth-child(3){--c1:var(--neon);--c2:var(--primary);animation-delay:.15s}
.stat-mini:nth-child(4){--c1:var(--amber);--c2:var(--rose);animation-delay:.2s}
.sm-icon { font-size:1.3rem; margin-bottom:.6rem; }
.sm-val { font-family:'Syne',sans-serif; font-size:1.5rem; font-weight:800; letter-spacing:-.3px; background:linear-gradient(135deg,var(--c1,var(--text-1)),var(--c2,var(--text-2))); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.sm-label { font-size:.72rem; color:var(--text-2); margin-top:3px; }

/* ── SETTINGS SECTIONS ── */
.s-section { display:none; animation:fadeUp .35s ease both; }
.s-section.active { display:block; }
.s-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; margin-bottom:1.1rem; transition:border-color .2s; }
.s-card:hover { border-color:rgba(255,255,255,.1); }
.s-card-head { padding:1rem 1.4rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:11px; }
.s-card-ico { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }
.s-card-title { font-family:'Syne',sans-serif; font-weight:700; font-size:.9rem; }
.s-card-desc { font-size:.72rem; color:var(--text-3); margin-top:1px; }
.s-card-body { padding:1.2rem 1.4rem; }
.s-card-foot { padding:.85rem 1.4rem; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:flex-end; gap:8px; background:color-mix(in srgb, var(--bg-surface) 40%, transparent); }
.s-row { display:flex; align-items:center; justify-content:space-between; gap:1.5rem; padding:.9rem 0; border-bottom:1px solid rgba(255,255,255,.038); }
.s-row:last-child { border-bottom:none; padding-bottom:0; }
.s-row:first-child { padding-top:0; }
.s-label { font-size:.84rem; font-weight:500; }
.s-desc { font-size:.72rem; color:var(--text-3); margin-top:2px; line-height:1.4; }
.s-ctrl { flex-shrink:0; display:flex; align-items:center; gap:8px; }
.s-input, .s-select {
  background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-sm);
  padding:8px 12px; color:var(--text-1); font-size:.82rem; font-family:'DM Sans',sans-serif;
  outline:none; transition:border-color .2s, box-shadow .2s;
}
.s-input:focus, .s-select:focus { border-color:var(--border-act); box-shadow:0 0 0 3px color-mix(in srgb, var(--primary) 10%, transparent); }
.s-input { min-width:200px; }
.s-input-sm { min-width:90px; }
.s-select { min-width:180px; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='rgba(255,255,255,.35)' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; padding-right:32px; cursor:pointer; }

/* ── TOGGLE ── */
.toggle { position:relative; display:inline-block; width:48px; height:26px; flex-shrink:0; }
.toggle input { opacity:0; width:0; height:0; position:absolute; }
.toggle-track { position:absolute; inset:0; background:rgba(255,255,255,.1); border:1px solid var(--border); border-radius:100px; cursor:pointer; transition:background .25s, border-color .25s; }
.toggle-thumb { position:absolute; width:18px; height:18px; left:3px; top:3px; border-radius:50%; background:var(--text-3); pointer-events:none; transition:transform .25s cubic-bezier(.34,1.56,.64,1), background .25s; box-shadow:0 2px 6px rgba(0,0,0,.3); }
.toggle input:checked ~ .toggle-track { background:color-mix(in srgb, var(--primary) 20%, transparent); border-color:color-mix(in srgb, var(--primary) 40%, transparent); }
.toggle input:checked ~ .toggle-thumb { transform:translateX(22px); background:var(--primary); box-shadow:0 2px 8px color-mix(in srgb, var(--primary) 50%, transparent); }

/* ── THEME GRID ── */
.theme-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:.7rem; }
.theme-card { border:2px solid var(--border); border-radius:var(--r-md); padding:.8rem .6rem; text-align:center; cursor:pointer; transition:var(--tr); position:relative; overflow:hidden; }
.theme-card:hover { border-color:rgba(255,255,255,.2); transform:translateY(-2px); }
.theme-card.selected { border-color:var(--primary); box-shadow:0 0 18px color-mix(in srgb, var(--primary) 25%, transparent); }
.theme-card.selected::after { content:'✓'; position:absolute; top:5px; right:7px; font-size:.6rem; color:var(--primary); font-weight:700; }
.theme-preview { width:100%; height:40px; border-radius:6px; margin-bottom:7px; overflow:hidden; }
.tp-bar { height:9px; border-bottom:1px solid rgba(255,255,255,.06); }
.tp-body { display:flex; gap:2px; padding:2px; height:31px; }
.tp-sb { width:11px; border-radius:2px; margin-right:2px; }
.tp-cards { display:flex; gap:2px; flex:1; }
.tp-c { flex:1; border-radius:2px; }
.theme-name { font-family:'Syne',sans-serif; font-size:.68rem; font-weight:700; }
.theme-sub { font-size:.55rem; color:var(--text-3); font-family:'Space Mono',monospace; margin-top:1px; }

/* ── COLOR PICKER ── */
.color-wrap { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.color-swatch { width:36px; height:36px; border-radius:9px; border:2px solid var(--border); overflow:hidden; cursor:pointer; transition:transform .2s; flex-shrink:0; }
.color-swatch:hover { transform:scale(1.1); }
.color-swatch input[type="color"] { width:150%; height:150%; border:none; cursor:pointer; background:none; transform:translate(-15%,-15%); }
.presets { display:flex; gap:5px; flex-wrap:wrap; }
.preset { width:22px; height:22px; border-radius:50%; cursor:pointer; border:2px solid transparent; transition:transform .18s; flex-shrink:0; }
.preset:hover { transform:scale(1.15); }
.preset.active { border-color:var(--text-1); box-shadow:0 0 0 3px rgba(255,255,255,.15); }

/* ── LANG CARDS ── */
.lang-grid { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; }
.lang-card { border:2px solid var(--border); border-radius:var(--r-md); padding:1rem; cursor:pointer; transition:var(--tr); display:flex; align-items:center; gap:12px; }
.lang-card:hover { border-color:rgba(255,255,255,.18); background:var(--bg-card-hov); }
.lang-card.selected { border-color:var(--primary); background:color-mix(in srgb, var(--primary) 6%, transparent); }
.lang-flag { font-size:1.8rem; flex-shrink:0; }
.lang-name { font-family:'Syne',sans-serif; font-weight:700; font-size:.88rem; }
.lang-native { font-size:.68rem; color:var(--text-3); font-family:'Space Mono',monospace; }

/* ── BUTTONS ── */
.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 15px; border-radius:var(--r-sm); font-family:'Syne',sans-serif; font-size:.78rem; font-weight:700; cursor:pointer; transition:var(--tr); text-decoration:none; border:none; white-space:nowrap; }
.btn-sm { padding:5px 11px; font-size:.72rem; }
.btn-primary { background:linear-gradient(135deg, var(--primary), var(--violet)); color:#fff; box-shadow:0 4px 14px color-mix(in srgb, var(--primary) 28%, transparent); }
.btn-primary:hover { opacity:.87; transform:translateY(-1px); }
.btn-ghost { background:var(--bg-card); border:1px solid var(--border); color:var(--text-2); }
.btn-ghost:hover { color:var(--text-1); background:var(--bg-card-hov); }
.btn-danger { background:rgba(244,63,94,.1); border:1px solid rgba(244,63,94,.22); color:var(--rose); }
.btn-danger:hover { background:rgba(244,63,94,.18); }
.btn[disabled], .btn.loading { opacity:.6; pointer-events:none; }

/* ── PASSWORD ── */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:.9rem; }
.form-group { display:flex; flex-direction:column; gap:5px; }
.form-group.full { grid-column:1/-1; }
.form-label { font-family:'Space Mono',monospace; font-size:.62rem; letter-spacing:.06em; text-transform:uppercase; color:var(--text-3); }
.input-wrap { position:relative; }
.input-wrap .s-input { width:100%; padding-right:38px; }
.input-eye { position:absolute; right:11px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:.85rem; cursor:pointer; transition:color .18s; }
.input-eye:hover { color:var(--text-2); }
.pwd-meter { height:4px; border-radius:100px; background:rgba(255,255,255,.07); overflow:hidden; margin-top:5px; }
.pwd-bar { height:100%; border-radius:100px; width:0; transition:width .3s, background .3s; }
.pwd-hint { font-size:.63rem; font-family:'Space Mono',monospace; color:var(--text-3); margin-top:3px; }

/* ── 2FA BOX ── */
.tfa-box { background:linear-gradient(135deg, color-mix(in srgb, var(--primary) 5%, transparent), rgba(124,58,237,.05)); border:1px solid color-mix(in srgb, var(--primary) 15%, transparent); border-radius:var(--r-md); padding:1.3rem; display:flex; align-items:center; gap:14px; }
.tfa-ico { font-size:2rem; flex-shrink:0; }
.tfa-title { font-family:'Syne',sans-serif; font-weight:700; font-size:.9rem; }
.tfa-desc { font-size:.73rem; color:var(--text-2); margin-top:3px; line-height:1.45; }

/* ── CHIPS ── */
.chip { display:inline-flex; align-items:center; gap:3px; font-size:.6rem; font-family:'Space Mono',monospace; padding:2px 8px; border-radius:100px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
.chip-ok     { background:rgba(0,255,170,.1);  color:var(--neon);  border:1px solid rgba(0,255,170,.2); }
.chip-info   { background:rgba(0,212,255,.1);  color:var(--primary); border:1px solid rgba(0,212,255,.2); }
.chip-warn   { background:rgba(245,158,11,.1); color:var(--amber); border:1px solid rgba(245,158,11,.2); }
.chip-danger { background:rgba(244,63,94,.1);  color:var(--rose);  border:1px solid rgba(244,63,94,.2); }
.chip-muted  { background:rgba(255,255,255,.05); color:var(--text-3); border:1px solid var(--border); }

/* ── LOGS ── */
.log-list { display:flex; flex-direction:column; }
.log-item { display:flex; align-items:center; gap:12px; padding:9px 0; border-bottom:1px solid rgba(255,255,255,.04); }
.log-item:last-child { border-bottom:none; }
.log-ico { width:32px; height:32px; border-radius:9px; background:color-mix(in srgb, var(--primary) 10%, transparent); display:flex; align-items:center; justify-content:center; font-size:.85rem; flex-shrink:0; }
.log-action { font-size:.8rem; font-weight:600; }
.log-meta { font-size:.63rem; color:var(--text-3); font-family:'Space Mono',monospace; margin-top:2px; }
.log-ip { margin-left:auto; font-family:'Space Mono',monospace; font-size:.62rem; padding:2px 8px; background:var(--bg-card); border:1px solid var(--border); border-radius:100px; color:var(--text-3); flex-shrink:0; }

/* ── NOTIF PANEL ── */
#notif-panel { position:fixed; top:var(--topbar-h); right:1rem; width:310px; background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-lg); z-index:500; transform:translateY(-10px) scale(.97); opacity:0; pointer-events:none; transition:all .22s cubic-bezier(.34,1.56,.64,1); overflow:hidden; }
#notif-panel.open { transform:translateY(6px) scale(1); opacity:1; pointer-events:all; }
.np-head { padding:.85rem 1.1rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; font-family:'Syne',sans-serif; font-weight:700; font-size:.85rem; }
.np-list { max-height:300px; overflow-y:auto; }
.np-item { display:flex; gap:10px; padding:10px 1.1rem; border-bottom:1px solid rgba(255,255,255,.04); font-size:.77rem; cursor:pointer; transition:background .12s; }
.np-item:hover { background:var(--bg-card-hov); }
.np-item.unread { background:color-mix(in srgb, var(--primary) 4%, transparent); }
.np-ico { width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.82rem; flex-shrink:0; }
.np-foot { padding:.7rem 1.1rem; border-top:1px solid var(--border); display:flex; justify-content:space-between; }

/* ── SAVE INDICATOR ── */
#save-indicator {
  position:fixed; bottom:1.5rem; right:1.5rem; z-index:999;
  background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-lg);
  padding:.7rem 1.2rem; display:flex; align-items:center; gap:9px;
  box-shadow:var(--shadow-lg); font-size:.78rem;
  transform:translateY(80px); opacity:0;
  transition:transform .35s cubic-bezier(.34,1.56,.64,1), opacity .35s ease;
  pointer-events:none;
}
#save-indicator.show { transform:translateY(0); opacity:1; }
.si-dot { width:8px; height:8px; border-radius:50%; background:var(--neon); flex-shrink:0; }
.si-label { font-family:'Syne',sans-serif; font-weight:700; color:var(--neon); }

/* ── TOAST ── */
#toast-stack { position:fixed; bottom:1.4rem; left:50%; transform:translateX(-50%); z-index:9000; display:flex; flex-direction:column-reverse; gap:7px; pointer-events:none; }
.toast { display:flex; align-items:center; gap:9px; padding:10px 16px; background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--r-md); box-shadow:var(--shadow-lg); font-size:.8rem; pointer-events:all; white-space:nowrap; transform:translateY(20px) scale(.95); opacity:0; transition:all .32s cubic-bezier(.34,1.56,.64,1); }
.toast.show { transform:translateY(0) scale(1); opacity:1; }
.toast-ico { font-size:1rem; }
.toast-msg { font-family:'Syne',sans-serif; font-weight:700; }

/* ── EMPTY ── */
.empty { text-align:center; padding:2.5rem 1rem; color:var(--text-3); font-size:.82rem; }
.empty-ico { font-size:2.2rem; margin-bottom:.6rem; }

/* ── MOBILE ── */
#sb-overlay { position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:199; opacity:0; pointer-events:none; transition:opacity .3s; }
#sb-overlay.show { opacity:1; pointer-events:all; }

/* ── ANIMATIONS ── */
@keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

@media (max-width:900px) {
  #sidebar { transform:translateX(-100%); }
  #sidebar.open { transform:translateX(0); }
  .main { margin-left:0 !important; }
  .tb-ham { display:flex; }
  .theme-grid { grid-template-columns:repeat(3,1fr); }
  .form-grid { grid-template-columns:1fr; }
  .stats-mini { grid-template-columns:1fr 1fr; }
}
@media (max-width:600px) {
  .page { padding:1.2rem 1rem; }
  .theme-grid { grid-template-columns:repeat(2,1fr); }
  .lang-grid { grid-template-columns:1fr; }
  .stats-mini { grid-template-columns:1fr; }
}
</style>
</head>
<body>

<div id="sb-overlay" onclick="closeSidebar()"></div>

<!-- ── SIDEBAR ── -->
<aside id="sidebar">
  <div class="sb-brand">
    <div class="sb-brand-icon">⚙️</div>
    <div class="sb-brand-name">Digital <em>Library</em></div>
  </div>

  <div class="sb-section">Navigation</div>
  <a class="sb-link" href="../dashboard.php">
    <i class="sb-icon bi bi-grid-1x2"></i> <?= htmlspecialchars($t['dashboard']) ?>
  </a>

  <div class="sb-section"><?= htmlspecialchars($t['settings']) ?></div>
  <a class="sb-link active" href="#" data-section="general"       onclick="switchSection('general',this);return false;"><i class="sb-icon bi bi-sliders2"></i>    <?= htmlspecialchars($t['general']) ?></a>
  <a class="sb-link"        href="#" data-section="appearance"    onclick="switchSection('appearance',this);return false;"><i class="sb-icon bi bi-palette"></i>     <?= htmlspecialchars($t['appearance']) ?></a>
  <a class="sb-link"        href="#" data-section="language"      onclick="switchSection('language',this);return false;"><i class="sb-icon bi bi-translate"></i>    <?= htmlspecialchars($t['language_tab']) ?></a>
  <a class="sb-link"        href="#" data-section="notifications" onclick="switchSection('notifications',this);return false;">
    <i class="sb-icon bi bi-bell"></i> <?= htmlspecialchars($t['notifications']) ?>
    <?php if ($notifCount > 0): ?><span class="sb-badge"><?= $notifCount ?></span><?php endif; ?>
  </a>
  <a class="sb-link"        href="#" data-section="security"      onclick="switchSection('security',this);return false;"><i class="sb-icon bi bi-shield-lock"></i>   <?= htmlspecialchars($t['security']) ?></a>
  <a class="sb-link"        href="#" data-section="system"        onclick="switchSection('system',this);return false;"><i class="sb-icon bi bi-gear-wide-connected"></i> <?= htmlspecialchars($t['system']) ?></a>
  <a class="sb-link"        href="#" data-section="library"       onclick="switchSection('library',this);return false;"><i class="sb-icon bi bi-book"></i>           <?= htmlspecialchars($t['library']) ?></a>
  <a class="sb-link"        href="#" data-section="logs"          onclick="switchSection('logs',this);return false;"><i class="sb-icon bi bi-clock-history"></i>     <?= htmlspecialchars($t['logs']) ?></a>

  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-avatar"><?= $avatar ?></div>
      <div>
        <div class="sb-user-name"><?= $firstName ?></div>
        <div class="sb-user-role">Admin</div>
      </div>
      <a href="logout.php" class="btn btn-ghost btn-sm" style="margin-left:auto;padding:4px 8px" title="Déconnexion">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    </div>
  </div>
</aside>

<!-- ── MAIN ── -->
<div class="main" id="main">
  <header class="topbar">
    <button class="tb-ham" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
    <div class="tb-bc">
      <span>DLS</span><span class="bc-sep">/</span>
      <span><?= htmlspecialchars($t['settings']) ?></span><span class="bc-sep">/</span>
      <span class="bc-curr" id="bc-curr"><?= htmlspecialchars($t['general']) ?></span>
    </div>
    <div class="tb-space"></div>
    <div class="db-status <?= $pdo ? 'ok' : 'err' ?>">
      <div class="db-dot <?= $pdo ? 'ok' : 'err' ?>"></div>
      <?= $pdo ? 'BD connectée' : 'BD hors ligne' ?>
    </div>
    <div class="tb-actions">
      <button class="tb-btn" id="notif-btn" onclick="toggleNotif()">
        <i class="bi bi-bell"></i>
        <?php if ($notifCount > 0): ?>
        <span class="notif-badge" id="notif-count"><?= min($notifCount,9) ?></span>
        <?php endif; ?>
      </button>
      <a href="../dashboard.php" class="tb-btn" title="Dashboard"><i class="bi bi-grid-1x2"></i></a>
      <div class="tb-user">
        <div class="tu-av"><?= $avatar ?></div>
        <div>
          <div class="tu-name"><?= $firstName ?></div>
          <div class="tu-role">admin</div>
        </div>
      </div>
    </div>
  </header>

  <main class="page">

    <!-- PAGE HEADER -->
    <div class="page-header">
      <div>
        <div class="ph-eyebrow">Administration</div>
        <div class="ph-title">⚙️ <?= htmlspecialchars($t['settings']) ?></div>
        <div class="ph-sub">Modifications synchronisées en temps réel dans dashboard.php</div>
        <div class="ph-meta">
          <span class="ph-pill blue"><i class="bi bi-database"></i> BD persistée</span>
          <span class="ph-pill green"><div class="pulse-dot"></div> Sync temps réel</span>
          <?php if (!$pdo): ?>
          <span class="ph-pill" style="background:rgba(244,63,94,.1);color:var(--rose);border-color:rgba(244,63,94,.2)"><i class="bi bi-exclamation-triangle"></i> Mode démo</span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end">
        <a href="../books/index.php" class="btn btn-ghost btn-sm"><i class="bi bi-book"></i> Catalogue</a>
        <a href="../users/index.php" class="btn btn-ghost btn-sm"><i class="bi bi-people"></i> Utilisateurs</a>
        <a href="../dashboard.php"  class="btn btn-primary btn-sm"><i class="bi bi-grid-1x2"></i> Dashboard</a>
      </div>
    </div>

    <!-- STATS MINI -->
    <div class="stats-mini">
      <div class="stat-mini"><div class="sm-icon">⚙️</div><div class="sm-val" id="st-settings"><?= $statsData['settings'] ?></div><div class="sm-label">Paramètres actifs</div></div>
      <div class="stat-mini"><div class="sm-icon">👥</div><div class="sm-val" id="st-users"><?= number_format($statsData['users']) ?></div><div class="sm-label">Utilisateurs actifs</div></div>
      <div class="stat-mini"><div class="sm-icon">📚</div><div class="sm-val" id="st-books"><?= number_format($statsData['books']) ?></div><div class="sm-label">Livres disponibles</div></div>
      <div class="stat-mini"><div class="sm-icon">🕐</div><div class="sm-val" style="font-size:.9rem"><?= $statsData['last_mod'] ? date('H:i', strtotime($statsData['last_mod'])) : '—' ?></div><div class="sm-label">Dernière modif.</div></div>
    </div>

    <!-- ══ GÉNÉRAL ══ -->
    <div class="s-section active" id="sec-general">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:color-mix(in srgb,var(--primary) 12%,transparent)">🎨</div>
          <div><div class="s-card-title">Personnalisation de la plateforme</div><div class="s-card-desc">Identité visuelle, nom et couleur — appliqués au dashboard instantanément</div></div>
        </div>
        <div class="s-card-body">
          <div class="s-row">
            <div><div class="s-label">Nom de la plateforme</div><div class="s-desc">Affiché dans le titre de page, les emails et l'en-tête du dashboard</div></div>
            <div class="s-ctrl">
              <input type="text" class="s-input" id="inp-site-name"
                     value="<?= htmlspecialchars($settings['site_name'], ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="Digital Library" maxlength="120">
            </div>
          </div>
          <div class="s-row">
            <div><div class="s-label">URL du logo</div><div class="s-desc">URL absolue ou chemin relatif (PNG, SVG, WebP)</div></div>
            <div class="s-ctrl">
              <input type="url" class="s-input" id="inp-site-logo"
                     value="<?= htmlspecialchars($settings['site_logo'], ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="https://…/logo.svg">
            </div>
          </div>
          <div class="s-row">
            <div><div class="s-label">Couleur principale</div><div class="s-desc">Appliquée instantanément ici ET dans dashboard.php via sync automatique</div></div>
            <div class="s-ctrl">
              <div class="color-wrap">
                <div class="color-swatch">
                  <input type="color" id="color-picker"
                         value="<?= htmlspecialchars($settings['primary_color'], ENT_QUOTES, 'UTF-8') ?>"
                         oninput="applyColor(this.value)"
                         onchange="saveSetting('primary_color', this.value)">
                </div>
                <div class="presets">
                  <?php foreach (['#00d4ff','#7c3aed','#00ffaa','#f59e0b','#f43f5e','#388bfd','#fb923c','#ec4899'] as $pc): ?>
                  <div class="preset <?= $settings['primary_color'] === $pc ? 'active' : '' ?>"
                       style="background:<?= htmlspecialchars($pc, ENT_QUOTES, 'UTF-8') ?>"
                       onclick="pickPreset('<?= htmlspecialchars($pc, ENT_QUOTES, 'UTF-8') ?>',this)"></div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ APPARENCE ══ -->
    <div class="s-section" id="sec-appearance">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:rgba(124,58,237,.12)">🌓</div>
          <div><div class="s-card-title">Thème de l'interface</div><div class="s-card-desc">Appliqué ici ET dans dashboard.php dès la prochaine visite (ou en temps réel via sync)</div></div>
        </div>
        <div class="s-card-body">
          <div class="theme-grid" id="theme-grid">
            <?php
            $themes = [
              'dark'    => ['Dark Mode',   'défaut',       '#05080f','#0b1020','rgba(255,255,255,.05)','rgba(255,255,255,.03)'],
              'light'   => ['Light Mode',  'clair',        '#f1f5fb','#ffffff','rgba(0,0,0,.04)',      'rgba(0,0,0,.04)'],
              'blue'    => ['Blue SaaS',   'pro',          '#06111f','#0a1a30','rgba(56,139,253,.1)',  'rgba(56,139,253,.08)'],
              'purple'  => ['Purple',      'premium',      '#0b0717','#130e24','rgba(167,139,250,.1)','rgba(167,139,250,.08)'],
              'minimal' => ['Minimal',     'black',        '#000000','#0a0a0a','rgba(255,255,255,.04)','rgba(255,255,255,.03)'],
            ];
            foreach ($themes as $key => [$name,$sub,$b1,$b2,$sbC,$cC]):
              $sel = $settings['theme'] === $key ? 'selected' : '';
            ?>
            <div class="theme-card <?= $sel ?>" onclick="selectTheme('<?= $key ?>',this)">
              <div class="theme-preview" style="background:<?= $b1 ?>">
                <div class="tp-bar" style="background:<?= $b2 ?>"></div>
                <div class="tp-body">
                  <div class="tp-sb" style="background:<?= $b2 ?>"></div>
                  <div class="tp-cards">
                    <div class="tp-c" style="background:<?= $cC ?>"></div>
                    <div class="tp-c" style="background:<?= $cC ?>"></div>
                  </div>
                </div>
              </div>
              <div class="theme-name"><?= htmlspecialchars($name) ?></div>
              <div class="theme-sub"><?= htmlspecialchars($sub) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ LANGUE ══ -->
    <div class="s-section" id="sec-language">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:rgba(245,158,11,.12)">🌍</div>
          <div><div class="s-card-title">Langue de l'interface</div><div class="s-card-desc">La page sera rechargée pour appliquer la langue</div></div>
        </div>
        <div class="s-card-body">
          <div class="lang-grid">
            <div class="lang-card <?= $settings['language']==='fr'?'selected':'' ?>" onclick="selectLang('fr',this)">
              <div class="lang-flag">🇫🇷</div>
              <div><div class="lang-name">Français</div><div class="lang-native">fr — French</div></div>
              <?php if ($settings['language']==='fr'): ?><span class="chip chip-ok" style="margin-left:auto">Actif</span><?php endif; ?>
            </div>
            <div class="lang-card <?= $settings['language']==='en'?'selected':'' ?>" onclick="selectLang('en',this)">
              <div class="lang-flag">🇬🇧</div>
              <div><div class="lang-name">English</div><div class="lang-native">en — Anglais</div></div>
              <?php if ($settings['language']==='en'): ?><span class="chip chip-ok" style="margin-left:auto">Active</span><?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ NOTIFICATIONS ══ -->
    <div class="s-section" id="sec-notifications">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:rgba(0,255,170,.1)">🔔</div>
          <div><div class="s-card-title">Système de notifications</div><div class="s-card-desc">Ces toggles contrôlent les alertes pour toute la plateforme</div></div>
        </div>
        <div class="s-card-body">
          <?php foreach ([
            ['notif_enabled','Activer les notifications','Activer/désactiver toutes les notifications'],
            ['notif_sales',  'Alertes ventes',           'Notifié à chaque vente confirmée'],
            ['notif_bonus',  'Alertes bonus',            'Notifié quand un bonus est déclenché'],
            ['notif_users',  'Alertes utilisateurs',     'Notifié à chaque nouvelle inscription'],
          ] as [$k,$lbl,$desc]): ?>
          <div class="s-row">
            <div><div class="s-label"><?= htmlspecialchars($lbl) ?></div><div class="s-desc"><?= htmlspecialchars($desc) ?></div></div>
            <div class="s-ctrl">
              <label class="toggle">
                <input type="checkbox" <?= $settings[$k]==='1'?'checked':'' ?> onchange="saveToggle('<?= $k ?>',this)">
                <div class="toggle-track"></div>
                <div class="toggle-thumb"></div>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ══ SÉCURITÉ ══ -->
    <div class="s-section" id="sec-security">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:rgba(244,63,94,.1)">🔑</div>
          <div><div class="s-card-title">Changer le mot de passe</div><div class="s-card-desc">Modifier le mot de passe du compte administrateur</div></div>
        </div>
        <div class="s-card-body">
          <div class="form-grid">
            <div class="form-group full">
              <label class="form-label" for="pwd-current">Mot de passe actuel</label>
              <div class="input-wrap">
                <input type="password" class="s-input" id="pwd-current" placeholder="••••••••" style="width:100%;padding-right:38px">
                <i class="bi bi-eye-slash input-eye" onclick="togglePwd('pwd-current',this)"></i>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" for="pwd-new">Nouveau mot de passe</label>
              <div class="input-wrap">
                <input type="password" class="s-input" id="pwd-new" placeholder="••••••••" style="width:100%;padding-right:38px" oninput="checkStrength(this.value)">
                <i class="bi bi-eye-slash input-eye" onclick="togglePwd('pwd-new',this)"></i>
              </div>
              <div class="pwd-meter"><div class="pwd-bar" id="pwd-bar"></div></div>
              <div class="pwd-hint" id="pwd-hint">Tapez un mot de passe…</div>
            </div>
            <div class="form-group">
              <label class="form-label" for="pwd-confirm">Confirmer</label>
              <div class="input-wrap">
                <input type="password" class="s-input" id="pwd-confirm" placeholder="••••••••" style="width:100%;padding-right:38px">
                <i class="bi bi-eye-slash input-eye" onclick="togglePwd('pwd-confirm',this)"></i>
              </div>
            </div>
          </div>
        </div>
        <div class="s-card-foot">
          <button class="btn btn-ghost" onclick="clearPwdFields()"><i class="bi bi-x"></i> Annuler</button>
          <button class="btn btn-primary" id="btn-pwd" onclick="changePassword()"><i class="bi bi-shield-check"></i> Changer le mot de passe</button>
        </div>
      </div>

      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:color-mix(in srgb,var(--primary) 12%,transparent)">🔐</div>
          <div><div class="s-card-title">Authentification à deux facteurs (2FA)</div><div class="s-card-desc">TOTP — Compatible Google Authenticator, Authy, 1Password</div></div>
        </div>
        <div class="s-card-body">
          <div class="tfa-box">
            <div class="tfa-ico">📱</div>
            <div style="flex:1">
              <div class="tfa-title">Double authentification</div>
              <div class="tfa-desc">Protège votre compte même si le mot de passe est compromis.</div>
            </div>
            <label class="toggle" style="flex-shrink:0">
              <input type="checkbox" <?= $settings['two_fa']==='1'?'checked':'' ?> onchange="saveToggle('two_fa',this)">
              <div class="toggle-track"></div>
              <div class="toggle-thumb"></div>
            </label>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ SYSTÈME ══ -->
    <div class="s-section" id="sec-system">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:color-mix(in srgb,var(--primary) 10%,transparent)">⚙️</div>
          <div><div class="s-card-title">Paramètres système</div><div class="s-card-desc">Devise, format de date, pagination, fuseau horaire</div></div>
        </div>
        <div class="s-card-body">
          <div class="s-row">
            <div><div class="s-label">Devise</div><div class="s-desc">Utilisée dans tous les affichages de prix et rapports</div></div>
            <div class="s-ctrl">
              <select class="s-select" onchange="saveSetting('currency',this.value)">
                <?php foreach (['FCFA'=>'FCFA — Franc CFA','EUR'=>'EUR — Euro','USD'=>'USD — Dollar','XOF'=>'XOF — FCFA BCEAO','GBP'=>'GBP — Livre Sterling'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $settings['currency']===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="s-row">
            <div><div class="s-label">Format de date</div><div class="s-desc">Utilisé dans les tableaux, exports et rapports</div></div>
            <div class="s-ctrl">
              <select class="s-select" onchange="saveSetting('date_format',this.value)">
                <option value="DD/MM/YYYY" <?= $settings['date_format']==='DD/MM/YYYY'?'selected':'' ?>>DD/MM/YYYY</option>
                <option value="MM/DD/YYYY" <?= $settings['date_format']==='MM/DD/YYYY'?'selected':'' ?>>MM/DD/YYYY</option>
                <option value="YYYY-MM-DD" <?= $settings['date_format']==='YYYY-MM-DD'?'selected':'' ?>>YYYY-MM-DD (ISO)</option>
              </select>
            </div>
          </div>
          <div class="s-row">
            <div><div class="s-label">Éléments par page</div><div class="s-desc">Appliqué dans tous les tableaux et listes</div></div>
            <div class="s-ctrl">
              <select class="s-select" onchange="saveSetting('pagination',this.value)">
                <?php foreach (['10','20','50','100'] as $v): ?>
                <option value="<?= $v ?>" <?= $settings['pagination']===$v?'selected':'' ?>><?= $v ?> par page</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="s-row">
            <div><div class="s-label">Fuseau horaire</div><div class="s-desc">Utilisé pour les timestamps et rappels</div></div>
            <div class="s-ctrl">
              <select class="s-select" onchange="saveSetting('timezone',this.value)">
                <?php foreach ([
                  'Africa/Douala'=>'Afrique/Douala (UTC+1)',
                  'Africa/Lagos' =>'Afrique/Lagos (UTC+1)',
                  'Africa/Dakar' =>'Afrique/Dakar (UTC+0)',
                  'Europe/Paris' =>'Europe/Paris (UTC+1/2)',
                  'UTC'          =>'UTC (UTC+0)',
                ] as $tz=>$lbl): ?>
                <option value="<?= $tz ?>" <?= $settings['timezone']===$tz?'selected':'' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ BIBLIOTHÈQUE ══ -->
    <div class="s-section" id="sec-library">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:rgba(0,255,170,.1)">📚</div>
          <div><div class="s-card-title">Paramètres bibliothèque</div><div class="s-card-desc">Règles bonus, accès par défaut, téléchargements</div></div>
        </div>
        <div class="s-card-body">
          <div class="s-row">
            <div>
              <div class="s-label">Règle bonus fidélité</div>
              <div class="s-desc"><span id="bonus-preview"><?= (int)$settings['bonus_rule'] ?></span> achats = 1 livre gratuit offert automatiquement</div>
            </div>
            <div class="s-ctrl">
              <input type="number" class="s-input s-input-sm" id="inp-bonus"
                     value="<?= (int)$settings['bonus_rule'] ?>" min="1" max="100"
                     oninput="document.getElementById('bonus-preview').textContent=this.value">
            </div>
          </div>
          <div class="s-row">
            <div><div class="s-label">Accès par défaut</div><div class="s-desc">Accès appliqué aux nouveaux livres</div></div>
            <div class="s-ctrl">
              <select class="s-select" onchange="saveSetting('default_access',this.value)">
                <option value="paid" <?= $settings['default_access']==='paid'?'selected':'' ?>>Payant</option>
                <option value="free" <?= $settings['default_access']==='free'?'selected':'' ?>>Gratuit</option>
              </select>
            </div>
          </div>
          <div class="s-row">
            <div><div class="s-label">Téléchargements max / livre</div><div class="s-desc">Nombre de téléchargements par utilisateur et par livre</div></div>
            <div class="s-ctrl">
              <input type="number" class="s-input s-input-sm" id="inp-maxdl"
                     value="<?= (int)$settings['max_downloads'] ?>" min="1" max="100">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ JOURNAUX ══ -->
    <div class="s-section" id="sec-logs">
      <div class="s-card">
        <div class="s-card-head">
          <div class="s-card-ico" style="background:rgba(245,158,11,.1)">📋</div>
          <div><div class="s-card-title">Journaux administrateur</div><div class="s-card-desc">Historique des actions — toutes modifications de paramètres incluses</div></div>
          <?php if (!empty($adminLogs)): ?><span class="chip chip-muted" style="margin-left:auto"><?= count($adminLogs) ?> entrées</span><?php endif; ?>
        </div>
        <div class="s-card-body">
          <?php if (empty($adminLogs)): ?>
          <div class="empty"><div class="empty-ico">📋</div><div>Aucun journal disponible</div></div>
          <?php else:
            $logIcons = ['login'=>'🔑','logout'=>'🚪','password_changed'=>'🔐','setting_changed'=>'⚙️','user_created'=>'👤','user_deleted'=>'🗑️'];
          ?>
          <div class="log-list">
            <?php foreach ($adminLogs as $log):
              $ico  = $logIcons[$log['action'] ?? ''] ?? '📋';
              $who  = trim($log['user_name'] ?? '') ?: 'Admin';
              $when = !empty($log['created_at']) ? date('d/m/Y H:i', strtotime($log['created_at'])) : '—';
            ?>
            <div class="log-item">
              <div class="log-ico"><?= $ico ?></div>
              <div style="flex:1;min-width:0">
                <div class="log-action"><?= htmlspecialchars($log['action'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="log-meta">
                  <?= htmlspecialchars($who, ENT_QUOTES, 'UTF-8') ?> · <?= $when ?>
                  <?php if (!empty($log['detail'])): ?>
                  · <span style="color:var(--text-3)"><?= htmlspecialchars(mb_substr($log['detail'],0,60), ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="log-ip"><?= htmlspecialchars($log['ip'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </main>
</div>

<!-- NOTIF PANEL -->
<div id="notif-panel">
  <div class="np-head">
    <span>Notifications</span>
    <?php if ($notifCount > 0): ?><span class="chip chip-danger"><?= $notifCount ?></span><?php endif; ?>
  </div>
  <div class="np-list">
    <?php if (empty($notifications)): ?>
    <div class="empty" style="padding:1.5rem"><div class="empty-ico" style="font-size:1.6rem">🔔</div>Aucune notification</div>
    <?php else: foreach ($notifications as $n):
      $unread = !(bool)($n['lu'] ?? false);
    ?>
    <div class="np-item <?= $unread ? 'unread' : '' ?>" onclick="markRead(<?= (int)$n['id'] ?>)">
      <div class="np-ico" style="background:<?= htmlspecialchars($n['bg'] ?? 'rgba(0,212,255,.1)', ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($n['icon'] ?? '🔔', ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:.77rem;color:var(--text-2)"><?= htmlspecialchars($n['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        <div style="font-size:.6rem;color:var(--text-3);font-family:'Space Mono',monospace"><?= timeAgo($n['created_at'] ?? '') ?></div>
      </div>
      <?php if ($unread): ?><div style="width:7px;height:7px;border-radius:50%;background:var(--primary);flex-shrink:0"></div><?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <div class="np-foot">
    <button class="btn btn-ghost btn-sm" onclick="markRead(0)"><i class="bi bi-check2-all"></i> Tout lire</button>
    <a href="../admin/notifications.php" class="btn btn-ghost btn-sm">Voir toutes <i class="bi bi-arrow-right"></i></a>
  </div>
</div>

<!-- SAVE INDICATOR -->
<div id="save-indicator">
  <div class="si-dot"></div>
  <div class="si-label" id="save-label">Enregistré ✓</div>
</div>

<div id="toast-stack"></div>

<!-- ════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════ -->
<script>
const CSRF     = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const SELF_URL = window.location.pathname;
const LANG     = <?= json_encode($lang, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

const SECTION_LABELS = {
  general:'Général', appearance:'Apparence', language:'Langue',
  notifications:'Notifications', security:'Sécurité',
  system:'Système', library:'Bibliothèque', logs:'Journaux',
};

/* ── SIDEBAR ── */
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sb-overlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sb-overlay').classList.remove('show');
}

/* ── SECTION NAVIGATION ── */
function switchSection(name, el) {
  document.querySelectorAll('.s-section').forEach(s => s.classList.remove('active'));
  document.getElementById('sec-' + name)?.classList.add('active');
  document.querySelectorAll('.sb-link[data-section]').forEach(l => l.classList.remove('active'));
  if (el) el.classList.add('active');
  const bc = document.getElementById('bc-curr');
  if (bc) bc.textContent = SECTION_LABELS[name] || name;
  if (window.innerWidth <= 900) closeSidebar();
}

/* ── TOAST ── */
const ICONS = { success:'✅', error:'🔴', warn:'⚠️', info:'ℹ️' };
function toast(msg, type = 'success') {
  const stack = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.innerHTML = `<span class="toast-ico">${ICONS[type]||'ℹ️'}</span><span class="toast-msg">${String(msg).replace(/</g,'&lt;')}</span>`;
  stack.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 350); }, 3200);
}

/* ── SAVE INDICATOR ── */
let _saveT = null;
function showSaved(msg = 'Enregistré ✓') {
  const el = document.getElementById('save-indicator');
  document.getElementById('save-label').textContent = msg;
  el.classList.add('show');
  clearTimeout(_saveT);
  _saveT = setTimeout(() => el.classList.remove('show'), 2500);
}

/* ════════════════════════════════════════
   SAVE SETTING — cœur du système
   Sauvegarde en BD ET applique localement
════════════════════════════════════════ */
async function saveSetting(key, value) {
  try {
    const res = await fetch(SELF_URL, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ action: 'save_setting', key, value: String(value), csrf: CSRF }),
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    if (data.success) {
      showSaved(`${key} enregistré ✓`);
      // Appliquer localement sur settings.php
      applySettingLocally(key, value);
      if (data.demo) toast('Mode démo — BD non connectée', 'warn');
    } else {
      toast(data.error || 'Erreur de sauvegarde', 'error');
    }
    return data;
  } catch (err) {
    console.error('[Settings]', err);
    showSaved('Enregistré (démo)');
    applySettingLocally(key, value);
    return { success: true, demo: true };
  }
}

/* ── Applique le paramètre dans l'interface settings.php ── */
function applySettingLocally(key, value) {
  const root = document.documentElement;
  switch (key) {
    case 'primary_color':
      root.style.setProperty('--primary', value);
      root.style.setProperty('--border-act', value + '99');
      break;
    case 'theme':
      root.setAttribute('data-theme', value);
      break;
    case 'site_name':
      document.title = value + ' — Paramètres';
      break;
  }
}

/* ── Toggle ── */
function saveToggle(key, cb) {
  saveSetting(key, cb.checked ? '1' : '0');
}

/* ── Thème ── */
function selectTheme(theme, card) {
  document.documentElement.setAttribute('data-theme', theme);
  document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  saveSetting('theme', theme);
  toast(`Thème "${theme}" enregistré. Dashboard mis à jour au prochain chargement.`, 'success');
}

/* ── Langue ── */
function selectLang(lang, card) {
  document.querySelectorAll('.lang-card').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  saveSetting('language', lang).then(d => {
    if (d.success) setTimeout(() => window.location.reload(), 900);
  });
  toast('Langue modifiée, rechargement…', 'info');
}

/* ── Color picker ── */
function applyColor(hex) {
  document.documentElement.style.setProperty('--primary', hex);
  document.documentElement.style.setProperty('--border-act', hex + '99');
  document.querySelectorAll('.preset').forEach(d => {
    d.classList.toggle('active', d.style.background.toLowerCase() === hex.toLowerCase());
  });
}
function pickPreset(hex, dot) {
  const picker = document.getElementById('color-picker');
  if (picker) picker.value = hex;
  applyColor(hex);
  document.querySelectorAll('.preset').forEach(d => d.classList.remove('active'));
  dot.classList.add('active');
  saveSetting('primary_color', hex);
}

/* ── Debounce ── */
function debounce(fn, delay) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}
function bindDebounce(id, key, delay = 800) {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('input', debounce(() => saveSetting(key, el.value.trim()), delay));
}

/* ── Mot de passe ── */
function togglePwd(id, icon) {
  const el = document.getElementById(id);
  if (!el) return;
  el.type = el.type === 'password' ? 'text' : 'password';
  icon.className = el.type === 'text' ? 'bi bi-eye input-eye' : 'bi bi-eye-slash input-eye';
}
function checkStrength(pwd) {
  const bar = document.getElementById('pwd-bar');
  const hint = document.getElementById('pwd-hint');
  if (!bar || !hint) return;
  let score = 0;
  if (pwd.length >= 8)            score++;
  if (pwd.length >= 12)           score++;
  if (/[A-Z]/.test(pwd))         score++;
  if (/[0-9]/.test(pwd))         score++;
  if (/[^A-Za-z0-9]/.test(pwd))  score++;
  const levels = [
    {p:'18%',c:'var(--rose)', t:'Très faible'},
    {p:'35%',c:'var(--rose)', t:'Faible'},
    {p:'55%',c:'var(--amber)',t:'Moyen'},
    {p:'78%',c:'var(--neon)', t:'Fort'},
    {p:'100%',c:'var(--neon)',t:'Très fort'},
  ];
  const lvl = pwd.length === 0 ? null : levels[Math.max(0, score - 1)];
  bar.style.width      = lvl ? lvl.p : '0%';
  bar.style.background = lvl ? lvl.c : '';
  hint.textContent     = lvl ? lvl.t : 'Tapez un mot de passe…';
  hint.style.color     = lvl ? lvl.c : 'var(--text-3)';
}
function clearPwdFields() {
  ['pwd-current','pwd-new','pwd-confirm'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  checkStrength('');
}
async function changePassword() {
  const cur = document.getElementById('pwd-current')?.value?.trim() ?? '';
  const nw  = document.getElementById('pwd-new')?.value?.trim() ?? '';
  const con = document.getElementById('pwd-confirm')?.value?.trim() ?? '';
  if (!cur || !nw || !con) { toast('Tous les champs sont requis.', 'warn'); return; }
  if (nw !== con)           { toast('Les mots de passe ne correspondent pas.', 'error'); return; }
  if (nw.length < 8)        { toast('Minimum 8 caractères requis.', 'warn'); return; }
  const btn = document.getElementById('btn-pwd');
  if (btn) { btn.classList.add('loading'); btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Modification…'; }
  try {
    const res = await fetch(SELF_URL, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ action:'change_password', current_password:cur, new_password:nw, confirm_password:con, csrf:CSRF }),
    });
    const data = await res.json();
    if (data.success) { toast(data.message || 'Mot de passe modifié !', 'success'); clearPwdFields(); }
    else toast(data.error || 'Erreur serveur', 'error');
  } catch {
    toast('Mot de passe modifié (démo) !', 'success');
    clearPwdFields();
  } finally {
    if (btn) { btn.classList.remove('loading'); btn.innerHTML = '<i class="bi bi-shield-check"></i> Changer le mot de passe'; }
  }
}

/* ── Notifications ── */
function toggleNotif() {
  const p = document.getElementById('notif-panel');
  p?.classList.toggle('open');
}
document.addEventListener('click', e => {
  const p = document.getElementById('notif-panel');
  const b = document.getElementById('notif-btn');
  if (p?.classList.contains('open') && !p.contains(e.target) && !b?.contains(e.target))
    p.classList.remove('open');
});
async function markRead(id) {
  try {
    const res = await fetch(SELF_URL, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ action:'mark_notif_read', notif_id:id, csrf:CSRF }),
    });
    const data = await res.json();
    if (data.success) {
      const badge   = document.getElementById('notif-count');
      const sbBadge = document.querySelector('.sb-badge');
      if (data.count === 0) { badge?.remove(); sbBadge?.remove(); }
      else {
        if (badge)   badge.textContent   = Math.min(data.count, 9);
        if (sbBadge) sbBadge.textContent = data.count;
      }
      if (id === 0) {
        document.querySelectorAll('.np-item.unread').forEach(el => el.classList.remove('unread'));
        toast('Toutes les notifications lues.', 'success');
      }
    }
  } catch (e) { console.error('[Notif]', e); }
}

/* ════════════════════════════════════════
   POLLING — synchronisation vers dashboard
   Toutes les 5s, vérifie si les settings
   en BD ont changé (depuis un autre onglet
   ou depuis le dashboard lui-même).
   Les changements sont stockés en BD :
   dashboard.php les lira au prochain load
   OU via son propre polling (5s aussi).
════════════════════════════════════════ */
let _lastTs = 0;
async function pollStats() {
  try {
    const res = await fetch(SELF_URL, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ action:'get_stats', csrf:CSRF }),
    });
    if (!res.ok) return;
    const data = await res.json();
    if (!data.success) return;
    const s = data.stats;
    const upd = (id, val) => { const el = document.getElementById(id); if (el && val !== undefined) el.textContent = val; };
    upd('st-settings', s.settings);
    upd('st-users',    typeof s.users === 'number' ? s.users.toLocaleString('fr-FR') : s.users);
    upd('st-books',    typeof s.books === 'number' ? s.books.toLocaleString('fr-FR') : s.books);
    // Badge notifications
    if (typeof data.notif_count === 'number') {
      const badge = document.getElementById('notif-count');
      const sbBadge = document.querySelector('.sb-badge');
      if (data.notif_count > 0) {
        const cnt = Math.min(data.notif_count, 9);
        if (badge) badge.textContent = cnt;
        if (sbBadge) sbBadge.textContent = data.notif_count;
      }
    }
  } catch {}
}
setInterval(pollStats, 5000);

/* ── INIT ── */
document.addEventListener('DOMContentLoaded', () => {
  const initColor = document.getElementById('color-picker')?.value;
  if (initColor) applyColor(initColor);

  bindDebounce('inp-site-name', 'site_name',  900);
  bindDebounce('inp-site-logo', 'site_logo',  900);
  bindDebounce('inp-bonus',     'bonus_rule', 700);
  bindDebounce('inp-maxdl',     'max_downloads', 700);

  setTimeout(() => toast(
    '<?= $pdo ? "Paramètres chargés depuis MySQL" : "Mode démo — connectez votre BD" ?>',
    '<?= $pdo ? "success" : "warn" ?>'
  ), 600);
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.getElementById('notif-panel')?.classList.remove('open');
});
</script>
</body>
</html>