<?php
/**
 * API JSON de la passerelle GTC-Vault ↔ facturation (autocomplétion des formulaires, assets/js/kyc-link.js).
 *
 *   GET ?q=jean            → { results: [ { uuid, name, type, typeLabel, phone, email, address, idNumber, idLabel,
 *                                           status: complete|incomplete, missing: [...] } ] }   (8 au plus)
 *   GET ?uuid=…            → { client: {…} }  (dossier déjà lié : état à jour)
 *
 * Accès : session du Dashboard + rôle des opérations ou de GTC-Vault (KYC_LINK_ROLES). Aucune pièce d'identité
 * ni chemin de fichier n'est renvoyé : seulement l'identité du client et l'état de son dossier.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/kyc_link.php';

auth_session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$reply = function (int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$user = current_user();
if (!$user) {
    $reply(401, ['error' => 'Session expirée : reconnectez-vous.']);
}
if (!in_array($user['role'], KYC_LINK_ROLES, true)) {
    $reply(403, ['error' => 'Accès refusé.']);
}
session_write_close();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $reply(405, ['error' => 'Méthode non autorisée.']);
}

$uuid = strtolower(trim((string) ($_GET['uuid'] ?? '')));
if ($uuid !== '') {
    $client = kyc_client_by_uuid($uuid);
    if (!$client) {
        $reply(404, ['error' => 'Dossier introuvable.']);
    }
    unset($client['id']);
    $reply(200, ['client' => $client]);
}

$q = trim((string) ($_GET['q'] ?? ''));
if (!mb_check_encoding($q, 'UTF-8') || mb_strlen($q) < 2) {
    $reply(200, ['results' => []]);
}
$reply(200, ['results' => kyc_client_search(mb_substr($q, 0, 80))]);
