<?php
declare(strict_types=1);

/** The JSON shape of a wiki report (slice 6): every list a whitelist, every page a link target; no raw row. */

const WIKI_LISTS = [
    'expired' => ['Expired', 'feather-alert-triangle', 'warning', 'Verified once, and the date has passed.'],
    'unverified' => ['Never verified', 'feather-help-circle', 'secondary', 'No one has vouched for these yet.'],
    'stale' => ['Stale', 'feather-clock', 'dark', 'Not edited for a long time.'],
    'orphans' => ['Orphans', 'feather-link-2', 'secondary', 'Nothing links to them.'],
    'broken' => ['Broken links', 'feather-slash', 'danger', 'A link to the trash or to nothing.'],
    'duplicates' => ['Duplicates', 'feather-copy', 'secondary', 'The same title, more than once.'],
    'unanswered' => ['Unanswered questions', 'feather-message-square', 'warning', 'Asked in a public channel, no reply yet.'],
];

function present_wiki_report(array $r): array
{
    $owner = static fn (array $x): ?array => ($x['owner_member_id'] ?? null) === null ? null : ['member_id' => $x['owner_member_id'], 'display_name' => $x['owner_name'] ?? null];
    $page = static fn (array $x): array => ['page_id' => $x['page_id'], 'title' => $x['title'], 'owner' => $owner($x), 'last_edited_at' => json_ts($x['last_edited_at'] ?? null), 'days_since_edit' => $x['days_since_edit'] ?? null];
    return [
        'expired' => array_map(static fn (array $x): array => $page($x) + ['verify_until' => json_ts($x['verify_until'])], $r['expired']),
        'unverified' => array_map($page, $r['unverified']),
        'stale' => array_map($page, $r['stale']),
        'orphans' => array_map($page, $r['orphans']),
        'broken' => array_map(static fn (array $x): array => ['page_id' => $x['from_page_id'], 'title' => $x['from_title'], 'block_id' => $x['from_block_id'], 'points_to' => ['page_id' => $x['to_page_id'], 'title' => $x['to_title']], 'reason' => $x['reason']], $r['broken']),
        'duplicates' => array_map(static fn (array $x): array => ['title' => $x['title'], 'count' => $x['n'], 'pages' => array_map(static fn (array $p): array => ['page_id' => $p['page_id'], 'title' => $p['title'], 'space' => ['space_id' => (int) $p['space_id'], 'name' => $p['space_name']]], $x['pages'])], $r['duplicates']),
        'unanswered' => array_map(static fn (array $x): array => ['message_id' => $x['message_id'], 'channel' => ['channel_id' => $x['channel_id'], 'name' => $x['channel_name']], 'author' => ['member_id' => $x['author_member_id'] === null ? null : (int) $x['author_member_id'], 'display_name' => $x['author_name']],
            'asked_at' => json_ts($x['asked_at']), 'excerpt' => $x['excerpt'], 'hours_open' => $x['hours_open']], $r['unanswered']),
    ];
}
