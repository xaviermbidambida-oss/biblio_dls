<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║         DIGITAL LIBRARY SYSTEM — Authentification           ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

if (!function_exists('requireLogin')) {
    function requireLogin(): void {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . (defined('APP_URL') ? APP_URL : '') . '/auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
    }
}

if (!function_exists('currentUser')) {
    /**
     * Récupère l'utilisateur connecté depuis la BD (avec cache session)
     * Retourne null si non connecté ou introuvable
     */
    function currentUser(bool $forceRefresh = false): ?array {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        // Cache en session pour éviter des requêtes répétées
        if (!$forceRefresh && isset($_SESSION['_user_cache'])) {
            return $_SESSION['_user_cache'];
        }

        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare("
                SELECT id, nom, prenom, email, role, avatar, statut, created_at
                FROM users
                WHERE id = :id AND statut != 'bloque'
                LIMIT 1
            ");
            $stmt->execute([':id' => (int)$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user) {
                // Utilisateur supprimé ou bloqué → déconnexion
                session_destroy();
                header('Location: auth/login.php?error=compte_invalide');
                exit;
            }

            // Mise en cache session
            $_SESSION['_user_cache']  = $user;
            $_SESSION['user_role']    = $user['role'];
            $_SESSION['username']     = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
            $_SESSION['user_email']   = $user['email'];

            return $user;
        } catch (Throwable $e) {
            error_log('[DLS] currentUser error: ' . $e->getMessage());
            // Fallback vers les données de session si la BD est indisponible
            return [
                'id'     => $_SESSION['user_id'],
                'nom'    => explode(' ', $_SESSION['username'] ?? 'Utilisateur')[1] ?? 'Utilisateur',
                'prenom' => explode(' ', $_SESSION['username'] ?? '')[0] ?? '',
                'email'  => $_SESSION['user_email'] ?? '',
                'role'   => $_SESSION['user_role']  ?? 'lecteur',
                'statut' => 'actif',
            ];
        }
    }
}

if (!function_exists('hasRole')) {
    function hasRole(string ...$roles): bool {
        $userRole = $_SESSION['user_role'] ?? 'lecteur';
        return in_array($userRole, $roles, true);
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin(): bool { return hasRole('admin'); }
}