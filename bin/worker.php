<?php
declare(strict_types=1);

/**
 * The worker's steps, one per invocation, idempotent (design §8; slice 8 builds the timer that runs them all). Today:
 *   php bin/worker.php dispatches [--limit=10]   slice 7 — the runs going are polled and what is due is called: each mention of an agent, or message to
 *                                                one in a DM, becomes ONE turn of the kernel's chat endpoint as that agent (the asker acting); the reply
 *                                                lands in the thread. SP_WORKER_NOW sets the clock of a proof. Advisory-locked: two passes never overlap.
 * Prints one JSON line (the counts) and exits 0; 1 for an unknown step.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

$step = $argv[1] ?? '';
$limit = 10;
foreach (array_slice($argv, 2) as $a) { if (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = max(1, (int) $m[1]); } }
$pdo = db();
switch ($step) {
    case 'dispatches':
        require_once dirname(__DIR__) . '/app/features/agents/dispatch.php';
        if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('spaces_worker_dispatches'))")->fetchColumn()) {
            echo json_encode(['step' => 'dispatches', 'skipped' => 'another pass holds the lock']) . "\n";
            exit(0);
        }
        $counts = dispatches_pass($pdo, worker_now(), $limit);
        echo json_encode(['step' => 'dispatches'] + $counts) . "\n";
        $pdo->query("SELECT pg_advisory_unlock(hashtext('spaces_worker_dispatches'))");
        exit(0);
    default:
        fwrite(STDERR, "usage: php bin/worker.php dispatches [--limit=10]\n");
        exit(1);
}
