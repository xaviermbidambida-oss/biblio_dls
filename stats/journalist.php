<?php
/**
 * stats/journalist.php — Statistiques personnelles journaliste
 * Connecté 100% à la BD
 */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit; }
$userRole = $_SESSION['user_role'] ?? 'lecteur';
if (!in_array($userRole, ['admin', 'journaliste'], true)) { header('Location: ../dashboard.php?error=access_denied'); exit; }

$userId   = (int)$_SESSION['user_id'];
$isAdmin  = $userRole === 'admin';
$username = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');

// Journaliste consulté (admin peut voir les stats d'un journaliste spécifique)
$targetId = $isAdmin && isset($_GET['user_id']) ? (int)$_GET['user_id'] : $userId;

// BD
$pdo = null;
foreach ([dirname(__DIR__).'/includes/config.php', dirname(__DIR__).'/config/config.php'] as $cp) {
    if (file_exists($cp)) { require_once $cp; break; }
}
if (!isset($pdo) || $pdo === null) {
    $h=defined('DB_HOST')?DB_HOST:'localhost'; $n=defined('DB_NAME')?DB_NAME:'digital_library';
    $u=defined('DB_USER')?DB_USER:'root'; $p=defined('DB_PASS')?DB_PASS:'';
    try { $pdo=new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4",$u,$p,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
    catch(Exception $e){ die('<p style="color:red">Erreur BD : '.$e->getMessage().'</p>'); }
}

// Infos utilisateur cible
$userInfoSt = $pdo->prepare("SELECT CONCAT(prenom,' ',nom) AS nom, email, created_at FROM users WHERE id=?");
$userInfoSt->execute([$targetId]);
$targetUser = $userInfoSt->fetch() ?: ['nom'=>'Journaliste', 'email'=>'—', 'created_at'=>date('Y-m-d')];

// Stats globales
$statsSt = $pdo->prepare(
    "SELECT
       COUNT(*) AS total,
       SUM(statut='disponible') AS publies,
       SUM(statut='archive') AS archives,
       COALESCE(SUM(nb_ventes),0) AS ventes_total,
       COALESCE(AVG(NULLIF(note_moyenne,0)),0) AS note_moy,
       COALESCE(SUM(prix*nb_ventes),0) AS revenus_estimes
     FROM livres WHERE ajoute_par=?"
);
$statsSt->execute([$targetId]);
$stats = $statsSt->fetch();

// Revenus réels (depuis table achats)
$revSt = $pdo->prepare("SELECT COALESCE(SUM(a.montant),0) AS total, COUNT(*) AS nb FROM achats a JOIN livres l ON l.id=a.livre_id WHERE l.ajoute_par=? AND a.statut='confirme'");
$revSt->execute([$targetId]);
$revData = $revSt->fetch();

// Revenus par mois (12 derniers mois)
$revMoisSt = $pdo->prepare(
    "SELECT DATE_FORMAT(a.created_at,'%Y-%m') AS mois,
            SUM(a.montant) AS montant,
            COUNT(*) AS nb_ventes
     FROM achats a
     JOIN livres l ON l.id=a.livre_id
     WHERE l.ajoute_par=? AND a.statut='confirme'
       AND a.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     GROUP BY mois ORDER BY mois"
);
$revMoisSt->execute([$targetId]);
$revMois = $revMoisSt->fetchAll();

// Mes livres avec détails
$livresSt = $pdo->prepare(
    "SELECT l.id, l.titre, l.statut, l.prix, l.note_moyenne, l.nb_ventes,
            l.pages, l.annee_parution, l.created_at,
            c.nom AS categorie,
            COUNT(a.id) AS achats_confirmes,
            COALESCE(SUM(a.montant),0) AS revenus_livre,
            (SELECT COUNT(*) FROM avis av WHERE av.livre_id=l.id AND av.statut='publie') AS nb_avis
     FROM livres l
     LEFT JOIN categories c ON c.id=l.categorie_id
     LEFT JOIN achats a ON a.livre_id=l.id AND a.statut='confirme'
     WHERE l.ajoute_par=?
     GROUP BY l.id
     ORDER BY l.created_at DESC"
);
$livresSt->execute([$targetId]);
$mesLivres = $livresSt->fetchAll();

// Top avis
$avisSt = $pdo->prepare(
    "SELECT a.note, a.commentaire, a.created_at,
            l.titre AS livre_titre,
            CONCAT(u.prenom,' ',u.nom) AS lecteur
     FROM avis a
     JOIN livres l ON l.id=a.livre_id
     JOIN users u ON u.id=a.user_id
     WHERE l.ajoute_par=? AND a.statut='publie'
     ORDER BY a.created_at DESC LIMIT 10"
);
$avisSt->execute([$targetId]);
$avisRecents = $avisSt->fetchAll();

// Evolution ventes 7 jours
$semSt = $pdo->prepare(
    "SELECT DATE(a.created_at) AS jour, COUNT(*) AS ventes, SUM(a.montant) AS montant
     FROM achats a JOIN livres l ON l.id=a.livre_id
     WHERE l.ajoute_par=? AND a.statut='confirme' AND a.created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)
     GROUP BY jour ORDER BY jour"
);
$semSt->execute([$targetId]);
$semaine = $semSt->fetchAll();

function fmtFCFA(float $n): string {
    return number_format($n, 0, ',', ' ') . ' FCFA';
}
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'à l\'instant';
    if ($diff < 3600) return (int)($diff/60) . ' min';
    if ($diff < 86400) return (int)($diff/3600) . 'h';
    return date('d/m/Y', strtotime($dt));
}

// Préparer données graphique mensuel
$moisLabels = [];
$moisVentes = [];
$moisMontants = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $moisLabels[] = date('M', strtotime($m.'-01'));
    $found = false;
    foreach ($revMois as $rm) {
        if ($rm['mois'] === $m) { $moisVentes[] = (int)$rm['nb_ventes']; $moisMontants[] = (float)$rm['montant']; $found = true; break; }
    }
    if (!$found) { $moisVentes[] = 0; $moisMontants[] = 0; }
}
$maxVentes = max(1, ...array_map('intval', $moisVentes));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes statistiques — DLS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@400;500;600&family=Space+Mono:wght@400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#05080f;--surface:#0b1020;--card:rgba(255,255,255,.032);--card-hov:rgba(255,255,255,.058);
  --border:rgba(255,255,255,.07);--cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;
  --amber:#f59e0b;--rose:#f43f5e;--orange:#f97316;
  --txt:#eef2ff;--txt2:rgba(238,242,255,.55);--txt3:rgba(238,242,255,.28);
  --r:10px;--r-lg:16px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--txt);min-height:100vh}
.page{max-width:1200px;margin:0 auto;padding:2rem 1.5rem}

/* NAV */
.nav{display:flex;align-items:center;gap:.6rem;margin-bottom:1.8rem;flex-wrap:wrap}
.nav a{font-size:.75rem;color:var(--txt3);text-decoration:none;display:flex;align-items:center;gap:4px;padding:5px 10px;border-radius:var(--r);border:1px solid var(--border);background:var(--card);transition:all .18s}
.nav a:hover,.nav a.active{color:var(--amber);border-color:rgba(245,158,11,.3)}
.nav-spacer{flex:1}

/* HEADER */
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.8rem}
.page-title{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;letter-spacing:-.5px}
.page-sub{font-size:.8rem;color:var(--txt2);margin-top:.3rem}
.user-chip{display:flex;align-items:center;gap:8px;padding:8px 13px;background:var(--card);border:1px solid var(--border);border-radius:var(--r-lg)}
.user-av{width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,var(--amber),var(--orange));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.8rem}

/* STATS GRID */
.stats-g{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:.9rem;margin-bottom:1.6rem}
.stat-c{background:var(--card);border:1px solid var(--border);border-radius:var(--r-lg);padding:1.2rem 1.4rem;position:relative;overflow:hidden;transition:transform .2s,border-color .2s}
.stat-c:hover{transform:translateY(-3px);border-color:rgba(255,255,255,.1)}
.stat-c::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--c1,#fff),var(--c2,#888));opacity:0;transition:opacity .3s}
.stat-c:hover::before{opacity:1}
.stat-c:nth-child(1){--c1:var(--amber);--c2:var(--orange)}
.stat-c:nth-child(2){--c1:var(--neon);--c2:var(--cyan)}
.stat-c:nth-child(3){--c1:var(--cyan);--c2:var(--violet)}
.stat-c:nth-child(4){--c1:var(--violet);--c2:var(--rose)}
.stat-c:nth-child(5){--c1:var(--rose);--c2:var(--amber)}
.stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;margin-bottom:.8rem}
.stat-v{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;letter-spacing:-.5px;line-height:1;background:linear-gradient(135deg,var(--c1,#fff),var(--c2,#aaa));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.stat-l{font-size:.72rem;color:var(--txt2);margin-top:4px}
.stat-sub{font-size:.62rem;font-family:'Space Mono',monospace;margin-top:6px;color:var(--txt3)}

/* GRID 2 colonnes */
.dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:1.5rem}
@media(max-width:900px){.dash-grid{grid-template-columns:1fr}}

/* CARD */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden}
.card-hdr{padding:1rem 1.3rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.8rem}
.card-title{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;display:flex;align-items:center;gap:7px}
.card-body{padding:1rem 1.3rem}

/* CHART */
.chart-bars{display:flex;align-items:flex-end;gap:4px;height:90px;padding:6px 0}
.cb-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px}
.cb{width:100%;border-radius:3px 3px 0 0;min-height:3px;transition:height .8s ease;position:relative;cursor:pointer}
.cb:hover{filter:brightness(1.3)}
.cb-tip{position:absolute;bottom:calc(100%+3px);left:50%;transform:translateX(-50%);font-family:'Space Mono',monospace;font-size:.5rem;background:var(--surface);border:1px solid var(--border);padding:1px 4px;border-radius:3px;white-space:nowrap;opacity:0;transition:opacity .15s;pointer-events:none;color:var(--txt2)}
.cb:hover .cb-tip{opacity:1}
.cb-lbl{font-family:'Space Mono',monospace;font-size:.5rem;color:var(--txt3);text-align:center}

/* TABLE */
.tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.tbl th{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.07em;text-transform:uppercase;color:var(--txt3);padding:7px 10px;border-bottom:1px solid var(--border);text-align:left}
.tbl td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.04);color:var(--txt2);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tbody tr:hover td{background:var(--card-hov)}
.td-p{color:var(--txt);font-weight:600}

/* CHIPS */
.chip{display:inline-flex;align-items:center;gap:3px;font-size:.58rem;font-family:'Space Mono',monospace;padding:2px 7px;border-radius:100px;font-weight:700;text-transform:uppercase}
.chip-ok{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.chip-muted{background:rgba(255,255,255,.05);color:var(--txt3);border:1px solid var(--border)}
.chip-warn{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}

/* AVIS */
.avis-item{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.avis-item:last-child{border-bottom:none}
.avis-dot{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.8rem;background:var(--card-hov);flex-shrink:0}
.avis-text{font-size:.76rem;color:var(--txt2);line-height:1.5}
.avis-stars{color:var(--amber);font-size:.65rem;letter-spacing:-1px}
.avis-time{font-size:.6rem;font-family:'Space Mono',monospace;color:var(--txt3);margin-top:2px}

/* PROG */
.prog-wrap{background:rgba(255,255,255,.06);border-radius:100px;height:4px;overflow:hidden;flex:1}
.prog{height:100%;border-radius:100px;transition:width .9s ease}

/* BTN */
.btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:var(--r);font-size:.72rem;font-weight:600;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;transition:all .18s;text-decoration:none}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--txt2)}
.btn-ghost:hover{color:var(--txt);border-color:rgba(255,255,255,.14)}
.btn-primary{background:linear-gradient(135deg,var(--amber),var(--orange));color:#000;font-weight:700}
.btn-primary:hover{opacity:.88}

/* EMPTY */
.empty{text-align:center;padding:2rem;color:var(--txt3);font-size:.8rem}
</style>
</head>
<body>
<div class="page">

  <!-- Nav -->
  <div class="nav">
    <a href="../dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
    <a href="../books/my_books.php"><i class="bi bi-book-half"></i> Mes livres</a>
    <a href="../admin/reviews.php"><i class="bi bi-chat-dots"></i> Avis</a>
    <a href="../admin/notifications.php"><i class="bi bi-bell"></i> Notifications</a>
    <a href="journalist.php" class="active"><i class="bi bi-bar-chart"></i> Statistiques</a>
    
  </div>

  <!-- Header -->
  <div class="page-hdr">
    <div>
      <div class="page-title">📊 Mes statistiques</div>
      <div class="page-sub">Performances de vos publications · Données en temps réel</div>
    </div>
    <div class="user-chip">
      <div class="user-av"><?= strtoupper(substr($targetUser['nom'],0,1)) ?></div>
      <div>
        <div style="font-size:.8rem;font-weight:600"><?= htmlspecialchars($targetUser['nom'], ENT_QUOTES, 'UTF-8') ?></div>
        <div style="font-size:.62rem;color:var(--amber);font-family:'Space Mono',monospace">Journaliste</div>
      </div>
    </div>
  </div>

  <!-- Stats principales -->
  <div class="stats-g">
    <div class="stat-c">
      <div class="stat-icon" style="background:rgba(245,158,11,.1)">📝</div>
      <div class="stat-v"><?= (int)($stats['total'] ?? 0) ?></div>
      <div class="stat-l">Livres publiés</div>
      <div class="stat-sub"><?= (int)($stats['publies'] ?? 0) ?> disponibles · <?= (int)($stats['archives'] ?? 0) ?> archivés</div>
    </div>
    <div class="stat-c">
      <div class="stat-icon" style="background:rgba(0,255,170,.08)">🛍️</div>
      <div class="stat-v"><?= (int)($stats['ventes_total'] ?? 0) ?></div>
      <div class="stat-l">Ventes générées</div>
      <div class="stat-sub"><?= (int)($revData['nb'] ?? 0) ?> achats confirmés</div>
    </div>
    <div class="stat-c">
      <div class="stat-icon" style="background:rgba(0,212,255,.1)">💰</div>
      <div class="stat-v" style="font-size:1rem"><?= fmtFCFA((float)($revData['total'] ?? 0)) ?></div>
      <div class="stat-l">Revenus totaux</div>
      <div class="stat-sub">Transactions confirmées</div>
    </div>
    <div class="stat-c">
      <div class="stat-icon" style="background:rgba(124,58,237,.1)">⭐</div>
      <div class="stat-v"><?= number_format((float)($stats['note_moy'] ?? 0), 1) ?></div>
      <div class="stat-l">Note moyenne</div>
      <div class="stat-sub">Sur 5 étoiles</div>
    </div>
    <div class="stat-c">
      <div class="stat-icon" style="background:rgba(244,63,94,.08)">💬</div>
      <div class="stat-v"><?= count($avisRecents) ?></div>
      <div class="stat-l">Avis récents</div>
      <div class="stat-sub">Publiés par les lecteurs</div>
    </div>
  </div>

  <!-- Grid 2 colonnes -->
  <div class="dash-grid">

    <!-- Graphique mensuel -->
    <div class="card">
      <div class="card-hdr">
        <div class="card-title"><i class="bi bi-graph-up-arrow" style="color:var(--neon)"></i> Ventes mensuelles</div>
        <span style="font-family:'Space Mono',monospace;font-size:.6rem;color:var(--txt3)">12 derniers mois</span>
      </div>
      <div class="card-body">
        <div class="chart-bars">
          <?php foreach ($moisVentes as $idx => $v):
            $h = max(3, round(($v / $maxVentes) * 80));
            $color = $v > 0 ? 'linear-gradient(to top,var(--amber),rgba(245,158,11,.3))' : 'rgba(255,255,255,.05)';
          ?>
          <div class="cb-wrap">
            <div class="cb" style="height:<?= $h ?>px;background:<?= $color ?>">
              <span class="cb-tip"><?= $v ?> vente<?= $v!==1?'s':'' ?> · <?= fmtFCFA($moisMontants[$idx] ?? 0) ?></span>
            </div>
            <div class="cb-lbl"><?= $moisLabels[$idx] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:.8rem;font-size:.68rem;color:var(--txt3)">
          <span>Total : <strong style="color:var(--amber)"><?= array_sum($moisVentes) ?> ventes</strong></span>
          <span>Revenus : <strong style="color:var(--neon)"><?= fmtFCFA(array_sum($moisMontants)) ?></strong></span>
        </div>
      </div>
    </div>

    <!-- Derniers avis -->
    <div class="card">
      <div class="card-hdr">
        <div class="card-title"><i class="bi bi-chat-quote-fill" style="color:var(--amber)"></i> Derniers avis</div>
        <a href="../admin/reviews.php" class="btn btn-ghost" style="font-size:.65rem">Voir tous</a>
      </div>
      <div class="card-body">
        <?php if (empty($avisRecents)): ?>
          <div class="empty"><i class="bi bi-chat-square-text" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>Aucun avis publié pour le moment</div>
        <?php else: foreach ($avisRecents as $a): ?>
        <div class="avis-item">
          <div class="avis-dot">💬</div>
          <div style="flex:1">
            <div class="avis-text">
              <strong style="color:var(--txt)"><?= htmlspecialchars($a['lecteur'], ENT_QUOTES, 'UTF-8') ?></strong>
              sur <em><?= htmlspecialchars(mb_substr($a['livre_titre'],0,30), ENT_QUOTES, 'UTF-8') ?>...</em>
              <?php if (!empty($a['commentaire'])): ?>
                — <?= htmlspecialchars(mb_substr($a['commentaire'],0,70), ENT_QUOTES, 'UTF-8') ?>...
              <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;margin-top:3px">
              <span class="avis-stars"><?= str_repeat('★',min(5,(int)$a['note'])) ?><?= str_repeat('☆',max(0,5-(int)$a['note'])) ?></span>
              <span class="avis-time"><?= timeAgo($a['created_at']) ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Tableau détaillé des livres -->
  <div class="card">
    <div class="card-hdr">
      <div class="card-title"><i class="bi bi-table" style="color:var(--cyan)"></i> Performances par livre</div>
      <a href="../books/create.php" class="btn btn-primary"><i class="bi bi-plus"></i> Nouveau livre</a>
    </div>
    <?php if (empty($mesLivres)): ?>
      <div class="empty" style="padding:2rem"><i class="bi bi-book" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>Aucun livre publié</div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Titre</th>
            <th>Catégorie</th>
            <th>Prix</th>
            <th>Note</th>
            <th>Ventes</th>
            <th>Revenus</th>
            <th>Avis</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mesLivres as $lb): ?>
          <tr>
            <td>
              <div class="td-p" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($lb['titre'], ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div style="font-size:.6rem;color:var(--txt3);font-family:'Space Mono',monospace">
                <?= date('d/m/Y', strtotime($lb['created_at'])) ?>
              </div>
            </td>
            <td style="font-size:.7rem"><?= htmlspecialchars($lb['categorie'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
            <td style="font-family:'Space Mono',monospace;font-size:.68rem;color:var(--amber)">
              <?= (float)$lb['prix'] > 0 ? number_format((float)$lb['prix'],0,',',' ').' F' : 'Gratuit' ?>
            </td>
            <td>
              <div style="color:var(--amber);font-size:.65rem"><?= number_format((float)($lb['note_moyenne']??0),1) ?>/5</div>
              <div style="display:flex;align-items:center;gap:4px;margin-top:2px">
                <div class="prog-wrap"><div class="prog" style="width:<?= min(100,(float)($lb['note_moyenne']??0)/5*100) ?>%;background:linear-gradient(90deg,var(--amber),var(--orange))"></div></div>
              </div>
            </td>
            <td class="td-p"><?= (int)($lb['nb_ventes'] ?? 0) ?></td>
            <td style="font-family:'Space Mono',monospace;font-size:.68rem;color:var(--neon)">
              <?= fmtFCFA((float)($lb['revenus_livre'] ?? 0)) ?>
            </td>
            <td class="td-p"><?= (int)($lb['nb_avis'] ?? 0) ?></td>
            <td>
              <?php $s=$lb['statut']??''; ?>
              <span class="chip <?= $s==='disponible'?'chip-ok':($s==='archive'?'chip-muted':'chip-warn') ?>">
                <?= htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8') ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:3px">
                <a href="../books/edit.php?id=<?= (int)$lb['id'] ?>" class="btn btn-ghost" style="font-size:.65rem" title="Modifier"><i class="bi bi-pencil"></i></a>
                <a href="../books/view.php?id=<?= (int)$lb['id'] ?>" class="btn btn-ghost" style="font-size:.65rem" title="Voir"><i class="bi bi-eye"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:rgba(255,255,255,.02)">
            <td colspan="4" style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--txt3);padding:9px 10px">
              <?= count($mesLivres) ?> livre<?= count($mesLivres)!==1?'s':'' ?> · Note moyenne : <?= number_format((float)($stats['note_moy']??0),1) ?>/5
            </td>
            <td class="td-p" style="font-family:'Space Mono',monospace;color:var(--amber)"><?= (int)($stats['ventes_total']??0) ?></td>
            <td style="font-family:'Space Mono',monospace;font-size:.7rem;color:var(--neon)"><?= fmtFCFA((float)($revData['total']??0)) ?></td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>