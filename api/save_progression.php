<?php
/**
 * api/save_progression.php — Sauvegarde de la progression de lecture
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($errno,$errstr){ ob_clean(); echo json_encode(['success'=>false]); exit; });

if (!isset($_SESSION['user_id'])) { ob_clean(); echo json_encode(['success'=>false]); exit; }

$body  = json_decode(file_get_contents('php://input'), true);
$livreId = (int)($body['livre_id'] ?? 0);
$page    = (int)($body['page'] ?? 1);
$total   = (int)($body['total'] ?? 1);
$userId  = (int)$_SESSION['user_id'];

if (!$livreId) { ob_clean(); echo json_encode(['success'=>false]); exit; }

$pct = $total > 0 ? round(($page / $total) * 100, 2) : 0;

$pdo=null;
foreach([dirname(__DIR__).'/includes/config.php',dirname(__DIR__).'/config/config.php'] as $cp){
    if(file_exists($cp)){require_once $cp;break;}
}
if(!isset($pdo)||$pdo===null){
    $h=defined('DB_HOST')?DB_HOST:'localhost';$n=defined('DB_NAME')?DB_NAME:'digital_library';
    $u=defined('DB_USER')?DB_USER:'root';$p=defined('DB_PASS')?DB_PASS:'';
    try{$pdo=new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    catch(Exception $e){ob_clean();echo json_encode(['success'=>false]);exit;}
}

try {
    // Table historique_lecture ou lecture_progression
    $tables = $pdo->query("SHOW TABLES LIKE 'historique_lecture'")->fetchColumn();
    $tableName = $tables ? 'historique_lecture' : 'lecture_progression';

    if ($tableName === 'historique_lecture') {
        $st = $pdo->prepare("INSERT INTO historique_lecture (user_id,livre_id,page_actuelle,total_pages,pourcentage) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE page_actuelle=?, total_pages=?, pourcentage=?, derniere_lecture=NOW()");
        $st->execute([$userId,$livreId,$page,$total,$pct,$page,$total,$pct]);
    } else {
        $st = $pdo->prepare("INSERT INTO lecture_progression (user_id,livre_id,page_actuelle,pourcentage) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE page_actuelle=?, pourcentage=?, updated_at=NOW()");
        $st->execute([$userId,$livreId,$page,$pct,$page,$pct]);
    }

    ob_clean();
    echo json_encode(['success'=>true,'page'=>$page,'pourcentage'=>$pct]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}