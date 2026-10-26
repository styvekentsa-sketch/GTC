<?php
/**
 * Clôture de caisse journalière — récapitulatif des reçus de caisse d'une journée, prêt à imprimer et à valider.
 *
 *   cash-closing.php                  Journée en cours
 *   cash-closing.php?date=AAAA-MM-JJ  Autre journée (pas dans le futur)
 *
 * Calcul (reçus PAYÉS de la journée, même date que la liste des reçus : création, à défaut émission) :
 *   entrées  = montants remis par les clients (amount_given, devise source, commission comprise)
 *   sorties  = montants versés aux clients (amount_received, devise cible)
 *   net      = entrées − sorties, par devise ; commissions par devise source
 * Les reçus en attente, brouillons et annulés sont listés à part, hors totaux.
 *
 * Validation (administrateurs, caissiers) : les totaux sont figés (table cash_closings) avec une empreinte des reçus ;
 * un reçu ajouté ou modifié ensuite est signalé et la journée peut être validée à nouveau. Journal d'activité.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';   // settings_all() : nom de l'entreprise (impression)

$user = require_login();
require_roles(OPERATION_ROLES);
$canClose = in_array($user['role'], ['admin', 'caissier'], true);

$tz    = new DateTimeZone('Africa/Douala');
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$date  = query('date', $today);
$dt    = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
if (!$dt || $dt->format('Y-m-d') !== $date || $date > $today) {
    $date = $today;
    $dt   = new DateTimeImmutable($today, $tz);
}
$next = $dt->modify('+1 day')->format('Y-m-d');
$prev = $dt->modify('-1 day')->format('Y-m-d');

/** Reçus de la journée et totaux. */
function closing_compute(string $date, string $next): array
{
    $rows = db_all('SELECT * FROM invoices WHERE COALESCE(created_at, issue_date) >= ? AND COALESCE(created_at, issue_date) < ? ORDER BY id',
        [$date, $next]);
    $currencies = [];   // code => [in, out, fees, count_in, count_out]
    $methods    = [];   // mode de paiement => [devise => [count, amount]]
    $operations = [];   // type d'opération => nombre
    $excluded   = [];   // statut => nombre (hors totaux)
    $paid       = 0;
    foreach ($rows as $r) {
        if ($r['status'] !== 'paid') {
            $excluded[$r['status']] = ($excluded[$r['status']] ?? 0) + 1;
            continue;
        }
        $paid++;
        $src = (string) $r['source_currency'];
        $dst = (string) $r['target_currency'];
        foreach ([$src, $dst] as $c) {
            $currencies[$c] ??= ['in' => 0.0, 'out' => 0.0, 'fees' => 0.0, 'count_in' => 0, 'count_out' => 0];
        }
        $currencies[$src]['in']   += (float) $r['amount_given'];
        $currencies[$src]['fees'] += (float) $r['fees'];
        $currencies[$src]['count_in']++;
        $currencies[$dst]['out']  += (float) $r['amount_received'];
        $currencies[$dst]['count_out']++;
        $method = (string) ($r['payment_method'] ?: 'Non précisé');
        $methods[$method][$src] ??= [0, 0.0];
        $methods[$method][$src][0]++;
        $methods[$method][$src][1] += (float) $r['amount_given'];
        $op = (string) $r['operation_type'];
        $operations[$op] = ($operations[$op] ?? 0) + 1;
    }
    ksort($currencies);
    ksort($methods);
    // Empreinte : identifiant, montants et statut de chaque reçu de la journée (détecte ajouts, modifications, suppressions)
    $fingerprint = hash('sha256', implode('|', array_map(fn ($r) => implode(':', [$r['id'], $r['amount_given'], $r['amount_received'], $r['fees'], $r['status'], $r['source_currency'], $r['target_currency']]), $rows)));
    return compact('rows', 'currencies', 'methods', 'operations', 'excluded', 'paid', 'fingerprint');
}

$data    = closing_compute($date, $next);
$closing = db_one('SELECT * FROM cash_closings WHERE closing_date = ?', [$date]);

/* ---------- Validation ---------- */
if (is_post()) {
    csrf_verify();
    if (post('op') === 'close') {
        require_roles(['admin', 'caissier']);
        $note = mb_substr(trim((string) post('note')), 0, 1000);
        $totals = ['currencies' => $data['currencies'], 'methods' => $data['methods'], 'operations' => $data['operations'], 'excluded' => $data['excluded']];
        $values = [$data['paid'], json_encode($totals, JSON_UNESCAPED_UNICODE), $data['fingerprint'], $note ?: null, (int) $user['id'], $user['name'], db_now()];
        if ($closing) {
            db_query('UPDATE cash_closings SET receipts_count = ?, totals_json = ?, fingerprint = ?, note = ?, closed_by = ?, closed_by_name = ?, closed_at = ? WHERE id = ?',
                [...$values, $closing['id']]);
        } else {
            db_query('INSERT INTO cash_closings (receipts_count, totals_json, fingerprint, note, closed_by, closed_by_name, closed_at, closing_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [...$values, $date]);
        }
        $summary = [];
        foreach ($data['currencies'] as $code => $c) {
            $summary[currency_label($code)] = 'net ' . money($c['in'] - $c['out'], $code);
        }
        activity_log('closing', 'cash', null, 'Caisse du ' . fdate($date) . ($closing ? ' (nouvelle validation)' : ''),
            ['reçus payés' => $data['paid']] + $summary + ($note !== '' ? ['note' => $note] : []));
        flash('success', 'Caisse du ' . fdate($date) . ' clôturée : ' . $data['paid'] . ' reçu' . ($data['paid'] > 1 ? 's' : '') . ' payé' . ($data['paid'] > 1 ? 's' : '') . '.');
        redirect('cash-closing.php?date=' . $date);
    }
}

$changed  = $closing && !hash_equals($closing['fingerprint'], $data['fingerprint']);
$history  = db_all('SELECT closing_date, receipts_count, closed_by_name, closed_at FROM cash_closings ORDER BY closing_date DESC LIMIT 10');
$company  = settings_all();
$name     = $company['company_name'] ?: GTC_NAME;
$dayLabel = ucfirst(['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int) $dt->format('w')]) . ' ' . fdate($date);

dash_header('Clôture de caisse', 'closing');
?>
<div class="toolbar no-print">
    <form method="get" class="filters closing-nav">
        <a href="cash-closing.php?date=<?= e($prev) ?>" class="btn btn--ghost btn--sm" title="Journée précédente"><?= icon('arrow-left') ?><span class="visually-hidden">Journée précédente</span></a>
        <label class="filters__date"><span>Journée du</span><input type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>" data-autosubmit></label>
        <?php if ($date < $today): ?>
            <a href="cash-closing.php?date=<?= e($next) ?>" class="btn btn--ghost btn--sm" title="Journée suivante"><?= icon('arrow') ?><span class="visually-hidden">Journée suivante</span></a>
            <a href="cash-closing.php" class="btn btn--ghost btn--sm">Aujourd'hui</a>
        <?php endif; ?>
    </form>
    <div class="toolbar__actions">
        <a href="invoices.php?<?= e(http_build_query(['action' => 'list', 'from' => $date, 'to' => $date])) ?>" class="btn btn--ghost"><?= icon('receipt') ?> Reçus du jour</a>
        <button type="button" class="btn btn--primary" onclick="window.print()"><?= icon('printer') ?> Imprimer</button>
    </div>
</div>

<!-- ==================== EN-TÊTE (impression) ==================== -->
<header class="closing-head">
    <div>
        <p class="closing-head__company"><?= e($name) ?></p>
        <h2 class="closing-head__title">Clôture de caisse — <?= e($dayLabel) ?></h2>
        <p class="page-intro">
            <?= $data['paid'] ?> reçu<?= $data['paid'] > 1 ? 's' : '' ?> payé<?= $data['paid'] > 1 ? 's' : '' ?> pris en compte
            <?php if ($data['excluded']): ?> · hors totaux :
                <?= e(implode(', ', array_map(fn ($s, $n) => $n . ' ' . mb_strtolower(INVOICE_STATUSES[$s]['label'] ?? $s), array_keys($data['excluded']), $data['excluded']))) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="closing-state">
        <?php if ($closing && !$changed): ?>
            <span class="badge badge--success"><?= icon('check') ?> Clôturée</span>
            <small class="cell-sub">par <?= e($closing['closed_by_name']) ?> le <?= e(fdate($closing['closed_at'], true)) ?></small>
        <?php elseif ($changed): ?>
            <span class="badge badge--danger"><?= icon('alert') ?> Modifiée depuis la clôture</span>
            <small class="cell-sub">clôturée par <?= e($closing['closed_by_name']) ?> le <?= e(fdate($closing['closed_at'], true)) ?> (<?= (int) $closing['receipts_count'] ?> reçus)</small>
        <?php else: ?>
            <span class="badge badge--warning">Non clôturée</span>
        <?php endif; ?>
    </div>
</header>

<?php if ($changed): ?>
    <div class="alert alert--warning no-print" role="alert"><?= icon('alert') ?>
        <p>Des reçus de cette journée ont été ajoutés, modifiés ou supprimés après la clôture. Vérifiez les totaux ci-dessous puis validez à nouveau la clôture.</p>
    </div>
<?php endif; ?>

<?php if (!$data['rows']): ?>
    <section class="panel"><p class="empty">Aucun reçu de caisse enregistré ce jour-là.</p></section>
<?php else: ?>

<!-- ==================== TOTAUX PAR DEVISE ==================== -->
<section class="panel">
    <header class="panel__head"><h2 class="panel__title">Totaux par devise <span class="cell-sub">(reçus payés)</span></h2></header>
    <?php if (!$data['currencies']): ?>
        <p class="empty">Aucun reçu payé ce jour-là.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table closing-table">
                <thead><tr><th>Devise</th><th class="num">Entrées<small class="closing-th-sub">remis par les clients</small></th><th class="num">Sorties<small class="closing-th-sub">versé aux clients</small></th><th class="num">Solde net</th><th class="num">Commissions<small class="closing-th-sub">comprises dans les entrées</small></th></tr></thead>
                <tbody>
                <?php foreach ($data['currencies'] as $code => $c): $net = $c['in'] - $c['out']; ?>
                    <tr>
                        <td><strong><?= e(currency_label($code)) ?></strong></td>
                        <td class="num"><?= e(money($c['in'], $code)) ?><small class="cell-sub"><?= $c['count_in'] ?> reçu<?= $c['count_in'] > 1 ? 's' : '' ?></small></td>
                        <td class="num"><?= e(money($c['out'], $code)) ?><small class="cell-sub"><?= $c['count_out'] ?> reçu<?= $c['count_out'] > 1 ? 's' : '' ?></small></td>
                        <td class="num closing-net <?= $net < 0 ? 'is-negative' : 'is-positive' ?>"><strong><?= $net > 0 ? '+' : '' ?><?= e(money($net, $code)) ?></strong></td>
                        <td class="num"><?= $c['fees'] > 0 ? e(money($c['fees'], $code)) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="help-text">Solde net positif : la caisse doit contenir davantage de cette devise qu'en début de journée ; négatif : moins.</p>
    <?php endif; ?>
</section>

<div class="dash-grid closing-grid">
    <!-- ==================== MODES DE PAIEMENT ==================== -->
    <section class="panel">
        <header class="panel__head"><h2 class="panel__title">Par mode de paiement</h2></header>
        <?php if (!$data['methods']): ?>
            <p class="empty">—</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Mode</th><th class="num">Reçus</th><th class="num">Montant remis</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['methods'] as $method => $byCur): foreach ($byCur as $code => [$n, $amount]): ?>
                        <tr><td><?= e($method) ?></td><td class="num"><?= (int) $n ?></td><td class="num"><?= e(money($amount, $code)) ?></td></tr>
                    <?php endforeach; endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- ==================== TYPES D'OPÉRATION ==================== -->
    <section class="panel">
        <header class="panel__head"><h2 class="panel__title">Par type d'opération</h2></header>
        <?php if (!$data['operations']): ?>
            <p class="empty">—</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($data['operations'] as $op => $n): ?>
                    <li class="list__item"><span class="list__main"><strong><?= e(OPERATION_TYPES[$op] ?? $op) ?></strong></span><span class="list__meta"><?= (int) $n ?> reçu<?= $n > 1 ? 's' : '' ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<!-- ==================== DÉTAIL DES REÇUS ==================== -->
<section class="panel">
    <header class="panel__head"><h2 class="panel__title">Détail des reçus (<?= count($data['rows']) ?>)</h2></header>
    <div class="table-wrap">
        <table class="table table--stack closing-receipts">
            <thead><tr><th>N° reçu</th><th>Heure</th><th>Client</th><th>Opération</th><th class="num">Remis</th><th class="num">Versé</th><th>Paiement</th><th>Statut</th><th>Agent</th></tr></thead>
            <tbody>
            <?php foreach ($data['rows'] as $r): ?>
                <tr class="<?= $r['status'] === 'paid' ? '' : 'is-disabled' ?>">
                    <td data-label="N° reçu"><a href="invoices.php?action=view&amp;id=<?= (int) $r['id'] ?>" class="link-strong"><?= e($r['invoice_number']) ?></a></td>
                    <td data-label="Heure"><?= e(substr((string) ($r['created_at'] ?: ''), 11, 5) ?: '—') ?></td>
                    <td data-label="Client"><?= e($r['client_name']) ?></td>
                    <td data-label="Opération"><?= e(OPERATION_TYPES[$r['operation_type']] ?? $r['operation_type']) ?></td>
                    <td data-label="Remis" class="num"><?= e(money($r['amount_given'], $r['source_currency'])) ?></td>
                    <td data-label="Versé" class="num"><?= e(money($r['amount_received'], $r['target_currency'])) ?></td>
                    <td data-label="Paiement"><?= e((string) $r['payment_method']) ?></td>
                    <td data-label="Statut"><?= badge($r['status'], INVOICE_STATUSES) ?></td>
                    <td data-label="Agent"><?= e((string) $r['agent_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- ==================== VALIDATION ==================== -->
<?php if ($closing && $closing['note']): ?>
    <section class="panel closing-note"><h2 class="panel__title">Observation</h2><p><?= nl2br(e($closing['note'])) ?></p></section>
<?php endif; ?>

<?php if ($canClose && $data['rows'] && (!$closing || $changed)): ?>
    <form method="post" class="panel form closing-form no-print">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="close">
        <h2 class="panel__title"><?= $changed ? 'Valider à nouveau la clôture' : 'Valider la clôture' ?></h2>
        <p class="page-intro">Vérifiez la caisse physique par devise, puis validez : les totaux ci-dessus sont figés avec votre nom et l'heure, et enregistrés dans le journal d'activité.</p>
        <div class="field">
            <label for="note">Observation <small>(facultatif : écart constaté, remarque…)</small></label>
            <textarea id="note" name="note" rows="2" maxlength="1000"><?= e((string) ($closing['note'] ?? '')) ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary" data-confirm="Valider la clôture de caisse du <?= e(fdate($date)) ?> ?"><?= icon('check') ?> Valider la clôture du <?= e(fdate($date)) ?></button>
        </div>
    </form>
<?php elseif (!$canClose && !$closing): ?>
    <p class="help-text no-print"><?= icon('alert') ?> La validation de la clôture est réservée aux administrateurs et aux caissiers.</p>
<?php endif; ?>

<!-- Signatures (impression uniquement) -->
<div class="closing-signatures print-only" aria-hidden="true">
    <div><span>Le caissier / la caissière</span></div>
    <div><span>Le responsable</span></div>
</div>

<?php if ($history): ?>
<section class="panel no-print">
    <header class="panel__head"><h2 class="panel__title">Dernières clôtures</h2></header>
    <ul class="list">
        <?php foreach ($history as $h): ?>
            <li class="list__item">
                <span class="list__main"><a href="cash-closing.php?date=<?= e($h['closing_date']) ?>" class="link-strong"><?= e(fdate($h['closing_date'])) ?></a>
                    <small><?= (int) $h['receipts_count'] ?> reçu<?= $h['receipts_count'] > 1 ? 's' : '' ?> payé<?= $h['receipts_count'] > 1 ? 's' : '' ?></small></span>
                <small class="list__meta">par <?= e($h['closed_by_name']) ?> le <?= e(fdate($h['closed_at'], true)) ?></small>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php dash_footer(); ?>
