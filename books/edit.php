<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — books/edit.php  v1.0                     ║
 * ║  Édition livre · Statut auto par prix · PDO sécurisé        ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── Session ───────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Connexion PDO ─────────────────────────────────────────────
foreach ([
    dirname(__DIR__) . '/includes/config.php',
    dirname(__DIR__) . '/config/config.php',
    dirname(__DIR__) . '/config/database.php',
    __DIR__ . '/../includes/config.php',
] as $cfgPath) {
    if (file_exists($cfgPath) && !defined('DB_HOST_LOADED')) {
        require_once $cfgPath;
        define('DB_HOST_LOADED', true);
        break;
    }
}

if (!isset($pdo) || $pdo === null) {
    $h = defined('DB_HOST') ? DB_HOST : 'localhost';
    $n = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $u = defined('DB_USER') ? DB_USER : 'root';
    $p = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$h};dbname={$n};charset=utf8mb4",
            $u, $p,
            [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES   => false]
        );
    } catch (PDOException $e) {
        error_log('[DLS edit.php] PDO: ' . $e->getMessage());
        $pdo = null;
    }
}

// ── Auth guard ────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$currentUserId   = (int)$_SESSION['user_id'];
$currentUserRole = $_SESSION['user_role'] ?? 'lecteur';

// Seuls admin et journaliste peuvent éditer
if (!in_array($currentUserRole, ['admin', 'journaliste'], true)) {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Validation ID ─────────────────────────────────────────────
$livreId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($livreId === false || $livreId === null || $livreId < 1) {
    header('Location: ../dashboard.php?error=invalid_id');
    exit;
}

if (!$pdo) {
    die('<p style="color:red;font-family:sans-serif;padding:2rem">Base de données inaccessible.</p>');
}

// ═══════════════════════════════════════════════════════════════
// LOGIQUE MÉTIER — Calcul automatique access_type selon prix
// RÈGLE PRINCIPALE : jamais modifiable manuellement
// ═══════════════════════════════════════════════════════════════
function getAccessTypeByPrice(float $prix): string {
    if ($prix <= 0) {
        return 'gratuit';
    }
    if ($prix <= 3000) {
        return 'standard';
    }
    return 'premium';
}

// ── Récupérer catégories ──────────────────────────────────────
$categories = [];
try {
    $categories = $pdo->query(
        "SELECT id, nom, icone FROM categories ORDER BY nom ASC"
    )->fetchAll();
} catch (Exception $e) {
    error_log('[DLS edit] categories: ' . $e->getMessage());
}

// ── Récupérer le livre ────────────────────────────────────────
$livre = null;
try {
    $stmt = $pdo->prepare(
        "SELECT l.*, c.nom AS categorie_nom
         FROM livres l
         LEFT JOIN categories c ON c.id = l.categorie_id
         WHERE l.id = ?
         LIMIT 1"
    );
    $stmt->execute([$livreId]);
    $livre = $stmt->fetch();
} catch (Exception $e) {
    error_log('[DLS edit] fetch livre: ' . $e->getMessage());
}

if (!$livre) {
    header('Location: ../dashboard.php?error=book_not_found');
    exit;
}

// Vérifier que le journaliste ne modifie que ses propres livres
if ($currentUserRole === 'journaliste' && (int)($livre['ajoute_par'] ?? 0) !== $currentUserId) {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

// ── Traitement POST ───────────────────────────────────────────
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validation CSRF
    $tokenPost = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $tokenPost)) {
        $errors[] = 'Token de sécurité invalide. Rechargez la page.';
    }

    if (empty($errors)) {

        // ── Récupérer & nettoyer les champs ──────────────────
        $titre       = trim($_POST['titre']       ?? '');
        $auteur      = trim($_POST['auteur']      ?? '');
        $description = trim($_POST['description'] ?? '');
        $prixRaw     = trim($_POST['prix']        ?? '');
        $pages       = trim($_POST['pages']       ?? '');
        $isbn        = trim($_POST['isbn']        ?? '');
        $couverture  = trim($_POST['couverture']  ?? '');
        $fichierPdf  = trim($_POST['fichier_pdf'] ?? '');
        $categorieId = trim($_POST['categorie_id'] ?? '');
        $statut      = trim($_POST['statut']      ?? 'disponible');
        $editeur     = trim($_POST['editeur']     ?? '');
        $langue      = trim($_POST['langue']      ?? 'Français');
        $annee       = trim($_POST['annee_parution'] ?? '');

        // ── Validation titre ──────────────────────────────────
        if ($titre === '') {
            $errors[] = 'Le titre est obligatoire.';
        } elseif (mb_strlen($titre) > 255) {
            $errors[] = 'Le titre ne peut pas dépasser 255 caractères.';
        }

        // ── Validation auteur ─────────────────────────────────
        if ($auteur === '') {
            $errors[] = 'L\'auteur est obligatoire.';
        } elseif (mb_strlen($auteur) > 150) {
            $errors[] = 'Le nom de l\'auteur ne peut pas dépasser 150 caractères.';
        }

        // ── VALIDATION STRICTE DU PRIX ────────────────────────
        // Accepter : entier ou décimal, point ou virgule
        $prixNormalized = str_replace(',', '.', $prixRaw);

        if ($prixRaw !== '' && !preg_match('/^\d+(\.\d{1,2})?$/', $prixNormalized)) {
            $errors[] = 'Le prix doit être un nombre valide (ex: 2500 ou 2500.00). Aucun texte, aucun caractère spécial.';
        } else {
            $prix = $prixRaw === '' ? 0.0 : (float)$prixNormalized;
            if ($prix < 0) {
                $errors[] = 'Le prix ne peut pas être négatif.';
            }
        }

        // ── ⚠️ CALCUL AUTOMATIQUE access_type côté backend ────
        // Toute valeur POST access_type est IGNORÉE intentionnellement
        if (empty($errors) || !isset($prix)) {
            $prix = isset($prix) ? $prix : 0.0;
        }
        $accessType = getAccessTypeByPrice($prix ?? 0.0);

        // ── Validation pages ──────────────────────────────────
        $pagesInt = null;
        if ($pages !== '') {
            if (!ctype_digit($pages) || (int)$pages < 1 || (int)$pages > 99999) {
                $errors[] = 'Le nombre de pages doit être un entier entre 1 et 99 999.';
            } else {
                $pagesInt = (int)$pages;
            }
        }

        // ── Validation catégorie ──────────────────────────────
        $categorieIdInt = null;
        if ($categorieId !== '' && $categorieId !== '0') {
            if (!ctype_digit($categorieId)) {
                $errors[] = 'Catégorie invalide.';
            } else {
                $categorieIdInt = (int)$categorieId;
                // Vérifier existence
                $catCheck = $pdo->prepare("SELECT id FROM categories WHERE id = ? LIMIT 1");
                $catCheck->execute([$categorieIdInt]);
                if (!$catCheck->fetch()) {
                    $errors[] = 'Catégorie sélectionnée introuvable.';
                    $categorieIdInt = null;
                }
            }
        }

        // ── Validation statut ─────────────────────────────────
        if (!in_array($statut, ['disponible', 'rupture', 'archive'], true)) {
            $statut = 'disponible';
        }

        // ── Validation ISBN ───────────────────────────────────
        if ($isbn !== '' && mb_strlen($isbn) > 20) {
            $isbn = mb_substr($isbn, 0, 20);
        }

        // ── Validation URL couverture ─────────────────────────
        if ($couverture !== '' && mb_strlen($couverture) > 500) {
            $errors[] = 'L\'URL de la couverture est trop longue (max 500 caractères).';
        }

        // ── Validation fichier PDF ────────────────────────────
        if ($fichierPdf !== '' && mb_strlen($fichierPdf) > 500) {
            $errors[] = 'Le chemin du fichier PDF est trop long (max 500 caractères).';
        }

        // ── Validation année ──────────────────────────────────
        $anneeInt = null;
        if ($annee !== '') {
            $anneeInt = (int)$annee;
            if ($anneeInt < -3000 || $anneeInt > (int)date('Y') + 5) {
                $errors[] = 'Année de parution invalide.';
                $anneeInt = null;
            }
        }

        // ── Enregistrement si aucune erreur ──────────────────
        if (empty($errors)) {
            try {
                $updateStmt = $pdo->prepare(
                    "UPDATE livres SET
                        titre          = :titre,
                        auteur         = :auteur,
                        description    = :description,
                        prix           = :prix,
                        access_type    = :access_type,
                        pages          = :pages,
                        isbn           = :isbn,
                        couverture     = :couverture,
                        fichier_pdf    = :fichier_pdf,
                        categorie_id   = :categorie_id,
                        statut         = :statut,
                        editeur        = :editeur,
                        langue         = :langue,
                        annee_parution = :annee_parution,
                        updated_at     = NOW()
                     WHERE id = :id
                     LIMIT 1"
                );

                $updateStmt->execute([
                    ':titre'          => $titre,
                    ':auteur'         => $auteur,
                    ':description'    => $description !== '' ? $description : null,
                    ':prix'           => $prix,
                    ':access_type'    => $accessType,   // ← calculé automatiquement
                    ':pages'          => $pagesInt,
                    ':isbn'           => $isbn !== '' ? $isbn : null,
                    ':couverture'     => $couverture !== '' ? $couverture : null,
                    ':fichier_pdf'    => $fichierPdf !== '' ? $fichierPdf : null,
                    ':categorie_id'   => $categorieIdInt,
                    ':statut'         => $statut,
                    ':editeur'        => $editeur !== '' ? $editeur : null,
                    ':langue'         => $langue !== '' ? $langue : 'Français',
                    ':annee_parution' => $anneeInt,
                    ':id'             => $livreId,
                ]);

                // Recharger le livre depuis la BD
                $stmt2 = $pdo->prepare(
                    "SELECT l.*, c.nom AS categorie_nom
                     FROM livres l
                     LEFT JOIN categories c ON c.id = l.categorie_id
                     WHERE l.id = ? LIMIT 1"
                );
                $stmt2->execute([$livreId]);
                $livre = $stmt2->fetch();

                $success = true;

            } catch (PDOException $e) {
                error_log('[DLS edit] UPDATE error: ' . $e->getMessage());
                if (str_contains($e->getMessage(), 'Duplicate') && str_contains($e->getMessage(), 'isbn')) {
                    $errors[] = 'Cet ISBN est déjà utilisé par un autre livre.';
                } else {
                    $errors[] = 'Erreur lors de la sauvegarde. Veuillez réessayer.';
                }
            }
        }
    }
}

// ── Helpers d'affichage ───────────────────────────────────────
function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function val(array $livre, string $key, string $fallback = ''): string {
    return e((string)($livre[$key] ?? $fallback));
}

// Calcul access_type pour la preview (basé sur le prix actuel)
$currentPrix       = (float)($livre['prix'] ?? 0);
$currentAccessType = getAccessTypeByPrice($currentPrix);

$accessMeta = [
    'gratuit'  => ['label' => 'Gratuit',  'color' => '#34d399', 'bg' => 'rgba(52,211,153,.12)',  'border' => 'rgba(52,211,153,.3)',  'icon' => '🟢'],
    'standard' => ['label' => 'Standard', 'color' => '#60a5fa', 'bg' => 'rgba(96,165,250,.12)',  'border' => 'rgba(96,165,250,.3)',  'icon' => '🔵'],
    'premium'  => ['label' => 'Premium',  'color' => '#fbbf24', 'bg' => 'rgba(251,191,36,.12)',  'border' => 'rgba(251,191,36,.3)',  'icon' => '💎'],
];

$statusMeta = [
    'disponible' => ['label' => 'Disponible', 'color' => '#34d399'],
    'rupture'    => ['label' => 'Rupture',    'color' => '#f97316'],
    'archive'    => ['label' => 'Archivé',    'color' => '#94a3b8'],
];

$backLink = '../dashboard.php';
$pageTitle = 'Modifier · ' . e((string)($livre['titre'] ?? 'Livre'));

// Initiales avatar
$username  = htmlspecialchars($_SESSION['user_name'] ?? 'U', ENT_QUOTES, 'UTF-8');
$avatar    = strtoupper(substr($username, 0, 1)) ?: 'U';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?> — Digital Library</title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:ital,wght@0,400;0,500;1,400&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;1,9..144,300;1,9..144,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
/* ═══════════════════════════════════════════════════
   RESET & VARIABLES
═══════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:       #06090f;
    --bg2:      #0c1220;
    --bg3:      #111827;
    --panel:    rgba(17,24,39,.95);
    --s1:       rgba(255,255,255,.03);
    --s2:       rgba(255,255,255,.055);
    --s3:       rgba(255,255,255,.085);
    --border:   rgba(255,255,255,.07);
    --border2:  rgba(255,255,255,.12);

    --accent:   #6366f1;
    --accent2:  #818cf8;
    --green:    #34d399;
    --blue:     #60a5fa;
    --amber:    #fbbf24;
    --rose:     #fb7185;
    --orange:   #f97316;

    --t1: #f8fafc;
    --t2: rgba(248,250,252,.65);
    --t3: rgba(248,250,252,.35);
    --t4: rgba(248,250,252,.16);

    --r-sm: 6px;
    --r-md: 10px;
    --r-lg: 16px;
    --r-xl: 22px;
    --r-2xl: 30px;

    --shadow-sm: 0 1px 4px rgba(0,0,0,.25);
    --shadow-md: 0 4px 20px rgba(0,0,0,.4);
    --shadow-lg: 0 12px 50px rgba(0,0,0,.55);
    --glow: 0 0 30px rgba(99,102,241,.14);

    --font-ui:    'Syne', sans-serif;
    --font-serif: 'Fraunces', Georgia, serif;
    --font-mono:  'DM Mono', monospace;

    --topbar-h: 56px;
    --sidebar-w: 260px;
}

html, body {
    font-family: var(--font-ui);
    background: var(--bg);
    color: var(--t1);
    min-height: 100vh;
    line-height: 1.6;
    overflow-x: hidden;
}

::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--accent); }

/* ═══════════════════════════════════════════════════
   BACKGROUND
═══════════════════════════════════════════════════ */
.bg-mesh {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    background:
        radial-gradient(ellipse at 15% 25%, rgba(99,102,241,.07) 0%, transparent 55%),
        radial-gradient(ellipse at 85% 75%, rgba(139,92,246,.05) 0%, transparent 55%),
        radial-gradient(ellipse at 50% 50%, rgba(99,102,241,.03) 0%, transparent 70%);
}

/* ═══════════════════════════════════════════════════
   LAYOUT
═══════════════════════════════════════════════════ */
.page-wrap {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ═══════════════════════════════════════════════════
   TOPBAR
═══════════════════════════════════════════════════ */
.topbar {
    height: var(--topbar-h);
    background: rgba(6,9,15,.88);
    backdrop-filter: blur(24px);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: .7rem;
    padding: 0 1.4rem;
    position: sticky;
    top: 0;
    z-index: 100;
    animation: slideDown .35s ease both;
}

@keyframes slideDown {
    from { opacity:0; transform:translateY(-8px); }
    to   { opacity:1; transform:none; }
}

.tb-brand {
    display: flex;
    align-items: center;
    gap: .5rem;
    text-decoration: none;
    color: var(--t1);
    font-weight: 800;
    font-size: .88rem;
    flex-shrink: 0;
}

.brand-gem {
    width: 30px; height: 30px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--accent), #a855f7);
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem;
    box-shadow: var(--glow);
}

.tb-breadcrumb {
    display: flex;
    align-items: center;
    gap: .4rem;
    font-family: var(--font-mono);
    font-size: .62rem;
    color: var(--t4);
}

.tb-breadcrumb a { color: var(--t3); text-decoration: none; transition: color .15s; }
.tb-breadcrumb a:hover { color: var(--t1); }
.tb-breadcrumb .sep { opacity: .35; }
.tb-breadcrumb .curr { color: var(--t2); }

.tb-spacer { flex: 1; }

.tb-chip {
    display: flex;
    align-items: center;
    gap: .45rem;
    padding: .3rem .65rem;
    background: var(--s1);
    border: 1px solid var(--border);
    border-radius: 100px;
    font-family: var(--font-mono);
    font-size: .62rem;
    color: var(--t3);
}

.tb-avatar {
    width: 28px; height: 28px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--accent), #a855f7);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: .72rem; color: #fff;
    flex-shrink: 0;
}

.tb-back {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .35rem .7rem;
    background: var(--s2);
    border: 1px solid var(--border2);
    border-radius: var(--r-sm);
    color: var(--t2);
    text-decoration: none;
    font-family: var(--font-mono);
    font-size: .7rem;
    transition: all .15s;
    flex-shrink: 0;
}

.tb-back:hover {
    background: var(--s3);
    color: var(--t1);
    border-color: rgba(99,102,241,.4);
    box-shadow: var(--glow);
}

/* ═══════════════════════════════════════════════════
   MAIN CONTENT
═══════════════════════════════════════════════════ */
.main {
    flex: 1;
    max-width: 1140px;
    width: 100%;
    margin: 0 auto;
    padding: 2rem 1.4rem 5rem;
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 1.5rem;
    align-items: start;
}

/* ── Page header ── */
.page-header {
    grid-column: 1 / -1;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    animation: fadeUp .4s ease both;
    margin-bottom: .2rem;
}

@keyframes fadeUp {
    from { opacity:0; transform:translateY(14px); }
    to   { opacity:1; transform:none; }
}

.page-eyebrow {
    font-family: var(--font-mono);
    font-size: .6rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--accent2);
    margin-bottom: .35rem;
    display: flex;
    align-items: center;
    gap: .4rem;
}

.page-eyebrow::before {
    content: '';
    display: inline-block;
    width: 16px; height: 1px;
    background: var(--accent2);
    opacity: .5;
}

.page-title {
    font-family: var(--font-serif);
    font-size: 1.9rem;
    font-weight: 600;
    font-style: italic;
    line-height: 1.15;
    color: var(--t1);
    letter-spacing: -.02em;
    max-width: 520px;
}

.page-subtitle {
    font-size: .78rem;
    color: var(--t3);
    margin-top: .4rem;
    font-family: var(--font-mono);
}

.page-id-badge {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .35rem .75rem;
    background: var(--s1);
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    font-family: var(--font-mono);
    font-size: .65rem;
    color: var(--t3);
    flex-shrink: 0;
}

/* ═══════════════════════════════════════════════════
   ALERTS
═══════════════════════════════════════════════════ */
.alert {
    grid-column: 1 / -1;
    display: flex;
    align-items: flex-start;
    gap: .7rem;
    padding: .9rem 1.1rem;
    border-radius: var(--r-lg);
    font-size: .8rem;
    line-height: 1.5;
    animation: fadeUp .3s ease both;
}

.alert-error {
    background: rgba(251,113,133,.07);
    border: 1px solid rgba(251,113,133,.2);
    color: var(--rose);
}

.alert-success {
    background: rgba(52,211,153,.07);
    border: 1px solid rgba(52,211,153,.2);
    color: var(--green);
}

.alert i { font-size: .95rem; flex-shrink: 0; margin-top: 1px; }

.alert-error-list {
    list-style: none;
    margin-top: .3rem;
}

.alert-error-list li::before {
    content: '→ ';
    opacity: .6;
}

/* ═══════════════════════════════════════════════════
   FORM CARD
═══════════════════════════════════════════════════ */
.form-card {
    background: var(--s1);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    overflow: hidden;
    animation: fadeUp .45s ease .05s both;
    position: relative;
}

.form-card::before {
    content: '';
    display: block;
    height: 2px;
    background: linear-gradient(90deg, var(--accent), #a855f7, var(--accent2));
}

.form-section {
    padding: 1.4rem 1.6rem;
    border-bottom: 1px solid var(--border);
}

.form-section:last-child { border-bottom: none; }

.section-label {
    font-family: var(--font-mono);
    font-size: .58rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--t4);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: .5rem;
}

.section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ── Field ── */
.field {
    margin-bottom: 1rem;
}

.field:last-child { margin-bottom: 0; }

.field-label {
    display: block;
    font-size: .72rem;
    font-weight: 600;
    color: var(--t2);
    margin-bottom: .35rem;
    display: flex;
    align-items: center;
    gap: .3rem;
}

.field-label .required {
    color: var(--rose);
    font-size: .65rem;
}

.field-input,
.field-select,
.field-textarea {
    width: 100%;
    padding: .65rem .85rem;
    background: var(--bg3);
    border: 1px solid var(--border2);
    border-radius: var(--r-md);
    color: var(--t1);
    font-family: var(--font-ui);
    font-size: .82rem;
    outline: none;
    transition: border-color .18s, box-shadow .18s, background .18s;
    appearance: none;
    -webkit-appearance: none;
}

.field-input:focus,
.field-select:focus,
.field-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(99,102,241,.15);
    background: rgba(17,24,39,.8);
}

.field-input::placeholder,
.field-textarea::placeholder {
    color: var(--t4);
    font-style: italic;
}

.field-input.error { border-color: var(--rose); }
.field-input.ok    { border-color: var(--green); }

.field-textarea {
    min-height: 110px;
    resize: vertical;
    line-height: 1.6;
}

.field-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M5 6L0 0h10z' fill='rgba(248,250,252,0.3)'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .75rem center;
    padding-right: 2rem;
    cursor: pointer;
}

.field-select option {
    background: var(--bg2);
    color: var(--t1);
}

/* ── Two columns row ── */
.field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .8rem;
}

/* ── Field hint ── */
.field-hint {
    margin-top: .3rem;
    font-family: var(--font-mono);
    font-size: .6rem;
    color: var(--t4);
    display: flex;
    align-items: center;
    gap: .3rem;
}

.field-hint i { font-size: .65rem; }

/* ── Prix field special ── */
.prix-wrap {
    position: relative;
}

.prix-wrap .field-input {
    padding-right: 4rem;
}

.prix-unit {
    position: absolute;
    right: .75rem;
    top: 50%;
    transform: translateY(-50%);
    font-family: var(--font-mono);
    font-size: .65rem;
    color: var(--t4);
    pointer-events: none;
}

/* ═══════════════════════════════════════════════════
   ACCESS TYPE PREVIEW (read-only)
═══════════════════════════════════════════════════ */
.access-preview {
    display: flex;
    align-items: center;
    gap: .9rem;
    padding: 1rem 1.1rem;
    border-radius: var(--r-lg);
    border: 1px solid var(--at-border, var(--border));
    background: var(--at-bg, var(--s1));
    transition: all .3s ease;
    margin-top: .5rem;
}

.access-icon {
    font-size: 1.6rem;
    flex-shrink: 0;
    filter: drop-shadow(0 2px 6px rgba(0,0,0,.3));
    transition: transform .3s ease;
}

.access-preview:hover .access-icon { transform: scale(1.1) rotate(-5deg); }

.access-info-wrap { flex: 1; min-width: 0; }

.access-label {
    font-family: var(--font-ui);
    font-weight: 800;
    font-size: 1rem;
    color: var(--at-color, var(--t1));
    line-height: 1.2;
}

.access-desc {
    font-family: var(--font-mono);
    font-size: .62rem;
    color: var(--t3);
    margin-top: 2px;
    line-height: 1.5;
}

.access-badge {
    display: flex;
    align-items: center;
    gap: .3rem;
    padding: .3rem .75rem;
    border-radius: 100px;
    font-family: var(--font-mono);
    font-size: .62rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    background: var(--at-bg, var(--s1));
    color: var(--at-color, var(--t2));
    border: 1px solid var(--at-border, var(--border));
    flex-shrink: 0;
}

.access-lock-info {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .55rem .8rem;
    background: rgba(99,102,241,.06);
    border: 1px dashed rgba(99,102,241,.25);
    border-radius: var(--r-md);
    font-family: var(--font-mono);
    font-size: .62rem;
    color: var(--accent2);
    margin-top: .6rem;
}

/* ── Prix thresholds ── */
.price-thresholds {
    display: flex;
    gap: .5rem;
    margin-top: .6rem;
    flex-wrap: wrap;
}

.pt-item {
    display: flex;
    align-items: center;
    gap: .3rem;
    padding: .25rem .55rem;
    border-radius: 100px;
    font-family: var(--font-mono);
    font-size: .58rem;
    border: 1px solid var(--border);
    background: var(--s1);
    color: var(--t4);
    transition: all .2s;
}

.pt-item.active {
    color: var(--pt-color, var(--t1));
    border-color: var(--pt-border, var(--border));
    background: var(--pt-bg, var(--s1));
    box-shadow: 0 0 8px rgba(0,0,0,.2);
}

.pt-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}

/* ═══════════════════════════════════════════════════
   SIDEBAR — Preview & Meta
═══════════════════════════════════════════════════ */
.sidebar-col {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    animation: fadeUp .5s ease .1s both;
}

/* ── Book preview card ── */
.preview-card {
    background: var(--s1);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    overflow: hidden;
    position: relative;
}

.preview-cover {
    height: 160px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #0d1f3c, #1a0d3c);
}

.preview-cover::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 40% 40%, rgba(99,102,241,.18), transparent 65%);
}

.preview-emoji {
    font-size: 3.2rem;
    position: relative;
    z-index: 1;
    filter: drop-shadow(0 4px 16px rgba(0,0,0,.5));
    animation: float 3.5s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50%       { transform: translateY(-6px); }
}

.preview-cover::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, transparent 50%, rgba(6,9,15,.9));
}

.preview-body {
    padding: 1rem 1.1rem 1.2rem;
}

.preview-genre {
    font-family: var(--font-mono);
    font-size: .56rem;
    color: var(--accent2);
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: .3rem;
}

.preview-title {
    font-family: var(--font-serif);
    font-size: 1rem;
    font-weight: 600;
    font-style: italic;
    line-height: 1.25;
    margin-bottom: .2rem;
    color: var(--t1);
    word-break: break-word;
}

.preview-author {
    font-size: .72rem;
    color: var(--t3);
    margin-bottom: .7rem;
}

.preview-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: .7rem;
    border-top: 1px solid var(--border);
    margin-top: .5rem;
}

.preview-price {
    font-family: var(--font-mono);
    font-size: .88rem;
    font-weight: 700;
    color: var(--amber);
}

.preview-price.free { color: var(--green); }
.preview-price.std  { color: var(--blue); }

/* ── Meta info card ── */
.meta-card {
    background: var(--s1);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    padding: 1.1rem 1.2rem;
}

.meta-card-title {
    font-family: var(--font-mono);
    font-size: .58rem;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--t4);
    margin-bottom: .8rem;
}

.meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .45rem 0;
    border-bottom: 1px solid rgba(255,255,255,.04);
    font-size: .75rem;
}

.meta-row:last-child { border-bottom: none; }

.meta-row-label { color: var(--t3); display: flex; align-items: center; gap: .4rem; }
.meta-row-label i { font-size: .72rem; }
.meta-row-value { font-family: var(--font-mono); font-size: .7rem; color: var(--t1); font-weight: 500; }

/* ── Rules card ── */
.rules-card {
    background: rgba(99,102,241,.04);
    border: 1px solid rgba(99,102,241,.15);
    border-radius: var(--r-xl);
    padding: 1.1rem 1.2rem;
}

.rules-title {
    font-family: var(--font-mono);
    font-size: .58rem;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--accent2);
    margin-bottom: .8rem;
    display: flex;
    align-items: center;
    gap: .4rem;
}

.rule-item {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .45rem 0;
    border-bottom: 1px solid rgba(99,102,241,.08);
    font-size: .75rem;
}

.rule-item:last-child { border-bottom: none; }

.rule-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.rule-text { color: var(--t2); line-height: 1.4; }
.rule-text strong { color: var(--t1); font-weight: 700; }

/* ═══════════════════════════════════════════════════
   SUBMIT BAR
═══════════════════════════════════════════════════ */
.submit-bar {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.1rem 1.4rem;
    background: rgba(12,18,32,.9);
    backdrop-filter: blur(20px);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    animation: fadeUp .5s ease .15s both;
}

.submit-info {
    font-family: var(--font-mono);
    font-size: .65rem;
    color: var(--t4);
    display: flex;
    align-items: center;
    gap: .5rem;
}

.submit-info i { color: var(--accent2); }

.submit-actions { display: flex; gap: .6rem; flex-wrap: wrap; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .6rem 1.1rem;
    border-radius: var(--r-md);
    font-family: var(--font-ui);
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .18s ease;
    text-decoration: none;
    border: none;
    white-space: nowrap;
}

.btn-primary {
    background: linear-gradient(135deg, var(--accent), #7c3aed);
    color: #fff;
    box-shadow: 0 4px 16px rgba(99,102,241,.28);
}

.btn-primary:hover {
    opacity: .88;
    transform: translateY(-1px);
    box-shadow: 0 6px 24px rgba(99,102,241,.4);
}

.btn-primary:active { transform: translateY(0); }

.btn-ghost {
    background: var(--s2);
    border: 1px solid var(--border2);
    color: var(--t2);
}

.btn-ghost:hover { background: var(--s3); color: var(--t1); }

/* ═══════════════════════════════════════════════════
   TOAST
═══════════════════════════════════════════════════ */
#toast-wrap {
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
    box-shadow: var(--shadow-md);
    font-size: .78rem;
    max-width: 300px;
    pointer-events: all;
    transform: translateX(110px);
    opacity: 0;
    transition: all .3s cubic-bezier(.34,1.56,.64,1);
}

.toast.show { transform: none; opacity: 1; }
.toast-icon { font-size: .95rem; flex-shrink: 0; }
.toast-title { font-weight: 700; font-family: var(--font-ui); }
.toast-sub { font-size: .67rem; color: var(--t3); }

/* ═══════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════ */
@media (max-width: 860px) {
    .main { grid-template-columns: 1fr; padding: 1.2rem .9rem 4rem; }
    .page-header, .submit-bar { grid-column: 1; }
    .sidebar-col { order: -1; }
    .preview-card { display: grid; grid-template-columns: 140px 1fr; }
    .preview-cover { height: auto; }
}

@media (max-width: 560px) {
    .field-row { grid-template-columns: 1fr; }
    .preview-card { display: block; }
    .price-thresholds { flex-wrap: wrap; }
    .tb-breadcrumb { display: none; }
    .tb-chip { display: none; }
    .submit-bar { flex-direction: column; align-items: stretch; }
    .submit-actions { flex-direction: column; }
    .btn { justify-content: center; }
}
</style>
</head>

<body>
<div class="bg-mesh" aria-hidden="true"></div>

<div class="page-wrap">

<!-- ═══ TOPBAR ═══ -->
<nav class="topbar" role="navigation" aria-label="Navigation">
    <a href="<?= e($backLink) ?>" class="tb-brand" aria-label="Digital Library">
        <div class="brand-gem">📚</div>
        <span>Digital Library</span>
    </a>

    <div class="tb-breadcrumb" aria-label="Fil d'Ariane">
        <a href="<?= e($backLink) ?>">Dashboard</a>
        <span class="sep">›</span>
        <a href="#">Livres</a>
        <span class="sep">›</span>
        <span class="curr">Modifier #<?= (int)$livreId ?></span>
    </div>

    <div class="tb-spacer"></div>

    <div class="tb-chip" aria-label="Utilisateur connecté">
        <div class="tb-avatar"><?= e($avatar) ?></div>
        <span><?= e(explode(' ', $username)[0] ?? 'U') ?></span>
        <span style="color:var(--accent2);font-size:.58rem"><?= e($currentUserRole) ?></span>
    </div>

    <a href="<?= e($backLink) ?>" class="tb-back" aria-label="Retour au tableau de bord">
        <i class="bi bi-arrow-left"></i>
        Dashboard
    </a>
</nav>

<!-- ═══ MAIN CONTENT ═══ -->
<main class="main" role="main">

    <!-- ── Page header ── -->
    <div class="page-header">
        <div>
            <div class="page-eyebrow">Édition du livre</div>
            <h1 class="page-title"><?= val($livre, 'titre', 'Livre sans titre') ?></h1>
            <div class="page-subtitle">
                par <?= val($livre, 'auteur', '—') ?>
                &nbsp;·&nbsp; ID #<?= (int)$livreId ?>
                &nbsp;·&nbsp; Modifié <?= !empty($livre['updated_at']) ? date('d/m/Y à H\hi', strtotime($livre['updated_at'])) : '—' ?>
            </div>
        </div>
        <div class="page-id-badge">
            <i class="bi bi-hash"></i>
            Livre #<?= (int)$livreId ?>
            <span style="color:var(--border2)">|</span>
            <?= val($livre, 'statut', 'disponible') ?>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <!-- ── Erreurs ── -->
    <div class="alert alert-error" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>Impossible de sauvegarder</strong>
            <ul class="alert-error-list">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <!-- ── Succès ── -->
    <div class="alert alert-success" role="status">
        <i class="bi bi-check-circle-fill"></i>
        <div>
            <strong>Livre mis à jour avec succès !</strong>
            <div style="font-size:.72rem;margin-top:2px;opacity:.8">
                Access type calculé automatiquement : <strong><?= e($currentAccessType) ?></strong>
                — Sauvegardé le <?= date('d/m/Y à H\hi') ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════
         FORMULAIRE PRINCIPAL
    ═══════════════════════════════════════════════ -->
    <form method="POST" action="" novalidate id="edit-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <div class="form-card">

            <!-- ── Section 1 : Informations essentielles ── -->
            <div class="form-section">
                <div class="section-label">Informations essentielles</div>

                <div class="field">
                    <label class="field-label" for="titre">
                        Titre du livre <span class="required">*</span>
                    </label>
                    <input
                        type="text"
                        id="titre"
                        name="titre"
                        class="field-input"
                        value="<?= val($livre, 'titre') ?>"
                        placeholder="Ex: Fondation"
                        maxlength="255"
                        required
                        autocomplete="off"
                        oninput="updatePreview()">
                    <div class="field-hint"><i class="bi bi-info-circle"></i> Max. 255 caractères</div>
                </div>

                <div class="field">
                    <label class="field-label" for="auteur">
                        Auteur <span class="required">*</span>
                    </label>
                    <input
                        type="text"
                        id="auteur"
                        name="auteur"
                        class="field-input"
                        value="<?= val($livre, 'auteur') ?>"
                        placeholder="Ex: Isaac Asimov"
                        maxlength="150"
                        required
                        autocomplete="off"
                        oninput="updatePreview()">
                </div>

                <div class="field">
                    <label class="field-label" for="description">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        class="field-textarea"
                        placeholder="Résumé ou description du livre…"
                        rows="4"
                        oninput="updatePreview()"><?= val($livre, 'description') ?></textarea>
                </div>
            </div>

            <!-- ── Section 2 : Prix & Statut ── -->
            <div class="form-section">
                <div class="section-label">Prix & Statut d'accès</div>

                <!-- Prix -->
                <div class="field">
                    <label class="field-label" for="prix">
                        Prix
                        <span style="font-weight:400;color:var(--t4);font-family:var(--font-mono);font-size:.6rem">(0 = Gratuit)</span>
                    </label>
                    <div class="prix-wrap">
                        <input
                            type="text"
                            id="prix"
                            name="prix"
                            class="field-input"
                            value="<?= number_format($currentPrix, 2, '.', '') ?>"
                            placeholder="0.00"
                            inputmode="decimal"
                            autocomplete="off"
                            oninput="onPrixChange(this.value)">
                        <span class="prix-unit">FCFA</span>
                    </div>
                    <div class="field-hint">
                        <i class="bi bi-info-circle"></i>
                        Entier ou décimal uniquement (ex: 2500 ou 2500.50). Pas de texte.
                    </div>

                    <!-- Seuils visuels -->
                    <div class="price-thresholds" id="price-thresholds">
                        <div class="pt-item" id="pt-gratuit"
                             style="--pt-color:var(--green);--pt-border:rgba(52,211,153,.35);--pt-bg:rgba(52,211,153,.08)">
                            <div class="pt-dot" style="color:var(--green)"></div>
                            Gratuit = 0 FCFA
                        </div>
                        <div class="pt-item" id="pt-standard"
                             style="--pt-color:var(--blue);--pt-border:rgba(96,165,250,.35);--pt-bg:rgba(96,165,250,.08)">
                            <div class="pt-dot" style="color:var(--blue)"></div>
                            Standard = 1–3 000 FCFA
                        </div>
                        <div class="pt-item" id="pt-premium"
                             style="--pt-color:var(--amber);--pt-border:rgba(251,191,36,.35);--pt-bg:rgba(251,191,36,.08)">
                            <div class="pt-dot" style="color:var(--amber)"></div>
                            Premium = +3 000 FCFA
                        </div>
                    </div>
                </div>

                <!-- Access type read-only -->
                <div class="field">
                    <div class="field-label">
                        <i class="bi bi-shield-lock" style="color:var(--accent2)"></i>
                        Type d'accès
                        <span style="font-weight:400;color:var(--t4);font-family:var(--font-mono);font-size:.6rem">(calculé automatiquement)</span>
                    </div>

                    <div class="access-preview" id="access-preview"
                         style="
                            --at-color: <?= e($accessMeta[$currentAccessType]['color']) ?>;
                            --at-bg:    <?= e($accessMeta[$currentAccessType]['bg']) ?>;
                            --at-border:<?= e($accessMeta[$currentAccessType]['border']) ?>">
                        <div class="access-icon" id="access-icon"><?= $accessMeta[$currentAccessType]['icon'] ?></div>
                        <div class="access-info-wrap">
                            <div class="access-label" id="access-label"><?= e($accessMeta[$currentAccessType]['label']) ?></div>
                            <div class="access-desc" id="access-desc">
                                <?php if ($currentAccessType === 'gratuit'): ?>
                                    Accès libre — aucun paiement requis
                                <?php elseif ($currentAccessType === 'standard'): ?>
                                    Prix entre 1 et 3 000 FCFA — achat requis
                                <?php else: ?>
                                    Prix supérieur à 3 000 FCFA — accès premium
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="access-badge" id="access-badge"
                             style="color:<?= e($accessMeta[$currentAccessType]['color']) ?>;border-color:<?= e($accessMeta[$currentAccessType]['border']) ?>">
                            <i class="bi bi-check-circle-fill" style="font-size:.6rem"></i>
                            <span id="access-badge-text"><?= strtoupper($currentAccessType) ?></span>
                        </div>
                    </div>

                    <div class="access-lock-info">
                        <i class="bi bi-lock-fill"></i>
                        Ce champ est <strong>calculé automatiquement</strong> d'après le prix.
                        Toute valeur soumise manuellement est ignorée par le serveur.
                    </div>
                </div>

                <!-- Statut -->
                <div class="field">
                    <label class="field-label" for="statut">Statut de publication</label>
                    <select id="statut" name="statut" class="field-select">
                        <option value="disponible" <?= ($livre['statut'] ?? '') === 'disponible' ? 'selected' : '' ?>>✅ Disponible</option>
                        <option value="rupture"    <?= ($livre['statut'] ?? '') === 'rupture'    ? 'selected' : '' ?>>⚠️ Rupture</option>
                        <option value="archive"    <?= ($livre['statut'] ?? '') === 'archive'    ? 'selected' : '' ?>>📦 Archivé</option>
                    </select>
                </div>
            </div>

            <!-- ── Section 3 : Catalogue ── -->
            <div class="form-section">
                <div class="section-label">Catalogue & Classification</div>

                <div class="field-row">
                    <div class="field">
                        <label class="field-label" for="categorie_id">Catégorie</label>
                        <select id="categorie_id" name="categorie_id" class="field-select" onchange="updatePreview()">
                            <option value="">— Sélectionner —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"
                                    <?= (int)($livre['categorie_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['icone'] ?? '') ?> <?= e($cat['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field-label" for="langue">Langue</label>
                        <select id="langue" name="langue" class="field-select">
                            <?php foreach (['Français', 'Anglais', 'Espagnol', 'Arabe', 'Portugais', 'Allemand', 'Autre'] as $l): ?>
                                <option value="<?= e($l) ?>" <?= ($livre['langue'] ?? 'Français') === $l ? 'selected' : '' ?>>
                                    <?= e($l) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="field-label" for="pages">Nombre de pages</label>
                        <input
                            type="number"
                            id="pages"
                            name="pages"
                            class="field-input"
                            value="<?= (int)($livre['pages'] ?? 0) ?: '' ?>"
                            placeholder="Ex: 320"
                            min="1"
                            max="99999"
                            inputmode="numeric">
                    </div>

                    <div class="field">
                        <label class="field-label" for="annee_parution">Année de parution</label>
                        <input
                            type="number"
                            id="annee_parution"
                            name="annee_parution"
                            class="field-input"
                            value="<?= !empty($livre['annee_parution']) ? (int)$livre['annee_parution'] : '' ?>"
                            placeholder="Ex: 2023"
                            min="-3000"
                            max="<?= date('Y') + 5 ?>"
                            inputmode="numeric">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="field-label" for="editeur">Éditeur</label>
                        <input
                            type="text"
                            id="editeur"
                            name="editeur"
                            class="field-input"
                            value="<?= val($livre, 'editeur') ?>"
                            placeholder="Ex: Gallimard"
                            maxlength="150"
                            autocomplete="off">
                    </div>

                    <div class="field">
                        <label class="field-label" for="isbn">ISBN</label>
                        <input
                            type="text"
                            id="isbn"
                            name="isbn"
                            class="field-input"
                            value="<?= val($livre, 'isbn') ?>"
                            placeholder="Ex: 978-2-07-036024-1"
                            maxlength="20"
                            autocomplete="off">
                    </div>
                </div>
            </div>

            <!-- ── Section 4 : Fichiers ── -->
            <div class="form-section">
                <div class="section-label">Fichiers & Médias</div>

                <div class="field">
                    <label class="field-label" for="couverture">
                        <i class="bi bi-image" style="color:var(--accent2)"></i>
                        URL de la couverture
                    </label>
                    <input
                        type="text"
                        id="couverture"
                        name="couverture"
                        class="field-input"
                        value="<?= val($livre, 'couverture') ?>"
                        placeholder="https://exemple.com/couverture.jpg"
                        maxlength="500"
                        autocomplete="off">
                    <div class="field-hint"><i class="bi bi-info-circle"></i> URL vers l'image de couverture (JPG, PNG, WebP)</div>
                </div>

                <div class="field">
                    <label class="field-label" for="fichier_pdf">
                        <i class="bi bi-file-earmark-pdf" style="color:var(--rose)"></i>
                        Chemin du fichier PDF
                    </label>
                    <input
                        type="text"
                        id="fichier_pdf"
                        name="fichier_pdf"
                        class="field-input"
                        value="<?= val($livre, 'fichier_pdf') ?>"
                        placeholder="uploads/livres/nom-du-fichier.pdf"
                        maxlength="500"
                        autocomplete="off">
                    <div class="field-hint"><i class="bi bi-info-circle"></i> Chemin relatif depuis la racine du projet</div>
                </div>
            </div>

        </div><!-- /form-card -->

        <!-- ── Barre de soumission ── -->
        <div class="submit-bar">
            <div class="submit-info">
                <i class="bi bi-shield-check"></i>
                Sécurisé · PDO préparé · access_type calculé côté serveur
            </div>
            <div class="submit-actions">
                <a href="<?= e($backLink) ?>" class="btn btn-ghost">
                    <i class="bi bi-x-lg"></i>
                    Annuler
                </a>
                <button type="submit" class="btn btn-primary" id="btn-submit">
                    <i class="bi bi-check2-circle"></i>
                    Sauvegarder les modifications
                </button>
            </div>
        </div>

    </form><!-- /edit-form -->

    <!-- ═══════════════════════════════════════════════
         SIDEBAR — Preview & Meta
    ═══════════════════════════════════════════════ -->
    <div class="sidebar-col" aria-label="Aperçu et informations">

        <!-- Preview card -->
        <div class="preview-card">
            <div class="preview-cover">
                <div class="preview-emoji" id="preview-emoji">📚</div>
            </div>
            <div class="preview-body">
                <div class="preview-genre" id="preview-genre">
                    <?= val($livre, 'categorie_nom', 'Catégorie') ?>
                </div>
                <div class="preview-title" id="preview-title">
                    <?= val($livre, 'titre', 'Titre du livre') ?>
                </div>
                <div class="preview-author" id="preview-author">
                    par <?= val($livre, 'auteur', '—') ?>
                </div>
                <div class="preview-footer">
                    <div class="preview-price <?= $currentAccessType ?>" id="preview-price">
                        <?= $currentPrix <= 0 ? 'Gratuit' : number_format($currentPrix, 0, '.', ' ') . ' FCFA' ?>
                    </div>
                    <div id="preview-access-badge" style="
                        display:inline-flex;align-items:center;gap:.3rem;
                        padding:.22rem .6rem;border-radius:100px;
                        font-family:var(--font-mono);font-size:.55rem;font-weight:700;letter-spacing:.04em;
                        background:<?= $accessMeta[$currentAccessType]['bg'] ?>;
                        color:<?= $accessMeta[$currentAccessType]['color'] ?>;
                        border:1px solid <?= $accessMeta[$currentAccessType]['border'] ?>">
                        <?= $accessMeta[$currentAccessType]['icon'] ?> <?= strtoupper($currentAccessType) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Meta infos -->
        <div class="meta-card">
            <div class="meta-card-title">Informations du livre</div>

            <div class="meta-row">
                <div class="meta-row-label"><i class="bi bi-hash"></i> ID</div>
                <div class="meta-row-value">#<?= (int)$livreId ?></div>
            </div>
            <div class="meta-row">
                <div class="meta-row-label"><i class="bi bi-calendar-plus"></i> Créé</div>
                <div class="meta-row-value">
                    <?= !empty($livre['created_at']) ? date('d/m/Y', strtotime($livre['created_at'])) : '—' ?>
                </div>
            </div>
            <div class="meta-row">
                <div class="meta-row-label"><i class="bi bi-calendar-check"></i> Modifié</div>
                <div class="meta-row-value">
                    <?= !empty($livre['updated_at']) ? date('d/m/Y H:i', strtotime($livre['updated_at'])) : '—' ?>
                </div>
            </div>
            <div class="meta-row">
                <div class="meta-row-label"><i class="bi bi-graph-up"></i> Ventes</div>
                <div class="meta-row-value"><?= number_format((int)($livre['nb_ventes'] ?? 0), 0, ',', ' ') ?></div>
            </div>
            <div class="meta-row">
                <div class="meta-row-label"><i class="bi bi-star-fill" style="color:var(--amber)"></i> Note</div>
                <div class="meta-row-value">⭐ <?= number_format((float)($livre['note_moyenne'] ?? 0), 1) ?> / 5</div>
            </div>
            <div class="meta-row">
                <div class="meta-row-label"><i class="bi bi-eye"></i> Lectures</div>
                <div class="meta-row-value"><?= number_format((int)($livre['nb_lectures'] ?? 0), 0, ',', ' ') ?></div>
            </div>
            <div class="meta-row">
                <div class="meta-row-label"><i class="bi bi-shield-lock" style="color:var(--accent2)"></i> Access type</div>
                <div class="meta-row-value" style="color:<?= e($accessMeta[$currentAccessType]['color']) ?>" id="meta-access-type">
                    <?= strtoupper($currentAccessType) ?>
                </div>
            </div>
        </div>

        <!-- Règles de calcul -->
        <div class="rules-card">
            <div class="rules-title">
                <i class="bi bi-robot"></i>
                Règles de calcul automatique
            </div>

            <div class="rule-item">
                <div class="rule-dot" style="background:var(--green)"></div>
                <div class="rule-text">
                    <strong>Gratuit</strong> — prix = 0 ou vide
                </div>
            </div>
            <div class="rule-item">
                <div class="rule-dot" style="background:var(--blue)"></div>
                <div class="rule-text">
                    <strong>Standard</strong> — prix entre 1 et 3 000 FCFA
                </div>
            </div>
            <div class="rule-item">
                <div class="rule-dot" style="background:var(--amber)"></div>
                <div class="rule-text">
                    <strong>Premium</strong> — prix supérieur à 3 000 FCFA
                </div>
            </div>
            <div class="rule-item" style="opacity:.6">
                <div class="rule-dot" style="background:var(--accent2)"></div>
                <div class="rule-text">
                    Valeurs POST ignorées — calcul 100% serveur
                </div>
            </div>
        </div>

    </div><!-- /sidebar-col -->

</main><!-- /main -->

</div><!-- /page-wrap -->

<!-- Toast stack -->
<div id="toast-wrap" aria-live="assertive" role="region"></div>

<!-- ═══════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════ -->
<script>
'use strict';

// ── Données initiales ─────────────────────────────────────────
const CATEGORIES = <?= json_encode(array_map(function($c) {
    return ['id' => (int)$c['id'], 'nom' => $c['nom'], 'icone' => $c['icone'] ?? '📚'];
}, $categories), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>;

const COVER_EMOJIS = ['📚','📘','📗','📙','📕','📓','📔','📒','🔮','💡','🌊','🏔️','⚡','🌌','🧠','🌿','⚙️','📜','🎭','🔭'];
const LIVRE_ID = <?= (int)$livreId ?>;

let currentEmoji = '<?= e($coverEmojis[array_rand($coverEmojis) ?? 0] ?? '📚') ?>';

// ── Logique access_type côté JS (miroir de PHP) ───────────────
// Uniquement pour la preview visuelle — le calcul réel est côté PHP
function getAccessTypeJS(prix) {
    const p = parseFloat(prix) || 0;
    if (p <= 0)    return 'gratuit';
    if (p <= 3000) return 'standard';
    return 'premium';
}

const ACCESS_META = {
    gratuit:  { label: 'Gratuit',  color: '#34d399', bg: 'rgba(52,211,153,.12)',  border: 'rgba(52,211,153,.3)',  icon: '🟢', desc: 'Accès libre — aucun paiement requis' },
    standard: { label: 'Standard', color: '#60a5fa', bg: 'rgba(96,165,250,.12)',  border: 'rgba(96,165,250,.3)',  icon: '🔵', desc: 'Prix entre 1 et 3 000 FCFA — achat requis' },
    premium:  { label: 'Premium',  color: '#fbbf24', bg: 'rgba(251,191,36,.12)',  border: 'rgba(251,191,36,.3)',  icon: '💎', desc: 'Prix supérieur à 3 000 FCFA — accès premium' },
};

// ── Mise à jour de la preview access_type ─────────────────────
function onPrixChange(val) {
    const prix = parseFloat(val.replace(',', '.')) || 0;
    const type = getAccessTypeJS(prix);
    const meta = ACCESS_META[type];

    // Preview widget
    const preview = document.getElementById('access-preview');
    if (preview) {
        preview.style.setProperty('--at-color',  meta.color);
        preview.style.setProperty('--at-bg',     meta.bg);
        preview.style.setProperty('--at-border', meta.border);
    }

    const iconEl  = document.getElementById('access-icon');
    const labelEl = document.getElementById('access-label');
    const descEl  = document.getElementById('access-desc');
    const badgeEl = document.getElementById('access-badge');
    const badgeTx = document.getElementById('access-badge-text');

    if (iconEl)  iconEl.textContent  = meta.icon;
    if (labelEl) labelEl.textContent = meta.label;
    if (descEl)  descEl.textContent  = meta.desc;
    if (badgeEl) {
        badgeEl.style.color       = meta.color;
        badgeEl.style.borderColor = meta.border;
    }
    if (badgeTx) badgeTx.textContent = type.toUpperCase();

    // Seuils actifs
    document.getElementById('pt-gratuit') ?.classList.toggle('active', type === 'gratuit');
    document.getElementById('pt-standard')?.classList.toggle('active', type === 'standard');
    document.getElementById('pt-premium') ?.classList.toggle('active', type === 'premium');

    // Prix dans la preview card
    const priceEl = document.getElementById('preview-price');
    if (priceEl) {
        priceEl.textContent = prix <= 0
            ? 'Gratuit'
            : prix.toLocaleString('fr-FR', {maximumFractionDigits: 0}) + ' FCFA';
        priceEl.className = 'preview-price ' + type;
    }

    // Badge dans la preview card
    const pBadge = document.getElementById('preview-access-badge');
    if (pBadge) {
        pBadge.style.background   = meta.bg;
        pBadge.style.color        = meta.color;
        pBadge.style.borderColor  = meta.border;
        pBadge.textContent        = meta.icon + ' ' + type.toUpperCase();
    }

    // Meta row
    const metaAccess = document.getElementById('meta-access-type');
    if (metaAccess) {
        metaAccess.textContent = type.toUpperCase();
        metaAccess.style.color = meta.color;
    }
}

// ── Mise à jour preview livre ─────────────────────────────────
function updatePreview() {
    const titre  = document.getElementById('titre')?.value  || 'Titre du livre';
    const auteur = document.getElementById('auteur')?.value || '—';
    const catSel = document.getElementById('categorie_id');

    const tEl = document.getElementById('preview-title');
    const aEl = document.getElementById('preview-author');
    const gEl = document.getElementById('preview-genre');

    if (tEl) tEl.textContent = titre   || 'Titre du livre';
    if (aEl) aEl.textContent = 'par ' + (auteur || '—');

    if (catSel && gEl) {
        const opt = catSel.options[catSel.selectedIndex];
        if (opt && opt.value) {
            gEl.textContent = opt.text.trim();
            // Update emoji based on category
            const catId = parseInt(opt.value);
            const idx   = catId % COVER_EMOJIS.length;
            const emoji = COVER_EMOJIS[idx];
            const eEl   = document.getElementById('preview-emoji');
            if (eEl) eEl.textContent = emoji;
        } else {
            gEl.textContent = 'Catégorie';
        }
    }
}

// ── Validation prix en temps réel ─────────────────────────────
document.getElementById('prix')?.addEventListener('input', function() {
    const raw = this.value.replace(',', '.');
    const isValid = raw === '' || /^\d+(\.\d{0,2})?$/.test(raw);
    this.classList.toggle('error', !isValid && raw !== '');
    this.classList.toggle('ok', isValid && raw !== '' && parseFloat(raw) >= 0);
    onPrixChange(raw);
});

// ── Formatage carte CN ─────────────────────────────────────────
document.getElementById('isbn')?.addEventListener('input', function() {
    const v = this.value.trim();
    this.classList.toggle('ok', v.length >= 10);
});

// ── Soumission formulaire ─────────────────────────────────────
document.getElementById('edit-form')?.addEventListener('submit', function(e) {
    const btn = document.getElementById('btn-submit');
    if (!btn) return;

    // Validation prix côté JS avant soumission
    const prixRaw = (document.getElementById('prix')?.value || '').replace(',', '.');
    if (prixRaw !== '' && !/^\d+(\.\d{1,2})?$/.test(prixRaw)) {
        e.preventDefault();
        toast('Prix invalide', 'Entrez un nombre valide (ex: 2500 ou 2500.50)', 'error');
        document.getElementById('prix')?.focus();
        return;
    }

    // Titre obligatoire
    const titre = (document.getElementById('titre')?.value || '').trim();
    if (!titre) {
        e.preventDefault();
        toast('Titre manquant', 'Le titre du livre est obligatoire.', 'error');
        document.getElementById('titre')?.focus();
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sauvegarde en cours…';
    btn.style.opacity = '.7';
});

// ── Toast system ──────────────────────────────────────────────
const T_ICONS = { success: '✅', error: '❌', warn: '⚠️', info: 'ℹ️' };
let toastTimer = null;

function toast(title, sub = '', type = 'info', dur = 4000) {
    const stack = document.getElementById('toast-wrap');
    if (!stack) return;
    const t = document.createElement('div');
    t.className = 'toast';
    t.style.borderColor = { success: 'var(--green)', error: 'var(--rose)', warn: 'var(--amber)', info: 'var(--accent2)' }[type] || 'var(--border2)';
    t.innerHTML = `
        <span class="toast-icon">${T_ICONS[type] || 'ℹ️'}</span>
        <div>
            <div class="toast-title">${escH(title)}</div>
            ${sub ? `<div class="toast-sub">${escH(sub)}</div>` : ''}
        </div>`;
    stack.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, dur);
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

// ── Init ──────────────────────────────────────────────────────
(function init() {
    // Appliquer état initial access_type
    const initialPrix = parseFloat(document.getElementById('prix')?.value || '0') || 0;
    onPrixChange(initialPrix);

    // Marquer seuil initial
    const initType = getAccessTypeJS(initialPrix);
    document.getElementById('pt-' + initType)?.classList.add('active');

    // Preview initiale
    updatePreview();

    // Toast succès si sauvegarde réussie
    <?php if ($success): ?>
    setTimeout(() => toast('Sauvegardé avec succès', 'Access type recalculé : <?= e($currentAccessType) ?>', 'success', 5000), 400);
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    setTimeout(() => toast('<?= count($errors) ?> erreur(s) détectée(s)', 'Vérifiez les champs en rouge.', 'error', 5000), 400);
    <?php endif; ?>
})();

// ── Raccourcis clavier ────────────────────────────────────────
document.addEventListener('keydown', e => {
    // Ctrl+S = Soumettre
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        document.getElementById('edit-form')?.requestSubmit();
    }
    // Escape = Retour
    if (e.key === 'Escape' && !e.target.matches('input,textarea,select')) {
        window.location.href = '<?= e($backLink) ?>';
    }
});
</script>

</body>
</html>