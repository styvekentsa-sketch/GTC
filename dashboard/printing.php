<?php
/**
 * Impression réseau : file des travaux + liste des imprimantes de l'agence.
 *
 * Les imprimantes (ajout, modification, imprimante par défaut, test de connexion) se gèrent
 * dans Paramètres → Système & sauvegardes (administrateurs).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/printers.php';

$user = require_login();
require_roles(OPERATION_ROLES);   // pas d'accès pour le rôle Secrétaire / Accueil
$tab  = query('tab') === 'printers' ? 'printers' : 'jobs';

/* ==========================================================================
   Actions POST
   ========================================================================== */

$errors  = [];
$jobForm = ['title' => '', 'printer_id' => '', 'copies' => 1, 'color' => 0, 'duplex' => 0, 'priority' => 'normal', 'notes' => ''];

if (is_post()) {
    csrf_verify();
    $op = post('op');

    // ----- Travaux d'impression -----
    if ($op === 'job_create') {
        $jobForm = [
            'title'      => post('title'),
            'printer_id' => (int) post('printer_id'),
            'copies'     => (int) post('copies', '1'),
            'color'      => isset($_POST['color']) ? 1 : 0,
            'duplex'     => isset($_POST['duplex']) ? 1 : 0,
            'priority'   => post('priority', 'normal'),
            'notes'      => post('notes'),
        ];
        if (mb_strlen($jobForm['title']) < 2 || mb_strlen($jobForm['title']) > 190) {
            $errors['title'] = 'Indiquez le nom du document.';
        }
        if ($jobForm['copies'] < 1 || $jobForm['copies'] > 999) {
            $errors['copies'] = 'Entre 1 et 999 copies.';
        }
        if (!isset(JOB_PRIORITIES[$jobForm['priority']])) {
            $errors['priority'] = 'Priorité invalide.';
        }
        if ($jobForm['printer_id'] && !printer_find($jobForm['printer_id'])) {
            $errors['printer_id'] = 'Imprimante invalide.';
        }
        if (mb_strlen($jobForm['notes']) > 2000) {
            $errors['notes'] = 'Notes trop longues.';
        }

        if (!$errors) {
            db_query(
                'INSERT INTO print_jobs (title, printer_id, copies, color, duplex, priority, status, notes, requested_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$jobForm['title'], $jobForm['printer_id'] ?: null, $jobForm['copies'], $jobForm['color'], $jobForm['duplex'],
                 $jobForm['priority'], 'queued', $jobForm['notes'] ?: null, $user['id'], db_now(), db_now()]
            );
            flash('success', "Travail « {$jobForm['title']} » ajouté à la file.");
            redirect('printing.php');
        }
    }

    if ($op === 'job_status') {
        $status = post('status');
        if (isset(JOB_STATUSES[$status])) {
            db_query('UPDATE print_jobs SET status = ?, updated_at = ? WHERE id = ?', [$status, db_now(), (int) post('id')]);
            flash('success', 'Statut du travail mis à jour : ' . JOB_STATUSES[$status]['label'] . '.');
        }
        redirect('printing.php' . (query('status') ? '?status=' . rawurlencode(query('status')) : ''));
    }

    if ($op === 'job_delete') {
        $job = db_one('SELECT requested_by FROM print_jobs WHERE id = ?', [(int) post('id')]);
        if ($job && (is_admin() || (int) $job['requested_by'] === (int) $user['id'])) {
            db_query('DELETE FROM print_jobs WHERE id = ?', [(int) post('id')]);
            flash('success', 'Travail supprimé.');
        } else {
            flash('error', "Vous ne pouvez supprimer que vos propres travaux.");
        }
        redirect('printing.php');
    }
}

/* ==========================================================================
   Données
   ========================================================================== */

$printers = printers_list();
$activePrinters = array_filter($printers, fn ($p) => (int) $p['is_active'] === 1);
if ($jobForm['printer_id'] === '' && $activePrinters) {
    $jobForm['printer_id'] = (int) reset($activePrinters)['id'];   // imprimante par défaut
}

$filter = query('status');
$counts = [];
foreach (db_all('SELECT status, COUNT(*) AS n FROM print_jobs GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['n'];
}

$jobSql = 'SELECT j.*, p.printer_name, u.name AS requester
           FROM print_jobs j
           LEFT JOIN printers p ON p.id = j.printer_id
           LEFT JOIN users u ON u.id = j.requested_by';
$jobParams = [];
if (isset(JOB_STATUSES[$filter])) {
    $jobSql .= ' WHERE j.status = ?';
    $jobParams[] = $filter;
} else {
    $jobSql .= " WHERE j.status IN ('queued', 'printing')";
}
$jobSql .= " ORDER BY CASE j.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END, j.id DESC LIMIT 100";
$jobs = db_all($jobSql, $jobParams);

$err = fn (string $k) => isset($errors[$k]) ? '<small class="field__error">' . e($errors[$k]) . '</small>' : '';

dash_header('Impression réseau', 'printing');
?>

<nav class="tabs" aria-label="Sections">
    <a href="printing.php" class="tabs__item<?= $tab === 'jobs' ? ' is-active' : '' ?>"><?= icon('file') ?> Travaux d'impression</a>
    <a href="printing.php?tab=printers" class="tabs__item<?= $tab === 'printers' ? ' is-active' : '' ?>"><?= icon('printer') ?> Imprimantes (<?= count($printers) ?>)</a>
</nav>

<?php if ($tab === 'jobs'): ?>

    <div class="dash-grid dash-grid--aside">
        <!-- File des travaux -->
        <section class="panel">
            <header class="panel__head">
                <h2 class="panel__title">File d'impression</h2>
                <div class="chips">
                    <a href="printing.php" class="chip<?= !isset(JOB_STATUSES[$filter]) ? ' is-active' : '' ?>">Actifs (<?= ($counts['queued'] ?? 0) + ($counts['printing'] ?? 0) ?>)</a>
                    <?php foreach (JOB_STATUSES as $key => $s): ?>
                        <a href="printing.php?status=<?= e($key) ?>" class="chip<?= $filter === $key ? ' is-active' : '' ?>"><?= e($s['label']) ?> (<?= $counts[$key] ?? 0 ?>)</a>
                    <?php endforeach; ?>
                </div>
            </header>

            <?php if (!$jobs): ?>
                <p class="empty">Aucun travail dans cette catégorie.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Document</th><th>Imprimante</th><th>Options</th><th>Demandé par</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                        <tbody>
                        <?php foreach ($jobs as $j): ?>
                            <tr>
                                <td>
                                    <strong><?= e($j['title']) ?></strong>
                                    <?php if ($j['priority'] === 'high'): ?><span class="badge badge--danger">Urgent</span><?php endif; ?>
                                    <?php if ($j['notes']): ?><small class="cell-sub"><?= e($j['notes']) ?></small><?php endif; ?>
                                </td>
                                <td><?= e($j['printer_name'] ?? 'Non assignée') ?></td>
                                <td class="cell-options">
                                    <?= (int) $j['copies'] ?>×
                                    · <?= $j['color'] ? 'Couleur' : 'N&amp;B' ?>
                                    <?= $j['duplex'] ? '· Recto-verso' : '' ?>
                                </td>
                                <td><?= e($j['requester'] ?? '—') ?><small class="cell-sub"><?= e(fdate($j['created_at'], true)) ?></small></td>
                                <td>
                                    <form method="post" action="printing.php<?= $filter ? '?status=' . e(rawurlencode($filter)) : '' ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="op" value="job_status">
                                        <input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                                        <label class="visually-hidden" for="js-<?= (int) $j['id'] ?>">Statut</label>
                                        <select id="js-<?= (int) $j['id'] ?>" name="status" class="select-sm" data-autosubmit>
                                            <?php foreach (JOB_STATUSES as $key => $s): ?>
                                                <option value="<?= e($key) ?>" <?= $key === $j['status'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td class="actions">
                                    <?php if (is_admin() || (int) $j['requested_by'] === (int) $user['id']): ?>
                                        <form method="post" class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="op" value="job_delete">
                                            <input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                                            <button type="submit" class="icon-btn icon-btn--danger" title="Supprimer" data-confirm="Supprimer ce travail d'impression ?"><?= icon('trash') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Nouveau travail -->
        <section class="panel">
            <h2 class="panel__title">Nouveau travail</h2>
            <form method="post" class="form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="job_create">
                <div class="field">
                    <label for="title">Document *</label>
                    <input id="title" name="title" required maxlength="190" placeholder="Ex. Reçu de transfert n° 1245" value="<?= e($jobForm['title']) ?>">
                    <?= $err('title') ?>
                </div>
                <div class="field">
                    <label for="printer_id">Imprimante</label>
                    <select id="printer_id" name="printer_id">
                        <option value="">Non assignée</option>
                        <?php foreach ($activePrinters as $p): ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (int) $jobForm['printer_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                                <?= e(printer_label($p)) ?><?= (int) $p['is_default'] === 1 ? ' (par défaut)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('printer_id') ?>
                </div>
                <div class="form-grid form-grid--2">
                    <div class="field">
                        <label for="copies">Copies</label>
                        <input id="copies" name="copies" type="number" min="1" max="999" value="<?= (int) $jobForm['copies'] ?>">
                        <?= $err('copies') ?>
                    </div>
                    <div class="field">
                        <label for="priority">Priorité</label>
                        <select id="priority" name="priority">
                            <?php foreach (JOB_PRIORITIES as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $key === $jobForm['priority'] ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="checks">
                    <label class="check"><input type="checkbox" name="color" <?= $jobForm['color'] ? 'checked' : '' ?>> Couleur</label>
                    <label class="check"><input type="checkbox" name="duplex" <?= $jobForm['duplex'] ? 'checked' : '' ?>> Recto-verso</label>
                </div>
                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000"><?= e($jobForm['notes']) ?></textarea>
                    <?= $err('notes') ?>
                </div>
                <button type="submit" class="btn btn--primary btn--block"><?= icon('plus') ?> Ajouter à la file</button>
            </form>
        </section>
    </div>

<?php else: ?>

    <section class="panel">
        <header class="panel__head">
            <h2 class="panel__title">Imprimantes de l'agence</h2>
            <?php if (is_admin()): ?><a href="settings.php#systeme" class="panel__link">Gérer dans Paramètres</a><?php endif; ?>
        </header>
        <?php if (!$printers): ?>
            <p class="empty">Aucune imprimante enregistrée.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead><tr><th>Imprimante</th><th>Papier</th><th>Réseau</th><th>État</th></tr></thead>
                    <tbody>
                    <?php foreach ($printers as $p): ?>
                        <?php $state = $p['last_status'] === 'online' ? ['success', 'En ligne'] : ($p['last_status'] === 'offline' ? ['danger', 'Hors ligne'] : ['muted', 'Non testée']); ?>
                        <tr class="<?= $p['is_active'] ? '' : 'is-disabled' ?>">
                            <td data-label="Imprimante">
                                <strong><?= e($p['printer_name']) ?></strong>
                                <?php if ((int) $p['is_default'] === 1): ?><span class="badge badge--success">Par défaut</span><?php endif; ?>
                                <small class="cell-sub"><?= e($p['printer_location']) ?><?= $p['is_active'] ? '' : ' · désactivée' ?></small>
                            </td>
                            <td data-label="Papier"><?= e(PRINTER_FORMATS[$p['paper_format']] ?? $p['paper_format']) ?></td>
                            <td data-label="Réseau"><?= $p['host'] ? '<code>' . e($p['host']) . ':' . (int) $p['port'] . '</code>' : '<small class="cell-sub">—</small>' ?></td>
                            <td data-label="État">
                                <span class="badge badge--<?= $state[0] ?>"><?= $state[1] ?></span>
                                <?php if ($p['last_checked_at']): ?><small class="cell-sub"><?= e(fdate($p['last_checked_at'], true)) ?></small><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if (!is_admin()): ?>
            <p class="help-text"><?= icon('alert') ?> Seuls les administrateurs peuvent ajouter, modifier ou tester les imprimantes.</p>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php dash_footer(); ?>
