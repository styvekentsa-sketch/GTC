<?php
/**
 * À propos — Global Trading Corporation
 *
 * Page centrée sur la transparence : présence physique, équipe réelle,
 * garanties concrètes (pas de témoignages clients).
 */
$pageTitle       = 'À propos';
$seoTitle        = 'À propos de GTC | Bureau de change rue Paul Soppo Priso, Douala';
$pageDescription = 'Global Trading Corporation, agence physique rue Paul Soppo Priso à Douala : une équipe identifiée pour le change, le transfert de fonds et l\'import-export.';
$currentPage     = 'a-propos';

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/team.php';
include __DIR__ . '/includes/header.php';

$waLink = 'https://wa.me/' . GTC_WHATSAPP . '?text=' . rawurlencode('Bonjour GTC, je souhaite avoir des informations.');

/* ==========================================================================
   Contenu
   ========================================================================== */

$hero = [
    'crumb'   => 'À propos',
    'image'   => 'office',
    'eyebrow' => 'Qui sommes-nous',
    'title'   => 'À propos de Global Trading Corporation',
    'lead'    => "Votre partenaire de confiance pour le change, le transfert de fonds et l'import/export à Douala.",
];

$highlights = [
    ['icon' => 'store', 'title' => 'Un guichet physique',
     'text' => 'Notre agence de la rue Paul Soppo Priso vous accueille du lundi au samedi : vous traitez vos opérations face à notre équipe.'],
    ['icon' => 'zap', 'title' => 'Des opérations traitées rapidement',
     'text' => 'Change de devises au comptant et transferts nationaux ou internationaux suivis du dépôt jusqu\'à la remise des fonds.'],
    ['icon' => 'globe', 'title' => 'Un réseau au-delà du Cameroun',
     'text' => 'Afrique, Europe, Amérique et Asie (Chine, Dubaï, Inde), grâce à nos partenaires au Cameroun et à l\'étranger.'],
];

$facts = [
    ['Création', '2020'],
    ['Fondateur', 'Chérif Alioum'],
    ['Siège social', 'Douala-Bonapriso'],
    ['Activités', 'Change, transfert, import-export'],
];

// Équipe : lue en base (gérée depuis dashboard/team.php)
$team = team_members();

$guarantees = [
    ['icon' => 'store',   'title' => 'Guichet Sécurisé',     'text' => 'Accueil et transactions directes à notre agence de Douala.'],
    ['icon' => 'eye',     'title' => 'Transparence Totale',  'text' => 'Taux appliqués confirmés avant toute validation de transaction.'],
    ['icon' => 'receipt', 'title' => 'Traçabilité & Reçu',   'text' => 'Délivrance de bordereau/facture pour chaque opération.'],
    ['icon' => 'phone',   'title' => 'Assistance Directe',   'text' => 'Équipe disponible sur place et par WhatsApp pour répondre à vos questions.'],
];

include __DIR__ . '/includes/hero.php';
?>

<!-- ==================== ENGAGEMENT & PRÉSENCE PHYSIQUE ==================== -->
<section class="section about-section">
    <div class="container about-intro">
        <div>
            <span class="section__eyebrow">Notre engagement</span>
            <h2>Une entreprise que vous pouvez venir rencontrer</h2>
            <p class="section__lead">
                Fondée en 2020 à Douala par Chérif Alioum, Global Trading Corporation a débuté dans l'import-export,
                le négoce et le transit, avant de développer le change de devises et le transfert de fonds.
                Aujourd'hui, nos clients — particuliers, familles de la diaspora et entreprises — traitent avec une
                équipe identifiée, dans un bureau ouvert au public.
            </p>

            <ul class="about-highlights">
                <?php foreach ($highlights as $h): ?>
                    <li>
                        <span class="about-icon"><?= icon($h['icon']) ?></span>
                        <div>
                            <h3><?= e($h['title']) ?></h3>
                            <p><?= e($h['text']) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <aside class="about-agency" aria-label="Notre agence">
            <div class="about-agency__photo">
                <?= unsplash_img('handshake', 'Poignée de main entre deux partenaires', ['ratio' => 16 / 10, 'sizes' => '(max-width: 992px) 100vw, 40vw']) ?>
            </div>
            <div class="about-agency__body">
                <h3 class="about-agency__title">Notre agence à Douala</h3>
                <ul class="about-agency__list">
                    <li><?= icon('pin') ?><span><strong>Adresse</strong><?= e(GTC_ADDRESS) ?></span></li>
                    <li><?= icon('clock') ?><span><strong>Horaires</strong>Lun-Ven 08h-17h30 · Sam 09h-14h</span></li>
                    <li><?= icon('phone') ?><span><strong>Téléphone</strong><a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a></span></li>
                </ul>
                <dl class="about-facts">
                    <?php foreach ($facts as [$label, $value]): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </div>
        </aside>
    </div>
</section>

<!-- ==================== ÉQUIPE ==================== -->
<?php if ($team): ?>
<section class="section section--white about-section team-section" id="equipe">
    <div class="container">
        <header class="team-section__header">
            <h2 class="team-banner">Rencontrez Notre Équipe</h2>
            <p class="section__lead">Les personnes qui vous reçoivent au guichet de la rue Paul Soppo Priso et suivent vos opérations.</p>
        </header>

        <!-- Photos : envoyées depuis le Dashboard (Équipe) ou fichiers nommés d'après le membre
             dans assets/img/team/ (ex. cherif-alioum.jpg) ; à défaut, initiales. -->
        <ul class="team-grid">
            <?php foreach ($team as $member): ?>
                <?php $photo = team_photo_url($member['photo'], '', $member['name']); ?>
                <li class="team-card">
                    <div class="team-card__photo">
                        <?php if ($photo): ?>
                            <img src="<?= e($photo) ?>" alt="Photo de <?= e($member['name']) ?>, <?= e($member['role']) ?>"
                                 width="180" height="180" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span class="team-card__initials" aria-hidden="true"><?= e(initials($member['name'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="team-card__name"><?= e($member['name']) ?></h3>
                    <p class="team-card__role"><?= e($member['role']) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<!-- ==================== GARANTIES DE CONFIANCE ==================== -->
<section class="section about-section" id="garanties">
    <div class="container">
        <header class="section__header section__header--center">
            <span class="section__eyebrow">Nos garanties</span>
            <h2>Nos garanties de confiance</h2>
            <p class="section__lead">Des engagements concrets, vérifiables à chacune de vos opérations.</p>
        </header>

        <ul class="grid grid--4 about-guarantees">
            <?php foreach ($guarantees as $i => $g): ?>
                <li class="about-guarantee">
                    <div class="about-guarantee__head">
                        <span class="about-icon"><?= icon($g['icon']) ?></span>
                        <span class="about-guarantee__num"><?= sprintf('%02d', $i + 1) ?></span>
                    </div>
                    <h3><?= e($g['title']) ?></h3>
                    <p><?= e($g['text']) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<!-- ==================== APPEL À L'ACTION ==================== -->
<section class="section section--white about-section">
    <div class="container">
        <div class="about-cta">
            <div>
                <h2>Passez nous voir ou écrivez-nous</h2>
                <p>Notre équipe vous reçoit à l'agence, rue Paul Soppo Priso, et répond à vos questions sur WhatsApp.</p>
            </div>
            <div class="about-cta__actions">
                <a href="<?= e($waLink) ?>" class="btn btn--accent btn--lg" target="_blank" rel="noopener">
                    <?= icon('whatsapp') ?> WhatsApp
                </a>
                <a href="contact.php" class="btn btn--outline btn--lg"><?= icon('mail') ?> Nous contacter</a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
