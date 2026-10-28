<?php
/**
 * Grille des taux de l'entreprise (table exchange_rates) — source UNIQUE de tous les convertisseurs :
 * simulateur public (services.php), simulateur avancé et factures du Dashboard, API JSON (api-rates.php,
 * dashboard/rates-api.php).
 *
 * Chaque devise a deux taux, exprimés en FCFA pour 1 unité :
 *   buy_rate  (achat / dépôt)    : le guichet ACHÈTE la devise au client  → devise → FCFA : montant × buy_rate
 *   sell_rate (vente / retrait)  : le guichet VEND la devise au client    → FCFA → devise : montant ÷ sell_rate
 * Entre deux devises étrangères : 1 A = buy_rate(A) ÷ sell_rate(B) B.
 * Achat = vente : taux unique (ex. parité fixe EUR = 655,957 FCFA).
 *
 * Gestion : Dashboard → Taux de change (dashboard/rates.php), administrateurs uniquement. Les cours du marché
 * (includes/exchange_rates.php) ne servent que de référence, importable avec la marge de l'entreprise.
 *
 * Cache : data/company-rates.json (réécrit à chaque enregistrement) + mémoire de la requête :
 * le site public ne lit la table qu'une fois après chaque modification.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/exchange_rates.php';

const COMPANY_RATES_CACHE = __DIR__ . '/../data/company-rates.json';

/** Devises étrangères de la grille, codes du Dashboard (FCFA = XAF, monnaie de référence, hors grille). */
function company_rate_codes(): array
{
    return array_values(array_filter(array_keys(GTC_CURRENCIES), fn ($c) => $c !== 'FCFA'));
}

/**
 * Grille complète : [code => ['code', 'name', 'buy', 'sell', 'updated_at', 'updated_by', 'updated_by_name']].
 * Les devises absentes de la table (nouvelle installation, devise ajoutée) y sont créées automatiquement.
 */
function company_rates(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $cache = is_file(COMPANY_RATES_CACHE) ? json_decode((string) @file_get_contents(COMPANY_RATES_CACHE), true) : null;
    if (is_array($cache) && isset($cache['rates']) && !array_diff(company_rate_codes(), array_keys($cache['rates']))) {
        return $memo = $cache['rates'];
    }

    $rows = company_rates_load();
    $missing = array_diff(company_rate_codes(), array_keys($rows));
    if ($missing) {
        company_rates_seed($missing);
        $rows = company_rates_load();
    }
    @file_put_contents(COMPANY_RATES_CACHE, json_encode(['rates' => $rows, 'cached_at' => time()]), LOCK_EX);
    return $memo = $rows;
}

/** Lecture de la table (une seule requête). */
function company_rates_load(): array
{
    $rows = db_all(
        "SELECT r.currency_code, r.currency_name, r.buy_rate, r.sell_rate, r.updated_at, r.updated_by,
                CASE WHEN r.updated_by IS NOT NULL AND u.id IS NULL THEN 'Compte supprimé' ELSE u.name END AS updated_by_name
         FROM exchange_rates r LEFT JOIN users u ON u.id = r.updated_by"
    );
    $rates = [];
    foreach ($rows as $r) {
        $rates[$r['currency_code']] = [
            'code'            => $r['currency_code'],
            'name'            => $r['currency_name'],
            'buy'             => (float) $r['buy_rate'],
            'sell'            => (float) $r['sell_rate'],
            'updated_at'      => (string) $r['updated_at'],
            'updated_by'      => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
            'updated_by_name' => $r['updated_by_name'],
        ];
    }
    // Ordre de la vitrine (includes/rates.php)
    $order = array_flip(company_rate_codes());
    uksort($rates, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    return $rates;
}

/**
 * Premières valeurs d'une devise : taux appliqués jusqu'ici (cours du marché ou taux saisis dans les anciens
 * Paramètres, marge comprise), sinon taux indicatifs de includes/rates.php.
 */
function company_rates_seed(array $codes): void
{
    $market = function_exists('setting') && setting('rates_source', 'market') === 'manual' ? null : fx_market();
    $margin = fx_margin();
    $insert = db()->prepare(
        'INSERT INTO exchange_rates (currency_code, currency_name, buy_rate, sell_rate, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, NULL)'
    );
    foreach ($codes as $code) {
        $saved = function_exists('setting') ? (float) setting('rate_' . $code, '0') : 0.0;
        $mid = (float) ($market['rates'][$code] ?? ($saved > 0 ? $saved : GTC_CURRENCIES[$code]['rate']));
        [$buy, $sell] = company_rates_from_market($mid, $margin);
        try {
            $insert->execute([$code, GTC_CURRENCIES[$code]['name'], $buy, $sell, db_now()]);
        } catch (PDOException) {
            // insérée entre-temps par une autre requête
        }
    }
}

/** Achat et vente à partir d'un cours du marché et d'une marge (fraction) : le client reçoit un peu moins dans les deux sens. */
function company_rates_from_market(float $mid, float $margin): array
{
    $margin = max(0.0, min($margin, 0.2));
    return [round($mid * (1 - $margin), 6), round($mid / (1 - $margin), 6)];
}

/** Supprime le cache : la prochaine lecture reprend la table (après chaque enregistrement). */
function company_rates_flush(): void
{
    if (is_file(COMPANY_RATES_CACHE)) {
        @unlink(COMPANY_RATES_CACHE);
    }
}

/**
 * Enregistre la grille. $values : [code => ['buy' => float, 'sell' => float]].
 * Seules les devises modifiées changent de date et d'auteur. Retourne le nombre de devises modifiées.
 */
function company_rates_save(array $values, int $userId): int
{
    $current = company_rates_load();
    $update = db()->prepare('UPDATE exchange_rates SET buy_rate = ?, sell_rate = ?, updated_at = ?, updated_by = ? WHERE currency_code = ?');
    $changed = 0;
    db()->beginTransaction();
    try {
        foreach ($values as $code => $v) {
            $old = $current[$code] ?? null;
            if ($old && abs($old['buy'] - $v['buy']) < 1e-7 && abs($old['sell'] - $v['sell']) < 1e-7) {
                continue;
            }
            $update->execute([round($v['buy'], 6), round($v['sell'], 6), db_now(), $userId, $code]);
            $changed++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    company_rates_flush();
    return $changed;
}

/** Dernière modification de la grille : ['at' => 'Y-m-d H:i:s', 'by' => nom|null]. */
function company_rates_updated(): array
{
    $last = ['at' => '', 'by' => null];
    foreach (company_rates() as $r) {
        if ($r['updated_at'] > $last['at']) {
            $last = ['at' => $r['updated_at'], 'by' => $r['updated_by_name']];
        }
    }
    return $last;
}

/** Libellé de mise à jour : « 19/10/2026 à 14:05 ». */
function company_rates_updated_label(): string
{
    $at = company_rates_updated()['at'];
    $ts = $at !== '' ? strtotime($at) : false;
    return $ts ? date('d/m/Y à H:i', $ts) : '—';
}

/**
 * Taux pour les scripts (data-rates) : [code => ['buy' => …, 'sell' => …, 'decimals' => …]], FCFA compris (1 / 1).
 * $fcfa : code de la monnaie locale ('XAF' dans le Dashboard, 'FCFA' sur le site public).
 */
function company_rates_js(string $fcfa = 'XAF'): array
{
    $out = [$fcfa => ['buy' => 1, 'sell' => 1, 'decimals' => 0]];
    foreach (company_rates() as $code => $r) {
        $out[$code] = ['buy' => $r['buy'], 'sell' => $r['sell'], 'decimals' => GTC_CURRENCIES[$code]['decimals'] ?? 2];
    }
    return $out;
}

/** Valeur de 1 unité en FCFA lorsque le guichet la reçoit (achat) ou la remet (vente). */
function company_rate_value(string $code, string $side): ?float
{
    if ($code === 'XAF' || $code === 'FCFA') {
        return 1.0;
    }
    $r = company_rates()[$code] ?? null;
    $v = $r ? (float) $r[$side === 'buy' ? 'buy' : 'sell'] : 0.0;
    return $v > 0 ? $v : null;
}

/**
 * Taux de l'entreprise pour une opération source → cible, présenté dans le sens le plus lisible (taux ≥ 1) :
 * ['rate' => float, 'direction' => 'multiply'|'divide', 'unit' => taux brut « 1 source = x cible »].
 */
function company_rate_pair(string $from, string $to): ?array
{
    if ($from === $to || (in_array($from, ['XAF', 'FCFA'], true) && in_array($to, ['XAF', 'FCFA'], true))) {
        return ['rate' => 1.0, 'direction' => 'multiply', 'unit' => 1.0];
    }
    $buy  = company_rate_value($from, 'buy');
    $sell = company_rate_value($to, 'sell');
    if (!$buy || !$sell) {
        return null;
    }
    $unit = $buy / $sell;
    return $unit >= 1
        ? ['rate' => round($unit, 4), 'direction' => 'multiply', 'unit' => $unit]
        : ['rate' => round(1 / $unit, 4), 'direction' => 'divide', 'unit' => $unit];
}
