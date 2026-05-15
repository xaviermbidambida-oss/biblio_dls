<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/includes/config.php';

// Mode vitrine
$forceGuest = true;

$user = [
    'name' => 'Invité',
    'isGuest' => true,
    'id' => null,
    'role' => null
];
?>

<?php
require_once 'includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$isLoggedIn = isset($_SESSION['user_id']);
$username = $isLoggedIn ? htmlspecialchars($_SESSION['username'] ?? 'Utilisateur') : '';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
// 🔥 Mode vitrine (force invité)
$forceGuest = true;

if ($forceGuest) {
    $user = [
        'name' => 'Invité',
        'isGuest' => true,
        'id' => null,
        'role' => null
    ];
} else {
    $user = [
        'name' => $_SESSION['user_name'] ?? 'Invité',
        'isGuest' => !isset($_SESSION['user_id']),
        'id' => $_SESSION['user_id'] ?? null,
        'role' => $_SESSION['user_role'] ?? null
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Digital Library System — Bibliothèque Numérique</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<script>
  tailwind.config = {
    theme: {
      extend: {
        fontFamily: {
          display: ['Syne', 'sans-serif'],
          mono: ['Space Mono', 'monospace'],
          body: ['Inter', 'sans-serif'],
        },
        colors: {
          night: '#0a0e1a',
          void: '#060910',
          cyan: { DEFAULT: '#00d4ff', dim: '#00d4ff33' },
          violet: { DEFAULT: '#8b5cf6', dim: '#8b5cf620' },
          neon: '#00ffaa',
        }
      }
    }
  }
</script>

<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --night: #0a0e1a;
    --void: #060910;
    --cyan: #00d4ff;
    --violet: #8b5cf6;
    --neon: #00ffaa;
    --white: #f0f4ff;
    --muted: rgba(240,244,255,0.45);
    --glass: rgba(255,255,255,0.04);
    --glass-border: rgba(255,255,255,0.08);
    --glow-cyan: 0 0 40px rgba(0,212,255,0.3);
    --glow-violet: 0 0 40px rgba(139,92,246,0.3);
  }

  html { scroll-behavior: smooth; }
  body { font-family: 'Inter', sans-serif; background: var(--night); color: var(--white); overflow-x: hidden; cursor: none; }

  /* CURSOR */
  #cursor { position: fixed; width: 10px; height: 10px; background: var(--cyan); border-radius: 50%; pointer-events: none; z-index: 99999; transform: translate(-50%, -50%); transition: width 0.2s, height 0.2s, background 0.2s; mix-blend-mode: screen; }
  #cursor-ring { position: fixed; width: 36px; height: 36px; border: 1px solid rgba(0,212,255,0.5); border-radius: 50%; pointer-events: none; z-index: 99998; transform: translate(-50%, -50%); transition: all 0.12s ease-out; }

  ::-webkit-scrollbar { width: 4px; }
  ::-webkit-scrollbar-track { background: var(--void); }
  ::-webkit-scrollbar-thumb { background: var(--cyan); border-radius: 4px; }

  /* INTRO */
  #intro { position: fixed; inset: 0; background: var(--void); z-index: 9999; display: flex; flex-direction: column; align-items: center; justify-content: center; transition: opacity 1.2s ease, visibility 1.2s ease; }
  #intro.hide { opacity: 0; visibility: hidden; }
  .intro-logo { font-family: 'Syne', sans-serif; font-size: 3rem; font-weight: 800; background: linear-gradient(135deg, var(--cyan), var(--violet)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -1px; margin-bottom: 2rem; }
  .boot-lines { font-family: 'Space Mono', monospace; font-size: 0.78rem; color: var(--neon); text-align: left; width: 320px; line-height: 2; }
  .boot-line { opacity: 0; transform: translateX(-8px); transition: all 0.4s ease; }
  .boot-line.show { opacity: 1; transform: translateX(0); }
  .progress-bar { margin-top: 2rem; width: 320px; height: 2px; background: rgba(255,255,255,0.08); border-radius: 2px; overflow: hidden; }
  .progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, var(--cyan), var(--violet)); border-radius: 2px; transition: width 0.6s ease; box-shadow: 0 0 12px var(--cyan); }

  /* BACKGROUND */
  #bg-canvas { position: fixed; inset: 0; z-index: -2; background: radial-gradient(ellipse at 20% 20%, #0d1a3a 0%, var(--void) 60%), radial-gradient(ellipse at 80% 80%, #12082a 0%, transparent 60%); }
  #bg-canvas::before { content: ''; position: absolute; inset: 0; background-image: linear-gradient(rgba(0,212,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(0,212,255,0.04) 1px, transparent 1px); background-size: 60px 60px; mask-image: radial-gradient(ellipse at center, black 30%, transparent 80%); }
  .orb { position: fixed; border-radius: 50%; filter: blur(80px); pointer-events: none; z-index: -1; animation: orbFloat 20s ease-in-out infinite; }
  .orb-1 { width: 500px; height: 500px; background: rgba(0,212,255,0.08); top: -100px; left: -100px; animation-delay: 0s; }
  .orb-2 { width: 400px; height: 400px; background: rgba(139,92,246,0.1); bottom: -100px; right: -100px; animation-delay: -7s; }
  .orb-3 { width: 300px; height: 300px; background: rgba(0,255,170,0.06); top: 40%; left: 40%; animation-delay: -14s; }
  @keyframes orbFloat { 0%,100%{transform:translate(0,0) scale(1)} 33%{transform:translate(40px,-30px) scale(1.05)} 66%{transform:translate(-30px,40px) scale(0.95)} }

  /* HEADER */
  #header { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; padding: 0 2rem; height: 64px; display: flex; align-items: center; justify-content: space-between; background: rgba(6,9,16,0.6); backdrop-filter: blur(20px); border-bottom: 1px solid var(--glass-border); transition: background 0.3s; }
  .nav-logo { display: flex; align-items: center; gap: 10px; font-family: 'Syne', sans-serif; font-weight: 800; font-size: 1rem; letter-spacing: -0.5px; text-decoration: none; color: var(--white); }
  .logo-icon { width: 34px; height: 34px; background: linear-gradient(135deg, var(--cyan), var(--violet)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; box-shadow: var(--glow-cyan); }
  .nav-links { display: flex; align-items: center; gap: 0.1rem; list-style: none; }
  .nav-links a { font-family: 'Inter', sans-serif; font-size: 0.78rem; font-weight: 500; color: var(--muted); text-decoration: none; padding: 6px 12px; border-radius: 6px; transition: color 0.2s, background 0.2s; letter-spacing: 0.02em; }
  .nav-links a:hover { color: var(--white); background: var(--glass); }
  .nav-links a.active { color: var(--cyan); }
  /* Lock icon on protected nav links for guests */
  .nav-links a[data-protected]::after { content: ' 🔒'; font-size: 0.55rem; opacity: 0.5; vertical-align: super; }
  .nav-actions { display: flex; align-items: center; gap: 0.75rem; }
  .btn-nav-primary { font-family: 'Syne', sans-serif; font-size: 0.78rem; font-weight: 700; padding: 8px 18px; border-radius: 8px; background: linear-gradient(135deg, var(--cyan), var(--violet)); color: #000; text-decoration: none; letter-spacing: 0.03em; transition: opacity 0.2s, transform 0.2s, box-shadow 0.2s; box-shadow: var(--glow-cyan); }
  .btn-nav-primary:hover { opacity: 0.85; transform: translateY(-1px); }
  .btn-nav-ghost { font-family: 'Syne', sans-serif; font-size: 0.78rem; font-weight: 600; padding: 8px 18px; border-radius: 8px; border: 1px solid var(--glass-border); color: var(--muted); text-decoration: none; letter-spacing: 0.03em; transition: border-color 0.2s, color 0.2s, background 0.2s; }
  .btn-nav-ghost:hover { border-color: var(--cyan); color: var(--cyan); background: var(--cyan-dim); }
  #hamburger { display: none; background: none; border: none; color: var(--white); font-size: 1.4rem; cursor: pointer; }
  #mobile-menu { display: none; position: fixed; inset: 0; top: 64px; background: rgba(6,9,16,0.97); backdrop-filter: blur(20px); z-index: 999; flex-direction: column; align-items: center; justify-content: center; gap: 1.5rem; }
  #mobile-menu.open { display: flex; }
  #mobile-menu a { font-family: 'Syne', sans-serif; font-size: 1.4rem; font-weight: 700; color: var(--muted); text-decoration: none; transition: color 0.2s; }
  #mobile-menu a:hover { color: var(--cyan); }
  @media (max-width: 900px) { .nav-links { display: none; } .nav-actions { display: none; } #hamburger { display: block; } }
  .btn-logout {
    display: inline-flex;
    align-items: center;
    gap: 10px;

    padding: 10px 18px;
    border-radius: 12px;

    font-size: 0.85rem;
    font-weight: 600;
    letter-spacing: 0.03em;

    color: #fff;
    text-decoration: none;

    background: linear-gradient(135deg, #ff4d6d, #ff6a88);
    border: 1px solid rgba(255, 77, 109, 0.4);

    box-shadow:
        0 4px 20px rgba(255, 77, 109, 0.25),
        inset 0 1px 0 rgba(255,255,255,0.1);

    position: relative;
    overflow: hidden;

    transition: all 0.3s ease;
}

/* Effet lumière */
.btn-logout::before {
    content: '';
    position: absolute;
    inset: 0;

    background: linear-gradient(
        120deg,
        transparent,
        rgba(255,255,255,0.25),
        transparent
    );

    transform: translateX(-100%);
    transition: transform 0.6s ease;
}

/* Hover */
.btn-logout:hover {
    transform: translateY(-2px) scale(1.03);

    box-shadow:
        0 8px 30px rgba(255, 77, 109, 0.4),
        0 0 15px rgba(255, 77, 109, 0.3);
}

.btn-logout:hover::before {
    transform: translateX(100%);
}

/* Click */
.btn-logout:active {
    transform: scale(0.97);
}

/* Icône */
.btn-logout i {
    font-size: 1rem;
    transition: transform 0.3s ease;
}

/* Animation icône */
.btn-logout:hover i {
    transform: translateX(3px);
}

.btn-logout:hover {
    opacity: 0.85;
}

  /* HERO */
  #hero { min-height: 100vh; padding-top: 64px; display: flex; align-items: center; justify-content: center; text-align: center; position: relative; overflow: hidden; }
  .hero-inner { max-width: 860px; padding: 0 1.5rem; position: relative; z-index: 1; }
  .hero-badge { display: inline-flex; align-items: center; gap: 8px; font-family: 'Space Mono', monospace; font-size: 0.72rem; color: var(--neon); border: 1px solid rgba(0,255,170,0.3); background: rgba(0,255,170,0.06); padding: 6px 16px; border-radius: 100px; margin-bottom: 2rem; letter-spacing: 0.05em; animation: badgePulse 3s ease-in-out infinite; }
  .badge-dot { width: 6px; height: 6px; background: var(--neon); border-radius: 50%; animation: blink 1.2s ease infinite; }
  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0.2} }
  @keyframes badgePulse { 0%,100%{box-shadow:0 0 0 rgba(0,255,170,0)} 50%{box-shadow:0 0 20px rgba(0,255,170,0.15)} }
  .hero-title { font-family: 'Syne', sans-serif; font-size: clamp(2.2rem, 6vw, 4.5rem); font-weight: 800; line-height: 1.05; letter-spacing: -2px; margin-bottom: 1.5rem; }
  .hero-title .line-1 { display: block; color: var(--white); }
  .hero-title .line-2 { display: block; background: linear-gradient(135deg, var(--cyan) 0%, var(--violet) 50%, var(--neon) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-size: 200% auto; animation: gradientShift 4s ease infinite; }
  @keyframes gradientShift { 0%,100%{background-position:0%} 50%{background-position:100%} }
  .hero-sub { font-size: 1.05rem; color: var(--muted); max-width: 560px; margin: 0 auto 2.5rem; line-height: 1.8; font-weight: 300; }
  .hero-ctas { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
  .btn-primary { display: inline-flex; align-items: center; gap: 8px; font-family: 'Syne', sans-serif; font-size: 0.9rem; font-weight: 700; padding: 14px 28px; border-radius: 12px; background: linear-gradient(135deg, var(--cyan), var(--violet)); color: #fff; text-decoration: none; letter-spacing: 0.03em; box-shadow: 0 0 30px rgba(0,212,255,0.25), 0 8px 24px rgba(0,0,0,0.4); transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s; position: relative; overflow: hidden; }
  .btn-primary::before { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,255,255,0.15), transparent); opacity: 0; transition: opacity 0.2s; }
  .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 0 50px rgba(0,212,255,0.4), 0 12px 32px rgba(0,0,0,0.5); }
  .btn-primary:hover::before { opacity: 1; }
  .btn-outline { display: inline-flex; align-items: center; gap: 8px; font-family: 'Syne', sans-serif; font-size: 0.9rem; font-weight: 700; padding: 14px 28px; border-radius: 12px; border: 1px solid var(--glass-border); color: var(--white); text-decoration: none; letter-spacing: 0.03em; background: var(--glass); backdrop-filter: blur(8px); transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s; }
  .btn-outline:hover { border-color: var(--cyan); transform: translateY(-3px); box-shadow: 0 0 30px rgba(0,212,255,0.15); }
  .hero-stats { display: flex; gap: 2rem; justify-content: center; margin-top: 4rem; flex-wrap: wrap; }
  .stat-item { text-align: center; opacity: 0; transform: translateY(20px); animation: fadeUp 0.6s ease forwards; }
  .stat-item:nth-child(1) { animation-delay: 1.2s; }
  .stat-item:nth-child(2) { animation-delay: 1.4s; }
  .stat-item:nth-child(3) { animation-delay: 1.6s; }
  .stat-number { font-family: 'Syne', sans-serif; font-size: 2rem; font-weight: 800; background: linear-gradient(135deg, var(--cyan), var(--violet)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
  .stat-label { font-size: 0.75rem; color: var(--muted); margin-top: 4px; letter-spacing: 0.05em; text-transform: uppercase; }
  @keyframes fadeUp { to { opacity:1; transform:translateY(0); } }
  .scroll-hint { position: absolute; bottom: 2rem; left: 50%; transform: translateX(-50%); display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--muted); font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; animation: scrollBounce 2s ease-in-out infinite; }
  .scroll-hint i { font-size: 1rem; color: var(--cyan); }
  @keyframes scrollBounce { 0%,100%{transform:translateX(-50%) translateY(0)} 50%{transform:translateX(-50%) translateY(6px)} }

  /* SECTION */
  .section { padding: 6rem 2rem; }
  .section-label { font-family: 'Space Mono', monospace; font-size: 0.7rem; letter-spacing: 0.15em; color: var(--cyan); text-transform: uppercase; margin-bottom: 1rem; }
  .section-title { font-family: 'Syne', sans-serif; font-size: clamp(1.8rem, 4vw, 3rem); font-weight: 800; letter-spacing: -1px; line-height: 1.1; margin-bottom: 1rem; }
  .section-sub { color: var(--muted); font-size: 1rem; max-width: 560px; line-height: 1.8; }

  /* BOOKS */
  .books-header { text-align: center; margin-bottom: 3.5rem; }
  .books-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1.5rem; max-width: 1200px; margin: 0 auto; }
  .book-card { background: var(--glass); border: 1px solid var(--glass-border); border-radius: 16px; overflow: hidden; backdrop-filter: blur(12px); transition: transform 0.3s ease, border-color 0.3s, box-shadow 0.3s; cursor: pointer; opacity: 0; transform: translateY(30px); }
  .book-card.visible { animation: cardReveal 0.6s ease forwards; }
  @keyframes cardReveal { to { opacity:1; transform:translateY(0); } }
  .book-card:hover { transform: translateY(-8px) scale(1.01); border-color: rgba(0,212,255,0.3); box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 30px rgba(0,212,255,0.1); }
  .book-cover { height: 180px; position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; }
  .book-cover-inner { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 4rem; position: relative; }
  .cover-1 { background: linear-gradient(135deg, #0d2137, #1a4a6b); }
  .cover-2 { background: linear-gradient(135deg, #1a0d37, #4a1a6b); }
  .cover-3 { background: linear-gradient(135deg, #0d3720, #1a6b3a); }
  .cover-4 { background: linear-gradient(135deg, #371a0d, #6b3a1a); }
  .cover-5 { background: linear-gradient(135deg, #0d2a37, #1a5a6b); }
  .cover-6 { background: linear-gradient(135deg, #2a0d37, #5a1a6b); }
  .book-cover::after { content: ''; position: absolute; inset: 0; background: linear-gradient(to bottom, transparent 40%, rgba(10,14,26,0.8)); }
  .book-badge { position: absolute; top: 12px; right: 12px; z-index: 2; font-family: 'Space Mono', monospace; font-size: 0.62rem; padding: 4px 10px; border-radius: 100px; letter-spacing: 0.05em; }
  .badge-new { background: rgba(0,255,170,0.15); color: var(--neon); border: 1px solid rgba(0,255,170,0.3); }
  .badge-hot { background: rgba(255,100,0,0.15); color: #ff8c42; border: 1px solid rgba(255,100,0,0.3); }
  .badge-free { background: rgba(0,212,255,0.15); color: var(--cyan); border: 1px solid rgba(0,212,255,0.3); }
  .book-info { padding: 1.2rem; }
  .book-genre { font-family: 'Space Mono', monospace; font-size: 0.62rem; color: var(--cyan); letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 6px; }
  .book-title { font-family: 'Syne', sans-serif; font-size: 0.95rem; font-weight: 700; margin-bottom: 4px; line-height: 1.3; }
  .book-author { font-size: 0.78rem; color: var(--muted); margin-bottom: 1rem; }
  .book-rating { display: flex; align-items: center; gap: 6px; margin-bottom: 1rem; }
  .stars { color: #fbbf24; font-size: 0.75rem; letter-spacing: -1px; }
  .rating-val { font-size: 0.75rem; color: var(--muted); }
  .book-actions { display: flex; gap: 8px; }
  .btn-read { flex: 1; padding: 8px 0; border-radius: 8px; border: 1px solid rgba(0,212,255,0.3); background: rgba(0,212,255,0.06); color: var(--cyan); font-family: 'Syne', sans-serif; font-size: 0.75rem; font-weight: 600; text-align: center; text-decoration: none; transition: background 0.2s, box-shadow 0.2s; cursor: pointer; }
  .btn-read:hover { background: rgba(0,212,255,0.12); box-shadow: 0 0 20px rgba(0,212,255,0.15); }
  .btn-buy { flex: 1; padding: 8px 0; border-radius: 8px; background: linear-gradient(135deg, var(--cyan), var(--violet)); color: #fff; font-family: 'Syne', sans-serif; font-size: 0.75rem; font-weight: 600; text-align: center; text-decoration: none; transition: opacity 0.2s, box-shadow 0.2s; cursor: pointer; }
  .btn-buy:hover { opacity: 0.85; box-shadow: 0 4px 20px rgba(0,212,255,0.3); }

  /* FEATURES */
  .features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; margin-top: 3rem; }
  .feature-card { background: var(--glass); border: 1px solid var(--glass-border); border-radius: 20px; padding: 2rem; backdrop-filter: blur(12px); transition: border-color 0.3s, transform 0.3s, box-shadow 0.3s; position: relative; overflow: hidden; opacity: 0; transform: translateY(30px); }
  .feature-card.visible { animation: cardReveal 0.6s ease forwards; }
  .feature-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, var(--cyan), transparent); opacity: 0; transition: opacity 0.3s; }
  .feature-card:hover::before { opacity: 1; }
  .feature-card:hover { border-color: rgba(0,212,255,0.2); transform: translateY(-6px); box-shadow: 0 20px 60px rgba(0,0,0,0.4), 0 0 30px rgba(0,212,255,0.08); }
  .feature-icon { width: 54px; height: 54px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1.2rem; }
  .icon-cyan { background: rgba(0,212,255,0.1); box-shadow: 0 0 20px rgba(0,212,255,0.15); color: var(--cyan); }
  .icon-violet { background: rgba(139,92,246,0.1); box-shadow: 0 0 20px rgba(139,92,246,0.15); color: var(--violet); }
  .icon-neon { background: rgba(0,255,170,0.08); box-shadow: 0 0 20px rgba(0,255,170,0.12); color: var(--neon); }
  .icon-orange { background: rgba(255,140,0,0.1); box-shadow: 0 0 20px rgba(255,140,0,0.15); color: #ff8c42; }
  .feature-title { font-family: 'Syne', sans-serif; font-size: 1.05rem; font-weight: 700; margin-bottom: 0.6rem; }
  .feature-desc { font-size: 0.85rem; color: var(--muted); line-height: 1.7; }

  /* AI ASSISTANT */
  .ai-wrapper { background: var(--glass); border: 1px solid var(--glass-border); border-radius: 24px; overflow: hidden; backdrop-filter: blur(12px); }
  .ai-topbar { padding: 1rem 1.5rem; border-bottom: 1px solid var(--glass-border); display: flex; align-items: center; gap: 12px; background: rgba(0,0,0,0.2); }
  .ai-dot { width: 10px; height: 10px; border-radius: 50%; }
  .ai-dot-red { background: #ff5f57; }
  .ai-dot-yellow { background: #ffbd2e; }
  .ai-dot-green { background: #28ca41; animation: blink 2s ease infinite; }
  .ai-title { font-family: 'Space Mono', monospace; font-size: 0.75rem; color: var(--muted); margin-left: 8px; }
  .ai-body { padding: 2rem; }
  .ai-prompt { font-family: 'Space Mono', monospace; font-size: 0.72rem; color: var(--neon); margin-bottom: 1rem; }
  #ai-text { font-family: 'Syne', sans-serif; font-size: 1.15rem; font-weight: 500; color: var(--white); min-height: 2.5rem; line-height: 1.6; }
  .ai-cursor { display: inline-block; width: 2px; height: 1.2em; background: var(--cyan); vertical-align: middle; margin-left: 2px; animation: blink 0.7s step-end infinite; }
  .datetime-widget { display: flex; align-items: center; gap: 2rem; justify-content: center; padding: 1.5rem; background: rgba(0,0,0,0.2); border-top: 1px solid var(--glass-border); }
  .dt-item { text-align: center; }
  .dt-label { font-family: 'Space Mono', monospace; font-size: 0.65rem; color: var(--muted); letter-spacing: 0.1em; text-transform: uppercase; }
  .dt-value { font-family: 'Syne', sans-serif; font-size: 1.3rem; font-weight: 700; color: var(--cyan); margin-top: 4px; }
  .dt-sep { color: var(--glass-border); font-size: 1.5rem; }

  /* CTA */
  #cta { text-align: center; padding: 8rem 2rem; position: relative; overflow: hidden; }
  #cta::before { content: ''; position: absolute; width: 600px; height: 600px; background: radial-gradient(circle, rgba(0,212,255,0.08) 0%, transparent 70%); top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: none; }
  .cta-inner { max-width: 640px; margin: 0 auto; position: relative; z-index: 1; }
  .cta-title { font-family: 'Syne', sans-serif; font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; letter-spacing: -1.5px; line-height: 1.1; margin-bottom: 1rem; }
  .cta-sub { color: var(--muted); font-size: 1rem; margin-bottom: 2.5rem; line-height: 1.8; }
  .btn-cta { display: inline-flex; align-items: center; gap: 10px; font-family: 'Syne', sans-serif; font-size: 1rem; font-weight: 800; padding: 18px 40px; border-radius: 14px; background: linear-gradient(135deg, var(--cyan), var(--violet), var(--neon)); background-size: 200% auto; color: #fff; text-decoration: none; letter-spacing: 0.03em; box-shadow: 0 0 40px rgba(0,212,255,0.3), 0 12px 40px rgba(0,0,0,0.5); transition: background-position 0.4s, transform 0.2s, box-shadow 0.3s; animation: ctaPulse 3s ease-in-out infinite; }
  .btn-cta:hover { background-position: right center; transform: translateY(-4px) scale(1.02); box-shadow: 0 0 60px rgba(0,212,255,0.5), 0 16px 50px rgba(0,0,0,0.6); }
  @keyframes ctaPulse { 0%,100%{box-shadow:0 0 40px rgba(0,212,255,0.3),0 12px 40px rgba(0,0,0,0.5)} 50%{box-shadow:0 0 60px rgba(0,212,255,0.5),0 12px 40px rgba(0,0,0,0.5)} }

  /* FOOTER */
  footer { border-top: 1px solid var(--glass-border); padding: 3rem 2rem 2rem; background: rgba(0,0,0,0.3); backdrop-filter: blur(10px); }
  .footer-grid { max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 3rem; }
  @media (max-width: 768px) { .footer-grid { grid-template-columns: 1fr 1fr; gap: 2rem; } }
  @media (max-width: 480px) { .footer-grid { grid-template-columns: 1fr; } }
  .footer-brand p { font-size: 0.85rem; color: var(--muted); line-height: 1.8; margin-top: 1rem; max-width: 280px; }
  .footer-col h4 { font-family: 'Syne', sans-serif; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 1rem; color: var(--white); }
  .footer-col ul { list-style: none; }
  .footer-col ul li { margin-bottom: 0.6rem; }
  .footer-col ul li a { font-size: 0.82rem; color: var(--muted); text-decoration: none; transition: color 0.2s; }
  .footer-col ul li a:hover { color: var(--cyan); }
  .footer-bottom { max-width: 1200px; margin: 2.5rem auto 0; padding-top: 1.5rem; border-top: 1px solid var(--glass-border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
  .footer-bottom p { font-size: 0.75rem; color: var(--muted); }
  .footer-tech { display: flex; gap: 1rem; }
  .tech-badge { font-family: 'Space Mono', monospace; font-size: 0.62rem; padding: 4px 10px; border-radius: 100px; border: 1px solid var(--glass-border); color: var(--muted); letter-spacing: 0.05em; }
  .divider { max-width: 1200px; margin: 0 auto; height: 1px; background: linear-gradient(90deg, transparent, var(--glass-border), transparent); }
  .reveal { opacity: 0; transform: translateY(30px); transition: opacity 0.7s ease, transform 0.7s ease; }
  .reveal.visible { opacity: 1; transform: translateY(0); }

  /* ============================================================
     TOAST — Enhanced with types
  ============================================================ */
  #toast {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    background: rgba(10,14,26,0.97);
    border: 1px solid rgba(0,212,255,0.3);
    border-radius: 14px;
    padding: 1rem 1.4rem;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.82rem;
    color: var(--white);
    backdrop-filter: blur(20px);
    box-shadow: 0 0 30px rgba(0,212,255,0.15), 0 10px 30px rgba(0,0,0,0.5);
    z-index: 9900;
    transform: translateY(120px) scale(0.95);
    opacity: 0;
    transition: all 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
    max-width: 340px;
    min-width: 260px;
  }
  #toast.show { transform: translateY(0) scale(1); opacity: 1; }
  #toast.toast-warn { border-color: rgba(251,191,36,0.4); box-shadow: 0 0 30px rgba(251,191,36,0.12), 0 10px 30px rgba(0,0,0,0.5); }
  #toast.toast-error { border-color: rgba(255,95,87,0.4); box-shadow: 0 0 30px rgba(255,95,87,0.12), 0 10px 30px rgba(0,0,0,0.5); }
  #toast.toast-success { border-color: rgba(0,255,170,0.4); box-shadow: 0 0 30px rgba(0,255,170,0.12), 0 10px 30px rgba(0,0,0,0.5); }
  .toast-icon { font-size: 1.15rem; flex-shrink: 0; }
  .toast-content { display: flex; flex-direction: column; gap: 2px; }
  .toast-title { font-family: 'Syne', sans-serif; font-weight: 700; font-size: 0.82rem; }
  .toast-sub { font-size: 0.73rem; color: var(--muted); }

  a[data-protected] {
  opacity: 0.7;
  }

  /* ============================================================
     AUTH GATE MODAL
  ============================================================ */
  #auth-gate {
    position: fixed;
    inset: 0;
    z-index: 9800;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.35s ease, visibility 0.35s ease;
  }
  #auth-gate.open { opacity: 1; visibility: visible; }

  .gate-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(6,9,16,0.85);
    backdrop-filter: blur(12px);
  }

  .gate-modal {
    position: relative;
    z-index: 1;
    background: linear-gradient(145deg, rgba(13,18,35,0.98), rgba(10,14,26,0.98));
    border: 1px solid rgba(0,212,255,0.2);
    border-radius: 24px;
    padding: 2.5rem;
    max-width: 440px;
    width: 100%;
    text-align: center;
    box-shadow: 0 0 80px rgba(0,212,255,0.1), 0 40px 80px rgba(0,0,0,0.6);
    transform: translateY(20px) scale(0.97);
    transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    overflow: hidden;
  }
  #auth-gate.open .gate-modal { transform: translateY(0) scale(1); }

  /* Animated top gradient line */
  .gate-modal::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--cyan), var(--violet), var(--neon));
    background-size: 200% auto;
    animation: gradientShift 3s linear infinite;
  }

  .gate-lock-icon {
    width: 72px;
    height: 72px;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(0,212,255,0.1), rgba(139,92,246,0.15));
    border: 1px solid rgba(0,212,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin: 0 auto 1.5rem;
    animation: lockPulse 2s ease-in-out infinite;
  }
  @keyframes lockPulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(0,212,255,0.3); }
    50% { box-shadow: 0 0 0 12px rgba(0,212,255,0); }
  }

  .gate-eyebrow {
    font-family: 'Space Mono', monospace;
    font-size: 0.65rem;
    letter-spacing: 0.15em;
    color: var(--cyan);
    text-transform: uppercase;
    margin-bottom: 0.6rem;
  }

  .gate-title {
    font-family: 'Syne', sans-serif;
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: -0.5px;
    margin-bottom: 0.75rem;
    line-height: 1.2;
  }

  .gate-desc {
    font-size: 0.85rem;
    color: var(--muted);
    line-height: 1.7;
    margin-bottom: 2rem;
  }

  .gate-page-hint {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: 'Space Mono', monospace;
    font-size: 0.68rem;
    color: var(--neon);
    background: rgba(0,255,170,0.06);
    border: 1px solid rgba(0,255,170,0.2);
    padding: 6px 14px;
    border-radius: 100px;
    margin-bottom: 1.8rem;
    letter-spacing: 0.03em;
  }

  .gate-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
  }

  .btn-gate-primary {
    flex: 1;
    padding: 13px 20px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--cyan), var(--violet));
    color: #fff;
    font-family: 'Syne', sans-serif;
    font-size: 0.88rem;
    font-weight: 700;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: opacity 0.2s, transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 4px 20px rgba(0,212,255,0.25);
    letter-spacing: 0.02em;
  }
  .btn-gate-primary:hover { opacity: 0.88; transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,212,255,0.4); }

  .btn-gate-ghost {
    flex: 1;
    padding: 13px 20px;
    border-radius: 12px;
    border: 1px solid var(--glass-border);
    color: var(--muted);
    font-family: 'Syne', sans-serif;
    font-size: 0.88rem;
    font-weight: 600;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: border-color 0.2s, color 0.2s, background 0.2s;
    letter-spacing: 0.02em;
  }
  .btn-gate-ghost:hover { border-color: rgba(139,92,246,0.4); color: var(--white); background: rgba(139,92,246,0.08); }

  .gate-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: var(--glass);
    border: 1px solid var(--glass-border);
    color: var(--muted);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
  }
  .gate-close:hover { color: var(--white); border-color: rgba(255,95,87,0.4); background: rgba(255,95,87,0.08); }

  .gate-divider { display: flex; align-items: center; gap: 12px; margin: 1.5rem 0 0; }
  .gate-divider::before, .gate-divider::after { content: ''; flex: 1; height: 1px; background: var(--glass-border); }
  .gate-divider span { font-size: 0.72rem; color: var(--muted); white-space: nowrap; font-family: 'Space Mono', monospace; }

  /* Countdown ring inside modal */
  .gate-countdown {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 1.2rem;
    font-family: 'Space Mono', monospace;
    font-size: 0.72rem;
    color: var(--muted);
  }
  .countdown-bar {
    width: 100%;
    height: 2px;
    background: var(--glass-border);
    border-radius: 2px;
    overflow: hidden;
    margin-top: 0.8rem;
  }
  .countdown-fill {
    height: 100%;
    width: 100%;
    background: linear-gradient(90deg, var(--cyan), var(--violet));
    border-radius: 2px;
    transform-origin: left;
    transition: transform linear;
  }
</style>
</head>

<body>

<!-- CURSOR -->
<div id="cursor"></div>
<div id="cursor-ring"></div>

<!-- BACKGROUND -->
<div id="bg-canvas">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
</div>

<!-- INTRO SCREEN -->
<div id="intro">
  <div class="intro-logo">📚 DLS</div>
  <div class="boot-lines">
    <div class="boot-line" id="b1">[ INIT ] &nbsp;Démarrage du système...</div>
    <div class="boot-line" id="b2">[ LOAD ] &nbsp;Chargement bibliothèque numérique...</div>
    <div class="boot-line" id="b3">[ AUTH ] &nbsp;Vérification des accès...</div>
    <div class="boot-line" id="b4">[ SEC  ] &nbsp;Chiffrement des données...</div>
    <div class="boot-line" id="b5" style="color:#00ffaa">[ OK   ] &nbsp;Accès autorisé ✔</div>
  </div>
  <div class="progress-bar">
    <div class="progress-fill" id="progress"></div>
  </div>
</div>

<!-- ============================================================
     AUTH GATE MODAL — shown to guests clicking protected pages
============================================================ -->
<div id="auth-gate" role="dialog" aria-modal="true" aria-labelledby="gate-title-text">
  <div class="gate-backdrop" id="gate-backdrop"></div>
  <div class="gate-modal">
    <button class="gate-close" id="gate-close" aria-label="Fermer">
      <i class="bi bi-x-lg"></i>
    </button>

    <div class="gate-lock-icon">🔒</div>

    <div class="gate-eyebrow">Accès restreint</div>
    <h2 class="gate-title" id="gate-title-text">Contenu réservé aux membres</h2>
    <p class="gate-desc">
      Cette section nécessite un compte. Rejoignez notre communauté de lecteurs pour accéder à l'intégralité du catalogue, vos achats et bien plus.
    </p>

    <div class="gate-page-hint" id="gate-page-hint">
      <i class="bi bi-arrow-right-circle"></i>
      <span id="gate-page-name">Page protégée</span>
    </div>

    <div class="gate-actions">
      <a href="#" id="gate-login-btn" class="btn-gate-primary">
        <i class="bi bi-box-arrow-in-right"></i> Se connecter
      </a>
      <a href="#" id="gate-register-btn" class="btn-gate-ghost">
        <i class="bi bi-person-plus"></i> S'inscrire
      </a>
    </div>

    <div class="gate-divider"><span>redirection automatique</span></div>

    <div class="gate-countdown">
      <i class="bi bi-clock"></i>
      <span id="gate-countdown-text">Redirection vers login dans 5s…</span>
    </div>
    <div class="countdown-bar">
      <div class="countdown-fill" id="countdown-fill"></div>
    </div>
  </div>
</div>

<!-- MOBILE MENU -->
<div id="mobile-menu">
  <a href="index.php">Accueil</a>
  <a href="catalogue.php" data-protected="true">Catalogue</a>
  <a href="livres.php" data-protected="true">Livres</a>
  <a href="statistiques.php" data-protected="true">Statistiques</a>
  <?php if ($isLoggedIn): ?>
    <a href="admin_dashboard.php" style="color:var(--cyan)">Dashboard</a>
    <a href="logout.php">Déconnexion</a>
  <?php else: ?>
    <a href="login.php">Connexion</a>
    <a href="register.php" style="color:var(--cyan)">Inscription</a>
  <?php endif; ?>
</div>

<!-- HEADER -->
<header id="header">
  <a href="index.php" class="nav-logo">
    <div class="logo-icon">📚</div>
    <span>Digital <span style="color:var(--cyan)">Library</span></span>
  </a>

  <nav>
    <ul class="nav-links">
      <li><a href="index.php" class="active">Accueil</a></li>
      <?php if ($isLoggedIn): ?>
        <li><a href="catalogue.php">Catalogue</a></li>
        <li><a href="livres.php">Livres</a></li>
        <li><a href="ajouter_livre.php">Ajouter</a></li>
        <li><a href="mes_achats.php">Mes achats</a></li>
        <li><a href="statistiques.php">Statistiques</a></li>
        <li><a href="admin_dashboard.php">Admin</a></li>
      <?php else: ?>
        <li><a href="catalogue.php" data-protected="true" data-page="Catalogue">Catalogue</a></li>
        <li><a href="livres.php" data-protected="true" data-page="Livres">Livres</a></li>
        <li><a href="ajouter_livre.php" data-protected="true" data-page="Ajouter un livre">Ajouter</a></li>
        <li><a href="mes_achats.php" data-protected="true" data-page="Mes achats">Mes achats</a></li>
        <li><a href="statistiques.php" data-protected="true" data-page="Statistiques">Stats</a></li>
        <li><a href="admin_dashboard.php" data-protected="true" data-page="Administration">Admin</a></li>
      <?php endif; ?>
    </ul>
  </nav>

  <div class="nav-actions">
  <a href="logout1.php" class="btn-logout">
    <i class="bi bi-box-arrow-right"></i>
    <span>Mode Invité</span>
</a>
      <a href="login.php" class="btn-nav-ghost">Connexion</a>
      <a href="register.php" class="btn-nav-primary">Commencer</a>
  </div>

  <button id="hamburger" aria-label="Menu">
    <i class="bi bi-list"></i>
  </button>
</header>

<!-- HERO -->
<section id="hero">
  <div class="hero-inner">
    <div class="hero-badge">
      <span class="badge-dot"></span>
      Bibliothèque Numérique Intelligente — v3.0
    </div>

    <h1 class="hero-title">
      <span class="line-1">Bienvenue dans votre</span>
      <span class="line-2">bibliothèque numérique</span>
    </h1>

    <p class="hero-sub">
      Accédez à des milliers d'ouvrages, gérez votre collection personnelle et plongez dans une expérience de lecture immersive conçue pour l'ère digitale.
    </p>

    <div class="hero-ctas">
      <a href="catalogue.php" class="btn-primary" <?= !$isLoggedIn ? 'data-protected="true" data-page="Catalogue"' : '' ?>>
        <i class="bi bi-compass"></i> Explorer le catalogue
      </a>
      <?php if (!$isLoggedIn): ?>
        <a href="register.php" class="btn-outline">
          <i class="bi bi-arrow-right"></i> Commencer gratuitement
        </a>
      <?php else: ?>
        <a href="mes_achats.php" class="btn-outline">
          <i class="bi bi-bag-check"></i> Mes achats
        </a>
      <?php endif; ?>
    </div>

    <div class="hero-stats">
      <div class="stat-item"><div class="stat-number">12K+</div><div class="stat-label">Livres disponibles</div></div>
      <div class="stat-item"><div class="stat-number">3.4K</div><div class="stat-label">Lecteurs actifs</div></div>
      <div class="stat-item"><div class="stat-number">98%</div><div class="stat-label">Satisfaction</div></div>
    </div>
  </div>

  <div class="scroll-hint">
    <i class="bi bi-chevron-down"></i>
    <span>Défiler</span>
  </div>
</section>

<!-- BOOKS SECTION -->
<section class="section" id="livres" style="max-width:1200px;margin:0 auto">
  <div class="books-header reveal">
    <div class="section-label">📚 Collection</div>
    <h2 class="section-title">Livres à la une</h2>
    <p class="section-sub" style="margin:0 auto">Découvrez notre sélection premium, curatée par nos experts littéraires pour satisfaire toutes les passions.</p>
  </div>

  <div class="books-grid">
    <?php
    $books = [
      ['emoji'=>'🌌','cover'=>'cover-1','genre'=>'Science-Fiction','title'=>"L'Œil de l'Univers",'author'=>'Elena Korvach','rating'=>'4.9','badge'=>'badge-new','badge-label'=>'NOUVEAU','id'=>1],
      ['emoji'=>'🧠','cover'=>'cover-2','genre'=>'Philosophie','title'=>'Le Paradoxe du Libre Arbitre','author'=>'Jean-Marc Duvall','rating'=>'4.7','badge'=>'badge-hot','badge-label'=>'POPULAIRE','id'=>2],
      ['emoji'=>'🌿','cover'=>'cover-3','genre'=>'Nature','title'=>'Forêts Oubliées','author'=>'Amara Diallo','rating'=>'4.6','badge'=>'badge-free','badge-label'=>'GRATUIT','id'=>3],
      ['emoji'=>'⚙️','cover'=>'cover-4','genre'=>'Technologie','title'=>'IA & Humanité','author'=>'Dr. Kai Tanaka','rating'=>'4.8','badge'=>'badge-hot','badge-label'=>'TENDANCE','id'=>4],
      ['emoji'=>'📜','cover'=>'cover-5','genre'=>'Histoire','title'=>'Empires Disparus','author'=>'Sofia Mercier','rating'=>'4.5','badge'=>'badge-new','badge-label'=>'NOUVEAU','id'=>5],
      ['emoji'=>'🎭','cover'=>'cover-6','genre'=>'Littérature','title'=>'Masques & Miroirs','author'=>'Léon Beaumont','rating'=>'4.9','badge'=>'badge-free','badge-label'=>'GRATUIT','id'=>6],
    ];
    foreach ($books as $book):
    ?>
    <div class="book-card">
      <div class="book-cover <?= $book['cover'] ?>">
        <div class="book-cover-inner"><?= $book['emoji'] ?></div>
        <span class="book-badge <?= $book['badge'] ?>"><?= $book['badge-label'] ?></span>
      </div>
      <div class="book-info">
        <div class="book-genre"><?= $book['genre'] ?></div>
        <div class="book-title"><?= $book['title'] ?></div>
        <div class="book-author">par <?= $book['author'] ?></div>
        <div class="book-rating">
          <span class="stars">★★★★★</span>
          <span class="rating-val"><?= $book['rating'] ?></span>
        </div>
        <div class="book-actions">
          <?php if ($isLoggedIn): ?>
            <a href="lire.php?id=<?= $book['id'] ?>" class="btn-read"><i class="bi bi-book"></i> Lire</a>
            <a href="achat.php?id=<?= $book['id'] ?>" class="btn-buy"><i class="bi bi-bag"></i> Acheter</a>
          <?php else: ?>
            <a href="lire.php?id=<?= $book['id'] ?>" class="btn-read" data-protected="true" data-page="Lecture"><i class="bi bi-book"></i> Lire</a>
            <a href="achat.php?id=<?= $book['id'] ?>" class="btn-buy" data-protected="true" data-page="Achat"><i class="bi bi-bag"></i> Acheter</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="text-align:center;margin-top:3rem" class="reveal">
    <a href="catalogue.php" class="btn-outline" style="display:inline-flex"
      <?= !$isLoggedIn ? 'data-protected="true" data-page="Catalogue"' : '' ?>>
      Voir tout le catalogue <i class="bi bi-arrow-right" style="margin-left:8px"></i>
    </a>
  </div>
</section>

<div class="divider"></div>

<!-- FEATURES -->
<section class="section" style="max-width:1200px;margin:0 auto;padding:6rem 2rem">
  <div class="reveal">
    <div class="section-label">⚡ Fonctionnalités</div>
    <h2 class="section-title">Tout ce dont vous avez besoin</h2>
    <p class="section-sub">Une plateforme complète pour explorer, lire, acheter et analyser votre expérience littéraire.</p>
  </div>
  <div class="features-grid">
    <div class="feature-card"><div class="feature-icon icon-cyan"><i class="bi bi-display"></i></div><div class="feature-title">Lecture en ligne</div><div class="feature-desc">Interface de lecture immersive avec mode nuit, police adaptable et synchronisation multi-appareils pour une expérience optimale.</div></div>
    <div class="feature-card"><div class="feature-icon icon-violet"><i class="bi bi-shield-lock"></i></div><div class="feature-title">Achat sécurisé</div><div class="feature-desc">Transactions cryptées, historique d'achats complet et accès immédiat à vos livres après paiement. Votre sécurité est notre priorité.</div></div>
    <div class="feature-card"><div class="feature-icon icon-neon"><i class="bi bi-search"></i></div><div class="feature-title">Recherche intelligente</div><div class="feature-desc">Moteur de recherche sémantique alimenté par l'IA pour trouver exactement ce que vous cherchez, même avec des mots-clés imprécis.</div></div>
    <div class="feature-card"><div class="feature-icon icon-orange"><i class="bi bi-graph-up-arrow"></i></div><div class="feature-title">Statistiques avancées</div><div class="feature-desc">Suivez vos habitudes de lecture, analysez vos genres préférés et visualisez votre progression avec des graphiques interactifs.</div></div>
  </div>
</section>

<div class="divider"></div>

<!-- AI ASSISTANT -->
<section id="assistant" style="max-width:900px;margin:0 auto;padding:6rem 2rem" class="reveal">
  <div style="text-align:center;margin-bottom:2.5rem">
    <div class="section-label">🤖 Assistant IA</div>
    <h2 class="section-title">Votre guide littéraire intelligent</h2>
  </div>
  <div class="ai-wrapper">
    <div class="ai-topbar">
      <div class="ai-dot ai-dot-red"></div>
      <div class="ai-dot ai-dot-yellow"></div>
      <div class="ai-dot ai-dot-green"></div>
      <span class="ai-title">bibliotheque-ia — assistant-v2.0</span>
    </div>
    <div class="ai-body">
      <div class="ai-prompt">$ assistant.ia --mode=recommendation --user=<?= $isLoggedIn ? htmlspecialchars($_SESSION['username'] ?? 'user') : 'guest' ?></div>
      <div id="ai-text"><span class="ai-cursor"></span></div>
    </div>
    <div class="datetime-widget">
      <div class="dt-item"><div class="dt-label">Date</div><div class="dt-value" id="dt-date">—</div></div>
      <div class="dt-sep">·</div>
      <div class="dt-item"><div class="dt-label">Heure</div><div class="dt-value" id="dt-time">—</div></div>
      <div class="dt-sep">·</div>
      <div class="dt-item"><div class="dt-label">Session</div><div class="dt-value" style="color:var(--neon);font-size:0.9rem"><?= $isLoggedIn ? '🟢 Connecté' : '🔴 Invité' ?></div></div>
    </div>
  </div>
</section>

<!-- CTA -->
<section id="cta" class="reveal">
  <div class="cta-inner">
    <div class="section-label" style="justify-content:center;display:flex">🚀 Accès</div>
    <h2 class="cta-title">Prêt à plonger dans l'univers des livres ?</h2>
    <p class="cta-sub">Rejoignez des milliers de lecteurs passionnés. Accédez à notre système complet dès maintenant.</p>
    <a href="login.php" class="btn-cta">
      <i class="bi bi-box-arrow-in-right"></i>
      <?= $isLoggedIn ? 'Accéder au catalogue' : 'Accéder au système' ?>
    </a>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-grid">
    <div class="footer-brand">
      <a href="index.php" class="nav-logo" style="text-decoration:none">
        <div class="logo-icon">📚</div>
        <span style="font-family:'Syne',sans-serif;font-weight:800">Digital Library</span>
      </a>
      <p>Une plateforme moderne pour explorer, lire et gérer votre bibliothèque numérique personnelle. Conçue pour les passionnés de lecture.</p>
    </div>
    <div class="footer-col">
      <h4>Navigation</h4>
      <ul>
        <li><a href="index.php">Accueil</a></li>
        <li><a href="catalogue.php" <?= !$isLoggedIn ? 'data-protected="true" data-page="Catalogue"' : '' ?>>Catalogue</a></li>
        <li><a href="livres.php" <?= !$isLoggedIn ? 'data-protected="true" data-page="Livres"' : '' ?>>Livres</a></li>
        <li><a href="statistiques.php" <?= !$isLoggedIn ? 'data-protected="true" data-page="Statistiques"' : '' ?>>Statistiques</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Compte</h4>
      <ul>
        <li><a href="login.php">Connexion</a></li>
        <li><a href="register.php">Inscription</a></li>
        <li><a href="mes_achats.php" <?= !$isLoggedIn ? 'data-protected="true" data-page="Mes achats"' : '' ?>>Mes achats</a></li>
        <li><a href="admin_dashboard.php" <?= !$isLoggedIn ? 'data-protected="true" data-page="Administration"' : '' ?>>Admin</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Ressources</h4>
      <ul>
        <li><a href="ajouter_livre.php" <?= !$isLoggedIn ? 'data-protected="true" data-page="Ajouter un livre"' : '' ?>>Ajouter un livre</a></li>
        <li><a href="#">Documentation</a></li>
        <li><a href="#">Aide</a></li>
        <li><a href="logout.php">Déconnexion</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <p>© <?= date('Y') ?> Digital Library System — Tous droits réservés.</p>
    <div class="footer-tech">
      <span class="tech-badge">PHP</span>
      <span class="tech-badge">TailwindCSS</span>
      <span class="tech-badge">JS</span>
    </div>
  </div>
</footer>

<!-- TOAST -->
<div id="toast" role="alert" aria-live="polite">
  <span class="toast-icon" id="toast-icon">✅</span>
  <div class="toast-content">
    <span class="toast-title" id="toast-title">Notification</span>
    <span class="toast-sub" id="toast-sub"></span>
  </div>
</div>

<!-- ============================================================
     JAVASCRIPT
============================================================ -->
<script>
// ─────────────────────────────────────────────────────────────
// CONFIG
// ─────────────────────────────────────────────────────────────
const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;
const USERNAME     = <?= json_encode($username) ?>;

// Pages publiques — jamais bloquées
const PUBLIC_PAGES = ['index.php', 'login.php', 'register.php', '#', ''];

// ─────────────────────────────────────────────────────────────
// CURSOR
// ─────────────────────────────────────────────────────────────
const cursor = document.getElementById('cursor');
const ring   = document.getElementById('cursor-ring');
let mx = 0, my = 0, rx = 0, ry = 0;

document.addEventListener('mousemove', e => {
  mx = e.clientX; my = e.clientY;
  cursor.style.left = mx + 'px';
  cursor.style.top  = my + 'px';
});

(function animateRing() {
  rx += (mx - rx) * 0.12;
  ry += (my - ry) * 0.12;
  ring.style.left = rx + 'px';
  ring.style.top  = ry + 'px';
  requestAnimationFrame(animateRing);
})();

document.querySelectorAll('a, button').forEach(el => {
  el.addEventListener('mouseenter', () => { cursor.style.width='18px'; cursor.style.height='18px'; cursor.style.background='var(--neon)'; ring.style.width='50px'; ring.style.height='50px'; });
  el.addEventListener('mouseleave', () => { cursor.style.width='10px'; cursor.style.height='10px'; cursor.style.background='var(--cyan)'; ring.style.width='36px'; ring.style.height='36px'; });
});

// ─────────────────────────────────────────────────────────────
// TOAST — enhanced with types
// ─────────────────────────────────────────────────────────────
let toastTimer = null;

function showToast(title, sub = '', type = 'default', duration = 4000) {
  const toast    = document.getElementById('toast');
  const iconEl   = document.getElementById('toast-icon');
  const titleEl  = document.getElementById('toast-title');
  const subEl    = document.getElementById('toast-sub');

  const icons = { default: '✅', warn: '⚠️', error: '🔒', success: '🎉', info: 'ℹ️' };
  const classes = { warn: 'toast-warn', error: 'toast-error', success: 'toast-success' };

  toast.className = toast.className.replace(/toast-\w+/g, '');
  if (classes[type]) toast.classList.add(classes[type]);

  iconEl.textContent  = icons[type] || icons.default;
  titleEl.textContent = title;
  subEl.textContent   = sub;

  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), duration);
}

// ─────────────────────────────────────────────────────────────
// INTRO
// ─────────────────────────────────────────────────────────────
const bootLines = [
  { id: 'b1', delay: 200 },
  { id: 'b2', delay: 700 },
  { id: 'b3', delay: 1300 },
  { id: 'b4', delay: 1900 },
  { id: 'b5', delay: 2600 },
];
const fill  = document.getElementById('progress');
const intro = document.getElementById('intro');

bootLines.forEach((line, i) => {
  setTimeout(() => {
    document.getElementById(line.id).classList.add('show');
    fill.style.width = ((i + 1) / bootLines.length * 100) + '%';
  }, line.delay);
});

setTimeout(() => {
  intro.classList.add('hide');
  document.body.style.overflow = 'auto';
  const msg = IS_LOGGED_IN ? `Bienvenue, ${USERNAME} 👋` : 'Bienvenue sur Digital Library !';
  const sub = IS_LOGGED_IN ? 'Accès complet activé.' : 'Connectez-vous pour accéder à tout le contenu.';
  showToast(msg, sub, IS_LOGGED_IN ? 'success' : 'default', 5000);
}, 3800);

// ─────────────────────────────────────────────────────────────
// HAMBURGER
// ─────────────────────────────────────────────────────────────
const ham        = document.getElementById('hamburger');
const mobileMenu = document.getElementById('mobile-menu');
ham.addEventListener('click', () => {
  mobileMenu.classList.toggle('open');
  ham.innerHTML = mobileMenu.classList.contains('open')
    ? '<i class="bi bi-x-lg"></i>'
    : '<i class="bi bi-list"></i>';
});

// ─────────────────────────────────────────────────────────────
// DATETIME
// ─────────────────────────────────────────────────────────────
function updateDatetime() {
  const now = new Date();
  document.getElementById('dt-date').textContent = now.toLocaleDateString('fr-FR', { day:'2-digit', month:'long', year:'numeric' });
  document.getElementById('dt-time').textContent = now.toLocaleTimeString('fr-FR');
}
setInterval(updateDatetime, 1000);
updateDatetime();

// ─────────────────────────────────────────────────────────────
// SCROLL REVEAL
// ─────────────────────────────────────────────────────────────
const revealObs = new IntersectionObserver(entries => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); revealObs.unobserve(e.target); } });
}, { threshold: 0.12 });
document.querySelectorAll('.reveal').forEach(el => revealObs.observe(el));

const cardObs = new IntersectionObserver((entries) => {
  entries.forEach((e, i) => {
    if (e.isIntersecting) { setTimeout(() => e.target.classList.add('visible'), i * 100); cardObs.unobserve(e.target); }
  });
}, { threshold: 0.1 });
document.querySelectorAll('.book-card, .feature-card').forEach(el => cardObs.observe(el));

// ─────────────────────────────────────────────────────────────
// TYPING EFFECT
// ─────────────────────────────────────────────────────────────
const aiPhrases = IS_LOGGED_IN
  ? [
      `Bienvenue ${USERNAME} ! Que souhaitez-vous lire aujourd'hui ?`,
      "Je vois que vous avez des préférences littéraires variées. Excellent goût !",
      "Votre bibliothèque personnelle est synchronisée et prête à l'emploi.",
      "Conseil du jour : explorez notre section Science-Fiction, de nouveaux titres viennent d'arriver.",
    ]
  : [
      "Bonjour ! Je suis votre assistant littéraire IA. Comment puis-je vous aider ?",
      "Je peux vous recommander des livres basés sur vos préférences. Inscrivez-vous !",
      "Nous avons 12 847 livres dans 48 catégories. Connectez-vous pour y accéder.",
      "Votre prochaine grande lecture vous attend. Créez votre compte gratuitement.",
    ];

let phraseIdx = 0, charIdx = 0, isDeleting = false;
const aiEl = document.getElementById('ai-text');

function typeEffect() {
  const phrase = aiPhrases[phraseIdx];
  const cur = '<span class="ai-cursor"></span>';
  if (!isDeleting && charIdx <= phrase.length) {
    aiEl.innerHTML = phrase.substring(0, charIdx) + cur; charIdx++;
    setTimeout(typeEffect, 40);
  } else if (isDeleting && charIdx >= 0) {
    aiEl.innerHTML = phrase.substring(0, charIdx) + cur; charIdx--;
    setTimeout(typeEffect, 20);
  } else if (!isDeleting && charIdx > phrase.length) {
    isDeleting = true; setTimeout(typeEffect, 2500);
  } else {
    isDeleting = false; phraseIdx = (phraseIdx + 1) % aiPhrases.length; charIdx = 0;
    setTimeout(typeEffect, 400);
  }
}
setTimeout(typeEffect, 4200);

// ─────────────────────────────────────────────────────────────
// HEADER SCROLL
// ─────────────────────────────────────────────────────────────
const header = document.getElementById('header');
window.addEventListener('scroll', () => {
  if (window.scrollY > 60) {
    header.style.background = 'rgba(6,9,16,0.92)';
    header.style.borderBottomColor = 'rgba(0,212,255,0.1)';
  } else {
    header.style.background = 'rgba(6,9,16,0.6)';
    header.style.borderBottomColor = 'rgba(255,255,255,0.08)';
  }
});

// ─────────────────────────────────────────────────────────────
// PARALLAX ORBS
// ─────────────────────────────────────────────────────────────
document.addEventListener('mousemove', e => {
  const x = (e.clientX / window.innerWidth - 0.5) * 20;
  const y = (e.clientY / window.innerHeight - 0.5) * 20;
  document.querySelector('.orb-1').style.transform = `translate(${x}px, ${y}px)`;
  document.querySelector('.orb-2').style.transform = `translate(${-x}px, ${-y}px)`;
});

// ═══════════════════════════════════════════════════════════════
// ██████████████████████████████████████████████████████████████
//   ACCESS GUARD — Système de restriction d'accès
// ██████████████████████████████████████████████████████████████
// ═══════════════════════════════════════════════════════════════

(function AccessGuard() {

  // ── Config ────────────────────────────────────────────────
  const REDIRECT_DELAY   = 5000;   // ms avant redirection auto
  const LOGIN_URL        = 'login.php';
  const REGISTER_URL     = 'register.php';

  // Pages considérées publiques (jamais bloquées)
  const publicPaths = ['index.php', 'login.php', 'register.php', '#', ''];

  let countdownTimer   = null;
  let countdownInterval = null;
  let pendingHref      = null;       // page que l'user voulait atteindre

  // ── Éléments DOM ─────────────────────────────────────────
  const gate          = document.getElementById('auth-gate');
  const gateBackdrop  = document.getElementById('gate-backdrop');
  const gateClose     = document.getElementById('gate-close');
  const gatePageName  = document.getElementById('gate-page-name');
  const gateLoginBtn  = document.getElementById('gate-login-btn');
  const gateRegBtn    = document.getElementById('gate-register-btn');
  const cdText        = document.getElementById('gate-countdown-text');
  const cdFill        = document.getElementById('countdown-fill');

  // ── Utilitaires ───────────────────────────────────────────

  /** Vérifie si un href est protégé */
  function isProtectedHref(href) {
    if (!href || href.startsWith('#') || href === '') return false;
    try {
      const url      = new URL(href, window.location.origin);
      const filename = url.pathname.split('/').pop() || 'index.php';
      return !publicPaths.includes(filename);
    } catch {
      return false;
    }
  }

  /** Construit l'URL de login avec paramètre redirect */
  function buildLoginUrl(redirect) {
    const encoded = encodeURIComponent(redirect || '');
    return `${LOGIN_URL}?redirect=${encoded}`;
  }

  /** Ouvre la modale d'auth */
  function openGate(href, pageName) {
    pendingHref = href;

    // Mettre à jour le contenu de la modale
    const label = pageName || href.split('/').pop().replace('.php','').replace(/_/g,' ') || 'Page protégée';
    gatePageName.textContent = '🔒 ' + label.charAt(0).toUpperCase() + label.slice(1);

    // URLs avec paramètre redirect
    const loginUrl    = buildLoginUrl(href);
    const registerUrl = buildLoginUrl(href).replace(LOGIN_URL, REGISTER_URL);
    gateLoginBtn.href    = loginUrl;
    gateRegBtn.href      = registerUrl;

    // Ouvrir la modale
    gate.classList.add('open');
    document.body.style.overflow = 'hidden';

    // Démarrer le compte à rebours
    startCountdown(REDIRECT_DELAY, loginUrl);

    // Toast discret
    showToast(
      '🔒 Accès restreint',
      'Connectez-vous pour accéder à cette page.',
      'error',
      3500
    );
  }

  /** Ferme la modale */
  function closeGate() {
    gate.classList.remove('open');
    document.body.style.overflow = '';
    stopCountdown();
    pendingHref = null;
  }

  /** Démarre le compte à rebours et redirige */
  function startCountdown(ms, redirectUrl) {
    stopCountdown();
    const startTime = Date.now();

    cdFill.style.transition = 'none';
    cdFill.style.transform  = 'scaleX(1)';

    // Force reflow
    cdFill.getBoundingClientRect();

    cdFill.style.transition = `transform ${ms}ms linear`;
    cdFill.style.transform  = 'scaleX(0)';

    const totalSec = Math.round(ms / 1000);

    countdownInterval = setInterval(() => {
      const elapsed  = Date.now() - startTime;
      const remaining = Math.max(0, Math.ceil((ms - elapsed) / 1000));
      cdText.textContent = `Redirection vers login dans ${remaining}s…`;
    }, 200);

    countdownTimer = setTimeout(() => {
      stopCountdown();
      window.location.href = redirectUrl;
    }, ms);
  }

  /** Arrête le compte à rebours */
  function stopCountdown() {
    clearTimeout(countdownTimer);
    clearInterval(countdownInterval);
    cdFill.style.transition = 'none';
    cdFill.style.transform  = 'scaleX(1)';
  }

  // ── Interception des clics ────────────────────────────────

  /**
   * Intercepteur principal : attache un listener à chaque
   * élément avec data-protected="true" ou dont le href
   * pointe vers une page protégée.
   */
  function interceptAll() {
    // Sélectionner tous les liens et boutons potentiellement protégés
    const candidates = document.querySelectorAll('a[href], button[data-href]');

    candidates.forEach(el => {
      // Déjà traité ?
      if (el.dataset.guardAttached) return;
      el.dataset.guardAttached = 'true';

      const href      = el.getAttribute('href') || el.dataset.href || '';
      const pageName  = el.dataset.page || '';
      const isManuallyProtected = el.dataset.protected === 'true';
      const isHrefProtected     = isProtectedHref(href);

      // Sauter si page publique et pas de flag manuel
      if (!isManuallyProtected && !isHrefProtected) return;

      // Si l'utilisateur est connecté, ne rien faire
      if (IS_LOGGED_IN) return;

      el.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        openGate(href, pageName);
      }, true);

      // Feedback visuel subtil au hover (pas de title natif)
      el.addEventListener('mouseenter', function() {
        if (!IS_LOGGED_IN) {
          ring.style.borderColor = 'rgba(251,191,36,0.6)';
          ring.style.width  = '44px';
          ring.style.height = '44px';
        }
      });
      el.addEventListener('mouseleave', function() {
        ring.style.borderColor = 'rgba(0,212,255,0.5)';
        ring.style.width  = '36px';
        ring.style.height = '36px';
      });
    });
  }

  // ── Fermeture modale ──────────────────────────────────────
  gateClose.addEventListener('click', closeGate);
  gateBackdrop.addEventListener('click', closeGate);

  // Escape key
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && gate.classList.contains('open')) closeGate();
  });

  // Clic sur boutons de la modale → stopper le countdown
  gateLoginBtn.addEventListener('click', stopCountdown);
  gateRegBtn.addEventListener('click', stopCountdown);

  // ── Init ─────────────────────────────────────────────────
  // Lancer après l'intro pour éviter tout conflit
  const INIT_DELAY = IS_LOGGED_IN ? 0 : 4000;
  setTimeout(interceptAll, INIT_DELAY);

  // Ré-intercepter si du contenu dynamique est ajouté (MutationObserver)
  const observer = new MutationObserver(() => interceptAll());
  observer.observe(document.body, { childList: true, subtree: true });

  // Exposer closeGate globalement (utile pour d'autres scripts)
  window.AccessGuard = { openGate, closeGate, interceptAll };

})(); // END AccessGuard

</script>

<script>
const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;

document.querySelectorAll('[data-protected]').forEach(link => {
  link.addEventListener('click', function(e) {
    if (!isLoggedIn) {
      e.preventDefault();

      // 🔥 Ouvre ton modal stylé
      document.getElementById('auth-gate').classList.add('open');

      // (optionnel) stocker la destination
      const target = this.getAttribute('href');
      document.getElementById('auth-gate').dataset.redirect = target;
    }

    showToast("Accès réservé", "Connecte-toi pour continuer", "warn");
  });
});


</script>


</body>
</html>