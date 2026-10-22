<?php
/**
 * Factures / bordereaux de transaction.
 *
 *   (défaut)      Saisie rapide + 10 dernières transactions
 *   ?action=list  Tous les reçus (recherche, filtres, pagination)
 *   ?action=view  Détail d'une facture
 *
 * Montant à recevoir = (montant versé − commission) × taux (taux « 1 source = X cible »)
 *                   ou (montant versé − commission) ÷ taux (taux « 1 cible = X source »).
 * La commission est exprimée dans la devise source.
 * Pré-remplissage possible depuis le simulateur (dashboard/simulator.php) via l'URL.
 * Calcul affiché en direct (dashboard.js) et TOUJOURS recalculé côté serveur.
 * « Imprimante de sortie » : enregistrée avec la facture puis transmise à print-invoice.php?id=X&printer_id=Y.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/printers.php';

$user   = require_login();   // exige une session valide ($_SESSION['logged_in'] === true)
require_roles(OPERATION_ROLES);   // pas d'accès pour le rôle Secrétaire / Accueil
$action = query('action', 'new');
$id     = (int) query('id');

/* ==========================================================================
   Fonctions locales
   ========================================================================== */

/** Numéro unique du jour : GTC-20261015-0001 */
function next_invoice_number(): string
{
    $prefix = 'GTC-' . date('Ymd') . '-';
    $last   = db_value('SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1', [$prefix . '%']);
    $seq    = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

/** Nombre saisi à la française (« 1 250 000,50 ») → chaîne numérique. */
function parse_number(string $value): string
{
    return str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], trim($value));
}

/** Valide la saisie. Retourne [données, erreurs]. */
function invoice_input(): array
{
    $d = [
        'client_name'     => post('client_name'),
        'client_phone'    => post('client_phone'),
        'operation_type'  => post('operation_type'),
        'amount_given'    => parse_number(post('amount_given')),
        'source_currency' => post('source_currency', 'EUR'),
        'target_currency' => post('target_currency', 'XAF'),
        'exchange_rate'   => parse_number(post('exchange_rate')),
        'rate_direction'  => post('rate_direction', 'multiply'),
        'fees'            => parse_number(post('fees', '0')) ?: '0',
        'payment_method'  => post('payment_method', 'Espèces'),
        'transaction_reason' => post('transaction_reason'),
        'printer_id'      => (int) post('printer_id'),
    ];
    $e = [];

    if (mb_strlen($d['client_name']) < 2 || mb_strlen($d['client_name']) > 150) {
        $e['client_name'] = 'Indiquez le nom et prénom du client.';
    }
    if ($d['client_phone'] !== '' && !preg_match('/^[0-9+().\s-]{6,50}$/', $d['client_phone'])) {
        $e['client_phone'] = 'Numéro de téléphone invalide.';
    }
    if (!isset(OPERATION_TYPES[$d['operation_type']])) {
        $e['operation_type'] = "Choisissez le type d'opération.";
    }
    if (!is_numeric($d['amount_given']) || (float) $d['amount_given'] <= 0 || (float) $d['amount_given'] > 1e13) {
        $e['amount_given'] = 'Montant invalide.';
    }
    if (!isset(DASH_CURRENCIES[$d['source_currency']])) {
        $e['source_currency'] = 'Devise source invalide.';
    }
    if (!isset(DASH_CURRENCIES[$d['target_currency']])) {
        $e['target_currency'] = 'Devise cible invalide.';
    }
    if (!is_numeric($d['exchange_rate']) || (float) $d['exchange_rate'] <= 0 || (float) $d['exchange_rate'] > 1e9) {
        $e['exchange_rate'] = 'Taux invalide.';
    }
    if (!in_array($d['rate_direction'], RATE_DIRECTIONS, true)) {
        $e['rate_direction'] = 'Sens du taux invalide.';
    }
    if (!isset(PAYMENT_METHODS[$d['payment_method']])) {
        $e['payment_method'] = 'Mode de paiement invalide.';
    }
    if (mb_strlen($d['transaction_reason']) > 255) {
        $e['transaction_reason'] = 'Motif trop long (255 caractères au plus).';
    }
    // Imprimante de sortie : obligatoire dès qu'au moins une imprimante active existe
    if ($d['printer_id'] ? !printer_find($d['printer_id']) : (bool) printer_default()) {
        $e['printer_id'] = "Choisissez l'imprimante de sortie.";
    }
    if (!is_numeric($d['fees']) || (float) $d['fees'] < 0) {
        $e['fees'] = 'Commission invalide.';
    } elseif (is_numeric($d['amount_given']) && (float) $d['fees'] >= (float) $d['amount_given']) {
        $e['fees'] = 'La commission doit être inférieure au montant versé.';
    }

    $d['amount_received'] = null;
    if (!$e) {
        $d['exchange_rate']   = round((float) $d['exchange_rate'], 4);
        $d['amount_given']    = round((float) $d['amount_given'], 2);
        $d['fees']            = round((float) $d['fees'], 2);
        $d['amount_received'] = compute_received($d['amount_given'] - $d['fees'], $d['exchange_rate'], $d['rate_direction'], $d['target_currency']);
        if ($d['amount_received'] <= 0) {
            $e['exchange_rate'] = 'Le montant à recevoir est nul : vérifiez le taux et son sens.';
        }
    }
    $d['client_phone'] = $d['client_phone'] === '' ? null : $d['client_phone'];
    $d['transaction_reason'] = $d['transaction_reason'] === '' ? null : $d['transaction_reason'];
    $d['sender_name'] = null;   // champ retiré : le motif peut contenir le nom de l'expéditeur (colonne gardée pour l'historique)
    return [$d, $e];
}

/* ==========================================================================
   Actions POST
   ========================================================================== */

$errors = [];
$form   = null;

if (is_post()) {
    csrf_verify();
    $op = post('op');

    if ($op === 'save') {
        [$form, $errors] = invoice_input();

        if (!$errors) {
            // Création (nouvelle tentative si deux factures sont enregistrées au même instant)
            for ($try = 0; $try < 3; $try++) {
                try {
                    $number = next_invoice_number();
                    db_query(
                        'INSERT INTO invoices (invoice_number, client_name, client_phone, operation_type, source_currency,
                         target_currency, amount_given, exchange_rate, rate_direction, amount_received, fees, payment_method,
                         transaction_reason, sender_name, printer_id, status, issue_date, agent_id, agent_name, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$number, $form['client_name'], $form['client_phone'], $form['operation_type'], $form['source_currency'],
                         $form['target_currency'], $form['amount_given'], $form['exchange_rate'], $form['rate_direction'],
                         $form['amount_received'], $form['fees'], $form['payment_method'], $form['transaction_reason'], $form['sender_name'],
                         $form['printer_id'] ?: null, 'paid', date('Y-m-d'),
                         (int) $_SESSION['user_id'], (string) $_SESSION['user_name'], db_now()]
                    );
                    $newId = (int) db()->lastInsertId();
                    flash('success', "Reçu {$number} enregistré.");
                    redirect('print-invoice.php?' . http_build_query(array_filter(['id' => $newId, 'printer_id' => $form['printer_id'], 'autoprint' => 1])));
                } catch (PDOException $ex) {
                    if ($try === 2) {
                        throw $ex;
                    }
                }
            }
        }
        $action = 'new';
    }

    if ($op === 'status') {
        $status = post('status');
        if (isset(INVOICE_STATUSES[$status])) {
            db_query('UPDATE invoices SET status = ?, updated_at = ? WHERE id = ?', [$status, db_now(), (int) post('id')]);
            flash('success', 'Statut mis à jour : ' . INVOICE_STATUSES[$status]['label'] . '.');
        }
        redirect('invoices.php?action=view&id=' . (int) post('id'));
    }

    if ($op === 'delete') {
        require_role('admin');
        db_query('DELETE FROM invoices WHERE id = ?', [(int) post('id')]);
        flash('success', 'Reçu supprimé.');
        redirect('invoices.php?action=list');
    }
}

/** Ligne de tableau (liste récente et liste complète). */
function invoice_row(array $inv): string
{
    ob_start(); ?>
    <tr>
        <td data-label="N° reçu">
            <a href="invoices.php?action=view&amp;id=<?= (int) $inv['id'] ?>" class="link-strong"><?= e($inv['invoice_number']) ?></a>
        </td>
        <td data-label="Date"><?= e(fdate($inv['created_at'] ?: $inv['issue_date'], true)) ?></td>
        <td data-label="Client"><span class="cell-stack">
            <?= e($inv['client_name']) ?>
            <?php if ($inv['client_phone']): ?><small class="cell-sub"><?= e($inv['client_phone']) ?></small><?php endif; ?>
        </span></td>
        <td data-label="Opération"><?= e(OPERATION_TYPES[$inv['operation_type']] ?? $inv['operation_type']) ?></td>
        <td data-label="Montants" class="num"><span class="cell-stack">
            <?= e(money($inv['amount_given'], $inv['source_currency'])) ?>
            <small class="cell-sub">→ <?= e(money($inv['amount_received'], $inv['target_currency'])) ?></small>
        </span></td>
        <td data-label="Agent"><?= e($inv['agent_name']) ?></td>
        <td class="actions">
            <a href="print-invoice.php?id=<?= (int) $inv['id'] ?>" class="btn btn--ghost btn--sm" target="_blank" rel="noopener"><?= icon('printer') ?> Imprimer</a>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

const INVOICE_TABLE_HEAD = '<thead><tr><th>N° reçu</th><th>Date</th><th>Client</th><th>Opération</th><th class="num">Montants</th><th>Agent</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>';

/* ==========================================================================
   Vue : détail
   ========================================================================== */

if ($action === 'view') {
    $inv = db_one('SELECT * FROM invoices WHERE id = ?', [$id]);
    if (!$inv) {
        flash('error', 'Reçu introuvable.');
        redirect('invoices.php');
    }

    dash_header('Reçu de caisse ' . $inv['invoice_number'], 'invoices');
    ?>
    <div class="toolbar">
        <a href="invoices.php?action=list" class="btn btn--ghost"><?= icon('arrow-left') ?> Tous les reçus</a>
        <div class="toolbar__actions">
            <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="status">
                <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                <label class="visually-hidden" for="status-change">Changer le statut</label>
                <select name="status" id="status-change" data-autosubmit>
                    <?php foreach (INVOICE_STATUSES as $key => $s): ?>
                        <option value="<?= e($key) ?>" <?= $key === $inv['status'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="print-invoice.php?id=<?= (int) $inv['id'] ?>&amp;autoprint=1" class="btn btn--primary" target="_blank" rel="noopener"><?= icon('printer') ?> Imprimer</a>
        </div>
    </div>

    <section class="panel">
        <div class="detail-head">
            <div>
                <p class="detail-head__label"><?= e(OPERATION_TYPES[$inv['operation_type']] ?? $inv['operation_type']) ?></p>
                <h2 class="detail-head__number"><?= e($inv['invoice_number']) ?></h2>
                <p class="cell-sub"><?= e(fdate($inv['created_at'] ?: $inv['issue_date'], true)) ?> · Agent : <?= e($inv['agent_name']) ?></p>
            </div>
            <?= badge($inv['status'], INVOICE_STATUSES) ?>
        </div>

        <div class="detail-grid">
            <dl class="detail-list">
                <div><dt>Client</dt><dd><?= e($inv['client_name']) ?></dd></div>
                <div><dt>Téléphone / WhatsApp</dt><dd><?= e($inv['client_phone'] ?? '—') ?></dd></div>
                <div><dt>Mode de paiement</dt><dd><?= e(PAYMENT_METHODS[$inv['payment_method']] ?? $inv['payment_method']) ?></dd></div>
                <div><dt>Motif</dt><dd><?= e(invoice_reason_text($inv)) ?></dd></div>
                <?php if ($inv['printer_id'] && ($prn = printer_find((int) $inv['printer_id'], false))): ?><div><dt>Imprimante</dt><dd><?= e($prn['printer_name']) ?></dd></div><?php endif; ?>
            </dl>
            <dl class="detail-list">
                <div><dt>Montant versé</dt><dd><?= e(money($inv['amount_given'], $inv['source_currency'])) ?></dd></div>
                <div><dt>Taux appliqué</dt><dd><?= e(rate_text($inv)) ?></dd></div>
                <?php if ((float) $inv['fees'] > 0): ?>
                    <div><dt>Commission</dt><dd><?= e(money($inv['fees'], $inv['source_currency'])) ?></dd></div>
                <?php endif; ?>
                <div class="detail-list__total"><dt>Montant à recevoir</dt><dd><?= e(money($inv['amount_received'], $inv['target_currency'])) ?></dd></div>
            </dl>
        </div>
        <?php if ($inv['description']): ?>
            <p class="detail-notes"><strong>Notes :</strong> <?= nl2br(e($inv['description'])) ?></p>
        <?php endif; ?>
    </section>

    <?php if (is_admin()): ?>
        <form method="post" class="danger-zone">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="delete">
            <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
            <button type="submit" class="btn btn--danger btn--sm" data-confirm="Supprimer définitivement le reçu <?= e($inv['invoice_number']) ?> ?">
                <?= icon('trash') ?> Supprimer le reçu
            </button>
        </form>
    <?php endif; ?>
    <?php
    dash_footer();
    exit;
}

/* ==========================================================================
   Vue : toutes les factures
   ========================================================================== */

if ($action === 'list') {
    $q       = query('q');
    $type    = query('type');
    $page    = max(1, (int) query('page', '1'));
    $perPage = 25;

    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(invoice_number LIKE ? OR client_name LIKE ? OR client_phone LIKE ? OR agent_name LIKE ? OR sender_name LIKE ? OR transaction_reason LIKE ?)';
        $like    = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if (isset(OPERATION_TYPES[$type])) {
        $where[]  = 'operation_type = ?';
        $params[] = $type;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total    = (int) db_value("SELECT COUNT(*) FROM invoices {$whereSql}", $params);
    $offset   = ($page - 1) * $perPage;
    $rows     = db_all("SELECT * FROM invoices {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

    dash_header('Tous les reçus', 'invoices');
    ?>
    <div class="toolbar">
        <form method="get" class="filters" role="search">
            <input type="hidden" name="action" value="list">
            <div class="search">
                <?= icon('search') ?>
                <label class="visually-hidden" for="q">Rechercher</label>
                <input id="q" name="q" type="search" placeholder="N°, client, motif, agent…" value="<?= e($q) ?>">
            </div>
            <label class="visually-hidden" for="f-type">Type</label>
            <select id="f-type" name="type" data-autosubmit>
                <option value="">Toutes opérations</option>
                <?php foreach (OPERATION_TYPES as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $key === $type ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($q !== '' || $type !== ''): ?><a href="invoices.php?action=list" class="btn btn--ghost btn--sm">Réinitialiser</a><?php endif; ?>
        </form>
        <a href="invoices.php" class="btn btn--primary"><?= icon('plus') ?> Nouveau reçu</a>
    </div>

    <section class="panel">
        <?php if (!$rows): ?>
            <p class="empty">Aucun reçu ne correspond à ces critères.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--hover table--stack"><?= INVOICE_TABLE_HEAD ?><tbody>
                    <?php foreach ($rows as $inv) { echo invoice_row($inv); } ?>
                </tbody></table>
            </div>
            <?= pagination($total, $page, $perPage, array_filter(['action' => 'list', 'q' => $q, 'type' => $type])) ?>
        <?php endif; ?>
    </section>
    <?php
    dash_footer();
    exit;
}

/* ==========================================================================
   Vue par défaut : saisie rapide + 10 dernières transactions
   ========================================================================== */

$prefilled = false;
if ($form === null) {
    $form = [
        'client_name' => '', 'client_phone' => '', 'operation_type' => 'Exchange',
        'amount_given' => '', 'source_currency' => 'EUR', 'target_currency' => settings_currency(),
        'exchange_rate' => '', 'rate_direction' => 'multiply', 'fees' => '0', 'payment_method' => 'Espèces', 'transaction_reason' => '',
        'printer_id' => (int) db_value('SELECT printer_id FROM invoices WHERE agent_id = ? AND printer_id IS NOT NULL ORDER BY id DESC LIMIT 1', [$user['id']]),
    ];
    // Pré-remplissage depuis le simulateur : chaque paramètre est validé, les autres sont ignorés
    $prefill = [
        'operation_type'  => fn ($v) => isset(OPERATION_TYPES[$v]),
        'source_currency' => fn ($v) => isset(DASH_CURRENCIES[$v]),
        'target_currency' => fn ($v) => isset(DASH_CURRENCIES[$v]),
        'rate_direction'  => fn ($v) => in_array($v, RATE_DIRECTIONS, true),
        'amount_given'    => fn ($v) => is_numeric($v) && (float) $v > 0,
        'exchange_rate'   => fn ($v) => is_numeric($v) && (float) $v > 0,
        'fees'            => fn ($v) => is_numeric($v) && (float) $v >= 0,
    ];
    foreach ($prefill as $key => $isValid) {
        $value = query($key);
        if ($value !== '' && $isValid($value)) {
            $form[$key] = in_array($key, ['amount_given', 'exchange_rate', 'fees'], true)
                ? str_replace('.', ',', $value) : $value;
            $prefilled = true;
        }
    }
}
$printers = printers_list(true);
if (!in_array((int) $form['printer_id'], array_map('intval', array_column($printers, 'id')), true)) {
    $form['printer_id'] = (int) ($printers[0]['id'] ?? 0);   // imprimante par défaut (première de la liste)
}
$recent = db_all('SELECT * FROM invoices ORDER BY id DESC LIMIT 10');
$err = fn (string $k) => isset($errors[$k]) ? '<small class="field__error">' . e($errors[$k]) . '</small>' : '';
$inv = fn (string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$currencyOptions = function (string $selected): string {
    $html = '';
    foreach (DASH_CURRENCIES as $code => $c) {
        $html .= '<option value="' . e($code) . '"' . ($code === $selected ? ' selected' : '') . '>' . e(currency_label($code)) . ' — ' . e($c['label']) . '</option>';
    }
    return $html;
};

dash_header('Reçus de caisse', 'invoices');
?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>Merci de corriger les champs indiqués.</p></div>
<?php elseif ($prefilled): ?>
    <div class="alert alert--success" role="status"><?= icon('calc') ?><p>Reçu pré-rempli depuis le simulateur : complétez les informations du client puis enregistrez.</p></div>
<?php endif; ?>

<!-- ==================== SAISIE RAPIDE ==================== -->
<form method="post" class="panel form invoice-form" id="invoice-form" novalidate
      data-rates="<?= e(json_encode(company_rates_js())) ?>"
      data-rates-updated="<?= e(company_rates_updated_label()) ?>"
      data-rates-api="rates-api.php"
      data-decimals="<?= e(json_encode(array_map(fn ($c) => $c['decimals'], DASH_CURRENCIES))) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save">

    <header class="panel__head">
        <h2 class="panel__title"><?= icon('file') ?> Nouveau reçu</h2>
        <p class="cell-sub">N° attribué automatiquement · Agent : <strong><?= e($user['name']) ?></strong></p>
    </header>

    <fieldset class="form-section">
        <legend>Client</legend>
        <div class="form-grid">
            <div class="field">
                <label for="client_name">Nom &amp; Prénom *</label>
                <input id="client_name" name="client_name" required maxlength="150" autocomplete="off" value="<?= e($form['client_name']) ?>"<?= $inv('client_name') ?>>
                <?= $err('client_name') ?>
            </div>
            <div class="field">
                <label for="client_phone">Téléphone / WhatsApp</label>
                <input id="client_phone" name="client_phone" type="tel" maxlength="50" autocomplete="off" placeholder="+237 6XX XX XX XX" value="<?= e($form['client_phone'] ?? '') ?>"<?= $inv('client_phone') ?>>
                <?= $err('client_phone') ?>
            </div>
            <div class="field">
                <label for="operation_type">Type d'opération *</label>
                <select id="operation_type" name="operation_type"<?= $inv('operation_type') ?>>
                    <?php foreach (OPERATION_TYPES as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $key === $form['operation_type'] ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('operation_type') ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>Montants &amp; taux</legend>
        <div class="form-grid form-grid--amounts">
            <div class="field">
                <label for="amount_given">Montant versé *</label>
                <input id="amount_given" name="amount_given" inputmode="decimal" required autocomplete="off" value="<?= e((string) $form['amount_given']) ?>"<?= $inv('amount_given') ?>>
                <?= $err('amount_given') ?>
            </div>
            <div class="field">
                <label for="source_currency">Devise source</label>
                <select id="source_currency" name="source_currency"<?= $inv('source_currency') ?>><?= $currencyOptions($form['source_currency']) ?></select>
                <?= $err('source_currency') ?>
            </div>
            <div class="field">
                <label for="target_currency">Devise cible</label>
                <select id="target_currency" name="target_currency"<?= $inv('target_currency') ?>><?= $currencyOptions($form['target_currency']) ?></select>
                <?= $err('target_currency') ?>
            </div>
            <div class="field">
                <label for="exchange_rate">Taux appliqué *</label>
                <div class="input-action">
                    <input id="exchange_rate" name="exchange_rate" inputmode="decimal" required autocomplete="off" value="<?= e((string) $form['exchange_rate']) ?>"<?= $inv('exchange_rate') ?>>
                    <button type="button" class="btn btn--ghost btn--sm" id="rate-suggest" title="Reprendre le taux de la grille de l'entreprise (Taux de change)">Taux GTC</button>
                </div>
                <small class="rate-badge" id="rate-badge" aria-live="polite"></small>
                <?= $err('exchange_rate') ?>
            </div>
            <div class="field">
                <label for="rate_direction">Sens du taux</label>
                <select id="rate_direction" name="rate_direction">
                    <option value="multiply" <?= $form['rate_direction'] === 'multiply' ? 'selected' : '' ?>>1 source = taux cible (×)</option>
                    <option value="divide" <?= $form['rate_direction'] === 'divide' ? 'selected' : '' ?>>1 cible = taux source (÷)</option>
                </select>
                <?= $err('rate_direction') ?>
            </div>
            <div class="field">
                <label for="fees">Commission / frais <small class="field__hint">(en devise source)</small></label>
                <input id="fees" name="fees" inputmode="decimal" autocomplete="off" value="<?= e((string) ($form['fees'] ?? '0')) ?>"<?= $inv('fees') ?>>
                <?= $err('fees') ?>
            </div>
            <div class="field">
                <label for="payment_method">Mode de paiement</label>
                <select id="payment_method" name="payment_method"<?= $inv('payment_method') ?>>
                    <?php foreach (PAYMENT_METHODS as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $key === $form['payment_method'] ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('payment_method') ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="form-section">
        <legend>Informations complémentaires</legend>
        <div class="form-grid">
            <div class="field field--full">
                <label for="transaction_reason">Motif / Expéditeur</label>
                <input id="transaction_reason" name="transaction_reason" maxlength="255" autocomplete="off" list="reason-suggestions"
                       placeholder="Ex. Frais de scolarité — ou nom de l'expéditeur" value="<?= e((string) ($form['transaction_reason'] ?? '')) ?>"<?= $inv('transaction_reason') ?>>
                <datalist id="reason-suggestions">
                    <option value="Assistance familiale">
                    <option value="Frais de scolarité">
                    <option value="Achat d'équipements">
                    <option value="Achat de marchandises">
                    <option value="Frais médicaux">
                    <option value="Voyage">
                    <option value="Règlement fournisseur">
                </datalist>
                <small class="field__hint">Motif de l'opération et/ou nom de la personne qui dépose ou envoie (ex. « Assistance familiale — Jean Mbarga »). Facultatif : « Non spécifié » sur le reçu s'il est laissé vide.</small>
                <?= $err('transaction_reason') ?>
            </div>
        </div>
    </fieldset>

    <!-- Montant calculé automatiquement -->
    <div class="net-box" aria-live="polite">
        <span class="net-box__label">Montant à recevoir</span>
        <strong class="net-box__value" id="net-amount">—</strong>
        <span class="net-box__detail" id="net-detail">Saisissez le montant versé et le taux.</span>
    </div>

    <div class="form-actions invoice-form__submit">
        <div class="field invoice-form__printer">
            <label for="printer_id">Imprimante de sortie</label>
            <?php if ($printers): ?>
                <!-- Liste actualisée en temps réel (assets/js/printers.js → printers-api.php) -->
                <div class="printer-picker" data-printer-picker data-api="printers-api.php">
                    <div class="printer-picker__row">
                        <select id="printer_id" name="printer_id"<?= $inv('printer_id') ?>>
                            <?php foreach ($printers as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" title="<?= e($p['printer_location']) ?>" <?= (int) $form['printer_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['printer_name']) ?> · <?= e(['a4' => 'A4', 'a5' => 'A5', 'ticket' => 'Ticket 80 mm'][$p['paper_format']] ?? $p['paper_format']) ?><?= (int) $p['is_default'] === 1 ? ' (par défaut)' : '' ?><?= printer_is_direct($p) ? ' · envoi direct' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn--ghost printer-picker__refresh" data-printer-refresh title="Actualiser la liste et détecter les imprimantes du réseau"><?= icon('refresh') ?> Actualiser / Détecter les imprimantes</button>
                    </div>
                    <p class="printer-picker__status" data-printer-status aria-live="polite"></p>
                </div>
                <?= $err('printer_id') ?>
            <?php else: ?>
                <p class="field__hint">Aucune imprimante configurée : le reçu sortira sur l'imprimante par défaut du poste<?= is_admin() ? ' (<a href="settings.php#systeme">ajouter une imprimante</a>)' : '' ?>.</p>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn--primary btn--lg"><?= icon('printer') ?> Enregistrer &amp; Imprimer le reçu</button>
    </div>
</form>

<!-- ==================== 10 DERNIÈRES TRANSACTIONS ==================== -->
<section class="panel">
    <header class="panel__head">
        <h2 class="panel__title">Dernières transactions</h2>
        <a href="invoices.php?action=list" class="panel__link">Tous les reçus</a>
    </header>
    <?php if (!$recent): ?>
        <p class="empty">Aucun reçu enregistré pour le moment.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--hover table--stack"><?= INVOICE_TABLE_HEAD ?><tbody>
                <?php foreach ($recent as $row) { echo invoice_row($row); } ?>
            </tbody></table>
        </div>
    <?php endif; ?>
</section>

<script src="<?= e(asset_url('assets/js/printers.js', '../')) ?>" defer></script>
<?php dash_footer(); ?>
