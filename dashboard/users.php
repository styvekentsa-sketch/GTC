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
            flash('success', "Compte de {$form['name']} créé.");
            redirect('users.php');
        }
    }

    // Déblocage immédiat d'un compte verrouillé après trop d'échecs de connexion
    if ($op === 'unlock') {
        $account = db_one('SELECT name, email FROM users WHERE id = ?', [$target]);
        if ($account) {
            auth_unlock($account['email']);
            flash('success', "Compte de {$account['name']} débloqué : il peut se reconnecter immédiatement.");
        }
        redirect('users.php');
    }

    // Garde-fous : on ne peut ni se désactiver, ni se rétrograder soi-même
    if (in_array($op, ['toggle', 'role'], true) && $target === (int) $user['id']) {
        flash('error', 'Vous ne pouvez pas modifier votre propre statut ou rôle.');
        redirect('users.php');
    }

    if ($op === 'toggle') {
        db_query("UPDATE users SET status = CASE WHEN status = 'actif' THEN 'inactif' ELSE 'actif' END WHERE id = ?", [$target]);
        auth_revoke_sessions($target);   // sessions ouvertes fermées (elles ne reviennent pas à la réactivation)
        flash('success', 'Statut du compte mis à jour.');
        redirect('users.php');
    }

    if ($op === 'role' && isset(USER_ROLES[post('role')])) {
        db_query('UPDATE users SET role = ? WHERE id = ?', [post('role'), $target]);
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
$takeovers = db_all('SELECT l.started_at, l.ended_at, l.ip, a.name AS admin_name, t.name AS target_name
                      FROM impersonation_log l
                      LEFT JOIN users a ON a.id = l.admin_id
                      LEFT JOIN users t ON t.id = l.target_id
                      ORDER BY l.id DESC LIMIT 10');
// Comptes verrouillés après trop d'échecs de connexion : [e-mail => fin du verrouillage]
$lockedEmails = auth_locked_emails();
$users = db_all("SELECT id, name, email, role, status, last_login_at, created_at FROM users ORDER BY CASE WHEN status = 'actif' THEN 0 ELSE 1 END, name");
foreach ($users as &$u) {
    $u['is_active'] = $u['status'] === 'actif';
}
unset($u);
$err   = fn (string $k) => isset($errors[$k]) ? '<small class="field__error">' . e($errors[$k]) . '</small>' : '';

dash_header('Utilisateurs', 'users');
?>

<div class="dash-grid dash-grid--aside">
    <section class="panel">
        <h2 class="panel__title">Comptes (<?= count($users) ?>)</h2>
        <div class="table-wrap">
            <table class="table users-table">
                <thead><tr><th>Utilisateur</th><th>Rôle</th><th>Dernière connexion</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $self = (int) $u['id'] === (int) $user['id']; ?>
                    <tr class="<?= $u['is_active'] ? '' : 'is-disabled' ?>">
                        <td>
                            <strong><?= e($u['name']) ?></strong><?= $self ? ' <span class="badge badge--info">Vous</span>' : '' ?>
                            <small class="cell-sub"><?= e($u['email']) ?></small>
                        </td>
                        <td>
                            <?php if ($self): ?>
                                <?= e(USER_ROLES[$u['role']] ?? $u['role']) ?>
                            <?php else: ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="role">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <label class="visually-hidden" for="role-<?= (int) $u['id'] ?>">Rôle</label>
                                    <select id="role-<?= (int) $u['id'] ?>" name="role" class="select-sm" data-autosubmit>
                                        <?php foreach (USER_ROLES as $key => $label): ?>
                                            <option value="<?= e($key) ?>" <?= $key === $u['role'] ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?= e(fdate($u['last_login_at'], true)) ?></td>
                        <td>
                            <?= $u['is_active'] ? '<span class="badge badge--success">Actif</span>' : '<span class="badge badge--muted">Inactif</span>' ?>
                        </td>
                        <td class="actions">
                            <?php if (!$self && $u['role'] !== 'admin' && $u['is_active']): ?>
                                <!-- Prise de contrôle du compte (dashboard/switch-user.php) -->
                                <form method="post" action="switch-user.php" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="start">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--impersonate"
                                            title="Incognito : se connecter en tant que <?= e($u['name']) ?>"
                                            aria-label="Se connecter en tant que <?= e($u['name']) ?>"
                                            data-confirm="Vous allez utiliser le compte de <?= e($u['name']) ?>. Vos actions seront faites en son nom et notées dans le journal. Continuer ?"><?= icon('eye') ?></button>
                                </form>
                            <?php endif; ?>
                            <details class="popover">
                                <summary class="icon-btn" title="Réinitialiser le mot de passe"><?= icon('lock') ?></summary>
                                <form method="post" class="popover__body">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="password">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <input type="text" value="<?= e($u['email']) ?>" autocomplete="username" readonly hidden>
                                    <label for="pw-<?= (int) $u['id'] ?>">Nouveau mot de passe</label>
                                    <input id="pw-<?= (int) $u['id'] ?>" name="password" type="password" minlength="10" required autocomplete="new-password">
                                    <button type="submit" class="btn btn--primary btn--sm">Enregistrer</button>
                                </form>
                            </details>
                            <?php if (!$self): ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="icon-btn<?= $u['is_active'] ? ' icon-btn--danger' : '' ?>"
                                            title="<?= $u['is_active'] ? 'Désactiver' : 'Réactiver' ?>"
                                            <?= $u['is_active'] ? 'data-confirm="Désactiver le compte de ' . e($u['name']) . ' ?"' : '' ?>>
                                        <?= icon($u['is_active'] ? 'logout' : 'check') ?>
                                    </button>
                                </form>
                            <?php endif; ?>
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
