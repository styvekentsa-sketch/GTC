<?php
/**
 * Images envoyées depuis le Dashboard (photos de l'équipe, logos des partenaires, logo des factures) :
 * conservées HORS du dossier du site, pour qu'aucune mise à jour du site ne les efface.
 *
 * Pourquoi : le site est mis à jour à partir de GitHub. Les fichiers envoyés depuis le Dashboard en ligne n'existent
 * que sur le serveur ; rangés dans assets/img/…, ils disparaissaient ou ne correspondaient plus après une mise à jour
 * (photo remplacée par des initiales, logo par le nom du partenaire).
 *
 *   Dossier : GTC_UPLOAD_DIR (includes/db.local.php), sinon « gtc-uploads » À CÔTÉ du site
 *             (ex. /home/compte/gtc-uploads à côté de public_html), sous-dossiers team/, partners/, brand/.
 *   Affichage : image.php?d=team&f=membre-….jpg (fichier hors de la racine web).
 *   Migration : une image encore rangée dans l'ancien dossier (assets/img/…) y est copiée dès son premier affichage.
 *   Fichiers suivis par Git (logo d'un partenaire ou photo nommés d'après lui, ex. dhl.png, cherif-alioum.jpg) :
 *   lus directement dans assets/img/…, jamais copiés.
 */

/** Anciens dossiers (dans le site) et leur adresse web, par type d'image. */
const UPLOAD_LEGACY = [
    'team'     => ['dir' => __DIR__ . '/../assets/img/team',     'url' => 'assets/img/team'],
    'partners' => ['dir' => __DIR__ . '/../assets/img/partners', 'url' => 'assets/img/partners'],
    'brand'    => ['dir' => __DIR__ . '/../assets/img/brand',    'url' => 'assets/img/brand'],
];

/** Nom de fichier image sûr (aucun chemin), ou null. */
function upload_safe_name(string $file): ?string
{
    $file = basename($file);
    return preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g)$/', $file) && !str_starts_with($file, '.') ? $file : null;
}

/** Dossier racine des images envoyées (créé au besoin), ou null s'il n'est pas utilisable. */
function uploads_root(): ?string
{
    static $root = false;
    if ($root !== false) {
        return $root;
    }
    $dir = defined('GTC_UPLOAD_DIR') && GTC_UPLOAD_DIR !== ''
        ? rtrim((string) GTC_UPLOAD_DIR, '/\\')
        : dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'gtc-uploads';
    if ((is_dir($dir) || @mkdir($dir, 0755, true)) && is_writable($dir)) {
        if (!is_file($dir . '/.htaccess')) {   // protection si le dossier se trouvait malgré tout sous la racine web
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $root = (realpath($dir) ?: $dir);
    }
    error_log('[GTC] Dossier des images envoyées indisponible : ' . $dir . ' (définir GTC_UPLOAD_DIR)');
    return $root = null;
}

/** Dossier d'un type d'image (team, partners, brand) hors du site, créé au besoin ; null si indisponible. */
function upload_dir(string $kind): ?string
{
    $root = isset(UPLOAD_LEGACY[$kind]) ? uploads_root() : null;
    if ($root === null) {
        return null;
    }
    $dir = $root . DIRECTORY_SEPARATOR . $kind;
    return (is_dir($dir) || @mkdir($dir, 0755, true)) ? $dir : null;
}

/** Dossier où enregistrer un nouvel envoi : hors du site, sinon (repli) l'ancien dossier du site. */
function upload_store_dir(string $kind): ?string
{
    $dir = upload_dir($kind) ?? UPLOAD_LEGACY[$kind]['dir'];
    return (is_dir($dir) || @mkdir($dir, 0755, true)) && is_writable($dir) ? $dir : null;
}

/**
 * Chemin d'une image existante, ou null. $gitManaged : fichier suivi par Git (nommé d'après le partenaire ou le
 * membre), lu dans l'ancien dossier uniquement. Sinon : dossier hors du site, puis ancien dossier (copié au passage).
 */
function upload_path(string $kind, string $file, bool $gitManaged = false): ?string
{
    $file = upload_safe_name($file);
    if ($file === null || !isset(UPLOAD_LEGACY[$kind])) {
        return null;
    }
    $legacy = UPLOAD_LEGACY[$kind]['dir'] . '/' . $file;
    if ($gitManaged) {
        return is_file($legacy) ? $legacy : null;
    }
    $dir = upload_dir($kind);
    if ($dir !== null && is_file($dir . '/' . $file)) {
        return $dir . '/' . $file;
    }
    if (is_file($legacy)) {
        // Migration : copie hors du site, à l'abri des mises à jour (l'original reste en place)
        if ($dir !== null && @copy($legacy, $dir . '/' . $file)) {
            return $dir . '/' . $file;
        }
        return $legacy;
    }
    return null;
}

/** Adresse web d'une image existante (versionnée), ou null. $prefix : chemin vers la racine du site. */
function upload_url(string $kind, string $file, string $prefix = '', bool $gitManaged = false): ?string
{
    $path = upload_path($kind, $file, $gitManaged);
    if ($path === null) {
        return null;
    }
    $name = basename($path);
    if (str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', UPLOAD_LEGACY[$kind]['dir']) . '/')) {
        return $prefix . UPLOAD_LEGACY[$kind]['url'] . '/' . rawurlencode($name) . '?v=' . filemtime($path);
    }
    return $prefix . 'image.php?d=' . $kind . '&f=' . rawurlencode($name) . '&v=' . filemtime($path);
}

/** Supprime une image envoyée, dans les deux emplacements. */
function upload_delete(string $kind, string $file): void
{
    $file = upload_safe_name($file);
    if ($file === null || !isset(UPLOAD_LEGACY[$kind])) {
        return;
    }
    foreach ([upload_dir($kind), UPLOAD_LEGACY[$kind]['dir']] as $dir) {
        if ($dir !== null && is_file($dir . '/' . $file)) {
            @unlink($dir . '/' . $file);
        }
    }
}
