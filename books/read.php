<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — read.php  v1.0                           ║
 * ║  Lecteur PDF premium · Bookmarks · Annotations · Stats      ║
 * ║  Sauvegarde progression AJAX · Contrôle d'accès sécurisé    ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Session ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Dépendances ──────────────────────────────────────────────
foreach ([
    __DIR__ . '/includes/config.php',
    __DIR__ . '/config/config.php',
    __DIR__ . '/config/database.php',
    __DIR__ . '/includes/database.php',
] as $cfgPath) {
    if (file_exists($cfgPath) && !defined('DB_HOST_LOADED')) {
        require_once $cfgPath;
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
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES   => false]
        );
    } catch (PDOException $e) {
        error_log('[DLS Reader] PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth guard ────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    // Mode DEMO — retirer en production
    if ($pdo) {
        try {
            $demo = $pdo->query("SELECT * FROM users WHERE statut='actif' LIMIT 1")->fetch();
            if ($demo) {
                $_SESSION['user_id']    = $demo['id'];
                $_SESSION['user_role']  = $demo['role'];
                $_SESSION['user_name']  = trim($demo['prenom'] . ' ' . $demo['nom']);
                $_SESSION['user_email'] = $demo['email'];
            }
        } catch (Exception $e) {}
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit;
    }
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';
$username = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$avatar   = strtoupper(substr($username, 0, 1)) ?: 'U';

// ── Créer les tables manquantes ───────────────────────────────
if ($pdo) {
    try {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS reading_bookmarks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            page_number INT NOT NULL,
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS reading_annotations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            page_number INT NOT NULL,
            texte_selectionne TEXT NULL,
            annotation TEXT NULL,
            couleur VARCHAR(20) DEFAULT '#facc15',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS reading_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            page_number INT DEFAULT 1,
            temps_lecture INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {
        error_log('[DLS Reader] Table creation: ' . $e->getMessage());
    }
}

// ── CSRF ──────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── AJAX handlers ─────────────────────────────────────────────
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // Validation CSRF pour les mutations
    if (in_array($action, ['save_progress','add_bookmark','delete_bookmark','add_annotation','delete_annotation','log_download'], true)) {
        $tokenPost = $_POST['csrf'] ?? '';
        if (!hash_equals($csrfToken, $tokenPost)) {
            echo json_encode(['success'=>false,'error'=>'CSRF invalide']); exit;
        }
    }

    switch ($action) {
        // ── Sauvegarder progression ──────────────────────────
        case 'save_progress':
            if (!$pdo) { echo json_encode(['success'=>false,'error'=>'BD inaccessible']); exit; }
            $livreId   = (int)($_POST['livre_id']   ?? 0);
            $page      = (int)($_POST['page']       ?? 1);
            $pct       = min(100, max(0, (float)($_POST['pourcentage'] ?? 0)));
            $tempsLec  = (int)($_POST['temps']      ?? 0);
            if ($livreId < 1) { echo json_encode(['success'=>false,'error'=>'livre_id manquant']); exit; }
            try {
                // lecture_progression
                $st = $pdo->prepare("
                    INSERT INTO lecture_progression (user_id, livre_id, page_actuelle, pourcentage)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE page_actuelle=VALUES(page_actuelle), pourcentage=VALUES(pourcentage), updated_at=NOW()
                ");
                $st->execute([$userId, $livreId, $page, $pct]);
                // reading_history (insert non-unique)
                $sh = $pdo->prepare("
                    INSERT INTO reading_history (user_id, livre_id, page_number, temps_lecture)
                    VALUES (?,?,?,?)
                ");
                $sh->execute([$userId, $livreId, $page, $tempsLec]);
                // nb_lectures incrémenté uniquement au premier enregistrement
                echo json_encode(['success'=>true,'page'=>$page,'pct'=>$pct]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
            }
            exit;

        // ── Charger progression ──────────────────────────────
        case 'get_progress':
            if (!$pdo) { echo json_encode(['success'=>false]); exit; }
            $livreId = (int)($_GET['livre_id'] ?? 0);
            try {
                $st = $pdo->prepare("SELECT page_actuelle, pourcentage FROM lecture_progression WHERE user_id=? AND livre_id=? LIMIT 1");
                $st->execute([$userId, $livreId]);
                $row = $st->fetch();
                echo json_encode(['success'=>true,'page'=>(int)($row['page_actuelle']??1),'pct'=>(float)($row['pourcentage']??0)]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false,'page'=>1,'pct'=>0]);
            }
            exit;

        // ── Ajouter bookmark ─────────────────────────────────
        case 'add_bookmark':
            if (!$pdo) { echo json_encode(['success'=>false,'error'=>'BD inaccessible']); exit; }
            $livreId = (int)($_POST['livre_id'] ?? 0);
            $page    = (int)($_POST['page']     ?? 1);
            $note    = trim($_POST['note']      ?? '');
            if ($livreId < 1 || $page < 1) { echo json_encode(['success'=>false,'error'=>'Données invalides']); exit; }
            try {
                $st = $pdo->prepare("INSERT INTO reading_bookmarks (user_id, livre_id, page_number, note) VALUES (?,?,?,?)");
                $st->execute([$userId, $livreId, $page, $note ?: null]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success'=>true,'id'=>$newId,'page'=>$page,'note'=>$note]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
            }
            exit;

        // ── Supprimer bookmark ────────────────────────────────
        case 'delete_bookmark':
            if (!$pdo) { echo json_encode(['success'=>false]); exit; }
            $bmId = (int)($_POST['bookmark_id'] ?? 0);
            try {
                $st = $pdo->prepare("DELETE FROM reading_bookmarks WHERE id=? AND user_id=?");
                $st->execute([$bmId, $userId]);
                echo json_encode(['success'=>true]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false]);
            }
            exit;

        // ── Charger bookmarks ─────────────────────────────────
        case 'get_bookmarks':
            if (!$pdo) { echo json_encode(['success'=>false,'bookmarks'=>[]]); exit; }
            $livreId = (int)($_GET['livre_id'] ?? 0);
            try {
                $st = $pdo->prepare("SELECT id, page_number, note, created_at FROM reading_bookmarks WHERE user_id=? AND livre_id=? ORDER BY page_number ASC");
                $st->execute([$userId, $livreId]);
                echo json_encode(['success'=>true,'bookmarks'=>$st->fetchAll()]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false,'bookmarks'=>[]]);
            }
            exit;

        // ── Ajouter annotation ────────────────────────────────
        case 'add_annotation':
            if (!$pdo) { echo json_encode(['success'=>false,'error'=>'BD inaccessible']); exit; }
            $livreId = (int)($_POST['livre_id']         ?? 0);
            $page    = (int)($_POST['page']             ?? 1);
            $texte   = trim($_POST['texte_selectionne'] ?? '');
            $annot   = trim($_POST['annotation']        ?? '');
            $couleur = in_array($_POST['couleur'] ?? '', ['#facc15','#34d399','#f87171','#60a5fa','#c084fc'], true)
                       ? $_POST['couleur'] : '#facc15';
            if ($livreId < 1) { echo json_encode(['success'=>false]); exit; }
            try {
                $st = $pdo->prepare("INSERT INTO reading_annotations (user_id, livre_id, page_number, texte_selectionne, annotation, couleur) VALUES (?,?,?,?,?,?)");
                $st->execute([$userId, $livreId, $page, $texte ?: null, $annot ?: null, $couleur]);
                $newId = $pdo->lastInsertId();
                echo json_encode(['success'=>true,'id'=>$newId]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
            }
            exit;

        // ── Supprimer annotation ──────────────────────────────
        case 'delete_annotation':
            if (!$pdo) { echo json_encode(['success'=>false]); exit; }
            $aId = (int)($_POST['annotation_id'] ?? 0);
            try {
                $st = $pdo->prepare("DELETE FROM reading_annotations WHERE id=? AND user_id=?");
                $st->execute([$aId, $userId]);
                echo json_encode(['success'=>true]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false]);
            }
            exit;

        // ── Charger annotations ────────────────────────────────
        case 'get_annotations':
            if (!$pdo) { echo json_encode(['success'=>false,'annotations'=>[]]); exit; }
            $livreId = (int)($_GET['livre_id'] ?? 0);
            try {
                $st = $pdo->prepare("SELECT id, page_number, texte_selectionne, annotation, couleur, created_at FROM reading_annotations WHERE user_id=? AND livre_id=? ORDER BY page_number ASC, created_at DESC");
                $st->execute([$userId, $livreId]);
                echo json_encode(['success'=>true,'annotations'=>$st->fetchAll()]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false,'annotations'=>[]]);
            }
            exit;

        // ── Logger téléchargement ─────────────────────────────
        case 'log_download':
            if (!$pdo) { echo json_encode(['success'=>false]); exit; }
            $livreId = (int)($_POST['livre_id'] ?? 0);
            // Vérifier limite
            try {
                $maxDl = 3;
                $set = $pdo->query("SELECT `value` FROM settings WHERE `key`='max_downloads' LIMIT 1")->fetchColumn();
                if ($set) $maxDl = (int)$set;
                $st = $pdo->prepare("SELECT count FROM user_downloads WHERE user_id=? AND livre_id=?");
                $st->execute([$userId, $livreId]);
                $dlRow = $st->fetch();
                if ($dlRow && (int)$dlRow['count'] >= $maxDl) {
                    echo json_encode(['success'=>false,'error'=>"Limite de {$maxDl} téléchargements atteinte."]);
                    exit;
                }
                $pdo->prepare("
                    INSERT INTO user_downloads (user_id, livre_id, count) VALUES (?,?,1)
                    ON DUPLICATE KEY UPDATE count=count+1, last_dl_at=NOW()
                ")->execute([$userId, $livreId]);
                // Notification
                $pdo->prepare("
                    INSERT INTO notifications (user_id, type, message) VALUES (?,?,?)
                ")->execute([$userId, 'download', 'Vous avez téléchargé un livre.']);
                echo json_encode(['success'=>true]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
            }
            exit;

        // ── Stats personnelles ────────────────────────────────
        case 'get_stats':
            if (!$pdo) { echo json_encode(['success'=>false]); exit; }
            $livreId = (int)($_GET['livre_id'] ?? 0);
            try {
                $prog = $pdo->prepare("SELECT page_actuelle, pourcentage FROM lecture_progression WHERE user_id=? AND livre_id=? LIMIT 1");
                $prog->execute([$userId, $livreId]);
                $progRow = $prog->fetch();
                $totalBm = $pdo->prepare("SELECT COUNT(*) FROM reading_bookmarks WHERE user_id=? AND livre_id=?");
                $totalBm->execute([$userId, $livreId]);
                $totalAn = $pdo->prepare("SELECT COUNT(*) FROM reading_annotations WHERE user_id=? AND livre_id=?");
                $totalAn->execute([$userId, $livreId]);
                $totalDl = $pdo->prepare("SELECT COALESCE(SUM(count),0) FROM user_downloads WHERE user_id=? AND livre_id=?");
                $totalDl->execute([$userId, $livreId]);
                $totalTime = $pdo->prepare("SELECT COALESCE(SUM(temps_lecture),0) FROM reading_history WHERE user_id=? AND livre_id=?");
                $totalTime->execute([$userId, $livreId]);
                echo json_encode([
                    'success'    => true,
                    'page'       => (int)($progRow['page_actuelle'] ?? 1),
                    'pct'        => round((float)($progRow['pourcentage'] ?? 0), 1),
                    'bookmarks'  => (int)$totalBm->fetchColumn(),
                    'annotations'=> (int)$totalAn->fetchColumn(),
                    'downloads'  => (int)$totalDl->fetchColumn(),
                    'temps'      => (int)$totalTime->fetchColumn(),
                ]);
            } catch (Exception $e) {
                echo json_encode(['success'=>false]);
            }
            exit;

        default:
            echo json_encode(['success'=>false,'error'=>'Action inconnue']);
            exit;
    }
}

// ── Récupérer le livre ────────────────────────────────────────
$livreId = (int)($_GET['id'] ?? 0);
$livre = null;
$hasAccess = false;
$accessError = '';
$progression = ['page_actuelle' => 1, 'pourcentage' => 0];
$bookmarks = [];
$annotations = [];
$recommendations = [];
$downloadCount = 0;

if ($livreId < 1) {
    $accessError = 'Aucun livre sélectionné.';
} elseif (!$pdo) {
    $accessError = 'Base de données inaccessible.';
} else {
    try {
        // Récupérer livre complet
        $st = $pdo->prepare("
            SELECT l.*, c.nom AS categorie_nom, c.icone AS categorie_icone
            FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            WHERE l.id = ? AND l.statut = 'disponible'
            LIMIT 1
        ");
        $st->execute([$livreId]);
        $livre = $st->fetch();

        if (!$livre) {
            $accessError = 'Ce livre est introuvable ou non disponible.';
        } else {
            $accessType = $livre['access_type'] ?? 'standard';

            // Vérification accès
            if ($accessType === 'gratuit') {
                $hasAccess = true;
            } elseif ($userRole === 'admin' || $userRole === 'journaliste') {
                $hasAccess = true;
            } else {
                // Vérifier achat
                $ac = $pdo->prepare("
                    SELECT id FROM achats
                    WHERE user_id=? AND livre_id=? AND statut='confirme'
                    LIMIT 1
                ");
                $ac->execute([$userId, $livreId]);
                if ($ac->fetch()) {
                    $hasAccess = true;
                } else {
                    // Vérifier bonus
                    $bn = $pdo->prepare("SELECT bonus_restant FROM user_bonus WHERE user_id=? AND bonus_restant>0");
                    $bn->execute([$userId]);
                    $hasAccess = (bool)$bn->fetch();
                    if (!$hasAccess) {
                        $accessError = 'premium_required';
                    }
                }
            }

            if ($hasAccess) {
                // Progression
                $pr = $pdo->prepare("SELECT page_actuelle, pourcentage FROM lecture_progression WHERE user_id=? AND livre_id=? LIMIT 1");
                $pr->execute([$userId, $livreId]);
                $pRow = $pr->fetch();
                if ($pRow) $progression = $pRow;

                // Bookmarks
                $bm = $pdo->prepare("SELECT id, page_number, note, created_at FROM reading_bookmarks WHERE user_id=? AND livre_id=? ORDER BY page_number ASC");
                $bm->execute([$userId, $livreId]);
                $bookmarks = $bm->fetchAll();

                // Annotations
                $an = $pdo->prepare("SELECT id, page_number, texte_selectionne, annotation, couleur, created_at FROM reading_annotations WHERE user_id=? AND livre_id=? ORDER BY page_number ASC, id DESC");
                $an->execute([$userId, $livreId]);
                $annotations = $an->fetchAll();

                // Téléchargements
                $dl = $pdo->prepare("SELECT COALESCE(SUM(count),0) FROM user_downloads WHERE user_id=? AND livre_id=?");
                $dl->execute([$userId, $livreId]);
                $downloadCount = (int)$dl->fetchColumn();

                // Incrémenter nb_lectures (une fois par session)
                $sessKey = 'read_' . $livreId;
                if (empty($_SESSION[$sessKey])) {
                    $_SESSION[$sessKey] = true;
                    $pdo->prepare("UPDATE livres SET nb_lectures=nb_lectures+1 WHERE id=?")->execute([$livreId]);
                }
            }
        }

        // Recommandations (même catégorie)
        if ($livre) {
            $rec = $pdo->prepare("
                SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.access_type,
                       c.nom AS categorie_nom, c.icone
                FROM livres l
                LEFT JOIN categories c ON c.id=l.categorie_id
                WHERE l.statut='disponible' AND l.categorie_id=? AND l.id!=?
                ORDER BY l.note_moyenne DESC LIMIT 4
            ");
            $rec->execute([$livre['categorie_id'] ?? 0, $livreId]);
            $recommendations = $rec->fetchAll();
        }

    } catch (Exception $e) {
        error_log('[DLS Reader] ' . $e->getMessage());
        $accessError = 'Erreur lors du chargement du livre.';
    }
}

// ── Max downloads ─────────────────────────────────────────────
$maxDownloads = 3;
if ($pdo) {
    try {
        $mdRow = $pdo->query("SELECT `value` FROM settings WHERE `key`='max_downloads' LIMIT 1")->fetchColumn();
        if ($mdRow) $maxDownloads = (int)$mdRow;
    } catch (Exception $e) {}
}

$livreJson     = $livre       ? json_encode($livre,       JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) : 'null';
$bookmarksJson = json_encode($bookmarks,    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
$annotationsJson = json_encode($annotations, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
$progJson      = json_encode($progression,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT);
$recsJson      = json_encode($recommendations, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);

?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $livre ? htmlspecialchars($livre['titre'], ENT_QUOTES, 'UTF-8') . ' — Lecteur Digital Library' : 'Lecteur Digital Library' ?></title>
<meta name="description" content="Lecteur de livres numériques premium">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Syne:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- PDF.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf_viewer.min.css">

<style>
/* ══════════════════════════════════════════════════════════
   VARIABLES & RESET
══════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  /* Dark theme */
  --bg:          #0c0f1a;
  --bg2:         #111526;
  --bg3:         #161b2e;
  --surface:     rgba(255,255,255,.035);
  --surface2:    rgba(255,255,255,.06);
  --border:      rgba(255,255,255,.07);
  --border2:     rgba(255,255,255,.13);

  --gold:        #e8c56c;
  --gold2:       #f0d080;
  --cyan:        #4dd8e8;
  --violet:      #8b5cf6;
  --green:       #34d399;
  --rose:        #fb7185;
  --amber:       #fbbf24;

  --text1:       #eef2f8;
  --text2:       rgba(238,242,248,.65);
  --text3:       rgba(238,242,248,.35);

  --topbar-h:    54px;
  --sidebar-w:   300px;
  --reader-max:  780px;

  --r-sm: 6px;
  --r-md: 10px;
  --r-lg: 16px;
  --r-xl: 22px;

  --shadow1: 0 2px 12px rgba(0,0,0,.3);
  --shadow2: 0 8px 40px rgba(0,0,0,.5);
  --shadow3: 0 24px 80px rgba(0,0,0,.65);

  --font-reading: 'Lora', Georgia, serif;
  --font-ui:      'Syne', sans-serif;
  --font-mono:    'Space Mono', monospace;

  --page-bg:     #1a1d2e;
  --page-text:   #dde4f0;
  --reading-size: 18px;
  --reading-lh:  1.85;
}

[data-theme="sepia"] {
  --bg:      #1a1410;
  --bg2:     #211c16;
  --bg3:     #2a231c;
  --surface: rgba(255,240,210,.04);
  --border:  rgba(255,240,210,.08);
  --page-bg: #1e190f;
  --page-text: #c8b89a;
  --text1:   #e8d5b0;
  --text2:   rgba(232,213,176,.65);
  --text3:   rgba(232,213,176,.35);
}

[data-theme="light"] {
  --bg:      #f5f5f5;
  --bg2:     #ececec;
  --bg3:     #e2e2e2;
  --surface: rgba(0,0,0,.04);
  --border:  rgba(0,0,0,.1);
  --page-bg: #fff;
  --page-text: #1a1a1a;
  --text1:   #1a1a1a;
  --text2:   rgba(26,26,26,.65);
  --text3:   rgba(26,26,26,.35);
}

html, body { height: 100%; overflow: hidden; font-family: var(--font-ui); background: var(--bg); color: var(--text1); }

::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--gold); }

/* ══════════════════════════════════════════════════════════
   LAYOUT
══════════════════════════════════════════════════════════ */
#app {
  display: grid;
  grid-template-rows: var(--topbar-h) 1fr;
  grid-template-columns: 1fr;
  height: 100vh;
  overflow: hidden;
}

#app.panel-open {
  grid-template-columns: 1fr var(--sidebar-w);
}

/* ══════════════════════════════════════════════════════════
   TOPBAR
══════════════════════════════════════════════════════════ */
#topbar {
  grid-column: 1 / -1;
  height: var(--topbar-h);
  background: rgba(12,15,26,.92);
  backdrop-filter: blur(24px);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: 0 1rem;
  z-index: 100;
  position: relative;
}

.tb-brand {
  display: flex;
  align-items: center;
  gap: .5rem;
  text-decoration: none;
  color: var(--text1);
  font-family: var(--font-ui);
  font-weight: 700;
  font-size: .82rem;
  flex-shrink: 0;
}

.brand-dot {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  background: linear-gradient(135deg, var(--gold), var(--violet));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .9rem;
}

.tb-sep { color: var(--text3); }

.tb-title {
  font-family: var(--font-ui);
  font-weight: 700;
  font-size: .88rem;
  max-width: 260px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text1);
}

.tb-spacer { flex: 1; }

/* Progress bar dans topbar */
.tb-progress {
  display: flex;
  align-items: center;
  gap: .6rem;
  font-size: .7rem;
  color: var(--text3);
  font-family: var(--font-mono);
}

.tb-prog-bar {
  width: 100px;
  height: 3px;
  background: var(--border2);
  border-radius: 100px;
  overflow: hidden;
}

.tb-prog-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--gold), var(--cyan));
  border-radius: 100px;
  transition: width .8s ease;
}

.tb-pct { color: var(--gold); font-weight: 700; min-width: 32px; text-align: right; }

/* Buttons topbar */
.tb-btn {
  width: 34px;
  height: 34px;
  border-radius: var(--r-sm);
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text2);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: .9rem;
  transition: all .15s;
  text-decoration: none;
  position: relative;
  flex-shrink: 0;
}

.tb-btn:hover, .tb-btn.active {
  background: var(--surface2);
  border-color: var(--border2);
  color: var(--text1);
}

.tb-btn.active { color: var(--gold); border-color: rgba(232,197,108,.3); }

.tb-btn-label {
  display: flex;
  align-items: center;
  gap: .4rem;
  padding: 0 .7rem;
  height: 34px;
  font-size: .74rem;
  font-family: var(--font-mono);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  color: var(--text2);
  cursor: pointer;
  transition: all .15s;
  text-decoration: none;
  white-space: nowrap;
  flex-shrink: 0;
}

.tb-btn-label:hover { background: var(--surface2); color: var(--text1); }

/* Page nav */
.page-nav {
  display: flex;
  align-items: center;
  gap: .35rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 2px;
}

.pn-btn {
  width: 28px;
  height: 28px;
  border-radius: var(--r-sm);
  background: none;
  border: none;
  color: var(--text2);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: .82rem;
  transition: all .15s;
}

.pn-btn:hover:not(:disabled) { color: var(--gold); background: var(--surface2); }
.pn-btn:disabled { opacity: .3; cursor: default; }

.pn-input {
  width: 42px;
  height: 28px;
  background: none;
  border: none;
  outline: none;
  text-align: center;
  font-family: var(--font-mono);
  font-size: .74rem;
  color: var(--text1);
}

.pn-total {
  font-family: var(--font-mono);
  font-size: .7rem;
  color: var(--text3);
  padding-right: 4px;
}

/* Theme switcher */
.theme-btns {
  display: flex;
  align-items: center;
  gap: 2px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 2px;
}

.theme-btn {
  width: 26px;
  height: 26px;
  border-radius: 4px;
  background: none;
  border: none;
  cursor: pointer;
  font-size: .78rem;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all .15s;
  color: var(--text3);
}

.theme-btn:hover, .theme-btn.active { background: var(--surface2); color: var(--text1); }
.theme-btn.active { color: var(--gold); }

/* Zoom */
.zoom-ctrl {
  display: flex;
  align-items: center;
  gap: 2px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 2px;
}

.zoom-btn {
  width: 26px; height: 26px; border-radius: 4px; background: none; border: none;
  cursor: pointer; font-size: .78rem; display: flex; align-items: center; justify-content: center;
  transition: background .15s; color: var(--text2);
}

.zoom-btn:hover { background: var(--surface2); color: var(--text1); }

.zoom-val {
  font-family: var(--font-mono);
  font-size: .65rem;
  color: var(--text2);
  min-width: 34px;
  text-align: center;
}

/* ══════════════════════════════════════════════════════════
   MAIN READER AREA
══════════════════════════════════════════════════════════ */
#reader-area {
  grid-row: 2;
  display: flex;
  overflow: hidden;
  position: relative;
}

/* ── PDF Viewer ── */
#pdf-container {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  background: var(--bg);
  position: relative;
  scroll-behavior: smooth;
}

#pdf-viewer {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 1rem 6rem;
  gap: 1.5rem;
  min-height: 100%;
}

.pdf-page-wrap {
  position: relative;
  box-shadow: var(--shadow3);
  border-radius: var(--r-md);
  overflow: hidden;
  transform-origin: top center;
  transition: transform .2s;
  cursor: pointer;
}

.pdf-page-wrap:hover .page-overlay-btns { opacity: 1; }

.page-overlay-btns {
  position: absolute;
  top: .5rem;
  right: .5rem;
  display: flex;
  gap: .25rem;
  opacity: 0;
  transition: opacity .2s;
  z-index: 10;
}

.page-mini-btn {
  width: 28px; height: 28px;
  background: rgba(12,15,26,.85);
  backdrop-filter: blur(8px);
  border: 1px solid var(--border2);
  border-radius: 6px;
  color: var(--text2);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: .72rem;
  transition: all .15s;
}

.page-mini-btn:hover { color: var(--gold); border-color: rgba(232,197,108,.4); }

canvas { display: block; }

/* ── Text reader (si pas de PDF) ── */
#text-reader {
  display: none;
  flex: 1;
  overflow-y: auto;
  background: var(--bg);
}

#text-content {
  max-width: var(--reader-max);
  margin: 0 auto;
  padding: 3rem 2rem 8rem;
  font-family: var(--font-reading);
  font-size: var(--reading-size);
  line-height: var(--reading-lh);
  color: var(--page-text);
}

#text-content h1, #text-content h2 {
  font-family: var(--font-ui);
  font-weight: 700;
  color: var(--text1);
  margin: 2.5rem 0 1rem;
}

#text-content p { margin-bottom: 1.4em; }
#text-content strong { color: var(--text1); }
#text-content em { color: var(--gold); font-style: italic; }

.chapter-sep {
  text-align: center;
  margin: 3rem auto;
  color: var(--gold);
  opacity: .4;
  font-size: 1.2rem;
  letter-spacing: .8em;
}

/* Highlight layers */
.highlight {
  border-radius: 2px;
  cursor: pointer;
  padding: 1px 0;
}

/* ══════════════════════════════════════════════════════════
   SIDEBAR PANEL
══════════════════════════════════════════════════════════ */
#side-panel {
  width: var(--sidebar-w);
  background: var(--bg2);
  border-left: 1px solid var(--border);
  display: none;
  flex-direction: column;
  overflow: hidden;
}

#app.panel-open #side-panel { display: flex; }

.panel-tabs {
  display: flex;
  border-bottom: 1px solid var(--border);
  background: var(--bg3);
  flex-shrink: 0;
}

.ptab {
  flex: 1;
  padding: .7rem .3rem;
  text-align: center;
  cursor: pointer;
  font-size: .62rem;
  font-family: var(--font-mono);
  letter-spacing: .04em;
  text-transform: uppercase;
  color: var(--text3);
  border-bottom: 2px solid transparent;
  transition: all .15s;
}

.ptab:hover { color: var(--text2); }
.ptab.active { color: var(--gold); border-bottom-color: var(--gold); }

.panel-body {
  flex: 1;
  overflow-y: auto;
  padding: .8rem;
}

.panel-section { display: none; }
.panel-section.active { display: block; }

/* Panel elements */
.panel-item {
  display: flex;
  align-items: flex-start;
  gap: .6rem;
  padding: .65rem .7rem;
  border-radius: var(--r-md);
  background: var(--surface);
  border: 1px solid var(--border);
  margin-bottom: .45rem;
  cursor: pointer;
  transition: all .15s;
}

.panel-item:hover { background: var(--surface2); border-color: var(--border2); }

.pi-icon {
  width: 30px; height: 30px;
  border-radius: 8px;
  background: rgba(232,197,108,.1);
  color: var(--gold);
  display: flex; align-items: center; justify-content: center;
  font-size: .82rem;
  flex-shrink: 0;
}

.pi-page {
  font-family: var(--font-mono);
  font-size: .62rem;
  color: var(--gold);
  font-weight: 700;
}

.pi-note {
  font-size: .74rem;
  color: var(--text2);
  margin-top: 2px;
  line-height: 1.4;
}

.pi-date {
  font-size: .6rem;
  color: var(--text3);
  font-family: var(--font-mono);
  margin-top: 2px;
}

.pi-delete {
  margin-left: auto;
  width: 22px; height: 22px;
  border-radius: 5px;
  background: none;
  border: none;
  color: var(--text3);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem;
  transition: all .15s;
  flex-shrink: 0;
}

.pi-delete:hover { color: var(--rose); background: rgba(251,113,133,.1); }

/* Annotation item */
.annot-item {
  padding: .65rem .7rem;
  border-radius: var(--r-md);
  border-left: 3px solid var(--gold);
  background: var(--surface);
  margin-bottom: .45rem;
  cursor: pointer;
  transition: background .15s;
}

.annot-item:hover { background: var(--surface2); }

.annot-highlight {
  font-size: .72rem;
  font-style: italic;
  color: var(--text3);
  margin-bottom: 4px;
  line-height: 1.4;
}

.annot-text {
  font-size: .75rem;
  color: var(--text2);
  line-height: 1.5;
}

.annot-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 5px;
}

.annot-page {
  font-family: var(--font-mono);
  font-size: .6rem;
  color: var(--text3);
}

/* Stats panel */
.stat-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .6rem .7rem;
  border-radius: var(--r-md);
  background: var(--surface);
  border: 1px solid var(--border);
  margin-bottom: .35rem;
}

.stat-row-label {
  font-size: .74rem;
  color: var(--text2);
  display: flex;
  align-items: center;
  gap: .4rem;
}

.stat-row-val {
  font-family: var(--font-mono);
  font-size: .78rem;
  font-weight: 700;
  color: var(--gold);
}

/* Progress ring */
.prog-ring-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.2rem 0 .8rem;
}

.prog-ring {
  transform: rotate(-90deg);
}

.prog-ring-bg {
  fill: none;
  stroke: var(--border2);
  stroke-width: 5;
}

.prog-ring-fill {
  fill: none;
  stroke: url(#ringGrad);
  stroke-width: 5;
  stroke-linecap: round;
  transition: stroke-dashoffset 1s ease;
}

.prog-ring-label {
  font-family: var(--font-ui);
  font-size: 1.5rem;
  font-weight: 800;
  fill: var(--gold);
}

.prog-ring-sub {
  font-family: var(--font-mono);
  font-size: .48rem;
  fill: var(--text3);
}

/* Recommendations */
.rec-item {
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: .6rem;
  border-radius: var(--r-md);
  border: 1px solid var(--border);
  background: var(--surface);
  margin-bottom: .4rem;
  text-decoration: none;
  color: var(--text1);
  transition: all .15s;
}

.rec-item:hover { background: var(--surface2); border-color: var(--border2); }

.rec-cover {
  width: 36px; height: 50px;
  border-radius: 4px;
  background: linear-gradient(135deg, var(--bg3), rgba(139,92,246,.3));
  display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; flex-shrink: 0;
}

.rec-info .rec-title {
  font-weight: 700;
  font-size: .76rem;
  line-height: 1.3;
}

.rec-info .rec-author {
  font-size: .65rem;
  color: var(--text3);
  margin-top: 2px;
}

.rec-info .rec-price {
  font-family: var(--font-mono);
  font-size: .65rem;
  color: var(--gold);
  margin-top: 4px;
}

/* Panel add forms */
.panel-add-btn {
  width: 100%;
  padding: .55rem;
  background: linear-gradient(135deg, rgba(232,197,108,.12), rgba(139,92,246,.12));
  border: 1px dashed rgba(232,197,108,.3);
  border-radius: var(--r-md);
  color: var(--gold);
  font-size: .75rem;
  font-family: var(--font-mono);
  cursor: pointer;
  transition: all .15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .4rem;
  margin-bottom: .6rem;
}

.panel-add-btn:hover { background: rgba(232,197,108,.18); border-color: rgba(232,197,108,.5); }

.panel-form {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  padding: .7rem;
  margin-bottom: .6rem;
  display: none;
  animation: fadeIn .2s ease;
}

.panel-form.open { display: block; }

.pf-label {
  font-size: .62rem;
  font-family: var(--font-mono);
  color: var(--text3);
  text-transform: uppercase;
  letter-spacing: .05em;
  display: block;
  margin-bottom: 4px;
}

.pf-input, .pf-textarea {
  width: 100%;
  background: var(--bg3);
  border: 1px solid var(--border2);
  border-radius: var(--r-sm);
  padding: .45rem .6rem;
  color: var(--text1);
  font-size: .78rem;
  font-family: var(--font-reading);
  outline: none;
  transition: border-color .15s;
  margin-bottom: .5rem;
}

.pf-input:focus, .pf-textarea:focus { border-color: rgba(232,197,108,.5); }
.pf-textarea { min-height: 70px; resize: vertical; }

.color-pickers {
  display: flex;
  gap: .35rem;
  margin-bottom: .5rem;
}

.color-pick {
  width: 24px; height: 24px;
  border-radius: 50%;
  cursor: pointer;
  border: 2px solid transparent;
  transition: border-color .15s;
}

.color-pick.selected { border-color: white; }

.pf-actions {
  display: flex;
  gap: .35rem;
}

.btn-sm {
  padding: .35rem .65rem;
  border-radius: var(--r-sm);
  font-size: .7rem;
  font-family: var(--font-mono);
  cursor: pointer;
  border: none;
  transition: all .15s;
}

.btn-gold {
  background: linear-gradient(135deg, var(--gold), #c4a245);
  color: #1a1000;
  font-weight: 700;
}

.btn-gold:hover { opacity: .85; }

.btn-ghost {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text2);
}

.btn-ghost:hover { color: var(--text1); background: var(--surface2); }

/* ══════════════════════════════════════════════════════════
   LOADING OVERLAY
══════════════════════════════════════════════════════════ */
#pdf-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  padding: 4rem 2rem;
  color: var(--text3);
}

.loader-ring {
  width: 48px;
  height: 48px;
  border: 3px solid var(--border2);
  border-top-color: var(--gold);
  border-radius: 50%;
  animation: spin .9s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }
@keyframes fadeIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }

/* ══════════════════════════════════════════════════════════
   MODALS & OVERLAYS
══════════════════════════════════════════════════════════ */
.modal-bg {
  position: fixed;
  inset: 0;
  background: rgba(12,15,26,.85);
  backdrop-filter: blur(14px);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  opacity: 0;
  pointer-events: none;
  transition: opacity .25s;
}

.modal-bg.open {
  opacity: 1;
  pointer-events: all;
}

.modal {
  background: var(--bg2);
  border: 1px solid var(--border2);
  border-radius: var(--r-xl);
  padding: 1.8rem;
  max-width: 440px;
  width: 100%;
  box-shadow: var(--shadow3);
  transform: translateY(16px) scale(.97);
  transition: transform .3s cubic-bezier(.34,1.56,.64,1);
}

.modal-bg.open .modal {
  transform: none;
}

.modal::before {
  content: '';
  display: block;
  height: 2px;
  margin: -1.8rem -1.8rem 1.5rem;
  border-radius: var(--r-xl) var(--r-xl) 0 0;
  background: linear-gradient(90deg, var(--gold), var(--cyan), var(--violet));
}

.modal-title {
  font-family: var(--font-ui);
  font-weight: 800;
  font-size: 1.15rem;
  margin-bottom: .4rem;
}

.modal-sub {
  font-size: .78rem;
  color: var(--text2);
  margin-bottom: 1.2rem;
}

/* Premium gate */
.premium-gate {
  text-align: center;
  padding: 1rem 0;
}

.premium-icon {
  font-size: 3rem;
  margin-bottom: .8rem;
  display: block;
}

.premium-price {
  font-family: var(--font-ui);
  font-size: 2rem;
  font-weight: 800;
  color: var(--gold);
  margin: .8rem 0;
}

.premium-price span {
  font-size: .85rem;
  color: var(--text3);
  font-family: var(--font-mono);
}

/* ══════════════════════════════════════════════════════════
   TOASTS
══════════════════════════════════════════════════════════ */
#toast-stack {
  position: fixed;
  bottom: 1.5rem;
  right: 1.5rem;
  z-index: 9999;
  display: flex;
  flex-direction: column-reverse;
  gap: .5rem;
  pointer-events: none;
}

.toast {
  display: flex;
  align-items: center;
  gap: .6rem;
  padding: .7rem 1rem;
  background: var(--bg2);
  border: 1px solid var(--border2);
  border-radius: var(--r-md);
  box-shadow: var(--shadow2);
  font-size: .78rem;
  max-width: 280px;
  pointer-events: all;
  transform: translateX(110px);
  opacity: 0;
  transition: all .3s cubic-bezier(.34,1.56,.64,1);
}

.toast.show { transform: none; opacity: 1; }
.toast-icon { font-size: 1rem; flex-shrink: 0; }
.toast-title { font-weight: 700; font-family: var(--font-ui); }
.toast-sub { font-size: .68rem; color: var(--text3); }

/* ══════════════════════════════════════════════════════════
   FLOATING PAGE INDICATOR
══════════════════════════════════════════════════════════ */
#page-indicator {
  position: fixed;
  bottom: 1.5rem;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(12,15,26,.9);
  backdrop-filter: blur(12px);
  border: 1px solid var(--border2);
  border-radius: 100px;
  padding: .4rem 1rem;
  font-family: var(--font-mono);
  font-size: .7rem;
  color: var(--text2);
  z-index: 50;
  opacity: 0;
  transition: opacity .4s;
  pointer-events: none;
}

#page-indicator.visible { opacity: 1; }

/* ══════════════════════════════════════════════════════════
   FULLSCREEN
══════════════════════════════════════════════════════════ */
#app.fullscreen #topbar { display: none; }
#app.fullscreen { grid-template-rows: 1fr; }
#app.fullscreen #pdf-container { border-radius: 0; }

/* ══════════════════════════════════════════════════════════
   SEARCH HIGHLIGHT
══════════════════════════════════════════════════════════ */
.search-overlay {
  position: fixed;
  top: var(--topbar-h);
  left: 0;
  right: 0;
  background: rgba(12,15,26,.95);
  backdrop-filter: blur(20px);
  padding: 1rem;
  z-index: 200;
  display: none;
  border-bottom: 1px solid var(--border);
}

.search-overlay.open { display: flex; align-items: center; gap: .7rem; }

.search-input {
  flex: 1;
  background: var(--surface);
  border: 1px solid var(--border2);
  border-radius: var(--r-md);
  padding: .6rem 1rem;
  color: var(--text1);
  font-family: var(--font-reading);
  font-size: .9rem;
  outline: none;
}

.search-input:focus { border-color: rgba(232,197,108,.5); }

.search-count {
  font-family: var(--font-mono);
  font-size: .7rem;
  color: var(--text3);
  white-space: nowrap;
}

/* ══════════════════════════════════════════════════════════
   THUMBNAILS STRIP
══════════════════════════════════════════════════════════ */
#thumb-strip {
  display: none;
  width: 86px;
  flex-shrink: 0;
  background: var(--bg3);
  border-right: 1px solid var(--border);
  overflow-y: auto;
  padding: .5rem;
  gap: .4rem;
  flex-direction: column;
  align-items: center;
}

#app.thumbs-open #thumb-strip { display: flex; }

.thumb-item {
  width: 64px;
  border: 2px solid transparent;
  border-radius: 4px;
  cursor: pointer;
  overflow: hidden;
  transition: border-color .15s;
  flex-shrink: 0;
}

.thumb-item canvas { width: 100%; height: auto; display: block; }

.thumb-item.active { border-color: var(--gold); }

.thumb-num {
  text-align: center;
  font-family: var(--font-mono);
  font-size: .52rem;
  color: var(--text3);
  padding: 2px 0;
}

/* ══════════════════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
  :root { --sidebar-w: 100vw; }
  #app.panel-open { grid-template-columns: 0 1fr; }
  #app.panel-open #pdf-container { display: none; }
  .tb-title { max-width: 120px; font-size: .8rem; }
  .zoom-ctrl { display: none; }
  .theme-btns { display: none; }
  #thumb-strip { display: none !important; }
}

@media (max-width: 480px) {
  .page-nav { display: none; }
  .tb-btn-label span { display: none; }
}
</style>
</head>
<body>

<?php if ($accessError && $accessError !== 'premium_required'): ?>
<!-- ═══ ERREUR ═══ -->
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:2rem">
  <div style="text-align:center;max-width:400px">
    <div style="font-size:4rem;margin-bottom:1rem">📚</div>
    <h1 style="font-family:var(--font-ui);font-weight:800;font-size:1.4rem;margin-bottom:.5rem">Livre introuvable</h1>
    <p style="color:var(--text2);font-size:.88rem;margin-bottom:1.5rem"><?= htmlspecialchars($accessError, ENT_QUOTES, 'UTF-8') ?></p>
    <a href="../../dashboard.php" style="display:inline-flex;align-items:center;gap:.5rem;padding:.65rem 1.2rem;background:linear-gradient(135deg,var(--gold),#c4a245);color:#1a1000;border-radius:var(--r-md);text-decoration:none;font-family:var(--font-ui);font-weight:700;font-size:.82rem">
      <i class="bi bi-arrow-left"></i> Retour au catalogue
    </a>
  </div>
</div>

<?php elseif ($accessError === 'premium_required' && $livre): ?>
<!-- ═══ PREMIUM GATE ═══ -->
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:2rem;position:relative;overflow:hidden">
  <div style="position:absolute;inset:0;background:radial-gradient(ellipse at 50% 40%,rgba(139,92,246,.15) 0%,transparent 70%);pointer-events:none"></div>
  <div class="modal" style="max-width:460px;position:relative">
    <div style="text-align:center">
      <div class="premium-icon">🔒</div>
      <h2 class="modal-title">Contenu Premium</h2>
      <p class="modal-sub">"<?= htmlspecialchars($livre['titre'], ENT_QUOTES, 'UTF-8') ?>" est un livre <?= htmlspecialchars($livre['access_type'] ?? 'premium', ENT_QUOTES, 'UTF-8') ?>. Achetez-le pour y accéder.</p>

      <?php $price = (float)($livre['prix'] ?? 0); ?>
      <div class="premium-price"><?= number_format($price, 0, ',', ' ') ?> <span>FCFA</span></div>

      <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--r-md);padding:.8rem;margin:1rem 0;text-align:left">
        <div style="display:flex;align-items:center;gap:.5rem;font-size:.78rem;color:var(--text2);margin-bottom:.35rem"><i class="bi bi-check2-circle" style="color:var(--green)"></i> Accès illimité à vie</div>
        <div style="display:flex;align-items:center;gap:.5rem;font-size:.78rem;color:var(--text2);margin-bottom:.35rem"><i class="bi bi-check2-circle" style="color:var(--green)"></i> Téléchargement inclus (<?= $maxDownloads ?>x)</div>
        <div style="display:flex;align-items:center;gap:.5rem;font-size:.78rem;color:var(--text2)"><i class="bi bi-check2-circle" style="color:var(--green)"></i> Bookmarks & annotations</div>
      </div>
        <a href="dashboard.php" style="display:flex;align-items:center;justify-content:center;gap:.5rem;padding:.7rem;background:var(--surface);border:1px solid var(--border);color:var(--text2);border-radius:var(--r-md);text-decoration:none;font-family:var(--font-mono);font-size:.78rem">
          <i class="bi bi-arrow-left"></i> Retour au catalogue
        </a>
      </div>
    </div>
  </div>
</div>

<?php else: /* ═══ READER ═══ */ ?>

<div id="app">

  <!-- ═══ TOPBAR ═══ -->
  <header id="topbar">
    <a href="my_library.php" class="tb-btn" title="Retour au catalogue"><i class="bi bi-arrow-left"></i></a>
    <a href="my_library.php" class="tb-brand">
      <div class="brand-dot">📚</div>
    </a>
    <span class="tb-sep">/</span>
    <span class="tb-title"><?= htmlspecialchars($livre['titre'] ?? 'Lecture', ENT_QUOTES, 'UTF-8') ?></span>

    <div class="tb-spacer"></div>

    <!-- Progress -->
    <div class="tb-progress" id="tb-prog-wrap">
      <div class="tb-prog-bar">
        <div class="tb-prog-fill" id="tb-prog-fill" style="width:<?= (float)($progression['pourcentage'] ?? 0) ?>%"></div>
      </div>
      <span class="tb-pct" id="tb-pct"><?= round((float)($progression['pourcentage'] ?? 0)) ?>%</span>
    </div>

    <!-- Page navigation -->
    <div class="page-nav">
      <button class="pn-btn" id="btn-prev" title="Page précédente (←)"><i class="bi bi-chevron-left"></i></button>
      <input class="pn-input" type="number" id="page-input" value="<?= (int)($progression['page_actuelle'] ?? 1) ?>" min="1">
      <span class="pn-total">/ <span id="total-pages">—</span></span>
      <button class="pn-btn" id="btn-next" title="Page suivante (→)"><i class="bi bi-chevron-right"></i></button>
    </div>

    <!-- Zoom -->
    <div class="zoom-ctrl" title="Zoom">
      <button class="zoom-btn" id="zoom-out" title="Dézoomer (-)"><i class="bi bi-dash"></i></button>
      <span class="zoom-val" id="zoom-val">100%</span>
      <button class="zoom-btn" id="zoom-in" title="Zoomer (+)"><i class="bi bi-plus"></i></button>
    </div>

    <!-- Themes -->
    <div class="theme-btns" title="Thème de lecture">
      <button class="theme-btn active" data-theme="dark" title="Sombre">🌙</button>
      <button class="theme-btn" data-theme="sepia" title="Sépia">📜</button>
      <button class="theme-btn" data-theme="light" title="Clair">☀️</button>
    </div>

    <!-- Font size -->
    <div class="zoom-ctrl" title="Taille de police">
      <button class="zoom-btn" id="font-down" title="Réduire texte">A-</button>
      <button class="zoom-btn" id="font-up" title="Agrandir texte">A+</button>
    </div>

    <!-- Thumbnails -->
    <button class="tb-btn" id="btn-thumbs" title="Miniatures des pages"><i class="bi bi-grid-3x3"></i></button>

    <!-- Search -->
    <button class="tb-btn" id="btn-search" title="Rechercher dans le livre (Ctrl+F)"><i class="bi bi-search"></i></button>

    <!-- Download (si autorisé) -->
    <?php if ($livre && !empty($livre['fichier_pdf'])): ?>
    <button class="tb-btn-label" id="btn-download" title="Télécharger">
      <i class="bi bi-download"></i>
      <span><?= $downloadCount ?>/<?= $maxDownloads ?></span>
    </button>
    <?php endif; ?>

    <!-- Sidebar tools -->
    <button class="tb-btn" id="btn-bm" title="Signets" data-panel="bookmarks"><i class="bi bi-bookmark"></i></button>
    <button class="tb-btn" id="btn-annot" title="Annotations" data-panel="annotations"><i class="bi bi-pencil-square"></i></button>
    <button class="tb-btn" id="btn-stats" title="Statistiques" data-panel="stats"><i class="bi bi-bar-chart"></i></button>
    <button class="tb-btn" id="btn-recs" title="Recommandations" data-panel="recs"><i class="bi bi-stars"></i></button>

    <!-- Fullscreen -->
    <button class="tb-btn" id="btn-fs" title="Plein écran (F11)"><i class="bi bi-fullscreen"></i></button>
  </header>

  <!-- ═══ READER AREA ═══ -->
  <div id="reader-area">

    <!-- Thumbnails strip -->
    <div id="thumb-strip"></div>

    <!-- Search bar -->
    <div class="search-overlay" id="search-overlay">
      <i class="bi bi-search" style="color:var(--text3);font-size:.88rem"></i>
      <input class="search-input" id="search-input-field" type="text" placeholder="Rechercher dans le livre…" autocomplete="off">
      <span class="search-count" id="search-count">—</span>
      <button class="tb-btn" id="search-prev-res"><i class="bi bi-chevron-up"></i></button>
      <button class="tb-btn" id="search-next-res"><i class="bi bi-chevron-down"></i></button>
      <button class="tb-btn" id="search-close-btn"><i class="bi bi-x"></i></button>
    </div>

    <!-- PDF Container -->
    <div id="pdf-container">
      <div id="pdf-viewer">
        <div id="pdf-loading">
          <div class="loader-ring"></div>
          <span style="font-size:.82rem">Chargement du livre…</span>
        </div>
      </div>
    </div>

    <!-- Text reader (fallback) -->
    <div id="text-reader">
      <div id="text-content"></div>
    </div>

    <!-- Side panel -->
    <div id="side-panel">
      <div class="panel-tabs">
        <div class="ptab active" data-section="bookmarks">🔖 Signets</div>
        <div class="ptab" data-section="annotations">✏️ Notes</div>
        <div class="ptab" data-section="stats">📊 Stats</div>
        <div class="ptab" data-section="recs">⭐ Suggestions</div>
      </div>

      <div class="panel-body">

        <!-- Bookmarks section -->
        <div class="panel-section active" id="section-bookmarks">
          <button class="panel-add-btn" id="add-bm-btn">
            <i class="bi bi-bookmark-plus"></i> Marquer la page actuelle
          </button>
          <div class="panel-form" id="bm-form">
            <label class="pf-label">Note (optionnelle)</label>
            <input class="pf-input" type="text" id="bm-note-input" placeholder="Ex: Passage intéressant…">
            <div class="pf-actions">
              <button class="btn-sm btn-gold" id="bm-save-btn"><i class="bi bi-bookmark-check"></i> Sauvegarder</button>
              <button class="btn-sm btn-ghost" onclick="document.getElementById('bm-form').classList.remove('open')">Annuler</button>
            </div>
          </div>
          <div id="bookmarks-list">
            <!-- Populated by JS -->
          </div>
        </div>

        <!-- Annotations section -->
        <div class="panel-section" id="section-annotations">
          <button class="panel-add-btn" id="add-annot-btn">
            <i class="bi bi-pencil-square"></i> Ajouter une annotation
          </button>
          <div class="panel-form" id="annot-form">
            <label class="pf-label">Texte sélectionné / extrait</label>
            <input class="pf-input" type="text" id="annot-texte-input" placeholder="Copiez un passage…">
            <label class="pf-label">Votre annotation</label>
            <textarea class="pf-textarea" id="annot-note-input" placeholder="Vos réflexions…"></textarea>
            <label class="pf-label">Couleur</label>
            <div class="color-pickers">
              <div class="color-pick selected" data-c="#facc15" style="background:#facc15"></div>
              <div class="color-pick" data-c="#34d399" style="background:#34d399"></div>
              <div class="color-pick" data-c="#f87171" style="background:#f87171"></div>
              <div class="color-pick" data-c="#60a5fa" style="background:#60a5fa"></div>
              <div class="color-pick" data-c="#c084fc" style="background:#c084fc"></div>
            </div>
            <div class="pf-actions">
              <button class="btn-sm btn-gold" id="annot-save-btn"><i class="bi bi-check2"></i> Sauvegarder</button>
              <button class="btn-sm btn-ghost" onclick="document.getElementById('annot-form').classList.remove('open')">Annuler</button>
            </div>
          </div>
          <div id="annotations-list"></div>
        </div>

        <!-- Stats section -->
        <div class="panel-section" id="section-stats">
          <div class="prog-ring-wrap">
            <svg class="prog-ring" width="100" height="100" viewBox="0 0 100 100">
              <defs>
                <linearGradient id="ringGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                  <stop offset="0%" style="stop-color:var(--gold)"/>
                  <stop offset="100%" style="stop-color:var(--cyan)"/>
                </linearGradient>
              </defs>
              <circle class="prog-ring-bg" cx="50" cy="50" r="44"/>
              <circle class="prog-ring-fill" id="ring-fill" cx="50" cy="50" r="44"
                      stroke-dasharray="276.46" stroke-dashoffset="276.46"/>
              <text x="50" y="47" text-anchor="middle" class="prog-ring-label" id="ring-pct">0%</text>
              <text x="50" y="60" text-anchor="middle" class="prog-ring-sub">PROGRESSION</text>
            </svg>
          </div>
          <div class="stat-row">
            <span class="stat-row-label"><i class="bi bi-file-text"></i> Page actuelle</span>
            <span class="stat-row-val" id="stat-page">—</span>
          </div>
          <div class="stat-row">
            <span class="stat-row-label"><i class="bi bi-book"></i> Pages totales</span>
            <span class="stat-row-val" id="stat-total">—</span>
          </div>
          <div class="stat-row">
            <span class="stat-row-label"><i class="bi bi-bookmark"></i> Signets</span>
            <span class="stat-row-val" id="stat-bm">0</span>
          </div>
          <div class="stat-row">
            <span class="stat-row-label"><i class="bi bi-pencil"></i> Annotations</span>
            <span class="stat-row-val" id="stat-an">0</span>
          </div>
          <div class="stat-row">
            <span class="stat-row-label"><i class="bi bi-download"></i> Téléchargements</span>
            <span class="stat-row-val" id="stat-dl"><?= $downloadCount ?> / <?= $maxDownloads ?></span>
          </div>
          <div class="stat-row">
            <span class="stat-row-label"><i class="bi bi-clock"></i> Temps total</span>
            <span class="stat-row-val" id="stat-time">—</span>
          </div>
          <div style="margin-top:.8rem;padding:.7rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-md)">
            <div style="font-size:.68rem;font-family:var(--font-mono);color:var(--text3);margin-bottom:.4rem">INFOS DU LIVRE</div>
            <div style="font-size:.75rem;color:var(--text2);line-height:1.8">
              <div>📖 <?= htmlspecialchars($livre['titre'], ENT_QUOTES, 'UTF-8') ?></div>
              <div>✍️ <?= htmlspecialchars($livre['auteur'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php if (!empty($livre['editeur'])): ?><div>🏛️ <?= htmlspecialchars($livre['editeur'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
              <?php if (!empty($livre['annee_parution'])): ?><div>📅 <?= htmlspecialchars($livre['annee_parution'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
              <?php if (!empty($livre['langue'])): ?><div>🌐 <?= htmlspecialchars($livre['langue'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
              <?php if (!empty($livre['pages'])): ?><div>📑 <?= (int)$livre['pages'] ?> pages</div><?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Recommendations section -->
        <div class="panel-section" id="section-recs">
          <p style="font-size:.72rem;color:var(--text3);font-family:var(--font-mono);margin-bottom:.8rem;text-transform:uppercase;letter-spacing:.05em">Dans la même catégorie</p>
          <div id="recs-list"></div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Floating page indicator -->
<div id="page-indicator">Page <span id="pi-cur">1</span></div>

<!-- Toast stack -->
<div id="toast-stack"></div>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════ -->
<script>
'use strict';

// ── Config ────────────────────────────────────────────────────
const LIVRE_ID    = <?= (int)$livreId ?>;
const LIVRE       = <?= $livreJson ?>;
const CSRF        = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const SAVED_PROG  = <?= $progJson ?>;
const MAX_DL      = <?= $maxDownloads ?>;
const PDF_URL     = <?= !empty($livre['fichier_pdf'])
    ? json_encode($livre['fichier_pdf'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)
    : 'null' ?>;
const HAS_TEXT    = <?= !empty($livre['contenu_extrait']) ? 'true' : 'false' ?>;
const CONTENT_EXTRAIT = <?= !empty($livre['contenu_extrait'])
    ? json_encode($livre['contenu_extrait'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)
    : 'null' ?>;
const RECS = <?= $recsJson ?>;

let initBookmarks   = <?= $bookmarksJson ?>;
let initAnnotations = <?= $annotationsJson ?>;

// ── State ─────────────────────────────────────────────────────
const state = {
  currentPage:  SAVED_PROG.page_actuelle || 1,
  totalPages:   0,
  zoom:         1.2,
  pdfDoc:       null,
  rendering:    false,
  renderQueue:  [],
  autoSaveTimer: null,
  readTimer:    null,
  readSeconds:  0,
  bookmarks:    [...initBookmarks],
  annotations:  [...initAnnotations],
  activePanel:  null,
  selectedColor:'#facc15',
  fontSizePx:   18,
  searchResults:[],
  searchIdx:    0,
  thumbsShown:  false,
  fullscreen:   false,
};

// ── Helpers ───────────────────────────────────────────────────
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function fmtTime(secs){const h=Math.floor(secs/3600),m=Math.floor((secs%3600)/60);return h>0?`${h}h ${m}min`:`${m}min`;}
function fmtDate(s){if(!s)return '';const d=new Date(s);return d.toLocaleDateString('fr-FR',{day:'2-digit',month:'short',year:'numeric'});}

// ── Toast ─────────────────────────────────────────────────────
const TICONS = {success:'✅',info:'📚',warn:'⚠️',error:'🔴'};
function toast(title, sub='', type='info', dur=3500){
  const stack = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = {success:'var(--green)',info:'var(--gold)',warn:'var(--amber)',error:'var(--rose)'}[type];
  t.innerHTML = `<span class="toast-icon">${TICONS[type]||'📚'}</span>
    <div><div class="toast-title">${esc(title)}</div>${sub?`<div class="toast-sub">${esc(sub)}</div>`:''}</div>`;
  stack.appendChild(t);
  requestAnimationFrame(()=>requestAnimationFrame(()=>t.classList.add('show')));
  setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),400);},dur);
}

// ── AJAX ──────────────────────────────────────────────────────
async function ajax(action, data={}, method='POST'){
  const base = window.location.pathname;
  if(method==='GET'){
    const params = new URLSearchParams({action,...data});
    const r = await fetch(`${base}?${params}`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    return r.json();
  }
  const body = new URLSearchParams({action, csrf:CSRF, livre_id:LIVRE_ID, ...data});
  const r = await fetch(base, {method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'}});
  return r.json();
}

// ══════════════════════════════════════════════════════════════
// PDF.JS READER
// ══════════════════════════════════════════════════════════════
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

async function initReader(){
  if(PDF_URL){
    await initPDF(PDF_URL);
  } else if(HAS_TEXT && CONTENT_EXTRAIT){
    initTextReader(CONTENT_EXTRAIT);
  } else {
    // Mode démo avec extrait de démonstration
    initTextReader(getDemoContent());
  }
  updateProgressBar(SAVED_PROG.pourcentage||0);
}

async function initPDF(url){
  const viewer = document.getElementById('pdf-viewer');
  try {
    const loadingTask = pdfjsLib.getDocument({url, withCredentials:false});
    loadingTask.onProgress = p => {
      if(p.total>0){
        const pct = Math.round((p.loaded/p.total)*100);
        const loading = document.getElementById('pdf-loading');
        if(loading) loading.querySelector('span').textContent = `Chargement… ${pct}%`;
      }
    };
    state.pdfDoc = await loadingTask.promise;
    state.totalPages = state.pdfDoc.numPages;

    document.getElementById('total-pages').textContent = state.totalPages;
    document.getElementById('page-input').max = state.totalPages;
    document.getElementById('stat-total').textContent = state.totalPages;
    document.getElementById('btn-prev').disabled = (state.currentPage <= 1);
    document.getElementById('btn-next').disabled = (state.currentPage >= state.totalPages);

    viewer.innerHTML = '';

    // Render all pages (lazy for large docs)
    if(state.totalPages <= 30){
      await renderAllPages();
    } else {
      await renderVisiblePages();
    }

    // Jump to saved page
    setTimeout(()=>scrollToPage(state.currentPage), 400);
    document.getElementById('page-input').value = state.currentPage;

    // Thumbnails
    renderThumbs();

  } catch(err){
    console.error('[PDF]', err);
    viewer.innerHTML = `<div style="text-align:center;padding:3rem;color:var(--text3)">
      <div style="font-size:3rem;margin-bottom:.8rem">📄</div>
      <p>Impossible de charger le PDF.</p>
      <p style="font-size:.75rem;margin-top:.5rem;color:var(--text3)">${esc(err.message)}</p>
    </div>`;
    // Fallback au lecteur texte si disponible
    if(HAS_TEXT && CONTENT_EXTRAIT) initTextReader(CONTENT_EXTRAIT);
  }
}

async function renderPage(pageNum, container=null){
  if(!state.pdfDoc || state.rendering) return;
  state.rendering = true;
  try {
    const page = await state.pdfDoc.getPage(pageNum);
    const viewport = page.getViewport({scale: state.zoom});

    const wrap = container || document.createElement('div');
    wrap.className = 'pdf-page-wrap';
    wrap.dataset.page = pageNum;

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    canvas.height = viewport.height;
    canvas.width  = viewport.width;
    wrap.appendChild(canvas);

    // Overlay buttons
    const overlayBtns = document.createElement('div');
    overlayBtns.className = 'page-overlay-btns';
    overlayBtns.innerHTML = `
      <button class="page-mini-btn" title="Marquer page" onclick="quickBookmark(${pageNum})"><i class="bi bi-bookmark-plus"></i></button>
      <button class="page-mini-btn" title="Annoter page" onclick="quickAnnotate(${pageNum})"><i class="bi bi-pencil"></i></button>`;
    wrap.appendChild(overlayBtns);

    await page.render({canvasContext:ctx, viewport}).promise;

    if(!container) document.getElementById('pdf-viewer').appendChild(wrap);

    return {canvas, wrap};
  } catch(e){
    console.error('[Render page]', e);
  } finally {
    state.rendering = false;
  }
}

async function renderAllPages(){
  const viewer = document.getElementById('pdf-viewer');
  viewer.innerHTML = '';
  for(let i=1; i<=state.totalPages; i++){
    const wrap = document.createElement('div');
    wrap.className = 'pdf-page-wrap';
    wrap.dataset.page = i;

    // Placeholder
    const ph = document.createElement('div');
    ph.style.cssText = `width:${Math.round(595*state.zoom)}px;height:${Math.round(842*state.zoom)}px;background:var(--bg3);border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;color:var(--text3);font-family:var(--font-mono);font-size:.7rem`;
    ph.textContent = `Page ${i}`;
    wrap.appendChild(ph);
    viewer.appendChild(wrap);
  }

  // IntersectionObserver for lazy rendering
  const io = new IntersectionObserver(async(entries)=>{
    for(const e of entries){
      if(e.isIntersecting){
        const wrap = e.target;
        const pNum = parseInt(wrap.dataset.page);
        if(!wrap.dataset.rendered){
          wrap.dataset.rendered = '1';
          wrap.innerHTML = '';
          await renderPage(pNum, wrap);
          const overlayBtns = document.createElement('div');
          overlayBtns.className = 'page-overlay-btns';
          overlayBtns.innerHTML = `
            <button class="page-mini-btn" onclick="quickBookmark(${pNum})"><i class="bi bi-bookmark-plus"></i></button>
            <button class="page-mini-btn" onclick="quickAnnotate(${pNum})"><i class="bi bi-pencil"></i></button>`;
          wrap.appendChild(overlayBtns);
          // Update current page tracking
          if(pNum !== state.currentPage){
            const rect = wrap.getBoundingClientRect();
            if(rect.top >= 0 && rect.top < window.innerHeight/2){
              updateCurrentPage(pNum);
            }
          }
        }
      }
    }
  }, {root:document.getElementById('pdf-container'), rootMargin:'200px'});

  viewer.querySelectorAll('.pdf-page-wrap').forEach(w=>io.observe(w));

  // Scroll observer for page tracking
  const scrollObs = new IntersectionObserver((entries)=>{
    entries.forEach(e=>{
      if(e.isIntersecting && e.intersectionRatio > 0.4){
        const pNum = parseInt(e.target.dataset.page);
        if(pNum && pNum !== state.currentPage) updateCurrentPage(pNum);
      }
    });
  }, {root:document.getElementById('pdf-container'), threshold:0.4});

  viewer.querySelectorAll('.pdf-page-wrap').forEach(w=>scrollObs.observe(w));
}

async function renderVisiblePages(){
  await renderAllPages();
}

async function renderThumbs(){
  if(!state.pdfDoc) return;
  const strip = document.getElementById('thumb-strip');
  strip.innerHTML = '';
  const limit = Math.min(state.totalPages, 50);
  for(let i=1; i<=limit; i++){
    const item = document.createElement('div');
    item.className = 'thumb-item' + (i===state.currentPage?' active':'');
    item.dataset.page = i;
    item.title = `Page ${i}`;
    item.onclick = ()=>goToPage(i);
    const canvas = document.createElement('canvas');
    item.appendChild(canvas);
    const num = document.createElement('div');
    num.className = 'thumb-num';
    num.textContent = i;
    item.appendChild(num);
    strip.appendChild(item);

    // Render thumbnail lazily
    const page = await state.pdfDoc.getPage(i);
    const vp = page.getViewport({scale:0.12});
    canvas.width  = vp.width;
    canvas.height = vp.height;
    await page.render({canvasContext:canvas.getContext('2d'), viewport:vp}).promise;
  }
}

async function renderVisiblePages2(){}

function scrollToPage(pageNum){
  const wrap = document.querySelector(`[data-page="${pageNum}"]`);
  if(wrap){
    wrap.scrollIntoView({behavior:'smooth', block:'start'});
  }
}

function updateCurrentPage(pageNum){
  if(pageNum < 1 || pageNum > state.totalPages) return;
  state.currentPage = pageNum;
  document.getElementById('page-input').value = pageNum;
  document.getElementById('btn-prev').disabled = (pageNum <= 1);
  document.getElementById('btn-next').disabled = (pageNum >= state.totalPages);
  document.getElementById('stat-page').textContent = pageNum;

  // Update active thumb
  document.querySelectorAll('.thumb-item').forEach(t=>{
    t.classList.toggle('active', parseInt(t.dataset.page)===pageNum);
  });

  // Floating indicator
  showPageIndicator(pageNum);

  // Auto-save (debounced)
  clearTimeout(state.autoSaveTimer);
  state.autoSaveTimer = setTimeout(()=>saveProgress(), 2000);

  // Update progress
  if(state.totalPages > 0){
    const pct = Math.round((pageNum / state.totalPages) * 100);
    updateProgressBar(pct);
  }
}

function goToPage(pageNum){
  pageNum = Math.max(1, Math.min(pageNum, state.totalPages||9999));
  state.currentPage = pageNum;
  document.getElementById('page-input').value = pageNum;
  scrollToPage(pageNum);
  updateCurrentPage(pageNum);
}

// ══════════════════════════════════════════════════════════════
// TEXT READER (fallback)
// ══════════════════════════════════════════════════════════════
function initTextReader(content){
  document.getElementById('pdf-container').style.display = 'none';
  const tr = document.getElementById('text-reader');
  tr.style.display = 'block';
  const tc = document.getElementById('text-content');

  // Parse content: ||||PAGE|||| as page separator
  const rawPages = content.split('||||PAGE||||');
  state.totalPages = rawPages.length;
  document.getElementById('total-pages').textContent = state.totalPages;
  document.getElementById('page-input').max = state.totalPages;
  document.getElementById('stat-total').textContent = state.totalPages;

  let html = '';
  rawPages.forEach((pageText, idx)=>{
    html += `<div class="text-page" data-page="${idx+1}">`;
    // Format paragraphs
    const paras = pageText.split('\n\n').filter(p=>p.trim());
    paras.forEach(p=>{
      const t = p.trim();
      if(t.startsWith('CHAPITRE') || t.startsWith('CHAPTER') || t.startsWith('##')){
        html += `<h2>${esc(t)}</h2>`;
      } else if(t.startsWith('#')){
        html += `<h1>${esc(t.replace(/^#+\s*/,''))}</h1>`;
      } else {
        html += `<p>${esc(t)}</p>`;
      }
    });
    if(idx < rawPages.length-1){
      html += `<div class="chapter-sep">• • •</div>`;
    }
    html += `</div>`;
  });

  tc.innerHTML = html;

  // Apply annotations highlights
  applyHighlights();

  // Scroll observer
  const io = new IntersectionObserver(entries=>{
    entries.forEach(e=>{
      if(e.isIntersecting && e.intersectionRatio>0.3){
        const pNum = parseInt(e.target.dataset.page);
        if(pNum && pNum!==state.currentPage) updateCurrentPage(pNum);
      }
    });
  },{root:tr, threshold:0.3});
  tc.querySelectorAll('.text-page').forEach(p=>io.observe(p));

  // Jump to saved
  setTimeout(()=>{
    const target = tc.querySelector(`[data-page="${state.currentPage}"]`);
    if(target) target.scrollIntoView({behavior:'smooth'});
  }, 300);

  document.getElementById('stat-page').textContent = state.currentPage;
  document.getElementById('btn-prev').disabled = (state.currentPage<=1);
  document.getElementById('btn-next').disabled = (state.currentPage>=state.totalPages);
}

function getDemoContent(){
  return `CHAPITRE I — La Bibliothèque Numérique

Dans le silence numérique de la bibliothèque, les pages se tournent sans bruit. Chaque livre est un univers à part entière, attendant d'être découvert par son lecteur.

Les algorithmes de recommandation analysent silencieusement vos habitudes de lecture, apprenant vos goûts avec chaque page tournée, chaque signet posé, chaque annotation griffonnée dans les marges virtuelles.

La révolution numérique a transformé notre rapport au livre sans en altérer l'essence : cette communion intime entre un auteur et son lecteur, traversant l'espace et le temps.

||||PAGE||||

CHAPITRE II — L'Art de la Lecture

Lire, c'est voyager sans bouger, c'est vivre mille vies en une seule, c'est dialoguer avec les plus grands esprits de l'humanité à travers les siècles.

La lecture numérique ajoute une dimension nouvelle : la possibilité de retrouver instantanément un passage, de partager une citation, de synchroniser sa lecture sur tous ses appareils.

Mais l'essence reste la même : ce plaisir immémorial de se perdre dans les mots d'un autre.

||||PAGE||||

CHAPITRE III — Les Annotations du Lecteur

Annoter un livre, c'est entrer en dialogue avec son auteur. Dans les marges des grands livres, on trouve parfois plus de sagesse que dans le texte lui-même.

Les lecteurs assidus savent que leurs propres annotations, leurs signets, leurs surlignages constituent une œuvre à part entière — leur lecture personnelle, unique et irremplaçable.

Cette plateforme vous permet de conserver et de retrouver chacune de ces traces précieuses.`;
}

function applyHighlights(){
  // Les annotations avec texte sélectionné peuvent être surlignées
  state.annotations.forEach(a=>{
    if(!a.texte_selectionne) return;
    const tc = document.getElementById('text-content');
    if(!tc) return;
    // Simple text highlighting (simplified)
    const html = tc.innerHTML;
    const escaped = a.texte_selectionne.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
    const re = new RegExp('(' + escaped + ')', 'g');
    tc.innerHTML = html.replace(re, `<mark class="highlight" style="background:${a.couleur}33;border-bottom:2px solid ${a.couleur};cursor:pointer" title="${esc(a.annotation||'')}" onclick="goToPage(${a.page_number})">$1</mark>`);
  });
}

// ══════════════════════════════════════════════════════════════
// PROGRESS
// ══════════════════════════════════════════════════════════════
function updateProgressBar(pct){
  pct = Math.max(0, Math.min(100, pct));
  document.getElementById('tb-prog-fill').style.width = pct + '%';
  document.getElementById('tb-pct').textContent = Math.round(pct) + '%';
  document.getElementById('ring-pct').textContent = Math.round(pct) + '%';
  // ring-fill stroke-dashoffset
  const C = 2 * Math.PI * 44; // = 276.46
  const offset = C - (pct / 100) * C;
  document.getElementById('ring-fill').style.strokeDashoffset = offset;
}

async function saveProgress(){
  const pct = state.totalPages > 0 ? (state.currentPage / state.totalPages) * 100 : 0;
  try {
    const res = await ajax('save_progress', {
      page: state.currentPage,
      pourcentage: pct.toFixed(2),
      temps: state.readSeconds,
    });
    if(res.success){
      toast('Progression sauvegardée', `Page ${state.currentPage}`, 'success', 2000);
      updateProgressBar(pct);
    }
  } catch(e){
    console.error('[Save progress]', e);
  }
}

// Auto-save toutes les 30s
setInterval(saveProgress, 30000);

// Timer de lecture
state.readTimer = setInterval(()=>{ state.readSeconds++; }, 1000);

// ══════════════════════════════════════════════════════════════
// BOOKMARKS
// ══════════════════════════════════════════════════════════════
function renderBookmarks(){
  const list = document.getElementById('bookmarks-list');
  document.getElementById('stat-bm').textContent = state.bookmarks.length;
  if(!state.bookmarks.length){
    list.innerHTML = `<div style="text-align:center;padding:2rem 1rem;color:var(--text3);font-size:.78rem"><i class="bi bi-bookmark" style="font-size:1.8rem;display:block;margin-bottom:.5rem"></i>Aucun signet pour le moment</div>`;
    return;
  }
  list.innerHTML = state.bookmarks.map(bm=>`
    <div class="panel-item" onclick="goToPage(${bm.page_number})">
      <div class="pi-icon"><i class="bi bi-bookmark-fill"></i></div>
      <div style="flex:1;min-width:0">
        <div class="pi-page">Page ${bm.page_number}</div>
        ${bm.note ? `<div class="pi-note">${esc(bm.note)}</div>` : ''}
        <div class="pi-date">${fmtDate(bm.created_at)}</div>
      </div>
      <button class="pi-delete" onclick="event.stopPropagation();deleteBookmark(${bm.id})" title="Supprimer"><i class="bi bi-x"></i></button>
    </div>`).join('');
}

async function addBookmark(note=''){
  try {
    const res = await ajax('add_bookmark', {page:state.currentPage, note});
    if(res.success){
      state.bookmarks.push({id:res.id, page_number:res.page, note:res.note, created_at:new Date().toISOString()});
      state.bookmarks.sort((a,b)=>a.page_number-b.page_number);
      renderBookmarks();
      document.getElementById('bm-form').classList.remove('open');
      document.getElementById('bm-note-input').value = '';
      toast('Signet ajouté', `Page ${state.currentPage}`, 'success');
    }
  } catch(e){ toast('Erreur','Impossible d\'ajouter le signet','error'); }
}

async function deleteBookmark(id){
  try {
    const res = await ajax('delete_bookmark', {bookmark_id:id});
    if(res.success){
      state.bookmarks = state.bookmarks.filter(b=>b.id!=id);
      renderBookmarks();
      toast('Signet supprimé','','info',1800);
    }
  } catch(e){}
}

function quickBookmark(pageNum){
  state.currentPage = pageNum;
  openPanel('bookmarks');
  document.getElementById('bm-form').classList.add('open');
}

// ══════════════════════════════════════════════════════════════
// ANNOTATIONS
// ══════════════════════════════════════════════════════════════
function renderAnnotations(){
  const list = document.getElementById('annotations-list');
  document.getElementById('stat-an').textContent = state.annotations.length;
  if(!state.annotations.length){
    list.innerHTML = `<div style="text-align:center;padding:2rem 1rem;color:var(--text3);font-size:.78rem"><i class="bi bi-pencil-square" style="font-size:1.8rem;display:block;margin-bottom:.5rem"></i>Aucune annotation</div>`;
    return;
  }
  list.innerHTML = state.annotations.map(a=>`
    <div class="annot-item" style="border-left-color:${a.couleur||'#facc15'}" onclick="goToPage(${a.page_number})">
      ${a.texte_selectionne ? `<div class="annot-highlight">"${esc(a.texte_selectionne.substring(0,80))}${a.texte_selectionne.length>80?'…':''}"</div>` : ''}
      ${a.annotation ? `<div class="annot-text">${esc(a.annotation)}</div>` : ''}
      <div class="annot-meta">
        <span class="annot-page">Page ${a.page_number} · ${fmtDate(a.created_at)}</span>
        <button class="pi-delete" onclick="event.stopPropagation();deleteAnnotation(${a.id})"><i class="bi bi-x"></i></button>
      </div>
    </div>`).join('');
}

async function addAnnotation(){
  const texte  = document.getElementById('annot-texte-input').value.trim();
  const annot  = document.getElementById('annot-note-input').value.trim();
  const couleur = state.selectedColor;
  if(!annot){ toast('Erreur','Veuillez écrire une annotation.','warn'); return; }
  try {
    const res = await ajax('add_annotation', {
      page: state.currentPage,
      texte_selectionne: texte,
      annotation: annot,
      couleur,
    });
    if(res.success){
      state.annotations.unshift({id:res.id, page_number:state.currentPage, texte_selectionne:texte, annotation:annot, couleur, created_at:new Date().toISOString()});
      renderAnnotations();
      document.getElementById('annot-form').classList.remove('open');
      document.getElementById('annot-texte-input').value = '';
      document.getElementById('annot-note-input').value = '';
      toast('Annotation ajoutée','','success');
    }
  } catch(e){ toast('Erreur','Impossible d\'ajouter l\'annotation','error'); }
}

async function deleteAnnotation(id){
  try {
    const res = await ajax('delete_annotation', {annotation_id:id});
    if(res.success){
      state.annotations = state.annotations.filter(a=>a.id!=id);
      renderAnnotations();
      toast('Annotation supprimée','','info',1800);
    }
  } catch(e){}
}

function quickAnnotate(pageNum){
  state.currentPage = pageNum;
  openPanel('annotations');
  document.getElementById('annot-form').classList.add('open');
}

// ══════════════════════════════════════════════════════════════
// STATS
// ══════════════════════════════════════════════════════════════
async function loadStats(){
  document.getElementById('stat-page').textContent = state.currentPage;
  document.getElementById('stat-total').textContent = state.totalPages || '—';
  document.getElementById('stat-time').textContent = fmtTime(state.readSeconds);
  try {
    const res = await ajax('get_stats', {livre_id:LIVRE_ID}, 'GET');
    if(res.success){
      document.getElementById('stat-bm').textContent = Math.max(res.bookmarks, state.bookmarks.length);
      document.getElementById('stat-an').textContent = Math.max(res.annotations, state.annotations.length);
      document.getElementById('stat-dl').textContent = `${res.downloads}/${MAX_DL}`;
      document.getElementById('stat-time').textContent = fmtTime(Math.max(res.temps, state.readSeconds));
    }
  } catch(e){}
}

// ══════════════════════════════════════════════════════════════
// RECOMMENDATIONS
// ══════════════════════════════════════════════════════════════
function renderRecs(){
  const list = document.getElementById('recs-list');
  if(!RECS || !RECS.length){
    list.innerHTML = `<div style="text-align:center;padding:2rem;color:var(--text3);font-size:.78rem">Aucune suggestion disponible</div>`;
    return;
  }
  const emojis = ['🌌','🧠','📜','🌿','⚙️','🎭'];
  list.innerHTML = RECS.map((r,i)=>`
    <a class="rec-item" href="read.php?id=${r.id}">
      <div class="rec-cover">${emojis[i%emojis.length]}</div>
      <div class="rec-info">
        <div class="rec-title">${esc(r.titre)}</div>
        <div class="rec-author">${esc(r.auteur)}</div>
        <div class="rec-price">${r.prix>0?Number(r.prix).toLocaleString('fr-FR')+' FCFA':'Gratuit'} · ⭐${Number(r.note_moyenne||0).toFixed(1)}</div>
      </div>
    </a>`).join('');
}

// ══════════════════════════════════════════════════════════════
// PANEL MANAGEMENT
// ══════════════════════════════════════════════════════════════
function openPanel(name){
  const app = document.getElementById('app');
  const wasOpen = state.activePanel;

  // Close if same
  if(wasOpen === name){
    app.classList.remove('panel-open');
    state.activePanel = null;
    document.querySelectorAll('.tb-btn[data-panel]').forEach(b=>b.classList.remove('active'));
    return;
  }

  state.activePanel = name;
  app.classList.add('panel-open');

  // Activate tab
  document.querySelectorAll('.ptab').forEach(t=>{
    t.classList.toggle('active', t.dataset.section===name);
  });
  document.querySelectorAll('.panel-section').forEach(s=>{
    s.classList.toggle('active', s.id===`section-${name}`);
  });
  document.querySelectorAll('.tb-btn[data-panel]').forEach(b=>{
    b.classList.toggle('active', b.dataset.panel===name);
  });

  // Load data for panel
  if(name==='stats') loadStats();
  if(name==='recs')  renderRecs();
}

// Panel tabs click
document.querySelectorAll('.ptab').forEach(tab=>{
  tab.addEventListener('click', ()=>openPanel(tab.dataset.section));
});

// Toolbar panel buttons
document.querySelectorAll('.tb-btn[data-panel]').forEach(btn=>{
  btn.addEventListener('click', ()=>openPanel(btn.dataset.panel));
});

// ══════════════════════════════════════════════════════════════
// THEME
// ══════════════════════════════════════════════════════════════
document.querySelectorAll('.theme-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.documentElement.setAttribute('data-theme', btn.dataset.theme);
    document.querySelectorAll('.theme-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    localStorage.setItem('dls_theme', btn.dataset.theme);
  });
});

// Restore saved theme
const savedTheme = localStorage.getItem('dls_theme');
if(savedTheme){
  document.documentElement.setAttribute('data-theme', savedTheme);
  document.querySelectorAll('.theme-btn').forEach(b=>{
    b.classList.toggle('active', b.dataset.theme===savedTheme);
  });
}

// ══════════════════════════════════════════════════════════════
// ZOOM
// ══════════════════════════════════════════════════════════════
function setZoom(z){
  state.zoom = Math.max(0.5, Math.min(3, z));
  document.getElementById('zoom-val').textContent = Math.round(state.zoom*100)+'%';
  // Re-render
  if(state.pdfDoc) renderAllPages().then(()=>renderThumbs());
  localStorage.setItem('dls_zoom', state.zoom);
}

document.getElementById('zoom-out').addEventListener('click', ()=>setZoom(state.zoom-0.1));
document.getElementById('zoom-in').addEventListener('click',  ()=>setZoom(state.zoom+0.1));

const savedZoom = parseFloat(localStorage.getItem('dls_zoom'));
if(!isNaN(savedZoom)) state.zoom = savedZoom;
document.getElementById('zoom-val').textContent = Math.round(state.zoom*100)+'%';

// ══════════════════════════════════════════════════════════════
// FONT SIZE (text mode)
// ══════════════════════════════════════════════════════════════
document.getElementById('font-up').addEventListener('click', ()=>{
  state.fontSizePx = Math.min(28, state.fontSizePx+1);
  document.documentElement.style.setProperty('--reading-size', state.fontSizePx+'px');
  localStorage.setItem('dls_font', state.fontSizePx);
});
document.getElementById('font-down').addEventListener('click', ()=>{
  state.fontSizePx = Math.max(13, state.fontSizePx-1);
  document.documentElement.style.setProperty('--reading-size', state.fontSizePx+'px');
  localStorage.setItem('dls_font', state.fontSizePx);
});

const savedFont = parseInt(localStorage.getItem('dls_font'));
if(!isNaN(savedFont)){ state.fontSizePx=savedFont; document.documentElement.style.setProperty('--reading-size', savedFont+'px'); }

// ══════════════════════════════════════════════════════════════
// NAVIGATION
// ══════════════════════════════════════════════════════════════
document.getElementById('btn-prev').addEventListener('click', ()=>goToPage(state.currentPage-1));
document.getElementById('btn-next').addEventListener('click', ()=>goToPage(state.currentPage+1));
document.getElementById('page-input').addEventListener('change', function(){
  const v=parseInt(this.value); if(!isNaN(v)) goToPage(v);
});

// ══════════════════════════════════════════════════════════════
// FULLSCREEN
// ══════════════════════════════════════════════════════════════
document.getElementById('btn-fs').addEventListener('click', ()=>{
  state.fullscreen = !state.fullscreen;
  document.getElementById('app').classList.toggle('fullscreen', state.fullscreen);
  document.getElementById('btn-fs').innerHTML = state.fullscreen
    ? '<i class="bi bi-fullscreen-exit"></i>'
    : '<i class="bi bi-fullscreen"></i>';
  // Native fullscreen API
  if(state.fullscreen && document.documentElement.requestFullscreen){
    document.documentElement.requestFullscreen().catch(()=>{});
  } else if(document.exitFullscreen){
    document.exitFullscreen().catch(()=>{});
  }
});

document.addEventListener('fullscreenchange', ()=>{
  if(!document.fullscreenElement && state.fullscreen){
    state.fullscreen = false;
    document.getElementById('app').classList.remove('fullscreen');
    document.getElementById('btn-fs').innerHTML = '<i class="bi bi-fullscreen"></i>';
  }
});

// ══════════════════════════════════════════════════════════════
// THUMBNAILS
// ══════════════════════════════════════════════════════════════
document.getElementById('btn-thumbs').addEventListener('click', ()=>{
  state.thumbsShown = !state.thumbsShown;
  document.getElementById('app').classList.toggle('thumbs-open', state.thumbsShown);
  document.getElementById('btn-thumbs').classList.toggle('active', state.thumbsShown);
});

// ══════════════════════════════════════════════════════════════
// SEARCH
// ══════════════════════════════════════════════════════════════
document.getElementById('btn-search').addEventListener('click', ()=>{
  const overlay = document.getElementById('search-overlay');
  overlay.classList.toggle('open');
  if(overlay.classList.contains('open')){
    document.getElementById('search-input-field').focus();
  }
});

document.getElementById('search-close-btn').addEventListener('click', ()=>{
  document.getElementById('search-overlay').classList.remove('open');
  clearSearchHighlights();
});

document.getElementById('search-input-field').addEventListener('input', function(){
  searchInBook(this.value.trim());
});

function searchInBook(query){
  clearSearchHighlights();
  if(!query || query.length < 2){
    document.getElementById('search-count').textContent = '—';
    return;
  }
  const tc = document.getElementById('text-content');
  if(!tc){ document.getElementById('search-count').textContent = 'PDF: recherche avancée non disponible'; return; }
  const html = tc.innerHTML;
  const re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi');
  const count = (html.match(re)||[]).length;
  tc.innerHTML = html.replace(re, '<mark class="search-mark" style="background:var(--amber);color:#000;border-radius:2px;padding:1px 2px">$1</mark>');
  document.getElementById('search-count').textContent = `${count} résultat${count!==1?'s':''}`;
  // Scroll to first
  const first = tc.querySelector('.search-mark');
  if(first) first.scrollIntoView({behavior:'smooth', block:'center'});
}

function clearSearchHighlights(){
  const tc = document.getElementById('text-content');
  if(!tc) return;
  tc.querySelectorAll('.search-mark').forEach(m=>{
    const text = document.createTextNode(m.textContent);
    m.parentNode.replaceChild(text, m);
  });
}

document.getElementById('search-input-field').addEventListener('keydown', e=>{
  if(e.key==='Escape') document.getElementById('search-close-btn').click();
});

// ══════════════════════════════════════════════════════════════
// DOWNLOAD
// ══════════════════════════════════════════════════════════════
const dlBtn = document.getElementById('btn-download');
if(dlBtn){
  dlBtn.addEventListener('click', async()=>{
    try {
      const res = await ajax('log_download', {livre_id:LIVRE_ID});
      if(res.success){
        // Déclencher le téléchargement
        const a = document.createElement('a');
        a.href = PHP_FILE_URL || '#';
        a.download = LIVRE.titre + '.pdf';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        toast('Téléchargement démarré', LIVRE.titre, 'success');
        // Update counter
        const span = dlBtn.querySelector('span');
        if(span){
          const parts = span.textContent.split('/');
          const cur = parseInt(parts[0])||0;
          span.textContent = `${cur+1}/${MAX_DL}`;
        }
      } else {
        toast('Limite atteinte', res.error||'Téléchargements épuisés', 'warn', 5000);
      }
    } catch(e){ toast('Erreur', 'Impossible de télécharger', 'error'); }
  });
}
const PHP_FILE_URL = <?= !empty($livre['fichier_pdf']) ? json_encode($livre['fichier_pdf'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) : 'null' ?>;

// ══════════════════════════════════════════════════════════════
// PAGE INDICATOR
// ══════════════════════════════════════════════════════════════
let piTimer = null;
function showPageIndicator(page){
  const el = document.getElementById('page-indicator');
  const piCur = document.getElementById('pi-cur');
  piCur.textContent = page;
  el.classList.add('visible');
  clearTimeout(piTimer);
  piTimer = setTimeout(()=>el.classList.remove('visible'), 2000);
}

// ══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ══════════════════════════════════════════════════════════════
document.addEventListener('keydown', e=>{
  if(e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA') return;
  switch(e.key){
    case 'ArrowLeft':  case 'PageUp':   e.preventDefault(); goToPage(state.currentPage-1); break;
    case 'ArrowRight': case 'PageDown': e.preventDefault(); goToPage(state.currentPage+1); break;
    case 'Home': e.preventDefault(); goToPage(1); break;
    case 'End':  e.preventDefault(); goToPage(state.totalPages||9999); break;
    case 'b': case 'B':
      if(!e.ctrlKey){ openPanel('bookmarks'); document.getElementById('bm-form').classList.add('open'); } break;
    case 'f': case 'F':
      if(e.ctrlKey){ e.preventDefault(); document.getElementById('btn-search').click(); } break;
    case '+': case '=': if(!e.ctrlKey) setZoom(state.zoom+0.1); break;
    case '-':           if(!e.ctrlKey) setZoom(state.zoom-0.1); break;
    case 'Escape':
      const so=document.getElementById('search-overlay');
      if(so.classList.contains('open')){ so.classList.remove('open'); clearSearchHighlights(); }
      else if(state.activePanel){ document.getElementById('app').classList.remove('panel-open'); state.activePanel=null; }
      break;
    case 'F11': e.preventDefault(); document.getElementById('btn-fs').click(); break;
  }
});

// ══════════════════════════════════════════════════════════════
// BOOKMARK FORM
// ══════════════════════════════════════════════════════════════
document.getElementById('add-bm-btn').addEventListener('click', ()=>{
  document.getElementById('bm-form').classList.toggle('open');
});
document.getElementById('bm-save-btn').addEventListener('click', ()=>{
  const note = document.getElementById('bm-note-input').value.trim();
  addBookmark(note);
});
document.getElementById('bm-note-input').addEventListener('keydown', e=>{
  if(e.key==='Enter') document.getElementById('bm-save-btn').click();
});

// ══════════════════════════════════════════════════════════════
// ANNOTATION FORM
// ══════════════════════════════════════════════════════════════
document.getElementById('add-annot-btn').addEventListener('click', ()=>{
  document.getElementById('annot-form').classList.toggle('open');
});
document.getElementById('annot-save-btn').addEventListener('click', addAnnotation);

// Color picker
document.querySelectorAll('.color-pick').forEach(cp=>{
  cp.addEventListener('click', ()=>{
    document.querySelectorAll('.color-pick').forEach(c=>c.classList.remove('selected'));
    cp.classList.add('selected');
    state.selectedColor = cp.dataset.c;
  });
});

// Capture selected text
document.addEventListener('mouseup', ()=>{
  const sel = window.getSelection();
  if(sel && sel.toString().trim().length>2){
    const selectedText = sel.toString().trim().substring(0,200);
    document.getElementById('annot-texte-input').value = selectedText;
    if(state.activePanel==='annotations'){
      document.getElementById('annot-form').classList.add('open');
    }
  }
});

// ══════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════
(async function(){
  renderBookmarks();
  renderAnnotations();
  renderRecs();
  await initReader();
  toast('Lecture reprise', `Page ${state.currentPage}`, 'success', 3000);

  // Stats update interval
  setInterval(()=>{
    document.getElementById('stat-time').textContent = fmtTime(state.readSeconds);
  }, 60000);
})();

</script>

<?php endif; ?>
</body>
</html>