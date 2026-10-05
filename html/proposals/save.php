<?php
declare(strict_types=1);
/** POST: action `proposal_make` (log `librarian.propose`; undo `proposal_dismiss`): the Librarian (or a person) proposes — kind, title, reason, message (a thread's first) or page, proposed_page (the draft made first). One open proposal per subject. */
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
require_member_here();
$errors = [];
foreach (['kind', 'title', 'reason'] as $f) { if ((string) req_val($f) === '') { $errors[$f] = ucfirst($f) . ' is required.'; } }
if ($errors !== []) { sp_refuse_fields($errors); }
$fields = ['kind' => req_val('kind'), 'title' => req_val('title'), 'reason' => req_val('reason'), 'message' => req_val('message'), 'page' => req_val('page'), 'proposed_page' => req_val('proposed_page')];
$id = sp_guard($pdo, static function () use ($pdo, $me, $fields): int {
    $pdo->beginTransaction();
    $id = make_proposal($pdo, $fields, $me);
    $subject = ($fields['message'] ?? '') !== '' ? 'message' : 'page';
    log_activity($pdo, 'librarian.propose', 'proposal', $id, ['entity_uuid' => ($fields['proposed_page'] ?? '') !== '' && is_uuid((string) $fields['proposed_page']) ? $fields['proposed_page'] : null,
        'after' => ['proposal_id' => $id, 'kind' => $fields['kind'], 'subject' => $subject]]);
    $pdo->commit();
    return $id;
});
sp_done('Proposed', $id, sp_land(return_path('/proposals/')), 'proposalChanged', ['proposal_id' => $id]);
