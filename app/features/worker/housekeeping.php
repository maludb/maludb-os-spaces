<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/imports/run.php';

/** Housekeeping (slice 8): what only piles up — expired sign-on nonces, ended sessions, old outbox rows (bodies blanked, rows kept), old pass rows, a member's recents beyond 50. */
function housekeeping_pass(PDO $pdo, DateTimeImmutable $now): array
{
    $n = static function (string $sql) use ($pdo): int { return (int) $pdo->exec($sql); };
    return [
        'nonces' => $n("DELETE FROM sso_nonces WHERE expires_at < now() - interval '1 day'"),
        'sessions' => $n("DELETE FROM member_sessions WHERE ended_at IS NOT NULL AND ended_at < now() - interval '90 days'"),
        'outbox_blanked' => $n("UPDATE notification_outbox SET body = '', body_html = NULL, subject = NULL, to_email = NULL WHERE status = 'sent' AND sent_at < now() - interval '180 days' AND (body <> '' OR body_html IS NOT NULL OR subject IS NOT NULL OR to_email IS NOT NULL)"),
        'passes' => $n("DELETE FROM worker_passes WHERE started_at < now() - interval '90 days'"),
        'previews' => import_preview_sweep(),
        'recents' => $n('DELETE FROM page_recents r USING (SELECT member_id, page_id, row_number() OVER (PARTITION BY member_id ORDER BY visited_at DESC) AS rn FROM page_recents) x WHERE x.member_id = r.member_id AND x.page_id = r.page_id AND x.rn > 50'),
    ];
}
