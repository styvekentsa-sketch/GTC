<?php
/**
 * Politique de confidentialité — Global Trading Corporation
 * Décrit les traitements réellement effectués par le site et le Dashboard (formulaire de contact,
 * statistiques de fréquentation sans cookie, opérations en agence, Espace Collaborateur).
 */
$root            = '../';
$pageTitle       = 'Politique de confidentialité';
$pageDescription = 'Comment Global Trading Corporation collecte, utilise, protège et conserve vos données personnelles, et comment exercer vos droits.';
$currentPage     = '';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/legal.php';
include __DIR__ . '/../includes/header.php';

$hero = [
    'eyebrow' => 'Informations légales',
    'title'   => 'Politique de confidentialité',
    'lead'    => 'Quelles données nous recueillons, pourquoi, combien de temps nous les gardons et quels sont vos droits.',
    'crumb'   => 'Politique de confidentialité',
    'parent'  => ['label' => 'Informations légales', 'href' => 'mentions-legales.php'],
];
include __DIR__ . '/../includes/hero.php';

legal_open('politique-confidentialite');
?>
            <p class="legal__intro"><?= e(GTC_NAME) ?> attache une grande importance à la confidentialité de vos
               informations. Cette politique s'applique au site <?= e(GTC_WEBSITE) ?> et aux opérations réalisées dans
               notre agence. Elle est établie conformément à <?= e(LEGAL_LAW_DATA) ?>.</p>

            <h2 id="responsable">1. Responsable du traitement</h2>
            <p><strong><?= e(GTC_NAME) ?></strong>, <?= e(legal_address()) ?> —
               <a href="mailto:<?= e(GTC_EMAIL) ?>"><?= e(GTC_EMAIL) ?></a> — <?= e(GTC_PHONE) ?>.</p>

            <h2 id="donnees">2. Données collectées et utilisation</h2>
            <div class="legal-table-wrap">
                <table class="legal-table">
                    <thead>
                        <tr><th scope="col">Situation</th><th scope="col">Données</th><th scope="col">Pourquoi</th><th scope="col">Durée de conservation</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">Formulaire de contact</th>
                            <td>Nom, e-mail, téléphone (facultatif), objet, message, adresse IP et date d'envoi</td>
                            <td>Répondre à votre demande ; prévenir les envois abusifs. Base : votre demande.</td>
                            <td>Le temps de traiter la demande, puis 3 ans au plus après le dernier échange</td>
                        </tr>
                        <tr>
                            <th scope="row">Statistiques de fréquentation</th>
                            <td>Empreinte chiffrée et non réversible de l'adresse IP, date de la visite</td>
                            <td>Compter les visiteurs uniques par jour (voir section 3). Base : intérêt légitime.</td>
                            <td>Empreintes conservées pour l'historique de fréquentation ; elles ne permettent pas de vous identifier</td>
                        </tr>
                        <tr>
                            <th scope="row">Contact par téléphone, e-mail ou WhatsApp</th>
                            <td>Les informations que vous choisissez de nous communiquer</td>
                            <td>Vous répondre et vous conseiller. Base : votre demande.</td>
                            <td>Durée de la relation, puis 3 ans au plus</td>
                        </tr>
                        <tr>
                            <th scope="row">Opérations en agence</th>
                            <td>Nom, téléphone, type d'opération, montants, devises, taux et mode de règlement</td>
                            <td>Établir votre bordereau, tenir la comptabilité et respecter nos obligations légales (réglementation des changes, lutte contre le blanchiment des capitaux). Base : contrat et obligation légale.</td>
                            <td>Durée imposée par la réglementation comptable et financière applicable</td>
                        </tr>
                        <tr>
                            <th scope="row">Espace Collaborateur (personnel)</th>
                            <td>Nom, e-mail, rôle, dates et adresses IP de connexion</td>
                            <td>Sécuriser l'accès aux outils internes. Base : intérêt légitime.</td>
                            <td>Durée d'activité du compte</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p>Nous ne vendons ni ne louons vos données, et nous ne les utilisons pas à des fins publicitaires.</p>

            <h2 id="audience">3. Analyse de trafic interne et sécurisée</h2>
            <p>Pour connaître la fréquentation du site, nous utilisons un compteur <strong>développé en interne</strong>,
               sans outil d'analyse tiers et <strong>sans cookie</strong> :</p>
            <ul>
                <li>votre adresse IP n'est <strong>jamais enregistrée en clair</strong> : elle est transformée en empreinte
                    chiffrée (HMAC-SHA256) à l'aide d'une clé secrète conservée sur notre serveur, hors de l'espace public ;
                    cette empreinte ne permet pas de retrouver l'adresse d'origine ;</li>
                <li>un visiteur n'est compté qu'une fois par jour ; les robots (moteurs de recherche, outils de surveillance)
                    sont exclus ;</li>
                <li>seuls des totaux (visiteurs du jour, du mois, total) sont consultés, par le personnel habilité, dans
                    l'Espace Collaborateur ; ces données ne sont transmises à personne.</li>
            </ul>

            <h2 id="destinataires">4. Destinataires</h2>
            <ul>
                <li>Le personnel habilité de <?= e(GTC_NAME) ?>, dans la limite de ses fonctions ;</li>
                <li>nos prestataires techniques (hébergement du site, messagerie électronique recevant les messages du
                    formulaire), tenus à la confidentialité ;</li>
                <li>les autorités administratives ou judiciaires, uniquement lorsque la loi l'exige.</li>
            </ul>

            <h2 id="tiers">5. Services tiers</h2>
            <p>Certaines fonctions s'appuient sur des services externes, qui reçoivent votre adresse IP et appliquent leur
               propre politique de confidentialité. Leurs serveurs peuvent être situés hors du Cameroun.</p>
            <ul>
                <li><strong>Google Fonts</strong> (police d'écriture) et <strong>Unsplash</strong> (photographies) : chargés à chaque visite, sans cookie ;</li>
                <li><strong>Google Maps</strong> (carte de la page Contact) : chargé uniquement si vous l'acceptez ;</li>
                <li><strong>Google Traduction</strong> : chargé uniquement si vous choisissez une autre langue que le français ;</li>
                <li><strong>WhatsApp</strong> (Meta) : utilisé uniquement si vous cliquez sur un lien WhatsApp pour nous écrire.</li>
            </ul>
            <p>Le détail figure dans la <a href="cookies.php">politique des cookies</a>.</p>

            <h2 id="securite">6. Sécurité</h2>
            <p>Nous mettons en œuvre des mesures techniques et organisationnelles adaptées :</p>
            <ul>
                <li>connexion chiffrée (HTTPS) ;</li>
                <li>Espace Collaborateur réservé au personnel, avec comptes nominatifs, mots de passe stockés sous forme
                    hachée, limitation des tentatives de connexion et fermeture automatique des sessions inactives ;</li>
                <li>formulaires protégés contre la falsification de requêtes et les envois automatisés ;</li>
                <li>accès aux données limité aux personnes qui en ont besoin.</li>
            </ul>

            <h2 id="droits">7. Vos droits</h2>
            <p>Vous disposez d'un droit d'<strong>accès</strong>, de <strong>rectification</strong>, d'<strong>effacement</strong>,
               d'<strong>opposition</strong> et de <strong>limitation</strong> du traitement de vos données, ainsi que du droit
               de retirer à tout moment votre consentement.</p>
            <p>Pour les exercer, écrivez à <a href="mailto:<?= e(GTC_EMAIL) ?>"><?= e(GTC_EMAIL) ?></a> ou par courrier à
               <?= e(legal_address()) ?>, en précisant votre demande. Nous pourrons vous demander de justifier de votre
               identité. Nous répondons dans un délai d'un mois.</p>
            <p>Si vous estimez que vos droits ne sont pas respectés, vous pouvez saisir l'autorité de contrôle compétente
               en matière de protection des données à caractère personnel au Cameroun.</p>

            <h2 id="mineurs">8. Mineurs</h2>
            <p>Nos services s'adressent aux personnes majeures. Nous ne collectons pas sciemment de données concernant des mineurs.</p>

            <h2 id="modifications">9. Modifications</h2>
            <p>Cette politique peut évoluer, notamment en cas de changement de la réglementation. La date de dernière mise à
               jour figure en tête de page ; nous vous invitons à la consulter régulièrement.</p>
<?php
legal_close();
include __DIR__ . '/../includes/footer.php';
