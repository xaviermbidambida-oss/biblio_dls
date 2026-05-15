<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — api/chat.php  v4.0 FINAL                        ║
 * ║  Endpoint IA conversationnel — Claude (Anthropic) + fallback local  ║
 * ║                                                                     ║
 * ║  Architecture :                                                     ║
 * ║    1. Sécurité (POST only, JSON strict, rate-limit session)         ║
 * ║    2. Contexte BD complet selon rôle (admin/journaliste/lecteur)    ║
 * ║    3. Appel API Anthropic (Claude)                                  ║
 * ║    4. Fallback intelligent si API indisponible                      ║
 * ║    5. Persistance des conversations (ai_conversations/ai_messages)  ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */

declare(strict_types=1);

/* ── Buffer + headers stricts dès le départ ─────────────────────── */
ob_start();

/* ── Chargement du config bootstrap ─────────────────────────────── */
$configPaths = [
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/config/config.php',
    __DIR__ . '/../includes/config.php',
];
foreach ($configPaths as $cp) {
    if (file_exists($cp)) {
        require_once $cp;
        break;
    }
}

/* ── Headers JSON stricts ────────────────────────────────────────── */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ── Gestionnaires d'erreurs → toujours retourner du JSON ───────── */
set_error_handler(function (int $errno, string $errstr): bool {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'error'  => 'Erreur serveur PHP (' . $errno . '): ' . $errstr,
        'answer' => null,
        'source' => 'error',
    ]);
    exit;
});

set_exception_handler(function (Throwable $e): void {
    ob_clean();
    http_response_code(500);
    error_log('[DLS Chat] Exception non capturée: ' . $e->getMessage());
    echo json_encode([
        'error'  => 'Exception serveur: ' . $e->getMessage(),
        'answer' => null,
        'source' => 'error',
    ]);
    exit;
});

/* ═══════════════════════════════════════════════════════════════════
   SECTION 1 — SÉCURITÉ & VALIDATION DES ENTRÉES
   ═══════════════════════════════════════════════════════════════════ */

/* ── Méthode POST uniquement ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée. Utilisez POST.', 'answer' => null]);
    exit;
}

/* ── Lire & décoder le corps JSON ────────────────────────────────── */
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['error' => 'JSON invalide : ' . json_last_error_msg(), 'answer' => null]);
    exit;
}

/* ── Extraction et validation des paramètres ─────────────────────── */
$question       = trim((string)($body['question'] ?? ''));
$role           = trim((string)($body['role']     ?? ($_SESSION['user_role'] ?? 'lecteur')));
$conversationId = (int)($body['conversation_id'] ?? 0);

/* Validation du rôle */
$allowedRoles = ['admin', 'journaliste', 'lecteur'];
if (!in_array($role, $allowedRoles, true)) {
    $role = 'lecteur';
}

/* Validation de la question */
if (empty($question)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['error' => 'La question ne peut pas être vide.', 'answer' => null]);
    exit;
}

if (strlen($question) > 2000) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'error'  => 'Question trop longue (maximum 2000 caractères).',
        'answer' => null,
    ]);
    exit;
}

/* ── Rate limiting basique via session ───────────────────────────── */
$now          = time();
$rateWindow   = 60;   // secondes
$rateMaxReqs  = 20;   // max requêtes par fenêtre

if (!isset($_SESSION['_chat_rate'])) {
    $_SESSION['_chat_rate'] = ['count' => 0, 'start' => $now];
}

if ($now - $_SESSION['_chat_rate']['start'] > $rateWindow) {
    $_SESSION['_chat_rate'] = ['count' => 0, 'start' => $now];
}

$_SESSION['_chat_rate']['count']++;

if ($_SESSION['_chat_rate']['count'] > $rateMaxReqs) {
    ob_clean();
    http_response_code(429);
    echo json_encode([
        'error'  => 'Trop de requêtes. Veuillez patienter quelques secondes.',
        'answer' => null,
    ]);
    exit;
}

/* ── Récupérer l'ID utilisateur depuis la session ────────────────── */
$userId = (int)($_SESSION['user_id'] ?? 0);

/* ═══════════════════════════════════════════════════════════════════
   SECTION 2 — CONNEXION BASE DE DONNÉES
   ═══════════════════════════════════════════════════════════════════ */

/**
 * Tente d'obtenir la connexion PDO.
 * Retourne null sans planter si la BD est indisponible.
 */
function getDatabaseConnection(): ?PDO
{
    /* Priorité 1 : via config.php déjà chargé (getDB()) */
    if (function_exists('getDB')) {
        try {
            return getDB();
        } catch (Throwable $e) {
            error_log('[DLS Chat] getDB() failed: ' . $e->getMessage());
        }
    }

    /* Priorité 2 : connexion directe avec constantes définies */
    $host    = defined('DB_HOST')    ? DB_HOST    : 'localhost';
    $name    = defined('DB_NAME')    ? DB_NAME    : 'digital_library';
    $user    = defined('DB_USER')    ? DB_USER    : 'root';
    $pass    = defined('DB_PASS')    ? DB_PASS    : '';
    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

    try {
        return new PDO(
            "mysql:host={$host};dbname={$name};charset={$charset}",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
            ]
        );
    } catch (PDOException $e) {
        error_log('[DLS Chat] PDO direct connection failed: ' . $e->getMessage());
        return null;
    }
}

$pdo = getDatabaseConnection();

/* ═══════════════════════════════════════════════════════════════════
   SECTION 3 — COLLECTE DU CONTEXTE BASE DE DONNÉES SELON LE RÔLE
   ═══════════════════════════════════════════════════════════════════
   Cette fonction lit TOUTES les tables du schéma SQL fourni :
   users, livres, achats, avis, categories, settings,
   lecture_progression, user_bonus, notifications, admin_logs
   ═══════════════════════════════════════════════════════════════════ */

/**
 * Collecte les données pertinentes depuis la BD selon le rôle.
 *
 * @param PDO|null $pdo     Connexion PDO (peut être null)
 * @param string   $role    'admin' | 'journaliste' | 'lecteur'
 * @param int      $userId  ID de l'utilisateur connecté (0 si anonyme)
 * @return string           Contexte formaté pour le prompt système
 */
function buildDatabaseContext(?PDO $pdo, string $role, int $userId): string
{
    if ($pdo === null) {
        return "(⚠️ Base de données indisponible — réponses locales uniquement)\n";
    }

    $ctx = '';

    try {

        /* ── ADMIN : accès complet à toutes les statistiques ─────── */
        if ($role === 'admin') {

            /* Utilisateurs */
            $totalUsers  = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE statut='actif'")->fetchColumn();
            $inactifUsers= (int)$pdo->query("SELECT COUNT(*) FROM users WHERE statut='inactif'")->fetchColumn();
            $blockedUsers= (int)$pdo->query("SELECT COUNT(*) FROM users WHERE statut='bloque'")->fetchColumn();
            $adminCount  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            $journCount  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='journaliste'")->fetchColumn();
            $lecteurCount= (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='lecteur'")->fetchColumn();
            $newUsers7d  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

            /* Livres */
            $totalBooks    = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
            $premiumBooks  = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND access_type='premium'")->fetchColumn();
            $standardBooks = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND access_type='standard'")->fetchColumn();
            $freeBooks     = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND (access_type='gratuit' OR prix=0)")->fetchColumn();
            $featuredBooks = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE is_featured=1")->fetchColumn();
            $bestsellerBooks=(int)$pdo->query("SELECT COUNT(*) FROM livres WHERE is_bestseller=1")->fetchColumn();
            $archivedBooks = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='archive'")->fetchColumn();
            $avgNote       = (float)$pdo->query("SELECT COALESCE(AVG(note_moyenne),0) FROM livres WHERE statut='disponible'")->fetchColumn();

            /* Ventes */
            $totalSales  = (int)$pdo->query("SELECT COUNT(*) FROM achats WHERE statut='confirme'")->fetchColumn();
            $totalRev    = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme'")->fetchColumn();
            $monthSales  = (int)$pdo->query("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
            $monthRev    = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
            $todaySales  = (int)$pdo->query("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()")->fetchColumn();
            $todayRev    = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()")->fetchColumn();
            $pendingSales = (int)$pdo->query("SELECT COUNT(*) FROM achats WHERE statut='en_attente'")->fetchColumn();
            $failedSales  = (int)$pdo->query("SELECT COUNT(*) FROM achats WHERE statut='echec'")->fetchColumn();

            /* TOP 5 livres les plus vendus */
            $topBooks = $pdo->query(
                "SELECT l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes,
                        c.nom AS categorie, l.access_type
                 FROM livres l
                 LEFT JOIN categories c ON c.id = l.categorie_id
                 WHERE l.statut='disponible'
                 ORDER BY l.nb_ventes DESC
                 LIMIT 5"
            )->fetchAll();

            /* TOP 5 acheteurs */
            $topBuyers = $pdo->query(
                "SELECT CONCAT(u.prenom,' ',u.nom) AS nom, u.email,
                        COUNT(a.id) AS nb_achats,
                        SUM(a.montant) AS total_depense
                 FROM achats a
                 JOIN users u ON u.id=a.user_id
                 WHERE a.statut='confirme'
                 GROUP BY u.id
                 ORDER BY total_depense DESC
                 LIMIT 5"
            )->fetchAll();

            /* Derniers inscrits */
            $recentUsers = $pdo->query(
                "SELECT CONCAT(prenom,' ',nom) AS nom, email, role, statut,
                        DATE_FORMAT(created_at,'%d/%m/%Y') AS date_inscription
                 FROM users
                 ORDER BY created_at DESC
                 LIMIT 5"
            )->fetchAll();

            /* Catégories */
            $categories = $pdo->query(
                "SELECT c.nom, c.icone, COUNT(l.id) AS nb_livres,
                        COALESCE(SUM(l.nb_ventes),0) AS total_ventes
                 FROM categories c
                 LEFT JOIN livres l ON l.categorie_id=c.id AND l.statut='disponible'
                 GROUP BY c.id
                 ORDER BY nb_livres DESC"
            )->fetchAll();

            /* Avis récents */
            $recentAvis = $pdo->query(
                "SELECT a.note, a.commentaire, a.statut,
                        CONCAT(u.prenom,' ',u.nom) AS lecteur,
                        l.titre AS livre,
                        DATE_FORMAT(a.created_at,'%d/%m/%Y') AS date_avis
                 FROM avis a
                 JOIN users u ON u.id=a.user_id
                 JOIN livres l ON l.id=a.livre_id
                 ORDER BY a.created_at DESC
                 LIMIT 5"
            )->fetchAll();

            /* Progression de lecture */
            $readingStats = $pdo->query(
                "SELECT COUNT(*) AS nb_sessions,
                        ROUND(AVG(pourcentage),1) AS pct_moyen,
                        COUNT(DISTINCT user_id) AS lecteurs_actifs
                 FROM lecture_progression"
            )->fetch();

            /* Bonus actifs */
            $bonusStats = $pdo->query(
                "SELECT COUNT(*) AS users_avec_bonus,
                        COALESCE(SUM(bonus_restant),0) AS bonus_disponibles,
                        COALESCE(SUM(bonus_total),0) AS bonus_total_attribues
                 FROM user_bonus"
            )->fetch();

            /* Notifications non lues */
            $unreadNotif = (int)$pdo->query(
                "SELECT COUNT(*) FROM notifications WHERE lu=0 OR is_read=0"
            )->fetchColumn();

            /* Settings actifs */
            $settings = $pdo->query(
                "SELECT setting_key, setting_value FROM settings"
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            /* ── Construction du contexte admin ──────────────────── */
            $ctx  = "=== DONNÉES ADMINISTRATEUR — DIGITAL LIBRARY SYSTEM ===\n\n";

            $ctx .= "📊 UTILISATEURS :\n";
            $ctx .= "  Total : {$totalUsers} | Actifs : {$activeUsers} | Inactifs : {$inactifUsers} | Bloqués : {$blockedUsers}\n";
            $ctx .= "  Admins : {$adminCount} | Journalistes : {$journCount} | Lecteurs : {$lecteurCount}\n";
            $ctx .= "  Nouveaux (7 derniers jours) : {$newUsers7d}\n\n";

            $ctx .= "📚 CATALOGUE :\n";
            $ctx .= "  Total disponibles : {$totalBooks} | Premium : {$premiumBooks} | Standard : {$standardBooks} | Gratuits : {$freeBooks}\n";
            $ctx .= "  En vedette : {$featuredBooks} | Bestsellers : {$bestsellerBooks} | Archivés : {$archivedBooks}\n";
            $ctx .= "  Note moyenne globale : " . number_format($avgNote, 2) . "/5\n\n";

            $ctx .= "💰 VENTES & REVENUS :\n";
            $ctx .= "  Total ventes : {$totalSales} | Revenus totaux : " . number_format($totalRev, 0, ',', ' ') . " FCFA\n";
            $ctx .= "  Ce mois : {$monthSales} ventes | " . number_format($monthRev, 0, ',', ' ') . " FCFA\n";
            $ctx .= "  Aujourd'hui : {$todaySales} ventes | " . number_format($todayRev, 0, ',', ' ') . " FCFA\n";
            $ctx .= "  En attente : {$pendingSales} | Échouées : {$failedSales}\n\n";

            if (!empty($topBooks)) {
                $ctx .= "🏆 TOP 5 LIVRES LES PLUS VENDUS :\n";
                foreach ($topBooks as $i => $b) {
                    $prix = $b['prix'] > 0 ? number_format((float)$b['prix'], 0, ',', ' ') . ' FCFA' : 'Gratuit';
                    $ctx .= "  " . ($i + 1) . ". « {$b['titre']} » par {$b['auteur']} ({$b['categorie']}) — {$b['nb_ventes']} ventes — Note : {$b['note_moyenne']}/5 — {$prix} [{$b['access_type']}]\n";
                }
                $ctx .= "\n";
            }

            if (!empty($topBuyers)) {
                $ctx .= "👑 TOP 5 ACHETEURS :\n";
                foreach ($topBuyers as $i => $u) {
                    $ctx .= "  " . ($i + 1) . ". {$u['nom']} ({$u['email']}) — {$u['nb_achats']} achats — " . number_format((float)$u['total_depense'], 0, ',', ' ') . " FCFA\n";
                }
                $ctx .= "\n";
            }

            if (!empty($recentUsers)) {
                $ctx .= "🆕 DERNIERS INSCRITS :\n";
                foreach ($recentUsers as $u) {
                    $ctx .= "  - {$u['nom']} ({$u['role']}) — {$u['email']} — Statut : {$u['statut']} — Inscrit le {$u['date_inscription']}\n";
                }
                $ctx .= "\n";
            }

            if (!empty($categories)) {
                $ctx .= "📂 CATÉGORIES :\n";
                foreach ($categories as $c) {
                    $ctx .= "  {$c['icone']} {$c['nom']} : {$c['nb_livres']} livres | {$c['total_ventes']} ventes\n";
                }
                $ctx .= "\n";
            }

            if (!empty($recentAvis)) {
                $ctx .= "💬 DERNIERS AVIS :\n";
                foreach ($recentAvis as $av) {
                    $stars = str_repeat('★', (int)$av['note']) . str_repeat('☆', 5 - (int)$av['note']);
                    $comment = mb_substr($av['commentaire'] ?? '', 0, 80);
                    $ctx .= "  {$stars} par {$av['lecteur']} sur « {$av['livre']} » ({$av['date_avis']}) — Statut : {$av['statut']}\n";
                    if ($comment) $ctx .= "    \"" . $comment . (mb_strlen($av['commentaire'] ?? '') > 80 ? '...' : '') . "\"\n";
                }
                $ctx .= "\n";
            }

            if ($readingStats) {
                $ctx .= "📖 LECTURE :\n";
                $ctx .= "  Sessions actives : {$readingStats['nb_sessions']} | Avancement moyen : {$readingStats['pct_moyen']}% | Lecteurs uniques : {$readingStats['lecteurs_actifs']}\n\n";
            }

            if ($bonusStats) {
                $ctx .= "🎁 PROGRAMME FIDÉLITÉ :\n";
                $ctx .= "  Utilisateurs avec bonus : {$bonusStats['users_avec_bonus']} | Bonus disponibles : {$bonusStats['bonus_disponibles']} | Total attribués : {$bonusStats['bonus_total_attribues']}\n\n";
            }

            $ctx .= "🔔 Notifications non lues : {$unreadNotif}\n\n";

            if (!empty($settings)) {
                $ctx .= "⚙️ PARAMÈTRES SYSTÈME :\n";
                $displaySettings = ['site_name', 'primary_color', 'theme', 'language', 'currency', 'bonus_rule', 'max_downloads', 'pagination'];
                foreach ($displaySettings as $sk) {
                    if (isset($settings[$sk])) {
                        $ctx .= "  {$sk} = {$settings[$sk]}\n";
                    }
                }
                $ctx .= "\n";
            }
        }

        /* ── JOURNALISTE : ses propres livres et statistiques ────── */
        elseif ($role === 'journaliste' && $userId > 0) {

            /* Statistiques globales de ses livres */
            $myStats = $pdo->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(statut='disponible') AS publies,
                        SUM(statut='archive') AS archives,
                        COALESCE(SUM(nb_ventes),0) AS ventes_total,
                        COALESCE(SUM(nb_lectures),0) AS lectures_total,
                        COALESCE(AVG(note_moyenne),0) AS note_moy,
                        COALESCE(SUM(nb_telechargements),0) AS dl_total
                 FROM livres WHERE ajoute_par=?"
            );
            $myStats->execute([$userId]);
            $st = $myStats->fetch();

            /* Revenus générés par ses livres */
            $revStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(a.montant),0) AS revenus,
                        COUNT(a.id) AS nb_ventes
                 FROM achats a
                 JOIN livres l ON l.id=a.livre_id
                 WHERE l.ajoute_par=? AND a.statut='confirme'"
            );
            $revStmt->execute([$userId]);
            $revData = $revStmt->fetch();

            /* Revenus du mois */
            $revMonthStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(a.montant),0) AS revenus_mois
                 FROM achats a
                 JOIN livres l ON l.id=a.livre_id
                 WHERE l.ajoute_par=? AND a.statut='confirme'
                 AND MONTH(a.created_at)=MONTH(NOW())
                 AND YEAR(a.created_at)=YEAR(NOW())"
            );
            $revMonthStmt->execute([$userId]);
            $revMois = (float)$revMonthStmt->fetchColumn();

            /* Liste de ses livres */
            $myBooksStmt = $pdo->prepare(
                "SELECT l.titre, l.auteur, l.statut, l.access_type,
                        l.note_moyenne, l.nb_ventes, l.nb_lectures,
                        l.prix, c.nom AS categorie,
                        DATE_FORMAT(l.created_at,'%d/%m/%Y') AS date_ajout
                 FROM livres l
                 LEFT JOIN categories c ON c.id=l.categorie_id
                 WHERE l.ajoute_par=?
                 ORDER BY l.nb_ventes DESC
                 LIMIT 10"
            );
            $myBooksStmt->execute([$userId]);
            $myBooks = $myBooksStmt->fetchAll();

            /* Derniers avis sur ses livres */
            $avisStmt = $pdo->prepare(
                "SELECT a.note, a.commentaire, a.statut,
                        CONCAT(u.prenom,' ',u.nom) AS lecteur,
                        l.titre AS livre,
                        DATE_FORMAT(a.created_at,'%d/%m/%Y') AS date_avis
                 FROM avis a
                 JOIN users u ON u.id=a.user_id
                 JOIN livres l ON l.id=a.livre_id
                 WHERE l.ajoute_par=?
                 ORDER BY a.created_at DESC
                 LIMIT 8"
            );
            $avisStmt->execute([$userId]);
            $myAvis = $avisStmt->fetchAll();

            /* Statistiques de lecture */
            $readStmt = $pdo->prepare(
                "SELECT COUNT(*) AS nb_lecteurs,
                        ROUND(AVG(lp.pourcentage),1) AS avancement_moyen
                 FROM lecture_progression lp
                 JOIN livres l ON l.id=lp.livre_id
                 WHERE l.ajoute_par=?"
            );
            $readStmt->execute([$userId]);
            $readStats = $readStmt->fetch();

            /* ── Construction du contexte journaliste ─────────────── */
            $ctx  = "=== MES DONNÉES JOURNALISTE ===\n\n";

            $ctx .= "📝 MES LIVRES :\n";
            $ctx .= "  Total : {$st['total']} | Publiés : {$st['publies']} | Archivés : {$st['archives']}\n\n";

            $ctx .= "💰 MES REVENUS :\n";
            $ctx .= "  Total : " . number_format((float)($revData['revenus'] ?? 0), 0, ',', ' ') . " FCFA | Ventes : {$revData['nb_ventes']}\n";
            $ctx .= "  Ce mois : " . number_format($revMois, 0, ',', ' ') . " FCFA\n\n";

            $ctx .= "📊 PERFORMANCE :\n";
            $ctx .= "  Note moyenne : " . number_format((float)$st['note_moy'], 1) . "/5\n";
            $ctx .= "  Lectures totales : {$st['lectures_total']} | Téléchargements : {$st['dl_total']}\n";
            if ($readStats) {
                $ctx .= "  Lecteurs actifs : {$readStats['nb_lecteurs']} | Avancement moyen : {$readStats['avancement_moyen']}%\n";
            }
            $ctx .= "\n";

            if (!empty($myBooks)) {
                $ctx .= "📚 MES LIVRES (détail) :\n";
                foreach ($myBooks as $b) {
                    $prix = $b['prix'] > 0 ? number_format((float)$b['prix'], 0, ',', ' ') . ' FCFA' : 'Gratuit';
                    $ctx .= "  • « {$b['titre']} » ({$b['categorie']}) — {$b['statut']} [{$b['access_type']}] — Note : {$b['note_moyenne']}/5 — {$b['nb_ventes']} ventes — {$b['nb_lectures']} lectures — {$prix}\n";
                }
                $ctx .= "\n";
            }

            if (!empty($myAvis)) {
                $ctx .= "💬 DERNIERS AVIS SUR MES LIVRES :\n";
                foreach ($myAvis as $av) {
                    $stars = str_repeat('★', (int)$av['note']) . str_repeat('☆', 5 - (int)$av['note']);
                    $comment = mb_substr($av['commentaire'] ?? '', 0, 100);
                    $ctx .= "  {$stars} — {$av['lecteur']} sur « {$av['livre']} » ({$av['date_avis']})\n";
                    if ($comment) $ctx .= "    \"" . $comment . "\"\n";
                }
                $ctx .= "\n";
            }
        }

        /* ── LECTEUR : ses achats, recommandations, catalogue ────── */
        else {

            /* Stats catalogue globales */
            $totalBooks = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
            $freeBooks  = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND (access_type='gratuit' OR prix=0)")->fetchColumn();
            $premBooks  = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND access_type='premium'")->fetchColumn();

            /* Ses achats */
            $purchStmt = null;
            $purchases = [];
            $spent     = 0.0;
            $bonusData = null;
            $readProgress = [];

            if ($userId > 0) {
                $purchStmt = $pdo->prepare(
                    "SELECT l.titre, l.auteur, l.prix, a.montant, c.nom AS categorie,
                            DATE_FORMAT(a.created_at,'%d/%m/%Y') AS date_achat,
                            a.methode
                     FROM achats a
                     JOIN livres l ON l.id=a.livre_id
                     LEFT JOIN categories c ON c.id=l.categorie_id
                     WHERE a.user_id=? AND a.statut='confirme'
                     ORDER BY a.created_at DESC
                     LIMIT 10"
                );
                $purchStmt->execute([$userId]);
                $purchases = $purchStmt->fetchAll();

                $spentStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(montant),0) FROM achats WHERE user_id=? AND statut='confirme'"
                );
                $spentStmt->execute([$userId]);
                $spent = (float)$spentStmt->fetchColumn();

                /* Progression de lecture */
                $readStmt = $pdo->prepare(
                    "SELECT l.titre, lp.pourcentage, lp.page_actuelle,
                            DATE_FORMAT(lp.updated_at,'%d/%m/%Y') AS derniere_lecture
                     FROM lecture_progression lp
                     JOIN livres l ON l.id=lp.livre_id
                     WHERE lp.user_id=?
                     ORDER BY lp.updated_at DESC
                     LIMIT 5"
                );
                $readStmt->execute([$userId]);
                $readProgress = $readStmt->fetchAll();

                /* Bonus fidélité */
                $bonusStmt = $pdo->prepare(
                    "SELECT achat_count, bonus_restant, bonus_total FROM user_bonus WHERE user_id=?"
                );
                $bonusStmt->execute([$userId]);
                $bonusData = $bonusStmt->fetch() ?: null;
            }

            /* Top recommandations (notes >= 4.0) */
            $recs = $pdo->query(
                "SELECT l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes,
                        c.nom AS genre, l.access_type
                 FROM livres l
                 LEFT JOIN categories c ON c.id=l.categorie_id
                 WHERE l.statut='disponible' AND l.note_moyenne >= 4.0
                 ORDER BY l.note_moyenne DESC, l.nb_ventes DESC
                 LIMIT 8"
            )->fetchAll();

            /* Livres gratuits */
            $freeList = $pdo->query(
                "SELECT l.titre, l.auteur, c.nom AS genre
                 FROM livres l
                 LEFT JOIN categories c ON c.id=l.categorie_id
                 WHERE l.statut='disponible' AND (l.access_type='gratuit' OR l.prix=0)
                 ORDER BY l.note_moyenne DESC
                 LIMIT 5"
            )->fetchAll();

            /* Catégories disponibles */
            $cats = $pdo->query(
                "SELECT c.nom, c.icone, COUNT(l.id) AS nb
                 FROM categories c
                 JOIN livres l ON l.categorie_id=c.id AND l.statut='disponible'
                 GROUP BY c.id
                 ORDER BY nb DESC"
            )->fetchAll();

            /* ── Construction du contexte lecteur ─────────────────── */
            $ctx  = "=== ESPACE LECTEUR — DIGITAL LIBRARY SYSTEM ===\n\n";

            $ctx .= "📚 CATALOGUE :\n";
            $ctx .= "  {$totalBooks} livres disponibles dont {$freeBooks} gratuits et {$premBooks} premium\n\n";

            if ($userId > 0) {
                $ctx .= "🛍️ MES ACHATS :\n";
                $ctx .= "  " . count($purchases) . " livres achetés | Total dépensé : " . number_format($spent, 0, ',', ' ') . " FCFA\n";

                if (!empty($purchases)) {
                    foreach ($purchases as $p) {
                        $ctx .= "  • « {$p['titre']} » par {$p['auteur']} ({$p['categorie']}) — " . number_format((float)$p['montant'], 0, ',', ' ') . " FCFA — {$p['date_achat']} via {$p['methode']}\n";
                    }
                }
                $ctx .= "\n";

                if (!empty($readProgress)) {
                    $ctx .= "📖 MA PROGRESSION DE LECTURE :\n";
                    foreach ($readProgress as $rp) {
                        $ctx .= "  • « {$rp['titre']} » — {$rp['pourcentage']}% lu (page {$rp['page_actuelle']}) — Dernière lecture : {$rp['derniere_lecture']}\n";
                    }
                    $ctx .= "\n";
                }

                if ($bonusData) {
                    $bonusRule = (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='bonus_rule' LIMIT 1")->fetchColumn() ?: 5);
                    $ctx .= "🎁 PROGRAMME FIDÉLITÉ :\n";
                    $ctx .= "  Achats accumulés : {$bonusData['achat_count']}/{$bonusRule} | Bonus disponibles : {$bonusData['bonus_restant']} | Total gagnés : {$bonusData['bonus_total']}\n";
                    $manquants = max(0, $bonusRule - (int)$bonusData['achat_count']);
                    if ($manquants > 0) {
                        $ctx .= "  Plus que {$manquants} achat(s) pour débloquer un livre gratuit !\n";
                    } else {
                        $ctx .= "  Vous avez un livre gratuit disponible ! 🎉\n";
                    }
                    $ctx .= "\n";
                }
            }

            if (!empty($recs)) {
                $ctx .= "⭐ MEILLEURES RECOMMANDATIONS :\n";
                foreach ($recs as $r) {
                    $prix = $r['prix'] > 0 ? number_format((float)$r['prix'], 0, ',', ' ') . ' FCFA' : 'Gratuit';
                    $ctx .= "  • « {$r['titre']} » par {$r['auteur']} ({$r['genre']}) — ★{$r['note_moyenne']}/5 — {$r['nb_ventes']} ventes — {$prix} [{$r['access_type']}]\n";
                }
                $ctx .= "\n";
            }

            if (!empty($freeList)) {
                $ctx .= "🎁 LIVRES GRATUITS :\n";
                foreach ($freeList as $f) {
                    $ctx .= "  • « {$f['titre']} » par {$f['auteur']} ({$f['genre']})\n";
                }
                $ctx .= "\n";
            }

            if (!empty($cats)) {
                $ctx .= "📂 CATÉGORIES DISPONIBLES :\n";
                foreach ($cats as $c) {
                    $ctx .= "  {$c['icone']} {$c['nom']} ({$c['nb']} livres)\n";
                }
                $ctx .= "\n";
            }
        }

    } catch (Throwable $e) {
        error_log('[DLS Chat] buildDatabaseContext error: ' . $e->getMessage());
        $ctx .= "\n(⚠️ Certaines données BD temporairement indisponibles : " . $e->getMessage() . ")\n";
    }

    return $ctx;
}

/* ── Collecter le contexte BD ────────────────────────────────────── */
$dbContext = buildDatabaseContext($pdo, $role, $userId);

/* ═══════════════════════════════════════════════════════════════════
   SECTION 4 — SYSTÈME PROMPT SELON LE RÔLE
   ═══════════════════════════════════════════════════════════════════ */

$roleLabels = [
    'admin'       => 'Administrateur de la plateforme',
    'journaliste' => 'Journaliste / Auteur',
    'lecteur'     => 'Lecteur',
];
$roleLabel = $roleLabels[$role] ?? 'Utilisateur';

$today = date('d/m/Y H:i');

$systemPrompt = <<<PROMPT
Tu es l'assistant intelligent du Digital Library System (DLS), une plateforme camerounaise de bibliothèque numérique.
Aujourd'hui : {$today}.
Rôle de l'utilisateur : {$roleLabel}.

{$dbContext}

=== INSTRUCTIONS STRICTES ===

DONNÉES PLATEFORME :
- Si la question concerne les données ci-dessus (livres, ventes, utilisateurs, statistiques, avis, catégories, progression, bonus), utilise-les directement pour répondre avec précision et exactitude.
- Cite les chiffres exacts fournis. Ne les invente jamais.
- Formate les montants en "1 500 FCFA" (espaces comme séparateurs de milliers).
- Ne dis jamais que tu n'as pas accès aux données si elles sont présentes ci-dessus.

QUESTIONS GÉNÉRALES :
- Pour toute question de culture générale, science, histoire, actualité, technologie, conseils, mathématiques, etc., réponds avec tes connaissances générales.
- Reste toujours pertinent, concis et utile.

FORMAT DES RÉPONSES :
- Maximum 300 mots sauf si l'utilisateur demande un rapport détaillé.
- Utilise des emojis avec parcimonie pour la lisibilité.
- Structure avec des puces ou numérotation si la liste est pertinente.
- Toujours en français, sauf si l'utilisateur écrit dans une autre langue.

ÉTHIQUE :
- Ne révèle jamais de données personnelles d'un utilisateur à un autre.
- Ne fournis pas de données sensibles (mots de passe, clés API, etc.).
- Respecte le rôle de l'utilisateur : un lecteur ne voit pas les données admin.
PROMPT;

/* ═══════════════════════════════════════════════════════════════════
   SECTION 5 — PERSISTANCE DE LA CONVERSATION (ai_messages / ai_conversations)
   ═══════════════════════════════════════════════════════════════════ */

/**
 * Sauvegarde un message dans la BD (si les tables existent).
 */
function saveMessage(?PDO $pdo, int $convId, string $role, string $content, string $source = 'api', int $tokens = 0): void
{
    if ($pdo === null || $convId <= 0) return;
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO ai_messages (conversation_id, role, content, source, tokens_used, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$convId, $role, $content, $source, $tokens]);
    } catch (Throwable $e) {
        error_log('[DLS Chat] saveMessage failed: ' . $e->getMessage());
    }
}

/**
 * Crée ou retrouve une conversation IA.
 */
function getOrCreateConversation(?PDO $pdo, int $userId, string $role, int $convId = 0): int
{
    if ($pdo === null) return 0;
    try {
        if ($convId > 0) {
            $check = $pdo->prepare("SELECT id FROM ai_conversations WHERE id=? AND (user_id=? OR user_id IS NULL)");
            $check->execute([$convId, $userId]);
            if ($check->fetch()) return $convId;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO ai_conversations (user_id, role, title, created_at, updated_at)
             VALUES (?, ?, 'Nouvelle conversation', NOW(), NOW())"
        );
        $stmt->execute([$userId > 0 ? $userId : null, $role]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[DLS Chat] getOrCreateConversation failed: ' . $e->getMessage());
        return 0;
    }
}

/* Obtenir ou créer la conversation */
$activeConvId = getOrCreateConversation($pdo, $userId, $role, $conversationId);

/* Sauvegarder la question de l'utilisateur */
saveMessage($pdo, $activeConvId, 'user', $question, 'user');

/* ═══════════════════════════════════════════════════════════════════
   SECTION 6 — APPEL API ANTHROPIC (Claude)
   ═══════════════════════════════════════════════════════════════════ */

/**
 * Appelle l'API Anthropic et retourne le texte de réponse.
 *
 * @return array{text: string, tokens: int, error: string|null}
 */
function callAnthropicAPI(string $apiKey, string $systemPrompt, string $question): array
{
    $model     = defined('ANTHROPIC_MODEL')      ? ANTHROPIC_MODEL      : 'claude-sonnet-4-20250514';
    $maxTokens = defined('ANTHROPIC_MAX_TOKENS') ? ANTHROPIC_MAX_TOKENS : 800;
    $timeout   = defined('ANTHROPIC_TIMEOUT')    ? ANTHROPIC_TIMEOUT    : 30;
    $version   = defined('ANTHROPIC_VERSION')    ? ANTHROPIC_VERSION    : '2023-06-01';

    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'system'     => $systemPrompt,
        'messages'   => [
            ['role' => 'user', 'content' => $question],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return ['text' => '', 'tokens' => 0, 'error' => 'JSON encode failed'];
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . $version,
            'User-Agent: DLS-ChatBot/4.0',
        ],
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError || $response === false) {
        error_log('[DLS Chat] cURL error: ' . $curlError);
        return ['text' => '', 'tokens' => 0, 'error' => 'cURL: ' . $curlError];
    }

    $parsed = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('[DLS Chat] API JSON parse error. HTTP ' . $httpCode . '. Body: ' . substr($response, 0, 500));
        return ['text' => '', 'tokens' => 0, 'error' => 'Réponse API invalide (HTTP ' . $httpCode . ')'];
    }

    if ($httpCode !== 200 || !isset($parsed['content'][0]['text'])) {
        $errMsg = $parsed['error']['message'] ?? ('Erreur API HTTP ' . $httpCode);
        error_log('[DLS Chat] API error ' . $httpCode . ': ' . $errMsg);
        return ['text' => '', 'tokens' => 0, 'error' => $errMsg];
    }

    $tokens = (int)($parsed['usage']['output_tokens'] ?? 0);
    return [
        'text'   => trim($parsed['content'][0]['text']),
        'tokens' => $tokens,
        'error'  => null,
    ];
}

/* ── Log erreur API ──────────────────────────────────────────────── */
function logApiError(?PDO $pdo, int $userId, int $convId, int $httpCode, string $msg): void
{
    if ($pdo === null) return;
    try {
        $pdo->prepare(
            "INSERT INTO ai_logs (user_id, conversation_id, http_code, error_msg, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        )->execute([$userId > 0 ? $userId : null, $convId > 0 ? $convId : null, $httpCode, $msg]);
    } catch (Throwable) {}
}

/* ═══════════════════════════════════════════════════════════════════
   SECTION 7 — FALLBACK INTELLIGENT (sans API Anthropic)
   ═══════════════════════════════════════════════════════════════════ */

/**
 * Génère une réponse intelligente locale basée sur le contexte BD.
 * Activé si la clé API est absente ou si l'API est indisponible.
 */
function generateIntelligentFallback(string $q, string $ctx, string $role, ?PDO $pdo): string
{
    $q_lower = mb_strtolower(trim($q), 'UTF-8');

    /* ── Helpers d'extraction de chiffres depuis le contexte ─────── */
    $extract = function (string $pattern, string $text, int $group = 1): ?string {
        return preg_match($pattern, $text, $m) ? $m[$group] : null;
    };

    /* ── SALUTATIONS ─────────────────────────────────────────────── */
    foreach (['bonjour', 'salut', 'hello', 'bonsoir', 'hi ', 'hey'] as $greet) {
        if (str_contains($q_lower, $greet)) {
            $roleGreet = match ($role) {
                'admin'       => 'Administrateur',
                'journaliste' => 'Journaliste',
                default       => 'cher lecteur',
            };
            return "👋 Bonjour {$roleGreet} ! Je suis l'assistant DLS. Je peux vous aider avec :\n" .
                   "• Les statistiques de la plateforme\n" .
                   "• La gestion de vos livres et ventes\n" .
                   "• Les recommandations de lecture\n" .
                   "• Toute question générale\n\nQue souhaitez-vous savoir ?";
        }
    }

    /* ── REQUÊTES DIRECTES À LA BD (si PDO disponible) ──────────── */
    if ($pdo !== null) {

        /* Nombre de livres */
        if (preg_match('/combien.*(livres?|ouvrages?|titres?)/i', $q) ||
            preg_match('/nombre.*(livres?|catalogue)/i', $q)) {
            $total   = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
            $gratuit = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND (access_type='gratuit' OR prix=0)")->fetchColumn();
            $premium = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND access_type='premium'")->fetchColumn();
            return "📚 Le catalogue contient **{$total} livres disponibles** :\n" .
                   "• 🌟 **{$premium}** livres premium\n" .
                   "• 📖 **" . ($total - $premium - $gratuit) . "** livres standard\n" .
                   "• 🎁 **{$gratuit}** livres gratuits";
        }

        /* Nombre d'utilisateurs */
        if (preg_match('/combien.*(utilisateurs?|membres?|inscrits?)/i', $q) ||
            str_contains($q_lower, 'utilisateur')) {
            $total  = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $actifs = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE statut='actif'")->fetchColumn();
            $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            $journ  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='journaliste'")->fetchColumn();
            return "👥 **{$total} utilisateurs** inscrits sur la plateforme :\n" .
                   "• ✅ **{$actifs}** actifs\n" .
                   "• 🔑 **{$admins}** administrateurs\n" .
                   "• ✍️ **{$journ}** journalistes\n" .
                   "• 📚 **" . ($total - $admins - $journ) . "** lecteurs";
        }

        /* Ventes / revenus */
        if (preg_match('/combien.*(ventes?|achats?)/i', $q) ||
            str_contains($q_lower, 'vente') ||
            str_contains($q_lower, 'achat')) {
            $total    = (int)$pdo->query("SELECT COUNT(*) FROM achats WHERE statut='confirme'")->fetchColumn();
            $rev      = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme'")->fetchColumn();
            $mois     = (int)$pdo->query("SELECT COUNT(*) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
            $revMois  = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
            return "💰 **Statistiques des ventes :**\n" .
                   "• Total : **{$total} ventes** | **" . number_format($rev, 0, ',', ' ') . " FCFA**\n" .
                   "• Ce mois : **{$mois} ventes** | **" . number_format($revMois, 0, ',', ' ') . " FCFA**";
        }

        /* Revenus */
        if (preg_match('/revenu|chiffre.d.affaire|montant|fcfa|argent/i', $q)) {
            $total   = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme'")->fetchColumn();
            $mois    = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
            $auj     = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND DATE(created_at)=CURDATE()")->fetchColumn();
            return "💵 **Revenus de la plateforme :**\n" .
                   "• Total cumulé : **" . number_format($total, 0, ',', ' ') . " FCFA**\n" .
                   "• Ce mois : **" . number_format($mois, 0, ',', ' ') . " FCFA**\n" .
                   "• Aujourd'hui : **" . number_format($auj, 0, ',', ' ') . " FCFA**";
        }

        /* Livre le plus vendu */
        if (preg_match('/plus.*(vendu|populaire|acheté)/i', $q) ||
            str_contains($q_lower, 'bestseller') ||
            str_contains($q_lower, 'top livre')) {
            $top = $pdo->query(
                "SELECT l.titre, l.auteur, l.nb_ventes, l.note_moyenne, c.nom AS cat
                 FROM livres l LEFT JOIN categories c ON c.id=l.categorie_id
                 WHERE l.statut='disponible' ORDER BY l.nb_ventes DESC LIMIT 3"
            )->fetchAll();
            if (!empty($top)) {
                $response = "🏆 **Top livres les plus vendus :**\n";
                foreach ($top as $i => $b) {
                    $response .= ($i + 1) . ". **« {$b['titre']} »** par {$b['auteur']} ({$b['cat']}) — {$b['nb_ventes']} ventes — ★{$b['note_moyenne']}/5\n";
                }
                return rtrim($response);
            }
        }

        /* Livres gratuits */
        if (str_contains($q_lower, 'gratuit')) {
            $nb = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND (access_type='gratuit' OR prix=0)")->fetchColumn();
            $list = $pdo->query("SELECT titre, auteur FROM livres WHERE statut='disponible' AND (access_type='gratuit' OR prix=0) ORDER BY note_moyenne DESC LIMIT 5")->fetchAll();
            $response = "🎁 **{$nb} livres gratuits** disponibles sur la plateforme :\n";
            foreach ($list as $b) {
                $response .= "• « {$b['titre']} » par {$b['auteur']}\n";
            }
            return rtrim($response);
        }

        /* Avis / commentaires */
        if (preg_match('/avis|commentaire|note.moyenne|évaluation/i', $q)) {
            $nb      = (int)$pdo->query("SELECT COUNT(*) FROM avis")->fetchColumn();
            $noteAvg = (float)$pdo->query("SELECT COALESCE(AVG(note),0) FROM avis WHERE statut='publie'")->fetchColumn();
            $publie  = (int)$pdo->query("SELECT COUNT(*) FROM avis WHERE statut='publie'")->fetchColumn();
            $attente = (int)$pdo->query("SELECT COUNT(*) FROM avis WHERE statut='en_attente'")->fetchColumn();
            return "💬 **Statistiques des avis :**\n" .
                   "• Total : **{$nb}** | Publiés : **{$publie}** | En attente : **{$attente}**\n" .
                   "• Note moyenne globale : **" . number_format($noteAvg, 1) . "/5**";
        }

        /* Catégories */
        if (preg_match('/catégorie|genre|type.*livre/i', $q)) {
            $cats = $pdo->query(
                "SELECT c.nom, c.icone, COUNT(l.id) AS nb
                 FROM categories c LEFT JOIN livres l ON l.categorie_id=c.id AND l.statut='disponible'
                 GROUP BY c.id ORDER BY nb DESC"
            )->fetchAll();
            if (!empty($cats)) {
                $response = "📂 **Catégories disponibles :**\n";
                foreach ($cats as $c) {
                    $response .= "{$c['icone']} **{$c['nom']}** : {$c['nb']} livres\n";
                }
                return rtrim($response);
            }
        }

        /* Bonus / fidélité */
        if (preg_match('/bonus|fidélité|récompense|point/i', $q)) {
            $bonusRule = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='bonus_rule'")->fetchColumn() ?: '5');
            $total     = (int)$pdo->query("SELECT COALESCE(SUM(bonus_restant),0) FROM user_bonus")->fetchColumn();
            return "🎁 **Programme fidélité :**\n" .
                   "• Règle : 1 livre gratuit tous les **{$bonusRule} achats**\n" .
                   "• Bonus actuellement disponibles : **{$total}**\n" .
                   "• Pour débloquer votre bonus, continuez à acheter des livres !";
        }
    }

    /* ── QUESTIONS GÉNÉRALES ─────────────────────────────────────── */
    /* Cameroun / géographie */
    if (preg_match('/cameroun|yaoundé|douala|afrique|fcfa/i', $q)) {
        return "🇨🇲 **Cameroun** : pays d'Afrique centrale, capitale politique **Yaoundé**, capitale économique **Douala**. Langues officielles : **français** et **anglais**. Environ **28 millions** d'habitants. La monnaie est le **FCFA** (Franc CFA d'Afrique Centrale).";
    }

    /* Intelligence artificielle */
    if (preg_match('/intelligence artificielle|ia\b|chatgpt|claude|llm|machine learning/i', $q)) {
        return "🤖 **Intelligence Artificielle :** domaine de l'informatique visant à simuler des capacités cognitives humaines.\nPrincipaux domaines : Machine Learning, Traitement du Langage Naturel (NLP), Vision par Ordinateur, Robotique.\nJe suis moi-même un assistant IA (Claude d'Anthropic) intégré au Digital Library System.";
    }

    /* Aide / aide-moi */
    if (str_contains($q_lower, 'aide') || str_contains($q_lower, 'help') || str_contains($q_lower, 'que sais-tu')) {
        $capabilities = match ($role) {
            'admin' => "• Statistiques complètes (utilisateurs, ventes, revenus, livres)\n• TOP acheteurs et TOP livres\n• Analyse des avis et commentaires\n• Suivi des notifications\n• Paramètres système",
            'journaliste' => "• Vos livres publiés et leurs performances\n• Vos revenus et ventes\n• Les avis de vos lecteurs\n• Statistiques de lecture",
            default => "• Catalogue et recommandations de livres\n• Vos achats et progression de lecture\n• Livres gratuits disponibles\n• Votre programme de fidélité",
        };
        return "🤖 **Je peux vous aider avec :**\n{$capabilities}\n• Toute question de culture générale\n• Conseils et informations diverses\n\nPostez votre question !";
    }

    /* ── FALLBACK GÉNÉRIQUE ──────────────────────────────────────── */
    $apiStatus = defined('ANTHROPIC_API_KEY') && strlen(ANTHROPIC_API_KEY) > 20
        ? "*(Mode dégradé — l'API IA est temporairement indisponible)*"
        : "*(Mode local — configurez `ANTHROPIC_API_KEY` dans `includes/config.php` pour des réponses IA complètes)*";

    return "Je comprends votre question : *\"{$q}\"*\n\n" .
           "En mode local, je peux vous renseigner sur toutes les données de votre plateforme DLS (livres, ventes, utilisateurs, statistiques). " .
           "Pour des réponses à des questions complexes et générales, configurez la clé API Claude.\n\n" .
           $apiStatus;
}

/* ═══════════════════════════════════════════════════════════════════
   SECTION 8 — ORCHESTRATION PRINCIPALE
   ═══════════════════════════════════════════════════════════════════ */

$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';

/* ── Cas 1 : API non configurée → fallback local immédiat ────────── */
if (empty($apiKey) || strlen($apiKey) < 20) {
    $answer = generateIntelligentFallback($question, $dbContext, $role, $pdo);
    saveMessage($pdo, $activeConvId, 'assistant', $answer, 'local');

    ob_clean();
    echo json_encode([
        'answer'          => $answer,
        'source'          => 'local',
        'conversation_id' => $activeConvId,
        'info'            => 'Clé API Anthropic non configurée. Réponse locale avec données BD.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── Cas 2 : API configurée → appel Claude ───────────────────────── */
$apiResult = callAnthropicAPI($apiKey, $systemPrompt, $question);

if ($apiResult['error'] !== null) {
    /* Échec API → fallback local avec message d'info */
    error_log('[DLS Chat] API call failed: ' . $apiResult['error']);
    logApiError($pdo, $userId, $activeConvId, 0, $apiResult['error']);

    $answer = generateIntelligentFallback($question, $dbContext, $role, $pdo);
    saveMessage($pdo, $activeConvId, 'assistant', $answer, 'local_fallback');

    ob_clean();
    echo json_encode([
        'answer'          => $answer,
        'source'          => 'local_fallback',
        'api_error'       => $apiResult['error'],
        'conversation_id' => $activeConvId,
        'info'            => 'API Claude indisponible — réponse locale avec données BD.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── Cas 3 : Succès API ──────────────────────────────────────────── */
$answer = $apiResult['text'];
saveMessage($pdo, $activeConvId, 'assistant', $answer, 'api', $apiResult['tokens']);

ob_clean();
echo json_encode([
    'answer'          => $answer,
    'source'          => 'api',
    'tokens_used'     => $apiResult['tokens'],
    'conversation_id' => $activeConvId,
], JSON_UNESCAPED_UNICODE);
exit;