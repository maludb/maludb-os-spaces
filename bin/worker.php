<?php
declare(strict_types=1);

/**
 * The worker (slice 8 — docs/build-specs/worker-import-export.md): ONE timer (spaces-worker.timer, every minute) runs ONE script. Every step is idempotent and isolated; the steps
 * whose moment has come run, or exactly the ones named; an advisory lock keeps two passes apart.
 *   php bin/worker.php [--only=scheduled,reminders,outbox,digest,snapshots,prune,retention,trash,wiki,exports,search,dispatches,imports,housekeeping] [--limit=200]
 *   php bin/worker.php dispatches [--limit=10]      slice 7's form: one step by name, its counts on one JSON line (the step's own shape)
 * SP_WORKER_NOW (ISO 8601) fakes the clock outside production — what PHP judges (the schedule, the digest hour, an import's time), never the database's now().
 * Prints one JSON line; exit 0, or 1 when a step erred or the arguments are wrong. A pass that finds the lock held prints {"skipped": …} and exits 0.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/worker/steps.php';

$only = [];
$limit = 200;
$legacy = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--only=(.*)$/', $a, $m)) { $only = array_values(array_filter(array_map('trim', explode(',', $m[1])))); }
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = max(1, (int) $m[1]); }
    elseif (in_array($a, WORKER_STEPS, true)) { $legacy = $a; $only = [$a]; if ($limit === 200) { $limit = 10; } }
    else { fwrite(STDERR, "usage: php bin/worker.php [--only=" . implode(',', WORKER_STEPS) . "] [--limit=200]\n"); exit(1); }
}
foreach ($only as $s) {
    if (!in_array($s, WORKER_STEPS, true)) { fwrite(STDERR, "No step is called $s.\n"); exit(1); }
}
foreach (array_slice($argv, 1) as $a) {
    if ($legacy !== null && preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = max(1, (int) $m[1]); }
}
$r = worker_pass(db(), $only, $limit, worker_now());
if ($r['skipped'] !== null) {
    echo json_encode(($legacy !== null ? ['step' => $legacy] : ['worker' => 'pass']) + ['skipped' => $r['skipped']]) . "\n";
    exit(0);
}
if ($legacy !== null) {
    $c = $r['steps'][$legacy] ?? [];
    unset($c['ran_at']);
    echo json_encode(['step' => $legacy] + $c + ($r['errors'] === [] ? [] : ['errors' => $r['errors']])) . "\n";
} else {
    $steps = [];
    foreach ($r['steps'] as $s => $c) { unset($c['ran_at']); $steps[$s] = $c; }
    echo json_encode(['worker' => 'pass', 'steps' => $steps, 'errors' => $r['errors']], JSON_UNESCAPED_UNICODE) . "\n";
}
exit($r['errors'] === [] ? 0 : 1);
