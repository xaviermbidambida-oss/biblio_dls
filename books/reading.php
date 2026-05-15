<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — books/reading.php  v2.0                  ║
 * ║  Lectures en cours · Lecteur PDF Premium · Dashboard        ║
 * ║  100% fonctionnel · PDO sécurisé · AJAX intégré             ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Session ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Connexion PDO ─────────────────────────────────────────────
$pdo = null;
foreach ([
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/includes/config.php',
] as $_cfgPath) {
    if (file_exists($_cfgPath) && !defined('DLS_RD_CFG')) {
        require_once $_cfgPath;
        define('DLS_RD_CFG', true);
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
            [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES   => false]
        );
    } catch (PDOException $e) {
        error_log('[DLS:reading] PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth guard ────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=books/reading.php');
    exit;
}
$userId    = (int)$_SESSION['user_id'];
$userRole  = $_SESSION['user_role'] ?? 'lecteur';
$username  = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$firstName = htmlspecialchars($_SESSION['user_prenom'] ?? explode(' ', $username)[0] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$avatar    = strtoupper(substr($username, 0, 1)) ?: 'U';

// ── CSRF ──────────────────────────────────────────────────────
if (empty($_SESSION['csrf_reading'])) {
    $_SESSION['csrf_reading'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_reading'];

// ── Auto-création tables manquantes ───────────────────────────
if ($pdo) {
    try {
        // lecture_progression
        $pdo->exec("CREATE TABLE IF NOT EXISTS lecture_progression (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            page_actuelle INT DEFAULT 1,
            total_pages INT DEFAULT 0,
            pourcentage DECIMAL(5,2) DEFAULT 0.00,
            temps_lecture INT DEFAULT 0,
            derniere_lecture TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            statut ENUM('en_cours','termine') DEFAULT 'en_cours',
            UNIQUE KEY uq_user_livre (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // favorites
        $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fav (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // reading_bookmarks
        $pdo->exec("CREATE TABLE IF NOT EXISTS reading_bookmarks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            page_number INT NOT NULL DEFAULT 1,
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // user_downloads
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_downloads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            livre_id INT UNSIGNED NOT NULL,
            count INT UNSIGNED DEFAULT 1,
            last_dl_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ud (user_id, livre_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Colonnes manquantes sur lecture_progression
        foreach (['derniere_lecture TIMESTAMP NULL', 'temps_lecture INT DEFAULT 0', 'total_pages INT DEFAULT 0', "statut ENUM('en_cours','termine') DEFAULT 'en_cours'"] as $colDef) {
            $colName = explode(' ', trim($colDef))[0];
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM lecture_progression LIKE '{$colName}'")->fetchAll();
                if (empty($cols)) {
                    $pdo->exec("ALTER TABLE lecture_progression ADD COLUMN {$colDef}");
                }
            } catch (Throwable $e) {}
        }

    } catch (Throwable $e) {
        error_log('[DLS:reading] Schema: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// AJAX HANDLER — toutes actions gérées dans le même fichier
// ══════════════════════════════════════════════════════════════
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];
    $body   = [];

    // Lire le corps JSON ou POST classique
    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true) ?? [];
    }
    $body = array_merge($_POST, $body);

    // Validation CSRF
    $incomingCsrf = $body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf_reading'] ?? '', $incomingCsrf)) {
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']);
        exit;
    }
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Base de données inaccessible']);
        exit;
    }

    try {
        switch ($action) {

            // ── Sauvegarder la progression ─────────────────────
            case 'save_progress':
                $livreId    = (int)($body['livre_id']     ?? 0);
                $page       = max(1, (int)($body['page_actuelle'] ?? 1));
                $totalP     = max(1, (int)($body['total_pages']   ?? 1));
                $tempsLec   = max(0, (int)($body['temps_lecture'] ?? 0));
                if (!$livreId) throw new Exception('livre_id manquant');

                $pct    = min(100.00, round(($page / $totalP) * 100, 2));
                $statut = ($pct >= 95) ? 'termine' : 'en_cours';

                $pdo->prepare(
                    "INSERT INTO lecture_progression
                        (user_id, livre_id, page_actuelle, total_pages, pourcentage, temps_lecture, derniere_lecture, statut)
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                     ON DUPLICATE KEY UPDATE
                        page_actuelle   = VALUES(page_actuelle),
                        total_pages     = VALUES(total_pages),
                        pourcentage     = VALUES(pourcentage),
                        temps_lecture   = temps_lecture + VALUES(temps_lecture),
                        derniere_lecture= NOW(),
                        statut          = VALUES(statut)"
                )->execute([$userId, $livreId, $page, $totalP, $pct, $tempsLec, $statut]);

                echo json_encode(['success' => true, 'pct' => $pct, 'statut' => $statut]);
                break;

            // ── Toggle favori ───────────────────────────────────
            case 'toggle_favorite':
                $livreId = (int)($body['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('livre_id manquant');

                // Vérifier existence livre
                $lSt = $pdo->prepare("SELECT id FROM livres WHERE id=? AND statut='disponible'");
                $lSt->execute([$livreId]);
                if (!$lSt->fetch()) throw new Exception('Livre introuvable');

                $chk = $pdo->prepare("SELECT id FROM favorites WHERE user_id=? AND livre_id=?");
                $chk->execute([$userId, $livreId]);
                $exists = $chk->fetch();

                if ($exists) {
                    $pdo->prepare("DELETE FROM favorites WHERE user_id=? AND livre_id=?")->execute([$userId, $livreId]);
                    $favorited = false;
                } else {
                    $pdo->prepare("INSERT IGNORE INTO favorites (user_id, livre_id) VALUES (?,?)")->execute([$userId, $livreId]);
                    $favorited = true;
                }

                $cntSt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id=?");
                $cntSt->execute([$userId]);
                echo json_encode(['success' => true, 'favorited' => $favorited, 'total' => (int)$cntSt->fetchColumn()]);
                break;

            // ── Ajouter signet ──────────────────────────────────
            case 'add_bookmark':
                $livreId = (int)($body['livre_id']     ?? 0);
                $page    = max(1, (int)($body['page_number'] ?? 1));
                $note    = trim(mb_substr($body['note'] ?? '', 0, 500));
                if (!$livreId) throw new Exception('livre_id manquant');

                $pdo->prepare(
                    "INSERT INTO reading_bookmarks (user_id, livre_id, page_number, note) VALUES (?,?,?,?)"
                )->execute([$userId, $livreId, $page, $note ?: null]);

                echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
                break;

            // ── Supprimer signet ────────────────────────────────
            case 'delete_bookmark':
                $id = (int)($body['id'] ?? 0);
                if (!$id) throw new Exception('id manquant');
                $pdo->prepare("DELETE FROM reading_bookmarks WHERE id=? AND user_id=?")->execute([$id, $userId]);
                echo json_encode(['success' => true]);
                break;

            // ── Récupérer signets ───────────────────────────────
            case 'get_bookmarks':
                $livreId = (int)($_GET['livre_id'] ?? 0);
                $sql = "SELECT rb.id, rb.livre_id, rb.page_number, rb.note, rb.created_at,
                               l.titre AS livre_titre
                        FROM reading_bookmarks rb
                        JOIN livres l ON l.id = rb.livre_id
                        WHERE rb.user_id = ?";
                $params = [$userId];
                if ($livreId > 0) { $sql .= " AND rb.livre_id = ?"; $params[] = $livreId; }
                $sql .= " ORDER BY rb.created_at DESC LIMIT 30";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                echo json_encode(['success' => true, 'bookmarks' => $st->fetchAll()]);
                break;

            // ── Contenu livre (extrait) ─────────────────────────
            case 'get_book_content':
                $livreId = (int)($_GET['livre_id'] ?? 0);
                $page    = max(1, (int)($_GET['page'] ?? 1));
                if (!$livreId) throw new Exception('livre_id manquant');

                // Vérifier accès (acheté ou gratuit/journaliste)
                $accessSt = $pdo->prepare(
                    "SELECT l.id, l.titre, l.auteur, l.pages, l.contenu_extrait,
                            l.access_type, l.prix, l.fichier_pdf,
                            (SELECT COUNT(*) FROM achats WHERE user_id=? AND livre_id=l.id AND statut='confirme') AS achete
                     FROM livres l WHERE l.id=? AND l.statut='disponible'"
                );
                $accessSt->execute([$userId, $livreId]);
                $livre = $accessSt->fetch();

                if (!$livre) throw new Exception('Livre introuvable');

                $canRead = (bool)$livre['achete']
                    || $userRole === 'admin'
                    || ($userRole === 'journaliste' && ((float)$livre['prix'] == 0 || $livre['access_type'] === 'gratuit' || (float)($livre['note_moyenne'] ?? 5) < 4.5))
                    || $livre['access_type'] === 'gratuit'
                    || (float)$livre['prix'] == 0;

                if (!$canRead) throw new Exception('Accès non autorisé');

                $extrait = $livre['contenu_extrait'] ?? '';
                // Découper en pages
                $pages = [];
                if ($extrait) {
                    $parts = array_values(array_filter(array_map('trim', explode('||||PAGE||||', $extrait))));
                    $pages = $parts;
                }
                if (empty($pages)) {
                    $pages = [
                        "CHAPITRE 1\n\nBienvenue dans votre lecteur numérique. Ce livre a été soigneusement sélectionné pour vous offrir la meilleure expérience de lecture.\n\nLa lecture est une fenêtre ouverte sur le monde, un voyage intérieur qui transforme notre façon de penser et d'agir.",
                        "CHAPITRE 2\n\nLe savoir s'acquiert avec la lecture, la confiance avec l'action. Ces mots résonnent comme un appel à l'exploration perpétuelle.\n\nDans cet espace littéraire digital, chaque livre représente une aventure intellectuelle unique.",
                        "CHAPITRE 3\n\nLes grandes œuvres de la littérature ont toujours eu un point commun : elles parlent à notre humanité profonde.\n\nElles nous font voyager, réfléchir, ressentir. C'est cette magie que nous vous proposons de vivre.",
                    ];
                }
                $totalPages = count($pages);
                $idx = max(0, min($page - 1, $totalPages - 1));
                $contenu = $pages[$idx];

                // Formater le contenu en HTML
                $html = '';
                $paras = preg_split('/\n{2,}/', trim($contenu));
                foreach ($paras as $para) {
                    $para = trim($para);
                    if (preg_match('/^CHAPITRE\s+\d+/i', $para) || preg_match('/^(ÉPILOGUE|POSTFACE|INTRODUCTION)/i', $para)) {
                        $html .= '<h2>' . htmlspecialchars($para, ENT_QUOTES, 'UTF-8') . '</h2>';
                    } else {
                        $html .= '<p>' . nl2br(htmlspecialchars($para, ENT_QUOTES, 'UTF-8')) . '</p>';
                    }
                }

                echo json_encode([
                    'success'     => true,
                    'titre'       => $livre['titre'],
                    'auteur'      => $livre['auteur'],
                    'contenu'     => $html,
                    'total_pages' => $totalPages,
                    'page'        => $page,
                    'has_pdf'     => !empty($livre['fichier_pdf']),
                ]);
                break;

            // ── Télécharger livre ───────────────────────────────
            case 'download_book':
                $livreId = (int)($body['livre_id'] ?? 0);
                if (!$livreId) throw new Exception('livre_id manquant');

                // Vérifier accès
                $dlSt = $pdo->prepare(
                    "SELECT l.fichier_pdf, l.titre,
                            (SELECT COUNT(*) FROM achats WHERE user_id=? AND livre_id=l.id AND statut='confirme') AS achete
                     FROM livres l WHERE l.id=? AND l.statut='disponible'"
                );
                $dlSt->execute([$userId, $livreId]);
                $dlLivre = $dlSt->fetch();

                if (!$dlLivre) throw new Exception('Livre introuvable');
                if (!$dlLivre['achete'] && $userRole !== 'admin') throw new Exception('Achat requis pour télécharger');
                if (empty($dlLivre['fichier_pdf']))  throw new Exception('Fichier PDF non disponible');

                $pdfPath = __DIR__ . '/../' . $dlLivre['fichier_pdf'];
                if (!file_exists($pdfPath)) throw new Exception('Fichier introuvable sur le serveur');

                // Logger le téléchargement
                $pdo->prepare(
                    "INSERT INTO user_downloads (user_id, livre_id, count) VALUES (?,?,1)
                     ON DUPLICATE KEY UPDATE count=count+1, last_dl_at=NOW()"
                )->execute([$userId, $livreId]);

                echo json_encode(['success' => true, 'url' => $dlLivre['fichier_pdf']]);
                break;

            // ── Stats de lecture ────────────────────────────────
            case 'reading_stats':
                $stS = $pdo->prepare(
                    "SELECT
                        SUM(CASE WHEN lp.statut='en_cours' OR (lp.pourcentage < 95 AND lp.pourcentage > 0) THEN 1 ELSE 0 END) AS en_cours,
                        SUM(CASE WHEN lp.statut='termine' OR lp.pourcentage >= 95 THEN 1 ELSE 0 END) AS termines,
                        SUM(lp.page_actuelle) AS pages_lues,
                        SUM(lp.temps_lecture) AS temps_total,
                        (SELECT COUNT(*) FROM favorites WHERE user_id=?) AS favoris
                     FROM lecture_progression lp
                     WHERE lp.user_id=?"
                );
                $stS->execute([$userId, $userId]);
                $stData = $stS->fetch();
                echo json_encode(['success' => true] + ($stData ?: []));
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Action inconnue: ' . htmlspecialchars($action)]);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// CHARGEMENT DONNÉES PAGE
// ══════════════════════════════════════════════════════════════
$livresEnCours  = [];
$livresTermines = [];
$stats = ['en_cours' => 0, 'termines' => 0, 'pages_lues' => 0, 'temps_total' => 0, 'streak' => 0, 'favoris' => 0];
$weekActivity   = [];
$bookmarks      = [];
$recommandations = [];
$notifications  = [];
$notifCount     = 0;

if ($pdo) {
    try {
        // ── Livres achetés + progression ──────────────────────
        $sqlMain = "
            SELECT
                l.id, l.titre, l.auteur, l.prix,
                COALESCE(l.access_type, 'standard') AS access_type,
                l.note_moyenne, l.pages,
                COALESCE(l.is_featured, 0) AS is_featured,
                COALESCE(l.is_bestseller, 0) AS is_bestseller,
                l.couverture, l.fichier_pdf,
                c.nom AS categorie_nom, c.icone AS categorie_icone, c.slug AS categorie_slug,
                lp.page_actuelle, lp.pourcentage, lp.derniere_lecture,
                lp.temps_lecture, lp.total_pages, lp.statut AS lp_statut,
                a.created_at AS date_achat,
                CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
            FROM achats a
            JOIN livres l ON l.id = a.livre_id AND l.statut = 'disponible'
            LEFT JOIN categories c ON c.id = l.categorie_id
            LEFT JOIN lecture_progression lp ON lp.livre_id = l.id AND lp.user_id = a.user_id
            LEFT JOIN favorites f ON f.livre_id = l.id AND f.user_id = a.user_id
            WHERE a.user_id = ? AND a.statut = 'confirme'
            GROUP BY l.id
            ORDER BY lp.derniere_lecture DESC, a.created_at DESC
        ";
        $stM = $pdo->prepare($sqlMain);
        $stM->execute([$userId]);
        $allLivres = $stM->fetchAll();

        // ── Livres gratuits avec progression (non achetés) ────
        $sqlFree = "
            SELECT
                l.id, l.titre, l.auteur, l.prix,
                COALESCE(l.access_type, 'gratuit') AS access_type,
                l.note_moyenne, l.pages,
                COALESCE(l.is_featured, 0) AS is_featured,
                COALESCE(l.is_bestseller, 0) AS is_bestseller,
                l.couverture, l.fichier_pdf,
                c.nom AS categorie_nom, c.icone AS categorie_icone, c.slug AS categorie_slug,
                lp.page_actuelle, lp.pourcentage, lp.derniere_lecture,
                lp.temps_lecture, lp.total_pages, lp.statut AS lp_statut,
                NULL AS date_achat,
                CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
            FROM lecture_progression lp
            JOIN livres l ON l.id = lp.livre_id AND l.statut = 'disponible'
            LEFT JOIN categories c ON c.id = l.categorie_id
            LEFT JOIN favorites f ON f.livre_id = l.id AND f.user_id = lp.user_id
            LEFT JOIN achats a ON a.livre_id = l.id AND a.user_id = lp.user_id AND a.statut = 'confirme'
            WHERE lp.user_id = ?
              AND (l.prix = 0 OR l.access_type = 'gratuit')
              AND a.id IS NULL
            ORDER BY lp.derniere_lecture DESC
        ";
        $stF = $pdo->prepare($sqlFree);
        $stF->execute([$userId]);
        $livresGratuits = $stF->fetchAll();

        // ── Fusionner et classer ──────────────────────────────
        $allLivres = array_merge($allLivres, $livresGratuits);
        // Dédupliquer par ID
        $seen = [];
        $allLivres = array_filter($allLivres, function($l) use (&$seen) {
            if (isset($seen[$l['id']])) return false;
            $seen[$l['id']] = true;
            return true;
        });

        foreach ($allLivres as $livre) {
            $pct    = (float)($livre['pourcentage'] ?? 0);
            $page   = (int)($livre['page_actuelle'] ?? 0);
            $totalP = (int)($livre['total_pages'] ?? $livre['pages'] ?? 0);
            // Recalcul pct si manquant
            if ($pct == 0 && $page > 0 && $totalP > 0) {
                $pct = round(($page / $totalP) * 100, 2);
            }
            $lp_statut = $livre['lp_statut'] ?? 'en_cours';
            $isTermine = ($lp_statut === 'termine' || $pct >= 95);

            if ($isTermine) {
                $livresTermines[] = $livre;
                $stats['termines']++;
            } else {
                $livresEnCours[] = $livre;
                $stats['en_cours']++;
            }
            $stats['pages_lues'] += $page;
            $stats['temps_total'] += (int)($livre['temps_lecture'] ?? 0);
        }

        // ── Favoris count ─────────────────────────────────────
        $stFav = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id=?");
        $stFav->execute([$userId]);
        $stats['favoris'] = (int)$stFav->fetchColumn();

        // ── Streak (jours actifs) ─────────────────────────────
        try {
            $stStrk = $pdo->prepare(
                "SELECT COUNT(DISTINCT DATE(derniere_lecture))
                 FROM lecture_progression
                 WHERE user_id=? AND derniere_lecture >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            $stStrk->execute([$userId]);
            $stats['streak'] = (int)$stStrk->fetchColumn();
        } catch (Throwable $e) { $stats['streak'] = 0; }

        // ── Activité hebdomadaire ─────────────────────────────
        try {
            $stWeek = $pdo->prepare(
                "SELECT DATE(derniere_lecture) AS jour, SUM(page_actuelle) AS pages
                 FROM lecture_progression
                 WHERE user_id=? AND derniere_lecture >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY DATE(derniere_lecture)"
            );
            $stWeek->execute([$userId]);
            $weekData = $stWeek->fetchAll();
        } catch (Throwable $e) { $weekData = []; }

        $frDays = ['Mon'=>'L','Tue'=>'M','Wed'=>'M','Thu'=>'J','Fri'=>'V','Sat'=>'S','Sun'=>'D'];
        $today = date('Y-m-d');
        for ($i = 6; $i >= 0; $i--) {
            $d      = date('Y-m-d', strtotime("-{$i} days"));
            $lbl    = $frDays[date('D', strtotime($d))] ?? '?';
            $pages  = 0;
            foreach ($weekData as $r) { if ($r['jour'] === $d) { $pages = (int)$r['pages']; break; } }
            $weekActivity[] = ['label' => $lbl, 'pages' => $pages, 'date' => $d, 'today' => ($d === $today)];
        }

        // ── Signets ───────────────────────────────────────────
        try {
            $stBm = $pdo->prepare(
                "SELECT rb.id, rb.livre_id, rb.page_number, rb.note, rb.created_at,
                        l.titre AS livre_titre
                 FROM reading_bookmarks rb
                 JOIN livres l ON l.id = rb.livre_id
                 WHERE rb.user_id = ?
                 ORDER BY rb.created_at DESC LIMIT 20"
            );
            $stBm->execute([$userId]);
            $bookmarks = $stBm->fetchAll();
        } catch (Throwable $e) { $bookmarks = []; }

        // ── Recommandations ───────────────────────────────────
        $livreLus = array_column(array_merge($livresEnCours, $livresTermines), 'id');
        $livreLus = array_map('intval', $livreLus);
        $excludeSQL = !empty($livreLus) ? 'AND l.id NOT IN (' . implode(',', $livreLus) . ')' : '';
        try {
            $sqlRec = "SELECT l.id, l.titre, l.auteur, l.prix,
                              COALESCE(l.access_type,'standard') AS access_type,
                              l.note_moyenne,
                              c.nom AS categorie_nom, c.icone AS categorie_icone
                       FROM livres l
                       LEFT JOIN categories c ON c.id = l.categorie_id
                       WHERE l.statut='disponible' $excludeSQL
                       ORDER BY l.note_moyenne DESC, l.nb_ventes DESC
                       LIMIT 6";
            $recommandations = $pdo->query($sqlRec)->fetchAll();
        } catch (Throwable $e) { $recommandations = []; }

        // ── Notifications ─────────────────────────────────────
        try {
            $stN = $pdo->prepare(
                "SELECT * FROM notifications
                 WHERE user_id=? OR user_id IS NULL
                 ORDER BY created_at DESC LIMIT 8"
            );
            $stN->execute([$userId]);
            $notifications = $stN->fetchAll();

            $stNC = $pdo->prepare(
                "SELECT COUNT(*) FROM notifications
                 WHERE (lu=0 OR is_read=0) AND (user_id=? OR user_id IS NULL)"
            );
            $stNC->execute([$userId]);
            $notifCount = (int)$stNC->fetchColumn();
        } catch (Throwable $e) { $notifications = []; $notifCount = 0; }

    } catch (Throwable $e) {
        error_log('[DLS:reading] Data: ' . $e->getMessage());
    }
}

// ── Helpers ───────────────────────────────────────────────────
function timeAgoRd(string $dt): string {
    if (!$dt || $dt === '0000-00-00 00:00:00') return 'Jamais';
    $diff = time() - strtotime($dt);
    if ($diff < 60)      return 'À l\'instant';
    if ($diff < 3600)    return (int)($diff / 60) . ' min';
    if ($diff < 86400)   return (int)($diff / 3600) . 'h';
    if ($diff < 604800)  return (int)($diff / 86400) . 'j';
    return date('d/m/Y', strtotime($dt));
}
function fmtTempsRd(int $s): string {
    if ($s <= 0)   return '—';
    if ($s < 60)   return $s . 's';
    if ($s < 3600) return (int)($s / 60) . ' min';
    $h = (int)($s / 3600); $m = (int)(($s % 3600) / 60);
    return $h . 'h' . ($m > 0 ? $m . 'min' : '');
}
function tempsRestantRd(int $page, int $total): string {
    $r = max(0, $total - $page);
    return fmtTempsRd($r * 120); // ~2 min/page
}
function bookEmojiRd(string $slug = '', int $id = 0): string {
    $map = ['sf' => '🌌','philo' => '🧠','nature' => '🌿','tech' => '⚙️','histoire' => '📜',
            'lit' => '🎭','sciences' => '🔬','eco' => '💹','art' => '🎨','dev' => '🌱'];
    $pool = ['📖','🌌','🧠','📜','🎭','🔬','🌿','⚙️','💹','🎨','🌱','🔭','🦋','🌊','⭐','🔮','💡','🌍','🏔️','⚡'];
    return $map[strtolower($slug)] ?? $pool[$id % count($pool)];
}
function safeOut(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lectures en cours — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══════════════════════════════════════════════
   RESET & DESIGN TOKENS
══════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#05080f;--surf:#0b1020;--card:rgba(255,255,255,.032);--card-h:rgba(255,255,255,.058);
  --b:rgba(255,255,255,.072);--ba:rgba(0,212,255,.38);
  --cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;--amber:#f59e0b;--rose:#f43f5e;--plum:#a78bfa;
  --tp:#eef2ff;--ts:rgba(238,242,255,.56);--tm:rgba(238,242,255,.28);
  --sw:256px;--th:62px;
  --r:8px;--rm:13px;--rl:18px;--rx:26px;
  --gc:0 0 28px rgba(0,212,255,.18);--sc:0 4px 24px rgba(0,0,0,.32);--sl:0 24px 64px rgba(0,0,0,.52);
  --ease:cubic-bezier(.34,1.56,.64,1);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--tp);overflow-x:hidden;min-height:100vh}
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:rgba(0,212,255,.3)}
a{text-decoration:none;color:inherit}

/* ── LAYOUT ── */
.app{display:flex;min-height:100vh}

/* ── SIDEBAR ── */
#sb{position:fixed;top:0;left:0;bottom:0;width:var(--sw);background:var(--surf);border-right:1px solid var(--b);display:flex;flex-direction:column;z-index:200;overflow:hidden;transition:transform .3s ease}
.sb-brand{height:var(--th);display:flex;align-items:center;gap:11px;padding:0 16px;border-bottom:1px solid var(--b);flex-shrink:0}
.sb-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--cyan),var(--violet));border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0;box-shadow:var(--gc)}
.sb-txt{font-family:'Syne',sans-serif;font-weight:800;font-size:.9rem;letter-spacing:-.3px}
.sb-txt em{color:var(--cyan);font-style:normal}
.sb-user{display:flex;align-items:center;gap:11px;padding:14px 16px;border-bottom:1px solid var(--b);flex-shrink:0}
.sb-av{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--neon),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.88rem;color:#fff;flex-shrink:0}
.sb-uname{font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-role{font-size:.6rem;font-family:'Space Mono',monospace;padding:2px 7px;border-radius:100px;background:rgba(0,255,170,.08);color:var(--neon);border:1px solid rgba(255,255,255,.07);display:inline-block;margin-top:3px;text-transform:uppercase}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0}
.nav-sect{font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;color:var(--tm);padding:8px 16px 3px}
.nav-a{display:flex;align-items:center;gap:11px;padding:9px 16px;margin:2px 8px;border-radius:var(--r);color:var(--ts);font-size:.83rem;font-weight:500;transition:all .15s;position:relative}
.nav-a:hover{color:var(--tp);background:var(--card-h)}
.nav-a.on{color:var(--neon);background:rgba(0,255,170,.08);border:1px solid rgba(255,255,255,.05)}
.nav-a.on::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:18px;background:var(--neon);border-radius:0 3px 3px 0}
.nav-ico{font-size:1rem;width:20px;text-align:center;flex-shrink:0}
.nav-badge{margin-left:auto;font-size:.58rem;font-family:'Space Mono',monospace;padding:2px 6px;border-radius:100px;background:var(--neon);color:#000;font-weight:700}
.sb-foot{padding:10px;border-top:1px solid var(--b);flex-shrink:0}
.sb-logout{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:var(--r);color:var(--ts);font-size:.78rem;transition:all .15s}
.sb-logout:hover{color:var(--rose);background:rgba(244,63,94,.06)}

/* ── MAIN ── */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-height:100vh}

/* ── TOPBAR ── */
#topbar{height:var(--th);background:rgba(5,8,15,.88);backdrop-filter:blur(22px);border-bottom:1px solid var(--b);display:flex;align-items:center;gap:.9rem;padding:0 1.5rem;position:sticky;top:0;z-index:100}
.bc{display:flex;align-items:center;gap:7px;font-size:.78rem;color:var(--ts)}
.bc a{color:var(--ts);transition:color .15s}.bc a:hover{color:var(--tp)}
.bc-sep{opacity:.3}
.bc-curr{font-family:'Syne',sans-serif;font-weight:700;color:var(--tp)}
.sp{flex:1}
.tb-btn{width:34px;height:34px;border-radius:var(--r);background:var(--card);border:1px solid var(--b);color:var(--ts);display:flex;align-items:center;justify-content:center;font-size:.95rem;transition:all .15s;position:relative;cursor:pointer}
.tb-btn:hover{color:var(--tp);background:var(--card-h)}
.notif-dot{position:absolute;top:-3px;right:-3px;min-width:15px;height:15px;padding:0 3px;background:var(--rose);border-radius:50%;font-size:.5rem;font-weight:700;font-family:'Space Mono',monospace;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);color:#fff}
.tb-user-chip{display:flex;align-items:center;gap:7px;padding:4px 10px 4px 4px;background:var(--card);border:1px solid var(--b);border-radius:100px;transition:all .15s}
.tb-user-chip:hover{border-color:var(--ba)}
.tb-av-sm{width:24px;height:24px;border-radius:7px;background:linear-gradient(135deg,var(--neon),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.68rem;color:#fff;flex-shrink:0}
.tb-uname{font-size:.75rem;font-weight:600}
.tb-role{font-size:.58rem;color:var(--neon);font-family:'Space Mono',monospace}
.tb-ham{display:none;background:none;border:none;color:var(--tp);font-size:1.3rem;cursor:pointer;width:34px;height:34px;align-items:center;justify-content:center;border-radius:var(--r)}

/* ── PAGE ── */
.page{flex:1;padding:1.8rem;max-width:1480px;width:100%;margin:0 auto}

/* ── ANIMATIONS ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes shimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
@keyframes gradMove{0%,100%{background-position:0 50%}50%{background-position:100% 50%}}

/* ── HERO ── */
.hero{background:linear-gradient(135deg,rgba(0,212,255,.06),rgba(124,58,237,.07),rgba(0,255,170,.04));border:1px solid rgba(0,212,255,.1);border-radius:var(--rx);padding:1.7rem 2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.8rem;position:relative;overflow:hidden;animation:fadeUp .4s ease both}
.hero::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon));background-size:200%;animation:gradMove 4s ease infinite}
.hero::after{content:'';position:absolute;right:-50px;top:-50px;width:200px;height:200px;background:radial-gradient(circle,rgba(0,212,255,.07),transparent 70%);pointer-events:none}
.hero-t{font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800;margin-bottom:4px}
.hero-s{font-size:.8rem;color:var(--ts);line-height:1.5}
.hero-pill{display:inline-flex;align-items:center;gap:5px;font-family:'Space Mono',monospace;font-size:.62rem;padding:3px 10px;border-radius:100px;background:rgba(0,255,170,.08);color:var(--neon);border:1px solid rgba(0,255,170,.18);margin-top:7px;text-transform:uppercase}

/* ── STATS GRID ── */
.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.9rem;margin-bottom:1.8rem}
.sc{background:var(--card);border:1px solid var(--b);border-radius:var(--rm);padding:1.2rem;transition:transform .22s,border-color .22s,box-shadow .22s;position:relative;overflow:hidden;animation:fadeUp .5s ease both}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--a1,#fff),var(--a2,#888));opacity:0;transition:opacity .3s}
.sc:hover{transform:translateY(-4px);border-color:rgba(255,255,255,.1);box-shadow:var(--sc)}
.sc:hover::before{opacity:1}
.sc:nth-child(1){--a1:var(--cyan);--a2:var(--violet);animation-delay:.04s}
.sc:nth-child(2){--a1:var(--violet);--a2:var(--rose);animation-delay:.08s}
.sc:nth-child(3){--a1:var(--neon);--a2:var(--cyan);animation-delay:.12s}
.sc:nth-child(4){--a1:var(--amber);--a2:var(--neon);animation-delay:.16s}
.sc:nth-child(5){--a1:var(--rose);--a2:var(--amber);animation-delay:.2s}
.sc:nth-child(6){--a1:var(--cyan);--a2:var(--neon);animation-delay:.24s}
.sc-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.8rem}
.sc-val{font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--a1,#fff),var(--a2,#aaa));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sc-lbl{font-size:.73rem;color:var(--ts);margin-top:4px;font-weight:500}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--r);font-family:'Syne',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .18s;border:none;white-space:nowrap;text-decoration:none}
.btn-sm{padding:5px 11px;font-size:.7rem}
.btn-xs{padding:3px 8px;font-size:.65rem}
.btn-p{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff;box-shadow:0 4px 14px rgba(0,212,255,.18)}
.btn-p:hover{opacity:.87;transform:translateY(-1px)}
.btn-g{background:var(--card);border:1px solid var(--b);color:var(--ts)}
.btn-g:hover{color:var(--tp);border-color:rgba(255,255,255,.14);background:var(--card-h)}
.btn-neon{background:rgba(0,255,170,.08);border:1px solid rgba(0,255,170,.2);color:var(--neon)}
.btn-neon:hover{background:rgba(0,255,170,.15)}
.btn-danger{background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);color:var(--rose)}
.btn-danger:hover{background:rgba(244,63,94,.15)}
.btn-fav{background:none;border:none;color:var(--tm);font-size:1.1rem;padding:4px;transition:all .2s;border-radius:6px;cursor:pointer;line-height:1}
.btn-fav:hover,.btn-fav.on{color:var(--rose);transform:scale(1.2)}
.btn-fav.on{text-shadow:0 0 12px rgba(244,63,94,.5)}

/* ── CHIPS ── */
.chip{display:inline-flex;align-items:center;gap:3px;font-size:.6rem;font-family:'Space Mono',monospace;padding:2px 8px;border-radius:100px;font-weight:700;text-transform:uppercase}
.chip-c{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.chip-n{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.chip-v{background:rgba(124,58,237,.1);color:var(--plum);border:1px solid rgba(124,58,237,.2)}
.chip-a{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.chip-r{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.chip-m{background:rgba(255,255,255,.05);color:var(--tm);border:1px solid var(--b)}

/* ── FILTERS ── */
.filters{display:flex;align-items:center;gap:.7rem;margin-bottom:1.4rem;flex-wrap:wrap}
.f-search{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--b);border-radius:var(--r);padding:6px 12px;flex:1;min-width:200px;transition:border-color .2s,box-shadow .2s}
.f-search:focus-within{border-color:var(--ba);box-shadow:var(--gc)}
.f-search input{background:none;border:none;outline:none;color:var(--tp);font-size:.8rem;font-family:'DM Sans',sans-serif;width:100%}
.f-search input::placeholder{color:var(--tm)}
.f-btn{padding:5px 12px;border-radius:100px;border:1px solid var(--b);background:var(--card);color:var(--ts);font-size:.7rem;font-family:'Space Mono',monospace;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.04em}
.f-btn:hover,.f-btn.on{border-color:var(--cyan);color:var(--cyan);background:rgba(0,212,255,.06)}
.f-sort{background:var(--card);border:1px solid var(--b);border-radius:var(--r);color:var(--ts);font-family:'Space Mono',monospace;font-size:.7rem;padding:6px 10px;cursor:pointer;outline:none;transition:border-color .2s}
.f-sort:focus{border-color:var(--ba)}

/* ── SECTION HEADER ── */
.sec-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.sec-t{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;display:flex;align-items:center;gap:8px}

/* ── BOOKS GRID ── */
.bk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:1.1rem}

/* ── BOOK CARD ── */
.bk{background:var(--card);border:1px solid var(--b);border-radius:var(--rl);overflow:hidden;transition:transform .25s var(--ease),border-color .22s,box-shadow .22s;position:relative;animation:fadeUp .5s ease both}
.bk:hover{transform:translateY(-6px) scale(1.015);border-color:rgba(0,212,255,.2);box-shadow:0 20px 50px rgba(0,0,0,.5)}
.bk-cover{height:120px;display:flex;align-items:center;justify-content:center;font-size:2.8rem;position:relative;overflow:hidden;background:linear-gradient(135deg,rgba(13,16,32,.9),rgba(124,58,237,.18))}
.bk-cover img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0}
.bk-cover-emoji{position:relative;z-index:1;filter:drop-shadow(0 4px 12px rgba(0,0,0,.4));transition:transform .3s var(--ease)}
.bk:hover .bk-cover-emoji{transform:scale(1.15) rotate(-4deg)}
.bk-vignette{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(5,8,15,.85));z-index:2}
.bk-badges{position:absolute;top:8px;left:8px;display:flex;gap:4px;z-index:3;flex-wrap:wrap}
.bk-badge{font-size:.52rem;padding:2px 7px;border-radius:100px;font-family:'Space Mono',monospace;font-weight:700;text-transform:uppercase}
.bb-premium{background:rgba(124,58,237,.5);color:#fff;border:1px solid rgba(0,212,255,.3)}
.bb-free{background:rgba(0,255,170,.15);color:var(--neon);border:1px solid rgba(0,255,170,.3)}
.bb-done{background:rgba(0,255,170,.2);color:var(--neon);border:1px solid rgba(0,255,170,.4)}
.bb-top{background:rgba(245,158,11,.15);color:var(--amber);border:1px solid rgba(245,158,11,.3)}
.bk-fav-wrap{position:absolute;top:8px;right:8px;z-index:3}
.bk-body{padding:10px 13px 0}
.bk-cat{font-family:'Space Mono',monospace;font-size:.55rem;color:var(--cyan);letter-spacing:.06em;text-transform:uppercase}
.bk-title{font-family:'Syne',sans-serif;font-size:.85rem;font-weight:700;margin:3px 0 2px;line-height:1.2;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.bk-author{font-size:.7rem;color:var(--ts)}
.bk-prog-wrap{padding:10px 13px}
.bk-prog-row{display:flex;justify-content:space-between;margin-bottom:5px;font-size:.65rem}
.bk-pct{font-family:'Space Mono',monospace;color:var(--neon);font-weight:700}
.bk-pg{color:var(--tm)}
.prog-bar{height:5px;background:rgba(255,255,255,.07);border-radius:100px;overflow:hidden}
.prog-fill{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--cyan),var(--violet));transition:width 1.2s var(--ease);box-shadow:0 0 8px rgba(0,212,255,.35)}
.prog-fill.done{background:linear-gradient(90deg,var(--neon),var(--cyan))}
.bk-meta{display:flex;justify-content:space-between;padding:0 13px 8px;font-size:.62rem;color:var(--tm);font-family:'Space Mono',monospace}
.bk-foot{padding:9px 13px 13px;border-top:1px solid rgba(255,255,255,.04);display:flex;gap:6px}
.bk-foot .btn-read{flex:1;justify-content:center}

/* ── EMPTY STATE ── */
.empty{text-align:center;padding:3rem 1rem}
.empty-ico{font-size:3.2rem;margin-bottom:.8rem;opacity:.5;display:block}
.empty-t{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;margin-bottom:.4rem}
.empty-s{font-size:.8rem;color:var(--tm);margin-bottom:1.2rem}

/* ── DASHBOARD GRID ── */
.dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:1.5rem}
@media(max-width:1100px){.dash-grid{grid-template-columns:1fr}}

/* ── CARD ── */
.card{background:var(--card);border:1px solid var(--b);border-radius:var(--rl);overflow:hidden;animation:fadeUp .5s ease both}
.card-h{padding:1.1rem 1.4rem;border-bottom:1px solid var(--b);display:flex;align-items:center;justify-content:space-between;gap:.8rem}
.card-t{font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:8px}
.card-b{padding:1.1rem 1.4rem}
.card-ico{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem}

/* ── ACTIVITÉ CHART ── */
.wk-chart{display:flex;align-items:flex-end;gap:6px;height:70px;padding:6px 0}
.wk-bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px}
.wk-b{width:100%;border-radius:4px 4px 0 0;background:linear-gradient(to top,var(--cyan),rgba(0,212,255,.2));min-height:4px;transition:height .9s var(--ease);position:relative;cursor:pointer}
.wk-b:hover{filter:brightness(1.3)}
.wk-b.today{background:linear-gradient(to top,var(--neon),rgba(0,255,170,.25))}
.wk-b .tip{position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);font-family:'Space Mono',monospace;font-size:.52rem;color:var(--tp);white-space:nowrap;background:var(--surf);border:1px solid var(--b);padding:2px 5px;border-radius:4px;opacity:0;transition:opacity .18s;pointer-events:none}
.wk-b:hover .tip{opacity:1}
.wk-lbl{font-family:'Space Mono',monospace;font-size:.52rem;color:var(--tm)}

/* ── SIGNETS ── */
.bm-list{display:flex;flex-direction:column;gap:5px}
.bm-item{display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(255,255,255,.024);border:1px solid rgba(255,255,255,.04);border-radius:var(--r);transition:background .15s;cursor:pointer}
.bm-item:hover{background:var(--card-h)}
.bm-pg{width:34px;height:34px;border-radius:8px;background:rgba(0,212,255,.1);color:var(--cyan);display:flex;align-items:center;justify-content:center;font-family:'Space Mono',monospace;font-size:.65rem;font-weight:700;flex-shrink:0}
.bm-info{flex:1;min-width:0}
.bm-t{font-size:.77rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bm-n{font-size:.62rem;color:var(--tm);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.bm-date{font-size:.58rem;font-family:'Space Mono',monospace;color:var(--tm);flex-shrink:0}

/* ── NOTIF PANEL ── */
#np{position:fixed;top:var(--th);right:1rem;width:300px;background:var(--surf);border:1px solid var(--b);border-radius:var(--rl);box-shadow:var(--sl);z-index:500;transform:translateY(-10px) scale(.97);opacity:0;pointer-events:none;transition:all .22s var(--ease);overflow:hidden}
#np.open{transform:translateY(6px) scale(1);opacity:1;pointer-events:all}
.np-h{padding:.9rem 1.1rem;border-bottom:1px solid var(--b);display:flex;align-items:center;justify-content:space-between;font-family:'Syne',sans-serif;font-weight:700;font-size:.83rem}
.np-list{max-height:280px;overflow-y:auto}
.np-item{display:flex;gap:10px;padding:10px 1.1rem;border-bottom:1px solid rgba(255,255,255,.04);font-size:.76px;cursor:pointer;transition:background .12s}
.np-item:hover{background:var(--card-h)}
.np-item.unread{background:rgba(0,212,255,.03)}
.np-ico{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;background:rgba(0,212,255,.08)}
.np-txt{color:var(--ts);line-height:1.45;font-size:.74rem}
.np-time{font-size:.58rem;font-family:'Space Mono',monospace;color:var(--tm);margin-top:2px}

/* ── TOAST ── */
#ts{position:fixed;bottom:1.4rem;right:1.4rem;z-index:9000;display:flex;flex-direction:column-reverse;gap:7px;pointer-events:none}
.toast{display:flex;align-items:center;gap:9px;padding:10px 14px;background:var(--surf);border:1px solid var(--b);border-radius:var(--rm);box-shadow:var(--sl);font-size:.78rem;max-width:295px;pointer-events:all;transform:translateX(110px);opacity:0;transition:all .35s var(--ease)}
.toast.show{transform:translateX(0);opacity:1}
.t-ico{font-size:1.05rem;flex-shrink:0}
.t-ttl{font-family:'Syne',sans-serif;font-weight:700}
.t-sub{color:var(--tm);font-size:.7rem;margin-top:1px}
.t-x{color:var(--tm);cursor:pointer;font-size:.78rem;flex-shrink:0}

/* ═══════════════════════════════════════
   PDF READER MODAL
═══════════════════════════════════════ */
#reader-modal{position:fixed;inset:0;z-index:2000;background:rgba(2,4,10,.95);backdrop-filter:blur(20px);display:flex;flex-direction:column;opacity:0;pointer-events:none;transition:opacity .3s ease}
#reader-modal.open{opacity:1;pointer-events:all}

.rd-top{height:54px;background:rgba(5,8,15,.92);border-bottom:1px solid var(--b);display:flex;align-items:center;gap:5px;padding:0 .8rem;flex-shrink:0;overflow-x:auto}
.rd-top::-webkit-scrollbar{height:0}
.rd-btn{width:32px;height:32px;border-radius:8px;background:var(--card);border:1px solid var(--b);color:var(--ts);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.85rem;transition:all .15s;flex-shrink:0}
.rd-btn:hover{color:var(--tp);background:var(--card-h)}
.rd-btn.on{color:var(--neon);border-color:rgba(0,255,170,.3);background:rgba(0,255,170,.08)}
.rd-sep{width:1px;height:22px;background:var(--b);margin:0 2px;flex-shrink:0}
.rd-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem;color:var(--tp);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0 .5rem;min-width:0}
.rd-page-inp{width:44px;background:var(--card);border:1px solid var(--b);border-radius:6px;color:var(--tp);font-family:'Space Mono',monospace;font-size:.7rem;padding:4px 6px;text-align:center;outline:none;transition:border-color .2s}
.rd-page-inp:focus{border-color:var(--ba)}
.rd-total{font-family:'Space Mono',monospace;font-size:.65rem;color:var(--tm);white-space:nowrap;flex-shrink:0}
.rd-zoom-lbl{font-family:'Space Mono',monospace;font-size:.62rem;color:var(--tm);min-width:34px;text-align:center;flex-shrink:0}

/* Progress bar lecteur */
.rd-prog{height:3px;background:rgba(255,255,255,.07);flex-shrink:0}
.rd-prog-fill{height:100%;background:linear-gradient(90deg,var(--cyan),var(--violet));transition:width .4s ease;box-shadow:0 0 6px rgba(0,212,255,.5)}

/* Body lecteur */
.rd-body{flex:1;display:flex;overflow:hidden;position:relative}

/* Sidebar signets dans lecteur */
.rd-sb{width:210px;background:rgba(5,8,15,.8);border-right:1px solid var(--b);display:flex;flex-direction:column;flex-shrink:0;overflow:hidden;transition:width .3s ease}
.rd-sb.closed{width:0;border-right:none}
.rd-sb-inner{width:210px;overflow-y:auto;padding:12px}
.rd-sb-sect{font-family:'Space Mono',monospace;font-size:.58rem;text-transform:uppercase;letter-spacing:.1em;color:var(--tm);margin-bottom:7px}
.rd-bm-item{display:flex;align-items:center;gap:7px;padding:6px 7px;border-radius:6px;cursor:pointer;transition:background .12s;font-size:.73rem}
.rd-bm-item:hover{background:var(--card-h)}
.rd-bm-pg{font-family:'Space Mono',monospace;font-size:.62rem;color:var(--cyan);flex-shrink:0;min-width:28px}

/* Canvas area */
.rd-canvas-area{flex:1;overflow:auto;display:flex;flex-direction:column;align-items:center;padding:1rem;gap:.8rem;position:relative}
#pdf-canvas{max-width:100%;border-radius:4px;box-shadow:0 8px 40px rgba(0,0,0,.6);transition:filter .3s}
#pdf-canvas.night{filter:invert(1) hue-rotate(180deg) sepia(.1)}
#pdf-canvas.sepia{filter:sepia(.5) brightness(.9)}

/* Loader PDF */
.rd-loader{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(5,8,15,.7);z-index:10}
.rd-loader-ring{width:46px;height:46px;border:3px solid rgba(0,212,255,.2);border-top-color:var(--cyan);border-radius:50%;animation:spin 1s linear infinite}

/* Contenu demo (sans PDF) */
#rd-demo{display:none;max-width:680px;width:100%;padding:2rem;animation:fadeUp .3s ease}
#rd-demo-title{font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;margin-bottom:1rem;color:var(--cyan)}
#rd-demo-body{font-size:.95rem;line-height:1.9;color:var(--ts)}
#rd-demo-body h2{font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:700;margin:1.5rem 0 .8rem;color:var(--tp);border-bottom:1px solid rgba(255,255,255,.06);padding-bottom:.5rem}
#rd-demo-body p{margin-bottom:1rem;text-indent:1.5em}
#rd-demo-body p:first-of-type{text-indent:0}
.rd-demo-nav{display:flex;align-items:center;justify-content:space-between;margin-top:2rem;padding-top:1rem;border-top:1px solid var(--b)}

/* Search bar in reader */
.rd-search{display:none;align-items:center;gap:6px;padding:6px 10px;background:rgba(245,158,11,.05);border-bottom:1px solid rgba(245,158,11,.15);flex-shrink:0}
.rd-search.open{display:flex}
.rd-search input{flex:1;background:none;border:none;outline:none;color:var(--tp);font-size:.78rem;font-family:'DM Sans',sans-serif}
.rd-search input::placeholder{color:var(--tm)}
.rd-search-res{font-family:'Space Mono',monospace;font-size:.6rem;color:var(--tm);flex-shrink:0}

/* Bottom bar lecteur */
.rd-bottom{height:42px;background:rgba(5,8,15,.92);border-top:1px solid var(--b);display:flex;align-items:center;justify-content:space-between;padding:0 1.2rem;flex-shrink:0;font-size:.7rem;color:var(--tm);font-family:'Space Mono',monospace}
.rd-save-dot{width:6px;height:6px;border-radius:50%;background:var(--neon);animation:pulse 2s infinite;display:inline-block;margin-right:4px}

/* Bookmark quick-add popup */
#bm-popup{position:fixed;top:calc(var(--th) + 65px);right:1rem;z-index:2100;background:var(--surf);border:1px solid var(--ba);border-radius:var(--rm);padding:14px 16px;width:240px;box-shadow:var(--sl);transform:translateY(-10px);opacity:0;pointer-events:none;transition:all .25s var(--ease)}
#bm-popup.open{transform:translateY(0);opacity:1;pointer-events:all}
#bm-popup h3{font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem;margin-bottom:8px}
#bm-popup input,#bm-popup textarea{width:100%;background:var(--card);border:1px solid var(--b);border-radius:6px;color:var(--tp);font-family:'DM Sans',sans-serif;font-size:.75rem;padding:6px 9px;outline:none;margin-bottom:6px;resize:none;transition:border-color .2s}
#bm-popup input:focus,#bm-popup textarea:focus{border-color:var(--ba)}
#bm-popup-btns{display:flex;gap:5px;justify-content:flex-end}

/* Mobile sidebar overlay */
#sb-ov{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;opacity:0;pointer-events:none;transition:opacity .3s}
#sb-ov.show{opacity:1;pointer-events:all}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  #sb{transform:translateX(-100%)}
  #sb.mob{transform:translateX(0)}
  .main{margin-left:0!important}
  .tb-ham{display:flex}
  .page{padding:1.2rem .9rem}
  .dash-grid{grid-template-columns:1fr}
  .bk-grid{grid-template-columns:repeat(auto-fill,minmax(170px,1fr))}
}
@media(max-width:480px){
  .bk-grid{grid-template-columns:1fr 1fr}
  .filters{flex-direction:column;align-items:stretch}
}
</style>
</head>
<body>
<div class="app">

<!-- ══════════ SIDEBAR ══════════ -->
<aside id="sb">
  <div class="sb-brand">
    <div class="sb-icon">📚</div>
    <div class="sb-txt">Digital <em>Library</em></div>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= $avatar ?></div>
    <div style="min-width:0">
      <div class="sb-uname"><?= safeOut($username) ?></div>
      <div class="sb-role">📖 <?= safeOut($userRole) ?></div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="nav-sect">Principal</div>
    <a href="../dashboard.php" class="nav-a"><span class="nav-ico"><i class="bi bi-grid-1x2"></i></span> Dashboard</a>
    <a href="../users/profile.php" class="nav-a"><span class="nav-ico"><i class="bi bi-person"></i></span> Mon profil</a>
    <div class="nav-sect">Bibliothèque</div>
    <a href="index.php" class="nav-a"><span class="nav-ico"><i class="bi bi-compass"></i></span> Catalogue</a>
    <a href="my_library.php" class="nav-a"><span class="nav-ico"><i class="bi bi-bookmark-heart"></i></span> Ma bibliothèque</a>
    <a href="reading.php" class="nav-a on"><span class="nav-ico"><i class="bi bi-book-fill"></i></span> En cours
      <?php if ($stats['en_cours'] > 0): ?><span class="nav-badge"><?= $stats['en_cours'] ?></span><?php endif; ?>
    </a>
    <div class="nav-sect">Personnel</div>
    <a href="../users/purchases.php" class="nav-a"><span class="nav-ico"><i class="bi bi-bag-check"></i></span> Mes achats</a>
    <a href="../users/bonus.php" class="nav-a"><span class="nav-ico"><i class="bi bi-gift"></i></span> Mes bonus</a>
    <a href="favorites.php" class="nav-a"><span class="nav-ico"><i class="bi bi-star"></i></span> Favoris
      <?php if ($stats['favoris'] > 0): ?><span class="nav-badge"><?= $stats['favoris'] ?></span><?php endif; ?>
    </a>
    <div class="nav-sect">Notifications</div>
    <a href="../admin/notifications.php" class="nav-a"><span class="nav-ico"><i class="bi bi-bell"></i></span> Notifications
      <?php if ($notifCount > 0): ?><span class="nav-badge"><?= $notifCount ?></span><?php endif; ?>
    </a>
  </nav>
  <div class="sb-foot">
    <a href="../logout.php" class="sb-logout"><i class="bi bi-box-arrow-left"></i> Déconnexion</a>
  </div>
</aside>
<div id="sb-ov" onclick="closeMob()"></div>

<!-- ══════════ MAIN ══════════ -->
<div class="main">
  <!-- TOPBAR -->
  <header id="topbar">
    <button type="button" class="tb-ham" onclick="toggleMob()"><i class="bi bi-list"></i></button>
    <div class="bc">
      <a href="../dashboard.php">DLS</a><span class="bc-sep">/</span>
      <a href="index.php">Livres</a><span class="bc-sep">/</span>
      <span class="bc-curr">Lectures en cours</span>
    </div>
    <div class="sp"></div>
    <button type="button" class="tb-btn" id="nb-btn" onclick="toggleNP()">
      <i class="bi bi-bell"></i>
      <?php if ($notifCount > 0): ?><span class="notif-dot"><?= min($notifCount, 9) ?></span><?php endif; ?>
    </button>
    <a href="../users/profile.php" class="tb-user-chip">
      <div class="tb-av-sm"><?= $avatar ?></div>
      <div>
        <div class="tb-uname"><?= safeOut($firstName) ?></div>
        <div class="tb-role"><?= safeOut($userRole) ?></div>
      </div>
    </a>
    <a href="../logout.php" class="tb-btn" title="Déconnexion"><i class="bi bi-box-arrow-right"></i></a>
  </header>

  <!-- PAGE CONTENT -->
  <main class="page">

    <!-- HERO -->
    <div class="hero">
      <div>
        <div class="hero-t">📖 Lectures en cours</div>
        <div class="hero-s">
          <?php if ($stats['en_cours'] > 0 || $stats['termines'] > 0): ?>
            <?= $stats['en_cours'] ?> en cours · <?= $stats['termines'] ?> terminé<?= $stats['termines'] > 1 ? 's' : '' ?> · <?= fmtTempsRd($stats['temps_total']) ?> de lecture
          <?php else: ?>
            Commencez votre parcours de lecture dès aujourd'hui
          <?php endif; ?>
        </div>
        <div class="hero-pill"><i class="bi bi-lightning-fill"></i> Reprise automatique · Synchronisation temps réel</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;flex-shrink:0">
        <a href="index.php" class="btn btn-p"><i class="bi bi-compass"></i> Explorer</a>
      </div>
    </div>

    <!-- STATS -->
    <div class="sg">
      <div class="sc"><div class="sc-icon" style="background:rgba(0,212,255,.1)">📖</div><div class="sc-val" id="st-en-cours"><?= $stats['en_cours'] ?></div><div class="sc-lbl">En cours</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(0,255,170,.08)">✅</div><div class="sc-val" id="st-termines"><?= $stats['termines'] ?></div><div class="sc-lbl">Terminés</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(124,58,237,.1)">📄</div><div class="sc-val" id="st-pages"><?= number_format($stats['pages_lues']) ?></div><div class="sc-lbl">Pages lues</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(245,158,11,.1)">⏱️</div><div class="sc-val" id="st-temps"><?= safeOut(fmtTempsRd($stats['temps_total'])) ?></div><div class="sc-lbl">Temps total</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(244,63,94,.08)">🔥</div><div class="sc-val" id="st-streak"><?= $stats['streak'] ?></div><div class="sc-lbl">Jours actifs</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(244,63,94,.08)">❤️</div><div class="sc-val" id="st-fav"><?= $stats['favoris'] ?></div><div class="sc-lbl">Favoris</div></div>
    </div>

    <!-- FILTERS -->
    <div class="filters">
      <div class="f-search">
        <i class="bi bi-search" style="color:var(--tm);font-size:.85rem"></i>
        <input type="search" id="f-search-inp" placeholder="Rechercher dans mes lectures…" autocomplete="off">
      </div>
      <div style="display:flex;gap:5px;flex-wrap:wrap">
        <button class="f-btn on" data-filter="all">Tous</button>
        <button class="f-btn" data-filter="progress">En cours</button>
        <button class="f-btn" data-filter="done">Terminés</button>
        <button class="f-btn" data-filter="fav">Favoris</button>
        <button class="f-btn" data-filter="premium">Premium</button>
        <button class="f-btn" data-filter="free">Gratuit</button>
      </div>
      <select class="f-sort" id="f-sort">
        <option value="recent">Récemment lus</option>
        <option value="pct-desc">Progression ↓</option>
        <option value="pct-asc">Progression ↑</option>
        <option value="alpha">Alphabétique</option>
        <option value="fav">Favoris d'abord</option>
      </select>
    </div>

    <!-- ── LIVRES EN COURS ── -->
    <div class="sec-h">
      <div class="sec-t"><span>📖</span> En cours <span class="chip chip-c"><?= count($livresEnCours) ?></span></div>
    </div>

    <?php if (empty($livresEnCours)): ?>
    <div class="empty" style="margin-bottom:1.5rem">
      <span class="empty-ico">📚</span>
      <div class="empty-t">Aucune lecture en cours</div>
      <div class="empty-s">Commencez à lire un livre depuis votre bibliothèque ou le catalogue</div>
      <a href="index.php" class="btn btn-p"><i class="bi bi-compass"></i> Explorer le catalogue</a>
    </div>
    <?php else: ?>
    <div class="bk-grid" id="bk-container" style="margin-bottom:2rem">
      <?php foreach ($livresEnCours as $i => $livre):
        $pct    = round((float)($livre['pourcentage'] ?? 0), 1);
        $page   = (int)($livre['page_actuelle'] ?? 0);
        $totalP = max(1, (int)($livre['total_pages'] ?? $livre['pages'] ?? 100));
        if ($pct == 0 && $page > 0) $pct = round(($page / $totalP) * 100, 1);
        $emoji    = bookEmojiRd($livre['categorie_slug'] ?? '', (int)$livre['id']);
        $isFav    = (bool)($livre['is_favorite'] ?? false);
        $hasCover = !empty($livre['couverture']);
        $hasPDF   = !empty($livre['fichier_pdf']);
        $accessT  = $livre['access_type'] ?? 'standard';
        $tpsR     = tempsRestantRd($page, $totalP);
        $dernier  = timeAgoRd($livre['derniere_lecture'] ?? '');
      ?>
      <div class="bk"
           data-id="<?= (int)$livre['id'] ?>"
           data-pct="<?= $pct ?>"
           data-fav="<?= $isFav ? '1' : '0' ?>"
           data-access="<?= safeOut($accessT) ?>"
           data-status="progress"
           data-title="<?= safeOut($livre['titre'] ?? '') ?>"
           style="animation-delay:<?= $i * 0.045 ?>s">
        <!-- Cover -->
        <div class="bk-cover">
          <?php if ($hasCover): ?>
            <img src="../<?= safeOut($livre['couverture']) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
          <?php endif; ?>
          <div class="bk-cover-emoji"><?= $emoji ?></div>
          <div class="bk-vignette"></div>
          <!-- Badges -->
          <div class="bk-badges">
            <?php if ($accessT === 'premium'): ?><span class="bk-badge bb-premium">💎 Premium</span><?php endif; ?>
            <?php if ($accessT === 'gratuit' || (float)($livre['prix'] ?? 0) == 0): ?><span class="bk-badge bb-free">✓ Gratuit</span><?php endif; ?>
            <?php if (!empty($livre['is_bestseller'])): ?><span class="bk-badge bb-top">🔥 Best</span><?php endif; ?>
            <?php if (!empty($livre['is_featured'])): ?><span class="bk-badge bb-top">⭐ Top</span><?php endif; ?>
          </div>
          <!-- Favori -->
          <div class="bk-fav-wrap">
            <button type="button"
                    class="btn-fav <?= $isFav ? 'on' : '' ?>"
                    data-livre-id="<?= (int)$livre['id'] ?>"
                    onclick="toggleFav(this)"
                    title="<?= $isFav ? 'Retirer des favoris' : 'Ajouter aux favoris' ?>">
              <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
            </button>
          </div>
        </div>
        <!-- Body -->
        <div class="bk-body">
          <div class="bk-cat"><?= safeOut(($livre['categorie_icone'] ?? '📚') . ' ' . ($livre['categorie_nom'] ?? 'Général')) ?></div>
          <div class="bk-title" title="<?= safeOut($livre['titre'] ?? '') ?>"><?= safeOut($livre['titre'] ?? '—') ?></div>
          <div class="bk-author"><?= safeOut($livre['auteur'] ?? '') ?></div>
        </div>
        <!-- Progression -->
        <div class="bk-prog-wrap">
          <div class="bk-prog-row">
            <span class="bk-pct"><?= $pct ?>%</span>
            <span class="bk-pg">p.<?= $page ?>/<?= $totalP ?></span>
          </div>
          <div class="prog-bar"><div class="prog-fill" style="width:<?= min(100, $pct) ?>%"></div></div>
        </div>
        <!-- Méta -->
        <div class="bk-meta">
          <span title="Temps restant estimé">⏱ <?= safeOut($tpsR) ?></span>
          <span title="Dernière lecture">🕐 <?= safeOut($dernier) ?></span>
        </div>
        <!-- Actions -->
        <div class="bk-foot">
          <button type="button" class="btn btn-p btn-sm btn-read"
                  onclick="openBook(<?= (int)$livre['id'] ?>,<?= (int)$page ?>,<?= (int)$totalP ?>,<?= json_encode($livre['fichier_pdf'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,<?= json_encode($livre['titre'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)">
            <i class="bi bi-book-fill"></i> <?= $page > 0 ? 'Continuer' : 'Lire' ?>
          </button>
          <button type="button" class="btn btn-g btn-sm"
                  onclick="openBmPopup(<?= (int)$livre['id'] ?>,<?= (int)$page ?>,<?= json_encode($livre['titre'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)"
                  title="Ajouter un signet">
            <i class="bi bi-bookmark-plus"></i>
          </button>
          <a href="view.php?id=<?= (int)$livre['id'] ?>" class="btn btn-g btn-sm" title="Détails du livre"><i class="bi bi-info-circle"></i></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── LIVRES TERMINÉS ── -->
    <?php if (!empty($livresTermines)): ?>
    <div class="sec-h">
      <div class="sec-t"><span>✅</span> Terminés <span class="chip chip-n"><?= count($livresTermines) ?></span></div>
      <button type="button" class="btn btn-g btn-sm" onclick="toggleDone(this)">
        <i class="bi bi-chevron-up" id="done-ico"></i>
      </button>
    </div>
    <div id="done-sec" style="margin-bottom:2rem">
      <div class="bk-grid">
        <?php foreach ($livresTermines as $i => $livre):
          $emoji   = bookEmojiRd($livre['categorie_slug'] ?? '', (int)$livre['id']);
          $isFav   = (bool)($livre['is_favorite'] ?? false);
          $page    = (int)($livre['page_actuelle'] ?? 0);
          $totalP  = max(1, (int)($livre['total_pages'] ?? $livre['pages'] ?? 100));
          $hasCover = !empty($livre['couverture']);
        ?>
        <div class="bk"
             data-id="<?= (int)$livre['id'] ?>"
             data-pct="100"
             data-fav="<?= $isFav ? '1' : '0' ?>"
             data-access="<?= safeOut($livre['access_type'] ?? 'standard') ?>"
             data-status="done"
             data-title="<?= safeOut($livre['titre'] ?? '') ?>"
             style="opacity:.78;animation-delay:<?= $i * 0.04 ?>s">
          <div class="bk-cover">
            <?php if ($hasCover): ?>
              <img src="../<?= safeOut($livre['couverture']) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
            <?php endif; ?>
            <div class="bk-cover-emoji"><?= $emoji ?></div>
            <div class="bk-vignette"></div>
            <div class="bk-badges"><span class="bk-badge bb-done">✓ Terminé</span></div>
            <div class="bk-fav-wrap">
              <button type="button" class="btn-fav <?= $isFav ? 'on' : '' ?>" data-livre-id="<?= (int)$livre['id'] ?>" onclick="toggleFav(this)">
                <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
              </button>
            </div>
          </div>
          <div class="bk-body">
            <div class="bk-cat"><?= safeOut(($livre['categorie_icone'] ?? '📚') . ' ' . ($livre['categorie_nom'] ?? 'Général')) ?></div>
            <div class="bk-title"><?= safeOut($livre['titre'] ?? '—') ?></div>
            <div class="bk-author"><?= safeOut($livre['auteur'] ?? '') ?></div>
          </div>
          <div class="bk-prog-wrap">
            <div class="bk-prog-row"><span class="bk-pct" style="color:var(--neon)">100%</span><span class="bk-pg">Terminé</span></div>
            <div class="prog-bar"><div class="prog-fill done" style="width:100%"></div></div>
          </div>
          <div class="bk-foot">
            <button type="button" class="btn btn-g btn-sm btn-read"
                    onclick="openBook(<?= (int)$livre['id'] ?>,1,<?= (int)$totalP ?>,<?= json_encode($livre['fichier_pdf'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,<?= json_encode($livre['titre'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)">
              <i class="bi bi-arrow-clockwise"></i> Relire
            </button>
            <a href="view.php?id=<?= (int)$livre['id'] ?>" class="btn btn-g btn-sm"><i class="bi bi-info-circle"></i></a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── DASHBOARD ── -->
    <div class="dash-grid">
      <!-- Activité hebdo -->
      <div class="card" style="animation-delay:.1s">
        <div class="card-h">
          <div class="card-t"><div class="card-ico" style="background:rgba(0,212,255,.1)">📊</div>Activité 7 jours</div>
          <span class="chip chip-c">Semaine</span>
        </div>
        <div class="card-b">
          <?php
          $maxWP = max(1, ...array_column($weekActivity, 'pages') ?: [1]);
          ?>
          <div class="wk-chart">
            <?php foreach ($weekActivity as $day):
              $h = max(4, round(($day['pages'] / $maxWP) * 58));
            ?>
            <div class="wk-bar">
              <div class="wk-b <?= $day['today'] ? 'today' : '' ?>" style="height:<?= $h ?>px">
                <span class="tip"><?= (int)$day['pages'] ?> pages</span>
              </div>
              <div class="wk-lbl"><?= safeOut($day['label']) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;justify-content:space-between;margin-top:.7rem;font-size:.67rem;color:var(--tm);font-family:'Space Mono',monospace">
            <span>Total : <strong style="color:var(--neon)"><?= array_sum(array_column($weekActivity, 'pages')) ?> pages</strong></span>
            <span>Moy : <strong style="color:var(--cyan)"><?= round(array_sum(array_column($weekActivity, 'pages')) / 7, 1) ?>/j</strong></span>
          </div>
        </div>
      </div>

      <!-- Signets -->
      <div class="card" style="animation-delay:.15s">
        <div class="card-h">
          <div class="card-t"><div class="card-ico" style="background:rgba(245,158,11,.1)">🔖</div>Signets &amp; Notes</div>
          <span class="chip chip-a"><?= count($bookmarks) ?></span>
        </div>
        <div class="card-b">
          <?php if (empty($bookmarks)): ?>
          <div style="text-align:center;padding:1rem 0;color:var(--tm);font-size:.8rem">
            <div style="font-size:1.8rem;margin-bottom:.4rem">🔖</div>Aucun signet — ajoutez-en pendant la lecture
          </div>
          <?php else: ?>
          <div class="bm-list" id="bm-list">
            <?php foreach (array_slice($bookmarks, 0, 6) as $bm): ?>
            <div class="bm-item" onclick="jumpToBookmark(<?= (int)$bm['livre_id'] ?>,<?= (int)$bm['page_number'] ?>)">
              <div class="bm-pg">p.<?= (int)$bm['page_number'] ?></div>
              <div class="bm-info">
                <div class="bm-t"><?= safeOut($bm['livre_titre'] ?? '') ?></div>
                <div class="bm-n"><?= safeOut($bm['note'] ?? 'Pas de note') ?></div>
              </div>
              <div style="display:flex;align-items:center;gap:4px">
                <div class="bm-date"><?= safeOut(timeAgoRd($bm['created_at'] ?? '')) ?></div>
                <button type="button" class="btn btn-danger btn-xs" onclick="event.stopPropagation();deleteBm(<?= (int)$bm['id'] ?>,this)" title="Supprimer"><i class="bi bi-x"></i></button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── RECOMMANDATIONS ── -->
    <?php if (!empty($recommandations)): ?>
    <div class="sec-h">
      <div class="sec-t"><span>🤖</span> Recommandations <em style="color:var(--ts);font-size:.78rem;font-style:normal;font-weight:400;margin-left:4px">Personnalisées</em></div>
      <span class="chip chip-v">IA</span>
    </div>
    <div class="bk-grid" style="margin-bottom:2rem">
      <?php foreach ($recommandations as $i => $rec):
        $emoji  = bookEmojiRd('', (int)$rec['id']);
        $prix   = (float)($rec['prix'] ?? 0);
        $accessT = $rec['access_type'] ?? 'standard';
        $isFree = ($prix == 0 || $accessT === 'gratuit');
      ?>
      <div class="bk" style="opacity:.88;animation-delay:<?= $i * 0.05 ?>s">
        <div class="bk-cover">
          <div class="bk-cover-emoji"><?= $emoji ?></div>
          <div class="bk-vignette"></div>
          <div class="bk-badges">
            <?php if ($accessT === 'premium'): ?><span class="bk-badge bb-premium">💎 Premium</span><?php endif; ?>
            <?php if ($isFree): ?><span class="bk-badge bb-free">✓ Gratuit</span><?php endif; ?>
          </div>
        </div>
        <div class="bk-body">
          <div class="bk-cat"><?= safeOut(($rec['categorie_icone'] ?? '📚') . ' ' . ($rec['categorie_nom'] ?? 'Général')) ?></div>
          <div class="bk-title"><?= safeOut($rec['titre'] ?? '—') ?></div>
          <div class="bk-author"><?= safeOut($rec['auteur'] ?? '') ?></div>
        </div>
        <div class="bk-meta" style="padding:8px 13px">
          <span>⭐ <?= number_format((float)($rec['note_moyenne'] ?? 0), 1) ?></span>
          <span><?= $isFree ? 'Gratuit' : number_format($prix, 0, '', ' ') . ' FCFA' ?></span>
        </div>
        <div class="bk-foot">
          <?php if ($isFree): ?>
          <button type="button" class="btn btn-neon btn-sm btn-read"
                  onclick="openBook(<?= (int)$rec['id'] ?>,1,100,'',<?= json_encode($rec['titre'] ?? '', JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)">
            <i class="bi bi-book"></i> Lire
          </button>
          <?php else: ?>
          <a href="view.php?id=<?= (int)$rec['id'] ?>" class="btn btn-g btn-sm btn-read"><i class="bi bi-eye"></i> Voir</a>
          <?php endif; ?>
          <a href="view.php?id=<?= (int)$rec['id'] ?>" class="btn btn-g btn-sm"><i class="bi bi-info-circle"></i></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </main>
</div><!-- /main -->

<!-- ══════════ NOTIFICATIONS PANEL ══════════ -->
<div id="np">
  <div class="np-h">
    <span>Notifications</span>
    <?php if ($notifCount > 0): ?><span class="chip chip-r"><?= $notifCount ?></span><?php endif; ?>
  </div>
  <div class="np-list">
    <?php if (empty($notifications)): ?>
    <div style="padding:1.5rem;text-align:center;color:var(--tm);font-size:.8rem"><div style="font-size:1.8rem;margin-bottom:.4rem">🔔</div>Aucune notification</div>
    <?php else: foreach ($notifications as $n):
      $unread = !(bool)($n['lu'] ?? $n['is_read'] ?? false);
    ?>
    <div class="np-item <?= $unread ? 'unread' : '' ?>">
      <div class="np-ico"><?= safeOut($n['icon'] ?? '🔔') ?></div>
      <div>
        <?php if (!empty($n['titre'])): ?>
          <div class="np-txt" style="font-weight:600;color:var(--tp)"><?= safeOut($n['titre']) ?></div>
        <?php endif; ?>
        <div class="np-txt"><?= safeOut(mb_substr($n['message'] ?? '', 0, 80)) ?><?= mb_strlen($n['message'] ?? '') > 80 ? '…' : '' ?></div>
        <div class="np-time"><?= safeOut(timeAgoRd($n['created_at'] ?? '')) ?></div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <div style="padding:.7rem 1.1rem;border-top:1px solid var(--b);display:flex;gap:6px">
    <a href="../admin/notifications.php" class="btn btn-g btn-sm" style="flex:1;justify-content:center">Voir tout</a>
  </div>
</div>

<!-- ══════════ PDF READER MODAL ══════════ -->
<div id="reader-modal">
  <!-- Topbar lecteur -->
  <div class="rd-top">
    <button type="button" class="rd-btn" onclick="closeReader()" title="Fermer (Échap)"><i class="bi bi-x-lg"></i></button>
    <div class="rd-sep"></div>
    <!-- Sidebar toggle -->
    <button type="button" class="rd-btn" id="rd-sb-btn" onclick="toggleRdSb()" title="Signets"><i class="bi bi-bookmark"></i></button>
    <div class="rd-sep"></div>
    <!-- Navigation pages -->
    <button type="button" class="rd-btn" id="btn-prev" onclick="rdPrev()" title="Page précédente (←)"><i class="bi bi-chevron-left"></i></button>
    <input type="number" id="rd-page-inp" class="rd-page-inp" value="1" min="1" title="Aller à la page">
    <span class="rd-total">/ <span id="rd-tot">?</span></span>
    <button type="button" class="rd-btn" id="btn-next" onclick="rdNext()" title="Page suivante (→)"><i class="bi bi-chevron-right"></i></button>
    <div class="rd-sep"></div>
    <!-- Titre -->
    <span id="rd-title" class="rd-title">—</span>
    <div class="rd-sep"></div>
    <!-- Zoom -->
    <button type="button" class="rd-btn" onclick="rdZoom(-0.2)" title="Réduire (-)"><i class="bi bi-zoom-out"></i></button>
    <span id="rd-zoom-lbl" class="rd-zoom-lbl">100%</span>
    <button type="button" class="rd-btn" onclick="rdZoom(0.2)" title="Agrandir (+)"><i class="bi bi-zoom-in"></i></button>
    <div class="rd-sep"></div>
    <!-- Modes visuels -->
    <button type="button" class="rd-btn on" id="btn-normal" onclick="setMode('normal')" title="Mode normal"><i class="bi bi-sun"></i></button>
    <button type="button" class="rd-btn" id="btn-night" onclick="setMode('night')" title="Mode nuit"><i class="bi bi-moon-stars"></i></button>
    <button type="button" class="rd-btn" id="btn-sepia" onclick="setMode('sepia')" title="Sépia"><i class="bi bi-palette"></i></button>
    <div class="rd-sep"></div>
    <!-- Recherche -->
    <button type="button" class="rd-btn" onclick="toggleSearch()" title="Rechercher (Ctrl+F)"><i class="bi bi-search"></i></button>
    <!-- Signet rapide -->
    <button type="button" class="rd-btn" onclick="addBmFromReader()" title="Signet (Ctrl+S)"><i class="bi bi-bookmark-plus"></i></button>
    <!-- Télécharger -->
    <button type="button" class="rd-btn" onclick="downloadBook()" title="Télécharger"><i class="bi bi-cloud-download"></i></button>
    <!-- Plein écran -->
    <button type="button" class="rd-btn" onclick="toggleFS()" title="Plein écran (F)"><i class="bi bi-fullscreen" id="fs-ico"></i></button>
  </div>

  <!-- Progress bar -->
  <div class="rd-prog"><div class="rd-prog-fill" id="rd-prog" style="width:0%"></div></div>

  <!-- Recherche bar -->
  <div class="rd-search" id="rd-search">
    <i class="bi bi-search" style="color:var(--amber);font-size:.82rem;flex-shrink:0"></i>
    <input type="search" id="rd-search-inp" placeholder="Rechercher dans le document…">
    <button type="button" class="rd-btn" onclick="doSearch()" style="width:auto;padding:0 8px"><i class="bi bi-arrow-return-left"></i></button>
    <span id="rd-search-res" class="rd-search-res"></span>
    <button type="button" class="rd-btn" onclick="clearSearch()"><i class="bi bi-x"></i></button>
  </div>

  <!-- Body -->
  <div class="rd-body">
    <!-- Sidebar signets -->
    <div class="rd-sb" id="rd-sb-panel">
      <div class="rd-sb-inner">
        <div class="rd-sb-sect">📌 Signets</div>
        <div id="rd-bm-list"><div style="color:var(--tm);font-size:.72rem;text-align:center;padding:1rem">Aucun signet</div></div>
      </div>
    </div>

    <!-- Canvas / Contenu -->
    <div class="rd-canvas-area" id="rd-canvas-area">
      <div class="rd-loader" id="rd-loader">
        <div style="text-align:center">
          <div class="rd-loader-ring"></div>
          <div style="margin-top:.8rem;font-family:'Space Mono',monospace;font-size:.7rem;color:var(--tm)">Chargement…</div>
        </div>
      </div>
      <canvas id="pdf-canvas" style="display:none"></canvas>
      <div id="rd-demo">
        <div id="rd-demo-title">—</div>
        <div id="rd-demo-body"></div>
        <div class="rd-demo-nav">
          <button type="button" class="btn btn-g" onclick="rdPrev()"><i class="bi bi-chevron-left"></i> Précédent</button>
          <span id="rd-demo-pg" style="font-family:'Space Mono',monospace;font-size:.7rem;color:var(--tm)">Page 1</span>
          <button type="button" class="btn btn-p" onclick="rdNext()">Suivant <i class="bi bi-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Bottom bar -->
  <div class="rd-bottom">
    <div><span class="rd-save-dot"></span>Sauvegarde auto</div>
    <div id="rd-time-info" style="display:flex;gap:.5rem"><span id="rd-time-spent">0 min</span><span>·</span><span id="rd-time-rem">— restant</span></div>
    <div><span id="rd-pct-lbl">0%</span> lu</div>
  </div>
</div>

<!-- ══════════ BOOKMARK POPUP ══════════ -->
<div id="bm-popup">
  <h3>🔖 Ajouter un signet</h3>
  <input type="text" id="bm-page-disp" placeholder="Page" readonly>
  <textarea id="bm-note" rows="2" placeholder="Note personnelle (optionnel)…" maxlength="500"></textarea>
  <div id="bm-popup-btns">
    <button type="button" class="btn btn-g btn-sm" onclick="closeBmPopup()">Annuler</button>
    <button type="button" class="btn btn-p btn-sm" onclick="saveBm()"><i class="bi bi-floppy"></i> Enregistrer</button>
  </div>
</div>

<!-- TOAST STACK -->
<div id="ts"></div>

<!-- ══════════ SCRIPTS ══════════ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
/* ═══════════════════════════════════════════
   CONFIGURATION GLOBALE
═══════════════════════════════════════════ */
const CSRF    = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const SELF    = location.pathname; // URL du fichier courant pour les requêtes AJAX
const USER_ID = <?= (int)$userId ?>;

// Configurer PDF.js worker
if (typeof pdfjsLib !== 'undefined') {
  pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

/* ═══════════════════════════════════════════
   UTILITAIRES
═══════════════════════════════════════════ */
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtTime(s) {
  s = parseInt(s) || 0;
  if (s <= 0)  return '0 s';
  if (s < 60)  return s + 's';
  if (s < 3600) return Math.round(s / 60) + ' min';
  const h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60);
  return h + 'h' + (m > 0 ? m + 'min' : '');
}

/* ═══════════════════════════════════════════
   AJAX — appel unifié avec CSRF intégré
═══════════════════════════════════════════ */
async function callAPI(action, data = {}) {
  data.csrf = CSRF;
  const res = await fetch(`${SELF}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': CSRF,
    },
    body: JSON.stringify(data),
  });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  const json = await res.json();
  if (!json.success) throw new Error(json.error || 'Erreur serveur');
  return json;
}

async function callAPIGet(action, params = {}) {
  params.action = action;
  params.csrf   = CSRF;
  const qs = new URLSearchParams(params).toString();
  const res = await fetch(`${SELF}?${qs}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  const json = await res.json();
  if (!json.success) throw new Error(json.error || 'Erreur serveur');
  return json;
}

/* ═══════════════════════════════════════════
   TOAST
═══════════════════════════════════════════ */
const TICONS  = { success:'✅', error:'❌', warn:'⚠️', info:'ℹ️' };
const TCOLORS = { success:'var(--neon)', error:'var(--rose)', warn:'var(--amber)', info:'var(--cyan)' };

function toast(title, sub = '', type = 'info', dur = 3500) {
  const stack = document.getElementById('ts');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = TCOLORS[type] || TCOLORS.info;
  t.innerHTML = `<span class="t-ico">${TICONS[type] || 'ℹ️'}</span>
    <div style="flex:1"><div class="t-ttl">${esc(title)}</div>${sub ? `<div class="t-sub">${esc(sub)}</div>` : ''}</div>
    <span class="t-x" onclick="this.closest('.toast').remove()"><i class="bi bi-x"></i></span>`;
  stack.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, dur);
}

/* ═══════════════════════════════════════════
   SIDEBAR MOBILE
═══════════════════════════════════════════ */
function toggleMob() {
  document.getElementById('sb').classList.toggle('mob');
  document.getElementById('sb-ov').classList.toggle('show');
}
function closeMob() {
  document.getElementById('sb').classList.remove('mob');
  document.getElementById('sb-ov').classList.remove('show');
}

/* ═══════════════════════════════════════════
   NOTIFICATIONS PANEL
═══════════════════════════════════════════ */
function toggleNP() { document.getElementById('np').classList.toggle('open'); }
document.addEventListener('click', e => {
  const p = document.getElementById('np'), b = document.getElementById('nb-btn');
  if (p?.classList.contains('open') && !p.contains(e.target) && !b?.contains(e.target)) p.classList.remove('open');
});

/* ═══════════════════════════════════════════
   FILTRES & RECHERCHE
═══════════════════════════════════════════ */
let gFilter = 'all', gSort = 'recent';

// Bindings filtres
document.querySelectorAll('.f-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.f-btn').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
    gFilter = btn.dataset.filter;
    applyFilters();
  });
});
document.getElementById('f-sort').addEventListener('change', e => { gSort = e.target.value; applyFilters(); });

let searchTimer;
document.getElementById('f-search-inp').addEventListener('input', e => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(applyFilters, 280);
});

function applyFilters() {
  const q = (document.getElementById('f-search-inp').value || '').toLowerCase().trim();
  const container = document.getElementById('bk-container');
  if (!container) return;
  const cards = Array.from(container.querySelectorAll('.bk'));

  cards.forEach(card => {
    const title   = (card.dataset.title || '').toLowerCase();
    const status  = card.dataset.status || '';
    const fav     = card.dataset.fav === '1';
    const access  = card.dataset.access || '';

    let show = true;
    if (q && !title.includes(q)) show = false;
    if (gFilter === 'progress' && status !== 'progress') show = false;
    if (gFilter === 'done'     && status !== 'done')     show = false;
    if (gFilter === 'fav'      && !fav)                  show = false;
    if (gFilter === 'premium'  && access !== 'premium')  show = false;
    if (gFilter === 'free'     && access !== 'gratuit' && access !== 'free') show = false;
    card.style.display = show ? '' : 'none';
  });

  // Tri
  const visible = Array.from(container.querySelectorAll('.bk')).filter(c => c.style.display !== 'none');
  visible.sort((a, b) => {
    const pa = parseFloat(a.dataset.pct || 0), pb = parseFloat(b.dataset.pct || 0);
    const fa = a.dataset.fav === '1', fb = b.dataset.fav === '1';
    const ta = a.dataset.title || '', tb = b.dataset.title || '';
    if (gSort === 'pct-desc') return pb - pa;
    if (gSort === 'pct-asc')  return pa - pb;
    if (gSort === 'alpha')     return ta.localeCompare(tb, 'fr');
    if (gSort === 'fav')       return (fb ? 1 : 0) - (fa ? 1 : 0);
    return 0; // recent : ordre d'origine
  });
  visible.forEach(el => container.appendChild(el));
}

/* ═══════════════════════════════════════════
   TOGGLE SECTION TERMINÉS
═══════════════════════════════════════════ */
function toggleDone(btn) {
  const sec = document.getElementById('done-sec');
  const ico = document.getElementById('done-ico');
  if (!sec) return;
  const hidden = sec.style.display === 'none';
  sec.style.display = hidden ? '' : 'none';
  if (ico) ico.className = hidden ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}

/* ═══════════════════════════════════════════
   FAVORIS
═══════════════════════════════════════════ */
async function toggleFav(btn) {
  const livreId = parseInt(btn.dataset.livreId) || 0;
  if (!livreId) return;
  const wasOn = btn.classList.contains('on');
  const icon  = btn.querySelector('i');

  // Optimistic update
  btn.classList.toggle('on');
  if (icon) icon.className = btn.classList.contains('on') ? 'bi bi-heart-fill' : 'bi bi-heart';
  btn.title = btn.classList.contains('on') ? 'Retirer des favoris' : 'Ajouter aux favoris';
  const card = btn.closest('.bk');
  if (card) card.dataset.fav = btn.classList.contains('on') ? '1' : '0';

  try {
    const d = await callAPI('toggle_favorite', { livre_id: livreId });
    toast(d.favorited ? '❤️ Favori ajouté' : '💔 Retiré des favoris', '', 'success', 2000);
    // Mettre à jour compteur stat
    const stFav = document.getElementById('st-fav');
    if (stFav) stFav.textContent = d.total || '';
  } catch (e) {
    // Revert
    btn.classList.toggle('on');
    if (icon) icon.className = wasOn ? 'bi bi-heart-fill' : 'bi bi-heart';
    if (card) card.dataset.fav = wasOn ? '1' : '0';
    toast('Erreur', e.message, 'error');
  }
}

/* ═══════════════════════════════════════════
   SIGNETS
═══════════════════════════════════════════ */
let bmState = { livreId: 0, page: 1, titre: '' };

function openBmPopup(livreId, page, titre) {
  bmState = { livreId, page, titre };
  document.getElementById('bm-page-disp').value = 'Page ' + page;
  document.getElementById('bm-note').value = '';
  document.getElementById('bm-popup').classList.add('open');
  document.getElementById('bm-note').focus();
}
function closeBmPopup() { document.getElementById('bm-popup').classList.remove('open'); }

async function saveBm() {
  const note = document.getElementById('bm-note').value.trim();
  try {
    await callAPI('add_bookmark', { livre_id: bmState.livreId, page_number: bmState.page, note });
    toast('🔖 Signet ajouté', 'Page ' + bmState.page, 'success');
    closeBmPopup();
    refreshBmList();
    if (rdState.bookId === bmState.livreId) refreshRdBmList();
  } catch (e) {
    toast('Erreur', e.message, 'error');
  }
}

function addBmFromReader() {
  openBmPopup(rdState.bookId, rdState.page, rdState.title);
}

async function deleteBm(id, btn) {
  if (!confirm('Supprimer ce signet ?')) return;
  btn.disabled = true;
  try {
    await callAPI('delete_bookmark', { id });
    btn.closest('.bm-item')?.remove();
    toast('Signet supprimé', '', 'info', 2000);
    if (rdState.bookId) refreshRdBmList();
  } catch (e) {
    btn.disabled = false;
    toast('Erreur', e.message, 'error');
  }
}

async function refreshBmList() {
  try {
    const d = await callAPIGet('get_bookmarks', {});
    const list = document.getElementById('bm-list');
    if (!list || !d.bookmarks) return;
    if (!d.bookmarks.length) {
      list.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--tm);font-size:.8rem"><div style="font-size:1.8rem;margin-bottom:.4rem">🔖</div>Aucun signet</div>';
      return;
    }
    list.innerHTML = d.bookmarks.slice(0, 6).map(bm => `
      <div class="bm-item" onclick="jumpToBookmark(${bm.livre_id},${bm.page_number})">
        <div class="bm-pg">p.${bm.page_number}</div>
        <div class="bm-info">
          <div class="bm-t">${esc(bm.livre_titre || '')}</div>
          <div class="bm-n">${esc(bm.note || 'Pas de note')}</div>
        </div>
        <div style="display:flex;align-items:center;gap:4px">
          <button type="button" class="btn btn-danger btn-xs" onclick="event.stopPropagation();deleteBm(${bm.id},this)"><i class="bi bi-x"></i></button>
        </div>
      </div>`).join('');
  } catch(e) {}
}

async function refreshRdBmList() {
  try {
    const d = await callAPIGet('get_bookmarks', { livre_id: rdState.bookId });
    const list = document.getElementById('rd-bm-list');
    if (!list || !d.bookmarks) return;
    if (!d.bookmarks.length) {
      list.innerHTML = '<div style="color:var(--tm);font-size:.72rem;text-align:center;padding:.8rem">Aucun signet</div>';
      return;
    }
    list.innerHTML = d.bookmarks.map(bm => `
      <div class="rd-bm-item" onclick="goToPage(${bm.page_number})">
        <span class="rd-bm-pg">p.${bm.page_number}</span>
        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.72rem;color:var(--ts)">${esc(bm.note || 'Signet')}</span>
      </div>`).join('');
  } catch(e) {}
}

function jumpToBookmark(livreId, page) {
  if (rdState.bookId === livreId) {
    goToPage(page);
  } else {
    // Trouver la carte du livre et l'ouvrir
    const card = document.querySelector(`.bk[data-id="${livreId}"]`);
    const title = card ? card.dataset.title : '';
    openBook(livreId, page, 100, '', title);
  }
}

// Fermer bm-popup au clic extérieur
document.addEventListener('click', e => {
  const pop = document.getElementById('bm-popup');
  if (pop?.classList.contains('open') && !pop.contains(e.target)) {
    const trigger = e.target.closest('[onclick*="openBmPopup"],[onclick*="addBmFromReader"]');
    if (!trigger) closeBmPopup();
  }
});

/* ═══════════════════════════════════════════
   PDF READER — État global
═══════════════════════════════════════════ */
const rdState = {
  bookId:     null,
  title:      '',
  pdfFile:    '',
  page:       1,
  totalPages: 1,
  zoom:       1.0,
  mode:       'normal',
  sessionStart: 0,
  autoSaveInterval: null,
};

let pdfDoc = null; // PDF.js document

/* ── Ouvrir un livre ── */
function openBook(id, startPage, totalPages, pdfFile, title) {
  rdState.bookId       = parseInt(id) || 0;
  rdState.title        = String(title || '—');
  rdState.pdfFile      = String(pdfFile || '');
  rdState.page         = Math.max(1, parseInt(startPage) || 1);
  rdState.totalPages   = Math.max(1, parseInt(totalPages) || 1);
  rdState.sessionStart = Date.now();

  // Afficher modal
  const modal = document.getElementById('reader-modal');
  modal.classList.add('open');
  document.body.style.overflow = 'hidden';

  // Mettre à jour l'en-tête
  document.getElementById('rd-title').textContent = rdState.title;
  document.getElementById('rd-tot').textContent   = '…';
  document.getElementById('rd-page-inp').value    = rdState.page;

  // Charger le contenu
  if (rdState.pdfFile && typeof pdfjsLib !== 'undefined') {
    loadPDFDoc('../' + rdState.pdfFile);
  } else {
    loadBookContent(rdState.page);
  }

  // Auto-save toutes les 20s
  clearInterval(rdState.autoSaveInterval);
  rdState.autoSaveInterval = setInterval(autoSave, 20000);

  refreshRdBmList();
  updateRdUI();
}

/* ── Auto-save ── */
async function autoSave() {
  if (!rdState.bookId) return;
  const elapsed = Math.round((Date.now() - rdState.sessionStart) / 1000);
  try {
    await callAPI('save_progress', {
      livre_id:      rdState.bookId,
      page_actuelle: rdState.page,
      total_pages:   rdState.totalPages,
      temps_lecture: elapsed,
    });
  } catch(e) {}
}

/* ── Fermer le lecteur ── */
async function closeReader() {
  // Sauvegarder avant fermeture
  if (rdState.bookId) {
    const elapsed = Math.round((Date.now() - rdState.sessionStart) / 1000);
    try {
      await callAPI('save_progress', {
        livre_id:      rdState.bookId,
        page_actuelle: rdState.page,
        total_pages:   rdState.totalPages,
        temps_lecture: elapsed,
      });
    } catch(e) {}
    // Mettre à jour la carte
    updateCardProgress();
  }
  clearInterval(rdState.autoSaveInterval);
  pdfDoc = null;

  document.getElementById('reader-modal').classList.remove('open');
  document.body.style.overflow = '';
  document.getElementById('pdf-canvas').style.display   = 'none';
  document.getElementById('rd-demo').style.display      = 'none';
  document.getElementById('rd-loader').style.display    = 'flex';
}

/* ── Mettre à jour la carte dans la liste ── */
function updateCardProgress() {
  if (!rdState.bookId) return;
  const card = document.querySelector(`.bk[data-id="${rdState.bookId}"]`);
  if (!card) return;
  const pct  = Math.min(100, Math.round((rdState.page / rdState.totalPages) * 100 * 10) / 10);
  const fill = card.querySelector('.prog-fill');
  const pctEl= card.querySelector('.bk-pct');
  const pgEl = card.querySelector('.bk-pg');
  if (fill)  fill.style.width = pct + '%';
  if (pctEl) pctEl.textContent = pct + '%';
  if (pgEl)  pgEl.textContent  = 'p.' + rdState.page + '/' + rdState.totalPages;
  card.dataset.pct = pct;
}

/* ═══════ PDF.js ═══════ */
async function loadPDFDoc(url) {
  showLoader(true);
  try {
    pdfDoc = await pdfjsLib.getDocument(url).promise;
    rdState.totalPages = pdfDoc.numPages;
    document.getElementById('rd-tot').textContent = rdState.totalPages;
    document.getElementById('rd-page-inp').max    = rdState.totalPages;
    renderPdfPage(Math.min(rdState.page, rdState.totalPages));
  } catch(e) {
    console.warn('[DLS] PDF load error:', e.message);
    pdfDoc = null;
    loadBookContent(rdState.page); // fallback
  }
}

async function renderPdfPage(pageNum) {
  if (!pdfDoc) { loadBookContent(pageNum); return; }
  showLoader(true);
  try {
    const page = await pdfDoc.getPage(pageNum);
    const vp   = page.getViewport({ scale: rdState.zoom * 1.5 });
    const canvas = document.getElementById('pdf-canvas');
    const ctx    = canvas.getContext('2d');
    canvas.width  = vp.width;
    canvas.height = vp.height;
    await page.render({ canvasContext: ctx, viewport: vp }).promise;
    applyMode(canvas);
    canvas.style.display = 'block';
    document.getElementById('rd-demo').style.display = 'none';
    rdState.page = pageNum;
    showLoader(false);
    updateRdUI();
  } catch(e) {
    loadBookContent(pageNum);
  }
}

/* ═══════ Contenu texte (fallback/demo) ═══════ */
async function loadBookContent(page) {
  showLoader(true);
  document.getElementById('pdf-canvas').style.display = 'none';
  const demo = document.getElementById('rd-demo');
  demo.style.display = 'block';

  try {
    const d = await callAPIGet('get_book_content', { livre_id: rdState.bookId, page });
    document.getElementById('rd-demo-title').textContent = d.titre || rdState.title;
    document.getElementById('rd-demo-body').innerHTML    = d.contenu || '<p>Contenu non disponible.</p>';
    if (d.total_pages > 0) {
      rdState.totalPages = d.total_pages;
      document.getElementById('rd-tot').textContent          = rdState.totalPages;
      document.getElementById('rd-page-inp').max             = rdState.totalPages;
    }
  } catch(e) {
    document.getElementById('rd-demo-title').textContent = rdState.title;
    document.getElementById('rd-demo-body').innerHTML    = '<p>Contenu temporairement indisponible.</p>';
  }
  rdState.page = page;
  document.getElementById('rd-demo-pg').textContent = 'Page ' + page + ' / ' + rdState.totalPages;
  showLoader(false);
  updateRdUI();
}

/* ── Navigation ── */
function rdPrev() {
  if (rdState.page <= 1) return;
  goToPage(rdState.page - 1);
}
function rdNext() {
  if (rdState.page >= rdState.totalPages) return;
  goToPage(rdState.page + 1);
}
function goToPage(p) {
  const num = Math.max(1, Math.min(rdState.totalPages, parseInt(p) || 1));
  if (pdfDoc) renderPdfPage(num);
  else        loadBookContent(num);
}

// Input page
document.getElementById('rd-page-inp').addEventListener('change', function () {
  goToPage(this.value);
});
document.getElementById('rd-page-inp').addEventListener('keydown', function (e) {
  if (e.key === 'Enter') goToPage(this.value);
});

/* ── Zoom ── */
function rdZoom(delta) {
  rdState.zoom = Math.max(0.4, Math.min(3.0, rdState.zoom + delta));
  document.getElementById('rd-zoom-lbl').textContent = Math.round(rdState.zoom * 100) + '%';
  if (pdfDoc) renderPdfPage(rdState.page);
}

/* ── Modes visuels ── */
function setMode(mode) {
  rdState.mode = mode;
  ['normal', 'night', 'sepia'].forEach(m => {
    document.getElementById('btn-' + m)?.classList.toggle('on', m === mode);
  });
  const canvas = document.getElementById('pdf-canvas');
  applyMode(canvas);
  const demo = document.getElementById('rd-demo');
  const bodyEl = document.getElementById('rd-demo-body');
  if (mode === 'night') {
    demo.style.background = '#111';
    if (bodyEl) bodyEl.style.color = '#ccc';
  } else if (mode === 'sepia') {
    demo.style.background = '#f5eed5';
    if (bodyEl) bodyEl.style.color = '#5c4a1e';
  } else {
    demo.style.background = '';
    if (bodyEl) bodyEl.style.color = '';
  }
}

function applyMode(canvas) {
  if (!canvas) return;
  canvas.className = '';
  if (rdState.mode === 'night')  canvas.classList.add('night');
  if (rdState.mode === 'sepia')  canvas.classList.add('sepia');
}

/* ── Sidebar signets ── */
function toggleRdSb() {
  const panel = document.getElementById('rd-sb-panel');
  panel?.classList.toggle('closed');
  document.getElementById('rd-sb-btn')?.classList.toggle('on');
}

/* ── Recherche dans PDF ── */
function toggleSearch() {
  const bar = document.getElementById('rd-search');
  bar?.classList.toggle('open');
  if (bar?.classList.contains('open')) document.getElementById('rd-search-inp').focus();
}

async function doSearch() {
  const q = (document.getElementById('rd-search-inp').value || '').trim();
  const res = document.getElementById('rd-search-res');
  if (!q) { res.textContent = ''; return; }
  if (!pdfDoc) { res.textContent = 'PDF requis'; return; }
  res.textContent = 'Recherche…';
  for (let i = 1; i <= pdfDoc.numPages; i++) {
    try {
      const pg = await pdfDoc.getPage(i);
      const tc = await pg.getTextContent();
      const text = tc.items.map(t => t.str).join(' ');
      if (text.toLowerCase().includes(q.toLowerCase())) {
        res.textContent = `Trouvé p.${i}`;
        renderPdfPage(i);
        return;
      }
    } catch(e) {}
  }
  res.textContent = 'Non trouvé';
}

function clearSearch() {
  document.getElementById('rd-search-inp').value = '';
  document.getElementById('rd-search-res').textContent = '';
  document.getElementById('rd-search').classList.remove('open');
}

/* ── Télécharger ── */
async function downloadBook() {
  if (!rdState.bookId) return;
  try {
    const d = await callAPI('download_book', { livre_id: rdState.bookId });
    const a = document.createElement('a');
    a.href     = '../' + d.url;
    a.download = (rdState.title || 'livre') + '.pdf';
    document.body.appendChild(a);
    a.click();
    a.remove();
    toast('📥 Téléchargement', 'Document téléchargé', 'success');
  } catch(e) {
    toast('Téléchargement', e.message, 'warn');
  }
}

/* ── Plein écran ── */
function toggleFS() {
  const modal = document.getElementById('reader-modal');
  const ico   = document.getElementById('fs-ico');
  if (!document.fullscreenElement) {
    modal.requestFullscreen?.().catch(() => {});
    if (ico) ico.className = 'bi bi-fullscreen-exit';
  } else {
    document.exitFullscreen?.();
    if (ico) ico.className = 'bi bi-fullscreen';
  }
}

/* ── Mise à jour UI ── */
function updateRdUI() {
  const pct = Math.min(100, Math.round((rdState.page / Math.max(1, rdState.totalPages)) * 1000) / 10);
  document.getElementById('rd-prog').style.width       = pct + '%';
  document.getElementById('rd-pct-lbl').textContent    = pct + '%';
  document.getElementById('rd-tot').textContent        = rdState.totalPages;
  document.getElementById('rd-page-inp').value         = rdState.page;

  // Boutons nav
  const prev = document.getElementById('btn-prev');
  const next = document.getElementById('btn-next');
  if (prev) prev.style.opacity = rdState.page <= 1 ? '.3' : '1';
  if (next) next.style.opacity = rdState.page >= rdState.totalPages ? '.3' : '1';

  // Temps
  const elapsed = Math.round((Date.now() - rdState.sessionStart) / 1000);
  document.getElementById('rd-time-spent').textContent = fmtTime(elapsed) + ' lus';
  const remaining = Math.max(0, rdState.totalPages - rdState.page) * 120;
  document.getElementById('rd-time-rem').textContent = fmtTime(remaining) + ' restant';
}

// Mise à jour bottom bar toutes les 5s
setInterval(() => { if (rdState.sessionStart) updateRdUI(); }, 5000);

/* ── Loader ── */
function showLoader(show) {
  document.getElementById('rd-loader').style.display = show ? 'flex' : 'none';
}

/* ═══════════════════════════════════════════
   CLAVIER
═══════════════════════════════════════════ */
document.addEventListener('keydown', e => {
  const modal = document.getElementById('reader-modal');
  if (!modal?.classList.contains('open')) return;
  const tag = document.activeElement?.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA') return;

  switch(e.key) {
    case 'ArrowRight': case 'ArrowDown': case 'PageDown': e.preventDefault(); rdNext(); break;
    case 'ArrowLeft':  case 'ArrowUp':   case 'PageUp':   e.preventDefault(); rdPrev(); break;
    case 'Escape':    closeReader(); break;
    case 'f': case 'F': toggleFS(); break;
    case '+': case '=': rdZoom(0.2); break;
    case '-':            rdZoom(-0.2); break;
  }
  if (e.ctrlKey && e.key === 's') { e.preventDefault(); addBmFromReader(); }
  if (e.ctrlKey && e.key === 'f') { e.preventDefault(); toggleSearch(); }
});

/* ═══════════════════════════════════════════
   INIT
═══════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
  // Animer les barres de progression
  setTimeout(() => {
    document.querySelectorAll('.prog-fill').forEach(b => {
      const w = b.style.width;
      b.style.width = '0%';
      requestAnimationFrame(() => requestAnimationFrame(() => { b.style.width = w; }));
    });
  }, 300);

  // Toast de bienvenue
  setTimeout(() => {
    toast(
      'Lectures en cours',
      '<?= $stats["en_cours"] ?> en cours · <?= $stats["termines"] ?> terminé<?= $stats["termines"] > 1 ? "s" : "" ?>',
      'success',
      3500
    );
  }, 700);

  // Polling stats toutes les 30s
  setInterval(async () => {
    try {
      const d = await callAPIGet('reading_stats', {});
      const upd = {
        'st-en-cours': d.en_cours,
        'st-termines':  d.termines,
        'st-pages':     d.pages_lues,
        'st-fav':       d.favoris,
      };
      Object.entries(upd).forEach(([id, val]) => {
        const el = document.getElementById(id);
        if (el && val != null) el.textContent = val;
      });
    } catch(e) {}
  }, 30000);
});
</script>
</body>
</html>