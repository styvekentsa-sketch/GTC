<?php
/**
 * Gestion de l'équipe affichée sur la page « À propos ».
 * Accès : administrateurs. Photos : assets/img/team/ (JPG / PNG, 2 Mo max).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/team.php';

$user = require_login();
require_role('admin'); // gestion de l'équipe publique : administrateurs uniquement

const TEAM_MAX_BYTES  = 2 * 1024 * 1024;  // 2 Mo
const TEAM_MAX_PIXELS = 6000;              // côté maximal accepté à l'envoi
const TEAM_PHOTO_SIZE = 480;               // photo finale carrée (si GD est disponible)
const TEAM_ALLOWED    = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

/**
 * Valide et enregistre la photo envoyée.
 * Retourne [nom_de_fichier|null, message_d_erreur|null] ; [null, null] si aucun fichier.
 *
 * Contrôles : erreur d'envoi, taille, fichier réellement téléversé, type MIME réel
 * (finfo, pas l'extension fournie), structure d'image valide (getimagesize), dimensions.
 * Si GD est disponible, l'image est réencodée (recadrage carré + redimensionnement) :
 * tout contenu caché dans le fichier d'origine est ainsi éliminé.
 */
function team_store_upload(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > TEAM_MAX_BYTES) {
        return [null, 'La photo dépasse la taille maximale de 2 Mo.'];
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return [null, "L'envoi de la photo a échoué. Merci de réessayer."];
    }

    $tmp  = $file['tmp_name'];
    $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : null;
    $info = @getimagesize($tmp);

    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
        || ($mime !== null && !isset(TEAM_ALLOWED[$mime]))) {
        return [null, 'Format non accepté : seules les images JPG et PNG sont autorisées.'];
    }
    if ($info[0] < 64 || $info[1] < 64 || $info[0] > TEAM_MAX_PIXELS || $info[1] > TEAM_MAX_PIXELS) {
        return [null, 'Dimensions de la photo invalides (entre 64 et 6000 pixels).'];
    }

    if (!is_dir(TEAM_PHOTO_DIR) && !mkdir(TEAM_PHOTO_DIR, 0755, true)) {
        return [null, "Le dossier des photos n'est pas accessible en écriture."];
    }

    $ext  = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $name = 'membre-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = TEAM_PHOTO_DIR . '/' . $name;

    if (function_exists('imagecreatetruecolor')) {
        // Réencodage : recadrage carré centré + redimensionnement
        $srcImg = $ext === 'png' ? @imagecreatefrompng($tmp) : @imagecreatefromjpeg($tmp);
        if (!$srcImg) {
            return [null, 'Image illisible ou corrompue.'];
        }
        $side = min($info[0], $info[1]);
        $sx   = (int) (($info[0] - $side) / 2);
        $sy   = (int) (($info[1] - $side) / 2);
        $size = min(TEAM_PHOTO_SIZE, $side);

        $out = imagecreatetruecolor($size, $size);
        if ($ext === 'png') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $srcImg, 0, 0, $sx, $sy, $size, $size, $side, $side);
        $saved = $ext === 'png' ? imagepng($out, $dest, 6) : imagejpeg($out, $dest, 85);
        if (!$saved) {
            return [null, "La photo n'a pas pu être enregistrée."];
        }
    } elseif (!move_uploaded_file($tmp, $dest)) {
        return [null, "La photo n'a pas pu être enregistrée."];
    }

    @chmod($dest, 0644);
    return [$name, null];
}

/* ==========================================================================
   Actions POST
   ========================================================================== */

$errors = [];
$form   = ['id' => 0, 'name' => '', 'role' => '', 'display_order' => '', 'photo' => null];

if (is_post()) {
    csrf_verify();
    $op = post('op');

    if ($op === 'save') {
        $form = [
            'id'            => (int) post('id'),
            'name'          => post('name'),
            'role'          => post('role'),
            'display_order' => post('display_order'),
            'photo'         => null,
        ];
        $existing = $form['id'] ? db_one('SELECT * FROM team_members WHERE id = ?', [$form['id']]) : null;
        if ($form['id'] && !$existing) {
            flash('error', 'Membre introuvable.');
            redirect('team.php');
        }
        $form['photo'] = $existing['photo'] ?? null;

        if (mb_strlen($form['name']) < 2 || mb_strlen($form['name']) > 100) {
            $errors['name'] = 'Indiquez le nom (2 à 100 caractères).';
        }
        if (mb_strlen($form['role']) < 2 || mb_strlen($form['role']) > 100) {
            $errors['role'] = 'Indiquez le rôle (2 à 100 caractères).';
        }
        if ($form['display_order'] === '') {
            $form['display_order'] = (string) ((int) db_value('SELECT COALESCE(MAX(display_order), 0) FROM team_members') + 1);
        } elseif (!ctype_digit($form['display_order']) || (int) $form['display_order'] > 999) {
            $errors['display_order'] = "L'ordre doit être un nombre entre 0 et 999.";
        }

        // Photo : envoi uniquement si le reste du formulaire est valide (pas de fichier orphelin)
        $newPhoto = null;
        if (!$errors) {
            [$newPhoto, $uploadError] = team_store_upload($_FILES['photo'] ?? []);
            if ($uploadError) {
                $errors['photo'] = $uploadError;
            }
        }

        if (!$errors) {
            $photo = $form['photo'];
            if ($newPhoto) {
                team_delete_photo($photo);           // remplacement : l'ancienne photo est supprimée
                $photo = $newPhoto;
            } elseif (isset($_POST['remove_photo'])) {
                team_delete_photo($photo);
                $photo = 'default-avatar.png';
            }

            if ($existing) {
                db_query('UPDATE team_members SET name = ?, role = ?, photo = ?, display_order = ? WHERE id = ?',
                    [$form['name'], $form['role'], $photo, (int) $form['display_order'], $form['id']]);
                flash('success', "Fiche de {$form['name']} mise à jour.");
            } else {
                db_query('INSERT INTO team_members (name, role, photo, display_order, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$form['name'], $form['role'], $photo ?? 'default-avatar.png', (int) $form['display_order'], db_now()]);
                flash('success', "{$form['name']} a été ajouté(e) à l'équipe.");
            }
            redirect('team.php');
        }
    }

    if ($op === 'delete') {
        $member = db_one('SELECT * FROM team_members WHERE id = ?', [(int) post('id')]);
        if ($member) {
            team_delete_photo($member['photo']);
            db_query('DELETE FROM team_members WHERE id = ?', [$member['id']]);
            flash('success', "{$member['name']} a été retiré(e) de l'équipe.");
        }
        redirect('team.php');
    }
}

// Mode édition (GET ?edit=ID)
if (!is_post() && query('edit')) {
    $form = db_one('SELECT id, name, role, display_order, photo FROM team_members WHERE id = ?', [(int) query('edit')]) ?: $form;
}

$members = db_all('SELECT * FROM team_members ORDER BY display_order ASC, id ASC');
$err     = fn (string $k) => isset($errors[$k]) ? '<small class="field__error">' . e($errors[$k]) . '</small>' : '';
$editing = (int) $form['id'] > 0;
$currentPhoto = $editing ? team_photo_url($form['photo'], '../') : null;

dash_header("Équipe", 'team');
?>

<div class="toolbar">
    <p class="page-intro">Ces fiches sont affichées dans la section « Notre équipe » de la page À propos.</p>
    <a href="../a-propos.php#equipe" class="btn btn--ghost btn--sm" target="_blank" rel="noopener"><?= icon('external') ?> Voir sur le site</a>
</div>

<div class="dash-grid dash-grid--aside">
    <!-- Liste -->
    <section class="panel">
        <h2 class="panel__title">Membres (<?= count($members) ?>)</h2>
        <?php if (!$members): ?>
            <p class="empty">Aucun membre. La section équipe est masquée sur le site tant que la liste est vide.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead><tr><th>Membre</th><th>Rôle</th><th class="num">Ordre</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <?php $url = team_photo_url($m['photo'], '../', $m['name']); ?>
                        <tr<?= (int) $m['id'] === (int) $form['id'] ? ' class="is-selected"' : '' ?>>
                            <td data-label="Membre">
                                <span class="member-cell">
                                    <?php if ($url): ?>
                                        <img class="member-thumb" src="<?= e($url) ?>" alt="" width="44" height="44">
                                    <?php else: ?>
                                        <span class="member-thumb member-thumb--initials" aria-hidden="true"><?= e(initials($m['name'])) ?></span>
                                    <?php endif; ?>
                                    <strong><?= e($m['name']) ?></strong>
                                </span>
                            </td>
                            <td data-label="Rôle"><?= e($m['role']) ?></td>
                            <td class="num" data-label="Ordre"><?= (int) $m['display_order'] ?></td>
                            <td class="actions">
                                <a href="team.php?edit=<?= (int) $m['id'] ?>" class="icon-btn" title="Modifier"><?= icon('edit') ?></a>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Supprimer"
                                            data-confirm="Retirer <?= e($m['name']) ?> de l'équipe ? Sa photo sera supprimée.">
                                        <?= icon('trash') ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Formulaire -->
    <section class="panel">
        <h2 class="panel__title"><?= $editing ? 'Modifier le membre' : 'Ajouter un membre' ?></h2>

        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>Merci de corriger les champs indiqués.</p></div>
        <?php endif; ?>

        <form method="post" action="team.php" enctype="multipart/form-data" class="form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="save">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= TEAM_MAX_BYTES ?>">

            <div class="field">
                <label for="t-name">Nom complet *</label>
                <input id="t-name" name="name" required maxlength="100" value="<?= e($form['name']) ?>"<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
                <?= $err('name') ?>
            </div>
            <div class="field">
                <label for="t-role">Rôle *</label>
                <input id="t-role" name="role" required maxlength="100" placeholder="Ex. Responsable Opérationnel" value="<?= e($form['role']) ?>"<?= isset($errors['role']) ? ' aria-invalid="true"' : '' ?>>
                <?= $err('role') ?>
            </div>
            <div class="field">
                <label for="t-order">Ordre d'affichage</label>
                <input id="t-order" name="display_order" type="number" min="0" max="999" placeholder="Automatique" value="<?= e((string) $form['display_order']) ?>"<?= isset($errors['display_order']) ? ' aria-invalid="true"' : '' ?>>
                <small class="field__hint">Le plus petit nombre apparaît en premier.</small>
                <?= $err('display_order') ?>
            </div>

            <div class="field">
                <label for="t-photo">Photo (JPG ou PNG, 2 Mo max)</label>
                <?php if ($currentPhoto): ?>
                    <div class="photo-current">
                        <img src="<?= e($currentPhoto) ?>" alt="Photo actuelle" width="64" height="64">
                        <label class="check"><input type="checkbox" name="remove_photo"> Retirer la photo actuelle</label>
                    </div>
                <?php endif; ?>
                <input id="t-photo" name="photo" type="file" accept="image/jpeg,image/png"<?= isset($errors['photo']) ? ' aria-invalid="true"' : '' ?>>
                <small class="field__hint"><?= $editing ? 'Choisissez un fichier pour remplacer la photo.' : 'Facultatif : sans photo, les initiales sont affichées.' ?> Format carré conseillé (au moins 400 × 400 px) : la photo est affichée en médaillon.</small>
                <?= $err('photo') ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> <?= $editing ? 'Enregistrer' : 'Ajouter' ?></button>
                <?php if ($editing): ?><a href="team.php" class="btn btn--ghost">Annuler</a><?php endif; ?>
            </div>
        </form>
    </section>
</div>

<?php dash_footer(); ?>
