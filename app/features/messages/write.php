<?php
declare(strict_types=1);

/** Writes to messages (slice 4): post, reply, edit, delete, schedule, react, save; an agent's shouts stripped. The guards of db/010 decide. */

/** Post a message: in a channel, or in a thread (the root given); scheduled when a time is given; the attachments re-pointed. Returns the id. */
function post_message(PDO $pdo, int $channelId, array $body, ?int $threadRoot, bool $alsoToChannel, ?string $scheduleFor, array $attachmentIds, int $by): int
{
    $st = $pdo->prepare('INSERT INTO messages (channel_id, thread_root_id, author_member_id, body, also_to_channel, scheduled_for, agent_run_id, attachment_count)
                         VALUES (:c, :r, :by, CAST(:b AS jsonb), :also, CAST(:s AS timestamptz), :run, :n) RETURNING id');
    $st->execute(['c' => $channelId, 'r' => $threadRoot, 'by' => $by, 'b' => json_encode($body, JSON_UNESCAPED_UNICODE), 'also' => $alsoToChannel && $threadRoot !== null ? 't' : 'f', 's' => $scheduleFor, 'run' => current_agent_run_id(), 'n' => count($attachmentIds)]);
    $id = (int) $st->fetchColumn();
    if ($attachmentIds !== []) {
        $u = $pdo->prepare("UPDATE attachments SET record_id = :m WHERE id = :a AND record_type = 'message' AND uploaded_by = :by AND (record_id IS NULL OR record_id = 0)");
        $n = 0;
        foreach ($attachmentIds as $a) { $u->execute(['m' => $id, 'a' => $a, 'by' => $by]); $n += $u->rowCount(); }
        $pdo->prepare('UPDATE messages SET attachment_count = :n WHERE id = :id')->execute(['n' => $n, 'id' => $id]);
    }
    return $id;
}

function edit_message(PDO $pdo, int $id, array $body): void
{
    $st = $pdo->prepare('UPDATE messages SET body = CAST(:b AS jsonb) WHERE id = :id AND deleted_at IS NULL');
    $st->execute(['b' => json_encode($body, JSON_UNESCAPED_UNICODE), 'id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
}

function delete_message(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare('UPDATE messages SET deleted_at = now(), deleted_by = :by WHERE id = :id AND deleted_at IS NULL');
    $st->execute(['by' => $by, 'id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('That message is gone already.');
    }
    $pdo->prepare("DELETE FROM attachments WHERE record_type = 'message' AND record_id = :id")->execute(['id' => $id]);
}

/** A scheduled message of mine: a new time, or sent now (null). */
function schedule_message(PDO $pdo, int $id, ?string $when): void
{
    $st = $pdo->prepare($when === null ? 'UPDATE messages SET scheduled_for = NULL, sent_at = now() WHERE id = :id AND sent_at IS NULL AND deleted_at IS NULL' : 'UPDATE messages SET scheduled_for = CAST(:w AS timestamptz) WHERE id = :id AND sent_at IS NULL AND deleted_at IS NULL');
    $st->execute($when === null ? ['id' => $id] : ['id' => $id, 'w' => $when]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('That message was sent already.');
    }
}

/** A scheduled message discarded: the row goes (it was never sent). */
function unschedule_message(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('DELETE FROM messages WHERE id = :id AND sent_at IS NULL');
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('That message was sent already.');
    }
}

function toggle_reaction(PDO $pdo, int $messageId, int $memberId, string $emoji, bool $on): bool
{
    $st = $pdo->prepare($on ? 'INSERT INTO message_reactions (message_id, member_id, emoji) VALUES (:m, :me, :e) ON CONFLICT DO NOTHING' : 'DELETE FROM message_reactions WHERE message_id = :m AND member_id = :me AND emoji = :e');
    $st->execute(['m' => $messageId, 'me' => $memberId, 'e' => $emoji]);
    return $st->rowCount() === 1;
}

function save_message(PDO $pdo, int $messageId, int $memberId, bool $on): bool
{
    $st = $pdo->prepare($on ? 'INSERT INTO saved_messages (member_id, message_id) VALUES (:me, :m) ON CONFLICT DO NOTHING' : 'DELETE FROM saved_messages WHERE member_id = :me AND message_id = :m');
    $st->execute(['me' => $memberId, 'm' => $messageId]);
    return $st->rowCount() === 1;
}

/** An agent never shouts: @channel, @here and @everyone runs become plain text (the manifest's channel_announce is the explicit, paused way). */
function strip_shouts(array $body): array
{
    foreach ($body as &$r) {
        if (($r['type'] ?? '') === 'mention' && in_array($r['mention']['type'] ?? '', ['channel', 'here', 'everyone'], true)) {
            $r = ['type' => 'text', 'text' => ['content' => '@' . $r['mention']['type'], 'link' => null], 'annotations' => [], 'plain_text' => '@' . $r['mention']['type']];
        }
    }
    unset($r);
    return $body;
}

/** The message body from Markdown: the inline converter (mentions resolved as the caller sees people; a shout as a mention run for a person). */
function message_body_from_markdown(PDO $pdo, string $markdown, int $channelId, bool $shoutsAllowed): array
{
    $ctx = markdown_context($pdo);
    $ctx['shouts'] = $shoutsAllowed;
    $lines = preg_split('/\R/', trim($markdown)) ?: [];
    $runs = [];
    foreach ($lines as $i => $line) {
        if ($i > 0) { $runs[] = ['type' => 'text', 'text' => ['content' => "\n", 'link' => null], 'annotations' => [], 'plain_text' => "\n"]; }
        foreach (markdown_inline_runs($line, $ctx) as $r) { $runs[] = $r; }
    }
    $runs = message_shout_runs($runs, $shoutsAllowed);
    return $runs;
}

/** "@channel", "@here", "@everyone" as words → mention runs (a person's); an agent's stay words. */
function message_shout_runs(array $runs, bool $allowed): array
{
    if (!$allowed) { return $runs; }
    $out = [];
    foreach ($runs as $r) {
        if (($r['type'] ?? '') !== 'text' || !preg_match('/@(channel|here|everyone)\b/', (string) ($r['text']['content'] ?? ''))) { $out[] = $r; continue; }
        $parts = preg_split('/(@(?:channel|here|everyone)\b)/', (string) $r['text']['content'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $p) {
            if (preg_match('/^@(channel|here|everyone)$/', $p, $m)) { $out[] = ['type' => 'mention', 'mention' => ['type' => $m[1], 'id' => '', 'name' => $m[1]], 'plain_text' => $p]; }
            else { $x = $r; $x['text']['content'] = $p; $x['plain_text'] = $p; $out[] = $x; }
        }
    }
    return $out;
}
