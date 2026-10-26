<?php
/**
 * Courriers officiels sur le papier à en-tête de Global Trading Corporation.
 *
 *   courriers.php                        Historique (recherche, statut, pagination)
 *   courriers.php?action=new[&from=ID]   Nouveau courrier (éventuellement à partir d'un courrier existant)
 *   courriers.php?action=edit&id=ID      Modification
 *   print-courrier.php?id=ID             Impression A4 sur le papier à en-tête
 *   pdf.php?type=courrier&id=ID          PDF prêt à envoyer (e-mail, WhatsApp)
 *
 * Règles et enregistrement : includes/courriers.php. Accès : comptes des opérations ; suppression réservée
 * aux administrateurs.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/courriers.php';

$user = require_login();
require_roles(OPERATION_ROLES);

$action = query('action', 'list');
$id     = (int) query('id');
$form   = null;
$errors = [];

/* ==========================================================================
   Actions (POST + CSRF)
   ========================================================================== */

if (is_post()) {
    csrf_verify();
    $op = post('op');

    if ($op === 'save') {
        $editId = (int) post('id') ?: null;
        if ($editId !== null && !db_value('SELECT 1 FROM courriers WHERE id = ?', [$editId])) {
            flash('error', 'Courrier introuvable (supprimé entre-temps ?).');
            redirect('courriers.php');
        }
        [$form, $errors] = courrier_input();
        if (!$errors) {
            $savedId = courrier_save($form, $user, $editId);
            $ref     = db_value('SELECT reference FROM courriers WHERE id = ?', [$savedId]);
            activity_log($editId ? 'update' : 'create', 'courrier', $savedId, $ref, [
                'destinataire' => $form['recipient_company'] !== '' ? $form['recipient_company'] : $form['recipient_name'], 'objet' => $form['subject'],
            ]);
            flash('success', "Courrier {$ref} " . ($editId ? 'modifié.' : 'enregistré.'));
            $then = post('then');
            redirect(match ($then) {
                'print' => "print-courrier.php?id={$savedId}&autoprint=1",
                'pdf'   => "pdf.php?type=courrier&id={$savedId}",
                default => 'courriers.php',
            });
        }
        $action = $editId ? 'edit' : 'new';
        $id     = (int) $editId;
    }

    if ($op === 'status') {
        $status = post('status');
        if (isset(COURRIER_STATUSES[$status])) {
            $before = db_one('SELECT reference, status FROM courriers WHERE id = ?', [(int) post('id')]);
            db_query('UPDATE courriers SET status = ?, updated_at = ? WHERE id = ?', [$status, db_now(), (int) post('id')]);
            if ($before && $before['status'] !== $status) {
                activity_log('status', 'courrier', (int) post('id'), $before['reference'],
                    ['statut' => (COURRIER_STATUSES[$before['status']]['label'] ?? $before['status']) . ' → ' . COURRIER_STATUSES[$status]['label']]);
            }
            flash('success', 'Statut mis à jour : ' . COURRIER_STATUSES[$status]['label'] . '.');
        }
        redirect('courriers.php');
    }

    if ($op === 'delete') {
        require_role('admin');
        $deleted = db_one('SELECT reference, recipient_company, recipient_name, subject FROM courriers WHERE id = ?', [(int) post('id')]);
        $ref     = $deleted['reference'] ?? null;
        if ($ref) {
            db_query('DELETE FROM courriers WHERE id = ?', [(int) post('id')]);
            activity_log('delete', 'courrier', (int) post('id'), $ref, [
                'destinataire' => $deleted['recipient_company'] ?: $deleted['recipient_name'], 'objet' => $deleted['subject'],
            ]);
            flash('success', "Courrier {$ref} supprimé.");
        }
        redirect('courriers.php');
    }
}

/* ==========================================================================
   Vue : rédaction / modification
   ========================================================================== */

if ($action === 'new' || $action === 'edit') {
    $existing = $action === 'edit' ? courrier_find($id) : null;
    if ($action === 'edit' && !$existing) {
        flash('error', 'Courrier introuvable.');
        redirect('courriers.php');
    }
    if ($form === null) {
        $source = $existing ?? (query('from') ? courrier_find((int) query('from')) : null);
        $form   = $source ?? array_fill_keys(array_keys(COURRIER_LIMITS), '');
        if (!$existing) {
            // Nouveau courrier (vierge ou dupliqué) : date du jour, brouillon ; valeurs par défaut si vierge
            $form['letter_date'] = date('Y-m-d');
            $form['status']      = 'draft';
            if (!$source) {
                $form = array_merge($form, [
                    'place' => COURRIER_DEFAULTS['place'], 'recipient_country' => COURRIER_DEFAULTS['country'],
                    'salutation' => COURRIER_DEFAULTS['salutation'], 'closing' => COURRIER_DEFAULTS['closing'],
                ], courrier_last_signatory($user));
            }
        }
    }
    $err = fn (string $k) => isset($errors[$k]) ? '<p class="field__error">' . e($errors[$k]) . '</p>' : '';
    $inv = fn (string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
    $val = fn (string $k) => e((string) ($form[$k] ?? ''));
    $max = fn (string $k) => ' maxlength="' . COURRIER_LIMITS[$k] . '"';

    dash_header($existing ? 'Modifier ' . $existing['reference'] : 'Nouveau courrier', 'courriers');
    ?>
    <div class="toolbar">
        <a href="courriers.php" class="btn btn--ghost"><?= icon('arrow-left') ?> Tous les courriers</a>
        <?php if ($existing): ?>
            <div class="toolbar__actions">
                <a href="print-courrier.php?id=<?= (int) $existing['id'] ?>" class="btn btn--ghost" target="_blank" rel="noopener"><?= icon('printer') ?> Aperçu / impression</a>
                <a href="pdf.php?type=courrier&amp;id=<?= (int) $existing['id'] ?>" class="btn btn--ghost"><?= icon('download') ?> PDF</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>Merci de corriger les champs indiqués.</p></div>
    <?php endif; ?>

    <form method="post" class="panel form courrier-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <?php if ($existing): ?><input type="hidden" name="id" value="<?= (int) $existing['id'] ?>"><?php endif; ?>

        <header class="panel__head">
            <h2 class="panel__title"><?= icon('letter') ?> <?= $existing ? 'Courrier ' . e($existing['reference']) : 'Nouveau courrier' ?></h2>
            <span class="cell-sub"><?= $existing
                ? 'Rédigé par ' . e($existing['created_by_name'] ?? '—') . ' le ' . e(fdate($existing['created_at'], true))
                : 'Référence attribuée à l\'enregistrement (prochaine : ' . e(courrier_next_reference(date('Y-m-d'))) . ')' ?></span>
        </header>

        <!-- ==================== MODÈLE (assets/js/courrier-editor.js) ==================== -->
        <div class="courrier-template" data-courrier-template hidden>
            <label for="courrier-template"><?= icon('letter') ?> Modèle de courrier</label>
            <select id="courrier-template" data-templates="<?= e(json_encode(COURRIER_TEMPLATES, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
                <option value="">— Choisir un modèle pour pré-remplir l'objet et le corps —</option>
                <?php foreach (COURRIER_TEMPLATES as $key => $tpl): ?>
                    <option value="<?= e($key) ?>"><?= e($tpl['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="field__hint">Le modèle remplace l'objet et le corps actuels (confirmation demandée si vous avez déjà écrit).</small>
        </div>

        <!-- ==================== DESTINATAIRE ==================== -->
        <fieldset class="form-section">
            <legend>Destinataire</legend>
            <div class="form-grid">
                <div class="field">
                    <label for="recipient_company">Entreprise / administration</label>
                    <input id="recipient_company" name="recipient_company" value="<?= $val('recipient_company') ?>"<?= $max('recipient_company') . $inv('recipient_company') ?> placeholder="Ex. Port Autonome de Douala">
                    <?= $err('recipient_company') ?>
                </div>
                <div class="field">
                    <label for="recipient_name">Nom du destinataire</label>
                    <input id="recipient_name" name="recipient_name" value="<?= $val('recipient_name') ?>"<?= $max('recipient_name') . $inv('recipient_name') ?> placeholder="Ex. Monsieur Jean Mbarga">
                    <?= $err('recipient_name') ?>
                </div>
                <div class="field">
                    <label for="recipient_role">Fonction <small>(facultatif)</small></label>
                    <input id="recipient_role" name="recipient_role" value="<?= $val('recipient_role') ?>"<?= $max('recipient_role') . $inv('recipient_role') ?> placeholder="Ex. Directeur Général">
                    <?= $err('recipient_role') ?>
                </div>
                <div class="field field--full">
                    <label for="recipient_address">Adresse</label>
                    <input id="recipient_address" name="recipient_address" value="<?= $val('recipient_address') ?>"<?= $max('recipient_address') . $inv('recipient_address') ?> placeholder="Ex. BP 4020, Boulevard de la Liberté">
                    <?= $err('recipient_address') ?>
                </div>
                <div class="field">
                    <label for="recipient_city">Ville</label>
                    <input id="recipient_city" name="recipient_city" value="<?= $val('recipient_city') ?>"<?= $max('recipient_city') . $inv('recipient_city') ?>>
                    <?= $err('recipient_city') ?>
                </div>
                <div class="field">
                    <label for="recipient_country">Pays</label>
                    <input id="recipient_country" name="recipient_country" value="<?= $val('recipient_country') ?>"<?= $max('recipient_country') . $inv('recipient_country') ?>>
                    <?= $err('recipient_country') ?>
                </div>
            </div>
        </fieldset>

        <!-- ==================== LETTRE ==================== -->
        <fieldset class="form-section">
            <legend>Lettre</legend>
            <div class="form-grid">
                <div class="field">
                    <label for="place">Lieu d'émission *</label>
                    <input id="place" name="place" required value="<?= $val('place') ?>"<?= $max('place') . $inv('place') ?>>
                    <?= $err('place') ?>
                </div>
                <div class="field">
                    <label for="letter_date">Date *</label>
                    <input id="letter_date" name="letter_date" type="date" required value="<?= $val('letter_date') ?>"<?= $inv('letter_date') ?>>
                    <?= $err('letter_date') ?>
                </div>
                <div class="field">
                    <label for="status">Statut</label>
                    <select id="status" name="status">
                        <?php foreach (COURRIER_STATUSES as $key => $st): ?>
                            <option value="<?= e($key) ?>" <?= $key === ($form['status'] ?? 'draft') ? 'selected' : '' ?>><?= e($st['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field field--full">
                    <label for="subject">Objet *</label>
                    <input id="subject" name="subject" required value="<?= $val('subject') ?>"<?= $max('subject') . $inv('subject') ?> placeholder="Ex. Demande de cotation pour le transit de 3 conteneurs">
                    <?= $err('subject') ?>
                </div>
                <div class="field field--full">
                    <label for="attachments">Pièces jointes <small>(facultatif)</small></label>
                    <input id="attachments" name="attachments" value="<?= $val('attachments') ?>"<?= $max('attachments') . $inv('attachments') ?> placeholder="Ex. Facture proforma PRO-2026-0004, copie du connaissement">
                    <?= $err('attachments') ?>
                </div>
            </div>
        </fieldset>

        <!-- ==================== CONTENU ==================== -->
        <fieldset class="form-section">
            <legend>Contenu</legend>
            <div class="field">
                <label for="salutation">Formule d'appel</label>
                <input id="salutation" name="salutation" value="<?= $val('salutation') ?>"<?= $max('salutation') . $inv('salutation') ?>>
                <?= $err('salutation') ?>
            </div>
            <div class="field">
                <label for="body">Corps du courrier *</label>
                <!-- Éditeur de texte enrichi (assets/js/courrier-editor.js) ; sans JavaScript, ce champ reste un texte simple -->
                <div class="rte" data-rte-for="body" data-variables="<?= e(json_encode(array_map(fn ($v) => $v[0], courrier_variables([])), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" hidden></div>
                <textarea id="body" name="body" rows="16" required class="courrier-form__body"<?= $inv('body') ?>><?= e(courrier_body_html((string) ($form['body'] ?? ''))) ?></textarea>
                <small class="field__hint">Police Arial, texte justifié par défaut. Les variables entre crochets (ex. [Nom de l'entreprise]) sont remplacées à l'impression et dans le PDF.</small>
                <?= $err('body') ?>
            </div>
            <div class="field">
                <label for="closing">Formule de politesse</label>
                <textarea id="closing" name="closing" rows="3"<?= $max('closing') . $inv('closing') ?>><?= $val('closing') ?></textarea>
                <?= $err('closing') ?>
            </div>
        </fieldset>

        <!-- ==================== SIGNATURE ==================== -->
        <fieldset class="form-section">
            <legend>Signature</legend>
            <div class="form-grid">
                <div class="field">
                    <label for="signatory_name">Nom du signataire *</label>
                    <input id="signatory_name" name="signatory_name" required value="<?= $val('signatory_name') ?>"<?= $max('signatory_name') . $inv('signatory_name') ?>>
                    <?= $err('signatory_name') ?>
                </div>
                <div class="field">
                    <label for="signatory_title">Fonction du signataire</label>
                    <input id="signatory_title" name="signatory_title" value="<?= $val('signatory_title') ?>"<?= $max('signatory_title') . $inv('signatory_title') ?> placeholder="Ex. Directeur Général">
                    <?= $err('signatory_title') ?>
                </div>
            </div>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?> Enregistrer</button>
            <button type="submit" name="then" value="print" class="btn btn--ghost"><?= icon('printer') ?> Enregistrer et imprimer</button>
            <button type="submit" name="then" value="pdf" class="btn btn--ghost"><?= icon('download') ?> Enregistrer et télécharger le PDF</button>
        </div>
    </form>
    <script src="<?= e(asset_url('assets/js/courrier-editor.js', '../')) ?>" defer></script>
    <?php
    dash_footer();
    exit;
}

/* ==========================================================================
   Vue : historique
   ========================================================================== */

$q       = mb_substr(query('q'), 0, 100);
$status  = isset(COURRIER_STATUSES[query('status')]) ? query('status') : '';
$page    = max(1, (int) query('page', '1'));
$perPage = 20;

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(reference LIKE ? OR recipient_company LIKE ? OR recipient_name LIKE ? OR subject LIKE ? OR body LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
}
if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total    = (int) db_value("SELECT COUNT(*) FROM courriers {$whereSql}", $params);
$page     = min($page, max(1, (int) ceil($total / $perPage)));
$offset   = ($page - 1) * $perPage;
$list     = db_all("SELECT * FROM courriers {$whereSql} ORDER BY letter_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

dash_header('Courriers', 'courriers');
?>
<div class="toolbar">
    <form method="get" class="filters" role="search">
        <div class="search">
            <?= icon('search') ?>
            <label class="visually-hidden" for="q">Rechercher</label>
            <input id="q" name="q" type="search" placeholder="Référence, entreprise, objet…" value="<?= e($q) ?>">
        </div>
        <label class="visually-hidden" for="f-status">Statut</label>
        <select id="f-status" name="status" data-autosubmit>
            <option value="">Tous les statuts</option>
            <?php foreach (COURRIER_STATUSES as $key => $st): ?><option value="<?= e($key) ?>" <?= $key === $status ? 'selected' : '' ?>><?= e($st['label']) ?></option><?php endforeach; ?>
        </select>
        <?php if ($q !== '' || $status !== ''): ?><a href="courriers.php" class="btn btn--ghost btn--sm">Réinitialiser</a><?php endif; ?>
    </form>
    <div class="toolbar__actions">
        <a href="courriers.php?action=new" class="btn btn--primary"><?= icon('plus') ?> Nouveau courrier</a>
    </div>
</div>

<section class="panel">
    <?php if (!$list): ?>
        <p class="empty"><?= $where ? 'Aucun courrier ne correspond à ces critères.' : 'Aucun courrier pour le moment. <a href="courriers.php?action=new">Rédiger le premier courrier</a>' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--hover table--stack">
                <thead><tr><th>Référence</th><th>Date</th><th>Destinataire</th><th>Objet</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($list as $c): $cid = (int) $c['id']; ?>
                    <tr>
                        <td data-label="Référence"><a href="courriers.php?action=edit&amp;id=<?= $cid ?>" class="link-strong"><?= e($c['reference']) ?></a></td>
                        <td data-label="Date"><?= e(fdate($c['letter_date'])) ?></td>
                        <td data-label="Destinataire"><span class="cell-stack"><?= e(courrier_recipient_label($c)) ?>
                            <?php if ($c['recipient_city']): ?><small class="cell-sub"><?= e(implode(', ', array_filter([$c['recipient_city'], $c['recipient_country']]))) ?></small><?php endif; ?></span></td>
                        <td data-label="Objet"><span class="courrier-subject"><?= e($c['subject']) ?></span></td>
                        <td data-label="Statut">
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="op" value="status">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <label class="visually-hidden" for="st-<?= $cid ?>">Statut de <?= e($c['reference']) ?></label>
                                <select id="st-<?= $cid ?>" name="status" class="select-sm" data-autosubmit>
                                    <?php foreach (COURRIER_STATUSES as $key => $st): ?><option value="<?= e($key) ?>" <?= $key === $c['status'] ? 'selected' : '' ?>><?= e($st['label']) ?></option><?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="actions">
                            <a href="print-courrier.php?id=<?= $cid ?>" class="icon-btn" title="Imprimer" target="_blank" rel="noopener"><?= icon('printer') ?><span class="visually-hidden">Imprimer</span></a>
                            <a href="pdf.php?type=courrier&amp;id=<?= $cid ?>" class="icon-btn" title="Télécharger le PDF"><?= icon('download') ?><span class="visually-hidden">PDF</span></a>
                            <a href="courriers.php?action=edit&amp;id=<?= $cid ?>" class="icon-btn" title="Modifier"><?= icon('edit') ?><span class="visually-hidden">Modifier</span></a>
                            <a href="courriers.php?action=new&amp;from=<?= $cid ?>" class="icon-btn" title="Dupliquer (nouveau courrier à partir de celui-ci)"><?= icon('row-add') ?><span class="visually-hidden">Dupliquer</span></a>
                            <?php if (is_admin()): ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="delete">
                                    <input type="hidden" name="id" value="<?= $cid ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Supprimer" data-confirm="Supprimer définitivement le courrier <?= e($c['reference']) ?> ?"><?= icon('trash') ?><span class="visually-hidden">Supprimer</span></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination($total, $page, $perPage, array_filter(['q' => $q, 'status' => $status])) ?>
    <?php endif; ?>
</section>

<?php dash_footer(); ?>
