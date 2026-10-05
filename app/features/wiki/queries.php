<?php
declare(strict_types=1);

/**
 * A wiki's reports (slice 6): every list is one SQL function of db/015 answering as the caller (a page they may not see is not in it); PHP only narrows to the space and adds the names.
 * Nothing here changes a page — verify, owner and trash are slice 2's actions, linked from each row. The nudge queues one `verification` notice through sp_notify() (db/012, with db/020's dedupe).
 */

/** The workspace numbers the lists were cut with (mcp_settings) beside the space's verify window. */
function wiki_numbers(PDO $pdo, array $space): array
{
    $r = $pdo->query('SELECT stale_page_days, unanswered_hours, wiki_default_verify_months FROM mcp_settings')->fetch() ?: [];
    return ['stale_days' => (int) ($r['stale_page_days'] ?? 90), 'unanswered_hours' => (int) ($r['unanswered_hours'] ?? 24),
            'verify_months' => (int) ($space['wiki_default_verify_months'] ?? $r['wiki_default_verify_months'] ?? 6)];
}

/** The seven lists for a space: ['expired', 'unverified', 'stale', 'orphans', 'broken', 'duplicates', 'unanswered'], each the function's rows (ids as text or int). */
function wiki_report(PDO $pdo, int $spaceId): array
{
    $one = static function (string $sql, array $args = []) use ($pdo): array { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); };
    $status = $one('SELECT page_id::text AS page_id, title, space_id, verification_state, verified_at, verify_until, wiki_owner_member_id, owner_name, last_edited_at, days_since_edit
                      FROM sp_wiki_status(:s) ORDER BY verify_until NULLS FIRST, lower(title)', ['s' => $spaceId]);
    $owner = static fn (array $r): array => $r + ['owner_member_id' => $r['wiki_owner_member_id'] === null ? null : (int) $r['wiki_owner_member_id']];
    $report = [
        'expired' => array_map($owner, array_values(array_filter($status, static fn (array $r): bool => $r['verification_state'] === 'expired'))),
        'unverified' => array_map($owner, array_values(array_filter($status, static fn (array $r): bool => $r['verification_state'] === 'none'))),
        'stale' => $one('SELECT s.page_id::text AS page_id, s.title, s.space_id, s.last_edited_at, s.days_since_edit, s.owner_member_id, m.display_name AS owner_name
                           FROM sp_stale_pages() s LEFT JOIN mcp_members m ON m.member_id = s.owner_member_id WHERE s.space_id = :s ORDER BY s.last_edited_at', ['s' => $spaceId]),
        'orphans' => $one('SELECT o.page_id::text AS page_id, o.title, o.space_id, o.last_edited_at, p.owner_member_id, m.display_name AS owner_name
                             FROM sp_orphan_pages() o LEFT JOIN mcp_pages p ON p.page_id = o.page_id LEFT JOIN mcp_members m ON m.member_id = p.owner_member_id WHERE o.space_id = :s ORDER BY o.last_edited_at', ['s' => $spaceId]),
        'broken' => $one('SELECT b.from_page_id::text AS from_page_id, b.from_title, b.from_block_id::text AS from_block_id, b.to_page_id::text AS to_page_id, b.to_title, b.reason
                            FROM sp_broken_links() b WHERE b.from_page_id IN (SELECT page_id FROM mcp_pages WHERE space_id = :s) ORDER BY lower(b.from_title)', ['s' => $spaceId]),
        'duplicates' => $one('SELECT d.title, d.n, (SELECT json_agg(json_build_object(\'page_id\', x.page_id, \'title\', x.plain_title, \'space_id\', x.space_id, \'space_name\', sp.name) ORDER BY x.created_at)
                                                       FROM mcp_pages x LEFT JOIN mcp_spaces sp ON sp.space_id = x.space_id WHERE x.page_id = ANY (d.page_ids)) AS pages
                               FROM sp_duplicate_titles() d WHERE :s = ANY (d.space_ids) ORDER BY d.n DESC, d.title', ['s' => $spaceId]),
        'unanswered' => $one('SELECT u.message_id, u.channel_id, u.channel_name, u.space_id, u.author_member_id, u.author_name, u.asked_at, u.excerpt, u.hours_open
                                FROM sp_unanswered_questions() u WHERE u.space_id = :s ORDER BY u.asked_at', ['s' => $spaceId]),
    ];
    foreach ($report['duplicates'] as &$d) { $d['pages'] = json_decode((string) $d['pages'], true) ?: []; $d['n'] = (int) $d['n']; }
    unset($d);
    foreach ($report['unanswered'] as &$u) { $u['message_id'] = (int) $u['message_id']; $u['channel_id'] = (int) $u['channel_id']; $u['hours_open'] = (float) $u['hours_open']; }
    unset($u);
    foreach (['stale', 'orphans'] as $k) {
        foreach ($report[$k] as &$r) { $r['owner_member_id'] = ($r['owner_member_id'] ?? null) === null ? null : (int) $r['owner_member_id']; $r['days_since_edit'] = isset($r['days_since_edit']) ? (int) $r['days_since_edit'] : null; }
        unset($r);
    }
    return $report;
}

/** Why a page is being nudged, in words (the notice's second line): from its state, or the stated reason. */
function wiki_nudge_reason(PDO $pdo, string $pageUuid, ?string $reason = null): string
{
    $st = $pdo->prepare("SELECT verification_state, verify_until, last_edited_at, (current_date - last_edited_at::date) AS days FROM mcp_pages WHERE page_id = CAST(:p AS uuid)");
    $st->execute(['p' => $pageUuid]);
    $p = $st->fetch();
    if ($p === false) { return 'It needs a look.'; }
    $stale = (int) one_value($pdo, 'SELECT stale_page_days FROM mcp_settings');
    $reason ??= $p['verification_state'] === 'expired' ? 'expired' : ($p['verification_state'] === 'none' ? 'never' : ((int) $p['days'] >= $stale ? 'stale' : 'look'));
    return match ($reason) {
        'expired' => 'It expired on ' . ($p['verify_until'] === null ? 'a date past' : date('M j, Y', strtotime((string) $p['verify_until']))) . '.',
        'never' => 'It was never verified.',
        'stale' => 'It has not been edited in ' . (int) $p['days'] . ' days.',
        default => 'It is due a look.',
    };
}

/**
 * Nudge the page's owner (or $memberId): one `verification` notice through sp_notify() with the dedupe key wiki_nudge:<page>:<ISO week>, so a second nudge the same week queues nothing.
 * Answers ['queued' => bool, 'member_id' => int, 'reason' => string]. Refusals are our own sentences (DomainException): an agent cannot be nudged (it is dispatched, not notified),
 * nor oneself, nor someone who may not see the page.
 */
function nudge_owner(PDO $pdo, string $pageUuid, ?int $memberId, int $by, ?string $reason = null): array
{
    $st = $pdo->prepare('SELECT plain_title, wiki_owner_member_id, owner_member_id FROM mcp_pages WHERE page_id = CAST(:p AS uuid)');
    $st->execute(['p' => $pageUuid]);
    $p = $st->fetch() ?: throw new DomainException('Not found.');
    $to = $memberId ?? ($p['wiki_owner_member_id'] !== null ? (int) $p['wiki_owner_member_id'] : ($p['owner_member_id'] !== null ? (int) $p['owner_member_id'] : null));
    if ($to === null) { throw new DomainException('This page has no owner to nudge. Give it one first.'); }
    $m = $pdo->prepare("SELECT display_name, member_kind FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL");
    $m->execute(['m' => $to]);
    $who = $m->fetch() ?: throw new DomainException('That person is not a member here.');
    if ($to === $by) { throw new DomainException('You are the one to look at this page: there is nobody else to nudge.'); }
    if ($who['member_kind'] === 'agent') { throw new DomainException($who['display_name'] . ' is an agent: an agent is asked in a channel, not notified.'); }
    if ((int) one_value($pdo, 'SELECT sp_level_rank(sp_page_level(CAST(:p AS uuid), :m))', ['p' => $pageUuid, 'm' => $to]) < 1) {
        throw new DomainException($who['display_name'] . ' cannot see that page, so a nudge would lead nowhere.');
    }
    $title = $p['plain_title'] !== '' ? $p['plain_title'] : 'Untitled';
    $why = wiki_nudge_reason($pdo, $pageUuid, $reason);
    $nid = one_value($pdo, "SELECT sp_notify(:m, 'verification', :t, :b, NULL, NULL, CAST(:p AS uuid), NULL, NULL, :d)",
        ['m' => $to, 't' => 'Please look at "' . mb_substr($title, 0, 150) . '"', 'b' => $why, 'p' => $pageUuid, 'd' => 'wiki_nudge:' . $pageUuid . ':' . date('o-\WW')]);
    return ['queued' => $nid !== null, 'member_id' => $to, 'reason' => $why, 'member_name' => $who['display_name']];
}
