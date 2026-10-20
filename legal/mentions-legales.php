<?php
/**
 * Mentions légales — Global Trading Corporation
 * Les informations officielles (RCCM, NIU, forme juridique, hébergeur…) se renseignent dans includes/config.php.
 */
$root            = '../';
$pageTitle       = 'Mentions légales';
$pageDescription = 'Mentions légales du site de Global Trading Corporation : éditeur, siège à Douala, contact, hébergement et propriété intellectuelle.';
$currentPage     = '';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/legal.php';
include __DIR__ . '/../includes/header.php';

$hero = [
    'eyebrow' => 'Informations légales',
    'title'   => 'Mentions légales',
    'lead'    => "Qui édite ce site, comment nous joindre et dans quel cadre juridique il est publié.",
    'crumb'   => 'Mentions légales',
];
include __DIR__ . '/../includes/hero.php';

// NIU : celui des Paramètres du Dashboard (identique aux factures), sinon celui de config.php
$niu = GTC_NIU;
try {
    require_once __DIR__ . '/../includes/settings.php';
    $niu = company_niu();
} catch (Throwable) {
    // base indisponible : valeur de config.php
}

// Lignes affichées seulement si l'information est renseignée
$identity = array_filter([
    'Raison sociale'        => GTC_NAME,
    'Forme juridique'       => GTC_LEGAL_FORM,
    'Siège social'          => legal_address(),
    'RCCM'                  => GTC_RCCM,
    'NIU'                   => $niu,
]);
$host = array_filter([
    'Hébergeur' => GTC_HOST_NAME,
    'Adresse'   => GTC_HOST_ADDRESS,
    'Contact'   => GTC_HOST_CONTACT,
]);

legal_open('mentions-legales');
?>
            <h2 id="editeur">1. Éditeur du site</h2>
            <p>Le site <strong><?= e(GTC_WEBSITE) ?></strong> est édité par :</p>
            <dl class="legal-facts">
                <?php foreach ($identity as $label => $value): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                <?php endforeach; ?>
                <div><dt>Téléphone</dt><dd><a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a> · <a href="tel:<?= e(GTC_PHONE_2_LINK) ?>"><?= e(GTC_PHONE_2) ?></a></dd></div>
                <div><dt>E-mail</dt><dd><a href="mailto:<?= e(GTC_EMAIL) ?>"><?= e(GTC_EMAIL) ?></a></dd></div>
                <div><dt>Site web</dt><dd><?= e(GTC_WEBSITE) ?></dd></div>
            </dl>

            <h2 id="publication">2. Directeur de la publication</h2>
            <p>La direction de la publication est assurée par
               <?= GTC_PUBLICATION_DIRECTOR !== '' ? '<strong>' . e(GTC_PUBLICATION_DIRECTOR) . '</strong>' : 'le Directeur Général' ?>
               de <?= e(GTC_NAME) ?>, joignable aux coordonnées ci-dessus.</p>

            <?php if ($host): ?>
                <h2 id="hebergement">3. Hébergement</h2>
                <dl class="legal-facts">
                    <?php foreach ($host as $label => $value): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>

            <h2 id="activite"><?= $host ? '4' : '3' ?>. Activité</h2>
            <p><?= e(GTC_NAME) ?> propose, depuis son agence de Douala, des services de <strong>transfert de fonds
               à l'international</strong> et d'<strong>échange de devises</strong>, ainsi qu'un accompagnement des
               opérations d'import-export.</p>
            <p>Les taux de change, estimations et délais présentés sur ce site sont <strong>indicatifs</strong>.
               Le taux applicable à une opération est confirmé par nos conseillers, en agence ou par téléphone,
               avant toute transaction.</p>

            <h2 id="propriete"><?= $host ? '5' : '4' ?>. Propriété intellectuelle</h2>
            <p>Le nom, le logo, les textes et la présentation de ce site appartiennent à <?= e(GTC_NAME) ?>.
               Toute reproduction sans autorisation écrite est interdite. Les conditions détaillées figurent dans les
               <a href="cgu.php">conditions générales d'utilisation</a>.</p>

            <h2 id="donnees"><?= $host ? '6' : '5' ?>. Données personnelles et cookies</h2>
            <p>Le traitement de vos données est décrit dans la <a href="politique-confidentialite.php">politique de
               confidentialité</a>, et l'usage des cookies dans la <a href="cookies.php">politique des cookies</a>.</p>

            <h2 id="cadre"><?= $host ? '7' : '6' ?>. Cadre juridique</h2>
            <p>Ce site est soumis au droit camerounais, notamment à <?= e(LEGAL_LAW_ECOMM) ?>, à <?= e(LEGAL_LAW_CYBER) ?>
               et à <?= e(LEGAL_LAW_DATA) ?>.</p>

            <h2 id="credits"><?= $host ? '8' : '7' ?>. Crédits</h2>
            <ul>
                <li>Photographies : <a href="https://unsplash.com" target="_blank" rel="noopener">Unsplash</a>, utilisées selon la licence Unsplash ; elles restent la propriété de leurs auteurs.</li>
                <li>Police de caractères : Inter (licence SIL Open Font License), servie par Google Fonts.</li>
            </ul>
<?php
legal_close();
include __DIR__ . '/../includes/footer.php';
