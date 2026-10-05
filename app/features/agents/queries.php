<?php
declare(strict_types=1);

/** Agents in Spaces (slice 7): the admin pages' reads — the agents that are members, the dispatches, what siblings read of ours. Through the mcp_* views. */

const DISPATCH_STATUSES = ['pending' => 'Pending', 'running' => 'Running', 'answered' => 'Answered', 'awaiting_approval' => 'Awaiting approval', 'refused' => 'Refused', 'failed' => 'Failed'];

/**
 * The agents that are members here (admitted — the Watcher the kernel never vouched for is not one): name, roles, the spaces and the channels
 * they are in, their last reply, and how many dispatches wait or failed. [{member_id, display_name, roles[], spaces[{space_id,name}], channels[{channel_id,name}], last_reply, pending, failed, answered}]
 */
function agents_here(PDO $pdo): array
{
    $agents = $pdo->query("SELECT member_id, display_name, job_title, roles, capability, status FROM mcp_members WHERE is_agent AND status = 'active' AND capability IS NOT NULL ORDER BY display_name")->fetchAll();
    $byId = [];
    foreach ($agents as $a) {
        $byId[(int) $a['member_id']] = ['member_id' => (int) $a['member_id'], 'display_name' => $a['display_name'], 'job_title' => $a['job_title'], 'roles' => pg_text_array((string) $a['roles']),
            'spaces' => [], 'channels' => [], 'last_reply' => null, 'pending' => 0, 'failed' => 0, 'answered' => 0, 'running' => 0];
    }
    if ($byId === []) { return []; }
    $ids = array_keys($byId);
    $in = implode(',', array_map('intval', $ids));
    foreach ($pdo->query("SELECT sm.member_id, s.space_id, s.name FROM mcp_space_members sm JOIN mcp_spaces s ON s.space_id = sm.space_id WHERE sm.member_id IN ($in) AND s.archived_at IS NULL ORDER BY s.name")->fetchAll() as $r) {
        $byId[(int) $r['member_id']]['spaces'][] = ['space_id' => (int) $r['space_id'], 'name' => $r['name']];
    }
    foreach ($pdo->query("SELECT cm.member_id, c.channel_id, c.name, c.kind FROM mcp_channel_members cm JOIN mcp_channels c ON c.channel_id = cm.channel_id WHERE cm.member_id IN ($in) AND c.kind IN ('public', 'private') AND c.archived_at IS NULL ORDER BY c.name")->fetchAll() as $r) {
        $byId[(int) $r['member_id']]['channels'][] = ['channel_id' => (int) $r['channel_id'], 'name' => $r['name']];
    }
    foreach ($pdo->query("SELECT agent_member_id, count(*) FILTER (WHERE status = 'sent' AND run_id IS NULL) AS pending, count(*) FILTER (WHERE status = 'sent' AND run_id IS NOT NULL) AS running,
                                 count(*) FILTER (WHERE status = 'failed') AS failed, count(*) FILTER (WHERE status = 'answered') AS answered
                            FROM mcp_agent_dispatches WHERE agent_member_id IN ($in) GROUP BY agent_member_id")->fetchAll() as $r) {
        $byId[(int) $r['agent_member_id']] = array_merge($byId[(int) $r['agent_member_id']], ['pending' => (int) $r['pending'], 'running' => (int) $r['running'], 'failed' => (int) $r['failed'], 'answered' => (int) $r['answered']]);
    }
    foreach ($pdo->query("SELECT DISTINCT ON (agent_member_id) agent_member_id, answered_at, reply_excerpt, channel_id, reply_message_id FROM mcp_agent_dispatches
                           WHERE status = 'answered' AND agent_member_id IN ($in) ORDER BY agent_member_id, answered_at DESC NULLS LAST")->fetchAll() as $r) {
        $byId[(int) $r['agent_member_id']]['last_reply'] = ['answered_at' => $r['answered_at'], 'excerpt' => $r['reply_excerpt'], 'channel_id' => $r['channel_id'] === null ? null : (int) $r['channel_id'], 'message_id' => $r['reply_message_id'] === null ? null : (int) $r['reply_message_id']];
    }
    return array_values($byId);
}

/** The status chip's filter as a WHERE fragment over mcp_agent_dispatches (alias d). */
function dispatch_status_filter(string $status): ?string
{
    return match ($status) {
        'pending' => "d.status = 'sent' AND d.run_id IS NULL",
        'running' => "d.status = 'sent' AND d.run_id IS NOT NULL",
        'answered', 'refused', 'failed', 'awaiting_approval' => "d.status = '" . $status . "'",
        default => null,
    };
}

/**
 * Every dispatch the caller may see (the view), newest first, 25 a page: the agent, the asker, the kind, the channel, the message's first line,
 * the status, attempts, the run, when, the reply's excerpt. $f: status (pending|running|answered|awaiting_approval|refused|failed), agent (member id).
 * ['rows' => [...], 'total', 'page', 'pages']
 */
function find_dispatches(PDO $pdo, array $f, int $page): array
{
    $where = ['true'];
    $args = [];
    if (($sql = dispatch_status_filter((string) ($f['status'] ?? ''))) !== null) { $where[] = $sql; }
    if (!empty($f['agent'])) { $where[] = 'd.agent_member_id = :agent'; $args['agent'] = (int) $f['agent']; }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_agent_dispatches d WHERE $w", $args);
    $per = 25;
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($page, $pages));
    $st = $pdo->prepare("SELECT d.dispatch_id, d.agent_member_id, d.agent_name, d.acting_member_id, ask.display_name AS asker_name, d.kind, d.channel_id, c.name AS channel_name, c.kind AS channel_kind,
                                d.record_id AS message_id, left(msg.plain_text, 120) AS first_line, d.status, d.run_id, d.request_id, d.attempts, d.detail, d.created_at, d.answered_at, d.reply_excerpt, d.reply_message_id
                           FROM mcp_agent_dispatches d
                           LEFT JOIN mcp_members ask ON ask.member_id = d.acting_member_id
                           LEFT JOIN mcp_channels c ON c.channel_id = d.channel_id
                           LEFT JOIN mcp_messages msg ON msg.message_id = d.record_id AND d.record_type = 'message'
                          WHERE $w ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** One dispatch the caller may see (the view). */
function find_dispatch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT dispatch_id, agent_member_id, agent_name, acting_member_id, kind, channel_id, record_id AS message_id, status, run_id, attempts, detail FROM mcp_agent_dispatches WHERE dispatch_id = :d');
    $st->execute(['d' => $id]);
    return $st->fetch() ?: null;
}

/**
 * The admin's retry: a failed dispatch — or one waiting for an approval the admin gives up on — goes back to `sent`, due now. The attempts are KEPT but never above five — db/016 offers a dispatch only
 * while attempts <= 5, so a retried one has exactly one more try (it fails again for good if it does).
 */
function retry_dispatch(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'sent', run_id = NULL, next_attempt_at = now(), attempts = least(attempts, 5), detail = NULL WHERE id = :d AND status IN ('failed', 'awaiting_approval') AND record_type = 'message'");
    $st->execute(['d' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Only a failed dispatch, or one still waiting for an approval, is retried.');
    }
}

/** The shares this application declares (maludb-os.json `shares[]`): [{tool, description, scoped}]. */
function declared_shares(): array
{
    $m = json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
    $out = [];
    foreach (is_array($m['shares'] ?? null) ? $m['shares'] : [] as $s) {
        $out[] = ['tool' => (string) ($s['tool'] ?? ''), 'description' => (string) ($s['description'] ?? ''), 'scoped' => !empty($s['scoped'])];
    }
    return $out;
}

/** What sibling applications read of ours: the `share.read` rows of the activity log, newest first. [{occurred_at, application, tool, rows}] */
function share_reads(PDO $pdo, int $limit): array
{
    $st = $pdo->prepare("SELECT activity_id, occurred_at, COALESCE(after->>'application', '') AS application, COALESCE(after->>'tool', '') AS tool, COALESCE(after->>'rows', after->>'row_count', after->>'count') AS row_count
                           FROM mcp_activity_log WHERE action = 'share.read' ORDER BY occurred_at DESC, activity_id DESC LIMIT :n");
    $st->bindValue('n', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    return array_map(static fn (array $r): array => ['activity_id' => (int) $r['activity_id'], 'occurred_at' => $r['occurred_at'], 'application' => $r['application'], 'tool' => $r['tool'], 'rows' => $r['row_count'] === null ? null : (int) $r['row_count']], $st->fetchAll());
}
