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

/** Classes d'un champ (bordure rouge en cas d'erreur). */
function field_class(array $errors, string $name): string
{
    return 'block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 '
         . 'focus:outline-none focus:ring-4 transition '
         . (isset($errors[$name])
            ? 'border-red-400 focus:border-red-500 focus:ring-red-100'
            : 'border-slate-300 focus:border-blue-600 focus:ring-blue-100');
}

/** Zone de dépôt d'un fichier. */
function dropzone(string $slot, string $label, string $hint, array $errors, string $extra = ''): string
{
    $err = $errors[$slot] ?? '';
    ob_start(); ?>
    <div class="kyc-slot" data-slot="<?= h($slot) ?>" <?= $extra ?>>
        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
            <span class="text-sm font-medium text-slate-800" id="<?= h($slot) ?>-label"><?= h($label) ?></span>
            <span class="flex items-center gap-2">
                <!-- Mode « Appareil photo mobile » : QR code à flasher (capture-api.php → capture.php) -->
                <button type="button" data-mobile aria-describedby="<?= h($slot) ?>-label" title="Photographier cette pièce avec un téléphone"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200 transition hover:bg-indigo-50 focus:outline-none focus:ring-4 focus:ring-indigo-100">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg> Scanner avec un mobile
                </button>
                <span class="text-xs font-medium" data-badge></span>
            </span>
        </div>
        <label for="<?= h($slot) ?>" data-drop
               class="group relative flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-6 text-center transition
                      focus-within:ring-4 focus-within:ring-blue-100
                      <?= $err ? 'border-red-300 bg-red-50/60' : 'border-slate-300 bg-slate-50 hover:border-blue-400 hover:bg-blue-50/50' ?>">
            <input type="file" id="<?= h($slot) ?>" name="<?= h($slot) ?>" class="sr-only"
                   accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                   aria-labelledby="<?= h($slot) ?>-label" aria-describedby="<?= h($slot) ?>-hint <?= h($slot) ?>-error">
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-white text-blue-600 shadow-sm ring-1 ring-slate-200 group-hover:ring-blue-200">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m0 0-4 4m4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
            </span>
            <span class="text-sm text-slate-700"><span class="font-semibold text-blue-700">Choisir un fichier</span> ou glissez-le ici</span>
            <span class="text-xs text-slate-500" id="<?= h($slot) ?>-hint"><?= h($hint) ?></span>
        </label>
        <div data-preview hidden class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div data-thumb class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200"></div>
            <div class="min-w-0 flex-1">
                <p data-name class="truncate text-sm font-medium text-slate-900"></p>
                <p data-meta class="text-xs text-slate-500"></p>
            </div>
            <button type="button" data-replace class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-50 focus:outline-none focus:ring-4 focus:ring-blue-100">Remplacer</button>
            <button type="button" data-remove aria-label="Retirer le fichier" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-4 focus:ring-red-100">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <p class="mt-1.5 text-xs text-red-600" id="<?= h($slot) ?>-error" data-error role="alert"><?= h($err) ?></p>
    </div>
    <?php
    return (string) ob_get_clean();
}

$errField = fn (string $name) => isset($errors[$name])
    ? '<p class="mt-1.5 text-xs text-red-600" id="' . h($name) . '-error" data-error-for="' . h($name) . '">' . h($errors[$name]) . '</p>'
    : '<p class="mt-1.5 hidden text-xs text-red-600" id="' . h($name) . '-error" data-error-for="' . h($name) . '"></p>';
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Nouveau dossier KYC · GTC-Vault</title>
    <!-- Tailwind compilé (assets/vault.css, voir assets/README-tailwind.txt) : aucun script externe -->
    <link rel="stylesheet" href="<?= h(vault_css()) ?>">
    <!-- L'attribut hidden doit l'emporter sur les classes d'affichage (flex, grid…) -->
    <style>[hidden] { display: none !important; }</style>
</head>
<body class="min-h-full bg-slate-100 font-sans text-slate-900 antialiased">

<!-- ==================== BARRE SUPÉRIEURE ==================== -->
<?= vault_topbar([['Clients', 'clients.php'], ['Nouveau dossier KYC', null]]) ?>

<main class="mx-auto max-w-6xl px-4 pb-16 pt-8 sm:px-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Nouveau dossier KYC</h1>
        <p class="mt-1 text-sm text-slate-600">Identité du client et pièces justificatives. Les champs marqués <span class="text-red-600">*</span> sont obligatoires.</p>
    </div>

    <?php if ($flash && isset($_GET['ok'])): ?>
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
            <div>
                <p class="font-semibold">Dossier de <?= h($flash['name']) ?> enregistré</p>
                <p class="mt-0.5"><?= (int) $flash['docs'] ?> pièce<?= $flash['docs'] > 1 ? 's' : '' ?> archivée<?= $flash['docs'] > 1 ? 's' : '' ?> ·
                    <a class="font-medium underline underline-offset-2" href="client.php?uuid=<?= h($flash['uuid']) ?>">Ouvrir la fiche</a>
                    <?php if (!empty($flash['photo'])): ?>
                        · <a class="font-medium underline underline-offset-2" href="client.php?uuid=<?= h($flash['uuid']) ?>#photo" data-flash-photo>Définir la photo de profil</a>
                    <?php endif; ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert" id="error-summary" tabindex="-1">
            <p class="font-semibold"><?= h($errors['_form'] ?? 'Le dossier n\'a pas été enregistré : corrigez les points signalés.') ?></p>
            <?php if ($hasFileErrors || !isset($errors['_form'])): ?>
                <p class="mt-1 text-red-800">Pour votre sécurité, les fichiers ne sont pas conservés après une erreur : sélectionnez-les à nouveau.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate id="kyc-form" class="grid gap-6 lg:grid-cols-3"
          data-max-bytes="<?= KYC_MAX_BYTES ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= KYC_MAX_BYTES ?>">

        <div class="space-y-6 lg:col-span-2">
            <!-- ==================== 1. INFORMATIONS CLIENT ==================== -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="s-client">
                <h2 id="s-client" class="flex items-center gap-2 text-base font-semibold text-slate-900">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">1</span>
                    Informations client
                </h2>

                <fieldset class="mt-5">
                    <legend class="mb-2 text-sm font-medium text-slate-800">Type de client <span class="text-red-600">*</span></legend>
                    <div class="grid grid-cols-2 gap-3">
                        <?php foreach (['particulier' => ['Particulier', 'Personne physique', 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9a7 7 0 0 1 14 0'],
                                        'entreprise'  => ['Entreprise', 'Personne morale', 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M9 10h.01M15 10h.01']] as $val => [$t, $sub, $path]): ?>
                            <label class="relative cursor-pointer">
                                <input type="radio" name="type_client" value="<?= $val ?>" class="peer sr-only" <?= ($old['type_client'] ?? '') === $val ? 'checked' : '' ?>>
                                <span class="flex items-center gap-3 rounded-xl border border-slate-300 bg-white p-3.5 transition
                                             hover:border-slate-400 peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:ring-2 peer-checked:ring-blue-600/20
                                             peer-focus-visible:ring-4 peer-focus-visible:ring-blue-100">
                                    <span class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 sm:flex">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $path ?>"/></svg>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-slate-900"><?= $t ?></span>
                                        <span class="block text-xs text-slate-500"><?= $sub ?></span>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?= $errField('type_client') ?>
                </fieldset>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="nom_complet" class="mb-1.5 block text-sm font-medium text-slate-800">
                            <span data-company-text="Raison sociale" data-person-text="Nom complet"><?= $isCompany ? 'Raison sociale' : 'Nom complet' ?></span> <span class="text-red-600">*</span>
                        </label>
                        <input type="text" id="nom_complet" name="nom_complet" required minlength="2" maxlength="255" autocomplete="off"
                               value="<?= h($old['nom_complet'] ?? '') ?>" class="<?= field_class($errors, 'nom_complet') ?>"
                               data-company-placeholder="Ex. : GLOBAL TRADING CORPORATION SARL" data-person-placeholder="Ex. : Jean-Paul Mballa Ngono"
                               placeholder="<?= $isCompany ? 'Ex. : GLOBAL TRADING CORPORATION SARL' : 'Ex. : Jean-Paul Mballa Ngono' ?>"
                               aria-describedby="nom_complet-error">
                        <?= $errField('nom_complet') ?>
                    </div>

                    <div>
                        <label for="telephone" class="mb-1.5 block text-sm font-medium text-slate-800">Téléphone <span class="text-red-600">*</span></label>
                        <div class="flex rounded-lg shadow-sm">
                            <label for="indicatif" class="sr-only">Indicatif du pays</label>
                            <select id="indicatif" name="indicatif"
                                    class="w-[7.5rem] shrink-0 rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 py-2.5 pl-3 pr-7 text-sm font-medium text-slate-700 focus:z-10 focus:border-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-100">
                                <?php foreach (KYC_DIAL_CODES as $code => [$iso, $country]): ?>
                                    <option value="<?= h($code) ?>" title="<?= h($country) ?>" <?= ($old['indicatif'] ?? '') === $code ? 'selected' : '' ?>><?= h($code) ?> <?= h($iso) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="tel" id="telephone" name="telephone" required inputmode="tel" autocomplete="off" maxlength="20"
                                   value="<?= h($old['telephone'] ?? '') ?>" placeholder="6 70 00 00 00"
                                   class="<?= str_replace(['rounded-lg', 'shadow-sm'], ['rounded-l-none rounded-r-lg', ''], field_class($errors, 'telephone')) ?> min-w-0 flex-1"
                                   aria-describedby="telephone-error">
                        </div>
                        <?= $errField('telephone') ?>
                    </div>

                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-medium text-slate-800">E-mail <span class="font-normal text-slate-500">(facultatif)</span></label>
                        <input type="email" id="email" name="email" maxlength="150" autocomplete="off" inputmode="email"
                               value="<?= h($old['email'] ?? '') ?>" placeholder="nom@exemple.com" class="<?= field_class($errors, 'email') ?>"
                               aria-describedby="email-error">
                        <?= $errField('email') ?>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="numero_identifiant" class="mb-1.5 block text-sm font-medium text-slate-800">
                            <span data-company-text="N° RCCM" data-person-text="N° CNI ou passeport"><?= $isCompany ? 'N° RCCM' : 'N° CNI ou passeport' ?></span> <span class="text-red-600">*</span>
                        </label>
                        <input type="text" id="numero_identifiant" name="numero_identifiant" required maxlength="100" autocomplete="off"
                               value="<?= h($old['numero_identifiant'] ?? '') ?>" class="<?= field_class($errors, 'numero_identifiant') ?> uppercase placeholder:normal-case"
                               data-company-placeholder="Ex. : RC/DLA/2019/B/2990" data-person-placeholder="Ex. : 112233445"
                               placeholder="<?= $isCompany ? 'Ex. : RC/DLA/2019/B/2990' : 'Ex. : 112233445' ?>"
                               aria-describedby="numero_identifiant-error">
                        <?= $errField('numero_identifiant') ?>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="adresse" class="mb-1.5 block text-sm font-medium text-slate-800">Adresse <span class="font-normal text-slate-500">(facultatif)</span></label>
                        <textarea id="adresse" name="adresse" rows="3" maxlength="2000"
                                  placeholder="Quartier, rue, ville, pays" class="<?= field_class($errors, 'adresse') ?> resize-y"
                                  aria-describedby="adresse-error"><?= h($old['adresse'] ?? '') ?></textarea>
                        <?= $errField('adresse') ?>
                    </div>
                </div>
            </section>

            <!-- ==================== 2. PIÈCES JUSTIFICATIVES ==================== -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="s-docs">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 id="s-docs" class="flex items-center gap-2 text-base font-semibold text-slate-900">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">2</span>
                        Pièces justificatives
                    </h2>
                    <p class="text-xs text-slate-500">JPG, PNG ou PDF · 5 Mo maximum par fichier</p>
                </div>

                <!-- Deux modes de capture -->
                <div class="mt-4 grid gap-2.5 sm:grid-cols-2">
                    <div class="flex items-start gap-3 rounded-xl bg-slate-50 p-3 ring-1 ring-inset ring-slate-200">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-blue-600 ring-1 ring-slate-200" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">Fichier / Scanner de bureau
                                <span class="ml-1 inline-block whitespace-nowrap rounded bg-blue-100 px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-blue-700">par défaut</span></p>
                            <p class="mt-0.5 text-xs text-slate-500">Glissez ou choisissez un PDF, JPG ou PNG (scanner réseau, PC).</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 rounded-xl bg-indigo-50/70 p-3 ring-1 ring-inset ring-indigo-200">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-indigo-600 ring-1 ring-indigo-200" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">Appareil photo mobile</p>
                            <p class="mt-0.5 text-xs text-slate-600">« Scanner avec un mobile » sur une pièce, puis flashez le QR code : la photo arrive ici en direct.</p>
                        </div>
                    </div>
                </div>

                <fieldset class="mt-5">
                    <legend class="mb-2 text-sm font-medium text-slate-800">
                        <span data-company-text="Pièce d'identité du représentant légal" data-person-text="Pièce d'identité"><?= $isCompany ? 'Pièce d\'identité du représentant légal' : 'Pièce d\'identité' ?></span>
                    </legend>
                    <div class="inline-flex rounded-lg bg-slate-100 p-1" role="radiogroup">
                        <?php foreach (['cni' => 'Carte nationale d\'identité', 'passeport' => 'Passeport'] as $val => $t): ?>
                            <label class="cursor-pointer">
                                <input type="radio" name="piece_identite" value="<?= $val ?>" class="peer sr-only" <?= ($old['piece_identite'] ?? 'cni') === $val ? 'checked' : '' ?>>
                                <span class="block rounded-md px-3.5 py-1.5 text-sm font-medium text-slate-600 transition peer-checked:bg-white peer-checked:text-slate-900 peer-checked:shadow-sm peer-focus-visible:ring-4 peer-focus-visible:ring-blue-100"><?= $t ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <?= dropzone('doc_identite', 'CNI — recto', 'Face avec la photo, bien lisible', $errors,
                        'data-label-cni="CNI — recto" data-label-passeport="Passeport — page photo"') ?>
                    <?= dropzone('doc_verso', 'CNI — verso', 'Face arrière, bien lisible', $errors, 'data-for="particulier-cni"') ?>
                    <?= dropzone('doc_rccm', 'Registre du commerce (RCCM)', 'Extrait RCCM en cours de validité', $errors, 'data-for="entreprise"') ?>
                    <?= dropzone('doc_statuts', 'Statuts de l\'entreprise', 'Facultatif · statuts signés', $errors, 'data-for="entreprise"') ?>
                </div>
            </section>
        </div>

        <!-- ==================== RÉCAPITULATIF ET ENVOI ==================== -->
        <aside class="lg:col-span-1">
            <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:sticky lg:top-6">
                <h2 class="text-base font-semibold text-slate-900">Récapitulatif</h2>
                <ul class="space-y-2.5 text-sm" id="kyc-checklist" aria-live="polite"></ul>
                <div class="flex items-center justify-between border-t border-slate-200 pt-3 text-xs text-slate-500">
                    <span>Fichiers sélectionnés</span>
                    <span id="kyc-total" class="font-medium text-slate-700">0 · 0 Ko</span>
                </div>
                <button type="submit" id="kyc-submit"
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3.5 text-base font-semibold text-white shadow-lg shadow-blue-600/25 transition
                               hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-200 disabled:cursor-wait disabled:opacity-70">
                    <svg class="h-5 w-5" data-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.4 8.3 8 9 4.6-.7 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
                    <svg class="hidden h-5 w-5 animate-spin" data-spinner viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    <span data-label>Enregistrer le dossier KYC</span>
                </button>
                <p class="flex items-start gap-2 text-xs leading-relaxed text-slate-500">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    Les pièces sont conservées dans un espace privé, accessible uniquement aux agents habilités.
                </p>
            </div>
        </aside>
    </form>
</main>

<!-- ==================== CAPTURE PAR TÉLÉPHONE : UN QR CODE POUR TOUTES LES PIÈCES ==================== -->
<dialog id="capture-dialog" aria-labelledby="capture-title"
        class="w-[min(520px,94vw)] rounded-2xl p-0 text-slate-900 shadow-2xl backdrop:bg-slate-900/70 backdrop:backdrop-blur-sm">
    <div class="p-5 sm:p-6">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="capture-title" class="text-base font-semibold">Scanner avec un mobile</h2>
                <p class="text-sm text-slate-600" id="capture-sub">Un seul QR code pour toutes les pièces, à la suite.</p>
            </div>
            <button type="button" data-capture-close class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-4 focus:ring-slate-200" aria-label="Fermer">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- QR code et marche à suivre (masqués une fois toutes les pièces reçues) -->
        <div id="capture-scan" class="mt-5 grid items-center gap-5 sm:grid-cols-[13rem_minmax(0,1fr)]">
            <div id="capture-qr" role="img" aria-label="QR code de capture"
                 class="mx-auto flex h-52 w-52 items-center justify-center rounded-2xl bg-white p-2 ring-1 ring-slate-200 transition-opacity">
                <svg class="h-8 w-8 animate-spin text-indigo-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            </div>
            <ol class="space-y-2 text-sm text-slate-700">
                <li class="flex gap-2.5"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">1</span><span>Flashez ce QR code avec l'appareil photo du téléphone.</span></li>
                <li class="flex gap-2.5"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">2</span><span>Photographiez chaque pièce : le téléphone passe seul à la suivante.</span></li>
                <li class="flex gap-2.5"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">3</span><span>Vérifiez les photos ici, puis validez.</span></li>
            </ol>
        </div>

        <!-- Pièces de la session : état et photo reçue -->
        <ul id="capture-steps" class="mt-5 grid gap-3" aria-live="polite"></ul>

        <div id="capture-status" role="status" aria-live="polite" class="mt-5 flex items-center gap-2.5 rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm ring-1 ring-inset ring-slate-200">
            <span data-dot class="h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-amber-400"></span>
            <span data-text class="min-w-0 flex-1">Préparation du QR code…</span>
            <span data-timer class="shrink-0 text-xs tabular-nums text-slate-500"></span>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <button type="button" data-capture-copy hidden class="text-xs font-medium text-slate-600 underline underline-offset-4 hover:text-slate-900">Copier le lien (WhatsApp…)</button>
            <span class="ml-auto flex gap-2">
                <button type="button" data-capture-renew hidden class="rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200">Nouveau QR code</button>
                <button type="button" data-capture-close data-cancel-label class="rounded-lg px-3.5 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-slate-200">Annuler</button>
                <button type="button" data-capture-validate hidden class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-200">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>
                    Photos correctes, valider
                </button>
            </span>
        </div>
        <p class="mt-4 text-[11px] leading-relaxed text-slate-500">QR code personnel, valable 15 minutes pour les pièces de ce dossier. Les photos sont transmises au dossier sécurisé puis effacées du serveur de transit.</p>
    </div>
</dialog>

<script src="assets/qrcode/qrcode.min.js"></script>
<script>
(function () {
  'use strict';

  var form = document.getElementById('kyc-form');
  var MAX = parseInt(form.getAttribute('data-max-bytes'), 10);
  var TYPES = { 'image/jpeg': 'JPG', 'image/png': 'PNG', 'application/pdf': 'PDF' };
  var EXT = /\.(jpe?g|png|pdf)$/i;
  var slots = {};

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
    numero_identifiant: function (v) { return /^[A-Z0-9][A-Z0-9 .\/\-]{2,99}$/i.test(v.trim()) ? '' : 'Numéro invalide : lettres, chiffres, espaces, « / », « - » ou « . » (3 caractères minimum).'; }
  };

  function setFieldError(name, msg) {
    var input = form.elements[name], box = form.querySelector('[data-error-for="' + name + '"]');
    if (!input || !box) return;
    box.textContent = msg; box.classList.toggle('hidden', !msg);
    input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    input.classList.toggle('border-red-400', !!msg); input.classList.toggle('focus:ring-red-100', !!msg);
    input.classList.toggle('border-slate-300', !msg); input.classList.toggle('focus:ring-blue-100', !msg);
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

    function setError(msg) {
      s.error.textContent = msg;
      s.drop.classList.toggle('border-red-300', !!msg); s.drop.classList.toggle('bg-red-50/60', !!msg);
      s.drop.classList.toggle('border-slate-300', !msg); s.drop.classList.toggle('bg-slate-50', !msg);
    }
    s.setError = setError;

    function clear() {
      s.input.value = '';
      if (s.url) { URL.revokeObjectURL(s.url); s.url = null; }
      s.preview.hidden = true; s.drop.hidden = false; s.thumb.innerHTML = '';
      renderChecklist();
    }
    s.clear = clear;

    function show(file, source) {
      if (s.url) URL.revokeObjectURL(s.url);
      s.thumb.innerHTML = '';
      if (/^image\//.test(file.type)) {
        s.url = URL.createObjectURL(file);
        var img = new Image(); img.src = s.url; img.alt = ''; img.className = 'h-full w-full object-cover';
        s.thumb.appendChild(img);
      } else {
        s.thumb.innerHTML = '<span class="flex h-full w-full flex-col items-center justify-center bg-red-50 text-red-600"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5"/></svg><span class="text-[10px] font-bold">PDF</span></span>';
      }
      s.name.textContent = file.name; s.name.title = file.name;
      s.meta.textContent = (TYPES[file.type] || 'Fichier') + ' · ' + fmtSize(file.size);
      // Photo reçue d'un téléphone : mention avec icône (texte fixe, aucune donnée saisie)
      if (source === 'mobile') s.meta.insertAdjacentHTML('afterbegin', '<span class="inline-flex items-center gap-1 font-medium text-indigo-700"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg>Photo prise au mobile</span> · ');
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
    ['dragenter', 'dragover'].forEach(function (t) {
      s.drop.addEventListener(t, function (e) { e.preventDefault(); s.drop.classList.add('border-blue-500', 'bg-blue-50', 'ring-4', 'ring-blue-100'); });
    });
    ['dragleave', 'dragend', 'drop'].forEach(function (t) {
      s.drop.addEventListener(t, function () { s.drop.classList.remove('border-blue-500', 'bg-blue-50', 'ring-4', 'ring-blue-100'); });
    });
    s.drop.addEventListener('drop', function (e) { e.preventDefault(); accept(e.dataTransfer.files); });
    el.querySelector('[data-remove]').addEventListener('click', function () { clear(); s.drop.querySelector('input').focus(); });
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
      s.badge.className = 'text-xs font-medium ' + (required ? 'text-red-600' : 'text-slate-400');
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
      var ok = it[0];
      return '<li class="flex items-center gap-2.5 ' + (ok ? 'text-slate-900' : 'text-slate-500') + '">'
        + (ok ? '<span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"><svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg></span>'
              : '<span class="h-5 w-5 shrink-0 rounded-full border-2 ' + (it[2] ? 'border-slate-300' : 'border-dashed border-slate-300') + '"></span>')
        + '<span class="min-w-0 flex-1 truncate">' + it[1].replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }) + '</span>'
        + (it[2] ? '' : '<span class="text-xs text-slate-400">facultatif</span>')
        + '<span class="sr-only">' + (ok ? ' : fait' : ' : à compléter') + '</span></li>';
    }).join('');
    document.getElementById('kyc-total').textContent = count + ' · ' + (bytes ? fmtSize(bytes) : '0 Ko');
  }

  // ---------- Envoi ----------
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
    var btn = document.getElementById('kyc-submit');
    btn.disabled = true;                                  // pas de double envoi
    btn.querySelector('[data-icon]').classList.add('hidden');
    btn.querySelector('[data-spinner]').classList.remove('hidden');
    btn.querySelector('[data-label]').textContent = 'Enregistrement en cours…';
  });
  // Retour arrière vers la page : bouton réactivé
  window.addEventListener('pageshow', function () {
    var btn = document.getElementById('kyc-submit');
    btn.disabled = false; btn.querySelector('[data-icon]').classList.remove('hidden');
    btn.querySelector('[data-spinner]').classList.add('hidden'); btn.querySelector('[data-label]').textContent = 'Enregistrer le dossier KYC';
  });

  // ---------- Mode « Appareil photo mobile » : UN QR code → le téléphone photographie toutes les pièces à la suite ----------
  // Pièces de la session : celle cliquée d'abord, puis les autres pièces visibles encore vides (ex. recto → verso).
  // Chaque photo reçue est placée aussitôt dans sa pièce ; une photo reprise sur le téléphone remplace la précédente.
  var cap = { dialog: document.getElementById('capture-dialog'), id: null, url: '', end: 0, poll: null, tick: null, run: 0, opener: null, steps: [], first: null };
  var capQr = document.getElementById('capture-qr'), capStatus = document.getElementById('capture-status'), capList = document.getElementById('capture-steps');
  var CSRF = form.elements.csrf_token.value;
  var ICON_OK = '<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';

  function capEsc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /** État affiché : tone = wait (orange), live (bleu), ok (vert), error (rouge). */
  function capState(text, tone) {
    var dot = capStatus.querySelector('[data-dot]');
    capStatus.querySelector('[data-text]').textContent = text;
    dot.className = 'h-2.5 w-2.5 shrink-0 rounded-full ' + ({ wait: 'animate-pulse bg-amber-400', live: 'animate-pulse bg-blue-500', ok: 'bg-emerald-500', error: 'bg-red-500' }[tone] || 'bg-slate-400');
    capStatus.className = 'mt-5 flex items-center gap-2.5 rounded-xl px-3.5 py-2.5 text-sm ring-1 ring-inset '
      + ({ ok: 'bg-emerald-50 text-emerald-900 ring-emerald-200', error: 'bg-red-50 text-red-900 ring-red-200' }[tone] || 'bg-slate-50 ring-slate-200');
  }
  function capStop() { clearTimeout(cap.poll); clearInterval(cap.tick); cap.poll = cap.tick = null; }
  function capExpired(msg) {
    capStop(); cap.id = null;
    capQr.classList.add('opacity-20');
    capStatus.querySelector('[data-timer]').textContent = '';
    capState(msg || 'QR code expiré. Générez-en un nouveau.', 'error');
    cap.dialog.querySelector('[data-capture-renew]').hidden = false;
  }
  var stepDone = function (s) { return s.received || s.status === 'skipped'; };

  /** Liste des pièces de la session : en attente / photo reçue (miniature) / passée. */
  function capRenderSteps() {
    var all = cap.steps.length && cap.steps.every(stepDone);
    capList.className = 'mt-5 grid gap-3' + (all && cap.steps.length > 1 ? ' sm:grid-cols-2' : '');
    capList.innerHTML = cap.steps.map(function (s, i) {
      var st = s.received ? '<span class="inline-flex items-center gap-1 text-emerald-700">' + ICON_OK + ' Reçue</span>'
        : s.status === 'skipped' ? '<span class="text-slate-500">Passée</span>'
        : (cap.next && cap.next.id === s.id ? '<span class="text-blue-700">En cours sur le téléphone…</span>' : '<span class="text-slate-500">En attente</span>');
      var thumb = s.thumb ? '<img src="' + s.thumb + '" alt="" class="' + (all ? 'h-28 w-full' : 'h-12 w-16') + ' rounded-lg bg-slate-100 object-cover ring-1 ring-slate-200">'
        : '<span class="flex ' + (all ? 'h-28 w-full' : 'h-12 w-16') + ' shrink-0 items-center justify-center rounded-lg bg-slate-100 text-sm font-semibold text-slate-400">' + (i + 1) + '</span>';
      return all
        ? '<li class="rounded-xl border border-slate-200 p-2.5">' + thumb + '<p class="mt-2 truncate text-sm font-semibold">' + capEsc(s.label) + '</p><p class="text-xs font-medium">' + st + '</p></li>'
        : '<li class="flex items-center gap-3 rounded-xl border border-slate-200 p-2.5">' + thumb
          + '<div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">' + capEsc(s.label) + (s.required ? '' : ' <span class="font-normal text-slate-400">(facultative)</span>') + '</p><p class="text-xs font-medium">' + st + '</p></div></li>';
    }).join('');
    // Toutes les pièces reçues : QR code masqué, vérification et validation
    cap.dialog.querySelector('#capture-scan').hidden = all;
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
    capQr.classList.remove('opacity-20');
    capQr.innerHTML = '<svg class="h-8 w-8 animate-spin text-indigo-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';
    cap.dialog.querySelector('[data-capture-renew]').hidden = true;
    cap.dialog.querySelector('[data-capture-copy]').hidden = true;
    capStatus.querySelector('[data-timer]').textContent = '';
    capState('Préparation du QR code…', 'wait');
    capRenderSteps();
    if (!cap.dialog.open) cap.dialog.showModal();

    var fd = new FormData();
    fd.append('csrf_token', CSRF); fd.append('slots', seq.join(',')); fd.append('piece', val('piece_identite'));
    fd.append('optional', seq.filter(function (k) { return r.required.indexOf(k) === -1; }).join(','));
    fetch('capture-api.php?action=create', { method: 'POST', body: fd, credentials: 'same-origin' })
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
      fetch('capture-api.php?action=status&id=' + cap.id, { cache: 'no-store', credentials: 'same-origin' })
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
    return fetch('capture-api.php?action=file&id=' + s.id, { cache: 'no-store', credentials: 'same-origin' })
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
      fetch('capture-api.php?action=cancel', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
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
</body>
</html>
