<?php
// ============================================================
// api/books.php — API livres dynamique (JSON)
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../includes/config.php';

$action   = $_GET['action'] ?? 'list';
$catId    = (int)($_GET['cat'] ?? 0);
$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(24, max(6, (int)($_GET['per'] ?? 12)));
$offset   = ($page - 1) * $perPage;

try {
    switch ($action) {

        case 'list':
        case 'featured':
            $where = ["l.statut = 'disponible'"];
            $params = [];

            if ($catId > 0) {
                $where[] = "l.categorie_id = ?";
                $params[] = $catId;
            }
            if ($search !== '') {
                $where[] = "(l.titre LIKE ? OR l.auteur LIKE ? OR l.description LIKE ?)";
                $like = '%' . $search . '%';
                $params = array_merge($params, [$like, $like, $like]);
            }

            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // Count
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM livres l $whereSQL");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();

            // Data
            $orderBy = $action === 'featured'
                ? 'ORDER BY l.note_moyenne DESC, l.nb_ventes DESC'
                : 'ORDER BY l.created_at DESC';

            $stmt = $pdo->prepare("
                SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne,
                       l.nb_ventes, l.pages, l.couverture,
                       SUBSTR(l.contenu_extrait,1,500) AS extrait,
                       c.nom AS genre, c.icone AS genre_icone
                FROM livres l
                LEFT JOIN categories c ON c.id = l.categorie_id
                $whereSQL
                $orderBy
                LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$perPage, $offset]));
            $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data'    => $books,
                'meta'    => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => ceil($total / $perPage)],
            ]);
            break;

        case 'detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID requis']); break; }

            $stmt = $pdo->prepare("
                SELECT l.*, c.nom AS genre, c.icone AS genre_icone
                FROM livres l LEFT JOIN categories c ON c.id=l.categorie_id
                WHERE l.id=? AND l.statut='disponible'
            ");
            $stmt->execute([$id]);
            $book = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$book) { echo json_encode(['success'=>false,'message'=>'Livre introuvable']); break; }

            // Check if user owns the book
            $owned = false;
            if (isset($_SESSION['user_id'])) {
                $ck = $pdo->prepare("SELECT id FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme'");
                $ck->execute([$_SESSION['user_id'], $id]);
                $owned = (bool)$ck->fetch();
            }

            $book['owned'] = $owned;
            // Only include full content if owned or free
            if (!$owned && (float)$book['prix'] > 0 && (float)$book['note_moyenne'] > 2.0) {
                $book['contenu_extrait'] = substr($book['contenu_extrait'] ?? '', 0, 500) . "\n\n[Achetez ce livre pour lire la suite…]";
                $book['fichier_pdf'] = null;
            }

            echo json_encode(['success' => true, 'data' => $book]);
            break;

        case 'trending':
            $limit = min(10, max(3, (int)($_GET['limit'] ?? 5)));
            $stmt = $pdo->query("
                SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne,
                       l.nb_ventes, c.icone
                FROM livres l LEFT JOIN categories c ON c.id=l.categorie_id
                WHERE l.statut='disponible'
                ORDER BY l.nb_ventes DESC
                LIMIT $limit
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'categories':
            $stmt = $pdo->query("
                SELECT c.id, c.nom, c.icone, c.slug,
                       COUNT(l.id) AS nb_livres
                FROM categories c
                LEFT JOIN livres l ON l.categorie_id=c.id AND l.statut='disponible'
                GROUP BY c.id ORDER BY c.id
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
}