<?php
/**
 * Gestion des comptes collaborateurs : administrateur, caissier, agent (réservé aux administrateurs).
 */
require_once __DIR__ . '/../includes/dashboard.php';

$user = require_login();
require_role('admin');

$errors = [];
$form   = ['name' => '', 'email' => '', 'role' => 'caissier'];

if (is_post()) {
    csrf_verify();
    $op     = post('op');
    $target = (int) post('id');

    if ($op === 'create') {
        $form = ['name' => post('name'), 'email' => mb_strtolower(post('email')), 'role' => post('role', 'caissier')];
        $password = (string) ($_POST['password'] ?? '');

        if (mb_strlen($form['name']) < 2 || mb_strlen($form['name']) > 100) {
            $errors['name'] = 'Nom requis.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 100) {
            $errors['email'] = 'E-mail invalide.';
        } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [$form['email']])) {
            $errors['email'] = 'Cet e-mail est déjà utilisé.';
        }
        if (!isset(USER_ROLES[$form['role']])) {
            $errors['role'] = 'Rôle invalide.';
        }
        if ($msg = auth_password_error($password)) {
            $errors['password'] = $msg;
        }

        if (!$errors) {
            db_query("INSERT INTO users (name, email, password, role, status, must_change_password, created_at) VALUES (?, ?, ?, ?, 'actif', 0, ?)",
                [$form['name'], $form['email'], auth_hash($password), $form['role'], db_now()]);
            activity_log('create', 'user', (int) db()->lastInsertId(), $form['name'], ['e-mail' => $form['email'], 'rôle' => role_label($form['role'])]);
            flash('success', "Compte de {$form['name']} créé.");
            redirect('users.php');
        }
    }

    // Déblocage immédiat d'un compte verrouillé après trop d'échecs de connexion
    if ($op === 'unlock') {
        $account = db_one('SELECT name, email FROM users WHERE id = ?', [$target]);
        if ($account) {
            auth_unlock($account['email']);
            activity_log('security', 'user', $target, $account['name'], ['action' => 'Compte débloqué']);
            flash('success', "Compte de {$account['name']} débloqué : il peut se reconnecter immédiatement.");
        }
        redirect('users.php');
    }

    // Garde-fous : on ne peut ni se désactiver, ni se rétrograder soi-même
    if (in_array($op, ['toggle', 'role', 'delete'], true) && $target === (int) $user['id']) {
        flash('error', $op === 'delete'
            ? 'Vous ne pouvez pas supprimer votre propre compte : vous perdriez l\'accès au Dashboard.'
            : 'Vous ne pouvez pas modifier votre propre statut ou rôle.');
        redirect('users.php');
    }

    /*
     * Suppression définitive d'un compte — SEUL endroit du Dashboard où un compte peut être supprimé
     * (page réservée aux administrateurs : require_role('admin') en tête de fichier ; jeton CSRF vérifié plus haut).
     * Refusée : son propre compte (ci-dessus), le dernier administrateur actif, et tout compte ayant émis des reçus
     * de caisse — l'auteur d'un reçu ne doit jamais disparaître (la base l'interdit : invoices.agent_id) ;
     * un tel compte se DÉSACTIVE (plus aucun accès, historique intact).
     */
    if ($op === 'delete') {
        $account = db_one('SELECT id, name, email, role, status, created_at, last_login_at FROM users WHERE id = ?', [$target]);
        if (!$account) {
            flash('error', 'Ce compte n\'existe plus (déjà supprimé ?).');
            redirect('users.php');
        }
        $receipts = (int) db_value('SELECT COUNT(*) FROM invoices WHERE agent_id = ?', [$target]);
        if ($receipts > 0) {
            flash('error', "Le compte de {$account['name']} a émis {$receipts} reçu" . ($receipts > 1 ? 's' : '') . ' de caisse : le supprimer effacerait '
                . "l'auteur de ces reçus. Désactivez-le plutôt : il ne pourra plus se connecter et l'historique reste intact.");
            redirect('users.php');
        }
        if ($account['role'] === 'admin'
            && (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'actif' AND id <> ?", [$target]) === 0) {
            flash('error', 'Impossible de supprimer le dernier administrateur actif.');
            redirect('users.php');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_query('DELETE FROM login_attempts WHERE email = ?', [auth_email_key($account['email'])]);
            db_query('DELETE FROM users WHERE id = ?', [$target]);   // sessions et « Se souvenir de moi » invalidés avec le compte
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[GTC] Suppression du compte ' . $target . ' : ' . $e->getMessage());
            flash('error', "Le compte de {$account['name']} est encore lié à des données : désactivez-le plutôt.");
            redirect('users.php');
        }
        activity_log('delete', 'user', $target, $account['name'], [
            'e-mail'           => $account['email'],
            'rôle'             => role_label($account['role']),
            'statut'           => $account['status'] === 'actif' ? 'Actif' : 'Inactif',
            'créé le'          => fdate($account['created_at'], true),
            'dernière connexion' => $account['last_login_at'] ? fdate($account['last_login_at'], true) : 'jamais',
        ]);
        flash('success', "Compte de {$account['name']} ({$account['email']}) supprimé.");
        redirect('users.php');
    }

    if ($op === 'toggle') {
        db_query("UPDATE users SET status = CASE WHEN status = 'actif' THEN 'inactif' ELSE 'actif' END WHERE id = ?", [$target]);
        $account = db_one('SELECT name, status FROM users WHERE id = ?', [$target]);
        if ($account) {
            activity_log('status', 'user', $target, $account['name'], ['statut' => $account['status'] === 'actif' ? 'Réactivé' : 'Désactivé']);
        }
        auth_revoke_sessions($target);   // sessions ouvertes fermées (elles ne reviennent pas à la réactivation)
        flash('success', 'Statut du compte mis à jour.');
        redirect('users.php');
    }

    if ($op === 'role' && isset(USER_ROLES[post('role')])) {
        $before = db_one('SELECT name, role FROM users WHERE id = ?', [$target]);
        db_query('UPDATE users SET role = ? WHERE id = ?', [post('role'), $target]);
        if ($before && $before['role'] !== post('role')) {
            activity_log('update', 'user', $target, $before['name'], ['rôle' => role_label($before['role']) . ' → ' . role_label(post('role'))]);
        }
        flash('success', 'Rôle mis à jour.');
        redirect('users.php');
    }

    if ($op === 'password') {
        $password = (string) ($_POST['password'] ?? '');
        if ($msg = auth_password_error($password)) {
            flash('error', $msg);
        } else {
            db_query('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?', [auth_hash($password), $target]);
            // Sessions ouvertes avec l'ancien mot de passe fermées ; l'administrateur qui change le sien reste connecté ici
            $version = auth_revoke_sessions($target);
            activity_log('security', 'user', $target, (string) db_value('SELECT name FROM users WHERE id = ?', [$target]), ['action' => 'Mot de passe redéfini par un administrateur']);
            if ($target === (int) $user['id']) {
                session_regenerate_id(true);
                $_SESSION['auth_version'] = $version;
            }
            flash('success', $target === (int) $user['id']
                ? 'Mot de passe modifié.'
                : "Nouveau mot de passe enregistré : communiquez-le à l'utilisateur, qui pourra le changer dans Paramètres → Mon profil.");
        }
        redirect('users.php');
    }
}

// Journal des prises de contrôle (10 dernières)
$takeovers = db_all("SELECT l.started_at, l.ended_at, l.ip, COALESCE(a.name, 'Compte supprimé') AS admin_name, COALESCE(t.name, 'Compte supprimé') AS target_name
                      FROM impersonation_log l
                      LEFT JOIN users a ON a.id = l.admin_id
                      LEFT JOIN users t ON t.id = l.target_id
                      ORDER BY l.id DESC LIMIT 10");
// Comptes verrouillés après trop d'échecs de connexion : [e-mail => fin du verrouillage]
$lockedEmails = auth_locked_emails();
$users = db_all("SELECT id, name, email, role, status, last_login_at, created_at FROM users ORDER BY CASE WHEN status = 'actif' THEN 0 ELSE 1 END, name");
foreach ($users as &$u) {
    $u['is_active'] = $u['status'] === 'actif';
}
unset($u);
$receiptsBy   = array_column(db_all('SELECT agent_id, COUNT(*) AS n FROM invoices GROUP BY agent_id'), 'n', 'agent_id');
$activeAdmins = (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'actif'");
$err   = fn (string $k) => isset($errors[$k]) ? '<small class="field__error">' . e($errors[$k]) . '</small>' : '';

dash_header('Utilisateurs', 'users');
?>

<div class="dash-grid dash-grid--aside">
    <section class="panel">
        <h2 class="panel__title">Comptes (<?= count($users) ?>)</h2>
        <div class="table-wrap">
            <table class="table table--stack users-table">
                <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Dernière connexion</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $self = (int) $u['id'] === (int) $user['id']; ?>
                    <tr class="<?= $u['is_active'] ? '' : 'is-disabled' ?>">
                        <td data-label="Utilisateur"><span class="cell-stack">
                            <strong><?= e($u['name']) ?></strong><?= $self ? ' <span class="badge badge--info">Vous</span>' : '' ?>
                            <small class="cell-sub"><?= e($u['email']) ?></small>
                        </span></td>
                        <td data-label="Rôle"><?= e(USER_ROLES[$u['role']] ?? $u['role']) ?></td>
                        <td data-label="Dernière connexion"><?= e(fdate($u['last_login_at'], true)) ?></td>
                        <td data-label="Statut">
                            <?= $u['is_active'] ? '<span class="badge badge--success">Actif</span>' : '<span class="badge badge--muted">Inactif</span>' ?>
                        </td>
                        <td class="actions">
                            <?php
                            $uid       = (int) $u['id'];
                            $nReceipts = (int) ($receiptsBy[$uid] ?? 0);
                            $lastAdmin = $u['role'] === 'admin' && $u['is_active'] && $activeAdmins <= 1;
                            // Modifier les droits : choix du rôle (formulaire op=role, jeton CSRF)
                            ob_start(); ?>
                                <form method="post" class="amenu__roles">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="role">
                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                    <?php foreach (USER_ROLES as $key => $label): ?>
                                        <label class="amenu__radio"><input type="radio" name="role" value="<?= e($key) ?>" <?= $key === $u['role'] ? 'checked' : '' ?>> <?= e($label) ?></label>
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn--primary btn--sm">Enregistrer le rôle</button>
                                </form>
                            <?php $rolesForm = ob_get_clean();
                            // Réinitialiser le mot de passe (formulaire op=password)
                            ob_start(); ?>
                                <form method="post" class="amenu__password">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="password">
                                    <input type="hidden" name="id" value="<?= $uid ?>">
                                    <input type="text" value="<?= e($u['email']) ?>" autocomplete="username" readonly hidden>
                                    <label for="pw-<?= $uid ?>">Nouveau mot de passe</label>
                                    <input id="pw-<?= $uid ?>" name="password" type="password" minlength="10" required autocomplete="new-password">
                                    <button type="submit" class="btn btn--primary btn--sm">Enregistrer</button>
                                </form>
                            <?php $passwordForm = ob_get_clean();

                            $items = $self
                                ? [['off', 'Modifier les droits', 'shield', 'Vous ne pouvez pas modifier votre propre rôle.'],
                                   ['sub', 'Changer mon mot de passe', 'lock', $passwordForm]]
                                : [['sub', 'Modifier les droits', 'shield', $rolesForm],
                                   ['sub', 'Réinitialiser le mot de passe', 'lock', $passwordForm]];
                            if (!$self && $u['role'] !== 'admin' && $u['is_active']) {
                                // Prise de contrôle du compte (dashboard/switch-user.php)
                                $items[] = ['post', 'Se connecter en tant que…', 'eye', ['action' => 'start', 'id' => $uid],
                                    "Vous allez utiliser le compte de {$u['name']}. Vos actions seront faites en son nom et notées dans le journal. Continuer ?",
                                    'action' => 'switch-user.php'];
                            }
                            if (!$self) {
                                $items[] = $u['is_active']
                                    ? ['post', 'Désactiver le compte', 'logout', ['op' => 'toggle', 'id' => $uid], "Désactiver le compte de {$u['name']} ? Il ne pourra plus se connecter ; son historique est conservé."]
                                    : ['post', 'Réactiver le compte', 'check', ['op' => 'toggle', 'id' => $uid], null];
                                $items[] = ['sep'];
                                $items[] = $nReceipts > 0 || $lastAdmin
                                    ? ['off', 'Supprimer le compte', 'trash', $lastAdmin ? 'Dernier administrateur actif.'
                                        : "A émis {$nReceipts} reçu" . ($nReceipts > 1 ? 's' : '') . ' de caisse : désactivez-le plutôt.']
                                    : delete_menu_item($uid, "Êtes-vous sûr de vouloir supprimer définitivement le compte utilisateur de {$u['name']} ({$u['email']}) ? Cette action est irréversible.", 'Supprimer le compte');
                            }
                            echo action_menu($items, 'Actions du compte de ' . $u['name']);
                            ?>
                        </td>
                    </tr>
                    <?php if ($lockUntil = $lockedEmails[mb_strtolower($u['email'])] ?? null): ?>
                        <!-- Compte verrouillé après trop d'échecs de connexion -->
                        <tr class="user-lock-row">
                            <td colspan="5">
                                <div class="user-lock">
                                    <span class="badge badge--danger"><?= icon('lock') ?> Bloqué (trop d'échecs)</span>
                                    <span class="user-lock__text">Connexion impossible pour <?= e($u['email']) ?> jusqu'à <?= e(date('H:i', $lockUntil)) ?> (<?= AUTH_MAX_ATTEMPTS ?> mots de passe erronés).</span>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="op" value="unlock">
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button type="submit" class="btn btn--ghost btn--sm" title="Effacer les tentatives échouées et autoriser la connexion immédiatement"><?= icon('unlock') ?> Débloquer le compte</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2 class="panel__title">Nouveau compte</h2>
        <form method="post" class="form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="create">
            <div class="field">
                <label for="u-name">Nom complet *</label>
                <input id="u-name" name="name" required maxlength="100" value="<?= e($form['name']) ?>">
                <?= $err('name') ?>
            </div>
            <div class="field">
                <label for="u-email">E-mail *</label>
                <input id="u-email" name="email" type="email" required maxlength="100" autocomplete="off" value="<?= e($form['email']) ?>">
                <?= $err('email') ?>
            </div>
            <div class="field">
                <label for="u-role">Rôle</label>
                <select id="u-role" name="role">
                    <?php foreach (USER_ROLES as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $key === $form['role'] ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('role') ?>
            </div>
            <div class="field">
                <label for="u-password">Mot de passe *</label>
                <input id="u-password" name="password" type="password" required minlength="10" autocomplete="new-password">
                <small class="field__hint">10 caractères minimum, avec au moins une lettre et un chiffre. L'utilisateur pourra le changer quand il le souhaite dans Paramètres → Mon profil.</small>
                <?= $err('password') ?>
            </div>
            <button type="submit" class="btn btn--primary btn--block"><?= icon('plus') ?> Créer le compte</button>
        </form>
    </section>
</div>

<!-- ==================== JOURNAL DES PRISES DE CONTRÔLE ==================== -->
<section class="panel">
    <header class="panel__head">
        <h2 class="panel__title">Prises de contrôle récentes</h2>
        <span class="cell-sub">Utilisation d'un compte par un administrateur (bouton <?= icon('eye') ?>)</span>
    </header>
    <?php if (!$takeovers): ?>
        <p class="empty">Aucune prise de contrôle enregistrée.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--stack">
                <thead><tr><th>Administrateur</th><th>Compte utilisé</th><th>Début</th><th>Fin</th><th>Adresse IP</th></tr></thead>
                <tbody>
                <?php foreach ($takeovers as $t): ?>
                    <tr>
                        <td data-label="Administrateur"><?= e($t['admin_name'] ?? 'Compte supprimé') ?></td>
                        <td data-label="Compte utilisé"><strong><?= e($t['target_name'] ?? 'Compte supprimé') ?></strong></td>
                        <td data-label="Début"><?= e(fdate($t['started_at'], true)) ?></td>
                        <td data-label="Fin"><?= $t['ended_at'] ? e(fdate($t['ended_at'], true)) : '<span class="badge badge--warning">En cours</span>' ?></td>
                        <td data-label="Adresse IP"><code><?= e($t['ip'] ?: '—') ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php dash_footer(); ?>
