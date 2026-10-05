<?php
declare(strict_types=1);

/**
 * The dispatch loop (slice 7, docs/build-specs/agents-in-spaces.md): the worker's step `dispatches`. sp_dispatches_due() offers what is
 * due; each is ONE turn of the kernel's chat endpoint as that agent, under the asker's identity (design §5, D10); the answer goes back
 * through sp_dispatch_record(), which keeps the placeholder, the reply as one row, the backoff and the five attempts — PHP never decides
 * whether an agent should answer. Nothing here calls a model and nothing holds a key. A run still going (202) is polled by the next pass.
 * Never a message's words, a reply or a context in a log payload.
 */
require_once dirname(__DIR__, 2) . '/richtext/render.php';
require_once dirname(__DIR__, 2) . '/richtext/markdown.php';
require_once dirname(__DIR__) . '/blocks/queries.php';
require_once dirname(__DIR__) . '/messages/write.php';

const DISPATCH_WAIT_SECONDS = 25;
const DISPATCH_RUN_LIMIT_SECONDS = 600;        // a run that has not finished in 10 minutes failed

/** The "now" of a pass: SP_WORKER_NOW (a proof's clock) or the real one. */
function worker_now(): DateTimeImmutable
{
    $t = getenv('SP_WORKER_NOW');
    try {
        return $t !== false && $t !== '' ? new DateTimeImmutable($t) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Exception) {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

/**
 * What the agent is told besides the utterance: who it is, why it was asked, the thread so far, the manners. $d carries `agent_name`,
 * `channel_kind`, `channel_name`, `space_name` (dispatch_enrich) and `context` (the last 12 turns as "Name: text").
 */
function dispatch_context(array $d): string
{
    $name = (string) ($d['agent_name'] ?? 'the agent');
    $where = in_array($d['channel_kind'] ?? '', ['dm', 'group_dm'], true)
        ? 'sent a direct message'
        : 'mentioned in #' . (string) ($d['channel_name'] ?? '') . (($d['space_name'] ?? '') !== '' ? ' of space ' . $d['space_name'] : '');
    $so = in_array($d['channel_kind'] ?? '', ['dm', 'group_dm'], true) ? 'The conversation so far' : 'The thread so far';
    return 'You are ' . $name . ', a member of Spaces. You were ' . $where . ".\n" . $so . ":\n" . trim((string) ($d['context'] ?? ''))
        . "\nAnswer in this " . (in_array($d['channel_kind'] ?? '', ['dm', 'group_dm'], true) ? 'conversation' : 'thread') . ", once, as yourself, citing the page or message you took it from. Never write @channel, @here or @everyone.";
}

/** The facts a dispatch row needs beside what sp_dispatches_due() gives: the agent's name, the space's id and name. */
function dispatch_enrich(PDO $pdo, array $d): array
{
    $st = $pdo->prepare('SELECT (SELECT display_name FROM members WHERE id = :a) AS agent_name, c.space_id, s.name AS space_name FROM channels c LEFT JOIN spaces s ON s.id = c.space_id WHERE c.id = :c');
    $st->execute(['a' => (int) $d['agent_member_id'], 'c' => (int) $d['channel_id']]);
    return $d + (($st->fetch() ?: []) + ['agent_name' => null, 'space_id' => null, 'space_name' => null]);
}

/**
 * The agent's Markdown as the message's rich text: the inline converter as the AGENT sees people (an @Name becomes a mention run only for a
 * member the agent may see), and every @channel / @here / @everyone left as plain words — an agent never shouts (slice 4's rule).
 */
function reply_to_rich_text(PDO $pdo, string $markdown, int $agentId): array
{
    $was = (string) $pdo->query("SELECT COALESCE(current_setting('app.member_id', true), '')")->fetchColumn();
    $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => (string) $agentId]);
    try {
        $runs = message_body_from_markdown($pdo, $markdown, 0, false);
    } finally {
        $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => $was]);
    }
    return strip_shouts($runs);
}

/** sp_dispatch_record(): the one call that writes the outcome. Returns the reply/placeholder message id or null. */
function dispatch_record(PDO $pdo, int $id, string $status, ?int $runId = null, ?string $requestId = null, ?array $reply = null, ?string $detail = null): ?int
{
    $st = $pdo->prepare('SELECT sp_dispatch_record(:d, :s, :r, :q, CAST(:b AS jsonb), :t)');
    $st->execute(['d' => $id, 's' => $status, 'r' => $runId, 'q' => $requestId, 'b' => $reply === null ? null : json_encode($reply, JSON_UNESCAPED_UNICODE), 't' => $detail === null ? null : mb_substr($detail, 0, 500)]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (int) $v;
}

/** Log a dispatch event: the ids, never the words. The actor is the asker for a call, the agent for what it did. */
function dispatch_log(PDO $pdo, string $action, array $d, array $after = [], bool $asAgent = false): void
{
    log_activity($pdo, $action, 'message', (int) $d['message_id'], [
        'actor_member_id' => $asAgent ? (int) $d['agent_member_id'] : ($d['acting_member_id'] === null ? null : (int) $d['acting_member_id']),
        'channel_id' => (int) $d['channel_id'], 'space_id' => $d['space_id'] === null ? null : (int) $d['space_id'], 'message_id' => (int) $d['message_id'],
        'agent_run_id' => isset($after['run_id']) ? (int) $after['run_id'] : null,
        'after' => ['dispatch_id' => (int) $d['dispatch_id'], 'agent_member_id' => (int) $d['agent_member_id'], 'kind' => $d['kind']] + $after,
    ]);
}

/** A failure told once: the log row, the record with its backoff, and — when it was the fifth — the asker's notice. Returns 'failed' (retried later or for good). */
function dispatch_fail(PDO $pdo, array $d, string $status, string $detail, ?int $run = null): string
{
    $detail = mb_substr($detail, 0, 200);
    dispatch_record($pdo, (int) $d['dispatch_id'], $status, null, null, null, $detail);
    $now = $pdo->prepare('SELECT status, attempts FROM agent_dispatches WHERE id = :d');
    $now->execute(['d' => (int) $d['dispatch_id']]);
    $row = $now->fetch();
    dispatch_log($pdo, 'agent.fail', $d, ['status' => $status, 'attempts' => (int) ($row['attempts'] ?? 0), 'detail' => $detail] + ($run !== null ? ['run_id' => $run] : []), true);
    if ($status === 'failed' && ($row['status'] ?? '') === 'failed' && $d['acting_member_id'] !== null) {        // the fifth: the asker is told, once
        $pdo->prepare("SELECT sp_notify(:m, 'agent_replied', :t, :b, 'message', :mid, NULL, :c, :mid)")
            ->execute(['m' => (int) $d['acting_member_id'], 't' => ($d['agent_name'] ?? 'The agent') . ' could not answer', 'b' => $detail, 'mid' => (int) $d['message_id'], 'c' => (int) $d['channel_id']]);
    }
    return $status === 'refused' ? 'refused' : 'failed';
}

/** What a finished answer of the kernel does: the reply posted, or a failure the run itself reported. */
function dispatch_finish(PDO $pdo, array $d, array $body, ?int $runId, ?string $requestId): string
{
    $status = (string) ($body['status'] ?? 'succeeded');
    if (in_array($status, ['failed', 'error', 'errored', 'cancelled', 'canceled', 'timeout'], true) || (!empty($body['error']) && trim((string) ($body['reply'] ?? '')) === '')) {
        $why = is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : (string) ($body['error'] ?? '');
        return dispatch_fail($pdo, $d, 'failed', $why !== '' ? $why : 'The run ended: ' . $status . '.', $runId);
    }
    $reply = trim((string) ($body['reply'] ?? ''));
    $rich = $reply === '' ? null : reply_to_rich_text($pdo, mb_substr($reply, 0, 8000), (int) $d['agent_member_id']);
    $pdo->beginTransaction();
    try {
        dispatch_record($pdo, (int) $d['dispatch_id'], 'answered', $runId, $requestId, $rich, null);
        dispatch_log($pdo, 'agent.reply', $d, ['run_id' => $runId, 'request_id' => $requestId, 'reply_length' => mb_strlen($reply), 'cost' => $body['cost'] ?? null, 'currency' => $body['currency'] ?? null], true);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return 'answered';
}

/**
 * One dispatch: the kernel's chat endpoint as the agent, the asker acting. Returns ['outcome' => answered|running|awaiting|refused|failed, 'http' => ?int].
 * $d is a row of sp_dispatches_due() (enriched here).
 */
function dispatch_agent(PDO $pdo, array $d): array
{
    $d = isset($d['space_name']) || isset($d['agent_name']) ? $d : dispatch_enrich($pdo, $d);
    $d += ['agent_name' => null, 'space_id' => null, 'space_name' => null];
    dispatch_log($pdo, 'agent.dispatch', $d);
    $answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . (int) $d['agent_member_id'], [
        'utterance' => mb_substr((string) $d['utterance'], 0, 4000), 'conversation_id' => (string) $d['conversation_id'], 'context' => dispatch_context($d), 'wait' => DISPATCH_WAIT_SECONDS,
    ], ['X-Acting-Member: ' . (int) $d['acting_member_id']], DISPATCH_WAIT_SECONDS + 15);
    $http = $answer['status'] ?? null;
    if ($answer === null || $http === 401 || $http >= 500) {
        return ['outcome' => dispatch_fail($pdo, $d, 'failed', $answer === null ? 'The kernel did not answer.' : 'The kernel answered ' . $http . '.'), 'http' => $http];
    }
    $body = $answer['body'] ?? [];
    if ($http === 409) {                                           // the agent is busy with another turn: not a refusal — the backoff retries it
        $why = is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : '';
        return ['outcome' => dispatch_fail($pdo, $d, 'failed', $why !== '' ? $why : 'The agent is busy with another turn.'), 'http' => $http];
    }
    if ($http >= 400) {
        $why = is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : '';
        return ['outcome' => dispatch_fail($pdo, $d, 'refused', $why !== '' ? $why : 'The kernel refused this turn (' . $http . ').'), 'http' => $http];
    }
    $runId = isset($body['run_id']) ? (int) $body['run_id'] : null;
    $requestId = isset($body['request_id']) ? (string) $body['request_id'] : $answer['request_id'];
    $state = (string) ($body['status'] ?? '');
    if ($state === 'pending_approval' || (empty($body['finished']) && !empty($body['approval_request_id']))) {
        $detail = 'Waiting for a person\'s approval (request ' . (int) ($body['approval_request_id'] ?? 0) . ')';
        dispatch_record($pdo, (int) $d['dispatch_id'], 'awaiting_approval', $runId, $requestId, null, $detail);   // keeps the run and the placeholder; polled until the person decides
        dispatch_log($pdo, 'agent.dispatch', $d, ['status' => 'awaiting_approval', 'approval_request_id' => (int) ($body['approval_request_id'] ?? 0), 'run_id' => $runId]);
        return ['outcome' => 'awaiting', 'http' => $http];
    }
    if (empty($body['finished'])) {
        if ($runId === null) {
            return ['outcome' => dispatch_fail($pdo, $d, 'failed', 'The kernel started no run.'), 'http' => $http];
        }
        dispatch_record($pdo, (int) $d['dispatch_id'], 'running', $runId, $requestId);
        return ['outcome' => 'running', 'http' => $http];
    }
    return ['outcome' => dispatch_finish($pdo, $d, $body, $runId, $requestId), 'http' => $http];
}

/**
 * The runs the kernel is still going on: GET ?run= for each (asker acting); finished → the placeholder becomes the reply; ten minutes → failed.
 * Returns ['polled', 'answered', 'failed', 'running'].
 */
function poll_running_dispatches(PDO $pdo, DateTimeImmutable $now): array
{
    $out = ['polled' => 0, 'answered' => 0, 'failed' => 0, 'running' => 0];
    $rows = $pdo->query("SELECT d.id AS dispatch_id, d.agent_member_id, d.acting_member_id, d.kind, d.channel_id, d.run_id, d.request_id, d.record_id AS message_id, d.conversation_id,
                                COALESCE(pm.created_at, d.created_at) AS started_at
                           FROM agent_dispatches d LEFT JOIN messages pm ON pm.id = d.pending_message_id
                          WHERE d.status = 'sent' AND d.run_id IS NOT NULL AND d.record_type = 'message' ORDER BY d.created_at LIMIT 50")->fetchAll();
    foreach ($rows as $r) {
        $out['polled']++;
        try {
            $d = dispatch_enrich($pdo, $r);
            $age = $now->getTimestamp() - (new DateTimeImmutable((string) $r['started_at']))->getTimestamp();
            $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $r['run_id'], null, ['X-Acting-Member: ' . (int) $r['acting_member_id']], 20);
            $http = $answer['status'] ?? null;
            $body = $answer['body'] ?? [];
            if ($answer !== null && $http >= 200 && $http < 300 && !empty($body['finished'])) {
                $res = dispatch_finish($pdo, $d, $body, (int) $r['run_id'], $r['request_id'] ?? null);
                $out[$res === 'answered' ? 'answered' : 'failed']++;
            } elseif ($answer !== null && $http >= 400 && $http < 500) {
                dispatch_fail($pdo, $d, 'failed', 'The kernel does not know that run (' . $http . ').', (int) $r['run_id']);
                $out['failed']++;
            } elseif ($age > DISPATCH_RUN_LIMIT_SECONDS) {
                dispatch_fail($pdo, $d, 'failed', 'The run did not finish.', (int) $r['run_id']);
                $out['failed']++;
            } else {
                $out['running']++;                                 // still going (or the kernel did not answer this time): the next pass asks again
            }
        } catch (Throwable $e) {
            error_log('poll of dispatch ' . $r['dispatch_id'] . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $out['running']++;
        }
    }
    return $out;
}

const WAITING_DECLINED = ['declined', 'rejected', 'refused', 'denied', 'cancelled', 'canceled'];

/**
 * The dispatches waiting for a person's approval (sp_dispatches_waiting(): a run kept, due for a poll): GET ?run= for each. The run answered → the reply is posted;
 * the kernel says it was declined, refused or is gone (a 4xx) → `refused` with its sentence and the placeholder removed; still going or still awaiting (or the kernel
 * did not answer) → 'awaiting_approval' again, which moves next_attempt_at on. Returns ['polled', 'answered', 'refused', 'awaiting'].
 */
function poll_waiting_dispatches(PDO $pdo): array
{
    $out = ['polled' => 0, 'answered' => 0, 'refused' => 0, 'awaiting' => 0];
    $rows = $pdo->query('SELECT w.*, d.kind FROM sp_dispatches_waiting(50) w JOIN agent_dispatches d ON d.id = w.dispatch_id')->fetchAll();
    foreach ($rows as $r) {
        $out['polled']++;
        try {
            $d = dispatch_enrich($pdo, $r);
            $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $r['run_id'], null, ['X-Acting-Member: ' . (int) $r['acting_member_id']], 20);
            $http = $answer['status'] ?? null;
            $body = $answer['body'] ?? [];
            $state = (string) ($body['status'] ?? '');
            $why = is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : (string) ($body['error'] ?? '');
            if ($answer !== null && $http >= 400 && $http < 500) {
                dispatch_fail($pdo, $d, 'refused', $why !== '' ? $why : 'The kernel does not know that run (' . $http . ').', (int) $r['run_id']);
                $out['refused']++;
            } elseif ($answer !== null && $http < 300 && in_array($state, WAITING_DECLINED, true)) {
                dispatch_fail($pdo, $d, 'refused', $why !== '' ? $why : 'The approval was declined.', (int) $r['run_id']);
                $out['refused']++;
            } elseif ($answer !== null && $http < 300 && !empty($body['finished'])) {
                $res = dispatch_finish($pdo, $d, $body, (int) $r['run_id'], $r['request_id'] ?? null);
                $out[$res === 'answered' ? 'answered' : 'refused']++;
            } else {
                dispatch_record($pdo, (int) $r['dispatch_id'], 'awaiting_approval', null, null, null, null);
                $out['awaiting']++;
            }
        } catch (Throwable $e) {
            error_log('poll of waiting dispatch ' . $r['dispatch_id'] . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }
    return $out;
}

/**
 * One pass of the step: the runs going are polled, then what is due (at most $limit) is called. Idempotent — a second pass dispatches nothing new.
 * ['called', 'answered', 'running', 'refused', 'failed', 'polled', 'awaiting'].
 */
function dispatches_pass(PDO $pdo, DateTimeImmutable $now, int $limit): array
{
    $out = ['called' => 0, 'answered' => 0, 'running' => 0, 'refused' => 0, 'failed' => 0, 'polled' => 0, 'awaiting' => 0];
    $polled = poll_running_dispatches($pdo, $now);
    $out['polled'] = $polled['polled'];
    $out['answered'] += $polled['answered'];
    $out['failed'] += $polled['failed'];
    $out['running'] += $polled['running'];
    $waited = poll_waiting_dispatches($pdo);
    $out['polled'] += $waited['polled'];
    $out['answered'] += $waited['answered'];
    $out['refused'] += $waited['refused'];
    $out['awaiting'] += $waited['awaiting'];
    $due = $pdo->prepare('SELECT * FROM sp_dispatches_due(:n)');
    $due->execute(['n' => max(1, $limit)]);
    foreach ($due->fetchAll() as $d) {
        $out['called']++;
        try {
            $r = dispatch_agent($pdo, dispatch_enrich($pdo, $d));
        } catch (Throwable $e) {
            error_log('dispatch ' . $d['dispatch_id'] . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $r = ['outcome' => 'failed'];
            try { dispatch_fail($pdo, dispatch_enrich($pdo, $d), 'failed', 'The turn could not be recorded.'); } catch (Throwable) { }
        }
        $out[$r['outcome']] = ($out[$r['outcome']] ?? 0) + 1;
    }
    return $out;
}
