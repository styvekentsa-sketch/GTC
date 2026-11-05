<?php
/**
 * Page d'accueil — Global Trading Corporation
 *
 * Bannière (accroche, repères de confiance, informations pratiques) → services en vedette (carrousel) → accès rapide
 * aux outils (suivi, simulateur, calculateur, FAQ) → partenaires → engagements → témoignages → appel à l'action.
 * Tous les textes reprennent des informations publiées ailleurs sur le site (À propos, Services, CGU).
 */
$pageTitle       = 'Accueil';
$seoTitle        = 'Bureau de change et transfert d\'argent à Douala | GTC';
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
        'icon'  => 'store',
        'title' => 'Un vrai guichet à Douala',
        'text'  => 'Notre agence de la rue Paul Soppo Priso vous accueille du lundi au samedi : vous traitez vos opérations face à une équipe identifiée.',
    ],
    [
        'icon'  => 'receipt',
        'title' => 'Des opérations traçables',
        'text'  => 'Taux annoncé avant chaque opération, référence unique et reçu pour chaque dépôt ou retrait. Vos informations ne sont jamais communiquées à des tiers.',
    ],
    [
        'icon'  => 'globe',
        'title' => 'Un réseau depuis 2020',
        'text'  => "Fondée en 2020 à Douala, GTC traite vos opérations vers l'Afrique, l'Europe, l'Amérique et l'Asie (Chine, Dubaï, Inde) avec ses partenaires.",
    ],
];

// Repères de confiance sous l'accroche de la bannière
$trust = ['Guichet à Douala depuis 2020', '7 devises au comptant', 'Afrique, Europe, Amérique, Asie', "Taux annoncé avant l'opération"];

// Accès rapide aux outils du site (le suivi, avec son champ de recherche, est construit à part)
$tools = [
    ['icon' => 'calc', 'title' => 'Simuler un change', 'text' => 'Estimez instantanément le montant que vous recevrez, au taux du jour de notre grille.',
     'href' => 'services.php#simulateur', 'link' => 'Ouvrir le simulateur', 'class' => 'open-calculator'],
    ['icon' => 'box', 'title' => 'Calculer un envoi', 'text' => 'Volume (CBM), poids volumétrique et estimation du fret maritime ou aérien de vos colis.',
     'href' => 'calculateur-cbm.php', 'link' => 'Calculateur CBM'],
    ['icon' => 'quote', 'title' => 'Questions fréquentes', 'text' => 'Documents à fournir, taux, transferts, retrait de marchandise : les réponses avant de venir.',
     'href' => 'faq.php', 'link' => 'Lire la FAQ'],
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
                Change de devises, transferts d'argent et fret international, traités depuis notre guichet de Douala
                par une équipe qui vous suit de bout en bout.
            </p>
            <div class="hero__actions">
                <a href="services.php" class="btn btn--accent btn--lg">
                    Nos services <?= icon('arrow') ?>
                </a>
                <a href="contact.php" class="btn btn--outline-light btn--lg">Nous contacter</a>
            </div>
            <ul class="hero__trust" aria-label="Pourquoi GTC">
                <?php foreach ($trust as $t): ?>
                    <li><?= icon('check') ?> <?= e($t) ?></li>
                <?php endforeach; ?>
            </ul>
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

            <a href="taux.php" class="hero__panel-rates">Voir les taux du jour <?= icon('arrow') ?></a>

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

    <?php include __DIR__ . '/includes/services-carousel.php'; ?>

    <div class="container svc-carousel__more">
        <a href="services.php" class="btn btn--primary">Voir tous nos services <?= icon('arrow') ?></a>
    </div>
</section>

<!-- ==================== ACCÈS RAPIDE (outils du site) ==================== -->
<section class="section" id="acces-rapide" aria-labelledby="tools-title">
    <div class="container">
        <header class="section__header">
            <span class="section__eyebrow">Accès rapide</span>
            <h2 id="tools-title">Vos outils en un clic</h2>
        </header>

        <div class="grid grid--4 tools">
            <!-- Suivi : recherche directe depuis l'accueil -->
            <article class="card tool-card tool-card--track">
                <span class="card__icon"><?= icon('ship') ?></span>
                <h3 class="card__title">Suivre une marchandise</h3>
                <p class="card__text">Saisissez votre code de suivi ou votre n° de B/L pour connaître l'étape en cours.</p>
                <form action="suivi.php" method="get" class="tool-card__form" role="search">
                    <label class="visually-hidden" for="home-track">Code de suivi ou n° de B/L / conteneur</label>
                    <input id="home-track" name="code" type="text" class="form-control" placeholder="GTC-XXXX-XXXX" maxlength="60" required autocomplete="off" autocapitalize="characters" spellcheck="false">
                    <button type="submit" class="btn btn--primary" aria-label="Suivre"><?= icon('search') ?></button>
                </form>
            </article>
            <?php foreach ($tools as $t): ?>
                <article class="card tool-card">
                    <span class="card__icon"><?= icon($t['icon']) ?></span>
                    <h3 class="card__title"><?= e($t['title']) ?></h3>
                    <p class="card__text"><?= e($t['text']) ?></p>
                    <a href="<?= e($t['href']) ?>" class="tool-card__link<?= isset($t['class']) ? ' ' . e($t['class']) : '' ?>"><?= e($t['link']) ?> <?= icon('arrow') ?></a>
                </article>
            <?php endforeach; ?>
        </div>
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
                <?php $logoW = partner_logo_width($logo, 56); ?>
                <img src="<?= e($logo) ?>" alt="<?= e($p['name']) ?>"<?= $logoW ? ' width="' . $logoW . '"' : '' ?> height="56" loading="lazy" decoding="async">
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
            <span class="section__eyebrow">Nos partenaires</span>
            <h2 class="partners__title" id="partners-title">Ils nous font confiance</h2>
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
<section class="section section--white" id="engagements">
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
<?php $testimonialsClass = ''; include __DIR__ . '/includes/testimonials.php'; ?>

<!-- ==================== APPEL À L'ACTION ==================== -->
<section class="section section--white section--tight" aria-labelledby="cta-title">
    <div class="container">
        <div class="cta-band">
            <div class="cta-band__text">
                <h2 id="cta-title">Une opération à réaliser&nbsp;?</h2>
                <p>Écrivez-nous sur WhatsApp ou appelez-nous : nous vous répondons avec le taux du jour et les étapes à suivre.
                    Notre guichet est ouvert du lundi au vendredi de 08h à 17h30 et le samedi de 09h à 14h.</p>
            </div>
            <div class="cta-band__actions">
                <a href="<?= e(wa_link('Bonjour GTC, je souhaite réaliser une opération.')) ?>" class="btn btn--accent btn--lg" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Écrire sur WhatsApp</a>
                <a href="tel:<?= e(GTC_PHONE_LINK) ?>" class="btn btn--outline-light btn--lg"><?= icon('phone') ?> <?= e(GTC_PHONE) ?></a>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
