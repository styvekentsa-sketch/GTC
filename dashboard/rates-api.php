<?php
/**
 * API JSON du taux de change à proposer (formulaire de facture, assets/js/dashboard.js).
 *
 *   GET ?from=CAD&to=XAF
 *   → { rate, direction, source: 'company', updated, updated_by }
 *
 * rate : taux de la grille de l'entreprise (Dashboard → Taux de change : achat de la devise remise,
 * vente de la devise reçue), dans le sens le plus lisible (taux ≥ 1), 4 décimales ;
 * l'agent reste libre de le modifier (taux négocié). Le calcul du montant est refait côté serveur.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';

auth_session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$reply = function (int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!current_user()) {
    $reply(401, ['error' => 'Session expirée : reconnectez-vous.']);
}
if (!in_array(current_user()['role'], OPERATION_ROLES, true)) {
    $reply(403, ['error' => 'Accès refusé.']);
}
session_write_close();

$from = strtoupper((string) ($_GET['from'] ?? ''));
$to   = strtoupper((string) ($_GET['to'] ?? ''));
if (!isset(DASH_CURRENCIES[$from], DASH_CURRENCIES[$to])) {
    $reply(400, ['error' => 'Devise inconnue.']);
}

$pair = company_rate_pair($from, $to);
if (!$pair) {
    $reply(503, ['error' => 'Taux indisponible pour cette paire de devises.']);
}

$updated = company_rates_updated();
$reply(200, [
    'from'       => $from,
    'to'         => $to,
    'rate'       => $pair['rate'],
    'direction'  => $pair['direction'],
    'source'     => 'company',
    'updated'    => company_rates_updated_label(),
    'updated_by' => $updated['by'],
]);
