<?php
/**
 * explorer.php — Digital Library System
 * Module catalogue complet avec filtres, rôles, paiement sécurisé
 * VERSION CORRIGÉE & OPTIMISÉE
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Session & sécurité ───────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();



// Régénérer l'ID de session si vieux (protection fixation)
if (!isset($_SESSION['session_init'])) {
    session_regenerate_id(true);
    $_SESSION['session_init'] = true;
}



// ── Connexion BDD ────────────────────────────────────────────────────
$pdo = null;
$configPath = __DIR__ . '/includes/config.php';
if (file_exists($configPath)) { require_once $configPath; }

if (!isset($pdo) || $pdo === null) {
    $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db_name = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $db_user = defined('DB_USER') ? DB_USER : 'root';
    $db_pass = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        $pdo = null;
    }
}




// require_once __DIR__ . '/books/seed.php';


// ── Identité utilisateur ─────────────────────────────────────────────
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin    = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$isJournalist = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'journaliste';
$userRole   = $_SESSION['user_role'] ?? 'lecteur';
$userId     = $_SESSION['user_id'] ?? null;

$username = 'Lecteur';

if (!empty($_SESSION['user_name'])) {
    $username = htmlspecialchars($_SESSION['user_name']);
} elseif (!empty($_SESSION['user_prenom']) && !empty($_SESSION['user_nom'])) {
    $username = htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']);
}

// ── Paramètres de filtre (GET — nettoyés) ────────────────────────────
$search      = trim(strip_tags($_GET['q']     ?? ''));
$filterType  = in_array($_GET['filter'] ?? '', ['all','gratuit','premium','top','recents']) 
               ? ($_GET['filter'] ?? 'all') : 'all';
$catId       = isset($_GET['cat']) && ctype_digit($_GET['cat']) ? (int)$_GET['cat'] : 0;
$page        = isset($_GET['page']) && ctype_digit($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$perPage     = 20;
$offset      = ($page - 1) * $perPage;

// ── Données démo (fallback si BDD absente) ───────────────────────────
function getDemoBooks(): array {
    $titles = [
        "L'Œil de l'Univers","Le Paradoxe du Libre Arbitre","Forêts Oubliées","IA & Humanité",
        "Empires Disparus","Masques & Miroirs","La Dernière Lumière","Chroniques du Vent",
        "L'Architecte du Temps","Mémoires Fractales","Le Silence des Dieux","Océans Numériques",
        "Les Gardiens du Seuil","Fragments d'Éternité","Le Dernier Oracle","Horizons Infinis",
        "Code Mortel","La Voix du Vide","Terres Brûlées","Algorithme Vivant","Poussière de Rêves",
        "L'Équilibre Rompu","Neuro-Chimère","Éclats de Vérité","La Carte du Monde Perdu",
    ];
    $authors = [
        "Elena Korvach","Jean-Marc Duvall","Amara Diallo","Dr. Kai Tanaka","Sofia Mercier",
        "Léon Beaumont","Isabelle Renaud","Marcus Osei","Yuki Tanaka","Priya Nair",
        "Diego Vasquez","Clara Hoffmann","Amine Berrada","Fatou Sow","Pavel Novak",
    ];
    $genres = [
        ['id'=>1,'nom'=>'Science-Fiction','icone'=>'🌌'],
        ['id'=>2,'nom'=>'Philosophie','icone'=>'🧠'],
        ['id'=>3,'nom'=>'Nature','icone'=>'🌿'],
        ['id'=>4,'nom'=>'Technologie','icone'=>'⚙️'],
        ['id'=>5,'nom'=>'Histoire','icone'=>'📜'],
        ['id'=>6,'nom'=>'Littérature','icone'=>'🎭'],
    ];
    $emojis = ['📘','📙','📗','📕','📔','📒','📓','🔮','💡','🌊','🏔️','⚡'];

    $books = [];
    for ($i = 0; $i < 24; $i++) {
        $note = round(1.0 + mt_rand(0, 40) / 10, 1);
        // Forcer quelques gratuits (notes basses ou aléatoire)
        $isFree = ($note <= 2.0) || (mt_rand(0, 3) === 0);
        $prix   = $isFree ? 0 : mt_rand(1, 8) * 1000;
        $genre  = $genres[$i % count($genres)];

        $books[] = [
            'id'              => $i + 1,
            'titre'           => $titles[$i % count($titles)],
            'auteur'          => $authors[$i % count($authors)],
            'prix'            => $prix,
            'note_moyenne'    => $note,
            'nb_ventes'       => mt_rand(100, 9999),
            'pages'           => mt_rand(120, 600),
            'annee_parution'  => mt_rand(2018, 2024),
            'categorie_id'    => $genre['id'],
            'genre'           => $genre['nom'],
            'genre_icone'     => $genre['icone'],
            'emoji'           => $emojis[$i % count($emojis)],
            'contenu_extrait' => generateDemoExtrait($titles[$i % count($titles)], $genre['nom']),
        ];
    }
    return $books;
}

function getDemoCategories(): array {
    return [
        ['id'=>1,'nom'=>'Science-Fiction','icone'=>'🌌','slug'=>'sf'],
        ['id'=>2,'nom'=>'Philosophie','icone'=>'🧠','slug'=>'philo'],
        ['id'=>3,'nom'=>'Nature','icone'=>'🌿','slug'=>'nature'],
        ['id'=>4,'nom'=>'Technologie','icone'=>'⚙️','slug'=>'tech'],
        ['id'=>5,'nom'=>'Histoire','icone'=>'📜','slug'=>'histoire'],
        ['id'=>6,'nom'=>'Littérature','icone'=>'🎭','slug'=>'lit'],
    ];
}

function generateDemoExtrait(string $titre = '', string $genre = 'général'): string {

    $titre = !empty($titre) ? $titre : 'Titre inconnu';
    $genre = !empty($genre) ? $genre : 'général';

    $chapitres = [
        ["L'Éveil", "La découverte initiale plonge le lecteur dans un univers fascinant."],
        ["La Découverte", "Les semaines suivantes furent rythmées par une succession de révélations."],
        ["Le Tournant", "Un événement inattendu bouleverse l'équilibre fragile."],
        ["Les Révélations", "Les vérités cachées émergent enfin."],
        ["L'Épreuve", "Le climax approche. Cette œuvre de %s tient toutes ses promesses."],
        ["Le Dénouement", "La résolution apporte la satisfaction attendue."],
        ["Épilogue", "Merci d'avoir partagé ce voyage au cœur de «%s»."]
    ];

    $pages = [];

foreach ($chapitres as $idx => $chap) {

    $texte = $chap[1];

    if ($idx == 4) {
        $texte = sprintf($texte, $genre ?? 'général');
    }

    if ($idx == 6) {
        $texte = sprintf($texte, $titre ?? 'Titre inconnu');
    }

    $pages[] = "CHAPITRE " . ($idx+1) . " — " . $chap[0] . "\n\n" . $texte;
}

    $labels = ["L'Éveil","La Découverte","Le Tournant","Les Révélations","L'Épreuve","Le Dénouement","Épilogue"];

    $pages = [];

    foreach ($chapitres as $idx => $chap) {
        $pages[] = "CHAPITRE " . ($idx + 1) . " — " . $labels[$idx] . "\n\n" . $chap[1];
    }

    return implode('||||PAGE||||', $pages);
}

// ── Requêtes BDD ou démo ─────────────────────────────────────────────
$allBooks   = [];
$categories = [];
$totalCount = 0;

if ($pdo !== null) {
    try {
        // ── Catégories
        $categories = $pdo->query("
            SELECT c.id, c.nom, c.icone, c.slug,
                   COUNT(l.id) AS nb_livres
            FROM categories c
            LEFT JOIN livres l ON l.categorie_id = c.id AND l.statut = 'disponible'
            GROUP BY c.id, c.nom, c.icone, c.slug
            ORDER BY c.nom
        ")->fetchAll();

        // ── Construire la requête dynamique (sécurisée par paramètres liés)
        $where  = ["l.statut = 'disponible'"];
        $params = [];

        if ($search !== '') {
            $where[]  = "(l.titre LIKE :search OR l.auteur LIKE :search2 OR l.description LIKE :search3)";
            $params[':search']  = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
            $params[':search3'] = '%' . $search . '%';
        }

        if ($catId > 0) {
            $where[]         = "l.categorie_id = :catId";
            $params[':catId'] = $catId;
        }

        switch ($filterType) {
            case 'gratuit':
                $where[] = "l.prix = 0";
                break;
            case 'premium':
                $where[] = "l.prix > 0";
                break;
            case 'top':
                $where[] = "l.note_moyenne >= 4.0";
                break;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $orderSQL = match($filterType) {
            'top'     => 'ORDER BY l.note_moyenne DESC, l.nb_ventes DESC',
            'recents' => 'ORDER BY l.annee_parution DESC, l.id DESC',
            default   => 'ORDER BY l.nb_ventes DESC, l.note_moyenne DESC',
        };

        // Compter le total (pour pagination)
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            $whereSQL
        ");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Récupérer la page courante
        $params[':limit']  = $perPage;
        $params[':offset'] = $offset;
        $stmt = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes,
                   l.pages, l.annee_parution, l.contenu_extrait, l.description,
                   c.id AS categorie_id, c.nom AS genre, c.icone AS genre_icone
            FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            $whereSQL
            $orderSQL
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            if ($k !== ':limit' && $k !== ':offset') $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $allBooks = $stmt->fetchAll();

    } catch (PDOException $e) {
        $pdo = null;
    }
}

// Fallback démo si pas de BDD ou résultats vides
if ($pdo === null || empty($allBooks)) {
    $demoBooks  = getDemoBooks();
    $categories = getDemoCategories();

    // Appliquer les filtres en PHP sur les données démo
    $filtered = array_filter($demoBooks, function($b) use ($search, $filterType, $catId) {
        if ($search !== '' && stripos($b['titre'].$b['auteur'], $search) === false) return false;
        if ($catId > 0 && (int)$b['categorie_id'] !== $catId) return false;
        switch ($filterType) {
            case 'gratuit':  return $b['prix'] == 0;
            case 'premium':  return $b['prix'] > 0;
            case 'top':      return $b['note_moyenne'] >= 4.0;
        }
        return true;
    });

    // Tri démo
    usort($filtered, function($a, $b) use ($filterType) {
        if ($filterType === 'top')     return $b['note_moyenne'] <=> $a['note_moyenne'];
        if ($filterType === 'recents') return $b['annee_parution'] <=> $a['annee_parution'];
        return $b['nb_ventes'] <=> $a['nb_ventes'];
    });

    $totalCount = count($filtered);
    $allBooks   = array_slice(array_values($filtered), $offset, $perPage);

    // Ajouter emoji aux livres démo
    $emojis = ['📘','📙','📗','📕','📔','📒','📓','🔮','💡','🌊','🏔️','⚡'];
    foreach ($allBooks as $i => &$b) {
        if (!isset($b['emoji'])) $b['emoji'] = $emojis[$i % count($emojis)];
    }
    unset($b);
}

// ── Aides d'affichage ────────────────────────────────────────────────
$totalPages  = max(1, (int)ceil($totalCount / $perPage));
$coverColors = [
    ['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],
    ['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],
    ['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],['#0d3c2a','#1a7a5a'],
    ['#2a0d1a','#6b1a3a'],['#0d1a3c','#1a3a7a'],['#3c0d2a','#7a1a5a'],
];
$emojis = ['📘','📙','📗','📕','📔','📒','📓','🔮','💡','🌊','🏔️','⚡'];

function getPriceBadge(float $note, float $prix): array {
    if ($prix == 0 || $note <= 2.0) return ['label'=>'GRATUIT','class'=>'badge-free','isFree'=>true];
    if ($note >= 4.0)               return ['label'=>'PREMIUM','class'=>'badge-premium','isFree'=>false];
    return ['label'=>'STANDARD','class'=>'badge-std','isFree'=>false];
}
function starsHtml(float $note): string {
    $r = round($note);
    return str_repeat('★', min(5, $r)) . str_repeat('☆', max(0, 5 - $r));
}
function buildUrl(array $override = []): string {
    global $search, $filterType, $catId, $page;
    $params = array_filter([
        'q'      => $override['q']      ?? ($search      !== '' ? $search      : null),
        'filter' => $override['filter'] ?? ($filterType  !== 'all' ? $filterType : null),
        'cat'    => $override['cat']    ?? ($catId        > 0     ? $catId        : null),
        'page'   => $override['page']   ?? null,
    ]);
    return 'explorer.php' . ($params ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Explorer le Catalogue — Digital Library</title>
<meta name="description" content="Explorez des milliers d'ouvrages. Filtrez par genre, prix, note.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#070b14;--paper:#0d1220;--slate:#131a2e;
  --mist:rgba(200,210,255,0.08);--fog:rgba(200,210,255,0.04);
  --gold:#e8c97d;--ember:#ff6b35;--sage:#4ecca3;--azure:#4a9eff;--plum:#9b59b6;
  --txt-primary:#f0eeea;--txt-secondary:rgba(240,238,234,0.55);--txt-muted:rgba(240,238,234,0.3);
  --glass:rgba(255,255,255,0.03);--glass-border:rgba(255,255,255,0.07);
  --ease-spring:cubic-bezier(0.34,1.56,0.64,1);--ease-smooth:cubic-bezier(0.25,0.46,0.45,0.94);
}
html{scroll-behavior:smooth}
body{font-family:'Cabinet Grotesk',sans-serif;background:var(--ink);color:var(--txt-primary);overflow-x:hidden;min-height:100vh}
::-webkit-scrollbar{width:3px;height:3px}::-webkit-scrollbar-track{background:var(--ink)}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* BG */
.bg-orb{position:fixed;border-radius:50%;filter:blur(120px);pointer-events:none;z-index:-1;animation:orbDrift 30s ease-in-out infinite}
.orb-a{width:600px;height:600px;background:rgba(232,201,125,0.04);top:-150px;left:-100px}
.orb-b{width:450px;height:450px;background:rgba(74,158,255,0.04);bottom:-80px;right:-80px;animation-delay:-14s}
@keyframes orbDrift{0%,100%{transform:translate(0,0)}50%{transform:translate(30px,-30px)}}

/* HEADER */
#site-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:62px;padding:0 2rem;display:flex;align-items:center;justify-content:space-between;background:rgba(7,11,20,0.88);backdrop-filter:blur(24px);border-bottom:1px solid var(--glass-border);transition:background .3s}
#site-header.scrolled{background:rgba(7,11,20,0.98)}
.nav-logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--txt-primary);font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600}
.logo-mark{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));display:flex;align-items:center;justify-content:center;font-size:.9rem}
.nav-links{display:flex;align-items:center;gap:2px;list-style:none}
.nav-links a{font-size:.8rem;font-weight:500;color:var(--txt-secondary);text-decoration:none;padding:6px 14px;border-radius:8px;transition:all .2s}
.nav-links a:hover,.nav-links a.active{color:var(--gold);background:var(--mist)}
.nav-actions{display:flex;align-items:center;gap:.6rem}
.btn-ghost{font-size:.78rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;padding:7px 16px;border-radius:8px;border:1px solid var(--glass-border);transition:all .2s}
.btn-ghost:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.btn-cta-nav{font-size:.78rem;font-weight:700;color:var(--ink);text-decoration:none;padding:7px 18px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember))}
.user-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 5px;background:var(--mist);border:1px solid var(--glass-border);border-radius:100px;text-decoration:none;color:var(--txt-primary);font-size:.78rem;font-weight:600}
.user-avatar{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--plum),var(--azure));display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:#fff}
.admin-badge{display:inline-flex;align-items:center;gap:4px;font-size:.62rem;padding:2px 8px;border-radius:100px;background:rgba(232,201,125,.1);color:var(--gold);border:1px solid rgba(232,201,125,.25);font-family:'JetBrains Mono',monospace}
#hamburger{display:none;background:none;border:none;color:var(--txt-primary);font-size:1.3rem;cursor:pointer}
#mobile-nav{display:none;position:fixed;inset:0;top:62px;background:rgba(7,11,20,.98);backdrop-filter:blur(24px);z-index:998;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem}
#mobile-nav.open{display:flex}
#mobile-nav a{font-family:'Clash Display',sans-serif;font-size:1.6rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;transition:color .2s}
#mobile-nav a:hover{color:var(--gold)}
@media(max-width:900px){.nav-links,.nav-actions{display:none}#hamburger{display:block}}

/* HERO BAND */
.explorer-hero{padding-top:62px;background:linear-gradient(180deg,rgba(232,201,125,.05) 0%,transparent 100%);border-bottom:1px solid var(--glass-border)}
.explorer-hero-inner{max-width:1400px;margin:0 auto;padding:2.5rem 2rem 2rem}
.breadcrumb{display:flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--txt-muted);margin-bottom:1.5rem}
.breadcrumb a{color:var(--txt-muted);text-decoration:none;transition:color .2s}.breadcrumb a:hover{color:var(--gold)}
.explorer-title{font-family:'Clash Display',sans-serif;font-size:clamp(1.8rem,4vw,3rem);font-weight:700;letter-spacing:-2px;margin-bottom:.4rem;line-height:1}
.explorer-title span{background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.explorer-sub{font-size:.85rem;color:var(--txt-secondary);margin-bottom:1.8rem}
.count-badge{display:inline-flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--sage);background:rgba(78,204,163,.08);border:1px solid rgba(78,204,163,.2);padding:4px 12px;border-radius:100px;margin-left:.8rem;vertical-align:middle}

/* SEARCH ROW */
.search-filter-row{display:flex;flex-wrap:wrap;gap:.8rem;align-items:center;margin-bottom:1.5rem}
.search-form{display:flex;gap:.6rem;flex:1;min-width:260px;max-width:540px}
.search-input{flex:1;padding:11px 16px;border-radius:10px;background:var(--slate);border:1px solid var(--glass-border);color:var(--txt-primary);font-family:'Cabinet Grotesk',sans-serif;font-size:.88rem;outline:none;transition:border-color .2s}
.search-input:focus{border-color:rgba(232,201,125,.4)}
.search-input::placeholder{color:var(--txt-muted)}
.search-btn{padding:11px 18px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-weight:700;cursor:pointer;font-family:'Cabinet Grotesk',sans-serif;font-size:.82rem;transition:opacity .2s;white-space:nowrap}
.search-btn:hover{opacity:.88}
.clear-btn{padding:11px 14px;border-radius:10px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-muted);font-size:.82rem;cursor:pointer;transition:all .2s;font-family:'Cabinet Grotesk',sans-serif;white-space:nowrap}
.clear-btn:hover{border-color:rgba(255,95,87,.3);color:#ff5f57}

/* FILTER TABS */
.filter-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.2rem}
.filter-tab{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:100px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-size:.76rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;font-family:'Cabinet Grotesk',sans-serif}
.filter-tab:hover{border-color:rgba(232,201,125,.35);color:var(--gold);background:rgba(232,201,125,.04)}
.filter-tab.active{border-color:rgba(232,201,125,.5);color:var(--gold);background:rgba(232,201,125,.08)}
.filter-tab .tab-count{font-family:'JetBrains Mono',monospace;font-size:.58rem;background:rgba(232,201,125,.12);padding:1px 6px;border-radius:100px}

/* CAT PILLS (sidebar/horizontal) */
.cat-strip{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:2rem}
.cat-pill{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:100px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-size:.74rem;font-weight:600;text-decoration:none;transition:all .2s;font-family:'Cabinet Grotesk',sans-serif}
.cat-pill:hover{border-color:rgba(74,158,255,.3);color:var(--azure)}
.cat-pill.active{border-color:rgba(74,158,255,.5);color:var(--azure);background:rgba(74,158,255,.06)}
.cat-all{border-color:var(--glass-border)}
.cat-all.active{border-color:rgba(232,201,125,.5);color:var(--gold);background:rgba(232,201,125,.06)}

/* MAIN LAYOUT */
main{max-width:1400px;margin:0 auto;padding:2rem 2rem 6rem}

/* RESULTS HEADER */
.results-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.8rem}
.results-info{font-size:.8rem;color:var(--txt-secondary)}
.results-info strong{color:var(--gold)}
.sort-select{padding:7px 12px;border-radius:8px;background:var(--slate);border:1px solid var(--glass-border);color:var(--txt-secondary);font-family:'Cabinet Grotesk',sans-serif;font-size:.78rem;outline:none;cursor:pointer}
.sort-select option{background:var(--slate)}

/* GRID */
.books-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1.2rem}
@media(max-width:640px){.books-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:380px){.books-grid{grid-template-columns:1fr}}

/* BOOK CARD */
.book-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:14px;overflow:hidden;cursor:pointer;position:relative;transition:transform .3s var(--ease-spring),border-color .3s,box-shadow .3s;animation:cardFadeIn .4s var(--ease-smooth) both}
@keyframes cardFadeIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.book-card:hover{transform:translateY(-8px) scale(1.02);border-color:rgba(232,201,125,.25);box-shadow:0 20px 50px rgba(0,0,0,.55),0 0 25px rgba(232,201,125,.07);z-index:5}
.book-cover{height:165px;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden}
.cover-bg{position:absolute;inset:0}
.cover-emoji{font-size:3.2rem;position:relative;z-index:1;filter:drop-shadow(0 4px 12px rgba(0,0,0,.5));transition:transform .3s var(--ease-spring)}
.book-card:hover .cover-emoji{transform:scale(1.15) rotate(-4deg)}
.cover-vignette{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 35%,rgba(7,11,20,.85));z-index:2}
.price-badge{position:absolute;top:8px;right:8px;z-index:3;font-family:'JetBrains Mono',monospace;font-size:.58rem;padding:3px 8px;border-radius:100px;letter-spacing:.05em}
.badge-free{background:rgba(78,204,163,.15);color:var(--sage);border:1px solid rgba(78,204,163,.3)}
.badge-std{background:rgba(74,158,255,.15);color:var(--azure);border:1px solid rgba(74,158,255,.3)}
.badge-premium{background:rgba(232,201,125,.15);color:var(--gold);border:1px solid rgba(232,201,125,.3)}
.top-badge{position:absolute;top:8px;left:8px;z-index:3;font-family:'JetBrains Mono',monospace;font-size:.55rem;padding:3px 6px;border-radius:6px;background:rgba(255,107,53,.15);color:var(--ember);border:1px solid rgba(255,107,53,.3)}

/* Hover overlay */
.card-hover-overlay{position:absolute;inset:0;background:rgba(7,11,20,.92);z-index:10;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.7rem;padding:1rem;opacity:0;transition:opacity .2s;border-radius:14px}
.book-card:hover .card-hover-overlay{opacity:1}
.hov-title{font-family:'Clash Display',sans-serif;font-size:.82rem;font-weight:600;text-align:center;color:var(--txt-primary);line-height:1.3}
.hov-author{font-size:.67rem;color:var(--txt-secondary)}
.hov-rating{display:flex;align-items:center;gap:4px;font-size:.7rem;color:var(--gold)}
.hov-price{font-family:'JetBrains Mono',monospace;font-size:.67rem;color:var(--txt-muted)}
.hov-btns{display:flex;gap:.5rem;width:100%}
.hov-btn-read{flex:1;padding:9px 0;border-radius:8px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-size:.72rem;font-weight:700;cursor:pointer;font-family:'Cabinet Grotesk',sans-serif}
.hov-btn-read:hover{opacity:.85}
.hov-btn-info{width:36px;height:36px;border-radius:8px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.8rem;transition:all .2s}
.hov-btn-info:hover{border-color:rgba(74,158,255,.4);color:var(--azure)}

/* Card body */
.book-body{padding:.8rem .9rem .9rem}
.book-genre{font-family:'JetBrains Mono',monospace;font-size:.56rem;color:var(--gold);letter-spacing:.1em;text-transform:uppercase;margin-bottom:3px}
.book-title{font-family:'Clash Display',sans-serif;font-size:.82rem;font-weight:600;letter-spacing:-.2px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.book-author{font-size:.68rem;color:var(--txt-secondary);margin-bottom:.5rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.book-meta{display:flex;align-items:center;justify-content:space-between}
.book-stars{color:var(--gold);font-size:.6rem;letter-spacing:-1px}
.book-price{font-family:'Clash Display',sans-serif;font-size:.76rem;font-weight:700;color:var(--gold)}
.book-price.free{color:var(--sage)}

/* EMPTY STATE */
.empty-state{text-align:center;padding:5rem 2rem;color:var(--txt-secondary)}
.empty-state .icon{font-size:3rem;margin-bottom:1rem;display:block}
.empty-state h3{font-family:'Clash Display',sans-serif;font-size:1.3rem;margin-bottom:.5rem;color:var(--txt-primary)}
.empty-state p{font-size:.85rem;line-height:1.7}
.empty-state a{color:var(--gold);text-decoration:none}

/* PAGINATION */
.pagination{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:3rem;flex-wrap:wrap}
.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 12px;border-radius:8px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-size:.8rem;font-weight:600;text-decoration:none;transition:all .2s;font-family:'Cabinet Grotesk',sans-serif}
.page-btn:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.page-btn.active{border-color:var(--gold);color:var(--gold);background:rgba(232,201,125,.08)}
.page-btn.disabled{opacity:.3;pointer-events:none}
.page-ellipsis{color:var(--txt-muted);font-size:.8rem;padding:0 4px}

/* TOAST */
#toast{position:fixed;bottom:2rem;right:2rem;z-index:9800;background:rgba(13,18,32,.97);border:1px solid rgba(232,201,125,.2);border-radius:14px;padding:1rem 1.3rem;display:flex;align-items:center;gap:12px;font-size:.8rem;backdrop-filter:blur(20px);transform:translateY(100px) scale(.96);opacity:0;transition:all .4s var(--ease-spring);pointer-events:none;max-width:300px}
#toast.show{transform:translateY(0) scale(1);opacity:1;pointer-events:auto}
#toast.t-success{border-color:rgba(78,204,163,.3)}#toast.t-error{border-color:rgba(255,95,87,.3)}#toast.t-warn{border-color:rgba(255,189,46,.3)}
.t-icon{font-size:1.1rem}.t-text{display:flex;flex-direction:column;gap:2px}
.t-title{font-family:'Clash Display',sans-serif;font-weight:600;font-size:.82rem}
.t-msg{font-size:.72rem;color:var(--txt-muted)}

/* AUTH GATE */
#auth-gate{position:fixed;inset:0;z-index:9500;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
#auth-gate.open{opacity:1;visibility:visible}
.gate-backdrop{position:absolute;inset:0;background:rgba(7,11,20,.88);backdrop-filter:blur(12px)}
.gate-box{position:relative;z-index:1;max-width:420px;width:100%;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:24px;padding:2.5rem;text-align:center;box-shadow:0 60px 120px rgba(0,0,0,.6);transform:translateY(20px) scale(.97);transition:transform .4s var(--ease-spring)}
#auth-gate.open .gate-box{transform:none}
.gate-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--ember));border-radius:24px 24px 0 0}
.gate-icon{font-size:2.5rem;margin-bottom:1rem}
.gate-eyebrow{font-family:'JetBrains Mono',monospace;font-size:.65rem;letter-spacing:.12em;color:var(--gold);text-transform:uppercase;margin-bottom:.5rem}
.gate-title{font-family:'Clash Display',sans-serif;font-size:1.5rem;font-weight:700;margin-bottom:.7rem}
.gate-desc{font-size:.82rem;color:var(--txt-secondary);line-height:1.7;margin-bottom:1.8rem}
.gate-btns{display:flex;gap:.7rem}
.btn-gate-solid{flex:1;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px}
.btn-gate-outline{flex:1;padding:12px;border-radius:10px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-family:'Clash Display',sans-serif;font-size:.88rem;font-weight:600;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s}
.btn-gate-outline:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.gate-cd{margin-top:1rem;font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--txt-muted)}
.gate-prog{width:100%;height:2px;background:var(--glass-border);border-radius:2px;overflow:hidden;margin-top:.5rem}
.gate-prog-fill{height:100%;width:100%;background:var(--gold);transform-origin:left;transition:transform 5s linear}
.gate-close{position:absolute;top:1rem;right:1rem;width:28px;height:28px;border-radius:7px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.8rem;transition:all .2s}
.gate-close:hover{color:#ff5f57;border-color:rgba(255,95,87,.3)}

/* PAYMENT MODAL */
#pay-modal{position:fixed;inset:0;z-index:9700;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
#pay-modal.open{opacity:1;visibility:visible}
.pay-backdrop{position:absolute;inset:0;background:rgba(7,11,20,.88);backdrop-filter:blur(16px)}
.pay-box{position:relative;z-index:1;width:100%;max-width:500px;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:24px;overflow:hidden;overflow-y:auto;max-height:92vh;box-shadow:0 50px 100px rgba(0,0,0,.6);transform:translateY(20px) scale(.97);transition:transform .4s var(--ease-spring)}
#pay-modal.open .pay-box{transform:none}
.pay-bar{height:2px;background:linear-gradient(90deg,var(--gold),var(--ember),var(--sage));background-size:200%;animation:shim 3s linear infinite}
@keyframes shim{to{background-position:200% center}}
.pay-close{position:absolute;top:1rem;right:1rem;width:30px;height:30px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;font-size:.85rem;z-index:10}
.pay-close:hover{border-color:rgba(255,95,87,.3);color:#ff5f57}
.pay-header{padding:1.8rem 1.8rem 1.2rem;border-bottom:1px solid var(--glass-border)}
.pay-book-row{display:flex;align-items:center;gap:14px;margin-bottom:1.2rem}
.pay-thumb{width:54px;height:54px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.8rem;background:var(--mist);border:1px solid var(--glass-border);flex-shrink:0}
.pay-book-title{font-family:'Clash Display',sans-serif;font-size:.95rem;font-weight:600}
.pay-book-author{font-size:.75rem;color:var(--txt-secondary);margin-top:2px}
.pay-amount-row{display:flex;align-items:baseline;gap:6px}
.pay-amount-label{font-size:.75rem;color:var(--txt-muted)}
.pay-amount-val{font-family:'Clash Display',sans-serif;font-size:1.8rem;font-weight:700;color:var(--gold)}
.pay-amount-curr{font-size:.85rem;color:var(--txt-muted)}
.pay-body{padding:1.5rem 1.8rem}
.pay-steps{display:flex;align-items:center;gap:6px;margin-bottom:1.8rem}
.step-dot{width:8px;height:8px;border-radius:50%;background:var(--glass-border);transition:all .3s}.step-dot.active{background:var(--gold);transform:scale(1.3)}.step-dot.done{background:var(--sage)}
.step-line{flex:1;height:1px;background:var(--glass-border);transition:background .3s}.step-line.done{background:var(--sage)}
.step-lbl{font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--txt-muted);margin-left:auto}
.method-title{font-size:.78rem;color:var(--txt-secondary);margin-bottom:1rem}
.methods-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-bottom:1.5rem}
.method-btn{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);cursor:pointer;transition:all .2s;text-align:left;font-family:'Cabinet Grotesk',sans-serif}
.method-btn:hover{border-color:rgba(232,201,125,.25);color:var(--txt-primary)}
.method-btn.selected{border-color:var(--gold);background:rgba(232,201,125,.06);color:var(--txt-primary)}
.method-icon{font-size:1.5rem;flex-shrink:0}
.method-name{font-size:.78rem;font-weight:600}
.method-sub{font-size:.65rem;color:var(--txt-muted);margin-top:1px}
.field-group{margin-bottom:1rem}
.field-label{font-size:.72rem;color:var(--txt-secondary);margin-bottom:5px;display:block;font-weight:500}
.field-input{width:100%;padding:11px 14px;border-radius:10px;background:var(--fog);border:1px solid var(--glass-border);color:var(--txt-primary);font-family:'Cabinet Grotesk',sans-serif;font-size:.85rem;outline:none;transition:border-color .2s}
.field-input:focus{border-color:rgba(232,201,125,.4)}
.field-input::placeholder{color:var(--txt-muted)}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}
.card-number-field{font-family:'JetBrains Mono',monospace;letter-spacing:2px}
.btn-pay{width:100%;padding:14px;border-radius:12px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;transition:opacity .2s,transform .2s;margin-top:1rem}
.btn-pay:hover:not(:disabled){opacity:.88;transform:translateY(-1px)}
.btn-pay:disabled{opacity:.4;cursor:not-allowed;transform:none}
.btn-pay-back{background:none;border:none;color:var(--txt-muted);font-size:.75rem;cursor:pointer;text-decoration:underline;width:100%;text-align:center;margin-top:.5rem}
.proc-spinner{width:60px;height:60px;border-radius:50%;border:3px solid var(--glass-border);border-top-color:var(--gold);margin:0 auto 1.2rem;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.proc-step{display:flex;align-items:center;gap:10px;font-size:.78rem;color:var(--txt-muted);padding:6px 0;transition:color .3s}
.proc-step.active{color:var(--gold)}.proc-step.done{color:var(--sage)}
.proc-icon{width:18px;height:18px;border-radius:50%;border:1px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:.55rem;flex-shrink:0}
.proc-step.done .proc-icon{background:var(--sage);border-color:var(--sage);color:#fff}
.success-ring{width:80px;height:80px;border-radius:50%;background:rgba(78,204,163,.1);border:2px solid var(--sage);display:flex;align-items:center;justify-content:center;font-size:2.5rem;margin:0 auto 1.2rem;animation:pop .5s var(--ease-spring)}
@keyframes pop{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
.success-title{font-family:'Clash Display',sans-serif;font-size:1.4rem;font-weight:700;color:var(--sage);margin-bottom:.4rem}
.success-sub{font-size:.82rem;color:var(--txt-muted)}
.success-ref{font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--txt-muted);margin:1rem 0;padding:8px 16px;background:var(--fog);border-radius:8px;border:1px solid var(--glass-border)}
.admin-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:10px;background:rgba(232,201,125,.05);border:1px solid rgba(232,201,125,.2);font-size:.78rem;color:var(--txt-secondary);margin-bottom:1.2rem}
.admin-notice i{color:var(--gold);flex-shrink:0;margin-top:2px}
.pay-security{display:flex;align-items:center;justify-content:center;gap:1.5rem;padding:1rem 1.8rem;border-top:1px solid var(--glass-border);background:rgba(0,0,0,.2)}
.sec-badge{display:flex;align-items:center;gap:5px;font-size:.65rem;color:var(--txt-muted)}
.sec-badge i{color:var(--sage)}

/* READER */
#reader-modal{position:fixed;inset:0;z-index:9600;opacity:0;visibility:hidden;transition:opacity .4s,visibility .4s;background:#0e0d0b;display:flex;flex-direction:column}
#reader-modal.open{opacity:1;visibility:visible}
.reader-header{height:54px;display:flex;align-items:center;justify-content:space-between;padding:0 1.8rem;border-bottom:1px solid rgba(255,255,255,.06);background:rgba(0,0,0,.4);backdrop-filter:blur(10px);flex-shrink:0}
.reader-title-wrap{display:flex;align-items:center;gap:10px;min-width:0;flex:1}
.reader-title{font-family:'Clash Display',sans-serif;font-size:.9rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.reader-controls{display:flex;align-items:center;gap:.6rem;flex-shrink:0}
.reader-btn{width:32px;height:32px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.85rem;transition:all .2s}
.reader-btn:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.reader-close-btn{border-color:rgba(255,95,87,.3);color:#ff5f57}
.reader-close-btn:hover{background:rgba(255,95,87,.1)}
.reader-prog-bar{height:3px;background:rgba(255,255,255,.06);flex-shrink:0}
.reader-prog-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--ember));transition:width .5s}
.reader-body{flex:1;overflow-y:auto}
.reader-inner{max-width:680px;margin:0 auto;padding:3rem 2rem 7rem}
.reader-content{font-family:'Georgia',serif;font-size:1.05rem;line-height:1.95;color:#e8e4da;transition:font-size .2s}
.reader-content h2{font-family:'Clash Display',sans-serif;font-size:1.3rem;font-weight:700;margin:2.5rem 0 1.2rem;color:#f0eeea;border-bottom:1px solid rgba(255,255,255,.06);padding-bottom:.6rem}
.reader-content p{margin-bottom:1.3rem;text-indent:1.5em}
.reader-nav{position:sticky;bottom:0;display:flex;align-items:center;justify-content:center;gap:1rem;background:rgba(14,13,11,.95);border-top:1px solid rgba(255,255,255,.06);padding:12px 2rem;backdrop-filter:blur(20px)}
.reader-nav-btn{background:none;border:1px solid var(--glass-border);border-radius:8px;color:var(--txt-muted);cursor:pointer;padding:7px 14px;display:flex;align-items:center;gap:4px;font-size:.78rem;font-family:'Cabinet Grotesk',sans-serif;transition:all .2s}
.reader-nav-btn:hover:not(:disabled){border-color:rgba(232,201,125,.3);color:var(--gold)}
.reader-nav-btn:disabled{opacity:.3;cursor:not-allowed}
.reader-page-info{font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--txt-muted);min-width:90px;text-align:center}
#reader-modal.reader-light{background:#f5f0e8}
#reader-modal.reader-light .reader-content{color:#2c2a24}
#reader-modal.reader-light .reader-content h2{color:#1a1814;border-color:rgba(0,0,0,.08)}
#reader-modal.reader-light .reader-header{background:rgba(245,240,232,.9);border-color:rgba(0,0,0,.08)}
#reader-modal.reader-light .reader-nav{background:rgba(245,240,232,.95);border-color:rgba(0,0,0,.08)}

/* FOOTER */
footer{border-top:1px solid var(--glass-border);padding:2rem;background:rgba(0,0,0,.2);text-align:center}
.footer-links{display:flex;gap:1.5rem;justify-content:center;flex-wrap:wrap;margin-bottom:1rem}
.footer-links a{font-size:.78rem;color:var(--txt-muted);text-decoration:none;transition:color .2s}
.footer-links a:hover{color:var(--gold)}
.footer-copy{font-size:.7rem;color:var(--txt-muted)}

/* Responsive */
@media(max-width:600px){
  .explorer-hero-inner{padding:1.8rem 1rem 1.5rem}
  main{padding:1.5rem 1rem 5rem}
  .search-filter-row{flex-direction:column;align-items:stretch}
  .search-form{max-width:100%}
}
</style>
</head>
<body>
<div class="bg-orb orb-a"></div>
<div class="bg-orb orb-b"></div>

<!-- HEADER -->
<header id="site-header">
  <a href="index.php" class="nav-logo">
    <div class="logo-mark">📚</div>
    Digital <span style="color:var(--gold)">Library</span>
  </a>
  <nav><ul class="nav-links">
    <li><a href="index.php">Accueil</a></li>
    <li><a href="explorer.php" class="active">Explorer</a></li>
    <li><a href="categories.php">Catégories</a></li>
    <li><a href="tendances.php">Tendances</a></li>
    <li><a href="recommandations-ia.php">Recommandations IA</a></li>
  </ul></nav>
  <div class="nav-actions">
    <?php if ($isLoggedIn): ?>
      <?php if ($isAdmin): ?><span class="admin-badge"><i class="bi bi-shield-fill"></i> Admin</span><?php endif; ?>
      <a href="dashboard.php" class="user-chip">
        <div class="user-avatar"><?= strtoupper(substr($username,0,2)) ?></div><?= $username ?>
      </a>
      <a href="logout.php" class="btn-ghost">Déconnexion</a>
    <?php else: ?>
      <a href="login.php" class="btn-ghost">Connexion</a>
      <a href="register.php" class="btn-cta-nav">Commencer →</a>
    <?php endif; ?>
  </div>
  <button id="hamburger" aria-label="Menu"><i class="bi bi-list"></i></button>
</header>
<nav id="mobile-nav">
  <a href="index.php">Accueil</a><a href="explorer.php">Explorer</a><a href="categories.php">Catégories</a>
  <a href="tendances.php">Tendances</a><a href="recommandations-ia.php">Recommandations IA</a>
  <?php if ($isLoggedIn): ?><a href="logout.php" style="color:var(--ember)">Déconnexion</a>
  <?php else: ?><a href="login.php">Connexion</a><a href="register.php" style="color:var(--gold)">S'inscrire</a><?php endif; ?>
</nav>

<!-- HERO BAND -->
<div class="explorer-hero">
  <div class="explorer-hero-inner">
    <div class="breadcrumb">
      <a href="index.php">Accueil</a><span>›</span>
      <a href="categories.php">Catégories</a><span>›</span>
      <span style="color:var(--txt-secondary)">Explorer</span>
    </div>
    <h1 class="explorer-title">
      Explorer le <span>Catalogue</span>
      <?php if ($totalCount > 0): ?>
        <span class="count-badge"><?= number_format($totalCount) ?> résultats</span>
      <?php endif; ?>
    </h1>
    <p class="explorer-sub">
      <?php if ($search !== ''): ?>
        Résultats pour <strong style="color:var(--gold)">"<?= htmlspecialchars($search) ?>"</strong>
      <?php elseif ($catId > 0): ?>
        <?php
          $activeCat = array_filter($categories, fn($c)=>(int)$c['id']===$catId);
          $activeCat = array_values($activeCat)[0] ?? null;
        ?>
        Catégorie : <strong style="color:var(--gold)"><?= $activeCat ? htmlspecialchars($activeCat['icone'].' '.$activeCat['nom']) : '' ?></strong>
      <?php else: ?>
        Découvrez des milliers d'œuvres — livres, journaux, récits et bien plus.
      <?php endif; ?>
    </p>

    <!-- BARRE DE RECHERCHE -->
    <div class="search-filter-row">
      <form class="search-form" action="explorer.php" method="GET">
        <?php if ($catId > 0): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
        <?php if ($filterType !== 'all'): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($filterType) ?>"><?php endif; ?>
        <input
          type="text"
          name="q"
          class="search-input"
          id="search-input"
          value="<?= htmlspecialchars($search) ?>"
          placeholder="🔍 Rechercher titre, auteur, genre…"
          autocomplete="off"
          maxlength="120"
        >
        <button type="submit" class="search-btn"><i class="bi bi-search"></i> Chercher</button>
        <?php if ($search !== ''): ?>
          <a href="<?= buildUrl(['q'=>'']) ?>" class="clear-btn" title="Effacer la recherche"><i class="bi bi-x"></i></a>
        <?php endif; ?>
      </form>
    </div>

    <!-- FILTRES RAPIDES -->
    <div class="filter-tabs">
      <?php
      $tabs = [
        'all'     => ['label'=>'Tous les livres', 'icon'=>'bi-grid'],
        'gratuit' => ['label'=>'Gratuit',          'icon'=>'bi-gift'],
        'premium' => ['label'=>'Premium',          'icon'=>'bi-gem'],
        'top'     => ['label'=>'Mieux notés ⭐',  'icon'=>'bi-star-fill'],
        'recents' => ['label'=>'Récents',          'icon'=>'bi-clock'],
      ];
      foreach ($tabs as $key => $tab):
        $url = buildUrl(['filter'=>$key, 'page'=>null]);
        $isActive = $filterType === $key;
      ?>
      <a href="<?= $url ?>" class="filter-tab <?= $isActive?'active':'' ?>">
        <i class="bi <?= $tab['icon'] ?>"></i> <?= $tab['label'] ?>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- CATÉGORIES PILLS -->
    <div class="cat-strip">
      <a href="<?= buildUrl(['cat'=>null,'page'=>null]) ?>" class="cat-pill cat-all <?= $catId===0?'active':'' ?>">
        Toutes catégories
      </a>
      <?php foreach ($categories as $cat): ?>
      <a href="<?= buildUrl(['cat'=>(int)$cat['id'],'page'=>null]) ?>" class="cat-pill <?= (int)$cat['id']===$catId?'active':'' ?>">
        <?= htmlspecialchars($cat['icone']??'') ?> <?= htmlspecialchars($cat['nom']) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- MAIN -->
<main>
  <!-- RÉSULTATS HEADER -->
  <div class="results-header">
    <div class="results-info">
      <?php if ($totalCount > 0): ?>
        <strong><?= number_format($totalCount) ?></strong> livre<?= $totalCount > 1 ? 's' : '' ?> trouvé<?= $totalCount > 1 ? 's' : '' ?>
        <?php if ($totalPages > 1): ?> · Page <strong><?= $page ?></strong>/<strong><?= $totalPages ?></strong><?php endif; ?>
      <?php else: ?>
        Aucun résultat
      <?php endif; ?>
    </div>
    <form action="explorer.php" method="GET" style="display:flex;align-items:center;gap:.6rem">
      <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
      <?php if ($catId > 0): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
      <select name="filter" class="sort-select" onchange="this.form.submit()">
        <option value="all"     <?= $filterType==='all'    ?'selected':'' ?>>Pertinence</option>
        <option value="top"     <?= $filterType==='top'    ?'selected':'' ?>>Mieux notés</option>
        <option value="recents" <?= $filterType==='recents'?'selected':'' ?>>Plus récents</option>
        <option value="gratuit" <?= $filterType==='gratuit'?'selected':'' ?>>Gratuits d'abord</option>
        <option value="premium" <?= $filterType==='premium'?'selected':'' ?>>Premium d'abord</option>
      </select>
    </form>
  </div>

  <!-- GRILLE LIVRES -->
  <?php if (empty($allBooks)): ?>
  <div class="empty-state">
    <span class="icon">📭</span>
    <h3>Aucun livre trouvé</h3>
    <p>
      Aucun résultat pour vos critères actuels.<br>
      <a href="explorer.php">Réinitialiser les filtres</a> ou modifiez votre recherche.
    </p>
  </div>
  <?php else: ?>
  <div class="books-grid" id="books-grid">
    <?php
    $animDelay = 0;
    foreach ($allBooks as $i => $book):
      $colors  = $coverColors[$i % count($coverColors)];
      $emoji   = $book['emoji'] ?? $emojis[$i % count($emojis)];
      $badge   = getPriceBadge((float)($book['note_moyenne']??0), (float)($book['prix']??0));
      $stars   = starsHtml((float)($book['note_moyenne']??0));
      $isFree  = $badge['isFree'];
      $note    = (float)($book['note_moyenne']??0);
      $prixFmt = $isFree ? 'Gratuit' : number_format((float)$book['prix'],0,'.',' ').' FCFA';
      $extrait = base64_encode(substr((string)($book['contenu_extrait']??''), 0, 10000));
      $isTop   = $note >= 4.5;
      $delayMs = ($i % 5) * 60;
    ?>
    <div
      class="book-card"
      style="animation-delay:<?= $delayMs ?>ms"
      data-id="<?= (int)($book['id']??0) ?>"
      data-titre="<?= htmlspecialchars((string)($book['titre']??''), ENT_QUOTES, 'UTF-8') ?>"
      data-auteur="<?= htmlspecialchars((string)($book['auteur']??''), ENT_QUOTES, 'UTF-8') ?>"
      data-prix="<?= (float)($book['prix']??0) ?>"
      data-note="<?= $note ?>"
      data-emoji="<?= $emoji ?>"
      data-extrait="<?= $extrait ?>"
      data-pages="<?= (int)($book['pages']??200) ?>"
    >
      <div class="book-cover">
        <div class="cover-bg" style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)"></div>
        <div class="cover-emoji"><?= $emoji ?></div>
        <div class="cover-vignette"></div>
        <span class="price-badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
        <?php if ($isTop): ?><span class="top-badge">TOP ⭐</span><?php endif; ?>
      </div>
      <!-- Hover overlay -->
      <div class="card-hover-overlay">
        <div class="hov-title"><?= htmlspecialchars((string)($book['titre']??'')) ?></div>
        <div class="hov-author">par <?= htmlspecialchars((string)($book['auteur']??'')) ?></div>
        <div class="hov-rating"><i class="bi bi-star-fill"></i> <?= number_format($note,1) ?> · <?= $book['pages']??'?' ?> pages</div>
        <div class="hov-price"><?= $prixFmt ?></div>
        <div class="hov-btns">
          <button class="hov-btn-read" onclick="handleRead(this.closest('.book-card'))"><i class="bi bi-book-open"></i> Lire</button>
          <button class="hov-btn-info" onclick="showInfo(this.closest('.book-card'))" title="Infos"><i class="bi bi-info-circle"></i></button>
        </div>
      </div>
      <!-- Corps -->
      <div class="book-body">
        <div class="book-genre"><?= htmlspecialchars((string)($book['genre_icone']??'')) ?> <?= htmlspecialchars((string)($book['genre']??'')) ?></div>
        <div class="book-title"><?= htmlspecialchars((string)($book['titre']??'')) ?></div>
        <div class="book-author">par <?= htmlspecialchars((string)($book['auteur']??'')) ?></div>
        <div class="book-meta">
          <span class="book-stars"><?= $stars ?></span>
          <span class="book-price <?= $isFree?'free':'' ?>"><?= $prixFmt ?></span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- PAGINATION -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
      <a href="<?= buildUrl(['page'=>$page-1]) ?>" class="page-btn"><i class="bi bi-chevron-left"></i></a>
    <?php else: ?>
      <span class="page-btn disabled"><i class="bi bi-chevron-left"></i></span>
    <?php endif; ?>

    <?php
    $range = 2;
    $start = max(1, $page - $range);
    $end   = min($totalPages, $page + $range);
    if ($start > 1) { echo '<a href="'.buildUrl(['page'=>1]).'" class="page-btn">1</a>'; if ($start > 2) echo '<span class="page-ellipsis">…</span>'; }
    for ($p = $start; $p <= $end; $p++):
    ?>
      <a href="<?= buildUrl(['page'=>$p]) ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
    <?php endfor;
    if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span class="page-ellipsis">…</span>'; echo '<a href="'.buildUrl(['page'=>$totalPages]).'" class="page-btn">'.$totalPages.'</a>'; }
    ?>

    <?php if ($page < $totalPages): ?>
      <a href="<?= buildUrl(['page'=>$page+1]) ?>" class="page-btn"><i class="bi bi-chevron-right"></i></a>
    <?php else: ?>
      <span class="page-btn disabled"><i class="bi bi-chevron-right"></i></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</main>

<footer>
  <div class="footer-links">
    <a href="index.php">Accueil</a>
    <a href="categories.php">Catégories</a>
    <a href="tendances.php">Tendances</a>
    <a href="aide.php">Aide</a>
    
  </div>
  <p class="footer-copy">© <?= date('Y') ?> Digital Library System — Tous droits réservés.</p>
</footer>

<!-- TOAST -->
<div id="toast"><span class="t-icon" id="t-icon">ℹ️</span><div class="t-text"><span class="t-title" id="t-title"></span><span class="t-msg" id="t-msg"></span></div></div>

<!-- AUTH GATE -->
<div id="auth-gate" role="dialog" aria-modal="true">
  <div class="gate-backdrop" id="gate-backdrop"></div>
  <div class="gate-box">
    <button class="gate-close" id="gate-close-btn">✕</button>
    <div class="gate-icon">🔒</div>
    <div class="gate-eyebrow">Accès restreint</div>
    <h2 class="gate-title">Connexion requise</h2>
    <p class="gate-desc">Créez un compte ou connectez-vous pour accéder à la lecture.</p>
    <div class="gate-btns">
      <a href="login.php" class="btn-gate-solid"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
      <a href="register.php" class="btn-gate-outline"><i class="bi bi-person-plus"></i> S'inscrire</a>
    </div>
    <div class="gate-cd" id="gate-cd">Redirection dans 5s…</div>
    <div class="gate-prog"><div class="gate-prog-fill" id="gate-fill"></div></div>
  </div>
</div>

<!-- PAYMENT MODAL -->
<div id="pay-modal" role="dialog" aria-modal="true">
  <div class="pay-backdrop" id="pay-backdrop"></div>
  <div class="pay-box">
    <div class="pay-bar"></div>
    <button class="pay-close" id="pay-close-btn">✕</button>
    <div class="pay-header">
      <div class="pay-book-row">
        <div class="pay-thumb" id="pay-thumb">📚</div>
        <div>
          <div class="pay-book-title" id="pay-title">Titre</div>
          <div class="pay-book-author" id="pay-author">Auteur</div>
        </div>
      </div>
      <div class="pay-amount-row">
        <span class="pay-amount-label">Montant :</span>
        <span class="pay-amount-val" id="pay-amount">0</span>
        <span class="pay-amount-curr">FCFA</span>
      </div>
    </div>
    <div class="pay-body">
      <!-- Admin gate -->
      <div id="admin-gate-section" style="display:none">
        <div class="admin-notice"><i class="bi bi-shield-lock-fill"></i>
          <div><strong style="color:var(--gold)">Accès administrateur</strong><br>
          Entrez votre email admin pour accéder gratuitement.<br>
          <small style="color:var(--txt-muted)">Format : admin.[nom]@adminsopecam.com</small></div>
        </div>
        <div class="field-group">
          <label class="field-label">Email administrateur</label>
          <input type="email" class="field-input" id="admin-email" placeholder="admin.dupont@adminsopecam.com">
        </div>
        <button class="btn-pay" id="btn-admin-verify"><i class="bi bi-shield-check"></i> Vérifier et accéder</button>
      </div>
      <!-- Pay steps -->
      <div id="pay-steps-section">
        <div class="pay-steps">
          <div class="step-dot active" id="sd1"></div><div class="step-line" id="sl1"></div>
          <div class="step-dot" id="sd2"></div><div class="step-line" id="sl2"></div>
          <div class="step-dot" id="sd3"></div>
          <span class="step-lbl" id="step-lbl">Méthode</span>
        </div>
        <!-- Step 1 -->
        <div id="step1">
          <div class="method-title">Choisissez votre méthode</div>
          <div class="methods-grid">
            <button class="method-btn" data-method="orange_money" type="button">
              <span class="method-icon">📱</span><div><div class="method-name">Orange Money</div><div class="method-sub">Paiement mobile</div></div>
            </button>
            <button class="method-btn" data-method="mobile_money" type="button">
              <span class="method-icon">📲</span><div><div class="method-name">Mobile Money</div><div class="method-sub">MTN, Moov…</div></div>
            </button>
            <button class="method-btn" data-method="visa" type="button">
              <span class="method-icon">💳</span><div><div class="method-name">Visa</div><div class="method-sub">Crédit / débit</div></div>
            </button>
            <button class="method-btn" data-method="mastercard" type="button">
              <span class="method-icon">🏦</span><div><div class="method-name">Mastercard</div><div class="method-sub">Internationale</div></div>
            </button>
            <button class="method-btn" data-method="carte_locale" type="button" style="grid-column:span 2">
              <span class="method-icon">🏧</span><div><div class="method-name">Carte Locale</div><div class="method-sub">Banque camerounaise</div></div>
            </button>
          </div>
          <button class="btn-pay" id="btn-step1-next" disabled type="button">Continuer <i class="bi bi-arrow-right"></i></button>
        </div>
        <!-- Step 2 -->
        <div id="step2" style="display:none">
          <div id="form-mobile" style="display:none">
            <div class="field-group">
              <label class="field-label">Numéro de téléphone</label>
              <input type="tel" class="field-input" id="phone" placeholder="6XX XXX XXX" maxlength="12">
            </div>
            <div class="field-group">
              <label class="field-label">Nom complet</label>
              <input type="text" class="field-input" id="mobile-name" placeholder="Jean Dupont">
            </div>
          </div>
          <div id="form-card" style="display:none">
            <div class="field-group">
              <label class="field-label">Numéro de carte</label>
              <input type="text" class="field-input card-number-field" id="card-num" placeholder="1234 5678 9012 3456" maxlength="19">
            </div>
            <div class="field-group">
              <label class="field-label">Nom du titulaire</label>
              <input type="text" class="field-input" id="card-name" placeholder="JEAN DUPONT">
            </div>
            <div class="field-row">
              <div class="field-group"><label class="field-label">Expiration</label><input type="text" class="field-input" id="card-exp" placeholder="MM/AA" maxlength="5"></div>
              <div class="field-group"><label class="field-label">CVV</label><input type="password" class="field-input" id="card-cvv" placeholder="•••" maxlength="4"></div>
            </div>
          </div>
          <button class="btn-pay" id="btn-pay-now" type="button"><i class="bi bi-lock-fill"></i> Payer maintenant</button>
          <button class="btn-pay-back" id="btn-step2-back" type="button">← Changer de méthode</button>
        </div>
      </div>
      <!-- Processing -->
      <div id="pay-processing" style="display:none;text-align:center;padding:2rem 0">
        <div class="proc-spinner"></div>
        <div style="font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;margin-bottom:.4rem">Traitement en cours…</div>
        <div style="font-size:.78rem;color:var(--txt-muted)">Veuillez ne pas fermer cette fenêtre</div>
        <div style="margin-top:1.5rem">
          <div class="proc-step" id="ps1"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Connexion serveur de paiement</span></div>
          <div class="proc-step" id="ps2"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Vérification des informations</span></div>
          <div class="proc-step" id="ps3"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Autorisation bancaire</span></div>
          <div class="proc-step" id="ps4"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Confirmation de transaction</span></div>
        </div>
      </div>
      <!-- Success -->
      <div id="pay-success" style="display:none;text-align:center;padding:2rem 0">
        <div class="success-ring">✅</div>
        <div class="success-title">Paiement réussi !</div>
        <div class="success-sub">Accès au livre activé.</div>
        <div class="success-ref" id="success-ref">REF: —</div>
        <button class="btn-pay" id="btn-open-reader" type="button"><i class="bi bi-book-open"></i> Ouvrir le lecteur</button>
      </div>
    </div>
    <div class="pay-security">
      <span class="sec-badge"><i class="bi bi-shield-check"></i> SSL 256-bit</span>
      <span class="sec-badge"><i class="bi bi-lock-fill"></i> Chiffré</span>
      <span class="sec-badge"><i class="bi bi-patch-check"></i> Sécurisé</span>
    </div>
  </div>
</div>

<!-- READER -->
<div id="reader-modal" role="dialog" aria-modal="true">
  <div class="reader-header">
    <div class="reader-title-wrap">
      <span id="reader-chap" style="font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--gold);background:rgba(232,201,125,.1);border:1px solid rgba(232,201,125,.2);padding:3px 8px;border-radius:6px;flex-shrink:0"></span>
      <span class="reader-title" id="reader-title">—</span>
    </div>
    <div class="reader-controls">
      <button class="reader-btn" id="btn-theme"><i class="bi bi-moon" id="theme-ico"></i></button>
      <button class="reader-btn" id="btn-font-up"><i class="bi bi-zoom-in"></i></button>
      <button class="reader-btn" id="btn-font-dn"><i class="bi bi-zoom-out"></i></button>
      <button class="reader-btn" id="btn-bookmark"><i class="bi bi-bookmark" id="bm-ico"></i></button>
      <button class="reader-btn reader-close-btn" id="btn-reader-close"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div class="reader-prog-bar"><div class="reader-prog-fill" id="reader-prog" style="width:0%"></div></div>
  <div class="reader-body" id="reader-body"><div class="reader-inner"><div class="reader-content" id="reader-content">Chargement…</div></div></div>
  <div class="reader-nav">
    <button class="reader-nav-btn" id="btn-prev-page"><i class="bi bi-chevron-left"></i> Précédente</button>
    <span class="reader-page-info" id="page-info">Page 1 / 1</span>
    <button class="reader-nav-btn" id="btn-next-page">Suivante <i class="bi bi-chevron-right"></i></button>
  </div>
</div>

<script>
'use strict';
// ── Config PHP injectée ─────────────────────────────────────
const IS_LOGGED   = <?= json_encode($isLoggedIn) ?>;
const IS_ADMIN    = <?= json_encode($isAdmin) ?>;
const IS_JOURNALIST = <?= json_encode($isJournalist) ?>;
const USERNAME    = <?= json_encode($username) ?>;

// ── État global ──────────────────────────────────────────────
let currentBook        = null;
let selectedMethod     = null;
let readerFont         = 17;
let readerLight        = false;
let readerPage         = 1;
let readerTotal        = 1;
let readerPages        = [];
let gateTimer          = null;
let gateInterval       = null;
let toastT             = null;

// ── HEADER scroll ────────────────────────────────────────────
const hdr = document.getElementById('site-header');
window.addEventListener('scroll', () => hdr.classList.toggle('scrolled', scrollY > 60));

// ── Hamburger ────────────────────────────────────────────────
const ham = document.getElementById('hamburger');
const mnav = document.getElementById('mobile-nav');
ham.addEventListener('click', () => {
  const o = mnav.classList.toggle('open');
  ham.innerHTML = o ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
});

// ── Toast ────────────────────────────────────────────────────
function toast(title, msg='', type='default', dur=3500) {
  const el = document.getElementById('toast');
  const icons = { default:'ℹ️', success:'✅', error:'❌', warn:'⚠️' };
  el.className = '';
  if (type !== 'default') el.classList.add('t-'+type);
  document.getElementById('t-icon').textContent  = icons[type]||'ℹ️';
  document.getElementById('t-title').textContent = title;
  document.getElementById('t-msg').textContent   = msg;
  el.classList.add('show');
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove('show'), dur);
}

// ── AUTH GATE ────────────────────────────────────────────────
function openAuthGate() {
  const gate = document.getElementById('auth-gate');
  gate.classList.add('open');
  document.body.style.overflow = 'hidden';
  let sec = 5;
  const cdEl   = document.getElementById('gate-cd');
  const fillEl = document.getElementById('gate-fill');
  if (cdEl) cdEl.textContent = `Redirection dans ${sec}s…`;
  if (fillEl) {
    fillEl.style.transition = 'none';
    fillEl.style.transform  = 'scaleX(1)';
    fillEl.getBoundingClientRect();
    fillEl.style.transition = 'transform 5s linear';
    fillEl.style.transform  = 'scaleX(0)';
  }
  clearInterval(gateInterval);
  gateInterval = setInterval(() => { sec--; if(cdEl) cdEl.textContent=`Redirection dans ${sec}s…`; if(sec<=0) clearInterval(gateInterval); }, 1000);
  clearTimeout(gateTimer);
  gateTimer = setTimeout(() => { window.location.href='login.php'; }, 5000);
}
function closeAuthGate() {
  document.getElementById('auth-gate').classList.remove('open');
  document.body.style.overflow = '';
  clearTimeout(gateTimer); clearInterval(gateInterval);
}
document.getElementById('gate-close-btn').addEventListener('click', closeAuthGate);
document.getElementById('gate-backdrop').addEventListener('click', closeAuthGate);

// ── BOOK DATA ─────────────────────────────────────────────────
function getBook(card) {
  if (!card) return null;
  let ext = '';
  try { ext = atob(card.dataset.extrait || ''); } catch(e) { ext = card.dataset.extrait || ''; }
  return {
    id:      card.dataset.id     || '0',
    title:   card.dataset.titre  || '—',
    author:  card.dataset.auteur || '—',
    price:   parseFloat(card.dataset.prix)  || 0,
    note:    parseFloat(card.dataset.note)  || 0,
    emoji:   card.dataset.emoji  || '📚',
    pages:   parseInt(card.dataset.pages)   || 200,
    extrait: ext,
  };
}

// ── DISPATCH READ ─────────────────────────────────────────────
function handleRead(card) {
  currentBook = getBook(card);
  if (!currentBook) return;
  if (!IS_LOGGED) { openAuthGate(); return; }
  const isFree = currentBook.price === 0 || currentBook.note <= 2.0;
  if (IS_ADMIN) { openPayModal(true); return; }
  if (isFree)   { openReader();        return; }
  openPayModal(false);
}

function showInfo(card) {
  const b = getBook(card);
  if (!b) return;
  const prixFmt = b.price === 0 ? 'Gratuit' : b.price.toLocaleString('fr-FR')+' FCFA';
  toast(b.title, `par ${b.author} · ${prixFmt} · Note : ${b.note}/5`, 'default', 5000);
}

// ── PAYMENT MODAL ─────────────────────────────────────────────
function openPayModal(adminMode) {
  if (!currentBook) return;
  document.getElementById('pay-title').textContent  = currentBook.title;
  document.getElementById('pay-author').textContent = 'par ' + currentBook.author;
  document.getElementById('pay-thumb').textContent  = currentBook.emoji;
  document.getElementById('pay-amount').textContent = currentBook.price === 0
    ? '0' : currentBook.price.toLocaleString('fr-FR');

  resetPayModal();

  if (adminMode) {
    document.getElementById('admin-gate-section').style.display = 'block';
    document.getElementById('pay-steps-section').style.display  = 'none';
  } else {
    document.getElementById('admin-gate-section').style.display = 'none';
    document.getElementById('pay-steps-section').style.display  = 'block';
  }

  document.getElementById('pay-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closePayModal() {
  document.getElementById('pay-modal').classList.remove('open');
  document.body.style.overflow = '';
  resetPayModal();
}

function resetPayModal() {
  ['step1','step2','admin-gate-section'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = id === 'step1' ? 'block' : 'none';
  });
  ['pay-processing','pay-success'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
  document.getElementById('pay-steps-section').style.display = 'block';
  ['sd1','sd2','sd3'].forEach((id,i)=>{const e=document.getElementById(id);if(e){e.className='step-dot';if(i===0)e.classList.add('active');}});
  ['sl1','sl2'].forEach(id=>{const e=document.getElementById(id);if(e)e.className='step-line';});
  const lbl=document.getElementById('step-lbl');if(lbl)lbl.textContent='Méthode';
  document.querySelectorAll('.method-btn').forEach(b=>b.classList.remove('selected'));
  selectedMethod=null;
  const btn=document.getElementById('btn-step1-next');if(btn)btn.disabled=true;
  ['phone','mobile-name','card-num','card-name','card-exp','card-cvv','admin-email'].forEach(id=>{const e=document.getElementById(id);if(e){e.value='';e.style.borderColor='';}});
  ['form-mobile','form-card'].forEach(id=>{const e=document.getElementById(id);if(e)e.style.display='none';});
  ['ps1','ps2','ps3','ps4'].forEach(id=>{const e=document.getElementById(id);if(e)e.className='proc-step';});
}

// Sélection méthode
document.querySelectorAll('.method-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.method-btn').forEach(b=>b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedMethod = btn.dataset.method;
    document.getElementById('btn-step1-next').disabled = false;
  });
});

// Step 1 → 2
document.getElementById('btn-step1-next').addEventListener('click', () => {
  if (!selectedMethod) return;
  document.getElementById('step1').style.display = 'none';
  document.getElementById('step2').style.display = 'block';
  document.getElementById('sd1').className = 'step-dot done';
  document.getElementById('sl1').className = 'step-line done';
  document.getElementById('sd2').className = 'step-dot active';
  document.getElementById('step-lbl').textContent = 'Informations';
  const mob = ['orange_money','mobile_money'].includes(selectedMethod);
  document.getElementById('form-mobile').style.display = mob ? 'block' : 'none';
  document.getElementById('form-card').style.display   = mob ? 'none'  : 'block';
});

// Retour
document.getElementById('btn-step2-back').addEventListener('click', () => {
  document.getElementById('step1').style.display = 'block';
  document.getElementById('step2').style.display = 'none';
  document.getElementById('sd1').className='step-dot active';
  document.getElementById('sl1').className='step-line';
  document.getElementById('sd2').className='step-dot';
  document.getElementById('step-lbl').textContent='Méthode';
});

// Payer
document.getElementById('btn-pay-now').addEventListener('click', () => {
  const mob = ['orange_money','mobile_money'].includes(selectedMethod);
  if (mob) {
    const phone = (document.getElementById('phone').value||'').replace(/\s/g,'');
    if (phone.length < 9) { toast('Numéro invalide','Minimum 9 chiffres.','error'); return; }
  } else {
    const cn  = (document.getElementById('card-num').value||'').replace(/\s/g,'');
    const exp = document.getElementById('card-exp').value||'';
    const cvv = document.getElementById('card-cvv').value||'';
    if (cn.length < 16)               { toast('Carte invalide','16 chiffres requis.','error'); return; }
    if (!exp.match(/^\d{2}\/\d{2}$/)) { toast('Expiration invalide','Format MM/AA.','error'); return; }
    if (cvv.length < 3)               { toast('CVV invalide','3-4 chiffres requis.','error'); return; }
  }
  // → Traitement
  document.getElementById('sd2').className='step-dot done';
  document.getElementById('sl2').className='step-line done';
  document.getElementById('sd3').className='step-dot active';
  document.getElementById('step-lbl').textContent='Traitement…';
  document.getElementById('step2').style.display       = 'none';
  document.getElementById('pay-processing').style.display = 'block';

  const steps  = ['ps1','ps2','ps3','ps4'];
  const delays = [0, 900, 1900, 3000];
  steps.forEach((id,i) => {
    setTimeout(()=>{
      if(i>0){const p=document.getElementById(steps[i-1]);if(p)p.className='proc-step done';}
      const c=document.getElementById(id);if(c)c.className='proc-step active';
    }, delays[i]);
  });
  setTimeout(()=>{
    const last=document.getElementById(steps[steps.length-1]);if(last)last.className='proc-step done';
    setTimeout(showPaySuccess, 350);
  }, delays[delays.length-1]+700);
});

function showPaySuccess() {
  document.getElementById('pay-processing').style.display = 'none';
  document.getElementById('pay-success').style.display    = 'block';
  document.getElementById('sd3').className='step-dot done';
  const ref = 'DLS-' + Date.now().toString(36).toUpperCase()+'-'+Math.random().toString(36).slice(2,6).toUpperCase();
  const refEl = document.getElementById('success-ref');
  if (refEl) refEl.textContent = 'REF: '+ref;
  toast('Paiement validé !','Accès activé.','success',5000);
  // Sauvegarde côté serveur (non bloquant)
  if (currentBook) {
    fetch('api/save_purchase.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({livre_id:currentBook.id, montant:currentBook.price, methode:selectedMethod, reference:ref})
    }).catch(()=>{});
  }
}

// Ouvrir lecteur après paiement
document.getElementById('btn-open-reader').addEventListener('click', () => {
  closePayModal();
  setTimeout(openReader, 300);
});

// Admin verify
document.getElementById('btn-admin-verify').addEventListener('click', () => {
  const emailEl = document.getElementById('admin-email');
  const email   = emailEl ? emailEl.value.trim() : '';
  const pattern = /^admin\.[a-zA-Z][a-zA-Z0-9.]*@adminsopecam\.com$/;
  if (!pattern.test(email)) {
    toast('Email invalide','Format : admin.[nom]@adminsopecam.com','error');
    if (emailEl) emailEl.style.borderColor='rgba(255,95,87,.5)';
    return;
  }
  if (emailEl) emailEl.style.borderColor='rgba(78,204,163,.5)';
  toast('Accès admin accordé','Ouverture du lecteur…','success',3000);
  setTimeout(()=>{ closePayModal(); openReader(); }, 1200);
});

// Fermeture pay modal
document.getElementById('pay-close-btn').addEventListener('click', closePayModal);
document.getElementById('pay-backdrop').addEventListener('click', closePayModal);

// Formatage carte
document.getElementById('card-num').addEventListener('input', function(){
  let v=this.value.replace(/\D/g,'').slice(0,16);
  this.value=v.match(/.{1,4}/g)?.join(' ')||v;
});
document.getElementById('card-exp').addEventListener('input', function(){
  let v=this.value.replace(/\D/g,'');
  if(v.length>2) v=v.slice(0,2)+'/'+v.slice(2,4);
  this.value=v;
});

// ── READER ───────────────────────────────────────────────────
function openReader() {
  if (!currentBook) return;
  document.getElementById('reader-title').textContent = currentBook.title;
  const raw   = currentBook.extrait || fallbackContent(currentBook.title);
  readerPages = buildPages(raw);
  readerTotal = readerPages.length;
  readerPage  = 1;
  try {
    const s = localStorage.getItem('dls_pg_'+currentBook.id);
    if (s) { const p=parseInt(s); if(p>=1&&p<=readerTotal) readerPage=p; }
  } catch(e) {}
  renderPage(false);
  document.getElementById('reader-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
  toast('Lecteur',''+readerTotal+' pages · Bonne lecture !','success',3000);
}

function buildPages(raw) {
  const parts = (raw||'').split('||||PAGE||||').map(p=>p.trim()).filter(Boolean);
  if (parts.length >= 5) return parts;
  const extras = [
    'APPROFONDISSEMENT\n\nCe chapitre révèle la profondeur de la vision de l\'auteur. Chaque mot a été soigneusement choisi pour créer une expérience unique.\n\nLes thèmes résonnent avec une actualité frappante, invitant à réfléchir sur notre condition.',
    'RÉFLEXIONS\n\nL\'auteur tisse des fils narratifs pour révéler une tapisserie d\'une richesse insoupçonnée.\n\nLe lecteur se trouve transformé, portant de nouvelles questions sur le monde.',
    'VERS LA FIN\n\nAlors que nous approchons de la conclusion, il convient de mesurer le chemin parcouru.\n\nCette œuvre restera comme un témoignage précieux et intemporel.',
    'ÉPILOGUE\n\nLa dernière page tournée, le livre refermé. Les grandes œuvres ne nous quittent pas vraiment.\n\nMerci d\'avoir partagé ce voyage littéraire.',
    'POSTFACE\n\nCette œuvre dépasse les frontières du genre : témoignage, réflexion et création artistique.\n\nRevenez-y dans quelques mois — vous le lirez différemment.',
  ];
  const r = [...parts];
  let idx=0; while(r.length<5){r.push(extras[idx%extras.length]);idx++;} return r;
}

function fallbackContent(title){
  return `CHAPITRE I — Introduction\n\n«${title}» nous plonge dans un univers fascinant dès les premières pages.\n\nL'auteur pose des fondations narratives solides, introduisant des personnages aux motivations complexes.||||PAGE||||CHAPITRE II — Développement\n\nLe récit s'accélère. La tension dramatique monte, portant le lecteur dans un tourbillon d'émotions.\n\nChaque dialogue est ciselé, chaque description immersive.||||PAGE||||CHAPITRE III — Le Nœud\n\nLa tension dramatique atteint son apogée. Les certitudes s'effondrent, les alliances se reconfigurent.\n\nC'est ici que le génie de l'auteur éclate pleinement.||||PAGE||||CHAPITRE IV — Les Révélations\n\nLes vérités cachées émergent. Ce que le lecteur croyait comprendre se révèle plus complexe.\n\nLes dialogues sont chargés d'une signification soigneusement construite.||||PAGE||||CHAPITRE V — Dénouement\n\nLa résolution, inattendue et inévitable, apporte la satisfaction que mérite ce voyage.\n\nUne conclusion qui laisse une empreinte durable.`;
}

function renderPage(animate, dir='next') {
  const cEl = document.getElementById('reader-content');
  if (!cEl) return;
  const raw = readerPages[readerPage-1] || 'Contenu non disponible.';
  let html = raw
    .replace(/^(CHAPITRE [IVX0-9]+[^\n]*)/gm, '<h2>$1</h2>')
    .replace(/^(ÉPILOGUE|POSTFACE|APPROFONDISSEMENT|RÉFLEXIONS|VERS LA FIN)/gm, '<h2>$1</h2>')
    .replace(/\n\n+/g,'</p><p>').replace(/\n/g,'<br>');
  html = '<p>'+html+'</p>';
  html = html.replace(/<p><h2>/g,'<h2>').replace(/<\/h2><\/p>/g,'</h2>').replace(/<p><\/p>/g,'');

  const pi  = document.getElementById('page-info');
  const pg  = document.getElementById('reader-prog');
  const chp = document.getElementById('reader-chap');
  const bp  = document.getElementById('btn-prev-page');
  const bn  = document.getElementById('btn-next-page');
  if (pi)  pi.textContent  = `Page ${readerPage} / ${readerTotal}`;
  if (pg)  pg.style.width  = ((readerPage/readerTotal)*100).toFixed(1)+'%';
  if (chp) chp.textContent = `Ch. ${readerPage}`;
  if (bp)  bp.disabled     = readerPage===1;
  if (bn)  bn.disabled     = readerPage===readerTotal;
  try { if(currentBook) localStorage.setItem('dls_pg_'+currentBook.id, readerPage); }catch(e){}

  if (!animate) {
    cEl.innerHTML=html; cEl.style.fontSize=readerFont+'px';
    const b=document.getElementById('reader-body');if(b)b.scrollTop=0; return;
  }
  cEl.style.cssText += ';opacity:0;transform:'+(dir==='next'?'translateX(-20px)':'translateX(20px)')+';transition:opacity .15s,transform .15s';
  setTimeout(()=>{
    cEl.innerHTML=html; cEl.style.fontSize=readerFont+'px';
    cEl.style.transition='none';
    cEl.style.transform=dir==='next'?'translateX(20px)':'translateX(-20px)';
    const b=document.getElementById('reader-body');if(b)b.scrollTop=0;
    requestAnimationFrame(()=>{
      cEl.style.transition='opacity .3s,transform .3s';
      cEl.style.opacity='1'; cEl.style.transform='translateX(0)';
    });
  },150);
}

function closeReader() {
  document.getElementById('reader-modal').classList.remove('open');
  document.body.style.overflow='';
}

document.getElementById('btn-reader-close').addEventListener('click', closeReader);
document.getElementById('btn-prev-page').addEventListener('click', ()=>{
  if(readerPage>1){readerPage--;renderPage(true,'prev');}else toast('Début','Première page.','warn',1500);
});
document.getElementById('btn-next-page').addEventListener('click', ()=>{
  if(readerPage<readerTotal){readerPage++;renderPage(true,'next');}else toast('Fin','Dernière page de l\'extrait.','warn',2000);
});
document.getElementById('btn-theme').addEventListener('click', ()=>{
  readerLight=!readerLight;
  document.getElementById('reader-modal').classList.toggle('reader-light',readerLight);
  document.getElementById('theme-ico').className=readerLight?'bi bi-sun-fill':'bi bi-moon';
  toast('Thème',readerLight?'Mode clair':'Mode sombre','default',1200);
});
document.getElementById('btn-font-up').addEventListener('click',()=>{readerFont=Math.min(24,readerFont+2);const c=document.getElementById('reader-content');if(c)c.style.fontSize=readerFont+'px';});
document.getElementById('btn-font-dn').addEventListener('click',()=>{readerFont=Math.max(12,readerFont-2);const c=document.getElementById('reader-content');if(c)c.style.fontSize=readerFont+'px';});
document.getElementById('btn-bookmark').addEventListener('click',()=>{
  const ico=document.getElementById('bm-ico');
  if(ico){ico.className='bi bi-bookmark-fill';ico.style.color='var(--gold)';}
  setTimeout(()=>{if(ico){ico.className='bi bi-bookmark';ico.style.color='';}},2000);
  toast('Marque-page',`Page ${readerPage}/${readerTotal} sauvegardée.`,'success',2500);
});

// Clavier
document.addEventListener('keydown', e=>{
  const ro = document.getElementById('reader-modal').classList.contains('open');
  const po = document.getElementById('pay-modal').classList.contains('open');
  const go = document.getElementById('auth-gate').classList.contains('open');
  if(e.key==='Escape'){if(ro)closeReader();else if(po)closePayModal();else if(go)closeAuthGate();return;}
  if(ro){
    if(['ArrowRight','ArrowDown','PageDown'].includes(e.key)){e.preventDefault();if(readerPage<readerTotal){readerPage++;renderPage(true,'next');}}
    if(['ArrowLeft','ArrowUp','PageUp'].includes(e.key)){e.preventDefault();if(readerPage>1){readerPage--;renderPage(true,'prev');}}
  }
});
</script>
</body>
</html>