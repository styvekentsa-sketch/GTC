<?php
/**
 * Compte propriétaire du Dashboard (Styve Kentsa).
 *
 * Identifié par son adresse e-mail de connexion : constante GTC_OWNER_EMAIL de includes/db.local.php si elle existe,
 * sinon OWNER_DEFAULT_EMAIL. Les adresses e-mail des comptes sont uniques : aucun autre compte ne peut la prendre.
 *
 * Réservé au propriétaire (connecté avec SON compte, jamais via une prise de contrôle) :
 *   - Paramètres → Système → « Alertes de connexion », « Envoi des e-mails », « Rapport d'activité par e-mail ».
 * Protégé contre les autres administrateurs (dashboard/users.php) : rôle, statut, mot de passe, suppression.
 */

const OWNER_DEFAULT_EMAIL = 'styvekentsa@gmail.com';

/** Adresse de connexion du compte propriétaire. */
function owner_email(): string
{
    $email = defined('GTC_OWNER_EMAIL') ? trim((string) constant('GTC_OWNER_EMAIL')) : '';
    return mb_strtolower($email !== '' ? $email : OWNER_DEFAULT_EMAIL);
}

/** Ce compte (ligne users) est-il celui du propriétaire ? */
function owner_is_account(?array $account): bool
{
    return $account !== null && mb_strtolower(trim((string) ($account['email'] ?? ''))) === owner_email();
}

/** La personne connectée est-elle le propriétaire, avec son propre compte (pas de prise de contrôle en cours) ? */
function owner_is_current(): bool
{
    if (function_exists('impersonation_active') && impersonation_active()) {
        return false;
    }
    $user = function_exists('current_user') ? current_user() : null;
    if (!$user) {
        return false;
    }
    // Adresse relue en base : la session ne suffit pas
    $email = db_value('SELECT email FROM users WHERE id = ?', [(int) $user['id']]);
    return is_string($email) && mb_strtolower(trim($email)) === owner_email();
}
