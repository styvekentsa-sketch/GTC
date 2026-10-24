<?php
/**
 * Impression d'une facture / bordereau de transaction.
 *
 * - Page autonome : aucun menu du Dashboard ; à l'impression, seule la facture apparaît.
 * - Imprimante cible : ?printer_id=Y (choisie à la création de la facture), sinon celle enregistrée
 *   avec la facture, sinon l'imprimante par défaut de l'agence. Son nom est affiché dans la barre d'outils
 *   et son format de papier (A4, A5, ticket) est appliqué automatiquement.
 * - Formats : ?format=a4, ?format=a5 ou ?format=ticket (imprimante de caisse 80 mm) pour forcer un format.
 * - Double souche : en A4, deux exemplaires identiques sur la même feuille (« Exemplaire client » en haut,
 *   « Exemplaire caisse / archive » en bas) séparés par une ligne de découpe placée au milieu de la page.
 *   Le ticket 80 mm imprime aussi les deux exemplaires à la suite ; l'A5 reste un exemplaire unique.
 * - Envoi direct : si l'imprimante choisie l'accepte (adresse IP + RAW 9100 / IPP 631 + option activée),
 *   la facture est envoyée au périphérique par le serveur (printers-api.php) sans boîte de dialogue ;
 *   sinon, ou en cas d'échec, la boîte d'impression du navigateur est utilisée.
 * - « Actualiser / Détecter » : état des imprimantes en temps réel (assets/js/printers.js).
 * - La boîte de dialogue d'impression s'ouvre automatiquement à l'arrivée sur la page
 *   (imprimante par défaut du poste : réseau ou locale). ?autoprint=0 la désactive
 *   (utilisé lors d'un simple changement de format).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/printers.php';
require_once __DIR__ . '/../includes/print-direct.php';

$user = require_login();
require_roles(OPERATION_ROLES);   // pas d'accès pour le rôle Secrétaire / Accueil

/** Nom de l'entreprise aux couleurs du logo : 1er mot bleu, 2e mot vert, suite en bleu (« Global Trading Corporation »). */
function receipt_brand_name(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    if (count($words) < 2) {
        return e($name);
    }
    $html = [];
    foreach ($words as $i => $word) {
        $html[] = '<span class="receipt__brand--' . ($i === 1 ? 'green' : 'blue') . '">' . e($word) . '</span>';
    }
    return implode(' ', $html);
}

$id      = (int) ($_GET['id'] ?? 0);
$company = settings_all();
$auto    = ($_GET['autoprint'] ?? '1') !== '0';

$inv = $id > 0 ? db_one('SELECT * FROM invoices WHERE id = ?', [$id]) : null;
if (!$inv) {
    http_response_code(404);
}

// Imprimante cible : choisie dans l'URL, sinon celle de la facture, sinon celle par défaut de l'agence
$printer = printer_find((int) ($_GET['printer_id'] ?? 0))
        ?? ($inv ? printer_find((int) $inv['printer_id']) : null)
        ?? printer_default();
$printers = printers_list(true);

// Format : choisi dans la barre d'outils, sinon celui de l'imprimante, sinon celui des Paramètres
$format = in_array($_GET['format'] ?? '', ['a4', 'a5', 'ticket'], true)
    ? $_GET['format']
    : ($printer && isset(PRINTER_FORMATS[$printer['paper_format']]) ? $printer['paper_format'] : settings_print_format());

// Exemplaires imprimés : double souche en A4 et en ticket, exemplaire unique en A5
$copies = print_copies($format);

$direct = $inv && printer_is_direct($printer);

$pageUrl = fn (array $params) => 'print-invoice.php?' . http_build_query(array_filter($params + ['id' => $id, 'printer_id' => $printer['id'] ?? null], fn ($v) => $v !== null));
$formatUrl = fn (string $f) => $pageUrl(['format' => $f, 'autoprint' => 0]);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $inv ? e($inv['invoice_number']) . ' — ' : 'Reçu introuvable — ' ?><?= e($company['company_name']) ?></title>
    <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/custom-select.css', '../')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/invoice-print.css', '../')) ?>">
</head>
<body class="print-page print-page--<?= e($format) ?>"<?= $inv && $auto ? ' data-autoprint="1"' : '' ?><?= $printer ? ' data-printer="' . e($printer['printer_name']) . '" data-printer-id="' . (int) $printer['id'] . '"' : '' ?>
      <?= $direct ? ' data-direct="1" data-api="printers-api.php" data-invoice="' . $id . '" data-csrf="' . e(csrf_token()) . '"' : '' ?>>

<!-- ==================== BARRE D'OUTILS (écran uniquement) ==================== -->
<nav class="print-toolbar" aria-label="Actions"<?= $inv && $printers ? ' data-printer-picker data-compact data-api="printers-api.php"' : '' ?>>
    <a href="invoices.php" class="pt-btn" data-href="invoices.php"><?= icon('arrow-left') ?> Retour aux reçus</a>
    <?php if ($inv): ?>
        <div class="pt-group" role="group" aria-label="Format de papier">
            <a href="<?= e($formatUrl('a4')) ?>" class="pt-btn<?= $format === 'a4' ? ' is-active' : '' ?>">A4</a>
            <a href="<?= e($formatUrl('a5')) ?>" class="pt-btn<?= $format === 'a5' ? ' is-active' : '' ?>">A5</a>
            <a href="<?= e($formatUrl('ticket')) ?>" class="pt-btn<?= $format === 'ticket' ? ' is-active' : '' ?>">Ticket 80 mm</a>
        </div>
        <?php if ($printers): ?>
            <form method="get" action="print-invoice.php" class="pt-printer">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="autoprint" value="0">
                <label for="pt-printer"><?= icon('printer') ?> Imprimante cible</label>
                <select id="pt-printer" name="printer_id" data-autosubmit>
                    <?php foreach ($printers as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($printer['id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['printer_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="pt-btn pt-btn--icon" data-printer-refresh title="Actualiser / Détecter les imprimantes" aria-label="Actualiser / Détecter les imprimantes"><?= icon('refresh') ?></button>
                <noscript><button type="submit" class="pt-btn">OK</button></noscript>
            </form>
        <?php endif; ?>
        <button type="button" class="pt-btn pt-btn--primary" data-print><?= $direct ? "Envoyer à l'imprimante" : 'Imprimer à nouveau' ?></button>
        <?php if ($direct): ?><button type="button" class="pt-btn" data-print-browser>Boîte d'impression</button><?php endif; ?>
        <?php $pdfUrl = 'pdf.php?type=receipt&id=' . (int) $inv['id'] . '&format=' . ($format === 'a4' ? 'a4' : 'a5'); ?>
        <!-- PDF téléchargé directement (téléphones : contourne les limites d'impression de Chrome Android et Safari iOS) -->
        <a href="<?= e($pdfUrl) ?>" class="pt-btn pt-btn--pdf" download><?= icon('download') ?> Télécharger le PDF</a>
        <button type="button" class="pt-btn" data-pdf-share data-pdf-url="<?= e($pdfUrl) ?>" data-pdf-title="Reçu <?= e($inv['invoice_number']) ?>" hidden><?= icon('share') ?> Partager</button>
        <?php if ($printers): ?><p class="printer-picker__status pt-printer__status" data-printer-status aria-live="polite"></p><?php endif; ?>
    <?php endif; ?>
</nav>

<?php if ($inv && $printer): ?>
    <!-- Rappel de l'imprimante cible (écran uniquement) -->
    <p class="print-target" role="status">
        <?php if ($direct): ?>
            Envoi direct sur <strong><?= e($printer['printer_name']) ?></strong> (<?= e($printer['printer_location']) ?>, <?= e($printer['host']) ?>)
            · <?= e(PRINTER_FORMATS[$printer['paper_format']] ?? '') ?><?= $printer['paper_format'] !== 'a5' ? ' · 2 exemplaires (client + caisse)' : '' ?> — aucune boîte de dialogue.
        <?php else: ?>
            Sortie prévue sur <strong><?= e($printer['printer_name']) ?></strong> (<?= e($printer['printer_location']) ?>)
            · <?= e(PRINTER_FORMATS[$format]) ?><?= count($copies) > 1 ? ' · 2 exemplaires (client + caisse)' : '' ?>.
            Dans la fenêtre d'impression, vérifiez que cette imprimante est sélectionnée.
        <?php endif; ?>
    </p>
    <p class="print-direct" data-direct-status role="status" hidden></p>
<?php endif; ?>

<?php if (!$inv): ?>
    <!-- ==================== FACTURE INTROUVABLE ==================== -->
    <main class="receipt receipt--error" role="alert">
        <p class="receipt__label">Erreur</p>
        <h1 class="receipt__number">Reçu introuvable</h1>
        <p>Le reçu demandé n'existe pas ou a été supprimée<?= $id > 0 ? ' (référence n° ' . $id . ')' : '' ?>.</p>
        <p>Vérifiez le lien ou retrouvez-le dans la liste des reçus.</p>
        <p><a href="invoices.php?action=list" class="pt-btn pt-btn--primary">Voir tous les reçus</a></p>
    </main>
<?php else: ?>
    <?php
    $operation = OPERATION_TYPES[$inv['operation_type']] ?? $inv['operation_type'];
    $payment   = PAYMENT_METHODS[$inv['payment_method']] ?? $inv['payment_method'];
    $issuedAt  = fdate($inv['created_at'] ?: $inv['issue_date'], true);
    $legal     = array_filter([GTC_RCCM ? 'RCCM : ' . GTC_RCCM : '']);
    $niu       = company_niu();
    ?>
    <?php
    /** Un exemplaire de la facture ; $copy : 'client', 'caisse' ou null (exemplaire unique). */
    $receipt = function (?string $copy, ?string $copyLabel) use ($inv, $company, $operation, $payment, $issuedAt, $legal, $niu, $user): void {
        ?>
        <article class="receipt<?= $copy ? ' receipt--' . $copy : '' ?>"<?= $copy === 'caisse' ? ' aria-hidden="true"' : '' ?>>
            <!-- En-tête de l'entreprise -->
            <header class="receipt__head">
                <img class="receipt__logo" src="<?= e(settings_logo_url('../')) ?>" alt="<?= e($company['company_name']) ?>" width="70" height="70">
                <div class="receipt__company">
                    <p class="receipt__name"><?= receipt_brand_name($company['company_name']) ?></p>
                    <p><?= e($company['company_address']) ?></p>
                    <p>Tél. <?= e($company['company_phone']) ?><?= $company['company_phone2'] !== '' ? ' · ' . e($company['company_phone2']) : '' ?></p>
                    <?php if ($niu !== ''): ?><p class="receipt__niu">NIU : <?= e($niu) ?></p><?php endif; ?>
                    <p><?= e(implode(' | ', array_filter([$company['company_email'], $company['company_website']]))) ?></p>
                </div>
                <?php if ($copyLabel): ?><p class="receipt__copy"><?= e($copyLabel) ?></p><?php endif; ?>
            </header>

            <!-- Informations de la transaction -->
            <section class="receipt__ref">
                <div>
                    <p class="receipt__label">Reçu de caisse</p>
                    <p class="receipt__number"><?= e($inv['invoice_number']) ?></p>
                </div>
                <dl class="receipt__meta">
                    <div><dt>Émise le</dt><dd><?= e($issuedAt) ?></dd></div>
                    <div><dt>Agent responsable</dt><dd><?= e($inv['agent_name']) ?></dd></div>
                </dl>
            </section>

            <section class="receipt__block">
                <p class="receipt__label">Client</p>
                <p class="receipt__client receipt__clamp receipt__clamp--2"><?= e($inv['client_name']) ?></p>
                <?php if ($inv['client_phone']): ?><p class="receipt__phone">Tél. / WhatsApp : <?= e($inv['client_phone']) ?></p><?php endif; ?>
            </section>

            <!-- Tableau récapitulatif -->
            <table class="receipt__table">
                <tbody>
                    <tr><th scope="row">Type d'opération</th><td><?= e($operation) ?></td></tr>
                    <tr><th scope="row">Mode de paiement</th><td><?= e($payment) ?></td></tr>
                    <tr><th scope="row">Motif</th><td><span class="receipt__clamp receipt__clamp--2"><?= e(invoice_reason_text($inv)) ?></span></td></tr>
                </tbody>
                <tfoot>
                    <tr class="receipt__total"><th scope="row">Montant net à remettre</th><td><?= e(money($inv['amount_received'], $inv['target_currency'])) ?></td></tr>
                </tfoot>
            </table>

            <!-- Signatures -->
            <section class="receipt__signatures">
                <div>
                    <p class="receipt__label">Signature du Client</p>
                    <span class="receipt__sign"></span>
                </div>
                <div>
                    <p class="receipt__label">Cachet / Signature Agent</p>
                    <span class="receipt__sign receipt__sign--stamp"></span>
                </div>
            </section>

            <!-- Pied de page -->
            <footer class="receipt__foot">
                <p class="receipt__thanks"><?= $copy === 'caisse'
                    ? 'Exemplaire caisse — à classer avec les pièces comptables du jour.'
                    : 'Merci pour votre confiance. Gardez ce reçu comme justificatif.' ?></p>
                <p>Ce document atteste la transaction réalisée à la date indiquée au taux confirmé lors de l'opération.
                   <?= e($company['company_name']) ?> — <?= e($company['company_address']) ?>.<?= $legal ? ' ' . e(implode(' · ', $legal)) . '.' : '' ?></p>
                <p class="receipt__printed">Imprimé le <?= e(date('d/m/Y à H:i')) ?> par <?= e($user['name']) ?></p>
            </footer>
        </article>
        <?php
    };
    ?>
    <!-- ==================== FACTURE (double souche en A4 et ticket) ==================== -->
    <main class="receipt-sheet receipt-sheet--<?= e($format) ?><?= count($copies) > 1 ? ' receipt-sheet--double' : '' ?>">
        <?php $first = true; foreach ($copies as $copy => $label): ?>
            <?php if (!$first): ?>
                <div class="cut-line" aria-hidden="true"><span><?= icon('scissors') ?> Ligne de découpe</span></div>
            <?php endif; ?>
            <?php $receipt($copy ?: null, $label); $first = false; ?>
        <?php endforeach; ?>
    </main>
<?php endif; ?>

<script src="<?= e(asset_url('assets/js/custom-select.js', '../')) ?>"></script>
<script src="<?= e(asset_url('assets/js/printers.js', '../')) ?>"></script>
<script src="<?= e(asset_url('assets/js/pdf-share.js', '../')) ?>" defer></script>
<script>
    // Impression automatique à l'arrivée sur la page (une fois le logo chargé) :
    // envoi direct à l'imprimante si elle l'accepte, sinon boîte d'impression du navigateur
    if (document.body.hasAttribute('data-autoprint')) {
        window.addEventListener('load', function () {
            setTimeout(function () { window.GTCPrinters.print(); }, 300);
        });
    }
    document.querySelectorAll('[data-print-browser]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });
    // Changement d'imprimante cible : rechargement avec son format de papier
    document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () { select.form.submit(); });
    });
    // « Imprimer à nouveau » et « Retour aux reçus »
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.GTCPrinters.print(); });
    });
    document.querySelectorAll('[data-href]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            window.location.href = link.getAttribute('data-href');
        });
    });
</script>
</body>
</html>
