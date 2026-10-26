<?php
/**
 * Modèle de configuration locale — Global Trading Corporation
 *
 * Copier ce fichier en includes/db.local.php puis compléter les valeurs.
 * includes/db.local.php contient des secrets : il n'est JAMAIS versionné (voir .gitignore).
 */

// ---------------------------------------------------------------------------
// Base de données
// Sans configuration : SQLite (fichier data/gtc.sqlite, créé automatiquement).
// Production MySQL / MariaDB : décommenter et compléter.
// ---------------------------------------------------------------------------
// define('DB_DRIVER', 'mysql');
// define('DB_HOST', 'localhost');
// define('DB_PORT', 3306);
// define('DB_NAME', 'gtc');
// define('DB_USER', 'gtc_user');
// define('DB_PASS', 'mot-de-passe-solide');

// ---------------------------------------------------------------------------
// Comptes créés automatiquement à l'installation (base vide uniquement)
//   admin@globaltradingcorporation.net  (Administrateur)
//   caisse@globaltradingcorporation.net (Caissier)
// Laissés en commentaire : aucun compte n'est créé, la page dashboard/setup.php
// fait créer le premier administrateur. Changez ces mots de passe après la première connexion.
// ---------------------------------------------------------------------------
// define('GTC_SEED_ADMIN_PASSWORD', 'choisir-un-mot-de-passe-solide');
// define('GTC_SEED_CASHIER_PASSWORD', 'choisir-un-autre-mot-de-passe');

// Sauvegardes automatiques (tools/auto-backup.php, tâche cron quotidienne) : dossier HORS de la racine web.
// Par défaut : dossier « gtc-backups » à côté du site, sinon data/backups. Historique conservé : 30 jours.
// define('GTC_BACKUP_DIR', '/home/COMPTE/gtc-backups');
// define('GTC_BACKUP_KEEP_DAYS', 30);
