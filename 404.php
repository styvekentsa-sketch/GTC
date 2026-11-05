<?php
/**
 * Page introuvable (erreur 404) — affichée par le serveur pour toute adresse inexistante (.htaccess : ErrorDocument).
 * Liens et fichiers en chemins absolus (site_root()) : la page s'affiche correctement quelle que soit la profondeur
 * de l'adresse demandée (/dossier/page-inexistante). Non indexée par les moteurs de recherche.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/seo.php';

http_response_code(404);
$root            = site_root();   // « / » : chemins absolus
$pageTitle       = 'Page introuvable';
$seoTitle        = 'Page introuvable | ' . GTC_NAME;
$pageDescription = "La page demandée n'existe pas ou a été déplacée.";
$robots          = 'noindex, follow';
$currentPage     = '';

include __DIR__ . '/includes/header.php';

$links = [
    ['index.php', 'globe', 'Accueil', "Retour à la page d'accueil"],
    ['services.php', 'exchange', 'Nos services', 'Change, transfert, fret et négoce'],
    ['taux.php', 'calc', 'Taux du jour', 'Notre grille de change en FCFA'],
    ['suivi.php', 'ship', 'Suivi colis', 'Où en est votre marchandise ?'],
    ['faq.php', 'quote', 'Questions fréquentes', 'Les réponses avant de venir'],
    ['contact.php', 'pin', 'Contact', 'Adresse, horaires et formulaire'],
];
?>

<section class="section section--white notfound">
    <div class="container notfound__inner">
        <p class="notfound__code" aria-hidden="true">404</p>
        <span class="section__eyebrow">Page introuvable</span>
        <h1 class="notfound__title">Cette page n'existe pas ou a été déplacée</h1>
        <p class="notfound__lead">L'adresse est peut-être mal saisie, ou le lien que vous avez suivi est ancien.
            Voici les pages les plus consultées :</p>

        <ul class="notfound__links">
            <?php foreach ($links as [$href, $ico, $title, $text]): ?>
                <li>
                    <a href="<?= e($root . $href) ?>" class="notfound__link">
                        <span class="card__icon"><?= icon($ico) ?></span>
                        <span><strong><?= e($title) ?></strong><small><?= e($text) ?></small></span>
                        <?= icon('arrow') ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="notfound__help">Vous cherchiez autre chose ? Écrivez-nous sur
            <a href="https://wa.me/<?= e(GTC_WHATSAPP) ?>" target="_blank" rel="noopener">WhatsApp</a> ou appelez le
            <a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a>.</p>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
