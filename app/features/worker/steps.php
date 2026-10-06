<?php
declare(strict_types=1);

/**
 * The worker's steps (slice 8, docs/build-specs/worker-import-export.md). One timer, one script (bin/worker.php), one pass a minute: every step is its own try,
 * idempotent, and does what the database's own sp_pass_*() functions say — a retention day, a snapshot interval and a purge date are the settings' and the channel's,
 * never a constant here. worker_pass() runs the steps that are DUE (the schedule below, judged from the last time each ran) or exactly those named; one
 * worker_passes row and one `worker.pass` log row say what each did. Never a message, a page's words or an address in a log row or an error.
 */
require_once dirname(__DIR__) . '/channels/retention.php';
require_once __DIR__ . '/outbox.php';
require_once __DIR__ . '/digest.php';
require_once __DIR__ . '/housekeeping.php';
require_once dirname(__DIR__) . '/agents/dispatch.php';
require_once dirname(__DIR__) . '/imports/run.php';
require_once dirname(__DIR__) . '/exports/run.php';

/** step => [kind, arg]: 'minute' every pass; 'every' N minutes; 'daily' at "HH:MM" in the workspace's time zone. 'digest' is judged per member inside its pass. */
const WORKER_SCHEDULE = [
    'scheduled' => ['minute'], 'reminders' => ['minute'], 'outbox' => ['minute'], 'digest' => ['every', 15], 'snapshots' => ['minute'], 'prune' => ['daily', '03:00'],
    'retention' => ['every', 60], 'trash' => ['daily', '03:10'], 'wiki' => ['daily', '06:00'], 'exports' => ['minute'], 'search' => ['every', 5],
    'dispatches' => ['minute'], 'imports' => ['minute'], 'housekeeping' => ['daily', '03:30'],
];
const WORKER_STEPS = ['scheduled', 'reminders', 'outbox', 'digest', 'snapshots', 'prune', 'retention', 'trash', 'wiki', 'exports', 'search', 'dispatches', 'imports', 'housekeeping'];
const WORKER_LOCK = "hashtext('sp_worker')";

/** A "now" of the pass as the database's own clock needs none: SP_WORKER_NOW moves only what PHP judges (the schedule, the digest hour). */
function scheduled_pass(PDO $pdo): array
{
    $due = $pdo->query('SELECT m.id, m.channel_id, m.author_member_id, c.space_id FROM messages m JOIN channels c ON c.id = m.channel_id WHERE m.sent_at IS NULL AND m.scheduled_for IS NOT NULL AND m.scheduled_for <= now() AND m.deleted_at IS NULL')->fetchAll();
    $n = (int) $pdo->query('SELECT sp_pass_scheduled()')->fetchColumn();
    foreach ($due as $m) {
        log_activity($pdo, 'message.post', 'message', (int) $m['id'], ['actor_member_id' => (int) $m['author_member_id'], 'channel_id' => (int) $m['channel_id'], 'space_id' => $m['space_id'] === null ? null : (int) $m['space_id'], 'message_id' => (int) $m['id'], 'after' => ['via' => 'scheduled']]);
    }
    return ['sent' => $n];
}

function reminders_pass(PDO $pdo): array
{
    $due = $pdo->query('SELECT id, member_id FROM reminders WHERE done_at IS NULL AND notified_at IS NULL AND remind_at <= now()')->fetchAll();
    $n = (int) $pdo->query('SELECT sp_pass_reminders()')->fetchColumn();
    foreach ($due as $r) { log_activity($pdo, 'reminder.fire', 'reminder', (int) $r['id'], ['actor_member_id' => null, 'after' => ['member_id' => (int) $r['member_id']]]); }
    return ['fired' => $n];
}

function snapshots_pass(PDO $pdo): array
{
    $due = $pdo->query("SELECT p.id::text AS id, p.space_id FROM pages p WHERE p.archived_at IS NULL AND p.content_rev > p.snapshot_rev AND p.last_edited_at < now() - make_interval(mins => sp_setting_int('version_snapshot_minutes')) ORDER BY p.last_edited_at LIMIT 200")->fetchAll();
    $n = (int) $pdo->query('SELECT sp_pass_snapshots(200)')->fetchColumn();
    foreach ($due as $p) { log_activity($pdo, 'page.version_save', 'page', $p['id'], ['actor_member_id' => null, 'space_id' => $p['space_id'] === null ? null : (int) $p['space_id'], 'after' => ['reason' => 'interval']]); }
    return ['versions' => $n];
}

function prune_pass(PDO $pdo): array
{
    return ['pruned' => (int) $pdo->query('SELECT sp_pass_version_prune()')->fetchColumn()];
}

/** sp_pass_retention() and the files of what it deleted: an attachment of a message that is gone has its row and its file removed. Per channel one `message.delete` row with the count. */
function retention_pass(PDO $pdo): array
{
    $per = $pdo->query('SELECT m.channel_id, c.space_id, count(*) AS n FROM messages m JOIN channels c ON c.id = m.channel_id WHERE c.retention_days IS NOT NULL AND m.created_at < now() - make_interval(days => c.retention_days) GROUP BY m.channel_id, c.space_id')->fetchAll();
    $n = (int) $pdo->query('SELECT sp_pass_retention()')->fetchColumn();
    foreach ($per as $c) {
        log_activity($pdo, 'message.delete', 'channel', (int) $c['channel_id'], ['actor_member_id' => null, 'channel_id' => (int) $c['channel_id'], 'space_id' => $c['space_id'] === null ? null : (int) $c['space_id'], 'after' => ['via' => 'retention', 'count' => (int) $c['n']]]);
    }
    $orphans = $pdo->query("SELECT a.id, a.storage_path, a.thumbnail_path FROM attachments a WHERE a.record_type = 'message' AND a.record_id > 0 AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.id = a.record_id)")->fetchAll();
    return ['deleted' => $n, 'files' => drop_attachments($pdo, $orphans)];
}

/** sp_pass_trash_purge() and the files of what went: the attachments of the purged pages' blocks, covers, row files and comments. One `page.delete` row per purged root. */
function trash_pass(PDO $pdo): array
{
    $roots = $pdo->query("SELECT p.id::text AS id, p.space_id, p.plain_title FROM pages p WHERE p.archived_at IS NOT NULL AND p.archived_via IS NULL AND p.archived_at < now() - make_interval(days => sp_setting_int('trash_retention_days'))")->fetchAll();
    $mine = [];
    foreach ($roots as $r) {
        $st = $pdo->prepare("WITH RECURSIVE sub AS (SELECT id FROM pages WHERE id = CAST(:r AS uuid) UNION ALL SELECT c.id FROM pages c JOIN sub ON c.parent_page_id = sub.id OR c.parent_database_id = sub.id)
                             SELECT (SELECT count(*) FROM sub) AS subtree,
                                    COALESCE((SELECT array_agg(a.id) FROM attachments a WHERE
                                         (a.record_type IN ('page_cover', 'page_icon', 'row_files') AND a.record_uuid IN (SELECT id FROM sub))
                                      OR (a.record_type = 'block' AND a.record_uuid IN (SELECT b.id FROM blocks b WHERE b.page_id IN (SELECT id FROM sub)))
                                      OR (a.record_type = 'comment' AND a.record_uuid IN (SELECT c.id FROM comments c WHERE c.page_id IN (SELECT id FROM sub)))), '{}') AS attachment_ids");
        $st->execute(['r' => $r['id']]);
        $x = $st->fetch();
        $mine[$r['id']] = ['subtree' => (int) $x['subtree'], 'attachments' => pg_int_array($x['attachment_ids'])];
    }
    $n = (int) $pdo->query('SELECT sp_pass_trash_purge()')->fetchColumn();
    $files = 0;
    foreach ($roots as $r) {
        log_activity($pdo, 'page.delete', 'page', $r['id'], ['actor_member_id' => null, 'space_id' => $r['space_id'] === null ? null : (int) $r['space_id'], 'after' => ['via' => 'trash', 'subtree_count' => $mine[$r['id']]['subtree']]]);
        $ids = $mine[$r['id']]['attachments'];
        if ($ids !== []) {
            $st = $pdo->prepare("SELECT a.id, a.storage_path, a.thumbnail_path FROM attachments a WHERE a.id = ANY (CAST(:ids AS bigint[])) AND NOT (
                    (a.record_type IN ('page_cover', 'page_icon', 'row_files') AND EXISTS (SELECT 1 FROM pages p WHERE p.id = a.record_uuid))
                 OR (a.record_type = 'block' AND EXISTS (SELECT 1 FROM blocks b WHERE b.id = a.record_uuid))
                 OR (a.record_type = 'comment' AND EXISTS (SELECT 1 FROM comments c WHERE c.id = a.record_uuid)))");
            $st->execute(['ids' => pg_array_literal($ids)]);
            $files += drop_attachments($pdo, $st->fetchAll());
        }
    }
    return ['purged' => $n, 'roots' => count($roots), 'files' => $files];
}

function wiki_pass(PDO $pdo): array
{
    $due = $pdo->query("SELECT id::text AS id, space_id, wiki_owner_member_id FROM pages WHERE verification_state = 'verified' AND verify_until IS NOT NULL AND verify_until < now() AND archived_at IS NULL")->fetchAll();
    $n = (int) $pdo->query('SELECT sp_pass_wiki_expire()')->fetchColumn();
    foreach ($due as $p) { log_activity($pdo, 'page.verify', 'page', $p['id'], ['actor_member_id' => null, 'space_id' => $p['space_id'] === null ? null : (int) $p['space_id'], 'after' => ['state' => 'expired']]); }
    return ['expired' => $n];
}

/** Exports whose life is over: sp_pass_exports_expire() answers the paths, the files go, so does the attachment that served them; one `export.delete` (via expiry) each. */
/** An import's uploaded file is kept as long as an export (7 days, exports.expires_at's own default) from the import's finish or failure; then the attachment and its files go and the log says so. Returns how many. */
function import_files_expire(PDO $pdo): int
{
    $rows = $pdo->query("SELECT DISTINCT i.id AS import_id FROM imports i JOIN attachments a ON a.record_type = 'import' AND a.record_id = i.id
                          WHERE i.status IN ('done', 'failed') AND i.finished_at < now() - interval '7 days'")->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($rows as $iid) {
        $st = $pdo->prepare("SELECT id FROM attachments WHERE record_type = 'import' AND record_id = :i");
        $st->execute(['i' => $iid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $aid) { delete_attachment($pdo, (int) $aid); $n++; }
        $pdo->prepare("UPDATE imports SET log = COALESCE(log || E'\\n', '') || 'The uploaded file is gone (kept 7 days after the import finished).' WHERE id = :i")->execute(['i' => $iid]);
        log_activity($pdo, 'import.file_expire', 'import', (int) $iid, ['actor_member_id' => null, 'after' => ['via' => 'expiry']]);
    }
    return $n;
}

function exports_pass(PDO $pdo): array
{
    $due = $pdo->query('SELECT id, created_by, kind FROM exports WHERE storage_path IS NOT NULL AND expires_at < now()')->fetchAll();
    $paths = $pdo->query('SELECT p FROM sp_pass_exports_expire() p')->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    foreach ($paths as $p) {
        $st = $pdo->prepare("SELECT id, storage_path, thumbnail_path FROM attachments WHERE record_type = 'export' AND storage_path = :p");
        $st->execute(['p' => $p]);
        array_push($rows, ...$st->fetchAll());
    }
    drop_attachments($pdo, $rows);
    $files = remove_attachment_files($paths);
    $gone = import_files_expire($pdo);
    foreach ($due as $e) { log_activity($pdo, 'export.delete', 'export', (int) $e['id'], ['actor_member_id' => null, 'after' => ['via' => 'expiry', 'kind' => $e['kind']]]); }
    return ['expired' => count($paths), 'files' => $files, 'import_files' => $gone];
}

function exports_step(PDO $pdo, int $limit): array
{
    return export_pass($pdo, $limit) + exports_pass($pdo);
}

function search_pass(PDO $pdo): array
{
    return ['indexed' => (int) $pdo->query('SELECT sp_search_catch_up(500)')->fetchColumn()];
}

/** Has $step's moment come? The last time it ran is in worker_passes.steps (ran_at, the pass's own clock). */
function worker_step_due(PDO $pdo, string $step, DateTimeImmutable $now, DateTimeZone $tz): bool
{
    [$kind, $arg] = (WORKER_SCHEDULE[$step] ?? ['minute']) + [1 => null];
    if ($kind === 'minute') { return true; }
    $last = one_value($pdo, 'SELECT steps -> :s ->> \'ran_at\' FROM worker_passes WHERE jsonb_exists(steps, :s2) ORDER BY id DESC LIMIT 1', ['s' => $step, 's2' => $step]);
    $lastAt = $last === null ? null : new DateTimeImmutable((string) $last);
    if ($kind === 'every') { return $lastAt === null || $now->getTimestamp() - $lastAt->getTimestamp() >= (int) $arg * 60 - 20; }
    $local = $now->setTimezone($tz);
    [$h, $m] = array_map('intval', explode(':', (string) $arg));
    $today = $local->setTime($h, $m);
    return $local >= $today && ($lastAt === null || $lastAt < $today);
}

/** One step by name. Returns its counts. */
function worker_run_step(PDO $pdo, string $step, int $limit, DateTimeImmutable $now): array
{
    return match ($step) {
        'scheduled' => scheduled_pass($pdo),
        'reminders' => reminders_pass($pdo),
        'outbox' => outbox_send_batch($pdo, $limit),
        'digest' => ['digests' => digest_pass($pdo, $now)],
        'snapshots' => snapshots_pass($pdo),
        'prune' => prune_pass($pdo),
        'retention' => retention_pass($pdo),
        'trash' => trash_pass($pdo),
        'wiki' => wiki_pass($pdo),
        'exports' => exports_step($pdo, max(1, min($limit, 20))),
        'search' => search_pass($pdo),
        'dispatches' => dispatches_pass($pdo, $now, min($limit, 10)),
        'imports' => import_pass($pdo, max(1, min($limit, 5))),
        'housekeeping' => housekeeping_pass($pdo, $now),
        default => throw new InvalidArgumentException('No step is called ' . $step . '.'),
    };
}

/**
 * One pass: the steps named in $only (all of them, whenever, when it is given) or else those whose moment has come; the counts and the errors. An advisory lock keeps two
 * passes apart: a pass that cannot take it answers ['skipped' => …]. Returns ['steps' => name => counts, 'errors' => [[step, sentence]], 'skipped' => ?string].
 */
function worker_pass(PDO $pdo, array $only, int $limit, DateTimeImmutable $now): array
{
    if (!(bool) $pdo->query('SELECT pg_try_advisory_lock(' . WORKER_LOCK . ')')->fetchColumn()) {
        return ['steps' => [], 'errors' => [], 'skipped' => 'another pass holds the lock'];
    }
    $out = ['steps' => [], 'errors' => [], 'skipped' => null];
    try {
        $set = $pdo->query('SELECT timezone FROM sp_settings WHERE id = 1')->fetch();
        $tz = worker_zone((string) ($set['timezone'] ?? 'UTC'));
        $pass = (int) one_value($pdo, 'INSERT INTO worker_passes DEFAULT VALUES RETURNING id');
        $names = $only === [] ? WORKER_STEPS : array_values(array_intersect(WORKER_STEPS, $only));
        foreach ($names as $step) {
            if ($only === [] && !worker_step_due($pdo, $step, $now, $tz)) { continue; }
            try {
                $out['steps'][$step] = worker_run_step($pdo, $step, $limit, $now) + ['ran_at' => $now->format(DATE_ATOM)];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $sentence = $e instanceof PDOException ? db_message($e, 'The database refused it.') : mb_substr($e->getMessage(), 0, 200);
                $out['errors'][] = [$step, $sentence];
                $out['steps'][$step] = ['failed' => 1, 'ran_at' => $now->format(DATE_ATOM)];       // a due step that erred waits for its next moment, it is not retried every minute
                error_log('worker step ' . $step . ': ' . $e::class . ': ' . $e->getMessage());
            }
        }
        $counts = [];
        foreach ($out['steps'] as $s => $c) { unset($c['ran_at']); $counts[$s] = $c; }
        $pdo->prepare('UPDATE worker_passes SET finished_at = now(), steps = CAST(:s AS jsonb), error = :e WHERE id = :id')
            ->execute(['s' => json_encode($out['steps'], JSON_UNESCAPED_SLASHES), 'e' => $out['errors'] === [] ? null : json_encode($out['errors'], JSON_UNESCAPED_UNICODE), 'id' => $pass]);
        log_activity($pdo, 'worker.pass', 'worker_pass', $pass, ['actor_member_id' => null, 'after' => ['steps' => $counts, 'errors' => count($out['errors'])]]);
    } finally {
        $pdo->query('SELECT pg_advisory_unlock(' . WORKER_LOCK . ')');
    }
    return $out;
}
