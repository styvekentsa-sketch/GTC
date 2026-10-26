<?php
/**
 * Sauvegarde automatique de la base de données — à lancer chaque jour par une tâche planifiée (cron).
 *
 *   php tools/auto-backup.php            sauvegarde + purge des sauvegardes de plus de 30 jours
 *   php tools/auto-backup.php --list     liste les sauvegardes présentes
 *
 * Exemple de tâche cron (chaque nuit à 2 h 30, cPanel → Tâches cron) :
 *   30 2 * * *  /usr/local/bin/php /home/COMPTE/public_html/tools/auto-backup.php >> /home/COMPTE/gtc-backups/backup.log 2>&1
 * Windows (Planificateur de tâches) :  php.exe "D:\Site GTC\tools\auto-backup.php"
 *
 * Fichier produit : gtc-auto-AAAAMMJJ-HHMMSS.sql.gz (même contenu que « Télécharger une sauvegarde BDD »
 * dans Paramètres → Système, compressé). Dossier : voir includes/backup.php (hors de la racine web par défaut).
 * Chaque fichier est relu après écriture : une sauvegarde incomplète est supprimée et signalée (code de sortie 1).
 * Seuls les fichiers gtc-auto-* sont purgés ; rien d'autre dans le dossier n'est jamais supprimé.
 *
 * Restauration : décompresser le .gz, puis importer le .sql (sqlite3 data/gtc.sqlite < fichier.sql, ou phpMyAdmin).
 * Le dossier tools/ est inaccessible depuis le web (.htaccess).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/includes/config.php';
require $root . '/includes/backup.php';

$log = function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

$dir = backup_dir();
if ($dir === null) {
    $log('ÉCHEC : impossible de créer le dossier des sauvegardes (définir GTC_BACKUP_DIR dans includes/db.local.php).');
    exit(1);
}

if (in_array('--list', $argv, true)) {
    $log("Dossier : {$dir}");
    foreach (backup_list($dir) as $b) {
        printf("  %s  %8s Ko  %s\n", date('d/m/Y H:i', $b['time']), number_format($b['size'] / 1024, 0, ',', ' '), basename($b['path']));
    }
    exit(0);
}

// Une seule sauvegarde à la fois (tâche relancée alors que la précédente tourne encore)
$lock = fopen($dir . '/.backup.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('Sauvegarde déjà en cours : rien à faire.');
    exit(0);
}

/* ---------- 1. Sauvegarde compressée ---------- */
$name = BACKUP_AUTO_PREFIX . date('Ymd-His') . '.sql.gz';
$tmp  = $dir . '/.' . $name . '.part';   // renommé seulement une fois complet et vérifié
$path = $dir . '/' . $name;
try {
    $gz = gzopen($tmp, 'wb6');
    if (!$gz) {
        throw new RuntimeException("écriture impossible dans {$dir}");
    }
    backup_dump(function (string $chunk) use ($gz): void {
        if (gzwrite($gz, $chunk) === false) {
            throw new RuntimeException('écriture interrompue (disque plein ?)');
        }
    }, 'sauvegarde automatique');
    gzclose($gz);

    // 2. Vérification : le fichier se décompresse entièrement et se termine par la fin de sauvegarde attendue
    $in = gzopen($tmp, 'rb');
    $tail = '';
    while (!gzeof($in)) {
        $tail = substr($tail . gzread($in, 65536), -200);
    }
    gzclose($in);
    if (!str_contains($tail, DB_DRIVER === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 1;' : 'PRAGMA foreign_keys = ON;')) {
        throw new RuntimeException('fichier incomplet après relecture');
    }
    if (!rename($tmp, $path)) {
        throw new RuntimeException('impossible de finaliser le fichier');
    }
    @chmod($path, 0600);
    $log(sprintf('OK : %s (%s Ko)', $path, number_format(filesize($path) / 1024, 0, ',', ' ')));
} catch (Throwable $e) {
    @unlink($tmp);
    $log('ÉCHEC : ' . $e->getMessage());
    error_log('[GTC] Sauvegarde automatique : ' . $e->getMessage());
    exit(1);
}

/* ---------- 3. Historique glissant : sauvegardes de plus de GTC_BACKUP_KEEP_DAYS jours supprimées ---------- */
// La sauvegarde la plus récente est toujours conservée, même si l'horloge du serveur dérive.
$limit = time() - GTC_BACKUP_KEEP_DAYS * 86400;
$removed = 0;
foreach (array_slice(backup_list($dir), 1) as $b) {
    if ($b['time'] < $limit && @unlink($b['path'])) {
        $removed++;
    }
}
$log(sprintf('Historique : %d sauvegarde(s) conservée(s), %d supprimée(s) (plus de %d jours).',
    count(backup_list($dir)), $removed, GTC_BACKUP_KEEP_DAYS));

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
