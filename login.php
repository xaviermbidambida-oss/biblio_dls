<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (function_exists('isLoggedIn') && isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

// ───────────────────────── INIT ─────────────────────────
$db = getDB();

$error = '';
$flash = getFlash();

$isBlocked = false;
$remainingHint = null;

// ───────────────────────── HELPERS ─────────────────────────
function getClientIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return trim(explode(',', $_SERVER[$k])[0]);
        }
    }
    return '0.0.0.0';
}

function writeLog(PDO $db, ?int $userId, string $action, string $detail, string $level = 'info'): void {
    try {
        $db->prepare("
            INSERT INTO admin_logs (user_id, action, detail, ip, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([
            $userId,
            $action,
            "[{$level}] {$detail}",
            getClientIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Throwable $e) {}
}

function maxAttempts(string $role): int {
    return match($role) {
        'admin' => 5,
        'journaliste' => 4,
        default => 3
    };
}

// ───────────────────────── LOGIN ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = "Veuillez remplir tous les champs.";
    }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Adresse e-mail invalide.";
    }
    else {

        $stmt = $db->prepare("
            SELECT id, nom, prenom, email, password, role, statut,
                   login_count, block_count, blocked_at
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── USER NOT FOUND ──
        if (!$user) {
            $error = "Identifiants incorrects.";
            writeLog($db, null, 'login_fail', "Unknown email: $email", 'warning');
        }

        // ── BLOCKED ──
        elseif ($user['statut'] === 'bloque') {
            $isBlocked = true;
            $error = "Compte bloqué. Contactez l'administrateur.";
        }

        // ── INACTIVE ──
        elseif ($user['statut'] !== 'actif') {
            $error = "Compte inactif. Contactez l'administrateur.";
        }

        // ── WRONG PASSWORD ──
        elseif (!password_verify($password, $user['password'])) {

            $max = maxAttempts($user['role']);

            $new = (int)$user['block_count'] + 1;

            if ($new >= $max) {

                $db->prepare("
                    UPDATE users
                    SET block_count = ?,
                        statut = 'bloque',
                        blocked_at = NOW()
                    WHERE id = ?
                ")->execute([$new, $user['id']]);

                $isBlocked = true;

                $error = "Compte bloqué après trop de tentatives.";

            } else {

                $left = $max - $new;
                $remainingHint = $left;

                $db->prepare("
                    UPDATE users
                    SET block_count = ?
                    WHERE id = ?
                ")->execute([$new, $user['id']]);

                $error = "Mot de passe incorrect. ({$left} tentative(s) restante(s))";
            }
        }

        // ── SUCCESS ──
        else {

            session_regenerate_id(true);

            $_SESSION['user_id']   = (int)$user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = trim($user['prenom'].' '.$user['nom']);
            $_SESSION['user_email']= $user['email'];

            $db->prepare("
                UPDATE users
                SET block_count = 0,
                    blocked_at = NULL,
                    login_count = login_count + 1,
                    last_login = NOW()
                WHERE id = ?
            ")->execute([$user['id']]);

            writeLog($db, (int)$user['id'], 'login_success', 'Login OK');

            setFlash('welcome', "Bienvenue {$prenom} 👋");

            header('Location: dashboard.php');
            exit;
        }
    }
}

function getFlash(): ?array {
  if (!isset($_SESSION)) {
      session_start();
  }

  $f = $_SESSION['_flash'] ?? null;

  if ($f !== null) {
      unset($_SESSION['_flash']);
      return $f;
  }

  return null;
}

function setFlash(string $type, string $msg): void {
    if (!isset($_SESSION)) {
        session_start();
    }

    $_SESSION['_flash'] = [
        'type' => $type,
        'msg'  => $msg
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#070b14;--paper:#0d1220;--slate:#131a2e;
  --mist:rgba(200,210,255,0.08);--fog:rgba(200,210,255,0.04);
  --gold:#e8c97d;--ember:#ff6b35;--sage:#4ecca3;--azure:#4a9eff;
  --red:#ff4d6d;--amber:#f59e0b;
  --txt-primary:#f0eeea;--txt-secondary:rgba(240,238,234,0.55);--txt-muted:rgba(240,238,234,0.3);
  --glass:rgba(255,255,255,0.03);--glass-border:rgba(255,255,255,0.07);--glass-hover:rgba(255,255,255,0.06);
  --glow-gold:0 0 40px rgba(232,201,125,0.15);
  --ease-spring:cubic-bezier(0.34,1.56,0.64,1);--ease-smooth:cubic-bezier(0.25,0.46,0.45,0.94);
  --radius:14px;--radius-sm:10px;
}
html{height:100%;scroll-behavior:smooth}
body{
  min-height:100vh;font-family:'Cabinet Grotesk',system-ui,sans-serif;
  background:var(--ink);color:var(--txt-primary);
  display:flex;align-items:center;justify-content:center;
  padding:1.5rem;overflow-x:hidden;position:relative;
}
::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-track{background:var(--ink)}
::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

body::before{
  content:'';position:fixed;inset:0;z-index:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
  opacity:.4;pointer-events:none;
}

.bg-orb{position:fixed;border-radius:50%;filter:blur(100px);pointer-events:none;z-index:0;animation:orbDrift 25s ease-in-out infinite}
.orb-a{width:600px;height:600px;background:rgba(232,201,125,0.06);top:-200px;left:-150px}
.orb-b{width:500px;height:500px;background:rgba(255,107,53,0.05);bottom:-180px;right:-150px;animation-delay:-10s}
.orb-c{width:320px;height:320px;background:rgba(78,204,163,0.04);top:50%;left:55%;animation-delay:-18s}
@keyframes orbDrift{
  0%,100%{transform:translate(0,0) scale(1)}
  33%{transform:translate(50px,-40px) scale(1.08)}
  66%{transform:translate(-40px,50px) scale(0.93)}
}

.bg-grid{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:
    linear-gradient(rgba(232,201,125,0.025) 1px,transparent 1px),
    linear-gradient(90deg,rgba(232,201,125,0.025) 1px,transparent 1px);
  background-size:60px 60px;
  mask-image:radial-gradient(ellipse at center,black 20%,transparent 75%);
}

/* ── Wrapper ── */
.auth-wrapper{
  position:relative;z-index:1;
  width:100%;max-width:1060px;
  display:grid;grid-template-columns:1fr 1fr;
  min-height:600px;border-radius:24px;overflow:hidden;
  border:1px solid var(--glass-border);
  box-shadow:0 0 0 1px rgba(232,201,125,0.05),0 50px 120px rgba(0,0,0,0.75),
    0 0 80px rgba(232,201,125,0.04),inset 0 1px 0 rgba(255,255,255,0.05);
  animation:wrapperIn .55s var(--ease-smooth) both;
}
@keyframes wrapperIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:820px){
  .auth-wrapper{grid-template-columns:1fr;max-width:480px}
  .auth-promo{display:none}
}

/* ── Promo ── */
.auth-promo{
  background:linear-gradient(155deg,#080d1c 0%,#0b0a1c 60%,#060e18 100%);
  padding:3rem 2.8rem;display:flex;flex-direction:column;justify-content:space-between;
  position:relative;overflow:hidden;border-right:1px solid rgba(255,255,255,0.05);
}
.promo-bar{
  position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--gold),var(--ember),var(--sage),var(--gold));
  background-size:300% auto;animation:barSlide 5s linear infinite;
}
@keyframes barSlide{0%{background-position:0%}100%{background-position:300%}}
.promo-deco{
  position:absolute;right:-20px;bottom:100px;font-size:9rem;opacity:.04;
  animation:bookFloat 7s ease-in-out infinite;pointer-events:none;user-select:none;
}
@keyframes bookFloat{0%,100%{transform:translateY(0) rotate(-4deg)}50%{transform:translateY(-20px) rotate(4deg)}}
.promo-logo{
  display:flex;align-items:center;gap:11px;font-family:'Clash Display',sans-serif;
  font-weight:600;font-size:1.05rem;text-decoration:none;color:var(--txt-primary);position:relative;z-index:1;
}
.logo-mark{
  width:40px;height:40px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,var(--gold),var(--ember));
  display:flex;align-items:center;justify-content:center;font-size:1rem;box-shadow:var(--glow-gold);
}
.promo-body{position:relative;z-index:1;padding:.75rem 0}
.promo-badge{
  display:inline-flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:0.64rem;
  color:var(--sage);border:1px solid rgba(78,204,163,0.25);background:rgba(78,204,163,0.05);
  padding:5px 14px;border-radius:100px;letter-spacing:0.08em;margin-bottom:1.5rem;
}
.badge-pulse{width:6px;height:6px;border-radius:50%;background:var(--sage);animation:blink 1.5s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.15}}
.promo-title{
  font-family:'Clash Display',sans-serif;font-size:1.85rem;font-weight:700;
  letter-spacing:-1.5px;line-height:1.1;margin-bottom:1rem;
}
.promo-title .g1{background:linear-gradient(135deg,var(--gold) 20%,var(--ember) 80%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-size:200% auto;animation:shimmer 4s linear infinite}
.promo-title .g2{background:linear-gradient(135deg,var(--sage),var(--azure));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
@keyframes shimmer{to{background-position:200% center}}
.promo-sub{font-size:0.82rem;color:var(--txt-secondary);line-height:1.85;margin-bottom:1.8rem}
.promo-features{list-style:none;display:flex;flex-direction:column;gap:.75rem}
.promo-features li{
  display:flex;align-items:flex-start;gap:12px;font-size:0.79rem;color:var(--txt-secondary);
  line-height:1.55;padding:.65rem .9rem;border-radius:var(--radius-sm);
  background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.04);
  transition:background .2s,border-color .2s;
}
.promo-features li:hover{background:rgba(232,201,125,0.04);border-color:rgba(232,201,125,0.15)}
.feat-ic{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:0.82rem;flex-shrink:0;margin-top:1px}
.fi-g{background:rgba(232,201,125,0.1);color:var(--gold)}
.fi-s{background:rgba(78,204,163,0.1);color:var(--sage)}
.fi-a{background:rgba(74,158,255,0.1);color:var(--azure)}
.fi-e{background:rgba(255,107,53,0.1);color:var(--ember)}
.promo-stats{
  display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;
  padding-top:1.5rem;border-top:1px solid var(--glass-border);position:relative;z-index:1;
}
.p-stat{text-align:center;padding:.5rem .4rem;border-radius:var(--radius-sm);background:rgba(255,255,255,0.02)}
.p-stat-val{
  font-family:'Clash Display',sans-serif;font-size:1.3rem;font-weight:700;
  background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-0.5px;
}
.p-stat-lbl{font-size:0.6rem;color:var(--txt-muted);text-transform:uppercase;letter-spacing:0.07em;margin-top:2px;font-family:'JetBrains Mono',monospace}

/* ── Form Panel ── */
.auth-form{
  background:var(--paper);padding:2.5rem 2.8rem;
  display:flex;flex-direction:column;justify-content:center;
  position:relative;overflow:hidden;
}
.auth-form::before{
  content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;
  background:radial-gradient(circle,rgba(232,201,125,0.06),transparent 70%);pointer-events:none;
}
.auth-form::after{
  content:'';position:absolute;bottom:-60px;left:-60px;width:180px;height:180px;border-radius:50%;
  background:radial-gradient(circle,rgba(255,107,53,0.04),transparent 70%);pointer-events:none;
}
.form-eyebrow{
  font-family:'JetBrains Mono',monospace;font-size:0.62rem;letter-spacing:0.14em;
  color:var(--gold);text-transform:uppercase;margin-bottom:.5rem;display:flex;align-items:center;gap:8px;
}
.form-eyebrow::before{content:'';width:18px;height:1px;background:var(--gold)}
.form-title{font-family:'Clash Display',sans-serif;font-size:1.75rem;font-weight:700;letter-spacing:-1px;line-height:1.1;margin-bottom:.35rem}
.form-title span{background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.form-sub{font-size:0.8rem;color:var(--txt-secondary);margin-bottom:1.8rem;line-height:1.7}

/* ── Alerts ── */
.alert{
  display:flex;align-items:flex-start;gap:10px;padding:13px 16px;border-radius:var(--radius-sm);
  font-size:0.79rem;line-height:1.65;margin-bottom:1.2rem;
  animation:alertSlide .35s var(--ease-spring) both;
}
@keyframes alertSlide{from{opacity:0;transform:translateY(-8px) scale(0.98)}to{opacity:1;transform:translateY(0) scale(1)}}
.alert-error  {background:rgba(255,77,109,0.08);border:1px solid rgba(255,77,109,0.3);color:#ff8fa0}
.alert-success{background:rgba(78,204,163,0.07);border:1px solid rgba(78,204,163,0.28);color:var(--sage)}
.alert-welcome{background:rgba(232,201,125,0.07);border:1px solid rgba(232,201,125,0.28);color:var(--gold)}
.alert-blocked{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.35);color:#fbbf24}
.alert i{flex-shrink:0;font-size:1rem;margin-top:1px}
.alert-title{font-weight:700;display:block;margin-bottom:2px}

/* Attempts bar */
.attempts-bar{
  display:flex;gap:5px;margin-top:8px;
}
.attempt-dot{
  width:10px;height:10px;border-radius:50%;
  background:rgba(255,77,109,0.18);border:1px solid rgba(255,77,109,0.35);
  transition:background .3s;
}
.attempt-dot.used{background:var(--red);border-color:var(--red)}

/* Fields */
.fields{display:flex;flex-direction:column;gap:1rem}
.field-group{display:flex;flex-direction:column;gap:.35rem}
.field-label{font-size:0.7rem;font-weight:500;letter-spacing:.04em;color:var(--txt-secondary);display:flex;align-items:center;gap:6px}
.field-wrap{position:relative}
.field-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--txt-muted);font-size:.88rem;pointer-events:none;transition:color .22s}
.field-input{
  width:100%;padding:13px 14px 13px 42px;background:var(--slate);border:1px solid var(--glass-border);
  border-radius:var(--radius-sm);color:var(--txt-primary);font-size:0.86rem;font-family:'Cabinet Grotesk',sans-serif;
  outline:none;transition:border-color .22s,box-shadow .22s,background .22s;
}
.field-input:focus{border-color:rgba(232,201,125,0.4);box-shadow:0 0 0 3px rgba(232,201,125,0.07);background:rgba(232,201,125,0.02)}
.field-wrap:focus-within .field-icon{color:var(--gold)}
.field-input::placeholder{color:rgba(240,238,234,0.2)}
.field-input.input-error{border-color:rgba(255,77,109,0.45);box-shadow:0 0 0 3px rgba(255,77,109,0.07)}
.field-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--txt-muted);cursor:pointer;font-size:.88rem;padding:4px;border-radius:4px;transition:color .2s}
.field-toggle:hover{color:var(--gold)}

/* Extras */
.form-extras{display:flex;align-items:center;justify-content:space-between;font-size:0.77rem}
.checkbox-row{display:flex;align-items:center;gap:8px;cursor:pointer;color:var(--txt-secondary)}
.checkbox-row input{width:15px;height:15px;accent-color:var(--gold);cursor:pointer}
.forgot-link{color:var(--gold);text-decoration:none;font-size:.75rem;opacity:.85;transition:opacity .2s}
.forgot-link:hover{opacity:1;text-decoration:underline}

/* Submit */
.btn-submit{
  width:100%;padding:14px;margin-top:.25rem;
  background:linear-gradient(135deg,var(--gold) 0%,var(--ember) 100%);
  border:none;border-radius:var(--radius);color:var(--ink);font-family:'Clash Display',sans-serif;
  font-size:.92rem;font-weight:700;letter-spacing:.03em;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:8px;
  box-shadow:0 8px 32px rgba(232,201,125,0.22),0 2px 8px rgba(0,0,0,0.35);
  transition:transform .22s var(--ease-spring),box-shadow .22s,opacity .22s;
  position:relative;overflow:hidden;
}
.btn-submit::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,0.14),transparent);opacity:0;transition:opacity .22s}
.btn-submit:hover:not(:disabled){transform:translateY(-3px);box-shadow:0 14px 44px rgba(232,201,125,0.35)}
.btn-submit:hover::before{opacity:1}
.btn-submit:active{transform:translateY(-1px)}
.btn-submit:disabled{opacity:.55;cursor:not-allowed;transform:none}
.btn-submit.loading{pointer-events:none;opacity:.7}
.btn-submit.loading .btn-label{display:none}
.btn-submit .spinner{width:18px;height:18px;border:2px solid rgba(7,11,20,0.3);border-top-color:var(--ink);border-radius:50%;animation:spin .7s linear infinite;display:none}
.btn-submit.loading .spinner{display:block}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-submit::after{content:'';position:absolute;top:0;left:-100%;width:55%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.18),transparent);animation:btnShimmer 2.8s ease-in-out infinite}
@keyframes btnShimmer{0%{left:-100%}100%{left:220%}}

/* Divider */
.divider{display:flex;align-items:center;gap:10px;margin:.6rem 0}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--glass-border)}
.divider span{font-family:'JetBrains Mono',monospace;font-size:0.65rem;color:var(--txt-muted);white-space:nowrap}

/* Demo */
.demo-grid{display:grid;grid-template-columns:1fr 1fr;gap:.65rem}
.btn-demo{
  display:flex;align-items:center;justify-content:center;gap:7px;padding:10px 12px;
  border-radius:var(--radius-sm);border:1px solid var(--glass-border);background:var(--glass);
  color:var(--txt-secondary);font-size:.76rem;font-weight:500;cursor:pointer;
  transition:border-color .2s,color .2s,background .2s,transform .2s var(--ease-spring);
  font-family:'Cabinet Grotesk',sans-serif;
}
.btn-demo:hover{border-color:rgba(232,201,125,0.3);color:var(--txt-primary);background:rgba(232,201,125,0.04);transform:translateY(-1px)}

/* Role chip */
.role-chip{display:inline-flex;align-items:center;gap:5px;font-family:'JetBrains Mono',monospace;font-size:0.6rem;padding:3px 11px;border-radius:100px;letter-spacing:0.04em;border:1px solid;margin-top:4px}
.chip-admin      {color:#fb923c;border-color:rgba(251,146,60,0.3);background:rgba(251,146,60,0.07)}
.chip-journaliste{color:var(--azure);border-color:rgba(74,158,255,0.3);background:rgba(74,158,255,0.07)}
.chip-lecteur    {color:var(--gold);border-color:rgba(232,201,125,0.3);background:rgba(232,201,125,0.07)}

/* Footer */
.form-footer{text-align:center;margin-top:1.4rem;font-size:.78rem;color:var(--txt-secondary)}
.form-footer a{color:var(--gold);text-decoration:none;font-weight:600;transition:opacity .2s}
.form-footer a:hover{opacity:.8;text-decoration:underline}
.btn-back{
  display:inline-flex;align-items:center;gap:6px;margin-top:.8rem;font-size:.73rem;color:var(--txt-muted);
  text-decoration:none;padding:6px 14px;border-radius:100px;border:1px solid var(--glass-border);
  background:transparent;transition:all .2s;
}
.btn-back:hover{border-color:rgba(232,201,125,0.25);color:var(--gold)}

/* Blocked state */
.blocked-overlay{
  display:flex;flex-direction:column;align-items:center;text-align:center;
  padding:1.5rem;background:rgba(245,158,11,0.05);border:1px solid rgba(245,158,11,0.2);
  border-radius:var(--radius);margin-top:.5rem;
}
.blocked-icon{font-size:2.5rem;margin-bottom:.75rem}
.blocked-title{font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;color:#fbbf24;margin-bottom:.4rem}
.blocked-msg{font-size:.78rem;color:var(--txt-secondary);line-height:1.7}
</style>
</head>
<body>

<div class="bg-orb orb-a"></div>
<div class="bg-orb orb-b"></div>
<div class="bg-orb orb-c"></div>
<div class="bg-grid"></div>

<div class="auth-wrapper">

  <!-- ── PROMO ── -->
  <div class="auth-promo">
    <div class="promo-bar"></div>
    <div class="promo-deco">📚</div>

    <a href="index.php" class="promo-logo">
      <div class="logo-mark">📚</div>
      Digital <span style="color:var(--gold);margin-left:3px">Library</span>
    </a>

    <div class="promo-body">
      <div class="promo-badge"><div class="badge-pulse"></div>Plateforme active — v4.0</div>
      <h2 class="promo-title">
        Votre univers littéraire<br>
        <span class="g1">intelligent</span> &amp; <span class="g2">moderne</span>
      </h2>
      <p class="promo-sub">Accédez à des milliers d'ouvrages, gérez votre collection et profitez de recommandations IA personnalisées.</p>
      <ul class="promo-features">
        <li><div class="feat-ic fi-g"><i class="bi bi-book-half"></i></div><span>Catalogue de 12 000+ ouvrages numériques</span></li>
        <li><div class="feat-ic fi-a"><i class="bi bi-robot"></i></div><span>Assistant IA pour recommandations personnalisées</span></li>
        <li><div class="feat-ic fi-s"><i class="bi bi-shield-check"></i></div><span>Plateforme sécurisée, données chiffrées</span></li>
        <li><div class="feat-ic fi-e"><i class="bi bi-gift"></i></div><span>1 livre offert après 5 achats</span></li>
      </ul>
    </div>

    <div class="promo-stats">
      <div class="p-stat"><div class="p-stat-val">12K+</div><div class="p-stat-lbl">Livres</div></div>
      <div class="p-stat"><div class="p-stat-val">3.4K</div><div class="p-stat-lbl">Lecteurs</div></div>
      <div class="p-stat"><div class="p-stat-val">98%</div><div class="p-stat-lbl">Satisf.</div></div>
    </div>
  </div>

  <!-- ── FORM ── -->
  <div class="auth-form">

    <div class="form-eyebrow">⚡ Accès sécurisé</div>
    <h1 class="form-title">Bon <span>retour</span> !</h1>
    <p class="form-sub">Entrez vos identifiants pour continuer votre aventure littéraire.</p>

    <?php
// Flash message (inscription réussie, bienvenue, etc.)
if (!empty($flash) && is_array($flash)):

    $type = $flash['type'] ?? 'success';
    $msg  = $flash['msg'] ?? '';

    if ($type === 'welcome'):
?>
    <div class="alert alert-welcome">
        <i class="bi bi-stars"></i>
        <div>
            <span class="alert-title">🎉 Bienvenue !</span>
            <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>

<?php elseif ($type === 'register_success'): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        <div>
            <span class="alert-title">🎊 Inscription réussie !</span>
            <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
            Vous pouvez maintenant vous connecter et explorer votre bibliothèque.
        </div>
    </div>

<?php elseif ($type === 'error'): ?>
    <div class="alert alert-error">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
    </div>

<?php else: ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php endif; ?>


<?php if ($isBlocked): ?>
<div class="blocked-overlay">
    <div class="blocked-icon">🔒</div>
    <div class="blocked-title">Compte bloqué</div>
    <p class="blocked-msg">
        Votre compte a été bloqué suite à trop de tentatives de connexion infructueuses.<br><br>
        Veuillez contacter l'administrateur de la plateforme pour débloquer votre accès.
    </p>
</div>

<?php elseif ($error): ?>
<div class="alert alert-error" id="errorAlert">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

        <?php if ($remainingHint !== null): ?>
            <div class="attempts-bar" id="attemptsBar"
                 data-remaining="<?= (int)$remainingHint ?>"></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
    <?php if (!$isBlocked): ?>
    <form method="POST" id="loginForm" novalidate autocomplete="on">

      <div class="fields">

        <div class="field-group">
          <label class="field-label" for="email"><i class="bi bi-envelope"></i> Adresse e-mail</label>
          <div class="field-wrap">
            <i class="bi bi-envelope field-icon"></i>
            <input type="email" id="email" name="email" class="field-input"
                   placeholder="vous@exemple.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   required autocomplete="email" maxlength="255">
          </div>
          <div id="roleIndicatorWrap"></div>
        </div>

        <div class="field-group">
          <label class="field-label" for="password"><i class="bi bi-lock"></i> Mot de passe</label>
          <div class="field-wrap">
            <i class="bi bi-lock field-icon"></i>
            <input type="password" id="password" name="password" class="field-input"
                   placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="field-toggle" onclick="togglePwd('password','eyeIcon')" aria-label="Afficher/masquer">
              <i class="bi bi-eye" id="eyeIcon"></i>
            </button>
          </div>
        </div>

        <div class="form-extras">
          <label class="checkbox-row">
            <input type="checkbox" name="remember" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
            Se souvenir de moi
          </label>
          <a href="forgot-password.php" class="forgot-link">Mot de passe oublié ?</a>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">
          <span class="btn-label"><i class="bi bi-box-arrow-in-right"></i> Se connecter</span>
          <div class="spinner"></div>
        </button>

      </div>
    </form>

    <div class="divider"><span>ou tester avec</span></div>

    <div class="demo-grid">
      <button class="btn-demo" onclick="fillDemo('admin@gmail.com','Admin2024!')">
        <i class="bi bi-shield-fill" style="color:#fb923c"></i> Compte Admin
      </button>
      <button class="btn-demo" onclick="fillDemo('journaliste@gmail.com','Pass2024!')">
        <i class="bi bi-pencil-fill" style="color:var(--azure)"></i> Journaliste
      </button>
    </div>
    <?php endif; ?>

    <div class="form-footer">
      Pas encore de compte ?
      <a href="register.php"><i class="bi bi-person-plus"></i> Créer un compte</a>
      <br>
      <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Retour à l'accueil</a>
    </div>

  </div>
</div>

<script>
// ── Toggle password ──────────────────────────────────────────
function togglePwd(inputId, iconId) {
  const inp = document.getElementById(inputId);
  const ico = document.getElementById(iconId);
  if (!inp || !ico) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}

// ── Attempts dots ────────────────────────────────────────────
const bar = document.getElementById('attemptsBar');
if (bar) {
  const remaining = parseInt(bar.dataset.remaining, 10);
  const maxShown  = remaining + (<?= (int)($remainingHint ?? 0) ?> - remaining) + (<?= (int)($remainingHint ?? 0) ?>);
  // We know: remaining = left, total = remaining + used
  // Re-derive from server value
  const used = bar.dataset.remaining !== undefined
    ? (<?= $remainingHint !== null ? "Math.round((document.getElementById('attemptsBar').dataset.remaining * 1))" : '0' ?>) : 0;
  // simpler: total dots = max possible, fill from left
  const total = 5; // visual max
  let html = '';
  for (let i = 0; i < total; i++) {
    const isUsed = i < (total - remaining);
    html += `<div class="attempt-dot${isUsed ? ' used' : ''}"></div>`;
  }
  bar.innerHTML = html;
}

// ── Role chip ────────────────────────────────────────────────
function detectRole(email) {
  email = email.toLowerCase();
  if (/^admin\.[a-z0-9]+@adminsopecam\.com$/.test(email)) return 'admin';
  if (/^journaliste\.[a-z0-9]+@sopecam\.com$/.test(email)) return 'journaliste';
  return 'lecteur';
}
const roleInfo = {
  admin:       {cls:'chip-admin',       icon:'🛡️', label:'Admin'},
  journaliste: {cls:'chip-journaliste', icon:'✍️',  label:'Journaliste SOPECAM'},
  lecteur:     {cls:'chip-lecteur',     icon:'📖', label:'Lecteur'},
};
const emailInput = document.getElementById('email');
const roleWrap   = document.getElementById('roleIndicatorWrap');
if (emailInput && roleWrap) {
  emailInput.addEventListener('input', function() {
    const val = this.value.trim();
    if (!val.includes('@') || !val.includes('.')) { roleWrap.innerHTML = ''; return; }
    const r = roleInfo[detectRole(val)];
    roleWrap.innerHTML = `<span class="role-chip ${r.cls}">${r.icon} ${r.label}</span>`;
  });
  // Trigger on load if pre-filled
  if (emailInput.value) emailInput.dispatchEvent(new Event('input'));
}

// ── Demo fill ────────────────────────────────────────────────
function fillDemo(email, pass) {
  const eEl = document.getElementById('email');
  const pEl = document.getElementById('password');
  if (eEl) { eEl.value = email; eEl.dispatchEvent(new Event('input')); }
  if (pEl) pEl.value = pass;
}

// ── Form submit ──────────────────────────────────────────────
document.getElementById('loginForm')?.addEventListener('submit', function(e) {
  const eEl = document.getElementById('email');
  const pEl = document.getElementById('password');
  const btn = document.getElementById('submitBtn');

  if (!eEl || !pEl) return;

  const email = eEl.value.trim();
  const pass  = pEl.value;

  eEl.classList.remove('input-error');
  pEl.classList.remove('input-error');

  if (!email) { eEl.classList.add('input-error'); eEl.focus(); e.preventDefault(); return; }
  if (!pass)  { pEl.classList.add('input-error'); pEl.focus(); e.preventDefault(); return; }

  if (btn) btn.classList.add('loading');
});

// ── Auto-dismiss flash after 6s ──────────────────────────────
setTimeout(() => {
  document.querySelectorAll('.alert-welcome, .alert-success').forEach(el => {
    el.style.transition = 'opacity .5s, transform .5s';
    el.style.opacity = '0';
    el.style.transform = 'translateY(-6px)';
    setTimeout(() => el.remove(), 500);
  });
}, 6000);
</script>
</body>

</html>