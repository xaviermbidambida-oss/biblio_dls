<?php
/**
 * stats/exports/csv_export.php
 * Export CSV complet des ventes + statistiques
 * Accès admin uniquement — utilisé via ?action=export_csv
 *
 * CORRECTION : utilisation de __DIR__ pour les includes,
 *              guard anti-double-inclusion,
 *              encodage UTF-8 BOM propre.
 */

// ── Guard : ce fichier ne doit jamais être appelé directement ──
if (!defined('BIBLIO_APP')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

// ── Sécurité : admin uniquement ────────────────────────────────
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    exit('Accès refusé.');
}

/**
 * Lance l'export CSV et termine le script.
 * Appelée depuis stats/index.php quand ?action=export_csv
 */
function exportCSV(): void
{
    global $pdo;

    // ── En-têtes HTTP ───────────────────────────────────────────
    $filename = 'digital-library-stats-' . date('Y-m-d_His') . '.csv';

    // Supprimer tout output déjà bufferisé pour éviter la corruption du fichier
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // BOM UTF-8 — indispensable pour Excel Windows
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // ── Section 1 : En-tête du rapport ─────────────────────────
    fputcsv($out, ['=== DIGITAL LIBRARY — RAPPORT STATISTIQUES ==='], ';');
    fputcsv($out, ['Généré le', date('d/m/Y H:i:s')], ';');
    fputcsv($out, [], ';');

    // ── Section 2 : Indicateurs globaux ────────────────────────
    fputcsv($out, ['INDICATEURS GLOBAUX'], ';');
    fputcsv($out, ['Indicateur', 'Valeur'], ';');

    $stats = [
        ['Total utilisateurs',     "SELECT COUNT(*) FROM users"],
        ['Admins',                 "SELECT COUNT(*) FROM users WHERE role='admin'"],
        ['Journalistes',           "SELECT COUNT(*) FROM users WHERE role='journaliste'"],
        ['Lecteurs',               "SELECT COUNT(*) FROM users WHERE role='lecteur'"],
        ['Total livres',           "SELECT COUNT(*) FROM livres"],
        ['Livres gratuits',        "SELECT COUNT(*) FROM livres WHERE prix = 0"],
        ['Livres payants',         "SELECT COUNT(*) FROM livres WHERE prix > 0"],
        ['Catégories',             "SELECT COUNT(*) FROM categories"],
        ['Ventes confirmées',      "SELECT COUNT(*) FROM achats WHERE statut = 'confirme'"],
        ['Revenus totaux (FCFA)',  "SELECT COALESCE(SUM(montant), 0) FROM achats WHERE statut = 'confirme'"],
        ["Revenus aujourd'hui",    "SELECT COALESCE(SUM(montant), 0) FROM achats WHERE statut = 'confirme' AND DATE(created_at) = CURDATE()"],
        ['Revenus ce mois',        "SELECT COALESCE(SUM(montant), 0) FROM achats WHERE statut = 'confirme' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"],
        ['Revenus cette année',    "SELECT COALESCE(SUM(montant), 0) FROM achats WHERE statut = 'confirme' AND YEAR(created_at) = YEAR(NOW())"],
        ['Bonus attribués',        "SELECT COALESCE(SUM(bonus_total), 0) FROM user_bonus"],
        ['Téléchargements',        "SELECT COALESCE(SUM(count), 0) FROM user_downloads"],
        ['Ruptures de stock',      "SELECT COUNT(*) FROM livres WHERE statut = 'rupture' OR stock = 0"],
    ];

    foreach ($stats as [$label, $sql]) {
        try {
            $val = $pdo->query($sql)->fetchColumn();
        } catch (PDOException $e) {
            $val = 'N/A';
        }
        fputcsv($out, [$label, $val], ';');
    }

    fputcsv($out, [], ';');

    // ── Section 3 : Top livres ──────────────────────────────────
    fputcsv($out, ['TOP LIVRES PAR VENTES'], ';');
    fputcsv($out, ['Rang', 'Titre', 'Auteur', 'Catégorie', 'Prix', 'Ventes', 'Revenus FCFA', 'Note'], ';');

    try {
        $rows = $pdo->query("
            SELECT
                l.titre,
                l.auteur,
                c.nom        AS cat,
                l.prix,
                COUNT(a.id)                  AS ventes,
                COALESCE(SUM(a.montant), 0)  AS revenue,
                l.note_moyenne
            FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            LEFT JOIN achats a     ON a.livre_id = l.id AND a.statut = 'confirme'
            GROUP BY l.id
            ORDER BY ventes DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $i => $r) {
            fputcsv($out, [
                $i + 1,
                $r['titre'],
                $r['auteur'],
                $r['cat'] ?? 'N/A',
                $r['prix'],
                $r['ventes'],
                $r['revenue'],
                $r['note_moyenne'],
            ], ';');
        }
    } catch (PDOException $e) {
        fputcsv($out, ['Erreur', $e->getMessage()], ';');
    }

    fputcsv($out, [], ';');

    // ── Section 4 : Ventes récentes ────────────────────────────
    fputcsv($out, ['VENTES RÉCENTES (100 dernières)'], ';');
    fputcsv($out, ['ID', 'Référence', 'Acheteur', 'Email', 'Livre', 'Montant FCFA', 'Méthode', 'Date'], ';');

    try {
        $rows = $pdo->query("
            SELECT
                a.id,
                a.reference,
                CONCAT(u.prenom, ' ', u.nom) AS acheteur,
                u.email,
                l.titre,
                a.montant,
                a.methode,
                DATE_FORMAT(a.created_at, '%d/%m/%Y %H:%i') AS date_achat
            FROM achats a
            JOIN users  u ON u.id = a.user_id
            JOIN livres l ON l.id = a.livre_id
            WHERE a.statut = 'confirme'
            ORDER BY a.created_at DESC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['reference'],
                $r['acheteur'],
                $r['email'],
                $r['titre'],
                $r['montant'],
                $r['methode'],
                $r['date_achat'],
            ], ';');
        }
    } catch (PDOException $e) {
        fputcsv($out, ['Erreur', $e->getMessage()], ';');
    }

    fputcsv($out, [], ';');

    // ── Section 5 : Revenus mensuels ───────────────────────────
    fputcsv($out, ['REVENUS MENSUELS (12 derniers mois)'], ';');
    fputcsv($out, ['Mois', 'Ventes', 'Revenus FCFA'], ';');

    try {
        $rows = $pdo->query("
            SELECT
                DATE_FORMAT(created_at, '%m/%Y')  AS mois,
                COUNT(*)                           AS ventes,
                COALESCE(SUM(montant), 0)          AS revenue
            FROM achats
            WHERE statut = 'confirme'
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY DATE_FORMAT(created_at, '%Y-%m') DESC
            LIMIT 12
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach (array_reverse($rows) as $r) {
            fputcsv($out, [$r['mois'], $r['ventes'], $r['revenue']], ';');
        }
    } catch (PDOException $e) {
        fputcsv($out, ['Erreur', $e->getMessage()], ';');
    }

    fputcsv($out, [], ';');
    fputcsv($out, ['--- Fin du rapport ---'], ';');

    fclose($out);
    exit(); // ← OBLIGATOIRE : stoppe tout output PHP après le CSV
}