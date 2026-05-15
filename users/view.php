<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — users/view.php                           ║
 * ║  Fiche utilisateur · Lecture seule · Sécurisé PDO           ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Session ───────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Connexion PDO ─────────────────────────────────────────────
foreach ([
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config/database.php',
] as $cfgPath) {
    if (file_exists($cfgPath) && !defined('DB_HOST_LOADED')) {
        require_once $cfgPath;
        define('DB_HOST_LOADED', true);
        break;
    }
}

if (!isset($pdo) || $pdo === null) {
    $h = defined('DB_HOST') ? DB_HOST : 'localhost';
    $n = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $u = defined('DB_USER') ? DB_USER : 'root';
    $p = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$h};dbname={$n};charset=utf8mb4",
            $u, $p,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        error_log('[DLS] view.php PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth guard ────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$currentUserId   = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['user_role'] ?? 'lecteur';

// ── Validation ID ─────────────────────────────────────────────
$targetId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$errorMsg = '';
$user     = null;

if ($targetId === false || $targetId === null || $targetId < 1) {
    $errorMsg = 'Identifiant utilisateur invalide ou manquant.';
} elseif (!$pdo) {
    $errorMsg = 'Base de données inaccessible. Vérifiez la configuration.';
} else {
    try {
        // ── Requête préparée — lecture seule ──────────────────
        $stmt = $pdo->prepare(
            "SELECT
                id,
                nom,
                prenom,
                email,
                role,
                statut,
                telephone,
                avatar,
                created_at,
                last_login
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$targetId]);
        $user = $stmt->fetch();

        if (!$user) {
            $errorMsg = 'Aucun utilisateur trouvé avec cet identifiant.';
        }
    } catch (PDOException $e) {
        error_log('[DLS] view.php query: ' . $e->getMessage());
        $errorMsg = 'Erreur lors de la récupération des données.';
    }
}

// ── Helpers ───────────────────────────────────────────────────
function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtDate(?string $dt, string $fallback = '—'): string {
    if (!$dt || $dt === '0000-00-00 00:00:00') return $fallback;
    $ts = strtotime($dt);
    if (!$ts) return $fallback;
    return date('d/m/Y à H\hi', $ts);
}

function timeAgo(?string $dt): string {
    if (!$dt) return '';
    $diff = time() - strtotime($dt);
    if ($diff < 0)       return 'à l\'instant';
    if ($diff < 60)      return 'il y a quelques secondes';
    if ($diff < 3600)    return 'il y a ' . (int)($diff / 60) . ' min';
    if ($diff < 86400)   return 'il y a ' . (int)($diff / 3600) . 'h';
    if ($diff < 2592000) return 'il y a ' . (int)($diff / 86400) . ' jour(s)';
    return 'le ' . date('d/m/Y', strtotime($dt));
}

// Lien retour adapté (fonctionne depuis /users/)
$backLink = '../dashboard.php';

// Métadonnées rôle
$roleMap = [
    'admin'       => ['label' => 'Administrateur', 'color' => '#f43f5e', 'bg' => 'rgba(244,63,94,.12)', 'icon' => '⚡'],
    'journaliste' => ['label' => 'Journaliste',    'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,.12)', 'icon' => '✍️'],
    'lecteur'     => ['label' => 'Lecteur',         'color' => '#00ffaa', 'bg' => 'rgba(0,255,170,.10)', 'icon' => '📖'],
];
$statutMap = [
    'actif'   => ['label' => 'Actif',   'color' => '#00ffaa', 'dot' => '#00ffaa'],
    'inactif' => ['label' => 'Inactif', 'color' => '#94a3b8', 'dot' => '#94a3b8'],
    'bloque'  => ['label' => 'Bloqué',  'color' => '#f43f5e', 'dot' => '#f43f5e'],
];

$roleMeta   = $user ? ($roleMap[$user['role']]   ?? $roleMap['lecteur'])    : null;
$statutMeta = $user ? ($statutMap[$user['statut']] ?? $statutMap['inactif']) : null;

// Initiales avatar
$initials = '?';
if ($user) {
    $p1 = mb_substr(trim($user['prenom'] ?? ''), 0, 1);
    $p2 = mb_substr(trim($user['nom']    ?? ''), 0, 1);
    $initials = strtoupper($p1 . $p2) ?: '?';
}

// Titre page
$pageTitle = $user
    ? 'Profil · ' . e(trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')))
    : 'Profil introuvable';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?> — Digital Library</title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
/* ═══════════════════════════════════════════════════
   ROOT & RESET
═══════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #070b14;
    --bg2:      #0d1525;
    --bg3:      #111e32;
    --s1:       rgba(255,255,255,.03);
    --s2:       rgba(255,255,255,.055);
    --s3:       rgba(255,255,255,.09);
    --border:   rgba(255,255,255,.07);
    --border2:  rgba(255,255,255,.12);

    --cyan:     #38bdf8;
    --cyan2:    #0ea5e9;
    --ice:      #e0f2fe;
    --slate:    #94a3b8;
    --muted:    rgba(148,163,184,.5);

    --t1: #f1f5f9;
    --t2: rgba(241,245,249,.65);
    --t3: rgba(241,245,249,.35);
    --t4: rgba(241,245,249,.18);

    --r-sm: 6px;
    --r-md: 12px;
    --r-lg: 18px;
    --r-xl: 26px;
    --r-2xl: 36px;

    --shadow-sm:  0 1px 6px rgba(0,0,0,.25);
    --shadow-md:  0 4px 20px rgba(0,0,0,.38);
    --shadow-lg:  0 12px 48px rgba(0,0,0,.55);
    --shadow-glow: 0 0 32px rgba(56,189,248,.12);

    --font-display: 'Syne', sans-serif;
    --font-serif:   'Instrument Serif', Georgia, serif;
    --font-mono:    'DM Mono', monospace;
}

html { scroll-behavior: smooth; }

body {
    font-family: var(--font-display);
    background: var(--bg);
    color: var(--t1);
    min-height: 100vh;
    line-height: 1.6;
    overflow-x: hidden;
}

/* ─── Scrollbar ─── */
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 4px; }

/* ═══════════════════════════════════════════════════
   BACKGROUND AMBIENT
═══════════════════════════════════════════════════ */
.bg-ambient {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    overflow: hidden;
}

.bg-ambient::before {
    content: '';
    position: absolute;
    top: -20%;
    left: -10%;
    width: 60%;
    height: 60%;
    background: radial-gradient(ellipse, rgba(14,165,233,.07) 0%, transparent 65%);
    animation: drift1 18s ease-in-out infinite alternate;
}

.bg-ambient::after {
    content: '';
    position: absolute;
    bottom: -10%;
    right: -15%;
    width: 55%;
    height: 55%;
    background: radial-gradient(ellipse, rgba(139,92,246,.05) 0%, transparent 65%);
    animation: drift2 22s ease-in-out infinite alternate;
}

@keyframes drift1 {
    from { transform: translate(0,0) scale(1); }
    to   { transform: translate(4%,3%) scale(1.06); }
}
@keyframes drift2 {
    from { transform: translate(0,0) scale(1); }
    to   { transform: translate(-3%,-4%) scale(1.08); }
}

/* ═══════════════════════════════════════════════════
   PAGE LAYOUT
═══════════════════════════════════════════════════ */
.page {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2rem 1rem 4rem;
}

.container {
    width: 100%;
    max-width: 780px;
}

/* ═══════════════════════════════════════════════════
   TOP NAV BAR
═══════════════════════════════════════════════════ */
.topbar {
    width: 100%;
    max-width: 780px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 2.5rem;
    padding: .6rem .8rem .6rem .4rem;
    background: rgba(13,21,37,.6);
    backdrop-filter: blur(20px);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    animation: slideDown .4s ease both;
}

@keyframes slideDown {
    from { opacity:0; transform:translateY(-10px); }
    to   { opacity:1; transform:translateY(0); }
}

.topbar-brand {
    display: flex;
    align-items: center;
    gap: .55rem;
    font-size: .82rem;
    font-weight: 700;
    color: var(--t2);
    text-decoration: none;
}

.brand-icon {
    width: 32px; height: 32px;
    border-radius: var(--r-sm);
    background: linear-gradient(135deg, var(--cyan2), #6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem;
    box-shadow: var(--shadow-glow);
}

.topbar-center {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-family: var(--font-mono);
    font-size: .65rem;
    color: var(--t4);
    letter-spacing: .04em;
}

.topbar-center i {
    font-size: .6rem;
    opacity: .4;
}

.topbar-center .curr {
    color: var(--t2);
}

.back-btn {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .38rem .75rem;
    background: var(--s2);
    border: 1px solid var(--border2);
    border-radius: var(--r-sm);
    color: var(--t2);
    text-decoration: none;
    font-size: .74rem;
    font-family: var(--font-mono);
    transition: all .18s ease;
    white-space: nowrap;
}

.back-btn:hover {
    background: var(--s3);
    color: var(--t1);
    border-color: rgba(56,189,248,.3);
    box-shadow: 0 0 14px rgba(56,189,248,.1);
}

.back-btn i { font-size: .72rem; }

/* ═══════════════════════════════════════════════════
   ERROR STATE
═══════════════════════════════════════════════════ */
.error-card {
    text-align: center;
    padding: 4rem 2rem;
    background: var(--s1);
    border: 1px solid rgba(244,63,94,.18);
    border-radius: var(--r-xl);
    animation: fadeUp .5s ease both;
}

.error-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    display: block;
    opacity: .7;
}

.error-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--t1);
    margin-bottom: .5rem;
}

.error-msg {
    font-size: .82rem;
    color: var(--t3);
    line-height: 1.6;
}

/* ═══════════════════════════════════════════════════
   HERO CARD — profil principal
═══════════════════════════════════════════════════ */
@keyframes fadeUp {
    from { opacity:0; transform:translateY(16px); }
    to   { opacity:1; transform:translateY(0); }
}

.hero-card {
    background: linear-gradient(160deg,
        rgba(14,165,233,.04) 0%,
        rgba(13,21,37,.9) 40%,
        rgba(7,11,20,.95) 100%
    );
    border: 1px solid var(--border);
    border-radius: var(--r-2xl);
    overflow: hidden;
    position: relative;
    margin-bottom: 1.2rem;
    animation: fadeUp .5s ease both;
    box-shadow: var(--shadow-lg);
}

/* Bande top colorée */
.hero-card::before {
    content: '';
    display: block;
    height: 3px;
    background: linear-gradient(90deg, var(--cyan2), #6366f1, #a855f7);
}

/* Grille de fond subtile */
.hero-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(56,189,248,.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(56,189,248,.025) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none;
    mask-image: radial-gradient(ellipse at 30% 30%, black 0%, transparent 70%);
}

.hero-inner {
    padding: 2.2rem 2.4rem 2rem;
    position: relative;
    z-index: 1;
}

/* ─ Layout avatar + identité ─ */
.hero-top {
    display: flex;
    align-items: flex-start;
    gap: 1.8rem;
    margin-bottom: 2rem;
}

/* Avatar */
.avatar-wrap {
    flex-shrink: 0;
    position: relative;
}

.avatar {
    width: 90px;
    height: 90px;
    border-radius: 22px;
    background: linear-gradient(145deg, var(--cyan2), #6366f1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-display);
    font-size: 1.9rem;
    font-weight: 800;
    color: #fff;
    box-shadow: 0 8px 32px rgba(14,165,233,.28), var(--shadow-md);
    overflow: hidden;
    position: relative;
}

.avatar img {
    width: 100%; height: 100%;
    object-fit: cover;
    border-radius: 22px;
}

/* Pastille statut en bas de l'avatar */
.avatar-status {
    position: absolute;
    bottom: -3px;
    right: -3px;
    width: 20px; height: 20px;
    border-radius: 50%;
    border: 3px solid var(--bg2);
    background: var(--dot, #00ffaa);
    box-shadow: 0 0 10px var(--dot, #00ffaa);
}

/* Infos identité */
.hero-identity {
    flex: 1;
    min-width: 0;
    padding-top: .25rem;
}

.hero-name {
    font-family: var(--font-serif);
    font-size: 1.85rem;
    font-weight: 400;
    font-style: italic;
    color: var(--t1);
    line-height: 1.15;
    margin-bottom: .55rem;
    letter-spacing: -.02em;
}

.hero-email {
    display: flex;
    align-items: center;
    gap: .45rem;
    font-family: var(--font-mono);
    font-size: .78rem;
    color: var(--t3);
    margin-bottom: .9rem;
    word-break: break-all;
}

.hero-email i { color: var(--cyan2); font-size: .72rem; flex-shrink: 0; }

/* Badges inline */
.badge-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .45rem;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .28rem .75rem;
    border-radius: 100px;
    font-family: var(--font-mono);
    font-size: .65rem;
    font-weight: 500;
    letter-spacing: .04em;
    text-transform: uppercase;
    border: 1px solid rgba(255,255,255,.08);
    white-space: nowrap;
}

.badge-role {
    background: var(--role-bg, rgba(0,0,0,.2));
    color: var(--role-color, #fff);
    border-color: color-mix(in srgb, var(--role-color) 30%, transparent);
}

.badge-statut {
    background: color-mix(in srgb, var(--statut-color) 10%, transparent);
    color: var(--statut-color);
    border-color: color-mix(in srgb, var(--statut-color) 25%, transparent);
}

.badge-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}

.badge-statut .badge-dot {
    animation: pulse-dot 2s ease-in-out infinite;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; }
    50%       { opacity: .4; }
}

.badge-id {
    background: var(--s2);
    color: var(--t4);
    border-color: var(--border);
}

/* ─ Divider ─ */
.hero-divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--border2) 30%, var(--border2) 70%, transparent);
    margin-bottom: 1.8rem;
}

/* ═══════════════════════════════════════════════════
   INFO SECTIONS
═══════════════════════════════════════════════════ */
.sections-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.2rem;
    animation: fadeUp .55s ease .1s both;
}

.section-card {
    background: var(--s1);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    padding: 1.2rem 1.4rem;
    transition: border-color .2s, background .2s;
}

.section-card:hover {
    border-color: var(--border2);
    background: var(--s2);
}

.section-card.full-width {
    grid-column: 1 / -1;
}

.section-header {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: 1rem;
    padding-bottom: .65rem;
    border-bottom: 1px solid var(--border);
}

.section-icon {
    width: 28px; height: 28px;
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
    flex-shrink: 0;
}

.section-title {
    font-family: var(--font-mono);
    font-size: .62rem;
    font-weight: 500;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--t3);
}

/* ─ Champ de données ─ */
.field {
    display: flex;
    flex-direction: column;
    gap: .18rem;
    padding: .5rem 0;
    border-bottom: 1px solid rgba(255,255,255,.04);
}

.field:last-child { border-bottom: none; padding-bottom: 0; }
.field:first-child { padding-top: 0; }

.field-label {
    font-family: var(--font-mono);
    font-size: .6rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--t4);
}

.field-value {
    font-size: .82rem;
    color: var(--t2);
    font-weight: 500;
    word-break: break-word;
    line-height: 1.4;
}

.field-value.empty {
    color: var(--t4);
    font-style: italic;
    font-size: .75rem;
}

.field-value.mono {
    font-family: var(--font-mono);
    font-size: .76rem;
    color: var(--t3);
}

.field-value.highlight {
    font-size: .9rem;
    font-weight: 700;
    color: var(--t1);
}

/* Champ avec icône inline */
.field-value-row {
    display: flex;
    align-items: center;
    gap: .4rem;
}

.field-value-row i {
    font-size: .72rem;
    opacity: .5;
    flex-shrink: 0;
}

/* Indicateur de date relatif */
.date-ago {
    display: inline-block;
    font-family: var(--font-mono);
    font-size: .6rem;
    color: var(--t4);
    margin-top: 2px;
}

/* ═══════════════════════════════════════════════════
   FOOTER INFO
═══════════════════════════════════════════════════ */
.page-footer {
    margin-top: 1.8rem;
    text-align: center;
    animation: fadeUp .6s ease .2s both;
}

.footer-meta {
    font-family: var(--font-mono);
    font-size: .6rem;
    color: var(--t4);
    letter-spacing: .05em;
}

.footer-meta span { color: var(--t3); }

/* ═══════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════ */
@media (max-width: 640px) {
    .hero-inner { padding: 1.5rem 1.2rem 1.4rem; }
    .hero-top { flex-direction: column; align-items: center; text-align: center; gap: 1.2rem; }
    .hero-name { font-size: 1.45rem; }
    .badge-row { justify-content: center; }
    .sections-grid { grid-template-columns: 1fr; }
    .section-card.full-width { grid-column: 1; }
    .topbar-center { display: none; }
}

@media (max-width: 400px) {
    .topbar { flex-wrap: wrap; gap: .5rem; }
    .page { padding: 1rem .75rem 3rem; }
}
</style>
</head>

<body>
<div class="bg-ambient" aria-hidden="true"></div>

<div class="page">

  <!-- ═══ TOPBAR ═══ -->
  <nav class="topbar" role="navigation" aria-label="Navigation">
    <a href="<?= e($backLink) ?>" class="topbar-brand" aria-label="Digital Library">
      <div class="brand-icon">📚</div>
      <span>Digital Library</span>
    </a>

    <div class="topbar-center" aria-hidden="true">
      <span>Utilisateurs</span>
      <i class="bi bi-chevron-right"></i>
      <span class="curr">Fiche profil</span>
    </div>

    <a href="<?= e($backLink) ?>" class="back-btn" aria-label="Retour au tableau de bord">
      <i class="bi bi-arrow-left"></i>
      Dashboard
    </a>
  </nav>

  <!-- ═══ CONTENU ═══ -->
  <div class="container">

  <?php if ($errorMsg): ?>
  <!-- ── Erreur ── -->
  <div class="error-card" role="alert">
    <span class="error-icon">🔍</span>
    <div class="error-title">Utilisateur introuvable</div>
    <p class="error-msg"><?= e($errorMsg) ?></p>
  </div>

  <?php else:
    // ─ Valeurs échappées ─────────────────────────────────────
    $fullName  = e(trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')));
    $prenom    = e($user['prenom'] ?? '');
    $nom       = e($user['nom']    ?? '');
    $email     = e($user['email']  ?? '');
    $role      = e($user['role']   ?? 'lecteur');
    $statut    = e($user['statut'] ?? 'inactif');
    $telephone = trim($user['telephone'] ?? '');
    $avatarUrl = trim($user['avatar']    ?? '');
    $createdAt = fmtDate($user['created_at'] ?? '');
    $lastLogin = fmtDate($user['last_login'] ?? '');
    $lastAgo   = timeAgo($user['last_login'] ?? '');
    $userId    = (int)$user['id'];
  ?>

  <!-- ═══ HERO CARD ═══ -->
  <article class="hero-card" aria-label="Profil de <?= $fullName ?>">
    <div class="hero-inner">
      <div class="hero-top">

        <!-- Avatar -->
        <div class="avatar-wrap">
          <div class="avatar" aria-hidden="true">
            <?php if ($avatarUrl && filter_var($avatarUrl, FILTER_VALIDATE_URL)): ?>
              <img src="<?= e($avatarUrl) ?>" alt="Avatar de <?= $fullName ?>" loading="lazy">
            <?php else: ?>
              <?= e($initials) ?>
            <?php endif; ?>
          </div>
          <div class="avatar-status"
               style="--dot:<?= e($statutMeta['dot'] ?? '#94a3b8') ?>"
               title="<?= e($statutMeta['label'] ?? 'Inconnu') ?>"
               aria-label="Statut : <?= e($statutMeta['label'] ?? 'Inconnu') ?>">
          </div>
        </div>

        <!-- Identité -->
        <div class="hero-identity">
          <h1 class="hero-name"><?= $fullName ?></h1>

          <div class="hero-email">
            <i class="bi bi-envelope-fill" aria-hidden="true"></i>
            <span><?= $email ?></span>
          </div>

          <div class="badge-row">
            <!-- Badge rôle -->
            <span class="badge badge-role"
                  style="--role-color:<?= e($roleMeta['color']) ?>;--role-bg:<?= e($roleMeta['bg']) ?>"
                  aria-label="Rôle : <?= e($roleMeta['label']) ?>">
              <span aria-hidden="true"><?= $roleMeta['icon'] ?></span>
              <?= e($roleMeta['label']) ?>
            </span>

            <!-- Badge statut -->
            <span class="badge badge-statut"
                  style="--statut-color:<?= e($statutMeta['color']) ?>"
                  aria-label="Statut : <?= e($statutMeta['label']) ?>">
              <span class="badge-dot" aria-hidden="true"></span>
              <?= e($statutMeta['label']) ?>
            </span>

            <!-- Badge ID -->
            <span class="badge badge-id" aria-label="Identifiant #<?= $userId ?>">
              # <?= $userId ?>
            </span>
          </div>
        </div>

      </div><!-- /hero-top -->

      <div class="hero-divider" aria-hidden="true"></div>

      <!-- ═══ GRILLE D'INFORMATIONS ═══ -->
      <div class="sections-grid">

        <!-- ─ Identité ─ -->
        <div class="section-card">
          <div class="section-header">
            <div class="section-icon" style="background:rgba(56,189,248,.1);color:var(--cyan)" aria-hidden="true">
              <i class="bi bi-person-fill"></i>
            </div>
            <span class="section-title">Identité</span>
          </div>

          <div class="field">
            <div class="field-label">Prénom</div>
            <div class="field-value <?= $prenom ? '' : 'empty' ?>">
              <?= $prenom ?: 'Non renseigné' ?>
            </div>
          </div>

          <div class="field">
            <div class="field-label">Nom de famille</div>
            <div class="field-value <?= $nom ? '' : 'empty' ?>">
              <?= $nom ?: 'Non renseigné' ?>
            </div>
          </div>

          <div class="field">
            <div class="field-label">Nom complet</div>
            <div class="field-value highlight"><?= $fullName ?></div>
          </div>
        </div>

        <!-- ─ Contact ─ -->
        <div class="section-card">
          <div class="section-header">
            <div class="section-icon" style="background:rgba(168,85,247,.1);color:#a855f7" aria-hidden="true">
              <i class="bi bi-telephone-fill"></i>
            </div>
            <span class="section-title">Contact</span>
          </div>

          <div class="field">
            <div class="field-label">Adresse e-mail</div>
            <div class="field-value">
              <div class="field-value-row">
                <i class="bi bi-envelope" aria-hidden="true"></i>
                <span><?= $email ?></span>
              </div>
            </div>
          </div>

          <div class="field">
            <div class="field-label">Téléphone</div>
            <div class="field-value <?= $telephone ? '' : 'empty' ?>">
              <?php if ($telephone): ?>
                <div class="field-value-row">
                  <i class="bi bi-telephone" aria-hidden="true"></i>
                  <span><?= e($telephone) ?></span>
                </div>
              <?php else: ?>
                Non renseigné
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- ─ Accès & Rôle ─ -->
        <div class="section-card">
          <div class="section-header">
            <div class="section-icon" style="background:rgba(244,63,94,.08);color:#f43f5e" aria-hidden="true">
              <i class="bi bi-shield-lock-fill"></i>
            </div>
            <span class="section-title">Accès & Rôle</span>
          </div>

          <div class="field">
            <div class="field-label">Rôle système</div>
            <div class="field-value">
              <span style="color:<?= e($roleMeta['color']) ?>;font-weight:700">
                <?= $roleMeta['icon'] ?> <?= e($roleMeta['label']) ?>
              </span>
            </div>
          </div>

          <div class="field">
            <div class="field-label">Statut du compte</div>
            <div class="field-value">
              <span style="color:<?= e($statutMeta['color']) ?>;font-weight:600">
                <?= e($statutMeta['label']) ?>
              </span>
            </div>
          </div>

          <div class="field">
            <div class="field-label">Identifiant unique</div>
            <div class="field-value mono">#<?= $userId ?></div>
          </div>
        </div>

        <!-- ─ Activité ─ -->
        <div class="section-card">
          <div class="section-header">
            <div class="section-icon" style="background:rgba(245,158,11,.1);color:#f59e0b" aria-hidden="true">
              <i class="bi bi-clock-history"></i>
            </div>
            <span class="section-title">Activité</span>
          </div>

          <div class="field">
            <div class="field-label">Inscription</div>
            <div class="field-value mono"><?= $createdAt ?: '—' ?></div>
          </div>

          <div class="field">
            <div class="field-label">Dernière connexion</div>
            <?php if ($lastLogin && $lastLogin !== '—'): ?>
              <div class="field-value mono"><?= $lastLogin ?></div>
              <div class="date-ago"><?= e($lastAgo) ?></div>
            <?php else: ?>
              <div class="field-value empty">Jamais connecté</div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /sections-grid -->
    </div><!-- /hero-inner -->
  </article>

  <?php endif; ?>

  <!-- ═══ FOOTER ═══ -->
  <footer class="page-footer" role="contentinfo">
    <p class="footer-meta">
      Digital Library Platform
      <span>·</span>
      Fiche utilisateur
      <span>·</span>
      Lecture seule
      <?php if ($user): ?>
        <span>·</span>
        ID <span>#<?= (int)$user['id'] ?></span>
      <?php endif; ?>
    </p>
  </footer>

</div><!-- /container -->
</div><!-- /page -->

</body>
</html>