<?php
/**
 * api/search.php — Recherche de livres
 * Retourne toujours du JSON valide
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($errno,$errstr){ ob_clean(); echo json_encode(['results'=>[]]); exit; });

$q = trim(strip_tags($_GET['q'] ?? ''));
if (strlen($q) < 2 || strlen($q) > 100) {
    ob_clean(); echo json_encode(['results'=>[]]); exit;
}

$pdo=null;
foreach([dirname(__DIR__).'/includes/config.php',dirname(__DIR__).'/config/config.php'] as $cp){
    if(file_exists($cp)){require_once $cp;break;}
}
if(!isset($pdo)||$pdo===null){
    $h=defined('DB_HOST')?DB_HOST:'localhost';$n=defined('DB_NAME')?DB_NAME:'digital_library';
    $u=defined('DB_USER')?DB_USER:'root';$p=defined('DB_PASS')?DB_PASS:'';
    try{$pdo=new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
    catch(Exception $e){ob_clean();echo json_encode(['results'=>[]]);exit;}
}

try {
    $like = '%' . $q . '%';
    $st = $pdo->prepare(
        "SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, c.nom AS categorie
         FROM livres l
         LEFT JOIN categories c ON c.id=l.categorie_id
         WHERE l.statut='disponible'
           AND (l.titre LIKE ? OR l.auteur LIKE ? OR c.nom LIKE ?)
         ORDER BY l.note_moyenne DESC
         LIMIT 8"
    );
    $st->execute([$like, $like, $like]);
    $results = $st->fetchAll();

    ob_clean();
    echo json_encode(['results' => $results, 'query' => $q], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['results' => [], 'error' => $e->getMessage()]);
}