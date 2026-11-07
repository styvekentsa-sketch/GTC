<?php
/**
 * GTC-Vault — coffre-fort KYC intégré au Dashboard (pages : dashboard/vault/)
 *
 *   require_once __DIR__ . '/../../includes/vault.php';
 *   $user = vault_guard();          // page HTML : connexion du Dashboard + rôle VAULT_ROLES
 *   $user = vault_guard('json');    // requête Fetch : 401 / 403 en JSON
 *   $user = vault_guard('plain');   // image ou fichier : 401 / 403 en texte
 *
 * Connexion, session, jeton CSRF, base de données et fuseau horaire (Africa/Douala) : ceux du Dashboard.
 * Tables : clients, client_documents, audit_logs (db_install_kyc, includes/db.php).
 * Pièces : data/kyc/AAAA/MM/<aléatoire>.ext — dossier interdit d'accès depuis le web (.htaccess).
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard.php';   // VAULT_ROLES, current_user(), activity_log()

// ---------- Configuration ----------
const VAULT_TZ        = 'Africa/Douala';
const KYC_MAX_BYTES   = 5 * 1024 * 1024;                       // 5 Mo par fichier
const KYC_MIME_TYPES  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
// Dossier des pièces : data/kyc/ par défaut (interdit d'accès par .htaccess et data/.htaccess).
// Recommandé en production : un dossier HORS de public_html, déclaré dans includes/db.local.php, ex.
//   define('GTC_KYC_DIR', '/home/u123456789/kyc');   (le dossier doit exister et être accessible en écriture à PHP)
define('KYC_STORAGE_DIR', defined('GTC_KYC_DIR') && GTC_KYC_DIR !== '' ? rtrim((string) GTC_KYC_DIR, '/\\') : __DIR__ . '/../data/kyc');
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
 */
function vault_guard(string|false $api = false): array
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
    if (!in_array($user['role'] ?? '', VAULT_ROLES, true)) {
        vault_audit('access_denied', null, null, 'Accès refusé à ' . ($_SERVER['SCRIPT_NAME'] ?? '?') . ' (rôle « ' . ($user['role'] ?: 'aucun') . ' »)');
        if ($api !== false) {
            vault_fail($api, 403, 'forbidden', 'Accès refusé.');
        }
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="fr"><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Accès refusé · GTC-Vault</title>'
           . '<body style="font-family:system-ui,sans-serif;background:#f1f5f9;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
           . '<div style="max-width:420px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px;text-align:center">'
           . '<h1 style="font-size:20px;margin:0 0 8px">Accès refusé</h1><p style="color:#475569;margin:0 0 20px">Votre compte n\'est pas autorisé à consulter les dossiers KYC. Cette tentative a été enregistrée.</p>'
           . '<a href="../' . h(role_home($user['role'] ?? '')) . '" style="color:#1d4ed8;font-weight:600"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>Retour au Dashboard</a></div></body></html>';
        exit;
    }
    return $user;
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
    return 'viewer.php?' . http_build_query(['d' => $documentId, 'm' => $mode, 'e' => $exp, 's' => $sig]);
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
    return $avatarPath ? 'avatar.php?c=' . rawurlencode($uuid) . '&v=' . substr(md5($avatarPath), 0, 10) : null;
}

/**
 * Avatar d'un client : photo de profil si elle existe, sinon ses initiales sur la couleur de son type.
 * $size : classes Tailwind de taille (ex. 'h-9 w-9 text-xs'), $round : forme ('rounded-full', 'rounded-2xl').
 */
function avatar_html(array $client, string $initials, string $size, string $round = 'rounded-full'): string
{
    $tone = ($client['type_client'] ?? '') === 'entreprise' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700';
    $url  = avatar_url((string) $client['uuid'], $client['avatar_path'] ?? null);
    if ($url) {
        return '<img src="' . h($url) . '" alt="" width="64" height="64" loading="lazy" decoding="async" draggable="false"'
             . ' class="' . h($size) . ' ' . $round . ' shrink-0 bg-slate-100 object-cover ring-1 ring-slate-200" data-avatar>';
    }
    return '<span class="flex ' . h($size) . ' shrink-0 items-center justify-center ' . $round . ' font-semibold ' . $tone . '" aria-hidden="true" data-avatar>'
         . h($initials ?: '?') . '</span>';
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

/** Feuille de style de GTC-Vault (Tailwind compilé, versionné par sa date de modification). */
function vault_css(): string
{
    $file = __DIR__ . '/../dashboard/vault/assets/vault.css';
    return 'assets/vault.css' . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** En-tête commun des pages : retour au Dashboard, fil d'Ariane, compte connecté. $crumbs : [[libellé, lien|null], …]. */
function vault_topbar(array $crumbs): string
{
    $user  = vault_user();
    $items = ['<li><a href="../index.php" class="hover:text-white">Dashboard</a></li>'];
    foreach ($crumbs as $i => [$label, $href]) {
        $last = $i === array_key_last($crumbs);
        $items[] = $last || !$href
            ? '<li class="max-w-[16rem] truncate font-medium text-white"' . ($last ? ' aria-current="page"' : '') . '>' . h($label) . '</li>'
            : '<li><a href="' . h($href) . '" class="hover:text-white">' . h($label) . '</a></li>';
    }
    $initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/u', trim($user['name'])) ?: [], 0, 2))));
    return '<header class="bg-slate-900 text-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <div class="flex min-w-0 items-center gap-4">
            <a href="clients.php" class="flex shrink-0 items-center gap-2.5 font-semibold tracking-tight">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><circle cx="12" cy="10.5" r="2"/><path d="M12 12.5V16"/></svg>
                </span>
                GTC-Vault
            </a>
            <nav aria-label="Fil d\'Ariane" class="hidden min-w-0 text-sm text-slate-300 md:block">
                <ol class="flex items-center gap-2">' . implode('<li aria-hidden="true" class="text-slate-500">/</li>', $items) . '</ol>
            </nav>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <a href="../index.php" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-sm font-medium text-slate-300 ring-1 ring-inset ring-white/15 hover:bg-white/10 hover:text-white" aria-label="Retour au Dashboard"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg><span class="hidden sm:inline">Dashboard</span></a>
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-xs font-semibold" title="' . h($user['name'] . ' · ' . role_label($user['role'])) . '" aria-label="Connecté : ' . h($user['name']) . '">' . h($initials ?: '?') . '</span>
        </div>
    </div>
</header>';
}
