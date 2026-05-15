<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════
// FORGOT-PASSWORD.PHP — Digital Library Premium
// Système de récupération de mot de passe sécurisé & complet
// ══════════════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
    ]);
}

// ── Rediriger si déjà connecté ──────────────────────────────
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// ══════════════════════════════════════════════════════════════
// CONNEXION PDO
// ══════════════════════════════════════════════════════════════
$pdo = null;
$configPaths = [
    __DIR__ . '/includes/config.php',
    __DIR__ . '/config/config.php',
    __DIR__ . '/config/database.php',
];
foreach ($configPaths as $cp) {
    if (file_exists($cp)) {
        require_once $cp;
        break;
    }
}

if (!isset($pdo) || $pdo === null) {
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=digital_library;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // En prod, ne pas exposer le message d'erreur
        error_log('DB Connection failed: ' . $e->getMessage());
        die('<div style="color:#f43f5e;padding:2rem;font-family:monospace;background:#07090f;">Erreur de connexion. Veuillez réessayer plus tard.</div>');
    }
}

// ══════════════════════════════════════════════════════════════
// AUTO-CRÉATION DES TABLES NÉCESSAIRES
// ══════════════════════════════════════════════════════════════
try {
    // Table password_resets
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token VARCHAR(128) NOT NULL UNIQUE,
        token_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        ip VARCHAR(45),
        user_agent VARCHAR(500),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user (user_id),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Table rate limiting reset
    $pdo->exec("CREATE TABLE IF NOT EXISTS reset_rate_limits (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(255) NOT NULL,
        attempts INT DEFAULT 1,
        first_attempt_at DATETIME NOT NULL,
        last_attempt_at DATETIME NOT NULL,
        blocked_until DATETIME NULL,
        INDEX idx_identifier (identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Table security logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        event_type VARCHAR(80) NOT NULL,
        detail TEXT,
        ip VARCHAR(45),
        user_agent VARCHAR(500),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_event (event_type),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Colonne password_changed_at sur users si absente
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_changed_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL");
    }
    // Colonne lu sur notifications (alias is_read)
    $colLu = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'lu'")->fetchAll();
    if (empty($colLu)) {
        // Vérifier si is_read existe pour ajouter lu comme alias
        $colIsRead = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_read'")->fetchAll();
        if (!empty($colIsRead)) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN lu TINYINT DEFAULT 0");
        }
    }
} catch (PDOException $e) {
    error_log('Table creation error: ' . $e->getMessage());
    // On continue, les tables existent peut-être déjà
}

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════

function getClientIP(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            return trim(explode(',', $_SERVER[$h])[0]);
        }
    }
    return '0.0.0.0';
}

function getUserAgent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500);
}

function securityLog(PDO $pdo, ?int $userId, string $event, string $detail = ''): void {
    try {
        $pdo->prepare(
            "INSERT INTO security_logs (user_id, event_type, detail, ip, user_agent)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$userId, $event, $detail, getClientIP(), getUserAgent()]);
    } catch (Throwable $e) {
        error_log('Security log error: ' . $e->getMessage());
    }
}

function checkRateLimit(PDO $pdo, string $identifier, int $maxAttempts = 5, int $windowMinutes = 30): array {
    try {
        $ip = getClientIP();
        $fullId = $identifier . '|' . $ip;

        // Nettoyer les anciens enregistrements
        $pdo->prepare(
            "DELETE FROM reset_rate_limits
             WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
             AND (blocked_until IS NULL OR blocked_until < NOW())"
        )->execute([$windowMinutes * 2]);

        // Vérifier si bloqué
        $st = $pdo->prepare(
            "SELECT * FROM reset_rate_limits WHERE identifier = ? LIMIT 1"
        );
        $st->execute([$fullId]);
        $record = $st->fetch();

        if ($record) {
            if ($record['blocked_until'] && strtotime($record['blocked_until']) > time()) {
                $remaining = ceil((strtotime($record['blocked_until']) - time()) / 60);
                return ['blocked' => true, 'remaining_minutes' => $remaining, 'attempts' => $record['attempts']];
            }

            if ((int)$record['attempts'] >= $maxAttempts) {
                // Bloquer 60 minutes
                $pdo->prepare(
                    "UPDATE reset_rate_limits SET blocked_until = DATE_ADD(NOW(), INTERVAL 60 MINUTE), attempts = attempts + 1 WHERE identifier = ?"
                )->execute([$fullId]);
                return ['blocked' => true, 'remaining_minutes' => 60, 'attempts' => (int)$record['attempts'] + 1];
            }

            $pdo->prepare(
                "UPDATE reset_rate_limits SET attempts = attempts + 1, last_attempt_at = NOW() WHERE identifier = ?"
            )->execute([$fullId]);

            return ['blocked' => false, 'attempts' => (int)$record['attempts'] + 1];
        } else {
            $pdo->prepare(
                "INSERT INTO reset_rate_limits (identifier, attempts, first_attempt_at, last_attempt_at)
                 VALUES (?, 1, NOW(), NOW())"
            )->execute([$fullId]);
            return ['blocked' => false, 'attempts' => 1];
        }
    } catch (PDOException $e) {
        error_log('Rate limit error: ' . $e->getMessage());
        return ['blocked' => false, 'attempts' => 0];
    }
}

function sendResetEmail(array $user, string $token, string $expires): bool {
    $resetLink  = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . '/reset-password.php?token=' . urlencode($token);
    $expireFmt  = date('d/m/Y à H:i', strtotime($expires));
    $ip         = getClientIP();
    $ua         = getUserAgent();
    $prenom     = htmlspecialchars($user['prenom'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
    $toEmail    = $user['email'];
    $toName     = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
    $siteName   = 'Digital Library';

    // Détection appareil basique
    $device = 'Appareil inconnu';
    $lua = strtolower($ua);
    if (str_contains($lua, 'mobile') || str_contains($lua, 'android')) {
        $device = 'Appareil mobile';
    } elseif (str_contains($lua, 'iphone') || str_contains($lua, 'ipad')) {
        $device = 'Apple iOS';
    } elseif (str_contains($lua, 'windows')) {
        $device = 'Windows PC';
    } elseif (str_contains($lua, 'mac')) {
        $device = 'Mac';
    } elseif (str_contains($lua, 'linux')) {
        $device = 'Linux';
    }

    $subject = "[$siteName] Réinitialisation de votre mot de passe";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Réinitialisation mot de passe</title>
<style>
  body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#07090f;color:#eef2ff}
  .wrap{max-width:580px;margin:0 auto;padding:40px 20px}
  .card{background:#0d1220;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,0.07)}
  .header{background:linear-gradient(135deg,#e8c97d,#ff6b35);padding:36px 40px;text-align:center}
  .logo{font-size:2rem;margin-bottom:8px}
  .header h1{margin:0;font-size:1.4rem;color:#07090f;font-weight:800;letter-spacing:-0.5px}
  .body{padding:40px}
  .greeting{font-size:1rem;font-weight:600;margin-bottom:12px;color:#f0eeea}
  .text{font-size:0.875rem;color:rgba(240,238,234,0.7);line-height:1.8;margin-bottom:20px}
  .btn-wrap{text-align:center;margin:32px 0}
  .btn{display:inline-block;padding:16px 40px;background:linear-gradient(135deg,#e8c97d,#ff6b35);color:#07090f;text-decoration:none;border-radius:12px;font-weight:700;font-size:0.95rem;letter-spacing:0.03em}
  .meta-box{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:20px;margin:24px 0;font-size:0.8rem}
  .meta-row{display:flex;gap:10px;margin-bottom:8px;align-items:flex-start}
  .meta-row:last-child{margin-bottom:0}
  .meta-label{color:rgba(240,238,234,0.45);min-width:110px;flex-shrink:0}
  .meta-value{color:#f0eeea;word-break:break-all}
  .warning{background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);border-radius:8px;padding:14px 16px;font-size:0.78rem;color:#fbbf24;margin-top:20px;line-height:1.7}
  .footer{padding:24px 40px;border-top:1px solid rgba(255,255,255,0.05);text-align:center;font-size:0.73rem;color:rgba(240,238,234,0.3)}
  .link-fallback{word-break:break-all;font-size:0.73rem;color:rgba(240,238,234,0.45);text-align:center;margin-top:16px}
  .expire-chip{display:inline-block;background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.3);color:#fb7185;padding:4px 12px;border-radius:100px;font-size:0.72rem;font-weight:600;margin-bottom:20px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="header">
      <div class="logo">📚</div>
      <h1>Digital Library</h1>
    </div>
    <div class="body">
      <div class="expire-chip">⏱ Expire dans 15 minutes</div>
      <div class="greeting">Bonjour, {$prenom} 👋</div>
      <p class="text">
        Nous avons reçu une demande de réinitialisation de mot de passe pour votre compte Digital Library.
        Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail — votre compte reste sécurisé.
      </p>
      <div class="btn-wrap">
        <a href="{$resetLink}" class="btn">🔑 Réinitialiser mon mot de passe</a>
      </div>
      <div class="meta-box">
        <div class="meta-row">
          <span class="meta-label">📅 Expire le :</span>
          <span class="meta-value"><strong>{$expireFmt}</strong></span>
        </div>
        <div class="meta-row">
          <span class="meta-label">🌐 Adresse IP :</span>
          <span class="meta-value">{$ip}</span>
        </div>
        <div class="meta-row">
          <span class="meta-label">💻 Appareil :</span>
          <span class="meta-value">{$device}</span>
        </div>
      </div>
      <div class="warning">
        ⚠️ <strong>Sécurité :</strong> Ce lien est à usage unique et expire dans 15 minutes.
        Ne le partagez jamais. Digital Library ne vous demandera jamais votre mot de passe par e-mail.
      </div>
      <p class="link-fallback">Lien de secours : {$resetLink}</p>
    </div>
    <div class="footer">
      © 2025 Digital Library · Cet e-mail a été envoyé automatiquement, ne pas y répondre.<br>
      Si vous n'avez pas fait cette demande, <a href="mailto:support@digitallibrary.cm" style="color:rgba(232,201,125,0.7)">contactez-nous</a>.
    </div>
  </div>
</div>
</body>
</html>
HTML;

    $txt = "Bonjour {$prenom},\n\nRéinitialisez votre mot de passe via ce lien (valide 15 min) :\n{$resetLink}\n\nSI vous n'avez pas fait cette demande, ignorez ce message.\n\n— Digital Library";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Digital Library <no-reply@digitallibrary.cm>\r\n";
    $headers .= "Reply-To: support@digitallibrary.cm\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    return mail($toEmail, $subject, $html, $headers);
}

function sendConfirmEmail(array $user): void {
    $prenom   = htmlspecialchars($user['prenom'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
    $toEmail  = $user['email'];
    $ip       = getClientIP();
    $time     = date('d/m/Y à H:i:s');
    $subject  = "[Digital Library] Mot de passe modifié avec succès";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><style>
  body{margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#07090f;color:#eef2ff}
  .wrap{max-width:540px;margin:0 auto;padding:40px 20px}
  .card{background:#0d1220;border-radius:16px;border:1px solid rgba(255,255,255,0.07);overflow:hidden}
  .header{background:linear-gradient(135deg,#4ecca3,#4a9eff);padding:32px 40px;text-align:center}
  .header h1{margin:0;font-size:1.3rem;color:#07090f;font-weight:800}
  .body{padding:36px 40px}
  .check{font-size:3rem;text-align:center;display:block;margin-bottom:16px}
  .title{text-align:center;font-size:1.1rem;font-weight:700;color:#4ecca3;margin-bottom:16px}
  .text{font-size:0.875rem;color:rgba(240,238,234,0.7);line-height:1.8}
  .info{background:rgba(255,255,255,0.04);border-radius:8px;padding:16px;margin:20px 0;font-size:0.8rem}
  .info-row{display:flex;gap:10px;margin-bottom:6px}
  .info-label{color:rgba(240,238,234,0.45);min-width:90px}
  .info-value{color:#f0eeea}
  .warn{background:rgba(244,63,94,0.07);border:1px solid rgba(244,63,94,0.2);border-radius:8px;padding:12px 16px;font-size:0.78rem;color:#fb7185;margin-top:16px}
  .footer{padding:20px 40px;border-top:1px solid rgba(255,255,255,0.05);text-align:center;font-size:0.72rem;color:rgba(240,238,234,0.3)}
</style></head>
<body>
<div class="wrap">
  <div class="card">
    <div class="header"><h1>📚 Digital Library</h1></div>
    <div class="body">
      <span class="check">✅</span>
      <div class="title">Mot de passe modifié avec succès !</div>
      <p class="text">Bonjour <strong>{$prenom}</strong>, votre mot de passe a bien été réinitialisé.<br>
      Toutes vos sessions actives ont été déconnectées pour votre sécurité.</p>
      <div class="info">
        <div class="info-row"><span class="info-label">📅 Date :</span><span class="info-value">{$time}</span></div>
        <div class="info-row"><span class="info-label">🌐 IP :</span><span class="info-value">{$ip}</span></div>
      </div>
      <div class="warn">⚠️ Si vous n'êtes pas à l'origine de ce changement, contactez immédiatement <a href="mailto:support@digitallibrary.cm" style="color:#fb7185">support@digitallibrary.cm</a>.</div>
    </div>
    <div class="footer">© 2025 Digital Library</div>
  </div>
</div>
</body></html>
HTML;

    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Digital Library <no-reply@digitallibrary.cm>\r\n";
    @mail($toEmail, $subject, $html, $headers);
}

// ══════════════════════════════════════════════════════════════
// CSRF
// ══════════════════════════════════════════════════════════════
if (empty($_SESSION['csrf_fp'])) {
    $_SESSION['csrf_fp'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_fp'];

function verifyCsrf(string $token): bool {
    return hash_equals($_SESSION['csrf_fp'] ?? '', $token);
}

// ══════════════════════════════════════════════════════════════
// LOGIQUE : DÉTECTER MODE
// Modes : 'request' | 'sent' | 'reset_form' | 'reset_done'
// ══════════════════════════════════════════════════════════════
$mode    = 'request';
$error   = '';
$success = '';
$resetUser = null;
$tokenData = null;

// ── MODE : RESET FORM (token dans URL) ──────────────────────
$rawToken = trim($_GET['token'] ?? '');
if ($rawToken !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $mode = 'reset_form';

    // Vérifier token
    try {
        $st = $pdo->prepare(
            "SELECT pr.*, u.id AS uid, u.prenom, u.nom, u.email, u.password AS current_hash
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token = ?
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
             LIMIT 1"
        );
        $st->execute([substr($rawToken, 0, 128)]);
        $tokenData = $st->fetch();

        if (!$tokenData) {
            $mode  = 'request';
            $error = 'Ce lien de réinitialisation est invalide ou a expiré. Veuillez soumettre une nouvelle demande.';
        }
    } catch (PDOException $e) {
        error_log('Token check error: ' . $e->getMessage());
        $mode  = 'request';
        $error = 'Une erreur technique est survenue. Veuillez réessayer.';
    }
}

// ══════════════════════════════════════════════════════════════
// TRAITEMENT POST
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    // ── ACTION : DEMANDE RESET ───────────────────────────────
    if ($action === 'request_reset') {
        if (!verifyCsrf($_POST['_csrf'] ?? '')) {
            $error = 'Token de sécurité invalide. Rechargez la page.';
        } else {
            $identifier = strtolower(trim($_POST['identifier'] ?? ''));

            if (empty($identifier)) {
                $error = 'Veuillez saisir votre e-mail ou nom d\'utilisateur.';
            } elseif (strlen($identifier) > 255) {
                $error = 'Identifiant trop long.';
            } else {
                // Rate limiting (5 tentatives / 30 min)
                $rl = checkRateLimit($pdo, 'reset:' . $identifier, 5, 30);
                if ($rl['blocked']) {
                    $error = "Trop de tentatives. Réessayez dans {$rl['remaining_minutes']} minute(s).";
                    securityLog($pdo, null, 'reset_rate_limited', "identifier=$identifier");
                } else {
                    // Chercher l'utilisateur (email OU nom)
                    // ANTI-ÉNUMÉRATION : message générique quelle que soit la situation
                    try {
                        $st = $pdo->prepare(
                            "SELECT id, prenom, nom, email, statut
                             FROM users
                             WHERE (email = ? OR LOWER(CONCAT(prenom,' ',nom)) = ?)
                             LIMIT 1"
                        );
                        $st->execute([$identifier, $identifier]);
                        $foundUser = $st->fetch();

                        // Traitement que l'utilisateur existe ou non (même délai)
                        if ($foundUser && $foundUser['statut'] === 'actif') {
                            $userId = (int)$foundUser['id'];

                            // Invalider anciens tokens
                            $pdo->prepare(
                                "UPDATE password_resets SET used_at = NOW()
                                 WHERE user_id = ? AND used_at IS NULL"
                            )->execute([$userId]);

                            // Générer token sécurisé
                            $token     = bin2hex(random_bytes(48));
                            $tokenHash = hash('sha256', $token);
                            $expires   = date('Y-m-d H:i:s', time() + 900); // 15 min

                            $pdo->prepare(
                                "INSERT INTO password_resets (user_id, token, token_hash, expires_at, ip, user_agent)
                                 VALUES (?, ?, ?, ?, ?, ?)"
                            )->execute([$userId, $token, $tokenHash, $expires, getClientIP(), getUserAgent()]);

                            $emailSent = sendResetEmail($foundUser, $token, $expires);
                            if (!$emailSent) {
                                error_log("Reset email failed for user_id=$userId");
                            }

                            securityLog($pdo, $userId, 'reset_requested', "identifier=$identifier, email_sent=" . ($emailSent ? 'yes' : 'no'));
                        } else {
                            // Utilisateur non trouvé ou inactif → log discret
                            securityLog($pdo, null, 'reset_not_found', "identifier=$identifier");
                            // Pause artificielle pour éviter timing attack
                            usleep(random_int(150000, 350000));
                        }

                        // Message générique TOUJOURS (anti-énumération)
                        $mode    = 'sent';
                        $success = '';
                    } catch (PDOException $e) {
                        error_log('Reset request error: ' . $e->getMessage());
                        $error = 'Une erreur technique est survenue. Veuillez réessayer dans quelques instants.';
                    }
                }
            }
        }
    }

    // ── ACTION : RESET MOT DE PASSE ─────────────────────────
    elseif ($action === 'do_reset') {
        if (!verifyCsrf($_POST['_csrf'] ?? '')) {
            $error = 'Token de sécurité invalide. Rechargez la page.';
            $mode  = 'reset_form';
        } else {
            $postToken  = substr(trim($_POST['token'] ?? ''), 0, 128);
            $newPass    = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            $mode = 'reset_form';

            if (empty($postToken)) {
                $error = 'Token manquant.';
            } elseif ($newPass !== $confirmPass) {
                $error = 'Les mots de passe ne correspondent pas.';
            } else {
                // Validation robustesse
                $passErrors = [];
                if (strlen($newPass) < 8)                         $passErrors[] = 'Au moins 8 caractères';
                if (!preg_match('/[A-Z]/', $newPass))             $passErrors[] = 'Une majuscule';
                if (!preg_match('/[a-z]/', $newPass))             $passErrors[] = 'Une minuscule';
                if (!preg_match('/[0-9]/', $newPass))             $passErrors[] = 'Un chiffre';
                if (!preg_match('/[\W_]/', $newPass))             $passErrors[] = 'Un caractère spécial';

                if (!empty($passErrors)) {
                    $error = 'Mot de passe trop faible. Manque : ' . implode(', ', $passErrors) . '.';
                } else {
                    try {
                        // Re-vérifier token (atomique)
                        $st = $pdo->prepare(
                            "SELECT pr.*, u.id AS uid, u.prenom, u.nom, u.email, u.password AS current_hash
                             FROM password_resets pr
                             JOIN users u ON u.id = pr.user_id
                             WHERE pr.token = ?
                               AND pr.used_at IS NULL
                               AND pr.expires_at > NOW()
                             LIMIT 1"
                        );
                        $st->execute([$postToken]);
                        $tr = $st->fetch();

                        if (!$tr) {
                            $error = 'Lien expiré ou déjà utilisé. Veuillez refaire une demande.';
                            $mode  = 'request';
                        } else {
                            // Empêcher réutilisation de l'ancien mot de passe
                            if (password_verify($newPass, $tr['current_hash'])) {
                                $error = 'Le nouveau mot de passe doit être différent de l\'ancien.';
                            } else {
                                $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);

                                $pdo->beginTransaction();
                                try {
                                    // Mettre à jour le mot de passe
                                    $pdo->prepare(
                                        "UPDATE users
                                         SET password = ?,
                                             password_changed_at = NOW(),
                                             block_count = 0,
                                             blocked_at = NULL
                                         WHERE id = ?"
                                    )->execute([$newHash, (int)$tr['uid']]);

                                    // Marquer le token comme utilisé
                                    $pdo->prepare(
                                        "UPDATE password_resets SET used_at = NOW() WHERE token = ?"
                                    )->execute([$postToken]);

                                    // Invalider toutes les sessions (si table existe)
                                    try {
                                        $pdo->prepare(
                                            "DELETE FROM user_sessions WHERE user_id = ?"
                                        )->execute([(int)$tr['uid']]);
                                    } catch (PDOException $e2) { /* table optionnelle */ }

                                    $pdo->commit();

                                    securityLog($pdo, (int)$tr['uid'], 'password_reset_success', 'Password changed via reset link');
                                    sendConfirmEmail($tr);

                                    $mode    = 'reset_done';
                                    $success = 'Mot de passe réinitialisé avec succès !';
                                } catch (Throwable $e) {
                                    $pdo->rollBack();
                                    error_log('Reset transaction error: ' . $e->getMessage());
                                    $error = 'Erreur lors de la mise à jour. Veuillez réessayer.';
                                }
                            }
                        }
                    } catch (PDOException $e) {
                        error_log('Reset form error: ' . $e->getMessage());
                        $error = 'Erreur technique. Veuillez réessayer.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mot de passe oublié — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══════════════ RESET & VARS ══════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#060810;
  --surface:#0b0e1a;
  --card:#0f1320;
  --glass:rgba(255,255,255,.035);
  --glass2:rgba(255,255,255,.06);
  --border:rgba(255,255,255,.07);
  --border-h:rgba(255,255,255,.13);
  --gold:#e8c97d;
  --ember:#ff6b35;
  --sage:#4ecca3;
  --azure:#4a9eff;
  --rose:#ff4d6d;
  --violet:#7c3aed;
  --txt:#f0eeea;
  --txt2:rgba(240,238,234,.6);
  --txt3:rgba(240,238,234,.28);
  --r:12px;
  --rl:18px;
  --rx:24px;
  --ease:cubic-bezier(.34,1.56,.64,1);
  --ease-s:cubic-bezier(.25,.46,.45,.94);
}
html{height:100%;scroll-behavior:smooth}
body{
  min-height:100vh;
  font-family:'DM Sans',sans-serif;
  background:var(--bg);
  color:var(--txt);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:2rem 1.2rem;
  overflow-x:hidden;
  position:relative;
}
::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-thumb{background:rgba(232,201,125,.3);border-radius:3px}

/* ══ Ambient background ══ */
.orb{position:fixed;border-radius:50%;filter:blur(100px);pointer-events:none;z-index:0;animation:drift 22s ease-in-out infinite}
.orb-a{width:700px;height:700px;top:-250px;left:-200px;background:rgba(124,58,237,.05);animation-delay:0s}
.orb-b{width:500px;height:500px;bottom:-150px;right:-150px;background:rgba(232,201,125,.04);animation-delay:-9s}
.orb-c{width:380px;height:380px;top:40%;left:55%;background:rgba(255,107,53,.035);animation-delay:-16s}
@keyframes drift{
  0%,100%{transform:translate(0,0) scale(1)}
  33%{transform:translate(40px,-30px) scale(1.06)}
  66%{transform:translate(-30px,40px) scale(.94)}
}

/* GRID */
.grid-bg{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(232,201,125,.018) 1px,transparent 1px),
    linear-gradient(90deg,rgba(232,201,125,.018) 1px,transparent 1px);
  background-size:55px 55px;
  mask-image:radial-gradient(ellipse at 50% 40%,black 15%,transparent 70%);
}

/* ══ WRAPPER ══ */
.wrap{
  position:relative;z-index:1;
  width:100%;max-width:1080px;
  display:grid;grid-template-columns:1fr 1fr;
  border-radius:var(--rx);
  overflow:hidden;
  border:1px solid var(--border);
  box-shadow:0 60px 140px rgba(0,0,0,.8),0 0 0 1px rgba(232,201,125,.04),inset 0 1px 0 rgba(255,255,255,.05);
  animation:wrapIn .5s var(--ease-s) both;
  min-height:580px;
}
@keyframes wrapIn{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:840px){.wrap{grid-template-columns:1fr;max-width:460px}.promo{display:none}}

/* ══ PROMO PANEL ══ */
.promo{
  background:linear-gradient(155deg,#06090f 0%,#0a091a 65%,#050d12 100%);
  padding:2.8rem 2.6rem;
  display:flex;flex-direction:column;justify-content:space-between;
  position:relative;overflow:hidden;
  border-right:1px solid rgba(255,255,255,.05);
}
.promo-top-bar{
  position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--violet),var(--gold),var(--ember),var(--sage));
  background-size:300% auto;animation:barFlow 5s linear infinite;
}
@keyframes barFlow{0%{background-position:0%}100%{background-position:300%}}

.promo-deco{
  position:absolute;right:-16px;bottom:80px;font-size:8rem;
  opacity:.03;pointer-events:none;user-select:none;
  animation:bookFloat 8s ease-in-out infinite;
}
@keyframes bookFloat{0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-18px) rotate(3deg)}}

.promo-logo{
  display:flex;align-items:center;gap:11px;
  text-decoration:none;color:var(--txt);
  font-family:'Syne',sans-serif;font-weight:800;font-size:.95rem;
  position:relative;z-index:1;
}
.logo-ico{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,var(--gold),var(--ember));
  display:flex;align-items:center;justify-content:center;font-size:.9rem;
  box-shadow:0 0 30px rgba(232,201,125,.18);
}

.promo-mid{position:relative;z-index:1;padding:.5rem 0}
.promo-tag{
  display:inline-flex;align-items:center;gap:7px;margin-bottom:1.4rem;
  font-family:'JetBrains Mono',monospace;font-size:.6rem;letter-spacing:.1em;
  color:var(--sage);border:1px solid rgba(78,204,163,.25);background:rgba(78,204,163,.05);
  padding:4px 12px;border-radius:100px;text-transform:uppercase;
}
.tag-dot{width:5px;height:5px;border-radius:50%;background:var(--sage);animation:blink 1.8s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.1}}

.promo-h{
  font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;
  letter-spacing:-1.5px;line-height:1.1;margin-bottom:.9rem;
}
.g-gold{background:linear-gradient(135deg,var(--gold) 20%,var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-size:200% auto;animation:shimmer 4s linear infinite}
.g-sage{background:linear-gradient(135deg,var(--sage),var(--azure));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
@keyframes shimmer{to{background-position:200% center}}

.promo-p{font-size:.8rem;color:var(--txt2);line-height:1.85;margin-bottom:1.6rem}

.steps{display:flex;flex-direction:column;gap:.65rem}
.step{
  display:flex;align-items:flex-start;gap:12px;padding:.65rem .9rem;
  border-radius:var(--r);background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.04);
  font-size:.76rem;color:var(--txt2);line-height:1.6;
  transition:border-color .2s,background .2s;
}
.step:hover{background:rgba(232,201,125,.03);border-color:rgba(232,201,125,.12)}
.step-ico{
  width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;
  font-size:.75rem;flex-shrink:0;margin-top:1px;
}
.si-1{background:rgba(232,201,125,.1);color:var(--gold)}
.si-2{background:rgba(255,107,53,.1);color:var(--ember)}
.si-3{background:rgba(78,204,163,.1);color:var(--sage)}
.si-4{background:rgba(74,158,255,.1);color:var(--azure)}

.promo-bot{position:relative;z-index:1;display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;padding-top:1.2rem;border-top:1px solid var(--border)}
.pstat{text-align:center;padding:.45rem;border-radius:var(--r);background:rgba(255,255,255,.02)}
.pstat-v{font-family:'Syne',sans-serif;font-size:1.25rem;font-weight:800;background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-.5px}
.pstat-l{font-family:'JetBrains Mono',monospace;font-size:.55rem;color:var(--txt3);text-transform:uppercase;letter-spacing:.07em;margin-top:2px}

/* ══ FORM PANEL ══ */
.panel{
  background:var(--card);
  padding:2.6rem 2.8rem;
  display:flex;flex-direction:column;justify-content:center;
  position:relative;overflow:hidden;
}
.panel::before{
  content:'';position:absolute;top:-80px;right:-80px;width:220px;height:220px;border-radius:50%;
  background:radial-gradient(circle,rgba(232,201,125,.05),transparent 70%);pointer-events:none;
}
.panel::after{
  content:'';position:absolute;bottom:-60px;left:-60px;width:180px;height:180px;border-radius:50%;
  background:radial-gradient(circle,rgba(124,58,237,.04),transparent 70%);pointer-events:none;
}

/* Breadcrumb */
.breadcrumb{
  display:flex;align-items:center;gap:7px;font-family:'JetBrains Mono',monospace;
  font-size:.6rem;letter-spacing:.1em;color:var(--txt3);text-transform:uppercase;
  margin-bottom:.9rem;
}
.bc-sep{opacity:.35}
.bc-cur{color:var(--gold)}

/* Panel header */
.panel-icon{
  width:52px;height:52px;border-radius:14px;margin-bottom:1.2rem;
  display:flex;align-items:center;justify-content:center;font-size:1.4rem;
  background:linear-gradient(135deg,rgba(232,201,125,.12),rgba(255,107,53,.08));
  border:1px solid rgba(232,201,125,.15);
  box-shadow:0 0 24px rgba(232,201,125,.08);
}
.panel-title{
  font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;
  letter-spacing:-1px;line-height:1.1;margin-bottom:.35rem;
}
.panel-title .hl{background:linear-gradient(135deg,var(--gold),var(--ember));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.panel-sub{font-size:.8rem;color:var(--txt2);line-height:1.75;margin-bottom:1.6rem}

/* Alert */
.alert{
  display:flex;align-items:flex-start;gap:10px;padding:13px 15px;border-radius:var(--r);
  margin-bottom:1.1rem;font-size:.79rem;line-height:1.65;
  animation:alertIn .3s var(--ease) both;
}
@keyframes alertIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.alert-err{background:rgba(255,77,109,.07);border:1px solid rgba(255,77,109,.28);color:#ff8fa0}
.alert-ok {background:rgba(78,204,163,.06);border:1px solid rgba(78,204,163,.25);color:var(--sage)}
.alert-info{background:rgba(74,158,255,.06);border:1px solid rgba(74,158,255,.22);color:var(--azure)}
.alert i{flex-shrink:0;font-size:1rem;margin-top:1px}
.alert-title{font-weight:700;display:block;margin-bottom:2px;font-family:'Syne',sans-serif}

/* Form elements */
.field-g{display:flex;flex-direction:column;gap:.38rem;margin-bottom:.95rem}
.field-label{font-size:.7rem;font-weight:600;letter-spacing:.04em;color:var(--txt2);display:flex;align-items:center;gap:6px}
.field-wrap{position:relative}
.field-ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:.88rem;pointer-events:none;transition:color .2s}
.field-input{
  width:100%;padding:13px 14px 13px 42px;
  background:rgba(255,255,255,.04);
  border:1px solid var(--border);
  border-radius:var(--r);
  color:var(--txt);
  font-family:'DM Sans',sans-serif;font-size:.85rem;
  outline:none;
  transition:border-color .22s,box-shadow .22s,background .22s;
}
.field-input:focus{border-color:rgba(232,201,125,.4);box-shadow:0 0 0 3px rgba(232,201,125,.07);background:rgba(232,201,125,.02)}
.field-wrap:focus-within .field-ico{color:var(--gold)}
.field-input::placeholder{color:var(--txt3)}
.field-input.has-toggle{padding-right:46px}

/* Password toggle */
.pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--txt3);cursor:pointer;padding:4px;border-radius:6px;font-size:.88rem;transition:color .2s}
.pw-toggle:hover{color:var(--gold)}

/* Strength meter */
.strength-wrap{margin-top:.5rem;display:none}
.strength-bars{display:flex;gap:4px;margin-bottom:4px}
.sb{flex:1;height:3px;border-radius:3px;background:rgba(255,255,255,.08);transition:background .35s}
.sb.s-weak{background:var(--rose)}
.sb.s-fair{background:var(--ember)}
.sb.s-good{background:var(--gold)}
.sb.s-strong{background:var(--sage)}
.strength-txt{font-family:'JetBrains Mono',monospace;font-size:.58rem;color:var(--txt3)}

/* Requirements checklist */
.req-list{display:flex;flex-direction:column;gap:.32rem;margin-top:.5rem}
.req-item{display:flex;align-items:center;gap:7px;font-size:.72rem;color:var(--txt3);transition:color .25s}
.req-item.ok{color:var(--sage)}
.req-item.fail{color:var(--rose)}
.req-dot{width:14px;height:14px;border-radius:50%;border:1px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:.55rem;flex-shrink:0;transition:all .25s}
.req-item.ok .req-dot{background:rgba(78,204,163,.15)}
.req-item.fail .req-dot{background:rgba(255,77,109,.12)}

/* Submit button */
.btn-submit{
  width:100%;padding:14px;border:none;border-radius:var(--r);cursor:pointer;
  font-family:'Syne',sans-serif;font-size:.92rem;font-weight:700;letter-spacing:.03em;
  background:linear-gradient(135deg,var(--gold) 0%,var(--ember) 100%);
  color:#07090f;
  display:flex;align-items:center;justify-content:center;gap:8px;
  box-shadow:0 8px 28px rgba(232,201,125,.22),0 2px 8px rgba(0,0,0,.3);
  transition:transform .22s var(--ease),box-shadow .22s,opacity .22s;
  position:relative;overflow:hidden;margin-top:.25rem;
}
.btn-submit::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.14),transparent);opacity:0;transition:opacity .2s}
.btn-submit:hover:not(:disabled){transform:translateY(-3px);box-shadow:0 14px 40px rgba(232,201,125,.35)}
.btn-submit:hover::before{opacity:1}
.btn-submit:active{transform:translateY(-1px)}
.btn-submit:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-submit.loading{pointer-events:none}
.btn-lbl{transition:opacity .2s}
.btn-submit.loading .btn-lbl{opacity:0;position:absolute}
.spinner{width:18px;height:18px;border:2px solid rgba(7,9,15,.25);border-top-color:#07090f;border-radius:50%;animation:spin .7s linear infinite;display:none}
.btn-submit.loading .spinner{display:block}
@keyframes spin{to{transform:rotate(360deg)}}
.btn-submit::after{content:'';position:absolute;top:0;left:-100%;width:55%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.16),transparent);animation:btnShimmer 3s ease-in-out infinite}
@keyframes btnShimmer{0%{left:-100%}100%{left:220%}}

/* Back link */
.link-back{
  display:inline-flex;align-items:center;gap:6px;
  font-size:.73rem;color:var(--txt3);text-decoration:none;
  padding:7px 14px;border-radius:100px;border:1px solid var(--border);
  margin-top:.9rem;transition:all .2s;align-self:center;
  background:transparent;cursor:pointer;border:none;font-family:'DM Sans',sans-serif;
}
.link-back:hover{color:var(--gold);border-color:rgba(232,201,125,.25)}
a.link-back{border:1px solid var(--border)}

/* ══ SUCCESS STATE ══ */
.success-state{
  display:flex;flex-direction:column;align-items:center;text-align:center;
  padding:1rem 0;animation:wrapIn .5s var(--ease) both;
}
.success-ring{
  width:76px;height:76px;border-radius:50%;
  background:linear-gradient(135deg,rgba(78,204,163,.12),rgba(74,158,255,.08));
  border:1px solid rgba(78,204,163,.25);
  display:flex;align-items:center;justify-content:center;font-size:2.2rem;
  margin-bottom:1.2rem;
  box-shadow:0 0 40px rgba(78,204,163,.1);
  animation:successPop .6s var(--ease) .1s both;
}
@keyframes successPop{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
.success-title{font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800;letter-spacing:-.5px;margin-bottom:.5rem;color:var(--sage)}
.success-text{font-size:.82rem;color:var(--txt2);line-height:1.8;max-width:320px}
.timer-badge{
  display:flex;align-items:center;gap:8px;
  margin:1.2rem 0;padding:10px 18px;border-radius:100px;
  background:rgba(232,201,125,.07);border:1px solid rgba(232,201,125,.2);
  font-family:'JetBrains Mono',monospace;font-size:.72rem;color:var(--gold);
}
.timer-val{font-size:.9rem;font-weight:700;min-width:35px}

/* ══ RESET DONE ══ */
.done-state{
  display:flex;flex-direction:column;align-items:center;text-align:center;
  padding:1rem 0;animation:wrapIn .5s var(--ease) both;
}
.done-ring{
  width:76px;height:76px;border-radius:50%;
  background:linear-gradient(135deg,rgba(78,204,163,.12),rgba(74,158,255,.1));
  border:1px solid rgba(78,204,163,.3);
  display:flex;align-items:center;justify-content:center;font-size:2.2rem;
  margin-bottom:1.2rem;
  box-shadow:0 0 50px rgba(78,204,163,.12);
  animation:successPop .6s var(--ease) .1s both;
}
.done-title{font-family:'Syne',sans-serif;font-size:1.35rem;font-weight:800;letter-spacing:-.5px;margin-bottom:.5rem;color:var(--sage)}
.done-text{font-size:.82rem;color:var(--txt2);line-height:1.8;margin-bottom:1.4rem;max-width:300px}

/* Footer */
.panel-footer{margin-top:1.3rem;text-align:center;font-size:.76rem;color:var(--txt3)}
.panel-footer a{color:var(--gold);text-decoration:none;font-weight:600;transition:opacity .2s}
.panel-footer a:hover{opacity:.8;text-decoration:underline}

/* Security hint */
.sec-hint{
  display:flex;align-items:center;gap:8px;padding:.7rem .9rem;border-radius:var(--r);
  background:rgba(78,204,163,.04);border:1px solid rgba(78,204,163,.12);
  font-size:.72rem;color:var(--txt3);margin-top:.9rem;line-height:1.55;
}
.sec-hint i{color:var(--sage);flex-shrink:0}

/* Divider */
.divider{height:1px;background:var(--border);margin:.8rem 0}

@media(max-width:480px){
  .panel{padding:2rem 1.5rem}
  .panel-title{font-size:1.3rem}
}
</style>
</head>
<body>

<div class="orb orb-a"></div>
<div class="orb orb-b"></div>
<div class="orb orb-c"></div>
<div class="grid-bg"></div>

<div class="wrap">

  <!-- ══ PROMO PANEL ══ -->
  <div class="promo">
    <div class="promo-top-bar"></div>
    <div class="promo-deco">🔐</div>

    <a href="index.php" class="promo-logo">
      <div class="logo-ico">📚</div>
      Digital <span style="color:var(--gold);margin-left:3px">Library</span>
    </a>

    <div class="promo-mid">
      <div class="promo-tag"><div class="tag-dot"></div>Récupération sécurisée</div>
      <h2 class="promo-h">
        Reprenez le<br>
        contrôle de votre<br>
        <span class="g-gold">compte</span> <span class="g-sage">facilement</span>
      </h2>
      <p class="promo-p">Notre système de récupération est sécurisé, chiffré et conçu pour protéger votre bibliothèque personnelle.</p>
      <div class="steps">
        <div class="step"><div class="step-ico si-1"><i class="bi bi-envelope-at"></i></div><span>Saisissez votre e-mail ou nom d'utilisateur</span></div>
        <div class="step"><div class="step-ico si-2"><i class="bi bi-send"></i></div><span>Recevez un lien sécurisé valable <strong style="color:var(--ember)">15 minutes</strong></span></div>
        <div class="step"><div class="step-ico si-3"><i class="bi bi-shield-lock"></i></div><span>Créez un nouveau mot de passe robuste</span></div>
        <div class="step"><div class="step-ico si-4"><i class="bi bi-check2-circle"></i></div><span>Reconnectez-vous et accédez à votre bibliothèque</span></div>
      </div>
    </div>

    <div class="promo-bot">
      <div class="pstat"><div class="pstat-v">256-bit</div><div class="pstat-l">Chiffrement</div></div>
      <div class="pstat"><div class="pstat-v">15 min</div><div class="pstat-l">Expiration</div></div>
      <div class="pstat"><div class="pstat-v">1×</div><div class="pstat-l">Utilisation</div></div>
    </div>
  </div>

  <!-- ══ FORM PANEL ══ -->
  <div class="panel">

    <?php if ($mode === 'request'): ?>

    <!-- ── FORMULAIRE DEMANDE ── -->
    <div class="breadcrumb">
      <span>Accueil</span><span class="bc-sep">/</span>
      <span>Connexion</span><span class="bc-sep">/</span>
      <span class="bc-cur">Mot de passe oublié</span>
    </div>

    <div class="panel-icon">🔑</div>
    <h1 class="panel-title">Mot de passe<br><span class="hl">oublié ?</span></h1>
    <p class="panel-sub">Pas d'inquiétude. Entrez votre adresse e-mail ou votre nom d'utilisateur et nous vous enverrons un lien de récupération.</p>

    <?php if ($error): ?>
    <div class="alert alert-err">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div><span class="alert-title">Erreur</span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <form method="POST" id="requestForm" novalidate>
      <input type="hidden" name="_action" value="request_reset">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="field-g">
        <label class="field-label" for="identifier">
          <i class="bi bi-person-circle"></i> E-mail ou nom d'utilisateur
        </label>
        <div class="field-wrap">
          <i class="bi bi-envelope field-ico" id="idIcon"></i>
          <input type="text" id="identifier" name="identifier" class="field-input"
                 placeholder="votre@email.com"
                 value="<?= htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 autocomplete="email username" maxlength="255"
                 oninput="updateIcon(this.value)">
        </div>
      </div>

      <button type="submit" class="btn-submit" id="reqBtn">
        <span class="btn-lbl"><i class="bi bi-send"></i> Envoyer le lien de récupération</span>
        <div class="spinner"></div>
      </button>
    </form>

    <div class="sec-hint">
      <i class="bi bi-shield-check"></i>
      <span>Le message affiché est identique que le compte existe ou non, pour protéger votre confidentialité.</span>
    </div>

    <div class="panel-footer">
      <a href="login.php"><i class="bi bi-arrow-left"></i> Retour à la connexion</a>
    </div>

    <?php elseif ($mode === 'sent'): ?>

    <!-- ── EMAIL ENVOYÉ ── -->
    <div class="success-state">
      <div class="success-ring">📨</div>
      <div class="success-title">Vérifiez votre boîte mail !</div>
      <p class="success-text">
        Si un compte est associé à cet identifiant, vous recevrez dans quelques instants un e-mail avec un lien de réinitialisation.
      </p>

      <div class="timer-badge">
        <i class="bi bi-clock"></i>
        Lien valide pendant
        <span class="timer-val" id="timer">15:00</span>
      </div>

      <div class="alert alert-info" style="width:100%;text-align:left">
        <i class="bi bi-info-circle"></i>
        <div>
          <span class="alert-title">Vous ne recevez rien ?</span>
          Vérifiez votre dossier spam. Si le problème persiste, contactez le support.
        </div>
      </div>

      <div class="divider" style="width:100%"></div>
      <div class="panel-footer" style="width:100%">
        <a href="forgot-password.php">↩ Faire une nouvelle demande</a>
        &nbsp;·&nbsp;
        <a href="login.php">Retour connexion</a>
      </div>
    </div>

    <?php elseif ($mode === 'reset_form' && $tokenData): ?>

    <!-- ── FORMULAIRE NOUVEAU MOT DE PASSE ── -->
    <div class="breadcrumb">
      <span>E-mail</span><span class="bc-sep">→</span>
      <span>Lien reçu</span><span class="bc-sep">→</span>
      <span class="bc-cur">Nouveau mot de passe</span>
    </div>

    <div class="panel-icon">🛡️</div>
    <h1 class="panel-title">Nouveau<br><span class="hl">mot de passe</span></h1>
    <p class="panel-sub">
      Bonjour <strong><?= htmlspecialchars($tokenData['prenom'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong> !
      Choisissez un mot de passe robuste pour sécuriser votre compte.
    </p>

    <?php if ($error): ?>
    <div class="alert alert-err">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <form method="POST" id="resetForm" novalidate>
      <input type="hidden" name="_action" value="do_reset">
      <input type="hidden" name="_csrf"  value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="token"  value="<?= htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="field-g">
        <label class="field-label" for="new_password"><i class="bi bi-lock"></i> Nouveau mot de passe</label>
        <div class="field-wrap">
          <i class="bi bi-lock field-ico"></i>
          <input type="password" id="new_password" name="new_password" class="field-input has-toggle"
                 placeholder="••••••••••" autocomplete="new-password" maxlength="128"
                 oninput="evalStrength(this.value)">
          <button type="button" class="pw-toggle" onclick="togglePw('new_password','eye1')"><i class="bi bi-eye" id="eye1"></i></button>
        </div>
        <div class="strength-wrap" id="strengthWrap">
          <div class="strength-bars">
            <div class="sb" id="sb1"></div><div class="sb" id="sb2"></div>
            <div class="sb" id="sb3"></div><div class="sb" id="sb4"></div>
          </div>
          <div class="strength-txt" id="strengthTxt">Force : —</div>
        </div>
        <div class="req-list" id="reqList">
          <div class="req-item" id="r-len"><div class="req-dot">✓</div> 8 caractères minimum</div>
          <div class="req-item" id="r-upp"><div class="req-dot">✓</div> Une majuscule (A–Z)</div>
          <div class="req-item" id="r-low"><div class="req-dot">✓</div> Une minuscule (a–z)</div>
          <div class="req-item" id="r-num"><div class="req-dot">✓</div> Un chiffre (0–9)</div>
          <div class="req-item" id="r-spe"><div class="req-dot">✓</div> Un caractère spécial (!@#...)</div>
        </div>
      </div>

      <div class="field-g">
        <label class="field-label" for="confirm_password"><i class="bi bi-lock-fill"></i> Confirmer le mot de passe</label>
        <div class="field-wrap">
          <i class="bi bi-lock-fill field-ico"></i>
          <input type="password" id="confirm_password" name="confirm_password" class="field-input has-toggle"
                 placeholder="••••••••••" autocomplete="new-password" maxlength="128"
                 oninput="checkMatch()">
          <button type="button" class="pw-toggle" onclick="togglePw('confirm_password','eye2')"><i class="bi bi-eye" id="eye2"></i></button>
        </div>
        <div id="matchHint" style="font-size:.7rem;margin-top:.3rem;display:none"></div>
      </div>

      <button type="submit" class="btn-submit" id="resetBtn" disabled>
        <span class="btn-lbl"><i class="bi bi-check-circle"></i> Confirmer le nouveau mot de passe</span>
        <div class="spinner"></div>
      </button>
    </form>

    <div class="panel-footer"><a href="login.php"><i class="bi bi-arrow-left"></i> Retour connexion</a></div>

    <?php elseif ($mode === 'reset_done'): ?>

    <!-- ── MOT DE PASSE CHANGÉ ── -->
    <div class="done-state">
      <div class="done-ring">✅</div>
      <div class="done-title">Mot de passe mis à jour !</div>
      <p class="done-text">
        Votre mot de passe a été réinitialisé avec succès. Toutes vos sessions actives ont été déconnectées pour votre sécurité.<br><br>
        Un e-mail de confirmation vous a été envoyé.
      </p>

      <a href="login.php" class="btn-submit" style="text-decoration:none;width:auto;padding:13px 32px">
        <i class="bi bi-box-arrow-in-right"></i> Se connecter maintenant
      </a>

      <div class="sec-hint" style="margin-top:1rem;max-width:340px">
        <i class="bi bi-shield-check"></i>
        <span>Si vous n'avez pas effectué ce changement, contactez immédiatement le support.</span>
      </div>
    </div>

    <?php endif; ?>

  </div><!-- /panel -->
</div><!-- /wrap -->

<script>
'use strict';

/* ── Icon toggle (email vs user) ── */
function updateIcon(val) {
  const ico = document.getElementById('idIcon');
  if (!ico) return;
  ico.className = val.includes('@') ? 'bi bi-envelope field-ico' : 'bi bi-person field-ico';
}

/* ── Toggle password visibility ── */
function togglePw(inputId, iconId) {
  const inp = document.getElementById(inputId);
  const ico = document.getElementById(iconId);
  if (!inp || !ico) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
}

/* ── Password strength evaluator ── */
const rules = [
  { id: 'r-len', test: v => v.length >= 8 },
  { id: 'r-upp', test: v => /[A-Z]/.test(v) },
  { id: 'r-low', test: v => /[a-z]/.test(v) },
  { id: 'r-num', test: v => /[0-9]/.test(v) },
  { id: 'r-spe', test: v => /[\W_]/.test(v) },
];
const levels = [
  { cls: 's-weak',   lbl: '🔴 Très faible', bars: 1 },
  { cls: 's-weak',   lbl: '🔴 Faible',       bars: 1 },
  { cls: 's-fair',   lbl: '🟡 Passable',      bars: 2 },
  { cls: 's-good',   lbl: '🟠 Bien',          bars: 3 },
  { cls: 's-strong', lbl: '🟢 Fort',          bars: 4 },
  { cls: 's-strong', lbl: '🟢 Très fort',     bars: 4 },
];

function evalStrength(val) {
  const sw = document.getElementById('strengthWrap');
  const st = document.getElementById('strengthTxt');
  const btn = document.getElementById('resetBtn');

  if (sw) sw.style.display = val.length > 0 ? 'block' : 'none';

  let score = 0;
  rules.forEach(r => {
    const el = document.getElementById(r.id);
    const ok = r.test(val);
    if (ok) score++;
    if (el) {
      el.classList.toggle('ok', ok);
      el.classList.toggle('fail', val.length > 0 && !ok);
    }
  });

  const lvl = levels[Math.max(0, Math.min(score, 5))];
  [1,2,3,4].forEach(i => {
    const b = document.getElementById('sb'+i);
    if (!b) return;
    b.className = 'sb';
    if (i <= lvl.bars) b.classList.add(lvl.cls);
  });
  if (st) st.textContent = 'Force : ' + lvl.lbl;

  checkMatch();

  // Enable only if all rules pass
  if (btn) btn.disabled = score < 5;
}

let passOk = false, matchOk = false;

function checkMatch() {
  const pw  = document.getElementById('new_password')?.value || '';
  const cpw = document.getElementById('confirm_password')?.value || '';
  const hint = document.getElementById('matchHint');
  const btn  = document.getElementById('resetBtn');

  const score = rules.filter(r => r.test(pw)).length;
  passOk  = score >= 5;

  if (cpw.length === 0) {
    if (hint) hint.style.display = 'none';
    matchOk = false;
  } else if (pw === cpw) {
    if (hint) { hint.style.display = 'block'; hint.style.color = 'var(--sage)'; hint.textContent = '✓ Les mots de passe correspondent'; }
    matchOk = true;
  } else {
    if (hint) { hint.style.display = 'block'; hint.style.color = 'var(--rose)'; hint.textContent = '✗ Les mots de passe ne correspondent pas'; }
    matchOk = false;
  }

  if (btn) btn.disabled = !(passOk && matchOk);
}

/* ── Form loading states ── */
document.getElementById('requestForm')?.addEventListener('submit', function(e) {
  const id = document.getElementById('identifier')?.value.trim();
  if (!id) { e.preventDefault(); return; }
  const btn = document.getElementById('reqBtn');
  if (btn) btn.classList.add('loading');
});

document.getElementById('resetForm')?.addEventListener('submit', function(e) {
  const pw  = document.getElementById('new_password')?.value || '';
  const cpw = document.getElementById('confirm_password')?.value || '';
  if (pw !== cpw || rules.filter(r => r.test(pw)).length < 5) {
    e.preventDefault();
    return;
  }
  const btn = document.getElementById('resetBtn');
  if (btn) btn.classList.add('loading');
});

/* ── Countdown timer (mode sent) ── */
const timerEl = document.getElementById('timer');
if (timerEl) {
  let secs = 15 * 60;
  const t = setInterval(() => {
    secs--;
    if (secs <= 0) { clearInterval(t); timerEl.textContent = 'Expiré'; timerEl.style.color = 'var(--rose)'; return; }
    const m = Math.floor(secs / 60).toString().padStart(2, '0');
    const s = (secs % 60).toString().padStart(2, '0');
    timerEl.textContent = `${m}:${s}`;
    if (secs < 120) timerEl.style.color = 'var(--rose)';
  }, 1000);
}
</script>
</body>
</html>