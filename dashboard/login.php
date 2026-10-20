<?php
/**
 * Connexion administrateur / staff.
 */
require_once __DIR__ . '/../includes/dashboard.php';

auth_session_start();
auth_send_headers();

// Première utilisation : aucun compte
if ((int) db_value('SELECT COUNT(*) FROM users') === 0) {
    redirect('setup.php');
}

// Déjà connecté
if (current_user()) {
    redirect('index.php');
}

/** Destination après connexion : uniquement une page du dashboard (anti-redirection ouverte). */
$next = query('next', 'index.php');
if (!preg_match('/^[a-z0-9-]+\.php$/', $next) || in_array($next, ['login.php', 'logout.php', 'setup.php', 'password.php'], true)) {
    $next = 'index.php';
}

$error = null;
$email = '';

if (is_post()) {
    csrf_verify();
    $email = post('email');

    if ($until = auth_locked_until($email)) {
        // Seule cette adresse est verrouillée : les autres comptes peuvent se connecter normalement
        $minutes = max(1, (int) ceil(($until - time()) / 60));
        $error = 'Trop de tentatives échouées pour cette adresse e-mail. Réessayez dans ' . $minutes
               . ' minute' . ($minutes > 1 ? 's' : '') . ', ou demandez à un administrateur de débloquer le compte.';
    } elseif (auth_attempt($email, (string) ($_POST['password'] ?? ''))) {
        redirect($next);
    } elseif ($until = auth_locked_until($email)) {
        $error = 'Identifiants incorrects. Trop de tentatives pour cette adresse e-mail : elle est verrouillée pendant '
               . AUTH_LOCK_MINUTES . ' minutes (un administrateur peut la débloquer).';
    } else {
        $error = 'Identifiants incorrects.';
    }
}

auth_layout_open('Connexion', 'Espace de gestion réservé au personnel de Global Trading Corporation.');
?>

<?php if ($error): ?>
    <div class="alert alert--error" role="alert"><?= icon('alert') ?><p><?= e($error) ?></p></div>
<?php endif; ?>

<form method="post" action="login.php?next=<?= e(rawurlencode($next)) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <div class="field">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required autocomplete="username" autofocus value="<?= e($email) ?>">
    </div>
    <div class="field">
        <label for="password">Mot de passe</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn--primary btn--block"><?= icon('lock') ?> Se connecter</button>
</form>

<?php auth_layout_close(); ?>
