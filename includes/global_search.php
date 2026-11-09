<?php
/**
 * Recherche globale du Dashboard (palette « Ctrl + K », barre du Hub) : une saisie, plusieurs tables.
 *
 *   Clients GTC-Vault     clients              nom, téléphone, N° CNI / passeport / RCCM   → vault/client.php?uuid=
 *   Reçus de caisse       invoices             N° de reçu, client, téléphone, N° de pièce  → invoices.php?action=view&id=
 *   Factures              commercial_invoices  N° de facture, client, téléphone, N° pièce  → invoices-list.php?action=edit&id=
 *   Suivi des colis       shipments            N° de suivi, réf. transporteur, client      → shipments.php?id=
 *   Modules               (liste du rôle)      nom du module                               → page du module
 *
 * Chaque catégorie n'est interrogée que si le rôle du compte ouvre la page correspondante (un caissier ne voit
 * jamais un dossier KYC qu'il ne pourrait pas ouvrir). Requêtes préparées, LIKE échappé, 5 résultats par catégorie
 * (+ nombre total et lien « Voir tout » vers la liste filtrée de la page).
 * Appelé par dashboard/search-api.php (JSON) ; l'interface est dans assets/js/global-search.js.
 */
declare(strict_types=1);

require_once __DIR__ . '/commercial_invoices.php';   // CINV_TYPES, CINV_STATUSES (+ includes/kyc_link.php)
require_once __DIR__ . '/shipments.php';

const GS_PER_CATEGORY = 5;
const GS_MIN_LENGTH   = 2;
const GS_MAX_LENGTH   = 80;

/** Mots de la recherche (5 au plus) et motif LIKE échappé de chacun. */
function gs_words(string $q): array
{
    $words = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], fn ($w) => $w !== '')), 0, 5);
    return array_map(fn ($w) => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $w) . '%', $words);
}

/**
 * Condition « chaque mot apparaît dans au moins une colonne » (+ chiffres du téléphone sans espaces).
 * Retourne [sql, paramètres].
 */
function gs_where(array $likes, array $columns, string $q, ?string $phoneColumn = null): array
{
    $and = [];
    $params = [];
    foreach ($likes as $like) {
        $or = [];
        foreach ($columns as $col) {
            $or[] = "{$col} LIKE ? ESCAPE '!'";
            $params[] = $like;
        }
        $and[] = '(' . implode(' OR ', $or) . ')';
    }
    $sql = '(' . implode(' AND ', $and) . ')';
    $digits = preg_replace('/\D+/', '', $q);
    if ($phoneColumn && strlen($digits) >= 4) {
        $sql .= " OR REPLACE(REPLACE(REPLACE({$phoneColumn}, ' ', ''), '-', ''), '.', '') LIKE ?";
        $params[] = '%' . $digits . '%';
    }
    return ['(' . $sql . ')', $params];
}

/** Résultats de la recherche, par catégorie, pour le compte connecté. */
function gs_search(string $q, array $user): array
{
    $q = trim((string) preg_replace('/\s+/u', ' ', mb_substr($q, 0, GS_MAX_LENGTH)));
    $likes = gs_words($q);
    $role = (string) ($user['role'] ?? '');
    $ops  = in_array($role, OPERATION_ROLES, true);
    $scopes = gs_scopes($user);
    if (mb_strlen($q) < GS_MIN_LENGTH || !$likes) {
        return ['query' => $q, 'total' => 0, 'categories' => [], 'scopes' => $scopes];
    }
    $categories = [];
    $n = GS_PER_CATEGORY;
    $enc = rawurlencode($q);

    // ---------- Clients GTC-Vault (rôles du coffre-fort uniquement : la fiche montre les pièces d'identité) ----------
    if (in_array($role, VAULT_ROLES, true) && vault_url() !== null) {
        [$where, $params] = gs_where($likes, ['nom_complet', 'telephone', 'numero_identifiant'], $q, 'telephone');
        $count = (int) db_value("SELECT COUNT(*) FROM clients WHERE {$where}", $params);
        if ($count) {
            $rows = db_all("SELECT id, uuid, type_client, nom_complet, telephone, email, adresse, numero_identifiant
                            FROM clients WHERE {$where} ORDER BY nom_complet LIMIT {$n}", $params);
            $docs = kyc_doc_types(array_column($rows, 'id'));
            $items = [];
            foreach ($rows as $r) {
                $c = kyc_client_public($r, $docs[(int) $r['id']] ?? []);
                $items[] = [
                    'title' => $c['name'],
                    'sub'   => implode(' · ', array_filter([$c['typeLabel'], $c['phone'], $c['idNumber'] !== '' ? $c['idLabel'] . ' ' . $c['idNumber'] : ''])),
                    'badge' => ['label' => $c['status'] === 'complete' ? 'KYC complet' : 'KYC incomplet', 'tone' => $c['status'] === 'complete' ? 'success' : 'warning'],
                    'url'   => 'vault/client.php?uuid=' . rawurlencode($c['uuid']),
                ];
            }
            $categories[] = ['key' => 'clients', 'label' => 'Clients GTC-Vault', 'icon' => icon('vault'),
                             'count' => $count, 'items' => $items, 'more' => 'vault/clients.php?q=' . $enc];
        }
    }

    if ($ops) {
        // ---------- Reçus de caisse ----------
        [$where, $params] = gs_where($likes, ['invoice_number', 'client_name', 'client_phone', 'client_id_number'], $q, 'client_phone');
        $count = (int) db_value("SELECT COUNT(*) FROM invoices WHERE {$where}", $params);
        if ($count) {
            $items = [];
            foreach (db_all("SELECT id, invoice_number, client_name, operation_type, amount_given, source_currency, status, kyc_status, created_at, issue_date
                             FROM invoices WHERE {$where} ORDER BY id DESC LIMIT {$n}", $params) as $r) {
                $items[] = [
                    'title' => $r['invoice_number'],
                    'sub'   => $r['client_name'] . ' · ' . (OPERATION_TYPES[$r['operation_type']] ?? $r['operation_type']) . ' · ' . fdate($r['created_at'] ?: $r['issue_date']),
                    'meta'  => money($r['amount_given'], $r['source_currency']),
                    'badge' => isset(INVOICE_STATUSES[$r['status']]) ? INVOICE_STATUSES[$r['status']] : null,
                    'url'   => 'invoices.php?action=view&id=' . (int) $r['id'],
                ];
            }
            $categories[] = ['key' => 'receipts', 'label' => 'Reçus de caisse', 'icon' => icon('receipt'),
                             'count' => $count, 'items' => $items, 'more' => 'invoices.php?action=list&q=' . $enc];
        }

        // ---------- Factures commerciales, proformas, devis ----------
        [$where, $params] = gs_where($likes, ['invoice_number', 'client_name', 'client_phone', 'client_id_number'], $q, 'client_phone');
        $count = (int) db_value("SELECT COUNT(*) FROM commercial_invoices WHERE {$where}", $params);
        if ($count) {
            $items = [];
            foreach (db_all("SELECT id, invoice_number, doc_type, client_name, total, currency, status, issue_date
                             FROM commercial_invoices WHERE {$where} ORDER BY issue_date DESC, id DESC LIMIT {$n}", $params) as $r) {
                $items[] = [
                    'title' => $r['invoice_number'],
                    'sub'   => (CINV_TYPES[$r['doc_type']]['label'] ?? 'Facture') . ' · ' . $r['client_name'] . ' · ' . fdate($r['issue_date']),
                    'meta'  => money($r['total'], $r['currency']),
                    'badge' => CINV_STATUSES[$r['status']] ?? null,
                    'url'   => 'invoices-list.php?action=edit&id=' . (int) $r['id'],
                ];
            }
            $categories[] = ['key' => 'commercial', 'label' => 'Factures', 'icon' => icon('file'),
                             'count' => $count, 'items' => $items, 'more' => 'invoices-list.php?q=' . $enc];
        }

        // ---------- Suivi des colis ----------
        [$where, $params] = gs_where($likes, ['tracking_number', 'carrier_reference', 'client_name', 'client_phone'], $q, 'client_phone');
        $count = (int) db_value("SELECT COUNT(*) FROM shipments WHERE {$where}", $params);
        if ($count) {
            $items = [];
            foreach (db_all("SELECT id, tracking_number, client_name, transport_mode, origin, destination, status
                             FROM shipments WHERE {$where} ORDER BY id DESC LIMIT {$n}", $params) as $r) {
                $route = implode(' → ', array_filter([(string) $r['origin'], (string) $r['destination']]));
                $items[] = [
                    'title' => $r['tracking_number'],
                    'sub'   => implode(' · ', array_filter([$r['client_name'], SHIPMENT_MODES[$r['transport_mode']] ?? '', $route])),
                    'badge' => isset(SHIPMENT_STATUSES[$r['status']]) ? ['label' => SHIPMENT_STATUSES[$r['status']]['label'], 'tone' => SHIPMENT_STATUSES[$r['status']]['tone']] : null,
                    'url'   => 'shipments.php?id=' . (int) $r['id'],
                ];
            }
            $categories[] = ['key' => 'shipments', 'label' => 'Suivi des colis', 'icon' => icon('ship'),
                             'count' => $count, 'items' => $items, 'more' => 'shipments.php?q=' . $enc];
        }
    }

    // ---------- Modules (navigation rapide) ----------
    $fold = fn (string $s) => mb_strtolower(strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'e', 'à' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i', 'û' => 'u']));
    $needle = $fold($q);
    $items = [];
    foreach (dash_modules() as $m) {
        $hay = $fold($m['label'] . ' ' . $m['title'] . ' ' . ($m['desc'] ?? '') . ' ' . ($m['tag'] ?? ''));
        if (str_contains($hay, $needle)) {
            $items[] = ['title' => $m['title'], 'sub' => $m['desc'] ?? '', 'url' => $m['href'], 'icon' => icon($m['icon'])];
        }
    }
    if ($items) {
        $categories[] = ['key' => 'modules', 'label' => 'Modules', 'icon' => icon('grid'),
                         'count' => count($items), 'items' => array_slice($items, 0, $n), 'more' => null];
    }

    $total = array_sum(array_column($categories, 'count'));
    return ['query' => $q, 'total' => $total, 'categories' => $categories, 'scopes' => $scopes];
}

/** Ce que le compte peut chercher (texte d'aide de la palette et message « aucun résultat »). */
function gs_scopes(array $user): array
{
    $role = (string) ($user['role'] ?? '');
    $scopes = [];
    if (in_array($role, VAULT_ROLES, true) && vault_url() !== null) {
        $scopes[] = 'clients GTC-Vault (nom, téléphone, N° CNI / RCCM)';
    }
    if (in_array($role, OPERATION_ROLES, true)) {
        array_push($scopes, 'reçus de caisse et factures (N° ou client)', 'colis (N° de suivi)');
    }
    $scopes[] = 'modules';
    return $scopes;
}
