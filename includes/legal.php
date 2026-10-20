<?php
/**
 * Pages légales (dossier legal/) : liste des pages, textes de référence et mise en page commune.
 *
 * Chaque page définit $root = '../', $legalPage (clé ci-dessous), puis :
 *   include header.php → include hero.php → legal_open() → contenu → legal_close() → include footer.php
 */

/** Pages légales : clé (nom du fichier) => libellé. */
const LEGAL_PAGES = [
    'mentions-legales'          => 'Mentions légales',
    'politique-confidentialite' => 'Politique de confidentialité',
    'cgu'                       => "Conditions générales d'utilisation",
    'cookies'                   => 'Politique des cookies',
];

/** Date de dernière mise à jour des textes (à modifier à chaque révision). */
const LEGAL_UPDATED = '2026-10-17';

/** Textes de loi camerounais cités dans les pages (à faire valider par un conseil juridique). */
const LEGAL_LAW_DATA   = 'la loi n° 2024/017 du 23 décembre 2024 relative à la protection des données à caractère personnel au Cameroun';
const LEGAL_LAW_CYBER  = 'la loi n° 2010/012 du 21 décembre 2010 relative à la cybersécurité et à la cybercriminalité au Cameroun';
const LEGAL_LAW_ECOMM  = 'la loi n° 2010/021 du 21 décembre 2010 régissant le commerce électronique au Cameroun';

/** Adresse complète de l'agence. */
function legal_address(): string
{
    return GTC_ADDRESS . ', Cameroun';
}

/** Date de mise à jour au format « 17 octobre 2026 ». */
function legal_updated(): string
{
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    [$y, $m, $d] = array_map('intval', explode('-', LEGAL_UPDATED));
    return $d . ' ' . $months[$m - 1] . ' ' . $y;
}

/** Ouvre la mise en page : sommaire des pages légales + article. */
function legal_open(string $current): void
{
    ?>
<section class="section legal-section">
    <div class="container legal-layout">
        <aside class="legal-nav" aria-label="Informations légales">
            <p class="legal-nav__title">Informations légales</p>
            <ul class="legal-nav__list">
                <?php foreach (LEGAL_PAGES as $key => $label): ?>
                    <li>
                        <a href="<?= e($key) ?>.php" class="legal-nav__link<?= $key === $current ? ' is-active' : '' ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>>
                            <?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="legal-nav__updated">Dernière mise à jour :<br><strong><?= e(legal_updated()) ?></strong></p>
        </aside>
        <article class="legal">
    <?php
}

/** Ferme la mise en page (avec le rappel des coordonnées de contact). */
function legal_close(): void
{
    ?>
            <aside class="legal__contact">
                <p class="legal__contact-title">Une question sur ces informations ?</p>
                <p>Écrivez-nous à <a href="mailto:<?= e(GTC_EMAIL) ?>"><?= e(GTC_EMAIL) ?></a>, appelez le
                   <a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a> ou passez à l'agence :
                   <?= e(legal_address()) ?>.</p>
            </aside>
        </article>
    </div>
</section>
    <?php
}
