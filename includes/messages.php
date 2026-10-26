<?php
/**
 * Messagerie du Dashboard — éléments communs à la page (dashboard/messages.php) et à l'actualisation
 * automatique (dashboard/messages-api.php + assets/js/messages-live.js).
 *
 * Les lignes de la liste sont produites ici, côté serveur : une ligne ajoutée en direct est identique
 * à une ligne affichée au chargement (mêmes boutons d'action, mêmes jetons CSRF, même échappement).
 */

/** Filtres de la liste : clé d'URL → [libellé, condition SQL]. « Tous » = hors archives. */
const MESSAGE_FILTERS = [
    'all'      => ['Tous',      "status <> 'archived'"],
    'unread'   => ['Non lus',   "status = 'unread'"],
    'read'     => ['Lus',       "status = 'read'"],
    'replied'  => ['Répondus',  "status = 'replied'"],
    'archived' => ['Archivés',  "status = 'archived'"],
];

/** Adresse de retour sûre : la liste filtrée ou un message de messages.php, jamais une URL externe. */
function messages_safe_back(string $back): string
{
    return preg_match('/^messages\.php(\?[a-z0-9_=&%+.-]*)?$/i', $back) ? $back : 'messages.php';
}

/** Nombre de messages par filtre, en une seule requête : ['all' => …, 'unread' => …, …]. */
function messages_counts(): array
{
    $row = db_one(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END)   AS unread,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END)     AS is_read,
                SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END)  AS replied,
                SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) AS archived
         FROM messages"
    ) ?: [];
    $archived = (int) ($row['archived'] ?? 0);
    return [
        'all'      => (int) ($row['total'] ?? 0) - $archived,
        'unread'   => (int) ($row['unread'] ?? 0),
        'read'     => (int) ($row['is_read'] ?? 0),
        'replied'  => (int) ($row['replied'] ?? 0),
        'archived' => $archived,
    ];
}

/** Lien WhatsApp vers le numéro de l'expéditeur (numéro camerounais à 9 chiffres complété par 237). */
function message_whatsapp(?string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) === 9 && $digits[0] === '6') {
        $digits = '237' . $digits;
    }
    return strlen($digits) >= 8 ? 'https://wa.me/' . $digits : null;
}

/** Petit formulaire d'action sur un message (POST + jeton CSRF). */
function message_action(int $id, string $op, string $label, string $icon, string $back, string $class = 'btn btn--ghost btn--sm', string $confirm = ''): string
{
    return '<form method="post" action="messages.php" class="inline-form">' . csrf_field()
        . '<input type="hidden" name="op" value="' . e($op) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">'
        . '<button type="submit" class="' . e($class) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '')
        . ' title="' . e($label) . '">' . icon($icon)
        . '<span' . (str_contains($class, 'icon-btn') ? ' class="visually-hidden"' : '') . '>' . e($label) . '</span></button></form>';
}

/* ==========================================================================
   Conversations : les messages d'une même adresse e-mail (casse ignorée) forment un seul fil
   ========================================================================== */

/** Expression SQL de la clé de conversation (identique en SQLite et MySQL). */
const MESSAGE_THREAD_KEY = 'LOWER(sender_email)';

/** Clé de conversation d'un message (même règle que MESSAGE_THREAD_KEY). */
function message_thread_key(string $email): string
{
    return strtolower($email);
}

/** Identifiant de ligne d'une conversation dans la page (pas d'adresse e-mail dans les attributs HTML). */
function message_thread_dom_id(string $key): string
{
    return substr(md5($key), 0, 16);
}

/**
 * Conversations dont au moins un message répond à $whereSql, de la plus récente à la plus ancienne :
 * [['key', 'last' => dernier message, 'total' => messages du filtre, 'unread' => non lus], …]
 */
function messages_threads(string $whereSql, array $params, ?int $limit = null, int $offset = 0): array
{
    $groups = db_all(
        'SELECT ' . MESSAGE_THREAD_KEY . " AS thread_key, COUNT(*) AS total,
                SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) AS unread, MAX(id) AS last_id
         FROM messages {$whereSql}
         GROUP BY " . MESSAGE_THREAD_KEY . '
         ORDER BY last_id DESC' . ($limit ? ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset : ''),
        $params
    );
    if (!$groups) {
        return [];
    }
    $last = [];
    foreach (db_all('SELECT * FROM messages WHERE id IN (' . implode(', ', array_map(fn ($g) => (int) $g['last_id'], $groups)) . ')') as $m) {
        $last[(int) $m['id']] = $m;
    }
    $threads = [];
    foreach ($groups as $g) {
        if (isset($last[(int) $g['last_id']])) {
            $threads[] = ['key' => $g['thread_key'], 'last' => $last[(int) $g['last_id']], 'total' => (int) $g['total'], 'unread' => (int) $g['unread']];
        }
    }
    return $threads;
}

/** Nombre de conversations dont au moins un message répond à $whereSql. */
function messages_threads_count(string $whereSql, array $params): int
{
    return (int) db_value('SELECT COUNT(DISTINCT ' . MESSAGE_THREAD_KEY . ") FROM messages {$whereSql}", $params);
}

/** Ligne de la liste : une conversation (dernier message, nombre de messages, non lus). */
function message_thread_row(array $t, string $back): string
{
    $m      = $t['last'];
    $mid    = (int) $m['id'];
    $status = $t['unread'] > 0 ? 'unread' : $m['status'];
    $label  = $t['total'] . ' message' . ($t['total'] > 1 ? 's' : '');
    ob_start(); ?>
    <tr class="<?= $status === 'unread' ? 'is-unread' : '' ?>" data-thread="<?= e(message_thread_dom_id($t['key'])) ?>" data-message-id="<?= $mid ?>">
        <td data-label="Interlocuteur"><span class="cell-stack">
            <a href="messages.php?id=<?= $mid ?>" class="link-strong"><?= e($m['sender_name']) ?></a>
            <small class="cell-sub"><?= e($m['sender_email']) ?></small>
            <span class="thread-count"><?= icon('mail') ?> <?= e($label) ?><?= $t['unread'] > 0 && $t['total'] > 1 ? ' · <strong>' . $t['unread'] . ' non lu' . ($t['unread'] > 1 ? 's' : '') . '</strong>' : '' ?></span>
        </span></td>
        <td data-label="Dernier message"><span class="cell-stack">
            <a href="messages.php?id=<?= $mid ?>" class="mail-table__subject"><?= e($m['subject']) ?></a>
            <small class="cell-sub mail-table__excerpt"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $m['message']), 0, 110, '…')) ?></small>
        </span></td>
        <td data-label="Dernier reçu le"><?= e(fdate($m['created_at'], true)) ?></td>
        <td data-label="Statut"><?= badge($status, MESSAGE_STATUSES) ?></td>
        <td class="actions mail-table__actions">
            <a href="messages.php?id=<?= $mid ?>" class="icon-btn" title="Ouvrir la conversation"><?= icon('eye') ?><span class="visually-hidden">Ouvrir</span></a>
            <?php if ($t['unread'] > 0): ?>
                <?= message_action($mid, 'thread_read', 'Marquer la conversation comme lue', 'check', $back, 'icon-btn') ?>
            <?php endif; ?>
            <?= $m['status'] === 'archived'
                ? message_action($mid, 'thread_unarchive', 'Désarchiver la conversation', 'inbox', $back, 'icon-btn')
                : message_action($mid, 'thread_archive', 'Archiver la conversation', 'archive', $back, 'icon-btn') ?>
            <?= message_action($mid, 'thread_delete', 'Supprimer la conversation', 'trash', $back, 'icon-btn icon-btn--danger',
                'Supprimer définitivement toute la conversation avec ' . $m['sender_name'] . ' (archives comprises) ?') ?>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
}
