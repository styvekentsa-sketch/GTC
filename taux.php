<?php
/**
 * Taux du jour — grille de change de Global Trading Corporation (page publique).
 *
 * Taux de la grille de l'entreprise (Dashboard → Taux de change, includes/company_rates.php), en FCFA :
 *   « Nous achetons » : ce que vous recevez en FCFA quand vous nous remettez la devise ;
 *   « Nous vendons »  : ce que vous payez en FCFA pour obtenir la devise.
 * Devises dont 1 unité vaut moins d'1 FCFA (naira) : affichées pour 1 000 unités, plus lisible.
 * Taux indicatifs (CGU § 3) : confirmés au guichet avant chaque opération. Mis à jour automatiquement
 * dans la page toutes les 2 minutes (api-rates.php), sans recharger.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/rates.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/settings.php';   // grille de l'entreprise (company_rates_js)

$rates   = company_rates_js('FCFA');
$updated = company_rates_updated_label();
$availabilityText = [
    'available' => 'Disponible au guichet',
    'request'   => 'Sur demande — nous contacter',
    'limited'   => 'Disponibilité limitée',
];

/** Taux FCFA formaté (2 décimales au plus, zéros inutiles retirés) : « 655,96 ». */
function rate_fcfa(float $v): string
{
    $text = number_format($v, $v >= 100 ? 2 : 4, ',', "\u{202F}");
    return rtrim(rtrim($text, '0'), ',');
}

// ---------- Méta & données de la page ----------
$pageTitle       = 'Taux du jour';
$seoTitle        = 'Taux de change du jour à Douala : EUR, USD, NGN… | GTC';
$pageDescription = 'Taux de change du jour de GTC à Douala : achat et vente de l\'euro, du dollar, du dollar canadien, du naira, du dirham et du yuan contre le franc CFA.';
$currentPage     = 'taux';
$pageSchema      = [seo_exchange_rates($rates)];   // taux du guichet en données structurées (includes/seo.php)

include __DIR__ . '/includes/header.php';

$hero = [
    'crumb'   => 'Taux du jour',
    'image'   => 'exchange',   // billets euros et dollars — GTC_IMAGES, includes/media.php
    'eyebrow' => 'Change de devises',
    'title'   => 'Taux du jour',
    'lead'    => "Notre grille d'achat et de vente contre le franc CFA, mise à jour par l'agence. Mise à jour du {$updated}.",
    'actions' => [
        ['label' => 'Simuler un change', 'href' => 'services.php#simulateur', 'class' => 'btn--accent open-calculator', 'icon' => 'calc'],
        ['label' => 'Demander un taux', 'href' => wa_link('Bonjour GTC, je souhaite connaître votre taux du jour pour :'), 'class' => 'btn--outline-light'],
    ],
];
include __DIR__ . '/includes/hero.php';
?>

<section class="section section--white section--tight">
    <div class="container rates" data-rates-page data-api="api-rates.php">

        <header class="rates__head">
            <div>
                <h2 class="rates__title">Grille du guichet de Douala</h2>
                <p class="rates__updated"><?= icon('clock') ?> Mise à jour du <strong data-rates-updated><?= e($updated) ?></strong></p>
            </div>
            <div class="rates__legend">
                <span><strong>Nous achetons</strong> : vous nous remettez la devise, vous recevez des FCFA</span>
                <span><strong>Nous vendons</strong> : vous payez en FCFA, vous recevez la devise</span>
            </div>
        </header>

        <div class="rates__table-wrap">
            <table class="rates__table">
                <caption class="visually-hidden">Taux de change du jour en francs CFA : achat et vente par devise</caption>
                <thead>
                    <tr>
                        <th scope="col">Devise</th>
                        <th scope="col" class="num">Nous achetons</th>
                        <th scope="col" class="num">Nous vendons</th>
                        <th scope="col">Disponibilité</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (GTC_CURRENCIES as $code => $c):
                    if ($code === 'FCFA' || empty($rates[$code])) {
                        continue;
                    }
                    $r    = $rates[$code];
                    $unit = $r['buy'] < 1 ? 1000 : 1;   // naira : pour 1 000 unités
                    ?>
                    <tr data-code="<?= e($code) ?>" data-unit="<?= $unit ?>">
                        <th scope="row" data-label="Devise">
                            <span class="rates__cur">
                                <?= flag($code, 36) ?>
                                <span><strong><?= e(($unit > 1 ? number_format($unit, 0, ',', "\u{202F}") . ' ' : '1 ') . $code) ?></strong><small><?= e($c['name']) ?></small></span>
                            </span>
                        </th>
                        <td class="num" data-label="Nous achetons"><strong data-side="buy"><?= e(rate_fcfa($r['buy'] * $unit)) ?></strong> <small>FCFA</small></td>
                        <td class="num" data-label="Nous vendons"><strong data-side="sell"><?= e(rate_fcfa($r['sell'] * $unit)) ?></strong> <small>FCFA</small></td>
                        <td data-label="Disponibilité">
                            <span class="rates__avail rates__avail--<?= e($c['availability']) ?>"><?= e($availabilityText[$c['availability']] ?? '') ?></span>
                            <?php if ($c['availability'] === 'available'): ?>
                                <a href="services.php#simulateur" class="rates__simulate open-calculator">Simuler</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="rates__note"><?= icon('alert') ?>
            <span>Taux indicatifs, communiqués à titre d'information : le taux applicable à votre opération est annoncé et confirmé
            au guichet avant toute transaction, selon le montant et la disponibilité des devises
            (<a href="legal/cgu.php#informations">conditions d'utilisation</a>).</span></p>

        <div class="cta-band rates__cta">
            <div class="cta-band__text">
                <h2>Un montant important ou une devise rare&nbsp;?</h2>
                <p>Indiquez-nous la devise et le montant : nous vous confirmons le taux et réservons les fonds si nécessaire.</p>
            </div>
            <div class="cta-band__actions">
                <a href="<?= e(wa_link('Bonjour GTC, je souhaite un taux pour :')) ?>" class="btn btn--accent btn--lg" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Demander sur WhatsApp</a>
                <a href="tel:<?= e(GTC_PHONE_LINK) ?>" class="btn btn--outline-light btn--lg"><?= icon('phone') ?> <?= e(GTC_PHONE) ?></a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
