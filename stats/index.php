<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║   DIGITAL LIBRARY SYSTEM — Statistics & Analytics Dashboard v4.0   ║
 * ║   100% connecté à la BD (PDO) — Données temps réel                 ║
 * ║   Accès admin uniquement                                           ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */


/**
 * ════════════════════════════════════════════════════════════════
 * BLOC À PLACER EN HAUT DE stats/index.php
 * (avant tout output HTML, avant tout echo)
 * ════════════════════════════════════════════════════════════════
 *
 * Ce bloc gère l'action export_csv et inclut les fichiers exports
 * avec __DIR__ pour éviter tout chemin cassé sous XAMPP Windows.
 */

// ── 1. Constante de sécurité ─────────────────────────────────
//    Empêche l'appel direct aux fichiers exports
define('BIBLIO_APP', true);

// ── 2. Dépendances principales ───────────────────────────────
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
// ── 3. Gestion de l'action export CSV ───────────────────────
//    Doit être AVANT tout output (avant le DOCTYPE HTML)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {

    // Vérification admin (double sécurité)
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        exit('Accès refusé.');
    }

    // Inclusion sécurisée avec __DIR__
    $csvExportFile = __DIR__ . '/exports/csv_export.php';

    if (!file_exists($csvExportFile)) {
        http_response_code(500);
        exit('Erreur : fichier csv_export.php introuvable. Vérifiez que le fichier existe dans stats/exports/');
    }

    require_once $csvExportFile;

    // Lancement de l'export (la fonction appelle exit() en fin)
    exportCSV();
}

// ── 4. Gestion de l'action rapport HTML ─────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'report') {

    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        exit('Accès refusé.');
    }

    $reportFile = __DIR__ . '/exports/report_export.php';

    if (!file_exists($reportFile)) {
        http_response_code(500);
        exit('Erreur : fichier report_export.php introuvable.');
    }

    require_once $reportFile;
    exit(); // Stop après le rapport HTML
}

// ── 5. Suite normale de index.php ────────────────────────────
//    Le reste de ton index.php continue ici normalement...


/*
 * ════════════════════════════════════════════════════════════════
 * BOUTONS À PLACER DANS TON HTML (dans index.php)
 * ════════════════════════════════════════════════════════════════
 *
 * Bouton export CSV :
 *
 *   <a href="?action=export_csv"
 *      class="btn btn-success">
 *       📥 Exporter CSV
 *   </a>
 *
 * Bouton rapport HTML imprimable :
 *
 *   <a href="?action=report"
 *      target="_blank"
 *      class="btn btn-primary">
 *       🖨️ Rapport imprimable
 *   </a>
 *
 * ════════════════════════════════════════════════════════════════
 */



if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/* ──────────────────────────────────────────────
   DÉPENDANCES
────────────────────────────────────────────── */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/data.php';


if (!isset($pdo) || !$pdo instanceof PDO) {
    die('❌ Erreur : connexion PDO introuvable.');
}
/* ──────────────────────────────────────────────
   VÉRIFICATION PDO
────────────────────────────────────────────── */

if (!isset($pdo) || !$pdo instanceof PDO) {
    die('❌ Erreur : connexion base de données indisponible.');
}

/* ──────────────────────────────────────────────
   AUTH GUARD — ADMIN UNIQUEMENT
────────────────────────────────────────────── */

if (!isset($_SESSION['user_id'])) {

    // Mode dev temporaire
    $_SESSION['user_id']   = 1;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_name'] = 'Administrateur';
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {

    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

/* ──────────────────────────────────────────────
   USER DATA
────────────────────────────────────────────── */

$userId = (int) ($_SESSION['user_id'] ?? 0);

$username = htmlspecialchars(
    $_SESSION['user_name'] ?? 'Admin',
    ENT_QUOTES,
    'UTF-8'
);

$avatar = strtoupper(substr($username, 0, 1)) ?: 'A';

/* ──────────────────────────────────────────────
   CSRF TOKEN
────────────────────────────────────────────── */

if (!function_exists('csrfToken')) {

    function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

$csrfToken = csrfToken();

/* ──────────────────────────────────────────────
   HELPERS PDO
────────────────────────────────────────────── */

if (!function_exists('dbFetch')) {

    function dbFetch(string $sql, array $params = []): array
    {
        global $pdo;

        if (!$pdo instanceof PDO) {
            return [];
        }

        try {

            $stmt = $pdo->prepare($sql);

            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {

            error_log('[Stats] dbFetch Error : ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('dbVal')) {

    function dbVal(string $sql, array $params = [], $default = 0)
    {
        global $pdo;

        if (!$pdo instanceof PDO) {
            return $default;
        }

        try {

            $stmt = $pdo->prepare($sql);

            $stmt->execute($params);

            $result = $stmt->fetchColumn();

            return $result !== false
                ? $result
                : $default;

        } catch (PDOException $e) {

            error_log('[Stats] dbVal Error : ' . $e->getMessage());

            return $default;
        }
    }
}

if (!function_exists('fmtFCFA')) {

    function fmtFCFA(float $amount): string
    {
        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
}

if (!function_exists('pct')) {

    function pct(float $current, float $previous): float
    {
        return $previous > 0
            ? round((($current - $previous) / $previous) * 100, 1)
            : 0;
    }
}

/* ──────────────────────────────────────────────
   EXPORT ACTIONS
────────────────────────────────────────────── */

$action = $_GET['action'] ?? '';

if ($action === 'export_csv') {

    require_once __DIR__ . '/exports/csv_export.php';

    if (function_exists('exportCSV')) {

        exportCSV();
    }

    exit;
}

if ($action === 'export_report') {

    $type = $_GET['type'] ?? 'monthly';

    require_once __DIR__ . '/../exports/report_export.php';

    if (function_exists('generateReport')) {

        generateReport($type);
    }

    exit;
}

/* ──────────────────────────────────────────────
   GLOBAL STATS
────────────────────────────────────────────── */

$totalUsers = dbVal("
    SELECT COUNT(*)
    FROM users
");

$totalBooks = dbVal("
    SELECT COUNT(*)
    FROM livres
");

$totalSales = dbVal("
    SELECT COUNT(*)
    FROM achats
    WHERE statut='confirme'
");

$totalRevenue = dbVal("
    SELECT COALESCE(SUM(montant),0)
    FROM achats
    WHERE statut='confirme'
");

$todayRevenue = dbVal("
    SELECT COALESCE(SUM(montant),0)
    FROM achats
    WHERE statut='confirme'
    AND DATE(created_at)=CURDATE()
");

$topBooks = dbFetch("
    SELECT
        l.titre,
        l.auteur,
        COUNT(a.id) AS ventes
    FROM livres l
    LEFT JOIN achats a
        ON a.livre_id = l.id
        AND a.statut='confirme'
    GROUP BY l.id
    ORDER BY ventes DESC
    LIMIT 10
");

$recentSales = dbFetch("
    SELECT
        a.reference,
        a.montant,
        a.created_at,
        l.titre,
        CONCAT(u.prenom,' ',u.nom) AS acheteur
    FROM achats a
    JOIN livres l ON l.id = a.livre_id
    JOIN users u ON u.id = a.user_id
    WHERE a.statut='confirme'
    ORDER BY a.created_at DESC
    LIMIT 10
");

// ── Helpers PDO ───────────────────────────────────────────────
function dbFetch(string $sql, array $params = []): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Stats] DB Error: ' . $e->getMessage());
        return [];
    }
}
function dbVal(string $sql, array $params = [], $default = 0) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetchColumn();
        return $r !== false ? $r : $default;
    } catch (PDOException $e) {
        error_log('[Stats] DB Error: ' . $e->getMessage());
        return $default;
    }
}
function fmtFCFA(float $n): string {
    return number_format($n, 0, ',', ' ') . ' FCFA';
}
function pct(float $a, float $b): float {
    return $b > 0 ? round((($a - $b) / $b) * 100, 1) : 0;
}

// ── Export Actions ─────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action === 'export_csv') {
    require_once __DIR__ . '/exports/csv_export.php';
    exportCSV();
    exit;
}
if ($action === 'export_report') {
    $type = $_GET['type'] ?? 'monthly';
    require_once __DIR__ . '/exports/report_export.php';
    generateReport($type);
    exit;
}

// ── Période active ────────────────────────────────────────────
$period = $_GET['period'] ?? '30';
$validPeriods = ['7', '30', '90', '365'];
if (!in_array($period, $validPeriods)) $period = '30';
$periodLabel = ['7'=>'7 jours','30'=>'30 jours','90'=>'90 jours','365'=>'1 an'][$period];

// ══════════════════════════════════════════════════════════════
// FETCH : Statistiques globales
// ══════════════════════════════════════════════════════════════
$now   = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$yearStart  = date('Y-01-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd   = date('Y-m-t', strtotime('-1 month'));
$periodStart    = date('Y-m-d', strtotime("-{$period} days"));

// Utilisateurs
$totalUsers     = (int)dbVal("SELECT COUNT(*) FROM users");
$totalAdmins    = (int)dbVal("SELECT COUNT(*) FROM users WHERE role='admin'");
$totalJourno    = (int)dbVal("SELECT COUNT(*) FROM users WHERE role='journaliste'");
$totalLecteurs  = (int)dbVal("SELECT COUNT(*) FROM users WHERE role='lecteur'");
$newUsersMonth  = (int)dbVal("SELECT COUNT(*) FROM users WHERE created_at >= ?", [$monthStart]);
$newUsersPrev   = (int)dbVal("SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?", [$lastMonthStart, $lastMonthEnd]);
$activeToday    = (int)dbVal("SELECT COUNT(*) FROM users WHERE DATE(last_login) = CURDATE()");
$blockedUsers   = (int)dbVal("SELECT COUNT(*) FROM users WHERE statut='bloque'");

// Livres
$totalBooks     = (int)dbVal("SELECT COUNT(*) FROM livres");
$freeBooks      = (int)dbVal("SELECT COUNT(*) FROM livres WHERE prix=0 OR access_type='free'");
$paidBooks      = $totalBooks - $freeBooks;
$totalCats      = (int)dbVal("SELECT COUNT(*) FROM categories");
$lowStock       = (int)dbVal("SELECT COUNT(*) FROM livres WHERE stock > 0 AND stock <= 5 AND statut='disponible'");
$outOfStock     = (int)dbVal("SELECT COUNT(*) FROM livres WHERE statut='rupture' OR stock=0");
$newBooksWeek   = (int)dbVal("SELECT COUNT(*) FROM livres WHERE created_at >= ?", [$weekStart]);
$totalDownloads = (int)dbVal("SELECT COALESCE(SUM(count),0) FROM user_downloads");
$featuredBooks  = (int)dbVal("SELECT COUNT(*) FROM livres WHERE is_featured=1");

// Ventes & Revenus
$totalSales       = (int)dbVal("SELECT COUNT(*) FROM achats WHERE statut='confirme'");
$totalRevenue     = (float)dbVal("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme'");
$revenueToday     = (float)dbVal("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()");
$revenueWeek      = (float)dbVal("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND created_at >= ?", [$weekStart]);
$revenueMonth     = (float)dbVal("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND created_at >= ?", [$monthStart]);
$revenueYear      = (float)dbVal("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND created_at >= ?", [$yearStart]);
$revenuePrevMonth = (float)dbVal("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND created_at BETWEEN ? AND ?", [$lastMonthStart, $lastMonthEnd]);
$revPct           = pct($revenueMonth, $revenuePrevMonth);
$salesMonth       = (int)dbVal("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND created_at >= ?", [$monthStart]);
$salesPrevMonth   = (int)dbVal("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND created_at BETWEEN ? AND ?", [$lastMonthStart, $lastMonthEnd]);
$salesPct         = pct($salesMonth, $salesPrevMonth);
$pendingSales     = (int)dbVal("SELECT COUNT(*) FROM achats WHERE statut='en_attente'");
$avgOrderValue    = $totalSales > 0 ? round($totalRevenue / $totalSales, 0) : 0;

// Bonus
$totalBonus     = (int)dbVal("SELECT COALESCE(SUM(bonus_total),0) FROM user_bonus");
$bonusAvailable = (int)dbVal("SELECT COALESCE(SUM(bonus_restant),0) FROM user_bonus");

// Lectures
$totalProgessions   = (int)dbVal("SELECT COUNT(*) FROM lecture_progression");
$avgReadingProgress = (float)dbVal("SELECT COALESCE(AVG(pourcentage),0) FROM lecture_progression");
$completedReads     = (int)dbVal("SELECT COUNT(*) FROM lecture_progression WHERE pourcentage >= 100");
$activeReaders      = (int)dbVal("SELECT COUNT(DISTINCT user_id) FROM lecture_progression WHERE updated_at >= ?", [$periodStart]);

// Taux
$conversionRate    = $totalUsers > 0 ? round(($totalSales / $totalUsers) * 100, 1) : 0;
$activeRate        = $totalUsers > 0 ? round(($activeToday / $totalUsers) * 100, 1) : 0;
$completionRate    = $totalProgessions > 0 ? round(($completedReads / $totalProgessions) * 100, 1) : 0;

// ── Graphique : Ventes par jour (période) ────────────────────
$salesChart = dbFetch("
    SELECT DATE(created_at) AS day,
           COUNT(*) AS ventes,
           COALESCE(SUM(montant),0) AS revenue
    FROM achats
    WHERE statut='confirme' AND created_at >= ?
    GROUP BY DATE(created_at)
    ORDER BY day ASC
", [$periodStart]);

// Combler les jours manquants
$salesChartMap = [];
foreach ($salesChart as $row) $salesChartMap[$row['day']] = $row;
$salesChartFull = [];
for ($i = (int)$period - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $salesChartFull[] = [
        'day'     => $d,
        'label'   => date('d/m', strtotime($d)),
        'ventes'  => (int)($salesChartMap[$d]['ventes'] ?? 0),
        'revenue' => (float)($salesChartMap[$d]['revenue'] ?? 0),
    ];
}

// ── Graphique : Revenus mensuels (12 mois) ───────────────────
$revenueChart = dbFetch("
    SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
           DATE_FORMAT(created_at,'%b %Y') AS label,
           COALESCE(SUM(montant),0) AS revenue,
           COUNT(*) AS ventes
    FROM achats WHERE statut='confirme'
    GROUP BY DATE_FORMAT(created_at,'%Y-%m')
    ORDER BY month DESC LIMIT 12
");
$revenueChart = array_reverse($revenueChart);

// ── Graphique : Nouveaux utilisateurs (12 mois) ──────────────
$usersChart = dbFetch("
    SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
           DATE_FORMAT(created_at,'%b') AS label,
           COUNT(*) AS count
    FROM users
    GROUP BY DATE_FORMAT(created_at,'%Y-%m')
    ORDER BY month DESC LIMIT 12
");
$usersChart = array_reverse($usersChart);

// ── Top livres les plus vendus ───────────────────────────────
$topBooks = dbFetch("
    SELECT l.id, l.titre, l.auteur, l.prix,
           c.nom AS categorie,
           COUNT(a.id) AS ventes,
           COALESCE(SUM(a.montant),0) AS revenue,
           l.note_moyenne
    FROM livres l
    LEFT JOIN achats a ON a.livre_id=l.id AND a.statut='confirme'
    LEFT JOIN categories c ON c.id=l.categorie_id
    GROUP BY l.id
    ORDER BY ventes DESC
    LIMIT 10
");

// ── Top livres les plus lus ──────────────────────────────────
$topRead = dbFetch("
    SELECT l.id, l.titre, l.auteur,
           COUNT(lp.id) AS lecteurs,
           AVG(lp.pourcentage) AS avg_prog
    FROM livres l
    LEFT JOIN lecture_progression lp ON lp.livre_id=l.id
    GROUP BY l.id
    ORDER BY lecteurs DESC
    LIMIT 8
");

// ── Top catégories ───────────────────────────────────────────
$topCats = dbFetch("
    SELECT c.nom, c.icone,
           COUNT(DISTINCT l.id) AS nb_livres,
           COUNT(a.id) AS nb_ventes,
           COALESCE(SUM(a.montant),0) AS revenue
    FROM categories c
    LEFT JOIN livres l ON l.categorie_id=c.id
    LEFT JOIN achats a ON a.livre_id=l.id AND a.statut='confirme'
    GROUP BY c.id
    ORDER BY nb_ventes DESC
");

// ── Répartition utilisateurs (pour pie chart) ────────────────
$usersByRole = [
    'admin'       => $totalAdmins,
    'journaliste' => $totalJourno,
    'lecteur'     => $totalLecteurs,
];

// ── Méthodes de paiement ─────────────────────────────────────
$paymentMethods = dbFetch("
    SELECT methode, COUNT(*) AS count,
           COALESCE(SUM(montant),0) AS total
    FROM achats WHERE statut='confirme'
    GROUP BY methode ORDER BY count DESC
");

// ── Heures d'activité ────────────────────────────────────────
$activityHours = dbFetch("
    SELECT HOUR(created_at) AS h, COUNT(*) AS count
    FROM achats WHERE statut='confirme'
    GROUP BY HOUR(created_at)
    ORDER BY h ASC
");
$activityHoursMap = [];
foreach ($activityHours as $r) $activityHoursMap[(int)$r['h']] = (int)$r['count'];
$hoursData = [];
for ($h = 0; $h < 24; $h++) {
    $hoursData[] = ['h' => $h, 'count' => $activityHoursMap[$h] ?? 0];
}

// ── Insights & Alertes ────────────────────────────────────────
$bestBook       = $topBooks[0] ?? null;
$bestCat        = $topCats[0]  ?? null;
$mostActiveHour = !empty($activityHours) ? $activityHours[array_search(max(array_column($activityHours,'count')), array_column($activityHours,'count'))]['h'] : null;
$topUser        = dbFetch("SELECT u.nom, u.prenom, COUNT(a.id) AS achats FROM users u LEFT JOIN achats a ON a.user_id=u.id AND a.statut='confirme' GROUP BY u.id ORDER BY achats DESC LIMIT 1")[0] ?? null;

$alerts = [];
if ($outOfStock > 0)  $alerts[] = ['type'=>'danger','icon'=>'📦','msg'=>"{$outOfStock} livre(s) en rupture de stock",'action'=>'books/index.php?statut=rupture'];
if ($lowStock > 0)    $alerts[] = ['type'=>'warn',  'icon'=>'⚠️','msg'=>"{$lowStock} livre(s) avec stock faible (≤5)",'action'=>'books/index.php?stock=low'];
if ($pendingSales > 0) $alerts[] = ['type'=>'info', 'icon'=>'⏳','msg'=>"{$pendingSales} vente(s) en attente de confirmation",'action'=>'admin/sales.php?statut=en_attente'];
if ($blockedUsers > 0) $alerts[] = ['type'=>'warn', 'icon'=>'🚫','msg'=>"{$blockedUsers} utilisateur(s) bloqué(s)",'action'=>'users/index.php?statut=bloque'];
if ($revenueToday === 0.0) $alerts[] = ['type'=>'warn','icon'=>'📉','msg'=>"Aucune vente enregistrée aujourd'hui",'action'=>'admin/sales.php'];

// ── Dernières ventes ─────────────────────────────────────────
$recentSales = dbFetch("
    SELECT a.id, a.montant, a.methode, a.created_at, a.reference,
           u.nom, u.prenom,
           l.titre AS livre_titre
    FROM achats a
    JOIN users u ON u.id=a.user_id
    JOIN livres l ON l.id=a.livre_id
    WHERE a.statut='confirme'
    ORDER BY a.created_at DESC
    LIMIT 10
");

// ── Derniers utilisateurs ─────────────────────────────────────
$recentUsers = dbFetch("
    SELECT id, nom, prenom, email, role, statut, created_at, last_login
    FROM users ORDER BY created_at DESC LIMIT 8
");

// ── Rapport généré ─────────────────────────────────────────────
$reportGenerated = !empty($_GET['report']) ? htmlspecialchars($_GET['report'], ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics Premium — Digital Library System</title>
<meta name="robots" content="noindex,nofollow">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<style>
/* ════════════════════════════════════════════════════════════
   RESET & VARIABLES
════════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  /* Background layers */
  --bg-base:      #030609;
  --bg-surface:   #080d16;
  --bg-card:      rgba(255,255,255,0.028);
  --bg-card-hov:  rgba(255,255,255,0.052);
  --border:       rgba(255,255,255,0.065);
  --border-glow:  rgba(0,212,255,0.32);

  /* Accent palette */
  --cyan:    #00d4ff;
  --violet:  #7c3aed;
  --neon:    #00ffaa;
  --amber:   #f59e0b;
  --rose:    #f43f5e;
  --orange:  #f97316;
  --indigo:  #6366f1;

  /* Text */
  --t1: #eef2ff;
  --t2: rgba(238,242,255,0.58);
  --t3: rgba(238,242,255,0.28);
  --t4: rgba(238,242,255,0.12);

  /* Shadows & glows */
  --glow-c:  0 0 32px rgba(0,212,255,0.14);
  --glow-v:  0 0 32px rgba(124,58,237,0.14);
  --shadow:  0 4px 28px rgba(0,0,0,0.38);
  --shadow-lg: 0 20px 60px rgba(0,0,0,0.55);

  /* Radius */
  --r:   10px;
  --r2:  16px;
  --r3:  22px;

  /* Layout */
  --sidebar-w: 240px;
  --topbar-h:  58px;
}

html { scroll-behavior: smooth; }

body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg-base);
  color: var(--t1);
  min-height: 100vh;
  overflow-x: hidden;
}

/* Grain overlay */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.028'/%3E%3C/svg%3E");
  opacity: 0.4;
}

::-webkit-scrollbar { width: 3px; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }

/* ════════════════════════════════════════════════════════════
   LAYOUT
════════════════════════════════════════════════════════════ */
.wrapper { display: flex; min-height: 100vh; position: relative; z-index: 1; }

/* ── SIDEBAR ── */
#sidebar {
  width: var(--sidebar-w);
  background: var(--bg-surface);
  border-right: 1px solid var(--border);
  position: fixed; top: 0; left: 0; bottom: 0;
  z-index: 200;
  display: flex; flex-direction: column;
  transition: transform .3s ease;
  overflow: hidden;
}
.sb-brand {
  height: var(--topbar-h);
  display: flex; align-items: center; gap: 10px;
  padding: 0 16px; border-bottom: 1px solid var(--border);
}
.sb-logo {
  width: 34px; height: 34px; border-radius: 10px;
  background: linear-gradient(135deg, var(--cyan), var(--violet));
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; box-shadow: var(--glow-c);
}
.sb-title { font-family:'Syne',sans-serif; font-weight:800; font-size:.88rem; letter-spacing:-.3px; }
.sb-title em { color: var(--cyan); font-style: normal; }

.sb-nav { flex: 1; overflow-y: auto; padding: 8px 0; }
.sb-section { font-family:'Space Mono',monospace; font-size:.56rem; letter-spacing:.12em; text-transform:uppercase; color: var(--t3); padding: 10px 16px 3px; }
.sb-item {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 16px; margin: 1px 8px;
  border-radius: var(--r); text-decoration: none;
  color: var(--t2); font-size: .81rem; font-weight: 500;
  transition: all .16s; position: relative;
}
.sb-item:hover { color: var(--t1); background: var(--bg-card-hov); }
.sb-item.active {
  color: var(--cyan);
  background: rgba(0,212,255,0.07);
  border: 1px solid rgba(0,212,255,0.12);
}
.sb-item.active::before {
  content: ''; position: absolute; left: 0; top: 50%;
  transform: translateY(-50%); width: 3px; height: 16px;
  background: var(--cyan); border-radius: 0 3px 3px 0;
  box-shadow: 0 0 8px var(--cyan);
}
.sb-icon { font-size: .95rem; width: 18px; text-align: center; }

.sb-footer { padding: 8px; border-top: 1px solid var(--border); }

/* ── MAIN ── */
.main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; }

/* ── TOPBAR ── */
#topbar {
  height: var(--topbar-h);
  background: rgba(3,6,9,.88); backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 1rem; padding: 0 1.5rem;
  position: sticky; top: 0; z-index: 100;
}
.tb-bc { font-size: .75rem; color: var(--t2); display: flex; align-items: center; gap: 6px; }
.tb-bc strong { font-family:'Syne',sans-serif; font-weight:700; color:var(--t1); }
.tb-spacer { flex: 1; }

/* Period selector */
.period-tabs {
  display: flex; gap: 3px;
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r); padding: 3px;
}
.period-tab {
  font-family: 'Space Mono', monospace; font-size: .62rem; padding: 4px 9px;
  border-radius: 7px; color: var(--t3); cursor: pointer; text-decoration: none;
  transition: all .16s; white-space: nowrap;
}
.period-tab:hover { color: var(--t1); }
.period-tab.active { background: linear-gradient(135deg, var(--cyan), var(--violet)); color: #fff; }

/* Live badge */
.live-badge {
  display: flex; align-items: center; gap: 5px;
  font-family: 'Space Mono', monospace; font-size: .6rem;
  color: var(--neon); padding: 4px 9px;
  border: 1px solid rgba(0,255,170,0.22); border-radius: 100px;
  background: rgba(0,255,170,0.06);
}
.live-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--neon); flex-shrink: 0;
  box-shadow: 0 0 6px var(--neon);
  animation: livePulse 1.5s ease-in-out infinite;
}
@keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.7)} }

.tb-avatar {
  width: 30px; height: 30px; border-radius: 9px;
  background: linear-gradient(135deg, var(--cyan), var(--violet));
  display: flex; align-items: center; justify-content: center;
  font-family:'Syne',sans-serif; font-weight:800; font-size:.72rem; color:#fff;
}

/* ── PAGE ── */
.page {
  flex: 1; padding: 1.5rem 1.8rem 5rem;
  max-width: 1560px; width: 100%;
}

/* ════════════════════════════════════════════════════════════
   COMPONENTS
════════════════════════════════════════════════════════════ */

/* Header */
.page-header {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
  margin-bottom: 1.5rem; animation: fadeUp .4s ease both;
}
.ph-title { font-family:'Syne',sans-serif; font-size: 1.6rem; font-weight: 800; letter-spacing: -.4px; }
.ph-sub { font-size: .78rem; color: var(--t2); margin-top: 3px; }
.ph-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

/* Alerts */
.alerts-row { display: flex; flex-direction: column; gap: 6px; margin-bottom: 1.2rem; animation: fadeUp .4s ease both; }
.alert {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 14px; border-radius: var(--r);
  font-size: .77rem; line-height: 1.4;
}
.alert-danger { background: rgba(244,63,94,.07); border: 1px solid rgba(244,63,94,.18); color: #fb7185; }
.alert-warn   { background: rgba(245,158,11,.07); border: 1px solid rgba(245,158,11,.18); color: #fbbf24; }
.alert-info   { background: rgba(0,212,255,.06);  border: 1px solid rgba(0,212,255,.18);  color: var(--cyan); }
.alert a { color: inherit; opacity: .7; margin-left: auto; font-size: .7rem; text-decoration: none; }
.alert a:hover { opacity: 1; }

/* KPI Grid */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: .85rem; margin-bottom: 1.4rem;
}
.kpi {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r2);
  padding: 1.2rem 1.3rem;
  position: relative; overflow: hidden;
  transition: transform .22s ease, border-color .22s, box-shadow .22s;
  animation: fadeUp .5s ease both;
  cursor: default;
}
.kpi::after {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(circle at 100% 0, rgba(255,255,255,.03), transparent 60%);
  pointer-events: none;
}
.kpi:hover { transform: translateY(-3px); border-color: rgba(255,255,255,.1); box-shadow: var(--shadow); }
.kpi-icon {
  width: 38px; height: 38px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem; margin-bottom: .8rem;
}
.kpi-val {
  font-family: 'Syne', sans-serif; font-size: 1.65rem; font-weight: 800;
  letter-spacing: -.4px; line-height: 1;
}
.kpi-label { font-size: .71rem; color: var(--t2); margin-top: 4px; font-weight: 500; }
.kpi-delta { margin-top: 7px; font-size: .64rem; font-family: 'Space Mono', monospace; display: flex; align-items: center; gap: 3px; }
.up   { color: var(--neon); }
.down { color: var(--rose); }
.neu  { color: var(--t3); }
.kpi-glow {
  position: absolute; bottom: -20px; right: -20px;
  width: 70px; height: 70px; border-radius: 50%;
  filter: blur(24px); opacity: .18; pointer-events: none;
}

/* Section */
.section { margin-bottom: 1.4rem; }
.section-title {
  font-family: 'Syne', sans-serif; font-weight: 700; font-size: .88rem;
  color: var(--t1); display: flex; align-items: center; gap: 8px;
  margin-bottom: .85rem;
}
.section-title::after {
  content: ''; flex: 1; height: 1px;
  background: linear-gradient(90deg, var(--border), transparent);
}

/* Card */
.card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r2);
  overflow: hidden;
  animation: fadeUp .5s ease both;
}
.card-head {
  padding: 1rem 1.3rem; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between; gap: .7rem;
}
.card-title {
  font-family: 'Syne', sans-serif; font-weight: 700; font-size: .86rem;
  display: flex; align-items: center; gap: 7px;
}
.cti {
  width: 28px; height: 28px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center; font-size: .82rem;
}
.card-body { padding: 1.1rem 1.3rem; }
.card-foot {
  padding: .8rem 1.3rem; border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  font-size: .72rem; color: var(--t3);
}

/* Grid layouts */
.g2  { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; margin-bottom: 1.2rem; }
.g3  { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.1rem; margin-bottom: 1.2rem; }
.g13 { display: grid; grid-template-columns: 1fr 3fr; gap: 1.1rem; margin-bottom: 1.2rem; }
.g31 { display: grid; grid-template-columns: 3fr 1fr; gap: 1.1rem; margin-bottom: 1.2rem; }
@media(max-width:1200px) { .g2,.g3,.g13,.g31 { grid-template-columns: 1fr; } }

/* Chart containers */
.chart-wrap { position: relative; }
.chart-wrap canvas { width: 100% !important; }

/* Tables */
.dt { width: 100%; border-collapse: collapse; font-size: .78rem; }
.dt th {
  font-family: 'Space Mono', monospace; font-size: .59rem; letter-spacing: .08em;
  text-transform: uppercase; color: var(--t3);
  padding: 6px 10px; border-bottom: 1px solid var(--border); text-align: left;
}
.dt td {
  padding: 9px 10px; border-bottom: 1px solid rgba(255,255,255,.03);
  color: var(--t2); vertical-align: middle;
  transition: background .12s;
}
.dt tr:last-child td { border-bottom: none; }
.dt tbody tr:hover td { background: var(--bg-card-hov); }
.td-bold { font-weight: 600; color: var(--t1); }
.rank {
  width: 24px; height: 24px; border-radius: 7px;
  display: inline-flex; align-items: center; justify-content: center;
  font-family: 'Space Mono', monospace; font-size: .62rem;
  background: var(--bg-card-hov); color: var(--t3);
}
.rank-1 { background: rgba(245,158,11,.15); color: var(--amber); }
.rank-2 { background: rgba(148,163,184,.12); color: #94a3b8; }
.rank-3 { background: rgba(180,83,9,.15); color: #c2773a; }

/* Progress bar */
.pb { background: rgba(255,255,255,.06); border-radius: 100px; height: 4px; overflow: hidden; }
.pb-fill { height: 100%; border-radius: 100px; transition: width 1.2s ease; }
.pb-cyan   { background: linear-gradient(90deg, var(--cyan), var(--violet)); }
.pb-green  { background: linear-gradient(90deg, var(--neon), #00a882); }
.pb-amber  { background: linear-gradient(90deg, var(--amber), var(--orange)); }

/* Chips */
.chip {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: .59rem; font-family:'Space Mono',monospace;
  padding: 2px 7px; border-radius: 100px; font-weight: 700;
  letter-spacing: .03em; text-transform: uppercase;
}
.chip-success { background:rgba(0,255,170,.09); color:var(--neon); border:1px solid rgba(0,255,170,.18); }
.chip-warn    { background:rgba(245,158,11,.09); color:var(--amber); border:1px solid rgba(245,158,11,.18); }
.chip-danger  { background:rgba(244,63,94,.09); color:var(--rose); border:1px solid rgba(244,63,94,.18); }
.chip-info    { background:rgba(0,212,255,.09); color:var(--cyan); border:1px solid rgba(0,212,255,.18); }
.chip-violet  { background:rgba(124,58,237,.09); color:#a78bfa; border:1px solid rgba(124,58,237,.18); }
.chip-muted   { background:rgba(255,255,255,.05); color:var(--t3); border:1px solid var(--border); }

/* Buttons */
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 13px; border-radius: var(--r);
  font-family:'Syne',sans-serif; font-size:.75rem; font-weight:700;
  cursor: pointer; transition: all .16s; text-decoration: none;
  border: none; white-space: nowrap;
}
.btn-sm { padding: 4px 9px; font-size: .68rem; }
.btn-xs { padding: 3px 7px; font-size: .62rem; }
.btn-primary { background: linear-gradient(135deg, var(--cyan), var(--violet)); color:#fff; box-shadow: 0 4px 14px rgba(0,212,255,.18); }
.btn-primary:hover { opacity:.86; transform:translateY(-1px); }
.btn-ghost { background: var(--bg-card); border:1px solid var(--border); color:var(--t2); }
.btn-ghost:hover { color:var(--t1); border-color:rgba(255,255,255,.12); }
.btn-success { background:rgba(0,255,170,.08); border:1px solid rgba(0,255,170,.2); color:var(--neon); }
.btn-success:hover { background:rgba(0,255,170,.15); }
.btn-warn { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.2); color:var(--amber); }
.btn-warn:hover { background:rgba(245,158,11,.15); }

/* Insight cards */
.insights-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: .85rem; margin-bottom: 1.4rem;
}
.insight {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--r2); padding: 1.1rem 1.2rem;
  position: relative; overflow: hidden;
  animation: fadeUp .55s ease both;
  transition: border-color .2s;
}
.insight:hover { border-color: rgba(0,212,255,.2); }
.insight-label {
  font-family: 'Space Mono', monospace; font-size: .58rem;
  text-transform: uppercase; letter-spacing: .1em; color: var(--t3);
  margin-bottom: .5rem;
}
.insight-val {
  font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.1rem;
  color: var(--t1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.insight-sub { font-size: .68rem; color: var(--t2); margin-top: 3px; }
.insight-icon {
  position: absolute; right: 1rem; top: 1rem;
  font-size: 1.5rem; opacity: .25;
}

/* Heatmap hours */
.hours-grid {
  display: grid; grid-template-columns: repeat(24, 1fr);
  gap: 3px; align-items: end; height: 56px;
}
.hour-bar {
  border-radius: 3px 3px 0 0;
  background: linear-gradient(to top, var(--cyan), rgba(0,212,255,.3));
  min-height: 3px; transition: height .8s ease;
  cursor: pointer; position: relative;
}
.hour-bar:hover { filter: brightness(1.3); }
.hour-bar::after {
  content: attr(data-tip);
  position: absolute; bottom: calc(100% + 4px); left: 50%;
  transform: translateX(-50%);
  font-family:'Space Mono',monospace; font-size:.5rem; white-space:nowrap;
  background:var(--bg-surface); border:1px solid var(--border);
  padding:2px 5px; border-radius:4px;
  opacity:0; transition:opacity .16s; pointer-events:none;
}
.hour-bar:hover::after { opacity:1; }
.hours-labels {
  display: grid; grid-template-columns: repeat(24, 1fr);
  gap: 3px; margin-top: 4px;
}
.hl { font-family:'Space Mono',monospace; font-size:.48rem; color:var(--t4); text-align:center; }

/* Revenue numbers */
.rev-breakdown {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: .7rem;
}
.rev-item {
  background: var(--bg-card-hov); border-radius: var(--r);
  padding: .85rem 1rem;
}
.rev-item-label { font-size: .66rem; color: var(--t3); font-family:'Space Mono',monospace; text-transform: uppercase; letter-spacing: .05em; }
.rev-item-val { font-family:'Syne',sans-serif; font-weight:800; font-size:1rem; margin-top:3px; }

/* Method bars */
.method-bar { margin-bottom: .7rem; }
.method-info { display: flex; justify-content: space-between; margin-bottom: 3px; font-size: .72rem; }
.method-name { color: var(--t2); }
.method-val { color: var(--t1); font-family:'Space Mono',monospace; }

/* Toast */
#toast-stack { position:fixed; bottom:1.2rem; right:1.2rem; z-index:9000; display:flex; flex-direction:column-reverse; gap:6px; pointer-events:none; }
.toast {
  display:flex; align-items:center; gap:8px; padding:10px 13px;
  background:var(--bg-surface); border:1px solid var(--border);
  border-radius:var(--r2); box-shadow:var(--shadow-lg);
  font-size:.77rem; max-width:280px; pointer-events:all;
  transform:translateX(110px); opacity:0;
  transition:all .32s cubic-bezier(.34,1.56,.64,1);
}
.toast.show { transform:translateX(0); opacity:1; }
.t-title { font-family:'Syne',sans-serif; font-weight:700; }
.t-sub { color:var(--t3); font-size:.67rem; }

/* Report modal */
#report-modal {
  position:fixed; inset:0; z-index:1000;
  display:flex; align-items:center; justify-content:center; padding:1rem;
  background:rgba(3,6,9,.88); backdrop-filter:blur(14px);
  opacity:0; pointer-events:none; transition:opacity .28s;
}
#report-modal.open { opacity:1; pointer-events:all; }
.report-box {
  background:var(--bg-surface); border:1px solid var(--border);
  border-radius:var(--r3); padding:1.8rem; max-width:480px; width:100%;
  box-shadow:var(--shadow-lg); position:relative; overflow:hidden;
  transform:translateY(16px); transition:transform .32s cubic-bezier(.34,1.56,.64,1);
}
#report-modal.open .report-box { transform:translateY(0); }
.report-box::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg, var(--cyan), var(--violet), var(--neon));
}

/* Refresh indicator */
.refresh-ring {
  width: 20px; height: 20px; position: relative; cursor: pointer;
}
.refresh-ring svg { animation: spin 8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* Animations */
@keyframes fadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
@keyframes shimmer { 0%{background-position:-600px 0} 100%{background-position:600px 0} }
.skeleton { background:linear-gradient(90deg, var(--bg-card) 25%, rgba(255,255,255,.05) 50%, var(--bg-card) 75%); background-size:1200px 100%; animation:shimmer 1.6s ease-in-out infinite; border-radius:var(--r); }

/* Mobile */
#mob-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:199; opacity:0; pointer-events:none; transition:opacity .3s; }
#mob-overlay.show { opacity:1; pointer-events:all; }
.mob-ham { display:none; background:none; border:none; color:var(--t1); font-size:1.3rem; cursor:pointer; }
@media(max-width:900px) {
  #sidebar { transform:translateX(-100%); }
  #sidebar.open { transform:translateX(0); }
  .main { margin-left:0; }
  .mob-ham { display:block; }
  .page { padding:1rem .9rem; }
  .g2,.g3 { grid-template-columns:1fr; }
  .kpi-grid { grid-template-columns:1fr 1fr; }
  .period-tabs { display:none; }
  .insights-grid { grid-template-columns:1fr 1fr; }
}
@media(max-width:480px) {
  .kpi-grid { grid-template-columns:1fr; }
  .insights-grid { grid-template-columns:1fr; }
  .rev-breakdown { grid-template-columns:1fr; }
  .ph-actions .btn-ghost { display:none; }
}
</style>
</head>
<body>
<div class="wrapper">

<!-- ══════════ SIDEBAR ══════════ -->
<aside id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">📊</div>
    <div class="sb-title">DLS <em>Analytics</em></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Dashboard</div>
    <a href="../dashboard.php" class="sb-item"><span class="sb-icon"><i class="bi bi-grid-1x2"></i></span> Dashboard</a>

    <div class="sb-section">Statistiques</div>
    <a href="index.php" class="sb-item active"><span class="sb-icon"><i class="bi bi-graph-up-arrow"></i></span> Vue globale</a>


    <div class="sb-section">Rapports</div>
    <a href="#" onclick="openReportModal()" class="sb-item"><span class="sb-icon"><i class="bi bi-file-earmark-text"></i></span> Générer rapport</a>
    <a href="?action=export_csv" class="sb-item"><span class="sb-icon"><i class="bi bi-file-earmark-spreadsheet"></i></span> Export CSV</a>

    <div class="sb-section">Administration</div>

  </nav>
  <div class="sb-footer">

  </div>
</aside>
<div id="mob-overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('show')"></div>

<!-- ══════════ MAIN ══════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header id="topbar">
    <button class="mob-ham" onclick="document.getElementById('sidebar').classList.add('open');document.getElementById('mob-overlay').classList.add('show')">
      <i class="bi bi-list"></i>
    </button>
    <div class="tb-bc">
      <span>DLS</span>
      <i class="bi bi-chevron-right" style="font-size:.55rem;opacity:.4"></i>
      <strong>Analytics</strong>
    </div>
    <div class="tb-spacer"></div>

    <!-- Period Selector -->
    <div class="period-tabs">
      <?php foreach (['7'=>'7j','30'=>'30j','90'=>'90j','365'=>'1an'] as $v => $l): ?>
      <a href="?period=<?= $v ?>" class="period-tab <?= $period === $v ? 'active' : '' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>

    <!-- Live badge -->
    <div class="live-badge" id="live-badge">
      <div class="live-dot"></div>
      <span>LIVE</span>
    </div>

    <!-- Refresh -->
    <div class="refresh-ring" onclick="doRefresh()" title="Rafraîchir maintenant" style="color:var(--t3)">
      <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
        <path d="M16.5 10a6.5 6.5 0 1 1-1.5-4.2" stroke-linecap="round"/>
        <path d="M15 3.5V7h-3.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>

    <div class="tb-avatar"><?= $avatar ?></div>
  </header>

  <!-- ══════════ PAGE ══════════ -->
  <div class="page" id="page-content">

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <div class="ph-title">📊 Analytics & Reporting</div>
        <div class="ph-sub">
          Données temps réel · Période : <strong style="color:var(--cyan)"><?= $periodLabel ?></strong>
          · Mis à jour il y a <span id="last-update">0</span>s
        </div>
      </div>
      <div class="ph-actions">
        <a href="#" onclick="openReportModal()" class="btn btn-ghost">
          <i class="bi bi-file-earmark-text"></i> Rapport
        </a>
        <div style="position:relative">
 
          <div id="export-menu" style="display:none;position:absolute;top:calc(100% + 5px);right:0;background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;z-index:50;min-width:160px;box-shadow:var(--shadow-lg)">
            <a href="?action=export_csv" class="sb-item" style="margin:0;padding:8px 14px;font-size:.78rem"><i class="bi bi-file-earmark-spreadsheet"></i> Export CSV</a>
            <a href="?action=export_report&type=monthly" class="sb-item" style="margin:0;padding:8px 14px;font-size:.78rem"><i class="bi bi-filetype-pdf"></i> Rapport PDF</a>
            <a href="?action=export_report&type=excel" class="sb-item" style="margin:0;padding:8px 14px;font-size:.78rem"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
          </div>
        </div>
        <button class="btn btn-primary" onclick="doRefresh()" type="button">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
      </div>
    </div>

    <!-- Alertes -->
    <?php if (!empty($alerts)): ?>
    <div class="alerts-row">
      <?php foreach ($alerts as $al): ?>
      <div class="alert alert-<?= $al['type'] ?>">
        <span><?= $al['icon'] ?></span>
        <span><?= htmlspecialchars($al['msg'], ENT_QUOTES, 'UTF-8') ?></span>
        <a href="<?= htmlspecialchars($al['action'], ENT_QUOTES, 'UTF-8') ?>">Voir &rarr;</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══ KPIs GLOBAUX ══ -->
    <div class="section-title">⚡ Indicateurs clés</div>
    <div class="kpi-grid" id="kpi-grid">

      <!-- Revenus totaux -->
      <div class="kpi" style="--kc:var(--neon)">
        <div class="kpi-icon" style="background:rgba(0,255,170,.1)">💰</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--neon),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= number_format($totalRevenue, 0, ',', ' ') ?>
        </div>
        <div class="kpi-label">Revenus totaux (FCFA)</div>
        <div class="kpi-delta <?= $revPct >= 0 ? 'up' : 'down' ?>">
          <i class="bi bi-arrow-<?= $revPct >= 0 ? 'up' : 'down' ?>-short"></i>
          <?= abs($revPct) ?>% vs mois précédent
        </div>
        <div class="kpi-glow" style="background:var(--neon)"></div>
      </div>

      <!-- Ventes -->
      <div class="kpi" style="--kc:var(--cyan)">
        <div class="kpi-icon" style="background:rgba(0,212,255,.1)">🛍️</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--cyan),var(--violet));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= number_format($totalSales) ?>
        </div>
        <div class="kpi-label">Ventes confirmées</div>
        <div class="kpi-delta <?= $salesPct >= 0 ? 'up' : 'down' ?>">
          <i class="bi bi-arrow-<?= $salesPct >= 0 ? 'up' : 'down' ?>-short"></i>
          <?= abs($salesPct) ?>% vs mois précédent
        </div>
        <div class="kpi-glow" style="background:var(--cyan)"></div>
      </div>

      <!-- Utilisateurs -->
      <div class="kpi">
        <div class="kpi-icon" style="background:rgba(124,58,237,.1)">👥</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--violet),var(--rose));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= number_format($totalUsers) ?>
        </div>
        <div class="kpi-label">Utilisateurs inscrits</div>
        <div class="kpi-delta up">
          <i class="bi bi-arrow-up-short"></i>
          +<?= $newUsersMonth ?> ce mois
        </div>
        <div class="kpi-glow" style="background:var(--violet)"></div>
      </div>

      <!-- Revenus du mois -->
      <div class="kpi">
        <div class="kpi-icon" style="background:rgba(245,158,11,.1)">📅</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--amber),var(--orange));-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-size:1.1rem">
          <?= number_format($revenueMonth, 0, ',', ' ') ?>
        </div>
        <div class="kpi-label">Revenus du mois (FCFA)</div>
        <div class="kpi-delta <?= $salesMonth >= $salesPrevMonth ? 'up' : 'down' ?>">
          <i class="bi bi-dot"></i> <?= $salesMonth ?> ventes ce mois
        </div>
        <div class="kpi-glow" style="background:var(--amber)"></div>
      </div>

      <!-- Livres -->
      <div class="kpi">
        <div class="kpi-icon" style="background:rgba(0,212,255,.07)">📚</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--cyan),var(--neon));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= number_format($totalBooks) ?>
        </div>
        <div class="kpi-label">Livres dans le catalogue</div>
        <div class="kpi-delta up">
          <i class="bi bi-arrow-up-short"></i> +<?= $newBooksWeek ?> cette semaine
        </div>
        <div class="kpi-glow" style="background:var(--cyan)"></div>
      </div>

      <!-- Panier moyen -->
      <div class="kpi">
        <div class="kpi-icon" style="background:rgba(244,63,94,.08)">🧾</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--rose),var(--violet));-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-size:1.2rem">
          <?= number_format($avgOrderValue, 0, ',', ' ') ?>
        </div>
        <div class="kpi-label">Panier moyen (FCFA)</div>
        <div class="kpi-delta neu"><i class="bi bi-dot"></i> Par achat confirmé</div>
        <div class="kpi-glow" style="background:var(--rose)"></div>
      </div>

      <!-- Taux conversion -->
      <div class="kpi">
        <div class="kpi-icon" style="background:rgba(99,102,241,.1)">🎯</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--indigo),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $conversionRate ?>%
        </div>
        <div class="kpi-label">Taux conversion</div>
        <div class="kpi-delta neu"><i class="bi bi-dot"></i> Achat / inscription</div>
        <div class="kpi-glow" style="background:var(--indigo)"></div>
      </div>

      <!-- Actifs aujourd'hui -->
      <div class="kpi">
        <div class="kpi-icon" style="background:rgba(0,255,170,.06)">🟢</div>
        <div class="kpi-val" style="background:linear-gradient(135deg,var(--neon),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
          <?= $activeToday ?>
        </div>
        <div class="kpi-label">Actifs aujourd'hui</div>
        <div class="kpi-delta <?= $activeRate >= 10 ? 'up' : 'neu' ?>">
          <i class="bi bi-dot"></i> <?= $activeRate ?>% du total
        </div>
        <div class="kpi-glow" style="background:var(--neon)"></div>
      </div>

    </div><!-- /kpi-grid -->

    <!-- ══ GRAPHIQUES PRINCIPAUX ══ -->
    <div class="section-title">📈 Évolution des ventes & revenus</div>
    <div class="g2">
      <!-- Sales trend -->
      <div class="card" style="animation-delay:.05s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(0,212,255,.1)"><i class="bi bi-bar-chart-fill" style="color:var(--cyan)"></i></div>
            Ventes par jour
          </div>
          <span class="chip chip-info"><?= $periodLabel ?></span>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:200px">
            <canvas id="chart-sales"></canvas>
          </div>
        </div>
        <div class="card-foot">
          <span>Total : <strong style="color:var(--neon)"><?= $totalSales ?> ventes</strong></span>
          <span>Moy. : <strong style="color:var(--cyan)"><?= $period > 0 ? round($totalSales / (int)$period, 1) : 0 ?>/jour</strong></span>
        </div>
      </div>

      <!-- Revenue trend -->
      <div class="card" style="animation-delay:.08s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(0,255,170,.08)"><i class="bi bi-graph-up" style="color:var(--neon)"></i></div>
            Revenus par jour
          </div>
          <span class="chip chip-success"><?= $periodLabel ?></span>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:200px">
            <canvas id="chart-revenue-daily"></canvas>
          </div>
        </div>
        <div class="card-foot">
          <span>Total : <strong style="color:var(--neon)"><?= fmtFCFA($totalRevenue) ?></strong></span>
          <span>Auj. : <strong style="color:var(--cyan)"><?= fmtFCFA($revenueToday) ?></strong></span>
        </div>
      </div>
    </div>

    <!-- Monthly charts -->
    <div class="g2">
      <!-- Monthly revenue -->
      <div class="card" style="animation-delay:.1s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(124,58,237,.1)"><i class="bi bi-currency-dollar" style="color:#a78bfa"></i></div>
            Revenus mensuels (12 mois)
          </div>
          <span class="chip chip-violet">Annuel</span>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:200px">
            <canvas id="chart-revenue-monthly"></canvas>
          </div>
        </div>
      </div>

      <!-- Users growth -->
      <div class="card" style="animation-delay:.12s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(244,63,94,.08)"><i class="bi bi-person-plus" style="color:var(--rose)"></i></div>
            Croissance utilisateurs
          </div>
          <span class="chip chip-danger">12 mois</span>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:200px">
            <canvas id="chart-users"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ BREAKDOWN REVENUS ══ -->
    <div class="section-title">💰 Détail des revenus</div>
    <div class="g13">
      <!-- Pie charts -->
      <div class="card" style="animation-delay:.14s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(245,158,11,.1)">🥧</div>
            Répartition
          </div>
        </div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div>
              <div style="font-size:.65rem;color:var(--t3);font-family:'Space Mono',monospace;text-align:center;margin-bottom:.5rem;text-transform:uppercase">Utilisateurs</div>
              <div class="chart-wrap" style="height:120px">
                <canvas id="chart-users-pie"></canvas>
              </div>
            </div>
            <div>
              <div style="font-size:.65rem;color:var(--t3);font-family:'Space Mono',monospace;text-align:center;margin-bottom:.5rem;text-transform:uppercase">Livres</div>
              <div class="chart-wrap" style="height:120px">
                <canvas id="chart-books-pie"></canvas>
              </div>
            </div>
          </div>
          <!-- Legend -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-top:.8rem">
            <div>
              <?php foreach (['admin'=>['var(--rose)','Admin'],'journaliste'=>['var(--amber)','Journalistes'],'lecteur'=>['var(--cyan)','Lecteurs']] as $k=>[$c,$l]): ?>
              <div style="display:flex;align-items:center;gap:5px;margin-bottom:3px;font-size:.68rem;color:var(--t2)">
                <div style="width:8px;height:8px;border-radius:50%;background:<?= $c ?>;flex-shrink:0"></div>
                <?= $l ?> <span style="margin-left:auto;font-family:'Space Mono',monospace;font-size:.6rem;color:var(--t1)"><?= $usersByRole[$k] ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <div>
              <div style="display:flex;align-items:center;gap:5px;margin-bottom:3px;font-size:.68rem;color:var(--t2)">
                <div style="width:8px;height:8px;border-radius:50%;background:var(--neon);flex-shrink:0"></div>
                Gratuits <span style="margin-left:auto;font-family:'Space Mono',monospace;font-size:.6rem;color:var(--t1)"><?= $freeBooks ?></span>
              </div>
              <div style="display:flex;align-items:center;gap:5px;font-size:.68rem;color:var(--t2)">
                <div style="width:8px;height:8px;border-radius:50%;background:var(--violet);flex-shrink:0"></div>
                Payants <span style="margin-left:auto;font-family:'Space Mono',monospace;font-size:.6rem;color:var(--t1)"><?= $paidBooks ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Revenue breakdown -->
      <div class="card" style="animation-delay:.15s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(0,255,170,.08)">💵</div>
            Revenus par période
          </div>
          <span class="chip chip-success"><?= fmtFCFA($totalRevenue) ?> total</span>
        </div>
        <div class="card-body">
          <div class="rev-breakdown" style="margin-bottom:1rem">
            <div class="rev-item">
              <div class="rev-item-label">Aujourd'hui</div>
              <div class="rev-item-val" style="color:var(--cyan)"><?= fmtFCFA($revenueToday) ?></div>
            </div>
            <div class="rev-item">
              <div class="rev-item-label">Cette semaine</div>
              <div class="rev-item-val" style="color:var(--neon)"><?= fmtFCFA($revenueWeek) ?></div>
            </div>
            <div class="rev-item">
              <div class="rev-item-label">Ce mois</div>
              <div class="rev-item-val" style="color:var(--amber)"><?= fmtFCFA($revenueMonth) ?></div>
            </div>
            <div class="rev-item">
              <div class="rev-item-label">Cette année</div>
              <div class="rev-item-val" style="color:var(--violet)"><?= fmtFCFA($revenueYear) ?></div>
            </div>
          </div>

          <!-- Méthodes de paiement -->
          <div style="font-size:.67rem;color:var(--t3);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.7rem">Méthodes de paiement</div>
          <?php
          $totalPay = array_sum(array_column($paymentMethods, 'count')) ?: 1;
          $payColors = ['orange_money'=>'var(--orange)','mobile_money'=>'var(--amber)','carte'=>'var(--cyan)'];
          foreach ($paymentMethods as $pm):
            $pct2 = round(($pm['count'] / $totalPay) * 100);
          ?>
          <div class="method-bar">
            <div class="method-info">
              <span class="method-name"><?= htmlspecialchars(ucwords(str_replace('_',' ',$pm['methode'])), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="method-val"><?= $pct2 ?>% · <?= fmtFCFA((float)$pm['total']) ?></span>
            </div>
            <div class="pb"><div class="pb-fill" style="width:<?= $pct2 ?>%;background:<?= $payColors[$pm['methode']] ?? 'var(--cyan)' ?>"></div></div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($paymentMethods)): ?>
          <p style="color:var(--t3);font-size:.75rem;text-align:center;padding:.8rem 0">Aucune vente enregistrée</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══ TOP LIVRES ══ -->
    <div class="section-title">🏆 Top performances</div>
    <div class="g2">
      <!-- Top vendus -->
      <div class="card" style="animation-delay:.17s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(245,158,11,.1)">🏅</div>
            Top 10 livres vendus
          </div>
          <a href="../books/index.php" class="btn btn-ghost btn-xs">Voir tout <i class="bi bi-arrow-right"></i></a>
        </div>
        <div style="overflow-x:auto">
          <table class="dt">
            <thead>
              <tr>
                <th>#</th>
                <th>Titre</th>
                <th>Catégorie</th>
                <th>Ventes</th>
                <th>Revenus</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($topBooks)): ?>
              <tr><td colspan="6" style="text-align:center;color:var(--t3);padding:1.5rem">Aucune vente</td></tr>
              <?php else: foreach ($topBooks as $i => $b): ?>
              <tr>
                <td><span class="rank rank-<?= $i+1 ?>"><?= $i+1 ?></span></td>
                <td>
                  <div class="td-bold" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars($b['titre'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="font-size:.64rem;color:var(--t3)"><?= htmlspecialchars($b['auteur'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><span class="chip chip-muted"><?= htmlspecialchars($b['categorie'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="td-bold" style="color:var(--neon)"><?= (int)$b['ventes'] ?></td>
                <td style="font-family:'Space Mono',monospace;font-size:.68rem;color:var(--amber)"><?= fmtFCFA((float)$b['revenue']) ?></td>
                <td style="font-size:.7rem;color:var(--amber)">
                  <?= (float)$b['note_moyenne'] > 0 ? '⭐ '.number_format((float)$b['note_moyenne'],1) : '—' ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Top lus -->
      <div class="card" style="animation-delay:.19s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(0,212,255,.1)">👁️</div>
            Top livres les plus lus
          </div>
        </div>
        <div class="card-body">
          <?php if (empty($topRead)): ?>
          <p style="text-align:center;color:var(--t3);font-size:.77rem;padding:1rem 0">Aucune progression de lecture</p>
          <?php else:
            $maxRead = max(array_column($topRead,'lecteurs')) ?: 1;
            foreach ($topRead as $i => $b):
              $pct3 = round(($b['lecteurs'] / $maxRead) * 100);
          ?>
          <div style="margin-bottom:.85rem">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:.77rem">
              <div style="display:flex;align-items:center;gap:7px">
                <span class="rank rank-<?= $i+1 ?>"><?= $i+1 ?></span>
                <div>
                  <div class="td-bold" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars($b['titre'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </div>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <div style="font-family:'Space Mono',monospace;font-size:.62rem;color:var(--cyan)"><?= (int)$b['lecteurs'] ?> lecteurs</div>
                <div style="font-size:.6rem;color:var(--t3)">moy. <?= round((float)$b['avg_prog'],0) ?>%</div>
              </div>
            </div>
            <div class="pb"><div class="pb-fill pb-cyan" style="width:<?= $pct3 ?>%"></div></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- ══ CATÉGORIES ══ -->
    <div class="section-title">🗂️ Analyse par catégories</div>
    <div class="g31">
      <!-- Table catégories -->
      <div class="card" style="animation-delay:.2s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(124,58,237,.1)">📁</div>
            Performance par catégorie
          </div>
        </div>
        <div style="overflow-x:auto">
          <table class="dt">
            <thead>
              <tr>
                <th>#</th>
                <th>Catégorie</th>
                <th>Livres</th>
                <th>Ventes</th>
                <th>Revenus</th>
                <th>Part</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $totalCatRevenue = array_sum(array_column($topCats,'revenue')) ?: 1;
              foreach ($topCats as $i => $cat):
                $share = round(($cat['revenue'] / $totalCatRevenue) * 100);
              ?>
              <tr>
                <td><span class="rank rank-<?= $i+1 ?>"><?= $i+1 ?></span></td>
                <td>
                  <div style="display:flex;align-items:center;gap:6px">
                    <span style="font-size:1rem"><?= htmlspecialchars($cat['icone'] ?? '📚', ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="td-bold"><?= htmlspecialchars($cat['nom'], ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                </td>
                <td style="color:var(--t2)"><?= (int)$cat['nb_livres'] ?></td>
                <td class="td-bold" style="color:var(--neon)"><?= (int)$cat['nb_ventes'] ?></td>
                <td style="font-family:'Space Mono',monospace;font-size:.66rem;color:var(--amber)"><?= fmtFCFA((float)$cat['revenue']) ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:6px">
                    <div class="pb" style="width:50px"><div class="pb-fill pb-cyan" style="width:<?= $share ?>%"></div></div>
                    <span style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--t3)"><?= $share ?>%</span>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Donut catégories -->
      <div class="card" style="animation-delay:.22s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(0,212,255,.1)">🥧</div>
            Ventes / catégorie
          </div>
        </div>
        <div class="card-body">
          <div class="chart-wrap" style="height:200px">
            <canvas id="chart-cats-donut"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ ACTIVITÉ ══ -->
    <div class="section-title">⏰ Activité & comportements</div>
    <div class="g2">
      <!-- Heures d'activité -->
      <div class="card" style="animation-delay:.23s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(0,212,255,.1)">🕐</div>
            Heures de forte activité
          </div>
          <?php if ($mostActiveHour !== null): ?>
          <span class="chip chip-info">Pic : <?= sprintf('%02d',intval($mostActiveHour)) ?>h00</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php
          $maxH = max(array_column($hoursData, 'count')) ?: 1;
          ?>
          <div class="hours-grid" style="height:56px">
            <?php foreach ($hoursData as $hd):
              $hh = max(3, round(($hd['count'] / $maxH) * 52));
              $isMax = ($mostActiveHour !== null && $hd['h'] === (int)$mostActiveHour);
            ?>
            <div class="hour-bar"
                 style="height:<?= $hh ?>px;<?= $isMax ? 'background:linear-gradient(to top,var(--neon),rgba(0,255,170,.3))' : '' ?>"
                 data-tip="<?= sprintf('%02d',$hd['h']) ?>h: <?= $hd['count'] ?>">
            </div>
            <?php endforeach; ?>
          </div>
          <div class="hours-labels">
            <?php for ($h=0;$h<24;$h++): ?>
            <div class="hl"><?= $h%3===0 ? sprintf('%02d',$h) : '' ?></div>
            <?php endfor; ?>
          </div>
        </div>
        <div class="card-foot">
          <span>Heure la + active : <strong style="color:var(--neon)"><?= $mostActiveHour !== null ? sprintf('%02d',intval($mostActiveHour)).'h00' : '—' ?></strong></span>
        </div>
      </div>

      <!-- Analytics lectures -->
      <div class="card" style="animation-delay:.24s">
        <div class="card-head">
          <div class="card-title">
            <div class="cti" style="background:rgba(0,255,170,.08)">📖</div>
            Analytics lectures
          </div>
        </div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-bottom:1rem">
            <div class="rev-item">
              <div class="rev-item-label">Sessions actives</div>
              <div class="rev-item-val" style="color:var(--neon)"><?= $totalProgessions ?></div>
            </div>
            <div class="rev-item">
              <div class="rev-item-label">Progression moy.</div>
              <div class="rev-item-val" style="color:var(--cyan)"><?= round($avgReadingProgress, 1) ?>%</div>
            </div>
            <div class="rev-item">
              <div class="rev-item-label">Lectures finies</div>
              <div class="rev-item-val" style="color:var(--amber)"><?= $completedReads ?></div>
            </div>
            <div class="rev-item">
              <div class="rev-item-label">Taux complétion</div>
              <div class="rev-item-val" style="color:var(--violet)"><?= $completionRate ?>%</div>
            </div>
          </div>
          <div style="font-size:.67rem;color:var(--t3);font-family:'Space Mono',monospace;text-transform:uppercase;margin-bottom:.5rem">Progression globale</div>
          <div class="pb"><div class="pb-fill pb-cyan" style="width:<?= round($avgReadingProgress) ?>%"></div></div>
          <div style="font-size:.65rem;color:var(--t3);margin-top:4px"><?= round($avgReadingProgress,1) ?>% de progression moyenne</div>
          <div style="margin-top:.9rem">
            <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--t2);margin-bottom:4px">
              <span>Lecteurs actifs (<?= $periodLabel ?>)</span>
              <strong style="color:var(--t1)"><?= $activeReaders ?></strong>
            </div>
            <div class="pb"><div class="pb-fill pb-green" style="width:<?= $totalUsers > 0 ? round(($activeReaders/$totalUsers)*100) : 0 ?>%"></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ INSIGHTS IA ══ -->
    <div class="section-title">🧠 Insights intelligents</div>
    <div class="insights-grid">
      <div class="insight" style="animation-delay:.25s">
        <div class="insight-icon">📖</div>
        <div class="insight-label">Livre le + populaire</div>
        <div class="insight-val"><?= htmlspecialchars($bestBook['titre'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="insight-sub"><?= (int)($bestBook['ventes'] ?? 0) ?> ventes · <?= fmtFCFA((float)($bestBook['revenue'] ?? 0)) ?></div>
      </div>

      <div class="insight" style="animation-delay:.27s">
        <div class="insight-icon">🗂️</div>
        <div class="insight-label">Catégorie tendance</div>
        <div class="insight-val"><?= htmlspecialchars(($bestCat['icone'] ?? '').' '.($bestCat['nom'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="insight-sub"><?= (int)($bestCat['nb_ventes'] ?? 0) ?> ventes · <?= (int)($bestCat['nb_livres'] ?? 0) ?> livres</div>
      </div>

      <div class="insight" style="animation-delay:.29s">
        <div class="insight-icon">⏰</div>
        <div class="insight-label">Heure de pointe</div>
        <div class="insight-val"><?= $mostActiveHour !== null ? sprintf('%02d',intval($mostActiveHour)).'h00 — '.sprintf('%02d',intval($mostActiveHour)+1).'h00' : '—' ?></div>
        <div class="insight-sub">Concentrez vos campagnes à cette heure</div>
      </div>

      <div class="insight" style="animation-delay:.31s">
        <div class="insight-icon">👤</div>
        <div class="insight-label">Utilisateur le + actif</div>
        <div class="insight-val"><?= $topUser ? htmlspecialchars(trim($topUser['prenom'].' '.$topUser['nom']), ENT_QUOTES, 'UTF-8') : '—' ?></div>
        <div class="insight-sub"><?= $topUser ? $topUser['achats'].' achats' : 'Aucun achat' ?></div>
      </div>

      <div class="insight" style="animation-delay:.33s">
        <div class="insight-icon">📈</div>
        <div class="insight-label">Croissance ventes (mois)</div>
        <div class="insight-val" style="color:<?= $salesPct >= 0 ? 'var(--neon)' : 'var(--rose)' ?>">
          <?= $salesPct >= 0 ? '+' : '' ?><?= $salesPct ?>%
        </div>
        <div class="insight-sub"><?= $salesMonth ?> vs <?= $salesPrevMonth ?> ventes (M-1)</div>
      </div>

      <div class="insight" style="animation-delay:.35s">
        <div class="insight-icon">🎯</div>
        <div class="insight-label">Conversion achat</div>
        <div class="insight-val" style="color:var(--cyan)"><?= $conversionRate ?>%</div>
        <div class="insight-sub">Sur <?= $totalUsers ?> inscrits · <?= $totalSales ?> acheteurs</div>
      </div>

      <div class="insight" style="animation-delay:.37s">
        <div class="insight-icon">📦</div>
        <div class="insight-label">Stock critique</div>
        <div class="insight-val" style="color:<?= $outOfStock > 0 ? 'var(--rose)' : 'var(--neon)' ?>">
          <?= $outOfStock > 0 ? $outOfStock.' rupture(s)' : 'OK ✓' ?>
        </div>
        <div class="insight-sub"><?= $lowStock ?> livre(s) avec stock faible</div>
      </div>

      <div class="insight" style="animation-delay:.39s">
        <div class="insight-icon">🎁</div>
        <div class="insight-label">Bonus fidélité</div>
        <div class="insight-val" style="color:var(--amber)"><?= $totalBonus ?> attribués</div>
        <div class="insight-sub"><?= $bonusAvailable ?> disponibles non utilisés</div>
      </div>
    </div>

    <!-- ══ UTILISATEURS ══ -->
    <div class="section-title">👥 Statistiques utilisateurs</div>
    <div class="g3">
      <div class="card" style="animation-delay:.4s">
        <div class="card-head">
          <div class="card-title"><div class="cti" style="background:rgba(244,63,94,.08)"><i class="bi bi-shield-check" style="color:var(--rose)"></i></div>Admins</div>
          <span class="chip chip-danger"><?= $totalAdmins ?></span>
        </div>
        <div class="card-body">
          <div class="pb"><div class="pb-fill" style="width:<?= $totalUsers > 0 ? round(($totalAdmins/$totalUsers)*100) : 0 ?>%;background:var(--rose)"></div></div>
          <div style="font-size:.68rem;color:var(--t3);margin-top:4px"><?= $totalUsers > 0 ? round(($totalAdmins/$totalUsers)*100, 1) : 0 ?>% du total</div>
        </div>
      </div>
      <div class="card" style="animation-delay:.42s">
        <div class="card-head">
          <div class="card-title"><div class="cti" style="background:rgba(245,158,11,.1)"><i class="bi bi-pen" style="color:var(--amber)"></i></div>Journalistes</div>
          <span class="chip chip-warn"><?= $totalJourno ?></span>
        </div>
        <div class="card-body">
          <div class="pb"><div class="pb-fill" style="width:<?= $totalUsers > 0 ? round(($totalJourno/$totalUsers)*100) : 0 ?>%;background:var(--amber)"></div></div>
          <div style="font-size:.68rem;color:var(--t3);margin-top:4px"><?= $totalUsers > 0 ? round(($totalJourno/$totalUsers)*100, 1) : 0 ?>% du total</div>
        </div>
      </div>
      <div class="card" style="animation-delay:.44s">
        <div class="card-head">
          <div class="card-title"><div class="cti" style="background:rgba(0,212,255,.1)"><i class="bi bi-book-half" style="color:var(--cyan)"></i></div>Lecteurs</div>
          <span class="chip chip-info"><?= $totalLecteurs ?></span>
        </div>
        <div class="card-body">
          <div class="pb"><div class="pb-fill pb-cyan" style="width:<?= $totalUsers > 0 ? round(($totalLecteurs/$totalUsers)*100) : 0 ?>%"></div></div>
          <div style="font-size:.68rem;color:var(--t3);margin-top:4px"><?= $totalUsers > 0 ? round(($totalLecteurs/$totalUsers)*100, 1) : 0 ?>% du total</div>
        </div>
      </div>
    </div>

    <!-- ══ DERNIÈRES VENTES ══ -->
    <div class="section-title">🧾 Dernières transactions</div>
    <div class="card" style="animation-delay:.45s;margin-bottom:1.4rem">
      <div class="card-head">
        <div class="card-title">
          <div class="cti" style="background:rgba(0,255,170,.08)">💳</div>
          Ventes récentes
        </div>
        <div style="display:flex;gap:5px">
          <a href="../admin/sales.php" class="btn btn-ghost btn-xs">Voir tout <i class="bi bi-arrow-right"></i></a>
          <a href="?action=export_csv" class="btn btn-success btn-xs"><i class="bi bi-download"></i> CSV</a>
        </div>
      </div>
      <div style="overflow-x:auto">
        <table class="dt">
          <thead>
            <tr>
              <th>Réf.</th>
              <th>Acheteur</th>
              <th>Livre</th>
              <th>Montant</th>
              <th>Méthode</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentSales)): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--t3);padding:2rem">Aucune vente</td></tr>
            <?php else: foreach ($recentSales as $sale): ?>
            <tr>
              <td style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--t3)"><?= htmlspecialchars(substr($sale['reference'] ?? 'N/A', 0, 12), ENT_QUOTES, 'UTF-8') ?>…</td>
              <td class="td-bold"><?= htmlspecialchars(trim(($sale['prenom'] ?? '').' '.($sale['nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
              <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--t2)"><?= htmlspecialchars($sale['livre_titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
              <td class="td-bold" style="color:var(--neon);font-family:'Space Mono',monospace;font-size:.72rem"><?= fmtFCFA((float)$sale['montant']) ?></td>
              <td>
                <?php $pm2 = $sale['methode'] ?? ''; ?>
                <span class="chip <?= $pm2==='orange_money' ? 'chip-warn' : ($pm2==='mobile_money' ? 'chip-info' : 'chip-muted') ?>">
                  <?= htmlspecialchars(ucwords(str_replace('_',' ',$pm2)), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--t3)">
                <?= !empty($sale['created_at']) ? date('d/m/Y H:i', strtotime($sale['created_at'])) : '—' ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-foot">
        <span><?= count($recentSales) ?> dernières transactions affichées</span>
        <span style="color:var(--neon);font-family:'Space Mono',monospace;font-size:.68rem"><?= fmtFCFA($totalRevenue) ?> total</span>
      </div>
    </div>

    <!-- ══ DERNIERS UTILISATEURS ══ -->
    <div class="card" style="animation-delay:.47s;margin-bottom:1.4rem">
      <div class="card-head">
        <div class="card-title">
          <div class="cti" style="background:rgba(124,58,237,.1)">👥</div>
          Derniers inscrits
        </div>
        <a href="../users/index.php" class="btn btn-ghost btn-xs">Gérer <i class="bi bi-arrow-right"></i></a>
      </div>
      <div style="overflow-x:auto">
        <table class="dt">
          <thead><tr><th>Utilisateur</th><th>Email</th><th>Rôle</th><th>Statut</th><th>Inscription</th><th>Dernière co.</th></tr></thead>
          <tbody>
            <?php foreach ($recentUsers as $u): ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:7px">
                  <div style="width:26px;height:26px;border-radius:8px;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.65rem;color:#fff;flex-shrink:0">
                    <?= strtoupper(substr($u['prenom'] ?? '?', 0, 1)) ?>
                  </div>
                  <span class="td-bold"><?= htmlspecialchars(trim(($u['prenom']??'').' '.($u['nom']??'')), ENT_QUOTES,'UTF-8') ?></span>
                </div>
              </td>
              <td style="font-size:.72rem;color:var(--t2)"><?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES,'UTF-8') ?></td>
              <td>
                <?php $rc=['admin'=>'chip-danger','journaliste'=>'chip-warn','lecteur'=>'chip-info']; ?>
                <span class="chip <?= $rc[$u['role']] ?? 'chip-muted' ?>"><?= htmlspecialchars($u['role'] ?? '', ENT_QUOTES,'UTF-8') ?></span>
              </td>
              <td>
                <?php $sc=['actif'=>'chip-success','bloque'=>'chip-danger','inactif'=>'chip-muted']; ?>
                <span class="chip <?= $sc[$u['statut']] ?? 'chip-muted' ?>"><?= htmlspecialchars($u['statut'] ?? '', ENT_QUOTES,'UTF-8') ?></span>
              </td>
              <td style="font-family:'Space Mono',monospace;font-size:.64rem;color:var(--t3)"><?= !empty($u['created_at']) ? date('d/m/Y', strtotime($u['created_at'])) : '—' ?></td>
              <td style="font-family:'Space Mono',monospace;font-size:.64rem;color:var(--t3)"><?= !empty($u['last_login']) ? date('d/m/Y H:i', strtotime($u['last_login'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentUsers)): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--t3);padding:2rem">Aucun utilisateur</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ══ RÉSUMÉ FINAL ══ -->
    <div class="section-title">📋 Résumé exécutif</div>
    <div class="g3" style="margin-bottom:0">
      <div class="card" style="animation-delay:.49s">
        <div class="card-head"><div class="card-title"><div class="cti" style="background:rgba(0,255,170,.08)">💼</div>Catalogue</div></div>
        <div class="card-body">
          <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.77rem">
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Total livres</span><strong><?= $totalBooks ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Gratuits</span><strong style="color:var(--neon)"><?= $freeBooks ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Payants</span><strong style="color:var(--cyan)"><?= $paidBooks ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">En vedette</span><strong style="color:var(--amber)"><?= $featuredBooks ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Rupture</span><strong style="color:var(--rose)"><?= $outOfStock ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Téléchargements</span><strong><?= number_format($totalDownloads) ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Catégories</span><strong><?= $totalCats ?></strong></div>
          </div>
        </div>
      </div>
      <div class="card" style="animation-delay:.51s">
        <div class="card-head"><div class="card-title"><div class="cti" style="background:rgba(0,212,255,.1)">👥</div>Utilisateurs</div></div>
        <div class="card-body">
          <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.77rem">
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Total</span><strong><?= $totalUsers ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Admins</span><strong style="color:var(--rose)"><?= $totalAdmins ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Journalistes</span><strong style="color:var(--amber)"><?= $totalJourno ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Lecteurs</span><strong style="color:var(--cyan)"><?= $totalLecteurs ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Actifs aujourd'hui</span><strong style="color:var(--neon)"><?= $activeToday ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Nouveaux ce mois</span><strong style="color:var(--neon)">+<?= $newUsersMonth ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Bloqués</span><strong style="color:var(--rose)"><?= $blockedUsers ?></strong></div>
          </div>
        </div>
      </div>
      <div class="card" style="animation-delay:.53s">
        <div class="card-head"><div class="card-title"><div class="cti" style="background:rgba(245,158,11,.1)">💰</div>Revenus</div></div>
        <div class="card-body">
          <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.77rem">
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Total cumulé</span><strong style="color:var(--neon)"><?= fmtFCFA($totalRevenue) ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Aujourd'hui</span><strong style="color:var(--cyan)"><?= fmtFCFA($revenueToday) ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Cette semaine</span><strong><?= fmtFCFA($revenueWeek) ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Ce mois</span><strong style="color:var(--amber)"><?= fmtFCFA($revenueMonth) ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Cette année</span><strong><?= fmtFCFA($revenueYear) ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Panier moyen</span><strong><?= fmtFCFA($avgOrderValue) ?></strong></div>
            <div style="display:flex;justify-content:space-between"><span style="color:var(--t2)">Bonus attribués</span><strong style="color:var(--amber)"><?= $totalBonus ?></strong></div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /page -->
</div><!-- /main -->
</div><!-- /wrapper -->

<!-- ══ REPORT MODAL ══ -->
<div id="report-modal">
  <div class="report-box">
    <button type="button" onclick="closeReportModal()" style="position:absolute;top:.8rem;right:.8rem;background:none;border:none;color:var(--t3);font-size:.9rem;cursor:pointer" aria-label="Fermer"><i class="bi bi-x-lg"></i></button>
    <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:1.2rem;margin-bottom:.4rem">📊 Générer un rapport</div>
    <p style="font-size:.77rem;color:var(--t2);margin-bottom:1.2rem">Sélectionnez le type de rapport à exporter</p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:1.2rem">
      <?php foreach (['daily'=>['📅','Journalier','Activité du jour'],'weekly'=>['📆','Hebdomadaire','7 derniers jours'],'monthly'=>['🗓️','Mensuel','Ce mois complet'],'annual'=>['📊','Annuel','Année en cours']] as $k=>[$ic,$n,$desc]): ?>
      <label style="cursor:pointer">
        <input type="radio" name="report_type" value="<?= $k ?>" style="display:none" <?= $k==='monthly'?'checked':'' ?>>
        <div class="report-type-opt" style="padding:.9rem;border-radius:var(--r2);border:2px solid var(--border);background:var(--bg-card);text-align:center;transition:border-color .16s" onclick="selectReport(this,'<?= $k ?>')">
          <div style="font-size:1.5rem;margin-bottom:.3rem"><?= $ic ?></div>
          <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem"><?= $n ?></div>
          <div style="font-size:.65rem;color:var(--t3)"><?= $desc ?></div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>

    <div style="margin-bottom:1rem">
      <div style="font-size:.67rem;color:var(--t3);font-family:'Space Mono',monospace;text-transform:uppercase;margin-bottom:.5rem">Format</div>
      <div style="display:flex;gap:.5rem">
        <label style="flex:1;cursor:pointer">
          <input type="radio" name="report_fmt" value="csv" style="display:none" checked>
          <div class="fmt-opt" style="padding:.6rem;border-radius:var(--r);border:1px solid var(--border);background:var(--bg-card);text-align:center;font-size:.72rem;font-family:'Syne',sans-serif;font-weight:700;cursor:pointer;transition:all .16s" onclick="selectFmt(this)">CSV</div>
        </label>
        <label style="flex:1;cursor:pointer">
          <input type="radio" name="report_fmt" value="pdf" style="display:none">
          <div class="fmt-opt" style="padding:.6rem;border-radius:var(--r);border:1px solid var(--border);background:var(--bg-card);text-align:center;font-size:.72rem;font-family:'Syne',sans-serif;font-weight:700;cursor:pointer;transition:all .16s" onclick="selectFmt(this)">PDF</div>
        </label>
        <label style="flex:1;cursor:pointer">
          <input type="radio" name="report_fmt" value="excel" style="display:none">
          <div class="fmt-opt" style="padding:.6rem;border-radius:var(--r);border:1px solid var(--border);background:var(--bg-card);text-align:center;font-size:.72rem;font-family:'Syne',sans-serif;font-weight:700;cursor:pointer;transition:all .16s" onclick="selectFmt(this)">Excel</div>
        </label>
      </div>
    </div>

    <button type="button" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px" onclick="doGenerateReport()">
      <i class="bi bi-file-earmark-arrow-down"></i> Générer & Télécharger
    </button>
  </div>
</div>

<!-- Toast stack -->
<div id="toast-stack"></div>

<!-- ══════════════ CHART DATA (PHP → JS) ══════════════ -->
<script>
// Data PHP → JS
const SALES_LABELS   = <?= json_encode(array_column($salesChartFull,'label'), JSON_UNESCAPED_UNICODE) ?>;
const SALES_DATA     = <?= json_encode(array_column($salesChartFull,'ventes')) ?>;
const REVENUE_DATA   = <?= json_encode(array_column($salesChartFull,'revenue')) ?>;
const REV_M_LABELS   = <?= json_encode(array_column($revenueChart,'label'),   JSON_UNESCAPED_UNICODE) ?>;
const REV_M_DATA     = <?= json_encode(array_column($revenueChart,'revenue')) ?>;
const USERS_LABELS   = <?= json_encode(array_column($usersChart,'label'),     JSON_UNESCAPED_UNICODE) ?>;
const USERS_DATA     = <?= json_encode(array_column($usersChart,'count')) ?>;
const CATS_LABELS    = <?= json_encode(array_column($topCats,'nom'),          JSON_UNESCAPED_UNICODE) ?>;
const CATS_VENTES    = <?= json_encode(array_column($topCats,'nb_ventes')) ?>;
const USERS_PIE      = <?= json_encode(array_values($usersByRole)) ?>;
const BOOKS_PIE      = <?= json_encode([$freeBooks,$paidBooks]) ?>;
const CSRF           = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

// Chart defaults
Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.color        = 'rgba(238,242,255,0.45)';
Chart.defaults.borderColor  = 'rgba(255,255,255,0.065)';

const CYAN   = '#00d4ff';
const VIOLET = '#7c3aed';
const NEON   = '#00ffaa';
const AMBER  = '#f59e0b';
const ROSE   = '#f43f5e';

function alphaHex(hex, a) {
  const r = parseInt(hex.slice(1,3),16);
  const g = parseInt(hex.slice(3,5),16);
  const b = parseInt(hex.slice(5,7),16);
  return `rgba(${r},${g},${b},${a})`;
}

function makeGradient(ctx, c1, c2, h = 200) {
  const g = ctx.createLinearGradient(0, 0, 0, h);
  g.addColorStop(0, alphaHex(c1, 0.6));
  g.addColorStop(1, alphaHex(c2 || c1, 0.02));
  return g;
}

const CHART_OPTS = {
  responsive: true, maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: 'rgba(8,13,22,0.95)',
      borderColor: 'rgba(255,255,255,0.1)',
      borderWidth: 1,
      titleFont: { family:"'Syne',sans-serif", weight:700 },
      bodyFont: { family:"'Space Mono',monospace", size:11 },
      padding: 10,
    }
  },
  scales: {
    x: {
      grid: { color: 'rgba(255,255,255,0.04)', drawTicks:false },
      ticks: { font: { family:"'Space Mono',monospace", size:10 }, maxTicksLimit: 8 }
    },
    y: {
      grid: { color: 'rgba(255,255,255,0.04)', drawTicks:false },
      ticks: { font: { family:"'Space Mono',monospace", size:10 } },
      beginAtZero: true,
    }
  }
};

// 1. Sales bar
(function() {
  const ctx = document.getElementById('chart-sales').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: SALES_LABELS,
      datasets: [{
        label: 'Ventes',
        data: SALES_DATA,
        backgroundColor: alphaHex(CYAN, 0.55),
        borderColor: CYAN,
        borderWidth: 1,
        borderRadius: 4,
        borderSkipped: false,
      }]
    },
    options: { ...CHART_OPTS, animation: { duration:1200, easing:'easeOutQuart' } }
  });
})();

// 2. Revenue daily area
(function() {
  const ctx = document.getElementById('chart-revenue-daily').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: SALES_LABELS,
      datasets: [{
        label: 'Revenus (FCFA)',
        data: REVENUE_DATA,
        fill: true,
        backgroundColor: makeGradient(ctx, NEON, NEON),
        borderColor: NEON,
        borderWidth: 2,
        pointRadius: 3,
        pointBackgroundColor: NEON,
        tension: 0.4,
      }]
    },
    options: {
      ...CHART_OPTS,
      plugins: { ...CHART_OPTS.plugins,
        tooltip: { ...CHART_OPTS.plugins.tooltip,
          callbacks: { label: ctx => ' ' + Number(ctx.raw).toLocaleString('fr-CM') + ' FCFA' }
        }
      },
      animation: { duration:1200, easing:'easeOutQuart' }
    }
  });
})();

// 3. Monthly revenue
(function() {
  const ctx = document.getElementById('chart-revenue-monthly').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: REV_M_LABELS,
      datasets: [{
        label: 'Revenus',
        data: REV_M_DATA,
        backgroundColor: REV_M_DATA.map((v, i) => {
          const max = Math.max(...REV_M_DATA, 1);
          const a = 0.25 + (v / max) * 0.55;
          return alphaHex(VIOLET, a);
        }),
        borderColor: VIOLET,
        borderWidth: 1,
        borderRadius: 4,
      }]
    },
    options: {
      ...CHART_OPTS,
      plugins: { ...CHART_OPTS.plugins,
        tooltip: { ...CHART_OPTS.plugins.tooltip,
          callbacks: { label: ctx => ' ' + Number(ctx.raw).toLocaleString('fr-CM') + ' FCFA' }
        }
      },
      animation: { duration:1400, easing:'easeOutBounce' }
    }
  });
})();

// 4. Users growth line
(function() {
  const ctx = document.getElementById('chart-users').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: USERS_LABELS,
      datasets: [{
        label: 'Nouveaux inscrits',
        data: USERS_DATA,
        fill: true,
        backgroundColor: makeGradient(ctx, ROSE, ROSE),
        borderColor: ROSE,
        borderWidth: 2,
        pointRadius: 3,
        pointBackgroundColor: ROSE,
        tension: 0.45,
      }]
    },
    options: {
      ...CHART_OPTS,
      animation: { duration:1200, easing:'easeOutQuart' }
    }
  });
})();

// 5. Users Pie
(function() {
  const ctx = document.getElementById('chart-users-pie').getContext('2d');
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: ['Admins','Journalistes','Lecteurs'],
      datasets: [{ data: USERS_PIE, backgroundColor: [ROSE, AMBER, CYAN], borderWidth:0, hoverOffset:6 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins: { legend:{display:false}, tooltip: CHART_OPTS.plugins.tooltip }, cutout:'68%' }
  });
})();

// 6. Books pie
(function() {
  const ctx = document.getElementById('chart-books-pie').getContext('2d');
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: ['Gratuits','Payants'],
      datasets: [{ data: BOOKS_PIE, backgroundColor: [NEON, VIOLET], borderWidth:0, hoverOffset:6 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins: { legend:{display:false}, tooltip: CHART_OPTS.plugins.tooltip }, cutout:'68%' }
  });
})();

// 7. Categories donut
(function() {
  const ctx = document.getElementById('chart-cats-donut').getContext('2d');
  const colors = [CYAN, VIOLET, NEON, AMBER, ROSE, '#6366f1','#ec4899','#14b8a6'];
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: CATS_LABELS,
      datasets: [{
        data: CATS_VENTES,
        backgroundColor: colors.slice(0, CATS_LABELS.length),
        borderWidth: 0,
        hoverOffset: 6,
      }]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins: {
        legend: { position:'bottom', labels:{ font:{family:"'Space Mono',monospace",size:9}, padding:10, boxWidth:10, color:'rgba(238,242,255,0.5)' } },
        tooltip: CHART_OPTS.plugins.tooltip
      },
      cutout: '62%',
      animation: { duration:1200, easing:'easeInOutQuart' }
    }
  });
})();

// ═══ TOAST ══════════════════════════════════════
function toast(title, sub, type='info', dur=3500) {
  const icons   = {info:'ℹ️',success:'✅',warn:'⚠️',error:'🔴'};
  const borders = {info:'var(--cyan)',success:'var(--neon)',warn:'var(--amber)',error:'var(--rose)'};
  const s  = document.getElementById('toast-stack');
  const el = document.createElement('div');
  el.className = 'toast';
  el.style.borderColor = borders[type] || borders.info;
  el.innerHTML = `<span>${icons[type]||'ℹ️'}</span><div class="t-body"><div class="t-title">${title}</div>${sub?`<div class="t-sub">${sub}</div>`:''}</div>`;
  s.appendChild(el);
  requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('show')));
  setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); }, dur);
}

// ═══ LIVE TIMER & AUTO-REFRESH ════════════════
let lastRefreshed = Date.now();
let autoRefreshTimer;

function startAutoRefresh() {
  autoRefreshTimer = setInterval(() => {
    const elapsed = Math.floor((Date.now() - lastRefreshed) / 1000);
    const el = document.getElementById('last-update');
    if (el) el.textContent = elapsed;
    if (elapsed >= 30) doRefresh();
  }, 1000);
}

function doRefresh() {
  clearInterval(autoRefreshTimer);
  toast('Actualisation…', 'Rechargement des données en cours', 'info', 1800);
  setTimeout(() => {
    lastRefreshed = Date.now();
    window.location.reload();
  }, 600);
}
startAutoRefresh();

// ═══ EXPORT MENU ═════════════════════════════
let exportMenuOpen = false;
function toggleExportMenu() {
  exportMenuOpen = !exportMenuOpen;
  document.getElementById('export-menu').style.display = exportMenuOpen ? 'block' : 'none';
}
document.addEventListener('click', e => {
  const m = document.getElementById('export-menu');
  if (m && exportMenuOpen && !m.parentElement.contains(e.target)) {
    exportMenuOpen = false;
    m.style.display = 'none';
  }
});

// ═══ REPORT MODAL ═════════════════════════════
let selectedReport = 'monthly';
let selectedFmt    = 'csv';

function openReportModal() {
  document.getElementById('report-modal').classList.add('open');
  // highlight default
  const opts = document.querySelectorAll('.report-type-opt');
  opts.forEach(o => o.style.borderColor = 'var(--border)');
  const monthly = document.querySelector('[onclick*="monthly"]');
  if (monthly) monthly.closest('.report-type-opt').style.borderColor = 'var(--cyan)';

  const fmtOpts = document.querySelectorAll('.fmt-opt');
  fmtOpts.forEach(o => o.style.borderColor = 'var(--border)');
  fmtOpts[0].style.borderColor = 'var(--cyan)';
  fmtOpts[0].style.color = 'var(--cyan)';
}
function closeReportModal() {
  document.getElementById('report-modal').classList.remove('open');
}
document.getElementById('report-modal').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeReportModal();
});

function selectReport(el, type) {
  selectedReport = type;
  document.querySelectorAll('.report-type-opt').forEach(o => {
    o.style.borderColor = 'var(--border)';
    o.style.background = 'var(--bg-card)';
  });
  el.style.borderColor = 'var(--cyan)';
  el.style.background = 'rgba(0,212,255,0.05)';
}
function selectFmt(el) {
  selectedFmt = el.closest('label').querySelector('input').value;
  document.querySelectorAll('.fmt-opt').forEach(o => {
    o.style.borderColor = 'var(--border)';
    o.style.color = '';
  });
  el.style.borderColor = 'var(--cyan)';
  el.style.color = 'var(--cyan)';
}
function doGenerateReport() {
  closeReportModal();
  toast('Génération en cours…', `Rapport ${selectedReport} (${selectedFmt.toUpperCase()})`, 'info', 2000);
  setTimeout(() => {
    window.location.href = `?action=export_report&type=${selectedReport}&fmt=${selectedFmt}`;
  }, 800);
}

// ═══ PROGRESS BARS ANIMATION ══════════════════
window.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    document.querySelectorAll('.pb-fill').forEach(b => {
      const w = b.style.width;
      b.style.width = '0%';
      requestAnimationFrame(() => requestAnimationFrame(() => { b.style.width = w; }));
    });
  }, 400);

  // Welcome toast
  setTimeout(() => toast('Analytics chargés ✓', 'Données en temps réel · Période : <?= addslashes($periodLabel) ?>', 'success', 4000), 600);

  // Success message from report
  <?php if ($reportGenerated): ?>
  setTimeout(() => toast('Rapport généré', '<?= addslashes($reportGenerated) ?>', 'success'), 1200);
  <?php endif; ?>
});

// ═══ KEYBOARD ════════════════════════════════
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeReportModal();
  if (e.key === 'r' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); doRefresh(); }
});
</script>
</body>
</html>