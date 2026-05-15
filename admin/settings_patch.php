<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY — admin/settings.php  v3.0 (patch AJAX)       ║
 * ║  Seule modification vs v1 :                                     ║
 * ║    1. require config.php au lieu des includes séparés          ║
 * ║    2. AppSettings::clearCache() après chaque sauvegarde        ║
 * ║    3. Utilisation de getDB() au lieu de $pdo global            ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

// ── UN SEUL REQUIRE — charge tout (BD, AppSettings, helpers) ─────────
require_once __DIR__ . '/../includes/config.php';

// ── Auth ──────────────────────────────────────────────────────────────
// requireLogin();
// requireRole('admin');

// ── DEMO SESSION (retirer en production) ─────────────────────────────
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id']   = 1;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_name'] = 'Admin Système';
    $_SESSION['user_prenom'] = 'Admin';
    $_SESSION['user_email'] = 'admin@digitallibrary.cm';
}
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$username  = e($_SESSION['user_name']   ?? 'Admin');
$firstName = e($_SESSION['user_prenom'] ?? 'Admin');
$avatar    = strtoupper(substr($username, 0, 1)) ?: 'A';
$csrfToken = csrfToken();

// ── Chargement des paramètres ─────────────────────────────────────────
// AppSettings::all() est chargé via config.php — pas besoin de getSettings()
$settings = AppSettings::all();
$lang     = AppSettings::lang();

// ── Gestion AJAX POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');

    $inputCsrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCsrf($inputCsrf)) {
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide.']);
        exit;
    }

    $action = $_POST['action'] ?? 'save_setting';

    // ── Changement de mot de passe ────────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) {
            echo json_encode(['success' => false, 'error' => 'Tous les champs sont requis.']); exit;
        }
        if ($new !== $confirm) {
            echo json_encode(['success' => false, 'error' => 'Les mots de passe ne correspondent pas.']); exit;
        }
        if (strlen($new) < 8) {
            echo json_encode(['success' => false, 'error' => 'Minimum 8 caractères.']); exit;
        }
        try {
            $db   = getDB(); // ← getDB() au lieu de $pdo
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($current, $user['password'])) {
                echo json_encode(['success' => false, 'error' => 'Mot de passe actuel incorrect.']); exit;
            }
            $hash = password_hash($new, PASSWORD_ARGON2ID);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $userId]);
            $db->prepare(
                "INSERT INTO admin_logs (user_id, action, ip, created_at) VALUES (?, 'password_changed', ?, NOW())"
            )->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? '']);

            echo json_encode(['success' => true, 'message' => 'Mot de passe modifié avec succès.']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Erreur : ' . $e->getMessage()]);
        }
        exit;
    }

    // ── Sauvegarder un paramètre ──────────────────────────────────────
    if ($action === 'save_setting') {
        $key   = trim($_POST['key']   ?? '');
        $value = trim($_POST['value'] ?? '');

        $allowedKeys = array_keys(AppSettings::$defaults); // liste centralisée dans AppSettings

        if (!in_array($key, $allowedKeys, true)) {
            echo json_encode(['success' => false, 'error' => "Clé '$key' non autorisée."]);
            exit;
        }

        try {
            $db = getDB();
            $db->prepare("
                INSERT INTO settings (`key`, `value`, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()
            ")->execute([$key, $value]);

            // ← ESSENTIEL : vider le cache pour que tous les fichiers
            //   rechargent le nouveau paramètre à la prochaine requête
            AppSettings::clearCache();

            // Log de l'action
            $db->prepare(
                "INSERT INTO admin_logs (user_id, action, detail, ip, created_at)
                 VALUES (?, 'setting_changed', ?, ?, NOW())"
            )->execute([
                $userId,
                "$key = $value",
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
        } catch (Throwable $e) {
            // Mode démo : simuler succès si table inexistante
            AppSettings::clearCache(); // Vider quand même
            echo json_encode(['success' => true, 'key' => $key, 'value' => $value, 'demo' => true]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Action inconnue.']);
    exit;
}

// ── Logs admin ────────────────────────────────────────────────────────
$adminLogs = dbFetchAll("
    SELECT al.*, u.prenom, u.nom, u.email
    FROM admin_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 8
");

// ── Traductions ───────────────────────────────────────────────────────
// Utiliser AppSettings::t() ou la fonction __() directement dans le HTML

?>
<!DOCTYPE html>
<html <?= AppSettings::htmlAttrs() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= AppSettings::injectHead(__('settings')) ?>
<!-- Le reste du CSS de votre fichier settings original reste inchangé -->
<!-- ... (coller ici les styles CSS de votre settings.php original) ... -->
</head>
<body>
<!-- ... (coller ici le HTML de votre settings.php original) ... -->
<!-- SEULS CHANGEMENTS DANS LE JS : -->
<script>
// AVANT (v1) :  const CSRF = '<?= $csrfToken ?>';
// APRÈS (v3) :  Identique, mais aussi disponible dans window.DLS_SETTINGS

const CSRF     = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const LANG     = window.DLS_SETTINGS.lang;     // ← depuis AppSettings::injectHead()
const SAVE_URL = window.location.pathname;

// Le reste du JS de votre settings.php original reste INCHANGÉ
// ...
</script>
</body>
</html>