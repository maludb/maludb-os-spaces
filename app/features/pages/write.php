<?php
declare(strict_types=1);

/**
 * Writes to pages, page_permissions, page_favorites, page_publications and the verbs of db/008 (sp_page_create, sp_page_move, sp_page_trash,
 * sp_page_purge, sp_page_set_locked, sp_page_duplicate) and db/007 (sp_page_restrict, sp_page_unrestrict, sp_page_restore). A refusal is a
 * DomainException in words; the database's own (P0001) is translated by sp_guard(). Every function runs inside the handler's transaction.
 */

/** Make a page: in a space's root, under a parent, or privately; from a template when given. `markdown` becomes one paragraph block (slice 3 brings the converter). */
function create_page(PDO $pdo, ?int $spaceId, ?string $parentUuid, string $title, ?string $icon, ?string $templateUuid, ?string $markdown, int $by): string
{
    if ($templateUuid !== null) {
        $st = $pdo->prepare('SELECT sp_page_duplicate(CAST(:t AS uuid), :s, CAST(:p AS uuid), sp_rich_text(:title), false)::text');
        $st->execute(['t' => $templateUuid, 's' => $spaceId, 'p' => $parentUuid, 'title' => $title]);
        $id = (string) $st->fetchColumn();
        if ($icon !== null) {
            $pdo->prepare('UPDATE pages SET icon = :i WHERE id = CAST(:id AS uuid)')->execute(['i' => $icon, 'id' => $id]);
        }
    } else {
        $st = $pdo->prepare("SELECT sp_page_create(:s, CAST(:p AS uuid), sp_rich_text(:title), 'page', :icon)::text");
        $st->execute(['s' => $spaceId, 'p' => $parentUuid, 'title' => $title, 'icon' => $icon]);
        $id = (string) $st->fetchColumn();
    }
    if ($markdown !== null && trim($markdown) !== '') {
        $pdo->prepare("SELECT sp_block_insert(CAST(:id AS uuid), NULL, NULL, 'paragraph', jsonb_build_object('rich_text', sp_rich_text(:md)))")->execute(['id' => $id, 'md' => trim($markdown)]);
    }
    return $id;
}

/** Change a page's title, icon or cover (a field left out stays; a locked page is the guard's). */
function update_page(PDO $pdo, string $uuid, array $f, int $by): void
{
    $sets = [];
    $args = ['id' => $uuid];
    if (array_key_exists('title', $f)) { $sets[] = 'title = sp_rich_text(:t)'; $args['t'] = (string) $f['title']; }
    if (array_key_exists('icon', $f)) { $sets[] = 'icon = :i'; $args['i'] = $f['icon']; }
    if (array_key_exists('cover_attachment_id', $f)) { $sets[] = 'cover_attachment_id = :c'; $args['c'] = $f['cover_attachment_id']; }
    if ($sets === []) {
        return;
    }
    $pdo->prepare('UPDATE pages SET ' . implode(', ', $sets) . ' WHERE id = CAST(:id AS uuid)')->execute($args);
}

/** Move a page under a parent, to a space's root, or to the caller's private pages; `after` orders it among its new siblings. */
function move_page(PDO $pdo, string $uuid, ?string $parentUuid, ?int $spaceId, bool $private, ?string $afterUuid, int $by): void
{
    if ($private) {
        $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
        if ($s['parent_database_id'] !== null) {
            throw new DomainException('A database row stays in its database');
        }
        $pdo->exec("SELECT set_config('app.sp_purging', '1', false)");
        $pdo->prepare("DELETE FROM blocks b WHERE b.type IN ('child_page', 'child_database') AND COALESCE((b.content->>'page_id')::uuid, (b.content->>'database_id')::uuid) = CAST(:id AS uuid)")->execute(['id' => $uuid]);
        $pdo->exec("SELECT set_config('app.sp_purging', '', false)");
        $pdo->prepare('UPDATE pages SET space_id = NULL, parent_page_id = NULL, owner_member_id = :me, section_id = NULL, position = sp_position_between((SELECT max(x.position) FROM pages x WHERE x.space_id IS NULL AND x.owner_member_id = :me AND x.parent_page_id IS NULL AND x.archived_at IS NULL AND x.id <> CAST(:id AS uuid)), NULL) WHERE id = CAST(:id AS uuid)')
            ->execute(['me' => $by, 'id' => $uuid]);
        $pdo->prepare('WITH RECURSIVE sub AS (SELECT id FROM pages WHERE parent_page_id = CAST(:id AS uuid) UNION ALL SELECT c.id FROM pages c JOIN sub ON c.parent_page_id = sub.id)
                       UPDATE pages x SET space_id = NULL, owner_member_id = :me, section_id = NULL FROM sub WHERE x.id = sub.id')->execute(['id' => $uuid, 'me' => $by]);
        $pdo->prepare('SELECT sp_recompute_permission_roots(CAST(:id AS uuid))')->execute(['id' => $uuid]);
        return;
    }
    $pdo->prepare('SELECT sp_page_move(CAST(:id AS uuid), CAST(:p AS uuid), :s)')->execute(['id' => $uuid, 'p' => $parentUuid, 's' => $spaceId]);
    if ($afterUuid !== null) {
        reorder_page($pdo, $uuid, $afterUuid);
    }
}

/** Put a page right after a sibling (a root page by pages.position; a subpage by its edge block in the parent). */
function reorder_page(PDO $pdo, string $uuid, string $afterUuid): void
{
    $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
    $a = page_state($pdo, $afterUuid) ?? throw new DomainException('The page to follow does not exist.');
    if ($a['parent_page_id'] !== $s['parent_page_id'] || $a['space_id'] !== $s['space_id']) {
        throw new DomainException('The page to follow is not a sibling.');
    }
    if ($s['parent_page_id'] === null) {
        $st = $pdo->prepare('SELECT position FROM pages WHERE parent_page_id IS NULL AND space_id IS NOT DISTINCT FROM :s AND archived_at IS NULL AND id <> CAST(:id AS uuid) AND position > (SELECT position FROM pages WHERE id = CAST(:a AS uuid)) ORDER BY position LIMIT 1');
        $st->execute(['s' => $s['space_id'], 'id' => $uuid, 'a' => $afterUuid]);
        $next = $st->fetchColumn();
        $pdo->prepare('UPDATE pages SET position = sp_position_between((SELECT position FROM pages WHERE id = CAST(:a AS uuid)), :n) WHERE id = CAST(:id AS uuid)')->execute(['a' => $afterUuid, 'n' => $next === false ? null : $next, 'id' => $uuid]);
        return;
    }
    $edge = static fn (string $pid) => $pdo->query("SELECT id::text FROM blocks WHERE page_id = '" . $s['parent_page_id'] . "' AND parent_block_id IS NULL AND type IN ('child_page', 'child_database') AND COALESCE(content->>'page_id', content->>'database_id') = '" . $pid . "'")->fetchColumn();
    $mine = $edge($uuid);
    $theirs = $edge($afterUuid);
    if ($mine === false || $theirs === false) {
        throw new DomainException('The page to follow is not a sibling.');
    }
    $pdo->prepare('SELECT sp_block_move(CAST(:b AS uuid), NULL, CAST(:a AS uuid))')->execute(['b' => $mine, 'a' => $theirs]);
    $pdo->prepare('UPDATE pages SET position = (SELECT position FROM blocks WHERE id = CAST(:b AS uuid)) WHERE id = CAST(:id AS uuid)')->execute(['b' => $mine, 'id' => $uuid]);
}

/** A copy of the page (its blocks and subpages) beside the original or elsewhere, titled "<title> (copy)" unless given. */
function duplicate_page(PDO $pdo, string $uuid, ?string $title, ?string $parentUuid, ?int $spaceId, bool $samePlace, int $by): string
{
    $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
    if ($samePlace) {
        $parentUuid = $s['parent_page_id'];
        $spaceId = $s['space_id'];
    }
    $st = $pdo->prepare('SELECT sp_page_duplicate(CAST(:id AS uuid), :s, CAST(:p AS uuid), sp_rich_text(:t), false)::text');
    $st->execute(['id' => $uuid, 's' => $spaceId, 'p' => $parentUuid, 't' => $title ?? (($s['plain_title'] !== '' ? $s['plain_title'] : 'Untitled') . ' (copy)')]);
    return (string) $st->fetchColumn();
}

/** Trash a page; its subtree goes with it. Returns the subtree's count (the page included). */
function trash_page(PDO $pdo, string $uuid, int $by): int
{
    $pdo->prepare('SELECT sp_page_trash(CAST(:id AS uuid))')->execute(['id' => $uuid]);
    return (int) one_value($pdo, 'SELECT 1 + count(*) FROM pages WHERE archived_via = CAST(:id AS uuid)', ['id' => $uuid]);
}

function restore_page(PDO $pdo, string $uuid, int $by): void
{
    $pdo->prepare('SELECT sp_page_restore(CAST(:id AS uuid))')->execute(['id' => $uuid]);
}

/** Delete a trashed page and its subtree for good. Returns how many pages went. */
function purge_page(PDO $pdo, string $uuid, int $by): int
{
    return (int) one_value($pdo, 'SELECT sp_page_purge(CAST(:id AS uuid))', ['id' => $uuid]);
}

/** Empty the trash the caller may see (or a space's). Returns how many pages went. */
function purge_trash(PDO $pdo, ?int $spaceId, int $by): int
{
    $n = 0;
    foreach (trash_list($pdo, $spaceId) as $t) {
        if (db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM pages WHERE id = CAST(:id AS uuid))', ['id' => $t['page_id']])) {
            $n += purge_page($pdo, (string) $t['page_id'], $by);
        }
    }
    return $n;
}

/** Lock (a version saved first) or unlock. Returns the version number saved (0 on unlock). */
function set_page_lock(PDO $pdo, string $uuid, bool $locked, int $by): int
{
    $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
    if ($s['is_locked'] === $locked) {
        throw new DomainException('Page "' . $s['plain_title'] . '" is already ' . ($locked ? 'locked' : 'unlocked') . '.');
    }
    $vn = 0;
    if ($locked) {
        $vn = (int) one_value($pdo, "SELECT sp_version_save(CAST(:id AS uuid), 'lock')", ['id' => $uuid]);
    }
    $pdo->prepare('SELECT sp_page_set_locked(CAST(:id AS uuid), :l)')->execute(['id' => $uuid, 'l' => $locked ? 't' : 'f']);
    return $vn;
}

function set_favorite(PDO $pdo, string $uuid, int $memberId, bool $on): bool
{
    if ($on) {
        $st = $pdo->prepare('INSERT INTO page_favorites (member_id, page_id, position) VALUES (:m, CAST(:id AS uuid), sp_position_between((SELECT max(position) FROM page_favorites WHERE member_id = :m), NULL)) ON CONFLICT DO NOTHING');
    } else {
        $st = $pdo->prepare('DELETE FROM page_favorites WHERE member_id = :m AND page_id = CAST(:id AS uuid)');
    }
    $st->execute(['m' => $memberId, 'id' => $uuid]);
    return $st->rowCount() === 1;
}

/** Share a page with a member (a person or an agent), a department or a guest at a level. The trigger carries the space's default along on the first row. */
function share_page(PDO $pdo, string $uuid, string $kind, int $principalId, string $level, int $by): void
{
    $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
    if ($kind === 'member') {
        $mk = one_value($pdo, "SELECT member_kind FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL", ['m' => $principalId]) ?? throw new DomainException('That member is not here.');
        if (db_bool($pdo, 'SELECT sp_member_is_guest(:m)', ['m' => $principalId])) {
            throw new DomainException(one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $principalId]) . ' is a guest: share to a guest with its own action.');
        }
        $kind = $mk === 'agent' ? 'agent' : 'member';
        if ($s['space_id'] === null && $s['owner_member_id'] === $principalId) {
            throw new DomainException('The owner already has full access.');
        }
    } elseif ($kind === 'guest') {
        if (!db_bool($pdo, 'SELECT sp_member_is_guest(:m)', ['m' => $principalId])) {
            throw new DomainException(one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $principalId]) . ' is a member: use Share.');
        }
        if (!in_array($level, GUEST_LEVELS, true)) {
            throw new DomainException('A guest is shared at view, comment or edit.');
        }
    } elseif ($kind === 'department') {
        if (!db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM departments WHERE id = :d AND archived_at IS NULL)', ['d' => $principalId])) {
            throw new DomainException('That department is not here.');
        }
    } else {
        throw new DomainException('Share with a member, a department or a guest.');
    }
    $st = $pdo->prepare('UPDATE page_permissions SET level = :l, granted_by = :by WHERE page_id = CAST(:id AS uuid) AND principal_kind = :k AND principal_id = :p');
    $st->execute(['l' => $level, 'by' => $by, 'id' => $uuid, 'k' => $kind, 'p' => $principalId]);
    if ($st->rowCount() === 0) {
        $pdo->prepare('INSERT INTO page_permissions (page_id, principal_kind, principal_id, level, granted_by) VALUES (CAST(:id AS uuid), :k, :p, :l, :by)')
            ->execute(['id' => $uuid, 'k' => $kind, 'p' => $principalId, 'l' => $level, 'by' => $by]);
    }
}

/** Remove a principal's row; when only the everyone rows remain, they go too (the space's default is simply back). */
function unshare_page(PDO $pdo, string $uuid, int $principalId, int $by): void
{
    $st = $pdo->prepare("DELETE FROM page_permissions WHERE page_id = CAST(:id AS uuid) AND principal_kind IN ('member', 'agent', 'department', 'guest') AND principal_id = :p");
    $st->execute(['id' => $uuid, 'p' => $principalId]);
    if ($st->rowCount() === 0) {
        throw new DomainException('This page is not shared with them.');
    }
    if ((int) one_value($pdo, "SELECT count(*) FROM page_permissions WHERE page_id = CAST(:id AS uuid) AND principal_kind NOT IN ('everyone_in_space', 'everyone')", ['id' => $uuid]) === 0) {
        $pdo->prepare('DELETE FROM page_permissions WHERE page_id = CAST(:id AS uuid)')->execute(['id' => $uuid]);
    }
}

function restrict_page(PDO $pdo, string $uuid, bool $restrict, int $by): void
{
    $pdo->prepare('SELECT ' . ($restrict ? 'sp_page_restrict' : 'sp_page_unrestrict') . '(CAST(:id AS uuid))')->execute(['id' => $uuid]);
}

/** Publish a page to the web. Returns the token (shown once; the hash is stored). */
function publish_page(PDO $pdo, string $uuid, array $opts, int $by): string
{
    $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
    if (db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM page_publications WHERE page_id = CAST(:id AS uuid) AND revoked_at IS NULL)', ['id' => $uuid])) {
        throw new DomainException('Page "' . $s['plain_title'] . '" is already published: rotate its link or unpublish it.');
    }
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO page_publications (page_id, token_hash, include_subpages, noindex, public_properties, layout, published_by) VALUES (CAST(:id AS uuid), :h, :sub, :noi, CAST(:props AS text[]), :layout, :by)')
        ->execute(['id' => $uuid, 'h' => hash('sha256', $token), 'sub' => !empty($opts['include_subpages']) ? 't' : 'f', 'noi' => ($opts['noindex'] ?? true) ? 't' : 'f',
                   'props' => pg_array_literal($opts['public_properties'] ?? []), 'layout' => $opts['layout'] ?? null, 'by' => $by]);
    return $token;
}

/** A new link; the old one stops now. Returns the new token. */
function rotate_publication(PDO $pdo, string $uuid, int $by): string
{
    $old = publication($pdo, $uuid) ?? throw new DomainException('This page is not published.');
    $pdo->prepare('UPDATE page_publications SET revoked_at = now(), revoked_by = :by WHERE id = :pid')->execute(['by' => $by, 'pid' => $old['publication_id']]);
    return publish_page($pdo, $uuid, ['include_subpages' => $old['include_subpages'], 'noindex' => $old['noindex'], 'public_properties' => $old['public_properties'], 'layout' => $old['layout']], $by);
}

function unpublish_page(PDO $pdo, string $uuid, int $by): void
{
    $st = $pdo->prepare('UPDATE page_publications SET revoked_at = now(), revoked_by = :by WHERE page_id = CAST(:id AS uuid) AND revoked_at IS NULL');
    $st->execute(['by' => $by, 'id' => $uuid]);
    if ($st->rowCount() === 0) {
        throw new DomainException('This page is not published.');
    }
}

/** Verify a wiki page for N months (the space's default, else the workspace's). */
function verify_page(PDO $pdo, string $uuid, ?int $months, int $by): string
{
    $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
    if ($s['space_id'] === null || !db_bool($pdo, 'SELECT is_wiki FROM spaces WHERE id = :s', ['s' => $s['space_id']])) {
        throw new DomainException('Only a page of a wiki space is verified.');
    }
    $months ??= (int) (one_value($pdo, 'SELECT COALESCE(wiki_default_verify_months, (SELECT wiki_default_verify_months FROM sp_settings WHERE id = 1)) FROM spaces WHERE id = :s', ['s' => $s['space_id']]) ?: 6);
    $st = $pdo->prepare("UPDATE pages SET verification_state = 'verified', verified_at = now(), verified_by = :by, verify_until = now() + make_interval(months => :m) WHERE id = CAST(:id AS uuid) RETURNING verify_until::text");
    $st->execute(['by' => $by, 'm' => $months, 'id' => $uuid]);
    return (string) $st->fetchColumn();
}

/** The wiki page's owner; the new owner is told. */
function set_page_owner(PDO $pdo, string $uuid, int $memberId, int $by): void
{
    $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
    if (!db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL)", ['m' => $memberId])) {
        throw new DomainException('That member is not here.');
    }
    $pdo->prepare('UPDATE pages SET wiki_owner_member_id = :m WHERE id = CAST(:id AS uuid)')->execute(['m' => $memberId, 'id' => $uuid]);
    $pdo->prepare("SELECT sp_notify(:m, 'share', :t, NULL, 'page', NULL, CAST(:id AS uuid), NULL, NULL, :d)")->execute(['m' => $memberId, 't' => 'You now own ' . ($s['plain_title'] !== '' ? $s['plain_title'] : 'a page'), 'id' => $uuid, 'd' => 'page_owner:' . $uuid . ':' . $memberId]);
}

/** A page from a template, at a destination (a space's root or under a page). */
function apply_template(PDO $pdo, string $templateUuid, ?int $spaceId, ?string $parentUuid, ?string $title, int $by): string
{
    $t = page_state($pdo, $templateUuid) ?? throw new DomainException('Not found.');
    if (!$t['is_template']) {
        throw new DomainException('That page is not a template.');
    }
    $st = $pdo->prepare('SELECT sp_page_duplicate(CAST(:t AS uuid), :s, CAST(:p AS uuid), CASE WHEN CAST(:title AS text) IS NULL THEN NULL ELSE sp_rich_text(CAST(:title2 AS text)) END, false)::text');
    $st->execute(['t' => $templateUuid, 's' => $spaceId, 'p' => $parentUuid, 'title' => $title, 'title2' => $title]);
    return (string) $st->fetchColumn();
}

/** Make a page a template, or stop (it leaves the sidebar and the search while one). */
function set_template(PDO $pdo, string $uuid, bool $on, int $by): void
{
    $s = page_state($pdo, $uuid) ?? throw new DomainException('Not found.');
    if ($s['space_id'] === null) {
        throw new DomainException('A private page is not a template: move it into a space first.');
    }
    if ($s['is_template'] === $on) {
        throw new DomainException('Page "' . $s['plain_title'] . '" ' . ($on ? 'is a template already.' : 'is not a template.'));
    }
    $pdo->prepare('UPDATE pages SET is_template = :t WHERE id = CAST(:id AS uuid)')->execute(['t' => $on ? 't' : 'f', 'id' => $uuid]);
}
