<?php
/**
 * api/save_purchase.php — Enregistrement des achats
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($errno,$errstr){ ob_clean(); echo json_encode(['success'=>false,'error'=>$errstr]); exit; });

if (!isset($_SESSION['user_id'])) { ob_clean(); echo json_encode(['success'=>false,'error'=>'Non authentifié']); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST') { ob_clean(); echo json_encode(['success'=>false,'error'=>'POST requis']); exit; }

$body    = json_decode(file_get_contents('php://input'), true);
$livreId = (int)($body['livre_id'] ?? 0);
$montant = (float)($body['montant'] ?? 0);
$methode = $body['methode'] ?? 'orange_money';
$ref     = preg_replace('/[^A-Z0-9\-_]/', '', strtoupper($body['reference'] ?? ''));
$userId  = (int)$_SESSION['user_id'];

if (!$livreId || !$ref) { ob_clean(); echo json_encode(['success'=>false,'error'=>'Données manquantes']); exit; }

$pdo=null;
foreach([dirname(__DIR__).'/includes/config.php',dirname(__DIR__).'/config/config.php'] as $cp){
    if(file_exists($cp)){require_once $cp;break;}
}
if(!isset($pdo)||$pdo===null){
    $h=defined('DB_HOST')?DB_HOST:'localhost';$n=defined('DB_NAME')?DB_NAME:'digital_library';
    $u=defined('DB_USER')?DB_USER:'root';$p=defined('DB_PASS')?DB_PASS:'';
    try{$pdo=new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    catch(Exception $e){ob_clean();echo json_encode(['success'=>false,'error'=>$e->getMessage()]);exit;}
}

try {
    // Vérifier si pas déjà acheté
    $exists = $pdo->prepare("SELECT id FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme' LIMIT 1");
    $exists->execute([$userId, $livreId]);
    if ($exists->fetch()) {
        ob_clean();
        echo json_encode(['success'=>true,'already'=>true,'message'=>'Déjà acheté']);
        exit;
    }

    $validMethods = ['orange_money','mobile_money','visa','mastercard','carte_locale','wallet','coupon'];
    if (!in_array($methode, $validMethods)) $methode = 'orange_money';

    // Adapter la méthode à l'ENUM BD si nécessaire
    $methodeDB = in_array($methode, ['orange_money','mobile_money','carte']) ? $methode : 'orange_money';

    $st = $pdo->prepare("INSERT INTO achats (user_id,livre_id,montant,methode,statut,reference) VALUES (?,?,?,?,?,?)");
    $st->execute([$userId, $livreId, $montant, $methodeDB, 'confirme', $ref]);

    // Update nb_ventes
    $pdo->prepare("UPDATE livres SET nb_ventes=nb_ventes+1 WHERE id=?")->execute([$livreId]);

    // Bonus fidélité
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_bonus (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL UNIQUE, achat_count INT UNSIGNED NOT NULL DEFAULT 0, bonus_total INT UNSIGNED NOT NULL DEFAULT 0, bonus_restant INT UNSIGNED NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bonusSt = $pdo->prepare("INSERT INTO user_bonus (user_id,achat_count) VALUES (?,1) ON DUPLICATE KEY UPDATE achat_count=achat_count+1");
    $bonusSt->execute([$userId]);

    $bonusInfo = $pdo->prepare("SELECT achat_count FROM user_bonus WHERE user_id=?");
    $bonusInfo->execute([$userId]);
    $bRow = $bonusInfo->fetch();
    $bonusTriggered = false;
    if ($bRow && (int)$bRow['achat_count'] >= 5) {
        $pdo->prepare("UPDATE user_bonus SET achat_count=achat_count-5, bonus_total=bonus_total+1, bonus_restant=bonus_restant+1 WHERE user_id=?")->execute([$userId]);
        $bonusTriggered = true;
    }

    ob_clean();
    echo json_encode(['success'=>true,'reference'=>$ref,'bonus_triggered'=>$bonusTriggered]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}