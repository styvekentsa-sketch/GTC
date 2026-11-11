<?php
/**
 * GTC-Vault — coffre-fort KYC intégré au Dashboard (pages : dashboard/vault/)
 *
 *   require_once __DIR__ . '/../../includes/vault.php';
 *   $user = vault_guard();          // page HTML : connexion du Dashboard + rôle VAULT_ROLES
 *   $user = vault_guard('json');    // requête Fetch : 401 / 403 en JSON
 *   $user = vault_guard('plain');   // image ou fichier : 401 / 403 en texte
 *
 * Connexion, session, jeton CSRF (csrf_token / csrf_verify), base de données (une seule instance PDO, db()) et
 * fuseau horaire (Africa/Douala) : ceux du Dashboard. Aucune connexion ni base propre au module.
 * Rôles : VAULT_ROLES (admin, conformite, agent) ; tout autre rôle reçoit une page 403, journalisée.
 * Journal : log_audit() → table audit_logs. Tables : clients, client_documents, audit_logs (db_install_kyc, includes/db.php).
 * Pièces : <dossier KYC>/AAAA/MM/<aléatoire>.ext, HORS de la racine web (kyc_storage_dir() ci-dessous).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard.php';   // VAULT_ROLES, current_user(), activity_log()

// ---------- Configuration ----------
const VAULT_TZ        = 'Africa/Douala';
const KYC_MAX_BYTES   = 5 * 1024 * 1024;                       // 5 Mo par fichier
const KYC_MIME_TYPES  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
/**
 * Dossier des pièces KYC, HORS de la racine web (même règle que les sauvegardes, includes/backup.php) :
 *   1. GTC_KYC_DIR, déclaré dans includes/db.local.php (ex. define('GTC_KYC_DIR', '/home/u123456789/gtc-kyc')) ;
 *   2. sinon le dossier « gtc-kyc » À CÔTÉ du site (au-dessus de public_html), créé automatiquement ;
 *   3. à défaut (hébergeur qui interdit d'écrire au-dessus du site) : data/kyc, interdit d'accès par .htaccess.
 * Des pièces déjà rangées dans data/kyc restent servies depuis data/kyc tant qu'elles n'ont pas été déplacées
 * (php tools/kyc-move.php) : aucun dossier client ne perd ses pièces au changement d'emplacement.
 */
function kyc_storage_dir(): string
{
    if (defined('GTC_KYC_DIR') && GTC_KYC_DIR !== '') {
        return rtrim((string) GTC_KYC_DIR, '/\\');
    }
    $site    = dirname(__DIR__);
    $legacy  = $site . '/data/kyc';
    $outside = rtrim(dirname($site), '/\\') . DIRECTORY_SEPARATOR . 'gtc-kyc';
    if (is_dir($outside) && is_writable($outside)) {
        return $outside;
    }
    if (!kyc_dir_has_files($legacy) && (is_dir($outside) || @mkdir($outside, 0700, true)) && is_writable($outside)) {
        // Protection si le dossier se trouve malgré tout sous une racine web
        is_file($outside . '/.htaccess') || @file_put_contents($outside . '/.htaccess', "Require all denied\n");
        return $outside;
    }
    return $legacy;
}

/** Le dossier contient-il au moins un fichier (pièce, photo de profil, capture) ? */
function kyc_dir_has_files(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getFilename() !== '.htaccess') {
            return true;
        }
    }
    return false;
}

define('KYC_STORAGE_DIR', kyc_storage_dir());

/** Saisie d'un nouveau client depuis la caisse (reçus, factures) : rôles du coffre-fort + rôles des opérations. */
const KYC_CASHDESK_ROLES = ['admin', 'conformite', 'agent', 'caissier'];
const KYC_VIEW_ROLES  = VAULT_ROLES;                            // admin, conformité, agent (includes/dashboard.php)
const KYC_DIAL_CODES  = [                                       // indicatif => [code pays, nom]
    '+237' => ['CM', 'Cameroun'],     '+241' => ['GA', 'Gabon'],              '+242' => ['CG', 'Congo'],
    '+235' => ['TD', 'Tchad'],        '+236' => ['CF', 'Centrafrique'],       '+240' => ['GQ', 'Guinée équatoriale'],
    '+234' => ['NG', 'Nigeria'],      '+225' => ['CI', "Côte d'Ivoire"],      '+221' => ['SN', 'Sénégal'],
    '+33'  => ['FR', 'France'],       '+32'  => ['BE', 'Belgique'],           '+1'   => ['US', 'États-Unis / Canada'],
    '+971' => ['AE', 'Émirats arabes unis'], '+86' => ['CN', 'Chine'],
];
/** Libellés des types de pièces (colonne client_documents.type_document). */
const KYC_DOC_LABELS = [
    'cni_recto'         => 'CNI — recto',
    'cni_verso'         => 'CNI — verso',
    'passeport'         => 'Passeport',
    'rccm'              => 'Registre du commerce (RCCM)',
    'statut_entreprise' => 'Statuts de l\'entreprise',
    'autre'             => 'Autre document',
];
const VIEWER_TTL = 600;   // un lien de consultation vaut 10 minutes, et seulement dans la session qui l'a reçu

if (!function_exists('h')) {
    /** Échappement HTML (même règle que e()). */
    function h(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** Base de données du Dashboard. */
function vault_db(): PDO
{
    return db();
}

/** Jeton CSRF de la session du Dashboard. */
function vault_csrf(): string
{
    return csrf_token();
}

/**
 * Accès à GTC-Vault : session du Dashboard (même connexion, mêmes expirations) puis rôle VAULT_ROLES.
 * Sans session : page de connexion du Dashboard, puis retour sur la page demandée.
 * Rôle non autorisé : refus journalisé dans audit_logs.
 * $api : false (page HTML), 'json' (Fetch) ou 'plain' (image, fichier).
 * $roles : rôles admis (par défaut VAULT_ROLES) ; la saisie d'un client depuis la caisse admet aussi les rôles
 *         des opérations (clients-nouveau.php en mode « caisse », capture-api.php), sans accès aux pièces.
 */
function vault_guard(string|false $api = false, ?array $roles = null): array
{
    auth_session_start();
    auth_send_headers();
    $user = current_user();
    if (!$user) {
        if ($api !== false) {
            vault_fail($api, 401, 'session_expired', 'Votre session a expiré. Reconnectez-vous.');
        }
        $page = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'clients.php');
        $uuid = (string) ($_GET['uuid'] ?? '');
        $next = 'vault/' . $page . (preg_match('/^[0-9a-f-]{36}$/', $uuid) ? '?uuid=' . $uuid : '');
        redirect('../login.php?next=' . rawurlencode($next));
    }
    if (!in_array($user['role'] ?? '', $roles ?? VAULT_ROLES, true)) {
        log_audit('access_denied', null, null, 'Accès refusé à ' . ($_SERVER['SCRIPT_NAME'] ?? '?') . ' (rôle « ' . ($user['role'] ?: 'aucun') . ' »)');
        if ($api !== false) {
            vault_fail($api, 403, 'forbidden', 'Accès refusé.');
        }
        vault_forbidden_page($user);
    }
    return $user;
}

/** Page « Accès refusé » (HTTP 403) dans la mise en page du Dashboard, sans aucun contenu du coffre-fort. */
function vault_forbidden_page(array $user): never
{
    http_response_code(403);
    header('Cache-Control: no-store');
    dash_header('Accès refusé', '', [VAULT_CSS], true);
    ?>
    <div class="empty vault-empty" role="alert">
        <span class="vault-empty__icon"><?= vault_icon('vault') ?></span>
        <h2>Accès refusé (403)</h2>
        <p>Votre compte n'est pas autorisé à consulter les dossiers KYC (GTC-Vault). L'accès est réservé à
            l'administration, à la conformité et aux agents. Cette tentative a été enregistrée.</p>
        <div class="toolbar__actions"><a href="<?= h(role_home($user['role'] ?? '')) ?>" class="btn btn--primary">Retour à mon espace</a></div>
    </div>
    <?php
    dash_footer();
    exit;
}

/** Réponse d'erreur sans contenu du dossier (JSON ou texte). */
function vault_fail(string $format, int $status, string $code, string $message): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $code, 'message' => $message, 'login' => '../login.php']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit;
}

/** Utilisateur connecté : ['id', 'name', 'role']. */
function vault_user(): array
{
    $u = current_user() ?? [];
    return ['id' => (int) ($u['id'] ?? 0), 'name' => (string) ($u['name'] ?? '') ?: 'Agent #' . (int) ($u['id'] ?? 0), 'role' => (string) ($u['role'] ?? '')];
}

/**
 * Journal d'audit de sécurité (table audit_logs, ajout seul). Ne bloque jamais la page : en cas d'échec,
 * l'erreur va dans le journal PHP. $client : ['uuid', 'nom_complet'] (copiés : la trace survit à la suppression).
 */
function vault_audit(string $action, ?array $client, ?int $documentId, string $details, ?array $as = null): void
{
    // $as : ['id', 'name'] de l'agent concerné quand la requête n'a pas de session (téléphone de capture)
    $user = $as ? ['id' => (int) $as['id'], 'name' => (string) $as['name']] : vault_user();
    try {
        db()->prepare('INSERT INTO audit_logs (created_at, user_id, user_name, action, client_uuid, client_name, document_id, details, ip, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                date('Y-m-d H:i:s'), $user['id'] ?: null, $user['name'], $action,
                $client['uuid'] ?? null, $client['nom_complet'] ?? null, $documentId,
                mb_substr($details, 0, 500), client_ip(),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
    } catch (Throwable $e) {
        error_log('[GTC-Vault audit] ' . $action . ' — ' . $details . ' — ' . $e->getMessage());
    }
}

/**
 * Journalisation centralisée de GTC-Vault (table audit_logs) : point d'entrée unique du module.
 *   log_audit('document_view', ['uuid' => $uuid, 'nom_complet' => $nom], $documentId, 'Consultation CNI recto');
 * Même instance PDO que le Dashboard ; ajout seul ; ne bloque jamais la page (voir vault_audit()).
 */
function log_audit(string $action, ?array $client = null, ?int $documentId = null, string $details = '', ?array $as = null): void
{
    vault_audit($action, $client, $documentId, $details, $as);
}

/** UUID v4 (identifiant public du client). */
function uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// ---------- Liens de consultation des pièces (viewer.php) ----------

/** Lien signé vers viewer.php : inutilisable dans une autre session, pour une autre pièce ou une fois expiré. */
function vault_viewer_url(int $documentId, string $mode = 'image'): string
{
    $_SESSION['viewer_key'] ??= bin2hex(random_bytes(32));
    $exp = time() + VIEWER_TTL;
    $sig = hash_hmac('sha256', $documentId . '|' . $mode . '|' . $exp, $_SESSION['viewer_key']);
    // Chemin depuis dashboard/ (pages GTC-Vault affichées avec une balise <base> vers dashboard/)
    return 'vault/viewer.php?' . http_build_query(['d' => $documentId, 'm' => $mode, 'e' => $exp, 's' => $sig]);
}

/** Vérifie la signature et l'expiration d'un lien de consultation. */
function vault_viewer_check(int $documentId, string $mode, int $exp, string $sig): bool
{
    $key = (string) ($_SESSION['viewer_key'] ?? '');
    return $key !== '' && $exp >= time() && $exp <= time() + VIEWER_TTL
        && hash_equals(hash_hmac('sha256', $documentId . '|' . $mode . '|' . $exp, $key), $sig);
}

/** Texte du filigrane : « GTC - USAGE INTERNE - Paul Ekwalla - 07/11/2026 14:05 ». */
function vault_watermark_text(): string
{
    return 'GTC - USAGE INTERNE - ' . vault_user()['name'] . ' - ' . date('d/m/Y H:i');
}

// ---------- Images des pièces ----------

/**
 * Ouvre une pièce JPEG / PNG et la remet à l'endroit d'après l'EXIF (photo prise au téléphone).
 * Utilisée par l'aperçu filigrané (viewer.php) et le recadrage des photos de profil (avatar.php) : les mêmes
 * coordonnées relatives désignent donc le même endroit sur l'aperçu et sur l'original.
 */
/**
 * Contrôle d'un fichier envoyé (formulaire KYC, saisie rapide depuis la caisse, ajout de pièce) :
 * [extension, mime] ou message d'erreur. Type lu dans le contenu du fichier, jamais dans son nom.
 */
function kyc_check_file(array $f): array|string
{
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > KYC_MAX_BYTES) {
        return 'Fichier trop lourd (5 Mo au maximum).';
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return 'Le fichier n\'a pas pu être reçu, réessayez.';
    }
    if ($f['size'] === 0) {
        return 'Le fichier est vide.';
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!isset(KYC_MIME_TYPES[$mime])) {
        return 'Format refusé : seuls JPG, PNG et PDF sont acceptés.';
    }
    return [KYC_MIME_TYPES[$mime], $mime];
}

/**
 * Range une pièce contrôlée par kyc_check_file() dans le stockage hors web et l'inscrit dans client_documents.
 * $f : fichier PHP + 'ext' et 'mime'. Retourne le chemin absolu (à effacer si la transaction échoue).
 */
function kyc_store_document(PDO $pdo, int $clientId, string $type, array $f, int $userId, string $now): string
{
    $dir = KYC_STORAGE_DIR . '/' . date('Y/m');
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Dossier de stockage inaccessible.');
    }
    $rel  = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $f['ext'];
    $dest = KYC_STORAGE_DIR . '/' . $rel;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        throw new RuntimeException('Copie du fichier impossible.');
    }
    @chmod($dest, 0640);
    $name = mb_substr((string) preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', basename((string) $f['name'])), 0, 255);
    $pdo->prepare('INSERT INTO client_documents (client_id, type_document, nom_original, chemin_fichier, taille_fichier, mime_type, created_at, uploaded_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$clientId, $type, $name, $rel, (int) $f['size'], $f['mime'], $now, $userId]);
    return $dest;
}

function kyc_open_image(string $path, string $mime): ?GdImage
{
    if (!function_exists('imagecreatefromjpeg')) {
        return null;
    }
    ini_set('memory_limit', '256M');   // photo de 12 Mpx décompressée : ~50 Mo
    $img = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
    if (!$img) {
        return null;
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle) {
            $img = imagerotate($img, $angle, 0) ?: $img;
        }
    }
    return $img;
}

// ---------- Photos de profil (recadrées dans une pièce d'identité, avatar.php) ----------
const AVATAR_SIZE = 320;   // carré, en pixels

/** Dossier des photos de profil (hors web, comme les pièces). */
function avatar_dir(): string
{
    return KYC_STORAGE_DIR . '/avatars';
}

/** Adresse de la photo de profil d'un client (null s'il n'en a pas) ; le nom du fichier sert de version. */
function avatar_url(string $uuid, ?string $avatarPath): ?string
{
    // Chemin depuis dashboard/ (balise <base> des pages GTC-Vault)
    return $avatarPath ? 'vault/avatar.php?c=' . rawurlencode($uuid) . '&v=' . substr(md5($avatarPath), 0, 10) : null;
}

/**
 * Avatar d'un client (composant member-thumb du Dashboard) : photo de profil si elle existe, sinon ses initiales,
 * vertes pour un particulier, bleues pour une entreprise. $size : 'sm' (36 px), 'md' (44 px) ou 'lg' (72 px, fiche).
 */
function avatar_html(array $client, string $initials, string $size = 'md'): string
{
    $url  = avatar_url((string) $client['uuid'], $client['avatar_path'] ?? null);
    $base = 'member-thumb vault-avatar vault-avatar--' . $size;
    if ($url) {
        return '<img src="' . h($url) . '" alt="" width="72" height="72" loading="lazy" decoding="async" draggable="false" class="' . $base . '" data-avatar>';
    }
    $tone = ($client['type_client'] ?? '') === 'entreprise' ? ' vault-avatar--company' : '';
    return '<span class="' . $base . ' member-thumb--initials' . $tone . '" aria-hidden="true" data-avatar>' . h($initials ?: '?') . '</span>';
}

/** Supprime le fichier d'une photo de profil. */
function avatar_delete_file(?string $relative): void
{
    if ($relative && preg_match('/^[a-f0-9]{32}\.jpg$/', $relative)) {
        @unlink(avatar_dir() . '/' . $relative);
    }
}

// ---------- Capture par téléphone (QR code) : capture-api.php (poste de l'agent), capture.php (téléphone) ----------
// Une SESSION de capture = un QR code pour plusieurs pièces à la suite (ex. CNI recto puis verso).
// Chaque pièce est une ligne de kyc_captures (batch_id = id de la 1re ligne, step = ordre) ; seul le jeton de la
// 1re ligne est utilisable. États d'une pièce : pending → uploaded (photo reçue) → consumed (récupérée par le poste) ;
// une photo reprise sur le téléphone repasse en « uploaded » ; skipped : pièce facultative passée ; cancelled.
const CAPTURE_TTL = 900;   // un QR code vaut 15 minutes, pour toutes les pièces de la session
const CAPTURE_MAX_STEPS = 4;
/** Pièces photographiables et leur libellé (doc_identite : selon la pièce choisie, CNI ou passeport). */
const CAPTURE_SLOTS = [
    'doc_identite' => ['cni' => 'CNI — recto', 'passeport' => 'Passeport — page photo'],
    'doc_verso'    => 'CNI — verso',
    'doc_rccm'     => 'Registre du commerce (RCCM)',
    'doc_statuts'  => 'Statuts de l\'entreprise',
];

/** Dossier des photos provisoires (avant leur récupération par le poste). */
function capture_tmp_dir(): string
{
    return KYC_STORAGE_DIR . '/tmp';
}

/** Capture correspondant au jeton d'un QR code (comparaison par empreinte), ou null. */
function capture_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return db_one('SELECT * FROM kyc_captures WHERE token_hash = ?', [hash('sha256', $token)]) ?: null;
}

/** Une capture peut-elle encore recevoir une photo ? (session ni annulée ni expirée) */
function capture_is_open(array $c): bool
{
    return $c['status'] !== 'cancelled' && (int) $c['expires_ts'] >= time();
}

/** Pièces d'une session de capture, dans l'ordre. */
function capture_batch(int $batchId): array
{
    return db_all('SELECT * FROM kyc_captures WHERE batch_id = ? ORDER BY step', [$batchId]);
}

/** Annule une session (toutes ses pièces) et efface les photos provisoires. */
function capture_cancel_batch(int $batchId): void
{
    foreach (capture_batch($batchId) as $c) {
        capture_delete_file($c['file_path']);
    }
    db_query("UPDATE kyc_captures SET status = 'cancelled', file_path = NULL WHERE batch_id = ?", [$batchId]);
}

/** Efface la photo provisoire d'une capture (fichier). */
function capture_delete_file(?string $relative): void
{
    if ($relative && preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', $relative)) {
        @unlink(capture_tmp_dir() . '/' . $relative);
    }
}

/** Nettoyage : captures expirées ou terminées depuis plus d'une heure (fichiers provisoires compris). */
function capture_purge(): void
{
    try {
        $now = time();
        foreach (db_all("SELECT id, file_path FROM kyc_captures WHERE expires_ts < ? AND file_path IS NOT NULL", [$now]) as $c) {
            capture_delete_file($c['file_path']);
            db_query('UPDATE kyc_captures SET file_path = NULL WHERE id = ?', [(int) $c['id']]);
        }
        db_query('DELETE FROM kyc_captures WHERE expires_ts < ?', [$now - 3600]);
    } catch (Throwable $e) {
        error_log('[GTC-Vault capture] nettoyage : ' . $e->getMessage());
    }
}

/** Adresse de la page de capture pour le téléphone (adresse réelle du site, jeton compris). */
function capture_public_url(string $token): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? parse_url(GTC_SITE_URL, PHP_URL_HOST));
    if (!preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $host)) {
        $host = (string) parse_url(GTC_SITE_URL, PHP_URL_HOST);   // en-tête Host inattendu : adresse officielle
    }
    return (is_https() ? 'https' : 'http') . '://' . $host . site_root() . 'dashboard/vault/capture.php?t=' . $token;
}

// ---------- Mise en forme ----------

/** Taille lisible : « 48 Ko », « 2,4 Mo ». */
function vault_size(int $bytes): string
{
    return $bytes < 1048576 ? max(1, (int) round($bytes / 1024)) . ' Ko' : str_replace('.', ',', (string) round($bytes / 1048576, 1)) . ' Mo';
}

/** Téléphone lisible : « +237670000000 » → « +237 6 70 00 00 00 ». */
function vault_phone(string $phone): string
{
    $codes = array_keys(KYC_DIAL_CODES);
    usort($codes, fn ($a, $b) => strlen($b) <=> strlen($a));       // indicatif le plus long d'abord
    foreach ($codes as $code) {
        if (str_starts_with($phone, $code)) {
            $rest = substr($phone, strlen($code));
            $head = strlen($rest) % 2 ? substr($rest, 0, 1) . ' ' : '';
            return $code . ' ' . $head . trim(chunk_split(substr($rest, strlen($rest) % 2), 2, ' '));
        }
    }
    return $phone;
}

/** Date enregistrée (heure de Douala) → ['7 nov. 2026', '11:28']. */
function vault_date(?string $value): array
{
    try {
        $d = new DateTimeImmutable((string) $value, new DateTimeZone(VAULT_TZ));
    } catch (Exception) {
        return ['', ''];
    }
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return [$d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y'), $d->format('H:i')];
}

/** Feuille de style de GTC-Vault (composants propres au module, sur les variables du Dashboard). */
const VAULT_CSS = 'assets/css/vault.css';

/** En-tête du Dashboard pour une page GTC-Vault (menu latéral, barre supérieure, entrée « GTC-Vault » active). */
function vault_header(string $title): void
{
    dash_header($title, 'vault', [VAULT_CSS, 'assets/css/kyc-link.css'], true);   // kyc-link.css : badge de conformité (documents financiers liés)
}

/** Icône SVG du Dashboard (includes/icons.php) ou tracé libre pour les icônes propres à GTC-Vault. */
function vault_icon(string $name): string
{
    $own = [
        'id-card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M15 9h3M15 13h3M6 16h7"/>',
        'phone'   => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
        'monitor' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'camera'  => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3.5"/>',
        'upload'  => '<path d="M12 16V4m0 0-4 4m4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'pdf'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'image'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M15 9h3M15 13h3M6 16h7"/>',
        'copy'    => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'building'=> '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M9 10h.01M15 10h.01"/>',
        'vault'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><circle cx="12" cy="10.5" r="2"/><path d="M12 12.5V16"/>',
        'send'    => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
        'sun'     => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'lens'    => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chev-l'  => '<path d="m15 18-6-6 6-6"/>',
        'chev-r'  => '<path d="m9 18 6-6-6-6"/>',
        'x'       => '<path d="M18 6 6 18M6 6l12 12"/>',
        'ok'      => '<path d="m5 12 5 5 9-10"/>',
    ];
    if (isset($own[$name])) {
        return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $own[$name] . '</svg>';
    }
    return icon($name);
}
