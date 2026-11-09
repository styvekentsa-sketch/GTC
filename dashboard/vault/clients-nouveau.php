<?php
/**
 * GTC-Vault — Nouveau dossier KYC (client + pièces justificatives)
 *
 * Tables : clients, client_documents (db_install_kyc, includes/db.php). Pièces : data/kyc/ (hors web).
 * Sécurité :
 *   - connexion du Dashboard + rôle VAULT_ROLES (vault_guard, includes/vault.php) ;
 *   - jeton CSRF de la session du Dashboard, vérifié en temps constant ;
 *   - toutes les règles sont revérifiées côté serveur (le contrôle du navigateur n'est qu'un confort) ;
 *   - type réel des fichiers lu dans leur contenu (finfo), jamais dans le nom ni dans l'en-tête envoyé ;
 *   - fichiers rangés hors de la racine web, sous un nom aléatoire, puis enregistrement en une transaction
 *     (en cas d'échec, les fichiers déjà copiés sont effacés).
 *
 * Réglages PHP conseillés : upload_max_filesize = 6M, post_max_size = 24M (4 pièces de 5 Mo au plus).
 */
declare(strict_types=1);

// Configuration, base, session et accès : includes/vault.php (connexion du Dashboard)
require_once __DIR__ . '/../../includes/vault.php';

$user   = vault_guard();
$userId = (int) $user['id'];
$csrf   = vault_csrf();

// ---------- Pièces attendues ----------
// slot => [libellé, type_document enregistré]
$slots = [
    'doc_identite' => 'Pièce d\'identité',
    'doc_verso'    => 'CNI — verso',
    'doc_rccm'     => 'Registre du commerce (RCCM)',
    'doc_statuts'  => 'Statuts de l\'entreprise',
];

/** Pièces obligatoires selon le type de client et la pièce d'identité choisie. */
function kyc_required(string $type, string $piece): array
{
    if ($type === 'entreprise') {
        return ['doc_rccm', 'doc_identite'];                 // + pièce du représentant légal
    }
    return $piece === 'cni' ? ['doc_identite', 'doc_verso'] : ['doc_identite'];
}

/** Contrôle d'un fichier envoyé : [extension, mime] ou message d'erreur. */
function kyc_check_file(array $f): array|string
{
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > KYC_MAX_BYTES) {
        return 'Fichier trop lourd (5 Mo au maximum).';
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return 'Le fichier n\'a pas pu être reçu, réessayez.';
    }
    if ($f['size'] === 0) {
        return 'Le fichier est vide.';
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!isset(KYC_MIME_TYPES[$mime])) {
        return 'Format refusé : seuls JPG, PNG et PDF sont acceptés.';
    }
    return [KYC_MIME_TYPES[$mime], $mime];
}

// ---------- Traitement ----------
$errors = [];
$old    = ['type_client' => 'particulier', 'piece_identite' => 'cni', 'indicatif' => '+237'];
$flash  = $_SESSION['kyc_flash'] ?? null;
unset($_SESSION['kyc_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Envoi plus lourd que post_max_size : PHP vide $_POST et $_FILES
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['_form'] = 'L\'envoi est trop volumineux. Chaque fichier doit faire 5 Mo au maximum.';
    }
    // Saisie relue dans tous les cas : réaffichée si l'envoi est refusé (session expirée comprise)
    $in = [
        'type_client'        => (string) ($_POST['type_client'] ?? ''),
        'piece_identite'     => (string) ($_POST['piece_identite'] ?? 'cni'),
        'nom_complet'        => trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['nom_complet'] ?? ''))),
        'indicatif'          => (string) ($_POST['indicatif'] ?? '+237'),
        'telephone'          => (string) preg_replace('/[^\d]/', '', (string) ($_POST['telephone'] ?? '')),
        'email'              => trim((string) ($_POST['email'] ?? '')),
        'adresse'            => trim((string) ($_POST['adresse'] ?? '')),
        'numero_identifiant' => strtoupper(trim((string) ($_POST['numero_identifiant'] ?? ''))),
    ];
    $old = array_filter($in, fn ($v) => $v !== '') + $old;

    if (isset($errors['_form'])) {
        // envoi trop volumineux : rien d'autre à contrôler
    } elseif (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        $errors['_form'] = 'Votre session a expiré. Vérifiez la saisie, sélectionnez à nouveau les fichiers puis renvoyez le formulaire.';
    } else {
        if (!in_array($in['type_client'], ['particulier', 'entreprise'], true)) {
            $errors['type_client'] = 'Choisissez le type de client.';
        }
        if (!in_array($in['piece_identite'], ['cni', 'passeport'], true)) {
            $in['piece_identite'] = 'cni';
        }
        $len = mb_strlen($in['nom_complet']);
        if ($len < 2 || $len > 255) {
            $errors['nom_complet'] = $in['type_client'] === 'entreprise' ? 'Indiquez la raison sociale.' : 'Indiquez le nom complet.';
        }
        if (!isset(KYC_DIAL_CODES[$in['indicatif']])) {
            $errors['telephone'] = 'Indicatif inconnu.';
        } elseif (strlen($in['telephone']) < 6 || strlen($in['telephone']) > 15) {
            $errors['telephone'] = 'Numéro invalide (6 à 15 chiffres, sans l\'indicatif).';
        }
        if ($in['email'] !== '' && (strlen($in['email']) > 150 || !filter_var($in['email'], FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if (mb_strlen($in['adresse']) > 2000) {
            $errors['adresse'] = 'Adresse trop longue (2 000 caractères au maximum).';
        } elseif (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $in['adresse'])) {
            // Saisie ou remplissage automatique du navigateur : l'e-mail se retrouverait sur les reçus et factures
            $errors['adresse'] = 'L\'adresse ne doit pas contenir d\'e-mail : indiquez-le dans le champ « E-mail ».';
        }
        if (!preg_match('~^[A-Z0-9][A-Z0-9 ./\-]{2,99}$~', $in['numero_identifiant'])) {
            $errors['numero_identifiant'] = 'Numéro invalide : lettres, chiffres, espaces, « / », « - » ou « . » (3 caractères minimum).';
        }

        // Fichiers : obligatoires selon le cas, facultatifs sinon ; les pièces hors sujet sont ignorées
        $required = kyc_required($in['type_client'], $in['piece_identite']);
        $allowed  = $in['type_client'] === 'entreprise' ? ['doc_identite', 'doc_rccm', 'doc_statuts'] : $required;
        $files    = [];
        foreach ($allowed as $slot) {
            $f = $_FILES[$slot] ?? null;
            if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                if (in_array($slot, $required, true)) {
                    $errors[$slot] = 'Cette pièce est obligatoire.';
                }
                continue;
            }
            $check = kyc_check_file($f);
            if (is_string($check)) {
                $errors[$slot] = $check;
            } else {
                $files[$slot] = $f + ['ext' => $check[0], 'mime' => $check[1]];
            }
        }

        if (!$errors) {
            $typeDoc = [
                'doc_identite' => $in['piece_identite'] === 'passeport' ? 'passeport' : 'cni_recto',
                'doc_verso'    => 'cni_verso',
                'doc_rccm'     => 'rccm',
                'doc_statuts'  => 'statut_entreprise',
            ];
            $saved = [];
            $pdo   = null;
            try {
                $pdo = vault_db();
                $dir = KYC_STORAGE_DIR . '/' . date('Y/m');
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new RuntimeException('Dossier de stockage inaccessible.');
                }
                $now  = date('Y-m-d H:i:s');
                $uuid = uuid_v4();
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO clients (uuid, type_client, nom_complet, telephone, email, adresse, numero_identifiant, created_at, updated_at, created_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$uuid, $in['type_client'], $in['nom_complet'], $in['indicatif'] . $in['telephone'],
                               $in['email'] ?: null, $in['adresse'] ?: null, $in['numero_identifiant'], $now, $now, $userId]);
                $clientId = (int) $pdo->lastInsertId();

                $insDoc = $pdo->prepare('INSERT INTO client_documents (client_id, type_document, nom_original, chemin_fichier, taille_fichier, mime_type, created_at, uploaded_by)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($files as $slot => $f) {
                    $rel  = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $f['ext'];
                    $dest = KYC_STORAGE_DIR . '/' . $rel;
                    if (!move_uploaded_file($f['tmp_name'], $dest)) {
                        throw new RuntimeException('Copie du fichier impossible.');
                    }
                    $saved[] = $dest;
                    @chmod($dest, 0640);
                    $name = mb_substr(preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', basename((string) $f['name'])), 0, 255);
                    $insDoc->execute([$clientId, $typeDoc[$slot], $name, $rel, (int) $f['size'], $f['mime'], $now, $userId]);
                }
                $pdo->commit();

                // Traçabilité : journal d'activité du Dashboard (qui a créé quel dossier) et journal de sécurité KYC
                $docTypes = array_map(fn ($s) => $typeDoc[$s], array_keys($files));
                activity_log('create', 'kyc_client', $clientId, $in['nom_complet'],
                    ['type' => $in['type_client'], 'pieces' => implode(', ', $docTypes)], $user);
                vault_audit('client_create', ['uuid' => $uuid, 'nom_complet' => $in['nom_complet']], null,
                    $user['name'] . ' a créé le dossier KYC de ' . $in['nom_complet'] . ' (' . count($files) . ' pièce' . (count($files) > 1 ? 's' : '') . ')');

                $_SESSION['kyc_flash'] = ['name' => $in['nom_complet'], 'uuid' => $uuid, 'docs' => count($files),
                    // Pièce d'identité en image : la photo de profil peut être recadrée tout de suite
                    'photo' => isset($files['doc_identite']) && $files['doc_identite']['mime'] !== 'application/pdf'];
                header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?ok=1', true, 303);
                exit;
            } catch (Throwable $e) {
                if ($pdo && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($saved as $path) {
                    @unlink($path);
                }
                error_log('[GTC-Vault KYC] ' . $e->getMessage());
                $errors['_form'] = 'Le dossier n\'a pas pu être enregistré. Réessayez ; si le problème persiste, contactez l\'administrateur.';
            }
        }
    }
}

$hasFileErrors = (bool) array_intersect_key($errors, $slots);
$isCompany     = ($old['type_client'] ?? '') === 'entreprise';

/** Zone de dépôt d'une pièce (fichier du poste) + bouton de capture par téléphone. */
function dropzone(string $slot, string $label, string $hint, array $errors, string $extra = ''): string
{
    $err = $errors[$slot] ?? '';
    ob_start(); ?>
    <div class="kyc-slot" data-slot="<?= h($slot) ?>" <?= $extra ?>>
        <div class="kyc-slot__head">
            <span class="kyc-slot__label" id="<?= h($slot) ?>-label"><?= h($label) ?></span>
            <span class="kyc-slot__tools">
                <!-- Mode « Appareil photo mobile » : un QR code pour toutes les pièces (capture-api.php → capture.php) -->
                <button type="button" class="kyc-mobile-btn" data-mobile aria-describedby="<?= h($slot) ?>-label" title="Photographier les pièces avec un téléphone">
                    <?= vault_icon('phone') ?> Scanner avec un mobile
                </button>
                <span class="kyc-req" data-badge></span>
            </span>
        </div>
        <label for="<?= h($slot) ?>" data-drop class="kyc-drop<?= $err ? ' is-invalid' : '' ?>">
            <input type="file" id="<?= h($slot) ?>" name="<?= h($slot) ?>" class="visually-hidden"
                   accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                   aria-labelledby="<?= h($slot) ?>-label" aria-describedby="<?= h($slot) ?>-hint <?= h($slot) ?>-error">
            <span class="kyc-drop__icon"><?= vault_icon('upload') ?></span>
            <span class="kyc-drop__text"><strong>Choisir un fichier</strong> ou glissez-le ici</span>
            <span class="kyc-drop__hint" id="<?= h($slot) ?>-hint"><?= h($hint) ?></span>
        </label>
        <div data-preview hidden class="kyc-file">
            <div data-thumb class="kyc-file__thumb"></div>
            <div class="kyc-file__body">
                <p data-name class="kyc-file__name"></p>
                <p data-meta class="kyc-file__meta"></p>
            </div>
            <button type="button" data-replace class="btn btn--ghost btn--sm">Remplacer</button>
            <button type="button" data-remove class="icon-btn icon-btn--danger" aria-label="Retirer le fichier" title="Retirer"><?= vault_icon('x') ?></button>
        </div>
        <p class="field__error" id="<?= h($slot) ?>-error" data-error role="alert"><?= h($err) ?></p>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Message d'erreur sous un champ (masqué s'il est vide). */
$errField = fn (string $name) => '<p class="field__error" id="' . h($name) . '-error" data-error-for="' . h($name) . '"'
    . (isset($errors[$name]) ? '>' . h($errors[$name]) : ' hidden>') . '</p>';
$invalid = fn (string $name) => isset($errors[$name]) ? ' aria-invalid="true"' : '';

vault_header('GTC-Vault · Nouveau dossier KYC');
?>

<div class="toolbar">
    <a href="vault/clients.php" class="btn btn--ghost"><?= icon('arrow-left') ?> Répertoire KYC</a>
    <p class="page-intro">Identité du client et pièces justificatives. Les champs marqués <span class="text-danger">*</span> sont obligatoires.</p>
</div>

<?php if ($flash && isset($_GET['ok'])): ?>
    <div class="alert alert--success" role="status">
        <?= icon('check') ?>
        <div>
            <p><strong>Dossier de <?= h($flash['name']) ?> enregistré</strong> — <?= (int) $flash['docs'] ?> pièce<?= $flash['docs'] > 1 ? 's' : '' ?> archivée<?= $flash['docs'] > 1 ? 's' : '' ?>.</p>
            <p class="vault-flash-links">
                <a href="vault/client.php?uuid=<?= h($flash['uuid']) ?>">Ouvrir la fiche</a>
                <?php if (!empty($flash['photo'])): ?>
                    · <a href="vault/client.php?uuid=<?= h($flash['uuid']) ?>#photo" data-flash-photo>Définir la photo de profil</a>
                <?php endif; ?>
            </p>
        </div>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert" id="error-summary" tabindex="-1">
        <?= icon('alert') ?>
        <div>
            <p><strong><?= h($errors['_form'] ?? 'Le dossier n\'a pas été enregistré : corrigez les points signalés.') ?></strong></p>
            <?php if ($hasFileErrors || !isset($errors['_form'])): ?>
                <p>Pour votre sécurité, les fichiers ne sont pas conservés après une erreur : sélectionnez-les à nouveau.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate id="kyc-form" class="dash-grid dash-grid--aside" data-max-bytes="<?= KYC_MAX_BYTES ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= KYC_MAX_BYTES ?>">

    <div class="vault-stack">
        <!-- ==================== 1. INFORMATIONS CLIENT ==================== -->
        <section class="panel" aria-labelledby="s-client">
            <header class="panel__head">
                <h2 class="panel__title" id="s-client"><span class="vault-step">1</span> Informations client</h2>
            </header>

            <fieldset class="field">
                <legend class="field__legend">Type de client <span class="text-danger">*</span></legend>
                <div class="choice-grid vault-types">
                    <?php foreach (['particulier' => ['Particulier', 'Personne physique', 'user'], 'entreprise' => ['Entreprise', 'Personne morale', 'building']] as $val => [$t, $sub, $ic]): ?>
                        <label class="choice">
                            <input type="radio" name="type_client" value="<?= $val ?>" <?= ($old['type_client'] ?? '') === $val ? 'checked' : '' ?>>
                            <span><?= vault_icon($ic) ?><span><strong><?= $t ?></strong><small><?= $sub ?></small></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?= $errField('type_client') ?>
            </fieldset>

            <div class="form-grid vault-form-grid">
                <div class="field field--full">
                    <label for="nom_complet"><span data-company-text="Raison sociale" data-person-text="Nom complet"><?= $isCompany ? 'Raison sociale' : 'Nom complet' ?></span> <span class="text-danger">*</span></label>
                    <input type="text" id="nom_complet" name="nom_complet" required minlength="2" maxlength="255" autocomplete="off"
                           value="<?= h($old['nom_complet'] ?? '') ?>"<?= $invalid('nom_complet') ?>
                           data-company-placeholder="Ex. : GLOBAL TRADING CORPORATION SARL" data-person-placeholder="Ex. : Jean-Paul Mballa Ngono"
                           placeholder="<?= $isCompany ? 'Ex. : GLOBAL TRADING CORPORATION SARL' : 'Ex. : Jean-Paul Mballa Ngono' ?>" aria-describedby="nom_complet-error">
                    <?= $errField('nom_complet') ?>
                </div>

                <div class="field">
                    <label for="telephone">Téléphone <span class="text-danger">*</span></label>
                    <div class="vault-phone">
                        <label for="indicatif" class="visually-hidden">Indicatif du pays</label>
                        <select id="indicatif" name="indicatif" data-native>
                            <?php foreach (KYC_DIAL_CODES as $code => [$iso, $country]): ?>
                                <option value="<?= h($code) ?>" title="<?= h($country) ?>" <?= ($old['indicatif'] ?? '') === $code ? 'selected' : '' ?>><?= h($code) ?> <?= h($iso) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="tel" id="telephone" name="telephone" required inputmode="tel" autocomplete="off" maxlength="20"
                               value="<?= h($old['telephone'] ?? '') ?>" placeholder="6 70 00 00 00"<?= $invalid('telephone') ?> aria-describedby="telephone-error">
                    </div>
                    <?= $errField('telephone') ?>
                </div>

                <div class="field">
                    <label for="email">E-mail <span class="field__hint">(facultatif)</span></label>
                    <input type="email" id="email" name="email" maxlength="150" autocomplete="email" inputmode="email"
                           value="<?= h($old['email'] ?? '') ?>" placeholder="nom@exemple.com"<?= $invalid('email') ?> aria-describedby="email-error">
                    <?= $errField('email') ?>
                </div>

                <div class="field field--full">
                    <label for="numero_identifiant"><span data-company-text="N° RCCM" data-person-text="N° CNI ou passeport"><?= $isCompany ? 'N° RCCM' : 'N° CNI ou passeport' ?></span> <span class="text-danger">*</span></label>
                    <input type="text" id="numero_identifiant" name="numero_identifiant" required maxlength="100" autocomplete="off" class="vault-uppercase vault-mono"
                           value="<?= h($old['numero_identifiant'] ?? '') ?>"<?= $invalid('numero_identifiant') ?>
                           data-company-placeholder="Ex. : RC/DLA/2019/B/2990" data-person-placeholder="Ex. : 112233445"
                           placeholder="<?= $isCompany ? 'Ex. : RC/DLA/2019/B/2990' : 'Ex. : 112233445' ?>" aria-describedby="numero_identifiant-error">
                    <?= $errField('numero_identifiant') ?>
                </div>

                <div class="field field--full">
                    <label for="adresse">Adresse <span class="field__hint">(facultatif)</span></label>
                    <textarea id="adresse" name="adresse" rows="3" maxlength="2000" autocomplete="street-address" placeholder="Quartier, rue, ville, pays"<?= $invalid('adresse') ?> aria-describedby="adresse-error"><?= h($old['adresse'] ?? '') ?></textarea>
                    <?= $errField('adresse') ?>
                </div>
            </div>
        </section>

        <!-- ==================== 2. PIÈCES JUSTIFICATIVES ==================== -->
        <section class="panel" aria-labelledby="s-docs">
            <header class="panel__head">
                <h2 class="panel__title" id="s-docs"><span class="vault-step">2</span> Pièces justificatives</h2>
                <span class="cell-sub">JPG, PNG ou PDF · 5 Mo maximum par fichier</span>
            </header>

            <!-- Deux modes de capture -->
            <div class="vault-modes">
                <div class="vault-mode">
                    <span class="vault-mode__icon"><?= vault_icon('monitor') ?></span>
                    <div>
                        <strong>Fichier / Scanner de bureau <span class="badge badge--info">Par défaut</span></strong>
                        <p>Glissez ou choisissez un PDF, JPG ou PNG (scanner réseau, PC).</p>
                    </div>
                </div>
                <div class="vault-mode vault-mode--mobile">
                    <span class="vault-mode__icon"><?= vault_icon('phone') ?></span>
                    <div>
                        <strong>Appareil photo mobile</strong>
                        <p>« Scanner avec un mobile » : un seul QR code, le téléphone photographie toutes les pièces à la suite.</p>
                    </div>
                </div>
            </div>

            <fieldset class="field vault-piece">
                <legend class="field__legend"><span data-company-text="Pièce d'identité du représentant légal" data-person-text="Pièce d'identité"><?= $isCompany ? 'Pièce d\'identité du représentant légal' : 'Pièce d\'identité' ?></span></legend>
                <div class="vault-seg">
                    <?php foreach (['cni' => 'Carte nationale d\'identité', 'passeport' => 'Passeport'] as $val => $t): ?>
                        <label><input type="radio" name="piece_identite" value="<?= $val ?>" <?= ($old['piece_identite'] ?? 'cni') === $val ? 'checked' : '' ?>><span><?= $t ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="kyc-slots">
                <?= dropzone('doc_identite', 'CNI — recto', 'Face avec la photo, bien lisible', $errors,
                    'data-label-cni="CNI — recto" data-label-passeport="Passeport — page photo"') ?>
                <?= dropzone('doc_verso', 'CNI — verso', 'Face arrière, bien lisible', $errors, 'data-for="particulier-cni"') ?>
                <?= dropzone('doc_rccm', 'Registre du commerce (RCCM)', 'Extrait RCCM en cours de validité', $errors, 'data-for="entreprise"') ?>
                <?= dropzone('doc_statuts', 'Statuts de l\'entreprise', 'Facultatif · statuts signés', $errors, 'data-for="entreprise"') ?>
            </div>
        </section>
    </div>

    <!-- ==================== RÉCAPITULATIF ET ENVOI ==================== -->
    <aside class="vault-aside">
        <section class="panel">
            <h2 class="panel__title">Récapitulatif</h2>
            <ul class="kyc-checklist" id="kyc-checklist" aria-live="polite"></ul>
            <p class="kyc-total"><span>Fichiers sélectionnés</span><strong id="kyc-total">0 · 0 Ko</strong></p>
            <button type="submit" id="kyc-submit" class="btn btn--primary btn--block kyc-submit">
                <span data-icon><?= icon('shield') ?></span>
                <span class="vault-spin" data-spinner hidden aria-hidden="true"></span>
                <span data-label>Enregistrer le dossier KYC</span>
            </button>
            <p class="vault-note"><?= icon('lock') ?> Les pièces sont conservées dans un espace privé, accessible uniquement aux agents habilités.</p>
        </section>
    </aside>
</form>

<!-- ==================== CAPTURE PAR TÉLÉPHONE : UN QR CODE POUR TOUTES LES PIÈCES ==================== -->
<dialog id="capture-dialog" class="vault-dialog" aria-labelledby="capture-title">
    <div class="vault-dialog__head">
        <span class="vault-dialog__icon"><?= vault_icon('phone') ?></span>
        <div>
            <h2 id="capture-title">Scanner avec un mobile</h2>
            <p id="capture-sub">Un seul QR code pour toutes les pièces, à la suite.</p>
        </div>
        <button type="button" class="icon-btn" data-capture-close aria-label="Fermer"><?= vault_icon('x') ?></button>
    </div>

    <!-- QR code et marche à suivre (masqués une fois toutes les pièces reçues) -->
    <div id="capture-scan" class="cap-scan">
        <div id="capture-qr" class="cap-qr" role="img" aria-label="QR code de capture"><span class="vault-spin"></span></div>
        <ol class="pwa-dialog__steps">
            <li>Flashez ce QR code avec l'appareil photo du téléphone.</li>
            <li>Photographiez chaque pièce : le téléphone passe seul à la suivante.</li>
            <li>Vérifiez les photos ici, puis validez.</li>
        </ol>
    </div>

    <!-- Pièces de la session : état et photo reçue -->
    <ul id="capture-steps" class="cap-steps" aria-live="polite"></ul>

    <div id="capture-status" class="cap-status" role="status" aria-live="polite" data-tone="wait">
        <span class="cap-status__dot"></span>
        <span class="cap-status__text" data-text>Préparation du QR code…</span>
        <span class="cap-status__timer" data-timer></span>
    </div>

    <div class="vault-dialog__actions">
        <button type="button" class="vault-link-btn" data-capture-copy hidden>Copier le lien (WhatsApp…)</button>
        <span class="vault-dialog__end">
            <button type="button" class="btn btn--primary btn--sm" data-capture-renew hidden><?= icon('refresh') ?> Nouveau QR code</button>
            <button type="button" class="btn btn--ghost btn--sm" data-capture-close data-cancel-label>Annuler</button>
            <button type="button" class="btn btn--primary btn--sm" data-capture-validate hidden><?= icon('check') ?> Photos correctes, valider</button>
        </span>
    </div>
    <p class="vault-dialog__fine">QR code personnel, valable 15 minutes pour les pièces de ce dossier. Les photos sont transmises au dossier sécurisé puis effacées du serveur de transit.</p>
</dialog>

<script src="vault/assets/qrcode/qrcode.min.js"></script>
<script>
(function () {
  'use strict';

  var form = document.getElementById('kyc-form');
  var MAX = parseInt(form.getAttribute('data-max-bytes'), 10);
  var TYPES = { 'image/jpeg': 'JPG', 'image/png': 'PNG', 'application/pdf': 'PDF' };
  var EXT = /\.(jpe?g|png|pdf)$/i;
  var slots = {};
  var SVG = function (paths, cls) { return '<svg class="icon' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>'; };
  var ICON_OK = SVG('<path d="m5 12 5 5 9-10"/>'), ICON_PHONE = SVG('<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>');
  var ICON_PDF = SVG('<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5"/>');

  function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmtSize(b) { return b < 1024 * 1024 ? Math.max(1, Math.round(b / 1024)) + ' Ko' : (b / 1048576).toFixed(1).replace('.', ',') + ' Mo'; }
  function val(name) { var el = form.querySelector('[name="' + name + '"]:checked') || form.elements[name]; return el ? el.value : ''; }
  function isCompany() { return val('type_client') === 'entreprise'; }

  /** Pièces visibles et obligatoires (mêmes règles que le serveur, kyc_required()). */
  function rules() {
    if (isCompany()) return { visible: ['doc_identite', 'doc_rccm', 'doc_statuts'], required: ['doc_rccm', 'doc_identite'] };
    var cni = val('piece_identite') === 'cni';
    return { visible: cni ? ['doc_identite', 'doc_verso'] : ['doc_identite'], required: cni ? ['doc_identite', 'doc_verso'] : ['doc_identite'] };
  }

  // ---------- Champs texte ----------
  var checks = {
    nom_complet: function (v) { v = v.trim(); return v.length >= 2 ? '' : (isCompany() ? 'Indiquez la raison sociale.' : 'Indiquez le nom complet.'); },
    telephone: function (v) { var d = v.replace(/\D/g, ''); return d.length >= 6 && d.length <= 15 ? '' : 'Numéro invalide (6 à 15 chiffres, sans l\'indicatif).'; },
    email: function (v) { v = v.trim(); return !v || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? '' : 'Adresse e-mail invalide.'; },
    adresse: function (v) { return /[^\s@]+@[^\s@]+\.[^\s@]{2,}/.test(v) ? 'L\'adresse ne doit pas contenir d\'e-mail : indiquez-le dans le champ « E-mail ».' : ''; },
    numero_identifiant: function (v) { return /^[A-Z0-9][A-Z0-9 .\/\-]{2,99}$/i.test(v.trim()) ? '' : 'Numéro invalide : lettres, chiffres, espaces, « / », « - » ou « . » (3 caractères minimum).'; }
  };

  /** Erreur d'un champ : message sous le champ, bordure rouge du Dashboard (aria-invalid). */
  function setFieldError(name, msg) {
    var input = form.elements[name], box = form.querySelector('[data-error-for="' + name + '"]');
    if (!input || !box) return;
    box.textContent = msg; box.hidden = !msg;
    if (msg) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
  }
  Object.keys(checks).forEach(function (name) {
    var input = form.elements[name];
    input.addEventListener('blur', function () { if (input.value.trim() || input.hasAttribute('data-touched')) setFieldError(name, checks[name](input.value)); input.setAttribute('data-touched', ''); });
    input.addEventListener('input', function () { if (input.getAttribute('aria-invalid') === 'true') setFieldError(name, checks[name](input.value)); renderChecklist(); });
  });

  // ---------- Zones de dépôt ----------
  form.querySelectorAll('.kyc-slot').forEach(function (el) {
    var slot = el.getAttribute('data-slot');
    var s = slots[slot] = {
      el: el, input: el.querySelector('input[type=file]'), drop: el.querySelector('[data-drop]'), preview: el.querySelector('[data-preview]'),
      thumb: el.querySelector('[data-thumb]'), name: el.querySelector('[data-name]'), meta: el.querySelector('[data-meta]'),
      error: el.querySelector('[data-error]'), badge: el.querySelector('[data-badge]'), url: null
    };

    function setError(msg) { s.error.textContent = msg; s.drop.classList.toggle('is-invalid', !!msg); }
    s.setError = setError;

    function clear() {
      s.input.value = '';
      if (s.url) { URL.revokeObjectURL(s.url); s.url = null; }
      s.preview.hidden = true; s.drop.hidden = false; s.thumb.innerHTML = ''; s.thumb.className = 'kyc-file__thumb';
      renderChecklist();
    }
    s.clear = clear;

    function show(file, source) {
      if (s.url) URL.revokeObjectURL(s.url);
      s.thumb.innerHTML = ''; s.thumb.className = 'kyc-file__thumb';
      if (/^image\//.test(file.type)) {
        s.url = URL.createObjectURL(file);
        var img = new Image(); img.src = s.url; img.alt = '';
        s.thumb.appendChild(img);
      } else {
        s.thumb.className = 'kyc-file__thumb kyc-file__thumb--pdf';
        s.thumb.innerHTML = ICON_PDF + '<span>PDF</span>';
      }
      s.name.textContent = file.name; s.name.title = file.name;
      s.meta.textContent = (TYPES[file.type] || 'Fichier') + ' · ' + fmtSize(file.size);
      // Photo reçue d'un téléphone : mention avec icône (texte fixe, aucune donnée saisie)
      if (source === 'mobile') s.meta.insertAdjacentHTML('afterbegin', '<span class="kyc-file__mobile">' + ICON_PHONE + 'Photo prise au mobile</span> · ');
      s.drop.hidden = true; s.preview.hidden = false;
      setError('');
      renderChecklist();
    }

    /** Contrôle d'un fichier : message d'erreur ou ''. */
    function check(file) {
      if (!(file.type in TYPES) || !EXT.test(file.name)) return 'Format refusé : seuls JPG, PNG et PDF sont acceptés.';
      if (file.size > MAX) return 'Fichier trop lourd (' + fmtSize(file.size) + ') : 5 Mo au maximum.';
      if (file.size === 0) return 'Le fichier est vide.';
      return '';
    }

    /** source : 'mobile' pour une photo reçue d'un téléphone (QR code), sinon fichier du poste. Renvoie true si acceptée. */
    function accept(files, source) {
      if (!files || !files.length) return false;
      if (files.length > 1) { setError('Un seul fichier par pièce.'); return false; }
      var file = files[0], msg = check(file);
      if (msg) { clear(); setError(msg); return false; }
      // Fichier déposé par glisser-déposer ou reçu du téléphone : recopié dans le champ pour un envoi classique du formulaire
      if (s.input.files[0] !== file) {
        try { var dt = new DataTransfer(); dt.items.add(file); s.input.files = dt.files; }
        catch (e) { setError('Votre navigateur ne gère pas le glisser-déposer : cliquez sur « Choisir un fichier ».'); return false; }
      }
      show(file, source);
      return true;
    }
    s.accept = accept;
    el.querySelector('[data-mobile]').addEventListener('click', function () { captureOpen(slot, this); });

    s.input.addEventListener('change', function () { s.input.files.length ? accept(s.input.files) : clear(); });
    ['dragenter', 'dragover'].forEach(function (t) { s.drop.addEventListener(t, function (e) { e.preventDefault(); s.drop.classList.add('is-dragover'); }); });
    ['dragleave', 'dragend', 'drop'].forEach(function (t) { s.drop.addEventListener(t, function () { s.drop.classList.remove('is-dragover'); }); });
    s.drop.addEventListener('drop', function (e) { e.preventDefault(); accept(e.dataTransfer.files); });
    el.querySelector('[data-remove]').addEventListener('click', function () { clear(); s.input.focus(); });
    el.querySelector('[data-replace]').addEventListener('click', function () { s.input.click(); });
  });
  // Fichier lâché à côté d'une zone : le navigateur ne doit pas l'ouvrir à la place de la page
  ['dragover', 'drop'].forEach(function (t) { window.addEventListener(t, function (e) { if (!e.target.closest || !e.target.closest('[data-drop]')) e.preventDefault(); }); });

  // ---------- Particulier / Entreprise, CNI / Passeport ----------
  function applyType() {
    var company = isCompany(), r = rules();
    form.querySelectorAll('[data-company-text]').forEach(function (n) { n.textContent = n.getAttribute(company ? 'data-company-text' : 'data-person-text'); });
    form.querySelectorAll('[data-company-placeholder]').forEach(function (n) { n.placeholder = n.getAttribute(company ? 'data-company-placeholder' : 'data-person-placeholder'); });
    var idSlot = slots.doc_identite.el, piece = val('piece_identite');
    idSlot.querySelector('[id$="-label"]').textContent = idSlot.getAttribute('data-label-' + piece);
    Object.keys(slots).forEach(function (k) {
      var s = slots[k], visible = r.visible.indexOf(k) !== -1, required = r.required.indexOf(k) !== -1;
      s.el.hidden = !visible;
      s.input.disabled = !visible;                       // une pièce masquée n'est jamais envoyée
      s.badge.textContent = required ? 'Obligatoire' : 'Facultatif';
      s.badge.classList.toggle('is-required', required);
    });
    renderChecklist();
  }
  form.querySelectorAll('[name="type_client"], [name="piece_identite"]').forEach(function (n) { n.addEventListener('change', applyType); });

  // ---------- Récapitulatif ----------
  function renderChecklist() {
    var r = rules(), items = [], count = 0, bytes = 0;
    items.push([form.elements.nom_complet.value.trim().length >= 2 && !checks.telephone(form.elements.telephone.value) && !checks.numero_identifiant(form.elements.numero_identifiant.value), 'Informations client', true]);
    r.visible.forEach(function (k) {
      var s = slots[k], f = s.input.files[0];
      if (f) { count++; bytes += f.size; }
      items.push([!!f, s.el.querySelector('[id$="-label"]').textContent, r.required.indexOf(k) !== -1]);
    });
    document.getElementById('kyc-checklist').innerHTML = items.map(function (it) {
      return '<li class="' + (it[0] ? 'is-done' : '') + (it[2] ? '' : ' is-optional') + '"><span class="kyc-check">' + (it[0] ? ICON_OK : '') + '</span>'
        + '<span class="kyc-checklist__label">' + esc(it[1]) + '</span>' + (it[2] ? '' : '<small>facultatif</small>')
        + '<span class="visually-hidden">' + (it[0] ? ' : fait' : ' : à compléter') + '</span></li>';
    }).join('');
    document.getElementById('kyc-total').textContent = count + ' · ' + (bytes ? fmtSize(bytes) : '0 Ko');
  }

  // ---------- Envoi ----------
  var submitBtn = document.getElementById('kyc-submit');
  function submitting(on) {
    submitBtn.disabled = on;
    submitBtn.querySelector('[data-icon]').hidden = on;
    submitBtn.querySelector('[data-spinner]').hidden = !on;
    submitBtn.querySelector('[data-label]').textContent = on ? 'Enregistrement en cours…' : 'Enregistrer le dossier KYC';
  }
  form.addEventListener('submit', function (e) {
    var first = null, r = rules();
    Object.keys(checks).forEach(function (name) {
      var msg = checks[name](form.elements[name].value);
      setFieldError(name, msg);
      if (msg && !first) first = form.elements[name];
    });
    r.visible.forEach(function (k) {
      var s = slots[k];
      if (!s.input.files.length && r.required.indexOf(k) !== -1) { s.setError('Cette pièce est obligatoire.'); if (!first) first = s.input; }
    });
    if (first) {
      e.preventDefault();
      (first.type === 'file' ? first.closest('.kyc-slot') : first).scrollIntoView({ behavior: 'smooth', block: 'center' });
      first.focus({ preventScroll: true });
      return;
    }
    submitting(true);                                      // pas de double envoi
  });
  // Retour arrière vers la page : bouton réactivé
  window.addEventListener('pageshow', function () { submitting(false); });

  // ---------- Mode « Appareil photo mobile » : UN QR code → le téléphone photographie toutes les pièces à la suite ----------
  // Pièces de la session : celle cliquée d'abord, puis les autres pièces visibles encore vides (ex. recto → verso).
  // Chaque photo reçue est placée aussitôt dans sa pièce ; une photo reprise sur le téléphone remplace la précédente.
  var cap = { dialog: document.getElementById('capture-dialog'), id: null, url: '', end: 0, poll: null, tick: null, run: 0, opener: null, steps: [], first: null, next: null };
  var capQr = document.getElementById('capture-qr'), capStatus = document.getElementById('capture-status'), capList = document.getElementById('capture-steps');
  var CSRF = form.elements.csrf_token.value;
  var stepDone = function (s) { return s.received || s.status === 'skipped'; };

  /** État affiché : tone = wait (orange), live (bleu), ok (vert), error (rouge). */
  function capState(text, tone) { capStatus.querySelector('[data-text]').textContent = text; capStatus.setAttribute('data-tone', tone); }
  function capStop() { clearTimeout(cap.poll); clearInterval(cap.tick); cap.poll = cap.tick = null; }
  function capExpired(msg) {
    capStop(); cap.id = null;
    capQr.classList.add('is-dim');
    capStatus.querySelector('[data-timer]').textContent = '';
    capState(msg || 'QR code expiré. Générez-en un nouveau.', 'error');
    cap.dialog.querySelector('[data-capture-renew]').hidden = false;
  }

  /** Liste des pièces de la session : en attente / photo reçue (miniature) / passée ; vérification finale. */
  function capRenderSteps() {
    var all = cap.steps.length && cap.steps.every(stepDone);
    capList.className = 'cap-steps' + (all && cap.steps.length > 1 ? ' cap-steps--review' : '');
    capList.innerHTML = cap.steps.map(function (s, i) {
      var st = s.received ? '<span class="cap-step__state is-ok">' + ICON_OK + ' Reçue</span>'
        : s.status === 'skipped' ? '<span class="cap-step__state">Passée</span>'
        : (cap.next && cap.next.id === s.id ? '<span class="cap-step__state is-live">En cours sur le téléphone…</span>' : '<span class="cap-step__state">En attente</span>');
      var thumb = '<span class="cap-step__thumb">' + (s.thumb ? '<img src="' + s.thumb + '" alt="">' : (i + 1)) + '</span>';
      return '<li class="cap-step">' + thumb + '<div class="cap-step__body"><p class="cap-step__label">' + esc(s.label)
        + (s.required ? '' : ' <small>(facultative)</small>') + '</p>' + st + '</div></li>';
    }).join('');
    // Toutes les pièces reçues : QR code masqué, vérification et validation
    document.getElementById('capture-scan').hidden = all;
    cap.dialog.querySelector('[data-capture-validate]').hidden = !all;
    cap.dialog.querySelector('[data-cancel-label]').hidden = all;
    if (all) capState('Toutes les pièces sont arrivées. Vérifiez qu\'elles sont nettes, puis validez (une photo reprise sur le téléphone remplacera la précédente).', 'ok');
  }

  /** Pièces à photographier : la pièce cliquée, puis les autres pièces visibles encore vides, dans l'ordre du formulaire. */
  function capSequence(slot) {
    var r = rules(), seq = [slot];
    r.visible.forEach(function (k) { if (k !== slot && !slots[k].input.files.length && seq.length < 4) seq.push(k); });
    return seq;
  }

  function captureOpen(slot, opener) {
    var run = ++cap.run;
    capStop();
    var seq = capSequence(slot), r = rules();
    cap.id = null; cap.opener = opener || cap.opener; cap.first = slot; cap.next = null;
    cap.steps = seq.map(function (k) { return { id: null, slot: k, label: slots[k].el.querySelector('[id$="-label"]').textContent, required: r.required.indexOf(k) !== -1, status: 'pending', version: '', received: false, thumb: null }; });
    document.getElementById('capture-sub').textContent = seq.length > 1
      ? 'Un seul QR code pour ' + seq.length + ' pièces, à la suite : ' + cap.steps.map(function (s) { return s.label; }).join(', ') + '.'
      : 'Pièce : ' + cap.steps[0].label + '.';
    capQr.classList.remove('is-dim');
    capQr.innerHTML = '<span class="vault-spin"></span>';
    cap.dialog.querySelector('[data-capture-renew]').hidden = true;
    cap.dialog.querySelector('[data-capture-copy]').hidden = true;
    capStatus.querySelector('[data-timer]').textContent = '';
    capState('Préparation du QR code…', 'wait');
    capRenderSteps();
    if (!cap.dialog.open) cap.dialog.showModal();

    var fd = new FormData();
    fd.append('csrf_token', CSRF); fd.append('slots', seq.join(',')); fd.append('piece', val('piece_identite'));
    fd.append('optional', seq.filter(function (k) { return r.required.indexOf(k) === -1; }).join(','));
    fetch('vault/capture-api.php?action=create', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, status: res.status, d: d }; }); })
      .then(function (res) {
        if (run !== cap.run) return;
        if (!res.ok) { capExpired(res.d.message || (res.status === 401 ? 'Session expirée : reconnectez-vous.' : 'Le QR code n\'a pas pu être créé.')); return; }
        cap.id = res.d.id; cap.url = res.d.url; cap.end = Date.now() + res.d.expires_in * 1000;
        res.d.steps.forEach(function (s, i) { cap.steps[i].id = s.id; cap.steps[i].label = s.label; cap.steps[i].required = s.required; });
        if (typeof qrcode !== 'function') { capExpired('Le générateur de QR code n\'a pas pu être chargé.'); return; }
        var qr = qrcode(0, 'M'); qr.addData(cap.url); qr.make();
        capQr.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
        var svg = capQr.querySelector('svg'); svg.setAttribute('width', '100%'); svg.setAttribute('height', '100%'); svg.setAttribute('aria-hidden', 'true');
        cap.dialog.querySelector('[data-capture-copy]').hidden = false;
        capState('En attente du téléphone…', 'wait');
        capRenderSteps();
        capTick(); cap.tick = setInterval(capTick, 1000);
        capPoll(run);
      })
      .catch(function () { if (run === cap.run) capExpired('Connexion au serveur impossible. Réessayez.'); });
  }

  function capTick() {
    var s = Math.max(0, Math.round((cap.end - Date.now()) / 1000));
    capStatus.querySelector('[data-timer]').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    if (!s) capExpired(cap.steps.some(function (x) { return x.received; }) ? 'QR code expiré. Les photos reçues restent dans le formulaire.' : null);
  }

  function capPoll(run) {
    cap.poll = setTimeout(function () {
      if (run !== cap.run || !cap.id) return;
      fetch('vault/capture-api.php?action=status&id=' + cap.id, { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { if (r.status === 401) throw new Error('401'); return r.json(); })
        .then(function (d) {
          if (run !== cap.run) return;
          if (d.status === 'expired' || d.status === 'cancelled') { capExpired(d.status === 'expired' ? null : 'Ce QR code n\'est plus actif. Générez-en un nouveau.'); return; }
          var fetches = [];
          d.steps.forEach(function (ds) {
            var s = cap.steps.filter(function (x) { return x.id === ds.id; })[0];
            if (!s) return;
            s.status = ds.status;
            // Photo reçue (ou reprise : nouvelle version) → récupérée et placée dans sa pièce
            if (ds.status === 'uploaded' && ds.version && ds.version !== s.version) { s.version = ds.version; fetches.push(capFetch(run, s)); }
          });
          // Pièce « en cours sur le téléphone » : seulement une fois le QR code flashé
          cap.next = d.status === 'opened' ? (cap.steps.filter(function (x) { return !stepDone(x) && x.status === 'pending'; })[0] || null) : null;
          if (!cap.steps.every(stepDone)) {
            var got = cap.steps.filter(stepDone).length;
            capState(d.status === 'opened'
              ? (got ? got + ' sur ' + cap.steps.length + ' reçue' + (got > 1 ? 's' : '') + ' · ' : 'Téléphone connecté · ') + (cap.next ? 'photo « ' + cap.next.label + ' » en cours…' : 'réception…')
              : 'En attente du téléphone…', d.status === 'opened' ? 'live' : 'wait');
          }
          capRenderSteps();
          Promise.all(fetches).then(function () { if (run === cap.run) capPoll(run); });
        })
        .catch(function (e) { if (run !== cap.run) return; if (e.message === '401') capExpired('Session expirée : reconnectez-vous.'); else capPoll(run); });
    }, 1500);
  }

  /** Photo reçue : téléchargée (une fois par version), puis placée dans sa pièce comme un fichier choisi sur le poste. */
  function capFetch(run, s) {
    return fetch('vault/capture-api.php?action=file&id=' + s.id, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('http'); return r.blob(); })
      .then(function (blob) {
        if (run !== cap.run) return;
        var type = blob.type === 'image/png' ? 'image/png' : 'image/jpeg';
        var file = new File([blob], 'photo-mobile-' + s.slot.replace('doc_', '') + (type === 'image/png' ? '.png' : '.jpg'), { type: type, lastModified: Date.now() });
        if (!slots[s.slot].accept([file], 'mobile')) { capState('Photo « ' + s.label + ' » refusée : ' + slots[s.slot].error.textContent, 'error'); return; }
        if (s.thumb) URL.revokeObjectURL(s.thumb);
        s.thumb = URL.createObjectURL(file); s.received = true;
        capRenderSteps();
      })
      .catch(function () { if (run === cap.run) capState('La photo « ' + s.label + ' » n\'a pas pu être récupérée : reprenez-la sur le téléphone.', 'error'); });
  }

  /** Fermeture (validation ou annulation) : la session est close côté serveur (plus aucun envoi possible). */
  cap.dialog.addEventListener('close', function () {
    cap.run++;
    capStop();
    if (cap.id) {
      var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('id', cap.id);
      fetch('vault/capture-api.php?action=cancel', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
    }
    cap.id = null;
    cap.steps.forEach(function (s) { if (s.thumb) URL.revokeObjectURL(s.thumb); s.thumb = null; });
    if (cap.opener) cap.opener.focus();
  });
  cap.dialog.querySelectorAll('[data-capture-close]').forEach(function (b) { b.addEventListener('click', function () { cap.dialog.close(); }); });
  cap.dialog.querySelector('[data-capture-validate]').addEventListener('click', function () { cap.dialog.close(); });
  cap.dialog.addEventListener('click', function (e) { if (e.target === cap.dialog) cap.dialog.close(); });
  cap.dialog.querySelector('[data-capture-renew]').addEventListener('click', function () {
    // Nouveau QR code : uniquement pour les pièces pas encore reçues
    var left = cap.steps.filter(function (s) { return !stepDone(s); });
    captureOpen(left.length ? left[0].slot : cap.first);
  });
  cap.dialog.querySelector('[data-capture-copy]').addEventListener('click', function () {
    var b = this;
    (navigator.clipboard ? navigator.clipboard.writeText(cap.url) : Promise.reject()).then(function () {
      b.textContent = 'Lien copié'; setTimeout(function () { b.textContent = 'Copier le lien (WhatsApp…)'; }, 1800);
    }, function () { window.prompt('Lien de capture (valable 15 minutes) :', cap.url); });
  });

  applyType();
  var summary = document.getElementById('error-summary');
  if (summary) summary.focus();
})();
</script>

<?php dash_footer(); ?>
