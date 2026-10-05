<?php
declare(strict_types=1);

/** The prelude of a message handler (slice 4): the channel feature, the message a request names (through the row function — 404 when unseen), the gates. */
require_once dirname(__DIR__) . '/channels/handler.php';
require_once dirname(__DIR__) . '/blocks/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The message the request names (`message` or `id`), with its channel (gated: the channel must be readable). Returns [message, channel]. */
function message_from_request(PDO $pdo): array
{
    $id = request_integer('message') ?? request_integer('id') ?? refuse(422, 'Say which message.');
    $m = message_row($pdo, $id) ?? refuse(404, 'Message not found.');
    $c = find_channel($pdo, (int) $m['channel_id']) ?? refuse(404, 'Channel not found.');
    return [$m, $c];
}

/** The posting gate: sp_can_post() — in the channel, live, the right held; 404 when unseen. */
function require_posting(array $c): void
{
    require_login();
    if ($c['archived_at'] !== null) {
        refuse(422, 'Channel ' . $c['label'] . ' is archived: nothing is posted in it.');
    }
    if (!can_post($c['channel_id'])) {
        refuse(403, $c['i_am_member'] ? 'You may not post in ' . $c['label'] . '.' : 'You are not in ' . $c['label'] . '.');
    }
}

/** A row of the trail about a message: channel_id, space_id, message_id; never the words. */
function message_log(PDO $pdo, string $action, array $c, int $messageId, array $after = []): void
{
    log_activity($pdo, $action, 'message', $messageId, ['channel_id' => $c['channel_id'], 'space_id' => $c['space_id'], 'message_id' => $messageId, 'after' => ['message_id' => $messageId] + $after]);
}

/** The facts a message's log carries: length, mentions, attachments, the schedule. */
function message_facts(array $body, array $attachments, ?string $scheduled): array
{
    $mentions = [];
    foreach ($body as $r) { if (($r['type'] ?? '') === 'mention') { $mentions[] = ($r['mention']['type'] ?? 'member') . ':' . ($r['mention']['id'] ?? ''); } }
    return ['length' => mb_strlen(implode('', array_map(static fn (array $r): string => (string) ($r['plain_text'] ?? ''), $body))), 'mentions' => $mentions, 'has_attachments' => $attachments !== [], 'scheduled_for' => $scheduled];
}

/** The attachment ids a post carries: `attachments[]` or `attachments` (comma-separated), each one the caller uploaded onto a message slot. */
function attachment_ids_from_request(): array
{
    $raw = $_POST['attachments'] ?? ($_GET['attachments'] ?? []);
    if (is_string($raw)) { $raw = array_filter(array_map('trim', explode(',', $raw))); }
    return array_values(array_unique(array_filter(array_map('intval', (array) $raw), static fn (int $v): bool => $v > 0)));
}

/** The time a request names (`schedule_for`, `remind_at`, `muted_until`), in the member's zone → UTC ISO; '' → null; a bad one → 422 in words. */
function request_time(string $field, bool $future = true): ?string
{
    $raw = trim((string) (req_val($field) ?? ''));
    if ($raw === '') { return null; }
    try {
        $t = new DateTimeImmutable($raw, new DateTimeZone(member_timezone()));
    } catch (Throwable) {
        sp_refuse_fields([$field => 'Give a time, like 2026-10-06 09:00.']);
    }
    if ($future && $t->getTimestamp() < time() + 30) {
        sp_refuse_fields([$field => 'The time must be in the future.']);
    }
    return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
}

/** The message's rendered row for the reply of a write (the composer appends it; the poll swaps it). */
function message_html(PDO $pdo, array $m, array $c, array $opts = []): string
{
    return view('channels/partials/message-row.php', ['m' => $m, 'c' => $c, 'me' => (int) current_member_id(), 'tz' => member_timezone(), 'inThread' => $opts['in_thread'] ?? false, 'may' => $opts['may'] ?? message_may($c), 'oob' => $opts['oob'] ?? false]);
}

/** What the caller may do to messages in a channel (the row's menu). */
function message_may(array $c): array
{
    return ['post' => $c['archived_at'] === null && can_post($c['channel_id']), 'manage' => channel_owner($c), 'member' => $c['i_am_member'], 'human' => (current_member()['member_kind'] ?? '') === 'human'];
}

/** A write's reply: the row's HTML and the hashes, with HX-Trigger messageChanged. */
function message_reply(PDO $pdo, int $messageId, array $c, string $did, array $extra = []): never
{
    $m = message_row($pdo, $messageId);
    $land = channel_path($c) . ($m !== null && $m['thread_root_id'] !== null ? '/threads/' . $m['thread_root_id'] : '') . '#message-row-' . $messageId;
    $payload = $m === null ? [] : ['message' => present_message($m), 'html' => message_html($pdo, $m, $c, ['in_thread' => $m['thread_root_id'] !== null])];
    sp_done($did, $messageId, sp_land(return_path($land), 'posted'), 'messageChanged', $payload + $extra);
}

/** The body a post carries: `runs` (the composer's JSON) or `markdown` (the converter); a person may shout, an agent's shouts are stripped. */
function message_body_from_request(PDO $pdo, array $c, bool $human): array
{
    $body = [];
    if (req_has('runs') && (string) req_val('runs') !== '') {
        $runs = json_decode((string) req_val('runs'), true);
        if (!is_array($runs)) { sp_refuse_fields(['runs' => 'The composer\'s runs are a JSON list.']); }
        foreach ($runs as $r) { if (is_array($r) && isset($r['type'])) { $body[] = $r; } }
        $body = message_shout_runs($body, $human && !in_array($c['kind'], ['dm', 'group_dm'], true));
    } elseif (req_has('markdown')) {
        $md = (string) req_val('markdown');
        if (mb_strlen($md) > 20000) { sp_refuse_fields(['markdown' => 'A message is at most 20,000 characters.']); }
        $body = trim($md) === '' ? [] : message_body_from_markdown($pdo, $md, $c['channel_id'], $human && !in_array($c['kind'], ['dm', 'group_dm'], true));
    }
    if (!$human) { $body = strip_shouts($body); }
    return $body;
}

/** True under an action or run token (an agent's or a person's API call): shouts come only from a person at the keyboard or a person's token. */
function is_action_authed_agent(): bool
{
    return (current_member()['member_kind'] ?? '') === 'agent';
}
