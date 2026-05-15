<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║     DIGITAL LIBRARY SYSTEM — Endpoint IA (AJAX)             ║
 * ║     Reçoit la question, interroge la BD, retourne JSON       ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/data.php';

// ── Uniquement POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

// ── Auth check ──
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié.']);
    exit;
}

// ── Lire et valider l'entrée ──
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true);
$question = isset($body['question']) ? trim(strip_tags($body['question'])) : '';

if (mb_strlen($question) < 2) {
    echo json_encode(['error' => 'Question trop courte.']);
    exit;
}
if (mb_strlen($question) > 300) {
    echo json_encode(['error' => 'Question trop longue (max 300 caractères).']);
    exit;
}

// ── Traitement ──
try {
    $user   = currentUser();
    $result = aiAnalyzeQuery($question, $user['role'], $user['id']);

    echo json_encode([
        'success'  => true,
        'answer'   => $result['answer'],
        'type'     => $result['type'],
        'question' => $question,
        'timestamp' => date('H:i'),
    ]);
} catch (Throwable $e) {
    error_log('[DLS-AI] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur. Veuillez réessayer.']);
}