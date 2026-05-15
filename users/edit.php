<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — users/edit.php                    ║
 * ║  Édition d'un utilisateur (admin uniquement)                ║
 * ║  PHP 8 · PDO · AJAX · Dark SaaS UI · 100% fonctionnel       ║
 * ║  Rôle auto-détecté depuis email — jamais accepté du POST    ║
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

// ── Connexion PDO ─────────────────────────────────────────────
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

// ── Mode DEMO : auto-login admin si pas de session ────────────
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

// ── Guard : admins uniquement ─────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); exit;
}
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php?error=access_denied'); exit;
}

// ── CSRF Token ────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ══════════════════════════════════════════════════════════════
// FONCTION MÉTIER : detectUserRole()
// Attribution automatique du rôle depuis l'email — JAMAIS
// depuis le formulaire. Source de vérité unique côté serveur.
// ══════════════════════════════════════════════════════════════
/**
 * detectUserRole — détermine le rôle d'un utilisateur par son email.
 *
 * Règles (priorité décroissante) :
 *  1. admin.[a-z0-9._-]+@adminsopecam.com  → 'admin'
 *  2. journaliste.[a-z0-9._-]+@sopecam.com → 'journaliste'
 *  3. Tout autre email valide              → 'lecteur'
 *
 * @param  string $email  Email déjà normalisé (trim + strtolower)
 * @return string         'admin' | 'journaliste' | 'lecteur'
 */
function detectUserRole(string $email): string
{
    // 🛡️ Admin : admin.<slug>@adminsopecam.com
    if (preg_match('/^admin\.[a-z0-9][a-z0-9._-]*@adminsopecam\.com$/', $email)) {
        return 'admin';
    }
    // ✍️ Journaliste : journaliste.<slug>@sopecam.com
    if (preg_match('/^journaliste\.[a-z0-9][a-z0-9._-]*@sopecam\.com$/', $email)) {
        return 'journaliste';
    }
    // 👤 Lecteur : fallback
    return 'lecteur';
}

// ── Récupération sécurisée de l'ID cible ─────────────────────
$targetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── Traitement AJAX POST ──────────────────────────────────────
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'message' => '', 'errors' => []];

    // ── Validation CSRF ───────────────────────────────────────
    $receivedCsrf = trim($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $receivedCsrf)) {
        $response['message'] = 'Token de sécurité invalide. Rechargez la page.';
        echo json_encode($response); exit;
    }

    if (!$pdo) {
        $response['message'] = 'Base de données inaccessible.';
        echo json_encode($response); exit;
    }

    // ── ID utilisateur cible ──────────────────────────────────
    $editId = (int)($_POST['user_id'] ?? 0);
    if ($editId <= 0) {
        $response['message'] = 'Identifiant utilisateur invalide.';
        echo json_encode($response); exit;
    }

    // ── Vérifier l'existence de l'utilisateur ─────────────────
    try {
        $chkUser = $pdo->prepare("SELECT id, email, role FROM users WHERE id = ? LIMIT 1");
        $chkUser->execute([$editId]);
        $existingUser = $chkUser->fetch();
        if (!$existingUser) {
            $response['message'] = "Aucun utilisateur trouvé avec l'ID #{$editId}.";
            echo json_encode($response); exit;
        }
    } catch (PDOException $e) {
        error_log('[DLS] User existence check failed: ' . $e->getMessage());
        $response['message'] = 'Erreur lors de la vérification de l\'utilisateur.';
        echo json_encode($response); exit;
    }

    // ── Collecte & nettoyage des inputs ───────────────────────
    $nom       = trim(htmlspecialchars($_POST['nom']       ?? '', ENT_QUOTES, 'UTF-8'));
    $prenom    = trim(htmlspecialchars($_POST['prenom']    ?? '', ENT_QUOTES, 'UTF-8'));
    $email     = trim(strtolower($_POST['email']           ?? ''));
    $telephone = trim(htmlspecialchars($_POST['telephone'] ?? '', ENT_QUOTES, 'UTF-8'));
    $statut    = $_POST['statut'] ?? '';
    // ⚠️ Le champ 'role' du POST est ignoré — jamais utilisé
    // Le rôle est TOUJOURS recalculé côté serveur via detectUserRole()
    $role = detectUserRole($email);

    // ── Validation backend ────────────────────────────────────
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

    $validStatuts = ['actif', 'inactif', 'bloque'];
    if (!in_array($statut, $validStatuts, true))
        $errors['statut'] = 'Statut invalide.';

    if (!empty($errors)) {
        $response['errors']  = $errors;
        $response['message'] = 'Veuillez corriger les erreurs ci-dessous.';
        echo json_encode($response); exit;
    }

    // ── Vérifier doublon email (hors utilisateur courant) ─────
    try {
        $chkEmail = $pdo->prepare(
            "SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1"
        );
        $chkEmail->execute([$email, $editId]);
        if ($chkEmail->fetch()) {
            $response['errors']['email'] = 'Cette adresse e-mail est déjà utilisée par un autre compte.';
            $response['message']         = 'Doublon d\'e-mail détecté.';
            echo json_encode($response); exit;
        }
    } catch (PDOException $e) {
        error_log('[DLS] Email duplicate check failed: ' . $e->getMessage());
        $response['message'] = 'Erreur lors de la vérification de l\'e-mail.';
        echo json_encode($response); exit;
    }

    // ── Mise à jour en base ───────────────────────────────────
    try {
        $stmt = $pdo->prepare(
            "UPDATE users
             SET nom        = :nom,
                 prenom     = :prenom,
                 email      = :email,
                 telephone  = :telephone,
                 role       = :role,
                 statut     = :statut,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':nom'       => $nom,
            ':prenom'    => $prenom,
            ':email'     => $email,
            ':telephone' => $telephone ?: null,
            ':role'      => $role,
            ':statut'    => $statut,
            ':id'        => $editId,
        ]);

        $rowsAffected = $stmt->rowCount();

        // ── Log admin ─────────────────────────────────────────
        try {
            $oldRole = $existingUser['role'];
            $oldEmail = $existingUser['email'];
            $roleChange = ($oldRole !== $role)
                ? " | rôle: {$oldRole}→{$role}"
                : '';
            $emailChange = ($oldEmail !== $email)
                ? " | email: {$oldEmail}→{$email}"
                : '';

            $logStmt = $pdo->prepare(
                "INSERT INTO admin_logs (user_id, action, detail, ip, created_at)
                 VALUES (:uid, 'user_updated', :detail, :ip, NOW())"
            );
            $logStmt->execute([
                ':uid'    => (int)$_SESSION['user_id'],
                ':detail' => "Utilisateur #{$editId} modifié : {$prenom} {$nom}{$emailChange}{$roleChange} (statut={$statut})",
                ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ]);
        } catch (Exception $e) {
            error_log('[DLS] Admin log failed: ' . $e->getMessage());
        }

        $response['success']       = true;
        $response['message']       = "Utilisateur <strong>{$prenom} {$nom}</strong> mis à jour avec succès.";
        $response['resolved_role'] = $role;
        $response['rows_affected'] = $rowsAffected;

    } catch (PDOException $e) {
        error_log('[DLS] Update user failed: ' . $e->getMessage());
        if ($e->getCode() === '23000') {
            $response['errors']['email'] = 'Cette adresse e-mail est déjà utilisée.';
            $response['message']         = 'Doublon détecté en base de données.';
        } else {
            $response['message'] = 'Erreur lors de la mise à jour de l\'utilisateur.';
        }
    }

    echo json_encode($response); exit;
}

// ── Chargement de l'utilisateur à éditer (GET) ───────────────
$user        = null;
$loadError   = null;
$dbConnected = ($pdo !== null);

if ($targetId <= 0) {
    $loadError = 'Identifiant utilisateur manquant ou invalide. Utilisez <code>?id=X</code>.';
} elseif ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, nom, prenom, email, telephone, role, statut, avatar, created_at, updated_at
             FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$targetId]);
        $user = $stmt->fetch();
        if (!$user) {
            $loadError = "Aucun utilisateur trouvé avec l'ID #{$targetId}.";
        }
    } catch (PDOException $e) {
        error_log('[DLS] Load user failed: ' . $e->getMessage());
        $loadError = 'Erreur lors du chargement de l\'utilisateur.';
    }
} else {
    $loadError = 'Base de données inaccessible.';
}

// ── Données pour la vue ───────────────────────────────────────
$adminName   = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$adminAvatar = strtoupper(substr($adminName, 0, 1)) ?: 'A';

// Valeurs pré-remplies (ou vides si pas d'utilisateur)
$vNom       = htmlspecialchars($user['nom']       ?? '', ENT_QUOTES, 'UTF-8');
$vPrenom    = htmlspecialchars($user['prenom']    ?? '', ENT_QUOTES, 'UTF-8');
$vEmail     = htmlspecialchars($user['email']     ?? '', ENT_QUOTES, 'UTF-8');
$vTelephone = htmlspecialchars($user['telephone'] ?? '', ENT_QUOTES, 'UTF-8');
$vRole      = $user['role']   ?? 'lecteur';
$vStatut    = $user['statut'] ?? 'actif';
$vAvatar    = $user['avatar'] ?? null;
$vInitials  = strtoupper(substr(trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')), 0, 2)) ?: '??';
$vFullName  = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
$vCreatedAt = !empty($user['created_at']) ? date('d/m/Y à H:i', strtotime($user['created_at'])) : '—';
$vUpdatedAt = !empty($user['updated_at']) ? date('d/m/Y à H:i', strtotime($user['updated_at'])) : '—';

// Recalcul du rôle actuel pour l'affichage (cohérence)
$displayRole = $user ? detectUserRole($user['email'] ?? '') : 'lecteur';
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Éditer l'utilisateur<?= $user ? ' — ' . $vFullName : '' ?> — Digital Library</title>
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
        syne:  ['Syne', 'sans-serif'],
        mono:  ['Space Mono', 'monospace'],
        sans:  ['DM Sans', 'sans-serif'],
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
    }
  }
}
</script>

<style>
/* ══════════════════════════════════════════════
   VARIABLES — identiques à create.php / dashboard
══════════════════════════════════════════════ */
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
  --orange:     #f97316;
  --text-1:     #eef2ff;
  --text-2:     rgba(238,242,255,.58);
  --text-muted: rgba(238,242,255,.3);
  --r-sm:  8px;
  --r-md:  13px;
  --r-lg:  18px;
  --r-xl:  26px;
  --glow:  0 0 28px rgba(0,212,255,.18);
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body {
  font-family:'DM Sans',sans-serif;
  background:var(--bg-base);
  color:var(--text-1);
  min-height:100vh;
  overflow-x:hidden;
}
::-webkit-scrollbar { width:3px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:4px; }

/* ── Background mesh ── */
.bg-mesh {
  position:fixed; inset:0; z-index:0; pointer-events:none;
  background:
    radial-gradient(ellipse 60% 50% at 15% 20%, rgba(0,212,255,.045) 0%, transparent 60%),
    radial-gradient(ellipse 40% 60% at 85% 75%, rgba(124,58,237,.055) 0%, transparent 60%),
    radial-gradient(ellipse 30% 40% at 50% 10%, rgba(0,255,170,.025) 0%, transparent 60%);
}

/* ── Topbar ── */
.topbar {
  position:fixed; top:0; left:0; right:0; z-index:100;
  height:62px;
  background:rgba(5,8,15,.88);
  backdrop-filter:blur(22px);
  border-bottom:1px solid var(--border);
  display:flex; align-items:center; gap:1rem; padding:0 1.6rem;
}
.brand-icon {
  width:36px; height:36px; border-radius:11px; flex-shrink:0;
  background:linear-gradient(135deg,var(--cyan),var(--violet));
  display:flex; align-items:center; justify-content:center;
  font-size:1rem; box-shadow:var(--glow); text-decoration:none;
}
.breadcrumb { display:flex; align-items:center; gap:6px; font-size:.78rem; color:var(--text-2); }
.breadcrumb a { color:var(--text-2); text-decoration:none; transition:color .15s; }
.breadcrumb a:hover { color:var(--cyan); }
.breadcrumb .sep { opacity:.3; }
.breadcrumb .cur { font-family:'Syne',sans-serif; font-weight:700; color:var(--text-1); }
.avatar-pill {
  display:flex; align-items:center; gap:7px;
  background:var(--bg-card); border:1px solid var(--border);
  padding:4px 10px 4px 4px; border-radius:100px; font-size:.75rem; font-weight:600;
}
.avatar-circle {
  width:26px; height:26px; border-radius:50%;
  background:linear-gradient(135deg,var(--rose),var(--violet));
  display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-weight:800; font-size:.7rem; color:#fff;
}

/* ── DB pill ── */
.db-pill {
  display:inline-flex; align-items:center; gap:5px;
  font-family:'Space Mono',monospace; font-size:.6rem; letter-spacing:.06em;
  padding:4px 10px; border-radius:100px; text-transform:uppercase; flex-shrink:0;
}
.db-pill.ok  { background:rgba(0,255,170,.08); color:var(--neon);  border:1px solid rgba(0,255,170,.2); }
.db-pill.err { background:rgba(244,63,94,.08); color:var(--rose);  border:1px solid rgba(244,63,94,.2); }
.db-dot { width:6px; height:6px; border-radius:50%; }
.db-dot.ok  { background:var(--neon); box-shadow:0 0 6px var(--neon); animation:pulse 2s ease-in-out infinite; }
.db-dot.err { background:var(--rose); }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── Layout ── */
.page-wrapper {
  position:relative; z-index:1;
  padding:88px 1.5rem 3rem;
  min-height:100vh;
  display:flex; flex-direction:column; align-items:center;
}
.page-header {
  width:100%; max-width:860px;
  display:flex; align-items:flex-start; justify-content:space-between; gap:1rem;
  margin-bottom:1.8rem;
  animation:slideDown .45s cubic-bezier(.22,1,.36,1) both;
}
.page-title {
  font-family:'Syne',sans-serif; font-weight:800; font-size:1.6rem;
  letter-spacing:-.5px; line-height:1;
  background:linear-gradient(135deg,var(--text-1),var(--text-2));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.page-sub { font-size:.8rem; color:var(--text-muted); margin-top:5px; }

/* ── Two-column layout ── */
.edit-layout {
  width:100%; max-width:860px;
  display:grid; grid-template-columns:240px 1fr; gap:1.2rem;
  align-items:start;
  animation:slideUp .5s cubic-bezier(.22,1,.36,1) .05s both;
}
@media(max-width:720px) { .edit-layout { grid-template-columns:1fr; } }

/* ── Sidebar card (avatar + meta) ── */
.meta-card {
  background:var(--bg-card); border:1px solid var(--border);
  border-radius:var(--r-xl); overflow:hidden; position:relative;
  backdrop-filter:blur(12px);
}
.meta-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,var(--violet),var(--cyan),var(--neon));
}
.meta-body { padding:1.5rem 1.2rem; display:flex; flex-direction:column; align-items:center; gap:.7rem; }

/* ── Avatar ── */
.user-avatar-wrap { position:relative; }
.user-avatar {
  width:88px; height:88px; border-radius:50%;
  background:linear-gradient(135deg,var(--cyan),var(--violet));
  display:flex; align-items:center; justify-content:center;
  font-family:'Syne',sans-serif; font-weight:800; font-size:1.6rem; color:#fff;
  box-shadow:0 0 30px rgba(0,212,255,.25), 0 0 0 3px rgba(0,212,255,.12);
  position:relative; overflow:hidden; transition:box-shadow .3s;
}
.user-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; }
.avatar-ring {
  position:absolute; inset:-4px; border-radius:50%;
  border:2px solid transparent;
  background:linear-gradient(135deg,var(--cyan),var(--violet)) border-box;
  -webkit-mask:linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
  -webkit-mask-composite:destination-out; mask-composite:exclude;
  animation:rotateRing 6s linear infinite;
}
@keyframes rotateRing { to { transform:rotate(360deg); } }

.meta-name {
  font-family:'Syne',sans-serif; font-weight:800; font-size:1rem;
  text-align:center; line-height:1.2;
}
.meta-email {
  font-size:.68rem; color:var(--text-muted); font-family:'Space Mono',monospace;
  text-align:center; word-break:break-all; padding:0 .4rem;
}

/* Role badge */
.role-badge {
  display:inline-flex; align-items:center; gap:5px;
  font-family:'Space Mono',monospace; font-size:.62rem; letter-spacing:.05em;
  padding:4px 12px; border-radius:100px; text-transform:uppercase; font-weight:700;
  transition:all .3s ease;
}
.rb-admin       { background:rgba(244,63,94,.12);  color:var(--rose);  border:1px solid rgba(244,63,94,.25); }
.rb-journaliste { background:rgba(245,158,11,.12); color:var(--amber); border:1px solid rgba(245,158,11,.25);}
.rb-lecteur     { background:rgba(0,255,170,.08);  color:var(--neon);  border:1px solid rgba(0,255,170,.2); }
.rb-dot { width:5px; height:5px; border-radius:50%; background:currentColor; flex-shrink:0; }

/* Meta info rows */
.meta-info { width:100%; border-top:1px solid var(--border); padding-top:.9rem; display:flex; flex-direction:column; gap:.45rem; }
.mi-row { display:flex; align-items:flex-start; gap:7px; font-size:.7rem; }
.mi-icon { color:var(--text-muted); font-size:.78rem; flex-shrink:0; margin-top:1px; }
.mi-label { color:var(--text-muted); font-family:'Space Mono',monospace; font-size:.58rem; text-transform:uppercase; letter-spacing:.05em; flex-shrink:0; min-width:52px; }
.mi-val { color:var(--text-2); word-break:break-all; line-height:1.4; }

/* Statut badge */
.statut-pill {
  display:inline-flex; align-items:center; gap:4px; font-size:.62rem;
  font-family:'Space Mono',monospace; padding:2px 8px; border-radius:100px;
  text-transform:uppercase; font-weight:700; letter-spacing:.03em;
}
.sp-actif   { background:rgba(0,255,170,.1); color:var(--neon);  border:1px solid rgba(0,255,170,.2); }
.sp-inactif { background:rgba(255,255,255,.05); color:var(--text-muted); border:1px solid var(--border); }
.sp-bloque  { background:rgba(244,63,94,.1);  color:var(--rose); border:1px solid rgba(244,63,94,.2); }

/* ── Form card ── */
.form-card {
  background:var(--bg-card); border:1px solid var(--border);
  border-radius:var(--r-xl); overflow:hidden;
  backdrop-filter:blur(12px); position:relative;
}
.form-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon));
}
.card-header {
  padding:1.2rem 1.6rem; border-bottom:1px solid var(--border);
  display:flex; align-items:center; gap:10px;
}
.header-icon {
  width:38px; height:38px; border-radius:11px; flex-shrink:0;
  background:linear-gradient(135deg,rgba(0,212,255,.15),rgba(124,58,237,.15));
  border:1px solid rgba(0,212,255,.15);
  display:flex; align-items:center; justify-content:center; font-size:1rem;
}
.card-title-text { font-family:'Syne',sans-serif; font-weight:700; font-size:.9rem; }
.card-subtitle   { font-size:.7rem; color:var(--text-muted); margin-top:2px; }
.card-body   { padding:1.5rem 1.6rem; }
.card-footer {
  padding:1.1rem 1.6rem; border-top:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
}

/* ── Section label ── */
.section-label {
  font-family:'Space Mono',monospace; font-size:.6rem; letter-spacing:.14em;
  text-transform:uppercase; color:var(--text-muted);
  display:flex; align-items:center; gap:10px; margin-bottom:1rem; margin-top:.2rem;
}
.section-label::after { content:''; flex:1; height:1px; background:var(--border); }

/* ── Form grid ── */
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:.9rem; }
@media(max-width:560px) { .form-grid-2 { grid-template-columns:1fr; } }

/* ── Field ── */
.field { display:flex; flex-direction:column; gap:5px; }
.field-label {
  font-family:'Space Mono',monospace; font-size:.62rem; letter-spacing:.07em;
  text-transform:uppercase; color:var(--text-2);
  display:flex; align-items:center; gap:5px;
}
.req { color:var(--rose); font-size:.55rem; }
.field-wrap { position:relative; }
.field-icon {
  position:absolute; left:12px; top:50%; transform:translateY(-50%);
  color:var(--text-muted); font-size:.85rem; pointer-events:none; transition:color .2s;
}
.field-wrap:focus-within .field-icon { color:var(--cyan); }
.field-input, .field-select {
  width:100%;
  background:rgba(255,255,255,.04);
  border:1px solid var(--border);
  border-radius:var(--r-md);
  padding:10px 12px 10px 36px;
  color:var(--text-1); font-size:.83rem;
  font-family:'DM Sans',sans-serif;
  outline:none;
  transition:border-color .2s,box-shadow .2s,background .2s;
  appearance:none; -webkit-appearance:none;
}
.field-select { cursor:pointer; }
.field-input::placeholder { color:var(--text-muted); }
.field-input:hover, .field-select:hover {
  border-color:rgba(255,255,255,.12); background:rgba(255,255,255,.06);
}
.field-input:focus, .field-select:focus {
  border-color:var(--border-act);
  box-shadow:0 0 0 3px rgba(0,212,255,.08),var(--glow);
  background:rgba(0,212,255,.03);
}
.field-input.error, .field-select.error {
  border-color:rgba(244,63,94,.5);
  box-shadow:0 0 0 3px rgba(244,63,94,.06);
}
.field-input.success { border-color:rgba(0,255,170,.4); }
.select-wrap::after {
  content:'\F282'; font-family:'bootstrap-icons';
  position:absolute; right:11px; top:50%; transform:translateY(-50%);
  color:var(--text-muted); font-size:.8rem; pointer-events:none;
}
.field-error { font-size:.67rem; color:var(--rose); display:flex; align-items:center; gap:4px; }
.field-error i { font-size:.7rem; }
.field-hint  { font-size:.67rem; color:var(--text-muted); }

/* ── Statut select cards ── */
.sel-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:.6rem; }
@media(max-width:420px) { .sel-cards { grid-template-columns:1fr; } }
.sel-card {
  position:relative; cursor:pointer;
  background:rgba(255,255,255,.03);
  border:1px solid var(--border);
  border-radius:var(--r-md);
  padding:.8rem .7rem .7rem;
  transition:all .2s; user-select:none; text-align:center;
}
.sel-card input[type="radio"] { position:absolute; opacity:0; width:0; height:0; }
.sel-card:hover { border-color:rgba(255,255,255,.12); background:var(--bg-hover); }
.sel-card.checked {
  border-color:var(--c,var(--neon));
  background:var(--cbg,rgba(0,255,170,.05));
  box-shadow:0 0 0 1px var(--c,var(--neon));
}
.sc-icon { font-size:1.2rem; margin-bottom:4px; }
.sc-name { font-family:'Syne',sans-serif; font-weight:700; font-size:.7rem; }
.sc-desc { font-size:.58rem; color:var(--text-muted); margin-top:2px; }
.sc-check {
  position:absolute; top:5px; right:5px; width:15px; height:15px;
  border-radius:50%; background:var(--c,var(--neon));
  display:none; align-items:center; justify-content:center;
  font-size:.52rem; color:#000; font-weight:800;
}
.sel-card.checked .sc-check { display:flex; }
.sel-card[data-val="actif"]   { --c:var(--neon);  --cbg:rgba(0,255,170,.05); }
.sel-card[data-val="inactif"] { --c:var(--text-muted); --cbg:rgba(255,255,255,.04); }
.sel-card[data-val="bloque"]  { --c:var(--rose);  --cbg:rgba(244,63,94,.06); }

/* ── Role preview panel ── */
.role-preview {
  display:flex; align-items:center; gap:12px;
  background:var(--bg-card); border:1px solid var(--border);
  border-radius:var(--r-md); padding:1rem 1.2rem;
  transition:border-color .3s,background .3s;
}
#rp-name { font-family:'Syne',sans-serif; font-weight:800; font-size:.92rem; transition:color .3s; }
.rp-chip {
  display:inline-flex; align-items:center; gap:4px;
  font-size:.6rem; font-family:'Space Mono',monospace;
  padding:2px 8px; border-radius:100px; font-weight:700; text-transform:uppercase;
  transition:all .3s;
}
.rp-pattern {
  font-family:'Space Mono',monospace; font-size:.6rem; color:var(--text-muted);
  text-align:right; flex-shrink:0; line-height:1.6;
}

/* ── Role rule cards ── */
.role-rules { display:grid; grid-template-columns:repeat(3,1fr); gap:.5rem; margin-bottom:1rem; }
.role-rule {
  border-radius:var(--r-sm); padding:.6rem .65rem; font-size:.65rem;
  transition:all .25s;
}
@media(max-width:480px) { .role-rules { grid-template-columns:1fr; } }

/* ── Buttons ── */
.btn {
  display:inline-flex; align-items:center; justify-content:center; gap:7px;
  padding:10px 20px; border-radius:var(--r-md);
  font-family:'Syne',sans-serif; font-weight:700; font-size:.8rem;
  cursor:pointer; transition:all .2s ease; text-decoration:none;
  border:none; white-space:nowrap; letter-spacing:.01em;
}
.btn-sm { padding:6px 13px; font-size:.73rem; }
.btn-primary {
  background:linear-gradient(135deg,var(--cyan),var(--violet));
  color:#fff; box-shadow:0 4px 18px rgba(0,212,255,.2); min-width:160px;
}
.btn-primary:hover:not(:disabled) { opacity:.88; transform:translateY(-2px); box-shadow:0 8px 28px rgba(0,212,255,.35); }
.btn-primary:active:not(:disabled) { transform:translateY(0); }
.btn-primary:disabled { opacity:.4; cursor:not-allowed; transform:none; }
.btn-ghost { background:var(--bg-card); border:1px solid var(--border); color:var(--text-2); }
.btn-ghost:hover { color:var(--text-1); background:var(--bg-hover); }
.btn-danger { background:rgba(244,63,94,.1); border:1px solid rgba(244,63,94,.25); color:var(--rose); }
.btn-danger:hover { background:rgba(244,63,94,.18); }
.btn-amber  { background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.25); color:var(--amber); }
.btn-amber:hover { background:rgba(245,158,11,.18); }

/* ── Spinner ── */
.spinner {
  width:15px; height:15px; border:2px solid rgba(255,255,255,.2);
  border-top-color:#fff; border-radius:50%;
  animation:spin .65s linear infinite; flex-shrink:0;
}
@keyframes spin { to { transform:rotate(360deg); } }

/* ── Banners ── */
.banner {
  display:flex; align-items:flex-start; gap:10px;
  padding:1rem 1.2rem; border-radius:var(--r-md);
  font-size:.82rem; margin-bottom:1.3rem;
  animation:slideDown .3s ease both;
}
.banner-success { background:rgba(0,255,170,.07); color:var(--neon); border:1px solid rgba(0,255,170,.25); }
.banner-error   { background:rgba(244,63,94,.07); color:#fca5a5;     border:1px solid rgba(244,63,94,.25); }
.banner-warn    { background:rgba(245,158,11,.07); color:var(--amber); border:1px solid rgba(245,158,11,.25); }
.banner i { flex-shrink:0; font-size:1rem; margin-top:1px; }

/* ── Change indicator ── */
.changed-indicator {
  position:absolute; top:8px; right:8px; width:7px; height:7px;
  border-radius:50%; background:var(--amber);
  box-shadow:0 0 6px var(--amber); display:none;
}
.field-wrap.changed .changed-indicator { display:block; }

/* ── Diff badge ── */
.diff-tag {
  display:inline-flex; align-items:center; gap:4px;
  font-family:'Space Mono',monospace; font-size:.58rem;
  padding:1px 6px; border-radius:4px;
  background:rgba(245,158,11,.12); color:var(--amber); border:1px solid rgba(245,158,11,.2);
  margin-left:6px; animation:fadeIn .3s ease;
}

/* ── Success overlay ── */
.success-overlay {
  position:absolute; inset:0;
  background:rgba(5,8,15,.94); backdrop-filter:blur(10px);
  border-radius:var(--r-xl);
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:1rem; z-index:10; opacity:0; pointer-events:none;
  transition:opacity .4s ease;
}
.success-overlay.show { opacity:1; pointer-events:all; }
.success-icon-wrap {
  width:72px; height:72px; border-radius:50%;
  background:linear-gradient(135deg,rgba(0,212,255,.15),rgba(0,255,170,.1));
  border:2px solid rgba(0,212,255,.3);
  display:flex; align-items:center; justify-content:center; font-size:2rem;
  animation:popIn .5s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes popIn { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }
.success-title { font-family:'Syne',sans-serif; font-weight:800; font-size:1.2rem; color:var(--neon); }
.success-sub { font-size:.8rem; color:var(--text-2); text-align:center; max-width:280px; }
.redirect-bar { width:200px; height:3px; background:rgba(255,255,255,.07); border-radius:100px; overflow:hidden; }
.redirect-fill { height:100%; background:linear-gradient(90deg,var(--cyan),var(--neon)); border-radius:100px; width:0%; }

/* ── Error card (user not found) ── */
.error-card {
  width:100%; max-width:860px;
  background:var(--bg-card); border:1px solid rgba(244,63,94,.2);
  border-radius:var(--r-xl); padding:3rem 2rem;
  text-align:center; animation:slideUp .5s ease both;
}
.error-icon { font-size:3rem; margin-bottom:1rem; }
.error-title { font-family:'Syne',sans-serif; font-weight:800; font-size:1.3rem; color:var(--rose); margin-bottom:.4rem; }
.error-sub { font-size:.82rem; color:var(--text-2); line-height:1.6; }

/* ── Animations ── */
@keyframes slideUp   { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideDown { from{opacity:0;transform:translateY(-12px)} to{opacity:1;transform:translateY(0)} }
@keyframes fadeIn    { from{opacity:0} to{opacity:1} }
@keyframes shake     { 0%,100%{transform:translateX(0)} 15%,45%,75%{transform:translateX(-5px)} 30%,60%{transform:translateX(5px)} }
.shake { animation:shake .45s ease both; }
</style>
</head>

<body class="dark">
<div class="bg-mesh" aria-hidden="true"></div>

<!-- ═══════════════ TOPBAR ═══════════════ -->
<header class="topbar" role="banner">
  <a href="../dashboard.php" class="brand-icon" title="Dashboard" aria-label="Dashboard">📚</a>
  <nav class="breadcrumb" aria-label="Fil d'Ariane">
    <a href="../dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
    <span class="sep">/</span>
    <a href="index.php">Utilisateurs</a>
    <span class="sep">/</span>
    <span class="cur">
      <?php if ($user): ?>
        Éditer — <?= $vFullName ?: '#' . $targetId ?>
      <?php else: ?>
        Éditer
      <?php endif; ?>
    </span>
  </nav>

  <div style="flex:1"></div>

  <!-- DB Status -->
  <?php if ($dbConnected): ?>
  <div class="db-pill ok" title="Base de données connectée"><span class="db-dot ok"></span>BD Connectée</div>
  <?php else: ?>
  <div class="db-pill err" title="BD inaccessible"><span class="db-dot err"></span>BD Hors ligne</div>
  <?php endif; ?>

  <!-- Admin pill -->
  <div class="avatar-pill">
    <div class="avatar-circle"><?= $adminAvatar ?></div>
    <span><?= $adminName ?></span>
  </div>

  <a href="index.php" class="btn btn-ghost btn-sm" title="Retour à la liste">
    <i class="bi bi-arrow-left"></i> Liste
  </a>
</header>

<!-- ═══════════════ PAGE ═══════════════ -->
<div class="page-wrapper">

  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">
        <?php if ($user): ?>
          Éditer l'utilisateur
        <?php else: ?>
          Utilisateur introuvable
        <?php endif; ?>
      </h1>
      <p class="page-sub">
        <?php if ($user): ?>
          Modification des informations · ID <span style="font-family:'Space Mono',monospace;color:var(--cyan)">#<?= $targetId ?></span>
          · Dernière modif : <?= $vUpdatedAt ?>
        <?php else: ?>
          Aucun utilisateur correspondant à cet identifiant.
        <?php endif; ?>
      </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-shrink:0">
      <?php if ($user): ?>
      <a href="view.php?id=<?= $targetId ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> Voir</a>
      <?php endif; ?>
      <a href="create.php" class="btn btn-ghost btn-sm"><i class="bi bi-plus-circle"></i> Créer</a>
    </div>
  </div>

  <!-- ═══ ERROR STATE ═══ -->
  <?php if ($loadError): ?>
  <div class="error-card" role="alert">
    <div class="error-icon">⚠️</div>
    <div class="error-title">
      <?= $dbConnected ? 'Utilisateur introuvable' : 'Base de données inaccessible' ?>
    </div>
    <p class="error-sub"><?= $loadError ?></p>
    <div style="margin-top:1.5rem;display:flex;gap:.7rem;justify-content:center;flex-wrap:wrap">
      <a href="index.php" class="btn btn-ghost btn-sm"><i class="bi bi-people"></i> Liste des utilisateurs</a>
      <a href="create.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> Créer un utilisateur</a>
      <a href="../dashboard.php" class="btn btn-ghost btn-sm"><i class="bi bi-grid-1x2"></i> Dashboard</a>
    </div>
  </div>

  <?php else: ?>

  <!-- ═══ MAIN LAYOUT ═══ -->
  <div class="edit-layout">

    <!-- ── SIDEBAR : Avatar + Meta ── -->
    <div class="meta-card">
      <div class="meta-body">

        <!-- Avatar -->
        <div class="user-avatar-wrap">
          <div class="user-avatar" id="avatar-display">
            <?php if ($vAvatar && file_exists('../' . $vAvatar)): ?>
              <img src="../<?= htmlspecialchars($vAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
            <?php else: ?>
              <span id="avatar-initials"><?= $vInitials ?></span>
            <?php endif; ?>
          </div>
          <div class="avatar-ring" aria-hidden="true"></div>
        </div>

        <!-- Nom -->
        <div class="meta-name" id="meta-name-display"><?= $vFullName ?: '—' ?></div>

        <!-- Email -->
        <div class="meta-email" id="meta-email-display"><?= $vEmail ?></div>

        <!-- Rôle badge (mis à jour dynamiquement) -->
        <div id="meta-role-badge"
          class="role-badge <?php
            echo match($displayRole) {
              'admin'       => 'rb-admin',
              'journaliste' => 'rb-journaliste',
              default       => 'rb-lecteur',
            };
          ?>">
          <span class="rb-dot"></span>
          <?php
            echo match($displayRole) {
              'admin'       => '⚡ Admin',
              'journaliste' => '✍️ Journaliste',
              default       => '📖 Lecteur',
            };
          ?>
        </div>

        <!-- Statut -->
        <div id="meta-statut-badge" class="statut-pill sp-<?= $vStatut ?>">
          <?php
            echo match($vStatut) {
              'actif'   => '🟢 Actif',
              'inactif' => '⚪ Inactif',
              'bloque'  => '🔴 Bloqué',
              default   => $vStatut,
            };
          ?>
        </div>

        <!-- Meta info -->
        <div class="meta-info">
          <div class="mi-row">
            <i class="bi bi-hash mi-icon"></i>
            <span class="mi-label">ID</span>
            <span class="mi-val" style="font-family:'Space Mono',monospace;color:var(--cyan)">#<?= $targetId ?></span>
          </div>
          <div class="mi-row">
            <i class="bi bi-calendar3 mi-icon"></i>
            <span class="mi-label">Créé</span>
            <span class="mi-val"><?= $vCreatedAt ?></span>
          </div>
          <div class="mi-row">
            <i class="bi bi-pencil mi-icon"></i>
            <span class="mi-label">Modifié</span>
            <span class="mi-val" id="meta-updated"><?= $vUpdatedAt ?></span>
          </div>
          <?php if (!empty($user['telephone'])): ?>
          <div class="mi-row">
            <i class="bi bi-telephone mi-icon"></i>
            <span class="mi-label">Tél</span>
            <span class="mi-val" id="meta-tel"><?= $vTelephone ?></span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Quick actions -->
        <div style="width:100%;border-top:1px solid var(--border);padding-top:.9rem;display:flex;flex-direction:column;gap:.45rem">
          <a href="view.php?id=<?= $targetId ?>" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center">
            <i class="bi bi-eye"></i> Voir le profil
          </a>
          <?php if ((int)$_SESSION['user_id'] !== $targetId): ?>
          <button type="button" class="btn btn-danger btn-sm" style="width:100%;justify-content:center"
                  onclick="confirmDelete(<?= $targetId ?>, '<?= htmlspecialchars(addslashes($vFullName), ENT_QUOTES, 'UTF-8') ?>')">
            <i class="bi bi-trash3"></i> Supprimer
          </button>
          <?php else: ?>
          <div style="font-size:.65rem;color:var(--text-muted);text-align:center;font-family:'Space Mono',monospace;padding:.3rem 0">
            <i class="bi bi-shield-lock"></i> Compte courant
          </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- ── FORM CARD ── -->
    <div class="form-card" id="form-card">

      <!-- Success overlay -->
      <div class="success-overlay" id="success-overlay" role="status" aria-live="polite">
        <div class="success-icon-wrap">✓</div>
        <div class="success-title">Modifications sauvegardées !</div>
        <div class="success-sub" id="success-detail">—</div>
        <div class="redirect-bar"><div class="redirect-fill" id="redirect-fill"></div></div>
        <p style="font-size:.65rem;color:var(--text-muted);font-family:'Space Mono',monospace">Redirection vers la liste…</p>
        <div style="display:flex;gap:8px;margin-top:.5rem;flex-wrap:wrap;justify-content:center">
          <button type="button" class="btn btn-ghost btn-sm" onclick="dismissSuccess()">
            <i class="bi bi-pencil"></i> Continuer l'édition
          </button>
          <a href="index.php" class="btn btn-primary btn-sm" style="min-width:auto">
            <i class="bi bi-people"></i> Liste utilisateurs
          </a>
        </div>
      </div>

      <!-- Card header -->
      <div class="card-header">
        <div class="header-icon">✏️</div>
        <div>
          <div class="card-title-text">Modifier les informations</div>
          <div class="card-subtitle">Les champs marqués <span style="color:var(--rose)">*</span> sont obligatoires</div>
        </div>
        <div style="margin-left:auto">
          <span id="changes-count" style="display:none" class="diff-tag">
            <i class="bi bi-pencil-fill"></i> <span id="changes-num">0</span> modif.
          </span>
        </div>
      </div>

      <!-- Card body -->
      <div class="card-body">

        <!-- Feedback banner -->
        <div id="form-banner" style="display:none" role="alert" aria-live="assertive"></div>

        <!-- FORM -->
        <form id="edit-user-form" novalidate autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="user_id"   value="<?= $targetId ?>">
          <!-- Le champ role n'est PAS dans le formulaire : le serveur le détermine seul -->

          <!-- ── Section 1 : Identité ── -->
          <div class="section-label">Identité</div>
          <div class="form-grid-2" style="margin-bottom:1rem">

            <div class="field">
              <label class="field-label" for="prenom">
                <i class="bi bi-person"></i> Prénom
              </label>
              <div class="field-wrap" id="wrap-prenom">
                <div class="changed-indicator"></div>
                <i class="bi bi-person field-icon"></i>
                <input type="text" id="prenom" name="prenom" class="field-input"
                       value="<?= $vPrenom ?>"
                       placeholder="Jean" maxlength="150" autocomplete="given-name">
              </div>
              <div class="field-error" id="err-prenom" style="display:none"></div>
            </div>

            <div class="field">
              <label class="field-label" for="nom">
                <i class="bi bi-person-badge"></i> Nom <span class="req">*</span>
              </label>
              <div class="field-wrap" id="wrap-nom">
                <div class="changed-indicator"></div>
                <i class="bi bi-person-badge field-icon"></i>
                <input type="text" id="nom" name="nom" class="field-input"
                       value="<?= $vNom ?>"
                       placeholder="Dupont" maxlength="150" required autocomplete="family-name">
              </div>
              <div class="field-error" id="err-nom" style="display:none"></div>
            </div>

          </div>

          <!-- ── Section 2 : Contact ── -->
          <div class="section-label">Contact</div>
          <div class="form-grid-2" style="margin-bottom:1rem">

            <div class="field">
              <label class="field-label" for="email">
                <i class="bi bi-envelope"></i> Email <span class="req">*</span>
              </label>
              <div class="field-wrap" id="wrap-email">
                <div class="changed-indicator"></div>
                <i class="bi bi-envelope field-icon"></i>
                <input type="email" id="email" name="email" class="field-input"
                       value="<?= $vEmail ?>"
                       placeholder="exemple@domaine.com" maxlength="255" required autocomplete="email">
              </div>
              <div class="field-error" id="err-email" style="display:none"></div>
              <div class="field-hint" id="email-role-hint" style="display:flex;align-items:center;gap:5px;margin-top:3px">
                <i class="bi bi-info-circle" style="color:var(--cyan);font-size:.68rem"></i>
                <span>La modification de l'email recalcule automatiquement le rôle</span>
              </div>
            </div>

            <div class="field">
              <label class="field-label" for="telephone">
                <i class="bi bi-telephone"></i> Téléphone
              </label>
              <div class="field-wrap" id="wrap-telephone">
                <div class="changed-indicator"></div>
                <i class="bi bi-telephone field-icon"></i>
                <input type="tel" id="telephone" name="telephone" class="field-input"
                       value="<?= $vTelephone ?>"
                       placeholder="+237 6XX XXX XXX" maxlength="20" autocomplete="tel">
              </div>
              <div class="field-error" id="err-telephone" style="display:none"></div>
              <div class="field-hint">Optionnel — Format libre</div>
            </div>

          </div>

          <!-- ── Section 3 : Rôle auto-détecté ── -->
          <div class="section-label">
            Rôle <span style="color:var(--cyan);font-size:.55rem;letter-spacing:.04em">AUTO-DÉTECTÉ</span>
          </div>

          <div style="background:rgba(0,212,255,.04);border:1px solid rgba(0,212,255,.1);border-radius:var(--r-md);padding:.8rem 1rem;margin-bottom:.9rem;font-size:.75rem;color:var(--text-2);display:flex;gap:10px;align-items:flex-start">
            <i class="bi bi-shield-lock-fill" style="color:var(--cyan);flex-shrink:0;margin-top:1px"></i>
            <div>
              Le rôle est <strong style="color:var(--text-1)">recalculé automatiquement</strong> par le serveur à chaque modification.
              Modifier l'e-mail modifie le rôle.
            </div>
          </div>

          <!-- Role preview box -->
          <div class="role-preview" id="role-preview" style="margin-bottom:.9rem">
            <div id="rp-icon" style="font-size:1.5rem;flex-shrink:0;transition:all .3s">
              <?= match($displayRole) { 'admin'=>'⚡', 'journaliste'=>'✍️', default=>'📖' } ?>
            </div>
            <div style="flex:1">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span id="rp-name" style="
                  color:<?= match($displayRole) { 'admin'=>'var(--rose)', 'journaliste'=>'var(--amber)', default=>'var(--neon)' } ?>
                ">
                  <?= match($displayRole) { 'admin'=>'Administrateur', 'journaliste'=>'Journaliste', default=>'Lecteur' } ?>
                </span>
                <span id="rp-chip" class="rp-chip <?= match($displayRole) { 'admin'=>'chip-danger', 'journaliste'=>'chip-warn', default=>'chip-success' } ?>"
                  style="<?= match($displayRole) {
                    'admin'       => 'background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)',
                    'journaliste' => 'background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)',
                    default       => 'background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)',
                  } ?>">
                  <?= match($displayRole) { 'admin'=>'admin.*@adminsopecam.com', 'journaliste'=>'journaliste.*@sopecam.com', default=>'Par défaut' } ?>
                </span>
              </div>
              <div id="rp-desc" style="font-size:.7rem;color:var(--text-muted);margin-top:3px">
                <?= match($displayRole) {
                  'admin'       => 'Accès complet à la plateforme — gestion totale',
                  'journaliste' => 'Publication de livres, statistiques éditoriales',
                  default       => 'Accès lecture & achats — attribué à tous les e-mails standards',
                } ?>
              </div>
            </div>
            <div id="rp-pattern" style="font-family:'Space Mono',monospace;font-size:.58rem;color:var(--text-muted);text-align:right;flex-shrink:0;line-height:1.7">
              <?= match($displayRole) { 'admin'=>'admin.[slug]<br>@adminsopecam.com', 'journaliste'=>'journaliste.[slug]<br>@sopecam.com', default=>'Tout autre<br>e-mail valide' } ?>
            </div>
          </div>

          <!-- Role rule cards -->
          <div class="role-rules">
            <div class="role-rule" data-role="admin" style="background:rgba(244,63,94,.04);border:1px solid rgba(244,63,94,.12)">
              <div style="font-size:.85rem;margin-bottom:3px">⚡</div>
              <div style="font-family:'Syne',sans-serif;font-weight:700;color:var(--rose)">Admin</div>
              <div style="color:var(--text-muted);font-family:'Space Mono',monospace;font-size:.55rem;margin-top:3px;word-break:break-all">admin.*@adminsopecam.com</div>
            </div>
            <div class="role-rule" data-role="journaliste" style="background:rgba(245,158,11,.04);border:1px solid rgba(245,158,11,.12)">
              <div style="font-size:.85rem;margin-bottom:3px">✍️</div>
              <div style="font-family:'Syne',sans-serif;font-weight:700;color:var(--amber)">Journaliste</div>
              <div style="color:var(--text-muted);font-family:'Space Mono',monospace;font-size:.55rem;margin-top:3px;word-break:break-all">journaliste.*@sopecam.com</div>
            </div>
            <div class="role-rule" data-role="lecteur" style="background:rgba(0,255,170,.04);border:1px solid rgba(0,255,170,.12)">
              <div style="font-size:.85rem;margin-bottom:3px">📖</div>
              <div style="font-family:'Syne',sans-serif;font-weight:700;color:var(--neon)">Lecteur</div>
              <div style="color:var(--text-muted);font-family:'Space Mono',monospace;font-size:.55rem;margin-top:3px">Tout autre e-mail</div>
            </div>
          </div>

          <!-- ── Section 4 : Statut ── -->
          <div class="section-label">Statut du compte</div>
          <div class="sel-cards" style="margin-bottom:1.2rem" role="radiogroup" aria-label="Statut du compte">

            <label class="sel-card<?= $vStatut === 'actif' ? ' checked' : '' ?>" data-val="actif">
              <input type="radio" name="statut" value="actif" <?= $vStatut === 'actif' ? 'checked' : '' ?> aria-label="Actif">
              <div class="sc-check">✓</div>
              <div class="sc-icon">🟢</div>
              <div class="sc-name">Actif</div>
              <div class="sc-desc">Connexion autorisée</div>
            </label>

            <label class="sel-card<?= $vStatut === 'inactif' ? ' checked' : '' ?>" data-val="inactif">
              <input type="radio" name="statut" value="inactif" <?= $vStatut === 'inactif' ? 'checked' : '' ?> aria-label="Inactif">
              <div class="sc-check">✓</div>
              <div class="sc-icon">⚪</div>
              <div class="sc-name">Inactif</div>
              <div class="sc-desc">En attente d'activation</div>
            </label>

            <label class="sel-card<?= $vStatut === 'bloque' ? ' checked' : '' ?>" data-val="bloque">
              <input type="radio" name="statut" value="bloque" <?= $vStatut === 'bloque' ? 'checked' : '' ?> aria-label="Bloqué">
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
        <div id="footer-status" style="font-size:.68rem;color:var(--text-muted);font-family:'Space Mono',monospace">
          Aucune modification
        </div>
        <div style="display:flex;gap:7px;align-items:center">
          <button type="button" class="btn btn-ghost btn-sm" onclick="revertChanges()" id="revert-btn"
                  style="display:none" title="Annuler les modifications">
            <i class="bi bi-arrow-counterclockwise"></i> Annuler
          </button>
          <a href="index.php" class="btn btn-ghost btn-sm">
            <i class="bi bi-x-lg"></i> Fermer
          </a>
          <button type="button" class="btn btn-primary" id="submit-btn"
                  onclick="submitForm()"
                  <?= !$dbConnected ? 'disabled title="Base de données inaccessible"' : '' ?>>
            <i class="bi bi-floppy-fill"></i>
            <span id="submit-label">Sauvegarder</span>
          </button>
        </div>
      </div>

    </div><!-- /form-card -->
  </div><!-- /edit-layout -->

  <?php endif; ?>

</div><!-- /page-wrapper -->

<!-- ═══════════════════════════ SCRIPTS ═══════════════════════════ -->
<script>
'use strict';

const DB_CONNECTED  = <?= $dbConnected ? 'true' : 'false' ?>;
const TARGET_ID     = <?= $targetId ?>;
const USER_EXISTS   = <?= $user ? 'true' : 'false' ?>;

// Valeurs initiales pour détecter les changements
const ORIGINAL = {
  nom:       <?= json_encode($user['nom']       ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
  prenom:    <?= json_encode($user['prenom']    ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
  email:     <?= json_encode($user['email']     ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
  telephone: <?= json_encode($user['telephone'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
  statut:    <?= json_encode($user['statut']    ?? 'actif', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
};

if (!USER_EXISTS) { /* page is in error state, no JS needed */ }

// ── Helpers ──────────────────────────────────────────────────
function esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function setError(fieldId, msg) {
  const el  = document.getElementById('err-' + fieldId);
  const inp = document.getElementById(fieldId);
  if (!el) return;
  if (msg) {
    el.innerHTML = `<i class="bi bi-exclamation-circle"></i> ${esc(msg)}`;
    el.style.display = 'flex';
    inp?.classList.add('error'); inp?.classList.remove('success');
  } else {
    el.style.display = 'none';
    inp?.classList.remove('error');
    if (inp?.value.trim()) inp?.classList.add('success');
  }
}
function clearAllErrors() {
  ['nom','prenom','email','telephone','statut'].forEach(f => setError(f, null));
}
function showBanner(type, msg) {
  const b = document.getElementById('form-banner');
  const icons = { success:'bi-check-circle-fill', error:'bi-x-circle-fill', warn:'bi-exclamation-triangle-fill' };
  b.className = `banner banner-${type}`;
  b.innerHTML = `<i class="bi ${icons[type]||'bi-info-circle'}"></i><div>${msg}</div>`;
  b.style.display = 'flex';
  b.scrollIntoView({ behavior:'smooth', block:'nearest' });
}
function hideBanner() { document.getElementById('form-banner').style.display = 'none'; }

// ── Change tracking ───────────────────────────────────────────
function getChangedFields() {
  const fields = ['nom','prenom','email','telephone'];
  const changed = [];
  fields.forEach(f => {
    const el = document.getElementById(f);
    if (el && el.value.trim() !== (ORIGINAL[f] || '').trim()) changed.push(f);
  });
  const statut = document.querySelector('input[name="statut"]:checked')?.value;
  if (statut && statut !== ORIGINAL.statut) changed.push('statut');
  return changed;
}

function updateChangeTracking() {
  const changed = getChangedFields();
  const cnt     = changed.length;
  const counter = document.getElementById('changes-count');
  const num     = document.getElementById('changes-num');
  const status  = document.getElementById('footer-status');
  const revert  = document.getElementById('revert-btn');

  // Update counter badge
  if (cnt > 0) {
    counter.style.display = 'inline-flex';
    num.textContent = cnt;
    status.textContent  = `${cnt} champ${cnt>1?'s':''} modifié${cnt>1?'s':''}`;
    status.style.color  = 'var(--amber)';
    revert.style.display = '';
  } else {
    counter.style.display = 'none';
    status.textContent   = 'Aucune modification';
    status.style.color   = 'var(--text-muted)';
    revert.style.display = 'none';
  }

  // Changed indicator dots
  ['nom','prenom','email','telephone'].forEach(f => {
    const wrap = document.getElementById('wrap-' + f);
    const el   = document.getElementById(f);
    if (!wrap || !el) return;
    const isChanged = el.value.trim() !== (ORIGINAL[f] || '').trim();
    wrap.classList.toggle('changed', isChanged);
  });

  // Sidebar live preview
  updateSidebarPreview();
}

function updateSidebarPreview() {
  const nom    = document.getElementById('nom')?.value.trim()    || '';
  const prenom = document.getElementById('prenom')?.value.trim() || '';
  const email  = document.getElementById('email')?.value.trim()  || '';
  const tel    = document.getElementById('telephone')?.value.trim() || '';
  const statut = document.querySelector('input[name="statut"]:checked')?.value || 'actif';

  const full = [prenom, nom].filter(Boolean).join(' ') || '—';
  const initials = (prenom[0] || '') + (nom[0] || '') || '??';

  // Name
  const nameEl = document.getElementById('meta-name-display');
  if (nameEl) nameEl.textContent = full.toUpperCase() === full ? full : full;

  // Email
  const emailEl = document.getElementById('meta-email-display');
  if (emailEl) emailEl.textContent = email || '—';

  // Initials in avatar
  const initEl = document.getElementById('avatar-initials');
  if (initEl) initEl.textContent = initials.toUpperCase().substring(0,2);

  // Tel in meta
  const telEl = document.getElementById('meta-tel');
  if (telEl) telEl.textContent = tel || '—';

  // Statut badge
  const statBadge = document.getElementById('meta-statut-badge');
  if (statBadge) {
    statBadge.className = `statut-pill sp-${statut}`;
    statBadge.innerHTML = { actif:'🟢 Actif', inactif:'⚪ Inactif', bloque:'🔴 Bloqué' }[statut] || statut;
  }
}

// ── Revert changes ────────────────────────────────────────────
function revertChanges() {
  document.getElementById('nom').value       = ORIGINAL.nom;
  document.getElementById('prenom').value    = ORIGINAL.prenom;
  document.getElementById('email').value     = ORIGINAL.email;
  document.getElementById('telephone').value = ORIGINAL.telephone;

  // Reset statut radio
  document.querySelectorAll('.sel-card').forEach(c => {
    const r = c.querySelector('input[type="radio"]');
    if (!r || r.name !== 'statut') return;
    r.checked = r.value === ORIGINAL.statut;
    c.classList.toggle('checked', r.value === ORIGINAL.statut);
  });

  clearAllErrors();
  hideBanner();
  updateChangeTracking();
  updateRolePreview(ORIGINAL.email);
  showBanner('warn', 'Modifications annulées — valeurs d\'origine restaurées.');
}

// ── Role auto-detection (miroir JS des regex PHP) ─────────────
const ROLE_RULES = [
  {
    role: 'admin',
    regex: /^admin\.[a-z0-9][a-z0-9._-]*@adminsopecam\.com$/i,
    icon: '⚡', name: 'Administrateur',
    desc: 'Accès complet à la plateforme — gestion totale',
    color: 'var(--rose)',
    chipStyle: 'background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)',
    chipTxt: 'admin.*@adminsopecam.com',
    border: 'rgba(244,63,94,.35)', bg: 'rgba(244,63,94,.04)',
    pattern: 'admin.[slug]<br>@adminsopecam.com',
    badgeClass: 'rb-admin', badgeTxt: '⚡ Admin',
  },
  {
    role: 'journaliste',
    regex: /^journaliste\.[a-z0-9][a-z0-9._-]*@sopecam\.com$/i,
    icon: '✍️', name: 'Journaliste',
    desc: 'Publication de livres, statistiques éditoriales',
    color: 'var(--amber)',
    chipStyle: 'background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)',
    chipTxt: 'journaliste.*@sopecam.com',
    border: 'rgba(245,158,11,.35)', bg: 'rgba(245,158,11,.04)',
    pattern: 'journaliste.[slug]<br>@sopecam.com',
    badgeClass: 'rb-journaliste', badgeTxt: '✍️ Journaliste',
  },
  {
    role: 'lecteur',
    regex: null,
    icon: '📖', name: 'Lecteur',
    desc: 'Accès lecture & achats — attribué à tous les e-mails standards',
    color: 'var(--neon)',
    chipStyle: 'background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)',
    chipTxt: 'Par défaut',
    border: 'rgba(0,255,170,.22)', bg: 'rgba(0,255,170,.03)',
    pattern: 'Tout autre<br>e-mail valide',
    badgeClass: 'rb-lecteur', badgeTxt: '📖 Lecteur',
  },
];

function detectRoleJS(email) {
  const normalized = email.trim().toLowerCase();
  if (!normalized || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized)) return null;
  for (const rule of ROLE_RULES) {
    if (rule.regex && rule.regex.test(normalized)) return rule;
  }
  return ROLE_RULES[2]; // lecteur
}

function updateRolePreview(email) {
  const rule = detectRoleJS(email);
  const preview = document.getElementById('role-preview');

  // Highlight rule cards
  document.querySelectorAll('.role-rule').forEach(el => {
    el.style.opacity   = rule ? (el.dataset.role === rule.role ? '1' : '.3') : '1';
    el.style.transform = (rule && el.dataset.role === rule.role) ? 'scale(1.04)' : 'scale(1)';
    el.style.transition = 'all .25s';
  });

  if (!rule) {
    document.getElementById('rp-icon').textContent  = '❓';
    document.getElementById('rp-name').textContent  = 'E-mail invalide';
    document.getElementById('rp-name').style.color  = 'var(--text-muted)';
    document.getElementById('rp-chip').textContent  = 'En attente';
    document.getElementById('rp-chip').style.cssText = 'background:rgba(255,255,255,.05);color:var(--text-muted);border:1px solid var(--border)';
    document.getElementById('rp-desc').textContent  = 'Entrez une adresse e-mail valide';
    document.getElementById('rp-pattern').innerHTML = 'Entrez un<br>e-mail valide';
    if (preview) { preview.style.borderColor = 'var(--border)'; preview.style.background = 'var(--bg-card)'; }

    const badge = document.getElementById('meta-role-badge');
    if (badge) { badge.className='role-badge rb-lecteur'; badge.innerHTML='<span class="rb-dot"></span>📖 Lecteur'; }
    return;
  }

  document.getElementById('rp-icon').textContent     = rule.icon;
  document.getElementById('rp-name').textContent     = rule.name;
  document.getElementById('rp-name').style.color     = rule.color;
  document.getElementById('rp-chip').textContent     = rule.chipTxt;
  document.getElementById('rp-chip').style.cssText   = rule.chipStyle;
  document.getElementById('rp-desc').textContent     = rule.desc;
  document.getElementById('rp-pattern').innerHTML    = rule.pattern;
  if (preview) { preview.style.borderColor = rule.border; preview.style.background = rule.bg; }

  // Update sidebar badge
  const badge = document.getElementById('meta-role-badge');
  if (badge) {
    badge.className = `role-badge ${rule.badgeClass}`;
    badge.innerHTML = `<span class="rb-dot"></span>${rule.badgeTxt}`;
  }
}

// ── Statut card interactions ──────────────────────────────────
document.querySelectorAll('.sel-card').forEach(card => {
  const radio = card.querySelector('input[type="radio"]');
  if (!radio || radio.name !== 'statut') return;
  if (radio.checked) card.classList.add('checked');
  card.addEventListener('click', () => {
    document.querySelectorAll('.sel-card[data-val]').forEach(c => {
      const r = c.querySelector('input[name="statut"]');
      if (r) { r.checked = false; c.classList.remove('checked'); }
    });
    radio.checked = true;
    card.classList.add('checked');
    updateChangeTracking();
  });
  card.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); }
  });
});

// ── Live field listeners ──────────────────────────────────────
['nom','prenom','telephone'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', () => {
    const el = document.getElementById('err-' + id);
    if (el?.style.display !== 'none') {
      document.getElementById(id)?.classList.remove('error');
      if (el) el.style.display = 'none';
    }
    updateChangeTracking();
  });
});

document.getElementById('email')?.addEventListener('input', function() {
  const el = document.getElementById('err-email');
  if (el?.style.display !== 'none') {
    this.classList.remove('error');
    el.style.display = 'none';
  }
  updateRolePreview(this.value);
  updateChangeTracking();
});

// Blur validation
document.getElementById('nom')?.addEventListener('blur', function() {
  if (this.value.trim().length < 2) setError('nom', 'Le nom doit contenir au moins 2 caractères.');
  else setError('nom', null);
});
document.getElementById('email')?.addEventListener('blur', function() {
  const val = this.value.trim();
  if (!val) { setError('email', 'L\'e-mail est obligatoire.'); updateRolePreview(''); }
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) { setError('email', 'Format e-mail invalide.'); updateRolePreview(''); }
  else { setError('email', null); updateRolePreview(val); }
});
document.getElementById('telephone')?.addEventListener('blur', function() {
  const val = this.value.trim();
  if (val && !/^[+\d\s\-()\u00A0]{6,20}$/.test(val)) setError('telephone', 'Format invalide.');
  else setError('telephone', null);
});

// ── Client-side validation ────────────────────────────────────
function validateClient() {
  clearAllErrors();
  let ok = true;
  const nom   = document.getElementById('nom').value.trim();
  const mail  = document.getElementById('email').value.trim();
  const tel   = document.getElementById('telephone').value.trim();
  const stat  = document.querySelector('input[name="statut"]:checked')?.value;

  if (nom.length < 2)   { setError('nom', 'Le nom doit contenir au moins 2 caractères.'); ok = false; }
  if (nom.length > 150) { setError('nom', 'Nom trop long (max 150).'); ok = false; }

  if (!mail) { setError('email', 'L\'e-mail est obligatoire.'); ok = false; }
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) { setError('email', 'Format e-mail invalide.'); ok = false; }

  if (tel && !/^[+\d\s\-()\u00A0]{6,20}$/.test(tel)) { setError('telephone', 'Format téléphone invalide.'); ok = false; }

  if (!stat) { setError('statut', 'Veuillez sélectionner un statut.'); ok = false; }

  return ok;
}

// ── AJAX Submit ───────────────────────────────────────────────
async function submitForm() {
  if (!DB_CONNECTED || !USER_EXISTS) return;
  hideBanner();

  if (!validateClient()) {
    document.querySelector('.field-input.error')?.scrollIntoView({ behavior:'smooth', block:'center' });
    document.getElementById('form-card').classList.add('shake');
    setTimeout(() => document.getElementById('form-card').classList.remove('shake'), 500);
    return;
  }

  const btn    = document.getElementById('submit-btn');
  const label  = document.getElementById('submit-label');
  const status = document.getElementById('footer-status');

  btn.disabled    = true;
  label.innerHTML = '<span class="spinner"></span> Sauvegarde…';
  status.textContent = 'Envoi en cours…';
  status.style.color = 'var(--cyan)';

  const form     = document.getElementById('edit-user-form');
  const formData = new FormData(form);

  try {
    const res = await fetch(window.location.href, {
      method:  'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body:    formData,
    });

    if (!res.ok) throw new Error(`HTTP ${res.status} — ${res.statusText}`);
    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('json')) throw new Error('Réponse non-JSON. Vérifiez edit.php.');
    const data = await res.json();

    if (data.success) {
      // ── Succès ──────────────────────────────────────────────
      const roleLabels = { admin:'⚡ Administrateur', journaliste:'✍️ Journaliste', lecteur:'📖 Lecteur' };
      const rl  = roleLabels[data.resolved_role] || data.resolved_role || '';
      const msg = (data.message || 'Modifications enregistrées.')
                + (rl ? ` · Rôle : <strong>${esc(rl)}</strong>` : '');

      // Update meta sidebar timestamp
      const now = new Date();
      const fmt = now.toLocaleDateString('fr-FR') + ' à ' + now.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
      const updEl = document.getElementById('meta-updated');
      if (updEl) updEl.textContent = fmt;

      // Update ORIGINAL to new values (prevent stale diff)
      ORIGINAL.nom       = document.getElementById('nom').value.trim();
      ORIGINAL.prenom    = document.getElementById('prenom').value.trim();
      ORIGINAL.email     = document.getElementById('email').value.trim();
      ORIGINAL.telephone = document.getElementById('telephone').value.trim();
      ORIGINAL.statut    = document.querySelector('input[name="statut"]:checked')?.value || 'actif';

      updateChangeTracking();
      showSuccessOverlay(msg);

    } else {
      // ── Erreurs ──────────────────────────────────────────────
      if (data.errors && typeof data.errors === 'object') {
        Object.entries(data.errors).forEach(([field, msg]) => setError(field, msg));
      }
      showBanner('error', data.message || 'Une erreur est survenue.');
      btn.disabled    = false;
      label.innerHTML = '<i class="bi bi-floppy-fill"></i> Sauvegarder';
      status.textContent = 'Erreur — corrigez les champs.';
      status.style.color  = 'var(--rose)';
    }

  } catch (err) {
    console.error('[DLS edit]', err);
    showBanner('error', `Erreur réseau : ${esc(err.message)}`);
    btn.disabled    = false;
    label.innerHTML = '<i class="bi bi-floppy-fill"></i> Sauvegarder';
    status.textContent = 'Erreur réseau.';
    status.style.color  = 'var(--rose)';
  }
}

// ── Success overlay ───────────────────────────────────────────
let redirectTimer = null;
function showSuccessOverlay(msg) {
  const overlay = document.getElementById('success-overlay');
  const detail  = document.getElementById('success-detail');
  const fill    = document.getElementById('redirect-fill');

  detail.innerHTML = msg;
  overlay.classList.add('show');

  clearInterval(redirectTimer);
  const dur = 4000, step = 30;
  let cur = 0;
  fill.style.width = '0%';
  redirectTimer = setInterval(() => {
    cur += (step / dur) * 100;
    fill.style.width = Math.min(cur, 100) + '%';
    if (cur >= 100) {
      clearInterval(redirectTimer);
      window.location.href = 'index.php?success=user_updated';
    }
  }, step);
}
function dismissSuccess() {
  clearInterval(redirectTimer);
  document.getElementById('success-overlay').classList.remove('show');
  document.getElementById('submit-btn').disabled = false;
  document.getElementById('submit-label').innerHTML = '<i class="bi bi-floppy-fill"></i> Sauvegarder';
  document.getElementById('footer-status').textContent = 'Aucune modification';
  document.getElementById('footer-status').style.color  = 'var(--text-muted)';
}

// ── Delete confirm ────────────────────────────────────────────
function confirmDelete(id, name) {
  if (!confirm(`⚠️ Supprimer "${name}" ?\n\nCette action est irréversible.`)) return;
  window.location.href = `delete.php?id=${id}`;
}

// ── Enter key submit ──────────────────────────────────────────
document.getElementById('edit-user-form')?.addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
    e.preventDefault(); submitForm();
  }
});

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (!USER_EXISTS) return;

  // Initial role preview based on loaded email
  updateRolePreview(document.getElementById('email')?.value || '');

  // Initial change tracking
  updateChangeTracking();

  // Init sel-cards checked state
  document.querySelectorAll('.sel-card').forEach(c => {
    const r = c.querySelector('input[type="radio"]');
    if (r?.checked) c.classList.add('checked');
  });

  if (!DB_CONNECTED) {
    showBanner('error', '⚠️ Base de données inaccessible. Vérifiez votre configuration.');
  }
});
</script>

</body>
</html>