<?php
/**
 * Authentification & sécurité du Dashboard — Global Trading Corporation
 *
 * - Session durcie : cookie HttpOnly / SameSite / Secure (HTTPS), mode strict,
 *   régénération de l'identifiant, expiration après inactivité.
 * - Mots de passe : password_hash() / password_verify() (bcrypt ou argon selon PHP).
 * - Protection des routes : require_login(), require_role().
 * - Jetons CSRF pour tous les formulaires POST.
 * - Limitation des tentatives de connexion par adresse IP.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

const AUTH_IDLE_TIMEOUT   = 1800;      // 30 min d'inactivité → déconnexion
const AUTH_ABSOLUTE_LIMIT = 8 * 3600;  // 8 h maximum par session
const AUTH_REGEN_INTERVAL = 900;       // nouvel identifiant de session toutes les 15 min
const AUTH_MAX_ATTEMPTS   = 5;         // tentatives échouées autorisées PAR ADRESSE E-MAIL…
const AUTH_LOCK_MINUTES   = 15;        // …par fenêtre de 15 minutes (au-delà, seule cette adresse est verrouillée)

if (!function_exists('e')) {
    /** Échappement HTML */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* ==========================================================================
   Session
   ========================================================================== */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Démarre une session sécurisée dédiée au Dashboard. */
function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_name('GTC_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $now = time();

    // Expiration (inactivité ou durée maximale)
    if (isset($_SESSION['user_id'])) {
        $idle    = $now - ($_SESSION['last_activity'] ?? $now);
        $elapsed = $now - ($_SESSION['login_time'] ?? $now);
        if ($idle > AUTH_IDLE_TIMEOUT || $elapsed > AUTH_ABSOLUTE_LIMIT) {
            auth_logout();
            auth_session_start();
            flash('warning', 'Votre session a expiré. Merci de vous reconnecter.');
            return;
        }
    }
    // Requête de fond (actualisation automatique, ex. dashboard/messages-api.php) : la session est vérifiée,
    // mais elle n'est ni prolongée (un onglet resté ouvert ne doit pas empêcher la déconnexion après
    // 30 min d'inactivité) ni renouvelée (évite les conflits avec les pages chargées en même temps).
    if (defined('GTC_PASSIVE_REQUEST') && GTC_PASSIVE_REQUEST) {
        return;
    }
    $_SESSION['last_activity'] = $now;

    // Régénération périodique de l'identifiant (anti-fixation)
    if (!isset($_SESSION['regenerated_at'])) {
        $_SESSION['regenerated_at'] = $now;
    } elseif ($now - $_SESSION['regenerated_at'] > AUTH_REGEN_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = $now;
    }
}

/** En-têtes de sécurité propres au Dashboard (pas de cache, pas d'indexation, pas d'iframe). */
function auth_send_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

/* ==========================================================================
   Utilisateur courant & protection des routes
   ========================================================================== */

/** Utilisateur connecté (relu en base à chaque requête : un compte désactivé est exclu immédiatement). */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    // Session authentifiée : identifiant ET drapeau de connexion posés par auth_attempt()
    if (empty($_SESSION['user_id']) || ($_SESSION['logged_in'] ?? false) !== true) {
        return $user = null;
    }
    $user = auth_session_user();
    // Compte utilisé en prise de contrôle désactivé ou modifié entre-temps : retour au compte administrateur
    if (!$user && impersonation_active() && impersonation_stop()) {
        flash('warning', "Le compte utilisé n'est plus disponible : vous êtes revenu sur votre compte administrateur.");
        $user = auth_session_user();
    }
    if (!$user) {
        $closed = isset($_SESSION['auth_version']);
        auth_logout();
        if ($closed && session_status() === PHP_SESSION_NONE) {
            // Mot de passe changé, compte désactivé ou mis à jour : l'utilisateur est informé à la connexion suivante
            auth_session_start();
            flash('warning', 'Votre session a été fermée : votre compte a été modifié. Merci de vous reconnecter.');
        }
        return $user = null;
    }
    // Toujours à jour si le nom ou le rôle est modifié par un administrateur
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    return $user;
}

/**
 * Compte de la session relu en base : actif ET version de session identique à celle de la connexion.
 * Toute augmentation de users.session_version (changement ou réinitialisation du mot de passe)
 * rend donc invalides les sessions ouvertes auparavant, sur tous les appareils.
 */
function auth_session_user(): ?array
{
    $row = db_one(
        "SELECT id, name, email, role, session_version FROM users WHERE id = ? AND status = 'actif'",
        [(int) ($_SESSION['user_id'] ?? 0)]
    );
    if (!$row || (int) $row['session_version'] !== (int) ($_SESSION['auth_version'] ?? -1)) {
        return null;
    }
    unset($row['session_version']);
    return $row;
}

/** Ferme les sessions ouvertes d'un compte (elles seront refusées à leur prochaine requête). */
function auth_revoke_sessions(int $userId): int
{
    db_query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
    return (int) db_value('SELECT session_version FROM users WHERE id = ?', [$userId]);
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/** Bloque l'accès aux visiteurs non connectés (redirection vers la connexion). */
function require_login(): array
{
    auth_session_start();
    auth_send_headers();

    $user = current_user();
    if (!$user) {
        // Aucun compte : première installation
        if ((int) db_value('SELECT COUNT(*) FROM users') === 0) {
            redirect('setup.php');
        }
        $next = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'index.php');
        redirect('login.php?next=' . rawurlencode($next));
    }

    // Aucun changement de mot de passe imposé : il reste disponible à tout moment
    // dans Paramètres → Mon profil & sécurité (dashboard/settings.php).
    return $user;
}

/** Page d'accueil du Dashboard selon le rôle (la secrétaire / l'accueil n'a accès qu'à la messagerie). */
function role_home(?string $role = null): string
{
    return ($role ?? current_user()['role'] ?? '') === 'secretaire' ? 'messages.php' : 'index.php';
}

/** Réserve une page / action à un rôle. */
function require_role(string $role): void
{
    require_roles([$role]);
}

/** Réserve une page / action à plusieurs rôles ; les autres comptes reviennent à leur page d'accueil. */
function require_roles(array $roles): void
{
    if (!in_array(current_user()['role'] ?? '', $roles, true)) {
        http_response_code(403);
        flash('error', "Vous n'avez pas les droits nécessaires pour cette action.");
        redirect(role_home());
    }
}

/* ==========================================================================
   Connexion / déconnexion
   ========================================================================== */

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Trop de tentatives échouées récentes depuis cette IP ? */
/*
 * Limitation des tentatives de connexion : par ADRESSE E-MAIL, jamais par adresse IP.
 * Tous les postes de l'agence partagent la même adresse IP : les erreurs commises sur un compte
 * ne doivent pas empêcher les autres comptes de se connecter.
 * Au-delà de AUTH_MAX_ATTEMPTS échecs en AUTH_LOCK_MINUTES minutes, seule l'adresse concernée est
 * verrouillée (que le compte existe ou non : aucune indication n'est donnée sur l'existence d'un compte).
 */

/** Adresse e-mail normalisée, clé du compteur d'échecs. */
function auth_email_key(string $email): string
{
    return mb_substr(mb_strtolower(trim($email)), 0, 190);
}

/** Date et heure courantes de Douala décalées de $seconds (format de la base). */
function auth_time(int $seconds = 0): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->modify(sprintf('%+d seconds', $seconds))->format('Y-m-d H:i:s');
}

/**
 * Fin du verrouillage de l'adresse (horodatage Unix), ou null si elle n'est pas verrouillée.
 * Fenêtre glissante : le verrou tombe quand le plus ancien des échecs comptés sort de la fenêtre.
 */
function auth_locked_until(string $email): ?int
{
    $key = auth_email_key($email);
    if ($key === '') {
        return null;
    }
    $oldest = db_value(
        'SELECT attempted_at FROM login_attempts WHERE email = ? AND attempted_at > ?
         ORDER BY attempted_at DESC LIMIT 1 OFFSET ' . (AUTH_MAX_ATTEMPTS - 1),
        [$key, auth_time(-AUTH_LOCK_MINUTES * 60)]
    );
    if (!$oldest) {
        return null;
    }
    return (new DateTimeImmutable($oldest, new DateTimeZone('Africa/Douala')))->getTimestamp() + AUTH_LOCK_MINUTES * 60;
}

function auth_is_locked(string $email): bool
{
    return auth_locked_until($email) !== null;
}

/** Adresses actuellement verrouillées : [e-mail => fin du verrouillage]. */
function auth_locked_emails(): array
{
    $locked = [];
    $rows = db_all(
        // Seuil écrit en entier dans la requête : lié comme paramètre, SQLite le comparerait comme du texte
        'SELECT email FROM login_attempts WHERE attempted_at > ? GROUP BY email HAVING COUNT(*) >= ' . (int) AUTH_MAX_ATTEMPTS,
        [auth_time(-AUTH_LOCK_MINUTES * 60)]
    );
    foreach ($rows as $row) {
        if ($until = auth_locked_until($row['email'])) {
            $locked[$row['email']] = $until;
        }
    }
    return $locked;
}

/** Efface les échecs d'une adresse (connexion réussie ou déblocage par un administrateur). */
function auth_unlock(string $email): void
{
    db_query('DELETE FROM login_attempts WHERE email = ?', [auth_email_key($email)]);
}

/**
 * Tente une connexion. Retourne true en cas de succès.
 * Temps de réponse constant que le compte existe ou non (pas d'énumération des e-mails).
 */
function auth_attempt(string $email, string $password): bool
{
    $email = mb_strtolower(trim($email));
    $user  = db_one('SELECT id, name, role, password, status, session_version FROM users WHERE email = ?', [$email]);

    // Hash factice pour équilibrer le temps de calcul si l'utilisateur n'existe pas
    $hash  = $user['password'] ?? '$2y$12$bjAmHBJvZzFw6pB47H1zfuaB9q1KLOaZsDdSCSv/9HOfRwnUcqzrS';
    $valid = password_verify($password, $hash) && $user && $user['status'] === 'actif';

    if (!$valid) {
        db_query(
            'INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, ?)',
            [client_ip(), auth_email_key($email), db_now()]
        );
        // Journal limité aux dernières 24 heures (l'adresse IP est conservée pour l'analyse d'une attaque)
        db_query('DELETE FROM login_attempts WHERE attempted_at < ?', [auth_time(-86400)]);
        return false;
    }

    // Mise à niveau transparente de l'algorithme de hachage
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        db_query('UPDATE users SET password = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    auth_unlock($email);   // compteur de cette adresse remis à zéro (les autres adresses ne sont pas concernées)
    // Historique : la connexion « actuelle » devient la « précédente », puis on enregistre la nouvelle
    db_query(
        'UPDATE users SET previous_login_at = last_login_at, previous_login_ip = last_login_ip,
         last_login_at = ?, last_login_ip = ? WHERE id = ?',
        [db_now(), client_ip(), $user['id']]
    );

    // Session entièrement vidée puis nouvel identifiant : rien d'une session précédente
    // (autre compte, prise de contrôle, jeton CSRF) ne subsiste
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['user_id']         = (int) $user['id'];
    $_SESSION['user_name']       = $user['name'];
    $_SESSION['user_role']       = $user['role'];
    $_SESSION['auth_version']    = (int) $user['session_version'];
    $_SESSION['logged_in']       = true;
    $_SESSION['login_time']     = time();
    $_SESSION['last_activity']  = time();
    $_SESSION['regenerated_at'] = time();
    unset($_SESSION['csrf_token']);

    return true;
}

/** Détruit complètement la session. */
function auth_logout(): void
{
    // Déconnexion pendant une prise de contrôle : la session est notée comme terminée dans le journal
    if (!empty($_SESSION['impersonation_log_id'])) {
        db_query('UPDATE impersonation_log SET ended_at = ? WHERE id = ? AND ended_at IS NULL', [db_now(), (int) $_SESSION['impersonation_log_id']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Strict',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/* ==========================================================================
   Prise de contrôle de compte (« Se connecter en tant que… »)
   --------------------------------------------------------------------------
   Réservée aux administrateurs (dashboard/switch-user.php, POST + jeton CSRF).
   - $_SESSION['original_admin_id'] : administrateur à l'origine de la prise de contrôle ;
   - $_SESSION['user_id'] / user_name / user_role : compte utilisé (droits de ce compte uniquement) ;
   - chaque prise de contrôle est inscrite dans la table impersonation_log.
   Pas de prise de contrôle en cascade, ni d'un autre administrateur, ni d'un compte inactif.
   ========================================================================== */

/** Une prise de contrôle est-elle en cours ? */
function impersonation_active(): bool
{
    return !empty($_SESSION['original_admin_id']);
}

/** Administrateur à l'origine de la prise de contrôle (relu en base : doit toujours être un admin actif). */
function impersonation_admin(): ?array
{
    if (!impersonation_active()) {
        return null;
    }
    $admin = db_one("SELECT id, name, email, role, session_version FROM users WHERE id = ? AND role = 'admin' AND status = 'actif'",
        [(int) $_SESSION['original_admin_id']]);
    // Mot de passe de l'administrateur changé entre-temps : pas de retour possible sans reconnexion
    if (!$admin || (int) $admin['session_version'] !== (int) ($_SESSION['original_admin_version'] ?? -1)) {
        return null;
    }
    return $admin;
}

/** Motif de refus d'une prise de contrôle, ou null si elle est autorisée. */
function impersonation_refusal(array $actor, ?array $target): ?string
{
    if (impersonation_active()) {
        return "Une prise de contrôle est déjà en cours : revenez d'abord à votre compte administrateur.";
    }
    if (($actor['role'] ?? '') !== 'admin') {
        return 'Action réservée aux administrateurs.';
    }
    if (!$target) {
        return 'Compte introuvable.';
    }
    if ((int) $target['id'] === (int) $actor['id']) {
        return 'Vous êtes déjà connecté avec ce compte.';
    }
    if ($target['role'] === 'admin') {
        return "Le compte d'un autre administrateur ne peut pas être utilisé.";
    }
    if ($target['status'] !== 'actif') {
        return "Ce compte est désactivé : réactivez-le avant de l'utiliser.";
    }
    return null;
}

/** Bascule la session vers le compte $target (contrôles déjà effectués par impersonation_refusal()). */
function impersonation_start(array $admin, array $target): void
{
    db_query('INSERT INTO impersonation_log (admin_id, target_id, ip, started_at) VALUES (?, ?, ?, ?)',
        [$admin['id'], $target['id'], client_ip(), db_now()]);
    $logId = (int) db()->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['original_admin_id']      = (int) $admin['id'];
    $_SESSION['original_admin_version'] = (int) ($_SESSION['auth_version'] ?? -1);
    $_SESSION['auth_version']           = (int) db_value('SELECT session_version FROM users WHERE id = ?', [$target['id']]);
    $_SESSION['impersonation_log_id']   = $logId;
    $_SESSION['user_id']              = (int) $target['id'];
    $_SESSION['user_name']            = $target['name'];
    $_SESSION['user_role']            = $target['role'];
    $_SESSION['regenerated_at']       = time();
    unset($_SESSION['csrf_token']);   // nouveau jeton pour la nouvelle identité
}

/**
 * Restaure la session de l'administrateur d'origine.
 * Retourne false (sans rien restaurer) si cet administrateur n'est plus un admin actif.
 */
function impersonation_stop(): bool
{
    $admin = impersonation_admin();
    if (!empty($_SESSION['impersonation_log_id'])) {
        db_query('UPDATE impersonation_log SET ended_at = ? WHERE id = ? AND ended_at IS NULL', [db_now(), (int) $_SESSION['impersonation_log_id']]);
    }
    unset($_SESSION['original_admin_id'], $_SESSION['impersonation_log_id'], $_SESSION['original_admin_version']);
    if (!$admin) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id']        = (int) $admin['id'];
    $_SESSION['user_name']      = $admin['name'];
    $_SESSION['user_role']      = $admin['role'];
    $_SESSION['auth_version']   = (int) $admin['session_version'];
    $_SESSION['regenerated_at'] = time();
    unset($_SESSION['csrf_token']);
    return true;
}

/** Hache un mot de passe (algorithme par défaut de PHP). */
function auth_hash(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/** Règle de robustesse minimale d'un mot de passe. Retourne un message d'erreur ou null. */
function auth_password_error(string $password): ?string
{
    if (mb_strlen($password) < 10) {
        return 'Le mot de passe doit contenir au moins 10 caractères.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
    }
    return null;
}

/* ==========================================================================
   CSRF
   ========================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Vérifie le jeton CSRF d'une requête POST ; stoppe la requête s'il est invalide. */
function csrf_verify(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        flash('error', 'Le formulaire a expiré. Merci de réessayer.');
        redirect(basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'index.php'));
    }
}

/* ==========================================================================
   Utilitaires
   ========================================================================== */

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Message flash (affiché une seule fois à la page suivante). */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}
