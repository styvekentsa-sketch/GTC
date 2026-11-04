<?php
/**
 * Sauvegardes de la base de données (administrateurs uniquement).
 *
 *   - Créer une sauvegarde maintenant : fichier compressé et vérifié, ajouté à l'historique ;
 *   - Télécharger : sauvegarde immédiate ou fichier de l'historique, compressé (.sql.gz) ou non (.sql),
 *     nommé gtc_backup_AAAA-MM-JJ_HHMM ;
 *   - Historique : sauvegardes automatiques (tools/auto-backup.php, tâche cron), manuelles et de sécurité ;
 *   - Restaurer : mot de passe de l'administrateur exigé ; sauvegarde de sécurité de l'état actuel prise avant ;
 *     SQLite : tout ou rien ; MySQL : retour automatique à l'état précédent en cas d'erreur ;
 *   - Supprimer.
 * Toutes les actions : POST + jeton CSRF, inscrites au journal d'activité. Moteur et fichiers : includes/backup.php.
 * Fichiers conservés hors de la racine web (dossier « gtc-backups » à côté du site, ou GTC_BACKUP_DIR).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/backup.php';

$user = require_login();
require_role('admin');

/** Taille lisible : 18,4 Ko / 2,1 Mo. */
function backup_size(int $bytes): string
{
    return $bytes >= 1048576
        ? number_format($bytes / 1048576, 1, ',', "\u{202F}") . ' Mo'
        : number_format(max(1, $bytes) / 1024, 1, ',', "\u{202F}") . ' Ko';
}

/** Envoie un fichier de l'historique : tel quel (.sql.gz) ou décompressé à la volée (.sql). */
function backup_send(array $b, bool $gzip): never
{
    session_write_close();
    header('Content-Type: ' . ($gzip ? 'application/gzip' : 'application/sql; charset=UTF-8'));
    header('Content-Disposition: attachment; filename="' . backup_download_name($b['time'], $gzip) . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($gzip) {
        header('Content-Length: ' . $b['size']);
        readfile($b['path']);
    } else {
        $gz = gzopen($b['path'], 'rb');
        while (!gzeof($gz)) {
            echo gzread($gz, 65536);
        }
        gzclose($gz);
    }
    exit;
}

/* ==========================================================================
   Actions (POST + CSRF)
   ========================================================================== */

if (is_post()) {
    csrf_verify();
    $op   = post('op');
    $name = post('name');

    // Sauvegarde immédiate téléchargée (sans passer par l'historique)
    if ($op === 'download_now') {
        $gzip = post('format') !== 'sql';
        activity_log('backup', 'database', null, 'Téléchargement d\'une sauvegarde complète', ['format' => $gzip ? '.sql.gz' : '.sql']);
        session_write_close();
        header('Content-Type: ' . ($gzip ? 'application/gzip' : 'application/sql; charset=UTF-8'));
        header('Content-Disposition: attachment; filename="' . backup_download_name(time(), $gzip) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if ($gzip) {
            // Compressé dans un fichier temporaire (supprimé aussitôt), puis envoyé
            $tmp = tempnam(sys_get_temp_dir(), 'gtcbk');
            $gz  = gzopen($tmp, 'wb6');
            backup_dump(function (string $chunk) use ($gz): void { gzwrite($gz, $chunk); }, $user['name']);
            gzclose($gz);
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
        } else {
            backup_dump(function (string $chunk): void { echo $chunk; }, $user['name']);
        }
        exit;
    }

    // Tous les documents PDF (reçus, factures, courriers) dans une archive : fichier temporaire, envoyé puis supprimé
    if ($op === 'pdfs') {
        require_once __DIR__ . '/../includes/pdf_export.php';
        $date = function (string $key): ?string {
            $v = post($key);
            $d = $v !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
            return $d && $d->format('Y-m-d') === $v ? $v : null;
        };
        [$from, $to] = [$date('from'), $date('to')];
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        try {
            [$zip, $counts] = pdf_export_zip($from, $to, $user);
        } catch (Throwable $e) {
            error_log('[GTC] Export des PDF : ' . $e->getMessage());
            flash('error', 'Export des documents PDF impossible : ' . $e->getMessage() . '.');
            redirect('backups.php');
        }
        activity_log('export', 'database', null, 'Documents PDF exportés (.zip)', [
            'période' => ($from ?? 'début') . ' → ' . ($to ?? 'aujourd\'hui'),
            'reçus' => $counts['recus-de-caisse'], 'factures' => $counts['factures'], 'courriers' => $counts['courriers'],
        ]);
        session_write_close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="gtc_documents_pdf_' . ($from || $to ? ($from ?? 'debut') . '_' . ($to ?? date('Y-m-d')) : date('Y-m-d_Hi')) . '.zip"');
        header('Content-Length: ' . filesize($zip));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($zip);
        @unlink($zip);
        exit;
    }

    // Rapport administratif PDF (includes/admin_report.php)
    if ($op === 'report') {
        require_once __DIR__ . '/../includes/admin_report.php';
        $pdf = admin_report_pdf($user);
        activity_log('export', 'database', null, 'Rapport administratif PDF');
        session_write_close();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="gtc_rapport_' . date('Y-m-d_Hi') . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }

    if ($op === 'create') {
        try {
            $path = backup_create('gtc-manuel-', $user['name']);
            activity_log('backup', 'database', null, 'Sauvegarde créée', ['fichier' => basename($path), 'taille' => backup_size((int) filesize($path))]);
            flash('success', 'Sauvegarde créée et vérifiée : ' . basename($path) . ' (' . backup_size((int) filesize($path)) . ').');
        } catch (Throwable $e) {
            error_log('[GTC] Sauvegarde manuelle : ' . $e->getMessage());
            flash('error', 'Sauvegarde impossible : ' . $e->getMessage() . '.');
        }
        redirect('backups.php');
    }

    $backup = backup_find($name);
    if (!$backup && in_array($op, ['download', 'delete', 'restore'], true)) {
        flash('error', 'Sauvegarde introuvable (supprimée entre-temps ?).');
        redirect('backups.php');
    }

    if ($op === 'download') {
        $gzip = post('format') !== 'sql';
        activity_log('backup', 'database', null, 'Téléchargement d\'une sauvegarde de l\'historique', ['fichier' => $backup['name'], 'format' => $gzip ? '.sql.gz' : '.sql']);
        backup_send($backup, $gzip);
    }

    if ($op === 'delete') {
        if (backup_delete($backup['name'])) {
            activity_log('delete', 'database', null, 'Sauvegarde supprimée', ['fichier' => $backup['name'], 'du' => date('d/m/Y H:i', $backup['time'])]);
            flash('success', 'Sauvegarde du ' . date('d/m/Y à H:i', $backup['time']) . ' supprimée.');
        } else {
            flash('error', 'Suppression impossible (droits sur le fichier ?).');
        }
        redirect('backups.php');
    }

    if ($op === 'restore') {
        $hash = (string) db_value('SELECT password FROM users WHERE id = ?', [(int) $user['id']]);
        if (!password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            activity_log('security', 'database', null, 'Restauration refusée : mot de passe incorrect', ['fichier' => $backup['name']]);
            flash('error', 'Mot de passe incorrect : la restauration n\'a pas été lancée.');
            redirect('backups.php');
        }
        if (post('confirm') !== '1') {
            flash('error', 'Cochez la case de confirmation pour lancer la restauration.');
            redirect('backups.php');
        }
        try {
            $result = backup_restore($backup['path'], $user['name']);
            // Journal : écrit dans la base restaurée (celui d'avant reste dans la sauvegarde de sécurité)
            activity_log('backup', 'database', null, 'Base restaurée', [
                'sauvegarde' => $backup['name'] . ' (' . date('d/m/Y H:i', $backup['time']) . ')',
                'sécurité'   => $result['safety'], 'instructions' => $result['statements'],
            ], $user);
            flash('success', 'Base restaurée à l\'état du ' . date('d/m/Y à H:i', $backup['time'])
                . '. L\'état précédent a été sauvegardé (« Avant restauration ») : vous pouvez revenir en arrière.');
        } catch (Throwable $e) {
            error_log('[GTC] Restauration : ' . $e->getMessage());
            flash('error', 'Restauration impossible : ' . $e->getMessage() . '.');
        }
        redirect('backups.php');
    }
}

/* ==========================================================================
   Vue
   ========================================================================== */

$dir      = backup_dir(false);
$history  = backup_list($dir, array_keys(BACKUP_KINDS));
$autos    = array_values(array_filter($history, fn ($b) => $b['kind'] === BACKUP_AUTO_PREFIX));
$lastAuto = $autos[0] ?? null;
$engine   = DB_DRIVER === 'mysql' ? 'MySQL' : 'SQLite';

dash_header('Sauvegardes', 'backups');
?>
<div class="dash-grid dash-grid--aside">
    <section class="panel">
        <header class="panel__head">
            <div>
                <h2 class="panel__title"><?= icon('archive') ?> Sauvegarder maintenant</h2>
                <span class="cell-sub">Structure et toutes les données : reçus, factures, dossiers de suivi, comptes, messages, paramètres.</span>
            </div>
        </header>
        <div class="backup-actions">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="create">
                <button type="submit" class="btn btn--primary"><?= icon('save') ?> Créer une sauvegarde maintenant</button>
                <small class="field__hint">Conservée sur le serveur, dans l'historique ci-dessous.</small>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="download_now">
                <div class="backup-actions__row">
                    <button type="submit" name="format" value="gz" class="btn btn--ghost"><?= icon('download') ?> Télécharger (.sql.gz compressé)</button>
                    <button type="submit" name="format" value="sql" class="btn btn--ghost"><?= icon('download') ?> Télécharger (.sql)</button>
                </div>
                <small class="field__hint">Fichier <code><?= e(backup_download_name(time(), true)) ?></code> enregistré sur cet ordinateur.</small>
            </form>
            <div class="backup-actions__more">
                <form method="post" class="backup-pdfs">
                    <?= csrf_field() ?>
                    <input type="hidden" name="op" value="pdfs">
                    <div class="backup-pdfs__period">
                        <label>Du <input type="date" name="from" max="<?= e(date('Y-m-d')) ?>"></label>
                        <label>au <input type="date" name="to" max="<?= e(date('Y-m-d')) ?>"></label>
                    </div>
                    <?php if (backup_zip_available()): ?>
                        <button type="submit" class="btn btn--ghost"><?= icon('download') ?> Télécharger tous les fichiers PDF (.zip)</button>
                        <small class="field__hint">Tous les reçus de caisse, factures / proformas / devis et courriers en PDF, rangés par dossier. Dates vides : tous les documents.</small>
                    <?php else: ?>
                        <button type="button" class="btn btn--ghost" disabled><?= icon('download') ?> Télécharger tous les fichiers PDF (.zip)</button>
                        <small class="field__hint">Indisponible : extension PHP « zip » non activée sur ce serveur (php.ini : <code>extension=zip</code>).</small>
                    <?php endif; ?>
                </form>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="op" value="report">
                    <button type="submit" class="btn btn--ghost"><?= icon('file') ?> Générer un rapport PDF</button>
                    <small class="field__hint">Bilan horodaté : base, sauvegardes, transactions, montants par devise, utilisateurs, journal.</small>
                </form>
            </div>
        </div>
        <p class="help-text"><?= icon('alert') ?> Fichiers confidentiels (comptes, empreintes des mots de passe, montants) : gardez une copie hors du serveur, en lieu sûr.</p>
    </section>

    <aside class="panel">
        <h2 class="panel__title">État</h2>
        <dl class="detail-list">
            <div><dt>Base</dt><dd><?= e($engine) ?><?= DB_DRIVER === 'mysql' ? ' · ' . e((string) DB_NAME) : '' ?></dd></div>
            <div><dt>Sauvegardes automatiques</dt><dd>
                <?php if (!$lastAuto): ?>
                    <span class="badge badge--warning">Non programmées</span>
                <?php else: ?>
                    <?= $lastAuto['time'] < time() - 2 * 86400 ? '<span class="badge badge--danger">En retard</span>' : '<span class="badge badge--success">Actives</span>' ?>
                    <small class="cell-sub">dernière le <?= e(date('d/m/Y à H:i', $lastAuto['time'])) ?></small>
                <?php endif; ?>
            </dd></div>
            <div><dt>Historique</dt><dd><?= count($history) ?> fichier<?= count($history) > 1 ? 's' : '' ?> · <?= e(backup_size(array_sum(array_column($history, 'size')))) ?></dd></div>
            <div><dt>Conservation</dt><dd>automatiques : <?= (int) GTC_BACKUP_KEEP_DAYS ?> jours · autres : jusqu'à suppression</dd></div>
        </dl>
        <?php if (!$lastAuto): ?>
            <p class="backup-cron">Programmez une tâche quotidienne (hPanel → Avancé → Tâches cron) :
                <code>php <?= e(str_replace('\\', '/', (string) realpath(__DIR__ . '/../tools/auto-backup.php'))) ?></code></p>
        <?php endif; ?>
        <?php if ($dir): ?><p class="cell-sub backup-dir">Dossier : <code><?= e($dir) ?></code></p><?php endif; ?>
    </aside>
</div>

<section class="panel">
    <header class="panel__head">
        <h2 class="panel__title"><?= icon('history') ?> Historique des sauvegardes</h2>
    </header>
    <?php if (!$history): ?>
        <p class="empty">Aucune sauvegarde sur le serveur pour le moment. « Créer une sauvegarde maintenant » ajoute la première.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--hover table--stack">
                <thead><tr><th>Date</th><th>Type</th><th>Créée par</th><th class="num">Taille</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($history as $i => $b):
                    [$kindLabel, $tone] = BACKUP_KINDS[$b['kind']];
                    $head = backup_header($b['path']);
                    $when = date('d/m/Y à H:i', $b['time']);
                    ob_start(); ?>
                    <form method="post" class="backup-restore">
                        <?= csrf_field() ?>
                        <input type="hidden" name="op" value="restore">
                        <input type="hidden" name="name" value="<?= e($b['name']) ?>">
                        <p>Toute la base revient à l'état du <strong><?= e($when) ?></strong>. Les opérations enregistrées depuis seront retirées
                            (l'état actuel est d'abord sauvegardé).</p>
                        <label for="rp-<?= $i ?>">Votre mot de passe</label>
                        <input id="rp-<?= $i ?>" name="password" type="password" required autocomplete="current-password">
                        <label class="check"><input type="checkbox" name="confirm" value="1" required> Je confirme la restauration</label>
                        <button type="submit" class="btn btn--danger btn--sm" data-confirm="Restaurer toute la base à l'état du <?= e($when) ?> ?"><?= icon('refresh') ?> Restaurer</button>
                    </form>
                    <?php $restoreForm = (string) ob_get_clean(); ?>
                    <tr>
                        <td data-label="Date"><strong><?= e($when) ?></strong><?= $i === 0 ? ' <span class="badge badge--muted">la plus récente</span>' : '' ?></td>
                        <td data-label="Type"><span class="badge badge--<?= e($tone) ?>"><?= e($kindLabel) ?></span></td>
                        <td data-label="Créée par"><?= e($head['author'] !== '' ? $head['author'] : '—') ?></td>
                        <td data-label="Taille" class="num"><?= e(backup_size($b['size'])) ?></td>
                        <td class="actions">
                            <?= action_menu([
                                ['post', 'Télécharger (.sql.gz)', 'download', ['op' => 'download', 'name' => $b['name'], 'format' => 'gz']],
                                ['post', 'Télécharger (.sql)', 'download', ['op' => 'download', 'name' => $b['name'], 'format' => 'sql']],
                                ['sep'],
                                ['sub', 'Restaurer cette sauvegarde…', 'refresh', $restoreForm],
                                ['sep'],
                                ['post', 'Supprimer', 'trash', ['op' => 'delete', 'name' => $b['name']], 'Supprimer définitivement la sauvegarde du ' . $when . ' ?', 'danger' => true],
                            ], 'Actions de la sauvegarde du ' . $when) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php dash_footer(); ?>
