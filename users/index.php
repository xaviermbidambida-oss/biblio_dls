<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  DIGITAL LIBRARY SYSTEM — Gestion des Utilisateurs          ║
 * ║  Interface PREMIUM — Admin Only                              ║
 * ╚══════════════════════════════════════════════════════════════╝
 */

// ── DÉPENDANCES ──────────────────────────────────────────────
if (file_exists(__DIR__ . '/../config/config.php')) {
    require_once __DIR__ . '/../config/config.php';
}
if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
}

// ── SESSION ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── AUTH ADMIN ───────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    // DEMO : simuler une session admin si absente (retirer en prod)
    $_SESSION['user_id']   = 1;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_name'] = 'Admin';
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php?error=access_denied');
    exit;
}

$adminId   = (int)$_SESSION['user_id'];
$adminName = htmlspecialchars($_SESSION['user_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$avatar    = strtoupper(substr($adminName, 0, 1)) ?: 'A';

// ── PDO / DB ──────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host   = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $dbname = defined('DB_NAME') ? DB_NAME : 'digital_library';
    $user   = defined('DB_USER') ? DB_USER : 'root';
    $pass   = defined('DB_PASS') ? DB_PASS : '';
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

// ══════════════════════════════════════════════════════════════
// ✅ FONCTION CENTRALE : DÉTECTION AUTOMATIQUE DU RÔLE
// ══════════════════════════════════════════════════════════════
/**
 * detectUserRole — Analyse l'email et retourne le rôle correspondant.
 * Cette fonction est la SEULE autorité pour déterminer le rôle.
 * Elle ignore toute valeur envoyée par le frontend.
 *
 * @param  string $email  Email brut (sera nettoyé en interne)
 * @return string         'admin' | 'journaliste' | 'lecteur'
 */
function detectUserRole(string $email): string
{
    // 1. Nettoyage obligatoire
    $email = strtolower(trim($email));

    // 2. Pattern ADMIN : admin.<nom>@adminsopecam.com
    //    Exemples valides : admin.dupont@adminsopecam.com
    //                       admin.dupont5@adminsopecam.com
    if (preg_match('/^admin\.[a-z0-9]+@adminsopecam\.com$/', $email)) {
        return 'admin';
    }

    // 3. Pattern JOURNALISTE : journaliste.<nom>@sopecam.com
    //    Exemples valides : journaliste.charles@sopecam.com
    //                       journaliste.charles11@sopecam.com
    if (preg_match('/^journaliste\.[a-z0-9]+@sopecam\.com$/', $email)) {
        return 'journaliste';
    }

    // 4. Par défaut : LECTEUR
    return 'lecteur';
}

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ── HELPERS ──────────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── TRAITEMENT AJAX ──────────────────────────────────────────
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) jsonResponse(['success' => false, 'error' => 'Token CSRF invalide.'], 403);

    $action = trim($_POST['action'] ?? '');

    try {
        $db = getDB();

        // ── AJOUTER ──────────────────────────────────────────
        if ($action === 'add') {
            $prenom    = trim($_POST['prenom']    ?? '');
            $nom       = trim($_POST['nom']       ?? '');
            $email     = trim($_POST['email']     ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $statut    = $_POST['statut'] ?? 'actif';
            $password  = $_POST['password'] ?? '';

            if (!$prenom || !$nom || !$email || !$password)
                jsonResponse(['success' => false, 'error' => 'Champs obligatoires manquants.']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                jsonResponse(['success' => false, 'error' => 'Email invalide.']);
            if (!in_array($statut, ['actif','inactif','bloque'], true))
                jsonResponse(['success' => false, 'error' => 'Statut invalide.']);
            if (strlen($password) < 6)
                jsonResponse(['success' => false, 'error' => 'Mot de passe trop court (min 6 car.).']);

            // ✅ Rôle calculé AUTOMATIQUEMENT côté serveur — ignorer $_POST['role']
            $role = detectUserRole($email);

            // Vérif email unique
            $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) jsonResponse(['success' => false, 'error' => 'Cet email est déjà utilisé.']);

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmt = $db->prepare("
                INSERT INTO users (prenom, nom, email, telephone, role, statut, password, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$prenom, $nom, $email, $telephone ?: null, $role, $statut, $hash]);
            $newId = (int)$db->lastInsertId();

            $u = $db->prepare("SELECT id,nom,prenom,email,telephone,role,statut,created_at FROM users WHERE id=?");
            $u->execute([$newId]);
            $newUser = $u->fetch();

            jsonResponse([
                'success' => true,
                'message' => 'Utilisateur créé avec succès.',
                'user'    => $newUser,
                'role_detected' => $role,
            ]);
        }

        // ── MODIFIER ─────────────────────────────────────────
        if ($action === 'edit') {
            $id        = (int)($_POST['id'] ?? 0);
            $prenom    = trim($_POST['prenom']    ?? '');
            $nom       = trim($_POST['nom']       ?? '');
            $email     = trim($_POST['email']     ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $statut    = $_POST['statut'] ?? 'actif';
            $password  = $_POST['password'] ?? '';

            if (!$id || !$prenom || !$nom || !$email)
                jsonResponse(['success' => false, 'error' => 'Champs obligatoires manquants.']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                jsonResponse(['success' => false, 'error' => 'Email invalide.']);
            if (!in_array($statut, ['actif','inactif','bloque'], true))
                jsonResponse(['success' => false, 'error' => 'Statut invalide.']);

            // ✅ Rôle recalculé AUTOMATIQUEMENT à chaque modification — $_POST['role'] ignoré
            $role = detectUserRole($email);

            // Vérif existence utilisateur
            $chkExist = $db->prepare("SELECT id FROM users WHERE id = ?");
            $chkExist->execute([$id]);
            if (!$chkExist->fetch()) jsonResponse(['success' => false, 'error' => 'Utilisateur introuvable.']);

            // Vérif email unique (excluant cet utilisateur)
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $id]);
            if ($chk->fetch()) jsonResponse(['success' => false, 'error' => 'Cet email est déjà utilisé par un autre compte.']);

            if ($password) {
                if (strlen($password) < 6)
                    jsonResponse(['success' => false, 'error' => 'Mot de passe trop court (min 6 car.).']);
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("UPDATE users SET prenom=?,nom=?,email=?,telephone=?,role=?,statut=?,password=?,updated_at=NOW() WHERE id=?");
                $stmt->execute([$prenom, $nom, $email, $telephone ?: null, $role, $statut, $hash, $id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET prenom=?,nom=?,email=?,telephone=?,role=?,statut=?,updated_at=NOW() WHERE id=?");
                $stmt->execute([$prenom, $nom, $email, $telephone ?: null, $role, $statut, $id]);
            }

            $u = $db->prepare("SELECT id,nom,prenom,email,telephone,role,statut,created_at FROM users WHERE id=?");
            $u->execute([$id]);
            $updUser = $u->fetch();

            jsonResponse([
                'success'        => true,
                'message'        => 'Utilisateur modifié avec succès.',
                'user'           => $updUser,
                'role_detected'  => $role,
            ]);
        }

        // ── SUPPRIMER ─────────────────────────────────────────
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success' => false, 'error' => 'ID invalide.']);
            if ($id === $adminId) jsonResponse(['success' => false, 'error' => 'Impossible de supprimer votre propre compte.']);

            $chk = $db->prepare("SELECT id FROM users WHERE id = ?");
            $chk->execute([$id]);
            if (!$chk->fetch()) jsonResponse(['success' => false, 'error' => 'Utilisateur introuvable.']);

            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);

            jsonResponse(['success' => true, 'message' => 'Utilisateur supprimé avec succès.']);
        }

        // ── BLOQUER / DÉBLOQUER ──────────────────────────────
        if ($action === 'toggle_statut') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success' => false, 'error' => 'ID invalide.']);
            if ($id === $adminId) jsonResponse(['success' => false, 'error' => 'Impossible de bloquer votre propre compte.']);

            $current = $db->prepare("SELECT statut FROM users WHERE id=?");
            $current->execute([$id]);
            $row = $current->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Utilisateur introuvable.']);

            $newStatut = ($row['statut'] === 'bloque') ? 'actif' : 'bloque';
            $upd = $db->prepare("UPDATE users SET statut=?, updated_at=NOW() WHERE id=?");
            $upd->execute([$newStatut, $id]);

            jsonResponse([
                'success' => true,
                'message' => $newStatut === 'bloque' ? 'Utilisateur bloqué.' : 'Utilisateur débloqué.',
                'statut'  => $newStatut
            ]);
        }

        // ── RÉCUPÉRER USER (pour modal edit) ─────────────────
        if ($action === 'get') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) jsonResponse(['success' => false, 'error' => 'ID invalide.']);
            $u = $db->prepare("SELECT id,nom,prenom,email,telephone,role,statut FROM users WHERE id=?");
            $u->execute([$id]);
            $user = $u->fetch();
            if (!$user) jsonResponse(['success' => false, 'error' => 'Utilisateur introuvable.']);
            jsonResponse(['success' => true, 'user' => $user]);
        }

        // ── DÉTECTER RÔLE EN TEMPS RÉEL (AJAX preview) ──────
        // Permet au frontend d'afficher le rôle détecté sans modifier la DB
        if ($action === 'detect_role') {
            $email = trim($_POST['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['success' => false, 'error' => 'Email invalide.']);
            }
            $role = detectUserRole($email);
            jsonResponse(['success' => true, 'role' => $role]);
        }

        // ── EXPORT CSV ───────────────────────────────────────
        if ($action === 'export') {
            $rows = $db->query("SELECT id,prenom,nom,email,telephone,role,statut,created_at FROM users ORDER BY created_at DESC")->fetchAll();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="utilisateurs_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
            fputcsv($out, ['ID','Prénom','Nom','Email','Téléphone','Rôle','Statut','Inscription'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['id'],$r['prenom'],$r['nom'],$r['email'],$r['telephone']??'',$r['role'],$r['statut'],$r['created_at']], ';');
            }
            fclose($out);
            exit;
        }

    } catch (Throwable $e) {
        error_log('[USERS ERROR] ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()]);
    }

    jsonResponse(['success' => false, 'error' => 'Action inconnue.']);
}

// ── EXPORT CSV (GET) ─────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $db = getDB();
        $rows = $db->query("SELECT id,prenom,nom,email,telephone,role,statut,created_at FROM users ORDER BY created_at DESC")->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="utilisateurs_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['ID','Prénom','Nom','Email','Téléphone','Rôle','Statut','Inscription'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'],$r['prenom'],$r['nom'],$r['email'],$r['telephone']??'',$r['role'],$r['statut'],$r['created_at']], ';');
        }
        fclose($out);
        exit;
    } catch (Throwable $e) {
        die('Erreur export : ' . $e->getMessage());
    }
}

// ── CHARGEMENT INITIAL ────────────────────────────────────────
$users      = [];
$totalUsers = 0;
$statsData  = [];
$dbError    = '';

try {
    $db = getDB();

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = 12;
    $offset  = ($page - 1) * $limit;
    $search  = trim($_GET['search'] ?? '');
    $roleF   = $_GET['role']   ?? '';
    $statutF = $_GET['statut'] ?? '';

    $where  = [];
    $params = [];

    if ($search) {
        $where[]  = "(nom LIKE ? OR prenom LIKE ? OR email LIKE ?)";
        $like     = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($roleF && in_array($roleF, ['admin','journaliste','lecteur'], true)) {
        $where[]  = "role = ?";
        $params[] = $roleF;
    }
    if ($statutF && in_array($statutF, ['actif','inactif','bloque'], true)) {
        $where[]  = "statut = ?";
        $params[] = $statutF;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM users $whereClause");
    $countStmt->execute($params);
    $totalUsers = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalUsers / $limit));

    $stmt = $db->prepare("
        SELECT id, nom, prenom, email, telephone, role, statut, avatar, created_at
        FROM users $whereClause
        ORDER BY created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    // Stats
    $statsData = [
        'total'        => $totalUsers,
        'admins'       => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn(),
        'journalistes' => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='journaliste'")->fetchColumn(),
        'lecteurs'     => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='lecteur'")->fetchColumn(),
        'bloques'      => (int)$db->query("SELECT COUNT(*) FROM users WHERE statut='bloque'")->fetchColumn(),
        'this_month'   => (int)$db->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn(),
    ];

} catch (Throwable $e) {
    $dbError = $e->getMessage();
    error_log('[USERS LOAD] ' . $dbError);
}

// ── UTILS AFFICHAGE ──────────────────────────────────────────
function roleConfig(string $r): array {
    return [
        'admin'       => ['label'=>'Admin',       'color'=>'#f43f5e','bg'=>'rgba(244,63,94,.1)',   'icon'=>'⚡'],
        'journaliste' => ['label'=>'Journaliste', 'color'=>'#f59e0b','bg'=>'rgba(245,158,11,.1)',  'icon'=>'✍️'],
        'lecteur'     => ['label'=>'Lecteur',     'color'=>'#00ffaa','bg'=>'rgba(0,255,170,.08)',  'icon'=>'📖'],
    ][$r] ?? ['label'=>ucfirst($r),'color'=>'#aaa','bg'=>'rgba(255,255,255,.06)','icon'=>'👤'];
}

function statutConfig(string $s): array {
    return [
        'actif'   => ['label'=>'Actif',   'color'=>'#00ffaa','bg'=>'rgba(0,255,170,.1)'],
        'bloque'  => ['label'=>'Bloqué',  'color'=>'#f43f5e','bg'=>'rgba(244,63,94,.1)'],
        'inactif' => ['label'=>'Inactif', 'color'=>'#94a3b8','bg'=>'rgba(148,163,184,.1)'],
    ][$s] ?? ['label'=>ucfirst($s),'color'=>'#aaa','bg'=>'rgba(255,255,255,.06)'];
}

function initials(string $prenom, string $nom): string {
    return strtoupper(substr($prenom, 0, 1) . substr($nom, 0, 1)) ?: 'U';
}

function fmtDate(?string $d): string {
    if (!$d) return '—';
    try { return (new DateTime($d))->format('d/m/Y'); } catch (Exception $e) { return '—'; }
}

$totalPages = max(1, (int)ceil($totalUsers / 12));
?>
<!DOCTYPE html>
<html lang="fr" data-role="admin">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des Utilisateurs — Digital Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:wght@400;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ══ RESET & VARS ═══════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#05080f;
  --surface:#0b1020;
  --surface2:#0e1526;
  --card:rgba(255,255,255,.033);
  --card-hov:rgba(255,255,255,.058);
  --border:rgba(255,255,255,.072);
  --border-act:rgba(0,212,255,.4);
  --cyan:#00d4ff;
  --violet:#7c3aed;
  --neon:#00ffaa;
  --amber:#f59e0b;
  --rose:#f43f5e;
  --text:#eef2ff;
  --text2:rgba(238,242,255,.58);
  --text3:rgba(238,242,255,.28);
  --sidebar-w:240px;
  --topbar-h:60px;
  --r:12px;
  --r-lg:18px;
  --r-xl:28px;
  --shadow:0 8px 40px rgba(0,0,0,.5);
  --glow:0 0 30px rgba(0,212,255,.14);
}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;display:flex}
::-webkit-scrollbar{width:3px;height:3px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:4px}
::-webkit-scrollbar-track{background:transparent}

body::before{
  content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background:
    radial-gradient(ellipse 70% 50% at 15% 10%,rgba(0,212,255,.06),transparent 65%),
    radial-gradient(ellipse 60% 45% at 85% 85%,rgba(124,58,237,.07),transparent 65%),
    linear-gradient(rgba(255,255,255,.011) 1px,transparent 1px),
    linear-gradient(90deg,rgba(255,255,255,.011) 1px,transparent 1px);
  background-size:100% 100%,100% 100%,36px 36px,36px 36px;
}

/* ══ SIDEBAR ════════════════════════════════════════════════ */
#sidebar{
  position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);
  background:var(--surface);border-right:1px solid var(--border);
  z-index:200;display:flex;flex-direction:column;
  transition:transform .3s ease;backdrop-filter:blur(22px);
}
.sb-brand{
  height:var(--topbar-h);display:flex;align-items:center;gap:10px;
  padding:0 18px;border-bottom:1px solid var(--border);flex-shrink:0;
}
.sb-logo{
  width:34px;height:34px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,var(--cyan),var(--violet));
  display:flex;align-items:center;justify-content:center;font-size:.9rem;
  box-shadow:0 0 18px rgba(0,212,255,.28);
}
.sb-brand-text{font-family:'Syne',sans-serif;font-weight:800;font-size:.88rem}
.sb-brand-text em{color:var(--cyan);font-style:normal}
.sb-nav{flex:1;overflow-y:auto;padding:12px 0}
.sb-sec{
  font-family:'Space Mono',monospace;font-size:.56rem;letter-spacing:.12em;
  text-transform:uppercase;color:var(--text3);padding:8px 18px 3px;
}
.sb-item{
  display:flex;align-items:center;gap:10px;
  padding:9px 18px;margin:2px 8px;border-radius:9px;
  text-decoration:none;color:var(--text2);font-size:.82rem;font-weight:500;
  transition:all .18s ease;position:relative;
}
.sb-item:hover{color:var(--text);background:var(--card-hov)}
.sb-item.active{color:var(--rose);background:rgba(244,63,94,.09);border:1px solid rgba(244,63,94,.12)}
.sb-item.active::before{
  content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);
  width:3px;height:16px;background:var(--rose);border-radius:0 3px 3px 0;
  box-shadow:0 0 10px var(--rose);
}
.sb-icon{font-size:1rem;width:18px;text-align:center}
.sb-badge{
  margin-left:auto;font-size:.58rem;font-family:'Space Mono',monospace;
  padding:2px 6px;border-radius:100px;background:var(--rose);color:#fff;font-weight:700;
}
.sb-user{
  display:flex;align-items:center;gap:10px;padding:12px 18px;
  border-top:1px solid var(--border);flex-shrink:0;
}
.sb-av{
  width:36px;height:36px;border-radius:11px;flex-shrink:0;
  background:linear-gradient(135deg,var(--rose),var(--violet));
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-weight:800;font-size:.85rem;color:#fff;
}
.sb-uname{font-family:'Syne',sans-serif;font-weight:700;font-size:.8rem}
.sb-urole{font-size:.62rem;color:var(--rose);font-family:'Space Mono',monospace;margin-top:2px}

/* ══ MAIN ════════════════════════════════════════════════════ */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;position:relative;z-index:1}

/* ── TOPBAR ─────────────────────────────────────────────── */
#topbar{
  position:sticky;top:0;z-index:100;
  height:var(--topbar-h);display:flex;align-items:center;gap:12px;padding:0 1.8rem;
  background:rgba(5,8,15,.85);backdrop-filter:blur(22px);border-bottom:1px solid var(--border);
}
.bc{display:flex;align-items:center;gap:8px;font-size:.78rem;color:var(--text2)}
.bc-curr{font-family:'Syne',sans-serif;font-weight:700;color:var(--text)}
.bc-sep{color:var(--text3)}
.topbar-space{flex:1}
.tb-search{
  display:flex;align-items:center;gap:7px;
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--r);padding:6px 12px;width:220px;
  transition:border-color .2s,box-shadow .2s;
}
.tb-search:focus-within{border-color:var(--border-act);box-shadow:var(--glow)}
.tb-search input{background:none;border:none;outline:none;color:var(--text);font-size:.78rem;font-family:'DM Sans',sans-serif;width:100%}
.tb-search input::placeholder{color:var(--text3)}
.tb-search i{color:var(--text3);font-size:.82rem}
.tb-btn{
  width:34px;height:34px;border-radius:var(--r);background:var(--card);border:1px solid var(--border);
  color:var(--text2);display:flex;align-items:center;justify-content:center;
  cursor:pointer;font-size:.9rem;transition:all .18s;text-decoration:none;
}
.tb-btn:hover{color:var(--text);background:var(--card-hov)}
.tb-ham{display:none;background:none;border:none;color:var(--text);font-size:1.2rem;cursor:pointer}

/* ══ PAGE ════════════════════════════════════════════════════ */
.page{padding:1.8rem;max-width:1400px;width:100%}

.page-head{
  display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:1rem;
  margin-bottom:1.8rem;animation:fadeUp .4s ease both;
}
.ph-title{
  font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;letter-spacing:-.5px;
  background:linear-gradient(135deg,var(--text) 60%,var(--text2));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.ph-sub{font-size:.82rem;color:var(--text2);margin-top:3px}
.ph-actions{display:flex;gap:10px;flex-wrap:wrap}

/* ── BUTTONS ──────────────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:9px 18px;border-radius:var(--r);font-family:'Syne',sans-serif;
  font-size:.78rem;font-weight:700;cursor:pointer;transition:all .18s ease;
  text-decoration:none;border:none;white-space:nowrap;
}
.btn-sm{padding:5px 11px;font-size:.72rem}
.btn-xs{padding:3px 8px;font-size:.65rem}
.btn-primary{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff;box-shadow:0 4px 18px rgba(0,212,255,.22)}
.btn-primary:hover{opacity:.85;transform:translateY(-1px);box-shadow:0 6px 24px rgba(0,212,255,.36)}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-ghost{background:var(--card);border:1px solid var(--border);color:var(--text2)}
.btn-ghost:hover{color:var(--text);border-color:rgba(255,255,255,.14);background:var(--card-hov)}
.btn-danger{background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.25);color:var(--rose)}
.btn-danger:hover{background:rgba(244,63,94,.18);box-shadow:0 0 16px rgba(244,63,94,.2)}
.btn-success{background:rgba(0,255,170,.1);border:1px solid rgba(0,255,170,.25);color:var(--neon)}
.btn-success:hover{background:rgba(0,255,170,.18)}
.btn-amber{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:var(--amber)}
.btn-amber:hover{background:rgba(245,158,11,.18)}

/* ── STATS ROW ──────────────────────────────────────────── */
.stats-row{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
  gap:.9rem;margin-bottom:2rem;
}
.sc{
  background:var(--card);border:1px solid var(--border);border-radius:var(--r-lg);
  padding:1.2rem 1.1rem;position:relative;overflow:hidden;
  transition:transform .22s,border-color .22s,box-shadow .22s;
  animation:fadeUp .5s ease both;cursor:default;
}
.sc::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--c1,var(--cyan)),var(--c2,var(--violet)));
  opacity:0;transition:opacity .3s;
}
.sc:hover{transform:translateY(-4px);border-color:rgba(255,255,255,.1);box-shadow:var(--shadow)}
.sc:hover::before{opacity:1}
.sc:nth-child(1){--c1:var(--cyan);--c2:var(--violet);animation-delay:.04s}
.sc:nth-child(2){--c1:var(--rose);--c2:var(--amber);animation-delay:.08s}
.sc:nth-child(3){--c1:var(--amber);--c2:var(--neon);animation-delay:.12s}
.sc:nth-child(4){--c1:var(--neon);--c2:var(--cyan);animation-delay:.16s}
.sc:nth-child(5){--c1:var(--violet);--c2:var(--rose);animation-delay:.2s}
.sc:nth-child(6){--c1:var(--cyan);--c2:var(--neon);animation-delay:.24s}
.sc-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:.8rem;background:var(--card-hov)}
.sc-val{font-family:'Syne',sans-serif;font-size:1.9rem;font-weight:800;letter-spacing:-.5px;line-height:1;
  background:linear-gradient(135deg,var(--c1,#fff),var(--c2,#aaa));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sc-label{font-size:.72rem;color:var(--text2);margin-top:4px;font-weight:500}

/* ── FILTERS / TOOLBAR ───────────────────────────────────── */
.toolbar{
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  margin-bottom:1.4rem;padding:1rem 1.2rem;
  background:var(--card);border:1px solid var(--border);border-radius:var(--r-lg);
  animation:fadeUp .5s ease .1s both;
}
.filter-group{display:flex;align-items:center;gap:7px;flex-wrap:wrap;flex:1}
.filter-select{
  background:var(--surface2);border:1px solid var(--border);border-radius:var(--r);
  padding:7px 12px;color:var(--text2);font-size:.78rem;font-family:'DM Sans',sans-serif;
  outline:none;cursor:pointer;transition:border-color .2s;
}
.filter-select:focus{border-color:var(--border-act)}
.filter-select option{background:var(--surface2)}
.view-toggle{display:flex;gap:4px;margin-left:auto}
.vt-btn{
  width:32px;height:32px;border-radius:var(--r);border:1px solid var(--border);
  background:var(--card);color:var(--text2);display:flex;align-items:center;
  justify-content:center;cursor:pointer;font-size:.85rem;transition:all .18s;
}
.vt-btn.active{background:rgba(0,212,255,.12);border-color:rgba(0,212,255,.3);color:var(--cyan)}

/* ── USERS CARDS GRID ─────────────────────────────────────── */
#view-cards{animation:fadeUp .5s ease .15s both}
#view-table{display:none;animation:fadeUp .5s ease both}
.users-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
  gap:1.1rem;margin-bottom:2rem;
}

/* ── USER CARD ────────────────────────────────────────────── */
.user-card{
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--r-xl);overflow:hidden;
  transition:transform .26s cubic-bezier(.34,1.4,.64,1),border-color .26s,box-shadow .26s;
  animation:fadeUp .5s ease both;position:relative;cursor:default;
}
.user-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--rc,var(--cyan)),var(--violet));
  opacity:0;transition:opacity .3s;
}
.user-card:hover{transform:translateY(-8px) scale(1.012);border-color:rgba(255,255,255,.1);box-shadow:0 24px 60px rgba(0,0,0,.5)}
.user-card:hover::before{opacity:1}
.user-card::after{
  content:'';position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(circle at 50% -20%,rgba(0,212,255,.06),transparent 65%);
  opacity:0;transition:opacity .3s;
}
.user-card:hover::after{opacity:1}

.uc-header{
  padding:1.5rem 1.4rem 1rem;display:flex;align-items:center;gap:13px;
  background:linear-gradient(135deg,rgba(255,255,255,.02),rgba(255,255,255,.005));
}
.uc-avatar{
  width:60px;height:60px;border-radius:18px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-weight:800;font-size:1.35rem;color:#fff;
  position:relative;transition:transform .22s;
}
.user-card:hover .uc-avatar{transform:scale(1.06) rotate(-2deg)}
.uc-status{
  position:absolute;bottom:2px;right:2px;width:13px;height:13px;
  border-radius:50%;border:2px solid var(--surface);
}
.uc-info{flex:1;min-width:0}
.uc-name{font-family:'Syne',sans-serif;font-weight:800;font-size:.98rem;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.uc-email{font-size:.73rem;color:var(--text2);margin-top:2px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.uc-badges{display:flex;gap:5px;margin-top:7px;flex-wrap:wrap}
.badge{
  display:inline-flex;align-items:center;gap:3px;
  font-family:'Space Mono',monospace;font-size:.58rem;letter-spacing:.03em;
  text-transform:uppercase;padding:3px 9px;border-radius:100px;font-weight:700;
}
.uc-body{padding:0 1.4rem 1rem}
.uc-info-row{
  display:flex;align-items:center;gap:7px;
  padding:5px 0;font-size:.73rem;color:var(--text2);
  border-bottom:1px solid rgba(255,255,255,.04);
}
.uc-info-row:last-child{border-bottom:none}
.uc-info-row i{color:var(--text3);width:14px;text-align:center;flex-shrink:0}
.uc-actions{
  padding:.9rem 1.4rem;border-top:1px solid var(--border);
  display:flex;gap:6px;align-items:center;background:rgba(255,255,255,.015);
}
.uc-actions .spacer{flex:1}

/* ── TABLE VIEW ───────────────────────────────────────────── */
.table-wrap{
  background:var(--card);border:1px solid var(--border);border-radius:var(--r-xl);
  overflow:hidden;margin-bottom:2rem;
}
table{width:100%;border-collapse:collapse;font-size:.8rem}
thead th{
  text-align:left;font-family:'Space Mono',monospace;font-size:.6rem;
  letter-spacing:.08em;text-transform:uppercase;color:var(--text3);
  padding:12px 14px;border-bottom:1px solid var(--border);
  background:rgba(255,255,255,.015);
}
tbody td{
  padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.04);
  color:var(--text2);vertical-align:middle;transition:background .12s;
}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:var(--card-hov)}
.td-strong{font-weight:600;color:var(--text)}
.td-mono{font-family:'Space Mono',monospace;font-size:.68rem}
.av-mini{
  width:34px;height:34px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-family:'Syne',sans-serif;font-weight:800;font-size:.72rem;color:#fff;
}
.td-user{display:flex;align-items:center;gap:9px}

/* ── PAGINATION ─────────────────────────────────────────── */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:2rem}
.pg-btn{
  width:34px;height:34px;border-radius:var(--r);border:1px solid var(--border);
  background:var(--card);color:var(--text2);display:flex;align-items:center;
  justify-content:center;cursor:pointer;font-size:.78rem;font-family:'Space Mono',monospace;
  text-decoration:none;transition:all .18s;
}
.pg-btn:hover{color:var(--text);border-color:rgba(255,255,255,.14);background:var(--card-hov)}
.pg-btn.active{background:rgba(0,212,255,.15);border-color:rgba(0,212,255,.4);color:var(--cyan)}
.pg-btn.disabled{opacity:.35;pointer-events:none}
.pg-info{font-size:.72rem;font-family:'Space Mono',monospace;color:var(--text3);padding:0 8px}

/* ══ MODAL ══════════════════════════════════════════════════ */
.modal-overlay{
  position:fixed;inset:0;z-index:900;
  background:rgba(5,8,15,.92);backdrop-filter:blur(16px);
  display:flex;align-items:center;justify-content:center;padding:1rem;
  opacity:0;pointer-events:none;transition:opacity .28s ease;
}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-box{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--r-xl);width:100%;max-width:520px;max-height:92vh;
  overflow-y:auto;box-shadow:0 32px 80px rgba(0,0,0,.6);
  transform:translateY(28px) scale(.96);
  transition:transform .3s cubic-bezier(.34,1.4,.64,1);
  position:relative;
}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1)}
.modal-box::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--cyan),var(--violet),var(--neon));
  border-radius:var(--r-xl) var(--r-xl) 0 0;
}
.modal-head{
  padding:1.5rem 1.6rem 1rem;display:flex;align-items:center;gap:12px;
  border-bottom:1px solid var(--border);
}
.modal-icon{width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.modal-title{font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem}
.modal-sub{font-size:.73rem;color:var(--text2);margin-top:2px}
.modal-close{
  margin-left:auto;width:30px;height:30px;border-radius:8px;
  background:var(--card);border:1px solid var(--border);
  color:var(--text2);display:flex;align-items:center;justify-content:center;
  cursor:pointer;font-size:.8rem;transition:all .18s;flex-shrink:0;
}
.modal-close:hover{color:var(--rose);border-color:rgba(244,63,94,.3);background:rgba(244,63,94,.08)}
.modal-body{padding:1.4rem 1.6rem}
.modal-footer{padding:1rem 1.6rem;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}

/* ── FORM ──────────────────────────────────────────────── */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.form-row.single{grid-template-columns:1fr}
.field{display:flex;flex-direction:column;gap:5px;margin-bottom:0}
.field label{font-family:'Space Mono',monospace;font-size:.6rem;letter-spacing:.08em;text-transform:uppercase;color:var(--text3)}
.field label .req{color:var(--rose);margin-left:3px}
.field input,.field select,.field textarea{
  background:var(--surface2);border:1px solid var(--border);
  border-radius:var(--r);padding:9px 13px;color:var(--text);
  font-size:.82rem;font-family:'DM Sans',sans-serif;outline:none;
  transition:border-color .2s,box-shadow .2s;
}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--border-act);box-shadow:0 0 20px rgba(0,212,255,.1)}
.field input::placeholder,.field textarea::placeholder{color:var(--text3)}
.field select option{background:var(--surface2)}
.field .hint{font-size:.65rem;color:var(--text3);margin-top:2px}
.field.error input,.field.error select{border-color:var(--rose)}
.field .field-err{font-size:.65rem;color:var(--rose);margin-top:2px;display:none}
.field.error .field-err{display:block}

/* ── ROLE PREVIEW BADGE ─────────────────────────────────── */
.role-preview-wrap{
  display:flex;align-items:center;gap:8px;
  padding:8px 12px;border-radius:var(--r);
  background:rgba(255,255,255,.025);border:1px solid var(--border);
  margin-top:6px;transition:all .3s;
}
.role-preview-label{font-family:'Space Mono',monospace;font-size:.58rem;text-transform:uppercase;color:var(--text3);letter-spacing:.06em}
#role-preview-badge{
  font-family:'Space Mono',monospace;font-size:.6rem;text-transform:uppercase;
  padding:3px 10px;border-radius:100px;font-weight:700;letter-spacing:.04em;
  transition:all .3s ease;
}
.role-preview-lock{font-size:.65rem;color:var(--text3);margin-left:auto;display:flex;align-items:center;gap:4px}

/* ── CONFIRM MODAL ─────────────────────────────────────── */
#modal-confirm .modal-box{max-width:380px}
.confirm-icon{font-size:3rem;text-align:center;margin:1rem 0 .5rem}
.confirm-msg{text-align:center;color:var(--text2);font-size:.85rem;line-height:1.6;margin-bottom:1rem}
.confirm-msg strong{color:var(--text)}

/* ── LOADING SPINNER ──────────────────────────────────── */
.btn-loading{position:relative;color:transparent !important}
.btn-loading::after{
  content:'';position:absolute;width:14px;height:14px;border-radius:50%;
  border:2px solid rgba(255,255,255,.3);border-top-color:#fff;
  animation:spin .7s linear infinite;
}

/* ══ TOAST ══════════════════════════════════════════════════ */
#toast-stack{position:fixed;bottom:1.4rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column-reverse;gap:7px;pointer-events:none}
.toast{
  display:flex;align-items:center;gap:9px;padding:10px 14px;
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--r);font-size:.78rem;max-width:300px;pointer-events:all;
  transform:translateX(110px);opacity:0;transition:all .35s cubic-bezier(.34,1.56,.64,1);
  box-shadow:var(--shadow);
}
.toast.show{transform:translateX(0);opacity:1}
.toast-body{flex:1}
.toast-title{font-family:'Syne',sans-serif;font-weight:700}
.toast-sub{font-size:.68rem;color:var(--text2);margin-top:1px}

/* ── EMPTY STATE ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:4rem 2rem;color:var(--text3);animation:fadeUp .5s ease both}
.empty-icon{font-size:3.5rem;margin-bottom:1rem;opacity:.6}
.empty-msg{font-size:.85rem;margin-bottom:1.4rem}

/* ── ANIMATIONS ──────────────────────────────────────────── */
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── DB ALERT ─────────────────────────────────────────────── */
.db-alert{
  display:flex;align-items:center;gap:10px;
  background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);
  border-radius:var(--r);padding:10px 14px;margin-bottom:1.4rem;
  font-size:.8rem;color:var(--rose);animation:fadeUp .4s ease both;
}

/* ── MOBILE ──────────────────────────────────────────────── */
#sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:199;opacity:0;pointer-events:none;transition:opacity .3s}
#sidebar-overlay.show{opacity:1;pointer-events:all}
@media(max-width:900px){
  #sidebar{transform:translateX(-100%)}
  #sidebar.open{transform:translateX(0)}
  .main{margin-left:0}
  .tb-ham{display:block}
  .tb-search{display:none}
  .form-row{grid-template-columns:1fr}
  .page{padding:1.2rem}
  .users-grid{grid-template-columns:1fr}
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .page-head{flex-direction:column;align-items:flex-start}
}
@media(max-width:480px){.stats-row{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<aside id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">📚</div>
    <div class="sb-brand-text">Digital <em>Library</em></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-sec">Principal</div>
    <a href="../dashboard.php" class="sb-item"><span class="sb-icon"><i class="bi bi-grid-1x2"></i></span> Dashboard</a>

    <div class="sb-sec">Administration</div>
    <a href="index.php" class="sb-item active">
      <span class="sb-icon"><i class="bi bi-people"></i></span> Utilisateurs
      <span class="sb-badge"><?= $statsData['total'] ?? 0 ?></span>
    </a>

  </nav>
  <div class="sb-user">
    <div class="sb-av"><?= $avatar ?></div>
    <div>
      <div class="sb-uname"><?= $adminName ?></div>
      <div class="sb-urole">⚡ Admin</div>
    </div>
  </div>
</aside>
<div id="sidebar-overlay" onclick="closeSidebar()"></div>

<!-- ══ MAIN ═════════════════════════════════════════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header id="topbar">
    <button class="tb-ham" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
    <div class="bc">
      <a href="../dashboard.php" style="color:var(--text2);text-decoration:none">DLS</a>
      <span class="bc-sep">/</span>
      <span class="bc-curr">Utilisateurs</span>
    </div>
    <div class="topbar-space"></div>
    <div class="tb-search">
      <i class="bi bi-search"></i>
      <input type="search" placeholder="Rechercher…" id="topbar-search" autocomplete="off">
    </div>
    <a href="?export=csv" class="tb-btn" title="Exporter CSV"><i class="bi bi-download"></i></a>
    <a href="../dashboard.php" class="tb-btn" title="Dashboard"><i class="bi bi-grid-1x2"></i></a>
    <a href="../logout1.php" class="tb-btn" title="Déconnexion" style="color:var(--rose)"><i class="bi bi-box-arrow-right"></i></a>
  </header>

  <!-- PAGE CONTENT -->
  <main class="page">

    <?php if ($dbError): ?>
    <div class="db-alert">
      <i class="bi bi-exclamation-triangle-fill"></i>
      Erreur DB : <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="page-head">
      <div>
        <h1 class="ph-title">Utilisateurs</h1>
        <p class="ph-sub"><?= $totalUsers ?> membre<?= $totalUsers > 1 ? 's' : '' ?> enregistré<?= $totalUsers > 1 ? 's' : '' ?></p>
      </div>
      <div class="ph-actions">
        <a href="?export=csv" class="btn btn-ghost"><i class="bi bi-download"></i> Exporter CSV</a>
        <button type="button" class="btn btn-primary" onclick="openAddModal()">
          <i class="bi bi-person-plus-fill"></i> Ajouter un utilisateur
        </button>
      </div>
    </div>

    <!-- STATS -->
    <div class="stats-row">
      <div class="sc"><div class="sc-icon" style="background:rgba(0,212,255,.1)">👥</div><div class="sc-val"><?= $statsData['total'] ?? 0 ?></div><div class="sc-label">Total membres</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(244,63,94,.1)">⚡</div><div class="sc-val"><?= $statsData['admins'] ?? 0 ?></div><div class="sc-label">Admins</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(245,158,11,.1)">✍️</div><div class="sc-val"><?= $statsData['journalistes'] ?? 0 ?></div><div class="sc-label">Journalistes</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(0,255,170,.08)">📖</div><div class="sc-val"><?= $statsData['lecteurs'] ?? 0 ?></div><div class="sc-label">Lecteurs</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(244,63,94,.08)">🔒</div><div class="sc-val"><?= $statsData['bloques'] ?? 0 ?></div><div class="sc-label">Bloqués</div></div>
      <div class="sc"><div class="sc-icon" style="background:rgba(0,212,255,.06)">🆕</div><div class="sc-val"><?= $statsData['this_month'] ?? 0 ?></div><div class="sc-label">Ce mois</div></div>
    </div>

    <!-- TOOLBAR -->
    <div class="toolbar">
      <div class="filter-group">
        <select class="filter-select" id="filter-role" onchange="applyFilters()">
          <option value="">Tous les rôles</option>
          <option value="admin"       <?= ($_GET['role']??'')==='admin'       ? 'selected' : '' ?>>Admin</option>
          <option value="journaliste" <?= ($_GET['role']??'')==='journaliste' ? 'selected' : '' ?>>Journaliste</option>
          <option value="lecteur"     <?= ($_GET['role']??'')==='lecteur'     ? 'selected' : '' ?>>Lecteur</option>
        </select>
        <select class="filter-select" id="filter-statut" onchange="applyFilters()">
          <option value="">Tous les statuts</option>
          <option value="actif"   <?= ($_GET['statut']??'')==='actif'   ? 'selected' : '' ?>>Actif</option>
          <option value="bloque"  <?= ($_GET['statut']??'')==='bloque'  ? 'selected' : '' ?>>Bloqué</option>
          <option value="inactif" <?= ($_GET['statut']??'')==='inactif' ? 'selected' : '' ?>>Inactif</option>
        </select>
        <button type="button" class="btn btn-ghost btn-sm" onclick="clearFilters()"><i class="bi bi-x-circle"></i> Réinitialiser</button>
      </div>
      <div class="view-toggle">
        <button type="button" class="vt-btn active" id="btn-cards" onclick="setView('cards')" title="Cartes"><i class="bi bi-grid-3x3-gap"></i></button>
        <button type="button" class="vt-btn" id="btn-table" onclick="setView('table')" title="Tableau"><i class="bi bi-table"></i></button>
      </div>
    </div>

    <!-- ══ CARDS VIEW ══ -->
    <div id="view-cards">
      <?php if (empty($users)): ?>
      <div class="empty-state">
        <div class="empty-icon">👥</div>
        <div class="empty-msg">Aucun utilisateur trouvé pour ces filtres.</div>
        <button type="button" class="btn btn-primary" onclick="openAddModal()"><i class="bi bi-person-plus-fill"></i> Ajouter le premier</button>
      </div>
      <?php else: ?>
      <div class="users-grid" id="cards-container">
        <?php
        $gradients = [
            'linear-gradient(135deg,#00d4ff,#7c3aed)',
            'linear-gradient(135deg,#f43f5e,#7c3aed)',
            'linear-gradient(135deg,#f59e0b,#f43f5e)',
            'linear-gradient(135deg,#00ffaa,#00d4ff)',
            'linear-gradient(135deg,#7c3aed,#00d4ff)',
        ];
        foreach ($users as $i => $u):
          $rc     = roleConfig($u['role'] ?? 'lecteur');
          $sc_cfg = statutConfig($u['statut'] ?? 'actif');
          $initls = initials($u['prenom'] ?? '', $u['nom'] ?? '');
          $grad   = $gradients[$i % count($gradients)];
          $delay  = min($i * 0.05, 0.4);
          $uid    = (int)$u['id'];
          $uname  = htmlspecialchars(trim(($u['prenom']??'').' '.($u['nom']??'')), ENT_QUOTES, 'UTF-8');
          $uname_js = htmlspecialchars(addslashes(trim(($u['prenom']??'').' '.($u['nom']??''))), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="user-card" style="--rc:<?= $rc['color'] ?>;animation-delay:<?= $delay ?>s" data-id="<?= $uid ?>">
          <div class="uc-header">
            <div class="uc-avatar" style="background:<?= $grad ?>">
              <?= $initls ?>
              <div class="uc-status" style="background:<?= $sc_cfg['color'] ?>"></div>
            </div>
            <div class="uc-info">
              <div class="uc-name"><?= $uname ?></div>
              <div class="uc-email"><?= htmlspecialchars($u['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="uc-badges">
                <span class="badge" style="background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;border:1px solid rgba(255,255,255,.06)">
                  <?= $rc['icon'] ?> <?= htmlspecialchars($rc['label'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="badge" style="background:<?= $sc_cfg['bg'] ?>;color:<?= $sc_cfg['color'] ?>;border:1px solid rgba(255,255,255,.06)">
                  ● <?= htmlspecialchars($sc_cfg['label'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </div>
            </div>
          </div>
          <div class="uc-body">
            <div class="uc-info-row"><i class="bi bi-telephone"></i><?= htmlspecialchars($u['telephone'] ?? 'Non renseigné', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="uc-info-row"><i class="bi bi-calendar3"></i><?= fmtDate($u['created_at'] ?? null) ?></div>
            <div class="uc-info-row"><i class="bi bi-hash"></i>ID #<?= $uid ?></div>
          </div>
          <div class="uc-actions">
            <button type="button" class="btn btn-ghost btn-xs" onclick="openEditModal(<?= $uid ?>)" title="Modifier">
              <i class="bi bi-pencil"></i> Modifier
            </button>
            <?php if (($u['statut'] ?? '') === 'bloque'): ?>
              <button type="button" class="btn btn-success btn-xs" onclick="toggleStatut(<?= $uid ?>, this)" title="Débloquer">
                <i class="bi bi-unlock"></i> Débloquer
              </button>
            <?php else: ?>
              <button type="button" class="btn btn-amber btn-xs" onclick="toggleStatut(<?= $uid ?>, this)" title="Bloquer">
                <i class="bi bi-lock"></i> Bloquer
              </button>
            <?php endif; ?>
            <div class="spacer"></div>
            <?php if ($uid !== $adminId): ?>
            <button type="button" class="btn btn-danger btn-xs"
              onclick="confirmDelete(<?= $uid ?>, '<?= $uname_js ?>')" title="Supprimer">
              <i class="bi bi-trash3"></i>
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ TABLE VIEW ══ -->
    <div id="view-table">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Utilisateur</th>
              <th>Email</th>
              <th>Rôle</th>
              <th>Statut</th>
              <th>Téléphone</th>
              <th>Inscription</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--text3);padding:2.5rem">Aucun utilisateur trouvé</td></tr>
            <?php else:
              $gradients2 = [
                'linear-gradient(135deg,#00d4ff,#7c3aed)',
                'linear-gradient(135deg,#f43f5e,#7c3aed)',
                'linear-gradient(135deg,#f59e0b,#f43f5e)',
                'linear-gradient(135deg,#00ffaa,#00d4ff)',
              ];
              $ti = 0;
              foreach ($users as $u):
                $rc     = roleConfig($u['role'] ?? 'lecteur');
                $sc_cfg = statutConfig($u['statut'] ?? 'actif');
                $tg     = $gradients2[$ti++ % count($gradients2)];
                $uid    = (int)$u['id'];
                $uname_js = htmlspecialchars(addslashes(trim(($u['prenom']??'').' '.($u['nom']??''))), ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
              <td class="td-mono td-strong">#<?= $uid ?></td>
              <td>
                <div class="td-user">
                  <div class="av-mini" style="background:<?= $tg ?>"><?= initials($u['prenom']??'', $u['nom']??'') ?></div>
                  <span class="td-strong"><?= htmlspecialchars(trim(($u['prenom']??'').' '.($u['nom']??'')), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </td>
              <td><?= htmlspecialchars($u['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td><span class="badge" style="background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;border:1px solid rgba(255,255,255,.05)"><?= $rc['icon'].' '.htmlspecialchars($rc['label'],ENT_QUOTES,'UTF-8') ?></span></td>
              <td><span class="badge" style="background:<?= $sc_cfg['bg'] ?>;color:<?= $sc_cfg['color'] ?>;border:1px solid rgba(255,255,255,.05)">● <?= htmlspecialchars($sc_cfg['label'],ENT_QUOTES,'UTF-8') ?></span></td>
              <td class="td-mono" style="font-size:.7rem"><?= htmlspecialchars($u['telephone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td class="td-mono" style="font-size:.7rem"><?= fmtDate($u['created_at'] ?? null) ?></td>
              <td>
                <div style="display:flex;gap:4px">
                  <button type="button" class="btn btn-ghost btn-xs" onclick="openEditModal(<?= $uid ?>)" title="Modifier"><i class="bi bi-pencil"></i></button>
                  <?php if (($u['statut'] ?? '') === 'bloque'): ?>
                    <button type="button" class="btn btn-success btn-xs" onclick="toggleStatut(<?= $uid ?>, this)" title="Débloquer"><i class="bi bi-unlock"></i></button>
                  <?php else: ?>
                    <button type="button" class="btn btn-amber btn-xs" onclick="toggleStatut(<?= $uid ?>, this)" title="Bloquer"><i class="bi bi-lock"></i></button>
                  <?php endif; ?>
                  <?php if ($uid !== $adminId): ?>
                  <button type="button" class="btn btn-danger btn-xs" onclick="confirmDelete(<?= $uid ?>, '<?= $uname_js ?>')" title="Supprimer"><i class="bi bi-trash3"></i></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1):
      $qs = http_build_query(array_filter([
        'search' => $_GET['search'] ?? '',
        'role'   => $_GET['role']   ?? '',
        'statut' => $_GET['statut'] ?? '',
      ]));
    ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="?<?= $qs ?>&page=<?= $page-1 ?>" class="pg-btn"><i class="bi bi-chevron-left"></i></a>
      <?php else: ?>
        <span class="pg-btn disabled"><i class="bi bi-chevron-left"></i></span>
      <?php endif; ?>

      <?php for ($p = max(1, $page-2); $p <= min($totalPages, $page+2); $p++): ?>
        <a href="?<?= $qs ?>&page=<?= $p ?>" class="pg-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>

      <span class="pg-info"><?= $page ?> / <?= $totalPages ?></span>

      <?php if ($page < $totalPages): ?>
        <a href="?<?= $qs ?>&page=<?= $page+1 ?>" class="pg-btn"><i class="bi bi-chevron-right"></i></a>
      <?php else: ?>
        <span class="pg-btn disabled"><i class="bi bi-chevron-right"></i></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </main>
</div><!-- /.main -->

<!-- ══ MODAL AJOUTER/MODIFIER ═══════════════════════════════ -->
<div class="modal-overlay" id="modal-user">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-icon" id="modal-icon" style="background:rgba(0,212,255,.12)">👤</div>
      <div>
        <div class="modal-title" id="modal-title">Ajouter un utilisateur</div>
        <div class="modal-sub" id="modal-sub">Remplissez les informations du nouveau membre</div>
      </div>
      <button type="button" class="modal-close" onclick="closeModal('modal-user')"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <form id="user-form" autocomplete="off" onsubmit="return false">
        <input type="hidden" id="f-action" name="action" value="add">
        <input type="hidden" id="f-id"     name="id"     value="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-row">
          <div class="field" id="field-prenom">
            <label>Prénom <span class="req">*</span></label>
            <input type="text" id="f-prenom" name="prenom" placeholder="Jean" required>
            <div class="field-err">Prénom requis</div>
          </div>
          <div class="field" id="field-nom">
            <label>Nom <span class="req">*</span></label>
            <input type="text" id="f-nom" name="nom" placeholder="Dupont" required>
            <div class="field-err">Nom requis</div>
          </div>
        </div>

        <div class="form-row single">
          <div class="field" id="field-email">
            <label>Email <span class="req">*</span></label>
            <input type="email" id="f-email" name="email"
                   placeholder="jean@exemple.com" required
                   oninput="previewRole(this.value)">
            <div class="field-err">Email valide requis</div>
            <!-- ✅ Badge temps réel du rôle détecté -->
            <div class="role-preview-wrap" id="role-preview-wrap" style="display:none">
              <span class="role-preview-label"><i class="bi bi-shield-check"></i> Rôle détecté</span>
              <span id="role-preview-badge">—</span>
              <span class="role-preview-lock"><i class="bi bi-lock-fill"></i> automatique</span>
            </div>
          </div>
        </div>

        <div class="form-row single">
          <div class="field">
            <label>Téléphone</label>
            <input type="tel" id="f-telephone" name="telephone" placeholder="6XX XXX XXX">
          </div>
        </div>

        <!-- ✅ Le select statut reste modifiable, mais le rôle est supprimé du formulaire
             car il est calculé automatiquement côté serveur -->
        <div class="form-row single">
          <div class="field">
            <label>Statut <span class="req">*</span></label>
            <select id="f-statut" name="statut" required>
              <option value="actif">✅ Actif</option>
              <option value="inactif">⚪ Inactif</option>
              <option value="bloque">🔒 Bloqué</option>
            </select>
          </div>
        </div>

        <div class="form-row single">
          <div class="field" id="field-password">
            <label id="pwd-label">Mot de passe <span class="req">*</span></label>
            <input type="password" id="f-password" name="password"
                   placeholder="Minimum 6 caractères" autocomplete="new-password">
            <div class="hint" id="pwd-hint"></div>
            <div class="field-err" id="pwd-err">Mot de passe requis (min. 6 car.)</div>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modal-user')">Annuler</button>
      <button type="button" class="btn btn-primary" id="modal-submit-btn" onclick="submitUserForm()">
        <i class="bi bi-check-circle" id="submit-icon"></i>
        <span id="modal-submit-label">Créer l'utilisateur</span>
      </button>
    </div>
  </div>
</div>

<!-- ══ MODAL CONFIRMATION SUPPRESSION ═══════════════════════ -->
<div class="modal-overlay" id="modal-confirm">
  <div class="modal-box">
    <div class="modal-head" style="border:none;padding-bottom:0">
      <div class="modal-close" onclick="closeModal('modal-confirm')" style="margin-left:auto"><i class="bi bi-x-lg"></i></div>
    </div>
    <div class="modal-body" style="padding-top:.5rem">
      <div class="confirm-icon">🗑️</div>
      <div class="confirm-msg">
        Supprimer <strong id="confirm-name"></strong> ?<br>
        Cette action est <strong>irréversible</strong>.
      </div>
      <input type="hidden" id="confirm-id" value="">
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modal-confirm')">Annuler</button>
      <button type="button" class="btn btn-danger" id="btn-confirm-delete" onclick="doDelete()">
        <i class="bi bi-trash3"></i> Supprimer définitivement
      </button>
    </div>
  </div>
</div>

<!-- TOAST STACK -->
<div id="toast-stack"></div>

<script>
/* ══════════════════════════════════════════════════════════════
   DIGITAL LIBRARY — users/index.php — JavaScript complet
   ══════════════════════════════════════════════════════════════ */

const CSRF = <?= json_encode($csrfToken, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const SELF = window.location.pathname;

/* ── CONFIG RÔLES (miroir JS pour affichage badge uniquement) ─ */
// NB : Ces constantes servent UNIQUEMENT à l'affichage frontend.
// Le rôle réel est TOUJOURS calculé côté PHP par detectUserRole().
const ROLE_CONFIG = {
  admin:       { label: '⚡ Admin',       color: '#f43f5e', bg: 'rgba(244,63,94,.18)',  border: 'rgba(244,63,94,.35)'  },
  journaliste: { label: '✍️ Journaliste', color: '#f59e0b', bg: 'rgba(245,158,11,.18)', border: 'rgba(245,158,11,.35)' },
  lecteur:     { label: '📖 Lecteur',     color: '#00ffaa', bg: 'rgba(0,255,170,.12)',  border: 'rgba(0,255,170,.3)'   },
};

/* ── PREVIEW RÔLE EN TEMPS RÉEL ────────────────────────────── */
// Cette fonction n'est qu'un aperçu visuel — elle NE détermine PAS
// le rôle final, qui est exclusivement calculé par le PHP backend.
function detectRoleClient(email) {
  const e = email.toLowerCase().trim();
  if (/^admin\.[a-z0-9]+@adminsopecam\.com$/.test(e))       return 'admin';
  if (/^journaliste\.[a-z0-9]+@sopecam\.com$/.test(e)) return 'journaliste';
  return 'lecteur';
}

function previewRole(email) {
  const wrap  = document.getElementById('role-preview-wrap');
  const badge = document.getElementById('role-preview-badge');

  if (!email || email.length < 5) {
    wrap.style.display = 'none';
    return;
  }

  const role = detectRoleClient(email);
  const cfg  = ROLE_CONFIG[role];

  wrap.style.display  = 'flex';
  badge.textContent   = cfg.label;
  badge.style.color   = cfg.color;
  badge.style.background = cfg.bg;
  badge.style.border  = '1px solid ' + cfg.border;
}

/* ── SIDEBAR ───────────────────────────────────────────────── */
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebar-overlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-overlay').classList.remove('show');
}

/* ── VIEW TOGGLE ───────────────────────────────────────────── */
function setView(v) {
  const isCards = v === 'cards';
  document.getElementById('view-cards').style.display = isCards ? '' : 'none';
  document.getElementById('view-table').style.display = isCards ? 'none' : '';
  document.getElementById('btn-cards').classList.toggle('active', isCards);
  document.getElementById('btn-table').classList.toggle('active', !isCards);
  try { localStorage.setItem('dls_users_view', v); } catch(e) {}
}
(function() {
  let v = 'cards';
  try { v = localStorage.getItem('dls_users_view') || 'cards'; } catch(e) {}
  setView(v);
})();

/* ── FILTERS ───────────────────────────────────────────────── */
function applyFilters() {
  const role   = document.getElementById('filter-role').value;
  const statut = document.getElementById('filter-statut').value;
  const search = document.getElementById('topbar-search').value.trim();
  const params = new URLSearchParams();
  if (role)   params.set('role', role);
  if (statut) params.set('statut', statut);
  if (search) params.set('search', search);
  window.location.href = SELF + (params.toString() ? '?' + params.toString() : '');
}
function clearFilters() {
  window.location.href = SELF;
}
document.getElementById('topbar-search').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') applyFilters();
});
(function() {
  const sp = new URLSearchParams(window.location.search);
  const s = sp.get('search');
  if (s) document.getElementById('topbar-search').value = s;
})();

/* ── TOAST ─────────────────────────────────────────────────── */
const TOAST_ICONS  = { success: '✅', error: '🔴', warn: '⚠️', info: 'ℹ️' };
const TOAST_COLORS = { success: 'var(--neon)', error: 'var(--rose)', warn: 'var(--amber)', info: 'var(--cyan)' };

function showToast(title, sub, type, dur) {
  sub  = sub  || '';
  type = type || 'info';
  dur  = dur  || 3800;
  const stack = document.getElementById('toast-stack');
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.borderColor = TOAST_COLORS[type] || TOAST_COLORS.info;
  t.innerHTML =
    '<span style="font-size:1rem">' + (TOAST_ICONS[type] || 'ℹ️') + '</span>' +
    '<div class="toast-body">' +
      '<div class="toast-title">' + escHtml(title) + '</div>' +
      (sub ? '<div class="toast-sub">' + escHtml(sub) + '</div>' : '') +
    '</div>' +
    '<span onclick="this.parentElement.remove()" style="cursor:pointer;color:var(--text2);font-size:.82rem;padding:2px 4px">✕</span>';
  stack.appendChild(t);
  requestAnimationFrame(function() {
    requestAnimationFrame(function() { t.classList.add('show'); });
  });
  setTimeout(function() {
    t.classList.remove('show');
    setTimeout(function() { if (t.parentNode) t.remove(); }, 400);
  }, dur);
}

function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/* ── MODAL ─────────────────────────────────────────────────── */
function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
  if (id === 'modal-user') resetForm();
}

document.querySelectorAll('.modal-overlay').forEach(function(m) {
  m.addEventListener('click', function(e) {
    if (e.target === m) closeModal(m.id);
  });
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    ['modal-confirm', 'modal-user'].forEach(function(id) {
      const el = document.getElementById(id);
      if (el && el.classList.contains('open')) closeModal(id);
    });
  }
});

/* ── RESET FORMULAIRE ──────────────────────────────────────── */
function resetForm() {
  document.getElementById('user-form').reset();
  document.getElementById('f-id').value = '';
  // Cacher le badge de prévisualisation
  const wrap = document.getElementById('role-preview-wrap');
  if (wrap) wrap.style.display = 'none';
  // Supprimer les erreurs
  ['field-prenom','field-nom','field-email','field-password'].forEach(function(fid) {
    const el = document.getElementById(fid);
    if (el) el.classList.remove('error');
  });
}

/* ── OPEN ADD MODAL ────────────────────────────────────────── */
function openAddModal() {
  resetForm();
  document.getElementById('f-action').value         = 'add';
  document.getElementById('modal-title').textContent = 'Ajouter un utilisateur';
  document.getElementById('modal-sub').textContent   = 'Le rôle est attribué automatiquement selon l\'email';
  document.getElementById('modal-icon').textContent  = '👤';
  document.getElementById('modal-icon').style.background = 'rgba(0,212,255,.12)';
  document.getElementById('modal-submit-label').textContent = "Créer l'utilisateur";
  document.getElementById('submit-icon').className   = 'bi bi-person-plus-fill';
  document.getElementById('f-password').required     = true;
  document.getElementById('pwd-label').innerHTML     = 'Mot de passe <span class="req">*</span>';
  document.getElementById('pwd-hint').textContent    = '';
  openModal('modal-user');
  setTimeout(function() {
    const el = document.getElementById('f-prenom');
    if (el) el.focus();
  }, 250);
}

/* ── OPEN EDIT MODAL ───────────────────────────────────────── */
async function openEditModal(id) {
  resetForm();
  document.getElementById('f-action').value          = 'edit';
  document.getElementById('f-id').value              = id;
  document.getElementById('modal-title').textContent  = 'Modifier l\'utilisateur';
  document.getElementById('modal-sub').textContent    = 'Le rôle est recalculé automatiquement selon l\'email';
  document.getElementById('modal-icon').textContent   = '✏️';
  document.getElementById('modal-icon').style.background = 'rgba(245,158,11,.12)';
  document.getElementById('modal-submit-label').textContent = 'Enregistrer les modifications';
  document.getElementById('submit-icon').className    = 'bi bi-check-circle';
  document.getElementById('f-password').required      = false;
  document.getElementById('pwd-label').innerHTML      = 'Nouveau mot de passe <span style="color:var(--text3)">(optionnel)</span>';
  document.getElementById('pwd-hint').textContent     = 'Laissez vide pour conserver le mot de passe actuel.';

  openModal('modal-user');

  try {
    const fd = new FormData();
    fd.append('action', 'get');
    fd.append('id', id);
    fd.append('csrf_token', CSRF);

    const res = await fetch('', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    if (!data.success) {
      showToast('Erreur', data.error || 'Impossible de charger cet utilisateur.', 'error');
      return;
    }

    const u = data.user;
    document.getElementById('f-prenom').value    = u.prenom    || '';
    document.getElementById('f-nom').value       = u.nom       || '';
    document.getElementById('f-email').value     = u.email     || '';
    document.getElementById('f-telephone').value = u.telephone || '';
    document.getElementById('f-statut').value    = u.statut    || 'actif';

    // ✅ Afficher le badge du rôle actuel de l'utilisateur
    if (u.email) previewRole(u.email);

  } catch (e) {
    showToast('Erreur réseau', e.message, 'error');
  }
}

/* ── SOUMETTRE FORMULAIRE ──────────────────────────────────── */
async function submitUserForm() {
  const action = document.getElementById('f-action').value;
  const prenom = document.getElementById('f-prenom').value.trim();
  const nom    = document.getElementById('f-nom').value.trim();
  const email  = document.getElementById('f-email').value.trim();
  const pwd    = document.getElementById('f-password').value;

  // Validation frontend basique
  let hasError = false;

  function setFieldError(fieldId, show) {
    const el = document.getElementById(fieldId);
    if (el) el.classList.toggle('error', show);
    if (show) hasError = true;
  }

  setFieldError('field-prenom',   !prenom);
  setFieldError('field-nom',      !nom);
  setFieldError('field-email',    !email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));
  setFieldError('field-password', action === 'add' && !pwd);

  if (action === 'add' && pwd && pwd.length < 6) {
    document.getElementById('field-password').classList.add('error');
    document.getElementById('pwd-err').textContent = 'Mot de passe trop court (min. 6 car.)';
    hasError = true;
  }

  if (hasError) {
    showToast('Formulaire incomplet', 'Vérifiez les champs en rouge.', 'warn');
    return;
  }

  const btn = document.getElementById('modal-submit-btn');
  btn.disabled = true;
  btn.classList.add('btn-loading');

  try {
    const fd = new FormData(document.getElementById('user-form'));
    fd.set('csrf_token', CSRF);
    // ✅ Sécurité : supprimer tout éventuel champ "role" envoyé depuis le DOM
    fd.delete('role');

    const res = await fetch('', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    if (!data.success) {
      showToast('Erreur', data.error || 'Une erreur est survenue.', 'error');
      return;
    }

    // ✅ Afficher le rôle réellement attribué par le serveur
    const roleLabel = data.role_detected
      ? (ROLE_CONFIG[data.role_detected] ? ROLE_CONFIG[data.role_detected].label : data.role_detected)
      : '';
    const toastMsg  = roleLabel
      ? data.message + ' — Rôle : ' + roleLabel
      : data.message;

    showToast('Succès !', toastMsg, 'success');
    closeModal('modal-user');

    setTimeout(function() { window.location.reload(); }, 900);

  } catch (e) {
    showToast('Erreur réseau', e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.classList.remove('btn-loading');
  }
}

/* ── TOGGLE STATUT ─────────────────────────────────────────── */
async function toggleStatut(id, btnEl) {
  const origHtml = btnEl.innerHTML;
  btnEl.disabled = true;
  btnEl.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:spin .7s linear infinite;display:inline-block"></i>';

  try {
    const fd = new FormData();
    fd.append('action',     'toggle_statut');
    fd.append('id',         id);
    fd.append('csrf_token', CSRF);

    const res = await fetch('', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    if (!data.success) {
      showToast('Erreur', data.error || 'Impossible de modifier le statut.', 'error');
      btnEl.innerHTML = origHtml;
      btnEl.disabled = false;
      return;
    }

    showToast('Statut mis à jour', data.message, data.statut === 'bloque' ? 'warn' : 'success');
    setTimeout(function() { window.location.reload(); }, 900);

  } catch (e) {
    showToast('Erreur réseau', e.message, 'error');
    btnEl.innerHTML = origHtml;
    btnEl.disabled = false;
  }
}

/* ── CONFIRM DELETE ────────────────────────────────────────── */
function confirmDelete(id, name) {
  document.getElementById('confirm-id').value        = id;
  document.getElementById('confirm-name').textContent = name;
  openModal('modal-confirm');
}

async function doDelete() {
  const id  = document.getElementById('confirm-id').value;
  const btn = document.getElementById('btn-confirm-delete');

  if (!id) { showToast('Erreur', 'ID invalide.', 'error'); return; }

  btn.disabled = true;
  btn.classList.add('btn-loading');

  try {
    const fd = new FormData();
    fd.append('action',     'delete');
    fd.append('id',         id);
    fd.append('csrf_token', CSRF);

    const res = await fetch('', {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });

    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    if (!data.success) {
      showToast('Erreur', data.error || 'Impossible de supprimer.', 'error');
      return;
    }

    showToast('Supprimé !', data.message, 'success');
    closeModal('modal-confirm');

    // Animation de suppression côté DOM
    const card = document.querySelector('.user-card[data-id="' + id + '"]');
    if (card) {
      card.style.transition = 'opacity .35s,transform .35s';
      card.style.opacity = '0';
      card.style.transform = 'scale(.85)';
      setTimeout(function() { card.remove(); }, 350);
    }

    const rows = document.querySelectorAll('tbody tr');
    rows.forEach(function(row) {
      const cells = row.querySelectorAll('td');
      if (cells.length > 0 && cells[0].textContent.trim() === '#' + id) {
        row.style.transition = 'opacity .35s';
        row.style.opacity = '0';
        setTimeout(function() { row.remove(); }, 350);
      }
    });

    setTimeout(function() { window.location.reload(); }, 1400);

  } catch (e) {
    showToast('Erreur réseau', e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.classList.remove('btn-loading');
  }
}

/* ── RACCOURCIS CLAVIER ────────────────────────────────────── */
document.addEventListener('keydown', function(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
    e.preventDefault();
    openAddModal();
  }
});

/* ── EFFET RIPPLE SUR CARDS ────────────────────────────────── */
document.addEventListener('click', function(e) {
  const card = e.target.closest('.user-card');
  if (!card || e.target.closest('button') || e.target.closest('a')) return;
  card.style.boxShadow = '0 0 0 2px rgba(0,212,255,.3)';
  setTimeout(function() { card.style.boxShadow = ''; }, 300);
});
</script>
</body>
</html>