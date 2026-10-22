<?php
/**
 * Paramètres de l'entreprise (table company_settings) — lecture, écriture et valeurs dérivées.
 *
 * Utilisés par : factures imprimées (coordonnées, logo, format), simulateur et formulaire
 * de facture (taux et commission par défaut, devise locale).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/exchange_rates.php';
require_once __DIR__ . '/company_rates.php';

const BRAND_DIR = __DIR__ . '/../assets/img/brand';
const BRAND_URL = 'assets/img/brand';

/** Tous les paramètres (valeurs par défaut complétées par la base). */
function settings_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = DEFAULT_SETTINGS;
    if ($pdo = db_try()) {
        try {
            foreach ($pdo->query('SELECT setting_key, setting_value FROM company_settings') as $row) {
                $cache[$row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (PDOException $e) {
            error_log('[GTC] Lecture des paramètres impossible : ' . $e->getMessage());
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $value = settings_all()[$key] ?? '';
    return $value !== '' ? $value : $default;
}

/** Enregistre plusieurs paramètres (requêtes préparées, insertion ou mise à jour). */
function settings_save(array $values): void
{
    $sql = DB_DRIVER === 'mysql'
        ? 'INSERT INTO company_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        : 'INSERT INTO company_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value';
    $stmt = db()->prepare($sql);
    foreach ($values as $key => $value) {
        $stmt->execute([$key, (string) $value]);
    }
}

/** NIU de l'entreprise (Paramètres → Entreprise ; à défaut la constante GTC_NIU de config.php). */
function company_niu(): string
{
    $niu = setting('company_niu');
    return $niu !== '' ? $niu : (defined('GTC_NIU') ? (string) GTC_NIU : '');
}

/** Devise locale par défaut au format du Dashboard (FCFA → XAF). */
function settings_currency(): string
{
    $currency = strtoupper(setting('currency', 'FCFA'));
    return $currency === 'FCFA' ? 'XAF' : $currency;
}

/** Format d'impression par défaut : a4 | a5 | ticket. */
function settings_print_format(): string
{
    $format = strtolower(setting('print_format', 'A4'));
    return in_array($format, ['a4', 'a5', 'ticket'], true) ? $format : 'a4';
}

/** URL du logo des factures ($prefix : chemin vers la racine du site). */
function settings_logo_url(string $prefix = ''): string
{
    $file = basename(setting('company_logo'));
    if ($file !== '' && preg_match('/^logo-[a-f0-9]{16}\.(png|jpe?g)$/', $file) && is_file(BRAND_DIR . '/' . $file)) {
        return $prefix . BRAND_URL . '/' . $file . '?v=' . filemtime(BRAND_DIR . '/' . $file);
    }
    return $prefix . 'assets/img/logo-recu.png';   // logo des reçus de caisse (globe GTC)
}
