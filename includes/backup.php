<?php
/**
 * Sauvegarde SQL de la base (structure + données), SQLite ou MySQL.
 *
 *   dashboard/settings.php   « Télécharger une sauvegarde BDD » (téléchargement immédiat)
 *   tools/auto-backup.php    Sauvegarde automatique quotidienne (tâche cron), historique glissant
 *
 * Dossier des sauvegardes automatiques : GTC_BACKUP_DIR (includes/db.local.php), sinon le dossier
 * « gtc-backups » À CÔTÉ du site (hors de la racine web), sinon data/backups (accès web interdit).
 */
require_once __DIR__ . '/db.php';

defined('GTC_BACKUP_KEEP_DAYS') || define('GTC_BACKUP_KEEP_DAYS', 30);   // historique glissant (jours)

const BACKUP_AUTO_PREFIX = 'gtc-auto-';   // seuls ces fichiers sont concernés par la purge

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

/** Sauvegardes automatiques présentes, de la plus récente à la plus ancienne : [[chemin, taille, date], …]. */
function backup_list(?string $dir = null): array
{
    $dir ??= backup_dir(false);
    if ($dir === null) {
        return [];
    }
    $files = [];
    foreach (glob($dir . '/' . BACKUP_AUTO_PREFIX . '*.sql.gz') ?: [] as $path) {
        $files[] = ['path' => $path, 'size' => filesize($path), 'time' => filemtime($path)];
    }
    usort($files, fn ($a, $b) => $b['time'] <=> $a['time']);
    return $files;
}
