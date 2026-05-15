<?php
// ============================================================
// DIGITAL LIBRARY — recommandations-ia.php
// Design harmonisé avec index.php — Backend intact
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();


$pdo = null;
$configPath = __DIR__ . '/includes/config.php';
if (file_exists($configPath)) { require_once $configPath; }

if (!isset($pdo) || $pdo === null) {
    try {
        $pdo = new PDO(
            "mysql:host=" . (defined('DB_HOST') ? DB_HOST : 'localhost') .
            ";dbname="    . (defined('DB_NAME') ? DB_NAME : 'digital_library') .
            ";charset=utf8mb4",
            defined('DB_USER') ? DB_USER : 'root',
            defined('DB_PASS') ? DB_PASS : '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) { $pdo = null; }
}

$username = 'Lecteur';

$isLoggedIn = isset($_SESSION['user_id']);
$userId = $_SESSION['user_id'] ?? null;

if ($isLoggedIn && $pdo && $userId) { 
    $stmt = $pdo->prepare("SELECT prenom, nom FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();

    if ($userData) {
        $username = htmlspecialchars(
            $userData['prenom'] . ' ' . $userData['nom'],
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin    = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$username = 'Lecteur';

if (!empty($_SESSION['user_name'])) {
    $username = htmlspecialchars($_SESSION['user_name']);
} elseif (!empty($_SESSION['user_prenom']) && !empty($_SESSION['user_nom'])) {
    $username = htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']);
}
$userId     = $_SESSION['user_id'] ?? null;

$recs        = [];
$becauseRecs = [];
$trending    = [];
$userStats   = ['total_achats' => 0, 'genres_preferes' => []];
$totalLivres = 0;

if ($pdo !== null) {
    try {
        $totalLivres = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
        $stmt = $pdo->prepare("SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes, l.contenu_extrait, l.pages, c.nom AS genre, c.icone AS genre_icone FROM livres l LEFT JOIN categories c ON c.id = l.categorie_id WHERE l.statut = 'disponible' ORDER BY l.note_moyenne DESC, l.nb_ventes DESC LIMIT 24");
        $stmt->execute(); $recs = $stmt->fetchAll();
        $stmt2 = $pdo->prepare("SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes, l.contenu_extrait, l.pages, c.nom AS genre, c.icone AS genre_icone FROM livres l LEFT JOIN categories c ON c.id = l.categorie_id WHERE l.statut = 'disponible' ORDER BY l.nb_ventes DESC LIMIT 12");
        $stmt2->execute(); $trending = $stmt2->fetchAll();
        if ($isLoggedIn && $userId) {
            $sa = $pdo->prepare("SELECT COUNT(*) FROM achats WHERE user_id=? AND statut='confirme'");
            $sa->execute([$userId]); $userStats['total_achats'] = (int)$sa->fetchColumn();
            $sb = $pdo->prepare("SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.contenu_extrait, l.pages, c.nom AS genre, c.icone AS genre_icone FROM achats a JOIN livres l ON l.id = a.livre_id LEFT JOIN categories c ON c.id = l.categorie_id WHERE a.user_id = ? AND a.statut = 'confirme' ORDER BY a.created_at DESC LIMIT 3");
            $sb->execute([$userId]); $becauseRecs = $sb->fetchAll();
        }
    } catch (PDOException $e) { /* silent */ }
}

$demoRecs = [
    ['id'=>1,'titre'=>"L'Œil de l'Univers",'auteur'=>'Elena Korvach','prix'=>4500,'note_moyenne'=>4.9,'nb_ventes'=>8234,'genre'=>'Science-Fiction','genre_icone'=>'🌌','pages'=>342,'contenu_extrait'=>"CHAPITRE I — L'Éveil\n\nIl était une fois dans une galaxie lointaine, une civilisation qui avait atteint les sommets de la connaissance.\n\nElena Korvach, scientifique de renom, fixait l'horizon galactique depuis son observatoire orbital.\n\n— L'univers nous parle, murmura-t-elle. Nous devons apprendre à écouter.\n\n||||PAGE||||CHAPITRE II — La Découverte\n\nLes semaines suivantes furent frénétiques. L'équipe entière se mobilisa autour de cette anomalie baptisée simplement : l'Œil.\n\nUn signal d'une régularité mathématique parfaite émanait de ce point précis de l'espace.\n\n||||PAGE||||CHAPITRE III — Le Voyage\n\nLa sonde Nova-7 traversa des millions de kilomètres. Quand les premières images arrivèrent, un silence de cathédrale s'abattit.\n\nL'Œil n'était pas une nébuleuse. C'était une structure. Artificielle.\n\n||||PAGE||||CHAPITRE IV — Le Contact\n\n« Bienvenue. Nous vous attendions. »\n\nL'humanité n'était plus seule.\n\n||||PAGE||||CHAPITRE V — L'Héritage\n\nCe que l'humanité apprit au contact de cette civilisation ancienne révolutionna chaque aspect de la vie humaine."],
    ['id'=>2,'titre'=>'Le Paradoxe du Libre Arbitre','auteur'=>'Jean-Marc Duvall','prix'=>3200,'note_moyenne'=>4.7,'nb_ventes'=>6120,'genre'=>'Philosophie','genre_icone'=>'🧠','pages'=>289,'contenu_extrait'=>"CHAPITRE I — La Question\n\nSommes-nous libres ? Cette question fondamentale a hanté l'humanité depuis l'aube de la conscience.\n\n||||PAGE||||CHAPITRE II — Les Déterministes\n\nSpinoza, Laplace : tous pointent vers la même conclusion. L'expérience de Libet est particulièrement dérangeante.\n\n||||PAGE||||CHAPITRE III — La Brèche Quantique\n\nL'indéterminisme quantique ouvre peut-être une brèche réelle dans la causalité déterministe.\n\n||||PAGE||||CHAPITRE IV — Le Compatibilisme\n\nÊtre libre ne signifie pas échapper à la causalité. Cela signifie agir selon ses propres désirs et valeurs.\n\n||||PAGE||||CHAPITRE V — Les Implications\n\nSi nous acceptons que le libre arbitre est une construction utile, quelles conséquences pour notre société ?"],
    ['id'=>3,'titre'=>'Forêts Oubliées','auteur'=>'Amara Diallo','prix'=>0,'note_moyenne'=>4.5,'nb_ventes'=>4560,'genre'=>'Nature','genre_icone'=>'🌿','pages'=>198,'contenu_extrait'=>"CHAPITRE I — Les Murmures\n\nLes arbres murmuraient des secrets anciens. Amara marchait depuis l'aube dans cette forêt primaire.\n\n||||PAGE||||CHAPITRE II — L'Écosystème\n\nLa forêt africaine est un monde à part entière. Chaque arbre abrite des dizaines d'espèces.\n\n||||PAGE||||CHAPITRE III — Les Gardiens\n\nAu cœur de la forêt vivent les Gardiens. Leur connaissance est encyclopédique.\n\n||||PAGE||||CHAPITRE IV — L'Urgence\n\nMais la déforestation avance. Chaque année, des millions d'hectares disparaissent.\n\n||||PAGE||||CHAPITRE V — L'Espoir\n\nIl est encore temps d'agir. Des initiatives locales montrent que reforestation et développement ne sont pas incompatibles."],
    ['id'=>4,'titre'=>'IA & Humanité','auteur'=>'Dr. Kai Tanaka','prix'=>6800,'note_moyenne'=>4.8,'nb_ventes'=>9874,'genre'=>'Technologie','genre_icone'=>'⚙️','pages'=>412,'contenu_extrait'=>"CHAPITRE I — Les Machines Pensantes\n\nLes machines pensent. La question n'est plus de savoir si elles le peuvent, mais ce que cela signifie pour nous.\n\n||||PAGE||||CHAPITRE II — L'Apprentissage Profond\n\nLe deep learning a révolutionné le domaine. Des réseaux capables de reconnaître des images, comprendre le langage.\n\n||||PAGE||||CHAPITRE III — Les Risques Réels\n\nBiais algorithmiques, surveillance de masse : les risques sont réels et documentés.\n\n||||PAGE||||CHAPITRE IV — La Régulation\n\nL'Union Européenne a adopté le premier cadre légal mondial sur l'IA.\n\n||||PAGE||||CHAPITRE V — La Coexistence\n\nTanaka plaide pour une coexistence harmonieuse. L'IA comme outil au service de l'humanité."],
    ['id'=>5,'titre'=>'Empires Disparus','auteur'=>'Sofia Mercier','prix'=>2800,'note_moyenne'=>4.5,'nb_ventes'=>3210,'genre'=>'Histoire','genre_icone'=>'📜','pages'=>534,'contenu_extrait'=>"CHAPITRE I — Rome Éternelle\n\nComment l'empire le plus puissant de l'Antiquité s'est-il effondré ?\n\n||||PAGE||||CHAPITRE II — La Perse\n\nL'empire perse s'étendait de l'Inde à l'Égypte. Sa chute face à Alexandre reste un tournant spectaculaire.\n\n||||PAGE||||CHAPITRE III — Les Mongols\n\nGengis Khan construisit l'empire le plus vaste de l'histoire.\n\n||||PAGE||||CHAPITRE IV — L'Ottoman\n\nL'empire ottoman dura six siècles. Sa disparition remodela le monde arabe pour toujours.\n\n||||PAGE||||CHAPITRE V — Leçons\n\nTous ces empires partagent des points communs dans leur déclin : surextension, corruption, perte de légitimité."],
    ['id'=>6,'titre'=>'Masques & Miroirs','auteur'=>'Léon Beaumont','prix'=>0,'note_moyenne'=>4.3,'nb_ventes'=>2100,'genre'=>'Littérature','genre_icone'=>'🎭','pages'=>276,'contenu_extrait'=>"CHAPITRE I — Le Personnage\n\nQui suis-je derrière le masque ? Cette question hantait Mathieu depuis toujours.\n\n||||PAGE||||CHAPITRE II — Les Doubles\n\nDans ce roman aux multiples miroirs, chaque personnage cache une autre identité.\n\n||||PAGE||||CHAPITRE III — La Spirale\n\nMathieu descend dans une spirale d'auto-analyse qui menace de l'engloutir.\n\n||||PAGE||||CHAPITRE IV — La Révélation\n\nL'identité n'est pas un donné mais une construction perpétuelle.\n\n||||PAGE||||CHAPITRE V — La Renaissance\n\nAccepter tous ses visages. Choisir lequel montrer et à qui. C'est peut-être cela, la vraie liberté."],
];

if (empty($recs))     $recs     = array_merge($demoRecs, $demoRecs, $demoRecs, $demoRecs);
if (empty($trending)) $trending = $demoRecs;
if ($totalLivres === 0) $totalLivres = '12 000+';

$coverColors = [['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a']];
$emojis = ['🌌','🧠','🌿','⚙️','📜','🎭','🔮','💡','🌊','🏔️','🦋','⚡'];

function getPriceCat(float $note, float $prix): array {
    if ($prix == 0 || $note <= 2.0) return ['label'=>'GRATUIT','class'=>'badge-free','display'=>'Gratuit'];
    if ($note <= 3.5) return ['label'=>'STANDARD','class'=>'badge-std','display'=>number_format($prix,0,'.',' ').' FCFA'];
    return ['label'=>'PREMIUM','class'=>'badge-premium','display'=>number_format($prix,0,'.',' ').' FCFA'];
}

$reasons = [
    'Correspond à votre profil IA','Tendance dans votre réseau',
    'Auteur similaire à vos favoris','Score de compatibilité élevé',
    'Nouveau dans votre genre préféré','Très bien noté par vos pairs',
    'Recommandé par l\'algorithme','Dans le top 1% de votre genre',
];

$prefs = [
    ['label'=>'Science-Fiction','pct'=>78,'color'=>'#4a9eff'],
    ['label'=>'Philosophie','pct'=>65,'color'=>'#9b59b6'],
    ['label'=>'Histoire','pct'=>52,'color'=>'#e8c97d'],
    ['label'=>'Technologie','pct'=>48,'color'=>'#ff6b35'],
    ['label'=>'Nature','pct'=>35,'color'=>'#4ecca3'],
    ['label'=>'Littérature','pct'=>28,'color'=>'#e84393'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Recommandations IA — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ═══════════════════════════════════════════════
   RESET & TOKENS — identiques index.php
═══════════════════════════════════════════════ */
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
body{font-family:'Cabinet Grotesk',sans-serif;background:var(--ink);color:var(--txt-primary);overflow-x:hidden}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-track{background:var(--ink)}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* NOISE TEXTURE */
body::before{content:'';position:fixed;inset:0;z-index:-1;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");opacity:0.4;pointer-events:none}

/* ORBS */
.bg-orb{position:fixed;border-radius:50%;filter:blur(110px);pointer-events:none;z-index:-2;animation:orbDrift 26s ease-in-out infinite}
.orb-a{width:600px;height:600px;background:rgba(155,89,182,0.07);top:-200px;right:-100px}
.orb-b{width:500px;height:500px;background:rgba(74,158,255,0.05);bottom:-150px;left:-80px;animation-delay:-13s}
.orb-c{width:350px;height:350px;background:rgba(232,201,125,0.04);top:45%;left:40%;animation-delay:-7s}
@keyframes orbDrift{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(40px,-35px) scale(1.06)}66%{transform:translate(-30px,40px) scale(0.94)}}

/* GRID DOTS */
.bg-dots{position:fixed;inset:0;z-index:-1;pointer-events:none;background-image:radial-gradient(circle,rgba(155,89,182,0.15) 1px,transparent 1px);background-size:36px 36px;mask-image:radial-gradient(ellipse 70% 70% at 50% 50%,black 10%,transparent 80%);opacity:.3}

/* ═══════════════════════════════════════════════
   HEADER
═══════════════════════════════════════════════ */
#site-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:62px;padding:0 2rem;display:flex;align-items:center;justify-content:space-between;background:rgba(7,11,20,0.82);backdrop-filter:blur(24px) saturate(1.4);border-bottom:1px solid var(--glass-border);transition:background .3s}
#site-header.scrolled{background:rgba(7,11,20,0.98)}
.nav-logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--txt-primary);font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600;letter-spacing:-.5px}
.logo-mark{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));display:flex;align-items:center;justify-content:center;font-size:.9rem;box-shadow:0 0 20px rgba(232,201,125,.3)}
.nav-links{display:flex;align-items:center;gap:2px;list-style:none}
.nav-links a{font-size:.8rem;font-weight:500;color:var(--txt-secondary);text-decoration:none;padding:6px 14px;border-radius:8px;transition:all .2s}
.nav-links a:hover,.nav-links a.active{color:var(--gold);background:var(--mist)}
.nav-actions{display:flex;align-items:center;gap:.6rem}
.btn-ghost{font-size:.78rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;padding:7px 16px;border-radius:8px;border:1px solid var(--glass-border);transition:all .2s}
.btn-ghost:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.btn-cta-nav{font-size:.78rem;font-weight:700;color:var(--ink);text-decoration:none;padding:7px 18px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));box-shadow:0 4px 16px rgba(232,201,125,.2);transition:all .2s}
.btn-cta-nav:hover{opacity:.88;transform:translateY(-1px)}
.user-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 5px;background:var(--mist);border:1px solid var(--glass-border);border-radius:100px;text-decoration:none;color:var(--txt-primary);font-size:.78rem;font-weight:600;transition:all .2s}
.user-avatar{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--plum),var(--azure));display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:#fff}
.admin-badge{display:inline-flex;align-items:center;gap:4px;font-size:.62rem;padding:3px 10px;border-radius:100px;background:rgba(232,201,125,.1);color:var(--gold);border:1px solid rgba(232,201,125,.25);font-family:'JetBrains Mono',monospace}
#hamburger{display:none;background:none;border:none;color:var(--txt-primary);font-size:1.3rem;cursor:pointer}
#mobile-nav{display:none;position:fixed;inset:0;top:62px;background:rgba(7,11,20,.98);backdrop-filter:blur(24px);z-index:998;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem}
#mobile-nav.open{display:flex}
#mobile-nav a{font-family:'Clash Display',sans-serif;font-size:1.6rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;transition:color .2s}
#mobile-nav a:hover{color:var(--gold)}
@media(max-width:900px){.nav-links,.nav-actions{display:none}#hamburger{display:block}}

/* ═══════════════════════════════════════════════
   PAGE HERO — style index.php
═══════════════════════════════════════════════ */
.page-hero{padding:100px 2rem 4rem;background:linear-gradient(180deg,rgba(155,89,182,0.08) 0%,transparent 100%);border-bottom:1px solid var(--glass-border);text-align:center}
.hero-label{display:inline-flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--plum);letter-spacing:.12em;border:1px solid rgba(155,89,182,.28);background:rgba(155,89,182,.06);padding:6px 16px;border-radius:100px;margin-bottom:1.6rem;animation:pulseBorder 3s ease-in-out infinite}
@keyframes pulseBorder{0%,100%{box-shadow:none}50%{box-shadow:0 0 20px rgba(155,89,182,.12)}}
.pulse-dot{width:6px;height:6px;background:var(--plum);border-radius:50%;animation:blink 1.4s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.1}}
.page-hero h1{font-family:'Clash Display',sans-serif;font-size:clamp(2rem,5vw,3.8rem);font-weight:700;letter-spacing:-2px;line-height:1.05;margin-bottom:1rem}
.page-hero h1 .grad{background:linear-gradient(135deg,var(--plum),var(--azure));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.page-hero p{font-size:.98rem;color:var(--txt-secondary);max-width:560px;margin:0 auto 2.5rem;line-height:1.8}
.hero-metrics{display:flex;gap:2.5rem;justify-content:center;flex-wrap:wrap}
.metric{text-align:center}
.metric-val{font-family:'Clash Display',sans-serif;font-size:1.9rem;font-weight:700;background:linear-gradient(135deg,var(--plum),var(--azure));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-1px}
.metric-label{font-size:.7rem;color:var(--txt-muted);margin-top:4px;letter-spacing:.08em;text-transform:uppercase}
.metric-sep{width:1px;background:var(--glass-border);align-self:stretch}

/* MARQUEE */
.marquee-strip{overflow:hidden;border-top:1px solid var(--glass-border);border-bottom:1px solid var(--glass-border);padding:12px 0;background:rgba(155,89,182,.03)}
.marquee-track{display:flex;gap:2.5rem;animation:marqueeScroll 28s linear infinite;white-space:nowrap}
.marquee-track span{font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--txt-muted);letter-spacing:.08em;display:flex;align-items:center;gap:7px}
.marquee-track span::before{content:'◆';color:var(--plum);font-size:.45rem}
@keyframes marqueeScroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}

/* MAIN */
main{max-width:1280px;margin:0 auto;padding:3rem 2rem 8rem}

/* ═══════════════════════════════════════════════
   SECTION EYEBROW
═══════════════════════════════════════════════ */
.section-eyebrow{font-family:'JetBrains Mono',monospace;font-size:.65rem;letter-spacing:.15em;color:var(--gold);text-transform:uppercase;margin-bottom:.6rem;display:flex;align-items:center;gap:8px}
.section-eyebrow::before{content:'';width:16px;height:1px;background:var(--gold)}
.section-title{font-family:'Clash Display',sans-serif;font-size:clamp(1.4rem,3vw,2rem);font-weight:700;letter-spacing:-1px;margin-bottom:.4rem}
.section-sub{font-size:.83rem;color:var(--txt-secondary);margin-bottom:2rem;line-height:1.75}

/* ═══════════════════════════════════════════════
   AI HERO GRID
═══════════════════════════════════════════════ */
.ai-hero{display:grid;grid-template-columns:1fr 400px;gap:2.5rem;align-items:start;margin-bottom:4rem}
@media(max-width:960px){.ai-hero{grid-template-columns:1fr}}

/* Profile card */
.ai-profile-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:18px;padding:1.6rem;margin-bottom:1.4rem;position:relative;overflow:hidden}
.ai-profile-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--plum),var(--azure),var(--plum));background-size:300%;animation:barShimmer 4s linear infinite}
@keyframes barShimmer{to{background-position:300% center}}
.ai-profile-title{font-family:'JetBrains Mono',monospace;font-size:.63rem;color:var(--sage);letter-spacing:.1em;text-transform:uppercase;margin-bottom:1rem}
.taste-tags{display:flex;flex-wrap:wrap;gap:.5rem}
.taste-tag{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:100px;font-size:.72rem;font-weight:600;border:1px solid;cursor:pointer;transition:all .2s;user-select:none}
.taste-tag:hover{transform:scale(1.05)}
.taste-tag.inactive{opacity:.3}
.tt-sf{background:rgba(74,158,255,.1);border-color:rgba(74,158,255,.3);color:var(--azure)}
.tt-ph{background:rgba(155,89,182,.1);border-color:rgba(155,89,182,.3);color:var(--plum)}
.tt-na{background:rgba(78,204,163,.1);border-color:rgba(78,204,163,.3);color:var(--sage)}
.tt-hi{background:rgba(232,201,125,.1);border-color:rgba(232,201,125,.3);color:var(--gold)}
.tt-te{background:rgba(255,107,53,.1);border-color:rgba(255,107,53,.3);color:var(--ember)}
.tt-li{background:rgba(232,67,147,.1);border-color:rgba(232,67,147,.3);color:#e84393}

/* Refresh button — style index.php primary */
.btn-refresh{display:inline-flex;align-items:center;gap:9px;padding:13px 26px;border-radius:12px;border:none;background:linear-gradient(135deg,var(--gold) 0%,var(--ember) 100%);color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;box-shadow:0 8px 28px rgba(232,201,125,.22);transition:transform .2s var(--ease-spring),box-shadow .2s,opacity .2s;position:relative;overflow:hidden}
.btn-refresh::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.14),transparent);opacity:0;transition:opacity .2s}
.btn-refresh:hover{transform:translateY(-2px);box-shadow:0 16px 40px rgba(232,201,125,.32)}
.btn-refresh:hover::before{opacity:1}
.btn-refresh i{transition:transform .4s}
.btn-refresh.spinning i{animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-refresh::after{content:'';position:absolute;top:0;left:-100%;width:60%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.14),transparent);animation:btnShimmer 2.8s ease-in-out infinite}
@keyframes btnShimmer{0%{left:-100%}100%{left:200%}}

/* ═══════════════════════════════════════════════
   AI TERMINAL — style index.php
═══════════════════════════════════════════════ */
.ai-terminal{background:var(--slate);border:1px solid var(--glass-border);border-radius:20px;overflow:hidden;box-shadow:0 40px 100px rgba(0,0,0,.45)}
.ai-topbar{padding:13px 16px;background:rgba(0,0,0,.3);border-bottom:1px solid var(--glass-border);display:flex;align-items:center;gap:7px}
.dot{width:10px;height:10px;border-radius:50%}
.dot-r{background:#ff5f57}.dot-y{background:#ffbd2e}.dot-g{background:#28ca41;animation:blink 2s infinite}
.ai-tb-label{font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--txt-muted);margin-left:8px}
.ai-body{padding:1.5rem}
.ai-prompt-line{font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--sage);margin-bottom:.8rem}
.ai-output-text{font-family:'Cabinet Grotesk',sans-serif;font-size:1rem;font-weight:500;color:var(--txt-primary);min-height:2.5rem;line-height:1.7}
.type-cursor{display:inline-block;width:2px;height:1em;background:var(--gold);vertical-align:middle;margin-left:2px;animation:blink .75s step-end infinite}
.ai-stats-row{display:flex;gap:1.5rem;padding:1rem 1.5rem;border-top:1px solid var(--glass-border);background:rgba(0,0,0,.2);flex-wrap:wrap}
.ai-stat .ai-stat-lbl{font-family:'JetBrains Mono',monospace;font-size:.58rem;color:var(--txt-muted);text-transform:uppercase;letter-spacing:.1em}
.ai-stat .ai-stat-val{font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;color:var(--gold);margin-top:2px}
.ai-chat-row{display:flex;gap:8px;padding:1rem 1.5rem;border-top:1px solid var(--glass-border)}
.ai-input{flex:1;background:var(--fog);border:1px solid var(--glass-border);border-radius:10px;padding:10px 14px;font-family:'Cabinet Grotesk',sans-serif;font-size:.83rem;color:var(--txt-primary);outline:none;transition:border-color .2s}
.ai-input:focus{border-color:rgba(232,201,125,.35)}
.ai-input::placeholder{color:var(--txt-muted)}
.ai-send-btn{padding:10px 18px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-size:.82rem;font-weight:700;cursor:pointer;transition:opacity .2s,transform .2s;font-family:'Cabinet Grotesk',sans-serif}
.ai-send-btn:hover{opacity:.88;transform:translateY(-1px)}

/* ═══════════════════════════════════════════════
   RECS GRID
═══════════════════════════════════════════════ */
.recs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1.2rem;margin-bottom:3rem}
.rec-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:16px;overflow:hidden;transition:all .3s var(--ease-spring);cursor:pointer;position:relative}
.rec-card:hover{transform:translateY(-8px) scale(1.01);border-color:rgba(232,201,125,.3);box-shadow:0 24px 60px rgba(0,0,0,.5),0 0 30px rgba(232,201,125,.06)}
.rec-cover{height:165px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.rec-cover-bg{position:absolute;inset:0}
.rec-emoji{font-size:3.2rem;position:relative;z-index:1;filter:drop-shadow(0 4px 12px rgba(0,0,0,.5));transition:transform .3s var(--ease-spring)}
.rec-card:hover .rec-emoji{transform:scale(1.12) rotate(-4deg)}
.rec-overlay{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(7,11,20,.85));z-index:2}
.rec-match{position:absolute;top:8px;left:8px;z-index:3;font-family:'JetBrains Mono',monospace;font-size:.58rem;padding:3px 9px;border-radius:6px;background:rgba(232,201,125,.85);color:var(--ink);font-weight:700}
.price-badge{position:absolute;top:8px;right:8px;z-index:3;font-family:'JetBrains Mono',monospace;font-size:.6rem;padding:3px 8px;border-radius:6px}
.badge-free{background:rgba(78,204,163,.15);color:var(--sage);border:1px solid rgba(78,204,163,.3)}
.badge-std{background:rgba(74,158,255,.15);color:var(--azure);border:1px solid rgba(74,158,255,.3)}
.badge-premium{background:rgba(232,201,125,.15);color:var(--gold);border:1px solid rgba(232,201,125,.3)}
.rec-body{padding:1rem}
.reason-badge{display:inline-flex;align-items:center;gap:4px;font-family:'JetBrains Mono',monospace;font-size:.57rem;color:var(--gold);background:rgba(232,201,125,.08);border:1px solid rgba(232,201,125,.18);padding:2px 8px;border-radius:4px;margin-bottom:.5rem}
.rec-title{font-family:'Clash Display',sans-serif;font-size:.88rem;font-weight:600;letter-spacing:-.3px;line-height:1.25;margin-bottom:2px}
.rec-author{font-size:.7rem;color:var(--txt-secondary);margin-bottom:.5rem}
.rec-rating{display:flex;align-items:center;gap:5px;margin-bottom:.7rem}
.rec-stars{color:var(--gold);font-size:.62rem;letter-spacing:-1px}
.rec-price{font-family:'Clash Display',sans-serif;font-size:.82rem;font-weight:700;color:var(--gold);margin-bottom:.7rem}
.rec-price.free{color:var(--sage)}
.rec-btn{width:100%;padding:9px;border-radius:9px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-size:.73rem;font-weight:700;cursor:pointer;font-family:'Cabinet Grotesk',sans-serif;transition:opacity .2s,transform .2s}
.rec-btn:hover{opacity:.88;transform:translateY(-1px)}

/* HORIZONTAL SCROLL */
.rec-scroll-wrap{overflow-x:auto;padding-bottom:1rem;scroll-snap-type:x mandatory}
.rec-scroll-wrap::-webkit-scrollbar{height:3px}
.rec-scroll-wrap::-webkit-scrollbar-thumb{background:rgba(232,201,125,.35);border-radius:3px}
.rec-scroll{display:flex;gap:1rem}
.rec-scroll .rec-card{flex-shrink:0;width:195px;scroll-snap-align:start}

/* BECAUSE CARDS */
.because-list{display:flex;flex-direction:column;gap:1rem;margin-bottom:3rem}
.because-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:18px;padding:1.3rem;display:grid;grid-template-columns:80px 1fr;gap:1.2rem;align-items:center;cursor:pointer;transition:all .25s var(--ease-spring)}
.because-card:hover{border-color:rgba(232,201,125,.25);transform:translateX(5px);box-shadow:0 12px 40px rgba(0,0,0,.4)}
.because-thumb{width:80px;height:100px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:2.2rem;flex-shrink:0}
.because-label{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--gold);text-transform:uppercase;margin-bottom:4px;letter-spacing:.08em}
.because-trigger{font-size:.78rem;color:var(--txt-secondary);margin-bottom:.5rem}
.because-title{font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600;letter-spacing:-.3px;margin-bottom:2px}
.because-author{font-size:.72rem;color:var(--txt-secondary)}
.because-meta{display:flex;gap:1rem;margin-top:.5rem;font-size:.7rem}
.because-score{color:var(--gold)}.because-price{font-family:'JetBrains Mono',monospace;color:var(--sage)}

/* PREF CHART */
.pref-chart{background:var(--glass);border:1px solid var(--glass-border);border-radius:20px;padding:1.8rem;margin-bottom:3rem;position:relative;overflow:hidden}
.pref-chart::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--ember),var(--sage));background-size:200%;animation:barShimmer 4s linear infinite}
.pref-title{font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:600;margin-bottom:1.5rem;letter-spacing:-.3px}
.pref-bar-row{display:flex;align-items:center;gap:1rem;margin-bottom:.9rem}
.pref-label{font-size:.75rem;color:var(--txt-secondary);min-width:110px}
.pref-bar-wrap{flex:1;height:6px;background:var(--mist);border-radius:4px;overflow:hidden}
.pref-bar-fill{height:100%;border-radius:4px;width:0;transition:width 1.5s var(--ease-smooth)}
.pref-pct{font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--txt-muted);min-width:36px;text-align:right}

/* INSIGHTS GRID */
.insights-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:3rem}
.insight-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:16px;padding:1.5rem;transition:all .25s var(--ease-spring)}
.insight-card:hover{border-color:rgba(232,201,125,.2);transform:translateY(-4px);box-shadow:0 16px 40px rgba(0,0,0,.4)}
.insight-icon{font-size:2rem;margin-bottom:.8rem}
.insight-title{font-family:'Clash Display',sans-serif;font-size:.95rem;font-weight:600;margin-bottom:.4rem;letter-spacing:-.2px}
.insight-desc{font-size:.78rem;color:var(--txt-secondary);line-height:1.7}
.insight-val{font-family:'Clash Display',sans-serif;font-size:1.9rem;font-weight:700;background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-top:.5rem;letter-spacing:-1px}

/* REVEAL */
.reveal{opacity:0;transform:translateY(22px);transition:opacity .6s var(--ease-smooth),transform .6s var(--ease-smooth)}
.reveal.visible{opacity:1;transform:none}
.reveal-delay-1{transition-delay:.1s}.reveal-delay-2{transition-delay:.2s}.reveal-delay-3{transition-delay:.3s}

/* ═══════════════════════════════════════════════
   TOAST
═══════════════════════════════════════════════ */
#toast{position:fixed;bottom:2rem;right:2rem;z-index:9800;background:rgba(13,18,32,.97);border:1px solid rgba(232,201,125,.2);border-radius:14px;padding:1rem 1.3rem;display:flex;align-items:center;gap:12px;font-size:.8rem;backdrop-filter:blur(20px);box-shadow:0 0 40px rgba(232,201,125,.08),0 12px 32px rgba(0,0,0,.5);transform:translateY(100px) scale(.96);opacity:0;transition:all .4s var(--ease-spring);pointer-events:none;max-width:320px}
#toast.show{transform:translateY(0) scale(1);opacity:1;pointer-events:auto}
#toast.t-success{border-color:rgba(78,204,163,.3)}
#toast.t-error{border-color:rgba(255,95,87,.3)}
#toast.t-warn{border-color:rgba(255,189,46,.3)}
.toast-icon{font-size:1.1rem;flex-shrink:0}
.toast-text{display:flex;flex-direction:column;gap:2px}
.toast-ttl{font-family:'Clash Display',sans-serif;font-weight:600;font-size:.82rem}
.toast-msg{font-size:.72rem;color:var(--txt-muted)}

/* ═══════════════════════════════════════════════
   AUTH GATE
═══════════════════════════════════════════════ */
#auth-gate{position:fixed;inset:0;z-index:9500;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
#auth-gate.open{opacity:1;visibility:visible}
.gate-backdrop{position:absolute;inset:0;background:rgba(7,11,20,.88);backdrop-filter:blur(12px)}
.gate-box{position:relative;z-index:1;max-width:420px;width:100%;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:24px;padding:2.5rem;text-align:center;box-shadow:0 60px 120px rgba(0,0,0,.6);transform:translateY(20px) scale(.97);transition:transform .4s var(--ease-spring)}
#auth-gate.open .gate-box{transform:none}
.gate-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--ember));border-radius:24px 24px 0 0}
.gate-icon{font-size:2.5rem;margin-bottom:1rem}
.gate-eyebrow{font-family:'JetBrains Mono',monospace;font-size:.65rem;letter-spacing:.12em;color:var(--gold);text-transform:uppercase;margin-bottom:.6rem}
.gate-title{font-family:'Clash Display',sans-serif;font-size:1.5rem;font-weight:700;letter-spacing:-.5px;margin-bottom:.7rem}
.gate-desc{font-size:.82rem;color:var(--txt-secondary);line-height:1.7;margin-bottom:1.8rem}
.gate-btns{display:flex;gap:.7rem}
.btn-gate-solid{flex:1;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:opacity .2s,transform .2s}
.btn-gate-solid:hover{opacity:.88;transform:translateY(-2px)}
.btn-gate-outline{flex:1;padding:12px;border-radius:10px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-family:'Clash Display',sans-serif;font-size:.88rem;font-weight:600;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s}
.btn-gate-outline:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.gate-close-btn{position:absolute;top:1rem;right:1rem;width:28px;height:28px;border-radius:7px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.8rem;transition:all .2s}
.gate-close-btn:hover{color:#ff5f57;border-color:rgba(255,95,87,.3)}
.gate-countdown{margin-top:1rem;font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--txt-muted)}
.gate-progress{width:100%;height:2px;background:var(--glass-border);border-radius:2px;overflow:hidden;margin-top:.5rem}
.gate-progress-fill{height:100%;width:100%;background:var(--gold);transform-origin:left;transition:transform 5s linear}

/* ═══════════════════════════════════════════════
   PAY MODAL — identique index.php
═══════════════════════════════════════════════ */
#pay-modal{position:fixed;inset:0;z-index:9700;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
#pay-modal.open{opacity:1;visibility:visible}
.pay-backdrop{position:absolute;inset:0;background:rgba(7,11,20,.88);backdrop-filter:blur(16px)}
.pay-box{position:relative;z-index:1;width:100%;max-width:500px;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:24px;overflow:hidden;overflow-y:auto;max-height:90vh;box-shadow:0 50px 100px rgba(0,0,0,.6);transform:translateY(20px) scale(.97);transition:transform .4s var(--ease-spring)}
#pay-modal.open .pay-box{transform:none}
.pay-bar{height:2px;background:linear-gradient(90deg,var(--gold),var(--ember),var(--sage),var(--gold));background-size:300%;animation:barShimmer 3s linear infinite}
.pay-header{padding:1.8rem 1.8rem 1.2rem;border-bottom:1px solid var(--glass-border)}
.pay-book-row{display:flex;align-items:center;gap:14px;margin-bottom:1.2rem}
.pay-book-thumb{width:54px;height:54px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.8rem;background:var(--mist);border:1px solid var(--glass-border)}
.pay-book-title{font-family:'Clash Display',sans-serif;font-size:.95rem;font-weight:600}
.pay-book-author{font-size:.75rem;color:var(--txt-secondary);margin-top:2px}
.pay-amount-row{display:flex;align-items:baseline;gap:6px}
.pay-amount-label{font-size:.75rem;color:var(--txt-muted)}
.pay-amount-val{font-family:'Clash Display',sans-serif;font-size:1.8rem;font-weight:700;color:var(--gold);letter-spacing:-1px}
.pay-amount-curr{font-size:.85rem;color:var(--txt-muted)}
.pay-body{padding:1.5rem 1.8rem}
.pay-steps{display:flex;align-items:center;gap:6px;margin-bottom:1.8rem}
.step-dot{width:8px;height:8px;border-radius:50%;background:var(--glass-border);transition:all .3s}
.step-dot.active{background:var(--gold);transform:scale(1.4)}
.step-dot.done{background:var(--sage)}
.step-line{flex:1;height:1px;background:var(--glass-border);transition:background .3s}
.step-line.done{background:var(--sage)}
.step-label{font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--txt-muted);margin-left:auto}
.method-title{font-size:.78rem;color:var(--txt-secondary);margin-bottom:1rem;font-weight:500}
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
.btn-pay{width:100%;padding:14px;border-radius:12px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;transition:opacity .2s,transform .2s;margin-top:1rem;box-shadow:0 8px 24px rgba(232,201,125,.2)}
.btn-pay:hover:not(:disabled){opacity:.88;transform:translateY(-2px)}
.btn-pay:disabled{opacity:.4;cursor:not-allowed}
.admin-notice{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:10px;background:rgba(232,201,125,.05);border:1px solid rgba(232,201,125,.2);font-size:.78rem;color:var(--txt-secondary);margin-bottom:1.2rem}
.admin-notice i{color:var(--gold);flex-shrink:0;margin-top:2px}
.process-spinner{width:60px;height:60px;border-radius:50%;border:3px solid var(--glass-border);border-top-color:var(--gold);margin:0 auto 1.2rem;animation:spin .8s linear infinite}
.proc-step{display:flex;align-items:center;gap:10px;font-size:.78rem;color:var(--txt-muted);padding:6px 0;transition:color .3s}
.proc-step.done{color:var(--sage)}.proc-step.active{color:var(--gold)}
.proc-icon{width:18px;height:18px;border-radius:50%;border:1px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:.55rem;flex-shrink:0}
.proc-step.done .proc-icon{background:var(--sage);border-color:var(--sage);color:#fff}
.success-ring{width:80px;height:80px;border-radius:50%;background:rgba(78,204,163,.1);border:2px solid var(--sage);display:flex;align-items:center;justify-content:center;font-size:2.5rem;margin:0 auto 1.2rem;animation:pop .5s var(--ease-spring);box-shadow:0 0 40px rgba(78,204,163,.2)}
@keyframes pop{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
.success-title{font-family:'Clash Display',sans-serif;font-size:1.4rem;font-weight:700;color:var(--sage);margin-bottom:.4rem}
.success-ref{font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--txt-muted);margin:1rem 0;padding:8px 16px;background:var(--fog);border-radius:8px;border:1px solid var(--glass-border)}
.pay-close{position:absolute;top:1rem;right:1rem;width:30px;height:30px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;font-size:.85rem;z-index:10}
.pay-close:hover{border-color:rgba(255,95,87,.3);color:#ff5f57}
.pay-security{display:flex;align-items:center;justify-content:center;gap:1.5rem;padding:1rem 1.8rem;border-top:1px solid var(--glass-border);background:rgba(0,0,0,.2)}
.sec-badge{display:flex;align-items:center;gap:5px;font-size:.65rem;color:var(--txt-muted)}
.sec-badge i{color:var(--sage)}
.btn-back-step{background:none;border:none;color:var(--txt-muted);font-size:.75rem;cursor:pointer;text-decoration:underline;display:block;margin:.5rem auto 0;transition:color .2s}
.btn-back-step:hover{color:var(--txt-primary)}

/* ═══════════════════════════════════════════════
   READER MODAL
═══════════════════════════════════════════════ */
#reader-modal{position:fixed;inset:0;z-index:9600;opacity:0;visibility:hidden;transition:opacity .4s,visibility .4s;background:#0e0d0b;display:flex;flex-direction:column}
#reader-modal.open{opacity:1;visibility:visible}
.reader-header{height:54px;display:flex;align-items:center;justify-content:space-between;padding:0 1.8rem;border-bottom:1px solid rgba(255,255,255,.06);background:rgba(0,0,0,.4);backdrop-filter:blur(10px);flex-shrink:0}
.reader-title-wrap{display:flex;align-items:center;gap:10px;min-width:0;flex:1}
.reader-title-el{font-family:'Clash Display',sans-serif;font-size:.9rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.reader-controls{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.reader-btn{width:32px;height:32px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;font-size:.85rem}
.reader-btn:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.reader-close-btn{border-color:rgba(255,95,87,.3);color:#ff5f57}
.reader-close-btn:hover{background:rgba(255,95,87,.1)}
.reader-body{flex:1;overflow-y:auto}
.reader-inner{max-width:680px;margin:0 auto;padding:3rem 2rem 6rem}
.reader-content{font-family:'Georgia',serif;font-size:1.05rem;line-height:1.95;color:#e8e4da}
.reader-content h2{font-family:'Clash Display',sans-serif;font-size:1.35rem;font-weight:700;margin:2.5rem 0 1.2rem;color:#f0eeea;border-bottom:1px solid rgba(255,255,255,.06);padding-bottom:.6rem;letter-spacing:-.5px}
.reader-content p{margin-bottom:1.3rem;text-indent:1.5em}
.reader-content p:first-of-type{text-indent:0}
.reader-progress-bar{height:3px;background:rgba(255,255,255,.06);flex-shrink:0}
.reader-progress-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--ember));transition:width .5s}
.reader-nav{display:flex;align-items:center;justify-content:center;gap:1rem;background:rgba(14,13,11,.95);border-top:1px solid rgba(255,255,255,.06);padding:12px 2rem;backdrop-filter:blur(20px);flex-shrink:0}
.reader-nav-btn{background:none;border:1px solid var(--glass-border);border-radius:8px;color:var(--txt-muted);cursor:pointer;padding:7px 14px;display:flex;align-items:center;gap:4px;font-size:.78rem;font-family:'Cabinet Grotesk',sans-serif;transition:all .2s}
.reader-nav-btn:hover:not(:disabled){border-color:rgba(232,201,125,.35);color:var(--gold)}
.reader-nav-btn:disabled{opacity:.3;cursor:not-allowed}
.reader-page-info{font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--txt-muted);min-width:80px;text-align:center}
#reader-modal.reader-light{background:#f5f0e8}
#reader-modal.reader-light .reader-content{color:#2c2a24}
#reader-modal.reader-light .reader-content h2{color:#1a1814;border-color:rgba(0,0,0,.08)}
#reader-modal.reader-light .reader-header,#reader-modal.reader-light .reader-nav{background:rgba(245,240,232,.95);border-color:rgba(0,0,0,.08)}

/* FOOTER */
footer{border-top:1px solid var(--glass-border);padding:2.5rem 2rem;background:rgba(0,0,0,.2)}
.footer-inner{max-width:1280px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.footer-links{display:flex;gap:1.2rem;flex-wrap:wrap}
.footer-links a{font-size:.78rem;color:var(--txt-muted);text-decoration:none;transition:color .2s}
.footer-links a:hover{color:var(--gold)}
.footer-copy{font-size:.7rem;color:var(--txt-muted)}
</style>
</head>
<body>
<div class="bg-orb orb-a"></div>
<div class="bg-orb orb-b"></div>
<div class="bg-orb orb-c"></div>
<div class="bg-dots"></div>

<!-- ═══════════ HEADER ═══════════ -->
<header id="site-header">
  <a href="index.php" class="nav-logo">
    <div class="logo-mark">📚</div>
    Digital <span style="color:var(--gold)">Library</span>
  </a>
  <nav><ul class="nav-links">
    <li><a href="index.php">Accueil</a></li>
    <li><a href="explorer.php">Explorer</a></li>
    <li><a href="categories.php">Catégories</a></li>
    <li><a href="tendances.php">Tendances</a></li>
    <li><a href="recommandations-ia.php" class="active">Recommandations IA</a></li>
  </ul></nav>
  <div class="nav-actions">
    <?php if ($isLoggedIn): ?>
      <?php if ($isAdmin): ?><span class="admin-badge"><i class="bi bi-shield-fill"></i> Admin</span><?php endif; ?>
      <a href="dashboard.php" class="user-chip"><div class="user-avatar"><?= strtoupper(substr($username,0,2)) ?></div><?= $username ?></a>
      <a href="logout.php" class="btn-ghost">Déconnexion</a>
    <?php else: ?>
      <a href="login.php" class="btn-ghost">Connexion</a>
      <a href="register.php" class="btn-cta-nav">Commencer →</a>
    <?php endif; ?>
  </div>
  <button id="hamburger"><i class="bi bi-list"></i></button>
</header>
<nav id="mobile-nav">
  <a href="index.php">Accueil</a><a href="explorer.php">Explorer</a>
  <a href="categories.php">Catégories</a><a href="tendances.php">Tendances</a>
  <a href="recommandations-ia.php">Recommandations IA</a>
  <?php if ($isLoggedIn): ?><a href="logout.php" style="color:var(--ember)">Déconnexion</a>
  <?php else: ?><a href="login.php">Connexion</a><a href="register.php" style="color:var(--gold)">S'inscrire</a><?php endif; ?>
</nav>

<!-- ═══════════ PAGE HERO ═══════════ -->
<div class="page-hero">
  <div class="hero-label"><span class="pulse-dot"></span> Moteur IA — Recommandations personnalisées</div>
  <h1>Votre guide <span class="grad">littéraire</span> intelligent</h1>
  <p>Notre IA analyse vos préférences, historique de lecture et tendances pour vous proposer des recommandations ultra-personnalisées en temps réel.</p>
  <div class="hero-metrics">
    <div class="metric"><div class="metric-val">94.2%</div><div class="metric-label">Précision IA</div></div>
    <div class="metric-sep"></div>
    <div class="metric"><div class="metric-val"><?= is_int($totalLivres) ? number_format($totalLivres,0,',',' ') : $totalLivres ?></div><div class="metric-label">Livres analysés</div></div>
    <div class="metric-sep"></div>
    <div class="metric"><div class="metric-val"><?= count($recs) ?></div><div class="metric-label">Suggestions</div></div>
  </div>
</div>

<!-- MARQUEE -->
<div class="marquee-strip" aria-hidden="true">
  <div class="marquee-track">
    <?php $tags = ['🌌 Science-Fiction','🧠 Philosophie','🌿 Nature','⚙️ Technologie','📜 Histoire','🎭 Littérature','🔬 Sciences','🎨 Arts','🚀 Développement','📰 Journaux','✍️ Récits','📊 Économie']; foreach (array_merge($tags,$tags,$tags) as $t): ?><span><?= $t ?></span><?php endforeach; ?>
  </div>
</div>

<main>

  <!-- AI HERO GRID -->
  <div class="ai-hero reveal">
    <div>
      <!-- Profile card -->
      <div class="ai-profile-card">
        <div class="ai-profile-title">🎯 Vos centres d'intérêt détectés</div>
        <div class="taste-tags">
          <span class="taste-tag tt-sf" data-genre="sf">🌌 Science-Fiction</span>
          <span class="taste-tag tt-ph" data-genre="philo">🧠 Philosophie</span>
          <span class="taste-tag tt-na inactive" data-genre="nature">🌿 Nature</span>
          <span class="taste-tag tt-hi" data-genre="histoire">📜 Histoire</span>
          <span class="taste-tag tt-te inactive" data-genre="tech">⚙️ Technologie</span>
          <span class="taste-tag tt-li" data-genre="litt">🎭 Littérature</span>
        </div>
      </div>
      <p style="font-size:.83rem;color:var(--txt-secondary);margin-bottom:1.3rem;line-height:1.75">
        <?php if ($isLoggedIn): ?>
          Bonjour <strong style="color:var(--gold)"><?= $username ?></strong> !
          <?php if ($userStats['total_achats'] > 0): ?>
            Basé sur vos <strong><?= $userStats['total_achats'] ?></strong> lecture(s), voici vos recommandations personnalisées.
          <?php else: ?>
            Commencez à lire pour affiner vos recommandations. En attendant, voici nos meilleurs livres.
          <?php endif; ?>
        <?php else: ?>
          <a href="register.php" style="color:var(--gold);font-weight:700">Créez un compte</a> pour des recommandations entièrement personnalisées basées sur votre historique de lecture.
        <?php endif; ?>
      </p>
      <button class="btn-refresh" id="btn-refresh">
        <i class="bi bi-arrow-clockwise"></i> Actualiser les recommandations
      </button>
    </div>

    <!-- TERMINAL -->
    <div class="ai-terminal">
      <div class="ai-topbar">
        <div class="dot dot-r"></div><div class="dot dot-y"></div><div class="dot dot-g"></div>
        <span class="ai-tb-label">recommendation-engine v3.2 — active</span>
      </div>
      <div class="ai-body">
        <div class="ai-prompt-line">$ ia.analyser --user=<?= $isLoggedIn ? htmlspecialchars($username) : 'guest' ?> --mode=deep-learning</div>
        <div class="ai-output-text" id="ai-output"><span class="type-cursor"></span></div>
      </div>
      <div class="ai-stats-row">
        <div class="ai-stat"><div class="ai-stat-lbl">Précision</div><div class="ai-stat-val">94.2%</div></div>
        <div class="ai-stat"><div class="ai-stat-lbl">Catalogue</div><div class="ai-stat-val"><?= is_int($totalLivres) ? number_format($totalLivres,0,',',' ') : $totalLivres ?></div></div>
        <div class="ai-stat"><div class="ai-stat-lbl">Profils similaires</div><div class="ai-stat-val">340</div></div>
      </div>
      <div class="ai-chat-row">
        <input type="text" class="ai-input" id="ai-input" placeholder="Posez une question à l'IA…" maxlength="200">
        <button class="ai-send-btn" id="ai-send"><i class="bi bi-send"></i></button>
      </div>
    </div>
  </div>

  <!-- RECOMMANDATIONS PERSONNALISÉES -->
  <div class="reveal">
    <div class="section-eyebrow">Sélection IA</div>
    <div class="section-title">Recommandations personnalisées</div>
    <div class="section-sub">Basées sur votre profil et vos habitudes de lecture.</div>
    <div class="recs-grid" id="recs-grid">
      <?php foreach (array_slice($recs, 0, 12) as $i => $book):
        $pc = getPriceCat((float)($book['note_moyenne']??0),(float)($book['prix']??0));
        $colors = $coverColors[$i % count($coverColors)];
        $emoji  = $emojis[$i % count($emojis)];
        $match  = mt_rand(78, 97);
        $reason = $reasons[$i % count($reasons)];
        $stars  = str_repeat('★', min(5,(int)round((float)($book['note_moyenne']??0)))) . str_repeat('☆', max(0,5-(int)round((float)($book['note_moyenne']??0))));
        $extrait64 = base64_encode(substr((string)($book['contenu_extrait']??''),0,8000));
      ?>
      <div class="rec-card"
           data-id="<?= (int)($book['id']??0) ?>"
           data-titre="<?= htmlspecialchars((string)($book['titre']??''),ENT_QUOTES,'UTF-8') ?>"
           data-auteur="<?= htmlspecialchars((string)($book['auteur']??''),ENT_QUOTES,'UTF-8') ?>"
           data-prix="<?= (float)($book['prix']??0) ?>"
           data-note="<?= (float)($book['note_moyenne']??0) ?>"
           data-emoji="<?= $emoji ?>"
           data-extrait="<?= $extrait64 ?>"
           data-pages="<?= (int)($book['pages']??200) ?>">
        <div class="rec-cover">
          <div class="rec-cover-bg" style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)"></div>
          <div class="rec-emoji"><?= $emoji ?></div>
          <div class="rec-overlay"></div>
          <span class="rec-match"><?= $match ?>% match</span>
          <span class="price-badge <?= $pc['class'] ?>"><?= $pc['label'] ?></span>
        </div>
        <div class="rec-body">
          <div><span class="reason-badge">🤖 <?= htmlspecialchars($reason) ?></span></div>
          <div class="rec-title"><?= htmlspecialchars((string)($book['titre']??'')) ?></div>
          <div class="rec-author">par <?= htmlspecialchars((string)($book['auteur']??'')) ?></div>
          <div class="rec-rating"><span class="rec-stars"><?= $stars ?></span><span style="color:var(--txt-muted);font-size:.7rem"><?= number_format((float)($book['note_moyenne']??0),1) ?></span></div>
          <div class="rec-price <?= ($book['prix']??0)==0?'free':'' ?>"><?= $pc['display'] ?></div>
          <button class="rec-btn" onclick="handleRead(this)"><i class="bi bi-book-open"></i> Lire maintenant</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- PARCE QUE VOUS AVEZ LU -->
  <div class="reveal">
    <div class="section-eyebrow">Parce que vous avez lu…</div>
    <div class="section-title">Dans le même esprit</div>
    <div class="section-sub">Des œuvres qui partagent l'univers de vos lectures récentes.</div>
    <div class="because-list">
      <?php
      $becauseSource = !empty($becauseRecs) ? $becauseRecs : array_slice($demoRecs, 0, 3);
      $becauseNext   = array_reverse($demoRecs);
      foreach ($becauseSource as $j => $b):
        $bColors = $coverColors[($j+2) % count($coverColors)];
        $bNext   = $becauseNext[$j % count($becauseNext)];
        $bEmojiN = $emojis[($j+6) % count($emojis)];
        $bExtr64 = base64_encode(substr((string)($bNext['contenu_extrait']??''),0,8000));
      ?>
      <div class="because-card"
           data-id="<?= (int)($bNext['id']??0) ?>"
           data-titre="<?= htmlspecialchars((string)($bNext['titre']??''),ENT_QUOTES,'UTF-8') ?>"
           data-auteur="<?= htmlspecialchars((string)($bNext['auteur']??''),ENT_QUOTES,'UTF-8') ?>"
           data-prix="<?= (float)($bNext['prix']??0) ?>"
           data-note="<?= (float)($bNext['note_moyenne']??0) ?>"
           data-emoji="<?= $bEmojiN ?>"
           data-extrait="<?= $bExtr64 ?>"
           data-pages="<?= (int)($bNext['pages']??200) ?>"
           onclick="handleReadFromCard(this)">
        <div class="because-thumb" style="background:linear-gradient(135deg,<?= $bColors[0] ?>,<?= $bColors[1] ?>)"><?= $bEmojiN ?></div>
        <div>
          <div class="because-label">Parce que vous avez lu</div>
          <div class="because-trigger">«<?= htmlspecialchars((string)($b['titre']??'')) ?>»</div>
          <div class="because-title"><?= htmlspecialchars((string)($bNext['titre']??'')) ?></div>
          <div class="because-author">par <?= htmlspecialchars((string)($bNext['auteur']??'')) ?></div>
          <div class="because-meta">
            <span class="because-score">⭐ <?= number_format((float)($bNext['note_moyenne']??0),1) ?></span>
            <span class="because-price"><?= (($bNext['prix']??0)==0) ? 'Gratuit' : number_format((float)($bNext['prix']??0),0,'.',' ').' FCFA' ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- PROFIL DE LECTURE -->
  <div class="pref-chart reveal">
    <div class="pref-title">📊 Votre profil de lecture</div>
    <?php foreach ($prefs as $p): ?>
    <div class="pref-bar-row">
      <span class="pref-label"><?= htmlspecialchars($p['label']) ?></span>
      <div class="pref-bar-wrap"><div class="pref-bar-fill" style="background:<?= $p['color'] ?>" data-pct="<?= $p['pct'] ?>"></div></div>
      <span class="pref-pct"><?= $p['pct'] ?>%</span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- TENDANCES POUR VOUS -->
  <div class="reveal">
    <div class="section-eyebrow">Tendances pour vous</div>
    <div class="section-title">Ce que les lecteurs comme vous adorent</div>
    <div class="section-sub">Recommandations basées sur des profils similaires au vôtre.</div>
    <div class="rec-scroll-wrap">
      <div class="rec-scroll">
        <?php foreach (array_slice($trending, 0, 12) as $k => $book):
          $pc = getPriceCat((float)($book['note_moyenne']??0),(float)($book['prix']??0));
          $colors = $coverColors[($k+4) % count($coverColors)];
          $emoji  = $emojis[($k+4) % count($emojis)];
          $match  = mt_rand(72, 95);
          $reason = $reasons[($k+2) % count($reasons)];
          $stars  = str_repeat('★', min(5,(int)round((float)($book['note_moyenne']??0))));
          $extr64 = base64_encode(substr((string)($book['contenu_extrait']??''),0,8000));
        ?>
        <div class="rec-card"
             data-id="<?= (int)($book['id']??0) ?>"
             data-titre="<?= htmlspecialchars((string)($book['titre']??''),ENT_QUOTES,'UTF-8') ?>"
             data-auteur="<?= htmlspecialchars((string)($book['auteur']??''),ENT_QUOTES,'UTF-8') ?>"
             data-prix="<?= (float)($book['prix']??0) ?>"
             data-note="<?= (float)($book['note_moyenne']??0) ?>"
             data-emoji="<?= $emoji ?>"
             data-extrait="<?= $extr64 ?>"
             data-pages="<?= (int)($book['pages']??200) ?>">
          <div class="rec-cover">
            <div class="rec-cover-bg" style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)"></div>
            <div class="rec-emoji"><?= $emoji ?></div>
            <div class="rec-overlay"></div>
            <span class="rec-match"><?= $match ?>%</span>
            <span class="price-badge <?= $pc['class'] ?>"><?= $pc['label'] ?></span>
          </div>
          <div class="rec-body">
            <div><span class="reason-badge">🔥 <?= htmlspecialchars($reason) ?></span></div>
            <div class="rec-title"><?= htmlspecialchars((string)($book['titre']??'')) ?></div>
            <div class="rec-author">par <?= htmlspecialchars((string)($book['auteur']??'')) ?></div>
            <div class="rec-rating"><span class="rec-stars"><?= $stars ?></span></div>
            <div class="rec-price <?= ($book['prix']??0)==0?'free':'' ?>"><?= $pc['display'] ?></div>
            <button class="rec-btn" onclick="handleRead(this)"><i class="bi bi-book-open"></i> Lire</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- INSIGHTS IA -->
  <div class="reveal" style="margin-top:3.5rem">
    <div class="section-eyebrow">Insights IA</div>
    <div class="section-title">Ce que l'IA a découvert sur vos goûts</div>
    <div class="insights-grid">
      <div class="insight-card"><div class="insight-icon">🌙</div><div class="insight-title">Lecteur nocturne</div><div class="insight-desc">Vous lisez principalement le soir. L'IA sélectionne des œuvres immersives adaptées.</div><div class="insight-val">78%</div></div>
      <div class="insight-card reveal-delay-1"><div class="insight-icon">⚡</div><div class="insight-title">Rythme soutenu</div><div class="insight-desc">Vos sessions durent en moyenne 45 min. Vous préférez les chapitres courts et denses.</div><div class="insight-val">45 min</div></div>
      <div class="insight-card reveal-delay-2"><div class="insight-icon">🎯</div><div class="insight-title">Affinité forte</div><div class="insight-desc">Taux d'achèvement exceptionnel sur les œuvres de science-fiction.</div><div class="insight-val">92%</div></div>
      <div class="insight-card reveal-delay-3"><div class="insight-icon">📈</div><div class="insight-title">Appétit croissant</div><div class="insight-desc">Vous explorez de plus en plus les ouvrages de philosophie. Tendance en hausse.</div><div class="insight-val">+34%</div></div>
    </div>
  </div>

</main>

<footer>
  <div class="footer-inner">
    <div class="footer-links">
      <a href="index.php">Accueil</a><a href="explorer.php">Explorer</a>
      <a href="categories.php">Catégories</a><a href="tendances.php">Tendances</a><a href="aide.php">Aide</a>
    </div>
    <p class="footer-copy">© <?= date('Y') ?> Digital Library System — Tous droits réservés.</p>
  </div>
</footer>

<!-- TOAST -->
<div id="toast"><span class="toast-icon" id="t-icon">ℹ️</span><div class="toast-text"><span class="toast-ttl" id="t-title"></span><span class="toast-msg" id="t-msg"></span></div></div>

<!-- AUTH GATE -->
<div id="auth-gate">
  <div class="gate-backdrop" id="gate-backdrop"></div>
  <div class="gate-box">
    <button class="gate-close-btn" id="gate-close">✕</button>
    <div class="gate-icon">🔒</div>
    <div class="gate-eyebrow">Accès restreint</div>
    <div class="gate-title">Connexion requise</div>
    <div class="gate-desc">Créez un compte ou connectez-vous pour accéder à ce livre et à tout notre catalogue premium.</div>
    <div class="gate-btns">
      <a href="login.php" class="btn-gate-solid"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
      <a href="register.php" class="btn-gate-outline"><i class="bi bi-person-plus"></i> S'inscrire</a>
    </div>
    <div class="gate-countdown" id="gate-cd">Redirection dans 5s…</div>
    <div class="gate-progress"><div class="gate-progress-fill" id="gate-fill"></div></div>
  </div>
</div>

<!-- PAY MODAL -->
<div id="pay-modal">
  <div class="pay-backdrop" id="pay-backdrop"></div>
  <div class="pay-box">
    <div class="pay-bar"></div>
    <button class="pay-close" id="pay-close">✕</button>
    <div class="pay-header">
      <div class="pay-book-row">
        <div class="pay-book-thumb" id="pay-thumb">📚</div>
        <div><div class="pay-book-title" id="pay-modal-title">—</div><div class="pay-book-author" id="pay-author">—</div></div>
      </div>
      <div class="pay-amount-row">
        <span class="pay-amount-label">Montant :</span>
        <span class="pay-amount-val" id="pay-amount">0</span>
        <span class="pay-amount-curr">FCFA</span>
      </div>
    </div>
    <div class="pay-body">
      <div id="admin-gate-form" style="display:none">
        <div class="admin-notice"><i class="bi bi-shield-lock-fill"></i><div><strong style="color:var(--gold)">Accès administrateur</strong><br>Entrez votre email admin pour accéder gratuitement.<br><small style="color:var(--txt-muted)">Format : admin.[nom]@adminsopecam.com</small></div></div>
        <div class="field-group"><label class="field-label">Email administrateur</label><input type="email" class="field-input" id="admin-email" placeholder="admin.dupont@adminsopecam.com"></div>
        <button class="btn-pay" id="btn-admin-verify"><i class="bi bi-shield-check"></i> Vérifier et accéder</button>
      </div>
      <div id="pay-steps-wrap">
        <div class="pay-steps">
          <div class="step-dot active" id="sd-1"></div><div class="step-line" id="sl-1"></div>
          <div class="step-dot" id="sd-2"></div><div class="step-line" id="sl-2"></div>
          <div class="step-dot" id="sd-3"></div>
          <span class="step-label" id="step-lbl">Méthode</span>
        </div>
        <div id="step-1">
          <div class="method-title">Choisissez votre méthode de paiement</div>
          <div class="methods-grid">
            <button class="method-btn" data-method="orange_money"><span class="method-icon">📱</span><div><div class="method-name">Orange Money</div><div class="method-sub">Instantané</div></div></button>
            <button class="method-btn" data-method="mobile_money"><span class="method-icon">📲</span><div><div class="method-name">Mobile Money</div><div class="method-sub">MTN, Moov…</div></div></button>
            <button class="method-btn" data-method="visa"><span class="method-icon">💳</span><div><div class="method-name">Visa</div><div class="method-sub">Crédit/débit</div></div></button>
            <button class="method-btn" data-method="mastercard"><span class="method-icon">🏦</span><div><div class="method-name">Mastercard</div><div class="method-sub">International</div></div></button>
            <button class="method-btn" data-method="carte_locale" style="grid-column:span 2"><span class="method-icon">🏧</span><div><div class="method-name">Carte Locale</div><div class="method-sub">Bancaire camerounaise</div></div></button>
          </div>
          <button class="btn-pay" id="btn-next-step" disabled>Continuer <i class="bi bi-arrow-right"></i></button>
        </div>
        <div id="step-2" style="display:none">
          <div id="form-mobile" style="display:none">
            <div class="field-group"><label class="field-label">Numéro de téléphone</label><input type="tel" class="field-input" id="phone-number" placeholder="6XX XXX XXX" maxlength="12"></div>
            <div class="field-group"><label class="field-label">Nom complet</label><input type="text" class="field-input" id="mobile-name" placeholder="Jean Dupont"></div>
          </div>
          <div id="form-card" style="display:none">
            <div class="field-group"><label class="field-label">Numéro de carte</label><input type="text" class="field-input" id="card-number" placeholder="1234 5678 9012 3456" maxlength="19"></div>
            <div class="field-group"><label class="field-label">Nom du titulaire</label><input type="text" class="field-input" id="card-name" placeholder="JEAN DUPONT"></div>
            <div class="field-row">
              <div class="field-group"><label class="field-label">Expiration</label><input type="text" class="field-input" id="card-expiry" placeholder="MM/AA" maxlength="5"></div>
              <div class="field-group"><label class="field-label">CVV</label><input type="password" class="field-input" id="card-cvv" placeholder="•••" maxlength="4"></div>
            </div>
          </div>
          <button class="btn-pay" id="btn-pay-now"><i class="bi bi-lock-fill"></i> Payer maintenant</button>
          <button class="btn-back-step" id="btn-back">← Changer de méthode</button>
        </div>
        <div id="pay-processing" style="display:none;text-align:center;padding:2rem 0">
          <div class="process-spinner"></div>
          <div style="font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;margin-bottom:.4rem">Traitement en cours…</div>
          <div style="font-size:.78rem;color:var(--txt-muted)">Ne fermez pas cette fenêtre</div>
          <div style="margin-top:1rem">
            <div class="proc-step" id="ps-1"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Connexion au serveur de paiement</span></div>
            <div class="proc-step" id="ps-2"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Vérification des informations</span></div>
            <div class="proc-step" id="ps-3"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Autorisation bancaire</span></div>
            <div class="proc-step" id="ps-4"><div class="proc-icon"><i class="bi bi-arrow-right"></i></div><span>Confirmation de transaction</span></div>
          </div>
        </div>
        <div id="pay-success" style="display:none;text-align:center;padding:2rem 0">
          <div class="success-ring">✅</div>
          <div class="success-title">Paiement réussi !</div>
          <div style="font-size:.82rem;color:var(--txt-muted)">Votre accès a été activé.</div>
          <div class="success-ref" id="success-ref">REF: —</div>
          <button class="btn-pay" id="btn-open-reader"><i class="bi bi-book-open"></i> Ouvrir le lecteur</button>
        </div>
      </div>
    </div>
    <div class="pay-security">
      <span class="sec-badge"><i class="bi bi-shield-check"></i> SSL 256-bit</span>
      <span class="sec-badge"><i class="bi bi-lock-fill"></i> Chiffré</span>
      <span class="sec-badge"><i class="bi bi-patch-check"></i> Sécurisé</span>
    </div>
  </div>
</div>

<!-- READER MODAL -->
<div id="reader-modal">
  <div class="reader-header">
    <div class="reader-title-wrap">
      <span id="reader-chapter-badge" style="font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--gold);background:rgba(232,201,125,.1);border:1px solid rgba(232,201,125,.2);padding:3px 8px;border-radius:6px;flex-shrink:0;white-space:nowrap"></span>
      <span class="reader-title-el" id="reader-title">—</span>
    </div>
    <div class="reader-controls">
      <button class="reader-btn" id="btn-theme"><i class="bi bi-moon" id="theme-ico"></i></button>
      <button class="reader-btn" id="btn-font-up"><i class="bi bi-zoom-in"></i></button>
      <button class="reader-btn" id="btn-font-down"><i class="bi bi-zoom-out"></i></button>
      <button class="reader-btn" id="btn-bookmark"><i class="bi bi-bookmark" id="bm-ico"></i></button>
      <button class="reader-btn reader-close-btn" id="btn-reader-close"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div class="reader-progress-bar"><div class="reader-progress-fill" id="reader-progress" style="width:0%"></div></div>
  <div class="reader-body" id="reader-body"><div class="reader-inner"><div class="reader-content" id="reader-content">Chargement…</div></div></div>
  <div class="reader-nav">
    <button class="reader-nav-btn" id="btn-prev-page"><i class="bi bi-chevron-left"></i> Précédente</button>
    <span class="reader-page-info" id="reader-page-info">Page 1 / 1</span>
    <button class="reader-nav-btn" id="btn-next-page">Suivante <i class="bi bi-chevron-right"></i></button>
  </div>
</div>

<script>
'use strict';
const IS_LOGGED=<?= json_encode($isLoggedIn) ?>;
const IS_ADMIN =<?= json_encode($isAdmin) ?>;
const USERNAME =<?= json_encode($username) ?>;

let currentBook=null,selectedMethod=null,readerFontSize=17,readerIsLight=false,readerPage=1,readerTotal=1,readerPages=[],gateTimer=null,gateInterval=null,toastT=null,aiT=null;

// TOAST
function toast(title,msg='',type='default',dur=4000){
  const el=document.getElementById('toast'),icons={default:'ℹ️',success:'✅',error:'❌',warn:'⚠️'};
  el.className='';if(type!=='default')el.classList.add('t-'+type);
  document.getElementById('t-icon').textContent=icons[type]||'ℹ️';
  document.getElementById('t-title').textContent=title;
  document.getElementById('t-msg').textContent=msg;
  el.classList.add('show');clearTimeout(toastT);toastT=setTimeout(()=>el.classList.remove('show'),dur);
}

// HEADER SCROLL
window.addEventListener('scroll',()=>document.getElementById('site-header').classList.toggle('scrolled',scrollY>60));

// HAMBURGER
const ham=document.getElementById('hamburger'),mNav=document.getElementById('mobile-nav');
ham.addEventListener('click',()=>{const o=mNav.classList.toggle('open');ham.innerHTML=o?'<i class="bi bi-x-lg"></i>':'<i class="bi bi-list"></i>';});

// REVEAL
const ro=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('visible');ro.unobserve(e.target);}}),{threshold:.1});
document.querySelectorAll('.reveal').forEach(el=>ro.observe(el));

// PREF BARS
const bo=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.querySelectorAll('.pref-bar-fill').forEach(b=>b.style.width=b.dataset.pct+'%');bo.unobserve(e.target);}}),{threshold:.3});
document.querySelectorAll('.pref-chart').forEach(el=>bo.observe(el));

// TASTE TAGS
document.querySelectorAll('.taste-tag').forEach(t=>t.addEventListener('click',()=>t.classList.toggle('inactive')));

// REFRESH
document.getElementById('btn-refresh').addEventListener('click',function(){
  this.classList.add('spinning');toast('Actualisation','Recalcul des recommandations IA…','default',2500);
  setTimeout(()=>{this.classList.remove('spinning');toast('Recommandations actualisées','Analyse de votre profil terminée.','success',3500);},2200);
});

// AI TYPING
const aiPhrases=IS_LOGGED?[
  `Bonjour ${USERNAME} ! Analyse de votre profil en cours…`,
  'Détection de patterns : Science-Fiction (78%), Philosophie (65%)',
  'Correspondance avec 340 profils similaires trouvée.',
  'Recommandation n°1 : correspondance à 94.2% avec votre profil.',
  'Algorithme hybride activé. Suggestions générées avec précision optimale.',
]:[
  'Bienvenue ! Créez un compte pour des recommandations personnalisées.',
  'Notre IA analyse les préférences de milliers de lecteurs.',
  'Connectez-vous pour accéder à votre profil de lecture personnalisé.',
  'Plus de 12 000 livres vous attendent dans notre catalogue.',
];
let aiIdx=0,aiCIdx=0,aiDel=false;
const aiOut=document.getElementById('ai-output');
function aiType(){
  if(!aiOut)return;
  const p=aiPhrases[aiIdx],cur='<span class="type-cursor"></span>';
  if(!aiDel&&aiCIdx<=p.length){aiOut.innerHTML=p.slice(0,aiCIdx)+cur;aiCIdx++;aiT=setTimeout(aiType,38);}
  else if(aiDel&&aiCIdx>=0){aiOut.innerHTML=p.slice(0,aiCIdx)+cur;aiCIdx--;aiT=setTimeout(aiType,18);}
  else if(!aiDel){aiDel=true;aiT=setTimeout(aiType,2800);}
  else{aiDel=false;aiIdx=(aiIdx+1)%aiPhrases.length;aiCIdx=0;aiT=setTimeout(aiType,400);}
}
setTimeout(aiType,900);
function handleAiChat(){
  const inp=document.getElementById('ai-input'),q=inp?inp.value.trim():'';
  if(!q)return;clearTimeout(aiT);
  const r=[`Pour "${q}", j'ai trouvé plusieurs correspondances dans notre catalogue !`,`Excellente requête ! Consultez Explorer pour les meilleurs résultats.`,`Notre IA cherche "${q}"… Plusieurs titres correspondent à votre profil.`];
  if(aiOut)aiOut.innerHTML=r[Math.floor(Math.random()*r.length)]+'<span class="type-cursor"></span>';
  if(inp)inp.value='';
}
document.getElementById('ai-send').addEventListener('click',handleAiChat);
document.getElementById('ai-input').addEventListener('keydown',e=>{if(e.key==='Enter')handleAiChat();});

// AUTH GATE
function openAuthGate(){
  const gate=document.getElementById('auth-gate');if(!gate)return;
  gate.classList.add('open');document.body.style.overflow='hidden';
  let sec=5;const cdEl=document.getElementById('gate-cd'),fillEl=document.getElementById('gate-fill');
  if(cdEl)cdEl.textContent=`Redirection dans ${sec}s…`;
  if(fillEl){fillEl.style.transition='none';fillEl.style.transform='scaleX(1)';fillEl.getBoundingClientRect();fillEl.style.transition='transform 5s linear';fillEl.style.transform='scaleX(0)';}
  clearInterval(gateInterval);gateInterval=setInterval(()=>{sec--;if(cdEl)cdEl.textContent=`Redirection dans ${sec}s…`;if(sec<=0)clearInterval(gateInterval);},1000);
  clearTimeout(gateTimer);gateTimer=setTimeout(()=>{window.location.href='login.php';},5000);
}
function closeAuthGate(){document.getElementById('auth-gate').classList.remove('open');document.body.style.overflow='';clearTimeout(gateTimer);clearInterval(gateInterval);}
document.getElementById('gate-close').addEventListener('click',closeAuthGate);
document.getElementById('gate-backdrop').addEventListener('click',closeAuthGate);

// BOOK DATA
function getBook(card){
  let extrait='';try{const b64=card.dataset.extrait||'';if(b64)extrait=atob(b64);}catch(e){extrait='';}
  return{id:card.dataset.id||'0',title:card.dataset.titre||'—',author:card.dataset.auteur||'—',price:parseFloat(card.dataset.prix)||0,note:parseFloat(card.dataset.note)||0,emoji:card.dataset.emoji||'📚',pages:parseInt(card.dataset.pages)||200,extrait};
}
function handleRead(btn){const card=btn.closest('.rec-card');if(!card)return;currentBook=getBook(card);dispatchRead();}
function handleReadFromCard(card){currentBook=getBook(card);dispatchRead();}
function dispatchRead(){
  if(!currentBook)return;
  if(!IS_LOGGED){openAuthGate();return;}
  if(IS_ADMIN){openPayModal(true);return;}
  if(currentBook.price===0||currentBook.note<=2.0){openReader();return;}
  openPayModal(false);
}

// PAY MODAL
function openPayModal(adminMode){
  if(!currentBook)return;
  document.getElementById('pay-modal-title').textContent=currentBook.title;
  document.getElementById('pay-author').textContent='par '+currentBook.author;
  document.getElementById('pay-thumb').textContent=currentBook.emoji;
  document.getElementById('pay-amount').textContent=currentBook.price===0?'0':currentBook.price.toLocaleString('fr-FR');
  resetPayModal();
  document.getElementById('admin-gate-form').style.display=adminMode?'block':'none';
  document.getElementById('pay-steps-wrap').style.display=adminMode?'none':'block';
  document.getElementById('pay-modal').classList.add('open');document.body.style.overflow='hidden';
}
function closePayModal(){document.getElementById('pay-modal').classList.remove('open');document.body.style.overflow='';resetPayModal();}
function resetPayModal(){
  selectedMethod=null;
  ['step-1','step-2','pay-processing','pay-success'].forEach((id,i)=>{const el=document.getElementById(id);if(el)el.style.display=i===0?'block':'none';});
  document.getElementById('admin-gate-form').style.display='none';
  document.getElementById('pay-steps-wrap').style.display='block';
  ['sd-1','sd-2','sd-3'].forEach(id=>{const el=document.getElementById(id);if(el)el.className='step-dot';});
  ['sl-1','sl-2'].forEach(id=>{const el=document.getElementById(id);if(el)el.className='step-line';});
  const sd1=document.getElementById('sd-1');if(sd1)sd1.classList.add('active');
  const lbl=document.getElementById('step-lbl');if(lbl)lbl.textContent='Méthode';
  document.querySelectorAll('.method-btn').forEach(b=>b.classList.remove('selected'));
  const btnN=document.getElementById('btn-next-step');if(btnN)btnN.disabled=true;
  ['phone-number','mobile-name','card-number','card-name','card-expiry','card-cvv','admin-email'].forEach(id=>{const el=document.getElementById(id);if(el){el.value='';el.style.borderColor='';}});
  const fm=document.getElementById('form-mobile'),fc=document.getElementById('form-card');
  if(fm)fm.style.display='none';if(fc)fc.style.display='none';
  ['ps-1','ps-2','ps-3','ps-4'].forEach(id=>{const el=document.getElementById(id);if(el)el.className='proc-step';});
}
document.querySelectorAll('.method-btn').forEach(btn=>btn.addEventListener('click',()=>{
  document.querySelectorAll('.method-btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');selectedMethod=btn.dataset.method;
  const n=document.getElementById('btn-next-step');if(n)n.disabled=false;
}));
document.getElementById('btn-next-step').addEventListener('click',()=>{
  if(!selectedMethod)return;
  document.getElementById('step-1').style.display='none';document.getElementById('step-2').style.display='block';
  document.getElementById('sd-1').className='step-dot done';document.getElementById('sl-1').className='step-line done';
  document.getElementById('sd-2').className='step-dot active';document.getElementById('step-lbl').textContent='Informations';
  const isMob=['orange_money','mobile_money'].includes(selectedMethod);
  document.getElementById('form-mobile').style.display=isMob?'block':'none';
  document.getElementById('form-card').style.display=isMob?'none':'block';
});
document.getElementById('btn-back').addEventListener('click',()=>{
  document.getElementById('step-2').style.display='none';document.getElementById('step-1').style.display='block';
  document.getElementById('sd-1').className='step-dot active';document.getElementById('sl-1').className='step-line';
  document.getElementById('sd-2').className='step-dot';document.getElementById('step-lbl').textContent='Méthode';
});
document.getElementById('btn-pay-now').addEventListener('click',()=>{
  const isMob=['orange_money','mobile_money'].includes(selectedMethod);
  if(isMob){const ph=(document.getElementById('phone-number').value||'').replace(/\s/g,'');if(ph.length<9){toast('Numéro invalide','Min 9 chiffres requis','error');return;}}
  else{
    const cn=(document.getElementById('card-number').value||'').replace(/\s/g,'');
    const exp=document.getElementById('card-expiry').value||'';const cvv=document.getElementById('card-cvv').value||'';
    if(cn.length<16){toast('Carte invalide','16 chiffres requis','error');return;}
    if(!/^\d{2}\/\d{2}$/.test(exp)){toast('Expiration invalide','Format MM/AA','error');return;}
    if(cvv.length<3){toast('CVV invalide','3-4 chiffres requis','error');return;}
  }
  document.getElementById('step-2').style.display='none';document.getElementById('pay-processing').style.display='block';
  document.getElementById('sd-2').className='step-dot done';document.getElementById('sl-2').className='step-line done';document.getElementById('sd-3').className='step-dot active';
  const steps=['ps-1','ps-2','ps-3','ps-4'],delays=[0,950,1950,3100];
  steps.forEach((sid,i)=>{setTimeout(()=>{if(i>0){const p=document.getElementById(steps[i-1]);if(p)p.className='proc-step done';}const c=document.getElementById(sid);if(c)c.className='proc-step active';},delays[i]);});
  setTimeout(()=>{
    const l=document.getElementById(steps[steps.length-1]);if(l)l.className='proc-step done';
    setTimeout(()=>{
      document.getElementById('pay-processing').style.display='none';document.getElementById('pay-success').style.display='block';
      document.getElementById('sd-3').className='step-dot done';
      const ref='DLS-'+Date.now().toString(36).toUpperCase()+'-'+Math.random().toString(36).slice(2,6).toUpperCase();
      const refEl=document.getElementById('success-ref');if(refEl)refEl.textContent='REF: '+ref;
      toast('Paiement validé !','Accès au livre activé.','success',5000);
      if(currentBook)fetch('api/save_purchase.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({livre_id:currentBook.id,montant:currentBook.price,methode:selectedMethod,reference:ref})}).catch(()=>{});
    },400);
  },delays[delays.length-1]+700);
});
document.getElementById('btn-open-reader').addEventListener('click',()=>{closePayModal();setTimeout(openReader,300);});
document.getElementById('btn-admin-verify').addEventListener('click',()=>{
  const emailEl=document.getElementById('admin-email'),email=(emailEl?emailEl.value.trim():'');
  if(!/^admin\.[a-zA-Z][a-zA-Z0-9.]*@adminsopecam\.com$/.test(email)){toast('Email invalide','Format : admin.[nom]@adminsopecam.com','error');if(emailEl)emailEl.style.borderColor='rgba(255,95,87,.5)';return;}
  if(emailEl)emailEl.style.borderColor='rgba(78,204,163,.5)';
  toast('Accès accordé','Ouverture du lecteur…','success',3000);setTimeout(()=>{closePayModal();openReader();},1200);
});
document.getElementById('card-number')?.addEventListener('input',function(){let v=this.value.replace(/\D/g,'').slice(0,16);this.value=v.match(/.{1,4}/g)?.join(' ')||v;});
document.getElementById('card-expiry')?.addEventListener('input',function(){let v=this.value.replace(/\D/g,'');if(v.length>2)v=v.slice(0,2)+'/'+v.slice(2,4);this.value=v;});
document.getElementById('pay-close').addEventListener('click',closePayModal);
document.getElementById('pay-backdrop').addEventListener('click',closePayModal);

// READER
function openReader(){
  if(!currentBook)return;const modal=document.getElementById('reader-modal');if(!modal)return;
  document.getElementById('reader-title').textContent=currentBook.title;
  const raw=currentBook.extrait||generateFallback(currentBook.title);
  readerPages=buildPages(raw);readerTotal=readerPages.length;readerPage=1;
  try{const s=localStorage.getItem('dls_page_'+currentBook.id);if(s){const p=parseInt(s,10);if(p>=1&&p<=readerTotal)readerPage=p;}}catch(e){}
  renderPage(false);modal.classList.add('open');document.body.style.overflow='hidden';
  toast('Lecteur ouvert',`${readerTotal} pages · Bonne lecture !`,'success',3000);
}
function buildPages(content){
  const parts=content.split('||||PAGE||||').map(p=>p.trim()).filter(p=>p.length>0);
  if(parts.length>=5)return parts;
  const extras=['APPROFONDISSEMENT\n\nCette œuvre révèle progressivement la profondeur de la vision de l\'auteur. Les thèmes abordés résonnent avec une actualité frappante.','RÉFLEXIONS\n\nCette partie de l\'œuvre nous plonge dans une méditation profonde. L\'auteur tisse ensemble des fils narratifs pour révéler une richesse insoupçonnée.','VERS LA FIN\n\nAlors que nous approchons de la conclusion, il convient de mesurer le chemin parcouru.','ÉPILOGUE\n\nLa dernière page tournée, le livre refermé, le lecteur reste un moment silencieux.','POSTFACE\n\nRevenez-y dans quelques mois — vous le lirez différemment, et ce sera comme le découvrir à nouveau.'];
  const result=[...parts];let idx=0;while(result.length<5){result.push(extras[idx%extras.length]);idx++;}return result;
}
function generateFallback(title){return `CHAPITRE I — Introduction\n\n${title} nous plonge dans un univers fascinant. Chaque page révèle de nouveaux secrets.\n\nL'auteur maîtrise l'art de captiver le lecteur dès les premières lignes.||||PAGE||||CHAPITRE II — Développement\n\nLa tension narrative monte progressivement. Les personnages prennent vie avec une authenticité remarquable.||||PAGE||||CHAPITRE III — Le Tournant\n\nUn événement inattendu vient bouleverser l'équilibre établi. Rien ne sera plus comme avant.||||PAGE||||CHAPITRE IV — Les Révélations\n\nLes vérités cachées émergent enfin. Ce que le lecteur croyait comprendre se révèle bien plus complexe.||||PAGE||||CHAPITRE V — Dénouement\n\nLa conclusion, inattendue et inévitable, laisse une empreinte durable dans l'esprit du lecteur.`;}
function renderPage(animate,dir='next'){
  const contentEl=document.getElementById('reader-content');if(!contentEl)return;
  const raw=readerPages[readerPage-1]||'Contenu non disponible.';
  let html=raw.replace(/^(CHAPITRE [IVX0-9]+[^—\n]*(?:—[^\n]*)?)/gm,'<h2>$1</h2>').replace(/^(ÉPILOGUE|POSTFACE|APPROFONDISSEMENT|RÉFLEXIONS|VERS LA FIN)/gm,'<h2>$1</h2>').replace(/\n\n+/g,'</p><p>').replace(/\n/g,'<br>');
  html='<p>'+html+'</p>';html=html.replace(/<p><h2>/g,'<h2>').replace(/<\/h2><\/p>/g,'</h2>').replace(/<p><\/p>/g,'');
  const pi=document.getElementById('reader-page-info'),pr=document.getElementById('reader-progress'),cb=document.getElementById('reader-chapter-badge'),btnP=document.getElementById('btn-prev-page'),btnN=document.getElementById('btn-next-page');
  if(pi)pi.textContent=`Page ${readerPage} / ${readerTotal}`;if(pr)pr.style.width=((readerPage/readerTotal)*100).toFixed(1)+'%';if(cb)cb.textContent=`Ch. ${readerPage}`;
  if(btnP)btnP.disabled=readerPage===1;if(btnN)btnN.disabled=readerPage===readerTotal;
  try{if(currentBook)localStorage.setItem('dls_page_'+currentBook.id,readerPage);}catch(e){}
  if(!animate){contentEl.innerHTML=html;contentEl.style.fontSize=readerFontSize+'px';const b=document.getElementById('reader-body');if(b)b.scrollTop=0;return;}
  contentEl.style.opacity='0';contentEl.style.transform=dir==='next'?'translateX(-30px)':'translateX(30px)';contentEl.style.transition='opacity .15s,transform .15s';
  setTimeout(()=>{contentEl.innerHTML=html;contentEl.style.fontSize=readerFontSize+'px';contentEl.style.opacity='0';contentEl.style.transform=dir==='next'?'translateX(30px)':'translateX(-30px)';contentEl.style.transition='none';const b=document.getElementById('reader-body');if(b)b.scrollTop=0;requestAnimationFrame(()=>{contentEl.style.transition='opacity .3s,transform .3s';contentEl.style.opacity='1';contentEl.style.transform='translateX(0)';});},150);
}
function closeReader(){document.getElementById('reader-modal').classList.remove('open');document.body.style.overflow='';}
document.getElementById('btn-reader-close').addEventListener('click',closeReader);
document.getElementById('btn-prev-page').addEventListener('click',()=>{if(readerPage>1){readerPage--;renderPage(true,'prev');}else toast('Début','Première page atteinte.','warn',2000);});
document.getElementById('btn-next-page').addEventListener('click',()=>{if(readerPage<readerTotal){readerPage++;renderPage(true,'next');}else toast('Fin','Vous avez atteint la dernière page.','warn',3000);});
document.getElementById('btn-theme').addEventListener('click',()=>{const modal=document.getElementById('reader-modal'),ico=document.getElementById('theme-ico');readerIsLight=!readerIsLight;modal.classList.toggle('reader-light',readerIsLight);if(ico)ico.className=readerIsLight?'bi bi-sun-fill':'bi bi-moon';toast('Thème',readerIsLight?'Mode clair':'Mode sombre','default',1500);});
document.getElementById('btn-font-up').addEventListener('click',()=>{readerFontSize=Math.min(24,readerFontSize+2);const c=document.getElementById('reader-content');if(c)c.style.fontSize=readerFontSize+'px';toast('Police',`${readerFontSize}px`,'default',1500);});
document.getElementById('btn-font-down').addEventListener('click',()=>{readerFontSize=Math.max(12,readerFontSize-2);const c=document.getElementById('reader-content');if(c)c.style.fontSize=readerFontSize+'px';toast('Police',`${readerFontSize}px`,'default',1500);});
document.getElementById('btn-bookmark').addEventListener('click',()=>{const ico=document.getElementById('bm-ico');if(ico){ico.className='bi bi-bookmark-fill';ico.style.color='var(--gold)';}setTimeout(()=>{if(ico){ico.className='bi bi-bookmark';ico.style.color='';}},2000);toast('Marque-page',`Page ${readerPage}/${readerTotal} sauvegardée.`,'success',3000);});

// KEYBOARD
document.addEventListener('keydown',e=>{
  const ro=document.getElementById('reader-modal')?.classList.contains('open');
  const po=document.getElementById('pay-modal')?.classList.contains('open');
  const go=document.getElementById('auth-gate')?.classList.contains('open');
  if(e.key==='Escape'){if(ro)closeReader();else if(po)closePayModal();else if(go)closeAuthGate();return;}
  if(ro){
    if(['ArrowRight','ArrowDown','PageDown'].includes(e.key)){e.preventDefault();if(readerPage<readerTotal){readerPage++;renderPage(true,'next');}}
    if(['ArrowLeft','ArrowUp','PageUp'].includes(e.key)){e.preventDefault();if(readerPage>1){readerPage--;renderPage(true,'prev');}}
  }
});
</script>
</body>
</html>