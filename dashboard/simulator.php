<?php
/**
 * Simulateur avancé du guichet : change, transfert, import/export & fret.
 *
 * Calcul en temps réel (assets/js/simulator.js) :
 *   frais           = montant envoyé × % ou montant fixe (en devise source)
 *   net à convertir = montant envoyé − frais
 *   montant reçu    = net × taux (taux « 1 source = X cible ») ou net ÷ taux (« 1 cible = X source »)
 * Le calcul inverse (montant souhaité à la réception → montant à verser) est également disponible.
 * Le bouton « Créer le bordereau » ouvre invoices.php avec tous les champs pré-remplis.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';

$user = require_login();
require_roles(OPERATION_ROLES);   // pas d'accès pour le rôle Secrétaire / Accueil

// Taux de la grille de l'entreprise (Dashboard → Taux de change), commission par défaut
$rates      = company_rates_js();
$rateSource = 'la grille GTC du ' . company_rates_updated_label();
$commission = str_replace('.', ',', setting('commission_percent', '0'));

$tabs = [
    'Exchange'      => ['icon' => 'exchange', 'label' => 'Change de devises',   'hint' => 'Achat ou vente de billets au guichet'],
    'Transfert'     => ['icon' => 'transfer', 'label' => 'Transfert de fonds',  'hint' => "Envoi ou réception d'argent"],
    'Import/Export' => ['icon' => 'box', 'label' => 'Import / Export & Fret', 'hint' => 'Règlement fournisseurs, fret'],
];

$currencyOptions = function (string $selected): string {
    $html = '';
    foreach (DASH_CURRENCIES as $code => $c) {
        $html .= '<option value="' . e($code) . '"' . ($code === $selected ? ' selected' : '') . '>'
               . e(currency_label($code)) . ' — ' . e($c['label']) . '</option>';
    }
    return $html;
};

dash_header('Simulateur Avancé', 'simulator');
?>

<div class="sim" id="simulator"
     data-rates="<?= e(json_encode($rates)) ?>"
     data-decimals="<?= e(json_encode(array_map(fn ($c) => $c['decimals'], DASH_CURRENCIES))) ?>"
     data-source="<?= e($rateSource) ?>">

    <!-- ==================== TYPE D'OPÉRATION ==================== -->
    <nav class="sim-tabs" role="tablist" aria-label="Type d'opération">
        <?php $first = true; foreach ($tabs as $key => $t): ?>
            <button type="button" class="sim-tab<?= $first ? ' is-active' : '' ?>" role="tab"
                    aria-selected="<?= $first ? 'true' : 'false' ?>" data-operation="<?= e($key) ?>">
                <span class="sim-tab__icon" aria-hidden="true"><?= icon($t['icon']) ?></span>
                <span class="sim-tab__text"><strong><?= e($t['label']) ?></strong><small><?= e($t['hint']) ?></small></span>
            </button>
        <?php $first = false; endforeach; ?>
    </nav>

    <div class="sim-grid">
        <!-- ==================== PARAMÈTRES ==================== -->
        <section class="panel sim-panel">
            <div class="sim-mode" id="sim-mode" role="group" aria-label="Sens du change">
                <button type="button" class="sim-mode__btn" data-mode="buy">Achat <small>le client vend des devises</small></button>
                <button type="button" class="sim-mode__btn is-active" data-mode="sell">Vente <small>le client achète des devises</small></button>
            </div>

            <div class="form-grid form-grid--2">
                <div class="field">
                    <label for="sim-source">Devise remise par le client</label>
                    <select id="sim-source"><?= $currencyOptions('XAF') ?></select>
                </div>
                <div class="field">
                    <label for="sim-target">Devise reçue</label>
                    <select id="sim-target"><?= $currencyOptions('EUR') ?></select>
                </div>
            </div>

            <div class="field">
                <label for="sim-rate">Taux de change du jour</label>
                <div class="input-action">
                    <input id="sim-rate" inputmode="decimal" autocomplete="off">
                    <select id="sim-direction" class="sim-direction" aria-label="Sens du taux">
                        <option value="multiply">1 source = taux cible (×)</option>
                        <option value="divide">1 cible = taux source (÷)</option>
                    </select>
                    <button type="button" class="btn btn--ghost btn--sm" id="sim-rate-suggest" title="Reprendre le taux de la grille de l'entreprise (Taux de change)">Taux GTC</button>
                </div>
                <small class="field__hint" id="sim-rate-hint"></small>
            </div>

            <div class="field">
                <label for="sim-fee">Commission / frais de guichet</label>
                <div class="input-action">
                    <input id="sim-fee" inputmode="decimal" autocomplete="off" value="<?= e($commission) ?>">
                    <div class="seg" role="group" aria-label="Type de frais">
                        <button type="button" class="seg__btn is-active" data-fee-type="percent">%</button>
                        <button type="button" class="seg__btn" data-fee-type="fixed" id="sim-fee-fixed-label">Montant</button>
                    </div>
                </div>
                <small class="field__hint" id="sim-fee-hint">Pourcentage du montant envoyé.</small>
            </div>

            <!-- Calcul bidirectionnel -->
            <div class="sim-amounts">
                <div class="field">
                    <label for="sim-send">Montant envoyé <span class="sim-cur" data-cur="source">EUR</span></label>
                    <input id="sim-send" class="sim-amount" inputmode="decimal" autocomplete="off" placeholder="0">
                </div>
                <button type="button" class="sim-swap" id="sim-swap" title="Inverser les devises" aria-label="Inverser les devises"><?= icon('swap') ?></button>
                <div class="field">
                    <label for="sim-receive">Montant à recevoir <span class="sim-cur" data-cur="target">FCFA</span></label>
                    <input id="sim-receive" class="sim-amount" inputmode="decimal" autocomplete="off" placeholder="0">
                </div>
            </div>
            <p class="field__hint">Saisissez l'un ou l'autre : le second montant se calcule instantanément.</p>
        </section>

        <!-- ==================== RÉSULTAT ==================== -->
        <aside class="panel sim-result" aria-live="polite">
            <h2 class="panel__title">Récapitulatif</h2>
            <dl class="sim-lines">
                <div><dt>Sous-total (montant envoyé)</dt><dd id="r-subtotal">—</dd></div>
                <div><dt>Frais de guichet <span id="r-fee-rate"></span></dt><dd id="r-fee">—</dd></div>
                <div><dt>Net à convertir</dt><dd id="r-net-source">—</dd></div>
                <div><dt>Taux appliqué</dt><dd id="r-rate">—</dd></div>
            </dl>
            <div class="sim-final">
                <span>Montant Net Final</span>
                <strong id="r-final">—</strong>
            </div>
            <a href="invoices.php" class="btn btn--primary btn--lg btn--block" id="sim-to-invoice" aria-disabled="true">
                <?= icon('file') ?> Créer le reçu
            </a>
            <p class="field__hint sim-result__hint">Ouvre la saisie du reçu de caisse avec tous les champs pré-remplis.</p>
        </aside>
    </div>

    <!-- ==================== COMPARATIF MULTI-DEVISES ==================== -->
    <section class="panel">
        <header class="panel__head">
            <h2 class="panel__title">Comparatif multi-devises</h2>
            <span class="cell-sub" id="cmp-note"></span>
        </header>
        <div class="sim-compare" id="sim-compare">
            <p class="empty">Saisissez un montant pour afficher ses équivalents.</p>
        </div>
    </section>
</div>

<script src="<?= e(asset_url('assets/js/simulator.js', site_root())) ?>" defer></script>
<?php dash_footer(); ?>
