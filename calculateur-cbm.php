<?php
/**
 * Calculateur de volume (CBM) et estimation de fret maritime / aérien — page publique.
 *
 * Calculs en temps réel dans le navigateur (assets/js/freight-calculator.js) :
 *   volume d'un colis (m³)         = L × l × H (cm) / 1 000 000
 *   poids volumétrique (kg, aérien) = L × l × H (cm) / 6 000
 *   maritime : base taxable = max(volume total, poids réel total en tonnes) — règle « poids ou mesure » (1 t = 1 m³) —
 *              et au moins le minimum de groupage ; coût = base × tarif par m³
 *   aérien   : poids taxable = max(poids réel, poids volumétrique), arrondi au demi-kilo supérieur ; coût = poids × tarif par kg
 *
 * Tarifs indicatifs en FCFA, réglés par l'administrateur (Dashboard → Paramètres → Taux) ; un tarif vide n'affiche
 * que les volumes et poids. Sans JavaScript : message invitant à demander un devis.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';   // local_picture() : image de la bannière
require_once __DIR__ . '/includes/settings.php';

$rateValue = function (string $key): string {
    $v = setting($key);
    return is_numeric($v) && (float) $v > 0 ? (string) (float) $v : '';
};
$freight = [
    'sea'    => $rateValue('freight_sea_rate'),
    'air'    => $rateValue('freight_air_rate'),
    'seaMin' => is_numeric(setting('freight_sea_min_cbm', '1')) ? (string) max(0, (float) setting('freight_sea_min_cbm', '1')) : '1',
];

// ---------- Méta & données de la page ----------
$pageTitle       = 'Calculateur CBM et fret';
$seoTitle        = 'Calculateur CBM et poids volumétrique | Fret maritime et aérien vers Douala';
$pageDescription = 'Calculez gratuitement le volume (m³ / CBM) et le poids volumétrique de vos colis, puis estimez le coût du fret maritime ou aérien vers Douala avec Global Trading Corporation.';
$currentPage     = 'calculateur';   // hors menu principal : lié depuis la page Suivi et la carte « Transit »

include __DIR__ . '/includes/header.php';

$hero = [
    'crumb'   => 'Calculateur CBM',
    'picture' => ['name' => 'port-nuit', 'width' => 1600, 'height' => 1067],   // assets/img/hero/port-nuit-*.webp|jpg (port de nuit, fond sombre)
    'overlay' => 'light',
    'eyebrow' => 'Transit & fret international',
    'title'   => 'Calculez le volume de vos colis',
    'lead'    => "Volume en m³ (CBM), poids volumétrique et estimation du coût du fret maritime ou aérien, ligne par ligne, en temps réel.",
];
include __DIR__ . '/includes/hero.php';
?>

<section class="section section--white section--tight">
    <div class="container">
        <noscript>
            <p class="freight-noscript">Le calculateur nécessite JavaScript. Pour une estimation, envoyez les dimensions de vos colis
                sur <a href="https://wa.me/<?= e(GTC_WHATSAPP) ?>">WhatsApp</a> ou au <a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a>.</p>
        </noscript>

        <div class="freight" data-freight
             data-rate-sea="<?= e($freight['sea']) ?>" data-rate-air="<?= e($freight['air']) ?>" data-sea-min="<?= e($freight['seaMin']) ?>"
             data-whatsapp="<?= e(GTC_WHATSAPP) ?>" hidden>

            <!-- ==================== SAISIE ==================== -->
            <div class="freight__main">
                <fieldset class="freight-modes">
                    <legend class="form-label">Mode de transport</legend>
                    <label class="freight-mode">
                        <input type="radio" name="freight-mode" value="sea" checked>
                        <span class="freight-mode__box"><?= icon('ship') ?><span><strong>Fret maritime</strong><small>Facturé au m³ (CBM)</small></span></span>
                    </label>
                    <label class="freight-mode">
                        <input type="radio" name="freight-mode" value="air">
                        <span class="freight-mode__box"><?= icon('send') ?><span><strong>Fret aérien</strong><small>Facturé au kg (L × l × H / 6000)</small></span></span>
                    </label>
                </fieldset>

                <div class="freight-units" role="radiogroup" aria-label="Unité des dimensions">
                    <span class="form-label">Dimensions en</span>
                    <label><input type="radio" name="freight-unit" value="cm" checked> centimètres</label>
                    <label><input type="radio" name="freight-unit" value="m"> mètres</label>
                </div>

                <div class="freight-lines" data-lines aria-live="polite"></div>

                <button type="button" class="btn btn--outline btn--sm freight__add" data-add><?= icon('plus') ?> Ajouter un type de colis</button>
            </div>

            <!-- ==================== RÉSULTATS ==================== -->
            <aside class="freight-result" aria-labelledby="freight-result-title">
                <h2 class="freight-result__title" id="freight-result-title">Résultat</h2>
                <dl class="freight-totals">
                    <div><dt>Nombre de colis</dt><dd data-out="count">0</dd></div>
                    <div><dt>Volume total</dt><dd><span data-out="cbm">0</span> m³</dd></div>
                    <div><dt>Poids réel total</dt><dd data-out="weight">—</dd></div>
                    <div><dt>Poids volumétrique</dt><dd><span data-out="volumetric">0</span> kg</dd></div>
                </dl>

                <div class="freight-estimate" aria-live="polite">
                    <p class="freight-estimate__label" data-out="basis-label">Volume taxable</p>
                    <p class="freight-estimate__basis" data-out="basis">0 m³</p>
                    <p class="freight-estimate__why" data-out="basis-why"></p>
                    <div data-estimate-block>
                        <p class="freight-estimate__label">Estimation du fret</p>
                        <p class="freight-estimate__cost" data-out="cost">—</p>
                        <p class="freight-estimate__rate" data-out="rate"></p>
                    </div>
                    <p class="freight-estimate__none" data-no-rate hidden>Tarif sur devis : envoyez-nous ce calcul pour recevoir un prix.</p>
                </div>

                <a href="https://wa.me/<?= e(GTC_WHATSAPP) ?>" class="btn btn--accent freight-result__cta" data-quote target="_blank" rel="noopener">
                    <?= icon('whatsapp') ?> Demander un devis précis
                </a>
                <p class="freight-result__note">Estimation indicative, hors dédouanement, taxes, assurance et frais portuaires.
                    Le prix définitif est confirmé par notre équipe sur devis.</p>
            </aside>
        </div>

        <!-- Rappel des règles de calcul (aussi lu par les moteurs de recherche) -->
        <div class="freight-help">
            <article>
                <h2>Comment est calculé le volume (CBM) ?</h2>
                <p>Volume d'un colis en m³ = longueur × largeur × hauteur en mètres (ou en cm ÷ 1 000 000), multiplié par le nombre de colis identiques.
                    Ex. : un carton de 60 × 40 × 40 cm = 0,096 m³ ; 10 cartons = 0,96 m³.</p>
            </article>
            <article>
                <h2>Fret maritime</h2>
                <p>Facturé au m³ « taxable » : le plus grand du volume total et du poids total en tonnes (1 tonne = 1 m³).
                    <?php if ((float) $freight['seaMin'] > 0): ?>En groupage, un minimum de <?= e(str_replace('.', ',', $freight['seaMin'])) ?> m³ est facturé.<?php endif; ?></p>
            </article>
            <article>
                <h2>Fret aérien</h2>
                <p>Poids volumétrique en kg = longueur × largeur × hauteur en cm ÷ 6 000. Le poids facturé est le plus élevé entre le poids réel et le poids volumétrique,
                    arrondi au demi-kilo supérieur.</p>
            </article>
        </div>

        <p class="freight-links"><?= icon('ship') ?> Marchandise déjà confiée à GTC ? <a href="suivi.php">Suivez votre colis</a></p>
    </div>
</section>

<script src="<?= e(asset_url('assets/js/freight-calculator.js', $root)) ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
