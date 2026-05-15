<?php
// includes/auth.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ Chemin corrigé
require_once __DIR__ . '/../config.php';

// Vérifier connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Helpers
function currentUser(): array {
    return [
        'id'     => (int)($_SESSION['user_id'] ?? 0),
        'name'   => $_SESSION['username'] ?? 'Invité',
        'email'  => $_SESSION['user_email'] ?? '',
        'role'   => $_SESSION['user_role'] ?? 'lecteur',
        'avatar' => $_SESSION['avatar'] ?? '',
    ];
}

function isAdmin(): bool       { return ($_SESSION['user_role'] ?? '') === 'admin'; }
function isJournaliste(): bool { return ($_SESSION['user_role'] ?? '') === 'journaliste'; }
function isLecteur(): bool     { return ($_SESSION['user_role'] ?? '') === 'lecteur'; }

// CSRF
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// Start session user
function startUserSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['prenom'] . ' ' . $user['nom'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['avatar']     = strtoupper(substr($user['prenom'], 0, 1));
}