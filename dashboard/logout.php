<?php
/**
 * Déconnexion (POST + jeton CSRF uniquement : un simple lien ne peut pas déconnecter).
 */
require_once __DIR__ . '/../includes/dashboard.php';

auth_session_start();

if (is_post()) {
    csrf_verify();
    // Jeton « Se souvenir de moi » du titulaire de la session (l'administrateur lors d'une prise de contrôle)
    activity_log('logout', 'session', isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, (string) ($_SESSION['user_name'] ?? ''));
    auth_remember_forget((int) ($_SESSION['original_admin_id'] ?? $_SESSION['user_id'] ?? 0) ?: null);
    auth_logout();
    auth_session_start();
    flash('success', 'Vous êtes déconnecté.');
}

redirect('login.php');
