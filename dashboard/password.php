<?php
/**
 * Ancienne page de changement de mot de passe.
 *
 * Le changement de mot de passe n'est plus imposé à la connexion : il se fait librement dans
 * Paramètres → Mon profil & sécurité. Cette adresse est conservée pour les anciens liens et favoris.
 */
require_once __DIR__ . '/../includes/dashboard.php';

require_login();
redirect('settings.php#profil');
