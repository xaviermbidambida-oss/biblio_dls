<?php
/**
 * Digital Library System
 * books/ajax/save_progress.php — Sauvegarde progression lecture
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';

startSession();
$user = getCurrentUser();

if (!$user) {
    echo json_encode(['ok' => false]);
    exit;
}

$livreId = (int)($_POST['livre_id'] ?? 0);
$page    = (int)($_POST['page']     ?? 1);
$total   = max(1, (int)($_POST['total'] ?? 1));

if ($livreId > 0) {
    sauvegarderProgression((int)$user['id'], $livreId, $page, $total);
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false]);
}