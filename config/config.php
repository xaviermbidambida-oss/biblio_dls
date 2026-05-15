<?php
require_once __DIR__ . '/../includes/config.php';

// WHITELIST ADMIN
if (!function_exists('isAdminWhitelisted')) {
    function isAdminWhitelisted(string $email): bool {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM admin_whitelist WHERE email = ?");
        $stmt->execute([$email]);
        return (bool) $stmt->fetch();
    }
}

// CURRENT USER
if (!function_exists('currentUser')) {
    function currentUser() {
        if (!isset($_SESSION['user_id'])) return null;

        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);

        return $stmt->fetch();
    }
}