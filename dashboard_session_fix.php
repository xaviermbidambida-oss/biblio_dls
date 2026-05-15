<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — PATCH DASHBOARD v3.0                      ║
 * ║  Remplace le bloc d'initialisation de session dans dashboard.php     ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 *
 * REMPLACER dans dashboard.php le bloc "Dépendances + Auth guard + DEMO"
 * (lignes ~1-60) par ce fichier.
 *
 * BUGS CORRIGÉS :
 *  #1 — "Bonjour Alexandre" hardcodé : la session DEMO est maintenant
 *       remplacée par une vraie lecture en BD via refreshUserSession().
 *  #2 — shouldRefreshSession() appelé avant que la connexion PDO
 *       soit disponible : on utilise getPDO() défini dans config.php.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth_fix.php';   // ← nouveau auth
require_once __DIR__ . '/includes/data.php';

// ── En PRODUCTION : décommenter et supprimer le bloc DEMO ───
// requireLogin();

// ── DEMO : simule une session si absente ─────────────────────
// ⚠️  Changer user_id pour tester un autre utilisateur.
// ⚠️  Le nom/rôle vient maintenant de la BD, pas du code.
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id']   = 1;   // ← ID à changer pour tester
    $_SESSION['session_ts'] = 0;  // Force un refresh immédiat
}

// ── Refresh depuis la BD (TTL 5 min) ─────────────────────────
if (shouldRefreshSession()) {
    $pdo = getPDO();                          // fonction dans config.php
    refreshUserSession($pdo, (int)$_SESSION['user_id']);
}

// ── Variables de session (toutes issues de la BD désormais) ──
$userId    = (int)$_SESSION['user_id'];
$username  = htmlspecialchars($_SESSION['username']   ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$userRole  = $_SESSION['user_role']  ?? 'lecteur';
$userEmail = htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8');
$avatar    = strtoupper(substr($username, 0, 1));
$firstName = htmlspecialchars(explode(' ', trim($username))[0], ENT_QUOTES, 'UTF-8');

// ── Validation du rôle ───────────────────────────────────────
$validRoles = ['admin', 'journaliste', 'lecteur'];
if (!in_array($userRole, $validRoles, true)) {
    session_destroy();
    header('Location: auth/login.php?error=role_invalide');
    exit;
}

// ── Chargement des données selon le rôle ────────────────────
$data = [];
try {
    switch ($userRole) {
        case 'admin':
            $data['stats']         = getAdminStats();
            $data['activity']      = getRecentActivity(8);
            $data['chart']         = getSalesChart7Days();
            $usersResult           = getUsers(1, 8);
            $data['users']         = $usersResult['data'] ?? [];
            $data['usersMeta']     = $usersResult;
            $data['notifications'] = getAdminNotifications(6);
            break;
        case 'journaliste':
            $data['stats']         = getJournalisteStats($userId);
            $data['notifications'] = getNotifications($userId, 5);
            break;
        case 'lecteur':
            $data['stats']         = getLecteurStats($userId);
            $data['notifications'] = getNotifications($userId, 5);
            break;
    }
    $data['notifCount'] = function_exists('getUnreadNotifCount')
        ? (int)getUnreadNotifCount($userId) : 0;
} catch (Throwable $e) {
    error_log('[DLS] Dashboard error: ' . $e->getMessage());
    $data['error'] = 'Erreur de chargement. Veuillez rafraîchir la page.';
}

// ── getPDO() à ajouter dans config/config.php si absent ──────
/*
function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}
*/