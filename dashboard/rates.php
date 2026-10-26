<?php
/**
 * Taux de change de l'entreprise — grille unique appliquée à tout le site (table exchange_rates).
 *
 * Utilisée par : simulateur public (services.php), simulateur avancé, formulaire de facture,
 * API JSON (api-rates.php, dashboard/rates-api.php). Voir includes/company_rates.php.
 *
 * Accès : consultation pour les comptes des opérations (admin, caissier, agent) ;
 *         modification, import et actualisation des cours du marché : administrateurs uniquement.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';   // grille des taux (includes/company_rates.php)

$user = require_login();
require_roles(OPERATION_ROLES);
$canEdit = is_admin();

/** Nombre saisi (virgule ou point, espaces tolérés). */
function rate_input(string $value): ?float
{
    $raw = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], trim($value));
    return is_numeric($raw) ? (float) $raw : null;
}

/** Valeur affichée dans un champ : 6 décimales au plus, sans zéros inutiles, virgule décimale. */
function rate_field(float $value): string
{
    return str_replace('.', ',', rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.'));
}

$errors = [];

/* ==========================================================================
   Actions (administrateurs)
   ========================================================================== */

if (is_post()) {
    csrf_verify();
    $canEdit || require_role('admin');
    $op = post('op');

    if ($op === 'refresh_market') {
        $market = fx_market(true);
        if ($market && !$market['stale']) {
            flash('success', 'Cours du marché actualisés (publication du ' . fx_updated_label($market) . '). Vos taux ne changent pas : utilisez « Importer » pour les reprendre.');
        } else {
            $cache = is_file(FX_CACHE_FILE) ? json_decode((string) file_get_contents(FX_CACHE_FILE), true) : [];
            flash('error', 'Actualisation impossible : ' . ($cache['last_error'] ?? 'service de taux injoignable') . '.');
        }
        redirect('rates.php');
    }

    if ($op === 'save') {
        $values = [];
        foreach (company_rate_codes() as $code) {
            $buy  = rate_input((string) ($_POST['buy'][$code] ?? ''));
            $sell = rate_input((string) ($_POST['sell'][$code] ?? ''));
            if ($buy === null || $buy <= 0 || $buy > 1e6) {
                $errors["buy_{$code}"] = 'Taux invalide.';
            }
            if ($sell === null || $sell <= 0 || $sell > 1e6) {
                $errors["sell_{$code}"] = 'Taux invalide.';
            }
            if (!isset($errors["buy_{$code}"]) && !isset($errors["sell_{$code}"]) && $buy > $sell) {
                $errors["sell_{$code}"] = "La vente doit être supérieure ou égale à l'achat.";
            }
            $values[$code] = ['buy' => (float) $buy, 'sell' => (float) $sell];
        }
        $margin = rate_input(post('market_margin_percent', '0'));
        if ($margin === null || $margin < 0 || $margin > 20) {
            $errors['market_margin_percent'] = 'Marge invalide (0 à 20 %).';
        }

        if (!$errors) {
            $previous = company_rates();   // pour le journal : ancien → nouveau taux
            $changed = company_rates_save($values, (int) $user['id']);
            if ($changed) {
                $diff = [];
                foreach ($values as $code => $v) {
                    $old = $previous[$code] ?? [];
                    if ((float) ($old['buy'] ?? 0) !== $v['buy'] || (float) ($old['sell'] ?? 0) !== $v['sell']) {
                        $diff[currency_label($code)] = 'achat ' . rate_format((float) ($old['buy'] ?? 0)) . ' → ' . rate_format($v['buy'])
                            . ', vente ' . rate_format((float) ($old['sell'] ?? 0)) . ' → ' . rate_format($v['sell']);
                    }
                }
                activity_log('update', 'rates', null, "Grille des taux ({$changed} modifié" . ($changed > 1 ? 's' : '') . ')',
                    $diff + ['marge marché' => round($margin, 2) . ' %']);
            }
            settings_save(['market_margin_percent' => (string) round($margin, 2), 'rates_updated' => date('Y-m-d')]);
            flash('success', $changed
                ? "{$changed} taux mis à jour et appliqués à tout le site : simulateur public, simulateur avancé et reçus de caisse."
                : 'Aucun taux modifié.');
            redirect('rates.php');
        }
    }
}

/* ==========================================================================
   Affichage
   ========================================================================== */

$rates   = company_rates();
$updated = company_rates_updated();
$market  = fx_market();
$margin  = fx_margin() * 100;
$posted  = is_post() && $errors;

dash_header('Taux de change', 'rates');
?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>Aucun taux n'a été enregistré : corrigez les valeurs indiquées.</p></div>
<?php endif; ?>

<!-- ==================== COURS DU MARCHÉ (RÉFÉRENCE) ==================== -->
<div class="panel rates-live">
    <div class="rates-live__status">
        <span class="rates-live__dot rates-live__dot--<?= $market && !$market['stale'] ? 'ok' : 'stale' ?>" aria-hidden="true"></span>
        <div>
            <p class="rates-live__title">Cours du marché mondial <small>(référence, jamais appliqué automatiquement)</small></p>
            <p class="rates-live__text">
                <?php if ($market): ?>
                    <?= $market['stale'] ? 'Dernier cours connu' : 'Publication' ?> du <strong><?= e(fx_updated_label($market)) ?></strong>
                    · source <a href="<?= e(FX_ATTRIBUTION['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(FX_ATTRIBUTION['label']) ?></a>, cours publiés une fois par jour.
                <?php else: ?>
                    Service de taux injoignable : la référence du marché n'est pas disponible pour le moment.
                <?php endif; ?>
            </p>
        </div>
    </div>
    <?php if ($canEdit): ?>
        <form method="post" class="rates-live__form">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="refresh_market">
            <button type="submit" class="btn btn--ghost"><?= icon('refresh') ?> Actualiser les cours du marché</button>
        </form>
    <?php endif; ?>
</div>

<!-- ==================== GRILLE DE L'ENTREPRISE ==================== -->
<form method="post" class="panel form company-rates" novalidate data-company-rates>
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save">

    <header class="panel__head">
        <div>
            <h2 class="panel__title"><?= icon('exchange') ?> Grille des taux de l'entreprise</h2>
            <p class="cell-sub">
                Dernière modification : <?= e(company_rates_updated_label()) ?><?= $updated['by'] ? ' par ' . e($updated['by']) : '' ?>
                · appliquée au simulateur du site, au simulateur avancé et aux reçus de caisse.
            </p>
        </div>
    </header>

    <div class="rates-help">
        <p><strong>Achat / Dépôt</strong> : le client vous remet la devise et reçoit des FCFA (montant × taux d'achat).</p>
        <p><strong>Vente / Retrait</strong> : le client paie en FCFA et reçoit la devise (montant ÷ taux de vente).</p>
        <p>Valeurs en FCFA pour 1 unité. Même valeur à l'achat et à la vente : taux fixe unique (ex. parité EUR = 655,957 FCFA).</p>
    </div>

    <?php if ($canEdit): ?>
        <div class="rates-import">
            <div class="field">
                <label for="r-margin">Marge de l'entreprise sur les cours du marché (%)</label>
                <input id="r-margin" name="market_margin_percent" inputmode="decimal" placeholder="Ex. 1,5" data-rates-margin
                       value="<?= e($posted ? post('market_margin_percent') : str_replace('.', ',', (string) round($margin, 2))) ?>"<?= isset($errors['market_margin_percent']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (isset($errors['market_margin_percent'])): ?><p class="field__error"><?= e($errors['market_margin_percent']) ?></p><?php endif; ?>
            </div>
            <button type="button" class="btn btn--ghost" data-rates-import<?= $market ? '' : ' disabled' ?>><?= icon('download') ?> Importer les cours du marché avec la marge</button>
            <small class="field__hint">Remplit la grille : achat = marché − marge, vente = marché + marge. Rien n'est appliqué avant « Enregistrer ».</small>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="table table--stack company-rates__table">
            <thead>
                <tr>
                    <th>Devise</th>
                    <th class="num">Marché (réf.)</th>
                    <th>Achat / Dépôt <small>FCFA</small></th>
                    <th>Vente / Retrait <small>FCFA</small></th>
                    <th class="num">Écart</th>
                    <th>Modifié le</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rates as $code => $r):
                $mid  = $market['rates'][$code] ?? null;
                $buy  = $posted ? (string) ($_POST['buy'][$code] ?? '') : rate_field($r['buy']);
                $sell = $posted ? (string) ($_POST['sell'][$code] ?? '') : rate_field($r['sell']);
            ?>
                <tr data-rate-row data-market="<?= $mid ? e((string) $mid) : '' ?>">
                    <td data-label="Devise"><span class="cell-stack">
                        <strong><?= e($code) ?> <span class="company-rates__symbol"><?= e(GTC_CURRENCIES[$code]['symbol'] ?? '') ?></span></strong>
                        <small class="cell-sub"><?= e($r['name']) ?></small>
                    </span></td>
                    <td data-label="Marché (réf.)" class="num"><?= $mid ? e(rate_format($mid)) : '—' ?></td>
                    <td data-label="Achat / Dépôt">
                        <label class="visually-hidden" for="buy-<?= e($code) ?>">Taux d'achat <?= e($code) ?></label>
                        <input id="buy-<?= e($code) ?>" name="buy[<?= e($code) ?>]" class="rate-input" inputmode="decimal" required data-rate-buy
                               value="<?= e($buy) ?>" data-initial="<?= e(rate_field($r['buy'])) ?>"<?= $canEdit ? '' : ' readonly' ?><?= isset($errors["buy_{$code}"]) ? ' aria-invalid="true"' : '' ?>>
                        <?php if (isset($errors["buy_{$code}"])): ?><p class="field__error"><?= e($errors["buy_{$code}"]) ?></p><?php endif; ?>
                    </td>
                    <td data-label="Vente / Retrait">
                        <label class="visually-hidden" for="sell-<?= e($code) ?>">Taux de vente <?= e($code) ?></label>
                        <input id="sell-<?= e($code) ?>" name="sell[<?= e($code) ?>]" class="rate-input" inputmode="decimal" required data-rate-sell
                               value="<?= e($sell) ?>" data-initial="<?= e(rate_field($r['sell'])) ?>"<?= $canEdit ? '' : ' readonly' ?><?= isset($errors["sell_{$code}"]) ? ' aria-invalid="true"' : '' ?>>
                        <?php if (isset($errors["sell_{$code}"])): ?><p class="field__error"><?= e($errors["sell_{$code}"]) ?></p><?php endif; ?>
                    </td>
                    <td data-label="Écart" class="num"><span class="company-rates__spread" data-rate-spread>—</span></td>
                    <td data-label="Modifié le"><span class="cell-stack">
                        <?= e(fdate($r['updated_at'], true)) ?>
                        <small class="cell-sub"><?= e($r['updated_by_name'] ?? 'Installation') ?></small>
                    </span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canEdit): ?>
        <div class="form-actions company-rates__actions">
            <span class="company-rates__pending" data-rates-pending hidden>Modifications non enregistrées</span>
            <button type="submit" class="btn btn--primary btn--lg"><?= icon('save') ?> Enregistrer et appliquer à tout le site</button>
        </div>
    <?php else: ?>
        <p class="field__hint">Seul un administrateur peut modifier la grille des taux.</p>
    <?php endif; ?>
</form>

<?php dash_footer(); ?>
