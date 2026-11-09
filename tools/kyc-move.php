<?php
/**
 * GTC-Vault : déplace les pièces KYC de data/kyc (dans le site) vers leur dossier HORS de la racine web.
 *
 *   php tools/kyc-move.php --dry-run    affiche ce qui serait déplacé, sans rien toucher
 *   php tools/kyc-move.php              déplace les fichiers
 *
 * Destination : GTC_KYC_DIR (includes/db.local.php) s'il est défini, sinon le dossier « gtc-kyc » à côté du site
 * (même règle que includes/vault.php, kyc_storage_dir()). L'arborescence est conservée (AAAA/MM/, avatars/, tmp/) :
 * les chemins enregistrés en base (client_documents.chemin_fichier, clients.avatar_path) restent donc valables.
 *
 * Sûreté : un fichier n'est supprimé de data/kyc qu'une fois sa copie vérifiée (taille et empreinte SHA-256) ;
 * un fichier différent déjà présent à destination n'est JAMAIS écrasé (signalé, code de sortie 1).
 * Une fois data/kyc vide, GTC-Vault utilise automatiquement le dossier extérieur.
 * Le dossier tools/ est inaccessible depuis le web (.htaccess) ; ce script refuse de s'exécuter hors ligne de commande.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/includes/config.php';
require $root . '/includes/db.php';          // charge includes/db.local.php (GTC_KYC_DIR)

$dryRun = in_array('--dry-run', $argv, true);
$source = $root . '/data/kyc';
$target = defined('GTC_KYC_DIR') && GTC_KYC_DIR !== '' ? rtrim((string) GTC_KYC_DIR, '/\\')
                                                        : rtrim(dirname($root), '/\\') . DIRECTORY_SEPARATOR . 'gtc-kyc';

$log = function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

if (!is_dir($source)) {
    $log("Rien à déplacer : {$source} n'existe pas.");
    exit(0);
}
if (realpath($source) === realpath($target)) {
    $log('Rien à déplacer : GTC_KYC_DIR désigne déjà data/kyc. Choisissez un dossier hors de la racine web.');
    exit(1);
}
$log(($dryRun ? '[SIMULATION] ' : '') . "Source : {$source}");
$log(($dryRun ? '[SIMULATION] ' : '') . "Destination : {$target}");

if (!$dryRun) {
    if (!is_dir($target) && !@mkdir($target, 0700, true)) {
        $log("ÉCHEC : impossible de créer {$target} (droits d'écriture, ou définir GTC_KYC_DIR dans includes/db.local.php).");
        exit(1);
    }
    is_file($target . '/.htaccess') || file_put_contents($target . '/.htaccess', "Require all denied\n");
}

$moved = $same = $failed = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getFilename() === '.htaccess') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
    $dest     = $target . '/' . $relative;
    $hash     = hash_file('sha256', $file->getPathname());
    if (is_file($dest)) {
        if (hash_file('sha256', $dest) === $hash) {
            $same++;
            $dryRun || unlink($file->getPathname());       // copie identique déjà en place
            continue;
        }
        $failed++;
        $log("CONFLIT : {$relative} existe déjà à destination avec un contenu différent : laissé en place.");
        continue;
    }
    if ($dryRun) {
        $log("à déplacer : {$relative}");
        $moved++;
        continue;
    }
    if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0700, true)) {
        $failed++;
        $log("ÉCHEC : dossier {$relative} impossible à créer.");
        continue;
    }
    if (!@copy($file->getPathname(), $dest) || hash_file('sha256', $dest) !== $hash) {
        @unlink($dest);
        $failed++;
        $log("ÉCHEC : copie de {$relative} incomplète : laissé en place.");
        continue;
    }
    unlink($file->getPathname());
    $moved++;
}

// Dossiers vidés (AAAA/MM, avatars, tmp) : supprimés, data/kyc conservé
if (!$dryRun) {
    $dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                                          RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($dirs as $dir) {
        if ($dir->isDir()) {
            @rmdir($dir->getPathname());                   // échoue sans bruit s'il reste un fichier
        }
    }
}

$log(($dryRun ? '[SIMULATION] ' : '') . "{$moved} fichier(s) déplacé(s), {$same} déjà en place, {$failed} problème(s).");
exit($failed ? 1 : 0);
