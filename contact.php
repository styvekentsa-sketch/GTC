<?php
/**
 * Contact & Localisation — Global Trading Corporation
 *
 * Le formulaire est envoyé à process-contact.php, qui redirige ici
 * avec le résultat (succès, ou erreurs + saisie) stocké en session.
 */
require_once __DIR__ . '/includes/config.php';
gtc_public_session_start();
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';

// ---------- Jeton anti-CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------- Résultat du dernier envoi (lu une seule fois) ----------
$result = $_SESSION['contact'] ?? [];
unset($_SESSION['contact']);

$success = ($result['status'] ?? '') === 'success';
$errors  = $result['errors'] ?? [];
$fields  = array_merge(
    ['nom' => '', 'email' => '', 'telephone' => '', 'sujet' => '', 'message' => ''],
    $result['old'] ?? []
);

// ---------- Méta & données de la page ----------
$pageTitle       = 'Contact';
$seoTitle        = 'Contact & horaires | Bureau de change GTC à Douala';
$pageDescription = 'Contactez Global Trading Corporation à Douala, rue Paul Soppo Priso (Bonapriso) : téléphone, WhatsApp, e-mail, horaires d\'ouverture, plan d\'accès et formulaire.';
$currentPage     = 'contact';

include __DIR__ . '/includes/header.php';

$hero = [
    'crumb'   => 'Contact',
    'picture' => ['name' => 'contact', 'width' => 1600, 'height' => 800],   // assets/img/hero/contact-*.webp|jpg
    'overlay' => 'light',
    'eyebrow' => 'Contact',
    'title'   => 'Contactez-nous & Rendez-nous visite',
    'lead'    => "Le secrétariat de la direction Global Trading Corporation est au bout du fil pour répondre à vos préoccupations, n'hésitez pas à poser autant de questions que nécessaire.",
];

$isOpen   = gtc_is_open();
$mapQuery = rawurlencode(GTC_MAPS_QUERY);

/** Attributs d'accessibilité + classe d'erreur pour un champ */
$fieldAttrs = function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? ' class="form-control is-invalid" aria-invalid="true" aria-describedby="err-' . $name . '"'
        : ' class="form-control"';
};
$fieldError = function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? '<p class="form-error" id="err-' . $name . '">' . e($errors[$name]) . '</p>'
        : '';
};

include __DIR__ . '/includes/hero.php';
?>

<!-- ==================== CARTES D'INFORMATIONS ==================== -->
<section class="section section--white section--tight">
    <div class="container">
        <div class="grid grid--4">
            <article class="card contact-card">
                <span class="card__icon"><?= icon('pin') ?></span>
                <h2 class="contact-card__title">Adresse physique</h2>
                <p class="contact-card__value"><?= e(GTC_ADDRESS_SHORT) ?></p>
                <p class="contact-card__meta">1.229 Paul Soppo Priso</p>
            </article>

            <article class="card contact-card">
                <span class="card__icon"><?= icon('phone') ?></span>
                <h2 class="contact-card__title">Téléphone / WhatsApp</h2>
                <p class="contact-card__value"><a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a></p>
                <p class="contact-card__meta"><a href="tel:<?= e(GTC_PHONE_2_LINK) ?>"><?= e(GTC_PHONE_2) ?></a></p>
                <a href="https://wa.me/<?= e(GTC_WHATSAPP) ?>" class="contact-card__link" target="_blank" rel="noopener">
                    <?= icon('whatsapp') ?> Écrire sur WhatsApp
                </a>
            </article>

            <article class="card contact-card">
                <span class="card__icon"><?= icon('mail') ?></span>
                <h2 class="contact-card__title">E-mail</h2>
                <p class="contact-card__value"><a href="mailto:<?= e(GTC_EMAIL) ?>"><?= e(GTC_EMAIL) ?></a></p>
            </article>

            <article class="card contact-card">
                <span class="card__icon"><?= icon('clock') ?></span>
                <h2 class="contact-card__title">Horaires</h2>
                <p class="contact-card__value">Lun-Ven 08h-17h30 | Sam 09h-14h</p>
                <span class="status <?= $isOpen ? 'status--open' : 'status--closed' ?>">
                    <span class="status__dot" aria-hidden="true"></span>
                    <?= $isOpen ? 'Ouvert' : 'Fermé' ?>
                </span>
            </article>
        </div>
    </div>
</section>

<!-- ==================== FORMULAIRE + CARTE ==================== -->
<section class="section" id="formulaire">
    <div class="container contact-layout">

        <!-- Formulaire -->
        <div class="form-panel">
            <h2>Demandez-nous quelque chose.</h2>
            <p class="form-panel__intro">
                Nous collectons les soumissions de tout visiteur afin de garantir une réponse bien détaillée pour une
                meilleure compréhension.
            </p>

            <?php if ($success): ?>
                <div class="alert alert--success" role="status">
                    <?= icon('check') ?>
                    <p>Merci ! Votre message a bien été envoyé. Nous vous répondrons dans les plus brefs délais.</p>
                </div>
            <?php elseif (!empty($errors['global'])): ?>
                <div class="alert alert--error" role="alert">
                    <?= icon('alert') ?>
                    <p><?= e($errors['global']) ?></p>
                </div>
            <?php elseif ($errors): ?>
                <div class="alert alert--error" role="alert">
                    <?= icon('alert') ?>
                    <p>Merci de corriger les champs indiqués ci-dessous.</p>
                </div>
            <?php endif; ?>

            <form action="process-contact.php" method="post" class="form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                <!-- Pot de miel anti-spam (masqué). Nom volontairement sans signification : un nom courant
                     (« website », « url »…) serait rempli par l'auto-remplissage du navigateur et le
                     message d'un vrai visiteur serait écarté comme celui d'un robot. -->
                <div class="form-hp" aria-hidden="true">
                    <label for="gtc_hp_7k">Ne pas remplir</label>
                    <input type="text" id="gtc_hp_7k" name="gtc_hp_7k" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore data-bwignore>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nom" class="form-label">Nom et Prénom <span class="req">*</span></label>
                        <input type="text" id="nom" name="nom" required maxlength="100" autocomplete="name"
                               value="<?= e($fields['nom']) ?>"<?= $fieldAttrs('nom') ?>>
                        <?= $fieldError('nom') ?>
                    </div>
                    <div class="form-group">
                        <label for="email" class="form-label">E-mail <span class="req">*</span></label>
                        <input type="email" id="email" name="email" required maxlength="150" autocomplete="email"
                               value="<?= e($fields['email']) ?>"<?= $fieldAttrs('email') ?>>
                        <?= $fieldError('email') ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="telephone" class="form-label">Téléphone</label>
                        <input type="tel" id="telephone" name="telephone" maxlength="25" autocomplete="tel"
                               value="<?= e($fields['telephone']) ?>"<?= $fieldAttrs('telephone') ?>>
                        <?= $fieldError('telephone') ?>
                    </div>
                    <div class="form-group">
                        <label for="sujet" class="form-label">Sujet <span class="req">*</span></label>
                        <input type="text" id="sujet" name="sujet" required maxlength="150"
                               value="<?= e($fields['sujet']) ?>"<?= $fieldAttrs('sujet') ?>>
                        <?= $fieldError('sujet') ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="message" class="form-label">Message <span class="req">*</span></label>
                    <textarea id="message" name="message" rows="6" required minlength="10" maxlength="5000"
                              placeholder="Dites-nous en plus..."<?= $fieldAttrs('message') ?>><?= e($fields['message']) ?></textarea>
                    <?= $fieldError('message') ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn--primary btn--lg">
                        <?= icon('send') ?> Envoyer
                    </button>
                    <p class="form-note"><span class="req">*</span> Champs obligatoires</p>
                </div>
            </form>
        </div>

        <!-- Localisation -->
        <aside class="map-panel">
            <div class="map-panel__head">
                <span class="card__icon"><?= icon('pin') ?></span>
                <div>
                    <h2 class="map-panel__heading">Rendez-nous visite</h2>
                    <p class="map-panel__meta">Bonapriso, carrefour ancien chococho</p>
                </div>
            </div>

            <!-- Carte Google Maps : chargée seulement après accord (assets/js/cookies.js) -->
            <div class="map-frame" data-consent-embed
                 data-src="https://maps.google.com/maps?q=<?= $mapQuery ?>&amp;t=m&amp;z=15&amp;output=embed&amp;iwloc=near"
                 data-title="Localisation de Global Trading Corporation à Douala">
                <div class="consent-embed__placeholder">
                    <span class="consent-embed__icon"><?= icon('pin') ?></span>
                    <p>La carte est fournie par Google Maps, qui peut déposer des cookies.</p>
                    <button type="button" class="btn btn--accent btn--sm" data-consent-load>Afficher la carte</button>
                    <a href="legal/cookies.php" class="consent-embed__more">En savoir plus</a>
                </div>
                <span class="map-frame__label" aria-hidden="true">
                    <span class="map-frame__dot"></span> <?= e(GTC_NAME) ?>
                </span>
            </div>

            <ul class="map-panel__list">
                <li>
                    <span class="map-panel__icon"><?= icon('pin') ?></span>
                    <span>
                        <strong><?= e(GTC_ADDRESS_SHORT) ?></strong>
                        <small>1.229 Paul Soppo Priso</small>
                    </span>
                </li>
                <li>
                    <span class="map-panel__icon"><?= icon('phone') ?></span>
                    <span>
                        <a href="tel:<?= e(GTC_PHONE_LINK) ?>"><strong><?= e(GTC_PHONE) ?></strong></a>
                        <small><a href="tel:<?= e(GTC_PHONE_2_LINK) ?>"><?= e(GTC_PHONE_2) ?></a></small>
                    </span>
                </li>
                <li>
                    <span class="map-panel__icon"><?= icon('mail') ?></span>
                    <span>
                        <a href="mailto:<?= e(GTC_EMAIL) ?>"><strong><?= e(GTC_EMAIL) ?></strong></a>
                    </span>
                </li>
            </ul>

            <?php if (social_available(array_keys(SOCIAL_NETWORKS))): ?>
                <div class="map-panel__social">
                    <p class="map-panel__social-title">Suivez-nous</p>
                    <?= social_links(['facebook', 'instagram', 'linkedin', 'youtube', 'twitter'], 'social--contact') ?>
                </div>
            <?php endif; ?>

            <div class="map-panel__actions">
                <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= $mapQuery ?>"
                   class="btn btn--primary btn--sm" target="_blank" rel="noopener">
                    Ouvrir dans Maps <?= icon('external') ?>
                </a>
                <a href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= $mapQuery ?>"
                   class="btn btn--outline btn--sm" target="_blank" rel="noopener">
                    Itinéraire <?= icon('compass') ?>
                </a>
            </div>
        </aside>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
