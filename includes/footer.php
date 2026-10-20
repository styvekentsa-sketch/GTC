<?php
/**
 * Pied de page commun — Global Trading Corporation
 * Requiert includes/header.php (constantes GTC_*, fonction e(), $navItems, $root).
 * Contient la bannière de consentement aux cookies (assets/js/cookies.js).
 */
$root = $root ?? '';
$legalLinks = [
    'mentions-legales.php'          => 'Mentions légales',
    'politique-confidentialite.php' => 'Politique de confidentialité',
    'cgu.php'                       => "Conditions générales d'utilisation",
    'cookies.php'                   => 'Politique des cookies',
];
?>
</main>

<!-- ==================== PIED DE PAGE ==================== -->
<footer class="site-footer">
    <div class="container site-footer__grid">

        <!-- Colonne 1 : Présentation -->
        <div class="site-footer__col">
            <a href="<?= e($root) ?>index.php" class="logo logo--light" data-secret-logo>
                <img class="logo__img" src="<?= e($root) ?>assets/img/logo-gtc.svg" alt="" width="91" height="50" loading="lazy">
                <span class="logo__text">
                    <span class="logo__name">Global Trading</span>
                    <span class="logo__sub">Corporation</span>
                </span>
            </a>
            <p class="site-footer__about">
                <?= e(GTC_NAME) ?> vous accompagne dans vos opérations de transfert de fonds
                à l'international et d'échange de devises, du Cameroun vers l'Afrique,
                l'Europe, l'Amérique et l'Asie.
            </p>
            <?php if (social_available(array_keys(SOCIAL_NETWORKS))): ?>
                <p class="site-footer__follow">Suivez-nous</p>
                <?= social_links(['facebook', 'instagram', 'linkedin', 'youtube', 'twitter'], 'social--footer') ?>
            <?php endif; ?>

            <a href="https://wa.me/<?= e(GTC_WHATSAPP) ?>" class="btn btn--accent btn--sm" target="_blank" rel="noopener">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5Z"/></svg>
                Écrire sur WhatsApp
            </a>
        </div>

        <!-- Colonne 2 : Navigation rapide -->
        <div class="site-footer__col">
            <h2 class="site-footer__title">Navigation rapide</h2>
            <ul class="site-footer__links">
                <?php foreach ($navItems as $item): ?>
                    <li><a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Colonne 3 : Informations légales -->
        <div class="site-footer__col">
            <h2 class="site-footer__title">Informations légales</h2>
            <ul class="site-footer__links">
                <?php foreach ($legalLinks as $file => $label): ?>
                    <li><a href="<?= e($root) ?>legal/<?= e($file) ?>"><?= e($label) ?></a></li>
                <?php endforeach; ?>
                <li><button type="button" class="site-footer__cookies" data-cookie-settings>Gérer les cookies</button></li>
            </ul>
        </div>

        <!-- Colonne 4 : Horaires & Coordonnées -->
        <div class="site-footer__col">
            <h2 class="site-footer__title">Horaires d'ouverture</h2>
            <dl class="hours">
                <div class="hours__row"><dt>Lundi – Vendredi</dt><dd>08h – 17h30</dd></div>
                <div class="hours__row"><dt>Samedi</dt><dd>09h – 14h</dd></div>
                <div class="hours__row"><dt>Dimanche</dt><dd>Fermé</dd></div>
            </dl>

            <h2 class="site-footer__title site-footer__title--spaced">Coordonnées</h2>
            <ul class="site-footer__contact">
                <li>
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span><?= e(GTC_ADDRESS) ?></span>
                </li>
                <li>
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.1 9.9a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/></svg>
                    <a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a>
                </li>
                <li>
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                    <a href="mailto:<?= e(GTC_EMAIL) ?>"><?= e(GTC_EMAIL) ?></a>
                </li>
            </ul>
        </div>
    </div>

    <div class="site-footer__bottom">
        <div class="container">
            <p><a href="<?= e($root) ?>dashboard/login.php" class="site-footer__secret" rel="nofollow" tabindex="-1" aria-hidden="true">©</a> <?= date('Y') ?> <?= e(GTC_NAME) ?>. Tous droits réservés.</p>
            <a href="<?= e($root) ?>dashboard/login.php" class="site-footer__staff" rel="nofollow">Espace Collaborateur</a>
        </div>
    </div>
</footer>

<!-- ==================== BANNIÈRE COOKIES (affichée par assets/js/cookies.js tant qu'aucun choix n'est fait) ==================== -->
<section class="cookie-banner" id="cookie-banner" aria-label="Gestion des cookies" hidden>
    <div class="cookie-banner__inner">
        <div class="cookie-banner__text">
            <p class="cookie-banner__title">Cookies &amp; confidentialité</p>
            <p>Ce site utilise des cookies indispensables à son fonctionnement. Avec votre accord, il charge aussi des
               services tiers, comme la carte Google Maps, qui peuvent déposer leurs propres cookies.
               <a href="<?= e($root) ?>legal/cookies.php">En savoir plus</a></p>
        </div>
        <div class="cookie-banner__actions">
            <button type="button" class="btn btn--outline btn--sm" data-cookie-reject>Refuser</button>
            <button type="button" class="btn btn--accent btn--sm" data-cookie-accept>Tout accepter</button>
        </div>
    </div>
</section>

<script src="<?= e(asset_url('assets/js/cookies.js', $root)) ?>" defer></script>
<script src="<?= e(asset_url('assets/js/custom-select.js', $root)) ?>" defer></script>
<script src="<?= e(asset_url('assets/js/main.js', $root)) ?>" defer></script>
</body>
</html>
