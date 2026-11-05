<?php
/**
 * Questions fréquentes — Global Trading Corporation
 *
 * Réponses tirées des informations déjà publiées sur le site (services, CGU, page Suivi, calculateur, agence) :
 * aucun tarif, délai ni montant n'est promis ici — ils sont confirmés par un conseiller avant chaque opération (CGU § 3).
 * Recherche et filtre par thème : assets/js/main.js § 7 (sans JavaScript, toutes les questions restent lisibles).
 * Données structurées FAQPage (JSON-LD) pour les moteurs de recherche.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';   // wa_link() et images (en-tête, pied de page)

// ---------- Méta & données de la page ----------
$pageTitle       = 'Questions fréquentes';
$seoTitle        = 'FAQ : change, transfert d\'argent et fret | GTC Douala';
$pageDescription = 'Questions fréquentes : taux de change, transferts d\'argent, documents à fournir, suivi de colis, fret, horaires et sécurité de l\'agence GTC à Douala.';
$currentPage     = 'faq';

include __DIR__ . '/includes/header.php';

// e() vient de includes/header.php : les réponses sont construites après son inclusion
$hours = 'du lundi au vendredi de 08h à 17h30 et le samedi de 09h à 14h';
$tel   = '<a href="tel:' . e(GTC_PHONE_LINK) . '">' . e(GTC_PHONE) . '</a>';
$wa    = '<a href="https://wa.me/' . e(GTC_WHATSAPP) . '" target="_blank" rel="noopener">WhatsApp (' . e(GTC_WHATSAPP_DISPLAY) . ')</a>';

// Thème => [libellé, icône, [[question, réponse HTML], …]]
$faq = [
    'change' => ['Change de devises', 'exchange', [
        ['Quelles devises pouvez-vous changer ?',
         "<p>Sept devises : l'euro (EUR), le dollar américain (USD), le dollar canadien (CAD), le franc CFA (FCFA), le naira (NGN), le dirham des Émirats (AED) et le yuan (CNY).</p>"
         . "<p>La disponibilité de chacune au guichet est indiquée sur la page <a href=\"services.php#devises\">Services</a> ; pour une devise « sur demande », contactez-nous avant de vous déplacer.</p>"],
        ['Comment le taux de change est-il fixé ?',
         "<p>Nous appliquons le taux du jour de notre grille, <strong>annoncé clairement avant chaque opération</strong> : vous savez exactement ce que vous recevrez avant de valider.</p>"
         . "<p>Les taux affichés sur le site et les résultats du simulateur sont indicatifs ; le taux applicable est confirmé par nos conseillers au moment de l'opération.</p>"],
        ['Puis-je connaître à l\'avance le montant que je vais recevoir ?',
         "<p>Oui : le <a href=\"services.php#simulateur\" class=\"open-calculator\">simulateur de change</a> calcule une estimation instantanée à partir de notre grille du jour. Pour une confirmation, envoyez-nous votre montant sur {$wa}.</p>"],
        ['Quels documents dois-je apporter ?',
         "<p>Munissez-vous d'une <strong>pièce d'identité en cours de validité</strong>. Selon le montant et la nature de l'opération, d'autres justificatifs peuvent être demandés, conformément à la réglementation en vigueur. En cas de doute, appelez-nous au {$tel} avant de venir.</p>"],
    ]],
    'transfert' => ['Transfert d\'argent', 'transfer', [
        ['Vers quels pays pouvez-vous envoyer de l\'argent ?',
         "<p>Nous traitons les transferts entre le Cameroun et l'<strong>Afrique, l'Europe, l'Amérique et l'Asie</strong> (notamment la Chine, Dubaï et l'Inde), grâce à nos partenaires au Cameroun et à l'étranger.</p>"],
        ['Le bénéficiaire doit-il avoir un compte bancaire ?',
         "<p>Non. Les fonds peuvent être reçus par <strong>virement bancaire ou en espèces</strong> : vous pouvez envoyer ou recevoir de l'argent même sans compte bancaire à l'étranger.</p>"],
        ['Combien coûte un transfert et en combien de temps arrive-t-il ?',
         "<p>Les frais et le délai dépendent du pays, du montant et du mode de remise. Ils vous sont <strong>communiqués et confirmés avant toute opération</strong> : aucune surprise une fois le dépôt effectué.</p>"
         . "<p>Chaque dépôt est traité rapidement, et le bénéficiaire est prévenu par appel dès le dépôt.</p>"],
        ['Comment puis-je payer ?',
         "<p>Au guichet, en <strong>espèces</strong>, par <strong>Orange Money</strong>, <strong>Mobile Money</strong> ou par <strong>virement</strong>. Le mode de paiement figure sur votre reçu.</p>"],
        ['Vais-je recevoir un justificatif ?',
         "<p>Oui. Chaque opération est enregistrée avec une <strong>référence unique</strong> et donne lieu à un reçu de caisse, imprimé ou envoyé en PDF. Conservez-le : il sert de preuve et de référence en cas de question.</p>"],
    ]],
    'fret' => ['Transit, fret et suivi', 'ship', [
        ['Comment suivre mon colis ou mon conteneur ?',
         "<p>Sur la page <a href=\"suivi.php\">Suivi colis</a>, saisissez le <strong>code de suivi</strong> remis par notre agence (format GTC-XXXX-XXXX) ou votre numéro de B/L, de LTA ou de conteneur. Vous voyez l'étape en cours : embarquement, en mer ou en vol, arrivée à Douala, douane, disponible en agence.</p>"],
        ['Comment estimer le volume et le coût de mon envoi ?',
         "<p>Le <a href=\"calculateur-cbm.php\">calculateur CBM</a> calcule le volume (m³) et le poids volumétrique de vos colis, ligne par ligne, et donne une estimation du fret maritime ou aérien. Le prix définitif est établi sur devis par notre équipe.</p>"],
        ['Où et comment récupérer ma marchandise ?',
         "<p>Dès que votre dossier passe à l'étape <strong>« Disponible en agence »</strong>, le lieu de retrait s'affiche sur la page de suivi. Présentez-vous avec une <strong>pièce d'identité</strong> et votre <strong>code de suivi</strong>.</p>"],
        ['Quels services proposez-vous pour l\'import-export ?',
         "<p>Accompagnement à l'importation et à l'exportation, règlement de vos fournisseurs à l'étranger, <a href=\"services.php#transit\">transit et dédouanement</a>, agent maritime, acconage, consignation et négoce. Chaque projet fait l'objet d'un devis.</p>"],
    ]],
    'agence' => ['Agence et sécurité', 'store', [
        ['Où se trouve votre agence et quels sont vos horaires ?',
         '<p>Notre guichet se trouve au <strong>' . e(GTC_ADDRESS) . "</strong>. Il est ouvert {$hours}. Le plan d'accès est sur la page <a href=\"contact.php\">Contact</a>.</p>"],
        ['Mes informations sont-elles confidentielles ?',
         "<p>Oui. Vos informations et le détail de vos opérations ne sont <strong>jamais communiqués à des tiers</strong>. Chaque dépôt et chaque retrait sont enregistrés et traçables, et vous traitez avec une équipe identifiée, dans un bureau ouvert au public depuis 2020.</p>"
         . "<p>Pour en savoir plus : <a href=\"legal/politique-confidentialite.php\">politique de confidentialité</a>.</p>"],
        ['Comment vous contacter rapidement ?',
         "<p>Par téléphone au {$tel}, sur {$wa} ou avec le <a href=\"contact.php#formulaire\">formulaire de contact</a>. Pour un change ou un transfert, indiquez la devise et le montant : nous vous répondons avec le taux du jour.</p>"],
    ]],
];

// Données structurées FAQPage : réponses en texte simple
$jsonLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
foreach ($faq as [, , $items]) {
    foreach ($items as [$q, $a]) {
        $jsonLd['mainEntity'][] = ['@type' => 'Question', 'name' => $q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('</p><p>', '</p> <p>', $a)), ENT_QUOTES, 'UTF-8')))]];
    }
}
$total = count($jsonLd['mainEntity']);


$hero = [
    'crumb'   => 'FAQ',
    'image'   => 'desk',   // photo de la bannière (bureau, téléphone et ordinateur : service client) — GTC_IMAGES, includes/media.php
    'eyebrow' => 'Aide',
    'title'   => 'Questions fréquentes',
    'lead'    => "Change, transferts, fret, documents, horaires : l'essentiel avant de venir au guichet ou de nous écrire.",
];
include __DIR__ . '/includes/hero.php';
echo seo_jsonld($jsonLd);
?>

<section class="section section--white section--tight">
    <div class="container faq" data-faq>

        <!-- Recherche et thèmes (assets/js/main.js § 7) -->
        <div class="faq__tools" data-faq-tools hidden>
            <div class="faq__search">
                <?= icon('search') ?>
                <label class="visually-hidden" for="faq-q">Rechercher une question</label>
                <input id="faq-q" type="search" class="form-control" placeholder="Rechercher : taux, documents, suivi, horaires…" autocomplete="off" data-faq-search>
            </div>
            <div class="faq__filters" role="group" aria-label="Thèmes">
                <button type="button" class="faq__filter" data-faq-filter="" aria-pressed="true">Tout <span><?= $total ?></span></button>
                <?php foreach ($faq as $key => [$label, $ico, $items]): ?>
                    <button type="button" class="faq__filter" data-faq-filter="<?= e($key) ?>" aria-pressed="false"><?= icon($ico) ?> <?= e($label) ?> <span><?= count($items) ?></span></button>
                <?php endforeach; ?>
            </div>
            <p class="faq__count" data-faq-count aria-live="polite"></p>
        </div>

        <div class="faq__layout">
            <div class="faq__groups">
                <?php foreach ($faq as $key => [$label, $ico, $items]): ?>
                    <section class="faq-group" data-faq-group="<?= e($key) ?>" aria-labelledby="faq-<?= e($key) ?>">
                        <h2 class="faq-group__title" id="faq-<?= e($key) ?>"><span class="faq-group__icon"><?= icon($ico) ?></span> <?= e($label) ?></h2>
                        <?php foreach ($items as $i => [$q, $a]): ?>
                            <details class="faq-item" id="<?= e($key) ?>-<?= $i + 1 ?>" data-faq-item>
                                <summary class="faq-item__q"><span><?= e($q) ?></span><span class="faq-item__toggle" aria-hidden="true"></span></summary>
                                <div class="faq-item__a"><?= $a ?></div>
                            </details>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
                <p class="faq__empty" data-faq-empty hidden>Aucune question ne correspond à votre recherche. Posez-la-nous directement : nous vous répondons rapidement.</p>
            </div>

            <!-- Encadré de contact -->
            <aside class="faq-help">
                <span class="faq-help__icon"><?= icon('whatsapp') ?></span>
                <h2 class="faq-help__title">Vous ne trouvez pas votre réponse ?</h2>
                <p>Notre équipe vous répond <?= e($hours) ?>.</p>
                <a href="https://wa.me/<?= e(GTC_WHATSAPP) ?>?text=<?= e(rawurlencode('Bonjour GTC, j\'ai une question :')) ?>" class="btn btn--accent btn--block" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Poser ma question sur WhatsApp</a>
                <a href="tel:<?= e(GTC_PHONE_LINK) ?>" class="btn btn--outline btn--block"><?= icon('phone') ?> <?= e(GTC_PHONE) ?></a>
                <a href="contact.php#formulaire" class="faq-help__link">Ou écrivez-nous via le formulaire <?= icon('arrow') ?></a>
            </aside>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
