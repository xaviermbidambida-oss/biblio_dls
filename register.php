<?php
// ============================================================
// register.php — Design harmonisé avec index.php
// ============================================================
declare(strict_types=1);

require_once 'includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (function_exists('isLoggedIn') && isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error  = '';
$fields = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nom       = trim($_POST['nom'] ?? '');
    $prenom    = trim($_POST['prenom'] ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $telephone = trim($_POST['telephone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm'] ?? '';

    $fields = compact('nom', 'prenom', 'email', 'telephone');

    if (!$nom || !$prenom || !$email || !$password || !$confirm) {
        $error = 'Veuillez remplir tous les champs obligatoires.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Email invalide.";
    } elseif ($telephone && !isValidPhone($telephone)) {
        $error = 'Numéro de téléphone invalide.';
    } elseif (strlen($password) < 8) {
        $error = 'Mot de passe trop court (8 caractères minimum).';
    } elseif ($password !== $confirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        $db = getDB();
        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);

        if ($chk->fetch()) {
            $error = 'Cette adresse email est déjà utilisée.';
        } else {
            $role = 'lecteur';
            if (preg_match('/^admin\.[a-z0-9]+@adminsopecam\.com$/i', $email)) {
                $role = 'admin';
            } elseif (preg_match('/^journaliste\.[a-z0-9]+@sopecam\.com$/i', $email)) {
                $role = 'journaliste';
            }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmt = $db->prepare("
                INSERT INTO users
                (nom, prenom, email, password, telephone, role, statut, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'actif', NOW())
            ");

            $stmt->execute([$nom, $prenom, $email, $hash, $telephone ?: null, $role]);

            $msg = match ($role) {
                'admin'       => '🛡️ Compte Administrateur créé avec succès.',
                'journaliste' => '✍️ Compte Journaliste créé avec succès.',
                default       => '🎉 Compte créé avec succès !',
            };

            if (function_exists('setFlash')) {
                setFlash('success', $msg . ' Connectez-vous.');
            }

            header('Location: login.php');
            exit;
        }
    }
}

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Créer un compte — Digital Library</title>
<meta name="description" content="Rejoignez la bibliothèque numérique premium. Inscription gratuite.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ═══════════════════════════════════════
   RESET & TOKENS — identiques à index.php
═══════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#070b14;--paper:#0d1220;--slate:#131a2e;
  --mist:rgba(200,210,255,0.08);--fog:rgba(200,210,255,0.04);
  --gold:#e8c97d;--ember:#ff6b35;--sage:#4ecca3;--azure:#4a9eff;--plum:#9b59b6;
  --txt-primary:#f0eeea;--txt-secondary:rgba(240,238,234,0.55);--txt-muted:rgba(240,238,234,0.3);
  --glass:rgba(255,255,255,0.03);--glass-border:rgba(255,255,255,0.07);
  --glow-gold:0 0 40px rgba(232,201,125,0.15);
  --ease-spring:cubic-bezier(0.34,1.56,0.64,1);--ease-smooth:cubic-bezier(0.25,0.46,0.45,0.94);
  --red:#ff4d6d;
}
html{height:100%;scroll-behavior:smooth}
body{
  font-family:'Cabinet Grotesk',system-ui,sans-serif;
  background:var(--ink);color:var(--txt-primary);
  min-height:100vh;overflow-x:hidden;
  display:flex;align-items:flex-start;justify-content:center;
  padding:0 1.5rem 3rem;
}
::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-track{background:var(--ink)}
::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* ═══════════════════════════════════════
   BACKGROUND — identique index.php
═══════════════════════════════════════ */
body::before{
  content:'';position:fixed;inset:0;z-index:-1;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
  opacity:0.4;pointer-events:none;
}
.bg-orb{position:fixed;border-radius:50%;filter:blur(100px);pointer-events:none;z-index:-2;animation:orbDrift 25s ease-in-out infinite}
.orb-a{width:700px;height:700px;background:rgba(232,201,125,0.06);top:-250px;left:-200px}
.orb-b{width:550px;height:550px;background:rgba(74,158,255,0.05);bottom:-180px;right:-150px;animation-delay:-10s}
.orb-c{width:380px;height:380px;background:rgba(78,204,163,0.04);top:35%;left:58%;animation-delay:-16s}
@keyframes orbDrift{
  0%,100%{transform:translate(0,0) scale(1)}
  33%{transform:translate(50px,-40px) scale(1.08)}
  66%{transform:translate(-40px,50px) scale(0.93)}
}
.bg-dots{
  position:fixed;inset:0;z-index:-1;pointer-events:none;
  background-image:radial-gradient(circle,rgba(232,201,125,0.12) 1px,transparent 1px);
  background-size:36px 36px;
  mask-image:radial-gradient(ellipse 75% 75% at 50% 50%,black 10%,transparent 80%);
  opacity:.3;
}

/* ═══════════════════════════════════════
   TOP NAV
═══════════════════════════════════════ */
.top-nav{
  position:fixed;top:0;left:0;right:0;z-index:100;
  height:58px;padding:0 2rem;
  display:flex;align-items:center;justify-content:space-between;
  background:rgba(7,11,20,0.7);backdrop-filter:blur(24px) saturate(1.4);
  border-bottom:1px solid var(--glass-border);
}
.nav-logo{
  display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--txt-primary);
  font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600;letter-spacing:-0.5px;
}
.logo-mark{
  width:32px;height:32px;border-radius:8px;
  background:linear-gradient(135deg,var(--gold),var(--ember));
  display:flex;align-items:center;justify-content:center;font-size:0.9rem;
  box-shadow:0 0 20px rgba(232,201,125,0.3);
}
.nav-back{
  display:flex;align-items:center;gap:6px;font-size:0.8rem;font-weight:500;
  color:var(--txt-secondary);text-decoration:none;
  padding:7px 14px;border-radius:8px;border:1px solid var(--glass-border);
  transition:all 0.2s;
}
.nav-back:hover{border-color:rgba(232,201,125,0.3);color:var(--gold)}

/* ═══════════════════════════════════════
   MAIN LAYOUT — split 2 colonnes
═══════════════════════════════════════ */
.page-wrapper{
  width:100%;max-width:1060px;
  margin-top:80px;
  display:grid;grid-template-columns:1fr 1.2fr;
  gap:2rem;align-items:start;
  position:relative;z-index:1;
  animation:pageIn 0.7s var(--ease-spring) forwards;
}
@keyframes pageIn{
  from{opacity:0;transform:translateY(28px)}
  to  {opacity:1;transform:translateY(0)}
}
@media(max-width:860px){
  .page-wrapper{grid-template-columns:1fr;max-width:520px}
  .promo-panel{display:none}
}

/* ═══════════════════════════════════════
   PROMO PANEL (left column)
═══════════════════════════════════════ */
.promo-panel{
  background:linear-gradient(145deg,var(--slate),rgba(10,15,28,0.95));
  border:1px solid var(--glass-border);border-radius:24px;
  padding:2.8rem 2.4rem;
  display:flex;flex-direction:column;gap:2rem;
  position:relative;overflow:hidden;
  box-shadow:0 40px 80px rgba(0,0,0,0.5);
}
/* accent bar */
.promo-bar{
  position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--gold),var(--ember),var(--sage),var(--gold));
  background-size:300%;animation:barShimmer 4s linear infinite;
}
@keyframes barShimmer{to{background-position:300% center}}
.promo-deco{
  position:absolute;right:-30px;bottom:60px;
  font-size:8rem;opacity:0.05;
  animation:decoFloat 7s ease-in-out infinite;pointer-events:none;user-select:none;
}
@keyframes decoFloat{0%,100%{transform:translateY(0) rotate(-8deg)}50%{transform:translateY(-20px) rotate(6deg)}}

.promo-header{}
.status-label{
  display:inline-flex;align-items:center;gap:8px;
  font-family:'JetBrains Mono',monospace;font-size:0.65rem;
  color:var(--sage);letter-spacing:0.1em;
  border:1px solid rgba(78,204,163,0.22);background:rgba(78,204,163,0.05);
  padding:5px 14px;border-radius:100px;margin-bottom:1.5rem;
  animation:pulseBorder 3s ease-in-out infinite;
}
.pulse-dot{width:6px;height:6px;background:var(--sage);border-radius:50%;animation:blink 1.4s step-end infinite;flex-shrink:0}
@keyframes pulseBorder{0%,100%{box-shadow:none}50%{box-shadow:0 0 20px rgba(78,204,163,0.1)}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0.1}}

.promo-title{
  font-family:'Clash Display',sans-serif;font-size:1.9rem;font-weight:700;
  letter-spacing:-1.5px;line-height:1.1;margin-bottom:0.8rem;
}
.promo-title .g1{
  background:linear-gradient(135deg,var(--gold),var(--ember));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.promo-title .g2{
  background:linear-gradient(135deg,var(--sage),var(--azure));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.promo-sub{font-size:0.83rem;color:var(--txt-secondary);line-height:1.8}

/* Feature list */
.promo-features{list-style:none;display:flex;flex-direction:column;gap:0.7rem}
.promo-features li{
  display:flex;align-items:flex-start;gap:12px;
  padding:0.75rem 1rem;border-radius:12px;
  background:var(--glass);border:1px solid var(--glass-border);
  font-size:0.8rem;color:var(--txt-secondary);line-height:1.55;
  transition:border-color 0.2s,background 0.2s;
}
.promo-features li:hover{border-color:rgba(232,201,125,0.2);background:rgba(232,201,125,0.03)}
.feat-icon{
  width:32px;height:32px;border-radius:8px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:0.85rem;margin-top:1px;
}
.fi-gold{background:rgba(232,201,125,0.1);color:var(--gold)}
.fi-sage{background:rgba(78,204,163,0.1);color:var(--sage)}
.fi-azure{background:rgba(74,158,255,0.1);color:var(--azure)}
.fi-ember{background:rgba(255,107,53,0.1);color:var(--ember)}

/* Stats */
.promo-stats{
  display:grid;grid-template-columns:repeat(3,1fr);gap:0.7rem;
  padding-top:1.5rem;border-top:1px solid var(--glass-border);
}
.stat{text-align:center;padding:0.7rem 0.4rem;border-radius:10px;background:rgba(255,255,255,0.02)}
.stat-val{
  font-family:'Clash Display',sans-serif;font-size:1.35rem;font-weight:700;
  background:linear-gradient(135deg,var(--gold),var(--ember));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  letter-spacing:-0.5px;
}
.stat-lbl{font-size:0.63rem;color:var(--txt-muted);text-transform:uppercase;letter-spacing:0.08em;margin-top:3px}

/* ═══════════════════════════════════════
   AUTH CARD (right column)
═══════════════════════════════════════ */
.auth-card{
  background:linear-gradient(145deg,var(--slate),rgba(13,18,32,0.98));
  border:1px solid var(--glass-border);border-radius:24px;overflow:hidden;
  box-shadow:0 60px 120px rgba(0,0,0,0.55),0 0 0 1px rgba(232,201,125,0.04),inset 0 1px 0 rgba(255,255,255,0.04);
}
.card-bar{
  height:2px;width:100%;
  background:linear-gradient(90deg,var(--gold),var(--ember),var(--sage),var(--gold));
  background-size:300%;animation:barShimmer 4s linear infinite;
}
.card-inner{padding:2.4rem 2.4rem 2rem}

/* ═══════════════════════════════════════
   PROGRESS STEPS
═══════════════════════════════════════ */
.progress-steps{
  display:flex;align-items:center;gap:0;margin-bottom:1.8rem;
}
.step{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;position:relative}
.step:not(:last-child)::after{
  content:'';position:absolute;top:12px;left:calc(50% + 14px);right:calc(-50% + 14px);
  height:1px;background:var(--glass-border);z-index:0;transition:background 0.5s;
}
.step.done:not(:last-child)::after,.step.active:not(:last-child)::after{
  background:linear-gradient(90deg,rgba(232,201,125,0.5),transparent);
}
.step-dot{
  width:24px;height:24px;border-radius:50%;
  border:1.5px solid var(--glass-border);
  background:var(--slate);
  display:flex;align-items:center;justify-content:center;
  font-family:'JetBrains Mono',monospace;font-size:0.62rem;color:var(--txt-muted);
  position:relative;z-index:1;transition:all 0.3s var(--ease-spring);
}
.step.active .step-dot{border-color:var(--gold);background:rgba(232,201,125,0.12);color:var(--gold);box-shadow:0 0 14px rgba(232,201,125,0.25)}
.step.done   .step-dot{border-color:var(--sage);background:rgba(78,204,163,0.12);color:var(--sage)}
.step-lbl{font-family:'JetBrains Mono',monospace;font-size:0.57rem;color:var(--txt-muted);text-transform:uppercase;letter-spacing:0.07em}
.step.active .step-lbl{color:var(--gold)}
.step.done   .step-lbl{color:var(--sage)}

/* Header */
.card-eyebrow{
  font-family:'JetBrains Mono',monospace;font-size:0.65rem;
  letter-spacing:0.15em;color:var(--gold);text-transform:uppercase;
  display:flex;align-items:center;gap:8px;margin-bottom:0.6rem;
}
.card-eyebrow::before{content:'';width:16px;height:1px;background:var(--gold)}
.card-title{
  font-family:'Clash Display',sans-serif;font-size:1.75rem;font-weight:700;
  letter-spacing:-1.2px;line-height:1.1;margin-bottom:0.4rem;
}
.card-title .grad{
  background:linear-gradient(135deg,var(--gold) 20%,var(--ember));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.card-sub{font-size:0.82rem;color:var(--txt-secondary);margin-bottom:1.6rem;line-height:1.7}

/* ═══════════════════════════════════════
   ALERTS
═══════════════════════════════════════ */
.alert{
  display:flex;align-items:flex-start;gap:10px;
  padding:12px 16px;border-radius:12px;
  font-size:0.8rem;line-height:1.65;margin-bottom:1.3rem;
}
.alert-error  {background:rgba(255,77,109,0.08);border:1px solid rgba(255,77,109,0.22);color:#ff8a9d}
.alert-success{background:rgba(78,204,163,0.07);border:1px solid rgba(78,204,163,0.22);color:var(--sage)}
.alert i{flex-shrink:0;font-size:0.95rem;margin-top:2px}

/* ═══════════════════════════════════════
   FORM FIELDS
═══════════════════════════════════════ */
.fields{display:flex;flex-direction:column;gap:0.9rem}
.fields-row{display:grid;grid-template-columns:1fr 1fr;gap:0.9rem}
.field-group{display:flex;flex-direction:column;gap:0.35rem}
.field-label{
  font-size:0.7rem;font-weight:600;letter-spacing:0.06em;
  color:var(--txt-secondary);display:flex;align-items:center;gap:6px;
}
.field-label .req{color:var(--ember);margin-left:2px}
.field-label .opt{
  font-size:0.6rem;font-family:'JetBrains Mono',monospace;
  color:var(--txt-muted);background:rgba(255,255,255,0.05);
  padding:1px 6px;border-radius:4px;letter-spacing:0.04em;
}
.field-wrap{position:relative}
.field-icon{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--txt-muted);font-size:0.88rem;pointer-events:none;
  transition:color 0.2s;
}
.field-wrap:focus-within .field-icon{color:var(--gold)}
.field-input{
  width:100%;padding:12px 14px 12px 42px;
  background:var(--fog);
  border:1px solid var(--glass-border);
  border-radius:12px;
  color:var(--txt-primary);
  font-family:'Cabinet Grotesk',sans-serif;font-size:0.86rem;
  outline:none;
  transition:border-color 0.25s,box-shadow 0.25s,background 0.25s;
}
.field-input:focus{
  border-color:rgba(232,201,125,0.4);
  box-shadow:0 0 0 3px rgba(232,201,125,0.07),0 0 24px rgba(232,201,125,0.05);
  background:rgba(232,201,125,0.02);
}
.field-input::placeholder{color:var(--txt-muted)}
.field-input.is-valid  {border-color:rgba(78,204,163,0.35);background:rgba(78,204,163,0.02)}
.field-input.is-invalid{border-color:rgba(255,77,109,0.35);background:rgba(255,77,109,0.02)}
.field-status{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  font-size:0.8rem;opacity:0;transition:opacity 0.25s;pointer-events:none;
}
.field-status.show{opacity:1}
.field-status.ok {color:var(--sage)}
.field-status.err{color:var(--red)}
.field-hint{font-size:0.67rem;color:var(--txt-muted);min-height:15px;transition:color 0.2s}
.field-hint.ok {color:var(--sage)}
.field-hint.err{color:var(--red)}
/* toggle pwd */
.field-toggle{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;color:var(--txt-muted);cursor:pointer;
  font-size:0.88rem;padding:4px;border-radius:6px;
  transition:color 0.2s;
}
.field-toggle:hover{color:var(--gold)}

/* Password strength */
.pwd-strength{margin-top:5px;display:none}
.pwd-bar{display:flex;gap:4px;margin-bottom:5px}
.pwd-bar span{flex:1;height:3px;border-radius:10px;background:var(--glass-border);transition:background 0.3s}
.pwd-lbl{font-family:'JetBrains Mono',monospace;font-size:0.63rem;color:var(--txt-muted);letter-spacing:0.05em;transition:color 0.3s}
.s1 span:nth-child(1){background:var(--red)}
.s2 span:nth-child(-n+2){background:var(--ember)}
.s3 span:nth-child(-n+3){background:var(--gold)}
.s4 span{background:var(--sage)}
.s1 .pwd-lbl{color:var(--red)}.s2 .pwd-lbl{color:var(--ember)}.s3 .pwd-lbl{color:var(--gold)}.s4 .pwd-lbl{color:var(--sage)}

/* Role preview */
.role-preview{margin-top:5px;display:none}
.role-chip{
  display:inline-flex;align-items:center;gap:6px;
  font-family:'JetBrains Mono',monospace;font-size:0.65rem;
  padding:4px 12px;border-radius:100px;
  transition:all 0.3s;
}

/* Terms */
.terms-row{
  display:flex;align-items:flex-start;gap:9px;
  font-size:0.75rem;color:var(--txt-secondary);line-height:1.65;cursor:pointer;
}
.terms-row input{width:15px;height:15px;accent-color:var(--gold);cursor:pointer;margin-top:2px;flex-shrink:0}
.terms-row a{color:var(--gold);text-decoration:none;transition:opacity 0.2s}
.terms-row a:hover{opacity:0.8}

/* ═══════════════════════════════════════
   SUBMIT — identique index.php
═══════════════════════════════════════ */
.btn-submit{
  width:100%;padding:14px;margin-top:0.5rem;
  background:linear-gradient(135deg,var(--gold) 0%,var(--ember) 100%);
  border:none;border-radius:12px;
  color:var(--ink);
  font-family:'Clash Display',sans-serif;font-size:0.95rem;font-weight:700;
  letter-spacing:-0.3px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:9px;
  box-shadow:0 8px 32px rgba(232,201,125,0.22),0 2px 8px rgba(0,0,0,0.3);
  transition:transform 0.2s var(--ease-spring),box-shadow 0.2s,opacity 0.2s;
  position:relative;overflow:hidden;
}
.btn-submit::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(135deg,rgba(255,255,255,0.15),transparent);
  opacity:0;transition:opacity 0.2s;
}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 16px 48px rgba(232,201,125,0.32)}
.btn-submit:hover::before{opacity:1}
.btn-submit:active{transform:translateY(0)}
.btn-submit.loading{opacity:0.65;pointer-events:none}
.btn-submit.loading .btn-text{display:none}
.btn-submit .spinner{
  width:18px;height:18px;
  border:2px solid rgba(7,11,20,0.3);border-top-color:var(--ink);
  border-radius:50%;animation:spin 0.7s linear infinite;display:none;
}
.btn-submit.loading .spinner{display:block}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-submit::after{
  content:'';position:absolute;top:0;left:-100%;width:60%;height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,0.14),transparent);
  animation:btnShimmer 2.8s ease-in-out infinite;
}
@keyframes btnShimmer{0%{left:-100%}100%{left:200%}}

/* ═══════════════════════════════════════
   PERKS (sous le bouton)
═══════════════════════════════════════ */
.perks{
  display:grid;grid-template-columns:1fr 1fr;gap:0.55rem;margin-top:1rem;
  padding-top:1rem;border-top:1px solid var(--glass-border);
}
.perk{
  display:flex;align-items:center;gap:7px;
  padding:8px 10px;border-radius:10px;
  background:var(--glass);border:1px solid var(--glass-border);
  font-size:0.73rem;color:var(--txt-secondary);
  transition:border-color 0.2s,background 0.2s;
}
.perk:hover{border-color:rgba(232,201,125,0.2);background:rgba(232,201,125,0.03)}
.perk-icon{font-size:0.95rem;flex-shrink:0}

/* Footer */
.card-footer{
  text-align:center;padding:1.4rem 2.4rem 2rem;
  border-top:1px solid var(--glass-border);
  font-size:0.8rem;color:var(--txt-secondary);
  background:rgba(0,0,0,0.18);
}
.card-footer a{color:var(--gold);text-decoration:none;font-weight:700;transition:opacity 0.2s}
.card-footer a:hover{opacity:0.8}

/* Divider */
.divider{display:flex;align-items:center;gap:10px;margin:0.4rem 0 0.7rem}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--glass-border)}
.divider span{font-family:'JetBrains Mono',monospace;font-size:0.63rem;color:var(--txt-muted);white-space:nowrap;letter-spacing:0.07em}

/* Responsive */
@media(max-width:520px){
  .card-inner{padding:1.8rem 1.4rem 1.6rem}
  .card-footer{padding:1.2rem 1.4rem 1.7rem}
  .fields-row{grid-template-columns:1fr}
  .perks{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- Background -->
<div class="bg-orb orb-a"></div>
<div class="bg-orb orb-b"></div>
<div class="bg-orb orb-c"></div>
<div class="bg-dots"></div>

<!-- Top nav -->
<nav class="top-nav">
  <a href="index.php" class="nav-logo">
    <div class="logo-mark">📚</div>
    Digital <span style="color:var(--gold)">&nbsp;Library</span>
  </a>
  <a href="index.php" class="nav-back">
    <i class="bi bi-arrow-left"></i> Accueil
  </a>
</nav>

<!-- ═══════════════════════════════
     MAIN PAGE
═══════════════════════════════ -->
<div class="page-wrapper">

  <!-- ── PROMO PANEL (gauche) ── -->
  <div class="promo-panel">
    <div class="promo-bar"></div>
    <div class="promo-deco">📚</div>

    <div class="promo-header">
      <div class="status-label">
        <span class="pulse-dot"></span>
        Inscription gratuite — Sans CB
      </div>
      <h2 class="promo-title">
        Rejoignez<br>
        <span class="g1">12 000+ lecteurs</span><br>
        <span class="g2">passionnés</span>
      </h2>
      <p class="promo-sub">
        Créez votre compte en 60 secondes et accédez à la bibliothèque numérique la plus moderne.
      </p>
    </div>

    <ul class="promo-features">
      <li>
        <div class="feat-icon fi-gold"><i class="bi bi-lightning-charge-fill"></i></div>
        <span>Accès immédiat dès l'inscription — aucune attente</span>
      </li>
      <li>
        <div class="feat-icon fi-azure"><i class="bi bi-book-half"></i></div>
        <span>Catalogue illimité — 12 000+ ouvrages numériques</span>
      </li>
      <li>
        <div class="feat-icon fi-sage"><i class="bi bi-robot"></i></div>
        <span>Recommandations IA personnalisées dès le départ</span>
      </li>
      <li>
        <div class="feat-icon fi-ember"><i class="bi bi-gift-fill"></i></div>
        <span>1 livre offert à l'inscription, sans conditions</span>
      </li>
    </ul>

    <div class="promo-stats">
      <div class="stat">
        <div class="stat-val">12K+</div>
        <div class="stat-lbl">Livres</div>
      </div>
      <div class="stat">
        <div class="stat-val">3.4K</div>
        <div class="stat-lbl">Membres</div>
      </div>
      <div class="stat">
        <div class="stat-val">4.9★</div>
        <div class="stat-lbl">Notation</div>
      </div>
    </div>
  </div>

  <!-- ── AUTH CARD (droite) ── -->
  <div class="auth-card">
    <div class="card-bar"></div>

    <div class="card-inner">

      <!-- Progress steps -->
      <div class="progress-steps">
        <div class="step active" id="step1">
          <div class="step-dot">1</div>
          <span class="step-lbl">Identité</span>
        </div>
        <div class="step" id="step2">
          <div class="step-dot">2</div>
          <span class="step-lbl">Contact</span>
        </div>
        <div class="step" id="step3">
          <div class="step-dot">3</div>
          <span class="step-lbl">Sécurité</span>
        </div>
      </div>

      <div class="card-eyebrow">Nouveau compte</div>
      <h1 class="card-title">Créer mon <span class="grad">espace</span></h1>
      <p class="card-sub">Remplissez les informations ci-dessous — c'est rapide !</p>

      <!-- Error -->
      <?php if ($error): ?>
        <div class="alert alert-error">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <!-- Form -->
      <form method="POST" id="registerForm" novalidate autocomplete="on">
        <div class="fields">

          <!-- Prénom + Nom -->
          <div class="fields-row">
            <div class="field-group">
              <label class="field-label" for="prenom">
                <i class="bi bi-person"></i> Prénom <span class="req">*</span>
              </label>
              <div class="field-wrap">
                <i class="bi bi-person field-icon"></i>
                <input type="text" id="prenom" name="prenom" class="field-input"
                       placeholder="Jean"
                       value="<?= e($fields['prenom'] ?? '') ?>"
                       required autocomplete="given-name" maxlength="150">
                <span class="field-status" id="prenomSt"></span>
              </div>
              <span class="field-hint" id="prenomHint"></span>
            </div>

            <div class="field-group">
              <label class="field-label" for="nom">
                <i class="bi bi-person-fill"></i> Nom <span class="req">*</span>
              </label>
              <div class="field-wrap">
                <i class="bi bi-person-fill field-icon"></i>
                <input type="text" id="nom" name="nom" class="field-input"
                       placeholder="Dupont"
                       value="<?= e($fields['nom'] ?? '') ?>"
                       required autocomplete="family-name" maxlength="150">
                <span class="field-status" id="nomSt"></span>
              </div>
              <span class="field-hint" id="nomHint"></span>
            </div>
          </div>

          <!-- Email -->
          <div class="field-group">
            <label class="field-label" for="email">
              <i class="bi bi-envelope"></i> Adresse e-mail <span class="req">*</span>
            </label>
            <div class="field-wrap">
              <i class="bi bi-envelope field-icon"></i>
              <input type="email" id="email" name="email" class="field-input"
                     placeholder="vous@exemple.com"
                     value="<?= e($fields['email'] ?? '') ?>"
                     required autocomplete="email" maxlength="255">
              <span class="field-status" id="emailSt"></span>
            </div>
            <span class="field-hint" id="emailHint"></span>
            <div class="role-preview" id="rolePreview"></div>
          </div>

          <!-- Téléphone -->
          <div class="field-group">
            <label class="field-label" for="telephone">
              <i class="bi bi-telephone"></i> Téléphone <span class="opt">Optionnel</span>
            </label>
            <div class="field-wrap">
              <i class="bi bi-telephone field-icon"></i>
              <input type="tel" id="telephone" name="telephone" class="field-input"
                     placeholder="+237 6XX XXX XXX"
                     value="<?= e($fields['telephone'] ?? '') ?>"
                     autocomplete="tel" maxlength="20">
              <span class="field-status" id="telSt"></span>
            </div>
            <span class="field-hint" id="telHint"></span>
          </div>

          <!-- Mot de passe -->
          <div class="field-group">
            <label class="field-label" for="password">
              <i class="bi bi-lock"></i> Mot de passe <span class="req">*</span>
            </label>
            <div class="field-wrap">
              <i class="bi bi-lock field-icon"></i>
              <input type="password" id="password" name="password" class="field-input"
                     placeholder="Minimum 8 caractères"
                     required autocomplete="new-password">
              <button type="button" class="field-toggle"
                      onclick="togglePwd('password','eye1')" aria-label="Voir le mot de passe">
                <i class="bi bi-eye" id="eye1"></i>
              </button>
            </div>
            <div class="pwd-strength" id="pwdStrength">
              <div class="pwd-bar"><span></span><span></span><span></span><span></span></div>
              <div class="pwd-lbl" id="pwdLbl"></div>
            </div>
          </div>

          <!-- Confirmation -->
          <div class="field-group">
            <label class="field-label" for="confirm">
              <i class="bi bi-lock-fill"></i> Confirmer le mot de passe <span class="req">*</span>
            </label>
            <div class="field-wrap">
              <i class="bi bi-lock-fill field-icon"></i>
              <input type="password" id="confirm" name="confirm" class="field-input"
                     placeholder="Répétez le mot de passe"
                     required autocomplete="new-password">
              <button type="button" class="field-toggle"
                      onclick="togglePwd('confirm','eye2')" aria-label="Voir le mot de passe">
                <i class="bi bi-eye" id="eye2"></i>
              </button>
              <span class="field-status" id="confirmSt"></span>
            </div>
            <span class="field-hint" id="confirmHint"></span>
          </div>

          <!-- CGU -->
          <label class="terms-row" for="terms">
            <input type="checkbox" id="terms" name="terms" required>
            <span>
              J'accepte les <a href="#" onclick="return false">conditions d'utilisation</a>
              et la <a href="#" onclick="return false">politique de confidentialité</a>.
            </span>
          </label>

          <!-- Submit -->
          <button type="submit" class="btn-submit" id="submitBtn">
            <span class="btn-text">
              <i class="bi bi-rocket-takeoff"></i> Créer mon compte
            </span>
            <div class="spinner"></div>
          </button>

          <!-- Divider -->
          <div class="divider"><span>ce que vous obtenez</span></div>

          <!-- Perks -->
          <div class="perks">
            <div class="perk"><span class="perk-icon">🎁</span> 1 livre offert</div>
            <div class="perk"><span class="perk-icon">🔒</span> Données chiffrées</div>
            <div class="perk"><span class="perk-icon">🤖</span> IA incluse</div>
            <div class="perk"><span class="perk-icon">♾️</span> Accès illimité</div>
          </div>

        </div>
      </form>
    </div>

    <!-- Footer -->
    <div class="card-footer">
      Déjà un compte ?
      <a href="login.php"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
    </div>
  </div>

</div><!-- /.page-wrapper -->

<script>
'use strict';

/* ── Toggle password ── */
function togglePwd(id, iconId) {
  const inp = document.getElementById(id);
  const ico = document.getElementById(iconId);
  const show = inp.type === 'password';
  inp.type   = show ? 'text' : 'password';
  ico.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}

/* ── Field status helper ── */
function setStatus(inputEl, stEl, hintEl, state, msg) {
  inputEl.classList.remove('is-valid','is-invalid');
  if (stEl) stEl.className = 'field-status';
  if (state === 'ok') {
    inputEl.classList.add('is-valid');
    if (stEl)   { stEl.innerHTML = '<i class="bi bi-check-circle-fill"></i>'; stEl.className = 'field-status show ok'; }
    if (hintEl) { hintEl.textContent = msg || ''; hintEl.className = 'field-hint ok'; }
  } else if (state === 'err') {
    inputEl.classList.add('is-invalid');
    if (stEl)   { stEl.innerHTML = '<i class="bi bi-x-circle-fill"></i>'; stEl.className = 'field-status show err'; }
    if (hintEl) { hintEl.textContent = msg || ''; hintEl.className = 'field-hint err'; }
  } else {
    if (hintEl) { hintEl.textContent = ''; hintEl.className = 'field-hint'; }
  }
}

/* ── Progress steps ── */
let pwdScore = 0;
function updateProgress() {
  const ok1 = document.getElementById('prenom').classList.contains('is-valid');
  const ok2 = document.getElementById('nom').classList.contains('is-valid');
  const ok3 = document.getElementById('email').classList.contains('is-valid');
  const ok4 = document.getElementById('confirm').classList.contains('is-valid');
  const s1  = document.getElementById('step1');
  const s2  = document.getElementById('step2');
  const s3  = document.getElementById('step3');

  if (ok1 && ok2) {
    s1.className = 'step done';
    if (ok3) {
      s2.className = 'step done';
      s3.className = (pwdScore >= 2 && ok4) ? 'step done' : 'step active';
    } else {
      s2.className = 'step active'; s3.className = 'step';
    }
  } else {
    s1.className = 'step active'; s2.className = 'step'; s3.className = 'step';
  }
}

/* ── Prénom ── */
document.getElementById('prenom').addEventListener('blur', function() {
  const v = this.value.trim();
  if (!v || v.length < 2) setStatus(this, document.getElementById('prenomSt'), document.getElementById('prenomHint'), 'err', v ? 'Minimum 2 caractères.' : 'Champ requis.');
  else setStatus(this, document.getElementById('prenomSt'), document.getElementById('prenomHint'), 'ok', '');
  updateProgress();
});

/* ── Nom ── */
document.getElementById('nom').addEventListener('blur', function() {
  const v = this.value.trim();
  if (!v || v.length < 2) setStatus(this, document.getElementById('nomSt'), document.getElementById('nomHint'), 'err', v ? 'Minimum 2 caractères.' : 'Champ requis.');
  else setStatus(this, document.getElementById('nomSt'), document.getElementById('nomHint'), 'ok', '');
  updateProgress();
});

/* ── Email + rôle preview ── */
const roleStyles = {
  admin:       { bg:'rgba(232,201,125,0.1)', border:'rgba(232,201,125,0.3)', color:'#e8c97d', icon:'🛡️', label:'Compte Admin détecté' },
  journaliste: { bg:'rgba(74,158,255,0.1)',  border:'rgba(74,158,255,0.3)',  color:'#4a9eff', icon:'✍️', label:'Journaliste SOPECAM' },
  lecteur:     { bg:'rgba(78,204,163,0.08)', border:'rgba(78,204,163,0.25)',color:'#4ecca3', icon:'📖', label:'Compte Lecteur' },
};
function detectRole(email) {
  email = email.toLowerCase();
  if (/^admin\.[a-z0-9]+@adminsopecam\.com$/.test(email)) return 'admin';
  if (/^journaliste\.[a-z0-9]+@sopecam\.com$/.test(email)) return 'journaliste';
  return 'lecteur';
}
const emailInput  = document.getElementById('email');
const rolePreview = document.getElementById('rolePreview');
emailInput.addEventListener('input', function() {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const val = this.value.trim();
  if (!val.includes('@')) { rolePreview.style.display = 'none'; return; }
  if (re.test(val)) {
    setStatus(this, document.getElementById('emailSt'), document.getElementById('emailHint'), 'ok', 'Email valide ✓');
    const role = detectRole(val);
    const s = roleStyles[role];
    rolePreview.style.display = 'block';
    rolePreview.innerHTML = `<span class="role-chip" style="background:${s.bg};border:1px solid ${s.border};color:${s.color}">${s.icon} ${s.label}</span>`;
  } else {
    rolePreview.style.display = 'none';
  }
  updateProgress();
});
emailInput.addEventListener('blur', function() {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const val = this.value.trim();
  if (!val) setStatus(this, document.getElementById('emailSt'), document.getElementById('emailHint'), 'err', "L'email est requis.");
  else if (!re.test(val)) setStatus(this, document.getElementById('emailSt'), document.getElementById('emailHint'), 'err', 'Format invalide.');
  else setStatus(this, document.getElementById('emailSt'), document.getElementById('emailHint'), 'ok', 'Email valide ✓');
  updateProgress();
});

/* ── Téléphone ── */
document.getElementById('telephone').addEventListener('blur', function() {
  const v = this.value.trim();
  if (!v) { setStatus(this, document.getElementById('telSt'), document.getElementById('telHint'), null); return; }
  const clean = v.replace(/[\s\-\.]/g,'');
  if (/^\+?[0-9]{8,15}$/.test(clean)) setStatus(this, document.getElementById('telSt'), document.getElementById('telHint'), 'ok', 'Numéro valide ✓');
  else setStatus(this, document.getElementById('telSt'), document.getElementById('telHint'), 'err', 'Format invalide (ex: +237 6XX XXX XXX).');
});

/* ── Password strength ── */
const pwdInput   = document.getElementById('password');
const pwdStrBox  = document.getElementById('pwdStrength');
const pwdBar     = document.querySelector('.pwd-bar');
const pwdLbl     = document.getElementById('pwdLbl');
const lblNames   = ['','Trop faible','Faible','Moyen','Fort'];
function calcStrength(p) {
  let s = 0;
  if (p.length >= 8)           s++;
  if (/[A-Z]/.test(p))        s++;
  if (/[0-9]/.test(p))        s++;
  if (/[^A-Za-z0-9]/.test(p)) s++;
  return s;
}
pwdInput.addEventListener('input', function() {
  if (!this.value) { pwdStrBox.style.display='none'; pwdScore=0; return; }
  pwdStrBox.style.display = 'block';
  pwdScore = calcStrength(this.value);
  pwdBar.className = 'pwd-bar s' + pwdScore;
  pwdLbl.textContent = lblNames[pwdScore] || '';
  checkConfirm();
  updateProgress();
});

/* ── Confirm ── */
const confirmInput = document.getElementById('confirm');
function checkConfirm() {
  const c = confirmInput.value;
  if (!c) { setStatus(confirmInput, document.getElementById('confirmSt'), document.getElementById('confirmHint'), null); return; }
  if (pwdInput.value === c)
    setStatus(confirmInput, document.getElementById('confirmSt'), document.getElementById('confirmHint'), 'ok', 'Les mots de passe correspondent ✓');
  else
    setStatus(confirmInput, document.getElementById('confirmSt'), document.getElementById('confirmHint'), 'err', 'Les mots de passe ne correspondent pas.');
  updateProgress();
}
confirmInput.addEventListener('input', checkConfirm);
confirmInput.addEventListener('blur',  checkConfirm);

/* ── Submit guard ── */
document.getElementById('registerForm').addEventListener('submit', function(e) {
  const terms = document.getElementById('terms');
  if (!terms.checked) {
    e.preventDefault();
    alert('Veuillez accepter les conditions d\'utilisation avant de continuer.');
    return;
  }
  document.getElementById('submitBtn').classList.add('loading');
});
</script>
</body>
</html>