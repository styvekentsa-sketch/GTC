<?php
/**
 * Journal d'activité — traçabilité des actions sensibles du Dashboard (table activity_log, includes/db.php).
 *
 *   activity_log('create', 'invoice', $id, 'GTC-20261026-0001', ['montant' => …])   enregistre une action
 *   dashboard/activity.php                                                           consultation (administrateurs)
 *
 * Complète impersonation_log (prises de contrôle) : pendant une prise de contrôle, l'action est attribuée au compte
 * utilisé ET l'administrateur réel est noté (acting_admin).
 * Un échec d'écriture du journal n'interrompt jamais l'action de l'utilisateur (simple entrée dans le journal PHP).
 */

/** Nature de l'action => [libellé, ton du badge]. */
const ACTIVITY_ACTIONS = [
    'create'       => ['label' => 'Création',          'tone' => 'success'],
    'update'       => ['label' => 'Modification',      'tone' => 'info'],
    'status'       => ['label' => 'Changement de statut', 'tone' => 'info'],
    'delete'       => ['label' => 'Suppression',       'tone' => 'danger'],
    'login'        => ['label' => 'Connexion',         'tone' => 'muted'],
    'login_failed' => ['label' => 'Échec de connexion', 'tone' => 'warning'],
    'logout'       => ['label' => 'Déconnexion',       'tone' => 'muted'],
    'export'       => ['label' => 'Export de données', 'tone' => 'warning'],
    'backup'       => ['label' => 'Sauvegarde BDD',    'tone' => 'warning'],
    'security'     => ['label' => 'Sécurité',          'tone' => 'warning'],
    'closing'      => ['label' => 'Clôture de caisse', 'tone' => 'success'],
];

/** Élément concerné => libellé. */
const ACTIVITY_ENTITIES = [
    'invoice'    => 'Reçu de caisse',
    'commercial' => 'Facture / devis',
    'courrier'   => 'Courrier',
    'message'    => 'Message du site',
    'user'       => 'Compte utilisateur',
    'team'       => 'Membre de l\'équipe',
    'partner'    => 'Partenaire',
    'printer'    => 'Imprimante',
    'print_job'  => 'Travail d\'impression',
    'rates'      => 'Taux de change',
    'settings'   => 'Paramètres',
    'session'    => 'Session',
    'database'   => 'Base de données',
    'cash'       => 'Caisse',
];

const ACTIVITY_KEEP_DAYS = 730;   // historique conservé : 2 ans (purge automatique, voir activity_log())

/**
 * Enregistre une action. $details : informations utiles (montants, ancien → nouveau statut…), stockées en JSON.
 * $user : auteur explicite (connexion, échec de connexion) ; par défaut le compte connecté.
 */
function activity_log(string $action, string $entity, ?int $entityId = null, string $label = '', array $details = [], ?array $user = null): void
{
    try {
        $user ??= function_exists('current_user') ? current_user() : null;
        $admin = function_exists('impersonation_admin') ? impersonation_admin() : null;
        db_query(
            'INSERT INTO activity_log (created_at, user_id, user_name, user_role, acting_admin, action, entity, entity_id, label, details, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [db_now(), $user['id'] ?? null, isset($user['name']) ? mb_substr((string) $user['name'], 0, 100) : null, $user['role'] ?? null,
             $admin ? mb_substr((string) $admin['name'], 0, 100) : null,
             $action, $entity, $entityId, mb_substr($label, 0, 255),
             $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
             function_exists('client_ip') ? client_ip() : null]
        );
        // Purge occasionnelle (1 écriture sur 200) des entrées de plus de ACTIVITY_KEEP_DAYS jours
        if (random_int(1, 200) === 1) {
            db_query('DELETE FROM activity_log WHERE created_at < ?',
                [(new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->modify('-' . ACTIVITY_KEEP_DAYS . ' days')->format('Y-m-d H:i:s')]);
        }
    } catch (Throwable $e) {
        error_log('[GTC] Journal d\'activité : ' . $e->getMessage());
    }
}

/** Détails lisibles d'une entrée (« Statut : Brouillon → Envoyé · Montant : 5 000 EUR »). */
function activity_details_text(?string $json): string
{
    $data = $json ? json_decode($json, true) : null;
    if (!is_array($data)) {
        return '';
    }
    $parts = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $parts[] = ucfirst(str_replace('_', ' ', (string) $key)) . ' : ' . $value;
    }
    return implode(' · ', $parts);
}
