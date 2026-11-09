<?php
/**
 * GTC-Vault — Archivage, restauration et suppression d'un dossier client (POST uniquement, jeton CSRF).
 *
 *   op=archive   dossier masqué du répertoire et de la recherche de la facturation ; conservé intact, restaurable
 *                (rôles GTC-Vault : administration, conformité, agents) ;
 *   op=restore   dossier archivé remis dans le répertoire (mêmes rôles) ;
 *   op=delete    suppression DÉFINITIVE du dossier, de ses pièces (fichiers) et de sa photo de profil —
 *                administrateurs uniquement, et refusée si des reçus ou factures sont rattachés au dossier :
 *                la preuve d'identification d'une opération doit être conservée (obligation KYC / LBC-FT) ;
 *                on archive alors le dossier.
 * Chaque action est inscrite au journal de sécurité (audit_logs), avec l'agent, la date et l'adresse IP.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/vault.php';

$user = vault_guard();   // connexion du Dashboard + rôle VAULT_ROLES

/** Retour vers la page d'origine (liste filtrée ou fiche) — jamais vers une autre page ni un autre site. */
function action_back(string $fallback): string
{
    $back = (string) ($_POST['back'] ?? '');
    return preg_match('#^vault/client(s)?\.php(\?[a-zA-Z0-9_=&%+.,:\-]*)?$#', $back) ? $back : $fallback;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('clients.php');
}
$token = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    flash('error', 'Le formulaire a expiré. Merci de réessayer.');
    redirect('clients.php');
}

$op   = (string) ($_POST['op'] ?? '');
$uuid = strtolower((string) ($_POST['uuid'] ?? ''));
$pdo  = vault_db();
$st   = $pdo->prepare('SELECT id, uuid, nom_complet, archived_at, avatar_path FROM clients WHERE uuid = ?');
$st->execute([$uuid]);
$client = $st->fetch();
if (!$client) {
    flash('error', 'Ce dossier n\'existe plus (déjà supprimé ?).');
    redirect('clients.php');
}
$name = $client['nom_complet'];
$back = action_back('vault/clients.php');
// Depuis la fiche d'un dossier supprimé : retour au répertoire (la fiche n'existe plus)
$toList = fn () => str_starts_with($back, 'vault/client.php') ? 'vault/clients.php' : $back;

switch ($op) {
    case 'archive':
        if ($client['archived_at'] === null) {
            $pdo->prepare('UPDATE clients SET archived_at = ?, archived_by = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int) $user['id'], (int) $client['id']]);
            vault_audit('client_archive', $client, null, $user['name'] . ' a archivé le dossier de ' . $name);
        }
        flash('success', 'Dossier de ' . $name . ' archivé. Il reste consultable dans l\'onglet « Archivés ».');
        redirect('../' . $back);

    case 'restore':
        if ($client['archived_at'] !== null) {
            $pdo->prepare('UPDATE clients SET archived_at = NULL, archived_by = NULL WHERE id = ?')->execute([(int) $client['id']]);
            vault_audit('client_restore', $client, null, $user['name'] . ' a restauré le dossier de ' . $name);
        }
        flash('success', 'Dossier de ' . $name . ' restauré dans le répertoire.');
        redirect('../' . $back);

    case 'delete':
        if (($user['role'] ?? '') !== 'admin') {
            vault_audit('access_denied', $client, null, 'Suppression refusée (rôle « ' . ($user['role'] ?? '?') . ' ») du dossier de ' . $name);
            flash('error', 'Seul un administrateur peut supprimer un dossier. Vous pouvez l\'archiver.');
            redirect('../' . $back);
        }
        // Dossier lié à des reçus ou factures : suppression refusée, archivage proposé
        $linked = (int) db_value('SELECT COUNT(*) FROM invoices WHERE vault_client_id = ?', [(int) $client['id']])
                + (int) db_value('SELECT COUNT(*) FROM commercial_invoices WHERE vault_client_id = ?', [(int) $client['id']]);
        if ($linked > 0) {
            flash('error', 'Le dossier de ' . $name . ' est rattaché à ' . $linked . ' document' . ($linked > 1 ? 's' : '')
                . ' financier' . ($linked > 1 ? 's' : '') . ' (reçus, factures) : il doit être conservé comme preuve d\'identification. Archivez-le plutôt.');
            redirect('../' . $back);
        }
        $docs = $pdo->prepare('SELECT chemin_fichier FROM client_documents WHERE client_id = ?');
        $docs->execute([(int) $client['id']]);
        $files = $docs->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM client_documents WHERE client_id = ?')->execute([(int) $client['id']]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([(int) $client['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[GTC-Vault suppression] ' . $e->getMessage());
            flash('error', 'La suppression a échoué : le dossier est intact. Réessayez.');
            redirect('../' . $back);
        }
        // Fichiers effacés après la base (un fichier orphelin vaut mieux qu'une fiche sans pièce)
        $base = realpath(KYC_STORAGE_DIR);
        $removed = 0;
        foreach ($files as $rel) {
            $path = realpath(KYC_STORAGE_DIR . '/' . $rel);
            if ($base !== false && $path !== false && str_starts_with($path, $base . DIRECTORY_SEPARATOR) && is_file($path) && @unlink($path)) {
                $removed++;
            }
        }
        avatar_delete_file($client['avatar_path']);
        vault_audit('client_delete', $client, null, $user['name'] . ' a supprimé définitivement le dossier de ' . $name
            . ' (' . count($files) . ' pièce' . (count($files) > 1 ? 's' : '') . ', ' . $removed . ' fichier' . ($removed > 1 ? 's' : '') . ' effacé' . ($removed > 1 ? 's' : '') . ')');
        flash('success', 'Dossier de ' . $name . ' supprimé définitivement, avec ses pièces.');
        redirect('../' . $toList());

    default:
        redirect('clients.php');
}
