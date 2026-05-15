<?php
/**
 * DIGITAL LIBRARY SYSTEM
 * Connexion base de données PDO
 * Fichier central DB
 */

declare(strict_types=1);

/* ─────────────────────────────────────────────
   CONFIGURATION BASE DE DONNÉES
───────────────────────────────────────────── */

$host     = 'localhost';
$dbname   = 'digital_library';
$username = 'root';
$password = '';

/* ─────────────────────────────────────────────
   CONNEXION PDO
───────────────────────────────────────────── */

try {

    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // erreurs propres
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // tableaux associatifs
            PDO::ATTR_EMULATE_PREPARES   => false,                  // sécurité
        ]
    );

} catch (PDOException $e) {

    // IMPORTANT : stop système si DB KO
    die("
        ❌ Erreur connexion base de données<br>
        <b>" . htmlspecialchars($e->getMessage()) . "</b>
    ");
}


/**
 * DIGITAL LIBRARY — config/database.php
 * Connexion PDO unique + helper dbCount()
 */

if (!isset($pdo)) {
    $dsn = 'mysql:host=localhost;dbname=digital_library;charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, 'root', '', $options);
    } catch (PDOException $e) {
        error_log('[DLS] DB connection failed: ' . $e->getMessage());
        $pdo = null;
    }
}

/**
 * dbCount — exécute un COUNT(*) et retourne l'entier.
 * Requiert la variable $pdo dans le scope global.
 */
if (!function_exists('dbCount')) {
    function dbCount(string $sql, array $params = []): int
    {
        global $pdo;
        if (!$pdo) return 0;
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            error_log('[DLS] dbCount error: ' . $e->getMessage());
            return 0;
        }
    }
}