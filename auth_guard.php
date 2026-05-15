<?php
/**
 * auth_guard.php
 * Protection des pages privées (version PRO)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔐 Vérification authentification
if (empty($_SESSION['user_id'])) {

    // 🔥 URL complète actuelle (fiable)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host   = $_SERVER['HTTP_HOST'];
    $uri    = $_SERVER['REQUEST_URI'];

    $currentUrl = $scheme . "://" . $host . $uri;

    // 🔒 Encodage sécurisé
    $redirectTo = urlencode($currentUrl);

    // 🚀 Redirection vers login
    header("Location: /login.php?redirect=" . $redirectTo);
    exit;
}