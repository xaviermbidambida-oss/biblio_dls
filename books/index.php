<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — books/index.php  VERSION SaaS v6.1          ║
 * ║  Paiement · Promo · Progression · Avis · Sécurité              ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
declare(strict_types=1);

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) session_start();

// ── PDO ─────────────────────────────────────────────────────────────
$pdo = null;
foreach ([
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/config/config.php',
    __DIR__ . '/../includes/config.php',
] as $cp) {
    if (file_exists($cp)) { require_once $cp; break; }
}

if (!isset($pdo) || $pdo === null) {
    $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db_name = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $db_user = defined('DB_USER') ? DB_USER : 'root';
    $db_pass = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user,
            $db_pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET CHARACTER SET utf8mb4");
    } catch (PDOException $e) {
        $pdo = null;
        error_log('[DLS] PDO connect error: ' . $e->getMessage());
    }
}

// ── AUTO-MIGRATION DES TABLES ────────────────────────────────────────
if ($pdo !== null) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                icone VARCHAR(10) DEFAULT '📚',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT IGNORE INTO categories (id,nom,slug,icone) VALUES
            (1,'Science-Fiction','sf','🌌'),(2,'Philosophie','philo','🧠'),
            (3,'Nature & Environnement','nature','🌿'),(4,'Technologie & IA','tech','⚙️'),
            (5,'Histoire','histoire','📜'),(6,'Littérature','lit','🎭'),
            (7,'Sciences','sciences','🔬'),(8,'Économie','eco','💹'),
            (9,'Art & Culture','art','🎨'),(10,'Développement Personnel','dev','🌱')");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(150) CHARACTER SET utf8mb4 NOT NULL,
                prenom VARCHAR(150) CHARACTER SET utf8mb4 NOT NULL DEFAULT '',
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role ENUM('lecteur','journaliste','admin') NOT NULL DEFAULT 'lecteur',
                statut ENUM('actif','inactif','bloque') NOT NULL DEFAULT 'actif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS livres (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                titre VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL,
                auteur VARCHAR(150) CHARACTER SET utf8mb4 NOT NULL DEFAULT 'Auteur inconnu',
                isbn VARCHAR(20) UNIQUE,
                description TEXT CHARACTER SET utf8mb4,
                prix DECIMAL(10,2) DEFAULT 0.00,
                stock INT DEFAULT 100,
                categorie_id INT UNSIGNED,
                couverture VARCHAR(255),
                fichier_pdf VARCHAR(255),
                annee_parution YEAR,
                editeur VARCHAR(150) CHARACTER SET utf8mb4,
                langue VARCHAR(50) DEFAULT 'Français',
                pages INT DEFAULT 200,
                statut ENUM('disponible','rupture','archive') DEFAULT 'disponible',
                note_moyenne DECIMAL(3,2) DEFAULT 0.00,
                nb_ventes INT DEFAULT 0,
                contenu_extrait MEDIUMTEXT CHARACTER SET utf8mb4,
                is_featured TINYINT(1) DEFAULT 0,
                ajoute_par INT UNSIGNED,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS achats (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                livre_id INT UNSIGNED NOT NULL,
                montant DECIMAL(10,2) NOT NULL DEFAULT 0,
                methode ENUM('orange_money','mobile_money','coupon','visa','mastercard') DEFAULT 'orange_money',
                statut ENUM('en_attente','confirme','echec') DEFAULT 'confirme',
                reference VARCHAR(60) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_bonus (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL UNIQUE,
                achat_count INT UNSIGNED NOT NULL DEFAULT 0,
                bonus_total INT UNSIGNED NOT NULL DEFAULT 0,
                bonus_restant INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS coupons (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL UNIQUE,
                user_id INT UNSIGNED NOT NULL,
                livre_id INT UNSIGNED NULL,
                valeur DECIMAL(10,2) DEFAULT 0.00,
                type ENUM('gratuit','reduction') DEFAULT 'gratuit',
                utilise TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                utilise_at TIMESTAMP NULL,
                expire_at TIMESTAMP NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lecture_progression (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                livre_id INT UNSIGNED NOT NULL,
                page_actuelle INT DEFAULT 1,
                total_pages INT DEFAULT 1,
                pourcentage DECIMAL(5,2) DEFAULT 0.00,
                bookmarked TINYINT(1) DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_prog (user_id, livre_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reviews (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                livre_id INT UNSIGNED NOT NULL,
                note TINYINT UNSIGNED DEFAULT 5,
                commentaire TEXT CHARACTER SET utf8mb4,
                approuve TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_review (user_id, livre_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS favoris (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                livre_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fav (user_id, livre_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (livre_id) REFERENCES livres(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                type VARCHAR(50),
                titre VARCHAR(255) CHARACTER SET utf8mb4,
                message TEXT CHARACTER SET utf8mb4,
                icon VARCHAR(10) DEFAULT '🔔',
                lu TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Colonnes complémentaires (idempotentes)
        foreach ([
            "ALTER TABLE livres MODIFY COLUMN titre VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL",
            "ALTER TABLE livres MODIFY COLUMN auteur VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Auteur inconnu'",
            "ALTER TABLE livres MODIFY COLUMN description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            "ALTER TABLE livres MODIFY COLUMN contenu_extrait MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            "ALTER TABLE livres ADD COLUMN IF NOT EXISTS is_featured TINYINT(1) NOT NULL DEFAULT 0",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Throwable) {}
        }

    } catch (PDOException $e) {
        error_log('[DLS] DB init: ' . $e->getMessage());
    }
}

// ── IDENTITÉ ─────────────────────────────────────────────────────────
$isLoggedIn   = isset($_SESSION['user_id']);
$isAdmin      = $isLoggedIn && ($_SESSION['user_role'] ?? '') === 'admin';
$isJournalist = $isLoggedIn && ($_SESSION['user_role'] ?? '') === 'journaliste';
$userRole     = $_SESSION['user_role'] ?? 'lecteur';
$userId       = (int)($_SESSION['user_id'] ?? 0);
$username     = 'Visiteur';
if (!empty($_SESSION['user_name'])) {
    $username = $_SESSION['user_name'];
} elseif (!empty($_SESSION['user_prenom'])) {
    $username = trim(($_SESSION['user_prenom'] ?? '') . ' ' . ($_SESSION['user_nom'] ?? ''));
}
$avatarLetter = strtoupper(mb_substr($username, 0, 1, 'UTF-8')) ?: 'V';

// ── DONNÉES CATALOG ───────────────────────────────────────────────────
$categories      = [];
$allBooks        = [];
$userPurchases   = [];
$userFavoris     = [];
$userBonusInfo   = ['achat_count' => 0, 'bonus_restant' => 0, 'bonus_total' => 0];
$userProgression = [];
$dashStats       = ['total'=>0,'gratuit'=>0,'premium'=>0,'ventes'=>0,'revenus'=>0,'users'=>0,'recents'=>[]];

if ($pdo !== null) {
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $categories = $pdo->query(
            "SELECT c.id, c.nom, c.slug, c.icone, COUNT(l.id) AS nb_livres
             FROM categories c
             LEFT JOIN livres l ON l.categorie_id=c.id AND l.statut='disponible'
             GROUP BY c.id,c.nom,c.slug,c.icone ORDER BY c.nom"
        )->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT l.id, l.titre, l.auteur, l.prix, l.note_moyenne, l.nb_ventes,
                    l.pages, l.annee_parution, l.contenu_extrait, l.description,
                    l.is_featured, l.statut, l.editeur,
                    c.id AS cat_id, c.nom AS genre, c.icone AS genre_icone, c.slug AS genre_slug
             FROM livres l
             LEFT JOIN categories c ON c.id = l.categorie_id
             WHERE l.statut = 'disponible'
             ORDER BY l.is_featured DESC, l.note_moyenne DESC, l.nb_ventes DESC
             LIMIT 2000"
        );
        $stmt->execute();
        $allBooks = $stmt->fetchAll();

        $dashStats['total']   = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn();
        $dashStats['gratuit'] = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND prix=0")->fetchColumn();
        $dashStats['premium'] = (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND prix>3500")->fetchColumn();
        $dashStats['ventes']  = (int)$pdo->query("SELECT COALESCE(SUM(nb_ventes),0) FROM livres")->fetchColumn();
        $dashStats['revenus'] = (int)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
        $dashStats['users']   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE statut='actif'")->fetchColumn();
        $dashStats['recents'] = $pdo->query("SELECT l.titre, l.auteur, a.montant, a.created_at FROM achats a JOIN livres l ON l.id=a.livre_id WHERE a.statut='confirme' ORDER BY a.created_at DESC LIMIT 5")->fetchAll();

        if ($isLoggedIn && $userId > 0) {
            $stA = $pdo->prepare("SELECT livre_id FROM achats WHERE user_id=? AND statut='confirme'");
            $stA->execute([$userId]);
            $userPurchases = array_column($stA->fetchAll(), 'livre_id');

            $stF = $pdo->prepare("SELECT livre_id FROM favoris WHERE user_id=?");
            $stF->execute([$userId]);
            $userFavoris = array_column($stF->fetchAll(), 'livre_id');

            $stB = $pdo->prepare("SELECT achat_count,bonus_restant,bonus_total FROM user_bonus WHERE user_id=?");
            $stB->execute([$userId]);
            $b = $stB->fetch();
            if ($b) $userBonusInfo = $b;

            $stP = $pdo->prepare("SELECT livre_id,page_actuelle,total_pages,pourcentage FROM lecture_progression WHERE user_id=?");
            $stP->execute([$userId]);
            foreach ($stP->fetchAll() as $row) {
                $userProgression[(int)$row['livre_id']] = $row;
            }
        }

    } catch (PDOException $e) {
        error_log('[DLS books] DB read error: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── GÉNÉRATION DES LIVRES SIMULÉS SI BD VIDE ─────────────────────────
$coverEmojis    = ['📚','📘','📗','📙','📕','📓','📔','📒','🔮','💡','🌊','🏔️','⚡','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌍','🎯','🌙'];
$coverPalettes  = [
    ['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],
    ['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],
    ['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],['#0d3c2a','#1a7a5a'],
    ['#2a0d1a','#6b1a3a'],['#0d1a3c','#1a3a7a'],['#3c0d2a','#7a1a5a'],
    ['#1c0d3c','#3a1a7a'],['#0d2c1a','#1a6b3a'],['#2c1a0d','#6a3a1a'],
];

if (empty($allBooks)) {
    if (empty($categories)) {
        $categories = [
            ['id'=>1,'nom'=>'Science-Fiction','icone'=>'🌌','slug'=>'sf','nb_livres'=>800],
            ['id'=>2,'nom'=>'Philosophie','icone'=>'🧠','slug'=>'philo','nb_livres'=>800],
            ['id'=>3,'nom'=>'Nature & Environnement','icone'=>'🌿','slug'=>'nature','nb_livres'=>800],
            ['id'=>4,'nom'=>'Technologie & IA','icone'=>'⚙️','slug'=>'tech','nb_livres'=>800],
            ['id'=>5,'nom'=>'Histoire','icone'=>'📜','slug'=>'histoire','nb_livres'=>800],
            ['id'=>6,'nom'=>'Littérature','icone'=>'🎭','slug'=>'lit','nb_livres'=>800],
            ['id'=>7,'nom'=>'Sciences','icone'=>'🔬','slug'=>'sciences','nb_livres'=>800],
            ['id'=>8,'nom'=>'Économie','icone'=>'💹','slug'=>'eco','nb_livres'=>800],
            ['id'=>9,'nom'=>'Art & Culture','icone'=>'🎨','slug'=>'art','nb_livres'=>800],
            ['id'=>10,'nom'=>'Développement Personnel','icone'=>'🌱','slug'=>'dev','nb_livres'=>800],
        ];
    }
    $titlePrefixes = ["L'Art de","Le Secret de","Les Mystères de","La Voie du","Comprendre","Au-delà de","Dans l'Ombre de","Le Paradoxe de","Les Âmes de","Nuits à","Les Gardiens de","Fragments de","L'Éveil de","Le Langage de","Au Cœur de","La Mémoire de","Le Chemin de","Vers les","L'Héritage de","Le Code de","Vision de","Révélations sur","Chroniques de","Mémoires d'","Le Dernier","La Quête de","Lumière sur","Silence de","L'Architecte de","Réflexions sur"];
    $titleSuffixes = ["la Conscience","l'Avenir","la Nuit","l'Invisible","la Terre Promise","l'Âme Humaine","l'Univers","la Mémoire","l'Espoir","la Résilience","la Liberté","la Vérité","l'Infini","la Beauté","la Complexité","la Sagesse","l'Identité","la Création","l'Intelligence","la Transformation","l'Espace","le Temps","la Matière","la Lumière","les Origines","l'Existence","la Société","la Culture","la Révolution","le Destin"];
    $authorPool    = ["Elena Korvach","Jean-Marc Duvall","Amara Diallo","Dr. Kai Tanaka","Sofia Mercier","Léon Beaumont","Marie-Claire Fontaine","Ahmed Benali","Isabelle Durand","Pierre Moreau","Fatou Koné","Roberto García","Anna Schmidt","Yuki Tanaka","Ibrahim Sow","Claire Martin","Thomas Bernard","Nadia Alami","Serge Dupont","Hélène Rousseau"];
    $editPool      = ['Gallimard','Le Seuil','Flammarion','Fayard','Grasset','Actes Sud','La Découverte','Albin Michel','Robert Laffont','PUF'];
    $pricePool     = [0,0,0,1500,2000,2500,3200,3500,3800,4200,5000,5800,6800];
    $notePool      = [1.2,1.5,2.0,2.5,3.0,3.5,4.0,4.2,4.5,4.7,4.8,4.9,5.0];

    $globalIdx = 0;
    foreach ($categories as $cat) {
        for ($j = 0; $j < 800 && $globalIdx < 8000; $j++, $globalIdx++) {
            $note  = $notePool[$globalIdx % count($notePool)];
            $prix  = ($note <= 2.0) ? 0 : $pricePool[$globalIdx % count($pricePool)];
            $titre = $titlePrefixes[$globalIdx % count($titlePrefixes)] . ' ' . $titleSuffixes[($globalIdx * 7) % count($titleSuffixes)];
            $allBooks[] = [
                'id'             => $globalIdx + 1,
                'titre'          => $titre,
                'auteur'         => $authorPool[$globalIdx % count($authorPool)],
                'prix'           => $prix,
                'note_moyenne'   => $note,
                'nb_ventes'      => ($globalIdx * 37 + 100) % 9999,
                'pages'          => 150 + (($globalIdx * 13) % 500),
                'annee_parution' => 2015 + ($globalIdx % 10),
                'description'    => "Une œuvre remarquable qui explore les thèmes de {$cat['nom']} avec profondeur.",
                'contenu_extrait'=> '',
                'cat_id'         => $cat['id'],
                'genre'          => $cat['nom'],
                'genre_icone'    => $cat['icone'],
                'genre_slug'     => $cat['slug'],
                'is_featured'    => ($globalIdx < 6) ? 1 : 0,
                'editeur'        => $editPool[$globalIdx % count($editPool)],
                'statut'         => 'disponible',
            ];
        }
    }
    $dashStats = ['total'=>8000,'gratuit'=>1890,'premium'=>2340,'ventes'=>98432,'revenus'=>4500000,'users'=>3456,'recents'=>[]];
}

// ── HELPERS ───────────────────────────────────────────────────────────
function getPriceBadge(float $note, float $prix): array {
    if ($prix == 0 || $note <= 2.0) return ['label'=>'GRATUIT','class'=>'badge-free','color'=>'sage'];
    if ($prix <= 3500)               return ['label'=>'STANDARD','class'=>'badge-std','color'=>'azure'];
    return ['label'=>'PREMIUM','class'=>'badge-premium','color'=>'gold'];
}
function fmtPrice(float $prix): string {
    return $prix == 0 ? 'Gratuit' : number_format($prix, 0, '.', ' ') . ' FCFA';
}
function fmtNum($v): string {
    return is_numeric($v) ? number_format((int)$v, 0, ',', ' ') : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function starsHtml(float $note): string {
    $r = (int)round(max(0, min(5, $note)));
    return str_repeat('★', $r) . str_repeat('☆', 5 - $r);
}
function canReadFree(float $note, float $prix, string $role, bool $purchased): bool {
    if ($role === 'admin') return true;
    if ($purchased)        return true;
    if ($role === 'journaliste') return ($note < 4.5 || $prix == 0);
    return false;
}
function bookExtrait(array $book): string {
    $raw = (string)($book['contenu_extrait'] ?? '');
    if (!$raw) return '';
    return base64_encode(mb_substr($raw, 0, 12000, 'UTF-8'));
}
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$maxRender   = min(count($allBooks), 500);
$booksRender = array_slice($allBooks, 0, $maxRender);

// ═══════════════════════════════════════════════════════════════
// AJAX HANDLERS
// ═══════════════════════════════════════════════════════════════
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // ── ACHAT SÉCURISÉ ────────────────────────────────────────
    if ($action === 'purchase' && $pdo !== null && $isLoggedIn) {
        $livreId = (int)($_POST['livre_id'] ?? 0);
        $methode = $_POST['methode'] ?? 'orange_money';
        $phone   = trim($_POST['phone'] ?? '');
        $coupon  = strtoupper(trim($_POST['coupon'] ?? ''));

        // Méthodes autorisées (wallet retiré)
        $validMethods = ['orange_money','mobile_money','coupon','visa','mastercard'];
        if (!in_array($methode, $validMethods, true)) {
            echo json_encode(['ok'=>false,'msg'=>'Méthode de paiement invalide.']);
            exit;
        }

        // Validation téléphone pour Mobile Money
        if (in_array($methode, ['orange_money','mobile_money'])) {
            $cleanPhone = preg_replace('/[\s\-\+]/', '', $phone);
            if (!preg_match('/^(6|2)[0-9]{8}$/', $cleanPhone)) {
                echo json_encode(['ok'=>false,'msg'=>'Format de paiement invalide. Numéro de téléphone incorrect (ex: 6XXXXXXXX).']);
                exit;
            }
        }

        // Récupérer le livre
        $stmt = $pdo->prepare("SELECT id, titre, prix, statut FROM livres WHERE id = ? AND statut = 'disponible'");
        $stmt->execute([$livreId]);
        $livre = $stmt->fetch();
        if (!$livre) {
            echo json_encode(['ok'=>false,'msg'=>'Livre introuvable ou non disponible.']);
            exit;
        }

        $prix = (float)$livre['prix'];

        // Vérifier si déjà acheté
        $stCheck = $pdo->prepare("SELECT id FROM achats WHERE user_id=? AND livre_id=? AND statut='confirme'");
        $stCheck->execute([$userId, $livreId]);
        if ($stCheck->fetch()) {
            echo json_encode(['ok'=>false,'msg'=>'Vous avez déjà acheté ce livre.']);
            exit;
        }

        $montantFinal = $prix;
        $bonusUsed    = false;
        $couponCode   = null;

        if ($methode === 'coupon') {
            if (empty($coupon) || strlen($coupon) < 4) {
                echo json_encode(['ok'=>false,'msg'=>'Code coupon invalide.']);
                exit;
            }
            $stCoupon = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND user_id = ? AND utilise = 0 AND (expire_at IS NULL OR expire_at > NOW())");
            $stCoupon->execute([$coupon, $userId]);
            $couponRow = $stCoupon->fetch();
            if (!$couponRow) {
                echo json_encode(['ok'=>false,'msg'=>'Code coupon invalide, déjà utilisé ou expiré.']);
                exit;
            }
            $montantFinal = 0.0;
            $couponCode   = $coupon;
            $bonusUsed    = true;
        }

        // ── Transaction ────────────────────────────────────────
        try {
            $pdo->beginTransaction();

            $ref = 'DLS-' . strtoupper(uniqid('', true)) . '-' . strtoupper(substr(md5((string)$userId), 0, 4));

            $pdo->prepare("INSERT INTO achats (user_id,livre_id,montant,methode,statut,reference) VALUES (?,?,?,?,'confirme',?)")
                ->execute([$userId, $livreId, $montantFinal, $methode, $ref]);

            // Marquer le coupon utilisé
            if ($bonusUsed && $couponCode) {
                $pdo->prepare("UPDATE coupons SET utilise=1, utilise_at=NOW() WHERE code=? AND user_id=?")
                    ->execute([$couponCode, $userId]);
            }

            // Mettre à jour le compteur bonus
            $pdo->prepare("INSERT INTO user_bonus (user_id, achat_count, bonus_total, bonus_restant) VALUES (?,1,0,0) ON DUPLICATE KEY UPDATE achat_count = achat_count + 1")
                ->execute([$userId]);

            $stB = $pdo->prepare("SELECT achat_count FROM user_bonus WHERE user_id = ?");
            $stB->execute([$userId]);
            $bonusRow = $stB->fetch();
            $newCount = (int)($bonusRow['achat_count'] ?? 0);
            $newCoupon = null;

            if ($newCount > 0 && $newCount % 5 === 0) {
                $newCoupon = 'BONUS' . strtoupper(substr(md5($userId . time()), 0, 8));
                $pdo->prepare("UPDATE user_bonus SET bonus_total=bonus_total+1, bonus_restant=bonus_restant+1 WHERE user_id=?")
                    ->execute([$userId]);
                $pdo->prepare("INSERT INTO coupons (code, user_id, valeur, type) VALUES (?, ?, 0.00, 'gratuit')")
                    ->execute([$newCoupon, $userId]);
                $pdo->prepare("INSERT INTO notifications (user_id, type, titre, message, icon) VALUES (?, 'coupon', 'Code promo généré !', ?, '🎁')")
                    ->execute([$userId, "🎁 Félicitations ! Vous avez cumulé 5 achats. Votre code promo : {$newCoupon}"]);
            }

            // Mise à jour nb_ventes
            $pdo->prepare("UPDATE livres SET nb_ventes = nb_ventes + 1 WHERE id = ?")
                ->execute([$livreId]);

            $pdo->commit();

            echo json_encode([
                'ok'          => true,
                'reference'   => $ref,
                'new_coupon'  => $newCoupon,
                'achat_count' => $newCount,
                'msg'         => 'Paiement validé avec succès !',
            ]);

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[DLS purchase] ' . $e->getMessage());
            echo json_encode(['ok'=>false,'msg'=>'Erreur technique. Veuillez réessayer.']);
        }
        exit;
    }

    // ── SAUVEGARDER PROGRESSION ────────────────────────────────
    if ($action === 'save_progression' && $pdo !== null && $isLoggedIn) {
        $livreId = (int)($_POST['livre_id'] ?? 0);
        $page    = max(1, (int)($_POST['page'] ?? 1));
        $total   = max(1, (int)($_POST['total'] ?? 1));
        $pct     = $total > 0 ? round(($page / $total) * 100, 2) : 0;

        $pdo->prepare("
            INSERT INTO lecture_progression (user_id,livre_id,page_actuelle,total_pages,pourcentage)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE page_actuelle=?,total_pages=?,pourcentage=?,updated_at=NOW()
        ")->execute([$userId, $livreId, $page, $total, $pct, $page, $total, $pct]);

        echo json_encode(['ok'=>true,'page'=>$page,'pct'=>$pct]);
        exit;
    }

    // ── SAUVEGARDER AVIS ──────────────────────────────────────
    if ($action === 'save_review' && $pdo !== null && $isLoggedIn) {
        $livreId     = (int)($_POST['livre_id'] ?? 0);
        $note        = max(1, min(5, (int)($_POST['note'] ?? 5)));
        $commentaire = mb_substr(trim($_POST['commentaire'] ?? ''), 0, 1000, 'UTF-8');

        if ($livreId <= 0) { echo json_encode(['ok'=>false,'msg'=>'Livre invalide.']); exit; }

        $pdo->prepare("
            INSERT INTO reviews (user_id, livre_id, note, commentaire)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE note=?, commentaire=?, created_at=NOW()
        ")->execute([$userId, $livreId, $note, $commentaire ?: null, $note, $commentaire ?: null]);

        $avg = $pdo->prepare("SELECT AVG(note) FROM reviews WHERE livre_id=? AND approuve=1");
        $avg->execute([$livreId]);
        $newAvg = round((float)$avg->fetchColumn(), 2);
        $pdo->prepare("UPDATE livres SET note_moyenne=? WHERE id=?")->execute([$newAvg, $livreId]);

        echo json_encode(['ok'=>true,'msg'=>'Votre avis a été enregistré. Merci !']);
        exit;
    }

    // ── TOGGLE FAVORIS ─────────────────────────────────────────
    if ($action === 'toggle_fav' && $pdo !== null && $isLoggedIn) {
        $livreId = (int)($_POST['livre_id'] ?? 0);
        $mode    = $_POST['mode'] ?? 'add';
        if ($mode === 'add') {
            $pdo->prepare("INSERT IGNORE INTO favoris (user_id, livre_id) VALUES (?,?)")->execute([$userId, $livreId]);
        } else {
            $pdo->prepare("DELETE FROM favoris WHERE user_id=? AND livre_id=?")->execute([$userId, $livreId]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── VALIDER COUPON ─────────────────────────────────────────
    if ($action === 'check_coupon' && $pdo !== null && $isLoggedIn) {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        if (strlen($code) < 4) { echo json_encode(['ok'=>false,'msg'=>'Code trop court.']); exit; }
        $stmt = $pdo->prepare("SELECT id, valeur FROM coupons WHERE code=? AND user_id=? AND utilise=0 AND (expire_at IS NULL OR expire_at > NOW())");
        $stmt->execute([$code, $userId]);
        $cp = $stmt->fetch();
        if ($cp) echo json_encode(['ok'=>true,'msg'=>'✓ Code valide ! Accès gratuit au livre.']);
        else     echo json_encode(['ok'=>false,'msg'=>'Code invalide, déjà utilisé ou expiré.']);
        exit;
    }

    // ── DASHBOARD STATS ────────────────────────────────────────
    if ($action === 'dashboard_stats' && $pdo !== null) {
        $data = [
            'total_livres' => (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible'")->fetchColumn(),
            'gratuit'      => (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND prix=0")->fetchColumn(),
            'premium'      => (int)$pdo->query("SELECT COUNT(*) FROM livres WHERE statut='disponible' AND prix>3500")->fetchColumn(),
            'ventes'       => (int)$pdo->query("SELECT COALESCE(SUM(nb_ventes),0) FROM livres")->fetchColumn(),
            'revenus_mois' => (int)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM achats WHERE statut='confirme' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn(),
            'users'        => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE statut='actif'")->fetchColumn(),
            'recents'      => $pdo->query("SELECT l.titre,l.auteur,a.montant FROM achats a JOIN livres l ON l.id=a.livre_id WHERE a.statut='confirme' ORDER BY a.created_at DESC LIMIT 5")->fetchAll(),
        ];
        echo json_encode($data);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Action inconnue.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Bibliothèque — <?= fmtNum($dashStats['total']) ?>+ livres — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══ RESET & TOKENS ══════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#070b14;--paper:#0d1220;--slate:#131a2e;
  --mist:rgba(200,210,255,0.08);--fog:rgba(200,210,255,0.04);
  --gold:#e8c97d;--ember:#ff6b35;--sage:#4ecca3;--azure:#4a9eff;--plum:#9b59b6;
  --red:#ff4d6d;--amber:#f59e0b;
  --txt-primary:#f0eeea;--txt-secondary:rgba(240,238,234,0.55);--txt-muted:rgba(240,238,234,0.3);
  --glass:rgba(255,255,255,0.03);--glass-border:rgba(255,255,255,0.07);
  --ease-spring:cubic-bezier(0.34,1.56,0.64,1);--ease-smooth:cubic-bezier(0.25,0.46,0.45,0.94);
  --r:12px;--r-lg:20px;
}
html{scroll-behavior:smooth}
body{font-family:'Cabinet Grotesk',system-ui,sans-serif;background:var(--ink);color:var(--txt-primary);overflow-x:hidden;line-height:1.6;min-height:100vh}
::-webkit-scrollbar{width:3px;height:3px}::-webkit-scrollbar-track{background:var(--ink)}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* BG ORBS */
.bg-orb{position:fixed;border-radius:50%;filter:blur(110px);pointer-events:none;z-index:-2;animation:orbDrift 28s ease-in-out infinite}
.orb-a{width:650px;height:650px;background:rgba(232,201,125,0.04);top:-200px;left:-120px}
.orb-b{width:500px;height:500px;background:rgba(74,158,255,0.04);bottom:-120px;right:-100px;animation-delay:-12s}
.orb-c{width:350px;height:350px;background:rgba(78,204,163,0.03);top:40%;left:40%;animation-delay:-20s}
@keyframes orbDrift{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(40px,-30px) scale(1.06)}66%{transform:translate(-30px,40px) scale(0.94)}}

/* HEADER */
#site-header{position:sticky;top:0;z-index:900;height:62px;padding:0 1.8rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;background:rgba(7,11,20,.9);backdrop-filter:blur(24px);border-bottom:1px solid var(--glass-border);transition:background .3s}
#site-header.scrolled{background:rgba(7,11,20,.98)}
.nav-logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--txt-primary);font-family:'Clash Display',sans-serif;font-size:1rem;font-weight:600;flex-shrink:0}
.logo-mark{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));display:flex;align-items:center;justify-content:center;font-size:.9rem;box-shadow:0 0 20px rgba(232,201,125,.3);flex-shrink:0}
.hdr-search{flex:1;max-width:460px;position:relative;margin:0 1rem}
.hdr-si{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--txt-muted);font-size:.82rem;pointer-events:none}
#global-search{width:100%;padding:9px 11px 9px 34px;background:var(--glass);border:1px solid var(--glass-border);border-radius:10px;color:var(--txt-primary);font-family:'Cabinet Grotesk',sans-serif;font-size:.82rem;outline:none;transition:border-color .2s}
#global-search:focus{border-color:rgba(232,201,125,.4);box-shadow:0 0 0 3px rgba(232,201,125,.07)}
#global-search::placeholder{color:var(--txt-muted)}
.hdr-actions{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.btn-ghost-sm{font-size:.75rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;padding:6px 14px;border-radius:8px;border:1px solid var(--glass-border);transition:all .2s}
.btn-ghost-sm:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.btn-cta-sm{font-size:.75rem;font-weight:700;color:var(--ink);text-decoration:none;padding:6px 16px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--ember));transition:opacity .2s}
.btn-cta-sm:hover{opacity:.88}
.user-chip{display:flex;align-items:center;gap:7px;padding:4px 10px 4px 4px;background:var(--mist);border:1px solid var(--glass-border);border-radius:100px;text-decoration:none;color:var(--txt-primary);font-size:.75rem;font-weight:600;cursor:pointer}
.user-av{width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,var(--plum),var(--azure));display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;color:#fff;flex-shrink:0}
.role-tag{font-family:'JetBrains Mono',monospace;font-size:.57rem;padding:2px 7px;border-radius:100px;background:rgba(232,201,125,.1);color:var(--gold);border:1px solid rgba(232,201,125,.25)}
#hamburger{display:none;background:none;border:none;color:var(--txt-primary);font-size:1.3rem;cursor:pointer;padding:4px}
#mobile-nav{display:none;position:fixed;inset:0;top:62px;background:rgba(7,11,20,.98);backdrop-filter:blur(24px);z-index:850;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem}
#mobile-nav.open{display:flex}
#mobile-nav a{font-family:'Clash Display',sans-serif;font-size:1.4rem;font-weight:600;color:var(--txt-secondary);text-decoration:none;transition:color .2s}
#mobile-nav a:hover{color:var(--gold)}
@media(max-width:860px){.hdr-search{display:none}#hamburger{display:block}.hdr-actions .btn-ghost-sm,.hdr-actions .btn-cta-sm{display:none}}

/* PAGE HEADER */
.page-header{padding:2rem 2rem 1.2rem;max-width:1440px;margin:0 auto}
.breadcrumb{display:flex;align-items:center;gap:7px;font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt-muted);margin-bottom:1rem}
.breadcrumb a{color:var(--txt-muted);text-decoration:none;transition:color .2s}.breadcrumb a:hover{color:var(--gold)}
.bc-sep{opacity:.4}
.page-title-row{display:flex;align-items:flex-end;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;margin-bottom:1.5rem}
.page-title{font-family:'Clash Display',sans-serif;font-size:clamp(1.8rem,4vw,2.8rem);font-weight:700;letter-spacing:-1.5px;line-height:1.05}
.page-title .hl{background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.live-badge{display:inline-flex;align-items:center;gap:5px;font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--sage);background:rgba(78,204,163,.08);border:1px solid rgba(78,204,163,.2);padding:4px 10px;border-radius:100px}
.live-pulse{width:6px;height:6px;background:var(--sage);border-radius:50%;animation:pulse 1.6s ease-in-out infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(78,204,163,.5);opacity:1}50%{box-shadow:0 0 0 5px rgba(78,204,163,0);opacity:.6}}
.stats-pills{display:flex;gap:.7rem;flex-wrap:wrap}
.stat-pill{display:flex;align-items:center;gap:6px;padding:5px 13px;background:var(--glass);border:1px solid var(--glass-border);border-radius:100px;font-size:.7rem}
.stat-pill .spv{font-weight:700;color:var(--txt-primary)}
.stat-pill .spl{color:var(--txt-muted)}

/* DASHBOARD BAND */
.dash-band{background:linear-gradient(135deg,rgba(232,201,125,.04),rgba(74,158,255,.04));border:1px solid var(--glass-border);border-radius:var(--r-lg);padding:1.2rem 1.8rem;max-width:1440px;margin:0 2rem 1.5rem;display:flex;gap:1.5rem;flex-wrap:wrap;align-items:center}
.dash-stat{text-align:center;flex:1;min-width:90px}
.dash-val{font-family:'Clash Display',sans-serif;font-size:1.45rem;font-weight:700;letter-spacing:-1px}
.dash-lbl{font-family:'JetBrains Mono',monospace;font-size:.57rem;color:var(--txt-muted);letter-spacing:.08em;text-transform:uppercase;margin-top:2px}
.dash-recent{flex:2;min-width:200px}
.dr-title{font-family:'JetBrains Mono',monospace;font-size:.58rem;color:var(--txt-muted);letter-spacing:.1em;text-transform:uppercase;margin-bottom:.5rem;display:flex;align-items:center;gap:5px}
.dr-item{display:flex;align-items:center;gap:8px;font-size:.7rem;padding:4px 0;border-bottom:1px solid var(--glass-border)}
.dr-item:last-child{border-bottom:none}
.dr-name{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--txt-secondary)}
.dr-amt{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--gold);flex-shrink:0}
.bonus-chip{background:rgba(78,204,163,.08);border:1px solid rgba(78,204,163,.2);border-radius:10px;padding:8px 14px;display:flex;align-items:center;gap:10px;font-size:.72rem;flex-shrink:0}
.bonus-bar{width:80px;height:4px;background:var(--glass-border);border-radius:4px;overflow:hidden}
.bonus-fill{height:100%;background:linear-gradient(90deg,var(--sage),var(--azure));transition:width .5s}

/* CONTROLS */
.controls{padding:0 2rem 1.2rem;max-width:1440px;margin:0 auto;display:flex;flex-direction:column;gap:.9rem}
.filter-row{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.flt-btn{display:inline-flex;align-items:center;gap:5px;font-size:.73rem;font-weight:600;padding:6px 14px;border-radius:100px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);cursor:pointer;transition:all .2s var(--ease-spring);font-family:'Cabinet Grotesk',sans-serif;white-space:nowrap}
.flt-btn:hover,.flt-btn.active{border-color:rgba(232,201,125,.4);color:var(--gold);background:rgba(232,201,125,.06);transform:translateY(-2px)}
.cat-row{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.cat-btn{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:600;padding:5px 12px;border-radius:8px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);cursor:pointer;transition:all .2s;font-family:'Cabinet Grotesk',sans-serif}
.cat-btn:hover,.cat-btn.active{border-color:rgba(74,158,255,.4);color:var(--azure);background:rgba(74,158,255,.07)}
.sort-strip{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.sort-lbl{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--txt-muted);letter-spacing:.07em;text-transform:uppercase;flex-shrink:0}
.sort-btn{font-size:.7rem;font-weight:600;padding:5px 11px;border-radius:6px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);cursor:pointer;transition:all .2s;font-family:'Cabinet Grotesk',sans-serif}
.sort-btn:hover,.sort-btn.active{border-color:rgba(78,204,163,.4);color:var(--sage);background:rgba(78,204,163,.07)}
.ctrl-divider{height:1px;background:var(--glass-border)}
.results-info{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--txt-muted)}
#clear-btn{font-size:.7rem;color:var(--ember);background:none;border:none;cursor:pointer;font-family:'Cabinet Grotesk',sans-serif;text-decoration:underline;display:none}
.view-toggle{display:flex;gap:4px;margin-left:auto}
.view-btn{width:28px;height:28px;border-radius:6px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.78rem;transition:all .2s}
.view-btn.active,.view-btn:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}

/* BOOKS CONTAINER */
#books-container{padding:0 2rem 5rem;max-width:1440px;margin:0 auto}

/* GRID */
.grid-view{display:none}
.grid-view.active{display:block}
.books-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(195px,1fr));gap:1.1rem}

/* SCROLL VIEW */
.scroll-view{display:none}
.scroll-view.active{display:block}
.cat-section{margin-bottom:2.5rem}
.cat-section-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:.9rem}
.cat-section-title{font-family:'Clash Display',sans-serif;font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:7px}
.cat-section-count{font-family:'JetBrains Mono',monospace;font-size:.58rem;color:var(--txt-muted);background:var(--glass);border:1px solid var(--glass-border);padding:2px 7px;border-radius:100px}
.see-all-link{font-size:.72rem;color:var(--azure);text-decoration:none;display:flex;align-items:center;gap:4px;transition:color .2s}
.see-all-link:hover{color:var(--gold)}
.scroll-row{display:flex;gap:1rem;overflow-x:auto;padding-bottom:10px;scroll-snap-type:x mandatory;scrollbar-width:thin}
.scroll-row::-webkit-scrollbar{height:3px}
.scroll-card{flex-shrink:0;width:195px;scroll-snap-align:start}

/* BOOK CARD */
.book-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:var(--r);overflow:hidden;cursor:pointer;position:relative;transition:transform .3s var(--ease-spring),border-color .3s,box-shadow .3s;animation:cardIn .4s var(--ease-smooth) both}
@keyframes cardIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.book-card:hover{transform:translateY(-8px) scale(1.02);border-color:rgba(232,201,125,.25);box-shadow:0 22px 54px rgba(0,0,0,.55);z-index:5}
.book-cover{height:160px;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden}
.cover-bg{position:absolute;inset:0}
.cover-emoji{font-size:3rem;position:relative;z-index:1;filter:drop-shadow(0 4px 12px rgba(0,0,0,.5));transition:transform .3s var(--ease-spring)}
.book-card:hover .cover-emoji{transform:scale(1.15) rotate(-4deg)}
.cover-vignette{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 35%,rgba(7,11,20,.88));z-index:2}
.price-badge{position:absolute;top:8px;right:8px;z-index:3;font-family:'JetBrains Mono',monospace;font-size:.55rem;padding:3px 8px;border-radius:100px;letter-spacing:.04em}
.badge-free{background:rgba(78,204,163,.15);color:var(--sage);border:1px solid rgba(78,204,163,.3)}
.badge-std{background:rgba(74,158,255,.15);color:var(--azure);border:1px solid rgba(74,158,255,.3)}
.badge-premium{background:rgba(232,201,125,.15);color:var(--gold);border:1px solid rgba(232,201,125,.3)}
.tag-purchased{position:absolute;top:8px;left:8px;z-index:3;font-family:'JetBrains Mono',monospace;font-size:.5rem;padding:2px 6px;border-radius:100px;background:rgba(78,204,163,.15);color:var(--sage);border:1px solid rgba(78,204,163,.3)}
.tag-fav{position:absolute;bottom:8px;right:8px;z-index:3;font-size:.8rem}
.tag-top{position:absolute;bottom:8px;left:8px;z-index:3;font-family:'JetBrains Mono',monospace;font-size:.5rem;padding:2px 6px;border-radius:6px;background:rgba(255,107,53,.15);color:var(--ember);border:1px solid rgba(255,107,53,.3)}
.prog-strip{position:absolute;bottom:0;left:0;right:0;height:3px;background:rgba(255,255,255,.05);z-index:4}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--sage),var(--azure));transition:width .5s}
.card-overlay{position:absolute;inset:0;background:rgba(7,11,20,.92);z-index:10;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6rem;padding:.9rem;opacity:0;transition:opacity .2s;border-radius:var(--r)}
.book-card:hover .card-overlay{opacity:1}
.ov-title{font-family:'Clash Display',sans-serif;font-size:.8rem;font-weight:600;text-align:center;color:var(--txt-primary);line-height:1.25}
.ov-author{font-size:.65rem;color:var(--txt-secondary)}
.ov-rating{display:flex;align-items:center;gap:4px;font-size:.68rem;color:var(--gold)}
.ov-price{font-family:'JetBrains Mono',monospace;font-size:.64rem;color:var(--txt-muted)}
.ov-btns{display:flex;gap:.5rem;width:100%}
.ov-btn-read{flex:1;padding:8px 0;border-radius:8px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-size:.7rem;font-weight:700;cursor:pointer;font-family:'Cabinet Grotesk',sans-serif;transition:opacity .2s}
.ov-btn-read:hover{opacity:.85}
.ov-btn-fav{width:34px;height:34px;border-radius:8px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.78rem;transition:all .2s}
.ov-btn-fav:hover,.ov-btn-fav.active{border-color:rgba(155,89,182,.4);color:var(--plum)}
.ov-btn-info{width:34px;height:34px;border-radius:8px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.78rem;transition:all .2s}
.ov-btn-info:hover{border-color:rgba(74,158,255,.4);color:var(--azure)}
.book-body{padding:.85rem .9rem .9rem}
.book-genre{font-family:'JetBrains Mono',monospace;font-size:.55rem;color:var(--gold);letter-spacing:.09em;text-transform:uppercase;margin-bottom:3px}
.book-title{font-family:'Clash Display',sans-serif;font-size:.83rem;font-weight:600;letter-spacing:-.2px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.book-author{font-size:.68rem;color:var(--txt-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.5rem}
.book-meta{display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem}
.book-stars{color:var(--gold);font-size:.58rem;letter-spacing:-1.5px}
.star-val{font-size:.63rem;color:var(--txt-muted)}
.book-pages{font-family:'JetBrains Mono',monospace;font-size:.57rem;color:var(--txt-muted)}
.book-price{font-family:'Clash Display',sans-serif;font-size:.82rem;font-weight:700;color:var(--gold)}
.book-price.free{color:var(--sage)}
.book-price.std{color:var(--azure)}
.card-btns{display:flex;gap:5px;margin-top:.6rem}
.btn-read-card{flex:1;padding:6px 0;border-radius:7px;border:1px solid rgba(232,201,125,.25);background:rgba(232,201,125,.04);color:var(--gold);font-size:.67rem;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Cabinet Grotesk',sans-serif}
.btn-read-card:hover{background:rgba(232,201,125,.1)}
.book-card.purchased .btn-read-card{border-color:rgba(78,204,163,.3);background:rgba(78,204,163,.06);color:var(--sage)}
.btn-fav-card{width:30px;height:30px;border-radius:7px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.75rem;transition:all .2s}
.btn-fav-card:hover,.btn-fav-card.active{border-color:rgba(155,89,182,.4);color:var(--plum)}

/* EMPTY + LOAD MORE */
#empty-state{display:none;text-align:center;padding:4rem 2rem}
#empty-state.show{display:block}
.empty-icon{font-size:3.5rem;margin-bottom:1rem;display:block;opacity:.3}
.empty-title{font-family:'Clash Display',sans-serif;font-size:1.3rem;color:var(--txt-secondary);margin-bottom:.4rem}
.empty-sub{font-size:.82rem;color:var(--txt-muted)}
.load-more-wrap{text-align:center;padding:2rem;display:none}
.load-more-wrap.show{display:block}
.btn-load-more{display:inline-flex;align-items:center;gap:8px;padding:11px 26px;border-radius:12px;border:1px solid rgba(232,201,125,.3);background:rgba(232,201,125,.04);color:var(--gold);font-family:'Clash Display',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;transition:all .2s var(--ease-spring)}
.btn-load-more:hover{background:rgba(232,201,125,.08);transform:translateY(-2px)}

/* TOAST */
#toast{position:fixed;bottom:2rem;right:2rem;z-index:9800;background:rgba(13,18,32,.97);border:1px solid rgba(232,201,125,.2);border-radius:var(--r);padding:1rem 1.3rem;display:flex;align-items:center;gap:11px;font-size:.8rem;backdrop-filter:blur(20px);box-shadow:0 0 40px rgba(232,201,125,.1),0 12px 32px rgba(0,0,0,.5);transform:translateY(110px) scale(.96);opacity:0;transition:all .4s var(--ease-spring);pointer-events:none;max-width:320px}
#toast.show{transform:translateY(0) scale(1);opacity:1;pointer-events:auto}
#toast.t-success{border-color:rgba(78,204,163,.3)}
#toast.t-error{border-color:rgba(255,77,109,.4)}
#toast.t-warn{border-color:rgba(245,158,11,.3)}
.t-icon{font-size:1.1rem;flex-shrink:0}
.t-title{font-family:'Clash Display',sans-serif;font-weight:600;font-size:.82rem}
.t-msg{font-size:.72rem;color:var(--txt-muted);margin-top:1px}

/* AUTH GATE */
#auth-gate{position:fixed;inset:0;z-index:9500;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
#auth-gate.open{opacity:1;visibility:visible}
.gate-bg{position:absolute;inset:0;background:rgba(7,11,20,.9);backdrop-filter:blur(12px)}
.gate-box{position:relative;z-index:1;max-width:420px;width:100%;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:var(--r-lg);padding:2.5rem;text-align:center;box-shadow:0 60px 120px rgba(0,0,0,.6);transform:translateY(20px) scale(.97);transition:transform .4s var(--ease-spring)}
#auth-gate.open .gate-box{transform:none}
.gate-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--ember));border-radius:var(--r-lg) var(--r-lg) 0 0}
.gate-icon{font-size:2.5rem;margin-bottom:.8rem}
.gate-eyebrow{font-family:'JetBrains Mono',monospace;font-size:.63rem;letter-spacing:.12em;color:var(--gold);text-transform:uppercase;margin-bottom:.5rem}
.gate-title{font-family:'Clash Display',sans-serif;font-size:1.5rem;font-weight:700;margin-bottom:.6rem}
.gate-desc{font-size:.82rem;color:var(--txt-secondary);line-height:1.7;margin-bottom:1.8rem}
.gate-btns{display:flex;gap:.7rem}
.btn-gate-solid{flex:1;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.87rem;font-weight:700;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:opacity .2s,transform .2s}
.btn-gate-solid:hover{opacity:.88;transform:translateY(-1px)}
.btn-gate-outline{flex:1;padding:12px;border-radius:10px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-family:'Clash Display',sans-serif;font-size:.87rem;font-weight:600;cursor:pointer;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s}
.btn-gate-outline:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.gate-close{position:absolute;top:.8rem;right:.8rem;width:28px;height:28px;border-radius:7px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.78rem}

/* PAYMENT MODAL */
#pay-modal{position:fixed;inset:0;z-index:9700;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
#pay-modal.open{opacity:1;visibility:visible}
.pay-bg{position:absolute;inset:0;background:rgba(7,11,20,.9);backdrop-filter:blur(18px)}
.pay-box{position:relative;z-index:1;width:100%;max-width:540px;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:var(--r-lg);overflow:hidden;overflow-y:auto;max-height:93vh;box-shadow:0 50px 100px rgba(0,0,0,.6);transform:translateY(20px) scale(.97);transition:transform .4s var(--ease-spring)}
#pay-modal.open .pay-box{transform:none}
.pay-shimmer{height:2px;width:100%;background:linear-gradient(90deg,var(--gold),var(--ember),var(--sage));background-size:200%;animation:shim 3s linear infinite}
@keyframes shim{to{background-position:200% center}}
.pay-close{position:absolute;top:.9rem;right:.9rem;width:30px;height:30px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:10;font-size:.85rem}
.pay-close:hover{border-color:rgba(255,95,87,.3);color:#ff5f57}
.pay-header{padding:1.8rem 1.8rem 1.2rem;border-bottom:1px solid var(--glass-border)}
.pay-book-row{display:flex;align-items:center;gap:13px;margin-bottom:1.2rem}
.pay-thumb{width:52px;height:52px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.7rem;background:var(--mist);border:1px solid var(--glass-border)}
.pay-book-title{font-family:'Clash Display',sans-serif;font-size:.93rem;font-weight:600}
.pay-book-author{font-size:.73rem;color:var(--txt-secondary);margin-top:2px}
.pay-amount-row{display:flex;align-items:baseline;gap:5px}
.pal{font-size:.73rem;color:var(--txt-muted)}
.pav{font-family:'Clash Display',sans-serif;font-size:1.75rem;font-weight:700;color:var(--gold)}
.pac{font-size:.83rem;color:var(--txt-muted)}
.pay-body{padding:1.4rem 1.8rem}

/* Payment steps */
.pay-steps{display:flex;align-items:center;gap:5px;margin-bottom:1.7rem}
.step-dot{width:8px;height:8px;border-radius:50%;background:var(--glass-border);transition:all .3s}
.step-dot.active{background:var(--gold);transform:scale(1.3)}
.step-dot.done{background:var(--sage)}
.step-line{flex:1;height:1px;background:var(--glass-border);transition:background .3s}
.step-line.done{background:var(--sage)}
.step-lbl{font-family:'JetBrains Mono',monospace;font-size:.62rem;color:var(--txt-muted);margin-left:auto}

/* Method grid */
.method-grid{display:grid;grid-template-columns:1fr 1fr;gap:.65rem;margin-bottom:1.2rem}
.method-btn{display:flex;align-items:center;gap:9px;padding:11px 13px;border-radius:11px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);cursor:pointer;transition:all .2s;font-family:'Cabinet Grotesk',sans-serif;text-align:left}
.method-btn:hover{border-color:rgba(232,201,125,.25);color:var(--txt-primary)}
.method-btn.selected{border-color:var(--gold);background:rgba(232,201,125,.06);color:var(--txt-primary)}
.m-icon{font-size:1.3rem;flex-shrink:0}
.m-name{font-size:.76rem;font-weight:600;line-height:1}
.m-sub{font-size:.6rem;color:var(--txt-muted);margin-top:1px}

/* Field */
.field-grp{margin-bottom:.9rem}
.field-lbl{font-size:.7rem;color:var(--txt-secondary);margin-bottom:4px;display:block;font-weight:500}
.field-inp{width:100%;padding:10px 13px;border-radius:9px;background:var(--fog);border:1px solid var(--glass-border);color:var(--txt-primary);font-family:'Cabinet Grotesk',sans-serif;font-size:.85rem;outline:none;transition:border-color .2s}
.field-inp:focus{border-color:rgba(232,201,125,.4)}
.field-inp::placeholder{color:var(--txt-muted)}
.field-inp.err{border-color:rgba(255,77,109,.5)}
.field-inp.ok{border-color:rgba(78,204,163,.5)}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.cn-field{font-family:'JetBrains Mono',monospace;letter-spacing:1.5px}
.field-hint{font-size:.62rem;color:var(--txt-muted);margin-top:3px;font-family:'JetBrains Mono',monospace}

/* Buttons pay */
.btn-pay{width:100%;padding:13px;border-radius:11px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.93rem;font-weight:700;cursor:pointer;transition:opacity .2s,transform .2s;margin-top:.9rem;display:flex;align-items:center;justify-content:center;gap:7px}
.btn-pay:hover:not(:disabled){opacity:.88;transform:translateY(-1px)}
.btn-pay:disabled{opacity:.4;cursor:not-allowed;transform:none}
.btn-back-sm{background:none;border:none;color:var(--txt-muted);font-size:.72rem;cursor:pointer;text-decoration:underline;width:100%;text-align:center;margin-top:.5rem;font-family:'Cabinet Grotesk',sans-serif}

/* Processing */
.proc-spinner{width:60px;height:60px;border-radius:50%;border:3px solid var(--glass-border);border-top-color:var(--gold);margin:0 auto 1.2rem;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.proc-step{display:flex;align-items:center;gap:9px;font-size:.76rem;color:var(--txt-muted);padding:5px 0;transition:color .3s}
.proc-step.active{color:var(--gold)}
.proc-step.done{color:var(--sage)}
.proc-ico{width:17px;height:17px;border-radius:50%;border:1px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:.52rem}
.proc-step.done .proc-ico{background:var(--sage);border-color:var(--sage);color:#fff}

/* Success */
.success-ring{width:78px;height:78px;border-radius:50%;background:rgba(78,204,163,.1);border:2px solid var(--sage);display:flex;align-items:center;justify-content:center;font-size:2.3rem;margin:0 auto 1.2rem;animation:popIn .5s var(--ease-spring);box-shadow:0 0 40px rgba(78,204,163,.2)}
@keyframes popIn{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
.success-title{font-family:'Clash Display',sans-serif;font-size:1.35rem;font-weight:700;color:var(--sage);margin-bottom:.4rem}
.success-sub{font-size:.82rem;color:var(--txt-muted)}
.success-ref{font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--txt-muted);margin:1rem 0;padding:7px 14px;background:var(--fog);border-radius:7px;border:1px solid var(--glass-border);word-break:break-all}
.bonus-cel{display:flex;align-items:center;gap:9px;padding:11px 13px;border-radius:9px;background:rgba(232,201,125,.08);border:1px solid rgba(232,201,125,.3);font-size:.76rem;color:var(--gold);margin-top:.8rem;animation:popIn .5s var(--ease-spring)}
.coupon-code-box{font-family:'JetBrains Mono',monospace;font-size:1.1rem;font-weight:700;color:var(--gold);background:rgba(232,201,125,.06);border:2px dashed rgba(232,201,125,.4);border-radius:8px;padding:8px 16px;text-align:center;letter-spacing:3px;margin-top:6px}
.pay-security{display:flex;align-items:center;justify-content:center;gap:1.2rem;padding:.9rem 1.8rem;border-top:1px solid var(--glass-border);background:rgba(0,0,0,.15)}
.sec-b{display:flex;align-items:center;gap:4px;font-size:.62rem;color:var(--txt-muted)}
.sec-b i{color:var(--sage)}

/* BOOK DETAIL PANEL */
#book-detail{position:fixed;inset:0;z-index:9400;display:flex;align-items:flex-end;justify-content:center;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
#book-detail.open{opacity:1;visibility:visible}
.detail-bg{position:absolute;inset:0;background:rgba(7,11,20,.82);backdrop-filter:blur(8px)}
.detail-panel{position:relative;z-index:1;width:100%;max-width:600px;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:var(--r-lg) var(--r-lg) 0 0;padding:2rem;box-shadow:0 -40px 80px rgba(0,0,0,.4);transform:translateY(100%);transition:transform .4s var(--ease-spring);max-height:88vh;overflow-y:auto}
#book-detail.open .detail-panel{transform:translateY(0)}
.detail-close{position:absolute;top:.9rem;right:.9rem;width:30px;height:30px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.82rem}
.detail-close:hover{border-color:rgba(255,95,87,.3);color:#ff5f57}
.detail-hdr{display:flex;gap:1.2rem;margin-bottom:1.5rem}
.detail-cover{width:80px;height:100px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:2.4rem;flex-shrink:0;border:1px solid var(--glass-border);background:var(--mist)}
.detail-title{font-family:'Clash Display',sans-serif;font-size:1.2rem;font-weight:700;margin-bottom:4px}
.detail-author{font-size:.82rem;color:var(--txt-secondary);margin-bottom:.6rem}
.detail-tags{display:flex;gap:5px;flex-wrap:wrap}
.detail-tag{font-family:'JetBrains Mono',monospace;font-size:.57rem;padding:2px 8px;border-radius:100px;border:1px solid var(--glass-border);color:var(--txt-muted)}
.access-info{display:flex;align-items:center;gap:8px;font-size:.75rem;padding:8px 12px;border-radius:8px;margin:.8rem 0}
.access-info.free{background:rgba(78,204,163,.08);border:1px solid rgba(78,204,163,.2);color:var(--sage)}
.access-info.paid{background:rgba(232,201,125,.06);border:1px solid rgba(232,201,125,.2);color:var(--gold)}
.detail-desc{font-size:.84rem;color:var(--txt-secondary);line-height:1.7;margin-bottom:1.4rem}
.detail-meta{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-bottom:1.4rem}
.meta-item{background:var(--fog);border-radius:9px;padding:9px 12px}
.meta-label{font-family:'JetBrains Mono',monospace;font-size:.55rem;color:var(--txt-muted);letter-spacing:.07em;text-transform:uppercase;margin-bottom:2px}
.meta-val{font-family:'Clash Display',sans-serif;font-size:.88rem;font-weight:600}
.detail-btns{display:flex;gap:.8rem;flex-wrap:wrap}
.btn-detail-read{flex:1;min-width:130px;padding:12px;border-radius:12px;border:none;background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.87rem;font-weight:700;cursor:pointer;transition:opacity .2s,transform .2s}
.btn-detail-read:hover{opacity:.88;transform:translateY(-1px)}
.btn-detail-fav{padding:12px 16px;border-radius:12px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-family:'Cabinet Grotesk',sans-serif;font-size:.87rem;cursor:pointer;transition:all .2s}
.btn-detail-fav:hover{border-color:rgba(155,89,182,.4);color:var(--plum)}

/* REVIEW MODAL */
#review-modal{position:fixed;inset:0;z-index:9750;display:flex;align-items:center;justify-content:center;padding:1.5rem;opacity:0;visibility:hidden;transition:opacity .3s,visibility .3s}
#review-modal.open{opacity:1;visibility:visible}
.review-bg{position:absolute;inset:0;background:rgba(7,11,20,.9);backdrop-filter:blur(14px)}
.review-box{position:relative;z-index:1;width:100%;max-width:440px;background:linear-gradient(145deg,var(--slate),var(--paper));border:1px solid var(--glass-border);border-radius:var(--r-lg);padding:2rem;box-shadow:0 50px 100px rgba(0,0,0,.6);transform:scale(.95);transition:transform .4s var(--ease-spring)}
#review-modal.open .review-box{transform:none}
.review-box::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--sage),var(--azure));border-radius:var(--r-lg) var(--r-lg) 0 0}
.review-icon{font-size:2.5rem;text-align:center;margin-bottom:.7rem}
.review-title{font-family:'Clash Display',sans-serif;font-size:1.25rem;font-weight:700;text-align:center;margin-bottom:.3rem}
.review-sub{font-size:.78rem;color:var(--txt-secondary);text-align:center;margin-bottom:1.4rem;line-height:1.6}
.star-picker{display:flex;justify-content:center;gap:.35rem;margin-bottom:1.2rem;font-size:1.7rem;cursor:pointer}
.star-picker span{transition:transform .15s;color:var(--txt-muted);line-height:1}
.star-picker span.lit{color:var(--gold)}
.star-picker span:hover{transform:scale(1.25)}
.review-textarea{width:100%;min-height:90px;resize:vertical;padding:10px 13px;border-radius:9px;background:var(--fog);border:1px solid var(--glass-border);color:var(--txt-primary);font-family:'Cabinet Grotesk',sans-serif;font-size:.85rem;outline:none;transition:border-color .2s;line-height:1.6}
.review-textarea:focus{border-color:rgba(78,204,163,.4)}
.review-textarea::placeholder{color:var(--txt-muted)}
.review-btns{display:flex;gap:.7rem;margin-top:1.2rem}
.btn-rev-send{flex:1;padding:11px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--sage),var(--azure));color:var(--ink);font-family:'Clash Display',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;transition:opacity .2s}
.btn-rev-send:hover{opacity:.88}
.btn-rev-skip{flex:1;padding:11px;border-radius:10px;border:1px solid var(--glass-border);background:var(--glass);color:var(--txt-secondary);font-family:'Clash Display',sans-serif;font-size:.88rem;cursor:pointer;transition:all .2s}
.btn-rev-skip:hover{border-color:rgba(255,77,109,.3);color:var(--red)}
.review-close{position:absolute;top:.8rem;right:.8rem;width:28px;height:28px;border-radius:7px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.78rem}

/* READER MODAL */
#reader-modal{position:fixed;inset:0;z-index:9600;opacity:0;visibility:hidden;transition:opacity .4s,visibility .4s;background:#0e0d0b;display:flex;flex-direction:column}
#reader-modal.open{opacity:1;visibility:visible}
.reader-hdr{height:54px;display:flex;align-items:center;justify-content:space-between;padding:0 1.8rem;border-bottom:1px solid rgba(255,255,255,.06);background:rgba(0,0,0,.4);backdrop-filter:blur(10px);flex-shrink:0}
.reader-title-wrap{display:flex;align-items:center;gap:10px;min-width:0;flex:1}
.reader-chap-tag{font-family:'JetBrains Mono',monospace;font-size:.58rem;color:var(--gold);background:rgba(232,201,125,.1);border:1px solid rgba(232,201,125,.2);padding:3px 7px;border-radius:6px;flex-shrink:0}
.reader-title{font-family:'Clash Display',sans-serif;font-size:.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.reader-controls{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.reader-btn{width:32px;height:32px;border-radius:8px;background:var(--glass);border:1px solid var(--glass-border);color:var(--txt-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.83rem;transition:all .2s}
.reader-btn:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.reader-btn-close{border-color:rgba(255,95,87,.3);color:#ff5f57}
.reader-btn-close:hover{background:rgba(255,95,87,.1)}
.reader-prog-bar{height:3px;background:rgba(255,255,255,.05);flex-shrink:0}
.reader-prog-fill{height:100%;background:linear-gradient(90deg,var(--gold),var(--ember));transition:width .5s}
.reader-hist-note{text-align:center;font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--sage);padding:4px;background:rgba(78,204,163,.06);border-bottom:1px solid rgba(78,204,163,.15);flex-shrink:0}
.reader-body{flex:1;overflow-y:auto}
.reader-inner{max-width:680px;margin:0 auto;padding:3rem 2rem 7rem}
.reader-content{font-family:'Georgia',serif;font-size:1.05rem;line-height:1.95;color:#e8e4da;transition:font-size .2s}
.reader-content h2{font-family:'Clash Display',sans-serif;font-size:1.3rem;font-weight:700;margin:2.5rem 0 1.2rem;color:#f0eeea;border-bottom:1px solid rgba(255,255,255,.06);padding-bottom:.5rem}
.reader-content p{margin-bottom:1.3rem;text-indent:1.5em}
.reader-content p:first-of-type{text-indent:0}
.reader-nav{position:sticky;bottom:0;display:flex;align-items:center;justify-content:center;gap:1rem;background:rgba(14,13,11,.95);border-top:1px solid rgba(255,255,255,.06);padding:11px 2rem;backdrop-filter:blur(20px)}
.reader-nav-btn{background:none;border:1px solid var(--glass-border);border-radius:8px;color:var(--txt-muted);cursor:pointer;padding:7px 13px;display:flex;align-items:center;gap:4px;font-size:.76rem;font-family:'Cabinet Grotesk',sans-serif;transition:all .2s}
.reader-nav-btn:hover:not(:disabled){border-color:rgba(232,201,125,.3);color:var(--gold)}
.reader-nav-btn:disabled{opacity:.3;cursor:not-allowed}
.reader-page-info{font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--txt-muted);min-width:80px;text-align:center}
#reader-modal.reader-light{background:#f5f0e8}
#reader-modal.reader-light .reader-content{color:#2c2a24}
#reader-modal.reader-light .reader-content h2{color:#1a1814;border-color:rgba(0,0,0,.07)}
#reader-modal.reader-light .reader-hdr{background:rgba(245,240,232,.92);border-color:rgba(0,0,0,.07)}
#reader-modal.reader-light .reader-nav{background:rgba(245,240,232,.96);border-color:rgba(0,0,0,.07)}

/* RESPONSIVE */
@media(max-width:768px){
  .dash-band{padding:1rem;gap:1rem;margin:0 1rem 1.2rem}
  .books-grid{grid-template-columns:repeat(auto-fill,minmax(155px,1fr))}
  .controls,.page-header,#books-container{padding-left:1rem;padding-right:1rem}
  .method-grid{grid-template-columns:1fr}
}
@media(max-width:480px){
  .books-grid{grid-template-columns:repeat(2,1fr)}
  .stats-pills{gap:.4rem}
  .stat-pill{padding:4px 9px}
}
</style>
</head>
<body>

<div class="bg-orb orb-a"></div>
<div class="bg-orb orb-b"></div>
<div class="bg-orb orb-c"></div>

<!-- ═══════════════════ HEADER ═══════════════════ -->
<header id="site-header">
  <a href="../index.php" class="nav-logo">
    <div class="logo-mark">📚</div>
    Digital <span style="color:var(--gold)">Library</span>
  </a>
  <div class="hdr-search">
    <i class="bi bi-search hdr-si"></i>
    <input type="text" id="global-search" placeholder="Rechercher titre, auteur, genre…" autocomplete="off">
  </div>
  <div class="hdr-actions">
    <?php if ($isLoggedIn): ?>
      <?php if ($isAdmin): ?>
        <span class="role-tag"><i class="bi bi-shield-fill"></i> Admin</span>
      <?php elseif ($isJournalist): ?>
        <span class="role-tag" style="background:rgba(74,158,255,.1);color:var(--azure);border-color:rgba(74,158,255,.25)"><i class="bi bi-pencil-fill"></i> Journaliste</span>
      <?php endif; ?>
      <a href="../dashboard.php" class="user-chip">
        <div class="user-av"><?= esc($avatarLetter) ?></div>
        <?= esc($username) ?>
      </a>
    <?php else: ?>
      <a href="../login.php" class="btn-ghost-sm">Connexion</a>
      <a href="../register.php" class="btn-cta-sm">S'inscrire →</a>
    <?php endif; ?>
    <button id="hamburger"><i class="bi bi-list"></i></button>
  </div>
</header>

<nav id="mobile-nav">
  <a href="../index.php">Accueil</a>
  <a href="../dashboard.php">Dashboard</a>
  <?php if ($isLoggedIn): ?>
    <a href="../dashboard.php" style="color:var(--gold)">Mon espace</a>
  <?php else: ?>
    <a href="../login.php">Connexion</a>
    <a href="../register.php" style="color:var(--gold)">S'inscrire</a>
  <?php endif; ?>
</nav>

<!-- ═══════════════════ PAGE HEADER ═══════════════════ -->
<div class="page-header">
  <div class="breadcrumb">
    <a href="../dashboard.php">Dashboard</a><span class="bc-sep">›</span>
    <span style="color:var(--txt-secondary)">Bibliothèque</span>
  </div>
  <div class="page-title-row">
    <div>
      <h1 class="page-title">Toute la <span class="hl">Bibliothèque</span></h1>
      <p style="font-size:.8rem;color:var(--txt-secondary);margin-top:4px;display:flex;align-items:center;gap:8px">
        <span class="live-badge"><span class="live-pulse"></span>LIVE</span>
        <span id="live-label"><?= fmtNum($dashStats['total']) ?>+ livres — Mise à jour automatique</span>
      </p>
    </div>
    <div class="stats-pills">
      <div class="stat-pill"><i class="bi bi-collection-fill" style="color:var(--gold)"></i><span class="spv" id="sp-total"><?= fmtNum($dashStats['total']) ?></span><span class="spl">Livres</span></div>
      <div class="stat-pill"><i class="bi bi-gift-fill" style="color:var(--sage)"></i><span class="spv" id="sp-gratuit" style="color:var(--sage)"><?= fmtNum($dashStats['gratuit']) ?></span><span class="spl">Gratuits</span></div>
      <div class="stat-pill"><i class="bi bi-gem" style="color:var(--gold)"></i><span class="spv" id="sp-premium" style="color:var(--gold)"><?= fmtNum($dashStats['premium']) ?></span><span class="spl">Premium</span></div>
      <div class="stat-pill"><i class="bi bi-people-fill" style="color:var(--azure)"></i><span class="spv" id="sp-users" style="color:var(--azure)"><?= fmtNum($dashStats['users']) ?></span><span class="spl">Utilisateurs</span></div>
    </div>
  </div>

  <?php if ($userBonusInfo['achat_count'] > 0 || $userBonusInfo['bonus_restant'] > 0): ?>
  <div class="bonus-chip" style="margin-top:.5rem;display:inline-flex">
    <i class="bi bi-gift-fill" style="color:var(--sage)"></i>
    <div>
      <div style="font-size:.72rem;font-weight:600">Bonus fidélité</div>
      <div style="font-size:.6rem;color:var(--txt-muted)"><?= (int)$userBonusInfo['achat_count'] ?>/5 achats</div>
      <?php if ($userBonusInfo['bonus_restant'] > 0): ?>
        <div style="font-size:.6rem;color:var(--sage)"><?= (int)$userBonusInfo['bonus_restant'] ?> livre(s) offert(s) !</div>
      <?php endif; ?>
    </div>
    <div class="bonus-bar"><div class="bonus-fill" style="width:<?= min(100, (int)$userBonusInfo['achat_count'] / 5 * 100) ?>%"></div></div>
  </div>
  <?php endif; ?>
</div>

<!-- ═══════════════════ DASHBOARD BAND ═══════════════════ -->
<div class="dash-band">
  <div class="dash-stat">
    <div class="dash-val" style="color:var(--gold)" id="ds-ventes"><?= fmtNum($dashStats['ventes']) ?></div>
    <div class="dash-lbl">Ventes totales</div>
  </div>
  <div class="dash-stat">
    <div class="dash-val" style="color:var(--sage)" id="ds-revenus"><?= fmtNum($dashStats['revenus']) ?></div>
    <div class="dash-lbl">Revenus mois (FCFA)</div>
  </div>
  <div class="dash-stat">
    <div class="dash-val" style="color:var(--azure)" id="ds-users"><?= fmtNum($dashStats['users']) ?></div>
    <div class="dash-lbl">Utilisateurs actifs</div>
  </div>
  <div class="dash-recent">
    <div class="dr-title"><span class="live-pulse"></span> Achats récents</div>
    <div id="dash-recent-list">
      <?php foreach ($dashStats['recents'] as $r): ?>
      <div class="dr-item">
        <span class="dr-name"><?= esc($r['titre'] ?? '—') ?></span>
        <span class="dr-amt"><?= fmtNum((int)($r['montant'] ?? 0)) ?> F</span>
      </div>
      <?php endforeach; ?>
      <?php if (empty($dashStats['recents'])): ?>
      <div class="dr-item"><span class="dr-name" style="color:var(--txt-muted)">Aucune vente récente</span></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════ CONTROLS ═══════════════════ -->
<div class="controls">
  <div class="filter-row">
    <button class="flt-btn active" data-filter="all"><i class="bi bi-grid"></i> Tous <span><?= fmtNum(count($allBooks)) ?></span></button>
    <button class="flt-btn" data-filter="free"><i class="bi bi-gift"></i> Gratuits</button>
    <button class="flt-btn" data-filter="premium"><i class="bi bi-gem"></i> Premium</button>
    <button class="flt-btn" data-filter="top"><i class="bi bi-star-fill"></i> Top notés</button>
    <button class="flt-btn" data-filter="recent"><i class="bi bi-clock"></i> Récents</button>
    <button class="flt-btn" data-filter="featured"><i class="bi bi-lightning-fill"></i> À la une</button>
    <?php if ($isLoggedIn): ?>
    <button class="flt-btn" data-filter="purchased"><i class="bi bi-check-circle"></i> Mes achats</button>
    <button class="flt-btn" data-filter="favoris"><i class="bi bi-heart-fill"></i> Favoris</button>
    <?php endif; ?>
    <button id="clear-btn">✕ Réinitialiser</button>
    <span class="results-info" id="results-info"></span>
    <div class="view-toggle">
      <button class="view-btn" id="btn-view-scroll" title="Vue catégories"><i class="bi bi-view-list"></i></button>
      <button class="view-btn active" id="btn-view-grid" title="Vue grille"><i class="bi bi-grid-3x3-gap"></i></button>
    </div>
  </div>
  <div class="ctrl-divider"></div>
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.8rem">
    <div class="cat-row">
      <button class="cat-btn active" data-cat="all">📋 Toutes</button>
      <?php foreach ($categories as $cat): ?>
      <button class="cat-btn" data-cat="<?= (int)$cat['id'] ?>"><?= esc($cat['icone'] ?? '') ?> <?= esc($cat['nom']) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="sort-strip">
      <span class="sort-lbl">Trier :</span>
      <button class="sort-btn active" data-sort="note">⭐ Note</button>
      <button class="sort-btn" data-sort="prix_asc">💰↑</button>
      <button class="sort-btn" data-sort="prix_desc">💰↓</button>
      <button class="sort-btn" data-sort="ventes">🔥 Ventes</button>
      <button class="sort-btn" data-sort="recent">🕒 Récents</button>
      <button class="sort-btn" data-sort="titre">🔤 A-Z</button>
    </div>
  </div>
</div>

<!-- ═══════════════════ BOOKS CONTAINER ═══════════════════ -->
<div id="books-container">

  <!-- VUE SCROLL -->
  <div class="scroll-view" id="scroll-view">
    <?php
    $byCategory = [];
    foreach ($allBooks as $book) { $byCategory[(int)($book['cat_id'] ?? 0)][] = $book; }
    foreach ($categories as $cat):
      $catBooks = $byCategory[(int)$cat['id']] ?? [];
      if (empty($catBooks)) continue;
      $catShow = array_slice($catBooks, 0, 30);
    ?>
    <div class="cat-section" data-cat-id="<?= (int)$cat['id'] ?>">
      <div class="cat-section-hdr">
        <div class="cat-section-title">
          <?= esc($cat['icone'] ?? '') ?> <?= esc($cat['nom']) ?>
          <span class="cat-section-count"><?= count($catBooks) ?> titres</span>
        </div>
        <a href="#" class="see-all-link" onclick="switchToGrid();filterCat('<?= (int)$cat['id'] ?>');return false">Voir tous <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="scroll-row">
        <?php foreach ($catShow as $bi => $book):
          $pc      = getPriceBadge((float)($book['note_moyenne'] ?? 0), (float)($book['prix'] ?? 0));
          $palette = $coverPalettes[$bi % count($coverPalettes)];
          $emoji   = $coverEmojis[$bi % count($coverEmojis)];
          $stars   = starsHtml((float)($book['note_moyenne'] ?? 0));
          $isPurch = in_array((int)$book['id'], array_map('intval', $userPurchases), true);
          $isFav   = in_array((int)$book['id'], array_map('intval', $userFavoris), true);
          $isFree  = canReadFree((float)($book['note_moyenne'] ?? 0), (float)($book['prix'] ?? 0), $userRole, $isPurch);
          $pctProg = isset($userProgression[(int)$book['id']]) ? (float)$userProgression[(int)$book['id']]['pourcentage'] : 0;
        ?>
        <div class="book-card scroll-card<?= $isPurch ? ' purchased' : '' ?>"
          data-id="<?= (int)$book['id'] ?>"
          data-titre="<?= esc($book['titre'] ?? '') ?>"
          data-auteur="<?= esc($book['auteur'] ?? '') ?>"
          data-prix="<?= (float)($book['prix'] ?? 0) ?>"
          data-note="<?= (float)($book['note_moyenne'] ?? 0) ?>"
          data-cat="<?= (int)($book['cat_id'] ?? 0) ?>"
          data-annee="<?= (int)($book['annee_parution'] ?? 2020) ?>"
          data-ventes="<?= (int)($book['nb_ventes'] ?? 0) ?>"
          data-featured="<?= (int)($book['is_featured'] ?? 0) ?>"
          data-emoji="<?= $emoji ?>"
          data-extrait="<?= bookExtrait($book) ?>"
          data-pages="<?= (int)($book['pages'] ?? 200) ?>"
          data-genre="<?= esc($book['genre'] ?? '') ?>"
          data-editeur="<?= esc($book['editeur'] ?? '') ?>"
          data-desc="<?= esc(mb_substr($book['description'] ?? '', 0, 200)) ?>"
          data-fav="<?= $isFav ? '1' : '0' ?>"
          data-purchased="<?= $isPurch ? '1' : '0' ?>"
          data-free="<?= $isFree ? '1' : '0' ?>"
          data-prog="<?= round($pctProg, 1) ?>">
          <div class="book-cover">
            <div class="cover-bg" style="background:linear-gradient(135deg,<?= $palette[0] ?>,<?= $palette[1] ?>)"></div>
            <div class="cover-emoji"><?= $emoji ?></div>
            <div class="cover-vignette"></div>
            <span class="price-badge <?= $pc['class'] ?>"><?= $pc['label'] ?></span>
            <?php if ($isPurch): ?><span class="tag-purchased">✓ Acheté</span><?php endif; ?>
            <?php if ($isFav): ?><span class="tag-fav">❤️</span><?php endif; ?>
            <?php if ($pctProg > 0): ?>
            <div class="prog-strip"><div class="prog-fill" style="width:<?= min(100, $pctProg) ?>%"></div></div>
            <?php endif; ?>
          </div>
          <div class="book-body">
            <div class="book-genre"><?= esc($book['genre_icone'] ?? '') ?> <?= esc($book['genre'] ?? '') ?></div>
            <div class="book-title"><?= esc($book['titre'] ?? '') ?></div>
            <div class="book-author">par <?= esc($book['auteur'] ?? '') ?></div>
            <div class="book-meta">
              <span class="book-stars"><?= $stars ?></span>
              <span class="book-pages"><?= (int)($book['pages'] ?? 200) ?>p</span>
            </div>
            <div class="book-price <?= $pc['color'] ?>"><?= fmtPrice((float)($book['prix'] ?? 0)) ?></div>
            <div class="card-btns">
              <button class="btn-read-card" onclick="handleRead(this)"><i class="bi bi-book-open"></i> Lire</button>
              <button class="btn-fav-card<?= $isFav ? ' active' : '' ?>" onclick="toggleFav(this)"><i class="bi bi-heart<?= $isFav ? '-fill' : '' ?>"></i></button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- VUE GRID -->
  <div class="grid-view active" id="grid-view">
    <div class="books-grid" id="books-grid">
      <?php foreach ($booksRender as $bi => $book):
        $pc      = getPriceBadge((float)($book['note_moyenne'] ?? 0), (float)($book['prix'] ?? 0));
        $palette = $coverPalettes[$bi % count($coverPalettes)];
        $emoji   = $coverEmojis[$bi % count($coverEmojis)];
        $stars   = starsHtml((float)($book['note_moyenne'] ?? 0));
        $isPurch = in_array((int)$book['id'], array_map('intval', $userPurchases), true);
        $isFav   = in_array((int)$book['id'], array_map('intval', $userFavoris), true);
        $isFree  = canReadFree((float)($book['note_moyenne'] ?? 0), (float)($book['prix'] ?? 0), $userRole, $isPurch);
        $isTop   = (float)($book['note_moyenne'] ?? 0) >= 4.5;
        $pctProg = isset($userProgression[(int)$book['id']]) ? (float)$userProgression[(int)$book['id']]['pourcentage'] : 0;
        $dly     = ($bi % 6) * 55;
      ?>
      <div class="book-card<?= $isPurch ? ' purchased' : '' ?>"
        style="animation-delay:<?= $dly ?>ms"
        data-id="<?= (int)$book['id'] ?>"
        data-titre="<?= esc($book['titre'] ?? '') ?>"
        data-auteur="<?= esc($book['auteur'] ?? '') ?>"
        data-prix="<?= (float)($book['prix'] ?? 0) ?>"
        data-note="<?= (float)($book['note_moyenne'] ?? 0) ?>"
        data-cat="<?= (int)($book['cat_id'] ?? 0) ?>"
        data-annee="<?= (int)($book['annee_parution'] ?? 2020) ?>"
        data-ventes="<?= (int)($book['nb_ventes'] ?? 0) ?>"
        data-featured="<?= (int)($book['is_featured'] ?? 0) ?>"
        data-emoji="<?= $emoji ?>"
        data-extrait="<?= bookExtrait($book) ?>"
        data-pages="<?= (int)($book['pages'] ?? 200) ?>"
        data-genre="<?= esc($book['genre'] ?? '') ?>"
        data-editeur="<?= esc($book['editeur'] ?? '') ?>"
        data-desc="<?= esc(mb_substr($book['description'] ?? '', 0, 200)) ?>"
        data-fav="<?= $isFav ? '1' : '0' ?>"
        data-purchased="<?= $isPurch ? '1' : '0' ?>"
        data-free="<?= $isFree ? '1' : '0' ?>"
        data-prog="<?= round($pctProg, 1) ?>">
        <div class="book-cover">
          <div class="cover-bg" style="background:linear-gradient(135deg,<?= $palette[0] ?>,<?= $palette[1] ?>)"></div>
          <div class="cover-emoji"><?= $emoji ?></div>
          <div class="cover-vignette"></div>
          <span class="price-badge <?= $pc['class'] ?>"><?= $pc['label'] ?></span>
          <?php if ($isPurch): ?><span class="tag-purchased">✓ Acheté</span><?php endif; ?>
          <?php if ($isFav): ?><span class="tag-fav">❤️</span><?php endif; ?>
          <?php if ($isTop): ?><span class="tag-top">TOP ⭐</span><?php endif; ?>
          <?php if ($pctProg > 0): ?><div class="prog-strip"><div class="prog-fill" style="width:<?= min(100,$pctProg) ?>%"></div></div><?php endif; ?>
        </div>
        <div class="card-overlay">
          <div class="ov-title"><?= esc($book['titre'] ?? '') ?></div>
          <div class="ov-author">par <?= esc($book['auteur'] ?? '') ?></div>
          <div class="ov-rating"><i class="bi bi-star-fill"></i> <?= number_format((float)($book['note_moyenne'] ?? 0), 1) ?> · <?= (int)($book['pages'] ?? 200) ?>p</div>
          <div class="ov-price"><?= fmtPrice((float)($book['prix'] ?? 0)) ?></div>
          <div class="ov-btns">
            <button class="ov-btn-read" onclick="handleRead(this.closest('.book-card'))"><i class="bi bi-book-open"></i> Lire</button>
            <button class="ov-btn-fav<?= $isFav ? ' active' : '' ?>" onclick="toggleFav(this.closest('.book-card').querySelector('.btn-fav-card'))"><i class="bi bi-heart<?= $isFav ? '-fill' : '' ?>"></i></button>
            <button class="ov-btn-info" onclick="showDetail(this.closest('.book-card'))"><i class="bi bi-info-circle"></i></button>
          </div>
        </div>
        <div class="book-body">
          <div class="book-genre"><?= esc($book['genre_icone'] ?? '') ?> <?= esc($book['genre'] ?? '') ?></div>
          <div class="book-title"><?= esc($book['titre'] ?? '') ?></div>
          <div class="book-author">par <?= esc($book['auteur'] ?? '') ?></div>
          <div class="book-meta">
            <div><span class="book-stars"><?= $stars ?></span><span class="star-val"> <?= number_format((float)($book['note_moyenne'] ?? 0), 1) ?></span></div>
            <span class="book-pages"><?= (int)($book['pages'] ?? 200) ?>p</span>
          </div>
          <div class="book-price <?= $pc['color'] ?>"><?= fmtPrice((float)($book['prix'] ?? 0)) ?></div>
          <div class="card-btns">
            <button class="btn-read-card" onclick="handleRead(this)"><i class="bi bi-book-open"></i> Lire</button>
            <button class="btn-fav-card<?= $isFav ? ' active' : '' ?>" onclick="toggleFav(this)"><i class="bi bi-heart<?= $isFav ? '-fill' : '' ?>"></i></button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div id="empty-state">
      <span class="empty-icon">🔍</span>
      <div class="empty-title">Aucun résultat</div>
      <div class="empty-sub">Modifiez vos filtres ou réinitialisez la recherche.</div>
    </div>

    <div class="load-more-wrap" id="load-more-wrap">
      <button class="btn-load-more" id="btn-load-more"><i class="bi bi-arrow-down-circle"></i> Charger plus de livres</button>
      <div style="font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--txt-muted);margin-top:.5rem" id="load-progress"></div>
    </div>
  </div>
</div>

<!-- ═══════ TOAST ═══════ -->
<div id="toast" role="alert">
  <span class="t-icon" id="t-icon">ℹ️</span>
  <div><div class="t-title" id="t-title">—</div><div class="t-msg" id="t-msg"></div></div>
</div>

<!-- ═══════ AUTH GATE ═══════ -->
<div id="auth-gate" role="dialog">
  <div class="gate-bg" id="gate-bg"></div>
  <div class="gate-box">
    <button class="gate-close" id="gate-close">✕</button>
    <div class="gate-icon">🔒</div>
    <div class="gate-eyebrow">Accès restreint</div>
    <h2 class="gate-title">Connexion requise</h2>
    <p class="gate-desc">Créez un compte ou connectez-vous pour accéder au catalogue complet.</p>
    <div class="gate-btns">
      <a href="../login.php" class="btn-gate-solid"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
      <a href="../register.php" class="btn-gate-outline"><i class="bi bi-person-plus"></i> S'inscrire</a>
    </div>
  </div>
</div>

<!-- ═══════ BOOK DETAIL PANEL ═══════ -->
<div id="book-detail" role="dialog">
  <div class="detail-bg" id="detail-bg"></div>
  <div class="detail-panel">
    <button class="detail-close" id="detail-close">✕</button>
    <div class="detail-hdr">
      <div class="detail-cover"><span id="detail-emoji">📚</span></div>
      <div>
        <div class="detail-title" id="detail-title">—</div>
        <div class="detail-author" id="detail-author">—</div>
        <div class="detail-tags" id="detail-tags"></div>
      </div>
    </div>
    <div id="detail-access"></div>
    <div class="detail-desc" id="detail-desc">—</div>
    <div class="detail-meta">
      <div class="meta-item"><div class="meta-label">Prix</div><div class="meta-val" id="d-price" style="color:var(--gold)">—</div></div>
      <div class="meta-item"><div class="meta-label">Note</div><div class="meta-val" id="d-note" style="color:var(--gold)">—</div></div>
      <div class="meta-item"><div class="meta-label">Pages</div><div class="meta-val" id="d-pages">—</div></div>
      <div class="meta-item"><div class="meta-label">Éditeur</div><div class="meta-val" id="d-edit" style="font-size:.76rem">—</div></div>
    </div>
    <div class="detail-btns">
      <button class="btn-detail-read" id="btn-detail-read"><i class="bi bi-book-open"></i> Lire maintenant</button>
      <button class="btn-detail-fav" id="btn-detail-fav"><i class="bi bi-heart"></i> Favoris</button>
    </div>
  </div>
</div>

<!-- ═══════ PAYMENT MODAL ═══════ -->
<div id="pay-modal" role="dialog" aria-modal="true">
  <div class="pay-bg" id="pay-bg"></div>
  <div class="pay-box">
    <div class="pay-shimmer"></div>
    <button class="pay-close" id="pay-close">✕</button>
    <div class="pay-header">
      <div class="pay-book-row">
        <div class="pay-thumb" id="pay-thumb">📚</div>
        <div>
          <div class="pay-book-title" id="pay-modal-title">—</div>
          <div class="pay-book-author" id="pay-author">—</div>
        </div>
      </div>
      <div class="pay-amount-row">
        <span class="pal">Montant :</span>
        <span class="pav" id="pay-amount">0</span>
        <span class="pac">FCFA</span>
      </div>
    </div>
    <div class="pay-body">

      <!-- SECTION ADMIN -->
      <div id="sec-admin" style="display:none">
        <div style="text-align:center;padding:1.2rem 0">
          <div style="font-size:2.5rem;margin-bottom:.7rem">🛡️</div>
          <div style="font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;color:var(--gold);margin-bottom:.5rem">Accès Administrateur</div>
          <p style="font-size:.8rem;color:var(--txt-secondary);line-height:1.65;margin-bottom:1.2rem">En tant qu'administrateur, vous accédez gratuitement à tous les livres. Confirmez votre email pour continuer.</p>
          <div style="font-size:.7rem;color:var(--txt-muted);font-family:'JetBrains Mono',monospace;margin-bottom:.8rem">Format : admin.[nom]@adminsopecam.com</div>
        </div>
        <div class="field-grp">
          <label class="field-lbl">Email administrateur</label>
          <input type="email" class="field-inp" id="admin-email" placeholder="admin.dupont@adminsopecam.com" autocomplete="email">
        </div>
        <button class="btn-pay" id="btn-admin-verify" type="button"><i class="bi bi-shield-check"></i> Confirmer et accéder</button>
      </div>

      <!-- SECTION JOURNALISTE -->
      <div id="sec-journalist" style="display:none;text-align:center;padding:1.5rem 0">
        <div style="font-size:2rem;margin-bottom:.8rem">✍️</div>
        <div style="font-family:'Clash Display',sans-serif;font-size:1.1rem;font-weight:700;color:var(--azure);margin-bottom:.5rem">Accès Journaliste</div>
        <p style="font-size:.8rem;color:var(--txt-secondary);line-height:1.65;margin-bottom:1.2rem">Ce livre est accessible gratuitement pour les journalistes (note &lt; 4.5★ ou livre gratuit).</p>
        <button class="btn-pay" id="btn-journalist-go" type="button" style="background:linear-gradient(135deg,var(--azure),var(--plum))"><i class="bi bi-book-open"></i> Accéder au livre</button>
      </div>

      <!-- SECTION PAIEMENT STANDARD -->
      <div id="sec-pay">
        <div class="pay-steps">
          <div class="step-dot active" id="sd1"></div>
          <div class="step-line" id="sl1"></div>
          <div class="step-dot" id="sd2"></div>
          <div class="step-line" id="sl2"></div>
          <div class="step-dot" id="sd3"></div>
          <span class="step-lbl" id="step-lbl">Méthode</span>
        </div>

        <!-- Étape 1 : Méthode -->
        <div id="step1">
          <div style="font-size:.78rem;color:var(--txt-secondary);margin-bottom:.9rem;font-weight:600">Choisissez votre méthode de paiement</div>
          <div class="method-grid">
            <button class="method-btn" data-method="orange_money" type="button">
              <span class="m-icon">📱</span>
              <div><div class="m-name">Orange Money</div><div class="m-sub">Instantané</div></div>
            </button>
            <button class="method-btn" data-method="mobile_money" type="button">
              <span class="m-icon">📲</span>
              <div><div class="m-name">Mobile Money</div><div class="m-sub">MTN, Moov…</div></div>
            </button>
            <button class="method-btn" data-method="visa" type="button">
              <span class="m-icon">💳</span>
              <div><div class="m-name">Visa / Mastercard</div><div class="m-sub">Crédit/débit</div></div>
            </button>
            <button class="method-btn" data-method="coupon" type="button">
              <span class="m-icon">🎁</span>
              <div><div class="m-name">Code coupon</div><div class="m-sub">Livre offert</div></div>
            </button>
          </div>
          <button class="btn-pay" id="btn-s1-next" disabled type="button">Continuer <i class="bi bi-arrow-right"></i></button>
        </div>

        <!-- Étape 2 : Formulaire -->
        <div id="step2" style="display:none">

          <!-- Mobile Money -->
          <div id="form-mobile" style="display:none">
            <div class="field-grp">
              <label class="field-lbl">Numéro de téléphone <span style="color:var(--red)">*</span></label>
              <input type="tel" class="field-inp" id="f-phone" placeholder="6XXXXXXXX" maxlength="12" autocomplete="tel">
              <div class="field-hint">Format : 6XXXXXXXX (9 chiffres — Orange ou MTN)</div>
            </div>
            <div class="field-grp">
              <label class="field-lbl">Nom complet</label>
              <input type="text" class="field-inp" id="f-name" placeholder="Jean Dupont" autocomplete="name">
            </div>
          </div>

          <!-- Carte bancaire -->
          <div id="form-card" style="display:none">
            <div class="field-grp">
              <label class="field-lbl">Numéro de carte <span style="color:var(--red)">*</span></label>
              <input type="text" class="field-inp cn-field" id="f-card" placeholder="1234 5678 9012 3456" maxlength="19" autocomplete="cc-number">
            </div>
            <div class="field-grp">
              <label class="field-lbl">Titulaire <span style="color:var(--red)">*</span></label>
              <input type="text" class="field-inp" id="f-cname" placeholder="NOM PRÉNOM" autocomplete="cc-name">
            </div>
            <div class="field-row">
              <div class="field-grp">
                <label class="field-lbl">Expiration <span style="color:var(--red)">*</span></label>
                <input type="text" class="field-inp" id="f-exp" placeholder="MM/AA" maxlength="5" autocomplete="cc-exp">
              </div>
              <div class="field-grp">
                <label class="field-lbl">CVV <span style="color:var(--red)">*</span></label>
                <input type="password" class="field-inp" id="f-cvv" placeholder="•••" maxlength="4" autocomplete="cc-csc">
              </div>
            </div>
          </div>

          <!-- Coupon -->
          <div id="form-coupon" style="display:none">
            <div class="field-grp">
              <label class="field-lbl">Code coupon</label>
              <input type="text" class="field-inp" id="f-coupon" placeholder="BONUS12345678" maxlength="20" style="text-transform:uppercase;letter-spacing:2px">
            </div>
            <div id="coupon-status" style="font-size:.73rem;margin-top:.3rem"></div>
            <button type="button" id="btn-check-coupon" style="background:none;border:1px solid var(--glass-border);color:var(--azure);font-size:.72rem;padding:6px 12px;border-radius:7px;cursor:pointer;font-family:'Cabinet Grotesk',sans-serif;margin-top:.3rem">Vérifier le code</button>
          </div>

          <div id="pay-error-msg" style="display:none;padding:9px 13px;background:rgba(255,77,109,.08);border:1px solid rgba(255,77,109,.3);border-radius:9px;font-size:.76rem;color:#ff8fa0;margin-top:.8rem"></div>

          <button class="btn-pay" id="btn-pay-now" type="button"><i class="bi bi-lock-fill"></i> Payer maintenant</button>
          <button class="btn-back-sm" id="btn-s2-back" type="button">← Changer de méthode</button>
        </div>
      </div>

      <!-- PROCESSING -->
      <div id="sec-processing" style="display:none;text-align:center;padding:2rem 0">
        <div class="proc-spinner"></div>
        <div style="font-family:'Clash Display',sans-serif;font-size:1.05rem;font-weight:700;margin-bottom:.4rem">Traitement en cours…</div>
        <div style="font-size:.76rem;color:var(--txt-muted);margin-bottom:1.2rem">Ne fermez pas cette fenêtre</div>
        <div style="text-align:left">
          <div class="proc-step" id="ps1"><div class="proc-ico"><i class="bi bi-arrow-right"></i></div><span>Vérification des informations</span></div>
          <div class="proc-step" id="ps2"><div class="proc-ico"><i class="bi bi-arrow-right"></i></div><span>Validation du paiement</span></div>
          <div class="proc-step" id="ps3"><div class="proc-ico"><i class="bi bi-arrow-right"></i></div><span>Débit du compte</span></div>
          <div class="proc-step" id="ps4"><div class="proc-ico"><i class="bi bi-arrow-right"></i></div><span>Activation de l'accès</span></div>
        </div>
      </div>

      <!-- SUCCESS -->
      <div id="sec-success" style="display:none;text-align:center;padding:2rem 0">
        <div class="success-ring">✅</div>
        <div class="success-title">Paiement validé !</div>
        <div class="success-sub">Accès au livre activé avec succès.</div>
        <div class="success-ref" id="success-ref">REF: —</div>
        <div id="bonus-cel" style="display:none" class="bonus-cel">
          <i class="bi bi-gift-fill"></i>
          <div><strong>🎉 Félicitations !</strong> Vous avez cumulé 5 achats.<br><div class="coupon-code-box" id="new-coupon-code">—</div></div>
        </div>
        <button class="btn-pay" id="btn-open-reader" type="button" style="margin-top:1.2rem"><i class="bi bi-book-open"></i> Lire maintenant</button>
      </div>

    </div>
    <div class="pay-security">
      <span class="sec-b"><i class="bi bi-shield-check"></i> SSL 256-bit</span>
      <span class="sec-b"><i class="bi bi-lock-fill"></i> Chiffré</span>
      <span class="sec-b"><i class="bi bi-patch-check"></i> Côté serveur</span>
    </div>
  </div>
</div>

<!-- ═══════ REVIEW MODAL ═══════ -->
<div id="review-modal" role="dialog">
  <div class="review-bg"></div>
  <div class="review-box">
    <button class="review-close" id="review-close">✕</button>
    <div class="review-icon">⭐</div>
    <div class="review-title">Votre avis sur ce livre</div>
    <p class="review-sub">Vous avez terminé ce livre ! Souhaitez-vous laisser un commentaire ?<br><em>(Facultatif — vous pouvez ignorer.)</em></p>
    <div class="star-picker" id="star-picker">
      <span data-val="1">★</span><span data-val="2">★</span><span data-val="3">★</span><span data-val="4">★</span><span data-val="5">★</span>
    </div>
    <textarea class="review-textarea" id="review-text" placeholder="Partagez votre expérience de lecture… (facultatif)" rows="3"></textarea>
    <div class="review-btns">
      <button class="btn-rev-send" id="btn-rev-send"><i class="bi bi-send"></i> Envoyer mon avis</button>
      <button class="btn-rev-skip" id="btn-rev-skip">Ignorer</button>
    </div>
  </div>
</div>

<!-- ═══════ READER MODAL ═══════ -->
<div id="reader-modal" role="dialog" aria-modal="true">
  <div id="reader-hist-note" class="reader-hist-note" style="display:none">
    📖 Reprise de lecture — page <span id="hist-page">1</span>
  </div>
  <div class="reader-hdr">
    <div class="reader-title-wrap">
      <span class="reader-chap-tag" id="reader-chap-tag">Ch. 1</span>
      <span class="reader-title" id="reader-title">—</span>
    </div>
    <div class="reader-controls">
      <button class="reader-btn" id="btn-theme"><i class="bi bi-moon" id="theme-ico"></i></button>
      <button class="reader-btn" id="btn-font-up"><i class="bi bi-zoom-in"></i></button>
      <button class="reader-btn" id="btn-font-dn"><i class="bi bi-zoom-out"></i></button>
      <button class="reader-btn" id="btn-bm"><i class="bi bi-bookmark" id="bm-ico"></i></button>
      <button class="reader-btn reader-btn-close" id="btn-reader-close"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div class="reader-prog-bar"><div class="reader-prog-fill" id="reader-prog" style="width:0%"></div></div>
  <div class="reader-body" id="reader-body">
    <div class="reader-inner"><div class="reader-content" id="reader-content">Chargement…</div></div>
  </div>
  <div class="reader-nav">
    <button class="reader-nav-btn" id="btn-prev-p"><i class="bi bi-chevron-left"></i> Précédente</button>
    <span class="reader-page-info" id="reader-page-info">Page 1 / 1</span>
    <button class="reader-nav-btn" id="btn-next-p">Suivante <i class="bi bi-chevron-right"></i></button>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════════ -->
<script>
'use strict';

/* ── Config PHP → JS ───────────────────────────────────────────── */
const IS_LOGGED     = <?= json_encode($isLoggedIn) ?>;
const IS_ADMIN      = <?= json_encode($isAdmin) ?>;
const IS_JOURNALIST = <?= json_encode($isJournalist) ?>;
const USER_ROLE     = <?= json_encode($userRole) ?>;
const USERNAME      = <?= json_encode($username) ?>;
const TOTAL_BOOKS   = <?= json_encode(count($allBooks)) ?>;
const RENDERED_COUNT = <?= json_encode($maxRender) ?>;
const AJAX_URL      = window.location.href.split('?')[0];

const USER_PURCHASES = new Set(<?= json_encode(array_values(array_map('intval', $userPurchases))) ?>);
const USER_FAVORIS   = new Set(<?= json_encode(array_values(array_map('intval', $userFavoris))) ?>);

const ALL_BOOKS_JS = <?= json_encode(array_map(function($b) {
    return [
        'id'    => (int)$b['id'],
        'titre' => (string)($b['titre'] ?? ''),
        'auteur'=> (string)($b['auteur'] ?? ''),
        'prix'  => (float)($b['prix'] ?? 0),
        'note'  => (float)($b['note_moyenne'] ?? 0),
        'cat_id'=> (int)($b['cat_id'] ?? 0),
        'annee' => (int)($b['annee_parution'] ?? 2020),
        'ventes'=> (int)($b['nb_ventes'] ?? 0),
        'pages' => (int)($b['pages'] ?? 200),
        'feat'  => (int)($b['is_featured'] ?? 0),
        'genre' => (string)($b['genre'] ?? ''),
        'edit'  => (string)($b['editeur'] ?? ''),
        'desc'  => mb_substr((string)($b['description'] ?? ''), 0, 200),
    ];
}, $allBooks)) ?>;

/* ── État global ───────────────────────────────────────────────── */
let currentBook    = null;
let selectedMethod = null;
let readerFont     = 17;
let readerLight    = false;
let readerPage     = 1;
let readerTotal    = 1;
let readerPages    = [];
let reviewNote     = 5;
let toastTimer     = null;
let activeFilter   = 'all';
let activeCat      = 'all';
let activeSort     = 'note';
let currentView    = 'grid';
let loadedCount    = RENDERED_COUNT;
const PAGE_SIZE    = 48;

/* ── HELPERS ────────────────────────────────────────────────────── */
function escH(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function ajax(data) {
    return fetch(AJAX_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams(data).toString()
    }).then(r => r.json());
}

/* ── TOAST ──────────────────────────────────────────────────────── */
function toast(title, msg = '', type = 'default', dur = 4000) {
    const el = document.getElementById('toast');
    const icons = {default:'ℹ️', success:'✅', error:'❌', warn:'⚠️'};
    el.className = '';
    if (type !== 'default') el.classList.add('t-' + type);
    document.getElementById('t-icon').textContent = icons[type] || 'ℹ️';
    document.getElementById('t-title').textContent = title;
    document.getElementById('t-msg').textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), dur);
}

/* ── HEADER ─────────────────────────────────────────────────────── */
const hdr = document.getElementById('site-header');
window.addEventListener('scroll', () => hdr.classList.toggle('scrolled', scrollY > 60));
const ham = document.getElementById('hamburger');
const mnav = document.getElementById('mobile-nav');
ham.addEventListener('click', () => {
    const o = mnav.classList.toggle('open');
    ham.innerHTML = o ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
});

/* ══════════════════════════════════════════════════════════════════
   FILTRES / RECHERCHE / TRI
══════════════════════════════════════════════════════════════════ */
const searchInput = document.getElementById('global-search');
const emptyState  = document.getElementById('empty-state');
const resultsInfo = document.getElementById('results-info');
const clearBtn    = document.getElementById('clear-btn');

function allGridCards() {
    return Array.from(document.querySelectorAll('#books-grid .book-card'));
}

function applyFilters() {
    if (currentView !== 'grid') return;
    const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const cards = allGridCards();
    let visible = 0;

    cards.forEach(c => {
        const titre   = (c.dataset.titre  || '').toLowerCase();
        const auteur  = (c.dataset.auteur || '').toLowerCase();
        const genre   = (c.dataset.genre  || '').toLowerCase();
        const edit    = (c.dataset.editeur|| '').toLowerCase();
        const prix    = parseFloat(c.dataset.prix)  || 0;
        const note    = parseFloat(c.dataset.note)  || 0;
        const catId   = parseInt(c.dataset.cat)     || 0;
        const annee   = parseInt(c.dataset.annee)   || 0;
        const feat    = parseInt(c.dataset.featured) || 0;
        const isPurch = c.dataset.purchased === '1';
        const isFav   = c.dataset.fav === '1';

        const matchQ = !q || titre.includes(q) || auteur.includes(q) || genre.includes(q) || edit.includes(q);
        let matchF = true;
        switch (activeFilter) {
            case 'free':      matchF = (prix === 0 || note <= 2.0); break;
            case 'premium':   matchF = (prix > 3500); break;
            case 'top':       matchF = (note >= 4.5); break;
            case 'recent':    matchF = (annee >= 2022); break;
            case 'featured':  matchF = (feat === 1); break;
            case 'purchased': matchF = isPurch; break;
            case 'favoris':   matchF = isFav; break;
        }
        const matchCat = activeCat === 'all' || catId === parseInt(activeCat);
        const show = matchQ && matchF && matchCat;
        c.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    if (resultsInfo) resultsInfo.textContent = visible + ' résultat' + (visible !== 1 ? 's' : '');
    if (emptyState)  emptyState.classList.toggle('show', visible === 0);
    if (clearBtn)    clearBtn.style.display = (q || activeFilter !== 'all' || activeCat !== 'all') ? 'inline' : 'none';
    sortCards();
}

function sortCards() {
    const grid = document.getElementById('books-grid');
    if (!grid) return;
    const cards = Array.from(grid.querySelectorAll('.book-card')).filter(c => c.style.display !== 'none');
    cards.sort((a, b) => {
        const pa = parseFloat(a.dataset.prix)  || 0, pb = parseFloat(b.dataset.prix)  || 0;
        const na = parseFloat(a.dataset.note)  || 0, nb = parseFloat(b.dataset.note)  || 0;
        const ta = (a.dataset.titre || '').toLowerCase(), tb = (b.dataset.titre || '').toLowerCase();
        const ya = parseInt(a.dataset.annee)   || 0, yb = parseInt(b.dataset.annee)   || 0;
        const va = parseInt(a.dataset.ventes)  || 0, vb = parseInt(b.dataset.ventes)  || 0;
        switch (activeSort) {
            case 'prix_asc':  return pa - pb;
            case 'prix_desc': return pb - pa;
            case 'titre':     return ta.localeCompare(tb, 'fr');
            case 'recent':    return yb - ya;
            case 'ventes':    return vb - va;
            default:          return nb - na;
        }
    });
    cards.forEach(c => grid.appendChild(c));
}

document.querySelectorAll('.flt-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.flt-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeFilter = btn.dataset.filter;
        if (currentView !== 'grid') switchToGrid();
        applyFilters();
    });
});

document.querySelectorAll('.cat-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeCat = btn.dataset.cat;
        if (currentView !== 'grid') switchToGrid();
        applyFilters();
    });
});

document.querySelectorAll('.sort-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeSort = btn.dataset.sort;
        sortCards();
    });
});

if (searchInput) {
    let sTimer = null;
    searchInput.addEventListener('input', () => { clearTimeout(sTimer); sTimer = setTimeout(applyFilters, 250); });
    searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.value = ''; applyFilters(); } });
}

clearBtn?.addEventListener('click', () => {
    if (searchInput) searchInput.value = '';
    activeFilter = 'all'; activeCat = 'all'; activeSort = 'note';
    document.querySelectorAll('.flt-btn').forEach(b => b.classList.toggle('active', b.dataset.filter === 'all'));
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.toggle('active', b.dataset.cat === 'all'));
    document.querySelectorAll('.sort-btn').forEach(b => b.classList.toggle('active', b.dataset.sort === 'note'));
    applyFilters();
    toast('Filtres réinitialisés', '', 'default', 1500);
});

function filterCat(catId) {
    activeCat = String(catId);
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.toggle('active', b.dataset.cat === activeCat));
    if (currentView !== 'grid') switchToGrid();
    applyFilters();
}
setTimeout(applyFilters, 150);

/* ── VIEW TOGGLE ────────────────────────────────────────────────── */
function switchToGrid() {
    currentView = 'grid';
    document.getElementById('scroll-view')?.classList.remove('active');
    document.getElementById('grid-view')?.classList.add('active');
    document.getElementById('btn-view-grid')?.classList.add('active');
    document.getElementById('btn-view-scroll')?.classList.remove('active');
    applyFilters();
}
function switchToScroll() {
    currentView = 'scroll';
    document.getElementById('scroll-view')?.classList.add('active');
    document.getElementById('grid-view')?.classList.remove('active');
    document.getElementById('btn-view-scroll')?.classList.add('active');
    document.getElementById('btn-view-grid')?.classList.remove('active');
}
document.getElementById('btn-view-scroll')?.addEventListener('click', switchToScroll);
document.getElementById('btn-view-grid')?.addEventListener('click', switchToGrid);

/* ── LOAD MORE ──────────────────────────────────────────────────── */
const PALETTES = [
    ['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],
    ['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],
    ['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],['#0d3c2a','#1a7a5a'],
    ['#2a0d1a','#6b1a3a'],['#0d1a3c','#1a3a7a'],['#3c0d2a','#7a1a5a'],
    ['#1c0d3c','#3a1a7a'],['#0d2c1a','#1a6b3a'],['#2c1a0d','#6a3a1a'],
];
const EMOJIS = ['📚','📘','📗','📙','📕','📓','📔','📒','🔮','💡','🌊','🏔️','⚡','🌌','🧠','🌿','⚙️','📜','🎭','🔭','🦋','🌍','🎯','🌙'];

function updateLoadMore() {
    const w = document.getElementById('load-more-wrap');
    const p = document.getElementById('load-progress');
    if (TOTAL_BOOKS > loadedCount) {
        w?.classList.add('show');
        if (p) p.textContent = loadedCount + ' / ' + TOTAL_BOOKS + ' livres chargés';
    } else {
        w?.classList.remove('show');
        if (p) p.textContent = 'Tous les ' + TOTAL_BOOKS + ' livres sont affichés';
    }
}

document.getElementById('btn-load-more')?.addEventListener('click', () => {
    const toLoad = Math.min(PAGE_SIZE, TOTAL_BOOKS - loadedCount);
    if (toLoad <= 0) { toast('Fin du catalogue', 'Tous les livres sont affichés.', 'warn'); return; }
    const grid = document.getElementById('books-grid');
    if (!grid) return;
    const slice = ALL_BOOKS_JS.slice(loadedCount, loadedCount + toLoad);
    slice.forEach((b, i) => {
        const idx   = loadedCount + i;
        const pal   = PALETTES[idx % PALETTES.length];
        const emoji = EMOJIS[idx % EMOJIS.length];
        const note  = b.note || 0, prix = b.prix || 0;
        let bCls = 'badge-std', bLbl = 'STANDARD', pCol = 'std';
        if (prix === 0 || note <= 2.0) { bCls = 'badge-free'; bLbl = 'GRATUIT'; pCol = 'free'; }
        else if (prix > 3500)          { bCls = 'badge-premium'; bLbl = 'PREMIUM'; pCol = 'gold'; }
        const stars   = '★'.repeat(Math.round(note)) + '☆'.repeat(5 - Math.round(note));
        const isPurch = USER_PURCHASES.has(b.id);
        const isFav   = USER_FAVORIS.has(b.id);
        const isFreeAccess = IS_ADMIN || isPurch || (IS_JOURNALIST && (note < 4.5 || prix === 0));
        const prixFmt = prix === 0 ? 'Gratuit' : prix.toLocaleString('fr-FR') + ' FCFA';

        const card = document.createElement('div');
        card.className = 'book-card' + (isPurch ? ' purchased' : '');
        card.style.animationDelay = (i % 6 * 55) + 'ms';
        Object.assign(card.dataset, {
            id: b.id, titre: b.titre, auteur: b.auteur, prix, note,
            cat: b.cat_id, annee: b.annee, ventes: b.ventes, feat: b.feat,
            emoji, extrait: '', pages: b.pages, genre: b.genre,
            editeur: b.edit, desc: b.desc,
            fav: isFav ? '1' : '0', purchased: isPurch ? '1' : '0',
            free: isFreeAccess ? '1' : '0', prog: '0',
        });
        card.innerHTML = `
          <div class="book-cover">
            <div class="cover-bg" style="background:linear-gradient(135deg,${pal[0]},${pal[1]})"></div>
            <div class="cover-emoji">${emoji}</div>
            <div class="cover-vignette"></div>
            <span class="price-badge ${bCls}">${bLbl}</span>
            ${isPurch ? '<span class="tag-purchased">✓ Acheté</span>' : ''}
            ${isFav   ? '<span class="tag-fav">❤️</span>' : ''}
          </div>
          <div class="card-overlay">
            <div class="ov-title">${escH(b.titre)}</div>
            <div class="ov-author">par ${escH(b.auteur)}</div>
            <div class="ov-rating"><i class="bi bi-star-fill"></i> ${note.toFixed(1)} · ${b.pages}p</div>
            <div class="ov-price">${prixFmt}</div>
            <div class="ov-btns">
              <button class="ov-btn-read" onclick="handleRead(this.closest('.book-card'))"><i class="bi bi-book-open"></i> Lire</button>
              <button class="ov-btn-fav${isFav ? ' active' : ''}" onclick="toggleFav(this.closest('.book-card').querySelector('.btn-fav-card'))"><i class="bi bi-heart${isFav ? '-fill' : ''}"></i></button>
              <button class="ov-btn-info" onclick="showDetail(this.closest('.book-card'))"><i class="bi bi-info-circle"></i></button>
            </div>
          </div>
          <div class="book-body">
            <div class="book-genre">${escH(b.genre)}</div>
            <div class="book-title">${escH(b.titre)}</div>
            <div class="book-author">par ${escH(b.auteur)}</div>
            <div class="book-meta"><div><span class="book-stars">${stars}</span><span class="star-val"> ${note.toFixed(1)}</span></div><span class="book-pages">${b.pages}p</span></div>
            <div class="book-price ${pCol}">${prixFmt}</div>
            <div class="card-btns">
              <button class="btn-read-card" onclick="handleRead(this)"><i class="bi bi-book-open"></i> Lire</button>
              <button class="btn-fav-card${isFav ? ' active' : ''}" onclick="toggleFav(this)"><i class="bi bi-heart${isFav ? '-fill' : ''}"></i></button>
            </div>
          </div>`;
        grid.appendChild(card);
    });
    loadedCount += toLoad;
    updateLoadMore();
    applyFilters();
    toast('Chargé', toLoad + ' livres supplémentaires.', 'success', 2000);
});
updateLoadMore();

/* ════════════════════════════════════════════════════════════════
   BOOK DATA
════════════════════════════════════════════════════════════════ */
function getBookData(card) {
    if (!card) return null;
    let ext = '';
    try { const b64 = card.dataset.extrait || ''; if (b64) ext = atob(b64); } catch(e) {}
    return {
        id:        card.dataset.id || '0',
        title:     card.dataset.titre || '—',
        author:    card.dataset.auteur || '—',
        price:     parseFloat(card.dataset.prix) || 0,
        note:      parseFloat(card.dataset.note) || 0,
        emoji:     card.dataset.emoji || '📚',
        pages:     parseInt(card.dataset.pages) || 200,
        genre:     card.dataset.genre || '—',
        editeur:   card.dataset.editeur || '—',
        desc:      card.dataset.desc || '',
        annee:     card.dataset.annee || '—',
        purchased: card.dataset.purchased === '1',
        fav:       card.dataset.fav === '1',
        isFree:    card.dataset.free === '1',
        prog:      parseFloat(card.dataset.prog) || 0,
        extrait:   ext,
    };
}

/* ════════════════════════════════════════════════════════════════
   DISPATCH READ
════════════════════════════════════════════════════════════════ */
function handleRead(el) {
    const card = el?.closest('.book-card');
    if (!card) return;
    currentBook = getBookData(card);
    dispatchRead();
}
function dispatchRead() {
    if (!currentBook) return;
    if (!IS_LOGGED)  { openAuthGate(); return; }
    if (IS_ADMIN)    { openPayModal('admin'); return; }
    if (currentBook.isFree) {
        if (IS_JOURNALIST && !currentBook.purchased) { openPayModal('journalist'); return; }
        openReader(); return;
    }
    if (currentBook.purchased) { openReader(); return; }
    openPayModal('pay');
}

/* ════════════════════════════════════════════════════════════════
   FAVORIS
════════════════════════════════════════════════════════════════ */
function toggleFav(btnEl) {
    if (!IS_LOGGED) { toast('Connexion requise', '', 'warn'); return; }
    const card = btnEl?.closest('.book-card');
    if (!card) return;
    const bookId = parseInt(card.dataset.id) || 0;
    const wasFav = card.dataset.fav === '1';
    const nowFav = !wasFav;
    card.dataset.fav = nowFav ? '1' : '0';

    if (btnEl) {
        btnEl.className = 'btn-fav-card' + (nowFav ? ' active' : '');
        btnEl.innerHTML = `<i class="bi bi-heart${nowFav ? '-fill' : ''}"></i>`;
    }
    const ft = card.querySelector('.tag-fav');
    if (nowFav && !ft) {
        const s = document.createElement('span');
        s.className = 'tag-fav'; s.textContent = '❤️';
        card.querySelector('.book-cover')?.appendChild(s);
    } else if (!nowFav && ft) ft.remove();

    const ovBtn = card.querySelector('.ov-btn-fav');
    if (ovBtn) {
        ovBtn.className = 'ov-btn-fav' + (nowFav ? ' active' : '');
        ovBtn.innerHTML = `<i class="bi bi-heart${nowFav ? '-fill' : ''}"></i>`;
    }
    if (nowFav) USER_FAVORIS.add(bookId); else USER_FAVORIS.delete(bookId);
    toast(nowFav ? 'Ajouté aux favoris ❤️' : 'Retiré des favoris', card.dataset.titre || '', nowFav ? 'success' : 'default', 2000);
    ajax({ action: 'toggle_fav', livre_id: bookId, mode: nowFav ? 'add' : 'remove' }).catch(() => {});
}

/* ════════════════════════════════════════════════════════════════
   DETAIL PANEL
════════════════════════════════════════════════════════════════ */
function showDetail(card) {
    if (!card) return;
    const b = getBookData(card);
    if (!b) return;
    currentBook = b;
    document.getElementById('detail-emoji').textContent  = b.emoji;
    document.getElementById('detail-title').textContent  = b.title;
    document.getElementById('detail-author').textContent = 'par ' + b.author;
    const tags = document.getElementById('detail-tags');
    if (tags) tags.innerHTML = `<span class="detail-tag">${escH(b.genre)}</span><span class="detail-tag">${b.annee}</span>`;
    document.getElementById('detail-desc').textContent  = b.desc || 'Aucune description.';
    document.getElementById('d-price').textContent = b.price === 0 ? 'Gratuit' : b.price.toLocaleString('fr-FR') + ' FCFA';
    document.getElementById('d-note').textContent  = '★ ' + b.note.toFixed(1) + ' / 5';
    document.getElementById('d-pages').textContent = b.pages + ' pages';
    document.getElementById('d-edit').textContent  = b.editeur;
    const aEl = document.getElementById('detail-access');
    if (aEl) {
        let msg = '', cls = 'paid', ico = 'bi-lock-fill';
        if (IS_ADMIN)          { msg = 'Accès administrateur — gratuit';        cls = 'free'; ico = 'bi-shield-check'; }
        else if (b.purchased)  { msg = 'Livre acheté — accès illimité';         cls = 'free'; ico = 'bi-check-circle-fill'; }
        else if (b.isFree && IS_JOURNALIST) { msg = 'Accès journaliste (note < 4.5★)'; cls = 'free'; ico = 'bi-pencil-fill'; }
        else                   { msg = 'Achat requis — ' + b.price.toLocaleString('fr-FR') + ' FCFA'; }
        aEl.innerHTML = `<div class="access-info ${cls}"><i class="bi ${ico}"></i> ${escH(msg)}</div>`;
    }
    document.getElementById('book-detail').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDetail() {
    document.getElementById('book-detail').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('detail-close')?.addEventListener('click', closeDetail);
document.getElementById('detail-bg')?.addEventListener('click', closeDetail);
document.getElementById('btn-detail-read')?.addEventListener('click', () => { closeDetail(); setTimeout(dispatchRead, 200); });
document.getElementById('btn-detail-fav')?.addEventListener('click', () => {
    if (!currentBook) return;
    const c = document.querySelector(`[data-id="${currentBook.id}"] .btn-fav-card`);
    if (c) toggleFav(c);
});

/* ════════════════════════════════════════════════════════════════
   AUTH GATE
════════════════════════════════════════════════════════════════ */
function openAuthGate() {
    document.getElementById('auth-gate').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeAuthGate() {
    document.getElementById('auth-gate').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('gate-close')?.addEventListener('click', closeAuthGate);
document.getElementById('gate-bg')?.addEventListener('click', closeAuthGate);

/* ════════════════════════════════════════════════════════════════
   PAYMENT MODAL
════════════════════════════════════════════════════════════════ */
function openPayModal(mode) {
    if (!currentBook) return;
    document.getElementById('pay-modal-title').textContent = currentBook.title;
    document.getElementById('pay-author').textContent      = 'par ' + currentBook.author;
    document.getElementById('pay-thumb').textContent       = currentBook.emoji;
    document.getElementById('pay-amount').textContent      = currentBook.price === 0 ? '0' : currentBook.price.toLocaleString('fr-FR');
    resetPayModal();

    const secAdmin  = document.getElementById('sec-admin');
    const secJourno = document.getElementById('sec-journalist');
    const secPay    = document.getElementById('sec-pay');
    [secAdmin, secJourno, secPay].forEach(el => { if (el) el.style.display = 'none'; });

    if (mode === 'admin')      { if (secAdmin)  secAdmin.style.display  = 'block'; }
    else if (mode === 'journalist') { if (secJourno) secJourno.style.display = 'block'; }
    else { if (secPay) secPay.style.display = 'block'; }

    document.getElementById('pay-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closePayModal() {
    document.getElementById('pay-modal').classList.remove('open');
    document.body.style.overflow = '';
    resetPayModal();
}

function resetPayModal() {
    selectedMethod = null;
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    if (step1) step1.style.display = 'block';
    ['step2','sec-processing','sec-success'].forEach(id => {
        const e = document.getElementById(id); if (e) e.style.display = 'none';
    });
    document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('selected'));
    const bN = document.getElementById('btn-s1-next'); if (bN) bN.disabled = true;
    ['f-phone','f-name','f-card','f-cname','f-exp','f-cvv','admin-email','f-coupon'].forEach(id => {
        const e = document.getElementById(id);
        if (e) { e.value = ''; e.className = e.className.replace(/\s*(err|ok)/g, ''); }
    });
    ['form-mobile','form-card','form-coupon'].forEach(id => {
        const e = document.getElementById(id); if (e) e.style.display = 'none';
    });
    ['ps1','ps2','ps3','ps4'].forEach(id => {
        const e = document.getElementById(id); if (e) e.className = 'proc-step';
    });
    ['sd1','sd2','sd3'].forEach((id, i) => {
        const e = document.getElementById(id);
        if (e) e.className = 'step-dot' + (i === 0 ? ' active' : '');
    });
    ['sl1','sl2'].forEach(id => {
        const e = document.getElementById(id); if (e) e.className = 'step-line';
    });
    const sl = document.getElementById('step-lbl'); if (sl) sl.textContent = 'Méthode';
    const bc = document.getElementById('bonus-cel'); if (bc) bc.style.display = 'none';
    const cs = document.getElementById('coupon-status'); if (cs) cs.textContent = '';
    const pe = document.getElementById('pay-error-msg'); if (pe) { pe.style.display = 'none'; pe.textContent = ''; }
}

// ── Sélection de méthode ──────────────────────────────────────────
document.querySelectorAll('.method-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedMethod = btn.dataset.method;
        const bN = document.getElementById('btn-s1-next');
        if (bN) bN.disabled = false;
    });
});

// ── Step 1 → 2 ───────────────────────────────────────────────────
document.getElementById('btn-s1-next')?.addEventListener('click', () => {
    if (!selectedMethod) return;
    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').style.display = 'block';
    document.getElementById('sd1').className = 'step-dot done';
    document.getElementById('sl1').className = 'step-line done';
    document.getElementById('sd2').className = 'step-dot active';
    document.getElementById('step-lbl').textContent = 'Informations';

    const isMob  = ['orange_money','mobile_money'].includes(selectedMethod);
    const isCard = ['visa','mastercard'].includes(selectedMethod);
    document.getElementById('form-mobile').style.display  = isMob    ? 'block' : 'none';
    document.getElementById('form-card').style.display    = isCard   ? 'block' : 'none';
    document.getElementById('form-coupon').style.display  = selectedMethod === 'coupon' ? 'block' : 'none';
});

// ── Retour ───────────────────────────────────────────────────────
document.getElementById('btn-s2-back')?.addEventListener('click', () => {
    document.getElementById('step1').style.display = 'block';
    document.getElementById('step2').style.display = 'none';
    document.getElementById('sd1').className = 'step-dot active';
    document.getElementById('sl1').className = 'step-line';
    document.getElementById('sd2').className = 'step-dot';
    document.getElementById('step-lbl').textContent = 'Méthode';
});

// ── Vérification coupon ──────────────────────────────────────────
document.getElementById('btn-check-coupon')?.addEventListener('click', () => {
    const code = (document.getElementById('f-coupon')?.value || '').trim().toUpperCase();
    const cs   = document.getElementById('coupon-status');
    if (!code) { if (cs) cs.innerHTML = '<span style="color:var(--red)">Entrez un code.</span>'; return; }
    ajax({ action: 'check_coupon', code }).then(data => {
        if (cs) cs.innerHTML = data.ok
            ? `<span style="color:var(--sage)">${escH(data.msg)}</span>`
            : `<span style="color:var(--red)">${escH(data.msg)}</span>`;
    }).catch(() => { if (cs) cs.innerHTML = '<span style="color:var(--red)">Erreur de vérification.</span>'; });
});

// ── Formatage carte & coupon ─────────────────────────────────────
document.getElementById('f-card')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g, '').slice(0, 16);
    this.value = v.match(/.{1,4}/g)?.join(' ') || v;
});
document.getElementById('f-exp')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 2) v = v.slice(0, 2) + '/' + v.slice(2, 4);
    this.value = v;
});
document.getElementById('f-coupon')?.addEventListener('input', function() {
    this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});
document.getElementById('f-phone')?.addEventListener('input', function() {
    this.value = this.value.replace(/[^\d\s\+\-]/g, '');
});

// ── Validation frontend ──────────────────────────────────────────
function validateFormFrontend() {
    const isMob  = ['orange_money','mobile_money'].includes(selectedMethod);
    const isCard = ['visa','mastercard'].includes(selectedMethod);
    const showErr = (msg) => {
        const pe = document.getElementById('pay-error-msg');
        if (pe) { pe.textContent = msg; pe.style.display = 'block'; }
        return false;
    };
    if (isMob) {
        const ph = (document.getElementById('f-phone')?.value || '').replace(/[\s\-\+]/g, '');
        if (!ph.match(/^[26][0-9]{8}$/)) return showErr('Format de paiement invalide. Veuillez vérifier vos informations.');
        document.getElementById('f-phone')?.classList.replace('err', 'ok');
    } else if (isCard) {
        const cn  = (document.getElementById('f-card')?.value || '').replace(/\s/g, '');
        const exp = document.getElementById('f-exp')?.value || '';
        const cvv = document.getElementById('f-cvv')?.value || '';
        if (cn.length < 16) return showErr('Numéro de carte invalide (16 chiffres requis).');
        if (!exp.match(/^\d{2}\/\d{2}$/)) return showErr("Date d'expiration invalide (MM/AA).");
        if (cvv.length < 3) return showErr('CVV invalide (3-4 chiffres).');
    } else if (selectedMethod === 'coupon') {
        const code = document.getElementById('f-coupon')?.value.trim() || '';
        if (code.length < 4) return showErr('Code coupon invalide.');
    }
    return true;
}

// ── Payer ─────────────────────────────────────────────────────────
document.getElementById('btn-pay-now')?.addEventListener('click', () => {
    const pe = document.getElementById('pay-error-msg');
    if (pe) pe.style.display = 'none';
    if (!validateFormFrontend()) return;

    document.getElementById('sd2').className = 'step-dot done';
    document.getElementById('sl2').className = 'step-line done';
    document.getElementById('sd3').className = 'step-dot active';
    document.getElementById('step2').style.display = 'none';
    document.getElementById('sec-processing').style.display = 'block';

    const steps  = ['ps1','ps2','ps3','ps4'];
    const delays = [0, 700, 1500, 2500];
    steps.forEach((id, i) => {
        setTimeout(() => {
            if (i > 0) { const p = document.getElementById(steps[i-1]); if (p) p.className = 'proc-step done'; }
            const c = document.getElementById(id); if (c) c.className = 'proc-step active';
        }, delays[i]);
    });

    const payload = {
        action:   'purchase',
        livre_id: currentBook?.id || 0,
        methode:  selectedMethod || 'orange_money',
        phone:    document.getElementById('f-phone')?.value || '',
        coupon:   document.getElementById('f-coupon')?.value || '',
    };

    setTimeout(() => {
        document.getElementById(steps[steps.length - 1]).className = 'proc-step done';
        ajax(payload).then(data => {
            if (data.ok) {
                showPaySuccess(data);
            } else {
                document.getElementById('sec-processing').style.display = 'none';
                document.getElementById('step2').style.display = 'block';
                document.getElementById('sd2').className = 'step-dot active';
                document.getElementById('sl2').className = 'step-line';
                document.getElementById('sd3').className = 'step-dot';
                const pe2 = document.getElementById('pay-error-msg');
                if (pe2) { pe2.textContent = data.msg || 'Erreur de paiement.'; pe2.style.display = 'block'; }
                toast('Paiement refusé', data.msg || '', 'error', 5000);
            }
        }).catch(() => {
            document.getElementById('sec-processing').style.display = 'none';
            document.getElementById('step2').style.display = 'block';
            const pe2 = document.getElementById('pay-error-msg');
            if (pe2) { pe2.textContent = 'Erreur réseau. Vérifiez votre connexion.'; pe2.style.display = 'block'; }
            toast('Erreur réseau', '', 'error', 4000);
        });
    }, delays[delays.length - 1] + 500);
});

function showPaySuccess(data) {
    document.getElementById('sec-processing').style.display = 'none';
    document.getElementById('sec-success').style.display    = 'block';
    document.getElementById('sd3').className = 'step-dot done';
    document.getElementById('success-ref').textContent = 'REF: ' + (data.reference || '—');

    // Code promo fidélité
    if (data.new_coupon) {
        const bc = document.getElementById('bonus-cel');
        const cc = document.getElementById('new-coupon-code');
        if (bc) bc.style.display = 'flex';
        if (cc) cc.textContent = data.new_coupon;
        setTimeout(() => toast('🎁 Coupon fidélité !', 'Code : ' + data.new_coupon, 'success', 8000), 600);
    }

    // Mettre à jour la carte du livre
    if (currentBook) {
        USER_PURCHASES.add(parseInt(currentBook.id));
        const card = document.querySelector(`[data-id="${currentBook.id}"]`);
        if (card) {
            card.dataset.purchased = '1'; card.dataset.free = '1';
            card.classList.add('purchased');
            if (!card.querySelector('.tag-purchased')) {
                const s = document.createElement('span');
                s.className = 'tag-purchased'; s.textContent = '✓ Acheté';
                card.querySelector('.book-cover')?.appendChild(s);
            }
            const rb = card.querySelector('.btn-read-card');
            if (rb) {
                rb.style.borderColor = 'rgba(78,204,163,.3)';
                rb.style.background  = 'rgba(78,204,163,.06)';
                rb.style.color       = 'var(--sage)';
            }
        }
    }
    toast('Paiement validé ! ✅', 'Accès au livre activé.', 'success', 5000);
    refreshDashboard();
}

document.getElementById('btn-open-reader')?.addEventListener('click', () => { closePayModal(); setTimeout(openReader, 300); });

// ── Admin verify ─────────────────────────────────────────────────
document.getElementById('btn-admin-verify')?.addEventListener('click', () => {
    const emailEl = document.getElementById('admin-email');
    const email   = emailEl?.value.trim() || '';
    const pattern = /^admin\.[a-zA-Z][a-zA-Z0-9.]*@adminsopecam\.com$/;
    if (!pattern.test(email)) {
        emailEl?.classList.add('err');
        toast('Email invalide', 'Format : admin.[nom]@adminsopecam.com', 'error');
        return;
    }
    emailEl?.classList.replace('err', 'ok');
    toast('Accès admin accordé', '', 'success', 2000);
    setTimeout(() => { closePayModal(); openReader(); }, 1000);
});

// ── Journaliste ──────────────────────────────────────────────────
document.getElementById('btn-journalist-go')?.addEventListener('click', () => { closePayModal(); setTimeout(openReader, 200); });

// ── Fermeture modal ──────────────────────────────────────────────
document.getElementById('pay-close')?.addEventListener('click', closePayModal);
document.getElementById('pay-bg')?.addEventListener('click', closePayModal);

/* ════════════════════════════════════════════════════════════════
   LECTEUR INTÉGRÉ
════════════════════════════════════════════════════════════════ */
function generateFallback(titre, genre) {
    return [
        `CHAPITRE 1 — L'Éveil\n\n«${titre}» nous plonge dans un univers fascinant dès les premières lignes. L'auteur pose des bases narratives solides.\n\nLe lecteur est saisi par la richesse de l'univers dépeint, relevant du domaine de ${genre}.`,
        `CHAPITRE 2 — La Découverte\n\nLes thèmes initiaux se complexifient. Les couches de signification insoupçonnées se révèlent une à une.\n\nL'auteur maîtrise l'art de maintenir l'intérêt du lecteur tout en construisant un édifice intellectuel cohérent.`,
        `CHAPITRE 3 — Le Tournant\n\nUn événement majeur fait basculer le récit. Les certitudes s'effondrent, les perspectives se renouvellent.\n\nC'est ici que l'œuvre révèle sa véritable ambition.`,
        `CHAPITRE 4 — Les Révélations\n\nLes vérités cachées émergent. Ce que le lecteur croyait comprendre était surface.\n\nL'auteur démontre une maîtrise narrative remarquable.`,
        `CHAPITRE 5 — Épilogue\n\nLa dernière page tournée, le livre refermé, le lecteur reste silencieux.\n\n«${titre}» restera longtemps dans les mémoires comme une œuvre marquante.`,
    ].join('||||PAGE||||');
}

function buildPages(raw) {
    const parts = (raw || '').split('||||PAGE||||').map(p => p.trim()).filter(Boolean);
    if (parts.length >= 3) return parts;
    return generateFallback(currentBook?.title || '—', currentBook?.genre || '—').split('||||PAGE||||');
}

function openReader() {
    if (!currentBook) return;
    const modal = document.getElementById('reader-modal');
    if (!modal) return;
    document.getElementById('reader-title').textContent = currentBook.title;
    const raw = currentBook.extrait || generateFallback(currentBook.title, currentBook.genre);
    readerPages = buildPages(raw);
    readerTotal = readerPages.length;
    readerPage  = 1;

    const histNote  = document.getElementById('reader-hist-note');
    const savedPage = parseInt(localStorage.getItem('dls_p_' + currentBook.id) || '0');
    if (savedPage > 1 && savedPage <= readerTotal && currentBook.prog > 0) {
        readerPage = savedPage;
        const hp = document.getElementById('hist-page'); if (hp) hp.textContent = savedPage;
        if (histNote) histNote.style.display = 'block';
    } else {
        if (histNote) histNote.style.display = 'none';
    }

    renderPage(false);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    toast('Lecteur ouvert', readerTotal + ' chapitres · Bonne lecture !', 'success', 3000);
    saveProgression();
}

function renderPage(animate, dir = 'next') {
    const cEl = document.getElementById('reader-content');
    if (!cEl) return;
    const raw = readerPages[readerPage - 1] || 'Contenu non disponible.';
    let html = raw
        .replace(/^(CHAPITRE \d+[^\n]*)/gm, '<h2>$1</h2>')
        .replace(/^(ÉPILOGUE[^\n]*)/gm, '<h2>$1</h2>')
        .replace(/\n\n+/g, '</p><p>').replace(/\n/g, '<br>');
    html = '<p>' + html + '</p>';
    html = html.replace(/<p><h2>/g,'<h2>').replace(/<\/h2><\/p>/g,'</h2>').replace(/<p><\/p>/g,'');

    document.getElementById('reader-page-info').textContent = `Page ${readerPage} / ${readerTotal}`;
    document.getElementById('reader-prog').style.width      = ((readerPage / readerTotal) * 100).toFixed(1) + '%';
    document.getElementById('reader-chap-tag').textContent  = 'Ch. ' + readerPage;
    const bp = document.getElementById('btn-prev-p');
    const bn = document.getElementById('btn-next-p');
    if (bp) bp.disabled = readerPage === 1;
    if (bn) bn.disabled = readerPage === readerTotal;

    if (!animate) {
        cEl.innerHTML = html; cEl.style.fontSize = readerFont + 'px';
        const b = document.getElementById('reader-body'); if (b) b.scrollTop = 0;
        return;
    }
    cEl.style.cssText += ';opacity:0;transform:translateX(' + (dir === 'next' ? '-20' : '20') + 'px);transition:opacity .15s,transform .15s';
    setTimeout(() => {
        cEl.innerHTML = html; cEl.style.fontSize = readerFont + 'px';
        cEl.style.transition = 'none';
        cEl.style.transform  = 'translateX(' + (dir === 'next' ? '20' : '-20') + 'px)';
        const b = document.getElementById('reader-body'); if (b) b.scrollTop = 0;
        requestAnimationFrame(() => {
            cEl.style.transition = 'opacity .3s,transform .3s';
            cEl.style.opacity    = '1';
            cEl.style.transform  = 'translateX(0)';
        });
    }, 150);
}

function saveProgression() {
    if (!currentBook) return;
    try { localStorage.setItem('dls_p_' + currentBook.id, readerPage); } catch(e) {}
    ajax({ action: 'save_progression', livre_id: currentBook.id, page: readerPage, total: readerTotal }).catch(() => {});
}

function onReaderFinished() {
    if (!IS_LOGGED || !currentBook) return;
    setTimeout(openReviewModal, 600);
}

function closeReader() {
    saveProgression();
    document.getElementById('reader-modal').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('btn-reader-close')?.addEventListener('click', closeReader);
document.getElementById('btn-prev-p')?.addEventListener('click', () => {
    if (readerPage > 1) { readerPage--; renderPage(true, 'prev'); saveProgression(); }
    else toast('Début du livre', '', 'warn', 1500);
});
document.getElementById('btn-next-p')?.addEventListener('click', () => {
    if (readerPage < readerTotal) { readerPage++; renderPage(true, 'next'); saveProgression(); }
    else {
        toast('🎉 Fin du livre !', 'Vous avez terminé ce livre.', 'success', 3000);
        onReaderFinished();
    }
});
document.getElementById('btn-theme')?.addEventListener('click', () => {
    readerLight = !readerLight;
    document.getElementById('reader-modal').classList.toggle('reader-light', readerLight);
    const ico = document.getElementById('theme-ico');
    if (ico) ico.className = readerLight ? 'bi bi-sun-fill' : 'bi bi-moon';
    toast('Thème', readerLight ? 'Clair' : 'Sombre', 'default', 1000);
});
document.getElementById('btn-font-up')?.addEventListener('click', () => {
    readerFont = Math.min(24, readerFont + 2);
    const c = document.getElementById('reader-content'); if (c) c.style.fontSize = readerFont + 'px';
});
document.getElementById('btn-font-dn')?.addEventListener('click', () => {
    readerFont = Math.max(12, readerFont - 2);
    const c = document.getElementById('reader-content'); if (c) c.style.fontSize = readerFont + 'px';
});
document.getElementById('btn-bm')?.addEventListener('click', () => {
    const ico = document.getElementById('bm-ico');
    if (ico) { ico.className = 'bi bi-bookmark-fill'; ico.style.color = 'var(--gold)'; }
    setTimeout(() => { const ico2 = document.getElementById('bm-ico'); if (ico2) { ico2.className = 'bi bi-bookmark'; ico2.style.color = ''; } }, 2000);
    toast('Marque-page', 'Page ' + readerPage + ' sauvegardée.', 'success', 2000);
});

/* ════════════════════════════════════════════════════════════════
   REVIEW MODAL
════════════════════════════════════════════════════════════════ */
function openReviewModal() {
    reviewNote = 5; updateStarPicker(5);
    document.getElementById('review-text').value = '';
    document.getElementById('review-modal').classList.add('open');
}
function closeReviewModal() {
    document.getElementById('review-modal').classList.remove('open');
}
function updateStarPicker(val) {
    document.querySelectorAll('#star-picker span').forEach((s, i) => s.classList.toggle('lit', i < val));
}
document.querySelectorAll('#star-picker span').forEach((s, i) => {
    s.addEventListener('click',      () => { reviewNote = i + 1; updateStarPicker(reviewNote); });
    s.addEventListener('mouseenter', () => updateStarPicker(i + 1));
    s.addEventListener('mouseleave', () => updateStarPicker(reviewNote));
});
updateStarPicker(5);

document.getElementById('btn-rev-skip')?.addEventListener('click', closeReviewModal);
document.getElementById('review-close')?.addEventListener('click', closeReviewModal);
document.getElementById('btn-rev-send')?.addEventListener('click', () => {
    if (!currentBook) { closeReviewModal(); return; }
    const commentaire = document.getElementById('review-text')?.value.trim() || '';
    ajax({ action: 'save_review', livre_id: currentBook.id, note: reviewNote, commentaire })
        .then(data => toast(data.ok ? 'Merci !' : 'Erreur', data.msg || '', data.ok ? 'success' : 'error', 3000))
        .catch(() => toast('Erreur réseau', '', 'error', 2500));
    closeReviewModal();
});

/* ════════════════════════════════════════════════════════════════
   DASHBOARD TEMPS RÉEL (polling 8s)
════════════════════════════════════════════════════════════════ */
function animCount(id, newVal) {
    const el = document.getElementById(id); if (!el) return;
    const old = parseInt((el.textContent || '').replace(/[\s,]/g, '')) || 0;
    if (Math.abs(newVal - old) < 2) { el.textContent = newVal.toLocaleString('fr-FR'); return; }
    const steps = 20, inc = (newVal - old) / steps;
    let cur = old;
    const t = setInterval(() => {
        cur += inc;
        el.textContent = Math.round(cur).toLocaleString('fr-FR');
        if (Math.abs(cur - newVal) < Math.abs(inc)) { el.textContent = newVal.toLocaleString('fr-FR'); clearInterval(t); }
    }, 30);
}

function refreshDashboard() {
    ajax({ action: 'dashboard_stats' }).then(data => {
        if (data.total_livres) animCount('sp-total', data.total_livres);
        if (data.gratuit)      animCount('sp-gratuit', data.gratuit);
        if (data.premium)      animCount('sp-premium', data.premium);
        if (data.users)        { animCount('sp-users', data.users); animCount('ds-users', data.users); }
        if (data.ventes)       animCount('ds-ventes', data.ventes);
        if (data.revenus_mois) animCount('ds-revenus', data.revenus_mois);
        if (Array.isArray(data.recents) && data.recents.length) {
            const el = document.getElementById('dash-recent-list');
            if (el) el.innerHTML = data.recents.map(r =>
                `<div class="dr-item"><span class="dr-name">${escH(r.titre || '—')}</span><span class="dr-amt">${parseInt(r.montant || 0).toLocaleString('fr-FR')} F</span></div>`
            ).join('');
        }
        const ll = document.getElementById('live-label');
        if (ll && data.total_livres) ll.textContent = data.total_livres.toLocaleString('fr-FR') + '+ livres disponibles — Mis à jour';
    }).catch(() => {});
}
setInterval(refreshDashboard, 8000);
setTimeout(refreshDashboard, 2500);

/* ── KEYBOARD ────────────────────────────────────────────────────── */
document.addEventListener('keydown', e => {
    const ro  = document.getElementById('reader-modal').classList.contains('open');
    const po  = document.getElementById('pay-modal').classList.contains('open');
    const go  = document.getElementById('auth-gate').classList.contains('open');
    const dop = document.getElementById('book-detail').classList.contains('open');
    const rv  = document.getElementById('review-modal').classList.contains('open');
    if (e.key === 'Escape') {
        if (ro)  closeReader();
        else if (po)  closePayModal();
        else if (go)  closeAuthGate();
        else if (dop) closeDetail();
        else if (rv)  closeReviewModal();
        return;
    }
    if (ro) {
        if (['ArrowRight','ArrowDown','PageDown'].includes(e.key)) {
            e.preventDefault();
            if (readerPage < readerTotal) { readerPage++; renderPage(true, 'next'); saveProgression(); }
            else onReaderFinished();
        }
        if (['ArrowLeft','ArrowUp','PageUp'].includes(e.key)) {
            e.preventDefault();
            if (readerPage > 1) { readerPage--; renderPage(true, 'prev'); saveProgression(); }
        }
    }
});

/* ── TOAST D'ACCUEIL ─────────────────────────────────────────────── */
setTimeout(() => {
    if (IS_LOGGED) {
        toast(`Bonjour ${USERNAME} 👋`, TOTAL_BOOKS.toLocaleString('fr-FR') + ' livres disponibles.', 'success', 5000);
    } else {
        toast('Bienvenue 📚', 'Connectez-vous pour accéder au catalogue.', 'default', 5000);
    }
}, 900);
</script>
</body>
</html>