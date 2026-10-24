<?php
/**
 * Page d'accueil — Global Trading Corporation
 */
$pageTitle       = 'Accueil';
$seoTitle        = 'Global Trading Corporation | Bureau de Change & Transfert d\'argent à Douala';
$pageDescription = 'Bureau de change et transfert d\'argent à Douala, rue Paul Soppo Priso : change FCFA, EUR, USD, CAD, transferts internationaux et commerce international.';
$currentPage     = 'accueil';

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/partners.php';
include __DIR__ . '/includes/header.php';

// ---------- Données ----------
$currencies = [
    ['code' => 'CAD',  'label' => 'CAD'],
    ['code' => 'USD',  'label' => 'USD'],
    ['code' => 'EUR',  'label' => 'EUR'],
    ['code' => 'FCFA', 'label' => 'FCFA'],
    ['code' => 'NGN',  'label' => 'NGN (Naira)'],
    ['code' => 'AED',  'label' => 'AED (Dirham)'],
    ['code' => 'CNY',  'label' => 'CNY (Yuan)'],
];

$values = [
    [
        'icon'  => 'clock',
        'title' => 'Disponibilité',
        'rows'  => [
            ['Lundi-Vendredi', '08h-17h30'],
            ['Samedi', '09h-14h'],
        ],
    ],
    [
        'icon'  => 'shield',
        'title' => 'Professionnalisme',
        'text'  => 'Exécution quotidienne rigoureuse et sécurisée.',
    ],
    [
        'icon'  => 'award',
        'title' => 'Expérience',
        'text'  => 'Expertise accumulée depuis la création.',
    ],
];

$isOpen = gtc_is_open();
?>

<!-- ==================== HERO ==================== -->
<section class="hero hero--image">
    <div class="hero__bg" aria-hidden="true">
        <!-- Port à conteneurs (assets/img/hero/port-*.webp|jpg) -->
        <?= local_picture('port', ['width' => 1600, 'height' => 1067, 'class' => 'hero__img--port']) ?>
    </div>
    <div class="container hero__grid">
        <div class="hero__content">
            <span class="section__eyebrow">Nous répondons présent à tous vos besoins</span>
            <h1 class="hero__title">Transfert de fonds &amp; échange de devises à l'international</h1>
            <p class="hero__lead">
                <!-- Depuis Douala, <?= e(GTC_NAME) ?> assure vos transferts d'argent de banque locale
                vers l'international ou en remise d'espèces main à main, ainsi que le change de
                vos devises : CAD, USD, EUR, FCFA, NGN, AED et CNY. -->
            </p>
            <div class="hero__actions">
                <a href="services.php" class="btn btn--accent btn--lg">
                    Nos Services <?= icon('arrow') ?>
                </a>
                <a href="contact.php" class="btn btn--outline-light btn--lg">Nous Contacter</a>
            </div>
        </div>

        <!-- Panneau d'information -->
        <aside class="hero__panel" aria-label="Informations pratiques">
            <div class="hero__panel-head">
                <h2 class="hero__panel-title">Informations pratiques</h2>
                <span class="status <?= $isOpen ? 'status--open' : 'status--closed' ?>">
                    <span class="status__dot" aria-hidden="true"></span>
                    <?= $isOpen ? 'Ouvert' : 'Fermé' ?>
                </span>
            </div>

            <dl class="info-list">
                <div class="info-list__row">
                    <dt>Lundi-Vendredi</dt><dd>08h-17h30</dd>
                </div>
                <div class="info-list__row">
                    <dt>Samedi</dt><dd>09h-14h</dd>
                </div>
            </dl>

            <p class="hero__panel-label">Devises disponibles</p>
            <ul class="chips">
                <?php foreach ($currencies as $c): ?>
                    <li class="chip"><?= e($c['code']) ?></li>
                <?php endforeach; ?>
            </ul>

            <a href="tel:<?= e(GTC_PHONE_LINK) ?>" class="hero__panel-phone">
                <span>Appelez-nous</span>
                <strong><?= e(GTC_PHONE) ?></strong>
            </a>
        </aside>
    </div>
</section>

<!-- ==================== SERVICES EN VEDETTE ==================== -->
<section class="section section--white" id="services">
    <div class="container">
        <header class="section__header">
            <span class="section__eyebrow">Nos services</span>
            <h2>Services en vedette</h2>
        </header>
    </div>

    <?php $carouselFull = false; include __DIR__ . '/includes/services-carousel.php'; ?>

    <div class="container svc-carousel__more">
        <a href="services.php" class="btn btn--primary">Voir tous nos services <?= icon('arrow') ?></a>
    </div>
</section>

<!-- ==================== NOS PARTENAIRES (gérés dans dashboard/partners.php) ==================== -->
<?php
$partners = partners_public();
$partnerSlider = count($partners) > 5;   // au-delà de 5 logos : défilement continu, sinon bandeau fixe
$partnerItem = function (array $p, bool $copy = false): void {
    $logo = partner_logo_url($p['logo'], '', $p['name']);
    $tag  = $p['website'] ? 'a' : 'span';
    ?>
    <li class="partners__item">
        <<?= $tag ?> class="partners__logo"<?= $p['website'] ? ' href="' . e($p['website']) . '" target="_blank" rel="noopener"' . ($copy ? ' tabindex="-1"' : '') : '' ?> title="<?= e($p['name']) ?>">
            <?php if ($logo): ?>
                <img src="<?= e($logo) ?>" alt="<?= e($p['name']) ?>" height="56" loading="lazy" decoding="async">
            <?php else: ?>
                <span class="partners__name"><?= e($p['name']) ?></span>
            <?php endif; ?>
        </<?= $tag ?>>
    </li>
    <?php
};
?>
<?php if ($partners): ?>
<section class="section section--white section--tight partners" id="partenaires" aria-labelledby="partners-title">
    <div class="container">
        <header class="section__header section__header--center partners__header">
            <span class="section__eyebrow">Nos Partenaires</span>
            <h2 class="partners__title" id="partners-title">Nos Partenaires Nous Font Confiance</h2>
        </header>

        <?php if ($partnerSlider): ?>
            <div class="partners__slider">
                <ul class="partners__track" role="list">
                    <?php foreach ($partners as $p) { $partnerItem($p); } ?>
                    <?php // Copie pour la boucle continue : ignorée par les lecteurs d'écran et le clavier ?>
                    <?php foreach ($partners as $p): ?>
                        <?php ob_start(); $partnerItem($p, true); echo str_replace('<li class="partners__item">', '<li class="partners__item" aria-hidden="true">', ob_get_clean()); ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <ul class="partners__row" role="list">
                <?php foreach ($partners as $p) { $partnerItem($p); } ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ==================== VALEURS & ENGAGEMENTS ==================== -->
<section class="section" id="engagements">
    <div class="container">
        <header class="section__header section__header--center">
            <span class="section__eyebrow">Valeurs &amp; Engagements</span>
            <h2>Nos engagements</h2>
        </header>

        <div class="grid grid--3">
            <?php foreach ($values as $i => $v): ?>
                <article class="card value-card">
                    <div class="value-card__head">
                        <span class="card__icon"><?= icon($v['icon']) ?></span>
                        <span class="value-card__num"><?= sprintf('%02d', $i + 1) ?></span>
                    </div>
                    <h3 class="card__title"><?= e($v['title']) ?></h3>

                    <?php if (!empty($v['rows'])): ?>
                        <dl class="info-list info-list--compact">
                            <?php foreach ($v['rows'] as [$label, $hours]): ?>
                                <div class="info-list__row">
                                    <dt><?= e($label) ?></dt><dd><?= e($hours) ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    <?php else: ?>
                        <p class="card__text"><?= e($v['text']) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ==================== TÉMOIGNAGES ==================== -->
<?php include __DIR__ . '/includes/testimonials.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
