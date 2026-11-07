<?php
/**
 * GTC-Vault — photo de profil d'un client, recadrée dans sa pièce d'identité
 *
 *   GET  avatar.php?c=UUID                         la photo (JPEG carré, 320 px) — agents autorisés uniquement
 *   POST avatar.php  action=save   (csrf_token, c, doc, x, y, size)   recadrage dans la pièce n° doc
 *   POST avatar.php  action=delete (csrf_token, c)                    retour aux initiales
 *
 * Recadrage : x, y, size sont relatifs (0 → 1) à l'image remise à l'endroit ; le carré est découpé dans le fichier
 * D'ORIGINE côté serveur (jamais envoyé au navigateur), donc sans filigrane. La photo est rangée hors web
 * (KYC_STORAGE_DIR/avatars) et n'est servie qu'aux comptes VAULT_ROLES. Création et retrait journalisés.
 */
declare(strict_types=1);

ini_set('display_errors', '0');   // réponse image ou JSON : aucun message PHP ne doit s'y glisser
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    define('GTC_PASSIVE_REQUEST', true);   // affichage dans les listes : la session n'est pas prolongée
}
require_once __DIR__ . '/../../includes/vault.php';

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$user   = vault_guard($isPost ? 'json' : 'plain');

/** Réponse JSON (enregistrement, suppression). */
function avatar_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$uuid   = strtolower((string) ($isPost ? ($_POST['c'] ?? '') : ($_GET['c'] ?? '')));
$client = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)
    ? db_one('SELECT id, uuid, nom_complet, type_client, avatar_path FROM clients WHERE uuid = ?', [$uuid])
    : null;

// ---------- Affichage ----------
if (!$isPost) {
    session_write_close();
    $file = $client && $client['avatar_path'] ? avatar_dir() . '/' . $client['avatar_path'] : null;
    if (!$file || !is_file($file)) {
        vault_fail('plain', 404, 'not_found', 'Aucune photo de profil.');
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, no-store');   // tirée d'une pièce d'identité : jamais conservée par le navigateur
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cross-Origin-Resource-Policy: same-origin');
    readfile($file);
    exit;
}

// ---------- Enregistrement / suppression ----------
if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
    avatar_json(['error' => 'Session expirée : rechargez la fiche.'], 403);
}
session_write_close();
if (!$client) {
    avatar_json(['error' => 'Dossier introuvable.'], 404);
}

$action = (string) ($_POST['action'] ?? '');
if ($action === 'delete') {
    avatar_delete_file($client['avatar_path']);
    db_query('UPDATE clients SET avatar_path = NULL, updated_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $client['id']]);
    vault_audit('avatar_delete', $client, null, $user['name'] . ' a retiré la photo de profil de ' . $client['nom_complet']);
    avatar_json(['ok' => true]);
}
if ($action !== 'save') {
    avatar_json(['error' => 'Action inconnue.'], 400);
}

// Pièce source : une image du MÊME client
$doc = db_one('SELECT id, type_document, chemin_fichier, mime_type FROM client_documents WHERE id = ? AND client_id = ?',
    [(int) ($_POST['doc'] ?? 0), (int) $client['id']]);
if (!$doc || !in_array($doc['mime_type'], ['image/jpeg', 'image/png'], true)) {
    avatar_json(['error' => 'Choisissez une pièce en image (JPG ou PNG) de ce dossier.'], 422);
}
$base = realpath(KYC_STORAGE_DIR);
$path = realpath(KYC_STORAGE_DIR . '/' . $doc['chemin_fichier']);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
    avatar_json(['error' => 'Fichier de la pièce introuvable.'], 404);
}
$img = kyc_open_image($path, (string) $doc['mime_type']);
if (!$img) {
    avatar_json(['error' => 'Image illisible (extension PHP GD requise).'], 422);
}

// Carré demandé (relatif), borné à l'image
$W = imagesx($img);
$H = imagesy($img);
$num = fn (string $k) => is_numeric($_POST[$k] ?? null) ? (float) $_POST[$k] : -1.0;
[$rx, $ry, $rs] = [$num('x'), $num('y'), $num('size')];
if ($rx < 0 || $ry < 0 || $rs <= 0 || $rx > 1 || $ry > 1 || $rs > 1) {
    avatar_json(['error' => 'Cadre de recadrage invalide.'], 422);
}
$side = (int) round($rs * $W);                                  // côté du carré (relatif à la largeur)
$side = max(24, min($side, $W, $H));
$sx   = (int) round(min(max(0, $rx * $W), $W - $side));
$sy   = (int) round(min(max(0, $ry * $H), $H - $side));

$out = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
imagecopyresampled($out, $img, 0, 0, $sx, $sy, AVATAR_SIZE, AVATAR_SIZE, $side, $side);

$dir = avatar_dir();
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    error_log('[GTC-Vault avatar] dossier inaccessible : ' . $dir);
    avatar_json(['error' => 'Le serveur n\'a pas pu enregistrer la photo.'], 500);
}
$name = bin2hex(random_bytes(16)) . '.jpg';
if (!imagejpeg($out, $dir . '/' . $name, 86)) {
    avatar_json(['error' => 'Le serveur n\'a pas pu enregistrer la photo.'], 500);
}
@chmod($dir . '/' . $name, 0640);
avatar_delete_file($client['avatar_path']);
db_query('UPDATE clients SET avatar_path = ?, updated_at = ? WHERE id = ?', [$name, date('Y-m-d H:i:s'), (int) $client['id']]);
vault_audit('avatar_set', $client, (int) $doc['id'],
    $user['name'] . ' a défini la photo de profil de ' . $client['nom_complet'] . ' (recadrée dans « ' . (KYC_DOC_LABELS[$doc['type_document']] ?? 'pièce') . ' »)');

avatar_json(['ok' => true, 'url' => avatar_url($client['uuid'], $name)]);
