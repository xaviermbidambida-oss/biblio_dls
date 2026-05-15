<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — users/create.php                  ║
 * ║  Création d'un utilisateur (admin uniquement)               ║
 * ║  PHP 8 · PDO · AJAX · Dark SaaS UI · 100% fonctionnel       ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Session & Auth ────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// Charger la config
foreach ([
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
] as $_cfgPath) {
    if (file_exists($_cfgPath) && !defined('DB_HOST_LOADED')) {
        require_once $_cfgPath;
        define('DB_HOST_LOADED', true);
        break;
    }
}

// Connexion PDO
if (!isset($pdo) || $pdo === null) {
    $_h = defined('DB_HOST') ? DB_HOST : 'localhost';
    $_n = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $_u = defined('DB_USER') ? DB_USER : 'root';
    $_p = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$_h};dbname={$_n};charset=utf8mb4",
            $_u, $_p,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        error_log('[DLS] PDO connection failed: ' . $e->getMessage());
        $pdo = null;
    }
}

// Mode DEMO : auto-login admin si pas de session
if (!isset($_SESSION['user_id']) && $pdo) {
    try {
        $demo = $pdo->query("SELECT * FROM users WHERE role='admin' AND statut='actif' LIMIT 1")->fetch();
        if ($demo) {
            $_SESSION['user_id']   = $demo['id'];
            $_SESSION['user_role'] = $demo['role'];
            $_SESSION['user_name'] = trim($demo['prenom'] . ' ' . $demo['nom']);
        }
    } catch (Exception $e) {}
}

// Guard : seuls les admins peuvent créer des utilisateurs
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); exit;
}
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php?error=access_denied'); exit;
}

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Attribution automatique du rôle selon l'email ────────────
/**
 * Détermine le rôle d'un utilisateur à partir de son adresse e-mail.
 *
 * Règles (priorité décroissante) :
 *  1. admin.[a-z0-9._-]+@adminsopecam.com  → 'admin'
 *  2. journaliste.[a-z0-9._-]+@sopecam.com → 'journaliste'
 *  3. Tout autre email valide              → 'lecteur'
 *
 * Le paramètre $email doit être déjà normalisé (trim + strtolower).
 */
function resolveRoleFromEmail(string $email): string
{
    // Pattern Admin : admin.<slug>@adminsopecam.com
    // Le slug contient au moins 1 caractère : lettres, chiffres, point, tiret, underscore
    if (preg_match('/^admin\.[a-z0-9][a-z0-9._-]*@adminsopecam\.com$/', $email)) {
        return 'admin';
    }

    // Pattern Journaliste : journaliste.<slug>@sopecam.com
    if (preg_match('/^journaliste\.[a-z0-9][a-z0-9._-]*@sopecam\.com$/', $email)) {
        return 'journaliste';
    }

    // Par défaut : lecteur
    return 'lecteur';
}

// ── Traitement AJAX POST ──────────────────────────────────────
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    $response = ['success' => false, 'message' => '', 'errors' => []];

    // Validation CSRF
    $receivedCsrf = trim($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $receivedCsrf)) {
        $response['message'] = 'Token de sécurité invalide. Rechargez la page.';
        echo json_encode($response); exit;
    }

    if (!$pdo) {
        $response['message'] = 'Base de données inaccessible.';
        echo json_encode($response); exit;
    }

    // ── Collecte & nettoyage des inputs ──────────────────────
    $nom       = trim(htmlspecialchars($_POST['nom']       ?? '', ENT_QUOTES, 'UTF-8'));
    $prenom    = trim(htmlspecialchars($_POST['prenom']    ?? '', ENT_QUOTES, 'UTF-8'));
    $email     = trim(strtolower($_POST['email']           ?? ''));
    $telephone = trim(htmlspecialchars($_POST['telephone'] ?? '', ENT_QUOTES, 'UTF-8'));
    $password  = $_POST['password'] ?? '';
    $statut    = $_POST['statut']   ?? '';

    // ── Attribution du rôle côté serveur (JAMAIS depuis le frontend) ──
    // Le champ "role" soumis par le formulaire est ignoré.
    // Le rôle est déterminé UNIQUEMENT par l'analyse de l'email.
    $role = resolveRoleFromEmail($email);

    // ── Validation serveur ────────────────────────────────────
    $errors = [];

    if (mb_strlen($nom) < 2)
        $errors['nom'] = 'Le nom doit contenir au moins 2 caractères.';
    if (mb_strlen($nom) > 150)
        $errors['nom'] = 'Le nom ne peut pas dépasser 150 caractères.';

    if (mb_strlen($prenom) > 150)
        $errors['prenom'] = 'Le prénom ne peut pas dépasser 150 caractères.';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'Adresse e-mail invalide.';
    if (mb_strlen($email) > 255)
        $errors['email'] = 'E-mail trop long (max 255 caractères).';

    if (!empty($telephone) && !preg_match('/^[+\d\s\-()]{6,20}$/', $telephone))
        $errors['telephone'] = 'Numéro de téléphone invalide.';

    if (mb_strlen($password) < 8)
        $errors['password'] = 'Le mot de passe doit contenir au moins 8 caractères.';

    // Note : $role est toujours valide car issu de resolveRoleFromEmail()
    $validStatuts = ['actif', 'inactif', 'bloque'];
    if (!in_array($statut, $validStatuts, true))
        $errors['statut'] = 'Statut invalide.';

    if (!empty($errors)) {
        $response['errors']  = $errors;
        $response['message'] = 'Veuillez corriger les erreurs ci-dessous.';
        echo json_encode($response); exit;
    }

    // ── Vérifier doublon email ────────────────────────────────
    try {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $response['errors']['email'] = 'Cette adresse e-mail est déjà utilisée.';
            $response['message']         = 'Cet e-mail existe déjà dans la base.';
            echo json_encode($response); exit;
        }
    } catch (PDOException $e) {
        error_log('[DLS] Email check failed: ' . $e->getMessage());
        $response['message'] = 'Erreur lors de la vérification de l\'e-mail.';
        echo json_encode($response); exit;
    }

    // ── Insertion ─────────────────────────────────────────────
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO users (nom, prenom, email, password, telephone, role, statut, created_at)
             VALUES (:nom, :prenom, :email, :password, :telephone, :role, :statut, NOW())"
        );
        $stmt->execute([
            ':nom'       => $nom,
            ':prenom'    => $prenom,
            ':email'     => $email,
            ':password'  => $hashedPassword,
            ':telephone' => $telephone ?: null,
            ':role'      => $role,
            ':statut'    => $statut,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // Log admin
        try {
            $logStmt = $pdo->prepare(
                "INSERT INTO admin_logs (user_id, action, detail, ip, created_at)
                 VALUES (:uid, 'user_created', :detail, :ip, NOW())"
            );
            $logStmt->execute([
                ':uid'    => (int)$_SESSION['user_id'],
                ':detail' => "Nouvel utilisateur créé : {$prenom} {$nom} <{$email}> (role={$role}, statut={$statut})",
                ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);
        } catch (Exception $e) {
            // Le log est non-bloquant
            error_log('[DLS] Admin log failed: ' . $e->getMessage());
        }

        $response['success']       = true;
        $response['message']       = "Utilisateur <strong>{$prenom} {$nom}</strong> créé avec succès (ID #{$newId}).";
        $response['user_id']       = $newId;
        $response['resolved_role'] = $role; // rôle effectivement enregistré

    } catch (PDOException $e) {
        error_log('[DLS] Insert user failed: ' . $e->getMessage());
        if ($e->getCode() === '23000') {
            $response['errors']['email'] = 'Cette adresse e-mail est déjà utilisée.';
            $response['message']         = 'Doublon détecté en base de données.';
        } else {
            $response['message'] = 'Erreur lors de la création de l\'utilisateur.';
        }
    }

    echo json_encode($response); exit;
}

// ── Données pour la vue ───────────────────────────────────────
$adminName   = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$adminAvatar = strtoupper(substr($adminName, 0, 1)) ?: 'A';
$dbConnected = ($pdo !== null);
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Créer un utilisateur — Digital Library</title>
<meta name="robots" content="noindex,nofollow">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!-- TailwindCSS CDN -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        syne: ['Syne', 'sans-serif'],
        mono: ['Space Mono', 'monospace'],
        sans: ['DM Sans', 'sans-serif'],
      },
      colors: {
        base:    '#05080f',
        surface: '#0b1020',
        cyan:    '#00d4ff',
        violet:  '#7c3aed',
        neon:    '#00ffaa',
        amber:   '#f59e0b',
        rose:    '#f43f5e',
      },
      boxShadow: {
        glow:   '0 0 28px rgba(0,212,255,.22)',
        'glow-v':'0 0 28px rgba(124,58,237,.22)',
        card:   '0 4px 24px rgba(0,0,0,.38)',
        lg:     '0 24px 64px rgba(0,0,0,.52)',
      },
    }
  }
}
</script>

<style>
/* ── Root variables (cohérence avec dashboard) ── */
:root {
  --bg-base:    #05080f;
  --bg-surface: #0b1020;
  --bg-card:    rgba(255,255,255,.032);
  --bg-hover:   rgba(255,255,255,.058);
  --border:     rgba(255,255,255,.072);
  --border-act: rgba(0,212,255,.38);
  --cyan:       #00d4ff;
  --violet:     #7c3aed;
  --neon:       #00ffaa;
  --amber:      #f59e0b;
  --rose:       #f43f5e;
  --text-1:     #eef2ff;
  --text-2:     rgba(238,242,255,.58);
  --text-muted: rgba(238,242,255,.3);
  --r-sm:  8px;
  --r-md:  13px;
  --r-lg:  18px;
  --r-xl:  26px;
  --glow:  0 0 28px rgba(0,212,255,.18);
}

*,*::before,*::after { box-sizing: border-box; margin:0; padding:0; }
html { scroll-behavior: smooth; }
body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg-base);
  color: var(--text-1);
  min-height: 100vh;
  overflow-x: hidden;
}

/* Scrollbar */
::-webkit-scrollbar { width:3px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius:4px; }

/* ── Background mesh ── */
.bg-mesh {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background:
    radial-gradient(ellipse 60% 50% at 15% 20%, rgba(0,212,255,.045) 0%, transparent 60%),
    radial-gradient(ellipse 40% 60% at 85% 75%, rgba(124,58,237,.055) 0%, transparent 60%),
    radial-gradient(ellipse 30% 40% at 50% 10%, rgba(0,255,170,.025) 0%, transparent 60%);
}

/* ── Topbar ── */
.topbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  height: 62px;
  background: rgba(5,8,15,.88);
  backdrop-filter: blur(22px);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 1rem; padding: 0 1.6rem;
}
.brand-icon {
  width: 36px; height: 36px; border-radius: 11px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--cyan), var(--violet));
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; box-shadow: var(--glow);
}
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: .78rem; color: var(--text-2); }
.breadcrumb a { color: var(--text-2); text-decoration: none; transition: color .15s; }
.breadcrumb a:hover { color: var(--cyan); }
.breadcrumb .sep { opacity: .3; }
.breadcrumb .cur { font-family: 'Syne', sans-serif; font-weight: 700; color: var(--text-1); }

.avatar-pill {
  display: flex; align-items: center; gap: 7px;
  background: var(--bg-card); border: 1px solid var(--border);
  padding: 4px 10px 4px 4px; border-radius: 100px;
  font-size: .75rem; font-weight: 600;
}
.avatar-circle {
  width: 26px; height: 26px; border-radius: 50%;
  background: linear-gradient(135deg, var(--rose), var(--violet));
  display: flex; align-items: center; justify-content: center;
  font-family: 'Syne', sans-serif; font-weight: 800; font-size: .7rem; color: #fff;
}

/* ── Main Layout ── */
.page-wrapper {
  position: relative; z-index: 1;
  padding: 88px 1.5rem 3rem;
  min-height: 100vh;
  display: flex; flex-direction: column; align-items: center;
}

/* ── Page Header ── */
.page-header {
  width: 100%; max-width: 780px;
  display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
  margin-bottom: 1.8rem;
  animation: slideDown .45s cubic-bezier(.22,1,.36,1) both;
}
.page-title {
  font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.6rem;
  letter-spacing: -.5px; line-height: 1;
  background: linear-gradient(135deg, var(--text-1), var(--text-2));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.page-sub { font-size: .8rem; color: var(--text-muted); margin-top: 5px; }

/* ── DB Status pill ── */
.db-pill {
  display: inline-flex; align-items: center; gap: 5px;
  font-family: 'Space Mono', monospace; font-size: .6rem; letter-spacing: .06em;
  padding: 4px 10px; border-radius: 100px; text-transform: uppercase; flex-shrink: 0;
}
.db-pill.ok  { background: rgba(0,255,170,.08); color: var(--neon);  border: 1px solid rgba(0,255,170,.2); }
.db-pill.err { background: rgba(244,63,94,.08); color: var(--rose);  border: 1px solid rgba(244,63,94,.2); }
.db-dot { width: 6px; height: 6px; border-radius: 50%; }
.db-dot.ok  { background: var(--neon); box-shadow: 0 0 6px var(--neon); animation: pulse 2s ease-in-out infinite; }
.db-dot.err { background: var(--rose); }
@keyframes pulse { 0%,100%{ opacity:1 } 50%{ opacity:.4 } }

/* ── Card ── */
.form-card {
  width: 100%; max-width: 780px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--r-xl);
  overflow: hidden;
  box-shadow: var(--shadow-card);
  backdrop-filter: blur(12px);
  animation: slideUp .5s cubic-bezier(.22,1,.36,1) .05s both;
  position: relative;
}
.form-card::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, var(--cyan), var(--violet), var(--neon));
}

.card-header {
  padding: 1.4rem 1.8rem;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 12px;
}
.header-icon {
  width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
  background: linear-gradient(135deg, rgba(0,212,255,.15), rgba(124,58,237,.15));
  border: 1px solid rgba(0,212,255,.15);
  display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
}
.card-title-text {
  font-family: 'Syne', sans-serif; font-weight: 700; font-size: 1rem;
}
.card-subtitle { font-size: .73rem; color: var(--text-muted); margin-top: 2px; }

.card-body { padding: 1.8rem; }

/* ── Section dividers ── */
.section-label {
  font-family: 'Space Mono', monospace; font-size: .6rem; letter-spacing: .14em;
  text-transform: uppercase; color: var(--text-muted);
  display: flex; align-items: center; gap: 10px; margin-bottom: 1.2rem; margin-top: .4rem;
}
.section-label::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}

/* ── Grid ── */
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
@media (max-width: 640px) {
  .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }
  .page-header { flex-direction: column; gap: .6rem; }
}

/* ── Field ── */
.field { display: flex; flex-direction: column; gap: 5px; }
.field-label {
  font-family: 'Space Mono', monospace; font-size: .62rem; letter-spacing: .07em;
  text-transform: uppercase; color: var(--text-2);
  display: flex; align-items: center; gap: 5px;
}
.req { color: var(--rose); font-size: .55rem; }

.field-wrap { position: relative; }
.field-icon {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  color: var(--text-muted); font-size: .85rem; pointer-events: none; transition: color .2s;
}
.field-wrap:focus-within .field-icon { color: var(--cyan); }

.field-input, .field-select {
  width: 100%;
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: 10px 12px 10px 36px;
  color: var(--text-1);
  font-size: .83rem;
  font-family: 'DM Sans', sans-serif;
  outline: none;
  transition: border-color .2s, box-shadow .2s, background .2s;
  appearance: none; -webkit-appearance: none;
}
.field-select { cursor: pointer; }
.field-input::placeholder { color: var(--text-muted); }

.field-input:hover, .field-select:hover {
  border-color: rgba(255,255,255,.12);
  background: rgba(255,255,255,.06);
}
.field-input:focus, .field-select:focus {
  border-color: var(--border-act);
  box-shadow: 0 0 0 3px rgba(0,212,255,.08), var(--glow);
  background: rgba(0,212,255,.03);
}
.field-input.error, .field-select.error {
  border-color: rgba(244,63,94,.5);
  box-shadow: 0 0 0 3px rgba(244,63,94,.06);
}
.field-input.success {
  border-color: rgba(0,255,170,.4);
}

/* Select arrow custom */
.select-wrap { position: relative; }
.select-wrap::after {
  content: '\F282'; font-family: 'bootstrap-icons';
  position: absolute; right: 11px; top: 50%; transform: translateY(-50%);
  color: var(--text-muted); font-size: .8rem; pointer-events: none;
}

/* Password wrapper */
.pwd-toggle {
  position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
  background: none; border: none; color: var(--text-muted); cursor: pointer;
  font-size: .85rem; padding: 2px 4px; border-radius: 4px;
  transition: color .15s;
}
.pwd-toggle:hover { color: var(--cyan); }

/* Strength bar */
.strength-bar {
  height: 3px; border-radius: 100px; margin-top: 5px;
  background: rgba(255,255,255,.07); overflow: hidden; transition: all .3s;
}
.strength-fill {
  height: 100%; border-radius: 100px;
  transition: width .4s ease, background .4s ease;
  width: 0%;
}

/* Error / hint text */
.field-error { font-size: .67rem; color: var(--rose); display: flex; align-items: center; gap: 4px; }
.field-hint  { font-size: .67rem; color: var(--text-muted); }
.field-error i { font-size: .7rem; }

/* ── Role / Status cards ── */
.select-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: .6rem; }
.select-cards-2 { display: grid; grid-template-columns: repeat(3, 1fr); gap: .6rem; }
@media (max-width: 400px) { .select-cards, .select-cards-2 { grid-template-columns: 1fr; } }

.sel-card {
  position: relative; cursor: pointer;
  background: rgba(255,255,255,.03);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: .85rem .8rem .75rem;
  transition: all .2s; user-select: none;
  text-align: center;
}
.sel-card input[type="radio"] {
  position: absolute; opacity: 0; width: 0; height: 0;
}
.sel-card:hover { border-color: rgba(255,255,255,.12); background: var(--bg-hover); }
.sel-card.checked {
  border-color: var(--current-c, var(--cyan));
  background: var(--current-bg, rgba(0,212,255,.06));
  box-shadow: 0 0 0 1px var(--current-c, var(--cyan));
}
.sc-icon { font-size: 1.3rem; margin-bottom: 5px; }
.sc-name { font-family: 'Syne', sans-serif; font-weight: 700; font-size: .73rem; }
.sc-desc { font-size: .6rem; color: var(--text-muted); margin-top: 2px; }
.sel-card .sc-check {
  position: absolute; top: 6px; right: 6px; width: 16px; height: 16px;
  border-radius: 50%; background: var(--current-c, var(--cyan));
  display: none; align-items: center; justify-content: center;
  font-size: .55rem; color: #000; font-weight: 800;
}
.sel-card.checked .sc-check { display: flex; }

/* role colors */
.sel-card[data-val="admin"]       { --current-c: var(--rose);   --current-bg: rgba(244,63,94,.06);  }
.sel-card[data-val="journaliste"] { --current-c: var(--amber);  --current-bg: rgba(245,158,11,.06); }
.sel-card[data-val="lecteur"]     { --current-c: var(--neon);   --current-bg: rgba(0,255,170,.06);  }
.sel-card[data-val="actif"]       { --current-c: var(--neon);   --current-bg: rgba(0,255,170,.06);  }
.sel-card[data-val="inactif"]     { --current-c: var(--text-muted); --current-bg: rgba(255,255,255,.04); }
.sel-card[data-val="bloque"]      { --current-c: var(--rose);   --current-bg: rgba(244,63,94,.06);  }

/* ── Divider ── */
.divider { height: 1px; background: var(--border); margin: 1.5rem 0; }

/* ── Buttons ── */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  padding: 11px 22px; border-radius: var(--r-md);
  font-family: 'Syne', sans-serif; font-weight: 700; font-size: .82rem;
  cursor: pointer; transition: all .2s ease; text-decoration: none;
  border: none; white-space: nowrap; letter-spacing: .01em;
}
.btn-primary {
  background: linear-gradient(135deg, var(--cyan), var(--violet));
  color: #fff;
  box-shadow: 0 4px 18px rgba(0,212,255,.2);
  min-width: 180px;
}
.btn-primary:hover:not(:disabled) {
  opacity: .88; transform: translateY(-2px);
  box-shadow: 0 8px 28px rgba(0,212,255,.35);
}
.btn-primary:active:not(:disabled) { transform: translateY(0); }
.btn-primary:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.btn-ghost {
  background: var(--bg-card); border: 1px solid var(--border);
  color: var(--text-2);
}
.btn-ghost:hover { color: var(--text-1); background: var(--bg-hover); }

/* ── Loader spinner ── */
.spinner {
  width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.2);
  border-top-color: #fff; border-radius: 50%;
  animation: spin .65s linear infinite; flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Feedback banners ── */
.banner {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 1rem 1.2rem; border-radius: var(--r-md);
  font-size: .82rem; margin-bottom: 1.4rem;
  border-left: 3px solid transparent;
  animation: slideDown .3s ease both;
}
.banner-success {
  background: rgba(0,255,170,.07); border-color: var(--neon);
  color: var(--neon); border: 1px solid rgba(0,255,170,.25);
}
.banner-error {
  background: rgba(244,63,94,.07); border-color: var(--rose);
  color: #fca5a5; border: 1px solid rgba(244,63,94,.25);
}
.banner-warn {
  background: rgba(245,158,11,.07);
  color: var(--amber); border: 1px solid rgba(245,158,11,.25);
}
.banner i { flex-shrink: 0; font-size: 1rem; margin-top: 1px; }

/* ── Footer actions ── */
.card-footer {
  padding: 1.2rem 1.8rem;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  flex-wrap: wrap;
}

/* ── Progress indicator ── */
.form-progress {
  display: flex; gap: .4rem; align-items: center; margin-bottom: 1.5rem;
}
.prog-step {
  height: 3px; flex: 1; border-radius: 100px;
  background: rgba(255,255,255,.07); overflow: hidden;
}
.prog-step-fill {
  height: 100%; border-radius: 100px;
  background: linear-gradient(90deg, var(--cyan), var(--violet));
  transition: width .5s ease;
  width: 0%;
}

/* ── Animations ── */
@keyframes slideUp   { from { opacity:0; transform:translateY(20px) } to { opacity:1; transform:translateY(0) } }
@keyframes slideDown { from { opacity:0; transform:translateY(-12px) } to { opacity:1; transform:translateY(0) } }
@keyframes fadeIn    { from { opacity:0 } to { opacity:1 } }
@keyframes shake     { 0%,100%{ transform:translateX(0) } 15%,45%,75%{ transform:translateX(-5px) } 30%,60%{ transform:translateX(5px) } }
.shake { animation: shake .45s ease both; }

/* ── Success state overlay ── */
.success-overlay {
  position: absolute; inset: 0;
  background: rgba(5,8,15,.92);
  backdrop-filter: blur(8px);
  border-radius: var(--r-xl);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 1rem; z-index: 10; opacity: 0; pointer-events: none;
  transition: opacity .4s ease;
}
.success-overlay.show { opacity: 1; pointer-events: all; }
.success-icon {
  width: 72px; height: 72px; border-radius: 50%;
  background: linear-gradient(135deg, rgba(0,255,170,.2), rgba(0,212,255,.1));
  border: 2px solid rgba(0,255,170,.3);
  display: flex; align-items: center; justify-content: center; font-size: 2rem;
  animation: popIn .5s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes popIn { from { transform: scale(0); opacity:0 } to { transform: scale(1); opacity:1 } }
.success-title { font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1.3rem; color: var(--neon); }
.success-sub { font-size: .8rem; color: var(--text-2); text-align: center; max-width: 300px; }
.redirect-bar {
  width: 200px; height: 3px; background: rgba(255,255,255,.07);
  border-radius: 100px; overflow: hidden; margin-top: .5rem;
}
.redirect-fill {
  height: 100%; background: linear-gradient(90deg, var(--neon), var(--cyan));
  border-radius: 100px; width: 0%;
  transition: width linear;
}
</style>
</head>

<body class="dark">
<!-- Mesh bg -->
<div class="bg-mesh" aria-hidden="true"></div>

<!-- ══════════ TOPBAR ══════════ -->
<header class="topbar" role="banner">
  <a href="../dashboard.php" class="brand-icon" title="Dashboard" aria-label="Dashboard">📚</a>
  <nav class="breadcrumb" aria-label="Fil d'Ariane">
    <a href="../dashboard.php"><i class="bi bi-grid-1x2 mr-1"></i>Dashboard</a>
    <span class="sep">/</span>
    <a href="index.php">Utilisateurs</a>
    <span class="sep">/</span>
    <span class="cur">Créer</span>
  </nav>

  <div style="flex:1"></div>

  <!-- DB Status -->
  <?php if ($dbConnected): ?>
  <div class="db-pill ok" title="Base de données connectée">
    <span class="db-dot ok"></span>
    BD Connectée
  </div>
  <?php else: ?>
  <div class="db-pill err" title="Base de données inaccessible">
    <span class="db-dot err"></span>
    BD Hors ligne
  </div>
  <?php endif; ?>

  <!-- Admin pill -->
  <div class="avatar-pill">
    <div class="avatar-circle"><?= $adminAvatar ?></div>
    <span><?= $adminName ?></span>
  </div>

  <a href="../dashboard.php" class="btn btn-ghost" style="padding:6px 12px;font-size:.73rem;" title="Retour au dashboard">
    <i class="bi bi-arrow-left"></i> Retour
  </a>
</header>

<!-- ══════════ PAGE WRAPPER ══════════ -->
<div class="page-wrapper">

  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">Créer un utilisateur</h1>
      <p class="page-sub">Remplissez tous les champs obligatoires pour ajouter un nouveau membre à la plateforme.</p>
    </div>
    <a href="index.php" class="btn btn-ghost" style="flex-shrink:0;font-size:.75rem;padding:8px 14px;">
      <i class="bi bi-people"></i> Liste utilisateurs
    </a>
  </div>

  <?php if (!$dbConnected): ?>
  <div class="banner banner-warn" style="width:100%;max-width:780px" role="alert">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div>
      <strong>Base de données inaccessible.</strong> Vérifiez <code>includes/config.php</code> et que le serveur MySQL est démarré. Le formulaire ne peut pas enregistrer les données.
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════ FORM CARD ══════════ -->
  <div class="form-card" id="form-card">

    <!-- Success overlay -->
    <div class="success-overlay" id="success-overlay" role="status" aria-live="polite">
      <div class="success-icon" id="success-icon-anim">✓</div>
      <div class="success-title">Utilisateur créé !</div>
      <div class="success-sub" id="success-detail">—</div>
      <div class="redirect-bar"><div class="redirect-fill" id="redirect-fill"></div></div>
      <p style="font-size:.67rem;color:var(--text-muted);font-family:'Space Mono',monospace;">Redirection vers la liste…</p>
      <div style="display:flex;gap:8px;margin-top:.5rem">
        <a href="create.php" class="btn btn-ghost" style="font-size:.75rem;padding:7px 14px;">
          <i class="bi bi-plus-circle"></i> Ajouter un autre
        </a>
        <a href="index.php" class="btn btn-primary" style="font-size:.75rem;padding:7px 14px;min-width:auto">
          <i class="bi bi-people"></i> Voir la liste
        </a>
      </div>
    </div>

    <!-- Card header -->
    <div class="card-header">
      <div class="header-icon">👤</div>
      <div>
        <div class="card-title-text">Nouveau compte utilisateur</div>
        <div class="card-subtitle">Les champs marqués <span style="color:var(--rose)">*</span> sont obligatoires</div>
      </div>
    </div>

    <!-- Card body -->
    <div class="card-body">

      <!-- Feedback banner (JS-injected) -->
      <div id="form-banner" style="display:none" role="alert" aria-live="assertive"></div>

      <!-- Progress -->
      <div class="form-progress" aria-hidden="true">
        <div class="prog-step"><div class="prog-step-fill" id="prog-1" style="width:0%"></div></div>
        <div class="prog-step"><div class="prog-step-fill" id="prog-2" style="width:0%"></div></div>
        <div class="prog-step"><div class="prog-step-fill" id="prog-3" style="width:0%"></div></div>
        <div class="prog-step"><div class="prog-step-fill" id="prog-4" style="width:0%"></div></div>
      </div>

      <!-- ═══ FORM ═══ -->
      <form id="create-user-form" novalidate autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <!-- ── Section 1 : Identité ── -->
        <div class="section-label">Identité</div>

        <div class="form-grid-2" style="margin-bottom:1rem">
          <!-- Prénom -->
          <div class="field">
            <label class="field-label" for="prenom">
              <i class="bi bi-person"></i> Prénom
            </label>
            <div class="field-wrap">
              <i class="bi bi-person field-icon"></i>
              <input type="text" id="prenom" name="prenom" class="field-input"
                     placeholder="Jean" maxlength="150" autocomplete="given-name">
            </div>
            <div class="field-error" id="err-prenom" style="display:none"></div>
          </div>

          <!-- Nom -->
          <div class="field">
            <label class="field-label" for="nom">
              <i class="bi bi-person-badge"></i> Nom <span class="req">*</span>
            </label>
            <div class="field-wrap">
              <i class="bi bi-person-badge field-icon"></i>
              <input type="text" id="nom" name="nom" class="field-input"
                     placeholder="Dupont" maxlength="150" required autocomplete="family-name">
            </div>
            <div class="field-error" id="err-nom" style="display:none"></div>
          </div>
        </div>

        <!-- ── Section 2 : Contact ── -->
        <div class="section-label">Contact</div>

        <div class="form-grid-2" style="margin-bottom:1rem">
          <!-- Email -->
          <div class="field">
            <label class="field-label" for="email">
              <i class="bi bi-envelope"></i> Email <span class="req">*</span>
            </label>
            <div class="field-wrap">
              <i class="bi bi-envelope field-icon"></i>
              <input type="email" id="email" name="email" class="field-input"
                     placeholder="jean.dupont@exemple.com" maxlength="255" required autocomplete="email">
            </div>
            <div class="field-error" id="err-email" style="display:none"></div>
          </div>

          <!-- Téléphone -->
          <div class="field">
            <label class="field-label" for="telephone">
              <i class="bi bi-telephone"></i> Téléphone
            </label>
            <div class="field-wrap">
              <i class="bi bi-telephone field-icon"></i>
              <input type="tel" id="telephone" name="telephone" class="field-input"
                     placeholder="+237 6XX XXX XXX" maxlength="20" autocomplete="tel">
            </div>
            <div class="field-error" id="err-telephone" style="display:none"></div>
            <div class="field-hint">Optionnel — Format libre</div>
          </div>
        </div>

        <!-- ── Section 3 : Sécurité ── -->
        <div class="section-label">Sécurité</div>

        <div class="form-grid-2" style="margin-bottom:1rem">
          <!-- Mot de passe -->
          <div class="field" style="grid-column: 1 / -1">
            <label class="field-label" for="password">
              <i class="bi bi-lock"></i> Mot de passe <span class="req">*</span>
            </label>
            <div class="field-wrap">
              <i class="bi bi-lock field-icon"></i>
              <input type="password" id="password" name="password" class="field-input"
                     placeholder="Minimum 8 caractères" maxlength="128" required
                     style="padding-right:40px" autocomplete="new-password">
              <button type="button" class="pwd-toggle" id="pwd-toggle"
                      onclick="togglePassword()" title="Afficher / masquer" aria-label="Afficher / masquer le mot de passe">
                <i class="bi bi-eye" id="pwd-eye"></i>
              </button>
            </div>
            <div class="strength-bar">
              <div class="strength-fill" id="strength-fill"></div>
            </div>
            <div class="field-error" id="err-password" style="display:none"></div>
            <div class="field-hint" id="strength-label">Entrez un mot de passe</div>
          </div>
        </div>

        <!-- ── Section 4 : Rôle (auto-détecté depuis l'email) ── -->
        <div class="section-label">Rôle <span style="color:var(--cyan);font-size:.55rem;letter-spacing:.04em">AUTO-DÉTECTÉ</span></div>

        <!-- Explication -->
        <div style="background:rgba(0,212,255,.04);border:1px solid rgba(0,212,255,.1);border-radius:var(--r-md);padding:.85rem 1rem;margin-bottom:1rem;font-size:.76rem;color:var(--text-2);display:flex;gap:10px;align-items:flex-start">
          <i class="bi bi-info-circle-fill" style="color:var(--cyan);flex-shrink:0;margin-top:1px"></i>
          <div>
            Le rôle est <strong style="color:var(--text-1)">attribué automatiquement</strong> par le serveur selon le format de l'e-mail.
            Saisissez l'adresse e-mail ci-dessus pour voir le rôle détecté en temps réel.
          </div>
        </div>

        <!-- Badge de rôle détecté (mis à jour par JS) -->
        <div id="role-preview-wrap" style="margin-bottom:1rem">
          <div id="role-preview" style="
            display:flex;align-items:center;gap:12px;
            background:var(--bg-card);border:1px solid var(--border);
            border-radius:var(--r-md);padding:1rem 1.2rem;
            transition:border-color .3s,background .3s;
          ">
            <div id="rp-icon" style="font-size:1.6rem;flex-shrink:0;transition:all .3s">📖</div>
            <div style="flex:1">
              <div style="display:flex;align-items:center;gap:8px">
                <span id="rp-name" style="font-family:'Syne',sans-serif;font-weight:800;font-size:.95rem;transition:color .3s">Lecteur</span>
                <span id="rp-chip" class="chip chip-success" style="transition:all .3s">Par défaut</span>
              </div>
              <div id="rp-desc" style="font-size:.72rem;color:var(--text-muted);margin-top:3px;transition:color .3s">
                Accès lecture &amp; achats — attribué à tous les e-mails standards
              </div>
            </div>
            <div id="rp-pattern" style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--text-muted);text-align:right;flex-shrink:0;line-height:1.6">
              Entrez un<br>e-mail valide
            </div>
          </div>
        </div>

        <!-- Règles visuelles -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:1rem">
          <div class="role-rule" data-role="admin" style="
            background:rgba(244,63,94,.04);border:1px solid rgba(244,63,94,.12);
            border-radius:var(--r-sm);padding:.6rem .7rem;font-size:.65rem;
          ">
            <div style="font-size:.9rem;margin-bottom:3px">⚡</div>
            <div style="font-family:'Syne',sans-serif;font-weight:700;color:var(--rose)">Admin</div>
            <div style="color:var(--text-muted);font-family:'Space Mono',monospace;font-size:.56rem;margin-top:3px;word-break:break-all">admin.*@adminsopecam.com</div>
          </div>
          <div class="role-rule" data-role="journaliste" style="
            background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.12);
            border-radius:var(--r-sm);padding:.6rem .7rem;font-size:.65rem;
          ">
            <div style="font-size:.9rem;margin-bottom:3px">✍️</div>
            <div style="font-family:'Syne',sans-serif;font-weight:700;color:var(--amber)">Journaliste</div>
            <div style="color:var(--text-muted);font-family:'Space Mono',monospace;font-size:.56rem;margin-top:3px;word-break:break-all">journaliste.*@sopecam.com</div>
          </div>
          <div class="role-rule" data-role="lecteur" style="
            background:rgba(0,255,170,.04);border:1px solid rgba(0,255,170,.12);
            border-radius:var(--r-sm);padding:.6rem .7rem;font-size:.65rem;
          ">
            <div style="font-size:.9rem;margin-bottom:3px">📖</div>
            <div style="font-family:'Syne',sans-serif;font-weight:700;color:var(--neon)">Lecteur</div>
            <div style="color:var(--text-muted);font-family:'Space Mono',monospace;font-size:.56rem;margin-top:3px">Tous les autres e-mails</div>
          </div>
        </div>

        <!-- Hidden input : valeur envoyée au serveur (ignorée côté PHP, mais présente pour cohérence) -->
        <input type="hidden" name="role" id="role-hidden" value="lecteur">
        <div class="field-error" id="err-role" style="display:none;margin-bottom:.8rem"></div>

        <!-- ── Section 5 : Statut ── -->
        <div class="section-label">Statut du compte</div>
        <div class="select-cards-2" style="margin-bottom:1.2rem" role="radiogroup" aria-label="Sélectionner un statut">
          <label class="sel-card" data-val="actif" title="Compte actif">
            <input type="radio" name="statut" value="actif" checked aria-label="Actif">
            <div class="sc-check">✓</div>
            <div class="sc-icon">🟢</div>
            <div class="sc-name">Actif</div>
            <div class="sc-desc">Connexion autorisée</div>
          </label>
          <label class="sel-card" data-val="inactif" title="Compte inactif">
            <input type="radio" name="statut" value="inactif" aria-label="Inactif">
            <div class="sc-check">✓</div>
            <div class="sc-icon">⚪</div>
            <div class="sc-name">Inactif</div>
            <div class="sc-desc">En attente d'activation</div>
          </label>
          <label class="sel-card" data-val="bloque" title="Compte bloqué">
            <input type="radio" name="statut" value="bloque" aria-label="Bloqué">
            <div class="sc-check">✓</div>
            <div class="sc-icon">🔴</div>
            <div class="sc-name">Bloqué</div>
            <div class="sc-desc">Accès refusé</div>
          </label>
        </div>
        <div class="field-error" id="err-statut" style="display:none;margin-bottom:.8rem"></div>

      </form>
    </div><!-- /card-body -->

    <!-- Card footer -->
    <div class="card-footer">
      <div style="font-size:.7rem;color:var(--text-muted);font-family:'Space Mono',monospace" id="footer-status">
        Prêt à enregistrer
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button type="button" class="btn btn-ghost" onclick="resetForm()" title="Réinitialiser le formulaire">
          <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
        </button>
        <button type="button" class="btn btn-primary" id="submit-btn"
                onclick="submitForm()"
                <?= !$dbConnected ? 'disabled title="Base de données inaccessible"' : '' ?>>
          <i class="bi bi-person-plus-fill"></i>
          <span id="submit-label">Créer l'utilisateur</span>
        </button>
      </div>
    </div>

  </div><!-- /form-card -->

</div><!-- /page-wrapper -->

<!-- ═══════════════════════════ SCRIPTS ═══════════════════════════ -->
<script>
'use strict';

const DB_CONNECTED = <?= $dbConnected ? 'true' : 'false' ?>;

// ── Helpers ─────────────────────────────────────────────────
function esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function setError(fieldId, msg) {
  const el   = document.getElementById('err-' + fieldId);
  const inp  = document.getElementById(fieldId);
  if (!el) return;
  if (msg) {
    el.innerHTML = `<i class="bi bi-exclamation-circle"></i> ${esc(msg)}`;
    el.style.display = 'flex';
    inp?.classList.add('error');
    inp?.classList.remove('success');
  } else {
    el.style.display = 'none';
    inp?.classList.remove('error');
    if (inp?.value.trim()) inp?.classList.add('success');
  }
}

function clearAllErrors() {
  ['nom','prenom','email','telephone','password','role','statut'].forEach(f => setError(f, null));
}

function showBanner(type, msg) {
  const b = document.getElementById('form-banner');
  const icons = { success:'bi-check-circle-fill', error:'bi-x-circle-fill', warn:'bi-exclamation-triangle-fill' };
  b.className = `banner banner-${type}`;
  b.innerHTML = `<i class="bi ${icons[type]||'bi-info-circle'}"></i><div>${msg}</div>`;
  b.style.display = 'flex';
  b.scrollIntoView({ behavior:'smooth', block:'nearest' });
}

function hideBanner() {
  document.getElementById('form-banner').style.display = 'none';
}

// ── Radio card selection ───────────────────────────────────
document.querySelectorAll('.sel-card').forEach(card => {
  const radio = card.querySelector('input[type="radio"]');
  if (!radio) return;

  // Init checked state
  if (radio.checked) card.classList.add('checked');

  card.addEventListener('click', () => {
    const name = radio.name;
    document.querySelectorAll(`.sel-card input[name="${name}"]`).forEach(r => {
      r.closest('.sel-card').classList.remove('checked');
    });
    radio.checked = true;
    card.classList.add('checked');
    updateProgress();
  });

  card.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); }
  });
});

// ── Auto-détection du rôle depuis l'email ─────────────────
/**
 * Miroir JS des regex PHP — uniquement pour le feedback visuel.
 * La décision RÉELLE est toujours prise côté serveur PHP.
 */
const ROLE_RULES = [
  {
    role:    'admin',
    regex:   /^admin\.[a-z0-9][a-z0-9._-]*@adminsopecam\.com$/i,
    icon:    '⚡',
    name:    'Administrateur',
    desc:    'Accès complet à la plateforme — gestion totale',
    chip:    'chip-danger',
    chipTxt: 'admin.*@adminsopecam.com',
    color:   'var(--rose)',
    border:  'rgba(244,63,94,.35)',
    bg:      'rgba(244,63,94,.05)',
    pattern: 'admin.[slug]<br>@adminsopecam.com',
  },
  {
    role:    'journaliste',
    regex:   /^journaliste\.[a-z0-9][a-z0-9._-]*@sopecam\.com$/i,
    icon:    '✍️',
    name:    'Journaliste',
    desc:    'Publication de livres, statistiques éditoriales',
    chip:    'chip-warn',
    chipTxt: 'journaliste.*@sopecam.com',
    color:   'var(--amber)',
    border:  'rgba(245,158,11,.35)',
    bg:      'rgba(245,158,11,.05)',
    pattern: 'journaliste.[slug]<br>@sopecam.com',
  },
  {
    role:    'lecteur',
    regex:   null, // fallback
    icon:    '📖',
    name:    'Lecteur',
    desc:    'Accès lecture & achats — attribué à tous les e-mails standards',
    chip:    'chip-success',
    chipTxt: 'Par défaut',
    color:   'var(--neon)',
    border:  'rgba(0,255,170,.22)',
    bg:      'rgba(0,255,170,.03)',
    pattern: 'Tout autre<br>e-mail valide',
  },
];

function detectRoleFromEmail(email) {
  const normalized = email.trim().toLowerCase();
  if (!normalized) return null;
  for (const rule of ROLE_RULES) {
    if (rule.regex && rule.regex.test(normalized)) return rule;
  }
  return ROLE_RULES[2]; // lecteur (fallback)
}

function updateRolePreview(email) {
  const rule = detectRoleFromEmail(email);
  const preview = document.getElementById('role-preview');
  const hidden  = document.getElementById('role-hidden');

  // Highlight matching rule card
  document.querySelectorAll('.role-rule').forEach(el => {
    el.style.opacity = rule ? (el.dataset.role === rule.role ? '1' : '.35') : '1';
    el.style.transform = (rule && el.dataset.role === rule.role) ? 'scale(1.03)' : 'scale(1)';
    el.style.transition = 'all .25s';
  });

  if (!rule) {
    // No valid email yet — reset
    document.getElementById('rp-icon').textContent    = '❓';
    document.getElementById('rp-name').textContent    = '—';
    document.getElementById('rp-name').style.color    = 'var(--text-muted)';
    document.getElementById('rp-chip').className      = 'chip chip-muted';
    document.getElementById('rp-chip').textContent    = 'En attente';
    document.getElementById('rp-desc').textContent    = 'Entrez une adresse e-mail valide';
    document.getElementById('rp-pattern').innerHTML   = 'Entrez un<br>e-mail valide';
    preview.style.borderColor  = 'var(--border)';
    preview.style.background   = 'var(--bg-card)';
    hidden.value = 'lecteur';
    return;
  }

  document.getElementById('rp-icon').textContent    = rule.icon;
  document.getElementById('rp-name').textContent    = rule.name;
  document.getElementById('rp-name').style.color    = rule.color;
  document.getElementById('rp-chip').className      = `chip ${rule.chip}`;
  document.getElementById('rp-chip').textContent    = rule.chipTxt;
  document.getElementById('rp-desc').textContent    = rule.desc;
  document.getElementById('rp-pattern').innerHTML   = rule.pattern;
  preview.style.borderColor  = rule.border;
  preview.style.background   = rule.bg;
  hidden.value = rule.role;
}

// ── Password toggle ────────────────────────────────────────
function togglePassword() {
  const inp  = document.getElementById('password');
  const icon = document.getElementById('pwd-eye');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'bi bi-eye';
  }
}

// ── Password strength ──────────────────────────────────────
const strengthLabels = [
  { label:'Trop court', color:'var(--rose)',  w:'15%' },
  { label:'Faible',     color:'var(--rose)',  w:'30%' },
  { label:'Passable',   color:'var(--amber)', w:'55%' },
  { label:'Bon',        color:'var(--cyan)',  w:'75%' },
  { label:'Excellent',  color:'var(--neon)',  w:'100%'},
];
function checkStrength(pwd) {
  let score = 0;
  if (pwd.length >= 8)  score++;
  if (pwd.length >= 12) score++;
  if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) score++;
  if (/\d/.test(pwd))   score++;
  if (/[^A-Za-z0-9]/.test(pwd)) score++;
  return Math.min(score, 4);
}

document.getElementById('password').addEventListener('input', function() {
  const fill  = document.getElementById('strength-fill');
  const label = document.getElementById('strength-label');
  const val   = this.value;
  if (!val) {
    fill.style.width = '0%'; fill.style.background = 'transparent';
    label.textContent = 'Entrez un mot de passe'; return;
  }
  const idx = val.length < 6 ? 0 : checkStrength(val);
  const s   = strengthLabels[idx];
  fill.style.width = s.w;
  fill.style.background = s.color;
  label.textContent = s.label;
  setError('password', null);
  updateProgress();
});

// ── Live validation on blur ────────────────────────────────
document.getElementById('nom').addEventListener('blur', function() {
  if (this.value.trim().length < 2) setError('nom', 'Le nom doit contenir au moins 2 caractères.');
  else setError('nom', null);
  updateProgress();
});
document.getElementById('email').addEventListener('blur', function() {
  const val = this.value.trim();
  if (!val) { setError('email', 'L\'e-mail est obligatoire.'); updateRolePreview(''); return; }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) { setError('email', 'Format d\'e-mail invalide.'); updateRolePreview(''); }
  else { setError('email', null); updateRolePreview(val); }
  updateProgress();
});
document.getElementById('telephone').addEventListener('blur', function() {
  const val = this.value.trim();
  if (val && !/^[+\d\s\-()\u00A0]{6,20}$/.test(val)) setError('telephone', 'Format invalide.');
  else setError('telephone', null);
});
['nom','prenom','email','telephone'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', () => {
    const el = document.getElementById('err-' + id);
    if (el && el.style.display !== 'none') {
      document.getElementById(id)?.classList.remove('error');
      el.style.display = 'none';
    }
    updateProgress();
    // Mise à jour du rôle en temps réel pendant la frappe
    if (id === 'email') updateRolePreview(document.getElementById('email').value);
  });
});

// ── Progress calculation ───────────────────────────────────
function updateProgress() {
  const nom   = document.getElementById('nom').value.trim();
  const email = document.getElementById('email').value.trim();
  const pass  = document.getElementById('password').value;
  const role  = document.querySelector('input[name="role"]:checked')?.value;

  const p1 = nom.length >= 2 ? 100 : (nom.length / 2) * 100;
  const p2 = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? 100 : 0;
  const p3 = pass.length >= 8 ? 100 : (pass.length / 8) * 100;
  const p4 = role ? 100 : 0;

  document.getElementById('prog-1').style.width = p1 + '%';
  document.getElementById('prog-2').style.width = p2 + '%';
  document.getElementById('prog-3').style.width = p3 + '%';
  document.getElementById('prog-4').style.width = p4 + '%';
}

// ── Client-side validation ─────────────────────────────────
function validateClient() {
  clearAllErrors();
  let ok = true;
  const nom  = document.getElementById('nom').value.trim();
  const mail = document.getElementById('email').value.trim();
  const pass = document.getElementById('password').value;
  const stat = document.querySelector('input[name="statut"]:checked')?.value;
  const tel  = document.getElementById('telephone').value.trim();

  if (nom.length < 2)   { setError('nom',  'Le nom doit contenir au moins 2 caractères.'); ok = false; }
  if (nom.length > 150) { setError('nom',  'Nom trop long (max 150).'); ok = false; }

  if (!mail) { setError('email', 'L\'e-mail est obligatoire.'); ok = false; }
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) { setError('email', 'Format e-mail invalide.'); ok = false; }

  if (tel && !/^[+\d\s\-()\u00A0]{6,20}$/.test(tel)) { setError('telephone', 'Format téléphone invalide.'); ok = false; }

  if (pass.length < 8) { setError('password', 'Mot de passe trop court (min 8 caractères).'); ok = false; }

  // Rôle : toujours valide car déterminé par le serveur — aucune validation client nécessaire
  if (!stat) { setError('statut', 'Veuillez sélectionner un statut.'); ok = false; }

  return ok;
}

// ── Submit via AJAX ────────────────────────────────────────
async function submitForm() {
  if (!DB_CONNECTED) {
    showBanner('error', '⚠️ Base de données inaccessible. Vérifiez votre configuration.');
    return;
  }

  hideBanner();
  if (!validateClient()) {
    document.querySelector('.field-input.error, .field-select.error')?.scrollIntoView({ behavior:'smooth', block:'center' });
    document.getElementById('form-card').classList.add('shake');
    setTimeout(() => document.getElementById('form-card').classList.remove('shake'), 500);
    return;
  }

  const btn   = document.getElementById('submit-btn');
  const label = document.getElementById('submit-label');
  const status = document.getElementById('footer-status');

  btn.disabled = true;
  label.innerHTML = '<span class="spinner"></span> Enregistrement…';
  status.textContent = 'Envoi en cours…';

  const form    = document.getElementById('create-user-form');
  const formData = new FormData(form);

  try {
    const res = await fetch(window.location.href, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept':           'application/json',
      },
      body: formData,
    });

    if (!res.ok) throw new Error(`HTTP ${res.status} — ${res.statusText}`);

    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('json')) throw new Error('Réponse non-JSON du serveur.');

    const data = await res.json();

    if (data.success) {
      // ── Succès ──────────────────────────────────────────
      const roleLabels = { admin:'⚡ Administrateur', journaliste:'✍️ Journaliste', lecteur:'📖 Lecteur' };
      const roleLabel  = roleLabels[data.resolved_role] || data.resolved_role || '';
      const fullMsg    = (data.message || 'Utilisateur créé avec succès.')
                         + (roleLabel ? ` · Rôle attribué : <strong>${esc(roleLabel)}</strong>` : '');
      showSuccessOverlay(fullMsg, data.user_id);
    } else {
      // ── Erreurs serveur ──────────────────────────────────
      if (data.errors && typeof data.errors === 'object') {
        Object.entries(data.errors).forEach(([field, msg]) => setError(field, msg));
      }
      showBanner('error', data.message || 'Une erreur est survenue.');
      btn.disabled = false;
      label.innerHTML = '<i class="bi bi-person-plus-fill"></i> Créer l\'utilisateur';
      status.textContent = 'Erreur — corrigez les champs.';
    }

  } catch (err) {
    console.error('[DLS create]', err);
    showBanner('error', `Erreur réseau : ${esc(err.message)}`);
    btn.disabled = false;
    label.innerHTML = '<i class="bi bi-person-plus-fill"></i> Créer l\'utilisateur';
    status.textContent = 'Erreur réseau.';
  }
}

// ── Success Overlay + redirect ─────────────────────────────
function showSuccessOverlay(msg, userId) {
  const overlay = document.getElementById('success-overlay');
  const detail  = document.getElementById('success-detail');
  const fill    = document.getElementById('redirect-fill');

  detail.innerHTML  = esc(msg).replace(/&lt;strong&gt;/g,'<strong>').replace(/&lt;\/strong&gt;/g,'</strong>');
  if (userId) detail.innerHTML += `<br><span style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--text-muted)">ID #${parseInt(userId)}</span>`;

  overlay.classList.add('show');

  // Redirect bar animation
  const dur  = 3500; // ms
  const step = 30;
  let   cur  = 0;
  const tick = setInterval(() => {
    cur += (step / dur) * 100;
    fill.style.width = Math.min(cur, 100) + '%';
    if (cur >= 100) {
      clearInterval(tick);
      window.location.href = 'index.php?success=user_created';
    }
  }, step);
}

// ── Reset form ─────────────────────────────────────────────
function resetForm() {
  document.getElementById('create-user-form').reset();
  clearAllErrors();
  hideBanner();

  // Reset strength bar
  document.getElementById('strength-fill').style.width = '0%';
  document.getElementById('strength-label').textContent = 'Entrez un mot de passe';

  // Reset radio cards visual
  document.querySelectorAll('.sel-card').forEach(c => c.classList.remove('checked'));
  document.querySelectorAll('.sel-card input[type="radio"]').forEach(r => {
    if (r.checked) r.closest('.sel-card').classList.add('checked');
  });

  // Remove success class from inputs
  document.querySelectorAll('.field-input.success').forEach(el => el.classList.remove('success'));

  // Reset progress
  ['prog-1','prog-2','prog-3','prog-4'].forEach(id => document.getElementById(id).style.width = '0%');

  // Reset role preview
  updateRolePreview('');
  document.querySelectorAll('.role-rule').forEach(el => { el.style.opacity='1'; el.style.transform='scale(1)'; });

  // Reset button
  const btn   = document.getElementById('submit-btn');
  const label = document.getElementById('submit-label');
  btn.disabled = !DB_CONNECTED;
  label.innerHTML = '<i class="bi bi-person-plus-fill"></i> Créer l\'utilisateur';
  document.getElementById('footer-status').textContent = 'Prêt à enregistrer';

  // Focus
  document.getElementById('prenom').focus();
}

// ── Enter key submit ───────────────────────────────────────
document.getElementById('create-user-form').addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
    e.preventDefault(); submitForm();
  }
});

// ── Initial state ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Init checked radio cards
  document.querySelectorAll('.sel-card').forEach(card => {
    const radio = card.querySelector('input[type="radio"]');
    if (radio?.checked) card.classList.add('checked');
  });

  document.getElementById('prenom').focus();

  if (!DB_CONNECTED) {
    showBanner('error', '⚠️ Base de données inaccessible. Le formulaire est désactivé.');
  }
});
</script>

</body>
</html>