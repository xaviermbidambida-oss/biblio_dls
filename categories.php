<?php
/**
 * categories.php — Digital Library System
 * Page des catégories avec listing et navigation vers l'explorateur
 * VERSION COMPLÈTE & SÉCURISÉE
 */

if (session_status() === PHP_SESSION_NONE) session_start();

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
    } catch (PDOException $e) { $pdo = null; }
}

// ── Identité ─────────────────────────────────────────────────────────
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin    = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$username = 'Lecteur';

if (!empty($_SESSION['user_name'])) {
    $username = htmlspecialchars($_SESSION['user_name']);
} elseif (!empty($_SESSION['user_prenom']) && !empty($_SESSION['user_nom'])) {
    $username = htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']);
}

// ── Catégorie active (vue détail) ─────────────────────────────────────
$activeCatId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;

// ── Données ───────────────────────────────────────────────────────────
$categories = [];
$activeCat  = null;
$catBooks   = [];
$stats      = ['total_cats'=>0,'total_books'=>0,'total_free'=>0];

// Données démo
$demoCategories = [
    ['id'=>1,'nom'=>'Science-Fiction',    'icone'=>'🌌','slug'=>'sf',      'description'=>'Voyages interstellaires, dystopies, intelligences artificielles. La SF explore les possibles de demain.',         'couleur'=>'#1a4a7a','nb_livres'=>18],
    ['id'=>2,'nom'=>'Philosophie',        'icone'=>'🧠','slug'=>'philo',   'description'=>'Questions fondamentales sur l\'existence, la connaissance et les valeurs humaines.',                               'couleur'=>'#4a1a7a','nb_livres'=>12],
    ['id'=>3,'nom'=>'Nature & Écologie',  'icone'=>'🌿','slug'=>'nature',  'description'=>'Explorations du monde naturel, biodiversité, et lutte pour la préservation de notre planète.',                   'couleur'=>'#1a6b3a','nb_livres'=>9],
    ['id'=>4,'nom'=>'Technologie',        'icone'=>'⚙️','slug'=>'tech',    'description'=>'IA, blockchain, cybersécurité, innovation. Comprendre et anticiper les révolutions numériques.',                 'couleur'=>'#7a3a1a','nb_livres'=>21],
    ['id'=>5,'nom'=>'Histoire',           'icone'=>'📜','slug'=>'histoire', 'description'=>'Des empires antiques aux conflits modernes, revisitez les grandes pages de l\'humanité.',                       'couleur'=>'#1a5a7a','nb_livres'=>15],
    ['id'=>6,'nom'=>'Littérature',        'icone'=>'🎭','slug'=>'lit',     'description'=>'Romans, nouvelles, essais littéraires. L\'exploration infinie de l\'expérience humaine à travers les mots.',     'couleur'=>'#5a1a7a','nb_livres'=>27],
    ['id'=>7,'nom'=>'Sciences',           'icone'=>'🔬','slug'=>'science', 'description'=>'Physique quantique, biologie moléculaire, cosmologie. La science à la portée de tous.',                          'couleur'=>'#1a7a5a','nb_livres'=>14],
    ['id'=>8,'nom'=>'Arts & Culture',     'icone'=>'🎨','slug'=>'arts',    'description'=>'Peinture, musique, cinéma, architecture. Tout ce qui élève l\'âme humaine.',                                    'couleur'=>'#6b1a3a','nb_livres'=>11],
    ['id'=>9,'nom'=>'Développement',      'icone'=>'🚀','slug'=>'dev',     'description'=>'Croissance personnelle, leadership, productivité et bien-être. Devenez la meilleure version de vous-même.',     'couleur'=>'#3a1a7a','nb_livres'=>16],
    ['id'=>10,'nom'=>'Journaux',          'icone'=>'📰','slug'=>'journaux','description'=>'Analyses d\'actualité, reportages de fond et chroniques. Restez informé avec les meilleures plumes.',            'couleur'=>'#7a1a5a','nb_livres'=>8],
    ['id'=>11,'nom'=>'Récits & Voyages',  'icone'=>'✍️','slug'=>'recits', 'description'=>'Témoignages, carnets de voyage et récits de vie. Des histoires vraies qui bouleversent.',                         'couleur'=>'#1a3a7a','nb_livres'=>13],
    ['id'=>12,'nom'=>'Économie',          'icone'=>'📊','slug'=>'eco',     'description'=>'Marchés financiers, entreprises, mondialisation. Comprendre les forces qui façonnent notre quotidien.',          'couleur'=>'#3a7a1a','nb_livres'=>10],
];

$demoBooksByCategory = [
    1 => [
        ['id'=>1,'titre'=>"L'Œil de l'Univers",'auteur'=>'Elena Korvach','prix'=>4500,'note_moyenne'=>4.9,'pages'=>342,'annee_parution'=>2023,'emoji'=>'🌌'],
        ['id'=>2,'titre'=>'Le Paradoxe Stellaire','auteur'=>'Dr. Kai Tanaka','prix'=>3800,'note_moyenne'=>4.6,'pages'=>289,'annee_parution'=>2024,'emoji'=>'⭐'],
        ['id'=>3,'titre'=>'Chroniques du Vide','auteur'=>'Marcus Osei','prix'=>0,'note_moyenne'=>1.9,'pages'=>198,'annee_parution'=>2022,'emoji'=>'🌑'],
        ['id'=>4,'titre'=>'Horizons Infinis','auteur'=>'Yuki Tanaka','prix'=>5200,'note_moyenne'=>4.8,'pages'=>412,'annee_parution'=>2023,'emoji'=>'🚀'],
    ],
    2 => [
        ['id'=>5,'titre'=>'Le Paradoxe du Libre Arbitre','auteur'=>'Jean-Marc Duvall','prix'=>3200,'note_moyenne'=>4.7,'pages'=>289,'annee_parution'=>2022,'emoji'=>'🧠'],
        ['id'=>6,'titre'=>'Éthique & Modernité','auteur'=>'Sofia Mercier','prix'=>2800,'note_moyenne'=>4.3,'pages'=>245,'annee_parution'=>2021,'emoji'=>'⚖️'],
        ['id'=>7,'titre'=>'Le Silence des Dieux','auteur'=>'Pavel Novak','prix'=>0,'note_moyenne'=>1.6,'pages'=>178,'annee_parution'=>2020,'emoji'=>'🕊️'],
    ],
    4 => [
        ['id'=>8,'titre'=>'IA & Humanité','auteur'=>'Dr. Kai Tanaka','prix'=>6800,'note_moyenne'=>4.8,'pages'=>412,'annee_parution'=>2024,'emoji'=>'⚙️'],
        ['id'=>9,'titre'=>'Code Mortel','auteur'=>'Amine Berrada','prix'=>4200,'note_moyenne'=>4.5,'pages'=>356,'annee_parution'=>2023,'emoji'=>'💻'],
        ['id'=>10,'titre'=>'Algorithme Vivant','auteur'=>'Clara Hoffmann','prix'=>0,'note_moyenne'=>2.0,'pages'=>198,'annee_parution'=>2022,'emoji'=>'🤖'],
        ['id'=>11,'titre'=>'Neuro-Chimère','auteur'=>'Diego Vasquez','prix'=>5500,'note_moyenne'=>4.7,'pages'=>388,'annee_parution'=>2024,'emoji'=>'🧬'],
    ],
    6 => [
        ['id'=>12,'titre'=>'Masques & Miroirs','auteur'=>'Léon Beaumont','prix'=>0,'note_moyenne'=>1.5,'pages'=>276,'annee_parution'=>2023,'emoji'=>'🎭'],
        ['id'=>13,'titre'=>'Fragments d\'Éternité','auteur'=>'Isabelle Renaud','prix'=>3400,'note_moyenne'=>4.4,'pages'=>312,'annee_parution'=>2022,'emoji'=>'✨'],
        ['id'=>14,'titre'=>'Poussière de Rêves','auteur'=>'Fatou Sow','prix'=>2600,'note_moyenne'=>4.2,'pages'=>234,'annee_parution'=>2021,'emoji'=>'🌙'],
    ],
];

if ($pdo !== null) {
    try {
        // Catégories avec compteur
        $categories = $pdo->query("
            SELECT c.id, c.nom, c.icone, c.slug,
                   COALESCE(c.description,'') AS description,
                   COUNT(l.id) AS nb_livres,
                   SUM(CASE WHEN l.prix = 0 THEN 1 ELSE 0 END) AS nb_gratuits,
                   ROUND(AVG(l.note_moyenne),1) AS note_moy
            FROM categories c
            LEFT JOIN livres l ON l.categorie_id = c.id AND l.statut = 'disponible'
            GROUP BY c.id, c.nom, c.icone, c.slug, c.description
            ORDER BY nb_livres DESC, c.nom
        ")->fetchAll();

        $stats['total_cats']  = count($categories);
        $stats['total_books'] = array_sum(array_column($categories,'nb_livres'));
        $stats['total_free']  = array_sum(array_column($categories,'nb_gratuits'));

        // Livres de la catégorie active
        if ($activeCatId > 0) {
            $stmtCat = $pdo->prepare("
                SELECT id, nom, icone, COALESCE(description,'') AS description
                FROM categories WHERE id = :id
            ");
            $stmtCat->execute([':id' => $activeCatId]);
            $activeCat = $stmtCat->fetch();

            if ($activeCat) {
                $stmtBooks = $pdo->prepare("
                    SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.pages, l.annee_parution
                    FROM livres l
                    WHERE l.categorie_id = :id AND l.statut = 'disponible'
                    ORDER BY l.note_moyenne DESC LIMIT 8
                ");
                $stmtBooks->execute([':id' => $activeCatId]);
                $catBooks = $stmtBooks->fetchAll();
            }
        }
    } catch (PDOException $e) {
        $pdo = null;
    }
}

// Fallback démo
if ($pdo === null || empty($categories)) {
    $categories = $demoCategories;
    $stats['total_cats']  = count($categories);
    $stats['total_books'] = array_sum(array_column($categories,'nb_livres'));
    $stats['total_free']  = 28;

    if ($activeCatId > 0) {
        foreach ($categories as $cat) {
            if ((int)$cat['id'] === $activeCatId) { $activeCat = $cat; break; }
        }
        $catBooks = $demoBooksByCategory[$activeCatId] ?? array_slice($demoBooksByCategory[1], 0, 4);
    }
}

// Couleurs de couverture
$coverColors = [
    ['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],
    ['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],
    ['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],['#0d3c2a','#1a7a5a'],
    ['#2a0d1a','#6b1a3a'],['#0d1a3c','#1a3a7a'],['#3c0d2a','#7a1a5a'],
];
$emojis = ['📘','📙','📗','📕','📔','📒','📓','🔮','💡','🌊','🏔️','⚡'];

// Gradients par catégorie
$catGradients = [
    '#1a4a7a,#0d1f3c','#4a1a7a,#1a0d3c','#1a6b3a,#0d3020',
    '#7a3a1a,#3c1a0d','#1a5a7a,#0d2a3c','#5a1a7a,#2a0d3c',
    '#3a6b1a,#1a2a0d','#7a5a1a,#3c2a0d','#1a7a5a,#0d3c2a',
    '#6b1a3a,#2a0d1a','#1a3a7a,#0d1a3c','#7a1a5a,#3c0d2a',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Catégories — Digital Library</title>
<meta name="description" content="Toutes les catégories de la bibliothèque numérique. Livres, journaux, récits classés par thème.">
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
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-track{background:var(--ink)}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* BG */
.bg-orb{position:fixed;border-radius:50%;filter:blur(120px);pointer-events:none;z-index:-1;animation:orbDrift 28s ease-in-out infinite}
.orb-a{width:550px;height:550px;background:rgba(232,201,125,0.04);top:-180px;right:-80px}
.orb-b{width:400px;height:400px;background:rgba(78,204,163,0.04);bottom:-80px;left:-60px;animation-delay:-12s}
@keyframes orbDrift{0%,100%{transform:translate(0,0)}50%{transform:translate(-30px,30px)}}

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
.cat-hero{padding-top:62px;background:linear-gradient(180deg,rgba(78,204,163,.04) 0%,transparent 100%);border-bottom:1px solid var(--glass-border)}
.cat-hero-inner{max-width:1200px;margin:0 auto;padding:2.5rem 2rem 2rem}
.breadcrumb{display:flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--txt-muted);margin-bottom:1.5rem}
.breadcrumb a{color:var(--txt-muted);text-decoration:none;transition:color .2s}.breadcrumb a:hover{color:var(--gold)}
.hero-title{font-family:'Clash Display',sans-serif;font-size:clamp(1.8rem,4vw,3rem);font-weight:700;letter-spacing:-2px;margin-bottom:.4rem}
.hero-title span{background:linear-gradient(135deg,var(--sage),var(--azure));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hero-sub{font-size:.88rem;color:var(--txt-secondary);margin-bottom:1.8rem}

/* STATS BAND */
.stats-band{display:flex;gap:2rem;flex-wrap:wrap;margin-bottom:0}
.stat-item{display:flex;flex-direction:column;gap:2px}
.stat-val{font-family:'Clash Display',sans-serif;font-size:1.4rem;font-weight:700;color:var(--gold)}
.stat-label{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt-muted);letter-spacing:.08em;text-transform:uppercase}

/* MAIN */
main{max-width:1200px;margin:0 auto;padding:2.5rem 2rem 6rem}

/* DETAIL VIEW (catégorie active) */
.cat-detail-banner{background:var(--glass);border:1px solid var(--glass-border);border-radius:20px;padding:2rem;margin-bottom:2.5rem;position:relative;overflow:hidden}
.cat-detail-banner::before{content:'';position:absolute;inset:0;opacity:.08;background:var(--detail-grad);z-index:0}
.cat-detail-inner{position:relative;z-index:1;display:flex;align-items:flex-start;gap:1.5rem;flex-wrap:wrap}
.cat-detail-icon{width:80px;height:80px;border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:2.8rem;background:var(--mist);border:1px solid var(--glass-border);flex-shrink:0}
.cat-detail-info{flex:1;min-width:200px}
.cat-detail-name{font-family:'Clash Display',sans-serif;font-size:1.8rem;font-weight:700;letter-spacing:-1px;margin-bottom:.4rem}
.cat-detail-desc{font-size:.88rem;color:var(--txt-secondary);line-height:1.7;margin-bottom:1rem}
.cat-detail-meta{display:flex;gap:1rem;flex-wrap:wrap}
.cat-meta-pill{display:inline-flex;align-items:center;gap:5px;font-family:'JetBrains Mono',monospace;font-size:.65rem;padding:4px 10px;border-radius:100px;border:1px solid var(--glass-border);color:var(--txt-muted)}
.cat-meta-pill i{color:var(--sage)}
.cat-detail-actions{display:flex;gap:.8rem;align-items:center;flex-shrink:0}
.btn-explore-cat{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.85rem;font-weight:700;text-decoration:none;transition:all .2s}
.btn-explore-cat:hover{opacity:.88;transform:translateY(-1px)}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:10px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-family:'Clash Display',sans-serif;font-size:.85rem;font-weight:600;text-decoration:none;transition:all .2s}
.btn-back:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}

/* CAT BOOKS MINI GRID */
.cat-books-section{margin-bottom:3rem}
.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem}
.section-head-title{font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:600}
.see-all-link{font-size:.78rem;color:var(--gold);text-decoration:none;display:flex;align-items:center;gap:4px;transition:gap .2s}
.see-all-link:hover{gap:8px}
.mini-books-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem}
.mini-book-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:12px;overflow:hidden;transition:all .3s var(--ease-spring);text-decoration:none;color:inherit;display:block}
.mini-book-card:hover{transform:translateY(-6px);border-color:rgba(232,201,125,.25);box-shadow:0 16px 40px rgba(0,0,0,.5)}
.mini-cover{height:120px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.mini-cover-bg{position:absolute;inset:0}
.mini-cover-emoji{font-size:2.5rem;position:relative;z-index:1;filter:drop-shadow(0 3px 8px rgba(0,0,0,.4))}
.mini-badge{position:absolute;top:6px;right:6px;z-index:2;font-family:'JetBrains Mono',monospace;font-size:.52rem;padding:2px 6px;border-radius:100px}
.mini-badge-free{background:rgba(78,204,163,.15);color:var(--sage);border:1px solid rgba(78,204,163,.3)}
.mini-badge-pay{background:rgba(232,201,125,.15);color:var(--gold);border:1px solid rgba(232,201,125,.3)}
.mini-body{padding:.7rem .8rem}
.mini-title{font-family:'Clash Display',sans-serif;font-size:.78rem;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.mini-author{font-size:.65rem;color:var(--txt-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mini-rating{display:flex;align-items:center;justify-content:space-between;margin-top:.5rem}
.mini-stars{color:var(--gold);font-size:.58rem}
.mini-price{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--gold)}
.mini-price.free{color:var(--sage)}

/* CATEGORIES GRID */
.cats-section-title{font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:600;margin-bottom:1.2rem;display:flex;align-items:center;gap:8px}
.cats-section-title .eyebrow{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--sage);letter-spacing:.12em;text-transform:uppercase}
.cats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.1rem}

/* CATEGORY CARD */
.cat-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:18px;padding:1.5rem;cursor:pointer;transition:all .3s var(--ease-spring);position:relative;overflow:hidden;text-decoration:none;color:inherit;display:block;animation:catIn .45s var(--ease-smooth) both}
@keyframes catIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
.cat-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--cat-c1,.07) 0%,transparent 60%);opacity:.07;z-index:0;transition:opacity .3s}
.cat-card:hover{transform:translateY(-6px) scale(1.015);border-color:rgba(255,255,255,.12);box-shadow:0 20px 50px rgba(0,0,0,.5)}
.cat-card:hover::before{opacity:.14}
.cat-card-inner{position:relative;z-index:1}
.cat-icon-wrap{width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:1.8rem;margin-bottom:1rem;border:1px solid var(--glass-border);background:var(--mist)}
.cat-name{font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;letter-spacing:-.4px;margin-bottom:.4rem}
.cat-desc{font-size:.78rem;color:var(--txt-secondary);line-height:1.65;margin-bottom:1rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.cat-footer{display:flex;align-items:center;justify-content:space-between}
.cat-count{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt-muted);display:flex;align-items:center;gap:5px}
.cat-count i{color:var(--sage)}
.cat-arrow{width:30px;height:30px;border-radius:8px;border:1px solid var(--glass-border);background:var(--glass);display:flex;align-items:center;justify-content:center;font-size:.75rem;color:var(--txt-muted);transition:all .3s}
.cat-card:hover .cat-arrow{border-color:rgba(232,201,125,.3);color:var(--gold);transform:translateX(3px)}
/* Note badge */
.cat-note{display:inline-flex;align-items:center;gap:3px;font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--gold);background:rgba(232,201,125,.08);padding:2px 7px;border-radius:100px;border:1px solid rgba(232,201,125,.15)}

/* TOAST */
#toast{position:fixed;bottom:2rem;right:2rem;z-index:9800;background:rgba(13,18,32,.97);border:1px solid rgba(232,201,125,.2);border-radius:14px;padding:1rem 1.3rem;display:flex;align-items:center;gap:12px;font-size:.8rem;backdrop-filter:blur(20px);transform:translateY(100px) scale(.96);opacity:0;transition:all .4s var(--ease-spring);pointer-events:none;max-width:300px}
#toast.show{transform:translateY(0) scale(1);opacity:1;pointer-events:auto}
#toast.t-success{border-color:rgba(78,204,163,.3)}
.t-icon{font-size:1.1rem}.t-text{display:flex;flex-direction:column;gap:2px}
.t-title{font-family:'Clash Display',sans-serif;font-weight:600;font-size:.82rem}
.t-msg{font-size:.72rem;color:var(--txt-muted)}

/* FOOTER */
footer{border-top:1px solid var(--glass-border);padding:2rem;background:rgba(0,0,0,.2);text-align:center}
.footer-links{display:flex;gap:1.5rem;justify-content:center;flex-wrap:wrap;margin-bottom:1rem}
.footer-links a{font-size:.78rem;color:var(--txt-muted);text-decoration:none;transition:color .2s}
.footer-links a:hover{color:var(--gold)}
.footer-copy{font-size:.7rem;color:var(--txt-muted)}

/* Responsive */
@media(max-width:640px){
  .cat-detail-inner{flex-direction:column}
  .cat-detail-actions{width:100%}
  .btn-explore-cat,.btn-back{flex:1;justify-content:center}
  .cats-grid{grid-template-columns:repeat(2,1fr)}
  .mini-books-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:380px){
  .cats-grid{grid-template-columns:1fr}
  .mini-books-grid{grid-template-columns:1fr}
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
    <li><a href="explorer.php">Explorer</a></li>
    <li><a href="categories.php" class="active">Catégories</a></li>
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
<div class="cat-hero">
  <div class="cat-hero-inner">
    <div class="breadcrumb">
      <a href="index.php">Accueil</a><span>›</span>
      <?php if ($activeCat): ?>
        <a href="categories.php">Catégories</a><span>›</span>
        <span style="color:var(--txt-secondary)"><?= htmlspecialchars($activeCat['icone'].' '.$activeCat['nom']) ?></span>
      <?php else: ?>
        <span style="color:var(--txt-secondary)">Catégories</span>
      <?php endif; ?>
    </div>

    <?php if ($activeCat): ?>
      <h1 class="hero-title"><?= htmlspecialchars($activeCat['icone']) ?> <span><?= htmlspecialchars($activeCat['nom']) ?></span></h1>
    <?php else: ?>
      <h1 class="hero-title">Toutes les <span>Catégories</span></h1>
    <?php endif; ?>

    <p class="hero-sub">
      <?php if ($activeCat): ?>
        <?= htmlspecialchars($activeCat['description'] ?? '') ?>
      <?php else: ?>
        <?= $stats['total_cats'] ?> catégories · <?= number_format((int)$stats['total_books']) ?> livres disponibles dont <?= $stats['total_free'] ?>+ gratuits
      <?php endif; ?>
    </p>

    <?php if (!$activeCat): ?>
    <div class="stats-band">
      <div class="stat-item"><div class="stat-val"><?= count($categories) ?></div><div class="stat-label">Catégories</div></div>
      <div class="stat-item"><div class="stat-val"><?= number_format((int)$stats['total_books']) ?>+</div><div class="stat-label">Livres</div></div>
      <div class="stat-item"><div class="stat-val" style="color:var(--sage)"><?= $stats['total_free'] ?>+</div><div class="stat-label">Gratuits</div></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- MAIN -->
<main>

  <?php if ($activeCat): ?>
  <!-- ── VUE CATÉGORIE ACTIVE ── -->
  <div class="cat-detail-banner" style="--detail-grad:linear-gradient(135deg,<?= htmlspecialchars($catGradients[$activeCatId % count($catGradients)]) ?>)">
    <div class="cat-detail-inner">
      <div class="cat-detail-icon"><?= htmlspecialchars($activeCat['icone'] ?? '📚') ?></div>
      <div class="cat-detail-info">
        <div class="cat-detail-name"><?= htmlspecialchars($activeCat['nom']) ?></div>
        <div class="cat-detail-desc"><?= htmlspecialchars($activeCat['description'] ?? '') ?></div>
        <div class="cat-detail-meta">
          <span class="cat-meta-pill"><i class="bi bi-book"></i> <?= count($catBooks) ?>+ livres</span>
          <span class="cat-meta-pill"><i class="bi bi-grid"></i> Collection curatée</span>
          <?php if ($isLoggedIn): ?><span class="cat-meta-pill"><i class="bi bi-unlock"></i> Accès complet</span><?php endif; ?>
        </div>
      </div>
      <div class="cat-detail-actions">
        <a href="categories.php" class="btn-back"><i class="bi bi-arrow-left"></i> Retour</a>
        <a href="explorer.php?cat=<?= $activeCatId ?>" class="btn-explore-cat"><i class="bi bi-compass"></i> Explorer tout</a>
      </div>
    </div>
  </div>

  <?php if (!empty($catBooks)): ?>
  <div class="cat-books-section">
    <div class="section-head">
      <div class="section-head-title">Livres de cette catégorie</div>
      <a href="explorer.php?cat=<?= $activeCatId ?>" class="see-all-link">Voir tout <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="mini-books-grid">
      <?php foreach ($catBooks as $i => $book):
        $colors    = $coverColors[$i % count($coverColors)];
        $emoji     = $book['emoji'] ?? $emojis[$i % count($emojis)];
        $isFree    = (float)($book['prix']??0) === 0.0 || (float)($book['note_moyenne']??0) <= 2.0;
        $note      = (float)($book['note_moyenne']??0);
        $stars     = str_repeat('★',min(5,round($note))).str_repeat('☆',max(0,5-round($note)));
        $prixFmt   = $isFree ? 'Gratuit' : number_format((float)$book['prix'],0,'.',' ').' F';
        $animDelay = $i * 60;
      ?>
      <a href="explorer.php?cat=<?= $activeCatId ?>&q=<?= urlencode($book['titre']) ?>"
         class="mini-book-card" style="animation-delay:<?= $animDelay ?>ms"
         onclick="event.preventDefault();toastInfo('<?= htmlspecialchars(addslashes($book['titre'])) ?>','<?= htmlspecialchars(addslashes($book['auteur']??'')) ?> · <?= $prixFmt ?>')">
        <div class="mini-cover">
          <div class="mini-cover-bg" style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)"></div>
          <div class="mini-cover-emoji"><?= $emoji ?></div>
          <span class="mini-badge <?= $isFree?'mini-badge-free':'mini-badge-pay' ?>"><?= $isFree?'GRATUIT':'PREMIUM' ?></span>
        </div>
        <div class="mini-body">
          <div class="mini-title"><?= htmlspecialchars($book['titre']) ?></div>
          <div class="mini-author">par <?= htmlspecialchars($book['auteur']??'—') ?></div>
          <div class="mini-rating">
            <span class="mini-stars"><?= $stars ?></span>
            <span class="mini-price <?= $isFree?'free':'' ?>"><?= $prixFmt ?></span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="text-align:center;margin:2rem 0">
    <a href="explorer.php?cat=<?= $activeCatId ?>" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:12px;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.9rem;font-weight:700;text-decoration:none;transition:all .2s">
      <i class="bi bi-compass"></i> Voir tous les livres de cette catégorie
    </a>
  </div>
  <?php endif; ?>

  <!-- Autres catégories -->
  <div class="cats-section-title" style="margin-top:3rem">
    <span class="eyebrow">Navigation</span>
    Autres catégories
  </div>

  <?php else: ?>
  <!-- ── VUE TOUTES CATÉGORIES ── -->
  <div class="cats-section-title">
    <span class="eyebrow">Catalogue</span>
    Choisissez votre univers
  </div>
  <?php endif; ?>

  <!-- GRILLE CATÉGORIES -->
  <div class="cats-grid">
    <?php foreach ($categories as $i => $cat):
      if ($activeCat && (int)$cat['id'] === $activeCatId) continue; // Masquer la catégorie active dans la liste
      $gradIdx = $i % count($catGradients);
      $gparts  = explode(',', $catGradients[$gradIdx]);
      $c1raw   = $gparts[0] ?? '#1a4a7a';
      $c2raw   = $gparts[1] ?? '#0d1f3c';
      $animD   = ($i % 6) * 55;
      $nbBooks = (int)($cat['nb_livres'] ?? 0);
      $noteMoy = isset($cat['note_moy']) ? (float)$cat['note_moy'] : 0;
    ?>
    <a href="categories.php?id=<?= (int)$cat['id'] ?>"
       class="cat-card"
       style="animation-delay:<?= $animD ?>ms;--cat-c1:<?= htmlspecialchars($c1raw) ?>">
      <div class="cat-card-inner">
        <div class="cat-icon-wrap" style="background:linear-gradient(135deg,<?= htmlspecialchars($c1raw) ?>44,<?= htmlspecialchars($c2raw) ?>44)">
          <?= htmlspecialchars($cat['icone']??'📚') ?>
        </div>
        <div class="cat-name"><?= htmlspecialchars($cat['nom']) ?></div>
        <div class="cat-desc"><?= htmlspecialchars($cat['description'] ?? 'Explorez cette catégorie pour découvrir de nouveaux ouvrages.') ?></div>
        <div class="cat-footer">
          <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
            <span class="cat-count"><i class="bi bi-book"></i> <?= $nbBooks ?> livre<?= $nbBooks!==1?'s':'' ?></span>
            <?php if ($noteMoy > 0): ?>
              <span class="cat-note">⭐ <?= number_format($noteMoy,1) ?></span>
            <?php endif; ?>
          </div>
          <span class="cat-arrow"><i class="bi bi-arrow-right"></i></span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- CTA Explorer -->
  <div style="text-align:center;margin-top:3.5rem;padding:2.5rem;background:var(--glass);border:1px solid var(--glass-border);border-radius:20px">
    <div style="font-family:'Clash Display',sans-serif;font-size:1.5rem;font-weight:700;letter-spacing:-1px;margin-bottom:.6rem">
      Vous ne trouvez pas votre genre ?
    </div>
    <p style="font-size:.88rem;color:var(--txt-secondary);margin-bottom:1.5rem">
      Utilisez l'explorateur pour rechercher parmi toute la bibliothèque.
    </p>
    <a href="explorer.php" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:12px;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.9rem;font-weight:700;text-decoration:none">
      <i class="bi bi-compass"></i> Explorer tout le catalogue
    </a>
  </div>
</main>

<footer>
  <div class="footer-links">
    <a href="index.php">Accueil</a><a href="explorer.php">Explorer</a>
    <a href="tendances.php">Tendances</a><a href="aide.php">Aide</a>
  </div>
  <p class="footer-copy">© <?= date('Y') ?> Digital Library System — Tous droits réservés.</p>
</footer>

<!-- TOAST -->
<div id="toast"><span class="t-icon" id="t-icon">ℹ️</span><div class="t-text"><span class="t-title" id="t-title"></span><span class="t-msg" id="t-msg"></span></div></div>

<script>
'use strict';
// Header scroll
const hdr=document.getElementById('site-header');
window.addEventListener('scroll',()=>hdr.classList.toggle('scrolled',scrollY>60));

// Hamburger
const ham=document.getElementById('hamburger'),mnav=document.getElementById('mobile-nav');
ham.addEventListener('click',()=>{const o=mnav.classList.toggle('open');ham.innerHTML=o?'<i class="bi bi-x-lg"></i>':'<i class="bi bi-list"></i>';});

// Toast
let toastT=null;
function showToast(title,msg='',type='default',dur=3500){
  const el=document.getElementById('toast');
  el.className=type!=='default'?'t-'+type:'';
  const icons={default:'ℹ️',success:'✅',error:'❌',warn:'⚠️'};
  document.getElementById('t-icon').textContent=icons[type]||'ℹ️';
  document.getElementById('t-title').textContent=title;
  document.getElementById('t-msg').textContent=msg;
  el.classList.add('show');
  clearTimeout(toastT);toastT=setTimeout(()=>el.classList.remove('show'),dur);
}
function toastInfo(title,msg){showToast(title,msg,'default',4000);}

// Keyboard escape
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'&&document.getElementById('toast').classList.contains('show')){
    document.getElementById('toast').classList.remove('show');
  }
});

// Welcome toast
<?php if ($activeCat): ?>
showToast('<?= htmlspecialchars(addslashes($activeCat['icone'].' '.$activeCat['nom'])) ?>','<?= count($catBooks) ?> livres disponibles dans cette catégorie.','success',4000);
<?php endif; ?>
</script>
</body>
</html>