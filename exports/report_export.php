<?php
/**
 * stats/exports/reports_export.php
 * Système d'Export & Impression Professionnel — Digital Library
 * Version Premium 3.0 — Production Ready
 */

// ── Guard sécurité ──────────────────────────────────────────────
if (!defined('BIBLIO_APP')) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/session.php';
}

if (empty($pdo)) {
    die(json_encode(['error' => 'Erreur connexion base de données.']));
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die('Accès refusé.');
}

// ── Helpers ─────────────────────────────────────────────────────
function money(mixed $v): string {
    return number_format((float)$v, 0, ',', ' ') . ' FCFA';
}

function safeQuery(PDO $pdo, string $sql, array $params = []): mixed {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log('SQL Error: ' . $e->getMessage());
        return null;
    }
}

// ── Période filtre ───────────────────────────────────────────────
$period     = $_GET['period']     ?? '30';   // jours
$reportType = $_GET['report']     ?? 'all';
$search     = trim($_GET['search'] ?? '');

$dateFrom = date('Y-m-d', strtotime("-{$period} days"));
$dateTo   = date('Y-m-d');

// ── Statistiques globales ────────────────────────────────────────
function getStats(PDO $pdo, string $dateFrom, string $dateTo): array {
    $s = [];
    $queries = [
        'users_total'      => "SELECT COUNT(*) FROM users",
        'users_actifs'     => "SELECT COUNT(*) FROM users WHERE statut='actif'",
        'users_bloques'    => "SELECT COUNT(*) FROM users WHERE statut='bloque'",
        'admins'           => "SELECT COUNT(*) FROM users WHERE role='admin'",
        'journalistes'     => "SELECT COUNT(*) FROM users WHERE role='journaliste'",
        'lecteurs'         => "SELECT COUNT(*) FROM users WHERE role='lecteur'",
        'livres_total'     => "SELECT COUNT(*) FROM livres",
        'livres_disponibles'=> "SELECT COUNT(*) FROM livres WHERE statut='disponible'",
        'livres_gratuits'  => "SELECT COUNT(*) FROM livres WHERE prix=0",
        'livres_payants'   => "SELECT COUNT(*) FROM livres WHERE prix>0",
        'revenus_total'    => "SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme'",
        'revenus_period'   => "SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND DATE(created_at) BETWEEN ? AND ?",
        'ventes_total'     => "SELECT COUNT(*) FROM achats WHERE statut='confirme'",
        'ventes_period'    => "SELECT COUNT(*) FROM achats WHERE statut='confirme' AND DATE(created_at) BETWEEN ? AND ?",
        'ventes_echec'     => "SELECT COUNT(*) FROM achats WHERE statut='echec'",
        'bonus_total'      => "SELECT COALESCE(SUM(bonus_total),0) FROM user_bonus",
        'bonus_restant'    => "SELECT COALESCE(SUM(bonus_restant),0) FROM user_bonus",
        'notifs_total'     => "SELECT COUNT(*) FROM notifications",
        'notifs_nonlues'   => "SELECT COUNT(*) FROM notifications WHERE lu=0",
        'logs_total'       => "SELECT COUNT(*) FROM admin_logs",
        'logs_period'      => "SELECT COUNT(*) FROM admin_logs WHERE DATE(created_at) BETWEEN ? AND ?",
    ];

    foreach ($queries as $key => $sql) {
        $needsDates = str_contains($sql, '?');
        $stmt = $needsDates
            ? safeQuery($pdo, $sql, [$dateFrom, $dateTo])
            : safeQuery($pdo, $sql);
        $s[$key] = $stmt ? (float)$stmt->fetchColumn() : 0;
    }
    return $s;
}

$stats = getStats($pdo, $dateFrom, $dateTo);

// ── Top livres ───────────────────────────────────────────────────
$topBooksStmt = safeQuery($pdo, "
    SELECT l.titre, l.auteur, l.prix, l.categorie_id,
           COUNT(a.id) AS ventes,
           COALESCE(SUM(a.montant),0) AS revenus,
           l.note_moyenne, l.nb_lectures
    FROM livres l
    LEFT JOIN achats a ON a.livre_id=l.id AND a.statut='confirme'
    GROUP BY l.id, l.titre, l.auteur, l.prix, l.categorie_id, l.note_moyenne, l.nb_lectures
    ORDER BY ventes DESC, revenus DESC
    LIMIT 10
");
$topBooks = $topBooksStmt ? $topBooksStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// ── Ventes récentes ──────────────────────────────────────────────
$recentSalesStmt = safeQuery($pdo, "
    SELECT a.reference, CONCAT(u.prenom,' ',u.nom) AS acheteur,
           l.titre, a.montant, a.methode, a.statut, a.created_at
    FROM achats a
    JOIN users u  ON u.id=a.user_id
    JOIN livres l ON l.id=a.livre_id
    ORDER BY a.created_at DESC
    LIMIT 20
");
$recentSales = $recentSalesStmt ? $recentSalesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// ── Top utilisateurs ─────────────────────────────────────────────
$topUsersStmt = safeQuery($pdo, "
    SELECT CONCAT(u.prenom,' ',u.nom) AS nom_complet, u.email, u.role,
           COUNT(a.id) AS nb_achats,
           COALESCE(SUM(a.montant),0) AS depenses,
           COALESCE(ub.bonus_total,0) AS bonus
    FROM users u
    LEFT JOIN achats a     ON a.user_id=u.id AND a.statut='confirme'
    LEFT JOIN user_bonus ub ON ub.user_id=u.id
    GROUP BY u.id, u.prenom, u.nom, u.email, u.role, ub.bonus_total
    ORDER BY depenses DESC
    LIMIT 10
");
$topUsers = $topUsersStmt ? $topUsersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// ── Journaux système (admin_logs) ────────────────────────────────
$adminLogsStmt = safeQuery($pdo, "
    SELECT al.action, al.detail, al.ip, al.created_at,
           CONCAT(u.prenom,' ',u.nom) AS admin_name
    FROM admin_logs al
    LEFT JOIN users u ON u.id=al.user_id
    ORDER BY al.created_at DESC
    LIMIT 15
");
$adminLogs = $adminLogsStmt ? $adminLogsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// ── Revenus par méthode ──────────────────────────────────────────
$revenueByMethodStmt = safeQuery($pdo, "
    SELECT methode, COUNT(*) AS nb, COALESCE(SUM(montant),0) AS total
    FROM achats WHERE statut='confirme'
    GROUP BY methode ORDER BY total DESC
");
$revenueByMethod = $revenueByMethodStmt ? $revenueByMethodStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// ── Métadonnées rapport ──────────────────────────────────────────
$generatedAt  = date('d/m/Y H:i:s');
$adminName    = trim(($_SESSION['user_prenom'] ?? '') . ' ' . ($_SESSION['user_nom'] ?? 'Administrateur'));
$reportPeriod = $period === 'all' ? 'Depuis le début' : "30 derniers jours (du {$dateFrom} au {$dateTo})";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport Analytics — Digital Library</title>
<style>
/* ════════════════════════════════════════════════════════════
   DESIGN SYSTEM — DIGITAL LIBRARY REPORTS
   Style : Stripe / Vercel Admin — Dark Premium
════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Outfit:wght@300;400;500;600;700;800&display=swap');

:root {
    --bg:           #080c14;
    --bg-2:         #0d1526;
    --surface:      #111827;
    --surface-2:    #1a2235;
    --border:       rgba(255,255,255,.07);
    --border-2:     rgba(255,255,255,.12);
    --muted:        #64748b;
    --muted-2:      #94a3b8;
    --text:         #f0f6ff;
    --text-2:       #cbd5e1;
    --accent:       #22c55e;
    --accent-glow:  rgba(34,197,94,.18);
    --accent-2:     #3b82f6;
    --accent-3:     #f59e0b;
    --accent-4:     #ec4899;
    --radius:       12px;
    --radius-lg:    18px;
    --shadow:       0 4px 24px rgba(0,0,0,.5);
    --shadow-lg:    0 12px 48px rgba(0,0,0,.6);
    --font:         'Outfit', sans-serif;
    --mono:         'DM Mono', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { scroll-behavior: smooth; }

body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.6;
    overflow-x: hidden;
}

/* ── NOISE TEXTURE ─────────────────────────────────────── */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 0;
}

/* ════════════════════════════════════════════════════════════
   TOP BAR — CONTRÔLES
════════════════════════════════════════════════════════════ */
.topbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: 60px;
    background: rgba(8,12,20,.9);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    z-index: 1000;
    gap: 16px;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.topbar-logo {
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -.3px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.topbar-logo span {
    display: inline-block;
    width: 28px; height: 28px;
    background: linear-gradient(135deg, var(--accent), #16a34a);
    border-radius: 7px;
    font-size: 14px;
    display: flex; align-items: center; justify-content: center;
}

.topbar-sep { width: 1px; height: 22px; background: var(--border-2); }

.topbar-title {
    font-size: 13px;
    color: var(--muted-2);
    font-weight: 500;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── BOUTONS ────────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 8px;
    font-family: var(--font);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .2s cubic-bezier(.4,0,.2,1);
    text-decoration: none;
    white-space: nowrap;
    line-height: 1;
}

.btn svg { width: 14px; height: 14px; flex-shrink: 0; }

.btn-ghost {
    background: transparent;
    color: var(--muted-2);
    border-color: var(--border-2);
}
.btn-ghost:hover {
    background: var(--surface);
    color: var(--text);
    border-color: var(--border-2);
}

.btn-primary {
    background: var(--accent);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 0 0 0 var(--accent-glow);
}
.btn-primary:hover {
    background: #16a34a;
    box-shadow: 0 0 20px var(--accent-glow);
    transform: translateY(-1px);
}

.btn-blue {
    background: var(--accent-2);
    color: #fff;
}
.btn-blue:hover { background: #2563eb; transform: translateY(-1px); }

.btn-amber {
    background: var(--accent-3);
    color: #000;
}
.btn-amber:hover { background: #d97706; transform: translateY(-1px); }

/* ════════════════════════════════════════════════════════════
   FILTRES BAR
════════════════════════════════════════════════════════════ */
.filters-bar {
    position: fixed;
    top: 60px; left: 0; right: 0;
    background: rgba(13,21,38,.95);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border);
    padding: 10px 28px;
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 999;
    flex-wrap: wrap;
}

.filter-select {
    background: var(--surface);
    color: var(--text-2);
    border: 1px solid var(--border-2);
    border-radius: 8px;
    padding: 6px 12px;
    font-family: var(--font);
    font-size: 13px;
    cursor: pointer;
    outline: none;
    transition: border-color .2s;
}
.filter-select:focus { border-color: var(--accent); }

.filter-search {
    flex: 1;
    max-width: 280px;
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border-2);
    border-radius: 8px;
    padding: 6px 12px;
    font-family: var(--font);
    font-size: 13px;
    outline: none;
    transition: border-color .2s;
}
.filter-search::placeholder { color: var(--muted); }
.filter-search:focus { border-color: var(--accent); }

.filter-label {
    font-size: 12px;
    color: var(--muted);
    font-weight: 500;
}

.filter-sep { flex: 1; }

.period-badge {
    font-size: 11px;
    color: var(--accent);
    background: var(--accent-glow);
    border: 1px solid rgba(34,197,94,.25);
    border-radius: 999px;
    padding: 3px 10px;
    font-weight: 600;
    font-family: var(--mono);
}

/* ════════════════════════════════════════════════════════════
   MAIN CONTENT
════════════════════════════════════════════════════════════ */
.main {
    padding: 140px 28px 60px;
    max-width: 1400px;
    margin: 0 auto;
    position: relative;
    z-index: 1;
}

/* ── RAPPORT HEADER ─────────────────────────────────────── */
.report-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 36px;
    padding-bottom: 28px;
    border-bottom: 1px solid var(--border);
    gap: 20px;
}

.report-header-left h1 {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -.5px;
    color: var(--text);
    margin-bottom: 6px;
}

.report-header-left p {
    font-size: 13px;
    color: var(--muted-2);
}

.report-meta {
    text-align: right;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.8;
    font-family: var(--mono);
}

.report-meta strong { color: var(--text-2); }

/* ── KPI GRID ───────────────────────────────────────────── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 14px;
    margin-bottom: 36px;
}

.kpi-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px 22px;
    position: relative;
    overflow: hidden;
    transition: border-color .2s, transform .2s;
}

.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: var(--kpi-accent, var(--accent));
    opacity: .7;
}

.kpi-card:hover {
    border-color: var(--border-2);
    transform: translateY(-2px);
}

.kpi-card .kpi-icon {
    font-size: 22px;
    margin-bottom: 10px;
    display: block;
}

.kpi-card .kpi-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--muted);
    margin-bottom: 6px;
}

.kpi-card .kpi-value {
    font-size: 26px;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -.5px;
    font-family: var(--mono);
    line-height: 1;
}

.kpi-card .kpi-sub {
    font-size: 11px;
    color: var(--muted);
    margin-top: 6px;
}

/* ── SECTIONS ───────────────────────────────────────────── */
.section {
    margin-bottom: 40px;
}

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    gap: 12px;
}

.section-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title .icon {
    width: 30px; height: 30px;
    background: var(--surface-2);
    border: 1px solid var(--border-2);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
}

.section-count {
    font-size: 12px;
    color: var(--muted);
    font-family: var(--mono);
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 3px 10px;
    border-radius: 999px;
}

/* ── TABLEAUX ───────────────────────────────────────────── */
.table-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow);
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}

thead th {
    background: var(--surface-2);
    padding: 12px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .7px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    color: var(--text-2);
    vertical-align: middle;
}

tbody tr:last-child td { border-bottom: none; }

tbody tr {
    transition: background .15s;
}

tbody tr:hover td { background: rgba(255,255,255,.025); }

.td-strong { color: var(--text); font-weight: 600; }
.td-mono   { font-family: var(--mono); font-size: 12px; color: var(--muted-2); }
.td-money  { font-family: var(--mono); font-weight: 700; color: var(--accent); }

/* ── BADGES ─────────────────────────────────────────────── */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .2px;
    white-space: nowrap;
}
.badge-green  { background: rgba(34,197,94,.12);  color: #22c55e; border: 1px solid rgba(34,197,94,.2); }
.badge-blue   { background: rgba(59,130,246,.12); color: #60a5fa; border: 1px solid rgba(59,130,246,.2); }
.badge-amber  { background: rgba(245,158,11,.12); color: #fbbf24; border: 1px solid rgba(245,158,11,.2); }
.badge-red    { background: rgba(239,68,68,.12);  color: #f87171; border: 1px solid rgba(239,68,68,.2); }
.badge-purple { background: rgba(139,92,246,.12); color: #a78bfa; border: 1px solid rgba(139,92,246,.2); }

/* ── RANK ───────────────────────────────────────────────── */
.rank {
    display: inline-flex;
    width: 24px; height: 24px;
    border-radius: 6px;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 800;
    font-family: var(--mono);
}
.rank-1 { background: rgba(245,158,11,.2); color: #f59e0b; }
.rank-2 { background: rgba(148,163,184,.15); color: #94a3b8; }
.rank-3 { background: rgba(180,83,9,.15); color: #b45309; }
.rank-n { background: var(--surface-2); color: var(--muted); }

/* ── RÉSUMÉ MÉTHODES ────────────────────────────────────── */
.method-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}

.method-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
}

.method-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    background: var(--surface-2);
    flex-shrink: 0;
}

.method-info { flex: 1; }
.method-name { font-size: 12px; color: var(--muted-2); font-weight: 500; text-transform: capitalize; }
.method-amount { font-size: 18px; font-weight: 800; font-family: var(--mono); color: var(--text); }
.method-count { font-size: 11px; color: var(--muted); }

/* ── FOOTER RAPPORT ─────────────────────────────────────── */
.report-footer {
    margin-top: 60px;
    padding: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: var(--muted);
    gap: 16px;
}

.report-footer strong { color: var(--text-2); }

.footer-sig {
    display: flex;
    align-items: center;
    gap: 8px;
}

.footer-sig-avatar {
    width: 32px; height: 32px;
    background: linear-gradient(135deg, var(--accent), #3b82f6);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff;
}

/* ── LOADER ─────────────────────────────────────────────── */
.loader-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(8,12,20,.85);
    backdrop-filter: blur(8px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 16px;
}

.loader-overlay.active { display: flex; }

.loader-ring {
    width: 48px; height: 48px;
    border: 3px solid var(--border-2);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin .8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.loader-text { font-size: 14px; color: var(--muted-2); font-weight: 500; }

/* ── NOTIFICATION TOAST ─────────────────────────────────── */
.toast {
    position: fixed;
    bottom: 24px; right: 24px;
    background: var(--surface-2);
    border: 1px solid var(--border-2);
    border-radius: var(--radius);
    padding: 14px 18px;
    font-size: 13px;
    color: var(--text);
    z-index: 10000;
    transform: translateY(80px);
    opacity: 0;
    transition: all .3s cubic-bezier(.4,0,.2,1);
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: 320px;
    box-shadow: var(--shadow-lg);
}

.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { border-left: 3px solid var(--accent); }
.toast.error   { border-left: 3px solid #ef4444; }
.toast.info    { border-left: 3px solid var(--accent-2); }

/* ── DIVIDER ────────────────────────────────────────────── */
.divider {
    height: 1px;
    background: var(--border);
    margin: 24px 0;
}

/* ════════════════════════════════════════════════════════════
   APERÇU PDF
════════════════════════════════════════════════════════════ */
.preview-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.85);
    backdrop-filter: blur(12px);
    z-index: 5000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.preview-overlay.active { display: flex; }

.preview-panel {
    background: var(--surface);
    border: 1px solid var(--border-2);
    border-radius: var(--radius-lg);
    width: 100%; max-width: 860px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.preview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}

.preview-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.preview-body {
    flex: 1;
    overflow-y: auto;
    padding: 28px;
    background: #fff;
    color: #111;
}

.preview-actions {
    display: flex;
    gap: 8px;
    padding: 14px 20px;
    border-top: 1px solid var(--border);
    justify-content: flex-end;
    background: var(--surface-2);
    flex-shrink: 0;
}

/* ════════════════════════════════════════════════════════════
   @MEDIA PRINT — IMPRESSION PROFESSIONNELLE A4
════════════════════════════════════════════════════════════ */
@media print {
    @page {
        size: A4 portrait;
        margin: 14mm 12mm 14mm 12mm;
    }

    /* Masquer tous les éléments d'interface */
    .topbar,
    .filters-bar,
    .loader-overlay,
    .toast,
    .preview-overlay,
    .no-print,
    .btn,
    button {
        display: none !important;
    }

    /* Reset fond et couleurs */
    body {
        background: #ffffff !important;
        color: #111111 !important;
        padding: 0 !important;
        font-size: 10pt;
        font-family: 'Segoe UI', Arial, sans-serif !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    body::before { display: none !important; }

    .main {
        padding: 0 !important;
        max-width: 100% !important;
        margin: 0 !important;
    }

    /* HEADER IMPRESSION */
    .report-header {
        display: flex !important;
        flex-direction: column !important;
        border-bottom: 2px solid #e2e8f0 !important;
        padding-bottom: 14pt !important;
        margin-bottom: 20pt !important;
    }

    .print-header {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        width: 100% !important;
    }

    .report-header-left h1 {
        font-size: 20pt !important;
        color: #111 !important;
        font-weight: 900 !important;
    }

    .report-header-left p,
    .report-meta {
        color: #555 !important;
        font-size: 9pt !important;
    }

    /* KPI GRID PRINT */
    .kpi-grid {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 8pt !important;
        margin-bottom: 20pt !important;
    }

    .kpi-card {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 6pt !important;
        padding: 10pt 12pt !important;
        box-shadow: none !important;
        break-inside: avoid;
    }

    .kpi-card::before { background: #22c55e !important; height: 2px !important; }

    .kpi-card .kpi-label { color: #555 !important; font-size: 8pt !important; }
    .kpi-card .kpi-value { color: #111 !important; font-size: 18pt !important; }
    .kpi-card .kpi-sub   { color: #777 !important; font-size: 7pt !important; }
    .kpi-card .kpi-icon  { font-size: 16pt !important; }

    /* TABLEAUX PRINT */
    .table-wrap {
        background: #fff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 6pt !important;
        box-shadow: none !important;
        break-inside: auto;
    }

    table { font-size: 8.5pt !important; }

    thead th {
        background: #f1f5f9 !important;
        color: #333 !important;
        padding: 8pt 10pt !important;
        border-bottom: 1px solid #cbd5e1 !important;
    }

    tbody td {
        padding: 7pt 10pt !important;
        color: #222 !important;
        border-bottom: 1px solid #f1f5f9 !important;
    }

    .td-strong { color: #111 !important; }
    .td-mono   { color: #444 !important; }
    .td-money  { color: #16a34a !important; font-weight: 800 !important; }

    tbody tr:hover td { background: none !important; }

    /* BADGES PRINT */
    .badge {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .badge-green  { background: #dcfce7 !important; color: #15803d !important; border-color: #bbf7d0 !important; }
    .badge-blue   { background: #dbeafe !important; color: #1d4ed8 !important; border-color: #bfdbfe !important; }
    .badge-amber  { background: #fef3c7 !important; color: #92400e !important; border-color: #fde68a !important; }
    .badge-red    { background: #fee2e2 !important; color: #991b1b !important; border-color: #fecaca !important; }
    .badge-purple { background: #ede9fe !important; color: #5b21b6 !important; border-color: #ddd6fe !important; }

    /* SECTIONS PRINT */
    .section { margin-bottom: 18pt !important; break-inside: avoid; }

    .section-header { margin-bottom: 8pt !important; }

    .section-title {
        font-size: 12pt !important;
        color: #111 !important;
        font-weight: 800 !important;
    }

    .section-title .icon {
        background: #f1f5f9 !important;
        border-color: #e2e8f0 !important;
    }

    .section-count {
        background: #f1f5f9 !important;
        color: #555 !important;
        border-color: #e2e8f0 !important;
    }

    /* FOOTER PRINT */
    .report-footer {
        background: #f8fafc !important;
        border-color: #e2e8f0 !important;
        color: #555 !important;
        font-size: 8pt !important;
        padding: 12pt !important;
        margin-top: 24pt !important;
    }

    .report-footer strong { color: #333 !important; }

    .footer-sig-avatar {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* MÉTHODE CARDS PRINT */
    .method-grid {
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 8pt !important;
    }

    .method-card {
        background: #f8fafc !important;
        border-color: #e2e8f0 !important;
        padding: 10pt !important;
    }

    .method-icon { background: #f1f5f9 !important; }
    .method-name   { color: #555 !important; }
    .method-amount { color: #111 !important; font-size: 14pt !important; }
    .method-count  { color: #777 !important; }

    /* SAUTS DE PAGE */
    .page-break-before { page-break-before: always; break-before: page; }
    tr { break-inside: avoid; }
    thead { display: table-header-group; }
}

/* ════════════════════════════════════════════════════════════
   RESPONSIVE
════════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
    .topbar { padding: 0 14px; }
    .filters-bar { padding: 8px 14px; }
    .main { padding: 130px 14px 40px; }
    .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .report-header { flex-direction: column; }
    .report-meta { text-align: left; }
    .table-wrap { overflow-x: auto; }
    table { min-width: 600px; }
    .method-grid { grid-template-columns: 1fr 1fr; }
    .btn span { display: none; }
}
</style>
</head>
<body>

<!-- ══ LOADER ════════════════════════════════════════════════ -->
<div class="loader-overlay" id="loader">
    <div class="loader-ring"></div>
    <div class="loader-text">Génération en cours…</div>
</div>

<!-- ══ TOAST ═════════════════════════════════════════════════ -->
<div class="toast" id="toast">
    <span id="toast-icon">✅</span>
    <span id="toast-msg">Action effectuée</span>
</div>

<!-- ══ TOPBAR ════════════════════════════════════════════════ -->
<header class="topbar no-print">
    <div class="topbar-left">
        <!-- Bouton retour intelligent -->
        <a class="btn btn-ghost" href="javascript:void(0)" onclick="goBack()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            <span>Retour</span>
        </a>
        <div class="topbar-sep"></div>
        <div class="topbar-logo">
            <span>📊</span>
            Digital Library
        </div>
        <div class="topbar-sep"></div>
        <span class="topbar-title">Rapports & Exports</span>
    </div>

    <div class="topbar-right">
        <button class="btn btn-ghost" onclick="refreshPage()" title="Actualiser">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            <span>Actualiser</span>
        </button>
        <button class="btn btn-amber" onclick="showPreview()" title="Aperçu avant impression">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <span>Aperçu</span>
        </button>
        <button class="btn btn-blue" onclick="exportPDF()" title="Exporter PDF">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span>Export PDF</span>
        </button>
        <button class="btn btn-primary" onclick="printReport()" title="Imprimer">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            <span>Imprimer</span>
        </button>
    </div>
</header>

<!-- ══ FILTRES ════════════════════════════════════════════════ -->
<div class="filters-bar no-print">
    <span class="filter-label">Période</span>
    <select class="filter-select" id="periodSelect" onchange="applyFilters()">
        <option value="7"   <?= $period==='7'   ?'selected':'' ?>>7 jours</option>
        <option value="30"  <?= $period==='30'  ?'selected':'' ?>>30 jours</option>
        <option value="90"  <?= $period==='90'  ?'selected':'' ?>>90 jours</option>
        <option value="365" <?= $period==='365' ?'selected':'' ?>>1 an</option>
    </select>

    <div class="topbar-sep"></div>

    <span class="filter-label">Rapport</span>
    <select class="filter-select" id="reportSelect" onchange="applyFilters()">
        <option value="all"      <?= $reportType==='all'      ?'selected':'' ?>>Complet</option>
        <option value="ventes"   <?= $reportType==='ventes'   ?'selected':'' ?>>Ventes</option>
        <option value="users"    <?= $reportType==='users'    ?'selected':'' ?>>Utilisateurs</option>
        <option value="livres"   <?= $reportType==='livres'   ?'selected':'' ?>>Livres</option>
        <option value="finances" <?= $reportType==='finances' ?'selected':'' ?>>Finances</option>
        <option value="logs"     <?= $reportType==='logs'     ?'selected':'' ?>>Journaux</option>
    </select>

    <input
        type="text"
        class="filter-search"
        id="searchInput"
        placeholder="Recherche rapide…"
        value="<?= htmlspecialchars($search) ?>"
        oninput="filterTable(this.value)"
    >

    <div class="filter-sep"></div>

    <span class="period-badge">
        <?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?>
    </span>
</div>

<!-- ══ APERÇU MODAL ═══════════════════════════════════════════ -->
<div class="preview-overlay" id="previewOverlay">
    <div class="preview-panel">
        <div class="preview-header">
            <div class="preview-title">
                👁 Aperçu avant impression
            </div>
            <button class="btn btn-ghost" onclick="closePreview()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Fermer
            </button>
        </div>
        <div class="preview-body" id="previewBody">
            <p style="color:#666;font-size:13px;">Aperçu du rapport en cours de génération…</p>
        </div>
        <div class="preview-actions">
            <button class="btn btn-ghost" onclick="closePreview()">Fermer</button>
            <button class="btn btn-blue" onclick="exportPDF()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Exporter PDF
            </button>
            <button class="btn btn-primary" onclick="printReport()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Imprimer
            </button>
        </div>
    </div>
</div>

<!-- ══ CONTENU PRINCIPAL ══════════════════════════════════════ -->
<main class="main" id="reportContent">

    <!-- HEADER RAPPORT -->
    <div class="report-header">
        <div class="report-header-left">
            <h1>📊 Digital Library — Rapport Analytics</h1>
            <p>Données extraites en temps réel · Période : <?= htmlspecialchars($reportPeriod) ?></p>
        </div>
        <div class="report-meta">
            <strong>Généré le</strong> <?= htmlspecialchars($generatedAt) ?><br>
            <strong>Par</strong> <?= htmlspecialchars($adminName) ?><br>
            <strong>Type</strong> <?= ucfirst($reportType) ?>
        </div>
    </div>

    <!-- ════════ KPI CARDS ════════ -->
    <?php if (in_array($reportType, ['all', 'ventes', 'finances', 'users', 'livres'])): ?>
    <div class="kpi-grid">
        <div class="kpi-card" style="--kpi-accent:#22c55e">
            <span class="kpi-icon">👥</span>
            <div class="kpi-label">Utilisateurs Total</div>
            <div class="kpi-value"><?= number_format((int)$stats['users_total']) ?></div>
            <div class="kpi-sub"><?= (int)$stats['users_actifs'] ?> actifs · <?= (int)$stats['users_bloques'] ?> bloqués</div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#3b82f6">
            <span class="kpi-icon">📚</span>
            <div class="kpi-label">Livres Catalogue</div>
            <div class="kpi-value"><?= number_format((int)$stats['livres_total']) ?></div>
            <div class="kpi-sub"><?= (int)$stats['livres_gratuits'] ?> gratuits · <?= (int)$stats['livres_payants'] ?> payants</div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#f59e0b">
            <span class="kpi-icon">🛒</span>
            <div class="kpi-label">Ventes Confirmées</div>
            <div class="kpi-value"><?= number_format((int)$stats['ventes_total']) ?></div>
            <div class="kpi-sub">Période : <?= (int)$stats['ventes_period'] ?> · Échecs : <?= (int)$stats['ventes_echec'] ?></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#22c55e">
            <span class="kpi-icon">💰</span>
            <div class="kpi-label">Revenus Totaux</div>
            <div class="kpi-value" style="font-size:18px"><?= money($stats['revenus_total']) ?></div>
            <div class="kpi-sub">Période : <?= money($stats['revenus_period']) ?></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#ec4899">
            <span class="kpi-icon">🎁</span>
            <div class="kpi-label">Bonus Fidélité</div>
            <div class="kpi-value"><?= number_format((int)$stats['bonus_total']) ?></div>
            <div class="kpi-sub">Restants : <?= number_format((int)$stats['bonus_restant']) ?></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#8b5cf6">
            <span class="kpi-icon">🔔</span>
            <div class="kpi-label">Notifications</div>
            <div class="kpi-value"><?= number_format((int)$stats['notifs_total']) ?></div>
            <div class="kpi-sub">Non lues : <?= (int)$stats['notifs_nonlues'] ?></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#06b6d4">
            <span class="kpi-icon">📋</span>
            <div class="kpi-label">Actions Admin</div>
            <div class="kpi-value"><?= number_format((int)$stats['logs_total']) ?></div>
            <div class="kpi-sub">Période : <?= (int)$stats['logs_period'] ?></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#f43f5e">
            <span class="kpi-icon">🛡️</span>
            <div class="kpi-label">Rôles Admin / Staff</div>
            <div class="kpi-value"><?= (int)$stats['admins'] ?> / <?= (int)$stats['journalistes'] ?></div>
            <div class="kpi-sub">Lecteurs : <?= number_format((int)$stats['lecteurs']) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════ REVENUS PAR MÉTHODE ════════ -->
    <?php if (!empty($revenueByMethod) && in_array($reportType, ['all', 'finances', 'ventes'])): ?>
    <div class="section">
        <div class="section-header">
            <div class="section-title">
                <div class="icon">💳</div>
                Revenus par méthode de paiement
            </div>
            <span class="section-count"><?= count($revenueByMethod) ?> méthodes</span>
        </div>
        <div class="method-grid">
            <?php
            $methodIcons = [
                'orange_money'  => ['🟠', 'Orange Money'],
                'mobile_money'  => ['📱', 'Mobile Money'],
                'carte'         => ['💳', 'Carte Bancaire'],
                'wallet'        => ['👛', 'Wallet'],
            ];
            foreach ($revenueByMethod as $m):
                $icon  = $methodIcons[$m['methode']][0] ?? '💰';
                $label = $methodIcons[$m['methode']][1] ?? ucfirst($m['methode']);
            ?>
            <div class="method-card">
                <div class="method-icon"><?= $icon ?></div>
                <div class="method-info">
                    <div class="method-name"><?= htmlspecialchars($label) ?></div>
                    <div class="method-amount"><?= money($m['total']) ?></div>
                    <div class="method-count"><?= (int)$m['nb'] ?> transaction<?= $m['nb']>1?'s':'' ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════ TOP LIVRES ════════ -->
    <?php if (!empty($topBooks) && in_array($reportType, ['all', 'livres', 'ventes'])): ?>
    <div class="section" id="sectionLivres">
        <div class="section-header">
            <div class="section-title">
                <div class="icon">📚</div>
                Top 10 livres par ventes
            </div>
            <span class="section-count"><?= count($topBooks) ?> livres</span>
        </div>
        <div class="table-wrap">
            <table id="tableLivres">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Titre</th>
                        <th>Auteur</th>
                        <th>Prix unitaire</th>
                        <th>Ventes</th>
                        <th>Note</th>
                        <th>Lectures</th>
                        <th>Revenus générés</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topBooks as $i => $book):
                    $rankClass = $i === 0 ? 'rank-1' : ($i === 1 ? 'rank-2' : ($i === 2 ? 'rank-3' : 'rank-n'));
                    $note = round($book['note_moyenne'] ?? 0, 1);
                    $stars = str_repeat('★', (int)$note) . str_repeat('☆', 5-(int)$note);
                ?>
                    <tr>
                        <td><span class="rank <?= $rankClass ?>"><?= $i + 1 ?></span></td>
                        <td class="td-strong"><?= htmlspecialchars($book['titre']) ?></td>
                        <td><?= htmlspecialchars($book['auteur']) ?></td>
                        <td class="td-mono"><?= money($book['prix']) ?></td>
                        <td>
                            <span class="badge badge-<?= $book['ventes']>0?'green':'blue' ?>">
                                <?= (int)$book['ventes'] ?> vente<?= $book['ventes']>1?'s':'' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($note > 0): ?>
                            <span title="<?= $note ?>/5" style="color:#f59e0b;font-size:12px"><?= $stars ?></span>
                            <small style="color:var(--muted);margin-left:4px"><?= $note ?></small>
                            <?php else: ?>
                            <span style="color:var(--muted);font-size:12px">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-mono"><?= number_format((int)$book['nb_lectures']) ?></td>
                        <td class="td-money"><?= money($book['revenus']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════ VENTES RÉCENTES ════════ -->
    <?php if (!empty($recentSales) && in_array($reportType, ['all', 'ventes', 'finances'])): ?>
    <div class="section" id="sectionVentes">
        <div class="section-header">
            <div class="section-title">
                <div class="icon">🛒</div>
                20 dernières transactions
            </div>
            <span class="section-count"><?= count($recentSales) ?> transactions</span>
        </div>
        <div class="table-wrap">
            <table id="tableVentes">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Acheteur</th>
                        <th>Livre</th>
                        <th>Montant</th>
                        <th>Méthode</th>
                        <th>Statut</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentSales as $sale):
                    $statutClass = match($sale['statut']) {
                        'confirme'   => 'badge-green',
                        'en_attente' => 'badge-amber',
                        'echec'      => 'badge-red',
                        default      => 'badge-blue',
                    };
                    $statutLabel = match($sale['statut']) {
                        'confirme'   => '✓ Confirmé',
                        'en_attente' => '⌛ En attente',
                        'echec'      => '✕ Échec',
                        default      => ucfirst($sale['statut']),
                    };
                ?>
                    <tr>
                        <td class="td-mono"><?= htmlspecialchars($sale['reference']) ?></td>
                        <td class="td-strong"><?= htmlspecialchars($sale['acheteur']) ?></td>
                        <td><?= htmlspecialchars($sale['titre']) ?></td>
                        <td class="td-money"><?= money($sale['montant']) ?></td>
                        <td>
                            <span class="badge badge-blue">
                                <?= htmlspecialchars(ucfirst(str_replace('_',' ',$sale['methode']))) ?>
                            </span>
                        </td>
                        <td><span class="badge <?= $statutClass ?>"><?= $statutLabel ?></span></td>
                        <td class="td-mono"><?= date('d/m/Y H:i', strtotime($sale['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════ TOP UTILISATEURS ════════ -->
    <?php if (!empty($topUsers) && in_array($reportType, ['all', 'users'])): ?>
    <div class="section page-break-before" id="sectionUsers">
        <div class="section-header">
            <div class="section-title">
                <div class="icon">👑</div>
                Top 10 utilisateurs (dépenses)
            </div>
            <span class="section-count"><?= count($topUsers) ?> utilisateurs</span>
        </div>
        <div class="table-wrap">
            <table id="tableUsers">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Utilisateur</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Achats</th>
                        <th>Bonus</th>
                        <th>Total dépensé</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topUsers as $i => $u):
                    $rankClass = $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-n'));
                    $roleClass = match($u['role']) {
                        'admin'       => 'badge-red',
                        'journaliste' => 'badge-purple',
                        default       => 'badge-blue',
                    };
                ?>
                    <tr>
                        <td><span class="rank <?= $rankClass ?>"><?= $i+1 ?></span></td>
                        <td class="td-strong"><?= htmlspecialchars($u['nom_complet']) ?></td>
                        <td class="td-mono"><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="badge <?= $roleClass ?>"><?= ucfirst($u['role']) ?></span></td>
                        <td class="td-mono"><?= (int)$u['nb_achats'] ?></td>
                        <td>
                            <?php if ($u['bonus'] > 0): ?>
                            <span class="badge badge-amber">🎁 <?= (int)$u['bonus'] ?></span>
                            <?php else: ?>
                            <span style="color:var(--muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-money"><?= money($u['depenses']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════ JOURNAUX SYSTÈME ════════ -->
    <?php if (!empty($adminLogs) && in_array($reportType, ['all', 'logs'])): ?>
    <div class="section" id="sectionLogs">
        <div class="section-header">
            <div class="section-title">
                <div class="icon">🔍</div>
                Journal des activités admin
            </div>
            <span class="section-count"><?= count($adminLogs) ?> entrées récentes</span>
        </div>
        <div class="table-wrap">
            <table id="tableLogs">
                <thead>
                    <tr>
                        <th>Administrateur</th>
                        <th>Action</th>
                        <th>Détail</th>
                        <th>Adresse IP</th>
                        <th>Date & Heure</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($adminLogs as $log): ?>
                    <tr>
                        <td class="td-strong"><?= htmlspecialchars($log['admin_name'] ?? 'Système') ?></td>
                        <td>
                            <span class="badge badge-purple">
                                <?= htmlspecialchars(strtoupper($log['action'] ?? '—')) ?>
                            </span>
                        </td>
                        <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars(substr($log['detail'] ?? '—', 0, 100)) ?>
                        </td>
                        <td class="td-mono"><?= htmlspecialchars($log['ip'] ?? '—') ?></td>
                        <td class="td-mono"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- FOOTER -->
    <div class="report-footer">
        <div>
            <strong>Digital Library Platform</strong> · Rapport généré le <?= htmlspecialchars($generatedAt) ?><br>
            Données MySQL · Accès administrateur sécurisé · Confidentiel
        </div>
        <div class="footer-sig">
            <div class="footer-sig-avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div>
            <div>
                <strong><?= htmlspecialchars($adminName) ?></strong><br>
                Administrateur système
            </div>
        </div>
    </div>

</main>

<!-- ══ JAVASCRIPT ════════════════════════════════════════════ -->
<script>
/* ── Retour intelligent ──────────────────────────────────── */
function goBack() {
    if (document.referrer && document.referrer !== window.location.href) {
        window.history.back();
    } else {
        window.location.href = 'admin/dashboard.php';
    }
}

/* ── Toast notification ──────────────────────────────────── */
function showToast(msg, type = 'success') {
    const toast = document.getElementById('toast');
    const icon  = document.getElementById('toast-icon');
    const text  = document.getElementById('toast-msg');
    const icons = { success: '✅', error: '❌', info: 'ℹ️' };
    toast.className = `toast ${type}`;
    icon.textContent = icons[type] || '✅';
    text.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3500);
}

/* ── Loader ──────────────────────────────────────────────── */
function showLoader(msg = 'Traitement en cours…') {
    document.querySelector('#loader .loader-text').textContent = msg;
    document.getElementById('loader').classList.add('active');
}
function hideLoader() {
    document.getElementById('loader').classList.remove('active');
}

/* ── Filtres & rechargement ──────────────────────────────── */
function applyFilters() {
    const period = document.getElementById('periodSelect').value;
    const report = document.getElementById('reportSelect').value;
    const search = document.getElementById('searchInput').value;
    showLoader('Application des filtres…');
    const url = new URL(window.location.href);
    url.searchParams.set('period', period);
    url.searchParams.set('report', report);
    if (search) url.searchParams.set('search', search);
    else url.searchParams.delete('search');
    window.location.href = url.toString();
}

/* ── Recherche rapide côté client ────────────────────────── */
function filterTable(query) {
    query = query.toLowerCase().trim();
    document.querySelectorAll('table tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = (!query || text.includes(query)) ? '' : 'none';
    });
}

/* ── Actualiser ──────────────────────────────────────────── */
function refreshPage() {
    showLoader('Actualisation des données…');
    window.location.reload();
}

/* ── Imprimer ────────────────────────────────────────────── */
function printReport() {
    showToast('Ouverture de la fenêtre d\'impression…', 'info');
    setTimeout(() => window.print(), 400);
}

/* ── Aperçu ──────────────────────────────────────────────── */
function showPreview() {
    const overlay = document.getElementById('previewOverlay');
    const body    = document.getElementById('previewBody');
    const content = document.getElementById('reportContent');
    // Cloner le contenu du rapport pour l'aperçu
    body.innerHTML = '';
    const clone = content.cloneNode(true);
    // Retirer les éléments no-print du clone
    clone.querySelectorAll('.no-print').forEach(el => el.remove());
    body.appendChild(clone);
    overlay.classList.add('active');
}

function closePreview() {
    document.getElementById('previewOverlay').classList.remove('active');
}

// Fermer aperçu au clic extérieur
document.getElementById('previewOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});

/* ── Export PDF via print ────────────────────────────────── */
function exportPDF() {
    showToast('Génération du PDF en cours…', 'info');
    // Méthode native : déclencher l'impression avec sauvegarde PDF
    setTimeout(() => {
        try {
            // Pré-configurer le titre pour le nom de fichier PDF
            const originalTitle = document.title;
            document.title = `Rapport_Digital_Library_<?= date('Y-m-d') ?>`;
            window.print();
            document.title = originalTitle;
            showToast('PDF généré ! Choisissez "Enregistrer en PDF" dans la boîte de dialogue.', 'success');
        } catch(e) {
            showToast('Erreur lors de la génération PDF.', 'error');
        }
    }, 600);
}

/* ── Initialisation ──────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    // Raccourcis clavier
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            printReport();
        }
        if (e.key === 'Escape') {
            closePreview();
        }
    });

    // Appliquer la recherche si présente dans l'URL
    const searchParam = new URLSearchParams(window.location.search).get('search');
    if (searchParam) filterTable(searchParam);

    console.log('[Digital Library] Rapport chargé · <?= $generatedAt ?>');
});
</script>

</body>
</html>