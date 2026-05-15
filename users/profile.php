<?php
/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — Profile utilisateur           ║
 * ║  VERSION ULTRA STABLE CORRIGÉE                          ║
 * ╚══════════════════════════════════════════════════════════╝
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/data.php';



if (!function_exists('fmtFCFA2')) {

    function fmtFCFA2(float $amount): string
    {
        if ($amount <= 0) {
            return 'Gratuit';
        }

        return number_format($amount, 0, ',', ' ') . ' FCFA';
    }
}

// ── SESSION ───────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── AUTH ──────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ── RÉCUPÉRATION UTILISATEUR ──────────────────────────
$user = null;

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT id, nom, prenom, email, role, statut, avatar, telephone, created_at, last_login
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch();

} catch (Throwable $e) {
    error_log('[PROFILE ERROR] ' . $e->getMessage());
}

// ── FALLBACK FIABLE ───────────────────────────────────
if (!$user) {
    $user = [
        'id'        => $userId,
        'nom'       => $_SESSION['user_name'] ?? '',
        'prenom'    => $_SESSION['user_prenom'] ?? '',
        'email'     => $_SESSION['user_email'] ?? '',
        'role'      => $_SESSION['user_role'] ?? 'lecteur',
        'statut'    => 'actif',
        'avatar'    => null,
        'telephone' => null,
        'created_at'=> null,
        'last_login'=> null,
    ];
}

// ── NOM UTILISATEUR INTELLIGENT ───────────────────────
$prenom = trim($user['prenom'] ?? '');
$nom    = trim($user['nom'] ?? '');

// 🔥 Correction intelligente anti-doublon
if ($prenom && $nom) {

    // Si le nom contient déjà le prénom → on garde le nom seulement
    if (stripos($nom, $prenom) !== false) {
        $userNom = $nom;
    } 
    // Si prénom = nom → un seul
    elseif (strtolower($prenom) === strtolower($nom)) {
        $userNom = $prenom;
    } 
    else {
        $userNom = $prenom . ' ' . $nom;
    }

} elseif ($prenom) {
    $userNom = $prenom;
} elseif ($nom) {
    $userNom = $nom;
} elseif (!empty($_SESSION['user_name'])) {
    $userNom = $_SESSION['user_name'];
} else {
    $userNom = 'Utilisateur';
}

$userNom = htmlspecialchars($userNom, ENT_QUOTES, 'UTF-8');

// Évite "David David"
if ($prenom && $nom && strtolower($prenom) === strtolower($nom)) {
    $userNom = $prenom;
}
elseif ($prenom || $nom) {
    $userNom = trim("$prenom $nom");
}
elseif (!empty($_SESSION['user_name'])) {
    $userNom = $_SESSION['user_name'];
}
else {
    $userNom = 'Utilisateur';
}

$userNom = htmlspecialchars($userNom, ENT_QUOTES, 'UTF-8');

// Prénom seul
$firstName = $prenom ?: explode(' ', $userNom)[0];
$firstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

// Avatar
$avatarLetter = strtoupper(substr($firstName, 0, 1)) ?: 'U';

// ── AUTRES INFOS ──────────────────────────────────────
$userRole  = $user['role'] ?? 'lecteur';
$userEmail = htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8');

// ── STATUT (FIX BUG $sc) ──────────────────────────────
$statut = $user['statut'] ?? 'actif';

$statutConfig = [
    'actif'   => ['label' => 'Actif',   'color' => '#00ffaa'],
    'bloque'  => ['label' => 'Bloqué',  'color' => '#ff4d6d'],
    'inactif' => ['label' => 'Inactif', 'color' => '#888'],
];

$sc = $statutConfig[$statut] ?? $statutConfig['actif'];

// ── STATS ─────────────────────────────────────────────
$stats = [];

try {
    switch ($userRole) {
        case 'admin':
            $stats = function_exists('getAdminStats') ? getAdminStats() : [];
            break;

        case 'journaliste':
            $stats = function_exists('getJournalisteStats') ? getJournalisteStats($userId) : [];
            break;

        default:
            $stats = function_exists('getLecteurStats') ? getLecteurStats($userId) : [];
            break;
    }
} catch (Throwable $e) {
    error_log('[STATS ERROR] ' . $e->getMessage());
}

// ── HELPERS ───────────────────────────────────────────
function fmtDate(?string $d): string {
    if (!$d) return '—';
    try {
        return (new DateTime($d))->format('d M Y à H:i');
    } catch (Exception $e) {
        return '—';
    }
}

function fmtFCFA(float $n): string {
    return number_format($n, 0, ',', ' ') . ' FCFA';
}

// ── ROLE CONFIG ───────────────────────────────────────
$roleConfig = [
    'admin' => ['label' => 'Administrateur', 'icon' => '⚡'],
    'journaliste' => ['label' => 'Journaliste', 'icon' => '✍️'],
    'lecteur' => ['label' => 'Lecteur', 'icon' => '📖'],
];

$rc = $roleConfig[$userRole] ?? $roleConfig['lecteur'];

// ── CSRF ──────────────────────────────────────────────
$csrfToken = function_exists('csrfToken') ? csrfToken() : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profil — <?= $userNom ?> · Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
/* ══ RESET & VARS ══════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#05080f;
  --surface:#0b1020;
  --card:rgba(255,255,255,.032);
  --card-hov:rgba(255,255,255,.055);
  --border:rgba(255,255,255,.072);
  --border-act:rgba(0,212,255,.4);
  --cyan:#00d4ff;
  --violet:#7c3aed;
  --neon:#00ffaa;
  --amber:#f59e0b;
  --rose:#f43f5e;
  --text:#eef2ff;
  --text2:rgba(238,242,255,.55);
  --text3:rgba(238,242,255,.28);
  --role-c:<?= $rc['color'] ?>;
  --role-bg:<?= $rc['bg'] ?>;
  --r:14px;
  --r-lg:22px;
  --r-xl:32px;
  --shadow:0 8px 40px rgba(0,0,0,.45);
  --glow:0 0 32px rgba(0,212,255,.15);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}

/* ══ NOISE / GRID BACKGROUND ═══════════════════════════════ */
body::before{
  content:'';position:fixed;inset:0;
  background-image:
    radial-gradient(ellipse 80% 50% at 20% 10%,rgba(0,212,255,.07) 0%,transparent 60%),
    radial-gradient(ellipse 60% 40% at 80% 80%,rgba(124,58,237,.08) 0%,transparent 60%),
    linear-gradient(rgba(255,255,255,.012) 1px,transparent 1px),
    linear-gradient(90deg,rgba(255,255,255,.012) 1px,transparent 1px);
  background-size:100% 100%,100% 100%,32px 32px,32px 32px;
  pointer-events:none;z-index:0
}

/* ══ NAV ════════════════════════════════════════════════════ */
.top-nav{
  position:sticky;top:0;z-index:100;
  display:flex;align-items:center;justify-content:space-between;
  padding:.85rem 2rem;
  background:rgba(5,8,15,.82);
  backdrop-filter:blur(22px);
  border-bottom:1px solid var(--border);
  gap:1rem;
}
.nav-brand{
  font-family:'Syne',sans-serif;font-weight:800;font-size:.92rem;
  display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--text)
}
.brand-dot{
  width:32px;height:32px;border-radius:10px;
  background:linear-gradient(135deg,var(--cyan),var(--violet));
  display:flex;align-items:center;justify-content:center;font-size:.95rem;
  box-shadow:0 0 18px rgba(0,212,255,.3)
}
.nav-actions{display:flex;align-items:center;gap:8px}
.btn-back{
  display:inline-flex;align-items:center;gap:7px;
  padding:8px 16px;border-radius:var(--r);
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.1);
  color:var(--text2);font-size:.8rem;font-weight:600;
  font-family:'Syne',sans-serif;
  text-decoration:none;transition:all .2s ease;
  backdrop-filter:blur(8px)
}
.btn-back:hover{
  color:var(--cyan);
  border-color:rgba(0,212,255,.35);
  background:rgba(0,212,255,.07);
  box-shadow:0 0 18px rgba(0,212,255,.12);
  transform:translateX(-2px)
}
.btn-logout{
  display:inline-flex;align-items:center;gap:7px;
  padding:8px 16px;border-radius:var(--r);
  background:rgba(244,63,94,.08);
  border:1px solid rgba(244,63,94,.22);
  color:var(--rose);font-size:.8rem;font-weight:600;
  font-family:'Syne',sans-serif;
  text-decoration:none;transition:all .2s ease;cursor:pointer
}
.btn-logout:hover{
  background:rgba(244,63,94,.15);
  box-shadow:0 0 18px rgba(244,63,94,.18)
}

/* ══ MAIN LAYOUT ════════════════════════════════════════════ */
.page{position:relative;z-index:1;max-width:1100px;margin:0 auto;padding:2.5rem 1.5rem 5rem}

/* ══ HERO ═══════════════════════════════════════════════════ */
.hero{
  position:relative;overflow:hidden;
  border-radius:var(--r-xl);
  background:linear-gradient(135deg,rgba(0,212,255,.07),rgba(124,58,237,.1) 50%,rgba(0,255,170,.04));
  border:1px solid rgba(255,255,255,.08);
  padding:3rem 2.5rem 2.5rem;
  margin-bottom:2rem;
  display:flex;align-items:flex-end;justify-content:space-between;
  gap:2rem;flex-wrap:wrap;
  animation:fadeUp .5s ease both;
}
.hero::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon))
}
/* Glow orbs décoratifs */
.hero::after{
  content:'';position:absolute;
  width:320px;height:320px;
  background:radial-gradient(circle,rgba(124,58,237,.18) 0%,transparent 70%);
  top:-60px;right:-60px;border-radius:50%;pointer-events:none
}
.hero-orb2{
  position:absolute;
  width:200px;height:200px;
  background:radial-gradient(circle,rgba(0,212,255,.12) 0%,transparent 70%);
  bottom:-40px;left:100px;border-radius:50%;pointer-events:none
}
.hero-left{display:flex;align-items:center;gap:2rem;flex-wrap:wrap;position:relative;z-index:1}
.avatar-wrap{position:relative;flex-shrink:0}
.avatar{
  width:96px;height:96px;border-radius:28px;
  background:linear-gradient(135deg,var(--role-c),var(--violet));
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-weight:800;font-size:2.2rem;color:#fff;
  box-shadow:0 0 0 3px rgba(255,255,255,.08),0 0 40px rgba(0,212,255,.2);
  position:relative;
  animation:avatarGlow 4s ease-in-out infinite
}
@keyframes avatarGlow{
  0%,100%{box-shadow:0 0 0 3px rgba(255,255,255,.08),0 0 30px rgba(0,212,255,.15)}
  50%   {box-shadow:0 0 0 3px rgba(255,255,255,.12),0 0 50px rgba(0,212,255,.3)}
}
.avatar img{width:100%;height:100%;object-fit:cover;border-radius:28px}
.avatar-status{
  position:absolute;bottom:4px;right:4px;
  width:16px;height:16px;border-radius:50%;
  background:<?= $sc['color'] ?>;
  border:2px solid var(--bg);
  box-shadow:0 0 8px <?= $sc['color'] ?>
}
.hero-info{min-width:0}
.hero-label{
  font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.12em;
  text-transform:uppercase;color:var(--text3);margin-bottom:.4rem
}
.hero-name{
  font-family:'Syne',sans-serif;font-size:2.2rem;font-weight:800;
  line-height:1.1;margin-bottom:.5rem;
  background:linear-gradient(135deg,var(--text) 60%,var(--text2));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent
}
.hero-email{font-size:.85rem;color:var(--text2);margin-bottom:.9rem}
.badges{display:flex;gap:8px;flex-wrap:wrap}
.badge{
  display:inline-flex;align-items:center;gap:5px;
  font-family:'Space Mono',monospace;font-size:.62rem;letter-spacing:.04em;text-transform:uppercase;
  padding:4px 12px;border-radius:100px;font-weight:700
}
.badge-role{background:var(--role-bg);color:var(--role-c);border:1px solid rgba(255,255,255,.08)}
.badge-statut{
  background:rgba(0,255,170,.08);
  color:<?= $sc['color'] ?>;
  border:1px solid rgba(255,255,255,.06)
}
.hero-right{position:relative;z-index:1;display:flex;flex-direction:column;align-items:flex-end;gap:.7rem}
.hero-since{
  font-family:'Space Mono',monospace;font-size:.65rem;color:var(--text3);text-align:right
}
.hero-since strong{color:var(--text2);display:block;font-size:.72rem;margin-top:2px}
.hero-id{
  font-family:'Space Mono',monospace;font-size:.65rem;
  color:var(--text3);text-align:right
}
.hero-id span{color:var(--cyan)}

/* ══ SECTION TITLE ══════════════════════════════════════════ */
.section-title{
  font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem;
  display:flex;align-items:center;gap:9px;margin-bottom:1rem;
  color:var(--text2);text-transform:uppercase;letter-spacing:.06em
}
.st-icon{
  width:28px;height:28px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;font-size:.85rem
}

/* ══ STATS GRID ══════════════════════════════════════════════ */
.stats-row{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
  gap:1rem;margin-bottom:2rem
}
.stat-card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--r-lg);
  padding:1.4rem 1.3rem;
  position:relative;overflow:hidden;
  backdrop-filter:blur(8px);
  transition:transform .22s ease,border-color .22s,box-shadow .22s;
  animation:fadeUp .5s ease both
}
.stat-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--c1,var(--cyan)),var(--c2,var(--violet)));
  opacity:0;transition:opacity .3s
}
.stat-card:hover{transform:translateY(-5px);border-color:rgba(255,255,255,.1);box-shadow:var(--shadow)}
.stat-card:hover::before{opacity:1}
.stat-card:nth-child(1){--c1:var(--cyan);--c2:var(--violet);animation-delay:.05s}
.stat-card:nth-child(2){--c1:var(--violet);--c2:var(--rose);animation-delay:.1s}
.stat-card:nth-child(3){--c1:var(--neon);--c2:var(--cyan);animation-delay:.15s}
.stat-card:nth-child(4){--c1:var(--amber);--c2:var(--rose);animation-delay:.2s}
.s-icon{
  width:44px;height:44px;border-radius:13px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.2rem;margin-bottom:1rem;
  background:var(--card-hov)
}
.s-val{
  font-family:'Syne',sans-serif;font-size:1.9rem;font-weight:800;
  line-height:1;letter-spacing:-.5px;
  background:linear-gradient(135deg,var(--c1,#fff),var(--c2,#aaa));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent
}
.s-label{font-size:.72rem;color:var(--text2);margin-top:5px;font-weight:500}
.s-sub{font-size:.64rem;font-family:'Space Mono',monospace;color:var(--text3);margin-top:6px}

/* ══ GRID 2 COL ══════════════════════════════════════════════ */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:2rem}
@media(max-width:800px){.two-col{grid-template-columns:1fr}}

/* ══ CARD ════════════════════════════════════════════════════ */
.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--r-lg);
  overflow:hidden;
  backdrop-filter:blur(8px);
  animation:fadeUp .55s ease both
}
.card-head{
  padding:1rem 1.3rem;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between
}
.card-body{padding:1.2rem 1.3rem}

/* ══ INFOS TABLE ════════════════════════════════════════════ */
.info-row{
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 0;
  border-bottom:1px solid rgba(255,255,255,.04);
  gap:1rem
}
.info-row:last-child{border-bottom:none}
.ir-label{
  font-family:'Space Mono',monospace;font-size:.62rem;
  text-transform:uppercase;letter-spacing:.07em;color:var(--text3);
  flex-shrink:0
}
.ir-val{font-size:.8rem;color:var(--text2);font-weight:500;text-align:right;word-break:break-all}
.ir-val strong{color:var(--text)}

/* ══ ACTIVITY LIST ══════════════════════════════════════════ */
.act-item{
  display:flex;align-items:flex-start;gap:11px;
  padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04)
}
.act-item:last-child{border-bottom:none}
.act-dot{
  width:32px;height:32px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:.88rem;flex-shrink:0;
  background:var(--card-hov)
}
.act-msg{font-size:.79rem;color:var(--text2);line-height:1.5}
.act-msg strong{color:var(--text);font-weight:600}
.act-time{font-size:.62rem;font-family:'Space Mono',monospace;color:var(--text3);margin-top:2px}

/* ══ BOOKS GRID ══════════════════════════════════════════════ */
.books-mini{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem}
.bm-card{
  background:var(--card-hov);border:1px solid var(--border);
  border-radius:var(--r);overflow:hidden;
  transition:transform .2s,border-color .2s
}
.bm-card:hover{transform:translateY(-4px);border-color:rgba(0,212,255,.22)}
.bm-cover{
  height:80px;display:flex;align-items:center;justify-content:center;font-size:2rem;
  background:linear-gradient(135deg,rgba(13,16,32,.9),rgba(124,58,237,.2))
}
.bm-info{padding:8px 9px 10px}
.bm-title{font-family:'Syne',sans-serif;font-size:.75rem;font-weight:700;line-height:1.2;margin-bottom:3px}
.bm-price{font-family:'Space Mono',monospace;font-size:.6rem;color:var(--neon)}

/* ══ EMPTY STATE ════════════════════════════════════════════ */
.empty{
  text-align:center;padding:2rem 1rem;color:var(--text3)
}
.empty-icon{font-size:2.5rem;margin-bottom:.5rem}
.empty-msg{font-size:.8rem}

/* ══ PROGRESS ════════════════════════════════════════════════ */
.prog-row{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.prog-label{font-size:.72rem;color:var(--text2);flex-shrink:0;min-width:120px}
.prog-bar{flex:1;height:5px;background:rgba(255,255,255,.07);border-radius:100px;overflow:hidden}
.prog-fill{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--cyan),var(--violet));transition:width 1.2s ease}
.prog-val{font-family:'Space Mono',monospace;font-size:.62rem;color:var(--text3);flex-shrink:0}

/* ══ CHIP ════════════════════════════════════════════════════ */
.chip{
  display:inline-flex;align-items:center;gap:3px;
  font-size:.6rem;font-family:'Space Mono',monospace;
  padding:2px 8px;border-radius:100px;font-weight:700;text-transform:uppercase
}
.chip-ok{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.chip-ko{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.chip-warn{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}

/* ══ TOAST ═══════════════════════════════════════════════════ */
#toast-stack{position:fixed;bottom:1.4rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:7px;pointer-events:none}
.toast{display:flex;align-items:center;gap:9px;padding:10px 14px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);font-size:.78rem;max-width:290px;pointer-events:all;transform:translateX(110px);opacity:0;transition:all .35s cubic-bezier(.34,1.56,.64,1);box-shadow:var(--shadow)}
.toast.show{transform:translateX(0);opacity:1}

/* ══ ANIMATIONS ══════════════════════════════════════════════ */
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

/* ══ RESPONSIVE ══════════════════════════════════════════════ */
@media(max-width:680px){
  .hero{padding:2rem 1.4rem 1.8rem;flex-direction:column}
  .hero-right{align-items:flex-start}
  .hero-name{font-size:1.6rem}
  .avatar{width:72px;height:72px;font-size:1.6rem;border-radius:20px}
  .top-nav{padding:.75rem 1rem}
  .btn-back span,.btn-logout span{display:none}
  .stats-row{grid-template-columns:1fr 1fr}
  .page{padding:1.5rem 1rem 4rem}
}
@media(max-width:420px){
  .stats-row{grid-template-columns:1fr}
}

/* ================================
   PURCHASE ITEM — PREMIUM UI
================================ */

.purchase-item{
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 18px 22px;
    margin-bottom: 16px;

    border-radius: 20px;

    background:
        linear-gradient(
            145deg,
            rgba(255,255,255,0.06),
            rgba(255,255,255,0.02)
        );

    border: 1px solid rgba(255,255,255,0.08);

    backdrop-filter: blur(18px);

    overflow: hidden;

    transition:
        transform .35s ease,
        border-color .35s ease,
        box-shadow .35s ease,
        background .35s ease;
}

/* Glow effect */
.purchase-item::before{
    content: '';

    position: absolute;
    inset: 0;

    background:
        linear-gradient(
            120deg,
            transparent,
            rgba(0,212,255,0.08),
            transparent
        );

    opacity: 0;
    transition: opacity .4s ease;
}

/* Hover cinematic */
.purchase-item:hover{
    transform: translateY(-4px) scale(1.01);

    border-color: rgba(0,212,255,0.35);

    box-shadow:
        0 10px 40px rgba(0,0,0,0.45),
        0 0 18px rgba(0,212,255,0.12);

    background:
        linear-gradient(
            145deg,
            rgba(255,255,255,0.08),
            rgba(255,255,255,0.03)
        );
}

.purchase-item:hover::before{
    opacity: 1;
}

/* ================================
   PURCHASE INFO
================================ */

.purchase-info{
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* ================================
   TITLE
================================ */

.purchase-title{
    font-size: 1rem;
    font-weight: 700;
    color: #ffffff;

    line-height: 1.4;

    letter-spacing: .3px;

    transition: color .3s ease;
}

.purchase-item:hover .purchase-title{
    color: #00d4ff;
}

/* ================================
   DATE + PRICE
================================ */

.act-time{
    display: flex;
    align-items: center;
    gap: 8px;

    font-size: .82rem;
    font-weight: 500;

    color: rgba(255,255,255,0.65);

    font-family: 'Space Mono', monospace;
}

/* ================================
   READ BUTTON
================================ */

.purchase-item a{
    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 10px 18px;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            #00d4ff,
            #0066ff
        );

    color: #fff;
    text-decoration: none;

    font-size: .78rem;
    font-weight: 700;

    transition:
        transform .3s ease,
        box-shadow .3s ease,
        opacity .3s ease;
}

/* Hover button */
.purchase-item a:hover{
    transform: translateY(-2px);

    box-shadow:
        0 8px 24px rgba(0,212,255,0.35);

    opacity: .95;
}

/* ================================
   MOBILE
================================ */

@media (max-width: 768px){

    .purchase-item{
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .purchase-item a{
        width: 100%;
    }

    .purchase-title{
        font-size: .95rem;
    }

}

body{
    background:
        radial-gradient(circle at top left, #111827, #050816);

    min-height: 100vh;

    color: white;

    font-family: 'Inter', sans-serif;
}  


</style>
</head>
<body>

<!-- ══ NAV ══════════════════════════════════════════════════ -->
<nav class="top-nav">
  <a href="../dashboard.php" class="nav-brand">
    <div class="brand-dot">📚</div>
    Digital <span style="color:var(--cyan);margin-left:4px">Library</span>
  </a>
  <div class="nav-actions">
    <a href="../dashboard.php" class="btn-back">
      <i class="bi bi-arrow-left"></i>
      <span>Dashboard</span>
    </a>
    </a>
  </div>
</nav>

<!-- ══ PAGE ══════════════════════════════════════════════════ -->
<div class="page">

  <!-- ── HERO ─────────────────────────────────────────────── -->
  <div class="hero">
    <div class="hero-orb2"></div>
    <div class="hero-left">
      <div class="avatar-wrap">
        <div class="avatar">
          <?php if (!empty($user['avatar']) && file_exists(__DIR__ . '/../' . $user['avatar'])): ?>
            <img src="../<?= htmlspecialchars($user['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
          <?php else: ?>
            <?= $avatarLetter ?>
          <?php endif; ?>
        </div>
        <div class="avatar-status" title="<?= htmlspecialchars($sc['label'], ENT_QUOTES, 'UTF-8') ?>"></div>
      </div>
      <div class="hero-info">
        <div class="hero-label">Profil utilisateur</div>
        <div class="hero-name"><?= $userNom ?></div>
        <div class="hero-email">
          <i class="bi bi-envelope" style="opacity:.5;margin-right:5px"></i>
          <?= htmlspecialchars($user['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="badges">
          <span class="badge badge-role"><?= $rc['icon'] ?> <?= htmlspecialchars($rc['label'], ENT_QUOTES, 'UTF-8') ?></span>
          <span class="badge badge-statut">● <?= htmlspecialchars($sc['label'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>
    </div>
    <div class="hero-right">
      <div class="hero-since">
        Membre depuis<br>
        <strong><?= fmtDate($user['created_at'] ?? null, 'Date inconnue') ?></strong>
      </div>
      <?php if (!empty($user['last_login'])): ?>
      <div class="hero-since">
        Dernière connexion<br>
        <strong><?= fmtDate($user['last_login']) ?></strong>
      </div>
      <?php endif; ?>
      <div class="hero-id">ID utilisateur : <span>#<?= (int)$user['id'] ?></span></div>
    </div>
  </div>

  <!-- ── STATS ─────────────────────────────────────────────── -->
  <div class="section-title" style="animation:fadeUp .5s ease .1s both">
    <div class="st-icon" style="background:rgba(0,212,255,.1)">📊</div>
    Mes statistiques
  </div>

  <div class="stats-row">
    <?php if ($userRole === 'admin'): ?>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(0,212,255,.1)">👥</div>
        <div class="s-val"><?= number_format((int)($stats['totalUsers'] ?? 0)) ?></div>
        <div class="s-label">Utilisateurs inscrits</div>
        <div class="s-sub">+<?= (int)($stats['newUsersMonth'] ?? 0) ?> ce mois</div>
      </div>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(124,58,237,.1)">📚</div>
        <div class="s-val"><?= number_format((int)($stats['totalBooks'] ?? 0)) ?></div>
        <div class="s-label">Livres dans le catalogue</div>
        <div class="s-sub">+<?= (int)($stats['newBooksWeek'] ?? 0) ?> cette semaine</div>
      </div>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(0,255,170,.08)">💰</div>
        <div class="s-val"><?= (int)($stats['totalSales'] ?? 0) ?></div>
        <div class="s-label">Ventes confirmées</div>
        <?php $sv = (float)($stats['salesVariation'] ?? 0); ?>
        <div class="s-sub" style="color:<?= $sv >= 0 ? 'var(--neon)' : 'var(--rose)' ?>">
          <?= $sv >= 0 ? '↑' : '↓' ?> <?= abs($sv) ?>% vs mois dernier
        </div>
      </div>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(244,63,94,.08)">💳</div>
        <div class="s-val" style="font-size:1.1rem"><?= htmlspecialchars($stats['revenueMonth'] ?? '0 FCFA', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="s-label">Chiffre d'affaires du mois</div>
        <?php $rv = (float)($stats['revVariation'] ?? 0); ?>
        <div class="s-sub" style="color:<?= $rv >= 0 ? 'var(--neon)' : 'var(--rose)' ?>">
          <?= $rv >= 0 ? '↑' : '↓' ?> <?= abs($rv) ?>%
        </div>
      </div>

    <?php elseif ($userRole === 'journaliste'): ?>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(245,158,11,.1)">📝</div>
        <div class="s-val"><?= (int)($stats['total'] ?? 0) ?></div>
        <div class="s-label">Livres publiés</div>
        <div class="s-sub">Catalogue total</div>
      </div>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(0,255,170,.08)">✅</div>
        <div class="s-val"><?= (int)($stats['published'] ?? 0) ?></div>
        <div class="s-label">Disponibles en ligne</div>
        <div class="s-sub">Visibles aux lecteurs</div>
      </div>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(0,212,255,.1)">🛍️</div>
        <div class="s-val"><?= (int)($stats['views'] ?? 0) ?></div>
        <div class="s-label">Ventes générées</div>
        <div class="s-sub">Total cumulé</div>
      </div>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(124,58,237,.1)">⭐</div>
        <?php
          $notes = array_column($stats['livres'] ?? [], 'note_moyenne');
          $avgNote = !empty($notes) ? round(array_sum($notes) / count($notes), 1) : 0;
        ?>
        <div class="s-val"><?= number_format($avgNote, 1) ?></div>
        <div class="s-label">Note moyenne / 5</div>
        <div class="s-sub">Sur l'ensemble des livres</div>
      </div>

    <?php else: /* lecteur */ ?>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(0,255,170,.08)">🛍️</div>
        <div class="s-val"><?= (int)($stats['booksPurchased'] ?? 0) ?></div>
        <div class="s-label">Livres achetés</div>
        <div class="s-sub">Ma bibliothèque</div>
      </div>
      <div class="s-val" style="font-size:1.05rem">
    <?= number_format((float)($stats['totalSpent'] ?? 0), 0, ',', ' ') ?> FCFA
</div>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(245,158,11,.1)">🎁</div>
        <div class="s-val"><?= (int)($stats['bonusCount'] ?? 0) ?></div>
        <div class="s-label">Bonus disponibles</div>
        <div class="s-sub"><?= (int)($stats['bonusCount'] ?? 0) > 0 ? '🎉 À utiliser !' : 'Aucun bonus actif' ?></div>
      </div>
      <div class="stat-card">
        <div class="s-icon" style="background:rgba(124,58,237,.1)">📚</div>
        <div class="s-val"><?= count($stats['catalogue'] ?? []) ?></div>
        <div class="s-label">Livres disponibles</div>
        <div class="s-sub">Dans le catalogue</div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── GRILLE INFOS + ACTIVITE ───────────────────────────── -->
  <div class="two-col" style="animation-delay:.15s">

    <!-- Informations personnelles -->
    <div class="card" style="animation-delay:.15s">
      <div class="card-head">
        <div class="section-title" style="margin:0">
          <div class="st-icon" style="background:rgba(0,212,255,.08)">👤</div>
          Informations
        </div>
      </div>
      <div class="card-body">
        <div class="info-row">
          <span class="ir-label">Prénom</span>
          <span class="ir-val"><strong><?= htmlspecialchars($user['prenom'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></span>
        </div>
        <div class="info-row">
          <span class="ir-label">Nom</span>
          <span class="ir-val"><strong><?= htmlspecialchars($user['nom'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></span>
        </div>
        <div class="info-row">
          <span class="ir-label">Email</span>
          <span class="ir-val"><?= htmlspecialchars($user['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="ir-label">Téléphone</span>
          <span class="ir-val"><?= htmlspecialchars($user['telephone'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="info-row">
          <span class="ir-label">Rôle</span>
          <span class="ir-val">
            <span class="badge badge-role"><?= $rc['icon'] ?> <?= htmlspecialchars($rc['label'], ENT_QUOTES, 'UTF-8') ?></span>
          </span>
        </div>
        <div class="info-row">
          <span class="ir-label">Statut</span>
          <span class="ir-val">
            <?php
              $chipClass = match($user['statut'] ?? '') {
                'actif'   => 'chip-ok',
                'bloque'  => 'chip-ko',
                default   => 'chip-warn',
              };
            ?>
            <span class="chip <?= $chipClass ?>"><?= htmlspecialchars($sc['label'], ENT_QUOTES, 'UTF-8') ?></span>
          </span>
        </div>
        <div class="info-row">
          <span class="ir-label">Inscription</span>
          <span class="ir-val"><?= fmtDate($user['created_at'] ?? null) ?></span>
        </div>
        <div class="info-row">
          <span class="ir-label">Dernière connexion</span>
          <span class="ir-val"><?= fmtDate($user['last_login'] ?? null) ?></span>
        </div>
      </div>
    </div>

    <!-- Activité récente / Contenu selon rôle -->
    <div class="card" style="animation-delay:.2s">
      <div class="card-head">
        <div class="section-title" style="margin:0">
          <?php if ($userRole === 'admin'): ?>
            <div class="st-icon" style="background:rgba(244,63,94,.08)">⚡</div> Activité récente
          <?php elseif ($userRole === 'journaliste'): ?>
            <div class="st-icon" style="background:rgba(245,158,11,.08)">📄</div> Mes publications
          <?php else: ?>
            <div class="st-icon" style="background:rgba(0,255,170,.06)">📚</div> Mes achats récents
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">

        <?php if ($userRole === 'admin'): ?>
          <!-- Activité admin -->
          <?php
            $activity = [];
            try { $activity = getRecentActivity(6); } catch (Throwable $e) {}
          ?>
          <?php if (empty($activity)): ?>
            <div class="empty"><div class="empty-icon">📭</div><div class="empty-msg">Aucune activité récente</div></div>
          <?php else: foreach ($activity as $a): ?>
            <div class="act-item">
              <div class="act-dot" style="color:<?= htmlspecialchars($a['color'] ?? '#fff', ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($a['icon'] ?? '•', ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div>
                <div class="act-msg"><?= htmlspecialchars($a['msg'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="act-time"><?= htmlspecialchars($a['time_ago'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>

        <?php elseif ($userRole === 'journaliste'): ?>
          <!-- Livres journaliste -->
          <?php $livres = $stats['livres'] ?? []; ?>
          <?php if (empty($livres)): ?>
            <div class="empty"><div class="empty-icon">📭</div><div class="empty-msg">Aucun livre publié</div></div>
          <?php else: foreach (array_slice($livres, 0, 6) as $l):
            $ls = $l['statut'] ?? '';
            $lchip = $ls === 'disponible' ? 'chip-ok' : ($ls === 'archive' ? '' : 'chip-warn');
          ?>
            <div class="act-item">
              <div class="act-dot">📄</div>
              <div style="flex:1">
                <div class="act-msg">
                  <strong><?= htmlspecialchars($l['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="act-time" style="display:flex;gap:6px;align-items:center;margin-top:3px">
                  <span><?= (int)($l['achats_count'] ?? 0) ?> ventes</span>
                  <span>·</span>
                  <span class="chip <?= $lchip ?>"><?= htmlspecialchars(ucfirst($ls), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>
              <span style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--amber)">
                <?= number_format((float)($l['note_moyenne'] ?? 0), 1) ?>⭐
              </span>
            </div>
          <?php endforeach; endif; ?>

        <?php else: /* lecteur */ ?>
          <!-- Historique achats lecteur -->
          <?php $history = $stats['history'] ?? []; ?>
          <?php $rEmojis = ['📖','🌌','🧠','🌿','⚙️','📜']; ?>
          <?php if (empty($history)): ?>
            <div class="empty">
              <div class="empty-icon">📖</div>
              <div class="empty-msg">Aucun achat pour le moment</div>
              <a href="..users/purchases.php" style="display:inline-block;margin-top:.8rem;font-size:.75rem;color:var(--cyan)">
                Explorer mes achats →
              </a>
            </div>
          <?php else: foreach (array_slice($history, 0, 6) as $i => $r): ?>
            <div class="act-item">
              <div class="act-dot"><?= $rEmojis[$i % count($rEmojis)] ?></div>
              <div style="flex:1">
                <div class="act-msg">
                  <strong><?= htmlspecialchars($r['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="act-time">
    <?= !empty($r['purchase_date'])
        ? date('d/m/Y', strtotime($r['purchase_date']))
        : '—'
    ?>

    · <?= fmtFCFA2((float)($r['paid_amount'] ?? 0)) ?>
</div>
<div class="purchase-item">

<div class="purchase-info">
    <div class="purchase-title">
        <?= htmlspecialchars($r['titre'] ?? 'Livre inconnu') ?>
    </div>

    <div class="act-time">
        <?= !empty($r['purchase_date'])
            ? date('d/m/Y', strtotime($r['purchase_date']))
            : '—'
        ?>

        · <?= fmtFCFA2((float)($r['paid_amount'] ?? 0)) ?>
    </div>
</div>



</a>

</div>
          <?php endforeach; endif; ?>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- ── SECTION BAS : CATALOGUE / LIVRES / PERFORMANCES ──── -->
  <?php if ($userRole === 'lecteur' && !empty($stats['catalogue'])): ?>
  <div class="card" style="animation:fadeUp .55s ease .25s both">
    <div class="card-head">
      <div class="section-title" style="margin:0">
        <div class="st-icon" style="background:rgba(0,212,255,.08)">🌟</div>
        Catalogue disponible
      </div>
      <a href="../books/index.php"
         style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--cyan);text-decoration:none">
        Voir tout →
      </a>
    </div>
    <div class="card-body">
      <?php $catEmojis = ['📖','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌊','⚡','🎯']; ?>
      <div class="books-mini">
        <?php foreach (array_slice($stats['catalogue'], 0, 8) as $i => $b): ?>
        <div class="bm-card">
          <div class="bm-cover"><?= $catEmojis[$i % count($catEmojis)] ?></div>
          <div class="bm-info">
            <div class="bm-title"><?= htmlspecialchars($b['titre'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="bm-price"><?= (float)($b['prix'] ?? 0) > 0 ? fmtFCFA2((float)$b['prix']) : 'Gratuit' ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php elseif ($userRole === 'journaliste' && !empty($stats['livres'])): ?>
  <!-- Performances journaliste -->
  <div class="card" style="animation:fadeUp .55s ease .25s both">
    <div class="card-head">
      <div class="section-title" style="margin:0">
        <div class="st-icon" style="background:rgba(245,158,11,.08)">📊</div>
        Performance par livre
      </div>
    </div>
    <div class="card-body">
      <?php
        $livresChart = $stats['livres'] ?? [];
        $maxVentes   = max(array_column($livresChart, 'achats_count') ?: [1]);
        $maxVentes   = $maxVentes > 0 ? $maxVentes : 1;
      ?>
      <?php foreach (array_slice($livresChart, 0, 8) as $l): ?>
      <div class="prog-row">
        <div class="prog-label" style="font-size:.7rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= htmlspecialchars(mb_substr($l['titre'] ?? '—', 0, 22), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="prog-bar">
          <div class="prog-fill" style="width:<?= min(100, round(((int)($l['achats_count'] ?? 0) / $maxVentes) * 100)) ?>%"></div>
        </div>
        <div class="prog-val"><?= (int)($l['achats_count'] ?? 0) ?> ventes</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php elseif ($userRole === 'admin'): ?>
  <!-- Résumé admin -->
  <div class="card" style="animation:fadeUp .55s ease .25s both">
    <div class="card-head">
      <div class="section-title" style="margin:0">
        <div class="st-icon" style="background:rgba(244,63,94,.08)">🔐</div>
        Accès administrateur
      </div>
    </div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.9rem">
        <?php
          $adminLinks = [
            ['icon'=>'👥','label'=>'Gérer les utilisateurs','url'=>'../users/index.php','c'=>'rgba(0,212,255,.08)'],
            ['icon'=>'📚','label'=>'Gérer les livres',      'url'=>'../books/index.php','c'=>'rgba(124,58,237,.08)'],
            ['icon'=>'💰','label'=>'Voir les ventes',       'url'=>'../admin/sales.php','c'=>'rgba(0,255,170,.06)'],
            ['icon'=>'📊','label'=>'Statistiques',          'url'=>'../stats/index.php','c'=>'rgba(245,158,11,.08)'],
            ['icon'=>'⚙️','label'=>'Paramètres',           'url'=>'../admin/settings.php','c'=>'rgba(244,63,94,.07)'],
            ['icon'=>'🔔','label'=>'Notifications',         'url'=>'../admin/notifications.php','c'=>'rgba(0,212,255,.06)'],
          ];
          foreach ($adminLinks as $al):
        ?>
        <a href="<?= htmlspecialchars($al['url'], ENT_QUOTES, 'UTF-8') ?>"
           style="display:flex;align-items:center;gap:10px;padding:12px;border-radius:var(--r);
                  background:<?= $al['c'] ?>;border:1px solid var(--border);
                  text-decoration:none;color:var(--text2);font-size:.8rem;font-weight:500;
                  transition:all .18s ease"
           onmouseover="this.style.color='var(--text)';this.style.borderColor='rgba(255,255,255,.12)'"
           onmouseout="this.style.color='var(--text2)';this.style.borderColor='var(--border)'">
          <span style="font-size:1.1rem"><?= $al['icon'] ?></span>
          <?= htmlspecialchars($al['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /page -->

<div id="toast-stack"></div>

<script>
// Toast
function showToast(title, type='info') {
  const icons = {info:'ℹ️',success:'✅',error:'🔴'};
  const colors = {info:'var(--cyan)',success:'var(--neon)',error:'var(--rose)'};
  const stack = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = colors[type] || colors.info;
  t.innerHTML = `<span>${icons[type]||'ℹ️'}</span><span style="font-family:'Syne',sans-serif;font-weight:700">${title}</span>`;
  stack.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, 3500);
}

// Animation progress bars au chargement
document.addEventListener('DOMContentLoaded', () => {
  // Reset et re-anime les barres
  document.querySelectorAll('.prog-fill').forEach(el => {
    const w = el.style.width;
    el.style.width = '0%';
    setTimeout(() => { el.style.width = w; }, 200);
  });

  // Message de bienvenue
  const params = new URLSearchParams(window.location.search);
  if (params.get('updated') === '1') showToast('Profil mis à jour !', 'success');
});
</script>
</body>
</html>