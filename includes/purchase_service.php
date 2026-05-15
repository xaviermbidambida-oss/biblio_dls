<?php
// ============================================================
// DIGITAL LIBRARY — includes/purchase_service.php
// Service central d'achat, bonus, notifications, stats
// ============================================================

/**
 * Traite un achat réel : insertion BD, stock, nb_ventes, bonus
 *
 * @return array ['success'=>bool, 'message'=>string, 'reference'=>string|null, 'bonus'=>bool]
 */
function processPurchase(PDO $pdo, int $userId, int $livreId): array
{
    try {
        $pdo->beginTransaction();

        // 1. Vérifier que le livre existe et est disponible
        $stmt = $pdo->prepare("SELECT id, titre, prix, stock, statut FROM livres WHERE id = ? FOR UPDATE");
        $stmt->execute([$livreId]);
        $livre = $stmt->fetch();

        if (!$livre) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Livre introuvable.', 'reference' => null, 'bonus' => false];
        }
        if ($livre['statut'] !== 'disponible') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Ce livre n\'est plus disponible.', 'reference' => null, 'bonus' => false];
        }
        if ((int)$livre['stock'] <= 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Stock épuisé.', 'reference' => null, 'bonus' => false];
        }

        // 2. Vérifier si déjà acheté
        $stmtCheck = $pdo->prepare("SELECT id FROM achats WHERE user_id = ? AND livre_id = ? AND statut = 'confirme'");
        $stmtCheck->execute([$userId, $livreId]);
        if ($stmtCheck->fetch()) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Vous possédez déjà ce livre.', 'reference' => null, 'bonus' => false];
        }

        // 3. Générer référence unique
        $reference = 'DLS-' . strtoupper(uniqid()) . '-' . strtoupper(substr(md5($userId . $livreId . time()), 0, 6));

        // 4. Insérer l'achat
        $stmtInsert = $pdo->prepare("
            INSERT INTO achats (user_id, livre_id, montant, methode, statut, reference)
            VALUES (?, ?, ?, 'orange_money', 'confirme', ?)
        ");
        $stmtInsert->execute([$userId, $livreId, $livre['prix'], $reference]);

        // 5. Mettre à jour nb_ventes et stock
        $pdo->prepare("UPDATE livres SET nb_ventes = nb_ventes + 1, stock = GREATEST(stock - 1, 0) WHERE id = ?")->execute([$livreId]);

        // 6. Vérifier et attribuer bonus
        $bonusAttribue = checkAndAssignBonus($pdo, $userId);

        $pdo->commit();

        // 7. Notification utilisateur
        $message = "✅ Achat confirmé : « {$livre['titre']} »";
        sendNotification($pdo, $userId, $message);

        return [
            'success'   => true,
            'message'   => 'Achat effectué avec succès.',
            'reference' => $reference,
            'bonus'     => $bonusAttribue,
            'livre'     => $livre,
        ];

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[purchase_service] processPurchase error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Erreur serveur. Veuillez réessayer.', 'reference' => null, 'bonus' => false];
    }
}

/**
 * Vérifie si l'utilisateur mérite un bonus (tous les 5 achats confirmés)
 * et l'attribue automatiquement si oui.
 *
 * @return bool true si bonus accordé
 */
function checkAndAssignBonus(PDO $pdo, int $userId): bool
{
    // Compter les achats payants confirmés (hors bonus)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM achats
        WHERE user_id = ? AND statut = 'confirme' AND montant > 0 AND methode != 'bonus'
    ");
    $stmt->execute([$userId]);
    $count = (int)$stmt->fetchColumn();

    // Compter les bonus déjà accordés
    $stmtBonus = $pdo->prepare("SELECT COUNT(*) FROM achats WHERE user_id = ? AND methode = 'bonus'");
    $stmtBonus->execute([$userId]);
    $bonusCount = (int)$stmtBonus->fetchColumn();

    // Le prochain bonus est dû si count >= (bonusCount + 1) * 5
    $bonusDu = ($bonusCount + 1) * 5;
    if ($count >= $bonusDu) {
        return assignBonus($pdo, $userId);
    }
    return false;
}

/**
 * Attribue un livre gratuit comme bonus.
 * Choisit un livre non encore possédé par l'utilisateur.
 *
 * @return bool true si bonus attribué avec succès
 */
function assignBonus(PDO $pdo, int $userId, ?int $livreId = null): bool
{
    try {
        // Si pas de livre spécifié, choisir le plus populaire non possédé
        if ($livreId === null) {
            $stmtLivre = $pdo->prepare("
                SELECT l.id FROM livres l
                WHERE l.statut = 'disponible'
                  AND l.prix > 0
                  AND l.id NOT IN (
                    SELECT livre_id FROM achats WHERE user_id = ? AND statut = 'confirme'
                  )
                ORDER BY l.nb_ventes DESC
                LIMIT 1
            ");
            $stmtLivre->execute([$userId]);
            $row = $stmtLivre->fetch();
            if (!$row) return false;
            $livreId = (int)$row['id'];
        }

        // Vérifier que le livre existe
        $stmtCheck = $pdo->prepare("SELECT titre FROM livres WHERE id = ?");
        $stmtCheck->execute([$livreId]);
        $livre = $stmtCheck->fetch();
        if (!$livre) return false;

        // Générer référence bonus
        $reference = 'BONUS-' . strtoupper(uniqid()) . '-' . strtoupper(substr(md5($userId . $livreId), 0, 4));

        // Insérer l'achat bonus (montant = 0, méthode = bonus)
        $stmtBonus = $pdo->prepare("
            INSERT INTO achats (user_id, livre_id, montant, methode, statut, reference)
            VALUES (?, ?, 0, 'bonus', 'confirme', ?)
            ON DUPLICATE KEY UPDATE id = id
        ");
        $stmtBonus->execute([$userId, $livreId, $reference]);

        // Notification
        sendNotification($pdo, $userId, "🎁 Vous avez reçu un livre gratuit : « {$livre['titre']} » — Merci pour votre fidélité !");

        return true;

    } catch (PDOException $e) {
        error_log('[purchase_service] assignBonus error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Retourne les statistiques de ventes pour le dashboard admin.
 */
function getSalesStats(PDO $pdo): array
{
    try {
        $stats = [];

        // Revenus totaux (hors bonus)
        $stats['total_revenus'] = (float)$pdo->query("
            SELECT COALESCE(SUM(montant), 0) FROM achats WHERE statut = 'confirme' AND methode != 'bonus'
        ")->fetchColumn();

        // Nombre de ventes payantes
        $stats['nb_ventes'] = (int)$pdo->query("
            SELECT COUNT(*) FROM achats WHERE statut = 'confirme' AND methode != 'bonus'
        ")->fetchColumn();

        // Nombre de bonus accordés
        $stats['nb_bonus'] = (int)$pdo->query("
            SELECT COUNT(*) FROM achats WHERE methode = 'bonus' AND statut = 'confirme'
        ")->fetchColumn();

        // Nombre d'utilisateurs ayant acheté
        $stats['nb_acheteurs'] = (int)$pdo->query("
            SELECT COUNT(DISTINCT user_id) FROM achats WHERE statut = 'confirme'
        ")->fetchColumn();

        // Revenus du mois courant
        $stats['revenus_mois'] = (float)$pdo->query("
            SELECT COALESCE(SUM(montant), 0) FROM achats
            WHERE statut = 'confirme' AND methode != 'bonus'
            AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())
        ")->fetchColumn();

        // Top 5 livres
        $stats['top_livres'] = $pdo->query("
            SELECT l.id, l.titre, l.auteur, l.prix, l.nb_ventes,
                   COUNT(a.id) AS nb_achats,
                   COALESCE(SUM(a.montant), 0) AS revenus
            FROM livres l
            LEFT JOIN achats a ON a.livre_id = l.id AND a.statut = 'confirme'
            GROUP BY l.id
            ORDER BY nb_achats DESC
            LIMIT 5
        ")->fetchAll();

        // Dernières ventes
        $stats['derniers_achats'] = $pdo->query("
            SELECT a.id, a.reference, a.montant, a.methode, a.statut, a.created_at,
                   CONCAT(u.prenom, ' ', u.nom) AS user_nom, u.email AS user_email,
                   l.titre AS livre_titre, l.prix AS livre_prix
            FROM achats a
            JOIN users u ON u.id = a.user_id
            JOIN livres l ON l.id = a.livre_id
            ORDER BY a.created_at DESC
            LIMIT 50
        ")->fetchAll();

        // Évolution revenus 7 derniers jours
        $stats['revenus_7j'] = $pdo->query("
            SELECT DATE(created_at) AS jour,
                   COALESCE(SUM(montant), 0) AS total,
                   COUNT(*) AS nb
            FROM achats
            WHERE statut = 'confirme' AND methode != 'bonus'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY jour ASC
        ")->fetchAll();

        return $stats;

    } catch (PDOException $e) {
        error_log('[purchase_service] getSalesStats error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Retourne le nombre d'achats confirmés d'un utilisateur.
 */
function getUserPurchaseCount(PDO $pdo, int $userId): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM achats WHERE user_id = ? AND statut = 'confirme'");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Envoie une notification à un utilisateur.
 * Crée la table si elle n'existe pas (sécurité).
 */
function sendNotification(PDO $pdo, int $userId, string $message): void
{
    try {
        // Créer la table si absente
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                message    TEXT NOT NULL,
                lu         TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->execute([$userId, $message]);

    } catch (PDOException $e) {
        error_log('[purchase_service] sendNotification error: ' . $e->getMessage());
    }
}

/**
 * Récupère les notifications non lues d'un utilisateur.
 */
function getUserNotifications(PDO $pdo, int $userId, bool $unreadOnly = false): array
{
    try {
        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        if ($unreadOnly) $sql .= " AND lu = 0";
        $sql .= " ORDER BY created_at DESC LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Marque toutes les notifications d'un user comme lues.
 */
function markNotificationsRead(PDO $pdo, int $userId): void
{
    try {
        $pdo->prepare("UPDATE notifications SET lu = 1 WHERE user_id = ?")->execute([$userId]);
    } catch (PDOException $e) {}
}

/**
 * Vérifie si un utilisateur a accès à un livre (achat ou bonus).
 */
function hasBookAccess(PDO $pdo, int $userId, int $livreId): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM achats
            WHERE user_id = ? AND livre_id = ? AND statut = 'confirme'
        ");
        $stmt->execute([$userId, $livreId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}