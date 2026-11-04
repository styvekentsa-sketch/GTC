<?php
/**
 * Sauvegarde SQL de la base (structure + données), SQLite ou MySQL — en PHP pur (pas besoin de mysqldump).
 *
 *   dashboard/backups.php    Sauvegardes : création, historique, téléchargement (.sql.gz / .sql), restauration, suppression
 *   dashboard/settings.php   « Télécharger une sauvegarde BDD » (téléchargement immédiat)
 *   tools/auto-backup.php    Sauvegarde automatique quotidienne (tâche cron), historique glissant
 *
 * Fichiers de l'historique (compressés, vérifiés après écriture) :
 *   gtc-auto-AAAAMMJJ-HHMMSS.sql.gz      automatiques (purgées après GTC_BACKUP_KEEP_DAYS jours)
 *   gtc-manuel-AAAAMMJJ-HHMMSS.sql.gz    créées depuis le Dashboard (conservées jusqu'à suppression)
 *   gtc-securite-AAAAMMJJ-HHMMSS.sql.gz  prises juste avant chaque restauration (retour arrière possible)
 *
 * Dossier des sauvegardes automatiques : GTC_BACKUP_DIR (includes/db.local.php), sinon le dossier
 * « gtc-backups » À CÔTÉ du site (hors de la racine web), sinon data/backups (accès web interdit).
 */
require_once __DIR__ . '/db.php';

defined('GTC_BACKUP_KEEP_DAYS') || define('GTC_BACKUP_KEEP_DAYS', 30);   // historique glissant (jours)

const BACKUP_AUTO_PREFIX = 'gtc-auto-';   // seuls ces fichiers sont concernés par la purge

/** Types de sauvegarde de l'historique : préfixe => [libellé, ton du badge]. */
const BACKUP_KINDS = [
    'gtc-auto-'     => ['Automatique', 'info'],
    'gtc-manuel-'   => ['Manuelle', 'success'],
    'gtc-securite-' => ['Avant restauration', 'warning'],
];

/** Nom de fichier valide de l'historique (aucun autre fichier du dossier n'est jamais lu ni supprimé). */
const BACKUP_NAME_PATTERN = '/^gtc-(auto|manuel|securite)-\d{8}-\d{6}\.sql\.gz$/';

/**
 * Écrit la sauvegarde SQL complète via $write (une chaîne à la fois) : la sortie ne passe jamais
 * entièrement en mémoire, même pour une grosse base.
 */
function backup_dump(callable $write, string $author): void
{
    $pdo   = db();
    $mysql = DB_DRIVER === 'mysql';
    $tables = $mysql
        ? $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    // users en premier (clés étrangères)
    usort($tables, fn ($a, $b) => ($b === 'users') <=> ($a === 'users') ?: strcmp($a, $b));

    $quoteId = fn (string $id) => $mysql ? '`' . str_replace('`', '``', $id) . '`' : '"' . str_replace('"', '""', $id) . '"';

    $write("-- Sauvegarde " . GTC_NAME . "\n");
    $write("-- Générée le " . date('d/m/Y à H:i') . ' par ' . str_replace(["\r", "\n"], ' ', $author) . "\n");
    $write("-- Moteur : " . ($mysql ? 'MySQL' : 'SQLite') . "\n");
    $write("-- ATTENTION : fichier confidentiel (comptes, empreintes de mots de passe, factures). À conserver en lieu sûr.\n\n");
    $write($mysql ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n" : "PRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n\n");

    foreach ($tables as $table) {
        $create = $mysql
            ? $pdo->query('SHOW CREATE TABLE ' . $quoteId($table))->fetch(PDO::FETCH_NUM)[1]
            : $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetchColumn();
        $write("-- Table {$table}\nDROP TABLE IF EXISTS " . $quoteId($table) . ";\n{$create};\n");

        $stmt = $pdo->query('SELECT * FROM ' . $quoteId($table));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols = implode(', ', array_map($quoteId, array_keys($row)));
            $vals = implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row));
            $write('INSERT INTO ' . $quoteId($table) . " ({$cols}) VALUES ({$vals});\n");
        }
        $write("\n");
    }
    if (!$mysql) {
        foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL") as $index) {
            $write($index['sql'] . ";\n");
        }
    }
    $write($mysql ? "\nSET FOREIGN_KEY_CHECKS = 1;\n" : "\nCOMMIT;\nPRAGMA foreign_keys = ON;\n");
}

/**
 * Dossier des sauvegardes automatiques, ou null. $create : le créer et le protéger au besoin (tools/auto-backup.php) ;
 * sinon (affichage dans les Paramètres), seul un dossier déjà existant est renvoyé.
 */
function backup_dir(bool $create = true): ?string
{
    $root = dirname(__DIR__);
    $candidates = defined('GTC_BACKUP_DIR') && GTC_BACKUP_DIR !== ''
        ? [GTC_BACKUP_DIR]
        : [dirname($root) . DIRECTORY_SEPARATOR . 'gtc-backups', $root . '/data/backups'];
    foreach ($candidates as $dir) {
        if (!$create) {
            if (is_dir($dir)) {
                return realpath($dir) ?: $dir;
            }
            continue;
        }
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            // Protection si le dossier se trouve malgré tout sous la racine web
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            }
            if (!is_file($dir . '/index.html')) {
                @file_put_contents($dir . '/index.html', '');
            }
            return realpath($dir) ?: $dir;
        }
    }
    return null;
}

/**
 * Sauvegardes de l'historique, de la plus récente à la plus ancienne : [[path, name, kind, size, time], …].
 * $prefixes : types retenus (par défaut : automatiques seulement, comme avant ; BACKUP_KINDS pour tout l'historique).
 */
function backup_list(?string $dir = null, array $prefixes = [BACKUP_AUTO_PREFIX]): array
{
    $dir ??= backup_dir(false);
    if ($dir === null) {
        return [];
    }
    $files = [];
    foreach ($prefixes as $prefix) {
        foreach (glob($dir . '/' . $prefix . '*.sql.gz') ?: [] as $path) {
            if (preg_match(BACKUP_NAME_PATTERN, basename($path))) {
                $files[] = ['path' => $path, 'name' => basename($path), 'kind' => $prefix, 'size' => filesize($path), 'time' => filemtime($path)];
            }
        }
    }
    usort($files, fn ($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
    return $files;
}

/** Sauvegarde de l'historique désignée par son nom (validé), ou null. */
function backup_find(string $name): ?array
{
    if (!preg_match(BACKUP_NAME_PATTERN, $name)) {
        return null;
    }
    foreach (backup_list(null, array_keys(BACKUP_KINDS)) as $b) {
        if ($b['name'] === $name) {
            return $b;
        }
    }
    return null;
}

/** Auteur et moteur notés dans l'en-tête du fichier : ['author' => …, 'engine' => 'MySQL'|'SQLite'|'']. */
function backup_header(string $path): array
{
    $info = ['author' => '', 'engine' => ''];
    if ($gz = @gzopen($path, 'rb')) {
        for ($i = 0; $i < 6 && !gzeof($gz); $i++) {
            $line = (string) gzgets($gz, 1000);
            if (preg_match('/^-- Générée le .+ par (.+)$/u', trim($line), $m)) {
                $info['author'] = $m[1];
            } elseif (preg_match('/^-- Moteur : (MySQL|SQLite)/', $line, $m)) {
                $info['engine'] = $m[1];
            }
        }
        gzclose($gz);
    }
    return $info;
}

/** Fin attendue d'une sauvegarde complète (dernière instruction écrite par backup_dump()). */
function backup_end_marker(string $engine): string
{
    return $engine === 'MySQL' ? 'SET FOREIGN_KEY_CHECKS = 1;' : 'PRAGMA foreign_keys = ON;';
}

/**
 * Crée une sauvegarde compressée dans l'historique et la vérifie (décompression complète, fin attendue).
 * Une seule à la fois (verrou) ; fichier temporaire renommé seulement une fois vérifié. Retourne son chemin.
 * @throws RuntimeException
 */
function backup_create(string $prefix, string $author): string
{
    if (!isset(BACKUP_KINDS[$prefix])) {
        throw new RuntimeException('type de sauvegarde inconnu');
    }
    $dir = backup_dir();
    if ($dir === null) {
        throw new RuntimeException('impossible de créer le dossier des sauvegardes (définir GTC_BACKUP_DIR dans includes/db.local.php)');
    }
    $lock = fopen($dir . '/.backup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('une sauvegarde est déjà en cours, réessayez dans un instant');
    }
    $name = $prefix . date('Ymd-His') . '.sql.gz';
    for ($i = 1; is_file($dir . '/' . $name); $i++) {   // deux sauvegardes dans la même seconde
        $name = $prefix . date('Ymd-His', time() + $i) . '.sql.gz';
    }
    $tmp  = $dir . '/.' . $name . '.part';
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
        }, $author);
        gzclose($gz);

        $in = gzopen($tmp, 'rb');
        $tail = '';
        while (!gzeof($in)) {
            $tail = substr($tail . gzread($in, 65536), -200);
        }
        gzclose($in);
        if (!str_contains($tail, backup_end_marker(DB_DRIVER === 'mysql' ? 'MySQL' : 'SQLite'))) {
            throw new RuntimeException('fichier incomplet après relecture');
        }
        if (!rename($tmp, $path)) {
            throw new RuntimeException('impossible de finaliser le fichier');
        }
        @chmod($path, 0600);
        return $path;
    } catch (Throwable $e) {
        @unlink($tmp);
        throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Instructions SQL d'une sauvegarde, une à une (générateur : le fichier n'est jamais chargé entièrement).
 * Découpage sur « ; » hors des chaînes et noms entre guillemets ; commentaires « -- » ignorés.
 * $mysql : chaînes échappées par barre oblique inverse (PDO::quote MySQL) en plus du doublement des apostrophes.
 */
function backup_statements(string $path, bool $mysql): Generator
{
    $gz = gzopen($path, 'rb');
    if (!$gz) {
        throw new RuntimeException('fichier illisible');
    }
    $sql = '';
    $quote = null;   // caractère de la chaîne en cours (' " `), ou null
    try {
        while (!gzeof($gz)) {
            $line = (string) gzgets($gz, 1 << 20);
            // Ligne de commentaire entre deux instructions (le reste de la ligne précédente n'est que des blancs)
            if ($quote === null && trim($sql) === '' && str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $c = $line[$i];
                if ($quote !== null) {
                    if ($mysql && $c === '\\' && $i + 1 < $len) {
                        $sql .= $c . $line[++$i];
                        continue;
                    }
                    if ($c === $quote) {
                        $quote = null;   // une apostrophe doublée ('') rouvre aussitôt la chaîne au caractère suivant
                    }
                    $sql .= $c;
                } elseif ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c;
                    $sql .= $c;
                } elseif ($c === ';') {
                    if (trim($sql) !== '') {
                        yield trim($sql);
                    }
                    $sql = '';
                } else {
                    $sql .= $c;
                }
            }
        }
    } finally {
        gzclose($gz);
    }
    if ($quote !== null || trim($sql) !== '') {
        throw new RuntimeException('fin de fichier inattendue (sauvegarde tronquée)');
    }
}

/**
 * Vérifie qu'une sauvegarde est restaurable sur ce serveur : même moteur, fichier complet, tables lisibles.
 * Retourne la liste des tables qu'elle contient. @throws RuntimeException
 */
function backup_check(string $path): array
{
    $engine = backup_header($path)['engine'];
    $here   = DB_DRIVER === 'mysql' ? 'MySQL' : 'SQLite';
    if ($engine !== $here) {
        throw new RuntimeException("sauvegarde {$engine} incompatible avec la base {$here} de ce serveur");
    }
    $tables = [];
    $last = '';
    foreach (backup_statements($path, $here === 'MySQL') as $sql) {
        if (preg_match('/^DROP TABLE IF EXISTS [`"]?([A-Za-z0-9_]+)[`"]?$/', $sql, $m)) {
            $tables[] = $m[1];
        }
        $last = $sql;
    }
    if ($last . ';' !== backup_end_marker($engine) || !in_array('users', $tables, true)) {
        throw new RuntimeException('sauvegarde incomplète ou endommagée');
    }
    return $tables;
}

/**
 * Restaure une sauvegarde de l'historique : la base retrouve exactement l'état de la sauvegarde (les tables absentes
 * de la sauvegarde sont supprimées). Une sauvegarde de sécurité de l'état actuel est prise juste avant.
 *   SQLite : tout se fait dans une seule transaction — en cas d'erreur, rien n'est modifié ;
 *   MySQL  : les changements de structure ne sont pas annulables — en cas d'erreur, la sauvegarde de sécurité
 *            est restaurée automatiquement.
 * Retourne ['safety' => nom de la sauvegarde de sécurité, 'statements' => nombre d'instructions]. @throws RuntimeException
 */
function backup_restore(string $path, string $author, bool $withSafety = true): array
{
    $mysql  = DB_DRIVER === 'mysql';
    $tables = backup_check($path);
    $safety = $withSafety ? backup_create('gtc-securite-', $author . ' (avant restauration)') : null;

    $pdo  = db();
    $qid  = fn (string $t) => $mysql ? '`' . str_replace('`', '``', $t) . '`' : '"' . str_replace('"', '""', $t) . '"';
    $current = $mysql
        ? $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    $count = 0;
    @set_time_limit(600);
    try {
        if ($mysql) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        } else {
            $pdo->exec('PRAGMA foreign_keys = OFF');   // sans effet dans une transaction : à régler avant
            $pdo->beginTransaction();
        }
        foreach (array_diff($current, $tables) as $extra) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $qid($extra));
        }
        foreach (backup_statements($path, $mysql) as $sql) {
            // Transaction et clés étrangères gérées ici (instructions de la sauvegarde ignorées)
            if (preg_match('/^(BEGIN TRANSACTION|COMMIT|PRAGMA foreign_keys\s*=\s*(ON|OFF))$/i', $sql)) {
                continue;
            }
            $pdo->exec($sql);
            $count++;
        }
        if (!$mysql) {
            $pdo->commit();
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    } catch (Throwable $e) {
        if (!$mysql && $pdo->inTransaction()) {
            $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw new RuntimeException('restauration annulée, base inchangée : ' . $e->getMessage(), 0, $e);
        }
        if ($mysql && $safety) {
            try {
                backup_restore($safety, $author, false);
            } catch (Throwable $back) {
                throw new RuntimeException('restauration interrompue ET retour arrière impossible : restaurez « ' . basename($safety)
                    . ' » avec phpMyAdmin (' . $e->getMessage() . ')', 0, $e);
            }
            throw new RuntimeException('restauration interrompue, état précédent rétabli : ' . $e->getMessage(), 0, $e);
        }
        throw new RuntimeException($e->getMessage(), 0, $e);
    }
    return ['safety' => $safety ? basename($safety) : null, 'statements' => $count];
}

/** Supprime une sauvegarde de l'historique (nom validé). */
function backup_delete(string $name): bool
{
    $b = backup_find($name);
    return $b !== null && @unlink($b['path']);
}

/** ZipArchive disponible (extension PHP « zip ») ? Export des documents PDF (includes/pdf_export.php). */
function backup_zip_available(): bool
{
    return class_exists('ZipArchive');
}

/** Nom proposé au téléchargement : gtc_backup_2026-11-04_0230.sql(.gz). */
function backup_download_name(int $time, bool $gzip): string
{
    return 'gtc_backup_' . date('Y-m-d_Hi', $time) . ($gzip ? '.sql.gz' : '.sql');
}
