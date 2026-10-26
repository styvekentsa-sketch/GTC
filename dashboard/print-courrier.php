<?php
/**
 * Impression A4 d'un courrier officiel sur le papier à en-tête de Global Trading Corporation.
 *
 *   print-courrier.php?id=12[&autoprint=1]
 *
 * Mise en page : expéditeur dans l'en-tête officiel ; références à gauche, lieu et date à droite ; destinataire
 * à droite ; objet et pièces jointes ; appel ; corps justifié ; formule de politesse ; signature à droite.
 * Courrier : includes/courriers.php ; en-tête : includes/letterhead.php ; PDF : pdf.php?type=courrier.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/courriers.php';
require_once __DIR__ . '/../includes/letterhead.php';

$user = require_login();
require_roles(OPERATION_ROLES);
auth_send_headers();

$id      = (int) query('id');
$c       = $id ? courrier_find($id) : null;
$auto    = query('autoprint') === '1';
$company = settings_all();
$name    = $company['company_name'] ?: GTC_NAME;
if ($c) {
    // Variables de publipostage ([Nom de l'entreprise], [Référence]…) dans les champs en texte simple
    foreach (['subject', 'salutation', 'closing'] as $field) {
        $c[$field] = courrier_merge($c[$field], $c, false);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $c ? e($c['reference'] . ' — ' . $c['subject']) : 'Courrier introuvable' ?> | <?= e($name) ?></title>
    <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/commercial-invoice-print.css', '../')) ?>">
</head>
<body>

<nav class="ci-toolbar" aria-label="Actions">
    <a href="courriers.php" class="ci-btn"><?= icon('arrow-left') ?> Retour aux courriers</a>
    <?php if ($c): ?>
        <a href="courriers.php?action=edit&amp;id=<?= (int) $c['id'] ?>" class="ci-btn"><?= icon('edit') ?> Modifier</a>
        <button type="button" class="ci-btn ci-btn--primary" onclick="window.print()"><?= icon('printer') ?> Imprimer</button>
        <!-- PDF téléchargé directement (téléphones : contourne les limites d'impression de Chrome Android et Safari iOS) -->
        <a href="pdf.php?type=courrier&amp;id=<?= (int) $c['id'] ?>" class="ci-btn ci-btn--pdf" download><?= icon('download') ?> Télécharger le PDF</a>
        <button type="button" class="ci-btn" data-pdf-share data-pdf-url="pdf.php?type=courrier&amp;id=<?= (int) $c['id'] ?>" data-pdf-title="Courrier <?= e($c['reference']) ?>" hidden><?= icon('share') ?> Partager</button>
        <p class="ci-hint">Dans la fenêtre d'impression : format <strong>A4</strong>, marges <strong>Aucune</strong>, échelle <strong>100 %</strong>, « Graphiques d'arrière-plan » coché. Le PDF garde exactement la même mise en page.</p>
    <?php endif; ?>
</nav>

<?php if (!$c): ?>
    <main class="ci-sheet ci-sheet--empty">
        <h1>Courrier introuvable</h1>
        <p>Le courrier demandé n'existe pas ou a été supprimé.</p>
        <p><a href="courriers.php" class="ci-btn ci-btn--primary">Voir tous les courriers</a></p>
    </main>
<?php else: ?>
<main class="ci-sheet">

    <?= letterhead_html($name) ?>

    <!-- Zone d'écriture : les cellules d'en-tête et de pied du tableau réservent la place du papier à en-tête sur chaque page -->
    <table class="ci-page" role="presentation">
        <thead><tr><td><div class="ci-page__top"></div></td></tr></thead>
        <tfoot><tr><td><div class="ci-page__bottom"></div></td></tr></tfoot>
        <tbody><tr><td class="ci-page__body">

    <article class="lt">
        <!-- Références (gauche) · lieu et date (droite) -->
        <div class="lt-top">
            <dl class="lt-refs">
                <div><dt>N/Réf. :</dt><dd><?= e($c['reference']) ?></dd></div>
            </dl>
            <p class="lt-date"><?= e($c['place']) ?>, le <?= e(courrier_long_date($c['letter_date'])) ?></p>
        </div>

        <!-- Destinataire (à droite) -->
        <address class="lt-to">
            <?php foreach (courrier_recipient_lines($c) as $i => $line): ?>
                <span class="<?= $i === 0 ? 'lt-to__first' : '' ?>"><?= e($line) ?></span>
            <?php endforeach; ?>
        </address>

        <!-- Objet et pièces jointes -->
        <dl class="lt-object">
            <div><dt>Objet :</dt><dd><?= e($c['subject']) ?></dd></div>
            <?php if ($c['attachments'] !== ''): ?><div class="lt-object__pj"><dt>P.J. :</dt><dd><?= e($c['attachments']) ?></dd></div><?php endif; ?>
        </dl>

        <?php if ($c['salutation'] !== ''): ?><p class="lt-salutation"><?= e($c['salutation']) ?></p><?php endif; ?>

        <!-- Corps en texte enrichi : HTML filtré à l'enregistrement ET ici (includes/courriers.php), variables remplacées -->
        <div class="lt-body"><?= courrier_sanitize_html(courrier_merge(courrier_body_html($c['body']), $c, true)) ?></div>

        <!-- Fin de lettre : formule de politesse + signature, jamais séparées par un saut de page -->
        <div class="lt-end">
            <?php if ($c['closing'] !== ''): ?><p class="lt-closing"><?= nl2br(e($c['closing'])) ?></p><?php endif; ?>
            <!-- Signature (à droite) : qualité, place du cachet et de la signature, nom -->
            <div class="lt-sign">
                <p class="lt-sign__for">Pour <?= e($name) ?>,</p>
                <?php if ($c['signatory_title'] !== ''): ?><p class="lt-sign__title"><?= e($c['signatory_title']) ?></p><?php endif; ?>
                <span class="lt-sign__space" aria-hidden="true"></span>
                <p class="lt-sign__name"><?= e($c['signatory_name']) ?></p>
            </div>
        </div>
    </article>

        </td></tr></tbody>
    </table>
</main>
<?php endif; ?>

<?php if ($c && $auto): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
<script src="<?= e(asset_url('assets/js/pdf-share.js', '../')) ?>" defer></script>
</body>
</html>
