<?php
/**
 * api/dashboard_stats.php — Statistiques en temps réel
 * Retourne toujours du JSON valide
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

set_error_handler(function($errno, $errstr) {
    ob_clean();
    echo json_encode(['error' => $errstr]);
    exit;
});

// Auth
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// BD
$pdo = null;
foreach ([dirname(__DIR__).'/includes/config.php', dirname(__DIR__).'/config/config.php'] as $cp) {
    if (file_exists($cp)) { require_once $cp; break; }
}
if (!isset($pdo) || $pdo === null) {
    $h=defined('DB_HOST')?DB_HOST:'localhost'; $n=defined('DB_NAME')?DB_NAME:'digital_library';
    $u=defined('DB_USER')?DB_USER:'root'; $p=defined('DB_PASS')?DB_PASS:'';
    try { $pdo=new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
    catch(Exception $e){ ob_clean(); echo json_encode(['error'=>$e->getMessage()]); exit; }
}

try {
    $stats = [
        'total_livres'  => (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn(),
        'gratuit'       => (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND prix=0")->fetchColumn(),
        'premium'       => (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND prix>3500")->fetchColumn(),
        'users'         => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE statut='actif'")->fetchColumn(),
        'ventes'        => (int)$pdo->query("SELECT COALESCE(SUM(nb_ventes),0) FROM livres")->fetchColumn(),
        'revenus_mois'  => (int)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn(),
    ];

    $recents = $pdo->query(
        "SELECT l.titre, a.montant, a.created_at
         FROM achats a JOIN livres l ON l.id=a.livre_id
         WHERE a.statut='confirme' ORDER BY a.created_at DESC LIMIT 5"
    )->fetchAll();

    $stats['recents'] = $recents;

    ob_clean();
    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['error' => $e->getMessage()]);
}