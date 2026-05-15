<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — admin/reviews.php  VERSION 3.0 COMPLETE         ║
 * ║  Avis · Notes · Commentaires · Modération · Temps réel AJAX        ║
 * ║  100% connecté BD · Sécurisé · Professionnel                       ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */

// ══════════════════════════════════════════════════════════════
// SESSION & AUTH
// ══════════════════════════════════════════════════════════════
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId   = (int) $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'lecteur';
$username = htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');

if (!in_array($userRole, ['admin', 'journaliste'], true)) {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

$isAdmin = ($userRole === 'admin');

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ══════════════════════════════════════════════════════════════
// CONNEXION PDO
// ══════════════════════════════════════════════════════════════
$pdo = null;
foreach ([
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config/database.php',
    __DIR__ . '/../includes/config.php',
] as $cp) {
    if (file_exists($cp)) { require_once $cp; break; }
}

if (!isset($pdo) || $pdo === null) {
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=digital_library;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        die('<div style="color:#f43f5e;padding:2rem;font-family:monospace">Erreur BD : ' . htmlspecialchars($e->getMessage()) . '</div>');
    }
}

// ══════════════════════════════════════════════════════════════
// AUTO-CRÉATION DES TABLES NÉCESSAIRES
// ══════════════════════════════════════════════════════════════
try {
    // Table avis principale
    $pdo->exec("CREATE TABLE IF NOT EXISTS avis (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        livre_id INT UNSIGNED NOT NULL,
        note TINYINT UNSIGNED DEFAULT 0,
        commentaire TEXT,
        statut ENUM('publie','en_attente','refuse') DEFAULT 'en_attente',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_livre (livre_id),
        INDEX idx_user (user_id),
        INDEX idx_statut (statut),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Table réponses aux avis
    $pdo->exec("CREATE TABLE IF NOT EXISTS review_replies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        avis_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        contenu TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_avis (avis_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Table likes
    $pdo->exec("CREATE TABLE IF NOT EXISTS review_likes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        avis_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_like (avis_id, user_id),
        INDEX idx_avis (avis_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Table signalements
    $pdo->exec("CREATE TABLE IF NOT EXISTS review_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        avis_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        raison VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_report (avis_id, user_id),
        INDEX idx_avis (avis_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Table logs/audit
    $pdo->exec("CREATE TABLE IF NOT EXISTS review_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        avis_id INT UNSIGNED,
        action VARCHAR(60) NOT NULL,
        detail TEXT,
        ip VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_avis (avis_id),
        INDEX idx_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Colonne note_moyenne sur livres si elle n'existe pas
    $cols = $pdo->query("SHOW COLUMNS FROM livres LIKE 'note_moyenne'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE livres ADD COLUMN note_moyenne DECIMAL(3,2) DEFAULT 0.00");
    }

} catch (PDOException $e) {
    // Tables peut-être déjà ok, on continue
}

// ══════════════════════════════════════════════════════════════
// HELPER : LOG ACTION
// ══════════════════════════════════════════════════════════════
function logReviewAction(PDO $pdo, int $userId, ?int $avisId, string $action, string $detail = ''): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $pdo->prepare("INSERT INTO review_logs (user_id, avis_id, action, detail, ip) VALUES (?,?,?,?,?)")
            ->execute([$userId, $avisId, $action, $detail, $ip]);
    } catch (PDOException $e) { /* silencieux */ }
}

// HELPER : Mettre à jour note_moyenne d'un livre
function updateNoteMoyenne(PDO $pdo, int $livreId): void {
    try {
        $pdo->prepare(
            "UPDATE livres SET note_moyenne = COALESCE(
                (SELECT ROUND(AVG(note),2) FROM avis WHERE livre_id=? AND statut='publie' AND note>0),
             0) WHERE id=?"
        )->execute([$livreId, $livreId]);
    } catch (PDOException $e) { /* silencieux */ }
}

// ══════════════════════════════════════════════════════════════
// TRAITEMENT DES REQUÊTES AJAX
// ══════════════════════════════════════════════════════════════
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? ($_GET['action'] ?? '');
    $csrfIn = $body['csrf'] ?? ($_POST['csrf'] ?? '');

    // Actions qui modifient des données → vérification CSRF
    $writeActions = ['add','approve','reject','delete','update','like','report','reply','reply_delete'];
    if (in_array($action, $writeActions, true)) {
        if (!hash_equals($csrfToken, (string)$csrfIn)) {
            echo json_encode(['success' => false, 'error' => 'Token CSRF invalide. Rechargez la page.']);
            exit;
        }
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : LIST — Liste des avis avec filtres et pagination
    // ─────────────────────────────────────────────────────────
    if ($action === 'list') {
        $statut  = $_GET['statut']   ?? 'all';
        $livreId = (int)($_GET['livre_id'] ?? 0);
        $noteMin = (int)($_GET['note_min'] ?? 0);
        $search  = trim($_GET['search'] ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset  = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        // Journaliste : seulement ses livres
        if (!$isAdmin) {
            $where[]  = "l.ajoute_par = ?";
            $params[] = $userId;
        }
        if ($statut !== 'all' && in_array($statut, ['en_attente','publie','refuse'], true)) {
            $where[]  = "a.statut = ?";
            $params[] = $statut;
        }
        if ($livreId > 0) {
            $where[]  = "a.livre_id = ?";
            $params[] = $livreId;
        }
        if ($noteMin > 0 && $noteMin <= 5) {
            $where[]  = "a.note >= ?";
            $params[] = $noteMin;
        }
        if ($search !== '') {
            $where[]  = "(l.titre LIKE ? OR CONCAT(u.prenom,' ',u.nom) LIKE ? OR a.commentaire LIKE ?)";
            $s = '%' . $search . '%';
            array_push($params, $s, $s, $s);
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        try {
            // Total
            $countSt = $pdo->prepare(
                "SELECT COUNT(*) FROM avis a
                 JOIN livres l ON l.id = a.livre_id
                 JOIN users u ON u.id = a.user_id
                 $whereSQL"
            );
            $countSt->execute($params);
            $total = (int)$countSt->fetchColumn();

            // Liste avis
            $listSt = $pdo->prepare(
                "SELECT
                    a.id, a.note, a.commentaire, a.statut,
                    a.created_at, a.updated_at,
                    l.id AS livre_id, l.titre AS livre_titre,
                    u.id AS user_id_col,
                    CONCAT(u.prenom,' ',u.nom) AS user_nom,
                    u.email AS user_email, u.role AS user_role,
                    (SELECT COUNT(*) FROM review_likes rl WHERE rl.avis_id = a.id) AS nb_likes,
                    (SELECT COUNT(*) FROM review_replies rr WHERE rr.avis_id = a.id) AS nb_replies,
                    (SELECT COUNT(*) FROM review_reports rpt WHERE rpt.avis_id = a.id) AS nb_reports,
                    (SELECT COUNT(*) FROM review_likes rl2 WHERE rl2.avis_id = a.id AND rl2.user_id = ?) AS user_liked
                 FROM avis a
                 JOIN livres l ON l.id = a.livre_id
                 JOIN users u ON u.id = a.user_id
                 $whereSQL
                 ORDER BY a.created_at DESC
                 LIMIT $perPage OFFSET $offset"
            );
            // Préparer les params avec userId en premier (pour user_liked)
            $listSt->execute(array_merge([$userId], $params));
            $avisList = $listSt->fetchAll();

            // Stats globales
            $statsParams = $isAdmin ? [] : [$userId];
            $statsWhere  = $isAdmin ? '' : 'JOIN livres l ON l.id=a.livre_id WHERE l.ajoute_par=?';
            $statsSt = $pdo->prepare(
                "SELECT a.statut, COUNT(*) AS nb FROM avis a $statsWhere GROUP BY a.statut"
            );
            $statsSt->execute($statsParams);
            $statsRaw = $statsSt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Note moyenne globale
            $noteMoyParams = $isAdmin ? [] : [$userId];
            $noteMoyWhere  = $isAdmin ? "WHERE a.statut='publie' AND a.note>0"
                                      : "JOIN livres l ON l.id=a.livre_id WHERE l.ajoute_par=? AND a.statut='publie' AND a.note>0";
            $noteMoySt = $pdo->prepare("SELECT ROUND(AVG(a.note),2) FROM avis a $noteMoyWhere");
            $noteMoySt->execute($noteMoyParams);
            $noteMoyGlobal = (float)($noteMoySt->fetchColumn() ?: 0);

            echo json_encode([
                'success'    => true,
                'avis'       => $avisList,
                'total'      => $total,
                'totalPages' => max(1, (int)ceil($total / $perPage)),
                'page'       => $page,
                'stats'      => [
                    'en_attente'  => (int)($statsRaw['en_attente'] ?? 0),
                    'publie'      => (int)($statsRaw['publie'] ?? 0),
                    'refuse'      => (int)($statsRaw['refuse'] ?? 0),
                    'total'       => array_sum(array_map('intval', $statsRaw)),
                    'note_moyenne'=> $noteMoyGlobal,
                ],
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Erreur BD : ' . $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : NOTES MOYENNES par livre
    // ─────────────────────────────────────────────────────────
    if ($action === 'notes_moyennes') {
        try {
            $params = [];
            $where  = "WHERE a.statut='publie' AND a.note>0";
            if (!$isAdmin) {
                $where  .= " AND l.ajoute_par=?";
                $params[] = $userId;
            }
            $st = $pdo->prepare(
                "SELECT l.id, l.titre,
                        ROUND(AVG(a.note),1) AS moy,
                        COUNT(a.id) AS nb,
                        SUM(a.note=5) AS nb5,
                        SUM(a.note=4) AS nb4,
                        SUM(a.note=3) AS nb3,
                        SUM(a.note=2) AS nb2,
                        SUM(a.note=1) AS nb1
                 FROM avis a
                 JOIN livres l ON l.id=a.livre_id
                 $where
                 GROUP BY l.id, l.titre
                 ORDER BY moy DESC, nb DESC
                 LIMIT 12"
            );
            $st->execute($params);
            $notes = $st->fetchAll();
            echo json_encode(['success' => true, 'notes' => $notes]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : STATS DYNAMIQUES complètes
    // ─────────────────────────────────────────────────────────
    if ($action === 'stats_full') {
        try {
            $result = [];

            // Top 5 livres commentés
            $topParams = $isAdmin ? [] : [$userId];
            $topWhere  = $isAdmin ? '' : 'AND l.ajoute_par=?';
            $st = $pdo->prepare(
                "SELECT l.titre, COUNT(a.id) AS nb_avis, ROUND(AVG(a.note),1) AS moy
                 FROM avis a JOIN livres l ON l.id=a.livre_id
                 WHERE a.statut='publie' $topWhere
                 GROUP BY l.id ORDER BY nb_avis DESC LIMIT 5"
            );
            $st->execute($topParams);
            $result['top_livres'] = $st->fetchAll();

            // Activité 7 derniers jours
            $actParams = $isAdmin ? [] : [$userId];
            $actWhere  = $isAdmin ? '' : 'AND l.ajoute_par=?';
            $st2 = $pdo->prepare(
                "SELECT DATE(a.created_at) AS jour, COUNT(*) AS nb
                 FROM avis a JOIN livres l ON l.id=a.livre_id
                 WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) $actWhere
                 GROUP BY jour ORDER BY jour"
            );
            $st2->execute($actParams);
            $rawAct = $st2->fetchAll(PDO::FETCH_KEY_PAIR);
            $actData = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $l = date('D', strtotime($d));
                $fr = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Jeu','Fri'=>'Ven','Sat'=>'Sam','Sun'=>'Dim'];
                $actData[] = ['label' => $fr[$l] ?? $l, 'nb' => (int)($rawAct[$d] ?? 0), 'date' => $d];
            }
            $result['activite_semaine'] = $actData;

            // Répartition des notes (1 à 5)
            $reptParams = $isAdmin ? [] : [$userId];
            $reptWhere  = $isAdmin ? "WHERE a.statut='publie' AND a.note>0"
                                   : "JOIN livres l ON l.id=a.livre_id WHERE l.ajoute_par=? AND a.statut='publie' AND a.note>0";
            $st3 = $pdo->prepare(
                "SELECT a.note, COUNT(*) AS nb FROM avis a $reptWhere GROUP BY a.note ORDER BY a.note DESC"
            );
            $st3->execute($reptParams);
            $rept = $st3->fetchAll(PDO::FETCH_KEY_PAIR);
            $total_notes = max(1, array_sum(array_map('intval', $rept)));
            $result['repartition_notes'] = [];
            for ($n = 5; $n >= 1; $n--) {
                $nb = (int)($rept[$n] ?? 0);
                $result['repartition_notes'][] = [
                    'note' => $n,
                    'nb'   => $nb,
                    'pct'  => round(($nb / $total_notes) * 100, 1),
                ];
            }

            // Utilisateurs les plus actifs
            $activeParams = $isAdmin ? [] : [$userId];
            $activeWhere  = $isAdmin ? '' : 'AND l.ajoute_par=?';
            $st4 = $pdo->prepare(
                "SELECT CONCAT(u.prenom,' ',u.nom) AS nom, u.role, COUNT(a.id) AS nb_avis,
                        ROUND(AVG(a.note),1) AS note_moy
                 FROM avis a
                 JOIN users u ON u.id=a.user_id
                 JOIN livres l ON l.id=a.livre_id
                 WHERE a.statut='publie' $activeWhere
                 GROUP BY u.id ORDER BY nb_avis DESC LIMIT 5"
            );
            $st4->execute($activeParams);
            $result['top_users'] = $st4->fetchAll();

            // Avis en attente urgents (> 24h)
            $urgentParams = $isAdmin ? [] : [$userId];
            $urgentWhere  = $isAdmin ? "WHERE a.statut='en_attente'"
                                     : "JOIN livres l ON l.id=a.livre_id WHERE a.statut='en_attente' AND l.ajoute_par=?";
            $st5 = $pdo->prepare("SELECT COUNT(*) FROM avis a $urgentWhere AND a.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $st5->execute($urgentParams);
            $result['urgents'] = (int)$st5->fetchColumn();

            echo json_encode(['success' => true, 'data' => $result]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : ADD — Ajouter un avis
    // ─────────────────────────────────────────────────────────
    if ($action === 'add') {
        $livreId     = (int)($body['livre_id'] ?? 0);
        $note        = min(5, max(0, (int)($body['note'] ?? 0)));
        $commentaire = trim($body['commentaire'] ?? '');

        if ($livreId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Veuillez choisir un livre.']);
            exit;
        }
        if (empty($commentaire)) {
            echo json_encode(['success' => false, 'error' => 'Le commentaire est obligatoire.']);
            exit;
        }
        if (mb_strlen($commentaire) > 2000) {
            echo json_encode(['success' => false, 'error' => 'Commentaire trop long (max 2000 caractères).']);
            exit;
        }

        // Vérifier que le livre existe et est accessible
        try {
            $stL = $isAdmin
                ? $pdo->prepare("SELECT id FROM livres WHERE id=?")
                : $pdo->prepare("SELECT id FROM livres WHERE id=? AND (ajoute_par=? OR statut='disponible')");
            $stL->execute($isAdmin ? [$livreId] : [$livreId, $userId]);
            if (!$stL->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Livre introuvable ou inaccessible.']);
                exit;
            }

            // Statut : admin publie directement, journaliste en attente
            $statut = $isAdmin ? 'publie' : 'en_attente';

            $st = $pdo->prepare(
                "INSERT INTO avis (user_id, livre_id, note, commentaire, statut) VALUES (?,?,?,?,?)"
            );
            $st->execute([$userId, $livreId, $note, $commentaire, $statut]);
            $newId = (int)$pdo->lastInsertId();

            // Récupérer l'avis complet
            $stNew = $pdo->prepare(
                "SELECT a.id, a.note, a.commentaire, a.statut, a.created_at,
                        l.titre AS livre_titre, l.id AS livre_id,
                        CONCAT(u.prenom,' ',u.nom) AS user_nom, u.email AS user_email, u.role AS user_role
                 FROM avis a
                 JOIN livres l ON l.id=a.livre_id
                 JOIN users u ON u.id=a.user_id
                 WHERE a.id=?"
            );
            $stNew->execute([$newId]);
            $newAvis = $stNew->fetch();

            if ($statut === 'publie') {
                updateNoteMoyenne($pdo, $livreId);
            }

            logReviewAction($pdo, $userId, $newId, 'add',
                "Avis ajouté sur livre_id=$livreId, note=$note");

            echo json_encode([
                'success' => true,
                'avis'    => $newAvis,
                'message' => $statut === 'publie'
                    ? 'Avis publié immédiatement.'
                    : 'Avis soumis. En attente de modération.',
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Erreur BD : ' . $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : UPDATE — Modifier un avis
    // ─────────────────────────────────────────────────────────
    if ($action === 'update') {
        $id          = (int)($body['id'] ?? 0);
        $note        = min(5, max(0, (int)($body['note'] ?? 0)));
        $commentaire = trim($body['commentaire'] ?? '');

        if ($id <= 0 || empty($commentaire)) {
            echo json_encode(['success' => false, 'error' => 'Données manquantes.']);
            exit;
        }
        if (mb_strlen($commentaire) > 2000) {
            echo json_encode(['success' => false, 'error' => 'Commentaire trop long.']);
            exit;
        }

        try {
            // Admin peut modifier tout, journaliste seulement ses avis
            $where  = $isAdmin ? "WHERE id=?" : "WHERE id=? AND user_id=?";
            $params = $isAdmin ? [$note, $commentaire, $id] : [$note, $commentaire, $id, $userId];
            $st = $pdo->prepare("UPDATE avis SET note=?, commentaire=?, statut='en_attente', updated_at=NOW() $where");
            $st->execute($params);

            if ($st->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'Avis introuvable ou non autorisé.']);
                exit;
            }

            $livreRow = $pdo->prepare("SELECT livre_id FROM avis WHERE id=?");
            $livreRow->execute([$id]);
            $lid = (int)($livreRow->fetchColumn() ?: 0);
            if ($lid) updateNoteMoyenne($pdo, $lid);

            logReviewAction($pdo, $userId, $id, 'update', "note=$note");
            echo json_encode(['success' => true, 'message' => 'Avis modifié.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : APPROVE — Approuver un avis
    // ─────────────────────────────────────────────────────────
    if ($action === 'approve') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'ID manquant.']); exit; }

        try {
            $where  = $isAdmin
                ? "WHERE id=?"
                : "WHERE id=? AND livre_id IN (SELECT id FROM livres WHERE ajoute_par=?)";
            $params = $isAdmin ? [$id] : [$id, $userId];
            $st = $pdo->prepare("UPDATE avis SET statut='publie', updated_at=NOW() $where");
            $st->execute($params);

            if ($st->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'Non autorisé ou introuvable.']);
                exit;
            }

            $livreRow = $pdo->prepare("SELECT livre_id FROM avis WHERE id=?");
            $livreRow->execute([$id]);
            $lid = (int)($livreRow->fetchColumn() ?: 0);
            if ($lid) updateNoteMoyenne($pdo, $lid);

            logReviewAction($pdo, $userId, $id, 'approve');
            echo json_encode(['success' => true, 'message' => 'Avis approuvé et publié.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : REJECT — Refuser un avis
    // ─────────────────────────────────────────────────────────
    if ($action === 'reject') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'ID manquant.']); exit; }

        try {
            $where  = $isAdmin
                ? "WHERE id=?"
                : "WHERE id=? AND livre_id IN (SELECT id FROM livres WHERE ajoute_par=?)";
            $params = $isAdmin ? [$id] : [$id, $userId];
            $st = $pdo->prepare("UPDATE avis SET statut='refuse', updated_at=NOW() $where");
            $st->execute($params);

            if ($st->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'Non autorisé ou introuvable.']);
                exit;
            }

            $livreRow = $pdo->prepare("SELECT livre_id FROM avis WHERE id=?");
            $livreRow->execute([$id]);
            $lid = (int)($livreRow->fetchColumn() ?: 0);
            if ($lid) updateNoteMoyenne($pdo, $lid);

            logReviewAction($pdo, $userId, $id, 'reject');
            echo json_encode(['success' => true, 'message' => 'Avis refusé.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : DELETE — Supprimer un avis (admin seulement)
    // ─────────────────────────────────────────────────────────
    if ($action === 'delete') {
        if (!$isAdmin) {
            echo json_encode(['success' => false, 'error' => 'Droits insuffisants. Réservé aux administrateurs.']);
            exit;
        }
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'ID manquant.']); exit; }

        try {
            $livreRow = $pdo->prepare("SELECT livre_id FROM avis WHERE id=?");
            $livreRow->execute([$id]);
            $lid = (int)($livreRow->fetchColumn() ?: 0);

            // Supprimer dépendances
            $pdo->prepare("DELETE FROM review_likes   WHERE avis_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM review_replies WHERE avis_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM review_reports WHERE avis_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM avis           WHERE id=?")->execute([$id]);

            if ($lid) updateNoteMoyenne($pdo, $lid);

            logReviewAction($pdo, $userId, $id, 'delete', "livre_id=$lid");
            echo json_encode(['success' => true, 'message' => 'Avis supprimé définitivement.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : LIKE — Aimer/ne plus aimer un avis
    // ─────────────────────────────────────────────────────────
    if ($action === 'like') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'ID manquant.']); exit; }

        try {
            // Vérifier si déjà liké
            $check = $pdo->prepare("SELECT id FROM review_likes WHERE avis_id=? AND user_id=?");
            $check->execute([$id, $userId]);
            $existing = $check->fetch();

            if ($existing) {
                $pdo->prepare("DELETE FROM review_likes WHERE avis_id=? AND user_id=?")->execute([$id, $userId]);
                $liked = false;
            } else {
                $pdo->prepare("INSERT INTO review_likes (avis_id, user_id) VALUES (?,?)")->execute([$id, $userId]);
                $liked = true;
            }

            $stCount = $pdo->prepare("SELECT COUNT(*) FROM review_likes WHERE avis_id=?");
            $stCount->execute([$id]);
            $nbLikes = (int)$stCount->fetchColumn();

            echo json_encode(['success' => true, 'liked' => $liked, 'nb_likes' => $nbLikes]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : REPORT — Signaler un avis
    // ─────────────────────────────────────────────────────────
    if ($action === 'report') {
        $id     = (int)($body['id'] ?? 0);
        $raison = trim($body['raison'] ?? 'Contenu inapproprié');
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'ID manquant.']); exit; }

        try {
            $check = $pdo->prepare("SELECT id FROM review_reports WHERE avis_id=? AND user_id=?");
            $check->execute([$id, $userId]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Vous avez déjà signalé cet avis.']);
                exit;
            }
            $pdo->prepare("INSERT INTO review_reports (avis_id, user_id, raison) VALUES (?,?,?)")
                ->execute([$id, $userId, mb_substr($raison, 0, 255)]);

            $stCount = $pdo->prepare("SELECT COUNT(*) FROM review_reports WHERE avis_id=?");
            $stCount->execute([$id]);
            $nbReports = (int)$stCount->fetchColumn();

            // Auto-mise en attente si 3+ signalements
            if ($nbReports >= 3) {
                $pdo->prepare("UPDATE avis SET statut='en_attente' WHERE id=? AND statut='publie'")->execute([$id]);
            }

            logReviewAction($pdo, $userId, $id, 'report', "raison=$raison");
            echo json_encode(['success' => true, 'message' => 'Avis signalé. Notre équipe va examiner.', 'nb_reports' => $nbReports]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : REPLY — Répondre à un avis
    // ─────────────────────────────────────────────────────────
    if ($action === 'reply') {
        $id      = (int)($body['id'] ?? 0);
        $contenu = trim($body['contenu'] ?? '');
        if ($id <= 0 || empty($contenu)) {
            echo json_encode(['success' => false, 'error' => 'Réponse vide ou ID manquant.']);
            exit;
        }
        if (mb_strlen($contenu) > 1000) {
            echo json_encode(['success' => false, 'error' => 'Réponse trop longue (max 1000 caractères).']);
            exit;
        }

        try {
            // Vérifier que l'avis existe et qu'on a le droit d'y répondre
            $stCheck = $isAdmin
                ? $pdo->prepare("SELECT id FROM avis WHERE id=?")
                : $pdo->prepare("SELECT a.id FROM avis a JOIN livres l ON l.id=a.livre_id WHERE a.id=? AND (l.ajoute_par=? OR a.user_id=?)");
            $stCheck->execute($isAdmin ? [$id] : [$id, $userId, $userId]);
            if (!$stCheck->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Non autorisé.']);
                exit;
            }

            $pdo->prepare("INSERT INTO review_replies (avis_id, user_id, contenu) VALUES (?,?,?)")
                ->execute([$id, $userId, $contenu]);
            $replyId = (int)$pdo->lastInsertId();

            $stReply = $pdo->prepare(
                "SELECT rr.id, rr.contenu, rr.created_at,
                        CONCAT(u.prenom,' ',u.nom) AS user_nom, u.role AS user_role
                 FROM review_replies rr JOIN users u ON u.id=rr.user_id
                 WHERE rr.id=?"
            );
            $stReply->execute([$replyId]);
            $reply = $stReply->fetch();

            logReviewAction($pdo, $userId, $id, 'reply');
            echo json_encode(['success' => true, 'reply' => $reply, 'message' => 'Réponse publiée.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : REPLIES — Charger les réponses d'un avis
    // ─────────────────────────────────────────────────────────
    if ($action === 'replies') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'ID manquant.']); exit; }

        try {
            $st = $pdo->prepare(
                "SELECT rr.id, rr.contenu, rr.created_at,
                        CONCAT(u.prenom,' ',u.nom) AS user_nom, u.role AS user_role
                 FROM review_replies rr
                 JOIN users u ON u.id=rr.user_id
                 WHERE rr.avis_id=?
                 ORDER BY rr.created_at ASC"
            );
            $st->execute([$id]);
            $replies = $st->fetchAll();
            echo json_encode(['success' => true, 'replies' => $replies]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACTION : REPLY_DELETE — Supprimer une réponse
    // ─────────────────────────────────────────────────────────
    if ($action === 'reply_delete') {
        if (!$isAdmin) {
            echo json_encode(['success' => false, 'error' => 'Droits insuffisants.']);
            exit;
        }
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'ID manquant.']); exit; }
        try {
            $pdo->prepare("DELETE FROM review_replies WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Réponse supprimée.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => "Action '$action' inconnue."]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// DONNÉES INITIALES (chargement page)
// ══════════════════════════════════════════════════════════════
$statsRaw = [];
try {
    $statsSt = $isAdmin
        ? $pdo->query("SELECT statut, COUNT(*) AS nb FROM avis GROUP BY statut")
        : $pdo->prepare("SELECT a.statut, COUNT(*) AS nb FROM avis a JOIN livres l ON l.id=a.livre_id WHERE l.ajoute_par=? GROUP BY a.statut");
    if (!$isAdmin) $statsSt->execute([$userId]);
    $statsRaw = $statsSt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}

$nbEn  = (int)($statsRaw['en_attente'] ?? 0);
$nbPub = (int)($statsRaw['publie']     ?? 0);
$nbRef = (int)($statsRaw['refuse']     ?? 0);
$nbTot = $nbEn + $nbPub + $nbRef;

// Note moyenne globale
$noteMoyGlobal = 0;
try {
    $nmSt = $isAdmin
        ? $pdo->query("SELECT ROUND(AVG(note),2) FROM avis WHERE statut='publie' AND note>0")
        : $pdo->prepare("SELECT ROUND(AVG(a.note),2) FROM avis a JOIN livres l ON l.id=a.livre_id WHERE l.ajoute_par=? AND a.statut='publie' AND a.note>0");
    if (!$isAdmin) { $nmSt->execute([$userId]); }
    $noteMoyGlobal = (float)($nmSt->fetchColumn() ?: 0);
} catch (PDOException $e) {}

// Livres pour les filtres
$mesLivres = [];
try {
    if ($isAdmin) {
        $mesLivres = $pdo->query("SELECT id, titre FROM livres WHERE statut='disponible' ORDER BY titre LIMIT 200")->fetchAll();
    } else {
        $stL = $pdo->prepare("SELECT id, titre FROM livres WHERE ajoute_par=? ORDER BY titre LIMIT 100");
        $stL->execute([$userId]);
        $mesLivres = $stL->fetchAll();
    }
} catch (PDOException $e) {}

// Livres pour le formulaire
$livresPourForm = [];
try {
    if ($isAdmin) {
        $livresPourForm = $pdo->query("SELECT id, titre, auteur FROM livres WHERE statut='disponible' ORDER BY titre LIMIT 200")->fetchAll();
    } else {
        $stLF = $pdo->prepare("SELECT id, titre, auteur FROM livres WHERE (ajoute_par=? OR statut='disponible') ORDER BY titre LIMIT 100");
        $stLF->execute([$userId]);
        $livresPourForm = $stLF->fetchAll();
    }
} catch (PDOException $e) {}

// Notifications
$notifCount = 0;
try {
    $stN = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE lu=0 AND (user_id=? OR user_id IS NULL)");
    $stN->execute([$userId]);
    $notifCount = (int)$stN->fetchColumn();
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Avis &amp; Commentaires — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ═══════════════ RESET & VARIABLES ═══════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#07090f;--surface:#0d1120;
  --card:rgba(255,255,255,.033);--card-hov:rgba(255,255,255,.065);
  --border:rgba(255,255,255,.07);--border-act:rgba(0,212,255,.38);
  --cyan:#00d4ff;--violet:#7c3aed;--neon:#00ffaa;
  --amber:#f59e0b;--rose:#f43f5e;--orange:#f97316;
  --txt:#eef2ff;--txt2:rgba(238,242,255,.58);--txt3:rgba(238,242,255,.28);
  --r:10px;--rl:16px;--rx:22px;
  --ease:cubic-bezier(.34,1.56,.64,1);
  --shadow:0 8px 32px rgba(0,0,0,.38);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--txt);min-height:100vh;overflow-x:hidden}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:rgba(0,212,255,.25);border-radius:3px}

/* ORBS décoratifs */
.orb{position:fixed;border-radius:50%;filter:blur(120px);pointer-events:none;z-index:0;animation:drift 26s ease-in-out infinite}
.orb-a{width:600px;height:600px;background:rgba(245,158,11,.04);top:-200px;left:-100px}
.orb-b{width:450px;height:450px;background:rgba(124,58,237,.05);bottom:-100px;right:-100px;animation-delay:-13s}
.orb-c{width:300px;height:300px;background:rgba(0,212,255,.03);top:40%;left:40%;animation-delay:-7s}
@keyframes drift{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(30px,-25px) scale(1.04)}}

/* LAYOUT */
.wrapper{position:relative;z-index:1;max-width:1320px;margin:0 auto;padding:1.6rem 1.8rem}

/* ═══════════════ TOPBAR ═══════════════ */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.8rem;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--txt);font-family:'Syne',sans-serif;font-weight:800;font-size:.95rem}
.brand-ico{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--amber),var(--rose));display:flex;align-items:center;justify-content:center;font-size:.9rem}
.topbar-nav{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap}
.nav-link{display:inline-flex;align-items:center;gap:5px;font-size:.73rem;color:var(--txt2);text-decoration:none;padding:5px 12px;border-radius:var(--r);border:1px solid var(--border);background:var(--card);transition:all .18s}
.nav-link:hover,.nav-link.active{border-color:rgba(245,158,11,.35);color:var(--amber);background:rgba(245,158,11,.06)}
.topbar-right{display:flex;align-items:center;gap:.4rem}
.tb-btn{width:32px;height:32px;border-radius:var(--r);background:var(--card);border:1px solid var(--border);color:var(--txt2);display:flex;align-items:center;justify-content:center;font-size:.88rem;transition:all .18s;text-decoration:none;position:relative;cursor:pointer}
.tb-btn:hover{color:var(--txt);background:var(--card-hov)}
.nb-badge{position:absolute;top:-3px;right:-3px;min-width:13px;height:13px;background:var(--rose);border-radius:50%;font-size:.48rem;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);color:#fff;font-weight:700}
.user-pill{display:flex;align-items:center;gap:6px;padding:4px 10px 4px 5px;background:var(--card);border:1px solid var(--border);border-radius:100px;font-size:.73rem;font-weight:600}
.user-av{width:23px;height:23px;border-radius:50%;background:linear-gradient(135deg,var(--amber),var(--violet));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.65rem;color:#fff}

/* ═══════════════ PAGE HEADER ═══════════════ */
.page-hdr{margin-bottom:1.6rem}
.page-title{font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800;letter-spacing:-.5px;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.page-sub{font-size:.8rem;color:var(--txt2);margin-top:.3rem}
.live-pill{display:inline-flex;align-items:center;gap:5px;font-family:'JetBrains Mono',monospace;font-size:.58rem;color:var(--neon);background:rgba(0,255,170,.07);border:1px solid rgba(0,255,170,.2);padding:3px 9px;border-radius:100px}
.live-dot{width:5px;height:5px;background:var(--neon);border-radius:50%;animation:livepulse 1.6s ease-in-out infinite}
@keyframes livepulse{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(0,255,170,.5)}50%{opacity:.6;box-shadow:0 0 0 5px rgba(0,255,170,0)}}

/* ═══════════════ STATS BAND ═══════════════ */
.stats-band{display:grid;grid-template-columns:repeat(5,1fr);gap:.85rem;margin-bottom:1.6rem}
@media(max-width:1024px){.stats-band{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.stats-band{grid-template-columns:1fr 1fr}}
.stat-c{background:var(--card);border:1px solid var(--border);border-radius:var(--rl);padding:1rem 1.2rem;position:relative;overflow:hidden;transition:transform .2s,border-color .2s,box-shadow .2s;cursor:default}
.stat-c:hover{transform:translateY(-3px);border-color:rgba(255,255,255,.11);box-shadow:var(--shadow)}
.stat-c::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--ac1,#fff),var(--ac2,#888))}
.stat-c:nth-child(1){--ac1:var(--amber);--ac2:var(--orange)}
.stat-c:nth-child(2){--ac1:var(--neon);--ac2:var(--cyan)}
.stat-c:nth-child(3){--ac1:var(--rose);--ac2:var(--violet)}
.stat-c:nth-child(4){--ac1:var(--cyan);--ac2:var(--violet)}
.stat-c:nth-child(5){--ac1:var(--violet);--ac2:var(--rose)}
.stat-ico{font-size:1.4rem;margin-bottom:.5rem;display:block}
.stat-val{font-family:'Syne',sans-serif;font-size:1.7rem;font-weight:800;letter-spacing:-.5px;line-height:1}
.stat-lbl{font-size:.67rem;color:var(--txt2);margin-top:3px;font-weight:500}

/* ═══════════════ MAIN GRID ═══════════════ */
.main-grid{display:grid;grid-template-columns:1fr 370px;gap:1.3rem;align-items:start}
@media(max-width:1050px){.main-grid{grid-template-columns:1fr}}

/* ═══════════════ CARD ═══════════════ */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--rl);overflow:hidden}
.card-hdr{padding:1rem 1.3rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.7rem;flex-wrap:wrap}
.card-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:7px}
.ct-ic{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.85rem}
.card-body{padding:1rem 1.3rem}
.card-footer{padding:.9rem 1.3rem;border-top:1px solid var(--border)}

/* ═══════════════ TABS ═══════════════ */
.tabs{display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:1rem}
.tab{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;padding:5px 13px;border-radius:100px;border:1px solid var(--border);background:var(--card);color:var(--txt2);cursor:pointer;transition:all .18s;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.tab:hover{border-color:rgba(245,158,11,.35);color:var(--amber)}
.tab.active{border-color:var(--amber);color:var(--amber);background:rgba(245,158,11,.07)}
.tab-cnt{opacity:.65;font-size:.7em}

/* ═══════════════ FILTRES & RECHERCHE ═══════════════ */
.filters-row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.9rem}
.search-wrap{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:7px 12px;flex:1;min-width:180px;transition:border-color .2s;max-width:320px}
.search-wrap:focus-within{border-color:var(--border-act)}
.search-wrap i{color:var(--txt3);font-size:.82rem;flex-shrink:0}
.search-wrap input{background:none;border:none;outline:none;color:var(--txt);font-size:.8rem;font-family:'DM Sans',sans-serif;width:100%}
.search-wrap input::placeholder{color:var(--txt3)}
select.flt-sel{font-size:.7rem;padding:6px 11px;border-radius:100px;border:1px solid var(--border);background:var(--surface);color:var(--txt2);outline:none;cursor:pointer;font-family:'JetBrains Mono',monospace;letter-spacing:.04em;transition:border-color .2s}
select.flt-sel:focus{border-color:var(--amber)}
.star-filter{display:flex;align-items:center;gap:3px;background:var(--card);border:1px solid var(--border);border-radius:100px;padding:4px 10px}
.star-filter span{font-size:.67rem;color:var(--txt3);white-space:nowrap;margin-right:4px}
.sf-btn{background:none;border:none;font-size:1.05rem;cursor:pointer;color:var(--txt3);transition:color .15s;padding:1px 2px}
.sf-btn:hover,.sf-btn.on{color:var(--amber)}
.sf-reset{font-size:.75rem;cursor:pointer;color:var(--txt3);background:none;border:none;padding:1px 3px;transition:color .15s}
.sf-reset:hover{color:var(--rose)}

/* ═══════════════ LISTE AVIS ═══════════════ */
#avis-list{display:flex;flex-direction:column;gap:10px;min-height:200px}
.avis-card{background:rgba(255,255,255,.022);border:1px solid rgba(255,255,255,.05);border-radius:var(--rl);padding:1rem 1.2rem;transition:border-color .2s,background .18s,box-shadow .2s;animation:fadeUp .3s ease both}
.avis-card:hover{background:var(--card-hov);border-color:rgba(255,255,255,.1)}
.avis-card.reported{border-color:rgba(244,63,94,.25);background:rgba(244,63,94,.03)}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.avis-top{display:flex;align-items:flex-start;justify-content:space-between;gap:.7rem;flex-wrap:wrap;margin-bottom:.6rem}
.avis-user{display:flex;align-items:center;gap:9px}
.avis-av{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--violet),var(--cyan));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.72rem;color:#fff;flex-shrink:0}
.avis-av.admin-av{background:linear-gradient(135deg,var(--rose),var(--orange))}
.avis-av.journaliste-av{background:linear-gradient(135deg,var(--amber),var(--orange))}
.avis-user-name{font-weight:600;font-size:.83rem}
.avis-user-email{font-size:.63rem;color:var(--txt3);font-family:'JetBrains Mono',monospace}
.avis-meta{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap}
.stars-display{color:var(--amber);font-size:.82rem;letter-spacing:-1px}
.stars-empty{color:rgba(255,255,255,.15)}
.note-num{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt3)}
.chip{display:inline-flex;align-items:center;gap:3px;font-size:.58rem;font-family:'JetBrains Mono',monospace;padding:2px 7px;border-radius:100px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.chip-att{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.chip-pub{background:rgba(0,255,170,.1);color:var(--neon);border:1px solid rgba(0,255,170,.2)}
.chip-ref{background:rgba(244,63,94,.1);color:var(--rose);border:1px solid rgba(244,63,94,.2)}
.chip-warn{background:rgba(245,158,11,.1);color:var(--amber);border:1px solid rgba(245,158,11,.2)}
.avis-date{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt3)}
.avis-book{font-size:.73rem;color:var(--cyan);margin-bottom:.55rem;display:flex;align-items:center;gap:5px}
.avis-text{font-size:.82rem;color:var(--txt2);line-height:1.65;margin-bottom:.75rem}
.avis-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.avis-actions{display:flex;gap:.35rem;flex-wrap:wrap;align-items:center}
.avis-social{display:flex;gap:.35rem;align-items:center}
.social-btn{display:inline-flex;align-items:center;gap:3px;padding:4px 9px;border-radius:var(--r);font-size:.68rem;cursor:pointer;border:1px solid var(--border);background:var(--card);color:var(--txt2);transition:all .18s}
.social-btn:hover{background:var(--card-hov);color:var(--txt)}
.social-btn.liked{color:var(--rose);border-color:rgba(244,63,94,.3)}
.social-btn.replies-open{color:var(--cyan);border-color:rgba(0,212,255,.3)}

/* Boutons d'action */
.btn{display:inline-flex;align-items:center;gap:4px;padding:5px 11px;border-radius:var(--r);font-size:.7rem;font-weight:600;cursor:pointer;border:none;transition:all .18s;font-family:'DM Sans',sans-serif;white-space:nowrap}
.btn-approve{background:rgba(0,255,170,.1);border:1px solid rgba(0,255,170,.25);color:var(--neon)}
.btn-approve:hover{background:rgba(0,255,170,.18)}
.btn-reject{background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);color:var(--rose)}
.btn-reject:hover{background:rgba(244,63,94,.16)}
.btn-delete{background:rgba(244,63,94,.06);border:1px solid rgba(244,63,94,.15);color:var(--rose)}
.btn-delete:hover{background:rgba(244,63,94,.15)}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--txt2)}
.btn-ghost:hover{color:var(--txt);border-color:rgba(255,255,255,.14);background:var(--card-hov)}
.btn-primary{background:linear-gradient(135deg,var(--amber),var(--rose));color:#fff;border:none}
.btn-primary:hover{opacity:.88;transform:translateY(-1px)}
.btn-cyan{background:rgba(0,212,255,.1);border:1px solid rgba(0,212,255,.25);color:var(--cyan)}
.btn-cyan:hover{background:rgba(0,212,255,.18)}

/* EMPTY STATE */
.empty{text-align:center;padding:3rem 1.5rem;color:var(--txt3)}
.empty i{font-size:2.8rem;display:block;margin-bottom:.7rem;opacity:.25}
.empty-t{font-family:'Syne',sans-serif;font-size:.95rem;color:var(--txt2);margin-bottom:.3rem}

/* PAGINATION */
.pagination{display:flex;gap:.4rem;justify-content:center;margin-top:1.1rem;flex-wrap:wrap;padding-bottom:.5rem}
.pag-btn{min-width:30px;height:30px;padding:0 8px;border-radius:var(--r);background:var(--card);border:1px solid var(--border);color:var(--txt2);cursor:pointer;font-size:.72rem;transition:all .18s;display:inline-flex;align-items:center;justify-content:center;gap:3px;font-family:'JetBrains Mono',monospace}
.pag-btn:hover,.pag-btn.active{border-color:rgba(245,158,11,.35);color:var(--amber)}

/* LOADER */
.loader{display:flex;align-items:center;justify-content:center;padding:2.5rem;gap:.6rem;color:var(--txt3);font-size:.8rem}
.spinner{width:18px;height:18px;border:2px solid rgba(0,212,255,.2);border-top-color:var(--cyan);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}

/* REPLIES */
.replies-box{margin-top:.9rem;border-top:1px solid rgba(255,255,255,.05);padding-top:.9rem;display:none}
.replies-box.open{display:block}
.reply-item{display:flex;gap:9px;margin-bottom:.7rem;animation:fadeUp .25s ease both}
.reply-av{width:26px;height:26px;border-radius:8px;background:linear-gradient(135deg,var(--cyan),var(--neon));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-weight:800;font-size:.62rem;color:#000;flex-shrink:0}
.reply-body{flex:1}
.reply-meta{font-size:.63rem;color:var(--txt3);margin-bottom:2px;font-family:'JetBrains Mono',monospace}
.reply-text{font-size:.78rem;color:var(--txt2);line-height:1.55}
.reply-form{display:flex;gap:.5rem;margin-top:.7rem}
.reply-input{flex:1;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r);padding:7px 11px;color:var(--txt);font-family:'DM Sans',sans-serif;font-size:.78rem;outline:none;transition:border-color .2s}
.reply-input:focus{border-color:var(--border-act)}
.reply-input::placeholder{color:var(--txt3)}

/* ═══════════════ FORMULAIRE AJOUT ═══════════════ */
.form-group{margin-bottom:.9rem}
.form-label{display:block;font-size:.72rem;color:var(--txt2);margin-bottom:4px;font-weight:500}
.form-input,.form-select,.form-textarea{
  width:100%;padding:9px 12px;border-radius:var(--r);
  background:rgba(255,255,255,.04);border:1px solid var(--border);
  color:var(--txt);font-family:'DM Sans',sans-serif;font-size:.82rem;outline:none;
  transition:border-color .2s,box-shadow .2s
}
.form-input:focus,.form-select:focus,.form-textarea:focus{
  border-color:var(--border-act);box-shadow:0 0 0 3px rgba(0,212,255,.07)
}
.form-input::placeholder,.form-textarea::placeholder{color:var(--txt3)}
.form-textarea{resize:vertical;min-height:88px;line-height:1.6}
.form-select option{background:var(--surface);color:var(--txt)}

/* STAR RATING INPUT */
.star-input{display:flex;align-items:center;gap:4px}
.star-btn{background:none;border:none;font-size:1.5rem;cursor:pointer;color:rgba(255,255,255,.15);transition:all .18s;padding:2px;line-height:1}
.star-btn:hover,.star-btn.active{color:var(--amber);transform:scale(1.15)}
.star-val{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt3);margin-left:6px}

/* NOTES MOYENNES */
.note-bar-row{display:flex;align-items:center;gap:.6rem;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.note-bar-row:last-child{border-bottom:none}
.nb-label{font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--txt3);min-width:20px;flex-shrink:0}
.nb-bar-wrap{flex:1;height:5px;background:rgba(255,255,255,.07);border-radius:100px;overflow:hidden}
.nb-bar{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--amber),var(--orange));transition:width 1.2s ease}
.nb-count{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt3);min-width:28px;text-align:right;flex-shrink:0}
.nb-moy{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:var(--amber);text-align:center;line-height:1}
.nb-stars{color:var(--amber);font-size:1rem;text-align:center;letter-spacing:-1px;margin:2px 0}
.nb-total{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt3);text-align:center}

/* GRAPHIQUE ACTIVITÉ */
.activity-chart{display:flex;align-items:flex-end;gap:5px;height:70px;margin:8px 0}
.ac-bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px}
.ac-bar{width:100%;border-radius:3px 3px 0 0;background:linear-gradient(to top,var(--cyan),rgba(0,212,255,.3));min-height:3px;transition:height .9s ease;position:relative;cursor:pointer}
.ac-bar:hover{filter:brightness(1.3)}
.ac-label{font-family:'JetBrains Mono',monospace;font-size:.55rem;color:var(--txt3);text-align:center}
.ac-tooltip{position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);font-family:'JetBrains Mono',monospace;font-size:.55rem;color:var(--txt);white-space:nowrap;background:var(--surface);border:1px solid var(--border);padding:2px 5px;border-radius:4px;opacity:0;transition:opacity .15s;pointer-events:none;z-index:10}
.ac-bar:hover .ac-tooltip{opacity:1}

/* ALERT */
.alert{padding:.75rem 1.1rem;border-radius:var(--r);margin-bottom:.9rem;font-size:.78rem;display:flex;align-items:center;gap:8px;animation:fadeUp .3s ease}
.alert-success{background:rgba(0,255,170,.07);border:1px solid rgba(0,255,170,.2);color:var(--neon)}
.alert-error{background:rgba(244,63,94,.07);border:1px solid rgba(244,63,94,.2);color:var(--rose)}
.alert-info{background:rgba(0,212,255,.07);border:1px solid rgba(0,212,255,.2);color:var(--cyan)}

/* MODAL CONFIRM */
#confirmModal{position:fixed;inset:0;z-index:9800;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(7,9,15,.9);backdrop-filter:blur(16px);opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s}
#confirmModal.open{opacity:1;visibility:visible}
.confirm-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--rx);padding:1.8rem;max-width:380px;width:100%;text-align:center;animation:fadeUp .3s ease}
.confirm-box h3{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:700;margin-bottom:.5rem}
.confirm-box p{font-size:.8rem;color:var(--txt2);margin-bottom:1.3rem;line-height:1.55}
.confirm-btns{display:flex;gap:.6rem;justify-content:center}

/* MODAL RÉPONSES */
#replyModal{position:fixed;inset:0;z-index:9700;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(7,9,15,.88);backdrop-filter:blur(14px);opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s}
#replyModal.open{opacity:1;visibility:visible}
.reply-modal-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--rx);padding:1.5rem;max-width:560px;width:100%;max-height:80vh;display:flex;flex-direction:column;animation:fadeUp .3s ease}
.rmo-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;padding-bottom:.8rem;border-bottom:1px solid var(--border)}
.rmo-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem}
.rmo-list{flex:1;overflow-y:auto;margin-bottom:.9rem}
.rmo-form{display:flex;gap:.5rem}
.rmo-input{flex:1;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:var(--r);padding:8px 12px;color:var(--txt);font-family:'DM Sans',sans-serif;font-size:.8rem;outline:none;transition:border-color .2s}
.rmo-input:focus{border-color:var(--border-act)}
.rmo-input::placeholder{color:var(--txt3)}

/* TOAST */
#toastStack{position:fixed;bottom:1.3rem;right:1.3rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:7px;pointer-events:none}
.toast{display:flex;align-items:center;gap:9px;padding:10px 14px;background:rgba(13,17,32,.97);border:1px solid var(--border);border-radius:var(--r);font-size:.77rem;max-width:290px;pointer-events:all;transform:translateX(110px);opacity:0;transition:all .35s var(--ease);backdrop-filter:blur(18px)}
.toast.show{transform:translateX(0);opacity:1}
.toast.t-s{border-color:rgba(0,255,170,.35)}
.toast.t-e{border-color:rgba(244,63,94,.35)}
.toast.t-w{border-color:rgba(245,158,11,.35)}
.toast.t-i{border-color:rgba(0,212,255,.35)}
.t-ico{font-size:.95rem;flex-shrink:0}
.t-body .t-ttl{font-family:'Syne',sans-serif;font-weight:700;font-size:.78rem}
.t-body .t-sub{font-size:.66rem;color:var(--txt2);margin-top:1px}
.t-x{color:var(--txt3);cursor:pointer;margin-left:auto;font-size:.72rem;flex-shrink:0;padding:2px}

/* BADGE REPORT */
.report-badge{background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.2);color:var(--rose);font-family:'JetBrains Mono',monospace;font-size:.58rem;padding:2px 6px;border-radius:100px;display:inline-flex;align-items:center;gap:3px}

/* TOP LIVRES / TOP USERS */
.top-item{display:flex;align-items:center;gap:.7rem;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.top-item:last-child{border-bottom:none}
.top-rank{font-family:'Syne',sans-serif;font-weight:800;font-size:.8rem;width:20px;flex-shrink:0;color:var(--txt3)}
.top-rank.top1{color:var(--amber)}
.top-rank.top2{color:var(--txt2)}
.top-rank.top3{color:var(--orange)}
.top-info{flex:1;min-width:0}
.top-name{font-size:.78rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.top-sub{font-size:.65rem;color:var(--txt3);font-family:'JetBrains Mono',monospace;margin-top:1px}
.top-score{flex-shrink:0;font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--amber)}

/* RÉSULTAT SEARCH */
#results-count{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt3)}

/* Responsive */
@media(max-width:768px){
  .wrapper{padding:1rem}
  .stats-band{grid-template-columns:1fr 1fr}
  .main-grid{grid-template-columns:1fr}
  .tabs{gap:.25rem}
}
@media(max-width:480px){
  .stats-band{grid-template-columns:1fr}
  .page-title{font-size:1.3rem}
}
</style>
</head>
<body>
<div class="orb orb-a"></div>
<div class="orb orb-b"></div>
<div class="orb orb-c"></div>

<div class="wrapper">

  <!-- ══════ TOPBAR ══════ -->
  <div class="topbar">
    <a href="../dashboard.php" class="brand">
      <div class="brand-ico">💬</div>
      Avis &amp; Commentaires
    </a>
    <div class="topbar-nav">
      <a href="../dashboard.php" class="nav-link"><i class="bi bi-grid-1x2"></i> Dashboard</a>
      <a href="../books/index.php" class="nav-link"><i class="bi bi-compass"></i> Catalogue</a>
      <?php if (!$isAdmin): ?>
        <a href="../books/my_books.php" class="nav-link"><i class="bi bi-book-half"></i> Mes livres</a>
        <a href="../stats/journalist.php" class="nav-link"><i class="bi bi-bar-chart"></i> Stats</a>
      <?php else: ?>
        <a href="../users/index.php" class="nav-link"><i class="bi bi-people"></i> Utilisateurs</a>
        <a href="../admin/settings.php" class="nav-link"><i class="bi bi-gear"></i> Paramètres</a>
      <?php endif; ?>
      <a href="reviews.php" class="nav-link active"><i class="bi bi-chat-dots"></i> Avis</a>
    </div>
    <div class="topbar-right">
      <a href="../admin/notifications.php" class="tb-btn" title="Notifications">
        <i class="bi bi-bell"></i>
        <?php if ($notifCount > 0): ?><span class="nb-badge"><?= min($notifCount, 9) ?></span><?php endif; ?>
      </a>
      <a href="../admin/reviews.php?export=1" class="tb-btn" title="Exporter les avis"><i class="bi bi-download"></i></a>
      <div class="user-pill">
        <div class="user-av"><?= strtoupper(substr($username, 0, 1)) ?></div>
        <?= $username ?>
      </div>
      <a href="../logout.php" class="tb-btn" title="Déconnexion"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </div>

  <!-- ══════ PAGE HEADER ══════ -->
  <div class="page-hdr">
    <div class="page-title">
      <i class="bi bi-chat-dots-fill" style="color:var(--amber)"></i>
      Gestion des Avis
      <?php if ($nbEn > 0): ?>
        <span class="chip chip-att"><?= $nbEn ?> en attente</span>
      <?php endif; ?>
      <span class="live-pill" style="margin-left:.2rem">
        <span class="live-dot"></span>Temps réel
      </span>
    </div>
    <div class="page-sub">
      <?= $isAdmin
          ? 'Vue administrateur — tous les avis de la plateforme'
          : 'Modération des avis reçus sur vos publications' ?>
      &nbsp;·&nbsp; Dernière mise à jour : <span id="last-update" style="font-family:'JetBrains Mono',monospace">—</span>
    </div>
  </div>

  <!-- ══════ STATS BAND ══════ -->
  <div class="stats-band">
    <div class="stat-c">
      <span class="stat-ico">⏳</span>
      <div class="stat-val" style="color:var(--amber)" id="stat-att"><?= $nbEn ?></div>
      <div class="stat-lbl">En attente</div>
    </div>
    <div class="stat-c">
      <span class="stat-ico">✅</span>
      <div class="stat-val" style="color:var(--neon)" id="stat-pub"><?= $nbPub ?></div>
      <div class="stat-lbl">Approuvés</div>
    </div>
    <div class="stat-c">
      <span class="stat-ico">🚫</span>
      <div class="stat-val" style="color:var(--rose)" id="stat-ref"><?= $nbRef ?></div>
      <div class="stat-lbl">Refusés</div>
    </div>
    <div class="stat-c">
      <span class="stat-ico">📊</span>
      <div class="stat-val" style="color:var(--cyan)" id="stat-tot"><?= $nbTot ?></div>
      <div class="stat-lbl">Total</div>
    </div>
    <div class="stat-c">
      <span class="stat-ico">⭐</span>
      <div class="stat-val" style="color:var(--violet)" id="stat-moy"><?= number_format($noteMoyGlobal, 1) ?></div>
      <div class="stat-lbl">Note moyenne</div>
    </div>
  </div>

  <!-- ══════ MAIN GRID ══════ -->
  <div class="main-grid">

    <!-- ██ COLONNE GAUCHE : liste + filtres ██ -->
    <div style="display:flex;flex-direction:column;gap:1.2rem">

      <!-- CARD : liste des avis -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-title">
            <div class="ct-ic" style="background:rgba(245,158,11,.1)">📋</div>
            Liste des avis
          </div>
          <div style="display:flex;align-items:center;gap:.5rem">
            <span id="results-count"></span>
            <button class="btn btn-ghost" onclick="loadAvis()" title="Actualiser">
              <i class="bi bi-arrow-clockwise" id="refresh-ico"></i>
            </button>
          </div>
        </div>
        <div class="card-body" style="padding-bottom:.5rem">

          <!-- Tabs filtre statut -->
          <div class="tabs">
            <button class="tab active" data-f="all"        onclick="setFilter('all',this)">
              Tous <span class="tab-cnt" id="fc-all"><?= $nbTot ?></span>
            </button>
            <button class="tab" data-f="en_attente"        onclick="setFilter('en_attente',this)">
              ⏳ Attente <span class="tab-cnt" id="fc-att"><?= $nbEn ?></span>
            </button>
            <button class="tab" data-f="publie"            onclick="setFilter('publie',this)">
              ✅ Approuvés <span class="tab-cnt" id="fc-pub"><?= $nbPub ?></span>
            </button>
            <button class="tab" data-f="refuse"            onclick="setFilter('refuse',this)">
              🚫 Refusés <span class="tab-cnt" id="fc-ref"><?= $nbRef ?></span>
            </button>
          </div>

          <!-- Filtres avancés -->
          <div class="filters-row">
            <div class="search-wrap">
              <i class="bi bi-search"></i>
              <input type="search" id="searchInp" placeholder="Livre, auteur, commentaire…"
                     autocomplete="off" oninput="debounceLoad()">
            </div>
            <?php if (!empty($mesLivres)): ?>
            <select class="flt-sel" id="livreFilter" onchange="debounceLoad()">
              <option value="">Tous les livres</option>
              <?php foreach ($mesLivres as $lv): ?>
              <option value="<?= (int)$lv['id'] ?>">
                <?= htmlspecialchars(mb_substr($lv['titre'], 0, 40), ENT_QUOTES, 'UTF-8') ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="star-filter">
              <span>Note min :</span>
              <?php for ($i = 1; $i <= 5; $i++): ?>
              <button class="sf-btn" data-star="<?= $i ?>" onclick="setNoteMin(<?= $i ?>)" title="<?= $i ?>★">★</button>
              <?php endfor; ?>
              <button class="sf-reset" onclick="setNoteMin(0)" title="Effacer filtre note">✕</button>
            </div>
          </div>

          <!-- Zone liste -->
          <div id="avis-list">
            <div class="loader"><div class="spinner"></div> Chargement des avis…</div>
          </div>

          <!-- Pagination -->
          <div class="pagination" id="pagination"></div>
        </div>
      </div>

      <!-- CARD : Activité hebdomadaire -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-title">
            <div class="ct-ic" style="background:rgba(0,212,255,.1)">📈</div>
            Activité — 7 derniers jours
          </div>
          <button class="btn btn-ghost" onclick="loadStatsFull()" style="font-size:.65rem">
            <i class="bi bi-arrow-clockwise"></i> Actualiser
          </button>
        </div>
        <div class="card-body">
          <div class="activity-chart" id="activity-chart">
            <div class="loader" style="padding:.5rem 0"><div class="spinner"></div></div>
          </div>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem" id="activity-legend"></div>
        </div>
      </div>

      <!-- CARD : Répartition des notes -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-title">
            <div class="ct-ic" style="background:rgba(245,158,11,.1)">⭐</div>
            Répartition des notes
          </div>
        </div>
        <div class="card-body" id="repartition-notes">
          <div class="loader" style="padding:.5rem 0"><div class="spinner"></div></div>
        </div>
      </div>

    </div>

    <!-- ██ COLONNE DROITE ██ -->
    <div style="display:flex;flex-direction:column;gap:1.2rem">

      <!-- FORMULAIRE AJOUT AVIS -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-title">
            <div class="ct-ic" style="background:rgba(0,212,255,.1)">✍️</div>
            Publier un avis
          </div>
        </div>
        <div class="card-body">
          <div id="formAlert"></div>
          <div class="form-group">
            <label class="form-label">Livre *</label>
            <select class="form-select" id="formLivre">
              <option value="">— Choisir un livre —</option>
              <?php foreach ($livresPourForm as $lv): ?>
              <option value="<?= (int)$lv['id'] ?>">
                <?= htmlspecialchars($lv['titre'], ENT_QUOTES, 'UTF-8') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Note (optionnelle)</label>
            <div class="star-input" id="starInput">
              <?php for ($i = 1; $i <= 5; $i++): ?>
              <button type="button" class="star-btn" data-star="<?= $i ?>"
                      onclick="setFormStar(<?= $i ?>)" title="<?= $i ?> étoile<?= $i > 1 ? 's' : '' ?>">★</button>
              <?php endfor; ?>
              <span class="star-val" id="starValLabel">Aucune note</span>
              <button type="button" onclick="setFormStar(0)"
                      style="background:none;border:none;color:var(--txt3);font-size:.7rem;cursor:pointer;margin-left:3px"
                      title="Effacer la note">✕</button>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Commentaire *</label>
            <textarea class="form-textarea" id="formComment"
                      placeholder="Votre avis sur ce livre…" maxlength="2000"></textarea>
            <div style="text-align:right;font-family:'JetBrains Mono',monospace;font-size:.58rem;color:var(--txt3);margin-top:3px">
              <span id="charCount">0</span>/2000
            </div>
          </div>
          <button class="btn btn-primary" style="width:100%;justify-content:center;padding:10px"
                  id="submitBtn" onclick="submitAvis()">
            <i class="bi bi-send"></i> Publier l'avis
          </button>
          <div style="font-size:.68rem;color:var(--txt3);text-align:center;margin-top:.5rem">
            <?php if ($isAdmin): ?>
              <i class="bi bi-lightning-fill" style="color:var(--neon)"></i> Publication immédiate (Admin)
            <?php else: ?>
              <i class="bi bi-clock" style="color:var(--amber)"></i> Soumis en attente de modération
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- NOTES MOYENNES PAR LIVRE -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-title">
            <div class="ct-ic" style="background:rgba(245,158,11,.1)">⭐</div>
            Notes moyennes par livre
          </div>
        </div>
        <div class="card-body">
          <div id="notes-moyennes-global" style="text-align:center;padding:.3rem 0 .9rem;border-bottom:1px solid var(--border);margin-bottom:.7rem">
            <div class="nb-moy" id="nb-moy-val"><?= number_format($noteMoyGlobal, 1) ?></div>
            <div class="nb-stars" id="nb-moy-stars">
              <?= str_repeat('★', (int)round($noteMoyGlobal)) . str_repeat('☆', 5 - (int)round($noteMoyGlobal)) ?>
            </div>
            <div class="nb-total" id="nb-moy-total">sur <?= $nbPub ?> avis publiés</div>
          </div>
          <div id="notesMoyennes">
            <div class="loader" style="padding:.5rem 0"><div class="spinner"></div></div>
          </div>
        </div>
      </div>

      <!-- TOP LIVRES & UTILISATEURS -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-title">
            <div class="ct-ic" style="background:rgba(0,255,170,.1)">🏆</div>
            Top livres commentés
          </div>
        </div>
        <div class="card-body" id="top-livres">
          <div class="loader" style="padding:.5rem 0"><div class="spinner"></div></div>
        </div>
      </div>

      <!-- UTILISATEURS LES PLUS ACTIFS -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-title">
            <div class="ct-ic" style="background:rgba(124,58,237,.1)">👥</div>
            Lecteurs les plus actifs
          </div>
        </div>
        <div class="card-body" id="top-users">
          <div class="loader" style="padding:.5rem 0"><div class="spinner"></div></div>
        </div>
      </div>

      <!-- GUIDE RAPIDE -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-title">
            <div class="ct-ic" style="background:rgba(124,58,237,.1)">💡</div>
            Guide de modération
          </div>
        </div>
        <div class="card-body" style="font-size:.78rem;color:var(--txt2);line-height:1.7">
          <div style="display:flex;flex-direction:column;gap:.5rem">
            <div><span class="chip chip-att" style="margin-right:6px">Attente</span>Avis soumis, non visible publiquement.</div>
            <div><span class="chip chip-pub" style="margin-right:6px">Approuvé</span>Visible sur la fiche du livre + note comptée.</div>
            <div><span class="chip chip-ref" style="margin-right:6px">Refusé</span>Masqué. Auteur peut voir son avis refusé.</div>
            <div style="display:flex;gap:5px;align-items:center"><i class="bi bi-flag" style="color:var(--rose)"></i> 3 signalements → mise en attente automatique.</div>
            <?php if ($isAdmin): ?>
            <div style="padding:.6rem;background:rgba(244,63,94,.06);border:1px solid rgba(244,63,94,.15);border-radius:var(--r);margin-top:.3rem;font-size:.72rem;color:var(--rose)">
              <i class="bi bi-shield-check" style="margin-right:4px"></i>
              Administrateur — accès complet à tous les avis, suppression définitive.
            </div>
            <?php else: ?>
            <div style="padding:.6rem;background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);border-radius:var(--r);margin-top:.3rem;font-size:.72rem;color:var(--amber)">
              <i class="bi bi-pencil-fill" style="margin-right:4px"></i>
              Journaliste — modération des avis sur vos publications uniquement.
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ══════ MODAL CONFIRMATION ══════ -->
<div id="confirmModal">
  <div class="confirm-box">
    <div style="font-size:2rem;margin-bottom:.5rem" id="confirmIco">⚠️</div>
    <h3 id="confirmTitle">Confirmer l'action</h3>
    <p id="confirmText">Êtes-vous sûr de vouloir continuer ?</p>
    <div class="confirm-btns">
      <button class="btn btn-ghost" onclick="closeConfirm()"><i class="bi bi-x"></i> Annuler</button>
      <button class="btn btn-primary" id="confirmOk"><i class="bi bi-check"></i> Confirmer</button>
    </div>
  </div>
</div>

<!-- ══════ MODAL RÉPONSES ══════ -->
<div id="replyModal">
  <div class="reply-modal-box">
    <div class="rmo-header">
      <div class="rmo-title" id="rmo-title">💬 Réponses</div>
      <button class="btn btn-ghost" onclick="closeReplyModal()" style="padding:4px 8px"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="rmo-list" id="rmo-list">
      <div class="loader"><div class="spinner"></div></div>
    </div>
    <div class="rmo-form">
      <input type="text" class="rmo-input" id="rmo-input"
             placeholder="Votre réponse…" maxlength="1000"
             onkeydown="if(event.key==='Enter')submitReply()">
      <button class="btn btn-cyan" onclick="submitReply()"><i class="bi bi-send"></i></button>
    </div>
  </div>
</div>

<!-- TOASTS -->
<div id="toastStack"></div>

<!-- ══════════════════════════════════════════════════
     JAVASCRIPT COMPLET
══════════════════════════════════════════════════ -->
<script>
'use strict';

const CSRF    = <?= json_encode($csrfToken) ?>;
const IS_ADM  = <?= json_encode($isAdmin) ?>;
const API_URL = window.location.pathname;

/* ════ UTILITAIRES ════ */
function esc(s){
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function timeAgo(dateStr){
  if(!dateStr) return '—';
  const diff = (Date.now() - new Date(dateStr).getTime()) / 1000;
  if(diff < 60)     return 'à l\'instant';
  if(diff < 3600)   return Math.floor(diff/60) + ' min';
  if(diff < 86400)  return Math.floor(diff/3600) + 'h';
  if(diff < 604800) return Math.floor(diff/86400) + 'j';
  return new Date(dateStr).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'});
}
function starsHtml(note, showEmpty=true){
  const n = Math.min(5, Math.max(0, parseInt(note)||0));
  return `<span class="stars-display">${'★'.repeat(n)}</span>`
       + (showEmpty ? `<span class="stars-empty">${'☆'.repeat(5-n)}</span>` : '');
}

/* ════ TOAST ════ */
function toast(title, sub='', type='default', dur=3500){
  const icons={default:'ℹ️',success:'✅',error:'❌',warn:'⚠️',info:'💡'};
  const cls={default:'t-i',success:'t-s',error:'t-e',warn:'t-w',info:'t-i'};
  const stk = document.getElementById('toastStack');
  const el = document.createElement('div');
  el.className = `toast ${cls[type]||'t-i'}`;
  el.innerHTML = `<span class="t-ico">${icons[type]||'ℹ️'}</span>
    <div class="t-body"><div class="t-ttl">${esc(title)}</div>${sub?`<div class="t-sub">${esc(sub)}</div>`:''}</div>
    <span class="t-x" onclick="this.parentElement.remove()">✕</span>`;
  stk.appendChild(el);
  requestAnimationFrame(()=>requestAnimationFrame(()=>el.classList.add('show')));
  setTimeout(()=>{el.classList.remove('show');setTimeout(()=>el.remove(),380);},dur);
}

/* ════ API ════ */
async function api(action, data={}){
  try{
    const isGet=['list','notes_moyennes','stats_full','replies'].includes(action);
    let url = API_URL+'?action='+encodeURIComponent(action);
    let opts = {headers:{'X-Requested-With':'XMLHttpRequest'}};

    if(isGet){
      Object.entries(data).forEach(([k,v])=>{
        if(v!==''&&v!==null&&v!==undefined) url+='&'+k+'='+encodeURIComponent(v);
      });
      opts.method = 'GET';
    }else{
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify({...data, csrf:CSRF});
    }
    const res = await fetch(url, opts);
    if(!res.ok) throw new Error('HTTP '+res.status);
    return await res.json();
  }catch(e){
    console.error('[Reviews API]',action,e);
    return {success:false, error:e.message};
  }
}

/* ════ ÉTAT GLOBAL ════ */
let state = {
  filter:'all', page:1, search:'', livreId:'', noteMin:0,
  totalPages:1, loadingAvis:false
};
let replyModalAvisId = null;
let confirmCallback  = null;
let loadTimer        = null;
let pollTimer        = null;

/* ════ FILTRES ════ */
function setFilter(f, el){
  state.filter = f;
  state.page   = 1;
  document.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
  if(el) el.classList.add('active');
  loadAvis();
}
function setNoteMin(n){
  state.noteMin = n;
  state.page    = 1;
  document.querySelectorAll('.sf-btn').forEach(b=>{
    b.classList.toggle('on', parseInt(b.dataset.star)<=n && n>0);
  });
  loadAvis();
}
function debounceLoad(){
  clearTimeout(loadTimer);
  loadTimer = setTimeout(()=>{
    state.search  = document.getElementById('searchInp')?.value||'';
    state.livreId = document.getElementById('livreFilter')?.value||'';
    state.page    = 1;
    loadAvis();
  }, 300);
}
function goPage(n){
  state.page = Math.max(1, Math.min(n, state.totalPages));
  loadAvis();
}

/* ════ CHARGEMENT AVIS ════ */
async function loadAvis(){
  if(state.loadingAvis) return;
  state.loadingAvis = true;

  const list = document.getElementById('avis-list');
  const ico  = document.getElementById('refresh-ico');
  if(ico) ico.style.animation = 'spin .7s linear infinite';

  // Skeleton loader
  list.innerHTML = '<div class="loader"><div class="spinner"></div> Chargement…</div>';

  const res = await api('list',{
    statut:   state.filter,
    page:     state.page,
    search:   state.search,
    livre_id: state.livreId,
    note_min: state.noteMin,
  });

  state.loadingAvis = false;
  if(ico) ico.style.animation = '';

  if(!res.success){
    list.innerHTML=`<div class="alert alert-error"><i class="bi bi-exclamation-triangle"></i> ${esc(res.error||'Erreur de chargement')}</div>`;
    return;
  }

  updateStats(res.stats||{});
  updateLastTime();

  const rc = document.getElementById('results-count');
  if(rc) rc.textContent = (res.total||0)+' résultat'+(res.total!==1?'s':'');

  state.totalPages = res.totalPages||1;

  if(!res.avis||res.avis.length===0){
    list.innerHTML=`<div class="empty">
      <i class="bi bi-chat-square-text"></i>
      <div class="empty-t">Aucun avis trouvé</div>
      <div style="font-size:.75rem">Modifiez les filtres ou publiez un premier avis.</div>
    </div>`;
  }else{
    list.innerHTML = res.avis.map(a=>renderAvis(a)).join('');
  }

  renderPagination();
}

function renderAvis(a){
  const id      = parseInt(a.id)||0;
  const note    = parseInt(a.note)||0;
  const chipCls = {en_attente:'chip-att',publie:'chip-pub',refuse:'chip-ref'}[a.statut]||'chip-att';
  const chipLbl = {en_attente:'En attente',publie:'Approuvé',refuse:'Refusé'}[a.statut]||a.statut;
  const avLet   = esc((a.user_nom||'?').trim().charAt(0).toUpperCase());
  const avCls   = {admin:'admin-av',journaliste:'journaliste-av'}[a.user_role]||'';
  const date    = a.created_at ? timeAgo(a.created_at) : '—';
  const liked   = parseInt(a.user_liked)===1;
  const nbLikes = parseInt(a.nb_likes)||0;
  const nbReplies = parseInt(a.nb_replies)||0;
  const nbReports = parseInt(a.nb_reports)||0;
  const isReported = nbReports >= 3;

  const canApprove = a.statut !== 'publie';
  const canReject  = a.statut !== 'refuse';
  const canDelete  = IS_ADM;

  const reportBadge = nbReports > 0
    ? `<span class="report-badge"><i class="bi bi-flag"></i> ${nbReports} signalement${nbReports>1?'s':''}</span>`
    : '';

  return `<div class="avis-card${isReported?' reported':''}" id="avis-${id}" data-id="${id}">
    <div class="avis-top">
      <div class="avis-user">
        <div class="avis-av ${avCls}">${avLet}</div>
        <div>
          <div class="avis-user-name">${esc(a.user_nom||'Utilisateur')}</div>
          <div class="avis-user-email">${esc(a.user_email||'')}${a.user_role?` · <span class="chip" style="font-size:.55rem;background:rgba(255,255,255,.06);color:var(--txt3)">${esc(a.user_role)}</span>`:''}</div>
        </div>
      </div>
      <div class="avis-meta">
        ${note>0 ? starsHtml(note) + `<span class="note-num">${note}/5</span>` : ''}
        <span class="chip ${chipCls}">${chipLbl}</span>
        <span class="avis-date" title="${esc(a.created_at||'')}">${date}</span>
        ${reportBadge}
      </div>
    </div>
    <div class="avis-book"><i class="bi bi-book" style="font-size:.78rem;opacity:.6"></i> ${esc(a.livre_titre||'—')}</div>
    <div class="avis-text">${esc(a.commentaire||'(aucun commentaire)')}</div>
    <div class="avis-footer">
      <div class="avis-actions">
        ${canApprove?`<button class="btn btn-approve" onclick="actionAvis('approve',${id})"><i class="bi bi-check-lg"></i> Approuver</button>`:''}
        ${canReject ?`<button class="btn btn-reject"  onclick="actionAvis('reject',${id})"><i class="bi bi-x-lg"></i> Refuser</button>`:''}
        ${canDelete ?`<button class="btn btn-delete"  onclick="confirmDelete(${id})" title="Supprimer définitivement"><i class="bi bi-trash3"></i></button>`:''}
      </div>
      <div class="avis-social">
        <button class="social-btn${liked?' liked':''}" onclick="actionLike(${id},this)" id="like-btn-${id}" title="${liked?'Ne plus aimer':'J\'aime'}">
          <i class="bi bi-heart${liked?'-fill':''}"></i> <span id="like-cnt-${id}">${nbLikes}</span>
        </button>
        <button class="social-btn" onclick="openReplyModal(${id},'${esc(a.livre_titre||'')}')" title="Réponses">
          <i class="bi bi-chat-dots"></i> <span id="reply-cnt-${id}">${nbReplies}</span>
        </button>
        <button class="social-btn" onclick="actionReport(${id})" title="Signaler" id="report-btn-${id}">
          <i class="bi bi-flag"></i>
        </button>
      </div>
    </div>
  </div>`;
}

function renderPagination(){
  const p = document.getElementById('pagination');
  if(!p || state.totalPages<=1){if(p)p.innerHTML='';return;}
  const cur = state.page, tot = state.totalPages;
  let html = '';
  if(cur>1) html+=`<button class="pag-btn" onclick="goPage(${cur-1})"><i class="bi bi-chevron-left"></i></button>`;
  if(cur>2) html+=`<button class="pag-btn" onclick="goPage(1)">1</button>`;
  if(cur>3) html+=`<span style="color:var(--txt3);font-size:.7rem;padding:0 3px">…</span>`;
  for(let i=Math.max(1,cur-2);i<=Math.min(tot,cur+2);i++){
    html+=`<button class="pag-btn${i===cur?' active':''}" onclick="goPage(${i})">${i}</button>`;
  }
  if(cur<tot-2) html+=`<span style="color:var(--txt3);font-size:.7rem;padding:0 3px">…</span>`;
  if(cur<tot-1) html+=`<button class="pag-btn" onclick="goPage(${tot})">${tot}</button>`;
  if(cur<tot) html+=`<button class="pag-btn" onclick="goPage(${cur+1})"><i class="bi bi-chevron-right"></i></button>`;
  p.innerHTML = html;
}

/* ════ ACTIONS AVIS ════ */
async function actionAvis(action, id){
  const card = document.getElementById('avis-'+id);
  if(card){card.style.opacity='.45';card.style.pointerEvents='none';}

  const res = await api(action, {id});
  if(res.success){
    toast(res.message||'Action effectuée','','success',2500);
    loadAvis();
    loadNotesMoyennes();
  }else{
    if(card){card.style.opacity='';card.style.pointerEvents='';}
    toast('Erreur', res.error||'Action impossible','error');
  }
}

function confirmDelete(id){
  openConfirm('🗑️','Supprimer cet avis ?',
    'Cette action est irréversible. Toutes les réponses et likes associés seront également supprimés.',
    ()=>actionAvis('delete', id));
}

async function actionLike(id, btn){
  const res = await api('like',{id});
  if(res.success){
    const isLiked = res.liked;
    btn.classList.toggle('liked', isLiked);
    const ico = btn.querySelector('i');
    if(ico) ico.className = `bi bi-heart${isLiked?'-fill':''}`;
    const cnt = document.getElementById('like-cnt-'+id);
    if(cnt) cnt.textContent = res.nb_likes||0;
  }else{
    toast('Erreur',res.error||'','error');
  }
}

async function actionReport(id){
  if(!confirm('Signaler cet avis comme inapproprié ?')) return;
  const res = await api('report',{id, raison:'Contenu inapproprié'});
  if(res.success){
    toast('Signalement enregistré', res.message||'', 'warn',3500);
    loadAvis();
  }else{
    toast('Erreur', res.error||'','error');
  }
}

/* ════ MODAL CONFIRM ════ */
function openConfirm(ico,title,text,cb){
  document.getElementById('confirmIco').textContent  = ico;
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmText').textContent  = text;
  confirmCallback = cb;
  document.getElementById('confirmModal').classList.add('open');
}
function closeConfirm(){
  document.getElementById('confirmModal').classList.remove('open');
  confirmCallback = null;
}
document.getElementById('confirmOk').addEventListener('click',()=>{
  closeConfirm();
  if(typeof confirmCallback==='function') confirmCallback();
});
document.getElementById('confirmModal').addEventListener('click',e=>{
  if(e.target===e.currentTarget) closeConfirm();
});

/* ════ MODAL RÉPONSES ════ */
async function openReplyModal(avisId, livreTitre){
  replyModalAvisId = avisId;
  document.getElementById('rmo-title').textContent = `💬 Réponses — ${livreTitre||'Avis #'+avisId}`;
  document.getElementById('rmo-input').value = '';
  document.getElementById('replyModal').classList.add('open');
  await loadReplies(avisId);
}
function closeReplyModal(){
  document.getElementById('replyModal').classList.remove('open');
  replyModalAvisId = null;
}
document.getElementById('replyModal').addEventListener('click',e=>{
  if(e.target===e.currentTarget) closeReplyModal();
});
async function loadReplies(avisId){
  const list = document.getElementById('rmo-list');
  list.innerHTML = '<div class="loader"><div class="spinner"></div></div>';
  const res = await api('replies',{id:avisId});
  if(!res.success||!res.replies.length){
    list.innerHTML='<div style="text-align:center;padding:1.5rem;color:var(--txt3);font-size:.8rem"><i class="bi bi-chat-dots" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:.4rem"></i>Aucune réponse pour le moment</div>';
    return;
  }
  list.innerHTML = res.replies.map(r=>`
    <div class="reply-item">
      <div class="reply-av">${esc((r.user_nom||'?').charAt(0).toUpperCase())}</div>
      <div class="reply-body">
        <div class="reply-meta">${esc(r.user_nom||'?')} <span class="chip" style="font-size:.5rem;padding:1px 5px;background:rgba(255,255,255,.06);color:var(--txt3)">${esc(r.user_role||'')}</span> · ${timeAgo(r.created_at)}</div>
        <div class="reply-text">${esc(r.contenu)}</div>
      </div>
      ${IS_ADM?`<button class="btn btn-delete" style="padding:3px 7px;font-size:.62rem" onclick="deleteReply(${parseInt(r.id)})"><i class="bi bi-trash3"></i></button>`:''}
    </div>`).join('');
}
async function submitReply(){
  if(!replyModalAvisId) return;
  const input = document.getElementById('rmo-input');
  const contenu = input.value.trim();
  if(!contenu){ toast('Réponse vide','Saisissez votre réponse.','warn'); return; }
  input.disabled = true;
  const res = await api('reply',{id:replyModalAvisId, contenu});
  input.disabled = false;
  if(res.success){
    input.value = '';
    toast('Réponse publiée','','success',2000);
    await loadReplies(replyModalAvisId);
    // Mettre à jour compteur
    const cnt = document.getElementById('reply-cnt-'+replyModalAvisId);
    if(cnt) cnt.textContent = parseInt(cnt.textContent||0)+1;
  }else{
    toast('Erreur',res.error||'','error');
  }
}
async function deleteReply(id){
  if(!confirm('Supprimer cette réponse ?')) return;
  const res = await api('reply_delete',{id});
  if(res.success){
    toast('Réponse supprimée','','success',2000);
    if(replyModalAvisId) loadReplies(replyModalAvisId);
  }else{
    toast('Erreur',res.error||'','error');
  }
}

/* ════ FORMULAIRE AJOUT ════ */
let formStar = 0;
function setFormStar(n){
  formStar = n;
  document.querySelectorAll('#starInput .star-btn').forEach(b=>{
    b.classList.toggle('active', parseInt(b.dataset.star)<=n && n>0);
  });
  const lbl=['','1 étoile','2 étoiles','3 étoiles','4 étoiles','5 étoiles'];
  const el = document.getElementById('starValLabel');
  if(el) el.textContent = lbl[n]||'Aucune note';
}

document.getElementById('formComment').addEventListener('input',function(){
  const el=document.getElementById('charCount');
  if(el) el.textContent = this.value.length;
});

async function submitAvis(){
  const livreId  = parseInt(document.getElementById('formLivre').value)||0;
  const comment  = document.getElementById('formComment').value.trim();
  const alertEl  = document.getElementById('formAlert');
  alertEl.innerHTML = '';

  if(!livreId){
    alertEl.innerHTML='<div class="alert alert-error"><i class="bi bi-exclamation-triangle"></i> Veuillez choisir un livre.</div>';
    return;
  }
  if(!comment){
    alertEl.innerHTML='<div class="alert alert-error"><i class="bi bi-exclamation-triangle"></i> Le commentaire est obligatoire.</div>';
    return;
  }

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner" style="width:15px;height:15px;border-width:2px;margin:0 auto"></div>';

  const res = await api('add',{livre_id:livreId, note:formStar, commentaire:comment});

  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-send"></i> Publier l\'avis';

  if(res.success){
    alertEl.innerHTML='<div class="alert alert-success"><i class="bi bi-check-circle"></i> '+esc(res.message||'Avis publié !')+'</div>';
    document.getElementById('formLivre').value = '';
    document.getElementById('formComment').value = '';
    document.getElementById('charCount').textContent = '0';
    setFormStar(0);
    toast(res.message||'Avis publié !','','success',3000);
    loadAvis();
    loadNotesMoyennes();
    loadStatsFull();
    setTimeout(()=>{alertEl.innerHTML='';},5000);
  }else{
    alertEl.innerHTML=`<div class="alert alert-error"><i class="bi bi-exclamation-triangle"></i> ${esc(res.error||'Erreur inconnue')}</div>`;
    toast('Erreur',res.error||'Impossible de publier','error');
  }
}

/* ════ NOTES MOYENNES ════ */
async function loadNotesMoyennes(){
  const el = document.getElementById('notesMoyennes');
  if(!el) return;

  const res = await api('notes_moyennes');
  if(!res.success||!res.notes||res.notes.length===0){
    el.innerHTML='<div style="text-align:center;font-size:.73rem;color:var(--txt3);padding:.5rem">Aucune note publiée.</div>';
    return;
  }

  el.innerHTML = res.notes.map(n=>`
    <div class="note-bar-row">
      <div style="display:flex;align-items:center;gap:5px;flex:1;min-width:0">
        <span style="font-size:.72rem;color:var(--txt2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1">${esc(n.titre||'—')}</span>
      </div>
      <div style="display:flex;align-items:center;gap:5px;flex-shrink:0">
        <span style="color:var(--amber);font-size:.78rem">★ ${parseFloat(n.moy||0).toFixed(1)}</span>
        <span style="font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--txt3)">(${n.nb})</span>
      </div>
    </div>`).join('');
}

/* ════ STATS COMPLÈTES ════ */
async function loadStatsFull(){
  const res = await api('stats_full');
  if(!res.success||!res.data) return;
  const data = res.data;

  // Graphique activité
  if(data.activite_semaine){
    const chart  = document.getElementById('activity-chart');
    const legend = document.getElementById('activity-legend');
    if(chart){
      const vals = data.activite_semaine.map(d=>d.nb);
      const maxV = Math.max(1,...vals);
      chart.innerHTML = data.activite_semaine.map(d=>{
        const h = Math.max(3, Math.round((d.nb/maxV)*62));
        return `<div class="ac-bar-wrap">
          <div class="ac-bar" style="height:${h}px">
            <span class="ac-tooltip">${d.nb} avis</span>
          </div>
          <div class="ac-label">${esc(d.label)}</div>
        </div>`;
      }).join('');
      if(legend){
        const total = vals.reduce((a,b)=>a+b,0);
        legend.innerHTML=`<span style="font-size:.68rem;color:var(--txt3)">Total : <strong style="color:var(--cyan)">${total} avis</strong> cette semaine</span>`;
      }
    }
  }

  // Répartition des notes
  if(data.repartition_notes){
    const el = document.getElementById('repartition-notes');
    if(el){
      el.innerHTML = data.repartition_notes.map(r=>`
        <div class="note-bar-row">
          <div class="nb-label">${r.note}★</div>
          <div class="nb-bar-wrap"><div class="nb-bar" style="width:${r.pct}%"></div></div>
          <div class="nb-count">${r.nb}</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--txt3);min-width:35px;text-align:right">${r.pct}%</div>
        </div>`).join('');
    }
  }

  // Top livres
  if(data.top_livres){
    const el = document.getElementById('top-livres');
    if(el){
      if(!data.top_livres.length){
        el.innerHTML='<div style="text-align:center;color:var(--txt3);font-size:.8rem;padding:.5rem">Aucune donnée</div>';
      }else{
        const ranks=['top1','top2','top3','',''];
        el.innerHTML = data.top_livres.map((l,i)=>`
          <div class="top-item">
            <div class="top-rank ${ranks[i]||''}">#${i+1}</div>
            <div class="top-info">
              <div class="top-name">${esc(l.titre||'—')}</div>
              <div class="top-sub">${l.nb_avis} avis · ★ ${parseFloat(l.moy||0).toFixed(1)}</div>
            </div>
            <div class="top-score">★ ${parseFloat(l.moy||0).toFixed(1)}</div>
          </div>`).join('');
      }
    }
  }

  // Top utilisateurs
  if(data.top_users){
    const el = document.getElementById('top-users');
    if(el){
      if(!data.top_users.length){
        el.innerHTML='<div style="text-align:center;color:var(--txt3);font-size:.8rem;padding:.5rem">Aucune donnée</div>';
      }else{
        const ranks=['top1','top2','top3','',''];
        el.innerHTML = data.top_users.map((u,i)=>`
          <div class="top-item">
            <div class="top-rank ${ranks[i]||''}">#${i+1}</div>
            <div class="top-info">
              <div class="top-name">${esc(u.nom||'—')}</div>
              <div class="top-sub">${u.nb_avis} avis publiés <span class="chip" style="font-size:.5rem;padding:1px 5px;background:rgba(255,255,255,.06);color:var(--txt3)">${esc(u.role||'')}</span></div>
            </div>
            <div class="top-score">★ ${parseFloat(u.note_moy||0).toFixed(1)}</div>
          </div>`).join('');
      }
    }
  }

  // Avis urgents
  if(typeof data.urgents!=='undefined' && data.urgents>0){
    toast('⚠️ Avis en attente',`${data.urgents} avis en attente depuis +24h`,'warn',6000);
  }
}

/* ════ MISE À JOUR DES STATS ════ */
function updateStats(stats){
  const s = stats||{};
  const set=(id,v)=>{const e=document.getElementById(id);if(e)e.textContent=v;};
  const tot=(s.en_attente||0)+(s.publie||0)+(s.refuse||0);
  set('stat-att',  s.en_attente||0);
  set('stat-pub',  s.publie||0);
  set('stat-ref',  s.refuse||0);
  set('stat-tot',  tot);
  set('fc-all', tot);
  set('fc-att', s.en_attente||0);
  set('fc-pub', s.publie||0);
  set('fc-ref', s.refuse||0);
  if(s.note_moyenne){
    set('stat-moy', parseFloat(s.note_moyenne).toFixed(1));
    set('nb-moy-val', parseFloat(s.note_moyenne).toFixed(1));
    const n=Math.round(s.note_moyenne);
    set('nb-moy-stars','★'.repeat(n)+'☆'.repeat(5-n));
    set('nb-moy-total','sur '+(s.publie||0)+' avis publiés');
  }
}
function updateLastTime(){
  const el = document.getElementById('last-update');
  if(el) el.textContent = new Date().toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}

/* ════ POLLING TEMPS RÉEL ════ */
function startPolling(){
  pollTimer = setInterval(()=>{
    if(!document.hidden){
      loadAvis();
      loadNotesMoyennes();
    }
  }, 14000);
}

/* ════ CLAVIER ════ */
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){
    if(document.getElementById('confirmModal').classList.contains('open')) closeConfirm();
    if(document.getElementById('replyModal').classList.contains('open'))  closeReplyModal();
  }
});

/* ════ INIT ════ */
document.addEventListener('DOMContentLoaded',()=>{
  loadAvis();
  loadNotesMoyennes();
  loadStatsFull();
  startPolling();

  // Reprendre après retour sur l'onglet
  document.addEventListener('visibilitychange',()=>{
    if(!document.hidden){
      loadAvis();
      loadNotesMoyennes();
      loadStatsFull();
    }
  });
});
</script>
</body>
</html>