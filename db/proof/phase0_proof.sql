-- Phase 0 proof of the schema (docs/spaces-design.md §10). Everything happens inside one transaction that is ROLLED BACK:
-- fixtures written as spaces_rw, reads as the two read roles, acting members set through app.member_id exactly as PHP and
-- the MCP servers set it. Run as postgres on the SCRATCH database:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d sp_dev -f db/proof/phase0_proof.sql
-- Prints one line per check (ok / FAIL) and the count.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint) TO PUBLIC;
CREATE TEMP TABLE ids (k text PRIMARY KEY, u uuid, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.u(k text) RETURNS uuid LANGUAGE sql STABLE AS $$ SELECT u FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
GRANT EXECUTE ON FUNCTION pg_temp.u(text), pg_temp.i(text) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer: every actor of §2
SET ROLE spaces_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',   'owner@example.invalid', 'super_admin', false, 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Priya',   'priya@example.invalid', 'user', false, 'active', 'write', '{user}'),
 (27, 'human', 'SMOKE Marco',   'marco@example.invalid', 'user', false, 'active', 'write', '{space_owner,user}'),
 (28, 'human', 'SMOKE Bea',     'bea@example.invalid',   'user', false, 'active', 'write', '{user}'),
 (29, 'human', 'SMOKE Ann',     'ann@cpa.invalid',       'user', true,  'active', 'read',  '{guest}'),
 (30, 'human', 'SMOKE Dana',    'dana@example.invalid',  'user', false, 'active', 'write', '{user}'),
 (31, 'human', 'SMOKE Lee',     'lee@example.invalid',   'user', false, 'active', 'write', '{user}'),
 (40, 'agent', 'SMOKE Seamus',  NULL,                    'user', false, 'active', 'write', '{user}'),
 (41, 'agent', 'SMOKE Watcher', NULL,                    'user', false, 'active', NULL,    '{}');
INSERT INTO departments (id, name, is_system, system_key, manager_member_id) VALUES
 (3, 'Accounting', true, 'accounting', NULL), (5, 'IT', true, 'it', 1), (7, 'Design', false, NULL, 27), (8, 'Engineering', false, NULL, NULL);
INSERT INTO department_members (member_id, department_id, is_admin, is_primary) VALUES
 (1, 5, true, true), (26, 7, false, true), (27, 7, true, true), (28, 3, false, true), (30, 8, false, true), (31, 8, false, true);
UPDATE sp_settings SET business_name = 'SMOKE Business';

-- ---------------------------------------------------------------- actors and rights (§2, §3, D2)
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(sp_is_admin() AND sp_has_right('publish.web') AND sp_has_right('settings.manage') AND NOT sp_is_guest(), 'the super-admin holds the admin role: publish.web, settings.manage, not a guest');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(sp_has_right('pages.write') AND sp_has_right('channels.create') AND sp_has_right('dm.write') AND NOT sp_has_right('share.guest') AND NOT sp_has_right('publish.web') AND NOT sp_is_admin(), 'a Member writes pages and channels, cannot share to a guest or publish');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(sp_has_right('space.manage') AND sp_has_right('share.guest') AND NOT sp_has_right('publish.web'), 'a Space owner manages spaces and shares to guests, cannot publish');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(sp_is_guest() AND sp_has_right('spaces.guest') AND NOT sp_has_right('pages.write') AND NOT sp_has_right('spaces.join') AND NOT sp_is_member_here(), 'a guest holds spaces.guest and nothing else, is not a member here');
SELECT pg_temp.as_member(40);
SELECT pg_temp.check(sp_has_right('pages.write') AND sp_has_right('channels.write') AND app_member_kind() = 'agent' AND NOT sp_is_guest(), 'an agent granted Member has a Member''s rights');
SELECT pg_temp.as_member(41);
SELECT pg_temp.check(NOT app_is_active_member() AND NOT sp_has_right('pages.write') AND (SELECT count(*) FROM sp_visible_page_ids()) = 0, 'an agent not yet admitted sees nothing and may do nothing');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT count(*) FROM sp_visible_page_ids()) = 0 AND (SELECT count(*) FROM sp_visible_channel_ids()) = 0 AND NOT sp_has_right('pages.write'), 'no acting member: every rule denies');
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 4 AND (SELECT count(*) FROM mcp_app_roles WHERE is_admin) = 1
    AND (SELECT rights FROM mcp_app_roles WHERE role_key = 'guest') = '{spaces.guest}', 'app_roles answers the four roles, one admin, the guest with one right');

-- ---------------------------------------------------------------- spaces of each kind, and who sees them (§3, D4, D5)
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM spaces WHERE is_default AND kind = 'open' AND name = 'General') = 1, 'General is seeded: open, the default');
SELECT pg_temp.check((SELECT count(*) FROM spaces WHERE department_id IN (3, 5) AND kind = 'closed') = 2 AND (SELECT count(*) FROM spaces WHERE department_id IN (7, 8)) = 0, 'a closed space per STANDING department (Accounting, IT), none for the others');
SELECT pg_temp.check((SELECT role FROM space_members sm JOIN spaces s ON s.id = sm.space_id WHERE s.department_id = 5 AND sm.member_id = 1) = 'owner', 'the department''s manager owns its space');
SELECT pg_temp.check((SELECT count(*) FROM channels c JOIN spaces s ON s.default_channel_id = c.id WHERE c.is_default AND c.name = 'general') = 3, 'every space has its #general');
INSERT INTO ids (k, i) SELECT 'general', id FROM spaces WHERE is_default;
INSERT INTO ids (k, i) SELECT 'it_space', id FROM spaces WHERE department_id = 5;
INSERT INTO ids (k, i) SELECT 'gch', default_channel_id FROM spaces WHERE is_default;
SELECT pg_temp.check((SELECT array_agg(x ORDER BY x) FROM sp_space_member_ids(pg_temp.i('general')) x) = '{1,26,27,28,30,31,40}', 'General''s members are every admitted non-guest, agents included; not the guest, not the unadmitted agent');
SELECT pg_temp.check((SELECT array_agg(x ORDER BY x) FROM sp_space_member_ids(pg_temp.i('it_space')) x) = '{1}', 'the IT space''s members are the department''s (derived from the mirror)');
-- Marco makes a private space and a closed one
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO spaces (name, slug, kind, member_level, everyone_level, created_by) VALUES ('Design leads', 'design-leads', 'private', 'edit', 'none', 27) RETURNING id) INSERT INTO ids (k, i) SELECT 'priv', id FROM ins;
INSERT INTO space_members (space_id, member_id, role) VALUES (pg_temp.i('priv'), 27, 'owner'), (pg_temp.i('priv'), 26, 'member');
WITH ins AS (INSERT INTO spaces (name, slug, kind, member_level, everyone_level, created_by) VALUES ('Product', 'product', 'closed', 'edit', 'none', 27) RETURNING id) INSERT INTO ids (k, i) SELECT 'prod', id FROM ins;
INSERT INTO space_members (space_id, member_id, role) VALUES (pg_temp.i('prod'), 27, 'owner');
SELECT pg_temp.refused($$INSERT INTO spaces (name, slug, kind, everyone_level) VALUES ('Bad', 'bad', 'closed', 'view')$$, 'violates check', 'only an open space reaches everyone (CHECK)');
SELECT pg_temp.refused($$UPDATE spaces SET is_default = false WHERE is_default$$, 'stays the default', 'the default space stays the default');
SELECT pg_temp.refused($$UPDATE spaces SET kind = 'closed' WHERE is_default$$, 'open to everyone', 'the default space stays open');
SELECT pg_temp.refused($$DELETE FROM space_members WHERE space_id = pg_temp.i('general') AND member_id = 26$$, 'Nobody leaves', 'nobody leaves the default space');
SELECT pg_temp.refused($$DELETE FROM space_members WHERE space_id = pg_temp.i('priv') AND member_id = 27$$, 'needs an owner', 'the last owner cannot leave a space');
SELECT pg_temp.refused($$INSERT INTO space_members (space_id, member_id, role) VALUES (pg_temp.i('prod'), 29, 'owner')$$, 'guest never owns', 'a guest never owns a space');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(pg_temp.i('priv') NOT IN (SELECT sp_visible_space_ids()) AND pg_temp.i('prod') IN (SELECT sp_visible_space_ids()) AND pg_temp.i('prod') NOT IN (SELECT sp_member_space_ids()), 'Dana sees the closed space (may request), never the private one');
SELECT pg_temp.check(sp_space_level(pg_temp.i('general')) = 'edit' AND sp_space_level(pg_temp.i('prod')) = 'none' AND sp_space_level(pg_temp.i('priv')) = 'none', 'Dana: edit in General, nothing in Product or the private space');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.i('priv') IN (SELECT sp_visible_space_ids()) AND sp_space_level(pg_temp.i('priv')) = 'edit', 'Priya, a member of the private space, sees it at edit');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(pg_temp.i('priv') IN (SELECT sp_visible_space_ids()) AND sp_space_level(pg_temp.i('priv')) = 'full', 'the Spaces admin sees the private space (D2) at full');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM sp_visible_space_ids()) = 0 AND (SELECT count(*) FROM sp_visible_channel_ids()) = 0, 'a guest sees no space and no channel by default');
SELECT pg_temp.as_member(27);
INSERT INTO space_join_requests (space_id, member_id, message) VALUES (pg_temp.i('prod'), 30, 'Please');
SELECT pg_temp.refused($$INSERT INTO space_join_requests (space_id, member_id) VALUES (pg_temp.i('prod'), 30)$$, 'duplicate key', 'one pending join request per member and space');

-- ---------------------------------------------------------------- pages: the tree, inherited / restricted / private permissions for seven actors (§3)
SELECT pg_temp.as_member(27);
INSERT INTO ids (k, u) VALUES ('root', sp_page_create(pg_temp.i('prod'), NULL, sp_rich_text('Product handbook'), 'page', '📘'));
INSERT INTO ids (k, u) VALUES ('child', sp_page_create(pg_temp.i('prod'), pg_temp.u('root'), sp_rich_text('Pricing')));
INSERT INTO ids (k, u) VALUES ('grand', sp_page_create(pg_temp.i('prod'), pg_temp.u('child'), sp_rich_text('Pricing 2027')));
SELECT pg_temp.check((SELECT parent_page_id FROM pages WHERE id = pg_temp.u('grand')) = pg_temp.u('child') AND (SELECT count(*) FROM blocks WHERE page_id = pg_temp.u('root') AND type = 'child_page') = 1, 'a subpage sits under its parent with a child_page edge block');
SELECT pg_temp.check((SELECT permission_root_id FROM pages WHERE id = pg_temp.u('grand')) IS NULL, 'with no explicit rows the space decides (permission_root NULL)');
SELECT pg_temp.check((SELECT array_agg(plain_title ORDER BY depth DESC) FROM sp_page_ancestors(pg_temp.u('grand'))) = '{"Product handbook",Pricing}', 'the breadcrumb lists the ancestors root first');
INSERT INTO space_members (space_id, member_id, role) VALUES (pg_temp.i('prod'), 26, 'member');
-- seven actors on the unshared tree
SELECT pg_temp.as_member(27); SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'full', 'owner of the space: full on every page');
SELECT pg_temp.as_member(26); SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'edit', 'a member of the space: the space''s member level (edit)');
SELECT pg_temp.as_member(30); SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'none' AND NOT sp_can_see_page(pg_temp.u('root')), 'not in the closed space: none');
SELECT pg_temp.as_member(29); SELECT pg_temp.check(sp_page_level(pg_temp.u('root')) = 'none', 'a guest: none');
SELECT pg_temp.as_member(40); SELECT pg_temp.check(sp_page_level(pg_temp.u('root')) = 'none', 'an agent not in the space: none');
SELECT pg_temp.as_member(1);  SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'full', 'the admin: full');
SELECT pg_temp.as_member(41); SELECT pg_temp.check(sp_page_level(pg_temp.u('root')) = 'none', 'an unadmitted agent: none');
-- share the child to a department (Engineering) at comment, and to the guest at view: additive
SELECT pg_temp.as_member(27);
INSERT INTO page_permissions (page_id, principal_kind, principal_id, level) VALUES (pg_temp.u('child'), 'department', 8, 'comment');
INSERT INTO page_permissions (page_id, principal_kind, principal_id, level) VALUES (pg_temp.u('child'), 'guest', 29, 'view');
SELECT pg_temp.check((SELECT permission_root_id FROM pages WHERE id = pg_temp.u('grand')) = pg_temp.u('child'), 'the grandchild inherits the child''s explicit set (permission_root = child)');
SELECT pg_temp.check((SELECT count(*) FROM page_permissions WHERE page_id = pg_temp.u('child') AND principal_kind = 'everyone_in_space') = 1, 'the first share carried the space''s default along (sharing is additive)');
SELECT pg_temp.as_member(26); SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'edit', 'the space member keeps edit after the share');
SELECT pg_temp.as_member(30); SELECT pg_temp.check(sp_page_level(pg_temp.u('child')) = 'comment' AND sp_page_level(pg_temp.u('grand')) = 'comment' AND sp_page_level(pg_temp.u('root')) = 'none', 'Engineering''s Dana: comment on the shared page and below it, none above');
SELECT pg_temp.as_member(29); SELECT pg_temp.check(sp_page_level(pg_temp.u('child')) = 'view' AND sp_can_see_page(pg_temp.u('grand')) AND NOT sp_can_see_page(pg_temp.u('root')) AND (SELECT count(*) FROM sp_visible_page_ids()) = 2, 'the guest: view on the shared page and its subpage, nothing else (2 visible)');
SELECT pg_temp.check((SELECT count(*) FROM sp_visible_member_ids()) BETWEEN 2 AND 3, 'the guest sees only the people present in what is shared (the sharer, themselves)');
-- restrict the grandchild: only named principals
SELECT pg_temp.as_member(27);
SELECT sp_page_restrict(pg_temp.u('grand'));
SELECT pg_temp.as_member(30); SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'comment' AND sp_page_level(pg_temp.u('child')) = 'comment', 'restricting keeps the named principals (the department) …');
SELECT pg_temp.as_member(26); SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'none' AND sp_page_level(pg_temp.u('child')) = 'edit', '… and drops the space''s default: the space member loses the restricted page, keeps its parent');
SELECT pg_temp.as_member(27); SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'full', 'the owner keeps full on the restricted page');
SELECT sp_page_unrestrict(pg_temp.u('grand'));
SELECT pg_temp.as_member(26); SELECT pg_temp.check(sp_page_level(pg_temp.u('grand')) = 'edit', 'unrestricting brings the space''s default back');
-- a private page: the owner's alone until shared
SELECT pg_temp.as_member(28);
INSERT INTO ids (k, u) VALUES ('private', sp_page_create(NULL, NULL, sp_rich_text('Bea''s notes')));
SELECT pg_temp.check(sp_page_level(pg_temp.u('private')) = 'full' AND (SELECT space_id IS NULL AND owner_member_id = 28 FROM pages WHERE id = pg_temp.u('private')), 'a private page is its owner''s, full');
SELECT pg_temp.as_member(1); SELECT pg_temp.check(sp_page_level(pg_temp.u('private')) = 'none', 'even the admin does not see another''s private page');
SELECT pg_temp.as_member(28);
INSERT INTO page_permissions (page_id, principal_kind, principal_id, level) VALUES (pg_temp.u('private'), 'member', 26, 'edit');
SELECT pg_temp.as_member(26); SELECT pg_temp.check(sp_page_level(pg_temp.u('private')) = 'edit' AND (sp_sidebar()->'shared'->0->>'page_id') = pg_temp.u('private')::text, 'a shared private page reaches its recipient at the given level and shows under Shared');
SELECT pg_temp.as_member(28);
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused($$INSERT INTO pages (space_id, parent_page_id, title) VALUES (NULL, pg_temp.u('private'), '[]')$$, 'same owner', 'a private page''s subpages belong to its owner (another member is refused)');
SELECT pg_temp.as_member(28);
SELECT pg_temp.as_member(29);
SELECT pg_temp.refused($$SELECT sp_page_create(pg_temp.i('general'), NULL, sp_rich_text('Guest page'))$$, 'guest never owns a space page', 'a guest never owns a space page');
SELECT pg_temp.as_member(27);
SELECT pg_temp.refused($$UPDATE pages SET parent_page_id = pg_temp.u('grand') WHERE id = pg_temp.u('root')$$, 'own subpage', 'a page cannot be moved under its own subpage');
SELECT pg_temp.check((SELECT count(*) FROM sp_page_permissions_explained(pg_temp.u('grand'))) >= 3, 'the permission explanation lists the owners and the inherited principals');

-- ---------------------------------------------------------------- blocks: between two, nested, moved, split, merged; a stale save; synced (§6, D6, D7)
SELECT pg_temp.as_member(26);
INSERT INTO ids (k, u) VALUES ('b1', sp_block_insert(pg_temp.u('child'), NULL, NULL, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('First'))));
INSERT INTO ids (k, u) VALUES ('b3', sp_block_insert(pg_temp.u('child'), NULL, pg_temp.u('b1'), 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Third'))));
INSERT INTO ids (k, u) VALUES ('b2', sp_block_insert(pg_temp.u('child'), NULL, pg_temp.u('b1'), 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Second'))));
SELECT pg_temp.check((SELECT array_agg(plain_text ORDER BY position) FROM blocks WHERE page_id = pg_temp.u('child') AND type = 'paragraph') = '{First,Second,Third}', 'a block inserted between two takes its place without renumbering');
INSERT INTO ids (k, u) VALUES ('b0', sp_block_insert(pg_temp.u('child'), NULL, NULL, 'heading_1', jsonb_build_object('rich_text', sp_rich_text('Top')), NULL, true));
SELECT pg_temp.check((SELECT plain_text FROM blocks WHERE page_id = pg_temp.u('child') AND parent_block_id IS NULL ORDER BY position LIMIT 1) = 'Top', 'a block inserted first comes first');
INSERT INTO ids (k, u) VALUES ('todo', sp_block_insert(pg_temp.u('child'), NULL, pg_temp.u('b3'), 'to_do', jsonb_build_object('rich_text', sp_rich_text('Task'), 'checked', false)));
INSERT INTO ids (k, u) VALUES ('nested', sp_block_insert(pg_temp.u('child'), pg_temp.u('todo'), NULL, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Detail'))));
SELECT pg_temp.check((SELECT has_children FROM blocks WHERE id = pg_temp.u('todo')), 'nesting a block under a to_do marks has_children');
SELECT sp_block_move(pg_temp.u('nested'), NULL, pg_temp.u('b1'));
SELECT pg_temp.check((SELECT parent_block_id IS NULL FROM blocks WHERE id = pg_temp.u('nested')) AND NOT (SELECT has_children FROM blocks WHERE id = pg_temp.u('todo')), 'moving a block out re-parents it and clears has_children');
SELECT pg_temp.check((SELECT array_agg(plain_text ORDER BY position) FROM blocks WHERE page_id = pg_temp.u('child') AND parent_block_id IS NULL AND type <> 'child_page') = '{Top,First,Detail,Second,Third,Task}',
    'a moved block lands after the named sibling (order: ' || (SELECT array_to_string(array_agg(plain_text ORDER BY position), ',') FROM blocks WHERE page_id = pg_temp.u('child') AND parent_block_id IS NULL AND type <> 'child_page') || ')');
-- split: Enter in the middle of "Second" = update + insert after; merge: Backspace = delete
UPDATE blocks SET content = jsonb_build_object('rich_text', sp_rich_text('Sec')), version = 1 WHERE id = pg_temp.u('b2');
INSERT INTO ids (k, u) VALUES ('b2b', sp_block_insert(pg_temp.u('child'), NULL, pg_temp.u('b2'), 'paragraph', jsonb_build_object('rich_text', sp_rich_text('ond'))));
SELECT pg_temp.check((SELECT array_agg(plain_text ORDER BY position) FROM blocks WHERE page_id = pg_temp.u('child') AND parent_block_id IS NULL AND type <> 'child_page') = '{Top,First,Detail,Sec,ond,Third,Task}',
    'a split is an update and an insert after (order: ' || (SELECT array_to_string(array_agg(plain_text ORDER BY position), ',') FROM blocks WHERE page_id = pg_temp.u('child') AND parent_block_id IS NULL AND type <> 'child_page') || ')');
DELETE FROM blocks WHERE id = pg_temp.u('b2b');
UPDATE blocks SET content = jsonb_build_object('rich_text', sp_rich_text('Second')), version = 2 WHERE id = pg_temp.u('b2');
SELECT pg_temp.check((SELECT version FROM blocks WHERE id = pg_temp.u('b2')) = 3, 'every content save bumps the version');
SELECT pg_temp.refused($$UPDATE blocks SET content = jsonb_build_object('rich_text', sp_rich_text('Stale')), version = 1 WHERE id = pg_temp.u('b2')$$, 'stale', 'a save carrying an old version is refused as stale (D7)');
SELECT pg_temp.refused($$INSERT INTO blocks (page_id, parent_block_id, type, content) VALUES (pg_temp.u('child'), pg_temp.u('b1'), 'table_row', '{"cells":[]}')$$, 'table row lives under a table', 'a table row only under a table');
SELECT pg_temp.refused($$INSERT INTO blocks (page_id, type, content) VALUES (pg_temp.u('child'), 'column', '{}')$$, 'column lives under a column list', 'a column only under a column list');
SELECT pg_temp.refused($$INSERT INTO blocks (page_id, parent_block_id, type, content) VALUES (pg_temp.u('child'), pg_temp.u('b0'), 'paragraph', '{}')$$, 'toggleable heading', 'only a toggleable heading holds children');
SELECT pg_temp.refused($$INSERT INTO blocks (page_id, type, content) VALUES (pg_temp.u('child'), 'nonsense', '{}')$$, 'violates check', 'an unknown block type is refused');
SELECT pg_temp.refused($$INSERT INTO blocks (page_id, type, content) VALUES (pg_temp.u('child'), 'code', '{"rich_text":[]}')$$, 'violates check', 'a code block names its language');
SELECT pg_temp.check((SELECT content_rev FROM pages WHERE id = pg_temp.u('child')) >= 8, 'every block write advances the page''s content_rev');
-- lock
SELECT sp_page_set_locked(pg_temp.u('child'), true);
SELECT pg_temp.refused($$UPDATE blocks SET content = jsonb_build_object('rich_text', sp_rich_text('x')), version = 3 WHERE id = pg_temp.u('b2')$$, 'locked', 'a locked page refuses block writes');
SELECT pg_temp.refused($$UPDATE pages SET title = sp_rich_text('New') WHERE id = pg_temp.u('child')$$, 'locked', 'a locked page refuses a title change');
SELECT sp_page_set_locked(pg_temp.u('child'), false);
-- synced: an original on the child page, a copy on the root page; the guest reads the child (view) but not the root
INSERT INTO ids (k, u) VALUES ('sync', sp_block_insert(pg_temp.u('child'), NULL, pg_temp.u('todo'), 'synced_block', '{}'));
INSERT INTO ids (k, u) VALUES ('synckid', sp_block_insert(pg_temp.u('child'), pg_temp.u('sync'), NULL, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Synced words'))));
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO blocks (id, page_id, type, position, content, synced_from) VALUES (gen_random_uuid(), pg_temp.u('root'), 'synced_block', 'zz', '{}', pg_temp.u('sync')) RETURNING id) INSERT INTO ids (k, u) SELECT 'copy', id FROM ins;
SELECT pg_temp.check((SELECT content->'synced_from'->>'block_id' FROM blocks WHERE id = pg_temp.u('copy')) = pg_temp.u('sync')::text, 'a synced copy records its original in content.synced_from');
SELECT pg_temp.check((SELECT x->'children'->0->'content'->'rich_text'->0->>'plain_text' FROM jsonb_array_elements(sp_page_tree(pg_temp.u('root'))) x WHERE x->>'type' = 'synced_block') = 'Synced words', 'a reader who may see the original reads it through the copy');
SELECT pg_temp.refused($$INSERT INTO blocks (page_id, type, content, synced_from) VALUES (pg_temp.u('root'), 'synced_block', '{}', pg_temp.u('copy'))$$, 'original synced block', 'a copy of a copy is refused');
-- the child-page edge: trashing the child removes its edge in the root; restoring brings it back under the root
SELECT sp_page_trash(pg_temp.u('child'));
SELECT pg_temp.check((SELECT archived_at IS NOT NULL AND archived_via = pg_temp.u('child') FROM pages WHERE id = pg_temp.u('grand')) AND (SELECT count(*) FROM blocks WHERE page_id = pg_temp.u('root') AND type = 'child_page') = 0, 'trashing a page takes its subtree and removes its edge block');
SELECT pg_temp.check((SELECT count(*) FROM mcp_trash) >= 1 AND (SELECT count(*) FROM mcp_trash WHERE page_id = pg_temp.u('grand')) = 0, 'the trash lists the page, not what went with it');
SELECT pg_temp.refused($$INSERT INTO blocks (page_id, type, content) VALUES (pg_temp.u('child'), 'paragraph', '{}')$$, 'in the trash', 'a trashed page refuses block writes');
SELECT sp_page_restore(pg_temp.u('child'));
SELECT pg_temp.check((SELECT archived_at IS NULL FROM pages WHERE id = pg_temp.u('grand')) AND (SELECT parent_page_id FROM pages WHERE id = pg_temp.u('child')) = pg_temp.u('root'), 'restoring brings the subtree back under its parent');
-- move the child to the root of General, then back under root
SELECT sp_page_move(pg_temp.u('child'), NULL, pg_temp.i('general'));
SELECT pg_temp.check((SELECT space_id = pg_temp.i('general') AND parent_page_id IS NULL FROM pages WHERE id = pg_temp.u('child')) AND (SELECT space_id FROM pages WHERE id = pg_temp.u('grand')) = pg_temp.i('general'), 'moving a page to another space moves its subtree');
SELECT sp_page_move(pg_temp.u('child'), pg_temp.u('root'));
SELECT pg_temp.check((SELECT space_id = pg_temp.i('prod') AND parent_page_id = pg_temp.u('root') FROM pages WHERE id = pg_temp.u('child')) AND (SELECT count(*) FROM blocks WHERE page_id = pg_temp.u('root') AND type = 'child_page') = 1, 'moving it back makes a new edge block');
-- duplicate
INSERT INTO ids (k, u) VALUES ('dup', sp_page_duplicate(pg_temp.u('child'), pg_temp.i('prod'), NULL, sp_rich_text('Pricing (copy)')));
SELECT pg_temp.check((SELECT count(*) FROM blocks WHERE page_id = pg_temp.u('dup')) = (SELECT count(*) FROM blocks WHERE page_id = pg_temp.u('child')) AND (SELECT count(*) FROM pages WHERE parent_page_id = pg_temp.u('dup')) = 1, 'duplicating copies every block and the subpage with new ids');

-- ---------------------------------------------------------------- Markdown: every adopted block type rendered (§6, D6)
SELECT pg_temp.as_member(27);
INSERT INTO ids (k, u) VALUES ('md', sp_page_create(pg_temp.i('prod'), NULL, sp_rich_text('Every block'), 'page', '🧪'));
DO $$
DECLARE p uuid := pg_temp.u('md'); last uuid := NULL; t text; c jsonb; tb uuid; cl uuid; col uuid; tg uuid;
BEGIN
    FOR t, c IN SELECT * FROM (VALUES
        ('paragraph', jsonb_build_object('rich_text', jsonb_build_array(
            jsonb_build_object('type','text','text',jsonb_build_object('content','Plain ','link',NULL),'annotations',jsonb_build_object('bold',false,'italic',false,'strikethrough',false,'underline',false,'code',false,'color','default'),'plain_text','Plain '),
            jsonb_build_object('type','text','text',jsonb_build_object('content','bold','link',NULL),'annotations',jsonb_build_object('bold',true,'italic',false,'strikethrough',false,'underline',false,'code',false,'color','default'),'plain_text','bold'),
            jsonb_build_object('type','text','text',jsonb_build_object('content',' italic','link',NULL),'annotations',jsonb_build_object('bold',false,'italic',true,'strikethrough',false,'underline',false,'code',false,'color','default'),'plain_text',' italic'),
            jsonb_build_object('type','text','text',jsonb_build_object('content',' code','link',NULL),'annotations',jsonb_build_object('bold',false,'italic',false,'strikethrough',false,'underline',false,'code',true,'color','default'),'plain_text',' code'),
            jsonb_build_object('type','text','text',jsonb_build_object('content',' struck','link',NULL),'annotations',jsonb_build_object('bold',false,'italic',false,'strikethrough',true,'underline',false,'code',false,'color','default'),'plain_text',' struck'),
            jsonb_build_object('type','text','text',jsonb_build_object('content',' a link','link',jsonb_build_object('url','https://example.invalid/x')),'annotations',jsonb_build_object('bold',false,'italic',false,'strikethrough',false,'underline',false,'code',false,'color','default'),'plain_text',' a link'),
            jsonb_build_object('type','mention','mention',jsonb_build_object('type','member','id','26'),'plain_text','@Priya'),
            jsonb_build_object('type','equation','equation',jsonb_build_object('expression','E=mc^2'),'plain_text','E=mc^2')))),
        ('heading_1', jsonb_build_object('rich_text', sp_rich_text('H1'))),
        ('heading_2', jsonb_build_object('rich_text', sp_rich_text('H2'))),
        ('heading_3', jsonb_build_object('rich_text', sp_rich_text('H3'))),
        ('bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('Bullet'))),
        ('numbered_list_item', jsonb_build_object('rich_text', sp_rich_text('Number one'))),
        ('numbered_list_item', jsonb_build_object('rich_text', sp_rich_text('Number two'))),
        ('to_do', jsonb_build_object('rich_text', sp_rich_text('Done thing'), 'checked', true)),
        ('to_do', jsonb_build_object('rich_text', sp_rich_text('Open thing'), 'checked', false)),
        ('quote', jsonb_build_object('rich_text', sp_rich_text('Quoted'))),
        ('callout', jsonb_build_object('rich_text', sp_rich_text('Careful'), 'icon', jsonb_build_object('emoji', '⚠️'), 'color', 'yellow_background')),
        ('code', jsonb_build_object('rich_text', sp_rich_text('print(1)'), 'language', 'python')),
        ('divider', '{}'::jsonb),
        ('image', jsonb_build_object('url', 'https://example.invalid/i.png', 'caption', sp_rich_text('Pic'))),
        ('video', jsonb_build_object('url', 'https://example.invalid/v.mp4', 'name', 'clip')),
        ('audio', jsonb_build_object('url', 'https://example.invalid/a.mp3', 'name', 'song')),
        ('file', jsonb_build_object('url', 'https://example.invalid/f.zip', 'name', 'bundle')),
        ('pdf', jsonb_build_object('url', 'https://example.invalid/d.pdf', 'name', 'deck')),
        ('bookmark', jsonb_build_object('url', 'https://example.invalid/b')),
        ('embed', jsonb_build_object('url', 'https://www.youtube.com/watch?v=x')),
        ('link_preview', jsonb_build_object('url', 'https://example.invalid/lp')),
        ('equation', jsonb_build_object('expression', 'a^2+b^2=c^2')),
        ('table_of_contents', '{}'::jsonb),
        ('breadcrumb', '{}'::jsonb),
        ('template', jsonb_build_object('rich_text', sp_rich_text('Add a row'))),
        ('link_to_page', jsonb_build_object('page_id', pg_temp.u('root'))),
        ('unsupported', jsonb_build_object('original_type', 'tab'))
    ) v(t, c) LOOP
        last := sp_block_insert(p, NULL, last, t, c);
    END LOOP;
    -- a toggle with a child, a table with two rows, a column list with two columns
    tg := sp_block_insert(p, NULL, last, 'toggle', jsonb_build_object('rich_text', sp_rich_text('Toggle me')));
    PERFORM sp_block_insert(p, tg, NULL, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Inside the toggle')));
    tb := sp_block_insert(p, NULL, tg, 'table', jsonb_build_object('table_width', 2, 'has_column_header', true));
    PERFORM sp_block_insert(p, tb, NULL, 'table_row', jsonb_build_object('cells', jsonb_build_array(sp_rich_text('A'), sp_rich_text('B'))));
    PERFORM sp_block_insert(p, tb, NULL, 'table_row', jsonb_build_object('cells', jsonb_build_array(sp_rich_text('1'), sp_rich_text('2'))));
    cl := sp_block_insert(p, NULL, tb, 'column_list', '{}'::jsonb);
    col := sp_block_insert(p, cl, NULL, 'column', '{}'::jsonb);
    PERFORM sp_block_insert(p, col, NULL, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Left column')));
    col := sp_block_insert(p, cl, NULL, 'column', '{}'::jsonb);
    PERFORM sp_block_insert(p, col, NULL, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Right column')));
    PERFORM sp_page_create(pg_temp.i('prod'), p, sp_rich_text('A subpage'));
    PERFORM sp_database_create(pg_temp.i('prod'), p, sp_rich_text('A child database'));
END$$;
INSERT INTO ids (k, u) SELECT 'mdtext', NULL;
CREATE TEMP TABLE t_md AS SELECT sp_page_markdown(pg_temp.u('md')) AS md;
SELECT pg_temp.check((SELECT md LIKE '# 🧪 Every block%' FROM t_md), 'Markdown: the title as H1 with its icon');
SELECT pg_temp.check((SELECT md LIKE '%Plain **bold** *italic* `code` ~~struck~~ [a link](https://example.invalid/x)@SMOKE Priya$E=mc^2$%' FROM t_md), 'Markdown: the rich-text runs — bold, italic, code, strikethrough, link, mention, equation (got: ' || (SELECT split_part(md, E'\n', 3) FROM t_md) || ')');
SELECT pg_temp.check((SELECT md LIKE E'%\n# H1\n%' AND md LIKE E'%\n## H2\n%' AND md LIKE E'%\n### H3\n%' FROM t_md), 'Markdown: the three headings');
SELECT pg_temp.check((SELECT md LIKE E'%- Bullet\n%' AND md LIKE E'%1. Number one\n2. Number two\n%' FROM t_md), 'Markdown: bulleted and numbered lists, numbered in sequence');
SELECT pg_temp.check((SELECT md LIKE E'%- [x] Done thing\n- [ ] Open thing\n%' FROM t_md), 'Markdown: to-dos as task items');
SELECT pg_temp.check((SELECT md LIKE E'%> Quoted\n%' AND md LIKE E'%> [!NOTE] ⚠️ Careful\n%' FROM t_md), 'Markdown: the quote and the callout');
SELECT pg_temp.check((SELECT md LIKE E'%```python\nprint(1)\n```%' AND md LIKE E'%\n---\n%' FROM t_md), 'Markdown: the code fence with its language and the divider');
SELECT pg_temp.check((SELECT md LIKE '%![Pic](https://example.invalid/i.png)%' AND md LIKE '%[clip](https://example.invalid/v.mp4)%' AND md LIKE '%[song](https://example.invalid/a.mp3)%' AND md LIKE '%[bundle](https://example.invalid/f.zip)%' AND md LIKE '%[deck](https://example.invalid/d.pdf)%' FROM t_md), 'Markdown: image, video, audio, file and pdf as links');
SELECT pg_temp.check((SELECT md LIKE '%<https://example.invalid/b>%' AND md LIKE '%<https://www.youtube.com/watch?v=x>%' AND md LIKE '%<https://example.invalid/lp>%' FROM t_md), 'Markdown: bookmark, embed and link preview as URLs');
SELECT pg_temp.check((SELECT md LIKE E'%$$\na^2+b^2=c^2\n$$%' FROM t_md), 'Markdown: a block equation');
SELECT pg_temp.check((SELECT md NOT LIKE '%table_of_contents%' AND md NOT LIKE '%breadcrumb%' AND md NOT LIKE '%Add a row%' FROM t_md), 'Markdown: table of contents, breadcrumb and template blocks render nothing');
SELECT pg_temp.check((SELECT md LIKE '%[[Product handbook]]%' AND md LIKE '%<!-- unsupported block tab -->%' FROM t_md), 'Markdown: a link to a page by its title; an unsupported block as a comment that keeps its type');
SELECT pg_temp.check((SELECT md LIKE E'%<details><summary>Toggle me</summary>\n\n    Inside the toggle\n%' FROM t_md), 'Markdown: a toggle as details with its children');
SELECT pg_temp.check((SELECT md LIKE E'%| A | B |\n| --- | --- |\n| 1 | 2 |\n%' FROM t_md), 'Markdown: a table with its header row');
SELECT pg_temp.check((SELECT md LIKE E'%Left column\n\nRight column\n%' FROM t_md), 'Markdown: columns flattened in reading order');
SELECT pg_temp.check((SELECT md LIKE '%- [[A subpage]]%' AND md LIKE '%- [[A child database]] (database)%' FROM t_md), 'Markdown: child pages and databases as links');
SELECT pg_temp.check((SELECT count(*) FROM page_links WHERE from_page_id = pg_temp.u('md') AND kind = 'link_to_page' AND to_page_id = pg_temp.u('root')) = 1
    AND (SELECT count(*) FROM page_links WHERE from_page_id = pg_temp.u('md') AND kind IN ('child_page', 'child_database')) = 2, 'page_links records the link_to_page and the child edges');
SELECT pg_temp.check((SELECT count(*) FROM sp_backlinks(pg_temp.u('root'))) >= 1, 'backlinks finds the page that links here');

-- ---------------------------------------------------------------- databases: every property type, a relation and its rollup, a view's filter/sort/group (§6, D8)
SELECT pg_temp.as_member(27);
INSERT INTO ids (k, u) VALUES ('tasks', sp_database_create(pg_temp.i('prod'), pg_temp.u('root'), sp_rich_text('Tasks'), jsonb_build_object(
    'title', '{"id":"title","name":"Task","type":"title"}'::jsonb,
    'Notes', '{"id":"notes","name":"Notes","type":"rich_text"}'::jsonb,
    'Points', '{"id":"points","name":"Points","type":"number"}'::jsonb,
    'Kind', '{"id":"kind","name":"Kind","type":"select","select":{"options":[{"name":"Bug"},{"name":"Feature"}]}}'::jsonb,
    'Tags', '{"id":"tags","name":"Tags","type":"multi_select","multi_select":{"options":[{"name":"ui"},{"name":"db"}]}}'::jsonb,
    'Status', '{"id":"status","name":"Status","type":"status","status":{"options":[{"name":"Todo"},{"name":"Done"}]}}'::jsonb,
    'Due', '{"id":"due","name":"Due","type":"date"}'::jsonb,
    'Owner', '{"id":"owner","name":"Owner","type":"people"}'::jsonb,
    'Files', '{"id":"files","name":"Files","type":"files"}'::jsonb,
    'Urgent', '{"id":"urgent","name":"Urgent","type":"checkbox"}'::jsonb,
    'Link', '{"id":"link","name":"Link","type":"url"}'::jsonb,
    'Mail', '{"id":"mail","name":"Mail","type":"email"}'::jsonb,
    'Phone', '{"id":"phone","name":"Phone","type":"phone_number"}'::jsonb,
    'Created', '{"id":"created","name":"Created","type":"created_time"}'::jsonb,
    'Creator', '{"id":"creator","name":"Creator","type":"created_by"}'::jsonb,
    'Edited', '{"id":"edited","name":"Edited","type":"last_edited_time"}'::jsonb,
    'Editor', '{"id":"editor","name":"Editor","type":"last_edited_by"}'::jsonb,
    'Ref', '{"id":"ref","name":"Ref","type":"unique_id","unique_id":{"prefix":"TSK"}}'::jsonb)));
SELECT pg_temp.check((SELECT count(*) FROM jsonb_object_keys((SELECT properties FROM databases WHERE id = pg_temp.u('tasks')))) = 18, 'a database with eighteen property types (every kept type but relation, rollup and verification)');
SELECT pg_temp.refused($$SELECT sp_database_property_save(pg_temp.u('tasks'), 'Calc', '{"type":"formula"}')$$, 'Extended', 'a formula property is refused (Extended)');
SELECT pg_temp.refused($$UPDATE databases SET properties = properties - 'title' WHERE id = pg_temp.u('tasks')$$, 'exactly one title', 'a database keeps exactly one title');
INSERT INTO ids (k, u) VALUES ('r1', sp_row_create(pg_temp.u('tasks'), jsonb_build_object('title', sp_rich_text('Fix the login'), 'Points', 3, 'Kind', 'Bug', 'Tags', '["ui"]'::jsonb, 'Status', 'Done', 'Due', '{"start":"2026-10-01"}'::jsonb, 'Owner', '["26"]'::jsonb, 'Urgent', true, 'Link', 'https://example.invalid')));
INSERT INTO ids (k, u) VALUES ('r2', sp_row_create(pg_temp.u('tasks'), jsonb_build_object('title', sp_rich_text('Build the board'), 'Points', 8, 'Kind', 'Feature', 'Tags', '["ui","db"]'::jsonb, 'Status', 'Todo', 'Due', '{"start":"2026-10-20"}'::jsonb, 'Owner', '["27"]'::jsonb, 'Urgent', false)));
INSERT INTO ids (k, u) VALUES ('r3', sp_row_create(pg_temp.u('tasks'), jsonb_build_object('title', sp_rich_text('Ship it'), 'Points', 5, 'Kind', 'Feature', 'Status', 'Todo', 'Urgent', false)));
SELECT pg_temp.check((SELECT properties->'Ref'->>'number' FROM pages WHERE id = pg_temp.u('r3')) = '3' AND (SELECT properties->'Ref'->>'prefix' FROM pages WHERE id = pg_temp.u('r1')) = 'TSK', 'unique ids number the rows in order with their prefix');
SELECT pg_temp.check((SELECT parent_page_id = pg_temp.u('tasks') AND plain_title = 'Fix the login' FROM pages WHERE id = pg_temp.u('r1')), 'a row is a page under its database with the title property as its title');
SELECT pg_temp.refused($$SELECT sp_row_create(pg_temp.u('tasks'), jsonb_build_object('title', sp_rich_text('x'), 'Points', 'three'))$$, 'takes a number', 'a number property refuses text');
SELECT pg_temp.refused($$SELECT sp_row_create(pg_temp.u('tasks'), jsonb_build_object('title', sp_rich_text('x'), 'Urgent', 'yes'))$$, 'true or false', 'a checkbox refuses text');
SELECT pg_temp.check((SELECT array_agg(title ORDER BY title) FROM sp_database_rows(pg_temp.u('tasks'), NULL, '{"property":"Status","status":{"equals":"Todo"}}')) = '{"Build the board","Ship it"}', 'filter: status equals');
SELECT pg_temp.check((SELECT array_agg(title) FROM sp_database_rows(pg_temp.u('tasks'), NULL, '{"and":[{"property":"Points","number":{"greater_than":4}},{"property":"Due","date":{"is_not_empty":true}}]}')) = '{"Build the board"}', 'filter: and of a number and a date');
SELECT pg_temp.check((SELECT array_agg(title ORDER BY title) FROM sp_database_rows(pg_temp.u('tasks'), NULL, '{"or":[{"property":"Urgent","checkbox":{"equals":true}},{"property":"Tags","multi_select":{"contains":"db"}}]}')) = '{"Build the board","Fix the login"}', 'filter: or of a checkbox and a multi-select contains');
SELECT pg_temp.check((SELECT array_agg(title) FROM sp_database_rows(pg_temp.u('tasks'), NULL, '{"property":"Owner","people":{"contains":"26"}}')) = '{"Fix the login"}', 'filter: people contains');
SELECT pg_temp.check((SELECT array_agg(title) FROM sp_database_rows(pg_temp.u('tasks'), NULL, '{"property":"Task","title":{"starts_with":"ship"}}')) = '{"Ship it"}', 'filter: by the property''s display name, case-insensitive');
SELECT pg_temp.check((SELECT array_agg(title) FROM sp_database_rows(pg_temp.u('tasks'), NULL, '{"property":"Due","date":{"is_empty":true}}')) = '{"Ship it"}', 'filter: date is empty');
SELECT pg_temp.check((SELECT array_agg(title) FROM sp_database_rows(pg_temp.u('tasks'), NULL, NULL, '[{"property":"Points","direction":"descending"}]')) = '{"Build the board","Ship it","Fix the login"}', 'sort: a number descending');
SELECT pg_temp.check((SELECT array_agg(title) FROM sp_database_rows(pg_temp.u('tasks'), NULL, NULL, '[{"property":"Kind","direction":"ascending"},{"property":"Points","direction":"ascending"}]')) = '{"Fix the login","Ship it","Build the board"}', 'sort: two keys');
WITH ins AS (INSERT INTO database_views (database_id, name, layout, group_by, filter, sort) VALUES (pg_temp.u('tasks'), 'Board', 'board', 'Status', '{"property":"Kind","select":{"equals":"Feature"}}', '[{"property":"Points","direction":"descending"}]') RETURNING id) INSERT INTO ids (k, u) SELECT 'board', id FROM ins;
SELECT pg_temp.check((SELECT array_agg(title || ':' || group_value) FROM sp_database_rows(pg_temp.u('tasks'), pg_temp.u('board'))) = '{"Build the board:Todo","Ship it:Todo"}', 'a view applies its filter, sort and group');
SELECT pg_temp.refused($$INSERT INTO database_views (database_id, name, layout) VALUES (pg_temp.u('tasks'), 'Cal', 'calendar')$$, 'violates check', 'a calendar view names its date property');
SELECT pg_temp.check((SELECT r.properties->'Creator'->>'name' = 'SMOKE Marco' AND (r.properties->'Created') IS NOT NULL AND r.properties->'Owner'->0->>'name' = 'SMOKE Priya' FROM sp_database_rows(pg_temp.u('tasks'), NULL, '{"property":"Task","title":{"equals":"Fix the login"}}') r), 'created_by, created_time and people are resolved to names');
-- relation + rollup
INSERT INTO ids (k, u) VALUES ('epics', sp_database_create(pg_temp.i('prod'), pg_temp.u('root'), sp_rich_text('Epics')));
SELECT sp_database_property_save(pg_temp.u('epics'), 'Tasks', jsonb_build_object('type', 'relation', 'relation', jsonb_build_object('database_id', pg_temp.u('tasks'), 'two_way', true)));
SELECT sp_database_property_save(pg_temp.u('epics'), 'Points', '{"type":"rollup","rollup":{"relation":"Tasks","property":"Points","function":"sum"}}');
SELECT sp_database_property_save(pg_temp.u('epics'), 'Done', '{"type":"rollup","rollup":{"relation":"Tasks","property":"Urgent","function":"percent_checked"}}');
SELECT sp_database_property_save(pg_temp.u('epics'), 'Count', '{"type":"rollup","rollup":{"relation":"Tasks","property":"Task","function":"count"}}');
SELECT sp_database_property_save(pg_temp.u('epics'), 'Latest', '{"type":"rollup","rollup":{"relation":"Tasks","property":"Due","function":"latest_date"}}');
SELECT pg_temp.check((SELECT properties->'Related to Tasks'->>'type' FROM databases WHERE id = pg_temp.u('tasks')) = 'relation', 'a two-way relation writes its dual property into the other database');
SELECT pg_temp.refused($$SELECT sp_database_property_save(pg_temp.u('epics'), 'Bad', '{"type":"rollup","rollup":{"relation":"Points","property":"Task","function":"count"}}')$$, 'through a relation', 'a rollup must go through a relation');
SELECT pg_temp.refused($$SELECT sp_database_property_save(pg_temp.u('epics'), 'Bad', '{"type":"rollup","rollup":{"relation":"Tasks","property":"Task","function":"median"}}')$$, 'function', 'a rollup names a function we have');
INSERT INTO ids (k, u) VALUES ('e1', sp_row_create(pg_temp.u('epics'), jsonb_build_object('title', sp_rich_text('Launch'))));
SELECT sp_row_relation_set(pg_temp.u('e1'), 'Tasks', ARRAY[pg_temp.u('r1'), pg_temp.u('r2')]);
SELECT pg_temp.check((SELECT count(*) FROM row_relations) = 4, 'a dual relation is one link read from both sides (2 links → 4 rows)');
SELECT pg_temp.check((SELECT r.properties->'Points'->>'display' = '11' AND r.properties->'Done'->>'display' = '50.0%' AND r.properties->'Count'->>'display' = '2' AND r.properties->'Latest'->>'display' = '2026-10-20' FROM sp_database_rows(pg_temp.u('epics')) r), 'rollups: sum, percent checked, count, latest date');
SELECT pg_temp.check((SELECT jsonb_array_length(r.properties->'Related to Tasks') = 1 FROM sp_database_rows(pg_temp.u('tasks'), NULL, '{"property":"Task","title":{"equals":"Fix the login"}}') r), 'the dual side shows the epic on the task');
SELECT sp_row_relation_set(pg_temp.u('e1'), 'Tasks', ARRAY[pg_temp.u('r1')]);
SELECT pg_temp.check((SELECT count(*) FROM row_relations) = 2 AND (SELECT count(*) FROM page_links WHERE kind = 'relation') = 2, 'removing a target removes both sides and the backlinks');
SELECT sp_database_property_remove(pg_temp.u('epics'), 'Points');
SELECT pg_temp.check(NOT ((SELECT properties FROM databases WHERE id = pg_temp.u('epics')) ? 'Points'), 'a property removed from the schema');
SELECT pg_temp.refused($$SELECT sp_database_property_remove(pg_temp.u('epics'), 'Tasks')$$, 'rollup goes through', 'a relation a rollup depends on cannot be removed first');
SELECT pg_temp.check((SELECT sp_page_markdown(pg_temp.u('e1')) LIKE '%- **Tasks:** [[Fix the login]]%'), 'a row renders its properties as Markdown');

-- ---------------------------------------------------------------- channels: public, private, DM, group DM; a thread; reactions, pins, unread, a tombstone (§6, D9)
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO channels (space_id, kind, name, topic) VALUES (pg_temp.i('prod'), 'public', 'launch', 'The launch') RETURNING id) INSERT INTO ids (k, i) SELECT 'launch', id FROM ins;
WITH ins AS (INSERT INTO channels (space_id, kind, name) VALUES (pg_temp.i('prod'), 'private', 'leads-only') RETURNING id) INSERT INTO ids (k, i) SELECT 'leads', id FROM ins;
INSERT INTO channel_members (channel_id, member_id) VALUES (pg_temp.i('leads'), 27), (pg_temp.i('leads'), 26);
SELECT pg_temp.refused($$INSERT INTO channels (space_id, kind, name) VALUES (pg_temp.i('prod'), 'public', 'Launch')$$, 'violates check', 'a channel name is lowercase, like Slack''s');
SELECT pg_temp.refused($$INSERT INTO channels (space_id, kind, name) VALUES (pg_temp.i('prod'), 'public', 'launch')$$, 'duplicate key', 'a channel name is unique in its space');
SELECT pg_temp.refused($$INSERT INTO channels (kind, name) VALUES ('dm', 'x')$$, 'violates check', 'a DM has no name and no space');
SELECT pg_temp.as_member(26); SELECT pg_temp.check(sp_in_channel(pg_temp.i('launch')) AND sp_can_post(pg_temp.i('launch')) AND sp_in_channel(pg_temp.i('leads')), 'a space member is in its public channels (no join needed) and in the private one she was added to');
SELECT pg_temp.as_member(30); SELECT pg_temp.check(NOT sp_in_channel(pg_temp.i('launch')) AND pg_temp.i('launch') NOT IN (SELECT sp_visible_channel_ids()) AND sp_in_channel(pg_temp.i('gch')), 'not in the closed space: its channels are closed; General''s #general is open');
SELECT pg_temp.as_member(28); SELECT pg_temp.check(pg_temp.i('leads') NOT IN (SELECT sp_visible_channel_ids()), 'a private channel is invisible to a non-member');
SELECT pg_temp.as_member(1);  SELECT pg_temp.check(pg_temp.i('leads') IN (SELECT sp_visible_channel_ids()), 'the admin reads every space channel, the private ones included');
SELECT pg_temp.as_member(29); SELECT pg_temp.refused($$INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('gch'), sp_rich_text('hi'))$$, 'member of the channel', 'a guest cannot post in a channel they are not in');
-- messages, a thread, also-to-channel
SELECT pg_temp.as_member(26);
WITH ins AS (INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('launch'), sp_rich_text('Are we launching Monday?')) RETURNING id) INSERT INTO ids (k, i) SELECT 'm1', id FROM ins;
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO messages (channel_id, thread_root_id, body) VALUES (pg_temp.i('launch'), pg_temp.i('m1'), sp_rich_text('Yes — we decided: Monday 9am')) RETURNING id) INSERT INTO ids (k, i) SELECT 'm2', id FROM ins;
WITH ins AS (INSERT INTO messages (channel_id, thread_root_id, body, also_to_channel) VALUES (pg_temp.i('launch'), pg_temp.i('m1'), sp_rich_text('Also sending to the channel'), true) RETURNING id) INSERT INTO ids (k, i) SELECT 'm3', id FROM ins;
SELECT pg_temp.check((SELECT reply_count = 2 AND last_reply_at IS NOT NULL FROM messages WHERE id = pg_temp.i('m1')) AND (SELECT message_count = 3 FROM channels WHERE id = pg_temp.i('launch')), 'a thread counts its replies; the channel counts every message');
SELECT pg_temp.check((SELECT count(*) FROM sp_channel_history(pg_temp.i('launch'))) = 2 AND (SELECT count(*) FROM sp_thread(pg_temp.i('m1'))) = 3, 'the channel shows top-level messages and also-to-channel replies; the thread shows all three');
SELECT pg_temp.refused($$INSERT INTO messages (channel_id, thread_root_id, body) VALUES (pg_temp.i('launch'), pg_temp.i('m2'), sp_rich_text('deeper'))$$, 'one level deep', 'a thread is one level deep');
SELECT pg_temp.refused($$INSERT INTO messages (channel_id, thread_root_id, body) VALUES (pg_temp.i('gch'), pg_temp.i('m1'), sp_rich_text('elsewhere'))$$, 'thread''s channel', 'a reply goes in its thread''s channel');
SELECT pg_temp.refused($$UPDATE messages SET body = sp_rich_text('hijacked') WHERE id = pg_temp.i('m1')$$, 'Only the author', 'only the author edits a message');
SELECT pg_temp.as_member(26);
UPDATE messages SET body = sp_rich_text('Are we launching on Monday?') WHERE id = pg_temp.i('m1');
SELECT pg_temp.check((SELECT edited_at IS NOT NULL FROM messages WHERE id = pg_temp.i('m1')), 'an edit is marked edited');
SELECT pg_temp.check((SELECT sp_thread_context(pg_temp.i('m1'))) LIKE 'SMOKE Priya: Are we launching on Monday?%SMOKE Marco: Yes%', 'the thread context names each speaker in order');
-- reactions, pins, saved, bookmarks
INSERT INTO message_reactions (message_id, member_id, emoji) VALUES (pg_temp.i('m2'), 26, '👍');
INSERT INTO saved_messages (member_id, message_id) VALUES (26, pg_temp.i('m2'));
INSERT INTO channel_pins (channel_id, message_id) VALUES (pg_temp.i('launch'), pg_temp.i('m1'));
INSERT INTO channel_pins (channel_id, page_id) VALUES (pg_temp.i('launch'), pg_temp.u('root'));
INSERT INTO channel_bookmarks (channel_id, title, url) VALUES (pg_temp.i('launch'), 'Status page', 'https://status.example.invalid');
SELECT pg_temp.check((SELECT x->'reactions'->0->>'emoji' = '👍' AND (x->'reactions'->0->>'mine')::boolean AND (x->>'is_saved')::boolean FROM sp_thread(pg_temp.i('m1')) x WHERE (x->>'message_id')::bigint = pg_temp.i('m2')), 'a message row carries its reactions and whether I saved it');
SELECT pg_temp.refused($$INSERT INTO message_reactions (message_id, member_id, emoji) VALUES (pg_temp.i('m1'), 30, '👍')$$, 'member of the channel', 'only a member of the channel reacts');
SELECT pg_temp.refused($$INSERT INTO channel_pins (channel_id, message_id) VALUES (pg_temp.i('gch'), pg_temp.i('m1'))$$, 'belongs to the channel', 'a pinned message belongs to the channel');
-- unread
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT unread_count = 1 AND first_unread_id = pg_temp.i('m1') FROM sp_unread() WHERE channel_id = pg_temp.i('launch')), 'Marco''s unread in #launch: Priya''s message (his own replies read)');
SELECT sp_channel_mark_read(pg_temp.i('launch'), pg_temp.i('m3'));
SELECT pg_temp.check((SELECT unread_count = 0 FROM sp_unread() WHERE channel_id = pg_temp.i('launch')), 'marking read clears the unread');
-- a tombstone
SELECT pg_temp.as_member(26);
UPDATE messages SET deleted_at = now() WHERE id = pg_temp.i('m1');
SELECT pg_temp.check((SELECT plain_text = '' AND deleted_by = 26 AND reply_count = 2 FROM messages WHERE id = pg_temp.i('m1')), 'a deleted message is a tombstone: no words, the thread''s shape kept');
SELECT pg_temp.refused($$UPDATE messages SET body = sp_rich_text('back') WHERE id = pg_temp.i('m1')$$, 'deleted message', 'a deleted message is not edited');
SELECT pg_temp.refused($$INSERT INTO messages (channel_id, thread_root_id, body) VALUES (pg_temp.i('launch'), pg_temp.i('m1'), sp_rich_text('late'))$$, 'was deleted', 'no reply to a deleted message');
-- DM and group DM
SELECT pg_temp.as_member(26);
INSERT INTO ids (k, i) VALUES ('dm', sp_dm_open(27));
SELECT pg_temp.check(sp_dm_open(27) = pg_temp.i('dm') AND (SELECT count(*) FROM channel_members WHERE channel_id = pg_temp.i('dm')) = 2, 'a DM is found again by its pair and has exactly two members');
SELECT pg_temp.refused($$SELECT sp_dm_open(26)$$, 'someone else', 'no DM with oneself');
SELECT pg_temp.refused($$INSERT INTO channel_members (channel_id, member_id) VALUES (pg_temp.i('dm'), 1)$$, 'exactly two', 'a third member cannot join a DM');
SELECT pg_temp.refused($$DELETE FROM channel_members WHERE channel_id = pg_temp.i('dm') AND member_id = 27$$, 'two people', 'nobody leaves a DM');
INSERT INTO ids (k, i) VALUES ('gdm', sp_group_dm_open(ARRAY[27, 28]));
SELECT pg_temp.check((SELECT kind = 'group_dm' FROM channels WHERE id = pg_temp.i('gdm')) AND sp_group_dm_open(ARRAY[28, 27]) = pg_temp.i('gdm'), 'a group DM of three is found again by its members');
SELECT pg_temp.refused($$SELECT sp_group_dm_open(ARRAY[27])$$, 'at least three', 'a group DM needs three people');
SELECT pg_temp.as_member(29);
SELECT pg_temp.refused($$SELECT sp_dm_open(30)$$, 'can see', 'a guest cannot DM a member they cannot see');
SELECT pg_temp.as_member(26);
INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('dm'), sp_rich_text('Marco, a word?'));
SELECT pg_temp.check((SELECT count(*) FROM sp_my_dms() WHERE channel_id = pg_temp.i('dm') AND last_line = 'Marco, a word?') = 1, 'my DMs list the conversation with its last line');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'dm') = 1, 'a DM notifies the other person');
-- a scheduled message
WITH ins AS (INSERT INTO messages (channel_id, body, scheduled_for) VALUES (pg_temp.i('launch'), sp_rich_text('Reminder: standup'), now() + interval '2 hours') RETURNING id) INSERT INTO ids (k, i) SELECT 'sched', id FROM ins;
SELECT pg_temp.check((SELECT sent_at IS NULL FROM messages WHERE id = pg_temp.i('sched')) AND (SELECT count(*) FROM sp_channel_history(pg_temp.i('launch')) x WHERE (x->>'message_id')::bigint = pg_temp.i('sched')) = 0, 'a scheduled message is unsent and unseen until its time');
SELECT pg_temp.check(sp_pass_scheduled() = 0, 'the scheduled pass sends nothing before its time');
UPDATE messages SET scheduled_for = now() - interval '1 minute' WHERE id = pg_temp.i('sched');
SELECT pg_temp.check(sp_pass_scheduled() = 1, 'the scheduled pass sends the one message due');
SELECT pg_temp.check((SELECT sent_at IS NOT NULL FROM messages WHERE id = pg_temp.i('sched')), 'and it is sent');
-- mentions: a department, @channel, a member
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('gch'), sp_rich_text('Heads up ') || jsonb_build_array(
    jsonb_build_object('type','mention','mention',jsonb_build_object('type','department','id','8'),'plain_text','@Engineering'),
    jsonb_build_object('type','mention','mention',jsonb_build_object('type','member','id','28'),'plain_text','@Bea'),
    jsonb_build_object('type','mention','mention',jsonb_build_object('type','channel'),'plain_text','@channel'))) RETURNING id) INSERT INTO ids (k, i) SELECT 'm4', id FROM ins;
SELECT pg_temp.check((SELECT array_agg(kind ORDER BY kind) FROM message_mentions WHERE message_id = pg_temp.i('m4')) = '{channel,department,member}', 'mentions are extracted: a department, a member, @channel');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE message_id = pg_temp.i('m4') AND kind = 'mention') >= 4, 'a @channel notifies the channel''s followers; the department''s members and Bea too');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE message_id = pg_temp.i('m4') AND member_id = 27) = 0, 'the author is never notified of their own message');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check((SELECT mention_count >= 1 FROM sp_unread() WHERE channel_id = pg_temp.i('gch')) AND (SELECT count(*) FROM sp_activity_feed() WHERE kind = 'mention') = 1, 'Dana sees the mention in her unread and her Activity');

-- ---------------------------------------------------------------- agents: a mention dispatch, a DM dispatch, the reply loop (§5, D10)
SELECT pg_temp.as_member(26);
WITH ins AS (INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('gch'), sp_rich_text('What did we decide about pricing? ') || jsonb_build_array(jsonb_build_object('type','mention','mention',jsonb_build_object('type','agent','id','40'),'plain_text','@Seamus'))) RETURNING id) INSERT INTO ids (k, i) SELECT 'm5', id FROM ins;
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE record_id = pg_temp.i('m5') AND agent_member_id = 40 AND kind = 'mention' AND via = 'chat' AND acting_member_id = 26 AND conversation_id = 'spaces:thread:' || pg_temp.i('m5')) = 1, 'a mention of an agent writes a dispatch: one chat turn as the agent, the asker acting, the thread the conversation');
INSERT INTO ids (k, i) VALUES ('agentdm', sp_dm_open(40));
INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('agentdm'), sp_rich_text('Seamus, summarise #launch'));
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE channel_id = pg_temp.i('agentdm') AND kind = 'dm') = 1, 'a DM to an agent writes a dispatch of kind dm');
SELECT pg_temp.check((SELECT count(*) FROM sp_dispatches_due()) = 2, 'both dispatches are due for the worker');
CREATE TEMP TABLE t_disp AS SELECT dispatch_id FROM sp_dispatches_due() WHERE message_id = pg_temp.i('m5');
INSERT INTO ids (k, i) SELECT 'd1', dispatch_id FROM t_disp;
SELECT pg_temp.as_member(NULL);   -- the worker has no member
SELECT sp_dispatch_record(pg_temp.i('d1'), 'running', 501, 'req-501');
SELECT pg_temp.check((SELECT count(*) FROM messages WHERE kind = 'agent_pending' AND thread_root_id = pg_temp.i('m5') AND author_member_id = 40) = 1 AND (SELECT count(*) FROM sp_dispatches_due()) = 1, 'a running dispatch shows the agent thinking in the thread and leaves the queue');
INSERT INTO ids (k, i) VALUES ('reply', sp_dispatch_record(pg_temp.i('d1'), 'answered', 501, 'req-501', sp_rich_text('We decided Monday 9am — see Pricing.')));
SELECT pg_temp.check((SELECT kind = 'message' AND author_member_id = 40 AND agent_run_id = 501 AND thread_root_id = pg_temp.i('m5') AND sent_at IS NOT NULL FROM messages WHERE id = pg_temp.i('reply')), 'the reply is posted in the thread as the agent, stamped with the kernel''s run');
SELECT pg_temp.check((SELECT status = 'answered' AND reply_message_id = pg_temp.i('reply') AND reply_excerpt LIKE 'We decided%' FROM agent_dispatches WHERE id = pg_temp.i('d1')), 'the dispatch records the answer');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'agent_replied' AND message_id = pg_temp.i('reply')) = 1, 'the asker is told the agent replied');
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE record_id = pg_temp.i('reply')) = 0, 'an agent''s own words dispatch nothing (no loop)');
CREATE TEMP TABLE t_disp2 AS SELECT dispatch_id FROM sp_dispatches_due();
INSERT INTO ids (k, i) SELECT 'd2', dispatch_id FROM t_disp2;
SELECT sp_dispatch_record(pg_temp.i('d2'), 'failed', NULL, NULL, NULL, 'kernel unreachable');
SELECT pg_temp.check((SELECT status = 'sent' AND attempts = 2 AND next_attempt_at > now() FROM agent_dispatches WHERE id = pg_temp.i('d2')) AND (SELECT count(*) FROM sp_dispatches_due()) = 0, 'a failed dispatch waits with backoff and retries later');
UPDATE agent_dispatches SET attempts = 6 WHERE id = pg_temp.i('d2');
SELECT sp_dispatch_record(pg_temp.i('d2'), 'failed', NULL, NULL, NULL, 'still down');
SELECT pg_temp.check((SELECT status = 'failed' FROM agent_dispatches WHERE id = pg_temp.i('d2')), 'after five attempts a dispatch fails for good');

-- ---------------------------------------------------------------- comments: inline, a discussion, resolved (§6)
SELECT pg_temp.as_member(26);
WITH ins AS (INSERT INTO comments (page_id, block_id, body) VALUES (pg_temp.u('child'), pg_temp.u('b2'), sp_rich_text('Is this still true? ') || jsonb_build_array(jsonb_build_object('type','mention','mention',jsonb_build_object('type','member','id','27'),'plain_text','@Marco'))) RETURNING id) INSERT INTO ids (k, u) SELECT 'c1', id FROM ins;
SELECT pg_temp.check((SELECT count(*) FROM comment_mentions WHERE comment_id = pg_temp.u('c1') AND principal_id = 27) = 1 AND (SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'comment' AND record_uuid = pg_temp.u('child')) = 1, 'an inline comment''s mention notifies the person');
SELECT pg_temp.as_member(27);
WITH ins AS (INSERT INTO comments (page_id, parent_comment_id, body) VALUES (pg_temp.u('child'), pg_temp.u('c1'), sp_rich_text('Yes, verified today')) RETURNING id) INSERT INTO ids (k, u) SELECT 'c2', id FROM ins;
SELECT pg_temp.check((SELECT block_id = pg_temp.u('b2') FROM comments WHERE id = pg_temp.u('c2')), 'a reply joins its discussion''s block');
SELECT pg_temp.refused($$INSERT INTO comments (page_id, parent_comment_id, body) VALUES (pg_temp.u('child'), pg_temp.u('c2'), sp_rich_text('deeper'))$$, 'one level deep', 'a discussion is one level deep');
SELECT pg_temp.refused($$UPDATE comments SET resolved_at = now() WHERE id = pg_temp.u('c2')$$, 'not a reply', 'resolve the discussion, not a reply');
UPDATE comments SET resolved_at = now() WHERE id = pg_temp.u('c1');
SELECT pg_temp.check((SELECT resolved_by = 27 FROM comments WHERE id = pg_temp.u('c1')), 'resolving records who');
SELECT pg_temp.refused($$INSERT INTO comments (page_id, parent_comment_id, body) VALUES (pg_temp.u('child'), pg_temp.u('c1'), sp_rich_text('too late'))$$, 'resolved', 'no reply in a resolved discussion');
SELECT pg_temp.refused($$UPDATE comments SET body = sp_rich_text('edited by another') WHERE id = pg_temp.u('c1')$$, 'Only the author', 'only the author edits a comment');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check((SELECT count(*) FROM mcp_comments WHERE page_id = pg_temp.u('child')) = 2 AND (SELECT count(*) FROM mcp_comments WHERE page_id = pg_temp.u('root')) = 0, 'comments follow their page''s visibility (Dana: comment on the shared page, nothing on the root)');

-- ---------------------------------------------------------------- versions: snapshot, restore (§6)
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(sp_version_save(pg_temp.u('md'), 'manual') = 1, 'a manual snapshot is version 1');
UPDATE blocks SET content = jsonb_build_object('rich_text', sp_rich_text('H1 changed')), version = 1 WHERE page_id = pg_temp.u('md') AND type = 'heading_1';
INSERT INTO ids (k, u) VALUES ('late', sp_block_insert(pg_temp.u('md'), NULL, NULL, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('added after the snapshot'))));
SELECT pg_temp.check(sp_pass_snapshots() = 0, 'the interval pass waits for the page to be quiet');
UPDATE pages SET last_edited_at = now() - interval '1 hour' WHERE id = pg_temp.u('md');
SELECT pg_temp.check(sp_pass_snapshots() = 1, 'the interval pass snapshots the one quiet, changed page');
SELECT pg_temp.check((SELECT version_no FROM pages WHERE id = pg_temp.u('md')) = 2, 'as version 2');
SELECT pg_temp.check(sp_pass_snapshots() = 0, 'and not again while nothing changed');
SELECT pg_temp.refused($$UPDATE page_versions SET snapshot = '{}' WHERE page_id = pg_temp.u('md')$$, 'permission denied', 'the writer may not touch a version (no UPDATE grant)');
RESET ROLE;
SELECT pg_temp.refused($$UPDATE page_versions SET snapshot = '{}' WHERE page_id = pg_temp.u('md')$$, 'never rewritten', 'a version is never rewritten (the trigger, even for the superuser)');
SET ROLE spaces_rw; SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT sp_version_markdown(id) LIKE '%# H1%' AND sp_version_markdown(id) NOT LIKE '%H1 changed%' FROM page_versions WHERE page_id = pg_temp.u('md') AND version_no = 1), 'a version renders as it was');
SELECT pg_temp.check(sp_version_restore((SELECT id FROM page_versions WHERE page_id = pg_temp.u('md') AND version_no = 1)) = 4, 'restoring version 1 snapshots the present (3) and writes the restore (4)');
SELECT pg_temp.check((SELECT plain_text FROM blocks WHERE page_id = pg_temp.u('md') AND type = 'heading_1') = 'H1' AND (SELECT count(*) FROM blocks WHERE id = pg_temp.u('late')) = 0
    AND (SELECT count(*) FROM pages WHERE parent_page_id = pg_temp.u('md') AND archived_at IS NULL) = 2 AND (SELECT count(*) FROM blocks WHERE page_id = pg_temp.u('md') AND type IN ('child_page', 'child_database')) = 2, 'the restore brings the old words back, drops the later block, keeps the live subpages and their edges');
SELECT pg_temp.check((SELECT array_agg(reason ORDER BY version_no) FROM page_versions WHERE page_id = pg_temp.u('md')) = '{manual,interval,before_restore,restore}', 'the versions tell their reasons');
RESET ROLE; SET LOCAL session_replication_role = replica;   -- age two versions as postgres (the writer may not touch a version: the immutability rule)
UPDATE page_versions SET created_at = now() - interval '400 days' WHERE page_id = pg_temp.u('md') AND version_no IN (1, 2);
SET LOCAL session_replication_role = origin; SET ROLE spaces_rw; SELECT pg_temp.as_member(27);
SELECT pg_temp.check(sp_pass_version_prune() = 2, 'retention prunes the two old versions');
SELECT pg_temp.check((SELECT count(*) FROM page_versions WHERE page_id = pg_temp.u('md')) = 2, 'the newest always kept');

-- ---------------------------------------------------------------- the trash and its purge; retention on a channel (§6, D9)
SELECT pg_temp.as_member(27);
SELECT sp_page_trash(pg_temp.u('dup'));
SELECT pg_temp.check(sp_pass_trash_purge() = 0, 'the trash keeps a page within its retention');
UPDATE pages SET archived_at = now() - interval '40 days' WHERE archived_via = pg_temp.u('dup') OR id = pg_temp.u('dup');
SELECT pg_temp.check(sp_pass_trash_purge() = 2, 'past the retention the page and its subtree (2) are purged');
SELECT pg_temp.check((SELECT count(*) FROM pages WHERE id = pg_temp.u('dup')) = 0, 'for good');
SELECT pg_temp.refused($$SELECT sp_page_purge(pg_temp.u('root'))$$, 'in the trash', 'only a page in the trash is purged');
UPDATE channels SET retention_days = 7 WHERE id = pg_temp.i('launch');
INSERT INTO messages (channel_id, body, created_at) VALUES (pg_temp.i('launch'), sp_rich_text('ancient'), now() - interval '8 days');
SELECT pg_temp.check(sp_pass_retention() = 1, 'a channel with 7-day retention loses one message on day 8');
SELECT pg_temp.check((SELECT count(*) FROM messages WHERE channel_id = pg_temp.i('launch') AND plain_text = 'ancient') = 0 AND (SELECT count(*) FROM messages WHERE channel_id = pg_temp.i('launch')) >= 4, 'the ancient one; the others stay');
UPDATE channels SET retention_days = NULL WHERE id = pg_temp.i('launch');
SELECT pg_temp.check(sp_pass_retention() = 0, 'no retention, nothing lost (D9: off by default)');
UPDATE channels SET archived_at = now() WHERE id = pg_temp.i('leads');
SELECT pg_temp.refused($$INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('leads'), sp_rich_text('x'))$$, 'archived', 'an archived channel is readable, never writable');
SELECT pg_temp.refused($$UPDATE channels SET archived_at = now() WHERE id = pg_temp.i('gch')$$, 'cannot be archived', 'a space''s default channel cannot be archived');

-- ---------------------------------------------------------------- the wiki (D12) and the Librarian's questions (§5)
SELECT pg_temp.as_member(27);
UPDATE spaces SET is_wiki = true, wiki_default_verify_months = 3 WHERE id = pg_temp.i('prod');
INSERT INTO ids (k, u) VALUES ('wiki', sp_page_create(pg_temp.i('prod'), NULL, sp_rich_text('How we deploy')));
SELECT pg_temp.check((SELECT wiki_owner_member_id = 27 AND verification_state = 'none' FROM pages WHERE id = pg_temp.u('wiki')), 'a wiki page gets its creator as owner, unverified');
UPDATE pages SET verification_state = 'verified' WHERE id = pg_temp.u('wiki');
SELECT pg_temp.check((SELECT verified_by = 27 AND verify_until BETWEEN now() + interval '89 days' AND now() + interval '93 days' FROM pages WHERE id = pg_temp.u('wiki')), 'verifying stamps who and when, with the space''s window (3 months)');
UPDATE pages SET verify_until = now() - interval '1 day' WHERE id = pg_temp.u('wiki');
SELECT pg_temp.check((SELECT verification_state FROM sp_wiki_status(pg_temp.i('prod')) WHERE page_id = pg_temp.u('wiki')) = 'expired', 'the wiki status reads a past date as expired before the pass runs');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check(sp_pass_wiki_expire() = 1, 'the expiry pass finds the one page past its date');
SELECT pg_temp.check((SELECT verification_state FROM pages WHERE id = pg_temp.u('wiki')) = 'expired' AND (SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'verification') = 1, 'marks it expired and tells the owner once');
SELECT pg_temp.check(sp_pass_wiki_expire() = 0, 'and not twice');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM sp_visible_page_ids() x WHERE x = pg_temp.u('wiki')) = 1, 'nothing is hidden when verification expires (a badge, not a wall)');
UPDATE pages SET last_edited_at = now() - interval '120 days' WHERE id = pg_temp.u('wiki');
SELECT pg_temp.check((SELECT count(*) FROM sp_stale_pages() WHERE page_id = pg_temp.u('wiki') AND days_since_edit >= 120) = 1, 'a page not edited in 90 days is stale');
INSERT INTO ids (k, u) VALUES ('dupe', sp_page_create(pg_temp.i('prod'), NULL, sp_rich_text('how we DEPLOY')));
SELECT pg_temp.check((SELECT n FROM sp_duplicate_titles() WHERE title = 'how we deploy') = 2, 'duplicate titles are found regardless of case');
SELECT pg_temp.refused($$DELETE FROM blocks WHERE type = 'child_page' AND (content->>'page_id')::uuid = pg_temp.u('grand')$$, 'trash the subpage first', 'a live subpage''s edge block cannot be deleted from under it');
SELECT set_config('app.sp_purging', '1', true);   -- make an orphan the way an import might: an edge lost
DELETE FROM blocks WHERE type = 'child_page' AND (content->>'page_id')::uuid = pg_temp.u('grand');
SELECT set_config('app.sp_purging', '', true);
SELECT pg_temp.check((SELECT count(*) FROM sp_orphan_pages() WHERE page_id = pg_temp.u('grand')) = 1, 'a page with no edge and no link is an orphan');
SELECT sp_page_trash(pg_temp.u('dupe'));
INSERT INTO blocks (page_id, type, content) VALUES (pg_temp.u('wiki'), 'link_to_page', jsonb_build_object('page_id', pg_temp.u('dupe')));
SELECT pg_temp.check((SELECT count(*) FROM sp_broken_links() WHERE to_page_id = pg_temp.u('dupe') AND reason = 'in the trash') = 1, 'a link to the trash is a broken link');
WITH ins AS (INSERT INTO messages (channel_id, body, created_at, sent_at) VALUES (pg_temp.i('gch'), sp_rich_text('Does anyone know the wifi password?'), now() - interval '30 hours', now() - interval '30 hours') RETURNING id) INSERT INTO ids (k, i) SELECT 'q', id FROM ins;
SELECT pg_temp.check((SELECT count(*) FROM sp_unanswered_questions() WHERE message_id = pg_temp.i('q') AND hours_open >= 24) = 1, 'a question with no reply in 24 hours is unanswered');
SELECT pg_temp.check((SELECT count(*) FROM sp_unanswered_questions() WHERE message_id = pg_temp.i('m5')) = 0, 'a question that was answered is not');
WITH ins AS (INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('launch'), sp_rich_text('Which caterer for the launch?')) RETURNING id) INSERT INTO ids (k, i) SELECT 'm7', id FROM ins;
INSERT INTO messages (channel_id, thread_root_id, body) VALUES (pg_temp.i('launch'), pg_temp.i('m7'), sp_rich_text('We decided: Rosa''s, as last year'));
SELECT pg_temp.check((SELECT count(*) FROM sp_thread_candidates(1) WHERE message_id = pg_temp.i('m7') AND 'We decided: Rosa''s, as last year' = ANY (decision_lines)) = 1, 'a thread with decision words and no page citing it is a candidate');
INSERT INTO librarian_proposals (kind, subject_message_id, subject_channel_id, title, reason, proposed_by) VALUES ('thread_to_page', pg_temp.i('m1'), pg_temp.i('launch'), 'Launch date decision', 'The thread decided the launch date', 40);
SELECT pg_temp.refused($$INSERT INTO librarian_proposals (kind, subject_message_id, title, reason) VALUES ('thread_to_page', pg_temp.i('m1'), 'again', 'x')$$, 'duplicate key', 'one open proposal per subject');
SELECT pg_temp.check((SELECT count(*) FROM sp_pages_changed_since(now() - interval '1 day', pg_temp.i('prod'))) >= 3, 'pages changed since lists the day''s edits in a space');

-- ---------------------------------------------------------------- search with every modifier (§7 Q1)
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM sp_search('caterer')) >= 1 AND (SELECT entity_kind FROM sp_search('Pricing 2027') LIMIT 1) = 'page', 'search finds messages and pages');
SELECT pg_temp.check((SELECT count(*) FROM sp_search('Monday', '{"is":"message"}')) >= 1 AND (SELECT count(*) FROM sp_search('Monday', '{"is":"page"}')) = 0, 'is: narrows the kind');
SELECT pg_temp.check((SELECT count(*) FROM sp_search('decided', '{"in":"#launch"}')) >= 1 AND (SELECT count(*) FROM sp_search('decided', '{"in":"#nowhere"}')) = 0, 'in: a channel by name');
SELECT pg_temp.check((SELECT count(*) FROM sp_search('decided', jsonb_build_object('from', 27))) >= 1 AND (SELECT count(*) FROM sp_search('decided', jsonb_build_object('from', 26))) = 0, 'from: a member');
SELECT pg_temp.check((SELECT count(*) FROM sp_search('', '{"has":"link","is":"page"}')) >= 1 AND (SELECT count(*) FROM sp_search('', '{"has":"file","is":"page"}')) >= 1, 'has:link and has:file');
SELECT pg_temp.check((SELECT count(*) FROM sp_search('Monday', '{"before":"2020-01-01"}')) = 0 AND (SELECT count(*) FROM sp_search('Monday', '{"after":"2020-01-01"}')) >= 1, 'before: and after:');
SELECT pg_temp.check((SELECT count(*) FROM sp_search('Fix the login', '{"is":"row"}')) = 1, 'a row is searchable as a row');
SELECT pg_temp.check((SELECT count(*) FROM sp_search('verified today', '{"is":"comment"}')) = 1, 'a comment is searchable');
SELECT pg_temp.check((SELECT excerpt LIKE '%<mark>caterer</mark>%' FROM sp_search('caterer') LIMIT 1), 'the excerpt marks the match');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check((SELECT count(*) FROM sp_search('Pricing 2027')) = 2 AND (SELECT count(*) FROM sp_search('Product handbook')) = 0, 'search answers only what the caller may see — the shared page (whose body names its subpage) and the subpage, never the root (Pricing 2027: ' || (SELECT count(*) FROM sp_search('Pricing 2027')) || ', Product handbook: ' || (SELECT count(*) FROM sp_search('Product handbook')) || ' — ' || COALESCE((SELECT string_agg(entity_kind || ':' || COALESCE(title, '') || ':' || left(excerpt, 40), ' | ') FROM sp_search('Product handbook')), '') || ')');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM sp_search('decided')) = 0, 'a guest searches nothing they were not shared');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(sp_search_catch_up() = 0, 'the index is complete: the catch-up has nothing to do');

-- ---------------------------------------------------------------- templates, favorites, recents, the public page (§6, D13)
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM pages WHERE is_template AND kind = 'page') = 6 AND (SELECT count(*) FROM pages WHERE is_template AND kind = 'database') = 4, 'six page templates and four database templates are seeded');
SELECT pg_temp.check((SELECT count(*) FROM sp_search('Agenda')) = 0, 'templates are not searchable');
INSERT INTO ids (k, u) VALUES ('fromtpl', sp_page_duplicate((SELECT id FROM pages WHERE is_template AND plain_title = 'Meeting notes'), pg_temp.i('general'), NULL, sp_rich_text('Monday meeting')));
SELECT pg_temp.check((SELECT NOT is_template FROM pages WHERE id = pg_temp.u('fromtpl')) AND (SELECT count(*) FROM blocks WHERE page_id = pg_temp.u('fromtpl') AND type = 'heading_2') = 4, 'applying a template copies its blocks into a real page');
INSERT INTO page_favorites (member_id, page_id) VALUES (26, pg_temp.u('fromtpl'));
SELECT sp_page_visited(pg_temp.u('fromtpl'));
SELECT pg_temp.check((sp_sidebar()->'favorites'->0->>'page_id') = pg_temp.u('fromtpl')::text AND (SELECT count(*) FROM page_recents WHERE member_id = 26 AND page_id = pg_temp.u('fromtpl')) = 1, 'favorites and recents');
SELECT pg_temp.as_member(1);
INSERT INTO page_publications (page_id, token_hash, include_subpages, published_by) VALUES (pg_temp.u('root'), encode(sha256('token-1'::bytea), 'hex'), true, 1);
SELECT pg_temp.refused($$INSERT INTO page_publications (page_id, token_hash) VALUES (pg_temp.u('root'), 'other')$$, 'duplicate key', 'one live publication per page');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT page_id = pg_temp.u('root') AND include_subpages FROM sp_public_page_lookup(encode(sha256('token-1'::bytea), 'hex'))), 'the public door finds a published page by its hashed token, with no acting member');
SELECT pg_temp.check((SELECT count(*) FROM sp_public_page_lookup('nope')) = 0, 'a wrong token finds nothing');
SELECT pg_temp.check((SELECT count(*) FROM sp_public_page_ids((SELECT id FROM page_publications WHERE page_id = pg_temp.u('root')))) >= 3, 'the published subtree includes the live subpages');
SELECT pg_temp.as_member(1);
UPDATE pages SET archived_at = now(), archived_by = 1 WHERE id = pg_temp.u('root');
SELECT pg_temp.check((SELECT revoked_at IS NOT NULL FROM page_publications WHERE page_id = pg_temp.u('root')), 'trashing a published page revokes its publication');
SELECT sp_page_restore(pg_temp.u('root'));

-- ---------------------------------------------------------------- notifications and the outbox (§6, §8)
SELECT pg_temp.as_member(27);
SELECT pg_temp.check((SELECT count(*) FROM notification_outbox WHERE channel = 'email' AND member_id = 28 AND kind = 'mention' AND status = 'queued') = 1, 'an away member''s mention queues one email');
SELECT pg_temp.check((SELECT count(*) FROM notification_outbox WHERE channel = 'text') = 0, 'nobody chose texts: no text queued');
INSERT INTO notification_prefs (member_id, text_enabled, text_kinds) VALUES (28, true, '{mention}') ON CONFLICT (member_id) DO UPDATE SET text_enabled = true, text_kinds = '{mention}';
INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('gch'), sp_rich_text('Bea ') || jsonb_build_array(jsonb_build_object('type','mention','mention',jsonb_build_object('type','member','id','28'),'plain_text','@Bea')));
SELECT pg_temp.check((SELECT count(*) FROM notification_outbox WHERE channel = 'text' AND member_id = 28 AND length(body) <= 480) = 1 AND (SELECT count(*) FROM notification_outbox WHERE member_id = 28) = 3, 'a member who chose texts gets a text row AND an email for a mention, the text within the kernel''s 480 characters');
UPDATE members SET last_seen_at = now() WHERE id = 28;
INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('gch'), sp_rich_text('Bea again ') || jsonb_build_array(jsonb_build_object('type','mention','mention',jsonb_build_object('type','member','id','28'),'plain_text','@Bea')));
SELECT pg_temp.check((SELECT count(*) FROM notification_outbox WHERE member_id = 28) = 3 AND (SELECT count(*) FROM notifications WHERE member_id = 28 AND kind = 'mention') = 3, 'a member who is here now gets the bell, not another email or text');
INSERT INTO channel_members (channel_id, member_id, notify) VALUES (pg_temp.i('gch'), 30, 'none') ON CONFLICT (channel_id, member_id) DO UPDATE SET notify = 'none';
INSERT INTO messages (channel_id, body) VALUES (pg_temp.i('gch'), sp_rich_text('Dana ') || jsonb_build_array(jsonb_build_object('type','mention','mention',jsonb_build_object('type','member','id','30'),'plain_text','@Dana')));
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 30 AND kind = 'mention') = 1, 'a channel set to notify none is quiet (the earlier mention stands)');
INSERT INTO reminders (member_id, message_id, remind_at) VALUES (27, pg_temp.i('m2'), now() - interval '1 minute');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check(sp_pass_reminders() = 1, 'a reminder fallen due is picked up');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'reminder') = 1, 'and becomes a notice');
SELECT pg_temp.check(sp_pass_reminders() = 0, 'and only once');

-- ---------------------------------------------------------------- files (D14)
SELECT pg_temp.as_member(26);
INSERT INTO attachments (record_type, record_uuid, filename, mime_type, byte_size, sha256, storage_path, width, height, uploaded_by)
VALUES ('block', pg_temp.u('b2'), 'diagram.png', 'image/png', 1024, repeat('a', 64), 'storage/attachments/block/x/1-diagram.png', 800, 600, 26);
INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by)
VALUES ('message', pg_temp.i('m2'), 'notes.pdf', 'application/pdf', 2048, repeat('b', 64), 'storage/attachments/message/x/2-notes.pdf', 27);
SELECT pg_temp.check((SELECT attachment_count FROM messages WHERE id = pg_temp.i('m2')) = 1, 'a message counts its attachments');
SELECT pg_temp.refused($$INSERT INTO attachments (record_type, filename, mime_type, byte_size, sha256, storage_path) VALUES ('block', 'x', 'text/plain', 1, 'c', 'p')$$, 'violates check', 'an attachment names exactly one record (bigint or uuid)');
SELECT pg_temp.as_member(30); SELECT pg_temp.check((SELECT count(*) FROM mcp_attachments) = 1, 'Dana sees the attachment on the shared page, not the one in the closed space''s channel');
SELECT pg_temp.as_member(29); SELECT pg_temp.check((SELECT count(*) FROM mcp_attachments) = 1, 'the guest sees the attachment on the page shared with them');

-- ---------------------------------------------------------------- the views, as the read roles (§6, db/017)
RESET ROLE; SET ROLE spaces_records_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_spaces) = 5 AND (SELECT count(*) FROM mcp_spaces WHERE i_am_member) = 3 AND (SELECT count(*) FROM mcp_spaces WHERE kind = 'private') = 1, 'the records role, as Priya: five spaces in view (two closed ones she may ask to join), three hers, one private');
SELECT pg_temp.check((SELECT count(*) FROM mcp_pages) = (SELECT count(*) FROM sp_visible_page_ids()) AND (SELECT count(*) FROM mcp_pages WHERE my_level = 'none') = 0, 'mcp_pages is exactly the visible set, each with my level');
SELECT pg_temp.check((SELECT count(*) FROM mcp_channels WHERE channel_id = pg_temp.i('leads')) = 1 AND (SELECT count(*) FROM mcp_messages WHERE channel_id = pg_temp.i('launch')) >= 4, 'channels and messages she may read');
SELECT pg_temp.check((SELECT count(*) FROM mcp_members WHERE is_agent) = 1 AND (SELECT count(*) FROM mcp_members WHERE email IS NOT NULL) = 1, 'the people picker marks agents; an email is one''s own only');
SELECT pg_temp.check((SELECT count(*) FROM mcp_notifications) >= 1 AND (SELECT count(*) FROM mcp_notifications WHERE member_id <> 26) = 0 AND (SELECT count(*) FROM mcp_reminders) = 0, 'notifications and reminders are one''s own');
SELECT pg_temp.check((SELECT count(*) FROM mcp_blocks WHERE page_id = pg_temp.u('private')) >= 0 AND (SELECT count(*) FROM mcp_page_permissions WHERE page_id = pg_temp.u('child')) >= 1, 'blocks and permissions follow the page');
SELECT pg_temp.check((SELECT count(*) FROM mcp_agent_dispatches) >= 1, 'she sees the dispatches she caused');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check((SELECT count(*) FROM mcp_pages WHERE page_id = pg_temp.u('root')) = 0 AND (SELECT count(*) FROM mcp_pages WHERE page_id = pg_temp.u('child')) = 1 AND (SELECT my_level FROM mcp_pages WHERE page_id = pg_temp.u('child')) = 'comment', 'as Dana: the shared page at comment, not its parent');
SELECT pg_temp.check((SELECT count(*) FROM mcp_spaces WHERE space_id = pg_temp.i('priv')) = 0 AND (SELECT count(*) FROM mcp_spaces WHERE space_id = pg_temp.i('prod') AND NOT i_am_member) = 1, 'as Dana: the private space is absent, the closed one shown as joinable');
SELECT pg_temp.check((SELECT count(*) FROM mcp_space_join_requests WHERE member_id = 30) = 1, 'her own join request');
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_pages) = 2 AND (SELECT count(*) FROM mcp_channels) = 0 AND (SELECT count(*) FROM mcp_spaces) = 0 AND (SELECT count(*) FROM mcp_members) <= 3, 'as the guest: the two shared pages, no channel, no space, few people');
SELECT pg_temp.as_member(41);
SELECT pg_temp.check((SELECT count(*) FROM mcp_pages) = 0 AND (SELECT count(*) FROM mcp_members) = 0, 'an unadmitted agent sees nothing');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT count(*) FROM mcp_pages) + (SELECT count(*) FROM mcp_channels) + (SELECT count(*) FROM mcp_members) + (SELECT count(*) FROM mcp_spaces) = 0, 'no acting member: every view is empty');
SELECT pg_temp.refused('SELECT count(*) FROM pages', 'permission denied', 'the records role cannot read the pages table');
SELECT pg_temp.refused('SELECT count(*) FROM messages', 'permission denied', 'the records role cannot read the messages table');
SELECT pg_temp.refused('SELECT count(*) FROM activity_log', 'permission denied', 'the records role cannot read the activity log');
SELECT pg_temp.refused('SELECT token_hash FROM page_publications', 'permission denied', 'the records role never sees a public token');
SELECT pg_temp.refused($$INSERT INTO pages (space_id, title) VALUES (1, '[]')$$, 'permission denied', 'the records role writes nothing');
SELECT pg_temp.check(mcp_member_kind(40) = 'agent' AND mcp_member_kind(41) IS NULL, 'mcp_member_kind answers for an admitted agent only');
SELECT pg_temp.check(mcp_admit_agent(41), 'first contact admits an active agent');
SELECT pg_temp.check(mcp_member_kind(41) = 'agent', 'the agent is admitted');
SELECT pg_temp.check(NOT mcp_admit_agent(41), 'and not twice');
RESET ROLE; SET ROLE spaces_activity_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 0, 'the activity role reads the log (empty in this proof)');
SELECT pg_temp.refused('SELECT count(*) FROM mcp_blocks', 'permission denied', 'the activity role sees no content view');
SELECT pg_temp.refused('SELECT count(*) FROM blocks', 'permission denied', 'the activity role sees no base table');
RESET ROLE;

-- ---------------------------------------------------------------- the one that proves the proof: every statement above ran as a role, nothing committed
SET ROLE spaces_rw;
SELECT pg_temp.check((SELECT count(*) FROM members) = 9, 'the fixtures are there until the rollback');
RESET ROLE;

\o
\pset tuples_only on
SELECT CASE WHEN ok THEN ' ok   ' ELSE ' FAIL ' END || label FROM proof ORDER BY n;
SELECT ' ' || count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
ROLLBACK;
