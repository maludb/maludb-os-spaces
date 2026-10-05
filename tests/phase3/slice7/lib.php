<?php
/**
 * Helpers for the slice 7 proofs (docs/build-specs/agents-in-spaces.md, "Proof"). Builds on slice 6's lib (the chain: 6 → 5 → 4 → 3 → 2 → 1 → Phase 2). agents_world() adds, over channel_world()
 * (Seamus 40 an agent in General, Product and #smoke-launch; Marco owner of Product; Priya, Dana members): the Librarian (42, an agent made by an incremental feed, admitted as a Member and added to
 * Product and #smoke-launch) and the Watcher (41, an agent the kernel never vouched for — not admitted). The worker is `php bin/worker.php dispatches`, run as a process, against the fake kernel
 * (:8402) whose chat endpoint is scripted by the state file (kernel_chat()). Everything a proof makes is named "SMOKE …".
 */
require dirname(__DIR__) . '/slice6/lib.php';

/** Script the fake kernel's chat endpoint: ['mode' => reply|running|approval|error500, 'status' => a refusal status|null, 'message' => its sentence, 'reply' => text, 'pending' => run_pending, 'run_reply' => text, 'run_status' => 404|null]. */
function kernel_chat(array $s): void
{
    kernel_state(function ($st) use ($s) {
        foreach (['chat_mode', 'chat_status', 'chat_message', 'run_pending', 'run_reply', 'run_status', 'chat'] as $k) { unset($st[$k]); }
        if (isset($s['mode'])) { $st['chat_mode'] = $s['mode']; }
        if (isset($s['status'])) { $st['chat_status'] = $s['status']; }
        if (isset($s['message'])) { $st['chat_message'] = $s['message']; }
        if (array_key_exists('pending', $s)) { $st['run_pending'] = (bool) $s['pending']; }
        if (isset($s['run_reply'])) { $st['run_reply'] = $s['run_reply']; }
        if (isset($s['run_status'])) { $st['run_status'] = $s['run_status']; }
        if (isset($s['reply'])) { $st['chat'] = ['run_id' => 'n', 'status' => 'succeeded', 'finished' => true, 'reply' => $s['reply'], 'actions' => [], 'cost' => 0.0345, 'currency' => 'USD']; }
        return $st;
    });
}
/** The calls the fake kernel has seen (one per line of its .chat log): [{agent, acting, utterance, conversation_id, context, wait, poll}]. */
function chat_log(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.chat';
    return array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) @file_get_contents($f)))));
}
function chat_calls(): int { return count(chat_log()); }
/** One pass of the worker's step, as a process (the proof's environment is inherited): the counts it printed. */
function worker_pass(array $env = []): array
{
    $cmd = '';
    foreach ($env as $k => $v) { $cmd .= $k . '=' . escapeshellarg((string) $v) . ' '; }
    $out = trim((string) shell_exec($cmd . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' dispatches 2>&1'));
    return json_decode($out, true) ?? ['raw' => $out];
}
function dispatch_db(int $id): array { return q('SELECT id, record_id, agent_member_id, acting_member_id, kind, status, run_id, attempts, detail, next_attempt_at, pending_message_id, reply_message_id, reply_excerpt, conversation_id, channel_id FROM agent_dispatches WHERE id = :d', ['d' => $id])[0] ?? []; }
function dispatch_of(int $messageId, int $agent): array { return q("SELECT id FROM agent_dispatches WHERE record_type = 'message' AND record_id = :m AND agent_member_id = :a", ['m' => $messageId, 'a' => $agent])[0] ?? []; }
function dispatch_id_of(int $messageId, int $agent): int { return (int) (dispatch_of($messageId, $agent)['id'] ?? 0); }
/** A dispatch due now (a proof does not wait out a backoff). */
function make_due(int $d): void { pdo()->exec("UPDATE agent_dispatches SET next_attempt_at = now() - interval '1 minute' WHERE id = $d"); }
/** The runs of a message body (decoded). */
function body_runs(int $messageId): array { return json_decode((string) one('SELECT body::text FROM messages WHERE id = :m', ['m' => $messageId]), true) ?: []; }
function activity_after(string $action, int $since): array { return q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id', ['a' => $action, 's' => $since]); }

function agents_world(): array
{
    $w = channel_world();
    $marco = as_member(27);
    if (one('SELECT 1 FROM members WHERE id = 42') === false || one('SELECT 1 FROM members WHERE id = 42') === null) {
        kernel_state(function ($s) {
            $s['incremental'] = incr(['members' => [['id' => 42, 'display_name' => 'SMOKE Librarian', 'email' => null, 'member_kind' => 'agent', 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'job_title' => 'Knowledge Librarian',
                'phone' => null, 'timezone' => 'UTC', 'departments' => [], 'updated_at' => '2026-10-05T00:00:00Z']],
                'access' => [['member_id' => 42, 'role' => 'user', 'roles' => ['user'], 'capability' => 'write', 'scopes' => []]]], '2026-10-05T00:00:00.000000Z');
            return $s;
        });
        sync();
        kernel_state(function ($s) { unset($s['incremental']); return $s; });
    }
    if (!in_channel($w['launch'], 42)) {
        act($marco, '/spaces/members/add.php', ['space' => $w['product'], 'member' => 42]);
        act($marco, '/channels/members/add.php', ['channel' => $w['launch'], 'member' => 42]);
    }
    return $w + ['seamus' => 40, 'librarian' => 42];
}
/** The ids the browser proof drives: a thread with a run going (the placeholder), an answered one, two proposals (one with a draft), a failed dispatch. */
function agents_browser_world(): array
{
    $w = agents_world();
    $priya = as_member(26); $marco = as_member(27);
    $launch = $w['launch']; $product = $w['product'];
    kernel_state(function ($s) { $s['facts']['4301'] = ['valid' => true, 'is_agent' => true, 'member_id' => 42, 'run_id' => 4301, 'request_id' => 'req-4301', 'trigger' => 'duty', 'endpoints' => [['name' => 'Actions MCP']]]; return $s; });
    $lib = as_agent(run_token(42, 4301));
    kernel_chat(['reply' => 'SMOKE browser reply: the launch is **Friday**.']);
    [, $b] = post($priya, $launch, '@SMOKE Seamus browser question one');
    $answered = (int) $b['record_id'];
    worker_pass();
    kernel_chat(['mode' => 'error500']);
    [, $b] = post($priya, $launch, '@SMOKE Librarian browser failure');
    $failed = dispatch_id_of((int) $b['record_id'], 42);
    for ($i = 0; $i < 6; $i++) { make_due($failed); worker_pass(); }
    kernel_chat(['mode' => 'running', 'pending' => true]);
    [, $b] = post($priya, $launch, '@SMOKE Seamus browser question two');
    $running = (int) $b['record_id'];
    worker_pass();
    kernel_chat(['mode' => 'reply']);
    [, $b] = post($priya, $launch, 'SMOKE We decided: browser banner stays blue.');
    $root = (int) $b['record_id'];
    [, $b] = act_token('/pages/save.php', ['title' => 'SMOKE Browser draft', 'space' => $product, 'markdown' => 'draft'], $lib);
    $draft = (string) $b['record_id'];
    [, $b] = act_token('/proposals/save.php', ['kind' => 'thread_to_page', 'title' => 'SMOKE Browser: make it a page', 'reason' => 'A decision in a thread.', 'message' => $root, 'proposed_page' => $draft], $lib);
    $p1 = (int) $b['record_id'];
    [, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Browser orphan', 'space' => $product]);
    [, $b] = act_token('/proposals/save.php', ['kind' => 'orphan', 'title' => 'SMOKE Browser: an orphan', 'reason' => 'Nothing links here.', 'page' => (string) $b['record_id']], $lib);
    $p2 = (int) $b['record_id'];
    [, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Browser parent', 'space' => $product]);
    return ['product' => $product, 'launch' => $launch, 'answered' => $answered, 'running' => $running, 'running_dispatch' => dispatch_id_of($running, 40), 'failed_dispatch' => $failed, 'proposal_thread' => $p1, 'proposal_orphan' => $p2, 'draft' => $draft, 'parent' => (string) $b['record_id']];
}
