<?php
declare(strict_types=1);

/** Writes to channels (slice 4): the verbs; db/010's guards decide and speak. Every function runs inside the handler's transaction. */

function save_channel(PDO $pdo, ?int $id, array $f, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO channels (space_id, kind, name, topic, purpose, created_by) VALUES (:s, :k, :n, :t, :p, :by) RETURNING id');
        $st->execute(['s' => $f['space_id'], 'k' => $f['kind'], 'n' => $f['name'], 't' => $f['topic'], 'p' => $f['purpose'], 'by' => $by]);
        $id = (int) $st->fetchColumn();
        $pdo->prepare('INSERT INTO channel_members (channel_id, member_id, added_by) VALUES (:c, :m, :m) ON CONFLICT DO NOTHING')->execute(['c' => $id, 'm' => $by]);
        return $id;
    }
    $pdo->prepare('UPDATE channels SET name = :n, topic = :t, purpose = :p, kind = :k WHERE id = :id')->execute(['n' => $f['name'], 't' => $f['topic'], 'p' => $f['purpose'], 'k' => $f['kind'], 'id' => $id]);
    return $id;
}

function archive_channel(PDO $pdo, int $id, bool $archive, int $by): void
{
    $st = $pdo->prepare($archive ? 'UPDATE channels SET archived_at = now(), archived_by = :by WHERE id = :id AND archived_at IS NULL' : 'UPDATE channels SET archived_at = NULL, archived_by = NULL WHERE id = :id AND archived_at IS NOT NULL AND :by = :by');
    $st->execute(['id' => $id, 'by' => $by]);
    if ($st->rowCount() !== 1) {
        throw new DomainException($archive ? 'That channel is archived already.' : 'That channel is not archived.');
    }
}

function delete_channel(PDO $pdo, int $id): void
{
    $c = channel_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($c['archived_at'] === null) {
        throw new DomainException('Archive the channel first; a live channel is not deleted.');
    }
    $pdo->prepare('DELETE FROM channels WHERE id = :id')->execute(['id' => $id]);
}

function join_channel(PDO $pdo, int $id, int $memberId): bool
{
    $c = channel_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($c['kind'] === 'private' && !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM channel_members WHERE channel_id = :c AND member_id = :m)', ['c' => $id, 'm' => $memberId])) {
        throw new DomainException('Channel #' . $c['name'] . ' is private: a member adds you.');
    }
    $st = $pdo->prepare('INSERT INTO channel_members (channel_id, member_id, added_by) VALUES (:c, :m, :m) ON CONFLICT DO NOTHING');
    $st->execute(['c' => $id, 'm' => $memberId]);
    return $st->rowCount() === 1;
}

function leave_channel(PDO $pdo, int $id, int $memberId): void
{
    if (db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM channels c JOIN spaces s ON s.id = c.space_id WHERE c.id = :c AND c.is_default AND s.is_default)', ['c' => $id])) {
        throw new DomainException("Nobody leaves the default space's #general");
    }
    $st = $pdo->prepare('DELETE FROM channel_members WHERE channel_id = :c AND member_id = :m');
    $st->execute(['c' => $id, 'm' => $memberId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('You are not in that channel.');
    }
}

function add_channel_member(PDO $pdo, int $id, int $memberId, int $by): bool
{
    $st = $pdo->prepare('INSERT INTO channel_members (channel_id, member_id, added_by) VALUES (:c, :m, :by) ON CONFLICT DO NOTHING');
    $st->execute(['c' => $id, 'm' => $memberId, 'by' => $by]);
    return $st->rowCount() === 1;
}

function remove_channel_member(PDO $pdo, int $id, int $memberId): void
{
    $st = $pdo->prepare('DELETE FROM channel_members WHERE channel_id = :c AND member_id = :m');
    $st->execute(['c' => $id, 'm' => $memberId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('They are not in that channel.');
    }
}

/** My own settings on a channel: notify, muted_until, starred, section (a follower row is made when missing, for a public channel I may read). */
function set_channel_notify(PDO $pdo, int $id, int $memberId, array $f): void
{
    if (!db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM channel_members WHERE channel_id = :c AND member_id = :m)', ['c' => $id, 'm' => $memberId])) {
        $pdo->prepare('INSERT INTO channel_members (channel_id, member_id, added_by) VALUES (:c, :m, :m)')->execute(['c' => $id, 'm' => $memberId]);
    }
    $sets = [];
    $args = ['c' => $id, 'm' => $memberId];
    if (array_key_exists('notify', $f)) { $sets[] = 'notify = :n'; $args['n'] = $f['notify']; }
    if (array_key_exists('muted_until', $f)) { $sets[] = 'muted_until = CAST(:mu AS timestamptz)'; $args['mu'] = $f['muted_until']; }
    if (array_key_exists('starred', $f)) { $sets[] = 'starred = :st'; $args['st'] = $f['starred'] ? 't' : 'f'; }
    if (array_key_exists('section', $f)) { $sets[] = 'section = :sec'; $args['sec'] = $f['section']; }
    if ($sets === []) { return; }
    $pdo->prepare('UPDATE channel_members SET ' . implode(', ', $sets) . ' WHERE channel_id = :c AND member_id = :m')->execute($args);
}

function pin(PDO $pdo, int $channelId, ?int $messageId, ?string $pageId, int $by): int
{
    $st = $pdo->prepare('INSERT INTO channel_pins (channel_id, message_id, page_id, pinned_by) VALUES (:c, :m, CAST(:p AS uuid), :by) ON CONFLICT DO NOTHING RETURNING id');
    $st->execute(['c' => $channelId, 'm' => $messageId, 'p' => $pageId, 'by' => $by]);
    $id = $st->fetchColumn();
    if ($id === false) {
        throw new DomainException('That is pinned already.');
    }
    return (int) $id;
}

function unpin(PDO $pdo, int $channelId, ?int $messageId, ?string $pageId): void
{
    $st = $messageId !== null ? $pdo->prepare('DELETE FROM channel_pins WHERE channel_id = :c AND message_id = :x') : $pdo->prepare('DELETE FROM channel_pins WHERE channel_id = :c AND page_id = CAST(:x AS uuid)');
    $st->execute(['c' => $channelId, 'x' => $messageId ?? $pageId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('That is not pinned.');
    }
}

function save_bookmark(PDO $pdo, ?int $id, int $channelId, array $f, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare("INSERT INTO channel_bookmarks (channel_id, title, url, page_id, emoji, position, created_by) VALUES (:c, :t, :u, CAST(:p AS uuid), :e, sp_position_between((SELECT max(position) FROM channel_bookmarks WHERE channel_id = :c), NULL), :by) RETURNING id");
        $st->execute(['c' => $channelId, 't' => $f['title'], 'u' => $f['url'], 'p' => $f['page_id'], 'e' => $f['emoji'], 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $st = $pdo->prepare('UPDATE channel_bookmarks SET title = :t, url = :u, page_id = CAST(:p AS uuid), emoji = :e WHERE id = :id AND channel_id = :c');
    $st->execute(['t' => $f['title'], 'u' => $f['url'], 'p' => $f['page_id'], 'e' => $f['emoji'], 'id' => $id, 'c' => $channelId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
    return $id;
}

function delete_bookmark(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('DELETE FROM channel_bookmarks WHERE id = :id');
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
}

function mark_read(PDO $pdo, int $channelId, int $messageId): void
{
    $st = $pdo->prepare('UPDATE channel_members SET last_read_message_id = greatest(last_read_message_id, :m), last_read_at = now() WHERE channel_id = :c AND member_id = app_current_member_id()');
    $st->execute(['c' => $channelId, 'm' => $messageId]);
    if ($st->rowCount() === 0) {                                  // a follower without a row yet (a public channel read through the space)
        $pdo->prepare('SELECT sp_channel_mark_read(:c, :m)')->execute(['c' => $channelId, 'm' => $messageId]);
    }
}

function open_dm(PDO $pdo, int $other): int
{
    return (int) one_value($pdo, 'SELECT sp_dm_open(:o)', ['o' => $other]);
}

function open_group_dm(PDO $pdo, array $members): int
{
    return (int) one_value($pdo, 'SELECT sp_group_dm_open(CAST(:m AS bigint[]))', ['m' => '{' . implode(',', array_map('intval', $members)) . '}']);
}
