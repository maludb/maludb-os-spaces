<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; the design system's reference is
 * record-picker.md, docs/spaces-record-picker.md here is the record). One entry per kind of record a form chooses:
 *
 *   member          a person or an agent (a space's "add a member", a page's "share with")        params space | page
 *   channel_member  who may be added to a channel                                                  param  channel
 *   channel_guest   the guests who may be added to a channel                                       param  channel
 *   guest           the guests a page may be shared to                                             param  page
 *   space           every live space the caller sees (a filter)
 *   space_member    the spaces the caller belongs to (a channel is made in one of them)
 *   space_page      the spaces the caller may write pages in (a page or a database is made, a page moved)
 *   space_export    the spaces the caller may export
 *   space_import    the spaces the caller may import into
 *   page            pages and databases the caller sees — or, with editable=1, may put a page under   params space, editable, pages_only, except
 *   channel_export  the channels the caller may export (a space owner's)
 *   database        the databases the caller sees — or, with editable=1, may write in
 *
 * Every `gate` is the authorization the screens that use the field apply (null = allowed, else the refusal's words); the pages, the
 * spaces and the databases come from mcp_* views, so what the caller may SEE is the database's own answer (the permission tree) —
 * the gate only adds what the screen itself asked for (an owner, a full page, the right to share to a guest).
 * `search` reuses the feature's own list query and only filters and slices what it returns; `label` names one record for a form's first
 * render and a 422 re-render (null when the caller may not see it: the form then shows the placeholder).
 */

/** The label a space shows: its icon, then its name. */
function picker_space_label(array $s): string
{
    return (($s['icon'] ?? '') !== '' ? $s['icon'] . ' ' : '') . $s['name'];
}

/** A space source over one of the feature's space lists: $fetch(PDO): the rows of that list. */
function picker_space_source(string $title, callable $fetch, ?callable $gate = null): array
{
    return [
        'title' => $title, 'noun' => 'space', 'params' => [],
        'gate' => $gate ?? static fn (array $p): ?string => null,
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($fetch): array {
            require_once __DIR__ . '/features/spaces/queries.php';
            $rows = array_map(static fn (array $s): array => [
                'id' => (int) $s['space_id'], 'label' => picker_space_label($s),
                'detail' => $s['detail'] ?? null,
            ], $fetch($pdo));
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            require_once __DIR__ . '/features/spaces/queries.php';
            $s = is_int($id) ? find_space($pdo, $id) : null;
            return $s === null ? null : picker_space_label($s);
        },
    ];
}

/** A member's label: the name, and "(agent)" for an agent, as the selects showed it. */
function picker_member_label(array $m): string
{
    return $m['display_name'] . (($m['member_kind'] ?? '') === 'agent' || !empty($m['is_agent']) ? ' (agent)' : '');
}

function picker_member_name(PDO $pdo, int|string $id): ?string
{
    if (!is_int($id)) { return null; }
    $st = $pdo->prepare("SELECT display_name, member_kind FROM members WHERE id = :id AND status = 'active'");
    $st->execute(['id' => $id]);
    $m = $st->fetch();
    return $m === false ? null : picker_member_label($m);
}

/** The rows of a member list: a person, with their title or e-mail underneath, an agent badged. */
function picker_member_rows(array $members): array
{
    return array_map(static function (array $m): array {
        $agent = ($m['member_kind'] ?? '') === 'agent' || !empty($m['is_agent']);
        return ['id' => (int) $m['member_id'], 'label' => picker_member_label($m),
            'detail' => implode(' · ', array_filter([(string) ($m['job_title'] ?? ''), (string) ($m['email'] ?? '')])) ?: null,
            'badge' => $agent ? 'Agent' : null, 'color' => 'info'];
    }, $members);
}

/** The channel a request names, when the caller may add to it: [channel, null] or [null, the refusal's words]. */
function picker_channel_for_add(PDO $pdo, ?int $id, bool $guests): array
{
    require_once __DIR__ . '/features/channels/handler.php';
    $c = $id === null ? null : find_channel($pdo, $id);
    if ($c === null) { return [null, 'That channel does not exist.']; }
    $may = $c['i_am_member'] && $c['archived_at'] === null && $c['kind'] !== 'dm';
    if ($guests) { $may = $may && channel_owner($c) && has_right('share.guest'); }
    return $may ? [$c, null] : [null, 'You may not add people to that channel.'];
}

/** A page the caller may manage sharing of (the full level), from a `page` param. */
function picker_full_page(array $params): ?string
{
    $page = $params['page'] ?? null;
    return $page !== null && has_full_page($page) ? null : 'You may not share that page.';
}

return [
    'member' => [
        'title' => 'People and agents', 'noun' => 'person', 'params' => ['space' => 'int', 'page' => 'uuid'],
        'gate' => static function (array $p): ?string {
            require_once __DIR__ . '/features/spaces/queries.php';
            if (is_guest()) { return 'You may not choose from the directory.'; }
            if ($p['space'] !== null) {                              // a space's "add a member": its owner
                $s = find_space(db(), $p['space']);
                return $s !== null && $s['i_am_owner'] && $s['archived_at'] === null ? null : 'You may not add people to that space.';
            }
            if ($p['page'] !== null) {                               // a page's "share with": the page at full
                return picker_full_page($p);
            }
            return 'Say what the person is for.';
        },
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $result = find_members_for_pick($pdo, $q, $params['space'], $page);
            $result['rows'] = picker_member_rows($result['rows']);
            return $result;
        },
        'label' => static fn (PDO $pdo, int|string $id): ?string => picker_member_name($pdo, $id),
    ],
    'channel_member' => [
        'title' => 'People and agents', 'noun' => 'person', 'params' => ['channel' => 'int'],
        'gate' => static fn (array $p): ?string => picker_channel_for_add(db(), $p['channel'], false)[1],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            [$c] = picker_channel_for_add($pdo, $params['channel'], false);
            return picker_result_from_list($c === null ? [] : picker_member_rows(channel_candidates($pdo, $c)['members']), $q, $page);
        },
        'label' => static fn (PDO $pdo, int|string $id): ?string => picker_member_name($pdo, $id),
    ],
    'channel_guest' => [
        'title' => 'Guests', 'noun' => 'guest', 'params' => ['channel' => 'int'],
        'gate' => static fn (array $p): ?string => picker_channel_for_add(db(), $p['channel'], true)[1],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            [$c] = picker_channel_for_add($pdo, $params['channel'], true);
            return picker_result_from_list($c === null ? [] : picker_member_rows(channel_candidates($pdo, $c)['guests']), $q, $page);
        },
        'label' => static fn (PDO $pdo, int|string $id): ?string => picker_member_name($pdo, $id),
    ],
    'guest' => [
        'title' => 'Guests', 'noun' => 'guest', 'params' => ['page' => 'uuid'],
        'gate' => static fn (array $p): ?string => has_right('share.guest') ? picker_full_page($p) : 'You may not share to a guest.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/pages/queries.php';
            return picker_result_from_list(picker_member_rows(guests_for_pick($pdo)), $q, $page);
        },
        'label' => static fn (PDO $pdo, int|string $id): ?string => picker_member_name($pdo, $id),
    ],

    'space' => picker_space_source('Spaces', static fn (PDO $pdo): array => find_spaces($pdo, [], 500)),
    'space_member' => picker_space_source('Your spaces', static function (PDO $pdo): array {
        require_once __DIR__ . '/features/channels/queries.php';
        return spaces_for_channel_create($pdo);
    }),
    'space_page' => picker_space_source('Spaces', static function (PDO $pdo): array {
        require_once __DIR__ . '/features/pages/queries.php';
        return spaces_for_create($pdo);
    }),
    'space_export' => picker_space_source('Spaces', static function (PDO $pdo): array {
        require_once __DIR__ . '/features/exports/queries.php';
        return export_pick_spaces($pdo, is_sp_admin());
    }, static fn (array $p): ?string => has_right('export.space') || is_sp_admin() ? null : 'You may not export a space.'),
    'space_import' => picker_space_source('Spaces', static function (PDO $pdo): array {
        require_once __DIR__ . '/features/imports/queries.php';
        return import_spaces($pdo);
    }, static fn (array $p): ?string => has_right('pages.write') || has_right('databases.write') ? null : 'You may not import.'),

    // Pages and databases-as-pages. Seen = the permission tree's answer (mcp_pages). editable=1 = the caller may put a page UNDER it (edit or
    // full), and then only pages of spaces the caller belongs to (or their private ones) are listed; a `space` param names a space the caller
    // must belong to, or nothing is listed. pages_only=1 leaves databases out; except=<uuid> leaves one page out (the page being moved).
    'page' => [
        'title' => 'Pages', 'noun' => 'page', 'params' => ['space' => 'int', 'editable' => 'int', 'pages_only' => 'int', 'except' => 'uuid'],
        'gate' => static fn (array $p): ?string => null,
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/pages/queries.php';
            $mine = array_map('intval', $pdo->query('SELECT space_id FROM mcp_spaces WHERE i_am_member')->fetchAll(PDO::FETCH_COLUMN));
            if ($params['space'] !== null && !in_array($params['space'], $mine, true)) {
                return picker_result_from_list([], $q, $page);
            }
            $filters = ['q' => $q, 'space' => $params['space'], 'editable' => !empty($params['editable'])];
            if (!empty($params['pages_only'])) { $filters['kind'] = 'page'; }
            $rows = [];
            foreach (find_pages($pdo, $filters, 500) as $p) {
                if ($p['page_id'] === ($params['except'] ?? null)) { continue; }
                if (!empty($params['editable']) && $p['space_id'] !== null && !in_array($p['space_id'], $mine, true)) { continue; }
                $rows[] = ['id' => $p['page_id'], 'label' => ($p['plain_title'] ?? '') !== '' ? $p['plain_title'] : 'Untitled', 'detail' => $p['space_name'] ?? 'Private',
                    'badge' => $p['kind'] === 'database' ? 'Database' : null, 'color' => 'secondary'];
            }
            return picker_result_from_list($rows, '', $page);          // the title match was made in SQL
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            require_once __DIR__ . '/features/pages/queries.php';
            $p = is_string($id) ? find_page($pdo, $id) : null;
            return $p === null ? null : (($p['plain_title'] ?? '') !== '' ? $p['plain_title'] : 'Untitled');
        },
    ],
    'channel_export' => [
        'title' => 'Channels', 'noun' => 'channel', 'params' => [],
        'gate' => static fn (array $p): ?string => has_right('export.space') || is_sp_admin() ? null : 'You may not export a channel.',
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/exports/queries.php';
            return picker_result_from_list(array_map(static fn (array $c): array => [
                'id' => (int) $c['channel_id'], 'label' => '#' . $c['name'], 'detail' => $c['space_name'],
            ], export_pick_channels($pdo, is_sp_admin())), $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            $st = $pdo->prepare('SELECT name FROM mcp_channels WHERE channel_id = :id AND kind IN (\'public\', \'private\')');
            $st->execute(['id' => is_int($id) ? $id : 0]);
            $n = $st->fetchColumn();
            return $n === false ? null : '#' . $n;
        },
    ],
    'database' => [
        'title' => 'Databases', 'noun' => 'database', 'params' => ['editable' => 'int'],
        'gate' => static fn (array $p): ?string => null,
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $edit = !empty($params['editable']) ? "AND p.my_level IN ('edit', 'full')" : '';
            $rows = $pdo->query("SELECT d.database_id::text AS id, d.title, s.name AS space_name FROM mcp_databases d JOIN mcp_pages p ON p.page_id = d.database_id LEFT JOIN mcp_spaces s ON s.space_id = p.space_id
                                  WHERE d.archived_at IS NULL AND NOT p.is_template $edit ORDER BY d.title, d.database_id LIMIT 500")->fetchAll();
            return picker_result_from_list(array_map(static fn (array $d): array => ['id' => $d['id'], 'label' => ($d['title'] ?? '') !== '' ? $d['title'] : 'Untitled', 'detail' => $d['space_name'] ?? 'Private'], $rows), $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            $st = $pdo->prepare('SELECT title FROM mcp_databases WHERE database_id = CAST(:id AS uuid)');
            $st->execute(['id' => (string) $id]);
            $t = $st->fetchColumn();
            return $t === false ? null : ($t !== '' ? $t : 'Untitled');
        },
    ],
];
