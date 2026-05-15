<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — Mes Achats v1.0                   ║
 * ║  users/purchases.php                                         ║
 * ║  Espace personnel achats · Amazon-style · Premium UI         ║
 * ║  ✅ PDO sécurisé · AJAX temps réel · CSRF protégé            ║
 * ║  ✅ Factures · Filtres · Stats · Favoris · Téléchargements   ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Session ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Config BD ────────────────────────────────────────────────
foreach ([
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/includes/config.php',
] as $_cfgPath) {
    if (file_exists($_cfgPath) && !defined('DB_HOST_LOADED')) {
        require_once $_cfgPath;
        define('DB_HOST_LOADED', true);
        break;
    }
}

if (!isset($pdo) || $pdo === null) {
    $_h = defined('DB_HOST') ? DB_HOST : 'localhost';
    $_n = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $_u = defined('DB_USER') ? DB_USER : 'root';
    $_p = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$_h};dbname={$_n};charset=utf8mb4",
            $_u, $_p,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES   => false]
        );
    } catch (PDOException $e) {
        error_log('[DLS-Purchases] PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth guard ───────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=users/purchases.php'); exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';
$username = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$firstName= htmlspecialchars(explode(' ', trim($username))[0] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$avatar   = strtoupper(substr($username, 0, 1)) ?: 'U';

// ── Helpers ───────────────────────────────────────────────────
function fmtFCFA(float $n): string { return number_format($n, 0, ',', ' ') . ' FCFA'; }
function safeE(string $s): string  { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function timeAgo(string $d): string {
    if (!$d) return '—';
    $diff = time() - strtotime($d);
    if ($diff < 60)     return 'à l\'instant';
    if ($diff < 3600)   return (int)($diff/60) . ' min';
    if ($diff < 86400)  return (int)($diff/3600) . 'h';
    if ($diff < 604800) return (int)($diff/86400) . 'j';
    return date('d/m/Y', strtotime($d));
}
function csrf(): string {
    if (empty($_SESSION['csrf_purch'])) $_SESSION['csrf_purch'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_purch'];
}
function genRef(): string { return 'FAC-' . strtoupper(base_convert(time(), 10, 36)) . '-' . strtoupper(substr(uniqid(), -4)); }

// ── Créer tables manquantes ───────────────────────────────────
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_favorite (user_id, livre_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            achat_id INT UNSIGNED NOT NULL,
            facture_reference VARCHAR(100) UNIQUE,
            montant DECIMAL(10,2) DEFAULT 0,
            statut ENUM('generee','envoyee') DEFAULT 'generee',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (achat_id) REFERENCES achats(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_activity (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            achat_id INT UNSIGNED NOT NULL,
            action VARCHAR(80) NOT NULL,
            detail TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_activity (user_id, created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (achat_id) REFERENCES achats(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS avis (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            note TINYINT UNSIGNED DEFAULT 0,
            commentaire TEXT,
            statut ENUM('publie','en_attente','refuse') DEFAULT 'publie',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_livre (user_id, livre_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Colonnes optionnelles pour notifications
        try { $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS titre VARCHAR(255) NULL"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS icon VARCHAR(10) DEFAULT '🔔'"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS bg VARCHAR(50) DEFAULT 'rgba(0,212,255,.08)'"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS lu TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}

    } catch (Throwable $e) {
        error_log('[DLS-Purchases] Schema: ' . $e->getMessage());
    }
}

// ── AJAX HANDLER ──────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $csrf   = $_POST['csrf'] ?? '';

    if (!hash_equals($_SESSION['csrf_purch'] ?? '', $csrf)) {
        echo json_encode(['success' => false, 'error' => 'Token invalide']); exit;
    }
    if (!$pdo) { echo json_encode(['success'=>false,'error'=>'BD inaccessible']); exit; }

    try {
        switch ($action) {

            case 'get_invoice':
                $achatId = (int)($_POST['achat_id'] ?? 0);
                // Vérifier appartenance
                $own = $pdo->prepare("SELECT id, montant, reference, created_at FROM achats WHERE id=? AND user_id=? AND statut='confirme'");
                $own->execute([$achatId, $userId]);
                $achat = $own->fetch();
                if (!$achat) throw new Exception('Achat introuvable');

                // Chercher ou créer la facture
                $inv = $pdo->prepare("SELECT * FROM purchase_invoices WHERE achat_id=?");
                $inv->execute([$achatId]);
                $facture = $inv->fetch();

                if (!$facture) {
                    $ref = genRef();
                    $pdo->prepare("INSERT INTO purchase_invoices (achat_id, facture_reference, montant, statut) VALUES (?,?,?,'generee')")
                        ->execute([$achatId, $ref, $achat['montant']]);
                    $facture = ['facture_reference' => $ref, 'montant' => $achat['montant'], 'statut' => 'generee', 'created_at' => date('Y-m-d H:i:s')];
                }

                // Détails complets du livre
                $lSt = $pdo->prepare("SELECT l.titre, l.auteur, c.nom AS cat FROM achats a JOIN livres l ON l.id=a.livre_id LEFT JOIN categories c ON c.id=l.categorie_id WHERE a.id=?");
                $lSt->execute([$achatId]);
                $livre = $lSt->fetch();

                echo json_encode(['success'=>true,'invoice'=> array_merge($facture, $achat, $livre ?: [])]);
                break;

            case 'toggle_favorite':
                $livreId = (int)($_POST['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('ID manquant');
                $ex = $pdo->prepare("SELECT id FROM favorites WHERE user_id=? AND livre_id=?");
                $ex->execute([$userId, $livreId]);
                if ($ex->fetch()) {
                    $pdo->prepare("DELETE FROM favorites WHERE user_id=? AND livre_id=?")->execute([$userId, $livreId]);
                    echo json_encode(['success'=>true,'action'=>'removed','msg'=>'Retiré des favoris']);
                } else {
                    $pdo->prepare("INSERT IGNORE INTO favorites (user_id, livre_id) VALUES (?,?)")->execute([$userId, $livreId]);
                    echo json_encode(['success'=>true,'action'=>'added','msg'=>'Ajouté aux favoris ⭐']);
                }
                break;

            case 'save_review':
                $livreId = (int)($_POST['livre_id'] ?? 0);
                $note    = min(5, max(1, (int)($_POST['note'] ?? 0)));
                $comment = trim(substr($_POST['commentaire'] ?? '', 0, 800));
                if (!$livreId || !$note) throw new Exception('Données incomplètes');
                $pdo->prepare("INSERT INTO avis (user_id, livre_id, note, commentaire, statut) VALUES (?,?,?,?,'publie') ON DUPLICATE KEY UPDATE note=VALUES(note), commentaire=VALUES(commentaire)")
                    ->execute([$userId, $livreId, $note, $comment]);
                $pdo->prepare("UPDATE livres SET note_moyenne=(SELECT AVG(note) FROM avis WHERE livre_id=? AND statut='publie'), nb_etoiles=(SELECT COUNT(*) FROM avis WHERE livre_id=? AND statut='publie') WHERE id=?")
                    ->execute([$livreId, $livreId, $livreId]);
                echo json_encode(['success'=>true,'msg'=>'Avis enregistré ✅']);
                break;

            case 'log_download':
                $livreId = (int)($_POST['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('ID manquant');
                // Vérifier possession
                $own = $pdo->prepare("SELECT COUNT(*) FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme'");
                $own->execute([$userId, $livreId]);
                if (!$own->fetchColumn()) throw new Exception('Non autorisé');
                $pdo->prepare("INSERT INTO user_downloads (user_id, livre_id, count) VALUES (?,?,1) ON DUPLICATE KEY UPDATE count=count+1, last_dl_at=NOW()")
                    ->execute([$userId, $livreId]);
                echo json_encode(['success'=>true]);
                break;

            case 'mark_notif_read':
                $nId = (int)($_POST['notif_id'] ?? 0);
                if ($nId) $pdo->prepare("UPDATE notifications SET lu=1 WHERE id=? AND (user_id=? OR user_id IS NULL)")->execute([$nId, $userId]);
                echo json_encode(['success'=>true]);
                break;

            case 'get_stats_refresh':
                $st = $pdo->prepare("
                    SELECT COUNT(*) AS total, COALESCE(SUM(montant),0) AS total_depense,
                           COUNT(CASE WHEN MONTH(created_at)=MONTH(NOW()) THEN 1 END) AS ce_mois,
                           COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(NOW()) THEN montant END),0) AS depense_mois
                    FROM achats WHERE user_id=? AND statut='confirme'");
                $st->execute([$userId]);
                echo json_encode(['success'=>true,'stats'=>$st->fetch()]);
                break;

            default:
                echo json_encode(['success'=>false,'error'=>'Action inconnue']);
        }
    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── PARAMÈTRES DE FILTRAGE ────────────────────────────────────
$filterMonth  = (int)($_GET['mois']    ?? 0);
$filterYear   = (int)($_GET['annee']   ?? 0);
$filterMethod = $_GET['methode']       ?? '';
$filterCat    = (int)($_GET['cat']     ?? 0);
$sortBy       = $_GET['tri']           ?? 'recent';
$search       = trim($_GET['q']        ?? '');
$perPage      = 12;
$currentPage  = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($currentPage - 1) * $perPage;

// ── CHARGEMENT DES DONNÉES ────────────────────────────────────
$purchases    = [];
$stats        = [];
$bonus        = [];
$notifications= [];
$notifCount   = 0;
$totalPages   = 1;
$totalCount   = 0;

if ($pdo) {
    try {
        // ── Construire WHERE dynamique ──
        $where  = ["a.user_id = ?", "a.statut = 'confirme'"];
        $params = [$userId];

        if ($filterMonth > 0)  { $where[] = "MONTH(a.created_at) = ?"; $params[] = $filterMonth; }
        if ($filterYear  > 0)  { $where[] = "YEAR(a.created_at) = ?";  $params[] = $filterYear;  }
        if ($filterMethod)     { $where[] = "a.methode = ?";             $params[] = $filterMethod;}
        if ($filterCat   > 0)  { $where[] = "l.categorie_id = ?";       $params[] = $filterCat;  }
        if ($search)           { $where[] = "(l.titre LIKE ? OR l.auteur LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $orderSQL = match($sortBy) {
            'ancien'  => 'ORDER BY a.created_at ASC',
            'prix_h'  => 'ORDER BY a.montant DESC',
            'prix_b'  => 'ORDER BY a.montant ASC',
            'titre'   => 'ORDER BY l.titre ASC',
            default   => 'ORDER BY a.created_at DESC',
        };

        // ── Compter le total ──
        $countSt = $pdo->prepare("SELECT COUNT(*) FROM achats a JOIN livres l ON l.id=a.livre_id $whereSQL");
        $countSt->execute($params);
        $totalCount = (int)$countSt->fetchColumn();
        $totalPages = max(1, ceil($totalCount / $perPage));

        // ── Récupérer les achats ──
        $purchSt = $pdo->prepare("
            SELECT
                a.id AS achat_id, a.montant, a.methode, a.reference, a.created_at AS date_achat,
                l.id AS livre_id, l.titre, l.auteur, l.prix, l.pages, l.access_type,
                l.note_moyenne, l.is_featured, l.is_bestseller, l.fichier_pdf,
                c.nom AS categorie_nom, c.icone AS cat_icone,
                lp.pourcentage, lp.page_actuelle, lp.statut AS lect_statut,
                ud.count AS nb_telechargements,
                f.id AS is_favorite,
                av.note AS ma_note
            FROM achats a
            JOIN livres l ON l.id = a.livre_id
            LEFT JOIN categories c ON c.id = l.categorie_id
            LEFT JOIN lecture_progression lp ON lp.user_id = a.user_id AND lp.livre_id = l.id
            LEFT JOIN user_downloads ud ON ud.user_id = a.user_id AND ud.livre_id = l.id
            LEFT JOIN favorites f ON f.user_id = a.user_id AND f.livre_id = l.id
            LEFT JOIN avis av ON av.user_id = a.user_id AND av.livre_id = l.id
            $whereSQL
            $orderSQL
            LIMIT ? OFFSET ?
        ");
        $purchSt->execute(array_merge($params, [$perPage, $offset]));
        $purchases = $purchSt->fetchAll();

        // ── Stats globales ──
        $statSt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_achats,
                COALESCE(SUM(montant), 0) AS total_depense,
                COUNT(CASE WHEN MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) THEN 1 END) AS achats_mois,
                COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) THEN montant END),0) AS depense_mois,
                MIN(created_at) AS premier_achat,
                MAX(created_at) AS dernier_achat,
                COUNT(CASE WHEN YEAR(created_at)=YEAR(NOW()) THEN 1 END) AS achats_annee,
                COALESCE(SUM(CASE WHEN YEAR(created_at)=YEAR(NOW()) THEN montant END),0) AS depense_annee
            FROM achats WHERE user_id=? AND statut='confirme'");
        $statSt->execute([$userId]);
        $stats = $statSt->fetch() ?: [];

        // Livres terminés
        $termSt = $pdo->prepare("SELECT COUNT(*) FROM lecture_progression lp JOIN achats a ON a.livre_id=lp.livre_id AND a.user_id=lp.user_id WHERE lp.user_id=? AND lp.statut='termine'");
        $termSt->execute([$userId]);
        $stats['livres_termines'] = (int)$termSt->fetchColumn();

        // ── Catégories pour filtres ──
        $catSt = $pdo->prepare("SELECT DISTINCT c.id, c.nom, c.icone FROM achats a JOIN livres l ON l.id=a.livre_id JOIN categories c ON c.id=l.categorie_id WHERE a.user_id=? AND a.statut='confirme' ORDER BY c.nom");
        $catSt->execute([$userId]);
        $categories = $catSt->fetchAll();

        // ── Années disponibles ──
        $yearSt = $pdo->prepare("SELECT DISTINCT YEAR(created_at) AS annee FROM achats WHERE user_id=? AND statut='confirme' ORDER BY annee DESC");
        $yearSt->execute([$userId]);
        $years = array_column($yearSt->fetchAll(), 'annee');

        // ── Méthodes de paiement ──
        $methSt = $pdo->prepare("SELECT DISTINCT methode FROM achats WHERE user_id=? AND statut='confirme'");
        $methSt->execute([$userId]);
        $methods = array_column($methSt->fetchAll(), 'methode');

        // ── Bonus ──
        $bonSt = $pdo->prepare("SELECT * FROM user_bonus WHERE user_id=?");
        $bonSt->execute([$userId]);
        $bonus = $bonSt->fetch() ?: ['bonus_restant'=>0,'achat_count'=>0,'bonus_total'=>0];

        // ── Graphique mensuel (12 mois) ──
        $chartSt = $pdo->prepare("
            SELECT MONTH(created_at) AS mois, YEAR(created_at) AS annee,
                   COUNT(*) AS nb, COALESCE(SUM(montant),0) AS total
            FROM achats WHERE user_id=? AND statut='confirme' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY annee, mois");
        $chartSt->execute([$userId]);
        $rawChart = $chartSt->fetchAll();

        $monthsChart = [];
        $frMois = ['','Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
        for ($i = 11; $i >= 0; $i--) {
            $ts   = strtotime("-$i months");
            $m    = (int)date('n', $ts);
            $y    = (int)date('Y', $ts);
            $nb   = 0; $tot = 0;
            foreach ($rawChart as $r) { if ((int)$r['mois']===$m && (int)$r['annee']===$y) { $nb=(int)$r['nb']; $tot=(float)$r['total']; break; } }
            $monthsChart[] = ['label'=>$frMois[$m], 'nb'=>$nb, 'total'=>$tot];
        }

        // ── Top catégories dépenses ──
        $catDepSt = $pdo->prepare("
            SELECT c.nom, c.icone, COUNT(*) AS nb, SUM(a.montant) AS total
            FROM achats a JOIN livres l ON l.id=a.livre_id LEFT JOIN categories c ON c.id=l.categorie_id
            WHERE a.user_id=? AND a.statut='confirme' GROUP BY c.id ORDER BY total DESC LIMIT 5");
        $catDepSt->execute([$userId]);
        $topCats = $catDepSt->fetchAll();

        // ── Notifications ──
        $notifSt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? OR user_id IS NULL ORDER BY created_at DESC LIMIT 6");
        $notifSt->execute([$userId]);
        $notifications = $notifSt->fetchAll();
        $ncSt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id=? OR user_id IS NULL)");
        $ncSt->execute([$userId]);
        $notifCount = (int)$ncSt->fetchColumn();

    } catch (Throwable $e) {
        error_log('[DLS-Purchases] Data: ' . $e->getMessage());
    }
}

$csrfToken  = csrf();
$bookEmojis = ['📖','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌊','⚡','🔮','🗺️','🏛️','🎯'];
$methodLabels = ['orange_money'=>'Orange Money','mobile_money'=>'MTN MoMo','carte'=>'Carte bancaire'];
$methodIcons  = ['orange_money'=>'🟠','mobile_money'=>'🟡','carte'=>'💳'];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes Achats — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#06090f;--surf:#0c1221;--card:rgba(255,255,255,.03);--card-hov:rgba(255,255,255,.056);
  --border:rgba(255,255,255,.07);--border-act:rgba(0,212,255,.38);
  --cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;--amber:#f59e0b;
  --rose:#f43f5e;--orange:#f97316;--gold:#fbbf24;
  --tp:#eef2ff;--ts:rgba(238,242,255,.56);--tm:rgba(238,242,255,.28);
  --sw:256px;--th:60px;
  --r1:8px;--r2:13px;--r3:18px;--r4:26px;
  --gc:0 0 28px rgba(0,212,255,.18);--sc:0 4px 24px rgba(0,0,0,.35);--slg:0 20px 60px rgba(0,0,0,.52);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tp);overflow-x:hidden;min-height:100vh}
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}

/* ── LAYOUT ── */
.app{display:flex;min-height:100vh}

/* ── SIDEBAR ── */
#sb{position:fixed;top:0;left:0;bottom:0;width:var(--sw);background:var(--surf);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:200;overflow:hidden;transition:transform .3s ease}
.sb-brand{height:var(--th);display:flex;align-items:center;gap:10px;padding:0 18px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-logo{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:var(--gc);flex-shrink:0}
.sb-name{font-family:'Syne',sans-serif;font-weight:800;font-size:.88rem}
.sb-name em{color:var(--cyan);font-style:normal}
.sb-user{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-av{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--neon),var(--cyan));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.9rem;color:#000;flex-shrink:0}
.sb-un{font-family:'Syne',sans-serif;font-weight:700;font-size:.83rem}
.sb-ur{font-size:.6rem;color:var(--neon);font-family:'Space Mono',monospace;margin-top:2px}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.sb-sect{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;color:var(--tm);padding:10px 18px 3px}
.sb-a{display:flex;align-items:center;gap:10px;padding:9px 18px;margin:1px 8px;border-radius:var(--r1);text-decoration:none;color:var(--ts);font-size:.82rem;font-weight:500;transition:all .15s;position:relative;cursor:pointer}
.sb-a:hover{color:var(--tp);background:var(--card-hov)}
.sb-a.on{color:var(--neon);background:rgba(0,255,170,.07);border:1px solid rgba(0,255,170,.1)}
.sb-a.on::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:16px;background:var(--neon);border-radius:0 3px 3px 0}
.sb-badge{margin-left:auto;font-size:.58rem;font-family:'Space Mono',monospace;padding:2px 6px;border-radius:100px;background:var(--neon);color:#000;font-weight:700}
.sb-foot{padding:10px;border-top:1px solid var(--border)}

/* ── MAIN ── */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-height:100vh}

/* ── TOPBAR ── */
#topbar{height:var(--th);background:rgba(6,9,15,.9);backdrop-filter:blur(22px);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:1rem;padding:0 1.6rem;position:sticky;top:0;z-index:100}
.tb-path{font-size:.78rem;color:var(--ts);display:flex;align-items:center;gap:6px}
.tb-curr{font-family:'Syne',sans-serif;font-weight:700;color:var(--tp)}
.tb-sp{flex:1}
.tb-search{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--border);border-radius:var(--r1);padding:6px 12px;width:240px;transition:all .2s}
.tb-search:focus-within{border-color:var(--border-act);box-shadow:var(--gc)}
.tb-search input{background:none;border:none;outline:none;color:var(--tp);font-size:.78rem;font-family:'DM Sans',sans-serif;width:100%}
.tb-search input::placeholder{color:var(--tm)}
.tb-btn{width:34px;height:34px;border-radius:var(--r1);background:var(--card);border:1px solid var(--border);color:var(--ts);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.95rem;transition:all .15s;text-decoration:none;position:relative}
.tb-btn:hover{color:var(--tp);background:var(--card-hov)}
.nb{position:absolute;top:-3px;right:-3px;min-width:15px;height:15px;padding:0 3px;background:var(--rose);border-radius:50%;font-size:.5rem;font-weight:700;font-family:'Space Mono',monospace;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);color:#fff}
.tb-ham{display:none;background:none;border:none;color:var(--tp);font-size:1.3rem;cursor:pointer;width:34px;height:34px;align-items:center;justify-content:center;border-radius:var(--r1)}

/* ── PAGE ── */
.page{padding:1.8rem 2rem 5rem;max-width:1440px;width:100%;margin:0 auto}

/* ── HERO ── */
.hero{background:linear-gradient(135deg,rgba(0,212,255,.06),rgba(124,58,237,.08),rgba(0,255,170,.05));border:1px solid rgba(0,212,255,.1);border-radius:var(--r4);padding:2rem 2.4rem;margin-bottom:2rem;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:1rem;animation:fadeUp .4s ease both}
.hero::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon))}
.hero::after{content:'';position:absolute;right:-60px;top:-60px;width:240px;height:240px;background:radial-gradient(circle,rgba(0,212,255,.07),transparent 65%);pointer-events:none}
.hero-t{font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;margin-bottom:4px}
.hero-s{font-size:.83rem;color:var(--ts);line-height:1.5}
.hero-pills{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
.pill{display:inline-flex;align-items:center;gap:4px;font-family:'Space Mono',monospace;font-size:.6rem;padding:3px 10px;border-radius:100px;text-transform:uppercase;font-weight:700}
.p-neon{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.p-cyan{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.p-amber{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.p-violet{background:rgba(124,58,237,.1);color:#a78bfa;border:1px solid rgba(124,58,237,.2)}
.p-rose{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}

/* ── STATS ── */
.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:1rem;margin-bottom:2rem}
.sc{background:var(--card);border:1px solid var(--border);border-radius:var(--r2);padding:1.3rem;transition:all .22s;position:relative;overflow:hidden;animation:fadeUp .5s ease both}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--a1,#fff),var(--a2,#888));opacity:0;transition:opacity .3s}
.sc:hover{transform:translateY(-4px);border-color:rgba(255,255,255,.1);box-shadow:var(--sc)}
.sc:hover::before{opacity:1}
.sc:nth-child(1){--a1:var(--neon);--a2:var(--cyan);animation-delay:.05s}
.sc:nth-child(2){--a1:var(--cyan);--a2:var(--violet);animation-delay:.1s}
.sc:nth-child(3){--a1:var(--amber);--a2:var(--orange);animation-delay:.15s}
.sc:nth-child(4){--a1:var(--violet);--a2:var(--rose);animation-delay:.2s}
.sc:nth-child(5){--a1:var(--rose);--a2:var(--amber);animation-delay:.25s}
.sc-i{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.9rem}
.sc-v{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--a1,#fff),var(--a2,#aaa));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sc-l{font-size:.72rem;color:var(--ts);margin-top:4px;font-weight:500}
.sc-sub{font-size:.65rem;color:var(--tm);margin-top:4px;font-family:'Space Mono',monospace}

/* ── LAYOUT 2 COL ── */
.two-col{display:grid;grid-template-columns:1fr 320px;gap:1.4rem;margin-bottom:1.5rem}
@media(max-width:1200px){.two-col{grid-template-columns:1fr}}

/* ── FILTERS ── */
.filters{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);padding:1.2rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.8rem;flex-wrap:wrap}
.filter-label{font-family:'Space Mono',monospace;font-size:.6rem;color:var(--tm);text-transform:uppercase;letter-spacing:.08em;white-space:nowrap}
.f-select{background:var(--card);border:1px solid var(--border);border-radius:var(--r1);padding:6px 10px;color:var(--tp);font-size:.75rem;font-family:'DM Sans',sans-serif;outline:none;cursor:pointer;transition:border-color .2s;min-width:120px}
.f-select:focus{border-color:var(--border-act)}
.f-sep{height:20px;width:1px;background:var(--border)}
.filter-count{font-family:'Space Mono',monospace;font-size:.68rem;color:var(--tm);margin-left:auto;white-space:nowrap}

/* ── CARD ── */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);overflow:hidden;animation:fadeUp .5s ease both}
.ch{padding:1.2rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem}
.ct{font-family:'Syne',sans-serif;font-weight:700;font-size:.92rem;display:flex;align-items:center;gap:8px}
.ci{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.88rem}
.cb{padding:1.2rem 1.5rem}
.cf{padding:.9rem 1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--r1);font-family:'Syne',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .18s;text-decoration:none;border:none;white-space:nowrap}
.btn-sm{padding:5px 11px;font-size:.72rem}
.btn-xs{padding:3px 8px;font-size:.66rem}
.btn-primary{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff;box-shadow:0 4px 14px rgba(0,212,255,.18)}
.btn-primary:hover{opacity:.88;transform:translateY(-1px)}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--ts)}
.btn-ghost:hover{color:var(--tp);border-color:rgba(255,255,255,.14);background:var(--card-hov)}
.btn-neon{background:rgba(0,255,170,.1);border:1px solid rgba(0,255,170,.25);color:var(--neon)}
.btn-neon:hover{background:rgba(0,255,170,.18)}
.btn-amber{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:var(--amber)}
.btn-amber:hover{background:rgba(245,158,11,.18)}
.btn-danger{background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.22);color:var(--rose)}

/* ── PURCHASE CARDS ── */
.pur-list{display:flex;flex-direction:column;gap:.9rem}
.pur-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);overflow:hidden;transition:all .22s;animation:fadeUp .5s ease both}
.pur-card:hover{border-color:rgba(0,212,255,.2);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(-2px)}
.pur-head{display:flex;align-items:center;gap:16px;padding:1.2rem 1.5rem;border-bottom:1px solid rgba(255,255,255,.04)}
.pur-emoji{font-size:2.5rem;flex-shrink:0;width:56px;height:56px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(12,18,33,.9),rgba(124,58,237,.2));border-radius:var(--r2);position:relative;overflow:hidden}
.pur-emoji::after{content:'';position:absolute;inset:0;background:linear-gradient(to bottom,transparent,rgba(0,0,0,.3))}
.pur-info{flex:1;min-width:0}
.pur-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
.pur-author{font-size:.75rem;color:var(--ts);margin-bottom:5px}
.pur-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pur-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0}
.pur-amount{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;color:var(--neon)}
.pur-date{font-size:.65rem;font-family:'Space Mono',monospace;color:var(--tm)}
.pur-ref{font-size:.6rem;font-family:'Space Mono',monospace;color:var(--tm)}
.pur-body{padding:1rem 1.5rem;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.pur-prog{flex:1;min-width:200px}
.pur-prog-row{display:flex;align-items:center;gap:8px;margin-bottom:4px}
.prog-bar{flex:1;height:4px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden}
.prog-fill{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--cyan),var(--violet));transition:width 1.2s ease;box-shadow:0 0 8px rgba(0,212,255,.3)}
.prog-fill.green{background:linear-gradient(90deg,var(--neon),#00a882)}
.prog-pct{font-family:'Space Mono',monospace;font-size:.62rem;color:var(--tm);flex-shrink:0;min-width:34px;text-align:right}
.pur-actions{display:flex;gap:5px;flex-shrink:0;flex-wrap:wrap}
.ic-btn{width:30px;height:30px;border-radius:8px;background:var(--card-hov);border:1px solid var(--border);color:var(--ts);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.78rem;transition:all .15s;text-decoration:none}
.ic-btn:hover{color:var(--tp);background:rgba(0,212,255,.08);border-color:rgba(0,212,255,.2)}
.ic-btn.fav{color:var(--amber);background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.2)}
.ic-btn.done{color:var(--neon);background:rgba(0,255,170,.06);border-color:rgba(0,255,170,.15)}
.stars{display:flex;gap:1px}
.star{font-size:.85rem;cursor:pointer;color:rgba(255,255,255,.15);transition:color .15s}
.star.on,.star:hover,.star.preview{color:var(--amber)}

/* ── CHIPS ── */
.chip{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-family:'Space Mono',monospace;padding:2px 8px;border-radius:100px;font-weight:700;text-transform:uppercase}
.chip-neon{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.chip-cyan{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.chip-amber{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.chip-violet{background:rgba(124,58,237,.1);color:#a78bfa;border:1px solid rgba(124,58,237,.2)}
.chip-rose{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.chip-muted{background:rgba(255,255,255,.05);color:var(--tm);border:1px solid var(--border)}

/* ── CHART BARS ── */
.chart-bars{display:flex;align-items:flex-end;gap:4px;height:80px;padding:6px 0}
.cw{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px}
.cb2{width:100%;border-radius:4px 4px 0 0;background:linear-gradient(to top,var(--cyan),rgba(0,212,255,.2));min-height:4px;transition:height 1s ease;position:relative;cursor:pointer}
.cb2:hover{filter:brightness(1.3)}
.cb-tt{position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);font-family:'Space Mono',monospace;font-size:.55rem;color:var(--tp);background:var(--surf);border:1px solid var(--border);padding:2px 6px;border-radius:4px;opacity:0;transition:opacity .18s;pointer-events:none;white-space:nowrap}
.cb2:hover .cb-tt{opacity:1}
.cb-l{font-family:'Space Mono',monospace;font-size:.52rem;color:var(--tm)}

/* ── SIDEBAR RIGHT ── */
.side-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r3);overflow:hidden;margin-bottom:1.2rem;animation:fadeUp .5s ease both}
.side-head{padding:.9rem 1.2rem;border-bottom:1px solid var(--border);font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem;display:flex;align-items:center;gap:7px}
.side-body{padding:1rem 1.2rem}
.li-item{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.li-item:last-child{border-bottom:none}
.li-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0}
.li-info{flex:1;min-width:0}
.li-t{font-size:.78rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.li-s{font-size:.65rem;color:var(--tm);font-family:'Space Mono',monospace}

/* ── BONUS ── */
.bonus-card{background:linear-gradient(135deg,rgba(124,58,237,.1),rgba(0,212,255,.06));border:1px solid rgba(124,58,237,.2);border-radius:var(--r3);padding:1.3rem;position:relative;overflow:hidden;margin-bottom:1.2rem}
.bonus-glow{position:absolute;top:-40px;right:-40px;width:150px;height:150px;background:radial-gradient(circle,rgba(124,58,237,.15),transparent 70%);pointer-events:none}
.bonus-prog-wrap{background:rgba(255,255,255,.06);border-radius:100px;height:6px;overflow:hidden;margin:8px 0}
.bonus-prog{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--violet),var(--cyan));transition:width 1.5s ease;box-shadow:0 0 10px rgba(124,58,237,.4)}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:3rem 2rem}
.empty-icon{font-size:3.5rem;margin-bottom:.8rem;opacity:.6}
.empty-t{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:700;margin-bottom:.4rem}
.empty-s{font-size:.82rem;color:var(--tm);margin-bottom:1.2rem}

/* ── PAGINATION ── */
.pag{display:flex;align-items:center;justify-content:center;gap:5px;margin-top:1.5rem}
.pag-btn{width:34px;height:34px;border-radius:var(--r1);background:var(--card);border:1px solid var(--border);color:var(--ts);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.78rem;text-decoration:none;transition:all .15s;font-family:'Space Mono',monospace}
.pag-btn:hover,.pag-btn.on{color:var(--tp);background:rgba(0,212,255,.1);border-color:rgba(0,212,255,.25)}
.pag-btn.on{color:var(--cyan);font-weight:700}
.pag-btn[disabled]{opacity:.3;cursor:not-allowed;pointer-events:none}

/* ── NOTIF PANEL ── */
#np{position:fixed;top:var(--th);right:1rem;width:310px;background:var(--surf);border:1px solid var(--border);border-radius:var(--r3);box-shadow:var(--slg);z-index:500;transform:translateY(-10px) scale(.97);opacity:0;pointer-events:none;transition:all .22s cubic-bezier(.34,1.56,.64,1);overflow:hidden}
#np.open{transform:translateY(6px) scale(1);opacity:1;pointer-events:all}
.np-h{padding:.9rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem}
.np-list{max-height:320px;overflow-y:auto}
.np-item{display:flex;gap:10px;padding:10px 1.1rem;border-bottom:1px solid rgba(255,255,255,.04);cursor:pointer;transition:background .12s;font-size:.76px}
.np-item:hover{background:var(--card-hov)}
.np-icon{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.np-txt{color:var(--ts);line-height:1.45;font-size:.75rem}
.np-time{font-size:.6rem;font-family:'Space Mono',monospace;color:var(--tm);margin-top:2px}
.np-unread{background:rgba(0,212,255,.03)}

/* ── MODALS ── */
.modal-ov{position:fixed;inset:0;background:rgba(6,9,15,.9);backdrop-filter:blur(14px);z-index:800;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-ov.open{opacity:1;pointer-events:all}
.modal-box{background:var(--surf);border:1px solid var(--border);border-radius:var(--r4);padding:2rem;max-width:540px;width:100%;box-shadow:var(--slg);transform:translateY(20px);transition:transform .3s cubic-bezier(.34,1.56,.64,1);position:relative;overflow:hidden;max-height:90vh;overflow-y:auto}
.modal-ov.open .modal-box{transform:translateY(0)}
.modal-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon))}
.modal-t{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;margin-bottom:1rem}
.modal-close{position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--tm);font-size:1rem;cursor:pointer;width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.modal-close:hover{color:var(--tp);background:var(--card-hov)}
.f-label{font-size:.68rem;font-family:'Space Mono',monospace;color:var(--tm);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px}
.f-input{width:100%;background:var(--card);border:1px solid var(--border);border-radius:var(--r1);padding:9px 13px;color:var(--tp);font-size:.83rem;font-family:'DM Sans',sans-serif;outline:none;margin-bottom:1rem;transition:border-color .2s}
.f-input:focus{border-color:var(--border-act);box-shadow:var(--gc)}
.f-textarea{resize:vertical;min-height:80px}

/* ── INVOICE ── */
.invoice{background:linear-gradient(135deg,rgba(12,18,33,.95),rgba(0,0,0,.8));border:1px solid rgba(0,212,255,.15);border-radius:var(--r3);padding:1.8rem;position:relative;overflow:hidden}
.inv-logo{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;color:var(--cyan);margin-bottom:1rem}
.inv-logo em{color:var(--tp);font-style:normal}
.inv-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.78rem}
.inv-row:last-child{border-bottom:none}
.inv-key{color:var(--ts)}
.inv-val{font-family:'Space Mono',monospace;font-size:.72rem;color:var(--tp);text-align:right}
.inv-total{display:flex;justify-content:space-between;align-items:center;padding:12px 0;margin-top:10px;border-top:1px solid rgba(0,212,255,.2)}
.inv-total-lbl{font-family:'Syne',sans-serif;font-weight:700}
.inv-total-amt{font-family:'Syne',sans-serif;font-weight:800;font-size:1.3rem;color:var(--neon)}
.inv-stamp{position:absolute;top:1.5rem;right:1.5rem;opacity:.08;font-size:4rem;transform:rotate(-20deg);pointer-events:none}

/* ── TOAST ── */
#toast-s{position:fixed;bottom:1.4rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:7px;pointer-events:none}
.toast{display:flex;align-items:center;gap:9px;padding:10px 14px;background:var(--surf);border:1px solid var(--border);border-radius:var(--r2);box-shadow:var(--slg);font-size:.78rem;max-width:300px;pointer-events:all;transform:translateX(120px);opacity:0;transition:all .35s cubic-bezier(.34,1.56,.64,1)}
.toast.show{transform:translateX(0);opacity:1}
.t-title{font-family:'Syne',sans-serif;font-weight:700}
.t-sub{color:var(--tm);font-size:.68rem}

/* ── SIDEBAR OVERLAY ── */
#sb-ov{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:199;opacity:0;pointer-events:none;transition:opacity .3s}
#sb-ov.show{opacity:1;pointer-events:all}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  #sb{transform:translateX(-100%)}
  #sb.open{transform:translateX(0)}
  .main{margin-left:0!important}
  .tb-ham{display:flex}
  .two-col{grid-template-columns:1fr}
  .page{padding:1.2rem 1rem 4rem}
}
@media(max-width:600px){
  .sg{grid-template-columns:1fr 1fr}
  .hero{flex-direction:column;align-items:flex-start}
  .pur-head{flex-wrap:wrap}
  .pur-right{align-items:flex-start}
}
</style>
</head>
<body>

<div class="app">

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside id="sb" role="navigation">
  <div class="sb-brand">
    <div class="sb-logo">📚</div>
    <div class="sb-name">Digital <em>Library</em></div>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= $avatar ?></div>
    <div>

      <div class="sb-un"><?= $username ?></div>
      <div class="sb-ur">🛍️ Mes Achats</div>
    </div>
  </div>
  <nav class="sb-nav">
  <div class="sb-sect">Compte</div>
    <a class="sb-a" href="../dashboard.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
    <a class="sb-a on" href="purchases.php"><i class="bi bi-bag-check"></i> Mes achats
      <?php if ((int)($stats['total_achats']??0) > 0): ?>
        <span class="sb-badge" style="background:var(--cyan);color:#000"><?= (int)$stats['total_achats'] ?></span>
      <?php endif; ?>
    </a>


</aside>
<div id="sb-ov" onclick="closeSB()"></div>

<!-- ══════════════ MAIN ══════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header id="topbar">
    <button class="tb-ham" onclick="openSB()" type="button"><i class="bi bi-list"></i></button>
    <div class="tb-path">
      <span>DLS</span>
      <span style="opacity:.3">/</span>
      <a href="../books/index.php" style="color:var(--ts);text-decoration:none">Catalogue</a>
      <span style="opacity:.3">/</span>
      <span class="tb-curr">Mes Achats</span>
    </div>
    <div class="tb-sp"></div>
    <!-- Search inline -->
    <form method="GET" style="display:contents">
      <?php foreach (['mois'=>$filterMonth,'annee'=>$filterYear,'methode'=>$filterMethod,'cat'=>$filterCat,'tri'=>$sortBy] as $k=>$v): ?>
        <?php if ($v): ?><input type="hidden" name="<?= $k ?>" value="<?= safeE((string)$v) ?>"><?php endif; ?>
      <?php endforeach; ?>
      <div class="tb-search">
        <i class="bi bi-search" style="color:var(--tm);font-size:.8rem"></i>
        <input type="search" name="q" placeholder="Rechercher un achat…" value="<?= safeE($search) ?>" autocomplete="off">
      </div>
    </form>
    <div style="display:flex;align-items:center;gap:5px">
      <button class="tb-btn" id="np-btn" onclick="toggleNP()" type="button" aria-label="Notifications">
        <i class="bi bi-bell"></i>
        <?php if ($notifCount > 0): ?><span class="nb"><?= min($notifCount,9) ?></span><?php endif; ?>
      </button>
      <a class="tb-btn" href="profile.php" style="font-family:'Syne',sans-serif;font-weight:800;font-size:.72rem;width:auto;padding:0 10px;gap:6px">
        <div style="width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,var(--neon),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:.68rem;color:#000;font-weight:800"><?= $avatar ?></div>
        <span style="font-size:.75rem"><?= $firstName ?></span>
      </a>
    </div>
  </header>

  <!-- PAGE -->
  <main class="page">

    <!-- HERO -->
    <div class="hero">
      <div>
        <div class="hero-t">Mes Achats 🛍️</div>
        <div class="hero-s">
          <?php if ((int)($stats['total_achats']??0) > 0): ?>
            <strong><?= (int)$stats['total_achats'] ?></strong> achat<?= (int)$stats['total_achats']>1?'s':'' ?> confirmé<?= (int)$stats['total_achats']>1?'s':'' ?> ·
            Total investi : <strong><?= fmtFCFA((float)($stats['total_depense']??0)) ?></strong>
          <?php else: ?>
            Votre historique d'achats apparaîtra ici. Explorez le catalogue !
          <?php endif; ?>
        </div>
        <div class="hero-pills">
          <?php if ($totalCount): ?>
            <span class="pill p-neon"><i class="bi bi-bag-check"></i> <?= $totalCount ?> achat<?= $totalCount>1?'s':'' ?></span>
          <?php endif; ?>
          <?php if ((float)($stats['depense_mois']??0)>0): ?>
            <span class="pill p-cyan"><i class="bi bi-calendar3"></i> <?= fmtFCFA((float)$stats['depense_mois']) ?> ce mois</span>
          <?php endif; ?>
          <?php if ((int)($stats['livres_termines']??0)>0): ?>
            <span class="pill p-amber"><i class="bi bi-check2-circle"></i> <?= (int)$stats['livres_termines'] ?> terminé<?= (int)$stats['livres_termines']>1?'s':'' ?></span>
          <?php endif; ?>
          <?php if ((int)($bonus['bonus_restant']??0)>0): ?>
            <span class="pill p-violet"><i class="bi bi-gift-fill"></i> <?= (int)$bonus['bonus_restant'] ?> bonus dispo</span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="../books/index.php" class="btn btn-primary"><i class="bi bi-compass"></i> Explorer</a>
        <a href="../books/my_library.php" class="btn btn-ghost"><i class="bi bi-bookmark-heart"></i> Ma bibliothèque</a>
      </div>
    </div>

    <!-- STATS -->
    <div class="sg">
      <div class="sc">
        <div class="sc-i" style="background:rgba(0,255,170,.08)">🛍️</div>
        <div class="sc-v"><?= (int)($stats['total_achats']??0) ?></div>
        <div class="sc-l">Total achats</div>
        <div class="sc-sub">Confirmés</div>
      </div>
      <div class="sc">
        <div class="sc-i" style="background:rgba(0,212,255,.08)">💰</div>
        <div class="sc-v" style="font-size:1rem"><?= fmtFCFA((float)($stats['total_depense']??0)) ?></div>
        <div class="sc-l">Total investi</div>
        <div class="sc-sub"><?= fmtFCFA((float)($stats['depense_annee']??0)) ?> cette année</div>
      </div>
      <div class="sc">
        <div class="sc-i" style="background:rgba(245,158,11,.08)">📅</div>
        <div class="sc-v"><?= (int)($stats['achats_mois']??0) ?></div>
        <div class="sc-l">Ce mois</div>
        <div class="sc-sub"><?= fmtFCFA((float)($stats['depense_mois']??0)) ?></div>
      </div>
      <div class="sc">
        <div class="sc-i" style="background:rgba(124,58,237,.08)">✅</div>
        <div class="sc-v"><?= (int)($stats['livres_termines']??0) ?></div>
        <div class="sc-l">Livres terminés</div>
        <div class="sc-sub">Lectures complètes</div>
      </div>
      <div class="sc">
        <div class="sc-i" style="background:rgba(244,63,94,.08)">🎁</div>
        <div class="sc-v"><?= (int)($bonus['bonus_restant']??0) ?></div>
        <div class="sc-l">Bonus disponibles</div>
        <div class="sc-sub"><?= (int)($bonus['bonus_total']??0) ?> gagnés au total</div>
      </div>
    </div>

    <!-- MAIN GRID -->
    <div class="two-col">

      <!-- COL GAUCHE : liste achats -->
      <div>
        <!-- FILTRES -->
        <form method="GET" id="filter-form">
          <div class="filters">
            <span class="filter-label">Filtrer :</span>

            <select name="annee" class="f-select" onchange="this.form.submit()">
              <option value="">Toutes les années</option>
              <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= $filterYear===$y?'selected':'' ?>><?= $y ?></option>
              <?php endforeach; ?>
            </select>

            <select name="mois" class="f-select" onchange="this.form.submit()">
              <option value="">Tous les mois</option>
              <?php $moisFr=['','Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
              for ($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= $filterMonth===$m?'selected':'' ?>><?= $moisFr[$m] ?></option>
              <?php endfor; ?>
            </select>

            <?php if (!empty($categories)): ?>
            <select name="cat" class="f-select" onchange="this.form.submit()">
              <option value="">Toutes catégories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>" <?= $filterCat===(int)$cat['id']?'selected':'' ?>>
                  <?= safeE($cat['icone']??'') ?> <?= safeE($cat['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <?php if (!empty($methods)): ?>
            <select name="methode" class="f-select" onchange="this.form.submit()">
              <option value="">Tous moyens</option>
              <?php foreach ($methods as $meth): ?>
                <option value="<?= safeE($meth) ?>" <?= $filterMethod===$meth?'selected':'' ?>>
                  <?= safeE($methodIcons[$meth]??'💳') ?> <?= safeE($methodLabels[$meth]??$meth) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <div class="f-sep"></div>

            <select name="tri" class="f-select" onchange="this.form.submit()">
              <option value="recent"  <?= $sortBy==='recent'?'selected':'' ?>>Plus récents</option>
              <option value="ancien"  <?= $sortBy==='ancien'?'selected':'' ?>>Plus anciens</option>
              <option value="prix_h"  <?= $sortBy==='prix_h'?'selected':'' ?>>Prix ↓</option>
              <option value="prix_b"  <?= $sortBy==='prix_b'?'selected':'' ?>>Prix ↑</option>
              <option value="titre"   <?= $sortBy==='titre' ?'selected':'' ?>>Titre A–Z</option>
            </select>

            <?php if ($search || $filterMonth || $filterYear || $filterMethod || $filterCat): ?>
            <a href="purchases.php" class="btn btn-ghost btn-sm"><i class="bi bi-x-circle"></i> Réinitialiser</a>
            <?php endif; ?>

            <span class="filter-count"><?= $totalCount ?> résultat<?= $totalCount>1?'s':'' ?></span>
          </div>
        </form>

        <!-- LISTE ACHATS -->
        <?php if (empty($purchases)): ?>
        <div class="empty">
          <div class="empty-icon">🛍️</div>
          <?php if ($search || $filterMonth || $filterYear || $filterMethod): ?>
            <div class="empty-t">Aucun résultat</div>
            <div class="empty-s">Modifiez vos filtres ou réinitialisez la recherche.</div>
            <a href="purchases.php" class="btn btn-ghost">Réinitialiser</a>
          <?php else: ?>
            <div class="empty-t">Aucun achat pour le moment</div>
            <div class="empty-s">Explorez le catalogue et achetez votre premier livre.</div>
            <a href="../books/index.php" class="btn btn-primary">Explorer le catalogue</a>
          <?php endif; ?>
        </div>
        <?php else: ?>

        <div class="pur-list">
          <?php foreach ($purchases as $i => $p):
            $pct    = (float)($p['pourcentage'] ?? 0);
            $done   = ($p['lect_statut'] ?? '') === 'termine';
            $isFav  = !empty($p['is_favorite']);
            $myNote = (int)($p['ma_note'] ?? 0);
            $emoji  = $bookEmojis[$i % count($bookEmojis)];
            $pdfPath = !empty($p['fichier_pdf']) ? '../' . safeE($p['fichier_pdf']) : '';
            $methLabel = $methodLabels[$p['methode']??''] ?? ($p['methode']??'');
            $methIcon  = $methodIcons[$p['methode']??'']  ?? '💳';
          ?>
          <div class="pur-card" data-id="<?= (int)$p['achat_id'] ?>" style="animation-delay:<?= $i*.04 ?>s">
            <div class="pur-head">
              <!-- Cover -->
              <div class="pur-emoji"><?= $emoji ?></div>
              <!-- Info -->
              <div class="pur-info">
                <div class="pur-title"><?= safeE($p['titre']??'') ?></div>
                <div class="pur-author"><?= safeE($p['auteur']??'') ?></div>
                <div class="pur-meta">
                  <?php if (!empty($p['categorie_nom'])): ?>
                    <span class="chip chip-violet"><?= safeE($p['cat_icone']??'') ?> <?= safeE($p['categorie_nom']) ?></span>
                  <?php endif; ?>
                  <?php if ($done): ?>
                    <span class="chip chip-neon">✓ Lu</span>
                  <?php elseif ($pct > 0): ?>
                    <span class="chip chip-cyan">En cours <?= round($pct) ?>%</span>
                  <?php else: ?>
                    <span class="chip chip-muted">Non commencé</span>
                  <?php endif; ?>
                  <?php if (!empty($p['is_bestseller'])): ?>
                    <span class="chip chip-amber">Bestseller</span>
                  <?php elseif (!empty($p['is_featured'])): ?>
                    <span class="chip chip-violet">★ Premium</span>
                  <?php endif; ?>
                  <?php if ($myNote > 0): ?>
                    <span style="color:var(--amber);font-size:.75rem">
                      <?php for($s=1;$s<=5;$s++) echo $s<=$myNote?'★':'☆'; ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <!-- Right -->
              <div class="pur-right">
                <div class="pur-amount"><?= fmtFCFA((float)($p['montant']??0)) ?></div>
                <div class="pur-date"><?= date('d/m/Y', strtotime($p['date_achat'])) ?></div>
                <div class="pur-ref"><?= $methIcon ?> <?= safeE($methLabel) ?></div>
                <div class="pur-ref" style="font-size:.55rem;opacity:.6">#<?= safeE($p['reference']??'') ?></div>
              </div>
            </div>

            <!-- Body : progression + actions -->
            <div class="pur-body">
              <!-- Progression -->
              <div class="pur-prog">
                <?php if ($p['pages'] ?? 0): ?>
                <div style="font-size:.68rem;color:var(--tm);margin-bottom:4px">
                  <?php if ($done): ?>
                    <i class="bi bi-check2-circle" style="color:var(--neon)"></i> Lecture terminée
                  <?php elseif ($pct > 0): ?>
                    Page <?= (int)($p['page_actuelle']??0) ?> / <?= (int)($p['pages']??0) ?> · <?= round($pct) ?>% lu
                  <?php else: ?>
                    <?= (int)($p['pages']??0) ?> pages · Non commencé
                  <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="pur-prog-row">
                  <div class="prog-bar">
                    <div class="prog-fill <?= $done?'green':'' ?>" style="width:<?= $pct>0?$pct:0 ?>%"></div>
                  </div>
                  <span class="prog-pct"><?= $done ? '✓' : ($pct>0 ? round($pct).'%' : '0%') ?></span>
                </div>
                <!-- Stars -->
                <div class="stars" data-livre="<?= (int)$p['livre_id'] ?>" data-note="<?= $myNote ?>" style="margin-top:5px">
                  <?php for($s=1;$s<=5;$s++): ?>
                  <span class="star <?= $s<=$myNote?'on':'' ?>"
                        onclick="quickRate(<?= (int)$p['livre_id'] ?>, <?= $s ?>, this.closest('.stars'))"
                        onmouseenter="previewStars(this,<?= $s ?>)"
                        onmouseleave="restoreStars(this.closest('.stars'))">★</span>
                  <?php endfor; ?>
                </div>
              </div>

              <!-- Actions -->
              <div class="pur-actions">
                <!-- Lire -->
                <?php if ($pdfPath): ?>
                <a href="#" class="btn btn-primary btn-sm"
                   onclick="openPdf(<?= json_encode($pdfPath,JSON_HEX_TAG) ?>, <?= (int)$p['livre_id'] ?>, <?= (int)($p['page_actuelle']??1) ?>, <?= json_encode($p['titre']??'',JSON_HEX_TAG) ?>);return false;">
                  <i class="bi bi-<?= $done?'arrow-counterclockwise':'book-fill' ?>"></i>
                  <?= $done ? 'Relire' : ($pct>0?'Reprendre':'Lire') ?>
                </a>
                <?php else: ?>
                <a href="../books/read.php?id=<?= (int)$p['livre_id'] ?>" class="btn btn-primary btn-sm">
                  <i class="bi bi-book-fill"></i> <?= $done ? 'Relire' : ($pct>0?'Reprendre':'Lire') ?>
                </a>
                <?php endif; ?>

                <!-- Voir détail -->
                <a class="ic-btn" href="../books/view.php?id=<?= (int)$p['livre_id'] ?>" title="Détails du livre">
                  <i class="bi bi-eye"></i>
                </a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
        <div class="pag">
          <?php
          $qs = http_build_query(array_filter(['mois'=>$filterMonth,'annee'=>$filterYear,'methode'=>$filterMethod,'cat'=>$filterCat,'tri'=>$sortBy,'q'=>$search]));
          ?>
          <?php if ($currentPage > 1): ?>
            <a href="purchases.php?page=<?= $currentPage-1 ?>&<?= $qs ?>" class="pag-btn"><i class="bi bi-chevron-left"></i></a>
          <?php else: ?>
            <span class="pag-btn" style="opacity:.3"><i class="bi bi-chevron-left"></i></span>
          <?php endif; ?>

          <?php for ($pg = max(1,$currentPage-2); $pg <= min($totalPages,$currentPage+2); $pg++): ?>
            <a href="purchases.php?page=<?= $pg ?>&<?= $qs ?>" class="pag-btn <?= $pg===$currentPage?'on':'' ?>"><?= $pg ?></a>
          <?php endfor; ?>

          <?php if ($currentPage < $totalPages): ?>
            <a href="purchases.php?page=<?= $currentPage+1 ?>&<?= $qs ?>" class="pag-btn"><i class="bi bi-chevron-right"></i></a>
          <?php else: ?>
            <span class="pag-btn" style="opacity:.3"><i class="bi bi-chevron-right"></i></span>
          <?php endif; ?>

          <span style="font-size:.68rem;color:var(--tm);font-family:'Space Mono',monospace;margin-left:8px">
            <?= $currentPage ?>/<?= $totalPages ?>
          </span>
        </div>
        <?php endif; ?>

        <?php endif; /* end purchases */ ?>
      </div><!-- /col gauche -->

      <!-- COL DROITE : sidebar -->
      <div>

        <!-- Bonus -->
        <?php if (isset($bonus['achat_count'])): ?>
        <div class="bonus-card" style="animation-delay:.1s">
          <div class="bonus-glow"></div>
          <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:.95rem;margin-bottom:4px">🎁 Programme Fidélité</div>
          <div style="font-size:.75rem;color:var(--ts)">
            <?php $rule=5; $left=max(0,$rule-(int)$bonus['achat_count']); ?>
            <?php if ((int)$bonus['bonus_restant']>0): ?>
              🎉 <strong style="color:var(--gold)"><?= (int)$bonus['bonus_restant'] ?> livre<?= (int)$bonus['bonus_restant']>1?'s':'' ?> gratuit<?= (int)$bonus['bonus_restant']>1?'s':'' ?></strong> disponible<?= (int)$bonus['bonus_restant']>1?'s':'' ?> !
            <?php else: ?>
              Plus que <strong><?= $left ?></strong> achat<?= $left>1?'s':'' ?> pour votre prochain bonus
            <?php endif; ?>
          </div>
          <div class="bonus-prog-wrap">
            <div class="bonus-prog" style="width:<?= min(100,round((int)$bonus['achat_count']/$rule*100)) ?>%"></div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--tm);font-family:'Space Mono',monospace">
            <span><?= (int)$bonus['achat_count'] ?>/<?= $rule ?> achats</span>
            <span><?= (int)$bonus['bonus_total'] ?> bonus gagnés</span>
          </div>
          <?php if ((int)$bonus['bonus_restant']>0): ?>
          <a href="../books/index.php?bonus=1" class="btn btn-neon btn-sm" style="margin-top:.8rem;width:100%;justify-content:center">
            <i class="bi bi-gift-fill"></i> Utiliser mes bonus
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Graphique mensuel -->
        <?php if (!empty($monthsChart)): ?>
        <div class="side-card" style="animation-delay:.15s">
          <div class="side-head"><i class="bi bi-bar-chart-line" style="color:var(--cyan)"></i> Achats — 12 mois</div>
          <div style="padding:1rem 1.2rem">
            <?php
            $maxNb = max(1, ...array_column($monthsChart, 'nb'));
            ?>
            <div class="chart-bars">
              <?php foreach ($monthsChart as $cm):
                $h = max(4, round($cm['nb']/$maxNb*72));
              ?>
              <div class="cw">
                <div class="cb2" style="height:<?= $h ?>px">
                  <span class="cb-tt"><?= (int)$cm['nb'] ?> achat<?= $cm['nb']>1?'s':'' ?><?php if($cm['total']>0): ?> · <?= fmtFCFA((float)$cm['total']) ?><?php endif; ?></span>
                </div>
                <div class="cb-l"><?= safeE($cm['label']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:.7rem;font-size:.68rem;color:var(--tm)">
              <span>Total : <strong style="color:var(--neon)"><?= array_sum(array_column($monthsChart,'nb')) ?> achats</strong></span>
              <span>Moy. : <strong style="color:var(--cyan)"><?= round(array_sum(array_column($monthsChart,'nb'))/12,1) ?>/mois</strong></span>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Top catégories -->
        <?php if (!empty($topCats)): ?>
        <div class="side-card" style="animation-delay:.2s">
          <div class="side-head"><i class="bi bi-tags" style="color:var(--amber)"></i> Catégories favorites</div>
          <div class="side-body">
            <?php $maxCat = max(1, ...array_column($topCats,'total')); ?>
            <?php foreach ($topCats as $tc): ?>
            <div class="li-item">
              <div class="li-icon" style="background:rgba(0,212,255,.08)"><?= safeE($tc['icone']??'📚') ?></div>
              <div class="li-info">
                <div class="li-t"><?= safeE($tc['nom']??'Général') ?></div>
                <div class="li-s"><?= (int)$tc['nb'] ?> livre<?= (int)$tc['nb']>1?'s':'' ?> · <?= fmtFCFA((float)$tc['total']) ?></div>
                <div style="height:3px;background:rgba(255,255,255,.06);border-radius:100px;margin-top:4px;overflow:hidden">
                  <div style="height:100%;width:<?= round((float)$tc['total']/$maxCat*100) ?>%;background:linear-gradient(90deg,var(--cyan),var(--violet));border-radius:100px;transition:width 1s ease"></div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Liens rapides -->
        <div class="side-card" style="animation-delay:.25s">
          <div class="side-head"><i class="bi bi-lightning-charge" style="color:var(--neon)"></i> Actions rapides</div>
          <div class="side-body">
            <?php $qlinks = [
              ['../books/index.php',              'bi-compass',           'var(--cyan)',   'Explorer le catalogue'],
              ['../books/my_library.php',          'bi-bookmark-heart',    'var(--neon)',   'Ma bibliothèque'],
              ['../books/my_library.php#favorites','bi-star',              'var(--amber)',  'Mes favoris'],
              ['../books/my_library.php#stats',    'bi-bar-chart-line',    'var(--violet)', 'Mes statistiques'],
              ['profile.php',                     'bi-person-gear',       'var(--ts)',     'Mon profil'],
            ];
            foreach ($qlinks as $ql): ?>
            <div class="li-item">
              <div class="li-icon" style="background:rgba(255,255,255,.04)">
                <i class="bi <?= $ql[1] ?>" style="color:<?= $ql[2] ?>;font-size:.85rem"></i>
              </div>
              <div class="li-info">
                <a href="<?= $ql[0] ?>" class="li-t" style="text-decoration:none;color:var(--tp)"><?= $ql[3] ?></a>
              </div>
              <i class="bi bi-chevron-right" style="color:var(--tm);font-size:.65rem"></i>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div><!-- /col droite -->
    </div><!-- /two-col -->

  </main>
</div><!-- /main -->
</div><!-- /app -->

<!-- ══════════════ NOTIFICATIONS PANEL ══════════════ -->
<div id="np" role="dialog" aria-label="Notifications">
  <div class="np-h">
    <span>Notifications</span>
    <?php if ($notifCount > 0): ?><span class="chip chip-rose"><?= $notifCount ?></span><?php endif; ?>
  </div>
  <div class="np-list">
    <?php if (empty($notifications)): ?>
    <div style="padding:1.5rem;text-align:center;color:var(--tm);font-size:.8rem">🔔 Aucune notification</div>
    <?php else: foreach ($notifications as $n):
      $unread = !(bool)($n['lu'] ?? $n['is_read'] ?? false);
    ?>
    <div class="np-item <?= $unread?'np-unread':'' ?>" onclick="markRead(<?= (int)$n['id'] ?>, this)">
      <div class="np-icon" style="background:<?= safeE($n['bg']??'rgba(0,212,255,.08)') ?>"><?= safeE($n['icon']??'🔔') ?></div>
      <div>
        <?php if (!empty($n['titre'])): ?><div class="np-txt" style="font-weight:600;color:var(--tp)"><?= safeE($n['titre']) ?></div><?php endif; ?>
        <div class="np-txt"><?= safeE(mb_substr($n['message']??'',0,80)) ?><?= mb_strlen($n['message']??'')>80?'…':'' ?></div>
        <div class="np-time"><?= timeAgo($n['created_at']??'') ?></div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <div style="padding:.7rem 1.1rem;border-top:1px solid var(--border);display:flex;gap:6px">
    <a href="../admin/notifications.php" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center">Voir tout</a>
    <a href="../admin/notifications.php?action=mark_all_read" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center">Tout lu</a>
  </div>
</div>

<!-- ══════════════ MODAL : FACTURE ══════════════ -->
<div class="modal-ov" id="invoice-modal" onclick="if(event.target===this)closeModal('invoice-modal')">
  <div class="modal-box" style="max-width:560px">
    <button class="modal-close" onclick="closeModal('invoice-modal')" type="button"><i class="bi bi-x-lg"></i></button>
    <div class="modal-t">🧾 Facture d'achat</div>
    <div id="invoice-content">
      <div style="text-align:center;padding:2rem;color:var(--tm)">
        <div class="pdf-spinner" style="margin:0 auto 1rem"></div>Chargement…
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:1.2rem">
      <button class="btn btn-ghost btn-sm" style="flex:1;justify-content:center" onclick="printInvoice()" type="button">
        <i class="bi bi-printer"></i> Imprimer
      </button>
      <button class="btn btn-neon btn-sm" style="flex:1;justify-content:center" onclick="closeModal('invoice-modal')" type="button">
        <i class="bi bi-check2"></i> Fermer
      </button>
    </div>
  </div>
</div>

<!-- ══════════════ MODAL : ÉVALUATION ══════════════ -->
<div class="modal-ov" id="review-modal" onclick="if(event.target===this)closeModal('review-modal')">
  <div class="modal-box" style="max-width:420px">
    <button class="modal-close" onclick="closeModal('review-modal')" type="button"><i class="bi bi-x-lg"></i></button>
    <div class="modal-t">⭐ Évaluer ce livre</div>
    <div id="review-book-name" style="font-size:.82rem;color:var(--ts);margin-bottom:1.2rem"></div>
    <input type="hidden" id="rev-livre-id" value="">
    <label class="f-label">Votre note</label>
    <div class="stars" id="rev-stars" style="font-size:2rem;gap:4px;margin-bottom:1rem">
      <?php for($s=1;$s<=5;$s++): ?>
      <span class="star" onclick="setRevStar(<?= $s ?>)"
            onmouseenter="previewRevStars(<?= $s ?>)"
            onmouseleave="restoreRevStars()">★</span>
      <?php endfor; ?>
    </div>
    <input type="hidden" id="rev-note" value="0">
    <label class="f-label" for="rev-comment">Commentaire (optionnel)</label>
    <textarea class="f-input f-textarea" id="rev-comment" placeholder="Partagez votre avis sur ce livre…" maxlength="500"></textarea>
    <button class="btn btn-primary" style="width:100%;justify-content:center" onclick="submitReview()" type="button">
      <i class="bi bi-send"></i> Envoyer mon avis
    </button>
  </div>
</div>

<!-- ══════════════ PDF READER MODAL ══════════════ -->
<div class="modal-ov" id="pdf-modal" onclick="if(event.target===this)closePdf()" style="align-items:stretch;padding:.5rem">
  <div style="background:var(--surf);border:1px solid var(--border);border-radius:var(--r4);width:100%;max-width:900px;max-height:95vh;display:flex;flex-direction:column;overflow:hidden;transform:translateY(20px);transition:transform .3s cubic-bezier(.34,1.56,.64,1);position:relative">
    <div id="pdf-modal-inner" class="modal-ov.open" style="display:flex;flex-direction:column;height:100%">
      <!-- Toolbar -->
      <div id="pdf-bar" style="background:var(--surf);border-bottom:1px solid var(--border);padding:10px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex-shrink:0">
        <button class="btn btn-ghost btn-sm" onclick="closePdf()" type="button"><i class="bi bi-x-lg"></i></button>
        <div id="pdf-title-el" style="font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
        <button class="btn btn-ghost btn-sm" onclick="pdfPrev()" id="btn-prev" type="button"><i class="bi bi-chevron-left"></i></button>
        <div id="pdf-pg" style="font-family:'Space Mono',monospace;font-size:.72rem;color:var(--tm);min-width:70px;text-align:center">— / —</div>
        <button class="btn btn-ghost btn-sm" onclick="pdfNext()" id="btn-next" type="button"><i class="bi bi-chevron-right"></i></button>
        <button class="btn btn-ghost btn-sm" onclick="pdfZoom(-1)" type="button"><i class="bi bi-zoom-out"></i></button>
        <span id="pdf-zoom" style="font-family:'Space Mono',monospace;font-size:.7rem;color:var(--tm);min-width:40px;text-align:center">100%</span>
        <button class="btn btn-ghost btn-sm" onclick="pdfZoom(1)" type="button"><i class="bi bi-zoom-in"></i></button>
        <button class="btn btn-ghost btn-sm" onclick="toggleFS()" type="button"><i class="bi bi-fullscreen"></i></button>
      </div>
      <!-- Progress -->
      <div id="pdf-prog-bar" style="height:3px;width:0%;background:linear-gradient(90deg,var(--cyan),var(--violet));transition:width .5s ease;flex-shrink:0"></div>
      <!-- Canvas -->
      <div id="pdf-canvas-wrap" style="flex:1;overflow:auto;display:flex;justify-content:center;padding:1rem;background:#111;position:relative">
        <canvas id="pdf-canvas" style="max-width:100%;box-shadow:0 4px 20px rgba(0,0,0,.5)"></canvas>
        <div id="pdf-loading" style="display:none;position:absolute;inset:0;background:rgba(0,0,0,.7);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.7rem">
          <div style="width:36px;height:36px;border:3px solid rgba(0,212,255,.2);border-top-color:var(--cyan);border-radius:50%;animation:spin 1s linear infinite"></div>
          <span style="font-family:'Space Mono',monospace;font-size:.78rem;color:var(--tm)">Chargement…</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- TOAST STACK -->
<div id="toast-s" role="region" aria-live="assertive"></div>

<!-- ══════════════ SCRIPTS ══════════════ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
/* ── GLOBALS ── */
const CSRF    = <?= json_encode($csrfToken,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const API     = window.location.href.split('?')[0];
const FNAME   = <?= json_encode($firstName,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}

/* ── AJAX ── */
async function api(action, data={}) {
  data.csrf = CSRF;
  const fd = new FormData();
  Object.entries(data).forEach(([k,v]) => fd.append(k, v));
  const r = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
    method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd
  });
  if (!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}

/* ── TOAST ── */
const TI = {info:'ℹ️',success:'✅',warn:'⚠️',error:'❌'};
const TC = {info:'var(--cyan)',success:'var(--neon)',warn:'var(--amber)',error:'var(--rose)'};
function toast(title, sub='', type='info', dur=3500) {
  const s = document.getElementById('toast-s');
  const t = document.createElement('div');
  t.className='toast';
  t.style.borderColor = TC[type]||TC.info;
  t.innerHTML=`<span>${TI[type]||'ℹ️'}</span><div style="flex:1"><div class="t-title">${esc(title)}</div>${sub?`<div class="t-sub">${esc(sub)}</div>`:''}</div><span style="cursor:pointer;color:var(--tm)" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></span>`;
  s.appendChild(t);
  requestAnimationFrame(()=>requestAnimationFrame(()=>t.classList.add('show')));
  setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),380)},dur);
}

/* ── SIDEBAR ── */
function openSB(){document.getElementById('sb').classList.add('open');document.getElementById('sb-ov').classList.add('show')}
function closeSB(){document.getElementById('sb').classList.remove('open');document.getElementById('sb-ov').classList.remove('show')}

/* ── NOTIF PANEL ── */
function toggleNP(){
  const p=document.getElementById('np');p.classList.toggle('open');
}
document.addEventListener('click',e=>{
  const p=document.getElementById('np'),b=document.getElementById('np-btn');
  if(p?.classList.contains('open')&&!p.contains(e.target)&&!b?.contains(e.target))p.classList.remove('open');
});
async function markRead(id, el) {
  el.classList.remove('np-unread');
  const badge=document.querySelector('.nb');
  if(badge){const n=parseInt(badge.textContent)-1;if(n<=0)badge.remove();else badge.textContent=n;}
  try{await api('mark_notif_read',{notif_id:id})}catch(e){}
}

/* ── MODALS ── */
function openModal(id){document.getElementById(id)?.classList.add('open')}
function closeModal(id){document.getElementById(id)?.classList.remove('open')}
document.addEventListener('keydown',e=>{
  if(e.key!=='Escape')return;
  document.querySelectorAll('.modal-ov.open').forEach(m=>m.classList.remove('open'));
  closePdf();
});

/* ── FAVORIS ── */
async function toggleFav(btn, livreId) {
  btn.disabled=true;
  try {
    const d = await api('toggle_favorite',{livre_id:livreId});
    if(d.success){
      const added=d.action==='added';
      btn.classList.toggle('fav',added);
      const ic=btn.querySelector('i');
      if(ic)ic.className='bi bi-'+(added?'star-fill':'star');
      toast(added?'Favori ajouté ⭐':'Favori retiré',d.msg||'','success',2500);
    } else toast('Erreur',d.error||'','error');
  } catch(e){toast('Erreur réseau',e.message,'error')} finally{btn.disabled=false}
}

/* ── RATING ── */
function previewStars(star, n) {
  const row=star.closest('.stars');
  row.querySelectorAll('.star').forEach((s,i)=>s.classList.toggle('on',i<n));
}
function restoreStars(row) {
  const note=parseInt(row.dataset.note||0);
  row.querySelectorAll('.star').forEach((s,i)=>s.classList.toggle('on',i<note));
}
async function quickRate(livreId, note, row) {
  row.dataset.note=note;
  row.querySelectorAll('.star').forEach((s,i)=>s.classList.toggle('on',i<note));
  try{
    const d=await api('save_review',{livre_id:livreId,note});
    if(d.success)toast('Note enregistrée',note+'/5 ⭐','success',2000);
    else toast('Erreur',d.error||'','error');
  }catch(e){toast('Erreur',e.message,'error')}
}

/* ── REVIEW MODAL ── */
let revStar=0;
function openReview(livreId, titre, existingNote) {
  document.getElementById('rev-livre-id').value=livreId;
  document.getElementById('review-book-name').textContent=titre;
  document.getElementById('rev-comment').value='';
  revStar=parseInt(existingNote)||0;
  updateRevStars(revStar);
  document.getElementById('rev-note').value=revStar;
  openModal('review-modal');
}
function setRevStar(n){revStar=n;document.getElementById('rev-note').value=n;updateRevStars(n)}
function previewRevStars(n){document.querySelectorAll('#rev-stars .star').forEach((s,i)=>s.classList.toggle('on',i<n))}
function restoreRevStars(){updateRevStars(revStar)}
function updateRevStars(n){document.querySelectorAll('#rev-stars .star').forEach((s,i)=>s.classList.toggle('on',i<n))}
async function submitReview() {
  const livreId=document.getElementById('rev-livre-id').value;
  const note=parseInt(document.getElementById('rev-note').value)||revStar;
  const comment=document.getElementById('rev-comment').value.trim();
  if(!note){toast('Erreur','Sélectionnez une note (1-5)','warn');return}
  try{
    const d=await api('save_review',{livre_id:livreId,note,commentaire:comment});
    if(d.success){toast('Avis publié',note+'/5 ⭐','success');closeModal('review-modal')}
    else toast('Erreur',d.error||'','error');
  }catch(e){toast('Erreur',e.message,'error')}
}

/* ── INVOICE ── */
async function openInvoice(achatId) {
  openModal('invoice-modal');
  document.getElementById('invoice-content').innerHTML=`<div style="text-align:center;padding:2rem;color:var(--tm)"><div style="width:28px;height:28px;border:2px solid rgba(0,212,255,.2);border-top-color:var(--cyan);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 1rem"></div>Génération de la facture…</div>`;
  try {
    const d = await api('get_invoice',{achat_id:achatId});
    if(!d.success){document.getElementById('invoice-content').innerHTML=`<div style="color:var(--rose);padding:1rem">${esc(d.error||'Erreur')}</div>`;return}
    const inv=d.invoice;
    const date=inv.created_at ? new Date(inv.created_at).toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric'}) : '—';
    const dateAchat=inv.date_achat ? new Date(inv.date_achat).toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric'}) : '—';
    document.getElementById('invoice-content').innerHTML=`
    <div class="invoice">
      <div class="inv-stamp">✅</div>
      <div class="inv-logo">Digital <em>Library</em></div>
      <div style="font-family:'Space Mono',monospace;font-size:.65rem;color:var(--tm);margin-bottom:1.2rem">REÇU D'ACHAT OFFICIEL</div>
      <div class="inv-row"><span class="inv-key">Référence facture</span><span class="inv-val">${esc(inv.facture_reference||'—')}</span></div>
      <div class="inv-row"><span class="inv-key">Référence paiement</span><span class="inv-val">${esc(inv.reference||'—')}</span></div>
      <div class="inv-row"><span class="inv-key">Date d'achat</span><span class="inv-val">${esc(dateAchat)}</span></div>
      <div class="inv-row"><span class="inv-key">Livre</span><span class="inv-val">${esc(inv.titre||'—')}</span></div>
      <div class="inv-row"><span class="inv-key">Auteur</span><span class="inv-val">${esc(inv.auteur||'—')}</span></div>
      <div class="inv-row"><span class="inv-key">Catégorie</span><span class="inv-val">${esc(inv.cat||'—')}</span></div>
      <div class="inv-row"><span class="inv-key">Moyen de paiement</span><span class="inv-val">${esc(inv.methode||'—')}</span></div>
      <div class="inv-row"><span class="inv-key">Statut</span><span class="inv-val" style="color:var(--neon)">✓ CONFIRMÉ</span></div>
      <div class="inv-total">
        <span class="inv-total-lbl">Total payé</span>
        <span class="inv-total-amt">${parseInt(inv.montant||0).toLocaleString('fr-CM')} FCFA</span>
      </div>
      <div style="margin-top:1rem;padding:10px;background:rgba(0,255,170,.05);border:1px solid rgba(0,255,170,.15);border-radius:8px;font-size:.65rem;font-family:'Space Mono',monospace;color:var(--tm);text-align:center">
        Généré le ${esc(date)} · Digital Library Platform
      </div>
    </div>`;
  }catch(e){document.getElementById('invoice-content').innerHTML=`<div style="color:var(--rose);padding:1rem">Erreur : ${esc(e.message)}</div>`}
}
function printInvoice(){
  const c=document.getElementById('invoice-content').innerHTML;
  const w=window.open('','_blank','width=600,height=700');
  w.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Facture</title><style>
    body{font-family:sans-serif;background:#111;color:#eef;padding:2rem}
    .invoice{background:#1a1a2e;border:1px solid rgba(0,212,255,.3);border-radius:12px;padding:1.5rem;max-width:500px;margin:0 auto}
    .inv-logo{font-weight:800;font-size:1.2rem;color:#00d4ff;margin-bottom:.5rem}
    .inv-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.07);font-size:.82rem}
    .inv-key{color:#aaa}.inv-val{font-family:monospace;text-align:right}
    .inv-total{display:flex;justify-content:space-between;padding:10px 0;border-top:1px solid rgba(0,212,255,.3);font-weight:700}
    .inv-total-amt{color:#00ffaa;font-size:1.2rem}
  </style></head><body>${c}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(()=>w.print(),400);
}

/* ── DOWNLOAD ── */
async function downloadPdf(livreId, path) {
  toast('Téléchargement','Démarrage…','info',2500);
  try { await api('log_download',{livre_id:livreId}); } catch(e){}
  const a=document.createElement('a');a.href=path;a.download='';a.click();
}

/* ═══════ PDF.JS READER ═══════ */
if (typeof pdfjsLib !== 'undefined') {
  pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}
let pdfDoc=null, pdfPage=1, pdfTotal=0, pdfScale=1.2, pdfLivreId=null, pdfSaveT=null;

function openPdf(path, livreId, startPage, titre) {
  pdfLivreId=livreId; pdfPage=parseInt(startPage)||1;
  document.getElementById('pdf-title-el').textContent=titre||'Lecture';
  openModal('pdf-modal');
  if(typeof pdfjsLib==='undefined'){toast('Erreur','PDF.js non disponible','error',5000);closePdf();return}
  loadPdfDoc(path);
}
function closePdf(){
  closeModal('pdf-modal');
  if(pdfDoc&&pdfLivreId)savePdfProg();
  pdfDoc=null;
}
async function loadPdfDoc(url) {
  showPdfLoad(true);
  try{
    pdfDoc=await pdfjsLib.getDocument({url}).promise;
    pdfTotal=pdfDoc.numPages;
    await renderPdfPage(pdfPage);
  }catch(e){toast('Erreur PDF',e.message,'error',5000);closePdf()}
}
async function renderPdfPage(n) {
  if(!pdfDoc)return;
  showPdfLoad(true);
  const page=await pdfDoc.getPage(n);
  const vp=page.getViewport({scale:pdfScale});
  const canvas=document.getElementById('pdf-canvas');
  const ctx=canvas.getContext('2d');
  canvas.width=vp.width;canvas.height=vp.height;
  await page.render({canvasContext:ctx,viewport:vp}).promise;
  pdfPage=n;
  document.getElementById('pdf-pg').textContent=`${n} / ${pdfTotal}`;
  document.getElementById('btn-prev').disabled=n<=1;
  document.getElementById('btn-next').disabled=n>=pdfTotal;
  document.getElementById('pdf-zoom').textContent=Math.round(pdfScale*100)+'%';
  document.getElementById('pdf-prog-bar').style.width=Math.round(n/pdfTotal*100)+'%';
  showPdfLoad(false);
  clearTimeout(pdfSaveT);
  pdfSaveT=setTimeout(savePdfProg, 3000);
}
function pdfPrev(){if(pdfPage>1)renderPdfPage(pdfPage-1)}
function pdfNext(){if(pdfPage<pdfTotal)renderPdfPage(pdfPage+1)}
function pdfZoom(d){pdfScale=Math.max(.4,Math.min(3,pdfScale+d*.2));renderPdfPage(pdfPage)}
function showPdfLoad(show){
  const l=document.getElementById('pdf-loading'),w=document.getElementById('pdf-canvas-wrap');
  if(l)l.style.display=show?'flex':'none';
  if(w)w.style.opacity=show?'.3':'1';
}
function toggleFS(){
  const el=document.getElementById('pdf-modal');
  if(!document.fullscreenElement)el?.requestFullscreen?.();
  else document.exitFullscreen?.();
}
async function savePdfProg(){
  if(!pdfLivreId||!pdfDoc)return;
  try{await api('log_download',{livre_id:pdfLivreId})}catch(e){}
  // Save progression via library endpoint
  try{
    const fd=new FormData();
    fd.append('csrf',CSRF);fd.append('livre_id',pdfLivreId);
    fd.append('page',pdfPage);fd.append('total_pages',pdfTotal);
    fetch('../books/my_library.php?action=save_progress',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).catch(()=>{});
  }catch(e){}
}
document.addEventListener('keydown',e=>{
  if(!pdfDoc)return;
  if(e.key==='ArrowRight'||e.key==='ArrowDown')pdfNext();
  if(e.key==='ArrowLeft'||e.key==='ArrowUp')pdfPrev();
  if(e.key==='+')pdfZoom(1);
  if(e.key==='-')pdfZoom(-1);
});

/* ── INIT ── */
@keyframes spin{to{transform:rotate(360deg)}}
document.addEventListener('DOMContentLoaded',()=>{
  // Animate progress bars
  setTimeout(()=>{
    document.querySelectorAll('.prog-fill').forEach(b=>{
      const w=b.style.width;b.style.width='0%';
      requestAnimationFrame(()=>requestAnimationFrame(()=>{b.style.width=w}));
    });
    document.querySelectorAll('.bonus-prog').forEach(b=>{
      const w=b.style.width;b.style.width='0%';
      requestAnimationFrame(()=>requestAnimationFrame(()=>{b.style.width=w}));
    });
    document.querySelectorAll('[style*="transition:width"]').forEach(b=>{
      const w=b.style.width;b.style.width='0%';
      requestAnimationFrame(()=>requestAnimationFrame(()=>{b.style.width=w}));
    });
  }, 300);

  // Welcome toast
  const nb=<?= $totalCount ?>;
  setTimeout(()=>{
    if(nb>0)toast('Mes Achats',nb+' achat'+(nb>1?'s':'')+' confirmé'+(nb>1?'s':''),'success',3500);
    else toast('Bienvenue '+FNAME,'Explorez le catalogue pour commencer','info',3500);
  },600);

  // URL params
  const p=new URLSearchParams(window.location.search);
  if(p.get('success')==='purchase')toast('Achat confirmé !','Votre livre est disponible','success',5000);
  if(p.get('error')==='access_denied')toast('Accès refusé','Droits insuffisants','error');
});

// Style for spin keyframe (inline since we can't use style tag after body)
const style=document.createElement('style');
style.textContent='@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(style);
</script>
</body>
</html>