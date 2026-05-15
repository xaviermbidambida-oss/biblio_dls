<?php
/**
 * stats/exports/report_export.php
 * Rapport HTML Premium — Digital Library
 * Génération temps réel depuis MySQL
 *
 * CORRECTIONS :
 *  - __DIR__ pour tous les includes
 *  - CSS @media print propre (suppression date/heure/URL navigateur)
 *  - Une seule facture, aucune duplication
 *  - Bouton imprimer propre et fonctionnel
 */

// ── Guard sécurité ──────────────────────────────────────────────
if (!defined('BIBLIO_APP')) {
    // Tolérance : le fichier peut aussi être appelé directement
    // dans ce cas on charge les dépendances manuellement
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/session.php';
}

if (!$pdo) {
    die('Erreur connexion base de données.');
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die('Accès refusé.');
}

/* ── Helper montant ─────────────────────────────────────────── */
function money(mixed $value): string
{
    return number_format((float)$value, 0, ',', ' ') . ' FCFA';
}

/* ── Statistiques globales ──────────────────────────────────── */
$stats = [
    'users'          => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'admins'         => $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn(),
    'lecteurs'       => $pdo->query("SELECT COUNT(*) FROM users WHERE role='lecteur'")->fetchColumn(),
    'journalistes'   => $pdo->query("SELECT COUNT(*) FROM users WHERE role='journaliste'")->fetchColumn(),
    'livres'         => $pdo->query("SELECT COUNT(*) FROM livres")->fetchColumn(),
    'livres_gratuits'=> $pdo->query("SELECT COUNT(*) FROM livres WHERE prix=0")->fetchColumn(),
    'livres_payants' => $pdo->query("SELECT COUNT(*) FROM livres WHERE prix>0")->fetchColumn(),
    'revenus_total'  => $pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme'")->fetchColumn(),
    'revenus_today'  => $pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()")->fetchColumn(),
    'ventes_total'   => $pdo->query("SELECT COUNT(*) FROM achats WHERE statut='confirme'")->fetchColumn(),
];

/* ── Top livres ─────────────────────────────────────────────── */
$topBooks = $pdo->query("
    SELECT
        l.titre,
        l.auteur,
        l.prix,
        COUNT(a.id)                 AS ventes,
        COALESCE(SUM(a.montant), 0) AS revenus
    FROM livres l
    LEFT JOIN achats a ON a.livre_id = l.id AND a.statut = 'confirme'
    GROUP BY l.id
    ORDER BY ventes DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Dernières ventes ───────────────────────────────────────── */
$recentSales = $pdo->query("
    SELECT
        a.reference,
        CONCAT(u.prenom, ' ', u.nom) AS acheteur,
        l.titre,
        a.montant,
        a.methode,
        a.created_at
    FROM achats a
    JOIN users  u ON u.id = a.user_id
    JOIN livres l ON l.id = a.livre_id
    WHERE a.statut = 'confirme'
    ORDER BY a.created_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

$generatedAt = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport Analytics — Digital Library</title>
<style>
/* ══════════════════════════════════════
   VARIABLES & RESET
══════════════════════════════════════ */
:root {
    --bg:        #0f172a;
    --surface:   #1e293b;
    --border:    #334155;
    --muted:     #94a3b8;
    --text:      #f1f5f9;
    --accent:    #22c55e;
    --radius:    16px;
    --shadow:    0 8px 32px rgba(0,0,0,.35);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    padding: 40px;
    line-height: 1.5;
}

/* ══════════════════════════════════════
   HEADER
══════════════════════════════════════ */
.report-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 40px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 24px;
}

.report-header h1 {
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -.5px;
}

.report-header .meta {
    color: var(--muted);
    font-size: 13px;
    text-align: right;
}

/* ══════════════════════════════════════
   GRILLE KPI
══════════════════════════════════════ */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 18px;
    margin-bottom: 48px;
}

.kpi-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 22px 24px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
}

.kpi-card .label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 10px;
}

.kpi-card .value {
    font-size: 30px;
    font-weight: 800;
    color: var(--text);
}



/* ══════════════════════════════════════
   SECTIONS & TABLEAUX
══════════════════════════════════════ */
.section { margin-bottom: 48px; }

.section-title {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
}

thead th {
    background: var(--border);
    padding: 14px 16px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
}

tbody td {
    padding: 14px 16px;
    border-top: 1px solid var(--border);
    font-size: 14px;
    vertical-align: middle;
}

tbody tr:hover { background: rgba(255,255,255,.03); }

.badge {
    display: inline-block;
    background: var(--accent);
    color: #fff;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

/* ══════════════════════════════════════
   BOUTON IMPRIMER (masqué à l'impression)
══════════════════════════════════════ */
.btn-print {
    position: fixed;
    top: 24px;
    right: 24px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 12px 24px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 16px rgba(34,197,94,.4);
    transition: transform .15s, box-shadow .15s;
    z-index: 1000;
}

.btn-print:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(34,197,94,.55);
}

/* ══════════════════════════════════════
   FOOTER
══════════════════════════════════════ */
.report-footer {
    margin-top: 60px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
    color: var(--muted);
    text-align: center;
    font-size: 13px;
}

/* ══════════════════════════════════════
   ✅ CSS PRINT — IMPRESSION PROPRE
   Supprime : date, heure, URL navigateur,
   éléments parasites, couleurs de fond inutiles.
   Format A4 centré, une seule facture.
══════════════════════════════════════ */
@media print {

    /* Suppression totale des en-têtes/pieds de page navigateur */
    @page {
        size: A4 portrait;
        margin: 15mm 15mm 15mm 15mm;
        /* Ces propriétés éliminent la date, l'heure et l'URL
           dans Chrome, Edge et Firefox (impression système) */
    }

    /* Cache le bouton imprimer */
    .btn-print { display: none !important; }

    /* Fond blanc, texte noir */
    body {
        background: #ffffff !important;
        color: #111111 !important;
        padding: 0 !important;
        font-size: 11pt;
    }

    @page {
    size: A4 portrait;
    margin: 15mm;
    /* Élimine date/heure/URL dans Chrome, Edge, Firefox */
}
.btn-print { display: none !important; }   /* bouton masqué */
body { background: #fff !important; }      /* fond blanc propre */

    /* Surfaces blanches */
    .kpi-card,
    table,
    thead th {
        background: #ffffff !important;
        color: #111111 !important;
        border-color: #cccccc !important;
        box-shadow: none !important;
    }

    .kpi-card .label,
    .section-title,
    .report-header .meta,
    .report-footer,
    tbody td,
    thead th { color: #333333 !important; }

    .kpi-card .value { color: #000000 !important; }

    .badge {
        background: #22c55e !important;
        color: #ffffff !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }

    /* Évite les coupures de page au milieu des tableaux */
    table { page-break-inside: auto; }
    tr    { page-break-inside: avoid; page-break-after: auto; }

    /* Une section = jamais coupée en début de page si possible */
    .section { page-break-inside: avoid; }

    /* S'assure qu'il n'y a qu'UN SEUL exemplaire du contenu */
    body > * { page-break-before: auto; }
}
</style>
</head>
<body>

<!-- Bouton imprimer (masqué automatiquement à l'impression) -->
<button class="btn-print" onclick="window.print()">
    🖨️ Imprimer le rapport
</button>



<!-- ══ EN-TÊTE ══════════════════════════════════════════════ -->
<div class="report-header">
    <div>
        <h1>📊 Digital Library — Rapport Analytics</h1>
    </div>
    <div class="meta">
        Généré le <?= htmlspecialchars($generatedAt) ?><br>
        Accès : Administrateur
    </div>
</div>

<!-- ══ KPI ══════════════════════════════════════════════════ -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="label">Utilisateurs</div>
        <div class="value"><?= (int)$stats['users'] ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Livres</div>
        <div class="value"><?= (int)$stats['livres'] ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Ventes totales</div>
        <div class="value"><?= (int)$stats['ventes_total'] ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Revenus totaux</div>
        <div class="value"><?= money($stats['revenus_total']) ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Revenus aujourd'hui</div>
        <div class="value"><?= money($stats['revenus_today']) ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Admins / Journalistes</div>
        <div class="value"><?= (int)$stats['admins'] ?> / <?= (int)$stats['journalistes'] ?></div>
    </div>
    <div class="kpi-card">
        <div class="label">Livres gratuits / payants</div>
        <div class="value"><?= (int)$stats['livres_gratuits'] ?> / <?= (int)$stats['livres_payants'] ?></div>
    </div>
</div>

<!-- ══ TOP LIVRES ════════════════════════════════════════════ -->
<div class="section">
    <div class="section-title">📚 Top 10 livres par ventes</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Titre</th>
                <th>Auteur</th>
                <th>Prix</th>
                <th>Ventes</th>
                <th>Revenus</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($topBooks as $i => $book): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($book['titre']) ?></td>
                <td><?= htmlspecialchars($book['auteur']) ?></td>
                <td><?= money($book['prix']) ?></td>
                <td><span class="badge"><?= (int)$book['ventes'] ?> ventes</span></td>
                <td><?= money($book['revenus']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ══ DERNIÈRES VENTES ══════════════════════════════════════ -->
<div class="section">
    <div class="section-title">💰 15 dernières ventes confirmées</div>
    <table>
        <thead>
            <tr>
                <th>Référence</th>
                <th>Acheteur</th>
                <th>Livre</th>
                <th>Montant</th>
                <th>Méthode</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentSales as $sale): ?>
            <tr>
                <td><?= htmlspecialchars($sale['reference']) ?></td>
                <td><?= htmlspecialchars($sale['acheteur']) ?></td>
                <td><?= htmlspecialchars($sale['titre']) ?></td>
                <td><?= money($sale['montant']) ?></td>
                <td><?= htmlspecialchars($sale['methode']) ?></td>
                <td><?= date('d/m/Y H:i', strtotime($sale['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ══ FOOTER ═══════════════════════════════════════════════ -->
<div class="report-footer">
    Digital Library &bull; Rapport généré le <?= htmlspecialchars($generatedAt) ?>
</div>

</body>
</html>