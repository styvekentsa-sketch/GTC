<?php
/**
 * API JSON de la recherche globale (palette « Ctrl + K » de toutes les pages, barre de recherche du Hub).
 *
 *   GET ?q=jean  →  { query, total, categories: [ { key, label, icon, count, more, items: [ { title, sub, meta?, badge?, url } ] } ] }
 *
 * Résultats limités aux pages que le compte connecté peut ouvrir (includes/global_search.php).
 * Adresses relatives au dossier dashboard/ (les pages de GTC-Vault y ramènent par leur balise <base>).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/global_search.php';

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
session_write_close();   // lecture seule : les autres requêtes de la session ne sont pas bloquées pendant la frappe

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $reply(405, ['error' => 'Méthode non autorisée.']);
}
$q = (string) ($_GET['q'] ?? '');
if (!mb_check_encoding($q, 'UTF-8')) {
    $reply(400, ['error' => 'Recherche invalide.']);
}

try {
    $reply(200, gs_search($q, $user));
} catch (Throwable $e) {
    error_log('[Recherche globale] ' . $e->getMessage());
    $reply(500, ['error' => 'Recherche momentanément indisponible.']);
}
