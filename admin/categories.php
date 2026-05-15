<?php
// ============================================================
// DIGITAL LIBRARY — admin/categories.php
// CRUD complet + AJAX — Interface SaaS ultra moderne
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

// Sécurité admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$adminName = 'Admin';
if (!empty($_SESSION['user_prenom']) && !empty($_SESSION['user_nom'])) {
    $adminName = htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']);
} elseif (!empty($_SESSION['user_name'])) {
    $adminName = htmlspecialchars($_SESSION['user_name']);
}

// ── Connexion PDO ────────────────────────────────────────────
$pdo = null;
$configPath = dirname(__DIR__) . '/includes/config.php';
if (file_exists($configPath)) require_once $configPath;
if (!isset($pdo) || $pdo === null) {
    $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db_name = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $db_user = defined('DB_USER') ? DB_USER : 'root';
    $db_pass = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) { $pdo = null; }
}

// ── AJAX Handler ─────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pdo) { echo json_encode(['success'=>false,'message'=>'Connexion BD impossible.']); exit; }

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // ── LISTER ──────────────────────────────────────────────
    if ($action === 'list') {
        try {
            $rows = $pdo->query("
                SELECT c.id, c.nom, c.slug, c.icone, c.created_at,
                       COUNT(l.id) AS nb_livres
                FROM categories c
                LEFT JOIN livres l ON l.categorie_id = c.id AND l.statut = 'disponible'
                GROUP BY c.id
                ORDER BY c.id ASC
            ")->fetchAll();
            echo json_encode(['success'=>true,'data'=>$rows]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── STATS ────────────────────────────────────────────────
    if ($action === 'stats') {
        try {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
            $nbLivres = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
            $topRow = $pdo->query("
                SELECT c.nom, c.icone, COUNT(l.id) AS nb
                FROM categories c
                LEFT JOIN livres l ON l.categorie_id = c.id
                GROUP BY c.id ORDER BY nb DESC LIMIT 1
            ")->fetch();
            echo json_encode([
                'success' => true,
                'total'   => $total,
                'nb_livres' => $nbLivres,
                'top' => $topRow ?: ['nom'=>'—','icone'=>'📚','nb'=>0],
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── AJOUTER ──────────────────────────────────────────────
    if ($action === 'add') {
        $nom   = trim($_POST['nom']  ?? '');
        $icone = trim($_POST['icone'] ?? '📚');
        $slug  = strtolower(trim(preg_replace('/[^a-z0-9]+/i','-', iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nom))));
        $slug  = trim($slug,'-');
        if (empty($nom)) { echo json_encode(['success'=>false,'message'=>'Le nom est obligatoire.']); exit; }
        if (empty($slug)) $slug = 'categorie-' . time();
        // Unicité du slug
        $existing = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE slug = ?");
        $existing->execute([$slug]);
        if ((int)$existing->fetchColumn() > 0) $slug .= '-' . time();
        try {
            $stmt = $pdo->prepare("INSERT INTO categories (nom, slug, icone) VALUES (?, ?, ?)");
            $stmt->execute([$nom, $slug, $icone]);
            $newId = (int)$pdo->lastInsertId();
            $cat = $pdo->prepare("SELECT c.*, COUNT(l.id) AS nb_livres FROM categories c LEFT JOIN livres l ON l.categorie_id=c.id WHERE c.id=? GROUP BY c.id");
            $cat->execute([$newId]);
            echo json_encode(['success'=>true,'message'=>'Catégorie ajoutée.','data'=>$cat->fetch()]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Erreur : ' . $e->getMessage()]);
        }
        exit;
    }

    // ── MODIFIER ─────────────────────────────────────────────
    if ($action === 'edit') {
        $id    = (int)($_POST['id']    ?? 0);
        $nom   = trim($_POST['nom']    ?? '');
        $icone = trim($_POST['icone']  ?? '📚');
        if (!$id || empty($nom)) { echo json_encode(['success'=>false,'message'=>'Données invalides.']); exit; }
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i','-', iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nom))));
        $slug = trim($slug,'-');
        if (empty($slug)) $slug = 'categorie-' . $id;
        try {
            $pdo->prepare("UPDATE categories SET nom=?, slug=?, icone=? WHERE id=?")->execute([$nom,$slug,$icone,$id]);
            $cat = $pdo->prepare("SELECT c.*, COUNT(l.id) AS nb_livres FROM categories c LEFT JOIN livres l ON l.categorie_id=c.id WHERE c.id=? GROUP BY c.id");
            $cat->execute([$id]);
            echo json_encode(['success'=>true,'message'=>'Catégorie mise à jour.','data'=>$cat->fetch()]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Erreur : ' . $e->getMessage()]);
        }
        exit;
    }

    // ── SUPPRIMER ────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'ID invalide.']); exit; }
        try {
            // Dissocier les livres liés avant suppression
            $pdo->prepare("UPDATE livres SET categorie_id = NULL WHERE categorie_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            echo json_encode(['success'=>true,'message'=>'Catégorie supprimée.']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Erreur : ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Action inconnue.']);
    exit;
}

// ── Données initiales pour SSR ───────────────────────────────
$categories = [];
$statsTotal = 0;
$statsLivres = 0;
$statsTop = ['nom'=>'—','icone'=>'📚','nb'=>0];
if ($pdo) {
    try {
        $categories = $pdo->query("
            SELECT c.id, c.nom, c.slug, c.icone, c.created_at,
                   COUNT(l.id) AS nb_livres
            FROM categories c
            LEFT JOIN livres l ON l.categorie_id = c.id AND l.statut = 'disponible'
            GROUP BY c.id ORDER BY c.id ASC
        ")->fetchAll();
        $statsTotal  = count($categories);
        $statsLivres = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
        $topRow = $pdo->query("
            SELECT c.nom, c.icone, COUNT(l.id) AS nb
            FROM categories c LEFT JOIN livres l ON l.categorie_id=c.id
            GROUP BY c.id ORDER BY nb DESC LIMIT 1
        ")->fetch();
        if ($topRow) $statsTop = $topRow;
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Catégories — Digital Library Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      fontFamily: {
        display: ['Syne','sans-serif'],
        body: ['DM Sans','sans-serif'],
        mono: ['JetBrains Mono','monospace'],
      },
      colors: {
        ink: '#08090e',
        card: '#10121a',
        panel: '#13161f',
        border: 'rgba(255,255,255,0.07)',
        gold: '#e8c97d',
        ember: '#ff6b35',
        sage: '#3ecfa3',
        azure: '#4a9eff',
        plum: '#9b59b6',
        rose: '#e74c3c',
      },
      animation: {
        'float': 'float 6s ease-in-out infinite',
        'shimmer': 'shimmer 2.5s linear infinite',
        'slide-up': 'slideUp 0.4s cubic-bezier(0.34,1.56,0.64,1)',
        'fade-in': 'fadeIn 0.3s ease',
        'scale-in': 'scaleIn 0.4s cubic-bezier(0.34,1.56,0.64,1)',
        'spin-slow': 'spin 3s linear infinite',
        'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
      },
      keyframes: {
        float: { '0%,100%': {transform:'translateY(0)'}, '50%': {transform:'translateY(-8px)'} },
        shimmer: { 'to': {'background-position':'200% center'} },
        slideUp: { 'from': {opacity:'0',transform:'translateY(20px) scale(0.97)'}, 'to': {opacity:'1',transform:'none'} },
        fadeIn: { 'from': {opacity:'0'}, 'to': {opacity:'1'} },
        scaleIn: { 'from': {opacity:'0',transform:'scale(0.92)'}, 'to': {opacity:'1',transform:'scale(1)'} },
        pulseSoft: { '0%,100%': {opacity:'1'}, '50%': {opacity:'0.6'} },
      },
      backdropBlur: { 'xs': '2px' },
      boxShadow: {
        'glow-gold': '0 0 30px rgba(232,201,125,0.2)',
        'glow-sage': '0 0 30px rgba(62,207,163,0.2)',
        'glow-azure': '0 0 30px rgba(74,158,255,0.2)',
        'lift': '0 20px 60px rgba(0,0,0,0.5)',
        'card': '0 4px 24px rgba(0,0,0,0.3)',
      },
    }
  }
}
</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{font-family:'DM Sans',sans-serif;background:#08090e;color:#f0eeea;overflow-x:hidden;min-height:100vh}
::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-track{background:#08090e}
::-webkit-scrollbar-thumb{background:#e8c97d;border-radius:3px}

/* Noise overlay */
body::before{
  content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");
  opacity:0.4;
}

/* Orbs */
.orb{position:fixed;border-radius:50%;filter:blur(120px);pointer-events:none;z-index:0;animation:float 10s ease-in-out infinite}

/* Card gradients */
.card-glow-gold{box-shadow:0 0 0 1px rgba(232,201,125,0.1),0 20px 60px rgba(0,0,0,0.4)}
.card-glow-sage{box-shadow:0 0 0 1px rgba(62,207,163,0.12),0 20px 60px rgba(0,0,0,0.4)}
.card-glow-azure{box-shadow:0 0 0 1px rgba(74,158,255,0.12),0 20px 60px rgba(0,0,0,0.4)}

/* Gradient text */
.text-gradient-gold{background:linear-gradient(135deg,#e8c97d,#ff6b35);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.text-gradient-sage{background:linear-gradient(135deg,#3ecfa3,#4a9eff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}

/* Shimmer border */
.shimmer-border{background:linear-gradient(90deg,#e8c97d,#ff6b35,#3ecfa3,#4a9eff,#e8c97d);background-size:300% auto;animation:shimmer 4s linear infinite}

/* Category card hover */
.cat-card{transition:transform 0.35s cubic-bezier(0.34,1.56,0.64,1),box-shadow 0.3s,border-color 0.3s}
.cat-card:hover{transform:translateY(-8px) scale(1.02);border-color:rgba(232,201,125,0.25)!important;box-shadow:0 24px 60px rgba(0,0,0,0.5),0 0 40px rgba(232,201,125,0.06)!important}

/* Input styles */
.field{
  width:100%;padding:12px 16px;
  background:rgba(255,255,255,0.04);
  border:1px solid rgba(255,255,255,0.08);
  border-radius:12px;
  color:#f0eeea;
  font-family:'DM Sans',sans-serif;
  font-size:0.88rem;
  outline:none;
  transition:border-color 0.2s,background 0.2s;
}
.field:focus{border-color:rgba(232,201,125,0.4);background:rgba(232,201,125,0.04)}
.field::placeholder{color:rgba(240,238,234,0.25)}

/* Modal backdrop */
.modal-bg{backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}

/* Emoji picker */
.emoji-opt{
  font-size:1.5rem;padding:8px;border-radius:10px;cursor:pointer;
  border:2px solid transparent;transition:all 0.15s;
}
.emoji-opt:hover{background:rgba(232,201,125,0.1);border-color:rgba(232,201,125,0.3);transform:scale(1.15)}
.emoji-opt.selected{background:rgba(232,201,125,0.15);border-color:var(--gold,#e8c97d);transform:scale(1.1)}

/* Sidebar */
.sidebar-item{
  display:flex;align-items:center;gap:10px;
  padding:9px 12px;border-radius:10px;
  color:rgba(240,238,234,0.5);
  text-decoration:none;
  font-size:0.82rem;font-weight:500;
  transition:all 0.2s;cursor:pointer;
  border:none;background:none;width:100%;
}
.sidebar-item:hover{background:rgba(255,255,255,0.04);color:#f0eeea}
.sidebar-item.active{background:rgba(232,201,125,0.08);color:#e8c97d;border:1px solid rgba(232,201,125,0.12)}

/* Toast */
#toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:0.6rem;pointer-events:none}
.toast-item{
  display:flex;align-items:center;gap:10px;
  padding:12px 16px;border-radius:14px;
  font-size:0.8rem;font-family:'DM Sans',sans-serif;
  backdrop-filter:blur(20px);
  min-width:220px;max-width:320px;
  transform:translateY(20px) scale(0.95);opacity:0;
  transition:all 0.4s cubic-bezier(0.34,1.56,0.64,1);
  pointer-events:auto;
}
.toast-item.show{transform:none;opacity:1}
.toast-success{background:rgba(13,18,32,0.97);border:1px solid rgba(62,207,163,0.3)}
.toast-error{background:rgba(13,18,32,0.97);border:1px solid rgba(231,76,60,0.3)}
.toast-info{background:rgba(13,18,32,0.97);border:1px solid rgba(232,201,125,0.25)}

/* Delete confirm overlay */
.delete-overlay{backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}

/* Stagger animation for cards */
.cat-card-wrap:nth-child(1){animation-delay:0ms}
.cat-card-wrap:nth-child(2){animation-delay:60ms}
.cat-card-wrap:nth-child(3){animation-delay:120ms}
.cat-card-wrap:nth-child(4){animation-delay:180ms}
.cat-card-wrap:nth-child(5){animation-delay:240ms}
.cat-card-wrap:nth-child(n+6){animation-delay:300ms}

@keyframes float{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(20px,-30px) scale(1.05)}}

@media(max-width:768px){
  #sidebar{transform:translateX(-100%);transition:transform 0.3s}
  #sidebar.open{transform:translateX(0)}
  #main{margin-left:0!important}
}
</style>
</head>
<body class="bg-ink">

<!-- Background orbs -->
<div class="orb" style="width:500px;height:500px;background:rgba(232,201,125,0.04);top:-100px;left:-150px;animation-delay:0s"></div>
<div class="orb" style="width:400px;height:400px;background:rgba(62,207,163,0.04);bottom:-100px;right:-100px;animation-delay:-4s"></div>
<div class="orb" style="width:300px;height:300px;background:rgba(74,158,255,0.03);top:40%;left:55%;animation-delay:-8s"></div>

<div class="flex min-h-screen relative z-10">

<!-- ══════════════════════════════════════
     SIDEBAR
══════════════════════════════════════ -->
<aside id="sidebar" class="fixed top-0 left-0 bottom-0 w-64 z-50 flex flex-col"
  style="background:rgba(8,9,14,0.97);border-right:1px solid rgba(255,255,255,0.06);backdrop-filter:blur(24px)">

  <!-- Brand -->
  <div class="p-5 border-b" style="border-color:rgba(255,255,255,0.06)">
    <a href="../index.php" class="flex items-center gap-3 no-underline">
      <div class="w-9 h-9 rounded-xl flex items-center justify-center text-base flex-shrink-0"
        style="background:linear-gradient(135deg,#e8c97d,#ff6b35);box-shadow:0 0 20px rgba(232,201,125,0.3)">📚</div>
      <div>
        <div class="font-display font-700 text-sm tracking-tight text-white" style="font-family:Syne,sans-serif;font-weight:700">Digital Library</div>
        <div class="text-xs font-mono" style="font-family:'JetBrains Mono',monospace;color:#e8c97d;font-size:0.58rem;letter-spacing:0.1em">ADMIN</div>
      </div>
    </a>
  </div>

  <!-- Nav -->
  <nav class="flex-1 p-3 overflow-y-auto">
    <p class="font-mono text-xs px-3 mb-2 mt-3 uppercase tracking-widest" style="font-family:'JetBrains Mono',monospace;font-size:0.58rem;color:rgba(240,238,234,0.25)">Principal</p>
    <a href="../dashboard.php" class="sidebar-item" style="color:rgba(231,76,60,0.7)"><span>🚪</span> Dashboard</a>
    <a href="categories.php" class="sidebar-item active"><span>🗂️</span> Catégories</a>

    
  </nav>





  <!-- Admin profile -->
  <div class="p-3 border-t" style="border-color:rgba(255,255,255,0.06)">
    <div class="flex items-center gap-3 p-3 rounded-xl" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06)">
      <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-black flex-shrink-0"
        style="background:linear-gradient(135deg,#e8c97d,#ff6b35)"><?= strtoupper(substr($adminName,0,2)) ?></div>
      <div class="min-w-0 flex-1">
        <div class="text-sm font-semibold truncate"><?= $adminName ?></div>
        <div class="text-xs font-mono" style="color:#e8c97d;font-family:'JetBrains Mono',monospace;font-size:0.58rem">ADMIN</div>
      </div>
    </div>
  </div>
</aside>

<!-- ══════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════ -->
<main id="main" class="flex-1 flex flex-col ml-64 min-h-screen">

  <!-- Top bar -->
  <div class="sticky top-0 z-40 flex items-center justify-between px-8 h-16"
    style="background:rgba(8,9,14,0.85);border-bottom:1px solid rgba(255,255,255,0.06);backdrop-filter:blur(24px)">
    <div class="flex items-center gap-3">
      <button class="md:hidden w-8 h-8 flex items-center justify-center rounded-lg border"
        style="border-color:rgba(255,255,255,0.08);background:rgba(255,255,255,0.03);color:rgba(240,238,234,0.6)"
        onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
      <div class="w-2 h-2 rounded-full animate-pulse-soft" style="background:#3ecfa3;box-shadow:0 0 8px rgba(62,207,163,0.6)"></div>
      <span class="font-display font-semibold text-sm" style="font-family:Syne,sans-serif">Gestion des Catégories</span>
      <span class="font-mono text-xs px-2 py-0.5 rounded-md" style="font-family:'JetBrains Mono',monospace;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);color:rgba(240,238,234,0.4);font-size:0.6rem">LIVE</span>
    </div>
    <div class="flex items-center gap-3">
      <span class="font-mono text-xs hidden sm:block" style="color:rgba(240,238,234,0.3);font-family:'JetBrains Mono',monospace" id="topbar-time"></span>
      <a href="../dashboard.php" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all hover:scale-105"
        style="border:1px solid rgba(255,255,255,0.08);background:rgba(255,255,255,0.03);color:rgba(240,238,234,0.5)">
       
      </a>
    </div>
  </div>

  <!-- Content area -->
  <div class="flex-1 p-8">

    <!-- ── PAGE HEADER ── -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-6 mb-10 animate-slide-up">
      <div>
        <div class="flex items-center gap-2 mb-2">
          <span class="font-mono text-xs tracking-widest uppercase" style="font-family:'JetBrains Mono',monospace;color:#3ecfa3;font-size:0.62rem">Bibliothèque</span>
          <span class="font-mono text-xs" style="color:rgba(240,238,234,0.2)">/ Taxonomie</span>
        </div>
        <h1 class="font-display text-4xl font-800 tracking-tight leading-none mb-2" style="font-family:Syne,sans-serif;font-weight:800;letter-spacing:-1.5px">
          Catégories
        </h1>
        <p class="text-sm" style="color:rgba(240,238,234,0.45)">Organisez et structurez votre catalogue de livres.</p>
      </div>
      <button id="btn-new-cat" onclick="openModal()"
        class="flex items-center gap-2 px-5 py-3 rounded-2xl font-semibold text-sm transition-all hover:scale-105 hover:shadow-glow-gold flex-shrink-0"
        style="background:linear-gradient(135deg,#e8c97d,#ff6b35);color:#08090e;box-shadow:0 8px 30px rgba(232,201,125,0.25);font-family:'DM Sans',sans-serif">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nouvelle catégorie
      </button>
    </div>

    <!-- ── STATS CARDS ── -->
    <div id="stats-row" class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-10">
      <!-- Total catégories -->
      <div class="rounded-2xl p-6 transition-all hover:scale-105 cursor-default card-glow-gold animate-scale-in"
        style="background:#10121a;border:1px solid rgba(232,201,125,0.1);animation-delay:0ms">
        <div class="flex items-start justify-between mb-4">
          <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl" style="background:rgba(232,201,125,0.1)">🗂️</div>
          <span class="font-mono text-xs px-2 py-1 rounded-full" style="font-family:'JetBrains Mono',monospace;background:rgba(232,201,125,0.08);color:#e8c97d;border:1px solid rgba(232,201,125,0.15);font-size:0.58rem">TOTAL</span>
        </div>
        <div class="font-display font-800 text-5xl tracking-tighter text-gradient-gold mb-1" style="font-family:Syne,sans-serif;font-weight:800" id="stat-total"><?= $statsTotal ?></div>
        <div class="text-sm" style="color:rgba(240,238,234,0.4)">Catégories actives</div>
      </div>
      <!-- Top catégorie -->
      <div class="rounded-2xl p-6 transition-all hover:scale-105 cursor-default card-glow-sage animate-scale-in"
        style="background:#10121a;border:1px solid rgba(62,207,163,0.1);animation-delay:60ms">
        <div class="flex items-start justify-between mb-4">
          <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl" style="background:rgba(62,207,163,0.1)" id="stat-top-icon"><?= htmlspecialchars($statsTop['icone']) ?></div>
          <span class="font-mono text-xs px-2 py-1 rounded-full" style="font-family:'JetBrains Mono',monospace;background:rgba(62,207,163,0.08);color:#3ecfa3;border:1px solid rgba(62,207,163,0.15);font-size:0.58rem">🔥 TOP</span>
        </div>
        <div class="font-display font-800 text-2xl tracking-tight mb-0.5 text-gradient-sage" style="font-family:Syne,sans-serif;font-weight:800" id="stat-top-nom"><?= htmlspecialchars($statsTop['nom']) ?></div>
        <div class="text-sm" style="color:rgba(240,238,234,0.4)" id="stat-top-nb"><?= (int)$statsTop['nb'] ?> livres liés</div>
      </div>
      <!-- Total livres -->
      <div class="rounded-2xl p-6 transition-all hover:scale-105 cursor-default card-glow-azure animate-scale-in"
        style="background:#10121a;border:1px solid rgba(74,158,255,0.1);animation-delay:120ms">
        <div class="flex items-start justify-between mb-4">
          <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl" style="background:rgba(74,158,255,0.1)">📖</div>
          <span class="font-mono text-xs px-2 py-1 rounded-full" style="font-family:'JetBrains Mono',monospace;background:rgba(74,158,255,0.08);color:#4a9eff;border:1px solid rgba(74,158,255,0.15);font-size:0.58rem">LIVRES</span>
        </div>
        <div class="font-display font-800 text-5xl tracking-tighter text-gradient-sage mb-1" style="font-family:Syne,sans-serif;font-weight:800;background:linear-gradient(135deg,#4a9eff,#9b59b6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text" id="stat-livres"><?= $statsLivres ?></div>
        <div class="text-sm" style="color:rgba(240,238,234,0.4)">Livres disponibles</div>
      </div>
    </div>

    <!-- ── SECTION TITLE + SEARCH ── -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
      <div class="flex items-center gap-3">
        <h2 class="font-display font-700 text-xl tracking-tight" style="font-family:Syne,sans-serif;font-weight:700">Toutes les catégories</h2>
        <span class="font-mono text-xs px-2 py-1 rounded-full" style="font-family:'JetBrains Mono',monospace;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);color:rgba(240,238,234,0.35);font-size:0.6rem" id="grid-count"><?= $statsTotal ?> entrées</span>
      </div>
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm" style="color:rgba(240,238,234,0.3)">🔍</span>
        <input type="text" id="search-input" placeholder="Rechercher…"
          class="pl-9 pr-4 py-2 rounded-xl text-sm transition-all"
          style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);color:#f0eeea;outline:none;font-family:'DM Sans',sans-serif;min-width:200px"
          oninput="filterCards(this.value)">
      </div>
    </div>

    <!-- ── CATEGORIES GRID ── -->
    <div id="cats-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 mb-8">
      <?php foreach ($categories as $i => $cat): ?>
      <div class="cat-card-wrap animate-scale-in" data-search="<?= strtolower(htmlspecialchars($cat['nom'])) ?>" style="animation-delay:<?= min($i,5)*60 ?>ms">
        <?= buildCatCard($cat) ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Empty state -->
    <div id="empty-state" class="<?= empty($categories)?'':'hidden' ?> text-center py-24">
      <div class="text-6xl mb-4 opacity-20 animate-float">🗂️</div>
      <div class="font-display font-600 text-xl mb-2" style="font-family:Syne,sans-serif;color:rgba(240,238,234,0.4)">Aucune catégorie</div>
      <div class="text-sm mb-6" style="color:rgba(240,238,234,0.25)">Commencez par créer votre première catégorie.</div>
      <button onclick="openModal()" class="px-6 py-3 rounded-xl font-semibold text-sm transition-all hover:scale-105"
        style="background:linear-gradient(135deg,#e8c97d,#ff6b35);color:#08090e">+ Créer une catégorie</button>
    </div>

  </div><!-- /content -->
</main>
</div>

<!-- ══════════════════════════════════════
     MODAL — AJOUT / ÉDITION
══════════════════════════════════════ -->
<div id="modal-overlay" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-bg"
  style="background:rgba(8,9,14,0.85)">
  <div id="modal-box" class="relative w-full max-w-md rounded-3xl overflow-hidden animate-slide-up"
    style="background:linear-gradient(145deg,#13161f,#10121a);border:1px solid rgba(255,255,255,0.08);box-shadow:0 60px 120px rgba(0,0,0,0.7)">

    <!-- Shimmer top bar -->
    <div class="h-0.5 w-full shimmer-border"></div>

    <!-- Header -->
    <div class="flex items-center justify-between px-7 pt-6 pb-4">
      <div>
        <div class="font-mono text-xs mb-1 uppercase tracking-widest" style="font-family:'JetBrains Mono',monospace;color:#3ecfa3;font-size:0.6rem" id="modal-mode-label">NOUVELLE CATÉGORIE</div>
        <h3 class="font-display font-700 text-xl tracking-tight" style="font-family:Syne,sans-serif;font-weight:700" id="modal-title">Créer une catégorie</h3>
      </div>
      <button onclick="closeModal()" class="w-8 h-8 rounded-xl flex items-center justify-center text-sm transition-all hover:scale-105"
        style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);color:rgba(240,238,234,0.4)">✕</button>
    </div>

    <!-- Body -->
    <div class="px-7 pb-7">
      <input type="hidden" id="edit-id" value="">

      <!-- Nom -->
      <div class="mb-5">
        <label class="block text-xs font-semibold mb-2" style="color:rgba(240,238,234,0.5);letter-spacing:0.06em;text-transform:uppercase;font-size:0.68rem">
          Nom de la catégorie <span style="color:#e74c3c">*</span>
        </label>
        <input type="text" id="input-nom" class="field" placeholder="ex: Science-Fiction, Philosophie…" maxlength="100" autocomplete="off">
        <div id="nom-error" class="text-xs mt-1 hidden" style="color:#e74c3c">Ce champ est obligatoire.</div>
      </div>

      <!-- Icône -->
      <div class="mb-6">
        <label class="block text-xs font-semibold mb-3" style="color:rgba(240,238,234,0.5);letter-spacing:0.06em;text-transform:uppercase;font-size:0.68rem">
          Icône
        </label>
        <div class="flex flex-wrap gap-2 p-4 rounded-2xl mb-3" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06)">
          <?php
          $emojis = ['📚','📘','📗','📙','📕','📓','📰','✍️','📖','🗞️','📜','🎭','🔮','💡','🌌','🧠','⚙️','🌿','📜','🏛️','🎵','🎨','🏆','🔬','🌍','🦋','⚡','🌊','🏔️','🎯'];
          foreach ($emojis as $e):
          ?>
          <button type="button" class="emoji-opt" onclick="selectEmoji(this, '<?= $e ?>')"><?= $e ?></button>
          <?php endforeach; ?>
        </div>
        <div class="flex items-center gap-3">
          <span class="text-xs" style="color:rgba(240,238,234,0.35)">Ou saisir manuellement :</span>
          <input type="text" id="input-icone" class="field text-center text-xl" style="width:60px;padding:8px;text-align:center" value="📚" maxlength="5">
          <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl flex-shrink-0"
            style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08)" id="icon-preview">📚</div>
        </div>
      </div>

      <!-- Buttons -->
      <div class="flex gap-3">
        <button onclick="closeModal()" class="flex-1 py-3 rounded-xl font-semibold text-sm transition-all hover:scale-105"
          style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);color:rgba(240,238,234,0.5)">
          Annuler
        </button>
        <button id="btn-save" onclick="saveCategory()" class="flex-2 flex items-center justify-center gap-2 py-3 px-6 rounded-xl font-semibold text-sm transition-all hover:scale-105"
          style="background:linear-gradient(135deg,#3ecfa3,#4a9eff);color:#08090e;box-shadow:0 8px 24px rgba(62,207,163,0.2);flex:2">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          <span id="btn-save-text">Enregistrer</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════
     MODAL SUPPRESSION
══════════════════════════════════════ -->
<div id="delete-overlay" class="fixed inset-0 z-50 hidden items-center justify-center p-4 delete-overlay"
  style="background:rgba(8,9,14,0.88)">
  <div class="w-full max-w-sm rounded-3xl p-8 text-center animate-scale-in"
    style="background:linear-gradient(145deg,#13161f,#10121a);border:1px solid rgba(231,76,60,0.2);box-shadow:0 0 60px rgba(231,76,60,0.08),0 40px 80px rgba(0,0,0,0.6)">
    <div class="w-16 h-16 rounded-full flex items-center justify-center text-3xl mx-auto mb-5"
      style="background:rgba(231,76,60,0.1);border:2px solid rgba(231,76,60,0.25)">🗑️</div>
    <h3 class="font-display font-700 text-xl mb-2 tracking-tight" style="font-family:Syne,sans-serif;font-weight:700">Supprimer la catégorie ?</h3>
    <p class="text-sm mb-2" style="color:rgba(240,238,234,0.5)">Cette action est irréversible.</p>
    <p class="text-sm mb-6 px-4 py-2 rounded-xl font-semibold" style="background:rgba(231,76,60,0.08);border:1px solid rgba(231,76,60,0.15);color:#e74c3c" id="delete-cat-name">—</p>
    <div class="text-xs mb-6 px-3 py-2 rounded-lg" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);color:rgba(240,238,234,0.35)">
      ⚠️ Les livres liés seront dissociés (non supprimés).
    </div>
    <div class="flex gap-3">
      <button onclick="closeDeleteModal()" class="flex-1 py-3 rounded-xl font-semibold text-sm transition-all hover:scale-105"
        style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);color:rgba(240,238,234,0.5)">
        Annuler
      </button>
      <button id="btn-confirm-delete" onclick="confirmDelete()" class="flex-1 py-3 rounded-xl font-semibold text-sm transition-all hover:scale-105"
        style="background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff;box-shadow:0 6px 20px rgba(231,76,60,0.25)">
        Supprimer
      </button>
    </div>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toast-container"></div>

<!-- ══════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════ -->
<script>
'use strict';

// ── ÉTAT ──────────────────────────────────────────────────────
let editMode     = false;
let deleteId     = null;
let deleteName   = '';

// ── TOPBAR CLOCK ──────────────────────────────────────────────
const timeEl = document.getElementById('topbar-time');
function tick(){ if(timeEl) timeEl.textContent = new Date().toLocaleTimeString('fr-FR'); }
setInterval(tick,1000); tick();

// ══════════════════════════════════════════════════════════════
// TOAST
// ══════════════════════════════════════════════════════════════
function toast(msg, type='info', dur=3500) {
  const container = document.getElementById('toast-container');
  const icons = { success:'✅', error:'❌', info:'💡' };
  const el = document.createElement('div');
  el.className = `toast-item toast-${type}`;
  el.innerHTML = `
    <span style="font-size:1rem;flex-shrink:0">${icons[type]||'💡'}</span>
    <span style="flex:1;font-size:0.8rem;color:#f0eeea;font-family:'DM Sans',sans-serif">${msg}</span>
  `;
  container.appendChild(el);
  requestAnimationFrame(() => { requestAnimationFrame(() => { el.classList.add('show'); }); });
  setTimeout(() => {
    el.classList.remove('show');
    setTimeout(() => el.remove(), 400);
  }, dur);
}

// ══════════════════════════════════════════════════════════════
// MODAL ADD / EDIT
// ══════════════════════════════════════════════════════════════
function openModal(catData = null) {
  editMode = !!catData;
  const overlay   = document.getElementById('modal-overlay');
  const box       = document.getElementById('modal-box');
  const modeLabel = document.getElementById('modal-mode-label');
  const mTitle    = document.getElementById('modal-title');
  const editIdEl  = document.getElementById('edit-id');
  const nomEl     = document.getElementById('input-nom');
  const iconeEl   = document.getElementById('input-icone');
  const preview   = document.getElementById('icon-preview');

  if (catData) {
    modeLabel.textContent    = 'MODIFIER LA CATÉGORIE';
    mTitle.textContent       = 'Modifier la catégorie';
    editIdEl.value           = catData.id;
    nomEl.value              = catData.nom;
    iconeEl.value            = catData.icone;
    preview.textContent      = catData.icone;
    document.getElementById('btn-save-text').textContent = 'Mettre à jour';
    // Sélectionner le bon emoji
    document.querySelectorAll('.emoji-opt').forEach(btn => {
      btn.classList.toggle('selected', btn.textContent.trim() === catData.icone);
    });
  } else {
    modeLabel.textContent    = 'NOUVELLE CATÉGORIE';
    mTitle.textContent       = 'Créer une catégorie';
    editIdEl.value           = '';
    nomEl.value              = '';
    iconeEl.value            = '📚';
    preview.textContent      = '📚';
    document.getElementById('btn-save-text').textContent = 'Enregistrer';
    document.querySelectorAll('.emoji-opt').forEach(btn => btn.classList.remove('selected'));
    document.querySelector('.emoji-opt')?.classList.add('selected');
    document.getElementById('nom-error').classList.add('hidden');
  }

  overlay.classList.remove('hidden');
  overlay.classList.add('flex');
  document.body.style.overflow = 'hidden';
  setTimeout(() => nomEl.focus(), 100);
}

function closeModal() {
  const overlay = document.getElementById('modal-overlay');
  overlay.classList.remove('flex');
  overlay.classList.add('hidden');
  document.body.style.overflow = '';
}

// Click backdrop closes modal
document.getElementById('modal-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// ── Emoji picker ─────────────────────────────────────────────
function selectEmoji(btn, emoji) {
  document.querySelectorAll('.emoji-opt').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('input-icone').value = emoji;
  document.getElementById('icon-preview').textContent = emoji;
}

// Live preview on manual input
document.getElementById('input-icone').addEventListener('input', function() {
  document.getElementById('icon-preview').textContent = this.value || '📚';
  document.querySelectorAll('.emoji-opt').forEach(b => b.classList.toggle('selected', b.textContent.trim() === this.value.trim()));
});

// ══════════════════════════════════════════════════════════════
// SAVE (ADD / EDIT)
// ══════════════════════════════════════════════════════════════
async function saveCategory() {
  const nom   = document.getElementById('input-nom').value.trim();
  const icone = document.getElementById('input-icone').value.trim() || '📚';
  const id    = document.getElementById('edit-id').value;
  const errEl = document.getElementById('nom-error');

  if (!nom) {
    errEl.classList.remove('hidden');
    document.getElementById('input-nom').focus();
    return;
  }
  errEl.classList.add('hidden');

  const btn = document.getElementById('btn-save');
  const txtEl = document.getElementById('btn-save-text');
  btn.disabled = true;
  txtEl.textContent = editMode ? 'Mise à jour…' : 'Enregistrement…';
  btn.style.opacity = '0.7';

  const body = new FormData();
  body.append('action', editMode ? 'edit' : 'add');
  body.append('nom', nom);
  body.append('icone', icone);
  if (editMode && id) body.append('id', id);

  try {
    const res = await fetch(window.location.href, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body,
    });
    const data = await res.json();

    if (data.success) {
      toast(data.message, 'success');
      closeModal();
      if (editMode) {
        updateCardInGrid(data.data);
      } else {
        addCardToGrid(data.data);
      }
      refreshStats();
    } else {
      toast(data.message || 'Erreur inconnue.', 'error');
    }
  } catch(e) {
    toast('Erreur réseau. Veuillez réessayer.', 'error');
  } finally {
    btn.disabled = false;
    txtEl.textContent = editMode ? 'Mettre à jour' : 'Enregistrer';
    btn.style.opacity = '1';
  }
}

// ══════════════════════════════════════════════════════════════
// DELETE
// ══════════════════════════════════════════════════════════════
function openDeleteModal(id, nom) {
  deleteId   = id;
  deleteName = nom;
  document.getElementById('delete-cat-name').textContent = `"${nom}"`;
  const overlay = document.getElementById('delete-overlay');
  overlay.classList.remove('hidden');
  overlay.classList.add('flex');
  document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
  const overlay = document.getElementById('delete-overlay');
  overlay.classList.remove('flex');
  overlay.classList.add('hidden');
  document.body.style.overflow = '';
  deleteId = null;
}

document.getElementById('delete-overlay').addEventListener('click', function(e){
  if(e.target === this) closeDeleteModal();
});

async function confirmDelete() {
  if (!deleteId) return;
  const btn = document.getElementById('btn-confirm-delete');
  btn.disabled = true;
  btn.textContent = 'Suppression…';

  const body = new FormData();
  body.append('action','delete');
  body.append('id', deleteId);

  try {
    const res = await fetch(window.location.href, {
      method:'POST',
      headers:{'X-Requested-With':'XMLHttpRequest'},
      body,
    });
    const data = await res.json();
    if (data.success) {
      toast(data.message, 'success');
      removeCardFromGrid(deleteId);
      closeDeleteModal();
      refreshStats();
    } else {
      toast(data.message || 'Erreur de suppression.', 'error');
    }
  } catch(e) {
    toast('Erreur réseau.', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Supprimer';
  }
}

// ══════════════════════════════════════════════════════════════
// GRID MANIPULATION
// ══════════════════════════════════════════════════════════════
function buildCardHTML(cat) {
  const nb    = parseInt(cat.nb_livres) || 0;
  const nom   = escHtml(cat.nom || '—');
  const slug  = escHtml(cat.slug || '—');
  const icone = escHtml(cat.icone || '📚');
  const date  = cat.created_at ? new Date(cat.created_at).toLocaleDateString('fr-FR',{day:'2-digit',month:'short',year:'numeric'}) : '—';
  const catObj = JSON.stringify({id:cat.id,nom:cat.nom||'',icone:cat.icone||'📚',slug:cat.slug||''}).replace(/'/g,"&#39;");

  return `
  <div class="cat-card rounded-2xl overflow-hidden group cursor-default"
    style="background:#10121a;border:1px solid rgba(255,255,255,0.07)"
    data-cat-id="${cat.id}" data-cat-nom="${nom.toLowerCase()}">

    <!-- Cover strip -->
    <div class="relative h-24 flex items-center justify-center overflow-hidden"
      style="background:linear-gradient(135deg,rgba(232,201,125,0.07),rgba(62,207,163,0.05))">
      <div class="text-5xl transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3">${icone}</div>
      <!-- Action buttons -->
      <div class="absolute top-3 right-3 flex gap-2 opacity-0 group-hover:opacity-100 transition-all duration-200 translate-y-1 group-hover:translate-y-0">
        <button onclick="editCat('${cat.id}','${nom}','${icone}','${slug}')" title="Modifier"
          class="w-8 h-8 rounded-xl flex items-center justify-center text-xs transition-all hover:scale-110"
          style="background:rgba(232,201,125,0.15);border:1px solid rgba(232,201,125,0.25);color:#e8c97d">
          ✏️
        </button>
        <button onclick="openDeleteModal(${cat.id},'${nom}')" title="Supprimer"
          class="w-8 h-8 rounded-xl flex items-center justify-center text-xs transition-all hover:scale-110"
          style="background:rgba(231,76,60,0.12);border:1px solid rgba(231,76,60,0.2);color:#e74c3c">
          🗑️
        </button>
      </div>
    </div>

    <!-- Body -->
    <div class="p-5">
      <div class="font-display font-700 text-base tracking-tight mb-1" style="font-family:Syne,sans-serif;font-weight:700">${nom}</div>
      <div class="font-mono text-xs mb-4 truncate" style="font-family:'JetBrains Mono',monospace;color:rgba(240,238,234,0.25);font-size:0.6rem">/${slug}</div>

      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <div class="w-2 h-2 rounded-full" style="background:${nb>0?'#3ecfa3':'rgba(255,255,255,0.2)'}"></div>
          <span class="text-xs font-semibold" style="color:${nb>0?'#3ecfa3':'rgba(240,238,234,0.3)'}">${nb} livre${nb>1?'s':''}</span>
        </div>
        <span class="text-xs" style="color:rgba(240,238,234,0.25);font-family:'JetBrains Mono',monospace;font-size:0.6rem">${date}</span>
      </div>
    </div>

    <!-- Bottom accent -->
    <div class="h-0.5 w-full opacity-0 group-hover:opacity-100 transition-opacity duration-300 shimmer-border"></div>
  </div>`;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function addCardToGrid(cat) {
  const grid = document.getElementById('cats-grid');
  const empty = document.getElementById('empty-state');
  empty.classList.add('hidden');

  const wrap = document.createElement('div');
  wrap.className = 'cat-card-wrap animate-scale-in';
  wrap.setAttribute('data-search', (cat.nom||'').toLowerCase());
  wrap.innerHTML = buildCardHTML(cat);
  grid.appendChild(wrap);

  updateGridCount();
}

function updateCardInGrid(cat) {
  const card = document.querySelector(`[data-cat-id="${cat.id}"]`);
  if (!card) { refreshGrid(); return; }
  const wrap = card.closest('.cat-card-wrap');
  if (wrap) {
    wrap.setAttribute('data-search', (cat.nom||'').toLowerCase());
    wrap.innerHTML = buildCardHTML(cat);
  }
}

function removeCardFromGrid(id) {
  const card = document.querySelector(`[data-cat-id="${id}"]`);
  if (!card) return;
  const wrap = card.closest('.cat-card-wrap');
  if (wrap) {
    wrap.style.transition = 'all 0.3s ease';
    wrap.style.opacity = '0';
    wrap.style.transform = 'scale(0.9)';
    setTimeout(() => {
      wrap.remove();
      updateGridCount();
      const grid = document.getElementById('cats-grid');
      if (!grid.querySelector('.cat-card-wrap')) {
        document.getElementById('empty-state').classList.remove('hidden');
      }
    }, 300);
  }
}

function updateGridCount() {
  const total = document.querySelectorAll('#cats-grid .cat-card-wrap').length;
  const el = document.getElementById('grid-count');
  if (el) el.textContent = total + ' entrée' + (total>1?'s':'');
}

// ══════════════════════════════════════════════════════════════
// EDIT HELPER
// ══════════════════════════════════════════════════════════════
function editCat(id, nom, icone, slug) {
  openModal({ id, nom, icone, slug });
}

// ══════════════════════════════════════════════════════════════
// REFRESH FULL GRID (fallback)
// ══════════════════════════════════════════════════════════════
async function refreshGrid() {
  try {
    const res = await fetch(`${window.location.href}?action=list`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (!data.success) return;
    const grid = document.getElementById('cats-grid');
    grid.innerHTML = '';
    if (data.data.length === 0) {
      document.getElementById('empty-state').classList.remove('hidden');
    } else {
      document.getElementById('empty-state').classList.add('hidden');
      data.data.forEach(cat => {
        const wrap = document.createElement('div');
        wrap.className = 'cat-card-wrap animate-scale-in';
        wrap.setAttribute('data-search', (cat.nom||'').toLowerCase());
        wrap.innerHTML = buildCardHTML(cat);
        grid.appendChild(wrap);
      });
    }
    updateGridCount();
  } catch(e) {}
}

// ══════════════════════════════════════════════════════════════
// REFRESH STATS
// ══════════════════════════════════════════════════════════════
async function refreshStats() {
  try {
    const res = await fetch(`${window.location.href}?action=stats`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (!data.success) return;
    animateCount('stat-total', data.total);
    animateCount('stat-livres', data.nb_livres);
    const topNom = document.getElementById('stat-top-nom');
    const topNb  = document.getElementById('stat-top-nb');
    const topIcon= document.getElementById('stat-top-icon');
    if (topNom)  topNom.textContent  = data.top.nom;
    if (topNb)   topNb.textContent   = data.top.nb + ' livres liés';
    if (topIcon) topIcon.textContent = data.top.icone;
    updateGridCount();
  } catch(e) {}
}

function animateCount(id, target) {
  const el = document.getElementById(id);
  if (!el) return;
  const start = parseInt(el.textContent) || 0;
  const diff  = target - start;
  const dur   = 600;
  const step  = 16;
  const steps = dur / step;
  let cur = 0;
  const t = setInterval(() => {
    cur++;
    el.textContent = Math.round(start + (diff * cur/steps));
    if (cur >= steps) { el.textContent = target; clearInterval(t); }
  }, step);
}

// ══════════════════════════════════════════════════════════════
// FILTER / SEARCH
// ══════════════════════════════════════════════════════════════
function filterCards(q) {
  const query = q.toLowerCase().trim();
  document.querySelectorAll('#cats-grid .cat-card-wrap').forEach(wrap => {
    const match = !query || (wrap.dataset.search||'').includes(query);
    wrap.style.display = match ? '' : 'none';
  });
  const visible = document.querySelectorAll('#cats-grid .cat-card-wrap:not([style*="none"])').length;
  const gc = document.getElementById('grid-count');
  if (gc) gc.textContent = visible + ' entrée' + (visible>1?'s':'');
}

// ══════════════════════════════════════════════════════════════
// KEYBOARD
// ══════════════════════════════════════════════════════════════
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeModal();
    closeDeleteModal();
  }
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    const modalVisible = !document.getElementById('modal-overlay').classList.contains('hidden');
    if (modalVisible) saveCategory();
  }
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
    e.preventDefault();
    document.getElementById('search-input')?.focus();
  }
});

// Enter key in nom field
document.getElementById('input-nom').addEventListener('keydown', e => {
  if (e.key === 'Enter') saveCategory();
});
</script>

<?php
// Helper PHP : génère le HTML d'une card catégorie côté serveur
function buildCatCard(array $cat): string {
    $nb    = (int)($cat['nb_livres'] ?? 0);
    $nom   = htmlspecialchars($cat['nom']  ?? '—', ENT_QUOTES);
    $slug  = htmlspecialchars($cat['slug'] ?? '—', ENT_QUOTES);
    $icone = htmlspecialchars($cat['icone'] ?? '📚', ENT_QUOTES);
    $id    = (int)$cat['id'];
    $dateStr = isset($cat['created_at']) ? date('d M Y', strtotime($cat['created_at'])) : '—';
    $color = $nb > 0 ? '#3ecfa3' : 'rgba(255,255,255,0.2)';
    $txt   = $nb > 0 ? '#3ecfa3' : 'rgba(240,238,234,0.3)';

    return "
    <div class='cat-card rounded-2xl overflow-hidden group cursor-default'
      style='background:#10121a;border:1px solid rgba(255,255,255,0.07)'
      data-cat-id='{$id}' data-cat-nom='" . strtolower($nom) . "'>
      <div class='relative h-24 flex items-center justify-center overflow-hidden'
        style='background:linear-gradient(135deg,rgba(232,201,125,0.07),rgba(62,207,163,0.05))'>
        <div class='text-5xl transition-transform duration-500 group-hover:scale-110 group-hover:rotate-3'>{$icone}</div>
        <div class='absolute top-3 right-3 flex gap-2 opacity-0 group-hover:opacity-100 transition-all duration-200 translate-y-1 group-hover:translate-y-0'>
          <button onclick=\"editCat('{$id}','{$nom}','{$icone}','{$slug}')\" title='Modifier'
            class='w-8 h-8 rounded-xl flex items-center justify-center text-xs transition-all hover:scale-110'
            style='background:rgba(232,201,125,0.15);border:1px solid rgba(232,201,125,0.25);color:#e8c97d'>✏️</button>
          <button onclick=\"openDeleteModal({$id},'{$nom}')\" title='Supprimer'
            class='w-8 h-8 rounded-xl flex items-center justify-center text-xs transition-all hover:scale-110'
            style='background:rgba(231,76,60,0.12);border:1px solid rgba(231,76,60,0.2);color:#e74c3c'>🗑️</button>
        </div>
      </div>
      <div class='p-5'>
        <div class='font-display font-700 text-base tracking-tight mb-1' style='font-family:Syne,sans-serif;font-weight:700'>{$nom}</div>
        <div class='font-mono text-xs mb-4 truncate' style='font-family:JetBrains Mono,monospace;color:rgba(240,238,234,0.25);font-size:0.6rem'>/{$slug}</div>
        <div class='flex items-center justify-between'>
          <div class='flex items-center gap-2'>
            <div class='w-2 h-2 rounded-full' style='background:{$color}'></div>
            <span class='text-xs font-semibold' style='color:{$txt}'>{$nb} livre" . ($nb>1?'s':'') . "</span>
          </div>
          <span class='text-xs' style='color:rgba(240,238,234,0.25);font-family:JetBrains Mono,monospace;font-size:0.6rem'>{$dateStr}</span>
        </div>
      </div>
      <div class='h-0.5 w-full opacity-0 group-hover:opacity-100 transition-opacity duration-300 shimmer-border'></div>
    </div>";
}
?>
</body>
</html>