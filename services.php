<?php
/**
 * Nos Solutions Financières & Commerciales — Global Trading Corporation
 *
 * Style : flat design (fond blanc, accents verts, cartes simples), limité à cette page
 * via le conteneur .svc (voir style.css, partie « 10g. Page Services »).
 */
$pageTitle       = 'Services';
$seoTitle        = 'Change FCFA, EUR, USD & transfert d\'argent à Douala | GTC';
$pageDescription = 'Change de devises FCFA, EUR, USD, CAD, Naira, Dirham de Dubaï et Yuan, transfert d\'argent international et import-export à Douala. Simulez votre change en ligne.';
$currentPage     = 'services';

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/rates.php';
require_once __DIR__ . '/includes/settings.php';   // taux de la grille de l'entreprise (includes/company_rates.php)
include __DIR__ . '/includes/header.php';

/** Lien WhatsApp avec message prérempli. */
function wa_link(string $message = ''): string
{
    return 'https://wa.me/' . GTC_WHATSAPP . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

/** Taux formaté à la française, au dix-millième près (« 655,957 », « 3,6941 »). */
function fx_rate_format(float $rate, string $code): string
{
    return rtrim(rtrim(number_format($rate, 4, ',', " "), '0'), ',') . " " . $code;
}

/** Montant formaté à la française selon la devise. */
function fx_format(float $amount, string $code): string
{
    $decimals = GTC_CURRENCIES[$code]['decimals'] ?? 2;
    return number_format($amount, $decimals, ',', "\u{202F}") . "\u{00A0}" . $code;
}

/**
 * Drapeau d'une devise, hébergé sur le site (assets/img/flags/{iso}.png, 80 px de large : net aussi sur écran haute densité).
 * Nouvelle devise : ajouter son drapeau dans ce dossier (ex. https://flagcdn.com/w80/{iso}.png).
 */
function flag(string $code, int $width = 32): string
{
    $iso = preg_replace('/[^a-z]/', '', GTC_CURRENCIES[$code]['flag'] ?? '');
    $h   = (int) round($width * 0.75);
    if ($iso === '' || !is_file(__DIR__ . '/assets/img/flags/' . $iso . '.png')) {
        return '<span class="flag flag--none" style="width:' . $width . 'px;height:' . $h . 'px" aria-hidden="true"></span>';
    }
    return '<img class="flag" src="' . e(asset_url('assets/img/flags/' . $iso . '.png')) . '"'
         . ' width="' . $width . '" height="' . $h . '" alt="" loading="lazy" decoding="async">';
}

/* ==========================================================================
   Contenu
   ========================================================================== */

$hero = [
    'crumb'   => 'Services',
    'image'   => 'buildings',
    'eyebrow' => 'Nos services',
    'title'   => 'Nos Solutions Financières & Commerciales',
    'lead'    => "Change de devises, transferts internationaux et import-export : GTC traite vos opérations "
               . "rapidement et au juste taux, depuis son guichet de Douala, rue Paul Soppo Priso.",
    'actions' => [
        ['label' => 'Simuler un change', 'href' => '#simulateur', 'class' => 'btn--accent open-calculator', 'icon' => 'calc'],
        ['label' => 'Nous contacter',    'href' => 'contact.php', 'class' => 'btn--outline-light'],
    ],
    'badges'  => [
        ['icon' => 'zap',   'label' => 'Traitement rapide'],
        ['icon' => 'store', 'label' => 'Guichet à Douala'],
        ['icon' => 'globe', 'label' => 'Afrique · Europe · Amérique · Asie'],
    ],
];

$services = [
    [
        'icon'    => 'exchange',
        'image'   => 'exchange',
        'alt'     => 'Billets en euros et en dollars : change de devises FCFA, EUR, USD',
        'title'   => 'Change de devises',
        'tagline' => 'Vos devises au taux du jour, sans détour.',
        'text'    => "Particuliers, voyageurs ou commerçants : changez vos billets en toute sécurité, avec un taux annoncé clairement avant chaque opération.",
        'points'  => [
            'Taux compétitifs, communiqués avant l\'opération',
            '7 devises : EUR, USD, CAD, FCFA, NGN, AED, CNY',
            'Échange immédiat au guichet',
            'Remise en espèces à Douala, rue Paul Soppo Priso',
        ],
        'cta'     => 'Demander un change',
        'simulate' => true,
        'message' => 'Bonjour GTC, je souhaite effectuer un change de devises. Quel est votre taux du jour ?',
    ],
    [
        'icon'    => 'transfer',
        'image'   => 'transfer',
        'alt'     => "Paiement par carte bancaire pour un transfert d'argent international",
        'title'   => 'Transfert de fonds international',
        'tagline' => 'Votre argent arrive là où il doit arriver.',
        'text'    => "Envoyez ou recevez des fonds entre le Cameroun et l'étranger, par virement bancaire ou en espèces — même sans compte bancaire à l'étranger.",
        'points'  => [
            'Traitement rapide de chaque dépôt',
            'Virement bancaire ou remise en espèces',
            'Bénéficiaire prévenu par appel dès le dépôt',
            'Afrique, Europe, Amérique, Chine, Dubaï, Inde',
        ],
        'cta'     => 'Demander un transfert',
        'message' => "Bonjour GTC, je souhaite effectuer un transfert d'argent international.",
    ],
    [
        'icon'    => 'box',
        'image'   => 'port',
        'alt'     => 'Port de commerce et conteneurs : import-export et commerce international',
        'title'   => 'Import / Export',
        'tagline' => "Vos marchandises, de l'achat à la livraison.",
        'text'    => "Née du négoce et du transit, GTC accompagne vos projets d'importation et d'exportation et règle vos fournisseurs à l'étranger.",
        'points'  => [
            'Négoce, transit et import-export',
            'Paiement de vos fournisseurs à l\'étranger',
            'Suivi à chaque étape de la démarche',
            'Étude de votre besoin et devis sur demande',
        ],
        'cta'     => 'Demander un devis',
        'message' => "Bonjour GTC, j'ai un projet d'import-export et je souhaite un devis.",
    ],
];

$availabilityText = [
    'available' => 'Guichet et transfert',
    'request'   => 'Sur demande — nous contacter',
    'limited'   => 'Disponibilité limitée',
];

$guarantees = [
    ['icon' => 'eye-off', 'title' => 'Confidentialité',      'text' => 'Vos informations et le détail de vos opérations ne sont jamais communiqués à des tiers.'],
    ['icon' => 'receipt', 'title' => 'Opérations traçables', 'text' => 'Chaque dépôt et chaque retrait sont enregistrés avec une référence unique.'],
    ['icon' => 'store',   'title' => 'Guichet physique',     'text' => 'Un vrai bureau à Douala, où vous êtes reçu par notre équipe.'],
];

// Simulateur : valeurs par défaut (affichées aussi sans JavaScript)
$fxFrom   = 'EUR';
$fxTo     = 'FCFA';
$fxAmount = 100;
// Taux de la grille de l'entreprise (Dashboard → Taux de change) : achat de la devise remise, vente de la devise reçue
$fxRates  = company_rates_js('FCFA');
$fxPair   = company_rate_pair($fxFrom, $fxTo);
$fxRate   = $fxPair ? (float) $fxPair['unit'] : 0.0;
$fxResult = $fxAmount * $fxRate;

include __DIR__ . '/includes/hero.php';
?>

<div class="svc">

    <!-- ==================== SERVICES ==================== -->
    <section class="section" id="services">
        <div class="container">
            <header class="section__header">
                <span class="section__eyebrow">Ce que nous faisons</span>
                <h2>Trois services, une seule exigence : la fiabilité</h2>
            </header>

            <div class="grid grid--3">
                <?php foreach ($services as $s): ?>
                    <article class="svc-card">
                        <div class="svc-card__media">
                            <?= unsplash_img($s['image'], $s['alt'], ['sizes' => '(max-width: 640px) 100vw, (max-width: 992px) 50vw, 33vw']) ?>
                        </div>
                        <div class="svc-card__body">
                            <span class="svc-card__icon"><?= icon($s['icon']) ?></span>
                            <h3 class="svc-card__title"><?= e($s['title']) ?></h3>
                            <p class="svc-card__tagline"><?= e($s['tagline']) ?></p>
                            <p class="svc-card__text"><?= e($s['text']) ?></p>

                            <ul class="svc-list">
                                <?php foreach ($s['points'] as $point): ?>
                                    <li><?= icon('check') ?><span><?= e($point) ?></span></li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="svc-card__actions">
                                <a href="<?= e(wa_link($s['message'])) ?>" class="btn btn--accent" target="_blank" rel="noopener">
                                    <?= icon('whatsapp') ?> <?= e($s['cta']) ?>
                                </a>
                                <?php if (!empty($s['simulate'])): ?>
                                    <a href="#simulateur" class="btn btn--outline open-calculator"><?= icon('calc') ?> Simuler</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ==================== DEVISES ==================== -->
    <section class="section" id="devises">
        <div class="container">
            <header class="section__header">
                <span class="section__eyebrow">Devises</span>
                <h2>Les devises que nous traitons</h2>
                <p class="section__lead">Sept devises disponibles au guichet de Douala et pour vos transferts.</p>
            </header>

            <ul class="cur-list">
                <?php foreach (GTC_CURRENCIES as $code => $c): ?>
                    <li class="cur-item">
                        <?= flag($code, 36) ?>
                        <div class="cur-item__main">
                            <p class="cur-item__code"><?= e($code) ?> <span><?= e($c['symbol']) ?></span></p>
                            <p class="cur-item__name"><?= e($c['name']) ?></p>
                        </div>
                        <div class="cur-item__meta">
                            <p class="cur-item__status cur-item__status--<?= e($c['availability']) ?>">
                                <?= e($availabilityText[$c['availability']]) ?>
                            </p>
                            <?php if ($code !== 'FCFA'): ?>
                                <button type="button" class="cur-item__link open-calculator" data-fx-pick="<?= e($code) ?>">Simuler</button>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <!-- ==================== RÉASSURANCE & CONTACT ==================== -->
    <section class="section section--alt" id="reassurance">
        <div class="container svc-split svc-split--top">
            <div>
                <span class="section__eyebrow">En toute confiance</span>
                <h2>Des opérations sécurisées, un guichet à Douala</h2>
                <p class="section__lead">
                    Depuis 2020, GTC accompagne particuliers, familles de la diaspora et entreprises.
                    Votre argent passe par une équipe identifiée et un bureau où vous pouvez venir.
                </p>

                <ul class="svc-guarantees">
                    <?php foreach ($guarantees as $g): ?>
                        <li>
                            <span class="svc-guarantees__icon"><?= icon($g['icon']) ?></span>
                            <div>
                                <strong><?= e($g['title']) ?></strong>
                                <p><?= e($g['text']) ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <p class="svc-zones"><?= icon('globe') ?><span><strong>Zones couvertes :</strong> Cameroun, Afrique, Europe, Amérique et Asie (Chine, Dubaï, Inde).</span></p>
            </div>

            <aside class="svc-contact">
                <h3 class="svc-contact__title">Passez nous voir ou écrivez-nous</h3>
                <ul class="svc-contact__list">
                    <li><?= icon('pin') ?><span><strong>Guichet</strong><?= e(GTC_ADDRESS) ?></span></li>
                    <li><?= icon('clock') ?><span><strong>Horaires</strong>Lun-Ven 08h-17h30 · Sam 09h-14h</span></li>
                    <li><?= icon('phone') ?><span><strong>Téléphone</strong><a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a></span></li>
                    <li><?= icon('mail') ?><span><strong>E-mail</strong><a href="mailto:<?= e(GTC_EMAIL) ?>"><?= e(GTC_EMAIL) ?></a></span></li>
                </ul>
                <div class="svc-contact__actions">
                    <a href="<?= e(wa_link('Bonjour GTC, je souhaite réaliser une opération.')) ?>" class="btn btn--accent btn--block" target="_blank" rel="noopener">
                        <?= icon('whatsapp') ?> Écrire sur WhatsApp
                    </a>
                    <a href="contact.php#formulaire" class="btn btn--outline btn--block"><?= icon('mail') ?> Formulaire de contact</a>
                </div>
            </aside>
        </div>
    </section>

</div>

<!-- ==================== SIMULATEUR (fenêtre modale) ==================== -->
<div id="calculator-modal" class="modal" hidden>
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="calculator-title" tabindex="-1">
        <button type="button" class="modal__close" data-modal-close aria-label="Fermer le simulateur"><?= icon('x') ?></button>

        <div class="modal__grid">
            <div class="modal__intro">
                <span class="section__eyebrow">Simulateur</span>
                <h2 id="calculator-title">Combien allez-vous recevoir ?</h2>
                <p class="modal__lead">
                    Choisissez vos devises et saisissez un montant : l'estimation s'affiche aussitôt.
                    Pour le taux exact du jour, un message suffit.
                </p>
                <ol class="svc-steps">
                    <li><span>1</span>Saisissez le montant et les devises.</li>
                    <li><span>2</span>Consultez le taux du jour sur WhatsApp.</li>
                    <li><span>3</span>Finalisez au guichet, en espèces ou par virement.</li>
                </ol>
            </div>

                <form class="fx" id="fx" data-rates="<?= e(json_encode($fxRates)) ?>" data-wa="<?= e(GTC_WHATSAPP) ?>" data-api="api-rates.php" onsubmit="return false">
                    <p class="fx__title"><?= icon('calc') ?> Calculateur de devises</p>

                    <label class="fx__label" for="fx-amount">Montant</label>
                    <div class="fx__row">
                        <input id="fx-amount" class="fx__input" type="number" min="0" step="any" inputmode="decimal"
                               value="<?= $fxAmount ?>" aria-describedby="fx-rate">
                        <label class="visually-hidden" for="fx-from">Devise de départ</label>
                        <select id="fx-from" class="fx__select">
                            <?php foreach (GTC_CURRENCIES as $code => $c): ?>
                                <option value="<?= e($code) ?>" <?= $code === $fxFrom ? 'selected' : '' ?>><?= e($code) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="fx__swap-row">
                        <button type="button" class="fx__swap" id="fx-swap" aria-label="Inverser les devises"><?= icon('swap') ?></button>
                    </div>

                    <label class="fx__label" for="fx-to">Estimation</label>
                    <div class="fx__row fx__row--result">
                        <output id="fx-result" class="fx__result" for="fx-amount fx-from fx-to" aria-live="polite"><?= e(fx_format($fxResult, $fxTo)) ?></output>
                        <select id="fx-to" class="fx__select">
                            <?php foreach (GTC_CURRENCIES as $code => $c): ?>
                                <option value="<?= e($code) ?>" <?= $code === $fxTo ? 'selected' : '' ?>><?= e($code) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <p class="fx__rate" id="fx-rate">
                        1 <?= e($fxFrom) ?> ≈ <?= e(fx_rate_format($fxRate, $fxTo)) ?>
                    </p>

                    <a class="btn btn--accent btn--lg btn--block" id="fx-cta" target="_blank" rel="noopener"
                       href="<?= e(wa_link("Bonjour GTC, je souhaite connaître le taux du jour pour convertir {$fxAmount} {$fxFrom} en {$fxTo}.")) ?>">
                        <?= icon('whatsapp') ?> Consulter le taux sur WhatsApp
                    </a>

                    <p class="fx__note">
                        Estimation aux taux du guichet GTC du <span id="fx-updated"><?= e(company_rates_updated_label()) ?></span>.
                        Le taux appliqué vous est confirmé avant toute opération.
                    </p>
                </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
