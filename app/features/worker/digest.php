<?php
declare(strict_types=1);

/**
 * The morning digest (slice 8): for each member who chose one email a day instead of one per event (notification_prefs.digest), in the hour the workspace's digest_hour
 * names in THEIR time zone, one email listing what is unread since their last digest — title, who, when, the link — queued as an outbox row of kind `digest`
 * (dedupe `digest:<member>:<their date>`: never twice a day) with a `digest` notice on the bell as the marker. Nothing unread, nothing sent.
 */
require_once __DIR__ . '/outbox.php';

const DIGEST_ITEMS = 25;

/** A time zone by name, the workspace's when the name is bad. */
function worker_zone(?string $name, string $fallback = 'UTC'): DateTimeZone
{
    foreach ([(string) $name, $fallback, 'UTC'] as $n) {
        try { if ($n !== '') { return new DateTimeZone($n); } } catch (Exception) { }
    }
    return new DateTimeZone('UTC');
}

function digest_pass(PDO $pdo, DateTimeImmutable $now): int
{
    $set = $pdo->query('SELECT digest_hour, timezone, business_name FROM sp_settings WHERE id = 1')->fetch();
    $hour = (int) $set['digest_hour'];
    $business = trim((string) ($set['business_name'] ?? '')) ?: app_name();
    $members = $pdo->query("SELECT m.id, m.timezone, m.email FROM members m JOIN notification_prefs p ON p.member_id = m.id
                             WHERE p.digest AND p.email_enabled AND m.status = 'active' AND m.capability IS NOT NULL AND m.member_kind <> 'agent' AND m.email IS NOT NULL ORDER BY m.id")->fetchAll();
    $made = 0;
    foreach ($members as $m) {
        $local = $now->setTimezone(worker_zone($m['timezone'], (string) $set['timezone']));
        if ((int) $local->format('G') !== $hour) { continue; }
        $date = $local->format('Y-m-d');
        $key = 'digest:' . $m['id'] . ':' . $date;
        if ((bool) one_value($pdo, 'SELECT EXISTS (SELECT 1 FROM notifications WHERE member_id = :m AND dedupe_key = :k)', ['m' => (int) $m['id'], 'k' => $key])) { continue; }
        $since = one_value($pdo, "SELECT max(created_at) FROM notifications WHERE member_id = :m AND kind = 'digest'", ['m' => (int) $m['id']]);
        $st = $pdo->prepare("SELECT n.title, n.kind, n.created_at, n.record_type, n.record_id, n.record_uuid::text AS record_uuid, n.channel_id, n.message_id, c.kind AS channel_kind, a.display_name AS who
                               FROM notifications n LEFT JOIN channels c ON c.id = n.channel_id LEFT JOIN members a ON a.id = n.actor_member_id
                              WHERE n.member_id = :m AND n.read_at IS NULL AND n.kind <> 'digest' AND (CAST(:s AS timestamptz) IS NULL OR n.created_at > CAST(:s AS timestamptz))
                              ORDER BY n.created_at, n.id");
        $st->execute(['m' => (int) $m['id'], 's' => $since]);
        $rows = $st->fetchAll();
        if ($rows === []) { continue; }
        $tz = $local->getTimezone();
        $items = [];
        foreach (array_slice($rows, 0, DIGEST_ITEMS) as $r) {
            $items[] = ['title' => (string) $r['title'], 'who' => (string) ($r['who'] ?? ''), 'link' => app_url(notification_record_url($r) ?? '/notifications'),
                        'when' => (new DateTimeImmutable((string) $r['created_at']))->setTimezone($tz)->format('M j, g:i A')];
        }
        $more = count($rows) - count($items);
        $title = 'Your Spaces digest: ' . count($rows) . ' unread ' . (count($rows) === 1 ? 'notice' : 'notices');
        $nid = one_value($pdo, "SELECT sp_notify(:m, 'digest', :t, :b, NULL, NULL, NULL, NULL, NULL, :k)", ['m' => (int) $m['id'], 't' => $title, 'b' => 'The morning digest of ' . $date . '.', 'k' => $key]);
        if ($nid === null) { continue; }
        $text = $title . "\n\n";
        foreach ($items as $i) { $text .= '- ' . $i['title'] . ($i['who'] !== '' ? ' (' . $i['who'] . ')' : '') . ' - ' . $i['when'] . "\n  " . $i['link'] . "\n"; }
        if ($more > 0) { $text .= "\n" . $more . " more in your bell.\n"; }
        $html = view('mail/digest.php', ['title' => $title, 'items' => $items, 'more' => $more, 'link' => app_url('/notifications'), 'business' => $business]);
        $pdo->prepare("INSERT INTO notification_outbox (channel, member_id, to_email, kind, dedupe_key, subject, body, body_html) VALUES ('email', :m, :e, 'digest', :k, :s, :b, :h) ON CONFLICT DO NOTHING")
            ->execute(['m' => (int) $m['id'], 'e' => $m['email'], 'k' => $key, 's' => $title, 'b' => $text, 'h' => $html]);
        log_activity($pdo, 'notification.digest', 'notification', (int) $nid, ['actor_member_id' => null, 'after' => ['member_id' => (int) $m['id'], 'count' => count($rows), 'date' => $date]]);
        $made++;
    }
    return $made;
}
