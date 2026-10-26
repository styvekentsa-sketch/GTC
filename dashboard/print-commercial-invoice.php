<?php
/**
 * Impression A4 d'une facture commerciale (facture, proforma, devis) avec l'en-tête officiel de
 * Global Trading Corporation : secteurs d'activité, mentions légales, banque, coordonnées.
 *
 *   print-commercial-invoice.php?id=12[&autoprint=1]
 *
 * Informations officielles : includes/config.php (GTC_ACTIVITIES, GTC_NIU, GTC_RCCM, GTC_CNPS, GTC_BANK,
 * GTC_PO_BOX, GTC_PHONE / GTC_PHONE_2, GTC_EMAIL, GTC_OFFICIAL_WEBSITE) ; nom et logo : Paramètres → Entreprise.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/commercial_invoices.php';
require_once __DIR__ . '/../includes/letterhead.php';

$user = require_login();
require_roles(OPERATION_ROLES);
auth_send_headers();

$id      = (int) query('id');
$inv     = $id ? cinv_find($id) : null;
$auto    = query('autoprint') === '1';
$company = settings_all();
$name    = $company['company_name'] ?: GTC_NAME;
$type    = $inv ? (CINV_TYPES[$inv['doc_type']] ?? CINV_TYPES['invoice']) : null;
$cur     = $inv['currency'] ?? 'XAF';
$curLbl  = currency_label($cur);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $inv ? e($inv['invoice_number'] . ' — ' . $inv['client_name']) : 'Facture introuvable' ?> | <?= e($name) ?></title>
    <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/commercial-invoice-print.css', '../')) ?>">
</head>
<body>

<nav class="ci-toolbar" aria-label="Actions">
    <a href="invoices-list.php" class="ci-btn"><?= icon('arrow-left') ?> Retour aux factures</a>
    <?php if ($inv): ?>
        <a href="invoices-list.php?action=edit&amp;id=<?= (int) $inv['id'] ?>" class="ci-btn"><?= icon('edit') ?> Modifier</a>
        <button type="button" class="ci-btn ci-btn--primary" onclick="window.print()"><?= icon('printer') ?> Imprimer</button>
        <!-- PDF téléchargé directement (téléphones : contourne les limites d'impression de Chrome Android et Safari iOS) -->
        <a href="pdf.php?type=invoice&amp;id=<?= (int) $inv['id'] ?>" class="ci-btn ci-btn--pdf" download><?= icon('download') ?> Télécharger le PDF</a>
        <button type="button" class="ci-btn" data-pdf-share data-pdf-url="pdf.php?type=invoice&amp;id=<?= (int) $inv['id'] ?>" data-pdf-title="<?= e($type['label'] . ' ' . $inv['invoice_number']) ?>" hidden><?= icon('share') ?> Partager</button>
    <?php endif; ?>
</nav>

<?php if (!$inv): ?>
    <main class="ci-sheet ci-sheet--empty">
        <h1>Facture introuvable</h1>
        <p>Le document demandé n'existe pas ou a été supprimé.</p>
        <p><a href="invoices-list.php" class="ci-btn ci-btn--primary">Voir toutes les factures</a></p>
    </main>
<?php else: ?>
<main class="ci-sheet">

    <!-- ==================== PAPIER À EN-TÊTE OFFICIEL (assets/img/letterhead/, d'après « Papier_entete_GTC final version.pdf ») ==================== -->
    <!-- Répété sur chaque page à l'impression (position fixe) ; la facture s'écrit au centre -->
    <?= letterhead_html($name) ?>

    <!-- Zone d'écriture : les cellules d'en-tête et de pied du tableau réservent la place du papier à en-tête sur chaque page -->
    <table class="ci-page" role="presentation">
        <thead><tr><td><div class="ci-page__top"></div></td></tr></thead>
        <tfoot><tr><td><div class="ci-page__bottom"></div></td></tr></tfoot>
        <tbody><tr><td class="ci-page__body">

    <!-- ==================== DOCUMENT & CLIENT ==================== -->
    <section class="ci-meta">
        <div class="ci-doc">
            <h1 class="ci-doc__title"><?= e($type['title']) ?></h1>
            <dl>
                <div><dt>N°</dt><dd><?= e($inv['invoice_number']) ?></dd></div>
                <div><dt>Date</dt><dd><?= e(fdate($inv['issue_date'])) ?></dd></div>
                <?php if ($inv['due_date']): ?><div><dt><?= $inv['doc_type'] === 'quote' ? 'Valable jusqu\'au' : 'Échéance' ?></dt><dd><?= e(fdate($inv['due_date'])) ?></dd></div><?php endif; ?>
                <div><dt>Devise</dt><dd><?= e($curLbl) ?></dd></div>
            </dl>
            <?php if ($inv['status'] !== 'issued'): ?>
                <p class="ci-stamp ci-stamp--<?= e($inv['status']) ?>"><?= e(mb_strtoupper(CINV_STATUSES[$inv['status']]['label'])) ?></p>
            <?php endif; ?>
        </div>
        <div class="ci-client">
            <p class="ci-client__label"><?= $inv['doc_type'] === 'quote' ? 'Devis établi pour' : 'Facturé à' ?></p>
            <p class="ci-client__name"><?= e($inv['client_name']) ?></p>
            <?php if ($inv['client_address']): ?><p><?= e($inv['client_address']) ?></p><?php endif; ?>
            <?php if ($inv['client_phone']): ?><p>Tél. : <?= e($inv['client_phone']) ?></p><?php endif; ?>
            <?php if ($inv['client_email']): ?><p><?= e($inv['client_email']) ?></p><?php endif; ?>
            <?php if ($inv['client_niu']): ?><p>NIU : <?= e($inv['client_niu']) ?></p><?php endif; ?>
        </div>
    </section>

    <!-- ==================== TABLEAU (colonnes, contenus et alignements définis dans le mini-tableur) ==================== -->
    <table class="ci-items">
        <thead>
            <tr>
                <th class="ci-items__n">N°</th>
                <?php foreach ($inv["columns"] as $col): ?>
                    <th class="<?= $col["type"] === "amount" ? "ci-items__num ci-items__amount" : "" ?> al-<?= e($col["align"]) ?>"><?= e($col["label"]) ?><?= $col["type"] === "amount" ? " <span class=\"ci-items__cur\">(" . e($curLbl) . ")</span>" : "" ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($inv["items"] as $i => $row): ?>
            <tr class="<?= $row["amount"] === null ? "ci-items__note" : "" ?>">
                <td class="ci-items__n"><?= $i + 1 ?></td>
                <?php foreach ($inv["columns"] as $col): ?>
                    <?php if ($col["type"] === "amount"): ?>
                        <td class="ci-items__num al-<?= e(cinv_cell_align($row, $col)) ?>"><?= $row["amount"] === null ? "" : "<strong>" . e(cinv_amount((float) $row["amount"], $cur)) . "</strong>" ?></td>
                    <?php else: ?>
                        <td class="ci-items__text al-<?= e(cinv_cell_align($row, $col)) ?>"><?= nl2br(e((string) ($row[$col["key"]] ?? ""))) ?></td>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ==================== TOTAUX ==================== -->
    <section class="ci-summary">
        <div class="ci-words">
            <p class="ci-words__label"><?= e($type['stop']) ?> :</p>
            <p class="ci-words__text"><?= e(amount_in_words((float) $inv['total'], $cur)) ?></p>
            <?php if ($inv['notes']): ?>
                <p class="ci-notes"><strong>Notes :</strong> <?= nl2br(e($inv['notes'])) ?></p>
            <?php endif; ?>
        </div>
        <table class="ci-totals">
            <?php if ((float) $inv['tax_rate'] > 0): ?>
                <tr><th>Total HT (<?= e($curLbl) ?>)</th><td><?= e(cinv_amount((float) $inv['subtotal'], $cur)) ?></td></tr>
                <tr><th>TVA <?= e(str_replace('.', ',', rtrim(rtrim(number_format((float) $inv['tax_rate'], 2, '.', ''), '0'), '.'))) ?> %</th><td><?= e(cinv_amount((float) $inv['tax_amount'], $cur)) ?></td></tr>
                <tr class="ci-totals__grand"><th>Total TTC (<?= e($curLbl) ?>)</th><td><?= e(cinv_amount((float) $inv['total'], $cur)) ?></td></tr>
            <?php else: ?>
                <tr class="ci-totals__grand"><th>Total (<?= e($curLbl) ?>)</th><td><?= e(cinv_amount((float) $inv['total'], $cur)) ?></td></tr>
            <?php endif; ?>
        </table>
    </section>

    <!-- ==================== SIGNATURES ==================== -->
    <section class="ci-signatures">
        <div><p>Le client</p><span class="ci-sign"></span></div>
        <div><p>Pour <?= e($name) ?> — La Direction</p><span class="ci-sign ci-sign--stamp">Cachet et signature</span></div>
    </section>

    <p class="ci-foot">Document établi le <?= e(fdate($inv['created_at'] ?: $inv['issue_date'])) ?> par <?= e($inv['created_by_name'] ?? $user['name']) ?></p>

        </td></tr></tbody>
    </table>
</main>
<?php endif; ?>

<?php if ($inv && $auto): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
<script src="<?= e(asset_url('assets/js/pdf-share.js', '../')) ?>" defer></script>
</body>
</html>
