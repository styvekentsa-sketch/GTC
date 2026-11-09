<?php
/**
 * Passerelle GTC-Vault ↔ facturation (reçus de caisse, factures commerciales).
 *
 * - Recherche des dossiers KYC pour l'autocomplétion des formulaires (dashboard/clients-api.php,
 *   assets/js/kyc-link.js) : nom, téléphone, adresse, N° CNI / passeport / RCCM, état du dossier.
 * - Complétude d'un dossier (règle unique, reprise par la fiche client de GTC-Vault).
 * - Liaison d'un document financier à un dossier : colonnes vault_client_id + kyc_status (état du dossier au
 *   moment de l'émission) dans invoices / commercial_invoices, et trace dans audit_logs (action « finance_link »).
 *
 * Fichier autonome : il n'ouvre ni le stockage des pièces ni la session du coffre-fort (includes/vault.php),
 * les caissiers peuvent donc lier un client sans avoir accès aux pièces d'identité.
 */
declare(strict_types=1);

/** Rôles pouvant rechercher un dossier depuis la facturation : opérations (caisse, agents) et GTC-Vault. */
const KYC_LINK_ROLES = ['admin', 'caissier', 'agent', 'conformite'];

/** États enregistrés avec un document financier (kyc_status). */
const KYC_LINK_STATES = [
    'complete'   => ['label' => 'Dossier KYC complet',   'tone' => 'success', 'icon' => 'check'],
    'incomplete' => ['label' => 'Dossier KYC incomplet', 'tone' => 'warning', 'icon' => 'alert'],
];

/**
 * Pièces manquantes d'un dossier (liste vide = dossier complet).
 * Particulier : CNI recto + verso, ou passeport. Entreprise : RCCM + pièce d'identité du représentant légal.
 */
function kyc_missing(string $type, array $docTypes): array
{
    $has = fn (string $t) => in_array($t, $docTypes, true);
    $missing = [];
    if ($type === 'entreprise') {
        if (!$has('rccm')) {
            $missing[] = 'Registre du commerce (RCCM)';
        }
        if (!$has('cni_recto') && !$has('passeport')) {
            $missing[] = 'Pièce d\'identité du représentant légal';
        }
    } elseif (!$has('passeport')) {
        if (!$has('cni_recto')) {
            $missing[] = $has('cni_verso') ? 'CNI — recto' : 'CNI (recto et verso) ou passeport';
        }
        if ($has('cni_recto') && !$has('cni_verso')) {
            $missing[] = 'CNI — verso';
        }
    }
    return $missing;
}

/** Types de pièces versées, par client : [client_id => ['cni_recto', …]]. */
function kyc_doc_types(array $clientIds): array
{
    $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
    if (!$clientIds) {
        return [];
    }
    $map = array_fill_keys($clientIds, []);
    $in  = implode(', ', array_fill(0, count($clientIds), '?'));
    foreach (db_all("SELECT client_id, type_document FROM client_documents WHERE client_id IN ({$in})", $clientIds) as $r) {
        $map[(int) $r['client_id']][] = $r['type_document'];
    }
    return $map;
}

/** Motif d'une adresse e-mail (repérage dans un texte libre). */
const KYC_EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu';

/**
 * Adresse physique seule, sur une ligne : toute adresse e-mail est retirée (saisie ou remplissage automatique du
 * navigateur qui l'aurait glissée dans le champ « Adresse »), les lignes sont jointes par « , ».
 *   « styvekentsa@gmail.com\nNdogbong » → « Ndogbong » ; « Rue Joss\nBonanjo, Douala » → « Rue Joss, Bonanjo, Douala »
 */
function kyc_clean_address(?string $adresse): string
{
    $text = (string) preg_replace(KYC_EMAIL_PATTERN, '', (string) $adresse);
    $lines = array_filter(array_map(fn ($l) => trim($l, " \t,;-"), preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== '');
    return trim((string) preg_replace('/\s{2,}/u', ' ', implode(', ', $lines)), ' ,;');
}

/** Libellé du numéro d'identification selon le type de client. */
function kyc_id_label(string $type): string
{
    return $type === 'entreprise' ? 'N° RCCM' : 'N° CNI / passeport';
}

/** Dossier présenté à la facturation (aucune pièce, aucun chemin de fichier : seulement l'identité et l'état). */
function kyc_client_public(array $c, array $docTypes): array
{
    $missing = kyc_missing((string) $c['type_client'], $docTypes);
    return [
        'uuid'      => (string) $c['uuid'],
        'name'      => (string) $c['nom_complet'],
        'type'      => (string) $c['type_client'],
        'typeLabel' => $c['type_client'] === 'entreprise' ? 'Entreprise' : 'Particulier',
        'phone'     => (string) $c['telephone'],
        'email'     => (string) ($c['email'] ?? ''),
        'address'   => kyc_clean_address($c['adresse'] ?? ''),   // colonne « adresse » seule, jamais l'e-mail
        'idNumber'  => (string) ($c['numero_identifiant'] ?? ''),
        'idLabel'   => kyc_id_label((string) $c['type_client']),
        'status'    => $missing ? 'incomplete' : 'complete',
        'missing'   => $missing,
    ];
}

/**
 * Recherche de dossiers (autocomplétion) : chaque mot doit apparaître dans le nom, le téléphone ou le N° de pièce.
 * Les chiffres d'un numéro de téléphone sont aussi cherchés sans espaces (« 677 88 » trouve « +237677889900 »).
 */
function kyc_client_search(string $q, int $limit = 8): array
{
    $words = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], fn ($w) => $w !== '')), 0, 5);
    if (!$words) {
        return [];
    }
    $esc = fn (string $s) => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    $where = [];
    $params = [];
    foreach ($words as $w) {
        $like = '%' . $esc($w) . '%';
        $or = ["nom_complet LIKE ? ESCAPE '!'", "numero_identifiant LIKE ? ESCAPE '!'", "telephone LIKE ? ESCAPE '!'"];
        array_push($params, $like, $like, $like);
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    // Téléphone saisi en morceaux (« 677 88 99 ») : les chiffres seuls sont comparés au numéro sans espaces
    $digits = preg_replace('/\D+/', '', $q);
    $phoneSql = '';
    if (strlen($digits) >= 4) {
        $phoneSql = " OR REPLACE(REPLACE(REPLACE(telephone, ' ', ''), '-', ''), '.', '') LIKE ?";
        $params[] = '%' . $digits . '%';
    }
    $rows = db_all('SELECT id, uuid, type_client, nom_complet, telephone, email, adresse, numero_identifiant FROM clients
                    WHERE archived_at IS NULL AND ((' . implode(' AND ', $where) . ')' . $phoneSql . ')   -- dossiers archivés exclus de la facturation
                    ORDER BY nom_complet LIMIT ' . max(1, min(20, $limit)), $params);
    $docs = kyc_doc_types(array_column($rows, 'id'));
    return array_map(fn ($r) => kyc_client_public($r, $docs[(int) $r['id']] ?? []), $rows);
}

/** Dossier par son identifiant public (uuid), avec son état ; null s'il n'existe pas (ou plus). */
function kyc_client_by_uuid(string $uuid): ?array
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
        return null;
    }
    $c = db_one('SELECT id, uuid, type_client, nom_complet, telephone, email, adresse, numero_identifiant FROM clients WHERE uuid = ?', [$uuid]);
    if (!$c) {
        return null;
    }
    return ['id' => (int) $c['id']] + kyc_client_public($c, kyc_doc_types([(int) $c['id']])[(int) $c['id']] ?? []);
}

/** Dossiers liés, par identifiant interne : [id => dossier public + id] (pages de liste, une seule requête). */
function kyc_clients_by_ids(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $in = implode(', ', array_fill(0, count($ids), '?'));
    $rows = db_all("SELECT id, uuid, type_client, nom_complet, telephone, email, adresse, numero_identifiant FROM clients WHERE id IN ({$in})", $ids);
    $docs = kyc_doc_types($ids);
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = ['id' => (int) $r['id']] + kyc_client_public($r, $docs[(int) $r['id']] ?? []);
    }
    return $out;
}

/**
 * Dossier choisi dans un formulaire (champ caché vault_client) relu côté serveur : l'identité et l'état du dossier
 * ne sont jamais repris du navigateur. Retourne [dossier|null, message d'erreur|null].
 */
function kyc_link_from_post(): array
{
    $uuid = trim((string) ($_POST['vault_client'] ?? ''));
    if ($uuid === '') {
        return [null, null];
    }
    $client = kyc_client_by_uuid(strtolower($uuid));
    return $client ? [$client, null] : [null, 'Le dossier GTC-Vault choisi n\'existe plus : recherchez à nouveau le client.'];
}

/**
 * Trace croisée dans le journal de sécurité de GTC-Vault : quel document financier a été émis pour ce dossier,
 * par qui, et dans quel état était le dossier KYC à ce moment-là. N'interrompt jamais l'enregistrement.
 */
function kyc_link_audit(array $client, string $docLabel, string $number, string $summary, array $user, string $verb = 'émis'): void
{
    $state = KYC_LINK_STATES[$client['status']]['label'] ?? $client['status'];
    $details = $docLabel . ' ' . $number . ' ' . $verb . ' par ' . $user['name'] . ($summary !== '' ? ' (' . $summary . ')' : '')
        . ' — ' . mb_strtolower($state) . ($client['missing'] ? ' : manque ' . implode(', ', $client['missing']) : '');
    try {
        db()->prepare('INSERT INTO audit_logs (created_at, user_id, user_name, action, client_uuid, client_name, document_id, details, ip, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                date('Y-m-d H:i:s'), (int) $user['id'] ?: null, (string) $user['name'], 'finance_link',
                $client['uuid'], $client['name'], null, mb_substr($details, 0, 500), client_ip(),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
    } catch (Throwable $e) {
        error_log('[GTC-Vault finance_link] ' . $details . ' — ' . $e->getMessage());
    }
}

/** Le compte connecté peut-il ouvrir la fiche GTC-Vault (pièces d'identité) ? */
function kyc_can_open_vault(): bool
{
    return in_array(current_user()['role'] ?? '', VAULT_ROLES, true) && vault_url() !== null;
}

/** Badge « Dossier KYC complet / incomplet » ($missing en info-bulle). $compact : libellé court (listes). */
function kyc_badge(string $status, array $missing = [], bool $compact = false): string
{
    $s = KYC_LINK_STATES[$status] ?? null;
    if (!$s) {
        return '';
    }
    $label = $compact ? ($status === 'complete' ? 'KYC complet' : 'KYC incomplet') : $s['label'];
    $title = $missing ? 'Manque : ' . implode(', ', $missing) : 'Pièces d\'identité vérifiées dans GTC-Vault';
    return '<span class="badge badge--' . e($s['tone']) . ' kyc-badge" data-kyc-badge="' . e($status) . '" title="' . e($title) . '">'
        . icon($s['icon']) . ' ' . e($label) . '</span>';
}

/**
 * Bloc « Dossier GTC-Vault » des pages de détail : badge de l'état actuel, rappel de l'état à l'émission s'il a
 * changé depuis, lien vers la fiche pour les rôles du coffre-fort.
 */
function kyc_link_summary(?array $client, ?string $statusAtIssue): string
{
    if (!$client) {
        return $statusAtIssue
            ? '<span class="cell-sub">Dossier GTC-Vault supprimé depuis l\'émission</span>'
            : '<span class="cell-sub">Aucun dossier GTC-Vault lié</span>';
    }
    $html = '<span class="kyc-summary">' . kyc_badge($client['status'], $client['missing']);
    if (kyc_can_open_vault()) {
        $html .= ' <a class="link-strong" href="vault/client.php?uuid=' . e($client['uuid']) . '">' . e($client['name']) . '</a>';
    } else {
        $html .= ' <span>' . e($client['name']) . '</span>';
    }
    if ($statusAtIssue && $statusAtIssue !== $client['status']) {
        $html .= '<small class="cell-sub">À l\'émission : ' . e(mb_strtolower(KYC_LINK_STATES[$statusAtIssue]['label'] ?? $statusAtIssue)) . '</small>';
    }
    if ($client['missing']) {
        $html .= '<small class="cell-sub">Manque : ' . e(implode(', ', $client['missing'])) . '</small>';
    }
    return $html . '</span>';
}

/**
 * Champ de recherche « Client GTC-Vault » des formulaires (assets/js/kyc-link.js).
 * $linked : dossier déjà lié (modification, ou nouvel affichage après une erreur) ; les champs à remplir sont
 * désignés par leur id dans data-kyc-fill (name, phone, email, address, idNumber).
 */
function kyc_link_field(?array $linked, array $fill, ?string $error = null): string
{
    ob_start(); ?>
    <div class="field field--full kyc-link" data-kyc-link data-api="clients-api.php"
         data-fill="<?= e(json_encode($fill)) ?>"<?= kyc_can_open_vault() ? ' data-vault="1"' : '' ?>>
        <label for="kyc-search">Client GTC-Vault <small>(recherche par nom, téléphone ou N° de pièce)</small></label>
        <input type="hidden" name="vault_client" value="<?= e($linked['uuid'] ?? '') ?>" data-kyc-uuid>
        <div class="kyc-link__box"<?= $linked ? ' hidden' : '' ?> data-kyc-box>
            <span class="kyc-link__icon" aria-hidden="true"><?= icon('search') ?></span>
            <input id="kyc-search" type="search" autocomplete="off" spellcheck="false" maxlength="80"
                   placeholder="Rechercher un client enregistré dans GTC-Vault…"
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="kyc-results" data-kyc-search>
            <ul class="kyc-link__results" id="kyc-results" role="listbox" hidden data-kyc-results></ul>
        </div>
        <div class="kyc-link__chosen"<?= $linked ? '' : ' hidden' ?> data-kyc-chosen>
            <?php if ($linked): ?>
                <?= kyc_link_chip($linked) ?>
            <?php endif; ?>
        </div>
        <small class="field__hint" data-kyc-hint>Le client choisi remplit les champs ci-dessous et le document est rattaché à son dossier KYC.</small>
        <?php if ($error): ?><small class="field__error"><?= e($error) ?></small><?php endif; ?>
        <noscript><small class="field__hint">La recherche GTC-Vault nécessite JavaScript ; les champs restent saisissables à la main.</small></noscript>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Carte du client lié (rendu serveur ; même structure que celle produite par kyc-link.js). */
function kyc_link_chip(array $c): string
{
    $sub = array_filter([$c['typeLabel'], $c['phone'], $c['idNumber'] !== '' ? $c['idLabel'] . ' ' . $c['idNumber'] : '']);
    return '<div class="kyc-chip"><span class="kyc-chip__icon" aria-hidden="true">' . icon('shield') . '</span>'
        . '<span class="kyc-chip__body"><strong data-kyc-name>' . e($c['name']) . '</strong>'
        . '<small>' . e(implode(' · ', $sub)) . '</small></span>'
        . kyc_badge($c['status'], $c['missing'])
        . '<button type="button" class="btn btn--ghost btn--sm" data-kyc-unlink>' . icon('x') . ' Délier</button>'
        . ($c['missing'] ? '<p class="kyc-chip__warn">' . icon('alert') . ' Dossier incomplet — manque : ' . e(implode(', ', $c['missing']))
            . '. Vérifiez l\'identité du client avant de finaliser l\'opération.</p>' : '')
        . '</div>';
}

/**
 * Documents financiers liés à un dossier (fiche GTC-Vault) : reçus de caisse et factures commerciales,
 * du plus récent au plus ancien.
 */
function kyc_finance_documents(int $clientId, int $limit = 50): array
{
    $docs = [];
    foreach (db_all('SELECT id, invoice_number, operation_type, amount_given, source_currency, status, kyc_status, agent_name, created_at, issue_date
                     FROM invoices WHERE vault_client_id = ? ORDER BY id DESC LIMIT ' . $limit, [$clientId]) as $r) {
        $docs[] = [
            'kind' => 'receipt', 'label' => 'Reçu de caisse', 'number' => $r['invoice_number'],
            'url' => 'invoices.php?action=view&id=' . (int) $r['id'],
            'date' => $r['created_at'] ?: $r['issue_date'], 'amount' => money($r['amount_given'], $r['source_currency']),
            'agent' => $r['agent_name'], 'kyc' => $r['kyc_status'],
        ];
    }
    foreach (db_all('SELECT id, invoice_number, doc_type, total, currency, kyc_status, created_by_name, created_at, issue_date
                     FROM commercial_invoices WHERE vault_client_id = ? ORDER BY id DESC LIMIT ' . $limit, [$clientId]) as $r) {
        $docs[] = [
            'kind' => 'commercial', 'label' => CINV_TYPES[$r['doc_type']]['label'] ?? 'Facture', 'number' => $r['invoice_number'],
            'url' => 'invoices-list.php?action=edit&id=' . (int) $r['id'],
            'date' => $r['created_at'] ?: $r['issue_date'], 'amount' => money($r['total'], $r['currency']),
            'agent' => (string) $r['created_by_name'], 'kyc' => $r['kyc_status'],
        ];
    }
    usort($docs, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
    return array_slice($docs, 0, $limit);
}
