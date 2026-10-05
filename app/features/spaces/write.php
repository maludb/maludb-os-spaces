<?php
declare(strict_types=1);

/**
 * Writes to spaces, space_members, space_join_requests, space_sections and pages.section_id. A refusal is a DomainException in words;
 * the database's own (P0001 — General's kind, the last owner, a guest owner, an archived space) and a duplicate (23505) are translated
 * by sp_guard(). Every function is called inside a transaction the handler opened.
 */

/** Make ($id null) or change a space. $f: name, icon, description, kind, member_level, everyone_level, is_wiki, wiki_default_verify_months. The creator owns it. */
function save_space(PDO $pdo, ?int $id, array $f, int $by): int
{
    $everyone = $f['kind'] === 'open' ? $f['everyone_level'] : 'none';                       // D5: only an open space reaches everyone
    $args = ['n' => $f['name'], 'i' => $f['icon'], 'd' => $f['description'], 'ml' => $f['member_level'], 'el' => $everyone, 'w' => $f['is_wiki'] ? 't' : 'f', 'wm' => $f['is_wiki'] ? $f['wiki_default_verify_months'] : null];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO spaces (name, slug, icon, description, kind, member_level, everyone_level, is_wiki, wiki_default_verify_months, created_by)
                             VALUES (:n, sp_unique_space_slug(:n), :i, :d, :k, :ml, :el, :w, :wm, :by) RETURNING id');
        $st->execute($args + ['k' => $f['kind'], 'by' => $by]);
        $id = (int) $st->fetchColumn();
        $pdo->prepare("INSERT INTO space_members (space_id, member_id, role, added_by) VALUES (:s, :m, 'owner', :m) ON CONFLICT (space_id, member_id) DO UPDATE SET role = 'owner'")->execute(['s' => $id, 'm' => $by]);
        return $id;
    }
    $pdo->prepare('UPDATE spaces SET name = :n, icon = :i, description = :d, member_level = :ml, everyone_level = :el, is_wiki = :w, wiki_default_verify_months = :wm WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

/** Change a space's kind. Leaving open sets everyone_level none; becoming open gives it the workspace's default_everyone_level. */
function set_space_kind(PDO $pdo, int $id, string $kind, int $by): void
{
    $s = space_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($s['kind'] === $kind) {
        throw new DomainException('Space "' . $s['name'] . '" is already ' . $kind . '.');
    }
    $everyone = $kind === 'open' ? (string) one_value($pdo, 'SELECT default_everyone_level FROM sp_settings WHERE id = 1') : 'none';
    $pdo->prepare('UPDATE spaces SET kind = :k, everyone_level = :e WHERE id = :id')->execute(['k' => $kind, 'e' => $everyone, 'id' => $id]);
}

/** Archive ($archive true) or restore a space. General is refused by the guard. */
function archive_space(PDO $pdo, int $id, bool $archive, int $by): void
{
    $s = space_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($archive) {
        if ($s['archived_at'] !== null) {
            throw new DomainException('Space "' . $s['name'] . '" is already archived.');
        }
        $pdo->prepare('UPDATE spaces SET archived_at = now(), archived_by = :by WHERE id = :id')->execute(['by' => $by, 'id' => $id]);
        return;
    }
    if ($s['archived_at'] === null) {
        throw new DomainException('Space "' . $s['name'] . '" is not archived.');
    }
    $pdo->prepare('UPDATE spaces SET archived_at = NULL, archived_by = NULL WHERE id = :id')->execute(['id' => $id]);
}

/** Delete a space for good: archived, with no live page. Its channels (and their messages) and its trashed pages go with it. Returns the name. */
function delete_space(PDO $pdo, int $id, int $by): string
{
    $s = space_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($s['is_default']) {
        throw new DomainException('The default space is never deleted.');
    }
    if ($s['archived_at'] === null) {
        throw new DomainException('Space "' . $s['name'] . '" is not archived: archive it first.');
    }
    $live = (int) one_value($pdo, 'SELECT count(*) FROM pages WHERE space_id = :s AND archived_at IS NULL', ['s' => $id]);
    if ($live > 0) {
        throw new DomainException('Space "' . $s['name'] . '" still holds ' . $live . ' page' . ($live === 1 ? '' : 's') . '; trash them first.');
    }
    $pdo->prepare('DELETE FROM pages WHERE space_id = :s')->execute(['s' => $id]);
    $pdo->prepare('UPDATE spaces SET default_channel_id = NULL WHERE id = :s')->execute(['s' => $id]);
    $pdo->prepare('DELETE FROM channels WHERE space_id = :s')->execute(['s' => $id]);
    $pdo->prepare('DELETE FROM spaces WHERE id = :s')->execute(['s' => $id]);
    return (string) $s['name'];
}

/** Make a space a wiki (or not). Turning it on gives every page with no wiki owner its creator (else $by). Returns the pages given an owner. */
function set_space_wiki(PDO $pdo, int $id, bool $wiki, ?int $months, int $by): int
{
    $s = space_state($pdo, $id) ?? throw new DomainException('Not found.');
    $pdo->prepare('UPDATE spaces SET is_wiki = :w, wiki_default_verify_months = :m WHERE id = :id')->execute(['w' => $wiki ? 't' : 'f', 'm' => $wiki ? $months : null, 'id' => $id]);
    if (!$wiki) {
        return 0;
    }
    $st = $pdo->prepare('UPDATE pages SET wiki_owner_member_id = COALESCE(created_by, :by) WHERE space_id = :s AND wiki_owner_member_id IS NULL AND parent_database_id IS NULL AND NOT is_template');
    $st->execute(['by' => $by, 's' => $id]);
    return $st->rowCount();
}

/** Join an open space as a member. Already in: nothing. Closed or private: refused in words. */
function join_space(PDO $pdo, int $spaceId, int $memberId): bool
{
    $s = space_state($pdo, $spaceId) ?? throw new DomainException('Not found.');
    if ($s['kind'] !== 'open') {
        throw new DomainException('Space "' . $s['name'] . '" is ' . $s['kind'] . ': ' . ($s['kind'] === 'closed' ? 'ask to join it' : 'an owner adds its members') . '.');
    }
    $st = $pdo->prepare("INSERT INTO space_members (space_id, member_id, role, added_by) VALUES (:s, :m, 'member', :m) ON CONFLICT (space_id, member_id) DO NOTHING");
    $st->execute(['s' => $spaceId, 'm' => $memberId]);
    return $st->rowCount() === 1;
}

/** Leave a space: the row deleted (the guard refuses General and the last owner). A derived member has no row: refused in words. */
function leave_space(PDO $pdo, int $spaceId, int $memberId): void
{
    $s = space_state($pdo, $spaceId) ?? throw new DomainException('Not found.');
    $st = $pdo->prepare('DELETE FROM space_members WHERE space_id = :s AND member_id = :m');
    $st->execute(['s' => $spaceId, 'm' => $memberId]);
    if ($st->rowCount() === 1) {
        return;
    }
    if ($s['department_id'] !== null && db_bool($pdo, 'SELECT :m IN (SELECT sp_space_member_ids(:s))', ['m' => $memberId, 's' => $spaceId])) {
        throw new DomainException('You are in ' . $s['name'] . ' through the department; leave the department in HR.');
    }
    throw new DomainException('You are not in ' . $s['name'] . '.');
}

/** Ask to join a closed space: one pending request per member; the owners are told. Returns the request id. */
function request_join(PDO $pdo, int $spaceId, int $memberId, ?string $message): int
{
    $s = space_state($pdo, $spaceId) ?? throw new DomainException('Not found.');
    if ($s['kind'] !== 'closed') {
        throw new DomainException($s['kind'] === 'open' ? 'Space "' . $s['name'] . '" is open: join it.' : 'Space "' . $s['name'] . '" is private: an owner adds its members.');
    }
    if (db_bool($pdo, 'SELECT :m IN (SELECT sp_space_member_ids(:s))', ['m' => $memberId, 's' => $spaceId])) {
        throw new DomainException('You are already in ' . $s['name'] . '.');
    }
    if (db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM space_join_requests WHERE space_id = :s AND member_id = :m AND status = 'pending')", ['s' => $spaceId, 'm' => $memberId])) {
        throw new DomainException('You already asked to join ' . $s['name'] . '.');
    }
    $st = $pdo->prepare('INSERT INTO space_join_requests (space_id, member_id, message) VALUES (:s, :m, :msg) RETURNING id');
    $st->execute(['s' => $spaceId, 'm' => $memberId, 'msg' => $message]);
    $id = (int) $st->fetchColumn();
    $who = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $memberId]);
    $owners = $pdo->prepare("SELECT member_id FROM space_members WHERE space_id = :s AND role = 'owner'");
    $owners->execute(['s' => $spaceId]);
    foreach ($owners->fetchAll(PDO::FETCH_COLUMN) as $owner) {
        $pdo->prepare("SELECT sp_notify(:o, 'join_request', :t, :b, 'space', :s, NULL, NULL, NULL, :d)")
            ->execute(['o' => (int) $owner, 't' => $who . ' asks to join ' . $s['name'], 'b' => $message === null ? null : mb_substr($message, 0, 200), 's' => $spaceId, 'd' => 'join_request:' . $id . ':' . (int) $owner]);
    }
    return $id;
}

/** Withdraw my pending request. */
function withdraw_request(PDO $pdo, int $spaceId, int $memberId): int
{
    $st = $pdo->prepare("UPDATE space_join_requests SET status = 'withdrawn', decided_at = now() WHERE space_id = :s AND member_id = :m AND status = 'pending' RETURNING id");
    $st->execute(['s' => $spaceId, 'm' => $memberId]);
    $id = $st->fetchColumn();
    if ($id === false) {
        throw new DomainException('You have no pending request here.');
    }
    return (int) $id;
}

/** Decide a pending request: approve inserts the member row; the requester is told either way. Returns [space_id, member_id, decision]. */
function decide_request(PDO $pdo, int $requestId, bool $approve, int $by): array
{
    $st = $pdo->prepare('SELECT r.space_id, r.member_id, r.status, s.name FROM space_join_requests r JOIN spaces s ON s.id = r.space_id WHERE r.id = :id');
    $st->execute(['id' => $requestId]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    if ($r['status'] !== 'pending') {
        throw new DomainException('This request was already ' . $r['status'] . '.');
    }
    $pdo->prepare('UPDATE space_join_requests SET status = :st, decided_by = :by, decided_at = now() WHERE id = :id')->execute(['st' => $approve ? 'approved' : 'declined', 'by' => $by, 'id' => $requestId]);
    if ($approve) {
        $pdo->prepare("INSERT INTO space_members (space_id, member_id, role, added_by) VALUES (:s, :m, 'member', :by) ON CONFLICT (space_id, member_id) DO NOTHING")->execute(['s' => $r['space_id'], 'm' => $r['member_id'], 'by' => $by]);
    }
    $pdo->prepare("SELECT sp_notify(:m, 'join_decided', :t, NULL, 'space', :s, NULL, NULL, NULL, :d)")
        ->execute(['m' => (int) $r['member_id'], 't' => $approve ? 'You are in ' . $r['name'] . ' now' : 'Your request to join ' . $r['name'] . ' was declined', 's' => (int) $r['space_id'], 'd' => 'join_decided:' . $requestId]);
    return ['space_id' => (int) $r['space_id'], 'member_id' => (int) $r['member_id'], 'decision' => $approve ? 'approved' : 'declined'];
}

/** Add a member (a person or an agent), or every live member of a department, with a role. Returns the rows added; each is told. */
function add_space_member(PDO $pdo, int $spaceId, ?int $memberId, ?int $departmentId, string $role, int $by): int
{
    $s = space_state($pdo, $spaceId) ?? throw new DomainException('Not found.');
    if ($memberId !== null) {
        $ids = [$memberId];
    } elseif ($departmentId !== null) {
        $st = $pdo->prepare("SELECT dm.member_id FROM department_members dm JOIN members m ON m.id = dm.member_id WHERE dm.department_id = :d AND dm.left_at IS NULL AND m.status = 'active' AND m.capability IS NOT NULL ORDER BY dm.member_id");
        $st->execute(['d' => $departmentId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            throw new DomainException('That department has nobody admitted to Spaces.');
        }
    } else {
        throw new DomainException('Say whom to add: a member or a department.');
    }
    $adder = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $by]);
    $rows = 0;
    foreach ($ids as $mid) {
        $st = $pdo->prepare('INSERT INTO space_members (space_id, member_id, role, added_by) VALUES (:s, :m, :r, :by) ON CONFLICT (space_id, member_id) DO NOTHING');
        $st->execute(['s' => $spaceId, 'm' => $mid, 'r' => $role, 'by' => $by]);
        if ($st->rowCount() === 1) {
            $rows++;
            $pdo->prepare("SELECT sp_notify(:m, 'share', :t, NULL, 'space', :s, NULL, NULL, NULL, :d)")->execute(['m' => $mid, 't' => $adder . ' added you to ' . $s['name'], 's' => $spaceId, 'd' => 'space_added:' . $spaceId . ':' . $mid]);
        }
    }
    return $rows;
}

/** Remove a member's row (the guard keeps the last owner; General refuses everyone). A derived member has no row: refused in words. */
function remove_space_member(PDO $pdo, int $spaceId, int $memberId, int $by): void
{
    $s = space_state($pdo, $spaceId) ?? throw new DomainException('Not found.');
    $who = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $memberId]);
    $st = $pdo->prepare('DELETE FROM space_members WHERE space_id = :s AND member_id = :m');
    $st->execute(['s' => $spaceId, 'm' => $memberId]);
    if ($st->rowCount() === 1) {
        return;
    }
    if ($s['department_id'] !== null && db_bool($pdo, 'SELECT :m IN (SELECT sp_space_member_ids(:s))', ['m' => $memberId, 's' => $spaceId])) {
        throw new DomainException($who . ' is in ' . $s['name'] . ' through the department; they leave with it, in HR.');
    }
    throw new DomainException($who . ' is not in ' . $s['name'] . '.');
}

/** Make a member an owner, or step an owner down (the guard keeps the last one; a guest never owns). */
function set_space_owner(PDO $pdo, int $spaceId, int $memberId, bool $owner, int $by): void
{
    $s = space_state($pdo, $spaceId) ?? throw new DomainException('Not found.');
    if ($owner) {
        $st = $pdo->prepare("INSERT INTO space_members (space_id, member_id, role, added_by) VALUES (:s, :m, 'owner', :by) ON CONFLICT (space_id, member_id) DO UPDATE SET role = 'owner'");
        $st->execute(['s' => $spaceId, 'm' => $memberId, 'by' => $by]);
        return;
    }
    $st = $pdo->prepare("UPDATE space_members SET role = 'member' WHERE space_id = :s AND member_id = :m AND role = 'owner'");
    $st->execute(['s' => $spaceId, 'm' => $memberId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException(one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $memberId]) . ' does not own ' . $s['name'] . '.');
    }
}

/** Add ($id null) a section after another (or last), or rename / move an existing one. A duplicate name is refused in words. */
function save_section(PDO $pdo, ?int $id, int $spaceId, ?string $name, ?int $after, bool $afterSent, int $by): int
{
    $s = space_state($pdo, $spaceId) ?? throw new DomainException('Not found.');
    if ($name !== null) {
        $dupe = $pdo->prepare('SELECT 1 FROM space_sections WHERE space_id = :s AND lower(name) = lower(:n) AND id IS DISTINCT FROM :id');
        $dupe->execute(['s' => $spaceId, 'n' => $name, 'id' => $id]);
        if ($dupe->fetchColumn() !== false) {
            throw new DomainException($s['name'] . ' already has a section named ' . $name . '.');
        }
    }
    $position = null;
    if ($id === null || $afterSent) {
        $position = section_position_after($pdo, $spaceId, $after, $afterSent, $id);
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO space_sections (space_id, name, position) VALUES (:s, :n, :p) RETURNING id');
        $st->execute(['s' => $spaceId, 'n' => $name ?? throw new DomainException('Give the section a name.'), 'p' => $position]);
        return (int) $st->fetchColumn();
    }
    $cur = $pdo->prepare('SELECT name FROM space_sections WHERE id = :id AND space_id = :s');
    $cur->execute(['id' => $id, 's' => $spaceId]);
    if ($cur->fetchColumn() === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('UPDATE space_sections SET name = COALESCE(:n, name), position = COALESCE(:p, position) WHERE id = :id')->execute(['n' => $name, 'p' => $position, 'id' => $id]);
    return $id;
}

/** A position after the section $after (sent empty: first; not sent: last), skipping $self. */
function section_position_after(PDO $pdo, int $spaceId, ?int $after, bool $afterSent, ?int $self): string
{
    $rows = $pdo->prepare('SELECT id, position FROM space_sections WHERE space_id = :s AND id IS DISTINCT FROM :self ORDER BY position, id');
    $rows->execute(['s' => $spaceId, 'self' => $self]);
    $list = $rows->fetchAll();
    if ($after === null && !$afterSent) {
        $last = $list === [] ? null : $list[count($list) - 1]['position'];
        return (string) one_value($pdo, 'SELECT sp_position_between(:a, NULL)', ['a' => $last]);
    }
    $prev = null;
    $next = null;
    if ($after === null) {
        $next = $list[0]['position'] ?? null;
    } else {
        foreach ($list as $i => $r) {
            if ((int) $r['id'] === $after) {
                $prev = $r['position'];
                $next = $list[$i + 1]['position'] ?? null;
                break;
            }
        }
        if ($prev === null) {
            throw new DomainException('The section to follow is not in this space.');
        }
    }
    return (string) one_value($pdo, 'SELECT sp_position_between(:a, :b)', ['a' => $prev, 'b' => $next]);
}

/** Delete a section; its pages go to the space's root (the FK sets section_id NULL). Returns the pages moved. */
function delete_section(PDO $pdo, int $id, int $by): int
{
    $moved = (int) one_value($pdo, 'SELECT count(*) FROM pages WHERE section_id = :id', ['id' => $id]);
    $st = $pdo->prepare('DELETE FROM space_sections WHERE id = :id');
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
    return $moved;
}

/** Put a root page of the space into a section (or none), after another root page of that section (or first). */
function assign_page_section(PDO $pdo, int $spaceId, string $pageUuid, ?int $sectionId, ?string $afterUuid, bool $afterSent, int $by): void
{
    $st = $pdo->prepare('SELECT plain_title, space_id, parent_page_id, archived_at FROM pages WHERE id = CAST(:p AS uuid)');
    $st->execute(['p' => $pageUuid]);
    $p = $st->fetch();
    if ($p === false || (int) $p['space_id'] !== $spaceId || $p['parent_page_id'] !== null || $p['archived_at'] !== null) {
        throw new DomainException('Only a root page of this space goes into a section.');
    }
    if ($sectionId !== null && !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM space_sections WHERE id = :id AND space_id = :s)', ['id' => $sectionId, 's' => $spaceId])) {
        throw new DomainException('That section is not in this space.');
    }
    $rows = $pdo->prepare('SELECT id::text AS id, position FROM pages WHERE space_id = :s AND parent_page_id IS NULL AND parent_database_id IS NULL AND archived_at IS NULL AND NOT is_template
                             AND section_id IS NOT DISTINCT FROM :sec AND id <> CAST(:p AS uuid) ORDER BY position, created_at');
    $rows->execute(['s' => $spaceId, 'sec' => $sectionId, 'p' => $pageUuid]);
    $list = $rows->fetchAll();
    $prev = null;
    $next = null;
    if (!$afterSent) {
        $prev = $list === [] ? null : $list[count($list) - 1]['position'];               // last
    } elseif ($afterUuid === null || $afterUuid === '') {
        $next = $list[0]['position'] ?? null;                                             // first
    } else {
        foreach ($list as $i => $r) {
            if ($r['id'] === $afterUuid) {
                $prev = $r['position'];
                $next = $list[$i + 1]['position'] ?? null;
                break;
            }
        }
        if ($prev === null) {
            throw new DomainException('The page to follow is not in that section.');
        }
    }
    $pdo->prepare('UPDATE pages SET section_id = :sec, position = sp_position_between(:a, :b) WHERE id = CAST(:p AS uuid)')->execute(['sec' => $sectionId, 'a' => $prev, 'b' => $next, 'p' => $pageUuid]);
}
