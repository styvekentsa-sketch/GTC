<?php
/**
 * Politique des cookies — Global Trading Corporation
 * Le tableau reflète les cookies et stockages réellement utilisés par le site (voir assets/js/cookies.js).
 */
$root            = '../';
$pageTitle       = 'Politique des cookies';
$pageDescription = 'Les cookies utilisés sur le site de Global Trading Corporation, leur durée, et comment accepter ou refuser les services tiers.';
$currentPage     = '';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/legal.php';
include __DIR__ . '/../includes/header.php';

$hero = [
    'eyebrow' => 'Informations légales',
    'title'   => 'Politique des cookies',
    'lead'    => 'Les cookies et traceurs utilisés sur ce site, et comment garder la main sur vos choix.',
    'crumb'   => 'Cookies',
    'parent'  => ['label' => 'Informations légales', 'href' => 'mentions-legales.php'],
];
include __DIR__ . '/../includes/hero.php';

legal_open('cookies');
?>
            <h2 id="definition">1. Qu'est-ce qu'un cookie ?</h2>
            <p>Un cookie est un petit fichier enregistré par votre navigateur lors de la visite d'un site. Le stockage local
               du navigateur (<em>localStorage</em>) fonctionne de manière comparable. Certains sont indispensables au
               fonctionnement du site ; d'autres, déposés par des services tiers, nécessitent votre accord.</p>

            <h2 id="choix">2. Votre choix</h2>
            <div class="cookie-choice" data-cookie-status>
                <p class="cookie-choice__state">Choix actuel : <strong data-cookie-status-label>aucun choix enregistré</strong></p>
                <div class="cookie-choice__actions">
                    <button type="button" class="btn btn--outline btn--sm" data-cookie-reject>Refuser</button>
                    <button type="button" class="btn btn--accent btn--sm" data-cookie-accept>Tout accepter</button>
                </div>
                <noscript><p>Le gestionnaire de choix nécessite JavaScript. Sans JavaScript, aucun service tiers soumis à consentement n'est chargé.</p></noscript>
            </div>
            <p>Vous pouvez modifier votre choix à tout moment ici ou avec le lien « Gérer les cookies » en bas de chaque page.
               Il est conservé dans votre navigateur jusqu'à ce que vous le changiez ou que vous effaciez vos données de navigation.</p>

            <h2 id="liste">3. Cookies et traceurs utilisés</h2>
            <div class="legal-table-wrap">
                <table class="legal-table">
                    <thead>
                        <tr><th scope="col">Nom</th><th scope="col">Émetteur</th><th scope="col">Finalité</th><th scope="col">Durée</th><th scope="col">Accord</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row"><code>GTC_SITE</code></th>
                            <td><?= e(GTC_NAME) ?></td>
                            <td>Sécurise l'envoi du formulaire de contact (jeton anti-falsification)</td>
                            <td>Fin de la navigation</td>
                            <td>Non requis (indispensable)</td>
                        </tr>
                        <tr>
                            <th scope="row"><code>cookie_consent</code><br><small>stockage local</small></th>
                            <td><?= e(GTC_NAME) ?></td>
                            <td>Mémorise votre choix concernant les cookies</td>
                            <td>Jusqu'à modification</td>
                            <td>Non requis (indispensable)</td>
                        </tr>
                        <tr>
                            <th scope="row"><code>googtrans</code></th>
                            <td><?= e(GTC_NAME) ?> / Google</td>
                            <td>Mémorise la langue choisie dans le sélecteur de langue</td>
                            <td>Fin de la navigation</td>
                            <td>Déposé uniquement si vous choisissez une langue</td>
                        </tr>
                        <tr>
                            <th scope="row">Cookies Google Traduction</th>
                            <td>Google</td>
                            <td>Fonctionnement de la traduction automatique</td>
                            <td>Fixée par Google</td>
                            <td>Chargé uniquement si vous choisissez une langue</td>
                        </tr>
                        <tr>
                            <th scope="row">Cookies Google Maps</th>
                            <td>Google</td>
                            <td>Affichage de la carte interactive de la page Contact</td>
                            <td>Fixée par Google</td>
                            <td><strong>Oui</strong> : chargé seulement après « Tout accepter » ou « Afficher la carte »</td>
                        </tr>
                        <tr>
                            <th scope="row"><code>GTC_ADMIN</code></th>
                            <td><?= e(GTC_NAME) ?></td>
                            <td>Session de l'Espace Collaborateur (personnel uniquement)</td>
                            <td>Fin de la navigation, 8 h au plus</td>
                            <td>Non requis (indispensable)</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2 id="audience">4. Mesure d'audience sans cookie</h2>
            <p>La fréquentation du site est mesurée par un compteur interne qui <strong>n'utilise aucun cookie</strong> et
               n'enregistre jamais votre adresse IP en clair. Son fonctionnement est détaillé dans la
               <a href="politique-confidentialite.php#audience">politique de confidentialité</a>.</p>

            <h2 id="aucun">5. Ce que nous n'utilisons pas</h2>
            <p>Aucun cookie publicitaire, aucun outil de suivi commercial et aucun bouton de partage de réseau social déposant
               des traceurs : les icônes de nos réseaux sociaux sont de simples liens.</p>

            <h2 id="ressources">6. Ressources externes sans cookie</h2>
            <p>La police d'écriture (Google Fonts) et les photographies (Unsplash) sont chargées depuis les serveurs de ces
               services. Ils ne déposent pas de cookie, mais reçoivent l'adresse IP de votre appareil, comme pour toute
               ressource chargée sur Internet.</p>

            <h2 id="navigateur">7. Paramétrer votre navigateur</h2>
            <p>Vous pouvez aussi bloquer ou supprimer les cookies depuis les réglages de votre navigateur (rubrique
               « Confidentialité » ou « Cookies »). Le blocage des cookies indispensables peut empêcher l'envoi du
               formulaire de contact.</p>
<?php
legal_close();
include __DIR__ . '/../includes/footer.php';
