<?php
/**
 * login.php — Extrait de logique de connexion avec redirect intelligent
 * ──────────────────────────────────────────────────────────────────────
 * Ajoutez cette logique dans votre login.php existant,
 * dans le bloc qui traite le formulaire POST.
 */

session_start();

// Pages autorisées pour la redirection (whitelist de sécurité)
// Ajoutez ici toutes vos pages internes protégées.
$ALLOWED_REDIRECTS = [
    'catalogue.php',
    'livres.php',
    'ajouter_livre.php',
    'mes_achats.php',
    'statistiques.php',
    'admin_dashboard.php',
    'lire.php',
    'achat.php',
];

// Récupérer la page de destination demandée (depuis ?redirect=)
$redirectParam = isset($_GET['redirect']) ? urldecode($_GET['GET']['redirect'] ?? $_GET['redirect']) : '';

// Valider le redirect (sécurité : éviter les open redirects)
function getSafeRedirect(string $redirect, array $allowed, string $fallback = 'catalogue.php'): string {
    if (empty($redirect)) return $fallback;

    // Extraire le nom du fichier seulement (ignorer les chemins absolus/URLs externes)
    $filename = basename(parse_url($redirect, PHP_URL_PATH) ?? '');

    // Vérifier que le fichier est dans la whitelist
    if (in_array($filename, $allowed, true)) {
        // Conserver les query params si présents
        $query = parse_url($redirect, PHP_URL_QUERY);
        return $filename . ($query ? '?' . $query : '');
    }

    return $fallback;
}

// ── Traitement du formulaire POST ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // TODO : Remplacez par votre propre logique de vérification BDD
    // Exemple fictif :
    // $user = getUserFromDB($username);
    // if ($user && password_verify($password, $user['password_hash'])) {

    if (/* votre condition de connexion réussie */ false) {

        // ── Connexion réussie ──────────────────────────────────
        session_regenerate_id(true); // Sécurité : prévenir session fixation

        // Stocker les infos en session
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'] ?? 'user';

        // ── Redirect intelligent ───────────────────────────────
        $safeTarget = getSafeRedirect($redirectParam, $ALLOWED_REDIRECTS);
        header('Location: ' . $safeTarget);
        exit;

    } else {

        // ── Connexion échouée ──────────────────────────────────
        $loginError = "Identifiants incorrects. Veuillez réessayer.";
        // Conserver le paramètre redirect dans le formulaire pour ne pas le perdre
    }
}

// ── Dans votre formulaire HTML, conservez le redirect : ──────
// <form method="POST" action="login.php?redirect=<?= htmlspecialchars($redirectParam) ?>">
//   ...
// </form>
//
// Ou en champ caché :
// <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectParam) ?>">