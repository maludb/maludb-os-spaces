<?php
declare(strict_types=1);

/** The words and the JSON shapes of the agent pages (slice 7). Whitelists over the views' rows. */

/** A dispatch's chip: [label, tone] — sent is "pending" until a run is set, then "running". */
function dispatch_chip(array $d): array
{
    return match ((string) $d['status']) {
        'sent' => ($d['run_id'] ?? null) === null ? ['pending', 'secondary'] : ['running', 'secondary'],
        'answered' => ['answered', 'success'],
        'awaiting_approval' => ['awaiting approval', 'warning'],
        'refused' => ['refused', 'dark'],
        'failed' => ['failed', 'danger'],
        default => [(string) $d['status'], 'secondary'],
    };
}

/** The status key a filter chip uses for a row ('pending' / 'running' stand for sent). */
function dispatch_status_key(array $d): string
{
    return (string) $d['status'] === 'sent' ? (($d['run_id'] ?? null) === null ? 'pending' : 'running') : (string) $d['status'];
}

function present_dispatch(array $d): array
{
    return ['dispatch_id' => (int) $d['dispatch_id'], 'agent' => ['member_id' => (int) $d['agent_member_id'], 'display_name' => $d['agent_name']],
            'asker' => $d['acting_member_id'] === null ? null : ['member_id' => (int) $d['acting_member_id'], 'display_name' => $d['asker_name'] ?? null],
            'kind' => $d['kind'], 'channel' => $d['channel_id'] === null ? null : ['channel_id' => (int) $d['channel_id'], 'name' => $d['channel_name'] ?? null, 'kind' => $d['channel_kind'] ?? null],
            'message_id' => $d['message_id'] === null ? null : (int) $d['message_id'], 'first_line' => $d['first_line'] ?? null,
            'status' => dispatch_status_key($d), 'attempts' => (int) $d['attempts'], 'run_id' => $d['run_id'] === null ? null : (int) $d['run_id'], 'detail' => $d['detail'] ?? null,
            'created_at' => json_ts($d['created_at']), 'answered_at' => json_ts($d['answered_at'] ?? null), 'reply_excerpt' => $d['reply_excerpt'] ?? null, 'may_retry' => $d['status'] === 'failed'];
}

function present_agent(array $a): array
{
    return ['member_id' => $a['member_id'], 'display_name' => $a['display_name'], 'job_title' => $a['job_title'], 'roles' => $a['roles'], 'spaces' => $a['spaces'], 'channels' => $a['channels'],
            'last_reply' => $a['last_reply'] === null ? null : ['answered_at' => json_ts($a['last_reply']['answered_at']), 'excerpt' => $a['last_reply']['excerpt'], 'channel_id' => $a['last_reply']['channel_id'], 'message_id' => $a['last_reply']['message_id']],
            'pending' => $a['pending'], 'running' => $a['running'], 'failed' => $a['failed'], 'answered' => $a['answered']];
}

function present_share_read(array $r): array
{
    return ['occurred_at' => json_ts($r['occurred_at']), 'application' => $r['application'], 'tool' => $r['tool'], 'rows' => $r['rows']];
}

/** The OS's AI Ops link for a run (a reply by an agent), for a holder of agents.settings — null when the launcher is not configured or there is no run. */
function os_run_url(?int $runId): ?string
{
    $base = rtrim((string) env('OS_LAUNCHER_URL', ''), '/');
    return $base === '' || $runId === null ? null : $base . '/ai/runs/' . $runId;
}
