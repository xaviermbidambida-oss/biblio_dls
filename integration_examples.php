<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — integration_examples.php                        ║
 * ║  Exemples d'intégration de books/seed.php dans toutes les pages    ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 *
 * CE FICHIER EST UN GUIDE DE RÉFÉRENCE — pas à déployer tel quel.
 * Copiez le bloc correspondant à chaque page de votre application.
 */

// ══════════════════════════════════════════════════════════════════
// ▶ INTÉGRATION 1 : index.php (Homepage)
// ══════════════════════════════════════════════════════════════════
/*
<?php
require_once __DIR__ . '/books/seed.php';   // ← UNE SEULE LIGNE

$featuredBooks  = getFeaturedBooks(8);       // Livres à la une
$freeBooks      = getFreeBooks(6);           // Section gratuits
$bestsellers    = getBestsellers(10);        // Bestsellers
$stats          = getDashboardStats();       // Stats pour le header
$categories     = getAllCategories();        // Catégories avec comptage
?>
<!DOCTYPE html>
<html>
<body>

<!-- Hero Section avec livres featured -->
<section class="hero">
    <h1><?= $stats['total_livres'] ?>+ livres disponibles</h1>
    <div class="featured-grid">
        <?php foreach ($featuredBooks as $book): ?>
        <div class="book-card"
             data-id="<?= $book['id'] ?>"
             data-prix="<?= $book['prix'] ?>">
            <div class="cover"><?= $book['categorie_icone'] ?></div>
            <h3><?= htmlspecialchars($book['titre']) ?></h3>
            <p>par <?= htmlspecialchars($book['auteur']) ?></p>
            <span class="badge <?= $book['access_type'] ?>">
                <?= strtoupper($book['access_type']) ?>
            </span>
            <div class="stars"><?= starsHtml($book['note_moyenne']) ?></div>
            <div class="price"><?= formatPrice($book['prix']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Section Gratuits -->
<section class="free-books">
    <h2>📗 Livres gratuits</h2>
    <div class="books-row">
        <?php foreach ($freeBooks as $book): ?>
        <div class="book-card">
            <h3><?= htmlspecialchars($book['titre']) ?></h3>
            <p><?= htmlspecialchars($book['auteur']) ?></p>
            <span class="badge gratuit">GRATUIT</span>
        </div>
        <?php endforeach; ?>
    </div>
</section>

</body>
</html>
*/


// ══════════════════════════════════════════════════════════════════
// ▶ INTÉGRATION 2 : books/index.php (Catalogue complet)
// ══════════════════════════════════════════════════════════════════
/*
<?php
require_once __DIR__ . '/../books/seed.php';

$allBooks   = getAllBooks(200);   // Tous les livres (limité à 200 pour le rendu PHP)
$categories = getAllCategories();
$stats      = getDashboardStats();

// Filtrage optionnel via GET
if (!empty($_GET['cat'])) {
    $allBooks = getBooksByCategory((int)$_GET['cat'], 100);
}
if (!empty($_GET['type'])) {
    $allBooks = getAllBooks(100, 0, ['access_type' => $_GET['type']]);
}
if (!empty($_GET['q'])) {
    $allBooks = searchBooks($_GET['q'], 50);
}
?>

<!-- Rendu des cartes -->
<?php foreach ($allBooks as $book): ?>
<div class="book-card"
     data-id="<?= $book['id'] ?>"
     data-titre="<?= htmlspecialchars($book['titre'], ENT_QUOTES) ?>"
     data-auteur="<?= htmlspecialchars($book['auteur'], ENT_QUOTES) ?>"
     data-prix="<?= $book['prix'] ?>"
     data-note="<?= $book['note_moyenne'] ?>"
     data-access="<?= $book['access_type'] ?>"
     data-cat="<?= $book['categorie_id'] ?>">

    <div class="book-cover" style="background: linear-gradient(135deg, #0d1f3c, #1a4a7a)">
        <span class="cover-icon"><?= $book['categorie_icone'] ?></span>
        <?php $badge = getAccessBadge($book); ?>
        <span class="price-badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
        <?php if ($book['is_bestseller']): ?>
        <span class="bestseller-tag">🏆 BESTSELLER</span>
        <?php endif; ?>
    </div>

    <div class="book-body">
        <div class="book-genre"><?= htmlspecialchars($book['categorie_nom']) ?></div>
        <h3 class="book-title"><?= htmlspecialchars($book['titre']) ?></h3>
        <p class="book-author">par <?= htmlspecialchars($book['auteur']) ?></p>
        <div class="book-meta">
            <span class="stars"><?= starsHtml((float)$book['note_moyenne']) ?></span>
            <span class="note"><?= number_format($book['note_moyenne'], 1) ?></span>
            <span class="pages"><?= $book['pages'] ?>p</span>
        </div>
        <div class="book-price"><?= formatPrice((float)$book['prix']) ?></div>
        <div class="book-stats">
            <span>📖 <?= number_format($book['nb_lectures']) ?> lectures</span>
            <span>⬇️ <?= number_format($book['nb_telechargements']) ?> téléchargements</span>
        </div>
    </div>
</div>
<?php endforeach; ?>
*/


// ══════════════════════════════════════════════════════════════════
// ▶ INTÉGRATION 3 : dashboard.php (Tableau de bord admin/lecteur)
// ══════════════════════════════════════════════════════════════════
/*
<?php
require_once __DIR__ . '/books/seed.php';

$stats       = getDashboardStats();
$featured    = getFeaturedBooks(4);
$bestsellers = getBestsellers(5);
$categories  = getAllCategories();
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['total_livres']) ?></div>
        <div class="stat-label">Livres disponibles</div>
    </div>
    <div class="stat-card premium">
        <div class="stat-value"><?= number_format($stats['premium']) ?></div>
        <div class="stat-label">Premium</div>
    </div>
    <div class="stat-card free">
        <div class="stat-value"><?= number_format($stats['gratuit']) ?></div>
        <div class="stat-label">Gratuits</div>
    </div>
    <div class="stat-card sales">
        <div class="stat-value"><?= number_format($stats['nb_ventes']) ?></div>
        <div class="stat-label">Ventes totales</div>
    </div>
    <div class="stat-card revenue">
        <div class="stat-value"><?= number_format($stats['revenus_mois']) ?> FCFA</div>
        <div class="stat-label">Revenus du mois</div>
    </div>
</div>

<!-- Achats récents -->
<div class="recent-sales">
    <h3>Achats récents</h3>
    <?php foreach ($stats['recents'] as $achat): ?>
    <div class="sale-item">
        <span><?= htmlspecialchars($achat['titre']) ?></span>
        <span><?= number_format($achat['montant']) ?> FCFA</span>
        <span><?= date('d/m H:i', strtotime($achat['created_at'])) ?></span>
    </div>
    <?php endforeach; ?>
</div>
*/


// ══════════════════════════════════════════════════════════════════
// ▶ INTÉGRATION 4 : explorer.php (Page exploration / recherche)
// ══════════════════════════════════════════════════════════════════
/*
<?php
require_once __DIR__ . '/books/seed.php';

$query      = trim($_GET['q'] ?? '');
$type       = $_GET['type'] ?? 'all';
$catId      = (int)($_GET['cat'] ?? 0);

if ($query) {
    $books = searchBooks($query, 50);
} elseif ($catId) {
    $books = getBooksByCategory($catId, 50);
} elseif ($type === 'premium') {
    $books = getPremiumBooks(50);
} elseif ($type === 'gratuit') {
    $books = getFreeBooks(50);
} else {
    $books = getAllBooks(50);
}

$categories = getAllCategories();
?>
<form method="GET">
    <input name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Rechercher un livre, auteur...">
    <select name="cat">
        <option value="">Toutes catégories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= $catId == $cat['id'] ? 'selected' : '' ?>>
            <?= $cat['icone'] ?> <?= htmlspecialchars($cat['nom']) ?>
            (<?= $cat['nb_livres'] ?>)
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">🔍 Rechercher</button>
</form>

<p><?= count($books) ?> résultat(s)<?= $query ? ' pour "' . htmlspecialchars($query) . '"' : '' ?></p>

<?php foreach ($books as $book): ?>
<!-- Carte livre avec données complètes -->
<?php endforeach; ?>
*/


// ══════════════════════════════════════════════════════════════════
// ▶ INTÉGRATION 5 : reader.php (Lecteur intégré)
// ══════════════════════════════════════════════════════════════════
/*
<?php
require_once __DIR__ . '/books/seed.php';

$bookId = (int)($_GET['id'] ?? 0);
if (!$bookId) {
    header('Location: books/index.php');
    exit;
}

$book = getBookById($bookId);
if (!$book) {
    header('Location: books/index.php?error=not_found');
    exit;
}

// Vérification accès (logique adaptée à votre système d'auth)
$userId     = $_SESSION['user_id'] ?? 0;
$userRole   = $_SESSION['user_role'] ?? 'lecteur';
$hasPurchased = false;

if ($userId) {
    $pdo = getLibraryPDO();
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT id FROM achats WHERE user_id = ? AND livre_id = ? AND statut = 'confirme' LIMIT 1");
        $stmt->execute([$userId, $bookId]);
        $hasPurchased = (bool)$stmt->fetch();
    }
}

$canRead = ($userRole === 'admin')
        || $hasPurchased
        || ($book['access_type'] === 'gratuit')
        || ($userRole === 'journaliste' && $book['note_moyenne'] < 4.5);

if (!$canRead) {
    header('Location: books/index.php?payment=required&id=' . $bookId);
    exit;
}

// Préparer les pages de l'extrait
$pages = explode('||||PAGE||||', $book['contenu_extrait'] ?? '');
$pages = array_filter(array_map('trim', $pages));
if (empty($pages)) {
    $pages = ['Aucun contenu disponible pour ce livre.'];
}
?>

<h1><?= htmlspecialchars($book['titre']) ?></h1>
<h2>par <?= htmlspecialchars($book['auteur']) ?></h2>

<div id="reader-content">
    <?php foreach ($pages as $i => $page): ?>
    <div class="page" data-page="<?= $i + 1 ?>" <?= $i > 0 ? 'style="display:none"' : '' ?>>
        <?= nl2br(htmlspecialchars($page)) ?>
    </div>
    <?php endforeach; ?>
</div>

<div class="reader-nav">
    <button id="prev" disabled>← Précédente</button>
    <span id="page-info">Page 1 / <?= count($pages) ?></span>
    <button id="next">Suivante →</button>
</div>
*/


// ══════════════════════════════════════════════════════════════════
// ▶ INTÉGRATION 6 : recommandations-ia.php (IA de recommandation)
// ══════════════════════════════════════════════════════════════════
/*
<?php
require_once __DIR__ . '/books/seed.php';

$userId   = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'lecteur';

// Récupérer les achats de l'utilisateur pour les recommandations
$purchasedIds = [];
$pdo = getLibraryPDO();
if ($pdo && $userId) {
    $stmt = $pdo->prepare("SELECT livre_id FROM achats WHERE user_id = ? AND statut = 'confirme'");
    $stmt->execute([$userId]);
    $purchasedIds = array_column($stmt->fetchAll(), 'livre_id');
}

// Recommandation : livres bien notés que l'utilisateur n'a pas encore
$allBooks   = getAllBooks(100, 0, ['min_note' => 4.0]);
$recommended = array_filter($allBooks, fn($b) => !in_array($b['id'], $purchasedIds));
$recommended = array_slice(array_values($recommended), 0, 12);

// Bestsellers dans les catégories achetées
$boughtCats  = array_unique(array_column(
    array_filter($allBooks, fn($b) => in_array($b['id'], $purchasedIds)),
    'categorie_id'
));
$sameCatBooks = array_filter($recommended, fn($b) => in_array($b['categorie_id'], $boughtCats));
?>

<h2>📚 Recommandés pour vous</h2>
<?php foreach (array_slice(array_values($sameCatBooks), 0, 6) as $book): ?>
<div class="book-card recommended">
    <div class="cover"><?= $book['categorie_icone'] ?></div>
    <h3><?= htmlspecialchars($book['titre']) ?></h3>
    <p>par <?= htmlspecialchars($book['auteur']) ?></p>
    <div class="stars"><?= starsHtml($book['note_moyenne']) ?></div>
    <div class="price"><?= formatPrice($book['prix']) ?></div>
    <span class="badge <?= $book['access_type'] ?>"><?= strtoupper($book['access_type']) ?></span>
</div>
<?php endforeach; ?>
*/


// ══════════════════════════════════════════════════════════════════
// ▶ INTÉGRATION 7 : AJAX / Fetch API (JavaScript)
// ══════════════════════════════════════════════════════════════════
/*
// Dans n'importe quel fichier JS ou <script> :

// Récupérer tous les livres
async function loadAllBooks() {
    const res  = await fetch('/api/books_api.php?action=all&limit=50');
    const json = await res.json();
    if (json.success) {
        renderBooks(json.data);
    }
}

// Recherche live
async function searchBooks(query) {
    const res  = await fetch(`/api/books_api.php?action=search&q=${encodeURIComponent(query)}`);
    const json = await res.json();
    return json.success ? json.data : [];
}

// Livres d'une catégorie
async function loadCategory(catId) {
    const res  = await fetch(`/api/books_api.php?action=by_category&cat_id=${catId}`);
    const json = await res.json();
    return json.success ? json.data : [];
}

// Un livre par ID
async function getBook(id) {
    const res  = await fetch(`/api/books_api.php?action=single&id=${id}`);
    const json = await res.json();
    return json.success ? json.data : null;
}

// Stats dashboard en temps réel
async function refreshStats() {
    const res  = await fetch('/api/books_api.php?action=stats');
    const json = await res.json();
    if (json.success) {
        document.getElementById('total-livres').textContent = json.data.total_livres;
        document.getElementById('nb-premium').textContent   = json.data.premium;
        document.getElementById('revenus').textContent      = json.data.revenus_mois + ' FCFA';
    }
}

// Polling toutes les 8 secondes
setInterval(refreshStats, 8000);
*/

echo "<!-- integration_examples.php chargé — Ce fichier est un guide de référence -->";