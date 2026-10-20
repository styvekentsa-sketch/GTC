<?php
/**
 * Déconnexion (POST + jeton CSRF uniquement : un simple lien ne peut pas déconnecter).
 */
require_once __DIR__ . '/../includes/dashboard.php';

auth_session_start();

if (is_post()) {
    csrf_verify();
    auth_logout();
    auth_session_start();
    flash('success', 'Vous êtes déconnecté.');
}

redirect('login.php');
