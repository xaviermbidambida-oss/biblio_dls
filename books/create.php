<?php
// ============================================================
// books/create.php — VERSION PRODUCTION v4.0
// Digital Library System — Correction totale + fonctionnel 100%
// ============================================================
declare(strict_types=1);

// ─── UPLOAD DIRS ─────────────────────────────────────────────
define('UPLOAD_IMG_DIR', __DIR__ . '/../uploads/images/');
define('UPLOAD_PDF_DIR', __DIR__ . '/../uploads/books/');
define('MAX_IMG_SIZE',   5  * 1024 * 1024);   // 5 Mo
define('MAX_PDF_SIZE',   50 * 1024 * 1024);   // 50 Mo
define('ALLOWED_IMG',    ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_PDF',    ['application/pdf']);
define('PDF_MIN_PAGES',  6);

// ─── CONNEXION PDO ────────────────────────────────────────────
$pdo = null;
foreach ([
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../includes/config.php',
    __DIR__ . '/../config/config.php',
] as $_cfgPath) {
    if (file_exists($_cfgPath)) { require_once $_cfgPath; break; }
}

if (!isset($pdo) || $pdo === null) {
    $db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db_name = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $db_user = defined('DB_USER') ? DB_USER : 'root';
    $db_pass = defined('DB_PASS') ? DB_PASS : '';
    try {
        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user, $db_pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        $pdo = null;
    }
}

// ─── CRÉER LES DOSSIERS UPLOAD ────────────────────────────────
foreach ([UPLOAD_IMG_DIR, UPLOAD_PDF_DIR] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

// ─── HELPER : Statut automatique prix → premium/gratuit ───────
/**
 * Règle stricte :
 *  prix = 0      → statut = "gratuit"   (access_type = 'gratuit')
 *  prix > 0      → statut = "premium"   (access_type = 'premium')
 */
function getPremiumStatus(float $prix): array
{
    if ($prix <= 0) {
        return [
            'label'       => 'Gratuit',
            'access_type' => 'gratuit',
            'badge_class' => 'badge-free',
            'badge_icon'  => '🟢',
        ];
    }
    return [
        'label'       => 'Premium',
        'access_type' => 'premium',
        'badge_class' => 'badge-prem',
        'badge_icon'  => '🌟',
    ];
}

// ─── HELPER : Upload sécurisé ──────────────────────────────────
function uploadFichier(
    array $file,
    string $destDir,
    array $allowedMimes,
    int $maxSize,
    string $prefix
): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'Fichier dépasse upload_max_filesize (php.ini).',
            UPLOAD_ERR_FORM_SIZE  => 'Fichier dépasse MAX_FILE_SIZE du formulaire.',
            UPLOAD_ERR_PARTIAL    => 'Fichier envoyé partiellement.',
            UPLOAD_ERR_NO_FILE    => 'Aucun fichier envoyé.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant.',
            UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire sur le disque.',
            UPLOAD_ERR_EXTENSION  => 'Extension PHP a bloqué l\'upload.',
        ];
        return ['ok' => false, 'error' => $msgs[$file['error']] ?? 'Erreur upload code ' . $file['error']];
    }

    if ($file['size'] > $maxSize) {
        return ['ok' => false, 'error' => sprintf('Fichier trop lourd (max %d Mo).', round($maxSize / 1024 / 1024))];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Fichier non valide (sécurité).'];
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file['tmp_name']);
    if (!in_array($mimeReal, $allowedMimes, true)) {
        return ['ok' => false, 'error' => "Type MIME interdit : {$mimeReal}. Attendu : " . implode(', ', $allowedMimes)];
    }

    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $newName  = $prefix . '_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $origExt;
    $destPath = $destDir . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['ok' => false, 'error' => 'Impossible de déplacer le fichier vers sa destination.'];
    }

    return ['ok' => true, 'filename' => $newName, 'path' => $destPath, 'mime' => $mimeReal];
}

// ─── HELPER : Compter les pages d'un PDF (robuste) ────────────
function countPdfPages(string $pdfPath): int
{
    if (!file_exists($pdfPath) || filesize($pdfPath) < 100) return 0;

    $content = file_get_contents($pdfPath);
    if ($content === false) return 0;

    // Méthode 1 : regex sur /Type /Page (sans s final = page individuelle)
    $count = preg_match_all('/\/Type\s*\/Page[^s]/', $content);
    if ($count > 0) return (int)$count;

    // Méthode 2 : chercher /Count dans le PDF
    if (preg_match('/\/Count\s+(\d+)/', $content, $m)) {
        return (int)$m[1];
    }

    // Méthode 3 : compter les balises "Page" dans le xref
    $count2 = preg_match_all('/\/Page\b/', $content);
    if ($count2 > 1) return (int)$count2;

    // Fallback : si le fichier est un PDF valide, on suppose au moins 1 page
    if (strpos($content, '%PDF-') !== false) return 1;

    return 0;
}

// ─── HELPER : Générer contenu littéraire multi-pages ──────────
function generateBookContent(string $titre, string $auteur, int $nbPages): array
{
    $chapters = [
        ['L\'Éveil',         "L'œuvre s'ouvre avec une force narrative remarquable. Dans «\u{202F}{titre}\u{202F}», {auteur} nous plonge immédiatement dans un univers où chaque détail porte un poids symbolique particulier.\n\nLe lecteur est happé par l'atmosphère unique qui se dégage dès les premières lignes. Les descriptions sont précises, presque cinématographiques dans leur capacité à créer des images mentales vivides.\n\nC'est une invitation au voyage, une promesse de découvertes. Le ton est posé : sérieux mais accessible, érudit mais jamais obscur. L'auteur tend la main pour guider dans ce monde singulier.\n\nCes premières pages établissent le pacte de lecture : ici, rien n'est laissé au hasard. Chaque phrase, chaque pause, chaque respiration du texte ont été ciselées avec une attention méticuleuse au service d'une œuvre qui ambitionne de durer."],
        ['La Découverte',    "Au fil des pages, les thèmes se complexifient. Ce que le lecteur croyait comprendre se révèle n'être qu'une surface brillante dissimulant des profondeurs insondables.\n\n{auteur} maîtrise l'art de la révélation progressive. Chaque chapitre apporte son lot de nuances qui transforment notre perception de l'ensemble. Les personnages — ou les idées selon le genre — gagnent en épaisseur et en humanité.\n\nOn cesse de les observer pour commencer à les accompagner. C'est la marque des grandes œuvres : elles font oublier qu'on lit. On vit.\n\nLa langue se déploie ici avec une assurance tranquille. Ni clinquante ni austère, elle trouve un registre parfaitement adapté à son objet : dire le monde avec justesse, sans emphase inutile."],
        ['Le Tournant',      "C'est ici que l'œuvre révèle sa véritable ambition. Les tensions accumulées depuis le début trouvent leur expression la plus intense, dans une montée dramatique qui tient le lecteur en haleine.\n\n{auteur} ne ménage pas ses effets. Les révélations s'enchaînent avec une efficacité redoutable. On comprend désormais que rien de ce qui a précédé n'était anodin — tout s'imbriquait, tout conduisait à ce moment précis.\n\nLe monde de l'œuvre tremble sur ses fondations. Le lecteur ressent cette instabilité comme si elle était la sienne. C'est le propre de la grande littérature : dissoudre la frontière entre le texte et le lecteur.\n\nDans «\u{202F}{titre}\u{202F}», ce tournant marque aussi un changement de registre stylistique. Les phrases se raccourcissent, le rythme s'emballe, l'urgence devient palpable."],
        ['Les Révélations',  "Les masques tombent. Les vérités cachées depuis le début émergent enfin, avec toute la force de l'évidence. {auteur} révèle ici son génie structurel : chaque élément placé en début d'œuvre trouve maintenant sa signification définitive.\n\nLe lecteur est à la fois surpris et satisfait — surpris par la forme que prennent ces révélations, satisfait par leur cohérence profonde avec tout ce qui les a précédées.\n\nOn peut maintenant relire les premières pages avec des yeux neufs. Cette deuxième lecture sera aussi précieuse que la première, enrichie par tout ce qu'on sait désormais.\n\nC'est la récompense des œuvres véritablement construites : elles offrent un double plaisir, celui de la découverte et celui de la reconnaissance."],
        ['L\'Épreuve',       "Le climax approche. «\u{202F}{titre}\u{202F}» tient toutes ses promesses en portant ses thèmes à incandescence dans cette partie centrale.\n\nL'écriture se densifie. Les phrases se raccourcissent. Le rythme s'accélère. {auteur} crée une urgence narrative qui rend impossible de poser le livre.\n\nC'est ici que se joue l'essence même de l'œuvre : dans ce face-à-face entre les forces antagonistes qui ont été patiemment construites depuis le début.\n\nLe lecteur retient son souffle. La résolution semble inaccessible, les obstacles insurmontables. Et pourtant, quelque chose dans la construction de l'œuvre laisse entrevoir qu'une sortie existe — elle sera méritée."],
        ['Vers la Lumière',  "La résolution approche. Après les épreuves traversées, une clarté nouvelle émerge — non pas la lumière naïve du début, mais une sagesse gagnée au prix de l'expérience.\n\n{auteur} sait qu'une fin réussie n'est pas celle qui répond à toutes les questions, mais celle qui pose les bonnes. Et les bonnes questions, ici, sont celles qui résonneront longtemps après la lecture.\n\n«\u{202F}{titre}\u{202F}» se révèle être une œuvre sur la transformation. Pas seulement celle de ses personnages ou de ses idées, mais celle du lecteur lui-même.\n\nQue gardera-t-on de ce voyage ? Une phrase, une image, une certitude ébranlée, une conviction renforcée. Ce résidu de lecture, c'est la marque des livres qui comptent."],
        ['Épilogue',         "La dernière page tournée, le livre refermé, le lecteur reste un moment silencieux, habité par ce qu'il vient de vivre. C'est la marque des grandes œuvres : elles ne nous quittent pas vraiment.\n\n«\u{202F}{titre}\u{202F}», de {auteur}, rejoindra la liste de celles qui changent quelque chose en nous. Qui modifient notre regard sur le monde, sur les autres, sur nous-mêmes.\n\nLa lecture est terminée. La réflexion, elle, ne fait que commencer. Et c'est peut-être là la plus belle promesse que puisse faire un livre : continuer à vivre en nous, longtemps après qu'on l'a refermé.\n\nMerci à {auteur} pour ce cadeau. Revenez-y dans quelques mois — vous le lirez différemment, et ce sera comme le découvrir à nouveau pour la première fois."],
    ];

    // Répéter les chapitres si nbPages demande plus
    $pages = [];
    for ($i = 0; $i < max(6, min($nbPages, 7)); $i++) {
        $ch = $chapters[$i % count($chapters)];
        $text = str_replace(['{titre}', '{auteur}'], [$titre, $auteur], $ch[1]);
        $pages[] = "CHAPITRE " . ($i + 1) . " — " . $ch[0] . "\n\n" . $text;
    }

    return $pages;
}

// ─── HELPER : Générer PDF (FPDF ou fallback natif) ─────────────
function generatePDF(string $titre, string $auteur, array $pages, string $outputPath): bool
{
    // Essayer FPDF
    $fpdfPaths = [
        __DIR__ . '/../vendor/fpdf/fpdf.php',
        __DIR__ . '/../fpdf/fpdf.php',
        __DIR__ . '/../../fpdf/fpdf.php',
        __DIR__ . '/../vendor/setasign/fpdf/fpdf.php',
    ];
    foreach ($fpdfPaths as $fp) {
        if (file_exists($fp)) {
            require_once $fp;
            if (class_exists('FPDF')) {
                return _generateWithFPDF($titre, $auteur, $pages, $outputPath);
            }
        }
    }

    return _generateNativePDF($titre, $auteur, $pages, $outputPath);
}

function _generateWithFPDF(string $titre, string $auteur, array $pages, string $outputPath): bool
{
    try {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetCreator('Digital Library System');
        $pdf->SetAuthor($auteur);
        $pdf->SetTitle($titre);
        $pdf->SetMargins(22, 22, 22);
        $pdf->SetAutoPageBreak(true, 25);

        $conv = fn(string $s): string => iconv('UTF-8', 'windows-1252//TRANSLIT', $s) ?: $s;

        // ── Couverture ──
        $pdf->AddPage();
        $pdf->SetFillColor(7, 11, 20);
        $pdf->Rect(0, 0, 210, 297, 'F');
        $pdf->SetFillColor(20, 40, 80);
        $pdf->Rect(15, 15, 180, 267, 'F');
        // Ligne décorative
        $pdf->SetDrawColor(232, 201, 125);
        $pdf->SetLineWidth(0.8);
        $pdf->Rect(20, 20, 170, 257, 'D');

        $pdf->SetY(80);
        $pdf->SetTextColor(232, 201, 125);
        $pdf->SetFont('Helvetica', 'B', 24);
        $pdf->MultiCell(170, 11, $conv($titre), 0, 'C');

        $pdf->Ln(10);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->SetFont('Helvetica', 'I', 13);
        $pdf->Cell(170, 8, $conv('par ' . $auteur), 0, 1, 'C');

        $pdf->SetY(250);
        $pdf->SetTextColor(100, 120, 150);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(170, 5, 'Digital Library System — ' . date('Y'), 0, 1, 'C');

        // ── Pages de contenu ──
        foreach ($pages as $idx => $content) {
            $pdf->AddPage();
            $pdf->SetTextColor(20, 20, 20);
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $lineConv = $conv($line);
                if (preg_match('/^CHAPITRE/', $line)) {
                    $pdf->SetFont('Helvetica', 'B', 13);
                    $pdf->SetTextColor(7, 11, 20);
                    $pdf->Ln(3);
                    $pdf->MultiCell(166, 7, $lineConv, 0, 'L');
                    $pdf->SetFont('Helvetica', '', 10.5);
                    $pdf->SetTextColor(20, 20, 20);
                    $pdf->Ln(2);
                } elseif (trim($line) === '') {
                    $pdf->Ln(3);
                } else {
                    $pdf->SetFont('Helvetica', '', 10.5);
                    $pdf->MultiCell(166, 5.5, $lineConv, 0, 'J');
                }
            }
            $pdf->SetY(-14);
            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->Cell(0, 5, '— ' . ($idx + 2) . ' —', 0, 0, 'C');
        }

        $pdf->Output('F', $outputPath);
        return file_exists($outputPath) && filesize($outputPath) > 500;
    } catch (Throwable $e) {
        return _generateNativePDF($titre, $auteur, $pages, $outputPath);
    }
}

function _generateNativePDF(string $titre, string $auteur, array $pages, string $outputPath): bool
{
    $safe = static function (string $s): string {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: '';
        return preg_replace('/[()\\\\]/', '\\\\$0', $s);
    };

    $objects  = [];
    $offsets  = [];

    // Objet 1 : Catalog
    $nbContent = count($pages) + 1; // couverture + pages
    $pageRefs  = '';
    for ($i = 3; $i < 3 + $nbContent; $i++) {
        $pageRefs .= "$i 0 R ";
    }

    $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [{$pageRefs}] /Count {$nbContent} >>\nendobj\n";
    // Police
    $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

    // Couverture (objet 4 = contenu, objet 5 = page)
    $coverStream = "BT /F1 22 Tf 100 720 Td (" . $safe($titre) . ") Tj ET\n"
                 . "BT /F1 12 Tf 100 680 Td (par " . $safe($auteur) . ") Tj ET\n"
                 . "BT /F1 9 Tf 100 60 Td (Digital Library System) Tj ET";
    $coverLen    = strlen($coverStream);
    $objects[4]  = "4 0 obj\n<< /Length {$coverLen} >>\nstream\n{$coverStream}\nendstream\nendobj\n";
    $objects[5]  = "5 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 3 0 R >> >> >>\nendobj\n";

    // Pages de contenu
    $objIdx = 6;
    foreach ($pages as $p => $pageContent) {
        $lines  = explode("\n", $pageContent);
        $stream = '';
        $y      = 780;
        foreach (array_slice($lines, 0, 38) as $line) {
            $lineClean = $safe(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line) ?: $line);
            if ($lineClean === '') { $y -= 6; continue; }
            $fontSize = preg_match('/^CHAPITRE/', $line) ? 12 : 9.5;
            $stream  .= "BT /F1 {$fontSize} Tf 40 {$y} Td ({$lineClean}) Tj ET\n";
            $y       -= ($fontSize > 10 ? 16 : 13);
            if ($y < 50) break;
        }
        $streamLen = strlen($stream);
        $objects[$objIdx]     = "{$objIdx} 0 obj\n<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream\nendobj\n";
        $objects[$objIdx + 1] = ($objIdx + 1) . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents {$objIdx} 0 R /Resources << /Font << /F1 3 0 R >> >> >>\nendobj\n";
        $objIdx += 2;
    }

    // Assemblage
    $pdf = "%PDF-1.5\n";
    foreach ($objects as $num => $obj) {
        $offsets[$num] = strlen($pdf);
        $pdf          .= $obj;
    }

    $xrefOffset = strlen($pdf);
    $totalObj   = count($objects) + 1;
    $pdf .= "xref\n0 {$totalObj}\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($objects as $num => $obj) {
        $pdf .= str_pad((string)$offsets[$num], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size {$totalObj} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

    return (bool) file_put_contents($outputPath, $pdf);
}

// ─── CHARGER LES CATÉGORIES DEPUIS LA BD ──────────────────────
function getCategories(?PDO $pdo): array
{
    if ($pdo === null) return [];
    try {
        return $pdo->query(
            "SELECT id, nom, slug, icone FROM categories ORDER BY nom ASC"
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// ─── VALIDER QU'UNE CATÉGORIE EXISTE EN BD ────────────────────
function validateCategoryId(?PDO $pdo, int $catId): bool
{
    if ($pdo === null || $catId <= 0) return false;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id = ?");
        $stmt->execute([$catId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════
// GESTION DES REQUÊTES POST (AJAX)
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? 'create');

    // ── CRÉER UN LIVRE ────────────────────────────────────────
    if ($action === 'create') {
        $errors = [];

        // ─ Lecture et nettoyage des champs ─
        $titre   = trim($_POST['titre']       ?? '');
        $auteur  = trim($_POST['auteur']       ?? '');
        $desc    = trim($_POST['description']  ?? '');
        $prix    = max(0.0, (float)($_POST['prix'] ?? 0));
        $pages   = max(1, (int)($_POST['pages'] ?? 50));
        $editeur = trim($_POST['editeur']      ?? '');
        $annee   = (int)($_POST['annee_parution'] ?? date('Y'));
        $langue  = trim($_POST['langue']       ?? 'Français');
        $catId   = (int)($_POST['categorie_id'] ?? 0);

        // ─ Validations ─
        if (mb_strlen($titre) < 2)  $errors[] = 'Le titre est requis (minimum 2 caractères).';
        if (mb_strlen($auteur) < 2) $errors[] = "L'auteur est requis (minimum 2 caractères).";
        if ($prix < 0)              $errors[] = 'Le prix ne peut pas être négatif.';
        if ($annee > (int)date('Y') + 1 || ($annee < 1000 && $annee !== 0)) {
            $errors[] = 'Année de parution invalide.';
        }

        // ─ Validation CATÉGORIE contre la base de données ─
        if ($catId <= 0) {
            $errors[] = 'Veuillez sélectionner une catégorie valide.';
        } elseif (!validateCategoryId($pdo, $catId)) {
            $errors[] = "La catégorie sélectionnée n'existe pas dans la base de données. Impossible de créer le livre.";
        }

        if (!empty($errors)) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        // ─ Statut automatique selon le prix ─
        $statusInfo = getPremiumStatus($prix);

        // ─ Upload image de couverture ─
        $imgRelPath = null;
        if (!empty($_FILES['couverture']['name']) && $_FILES['couverture']['error'] !== UPLOAD_ERR_NO_FILE) {
            $res = uploadFichier($_FILES['couverture'], UPLOAD_IMG_DIR, ALLOWED_IMG, MAX_IMG_SIZE, 'cover');
            if (!$res['ok']) {
                echo json_encode(['success' => false, 'errors' => [$res['error']]]);
                exit;
            }
            $imgRelPath = 'uploads/images/' . $res['filename'];
        }

        // ─ Gestion du PDF ─
        $pdfRelPath      = null;
        $pdfWasGenerated = false;

        if (!empty($_FILES['fichier_pdf']['name']) && $_FILES['fichier_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            // PDF fourni manuellement
            $res2 = uploadFichier($_FILES['fichier_pdf'], UPLOAD_PDF_DIR, ALLOWED_PDF, MAX_PDF_SIZE, 'book');
            if (!$res2['ok']) {
                // Nettoyer l'image déjà uploadée
                if ($imgRelPath && file_exists(__DIR__ . '/../' . $imgRelPath)) @unlink(__DIR__ . '/../' . $imgRelPath);
                echo json_encode(['success' => false, 'errors' => [$res2['error']]]);
                exit;
            }
            // Vérifier le nombre de pages minimum
            $pdfPageCount = countPdfPages($res2['path']);
            if ($pdfPageCount < PDF_MIN_PAGES && $pdfPageCount > 0) {
                @unlink($res2['path']);
                if ($imgRelPath && file_exists(__DIR__ . '/../' . $imgRelPath)) @unlink(__DIR__ . '/../' . $imgRelPath);
                echo json_encode(['success' => false, 'errors' => [
                    "Le PDF fourni ne contient que {$pdfPageCount} page(s). Minimum requis : " . PDF_MIN_PAGES . " pages."
                ]]);
                exit;
            }
            $pdfRelPath = 'uploads/books/' . $res2['filename'];
        } else {
            // Génération automatique du PDF
            $pdfFilename = 'generated_' . bin2hex(random_bytes(8)) . '_' . time() . '.pdf';
            $pdfAbsPath  = UPLOAD_PDF_DIR . $pdfFilename;
            $bookPages   = generateBookContent($titre, $auteur, max(6, $pages));
            $pdfOk       = generatePDF($titre, $auteur, $bookPages, $pdfAbsPath);
            if ($pdfOk) {
                $pdfRelPath      = 'uploads/books/' . $pdfFilename;
                $pdfWasGenerated = true;
            }
        }

        // ─ Contenu extrait pour le lecteur intégré ─
        $bookPages    = generateBookContent($titre, $auteur, max(6, $pages));
        $extractedContent = implode('||||PAGE||||', $bookPages);

        // ─ Enregistrement en base de données ─
        if ($pdo !== null) {
            try {
                $sql = "INSERT INTO livres
                            (titre, auteur, description, prix, categorie_id,
                             couverture, fichier_pdf, contenu_extrait,
                             pages, editeur, annee_parution, langue,
                             statut, stock, access_type, created_at)
                        VALUES
                            (:titre, :auteur, :desc, :prix, :cat_id,
                             :couverture, :fichier_pdf, :extrait,
                             :pages, :editeur, :annee, :langue,
                             'disponible', 100, :access_type, NOW())";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':titre'       => $titre,
                    ':auteur'      => $auteur,
                    ':desc'        => $desc,
                    ':prix'        => $prix,
                    ':cat_id'      => $catId,
                    ':couverture'  => $imgRelPath,
                    ':fichier_pdf' => $pdfRelPath,
                    ':extrait'     => $extractedContent,
                    ':pages'       => $pages,
                    ':editeur'     => $editeur,
                    ':annee'       => $annee ?: null,
                    ':langue'      => $langue,
                    ':access_type' => $statusInfo['access_type'],
                ]);

                $newId = (int) $pdo->lastInsertId();

                echo json_encode([
                    'success'       => true,
                    'message'       => 'Livre ajouté avec succès !',
                    'id'            => $newId,
                    'titre'         => $titre,
                    'auteur'        => $auteur,
                    'prix'          => $prix,
                    'status_label'  => $statusInfo['label'],
                    'access_type'   => $statusInfo['access_type'],
                    'badge_class'   => $statusInfo['badge_class'],
                    'couverture'    => $imgRelPath,
                    'fichier_pdf'   => $pdfRelPath,
                    'pages'         => $pages,
                    'categorie_id'  => $catId,
                    'pdf_generated' => $pdfWasGenerated,
                ]);

            } catch (PDOException $e) {
                // Nettoyer les fichiers uploadés en cas d'erreur SQL
                if ($imgRelPath && file_exists(__DIR__ . '/../' . $imgRelPath)) @unlink(__DIR__ . '/../' . $imgRelPath);
                if ($pdfRelPath && file_exists(__DIR__ . '/../' . $pdfRelPath)) @unlink(__DIR__ . '/../' . $pdfRelPath);

                // Détecter les erreurs courantes
                $errCode = $e->errorInfo[1] ?? 0;
                $errMsg  = 'Erreur base de données.';
                if ($errCode === 1062) $errMsg = 'Ce livre existe déjà (titre en double ou ISBN en conflit).';
                if ($errCode === 1452) $errMsg = 'La catégorie sélectionnée n\'existe pas (contrainte de clé étrangère).';

                echo json_encode(['success' => false, 'errors' => [$errMsg, $e->getMessage()]]);
            }
        } else {
            echo json_encode(['success' => false, 'errors' => ['Base de données inaccessible. Vérifiez config/database.php.']]);
        }
        exit;
    }

    // ── METTRE À JOUR UN LIVRE ────────────────────────────────
    if ($action === 'update') {
        $errors = [];
        $id     = (int)($_POST['id'] ?? 0);
        $titre  = trim($_POST['titre']        ?? '');
        $auteur = trim($_POST['auteur']        ?? '');
        $desc   = trim($_POST['description']   ?? '');
        $prix   = max(0.0, (float)($_POST['prix'] ?? 0));
        $pages  = max(1, (int)($_POST['pages'] ?? 1));
        $editeur= trim($_POST['editeur']       ?? '');
        $annee  = (int)($_POST['annee_parution'] ?? date('Y'));
        $langue = trim($_POST['langue']        ?? 'Français');
        $catId  = (int)($_POST['categorie_id'] ?? 0);

        if (!$id)                   $errors[] = 'ID de livre invalide.';
        if (mb_strlen($titre) < 2)  $errors[] = 'Le titre est requis.';
        if (mb_strlen($auteur) < 2) $errors[] = "L'auteur est requis.";

        if ($catId <= 0) {
            $errors[] = 'Veuillez sélectionner une catégorie valide.';
        } elseif (!validateCategoryId($pdo, $catId)) {
            $errors[] = "La catégorie sélectionnée n'existe pas dans la base de données.";
        }

        if (!empty($errors)) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        $statusInfo = getPremiumStatus($prix);

        if ($pdo !== null) {
            try {
                // Récupérer les anciens fichiers
                $stmt = $pdo->prepare("SELECT couverture, fichier_pdf FROM livres WHERE id = ?");
                $stmt->execute([$id]);
                $oldData = $stmt->fetch();
                if (!$oldData) {
                    echo json_encode(['success' => false, 'errors' => ['Livre introuvable.']]);
                    exit;
                }

                $imgRelPath = $oldData['couverture'];
                $pdfRelPath = $oldData['fichier_pdf'];

                // Nouvelle image ?
                if (!empty($_FILES['couverture']['name']) && $_FILES['couverture']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $res = uploadFichier($_FILES['couverture'], UPLOAD_IMG_DIR, ALLOWED_IMG, MAX_IMG_SIZE, 'cover');
                    if ($res['ok']) {
                        if ($imgRelPath && file_exists(__DIR__ . '/../' . $imgRelPath)) @unlink(__DIR__ . '/../' . $imgRelPath);
                        $imgRelPath = 'uploads/images/' . $res['filename'];
                    } else {
                        echo json_encode(['success' => false, 'errors' => [$res['error']]]);
                        exit;
                    }
                }

                // Nouveau PDF ?
                if (!empty($_FILES['fichier_pdf']['name']) && $_FILES['fichier_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $res2 = uploadFichier($_FILES['fichier_pdf'], UPLOAD_PDF_DIR, ALLOWED_PDF, MAX_PDF_SIZE, 'book');
                    if ($res2['ok']) {
                        $pdfPageCount = countPdfPages($res2['path']);
                        if ($pdfPageCount < PDF_MIN_PAGES && $pdfPageCount > 0) {
                            @unlink($res2['path']);
                            echo json_encode(['success' => false, 'errors' => [
                                "Le PDF ne contient que {$pdfPageCount} page(s). Minimum : " . PDF_MIN_PAGES . "."
                            ]]);
                            exit;
                        }
                        if ($pdfRelPath && file_exists(__DIR__ . '/../' . $pdfRelPath)) @unlink(__DIR__ . '/../' . $pdfRelPath);
                        $pdfRelPath = 'uploads/books/' . $res2['filename'];
                    } else {
                        echo json_encode(['success' => false, 'errors' => [$res2['error']]]);
                        exit;
                    }
                }

                $stmt2 = $pdo->prepare(
                    "UPDATE livres SET
                        titre = :titre, auteur = :auteur, description = :desc,
                        prix = :prix, categorie_id = :cat_id,
                        couverture = :couv, fichier_pdf = :pdf,
                        pages = :pages, editeur = :editeur,
                        annee_parution = :annee, langue = :langue,
                        access_type = :access_type, updated_at = NOW()
                     WHERE id = :id"
                );
                $stmt2->execute([
                    ':titre'       => $titre,
                    ':auteur'      => $auteur,
                    ':desc'        => $desc,
                    ':prix'        => $prix,
                    ':cat_id'      => $catId,
                    ':couv'        => $imgRelPath,
                    ':pdf'         => $pdfRelPath,
                    ':pages'       => $pages,
                    ':editeur'     => $editeur,
                    ':annee'       => $annee ?: null,
                    ':langue'      => $langue,
                    ':access_type' => $statusInfo['access_type'],
                    ':id'          => $id,
                ]);

                echo json_encode([
                    'success'      => true,
                    'message'      => 'Livre mis à jour avec succès !',
                    'id'           => $id,
                    'titre'        => $titre,
                    'auteur'       => $auteur,
                    'prix'         => $prix,
                    'status_label' => $statusInfo['label'],
                    'access_type'  => $statusInfo['access_type'],
                    'couverture'   => $imgRelPath,
                    'fichier_pdf'  => $pdfRelPath,
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'errors' => ['Erreur SQL : ' . $e->getMessage()]]);
            }
        } else {
            echo json_encode(['success' => false, 'errors' => ['Base de données inaccessible.']]);
        }
        exit;
    }

    // ── SUPPRIMER UN LIVRE ────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'errors' => ['ID invalide.']]);
            exit;
        }

        if ($pdo !== null) {
            try {
                $stmt = $pdo->prepare("SELECT couverture, fichier_pdf FROM livres WHERE id = ?");
                $stmt->execute([$id]);
                $book = $stmt->fetch();
                if ($book) {
                    foreach (['couverture', 'fichier_pdf'] as $col) {
                        if (!empty($book[$col])) {
                            $fp = __DIR__ . '/../' . $book[$col];
                            if (file_exists($fp)) @unlink($fp);
                        }
                    }
                }
                $pdo->prepare("DELETE FROM livres WHERE id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Livre supprimé avec succès.', 'id' => $id]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'errors' => [$e->getMessage()]]);
            }
        } else {
            echo json_encode(['success' => false, 'errors' => ['Base de données inaccessible.']]);
        }
        exit;
    }

    // ── CHARGER UN LIVRE (édition) ─────────────────────────────
    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        if ($pdo && $id) {
            $stmt = $pdo->prepare(
                "SELECT l.*, c.nom AS categorie_nom, c.icone AS categorie_icone
                 FROM livres l
                 LEFT JOIN categories c ON c.id = l.categorie_id
                 WHERE l.id = ?"
            );
            $stmt->execute([$id]);
            $book = $stmt->fetch();
            echo json_encode(['success' => (bool)$book, 'book' => $book ?: null]);
        } else {
            echo json_encode(['success' => false, 'book' => null]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'errors' => ['Action inconnue : ' . htmlspecialchars($action)]]);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// CHARGEMENT DES DONNÉES POUR L'AFFICHAGE (GET)
// ═══════════════════════════════════════════════════════════════
$categories = getCategories($pdo);
$books      = [];
$totalBooks = 0;
$page       = max(1, (int)($_GET['p'] ?? 1));
$limit      = 12;
$offset     = ($page - 1) * $limit;
$search     = trim($_GET['q'] ?? '');
$filterCat  = (int)($_GET['cat'] ?? 0);

if ($pdo !== null) {
    try {
        // Construire la requête avec paramètres liés (anti-injection SQL)
        $conditions = ["l.statut != 'archive'"];
        $params     = [];

        if ($search !== '') {
            $conditions[] = "(l.titre LIKE :search OR l.auteur LIKE :search2)";
            $params[':search']  = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }

        if ($filterCat > 0) {
            $conditions[] = "l.categorie_id = :cat_filter";
            $params[':cat_filter'] = $filterCat;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $conditions);

        // COUNT total (requête séparée et correcte)
        $countSql  = "SELECT COUNT(*) FROM livres l {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalBooks = (int) $countStmt->fetchColumn();

        // Livres paginés
        $params[':limit']  = $limit;
        $params[':offset'] = $offset;

        $sql = "SELECT l.*, c.nom AS cat_nom, c.icone AS cat_icone
                FROM livres l
                LEFT JOIN categories c ON c.id = l.categorie_id
                {$whereClause}
                ORDER BY l.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            if ($key === ':limit' || $key === ':offset') {
                $stmt->bindValue($key, $val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $val);
            }
        }
        $stmt->execute();
        $books = $stmt->fetchAll();

    } catch (PDOException $e) {
        // Échec silencieux côté affichage, erreur loguée
        error_log('[DLS create.php] DB read error: ' . $e->getMessage());
    }
}

$totalPages = max(1, (int) ceil($totalBooks / $limit));

// Palettes de couleurs et emojis pour les couvertures sans image
$coverColors = [
    ['#0d1f3c','#1a4a7a'], ['#1a0d3c','#4a1a7a'], ['#0d3020','#1a6b3a'],
    ['#3c1a0d','#7a3a1a'], ['#0d2a3c','#1a5a7a'], ['#2a0d3c','#5a1a7a'],
    ['#1a2a0d','#3a6b1a'], ['#3c2a0d','#7a5a1a'], ['#0d3c2a','#1a7a5a'],
    ['#2a0d1a','#6b1a3a'], ['#0d1a3c','#1a3a7a'], ['#3c0d2a','#7a1a5a'],
];
$emojis = ['🌌','🧠','🌿','⚙️','📜','🎭','🔮','💡','🌊','🏔️','🦋','⚡','📖','🔭','🌍'];
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des Livres — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══════════════ RESET & VARS ══════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#07090f;--surface:#0e1120;--card:rgba(255,255,255,.032);
  --border:rgba(255,255,255,.08);--border-act:rgba(232,201,125,.4);
  --gold:#e8c97d;--ember:#ff6b35;--sage:#4ecca3;--azure:#4a9eff;
  --ruby:#ff5f57;--plum:#a78bfa;
  --txt:#f0eeea;--txt2:rgba(240,238,234,.56);--txt3:rgba(240,238,234,.28);
  --glass:rgba(255,255,255,.025);--gh:rgba(255,255,255,.06);
  --spring:cubic-bezier(.34,1.56,.64,1);--smooth:cubic-bezier(.25,.46,.45,.94);
  --r:10px;--r-lg:16px;--r-xl:22px;
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--txt);
  min-height:100vh;overflow-x:hidden;line-height:1.6}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:rgba(232,201,125,.35);border-radius:3px}

/* ── HEADER ── */
.hdr{
  position:sticky;top:0;z-index:800;height:58px;padding:0 1.6rem;
  display:flex;align-items:center;gap:.8rem;
  background:rgba(7,9,15,.9);backdrop-filter:blur(22px) saturate(1.3);
  border-bottom:1px solid var(--border);
}
.hdr-logo{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--txt);
  font-family:'Syne',sans-serif;font-size:.95rem;font-weight:800;letter-spacing:-.4px}
.logo-gem{width:32px;height:32px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,var(--gold),var(--ember));
  display:flex;align-items:center;justify-content:center;font-size:.9rem;
  box-shadow:0 0 18px rgba(232,201,125,.28)}
.hdr-search{flex:1;max-width:340px;position:relative}
.hdr-search input{width:100%;padding:7px 13px 7px 34px;border-radius:var(--r);
  background:var(--glass);border:1px solid var(--border);color:var(--txt);
  font-family:'DM Sans',sans-serif;font-size:.8rem;outline:none;transition:border-color .2s}
.hdr-search input:focus{border-color:var(--border-act)}
.hdr-search input::placeholder{color:var(--txt3)}
.hdr-search-ico{position:absolute;left:11px;top:50%;transform:translateY(-50%);
  color:var(--txt3);font-size:.8rem;pointer-events:none}
.hdr-right{display:flex;align-items:center;gap:.5rem;margin-left:auto}
.db-pill{display:inline-flex;align-items:center;gap:5px;font-family:'JetBrains Mono',monospace;
  font-size:.6rem;padding:3px 9px;border-radius:100px}
.db-ok{background:rgba(78,204,163,.1);color:var(--sage);border:1px solid rgba(78,204,163,.25)}
.db-fail{background:rgba(255,95,87,.1);color:var(--ruby);border:1px solid rgba(255,95,87,.25)}
.pulse{width:5px;height:5px;border-radius:50%;background:currentColor;animation:blink 1.4s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.15}}
.btn-hdr{font-family:'DM Sans',sans-serif;font-size:.75rem;font-weight:600;
  padding:6px 13px;border-radius:var(--r);cursor:pointer;transition:all .2s;
  display:inline-flex;align-items:center;gap:5px;text-decoration:none;border:none}
.btn-back{color:var(--txt2);background:var(--glass);border:1px solid var(--border)}
.btn-back:hover{border-color:var(--border-act);color:var(--gold)}
.btn-add{background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--bg);
  font-weight:700;box-shadow:0 4px 14px rgba(232,201,125,.2)}
.btn-add:hover{opacity:.87;transform:translateY(-1px)}

/* ══════════════ LAYOUT SPLIT ══════════════ */
.layout{display:grid;grid-template-columns:390px 1fr;min-height:calc(100vh - 58px)}
@media(max-width:1024px){.layout{grid-template-columns:1fr}}
.form-panel{
  background:var(--surface);border-right:1px solid var(--border);
  padding:1.6rem;position:sticky;top:58px;height:calc(100vh - 58px);
  overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(232,201,125,.2) transparent
}
@media(max-width:1024px){.form-panel{position:static;height:auto;border-right:none;border-bottom:1px solid var(--border)}}
.books-panel{padding:1.6rem;overflow-y:auto}

/* ══════════════ FORM STYLING ══════════════ */
.form-head{margin-bottom:1.4rem}
.form-title{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800;
  letter-spacing:-.4px;display:flex;align-items:center;gap:7px;margin-bottom:4px}
.form-subtitle{font-size:.75rem;color:var(--txt2)}

.f-label{font-size:.68rem;font-weight:600;color:var(--txt2);text-transform:uppercase;
  letter-spacing:.07em;display:block;margin-bottom:4px}
.f-input,.f-textarea,.f-select{
  width:100%;padding:9px 12px;border-radius:var(--r);
  background:rgba(255,255,255,.04);border:1px solid var(--border);
  color:var(--txt);font-family:'DM Sans',sans-serif;font-size:.83rem;
  outline:none;transition:border-color .2s,box-shadow .2s;line-height:1.5}
.f-input:focus,.f-textarea:focus,.f-select:focus{
  border-color:var(--border-act);box-shadow:0 0 0 3px rgba(232,201,125,.07)}
.f-input::placeholder,.f-textarea::placeholder{color:var(--txt3)}
.f-textarea{resize:vertical;min-height:80px}
.f-select{appearance:none;cursor:pointer;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 11px center;padding-right:30px}
.f-select option{background:var(--surface);color:var(--txt)}
.f-section{margin-bottom:1rem}
.f-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.f-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.7rem}
@media(max-width:540px){.f-row,.f-row-3{grid-template-columns:1fr}}

/* ── Statut auto badge ── */
.status-badge{
  display:inline-flex;align-items:center;gap:5px;margin-top:.45rem;
  font-family:'JetBrains Mono',monospace;font-size:.63rem;padding:3px 10px;border-radius:100px;
  transition:all .3s var(--spring);font-weight:600
}
.status-free{background:rgba(78,204,163,.1);color:var(--sage);border:1px solid rgba(78,204,163,.3)}
.status-prem{background:rgba(232,201,125,.1);color:var(--gold);border:1px solid rgba(232,201,125,.3)}

/* ── Catégorie : feedback de validation ── */
.cat-feedback{font-size:.68rem;margin-top:.35rem;display:none;
  padding:3px 9px;border-radius:6px;font-weight:500}
.cat-ok{display:inline-flex;align-items:center;gap:4px;
  background:rgba(78,204,163,.1);color:var(--sage)}
.cat-err{display:inline-flex;align-items:center;gap:4px;
  background:rgba(255,95,87,.1);color:var(--ruby)}
.f-select.f-err{border-color:rgba(255,95,87,.5);box-shadow:0 0 0 3px rgba(255,95,87,.07)}
.f-select.f-ok{border-color:rgba(78,204,163,.4)}

/* ── Drop zones ── */
.drop-zone{
  border:2px dashed var(--border);border-radius:var(--r-lg);
  padding:1.3rem;text-align:center;cursor:pointer;
  transition:border-color .25s,background .25s;position:relative;
  overflow:hidden;background:rgba(255,255,255,.02)
}
.drop-zone:hover,.drop-zone.drag-over{border-color:rgba(232,201,125,.4);background:rgba(232,201,125,.03)}
.drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.drop-icon{font-size:1.6rem;display:block;margin-bottom:.4rem}
.drop-text{font-size:.75rem;color:var(--txt2)}
.drop-text em{color:var(--gold);font-style:normal;font-weight:600}
.img-preview{width:100%;border-radius:var(--r);max-height:150px;object-fit:cover;
  margin-top:.6rem;display:none;border:1px solid var(--border)}
.pdf-indicator{display:none;margin-top:.5rem;font-size:.72rem;
  padding:5px 10px;border-radius:7px;
  background:rgba(78,204,163,.08);color:var(--sage);
  border:1px solid rgba(78,204,163,.2);align-items:center;gap:6px}
.pdf-size-err{background:rgba(255,95,87,.08);color:var(--ruby);border-color:rgba(255,95,87,.2)}
.pdf-pages-warn{background:rgba(255,107,53,.08);color:var(--ember);border-color:rgba(255,107,53,.2)}

/* ── Boutons formulaire ── */
.btn-submit{
  width:100%;padding:11px;border-radius:var(--r-lg);border:none;margin-top:.9rem;
  background:linear-gradient(135deg,var(--gold),var(--ember));color:var(--bg);
  font-family:'Syne',sans-serif;font-size:.9rem;font-weight:800;cursor:pointer;
  transition:all .2s;box-shadow:0 6px 20px rgba(232,201,125,.18);letter-spacing:-.3px;
  display:flex;align-items:center;justify-content:center;gap:7px
}
.btn-submit:hover:not(:disabled){opacity:.87;transform:translateY(-2px)}
.btn-submit:disabled{opacity:.4;cursor:not-allowed;transform:none}
.btn-cancel{width:100%;padding:9px;border-radius:var(--r);border:1px solid var(--border);
  background:var(--glass);color:var(--txt2);font-family:'DM Sans',sans-serif;
  font-size:.8rem;font-weight:600;cursor:pointer;transition:all .2s;margin-top:.4rem}
.btn-cancel:hover{border-color:rgba(255,95,87,.35);color:var(--ruby)}
.spinner{width:16px;height:16px;border-radius:50%;border:2px solid rgba(0,0,0,.2);
  border-top-color:var(--bg);animation:spin .7s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}

/* ══════════════ BOOKS PANEL ══════════════ */
.stats-row{display:flex;gap:1rem;margin-bottom:1.6rem;flex-wrap:wrap}
.stat-chip{background:var(--glass);border:1px solid var(--border);border-radius:var(--r-lg);
  padding:.65rem 1rem;display:flex;align-items:center;gap:.6rem}
.stat-v{font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800;letter-spacing:-1px;
  background:linear-gradient(135deg,var(--gold),var(--ember));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent}
.stat-l{font-size:.65rem;color:var(--txt3);text-transform:uppercase;letter-spacing:.09em;margin-top:1px}

.books-hdr{display:flex;align-items:center;justify-content:space-between;
  margin-bottom:1.2rem;flex-wrap:wrap;gap:.6rem}
.books-hdr-title{font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800;letter-spacing:-.4px}

/* Filtres catégories */
.cat-filter-row{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1.2rem}
.cat-filter-btn{font-size:.68rem;font-weight:600;padding:4px 12px;border-radius:100px;
  border:1px solid var(--border);background:var(--glass);color:var(--txt2);cursor:pointer;
  transition:all .2s;font-family:'DM Sans',sans-serif;display:flex;align-items:center;gap:4px}
.cat-filter-btn:hover,.cat-filter-btn.active{
  border-color:rgba(74,158,255,.4);color:var(--azure);background:rgba(74,158,255,.07)}

/* Vue toggle */
.view-toggle{display:flex;gap:3px}
.vt{width:30px;height:30px;border-radius:7px;background:var(--glass);
  border:1px solid var(--border);color:var(--txt3);cursor:pointer;
  display:flex;align-items:center;justify-content:center;font-size:.8rem;transition:all .2s}
.vt.active,.vt:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}

/* ══════════════ BOOK CARDS ══════════════ */
.books-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:1rem}
.books-grid.list-view{grid-template-columns:1fr}
.bk{
  background:var(--glass);border:1px solid var(--border);border-radius:var(--r-lg);
  overflow:hidden;cursor:pointer;position:relative;
  transition:transform .3s var(--spring),border-color .3s,box-shadow .3s;
  animation:fadeUp .4s var(--spring) both;opacity:0
}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.bk:hover{transform:translateY(-6px) scale(1.015);border-color:rgba(232,201,125,.22);
  box-shadow:0 18px 50px rgba(0,0,0,.5),0 0 24px rgba(232,201,125,.06)}
.bk-cover{height:155px;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden}
.bk-cover img{width:100%;height:100%;object-fit:cover;transition:transform .4s var(--smooth)}
.bk:hover .bk-cover img{transform:scale(1.06)}
.bk-cover-grad{position:absolute;inset:0}
.bk-cover-emoji{font-size:2.8rem;position:relative;z-index:1;filter:drop-shadow(0 4px 10px rgba(0,0,0,.5));
  transition:transform .3s var(--spring)}
.bk:hover .bk-cover-emoji{transform:scale(1.12) rotate(-5deg)}
.bk-vignette{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(7,9,15,.88));z-index:2}
/* Access badge */
.bk-badge{position:absolute;top:7px;left:7px;z-index:4;font-family:'JetBrains Mono',monospace;
  font-size:.56rem;padding:2px 7px;border-radius:100px;font-weight:700}
.bk-badge-free{background:rgba(78,204,163,.15);color:var(--sage);border:1px solid rgba(78,204,163,.3)}
.bk-badge-prem{background:rgba(232,201,125,.15);color:var(--gold);border:1px solid rgba(232,201,125,.3)}
/* Cat badge */
.bk-cat{position:absolute;top:7px;right:7px;z-index:4;font-size:.7rem}
/* Overlay */
.bk-overlay{position:absolute;inset:0;background:rgba(7,9,15,.87);z-index:10;
  display:flex;align-items:center;justify-content:center;gap:.5rem;
  opacity:0;transition:opacity .22s;border-radius:var(--r-lg)}
.bk:hover .bk-overlay{opacity:1}
.ov-btn{padding:6px 13px;border-radius:7px;border:none;font-size:.7rem;font-weight:700;
  cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif;
  display:flex;align-items:center;gap:4px}
.ov-edit{background:rgba(232,201,125,.15);color:var(--gold);border:1px solid rgba(232,201,125,.3)}
.ov-edit:hover{background:rgba(232,201,125,.28)}
.ov-del{background:rgba(255,95,87,.1);color:var(--ruby);border:1px solid rgba(255,95,87,.25)}
.ov-del:hover{background:rgba(255,95,87,.22)}
/* Body */
.bk-body{padding:.85rem .9rem .9rem}
.bk-cat-label{font-family:'JetBrains Mono',monospace;font-size:.56rem;color:var(--azure);
  text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px}
.bk-title{font-family:'Syne',sans-serif;font-size:.84rem;font-weight:700;letter-spacing:-.2px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.bk-author{font-size:.7rem;color:var(--txt2);margin-bottom:.5rem;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bk-footer{display:flex;align-items:center;justify-content:space-between}
.bk-price{font-family:'Syne',sans-serif;font-size:.84rem;font-weight:800;color:var(--gold)}
.bk-price.free{color:var(--sage)}
.bk-pages{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--txt3)}

/* List view */
.books-grid.list-view .bk{display:flex;align-items:stretch}
.books-grid.list-view .bk-cover{width:78px;height:78px;flex-shrink:0;border-radius:var(--r) 0 0 var(--r)}
.books-grid.list-view .bk-body{flex:1;padding:.7rem .85rem;display:flex;
  flex-direction:column;justify-content:center}
.books-grid.list-view .bk-overlay{border-radius:0 var(--r-lg) var(--r-lg) 0}

/* ══════════════ PAGINATION ══════════════ */
.pagination{display:flex;gap:.4rem;justify-content:center;margin-top:1.8rem;flex-wrap:wrap}
.pg{width:34px;height:34px;border-radius:var(--r);background:var(--glass);
  border:1px solid var(--border);color:var(--txt2);cursor:pointer;font-size:.78rem;
  font-family:'DM Sans',sans-serif;display:flex;align-items:center;justify-content:center;transition:all .2s}
.pg:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}
.pg.active{background:rgba(232,201,125,.1);border-color:var(--gold);color:var(--gold);font-weight:700}
.pg:disabled{opacity:.3;cursor:not-allowed}

/* ══════════════ EMPTY STATE ══════════════ */
.empty{text-align:center;padding:3.5rem 2rem;color:var(--txt3);grid-column:1/-1}
.empty i{font-size:2.8rem;display:block;margin-bottom:.8rem;opacity:.35}
.empty h3{font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;margin-bottom:.3rem}
.empty p{font-size:.78rem}

/* ══════════════ TOAST ══════════════ */
#toast{
  position:fixed;bottom:1.8rem;right:1.8rem;z-index:9900;
  background:rgba(14,17,32,.97);border:1px solid rgba(232,201,125,.2);
  border-radius:var(--r-xl);padding:.9rem 1.2rem;display:flex;align-items:center;gap:10px;
  font-size:.78rem;backdrop-filter:blur(22px);
  box-shadow:0 8px 32px rgba(0,0,0,.45),0 0 30px rgba(232,201,125,.07);
  transform:translateY(90px) scale(.96);opacity:0;
  transition:all .38s var(--spring);pointer-events:none;max-width:300px;min-width:220px
}
#toast.show{transform:translateY(0) scale(1);opacity:1;pointer-events:auto}
#toast.t-success{border-color:rgba(78,204,163,.3)}
#toast.t-error{border-color:rgba(255,95,87,.35)}
#toast.t-warn{border-color:rgba(255,107,53,.35)}
.t-icon{font-size:1rem;flex-shrink:0}
.t-body{flex:1}
.t-title{font-family:'Syne',sans-serif;font-weight:700;font-size:.8rem}
.t-msg{font-size:.68rem;color:var(--txt3);margin-top:1px}

/* ══════════════ CONFIRM MODAL ══════════════ */
#confirm-modal{position:fixed;inset:0;z-index:9800;
  display:flex;align-items:center;justify-content:center;padding:1rem;
  opacity:0;visibility:hidden;transition:all .28s}
#confirm-modal.open{opacity:1;visibility:visible}
.cm-bg{position:absolute;inset:0;background:rgba(7,9,15,.88);backdrop-filter:blur(14px)}
.cm-box{position:relative;z-index:1;background:var(--surface);border:1px solid var(--border);
  border-radius:var(--r-xl);padding:2rem;max-width:350px;width:100%;text-align:center;
  box-shadow:0 40px 80px rgba(0,0,0,.55);transform:scale(.95);transition:transform .3s var(--spring)}
#confirm-modal.open .cm-box{transform:scale(1)}
.cm-icon{font-size:2.4rem;margin-bottom:.7rem}
.cm-title{font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:800;margin-bottom:.35rem}
.cm-desc{font-size:.78rem;color:var(--txt2);margin-bottom:1.4rem}
.cm-btns{display:flex;gap:.6rem}
.btn-cm-yes{flex:1;padding:10px;border-radius:var(--r);border:none;
  background:linear-gradient(135deg,#ff5f57,#ff3b30);color:#fff;
  font-family:'Syne',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:opacity .2s}
.btn-cm-yes:hover{opacity:.84}
.btn-cm-no{flex:1;padding:10px;border-radius:var(--r);border:1px solid var(--border);
  background:var(--glass);color:var(--txt2);font-family:'Syne',sans-serif;
  font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s}
.btn-cm-no:hover{border-color:rgba(232,201,125,.3);color:var(--gold)}

/* ══════════════ RESPONSIVE ══════════════ */
@media(max-width:640px){
  .hdr{padding:0 .9rem}
  .books-panel,.form-panel{padding:1rem}
  .stats-row{gap:.6rem}
  .books-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<!-- ══════ HEADER ══════ -->
<header class="hdr">
  <a href="../index.php" class="hdr-logo">
    <div class="logo-gem">📚</div>
    Digital <span style="color:var(--gold)">Library</span>
  </a>

  <div class="hdr-search">
    <i class="bi bi-search hdr-search-ico"></i>
    <input type="text" id="search-input" placeholder="Rechercher titre, auteur…"
           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
  </div>

  <div class="hdr-right">
    <span class="db-pill <?= $pdo ? 'db-ok' : 'db-fail' ?>">
      <span class="pulse"></span>
      <?= $pdo ? 'BD connectée' : 'BD hors ligne' ?>
    </span>
    <a href="../dashboard.php" class="btn-hdr btn-back">
      <i class="bi bi-arrow-left"></i> Dashboard
    </a>
    <button class="btn-hdr btn-add" onclick="openAddForm()">
      <i class="bi bi-plus-lg"></i> Nouveau livre
    </button>
  </div>
</header>

<!-- ══════ LAYOUT ══════ -->
<div class="layout">

  <!-- ══════ PANNEAU FORMULAIRE ══════ -->
  <aside class="form-panel" id="form-panel">
    <div class="form-head">
      <div class="form-title" id="form-title-txt">
        <i class="bi bi-book-fill" style="color:var(--gold)"></i>
        Ajouter un livre
      </div>
      <div class="form-subtitle" id="form-subtitle-txt">
        Remplissez tous les champs requis. Le statut premium/gratuit est calculé automatiquement selon le prix.
      </div>
    </div>

    <form id="book-form" enctype="multipart/form-data" novalidate autocomplete="off">
      <input type="hidden" id="f-action"  name="action" value="create">
      <input type="hidden" id="f-id"      name="id" value="">

      <!-- TITRE -->
      <div class="f-section">
        <label class="f-label" for="f-titre">Titre du livre *</label>
        <input type="text" class="f-input" id="f-titre" name="titre"
               placeholder="Ex. : L'Œil de l'Univers" required maxlength="255">
      </div>

      <!-- AUTEUR -->
      <div class="f-section">
        <label class="f-label" for="f-auteur">Auteur *</label>
        <input type="text" class="f-input" id="f-auteur" name="auteur"
               placeholder="Ex. : Elena Korvach" required maxlength="150">
      </div>

      <!-- PRIX + PAGES -->
      <div class="f-row">
        <div class="f-section">
          <label class="f-label" for="f-prix">Prix (FCFA)</label>
          <input type="number" class="f-input" id="f-prix" name="prix"
                 placeholder="0" min="0" step="100" value="0">
          <div id="status-badge-wrap">
            <span class="status-badge status-free" id="status-badge">🟢 Gratuit (auto)</span>
          </div>
        </div>
        <div class="f-section">
          <label class="f-label" for="f-pages">Nombre de pages</label>
          <input type="number" class="f-input" id="f-pages" name="pages"
                 placeholder="100" min="1" max="5000" value="100">
        </div>
      </div>

      <!-- CATÉGORIE — Sélection obligatoire, validée contre la BD -->
      <div class="f-section">
        <label class="f-label" for="f-categorie">
          Catégorie *
          <span style="color:var(--txt3);font-size:.6rem;text-transform:none;font-weight:400;margin-left:4px">
            (doit exister en base de données)
          </span>
        </label>
        <?php if (empty($categories)): ?>
          <div style="padding:9px 12px;border-radius:var(--r);background:rgba(255,95,87,.08);
                      border:1px solid rgba(255,95,87,.25);color:var(--ruby);font-size:.78rem">
            <i class="bi bi-exclamation-triangle-fill"></i>
            Aucune catégorie trouvée en base de données.
            Créez d'abord des catégories via <a href="../admin/categories.php" style="color:var(--gold)">admin/categories.php</a>
            ou exécutez le script SQL de migration.
          </div>
          <input type="hidden" name="categorie_id" value="0">
        <?php else: ?>
          <select class="f-select" id="f-categorie" name="categorie_id"
                  required onchange="validateCategorySelect()">
            <option value="" disabled selected>— Choisir une catégorie —</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>">
                <?= htmlspecialchars(($cat['icone'] ?? '') . ' ' . ($cat['nom'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="cat-feedback" id="cat-feedback"></div>
        <?php endif; ?>
      </div>

      <!-- ÉDITEUR + ANNÉE -->
      <div class="f-row">
        <div class="f-section">
          <label class="f-label" for="f-editeur">Éditeur</label>
          <input type="text" class="f-input" id="f-editeur" name="editeur"
                 placeholder="Ex. : Gallimard" maxlength="150">
        </div>
        <div class="f-section">
          <label class="f-label" for="f-annee">Année de parution</label>
          <input type="number" class="f-input" id="f-annee" name="annee_parution"
                 placeholder="<?= date('Y') ?>" min="1800" max="<?= date('Y') + 1 ?>"
                 value="<?= date('Y') ?>">
        </div>
      </div>

      <!-- LANGUE -->
      <div class="f-section">
        <label class="f-label" for="f-langue">Langue</label>
        <select class="f-select f-input" id="f-langue" name="langue">
          <option value="Français"  selected>🇫🇷 Français</option>
          <option value="Anglais">🇬🇧 Anglais</option>
          <option value="Espagnol">🇪🇸 Espagnol</option>
          <option value="Arabe">🇸🇦 Arabe</option>
          <option value="Allemand">🇩🇪 Allemand</option>
          <option value="Portugais">🇵🇹 Portugais</option>
        </select>
      </div>

      <!-- DESCRIPTION -->
      <div class="f-section">
        <label class="f-label" for="f-desc">Description / Synopsis</label>
        <textarea class="f-textarea" id="f-desc" name="description"
                  placeholder="Résumé du livre, thèmes abordés…" maxlength="2000" rows="3"></textarea>
      </div>

      <!-- IMAGE DE COUVERTURE -->
      <div class="f-section">
        <label class="f-label">
          Image de couverture
          <span style="color:var(--txt3);font-size:.6rem;text-transform:none;font-weight:400;margin-left:4px">
            JPG / PNG / WebP — max 5 Mo
          </span>
        </label>
        <div class="drop-zone" id="drop-img"
             ondragover="onDragOver(event,'drop-img')"
             ondragleave="onDragLeave('drop-img')"
             ondrop="onDrop(event,'drop-img','f-couverture',onImageFile)">
          <input type="file" id="f-couverture" name="couverture"
                 accept="image/jpeg,image/png,image/webp,image/gif"
                 onchange="onImageFile(this.files[0])">
          <span class="drop-icon" id="drop-img-icon">🖼️</span>
          <div class="drop-text" id="drop-img-text">Glissez une image ici ou <em>parcourir</em></div>
        </div>
        <img id="img-preview" class="img-preview" alt="Aperçu couverture">
      </div>

      <!-- FICHIER PDF -->
      <div class="f-section">
        <label class="f-label">
          Fichier PDF
          <span style="color:var(--sage);font-size:.6rem;text-transform:none;font-weight:400;margin-left:4px">
            ✨ Généré automatiquement si non fourni — min <?= PDF_MIN_PAGES ?> pages
          </span>
        </label>
        <div class="drop-zone" id="drop-pdf"
             ondragover="onDragOver(event,'drop-pdf')"
             ondragleave="onDragLeave('drop-pdf')"
             ondrop="onDrop(event,'drop-pdf','f-pdf',onPdfFile)">
          <input type="file" id="f-pdf" name="fichier_pdf"
                 accept="application/pdf"
                 onchange="onPdfFile(this.files[0])">
          <span class="drop-icon" id="drop-pdf-icon">📄</span>
          <div class="drop-text" id="drop-pdf-text">Glissez un PDF ici ou <em>parcourir</em></div>
        </div>
        <div class="pdf-indicator" id="pdf-indicator">
          <i class="bi bi-check-circle-fill"></i>
          <span id="pdf-indicator-text">PDF sélectionné</span>
        </div>
      </div>

      <!-- BOUTON SOUMETTRE -->
      <button type="submit" class="btn-submit" id="btn-submit">
        <i class="bi bi-plus-circle"></i>
        <span id="btn-submit-txt">Ajouter le livre</span>
      </button>
      <button type="button" class="btn-cancel" id="btn-cancel"
              style="display:none" onclick="resetForm()">
        Annuler l'édition
      </button>
    </form>
  </aside>

  <!-- ══════ PANNEAU LIVRES ══════ -->
  <main class="books-panel">

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-chip">
        <div>
          <div class="stat-v" id="stat-total"><?= $totalBooks ?></div>
          <div class="stat-l">Livres total</div>
        </div>
      </div>
      <?php
      $cntFree = $cntPrem = 0;
      if ($pdo) {
          try {
              $row = $pdo->query(
                  "SELECT
                    SUM(CASE WHEN prix = 0 OR access_type = 'gratuit' THEN 1 ELSE 0 END) AS free,
                    SUM(CASE WHEN prix > 0 AND (access_type = 'premium' OR access_type IS NULL) THEN 1 ELSE 0 END) AS prem
                   FROM livres WHERE statut != 'archive'"
              )->fetch();
              $cntFree = (int)($row['free'] ?? 0);
              $cntPrem = (int)($row['prem'] ?? 0);
          } catch (PDOException $e) {}
      }
      ?>
      <div class="stat-chip">
        <div>
          <div class="stat-v" id="stat-free" style="background:linear-gradient(135deg,var(--sage),#00a882);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
            <?= $cntFree ?>
          </div>
          <div class="stat-l">Gratuits</div>
        </div>
      </div>
      <div class="stat-chip">
        <div>
          <div class="stat-v" id="stat-prem">
            <?= $cntPrem ?>
          </div>
          <div class="stat-l">Premium</div>
        </div>
      </div>
      <?php
      $catCount = count($categories);
      ?>
      <div class="stat-chip">
        <div>
          <div class="stat-v" style="background:linear-gradient(135deg,var(--azure),var(--plum));-webkit-background-clip:text;-webkit-text-fill-color:transparent">
            <?= $catCount ?>
          </div>
          <div class="stat-l">Catégories</div>
        </div>
      </div>
    </div>

    <!-- En-tête + filtres -->
    <div class="books-hdr">
      <div class="books-hdr-title">
        Catalogue
        <?php if ($search): ?>
          <span style="color:var(--gold);font-size:.88rem;font-weight:600;margin-left:.4rem">
            — "<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
          </span>
        <?php endif; ?>
      </div>
      <div class="view-toggle">
        <button class="vt active" id="vt-grid" onclick="setView('grid')" title="Grille">
          <i class="bi bi-grid-3x3-gap"></i>
        </button>
        <button class="vt" id="vt-list" onclick="setView('list')" title="Liste">
          <i class="bi bi-list-ul"></i>
        </button>
      </div>
    </div>

    <!-- Filtres catégories -->
    <?php if (!empty($categories)): ?>
    <div class="cat-filter-row">
      <button class="cat-filter-btn <?= $filterCat === 0 ? 'active' : '' ?>"
              onclick="filterByCat(0)">
        📋 Toutes
      </button>
      <?php foreach ($categories as $cat): ?>
      <button class="cat-filter-btn <?= $filterCat === (int)$cat['id'] ? 'active' : '' ?>"
              onclick="filterByCat(<?= (int)$cat['id'] ?>)">
        <?= htmlspecialchars(($cat['icone'] ?? '') . ' ' . ($cat['nom'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Grille de livres -->
    <div class="books-grid" id="books-grid">
      <?php if (empty($books)): ?>
        <div class="empty">
          <i class="bi bi-book"></i>
          <h3><?= $search ? 'Aucun résultat' : 'Aucun livre' ?></h3>
          <p><?= $search
              ? 'Essayez un autre terme de recherche.'
              : 'Ajoutez votre premier livre via le formulaire.' ?></p>
        </div>
      <?php else: ?>
        <?php foreach ($books as $i => $book):
          $prix     = (float)($book['prix'] ?? 0);
          $isFree   = $prix <= 0 || ($book['access_type'] ?? '') === 'gratuit';
          $badgeCls = $isFree ? 'bk-badge-free' : 'bk-badge-prem';
          $badgeLbl = $isFree ? 'GRATUIT' : 'PREMIUM';
          $priceLbl = $isFree ? 'Gratuit' : number_format($prix, 0, '.', ' ') . ' FCFA';
          $priceClass = $isFree ? 'free' : '';
          $colors   = $coverColors[$i % count($coverColors)];
          $emoji    = $emojis[$i % count($emojis)];
          $hasImg   = !empty($book['couverture'])
                      && file_exists(__DIR__ . '/../' . $book['couverture']);
        ?>
        <div class="bk" id="bk-<?= $book['id'] ?>"
             style="animation-delay:<?= ($i % 12) * 0.05 ?>s">
          <div class="bk-cover">
            <?php if ($hasImg): ?>
              <img src="../<?= htmlspecialchars($book['couverture'], ENT_QUOTES, 'UTF-8') ?>"
                   alt="<?= htmlspecialchars($book['titre'], ENT_QUOTES, 'UTF-8') ?>"
                   loading="lazy">
            <?php else: ?>
              <div class="bk-cover-grad"
                   style="background:linear-gradient(135deg,<?= $colors[0] ?>,<?= $colors[1] ?>)">
              </div>
              <div class="bk-cover-emoji"><?= $emoji ?></div>
            <?php endif; ?>
            <div class="bk-vignette"></div>
            <span class="bk-badge <?= $badgeCls ?>"><?= $badgeLbl ?></span>
            <?php if (!empty($book['cat_icone'])): ?>
              <span class="bk-cat" title="<?= htmlspecialchars($book['cat_nom'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($book['cat_icone'], ENT_QUOTES, 'UTF-8') ?>
              </span>
            <?php endif; ?>
            <div class="bk-overlay">
              <button class="ov-btn ov-edit"
                      onclick="editBook(<?= (int)$book['id'] ?>)">
                <i class="bi bi-pencil"></i> Éditer
              </button>
              <button class="ov-btn ov-del"
                      onclick="confirmDelete(<?= (int)$book['id'] ?>, '<?= addslashes(htmlspecialchars($book['titre'], ENT_QUOTES, 'UTF-8')) ?>')">
                <i class="bi bi-trash3"></i>
              </button>
            </div>
          </div>
          <div class="bk-body">
            <?php if (!empty($book['cat_nom'])): ?>
              <div class="bk-cat-label">
                <?= htmlspecialchars(($book['cat_icone'] ?? '') . ' ' . ($book['cat_nom'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </div>
            <?php endif; ?>
            <div class="bk-title" title="<?= htmlspecialchars($book['titre'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($book['titre'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="bk-author">
              <?= htmlspecialchars($book['auteur'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="bk-footer">
              <span class="bk-price <?= $priceClass ?>"><?= $priceLbl ?></span>
              <span class="bk-pages"><?= (int)($book['pages'] ?? 0) ?>p</span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination" id="pagination">
      <button class="pg" <?= $page <= 1 ? 'disabled' : '' ?>
              onclick="goPage(<?= $page - 1 ?>)">
        <i class="bi bi-chevron-left"></i>
      </button>
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <button class="pg <?= $p == $page ? 'active' : '' ?>"
                onclick="goPage(<?= $p ?>)"><?= $p ?></button>
      <?php endfor; ?>
      <button class="pg" <?= $page >= $totalPages ? 'disabled' : '' ?>
              onclick="goPage(<?= $page + 1 ?>)">
        <i class="bi bi-chevron-right"></i>
      </button>
    </div>
    <?php endif; ?>

  </main>
</div><!-- /layout -->

<!-- ══════ TOAST ══════ -->
<div id="toast">
  <span class="t-icon" id="t-icon">✅</span>
  <div class="t-body">
    <div class="t-title" id="t-title"></div>
    <div class="t-msg"   id="t-msg"></div>
  </div>
</div>

<!-- ══════ CONFIRM MODAL ══════ -->
<div id="confirm-modal">
  <div class="cm-bg" onclick="closeConfirm()"></div>
  <div class="cm-box">
    <div class="cm-icon">🗑️</div>
    <div class="cm-title">Supprimer ce livre ?</div>
    <div class="cm-desc" id="cm-desc">Cette action est irréversible.</div>
    <div class="cm-btns">
      <button class="btn-cm-yes" id="btn-cm-yes">Supprimer</button>
      <button class="btn-cm-no"  onclick="closeConfirm()">Annuler</button>
    </div>
  </div>
</div>

<script>
'use strict';

// ── Config ────────────────────────────────────────────────────
const SELF      = window.location.pathname;
const PDF_MIN   = <?= PDF_MIN_PAGES ?>;
let toastTimer  = null;
let searchTimer = null;
let deleteId    = null;
let currentView = localStorage.getItem('bk_view') || 'grid';

// ── TOAST ─────────────────────────────────────────────────────
const TOAST_ICONS = {success:'✅',error:'❌',warn:'⚠️',info:'ℹ️'};
function showToast(title, msg = '', type = 'info', dur = 4500) {
  const el = document.getElementById('toast');
  el.className = type !== 'info' ? 't-' + type : '';
  document.getElementById('t-icon').textContent  = TOAST_ICONS[type] || '•';
  document.getElementById('t-title').textContent = title;
  document.getElementById('t-msg').textContent   = msg;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), dur);
}

// ── PRIX → STATUT AUTOMATIQUE ─────────────────────────────────
const priceInput = document.getElementById('f-prix');
const statusBadge = document.getElementById('status-badge');

function updateStatusBadge() {
  const v = parseFloat(priceInput.value) || 0;
  if (v <= 0) {
    statusBadge.className = 'status-badge status-free';
    statusBadge.textContent = '🟢 Gratuit (automatique)';
  } else {
    statusBadge.className = 'status-badge status-prem';
    statusBadge.textContent = '🌟 Premium (automatique)';
  }
}
priceInput?.addEventListener('input', updateStatusBadge);

// ── VALIDATION CATÉGORIE CLIENT ────────────────────────────────
function validateCategorySelect() {
  const sel = document.getElementById('f-categorie');
  const fb  = document.getElementById('cat-feedback');
  if (!sel || !fb) return true;
  const val = parseInt(sel.value) || 0;
  if (val > 0) {
    sel.classList.replace('f-err', 'f-ok') || sel.classList.add('f-ok');
    fb.className   = 'cat-feedback cat-ok';
    fb.style.display = 'inline-flex';
    fb.innerHTML   = '<i class="bi bi-check-circle-fill"></i> Catégorie valide';
    return true;
  } else {
    sel.classList.replace('f-ok', 'f-err') || sel.classList.add('f-err');
    fb.className   = 'cat-feedback cat-err';
    fb.style.display = 'inline-flex';
    fb.innerHTML   = '<i class="bi bi-x-circle-fill"></i> Sélectionnez une catégorie existante';
    return false;
  }
}

// ── DRAG & DROP générique ─────────────────────────────────────
function onDragOver(e, zoneId) {
  e.preventDefault();
  document.getElementById(zoneId)?.classList.add('drag-over');
}
function onDragLeave(zoneId) {
  document.getElementById(zoneId)?.classList.remove('drag-over');
}
function onDrop(e, zoneId, inputId, callback) {
  e.preventDefault();
  document.getElementById(zoneId)?.classList.remove('drag-over');
  const file = e.dataTransfer?.files[0];
  if (!file) return;
  const input = document.getElementById(inputId);
  if (input) {
    try {
      const dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
    } catch(err) {}
  }
  callback(file);
}

// ── IMAGE DROP / SELECT ───────────────────────────────────────
function onImageFile(file) {
  if (!file) return;
  const allowedTypes = ['image/jpeg','image/png','image/webp','image/gif'];
  if (!allowedTypes.includes(file.type)) {
    showToast('Format invalide', 'Formats acceptés : JPG, PNG, WebP.', 'error');
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    showToast('Image trop lourde', `${(file.size / 1024 / 1024).toFixed(1)} Mo — max 5 Mo.`, 'error');
    return;
  }
  document.getElementById('drop-img-icon').textContent = '✅';
  document.getElementById('drop-img-text').innerHTML =
    `<strong>${escHtml(file.name)}</strong> <span style="color:var(--txt3)">(${(file.size/1024).toFixed(0)} Ko)</span>`;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('img-preview');
    prev.src = e.target.result;
    prev.style.display = 'block';
  };
  reader.readAsDataURL(file);
}

// ── PDF DROP / SELECT ─────────────────────────────────────────
function onPdfFile(file) {
  if (!file) return;
  const ind   = document.getElementById('pdf-indicator');
  const indTxt = document.getElementById('pdf-indicator-text');

  if (file.type !== 'application/pdf') {
    showToast('Fichier invalide', 'Seuls les fichiers PDF sont acceptés.', 'error');
    document.getElementById('f-pdf').value = '';
    return;
  }
  if (file.size > 50 * 1024 * 1024) {
    showToast('PDF trop lourd', `${(file.size / 1024 / 1024).toFixed(1)} Mo — max 50 Mo.`, 'error');
    document.getElementById('f-pdf').value = '';
    return;
  }

  document.getElementById('drop-pdf-icon').textContent = '✅';
  document.getElementById('drop-pdf-text').innerHTML =
    `<strong>${escHtml(file.name)}</strong> <span style="color:var(--sage)">(${(file.size/1024/1024).toFixed(2)} Mo)</span>`;

  ind.className = 'pdf-indicator';
  ind.style.display = 'flex';
  indTxt.textContent = `${file.name} — ${(file.size/1024/1024).toFixed(2)} Mo (validation serveur au dépôt)`;
}

// ── SOUMISSION FORMULAIRE ─────────────────────────────────────
const bookForm  = document.getElementById('book-form');
const btnSubmit = document.getElementById('btn-submit');

bookForm.addEventListener('submit', async e => {
  e.preventDefault();

  // Validations client
  const titre  = document.getElementById('f-titre').value.trim();
  const auteur = document.getElementById('f-auteur').value.trim();

  if (titre.length < 2)  { showToast('Titre requis',  'Minimum 2 caractères.', 'error'); return; }
  if (auteur.length < 2) { showToast('Auteur requis', 'Minimum 2 caractères.', 'error'); return; }
  if (!validateCategorySelect())               { showToast('Catégorie requise', 'Choisissez une catégorie existante.', 'error'); return; }

  const isEdit = document.getElementById('f-action').value === 'update';

  // Loader
  btnSubmit.disabled = true;
  btnSubmit.innerHTML = '<div class="spinner"></div><span>Traitement en cours…</span>';

  const formData = new FormData(bookForm);

  try {
    const resp = await fetch(SELF, { method: 'POST', body: formData });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);

    let data;
    try {
      data = await resp.json();
    } catch(parseErr) {
      throw new Error('Réponse serveur invalide (non-JSON). Vérifiez les erreurs PHP.');
    }

    if (data.success) {
      showToast(
        isEdit ? '✏️ Livre mis à jour !' : '📚 Livre ajouté !',
        isEdit
          ? `"${data.titre}" — ${data.status_label}`
          : `"${data.titre}" — ${data.status_label}${data.pdf_generated ? ' · PDF généré ✨' : ''}`,
        'success',
        5000
      );

      if (isEdit) {
        updateCardInDOM(data);
      } else {
        addCardToDOM(data);
        // Mise à jour compteurs
        incrStat('stat-total', 1);
        if (data.access_type === 'gratuit') incrStat('stat-free', 1);
        else incrStat('stat-prem', 1);
      }
      resetForm();
    } else {
      const errs = data.errors || ['Erreur inconnue.'];
      showToast('Erreur', errs.join(' • '), 'error', 7000);
      console.error('[create.php] Errors:', errs);
    }
  } catch (err) {
    showToast('Erreur réseau', err.message, 'error', 6000);
    console.error('[create.php] Fetch error:', err);
  } finally {
    btnSubmit.disabled = false;
    const icon = isEdit ? 'check-circle' : 'plus-circle';
    const txt  = isEdit ? 'Mettre à jour' : 'Ajouter le livre';
    btnSubmit.innerHTML = `<i class="bi bi-${icon}"></i><span>${txt}</span>`;
  }
});

// ── ÉDITER UN LIVRE ───────────────────────────────────────────
async function editBook(id) {
  try {
    const fd = new FormData();
    fd.append('action', 'get');
    fd.append('id', id);
    const resp = await fetch(SELF, { method: 'POST', body: fd });
    const data = await resp.json();

    if (!data.success || !data.book) {
      showToast('Erreur', 'Livre introuvable.', 'error');
      return;
    }

    const b = data.book;
    document.getElementById('f-action').value  = 'update';
    document.getElementById('f-id').value      = b.id;
    document.getElementById('f-titre').value   = b.titre   || '';
    document.getElementById('f-auteur').value  = b.auteur  || '';
    document.getElementById('f-prix').value    = b.prix    || 0;
    document.getElementById('f-pages').value   = b.pages   || 100;
    document.getElementById('f-editeur').value = b.editeur || '';
    document.getElementById('f-annee').value   = b.annee_parution || new Date().getFullYear();
    document.getElementById('f-desc').value    = b.description || '';

    const langSel = document.getElementById('f-langue');
    if (langSel && b.langue) {
      for (const opt of langSel.options) {
        if (opt.value === b.langue) { opt.selected = true; break; }
      }
    }

    // Sélectionner la catégorie
    const catSel = document.getElementById('f-categorie');
    if (catSel && b.categorie_id) {
      for (const opt of catSel.options) {
        if (parseInt(opt.value) === parseInt(b.categorie_id)) { opt.selected = true; break; }
      }
      validateCategorySelect();
    }

    // Aperçu image
    if (b.couverture) {
      const prev = document.getElementById('img-preview');
      prev.src = '../' + b.couverture;
      prev.style.display = 'block';
    }

    updateStatusBadge();

    document.getElementById('form-title-txt').innerHTML =
      '<i class="bi bi-pencil-fill" style="color:var(--gold)"></i> Modifier le livre';
    document.getElementById('form-subtitle-txt').textContent =
      'Modifiez les champs et cliquez sur "Mettre à jour".';
    document.getElementById('btn-submit').innerHTML =
      '<i class="bi bi-check-circle"></i><span>Mettre à jour</span>';
    document.getElementById('btn-cancel').style.display = 'block';

    // Scroll vers le formulaire (mobile)
    document.getElementById('form-panel').scrollIntoView({ behavior: 'smooth' });

  } catch (err) {
    showToast('Erreur', err.message, 'error');
  }
}

function openAddForm() {
  resetForm();
  document.getElementById('f-titre').focus();
  document.getElementById('form-panel').scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
  document.getElementById('book-form').reset();
  document.getElementById('f-action').value    = 'create';
  document.getElementById('f-id').value        = '';
  document.getElementById('img-preview').style.display = 'none';
  document.getElementById('drop-img-icon').textContent = '🖼️';
  document.getElementById('drop-img-text').innerHTML = 'Glissez une image ici ou <em>parcourir</em>';
  document.getElementById('drop-pdf-icon').textContent = '📄';
  document.getElementById('drop-pdf-text').innerHTML = 'Glissez un PDF ici ou <em>parcourir</em>';
  document.getElementById('pdf-indicator').style.display = 'none';
  document.getElementById('form-title-txt').innerHTML =
    '<i class="bi bi-book-fill" style="color:var(--gold)"></i> Ajouter un livre';
  document.getElementById('form-subtitle-txt').textContent =
    'Remplissez tous les champs requis. Le statut premium/gratuit est calculé automatiquement selon le prix.';
  document.getElementById('btn-submit').innerHTML =
    '<i class="bi bi-plus-circle"></i><span id="btn-submit-txt">Ajouter le livre</span>';
  document.getElementById('btn-cancel').style.display = 'none';

  // Reset validation catégorie
  const catSel = document.getElementById('f-categorie');
  const catFb  = document.getElementById('cat-feedback');
  if (catSel) { catSel.classList.remove('f-ok', 'f-err'); catSel.value = ''; }
  if (catFb)  { catFb.style.display = 'none'; }

  updateStatusBadge();
}

// ── DELETE ────────────────────────────────────────────────────
function confirmDelete(id, titre) {
  deleteId = id;
  document.getElementById('cm-desc').textContent = `"${titre}" sera supprimé définitivement (livre + fichiers).`;
  document.getElementById('confirm-modal').classList.add('open');
  document.getElementById('btn-cm-yes').onclick = () => doDelete(id);
}
function closeConfirm() {
  document.getElementById('confirm-modal').classList.remove('open');
  deleteId = null;
}

async function doDelete(id) {
  closeConfirm();
  try {
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    const resp = await fetch(SELF, { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success) {
      const card = document.getElementById('bk-' + id);
      if (card) {
        card.style.transition = 'all .3s';
        card.style.opacity    = '0';
        card.style.transform  = 'scale(.88)';
        setTimeout(() => card.remove(), 320);
      }
      incrStat('stat-total', -1);
      showToast('Livre supprimé', 'Fichiers supprimés également.', 'success');
    } else {
      showToast('Erreur', (data.errors || ['Erreur inconnue.']).join(' | '), 'error');
    }
  } catch (err) {
    showToast('Erreur réseau', err.message, 'error');
  }
}

// ── DOM — Construire une carte ────────────────────────────────
const PALETTES = [
  ['#0d1f3c','#1a4a7a'],['#1a0d3c','#4a1a7a'],['#0d3020','#1a6b3a'],
  ['#3c1a0d','#7a3a1a'],['#0d2a3c','#1a5a7a'],['#2a0d3c','#5a1a7a'],
  ['#1a2a0d','#3a6b1a'],['#3c2a0d','#7a5a1a'],['#0d3c2a','#1a7a5a'],
  ['#2a0d1a','#6b1a3a'],['#0d1a3c','#1a3a7a'],['#3c0d2a','#7a1a5a'],
];
const EMOJIS_JS = ['🌌','🧠','🌿','⚙️','📜','🎭','🔮','💡','🌊','🏔️','🦋','⚡','📖','🔭','🌍'];

function buildCard(data, idx) {
  const isFree   = data.access_type === 'gratuit' || parseFloat(data.prix || 0) <= 0;
  const badgeCls = isFree ? 'bk-badge-free' : 'bk-badge-prem';
  const badgeLbl = isFree ? 'GRATUIT' : 'PREMIUM';
  const priceTxt = isFree ? 'Gratuit' : parseFloat(data.prix).toLocaleString('fr-FR') + ' FCFA';
  const priceClass = isFree ? 'free' : '';
  const palette  = PALETTES[idx % PALETTES.length];
  const emoji    = EMOJIS_JS[idx % EMOJIS_JS.length];
  const pages    = parseInt(data.pages) || 0;
  const cover    = data.couverture
    ? `<img src="../${escHtml(data.couverture)}" alt="${escHtml(data.titre)}" loading="lazy">`
    : `<div class="bk-cover-grad" style="background:linear-gradient(135deg,${palette[0]},${palette[1]})"></div>
       <div class="bk-cover-emoji">${emoji}</div>`;

  return `<div class="bk" id="bk-${parseInt(data.id)}" style="animation-delay:.04s">
    <div class="bk-cover">
      ${cover}
      <div class="bk-vignette"></div>
      <span class="bk-badge ${badgeCls}">${badgeLbl}</span>
      <div class="bk-overlay">
        <button class="ov-btn ov-edit" onclick="editBook(${parseInt(data.id)})">
          <i class="bi bi-pencil"></i> Éditer
        </button>
        <button class="ov-btn ov-del" onclick="confirmDelete(${parseInt(data.id)}, '${escJs(data.titre)}')">
          <i class="bi bi-trash3"></i>
        </button>
      </div>
    </div>
    <div class="bk-body">
      <div class="bk-title" title="${escHtml(data.titre)}">${escHtml(data.titre)}</div>
      <div class="bk-author">${escHtml(data.auteur)}</div>
      <div class="bk-footer">
        <span class="bk-price ${priceClass}">${priceTxt}</span>
        <span class="bk-pages">${pages}p</span>
      </div>
    </div>
  </div>`;
}

function addCardToDOM(data) {
  const grid  = document.getElementById('books-grid');
  const empty = grid.querySelector('.empty');
  if (empty) empty.remove();
  const idx = grid.children.length;
  const tmp = document.createElement('div');
  tmp.innerHTML = buildCard(data, idx).trim();
  const card = tmp.firstChild;
  grid.prepend(card);
  requestAnimationFrame(() => { card.style.opacity = '1'; });
}

function updateCardInDOM(data) {
  const old = document.getElementById('bk-' + data.id);
  if (!old) { location.reload(); return; }
  const idx = Array.from(old.parentNode.children).indexOf(old);
  const tmp = document.createElement('div');
  tmp.innerHTML = buildCard(data, idx).trim();
  old.replaceWith(tmp.firstChild);
}

// ── Stats counter ─────────────────────────────────────────────
function incrStat(id, delta) {
  const el = document.getElementById(id);
  if (el) el.textContent = Math.max(0, (parseInt(el.textContent) || 0) + delta);
}

// ── RECHERCHE ─────────────────────────────────────────────────
const searchInput = document.getElementById('search-input');
searchInput?.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    const q = searchInput.value.trim();
    const url = new URL(window.location.href);
    if (q) url.searchParams.set('q', q);
    else   url.searchParams.delete('q');
    url.searchParams.delete('p');
    window.location.href = url.toString();
  }, 600);
});

// ── FILTRE CATÉGORIE ──────────────────────────────────────────
function filterByCat(catId) {
  const url = new URL(window.location.href);
  if (catId > 0) url.searchParams.set('cat', catId);
  else            url.searchParams.delete('cat');
  url.searchParams.delete('p');
  window.location.href = url.toString();
}

// ── VUE ───────────────────────────────────────────────────────
function setView(v) {
  currentView = v;
  const grid = document.getElementById('books-grid');
  grid.classList.toggle('list-view', v === 'list');
  document.getElementById('vt-grid').classList.toggle('active', v === 'grid');
  document.getElementById('vt-list').classList.toggle('active', v === 'list');
  localStorage.setItem('bk_view', v);
}
setView(currentView);

// ── PAGINATION ────────────────────────────────────────────────
function goPage(p) {
  const url = new URL(window.location.href);
  url.searchParams.set('p', p);
  window.location.href = url.toString();
}

// ── KEYBOARD ──────────────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeConfirm();
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    if (bookForm.contains(document.activeElement)) {
      bookForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }
  }
});

// ── Helpers ───────────────────────────────────────────────────
function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function escJs(s) {
  return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"');
}

// ── INIT ──────────────────────────────────────────────────────
updateStatusBadge();
</script>
</body>
</html>