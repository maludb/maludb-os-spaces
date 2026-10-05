<?php
/**
 * Helpers for the slice 4 proofs (docs/build-specs/channels.md, "Proof"). Builds on slice 3's lib (its world). channel_world() adds: Seamus (40, the
 * agent) a member of General and Product; in Product `#launch` (public) and `#leads-only` (private: Marco, Priya); the guest Ann added to `#launch`
 * by Marco. Everything a proof makes is named "SMOKE …" (channels: smoke-…).
 */
require dirname(__DIR__) . '/slice3/lib.php';

function channel_by_name(int $space, string $name): ?int { $v = one('SELECT id FROM channels WHERE space_id = :s AND name = :n', ['s' => $space, 'n' => $name]); return $v === false || $v === null ? null : (int) $v; }
function channel_row(int $id): array { return q('SELECT id, space_id, kind, name, topic, purpose, is_default, archived_at, retention_days, message_count, last_message_at FROM channels WHERE id = :id', ['id' => $id])[0] ?? []; }
function message_db(int $id): array { return q('SELECT id, channel_id, thread_root_id, author_member_id, kind, plain_text, reply_count, also_to_channel, edited_at, deleted_at, scheduled_for, sent_at, attachment_count FROM messages WHERE id = :id', ['id' => $id])[0] ?? []; }
function in_channel(int $channel, int $member): bool { return (bool) one('SELECT EXISTS (SELECT 1 FROM channel_members WHERE channel_id = :c AND member_id = :m)', ['c' => $channel, 'm' => $member]); }
function post(string $jar, int $channel, string $md, array $extra = []): array { return act($jar, '/channels/messages/post.php', ['channel' => $channel, 'markdown' => $md] + $extra); }
function reply(string $jar, int $message, string $md, array $extra = []): array { return act($jar, '/channels/messages/reply.php', ['message' => $message, 'markdown' => $md] + $extra); }
function notes(int $member, string $kind, ?int $since = null): array { return q('SELECT id, kind, title, body, channel_id, message_id FROM notifications WHERE member_id = :m AND kind = :k AND id > :s ORDER BY id', ['m' => $member, 'k' => $kind, 's' => $since ?? 0]); }
function last_note_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM notifications'); }
function as_viewer(int $member): void { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); }
function channel_world(): array
{
    $w = editor_world();
    $marco = as_member(27); $owner = as_member(1);
    $launch = channel_by_name($w['product'], 'smoke-launch');
    if ($launch === null) {
        act($owner, '/spaces/members/add.php', ['space' => $w['general'], 'member' => 40]);
        act($marco, '/spaces/members/add.php', ['space' => $w['product'], 'member' => 40]);
        [, $b] = act($marco, '/channels/save.php', ['space' => $w['product'], 'name' => 'smoke-launch', 'kind' => 'public', 'topic' => 'The launch', 'purpose' => 'Everything about the launch']);
        $launch = (int) $b['record_id'];
        [, $b] = act($marco, '/channels/save.php', ['space' => $w['product'], 'name' => 'smoke-leads-only', 'kind' => 'private', 'topic' => 'Leads']);
        $leads = (int) $b['record_id'];
        act($marco, '/channels/members/add.php', ['channel' => $leads, 'member' => 26]);
        act($marco, '/channels/members/add.php', ['channel' => $launch, 'member' => 40]);
        act($marco, '/channels/members/add-guest.php', ['channel' => $launch, 'guest' => 29]);
        act(as_member(26), '/channels/join.php', ['channel' => $launch]);
        act(as_member(30), '/channels/join.php', ['channel' => $launch]);
    }
    return $w + ['launch' => $launch, 'leads_channel' => channel_by_name($w['product'], 'smoke-leads-only'), 'general_channel' => channel_by_name($w['general'], 'general'), 'product_general' => channel_by_name($w['product'], 'general')];
}
