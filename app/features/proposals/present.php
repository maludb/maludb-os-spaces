<?php
declare(strict_types=1);

/** The words and the JSON shape of a proposal card (slice 7). */

function present_proposal(array $p): array
{
    return ['proposal_id' => $p['proposal_id'], 'kind' => $p['kind'], 'icon' => PROPOSAL_KINDS[$p['kind']][0] ?? 'feather-inbox', 'title' => $p['title'], 'reason' => $p['reason'], 'status' => $p['status'],
            'proposed_by' => $p['proposed_by'] === null ? null : ['member_id' => $p['proposed_by'], 'display_name' => $p['proposed_by_name']],
            'subject' => ['page_id' => $p['subject_page_id'], 'page_title' => $p['subject_page_title'], 'message_id' => $p['subject_message_id'], 'channel_id' => $p['subject_channel_id'], 'first_line' => $p['subject_first_line'], 'url' => proposal_subject_url($p)],
            'draft' => $p['proposed_page_id'] === null ? null : ['page_id' => $p['proposed_page_id'], 'title' => $p['draft_title'], 'url' => '/pages/' . $p['proposed_page_id']],
            'created_at' => json_ts($p['created_at']), 'decided_by' => $p['decided_by'] === null ? null : ['member_id' => $p['decided_by'], 'display_name' => $p['decided_by_name']], 'decided_at' => json_ts($p['decided_at']), 'decision_note' => $p['decision_note']];
}

/** Where the subject is: the page, or the thread (the channel's own page with the thread open). */
function proposal_subject_url(array $p): ?string
{
    if ($p['subject_page_id'] !== null) { return '/pages/' . $p['subject_page_id']; }
    if ($p['subject_message_id'] !== null && $p['subject_channel_id'] !== null) { return '/channels/' . $p['subject_channel_id'] . '/threads/' . $p['subject_message_id']; }
    return null;
}
