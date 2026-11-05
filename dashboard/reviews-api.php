<?php
/**
 * Suivi en direct des invitations à donner un avis (dashboard/reviews.php?f=invites, assets/js/dashboard.js § 12).
 *
 *   GET reviews-api.php?ids=12,11,10
 *   → {
 *       counts:  { total, opened, answered, opened_pct, answered_pct },
 *       invites: { "12": { state: "opened:2", key: "opened", name: "Paul Kamga", status: "<span …>", review: "—" } }
 *     }
 *   state : clé du statut + nombre d'ouvertures (le navigateur ne met à jour que les lignes qui ont changé)
 *   status / review : cellules « Suivi » et « Avis », même rendu que la page (HTML échappé côté PHP)
 *
 * Sécurité : comptes de la page Avis (MESSAGE_ROLES) ; lecture seule (GET). Session vérifiée sans être prolongée
 * (GTC_PASSIVE_REQUEST, voir includes/auth.php) : la déconnexion après inactivité reste effective.
 */
const GTC_PASSIVE_REQUEST = true;

require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/reviews.php';

auth_session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function reviews_api_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    reviews_api_reply(405, ['error' => 'Méthode non autorisée.']);
}
$user = current_user();
if (!$user) {
    reviews_api_reply(401, ['error' => 'Session expirée : reconnectez-vous.']);
}
if (!in_array($user['role'] ?? '', MESSAGE_ROLES, true)) {
    reviews_api_reply(403, ['error' => 'Accès refusé.']);
}
session_write_close();   // lecture seule : la session n'est plus verrouillée pendant la réponse

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))))));
$ids = array_slice($ids, 0, 50);   // une page de la liste (20 lignes) au plus

$counts = review_invite_counts();
$pct = fn (int $n) => $counts['total'] ? round($n / $counts['total'] * 100) . "\u{202F}%" : '—';
$data = [
    'counts'  => $counts + ['opened_pct' => $pct($counts['opened']), 'answered_pct' => $pct($counts['answered'])],
    'invites' => (object) [],
];
if ($ids) {
    $in = implode(', ', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (db_all(REVIEW_INVITES_SQL . " WHERE i.id IN ({$in})", $ids) as $inv) {
        $key = review_invite_status($inv)[0];
        $out[(string) $inv['id']] = [
            'state'  => $key . ':' . (int) $inv['open_count'],
            'key'    => $key,
            'name'   => (string) ($inv['client_name'] ?? ''),
            'status' => review_invite_status_html($inv),
            'review' => review_invite_review_html($inv),
        ];
    }
    $data['invites'] = (object) $out;
}
reviews_api_reply(200, $data);
