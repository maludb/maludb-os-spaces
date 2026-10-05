<?php
declare(strict_types=1);
/** POST: action `dispatch_retry` (log `agent.dispatch` with after.retry = true; right agents.settings): a failed dispatch goes back to `sent`, due now; the next pass tries it (one more attempt). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/queries.php';
sp_handler_begin();
require_right('agents.settings');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('dispatch') ?? request_integer('id') ?? refuse(422, 'Say which dispatch.');
$d = find_dispatch($pdo, $id) ?? refuse(404, 'Dispatch not found.');
sp_guard($pdo, static function () use ($pdo, $me, $id, $d): void {
    $pdo->beginTransaction();
    retry_dispatch($pdo, $id, $me);
    log_activity($pdo, 'agent.dispatch', 'message', $d['message_id'], ['channel_id' => $d['channel_id'], 'message_id' => $d['message_id'],
        'after' => ['dispatch_id' => $id, 'agent_member_id' => (int) $d['agent_member_id'], 'kind' => $d['kind'], 'retry' => true]]);
    $pdo->commit();
});
sp_done('Retrying', $id, sp_land(return_path('/admin/dispatches'), 'retried'), 'dispatchChanged', ['dispatch_id' => $id]);
