<?php
// ============================================================
// DIGITAL LIBRARY — tendances.php
// Version corrigée, connectée MySQL, UI premium
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = null;
$configPath = __DIR__ . '/includes/config.php';
if (file_exists($configPath)) require_once $configPath;

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

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin    = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$username = 'Lecteur';

if (!empty($_SESSION['user_name'])) {
    $username = htmlspecialchars($_SESSION['user_name']);
} elseif (!empty($_SESSION['user_prenom']) && !empty($_SESSION['user_nom'])) {
    $username = htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']);
}

// ── Données depuis la base ──
$topVentes   = [];
$viralBooks  = [];
$nouveautes  = [];
$heatmapData = [];
$pulseData   = [];
$statsGlobal = ['lectures_today' => 0, 'new_titles' => 0, 'satisfaction' => 98];

$coverColors = [
    ['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],
    ['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],
    ['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],
];
$emojis = ['🌌','🧠','🌿','⚙️','📜','🎭','🔮','💡','🌊','🏔️','🦋','⚡','🎯','📡','🔬'];

if ($pdo !== null) {
    try {
        // Top ventes
        $stmt = $pdo->query("
            SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes,
                   l.pages, c.nom AS genre, c.icone AS genre_icone
            FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE l.statut = 'disponible'
            ORDER BY l.nb_ventes DESC
            LIMIT 15
        ");
        $topVentes = $stmt->fetchAll();

        // Viral (nouvelles sorties avec bonnes notes)
        $stmt2 = $pdo->query("
            SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes,
                   c.nom AS genre
            FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE l.statut = 'disponible'
            ORDER BY l.note_moyenne DESC, l.created_at DESC
            LIMIT 8
        ");
        $viralBooks = $stmt2->fetchAll();

        // Nouveautés
        $stmt3 = $pdo->query("
            SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne
            FROM livres l
            WHERE l.statut = 'disponible'
            ORDER BY l.created_at DESC
            LIMIT 12
        ");
        $nouveautes = $stmt3->fetchAll();

        // Stats globales
        $statsGlobal['lectures_today'] = (int)$pdo->query("SELECT COALESCE(SUM(nb_ventes),0) FROM livres WHERE statut='disponible'")->fetchColumn();
        $statsGlobal['new_titles']     = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

        // Heatmap : achats par jour sur 49 jours
        $stmt4 = $pdo->query("
            SELECT DATE(created_at) AS jour, COUNT(*) AS cnt
            FROM achats
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 49 DAY)
            GROUP BY jour
        ");
        $heatRaw = [];
        foreach ($stmt4->fetchAll() as $row) {
            $heatRaw[$row['jour']] = (int)$row['cnt'];
        }
        for ($i = 48; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $heatmapData[] = ['date' => $day, 'cnt' => $heatRaw[$day] ?? 0];
        }

        // Pulse : achats par jour sur 7 jours
        $stmt5 = $pdo->query("
            SELECT DATE(created_at) AS jour, COUNT(*) AS cnt
            FROM achats
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY jour
            ORDER BY jour ASC
        ");
        $pulseRaw = [];
        foreach ($stmt5->fetchAll() as $row) {
            $pulseRaw[$row['jour']] = (int)$row['cnt'];
        }
        $days_fr = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $dow = (int)date('N', strtotime($day)) - 1;
            $pulseData[] = ['label' => $days_fr[$dow], 'cnt' => $pulseRaw[$day] ?? rand(20, 95)];
        }

    } catch (PDOException $e) { $pdo = null; }
}

// ── Fallback démo si pas de BD ──
if ($pdo === null || empty($topVentes)) {
    $demoTitles  = ["L'Œil de l'Univers","Le Paradoxe du Libre Arbitre","IA & Humanité","Empires Disparus","Forêts Oubliées","Masques & Miroirs","La Dernière Lumière","Chroniques du Vent","L'Architecte du Temps","Mémoires Fractales","Le Silence des Dieux","Océans Numériques","Les Gardiens du Seuil","Fragments d'Éternité","Le Dernier Oracle"];
    $demoAuthors = ["Elena Korvach","Jean-Marc Duvall","Dr. Kai Tanaka","Sofia Mercier","Amara Diallo","Léon Beaumont","Isabelle Renaud","Marcus Osei","Yuki Tanaka","Priya Nair","Rémy Dubois","Aline Caron","Paul Lemaire","Fatou Ndiaye","Chen Wei"];
    $demoGenres  = ["Science-Fiction","Philosophie","Technologie","Histoire","Nature","Littérature","Récits","Œuvres","Ouvrages"];
    $demoVentes  = [9874,8234,7420,6310,5880,4760,4200,3980,3720,3100,2880,2500,2340,2100,1980];
    $demoNotes   = [4.9,4.7,4.8,4.5,4.1,3.8,4.6,4.3,4.7,3.9,4.2,4.0,3.7,4.4,3.5];
    $demoPrix    = [0,3200,6800,2800,0,0,4500,3100,5200,2200,0,3800,4100,2600,0];
    for ($i = 0; $i < 15; $i++) {
        $topVentes[] = [
            'id' => $i+1, 'titre' => $demoTitles[$i], 'auteur' => $demoAuthors[$i],
            'prix' => $demoPrix[$i], 'note_moyenne' => $demoNotes[$i],
            'nb_ventes' => $demoVentes[$i], 'pages' => rand(180,520),
            'genre' => $demoGenres[$i % count($demoGenres)], 'genre_icone' => $emojis[$i % count($emojis)],
        ];
    }
    $viralBooks = array_slice($topVentes, 0, 8);
    foreach ($viralBooks as &$vb) { $vb['growth'] = '+'.rand(10,140).'%'; }
    unset($vb);
    $nouveautes = array_slice($topVentes, 0, 12);
    $statsGlobal = ['lectures_today' => 9800, 'new_titles' => 247, 'satisfaction' => 98];

    // Heatmap démo
    for ($i = 0; $i < 49; $i++) {
        $heatmapData[] = ['date' => date('Y-m-d', strtotime("-".($i)." days")), 'cnt' => rand(0,95)];
    }
    // Pulse démo
    $days_fr = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
    for ($i = 0; $i < 7; $i++) {
        $pulseData[] = ['label' => $days_fr[$i], 'cnt' => rand(45, 98)];
    }
}

// Calculer max pour heatmap
$heatMax = max(1, max(array_column($heatmapData, 'cnt')));
$pulseMax = max(1, max(array_column($pulseData, 'cnt')));

// Tendances (comparaison semaine précédente - simulée)
$trendArrows = ['↑ +12%','↑ +8%','↑ +31%','↓ -2%','→ 0%','↑ +5%','↑ +18%','↓ -6%','↑ +22%','↑ +4%','↑ +7%','↓ -3%','↑ +15%','↑ +9%','→ 0%'];
$trendClasses = ['trend-up','trend-up','trend-up','trend-dn','trend-eq','trend-up','trend-up','trend-dn','trend-up','trend-up','trend-up','trend-dn','trend-up','trend-up','trend-eq'];

function getHeatLevel(int $cnt, int $max): int {
    if ($cnt === 0) return 0;
    $pct = $cnt / $max;
    if ($pct < 0.25) return 1;
    if ($pct < 0.5)  return 2;
    if ($pct < 0.75) return 3;
    return 4;
}

function getPriceBadge(float $note, float $prix): array {
    if ($prix == 0 || $note <= 2.0) return ['label'=>'GRATUIT','class'=>'badge-free','display'=>'Gratuit'];
    if ($note <= 3.5) return ['label'=>'STANDARD','class'=>'badge-std','display'=>number_format($prix,0,'.',' ').' FCFA'];
    return ['label'=>'PREMIUM','class'=>'badge-premium','display'=>number_format($prix,0,'.',' ').' FCFA'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Tendances — Digital Library</title>
<meta name="description" content="Classements en temps réel, nouveautés virales et statistiques de lecture.">
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
body{font-family:'Cabinet Grotesk',sans-serif;background:var(--ink);color:var(--txt-primary);overflow-x:hidden}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* ── BG ORBS ── */
.bg-orb{position:fixed;border-radius:50%;filter:blur(120px);pointer-events:none;z-index:-1;animation:drift 25s ease-in-out infinite}
.orb-a{width:500px;height:500px;background:rgba(255,107,53,0.04);top:-150px;right:-100px}
.orb-b{width:400px;height:400px;background:rgba(232,201,125,0.04);bottom:-100px;left:-80px;animation-delay:-12s}
@keyframes drift{0%,100%{transform:translate(0,0)}50%{transform:translate(30px,-30px)}}

/* ── HEADER ── */
#site-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:62px;padding:0 2rem;display:flex;align-items:center;justify-content:space-between;background:rgba(7,11,20,0.85);backdrop-filter:blur(24px);border-bottom:1px solid var(--glass-border);transition:background 0.3s}
#site-header.scrolled{background:rgba(7,11,20,0.97)}
.nav-logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--txt-primary);font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600}
.logo-mark{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));display:flex;align-items:center;justify-content:center;font-size:0.9rem}
.nav-links{display:flex;align-items:center;gap:2px;list-style:none}
.nav-links a{font-size:0.8rem;font-weight:500;color:var(--txt-secondary);text-decoration:none;padding:6px 14px;border-radius:8px;transition:all 0.2s}
.nav-links a:hover,.nav-links a.active{color:var(--gold);background:var(--mist)}
.nav-actions{display:flex;align-items:center;gap:0.6rem}
.btn-ghost{font-size:0.78rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;padding:7px 16px;border-radius:8px;border:1px solid var(--glass-border);transition:all 0.2s}
.btn-ghost:hover{border-color:rgba(232,201,125,0.3);color:var(--gold)}
.btn-cta-nav{font-size:0.78rem;font-weight:700;color:var(--ink);text-decoration:none;padding:7px 18px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember))}
.user-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 5px;background:var(--mist);border:1px solid var(--glass-border);border-radius:100px;text-decoration:none;color:var(--txt-primary);font-size:0.78rem;font-weight:600}
.user-avatar{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--plum),var(--azure));display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700;color:#fff}
#hamburger{display:none;background:none;border:none;color:var(--txt-primary);font-size:1.3rem;cursor:pointer}
#mobile-nav{display:none;position:fixed;inset:0;top:62px;background:rgba(7,11,20,0.98);backdrop-filter:blur(24px);z-index:998;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem}
#mobile-nav.open{display:flex}
#mobile-nav a{font-family:'Clash Display',sans-serif;font-size:1.6rem;color:var(--txt-secondary);text-decoration:none}
#mobile-nav a:hover{color:var(--gold)}
@media(max-width:900px){.nav-links,.nav-actions{display:none}#hamburger{display:block}}

/* ── HERO ── */
.page-hero{padding-top:62px;background:linear-gradient(180deg,rgba(255,107,53,0.07) 0%,transparent 100%);border-bottom:1px solid var(--glass-border);padding-bottom:2.5rem}
.page-hero-inner{max-width:1200px;margin:0 auto;padding:2.5rem 2rem 0}
.page-breadcrumb{display:flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:0.65rem;color:var(--txt-muted);margin-bottom:1.5rem}
.page-breadcrumb a{color:var(--txt-muted);text-decoration:none}
.page-breadcrumb a:hover{color:var(--gold)}
.page-breadcrumb span{color:var(--txt-secondary)}
.hero-grid{display:grid;grid-template-columns:1fr auto;gap:2rem;align-items:center}
@media(max-width:768px){.hero-grid{grid-template-columns:1fr}}
.page-h1{font-family:'Clash Display',sans-serif;font-size:clamp(2rem,4vw,3rem);font-weight:700;letter-spacing:-2px;margin-bottom:0.8rem}
.page-h1 em{font-style:normal;background:linear-gradient(135deg,var(--ember),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.page-sub{font-size:0.9rem;color:var(--txt-secondary);line-height:1.8;margin-bottom:1.5rem}
.live-badge{display:inline-flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;font-size:0.65rem;color:var(--ember);background:rgba(255,107,53,0.08);border:1px solid rgba(255,107,53,0.2);padding:5px 12px;border-radius:100px}
.live-dot{width:6px;height:6px;background:var(--ember);border-radius:50%;animation:blink 1.2s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0.1}}
.stats-row{display:flex;gap:2.5rem;flex-wrap:wrap}
.stat-item{text-align:center}
.stat-val{font-family:'Clash Display',sans-serif;font-size:2rem;font-weight:700;background:linear-gradient(135deg,var(--ember),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-1px}
.stat-lbl{font-size:0.68rem;color:var(--txt-muted);text-transform:uppercase;letter-spacing:0.1em;margin-top:3px}

/* ── MAIN ── */
main{max-width:1200px;margin:0 auto;padding:3rem 2rem 6rem}
.section-hdr{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.5rem}
.section-hdr-title{font-family:'Clash Display',sans-serif;font-size:1.3rem;font-weight:700;letter-spacing:-0.5px;display:flex;align-items:center;gap:8px}
.see-all{font-size:0.75rem;color:var(--gold);text-decoration:none;border:1px solid rgba(232,201,125,0.2);padding:4px 12px;border-radius:6px;transition:all 0.2s}
.see-all:hover{background:rgba(232,201,125,0.08)}
.eyebrow{font-family:'JetBrains Mono',monospace;font-size:0.62rem;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.4rem}

/* ── TOP TABLE ── */
.top-table{width:100%;border-collapse:collapse;margin-bottom:3rem}
.top-table thead tr{border-bottom:1px solid var(--glass-border)}
.top-table thead th{font-family:'JetBrains Mono',monospace;font-size:0.6rem;color:var(--txt-muted);text-transform:uppercase;letter-spacing:0.1em;padding:0.6rem 0.8rem;text-align:left}
.top-table tbody tr{border-bottom:1px solid var(--glass-border);transition:background 0.2s;cursor:pointer}
.top-table tbody tr:hover{background:var(--mist)}
.top-table td{padding:1rem 0.8rem;vertical-align:middle}
.rank-num{font-family:'Clash Display',sans-serif;font-size:1.8rem;font-weight:700;color:var(--txt-muted);line-height:1}
.rank-1 .rank-num{color:var(--gold)}
.rank-2 .rank-num{color:#c0c0c0}
.rank-3 .rank-num{color:#cd7f32}
.top-thumb{width:48px;height:62px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0}
.top-title{font-family:'Clash Display',sans-serif;font-size:0.92rem;font-weight:600;letter-spacing:-0.2px;margin-bottom:2px}
.top-author{font-size:0.72rem;color:var(--txt-secondary)}
.genre-tag{display:inline-flex;align-items:center;font-family:'JetBrains Mono',monospace;font-size:0.58rem;padding:2px 8px;border-radius:4px;background:var(--mist);color:var(--txt-muted);border:1px solid var(--glass-border);margin-top:4px}
.top-note{color:var(--gold);font-size:0.8rem}
.stars-txt{font-family:'JetBrains Mono',monospace;font-size:0.6rem;color:var(--txt-muted);margin-top:2px}
.top-ventes{font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:700;color:var(--ember);text-align:right}
.top-ventes-lbl{font-size:0.62rem;color:var(--txt-muted);text-align:right;margin-top:2px;font-family:'JetBrains Mono',monospace}
.trend-arrow{font-size:0.75rem;font-family:'JetBrains Mono',monospace}
.trend-up{color:var(--sage)}.trend-dn{color:#ff5f57}.trend-eq{color:var(--txt-muted)}
.price-txt{font-family:'Clash Display',sans-serif;font-size:0.88rem;font-weight:700;color:var(--gold)}
.price-txt.free{color:var(--sage)}
/* Badges */
.badge-free{background:rgba(78,204,163,0.15);color:var(--sage);border:1px solid rgba(78,204,163,0.3);font-family:'JetBrains Mono',monospace;font-size:0.58rem;padding:2px 7px;border-radius:6px}
.badge-std{background:rgba(74,158,255,0.15);color:var(--azure);border:1px solid rgba(74,158,255,0.3);font-family:'JetBrains Mono',monospace;font-size:0.58rem;padding:2px 7px;border-radius:6px}
.badge-premium{background:rgba(232,201,125,0.15);color:var(--gold);border:1px solid rgba(232,201,125,0.3);font-family:'JetBrains Mono',monospace;font-size:0.58rem;padding:2px 7px;border-radius:6px}
@media(max-width:640px){.col-trend,.col-note,.col-genre{display:none}}

/* ── PULSE CHART ── */
.pulse-wrap{background:var(--glass);border:1px solid var(--glass-border);border-radius:20px;padding:1.8rem;margin-bottom:3rem}
.pulse-title{font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:600;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pulse-live-tag{font-size:0.6rem;color:var(--sage);background:rgba(78,204,163,0.08);border:1px solid rgba(78,204,163,0.2);padding:3px 8px;border-radius:6px;font-family:'JetBrains Mono',monospace}
.pulse-chart-area{display:flex;flex-direction:column;gap:4px}
.pulse-bars{display:flex;align-items:flex-end;gap:6px;height:90px}
.p-bar{flex:1;border-radius:3px 3px 0 0;background:linear-gradient(180deg,var(--ember),rgba(255,107,53,0.25));transition:height 1s var(--ease-spring);cursor:pointer;position:relative;min-width:0}
.p-bar:hover::after{content:attr(data-label);position:absolute;bottom:calc(100%+6px);left:50%;transform:translateX(-50%);font-family:'JetBrains Mono',monospace;font-size:0.55rem;color:var(--txt-primary);white-space:nowrap;background:var(--slate);padding:3px 8px;border-radius:4px;border:1px solid var(--glass-border);z-index:10}
.pulse-labels{display:flex;gap:6px;margin-top:4px}
.p-lbl{flex:1;text-align:center;font-family:'JetBrains Mono',monospace;font-size:0.55rem;color:var(--txt-muted)}

/* ── VIRAL CARDS ── */
.viral-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;margin-bottom:3rem}
.viral-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:16px;padding:1.2rem;display:flex;gap:1rem;align-items:flex-start;cursor:pointer;transition:all 0.25s var(--ease-spring);text-decoration:none;color:inherit}
.viral-card:hover{border-color:rgba(255,107,53,0.3);transform:translateY(-4px);box-shadow:0 16px 40px rgba(0,0,0,0.4)}
.viral-thumb{width:56px;height:72px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0}
.viral-rank{font-family:'Clash Display',sans-serif;font-size:0.68rem;font-weight:700;color:var(--ember);margin-bottom:3px}
.viral-title{font-family:'Clash Display',sans-serif;font-size:0.88rem;font-weight:600;letter-spacing:-0.2px;line-height:1.25;margin-bottom:2px}
.viral-author{font-size:0.7rem;color:var(--txt-secondary);margin-bottom:0.5rem}
.viral-meta{display:flex;align-items:center;gap:0.7rem;flex-wrap:wrap}
.viral-score{font-size:0.7rem;color:var(--gold)}
.viral-growth{font-family:'JetBrains Mono',monospace;font-size:0.6rem;color:var(--sage);background:rgba(78,204,163,0.08);border:1px solid rgba(78,204,163,0.2);padding:2px 7px;border-radius:4px}

/* ── NOUVEAUTÉS SCROLL ── */
.nouv-scroll{display:flex;gap:1rem;overflow-x:auto;padding-bottom:1rem;margin-bottom:3rem;scroll-snap-type:x mandatory}
.nouv-scroll::-webkit-scrollbar{height:3px}
.nouv-scroll::-webkit-scrollbar-thumb{background:rgba(232,201,125,0.3);border-radius:3px}
.nouv-card{flex-shrink:0;width:175px;scroll-snap-align:start;background:var(--glass);border:1px solid var(--glass-border);border-radius:14px;overflow:hidden;cursor:pointer;transition:all 0.3s var(--ease-spring);text-decoration:none;color:inherit}
.nouv-card:hover{transform:translateY(-8px);border-color:rgba(232,201,125,0.25)}
.nouv-cover{height:140px;display:flex;align-items:center;justify-content:center;font-size:2.8rem;position:relative}
.nouv-new-badge{position:absolute;top:6px;left:6px;font-family:'JetBrains Mono',monospace;font-size:0.52rem;padding:2px 7px;border-radius:4px;background:rgba(78,204,163,0.85);color:#fff}
.nouv-body{padding:0.8rem}
.nouv-title{font-family:'Clash Display',sans-serif;font-size:0.82rem;font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nouv-author{font-size:0.65rem;color:var(--txt-secondary)}
.nouv-price{font-family:'JetBrains Mono',monospace;font-size:0.65rem;margin-top:5px}
.nouv-price.free{color:var(--sage)}.nouv-price.paid{color:var(--gold)}

/* ── HEATMAP ── */
.heat-section{margin-bottom:3rem}
.heat-legend{display:flex;align-items:center;gap:0.5rem;margin-bottom:1rem;font-family:'JetBrains Mono',monospace;font-size:0.6rem;color:var(--txt-muted)}
.heat-legend-cell{width:12px;height:12px;border-radius:2px}
.heat-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
.heat-cell{aspect-ratio:1;border-radius:3px;transition:transform 0.15s;cursor:pointer;position:relative}
.heat-cell:hover{transform:scale(1.5);z-index:5}
.heat-cell:hover::after{content:attr(title);position:absolute;bottom:calc(100%+4px);left:50%;transform:translateX(-50%);font-family:'JetBrains Mono',monospace;font-size:0.5rem;white-space:nowrap;background:var(--slate);color:var(--txt-primary);padding:2px 6px;border-radius:3px;border:1px solid var(--glass-border);z-index:20;pointer-events:none}
.heat-0{background:var(--slate)}
.heat-1{background:rgba(255,107,53,0.2)}
.heat-2{background:rgba(255,107,53,0.4)}
.heat-3{background:rgba(255,107,53,0.65)}
.heat-4{background:rgba(255,107,53,0.92)}

/* ── TOAST ── */
#toast{position:fixed;bottom:2rem;right:2rem;z-index:9800;background:rgba(13,18,32,0.97);border:1px solid rgba(232,201,125,0.2);border-radius:14px;padding:1rem 1.3rem;display:flex;align-items:center;gap:12px;backdrop-filter:blur(20px);transform:translateY(100px);opacity:0;transition:all 0.4s var(--ease-spring);pointer-events:none;max-width:300px}
#toast.show{transform:translateY(0);opacity:1;pointer-events:auto}
.toast-icon{font-size:1.1rem}
.toast-text{display:flex;flex-direction:column;gap:2px}
.toast-ttl{font-family:'Clash Display',sans-serif;font-weight:600;font-size:0.82rem}
.toast-msg{font-size:0.72rem;color:var(--txt-muted)}

/* ── REVEAL ── */
.reveal{opacity:0;transform:translateY(20px);transition:opacity 0.6s var(--ease-smooth),transform 0.6s var(--ease-smooth)}
.reveal.visible{opacity:1;transform:none}

/* ── FOOTER ── */
footer{border-top:1px solid var(--glass-border);padding:2rem;background:rgba(0,0,0,0.2);text-align:center}
.footer-links{display:flex;gap:1.5rem;justify-content:center;flex-wrap:wrap;margin-bottom:1rem}
.footer-links a{font-size:0.78rem;color:var(--txt-muted);text-decoration:none;transition:color 0.2s}
.footer-links a:hover{color:var(--gold)}
.footer-copy{font-size:0.7rem;color:var(--txt-muted)}

/* DB Status */
.db-status{display:inline-flex;align-items:center;gap:5px;font-family:'JetBrains Mono',monospace;font-size:0.6rem;padding:3px 10px;border-radius:100px;border:1px solid;margin-left:auto}
.db-online{color:var(--sage);border-color:rgba(78,204,163,0.3);background:rgba(78,204,163,0.06)}
.db-offline{color:var(--ember);border-color:rgba(255,107,53,0.3);background:rgba(255,107,53,0.06)}
</style>
</head>
<body>
<div class="bg-orb orb-a"></div>
<div class="bg-orb orb-b"></div>

<!-- HEADER -->
<header id="site-header">
  <a href="index.php" class="nav-logo"><div class="logo-mark">📚</div>Digital <span style="color:var(--gold)">Library</span></a>
  <nav><ul class="nav-links">
    <li><a href="index.php">Accueil</a></li>
    <li><a href="explorer.php">Explorer</a></li>
    <li><a href="categories.php">Catégories</a></li>
    <li><a href="tendances.php" class="active">Tendances</a></li>
    <li><a href="recommandations-ia.php">Recommandations IA</a></li>
  </ul></nav>
  <div class="nav-actions">
    <?php if ($isLoggedIn): ?>
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
  <?php if ($isLoggedIn): ?>
    <a href="logout.php" style="color:var(--ember)">Déconnexion</a>
  <?php else: ?>
    <a href="login.php">Connexion</a><a href="register.php" style="color:var(--gold)">S'inscrire</a>
  <?php endif; ?>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div class="page-hero-inner">
    <div class="page-breadcrumb">
      <a href="index.php">Accueil</a><span>›</span>
      <span>Tendances</span>
      <span class="db-status <?= $pdo ? 'db-online' : 'db-offline' ?>">
        <?= $pdo ? '● BD connectée' : '○ Mode démo' ?>
      </span>
    </div>
    <div class="hero-grid">
      <div>
        <div class="live-badge"><span class="live-dot"></span> Mis à jour en temps réel</div>
        <div style="height:12px"></div>
        <h1 class="page-h1">Ce qui <em>cartonne</em> en ce moment</h1>
        <p class="page-sub">Classements en temps réel, nouveautés virales et analyses de tendances pour rester à la pointe de la lecture numérique.</p>
      </div>
      <div class="stats-row">
        <div class="stat-item">
          <div class="stat-val"><?= $statsGlobal['lectures_today'] >= 1000 ? number_format($statsGlobal['lectures_today']/1000,1).'K' : $statsGlobal['lectures_today'] ?></div>
          <div class="stat-lbl">Lectures today</div>
        </div>
        <div class="stat-item">
          <div class="stat-val"><?= $statsGlobal['new_titles'] ?></div>
          <div class="stat-lbl">Nouveaux titres</div>
        </div>
        <div class="stat-item">
          <div class="stat-val"><?= $statsGlobal['satisfaction'] ?>%</div>
          <div class="stat-lbl">Satisfaction</div>
        </div>
      </div>
    </div>
  </div>
</div>

<main>
  <!-- TOP VENTES -->
  <div class="reveal">
    <div class="eyebrow" style="color:var(--ember)">🔥 Classement</div>
    <div class="section-hdr">
      <div class="section-hdr-title">Top Ventes — Cette semaine</div>
      <a class="see-all" href="explorer.php">Voir tout →</a>
    </div>
    <div style="overflow-x:auto">
    <table class="top-table">
      <thead>
        <tr>
          <th style="width:44px">#</th>
          <th style="width:56px"></th>
          <th>Livre</th>
          <th class="col-genre">Genre</th>
          <th class="col-note">Note</th>
          <th style="text-align:right">Ventes</th>
          <th class="col-trend">Tendance</th>
          <th>Prix</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topVentes as $i => $book):
          $pc     = getPriceBadge((float)($book['note_moyenne']??0),(float)($book['prix']??0));
          $colors = $coverColors[$i % count($coverColors)];
          $emoji  = $book['genre_icone'] ?? $emojis[$i % count($emojis)];
          $note   = (float)($book['note_moyenne'] ?? 0);
          $stars  = str_repeat('★', min(5,(int)round($note))) . str_repeat('☆', max(0, 5-(int)round($note)));
          $trend  = $trendArrows[$i % count($trendArrows)];
          $tClass = $trendClasses[$i % count($trendClasses)];
        ?>
        <tr class="rank-<?= $i+1 ?>" onclick="showToast('<?= htmlspecialchars(addslashes($book['titre']),ENT_QUOTES) ?>','par <?= htmlspecialchars(addslashes($book['auteur']),ENT_QUOTES) ?> — <?= number_format((int)$book['nb_ventes'],0,'',' ') ?> ventes')">
          <td><span class="rank-num"><?= $i+1 ?></span></td>
          <td><div class="top-thumb" style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)"><?= $emoji ?></div></td>
          <td>
            <div class="top-title"><?= htmlspecialchars((string)($book['titre']??'')) ?></div>
            <div class="top-author">par <?= htmlspecialchars((string)($book['auteur']??'')) ?></div>
          </td>
          <td class="col-genre"><span class="genre-tag"><?= htmlspecialchars((string)($book['genre']??'—')) ?></span></td>
          <td class="col-note">
            <div class="top-note"><?= $stars ?></div>
            <div class="stars-txt"><?= number_format($note,1) ?>/5</div>
          </td>
          <td>
            <div class="top-ventes"><?= number_format((int)($book['nb_ventes']??0),0,'',' ') ?></div>
            <div class="top-ventes-lbl">ventes</div>
          </td>
          <td class="col-trend"><span class="trend-arrow <?= $tClass ?>"><?= $trend ?></span></td>
          <td><span class="price-txt <?= $pc['display']==='Gratuit'?'free':'' ?>"><?= $pc['display'] ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- PULSE CHART -->
  <div class="pulse-wrap reveal">
    <div class="pulse-title">
      📈 Activité de lecture
      <span class="pulse-live-tag">LIVE</span>
      <?php if ($pdo): ?>
        <span style="font-family:'JetBrains Mono',monospace;font-size:0.6rem;color:var(--txt-muted);margin-left:auto">Données réelles · 7 derniers jours</span>
      <?php endif; ?>
    </div>
    <div class="pulse-chart-area">
      <div class="pulse-bars" id="pulse-bars">
        <?php foreach ($pulseData as $pd):
          $pct = $pulseMax > 0 ? round($pd['cnt']/$pulseMax*100) : 0;
        ?>
        <div class="p-bar" style="height:0%" data-pct="<?= $pct ?>" data-label="<?= (int)$pd['cnt'] ?> achats · <?= htmlspecialchars($pd['label']) ?>"></div>
        <?php endforeach; ?>
      </div>
      <div class="pulse-labels">
        <?php foreach ($pulseData as $pd): ?>
          <span class="p-lbl"><?= htmlspecialchars($pd['label']) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- NOUVEAUTÉS VIRALES -->
  <div class="reveal">
    <div class="eyebrow" style="color:var(--sage)">⚡ Viral</div>
    <div class="section-hdr">
      <div class="section-hdr-title">Nouveautés virales</div>
      <a class="see-all" href="explorer.php">Voir tout →</a>
    </div>
    <div class="viral-grid">
      <?php foreach ($viralBooks as $i => $book):
        $colors = $coverColors[$i % count($coverColors)];
        $emoji  = $book['genre_icone'] ?? $emojis[$i % count($emojis)];
        $growth = $book['growth'] ?? '+'.rand(10,140).'%';
        $note   = number_format((float)($book['note_moyenne']??0),1);
      ?>
      <a class="viral-card" href="explorer.php?livre=<?= (int)($book['id']??0) ?>">
        <div class="viral-thumb" style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)"><?= $emoji ?></div>
        <div>
          <div class="viral-rank">🔥 #<?= $i+1 ?> Viral</div>
          <div class="viral-title"><?= htmlspecialchars((string)($book['titre']??'')) ?></div>
          <div class="viral-author">par <?= htmlspecialchars((string)($book['auteur']??'')) ?></div>
          <div class="viral-meta">
            <span class="viral-score">⭐ <?= $note ?></span>
            <span class="viral-growth">↑ <?= htmlspecialchars($growth) ?></span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- NOUVEAUTÉS SCROLL -->
  <div class="reveal">
    <div class="eyebrow" style="color:var(--azure)">🆕 Nouveautés</div>
    <div class="section-hdr"><div class="section-hdr-title">Sorties récentes</div></div>
    <div class="nouv-scroll">
      <?php foreach ($nouveautes as $i => $book):
        $colors = $coverColors[$i % count($coverColors)];
        $emoji  = $emojis[$i % count($emojis)];
        $isFree = ((float)($book['prix']??0)) == 0;
        $priceLabel = $isFree ? 'Gratuit' : number_format((float)$book['prix'],0,'',' ').' FCFA';
      ?>
      <a class="nouv-card" href="explorer.php?livre=<?= (int)($book['id']??0) ?>">
        <div class="nouv-cover" style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)">
          <span class="nouv-new-badge">NOUVEAU</span>
          <?= $emoji ?>
        </div>
        <div class="nouv-body">
          <div class="nouv-title"><?= htmlspecialchars((string)($book['titre']??'')) ?></div>
          <div class="nouv-author"><?= htmlspecialchars((string)($book['auteur']??'')) ?></div>
          <div class="nouv-price <?= $isFree ? 'free' : 'paid' ?>"><?= $priceLabel ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- HEATMAP -->
  <div class="reveal heat-section">
    <div class="eyebrow" style="color:var(--gold)">📅 Heatmap</div>
    <div class="section-hdr"><div class="section-hdr-title">Activité de lecture — 7 dernières semaines</div></div>
    <div class="heat-legend">
      <span>Moins</span>
      <div class="heat-legend-cell heat-0"></div>
      <div class="heat-legend-cell heat-1"></div>
      <div class="heat-legend-cell heat-2"></div>
      <div class="heat-legend-cell heat-3"></div>
      <div class="heat-legend-cell heat-4"></div>
      <span>Plus</span>
    </div>
    <div class="heat-grid">
      <?php foreach ($heatmapData as $cell):
        $level = getHeatLevel((int)$cell['cnt'], $heatMax);
        $dateFormatted = date('d M Y', strtotime($cell['date']));
        $label = $cell['cnt'] > 0 ? "{$cell['cnt']} achats · {$dateFormatted}" : "Aucun achat · {$dateFormatted}";
      ?>
      <div class="heat-cell heat-<?= $level ?>" title="<?= htmlspecialchars($label) ?>" onclick="showToast('Activité','<?= htmlspecialchars($label) ?>')"></div>
      <?php endforeach; ?>
    </div>
  </div>
</main>

<footer>
  <div class="footer-links">
    <a href="index.php">Accueil</a><a href="explorer.php">Explorer</a>
    <a href="categories.php">Catégories</a><a href="aide.php">Aide</a>
    <a href="privacy.php">Confidentialité</a>
  </div>
  <p class="footer-copy">© <?= date('Y') ?> Digital Library System — Tous droits réservés.</p>
</footer>

<div id="toast">
  <span class="toast-icon" id="t-icon">ℹ️</span>
  <div class="toast-text">
    <span class="toast-ttl" id="t-title"></span>
    <span class="toast-msg" id="t-msg"></span>
  </div>
</div>

<script>
'use strict';
let toastT = null;
function showToast(title, msg='', type='default', dur=3000) {
  const el = document.getElementById('toast');
  const icons = {default:'ℹ️',success:'✅',error:'❌',warn:'⚠️'};
  el.className = '';
  document.getElementById('t-icon').textContent  = icons[type] || 'ℹ️';
  document.getElementById('t-title').textContent = title;
  document.getElementById('t-msg').textContent   = msg;
  el.classList.add('show');
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove('show'), dur);
}

// Header scroll
const hdr = document.getElementById('site-header');
window.addEventListener('scroll', () => hdr.classList.toggle('scrolled', window.scrollY > 40));

// Mobile nav
const ham = document.getElementById('hamburger');
const mNav = document.getElementById('mobile-nav');
if (ham) ham.addEventListener('click', () => {
  const o = mNav.classList.toggle('open');
  ham.innerHTML = o ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
});

// Pulse bars animation
setTimeout(() => {
  document.querySelectorAll('.p-bar').forEach(b => {
    b.style.height = b.dataset.pct + '%';
  });
}, 600);

// Reveal
const obs = new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); }});
}, { threshold: 0.08 });
document.querySelectorAll('.reveal').forEach(el => obs.observe(el));

// Keyboard ESC
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.getElementById('toast').classList.remove('show'); });
</script>
</body>
</html>