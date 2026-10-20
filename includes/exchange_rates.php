<?php
/**
 * Taux de change du marché — Global Trading Corporation
 *
 * Source : open.er-api.com (données ExchangeRate-API, accès libre sans clé, mise à jour quotidienne).
 * Les cours sont mis en cache 30 minutes dans data/exchange-rates.json ; en cas de panne de l'API,
 * le dernier cours connu est conservé.
 *
 * Attention : ces cours ne sont qu'une RÉFÉRENCE : les taux appliqués partout (site, simulateurs, factures) sont ceux
 * de la grille de l'entreprise (includes/company_rates.php, Dashboard → Taux de change), où l'administrateur
 * peut importer ces cours avec sa marge.
 *
 * Valeurs exprimées comme partout dans le site : valeur de 1 unité de la devise en FCFA (XAF).
 * La parité EUR / FCFA est fixe et légale : 1 EUR = 655,957 FCFA. Les autres devises sont calculées
 * à partir du cours de l'euro, ce qui garde des taux croisés cohérents avec cette parité.
 *
 * Marge d'import : réglage market_margin_percent (Dashboard → Taux de change).
 */

require_once __DIR__ . '/db.php';

const FX_API_URL     = 'https://open.er-api.com/v6/latest/EUR';
const FX_CACHE_FILE  = __DIR__ . '/../data/exchange-rates.json';
const FX_CACHE_TTL   = 1800;      // 30 minutes
const FX_RETRY_DELAY = 300;       // après un échec, nouvel essai 5 minutes plus tard (pages jamais ralenties en boucle)
const FX_EUR_XAF     = 655.957;   // parité fixe
const FX_CODES       = ['EUR', 'USD', 'CAD', 'NGN', 'AED', 'CNY'];
const FX_ATTRIBUTION = ['label' => 'ExchangeRate-API', 'url' => 'https://www.exchangerate-api.com'];

/** Interroge l'API. Retourne ['rates' => [code => FCFA], 'provider_updated' => int, 'fetched_at' => int] ou un message d'erreur. */
function fx_fetch_market(): array|string
{
    if (!function_exists('curl_init')) {
        return "l'extension cURL de PHP n'est pas disponible";
    }
    $ch = curl_init(FX_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'GTC-Dashboard/1.0 (+' . (defined('GTC_SITE_URL') ? GTC_SITE_URL : 'globaltradingcorporation.net') . ')',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    // Certificats SSL : magasin du système (Windows, macOS) si PHP n'a pas de fichier de certificats configuré.
    // La vérification n'est jamais désactivée.
    if (defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo') && !ini_get('openssl.cafile')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    if ($body === false || $http !== 200) {
        return 'service de taux injoignable' . ($err ? " ({$err})" : " (HTTP {$http})");
    }
    $data = json_decode((string) $body, true);
    if (($data['result'] ?? '') !== 'success' || !is_array($data['rates'] ?? null)) {
        return 'réponse inattendue du service de taux';
    }
    $rates = [];
    foreach (FX_CODES as $code) {
        $perEuro = (float) ($code === 'EUR' ? 1 : ($data['rates'][$code] ?? 0));
        if ($perEuro <= 0) {
            return "cours manquant pour {$code}";
        }
        $rates[$code] = round(FX_EUR_XAF / $perEuro, 6);   // 1 unité = X FCFA
    }
    return ['rates' => $rates, 'provider_updated' => (int) ($data['time_last_update_unix'] ?? time()), 'fetched_at' => time()];
}

/**
 * Cours du marché (cache de 30 minutes). $force : interrogation immédiate de l'API.
 * Retourne null si aucun cours n'a jamais pu être obtenu ; 'stale' => true si le dernier cours connu est réutilisé.
 */
function fx_market(bool $force = false): ?array
{
    static $memo = null;
    if ($memo !== null && !$force) {
        return $memo ?: null;
    }
    $cache = is_file(FX_CACHE_FILE) ? json_decode((string) @file_get_contents(FX_CACHE_FILE), true) : null;
    $now = time();
    $fresh = is_array($cache) && isset($cache['rates']) && $now - (int) ($cache['fetched_at'] ?? 0) < FX_CACHE_TTL;
    $waiting = is_array($cache) && $now < (int) ($cache['retry_at'] ?? 0);

    if ($fresh && !$force) {
        return $memo = $cache + ['stale' => false];
    }
    if ($waiting && !$force) {
        return $memo = (isset($cache['rates']) ? $cache + ['stale' => true] : false) ?: null;
    }

    $result = fx_fetch_market();
    if (is_array($result)) {
        @file_put_contents(FX_CACHE_FILE, json_encode($result), LOCK_EX);
        return $memo = $result + ['stale' => false];
    }
    // Échec : dernier cours connu conservé, nouvel essai plus tard
    $keep = is_array($cache) ? $cache : [];
    $keep['retry_at'] = $now + FX_RETRY_DELAY;
    $keep['last_error'] = $result;
    @file_put_contents(FX_CACHE_FILE, json_encode($keep), LOCK_EX);
    error_log('[GTC] Taux du marché indisponibles : ' . $result);
    return $memo = (isset($keep['rates']) ? $keep + ['stale' => true] : false) ?: null;
}

/** Marge de l'entreprise appliquée à l'import des cours du marché (fraction : 0,005 pour 0,5 %). */
function fx_margin(): float
{
    $m = function_exists('setting') ? (float) str_replace(',', '.', setting('market_margin_percent', '0')) : 0.0;
    return max(0.0, min($m, 20.0)) / 100;
}

/** Libellé de la dernière mise à jour du marché : « 17/10/2026 à 00:02 ». */
function fx_updated_label(?array $market): ?string
{
    return $market ? date('d/m/Y à H:i', (int) $market['provider_updated']) : null;
}
