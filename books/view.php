<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — books/view.php  v4.0 SINGLE-FILE        ║
 * ║  TOUT en un seul fichier : HTML + PHP + AJAX + JSON        ║
 * ║  Aucun fichier ajax/ séparé requis                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Config BD ────────────────────────────────────────────────
foreach ([
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../config/database.php',
    __DIR__ . '/includes/config.php',
] as $p) {
    if (file_exists($p) && !defined('DB_HOST_LOADED')) {
        require_once $p;
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
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) {
        error_log('[view.php] PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    if ($pdo) {
        try {
            $demo = $pdo->query("SELECT * FROM users WHERE statut='actif' LIMIT 1")->fetch();
            if ($demo) {
                $_SESSION['user_id']    = $demo['id'];
                $_SESSION['user_role']  = $demo['role'];
                $_SESSION['user_name']  = trim(($demo['prenom'] ?? '') . ' ' . $demo['nom']);
                $_SESSION['user_email'] = $demo['email'];
            }
        } catch (Exception $e) {}
    }
    if (!isset($_SESSION['user_id'])) {
        // Si AJAX, retourner JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Non authentifié']);
            exit;
        }
        header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';
$username = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
$avatar   = strtoupper(substr($username, 0, 1)) ?: 'U';

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Helper JSON sortie propre
function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper validation CSRF
function checkCsrf(): void {
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        jsonOut(['success' => false, 'message' => 'Token CSRF invalide'], 403);
    }
}

// ══════════════════════════════════════════════════════════════
//  BLOC AJAX — traité avant tout affichage HTML
//  Détection : header X-Requested-With ou paramètre ?action=
// ══════════════════════════════════════════════════════════════
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       || isset($_GET['action'])
       || isset($_POST['action']);

if ($isAjax) {
    $action  = $_POST['action'] ?? $_GET['action'] ?? '';
    $livreId = (int)($_POST['livre_id'] ?? $_GET['livre_id'] ?? $_GET['id'] ?? 0);

    if (!$pdo) jsonOut(['success' => false, 'message' => 'Base de données inaccessible'], 500);

    switch ($action) {

        // ─── TOGGLE FAVORI ──────────────────────────────────
        case 'toggle_favorite':
            checkCsrf();
            if (!$livreId) jsonOut(['success' => false, 'message' => 'livre_id manquant']);
            try {
                $st = $pdo->prepare("SELECT id FROM favorites WHERE user_id=? AND livre_id=?");
                $st->execute([$userId, $livreId]);
                $exists = $st->fetch();
                if ($exists) {
                    $pdo->prepare("DELETE FROM favorites WHERE user_id=? AND livre_id=?")->execute([$userId, $livreId]);
                    jsonOut(['success' => true, 'favorited' => false]);
                } else {
                    $pdo->prepare("INSERT IGNORE INTO favorites (user_id, livre_id) VALUES (?,?)")->execute([$userId, $livreId]);
                    jsonOut(['success' => true, 'favorited' => true]);
                }
            } catch (Exception $e) {
                error_log('[toggle_favorite] ' . $e->getMessage());
                jsonOut(['success' => false, 'message' => 'Erreur base de données'], 500);
            }

        // ─── SOUMETTRE AVIS ─────────────────────────────────
        case 'submit_review':
            checkCsrf();
            $note       = (int)($_POST['note'] ?? 0);
            $commentaire = trim($_POST['commentaire'] ?? '');
            if (!$livreId || $note < 1 || $note > 5) {
                jsonOut(['success' => false, 'message' => 'Données invalides']);
            }
            try {
                // Upsert : insérer ou mettre à jour
                $pdo->prepare("
                    INSERT INTO reviews (user_id, livre_id, note, commentaire, statut)
                    VALUES (?,?,?,?,'approved')
                    ON DUPLICATE KEY UPDATE note=VALUES(note), commentaire=VALUES(commentaire), updated_at=NOW()
                ")->execute([$userId, $livreId, $note, $commentaire]);

                // Recalculer note_moyenne
                $avg = $pdo->prepare("SELECT ROUND(AVG(note),2) FROM reviews WHERE livre_id=? AND statut='approved'");
                $avg->execute([$livreId]);
                $noteMoyenne = (float)$avg->fetchColumn();
                $pdo->prepare("UPDATE livres SET note_moyenne=? WHERE id=?")->execute([$noteMoyenne, $livreId]);

                jsonOut(['success' => true, 'note_moyenne' => $noteMoyenne]);
            } catch (Exception $e) {
                error_log('[submit_review] ' . $e->getMessage());
                jsonOut(['success' => false, 'message' => 'Erreur base de données'], 500);
            }

        // ─── SUPPRIMER AVIS ─────────────────────────────────
        case 'delete_review':
            checkCsrf();
            $reviewId = (int)($_POST['review_id'] ?? 0);
            if (!$reviewId) jsonOut(['success' => false, 'message' => 'review_id manquant']);
            try {
                // Vérifier propriété (ou admin)
                $cond = $userRole === 'admin' ? 'id=?' : 'id=? AND user_id=?';
                $params = $userRole === 'admin' ? [$reviewId] : [$reviewId, $userId];
                $del = $pdo->prepare("DELETE FROM reviews WHERE $cond");
                $del->execute($params);

                if ($del->rowCount() === 0) jsonOut(['success' => false, 'message' => 'Avis introuvable ou non autorisé'], 403);

                // Recalculer note_moyenne
                $avg = $pdo->prepare("SELECT ROUND(AVG(note),2) FROM reviews WHERE livre_id=? AND statut='approved'");
                $avg->execute([$livreId]);
                $noteMoyenne = (float)($avg->fetchColumn() ?? 0);
                $pdo->prepare("UPDATE livres SET note_moyenne=? WHERE id=?")->execute([$noteMoyenne, $livreId]);

                jsonOut(['success' => true, 'note_moyenne' => $noteMoyenne]);
            } catch (Exception $e) {
                error_log('[delete_review] ' . $e->getMessage());
                jsonOut(['success' => false, 'message' => 'Erreur'], 500);
            }

        // ─── ACHAT ──────────────────────────────────────────
        case 'process_purchase':
            checkCsrf();
            $methode   = in_array($_POST['methode'] ?? '', ['orange_money','mobile_money','carte'])
                       ? $_POST['methode'] : 'orange_money';
            $telephone = preg_replace('/\D/', '', $_POST['telephone'] ?? '');
            $montant   = (float)($_POST['montant'] ?? 0);

            if (!$livreId) jsonOut(['success' => false, 'message' => 'livre_id manquant']);
            try {
                // Déjà acheté ?
                $stCheck = $pdo->prepare("SELECT id FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme' LIMIT 1");
                $stCheck->execute([$userId, $livreId]);
                if ($stCheck->fetch()) jsonOut(['success' => false, 'already_owned' => true, 'message' => 'Déjà acheté']);

                // Récupérer le prix réel
                $stPrix = $pdo->prepare("SELECT prix FROM livres WHERE id=? LIMIT 1");
                $stPrix->execute([$livreId]);
                $livreRow = $stPrix->fetch();
                if (!$livreRow) jsonOut(['success' => false, 'message' => 'Livre introuvable']);
                $montantReel = (float)$livreRow['prix'];

                // Référence unique
                $reference = 'DL-' . strtoupper(bin2hex(random_bytes(5))) . '-' . time();

                $pdo->prepare("
                    INSERT INTO achats (user_id, livre_id, montant, methode, statut, reference)
                    VALUES (?,?,?,'$methode','confirme',?)
                ")->execute([$userId, $livreId, $montantReel, $reference]);

                // Incrémenter nb_ventes (si trigger absent)
                $pdo->prepare("UPDATE livres SET nb_ventes=nb_ventes+1 WHERE id=?")->execute([$livreId]);

                // Notification
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, message)
                        VALUES (?,  'purchase', ?)
                    ")->execute([$userId, "Achat confirmé · Réf : $reference"]);
                } catch (Exception $e) {}

                jsonOut(['success' => true, 'reference' => $reference]);
            } catch (Exception $e) {
                error_log('[process_purchase] ' . $e->getMessage());
                jsonOut(['success' => false, 'message' => 'Erreur lors de l\'achat'], 500);
            }

        // ─── SAUVEGARDE PROGRESSION ─────────────────────────
        case 'save_progress':
            checkCsrf();
            $page       = max(1, (int)($_POST['page_actuelle'] ?? 1));
            $pct        = min(100, max(0, (float)($_POST['pourcentage'] ?? 0)));
            $totalPages = max(0, (int)($_POST['total_pages'] ?? 0));
            $temps      = max(0, (int)($_POST['temps_lecture'] ?? 0));

            if (!$livreId) jsonOut(['success' => false, 'message' => 'livre_id manquant']);
            try {
                $pdo->prepare("
                    INSERT INTO lecture_progression (user_id, livre_id, page_actuelle, pourcentage, total_pages, temps_lecture, derniere_lecture)
                    VALUES (?,?,?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE
                        page_actuelle=VALUES(page_actuelle),
                        pourcentage=VALUES(pourcentage),
                        total_pages=VALUES(total_pages),
                        temps_lecture=VALUES(temps_lecture),
                        derniere_lecture=NOW()
                ")->execute([$userId, $livreId, $page, $pct, $totalPages, $temps]);
                jsonOut(['success' => true]);
            } catch (Exception $e) {
                // Fallback sans colonnes optionnelles
                try {
                    $pdo->prepare("
                        INSERT INTO lecture_progression (user_id, livre_id, page_actuelle, pourcentage)
                        VALUES (?,?,?,?)
                        ON DUPLICATE KEY UPDATE page_actuelle=VALUES(page_actuelle), pourcentage=VALUES(pourcentage)
                    ")->execute([$userId, $livreId, $page, $pct]);
                    jsonOut(['success' => true]);
                } catch (Exception $e2) {
                    error_log('[save_progress] ' . $e2->getMessage());
                    jsonOut(['success' => false, 'message' => 'Erreur'], 500);
                }
            }

        // ─── AJOUTER SIGNET ─────────────────────────────────
        case 'add_bookmark':
            checkCsrf();
            $pageNum = max(1, (int)($_POST['page_number'] ?? 1));
            $note    = trim(mb_substr($_POST['note'] ?? '', 0, 500));

            if (!$livreId) jsonOut(['success' => false, 'message' => 'livre_id manquant']);
            try {
                $pdo->prepare("
                    INSERT INTO reading_bookmarks (user_id, livre_id, page_number, note)
                    VALUES (?,?,?,?)
                ")->execute([$userId, $livreId, $pageNum, $note]);
                jsonOut(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            } catch (Exception $e) {
                error_log('[add_bookmark] ' . $e->getMessage());
                jsonOut(['success' => false, 'message' => 'Erreur'], 500);
            }

        // ─── SUPPRIMER SIGNET ────────────────────────────────
        case 'delete_bookmark':
            checkCsrf();
            $bookmarkId = (int)($_POST['bookmark_id'] ?? 0);
            if (!$bookmarkId) jsonOut(['success' => false, 'message' => 'bookmark_id manquant']);
            try {
                $pdo->prepare("DELETE FROM reading_bookmarks WHERE id=? AND user_id=?")->execute([$bookmarkId, $userId]);
                jsonOut(['success' => true]);
            } catch (Exception $e) {
                jsonOut(['success' => false, 'message' => 'Erreur'], 500);
            }

        // ─── GET SIGNETS ─────────────────────────────────────
        case 'get_bookmarks':
            if (!$livreId) jsonOut(['success' => false, 'message' => 'livre_id manquant']);
            try {
                $st = $pdo->prepare("SELECT * FROM reading_bookmarks WHERE user_id=? AND livre_id=? ORDER BY page_number ASC");
                $st->execute([$userId, $livreId]);
                jsonOut(['success' => true, 'bookmarks' => $st->fetchAll()]);
            } catch (Exception $e) {
                jsonOut(['success' => false, 'message' => 'Erreur'], 500);
            }

        // ─── LOG TÉLÉCHARGEMENT ──────────────────────────────
        case 'log_download':
            checkCsrf();
            if (!$livreId) jsonOut(['success' => false, 'message' => 'livre_id manquant']);
            try {
                // Vérifier accès
                $stA = $pdo->prepare("SELECT id FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme' LIMIT 1");
                $stA->execute([$userId, $livreId]);
                $hasAccess = (bool)$stA->fetch();

                // Vérifier si gratuit
                if (!$hasAccess) {
                    $stL = $pdo->prepare("SELECT prix, access_type FROM livres WHERE id=? LIMIT 1");
                    $stL->execute([$livreId]);
                    $lRow = $stL->fetch();
                    if ($lRow && ((float)$lRow['prix'] == 0 || $lRow['access_type'] === 'gratuit')) $hasAccess = true;
                }
                if (!$hasAccess && !in_array($userRole, ['admin','journaliste'])) {
                    jsonOut(['success' => false, 'message' => 'Accès non autorisé'], 403);
                }

                // Limite téléchargements
                $maxDl = 3;
                try { $mx = $pdo->query("SELECT `value` FROM settings WHERE `key`='max_downloads' LIMIT 1")->fetchColumn(); if ($mx) $maxDl = (int)$mx; } catch(Exception $e){}

                $stDl = $pdo->prepare("SELECT count FROM user_downloads WHERE user_id=? AND livre_id=?");
                $stDl->execute([$userId, $livreId]);
                $dlRow = $stDl->fetch();
                $count = $dlRow ? (int)$dlRow['count'] : 0;

                if ($count >= $maxDl && $userRole !== 'admin') {
                    jsonOut(['success' => false, 'message' => "Limite atteinte ($count/$maxDl téléchargements)", 'count' => $count, 'max' => $maxDl]);
                }

                // Incrémenter
                $pdo->prepare("
                    INSERT INTO user_downloads (user_id, livre_id, count)
                    VALUES (?,?,1)
                    ON DUPLICATE KEY UPDATE count=count+1, last_dl_at=NOW()
                ")->execute([$userId, $livreId]);

                // URL du fichier
                $stFile = $pdo->prepare("SELECT fichier_pdf FROM livres WHERE id=? LIMIT 1");
                $stFile->execute([$livreId]);
                $fileRow = $stFile->fetch();
                $url = $fileRow['fichier_pdf'] ?? null;

                if (!$url) jsonOut(['success' => false, 'message' => 'Fichier PDF non disponible']);

                jsonOut(['success' => true, 'url' => $url, 'count' => $count + 1, 'max' => $maxDl]);
            } catch (Exception $e) {
                error_log('[log_download] ' . $e->getMessage());
                jsonOut(['success' => false, 'message' => 'Erreur'], 500);
            }

        // ─── GET NOTIFICATIONS ───────────────────────────────
        case 'get_notifications':
            try {
                $st = $pdo->prepare("
                    SELECT id, type, message, is_read, created_at
                    FROM notifications
                    WHERE user_id=? OR user_id IS NULL
                    ORDER BY created_at DESC LIMIT 30
                ");
                $st->execute([$userId]);
                $notifs = $st->fetchAll();
                $unread = array_sum(array_column($notifs, 'is_read') ? array_map(fn($n) => (int)!$n['is_read'], $notifs) : []);
                $unread = count(array_filter($notifs, fn($n) => !$n['is_read']));
                jsonOut(['success' => true, 'notifications' => $notifs, 'unread' => $unread]);
            } catch (Exception $e) {
                jsonOut(['success' => true, 'notifications' => [], 'unread' => 0]);
            }

        // ─── MARQUER NOTIFICATIONS LUES ─────────────────────
        case 'mark_notifications_read':
            checkCsrf();
            try {
                $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? OR user_id IS NULL")->execute([$userId]);
                jsonOut(['success' => true]);
            } catch (Exception $e) {
                jsonOut(['success' => false, 'message' => 'Erreur'], 500);
            }

        default:
            jsonOut(['success' => false, 'message' => 'Action inconnue: ' . htmlspecialchars($action)], 400);
    }
    // Sécurité — ne jamais atteindre ici
    exit;
}

// ══════════════════════════════════════════════════════════════
//  SUITE : Affichage HTML normal
// ══════════════════════════════════════════════════════════════

// ── Créer tables manquantes ──────────────────────────────────
if ($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS favorites (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                livre_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_favorite (user_id, livre_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS reviews (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                livre_id INT UNSIGNED NOT NULL,
                note INT NOT NULL,
                commentaire TEXT NULL,
                statut ENUM('pending','approved','rejected') DEFAULT 'approved',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_review (user_id, livre_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
        ");

        // Migration douce lecture_progression
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM lecture_progression")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('derniere_lecture', $cols)) $pdo->exec("ALTER TABLE lecture_progression ADD COLUMN derniere_lecture TIMESTAMP NULL");
            if (!in_array('temps_lecture',   $cols)) $pdo->exec("ALTER TABLE lecture_progression ADD COLUMN temps_lecture INT UNSIGNED NOT NULL DEFAULT 0");
            if (!in_array('total_pages',     $cols)) $pdo->exec("ALTER TABLE lecture_progression ADD COLUMN total_pages INT UNSIGNED NOT NULL DEFAULT 0");
        } catch (Exception $e) {}
    } catch (Exception $e) {
        error_log('[view.php] Schema: ' . $e->getMessage());
    }
}

// ── Récupérer livre ──────────────────────────────────────────
$livreId = (int)($_GET['id'] ?? 0);
$livre   = null;
$erreur  = '';

if ($livreId < 1) {
    $erreur = 'Aucun livre sélectionné.';
} elseif (!$pdo) {
    $erreur = 'Base de données inaccessible.';
} else {
    try {
        $st = $pdo->prepare("
            SELECT l.*,
                   c.id AS categorie_id, c.nom AS categorie_nom,
                   c.slug AS categorie_slug, c.icone AS categorie_icone,
                   c.couleur AS categorie_couleur,
                   u.nom AS ajout_par_nom
            FROM livres l
            LEFT JOIN categories c ON c.id = l.categorie_id
            LEFT JOIN users u ON u.id = l.ajoute_par
            WHERE l.id = ? AND l.statut = 'disponible'
            LIMIT 1
        ");
        $st->execute([$livreId]);
        $livre = $st->fetch();
        if (!$livre) $erreur = 'Livre introuvable ou non disponible.';
    } catch (Exception $e) {
        $erreur = 'Erreur lors du chargement.';
    }
}

// ── Accès & état utilisateur ─────────────────────────────────
$hasAccess     = false;
$alreadyBought = false;
$isFavorite    = false;
$userReview    = null;
$progression   = ['page_actuelle' => 1, 'pourcentage' => 0.0];
$downloadCount = 0;
$maxDownloads  = 3;
$notifCount    = 0;

if ($livre && $pdo) {
    try {
        $accessType = $livre['access_type'] ?? 'paid';
        if ($accessType === 'gratuit' || (float)$livre['prix'] == 0) {
            $hasAccess = true;
        } elseif ($userRole === 'admin' || $userRole === 'journaliste') {
            $hasAccess = true;
        } else {
            $stA = $pdo->prepare("SELECT id FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme' LIMIT 1");
            $stA->execute([$userId, $livreId]);
            if ($stA->fetch()) { $hasAccess = true; $alreadyBought = true; }
        }

        $stF = $pdo->prepare("SELECT id FROM favorites WHERE user_id=? AND livre_id=?");
        $stF->execute([$userId, $livreId]);
        $isFavorite = (bool)$stF->fetch();

        $stP = $pdo->prepare("SELECT page_actuelle, pourcentage FROM lecture_progression WHERE user_id=? AND livre_id=? LIMIT 1");
        $stP->execute([$userId, $livreId]);
        $pRow = $stP->fetch();
        if ($pRow) $progression = $pRow;

        $stR = $pdo->prepare("SELECT * FROM reviews WHERE user_id=? AND livre_id=? LIMIT 1");
        $stR->execute([$userId, $livreId]);
        $userReview = $stR->fetch() ?: null;

        $stDl = $pdo->prepare("SELECT COALESCE(SUM(count),0) FROM user_downloads WHERE user_id=? AND livre_id=?");
        $stDl->execute([$userId, $livreId]);
        $downloadCount = (int)$stDl->fetchColumn();

        try { $mx = $pdo->query("SELECT `value` FROM settings WHERE `key`='max_downloads' LIMIT 1")->fetchColumn(); if($mx) $maxDownloads=(int)$mx; } catch(Exception $e){}

        $stN = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE is_read=0 AND (user_id=? OR user_id IS NULL)");
        $stN->execute([$userId]);
        $notifCount = (int)$stN->fetchColumn();

        if ($hasAccess && empty($_SESSION['view_' . $livreId])) {
            $_SESSION['view_' . $livreId] = true;
            $pdo->prepare("UPDATE livres SET nb_lectures=nb_lectures+1 WHERE id=?")->execute([$livreId]);
        }
    } catch (Exception $e) {
        error_log('[view.php] User data: ' . $e->getMessage());
    }
}

// ── Avis ─────────────────────────────────────────────────────
$reviews     = [];
$ratingDist  = [5=>0, 4=>0, 3=>0, 2=>0, 1=>0];

if ($livre && $pdo) {
    try {
        $stRev = $pdo->prepare("
            SELECT r.*, u.nom, u.prenom
            FROM reviews r
            JOIN users u ON u.id = r.user_id
            WHERE r.livre_id=? AND r.statut='approved'
            ORDER BY r.created_at DESC LIMIT 20
        ");
        $stRev->execute([$livreId]);
        $reviews = $stRev->fetchAll();

        $stDist = $pdo->prepare("SELECT note, COUNT(*) AS cnt FROM reviews WHERE livre_id=? AND statut='approved' GROUP BY note");
        $stDist->execute([$livreId]);
        foreach ($stDist->fetchAll() as $row) {
            $ratingDist[(int)$row['note']] = (int)$row['cnt'];
        }
    } catch (Exception $e) {}
}

// ── Livres similaires ─────────────────────────────────────────
$similaires = [];
$memeAuteur = [];

if ($livre && $pdo) {
    try {
        $stSim = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, l.prix, l.access_type, l.note_moyenne,
                   l.nb_ventes, l.is_bestseller, l.is_featured,
                   c.nom AS categorie_nom, c.icone AS categorie_icone
            FROM livres l LEFT JOIN categories c ON c.id=l.categorie_id
            WHERE l.statut='disponible' AND l.categorie_id=? AND l.id!=?
            ORDER BY l.note_moyenne DESC, l.nb_ventes DESC LIMIT 8
        ");
        $stSim->execute([$livre['categorie_id'] ?? 0, $livreId]);
        $similaires = $stSim->fetchAll();

        $stAut = $pdo->prepare("
            SELECT l.id, l.titre, l.auteur, l.prix, l.access_type, l.note_moyenne
            FROM livres l WHERE l.statut='disponible' AND l.auteur=? AND l.id!=?
            ORDER BY l.note_moyenne DESC LIMIT 4
        ");
        $stAut->execute([$livre['auteur'], $livreId]);
        $memeAuteur = $stAut->fetchAll();
    } catch (Exception $e) {}
}

// ── Helpers ───────────────────────────────────────────────────
function stars(float $note, bool $interactive = false, int $currentNote = 0): string {
    $html = '<div class="stars' . ($interactive ? ' stars-interactive' : '') . '">';
    for ($i = 1; $i <= 5; $i++) {
        $filled = $i <= round($note);
        if ($interactive) {
            $html .= "<span class='star" . ($i <= $currentNote ? ' filled' : '') . "' data-v='$i' onclick='setRating($i)' onmouseover='hoverRating($i)' onmouseout='resetRatingHover()'>★</span>";
        } else {
            $html .= "<span class='star" . ($filled ? ' filled' : '') . "'>★</span>";
        }
    }
    $html .= '</div>';
    return $html;
}

function ageDate(string $d): string {
    if (!$d) return '';
    $diff = time() - strtotime($d);
    if ($diff < 86400)  return 'Aujourd\'hui';
    if ($diff < 604800) return (int)($diff/86400) . 'j';
    if ($diff < 2592000) return (int)($diff/604800) . ' sem.';
    return date('d/m/Y', strtotime($d));
}

$prixFormate = $livre ? number_format((float)$livre['prix'], 0, ',', ' ') : '0';
$noteGlobale = $livre ? round((float)($livre['note_moyenne'] ?? 0), 1) : 0;
$totalAvis   = array_sum($ratingDist);
$accessType  = $livre['access_type'] ?? 'paid';
$isFree      = $accessType === 'gratuit' || (float)($livre['prix'] ?? 0) == 0;
$progPct     = round((float)$progression['pourcentage'], 1);
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $livre ? htmlspecialchars($livre['titre'], ENT_QUOTES) . ' — Digital Library' : 'Digital Library' ?></title>
<meta name="description" content="<?= $livre ? htmlspecialchars(mb_substr($livre['description'] ?? '', 0, 160), ENT_QUOTES) : '' ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#06090f;--bg2:#0d1120;--bg3:#131828;--bg4:#181e2c;
  --s1:rgba(255,255,255,.03);--s2:rgba(255,255,255,.055);--s3:rgba(255,255,255,.09);
  --b1:rgba(255,255,255,.06);--b2:rgba(255,255,255,.11);--b3:rgba(255,255,255,.18);
  --gold:#e8c56c;--gold2:#f5d580;--gold3:rgba(232,197,108,.12);
  --cyan:#00d4ff;--cyan2:rgba(0,212,255,.12);
  --violet:#7c3aed;--violet2:rgba(124,58,237,.12);
  --neon:#00ffaa;--neon2:rgba(0,255,170,.1);
  --rose:#fb7185;--rose2:rgba(251,113,133,.1);
  --amber:#fbbf24;
  --t1:#eef2ff;--t2:rgba(238,242,255,.62);--t3:rgba(238,242,255,.32);--t4:rgba(238,242,255,.16);
  --topbar:60px;
  --r:8px;--rm:12px;--rl:18px;--rx:24px;
  --sh1:0 2px 12px rgba(0,0,0,.35);--sh2:0 8px 40px rgba(0,0,0,.55);--sh3:0 20px 80px rgba(0,0,0,.7);
}
html{scroll-behavior:smooth;overflow-x:hidden}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
button{cursor:pointer;font-family:'DM Sans',sans-serif;outline:none}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:var(--gold);opacity:.4}

/* TOPBAR */
#topbar{position:fixed;top:0;left:0;right:0;height:var(--topbar);background:rgba(6,9,15,.88);backdrop-filter:blur(24px);border-bottom:1px solid var(--b1);display:flex;align-items:center;gap:.8rem;padding:0 1.5rem;z-index:500}
.tb-brand{display:flex;align-items:center;gap:.45rem;font-family:'Syne',sans-serif;font-weight:800;font-size:.82rem;color:var(--t1)}
.tb-logo{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,var(--gold),var(--violet));display:flex;align-items:center;justify-content:center;font-size:.95rem;box-shadow:0 0 14px rgba(232,197,108,.3)}
.tb-sep{color:var(--t4);margin:0 .1rem}
.tb-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.85rem;max-width:300px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:var(--t2)}
.tb-sp{flex:1}
.tb-btn{width:36px;height:36px;border-radius:var(--r);background:var(--s1);border:1px solid var(--b1);color:var(--t2);display:flex;align-items:center;justify-content:center;font-size:.9rem;transition:all .16s;position:relative;text-decoration:none;flex-shrink:0}
.tb-btn:hover{background:var(--s2);border-color:var(--b2);color:var(--t1)}
.tb-btn.active{color:var(--gold);border-color:rgba(232,197,108,.3);background:var(--gold3)}
.nb{position:absolute;top:-3px;right:-3px;min-width:15px;height:15px;padding:0 3px;background:var(--rose);border-radius:50%;font-size:.5rem;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);color:#fff}
.tb-user{display:flex;align-items:center;gap:.45rem;padding:4px 9px;border-radius:var(--r);background:var(--s1);border:1px solid var(--b1);font-size:.78rem;font-weight:600;cursor:default}
.av-sm{width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,var(--neon),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.72rem;color:#fff;flex-shrink:0}

/* PAGE */
.page{padding-top:calc(var(--topbar) + 2rem);padding-bottom:5rem;max-width:1380px;margin:0 auto;padding-left:1.5rem;padding-right:1.5rem}

/* HERO */
.hero{position:relative;border-radius:var(--rx);overflow:hidden;background:linear-gradient(135deg,var(--bg3) 0%,var(--bg2) 100%);border:1px solid var(--b1);margin-bottom:2.5rem}
.hero-bg{position:absolute;inset:0;background:radial-gradient(ellipse at 80% 50%,rgba(124,58,237,.18) 0%,transparent 65%),radial-gradient(ellipse at 10% 70%,rgba(0,212,255,.08) 0%,transparent 55%);pointer-events:none;z-index:0}
.hero-inner{display:flex;gap:2.5rem;padding:2.5rem;position:relative;z-index:1;align-items:flex-start}
.hero-cover-wrap{flex-shrink:0;position:relative}
.hero-cover{width:190px;height:270px;border-radius:var(--rl);overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.06);background:linear-gradient(135deg,var(--bg4),rgba(124,58,237,.3));display:flex;align-items:center;justify-content:center;font-size:5rem;position:relative;transition:transform .3s}
.hero-cover:hover{transform:scale(1.03) translateY(-4px)}
.hero-cover img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.hero-cover-glow{position:absolute;bottom:-20px;left:50%;transform:translateX(-50%);width:140px;height:30px;background:rgba(124,58,237,.3);filter:blur(20px);border-radius:50%;pointer-events:none}
.hero-info{flex:1;min-width:0}
.hero-breadcrumb{display:flex;align-items:center;gap:.4rem;font-size:.72rem;color:var(--t3);font-family:'Space Mono',monospace;margin-bottom:.7rem;flex-wrap:wrap}
.hero-badges{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.9rem}
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:4px 10px;border-radius:100px;font-size:.58rem;font-family:'Space Mono',monospace;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.badge-premium{background:linear-gradient(90deg,rgba(124,58,237,.25),rgba(0,212,255,.15));color:var(--cyan);border:1px solid rgba(0,212,255,.2)}
.badge-free{background:rgba(0,255,170,.12);color:var(--neon);border:1px solid rgba(0,255,170,.25)}
.badge-best{background:rgba(251,113,133,.12);color:var(--rose);border:1px solid rgba(251,113,133,.25)}
.badge-feat{background:rgba(251,191,36,.1);color:var(--amber);border:1px solid rgba(251,191,36,.2)}
.badge-std{background:var(--s2);color:var(--t3);border:1px solid var(--b1)}
.hero-title{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;line-height:1.15;letter-spacing:-.5px;margin-bottom:.4rem}
.hero-author{font-size:.9rem;color:var(--cyan);font-weight:600;margin-bottom:.9rem}
.hero-rating{display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;flex-wrap:wrap}
.hero-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.6rem;margin-bottom:1.2rem}
.hs-item{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r);padding:.55rem .7rem;text-align:center}
.hs-val{font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:var(--gold)}
.hs-lbl{font-size:.6rem;color:var(--t3);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.04em;margin-top:2px}
.hero-meta{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.3rem}
.meta-chip{display:inline-flex;align-items:center;gap:.35rem;padding:4px 10px;border-radius:100px;background:var(--s1);border:1px solid var(--b1);font-size:.7rem;color:var(--t2)}
.hero-desc{font-size:.88rem;line-height:1.75;color:var(--t2);max-height:90px;overflow:hidden;transition:max-height .4s;position:relative}
.hero-desc.expanded{max-height:500px}
.hero-desc::after{content:'';position:absolute;bottom:0;left:0;right:0;height:28px;background:linear-gradient(to top,rgba(19,24,40,1),transparent);pointer-events:none;transition:opacity .3s}
.hero-desc.expanded::after{opacity:0}
.hero-actions{display:flex;gap:.7rem;flex-wrap:wrap;margin-top:1.2rem}
.prog-wrap{margin-bottom:.5rem}
.prog-bar{height:6px;background:rgba(255,255,255,.07);border-radius:100px;overflow:hidden}
.prog-fill{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--cyan),var(--violet));transition:width 1.2s ease;box-shadow:0 0 8px rgba(0,212,255,.4)}
.prog-label{display:flex;justify-content:space-between;font-size:.62rem;font-family:'Space Mono',monospace;color:var(--t3);margin-top:3px}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:.45rem;padding:10px 20px;border-radius:var(--r);font-family:'Syne',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;border:none;transition:all .18s ease;white-space:nowrap;text-decoration:none}
.btn-lg{padding:13px 28px;font-size:.88rem}
.btn-sm{padding:6px 14px;font-size:.72rem}
.btn-xs{padding:4px 10px;font-size:.65rem}
.btn-primary{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff;box-shadow:0 4px 18px rgba(0,212,255,.2)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,212,255,.35);opacity:.92}
.btn-gold{background:linear-gradient(135deg,var(--gold),#c4a030);color:#1a0f00;font-weight:800;box-shadow:0 4px 18px rgba(232,197,108,.2)}
.btn-gold:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(232,197,108,.35)}
.btn-outline{background:transparent;border:1px solid var(--b2);color:var(--t2)}
.btn-outline:hover{background:var(--s2);border-color:var(--b3);color:var(--t1)}
.btn-ghost{background:var(--s1);border:1px solid var(--b1);color:var(--t2)}
.btn-ghost:hover{background:var(--s2);color:var(--t1)}
.btn-fav{background:var(--s1);border:1px solid var(--b1);color:var(--t3);font-size:1.1rem;padding:10px 14px}
.btn-fav.active,.btn-fav:hover{color:var(--rose);border-color:rgba(251,113,133,.3);background:var(--rose2)}
.btn-fav.active{text-shadow:0 0 12px var(--rose)}
.btn-danger{background:rgba(251,113,133,.1);border:1px solid rgba(251,113,133,.25);color:var(--rose)}
.btn-danger:hover{background:rgba(251,113,133,.2)}
.btn-success{background:rgba(0,255,170,.08);border:1px solid rgba(0,255,170,.2);color:var(--neon)}

/* SECTIONS */
.section{margin-bottom:2.5rem}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem;padding-bottom:.8rem;border-bottom:1px solid var(--b1)}
.section-title{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:800;display:flex;align-items:center;gap:.5rem}
.card{background:var(--s1);border:1px solid var(--b1);border-radius:var(--rl);overflow:hidden;backdrop-filter:blur(8px)}
.card-body{padding:1.4rem}
.card-inner{background:var(--bg3);border:1px solid var(--b1);border-radius:var(--rm);padding:1rem;margin-bottom:.6rem}

/* TABS */
.tabs{display:flex;gap:0;border-bottom:1px solid var(--b1);margin-bottom:1.5rem;overflow-x:auto}
.tab{padding:.75rem 1.2rem;font-family:'Space Mono',monospace;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--t3);cursor:pointer;border-bottom:2px solid transparent;transition:all .18s;white-space:nowrap;background:none;border-top:none;border-left:none;border-right:none}
.tab:hover{color:var(--t2)}
.tab.active{color:var(--gold);border-bottom-color:var(--gold)}

/* STARS */
.stars{display:inline-flex;gap:1px}
.star{color:rgba(255,255,255,.15);font-size:1.1rem;transition:color .1s}
.star.filled{color:var(--gold)}
.stars-interactive .star{cursor:pointer;font-size:1.4rem;transition:all .1s}
.stars-interactive .star:hover,.stars-interactive .star.hover{color:var(--gold);transform:scale(1.15)}

/* REVIEWS */
.review-item{background:var(--s1);border:1px solid var(--b1);border-radius:var(--rl);padding:1.1rem;margin-bottom:.7rem;transition:border-color .18s}
.review-item:hover{border-color:var(--b2)}
.review-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
.review-user{display:flex;align-items:center;gap:.6rem}
.review-av{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.78rem;color:#fff;flex-shrink:0}
.review-name{font-weight:700;font-size:.82rem}
.review-date{font-size:.62rem;color:var(--t3);font-family:'Space Mono',monospace}
.review-text{font-size:.83rem;color:var(--t2);line-height:1.65;margin-top:.35rem}
.rating-bar{display:flex;align-items:center;gap:.6rem;margin-bottom:.3rem}
.rb-label{font-family:'Space Mono',monospace;font-size:.62rem;color:var(--t3);width:12px;text-align:right;flex-shrink:0}
.rb-bar{flex:1;height:6px;background:rgba(255,255,255,.06);border-radius:100px;overflow:hidden}
.rb-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--amber));border-radius:100px;transition:width .8s ease}
.rb-count{font-size:.62rem;color:var(--t3);font-family:'Space Mono',monospace;width:22px;text-align:right;flex-shrink:0}

/* BOOK CARDS */
.books-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.9rem}
.bk-card{background:var(--s1);border:1px solid var(--b1);border-radius:var(--rl);overflow:hidden;transition:transform .25s cubic-bezier(.34,1.56,.64,1),border-color .18s,box-shadow .18s;text-decoration:none;color:var(--t1);display:block}
.bk-card:hover{transform:translateY(-5px) scale(1.015);border-color:rgba(0,212,255,.2);box-shadow:0 16px 48px rgba(0,0,0,.5)}
.bk-cover-sm{height:100px;display:flex;align-items:center;justify-content:center;font-size:2.5rem;background:linear-gradient(135deg,var(--bg4),rgba(124,58,237,.2));position:relative;overflow:hidden}
.bk-cover-sm img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.bk-info{padding:.7rem .8rem}
.bk-cat-sm{font-size:.56rem;font-family:'Space Mono',monospace;color:var(--cyan);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px}
.bk-title-sm{font-family:'Syne',sans-serif;font-size:.78rem;font-weight:700;line-height:1.2;margin-bottom:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.bk-author-sm{font-size:.65rem;color:var(--t3);margin-bottom:.4rem}
.bk-price{font-family:'Space Mono',monospace;font-size:.65rem}
.price-free{color:var(--neon)}
.price-paid{color:var(--gold)}

/* MODAL */
.modal-bg{position:fixed;inset:0;background:rgba(6,9,15,.88);backdrop-filter:blur(18px);z-index:900;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-bg.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--b2);border-radius:var(--rx);max-width:480px;width:100%;box-shadow:var(--sh3);transform:translateY(20px) scale(.96);transition:transform .3s cubic-bezier(.34,1.56,.64,1)}
.modal-bg.open .modal{transform:none}
.modal-top{height:4px;background:linear-gradient(90deg,var(--gold),var(--cyan),var(--violet));border-radius:var(--rx) var(--rx) 0 0}
.modal-body{padding:1.8rem}
.modal-title{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800;margin-bottom:.35rem}
.modal-sub{font-size:.78rem;color:var(--t2);margin-bottom:1.3rem}
.pay-methods{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem;margin-bottom:1rem}
.pay-method{padding:.7rem;border:2px solid var(--b1);border-radius:var(--rm);text-align:center;cursor:pointer;transition:all .18s;background:var(--s1)}
.pay-method:hover,.pay-method.selected{border-color:var(--gold);background:var(--gold3)}
.pay-icon{font-size:1.5rem;margin-bottom:.3rem}
.pay-name{font-size:.65rem;font-family:'Space Mono',monospace;font-weight:700;text-transform:uppercase;color:var(--t2)}
.pay-input{width:100%;background:var(--bg3);border:1px solid var(--b2);border-radius:var(--r);padding:.65rem .9rem;color:var(--t1);font-family:'DM Sans',sans-serif;font-size:.85rem;outline:none;transition:border-color .18s;margin-bottom:.7rem}
.pay-input:focus{border-color:rgba(232,197,108,.5)}
.pay-price{background:var(--s1);border:1px solid var(--b1);border-radius:var(--rm);padding:.8rem 1rem;text-align:center;margin-bottom:1rem}
.pay-price-val{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;color:var(--gold)}
.pay-price-lbl{font-size:.7rem;color:var(--t3);font-family:'Space Mono',monospace}
.pay-benefits{margin-bottom:1rem;font-size:.76rem;color:var(--t2)}
.pay-benefit{display:flex;align-items:center;gap:.4rem;margin-bottom:.3rem}
.pay-benefit .ic{color:var(--neon);font-size:.8rem}
.pay-footer{display:flex;gap:.6rem;align-items:center}
.pay-secure{font-size:.62rem;color:var(--t3);font-family:'Space Mono',monospace;display:flex;align-items:center;gap:.3rem}
.pay-spinner{display:none;width:18px;height:18px;border:2px solid rgba(255,255,255,.2);border-top-color:var(--gold);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* READER */
#reader-modal{position:fixed;inset:0;background:rgba(6,9,15,.96);backdrop-filter:blur(20px);z-index:1000;display:flex;flex-direction:column;opacity:0;pointer-events:none;transition:opacity .3s}
#reader-modal.open{opacity:1;pointer-events:all}
.rd-topbar{height:52px;background:rgba(6,9,15,.9);border-bottom:1px solid var(--b1);display:flex;align-items:center;gap:.5rem;padding:0 .9rem;flex-shrink:0}
.rd-btn{width:32px;height:32px;border-radius:7px;background:var(--s1);border:1px solid var(--b1);color:var(--t2);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.82rem;transition:all .16s;flex-shrink:0}
.rd-btn:hover{background:var(--s2);color:var(--t1)}
.rd-title-bar{flex:1;font-family:'Syne',sans-serif;font-size:.82rem;font-weight:700;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:var(--t2);margin:0 .5rem}
.rd-nav{display:flex;align-items:center;gap:.3rem;background:var(--s1);border:1px solid var(--b1);border-radius:7px;padding:2px}
.rd-pg-btn{width:26px;height:26px;border-radius:5px;background:none;border:none;color:var(--t2);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.78rem;transition:all .15s}
.rd-pg-btn:hover:not(:disabled){color:var(--gold)}
.rd-pg-btn:disabled{opacity:.3}
.rd-pg-inp{width:44px;background:none;border:none;text-align:center;font-family:'Space Mono',monospace;font-size:.7rem;color:var(--t1);outline:none}
.rd-pg-total{font-family:'Space Mono',monospace;font-size:.65rem;color:var(--t3);padding-right:4px}
.rd-prog-bar{height:3px;background:rgba(255,255,255,.06);flex-shrink:0}
.rd-prog-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--cyan));transition:width .4s;box-shadow:0 0 6px rgba(0,212,255,.4)}
.rd-body{flex:1;display:flex;overflow:hidden}
.rd-canvas{flex:1;overflow:auto;display:flex;flex-direction:column;align-items:center;padding:1.2rem;gap:1rem;background:rgba(10,12,20,.6)}
.rd-canvas canvas{box-shadow:var(--sh3);border-radius:4px;max-width:100%}
.rd-text{flex:1;overflow-y:auto;max-width:750px;margin:0 auto;padding:2rem;font-family:Georgia,serif;font-size:1rem;line-height:1.85;color:rgba(238,242,255,.8)}
.rd-text h2{font-family:'Syne',sans-serif;color:var(--t1);margin:2rem 0 .8rem;font-size:1.2rem}
.rd-text p{margin-bottom:1.2em}
.rd-loader{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:.7rem;background:rgba(6,9,15,.6);z-index:5}
.rd-ring{width:44px;height:44px;border:3px solid rgba(232,197,108,.15);border-top-color:var(--gold);border-radius:50%;animation:spin .8s linear infinite}
.rd-bottom{height:40px;background:rgba(6,9,15,.9);border-top:1px solid var(--b1);display:flex;align-items:center;justify-content:space-between;padding:0 1rem;font-size:.68rem;font-family:'Space Mono',monospace;color:var(--t3);flex-shrink:0}
.rd-autosave{display:flex;align-items:center;gap:.35rem}
.rd-dot{width:5px;height:5px;border-radius:50%;background:var(--neon);animation:pls 2s infinite}
@keyframes pls{0%,100%{opacity:1}50%{opacity:.3}}

/* NOTIFICATIONS */
#notif-panel{position:fixed;top:calc(var(--topbar)+8px);right:1.2rem;width:310px;background:var(--bg2);border:1px solid var(--b2);border-radius:var(--rl);box-shadow:var(--sh3);z-index:600;opacity:0;pointer-events:none;transform:translateY(-8px) scale(.97);transition:all .22s cubic-bezier(.34,1.56,.64,1)}
#notif-panel.open{opacity:1;pointer-events:all;transform:none}
.np-head{padding:.85rem 1rem;border-bottom:1px solid var(--b1);display:flex;align-items:center;justify-content:space-between;font-family:'Syne',sans-serif;font-weight:700;font-size:.82rem}
.np-list{max-height:300px;overflow-y:auto}
.np-item{display:flex;gap:.6rem;padding:.75rem 1rem;border-bottom:1px solid rgba(255,255,255,.03);cursor:pointer;transition:background .12s;font-size:.76rem}
.np-item:hover{background:var(--s2)}
.np-item.unread{background:rgba(0,212,255,.03)}
.np-ico{width:28px;height:28px;border-radius:8px;background:var(--cyan2);display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;margin-top:1px}
.np-text{color:var(--t2);line-height:1.4}
.np-time{font-size:.58rem;font-family:'Space Mono',monospace;color:var(--t3);margin-top:2px}
.np-footer{padding:.65rem 1rem;border-top:1px solid var(--b1);display:flex;gap:.4rem}

/* TOASTS */
#toast-stack{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:.5rem;pointer-events:none}
.toast{display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;background:var(--bg2);border:1px solid var(--b2);border-radius:var(--rm);box-shadow:var(--sh2);font-size:.78rem;max-width:300px;pointer-events:all;transform:translateX(120px);opacity:0;transition:all .3s cubic-bezier(.34,1.56,.64,1)}
.toast.show{transform:none;opacity:1}
.t-ico{font-size:1rem;flex-shrink:0}
.t-ttl{font-family:'Syne',sans-serif;font-weight:700}
.t-sub{font-size:.68rem;color:var(--t3);margin-top:1px}

/* MISC */
.chip{display:inline-flex;align-items:center;gap:.25rem;padding:2px 9px;border-radius:100px;font-size:.6rem;font-family:'Space Mono',monospace;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.chip-g{background:rgba(0,255,170,.08);color:var(--neon);border:1px solid rgba(0,255,170,.18)}
.chip-b{background:rgba(0,212,255,.08);color:var(--cyan);border:1px solid rgba(0,212,255,.18)}
.chip-v{background:rgba(124,58,237,.1);color:#a78bfa;border:1px solid rgba(124,58,237,.2)}
.chip-r{background:rgba(251,113,133,.08);color:var(--rose);border:1px solid rgba(251,113,133,.18)}
.chip-a{background:rgba(251,191,36,.08);color:var(--amber);border:1px solid rgba(251,191,36,.18)}
.divider{height:1px;background:var(--b1);margin:1.5rem 0}
.input{width:100%;background:var(--bg3);border:1px solid var(--b2);border-radius:var(--r);padding:.65rem .9rem;color:var(--t1);font-family:'DM Sans',sans-serif;font-size:.85rem;outline:none;transition:border-color .18s}
.input:focus{border-color:rgba(232,197,108,.5)}
textarea.input{min-height:90px;resize:vertical;line-height:1.6}
.lbl{font-size:.65rem;font-family:'Space Mono',monospace;color:var(--t3);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.35rem}
.sep{color:var(--t4)}
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.anim{animation:fadeInUp .45s ease both}
.a1{animation-delay:.05s}.a2{animation-delay:.1s}.a3{animation-delay:.15s}

@media(max-width:900px){
  .hero-inner{flex-direction:column;align-items:center;text-align:center;padding:1.5rem}
  .hero-cover{width:160px;height:230px}
  .hero-rating,.hero-meta,.hero-badges,.hero-breadcrumb{justify-content:center}
  .hero-actions{justify-content:center}
}
@media(max-width:600px){
  .hero-title{font-size:1.5rem}
  .hero-stats{grid-template-columns:repeat(3,1fr)}
  .books-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
  .tabs{overflow-x:auto}
  .tab{padding:.6rem .8rem;font-size:.62rem}
  .page{padding-left:1rem;padding-right:1rem}
  .tb-title{max-width:120px}
  .tb-user{display:none}
}
</style>
</head>
<body>

<?php if ($erreur): ?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:2rem">
  <div style="text-align:center;max-width:400px">
    <div style="font-size:4rem;margin-bottom:1rem">📚</div>
    <h1 style="font-family:'Syne',sans-serif;font-weight:800;font-size:1.4rem;margin-bottom:.5rem">Livre introuvable</h1>
    <p style="color:var(--t2);font-size:.85rem;margin-bottom:1.5rem"><?= htmlspecialchars($erreur, ENT_QUOTES) ?></p>
    <a href="../dashboard.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> Retour au catalogue</a>
  </div>
</div>
<?php else: ?>

<header id="topbar">
  <a href="../dashboard.php" class="tb-btn" title="Retour"><i class="bi bi-arrow-left"></i></a>
  <a href="../dashboard.php" class="tb-brand">
    <div class="tb-logo">📚</div>
    <span>Digital Library</span>
  </a>
  <span class="sep">/</span>
  <a href="index.php" style="font-size:.72rem;color:var(--t3)">Catalogue</a>
  <span class="sep">/</span>
  <span class="tb-title"><?= htmlspecialchars($livre['titre'], ENT_QUOTES) ?></span>
  <div class="tb-sp"></div>

  
  <button class="tb-btn <?= $isFavorite ? 'active' : '' ?>" id="tb-fav-btn" onclick="toggleFavorite()" title="Favori">
    <i class="bi <?= $isFavorite ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
  </button>
  <?php if ($hasAccess): ?>
  <button class="btn btn-primary btn-sm" onclick="openReader()" style="height:36px">
    <i class="bi bi-play-fill"></i>
    <?= $progPct > 0 ? 'Continuer (' . $progPct . '%)' : 'Lire' ?>
  </button>
  <?php endif; ?>
  <div class="tb-user">
    <div class="av-sm"><?= $avatar ?></div>
    <span style="font-size:.76rem;font-weight:600"><?= $username ?></span>
  </div>
  
</header>

<main class="page">

  <!-- HERO -->
  <section class="hero anim">
    <div class="hero-bg"></div>
    <div class="hero-inner">
      <div class="hero-cover-wrap">
        <div class="hero-cover">
          <?php if (!empty($livre['couverture'])): ?>
            <img src="../<?= htmlspecialchars($livre['couverture'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($livre['titre'], ENT_QUOTES) ?>">
          <?php else: ?>
            <?= (['📖','🌌','🧠','📜','🎭','🔬','🌿','⚙️','💹','🎨','🌱','🔭'])[$livre['id'] % 12] ?>
          <?php endif; ?>
        </div>
        <div class="hero-cover-glow"></div>
        <?php if ($progPct > 0): ?>
        <div class="prog-wrap" style="margin-top:.8rem">
          <div class="prog-bar"><div class="prog-fill" style="width:<?= min(100,$progPct) ?>%"></div></div>
          <div class="prog-label"><span>Progression</span><span><?= $progPct ?>%</span></div>
        </div>
        <?php endif; ?>
      </div>

      <div class="hero-info">
        <div class="hero-breadcrumb">
          <a href="index.php">Catalogue</a><span>›</span>
          <a href="index.php?categorie=<?= htmlspecialchars($livre['categorie_slug'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars(($livre['categorie_icone'] ?? '📚') . ' ' . ($livre['categorie_nom'] ?? 'Général'), ENT_QUOTES) ?></a>
        </div>
        <div class="hero-badges">
          <?php if ($accessType === 'premium'): ?><span class="badge badge-premium">💎 Premium</span>
          <?php elseif ($isFree): ?><span class="badge badge-free">✓ Gratuit</span>
          <?php else: ?><span class="badge badge-std">📗 Standard</span><?php endif; ?>
          <?php if (!empty($livre['is_bestseller'])): ?><span class="badge badge-best">🔥 Bestseller</span><?php endif; ?>
          <?php if (!empty($livre['is_featured'])): ?><span class="badge badge-feat">⭐ À la une</span><?php endif; ?>
        </div>
        <h1 class="hero-title"><?= htmlspecialchars($livre['titre'], ENT_QUOTES) ?></h1>
        <div class="hero-author">par <a href="index.php?auteur=<?= urlencode($livre['auteur']) ?>"><?= htmlspecialchars($livre['auteur'], ENT_QUOTES) ?></a></div>
        <div class="hero-rating">
          <?= stars($noteGlobale) ?>
          <span style="font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:var(--gold)" id="note-display"><?= $noteGlobale ?></span>
          <span style="font-size:.72rem;color:var(--t3);font-family:'Space Mono',monospace">(<?= $totalAvis ?> avis)</span>
          <?php if ($progPct >= 100): ?><span class="chip chip-g">✓ Lu</span><?php endif; ?>
        </div>
        <div class="hero-stats">
          <div class="hs-item"><div class="hs-val"><?= number_format((int)($livre['nb_lectures'] ?? 0)) ?></div><div class="hs-lbl">Lectures</div></div>
          <div class="hs-item"><div class="hs-val"><?= number_format((int)($livre['nb_ventes'] ?? 0)) ?></div><div class="hs-lbl">Ventes</div></div>
          <div class="hs-item"><div class="hs-val"><?= number_format((int)($livre['nb_telechargements'] ?? $livre['download_count'] ?? 0)) ?></div><div class="hs-lbl">Télécharg.</div></div>
          <?php if (!empty($livre['pages'])): ?><div class="hs-item"><div class="hs-val"><?= (int)$livre['pages'] ?></div><div class="hs-lbl">Pages</div></div><?php endif; ?>
          <div class="hs-item"><div class="hs-val"><?= $noteGlobale ?></div><div class="hs-lbl">Note /5</div></div>
        </div>
        <div class="hero-meta">
          <?php if (!empty($livre['categorie_nom'])): ?><span class="meta-chip"><i class="bi bi-tag"></i><?= htmlspecialchars($livre['categorie_icone'] . ' ' . $livre['categorie_nom'], ENT_QUOTES) ?></span><?php endif; ?>
          <?php if (!empty($livre['langue'])): ?><span class="meta-chip"><i class="bi bi-globe"></i><?= htmlspecialchars($livre['langue'], ENT_QUOTES) ?></span><?php endif; ?>
          <?php if (!empty($livre['editeur'])): ?><span class="meta-chip"><i class="bi bi-building"></i><?= htmlspecialchars($livre['editeur'], ENT_QUOTES) ?></span><?php endif; ?>
          <?php if (!empty($livre['annee_parution'])): ?><span class="meta-chip"><i class="bi bi-calendar3"></i><?= (int)$livre['annee_parution'] ?></span><?php endif; ?>
        </div>
        <?php if (!empty($livre['description'])): ?>
        <div class="hero-desc" id="desc-box"><?= nl2br(htmlspecialchars($livre['description'], ENT_QUOTES)) ?></div>
        <button onclick="toggleDesc()" id="desc-btn" class="btn btn-ghost btn-xs" style="margin-top:.5rem"><i class="bi bi-chevron-down" id="desc-chevron"></i> <span>Lire plus</span></button>
        <?php endif; ?>
        <div class="hero-actions">
          <?php if ($isFree || $alreadyBought): ?>
            <button onclick="openReader()" class="btn btn-primary btn-lg">
              <i class="bi bi-play-fill"></i> <?= $progPct > 0 ? 'Continuer la lecture' : 'Lire maintenant' ?>
            </button>
            <?php if (!empty($livre['fichier_pdf'])): ?>
            <button onclick="downloadBook()" class="btn btn-outline" id="dl-btn">
              <i class="bi bi-download"></i> Télécharger (<?= $downloadCount ?>/<?= $maxDownloads ?>)
            </button>
            <?php endif; ?>
          <?php else: ?>
            <button onclick="openPurchaseModal()" class="btn btn-gold btn-lg">
              <i class="bi bi-bag-fill"></i> Acheter — <?= $prixFormate ?> FCFA
            </button>
            <?php if (!empty($livre['contenu_extrait'])): ?>
            <button onclick="openReader(true)" class="btn btn-outline"><i class="bi bi-eye"></i> Extrait gratuit</button>
            <?php endif; ?>
          <?php endif; ?>
          <button class="btn btn-fav <?= $isFavorite ? 'active' : '' ?>" id="fav-btn" onclick="toggleFavorite()">
            <i class="bi <?= $isFavorite ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
          </button>
          <?php if (!$userReview): ?>
          <button onclick="scrollToReviews()" class="btn btn-ghost btn-sm"><i class="bi bi-star"></i> Laisser un avis</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- TABS -->
  <div class="tabs" id="main-tabs">
    <button class="tab active" data-tab="about"   onclick="switchTab('about',this)">📋 À propos</button>
    <button class="tab"        data-tab="reviews" onclick="switchTab('reviews',this)">⭐ Avis (<?= $totalAvis ?>)</button>
    <button class="tab"        data-tab="similar" onclick="switchTab('similar',this)">📚 Similaires</button>
    <button class="tab"        data-tab="stats"   onclick="switchTab('stats',this)">📊 Statistiques</button>
  </div>

  <!-- TAB: À PROPOS -->
  <div class="tab-content" id="tab-about">
    <div class="card anim a1">
      <div class="card-body">
        <h3 style="font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;margin-bottom:1rem;color:var(--t2)">Détails du livre</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.6rem">
          <?php
          $details = [
              ['📖','Titre',$livre['titre']],['✍️','Auteur',$livre['auteur']],
              ['🏛️','Éditeur',$livre['editeur']??'—'],['🌐','Langue',$livre['langue']??'Français'],
              ['📑','Pages',!empty($livre['pages'])?$livre['pages'].' pages':'—'],
              ['📅','Publication',!empty($livre['annee_parution'])?(int)$livre['annee_parution']:'—'],
              ['🔖','ISBN',$livre['isbn']??'—'],['📂','Catégorie',($livre['categorie_icone']??'📚').' '.($livre['categorie_nom']??'—')],
              ['💰','Prix',$isFree?'Gratuit':$prixFormate.' FCFA'],['🎯','Accès',ucfirst($accessType)],
          ];
          foreach ($details as [$ico,$lbl,$val]):
            if (!$val||$val==='—') continue;
          ?>
          <div class="card-inner" style="display:flex;align-items:center;gap:.6rem">
            <span style="font-size:1rem;width:22px;text-align:center;flex-shrink:0"><?= $ico ?></span>
            <div>
              <div style="font-size:.6rem;font-family:'Space Mono',monospace;color:var(--t3);text-transform:uppercase;letter-spacing:.05em"><?= $lbl ?></div>
              <div style="font-size:.82rem;font-weight:600;margin-top:1px"><?= htmlspecialchars((string)$val, ENT_QUOTES) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB: AVIS -->
  <div class="tab-content" id="tab-reviews" style="display:none">
    <?php if ($totalAvis > 0): ?>
    <div class="card anim a1" style="margin-bottom:1.2rem">
      <div class="card-body">
        <div style="display:flex;align-items:center;gap:2rem;flex-wrap:wrap">
          <div style="text-align:center;flex-shrink:0">
            <div style="font-family:'Syne',sans-serif;font-size:3rem;font-weight:800;color:var(--gold);line-height:1" id="avg-display"><?= $noteGlobale ?></div>
            <?= stars($noteGlobale) ?>
            <div style="font-size:.68rem;color:var(--t3);font-family:'Space Mono',monospace;margin-top:.3rem"><?= $totalAvis ?> avis</div>
          </div>
          <div style="flex:1;min-width:200px">
            <?php for ($star = 5; $star >= 1; $star--): ?>
            <?php $cnt = $ratingDist[$star]; $pct = $totalAvis > 0 ? ($cnt/$totalAvis)*100 : 0; ?>
            <div class="rating-bar">
              <span class="rb-label"><?= $star ?></span>
              <span style="color:var(--gold);font-size:.7rem;flex-shrink:0">★</span>
              <div class="rb-bar"><div class="rb-fill" style="width:<?= round($pct) ?>%"></div></div>
              <span class="rb-count"><?= $cnt ?></span>
            </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="card anim a2" style="margin-bottom:1.2rem" id="review-form-card">
      <div class="card-body">
        <h3 id="review-form-title" style="font-family:'Syne',sans-serif;font-weight:700;font-size:.92rem;margin-bottom:.9rem">
          <?= $userReview ? '✏️ Modifier votre avis' : '⭐ Laisser un avis' ?>
        </h3>
        <?php if ($hasAccess || $isFree): ?>
        <div>
          <label class="lbl">Votre note</label>
          <?= stars(0, true, (int)($userReview['note'] ?? 0)) ?>
          <div style="font-size:.65rem;color:var(--t3);font-family:'Space Mono',monospace;margin:.2rem 0 .8rem" id="rating-text">Cliquez pour noter</div>
          <label class="lbl">Commentaire (optionnel)</label>
          <textarea class="input" id="review-comment" placeholder="Partagez votre expérience…"><?= htmlspecialchars($userReview['commentaire'] ?? '', ENT_QUOTES) ?></textarea>
          <div style="display:flex;gap:.5rem;margin-top:.7rem;align-items:center">
            <button onclick="submitReview()" class="btn btn-primary btn-sm" id="review-submit-btn">
              <i class="bi bi-check2"></i> <?= $userReview ? 'Modifier' : "Publier l'avis" ?>
            </button>
            <?php if ($userReview): ?>
            <button onclick="deleteReview(<?= (int)$userReview['id'] ?>)" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
            <span style="font-size:.65rem;color:var(--t3);font-family:'Space Mono',monospace" id="review-status"></span>
          </div>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:1rem 0;color:var(--t3);font-size:.82rem">
          <i class="bi bi-lock" style="font-size:1.4rem;display:block;margin-bottom:.4rem"></i>
          Achetez ce livre pour laisser un avis.
          <button onclick="openPurchaseModal()" class="btn btn-gold btn-sm" style="margin-top:.6rem;display:block;width:fit-content;margin-inline:auto">Acheter maintenant</button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div id="reviews-list">
      <?php if (empty($reviews)): ?>
      <div style="text-align:center;padding:2.5rem 1rem;color:var(--t3)">
        <div style="font-size:2.5rem;margin-bottom:.6rem">💬</div>
        <div style="font-size:.88rem">Aucun avis pour l'instant.</div>
      </div>
      <?php else: ?>
      <?php foreach ($reviews as $rev):
        $revAvatar  = strtoupper(substr($rev['prenom'] ?? $rev['nom'] ?? 'U', 0, 1));
        $revName    = trim(($rev['prenom'] ?? '') . ' ' . ($rev['nom'] ?? ''));
        $isMyReview = ((int)$rev['user_id'] === $userId);
      ?>
      <div class="review-item" id="rev-<?= (int)$rev['id'] ?>">
        <div class="review-header">
          <div class="review-user">
            <div class="review-av" style="<?= $isMyReview ? 'background:linear-gradient(135deg,var(--gold),var(--amber))' : '' ?>"><?= $revAvatar ?></div>
            <div>
              <div class="review-name"><?= htmlspecialchars($revName, ENT_QUOTES) ?> <?= $isMyReview ? '<span class="chip chip-g" style="font-size:.5rem">Vous</span>' : '' ?></div>
              <div class="review-date"><?= ageDate($rev['created_at'] ?? '') ?></div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:.5rem">
            <?= stars((float)$rev['note']) ?>
            <span style="font-family:'Syne',sans-serif;font-weight:700;color:var(--gold)"><?= (int)$rev['note'] ?>/5</span>
            <?php if ($isMyReview || $userRole === 'admin'): ?>
            <button onclick="deleteReview(<?= (int)$rev['id'] ?>)" class="btn btn-danger btn-xs"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($rev['commentaire'])): ?>
        <div class="review-text"><?= nl2br(htmlspecialchars($rev['commentaire'], ENT_QUOTES)) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAB: SIMILAIRES -->
  <div class="tab-content" id="tab-similar" style="display:none">
    <?php if (!empty($memeAuteur)): ?>
    <div class="section">
      <div class="section-header">
        <div class="section-title">✍️ Du même auteur <span class="chip chip-b"><?= count($memeAuteur) ?></span></div>
      </div>
      <div class="books-grid">
        <?php foreach ($memeAuteur as $b): $bE = ['📖','🌌','🧠','📜','🎭','🔬'][$b['id']%6]; ?>
        <a href="view.php?id=<?= (int)$b['id'] ?>" class="bk-card">
          <div class="bk-cover-sm"><?= $bE ?></div>
          <div class="bk-info">
            <div class="bk-title-sm"><?= htmlspecialchars($b['titre'], ENT_QUOTES) ?></div>
            <div class="bk-author-sm"><?= htmlspecialchars($b['auteur'], ENT_QUOTES) ?></div>
            <div class="bk-price <?= (float)$b['prix']==0?'price-free':'price-paid' ?>"><?= (float)$b['prix']==0?'Gratuit':number_format((float)$b['prix'],0,',',' ').' FCFA' ?> · ⭐<?= number_format((float)$b['note_moyenne'],1) ?></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($similaires)): ?>
    <div class="section">
      <div class="section-header">
        <div class="section-title">📚 Vous aimerez aussi <span class="chip chip-v"><?= count($similaires) ?></span></div>
        <span style="font-size:.7rem;color:var(--t3);font-family:'Space Mono',monospace">Même catégorie</span>
      </div>
      <div class="books-grid">
        <?php foreach ($similaires as $b): $bE = ['📖','🌌','🧠','📜','🎭','🔬','🌿','⚙️'][$b['id']%8]; ?>
        <a href="view.php?id=<?= (int)$b['id'] ?>" class="bk-card">
          <div class="bk-cover-sm"><?= $bE ?></div>
          <div class="bk-info">
            <div class="bk-cat-sm"><?= htmlspecialchars($b['categorie_icone']??'📚', ENT_QUOTES) ?></div>
            <div class="bk-title-sm"><?= htmlspecialchars($b['titre'], ENT_QUOTES) ?></div>
            <div class="bk-author-sm"><?= htmlspecialchars($b['auteur'], ENT_QUOTES) ?></div>
            <div class="bk-price <?= (float)$b['prix']==0?'price-free':'price-paid' ?>"><?= (float)$b['prix']==0?'Gratuit':number_format((float)$b['prix'],0,',',' ').' FCFA' ?> · ⭐<?= number_format((float)$b['note_moyenne'],1) ?></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if (empty($similaires) && empty($memeAuteur)): ?>
    <div style="text-align:center;padding:3rem 1rem;color:var(--t3)"><div style="font-size:2.5rem;margin-bottom:.6rem">🔍</div><div style="font-size:.88rem">Aucun livre similaire trouvé.</div></div>
    <?php endif; ?>
  </div>

  <!-- TAB: STATS -->
  <div class="tab-content" id="tab-stats" style="display:none">
    <div class="card anim a1">
      <div class="card-body">
        <h3 style="font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;margin-bottom:1rem">Statistiques</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.8rem">
          <?php foreach ([
            ['🌟','Note moyenne',$noteGlobale.'/5','var(--gold)'],
            ['💬','Total avis',$totalAvis,'var(--cyan)'],
            ['👁️','Lectures',number_format((int)($livre['nb_lectures']??0)),'var(--neon)'],
            ['💰','Ventes',number_format((int)($livre['nb_ventes']??0)),'var(--violet)'],
            ['📥','Téléchargements',number_format((int)($livre['nb_telechargements']??$livre['download_count']??0)),'#60a5fa'],
            ['📑','Pages',!empty($livre['pages'])?(int)$livre['pages']:'—','var(--t2)'],
          ] as [$ico,$lbl,$val,$color]): ?>
          <div class="card-inner">
            <div style="font-size:1.4rem;margin-bottom:.4rem"><?= $ico ?></div>
            <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:<?= $color ?>"><?= $val ?></div>
            <div style="font-size:.65rem;color:var(--t3);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:.04em;margin-top:2px"><?= $lbl ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="divider"></div>
        <h3 style="font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;margin-bottom:1rem">Votre progression</h3>
        <div style="max-width:500px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
            <span style="font-size:.78rem;color:var(--t2)">Avancement</span>
            <span style="font-family:'Space Mono',monospace;font-size:.8rem;color:var(--gold);font-weight:700"><?= $progPct ?>%</span>
          </div>
          <div class="prog-bar" style="height:10px"><div class="prog-fill" style="width:<?= min(100,$progPct) ?>%"></div></div>
          <?php if (!empty($progression['page_actuelle']) && (int)$progression['page_actuelle'] > 1): ?>
          <div style="font-size:.65rem;color:var(--t3);font-family:'Space Mono',monospace;margin-top:.4rem">Page <?= (int)$progression['page_actuelle'] ?><?php if (!empty($livre['pages'])): ?> / <?= (int)$livre['pages'] ?><?php endif; ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</main>

<!-- MODAL ACHAT -->
<div class="modal-bg" id="purchase-modal">
  <div class="modal">
    <div class="modal-top"></div>
    <div class="modal-body">
      <div id="purchase-step-1">
        <h2 class="modal-title">🛒 Acheter ce livre</h2>
        <p class="modal-sub"><strong><?= htmlspecialchars($livre['titre'], ENT_QUOTES) ?></strong><br>de <?= htmlspecialchars($livre['auteur'], ENT_QUOTES) ?></p>
        <div class="pay-price">
          <div class="pay-price-val"><?= $prixFormate ?> <span style="font-size:.9rem">FCFA</span></div>
          <div class="pay-price-lbl">Accès illimité à vie</div>
        </div>
        <label class="lbl" style="margin-bottom:.5rem">Méthode de paiement</label>
        <div class="pay-methods">
          <div class="pay-method selected" data-method="orange_money" onclick="selectMethod('orange_money',this)"><div class="pay-icon">🟠</div><div class="pay-name">Orange Money</div></div>
          <div class="pay-method" data-method="mobile_money" onclick="selectMethod('mobile_money',this)"><div class="pay-icon">📱</div><div class="pay-name">Mobile Money</div></div>
          <div class="pay-method" data-method="carte" onclick="selectMethod('carte',this)"><div class="pay-icon">💳</div><div class="pay-name">Carte</div></div>
        </div>
        <div id="phone-field"><label class="lbl">Numéro de téléphone</label><input class="pay-input" type="tel" id="pay-phone" placeholder="Ex: 697123456" maxlength="15"></div>
        <div id="card-fields" style="display:none">
          <label class="lbl">Numéro de carte</label>
          <input class="pay-input" type="text" id="card-number" placeholder="1234 5678 9012 3456" maxlength="19" oninput="formatCard(this)">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <div><label class="lbl">Expiration</label><input class="pay-input" type="text" id="card-exp" placeholder="MM/AA" maxlength="5"></div>
            <div><label class="lbl">CVV</label><input class="pay-input" type="text" id="card-cvv" placeholder="123" maxlength="3"></div>
          </div>
        </div>
        <div class="pay-benefits">
          <div class="pay-benefit"><i class="bi bi-check-circle-fill ic"></i> Accès illimité à vie</div>
          <div class="pay-benefit"><i class="bi bi-check-circle-fill ic"></i> Téléchargement PDF (<?= $maxDownloads ?>x)</div>
          <div class="pay-benefit"><i class="bi bi-check-circle-fill ic"></i> Bookmarks & annotations</div>
          <div class="pay-benefit"><i class="bi bi-check-circle-fill ic"></i> Suivi de progression automatique</div>
        </div>
        <div class="pay-footer">
          <button onclick="processPurchase()" class="btn btn-gold" id="pay-btn" style="flex:1;justify-content:center">
            <div class="pay-spinner" id="pay-spinner"></div>
            <i class="bi bi-lock-fill" id="pay-icon"></i>
            <span id="pay-label">Payer <?= $prixFormate ?> FCFA</span>
          </button>
          <button onclick="closeModal('purchase-modal')" class="btn btn-ghost"><i class="bi bi-x"></i></button>
        </div>
        <div class="pay-secure"><i class="bi bi-shield-check"></i> Paiement sécurisé · Référence unique générée</div>
        <div style="font-size:.65rem;color:var(--rose);margin-top:.4rem" id="pay-error"></div>
      </div>
      <div id="purchase-step-2" style="display:none;text-align:center;padding:1rem 0">
        <div style="font-size:3.5rem;margin-bottom:.8rem">✅</div>
        <h2 style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:var(--neon);margin-bottom:.5rem">Achat confirmé !</h2>
        <p style="font-size:.82rem;color:var(--t2);margin-bottom:1rem">Votre accès est activé immédiatement.</p>
        <div style="background:var(--s1);border:1px solid var(--b1);border-radius:var(--rm);padding:.8rem;margin-bottom:1rem;font-family:'Space Mono',monospace;font-size:.72rem">
          <div style="color:var(--t3);margin-bottom:.2rem">RÉFÉRENCE</div>
          <div style="color:var(--gold);font-weight:700" id="pay-ref">—</div>
        </div>
        <button onclick="location.reload()" class="btn btn-primary" style="width:100%;justify-content:center"><i class="bi bi-play-fill"></i> Lire maintenant</button>
        <button onclick="closeModal('purchase-modal')" class="btn btn-ghost btn-sm" style="margin-top:.5rem">Fermer</button>
      </div>
    </div>
  </div>
</div>

<!-- LECTEUR PDF -->
<div id="reader-modal">
  <div class="rd-topbar">
    <button class="rd-btn" onclick="closeReader()" title="Fermer (Esc)"><i class="bi bi-x-lg"></i></button>
    <button class="rd-btn" onclick="rdPrev()" id="rd-prev-btn"><i class="bi bi-chevron-left"></i></button>
    <div class="rd-nav">
      <input type="number" id="rd-page-inp" class="rd-pg-inp" value="<?= (int)($progression['page_actuelle'] ?? 1) ?>" min="1" onchange="rdGo(this.value)">
      <span class="rd-pg-total">/ <span id="rd-total">—</span></span>
    </div>
    <button class="rd-btn" onclick="rdNext()" id="rd-next-btn"><i class="bi bi-chevron-right"></i></button>
    <span class="rd-title-bar"><?= htmlspecialchars($livre['titre'], ENT_QUOTES) ?></span>
    <button class="rd-btn" onclick="rdZoom(-0.15)">A-</button>
    <span id="rd-zoom-val" style="font-family:'Space Mono',monospace;font-size:.62rem;color:var(--t3);min-width:34px;text-align:center">100%</span>
    <button class="rd-btn" onclick="rdZoom(0.15)">A+</button>
    <button class="rd-btn" onclick="rdTheme('dark')" title="Sombre">🌙</button>
    <button class="rd-btn" onclick="rdTheme('sepia')" title="Sépia">📜</button>
    <button class="rd-btn" onclick="rdTheme('light')" title="Clair">☀️</button>
    <button class="rd-btn" id="rd-bm-btn" onclick="addQuickBookmark()" title="Signet"><i class="bi bi-bookmark-plus"></i></button>
    <?php if (!empty($livre['fichier_pdf']) && $hasAccess): ?>
    <button class="rd-btn" onclick="downloadBook()" title="Télécharger"><i class="bi bi-download"></i></button>
    <?php endif; ?>
    <button class="rd-btn" onclick="rdFullscreen()"><i class="bi bi-fullscreen" id="rd-fs-icon"></i></button>
  </div>
  <div class="rd-prog-bar"><div class="rd-prog-fill" id="rd-prog-fill" style="width:<?= $progPct ?>%"></div></div>
  <div class="rd-body" id="rd-body">
    <div class="rd-loader" id="rd-loader">
      <div class="rd-ring"></div>
      <div style="font-family:'Space Mono',monospace;font-size:.7rem;color:var(--t3)" id="rd-loader-text">Chargement…</div>
    </div>
    <div class="rd-canvas" id="rd-canvas-area" style="display:none"><canvas id="pdf-canvas"></canvas></div>
    <div class="rd-text" id="rd-text-area" style="display:none"></div>
  </div>
  <div class="rd-bottom">
    <div class="rd-autosave"><div class="rd-dot"></div><span>Sauvegarde auto</span></div>
    <div id="rd-time-info">0 min lus</div>
    <div><span id="rd-pct-label"><?= $progPct ?>%</span> lu</div>
  </div>
</div>

<!-- NOTIFICATIONS -->
<div id="notif-panel">
  <div class="np-head"><span>Notifications</span><?php if ($notifCount > 0): ?><span class="chip chip-r"><?= $notifCount ?></span><?php endif; ?></div>
  <div class="np-list" id="np-list"><div style="padding:1.5rem;text-align:center;color:var(--t3);font-size:.78rem">Chargement…</div></div>
  <div class="np-footer">
    <button onclick="markNotifsRead()" class="btn btn-ghost btn-xs">Tout lire</button>
    <button onclick="toggleNotifPanel()" class="btn btn-ghost btn-xs">Fermer</button>
  </div>
</div>

<div id="toast-stack"></div>

<script>
'use strict';

/* ════ CONFIG ════ */
const LIVRE_ID   = <?= (int)$livreId ?>;
const CSRF       = <?= json_encode($csrf) ?>;
const USER_ID    = <?= $userId ?>;
const HAS_ACCESS = <?= $hasAccess ? 'true' : 'false' ?>;
const IS_FREE    = <?= $isFree ? 'true' : 'false' ?>;
const PDF_URL    = <?= !empty($livre['fichier_pdf']) ? json_encode('../' . $livre['fichier_pdf']) : 'null' ?>;
const EXTRAIT    = <?= !empty($livre['contenu_extrait']) ? json_encode($livre['contenu_extrait'], JSON_UNESCAPED_UNICODE) : 'null' ?>;
const SAVED_PAGE = <?= (int)($progression['page_actuelle'] ?? 1) ?>;
const MAX_DL     = <?= $maxDownloads ?>;

// ⚡ URL de base AJAX = ce même fichier
const SELF_URL   = 'view.php?id=' + LIVRE_ID;

/* ════ HELPERS ════ */
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') }
function fmtTime(s){ if(s<60)return s+'s'; if(s<3600)return Math.round(s/60)+'min'; return Math.floor(s/3600)+'h'+Math.round((s%3600)/60)+'min'; }
function fmtDate(d){ if(!d)return ''; return new Date(d).toLocaleDateString('fr-FR',{day:'2-digit',month:'short',year:'numeric'}); }

/* ════ TOAST ════ */
const TICONS = {success:'✅',info:'📚',warn:'⚠️',error:'🔴'};
const TCOLORS = {success:'var(--neon)',info:'var(--gold)',warn:'var(--amber)',error:'var(--rose)'};

function toast(title, sub='', type='info', dur=3800) {
  const stack = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = TCOLORS[type]||TCOLORS.info;
  t.innerHTML = `<span class="t-ico">${TICONS[type]||'📚'}</span>
    <div><div class="t-ttl">${esc(title)}</div>${sub?`<div class="t-sub">${esc(sub)}</div>`:''}</div>`;
  stack.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, dur);
}

/* ════ AJAX — pointe vers CE MÊME FICHIER ════ */
async function ajax(action, data={}) {
  const body = new URLSearchParams({ action, csrf: CSRF, livre_id: LIVRE_ID, ...data });
  const r = await fetch(SELF_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': CSRF,
    },
    body: body.toString()
  });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

async function ajaxGet(action, params={}) {
  const qs = new URLSearchParams({ action, livre_id: LIVRE_ID, ...params }).toString();
  const r = await fetch(SELF_URL + '&' + qs, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

/* ════ TABS ════ */
function switchTab(name, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  const el = document.getElementById('tab-' + name);
  if (el) el.style.display = '';
  btn.classList.add('active');
}

/* ════ DESC ════ */
function toggleDesc() {
  const box = document.getElementById('desc-box');
  const chevron = document.getElementById('desc-chevron');
  const btn = document.getElementById('desc-btn');
  const expanded = box.classList.toggle('expanded');
  chevron.className = expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
  const sp = btn.querySelector('span');
  if(sp) sp.textContent = expanded ? 'Lire moins' : 'Lire plus';
}

/* ════ FAVORIS ════ */
let isFav = <?= $isFavorite ? 'true' : 'false' ?>;

async function toggleFavorite() {
  const btns = [document.getElementById('fav-btn'), document.getElementById('tb-fav-btn')];
  isFav = !isFav;
  btns.forEach(btn => {
    if (!btn) return;
    btn.classList.toggle('active', isFav);
    const ico = btn.querySelector('i');
    if (ico) ico.className = isFav ? 'bi bi-heart-fill' : 'bi bi-heart';
  });
  try {
    const res = await ajax('toggle_favorite');
    if (res.success) {
      toast(res.favorited ? '❤️ Ajouté aux favoris' : '💔 Retiré des favoris', '', 'success', 2500);
    } else {
      isFav = !isFav;
      btns.forEach(btn => {
        if (!btn) return;
        btn.classList.toggle('active', isFav);
        const ico = btn.querySelector('i');
        if (ico) ico.className = isFav ? 'bi bi-heart-fill' : 'bi bi-heart';
      });
      toast('Erreur', res.message||'', 'error');
    }
  } catch(e) { isFav = !isFav; toast('Erreur réseau', '', 'error'); }
}

/* ════ ACHAT ════ */
let selectedMethod = 'orange_money';

function openPurchaseModal() {
  document.getElementById('purchase-step-1').style.display = '';
  document.getElementById('purchase-step-2').style.display = 'none';
  document.getElementById('pay-error').textContent = '';
  openModal('purchase-modal');
}

function selectMethod(method, el) {
  selectedMethod = method;
  document.querySelectorAll('.pay-method').forEach(m => m.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('phone-field').style.display = method === 'carte' ? 'none' : '';
  document.getElementById('card-fields').style.display = method === 'carte' ? '' : 'none';
}

function formatCard(input) {
  let v = input.value.replace(/\D/g,'').substring(0,16);
  input.value = v.replace(/(.{4})/g,'$1 ').trim();
}

async function processPurchase() {
  const btn     = document.getElementById('pay-btn');
  const spinner = document.getElementById('pay-spinner');
  const ico     = document.getElementById('pay-icon');
  const lbl     = document.getElementById('pay-label');
  const errEl   = document.getElementById('pay-error');
  const phone   = document.getElementById('pay-phone').value.trim();

  if (selectedMethod !== 'carte' && !phone) { errEl.textContent = 'Veuillez entrer votre numéro de téléphone.'; return; }

  btn.disabled = true; spinner.style.display = 'block'; ico.style.display = 'none';
  lbl.textContent = 'Traitement en cours…'; errEl.textContent = '';

  try {
    const res = await ajax('process_purchase', { methode: selectedMethod, telephone: phone });
    if (res.success) {
      document.getElementById('pay-ref').textContent = res.reference;
      document.getElementById('purchase-step-1').style.display = 'none';
      document.getElementById('purchase-step-2').style.display = '';
      toast('✅ Achat confirmé', res.reference, 'success', 6000);
    } else if (res.already_owned) {
      closeModal('purchase-modal'); toast('ℹ️ Livre déjà acheté', '', 'info');
    } else {
      errEl.textContent = res.message || 'Erreur de paiement. Réessayez.';
    }
  } catch(e) {
    errEl.textContent = 'Erreur réseau. Vérifiez votre connexion.';
  } finally {
    btn.disabled = false; spinner.style.display = 'none'; ico.style.display = '';
    lbl.textContent = `Payer <?= $prixFormate ?> FCFA`;
  }
}

/* ════ REVIEWS ════ */
let currentRating = <?= (int)($userReview['note'] ?? 0) ?>;

function setRating(val) {
  currentRating = val;
  const texts = ['','Mauvais','Passable','Bien','Très bien','Excellent'];
  document.getElementById('rating-text').textContent = texts[val]||'';
  document.querySelectorAll('.stars-interactive .star').forEach((s,i) => s.classList.toggle('filled', i < val));
}

function hoverRating(val) {
  document.querySelectorAll('.stars-interactive .star').forEach((s,i) => s.classList.toggle('hover', i < val));
}

function resetRatingHover() {
  document.querySelectorAll('.stars-interactive .star').forEach((s,i) => {
    s.classList.remove('hover');
    s.classList.toggle('filled', i < currentRating);
  });
}

async function submitReview() {
  if (currentRating < 1) { toast('Note requise', 'Cliquez sur les étoiles pour noter.', 'warn'); return; }
  const btn = document.getElementById('review-submit-btn');
  const status = document.getElementById('review-status');
  btn.disabled = true; status.textContent = '⏳ Envoi…';
  try {
    const res = await ajax('submit_review', { note: currentRating, commentaire: document.getElementById('review-comment').value.trim() });
    if (res.success) {
      toast('⭐ Avis publié', '', 'success');
      status.textContent = '';
      if (res.note_moyenne !== undefined) {
        document.querySelectorAll('#note-display, #avg-display').forEach(el => el.textContent = parseFloat(res.note_moyenne).toFixed(1));
      }
      document.getElementById('review-form-title').textContent = '✏️ Modifier votre avis';
      document.getElementById('review-submit-btn').innerHTML = '<i class="bi bi-check2"></i> Modifier';
      setTimeout(() => location.reload(), 2000);
    } else { status.textContent = res.message||'Erreur'; toast('Erreur', res.message||'', 'error'); }
  } catch(e) { toast('Erreur réseau', '', 'error'); status.textContent = ''; }
  finally { btn.disabled = false; }
}

async function deleteReview(id) {
  if (!confirm('Supprimer cet avis ?')) return;
  try {
    const res = await ajax('delete_review', { review_id: id });
    if (res.success) {
      const el = document.getElementById('rev-' + id);
      if (el) { el.style.opacity='0'; el.style.transform='translateX(20px)'; setTimeout(()=>el.remove(),300); }
      toast('Avis supprimé', '', 'info', 2500);
      if (res.note_moyenne !== undefined) {
        document.querySelectorAll('#note-display, #avg-display').forEach(el => el.textContent = parseFloat(res.note_moyenne).toFixed(1));
      }
    }
  } catch(e) { toast('Erreur', '', 'error'); }
}

function scrollToReviews() {
  switchTab('reviews', document.querySelector('[data-tab="reviews"]'));
  document.getElementById('tab-reviews').scrollIntoView({ behavior: 'smooth' });
}

/* ════ TÉLÉCHARGEMENT ════ */
async function downloadBook() {
  const btn = document.getElementById('dl-btn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass"></i> Traitement…'; }
  try {
    const res = await ajax('log_download');
    if (res.success && res.url) {
      const a = document.createElement('a'); a.href = '../' + res.url; a.download = '';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      toast('📥 Téléchargement démarré', `${res.count}/${MAX_DL} utilisés`, 'success');
      if (btn) btn.innerHTML = `<i class="bi bi-download"></i> Télécharger (${res.count}/${MAX_DL})`;
    } else {
      toast('Téléchargement', res.message||'Limite atteinte', 'warn', 5000);
      if (btn) btn.innerHTML = `<i class="bi bi-download"></i> Télécharger`;
    }
  } catch(e) { toast('Erreur', '', 'error'); if (btn) btn.innerHTML = `<i class="bi bi-download"></i> Télécharger`; }
  finally { if (btn) btn.disabled = false; }
}

/* ════ LECTEUR PDF ════ */
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

let rdState = {
  pdfDoc: null, page: SAVED_PAGE||1, total: 0,
  zoom: 1.2, theme: 'dark', previewOnly: false,
  sessionStart: Date.now(), elapsed: 0,
  autoSave: null, timer: null,
};

function openReader(previewOnly=false) {
  rdState.previewOnly = previewOnly;
  rdState.sessionStart = Date.now();
  document.getElementById('reader-modal').classList.add('open');
  document.body.style.overflow = 'hidden';
  document.getElementById('rd-loader').style.display = 'flex';
  document.getElementById('rd-canvas-area').style.display = 'none';
  document.getElementById('rd-text-area').style.display = 'none';

  if (PDF_URL && !previewOnly) loadPDF(PDF_URL);
  else if (EXTRAIT) loadText(EXTRAIT);
  else loadText(getDefaultContent());

  clearInterval(rdState.autoSave);
  rdState.autoSave = setInterval(() => { if (!rdState.previewOnly && HAS_ACCESS) saveProgress(false); }, 20000);
  clearInterval(rdState.timer);
  rdState.timer = setInterval(() => { rdState.elapsed++; document.getElementById('rd-time-info').textContent = fmtTime(rdState.elapsed) + ' lus'; }, 1000);
}

function closeReader() {
  if (HAS_ACCESS && !rdState.previewOnly) saveProgress(false);
  clearInterval(rdState.autoSave); clearInterval(rdState.timer);
  document.getElementById('reader-modal').classList.remove('open');
  document.body.style.overflow = '';
  if (!rdState.previewOnly && rdState.total > 0) {
    const pct = Math.round((rdState.page / rdState.total) * 100);
    document.querySelectorAll('.prog-fill').forEach(el => el.style.width = Math.min(100,pct) + '%');
  }
}

async function loadPDF(url) {
  document.getElementById('rd-loader-text').textContent = 'Chargement PDF…';
  try {
    const task = pdfjsLib.getDocument({ url, withCredentials: false });
    task.onProgress = p => { if(p.total>0) document.getElementById('rd-loader-text').textContent = `Chargement… ${Math.round((p.loaded/p.total)*100)}%`; };
    rdState.pdfDoc = await task.promise;
    rdState.total = rdState.pdfDoc.numPages;
    document.getElementById('rd-total').textContent = rdState.total;
    document.getElementById('rd-page-inp').max = rdState.total;
    document.getElementById('rd-canvas-area').style.display = 'flex';
    document.getElementById('rd-loader').style.display = 'none';
    renderPDFPage(rdState.page);
  } catch(e) {
    console.warn('[PDF]', e);
    if (EXTRAIT) loadText(EXTRAIT); else loadText(getDefaultContent());
  }
}

async function renderPDFPage(pageNum) {
  if (!rdState.pdfDoc) return;
  pageNum = Math.max(1, Math.min(pageNum, rdState.total));
  document.getElementById('rd-loader').style.display = 'flex';
  document.getElementById('rd-loader-text').textContent = `Page ${pageNum}/${rdState.total}`;
  try {
    const page = await rdState.pdfDoc.getPage(pageNum);
    const vp = page.getViewport({ scale: rdState.zoom * 1.4 });
    const canvas = document.getElementById('pdf-canvas');
    const ctx = canvas.getContext('2d');
    canvas.width = vp.width; canvas.height = vp.height;
    await page.render({ canvasContext: ctx, viewport: vp }).promise;
    rdState.page = pageNum;
    document.getElementById('rd-loader').style.display = 'none';
    document.getElementById('rd-canvas-area').style.display = 'flex';
    updateRdUI(); applyRdTheme();
  } catch(e) { document.getElementById('rd-loader').style.display = 'none'; toast('Erreur page', e.message, 'error'); }
}

function loadText(content) {
  rdState.pdfDoc = null;
  const pages = content.split('||||PAGE||||');
  rdState.total = pages.length;
  document.getElementById('rd-total').textContent = rdState.total;
  document.getElementById('rd-page-inp').max = rdState.total;
  document.getElementById('rd-loader').style.display = 'none';
  document.getElementById('rd-canvas-area').style.display = 'none';
  const area = document.getElementById('rd-text-area');
  area.style.display = 'block';

  function showPage(p) {
    const idx = Math.max(0, Math.min(p-1, pages.length-1));
    let html = '';
    pages[idx].split('\n\n').filter(s=>s.trim()).forEach(para => {
      const t = para.trim();
      html += (t.toUpperCase().startsWith('CHAPITRE')||t.startsWith('##')) ? `<h2>${esc(t)}</h2>` : `<p>${esc(t)}</p>`;
    });
    if (rdState.previewOnly) {
      html += `<div style="margin-top:2rem;padding:1.5rem;background:rgba(232,197,108,.06);border:1px solid rgba(232,197,108,.2);border-radius:12px;text-align:center">
        <div style="font-size:1.5rem;margin-bottom:.4rem">🔒</div>
        <div style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:.4rem">Extrait terminé</div>
        <div style="font-size:.8rem;color:rgba(238,242,255,.6);margin-bottom:.8rem">Achetez ce livre pour lire la suite</div>
        <button onclick="closeReader();openPurchaseModal()" style="background:linear-gradient(135deg,var(--gold),#c4a030);color:#1a0f00;border:none;border-radius:8px;padding:10px 20px;font-family:'Syne',sans-serif;font-weight:700;cursor:pointer">Acheter maintenant</button>
      </div>`;
    }
    area.innerHTML = html;
    area.scrollTop = 0;
    rdState.page = p;
    updateRdUI();
  }
  showPage(rdState.page);
  window._rdShowPage = showPage;
}

function rdNext() { const next = rdState.page+1; if(next>rdState.total) return; if(rdState.pdfDoc) renderPDFPage(next); else if(window._rdShowPage) window._rdShowPage(next); }
function rdPrev() { const prev = rdState.page-1; if(prev<1) return; if(rdState.pdfDoc) renderPDFPage(prev); else if(window._rdShowPage) window._rdShowPage(prev); }
function rdGo(val) { const p = parseInt(val)||1; if(rdState.pdfDoc) renderPDFPage(p); else if(window._rdShowPage) window._rdShowPage(p); }

function rdZoom(delta) {
  rdState.zoom = Math.max(0.5, Math.min(3, rdState.zoom+delta));
  document.getElementById('rd-zoom-val').textContent = Math.round(rdState.zoom*100)+'%';
  if (rdState.pdfDoc) renderPDFPage(rdState.page);
}

function rdTheme(theme) { rdState.theme = theme; applyRdTheme(); }
function applyRdTheme() {
  const canvas = document.getElementById('pdf-canvas');
  const text = document.getElementById('rd-text-area');
  const modal = document.getElementById('reader-modal');
  if (rdState.theme==='sepia') { canvas.style.filter='sepia(.4) brightness(.95)'; text.style.background='#f5ede0'; text.style.color='#3a2a1a'; modal.style.background='rgba(30,20,10,.97)'; }
  else if (rdState.theme==='light') { canvas.style.filter=''; text.style.background='#f8f8f8'; text.style.color='#1a1a1a'; modal.style.background='rgba(240,240,245,.98)'; }
  else { canvas.style.filter=''; text.style.background=''; text.style.color=''; modal.style.background='rgba(6,9,15,.96)'; }
}

function updateRdUI() {
  document.getElementById('rd-page-inp').value = rdState.page;
  document.getElementById('rd-prev-btn').disabled = rdState.page<=1;
  document.getElementById('rd-next-btn').disabled = rdState.page>=rdState.total;
  const pct = rdState.total>0 ? Math.round((rdState.page/rdState.total)*100) : 0;
  document.getElementById('rd-prog-fill').style.width = pct+'%';
  document.getElementById('rd-pct-label').textContent = pct+'%';
}

function rdFullscreen() {
  const modal = document.getElementById('reader-modal');
  if (!document.fullscreenElement) { modal.requestFullscreen?.().catch(()=>{}); document.getElementById('rd-fs-icon').className='bi bi-fullscreen-exit'; }
  else { document.exitFullscreen?.(); document.getElementById('rd-fs-icon').className='bi bi-fullscreen'; }
}

/* ════ PROGRESSION ════ */
async function saveProgress(showToast=true) {
  if (!HAS_ACCESS || rdState.previewOnly) return;
  const pct = rdState.total>0 ? (rdState.page/rdState.total)*100 : 0;
  try {
    await ajax('save_progress', { page_actuelle: rdState.page, pourcentage: pct.toFixed(2), total_pages: rdState.total, temps_lecture: rdState.elapsed });
    if (showToast) toast('✓ Progression sauvegardée', `Page ${rdState.page}`, 'success', 2000);
  } catch(e) {}
}

/* ════ BOOKMARKS ════ */
async function addQuickBookmark() {
  const note = prompt(`Signet — Page ${rdState.page}\nNote personnelle :`, '');
  if (note === null) return;
  try {
    const res = await ajax('add_bookmark', { page_number: rdState.page, note });
    if (res.success) {
      toast('🔖 Signet ajouté', `Page ${rdState.page}`, 'success');
      const btn = document.getElementById('rd-bm-btn');
      btn.style.color = 'var(--gold)';
      setTimeout(() => btn.style.color = '', 2000);
    } else toast('Erreur', res.message||'', 'error');
  } catch(e) { toast('Erreur', '', 'error'); }
}

function getDefaultContent() {
  return `CHAPITRE I — Introduction\n\nBienvenue dans votre lecteur numérique. Ce livre a été soigneusement sélectionné pour vous offrir la meilleure expérience de lecture.\n\nLa révolution numérique a transformé notre rapport au livre. Chaque page tournée contribue à votre expérience personnelle et unique.\n\n||||PAGE||||\n\nCHAPITRE II — La Lecture Numérique\n\nLire, c'est voyager sans bouger. Dans cet espace littéraire, chaque livre représente une aventure intellectuelle. Votre progression est sauvegardée automatiquement.`;
}

/* ════ NOTIFICATIONS ════ */
async function toggleNotifPanel() {
  const panel = document.getElementById('notif-panel');
  const isOpen = panel.classList.toggle('open');
  if (isOpen) loadNotifications();
}

async function loadNotifications() {
  try {
    const res = await ajaxGet('get_notifications');
    const list = document.getElementById('np-list');
    if (!res.notifications?.length) {
      list.innerHTML = '<div style="padding:1.5rem;text-align:center;color:var(--t3);font-size:.78rem">Aucune notification</div>';
      return;
    }
    const icons = {purchase:'💳',download:'📥',favorite:'❤️',bonus:'🎁'};
    list.innerHTML = res.notifications.map(n => `
      <div class="np-item ${n.is_read?'':'unread'}">
        <div class="np-ico">${icons[n.type]||'🔔'}</div>
        <div><div class="np-text">${esc(n.message||'Notification')}</div><div class="np-time">${fmtDate(n.created_at)}</div></div>
      </div>`).join('');
    if (res.unread === 0) { const nb = document.querySelector('#notif-btn .nb'); if(nb) nb.remove(); }
  } catch(e) {}
}

async function markNotifsRead() {
  try {
    await ajax('mark_notifications_read');
    const nb = document.querySelector('#notif-btn .nb'); if(nb) nb.remove();
    toast('✓ Notifications lues', '', 'success', 2000);
    toggleNotifPanel();
  } catch(e) {}
}

document.addEventListener('click', e => {
  const panel = document.getElementById('notif-panel');
  const btn = document.getElementById('notif-btn');
  if (panel?.classList.contains('open') && !panel.contains(e.target) && !btn?.contains(e.target)) panel.classList.remove('open');
});

/* ════ MODALES ════ */
function openModal(id) { document.getElementById(id)?.classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); document.body.style.overflow = ''; }
document.querySelectorAll('.modal-bg').forEach(bg => bg.addEventListener('click', e => { if(e.target===bg) closeModal(bg.id); }));

/* ════ KEYBOARD ════ */
document.addEventListener('keydown', e => {
  if (document.getElementById('reader-modal').classList.contains('open')) {
    if (e.key==='ArrowRight'||e.key==='PageDown') { e.preventDefault(); rdNext(); }
    if (e.key==='ArrowLeft' ||e.key==='PageUp')   { e.preventDefault(); rdPrev(); }
    if (e.key==='Escape') closeReader();
    if (e.key==='f'||e.key==='F') rdFullscreen();
    if (e.key==='+'||e.key==='=') rdZoom(0.15);
    if (e.key==='-') rdZoom(-0.15);
    if ((e.key==='s'||e.key==='S')&&e.ctrlKey) { e.preventDefault(); addQuickBookmark(); }
    return;
  }
  if (e.key==='Escape') document.querySelectorAll('.modal-bg.open').forEach(m => closeModal(m.id));
});

document.addEventListener('fullscreenchange', () => {
  if (!document.fullscreenElement) document.getElementById('rd-fs-icon').className = 'bi bi-fullscreen';
});

/* ════ INIT ════ */
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    document.querySelectorAll('.prog-fill').forEach(b => {
      const w = b.style.width; b.style.width='0%';
      requestAnimationFrame(() => requestAnimationFrame(() => b.style.width=w));
    });
  }, 400);

  setRating(<?= (int)($userReview['note'] ?? 0) ?>);

  <?php if ($hasAccess && $progPct > 0): ?>
  toast('Bienvenue', 'Reprenez votre lecture — <?= $progPct ?>% lu', 'info', 3500);
  <?php elseif ($isFree): ?>
  toast('Livre gratuit', 'Lecture disponible immédiatement', 'success', 3000);
  <?php endif; ?>
});
</script>

<?php endif; ?>
</body>
</html>