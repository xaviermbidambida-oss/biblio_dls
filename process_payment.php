<?php
/**
 * Digital Library System
 * books/ajax/process_payment.php — Traitement paiement AJAX
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';

startSession();
$user = getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Lecture JSON body
$input = json_decode(file_get_contents('php://input'), true);

$livreId = (int)($input['livre_id'] ?? 0);
$montant = (float)($input['montant'] ?? 0);
$methode = in_array($input['methode'] ?? '', ['orange_money','mobile_money','carte'])
           ? $input['methode'] : 'orange_money';

if ($livreId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Livre invalide']);
    exit;
}

$db = getDB();

// Vérifier que le livre existe
$stmt = $db->prepare("SELECT id, prix, titre FROM livres WHERE id = ? AND statut = 'disponible' LIMIT 1");
$stmt->execute([$livreId]);
$livre = $stmt->fetch();

if (!$livre) {
    http_response_code(404);
    echo json_encode(['error' => 'Livre introuvable']);
    exit;
}

// Vérifier doublon
if (hasPurchased((int)$user['id'], $livreId)) {
    echo json_encode(['ok' => true, 'reference' => 'DEJA-ACHETE', 'message' => 'Déjà acheté']);
    exit;
}

// Enregistrer l'achat
try {
    $ref = enregistrerAchat((int)$user['id'], $livreId, $montant, $methode);
    echo json_encode([
        'ok'        => true,
        'reference' => $ref,
        'livre_id'  => $livreId,
        'titre'     => $livre['titre'],
        'montant'   => $montant,
        'methode'   => $methode,
        'message'   => 'Paiement confirmé',
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur lors de l\'enregistrement']);
}