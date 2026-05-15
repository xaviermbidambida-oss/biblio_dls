<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin    = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$username   = 'Utilisateur';
if ($isLoggedIn) {
    $prenom     = trim($_SESSION['user_prenom'] ?? '');
    $nomComplet = trim($_SESSION['username'] ?? $_SESSION['user_name'] ?? '');
    if ($prenom && $nomComplet) {
        $username = (stripos($nomComplet, $prenom) === 0) ? $nomComplet : "$prenom $nomComplet";
    } elseif ($nomComplet) { $username = $nomComplet; }
    elseif ($prenom)       { $username = $prenom; }
    $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Centre d'Aide — Digital Library System</title>
<meta name="description" content="Trouvez des réponses à toutes vos questions sur Digital Library System.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#070b14;--paper:#0d1220;--slate:#131a2e;
  --mist:rgba(200,210,255,0.08);--fog:rgba(200,210,255,0.04);
  --gold:#e8c97d;--ember:#ff6b35;--sage:#4ecca3;--azure:#4a9eff;--plum:#9b59b6;
  --txt-primary:#f0eeea;--txt-secondary:rgba(240,238,234,0.55);--txt-muted:rgba(240,238,234,0.3);
  --glass:rgba(255,255,255,0.03);--glass-border:rgba(255,255,255,0.07);--glass-hover:rgba(255,255,255,0.06);
  --ease-spring:cubic-bezier(0.34,1.56,0.64,1);--ease-smooth:cubic-bezier(0.25,0.46,0.45,0.94);
}
html{scroll-behavior:smooth}
body{font-family:'Cabinet Grotesk',system-ui,sans-serif;background:var(--ink);color:var(--txt-primary);overflow-x:hidden;line-height:1.6}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-track{background:var(--ink)}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* NOISE + ORBS */
body::before{content:'';position:fixed;inset:0;z-index:-1;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");opacity:0.4;pointer-events:none}
.bg-orb{position:fixed;border-radius:50%;filter:blur(100px);pointer-events:none;z-index:-2;animation:orbDrift 25s ease-in-out infinite}
.orb-a{width:600px;height:600px;background:rgba(232,201,125,0.05);top:-200px;left:-100px}
.orb-b{width:500px;height:500px;background:rgba(74,158,255,0.05);bottom:-150px;right:-100px;animation-delay:-10s}
.orb-c{width:350px;height:350px;background:rgba(78,204,163,0.04);top:50%;left:50%;animation-delay:-18s}
@keyframes orbDrift{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(50px,-40px) scale(1.08)}66%{transform:translate(-40px,50px) scale(0.93)}}

/* HEADER */
#site-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:62px;padding:0 2rem;display:flex;align-items:center;justify-content:space-between;background:rgba(7,11,20,0.7);backdrop-filter:blur(24px) saturate(1.4);border-bottom:1px solid var(--glass-border);transition:background 0.3s}
#site-header.scrolled{background:rgba(7,11,20,0.95)}
.nav-logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--txt-primary);font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600;letter-spacing:-0.5px}
.logo-mark{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));display:flex;align-items:center;justify-content:center;font-size:0.9rem;box-shadow:0 0 20px rgba(232,201,125,0.3)}
.nav-links{display:flex;align-items:center;gap:2px;list-style:none}
.nav-links a{font-size:0.8rem;font-weight:500;color:var(--txt-secondary);text-decoration:none;padding:6px 14px;border-radius:8px;transition:color 0.2s,background 0.2s}
.nav-links a:hover,.nav-links a.active{color:var(--txt-primary);background:var(--mist)}
.nav-links a.active{color:var(--gold)}
.nav-actions{display:flex;align-items:center;gap:0.6rem}
.btn-ghost{font-size:0.78rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;padding:7px 16px;border-radius:8px;border:1px solid var(--glass-border);transition:all 0.2s}
.btn-ghost:hover{border-color:rgba(232,201,125,0.3);color:var(--gold)}
.btn-cta-nav{font-size:0.78rem;font-weight:700;color:var(--ink);text-decoration:none;padding:7px 18px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));transition:opacity 0.2s,transform 0.2s;box-shadow:0 4px 16px rgba(232,201,125,0.25)}
.btn-cta-nav:hover{opacity:0.88;transform:translateY(-1px)}
.user-chip{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 5px;background:var(--mist);border:1px solid var(--glass-border);border-radius:100px;text-decoration:none;color:var(--txt-primary);font-size:0.78rem;font-weight:600;transition:all 0.2s}
.user-avatar{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--plum),var(--azure));display:flex;align-items:center;justify-content:center;font-size:0.7rem;font-weight:700;color:#fff}
#hamburger{display:none;background:none;border:none;color:var(--txt-primary);font-size:1.3rem;cursor:pointer;padding:4px}
#mobile-nav{display:none;position:fixed;inset:0;top:62px;background:rgba(7,11,20,0.98);backdrop-filter:blur(24px);z-index:998;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem}
#mobile-nav.open{display:flex}
#mobile-nav a{font-family:'Clash Display',sans-serif;font-size:1.6rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;transition:color 0.2s}
#mobile-nav a:hover{color:var(--gold)}
@media(max-width:900px){.nav-links,.nav-actions{display:none}#hamburger{display:block}}

/* PAGE HERO */
.page-hero{padding:140px 2rem 80px;text-align:center;position:relative;overflow:hidden}
.page-hero::before{content:'';position:absolute;width:600px;height:600px;background:radial-gradient(circle,rgba(232,201,125,0.06) 0%,transparent 70%);top:50%;left:50%;transform:translate(-50%,-50%);pointer-events:none}
.hero-eyebrow{display:inline-flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:0.68rem;color:var(--sage);letter-spacing:0.12em;border:1px solid rgba(78,204,163,0.25);background:rgba(78,204,163,0.05);padding:6px 16px;border-radius:100px;margin-bottom:1.5rem}
.pulse-dot{width:6px;height:6px;background:var(--sage);border-radius:50%;animation:blink 1.4s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0.1}}
.page-hero h1{font-family:'Clash Display',sans-serif;font-size:clamp(2.2rem,6vw,4.5rem);font-weight:700;letter-spacing:-2px;line-height:1.05;margin-bottom:1rem}
.page-hero h1 span{background:linear-gradient(135deg,var(--gold) 20%,var(--ember) 80%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.page-hero p{font-size:1rem;color:var(--txt-secondary);max-width:540px;margin:0 auto 2.5rem;line-height:1.8}

/* SEARCH BAR */
.search-wrap{max-width:580px;margin:0 auto;position:relative;z-index:1}
.search-bar{display:flex;align-items:center;gap:0;background:rgba(255,255,255,0.04);border:1px solid var(--glass-border);border-radius:14px;overflow:hidden;transition:border-color 0.3s,box-shadow 0.3s;backdrop-filter:blur(8px)}
.search-bar:focus-within{border-color:rgba(232,201,125,0.4);box-shadow:0 0 0 3px rgba(232,201,125,0.06),0 8px 32px rgba(0,0,0,0.3)}
.search-icon-wrap{padding:0 16px;color:var(--txt-muted);font-size:1rem;flex-shrink:0}
.search-bar input{flex:1;background:none;border:none;padding:14px 0;font-family:'Cabinet Grotesk',sans-serif;font-size:0.92rem;color:var(--txt-primary);outline:none}
.search-bar input::placeholder{color:var(--txt-muted)}
.search-btn{padding:10px 22px;margin:5px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:0.82rem;font-weight:700;cursor:pointer;transition:opacity 0.2s,transform 0.2s;white-space:nowrap}
.search-btn:hover{opacity:0.88;transform:scale(1.02)}
.search-tags{display:flex;gap:0.5rem;justify-content:center;margin-top:1rem;flex-wrap:wrap}
.search-tag{font-size:0.72rem;padding:4px 12px;border-radius:100px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;transition:all 0.2s}
.search-tag:hover{border-color:rgba(232,201,125,0.3);color:var(--gold)}

/* SECTIONS */
.section{padding:5rem 2rem}
.container{max-width:1100px;margin:0 auto}
.section-eyebrow{font-family:'JetBrains Mono',monospace;font-size:0.68rem;letter-spacing:0.15em;color:var(--gold);text-transform:uppercase;margin-bottom:0.8rem;display:flex;align-items:center;gap:8px}
.section-eyebrow::before{content:'';width:20px;height:1px;background:var(--gold)}
.section-title{font-family:'Clash Display',sans-serif;font-size:clamp(1.6rem,3.5vw,2.5rem);font-weight:700;letter-spacing:-1.2px;line-height:1.1;margin-bottom:0.8rem}
.section-sub{font-size:0.9rem;color:var(--txt-secondary);max-width:520px;line-height:1.8}

/* HELP CARDS */
.help-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1.25rem;margin-top:2.5rem}
.help-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:20px;padding:1.8rem;cursor:pointer;transition:transform 0.3s var(--ease-spring),border-color 0.3s,box-shadow 0.3s;text-decoration:none;color:inherit;display:block;position:relative;overflow:hidden}
.help-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;opacity:0;transition:opacity 0.3s}
.help-card:hover{transform:translateY(-6px);border-color:rgba(232,201,125,0.2);box-shadow:0 20px 60px rgba(0,0,0,0.4),0 0 30px rgba(232,201,125,0.05)}
.help-card:hover::before{opacity:1}
.hc-gold::before{background:linear-gradient(90deg,var(--gold),var(--ember))}
.hc-sage::before{background:linear-gradient(90deg,var(--sage),var(--azure))}
.hc-azure::before{background:linear-gradient(90deg,var(--azure),var(--plum))}
.hc-ember::before{background:linear-gradient(90deg,var(--ember),var(--gold))}
.help-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:1.2rem}
.hi-gold{background:rgba(232,201,125,0.1);color:var(--gold)}
.hi-sage{background:rgba(78,204,163,0.1);color:var(--sage)}
.hi-azure{background:rgba(74,158,255,0.1);color:var(--azure)}
.hi-ember{background:rgba(255,107,53,0.1);color:var(--ember)}
.help-card h3{font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600;letter-spacing:-0.3px;margin-bottom:0.5rem}
.help-card p{font-size:0.8rem;color:var(--txt-secondary);line-height:1.65;margin-bottom:1rem}
.help-card-link{font-family:'JetBrains Mono',monospace;font-size:0.65rem;color:var(--gold);letter-spacing:0.08em;display:flex;align-items:center;gap:5px;transition:gap 0.2s}
.help-card:hover .help-card-link{gap:10px}

/* FAQ */
.faq-wrap{margin-top:2.5rem}
.faq-category{margin-bottom:2.5rem}
.faq-cat-title{font-family:'Clash Display',sans-serif;font-size:0.88rem;font-weight:600;color:var(--txt-muted);letter-spacing:0.06em;text-transform:uppercase;display:flex;align-items:center;gap:8px;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid var(--glass-border)}
.faq-item{border:1px solid var(--glass-border);border-radius:14px;overflow:hidden;margin-bottom:0.6rem;background:var(--glass);transition:border-color 0.2s}
.faq-item.open{border-color:rgba(232,201,125,0.2);background:rgba(232,201,125,0.02)}
.faq-question{width:100%;display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.1rem 1.4rem;background:none;border:none;color:var(--txt-primary);cursor:pointer;font-family:'Cabinet Grotesk',sans-serif;font-size:0.88rem;font-weight:600;text-align:left;transition:color 0.2s}
.faq-question:hover{color:var(--gold)}
.faq-item.open .faq-question{color:var(--gold)}
.faq-arrow{width:26px;height:26px;border-radius:7px;background:var(--glass);border:1px solid var(--glass-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform 0.3s var(--ease-spring),background 0.2s}
.faq-item.open .faq-arrow{transform:rotate(45deg);background:rgba(232,201,125,0.1);border-color:rgba(232,201,125,0.3)}
.faq-answer{max-height:0;overflow:hidden;transition:max-height 0.4s var(--ease-smooth),padding 0.3s}
.faq-item.open .faq-answer{max-height:600px;padding-bottom:1.2rem}
.faq-answer-inner{padding:0 1.4rem;font-size:0.84rem;color:var(--txt-secondary);line-height:1.8}
.faq-answer-inner p{margin-bottom:0.6rem}
.faq-answer-inner ul{list-style:none;padding:0}
.faq-answer-inner ul li{padding:3px 0 3px 16px;position:relative;color:var(--txt-muted)}
.faq-answer-inner ul li::before{content:'→';position:absolute;left:0;color:var(--gold);font-size:0.7rem}
.faq-answer-inner strong{color:var(--txt-primary)}
.faq-badge{font-family:'JetBrains Mono',monospace;font-size:0.6rem;padding:2px 8px;border-radius:100px;background:rgba(232,201,125,0.1);color:var(--gold);border:1px solid rgba(232,201,125,0.2);flex-shrink:0}

/* CONTACT BANNER */
.contact-banner{background:linear-gradient(135deg,var(--slate),rgba(19,26,46,0.9));border:1px solid var(--glass-border);border-radius:24px;padding:3rem;display:flex;align-items:center;justify-content:space-between;gap:2rem;margin-top:4rem;position:relative;overflow:hidden}
.contact-banner::before{content:'';position:absolute;width:300px;height:300px;background:radial-gradient(circle,rgba(232,201,125,0.08) 0%,transparent 70%);top:-100px;right:-50px;pointer-events:none}
.contact-banner-info h3{font-family:'Clash Display',sans-serif;font-size:1.5rem;font-weight:700;letter-spacing:-0.5px;margin-bottom:0.5rem}
.contact-banner-info p{font-size:0.85rem;color:var(--txt-secondary);max-width:400px;line-height:1.7}
.contact-btns{display:flex;gap:0.75rem;flex-shrink:0;flex-wrap:wrap}
.btn-contact-primary{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:0.85rem;font-weight:700;text-decoration:none;transition:opacity 0.2s,transform 0.2s}
.btn-contact-primary:hover{opacity:0.88;transform:translateY(-2px)}
.btn-contact-outline{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-family:'Clash Display',sans-serif;font-size:0.85rem;font-weight:600;text-decoration:none;transition:all 0.2s}
.btn-contact-outline:hover{border-color:rgba(232,201,125,0.3);color:var(--gold)}
@media(max-width:700px){.contact-banner{flex-direction:column;text-align:center}.contact-btns{justify-content:center}}

/* BACK BTN */
.back-btn{display:inline-flex;align-items:center;gap:8px;font-family:'Cabinet Grotesk',sans-serif;font-size:0.82rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;padding:8px 16px;border-radius:10px;border:1px solid var(--glass-border);background:var(--glass);transition:all 0.2s}
.back-btn:hover{border-color:rgba(232,201,125,0.3);color:var(--gold);transform:translateX(-3px)}

/* STATUS BADGES */
.status-row{display:flex;gap:1.2rem;flex-wrap:wrap;margin-top:1.5rem}
.status-badge{display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:100px;font-size:0.75rem;font-weight:600;font-family:'JetBrains Mono',monospace}
.sb-green{background:rgba(78,204,163,0.1);border:1px solid rgba(78,204,163,0.2);color:var(--sage)}
.sb-yellow{background:rgba(255,189,46,0.1);border:1px solid rgba(255,189,46,0.2);color:#ffbd2e}
.status-dot{width:7px;height:7px;border-radius:50%;background:currentColor;animation:blink 2s infinite}

/* DIVIDER */
.section-divider{max-width:1100px;margin:0 auto;height:1px;background:linear-gradient(90deg,transparent,var(--glass-border),transparent)}

/* FOOTER */
footer{border-top:1px solid var(--glass-border);padding:2rem;background:rgba(0,0,0,0.25)}
.footer-bottom{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.footer-copy{font-size:0.73rem;color:var(--txt-muted)}
.tech-pills{display:flex;gap:0.6rem}
.tech-pill{font-family:'JetBrains Mono',monospace;font-size:0.6rem;padding:4px 10px;border-radius:100px;border:1px solid var(--glass-border);color:var(--txt-muted)}

/* REVEAL */
.reveal{opacity:0;transform:translateY(24px);transition:opacity 0.6s var(--ease-smooth),transform 0.6s var(--ease-smooth)}
.reveal.visible{opacity:1;transform:none}
.reveal-delay-1{transition-delay:0.1s}.reveal-delay-2{transition-delay:0.2s}.reveal-delay-3{transition-delay:0.3s}
</style>
</head>
<body>

<div class="bg-orb orb-a"></div>
<div class="bg-orb orb-b"></div>
<div class="bg-orb orb-c"></div>

<!-- HEADER -->
<header id="site-header">
  <a href="index.php" class="nav-logo">
    <div class="logo-mark">📚</div>
    Digital <span style="color:var(--gold)">Library</span>
  </a>
  <nav>
    <ul class="nav-links">
      <li><a href="index.php">Accueil</a></li>
      <li><a href="explorer.php">Explorer</a></li>
      <li><a href="categories.php">Catégories</a></li>
      <li><a href="tendances.php">Tendances</a></li>
      <li><a href="aide.php" class="active">Aide</a></li>
    </ul>
  </nav>
  <div class="nav-actions">
    <?php if ($isLoggedIn): ?>
      <a href="dashboard.php" class="user-chip">
        <div class="user-avatar"><?= strtoupper(substr($username,0,2)) ?></div>
        <?= $username ?>
      </a>
      <a href="logout.php" class="btn-ghost">Déconnexion</a>
    <?php else: ?>
      <a href="login.php" class="btn-ghost">Connexion</a>
      <a href="register.php" class="btn-cta-nav">Commencer →</a>
    <?php endif; ?>
  </div>
  <button id="hamburger" aria-label="Menu" aria-expanded="false"><i class="bi bi-list"></i></button>
</header>

<nav id="mobile-nav">
  <a href="index.php">Accueil</a>
  <a href="explorer.php">Explorer</a>
  <a href="aide.php">Aide</a>
  <a href="apropos.php">À propos</a>
  <?php if ($isLoggedIn): ?>
    <a href="logout.php" style="color:var(--ember)">Déconnexion</a>
  <?php else: ?>
    <a href="login.php">Connexion</a>
    <a href="register.php" style="color:var(--gold)">S'inscrire</a>
  <?php endif; ?>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div style="max-width:700px;margin:0 auto;position:relative;z-index:1">
    <div style="margin-bottom:1.5rem">
      <a href="index.php" class="back-btn"><i class="bi bi-arrow-left"></i> Retour à l'accueil</a>
    </div>
    <div class="hero-eyebrow">
      <span class="pulse-dot"></span>
      Support disponible 24h/7j
    </div>
    <h1>Centre d'<span>Aide</span></h1>
    <p>Trouvez des réponses rapides à vos questions, explorez nos guides ou contactez notre équipe de support.</p>

    <!-- SEARCH BAR -->
    <div class="search-wrap">
      <div class="search-bar">
        <div class="search-icon-wrap"><i class="bi bi-search"></i></div>
        <input type="text" placeholder="Recherchez un sujet, un mot-clé…" id="search-input" autocomplete="off">
        <button class="search-btn" onclick="handleSearch()">Rechercher</button>
      </div>
      <div class="search-tags">
        <button class="search-tag" onclick="filterSearch('paiement')">💳 Paiement</button>
        <button class="search-tag" onclick="filterSearch('compte')">👤 Compte</button>
        <button class="search-tag" onclick="filterSearch('lecture')">📖 Lecture</button>
      </div>
    </div>

    <!-- STATUS SYSTEM -->
    <div class="status-row" style="justify-content:center;margin-top:2rem">
      <div class="status-badge sb-green"><span class="status-dot"></span> Tous les systèmes opérationnels</div>
      <div class="status-badge sb-yellow"><span class="status-dot"></span> Temps de réponse moyen : &lt;2 min</div>
    </div>
  </div>
</div>

<!-- HELP CARDS -->
<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="reveal">
      <div class="section-eyebrow">Catégories d'aide</div>
      <h2 class="section-title">Que recherchez-vous ?</h2>
      <p class="section-sub">Sélectionnez une catégorie pour accéder aux articles correspondants.</p>
    </div>
    <div class="help-cards-grid">
      <a href="#faq-compte" class="help-card hc-gold reveal" onclick="scrollToFaq('faq-compte')">
        <div class="help-icon hi-gold"><i class="bi bi-person-gear"></i></div>
        <h3>Gestion de compte</h3>
        <p>Inscription, connexion, modification du profil, sécurité et suppression de compte.</p>
        <div class="help-card-link"><i class="bi bi-arrow-right"></i> Voir les articles</div>
      </a>
      <a href="#faq-paiement" class="help-card hc-sage reveal reveal-delay-1" onclick="scrollToFaq('faq-paiement')">
        <div class="help-icon hi-sage"><i class="bi bi-credit-card-2-front"></i></div>
        <h3>Paiement & Facturation</h3>
        <p>Méthodes de paiement acceptées, factures, remboursements et problèmes de transaction.</p>
        <div class="help-card-link"><i class="bi bi-arrow-right"></i> Voir les articles</div>
      </a>
      <a href="#faq-lecture" class="help-card hc-azure reveal reveal-delay-2" onclick="scrollToFaq('faq-lecture')">
        <div class="help-icon hi-azure"><i class="bi bi-book-open-fill"></i></div>
        <h3>Lecture & Lecteur</h3>
        <p>Comment utiliser le lecteur intégré, personnaliser votre expérience et résoudre les bugs.</p>
        <div class="help-card-link"><i class="bi bi-arrow-right"></i> Voir les articles</div>
      </a>
      <a href="#faq-catalogue" class="help-card hc-ember reveal reveal-delay-3" onclick="scrollToFaq('faq-catalogue')">
        <div class="help-icon hi-ember"><i class="bi bi-collection-fill"></i></div>
        <h3>Catalogue & Livres</h3>
        <p>Recherche, disponibilité, accès aux livres gratuits et premium, nouveautés.</p>
        <div class="help-card-link"><i class="bi bi-arrow-right"></i> Voir les articles</div>
      </a>
    </div>
  </div>
</section>

<div class="section-divider"></div>

<!-- FAQ -->
<section class="section" id="faq">
  <div class="container">
    <div class="reveal">
      <div class="section-eyebrow">FAQ</div>
      <h2 class="section-title">Questions fréquentes</h2>
      <p class="section-sub">Les réponses aux questions les plus posées par notre communauté.</p>
    </div>

    <div class="faq-wrap">

      <!-- COMPTE -->
      <div class="faq-category reveal" id="faq-compte">
        <div class="faq-cat-title">
          <i class="bi bi-person-circle" style="color:var(--gold)"></i>
          Gestion de compte
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Comment créer un compte sur Digital Library ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>La création d'un compte est simple et gratuite. Cliquez sur <strong>« Commencer »</strong> ou <strong>« S'inscrire »</strong> en haut de la page, puis remplissez le formulaire avec :</p>
              <ul>
                <li>Votre prénom et nom complet</li>
                <li>Une adresse e-mail valide</li>
                <li>Un mot de passe sécurisé (minimum 8 caractères)</li>
              </ul>
              <p>Votre compte est activé immédiatement, sans validation par e-mail. Vous pouvez accéder aux livres gratuits dès l'inscription.</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Comment réinitialiser mon mot de passe ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Si vous avez oublié votre mot de passe, rendez-vous sur la page de <strong>connexion</strong> et cliquez sur <em>« Mot de passe oublié ? »</em>.</p>
              <p>Entrez votre adresse e-mail et vous recevrez un lien de réinitialisation valable 30 minutes. Vérifiez aussi votre dossier <strong>Spam</strong> si vous ne voyez pas l'e-mail dans votre boîte principale.</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Puis-je modifier mes informations personnelles ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Oui, depuis votre <strong>tableau de bord</strong> (Dashboard), accédez à la section <em>Profil</em>. Vous pouvez modifier :</p>
              <ul>
                <li>Votre nom et prénom</li>
                <li>Votre adresse e-mail</li>
                <li>Votre mot de passe</li>
                <li>Vos préférences de notification</li>
              </ul>
              <p>Les modifications sont enregistrées instantanément.</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Comment supprimer mon compte ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>La suppression de compte est définitive et irréversible. Pour procéder :</p>
              <ul>
                <li>Allez dans <strong>Dashboard → Paramètres → Compte</strong></li>
                <li>Cliquez sur <em>« Supprimer mon compte »</em></li>
                <li>Confirmez avec votre mot de passe actuel</li>
              </ul>
              <p><strong>Attention :</strong> Tous vos achats, historique de lecture et données personnelles seront supprimés définitivement. Nous vous recommandons d'exporter vos données avant de supprimer.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- PAIEMENT -->
      <div class="faq-category reveal" id="faq-paiement">
        <div class="faq-cat-title">
          <i class="bi bi-credit-card" style="color:var(--sage)"></i>
          Paiement & Facturation
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Quelles méthodes de paiement sont acceptées ?
            <span class="faq-badge">Populaire</span>
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Digital Library accepte les méthodes de paiement suivantes :</p>
              <ul>
                <li><strong>📱 Orange Money</strong> — Paiement mobile instantané</li>
                <li><strong>📲 Mobile Money</strong> — MTN, Moov et autres opérateurs</li>
                <li><strong>💳 Visa / Mastercard</strong> — Cartes de crédit et débit internationales</li>
                <li><strong>🏧 Carte locale</strong> — Cartes bancaires camerounaises</li>
              </ul>
              <p>Toutes les transactions sont chiffrées avec un cryptage SSL 256 bits pour votre sécurité.</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Mon paiement a échoué. Que faire ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>En cas d'échec de paiement, vérifiez les points suivants :</p>
              <ul>
                <li>Votre solde mobile money ou bancaire est suffisant</li>
                <li>Les informations de carte sont correctement saisies</li>
                <li>Votre carte n'est pas expirée ou bloquée</li>
                <li>Votre connexion Internet est stable pendant la transaction</li>
              </ul>
              <p>Si le problème persiste après ces vérifications, contactez notre support. <strong>Aucun montant n'est prélevé lors d'une transaction échouée.</strong></p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Comment obtenir un remboursement ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Nous offrons une politique de remboursement sous <strong>7 jours</strong> à compter de l'achat, si :</p>
              <ul>
                <li>Le contenu acheté ne correspond pas à la description</li>
                <li>Un problème technique vous a empêché d'accéder au livre</li>
                <li>Un double paiement a été effectué par erreur</li>
              </ul>
              <p>Les remboursements sont traités sous 3 à 5 jours ouvrés. Contactez le support avec votre référence de transaction (format DLS-XXXX).</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Puis-je obtenir une facture pour mon achat ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Oui. Après chaque achat réussi, un récapitulatif est disponible dans <strong>Dashboard → Mes achats</strong>. Vous pouvez y télécharger une facture PDF pour chaque transaction.</p>
              <p>La référence de transaction (format <strong>DLS-XXXX</strong>) est également visible sur l'écran de confirmation de paiement.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- LECTURE -->
      <div class="faq-category reveal" id="faq-lecture">
        <div class="faq-cat-title">
          <i class="bi bi-book-half" style="color:var(--azure)"></i>
          Lecture & Lecteur intégré
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Comment fonctionne le lecteur intégré ?
            <span class="faq-badge">Populaire</span>
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Le lecteur Digital Library est une interface style e-reader directement dans votre navigateur. Il offre :</p>
              <ul>
                <li><strong>Navigation multi-pages</strong> — Flèches de navigation ou touches clavier (←→)</li>
                <li><strong>Mode sombre/clair</strong> — Basculez facilement avec le bouton lune/soleil</li>
                <li><strong>Taille de police ajustable</strong> — Zoom avant/arrière pour le confort de lecture</li>
                <li><strong>Marque-pages</strong> — Sauvegardez votre progression automatiquement</li>
                <li><strong>Navigation clavier</strong> — Flèches, PageUp/PageDown supportés</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Ma progression est-elle sauvegardée automatiquement ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Oui, votre progression est sauvegardée localement dans votre navigateur à chaque changement de page. Si vous fermez le lecteur et revenez plus tard, vous reprendrez à la dernière page consultée.</p>
              <p><strong>Note :</strong> La progression locale est liée à votre navigateur. Pour une synchronisation multi-appareils, vous devez être connecté à votre compte.</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Le lecteur fonctionne-t-il sur mobile ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Absolument. Le lecteur est entièrement responsive et optimisé pour les écrans mobiles. Sur tactile, vous pouvez :</p>
              <ul>
                <li>Utiliser les boutons Précédente / Suivante</li>
                <li>Pincer pour zoomer (zoom natif du navigateur)</li>
                <li>Faire défiler verticalement dans une page longue</li>
              </ul>
              <p>Nous recommandons les navigateurs Chrome, Firefox ou Safari dans leur dernière version.</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Puis-je lire hors-ligne ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>La lecture hors-ligne n'est pas encore disponible dans cette version. Une connexion Internet est nécessaire pour charger et afficher les contenus.</p>
              <p>Cette fonctionnalité est dans notre <strong>roadmap</strong> et sera disponible dans une prochaine mise à jour.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- CATALOGUE -->
      <div class="faq-category reveal" id="faq-catalogue">
        <div class="faq-cat-title">
          <i class="bi bi-collection" style="color:var(--ember)"></i>
          Catalogue & Livres
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Quelle est la différence entre les livres gratuits et premium ?
            <span class="faq-badge">Important</span>
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Notre catalogue est divisé en trois catégories :</p>
              <ul>
                <li><strong>🟢 GRATUIT</strong> — Accessible à tout utilisateur connecté, sans paiement</li>
                <li><strong>🔵 STANDARD</strong> — Accès unique à l'achat, tarifs entre 1 000 et 4 000 FCFA</li>
                <li><strong>🟡 PREMIUM</strong> — Contenus exclusifs haute valeur, à partir de 4 500 FCFA</li>
              </ul>
              <p>Les livres gratuits ont généralement une note inférieure à 2/5 ou sont librement distribués par leurs auteurs.</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Comment sont sélectionnés les livres du catalogue ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Notre équipe éditoriale sélectionne les ouvrages selon plusieurs critères :</p>
              <ul>
                <li>Qualité du contenu et originalité</li>
                <li>Pertinence thématique et actualité</li>
                <li>Notes et avis des lecteurs</li>
                <li>Diversité des genres et des auteurs</li>
              </ul>
              <p>Les auteurs peuvent soumettre leurs œuvres via notre formulaire de candidature (disponible dans la section <em>Publier</em>).</p>
            </div>
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question" onclick="toggleFaq(this)">
            Que faire si un livre acheté n'est plus disponible ?
            <div class="faq-arrow"><i class="bi bi-plus-lg"></i></div>
          </button>
          <div class="faq-answer">
            <div class="faq-answer-inner">
              <p>Si un livre que vous avez acheté est retiré du catalogue, vous conservez définitivement l'accès à votre exemplaire. Votre bibliothèque personnelle est toujours consultable dans <strong>Dashboard → Ma bibliothèque</strong>.</p>
              <p>En cas de problème d'accès, contactez notre support avec votre référence d'achat.</p>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /faq-wrap -->

    <!-- CONTACT BANNER -->
    <div class="contact-banner reveal">
      <div class="contact-banner-info">
        <h3>Vous n'avez pas trouvé la réponse ?</h3>
        <p>Notre équipe de support est disponible pour répondre à toutes vos questions spécifiques. Temps de réponse moyen inférieur à 2 minutes.</p>
      </div>
      <div class="contact-btns">
        <a href="mailto:support@digitallibrary.cm" class="btn-contact-primary"><i class="bi bi-envelope-fill"></i> Envoyer un e-mail</a>
        <a href="#" class="btn-contact-outline" onclick="openChat()"><i class="bi bi-chat-dots-fill"></i> Chat en direct</a>
      </div>
    </div>

  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-bottom">
    <p class="footer-copy">© <?= date('Y') ?> Digital Library System — Tous droits réservés.</p>
    <div class="tech-pills">
      <span class="tech-pill">PHP</span><span class="tech-pill">MySQL</span><span class="tech-pill">JavaScript</span>
    </div>
  </div>
</footer>

<script>
// HEADER SCROLL
const hdr = document.getElementById('site-header');
if (hdr) window.addEventListener('scroll', () => hdr.classList.toggle('scrolled', window.scrollY > 60));

// HAMBURGER
const ham = document.getElementById('hamburger');
const mNav = document.getElementById('mobile-nav');
if (ham && mNav) {
  ham.addEventListener('click', () => {
    const open = mNav.classList.toggle('open');
    ham.setAttribute('aria-expanded', open);
    ham.innerHTML = open ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
  });
}

// SCROLL REVEAL
const revObs = new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); revObs.unobserve(e.target); }});
}, { threshold: 0.08 });
document.querySelectorAll('.reveal').forEach(el => revObs.observe(el));

// FAQ ACCORDION
function toggleFaq(btn) {
  const item = btn.closest('.faq-item');
  const isOpen = item.classList.contains('open');
  // Fermer tous
  document.querySelectorAll('.faq-item.open').forEach(i => i.classList.remove('open'));
  if (!isOpen) item.classList.add('open');
}

// SEARCH
function handleSearch() {
  const q = document.getElementById('search-input').value.trim();
  if (!q) return;
  // Mettre en évidence les éléments correspondants
  document.querySelectorAll('.faq-question').forEach(btn => {
    const text = btn.textContent.toLowerCase();
    const item = btn.closest('.faq-item');
    if (text.includes(q.toLowerCase())) {
      item.classList.add('open');
      item.scrollIntoView({ behavior:'smooth', block:'center' });
    }
  });
}
function filterSearch(term) {
  document.getElementById('search-input').value = term;
  handleSearch();
}
document.getElementById('search-input')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') handleSearch();
});

// SCROLL TO FAQ SECTION
function scrollToFaq(id) {
  event.preventDefault();
  const el = document.getElementById(id);
  if (el) el.scrollIntoView({ behavior:'smooth', block:'start' });
}

// CHAT SIMULÉ
function openChat() {
  alert('Le chat en direct sera disponible prochainement.\nContactez-nous par e-mail : support@digitallibrary.cm');
}
</script>
</body>
</html>