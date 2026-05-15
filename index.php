<?php
// ============================================================
// DIGITAL LIBRARY SYSTEM — index.php
// ============================================================


if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = null;
$configPath = __DIR__ . '/includes/config.php';

if (file_exists($configPath)) {
    require_once $configPath;
}

if (!isset($pdo) || $pdo === null) {

    $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db_name = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $db_user = defined('DB_USER') ? DB_USER : 'root';
    $db_pass = defined('DB_PASS') ? DB_PASS : '';

    try {

        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user,
            $db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

    } catch (PDOException $e) {
        $pdo = null;
    }
}

/* =========================================================
   INJECTION DES LIVRES RÉALISTES
========================================================= */

require_once __DIR__ . '/books_data.php';

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin    = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$username = 'Lecteur';

if (!empty($_SESSION['user_name'])) {
    $username = htmlspecialchars($_SESSION['user_name']);
} elseif (!empty($_SESSION['user_prenom']) && !empty($_SESSION['user_nom'])) {
    $username = htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']);
}
$userId = $_SESSION['user_id'] ?? null;

$books = []; $cats = []; $trending = [];
$stats = ['total_livres' => 0, 'total_users' => 0, 'total_ventes' => 0];

if ($pdo !== null) {
    try {
        $stats['total_livres'] = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
        $stats['total_users']  = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stats['total_ventes'] = (int)$pdo->query("SELECT COALESCE(SUM(nb_ventes),0) FROM livres")->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes,
                   l.couverture, l.pages, l.description, l.contenu_extrait,
                   c.nom AS genre, c.icone AS genre_icone,
                   l.annee_parution, l.editeur
            FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE l.statut = 'disponible'
            ORDER BY l.note_moyenne DESC, l.nb_ventes DESC
            LIMIT 12
        ");
        $stmt->execute();
        $books = $stmt->fetchAll();
        $cats     = $pdo->query("SELECT id, nom, icone, slug FROM categories ORDER BY id")->fetchAll();
        $trending = $pdo->query("
            SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, c.icone,
                   l.contenu_extrait, l.pages
            FROM livres l LEFT JOIN categories c ON c.id=l.categorie_id
            WHERE l.statut='disponible' ORDER BY l.nb_ventes DESC LIMIT 5
        ")->fetchAll();
    } catch (PDOException $e) { $pdo = null; }
}

// ── Contenu démo multi-chapitres (5+ chapitres par livre) ──
$demoBooks = [
    [
        'id'=>1,'titre'=>"L'Œil de l'Univers",'auteur'=>'Elena Korvach','prix'=>4500,
        'note_moyenne'=>4.9,'nb_ventes'=>8234,'genre'=>'Science-Fiction','genre_icone'=>'🌌',
        'pages'=>342,'description'=>'Une épopée galactique captivante.',
        'couverture'=>null,'annee_parution'=>2023,'editeur'=>'Gallimard',
        'contenu_extrait'=>"CHAPITRE I — L'Éveil\n\nIl était une fois dans une galaxie lointaine, une civilisation qui avait atteint les sommets de la connaissance. Les étoiles n'étaient plus des mystères mais des destinations. Les trous noirs, des portes vers l'infini.\n\nElena Korvach, scientifique de renom, fixait l'horizon galactique depuis son observatoire orbital. Ses yeux, fatigués de milliers d'heures de recherche, reflétaient la lumière de mille soleils.\n\n— L'univers nous parle, murmura-t-elle. Nous devons apprendre à écouter.\n\n||||PAGE||||CHAPITRE II — La Découverte\n\nLes semaines suivantes furent frénétiques. L'équipe entière se mobilisa autour de cette anomalie baptisée simplement : l'Œil. Un signal d'une régularité mathématique parfaite émanait de ce point précis de l'espace.\n\nLes théories fusèrent. Intelligence extraterrestre ? Phénomène naturel inconnu ? Artefact d'une civilisation disparue ?\n\nElena refusait de spéculer. Elle voulait des preuves. Des données. De la certitude.\n\n— Nous allons y envoyer une sonde, annonça-t-elle. Préparations dans 72 heures.\n\n||||PAGE||||CHAPITRE III — Le Voyage\n\nLa sonde Nova-7 traversa des millions de kilomètres en quelques semaines grâce au nouveau moteur à distorsion. Elena suivait chaque télémétrie, chaque variation de signal.\n\nQuand les premières images arrivèrent, un silence de cathédrale s'abattit sur la salle de contrôle.\n\nL'Œil n'était pas une nébuleuse. C'était une structure. Artificielle. D'une taille dépassant l'entendement.\n\n— Dieu du ciel, souffla le directeur de mission.\n\nElena, elle, souriait. Elle savait depuis le début.\n\n||||PAGE||||CHAPITRE IV — Le Contact\n\nLe signal changea le jour où Nova-7 atteignit la structure. Il devint plus complexe, plus riche, comme si l'Œil reconnaissait leur présence et adaptait sa communication.\n\nLes linguistes, les mathématiciens, les philosophes : tous furent convoqués. En quatre-vingt-seize heures, le premier message fut déchiffré :\n\n« Bienvenue. Nous vous attendions. »\n\nL'humanité n'était plus seule.\n\n||||PAGE||||CHAPITRE V — L'Héritage\n\nCe que l'humanité apprit au contact de cette civilisation ancienne révolutionna chaque aspect de la vie humaine. La médecine, la physique, la philosophie, l'art.\n\nElena passa les trente dernières années de sa vie à servir de pont entre les deux civilisations. Son nom resterait gravé dans l'histoire comme celui de Colomb, mais en infiniment plus grand.\n\nCar elle n'avait pas découvert un nouveau continent.\n\nElle avait découvert que l'univers était habité, et que nous n'étions qu'au commencement de notre véritable histoire.",
    ],
    [
        'id'=>2,'titre'=>'Le Paradoxe du Libre Arbitre','auteur'=>'Jean-Marc Duvall','prix'=>3200,
        'note_moyenne'=>4.7,'nb_ventes'=>6120,'genre'=>'Philosophie','genre_icone'=>'🧠',
        'pages'=>289,'description'=>'Une réflexion profonde sur la liberté humaine.',
        'couverture'=>null,'annee_parution'=>2022,'editeur'=>'Le Seuil',
        'contenu_extrait'=>"CHAPITRE I — La Question Initiale\n\nDepuis l'aube de la conscience, l'humanité s'est posé une question fondamentale : sommes-nous libres ?\n\nJean-Marc Duvall a consacré trente ans à cette interrogation. Ses travaux, controversés et brillants, ont bouleversé le monde académique.\n\nDans ce livre, il nous invite à un voyage au cœur du libre arbitre.\n\n||||PAGE||||CHAPITRE II — Les Déterministes\n\nLes déterministes affirment que chaque action humaine est le résultat inévitable d'une chaîne causale remontant à la nuit des temps. Vos choix d'aujourd'hui étaient inscrits dans les lois de la physique bien avant votre naissance.\n\nSpinoza, Laplace, plus récemment des neuroscientifiques comme Benjamin Libet : tous pointent vers la même conclusion troublante. L'expérience de Libet est particulièrement dérangeante : notre cerveau prend la décision plusieurs centaines de millisecondes avant que nous en soyons conscients.\n\n||||PAGE||||CHAPITRE III — Les Libertariens Philosophiques\n\nMais le débat est loin d'être clos. Des philosophes comme Robert Kane soutiennent que l'indéterminisme quantique ouvre une brèche réelle dans la causalité déterministe.\n\nSi les événements quantiques sont genuinement aléatoires, et si notre cerveau est sensible à ces fluctuations, alors la causalité stricte est brisée. Le chaos offre peut-être la liberté là où la physique classique la refusait.\n\n||||PAGE||||CHAPITRE IV — Le Compatibilisme\n\nDuvall défend une position nuancée : le compatibilisme. Liberté et déterminisme ne sont pas incompatibles si l'on redéfinit correctement la liberté.\n\nÊtre libre ne signifie pas échapper à la causalité. Cela signifie agir selon ses propres désirs, valeurs et raisons, sans contrainte externe. Cette liberté-là est réelle et suffisante pour fonder la responsabilité morale.\n\n||||PAGE||||CHAPITRE V — Les Implications\n\nSi nous acceptons que le libre arbitre est une construction utile plutôt qu'une réalité métaphysique absolue, quelles conséquences pour notre société ?\n\nLa justice, la morale, le mérite, la punition : tout notre édifice social repose sur l'idée que les individus sont responsables de leurs actes.\n\nDuvall nous invite à construire une éthique plus compassionnelle, fondée non sur la punition mais sur la compréhension et la transformation.",
    ],
    [
        'id'=>3,'titre'=>'Forêts Oubliées','auteur'=>'Amara Diallo','prix'=>0,
        'note_moyenne'=>1.8,'nb_ventes'=>4560,'genre'=>'Nature','genre_icone'=>'🌿',
        'pages'=>198,'description'=>'Un voyage au cœur de la forêt africaine.',
        'couverture'=>null,'annee_parution'=>2021,'editeur'=>'Actes Sud',
        'contenu_extrait'=>"CHAPITRE I — Les Murmures de la Forêt\n\nLes arbres murmuraient des secrets anciens que seul le vent connaissait. Amara marchait depuis l'aube dans cette forêt primaire, son carnet de notes sous le bras, les sens en éveil.\n\nChaque pas révélait un nouveau prodige : une fleur inconnue, un insecte aux couleurs impossibles, un champignon luminescent dans la pénombre.\n\n||||PAGE||||CHAPITRE II — L'Écosystème Vivant\n\nLa forêt africaine est un monde à part entière. Chaque arbre abrite des dizaines d'espèces. Chaque feuille est un écosystème. Les relations entre les êtres vivants forment un réseau d'une complexité vertigineuse.\n\nLes populations locales vivaient en harmonie avec cet espace depuis des millénaires. Leurs savoirs traditionnels dépassaient souvent notre science moderne.\n\n||||PAGE||||CHAPITRE III — Les Gardiens\n\nAu cœur de la forêt vivent les Gardiens — ainsi Amara surnomme-t-elle ces communautés qui ont fait vœu de protéger cet héritage naturel.\n\nLeur connaissance des plantes médicinales, des cycles naturels, des langages animaux est encyclopédique. Chaque ancienne est une bibliothèque vivante.\n\n||||PAGE||||CHAPITRE IV — L'Urgence\n\nMais la déforestation avance. Chaque année, des millions d'hectares disparaissent. Le poumon de la planète suffoque sous les coups de la cupidité et de l'ignorance.\n\nAmara a assisté, impuissante, à l'abattage d'arbres millénaires. Des larmes coulaient sur ses joues — pas de sentimentalisme, mais de la rage lucide.\n\n||||PAGE||||CHAPITRE V — L'Espoir\n\nIl est encore temps d'agir. Amara en était convaincue. Des initiatives locales montrent que reforestation et développement ne sont pas incompatibles.\n\nC'est pourquoi elle écrivait. Pour témoigner. Pour alerter. Pour sauver ce qui peut encore l'être, et transmettre la mémoire de ce monde extraordinaire aux générations futures.",
    ],
    [
        'id'=>4,'titre'=>'IA & Humanité','auteur'=>'Dr. Kai Tanaka','prix'=>6800,
        'note_moyenne'=>4.8,'nb_ventes'=>9874,'genre'=>'Technologie','genre_icone'=>'⚙️',
        'pages'=>412,'description'=>"L'avenir de l'intelligence artificielle.",
        'couverture'=>null,'annee_parution'=>2024,'editeur'=>'Flammarion',
        'contenu_extrait'=>"CHAPITRE I — Les Machines Pensantes\n\nLes machines pensent. La question n'est plus de savoir si elles le peuvent, mais ce que cela signifie pour nous.\n\nDepuis l'invention du premier neurone artificiel jusqu'aux grands modèles de langage d'aujourd'hui, l'intelligence artificielle a traversé plusieurs hivers et plusieurs printemps.\n\n||||PAGE||||CHAPITRE II — L'Apprentissage Profond\n\nLe deep learning a révolutionné le domaine. Des réseaux de neurones artificiels capables de reconnaître des images, comprendre le langage, jouer aux échecs mieux que n'importe quel humain.\n\nMais cette puissance cache une fragilité : ces systèmes ne comprennent pas vraiment. Ils interpolent, ils corrèlent, ils prédisent. La compréhension reste, pour l'instant, un privilège humain.\n\n||||PAGE||||CHAPITRE III — Les Risques Réels\n\nBiais algorithmiques, surveillance de masse, désinformation à grande échelle : les risques sont réels et documentés.\n\nTanaka ne verse pas dans le catastrophisme mais refuse l'angélisme technologique. Les dangers ne viennent pas de machines conscientes qui se rebellent — ils viennent d'humains irresponsables qui déploient des outils puissants sans garde-fous.\n\n||||PAGE||||CHAPITRE IV — La Régulation\n\nFace à ces défis, les gouvernements tentent de réguler. L'Union Européenne a adopté le premier cadre légal mondial sur l'IA.\n\nMais la régulation court toujours derrière la technologie. Comment encadrer ce qu'on ne comprend pas encore ? C'est le défi central de notre époque.\n\n||||PAGE||||CHAPITRE V — La Coexistence\n\nTanaka plaide pour une coexistence harmonieuse. L'IA comme outil au service de l'humanité, et non comme concurrent.\n\nLa clé : garder l'humain au centre de toutes les décisions. Pas d'automatisation sans supervision. Pas de déploiement sans responsabilité. L'avenir appartient à ceux qui sauront allier l'intelligence artificielle à la sagesse humaine.",
    ],
    [
        'id'=>5,'titre'=>'Empires Disparus','auteur'=>'Sofia Mercier','prix'=>2800,
        'note_moyenne'=>4.5,'nb_ventes'=>3210,'genre'=>'Histoire','genre_icone'=>'📜',
        'pages'=>534,'description'=>'La chute des grands empires mondiaux.',
        'couverture'=>null,'annee_parution'=>2020,'editeur'=>'Fayard',
        'contenu_extrait'=>"CHAPITRE I — Rome Éternelle\n\nCarthage devait être détruite, et elle le fut. Mais Rome elle-même ne résista pas au temps. Comment l'empire le plus puissant de l'Antiquité s'est-il effondré ?\n\nSofia Mercier retrace avec maestria les mécanismes internes qui rongeaient l'Empire romain de l'intérieur : corruption, inégalités, surextension militaire.\n\n||||PAGE||||CHAPITRE II — La Perse des Rois\n\nL'empire perse s'étendait de l'Inde à l'Égypte. Sa chute face à Alexandre reste l'un des tournants les plus spectaculaires de l'histoire antique.\n\nPourtant, cet empire avait duré deux siècles et avait développé une administration sophistiquée, une tolérance religieuse remarquable pour son époque.\n\n||||PAGE||||CHAPITRE III — Les Mongols\n\nGengis Khan construisit l'empire le plus vaste de l'histoire. En quelques décennies, ses successeurs le virent se fragmenter.\n\nLa violence de la conquête mongole cache une réalité méconnue : la Pax Mongolica permit l'essor du commerce sur la route de la soie et facilita des échanges culturels inédits.\n\n||||PAGE||||CHAPITRE IV — L'Empire Ottoman\n\nL'empire ottoman dura six siècles. Sa disparition après la Première Guerre mondiale remodela le monde arabe pour toujours.\n\nDe la prise de Constantinople en 1453 au démembrement de 1918, cette civilisation avait su intégrer des dizaines de peuples dans un système administratif cohérent.\n\n||||PAGE||||CHAPITRE V — Leçons du Passé\n\nTous ces empires partagent des points communs dans leur déclin : surextension, corruption, perte de légitimité, incapacité à s'adapter.\n\nDes leçons que nos démocraties modernes feraient bien de méditer. Car si les formes du pouvoir changent, sa fragilité, elle, reste éternelle.",
    ],
    [
        'id'=>6,'titre'=>'Masques & Miroirs','auteur'=>'Léon Beaumont','prix'=>0,
        'note_moyenne'=>1.5,'nb_ventes'=>2100,'genre'=>'Littérature','genre_icone'=>'🎭',
        'pages'=>276,'description'=>'Un roman psychologique haletant.',
        'couverture'=>null,'annee_parution'=>2023,'editeur'=>'Grasset',
        'contenu_extrait'=>"CHAPITRE I — Le Personnage\n\nQui suis-je derrière le masque que je porte chaque jour ? Cette question hantait Mathieu depuis toujours.\n\nLe roman s'ouvre sur une scène d'une banalité troublante : Mathieu se prépare pour aller travailler, mais ne reconnaît plus son reflet dans le miroir.\n\n||||PAGE||||CHAPITRE II — Les Doubles\n\nDans ce roman aux multiples miroirs, chaque personnage cache une autre identité. Rien n'est ce qu'il paraît.\n\nBeaumont utilise la technique du double narrateur avec une habileté rare : on ne sait jamais vraiment qui parle, qui observe, qui ment.\n\n||||PAGE||||CHAPITRE III — La Spirale\n\nMathieu descend dans une spirale d'auto-analyse qui menace de l'engloutir. Qui est le masque ? Qui est le visage ?\n\nLes chapitres s'enchaînent avec une urgence croissante. Le style se fragmente, les phrases se raccourcissent, la typographie elle-même semble se décomposer.\n\n||||PAGE||||CHAPITRE IV — La Révélation\n\nLa vérité, quand elle arrive, est à la fois libératrice et terrifiante. Mathieu comprend que l'identité n'est pas un donné mais une construction perpétuelle.\n\nNous sommes tous des auteurs de nous-mêmes, écrivant notre personnage au jour le jour, souvent sans le savoir.\n\n||||PAGE||||CHAPITRE V — La Renaissance\n\nAccepter tous ses visages. Choisir lequel montrer et à qui. C'est peut-être cela, la vraie liberté.\n\nBeaumont nous offre un roman sur la complexité de l'être humain moderne — un miroir tendu vers le lecteur qui, s'il ose y regarder, ne se verra plus jamais de la même façon.",
    ],
];

if ($pdo === null || empty($books)) {
    $books    = $demoBooks;
    $cats     = [
        ['id'=>1,'nom'=>'Livres','icone'=>'📘','slug'=>'livres'],
        ['id'=>2,'nom'=>'Journaux','icone'=>'📰','slug'=>'journaux'],
        ['id'=>3,'nom'=>'Récits','icone'=>'✍️','slug'=>'recits'],
        ['id'=>4,'nom'=>'Œuvres','icone'=>'📖','slug'=>'oeuvres'],
        ['id'=>5,'nom'=>'Ouvrages','icone'=>'📚','slug'=>'ouvrages'],
    ];
    $trending = array_slice($demoBooks, 0, 5);
    $stats    = ['total_livres' => '12K+', 'total_users' => '3.4K', 'total_ventes' => '98K+'];
}

$coverColors = [
    ['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],
    ['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],
    ['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],['#0d3c2a','#1a7a5a'],
    ['#2a0d1a','#6b1a3a'],['#0d1a3c','#1a3a7a'],['#3c0d2a','#7a1a5a'],
];
$emojis = ['🌌','🧠','🌿','⚙️','📜','🎭','🔮','💡','🌊','🏔️','🦋','⚡'];

function getPriceCategory(float $note, float $prix): array {
    if ($prix == 0 || $note <= 2.0) return ['label'=>'GRATUIT','class'=>'badge-free','price_display'=>'Gratuit'];
    if ($note <= 3.5) return ['label'=>'STANDARD','class'=>'badge-std','price_display'=>number_format($prix,0,'.',' ').' FCFA'];
    return ['label'=>'PREMIUM','class'=>'badge-premium','price_display'=>number_format($prix,0,'.',' ').' FCFA'];
}
function formatStat($val): string {
    if (is_int($val)||(is_string($val)&&ctype_digit($val))) return number_format((int)$val,0,',',' ');
    return htmlspecialchars((string)$val);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Digital Library System — Bibliothèque Numérique</title>
<meta name="description" content="La bibliothèque numérique premium. Explorez, lisez, découvrez.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#070b14;--paper:#0d1220;--slate:#131a2e;
  --mist:rgba(200,210,255,0.08);--fog:rgba(200,210,255,0.04);
  --gold:#e8c97d;--ember:#ff6b35;--sage:#4ecca3;--azure:#4a9eff;--plum:#9b59b6;
  --txt-primary:#f0eeea;--txt-secondary:rgba(240,238,234,0.55);--txt-muted:rgba(240,238,234,0.3);
  --glass:rgba(255,255,255,0.03);--glass-border:rgba(255,255,255,0.07);--glass-hover:rgba(255,255,255,0.06);
  --glow-gold:0 0 40px rgba(232,201,125,0.15);
  --ease-spring:cubic-bezier(0.34,1.56,0.64,1);--ease-smooth:cubic-bezier(0.25,0.46,0.45,0.94);
}
html{scroll-behavior:smooth}
body{font-family:'Cabinet Grotesk',system-ui,sans-serif;background:var(--ink);color:var(--txt-primary);overflow-x:hidden;line-height:1.6}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-track{background:var(--ink)}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* CURSOR */
#cursor-dot{position:fixed;width:8px;height:8px;background:var(--gold);border-radius:50%;pointer-events:none;z-index:99999;transform:translate(-50%,-50%);transition:transform 0.1s,width 0.2s,height 0.2s;mix-blend-mode:screen}
#cursor-ring{position:fixed;width:32px;height:32px;border:1px solid rgba(232,201,125,0.4);border-radius:50%;pointer-events:none;z-index:99998;transform:translate(-50%,-50%);transition:all 0.15s var(--ease-smooth)}
@media(pointer:coarse){#cursor-dot,#cursor-ring{display:none}}

/* NOISE + ORBS */
body::before{content:'';position:fixed;inset:0;z-index:-1;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");opacity:0.4;pointer-events:none}
.bg-orb{position:fixed;border-radius:50%;filter:blur(100px);pointer-events:none;z-index:-2;animation:orbDrift 25s ease-in-out infinite}
.orb-a{width:600px;height:600px;background:rgba(232,201,125,0.05);top:-200px;left:-100px}
.orb-b{width:500px;height:500px;background:rgba(74,158,255,0.05);bottom:-150px;right:-100px;animation-delay:-10s}
.orb-c{width:350px;height:350px;background:rgba(78,204,163,0.04);top:50%;left:50%;animation-delay:-18s}
@keyframes orbDrift{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(50px,-40px) scale(1.08)}66%{transform:translate(-40px,50px) scale(0.93)}}

/* INTRO */
#intro{position:fixed;inset:0;z-index:9999;background:var(--ink);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:2rem;transition:opacity 1s var(--ease-smooth),visibility 1s}
#intro.done{opacity:0;visibility:hidden;pointer-events:none}
.intro-brand{font-family:'Clash Display',sans-serif;font-size:2.5rem;font-weight:700;letter-spacing:-2px;background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.intro-lines{font-family:'JetBrains Mono',monospace;font-size:0.72rem;color:var(--sage);display:flex;flex-direction:column;gap:6px;width:300px}
.intro-line{opacity:0;transform:translateX(-10px);transition:all 0.4s var(--ease-smooth)}
.intro-line.show{opacity:1;transform:none}
.boot-bar{width:300px;height:2px;background:var(--mist);border-radius:2px;overflow:hidden}
.boot-fill{height:100%;width:0;background:linear-gradient(90deg,var(--gold),var(--ember));transition:width 0.5s var(--ease-smooth);box-shadow:0 0 12px var(--gold)}

/* HEADER */
#site-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:62px;padding:0 2rem;display:flex;align-items:center;justify-content:space-between;background:rgba(7,11,20,0.7);backdrop-filter:blur(24px) saturate(1.4);border-bottom:1px solid var(--glass-border);transition:background 0.3s}
#site-header.scrolled{background:rgba(7,11,20,0.95)}
.nav-logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--txt-primary);font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600;letter-spacing:-0.5px}
.logo-mark{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));display:flex;align-items:center;justify-content:center;font-size:0.9rem;box-shadow:0 0 20px rgba(232,201,125,0.3)}
.nav-links{display:flex;align-items:center;gap:2px;list-style:none}
.nav-links a{font-size:0.8rem;font-weight:500;color:var(--txt-secondary);text-decoration:none;padding:6px 14px;border-radius:8px;transition:color 0.2s,background 0.2s}
.nav-links a:hover,.nav-links a.active{color:var(--txt-primary);background:var(--mist)}
.nav-links a.active{color:var(--gold)}
.nav-actions{display:flex;align-items:center;gap:0.6rem}
.btn-ghost{font-size:0.78rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;padding:7px 16px;border-radius:8px;border:1px solid var(--glass-border);transition:all 0.2s}
.btn-ghost:hover{border-color:rgba(232,201,125,0.3);color:var(--gold)}
.btn-cta-nav{font-size:0.78rem;font-weight:700;color:var(--ink);text-decoration:none;padding:7px 18px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));transition:opacity 0.2s,transform 0.2s,box-shadow 0.2s;box-shadow:0 4px 16px rgba(232,201,125,0.25)}
.btn-cta-nav:hover{opacity:0.88;transform:translateY(-1px)}
.user-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 5px;background:var(--mist);border:1px solid var(--glass-border);border-radius:100px;text-decoration:none;color:var(--txt-primary);font-size:0.78rem;font-weight:600;transition:all 0.2s}
.user-avatar{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--plum),var(--azure));display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700;color:#fff}
.admin-badge{display:inline-flex;align-items:center;gap:4px;font-size:0.62rem;padding:2px 8px;border-radius:100px;background:rgba(232,201,125,0.1);color:var(--gold);border:1px solid rgba(232,201,125,0.25);font-family:'JetBrains Mono',monospace}
#hamburger{display:none;background:none;border:none;color:var(--txt-primary);font-size:1.3rem;cursor:pointer;padding:4px}
#mobile-nav{display:none;position:fixed;inset:0;top:62px;background:rgba(7,11,20,0.98);backdrop-filter:blur(24px);z-index:998;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem}
#mobile-nav.open{display:flex}
#mobile-nav a{font-family:'Clash Display',sans-serif;font-size:1.6rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;transition:color 0.2s}
#mobile-nav a:hover{color:var(--gold)}
@media(max-width:900px){.nav-links,.nav-actions{display:none}#hamburger{display:block}}

/* HERO */
#hero{min-height:100vh;padding-top:62px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;text-align:center}
.hero-content{max-width:820px;padding:0 1.5rem;position:relative;z-index:1}
.hero-label{display:inline-flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:0.68rem;color:var(--sage);letter-spacing:0.12em;border:1px solid rgba(78,204,163,0.25);background:rgba(78,204,163,0.05);padding:6px 16px;border-radius:100px;margin-bottom:2rem;animation:pulseBorder 3s ease-in-out infinite}
.pulse-dot{width:6px;height:6px;background:var(--sage);border-radius:50%;animation:blink 1.4s step-end infinite}
@keyframes pulseBorder{0%,100%{box-shadow:none}50%{box-shadow:0 0 20px rgba(78,204,163,0.12)}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0.1}}
.hero-h1{font-family:'Clash Display',sans-serif;font-size:clamp(2.5rem,7vw,5.5rem);font-weight:700;letter-spacing:-3px;line-height:0.95;margin-bottom:1.5rem}
.hero-h1 .line-1{display:block;color:var(--txt-primary);margin-bottom:0.1em}
.hero-h1 .line-2{display:block;background:linear-gradient(135deg,var(--gold) 20%,var(--ember) 60%,var(--gold) 100%);background-size:200% auto;-webkit-background-clip:text;-webkit-text-fill-color:transparent;animation:shimmer 4s linear infinite}
@keyframes shimmer{to{background-position:200% center}}
.hero-sub{font-size:1.05rem;color:var(--txt-secondary);max-width:520px;margin:0 auto 2.5rem;line-height:1.8}
.hero-btns{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap}
.btn-hero-primary{display:inline-flex;align-items:center;gap:8px;font-family:'Clash Display',sans-serif;font-size:0.95rem;font-weight:600;padding:14px 28px;border-radius:12px;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);text-decoration:none;box-shadow:0 8px 32px rgba(232,201,125,0.25);transition:transform 0.2s var(--ease-spring),box-shadow 0.2s}
.btn-hero-primary:hover{transform:translateY(-3px);box-shadow:0 16px 48px rgba(232,201,125,0.35)}
.btn-hero-secondary{display:inline-flex;align-items:center;gap:8px;font-family:'Clash Display',sans-serif;font-size:0.95rem;font-weight:600;padding:14px 28px;border-radius:12px;border:1px solid var(--glass-border);background:var(--glass);backdrop-filter:blur(8px);color:var(--txt-primary);text-decoration:none;transition:all 0.2s}
.btn-hero-secondary:hover{border-color:rgba(232,201,125,0.3);transform:translateY(-3px)}
.hero-metrics{display:flex;gap:3rem;justify-content:center;margin-top:4.5rem;flex-wrap:wrap}
.metric{text-align:center}
.metric-val{font-family:'Clash Display',sans-serif;font-size:2.2rem;font-weight:700;background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-1px}
.metric-label{font-size:0.72rem;color:var(--txt-muted);margin-top:4px;letter-spacing:0.08em;text-transform:uppercase}
.metric-sep{width:1px;background:var(--glass-border);align-self:stretch}

/* MARQUEE */
.marquee-strip{overflow:hidden;border-top:1px solid var(--glass-border);border-bottom:1px solid var(--glass-border);padding:14px 0;background:var(--fog)}
.marquee-track{display:flex;gap:3rem;animation:marqueeScroll 30s linear infinite;white-space:nowrap}
.marquee-track span{font-family:'JetBrains Mono',monospace;font-size:0.72rem;color:var(--txt-muted);letter-spacing:0.08em;display:flex;align-items:center;gap:8px}
.marquee-track span::before{content:'◆';color:var(--gold);font-size:0.5rem}
@keyframes marqueeScroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}

/* SECTIONS */
.section{padding:6rem 2rem}
.container{max-width:1200px;margin:0 auto}
.section-eyebrow{font-family:'JetBrains Mono',monospace;font-size:0.68rem;letter-spacing:0.15em;color:var(--gold);text-transform:uppercase;margin-bottom:0.8rem;display:flex;align-items:center;gap:8px}
.section-eyebrow::before{content:'';width:20px;height:1px;background:var(--gold)}
.section-title{font-family:'Clash Display',sans-serif;font-size:clamp(1.8rem,4vw,3rem);font-weight:700;letter-spacing:-1.5px;line-height:1.05;margin-bottom:1rem}
.section-sub{font-size:0.95rem;color:var(--txt-secondary);max-width:520px;line-height:1.8}

/* CATEGORY PILLS */
.cat-pills{display:flex;gap:0.6rem;flex-wrap:wrap;margin:2rem 0 3rem}
.cat-pill{display:inline-flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:600;padding:8px 16px;border-radius:100px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);cursor:pointer;transition:all 0.2s var(--ease-spring);font-family:'Cabinet Grotesk',sans-serif}
.cat-pill:hover,.cat-pill.active{border-color:rgba(232,201,125,0.4);color:var(--gold);background:rgba(232,201,125,0.06);transform:translateY(-2px)}

/* BOOK GRID */
.books-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.25rem}
.book-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:16px;overflow:hidden;transition:transform 0.3s var(--ease-spring),border-color 0.3s,box-shadow 0.3s;cursor:pointer;opacity:0;transform:translateY(24px)}
.book-card.revealed{animation:cardIn 0.5s var(--ease-spring) forwards}
@keyframes cardIn{to{opacity:1;transform:translateY(0)}}
.book-card:hover{transform:translateY(-8px) scale(1.01);border-color:rgba(232,201,125,0.25);box-shadow:0 24px 64px rgba(0,0,0,0.5),0 0 30px rgba(232,201,125,0.08)}
.book-cover{height:175px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center}
.cover-emoji{font-size:3.5rem;position:relative;z-index:1;filter:drop-shadow(0 4px 12px rgba(0,0,0,0.5))}
.cover-gradient{position:absolute;inset:0}
.book-cover::after{content:'';position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(7,11,20,0.85))}
.price-badge{position:absolute;top:10px;right:10px;z-index:3;font-family:'JetBrains Mono',monospace;font-size:0.6rem;padding:4px 10px;border-radius:100px;letter-spacing:0.06em}
.badge-free{background:rgba(78,204,163,0.15);color:var(--sage);border:1px solid rgba(78,204,163,0.3)}
.badge-std{background:rgba(74,158,255,0.15);color:var(--azure);border:1px solid rgba(74,158,255,0.3)}
.badge-premium{background:rgba(232,201,125,0.15);color:var(--gold);border:1px solid rgba(232,201,125,0.3)}
.book-body{padding:1.1rem}
.book-genre{font-family:'JetBrains Mono',monospace;font-size:0.6rem;color:var(--gold);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:5px}
.book-title{font-family:'Clash Display',sans-serif;font-size:0.92rem;font-weight:600;letter-spacing:-0.3px;line-height:1.25;margin-bottom:3px}
.book-author{font-size:0.75rem;color:var(--txt-secondary);margin-bottom:0.8rem}
.book-stars{display:flex;align-items:center;gap:5px;margin-bottom:1rem}
.stars-fill{color:var(--gold);font-size:0.7rem;letter-spacing:-2px}
.stars-val{font-size:0.72rem;color:var(--txt-muted);font-weight:500}
.book-price{font-family:'Clash Display',sans-serif;font-size:0.88rem;font-weight:700;color:var(--gold);margin-bottom:0.9rem}
.book-price.free{color:var(--sage)}
.book-btns{display:flex;gap:7px}
.btn-read-book{flex:1;padding:8px 0;border-radius:8px;border:1px solid rgba(232,201,125,0.25);background:rgba(232,201,125,0.04);color:var(--gold);font-size:0.72rem;font-weight:600;cursor:pointer;transition:all 0.2s;text-align:center;font-family:'Cabinet Grotesk',sans-serif}
.btn-read-book:hover{background:rgba(232,201,125,0.1);box-shadow:0 0 20px rgba(232,201,125,0.1)}
.btn-info-book{width:34px;height:34px;border-radius:8px;flex-shrink:0;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-muted);font-size:0.85rem;cursor:pointer;transition:all 0.2s;display:flex;align-items:center;justify-content:center}
.btn-info-book:hover{border-color:rgba(74,158,255,0.3);color:var(--azure)}

/* FEATURED / TRENDING */
.featured-layout{display:grid;grid-template-columns:1fr 340px;gap:2rem;align-items:start}
@media(max-width:960px){.featured-layout{grid-template-columns:1fr}}
.trending-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:16px;padding:1.1rem 1.2rem;display:flex;align-items:center;gap:1rem;margin-bottom:0.75rem;cursor:pointer;transition:all 0.2s var(--ease-spring);color:inherit}
.trending-card:hover{border-color:rgba(232,201,125,0.2);transform:translateX(4px)}
.trend-rank{font-family:'Clash Display',sans-serif;font-size:2rem;font-weight:700;color:var(--txt-muted);width:36px;flex-shrink:0;text-align:center;line-height:1}
.trending-card:hover .trend-rank{color:var(--gold)}
.trend-info{flex:1;min-width:0}
.trend-title{font-family:'Clash Display',sans-serif;font-size:0.88rem;font-weight:600;letter-spacing:-0.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.trend-author{font-size:0.72rem;color:var(--txt-muted);margin-top:2px}
.trend-rating{font-family:'JetBrains Mono',monospace;font-size:0.72rem;color:var(--gold);flex-shrink:0}

/* AI TERMINAL */
.ai-terminal{background:var(--slate);border:1px solid var(--glass-border);border-radius:20px;overflow:hidden;box-shadow:0 40px 100px rgba(0,0,0,0.4)}
.ai-topbar{padding:14px 18px;background:rgba(0,0,0,0.3);border-bottom:1px solid var(--glass-border);display:flex;align-items:center;gap:8px}
.dot{width:10px;height:10px;border-radius:50%}
.dot-r{background:#ff5f57}.dot-y{background:#ffbd2e}.dot-g{background:#28ca41;animation:blink 2s infinite}
.ai-titlebar{font-family:'JetBrains Mono',monospace;font-size:0.7rem;color:var(--txt-muted);margin-left:10px}
.ai-body{padding:1.8rem}
.ai-prompt{font-family:'JetBrains Mono',monospace;font-size:0.7rem;color:var(--sage);margin-bottom:1rem}
#ai-output{font-family:'Cabinet Grotesk',sans-serif;font-size:1.05rem;font-weight:500;color:var(--txt-primary);min-height:2.5rem;line-height:1.65}
.type-cursor{display:inline-block;width:2px;height:1em;background:var(--gold);vertical-align:middle;margin-left:2px;animation:blink 0.75s step-end infinite}
.ai-footer{padding:1.2rem 1.8rem;border-top:1px solid var(--glass-border);display:flex;gap:2rem;background:rgba(0,0,0,0.2)}
.dt-block .dt-lbl{font-family:'JetBrains Mono',monospace;font-size:0.6rem;color:var(--txt-muted);letter-spacing:0.1em;text-transform:uppercase}
.dt-block .dt-val{font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;color:var(--gold);margin-top:3px}
.ai-chat-row{display:flex;gap:10px;margin-top:1.2rem;padding-top:1.2rem;border-top:1px solid var(--glass-border)}
.ai-input{flex:1;background:var(--fog);border:1px solid var(--glass-border);border-radius:10px;padding:10px 14px;font-family:'Cabinet Grotesk',sans-serif;font-size:0.82rem;color:var(--txt-primary);outline:none;transition:border-color 0.2s}
.ai-input:focus{border-color:rgba(232,201,125,0.3)}
.ai-input::placeholder{color:var(--txt-muted)}
.ai-send{padding:10px 18px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-size:0.82rem;font-weight:700;cursor:pointer;transition:opacity 0.2s,transform 0.2s;font-family:'Cabinet Grotesk',sans-serif}
.ai-send:hover{opacity:0.88;transform:translateY(-1px)}

/* FEATURES */
.features-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.25rem;margin-top:3rem}
.feature-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:20px;padding:1.8rem;position:relative;overflow:hidden;transition:transform 0.3s var(--ease-spring),border-color 0.3s}
.feature-card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--gold),transparent);opacity:0;transition:opacity 0.3s}
.feature-card:hover{transform:translateY(-6px);border-color:rgba(232,201,125,0.15)}
.feature-card:hover::before{opacity:1}
.feature-icon{width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:1.1rem}
.fi-gold{background:rgba(232,201,125,0.1)}.fi-sage{background:rgba(78,204,163,0.1)}.fi-azure{background:rgba(74,158,255,0.1)}.fi-ember{background:rgba(255,107,53,0.1)}
.feature-name{font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600;letter-spacing:-0.3px;margin-bottom:0.5rem}
.feature-desc{font-size:0.82rem;color:var(--txt-secondary);line-height:1.7}

/* CTA */
#cta-section{text-align:center;padding:8rem 2rem;position:relative;overflow:hidden}
#cta-section::before{content:'';position:absolute;width:700px;height:700px;background:radial-gradient(circle,rgba(232,201,125,0.06) 0%,transparent 70%);top:50%;left:50%;transform:translate(-50%,-50%);pointer-events:none}
.cta-title{font-family:'Clash Display',sans-serif;font-size:clamp(2rem,5vw,4rem);font-weight:700;letter-spacing:-2px;line-height:1.05;margin-bottom:1rem}
.cta-sub{font-size:1rem;color:var(--txt-secondary);margin-bottom:2.5rem}
.btn-cta-main{display:inline-flex;align-items:center;gap:10px;font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:700;padding:18px 40px;border-radius:14px;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);text-decoration:none;box-shadow:0 0 60px rgba(232,201,125,0.2),0 16px 48px rgba(0,0,0,0.4);animation:ctaPulse 3s ease-in-out infinite;transition:transform 0.2s var(--ease-spring)}
.btn-cta-main:hover{transform:translateY(-4px) scale(1.02)}
@keyframes ctaPulse{0%,100%{box-shadow:0 0 60px rgba(232,201,125,0.2),0 16px 48px rgba(0,0,0,0.4)}50%{box-shadow:0 0 80px rgba(232,201,125,0.35),0 16px 48px rgba(0,0,0,0.4)}}

/* FOOTER */
footer{border-top:1px solid var(--glass-border);padding:3rem 2rem 2rem;background:rgba(0,0,0,0.25)}
.footer-inner{max-width:1200px;margin:0 auto}
.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:3rem;margin-bottom:3rem}
@media(max-width:768px){.footer-grid{grid-template-columns:1fr 1fr;gap:2rem}}
@media(max-width:480px){.footer-grid{grid-template-columns:1fr}}
.footer-brand-desc{font-size:0.82rem;color:var(--txt-muted);line-height:1.8;margin-top:0.8rem;max-width:260px}
.footer-col h5{font-family:'Clash Display',sans-serif;font-size:0.78rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:var(--txt-secondary);margin-bottom:1rem}
.footer-col ul{list-style:none}
.footer-col ul li{margin-bottom:0.6rem}
.footer-col ul li a{font-size:0.8rem;color:var(--txt-muted);text-decoration:none;transition:color 0.2s}
.footer-col ul li a:hover{color:var(--gold)}
.footer-bottom{padding-top:1.5rem;border-top:1px solid var(--glass-border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.footer-copy{font-size:0.73rem;color:var(--txt-muted)}
.tech-pills{display:flex;gap:0.6rem}
.tech-pill{font-family:'JetBrains Mono',monospace;font-size:0.6rem;padding:4px 10px;border-radius:100px;border:1px solid var(--glass-border);color:var(--txt-muted)}
.section-divider{max-width:1200px;margin:0 auto;height:1px;background:linear-gradient(90deg,transparent,var(--glass-border),transparent)}

/* TOAST */
#toast{position:fixed;bottom:2rem;right:2rem;z-index:9800;background:rgba(13,18,32,0.97);border:1px solid rgba(232,201,125,0.2);border-radius:14px;padding:1rem 1.3rem;display:flex;align-items:center;gap:12px;font-size:0.8rem;backdrop-filter:blur(20px);box-shadow:0 0 40px rgba(232,201,125,0.1),0 12px 32px rgba(0,0,0,0.5);transform:translateY(100px) scale(0.96);opacity:0;transition:all 0.4s var(--ease-spring);max-width:320px;min-width:240px;pointer-events:none}
#toast.show{transform:translateY(0) scale(1);opacity:1;pointer-events:auto}
#toast.t-success{border-color:rgba(78,204,163,0.3)}
#toast.t-error{border-color:rgba(255,95,87,0.3)}
#toast.t-warn{border-color:rgba(255,189,46,0.3)}
.toast-icon{font-size:1.1rem;flex-shrink:0}
.toast-text{display:flex;flex-direction:column;gap:2px}
.toast-ttl{font-family:'Clash Display',sans-serif;font-weight:600;font-size:0.82rem}
.toast-msg{font-size:0.72rem;color:var(--txt-muted)}

/* ═══════════════════════════════════════
   AUTH GATE MODAL
═══════════════════════════════════════ */
#auth-gate{position:fixed;inset:0;z-index:9500;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity 0.3s,visibility 0.3s}
#auth-gate.open{opacity:1;visibility:visible}
.gate-backdrop{position:absolute;inset:0;background:rgba(7,11,20,0.88);backdrop-filter:blur(12px)}
.gate-box{position:relative;z-index:1;max-width:420px;width:100%;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:24px;padding:2.5rem;text-align:center;overflow:hidden;box-shadow:0 60px 120px rgba(0,0,0,0.6);transform:translateY(20px) scale(0.97);transition:transform 0.4s var(--ease-spring)}
#auth-gate.open .gate-box{transform:none}
.gate-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--ember))}
.gate-icon{font-size:2.5rem;margin-bottom:1rem}
.gate-eyebrow{font-family:'JetBrains Mono',monospace;font-size:0.65rem;letter-spacing:0.12em;color:var(--gold);text-transform:uppercase;margin-bottom:0.6rem}
.gate-title{font-family:'Clash Display',sans-serif;font-size:1.5rem;font-weight:700;letter-spacing:-0.5px;margin-bottom:0.7rem}
.gate-desc{font-size:0.82rem;color:var(--txt-secondary);line-height:1.7;margin-bottom:1.8rem}
.gate-btns{display:flex;gap:0.7rem}
.btn-gate-solid{flex:1;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:0.88rem;font-weight:700;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:opacity 0.2s,transform 0.2s}
.btn-gate-solid:hover{opacity:0.88;transform:translateY(-2px)}
.btn-gate-outline{flex:1;padding:12px;border-radius:10px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-family:'Clash Display',sans-serif;font-size:0.88rem;font-weight:600;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:all 0.2s}
.btn-gate-outline:hover{border-color:rgba(232,201,125,0.3);color:var(--gold)}
.gate-countdown{margin-top:1.2rem;font-family:'JetBrains Mono',monospace;font-size:0.65rem;color:var(--txt-muted)}
.gate-progress{width:100%;height:2px;background:var(--glass-border);border-radius:2px;overflow:hidden;margin-top:0.6rem}
.gate-progress-fill{height:100%;width:100%;background:var(--gold);transform-origin:left;transition:transform 5s linear}
.gate-close-btn{position:absolute;top:1rem;right:1rem;width:28px;height:28px;border-radius:7px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.8rem;transition:all 0.2s}
.gate-close-btn:hover{color:#ff5f57;border-color:rgba(255,95,87,0.3)}

/* ═══════════════════════════════════════
   PAYMENT MODAL
═══════════════════════════════════════ */
#pay-modal{position:fixed;inset:0;z-index:9700;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity 0.3s,visibility 0.3s}
#pay-modal.open{opacity:1;visibility:visible}
.pay-backdrop{position:absolute;inset:0;background:rgba(7,11,20,0.88);backdrop-filter:blur(16px)}
.pay-box{position:relative;z-index:1;width:100%;max-width:500px;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:24px;overflow:hidden;overflow-y:auto;max-height:90vh;box-shadow:0 0 100px rgba(232,201,125,0.08),0 50px 100px rgba(0,0,0,0.6);transform:translateY(20px) scale(0.97);transition:transform 0.4s var(--ease-spring)}
#pay-modal.open .pay-box{transform:none}
.pay-bar{height:2px;width:100%;background:linear-gradient(90deg,var(--gold),var(--ember),var(--sage));background-size:200%;animation:shimmer 3s linear infinite}
.pay-header{padding:1.8rem 1.8rem 1.2rem;border-bottom:1px solid var(--glass-border)}
.pay-book-row{display:flex;align-items:center;gap:14px;margin-bottom:1.2rem}
.pay-book-thumb{width:54px;height:54px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.8rem;flex-shrink:0;background:var(--mist);border:1px solid var(--glass-border)}
.pay-book-title{font-family:'Clash Display',sans-serif;font-size:0.95rem;font-weight:600;letter-spacing:-0.3px}
.pay-book-author{font-size:0.75rem;color:var(--txt-secondary);margin-top:2px}
.pay-amount-row{display:flex;align-items:baseline;gap:6px}
.pay-amount-label{font-size:0.75rem;color:var(--txt-muted)}
.pay-amount-val{font-family:'Clash Display',sans-serif;font-size:1.8rem;font-weight:700;color:var(--gold);letter-spacing:-1px}
.pay-amount-curr{font-size:0.85rem;color:var(--txt-muted)}
.pay-body{padding:1.5rem 1.8rem}
.pay-steps{display:flex;align-items:center;gap:6px;margin-bottom:1.8rem}
.step-dot{width:8px;height:8px;border-radius:50%;background:var(--glass-border);transition:background 0.3s,transform 0.3s}
.step-dot.active{background:var(--gold);transform:scale(1.3)}
.step-dot.done{background:var(--sage)}
.step-line{flex:1;height:1px;background:var(--glass-border);transition:background 0.3s}
.step-line.done{background:var(--sage)}
.pay-step-label{font-family:'JetBrains Mono',monospace;font-size:0.65rem;color:var(--txt-muted);margin-left:auto}
.method-title{font-size:0.78rem;color:var(--txt-secondary);margin-bottom:1rem;font-weight:500}
.methods-grid{display:grid;grid-template-columns:1fr 1fr;gap:0.7rem;margin-bottom:1.5rem}
.method-btn{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);cursor:pointer;transition:all 0.2s var(--ease-spring);text-align:left;font-family:'Cabinet Grotesk',sans-serif}
.method-btn:hover{border-color:rgba(232,201,125,0.25);color:var(--txt-primary)}
.method-btn.selected{border-color:var(--gold);background:rgba(232,201,125,0.06);color:var(--txt-primary)}
.method-icon{font-size:1.5rem;flex-shrink:0}
.method-name{font-size:0.78rem;font-weight:600}
.method-sub{font-size:0.65rem;color:var(--txt-muted);margin-top:1px}
.field-group{margin-bottom:1rem}
.field-label{font-size:0.72rem;color:var(--txt-secondary);margin-bottom:5px;display:block;font-weight:500}
.field-input{width:100%;padding:11px 14px;border-radius:10px;background:var(--fog);border:1px solid var(--glass-border);color:var(--txt-primary);font-family:'Cabinet Grotesk',sans-serif;font-size:0.85rem;outline:none;transition:border-color 0.2s}
.field-input:focus{border-color:rgba(232,201,125,0.4)}
.field-input::placeholder{color:var(--txt-muted)}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:0.7rem}
.card-number-field{font-family:'JetBrains Mono',monospace;letter-spacing:2px}
#pay-processing{display:none;text-align:center;padding:2rem 0}
.process-spinner{width:60px;height:60px;border-radius:50%;border:3px solid var(--glass-border);border-top-color:var(--gold);margin:0 auto 1.2rem;animation:spin 0.8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.process-steps{text-align:left;margin-top:1.5rem}
.proc-step{display:flex;align-items:center;gap:10px;font-size:0.78rem;color:var(--txt-muted);padding:6px 0;transition:color 0.3s}
.proc-step.done{color:var(--sage)}.proc-step.active{color:var(--gold)}
.proc-icon{width:18px;height:18px;border-radius:50%;border:1px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:0.55rem;flex-shrink:0}
.proc-step.done .proc-icon{background:var(--sage);border-color:var(--sage);color:#fff}
#pay-success{display:none;text-align:center;padding:2rem 0}
.success-ring{width:80px;height:80px;border-radius:50%;background:rgba(78,204,163,0.1);border:2px solid var(--sage);display:flex;align-items:center;justify-content:center;font-size:2.5rem;margin:0 auto 1.2rem;animation:successPop 0.5s var(--ease-spring);box-shadow:0 0 40px rgba(78,204,163,0.2)}
@keyframes successPop{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
.success-title{font-family:'Clash Display',sans-serif;font-size:1.4rem;font-weight:700;color:var(--sage);letter-spacing:-0.5px;margin-bottom:0.4rem}
.success-sub{font-size:0.82rem;color:var(--txt-muted)}
.success-ref{font-family:'JetBrains Mono',monospace;font-size:0.68rem;color:var(--txt-muted);margin:1rem 0;padding:8px 16px;background:var(--fog);border-radius:8px;border:1px solid var(--glass-border)}
.admin-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:10px;background:rgba(232,201,125,0.05);border:1px solid rgba(232,201,125,0.2);font-size:0.78rem;color:var(--txt-secondary);margin-bottom:1.2rem}
.admin-notice i{color:var(--gold);flex-shrink:0;margin-top:2px}
.btn-pay{width:100%;padding:14px;border-radius:12px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:0.95rem;font-weight:700;cursor:pointer;transition:opacity 0.2s,transform 0.2s,box-shadow 0.2s;box-shadow:0 8px 24px rgba(232,201,125,0.2);letter-spacing:-0.3px;margin-top:1rem}
.btn-pay:hover:not(:disabled){opacity:0.88;transform:translateY(-2px)}
.btn-pay:disabled{opacity:0.45;cursor:not-allowed;transform:none}
.pay-close{position:absolute;top:1rem;right:1rem;width:30px;height:30px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s;font-size:0.85rem;z-index:10;line-height:1}
.pay-close:hover{border-color:rgba(255,95,87,0.3);color:#ff5f57}
.pay-security{display:flex;align-items:center;justify-content:center;gap:1.5rem;padding:1rem 1.8rem;border-top:1px solid var(--glass-border);background:rgba(0,0,0,0.2)}
.sec-badge{display:flex;align-items:center;gap:5px;font-size:0.65rem;color:var(--txt-muted)}
.sec-badge i{color:var(--sage)}

/* ═══════════════════════════════════════
   READER MODAL — VERSION CORRIGÉE
═══════════════════════════════════════ */
#reader-modal{position:fixed;inset:0;z-index:9600;opacity:0;visibility:hidden;transition:opacity 0.4s,visibility 0.4s;background:#0e0d0b;display:flex;flex-direction:column}
#reader-modal.open{opacity:1;visibility:visible}
.reader-header{height:54px;display:flex;align-items:center;justify-content:space-between;padding:0 1.8rem;border-bottom:1px solid rgba(255,255,255,0.06);background:rgba(0,0,0,0.4);backdrop-filter:blur(10px);flex-shrink:0}
.reader-title-wrap{display:flex;align-items:center;gap:10px;min-width:0;flex:1}
.reader-title{font-family:'Clash Display',sans-serif;font-size:0.9rem;font-weight:600;letter-spacing:-0.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.reader-controls{display:flex;align-items:center;gap:0.6rem;flex-shrink:0}
.reader-btn{width:32px;height:32px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;transition:all 0.2s;display:flex;align-items:center;justify-content:center;font-size:0.85rem}
.reader-btn:hover{border-color:rgba(232,201,125,0.3);color:var(--gold)}
.reader-close-btn{border-color:rgba(255,95,87,0.3);color:#ff5f57}
.reader-close-btn:hover{background:rgba(255,95,87,0.1)}
.reader-body{flex:1;overflow-y:auto;position:relative}
.reader-inner{max-width:680px;margin:0 auto;padding:3rem 2rem 7rem}
.reader-content{font-family:'Georgia',serif;font-size:1.05rem;line-height:1.95;color:#e8e4da;transition:font-size 0.2s}
.reader-content h2{font-family:'Clash Display',sans-serif;font-size:1.35rem;font-weight:700;letter-spacing:-0.5px;margin:2.5rem 0 1.2rem;color:#f0eeea;border-bottom:1px solid rgba(255,255,255,0.06);padding-bottom:0.6rem}
.reader-content p{margin-bottom:1.3rem;text-indent:1.5em}
.reader-content p:first-of-type{text-indent:0}
/* Page animations */
@keyframes pageSlideIn{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}
@keyframes pageSlideOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(-40px)}}
@keyframes pageSlideInBack{from{opacity:0;transform:translateX(-40px)}to{opacity:1;transform:translateX(0)}}
.page-anim-next{animation:pageSlideIn 0.3s var(--ease-smooth) forwards}
.page-anim-prev{animation:pageSlideInBack 0.3s var(--ease-smooth) forwards}
/* Bottom nav */
.reader-nav{position:sticky;bottom:0;left:0;right:0;display:flex;align-items:center;justify-content:center;gap:1rem;background:rgba(14,13,11,0.95);border-top:1px solid rgba(255,255,255,0.06);padding:12px 2rem;backdrop-filter:blur(20px);flex-shrink:0}
.reader-nav-btn{background:none;border:1px solid var(--glass-border);border-radius:8px;color:var(--txt-muted);cursor:pointer;font-size:1rem;transition:all 0.2s;padding:7px 14px;display:flex;align-items:center;gap:4px;font-size:0.78rem;font-family:'Cabinet Grotesk',sans-serif}
.reader-nav-btn:hover:not(:disabled){border-color:rgba(232,201,125,0.3);color:var(--gold)}
.reader-nav-btn:disabled{opacity:0.3;cursor:not-allowed}
.reader-page-info{font-family:'JetBrains Mono',monospace;font-size:0.7rem;color:var(--txt-muted);min-width:80px;text-align:center}
.reader-progress-bar{height:3px;background:rgba(255,255,255,0.06);flex-shrink:0}
.reader-progress-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--ember));transition:width 0.5s var(--ease-smooth)}
/* Light theme for reader */
#reader-modal.reader-light{background:#f5f0e8}
#reader-modal.reader-light .reader-content{color:#2c2a24}
#reader-modal.reader-light .reader-content h2{color:#1a1814;border-color:rgba(0,0,0,0.08)}
#reader-modal.reader-light .reader-header{background:rgba(245,240,232,0.9);border-color:rgba(0,0,0,0.08)}
#reader-modal.reader-light .reader-nav{background:rgba(245,240,232,0.95);border-color:rgba(0,0,0,0.08)}

/* REVEAL */
.reveal{opacity:0;transform:translateY(28px);transition:opacity 0.65s var(--ease-smooth),transform 0.65s var(--ease-smooth)}
.reveal.visible{opacity:1;transform:none}
.reveal-delay-1{transition-delay:0.1s}.reveal-delay-2{transition-delay:0.2s}.reveal-delay-3{transition-delay:0.3s}
</style>
</head>
<body>

<div id="cursor-dot"></div>
<div id="cursor-ring"></div>
<div class="bg-orb orb-a"></div>
<div class="bg-orb orb-b"></div>
<div class="bg-orb orb-c"></div>

<!-- ═══════════ INTRO ═══════════ -->
<div id="intro" role="status" aria-label="Chargement">
  <div class="intro-brand">📚 DLS</div>
  <div class="intro-lines">
    <div class="intro-line" id="il1">[ INIT ]  Bibliothèque numérique...</div>
    <div class="intro-line" id="il2">[ AUTH ]  Vérification session...</div>
    <div class="intro-line" id="il3">[ DATA ]  Chargement catalogue...</div>
    <div class="intro-line" id="il4" style="color:var(--sage)">[ OK   ]  Système prêt ✓</div>
  </div>
  <div class="boot-bar"><div class="boot-fill" id="boot-fill"></div></div>
</div>

<!-- ═══════════ HEADER ═══════════ -->
<header id="site-header">
  <a href="index.php" class="nav-logo">
    <div class="logo-mark">📚</div>
    Digital <span style="color:var(--gold)">Library</span>
  </a>
  <nav aria-label="Navigation principale">
    <ul class="nav-links">
      <li><a href="index.php" class="active">Accueil</a></li>
      <li><a href="explorer.php">Explorer</a></li>
      <li><a href="categories.php">Catégories</a></li>
      <li><a href="tendances.php">Tendances</a></li>
      <li><a href="recommandations-ia.php">Recommandations IA</a></li>
    </ul>
  </nav>
  <div class="nav-actions">
    <?php if ($isLoggedIn): ?>
      <?php if ($isAdmin): ?>
        <span class="admin-badge"><i class="bi bi-shield-fill"></i> Admin</span>
      <?php endif; ?>
      <a href="dashboard.php" class="user-chip">
        <div class="user-avatar"><?= strtoupper(substr($username,0,2)) ?></div>
        <?= $username ?>
      </a>
      <a href="logout.php" class="btn-ghost">Déconnexion</a>
    <?php else: ?>
      <a href="login.php" class="btn-ghost">Connexion</a>
      <a href="register.php" class="btn-cta-nav">Commencer →</a>
    <?php endif; ?>
  </div>
  <button id="hamburger" aria-label="Menu" aria-expanded="false"><i class="bi bi-list"></i></button>
</header>

<nav id="mobile-nav" aria-label="Navigation mobile">
  <a href="index.php">Accueil</a>
  <a href="explorer.php">Explorer</a>
  <a href="categories.php">Catégories</a>
  <a href="tendances.php">Tendances</a>
  <a href="recommandations-ia.php">Recommandations IA</a>
  <?php if ($isLoggedIn): ?>
    <a href="logout.php" style="color:var(--ember)">Déconnexion</a>
  <?php else: ?>
    <a href="login.php">Connexion</a>
    <a href="register.php" style="color:var(--gold)">S'inscrire</a>
  <?php endif; ?>
</nav>

<!-- ═══════════ HERO ═══════════ -->
<section id="hero" aria-label="Accueil">
  <div class="hero-content">
    <div class="hero-label">
      <span class="pulse-dot"></span>
      Bibliothèque Numérique Intelligente — Édition Premium
    </div>
    <h1 class="hero-h1">
      <span class="line-1">Bienvenue dans</span>
      <span class="line-2">votre bibliothèque</span>
    </h1>
    <p class="hero-sub">
      Explorez <?= formatStat($stats['total_livres']) ?>+ ouvrages, découvrez de nouvelles perspectives et plongez dans une expérience de lecture immersive conçue pour l'ère numérique.
    </p>
    <div class="hero-btns">
      <a href="explorer.php" class="btn-hero-primary"><i class="bi bi-compass"></i> Explorer le catalogue</a>
      <?php if (!$isLoggedIn): ?>
        <a href="register.php" class="btn-hero-secondary"><i class="bi bi-person-plus"></i> Créer un compte</a>
      <?php else: ?>
        <a href="tendances.php" class="btn-hero-secondary"><i class="bi bi-graph-up"></i> Voir les tendances</a>
      <?php endif; ?>
    </div>
    <div class="hero-metrics">
      <div class="metric">
        <div class="metric-val"><?= formatStat($stats['total_livres']) ?>+</div>
        <div class="metric-label">Livres disponibles</div>
      </div>
      <div class="metric-sep"></div>
      <div class="metric">
        <div class="metric-val"><?= formatStat($stats['total_users']) ?>+</div>
        <div class="metric-label">Lecteurs actifs</div>
      </div>
      <div class="metric-sep"></div>
      <div class="metric">
        <div class="metric-val">98%</div>
        <div class="metric-label">Satisfaction</div>
      </div>
    </div>
  </div>
</section>

<!-- MARQUEE -->
<div class="marquee-strip" aria-hidden="true">
  <div class="marquee-track">
    <?php foreach (array_merge($cats,$cats,$cats,$cats) as $c): ?>
      <span><?= htmlspecialchars($c['icone']??'') ?> <?= htmlspecialchars($c['nom']??'') ?></span>
    <?php endforeach; ?>
  </div>
</div>

<!-- ═══════════ BOOKS ═══════════ -->
<section class="section" id="livres-section">
  <div class="container">
    <div class="featured-layout">
      <!-- GRILLE LIVRES -->
      <div>
        <div class="reveal">
          <div class="section-eyebrow">Collection</div>
          <h2 class="section-title">Livres à la une</h2>
          <p class="section-sub">Une sélection premium curatée par nos experts.</p>
        </div>
        <div class="cat-pills reveal">
          <button class="cat-pill active" data-cat="all">Tous</button>
          <?php foreach ($cats as $c): ?>
            <button class="cat-pill" data-cat="<?= (int)($c['id']??0) ?>">
              <?= htmlspecialchars($c['icone']??'') ?> <?= htmlspecialchars($c['nom']??'') ?>
            </button>
          <?php endforeach; ?>
        </div>
        <div class="books-grid" id="books-grid">
          <?php foreach ($books as $i => $book):
            $pc     = getPriceCategory((float)($book['note_moyenne']??0),(float)($book['prix']??0));
            $colors = $coverColors[$i % count($coverColors)];
            $emoji  = $emojis[$i % count($emojis)];
            $stars  = '';
            $rating = round((float)($book['note_moyenne']??0));
            for($s=1;$s<=5;$s++) $stars.=$s<=$rating?'★':'☆';
            // Encoder le contenu en base64 pour éviter tout problème d'attributs HTML
            $extrait = base64_encode(substr((string)($book['contenu_extrait']??''),0,8000));
          ?>
          <div class="book-card"
               data-id="<?= (int)($book['id']??0) ?>"
               data-titre="<?= htmlspecialchars((string)($book['titre']??''),ENT_QUOTES,'UTF-8') ?>"
               data-auteur="<?= htmlspecialchars((string)($book['auteur']??''),ENT_QUOTES,'UTF-8') ?>"
               data-prix="<?= (float)($book['prix']??0) ?>"
               data-note="<?= (float)($book['note_moyenne']??0) ?>"
               data-emoji="<?= $emoji ?>"
               data-extrait="<?= $extrait ?>"
               data-pages="<?= (int)($book['pages']??200) ?>">
            <div class="book-cover">
              <div class="cover-gradient" style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)"></div>
              <div class="cover-emoji"><?= $emoji ?></div>
              <span class="price-badge <?= $pc['class'] ?>"><?= $pc['label'] ?></span>
            </div>
            <div class="book-body">
              <div class="book-genre"><?= htmlspecialchars((string)($book['genre_icone']??'')) ?> <?= htmlspecialchars((string)($book['genre']??'')) ?></div>
              <div class="book-title"><?= htmlspecialchars((string)($book['titre']??'')) ?></div>
              <div class="book-author">par <?= htmlspecialchars((string)($book['auteur']??'')) ?></div>
              <div class="book-stars">
                <span class="stars-fill"><?= $stars ?></span>
                <span class="stars-val"><?= number_format((float)($book['note_moyenne']??0),1) ?></span>
              </div>
              <div class="book-price <?= ($book['prix']??0)==0?'free':'' ?>"><?= $pc['price_display'] ?></div>
              <div class="book-btns">
                <button class="btn-read-book" onclick="handleRead(this)"><i class="bi bi-book-open"></i> Lire</button>
                <button class="btn-info-book" onclick="showBookInfo(this)" title="Détails"><i class="bi bi-info-circle"></i></button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="text-align:center;margin-top:2.5rem" class="reveal">
          <a href="explorer.php" class="btn-hero-secondary" style="display:inline-flex">
            Voir tout le catalogue <i class="bi bi-arrow-right" style="margin-left:8px"></i>
          </a>
        </div>
      </div>

      <!-- TRENDING SIDEBAR -->
      <div>
        <div class="reveal">
          <div class="section-eyebrow">Tendances</div>
          <h3 class="section-title" style="font-size:1.5rem">Top du moment</h3>
        </div>
        <div style="margin-top:1.5rem">
          <?php foreach ($trending as $i => $t):
            $tEmoji  = $emojis[$i % count($emojis)];
            $tExtrait= base64_encode(substr((string)($t['contenu_extrait']??''),0,8000));
          ?>
          <div class="trending-card reveal reveal-delay-<?= min($i+1,3) ?>"
               data-id="<?= (int)($t['id']??0) ?>"
               data-titre="<?= htmlspecialchars((string)($t['titre']??''),ENT_QUOTES,'UTF-8') ?>"
               data-auteur="<?= htmlspecialchars((string)($t['auteur']??''),ENT_QUOTES,'UTF-8') ?>"
               data-prix="<?= (float)($t['prix']??0) ?>"
               data-note="<?= (float)($t['note_moyenne']??0) ?>"
               data-emoji="<?= $tEmoji ?>"
               data-extrait="<?= $tExtrait ?>"
               data-pages="<?= (int)($t['pages']??200) ?>"
               onclick="handleReadFromCard(this)">
            <span class="trend-rank"><?= $i+1 ?></span>
            <div class="trend-info">
              <div class="trend-title"><?= htmlspecialchars((string)($t['titre']??'')) ?></div>
              <div class="trend-author"><?= htmlspecialchars((string)($t['auteur']??'')) ?></div>
            </div>
            <span class="trend-rating">★ <?= number_format((float)($t['note_moyenne']??0),1) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="section-divider"></div>

<!-- ═══════════ FEATURES ═══════════ -->
<section class="section">
  <div class="container">
    <div class="reveal">
      <div class="section-eyebrow">Fonctionnalités</div>
      <h2 class="section-title">Tout ce dont vous avez besoin</h2>
      <p class="section-sub">Une plateforme complète pour explorer, lire et gérer votre bibliothèque.</p>
    </div>
    <div class="features-grid">
      <div class="feature-card reveal">
        <div class="feature-icon fi-gold"><i class="bi bi-book" style="color:var(--gold)"></i></div>
        <div class="feature-name">Lecteur immersif</div>
        <div class="feature-desc">Interface style Kindle avec mode sombre/clair, police ajustable, navigation multi-pages et sauvegarde automatique de progression.</div>
      </div>
      <div class="feature-card reveal reveal-delay-1">
        <div class="feature-icon fi-sage"><i class="bi bi-shield-check" style="color:var(--sage)"></i></div>
        <div class="feature-name">Paiement sécurisé</div>
        <div class="feature-desc">Orange Money, Mobile Money, Visa, Mastercard, carte locale — transactions chiffrées, accès immédiat après validation.</div>
      </div>
      <div class="feature-card reveal reveal-delay-2">
        <div class="feature-icon fi-azure"><i class="bi bi-robot" style="color:var(--azure)"></i></div>
        <div class="feature-name">Recommandations IA</div>
        <div class="feature-desc">Notre assistant intelligent analyse vos goûts et vous propose des livres parfaitement adaptés à vos préférences.</div>
      </div>
      <div class="feature-card reveal reveal-delay-3">
        <div class="feature-icon fi-ember"><i class="bi bi-graph-up-arrow" style="color:var(--ember)"></i></div>
        <div class="feature-name">Statistiques avancées</div>
        <div class="feature-desc">Suivez votre progression, temps de lecture, et visualisez vos habitudes littéraires en temps réel.</div>
      </div>
    </div>
  </div>
</section>

<div class="section-divider"></div>

<!-- ═══════════ AI TERMINAL ═══════════ -->
<section class="section" id="assistant-section">
  <div class="container" style="max-width:800px">
    <div style="text-align:center;margin-bottom:2.5rem" class="reveal">
      <div class="section-eyebrow" style="justify-content:center">Assistant IA</div>
      <h2 class="section-title">Votre guide littéraire</h2>
    </div>
    <div class="ai-terminal reveal">
      <div class="ai-topbar">
        <div class="dot dot-r"></div><div class="dot dot-y"></div><div class="dot dot-g"></div>
        <span class="ai-titlebar">bibliotheque-ia — recommandation-engine v3.0</span>
      </div>
      <div class="ai-body">
        <div class="ai-prompt">$ ia.recommander --user=<?= $isLoggedIn ? htmlspecialchars($username) : 'guest' ?> --mode=interactif</div>
        <div id="ai-output"><span class="type-cursor"></span></div>
        <div class="ai-chat-row">
          <input type="text" class="ai-input" id="ai-input" placeholder="Posez une question à l'IA…" maxlength="200" autocomplete="off">
          <button class="ai-send" id="ai-send-btn"><i class="bi bi-send"></i> Envoyer</button>
        </div>
      </div>
      <div class="ai-footer">
        <div class="dt-block">
          <div class="dt-lbl">Date</div>
          <div class="dt-val" id="dt-date">—</div>
        </div>
        <div class="dt-block">
          <div class="dt-lbl">Heure</div>
          <div class="dt-val" id="dt-time">—</div>
        </div>
        <div class="dt-block" style="margin-left:auto">
          <div class="dt-lbl">Session</div>
          <div class="dt-val" style="color:<?= $isLoggedIn?'var(--sage)':'var(--ember)' ?>;font-size:0.8rem">
            <?= $isLoggedIn?'🟢 Connecté':'🔴 Invité' ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════ CTA ═══════════ -->
<section id="cta-section" class="reveal">
  <div style="position:relative;z-index:1">
    <div class="section-eyebrow" style="justify-content:center">Commencer</div>
    <h2 class="cta-title">Prêt à découvrir<br>votre prochaine lecture ?</h2>
    <p class="cta-sub">Rejoignez des milliers de lecteurs passionnés dès aujourd'hui.</p>
    <a href="<?= $isLoggedIn?'explorer.php':'register.php' ?>" class="btn-cta-main">
      <i class="bi bi-arrow-right-circle"></i>
      <?= $isLoggedIn?'Explorer le catalogue':'Commencer gratuitement' ?>
    </a>
  </div>
</section>

<!-- ═══════════ FOOTER ═══════════ -->
<footer>
  <div class="footer-inner">
    <div class="footer-grid">
      <div>
        <a href="index.php" class="nav-logo"><div class="logo-mark">📚</div> Digital Library</a>
        <p class="footer-brand-desc">Bibliothèque numérique premium. Explorez, lisez et gérez votre collection littéraire digitale.</p>
      </div>
      <div class="footer-col">
        <h5>Navigation</h5>
        <ul>
          <li><a href="index.php">Accueil</a></li>
          <li><a href="explorer.php">Explorer</a></li>
          <li><a href="categories.php">Catégories</a></li>
          <li><a href="tendances.php">Tendances</a></li>
          <li><a href="recommandations-ia.php">Recommandations IA</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h5>Compte</h5>
        <ul>
          <li><a href="login.php">Connexion</a></li>
          <li><a href="register.php">Inscription</a></li>
          <?php if($isLoggedIn): ?><li><a href="dashboard.php">Mon espace</a></li><li><a href="logout.php">Déconnexion</a></li><?php endif; ?>
          <li><a href="aide.php">Aide</a></li>
          </ul>
      </div>
 
    </div>
    <div class="footer-bottom">
      <p class="footer-copy">© <?= date('Y') ?> Digital Library System — Tous droits réservés.</p>
      <div class="tech-pills">
        <span class="tech-pill">PHP</span><span class="tech-pill">MySQL</span><span class="tech-pill">JavaScript</span>
      </div>
    </div>
  </div>
</footer>

<!-- ═══════════ TOAST ═══════════ -->
<div id="toast" role="alert" aria-live="polite">
  <span class="toast-icon" id="t-icon">✅</span>
  <div class="toast-text">
    <span class="toast-ttl" id="t-title">Notification</span>
    <span class="toast-msg" id="t-msg"></span>
  </div>
</div>

<!-- ═══════════════════════════════
     AUTH GATE MODAL — CORRIGÉ
     Fermeture fonctionne toujours
     Compte à rebours + redirection
═══════════════════════════════ -->
<div id="auth-gate" role="dialog" aria-modal="true" aria-labelledby="gate-heading">
  <div class="gate-backdrop" id="gate-backdrop"></div>
  <div class="gate-box">
    <button class="gate-close-btn" aria-label="Fermer" id="gate-close-btn">✕</button>
    <div class="gate-icon">🔒</div>
    <div class="gate-eyebrow">Accès restreint</div>
    <h2 class="gate-title" id="gate-heading">Connexion requise</h2>
    <p class="gate-desc">Créez un compte ou connectez-vous pour lire ce livre et accéder à tout le catalogue.</p>
    <div class="gate-btns">
      <a href="login.php" class="btn-gate-solid"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
      <a href="register.php" class="btn-gate-outline"><i class="bi bi-person-plus"></i> S'inscrire</a>
    </div>
    <div class="gate-countdown" id="gate-cd">Redirection dans 5s…</div>
    <div class="gate-progress"><div class="gate-progress-fill" id="gate-fill"></div></div>
  </div>
</div>

<!-- ═══════════════════════════════
     PAYMENT MODAL — CORRIGÉ
     Reset complet, champs vidés
     Simulation réaliste
═══════════════════════════════ -->
<div id="pay-modal" role="dialog" aria-modal="true" aria-labelledby="pay-modal-title">
  <div class="pay-backdrop" id="pay-backdrop"></div>
  <div class="pay-box">
    <div class="pay-bar"></div>
    <button class="pay-close" id="pay-close" aria-label="Fermer">✕</button>

    <div class="pay-header">
      <div class="pay-book-row">
        <div class="pay-book-thumb" id="pay-thumb">📚</div>
        <div class="pay-book-info">
          <div class="pay-book-title" id="pay-modal-title">Titre du livre</div>
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

      <!-- ADMIN GATE -->
      <div id="admin-gate-form" style="display:none">
        <div class="admin-notice">
          <i class="bi bi-shield-lock-fill"></i>
          <div>
            <strong style="color:var(--gold)">Accès administrateur</strong><br>
            Entrez votre email admin pour accéder gratuitement.
            <br><small style="color:var(--txt-muted)">Format : admin.[nom]@adminsopecam.com</small>
          </div>
        </div>
        <div class="field-group">
          <label class="field-label" for="admin-email-input">Email administrateur</label>
          <input type="email" class="field-input" id="admin-email-input" placeholder="admin.dupont@adminsopecam.com">
        </div>
        <button class="btn-pay" id="btn-admin-verify"><i class="bi bi-shield-check"></i> Vérifier et accéder</button>
      </div>

      <!-- PAYMENT STEPS -->
      <div id="pay-step-indicators">
        <div class="pay-steps">
          <div class="step-dot active" id="sd-1"></div>
          <div class="step-line" id="sl-1"></div>
          <div class="step-dot" id="sd-2"></div>
          <div class="step-line" id="sl-2"></div>
          <div class="step-dot" id="sd-3"></div>
          <div class="pay-step-label" id="step-label">Méthode de paiement</div>
        </div>

        <!-- STEP 1 : Choix méthode -->
        <div id="step-1">
          <div class="method-title">Choisissez votre méthode de paiement</div>
          <div class="methods-grid">
            <button class="method-btn" data-method="orange_money" type="button">
              <span class="method-icon">📱</span>
              <div><div class="method-name">Orange Money</div><div class="method-sub">Paiement mobile instantané</div></div>
            </button>
            <button class="method-btn" data-method="mobile_money" type="button">
              <span class="method-icon">📲</span>
              <div><div class="method-name">Mobile Money</div><div class="method-sub">MTN, Moov, etc.</div></div>
            </button>
            <button class="method-btn" data-method="visa" type="button">
              <span class="method-icon">💳</span>
              <div><div class="method-name">Visa</div><div class="method-sub">Carte de crédit/débit</div></div>
            </button>
            <button class="method-btn" data-method="mastercard" type="button">
              <span class="method-icon">🏦</span>
              <div><div class="method-name">Mastercard</div><div class="method-sub">Carte internationale</div></div>
            </button>
            <button class="method-btn" data-method="carte_locale" type="button" style="grid-column:span 2">
              <span class="method-icon">🏧</span>
              <div><div class="method-name">Carte Locale</div><div class="method-sub">Carte bancaire camerounaise</div></div>
            </button>
          </div>
          <button class="btn-pay" id="btn-next-step" disabled type="button">
            Continuer <i class="bi bi-arrow-right"></i>
          </button>
        </div>

        <!-- STEP 2 : Formulaire -->
        <div id="step-2" style="display:none">
          <!-- Formulaire Mobile Money -->
          <div id="form-mobile" style="display:none">
            <div class="field-group">
              <label class="field-label" for="phone-number">Numéro de téléphone</label>
              <input type="tel" class="field-input" id="phone-number" placeholder="6XX XXX XXX" maxlength="12" autocomplete="tel">
            </div>
            <div class="field-group">
              <label class="field-label" for="mobile-name">Nom complet</label>
              <input type="text" class="field-input" id="mobile-name" placeholder="Jean Dupont" autocomplete="name">
            </div>
          </div>
          <!-- Formulaire Carte -->
          <div id="form-card" style="display:none">
            <div class="field-group">
              <label class="field-label" for="card-number">Numéro de carte</label>
              <input type="text" class="field-input card-number-field" id="card-number" placeholder="1234 5678 9012 3456" maxlength="19" autocomplete="cc-number">
            </div>
            <div class="field-group">
              <label class="field-label" for="card-name">Nom du titulaire</label>
              <input type="text" class="field-input" id="card-name" placeholder="JEAN DUPONT" autocomplete="cc-name">
            </div>
            <div class="field-row">
              <div class="field-group">
                <label class="field-label" for="card-expiry">Expiration</label>
                <input type="text" class="field-input" id="card-expiry" placeholder="MM/AA" maxlength="5" autocomplete="cc-exp">
              </div>
              <div class="field-group">
                <label class="field-label" for="card-cvv">CVV</label>
                <input type="password" class="field-input" id="card-cvv" placeholder="•••" maxlength="4" autocomplete="cc-csc">
              </div>
            </div>
          </div>
          <button class="btn-pay" id="btn-pay-now" type="button"><i class="bi bi-lock-fill"></i> Payer maintenant</button>
          <div style="text-align:center;margin-top:0.5rem">
            <button id="btn-back-step1" type="button" style="background:none;border:none;color:var(--txt-muted);font-size:0.75rem;cursor:pointer;text-decoration:underline">← Changer de méthode</button>
          </div>
        </div>
      </div>

      <!-- PROCESSING -->
      <div id="pay-processing" style="display:none;text-align:center;padding:2rem 0">
        <div class="process-spinner"></div>
        <div style="font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;margin-bottom:0.4rem">Traitement en cours…</div>
        <div style="font-size:0.78rem;color:var(--txt-muted)">Veuillez ne pas fermer cette fenêtre</div>
        <div class="process-steps">
          <div class="proc-step" id="ps-1"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Connexion au serveur de paiement</span></div>
          <div class="proc-step" id="ps-2"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Vérification des informations</span></div>
          <div class="proc-step" id="ps-3"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Autorisation bancaire</span></div>
          <div class="proc-step" id="ps-4"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Confirmation de transaction</span></div>
        </div>
      </div>

      <!-- SUCCESS -->
      <div id="pay-success" style="display:none;text-align:center;padding:2rem 0">
        <div class="success-ring">✅</div>
        <div class="success-title">Paiement réussi !</div>
        <div class="success-sub">Votre accès au livre a été activé.</div>
        <div class="success-ref" id="success-ref">REF: —</div>
        <button class="btn-pay" id="btn-open-reader-after-pay" type="button"><i class="bi bi-book-open"></i> Ouvrir le lecteur</button>
      </div>
    </div>

    <div class="pay-security">
      <span class="sec-badge"><i class="bi bi-shield-check"></i> SSL 256-bit</span>
      <span class="sec-badge"><i class="bi bi-lock-fill"></i> Chiffré</span>
      <span class="sec-badge"><i class="bi bi-patch-check"></i> Sécurisé</span>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════
     READER MODAL — VERSION CORRIGÉE
     Multi-pages, fermeture invité,
     navigation clavier, thème
═══════════════════════════════ -->
<div id="reader-modal" role="dialog" aria-modal="true" aria-label="Lecteur de livre">

  <div class="reader-header">
    <div class="reader-title-wrap">
      <span id="reader-chapter-badge" style="font-family:'JetBrains Mono',monospace;font-size:0.6rem;color:var(--gold);background:rgba(232,201,125,0.1);border:1px solid rgba(232,201,125,0.2);padding:3px 8px;border-radius:6px;flex-shrink:0"></span>
      <span class="reader-title" id="reader-title">—</span>
    </div>
    <div class="reader-controls">
      <button class="reader-btn" title="Mode sombre/clair" id="btn-theme-toggle"><i class="bi bi-moon" id="theme-icon"></i></button>
      <button class="reader-btn" title="Police plus grande" id="btn-font-up"><i class="bi bi-zoom-in"></i></button>
      <button class="reader-btn" title="Police plus petite" id="btn-font-down"><i class="bi bi-zoom-out"></i></button>
      <button class="reader-btn" title="Marque-page" id="btn-bookmark"><i class="bi bi-bookmark" id="bookmark-icon"></i></button>
      <!-- ✅ CORRIGÉ : bouton fermer toujours fonctionnel -->
      <button class="reader-btn reader-close-btn" id="btn-reader-close" title="Fermer le lecteur"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>

  <div class="reader-progress-bar">
    <div class="reader-progress-fill" id="reader-progress" style="width:0%"></div>
  </div>

  <div class="reader-body" id="reader-body">
    <div class="reader-inner">
      <div class="reader-content" id="reader-content">Chargement du livre…</div>
    </div>
  </div>

  <div class="reader-nav">
    <button class="reader-nav-btn" id="btn-prev-page"><i class="bi bi-chevron-left"></i> Précédente</button>
    <span class="reader-page-info" id="reader-page-info">Page 1 / 1</span>
    <button class="reader-nav-btn" id="btn-next-page">Suivante <i class="bi bi-chevron-right"></i></button>
  </div>

</div>

<!-- ════════════════════════════════════════════════════════
     JAVASCRIPT — VERSION CORRIGÉE COMPLÈTE
════════════════════════════════════════════════════════ -->
<script>
'use strict';

// ── CONFIG PHP ──────────────────────────────────────────────
const IS_LOGGED = <?= json_encode($isLoggedIn) ?>;
const IS_ADMIN  = <?= json_encode($isAdmin) ?>;
const USERNAME  = <?= json_encode($username) ?>;

// ── STATE GLOBAL ────────────────────────────────────────────
let currentBook      = null;
let selectedMethod   = null;
let readerFontSize   = 17;
let readerIsLight    = false;
let readerCurrentPage= 1;
let readerTotalPages = 1;
let readerPages      = [];
let gateTimer        = null;
let gateInterval     = null;
let toastTimer       = null;
let aiTypingTimer    = null;

// ═══════════════════════════════════════════════════════════
// CUSTOM CURSOR (desktop uniquement)
// ═══════════════════════════════════════════════════════════
const cursorDot  = document.getElementById('cursor-dot');
const cursorRing = document.getElementById('cursor-ring');
if (cursorDot && cursorRing) {
  let mx=0,my=0,rx=0,ry=0;
  document.addEventListener('mousemove', e => {
    mx = e.clientX; my = e.clientY;
    cursorDot.style.left = mx + 'px';
    cursorDot.style.top  = my + 'px';
  });
  (function animRing() {
    rx += (mx - rx) * 0.13;
    ry += (my - ry) * 0.13;
    cursorRing.style.left = rx + 'px';
    cursorRing.style.top  = ry + 'px';
    requestAnimationFrame(animRing);
  })();
  document.querySelectorAll('a,button,.book-card,.trending-card').forEach(el => {
    el.addEventListener('mouseenter', () => {
      cursorDot.style.transform  = 'translate(-50%,-50%) scale(2)';
      cursorRing.style.width  = '48px';
      cursorRing.style.height = '48px';
    });
    el.addEventListener('mouseleave', () => {
      cursorDot.style.transform  = 'translate(-50%,-50%) scale(1)';
      cursorRing.style.width  = '32px';
      cursorRing.style.height = '32px';
    });
  });
}

// ═══════════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════════
function toast(title, msg='', type='default', dur=4000) {
  const el = document.getElementById('toast');
  const icons = { default:'ℹ️', warn:'⚠️', error:'❌', success:'🎉' };
  el.className = '';
  if (type !== 'default') el.classList.add('t-' + type);
  document.getElementById('t-icon').textContent  = icons[type] || 'ℹ️';
  document.getElementById('t-title').textContent = title;
  document.getElementById('t-msg').textContent   = msg;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), dur);
}

// ═══════════════════════════════════════════════════════════
// INTRO LOADER
// ═══════════════════════════════════════════════════════════
const bootFill = document.getElementById('boot-fill');
[{id:'il1',d:200},{id:'il2',d:700},{id:'il3',d:1300},{id:'il4',d:1900}].forEach((l,i) => {
  setTimeout(() => {
    const el = document.getElementById(l.id);
    if (el) el.classList.add('show');
    if (bootFill) bootFill.style.width = ((i+1)/4*100) + '%';
  }, l.d);
});
setTimeout(() => {
  const intro = document.getElementById('intro');
  if (intro) intro.classList.add('done');
  const msg = IS_LOGGED ? `Bienvenue, ${USERNAME} 👋` : 'Bienvenue sur Digital Library !';
  toast(msg, IS_LOGGED ? 'Accès complet activé.' : 'Connectez-vous pour accéder à tout.', IS_LOGGED ? 'success' : 'default', 5000);
}, 2800);

// ═══════════════════════════════════════════════════════════
// HEADER + HAMBURGER
// ═══════════════════════════════════════════════════════════
const ham = document.getElementById('hamburger');
const mobileNav = document.getElementById('mobile-nav');
if (ham && mobileNav) {
  ham.addEventListener('click', () => {
    const open = mobileNav.classList.toggle('open');
    ham.setAttribute('aria-expanded', open);
    ham.innerHTML = open ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
  });
}
const hdr = document.getElementById('site-header');
if (hdr) window.addEventListener('scroll', () => hdr.classList.toggle('scrolled', window.scrollY > 60));

// ═══════════════════════════════════════════════════════════
// DATETIME
// ═══════════════════════════════════════════════════════════
function updateDT() {
  const n = new Date();
  const d = document.getElementById('dt-date');
  const t = document.getElementById('dt-time');
  if (d) d.textContent = n.toLocaleDateString('fr-FR', {day:'2-digit',month:'long',year:'numeric'});
  if (t) t.textContent = n.toLocaleTimeString('fr-FR');
}
setInterval(updateDT, 1000);
updateDT();

// ═══════════════════════════════════════════════════════════
// SCROLL REVEAL
// ═══════════════════════════════════════════════════════════
const revObs = new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); revObs.unobserve(e.target); }});
}, { threshold: 0.1 });
document.querySelectorAll('.reveal').forEach(el => revObs.observe(el));

const cardObs = new IntersectionObserver(entries => {
  entries.forEach((e, i) => { if (e.isIntersecting) { setTimeout(() => e.target.classList.add('revealed'), i*80); cardObs.unobserve(e.target); }});
}, { threshold: 0.08 });
document.querySelectorAll('.book-card').forEach(el => cardObs.observe(el));

// ═══════════════════════════════════════════════════════════
// TYPING AI
// ═══════════════════════════════════════════════════════════
const aiPhrases = IS_LOGGED ? [
  `Bonjour ${USERNAME} ! Que souhaitez-vous lire aujourd'hui ?`,
  "Votre bibliothèque personnelle est prête. Découvrez nos nouveautés !",
  "Conseil du jour : explorez la Science-Fiction — de nouveaux titres viennent d'arriver !",
  "Basé sur vos préférences, je recommande les ouvrages de philosophie et technologie.",
] : [
  "Bonjour ! Je suis votre assistant littéraire IA. Comment puis-je vous aider ?",
  "Nous avons plus de 12 000 livres dans 5 catégories. Créez un compte pour y accéder !",
  "Je peux vous recommander des livres selon vos goûts. Inscrivez-vous gratuitement.",
  "Votre prochaine grande lecture vous attend — rejoignez notre communauté !",
];
let aiPIdx=0, aiCIdx=0, aiDeleting=false;
const aiOut = document.getElementById('ai-output');
function aiType() {
  if (!aiOut) return;
  const p = aiPhrases[aiPIdx];
  const cur = '<span class="type-cursor"></span>';
  if (!aiDeleting && aiCIdx <= p.length) {
    aiOut.innerHTML = p.slice(0, aiCIdx) + cur; aiCIdx++;
    aiTypingTimer = setTimeout(aiType, 40);
  } else if (aiDeleting && aiCIdx >= 0) {
    aiOut.innerHTML = p.slice(0, aiCIdx) + cur; aiCIdx--;
    aiTypingTimer = setTimeout(aiType, 18);
  } else if (!aiDeleting) {
    aiDeleting = true;
    aiTypingTimer = setTimeout(aiType, 2800);
  } else {
    aiDeleting = false;
    aiPIdx = (aiPIdx + 1) % aiPhrases.length;
    aiCIdx = 0;
    aiTypingTimer = setTimeout(aiType, 400);
  }
}
setTimeout(aiType, 3200);

// AI Chat
function handleAiChat() {
  const inp = document.getElementById('ai-input');
  const q = inp ? inp.value.trim() : '';
  if (!q) return;
  clearTimeout(aiTypingTimer);
  const replies = [
    `Pour "${q}", explorez notre catalogue — plusieurs titres correspondent !`,
    `Excellente question sur "${q}" ! Consultez la section Tendances pour les meilleures œuvres.`,
    `Je cherche des livres sur "${q}" dans notre base… Résultats disponibles dans Explorer.`,
    `Notre collection contient de nombreux titres liés à "${q}". ${IS_LOGGED ? 'Bonne lecture !' : 'Connectez-vous pour y accéder !'}`,
  ];
  if (aiOut) aiOut.innerHTML = replies[Math.floor(Math.random() * replies.length)] + '<span class="type-cursor"></span>';
  if (inp) inp.value = '';
}
const aiSendBtn = document.getElementById('ai-send-btn');
const aiInput   = document.getElementById('ai-input');
if (aiSendBtn) aiSendBtn.addEventListener('click', handleAiChat);
if (aiInput)   aiInput.addEventListener('keydown', e => { if (e.key === 'Enter') handleAiChat(); });

// ═══════════════════════════════════════════════════════════
// CATEGORY PILLS
// ═══════════════════════════════════════════════════════════
document.querySelectorAll('.cat-pill').forEach(pill => {
  pill.addEventListener('click', () => {
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
  });
});

// ═══════════════════════════════════════════════════════════
// AUTH GATE — CORRIGÉ
// ✅ Fermeture fonctionne toujours (bouton X, backdrop, Escape)
// ✅ Compte à rebours décrémente correctement
// ✅ Redirection vers login.php après 5s
// ═══════════════════════════════════════════════════════════
function openAuthGate() {
  const gate = document.getElementById('auth-gate');
  if (!gate) return;
  gate.classList.add('open');
  document.body.style.overflow = 'hidden';

  let sec = 5;
  const cdEl   = document.getElementById('gate-cd');
  const fillEl = document.getElementById('gate-fill');

  if (cdEl) cdEl.textContent = `Redirection dans ${sec}s…`;

  // Animation barre
  if (fillEl) {
    fillEl.style.transition = 'none';
    fillEl.style.transform  = 'scaleX(1)';
    // Forcer reflow
    fillEl.getBoundingClientRect();
    fillEl.style.transition = 'transform 5s linear';
    fillEl.style.transform  = 'scaleX(0)';
  }

  // Décrémenter chaque seconde
  clearInterval(gateInterval);
  gateInterval = setInterval(() => {
    sec--;
    if (cdEl) cdEl.textContent = `Redirection dans ${sec}s…`;
    if (sec <= 0) clearInterval(gateInterval);
  }, 1000);

  // Redirection après 5s
  clearTimeout(gateTimer);
  gateTimer = setTimeout(() => { window.location.href = 'login.php'; }, 5000);
}

function closeAuthGate() {
  const gate = document.getElementById('auth-gate');
  if (gate) gate.classList.remove('open');
  document.body.style.overflow = '';
  clearTimeout(gateTimer);
  clearInterval(gateInterval);
  gateTimer = null;
  gateInterval = null;
}

// Liaisons fermeture auth gate
const gateCloseBtn = document.getElementById('gate-close-btn');
const gateBackdrop = document.getElementById('gate-backdrop');
if (gateCloseBtn) gateCloseBtn.addEventListener('click', closeAuthGate);
if (gateBackdrop) gateBackdrop.addEventListener('click', closeAuthGate);

// ═══════════════════════════════════════════════════════════
// BOOK DATA — Décodage base64 sécurisé
// ═══════════════════════════════════════════════════════════
function getBookDataFromCard(card) {
  if (!card) return null;
  let extrait = '';
  try {
    const b64 = card.dataset.extrait || '';
    if (b64) extrait = atob(b64);
  } catch(e) {
    extrait = card.dataset.extrait || '';
  }
  return {
    id:     card.dataset.id     || '0',
    title:  card.dataset.titre  || '—',
    author: card.dataset.auteur || '—',
    price:  parseFloat(card.dataset.prix)  || 0,
    note:   parseFloat(card.dataset.note)  || 0,
    emoji:  card.dataset.emoji  || '📚',
    pages:  parseInt(card.dataset.pages)   || 200,
    extrait: extrait,
  };
}

// ═══════════════════════════════════════════════════════════
// DISPATCH READ — Logique centrale
// ═══════════════════════════════════════════════════════════
function handleRead(btnEl) {
  const card = btnEl ? btnEl.closest('.book-card') : null;
  if (!card) return;
  currentBook = getBookDataFromCard(card);
  dispatchRead();
}
function handleReadFromCard(cardEl) {
  currentBook = getBookDataFromCard(cardEl);
  dispatchRead();
}
function dispatchRead() {
  if (!currentBook) return;
  if (!IS_LOGGED) { openAuthGate(); return; }
  const isFree = currentBook.price === 0 || currentBook.note <= 2.0;
  if (IS_ADMIN)  { openPayModal(true);  return; }
  if (isFree)    { openReader();         return; }
  openPayModal(false);
}

function showBookInfo(btnEl) {
  const card = btnEl ? btnEl.closest('.book-card') : null;
  if (!card) return;
  const prix = parseFloat(card.dataset.prix) || 0;
  toast(card.dataset.titre || '—', `par ${card.dataset.auteur || '—'} — ${prix === 0 ? 'Gratuit' : prix.toLocaleString('fr-FR') + ' FCFA'}`, 'default', 4000);
}

// ═══════════════════════════════════════════════════════════
// PAYMENT MODAL — CORRIGÉ COMPLET
// ✅ Reset complet des champs après paiement
// ✅ Simulation réaliste étape par étape
// ✅ Ouverture lecteur après succès
// ═══════════════════════════════════════════════════════════
function openPayModal(adminMode) {
  if (!currentBook) return;
  document.getElementById('pay-modal-title').textContent = currentBook.title;
  document.getElementById('pay-author').textContent      = 'par ' + currentBook.author;
  document.getElementById('pay-thumb').textContent       = currentBook.emoji;
  document.getElementById('pay-amount').textContent      = currentBook.price === 0 ? '0' : currentBook.price.toLocaleString('fr-FR');

  resetPayModal();

  if (adminMode) {
    document.getElementById('admin-gate-form').style.display    = 'block';
    document.getElementById('pay-step-indicators').style.display = 'none';
  } else {
    document.getElementById('admin-gate-form').style.display    = 'none';
    document.getElementById('pay-step-indicators').style.display = 'block';
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
  // Affichage vues
  const step1    = document.getElementById('step-1');
  const step2    = document.getElementById('step-2');
  const procEl   = document.getElementById('pay-processing');
  const succEl   = document.getElementById('pay-success');
  const adminEl  = document.getElementById('admin-gate-form');
  const stepsEl  = document.getElementById('pay-step-indicators');

  if (step1)   step1.style.display   = 'block';
  if (step2)   step2.style.display   = 'none';
  if (procEl)  procEl.style.display  = 'none';
  if (succEl)  succEl.style.display  = 'none';
  if (adminEl) adminEl.style.display = 'none';
  if (stepsEl) stepsEl.style.display = 'block';

  // Reset step indicators
  ['sd-1','sd-2','sd-3'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.className = 'step-dot';
  });
  ['sl-1','sl-2'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.className = 'step-line';
  });
  const sd1 = document.getElementById('sd-1');
  if (sd1) sd1.classList.add('active');
  const lbl = document.getElementById('step-label');
  if (lbl) lbl.textContent = 'Méthode de paiement';

  // ✅ Reset méthodes sélectionnées
  document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('selected'));
  selectedMethod = null;
  const btnNext = document.getElementById('btn-next-step');
  if (btnNext) btnNext.disabled = true;

  // ✅ Vider TOUS les champs
  ['phone-number','mobile-name','card-number','card-name','card-expiry','card-cvv','admin-email-input'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.value = ''; el.style.borderColor = ''; }
  });

  // Reset formulaires
  const fmob  = document.getElementById('form-mobile');
  const fcard = document.getElementById('form-card');
  if (fmob)  fmob.style.display  = 'none';
  if (fcard) fcard.style.display = 'none';

  // Reset proc steps
  ['ps-1','ps-2','ps-3','ps-4'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.className = 'proc-step';
  });
}

// Sélection méthode
document.querySelectorAll('.method-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    selectedMethod = btn.dataset.method;
    const btnNext = document.getElementById('btn-next-step');
    if (btnNext) btnNext.disabled = false;
  });
});

// Étape 1 → 2
const btnNextStep = document.getElementById('btn-next-step');
if (btnNextStep) {
  btnNextStep.addEventListener('click', () => {
    if (!selectedMethod) return;
    document.getElementById('step-1').style.display = 'none';
    document.getElementById('step-2').style.display = 'block';

    document.getElementById('sd-1').className = 'step-dot done';
    document.getElementById('sl-1').className = 'step-line done';
    document.getElementById('sd-2').className = 'step-dot active';
    document.getElementById('step-label').textContent = 'Informations de paiement';

    const isMobile = ['orange_money','mobile_money'].includes(selectedMethod);
    const fmob  = document.getElementById('form-mobile');
    const fcard = document.getElementById('form-card');
    if (fmob)  fmob.style.display  = isMobile ? 'block' : 'none';
    if (fcard) fcard.style.display = isMobile ? 'none'  : 'block';
  });
}

// Retour étape 1
const btnBack = document.getElementById('btn-back-step1');
if (btnBack) {
  btnBack.addEventListener('click', () => {
    document.getElementById('step-1').style.display = 'block';
    document.getElementById('step-2').style.display = 'none';
    document.getElementById('sd-1').className = 'step-dot active';
    document.getElementById('sl-1').className = 'step-line';
    document.getElementById('sd-2').className = 'step-dot';
    document.getElementById('step-label').textContent = 'Méthode de paiement';
  });
}

// Payer maintenant
const btnPayNow = document.getElementById('btn-pay-now');
if (btnPayNow) {
  btnPayNow.addEventListener('click', () => {
    // Validation
    const isMobile = ['orange_money','mobile_money'].includes(selectedMethod);
    if (isMobile) {
      const phone = (document.getElementById('phone-number').value || '').replace(/\s/g,'');
      if (phone.length < 9) { toast('Numéro invalide', 'Entrez un numéro valide (min 9 chiffres)', 'error'); return; }
    } else {
      const cn  = (document.getElementById('card-number').value || '').replace(/\s/g,'');
      const exp = document.getElementById('card-expiry').value || '';
      const cvv = document.getElementById('card-cvv').value || '';
      if (cn.length < 16) { toast('Carte invalide', 'Numéro de carte incomplet (16 chiffres)', 'error'); return; }
      if (!exp.match(/^\d{2}\/\d{2}$/)) { toast('Expiration invalide', 'Format MM/AA requis', 'error'); return; }
      if (cvv.length < 3) { toast('CVV invalide', '3 à 4 chiffres requis', 'error'); return; }
    }

    // Passer à l'étape 3
    document.getElementById('sd-2').className = 'step-dot done';
    document.getElementById('sl-2').className = 'step-line done';
    document.getElementById('sd-3').className = 'step-dot active';
    document.getElementById('step-label').textContent = 'Traitement…';
    document.getElementById('step-2').style.display    = 'none';
    document.getElementById('pay-processing').style.display = 'block';

    // Animation étapes
    const steps   = ['ps-1','ps-2','ps-3','ps-4'];
    const delays  = [0, 950, 1950, 3100];
    steps.forEach((sid, i) => {
      setTimeout(() => {
        if (i > 0) {
          const prev = document.getElementById(steps[i-1]);
          if (prev) prev.className = 'proc-step done';
        }
        const cur = document.getElementById(sid);
        if (cur) cur.className = 'proc-step active';
      }, delays[i]);
    });
    setTimeout(() => {
      const last = document.getElementById(steps[steps.length-1]);
      if (last) last.className = 'proc-step done';
      setTimeout(showPaySuccess, 400);
    }, delays[delays.length-1] + 700);
  });
}

function showPaySuccess() {
  document.getElementById('pay-processing').style.display = 'none';
  document.getElementById('pay-success').style.display    = 'block';
  document.getElementById('sd-3').className = 'step-dot done';
  const ref = 'DLS-' + Date.now().toString(36).toUpperCase() + '-' + Math.random().toString(36).slice(2,6).toUpperCase();
  const refEl = document.getElementById('success-ref');
  if (refEl) refEl.textContent = 'REF: ' + ref;
  toast('Paiement validé !', 'Accès au livre activé.', 'success', 5000);

  // Tentative de sauvegarde côté serveur
  if (currentBook) {
    fetch('api/save_purchase.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({livre_id:currentBook.id, montant:currentBook.price, methode:selectedMethod, reference:ref})
    }).catch(() => {});
  }
}

// ✅ Bouton "Ouvrir le lecteur" dans succès
const btnOpenReaderAfterPay = document.getElementById('btn-open-reader-after-pay');
if (btnOpenReaderAfterPay) {
  btnOpenReaderAfterPay.addEventListener('click', () => {
    closePayModal();
    setTimeout(() => openReader(), 300);
  });
}

// Admin verify
const btnAdminVerify = document.getElementById('btn-admin-verify');
if (btnAdminVerify) {
  btnAdminVerify.addEventListener('click', () => {
    const emailEl = document.getElementById('admin-email-input');
    const email   = (emailEl ? emailEl.value.trim() : '');
    const pattern = /^admin\.[a-zA-Z][a-zA-Z0-9.]*@adminsopecam\.com$/;
    if (!pattern.test(email)) {
      toast('Email invalide', 'Format requis : admin.[nom]@adminsopecam.com', 'error');
      if (emailEl) emailEl.style.borderColor = 'rgba(255,95,87,0.5)';
      return;
    }
    if (emailEl) emailEl.style.borderColor = 'rgba(78,204,163,0.5)';
    toast('Accès administrateur accordé', 'Ouverture du lecteur…', 'success', 3000);
    setTimeout(() => { closePayModal(); openReader(); }, 1200);
  });
}

// Formatage carte
const cardNumberInput = document.getElementById('card-number');
if (cardNumberInput) {
  cardNumberInput.addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').slice(0,16);
    this.value = v.match(/.{1,4}/g)?.join(' ') || v;
  });
}
const cardExpiryInput = document.getElementById('card-expiry');
if (cardExpiryInput) {
  cardExpiryInput.addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'');
    if (v.length > 2) v = v.slice(0,2) + '/' + v.slice(2,4);
    this.value = v;
  });
}

// Fermeture modale paiement
const payClose   = document.getElementById('pay-close');
const payBackdrop = document.getElementById('pay-backdrop');
if (payClose)    payClose.addEventListener('click', closePayModal);
if (payBackdrop) payBackdrop.addEventListener('click', closePayModal);

// ═══════════════════════════════════════════════════════════
// READER — CORRIGÉ COMPLET
// ✅ Fermeture fonctionne pour tous (invités inclus)
// ✅ Navigation multi-pages avec animation
// ✅ Minimum 5 pages par livre
// ✅ Sauvegarde progression localStorage
// ✅ Thème clair/sombre
// ✅ Taille police ajustable
// ═══════════════════════════════════════════════════════════
function openReader() {
  if (!currentBook) return;
  const modal = document.getElementById('reader-modal');
  if (!modal) return;

  document.getElementById('reader-title').textContent = currentBook.title;

  // Générer les pages
  const raw = currentBook.extrait || generateFallbackContent(currentBook.title);
  readerPages = buildPages(raw, currentBook.pages || 200);
  readerTotalPages  = readerPages.length;
  readerCurrentPage = 1;

  // Restaurer progression
  try {
    const saved = localStorage.getItem('dls_page_' + currentBook.id);
    if (saved) {
      const p = parseInt(saved, 10);
      if (p >= 1 && p <= readerTotalPages) readerCurrentPage = p;
    }
  } catch(e) {}

  renderPage(false);
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
  toast('Lecteur ouvert', `${readerTotalPages} pages · Bonne lecture !`, 'success', 3000);
}

// ✅ Génère MINIMUM 5 pages depuis le contenu
function buildPages(content, totalBookPages) {
  // Séparateur de pages inséré dans le PHP
  const parts = content.split('||||PAGE||||').map(p => p.trim()).filter(p => p.length > 0);

  if (parts.length >= 5) return parts;

  // Compléter jusqu'à 5 pages
  const extras = [
    `APPROFONDISSEMENT\n\nLa lecture de cette œuvre révèle progressivement la profondeur de la vision de l'auteur. Chaque mot a été soigneusement choisi pour créer une expérience unique et mémorable.\n\nLes thèmes abordés résonnent avec une actualité frappante, nous invitant à réfléchir sur notre propre condition et sur les grandes questions qui traversent notre époque.`,
    `RÉFLEXIONS\n\nCette partie de l'œuvre nous plonge dans une méditation profonde. L'auteur tisse ensemble des fils narratifs qui semblaient disparates pour révéler une tapisserie d'une richesse insoupçonnée.\n\nLe lecteur se retrouve transformé par cette expérience, portant en lui de nouvelles questions et de nouvelles perspectives sur le monde.`,
    `VERS LA FIN\n\nAlors que nous approchons de la conclusion de ce voyage littéraire, il convient de s'arrêter pour mesurer le chemin parcouru.\n\nCette œuvre restera comme un témoignage précieux de son époque, et comme une œuvre intemporelle qui continuera de parler aux générations futures.`,
    `ÉPILOGUE\n\nLa dernière page tournée, le livre refermé, le lecteur reste un moment silencieux, habité par ce qu'il vient de vivre. C'est la marque des grandes œuvres : elles ne nous quittent pas vraiment.\n\nMerci d'avoir partagé ce voyage. À bientôt pour de nouvelles aventures dans l'univers infini des livres.`,
    `POSTFACE\n\nL'auteur nous offre ici une œuvre qui dépasse les frontières du genre. À la fois témoignage, réflexion et création artistique, ce livre occupe une place unique dans la littérature contemporaine.\n\nRevenez-y dans quelques mois — vous le lirez différemment, et ce sera comme le découvrir à nouveau.`,
  ];

  const result = [...parts];
  let idx = 0;
  while (result.length < 5) {
    result.push(extras[idx % extras.length]);
    idx++;
  }
  return result;
}

function generateFallbackContent(title) {
  return `CHAPITRE I — Introduction||||PAGE||||CHAPITRE II — Développement\n\n${title} nous plonge dans un univers fascinant. Chaque page révèle de nouveaux secrets.||||PAGE||||CHAPITRE III — Le Nœud\n\nLa tension dramatique atteint son apogée. Les personnages sont confrontés à leurs contradictions les plus profondes.||||PAGE||||CHAPITRE IV — Les Révélations\n\nLes vérités cachées émergent enfin. Ce que le lecteur croyait comprendre se révèle bien plus complexe.||||PAGE||||CHAPITRE V — Dénouement\n\nLa conclusion, inattendue et inévitable, laisse une empreinte durable. Une œuvre qui restera longtemps en mémoire.`;
}

function renderPage(animate, direction='next') {
  const contentEl = document.getElementById('reader-content');
  if (!contentEl) return;

  const raw = readerPages[readerCurrentPage - 1] || 'Contenu non disponible.';

  // Formatage HTML
  let html = raw
    .replace(/^(CHAPITRE [IVX0-9]+[^—\n]*(?:—[^\n]*)?)/gm, '<h2>$1</h2>')
    .replace(/^(ÉPILOGUE|POSTFACE|APPROFONDISSEMENT|RÉFLEXIONS|VERS LA FIN)/gm, '<h2>$1</h2>')
    .replace(/\n\n+/g, '</p><p>')
    .replace(/\n/g, '<br>');
  html = '<p>' + html + '</p>';
  html = html.replace(/<p><h2>/g,'<h2>').replace(/<\/h2><\/p>/g,'</h2>').replace(/<p><\/p>/g,'');

  // Mettre à jour les infos
  const pageInfo = document.getElementById('reader-page-info');
  const progress = document.getElementById('reader-progress');
  const chapterBadge = document.getElementById('reader-chapter-badge');
  const btnPrev = document.getElementById('btn-prev-page');
  const btnNext = document.getElementById('btn-next-page');

  if (pageInfo) pageInfo.textContent = `Page ${readerCurrentPage} / ${readerTotalPages}`;
  if (progress) progress.style.width = ((readerCurrentPage / readerTotalPages) * 100).toFixed(1) + '%';
  if (chapterBadge) chapterBadge.textContent = `Ch. ${readerCurrentPage}`;
  if (btnPrev) btnPrev.disabled = readerCurrentPage === 1;
  if (btnNext) btnNext.disabled = readerCurrentPage === readerTotalPages;

  // Sauvegarde progression
  try {
    if (currentBook) localStorage.setItem('dls_page_' + currentBook.id, readerCurrentPage);
  } catch(e) {}

  if (!animate) {
    contentEl.innerHTML = html;
    contentEl.style.fontSize = readerFontSize + 'px';
    const body = document.getElementById('reader-body');
    if (body) body.scrollTop = 0;
    return;
  }

  // Animation
  const animClass = direction === 'next' ? 'page-anim-next' : 'page-anim-prev';
  contentEl.style.opacity = '0';
  contentEl.style.transform = direction === 'next' ? 'translateX(-30px)' : 'translateX(30px)';
  contentEl.style.transition = 'opacity 0.15s ease, transform 0.15s ease';

  setTimeout(() => {
    contentEl.innerHTML   = html;
    contentEl.style.fontSize = readerFontSize + 'px';
    contentEl.style.opacity = '0';
    contentEl.style.transform = direction === 'next' ? 'translateX(30px)' : 'translateX(-30px)';
    contentEl.style.transition = 'none';

    const body = document.getElementById('reader-body');
    if (body) body.scrollTop = 0;

    requestAnimationFrame(() => {
      contentEl.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
      contentEl.style.opacity    = '1';
      contentEl.style.transform  = 'translateX(0)';
    });
  }, 150);
}

// Navigation pages
const btnPrevPage = document.getElementById('btn-prev-page');
const btnNextPage = document.getElementById('btn-next-page');
if (btnPrevPage) {
  btnPrevPage.addEventListener('click', () => {
    if (readerCurrentPage > 1) {
      readerCurrentPage--;
      renderPage(true, 'prev');
    } else {
      toast('Début', 'Vous êtes à la première page.', 'warn', 2000);
    }
  });
}
if (btnNextPage) {
  btnNextPage.addEventListener('click', () => {
    if (readerCurrentPage < readerTotalPages) {
      readerCurrentPage++;
      renderPage(true, 'next');
    } else {
      toast('Fin du livre', "Vous avez atteint la dernière page de cet extrait.", 'warn', 3000);
    }
  });
}

// ✅ FERMETURE LECTEUR — fonctionne pour TOUS les utilisateurs
function closeReader() {
  const modal = document.getElementById('reader-modal');
  if (modal) modal.classList.remove('open');
  document.body.style.overflow = '';
}

const btnReaderClose = document.getElementById('btn-reader-close');
if (btnReaderClose) btnReaderClose.addEventListener('click', closeReader);

// Thème clair/sombre
const btnThemeToggle = document.getElementById('btn-theme-toggle');
if (btnThemeToggle) {
  btnThemeToggle.addEventListener('click', () => {
    const modal = document.getElementById('reader-modal');
    const icon  = document.getElementById('theme-icon');
    if (!modal) return;
    readerIsLight = !readerIsLight;
    modal.classList.toggle('reader-light', readerIsLight);
    if (icon) icon.className = readerIsLight ? 'bi bi-sun-fill' : 'bi bi-moon';
    toast('Thème', readerIsLight ? 'Mode clair activé.' : 'Mode sombre activé.', 'default', 1500);
  });
}

// Taille police
const btnFontUp   = document.getElementById('btn-font-up');
const btnFontDown = document.getElementById('btn-font-down');
if (btnFontUp) {
  btnFontUp.addEventListener('click', () => {
    readerFontSize = Math.min(24, readerFontSize + 2);
    const c = document.getElementById('reader-content');
    if (c) c.style.fontSize = readerFontSize + 'px';
    toast('Police', `Taille : ${readerFontSize}px`, 'default', 1500);
  });
}
if (btnFontDown) {
  btnFontDown.addEventListener('click', () => {
    readerFontSize = Math.max(12, readerFontSize - 2);
    const c = document.getElementById('reader-content');
    if (c) c.style.fontSize = readerFontSize + 'px';
    toast('Police', `Taille : ${readerFontSize}px`, 'default', 1500);
  });
}

// Marque-page
const btnBookmark = document.getElementById('btn-bookmark');
if (btnBookmark) {
  btnBookmark.addEventListener('click', () => {
    const icon = document.getElementById('bookmark-icon');
    if (icon) { icon.className = 'bi bi-bookmark-fill'; icon.style.color = 'var(--gold)'; }
    setTimeout(() => { if (icon) { icon.className = 'bi bi-bookmark'; icon.style.color = ''; }}, 2000);
    toast('Marque-page', `Page ${readerCurrentPage}/${readerTotalPages} sauvegardée.`, 'success', 3000);
  });
}

// ═══════════════════════════════════════════════════════════
// NAVIGATION CLAVIER GLOBALE
// ═══════════════════════════════════════════════════════════
document.addEventListener('keydown', e => {
  const readerOpen = document.getElementById('reader-modal')?.classList.contains('open');
  const payOpen    = document.getElementById('pay-modal')?.classList.contains('open');
  const gateOpen   = document.getElementById('auth-gate')?.classList.contains('open');

  if (e.key === 'Escape') {
    if (readerOpen) closeReader();
    else if (payOpen) closePayModal();
    else if (gateOpen) closeAuthGate();
    return;
  }

  if (readerOpen) {
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'PageDown') {
      e.preventDefault();
      if (readerCurrentPage < readerTotalPages) { readerCurrentPage++; renderPage(true,'next'); }
    }
    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') {
      e.preventDefault();
      if (readerCurrentPage > 1) { readerCurrentPage--; renderPage(true,'prev'); }
    }
  }
});

// ═══════════════════════════════════════════════════════════
// PARALLAXE ORBS
// ═══════════════════════════════════════════════════════════
document.addEventListener('mousemove', e => {
  const x = (e.clientX / window.innerWidth  - 0.5) * 30;
  const y = (e.clientY / window.innerHeight - 0.5) * 30;
  const a = document.querySelector('.orb-a');
  const b = document.querySelector('.orb-b');
  if (a) a.style.transform = `translate(${x}px,${y}px)`;
  if (b) b.style.transform = `translate(${-x}px,${-y}px)`;
});
</script>
</body>
</html>