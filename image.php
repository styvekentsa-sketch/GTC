<?php
/**
 * Affichage des images envoyées depuis le Dashboard, conservées hors du dossier du site (includes/uploads.php).
 *
 *   image.php?d=team|partners|brand&f=nom-du-fichier.jpg[&v=version]
 *
 * Seuls les fichiers PNG / JPG des trois dossiers autorisés sont servis (nom vérifié, aucun chemin accepté).
 * Adresse versionnée (&v=date de modification) : mise en cache longue par le navigateur.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/uploads.php';

$kind = (string) ($_GET['d'] ?? '');
$file = upload_safe_name((string) ($_GET['f'] ?? ''));
$dir  = isset(UPLOAD_LEGACY[$kind]) ? upload_dir($kind) : null;
$path = ($dir !== null && $file !== null && is_file($dir . '/' . $file)) ? $dir . '/' . $file : null;
$info = $path ? @getimagesize($path) : false;

if (!$path || !$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Image introuvable.');
}

$mtime = filemtime($path);
$etag  = '"' . md5($file . $mtime . filesize($path)) . '"';
header('Content-Type: ' . $info['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=' . (isset($_GET['v']) ? 31536000 : 3600));
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($path));
readfile($path);
