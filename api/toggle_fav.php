<?php
/**
 * api/toggle_fav.php — Gestion des favoris
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($errno, $errstr) { ob_clean(); echo json_encode(['success'=>false,'error'=>$errstr]); exit; });

if (!isset($_SESSION['user_id'])) { ob_clean(); echo json_encode(['success'=>false,'error'=>'Non authentifié']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ob_clean(); echo json_encode(['success'=>false,'error'=>'POST requis']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$livreId = (int)($body['livre_id'] ?? 0);
$action  = $body['action'] ?? 'add'; // 'add' | 'remove'
$userId  = (int)$_SESSION['user_id'];

if (!$livreId) { ob_clean(); echo json_encode(['success'=>false,'error'=>'livre_id manquant']); exit; }

$pdo = null;
foreach ([dirname(__DIR__).'/includes/config.php', dirname(__DIR__).'/config/config.php'] as $cp) {
    if (file_exists($cp)) { require_once $cp; break; }
}
if (!isset($pdo)||$pdo===null) {
    $h=defined('DB_HOST')?DB_HOST:'localhost'; $n=defined('DB_NAME')?DB_NAME:'digital_library';
    $u=defined('DB_USER')?DB_USER:'root'; $p=defined('DB_PASS')?DB_PASS:'';
    try { $pdo=new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    catch(Exception $e){ ob_clean(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
}

try {
    // Créer table si absente
    $pdo->exec("CREATE TABLE IF NOT EXISTS favoris (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, livre_id INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_fav(user_id,livre_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($action === 'add') {
        $st = $pdo->prepare("INSERT IGNORE INTO favoris (user_id, livre_id) VALUES (?,?)");
        $st->execute([$userId, $livreId]);
    } else {
        $st = $pdo->prepare("DELETE FROM favoris WHERE user_id=? AND livre_id=?");
        $st->execute([$userId, $livreId]);
    }
    ob_clean();
    echo json_encode(['success'=>true,'action'=>$action,'livre_id'=>$livreId]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}