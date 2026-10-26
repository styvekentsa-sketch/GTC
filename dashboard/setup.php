<?php
/**
 * Installation initiale — création du premier compte administrateur.
 * Cette page se désactive automatiquement dès qu'un compte existe.
 */
require_once __DIR__ . '/../includes/dashboard.php';

auth_session_start();
auth_send_headers();

if ((int) db_value('SELECT COUNT(*) FROM users') > 0) {
    redirect('login.php');
}

$errors = [];
$name = $email = '';

if (is_post()) {
    csrf_verify();

    $name     = post('name');
    $email    = mb_strtolower(post('email'));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors[] = 'Veuillez indiquer votre nom complet.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse e-mail invalide.';
    }
    if ($msg = auth_password_error($password)) {
        $errors[] = $msg;
    }
    if (!hash_equals($password, $confirm)) {
        $errors[] = 'Les deux mots de passe ne correspondent pas.';
    }

    if (!$errors) {
        db_query(
            "INSERT INTO users (name, email, password, role, status, created_at) VALUES (?, ?, ?, ?, 'actif', ?)",
            [$name, $email, auth_hash($password), 'admin', db_now()]
        );
        activity_log('create', 'user', (int) db()->lastInsertId(), $name, ['action' => 'Premier administrateur (installation)'],
            ['id' => null, 'name' => $name, 'role' => 'admin']);
        flash('success', 'Compte administrateur créé. Vous pouvez maintenant vous connecter.');
        redirect('login.php');
    }
}

auth_layout_open('Installation', 'Créez le compte administrateur principal du Dashboard.');
?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert">
        <?= icon('alert') ?>
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <div class="field">
        <label for="name">Nom complet</label>
        <input id="name" name="name" type="text" required maxlength="100" autocomplete="name" value="<?= e($name) ?>">
    </div>
    <div class="field">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required maxlength="100" autocomplete="email" value="<?= e($email) ?>">
    </div>
    <div class="field">
        <label for="password">Mot de passe</label>
        <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
        <small class="field__hint">10 caractères minimum, avec au moins une lettre et un chiffre.</small>
    </div>
    <div class="field">
        <label for="password_confirm">Confirmer le mot de passe</label>
        <input id="password_confirm" name="password_confirm" type="password" required minlength="10" autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn--primary btn--block"><?= icon('lock') ?> Créer le compte</button>
</form>

<?php auth_layout_close(); ?>
