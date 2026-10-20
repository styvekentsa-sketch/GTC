<?php
/**
 * API publique des taux du guichet (simulateur de services.php, assets/js/main.js).
 *
 *   GET api-rates.php
 *   → { rates: { FCFA: {buy, sell, decimals}, EUR: {…}, … }, updated: "19/10/2026 à 14:05" }
 *
 * Taux de la grille de l'entreprise (table exchange_rates, Dashboard → Taux de change), lus depuis le cache
 * data/company-rates.json : aucune requête en base tant que la grille ne change pas.
 * Cache navigateur de 60 secondes : une modification est visible sur le site en une minute au plus.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/settings.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

try {
    $data = ['rates' => company_rates_js('FCFA'), 'updated' => company_rates_updated_label()];
    header('Cache-Control: public, max-age=60');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[GTC] API des taux : ' . $e->getMessage());
    http_response_code(503);
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Taux momentanément indisponibles.']);
}
