<?php
declare(strict_types=1);
/**
 * /proposals/ — the Librarian's proposals as cards (screen `proposal-list`; params: status = proposed|accepted|dismissed (default proposed), kind). Every member sees the proposals about what they may see
 * (mcp_librarian_proposals); accepting and dismissing are theirs too (a thread_to_page one asks where the draft goes). Agents use the tools, not this page.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
require_login();
require_human();
$pdo = db();
require_member_here();
$status = request_string('status');
if (!isset(PROPOSAL_STATUSES[$status])) { $status = 'proposed'; }
$kind = request_string('kind');
if (!isset(PROPOSAL_KINDS[$kind])) { $kind = ''; }
$rows = find_proposals($pdo, ['status' => $status, 'kind' => $kind]);
$counts = proposal_counts($pdo);
$dest = $status === 'proposed' ? accept_destinations($pdo) : ['spaces' => [], 'pages' => []];
log_screen_view($pdo, 'proposal-list');
if (wants_json()) {
    respond_screen(['status' => $status, 'kind' => $kind === '' ? null : $kind, 'counts' => $counts, 'proposals' => array_map('present_proposal', $rows)]);
}
$notices = ['accepted' => ['success', 'Accepted.'], 'dismissed' => ['success', 'Dismissed.']];
render_screen('Librarian proposals', view('proposals/index.php', ['rows' => $rows, 'status' => $status, 'kind' => $kind, 'counts' => $counts, 'dest' => $dest, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'admin-proposals', 'screen' => 'proposal-list']);
