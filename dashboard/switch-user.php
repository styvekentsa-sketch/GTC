<?php
/**
 * Prise de contrôle de compte (« Se connecter en tant que… ») et retour au compte administrateur.
 *
 *   POST action=start, id=<compte>   → l'administrateur utilise le compte choisi (users.php)
 *   POST action=stop                 → retour au compte administrateur (bannière du Dashboard)
 *
 * Sécurité :
 *   - POST uniquement, avec jeton CSRF : un lien ou une adresse tapée dans le navigateur ne déclenche rien ;
 *   - start : l'utilisateur à l'origine de la requête doit être un administrateur actif (relu en base),
 *     sans prise de contrôle déjà en cours ; jamais vers un autre administrateur ni un compte inactif ;
 *   - stop : n'agit que si la session contient une prise de contrôle, et restaure l'administrateur
 *     d'origine uniquement s'il est toujours administrateur et actif (sinon déconnexion complète) ;
 *   - identifiant de session renouvelé à chaque bascule ; chaque prise de contrôle est journalisée.
 */
require_once __DIR__ . '/../includes/dashboard.php';

$user = require_login();

if (!is_post()) {
    http_response_code(405);
    flash('error', 'Action non autorisée.');
    redirect('index.php');
}
csrf_verify();

$action = post('action');

if ($action === 'start') {
    $target = db_one('SELECT id, name, role, status FROM users WHERE id = ?', [(int) post('id')]);
    if ($reason = impersonation_refusal($user, $target)) {
        http_response_code(403);
        flash('error', $reason);
        redirect(impersonation_active() || $user['role'] !== 'admin' ? 'index.php' : 'users.php');
    }
    activity_log('security', 'user', (int) $target['id'], $target['name'], ['action' => 'Prise de contrôle du compte'], $user);
    impersonation_start($user, $target);
    flash('warning', "Vous utilisez maintenant le compte de {$target['name']}. Toutes vos actions sont faites en son nom.");
    redirect('index.php');
}

if ($action === 'stop') {
    if (!impersonation_active()) {
        redirect('index.php');
    }
    if (!impersonation_stop()) {
        // L'administrateur d'origine a été désactivé ou n'est plus administrateur
        auth_logout();
        auth_session_start();
        flash('error', "Votre compte administrateur n'est plus actif : la session a été fermée.");
        redirect('login.php');
    }
    activity_log('security', 'user', null, 'Fin de prise de contrôle', [], current_user());
    flash('success', 'Vous êtes revenu sur votre compte administrateur.');
    redirect('users.php');
}

http_response_code(400);
flash('error', 'Action inconnue.');
redirect('index.php');
