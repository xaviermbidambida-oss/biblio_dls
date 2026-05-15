<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — api/books_api.php                               ║
 * ║  API interne centralisée — Toutes les interfaces utilisent cet      ║
 * ║  endpoint pour récupérer les livres en JSON via AJAX / Fetch API    ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 *
 * ENDPOINTS :
 *   GET  ?action=all          → Tous les livres
 *   GET  ?action=featured     → Livres mis en avant
 *   GET  ?action=premium      → Livres premium
 *   GET  ?action=free         → Livres gratuits
 *   GET  ?action=bestsellers  → Bestsellers
 *   GET  ?action=categories   → Toutes les catégories
 *   GET  ?action=stats        → Statistiques dashboard
 *   GET  ?action=single&id=X  → Un livre par ID
 *   GET  ?action=search&q=X   → Recherche plein texte
 *   GET  ?action=by_category&cat_id=X → Livres d'une catégorie
 */

// Sécurité basique
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

// CORS pour les appels internes
$allowedOrigins = ['http://localhost', 'https://localhost'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

// Charger le centre de données
$seedPath = dirname(__DIR__) . '/books/seed.php';
if (!file_exists($seedPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'seed.php introuvable', 'path' => $seedPath]);
    exit;
}

define('BOOKS_SEED_NO_AUTORUN', true); // On contrôle manuellement
require_once $seedPath;
seedBooks(); // Seed si nécessaire

// Router
$action = $_GET['action'] ?? 'all';
$limit  = max(1, min(500, (int)($_GET['limit']  ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

function apiResponse(bool $success, $data, string $message = ''): void
{
    echo json_encode([
        'success'   => $success,
        'timestamp' => time(),
        'message'   => $message,
        'data'      => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

switch ($action) {
    case 'all':
        $filters = [];
        if (!empty($_GET['access_type']))  $filters['access_type']  = $_GET['access_type'];
        if (!empty($_GET['categorie_id'])) $filters['categorie_id'] = (int)$_GET['categorie_id'];
        if (!empty($_GET['min_note']))     $filters['min_note']     = (float)$_GET['min_note'];
        apiResponse(true, getAllBooks($limit, $offset, $filters));

    case 'featured':
        apiResponse(true, getFeaturedBooks($limit));

    case 'premium':
        apiResponse(true, getPremiumBooks($limit));

    case 'free':
        apiResponse(true, getFreeBooks($limit));

    case 'bestsellers':
        apiResponse(true, getBestsellers($limit));

    case 'categories':
        apiResponse(true, getAllCategories());

    case 'stats':
        apiResponse(true, getDashboardStats());

    case 'single':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            apiResponse(false, null, 'ID requis');
        }
        $book = getBookById($id);
        if (!$book) {
            http_response_code(404);
            apiResponse(false, null, 'Livre introuvable');
        }
        apiResponse(true, $book);

    case 'search':
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            http_response_code(400);
            apiResponse(false, [], 'Requête trop courte (min 2 caractères)');
        }
        apiResponse(true, searchBooks($q, $limit));

    case 'by_category':
        $catId = (int)($_GET['cat_id'] ?? 0);
        if (!$catId) {
            http_response_code(400);
            apiResponse(false, null, 'cat_id requis');
        }
        apiResponse(true, getBooksByCategory($catId, $limit));

    default:
        http_response_code(400);
        apiResponse(false, null, 'Action inconnue : ' . htmlspecialchars($action));
}