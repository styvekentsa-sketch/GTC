<?php
/**
 * Ancien module « Impression réseau » (file des travaux d'impression) — retiré du Dashboard le 11/11/2026.
 * Un ancien favori ou lien mène ici : retour au Hub avec un message. Les imprimantes utilisées pour les reçus
 * de caisse se gèrent toujours dans Paramètres (administrateurs) ; la table print_jobs est conservée (historique).
 */
require_once __DIR__ . '/../includes/dashboard.php';

require_login();
flash('warning', 'Le module « Impression réseau » a été retiré. Les imprimantes des reçus se gèrent dans Paramètres.');
redirect('index.php');
