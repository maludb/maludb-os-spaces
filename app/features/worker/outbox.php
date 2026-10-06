<?php
declare(strict_types=1);

/**
 * The outbox sender (slice 8, docs/build-specs/worker-import-export.md): the rows sp_notify() and the digest queue — one per recipient AND channel — are
 * delivered here. Email goes through MaluMail (the kit's malumail_send(); Spaces holds no mailbox), texts through the kernel's K6 door (kernel_call to
 * /api/v1/notify/sms.php — Spaces holds no Twilio key). THE RULES:
 *   - email is AT-MOST-ONCE: the row is marked `sent` BEFORE the send is attempted and `failed` (never queued again) after a failure — a person is never mailed twice;
 *     a provider's refusal of the address (suppressed, rejected) is `skipped` with the code;
 *   - a text that the kernel could not take (a 5xx, no answer) is retried with a backoff (send_after + 2^attempts minutes), five attempts then `failed`; each K6 refusal
 *     (no_sender, not_held, no_verified_phone, opted_out, rate_limited, invalid) is `skipped` with the code and the email row stands.
 * Never a body, a subject or an address in the trail: the channel, the kind and a code.
 */
require_once dirname(__DIR__) . '/notify/present.php';

const OUTBOX_MAX_ATTEMPTS = 5;
const K6_REFUSALS = ['no_sender', 'not_held', 'no_verified_phone', 'opted_out', 'rate_limited', 'invalid'];

/** Where a queued row leads, as an absolute URL: the notice it was queued with (same member, kind and instant), else the bell. */
function outbox_link(PDO $pdo, array $o): string
{
    $st = $pdo->prepare("SELECT n.kind, n.record_type, n.record_id, n.record_uuid::text AS record_uuid, n.channel_id, n.message_id, c.kind AS channel_kind
                           FROM notifications n LEFT JOIN channels c ON c.id = n.channel_id
                          WHERE n.member_id = :m AND n.kind = :k AND n.created_at = :t ORDER BY (n.title = :s) DESC, n.id LIMIT 1");
    $st->execute(['m' => (int) $o['member_id'], 'k' => $o['kind'], 't' => $o['created_at'], 's' => (string) ($o['subject'] ?? '')]);
    $n = $st->fetch();
    $path = $n === false ? null : notification_record_url($n);
    return app_url($path ?? '/notifications');
}

/** The email as MaluMail takes it: the text part with the link and the sign-off, the HTML from the views (the row's own body_html when it has one). */
function outbox_mail(PDO $pdo, array $o): array
{
    $business = trim((string) one_value($pdo, 'SELECT business_name FROM sp_settings WHERE id = 1')) ?: app_name();
    $link = outbox_link($pdo, $o);
    $subject = (string) ($o['subject'] ?? '') !== '' ? (string) $o['subject'] : 'A notice from ' . $business;
    $text = trim((string) $o['body']) . "\n\n" . $link . "\n\n-- " . $business . ($business !== app_name() ? ' (' . app_name() . ')' : '') . "\n";
    $html = (string) ($o['body_html'] ?? '') !== '' ? (string) $o['body_html']
        : view('mail/notice.php', ['title' => $subject, 'body' => (string) $o['body'], 'link' => $link, 'business' => $business]);
    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}

function outbox_log(PDO $pdo, string $action, array $o, ?string $code = null): void
{
    log_activity($pdo, $action, 'notification_outbox', (int) $o['id'], ['actor_member_id' => null, 'after' => ['channel' => $o['channel'], 'kind' => $o['kind'], 'member_id' => (int) $o['member_id']] + ($code === null ? [] : ['code' => $code])]);
}

function outbox_finish(PDO $pdo, array $o, string $status, ?string $detail, ?string $ref = null): void
{
    $pdo->prepare('UPDATE notification_outbox SET status = :s, detail = :d, provider_ref = COALESCE(:r, provider_ref), sent_at = CASE WHEN :s2 = \'sent\' THEN COALESCE(sent_at, now()) ELSE sent_at END WHERE id = :id')
        ->execute(['s' => $status, 's2' => $status, 'd' => $detail === null ? null : mb_substr($detail, 0, 200), 'r' => $ref, 'id' => (int) $o['id']]);
}

/** A text that was not taken: five tries with 2^attempts minutes between, then failed. Returns 'retried' or 'failed'. */
function outbox_retry(PDO $pdo, array $o, string $why): string
{
    $attempts = (int) $o['attempts'] + 1;
    if ($attempts >= OUTBOX_MAX_ATTEMPTS) {
        $pdo->prepare("UPDATE notification_outbox SET status = 'failed', attempts = :a, detail = :d WHERE id = :id")->execute(['a' => $attempts, 'd' => $why, 'id' => (int) $o['id']]);
        outbox_log($pdo, 'notification.fail', $o, 'attempts');
        return 'failed';
    }
    $pdo->prepare("UPDATE notification_outbox SET attempts = :a, detail = :d, send_after = now() + make_interval(mins => :m) WHERE id = :id AND status = 'queued'")
        ->execute(['a' => $attempts, 'd' => $why, 'm' => 2 ** $attempts, 'id' => (int) $o['id']]);
    return 'retried';
}

/** One email. At-most-once: claimed as sent first. Returns 'sent', 'skipped' or 'failed'. */
function outbox_send_email(PDO $pdo, array $o): string
{
    $to = trim((string) ($o['to_email'] ?? '')) !== '' ? (string) $o['to_email'] : (string) ($o['member_email'] ?? '');
    if ($to === '') {
        outbox_finish($pdo, $o, 'skipped', 'no_address');
        outbox_log($pdo, 'notification.skip', $o, 'no_address');
        return 'skipped';
    }
    $claim = $pdo->prepare("UPDATE notification_outbox SET status = 'sent', attempts = attempts + 1, sent_at = now(), detail = 'sending' WHERE id = :id AND status = 'queued'");
    $claim->execute(['id' => (int) $o['id']]);
    if ($claim->rowCount() !== 1) {
        return 'skipped';                                      // another pass took it
    }
    try {
        $mail = outbox_mail($pdo, $o);
        $r = malumail_send(['to' => $to, 'subject' => $mail['subject'], 'text' => $mail['text'], 'html' => $mail['html'],
                            'from' => (string) env('MAIL_FROM', ''), 'from_name' => (string) env('MAIL_FROM_NAME', app_name())]);
    } catch (Throwable $e) {
        error_log('outbox ' . $o['id'] . ': ' . $e->getMessage());
        outbox_finish($pdo, $o, 'failed', 'The mail could not be sent.');
        outbox_log($pdo, 'notification.fail', $o, 'transport');
        return 'failed';
    }
    $status = (int) $r['status'];
    $rejected = $r['body']['rejected'][0]['reason'] ?? null;
    if ($status === 400 || ($status === 200 && empty($r['body']['accepted']) && $rejected !== null)) {
        $code = is_string($rejected) && str_starts_with($rejected, 'suppressed') ? 'suppressed' : 'rejected';
        outbox_finish($pdo, $o, 'skipped', $code);
        outbox_log($pdo, 'notification.skip', $o, $code);
        return 'skipped';
    }
    if ($status >= 200 && $status < 300) {
        outbox_finish($pdo, $o, 'sent', null, $r['message_id']);
        outbox_log($pdo, 'notification.send', $o);
        return 'sent';
    }
    outbox_finish($pdo, $o, 'failed', 'MaluMail answered ' . $status . '.');
    outbox_log($pdo, 'notification.fail', $o, 'http_' . $status);
    return 'failed';
}

/** One text through K6. Returns 'sent', 'skipped', 'retried' or 'failed'. */
function outbox_send_text(PDO $pdo, array $o): string
{
    $text = mb_substr((string) $o['body'], 0, 480);
    $ref = (string) $o['kind'] . ':' . ($o['record_id'] ?? $o['id']);
    $r = kernel_call('POST', '/api/v1/notify/sms.php', ['member_id' => (int) $o['member_id'], 'text' => $text, 'reference' => $ref], [], 10);
    if ($r === null) {
        return outbox_retry($pdo, $o, 'The kernel did not answer.');
    }
    $status = (int) $r['status'];
    if ($status === 202 || ($status >= 200 && $status < 300)) {
        $pdo->prepare("UPDATE notification_outbox SET attempts = attempts + 1 WHERE id = :id")->execute(['id' => (int) $o['id']]);
        outbox_finish($pdo, $o, 'sent', null, isset($r['body']['notification']['id']) ? (string) $r['body']['notification']['id'] : null);
        outbox_log($pdo, 'notification.send', $o);
        return 'sent';
    }
    $code = (string) ($r['body']['error']['code'] ?? '');
    if (in_array($code, K6_REFUSALS, true) || ($status >= 400 && $status < 500 && $status !== 429 && $status !== 408)) {
        $code = $code !== '' ? $code : 'refused';
        outbox_finish($pdo, $o, 'skipped', $code);
        outbox_log($pdo, 'notification.skip', $o, $code);
        return 'skipped';
    }
    return outbox_retry($pdo, $o, 'The kernel answered ' . $status . '.');
}

/** Deliver what is due. Returns ['sent', 'skipped', 'failed', 'retried']. */
function outbox_send_batch(PDO $pdo, int $limit): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'retried' => 0];
    $st = $pdo->prepare("SELECT o.id, o.channel, o.member_id, o.to_email, o.kind, o.record_type, o.record_id, o.subject, o.body, o.body_html, o.attempts, o.created_at, m.email AS member_email, m.status AS member_status
                           FROM notification_outbox o JOIN members m ON m.id = o.member_id
                          WHERE o.status = 'queued' AND o.send_after <= now() ORDER BY o.send_after, o.id LIMIT :l");
    $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() as $o) {
        try {
            if ($o['member_status'] !== 'active') {
                outbox_finish($pdo, $o, 'skipped', 'inactive');
                outbox_log($pdo, 'notification.skip', $o, 'inactive');
                $r = 'skipped';
            } else {
                $r = $o['channel'] === 'text' ? outbox_send_text($pdo, $o) : outbox_send_email($pdo, $o);
            }
        } catch (Throwable $e) {
            error_log('outbox row ' . $o['id'] . ': ' . $e->getMessage());
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $r = 'failed';
        }
        $out[$r] = ($out[$r] ?? 0) + 1;
    }
    return $out;
}
