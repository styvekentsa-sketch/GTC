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

// GTC-Vault (dossiers KYC, dashboard/vault/) : dossier des pièces d'identité, de préférence HORS de la racine web
// (au-dessus de public_html). Par défaut : data/kyc (interdit d'accès par .htaccess). Le dossier doit exister et être
// accessible en écriture à PHP. Pensez à le sauvegarder : les sauvegardes .sql ne contiennent que la base, pas les fichiers.
// define('GTC_KYC_DIR', '/home/COMPTE/gtc-kyc');

// Assistant de rédaction des courriers (dashboard/courriers.php) : SUSPENDU par défaut. Pour l'activer :
// define('GTC_AI_ASSISTANT', true);
// puis la clé API Claude d'Anthropic
// (https://platform.claude.com → API Keys). Sans clé, l'assistant reste affiché mais désactivé.
// define('GTC_AI_API_KEY', 'sk-ant-...');          // ou variable d'environnement GTC_AI_API_KEY (SetEnv dans .htaccess)
// define('GTC_AI_MODEL', 'claude-opus-5-5');   // facultatif
// Poste Windows uniquement, si l'assistant signale « injoignable » (erreur de certificat SSL) : liste des certificats
// racine, par ex. celle fournie avec Git. Inutile chez Hostinger.
// define('GTC_AI_CA_BUNDLE', 'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt');
