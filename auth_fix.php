<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — Auth + Session réelle v3.0        ║
 * ║  CORRECTIF MAJEUR : charge le vrai utilisateur depuis la BD  ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * BUG #1 RÉSOLU : "Bonjour Alexandre" en dur dans la session démo.
 *   → refreshUserSession() relit la BD à chaque chargement (TTL 5min).
 *   → En prod, remplacer le bloc DEMO par requireLogin().
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit;
    }
}

/**
 * Recharge les données utilisateur depuis la BD.
 * Appeler après avoir obtenu une connexion PDO.
 */
function refreshUserSession(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare(
        "SELECT id, nom, email, role, avatar FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: /auth/login.php?error=session_expired');
        exit;
    }

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['username']   = $user['nom'];          // ← vrai nom depuis BD
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_avatar']= $user['avatar'] ?? null;
    $_SESSION['session_ts'] = time();
}

function shouldRefreshSession(): bool {
    return ((time() - ($_SESSION['session_ts'] ?? 0)) > 300);
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValidate(string $token): bool {
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}