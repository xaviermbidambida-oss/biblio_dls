<?php
/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — Simulation Paiement Mobile    ║
 * ║  api/pay.php                                             ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * Simule Orange Money / MTN Mobile Money.
 * Succès à 80 %, échec à 20 %.
 */

defined('DLS_ROOT') || define('DLS_ROOT', dirname(__DIR__));
require_once DLS_ROOT . '/config/config.php';
require_once DLS_ROOT . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ── POST uniquement ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

// ── Session requise ───────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié.']);
    exit;
}

// ── Corps JSON ────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Corps JSON invalide.']);
    exit;
}

// ── Validation des champs ─────────────────────────────────────
$userId   = (int) $_SESSION['user_id'];
$livreId  = isset($body['livre_id'])  ? (int)    $body['livre_id']  : 0;
$methode  = isset($body['methode'])   ? (string) $body['methode']   : '';
$phone    = isset($body['telephone']) ? trim((string) $body['telephone']) : '';

$methodesAutorisees = ['orange', 'mtn'];

if ($livreId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Identifiant de livre invalide.']);
    exit;
}

if (!in_array($methode, $methodesAutorisees, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Méthode de paiement invalide.']);
    exit;
}

if (strlen($phone) < 8 || !ctype_digit($phone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Numéro de téléphone invalide (8–9 chiffres).']);
    exit;
}

// ── Vérifier le livre ─────────────────────────────────────────
try {
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        "SELECT id, titre, prix FROM livres
         WHERE id = :id AND statut = 'disponible'
         LIMIT 1"
    );
    $stmt->execute([':id' => $livreId]);
    $livre = $stmt->fetch();

    if (!$livre) {
        http_response_code(404);
        echo json_encode(['error' => 'Livre introuvable ou indisponible.']);
        exit;
    }

    $montant = (float) $livre['prix'];

    // Livre gratuit : pas de paiement nécessaire
    if ($montant <= 0) {
        echo json_encode([
            'success'   => true,
            'message'   => 'Ce livre est gratuit. Accès immédiat.',
            'libre_acces' => true,
        ]);
        exit;
    }

    // ── Vérifier si déjà acheté ───────────────────────────────
    $stmt2 = $pdo->prepare(
        "SELECT id FROM achats
         WHERE user_id = :uid AND livre_id = :lid AND statut = 'confirme'
         LIMIT 1"
    );
    $stmt2->execute([':uid' => $userId, ':lid' => $livreId]);
    if ($stmt2->fetch()) {
        echo json_encode([
            'success'  => true,
            'message'  => 'Vous avez déjà acheté ce livre.',
            'deja_achete' => true,
        ]);
        exit;
    }

    // ── Simulation paiement ───────────────────────────────────
    // 80 % succès / 20 % échec
    $success     = (mt_rand(1, 100) <= 80);
    $reference   = strtoupper(uniqid('DLS-', true));
    $methodeDB   = $methode === 'orange' ? 'orange_money' : 'mtn_money';
    $statutAchat = $success ? 'confirme' : 'annule';

    // ── Insérer l'achat ───────────────────────────────────────
    $ins = $pdo->prepare(
        "INSERT INTO achats
             (user_id, livre_id, montant, methode_paiement, statut, reference, created_at)
         VALUES
             (:uid, :lid, :montant, :methode, :statut, :ref, NOW())"
    );
    $ins->execute([
        ':uid'     => $userId,
        ':lid'     => $livreId,
        ':montant' => $montant,
        ':methode' => $methodeDB,
        ':statut'  => $statutAchat,
        ':ref'     => $reference,
    ]);

    $achatId = (int) $pdo->lastInsertId();

    // ── Enregistrer le paiement ───────────────────────────────
    $insPay = $pdo->prepare(
        "INSERT INTO paiements
             (achat_id, user_id, montant, methode, numero_compte, transaction_id, statut)
         VALUES
             (:aid, :uid, :montant, :methode, :phone, :txid, :statut)"
    );
    $statutPay = $success ? 'valide' : 'echoue';
    $insPay->execute([
        ':aid'     => $achatId,
        ':uid'     => $userId,
        ':montant' => $montant,
        ':methode' => $methodeDB,
        ':phone'   => $phone,
        ':txid'    => $reference,
        ':statut'  => $statutPay,
    ]);

    // ── Incrémenter nb_ventes si succès ──────────────────────
    if ($success) {
        $pdo->prepare("UPDATE livres SET nb_ventes = nb_ventes + 1 WHERE id = :id")
            ->execute([':id' => $livreId]);

        // Notification utilisateur
        $notifMsg = 'Vous avez acheté « ' . $livre['titre'] . ' » pour '
                  . number_format($montant, 0, ',', ' ') . ' FCFA.';
        $pdo->prepare(
            "INSERT INTO notifications (user_id, titre, message, type)
             VALUES (:uid, 'Achat confirmé', :msg, 'succes')"
        )->execute([':uid' => $userId, ':msg' => $notifMsg]);
    }

    // ── Réponse ───────────────────────────────────────────────
    if ($success) {
        echo json_encode([
            'success'    => true,
            'message'    => '✅ Paiement validé ! Vous pouvez lire ce livre.',
            'reference'  => $reference,
            'montant'    => $montant,
            'livre_id'   => $livreId,
            'url_lecture' => '../books/read.php?id=' . $livreId,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '❌ Paiement échoué. Solde insuffisant ou PIN incorrect.',
            'code'    => 'PAYMENT_FAILED',
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Throwable $e) {
    error_log('[DLS] pay.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur lors du traitement du paiement.']);
}

exit;