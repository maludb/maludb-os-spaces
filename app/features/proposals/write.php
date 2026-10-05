<?php
declare(strict_types=1);

/** Writes to the Librarian's proposals (slice 7): make, accept, dismiss. The gates are the handlers'; the database's unique index and sp_page_move do the rest. */

/** Who owns the space a proposal is about (humans): the people told when one is made. */
function proposal_owner_ids(PDO $pdo, ?int $spaceId): array
{
    if ($spaceId === null) { return []; }
    $st = $pdo->prepare("SELECT sm.member_id FROM space_members sm JOIN members m ON m.id = sm.member_id AND m.member_kind = 'human' AND m.status = 'active' WHERE sm.space_id = :s AND sm.role = 'owner'");
    $st->execute(['s' => $spaceId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * A proposal: kind, title, reason and its subject — `message` (a thread's first message; a reply names its root) or `page` — and `proposed_page`
 * (the draft made with page_create first). The subject and the draft must be visible to the maker (404 'Not found.' otherwise). One open
 * proposal per subject (the index: a second is 422 "already proposed"). The subject space's owners are told once. Returns the id.
 */
function make_proposal(PDO $pdo, array $fields, int $by): int
{
    $kind = (string) ($fields['kind'] ?? '');
    if (!isset(PROPOSAL_KINDS[$kind])) { throw new DomainException('The kind is one of ' . implode(', ', array_keys(PROPOSAL_KINDS)) . '.'); }
    $title = trim((string) ($fields['title'] ?? ''));
    $reason = trim((string) ($fields['reason'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200) { throw new DomainException('The title is one to 200 characters.'); }
    if ($reason === '' || mb_strlen($reason) > 2000) { throw new DomainException('The reason is one to 2000 characters.'); }
    $messageId = ($fields['message'] ?? null) !== null && $fields['message'] !== '' ? (int) $fields['message'] : null;
    $pageId = ($fields['page'] ?? null) !== null && $fields['page'] !== '' ? (string) $fields['page'] : null;
    if (($messageId === null) === ($pageId === null)) { throw new DomainException('Name the subject: a message or a page.'); }
    $channelId = null;
    $spaceId = null;
    if ($messageId !== null) {
        $st = $pdo->prepare("SELECT COALESCE(thread_root_id, message_id) AS root, channel_id FROM mcp_messages WHERE message_id = :m");
        $st->execute(['m' => $messageId]);
        $m = $st->fetch() ?: throw new DomainException('Not found.');
        $messageId = (int) $m['root'];
        $channelId = (int) $m['channel_id'];
        $kindRow = $pdo->prepare('SELECT kind, space_id FROM mcp_channels WHERE channel_id = :c');
        $kindRow->execute(['c' => $channelId]);
        $c = $kindRow->fetch() ?: throw new DomainException('Not found.');
        if (in_array($c['kind'], ['dm', 'group_dm'], true)) { throw new DomainException('A direct message is not proposed about.'); }
        $spaceId = $c['space_id'] === null ? null : (int) $c['space_id'];
    } else {
        if (!is_uuid($pageId)) { throw new DomainException('The page is named by its id.'); }
        $st = $pdo->prepare('SELECT space_id FROM mcp_pages WHERE page_id = CAST(:p AS uuid)');
        $st->execute(['p' => $pageId]);
        $p = $st->fetch() ?: throw new DomainException('Not found.');
        $spaceId = $p['space_id'] === null ? null : (int) $p['space_id'];
    }
    if (in_array($kind, ['thread_to_page', 'unanswered'], true) && $messageId === null) { throw new DomainException('A ' . str_replace('_', ' ', $kind) . ' proposal is about a thread: name its message.'); }
    if (!in_array($kind, ['thread_to_page', 'unanswered'], true) && $pageId === null) { throw new DomainException('A ' . str_replace('_', ' ', $kind) . ' proposal is about a page: name it.'); }
    $draft = ($fields['proposed_page'] ?? null) !== null && $fields['proposed_page'] !== '' ? (string) $fields['proposed_page'] : null;
    if ($draft !== null) {
        if (!is_uuid($draft) || one_value($pdo, 'SELECT 1 FROM mcp_pages WHERE page_id = CAST(:p AS uuid)', ['p' => $draft]) === null) { throw new DomainException('Not found.'); }
    }
    $dup = $pdo->prepare("SELECT proposal_id FROM mcp_librarian_proposals WHERE status = 'proposed' AND kind = :k AND subject_page_id IS NOT DISTINCT FROM CAST(:p AS uuid) AND subject_message_id IS NOT DISTINCT FROM :m");
    $dup->execute(['k' => $kind, 'p' => $pageId, 'm' => $messageId]);
    if (($have = $dup->fetchColumn()) !== false) { throw new DomainException('That is already proposed.'); }
    try {
        $st = $pdo->prepare('INSERT INTO librarian_proposals (kind, subject_page_id, subject_message_id, subject_channel_id, proposed_page_id, title, reason, proposed_by)
                             VALUES (:k, CAST(:p AS uuid), :m, :c, CAST(:d AS uuid), :t, :r, :by) RETURNING id');
        $st->execute(['k' => $kind, 'p' => $pageId, 'm' => $messageId, 'c' => $channelId, 'd' => $draft, 't' => $title, 'r' => $reason, 'by' => $by]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') { throw new DomainException('That is already proposed.'); }
        throw $e;
    }
    $id = (int) $st->fetchColumn();
    $who = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $by]);
    $notify = $pdo->prepare("SELECT sp_notify(:o, 'proposal', :t, :b, 'proposal', :id, CAST(:u AS uuid), :c, :msg, :dd)");
    foreach (proposal_owner_ids($pdo, $spaceId) as $owner) {
        $notify->execute(['o' => $owner, 't' => $who . ' proposed: ' . mb_substr($title, 0, 150), 'b' => mb_substr($reason, 0, 300), 'id' => $id, 'u' => $pageId, 'c' => $channelId, 'msg' => $messageId, 'dd' => 'proposal:' . $id]);
    }
    return $id;
}

/**
 * Accept: a thread_to_page one moves its draft where the acceptor says — `$parent` a page's id, or `space:<id>` for a space's root (the handler
 * gated both); a verify one is marked and the page linked for the acceptor to verify there (slice 2's page_verify); orphan, duplicate, broken_link,
 * unanswered and stale are marked and the page or the thread linked for a person to act. Returns ['proposal_id', 'kind', 'link', 'page_id', 'moved'].
 */
function accept_proposal(PDO $pdo, int $id, ?string $parentUuid, int $by): array
{
    $p = find_proposal($pdo, $id) ?? throw new DomainException('Not found.');
    if ($p['status'] !== 'proposed') { throw new DomainException('That proposal is already ' . $p['status'] . '.'); }
    $moved = false;
    $page = $p['subject_page_id'];
    if ($p['kind'] === 'thread_to_page') {
        if ($p['proposed_page_id'] === null) { throw new DomainException('The Librarian made no draft: there is nothing to move.'); }
        if ($parentUuid === null || $parentUuid === '') { throw new DomainException('Say where the draft goes: a parent page or a space.'); }
        if (str_starts_with($parentUuid, 'space:')) {
            $pdo->prepare('SELECT sp_page_move(CAST(:d AS uuid), NULL, :s)')->execute(['d' => $p['proposed_page_id'], 's' => (int) substr($parentUuid, 6)]);
        } else {
            $pdo->prepare('SELECT sp_page_move(CAST(:d AS uuid), CAST(:p AS uuid), NULL)')->execute(['d' => $p['proposed_page_id'], 'p' => $parentUuid]);
        }
        $moved = true;
        $page = $p['proposed_page_id'];
    }
    $st = $pdo->prepare("UPDATE librarian_proposals SET status = 'accepted', decided_by = :by, decided_at = now() WHERE id = :id AND status = 'proposed'");
    $st->execute(['by' => $by, 'id' => $id]);
    if ($st->rowCount() !== 1) { throw new DomainException('That proposal is already decided.'); }
    $link = $page !== null ? '/pages/' . $page
        : ($p['subject_message_id'] !== null && $p['subject_channel_id'] !== null ? '/channels/' . $p['subject_channel_id'] . '/threads/' . $p['subject_message_id'] : '/proposals/');
    return ['proposal_id' => $id, 'kind' => $p['kind'], 'link' => $link, 'page_id' => $page, 'moved' => $moved, 'space_id' => $p['space_id'] === null ? null : (int) $p['space_id']];
}

/** Dismiss: status dismissed, the reason kept (db/021 decision_note). */
function dismiss_proposal(PDO $pdo, int $id, ?string $reason, int $by): void
{
    $p = find_proposal($pdo, $id) ?? throw new DomainException('Not found.');
    if ($p['status'] !== 'proposed') { throw new DomainException('That proposal is already ' . $p['status'] . '.'); }
    $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
    if ($reason !== null && mb_strlen($reason) > 500) { throw new DomainException('The reason is at most 500 characters.'); }
    $st = $pdo->prepare("UPDATE librarian_proposals SET status = 'dismissed', decided_by = :by, decided_at = now(), decision_note = :n WHERE id = :id AND status = 'proposed'");
    $st->execute(['by' => $by, 'id' => $id, 'n' => $reason]);
    if ($st->rowCount() !== 1) { throw new DomainException('That proposal is already decided.'); }
}
