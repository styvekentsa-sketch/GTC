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

/** Ligne de la liste des messages. */
function message_row(array $m, string $back): string
{
    $mid = (int) $m['id'];
    ob_start(); ?>
    <tr class="<?= $m['status'] === 'unread' ? 'is-unread' : '' ?>" data-message-id="<?= $mid ?>">
        <td data-label="Expéditeur"><span class="cell-stack">
            <a href="messages.php?id=<?= $mid ?>" class="link-strong"><?= e($m['sender_name']) ?></a>
            <small class="cell-sub"><?= e($m['sender_email']) ?></small>
        </span></td>
        <td data-label="Sujet"><span class="cell-stack">
            <a href="messages.php?id=<?= $mid ?>" class="mail-table__subject"><?= e($m['subject']) ?></a>
            <small class="cell-sub mail-table__excerpt"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $m['message']), 0, 110, '…')) ?></small>
        </span></td>
        <td data-label="Reçu le"><?= e(fdate($m['created_at'], true)) ?></td>
        <td data-label="Statut"><?= badge($m['status'], MESSAGE_STATUSES) ?></td>
        <td class="actions mail-table__actions">
            <a href="messages.php?id=<?= $mid ?>" class="icon-btn" title="Lire le message"><?= icon('eye') ?><span class="visually-hidden">Lire</span></a>
            <?php if ($m['status'] === 'unread'): ?>
                <?= message_action($mid, 'read', 'Marquer comme lu', 'check', $back, 'icon-btn') ?>
            <?php endif; ?>
            <?= $m['status'] === 'archived'
                ? message_action($mid, 'unarchive', 'Désarchiver', 'inbox', $back, 'icon-btn')
                : message_action($mid, 'archive', 'Archiver', 'archive', $back, 'icon-btn') ?>
            <?= message_action($mid, 'delete', 'Supprimer', 'trash', $back, 'icon-btn icon-btn--danger',
                'Supprimer définitivement le message de ' . $m['sender_name'] . ' ?') ?>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
}
