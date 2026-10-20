<?php
/**
 * Partenaires affichés sur la page d'accueil (section « Nos Partenaires ») — réservé aux administrateurs.
 * Ajout, modification, ordre d'affichage, visibilité et logo officiel (PNG / JPG, voir includes/partners.php).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/partners.php';

$user = require_login();
require_role('admin');

$errors = [];
$form   = ['id' => 0, 'name' => '', 'website' => '', 'display_order' => '', 'is_active' => 1, 'logo' => null];

if (is_post()) {
    csrf_verify();
    $op = post('op');

    if ($op === 'save') {
        $form = [
            'id'            => (int) post('id'),
            'name'          => post('name'),
            'website'       => post('website'),
            'display_order' => post('display_order'),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
            'logo'          => null,
        ];
        $existing = $form['id'] ? db_one('SELECT * FROM partners WHERE id = ?', [$form['id']]) : null;
        if ($form['id'] && !$existing) {
            flash('error', 'Partenaire introuvable.');
            redirect('partners.php');
        }
        $form['logo'] = $existing['logo'] ?? null;

        if (mb_strlen($form['name']) < 2 || mb_strlen($form['name']) > 100) {
            $errors['name'] = 'Indiquez le nom du partenaire (2 à 100 caractères).';
        }
        $website = partner_clean_url($form['website']);
        if ($website === null) {
            $errors['website'] = 'Adresse de site invalide (ex. https://www.dhl.com).';
        }
        if ($form['display_order'] === '') {
            $form['display_order'] = (string) ((int) db_value('SELECT COALESCE(MAX(display_order), 0) FROM partners') + 1);
        } elseif (!ctype_digit($form['display_order']) || (int) $form['display_order'] > 999) {
            $errors['display_order'] = "L'ordre doit être un nombre entre 0 et 999.";
        }

        // Logo : envoyé seulement si le reste du formulaire est valide (pas de fichier orphelin)
        $newLogo = null;
        if (!$errors) {
            [$newLogo, $uploadError] = partner_store_logo($_FILES['logo'] ?? []);
            if ($uploadError) {
                $errors['logo'] = $uploadError;
            }
        }

        if (!$errors) {
            $logo = $form['logo'];
            if ($newLogo) {
                partner_delete_logo($logo);
                $logo = $newLogo;
            } elseif (isset($_POST['remove_logo'])) {
                partner_delete_logo($logo);
                $logo = null;
            }
            $values = [$form['name'], $logo, $website ?: null, (int) $form['display_order'], $form['is_active']];
            if ($existing) {
                db_query('UPDATE partners SET name = ?, logo = ?, website = ?, display_order = ?, is_active = ? WHERE id = ?', [...$values, $form['id']]);
                flash('success', "Partenaire « {$form['name']} » mis à jour.");
            } else {
                db_query('INSERT INTO partners (name, logo, website, display_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?)', [...$values, db_now()]);
                flash('success', "Partenaire « {$form['name']} » ajouté.");
            }
            redirect('partners.php');
        }
    }

    if ($op === 'delete') {
        $partner = db_one('SELECT * FROM partners WHERE id = ?', [(int) post('id')]);
        if ($partner) {
            partner_delete_logo($partner['logo']);
            db_query('DELETE FROM partners WHERE id = ?', [$partner['id']]);
            flash('success', "Partenaire « {$partner['name']} » supprimé.");
        }
        redirect('partners.php');
    }
}

// Mode édition (GET ?edit=ID)
if (!is_post() && query('edit')) {
    $row = db_one('SELECT * FROM partners WHERE id = ?', [(int) query('edit')]);
    if ($row) {
        $form = $row;
        $form['website'] = (string) $row['website'];
    }
}

$partners = db_all('SELECT * FROM partners ORDER BY display_order ASC, id ASC');
$err      = fn (string $k) => isset($errors[$k]) ? '<small class="field__error">' . e($errors[$k]) . '</small>' : '';
$inv      = fn (string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$editing  = (int) $form['id'] > 0;
$currentLogo = $editing ? partner_logo_url($form['logo'], '../') : null;

dash_header('Partenaires', 'partners');
?>

<div class="toolbar">
    <p class="page-intro">Ces partenaires sont affichés dans la section « Nos Partenaires » de la page d'accueil.</p>
    <a href="../index.php#partenaires" class="btn btn--ghost btn--sm" target="_blank" rel="noopener"><?= icon('external') ?> Voir sur le site</a>
</div>

<div class="dash-grid dash-grid--aside">
    <!-- Liste -->
    <section class="panel">
        <h2 class="panel__title">Partenaires (<?= count($partners) ?>)</h2>
        <?php if (!$partners): ?>
            <p class="empty">Aucun partenaire : la section est masquée sur le site tant que la liste est vide.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead><tr><th>Partenaire</th><th>Site web</th><th class="num">Ordre</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($partners as $p): ?>
                        <?php $url = partner_logo_url($p['logo'], '../', $p['name']); ?>
                        <tr class="<?= trim(((int) $p['id'] === (int) $form['id'] ? 'is-selected ' : '') . ($p['is_active'] ? '' : 'is-disabled')) ?>">
                            <td data-label="Partenaire">
                                <span class="partner-cell">
                                    <span class="partner-thumb">
                                        <?php if ($url): ?>
                                            <img src="<?= e($url) ?>" alt="" height="32">
                                        <?php else: ?>
                                            <span class="partner-thumb__text" aria-hidden="true"><?= e(initials($p['name'])) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span>
                                        <strong><?= e($p['name']) ?></strong>
                                        <small class="cell-sub"><?= $url ? 'Logo officiel' : 'Sans logo : nom affiché' ?><?= $p['is_active'] ? '' : ' · masqué' ?></small>
                                    </span>
                                </span>
                            </td>
                            <td data-label="Site web"><?= $p['website'] ? '<a href="' . e($p['website']) . '" target="_blank" rel="noopener">' . e(preg_replace('#^https?://(www\.)?#', '', $p['website'])) . '</a>' : '<small class="cell-sub">—</small>' ?></td>
                            <td data-label="Ordre" class="num"><?= (int) $p['display_order'] ?></td>
                            <td class="actions">
                                <a href="partners.php?edit=<?= (int) $p['id'] ?>" class="icon-btn" title="Modifier" aria-label="Modifier <?= e($p['name']) ?>"><?= icon('edit') ?></a>
                                <form method="post" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Supprimer" aria-label="Supprimer <?= e($p['name']) ?>"
                                            data-confirm="Supprimer le partenaire « <?= e($p['name']) ?> » ? Son logo sera supprimé."><?= icon('trash') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="help-text"><?= icon('alert') ?> Utilisez uniquement les logos officiels fournis par vos partenaires, avec leur accord. Format conseillé : PNG à fond transparent, au moins 300 px de large.</p>
    </section>

    <!-- Formulaire -->
    <section class="panel">
        <h2 class="panel__title"><?= $editing ? 'Modifier le partenaire' : 'Ajouter un partenaire' ?></h2>

        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>Merci de corriger les champs indiqués.</p></div>
        <?php endif; ?>

        <form method="post" action="partners.php<?= $editing ? '?edit=' . (int) $form['id'] : '' ?>" enctype="multipart/form-data" class="form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="save">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= PARTNER_MAX_BYTES ?>">

            <div class="field">
                <label for="p-name">Nom du partenaire *</label>
                <input id="p-name" name="name" required maxlength="100" value="<?= e($form['name']) ?>"<?= $inv('name') ?>>
                <small class="field__hint">Affiché à la place du logo tant qu'aucun logo n'est envoyé, et utilisé comme texte alternatif.</small>
                <?= $err('name') ?>
            </div>
            <div class="field">
                <label for="p-website">Site web <small class="field__hint">(facultatif)</small></label>
                <input id="p-website" name="website" maxlength="255" placeholder="https://www.exemple.com" value="<?= e((string) $form['website']) ?>"<?= $inv('website') ?>>
                <?= $err('website') ?>
            </div>
            <div class="field">
                <label for="p-order">Ordre d'affichage</label>
                <input id="p-order" name="display_order" type="number" min="0" max="999" placeholder="Automatique" value="<?= e((string) $form['display_order']) ?>"<?= $inv('display_order') ?>>
                <small class="field__hint">Le plus petit nombre apparaît en premier.</small>
                <?= $err('display_order') ?>
            </div>
            <div class="field">
                <label for="p-logo">Logo officiel (PNG ou JPG, 2 Mo max)</label>
                <?php if ($currentLogo): ?>
                    <div class="logo-current">
                        <img src="<?= e($currentLogo) ?>" alt="Logo actuel" height="48">
                        <label class="check"><input type="checkbox" name="remove_logo"> Retirer le logo</label>
                    </div>
                <?php endif; ?>
                <input id="p-logo" name="logo" type="file" accept="image/png,image/jpeg"<?= $inv('logo') ?>>
                <?= $err('logo') ?>
            </div>
            <label class="check"><input type="checkbox" name="is_active" <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>> Afficher sur le site</label>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> <?= $editing ? 'Enregistrer' : 'Ajouter' ?></button>
                <?php if ($editing): ?><a href="partners.php" class="btn btn--ghost">Annuler</a><?php endif; ?>
            </div>
        </form>
    </section>
</div>

<?php dash_footer(); ?>
