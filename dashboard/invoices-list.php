<?php
/**
 * Factures commerciales (facture, facture proforma, devis) — style tableur : lignes d'articles,
 * quantités, prix unitaires, totaux HT / TVA / TTC et montant en lettres.
 *
 *   invoices-list.php                 Liste (recherche, filtres type / statut, pagination)
 *   invoices-list.php?action=new      Nouvelle facture
 *   invoices-list.php?action=edit&id  Modification
 *   print-commercial-invoice.php?id   Impression A4 avec l'en-tête officiel
 *
 * Distinct des « Reçus de caisse » (dashboard/invoices.php). Calculs refaits côté serveur
 * (includes/commercial_invoices.php) ; assets/js/commercial-invoice.js n'assure que l'aperçu en direct.
 * Accès : comptes des opérations ; suppression réservée aux administrateurs.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/commercial_invoices.php';

$user = require_login();
require_roles(OPERATION_ROLES);

$action = query('action', 'list');
$id     = (int) query('id');
$form   = null;
$calc   = null;
$errors = [];

/* ==========================================================================
   Actions (POST + CSRF)
   ========================================================================== */

if (is_post()) {
    csrf_verify();
    $op = post('op');

    if ($op === 'save') {
        $editId = (int) post('id') ?: null;
        if ($editId !== null && !db_value('SELECT 1 FROM commercial_invoices WHERE id = ?', [$editId])) {
            flash('error', 'Facture introuvable (supprimée entre-temps ?).');
            redirect('invoices-list.php');
        }
        [$form, $calc, $errors] = cinv_input();
        if (!$errors) {
            $savedId = cinv_save($form, $calc, $user, $editId);
            $number  = db_value('SELECT invoice_number FROM commercial_invoices WHERE id = ?', [$savedId]);
            flash('success', CINV_TYPES[$form['doc_type']]['label'] . " {$number} " . ($editId ? 'modifiée.' : 'enregistrée.'));
            redirect(post('then') === 'print' ? "print-commercial-invoice.php?id={$savedId}&autoprint=1" : 'invoices-list.php');
        }
        $action = $editId ? 'edit' : 'new';
        $id     = (int) $editId;
    }

    if ($op === 'status') {
        $status = post('status');
        if (isset(CINV_STATUSES[$status])) {
            db_query('UPDATE commercial_invoices SET status = ?, updated_at = ? WHERE id = ?', [$status, db_now(), (int) post('id')]);
            flash('success', 'Statut mis à jour : ' . CINV_STATUSES[$status]['label'] . '.');
        }
        redirect('invoices-list.php');
    }

    if ($op === 'delete') {
        require_role('admin');
        $number = db_value('SELECT invoice_number FROM commercial_invoices WHERE id = ?', [(int) post('id')]);
        if ($number) {
            cinv_delete((int) post('id'));
            flash('success', "Document {$number} supprimé.");
        }
        redirect('invoices-list.php');
    }
}

/* ==========================================================================
   Vue : saisie / modification
   ========================================================================== */

if ($action === 'new' || $action === 'edit') {
    $existing = $action === 'edit' ? cinv_find($id) : null;
    if ($action === 'edit' && !$existing) {
        flash('error', 'Facture introuvable.');
        redirect('invoices-list.php');
    }
    if ($form === null) {
        $form = $existing ?? [
            'doc_type' => in_array(query('type'), array_keys(CINV_TYPES), true) ? query('type') : 'invoice', 'status' => 'issued',
            'client_name' => '', 'client_phone' => '', 'client_email' => '', 'client_address' => '', 'client_niu' => '',
            'issue_date' => date('Y-m-d'), 'due_date' => '', 'currency' => 'XAF', 'tax_rate' => 0, 'notes' => '',
        ];
        $items   = $existing['items'] ?? [];
        $columns = $existing['columns'] ?? CINV_DEFAULT_COLUMNS;
    } else {
        $items   = $calc['items'];
        $columns = $calc['columns'];
    }
    $currency = $form['currency'];
    $dec      = DASH_CURRENCIES[$currency]['decimals'] ?? 0;
    // Champ numérique pré-rempli : nombre brut avec virgule (1250000 / 12,5)
    // (zéros retirés seulement après la virgule : 3500000 reste 3500000, 12.50 devient 12,5)
    $num = function ($v, int $d): string {
        if ($v === '' || $v === null) {
            return '';
        }
        $s = number_format((float) $v, $d, '.', '');
        return str_replace('.', ',', str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s);
    };
    // 5 lignes au minimum (saisie possible sans JavaScript ; le script n'en garde qu'une vide en bas)
    $rows = array_pad($items, max(5, count($items) + 1), ['amount' => null]);
    $nextKey = 1 + max(array_map(fn ($c) => $c['key'] === 'amount' ? 0 : (int) substr($c['key'], 1), $columns));
    $err  = fn (string $k) => isset($errors[$k]) && is_string($errors[$k]) ? '<p class="field__error">' . e($errors[$k]) . '</p>' : '';
    $inv  = fn (string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';

    dash_header($existing ? 'Modifier ' . $existing['invoice_number'] : 'Nouvelle facture', 'commercial');
    ?>
    <div class="toolbar">
        <a href="invoices-list.php" class="btn btn--ghost"><?= icon('arrow-left') ?> Toutes les factures</a>
        <?php if ($existing): ?>
            <a href="print-commercial-invoice.php?id=<?= (int) $existing['id'] ?>" class="btn btn--ghost" target="_blank" rel="noopener"><?= icon('printer') ?> Aperçu / impression</a>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>Merci de corriger les champs indiqués<?= isset($errors['items']) ? ' : ' . e(implode(' ', $errors['items'])) : '.' ?></p></div>
    <?php endif; ?>

    <form method="post" class="panel form cinv-form" id="cinv-form" novalidate
          data-currency-words="<?= e(json_encode(CINV_CURRENCY_WORDS, JSON_UNESCAPED_UNICODE)) ?>"
          data-decimals="<?= e(json_encode(array_map(fn ($c) => $c['decimals'], DASH_CURRENCIES))) ?>"
          data-max-lines="<?= CINV_MAX_LINES ?>"
          data-max-columns="<?= CINV_MAX_TEXT_COLUMNS ?>"
          data-next-key="<?= (int) $nextKey ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <?php if ($existing): ?><input type="hidden" name="id" value="<?= (int) $existing['id'] ?>"><?php endif; ?>

        <header class="panel__head">
            <h2 class="panel__title"><?= icon('file') ?> <?= $existing ? e(CINV_TYPES[$existing['doc_type']]['label'] . ' ' . $existing['invoice_number']) : 'Nouvelle facture' ?></h2>
            <span class="cell-sub"><?= $existing ? 'Créée par ' . e($existing['created_by_name'] ?? '—') . ' le ' . e(fdate($existing['created_at'], true)) : 'N° attribué automatiquement à l\'enregistrement' ?></span>
        </header>

        <fieldset class="form-section">
            <legend>Document</legend>
            <div class="form-grid">
                <div class="field">
                    <label for="doc_type">Type</label>
                    <select id="doc_type" name="doc_type"<?= $inv('doc_type') ?>>
                        <?php foreach (CINV_TYPES as $key => $t): ?>
                            <option value="<?= e($key) ?>" <?= $key === $form['doc_type'] ? 'selected' : '' ?>><?= e($t['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('doc_type') ?>
                </div>
                <div class="field">
                    <label for="issue_date">Date d'émission *</label>
                    <input id="issue_date" name="issue_date" type="date" required value="<?= e($form['issue_date']) ?>"<?= $inv('issue_date') ?>>
                    <?= $err('issue_date') ?>
                </div>
                <div class="field">
                    <label for="due_date">Échéance <small>(facultatif)</small></label>
                    <input id="due_date" name="due_date" type="date" value="<?= e((string) $form['due_date']) ?>"<?= $inv('due_date') ?>>
                    <?= $err('due_date') ?>
                </div>
                <div class="field">
                    <label for="currency">Devise</label>
                    <select id="currency" name="currency" data-cinv-currency<?= $inv('currency') ?>>
                        <?php foreach (DASH_CURRENCIES as $code => $c): ?>
                            <option value="<?= e($code) ?>" <?= $code === $currency ? 'selected' : '' ?>><?= e(currency_label($code)) ?> — <?= e($c['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($existing): ?>
                    <div class="field">
                        <label for="status">Statut</label>
                        <select id="status" name="status">
                            <?php foreach (CINV_STATUSES as $key => $st): ?>
                                <option value="<?= e($key) ?>" <?= $key === $form['status'] ? 'selected' : '' ?>><?= e($st['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="status" value="issued">
                <?php endif; ?>
            </div>
        </fieldset>

        <fieldset class="form-section">
            <legend>Client</legend>
            <div class="form-grid">
                <div class="field">
                    <label for="client_name">Nom / Raison sociale *</label>
                    <input id="client_name" name="client_name" required maxlength="150" autocomplete="off" value="<?= e($form['client_name']) ?>"<?= $inv('client_name') ?>>
                    <?= $err('client_name') ?>
                </div>
                <div class="field">
                    <label for="client_phone">Téléphone</label>
                    <input id="client_phone" name="client_phone" type="tel" maxlength="50" placeholder="+237 6XX XX XX XX" value="<?= e((string) $form['client_phone']) ?>"<?= $inv('client_phone') ?>>
                    <?= $err('client_phone') ?>
                </div>
                <div class="field">
                    <label for="client_email">E-mail</label>
                    <input id="client_email" name="client_email" type="email" maxlength="150" value="<?= e((string) $form['client_email']) ?>"<?= $inv('client_email') ?>>
                    <?= $err('client_email') ?>
                </div>
                <div class="field">
                    <label for="client_niu">NIU du client <small>(entreprise)</small></label>
                    <input id="client_niu" name="client_niu" maxlength="30" style="text-transform: uppercase" value="<?= e((string) $form['client_niu']) ?>"<?= $inv('client_niu') ?>>
                    <?= $err('client_niu') ?>
                </div>
                <div class="field field--full">
                    <label for="client_address">Adresse</label>
                    <input id="client_address" name="client_address" maxlength="255" placeholder="Quartier, ville, BP…" value="<?= e((string) $form['client_address']) ?>"<?= $inv('client_address') ?>>
                    <?= $err('client_address') ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="form-section">
            <legend>Tableau de la facture</legend>

            <!-- Mini-tableur : cellules modifiables directement, barre d'outils (assets/js/commercial-invoice.js) -->
            <div class="xl" data-cinv-xl>
                <div class="xl-toolbar" role="toolbar" aria-label="Outils du tableau">
                    <div class="xl-namebox" data-cinv-namebox aria-live="polite" title="Cellule active">—</div>
                    <div class="xl-group" role="group" aria-label="Lignes et colonnes">
                        <button type="button" class="xl-btn" data-xl="row-add" title="Ajouter une ligne sous la cellule active"><?= icon('row-add') ?><span>Ligne</span></button>
                        <button type="button" class="xl-btn" data-xl="row-del" title="Supprimer la ligne active" disabled><?= icon('row-del') ?><span>Supprimer la ligne</span></button>
                        <button type="button" class="xl-btn" data-xl="col-add" title="Ajouter une colonne à droite de la colonne active"><?= icon('col-add') ?><span>Colonne</span></button>
                        <button type="button" class="xl-btn" data-xl="col-del" title="Supprimer la colonne active" disabled><?= icon('col-del') ?><span>Supprimer la colonne</span></button>
                    </div>
                    <div class="xl-group" role="group" aria-label="Alignement">
                        <button type="button" class="xl-btn xl-btn--icon" data-xl-align="left" title="Aligner à gauche" aria-pressed="false" disabled><?= icon('align-left') ?><span class="visually-hidden">Aligner à gauche</span></button>
                        <button type="button" class="xl-btn xl-btn--icon" data-xl-align="center" title="Centrer" aria-pressed="false" disabled><?= icon('align-center') ?><span class="visually-hidden">Centrer</span></button>
                        <button type="button" class="xl-btn xl-btn--icon" data-xl-align="right" title="Aligner à droite" aria-pressed="false" disabled><?= icon('align-right') ?><span class="visually-hidden">Aligner à droite</span></button>
                        <span class="xl-scope" role="radiogroup" aria-label="Appliquer l'alignement à">
                            <button type="button" class="xl-scope__btn is-active" data-xl-scope="cell" role="radio" aria-checked="true">Cellule</button>
                            <button type="button" class="xl-scope__btn" data-xl-scope="column" role="radio" aria-checked="false">Colonne</button>
                        </span>
                    </div>
                </div>

                <div class="sheet-wrap">
                    <table class="sheet sheet--xl" data-cinv-sheet>
                        <thead>
                            <tr data-cinv-head>
                                <th class="sheet__n" scope="col">N°</th>
                                <?php foreach ($columns as $col): $isAmount = $col['type'] === 'amount'; ?>
                                    <th class="sheet__col<?= $isAmount ? ' sheet__col--amount' : '' ?> is-<?= e($col['align']) ?>" scope="col"
                                        data-col="<?= e($col['key']) ?>" data-align="<?= e($col['align']) ?>">
                                        <div class="sheet__head">
                                            <?php if ($isAmount): ?><span class="sheet__sigma" title="Colonne additionnée dans le total">Σ</span><?php endif; ?>
                                            <div class="xl-cell xl-cell--head" contenteditable="true" spellcheck="false" role="textbox"
                                                 aria-label="Titre de la colonne" data-cinv-label data-placeholder="<?= $isAmount ? 'Montant' : 'Titre de colonne' ?>"><?= e($col['label']) ?></div>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody data-cinv-rows>
                        <?php foreach ($rows as $i => $row): ?>
                            <tr data-cinv-row>
                                <td class="sheet__n" data-cinv-n><?= $i + 1 ?></td>
                                <?php foreach ($columns as $col):
                                    $align = cinv_cell_align($row, $col);
                                    $own   = isset($row['_align'][$col['key']]) ? ' data-align="' . e($align) . '"' : '';
                                    $value = $col['type'] === 'amount' ? $num($row['amount'] ?? null, $dec) : (string) ($row[$col['key']] ?? ''); ?>
                                    <td class="is-<?= e($align) ?><?= $col['type'] === 'amount' ? ' sheet__amount' : '' ?>" data-col="<?= e($col['key']) ?>"<?= $own ?>>
                                        <div class="xl-cell" contenteditable="true" role="textbox" data-cinv-cell<?= $col['type'] === 'amount' ? ' inputmode="decimal" data-cinv-amount' : ' aria-multiline="true"' ?>><?= e($value) ?></div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Contenu du tableau transmis au serveur à l'enregistrement (col_key[], col_label[], col_align[], cell[clé][], cell_align[clé][]) -->
                <div data-cinv-payload hidden></div>
                <noscript><p class="field__error">Le tableau de la facture nécessite JavaScript (activez-le dans votre navigateur).</p></noscript>
            </div>
            <small class="field__hint xl-hint">Cliquez dans une cellule pour écrire · Entrée : cellule du dessous · Maj + Entrée : retour à la ligne · ↑ ↓ : changer de ligne · une ligne vide est ajoutée automatiquement en bas · seule la colonne Σ est additionnée · ligne sans montant : titre ou commentaire · montant négatif : remise.</small>
        </fieldset>

        <div class="cinv-bottom">
            <div class="field cinv-notes">
                <label for="notes">Notes / conditions <small>(imprimées sur la facture)</small></label>
                <textarea id="notes" name="notes" rows="4" maxlength="2000" placeholder="Ex. Paiement à 30 jours par virement · Validité du devis : 15 jours"<?= $inv('notes') ?>><?= e((string) $form['notes']) ?></textarea>
                <?= $err('notes') ?>
            </div>
            <div class="cinv-totals">
                <dl>
                    <div><dt>Total HT</dt><dd data-cinv-subtotal><?= $calc ? e(money($calc['subtotal'], $currency)) : (isset($existing) ? e(money((float) $existing['subtotal'], $currency)) : '—') ?></dd></div>
                    <div class="cinv-totals__tax">
                        <dt>
                            <label for="tax_rate">TVA</label>
                            <span class="cinv-tax">
                                <input id="tax_rate" name="tax_rate" inputmode="decimal" value="<?= e($num($form['tax_rate'], 2) ?: '0') ?>" data-cinv-tax<?= $inv('tax_rate') ?>> %
                                <button type="button" class="chip" data-cinv-tax-preset="19,25">19,25 %</button>
                                <button type="button" class="chip" data-cinv-tax-preset="0">0 %</button>
                            </span>
                        </dt>
                        <dd data-cinv-taxamount><?= $calc ? e(money($calc['tax_amount'], $currency)) : (isset($existing) ? e(money((float) $existing['tax_amount'], $currency)) : '—') ?></dd>
                    </div>
                    <div class="cinv-totals__grand"><dt>Total TTC</dt><dd data-cinv-total><?= $calc ? e(money($calc['total'], $currency)) : (isset($existing) ? e(money((float) $existing['total'], $currency)) : '—') ?></dd></div>
                </dl>
                <p class="cinv-words" data-cinv-words><?= $calc ? e(amount_in_words($calc['total'], $currency)) : (isset($existing) ? e(amount_in_words((float) $existing['total'], $currency)) : '') ?></p>
                <?= $err('tax_rate') ?>
            </div>
        </div>

        <div class="form-actions cinv-actions">
            <button type="submit" class="btn btn--ghost" name="then" value="list"><?= icon('save') ?> Enregistrer</button>
            <button type="submit" class="btn btn--primary btn--lg" name="then" value="print"><?= icon('printer') ?> Enregistrer &amp; imprimer</button>
        </div>
    </form>
    <script src="<?= e(asset_url('assets/js/commercial-invoice.js', '../')) ?>" defer></script>
    <?php
    dash_footer();
    exit;
}

/* ==========================================================================
   Vue : liste
   ========================================================================== */

$q       = mb_substr(query('q'), 0, 100);
$type    = isset(CINV_TYPES[query('type')]) ? query('type') : '';
$status  = isset(CINV_STATUSES[query('status')]) ? query('status') : '';
$page    = max(1, (int) query('page', '1'));
$perPage = 25;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(invoice_number LIKE ? OR client_name LIKE ? OR client_phone LIKE ? OR client_email LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
}
if ($type !== '') {
    $where[] = 'doc_type = ?';
    $params[] = $type;
}
if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total    = (int) db_value("SELECT COUNT(*) FROM commercial_invoices {$whereSql}", $params);
$page     = min($page, max(1, (int) ceil($total / $perPage)));
$offset   = ($page - 1) * $perPage;
$list     = db_all("SELECT * FROM commercial_invoices {$whereSql} ORDER BY issue_date DESC, id DESC LIMIT {$perPage} OFFSET {$offset}", $params);
$month    = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS amount FROM commercial_invoices
                    WHERE doc_type = 'invoice' AND status <> 'cancelled' AND currency = 'XAF' AND issue_date >= ?", [date('Y-m-01')]);
$unpaid   = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS amount FROM commercial_invoices
                    WHERE doc_type = 'invoice' AND status = 'issued' AND currency = 'XAF'");

dash_header('Factures', 'commercial');
?>
<section class="stats stats--summary cinv-stats" aria-label="Synthèse des factures">
    <article class="stat">
        <span class="stat__icon stat__icon--blue"><?= icon("file") ?></span>
        <div>
            <p class="stat__label">Facturé ce mois (FCFA)</p>
            <p class="stat__value"><?= e(money((float) $month["amount"], "XAF")) ?></p>
            <p class="stat__note"><?= (int) $month["n"] ?> facture<?= (int) $month["n"] > 1 ? "s" : "" ?> hors annulées</p>
        </div>
    </article>
    <article class="stat">
        <span class="stat__icon stat__icon--gold"><?= icon("clock") ?></span>
        <div>
            <p class="stat__label">En attente de paiement (FCFA)</p>
            <p class="stat__value"><?= e(money((float) $unpaid["amount"], "XAF")) ?></p>
            <p class="stat__note"><?= (int) $unpaid["n"] ?> facture<?= (int) $unpaid["n"] > 1 ? "s" : "" ?> émise<?= (int) $unpaid["n"] > 1 ? "s" : "" ?>, non payée<?= (int) $unpaid["n"] > 1 ? "s" : "" ?></p>
        </div>
    </article>
</section>

<div class="toolbar">
    <form method="get" class="filters" role="search">
        <div class="search">
            <?= icon('search') ?>
            <label class="visually-hidden" for="q">Rechercher</label>
            <input id="q" name="q" type="search" placeholder="N°, client, téléphone…" value="<?= e($q) ?>">
        </div>
        <label class="visually-hidden" for="f-type">Type</label>
        <select id="f-type" name="type" data-autosubmit>
            <option value="">Tous les documents</option>
            <?php foreach (CINV_TYPES as $key => $t): ?><option value="<?= e($key) ?>" <?= $key === $type ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="f-status">Statut</label>
        <select id="f-status" name="status" data-autosubmit>
            <option value="">Tous les statuts</option>
            <?php foreach (CINV_STATUSES as $key => $st): ?><option value="<?= e($key) ?>" <?= $key === $status ? 'selected' : '' ?>><?= e($st['label']) ?></option><?php endforeach; ?>
        </select>
        <?php if ($q !== '' || $type !== '' || $status !== ''): ?><a href="invoices-list.php" class="btn btn--ghost btn--sm">Réinitialiser</a><?php endif; ?>
    </form>
    <div class="toolbar__actions">
        <a href="invoices-list.php?action=new&amp;type=quote" class="btn btn--ghost"><?= icon('plus') ?> Devis</a>
        <a href="invoices-list.php?action=new&amp;type=proforma" class="btn btn--ghost"><?= icon('plus') ?> Proforma</a>
        <a href="invoices-list.php?action=new" class="btn btn--primary"><?= icon('plus') ?> Nouvelle facture</a>
    </div>
</div>

<section class="panel">
    <?php if (!$list): ?>
        <p class="empty"><?= $where ? 'Aucun document ne correspond à ces critères.' : 'Aucune facture pour le moment. <a href="invoices-list.php?action=new">Créer la première facture</a>' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--hover table--stack">
                <thead><tr><th>N°</th><th>Date</th><th>Client</th><th class="num">Total TTC</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($list as $c): $cid = (int) $c['id']; ?>
                    <tr>
                        <td data-label="N°"><span class="cell-stack">
                            <a href="invoices-list.php?action=edit&amp;id=<?= $cid ?>" class="link-strong"><?= e($c['invoice_number']) ?></a>
                            <small class="cell-sub"><?= e(CINV_TYPES[$c['doc_type']]['label'] ?? $c['doc_type']) ?></small>
                        </span></td>
                        <td data-label="Date"><span class="cell-stack"><?= e(fdate($c['issue_date'])) ?>
                            <?php if ($c['due_date']): ?><small class="cell-sub">Échéance <?= e(fdate($c['due_date'])) ?></small><?php endif; ?></span></td>
                        <td data-label="Client"><span class="cell-stack"><?= e($c['client_name']) ?>
                            <?php if ($c['client_phone']): ?><small class="cell-sub"><?= e($c['client_phone']) ?></small><?php endif; ?></span></td>
                        <td data-label="Total TTC" class="num"><strong><?= e(money((float) $c['total'], $c['currency'])) ?></strong></td>
                        <td data-label="Statut">
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="op" value="status">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <label class="visually-hidden" for="st-<?= $cid ?>">Statut de <?= e($c['invoice_number']) ?></label>
                                <select id="st-<?= $cid ?>" name="status" class="select-sm" data-autosubmit>
                                    <?php foreach (CINV_STATUSES as $key => $st): ?><option value="<?= e($key) ?>" <?= $key === $c['status'] ? 'selected' : '' ?>><?= e($st['label']) ?></option><?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="actions">
                            <a href="print-commercial-invoice.php?id=<?= $cid ?>" class="icon-btn" title="Imprimer" target="_blank" rel="noopener"><?= icon('printer') ?><span class="visually-hidden">Imprimer</span></a>
                            <a href="invoices-list.php?action=edit&amp;id=<?= $cid ?>" class="icon-btn" title="Modifier"><?= icon('edit') ?><span class="visually-hidden">Modifier</span></a>
                            <?php if (is_admin()): ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="delete">
                                    <input type="hidden" name="id" value="<?= $cid ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Supprimer" data-confirm="Supprimer définitivement <?= e($c['invoice_number']) ?> ?"><?= icon('trash') ?><span class="visually-hidden">Supprimer</span></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination($total, $page, $perPage, array_filter(['q' => $q, 'type' => $type, 'status' => $status])) ?>
    <?php endif; ?>
</section>

<?php dash_footer(); ?>
