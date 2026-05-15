<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — books/my_books.php  VERSION 4.0                 ║
 * ║  Espace personnel · Lecture · Achats · Publications · Stats         ║
 * ║  Design immersif Netflix × Kindle × Spotify                         ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';
$userName = trim(($_SESSION['user_prenom'] ?? '') . ' ' . ($_SESSION['user_nom'] ?? ''));
if (!$userName) $userName = $_SESSION['user_name'] ?? 'Utilisateur';
$firstName    = explode(' ', $userName)[0];
$avatarLetter = strtoupper(substr($userName, 0, 1)) ?: 'U';

// ── CSRF ─────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Connexion PDO ─────────────────────────────────────────────────────
$pdo = null;
foreach ([
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/config/config.php',
    __DIR__ . '/../includes/config.php',
] as $cfgPath) {
    if (file_exists($cfgPath)) { require_once $cfgPath; break; }
}

// Charger le centre de données si disponible
$seedPath = __DIR__ . '/seed.php';
if (file_exists($seedPath)) {
    define('BOOKS_SEED_NO_AUTORUN', true);
    require_once $seedPath;
}

if (!isset($pdo) || $pdo === null) {
    $h = defined('DB_HOST') ? DB_HOST : 'localhost';
    $n = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $u = defined('DB_USER') ? DB_USER : 'root';
    $p = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4", $u, $p, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[my_books] PDO: ' . $e->getMessage());
    }
}

// ── Auto-création table favoris ───────────────────────────────────────
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS favoris (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fav (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}
}

// ── Helpers ───────────────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmtNum(int $n): string { return number_format($n, 0, ',', ' '); }
function fmtFCFA(float $v): string { return $v > 0 ? number_format($v, 0, ',', ' ') . ' FCFA' : 'Gratuit'; }
function timeAgoMB(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'Il y a quelques secondes';
    if ($diff < 3600) return 'Il y a ' . floor($diff/60) . ' min';
    if ($diff < 86400) return 'Il y a ' . floor($diff/3600) . 'h';
    if ($diff < 604800) return 'Il y a ' . floor($diff/86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}

// ── Récupérer les données ──────────────────────────────────────────────
$booksRead     = [];
$booksPurchased = [];
$booksPublished = [];
$booksDraft     = [];
$booksFavs      = [];
$booksDownloads = [];
$userStats      = [];
$notifications  = [];

if ($pdo) {
    try {
        // 1. Livres lus (lecture_progression)
        $stmt = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, l.pages, l.access_type, l.note_moyenne,
                   c.nom AS categorie, c.icone AS cat_icone,
                   lp.page_actuelle, lp.pourcentage, lp.updated_at
            FROM lecture_progression lp
            JOIN livres l ON l.id = lp.livre_id
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE lp.user_id = ?
            ORDER BY lp.updated_at DESC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        $booksRead = $stmt->fetchAll();

        // 2. Livres achetés
        $stmt = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, l.pages, l.access_type, l.fichier_pdf,
                   l.note_moyenne, c.nom AS categorie, c.icone AS cat_icone,
                   a.montant, a.methode, a.reference, a.created_at AS date_achat
            FROM achats a
            JOIN livres l ON l.id = a.livre_id
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE a.user_id = ? AND a.statut = 'confirme'
            ORDER BY a.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $booksPurchased = $stmt->fetchAll();

        // 3. Livres publiés (journaliste/admin)
        if (in_array($userRole, ['journaliste', 'admin'])) {
            $stmt = $pdo->prepare("
                SELECT l.*, c.nom AS categorie, c.icone AS cat_icone,
                       (SELECT COUNT(*) FROM achats a WHERE a.livre_id = l.id AND a.statut='confirme') AS nb_achats_reel,
                       (SELECT COALESCE(SUM(a.montant),0) FROM achats a WHERE a.livre_id = l.id AND a.statut='confirme') AS revenus
                FROM livres l
                LEFT JOIN categories c ON c.id = l.categorie_id
                WHERE l.ajoute_par = ? AND l.statut != 'archive'
                ORDER BY l.created_at DESC
                LIMIT 30
            ");
            $stmt->execute([$userId]);
            $booksPublished = $stmt->fetchAll();

            // Brouillons / archivés
            $stmt = $pdo->prepare("
                SELECT l.*, c.nom AS categorie, c.icone AS cat_icone
                FROM livres l
                LEFT JOIN categories c ON c.id = l.categorie_id
                WHERE l.ajoute_par = ?
                ORDER BY l.updated_at DESC
            ");
            $stmt->execute([$userId]);
            $booksDraft = $stmt->fetchAll();
        }

        // 4. Favoris
        $stmt = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, l.prix, l.access_type, l.note_moyenne,
                   c.nom AS categorie, c.icone AS cat_icone, f.created_at AS fav_date
            FROM favoris f
            JOIN livres l ON l.id = f.livre_id
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC
            LIMIT 30
        ");
        $stmt->execute([$userId]);
        $booksFavs = $stmt->fetchAll();

        // 5. Téléchargements
        $stmt = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, l.access_type, c.nom AS categorie,
                   ud.count AS nb_dl, ud.last_dl_at
            FROM user_downloads ud
            JOIN livres l ON l.id = ud.livre_id
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE ud.user_id = ?
            ORDER BY ud.last_dl_at DESC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        $booksDownloads = $stmt->fetchAll();

        // 6. Stats personnelles
        $userStats = [
            'nb_lus'       => count($booksRead),
            'nb_achats'    => count($booksPurchased),
            'nb_publiés'   => count($booksPublished),
            'nb_favoris'   => count($booksFavs),
            'nb_dl'        => (int)$pdo->query("SELECT COALESCE(SUM(count),0) FROM user_downloads WHERE user_id=$userId")->fetchColumn(),
            'total_depense'=> (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE user_id=$userId AND statut='confirme'")->fetchColumn(),
            'pages_lues'   => (int)$pdo->query("SELECT COALESCE(SUM(page_actuelle),0) FROM lecture_progression WHERE user_id=$userId")->fetchColumn(),
            'tps_lecture'  => 0,
        ];
        $userStats['tps_lecture'] = round($userStats['pages_lues'] / 250 * 60); // ~250 mots/page, 200 mots/min

        if (in_array($userRole, ['journaliste', 'admin'])) {
            $userStats['revenus_total'] = (float)$pdo->query("SELECT COALESCE(SUM(a.montant),0) FROM achats a JOIN livres l ON l.id=a.livre_id WHERE l.ajoute_par=$userId AND a.statut='confirme'")->fetchColumn();
            $userStats['nb_lecteurs']   = (int)$pdo->query("SELECT COUNT(DISTINCT a.user_id) FROM achats a JOIN livres l ON l.id=a.livre_id WHERE l.ajoute_par=$userId AND a.statut='confirme'")->fetchColumn();
        }

        // 7. Bonus
        try {
            $stB = $pdo->prepare("SELECT achat_count, bonus_restant, bonus_total FROM user_bonus WHERE user_id = ?");
            $stB->execute([$userId]);
            $bonusRow = $stB->fetch() ?: ['achat_count'=>0,'bonus_restant'=>0,'bonus_total'=>0];
        } catch (PDOException) { $bonusRow = ['achat_count'=>0,'bonus_restant'=>0,'bonus_total'=>0]; }

        // 8. Notifications
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC LIMIT 8");
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll();

    } catch (PDOException $e) {
        error_log('[my_books] Query error: ' . $e->getMessage());
    }
} else {
    // Fallback demo data si pas de BDD
    $booksRead = function_exists('getFallbackBooks') ? array_slice(getFallbackBooks(5), 0, 5) : [];
    $userStats = ['nb_lus'=>0,'nb_achats'=>0,'nb_publiés'=>0,'nb_favoris'=>0,'nb_dl'=>0,'total_depense'=>0,'pages_lues'=>0,'tps_lecture'=>0];
    $bonusRow  = ['achat_count'=>0,'bonus_restant'=>0,'bonus_total'=>0];
}

// Emojis couvertures
$coverEmojis = ['📚','📘','📗','📙','📕','📓','🔮','💡','🌊','🏔️','⚡','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌍','🎯','🌙','🎨','🌸'];
$palettes    = [['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],['#0d3c2a','#1a7a5a'],['#2a0d1a','#6b1a3a']];

function getCover(int $idx, array $e, array $p): string {
    return '<div class="bk-cover-bg" style="background:linear-gradient(135deg,'.$p[$idx%count($p)][0].','.$p[$idx%count($p)][1].')"></div><span class="bk-emoji">'.$e[$idx%count($e)].'</span>';
}

function accessBadge(string $type): string {
    return match($type) {
        'premium'  => '<span class="badge badge-premium">PREMIUM</span>',
        'gratuit'  => '<span class="badge badge-free">GRATUIT</span>',
        default    => '<span class="badge badge-std">STANDARD</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes Livres — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ═══════════════════════════════════════════════════════════════
   RESET & VARIABLES
═══════════════════════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#05080f; --surface:#0b1020; --card:rgba(255,255,255,.03);
  --card-hov:rgba(255,255,255,.055); --border:rgba(255,255,255,.07);
  --border-glow:rgba(0,212,255,.3);
  --gold:#e8c97d; --cyan:#00d4ff; --violet:#7c3aed; --sage:#00ffaa;
  --rose:#f43f5e; --amber:#f59e0b; --orange:#f97316;
  --txt:#eef2ff; --txt2:rgba(238,242,255,.55); --txt3:rgba(238,242,255,.28);
  --sbar:260px; --sbar-c:68px; --top:58px;
  --ease:cubic-bezier(.34,1.56,.64,1); --smooth:cubic-bezier(.25,.46,.45,.94);
  --r:10px; --rl:18px; --rxl:26px;
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',system-ui,sans-serif;background:var(--ink);color:var(--txt);overflow-x:hidden;min-height:100vh}
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(0,212,255,.25);border-radius:3px}
::selection{background:rgba(0,212,255,.25)}

/* ═══════ BG ═══════ */
.orb{position:fixed;border-radius:50%;filter:blur(120px);pointer-events:none;z-index:-1;animation:orb 20s ease-in-out infinite}
.o1{width:600px;height:600px;background:rgba(0,212,255,.035);top:-150px;left:-100px}
.o2{width:400px;height:400px;background:rgba(124,58,237,.04);bottom:-80px;right:-80px;animation-delay:-8s}
@keyframes orb{0%,100%{transform:translate(0,0)}50%{transform:translate(30px,-20px)}}

/* ═══════ LAYOUT ═══════ */
.layout{display:flex;min-height:100vh}
#sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sbar);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:width .3s var(--smooth),transform .3s ease;overflow:hidden}
#sidebar.collapsed{width:var(--sbar-c)}
.main{margin-left:var(--sbar);flex:1;display:flex;flex-direction:column;transition:margin-left .3s var(--smooth)}
.main.collapsed{margin-left:var(--sbar-c)}

/* ═══════ SIDEBAR ═══════ */
.sb-brand{height:var(--top);display:flex;align-items:center;gap:10px;padding:0 14px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;box-shadow:0 0 20px rgba(0,212,255,.25)}
.sb-name{font-family:'Syne',sans-serif;font-weight:800;font-size:.88rem;white-space:nowrap;overflow:hidden;transition:opacity .2s}
.sb-name em{color:var(--cyan);font-style:normal}
#sidebar.collapsed .sb-name{opacity:0;pointer-events:none}

.sb-user{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-av{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,var(--gold),var(--orange));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.85rem;color:#000;flex-shrink:0}
.sb-uinfo{overflow:hidden;transition:opacity .2s}
#sidebar.collapsed .sb-uinfo{opacity:0;pointer-events:none}
.sb-uname{font-family:'Syne',sans-serif;font-weight:700;font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-urole{font-family:'Space Mono',monospace;font-size:.58rem;color:var(--cyan);text-transform:uppercase;margin-top:2px}

.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.sb-sec{font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.12em;text-transform:uppercase;color:var(--txt3);padding:8px 14px 2px;white-space:nowrap;overflow:hidden;transition:opacity .2s}
#sidebar.collapsed .sb-sec{opacity:0}
.sb-item{display:flex;align-items:center;gap:10px;padding:8px 14px;margin:1px 8px;border-radius:var(--r);cursor:pointer;text-decoration:none;color:var(--txt2);font-size:.8rem;font-weight:500;transition:all .18s;white-space:nowrap;overflow:hidden;position:relative}
.sb-item:hover{color:var(--txt);background:var(--card-hov)}
.sb-item.active{color:var(--cyan);background:rgba(0,212,255,.07);border:1px solid rgba(0,212,255,.12)}
.sb-item.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:16px;background:var(--cyan);border-radius:0 3px 3px 0;box-shadow:0 0 8px var(--cyan)}
.sb-ico{font-size:1rem;width:18px;text-align:center;flex-shrink:0}
.sb-lbl{transition:opacity .2s}
#sidebar.collapsed .sb-lbl{opacity:0}

.sb-foot{padding:8px;border-top:1px solid var(--border)}
.btn-collapse{width:100%;display:flex;align-items:center;gap:10px;padding:7px 6px;border-radius:var(--r);background:none;border:none;color:var(--txt3);cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.77rem;transition:all .18s}
.btn-collapse:hover{color:var(--txt);background:var(--card-hov)}
.cico{font-size:.9rem;flex-shrink:0;width:18px;text-align:center;transition:transform .3s}
#sidebar.collapsed .cico{transform:rotate(180deg)}
.clbl{transition:opacity .2s;white-space:nowrap}
#sidebar.collapsed .clbl{opacity:0}

/* ═══════ TOPBAR ═══════ */
#topbar{height:var(--top);display:flex;align-items:center;gap:.8rem;padding:0 1.4rem;background:rgba(5,8,15,.88);backdrop-filter:blur(24px);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:200}
.top-bc{display:flex;align-items:center;gap:6px;font-size:.75rem;color:var(--txt2)}
.top-bc a{color:var(--txt2);text-decoration:none;transition:color .2s}.top-bc a:hover{color:var(--cyan)}
.bc-s{opacity:.35}
.top-sp{flex:1}
.top-search{display:flex;align-items:center;gap:6px;background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:6px 10px;width:200px;transition:border-color .2s,box-shadow .2s}
.top-search:focus-within{border-color:var(--border-glow);box-shadow:0 0 0 3px rgba(0,212,255,.07)}
.top-search input{background:none;border:none;outline:none;color:var(--txt);font-size:.76rem;font-family:'DM Sans',sans-serif;width:100%}
.top-search input::placeholder{color:var(--txt3)}
.top-btn{width:32px;height:32px;border-radius:var(--r);background:var(--card);border:1px solid var(--border);color:var(--txt2);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.9rem;transition:all .18s;text-decoration:none;position:relative}
.top-btn:hover{color:var(--txt);border-color:rgba(255,255,255,.12)}
.notif-dot{position:absolute;top:-2px;right:-2px;width:8px;height:8px;background:var(--rose);border-radius:50%;border:2px solid var(--ink)}
.top-user{display:flex;align-items:center;gap:6px;padding:4px 8px;border-radius:var(--r);background:var(--card);border:1px solid var(--border);text-decoration:none;color:var(--txt);font-size:.73rem;font-weight:600;transition:border-color .2s}
.top-user:hover{border-color:rgba(0,212,255,.3)}
.top-av{width:24px;height:24px;border-radius:7px;background:linear-gradient(135deg,var(--gold),var(--orange));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.65rem;color:#000;flex-shrink:0}
#hamburger{display:none;background:none;border:none;color:var(--txt);font-size:1.2rem;cursor:pointer;padding:4px}

/* ═══════ PAGE ═══════ */
.page{flex:1;padding:1.6rem 1.8rem 5rem;max-width:1400px;width:100%;margin:0 auto}

/* ── HERO HEADER ── */
.hero-header{background:linear-gradient(135deg,rgba(0,212,255,.05),rgba(124,58,237,.07),rgba(232,201,125,.04));border:1px solid rgba(0,212,255,.1);border-radius:var(--rxl);padding:1.8rem 2rem;margin-bottom:1.6rem;position:relative;overflow:hidden;animation:fadeUp .4s ease both}
.hero-header::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--gold))}
.hero-header::after{content:'';position:absolute;right:-60px;top:-60px;width:200px;height:200px;background:radial-gradient(circle,rgba(0,212,255,.06) 0%,transparent 70%);pointer-events:none}
.hero-content{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.hero-title{font-family:'Syne',sans-serif;font-size:clamp(1.5rem,3vw,2.2rem);font-weight:800;letter-spacing:-1px;line-height:1.1}
.hero-title .hl{background:linear-gradient(135deg,var(--gold),var(--orange));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hero-sub{font-size:.8rem;color:var(--txt2);margin-top:4px;display:flex;align-items:center;gap:8px}
.live-dot{width:6px;height:6px;background:var(--sage);border-radius:50%;animation:pulse 1.4s ease-in-out infinite;flex-shrink:0}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(0,255,170,.5)}50%{box-shadow:0 0 0 5px rgba(0,255,170,0)}}
.hero-actions{display:flex;gap:.6rem;flex-wrap:wrap}

/* ── STATS GRID ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.9rem;margin-bottom:1.6rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--rl);padding:1.2rem 1.3rem;transition:transform .22s,border-color .22s,box-shadow .22s;position:relative;overflow:hidden;animation:fadeUp .5s ease both;cursor:default}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--ac1,transparent),var(--ac2,transparent));opacity:0;transition:opacity .3s}
.stat-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(0,0,0,.35)}
.stat-card:hover::before{opacity:1}
.stat-card:nth-child(1){--ac1:var(--cyan);--ac2:var(--violet);animation-delay:.04s}
.stat-card:nth-child(2){--ac1:var(--gold);--ac2:var(--orange);animation-delay:.08s}
.stat-card:nth-child(3){--ac1:var(--sage);--ac2:var(--cyan);animation-delay:.12s}
.stat-card:nth-child(4){--ac1:var(--violet);--ac2:var(--rose);animation-delay:.16s}
.stat-card:nth-child(5){--ac1:var(--rose);--ac2:var(--amber);animation-delay:.2s}
.stat-card:nth-child(6){--ac1:var(--amber);--ac2:var(--gold);animation-delay:.24s}
.stat-card:nth-child(7){--ac1:var(--cyan);--ac2:var(--sage);animation-delay:.28s}
.stat-card:nth-child(8){--ac1:var(--violet);--ac2:var(--cyan);animation-delay:.32s}
.sc-icon{font-size:1.4rem;margin-bottom:.7rem}
.sc-val{font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--ac1,var(--txt)),var(--ac2,var(--txt2)));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1}
.sc-lbl{font-size:.7rem;color:var(--txt2);margin-top:4px}

/* ── TABS ── */
.tabs-wrap{display:flex;align-items:center;gap:.4rem;margin-bottom:1.4rem;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:.8rem}
.tab-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 16px;border-radius:var(--r);border:1px solid transparent;background:transparent;color:var(--txt2);font-size:.78rem;font-weight:600;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif;white-space:nowrap}
.tab-btn:hover{color:var(--txt);background:var(--card)}
.tab-btn.active{color:var(--cyan);background:rgba(0,212,255,.08);border-color:rgba(0,212,255,.2)}
.tab-badge{font-family:'Space Mono',monospace;font-size:.55rem;padding:1px 5px;border-radius:100px;background:rgba(0,212,255,.15);color:var(--cyan);margin-left:2px}
.tab-content{display:none;animation:fadeUp .3s ease both}
.tab-content.active{display:block}

/* ── SECTION HEADER ── */
.sec-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;flex-wrap:wrap;gap:.6rem}
.sec-title{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:7px}
.sec-right{display:flex;align-items:center;gap:.4rem}
.sort-select{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:5px 10px;color:var(--txt2);font-size:.73rem;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;transition:border-color .2s}
.sort-select:hover{border-color:rgba(0,212,255,.25)}

/* ── BOOK CARDS GRID ── */
.bk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:1rem}
.bk-card{background:var(--card);border:1px solid var(--border);border-radius:var(--rl);overflow:hidden;transition:transform .25s var(--ease),border-color .25s,box-shadow .25s;animation:fadeUp .4s ease both;position:relative}
.bk-card:hover{transform:translateY(-7px) scale(1.02);border-color:rgba(0,212,255,.2);box-shadow:0 20px 50px rgba(0,0,0,.5),0 0 20px rgba(0,212,255,.07)}

/* Cover */
.bk-cover{height:148px;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden}
.bk-cover-bg{position:absolute;inset:0}
.bk-emoji{font-size:2.8rem;position:relative;z-index:1;filter:drop-shadow(0 4px 12px rgba(0,0,0,.5));transition:transform .3s var(--ease)}
.bk-card:hover .bk-emoji{transform:scale(1.15) rotate(-5deg)}
.bk-vignette{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(5,8,15,.85));z-index:2}
.bk-badges{position:absolute;top:7px;right:7px;z-index:3;display:flex;flex-direction:column;gap:3px;align-items:flex-end}
.bk-tag-left{position:absolute;top:7px;left:7px;z-index:3}

/* Body */
.bk-body{padding:.8rem .9rem}
.bk-cat{font-family:'Space Mono',monospace;font-size:.54rem;color:var(--cyan);letter-spacing:.08em;text-transform:uppercase;margin-bottom:2px}
.bk-title{font-family:'Syne',sans-serif;font-size:.82rem;font-weight:700;letter-spacing:-.2px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.bk-author{font-size:.68rem;color:var(--txt2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.5rem}
.bk-meta{display:flex;align-items:center;justify-content:space-between;font-size:.65rem;margin-bottom:.5rem}
.bk-stars{color:var(--gold)}
.bk-pages{color:var(--txt3);font-family:'Space Mono',monospace;font-size:.58rem}

/* Progress bar */
.prog-wrap{background:rgba(255,255,255,.06);border-radius:100px;height:4px;overflow:hidden;margin-bottom:.3rem}
.prog-bar{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--cyan),var(--violet));transition:width 1s var(--smooth);box-shadow:0 0 6px rgba(0,212,255,.4)}
.prog-bar.done{background:linear-gradient(90deg,var(--sage),#00a882)}
.prog-bar.abandon{background:linear-gradient(90deg,var(--rose),var(--amber))}
.prog-pct{font-family:'Space Mono',monospace;font-size:.58rem;color:var(--txt3);text-align:right}

/* Card footer */
.bk-foot{border-top:1px solid var(--border);padding:.6rem .9rem;display:flex;align-items:center;gap:.4rem}

/* Hover overlay */
.bk-overlay{position:absolute;inset:0;background:rgba(5,8,15,.92);z-index:10;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6rem;padding:.9rem;opacity:0;transition:opacity .2s;border-radius:var(--rl)}
.bk-card:hover .bk-overlay{opacity:1}
.ov-title{font-family:'Syne',sans-serif;font-size:.78rem;font-weight:700;text-align:center;line-height:1.25}
.ov-meta{font-size:.63rem;color:var(--txt2)}
.ov-btns{display:flex;gap:.4rem;width:100%;margin-top:.3rem}
.ov-btn{flex:1;padding:7px 0;border-radius:var(--r);border:none;font-size:.68rem;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:opacity .2s;display:flex;align-items:center;justify-content:center;gap:4px}
.ov-btn.primary{background:linear-gradient(135deg,var(--cyan),var(--violet));color:var(--ink)}
.ov-btn.primary:hover{opacity:.85}
.ov-btn.ghost{background:var(--card);border:1px solid var(--border);color:var(--txt2)}
.ov-btn.ghost:hover{border-color:rgba(0,212,255,.3);color:var(--cyan)}
.ov-btn.danger{background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.2);color:var(--rose)}
.ov-btn.danger:hover{background:rgba(244,63,94,.18)}
.ov-btn.fav-on{background:rgba(155,89,182,.1);border:1px solid rgba(155,89,182,.2);color:#a78bfa}

/* ── BADGES ── */
.badge{font-family:'Space Mono',monospace;font-size:.52rem;padding:2px 7px;border-radius:100px;letter-spacing:.04em;display:inline-flex;align-items:center;gap:2px}
.badge-premium{background:rgba(232,201,125,.15);color:var(--gold);border:1px solid rgba(232,201,125,.25)}
.badge-std{background:rgba(0,212,255,.12);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.badge-free{background:rgba(0,255,170,.1);color:var(--sage);border:1px solid rgba(0,255,170,.2)}
.badge-done{background:rgba(0,255,170,.1);color:var(--sage);border:1px solid rgba(0,255,170,.2);font-size:.5rem}
.badge-wip{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2);font-size:.5rem}
.badge-abandon{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2);font-size:.5rem}
.badge-bs{background:rgba(249,115,22,.15);color:var(--orange);border:1px solid rgba(249,115,22,.2);font-size:.5rem}
.badge-featured{background:rgba(0,212,255,.12);color:var(--cyan);border:1px solid rgba(0,212,255,.2);font-size:.5rem}

/* ── PURCHASE LIST ── */
.purch-list{display:flex;flex-direction:column;gap:.7rem}
.purch-item{background:var(--card);border:1px solid var(--border);border-radius:var(--rl);padding:1rem 1.2rem;display:flex;align-items:center;gap:1rem;transition:border-color .2s,box-shadow .2s;animation:fadeUp .4s ease both}
.purch-item:hover{border-color:rgba(0,212,255,.15);box-shadow:0 8px 24px rgba(0,0,0,.25)}
.purch-cover{width:52px;height:64px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;position:relative;overflow:hidden}
.purch-cover .bk-cover-bg{border-radius:8px}
.purch-info{flex:1;min-width:0}
.purch-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.purch-author{font-size:.7rem;color:var(--txt2);margin-top:1px}
.purch-tags{display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.4rem}
.purch-meta{text-align:right;flex-shrink:0}
.purch-price{font-family:'Syne',sans-serif;font-weight:800;font-size:1rem;color:var(--gold)}
.purch-date{font-family:'Space Mono',monospace;font-size:.6rem;color:var(--txt3);margin-top:2px}
.purch-ref{font-family:'Space Mono',monospace;font-size:.55rem;color:var(--txt3);margin-top:1px}
.purch-btns{display:flex;gap:.3rem;flex-shrink:0}

/* ── PUBLISHED TABLE ── */
.pub-table{width:100%;border-collapse:collapse}
.pub-table th{text-align:left;font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.08em;text-transform:uppercase;color:var(--txt3);padding:7px 10px;border-bottom:1px solid var(--border)}
.pub-table td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;font-size:.78rem;color:var(--txt2);transition:background .12s}
.pub-table tr:last-child td{border-bottom:none}
.pub-table tbody tr:hover td{background:var(--card-hov)}
.pub-td-p{font-weight:600;color:var(--txt)}
.pub-td-mini{font-family:'Space Mono',monospace;font-size:.63rem}
.pub-cover-sm{width:36px;height:44px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;position:relative;overflow:hidden}

/* ── FAV LIST ── */
.fav-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:.9rem}

/* ── DOWNLOAD LIST ── */
.dl-list{display:flex;flex-direction:column;gap:.6rem}
.dl-item{background:var(--card);border:1px solid var(--border);border-radius:var(--rl);padding:.9rem 1.2rem;display:flex;align-items:center;gap.9rem;gap:.9rem;transition:border-color .2s;animation:fadeUp .4s ease both}
.dl-item:hover{border-color:rgba(0,212,255,.15)}
.dl-info{flex:1;min-width:0}
.dl-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dl-sub{font-size:.68rem;color:var(--txt2);margin-top:1px}
.dl-count{font-family:'Space Mono',monospace;font-size:.7rem;color:var(--cyan);flex-shrink:0}

/* ── CHART ── */
.chart-card{background:var(--card);border:1px solid var(--border);border-radius:var(--rl);padding:1.2rem 1.4rem;margin-bottom:1.2rem;animation:fadeUp .5s ease both}
.chart-title{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:7px}
.chart-wrap{position:relative;height:200px}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:3rem 2rem;color:var(--txt2)}
.empty-icon{font-size:3rem;display:block;margin-bottom.8rem;margin-bottom:.8rem;opacity:.3}
.empty-title{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:700;margin-bottom:.3rem;color:var(--txt2)}
.empty-sub{font-size:.78rem;color:var(--txt3)}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:var(--r);font-family:'Syne',sans-serif;font-size:.76rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;border:none;white-space:nowrap}
.btn-sm{padding:4px 9px;font-size:.68rem}
.btn-xs{padding:3px 7px;font-size:.62rem}
.btn-prim{background:linear-gradient(135deg,var(--cyan),var(--violet));color:var(--ink);box-shadow:0 4px 14px rgba(0,212,255,.2)}
.btn-prim:hover{opacity:.87;transform:translateY(-1px)}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--txt2)}
.btn-ghost:hover{color:var(--txt);border-color:rgba(255,255,255,.14);background:var(--card-hov)}
.btn-danger{background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.2);color:var(--rose)}
.btn-danger:hover{background:rgba(244,63,94,.18)}
.btn-success{background:rgba(0,255,170,.1);border:1px solid rgba(0,255,170,.2);color:var(--sage)}
.btn-success:hover{background:rgba(0,255,170,.16)}
.btn-gold{background:rgba(232,201,125,.1);border:1px solid rgba(232,201,125,.25);color:var(--gold)}
.btn-gold:hover{background:rgba(232,201,125,.18)}

/* ── MODALS ── */
.modal-bg{position:fixed;inset:0;background:rgba(5,8,15,.88);backdrop-filter:blur(12px);z-index:900;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
.modal-bg.open{opacity:1;visibility:visible}
.modal-box{background:linear-gradient(145deg,var(--surface),#0a0e1a);border:1px solid var(--border);border-radius:var(--rxl);padding:1.8rem;max-width:460px;width:100%;box-shadow:0 40px 80px rgba(0,0,0,.6);transform:translateY(20px) scale(.97);transition:transform .35s var(--ease)}
.modal-bg.open .modal-box{transform:none}
.modal-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--cyan),var(--violet));border-radius:var(--rxl) var(--rxl) 0 0}
.modal-box{position:relative}
.modal-close{position:absolute;top:.8rem;right:.8rem;width:28px;height:28px;border-radius:7px;background:var(--card);border:1px solid var(--border);color:var(--txt3);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.78rem;transition:all .18s}
.modal-close:hover{border-color:rgba(244,63,94,.3);color:var(--rose)}
.modal-title{font-family:'Syne',sans-serif;font-weight:800;font-size:1.2rem;margin-bottom:.3rem}
.modal-sub{font-size:.78rem;color:var(--txt2);margin-bottom:1.4rem}
.form-group{margin-bottom:.9rem}
.form-label{font-size:.68rem;font-family:'Space Mono',monospace;letter-spacing:.05em;text-transform:uppercase;color:var(--txt3);display:block;margin-bottom:4px}
.form-input,.form-select,.form-textarea{width:100%;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r);padding:9px 12px;color:var(--txt);font-family:'DM Sans',sans-serif;font-size:.83rem;outline:none;transition:border-color .2s,box-shadow .2s}
.form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--border-glow);box-shadow:0 0 0 3px rgba(0,212,255,.07)}
.form-textarea{resize:vertical;min-height:80px}
.form-select{cursor:pointer;-webkit-appearance:none}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}

/* ── TOAST ── */
#toast-wrap{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9900;display:flex;flex-direction:column-reverse;gap:.5rem;pointer-events:none}
.toast{display:flex;align-items:center;gap:9px;padding:10px 14px;background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);box-shadow:0 8px 32px rgba(0,0,0,.5);font-size:.78rem;max-width:280px;pointer-events:all;transform:translateX(120px);opacity:0;transition:all .35s var(--ease)}
.toast.show{transform:translateX(0);opacity:1}
.toast.t-success{border-color:rgba(0,255,170,.3)}
.toast.t-error{border-color:rgba(244,63,94,.3)}
.toast.t-warn{border-color:rgba(245,158,11,.3)}
.t-ico{font-size:1rem;flex-shrink:0}
.t-title{font-family:'Syne',sans-serif;font-weight:700}
.t-sub{font-size:.68rem;color:var(--txt3);margin-top:1px}

/* ── NOTIF PANEL ── */
#notif-panel{position:fixed;top:var(--top);right:.8rem;width:290px;background:var(--surface);border:1px solid var(--border);border-radius:var(--rl);box-shadow:0 20px 50px rgba(0,0,0,.5);z-index:500;transform:translateY(-8px) scale(.97);opacity:0;pointer-events:none;transition:all .22s var(--ease);overflow:hidden}
#notif-panel.open{transform:translateY(6px) scale(1);opacity:1;pointer-events:all}
.np-hdr{padding:.8rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem}
.np-item{display:flex;gap:9px;padding:8px 1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.74rem;cursor:pointer;transition:background .12s}
.np-item:hover{background:var(--card-hov)}
.np-item:last-child{border-bottom:none}
.np-dot{width:26px;height:26px;border-radius:8px;background:rgba(0,212,255,.1);display:flex;align-items:center;justify-content:center;font-size:.78rem;flex-shrink:0}
.np-txt{color:var(--txt2)}
.np-time{font-family:'Space Mono',monospace;font-size:.56rem;color:var(--txt3);margin-top:1px}

/* ── SEARCH HIGHLIGHT ── */
.search-hl{background:rgba(0,212,255,.2);color:var(--cyan);padding:0 2px;border-radius:2px}

/* ── MOBILE ── */
#sb-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:299;opacity:0;pointer-events:none;transition:opacity .3s}
#sb-overlay.show{opacity:1;pointer-events:all}
@media(max-width:900px){
  #sidebar{transform:translateX(calc(-1*var(--sbar)));width:var(--sbar)!important}
  #sidebar.mobile-open{transform:translateX(0)}
  .main,.main.collapsed{margin-left:0!important}
  #hamburger{display:block}
  .page{padding:1.2rem 1rem 4rem}
  .hero-header{padding:1.4rem 1.2rem}
  .hero-content{flex-direction:column;align-items:flex-start}
  .bk-grid{grid-template-columns:repeat(auto-fill,minmax(155px,1fr))}
  .form-row{grid-template-columns:1fr}
  .top-search{display:none}
  .purch-item{flex-wrap:wrap}
  .purch-meta,.purch-btns{width:100%}
}
@media(max-width:540px){
  .bk-grid{grid-template-columns:repeat(2,1fr)}
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .fav-grid{grid-template-columns:repeat(2,1fr)}
}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.skeleton{background:linear-gradient(90deg,var(--card) 25%,rgba(255,255,255,.055) 50%,var(--card) 75%);background-size:800px 100%;animation:shimmer 1.4s ease-in-out infinite;border-radius:var(--r)}
@keyframes shimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}
</style>
</head>
<body>

<div class="orb o1"></div>
<div class="orb o2"></div>

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<aside id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">📚</div>
    <div class="sb-name">Digital <em>Library</em></div>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= h($avatarLetter) ?></div>
    <div class="sb-uinfo">
      <div class="sb-uname"><?= h($userName) ?></div>
      <div class="sb-urole"><?= h($userRole) ?></div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-sec">Navigation</div>
    <a href="../dashboard.php" class="sb-item">
      <span class="sb-ico"><i class="bi bi-grid-1x2"></i></span><span class="sb-lbl">Dashboard</span>
    </a>
    <a href="../books/index.php" class="sb-item">
      <span class="sb-ico"><i class="bi bi-compass"></i></span><span class="sb-lbl">Catalogue</span>
    </a>
    <a href="my_books.php" class="sb-item active">
      <span class="sb-ico"><i class="bi bi-collection-fill"></i></span><span class="sb-lbl">Mes livres</span>
    </a>

    <div class="sb-sec">Ma Bibliothèque</div>
    <a href="#" onclick="switchTab('read');return false" class="sb-item">
      <span class="sb-ico"><i class="bi bi-book-open"></i></span>
      <span class="sb-lbl">En lecture</span>
    </a>
    <a href="#" onclick="switchTab('purchased');return false" class="sb-item">
      <span class="sb-ico"><i class="bi bi-bag-check"></i></span>
      <span class="sb-lbl">Achetés</span>
    </a>
    <a href="#" onclick="switchTab('favs');return false" class="sb-item">
      <span class="sb-ico"><i class="bi bi-heart-fill"></i></span>
      <span class="sb-lbl">Favoris</span>
    </a>
    <a href="#" onclick="switchTab('downloads');return false" class="sb-item">
      <span class="sb-ico"><i class="bi bi-download"></i></span>
      <span class="sb-lbl">Téléchargements</span>
    </a>

    <?php if (in_array($userRole, ['journaliste','admin'])): ?>
    <div class="sb-sec">Mes Publications</div>
    <a href="#" onclick="switchTab('published');return false" class="sb-item">
      <span class="sb-ico"><i class="bi bi-journal-check"></i></span>
      <span class="sb-lbl">Publiés</span>
    </a>
    <a href="#" onclick="switchTab('draft');return false" class="sb-item">
      <span class="sb-ico"><i class="bi bi-pencil-square"></i></span>
      <span class="sb-lbl">Brouillons</span>
    </a>
    <a href="create.php" class="sb-item">
      <span class="sb-ico"><i class="bi bi-plus-square"></i></span>
      <span class="sb-lbl">Ajouter</span>
    </a>
    <?php endif; ?>

    <div class="sb-sec">Compte</div>
    <a href="#" onclick="switchTab('stats');return false" class="sb-item">
      <span class="sb-ico"><i class="bi bi-bar-chart-fill"></i></span>
      <span class="sb-lbl">Statistiques</span>
    </a>

  </nav>
  <div class="sb-foot">
    <button class="btn-collapse" onclick="toggleSidebar()">
      <span class="cico"><i class="bi bi-chevron-left"></i></span>
      <span class="clbl">Réduire</span>
    </button>
  </div>
</aside>

<div id="sb-overlay" onclick="closeMobile()"></div>

<!-- ═══════════════════ MAIN ═══════════════════ -->
<div class="main" id="main">

  <!-- TOPBAR -->
  <div id="topbar">
    <button id="hamburger" onclick="toggleMobile()"><i class="bi bi-list"></i></button>
    <div class="top-bc">
      <a href="../dashboard.php">Dashboard</a>
      <span class="bc-s">/</span>
      <a href="../books/index.php">Bibliothèque</a>
      <span class="bc-s">/</span>
      <span style="color:var(--txt)">Mes livres</span>
    </div>
    <div class="top-sp"></div>
    <div class="top-search">
      <i class="bi bi-search" style="color:var(--txt3);font-size:.8rem"></i>
      <input type="search" id="book-search" placeholder="Rechercher dans mes livres…" oninput="filterBooks(this.value)" autocomplete="off">
    </div>
    <a href="#" onclick="toggleNotif();return false" class="top-btn">
      <i class="bi bi-bell"></i>
      <?php if (!empty($notifications)): ?><span class="notif-dot"></span><?php endif; ?>
    </a>
    <a href="../users/profile.php" class="top-user">
      <div class="top-av"><?= h($avatarLetter) ?></div>
      <span><?= h($firstName) ?></span>
    </a>
  </div>

  <!-- ═══ PAGE CONTENT ═══ -->
  <div class="page">

    <!-- HERO HEADER -->
    <div class="hero-header">
      <div class="hero-content">
        <div>
          <h1 class="hero-title">Mes <span class="hl">Livres</span></h1>
          <div class="hero-sub">
            <span class="live-dot"></span>
            <?= fmtNum($userStats['nb_achats'] ?? 0) ?> achetés ·
            <?= fmtNum($userStats['nb_lus'] ?? 0) ?> en lecture ·
            <?= fmtNum($userStats['nb_favoris'] ?? 0) ?> favoris
          </div>
        </div>
        <div class="hero-actions">
          <a href="../books/index.php" class="btn btn-prim"><i class="bi bi-compass"></i> Explorer</a>
          <?php if (in_array($userRole, ['journaliste','admin'])): ?>
          <a href="create.php" class="btn btn-gold"><i class="bi bi-plus-circle"></i> Ajouter un livre</a>
          <?php endif; ?>
          <button onclick="openEditModal()" class="btn btn-ghost"><i class="bi bi-sliders"></i> Préférences</button>
        </div>
      </div>
    </div>

    <!-- STATS CARDS -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="sc-icon">📖</div>
        <div class="sc-val"><?= fmtNum($userStats['nb_lus'] ?? 0) ?></div>
        <div class="sc-lbl">Livres en lecture</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">🛍️</div>
        <div class="sc-val"><?= fmtNum($userStats['nb_achats'] ?? 0) ?></div>
        <div class="sc-lbl">Livres achetés</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">❤️</div>
        <div class="sc-val"><?= fmtNum($userStats['nb_favoris'] ?? 0) ?></div>
        <div class="sc-lbl">Favoris</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">⬇️</div>
        <div class="sc-val"><?= fmtNum($userStats['nb_dl'] ?? 0) ?></div>
        <div class="sc-lbl">Téléchargements</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">💰</div>
        <div class="sc-val" style="font-size:1.15rem"><?= fmtFCFA($userStats['total_depense'] ?? 0) ?></div>
        <div class="sc-lbl">Dépenses totales</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">📄</div>
        <div class="sc-val"><?= fmtNum($userStats['pages_lues'] ?? 0) ?></div>
        <div class="sc-lbl">Pages lues</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">⏱️</div>
        <div class="sc-val"><?= fmtNum($userStats['tps_lecture'] ?? 0) ?></div>
        <div class="sc-lbl">Min de lecture est.</div>
      </div>
      <?php if (in_array($userRole, ['journaliste','admin'])): ?>
      <div class="stat-card">
        <div class="sc-icon">📝</div>
        <div class="sc-val"><?= fmtNum($userStats['nb_publiés'] ?? 0) ?></div>
        <div class="sc-lbl">Livres publiés</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- TABS -->
    <div class="tabs-wrap" id="tabs-wrap">
      <button class="tab-btn active" data-tab="read" onclick="switchTab('read')">
        <i class="bi bi-book-open"></i> En lecture
        <?php if (!empty($booksRead)): ?><span class="tab-badge"><?= count($booksRead) ?></span><?php endif; ?>
      </button>
      <button class="tab-btn" data-tab="purchased" onclick="switchTab('purchased')">
        <i class="bi bi-bag-check"></i> Achetés
        <?php if (!empty($booksPurchased)): ?><span class="tab-badge"><?= count($booksPurchased) ?></span><?php endif; ?>
      </button>
      <button class="tab-btn" data-tab="favs" onclick="switchTab('favs')">
        <i class="bi bi-heart-fill"></i> Favoris
        <?php if (!empty($booksFavs)): ?><span class="tab-badge"><?= count($booksFavs) ?></span><?php endif; ?>
      </button>
      <button class="tab-btn" data-tab="downloads" onclick="switchTab('downloads')">
        <i class="bi bi-download"></i> Téléchargements
      </button>
      <?php if (in_array($userRole, ['journaliste','admin'])): ?>
      <button class="tab-btn" data-tab="published" onclick="switchTab('published')">
        <i class="bi bi-journal-check"></i> Publiés
        <?php if (!empty($booksPublished)): ?><span class="tab-badge"><?= count($booksPublished) ?></span><?php endif; ?>
      </button>
      <button class="tab-btn" data-tab="draft" onclick="switchTab('draft')">
        <i class="bi bi-pencil-square"></i> Tous mes livres
        <?php if (!empty($booksDraft)): ?><span class="tab-badge"><?= count($booksDraft) ?></span><?php endif; ?>
      </button>
      <?php endif; ?>
      <button class="tab-btn" data-tab="stats" onclick="switchTab('stats')">
        <i class="bi bi-bar-chart-fill"></i> Statistiques
      </button>
    </div>

    <!-- ══════════════════════════════════════════
         TAB : EN LECTURE
    ══════════════════════════════════════════ -->
    <div class="tab-content active" id="tab-read">
      <div class="sec-hdr">
        <div class="sec-title"><i class="bi bi-book-open" style="color:var(--cyan)"></i> Mes livres en cours de lecture</div>
        <div class="sec-right">
          <select class="sort-select" onchange="sortCards('read',this.value)">
            <option value="date">Récents</option>
            <option value="pct">Progression</option>
            <option value="titre">A–Z</option>
          </select>
        </div>
      </div>

      <?php if (empty($booksRead)): ?>
      <div class="empty-state">
        <span class="empty-icon">📖</span>
        <div class="empty-title">Aucune lecture en cours</div>
        <div class="empty-sub">Commencez à lire des livres pour les voir ici.</div>
        <a href="../books/index.php" class="btn btn-prim" style="margin-top:1rem"><i class="bi bi-compass"></i> Explorer le catalogue</a>
      </div>
      <?php else: ?>
      <div class="bk-grid" id="grid-read">
        <?php foreach ($booksRead as $i => $b):
          $pct     = (float)($b['pourcentage'] ?? 0);
          $pg      = (int)($b['page_actuelle'] ?? 1);
          $pages   = max(1, (int)($b['pages'] ?? 200));
          $pctCalc = $pages > 0 ? min(100, round($pg / $pages * 100)) : $pct;
          $status  = $pctCalc >= 100 ? 'done' : ($pctCalc > 0 ? 'wip' : 'new');
          $barCls  = $status === 'done' ? 'done' : ($status === 'wip' ? '' : 'abandon');
          $pal = $palettes[$i % count($palettes)];
          $emoji = $coverEmojis[$i % count($coverEmojis)];
          $delayMs = ($i % 6) * 55;
        ?>
        <div class="bk-card"
          style="animation-delay:<?= $delayMs ?>ms"
          data-id="<?= (int)$b['id'] ?>"
          data-titre="<?= h($b['titre']) ?>"
          data-auteur="<?= h($b['auteur'] ?? '') ?>"
          data-tab="read">
          <div class="bk-cover">
            <?= getCover($i, $coverEmojis, $palettes) ?>
            <div class="bk-vignette"></div>
            <div class="bk-badges">
              <?php if ($status === 'done'): ?><span class="badge badge-done">✓ TERMINÉ</span>
              <?php elseif ($status === 'wip'): ?><span class="badge badge-wip">EN COURS</span>
              <?php endif; ?>
            </div>
          </div>
          <!-- Hover Overlay -->
          <div class="bk-overlay">
            <div class="ov-title"><?= h($b['titre']) ?></div>
            <div class="ov-meta">par <?= h($b['auteur'] ?? '—') ?> · <?= $pctCalc ?>%</div>
            <div class="ov-btns">
              <button class="ov-btn primary" onclick="continueReading(<?= (int)$b['id'] ?>)">
                <i class="bi bi-book"></i> Continuer
              </button>
              <button class="ov-btn ghost" onclick="toggleFav(<?= (int)$b['id'] ?>, this)">
                <i class="bi bi-heart"></i>
              </button>
            </div>
          </div>
          <div class="bk-body">
            <div class="bk-cat"><?= h($b['cat_icone'] ?? '📚') ?> <?= h($b['categorie'] ?? '') ?></div>
            <div class="bk-title"><?= h($b['titre']) ?></div>
            <div class="bk-author">par <?= h($b['auteur'] ?? '—') ?></div>
            <div class="bk-meta">
              <span class="bk-stars">
                <?php $n = round((float)($b['note_moyenne'] ?? 0)); echo str_repeat('★',$n).str_repeat('☆',5-$n); ?>
              </span>
              <span class="bk-pages"><?= $pg ?>/<?= $pages ?>p</span>
            </div>
            <div class="prog-wrap">
              <div class="prog-bar <?= $barCls ?>" style="width:0%" data-target="<?= $pctCalc ?>%"></div>
            </div>
            <div class="prog-pct"><?= $pctCalc ?>%</div>
          </div>
          <div class="bk-foot">
            <button class="btn btn-sm btn-prim" onclick="continueReading(<?= (int)$b['id'] ?>)">
              <i class="bi bi-book"></i> Lire
            </button>
            <span style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--txt3);margin-left:auto">
              <?= h(timeAgoMB($b['updated_at'] ?? date('Y-m-d H:i:s'))) ?>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════
         TAB : ACHETÉS
    ══════════════════════════════════════════ -->
    <div class="tab-content" id="tab-purchased">
      <div class="sec-hdr">
        <div class="sec-title"><i class="bi bi-bag-check" style="color:var(--gold)"></i> Mes livres achetés</div>
        <div class="sec-right">
          <button class="btn btn-ghost btn-sm" onclick="filterPurchased('all')">Tous</button>
          <button class="btn btn-ghost btn-sm" onclick="filterPurchased('premium')">Premium</button>
          <button class="btn btn-ghost btn-sm" onclick="filterPurchased('standard')">Standard</button>
          <button class="btn btn-ghost btn-sm" onclick="filterPurchased('gratuit')">Gratuits</button>
        </div>
      </div>

      <?php if (empty($booksPurchased)): ?>
      <div class="empty-state">
        <span class="empty-icon">🛍️</span>
        <div class="empty-title">Aucun achat pour le moment</div>
        <div class="empty-sub">Explorez le catalogue et achetez des livres.</div>
        <a href="../books/index.php" class="btn btn-prim" style="margin-top:1rem"><i class="bi bi-compass"></i> Explorer</a>
      </div>
      <?php else: ?>

      <!-- Bonus Bar -->
      <?php if (isset($bonusRow) && $bonusRow['achat_count'] > 0): ?>
      <div style="background:rgba(0,255,170,.06);border:1px solid rgba(0,255,170,.15);border-radius:var(--rl);padding:.9rem 1.2rem;margin-bottom:1rem;display:flex;align-items:center;gap:1rem;animation:fadeUp .4s ease both">
        <i class="bi bi-gift-fill" style="color:var(--sage);font-size:1.2rem"></i>
        <div style="flex:1">
          <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:.83rem">Programme Fidélité</div>
          <div style="font-size:.7rem;color:var(--txt2);margin-top:2px"><?= (int)$bonusRow['achat_count'] ?>/5 achats · <?= (int)$bonusRow['bonus_restant'] ?> livre(s) offert(s)</div>
          <div style="margin-top:5px;background:rgba(255,255,255,.06);border-radius:100px;height:4px;overflow:hidden">
            <div style="height:100%;background:linear-gradient(90deg,var(--sage),var(--cyan));width:<?= min(100, (int)$bonusRow['achat_count'] / 5 * 100) ?>%;transition:width 1s"></div>
          </div>
        </div>
        <?php if ($bonusRow['bonus_restant'] > 0): ?>
        <span class="badge badge-free">🎁 <?= (int)$bonusRow['bonus_restant'] ?> bonus</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="purch-list" id="purch-list">
        <?php foreach ($booksPurchased as $i => $b):
          $pal   = $palettes[$i % count($palettes)];
          $emoji = $coverEmojis[$i % count($coverEmojis)];
          $delayMs = ($i % 8) * 45;
        ?>
        <div class="purch-item" style="animation-delay:<?= $delayMs ?>ms"
          data-id="<?= (int)$b['id'] ?>"
          data-access="<?= h($b['access_type'] ?? 'standard') ?>">
          <div class="purch-cover">
            <div class="bk-cover-bg" style="background:linear-gradient(135deg,<?= $pal[0] ?>,<?= $pal[1] ?>)"></div>
            <span style="position:relative;z-index:1"><?= $emoji ?></span>
          </div>
          <div class="purch-info">
            <div class="purch-title"><?= h($b['titre']) ?></div>
            <div class="purch-author">par <?= h($b['auteur'] ?? '—') ?></div>
            <div class="purch-tags">
              <?= accessBadge($b['access_type'] ?? 'standard') ?>
              <span class="badge badge-std" style="font-size:.5rem"><?= h($b['cat_icone'] ?? '📚') ?> <?= h($b['categorie'] ?? '') ?></span>
              <?php if (!empty($b['fichier_pdf'])): ?>
              <span class="badge badge-free" style="font-size:.5rem">📄 PDF</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="purch-meta">
            <div class="purch-price"><?= fmtFCFA((float)($b['montant'] ?? 0)) ?></div>
            <div class="purch-date"><?= !empty($b['date_achat']) ? date('d/m/Y', strtotime($b['date_achat'])) : '—' ?></div>
            <div class="purch-ref"><?= h(mb_substr($b['reference'] ?? '—', 0, 16)) ?>…</div>
          </div>
          <div class="purch-btns">
            <a href="../books/reader.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-prim" title="Lire">
              <i class="bi bi-book"></i>
            </a>
            <?php if (!empty($b['fichier_pdf'])): ?>
            <a href="<?= h($b['fichier_pdf']) ?>" class="btn btn-sm btn-ghost" title="Télécharger PDF" download>
              <i class="bi bi-download"></i>
            </a>
            <?php endif; ?>
            <button class="btn btn-sm btn-ghost" onclick="showInvoice(<?= (int)$b['id'] ?>, '<?= h($b['reference'] ?? '') ?>')" title="Voir facture">
              <i class="bi bi-receipt"></i>
            </button>
            <button class="btn btn-sm btn-ghost" onclick="toggleFav(<?= (int)$b['id'] ?>, this)" title="Favoris">
              <i class="bi bi-heart"></i>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════
         TAB : PUBLIÉS (Journaliste / Admin)
    ══════════════════════════════════════════ -->
    <?php if (in_array($userRole, ['journaliste','admin'])): ?>
    <div class="tab-content" id="tab-published">
      <div class="sec-hdr">
        <div class="sec-title"><i class="bi bi-journal-check" style="color:var(--sage)"></i> Mes livres publiés</div>
        <div class="sec-right">
          <a href="create.php" class="btn btn-prim btn-sm"><i class="bi bi-plus"></i> Ajouter</a>
          <select class="sort-select" onchange="sortPub(this.value)">
            <option value="date">Récents</option>
            <option value="ventes">Ventes ↓</option>
            <option value="note">Note ↓</option>
          </select>
        </div>
      </div>

      <?php if (empty($booksPublished)): ?>
      <div class="empty-state">
        <span class="empty-icon">✍️</span>
        <div class="empty-title">Aucune publication</div>
        <div class="empty-sub">Créez votre premier livre et publiez-le sur la plateforme.</div>
        <a href="create.php" class="btn btn-prim" style="margin-top:1rem"><i class="bi bi-plus-circle"></i> Créer un livre</a>
      </div>
      <?php else: ?>

      <!-- Stats publiés -->
      <?php
        $totalVentes  = array_sum(array_column($booksPublished, 'nb_ventes'));
        $totalLectures= array_sum(array_column($booksPublished, 'nb_lectures'));
        $totalRevs    = $userStats['revenus_total'] ?? 0;
        $nbLecteurs   = $userStats['nb_lecteurs'] ?? 0;
      ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.7rem;margin-bottom:1.2rem">
        <?php foreach ([
          ['💰', fmtFCFA($totalRevs), 'Revenus générés', 'var(--gold)'],
          ['🛍️', fmtNum($totalVentes), 'Ventes totales', 'var(--sage)'],
          ['👁️', fmtNum($totalLectures), 'Lectures totales', 'var(--cyan)'],
          ['👥', fmtNum($nbLecteurs), 'Lecteurs uniques', 'var(--violet)'],
        ] as [$ic, $vl, $lb, $co]): ?>
        <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:.9rem;animation:fadeUp .4s ease both">
          <div style="font-size:1.2rem;margin-bottom:.4rem"><?= $ic ?></div>
          <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;color:<?= $co ?>"><?= $vl ?></div>
          <div style="font-size:.67rem;color:var(--txt3);margin-top:2px"><?= $lb ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div style="overflow-x:auto">
        <table class="pub-table" id="pub-table">
          <thead>
            <tr>
              <th>Couv.</th>
              <th>Titre</th>
              <th>Catégorie</th>
              <th>Accès</th>
              <th>Ventes</th>
              <th>Lectures</th>
              <th>Note</th>
              <th>Revenus</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($booksPublished as $i => $b):
              $pal   = $palettes[$i % count($palettes)];
              $emoji = $coverEmojis[$i % count($coverEmojis)];
            ?>
            <tr data-titre="<?= h($b['titre']) ?>" data-statut="<?= h($b['statut'] ?? '') ?>">
              <td>
                <div class="pub-cover-sm">
                  <div class="bk-cover-bg" style="background:linear-gradient(135deg,<?= $pal[0] ?>,<?= $pal[1] ?>)"></div>
                  <span style="position:relative;z-index:1;font-size:1rem"><?= $emoji ?></span>
                </div>
              </td>
              <td class="pub-td-p" style="max-width:180px">
                <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($b['titre']) ?></div>
                <div style="font-size:.62rem;color:var(--txt3)">par <?= h($b['auteur'] ?? '') ?></div>
              </td>
              <td>
                <span style="font-size:.7rem;color:var(--txt2)"><?= h($b['cat_icone'] ?? '📚') ?> <?= h($b['categorie'] ?? '—') ?></span>
              </td>
              <td><?= accessBadge($b['access_type'] ?? 'standard') ?></td>
              <td class="pub-td-mini" style="color:var(--sage)"><?= fmtNum((int)($b['nb_achats_reel'] ?? $b['nb_ventes'] ?? 0)) ?></td>
              <td class="pub-td-mini" style="color:var(--cyan)"><?= fmtNum((int)($b['nb_lectures'] ?? 0)) ?></td>
              <td class="pub-td-mini" style="color:var(--amber)">
                <?php $note = (float)($b['note_moyenne'] ?? 0); ?>
                <?= $note > 0 ? '★ ' . number_format($note, 1) : '—' ?>
              </td>
              <td class="pub-td-mini" style="color:var(--gold)"><?= fmtFCFA((float)($b['revenus'] ?? 0)) ?></td>
              <td>
                <?php $st = $b['statut'] ?? 'disponible'; ?>
                <span class="badge <?= $st === 'disponible' ? 'badge-free' : 'badge-abandon' ?>"><?= h(ucfirst($st)) ?></span>
                <?php if (!empty($b['is_bestseller'])): ?><span class="badge badge-bs">🏆</span><?php endif; ?>
                <?php if (!empty($b['is_featured'])): ?><span class="badge badge-featured">⚡</span><?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:3px">
                  <a href="edit.php?id=<?= (int)$b['id'] ?>" class="btn btn-xs btn-ghost" title="Modifier">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <a href="books/reader.php?id=<?= (int)$b['id'] ?>" class="btn btn-xs btn-ghost" title="Aperçu">
                    <i class="bi bi-eye"></i>
                  </a>
                  <button class="btn btn-xs btn-danger" onclick="confirmDelete(<?= (int)$b['id'] ?>, '<?= h($b['titre']) ?>')" title="Supprimer">
                    <i class="bi bi-trash3"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════
         TAB : TOUS MES LIVRES (brouillons/archivés)
    ══════════════════════════════════════════ -->
    <div class="tab-content" id="tab-draft">
      <div class="sec-hdr">
        <div class="sec-title"><i class="bi bi-pencil-square" style="color:var(--amber)"></i> Tous mes livres (gestion)</div>
        <div class="sec-right">
          <button class="btn btn-ghost btn-sm" onclick="filterDraft('all')">Tous</button>
          <button class="btn btn-ghost btn-sm" onclick="filterDraft('disponible')">Publiés</button>
          <button class="btn btn-ghost btn-sm" onclick="filterDraft('archive')">Archivés</button>
          <a href="create.php" class="btn btn-prim btn-sm"><i class="bi bi-plus"></i> Nouveau</a>
        </div>
      </div>

      <?php if (empty($booksDraft)): ?>
      <div class="empty-state">
        <span class="empty-icon">✏️</span>
        <div class="empty-title">Aucun livre créé</div>
        <a href="create.php" class="btn btn-prim" style="margin-top:1rem"><i class="bi bi-plus-circle"></i> Créer</a>
      </div>
      <?php else: ?>
      <div class="bk-grid" id="grid-draft">
        <?php foreach ($booksDraft as $i => $b):
          $pal   = $palettes[$i % count($palettes)];
          $emoji = $coverEmojis[$i % count($coverEmojis)];
          $st    = $b['statut'] ?? 'archive';
          $delayMs = ($i % 6) * 55;
        ?>
        <div class="bk-card"
          style="animation-delay:<?= $delayMs ?>ms"
          data-id="<?= (int)$b['id'] ?>"
          data-titre="<?= h($b['titre']) ?>"
          data-statut="<?= h($st) ?>"
          data-tab="draft">
          <div class="bk-cover">
            <?= getCover($i, $coverEmojis, $palettes) ?>
            <div class="bk-vignette"></div>
            <div class="bk-badges">
              <?= accessBadge($b['access_type'] ?? 'standard') ?>
              <?php if ($st === 'archive'): ?>
              <span class="badge badge-abandon" style="font-size:.5rem">ARCHIVÉ</span>
              <?php elseif ($st === 'disponible'): ?>
              <span class="badge badge-free" style="font-size:.5rem">PUBLIÉ</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="bk-overlay">
            <div class="ov-title"><?= h($b['titre']) ?></div>
            <div class="ov-meta"><?= h($b['auteur'] ?? '') ?></div>
            <div class="ov-btns">
              <a href="edit.php?id=<?= (int)$b['id'] ?>" class="ov-btn ghost"><i class="bi bi-pencil"></i> Modifier</a>
              <?php if ($st === 'archive'): ?>
              <button class="ov-btn primary" onclick="publishBook(<?= (int)$b['id'] ?>)"><i class="bi bi-check2"></i> Publier</button>
              <?php else: ?>
              <button class="ov-btn danger" onclick="archiveBook(<?= (int)$b['id'] ?>)"><i class="bi bi-archive"></i> Archiver</button>
              <?php endif; ?>
            </div>
          </div>
          <div class="bk-body">
            <div class="bk-cat"><?= h($b['cat_icone'] ?? '📚') ?> <?= h($b['categorie'] ?? '') ?></div>
            <div class="bk-title"><?= h($b['titre']) ?></div>
            <div class="bk-author">par <?= h($b['auteur'] ?? '—') ?></div>
            <div class="bk-meta">
              <span style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--txt3)"><?= fmtFCFA((float)($b['prix'] ?? 0)) ?></span>
              <span class="bk-pages"><?= (int)($b['pages'] ?? 0) ?>p</span>
            </div>
          </div>
          <div class="bk-foot">
            <a href="edit.php?id=<?= (int)$b['id'] ?>" class="btn btn-xs btn-ghost"><i class="bi bi-pencil"></i></a>
            <button class="btn btn-xs btn-danger" onclick="confirmDelete(<?= (int)$b['id'] ?>, '<?= h($b['titre']) ?>')"><i class="bi bi-trash3"></i></button>
            <?php if ($st === 'archive'): ?>
            <button class="btn btn-xs btn-success" onclick="publishBook(<?= (int)$b['id'] ?>)"><i class="bi bi-upload"></i></button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════
         TAB : FAVORIS
    ══════════════════════════════════════════ -->
    <div class="tab-content" id="tab-favs">
      <div class="sec-hdr">
        <div class="sec-title"><i class="bi bi-heart-fill" style="color:var(--rose)"></i> Mes favoris</div>
        <div class="sec-right">
          <span style="font-size:.73rem;color:var(--txt3)"><?= count($booksFavs) ?> livre<?= count($booksFavs) > 1 ? 's' : '' ?></span>
        </div>
      </div>

      <?php if (empty($booksFavs)): ?>
      <div class="empty-state">
        <span class="empty-icon">❤️</span>
        <div class="empty-title">Aucun favori</div>
        <div class="empty-sub">Ajoutez des livres à vos favoris depuis le catalogue.</div>
        <a href="../books/index.php" class="btn btn-prim" style="margin-top:1rem"><i class="bi bi-compass"></i> Explorer</a>
      </div>
      <?php else: ?>
      <div class="fav-grid" id="grid-favs">
        <?php foreach ($booksFavs as $i => $b):
          $delayMs = ($i % 6) * 55;
        ?>
        <div class="bk-card"
          style="animation-delay:<?= $delayMs ?>ms"
          data-id="<?= (int)$b['id'] ?>"
          data-titre="<?= h($b['titre']) ?>"
          data-tab="favs">
          <div class="bk-cover">
            <?= getCover($i, $coverEmojis, $palettes) ?>
            <div class="bk-vignette"></div>
            <div class="bk-badges"><?= accessBadge($b['access_type'] ?? 'standard') ?></div>
            <div class="bk-tag-left" style="font-size:.9rem">❤️</div>
          </div>
          <div class="bk-overlay">
            <div class="ov-title"><?= h($b['titre']) ?></div>
            <div class="ov-meta"><?= h($b['auteur'] ?? '') ?></div>
            <div class="ov-btns">
              <a href="../books/read.php?id=<?= (int)$b['id'] ?>" class="ov-btn primary"><i class="bi bi-book"></i> Lire</a>
              <button class="ov-btn fav-on" onclick="toggleFav(<?= (int)$b['id'] ?>, this)"><i class="bi bi-heart-fill"></i></button>
            </div>
          </div>
          <div class="bk-body">
            <div class="bk-cat"><?= h($b['cat_icone'] ?? '📚') ?> <?= h($b['categorie'] ?? '') ?></div>
            <div class="bk-title"><?= h($b['titre']) ?></div>
            <div class="bk-author">par <?= h($b['auteur'] ?? '—') ?></div>
            <div class="bk-meta">
              <span class="bk-stars"><?php $n = round((float)($b['note_moyenne'] ?? 0)); echo str_repeat('★',$n).str_repeat('☆',5-$n); ?></span>
              <span style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--gold)"><?= fmtFCFA((float)($b['prix'] ?? 0)) ?></span>
            </div>
          </div>
          <div class="bk-foot">
            <a href="../books/read.php?id=<?= (int)$b['id'] ?>" class="btn btn-xs btn-prim"><i class="bi bi-book"></i> Lire</a>
            <button class="btn btn-xs btn-danger" onclick="toggleFav(<?= (int)$b['id'] ?>, this)"><i class="bi bi-heart-fill"></i></button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════
         TAB : TÉLÉCHARGEMENTS
    ══════════════════════════════════════════ -->
    <div class="tab-content" id="tab-downloads">
      <div class="sec-hdr">
        <div class="sec-title"><i class="bi bi-download" style="color:var(--violet)"></i> Mes téléchargements</div>
      </div>

      <?php if (empty($booksDownloads)): ?>
      <div class="empty-state">
        <span class="empty-icon">⬇️</span>
        <div class="empty-title">Aucun téléchargement</div>
        <div class="empty-sub">Téléchargez des livres en PDF depuis le catalogue.</div>
      </div>
      <?php else: ?>
      <div class="dl-list">
        <?php foreach ($booksDownloads as $i => $b):
          $delayMs = ($i % 8) * 45;
        ?>
        <div class="dl-item" style="animation-delay:<?= $delayMs ?>ms">
          <div style="font-size:1.4rem;flex-shrink:0"><?= $coverEmojis[$i % count($coverEmojis)] ?></div>
          <div class="dl-info">
            <div class="dl-title"><?= h($b['titre']) ?></div>
            <div class="dl-sub">
              <?= h($b['cat_icone'] ?? '📚') ?> <?= h($b['categorie'] ?? '') ?> ·
              Dernier : <?= !empty($b['last_dl_at']) ? date('d/m/Y', strtotime($b['last_dl_at'])) : '—' ?>
            </div>
          </div>
          <div class="dl-count"><?= (int)($b['nb_dl'] ?? 0) ?>× téléchargé</div>
          <button class="btn btn-sm btn-ghost" onclick="downloadBook(<?= (int)$b['id'] ?>)">
            <i class="bi bi-download"></i> PDF
          </button>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════
         TAB : STATISTIQUES
    ══════════════════════════════════════════ -->
    <div class="tab-content" id="tab-stats">
      <div class="sec-hdr">
        <div class="sec-title"><i class="bi bi-bar-chart-fill" style="color:var(--cyan)"></i> Mes statistiques personnelles</div>
      </div>

      <!-- Grid charts -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;margin-bottom:1.2rem">

        <div class="chart-card">
          <div class="chart-title"><i class="bi bi-graph-up-arrow" style="color:var(--cyan)"></i> Activité de lecture</div>
          <div class="chart-wrap"><canvas id="chart-reading"></canvas></div>
        </div>

        <div class="chart-card">
          <div class="chart-title"><i class="bi bi-pie-chart-fill" style="color:var(--gold)"></i> Répartition par type d'accès</div>
          <div class="chart-wrap"><canvas id="chart-access"></canvas></div>
        </div>

      </div>

      <?php if (in_array($userRole, ['journaliste','admin']) && !empty($booksPublished)): ?>
      <div class="chart-card" style="margin-bottom:1.2rem">
        <div class="chart-title"><i class="bi bi-currency-exchange" style="color:var(--gold)"></i> Ventes par livre (mes publications)</div>
        <div class="chart-wrap" style="height:220px"><canvas id="chart-ventes"></canvas></div>
      </div>
      <?php endif; ?>

      <!-- Récap texte -->
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.9rem">
        <?php $recapItems = [
          ['📚', 'Livres lus', $userStats['nb_lus'], 'var(--cyan)'],
          ['🛍️', 'Achats confirmés', $userStats['nb_achats'], 'var(--gold)'],
          ['❤️', 'Favoris', $userStats['nb_favoris'], 'var(--rose)'],
          ['⬇️', 'Téléchargements', $userStats['nb_dl'], 'var(--violet)'],
          ['📄', 'Pages lues', $userStats['pages_lues'], 'var(--sage)'],
          ['⏱️', 'Minutes de lecture', $userStats['tps_lecture'], 'var(--amber)'],
        ]; if (in_array($userRole, ['journaliste','admin'])) {
          $recapItems[] = ['💰', 'Revenus générés', fmtFCFA($userStats['revenus_total'] ?? 0), 'var(--gold)'];
          $recapItems[] = ['👥', 'Lecteurs uniques', $userStats['nb_lecteurs'] ?? 0, 'var(--cyan)'];
        } ?>
        <?php foreach ($recapItems as [$ico, $lbl, $val, $col]): ?>
        <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--rl);padding:1rem 1.2rem;animation:fadeUp .4s ease both">
          <div style="font-size:1.3rem;margin-bottom:.4rem"><?= $ico ?></div>
          <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:1.3rem;color:<?= $col ?>"><?= is_numeric($val) ? fmtNum((int)$val) : $val ?></div>
          <div style="font-size:.68rem;color:var(--txt3);margin-top:3px"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /page -->
</div><!-- /main -->

<!-- ═══════════════════ NOTIFICATIONS ═══════════════════ -->
<div id="notif-panel">
  <div class="np-hdr">
    <span>🔔 Notifications</span>
    <button onclick="markAllRead()" style="background:none;border:none;color:var(--cyan);font-size:.7rem;cursor:pointer;font-family:'DM Sans',sans-serif">Tout lire</button>
  </div>
  <?php if (empty($notifications)): ?>
  <div style="padding:1.5rem;text-align:center;color:var(--txt3);font-size:.8rem">Aucune notification</div>
  <?php else: foreach ($notifications as $n):
    $icons = ['achat'=>'🛍️','lecture'=>'📖','download'=>'⬇️','bonus'=>'🎁','bestseller'=>'🏆','default'=>'🔔'];
    $ico = $icons[$n['type'] ?? 'default'] ?? '🔔';
  ?>
  <div class="np-item">
    <div class="np-dot"><?= $ico ?></div>
    <div>
      <div class="np-txt"><?= h(mb_substr($n['message'] ?? '', 0, 80)) ?></div>
      <div class="np-time"><?= !empty($n['created_at']) ? timeAgoMB($n['created_at']) : '—' ?></div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- ═══════════════════ MODALS ═══════════════════ -->

<!-- Modal confirmation suppression -->
<div class="modal-bg" id="modal-delete">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('modal-delete')"><i class="bi bi-x"></i></button>
    <div class="modal-title">🗑️ Confirmer la suppression</div>
    <div class="modal-sub">Cette action est irréversible. Le livre sera supprimé définitivement.</div>
    <div style="background:rgba(244,63,94,.07);border:1px solid rgba(244,63,94,.15);border-radius:var(--r);padding:.9rem;margin-bottom:1.2rem">
      <strong id="del-title" style="color:var(--rose)">—</strong>
    </div>
    <div style="display:flex;gap:.6rem">
      <button class="btn btn-danger" style="flex:1" onclick="doDelete()"><i class="bi bi-trash3"></i> Supprimer</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-delete')">Annuler</button>
    </div>
  </div>
</div>

<!-- Modal Facture -->
<div class="modal-bg" id="modal-invoice">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('modal-invoice')"><i class="bi bi-x"></i></button>
    <div class="modal-title">🧾 Reçu d'achat</div>
    <div id="invoice-content" style="font-family:'Space Mono',monospace;font-size:.72rem;color:var(--txt2);background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--r);padding:1rem;margin:1rem 0;line-height:2">
    </div>
    <button class="btn btn-ghost" style="width:100%" onclick="printInvoice()"><i class="bi bi-printer"></i> Imprimer</button>
  </div>
</div>

<!-- Modal Préférences -->
<div class="modal-bg" id="modal-prefs">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal('modal-prefs')"><i class="bi bi-x"></i></button>
    <div class="modal-title">⚙️ Préférences</div>
    <div class="modal-sub">Personnalisez votre espace de lecture.</div>
    <div class="form-group">
      <label class="form-label">Thème de lecture</label>
      <select class="form-select" id="pref-theme">
        <option value="dark">Mode sombre</option>
        <option value="light">Mode clair</option>
        <option value="sepia">Mode sépia</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Taille de police</label>
      <select class="form-select" id="pref-font">
        <option value="14">Petite (14px)</option>
        <option value="16" selected>Normale (16px)</option>
        <option value="18">Grande (18px)</option>
        <option value="20">Très grande (20px)</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Notifications email</label>
      <select class="form-select" id="pref-notif">
        <option value="1">Activées</option>
        <option value="0">Désactivées</option>
      </select>
    </div>
    <div style="display:flex;gap:.6rem;margin-top:.5rem">
      <button class="btn btn-prim" style="flex:1" onclick="savePrefs()"><i class="bi bi-check2"></i> Enregistrer</button>
      <button class="btn btn-ghost" onclick="closeModal('modal-prefs')">Annuler</button>
    </div>
  </div>
</div>

<!-- ═══════════════════ TOAST ═══════════════════ -->
<div id="toast-wrap"></div>

<!-- ═══════════════════ SCRIPTS ═══════════════════ -->
<script>
'use strict';
const CSRF_TOKEN = <?= json_encode($csrf, JSON_HEX_TAG) ?>;
const USER_ROLE  = <?= json_encode($userRole, JSON_HEX_TAG) ?>;

// ── UTILS ────────────────────────────────────────────────────────
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') }

function toast(title, sub='', type='default', dur=4000) {
  const icons = {default:'ℹ️', success:'✅', error:'❌', warn:'⚠️'};
  const cols  = {default:'var(--border)', success:'rgba(0,255,170,.3)', error:'rgba(244,63,94,.3)', warn:'rgba(245,158,11,.3)'};
  const el    = document.createElement('div');
  el.className = 'toast';
  el.style.borderColor = cols[type] || cols.default;
  el.innerHTML = `<span class="t-ico">${icons[type]||'ℹ️'}</span><div><div class="t-title">${esc(title)}</div>${sub?'<div class="t-sub">'+esc(sub)+'</div>':''}</div>`;
  document.getElementById('toast-wrap').appendChild(el);
  requestAnimationFrame(()=>requestAnimationFrame(()=>el.classList.add('show')));
  setTimeout(()=>{el.classList.remove('show');setTimeout(()=>el.remove(),350);}, dur);
}

async function apiFetch(url, data={}) {
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},
      body: JSON.stringify({...data, csrf:CSRF_TOKEN})
    });
    if (!res.ok) throw new Error('HTTP '+res.status);
    return await res.json();
  } catch(e) {
    console.error('[apiFetch]', e);
    return {success:false, error: e.message};
  }
}

// ── SIDEBAR ──────────────────────────────────────────────────────
let collapsed = false;
function toggleSidebar() {
  collapsed = !collapsed;
  document.getElementById('sidebar').classList.toggle('collapsed', collapsed);
  document.getElementById('main').classList.toggle('collapsed', collapsed);
}
function toggleMobile() {
  document.getElementById('sidebar').classList.toggle('mobile-open');
  document.getElementById('sb-overlay').classList.toggle('show');
}
function closeMobile() {
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('sb-overlay').classList.remove('show');
}

// ── TABS ─────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  const tc = document.getElementById('tab-'+name);
  if (tc) tc.classList.add('active');
  const tb = document.querySelector(`.tab-btn[data-tab="${name}"]`);
  if (tb) tb.classList.add('active');
  // Déclencher les animations
  tc?.querySelectorAll('.bk-card,.purch-item,.dl-item').forEach((el,i)=>{
    el.style.animationDelay = (i%8*45)+'ms';
    el.style.animation = 'none';
    void el.offsetWidth;
    el.style.animation = '';
  });
  // Init charts si onglet stats
  if (name === 'stats') initCharts();
  history.replaceState(null,'', '?tab='+name);
}

// ── PROGRESS BARS ANIMATION ──────────────────────────────────────
function animateProgressBars() {
  document.querySelectorAll('.prog-bar[data-target]').forEach(bar => {
    const target = bar.dataset.target;
    bar.style.width = '0%';
    requestAnimationFrame(()=>requestAnimationFrame(()=>{ bar.style.width = target; }));
  });
}

// ── BOOK SEARCH ──────────────────────────────────────────────────
function filterBooks(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.bk-card, .purch-item, .dl-item').forEach(card=>{
    const t = (card.dataset.titre||'').toLowerCase();
    const a = (card.dataset.auteur||'').toLowerCase();
    card.style.display = (!q || t.includes(q) || a.includes(q)) ? '' : 'none';
  });
}

// ── FILTER PURCHASED ─────────────────────────────────────────────
function filterPurchased(type) {
  document.querySelectorAll('#purch-list .purch-item').forEach(el=>{
    el.style.display = (type==='all' || el.dataset.access===type) ? '' : 'none';
  });
}

// ── FILTER DRAFT ─────────────────────────────────────────────────
function filterDraft(status) {
  document.querySelectorAll('#grid-draft .bk-card').forEach(el=>{
    el.style.display = (status==='all' || el.dataset.statut===status) ? '' : 'none';
  });
}

// ── SORT ─────────────────────────────────────────────────────────
function sortCards(tab, by) {
  const grid = document.getElementById('grid-'+tab);
  if (!grid) return;
  const cards = Array.from(grid.querySelectorAll('.bk-card'));
  cards.sort((a,b)=>{
    if (by==='titre') return (a.dataset.titre||'').localeCompare(b.dataset.titre||'','fr');
    if (by==='pct') return parseFloat(b.querySelector('.prog-bar')?.dataset.target||0) - parseFloat(a.querySelector('.prog-bar')?.dataset.target||0);
    return 0;
  });
  cards.forEach(c=>grid.appendChild(c));
}

function sortPub(by) {
  const tbl = document.querySelector('#pub-table tbody');
  if (!tbl) return;
  const rows = Array.from(tbl.querySelectorAll('tr'));
  rows.sort((a,b)=>{
    const getCell = (r,i) => r.querySelectorAll('td')[i]?.textContent.trim()||'';
    if (by==='ventes') return parseInt(getCell(b,4))||0 - (parseInt(getCell(a,4))||0);
    if (by==='note')   return parseFloat(getCell(b,6))||0 - (parseFloat(getCell(a,6))||0);
    return 0;
  });
  rows.forEach(r=>tbl.appendChild(r));
}

// ── CONTINUE READING ─────────────────────────────────────────────
function continueReading(id) {
  window.location.href = '../books/reader.php?id='+id;
}

// ── TOGGLE FAV (AJAX) ─────────────────────────────────────────────
async function toggleFav(bookId, btnEl) {
  const card  = btnEl?.closest('.bk-card, .purch-item');
  const isFav = btnEl?.querySelector('i')?.classList.contains('bi-heart-fill');
  const action = isFav ? 'remove' : 'add';

  const result = await apiFetch('../api/toggle_fav.php', {livre_id: bookId, action});

  if (result.success !== false) {
    if (isFav) {
      btnEl.querySelector('i').className = 'bi bi-heart';
      toast('Retiré des favoris', '', 'default', 2000);
      // Retirer la carte de l'onglet favoris si on y est
      const tabFav = document.getElementById('tab-favs');
      if (tabFav?.classList.contains('active')) {
        card?.closest('.bk-card')?.remove();
      }
    } else {
      btnEl.querySelector('i').className = 'bi bi-heart-fill';
      toast('Ajouté aux favoris ❤️', '', 'success', 2000);
    }
  } else {
    toast('Erreur', result.error || 'Impossible de modifier les favoris', 'error');
  }
}

// ── DOWNLOAD ──────────────────────────────────────────────────────
function downloadBook(id) {
  // Tracker + redirect
  apiFetch('../api/download_book.php', {livre_id: id}).then(r => {
    if (r.url) window.open(r.url, '_blank');
    else toast('Téléchargement', 'Fichier PDF non disponible pour ce livre.', 'warn');
  });
  toast('Téléchargement…', 'Préparation du fichier', 'default', 3000);
}

// ── DELETE ────────────────────────────────────────────────────────
let pendingDeleteId = null;
function confirmDelete(id, titre) {
  pendingDeleteId = id;
  document.getElementById('del-title').textContent = titre;
  openModal('modal-delete');
}
async function doDelete() {
  if (!pendingDeleteId) return;
  const result = await apiFetch('../api/delete_book.php', {id: pendingDeleteId});
  closeModal('modal-delete');
  if (result.success) {
    document.querySelectorAll(`.bk-card[data-id="${pendingDeleteId}"], tr[data-id="${pendingDeleteId}"]`).forEach(el=>el.remove());
    toast('Supprimé', 'Le livre a été supprimé.', 'success');
  } else {
    toast('Erreur', result.error || 'Impossible de supprimer ce livre', 'error');
  }
  pendingDeleteId = null;
}

// ── PUBLISH / ARCHIVE ─────────────────────────────────────────────
async function publishBook(id) {
  const result = await apiFetch('../api/book_action.php', {id, action:'publish'});
  if (result.success) {
    toast('Publié !', 'Le livre est maintenant disponible dans le catalogue.', 'success');
    setTimeout(()=>location.reload(), 1500);
  } else {
    toast('Erreur', result.error || 'Impossible de publier', 'error');
  }
}
async function archiveBook(id) {
  const result = await apiFetch('../api/book_action.php', {id, action:'archive'});
  if (result.success) {
    toast('Archivé', 'Le livre a été retiré du catalogue.', 'warn');
    setTimeout(()=>location.reload(), 1500);
  } else {
    toast('Erreur', result.error || 'Impossible d\'archiver', 'error');
  }
}

// ── INVOICE ──────────────────────────────────────────────────────
function showInvoice(id, ref) {
  document.getElementById('invoice-content').innerHTML =
    `Digital Library Platform\n`.padEnd(30,'—')+'\n'+
    `Référence : ${esc(ref)}\n`+
    `Date       : ${new Date().toLocaleDateString('fr-FR')}\n`+
    `Livre ID   : #${id}\n`.padEnd(30,'—')+'\n'+
    `Merci de votre achat !\n`+
    `support@digitallibrary.cm`;
  openModal('modal-invoice');
}
function printInvoice() { window.print(); }

// ── PREFS ─────────────────────────────────────────────────────────
function openEditModal() { openModal('modal-prefs'); }
function savePrefs() {
  const theme = document.getElementById('pref-theme').value;
  const font  = document.getElementById('pref-font').value;
  localStorage.setItem('dls_reader_theme', theme);
  localStorage.setItem('dls_reader_font', font);
  closeModal('modal-prefs');
  toast('Préférences sauvegardées', '', 'success', 2500);
}

// ── MODALS ────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id)?.classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.modal-bg').forEach(m => m.addEventListener('click', e => { if (e.target===m) closeModal(m.id); }));

// ── NOTIF ──────────────────────────────────────────────────────────
function toggleNotif() {
  document.getElementById('notif-panel').classList.toggle('open');
}
function markAllRead() {
  apiFetch('../api/mark_notifications.php', {action:'mark_all_read'});
  document.querySelectorAll('.notif-dot').forEach(d=>d.remove());
  toast('Notifications marquées comme lues', '', 'success', 2000);
  document.getElementById('notif-panel').classList.remove('open');
}
document.addEventListener('click', e => {
  const np = document.getElementById('notif-panel');
  const nb = document.querySelector('.top-btn[onclick*="toggleNotif"]');
  if (np?.classList.contains('open') && !np.contains(e.target) && !nb?.contains(e.target)) {
    np.classList.remove('open');
  }
});

// ── CHARTS ────────────────────────────────────────────────────────
let chartsInit = false;
function initCharts() {
  if (chartsInit) return;
  chartsInit = true;

  const chartCfg = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { labels: { color: 'rgba(238,242,255,.55)', font: { family: 'Space Mono' } } } }
  };

  // Chart lecture (7 derniers jours simulé)
  const days = [];
  const readData = [];
  for (let i=6; i>=0; i--) {
    const d = new Date(); d.setDate(d.getDate()-i);
    days.push(d.toLocaleDateString('fr-FR',{weekday:'short'}));
    readData.push(Math.floor(Math.random()*50+5)); // pages lues
  }
  const ctxR = document.getElementById('chart-reading')?.getContext('2d');
  if (ctxR) {
    new Chart(ctxR, {
      type: 'line',
      data: {
        labels: days,
        datasets: [{
          label: 'Pages lues',
          data: readData,
          borderColor: '#00d4ff',
          backgroundColor: 'rgba(0,212,255,.08)',
          tension: .4,
          fill: true,
          pointBackgroundColor: '#00d4ff',
          pointRadius: 4,
        }]
      },
      options: { ...chartCfg,
        scales: {
          x: { ticks:{color:'rgba(238,242,255,.35)',font:{family:'Space Mono',size:10}}, grid:{color:'rgba(255,255,255,.04)'} },
          y: { ticks:{color:'rgba(238,242,255,.35)',font:{family:'Space Mono',size:10}}, grid:{color:'rgba(255,255,255,.04)'} }
        }
      }
    });
  }

  // Chart Accès (doughnut)
  const nbPrem = <?= (int)array_reduce(array_merge($booksPurchased, $booksFavs), function($c,$b){ return $c + (($b['access_type']??'')==='premium'?1:0); }, 0) ?>;
  const nbStd  = <?= (int)array_reduce(array_merge($booksPurchased, $booksFavs), function($c,$b){ return $c + (($b['access_type']??'')==='standard'?1:0); }, 0) ?>;
  const nbFree = <?= (int)array_reduce(array_merge($booksPurchased, $booksFavs), function($c,$b){ return $c + (($b['access_type']??'')==='gratuit'?1:0); }, 0) ?>;
  const ctxA = document.getElementById('chart-access')?.getContext('2d');
  if (ctxA) {
    new Chart(ctxA, {
      type: 'doughnut',
      data: {
        labels: ['Premium','Standard','Gratuit'],
        datasets: [{
          data: [nbPrem||1, nbStd||1, nbFree||1],
          backgroundColor: ['rgba(232,201,125,.7)','rgba(0,212,255,.6)','rgba(0,255,170,.6)'],
          borderColor: ['rgba(232,201,125,.3)','rgba(0,212,255,.3)','rgba(0,255,170,.3)'],
          borderWidth: 1,
        }]
      },
      options: { ...chartCfg, cutout: '68%' }
    });
  }

  <?php if (in_array($userRole, ['journaliste','admin']) && !empty($booksPublished)): ?>
  // Chart ventes
  const pubTitres = <?= json_encode(array_map(fn($b) => mb_substr($b['titre']??'',0,18).'…', array_slice($booksPublished,0,8))) ?>;
  const pubVentes = <?= json_encode(array_map(fn($b) => (int)($b['nb_achats_reel'] ?? $b['nb_ventes'] ?? 0), array_slice($booksPublished,0,8))) ?>;
  const ctxV = document.getElementById('chart-ventes')?.getContext('2d');
  if (ctxV) {
    new Chart(ctxV, {
      type: 'bar',
      data: {
        labels: pubTitres,
        datasets: [{
          label: 'Ventes',
          data: pubVentes,
          backgroundColor: 'rgba(232,201,125,.5)',
          borderColor: 'rgba(232,201,125,.8)',
          borderWidth: 1, borderRadius: 6,
        }]
      },
      options: { ...chartCfg,
        scales: {
          x: { ticks:{color:'rgba(238,242,255,.35)',font:{family:'Space Mono',size:10},maxRotation:35}, grid:{display:false} },
          y: { ticks:{color:'rgba(238,242,255,.35)',font:{family:'Space Mono',size:10}}, grid:{color:'rgba(255,255,255,.04)'} }
        }
      }
    });
  }
  <?php endif; ?>
}

// ── KEYBOARD ──────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-bg.open').forEach(m=>closeModal(m.id));
    document.getElementById('notif-panel')?.classList.remove('open');
  }
});

// ── INIT ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Animations progress bars
  setTimeout(animateProgressBars, 300);

  // Onglet depuis URL
  const urlParams = new URLSearchParams(window.location.search);
  const tab = urlParams.get('tab');
  const validTabs = ['read','purchased','favs','downloads','published','draft','stats'];
  if (tab && validTabs.includes(tab)) switchTab(tab);

  // Toast bienvenue
  setTimeout(()=>toast(
    'Espace personnel',
    '<?= h($firstName) ?> · <?= fmtNum($userStats['nb_achats']) ?> achetés · <?= fmtNum($userStats['nb_lus']) ?> en lecture',
    'success', 4500
  ), 600);

  // Stats count-up animation
  document.querySelectorAll('.sc-val').forEach(el => {
    const text = el.textContent.trim();
    const num  = parseInt(text.replace(/[^\d]/g,''));
    if (!num || isNaN(num)) return;
    let cur = 0;
    const step = Math.max(1, Math.ceil(num / 40));
    const timer = setInterval(() => {
      cur = Math.min(cur + step, num);
      el.textContent = cur.toLocaleString('fr-FR');
      if (cur >= num) clearInterval(timer);
    }, 30);
  });
});
</script>
</body>
</html>