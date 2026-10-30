<?php
/**
 * Papier à en-tête officiel (assets/img/letterhead/, d'après « Papier_entete_GTC final version.pdf »), version HTML.
 * Utilisé par dashboard/print-commercial-invoice.php et dashboard/print-courrier.php ; styles : .lh dans
 * assets/css/commercial-invoice-print.css. Répété sur chaque page à l'impression (position fixe).
 * Équivalent PDF : cipdf_letterhead() (includes/commercial_invoice_pdf.php).
 */

/** En-tête (bandeau, secteurs d'activité, logo) et pied de page (mentions légales, banque, coordonnées). */
function letterhead_html(string $companyName): string
{
    ob_start(); ?>
    <header class="lh lh--top">
        <img src="<?= e(asset_url('assets/img/letterhead/header.png', site_root())) ?>" alt="" class="lh__band">
        <ul class="lh__activities">
            <?php foreach (array_chunk(GTC_ACTIVITIES, 2) as $pair): ?>
                <li><?= implode(' <span class="lh__dot" aria-hidden="true">·</span> ', array_map('e', $pair)) ?></li>
            <?php endforeach; ?>
        </ul>
        <img src="<?= e(asset_url('assets/img/letterhead/logo.png', site_root())) ?>" alt="<?= e($companyName) ?>" class="lh__logo">
    </header>
    <footer class="lh lh--bottom">
        <img src="<?= e(asset_url('assets/img/letterhead/footer-stripes.png', site_root())) ?>" alt="" class="lh__stripes">
        <div class="lh__band-bottom">
            <p>N° de contribuable : <?= e(GTC_NIU) ?> <span>|</span> RCCM : <?= e(GTC_RCCM) ?> <span>|</span> N° matricule CNPS : <?= e(GTC_CNPS) ?></p>
            <p>Banque : <?= e(GTC_BANK['name']) ?> <span>|</span> Code banque : <?= e(GTC_BANK['bank_code']) ?> <span>|</span> Code guichet : <?= e(GTC_BANK['branch_code']) ?> <span>|</span> N° compte : <?= e(GTC_BANK['account']) ?></p>
            <p><?= e(GTC_PO_BOX) ?> <span>|</span> Mob. : <?= e(GTC_PHONE_2) ?> <span>|</span> Bur. : <?= e(GTC_PHONE) ?></p>
            <p>Site web : <?= e(GTC_OFFICIAL_WEBSITE) ?> <span>|</span> Email : <?= e(GTC_EMAIL) ?></p>
        </div>
        <img src="<?= e(asset_url('assets/img/letterhead/footer-line.png', site_root())) ?>" alt="" class="lh__line">
    </footer>
    <?php
    return (string) ob_get_clean();
}
