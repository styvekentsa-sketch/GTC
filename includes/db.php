<?php
/**
 * Connexion PDO sécurisée — Global Trading Corporation
 *
 * Configuration :
 *   Par défaut : SQLite (fichier data/gtc.sqlite), idéal en local, aucune installation.
 *   Production : créer includes/db.local.php (jamais versionné / partagé ; modèle : includes/db.local.example.php) :
 *
 *     <?php
 *     define('DB_DRIVER', 'mysql');
 *     define('DB_HOST', 'localhost');
 *     define('DB_PORT', 3306);
 *     define('DB_NAME', 'gtc');
 *     define('DB_USER', 'gtc_user');
 *     define('DB_PASS', 'mot-de-passe-solide');
 *
 * Les tables sont créées automatiquement à la première connexion.
 * Toutes les requêtes de l'application utilisent des requêtes préparées (anti-injection SQL).
 */

if (is_file(__DIR__ . '/db.local.php')) {
    require_once __DIR__ . '/db.local.php';
}

defined('DB_DRIVER')      || define('DB_DRIVER', 'sqlite');           // 'sqlite' | 'mysql'
defined('DB_SQLITE_PATH') || define('DB_SQLITE_PATH', dirname(__DIR__) . '/data/gtc.sqlite');
defined('DB_HOST')        || define('DB_HOST', 'localhost');
defined('DB_PORT')        || define('DB_PORT', 3306);
defined('DB_NAME')        || define('DB_NAME', 'gtc');
defined('DB_USER')        || define('DB_USER', '');
defined('DB_PASS')        || define('DB_PASS', '');

/**
 * Retourne l'instance PDO unique (connexion paresseuse).
 * En cas d'échec : journalisation de l'erreur réelle, message générique à l'utilisateur.
 */
function db(): PDO
{
    try {
        return db_connect();
    } catch (PDOException $e) {
        error_log('[GTC] Connexion base de données impossible : ' . $e->getMessage());
        http_response_code(503);
        exit('Service momentanément indisponible. Merci de réessayer dans quelques instants.');
    }
}

/** Variante non bloquante : retourne null si la base est indisponible (pages publiques). */
function db_try(): ?PDO
{
    try {
        return db_connect();
    } catch (PDOException $e) {
        error_log('[GTC] Base de données indisponible : ' . $e->getMessage());
        return null;
    }
}

/** Ouvre la connexion (une seule fois par requête). Lève PDOException en cas d'échec. */
function db_connect(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if (DB_DRIVER === 'mysql') {
        $dsn  = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
    } else {
        $dir = dirname(DB_SQLITE_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $conn = new PDO('sqlite:' . DB_SQLITE_PATH, null, null, $options);
        $conn->exec('PRAGMA foreign_keys = ON');
        $conn->exec('PRAGMA journal_mode = WAL');
    }

    db_ensure_schema($conn);
    return $pdo = $conn;
}

/** Date/heure courante au format SQL (fuseau Africa/Douala). */
function db_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->format('Y-m-d H:i:s');
}

/** Exécute une requête préparée et retourne le PDOStatement. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Première ligne d'un résultat (ou null). */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Toutes les lignes d'un résultat. */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** Valeur scalaire (première colonne de la première ligne). */
function db_value(string $sql, array $params = [])
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Crée les tables manquantes (installation initiale puis ajouts ultérieurs).
 * Deux tests légers par requête HTTP ; chaque création n'a lieu qu'une fois.
 */
function db_ensure_schema(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'users')) {
        db_install_core($pdo);
        db_seed_accounts($pdo);
    } elseif (!db_column_exists($pdo, 'users', 'status')) {
        // Base installée avant la gestion des rôles : conversion de la table users
        db_upgrade_users($pdo);
        db_seed_accounts($pdo);
    }
    if (!db_table_exists($pdo, 'print_jobs')) {
        // Base incomplète : les tables manquantes sont créées (CREATE TABLE IF NOT EXISTS)
        db_install_core($pdo);
    }
    if (!db_table_exists($pdo, 'printers')) {
        db_install_printers($pdo);
    } elseif (!db_column_exists($pdo, 'printers', 'printer_name')) {
        // Ancienne table (name, location…) : conversion vers printer_name / printer_location / is_default
        db_rebuild_printers($pdo);
    }
    if (!db_column_exists($pdo, 'printers', 'direct_print')) {
        // Envoi direct au périphérique (RAW 9100 / IPP 631) sans boîte de dialogue du navigateur
        $pdo->exec('ALTER TABLE printers ADD COLUMN direct_print ' . (DB_DRIVER === 'mysql' ? 'TINYINT(1)' : 'SMALLINT') . ' NOT NULL DEFAULT 0');
    }
    if (!db_table_exists($pdo, 'invoices')) {
        db_create_invoices($pdo);
    }
    if (!db_column_exists($pdo, 'users', 'phone')) {
        db_upgrade_users_profile($pdo);
    }
    if (!db_table_exists($pdo, 'company_settings')) {
        db_install_settings($pdo);
    }
    if (!db_table_exists($pdo, 'site_visits')) {
        db_install_visits($pdo);
    }
    if (!db_table_exists($pdo, 'team_members')) {
        db_install_team($pdo);
    }
    if (!db_column_exists($pdo, 'invoices', 'invoice_number')) {
        db_rebuild_invoices($pdo);
    }
    if (!db_column_exists($pdo, 'invoices', 'printer_id')) {
        // Imprimante de sortie choisie à la création de la facture (réutilisée pour les réimpressions)
        $pdo->exec('ALTER TABLE invoices ADD COLUMN printer_id ' . db_types()['fk'] . ' NULL');
    }
    if (!db_column_exists($pdo, 'invoices', 'transaction_reason')) {
        // Motif de l'opération et émetteur / donneur d'ordre (affichés sur les bordereaux)
        $pdo->exec('ALTER TABLE invoices ADD COLUMN transaction_reason VARCHAR(255) NULL');
        $pdo->exec('ALTER TABLE invoices ADD COLUMN sender_name VARCHAR(255) NULL');
    }
    if (db_invoices_restricted($pdo)) {
        // Anciennes listes figées (ENUM / CHECK) : Dépôt, Retrait, Orange Money… seraient refusés
        db_widen_invoice_lists($pdo);
    }
    if (!db_table_exists($pdo, 'commercial_invoices')) {
        // Factures commerciales multi-lignes (dashboard/invoices-list.php), distinctes des reçus de caisse
        db_install_commercial_invoices($pdo);
    }
    if (!db_column_exists($pdo, 'commercial_invoices', 'columns_json')) {
        // Tableau personnalisable : colonnes propres à chaque facture, cellules de chaque ligne en JSON
        db_upgrade_commercial_invoices_flexible($pdo);
    }
    if (!db_table_exists($pdo, 'courriers')) {
        // Courriers officiels sur papier à en-tête (dashboard/courriers.php, includes/courriers.php)
        db_install_courriers($pdo);
    }
    if (!db_table_exists($pdo, 'exchange_rates')) {
        // Grille des taux de l'entreprise (achat / vente), remplie par includes/company_rates.php
        db_install_company_rates($pdo);
    }
    if (db_users_roles_outdated($pdo)) {
        // Rôle « Secrétaire / Accueil » absent de l'ancienne liste figée (ENUM / CHECK)
        db_widen_user_roles($pdo);
    }
    if (db_table_exists($pdo, 'messages') && !db_column_exists($pdo, 'messages', 'sender_name')) {
        // Messagerie : expéditeur (sender_*) et statut (non lu, lu, répondu, archivé) au lieu de is_read
        db_upgrade_messages($pdo);
    }
    if (!db_column_exists($pdo, 'users', 'session_version')) {
        // Version de session : augmentée à chaque changement de mot de passe, elle ferme les sessions ouvertes ailleurs
        $pdo->exec('ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1');
    }
    if (!db_column_exists($pdo, 'users', 'remember_token')) {
        // « Se souvenir de moi » : empreinte SHA-256 du jeton du cookie et date de fin de validité
        $pdo->exec('ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) NULL');
        $pdo->exec('ALTER TABLE users ADD COLUMN remember_expires ' . db_types()['dt'] . ' NULL');
    }
    if (!db_table_exists($pdo, 'partners')) {
        db_install_partners($pdo);
    }
    if (!db_table_exists($pdo, 'impersonation_log')) {
        db_install_impersonation($pdo);
    }
    // Changement de mot de passe plus jamais imposé à la connexion : annule le statut « provisoire »
    // des comptes existants (une seule fois : plus aucun code ne remet ce champ à 1)
    if ($pdo->query('SELECT 1 FROM users WHERE must_change_password <> 0 LIMIT 1')->fetchColumn()) {
        $pdo->exec('UPDATE users SET must_change_password = 0 WHERE must_change_password <> 0');
    }
}

/**
 * Table des factures / bordereaux de transaction.
 *
 * Colonnes demandées : invoice_number, client_name, client_phone, operation_type,
 * source_currency, target_currency, amount_given, exchange_rate, amount_received,
 * payment_method, agent_id, agent_name, created_at.
 * Colonnes complémentaires : rate_direction (taux saisi « 1 source = X cible » ou
 * « 1 cible = X source »), status et issue_date (statistiques du tableau de bord),
 * client_email, description, fees, due_date (historique des anciennes factures).
 *
 * agent_id → users(id) en ON DELETE RESTRICT : un compte ayant émis des factures ne peut pas
 * être supprimé (il se désactive), afin de ne jamais perdre de pièces comptables.
 */
function db_create_invoices(PDO $pdo): void
{
    ['pk' => $pk, 'fk' => $fk, 'dt' => $dt, 'suffix' => $suffix] = db_types();
    $mysql = DB_DRIVER === 'mysql';

    // Type d'opération et mode de paiement : valeurs vérifiées par l'application (OPERATION_TYPES,
    // PAYMENT_METHODS dans includes/dashboard.php) pour pouvoir en ajouter sans modifier la table
    $operation = 'VARCHAR(30)';
    $payment   = 'VARCHAR(30)';
    $decimal   = $mysql ? 'DECIMAL(15,2)' : 'NUMERIC';
    $rate      = $mysql ? 'DECIMAL(15,4)' : 'NUMERIC';
    $ts        = $mysql ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TEXT DEFAULT CURRENT_TIMESTAMP';

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id {$pk},
        invoice_number VARCHAR(50) NOT NULL UNIQUE,
        client_name VARCHAR(150) NOT NULL,
        client_phone VARCHAR(50) NULL,
        client_email VARCHAR(190) NULL,
        operation_type {$operation} NOT NULL,
        source_currency VARCHAR(10) NOT NULL,
        target_currency VARCHAR(10) NOT NULL,
        amount_given {$decimal} NOT NULL,
        exchange_rate {$rate} NOT NULL,
        rate_direction VARCHAR(10) NOT NULL DEFAULT 'multiply',
        amount_received {$decimal} NOT NULL,
        fees {$decimal} NOT NULL DEFAULT 0,
        payment_method {$payment} DEFAULT 'Espèces',
        transaction_reason VARCHAR(255) NULL,
        sender_name VARCHAR(255) NULL,
        printer_id {$fk} NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'paid',
        issue_date DATE NOT NULL,
        due_date DATE NULL,
        description TEXT NULL,
        agent_id {$fk} NOT NULL,
        agent_name VARCHAR(100) NOT NULL,
        created_at {$ts},
        updated_at {$dt} NULL,
        FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE RESTRICT
    ){$suffix}");

    foreach ([
        'CREATE INDEX idx_invoices_status ON invoices (status)',
        'CREATE INDEX idx_invoices_issue ON invoices (issue_date)',
        'CREATE INDEX idx_invoices_agent ON invoices (agent_id)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException) {
            // index déjà existant
        }
    }
}

/**
 * Visites du site public : un visiteur (empreinte d'IP) compté une seule fois par jour.
 * La contrainte UNIQUE (ip_address, visit_date) empêche les doublons lors des rafraîchissements.
 */
/**
 * Profil utilisateur : téléphone et historique des connexions
 * (connexion actuelle et précédente, avec leur adresse IP, pour repérer un accès inhabituel).
 */
function db_upgrade_users_profile(PDO $pdo): void
{
    ['dt' => $dt] = db_types();
    foreach ([
        'phone VARCHAR(50) NULL',
        'last_login_ip VARCHAR(45) NULL',
        "previous_login_at {$dt} NULL",
        'previous_login_ip VARCHAR(45) NULL',
    ] as $column) {
        $pdo->exec("ALTER TABLE users ADD COLUMN {$column}");
    }
}

/** Paramètres de l'entreprise (clé / valeur) et leurs valeurs par défaut. */
const DEFAULT_SETTINGS = [
    'company_name'       => 'Global Trading Corporation',
    'company_address'    => 'Rue Paul Soppo Priso, Douala',
    'company_phone'      => '+237 233 43 13 22',
    'company_phone2'     => '+237 673 66 00 00',
    'company_email'      => 'gtc8181@gmail.com',
    'company_website'    => 'globaltradingcorporation.net',
    'company_logo'       => '',
    'company_niu'        => '',   // NIU de l'entreprise, imprimé sur toutes les factures
    'currency'           => 'FCFA',
    'print_format'       => 'A4',
    'commission_percent' => '0',
    'printer_scan_subnet' => '',   // sous-réseau analysé pour détecter les imprimantes (ex. 192.168.1.0/24)
    'rates_source'        => 'market',   // 'market' : cours du marché automatiques · 'manual' : taux saisis
    'market_margin_percent' => '0',      // marge ajoutée aux cours du marché (%)
];

function db_install_settings(PDO $pdo): void
{
    ['suffix' => $suffix] = db_types();
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    ){$suffix}");

    $insert = $pdo->prepare(DB_DRIVER === 'mysql'
        ? 'INSERT IGNORE INTO company_settings (setting_key, setting_value) VALUES (?, ?)'
        : 'INSERT OR IGNORE INTO company_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach (DEFAULT_SETTINGS as $key => $value) {
        $insert->execute([$key, $value]);
    }
}

function db_install_visits(PDO $pdo): void
{
    ['pk' => $pk, 'suffix' => $suffix] = db_types();
    $mysql = DB_DRIVER === 'mysql';
    $ts    = $mysql ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TEXT DEFAULT CURRENT_TIMESTAMP';

    $pdo->exec("CREATE TABLE IF NOT EXISTS site_visits (
        id {$pk},
        ip_address VARCHAR(45) NOT NULL,
        visit_date DATE NOT NULL,
        created_at {$ts},
        " . ($mysql ? 'UNIQUE KEY unique_visit (ip_address, visit_date)' : 'CONSTRAINT unique_visit UNIQUE (ip_address, visit_date)') . "
    ){$suffix}");

    try {
        $pdo->exec('CREATE INDEX idx_visits_date ON site_visits (visit_date)');
    } catch (PDOException) {
        // index déjà existant
    }
}

/** Liste des colonnes d'une table (SQLite / MySQL). */
function db_columns(PDO $pdo, string $table): array
{
    return DB_DRIVER === 'mysql'
        ? $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN)
        : array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
}

/**
 * Reconstruit la table invoices dans la nouvelle structure en conservant toutes les factures.
 * Correspondances : number → invoice_number, amount → amount_given, currency → source_currency,
 * rate → exchange_rate, net_amount → amount_received, created_by → agent_id ;
 * types et modes de règlement convertis vers les nouvelles valeurs.
 */
function db_rebuild_invoices(PDO $pdo): void
{
    $mysql = DB_DRIVER === 'mysql';
    $pdo->exec($mysql ? 'RENAME TABLE invoices TO invoices_legacy' : 'ALTER TABLE invoices RENAME TO invoices_legacy');

    // Index SQLite : leurs noms sont globaux, on libère ceux de l'ancienne table
    if (!$mysql) {
        foreach (['idx_invoices_status', 'idx_invoices_issue', 'idx_invoices_agent'] as $index) {
            $pdo->exec("DROP INDEX IF EXISTS {$index}");
        }
    }
    db_create_invoices($pdo);

    $fallbackAgent = $pdo->query("SELECT id, name FROM users ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, id LIMIT 1")->fetch();
    $userNames     = $pdo->query('SELECT id, name FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
    $operations    = ['exchange_buy' => 'Exchange', 'exchange_sell' => 'Exchange', 'transfer' => 'Transfert', 'import_export' => 'Import/Export'];
    $payments      = ['cash' => 'Espèces', 'transfer' => 'Virement'];

    $insert = $pdo->prepare(
        'INSERT INTO invoices (id, invoice_number, client_name, client_phone, client_email, operation_type, source_currency,
         target_currency, amount_given, exchange_rate, rate_direction, amount_received, fees, payment_method, status,
         issue_date, due_date, description, agent_id, agent_name, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($pdo->query('SELECT * FROM invoices_legacy ORDER BY id')->fetchAll() as $row) {
        $source = $row['currency'] ?? 'XAF';
        $target = ($row['target_currency'] ?? null) ?: $source;
        $rate   = (float) (($row['rate'] ?? null) ?: 1);
        $dir    = 'multiply';
        if ($rate > 0 && $rate < 0.01) {
            // Très petit taux (ex. 1 FCFA = 0,001524 EUR) : stocké en sens inverse pour garder la précision
            $rate = round(1 / $rate, 4);
            $dir  = 'divide';
        }
        $agentId = (int) (($row['created_by'] ?? null) ?: $fallbackAgent['id']);
        $agentId = isset($userNames[$agentId]) ? $agentId : (int) $fallbackAgent['id'];

        $insert->execute([
            $row['id'],
            $row['number'],
            $row['client_name'],
            $row['client_phone'] ?? null,
            $row['client_email'] ?? null,
            $operations[$row['operation_type'] ?? ''] ?? 'Exchange',
            $source,
            $target,
            $row['amount'],
            $rate,
            $dir,
            ($row['net_amount'] ?? null) ?? $row['amount'],
            $row['fees'] ?? 0,
            $payments[$row['payment_method'] ?? ''] ?? 'Espèces',
            $row['status'] ?? 'paid',
            $row['issue_date'],
            $row['due_date'] ?? null,
            $row['description'] ?? null,
            $agentId,
            ($row['agent_name'] ?? null) ?: ($userNames[$agentId] ?? 'Inconnu'),
            $row['created_at'] ?? db_now(),
            $row['updated_at'] ?? null,
        ]);
    }

    $pdo->exec('DROP TABLE invoices_legacy');
}

function db_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        return true;
    } catch (PDOException) {
        return false;
    }
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $pdo->query("SELECT {$column} FROM {$table} LIMIT 1");
        return true;
    } catch (PDOException) {
        return false;
    }
}

/**
 * Comptes créés à l'installation (ou lors de la conversion d'une ancienne base), UNIQUEMENT si leur mot de passe
 * est défini dans includes/db.local.php (fichier jamais versionné, voir includes/db.local.example.php) :
 *   - administrateur : GTC_SEED_ADMIN_PASSWORD, seulement s'il n'existe AUCUN administrateur ;
 *   - caissier       : GTC_SEED_CASHIER_PASSWORD, seulement si son adresse e-mail n'existe pas encore.
 * Sans ces mots de passe, aucun compte n'est créé : dashboard/setup.php fait créer le premier administrateur.
 * Aucun mot de passe n'est donc écrit dans le code publié.
 */
const DEFAULT_ADMIN_EMAIL   = 'admin@globaltradingcorporation.net';
const DEFAULT_CASHIER_EMAIL = 'caisse@globaltradingcorporation.net';

/** Mots de passe d'installation définis localement : interdits comme nouveaux mots de passe. */
function db_seed_passwords(): array
{
    return array_values(array_filter([
        defined('GTC_SEED_ADMIN_PASSWORD') ? (string) GTC_SEED_ADMIN_PASSWORD : '',
        defined('GTC_SEED_CASHIER_PASSWORD') ? (string) GTC_SEED_CASHIER_PASSWORD : '',
    ]));
}

function db_seed_accounts(PDO $pdo): void
{
    $insert = $pdo->prepare(
        "INSERT INTO users (name, email, password, role, status, must_change_password, created_at)
         VALUES (?, ?, ?, ?, 'actif', 0, ?)"
    );

    $adminPassword   = defined('GTC_SEED_ADMIN_PASSWORD') ? (string) GTC_SEED_ADMIN_PASSWORD : '';
    $cashierPassword = defined('GTC_SEED_CASHIER_PASSWORD') ? (string) GTC_SEED_CASHIER_PASSWORD : '';

    $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($adminPassword !== '' && $admins === 0 && !db_email_exists($pdo, DEFAULT_ADMIN_EMAIL)) {
        $insert->execute(['Administrateur', DEFAULT_ADMIN_EMAIL, password_hash($adminPassword, PASSWORD_DEFAULT), 'admin', db_now()]);
    }
    if ($cashierPassword !== '' && !db_email_exists($pdo, DEFAULT_CASHIER_EMAIL)) {
        $insert->execute(['Caisse principale', DEFAULT_CASHIER_EMAIL, password_hash($cashierPassword, PASSWORD_DEFAULT), 'caissier', db_now()]);
    }
}

function db_email_exists(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Conversion d'une table users antérieure vers la structure avec rôles :
 *   password_hash → password · is_active (1/0) → status (actif/inactif) · rôle « staff » → « caissier ».
 * Aucun compte n'est perdu.
 */
function db_upgrade_users(PDO $pdo): void
{
    $mysql = DB_DRIVER === 'mysql';

    if (!db_column_exists($pdo, 'users', 'must_change_password')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password SMALLINT NOT NULL DEFAULT 0');
    }

    $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'actif'");
    if (db_column_exists($pdo, 'users', 'is_active')) {
        $pdo->exec("UPDATE users SET status = CASE WHEN is_active = 1 THEN 'actif' ELSE 'inactif' END");
        try {
            $pdo->exec('ALTER TABLE users DROP COLUMN is_active');
        } catch (PDOException) {
            // ancien moteur SQLite sans DROP COLUMN : la colonne reste, inutilisée
        }
    }

    if (db_column_exists($pdo, 'users', 'password_hash')) {
        $pdo->exec($mysql
            ? 'ALTER TABLE users CHANGE password_hash password VARCHAR(255) NOT NULL'
            : 'ALTER TABLE users RENAME COLUMN password_hash TO password');
    }

    $pdo->exec("UPDATE users SET role = 'caissier' WHERE role NOT IN ('" . implode("', '", USER_ROLE_KEYS) . "')");

    if ($mysql) {
        $pdo->exec("ALTER TABLE users
            MODIFY role " . db_enum_type('role', USER_ROLE_KEYS) . " NOT NULL DEFAULT 'caissier',
            MODIFY status ENUM('actif', 'inactif') NOT NULL DEFAULT 'actif'");
    }
}

/** Types SQL adaptés au moteur (MySQL / SQLite). */
function db_types(): array
{
    $mysql = DB_DRIVER === 'mysql';
    return [
        'pk'     => $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'fk'     => $mysql ? 'INT UNSIGNED' : 'INTEGER',
        'dt'     => $mysql ? 'DATETIME' : 'TEXT',
        'money'  => $mysql ? 'DECIMAL(15,2)' : 'NUMERIC',
        'suffix' => $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
    ];
}

/**
 * Équipe affichée sur la page « À propos » (gérée depuis dashboard/team.php).
 * Les 4 membres actuels sont insérés à la création de la table.
 */
function db_install_team(PDO $pdo): void
{
    ['pk' => $pk, 'dt' => $dt, 'suffix' => $suffix] = db_types();

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_members (
        id {$pk},
        name VARCHAR(100) NOT NULL,
        role VARCHAR(100) NOT NULL,
        photo VARCHAR(255) DEFAULT 'default-avatar.png',
        display_order INT DEFAULT 0,
        created_at {$dt} NOT NULL
    ){$suffix}");

    if ((int) $pdo->query('SELECT COUNT(*) FROM team_members')->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO team_members (name, role, display_order, created_at) VALUES (?, ?, ?, ?)');
        foreach ([
            ['Chérif Alioum',  'Directeur Général',        1],
            ['Vanessa Ngakam', 'Secrétaire',               2],
            ['Flore Sany',     'Réceptionniste',           3],
            ['Faissal Issa',   'Responsable Opérationnel', 4],
        ] as [$name, $role, $order]) {
            $insert->execute([$name, $role, $order, db_now()]);
        }
    }
}

/**
 * Imprimantes réseau de l'agence.
 *
 * Colonnes demandées : printer_name, printer_location, is_default.
 * Colonnes complémentaires : paper_format (format de papier appliqué automatiquement à l'impression),
 * host / port (adresse réseau, facultative, pour le test de connexion), direct_print (envoi direct au périphérique),
 * is_active, last_status, last_checked_at.
 */
const DEFAULT_PRINTERS = [
    // [nom, emplacement, format de papier, par défaut]
    ['Imprimante Guichet Caisse',        'Guichet principal — Rez-de-chaussée', 'a5',     1],
    ['Imprimante Bureau Administration', 'Bureau de la direction',              'a4',     0],
    ['Imprimante Thermique Ticket',      'Guichet caisse — Comptoir',           'ticket', 0],
];

function db_create_printers(PDO $pdo): void
{
    ['pk' => $pk, 'dt' => $dt, 'suffix' => $suffix] = db_types();
    $bool = DB_DRIVER === 'mysql' ? 'TINYINT(1)' : 'SMALLINT';

    $pdo->exec("CREATE TABLE IF NOT EXISTS printers (
        id {$pk},
        printer_name VARCHAR(100) NOT NULL,
        printer_location VARCHAR(100) NOT NULL,
        is_default {$bool} DEFAULT 0,
        paper_format VARCHAR(10) NOT NULL DEFAULT 'a4',
        host VARCHAR(190) NULL,
        port INT NOT NULL DEFAULT 9100,
        direct_print {$bool} NOT NULL DEFAULT 0,
        is_active {$bool} NOT NULL DEFAULT 1,
        last_status VARCHAR(20) NULL,
        last_checked_at {$dt} NULL,
        created_at {$dt} NULL
    ){$suffix}");
}

function db_install_printers(PDO $pdo): void
{
    db_create_printers($pdo);
    if ((int) $pdo->query('SELECT COUNT(*) FROM printers')->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO printers (printer_name, printer_location, paper_format, is_default, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach (DEFAULT_PRINTERS as $printer) {
            $insert->execute([...$printer, db_now()]);
        }
    }
}

/**
 * Conversion de l'ancienne table printers (name, host, port, location…) sans perte :
 * name → printer_name, location → printer_location ; la première imprimante active devient celle par défaut.
 * Les identifiants sont conservés (travaux d'impression déjà assignés).
 */
function db_rebuild_printers(PDO $pdo): void
{
    $pdo->exec(DB_DRIVER === 'mysql' ? 'RENAME TABLE printers TO printers_legacy' : 'ALTER TABLE printers RENAME TO printers_legacy');
    db_create_printers($pdo);

    $insert = $pdo->prepare(
        'INSERT INTO printers (id, printer_name, printer_location, is_default, paper_format, host, port, is_active, last_status, last_checked_at, created_at)
         VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($pdo->query('SELECT * FROM printers_legacy ORDER BY id')->fetchAll() as $row) {
        $insert->execute([
            $row['id'],
            $row['name'],
            ($row['location'] ?? '') !== '' ? $row['location'] : 'Agence de Douala',
            'a4',
            ($row['host'] ?? '') !== '' ? $row['host'] : null,
            (int) ($row['port'] ?? 9100),
            (int) ($row['is_active'] ?? 1),
            $row['last_status'] ?? null,
            $row['last_checked_at'] ?? null,
            $row['created_at'] ?? db_now(),
        ]);
    }
    $pdo->exec('DROP TABLE printers_legacy');

    $first = $pdo->query('SELECT id FROM printers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if ($first) {
        $pdo->prepare('UPDATE printers SET is_default = 1 WHERE id = ?')->execute([$first]);
    } else {
        db_install_printers($pdo);   // aucune imprimante : imprimantes par défaut de l'agence
    }
}

/**
 * Partenaires affichés sur la page d'accueil (section « Nos Partenaires », gérée dans dashboard/partners.php).
 * logo : nom du fichier dans assets/img/partners/ (vide : le nom du partenaire est affiché à la place).
 */
function db_install_partners(PDO $pdo): void
{
    ['pk' => $pk, 'dt' => $dt, 'suffix' => $suffix] = db_types();
    $bool = DB_DRIVER === 'mysql' ? 'TINYINT(1)' : 'SMALLINT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS partners (
        id {$pk},
        name VARCHAR(100) NOT NULL,
        logo VARCHAR(255) NULL,
        website VARCHAR(255) NULL,
        display_order INT NOT NULL DEFAULT 0,
        is_active {$bool} NOT NULL DEFAULT 1,
        created_at {$dt} NOT NULL
    ){$suffix}");

    if ((int) $pdo->query('SELECT COUNT(*) FROM partners')->fetchColumn() === 0) {
        $insert = $pdo->prepare('INSERT INTO partners (name, display_order, created_at) VALUES (?, ?, ?)');
        foreach (['DHL', 'IRAN CAMEROON Trade Center', 'Bytes'] as $i => $name) {
            $insert->execute([$name, $i + 1, db_now()]);
        }
    }
}

/**
 * Journal des prises de contrôle de compte (« Se connecter en tant que… », dashboard/switch-user.php) :
 * quel administrateur a utilisé quel compte, quand, et depuis quelle adresse IP.
 */
function db_install_impersonation(PDO $pdo): void
{
    ['pk' => $pk, 'fk' => $fk, 'dt' => $dt, 'suffix' => $suffix] = db_types();
    $pdo->exec("CREATE TABLE IF NOT EXISTS impersonation_log (
        id {$pk},
        admin_id {$fk} NOT NULL,
        target_id {$fk} NOT NULL,
        ip VARCHAR(45) NULL,
        started_at {$dt} NOT NULL,
        ended_at {$dt} NULL
    ){$suffix}");
}

/** La table invoices limite-t-elle encore le type d'opération / mode de paiement (ENUM ou CHECK d'origine) ? */
function db_invoices_restricted(PDO $pdo): bool
{
    if (DB_DRIVER === 'mysql') {
        $col = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'operation_type'")->fetch();
        return $col && stripos((string) $col['Type'], 'enum') === 0;
    }
    $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'invoices'")->fetchColumn();
    return str_contains($sql, 'CHECK (operation_type') || str_contains($sql, 'CHECK (payment_method');
}

/**
 * Libère le type d'opération et le mode de paiement (VARCHAR) sans perdre de facture.
 * MySQL : modification des colonnes ; SQLite : table reconstruite (une contrainte CHECK ne peut pas être retirée).
 */
function db_widen_invoice_lists(PDO $pdo): void
{
    if (DB_DRIVER === 'mysql') {
        $pdo->exec("ALTER TABLE invoices MODIFY operation_type VARCHAR(30) NOT NULL, MODIFY payment_method VARCHAR(30) NULL DEFAULT 'Espèces'");
        return;
    }
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec('ALTER TABLE invoices RENAME TO invoices_v1');
        foreach (['idx_invoices_status', 'idx_invoices_issue', 'idx_invoices_agent'] as $index) {
            $pdo->exec("DROP INDEX IF EXISTS {$index}");
        }
        db_create_invoices($pdo);
        $columns = implode(', ', array_intersect(db_columns($pdo, 'invoices_v1'), db_columns($pdo, 'invoices')));
        $pdo->exec("INSERT INTO invoices ({$columns}) SELECT {$columns} FROM invoices_v1");
        $pdo->exec('DROP TABLE invoices_v1');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

/**
 * Factures commerciales (facture, facture proforma, devis) : en-tête + lignes d'articles.
 * Tableau personnalisable : columns_json = colonnes de la facture [{key, label, type: text|amount}],
 * cells_json = cellules de chaque ligne {clé: valeur}. description (1re colonne texte) et line_total (montant)
 * sont tenus à jour pour la recherche et les totaux.
 * Les montants sont recalculés côté serveur à chaque enregistrement (jamais repris tels quels du navigateur).
 * Numéro unique par type et par année : FAC-2026-0001, PRO-2026-0001, DEV-2026-0001.
 */
function db_install_commercial_invoices(PDO $pdo): void
{
    ['pk' => $pk, 'fk' => $fk, 'dt' => $dt, 'money' => $money, 'suffix' => $suffix] = db_types();
    $mysql  = DB_DRIVER === 'mysql';
    $rate   = $mysql ? 'DECIMAL(5,2)' : 'NUMERIC';
    $ts     = $mysql ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TEXT DEFAULT CURRENT_TIMESTAMP';
    $type   = db_enum_type('doc_type', ['invoice', 'proforma', 'quote']);
    $status = db_enum_type('status', ['issued', 'paid', 'cancelled']);

    $pdo->exec("CREATE TABLE IF NOT EXISTS commercial_invoices (
            id {$pk},
            invoice_number VARCHAR(30) NOT NULL UNIQUE,
            doc_type {$type} NOT NULL DEFAULT 'invoice',
            status {$status} NOT NULL DEFAULT 'issued',
            client_name VARCHAR(150) NOT NULL,
            client_phone VARCHAR(50) NULL,
            client_email VARCHAR(150) NULL,
            client_address VARCHAR(255) NULL,
            client_niu VARCHAR(30) NULL,
            issue_date DATE NOT NULL,
            due_date DATE NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'XAF',
            tax_rate {$rate} NOT NULL DEFAULT 0,
            subtotal {$money} NOT NULL DEFAULT 0,
            tax_amount {$money} NOT NULL DEFAULT 0,
            total {$money} NOT NULL DEFAULT 0,
            notes TEXT NULL,
            columns_json TEXT NULL,
            created_by {$fk} NULL,
            created_by_name VARCHAR(100) NULL,
            created_at {$ts},
            updated_at {$dt} NULL
        ){$suffix}");
    $pdo->exec("CREATE TABLE IF NOT EXISTS commercial_invoice_items (
            id {$pk},
            invoice_id {$fk} NOT NULL,
            position INT NOT NULL DEFAULT 1,
            description VARCHAR(500) NOT NULL DEFAULT '',
            line_total {$money} NOT NULL DEFAULT 0,
            cells_json TEXT NULL,
            FOREIGN KEY (invoice_id) REFERENCES commercial_invoices(id) ON DELETE CASCADE
        ){$suffix}");
    foreach ([
        'CREATE INDEX idx_cinv_date ON commercial_invoices (issue_date)',
        'CREATE INDEX idx_cinv_client ON commercial_invoices (client_name)',
        'CREATE INDEX idx_citems_invoice ON commercial_invoice_items (invoice_id, position)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException) {
            // index déjà présent
        }
    }
}

/** Courriers officiels : destinataire, références, objet, corps, formule de politesse, signataire. */
function db_install_courriers(PDO $pdo): void
{
    ['pk' => $pk, 'fk' => $fk, 'dt' => $dt, 'suffix' => $suffix] = db_types();
    $ts     = DB_DRIVER === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TEXT DEFAULT CURRENT_TIMESTAMP';
    $status = db_enum_type('status', ['draft', 'sent']);

    $pdo->exec("CREATE TABLE IF NOT EXISTS courriers (
            id {$pk},
            reference VARCHAR(40) NOT NULL UNIQUE,
            status {$status} NOT NULL DEFAULT 'draft',
            recipient_company VARCHAR(150) NULL,
            recipient_name VARCHAR(150) NULL,
            recipient_role VARCHAR(150) NULL,
            recipient_address VARCHAR(255) NULL,
            recipient_city VARCHAR(100) NULL,
            recipient_country VARCHAR(100) NULL,
            place VARCHAR(100) NOT NULL DEFAULT 'Douala',
            letter_date DATE NOT NULL,
            subject VARCHAR(255) NOT NULL,
            salutation VARCHAR(150) NULL,
            body TEXT NOT NULL,
            closing VARCHAR(500) NULL,
            signatory_name VARCHAR(100) NOT NULL,
            signatory_title VARCHAR(100) NULL,
            attachments VARCHAR(255) NULL,
            created_by {$fk} NULL,
            created_by_name VARCHAR(100) NULL,
            created_at {$ts},
            updated_at {$dt} NULL
        ){$suffix}");
    foreach ([
        'CREATE INDEX idx_courriers_date ON courriers (letter_date)',
        'CREATE INDEX idx_courriers_company ON courriers (recipient_company)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException) {
            // index déjà présent
        }
    }
}

/**
 * Factures créées avant le tableau personnalisable (Désignation, Quantité, Prix unitaire, Montant) :
 * ajout des colonnes JSON, lignes reprises en « Désignation » + « Montant » (quantité et prix unitaire
 * intégrés au texte lorsqu'ils étaient utiles). Les anciennes colonnes restent, inutilisées.
 */
function db_upgrade_commercial_invoices_flexible(PDO $pdo): void
{
    $pdo->exec('ALTER TABLE commercial_invoices ADD COLUMN columns_json TEXT NULL');
    if (!db_column_exists($pdo, 'commercial_invoice_items', 'cells_json')) {
        $pdo->exec('ALTER TABLE commercial_invoice_items ADD COLUMN cells_json TEXT NULL');
    }
    $columns = json_encode([['key' => 'c1', 'label' => 'Désignation', 'type' => 'text'], ['key' => 'amount', 'label' => 'Montant', 'type' => 'amount']], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('UPDATE commercial_invoices SET columns_json = ? WHERE columns_json IS NULL')->execute([$columns]);

    $hasQty = db_column_exists($pdo, 'commercial_invoice_items', 'quantity');
    $update = $pdo->prepare('UPDATE commercial_invoice_items SET cells_json = ? WHERE id = ?');
    foreach ($pdo->query('SELECT * FROM commercial_invoice_items WHERE cells_json IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $text = (string) $item['description'];
        if ($hasQty && ((float) $item['quantity'] != 1.0)) {
            $text .= ' (' . rtrim(rtrim(number_format((float) $item['quantity'], 3, ',', ''), '0'), ',') . ' × ' . number_format((float) $item['unit_price'], 0, ',', ' ') . ')';
        }
        $update->execute([json_encode(['c1' => $text, 'amount' => (float) $item['line_total']], JSON_UNESCAPED_UNICODE), $item['id']]);
    }
}

/**
 * Taux de change de l'entreprise : une ligne par devise étrangère, valeurs en FCFA pour 1 unité.
 * buy_rate : achat / dépôt (le guichet achète la devise) · sell_rate : vente / retrait (le guichet la vend).
 */
function db_install_company_rates(PDO $pdo): void
{
    ['pk' => $pk, 'fk' => $fk, 'suffix' => $suffix] = db_types();
    $mysql = DB_DRIVER === 'mysql';
    $pdo->exec("CREATE TABLE IF NOT EXISTS exchange_rates (
            id {$pk},
            currency_code VARCHAR(10) NOT NULL UNIQUE,
            currency_name VARCHAR(100) NOT NULL,
            buy_rate " . ($mysql ? 'DECIMAL(18,6)' : 'NUMERIC') . " NOT NULL,
            sell_rate " . ($mysql ? 'DECIMAL(18,6)' : 'NUMERIC') . " NOT NULL,
            updated_at " . ($mysql ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TEXT DEFAULT CURRENT_TIMESTAMP') . ",
            updated_by {$fk} NULL
        ){$suffix}");
}

/** Rôles des comptes (libellés : USER_ROLES dans includes/dashboard.php). */
const USER_ROLE_KEYS = ['admin', 'caissier', 'agent', 'secretaire'];

/** Statuts d'un message reçu par le formulaire de contact. */
const MESSAGE_STATUS_KEYS = ['unread', 'read', 'replied', 'archived'];

/** Liste fermée : ENUM sous MySQL, VARCHAR + CHECK sous SQLite. */
function db_enum_type(string $column, array $values): string
{
    $list = "'" . implode("', '", $values) . "'";
    return DB_DRIVER === 'mysql' ? "ENUM({$list})" : "VARCHAR(20) CHECK ({$column} IN ({$list}))";
}

/** Structure de la table des comptes (installation et reconstruction). */
function db_users_table_sql(string $table): string
{
    ['pk' => $pk, 'dt' => $dt, 'suffix' => $suffix] = db_types();
    $ts     = DB_DRIVER === 'mysql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TEXT DEFAULT CURRENT_TIMESTAMP';
    $role   = db_enum_type('role', USER_ROLE_KEYS);
    $status = db_enum_type('status', ['actif', 'inactif']);

    return "CREATE TABLE IF NOT EXISTS {$table} (
            id {$pk},
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role {$role} NOT NULL DEFAULT 'caissier',
            status {$status} NOT NULL DEFAULT 'actif',
            must_change_password SMALLINT NOT NULL DEFAULT 0,
            session_version INT NOT NULL DEFAULT 1,
            remember_token VARCHAR(64) NULL,
            remember_expires {$dt} NULL,
            phone VARCHAR(50) NULL,
            last_login_at {$dt} NULL,
            last_login_ip VARCHAR(45) NULL,
            previous_login_at {$dt} NULL,
            previous_login_ip VARCHAR(45) NULL,
            created_at {$ts}
        ){$suffix}";
}

/** La liste des rôles de la table users est-elle figée sans le rôle « secretaire » ? */
function db_users_roles_outdated(PDO $pdo): bool
{
    if (DB_DRIVER === 'mysql') {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
        return $col && stripos((string) $col['Type'], 'enum') === 0 && !str_contains((string) $col['Type'], "'secretaire'");
    }
    $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    return str_contains($sql, 'CHECK (role IN') && !str_contains($sql, "'secretaire'");
}

/**
 * Ajoute les nouveaux rôles sans perdre de compte.
 * SQLite : nouvelle table, copie, puis renommage (procédure recommandée : les clés étrangères
 * de invoices.agent_id continuent de désigner « users »).
 */
function db_widen_user_roles(PDO $pdo): void
{
    if (DB_DRIVER === 'mysql') {
        $pdo->exec('ALTER TABLE users MODIFY role ' . db_enum_type('role', USER_ROLE_KEYS) . " NOT NULL DEFAULT 'caissier'");
        return;
    }
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec('DROP TABLE IF EXISTS users_new');
        $pdo->exec(db_users_table_sql('users_new'));
        $columns = implode(', ', array_intersect(db_columns($pdo, 'users'), db_columns($pdo, 'users_new')));
        $pdo->exec("INSERT INTO users_new ({$columns}) SELECT {$columns} FROM users");
        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_new RENAME TO users');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

/** Ancienne table messages (name, email, phone, is_read) → sender_name, sender_email, sender_phone, status. */
function db_upgrade_messages(PDO $pdo): void
{
    $status = db_enum_type('status', MESSAGE_STATUS_KEYS);
    if (DB_DRIVER === 'mysql') {
        $pdo->exec("ALTER TABLE messages
            CHANGE name sender_name VARCHAR(255) NOT NULL,
            CHANGE email sender_email VARCHAR(255) NOT NULL,
            CHANGE phone sender_phone VARCHAR(50) NULL,
            MODIFY subject VARCHAR(255) NOT NULL,
            ADD COLUMN status {$status} NOT NULL DEFAULT 'unread' AFTER message");
    } else {
        foreach (['name' => 'sender_name', 'email' => 'sender_email', 'phone' => 'sender_phone'] as $old => $new) {
            $pdo->exec("ALTER TABLE messages RENAME COLUMN {$old} TO {$new}");
        }
        $pdo->exec("ALTER TABLE messages ADD COLUMN status {$status} NOT NULL DEFAULT 'unread'");
    }
    if (db_column_exists($pdo, 'messages', 'is_read')) {
        $pdo->exec("UPDATE messages SET status = CASE WHEN is_read = 1 THEN 'read' ELSE 'unread' END");
        try {
            $pdo->exec(DB_DRIVER === 'mysql' ? 'DROP INDEX idx_messages_read ON messages' : 'DROP INDEX IF EXISTS idx_messages_read');
            $pdo->exec('ALTER TABLE messages DROP COLUMN is_read');
        } catch (PDOException) {
            // ancien moteur SQLite sans DROP COLUMN : la colonne reste, inutilisée
        }
    }
    try {
        $pdo->exec('CREATE INDEX idx_messages_status ON messages (status, id)');
    } catch (PDOException) {
        // index déjà présent
    }
}

/** Tables du site et du Dashboard (installation initiale). */
function db_install_core(PDO $pdo): void
{
    ['pk' => $pk, 'fk' => $fk, 'dt' => $dt, 'money' => $money, 'suffix' => $suffix] = db_types();

    // Rôles et statut : ENUM sous MySQL, VARCHAR + CHECK sous SQLite
    $mysql      = DB_DRIVER === 'mysql';
    $ts         = $mysql ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TEXT DEFAULT CURRENT_TIMESTAMP';
    $msgStatus  = db_enum_type('status', MESSAGE_STATUS_KEYS);

    $tables = [
        db_users_table_sql('users'),

        "CREATE TABLE IF NOT EXISTS login_attempts (
            id {$pk},
            ip VARCHAR(45) NOT NULL,
            email VARCHAR(190) NOT NULL,
            attempted_at {$dt} NOT NULL
        ){$suffix}",

        "CREATE TABLE IF NOT EXISTS print_jobs (
            id {$pk},
            title VARCHAR(190) NOT NULL,
            printer_id {$fk} NULL,
            copies INT NOT NULL DEFAULT 1,
            color SMALLINT NOT NULL DEFAULT 0,
            duplex SMALLINT NOT NULL DEFAULT 0,
            priority VARCHAR(10) NOT NULL DEFAULT 'normal',
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            notes TEXT NULL,
            requested_by {$fk} NULL,
            created_at {$dt} NOT NULL,
            updated_at {$dt} NOT NULL
        ){$suffix}",

        "CREATE TABLE IF NOT EXISTS messages (
            id {$pk},
            sender_name VARCHAR(255) NOT NULL,
            sender_email VARCHAR(255) NOT NULL,
            sender_phone VARCHAR(50) NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            ip VARCHAR(45) NULL,
            status {$msgStatus} NOT NULL DEFAULT 'unread',
            created_at {$ts}
        ){$suffix}",
    ];

    $indexes = [
        'CREATE INDEX idx_attempts_ip ON login_attempts (ip, attempted_at)',
        'CREATE INDEX idx_attempts_email ON login_attempts (email, attempted_at)',
        'CREATE INDEX idx_jobs_status ON print_jobs (status)',
        'CREATE INDEX idx_messages_status ON messages (status, id)',
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    foreach ($indexes as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException) {
            // index déjà existant
        }
    }
}
