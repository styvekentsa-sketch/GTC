<?php
/**
 * Bandeau défilant (droite → gauche) des services de GTC, même carte sur index.php et services.php :
 * image, icône, titre, accroche, description, points clés et boutons WhatsApp / simulateur.
 * Le défilement s'arrête au survol ou au focus ; il est remplacé par une grille si l'utilisateur
 * a demandé moins d'animations. Images : clés de GTC_IMAGES (includes/media.php).
 * Styles : .svc-carousel / .svc-card dans assets/css/style.css.
 */
$gtcServices = [
    [
        'icon'     => 'exchange',
        'image'    => 'af-naira',
        'alt'      => 'Billets de naira nigérians : change de devises FCFA, NGN, EUR, USD',
        'title'    => 'Change de devises',
        'tagline'  => 'Vos devises au taux du jour, sans détour.',
        'text'     => 'Particuliers, voyageurs ou commerçants : changez vos billets en toute sécurité, avec un taux annoncé clairement avant chaque opération.',
        'points'   => [
            "Taux compétitifs, communiqués avant l'opération",
            '7 devises : EUR, USD, CAD, FCFA, NGN, AED, CNY',
            'Échange immédiat au guichet',
            'Remise en espèces à Douala, rue Paul Soppo Priso',
        ],
        'cta'      => 'Demander un change',
        'simulate' => true,
        'message'  => 'Bonjour GTC, je souhaite effectuer un change de devises. Quel est votre taux du jour ?',
    ],
    [
        'icon'    => 'transfer',
        'image'   => 'af-laptop',
        'alt'     => "Deux professionnels à Lagos suivent un transfert de fonds sur ordinateur",
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
        'image'   => 'af-terminal',
        'alt'     => 'Portiques du terminal à conteneurs du port du Cap : import-export en Afrique',
        'title'   => 'Import / Export',
        'tagline' => "Vos marchandises, de l'achat à la livraison.",
        'text'    => "Née du négoce et du transit, GTC accompagne vos projets d'importation et d'exportation et règle vos fournisseurs à l'étranger.",
        'points'  => [
            'Négoce, transit et import-export',
            "Paiement de vos fournisseurs à l'étranger",
            'Suivi à chaque étape de la démarche',
            'Étude de votre besoin et devis sur demande',
        ],
        'cta'     => 'Demander un devis',
        'message' => "Bonjour GTC, j'ai un projet d'import-export et je souhaite un devis.",
    ],
    [
        'icon'    => 'file-check',
        'image'   => 'af-trucks',
        'alt'     => 'Camions de marchandises sur une route du Kenya : transit et acheminement',
        'title'   => 'Transit',
        'tagline' => 'Vos formalités douanières, sans perte de temps.',
        'text'    => 'Nous prenons en charge le dédouanement et l’acheminement de vos marchandises, pour qu’elles quittent le port rapidement.',
        'points'  => [
            'Préparation des documents douaniers',
            'Dédouanement à l’import et à l’export',
            'Acheminement jusqu’à destination',
            'Devis sur demande',
        ],
        'cta'     => 'Demander un devis',
        'message' => 'Bonjour GTC, je souhaite un devis pour une opération de transit.',
    ],
    [
        'icon'    => 'ship',
        'image'   => 'af-ship',
        'alt'     => 'Navire à quai au port du Cap, en Afrique du Sud',
        'title'   => 'Agent Maritime',
        'tagline' => 'Votre navire bien accueilli à chaque escale.',
        'text'    => "Nous représentons armateurs et capitaines auprès des autorités portuaires et organisons l'escale de bout en bout.",
        'points'  => [
            "Gestion complète de l'escale",
            'Formalités auprès des autorités portuaires',
            'Assistance aux navires et aux équipages',
            'Interlocuteur unique sur place',
        ],
        'cta'     => 'Nous contacter',
        'message' => "Bonjour GTC, je souhaite vos services d'agent maritime.",
    ],
    [
        'icon'    => 'forklift',
        'image'   => 'af-stacker',
        'alt'     => 'Chariot gerbeur devant des conteneurs empilés au port du Cap',
        'title'   => 'Acconier',
        'tagline' => 'Vos cargaisons manipulées avec soin.',
        'text'    => 'Chargement, déchargement et stockage de vos marchandises à quai, dans le respect des règles de sécurité.',
        'points'  => [
            'Manutention à quai',
            'Chargement et déchargement des navires',
            'Sécurisation des cargaisons',
            'Devis sur demande',
        ],
        'cta'     => 'Demander un devis',
        'message' => 'Bonjour GTC, je souhaite un devis pour de la manutention portuaire (acconage).',
    ],
    [
        'icon'    => 'anchor',
        'image'   => 'af-durban',
        'alt'     => "Porte-conteneurs à l'entrée du port de Durban, en Afrique du Sud",
        'title'   => 'Consignation',
        'tagline' => 'Vos marchandises suivies, vos intérêts protégés.',
        'text'    => 'Nous assurons la prise en charge administrative de vos marchandises et veillons à leur bonne réception.',
        'points'  => [
            'Prise en charge administrative',
            'Réception et protection des marchandises',
            'Lien avec armateurs et destinataires',
            'Suivi jusqu’à la livraison',
        ],
        'cta'     => 'Nous contacter',
        'message' => 'Bonjour GTC, je souhaite vos services de consignation.',
    ],
    [
        'icon'    => 'handshake',
        'image'   => 'af-deal',
        'alt'     => "Poignée de main entre partenaires d'affaires à Lagos : négoce",
        'title'   => 'Négoce',
        'tagline' => 'Le bon produit, au bon prix, au bon partenaire.',
        'text'    => 'Achat, vente et intermédiation commerciale internationale : nous vous mettons en relation avec des partenaires fiables.',
        'points'  => [
            'Achat et vente de marchandises',
            'Intermédiation commerciale internationale',
            'Recherche de fournisseurs fiables',
            'Négociation des conditions',
        ],
        'cta'     => 'Nous contacter',
        'message' => 'Bonjour GTC, je souhaite échanger avec vous sur une opération de négoce.',
    ],
];

$serviceCard = function (array $s, bool $copy): void {
    $tab = $copy ? ' tabindex="-1"' : '';
    ?>
    <li class="svc-card"<?= $copy ? ' aria-hidden="true"' : '' ?>>
        <div class="svc-card__media">
            <?= unsplash_img($s['image'], $copy ? '' : $s['alt'], ['sizes' => '400px']) ?>
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
                <a href="<?= e(wa_link($s['message'])) ?>" class="btn btn--accent" target="_blank" rel="noopener"<?= $tab ?>>
                    <?= icon('whatsapp') ?> <?= e($s['cta']) ?>
                </a>
                <?php if (!empty($s['simulate'])): ?>
                    <a href="services.php#simulateur" class="btn btn--outline open-calculator"<?= $tab ?>><?= icon('calc') ?> Simuler</a>
                <?php endif; ?>
            </div>
        </div>
    </li>
    <?php
};
?>
<div class="svc-carousel">
    <ul class="svc-carousel__track" role="list">
        <?php foreach ($gtcServices as $s) { $serviceCard($s, false); } ?>
        <?php // Copie pour la boucle continue : ignorée par les lecteurs d'écran et le clavier ?>
        <?php foreach ($gtcServices as $s) { $serviceCard($s, true); } ?>
    </ul>
</div>
