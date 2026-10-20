<?php
/**
 * Conditions générales d'utilisation — Global Trading Corporation
 */
$root            = '../';
$pageTitle       = "Conditions générales d'utilisation";
$pageDescription = "Conditions générales d'utilisation du site de Global Trading Corporation : accès, informations indicatives, propriété intellectuelle et responsabilités.";
$currentPage     = '';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/legal.php';
include __DIR__ . '/../includes/header.php';

$hero = [
    'eyebrow' => 'Informations légales',
    'title'   => "Conditions générales d'utilisation",
    'lead'    => 'Les règles qui encadrent la consultation et l\'utilisation de ce site.',
    'crumb'   => 'CGU',
    'parent'  => ['label' => 'Informations légales', 'href' => 'mentions-legales.php'],
];
include __DIR__ . '/../includes/hero.php';

legal_open('cgu');
?>
            <h2 id="objet">1. Objet et acceptation</h2>
            <p>Les présentes conditions générales d'utilisation (CGU) définissent les règles d'accès et d'utilisation du
               site <?= e(GTC_WEBSITE) ?>, édité par <?= e(GTC_NAME) ?> (voir les <a href="mentions-legales.php">mentions
               légales</a>). En naviguant sur le site, vous acceptez ces conditions.</p>

            <h2 id="acces">2. Accès au site</h2>
            <p>Le site est accessible gratuitement, à tout moment, sauf interruption pour maintenance, mise à jour ou cas de
               force majeure. Les frais de connexion à Internet restent à votre charge. Nous pouvons faire évoluer, suspendre
               ou interrompre tout ou partie du site sans préavis.</p>

            <h2 id="informations">3. Informations présentées</h2>
            <p>Le site présente nos services de transfert de fonds, d'échange de devises et d'accompagnement import-export.
               Ces informations sont fournies à titre <strong>général et indicatif</strong> :</p>
            <ul>
                <li>les <strong>taux de change</strong> affichés et les résultats du <strong>calculateur</strong> sont des
                    estimations ; ils ne constituent ni une offre ni un engagement ;</li>
                <li>le taux applicable, les frais et les délais d'une opération sont confirmés par nos conseillers avant toute
                    transaction ;</li>
                <li>toute opération est réalisée en agence ou après échange avec nos équipes, sur présentation des justificatifs
                    exigés par la réglementation en vigueur.</li>
            </ul>

            <h2 id="usage">4. Utilisation du formulaire de contact</h2>
            <p>Vous vous engagez à fournir des informations exactes et à ne pas transmettre de contenu illicite, injurieux,
               trompeur ou publicitaire. Les envois automatisés sont interdits et filtrés.</p>
            <p><strong>Vigilance :</strong> méfiez-vous des messages qui se présenteraient comme provenant de
               <?= e(GTC_NAME) ?> en utilisant d'autres coordonnées que celles de ce site. En cas de doute, contactez-nous
               directement au <a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a>.</p>

            <h2 id="propriete">5. Propriété intellectuelle</h2>
            <p>Le nom <?= e(GTC_NAME) ?>, le <strong>logo GTC</strong>, les textes, la charte graphique et l'organisation du
               site sont la propriété exclusive de <?= e(GTC_NAME) ?> et sont protégés par le droit de la propriété
               intellectuelle. Toute reproduction, représentation, modification ou utilisation, totale ou partielle, sans
               autorisation écrite préalable est interdite.</p>
            <p>Les photographies proviennent d'Unsplash et restent la propriété de leurs auteurs, conformément à la licence
               Unsplash. Vous pouvez créer un lien vers la page d'accueil du site, à condition de ne pas l'afficher à
               l'intérieur d'un autre site et de ne pas laisser croire à un partenariat.</p>

            <h2 id="responsabilite">6. Limitation de responsabilité</h2>
            <p><?= e(GTC_NAME) ?> veille à l'exactitude des informations publiées, sans pouvoir garantir leur exhaustivité ni
               leur mise à jour permanente. Sa responsabilité ne saurait être engagée :</p>
            <ul>
                <li>en cas de décision prise sur la seule base d'une information indicative du site (taux, estimation, délai) ;</li>
                <li>en cas d'interruption, de lenteur ou d'indisponibilité du site ;</li>
                <li>en cas de dommage lié à l'équipement de l'utilisateur ou à l'introduction d'un virus ;</li>
                <li>pour le contenu des sites tiers vers lesquels renvoient des liens (WhatsApp, Google Maps, réseaux sociaux).</li>
            </ul>

            <h2 id="espace">7. Espace Collaborateur</h2>
            <p>L'Espace Collaborateur est strictement réservé au personnel autorisé de <?= e(GTC_NAME) ?>. Toute tentative
               d'accès ou de maintien frauduleux est interdite et peut faire l'objet de poursuites, conformément à
               <?= e(LEGAL_LAW_CYBER) ?>.</p>

            <h2 id="donnees">8. Données personnelles et cookies</h2>
            <p>Voir la <a href="politique-confidentialite.php">politique de confidentialité</a> et la
               <a href="cookies.php">politique des cookies</a>.</p>

            <h2 id="modification">9. Modification des CGU</h2>
            <p>Nous pouvons modifier ces conditions à tout moment. La version applicable est celle en ligne lors de votre visite.</p>

            <h2 id="droit">10. Droit applicable et litiges</h2>
            <p>Les présentes CGU sont régies par le droit camerounais. En cas de différend, une solution amiable sera
               recherchée en priorité ; à défaut, les tribunaux compétents de Douala seront seuls compétents.</p>
<?php
legal_close();
include __DIR__ . '/../includes/footer.php';
