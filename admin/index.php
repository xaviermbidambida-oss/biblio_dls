<?php
// ============================================================
// admin/stats.php — Digital Library Analytics Dashboard
// Version 2.1 | SaaS Premium UI | Real-time AJAX + PDO
// ============================================================

session_start();

// ── DB CONFIG ───────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'digital_library');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ── PDO SINGLETON ────────────────────────────────────────────
function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}



if ($userRole === 'admin'): ?>
  <div class="nav-section">Administration</div>
  
  <a href="<?= BASE_URL ?>users/index.php" class="nav-item">
    <span class="nav-icon"><i class="bi bi-people"></i></span>
    <span class="nav-label">Utilisateurs</span>
    <?php $uc = (int)($data['stats']['totalUsers'] ?? 0); if ($uc): ?>
      <span class="nav-badge"><?= $uc > 999 ? round($uc/1000,1).'K' : $uc ?></span>
    <?php endif; ?>
  </a>
  
  <a href="<?= BASE_URL ?>books/index.php" class="nav-item">
    <span class="nav-icon"><i class="bi bi-book-half"></i></span>
    <span class="nav-label">Gestion livres</span>
  </a>
  
  <a href="<?= BASE_URL ?>books/create.php" class="nav-item">
    <span class="nav-icon"><i class="bi bi-plus-square"></i></span>
    <span class="nav-label">Ajouter un livre</span>
  </a>
  
  <a href="<?= BASE_URL ?>admin/sales.php" class="nav-item">
    <span class="nav-icon"><i class="bi bi-cash-coin"></i></span>
    <span class="nav-label">Ventes</span>
  </a>
  
  <a href="<?= BASE_URL ?>admin/categories.php" class="nav-item">
    <span class="nav-icon"><i class="bi bi-tags"></i></span>
    <span class="nav-label">Catégories</span>
  </a>
  
  <a href="<?= BASE_URL ?>admin/stats.php" class="nav-item">
    <span class="nav-icon"><i class="bi bi-graph-up-arrow"></i></span>
    <span class="nav-label">Statistiques</span>
  </a>
  
  <a href="<?= BASE_URL ?>admin/settings.php" class="nav-item">
    <span class="nav-icon"><i class="bi bi-gear"></i></span>
    <span class="nav-label">Paramètres</span>
  </a>
  <?php endif; 

// ── SECURITY: Admin only ──────────────────────────────────────
function requireAdmin(): void {
    if (
        !isset($_SESSION['user_id'], $_SESSION['role']) ||
        $_SESSION['role'] !== 'admin'
    ) {
        http_response_code(403);
        header('Location: /login.php');
        exit;
    }
}

// ── PERIOD HELPER ─────────────────────────────────────────────
function getPeriodDates(string $period): array {
    $allowed = ['day', 'month', 'year'];
    $period  = in_array($period, $allowed, true) ? $period : 'month';

    return match ($period) {
        'day'   => ['start' => date('Y-m-d'), 'end' => date('Y-m-d'), 'label' => "Aujourd'hui"],
        'year'  => ['start' => date('Y-01-01'), 'end' => date('Y-12-31'), 'label' => 'Cette année'],
        default => ['start' => date('Y-m-01'), 'end' => date('Y-m-t'), 'label' => 'Ce mois'],
    };
}

// ============================================================
// ── STATS FUNCTIONS (PDO REAL QUERIES) ──────────────────────
// ============================================================

/** Global KPI cards */
function getGlobalStats(string $start, string $end): array {
    $pdo = getPDO();

    $revenue = (float) $pdo->prepare(
        "SELECT COALESCE(SUM(montant),0) FROM achats
         WHERE statut='confirme' AND DATE(created_at) BETWEEN :s AND :e"
    ); 
    $stRevenue = $pdo->prepare(
        "SELECT COALESCE(SUM(montant),0) AS rev FROM achats
         WHERE statut='confirme' AND DATE(created_at) BETWEEN :s AND :e"
    );
    $stRevenue->execute([':s' => $start, ':e' => $end]);
    $revenue = (float) ($stRevenue->fetchColumn() ?: 0);

    $stSales = $pdo->prepare(
        "SELECT COUNT(*) FROM achats WHERE statut='confirme' AND DATE(created_at) BETWEEN :s AND :e"
    );
    $stSales->execute([':s' => $start, ':e' => $end]);
    $sales = (int) $stSales->fetchColumn();

    $stUsers = $pdo->prepare(
        "SELECT COUNT(*) FROM users WHERE DATE(created_at) BETWEEN :s AND :e"
    );
    $stUsers->execute([':s' => $start, ':e' => $end]);
    $newUsers = (int) $stUsers->fetchColumn();

    $stTotal = $pdo->query("SELECT COUNT(*) FROM users WHERE statut='actif'");
    $totalUsers = (int) $stTotal->fetchColumn();

    $stBooks = $pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'");
    $totalBooks = (int) $stBooks->fetchColumn();

    // Previous period delta
    $days = max(1, (strtotime($end) - strtotime($start)) / 86400 + 1);
    $prevEnd   = date('Y-m-d', strtotime($start) - 86400);
    $prevStart = date('Y-m-d', strtotime($prevEnd) - ($days - 1) * 86400);

    $stPrev = $pdo->prepare(
        "SELECT COALESCE(SUM(montant),0) FROM achats
         WHERE statut='confirme' AND DATE(created_at) BETWEEN :s AND :e"
    );
    $stPrev->execute([':s' => $prevStart, ':e' => $prevEnd]);
    $prevRevenue = (float) ($stPrev->fetchColumn() ?: 1);
    $revDelta = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : 0;

    return compact('revenue', 'sales', 'newUsers', 'totalUsers', 'totalBooks', 'revDelta');
}

/** Daily sales for line chart (last 30 days or filtered) */
function getRevenueStats(string $start, string $end): array {
    $pdo = getPDO();
    $st  = $pdo->prepare(
        "SELECT DATE(created_at) AS jour,
                COUNT(*) AS nb_ventes,
                COALESCE(SUM(montant),0) AS total
         FROM achats
         WHERE statut='confirme' AND DATE(created_at) BETWEEN :s AND :e
         GROUP BY jour ORDER BY jour ASC"
    );
    $st->execute([':s' => $start, ':e' => $end]);
    return $st->fetchAll();
}

/** Monthly revenue for bar chart (current year) */
function getMonthlyRevenue(): array {
    $pdo = getPDO();
    $st  = $pdo->prepare(
        "SELECT MONTH(created_at) AS mois,
                COALESCE(SUM(montant),0) AS total,
                COUNT(*) AS ventes
         FROM achats
         WHERE statut='confirme' AND YEAR(created_at) = YEAR(NOW())
         GROUP BY mois ORDER BY mois ASC"
    );
    $st->execute();
    return $st->fetchAll();
}

/** Category breakdown for pie chart */
function getCategoryStats(): array {
    $pdo = getPDO();
    $st  = $pdo->query(
        "SELECT c.nom AS categorie,
                COUNT(a.id) AS ventes,
                COALESCE(SUM(a.montant),0) AS total
         FROM categories c
         LEFT JOIN livres l ON l.categorie_id = c.id
         LEFT JOIN achats a ON a.livre_id = l.id AND a.statut='confirme'
         GROUP BY c.id, c.nom
         HAVING ventes > 0
         ORDER BY ventes DESC"
    );
    return $st->fetchAll();
}

/** Top 10 best-selling books */
function getTopBooks(): array {
    $pdo = getPDO();
    $st  = $pdo->query(
        "SELECT l.id, l.titre, l.auteur, l.prix,
                l.couverture, l.note_moyenne,
                COUNT(a.id) AS nb_ventes,
                COALESCE(SUM(a.montant),0) AS total_revenu
         FROM livres l
         LEFT JOIN achats a ON a.livre_id = l.id AND a.statut='confirme'
         GROUP BY l.id
         ORDER BY nb_ventes DESC
         LIMIT 10"
    );
    return $st->fetchAll();
}

/** Recent activity (last 20 events) */
function getUserActivity(): array {
    $pdo = getPDO();
    $st  = $pdo->query(
        "SELECT 'achat' AS type,
                CONCAT(u.prenom,' ',u.nom) AS acteur,
                l.titre AS detail,
                a.montant,
                a.created_at
         FROM achats a
         JOIN users u ON u.id = a.user_id
         JOIN livres l ON l.id = a.livre_id
         WHERE a.statut='confirme'
         UNION ALL
         SELECT 'inscription',
                CONCAT(prenom,' ',nom),
                email, NULL, created_at
         FROM users
         ORDER BY created_at DESC
         LIMIT 20"
    );
    return $st->fetchAll();
}

// ── EXPORT CSV ────────────────────────────────────────────────
function exportCSV(string $type, string $start, string $end): void {
    $pdo  = getPDO();
    $rows = [];
    $filename = '';

    if ($type === 'achats') {
        $st = $pdo->prepare(
            "SELECT a.reference, CONCAT(u.prenom,' ',u.nom) AS client,
                    u.email, l.titre AS livre, a.montant, a.methode, a.statut, a.created_at
             FROM achats a
             JOIN users u ON u.id = a.user_id
             JOIN livres l ON l.id = a.livre_id
             WHERE DATE(a.created_at) BETWEEN :s AND :e
             ORDER BY a.created_at DESC"
        );
        $st->execute([':s' => $start, ':e' => $end]);
        $rows     = $st->fetchAll();
        $filename = 'achats_' . $start . '_' . $end . '.csv';
    } elseif ($type === 'users') {
        $st = $pdo->prepare(
            "SELECT id, nom, prenom, email, role, statut, created_at
             FROM users WHERE DATE(created_at) BETWEEN :s AND :e ORDER BY created_at DESC"
        );
        $st->execute([':s' => $start, ':e' => $end]);
        $rows     = $st->fetchAll();
        $filename = 'utilisateurs_' . $start . '_' . $end . '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ── AJAX ENDPOINT ─────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    requireAdmin();
    header('Content-Type: application/json');

    $period = $_GET['period'] ?? 'month';
    $dates  = getPeriodDates($period);

    // Export shortcut
    if (isset($_GET['export'])) {
        exportCSV($_GET['export'], $dates['start'], $dates['end']);
    }

    echo json_encode([
        'ok'        => true,
        'ts'        => date('H:i:s'),
        'period'    => $dates,
        'kpi'       => getGlobalStats($dates['start'], $dates['end']),
        'daily'     => getRevenueStats($dates['start'], $dates['end']),
        'monthly'   => getMonthlyRevenue(),
        'categories'=> getCategoryStats(),
        'topBooks'  => getTopBooks(),
        'activity'  => getUserActivity(),
    ]);
    exit;
}

// ── NORMAL PAGE LOAD ──────────────────────────────────────────
requireAdmin();

$period = $_GET['period'] ?? 'month';
if (isset($_GET['export'])) {
    $dates = getPeriodDates($period);
    exportCSV($_GET['export'], $dates['start'], $dates['end']);
}

$dates      = getPeriodDates($period);
$kpi        = getGlobalStats($dates['start'], $dates['end']);
$topBooks   = getTopBooks();
$activity   = getUserActivity();
$monthly    = getMonthlyRevenue();
$daily      = getRevenueStats($dates['start'], $dates['end']);
$categories = getCategoryStats();

$adminName   = htmlspecialchars($_SESSION['admin_name'] ?? 'Administrateur');
$adminAvatar = htmlspecialchars($_SESSION['avatar'] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics Dashboard — Bibliothèque Numérique</title>

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<!-- Tailwind CSS CDN -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      fontFamily: {
        display: ['Syne', 'sans-serif'],
        body: ['DM Sans', 'sans-serif'],
      },
      colors: {
        ink: '#0d0f14',
        surface: '#13161e',
        card: '#1a1e2a',
        border: '#252936',
        muted: '#8891aa',
        accent: { DEFAULT:'#6366f1', light:'#818cf8', dark:'#4f46e5' },
        emerald2: '#10b981',
        amber2: '#f59e0b',
        rose2: '#f43f5e',
        sky2: '#38bdf8',
      },
      boxShadow: {
        glow: '0 0 40px -10px rgba(99,102,241,.45)',
        card: '0 4px 24px rgba(0,0,0,.35)',
      },
    }
  }
}
</script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
  /* ── RESET / BASE ─────────────────────────────── */
  *, *::before, *::after { box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body {
    font-family: 'DM Sans', sans-serif;
    background: #0d0f14;
    color: #e2e4ef;
    min-height: 100vh;
  }

  /* ── ANIMATIONS ───────────────────────────────── */
  @keyframes fadeUp {
    from { opacity:0; transform:translateY(18px); }
    to   { opacity:1; transform:translateY(0); }
  }
  @keyframes pulse-ring {
    0%   { transform:scale(.9); opacity:.8; }
    70%  { transform:scale(1.3); opacity:0; }
    100% { transform:scale(.9); opacity:0; }
  }
  @keyframes shimmer {
    0%   { background-position: -400px 0; }
    100% { background-position:  400px 0; }
  }
  @keyframes countUp { from { opacity:0; } to { opacity:1; } }
  @keyframes spin { to { transform: rotate(360deg); } }

  .fade-up   { animation: fadeUp .55s cubic-bezier(.22,1,.36,1) both; }
  .delay-1   { animation-delay:.08s; }
  .delay-2   { animation-delay:.16s; }
  .delay-3   { animation-delay:.24s; }
  .delay-4   { animation-delay:.32s; }
  .delay-5   { animation-delay:.40s; }

  /* ── GLASS CARD ───────────────────────────────── */
  .glass-card {
    background: rgba(26,30,42,.85);
    backdrop-filter: blur(14px) saturate(160%);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 18px;
    transition: transform .25s ease, box-shadow .25s ease;
  }
  .glass-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,.45); }

  /* ── KPI CARD ─────────────────────────────────── */
  .kpi-card {
    position: relative; overflow: hidden;
    background: rgba(26,30,42,.9);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 20px;
    padding: 1.6rem;
    transition: transform .25s ease, box-shadow .25s ease;
    cursor: default;
  }
  .kpi-card::before {
    content:''; position:absolute; inset:0;
    background: linear-gradient(135deg, rgba(255,255,255,.04) 0%, transparent 60%);
    pointer-events: none;
  }
  .kpi-card:hover { transform: translateY(-5px) scale(1.02); box-shadow: 0 16px 48px rgba(0,0,0,.5); }
  .kpi-icon {
    width: 52px; height: 52px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem;
  }
  .kpi-value { font-family:'Syne',sans-serif; font-size:2.1rem; font-weight:800; letter-spacing:-.02em; }
  .kpi-label { font-size:.78rem; font-weight:500; letter-spacing:.06em; text-transform:uppercase; color:#8891aa; }
  .kpi-delta { font-size:.78rem; font-weight:600; border-radius:20px; padding:2px 10px; }
  .delta-pos { background:rgba(16,185,129,.15); color:#34d399; }
  .delta-neg { background:rgba(244,63,94,.15);  color:#fb7185; }

  /* ── LIVE BADGE ───────────────────────────────── */
  .live-badge {
    display:inline-flex; align-items:center; gap:6px;
    background: rgba(244,63,94,.12);
    border: 1px solid rgba(244,63,94,.25);
    border-radius: 50px; padding: 4px 12px;
    font-size:.72rem; font-weight:600; letter-spacing:.08em;
    color: #fb7185; text-transform: uppercase;
  }
  .live-dot {
    width:7px; height:7px; border-radius:50%; background:#f43f5e;
    position: relative;
  }
  .live-dot::before {
    content:''; position:absolute; inset:-2px; border-radius:50%;
    background: #f43f5e; opacity:.6;
    animation: pulse-ring 1.6s ease-out infinite;
  }

  /* ── TABLE ────────────────────────────────────── */
  .data-table { width:100%; border-collapse: separate; border-spacing: 0 4px; }
  .data-table thead th {
    font-size:.7rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase;
    color:#8891aa; padding:.6rem 1rem; text-align:left;
  }
  .data-table tbody tr {
    background: rgba(255,255,255,.03); border-radius:10px;
    transition: background .2s;
  }
  .data-table tbody tr:hover { background: rgba(99,102,241,.08); }
  .data-table tbody td { padding:.75rem 1rem; font-size:.875rem; }
  .data-table tbody td:first-child { border-radius:10px 0 0 10px; }
  .data-table tbody td:last-child  { border-radius:0 10px 10px 0; }

  /* ── SKELETON ─────────────────────────────────── */
  .skeleton {
    background: linear-gradient(90deg, rgba(255,255,255,.05) 25%, rgba(255,255,255,.1) 37%, rgba(255,255,255,.05) 63%);
    background-size: 400px 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 8px;
  }

  /* ── RANK BADGE ───────────────────────────────── */
  .rank-badge {
    width:28px; height:28px; border-radius:8px;
    display:inline-flex; align-items:center; justify-content:center;
    font-family:'Syne',sans-serif; font-weight:800; font-size:.8rem;
  }

  /* ── SCROLLBAR ────────────────────────────────── */
  ::-webkit-scrollbar { width:6px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#252936; border-radius:6px; }

  /* ── PERIOD TABS ──────────────────────────────── */
  .period-tab {
    padding:.4rem 1.1rem; border-radius:8px; font-size:.8rem;
    font-weight:500; transition:.2s; cursor:pointer;
    border: 1px solid transparent; color:#8891aa;
  }
  .period-tab:hover { color:#e2e4ef; background:rgba(255,255,255,.05); }
  .period-tab.active { background:#6366f1; color:#fff; border-color:#6366f1; }

  /* ── SPINNER ──────────────────────────────────── */
  .spinner {
    width:18px; height:18px; border-radius:50%;
    border:2px solid rgba(255,255,255,.15);
    border-top-color:#6366f1;
    animation: spin .7s linear infinite;
  }

  /* ── ACTIVITY TIMELINE ────────────────────────── */
  .timeline-item { position:relative; padding-left: 2rem; }
  .timeline-item::before {
    content:''; position:absolute; left:.55rem; top:1.5rem;
    width:1px; height:calc(100% + 4px);
    background: rgba(255,255,255,.07);
  }
  .timeline-item:last-child::before { display:none; }
  .timeline-dot {
    position:absolute; left:0; top:.55rem;
    width:18px; height:18px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:.65rem;
  }

  /* ── GRADIENT MESH BG ─────────────────────────── */
  .mesh-bg {
    position:fixed; inset:0; pointer-events:none; z-index:0;
    background:
      radial-gradient(ellipse 60% 50% at 20% -10%, rgba(99,102,241,.12) 0%, transparent 60%),
      radial-gradient(ellipse 50% 40% at 80% 110%, rgba(16,185,129,.07) 0%, transparent 60%),
      radial-gradient(ellipse 40% 60% at 60% 50%, rgba(56,189,248,.04) 0%, transparent 60%);
  }
</style>
</head>

<body class="antialiased">

<!-- Mesh Background -->
<div class="mesh-bg"></div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SIDEBAR                                                 -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="fixed inset-y-0 left-0 z-40 w-64 flex flex-col"
     style="background:rgba(13,15,20,.97);border-right:1px solid #252936;">

  <!-- Logo -->
  <div class="px-6 pt-7 pb-6 flex items-center gap-3">
    <div class="w-9 h-9 rounded-xl flex items-center justify-center text-xl"
         style="background:linear-gradient(135deg,#6366f1,#818cf8);">📚</div>
    <div>
      <div class="font-display font-800 text-sm text-white">BiblioNum</div>
      <div class="text-xs text-muted">Admin Console</div>
    </div>
  </div>

  <!-- Nav -->
  <nav class="flex-1 px-4 py-2 space-y-1">
    <?php
    $navItems = [
      ['icon'=>'⚡','label'=>'Dashboard','href'=>'dashboard.php','active'=>false],
      ['icon'=>'📊','label'=>'Analytics','href'=>'stats.php','active'=>true],
      ['icon'=>'📚','label'=>'Livres','href'=>'livres.php','active'=>false],
      ['icon'=>'👤','label'=>'Utilisateurs','href'=>'users.php','active'=>false],
      ['icon'=>'💰','label'=>'Achats','href'=>'achats.php','active'=>false],
      ['icon'=>'📦','label'=>'Catégories','href'=>'categories.php','active'=>false],
      ['icon'=>'⚙️','label'=>'Paramètres','href'=>'settings.php','active'=>false],
    ];
    foreach($navItems as $item): ?>
      <a href="<?= $item['href'] ?>"
         class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all duration-200
                <?= $item['active']
                    ? 'bg-accent text-white font-medium shadow-glow'
                    : 'text-muted hover:text-white hover:bg-white/5' ?>">
        <span class="text-base"><?= $item['icon'] ?></span>
        <?= $item['label'] ?>
        <?php if($item['active']): ?>
          <div class="ml-auto w-1.5 h-1.5 rounded-full bg-white/60"></div>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- User card bottom -->
  <div class="px-4 pb-6">
    <div class="flex items-center gap-3 px-3 py-3 rounded-xl" style="background:rgba(255,255,255,.04);border:1px solid #252936;">
      <?php if($adminAvatar): ?>
        <img src="<?= $adminAvatar ?>" class="w-9 h-9 rounded-full object-cover">
      <?php else: ?>
        <div class="w-9 h-9 rounded-full bg-accent flex items-center justify-center text-sm font-display font-700 text-white">
          <?= mb_strtoupper(mb_substr($adminName,0,1)) ?>
        </div>
      <?php endif; ?>
      <div class="flex-1 min-w-0">
        <div class="text-sm font-medium text-white truncate"><?= $adminName ?></div>
        <div class="text-xs text-muted">Administrateur</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- MAIN CONTENT                                             -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="pl-64 relative z-10 min-h-screen">

  <!-- ── TOP HEADER ───────────────────────────────────── -->
  <header class="sticky top-0 z-30 px-8 py-4 flex items-center justify-between"
          style="background:rgba(13,15,20,.85);backdrop-filter:blur(20px);border-bottom:1px solid #252936;">

    <div class="flex items-center gap-4">
      <div>
        <h1 class="font-display font-800 text-xl text-white tracking-tight">Analytics Dashboard</h1>
        <p class="text-xs text-muted mt-0.5" id="header-date">Chargement...</p>
      </div>
      <div class="live-badge">
        <div class="live-dot"></div>
        LIVE
      </div>
    </div>

    <div class="flex items-center gap-3">
      <!-- Period tabs -->
      <div class="flex items-center gap-1 p-1 rounded-xl" style="background:rgba(255,255,255,.04);border:1px solid #252936;">
        <button class="period-tab <?= $period==='day'?'active':'' ?>" onclick="setPeriod('day')">Jour</button>
        <button class="period-tab <?= $period==='month'?'active':'' ?>" onclick="setPeriod('month')">Mois</button>
        <button class="period-tab <?= $period==='year'?'active':'' ?>" onclick="setPeriod('year')">Année</button>
      </div>

      <!-- Export buttons -->
      <div class="flex gap-2">
        <button onclick="exportData('achats')"
                class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium transition"
                style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#34d399;">
          ⬇ Achats CSV
        </button>
        <button onclick="exportData('users')"
                class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium transition"
                style="background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.25);color:#818cf8;">
          ⬇ Users CSV
        </button>
      </div>

      <!-- Refresh indicator -->
      <div class="flex items-center gap-2 text-xs text-muted">
        <div id="spinner" class="spinner hidden"></div>
        <span id="last-update">—</span>
      </div>
    </div>
  </header>

  <!-- ── PAGE CONTENT ──────────────────────────────────── -->
  <main class="px-8 py-8 space-y-8">

    <!-- KPI CARDS -->
    <section id="kpi-section" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <!-- Revenue -->
      <div class="kpi-card fade-up delay-1">
        <div class="flex items-start justify-between mb-4">
          <div class="kpi-icon" style="background:linear-gradient(135deg,rgba(99,102,241,.25),rgba(99,102,241,.1));">💰</div>
          <span class="kpi-delta <?= $kpi['revDelta']>=0?'delta-pos':'delta-neg' ?>" id="kpi-delta">
            <?= ($kpi['revDelta']>=0?'+':'').$kpi['revDelta'] ?>%
          </span>
        </div>
        <div class="kpi-value text-white" id="kpi-revenue">
          <?= number_format($kpi['revenue'],0,',',' ') ?> FCFA
        </div>
        <div class="kpi-label mt-1">Revenus</div>
        <div class="mt-3 h-1 rounded-full bg-white/5">
          <div class="h-1 rounded-full bg-accent/60 transition-all duration-700" style="width:<?= min(100,max(10,$kpi['revenue']/1000)) ?>%"></div>
        </div>
      </div>

      <!-- Ventes -->
      <div class="kpi-card fade-up delay-2">
        <div class="flex items-start justify-between mb-4">
          <div class="kpi-icon" style="background:linear-gradient(135deg,rgba(16,185,129,.25),rgba(16,185,129,.1));">📚</div>
          <span class="kpi-delta delta-pos text-xs">Achats confirmés</span>
        </div>
        <div class="kpi-value text-white" id="kpi-sales"><?= number_format($kpi['sales']) ?></div>
        <div class="kpi-label mt-1">Ventes</div>
        <div class="mt-3 h-1 rounded-full bg-white/5">
          <div class="h-1 rounded-full transition-all duration-700" style="width:<?= min(100,max(10,$kpi['sales']*2)) ?>%;background:#10b981;"></div>
        </div>
      </div>

      <!-- New Users -->
      <div class="kpi-card fade-up delay-3">
        <div class="flex items-start justify-between mb-4">
          <div class="kpi-icon" style="background:linear-gradient(135deg,rgba(56,189,248,.25),rgba(56,189,248,.1));">👤</div>
          <span class="kpi-delta delta-pos text-xs"><?= $kpi['totalUsers'] ?> actifs</span>
        </div>
        <div class="kpi-value text-white" id="kpi-new-users"><?= number_format($kpi['newUsers']) ?></div>
        <div class="kpi-label mt-1">Nouveaux membres</div>
        <div class="mt-3 h-1 rounded-full bg-white/5">
          <div class="h-1 rounded-full transition-all duration-700" style="width:<?= min(100,max(10,$kpi['newUsers']*5)) ?>%;background:#38bdf8;"></div>
        </div>
      </div>

      <!-- Livres -->
      <div class="kpi-card fade-up delay-4">
        <div class="flex items-start justify-between mb-4">
          <div class="kpi-icon" style="background:linear-gradient(135deg,rgba(245,158,11,.25),rgba(245,158,11,.1));">🎁</div>
          <span class="kpi-delta delta-pos text-xs">Catalogue</span>
        </div>
        <div class="kpi-value text-white" id="kpi-books"><?= number_format($kpi['totalBooks']) ?></div>
        <div class="kpi-label mt-1">Livres disponibles</div>
        <div class="mt-3 h-1 rounded-full bg-white/5">
          <div class="h-1 rounded-full transition-all duration-700" style="width:<?= min(100,max(10,$kpi['totalBooks']/10)) ?>%;background:#f59e0b;"></div>
        </div>
      </div>
    </section>

    <!-- CHARTS ROW 1 -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 fade-up delay-2">

      <!-- Line chart — Ventes/Revenus par jour -->
      <div class="glass-card p-6 lg:col-span-2">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h2 class="font-display font-700 text-white text-base">Ventes & Revenus</h2>
            <p class="text-xs text-muted mt-0.5">Évolution sur la période sélectionnée</p>
          </div>
          <div class="flex items-center gap-4 text-xs text-muted">
            <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 rounded bg-accent inline-block"></span>Revenus</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 rounded bg-emerald-400 inline-block"></span>Ventes</span>
          </div>
        </div>
        <div class="relative" style="height:240px;">
          <canvas id="lineChart"></canvas>
        </div>
      </div>

      <!-- Pie chart — Catégories -->
      <div class="glass-card p-6">
        <div class="mb-6">
          <h2 class="font-display font-700 text-white text-base">Catégories</h2>
          <p class="text-xs text-muted mt-0.5">Répartition des ventes</p>
        </div>
        <div class="relative" style="height:200px;">
          <canvas id="pieChart"></canvas>
        </div>
        <div id="pie-legend" class="mt-4 space-y-2"></div>
      </div>
    </div>

    <!-- Bar chart — Revenus mensuels -->
    <div class="glass-card p-6 fade-up delay-3">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h2 class="font-display font-700 text-white text-base">Revenus mensuels</h2>
          <p class="text-xs text-muted mt-0.5">Cumul par mois — Année en cours</p>
        </div>
      </div>
      <div style="height:200px;">
        <canvas id="barChart"></canvas>
      </div>
    </div>

    <!-- TOP BOOKS + ACTIVITY -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 fade-up delay-4">

      <!-- Top Books -->
      <div class="glass-card p-6 lg:col-span-3">
        <div class="flex items-center justify-between mb-5">
          <div>
            <h2 class="font-display font-700 text-white text-base">🏆 Top Livres</h2>
            <p class="text-xs text-muted mt-0.5">Classés par nombre de ventes</p>
          </div>
        </div>
        <table class="data-table" id="top-books-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Titre</th>
              <th>Auteur</th>
              <th>Prix</th>
              <th class="text-right">Ventes</th>
              <th class="text-right">CA</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($topBooks as $i => $b): ?>
            <tr class="fade-up" style="animation-delay:<?= .05*$i ?>s">
              <td>
                <?php
                $colors = ['linear-gradient(135deg,#f59e0b,#fbbf24)','linear-gradient(135deg,#94a3b8,#cbd5e1)','linear-gradient(135deg,#c2622b,#e08a58)'];
                $bg = $colors[$i] ?? 'rgba(255,255,255,.06)';
                ?>
                <span class="rank-badge text-xs font-800"
                      style="background:<?= $bg ?>;color:<?= $i<3?'#fff':'#8891aa' ?>;">
                  <?= $i<3 ? ['🥇','🥈','🥉'][$i] : $i+1 ?>
                </span>
              </td>
              <td class="text-white font-medium max-w-0" style="max-width:160px;">
                <div class="truncate" title="<?= htmlspecialchars($b['titre']) ?>">
                  <?= htmlspecialchars(mb_strimwidth($b['titre'],0,35,'…')) ?>
                </div>
              </td>
              <td class="text-muted text-xs"><?= htmlspecialchars($b['auteur']) ?></td>
              <td class="text-xs text-emerald-400"><?= number_format($b['prix'],0,',',' ') ?></td>
              <td class="text-right">
                <span class="inline-flex items-center gap-1 text-xs font-600 px-2 py-0.5 rounded-full"
                      style="background:rgba(99,102,241,.12);color:#818cf8;">
                  <?= number_format($b['nb_ventes']) ?>
                </span>
              </td>
              <td class="text-right text-xs text-white font-600">
                <?= number_format($b['total_revenu'],0,',',' ') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Activity Timeline -->
      <div class="glass-card p-6 lg:col-span-2">
        <div class="mb-5">
          <h2 class="font-display font-700 text-white text-base">Activité récente</h2>
          <p class="text-xs text-muted mt-0.5">Dernières transactions & inscriptions</p>
        </div>
        <div class="space-y-4 overflow-y-auto pr-1" style="max-height:400px;" id="activity-feed">
          <?php foreach(array_slice($activity,0,12) as $evt): ?>
            <?php
            $isAchat = $evt['type'] === 'achat';
            $dotBg   = $isAchat ? 'rgba(16,185,129,.25)' : 'rgba(99,102,241,.25)';
            $dotColor= $isAchat ? '#10b981' : '#6366f1';
            $icon    = $isAchat ? '💳' : '🆕';
            $time    = date('H:i · d/m', strtotime($evt['created_at']));
            ?>
            <div class="timeline-item">
              <div class="timeline-dot" style="background:<?= $dotBg ?>;color:<?= $dotColor ?>;">
                <?= $icon ?>
              </div>
              <div>
                <div class="text-sm text-white font-medium"><?= htmlspecialchars($evt['acteur']) ?></div>
                <div class="text-xs text-muted mt-0.5 truncate" style="max-width:180px;">
                  <?= htmlspecialchars($evt['detail']) ?>
                  <?php if($evt['montant']): ?>
                    · <span class="text-emerald-400 font-600"><?= number_format($evt['montant'],0,',',' ') ?> FCFA</span>
                  <?php endif; ?>
                </div>
                <div class="text-xs mt-1" style="color:#4a5168;"><?= $time ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </main>
</div><!-- /pl-64 -->

<!-- ═══════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT                                               -->
<!-- ═══════════════════════════════════════════════════════ -->
<script>
// ── GLOBAL STATE ─────────────────────────────────────────────
let currentPeriod = '<?= $period ?>';
let lineChart, barChart, pieChart;
let refreshTimer;

// ── CHART.JS THEME ───────────────────────────────────────────
Chart.defaults.color = '#8891aa';
Chart.defaults.font.family = 'DM Sans';
Chart.defaults.plugins.legend.display = false;

const PALETTE = ['#6366f1','#10b981','#38bdf8','#f59e0b','#f43f5e','#a78bfa','#34d399'];

// ── MONTHS LABELS ────────────────────────────────────────────
const MONTHS = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];

// ── INIT CHARTS ──────────────────────────────────────────────
function initCharts(data) {
  // LINE CHART
  const lineCtx = document.getElementById('lineChart').getContext('2d');
  const labels  = data.daily.map(d => d.jour);
  const revenues = data.daily.map(d => parseFloat(d.total));
  const sales    = data.daily.map(d => parseInt(d.nb_ventes));

  const gradBlue = lineCtx.createLinearGradient(0,0,0,220);
  gradBlue.addColorStop(0,'rgba(99,102,241,.35)');
  gradBlue.addColorStop(1,'rgba(99,102,241,.0)');

  const gradGreen = lineCtx.createLinearGradient(0,0,0,220);
  gradGreen.addColorStop(0,'rgba(16,185,129,.25)');
  gradGreen.addColorStop(1,'rgba(16,185,129,.0)');

  lineChart = new Chart(lineCtx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Revenus (FCFA)',
          data: revenues,
          borderColor: '#6366f1', backgroundColor: gradBlue,
          borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 6,
          tension: .4, fill: true, yAxisID: 'y',
        },
        {
          label: 'Ventes',
          data: sales,
          borderColor: '#10b981', backgroundColor: gradGreen,
          borderWidth: 2, pointRadius: 2, pointHoverRadius: 5,
          tension: .4, fill: true, yAxisID: 'y1',
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode:'index', intersect:false },
      animation: { duration:700, easing:'easeOutQuart' },
      plugins: {
        tooltip: {
          backgroundColor:'rgba(13,15,20,.92)',
          borderColor:'rgba(255,255,255,.08)', borderWidth:1,
          padding:12, cornerRadius:10,
          callbacks: {
            label: ctx => {
              const v = ctx.dataset.yAxisID === 'y'
                ? new Intl.NumberFormat('fr-FR').format(ctx.parsed.y) + ' FCFA'
                : ctx.parsed.y + ' ventes';
              return ' ' + ctx.dataset.label + ': ' + v;
            }
          }
        }
      },
      scales: {
        x: { grid:{ color:'rgba(255,255,255,.04)' }, ticks:{ maxRotation:0, maxTicksLimit:8 } },
        y: {
          position:'left', grid:{ color:'rgba(255,255,255,.04)' },
          ticks:{ callback: v => new Intl.NumberFormat('fr-FR',{notation:'compact'}).format(v) }
        },
        y1: {
          position:'right', grid:{ display:false },
          ticks:{ callback: v => v + ' v' }
        }
      }
    }
  });

  // BAR CHART
  const barCtx = document.getElementById('barChart').getContext('2d');
  const monthlyMap = {};
  data.monthly.forEach(m => { monthlyMap[parseInt(m.mois)] = parseFloat(m.total); });
  const barData = MONTHS.map((_,i) => monthlyMap[i+1] || 0);

  barChart = new Chart(barCtx, {
    type: 'bar',
    data: {
      labels: MONTHS,
      datasets:[{
        label:'Revenus',
        data: barData,
        backgroundColor: barData.map((_,i) =>
          i === new Date().getMonth()
            ? '#6366f1'
            : 'rgba(99,102,241,.25)'
        ),
        borderRadius: 8, borderSkipped: false,
        hoverBackgroundColor: '#818cf8',
      }]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      animation:{ duration:700, easing:'easeOutQuart' },
      plugins:{
        tooltip:{
          backgroundColor:'rgba(13,15,20,.92)',
          borderColor:'rgba(255,255,255,.08)', borderWidth:1,
          padding:12, cornerRadius:10,
          callbacks:{
            label: ctx => ' ' + new Intl.NumberFormat('fr-FR').format(ctx.parsed.y) + ' FCFA'
          }
        }
      },
      scales:{
        x:{ grid:{ display:false } },
        y:{ grid:{ color:'rgba(255,255,255,.04)' },
            ticks:{ callback: v => new Intl.NumberFormat('fr-FR',{notation:'compact'}).format(v) } }
      }
    }
  });

  // PIE CHART
  buildPieChart(data.categories);
}

function buildPieChart(cats) {
  const pieCtx = document.getElementById('pieChart').getContext('2d');
  const labels  = cats.map(c => c.categorie);
  const values  = cats.map(c => parseInt(c.ventes));
  const colors  = cats.map((_,i) => PALETTE[i % PALETTE.length]);

  if (pieChart) pieChart.destroy();
  pieChart = new Chart(pieCtx, {
    type: 'doughnut',
    data: { labels, datasets:[{ data:values, backgroundColor:colors, borderWidth:0, hoverOffset:8 }] },
    options: {
      responsive:true, maintainAspectRatio:false, cutout:'72%',
      animation:{ duration:700, easing:'easeOutQuart' },
      plugins:{
        tooltip:{
          backgroundColor:'rgba(13,15,20,.92)',
          borderColor:'rgba(255,255,255,.08)', borderWidth:1,
          padding:10, cornerRadius:10,
        }
      }
    }
  });

  // Custom legend
  const legend = document.getElementById('pie-legend');
  legend.innerHTML = cats.slice(0,5).map((c,i) => `
    <div class="flex items-center justify-between text-xs">
      <span class="flex items-center gap-2 text-muted">
        <span class="w-2.5 h-2.5 rounded-sm flex-shrink-0" style="background:${colors[i]};"></span>
        ${escHtml(c.categorie)}
      </span>
      <span class="font-600 text-white">${c.ventes}</span>
    </div>`
  ).join('');
}

// ── UPDATE CHARTS ─────────────────────────────────────────────
function updateCharts(data) {
  // Line
  lineChart.data.labels = data.daily.map(d => d.jour);
  lineChart.data.datasets[0].data = data.daily.map(d => parseFloat(d.total));
  lineChart.data.datasets[1].data = data.daily.map(d => parseInt(d.nb_ventes));
  lineChart.update('active');

  // Bar
  const monthlyMap = {};
  data.monthly.forEach(m => { monthlyMap[parseInt(m.mois)] = parseFloat(m.total); });
  barChart.data.datasets[0].data = MONTHS.map((_,i) => monthlyMap[i+1]||0);
  barChart.data.datasets[0].backgroundColor = MONTHS.map((_,i) =>
    i === new Date().getMonth() ? '#6366f1' : 'rgba(99,102,241,.25)'
  );
  barChart.update('active');

  // Pie
  buildPieChart(data.categories);
}

// ── UPDATE KPIs ───────────────────────────────────────────────
function updateKPI(kpi) {
  animateCount('kpi-revenue', kpi.revenue, v => fmtFCFA(v));
  animateCount('kpi-sales',   kpi.sales);
  animateCount('kpi-new-users', kpi.newUsers);
  animateCount('kpi-books',   kpi.totalBooks);

  const deltaEl = document.getElementById('kpi-delta');
  if (deltaEl) {
    const pos = kpi.revDelta >= 0;
    deltaEl.className = 'kpi-delta ' + (pos ? 'delta-pos' : 'delta-neg');
    deltaEl.textContent = (pos ? '+' : '') + kpi.revDelta + '%';
  }
}

function fmtFCFA(v) {
  return new Intl.NumberFormat('fr-FR').format(Math.round(v)) + ' FCFA';
}

function animateCount(id, target, fmt) {
  const el = document.getElementById(id);
  if (!el) return;
  const start = 0;
  const duration = 600;
  const startTime = performance.now();
  const render = (now) => {
    const pct = Math.min((now - startTime) / duration, 1);
    const eased = 1 - Math.pow(1 - pct, 3);
    const val = Math.round(start + (target - start) * eased);
    el.textContent = fmt ? fmt(val) : new Intl.NumberFormat('fr-FR').format(val);
    if (pct < 1) requestAnimationFrame(render);
  };
  requestAnimationFrame(render);
}

// ── ACTIVITY FEED ──────────────────────────────────────────────
function renderActivity(events) {
  const feed = document.getElementById('activity-feed');
  const ICONS = { achat:'💳', inscription:'🆕' };
  feed.innerHTML = events.slice(0,12).map(e => {
    const isAchat = e.type === 'achat';
    const bg   = isAchat ? 'rgba(16,185,129,.25)' : 'rgba(99,102,241,.25)';
    const time = fmtTime(e.created_at);
    return `
      <div class="timeline-item">
        <div class="timeline-dot" style="background:${bg};">${ICONS[e.type]||'•'}</div>
        <div>
          <div class="text-sm text-white font-medium">${escHtml(e.acteur)}</div>
          <div class="text-xs text-muted mt-0.5 truncate" style="max-width:180px;">
            ${escHtml(e.detail)}
            ${e.montant ? '· <span style="color:#34d399;font-weight:600;">' + fmtNum(e.montant) + ' FCFA</span>' : ''}
          </div>
          <div class="text-xs mt-1" style="color:#4a5168;">${time}</div>
        </div>
      </div>`;
  }).join('');
}

function fmtTime(ts) {
  const d = new Date(ts.replace(' ','T'));
  return d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}) +
         ' · ' + d.toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit'});
}
function fmtNum(v) { return new Intl.NumberFormat('fr-FR').format(Math.round(v)); }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── FETCH DATA ────────────────────────────────────────────────
async function fetchData(period, init = false) {
  const spinner = document.getElementById('spinner');
  const lu = document.getElementById('last-update');
  spinner.classList.remove('hidden');

  try {
    const res  = await fetch(`?ajax=1&period=${encodeURIComponent(period)}&_=${Date.now()}`);
    const data = await res.json();
    if (!data.ok) throw new Error('API error');

    if (init) {
      initCharts(data);
    } else {
      updateCharts(data);
    }
    updateKPI(data.kpi);
    renderActivity(data.activity);

    lu.textContent = 'Mis à jour ' + data.ts;
  } catch(e) {
    lu.textContent = '⚠ Erreur réseau';
    console.error(e);
  } finally {
    spinner.classList.add('hidden');
  }
}

// ── PERIOD SWITCHING ──────────────────────────────────────────
function setPeriod(p) {
  currentPeriod = p;
  document.querySelectorAll('.period-tab').forEach(t => {
    t.classList.toggle('active', t.textContent.trim().toLowerCase().startsWith(p.slice(0,1)));
  });
  // Fix: match by data
  const MAP = { day:'Jour', month:'Mois', year:'Année' };
  document.querySelectorAll('.period-tab').forEach(t => {
    t.classList.toggle('active', t.textContent.trim() === MAP[p]);
  });

  clearInterval(refreshTimer);
  fetchData(p, false).then(() => {
    refreshTimer = setInterval(() => fetchData(currentPeriod, false), 5000);
  });
}

// ── EXPORT ────────────────────────────────────────────────────
function exportData(type) {
  window.location.href = `?export=${encodeURIComponent(type)}&period=${currentPeriod}`;
}

// ── HEADER DATE ───────────────────────────────────────────────
function updateHeaderDate() {
  const el = document.getElementById('header-date');
  if (!el) return;
  const now = new Date();
  el.textContent = now.toLocaleDateString('fr-FR',{
    weekday:'long', year:'numeric', month:'long', day:'numeric'
  });
}

// ── BOOT ──────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  updateHeaderDate();
  setInterval(updateHeaderDate, 60000);

  // Charts need DOM — fetch & init
  fetchData(currentPeriod, true).then(() => {
    refreshTimer = setInterval(() => fetchData(currentPeriod, false), 5000);
  });
});
</script>
</body>
</html>