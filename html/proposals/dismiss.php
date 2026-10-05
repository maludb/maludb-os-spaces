<?php
declare(strict_types=1);
/** POST: action `proposal_dismiss` (log `librarian.dismiss`): the proposal is dismissed, the reason kept; the Librarian does not propose it again within 90 days (recently_dismissed). */
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
require_member_here();
$id = request_integer('proposal') ?? refuse(422, 'Say which proposal.');
$p = find_proposal($pdo, $id) ?? refuse(404, 'Proposal not found.');
$reason = req_val('reason');
sp_guard($pdo, static function () use ($pdo, $me, $id, $p, $reason): void {
    $pdo->beginTransaction();
    dismiss_proposal($pdo, $id, $reason, $me);
    log_activity($pdo, 'librarian.dismiss', 'proposal', $id, ['space_id' => $p['space_id'], 'after' => ['proposal_id' => $id, 'kind' => $p['kind'], 'subject' => $p['subject_message_id'] !== null ? 'message' : 'page', 'has_reason' => $reason !== null && $reason !== '']]);
    $pdo->commit();
});
sp_done('Dismissed', $id, sp_land(return_path('/proposals/'), 'dismissed'), 'proposalChanged', ['proposal_id' => $id]);
