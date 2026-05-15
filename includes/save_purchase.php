<?php
// ============================================================
// DIGITAL LIBRARY — api/save_purchase.php
// Endpoint AJAX : traite un achat réel via purchase_service
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

// Sécurité : utilisateur connecté obligatoire
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Lire le JSON du body
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['livre_id'])) {
    echo json_encode(['success' => false, 'message' => 'Données invalides.']);
    exit;
}

$livreId  = (int)$data['livre_id'];
$montant  = isset($data['montant'])  ? (float)$data['montant']  : 0;
$methode  = isset($data['methode'])  ? (string)$data['methode'] : 'orange_money';
$reference= isset($data['reference'])? (string)$data['reference']: null;

// Méthodes autorisées
$methodesAutorisees = ['orange_money','mobile_money','visa','mastercard','carte_locale','bonus'];
if (!in_array($methode, $methodesAutorisees)) $methode = 'orange_money';

// Connexion PDO
$pdo = null;
$configPath = dirname(__DIR__) . '/includes/config.php';
if (file_exists($configPath)) require_once $configPath;

if (!isset($pdo) || $pdo === null) {
    $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db_name = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $db_user = defined('DB_USER') ? DB_USER : 'root';
    $db_pass = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user, $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Connexion BD impossible.']);
        exit;
    }
}

require_once dirname(__DIR__) . '/includes/purchase_service.php';

// Si une référence JS est fournie, utiliser processPurchase avec override
$result = processPurchase($pdo, $userId, $livreId);

// Si une référence JS est fournie (générée côté client), mettre à jour la référence
if ($result['success'] && $reference) {
    try {
        $pdo->prepare("UPDATE achats SET reference = ?, methode = ? WHERE user_id = ? AND livre_id = ? AND statut = 'confirme' ORDER BY created_at DESC LIMIT 1")
            ->execute([$reference, $methode, $userId, $livreId]);
    } catch (PDOException $e) {}
}

// Retourner le résultat
echo json_encode([
    'success'   => $result['success'],
    'message'   => $result['message'],
    'reference' => $result['reference'] ?? $reference,
    'bonus'     => $result['bonus'] ?? false,
    'achats_count' => getUserPurchaseCount($pdo, $userId),
]);