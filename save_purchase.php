<?php
// ============================================================
// api/save_purchase.php — Enregistrement achat sécurisé
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Vérifier connexion utilisateur
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

// Méthode POST uniquement
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

// Lire le corps JSON
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Données invalides']);
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$livreId  = (int)($data['livre_id'] ?? 0);
$montant  = (float)($data['montant'] ?? 0);
$methode  = $data['methode'] ?? 'orange_money';
$reference = trim($data['reference'] ?? '');

// Validation
$methodesValides = ['orange_money', 'mobile_money', 'visa', 'mastercard', 'carte_locale', 'carte'];
if (!$livreId || $livreId < 1) {
    echo json_encode(['success' => false, 'message' => 'Livre invalide']);
    exit;
}
if (!in_array($methode, $methodesValides)) {
    $methode = 'orange_money';
}
if (empty($reference)) {
    $reference = 'DLS-' . strtoupper(uniqid('', true));
}

// Sanitize reference
$reference = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($reference));
if (strlen($reference) > 50) $reference = substr($reference, 0, 50);

try {
    // Vérifier que le livre existe
    $stmt = $pdo->prepare("SELECT id, titre, prix, note_moyenne FROM livres WHERE id = ? AND statut = 'disponible'");
    $stmt->execute([$livreId]);
    $livre = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$livre) {
        echo json_encode(['success' => false, 'message' => 'Livre non trouvé']);
        exit;
    }

    // Vérifier si déjà acheté
    $check = $pdo->prepare("SELECT id FROM achats WHERE user_id = ? AND livre_id = ? AND statut = 'confirme'");
    $check->execute([$userId, $livreId]);
    if ($check->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Déjà acheté', 'already_owned' => true]);
        exit;
    }

    // Insérer l'achat
    $methodeDb = in_array($methode, ['visa', 'mastercard', 'carte_locale']) ? 'carte' : $methode;
    if (!in_array($methodeDb, ['orange_money', 'mobile_money', 'carte'])) $methodeDb = 'orange_money';

    $ins = $pdo->prepare("
        INSERT INTO achats (user_id, livre_id, montant, methode, statut, reference)
        VALUES (?, ?, ?, ?, 'confirme', ?)
    ");
    $ins->execute([$userId, $livreId, $montant, $methodeDb, $reference]);

    // Incrémenter nb_ventes
    $pdo->prepare("UPDATE livres SET nb_ventes = nb_ventes + 1 WHERE id = ?")
        ->execute([$livreId]);

    // Initialiser progression lecture
    $prog = $pdo->prepare("
        INSERT IGNORE INTO lecture_progression (user_id, livre_id, page_actuelle, pourcentage)
        VALUES (?, ?, 1, 0)
    ");
    $prog->execute([$userId, $livreId]);

    echo json_encode([
        'success'   => true,
        'message'   => 'Achat enregistré avec succès',
        'reference' => $reference,
        'livre_id'  => $livreId,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
}