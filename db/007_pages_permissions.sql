-- 007: PAGES, THE TREE and THE PERMISSION TREE (design §3, §6; D5, D6, D12, D13).
--
-- A page is a tree of blocks (db/008), keyed by UUID (the editor makes ids as it types; URLs do not enumerate; a Notion import
-- keeps its ids — D6). It lives in a space (space_id) under a parent page, or is PRIVATE (space_id NULL, owner_member_id set:
-- its owner's alone until shared), or is a database ROW (parent_database_id: a page with typed properties, db/009).
--
-- Permissions are the tree's, resolved once: a page inherits the nearest ancestor that has explicit page_permissions rows
-- (pages.permission_root_id, kept by trigger), else the space decides (sp_space_level, db/006). Six levels, as Notion's:
-- none < view < comment < edit_content < edit < full. A page's level for a member is the HIGHEST its principals give it
-- (the member itself, an agent, a guest, a department, everyone in the space, everyone); a space's owner and a Spaces admin
-- hold full on every space page. sp_visible_page_ids() answers the caller's whole set in ONE query (the kernel's db/160
-- lesson) and every mcp_* view tests it once per statement.
--
-- Also here: versions (snapshots, never rewritten — the retired documents.md rule kept), the public page (a hashed token is
-- the authority — D13), favorites, recents, the trash (archived_at, cascading to the subtree), the wiki columns (D12), and
-- page_links (backlinks, orphans, broken links; maintained from blocks by db/008).
--
-- THE ESTATE'S DATA MODEL (design §6.1): new — nothing in the estate is close (Help Desk's knowledge base is its own, read
-- through K7); these become the canonical tables for pages.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- Fractional positions: a lexicographic key over the digits 0-9a-z. sp_position_between(a, b) returns a string strictly
-- between a and b (NULL = open end); an insert between two siblings never renumbers the rest.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_position_between(p_a text, p_b text) RETURNS text
    LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE digits constant text := '0123456789abcdefghijklmnopqrstuvwxyz';
        a text := COALESCE(p_a, ''); b text := COALESCE(p_b, '');
        i integer := 1; ca integer; cb integer; prefix text := ''; mid integer;
BEGIN
    IF p_a IS NOT NULL AND p_b IS NOT NULL AND p_a >= p_b THEN
        RAISE EXCEPTION 'sp_position_between: % is not before %', p_a, p_b USING ERRCODE = 'P0001';
    END IF;
    LOOP
        ca := CASE WHEN i <= length(a) THEN position(substr(a, i, 1) IN digits) - 1 ELSE 0 END;          -- '' sorts first
        cb := CASE WHEN i <= length(b) THEN position(substr(b, i, 1) IN digits) - 1 ELSE 36 END;         -- open end = past 'z'
        IF cb - ca >= 2 THEN
            mid := (ca + cb) / 2;
            RETURN prefix || substr(digits, mid + 1, 1);
        END IF;
        -- adjacent or equal digits: carry the digit and go one deeper (if ca+1 = cb, we must stay under b: continue with a's digit)
        prefix := prefix || substr(digits, ca + 1, 1);
        IF cb - ca = 1 THEN b := '';  END IF;   -- past this digit, b no longer bounds us (we are strictly below b's digit)
        i := i + 1;
        IF i > 60 THEN RAISE EXCEPTION 'sp_position_between: keys too deep' USING ERRCODE = 'P0001'; END IF;
    END LOOP;
END$$;

CREATE TABLE pages (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    space_id                bigint REFERENCES spaces(id) ON DELETE RESTRICT,            -- NULL = a private page
    parent_page_id          uuid REFERENCES pages(id) ON DELETE RESTRICT,
    parent_database_id      uuid,                                                        -- a ROW of this database (FK in db/009; = the database page's id)
    kind                    text NOT NULL DEFAULT 'page' CHECK (kind IN ('page', 'database')),
    title                   jsonb NOT NULL DEFAULT '[]' CHECK (sp_is_rich_text(title)),
    plain_title             text GENERATED ALWAYS AS (sp_rich_text_plain(title)) STORED,
    icon                    text,                                                        -- an emoji, or 'file:<attachment id>'
    cover_attachment_id     bigint,                                                      -- FK in db/012
    is_locked               boolean NOT NULL DEFAULT false,
    is_template             boolean NOT NULL DEFAULT false,
    template_of_database_id uuid,                                                        -- a database's row template
    owner_member_id         bigint REFERENCES members(id) ON DELETE SET NULL,            -- a private page's owner; else the creator
    wiki_owner_member_id    bigint REFERENCES members(id) ON DELETE SET NULL,            -- D12
    verification_state      text NOT NULL DEFAULT 'none' CHECK (verification_state IN ('none', 'verified', 'expired')),
    verified_at             timestamptz,
    verified_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    verify_until            timestamptz,
    properties              jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(properties) = 'object'),   -- a row's values (db/009)
    permission_root_id      uuid REFERENCES pages(id) ON DELETE SET NULL,               -- the nearest ancestor (or self) with explicit rows; NULL = the space
    position                text NOT NULL DEFAULT 'a0',
    section_id              bigint REFERENCES space_sections(id) ON DELETE SET NULL,     -- a root page's sidebar section
    content_rev             bigint NOT NULL DEFAULT 0,                                   -- bumps on every block write (polling, snapshots)
    version_no              integer NOT NULL DEFAULT 0,                                  -- the last snapshot's number
    snapshot_rev            bigint NOT NULL DEFAULT 0,                                   -- content_rev at the last snapshot
    created_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at              timestamptz NOT NULL DEFAULT now(),
    last_edited_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    last_edited_at          timestamptz NOT NULL DEFAULT now(),
    archived_at             timestamptz,                                                 -- the trash
    archived_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_via            uuid,                                                        -- the page whose trashing took this one along
    updated_at              timestamptz NOT NULL DEFAULT now(),
    CHECK (space_id IS NOT NULL OR owner_member_id IS NOT NULL),                        -- a private page has an owner
    CHECK (parent_database_id IS NULL OR parent_page_id IS NOT NULL)                    -- a row sits under its database's page
);
CREATE INDEX pages_space_parent_idx    ON pages (space_id, parent_page_id, position) WHERE archived_at IS NULL;
CREATE INDEX pages_parent_idx          ON pages (parent_page_id);
CREATE INDEX pages_database_idx        ON pages (parent_database_id) WHERE parent_database_id IS NOT NULL;
CREATE INDEX pages_owner_idx           ON pages (owner_member_id) WHERE space_id IS NULL;
CREATE INDEX pages_permission_root_idx ON pages (permission_root_id);
CREATE INDEX pages_title_trgm_idx      ON pages USING gin (plain_title gin_trgm_ops);
CREATE INDEX pages_edited_idx          ON pages (last_edited_at DESC);
CREATE INDEX pages_trash_idx           ON pages (archived_at) WHERE archived_at IS NOT NULL;
CREATE INDEX pages_wiki_idx            ON pages (space_id, verification_state, verify_until) WHERE archived_at IS NULL;
CREATE INDEX pages_template_idx        ON pages (space_id, is_template) WHERE is_template;
CREATE TRIGGER pages_touch BEFORE UPDATE ON pages FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE page_permissions (
    page_id         uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    principal_kind  text NOT NULL CHECK (principal_kind IN ('member', 'department', 'agent', 'guest', 'everyone_in_space', 'everyone')),
    principal_id    bigint,                                                              -- NULL for the two everyone kinds
    level           text NOT NULL CHECK (level IN ('none', 'view', 'comment', 'edit_content', 'edit', 'full')),
    granted_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    CHECK ((principal_kind IN ('everyone_in_space', 'everyone')) = (principal_id IS NULL))
);
CREATE UNIQUE INDEX page_permissions_principal ON page_permissions (page_id, principal_kind, COALESCE(principal_id, 0));
CREATE INDEX page_permissions_lookup ON page_permissions (principal_kind, principal_id, page_id);

CREATE TABLE page_links (
    from_page_id    uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    from_block_id   uuid,                                                                -- the block that carries the link (db/008); NULL = the page's properties
    to_page_id      uuid NOT NULL,                                                       -- no FK: a link to a trashed or deleted page is what broken_links finds
    kind            text NOT NULL CHECK (kind IN ('link_to_page', 'child_page', 'child_database', 'mention', 'relation')),
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX page_links_to_idx   ON page_links (to_page_id);
CREATE INDEX page_links_from_idx ON page_links (from_page_id);
CREATE INDEX page_links_block_idx ON page_links (from_block_id);

CREATE TABLE page_favorites (
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    page_id     uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    position    text NOT NULL DEFAULT 'a0',
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, page_id)
);
CREATE TABLE page_recents (
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    page_id     uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    visited_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, page_id)
);
CREATE INDEX page_recents_member_idx ON page_recents (member_id, visited_at DESC);

-- Snapshots: the whole block tree, title and properties, as JSON. Written by the worker when the page changed and the last
-- snapshot is older than the interval, on `version_save`, before a restore, and on lock. NEVER rewritten; restoring writes
-- a new version and a new snapshot. Pruned by retention (db/014).
CREATE TABLE page_versions (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    page_id       uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    version_no    integer NOT NULL,
    content_rev   bigint NOT NULL,
    reason        text NOT NULL CHECK (reason IN ('interval', 'manual', 'before_restore', 'lock', 'import', 'restore')),
    snapshot      jsonb NOT NULL,                                                        -- {title, icon, properties, blocks:[...tree...]}
    plain_text    text,
    saved_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (page_id, version_no)
);
CREATE INDEX page_versions_page_idx ON page_versions (page_id, version_no DESC);

CREATE OR REPLACE FUNCTION sp_page_versions_immutable() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'A page version is never rewritten: restore it, which writes a new one' USING ERRCODE = 'P0001';
END$$;
CREATE TRIGGER page_versions_immutable BEFORE UPDATE ON page_versions FOR EACH ROW EXECUTE FUNCTION sp_page_versions_immutable();

-- The public page (D13). One live publication per page; the token is hashed; rotating writes a new row and revokes the old.
CREATE TABLE page_publications (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    page_id           uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    token_hash        text NOT NULL UNIQUE,                                              -- sha256 of the 48-hex token
    include_subpages  boolean NOT NULL DEFAULT false,
    noindex           boolean NOT NULL DEFAULT true,
    public_properties text[] NOT NULL DEFAULT '{}',                                     -- a row's property keys shown publicly
    layout            text CHECK (layout IS NULL OR layout IN ('table', 'gallery')),    -- a published database
    published_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    published_at      timestamptz NOT NULL DEFAULT now(),
    revoked_at        timestamptz,
    revoked_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    views             bigint NOT NULL DEFAULT 0,
    last_viewed_at    timestamptz
);
CREATE UNIQUE INDEX page_publications_one_live ON page_publications (page_id) WHERE revoked_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- The permission tree.
-- ---------------------------------------------------------------------------------------------
-- The nearest ancestor (self included) with explicit rows; NULL when none up to the root (the space decides, or the owner).
CREATE OR REPLACE FUNCTION sp_compute_permission_root(p_page_id uuid) RETURNS uuid
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE cur uuid := p_page_id; par uuid; n integer := 0;
BEGIN
    WHILE cur IS NOT NULL LOOP
        IF EXISTS (SELECT 1 FROM page_permissions pp WHERE pp.page_id = cur) THEN RETURN cur; END IF;
        SELECT parent_page_id INTO par FROM pages WHERE id = cur;
        cur := par; n := n + 1;
        IF n > 200 THEN RAISE EXCEPTION 'page tree too deep' USING ERRCODE = 'P0001'; END IF;
    END LOOP;
    RETURN NULL;
END$$;

-- Recompute permission_root_id for a page and its whole subtree (after a permission row changed or the page moved).
CREATE OR REPLACE FUNCTION sp_recompute_permission_roots(p_page_id uuid) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    WITH RECURSIVE sub AS (
        SELECT id FROM pages WHERE id = p_page_id
        UNION ALL
        SELECT c.id FROM pages c JOIN sub ON c.parent_page_id = sub.id
    )
    UPDATE pages p SET permission_root_id = sp_compute_permission_root(p.id)
      FROM sub WHERE p.id = sub.id;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- Sharing is additive, restricting is explicit (Notion's rule): the first explicit row on a space page carries the space's
-- default along as an everyone_in_space row (and `everyone` for an open space) — so sharing a page with one more person never
-- takes it from the space's members. sp_page_restrict() removes those rows; that is what "Restrict access" does.
CREATE OR REPLACE FUNCTION sp_page_permissions_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; s spaces%ROWTYPE;
BEGIN
    IF NEW.granted_by IS NULL THEN NEW.granted_by := app_current_member_id(); END IF;
    IF NEW.principal_kind IN ('everyone_in_space', 'everyone') THEN RETURN NEW; END IF;
    IF current_setting('app.sp_restrict', true) = '1' OR current_setting('app.sp_perm_copy', true) = '1' THEN RETURN NEW; END IF;
    IF EXISTS (SELECT 1 FROM page_permissions pp WHERE pp.page_id = NEW.page_id) THEN RETURN NEW; END IF;
    PERFORM set_config('app.sp_perm_copy', '1', true);
    SELECT * INTO p FROM pages WHERE id = NEW.page_id;
    IF p.space_id IS NULL THEN RETURN NEW; END IF;                          -- a private page: the owner's alone plus the shares
    SELECT * INTO s FROM spaces WHERE id = p.space_id;
    -- the default this page inherited, made explicit so it survives the share
    IF p.parent_page_id IS NOT NULL AND p.permission_root_id IS NOT NULL AND p.permission_root_id <> p.id THEN
        INSERT INTO page_permissions (page_id, principal_kind, principal_id, level, granted_by)
        SELECT NEW.page_id, pp.principal_kind, pp.principal_id, pp.level, NEW.granted_by FROM page_permissions pp WHERE pp.page_id = p.permission_root_id;
    ELSE
        INSERT INTO page_permissions (page_id, principal_kind, level, granted_by) VALUES (NEW.page_id, 'everyone_in_space', s.member_level, NEW.granted_by);
        IF s.kind = 'open' AND s.everyone_level <> 'none' THEN
            INSERT INTO page_permissions (page_id, principal_kind, level, granted_by) VALUES (NEW.page_id, 'everyone', s.everyone_level, NEW.granted_by);
        END IF;
    END IF;
    PERFORM set_config('app.sp_perm_copy', '', true);
    RETURN NEW;
END$$;
CREATE TRIGGER page_permissions_before BEFORE INSERT ON page_permissions FOR EACH ROW EXECUTE FUNCTION sp_page_permissions_before();

-- Restrict: only the principals named on the page reach it (the sharer keeps full so the page is not lost).
CREATE OR REPLACE FUNCTION sp_page_restrict(p_page_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); p pages%ROWTYPE;
BEGIN
    SELECT * INTO p FROM pages WHERE id = p_page_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'The page does not exist' USING ERRCODE = 'P0001'; END IF;
    PERFORM set_config('app.sp_restrict', '1', true);
    IF p.parent_page_id IS NOT NULL AND p.permission_root_id IS NOT NULL AND p.permission_root_id <> p.id THEN
        INSERT INTO page_permissions (page_id, principal_kind, principal_id, level)
        SELECT p_page_id, pp.principal_kind, pp.principal_id, pp.level FROM page_permissions pp WHERE pp.page_id = p.permission_root_id AND pp.principal_kind NOT IN ('everyone_in_space', 'everyone')
        ON CONFLICT DO NOTHING;
    END IF;
    IF me IS NOT NULL AND p.space_id IS NOT NULL THEN
        INSERT INTO page_permissions (page_id, principal_kind, principal_id, level) VALUES (p_page_id, 'member', me, 'full') ON CONFLICT DO NOTHING;
    END IF;
    DELETE FROM page_permissions WHERE page_id = p_page_id AND principal_kind IN ('everyone_in_space', 'everyone');
    PERFORM set_config('app.sp_restrict', '', true);
    -- a restricted page with no rows at all would fall back to the space: keep at least the restricter
    IF NOT EXISTS (SELECT 1 FROM page_permissions WHERE page_id = p_page_id) AND me IS NOT NULL THEN
        PERFORM set_config('app.sp_restrict', '1', true);
        INSERT INTO page_permissions (page_id, principal_kind, principal_id, level) VALUES (p_page_id, 'member', me, 'full');
        PERFORM set_config('app.sp_restrict', '', true);
    END IF;
    PERFORM sp_recompute_permission_roots(p_page_id);
END$$;

-- Unrestrict: the space's default comes back (the shares stay).
CREATE OR REPLACE FUNCTION sp_page_unrestrict(p_page_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; s spaces%ROWTYPE;
BEGIN
    SELECT * INTO p FROM pages WHERE id = p_page_id;
    IF p.space_id IS NULL THEN RETURN; END IF;
    SELECT * INTO s FROM spaces WHERE id = p.space_id;
    INSERT INTO page_permissions (page_id, principal_kind, level) VALUES (p_page_id, 'everyone_in_space', s.member_level) ON CONFLICT DO NOTHING;
    IF s.kind = 'open' AND s.everyone_level <> 'none' THEN
        INSERT INTO page_permissions (page_id, principal_kind, level) VALUES (p_page_id, 'everyone', s.everyone_level) ON CONFLICT DO NOTHING;
    END IF;
END$$;

CREATE OR REPLACE FUNCTION sp_page_permissions_changed() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM sp_recompute_permission_roots(COALESCE(NEW.page_id, OLD.page_id));
    RETURN NULL;
END$$;
CREATE TRIGGER page_permissions_changed AFTER INSERT OR DELETE ON page_permissions FOR EACH ROW EXECUTE FUNCTION sp_page_permissions_changed();

-- The level a page gives a member. The HIGHEST of: owner (private) → full; space owner / Spaces admin → full on a space page;
-- explicit rows on the permission root for the member's principals; else (no root) the space's level.
CREATE OR REPLACE FUNCTION sp_page_level(p_page_id uuid, p_member_id bigint DEFAULT app_current_member_id()) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; best smallint := 0; lvl text; is_guest boolean; is_admin boolean; in_space boolean;
BEGIN
    IF p_member_id IS NULL THEN RETURN 'none'; END IF;
    SELECT * INTO p FROM pages WHERE id = p_page_id;
    IF NOT FOUND THEN RETURN 'none'; END IF;
    IF NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL) THEN RETURN 'none'; END IF;
    is_guest := sp_member_is_guest(p_member_id);
    is_admin := (p_member_id = app_current_member_id()) AND sp_is_admin();

    IF p.space_id IS NULL AND p.owner_member_id = p_member_id THEN RETURN 'full'; END IF;
    IF p.space_id IS NOT NULL AND (is_admin OR sp_is_space_owner(p.space_id, p_member_id)) THEN RETURN 'full'; END IF;

    in_space := p.space_id IS NOT NULL AND p.space_id IN (SELECT sp_member_space_ids(p_member_id));

    IF p.permission_root_id IS NULL THEN
        IF p.space_id IS NULL THEN RETURN 'none'; END IF;            -- someone else's private page, unshared
        RETURN sp_space_level(p.space_id, p_member_id);
    END IF;

    SELECT max(sp_level_rank(pp.level)) INTO best
      FROM page_permissions pp
     WHERE pp.page_id = p.permission_root_id
       AND ((pp.principal_kind IN ('member', 'agent', 'guest') AND pp.principal_id = p_member_id)
            OR (pp.principal_kind = 'department' AND NOT is_guest AND pp.principal_id IN
                   (SELECT dm.department_id FROM department_members dm WHERE dm.member_id = p_member_id AND dm.left_at IS NULL))
            OR (pp.principal_kind = 'everyone_in_space' AND in_space)
            OR (pp.principal_kind = 'everyone' AND NOT is_guest));
    best := COALESCE(best, 0);
    RETURN (sp_permission_levels())[best + 1];
END$$;

CREATE OR REPLACE FUNCTION sp_can_see_page(p_page_id uuid) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT sp_level_rank(sp_page_level(p_page_id)) >= 1 $$;
CREATE OR REPLACE FUNCTION sp_can_comment_page(p_page_id uuid) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT sp_level_rank(sp_page_level(p_page_id)) >= 2 $$;
CREATE OR REPLACE FUNCTION sp_can_edit_content(p_page_id uuid) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT sp_level_rank(sp_page_level(p_page_id)) >= 3 $$;
CREATE OR REPLACE FUNCTION sp_can_edit_page(p_page_id uuid) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT sp_level_rank(sp_page_level(p_page_id)) >= 4 $$;
CREATE OR REPLACE FUNCTION sp_has_full_page(p_page_id uuid) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT sp_level_rank(sp_page_level(p_page_id)) >= 5 $$;

-- The caller's visible pages, in ONE query. The trash is included (a trashed page is still the viewer's to restore);
-- callers filter archived_at themselves.
CREATE OR REPLACE FUNCTION sp_visible_page_ids() RETURNS SETOF uuid
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); is_guest boolean; is_admin boolean;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    is_guest := sp_is_guest(); is_admin := sp_is_admin();
    RETURN QUERY
    WITH my_spaces AS (SELECT sp_member_space_ids(me) AS id),
         my_depts  AS (SELECT dm.department_id AS id FROM department_members dm WHERE dm.member_id = me AND dm.left_at IS NULL AND NOT is_guest),
         owned     AS (SELECT sm.space_id AS id FROM space_members sm WHERE sm.member_id = me AND sm.role = 'owner'),
         open_sp   AS (SELECT s.id FROM spaces s WHERE s.kind = 'open' AND s.everyone_level <> 'none' AND NOT is_guest)
    SELECT p.id FROM pages p
     WHERE (p.space_id IS NULL AND p.owner_member_id = me)
        OR (p.space_id IS NOT NULL AND (is_admin OR p.space_id IN (SELECT id FROM owned)))
        OR (p.space_id IS NOT NULL AND p.permission_root_id IS NULL
            AND (p.space_id IN (SELECT id FROM my_spaces) OR p.space_id IN (SELECT id FROM open_sp)))
        OR (p.permission_root_id IS NOT NULL AND EXISTS (
               SELECT 1 FROM page_permissions pp
                WHERE pp.page_id = p.permission_root_id AND pp.level <> 'none'
                  AND ((pp.principal_kind IN ('member', 'agent', 'guest') AND pp.principal_id = me)
                       OR (pp.principal_kind = 'department' AND pp.principal_id IN (SELECT id FROM my_depts))
                       OR (pp.principal_kind = 'everyone_in_space' AND p.space_id IN (SELECT id FROM my_spaces))
                       OR (pp.principal_kind = 'everyone' AND NOT is_guest))));
END$$;

-- The members the caller may see at all (the people picker, @mentions, the directory): a member sees every admitted member
-- and agent; a guest sees only the people present in what is shared with them (the owners and editors of those pages, the
-- members of those channels — db/010 extends this) plus themselves.
CREATE OR REPLACE FUNCTION sp_visible_member_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    IF NOT sp_is_guest() THEN
        RETURN QUERY SELECT m.id FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL;
        RETURN;
    END IF;
    RETURN QUERY
        SELECT me
        UNION
        SELECT p.owner_member_id FROM pages p WHERE p.id IN (SELECT sp_visible_page_ids()) AND p.owner_member_id IS NOT NULL
        UNION
        SELECT p.last_edited_by FROM pages p WHERE p.id IN (SELECT sp_visible_page_ids()) AND p.last_edited_by IS NOT NULL
        UNION
        SELECT pp.principal_id FROM page_permissions pp WHERE pp.principal_kind IN ('member', 'agent')
           AND pp.page_id IN (SELECT permission_root_id FROM pages WHERE id IN (SELECT sp_visible_page_ids()));
END$$;

-- ---------------------------------------------------------------------------------------------
-- The referee on pages.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_pages_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE par pages%ROWTYPE; sp spaces%ROWTYPE;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.created_by IS NULL THEN NEW.created_by := app_current_member_id(); END IF;
        IF NEW.last_edited_by IS NULL THEN NEW.last_edited_by := NEW.created_by; END IF;
        IF NEW.owner_member_id IS NULL THEN NEW.owner_member_id := NEW.created_by; END IF;
    END IF;
    -- the parent is checked when the page is born or re-parented or changes space; a bookkeeping update (permission roots,
    -- a title) while a subtree is mid-move must not trip it
    IF NEW.parent_page_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.parent_page_id IS DISTINCT FROM OLD.parent_page_id OR NEW.space_id IS DISTINCT FROM OLD.space_id OR NEW.owner_member_id IS DISTINCT FROM OLD.owner_member_id) THEN
        IF NEW.parent_page_id = NEW.id THEN RAISE EXCEPTION 'A page cannot be its own parent' USING ERRCODE = 'P0001'; END IF;
        SELECT * INTO par FROM pages WHERE id = NEW.parent_page_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'The parent page does not exist' USING ERRCODE = 'P0001'; END IF;
        IF par.space_id IS DISTINCT FROM NEW.space_id THEN
            RAISE EXCEPTION 'A page lives in its parent''s space (move the page instead)' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.space_id IS NULL AND par.owner_member_id <> NEW.owner_member_id THEN
            RAISE EXCEPTION 'A private page''s subpages belong to the same owner' USING ERRCODE = 'P0001';
        END IF;
        -- no cycles
        IF TG_OP = 'UPDATE' AND NEW.parent_page_id IS DISTINCT FROM OLD.parent_page_id AND EXISTS (
               WITH RECURSIVE up AS (SELECT id, parent_page_id FROM pages WHERE id = NEW.parent_page_id
                                     UNION ALL SELECT p.id, p.parent_page_id FROM pages p JOIN up ON p.id = up.parent_page_id)
               SELECT 1 FROM up WHERE id = NEW.id) THEN
            RAISE EXCEPTION 'A page cannot be moved under its own subpage' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    IF NEW.space_id IS NOT NULL THEN
        SELECT * INTO sp FROM spaces WHERE id = NEW.space_id;
        IF sp.archived_at IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.archived_at IS DISTINCT FROM OLD.archived_at OR NEW.title IS DISTINCT FROM OLD.title) THEN
            RAISE EXCEPTION 'Space "%" is archived: nothing changes in it', sp.name USING ERRCODE = 'P0001';
        END IF;
        IF sp_member_is_guest(NEW.owner_member_id) AND NEW.parent_page_id IS NULL AND TG_OP = 'INSERT' THEN
            RAISE EXCEPTION 'A guest never owns a space page' USING ERRCODE = 'P0001';
        END IF;
        -- the wiki: an owner and a verification window from the first write (D12)
        IF sp.is_wiki AND NEW.wiki_owner_member_id IS NULL THEN NEW.wiki_owner_member_id := NEW.created_by; END IF;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF OLD.is_locked AND NEW.is_locked AND (NEW.title, NEW.icon, NEW.properties, NEW.cover_attachment_id) IS DISTINCT FROM (OLD.title, OLD.icon, OLD.properties, OLD.cover_attachment_id) THEN
            RAISE EXCEPTION 'Page "%" is locked: unlock it to change it', OLD.plain_title USING ERRCODE = 'P0001';
        END IF;
        IF OLD.archived_at IS NOT NULL AND NEW.archived_at IS NOT NULL AND (NEW.title, NEW.properties, NEW.parent_page_id) IS DISTINCT FROM (OLD.title, OLD.properties, OLD.parent_page_id) THEN
            RAISE EXCEPTION 'Page "%" is in the trash: restore it first', OLD.plain_title USING ERRCODE = 'P0001';
        END IF;
        IF (NEW.title, NEW.icon, NEW.properties, NEW.cover_attachment_id) IS DISTINCT FROM (OLD.title, OLD.icon, OLD.properties, OLD.cover_attachment_id) THEN
            NEW.content_rev := OLD.content_rev + 1;
            NEW.last_edited_at := now();
            NEW.last_edited_by := COALESCE(app_current_member_id(), OLD.last_edited_by);
            IF OLD.verification_state = 'verified' AND NEW.verification_state = 'verified' AND NEW.title IS DISTINCT FROM OLD.title THEN
                NULL;   -- a title edit keeps the verification; a content edit (db/008) keeps it too — expiry is by date (D12: a badge, not a wall)
            END IF;
        END IF;
        IF NEW.verification_state = 'verified' AND (OLD.verification_state <> 'verified' OR NEW.verified_at IS DISTINCT FROM OLD.verified_at) THEN
            NEW.verified_at := COALESCE(NEW.verified_at, now());
            NEW.verified_by := COALESCE(NEW.verified_by, app_current_member_id());
            IF NEW.verify_until IS NULL THEN
                NEW.verify_until := NEW.verified_at + make_interval(months => COALESCE(sp.wiki_default_verify_months, (SELECT wiki_default_verify_months FROM sp_settings WHERE id = 1)));
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER pages_guard BEFORE INSERT OR UPDATE ON pages FOR EACH ROW EXECUTE FUNCTION sp_pages_guard();

-- After a move, the subtree's permission roots follow; after an insert, the page takes its parent's root.
CREATE OR REPLACE FUNCTION sp_pages_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        UPDATE pages SET permission_root_id = sp_compute_permission_root(NEW.id) WHERE id = NEW.id;
    ELSIF NEW.parent_page_id IS DISTINCT FROM OLD.parent_page_id THEN
        PERFORM sp_recompute_permission_roots(NEW.id);
    END IF;
    -- the trash cascades to the subtree with the same stamp; a restore brings back what went with it
    IF TG_OP = 'UPDATE' AND NEW.archived_at IS NOT NULL AND OLD.archived_at IS NULL THEN
        UPDATE pages c SET archived_at = NEW.archived_at, archived_by = NEW.archived_by, archived_via = NEW.id
          FROM (WITH RECURSIVE sub AS (SELECT id FROM pages WHERE parent_page_id = NEW.id AND archived_at IS NULL
                                       UNION ALL SELECT p.id FROM pages p JOIN sub ON p.parent_page_id = sub.id WHERE p.archived_at IS NULL)
                SELECT id FROM sub) s
         WHERE c.id = s.id;
        UPDATE page_publications SET revoked_at = now(), revoked_by = NEW.archived_by WHERE page_id = NEW.id AND revoked_at IS NULL;
    ELSIF TG_OP = 'UPDATE' AND NEW.archived_at IS NULL AND OLD.archived_at IS NOT NULL THEN
        UPDATE pages SET archived_at = NULL, archived_by = NULL, archived_via = NULL WHERE archived_via = NEW.id;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER pages_after AFTER INSERT OR UPDATE OF parent_page_id, archived_at ON pages FOR EACH ROW EXECUTE FUNCTION sp_pages_after();

-- Restoring a page whose parent is still in the trash puts it at the root of its space (Notion's behaviour).
CREATE OR REPLACE FUNCTION sp_page_restore(p_page_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; par pages%ROWTYPE;
BEGIN
    SELECT * INTO p FROM pages WHERE id = p_page_id;
    IF NOT FOUND OR p.archived_at IS NULL THEN RAISE EXCEPTION 'The page is not in the trash' USING ERRCODE = 'P0001'; END IF;
    IF p.parent_page_id IS NOT NULL THEN
        SELECT * INTO par FROM pages WHERE id = p.parent_page_id;
        IF par.archived_at IS NOT NULL THEN
            UPDATE pages SET parent_page_id = NULL, parent_database_id = NULL, archived_at = NULL, archived_by = NULL, archived_via = NULL WHERE id = p_page_id;
            RETURN;
        END IF;
    END IF;
    UPDATE pages SET archived_at = NULL, archived_by = NULL, archived_via = NULL WHERE id = p_page_id;
END$$;

-- The public door: the only way a request with no session reads a page (PHP calls it as the writer, no acting member).
CREATE OR REPLACE FUNCTION sp_public_page_lookup(p_token_hash text)
    RETURNS TABLE (publication_id bigint, page_id uuid, include_subpages boolean, noindex boolean, public_properties text[], layout text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT pb.id, pb.page_id, pb.include_subpages, pb.noindex, pb.public_properties, pb.layout
      FROM page_publications pb JOIN pages p ON p.id = pb.page_id
     WHERE pb.token_hash = p_token_hash AND pb.revoked_at IS NULL AND p.archived_at IS NULL;
$$;
-- The published subtree (when include_subpages): the page and every descendant not in the trash.
CREATE OR REPLACE FUNCTION sp_public_page_ids(p_publication_id bigint) RETURNS SETOF uuid
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE pb page_publications%ROWTYPE;
BEGIN
    SELECT * INTO pb FROM page_publications WHERE id = p_publication_id AND revoked_at IS NULL;
    IF NOT FOUND THEN RETURN; END IF;
    IF NOT pb.include_subpages THEN RETURN QUERY SELECT pb.page_id; RETURN; END IF;
    RETURN QUERY
        WITH RECURSIVE sub AS (SELECT id FROM pages WHERE id = pb.page_id AND archived_at IS NULL
                               UNION ALL SELECT p.id FROM pages p JOIN sub ON p.parent_page_id = sub.id WHERE p.archived_at IS NULL)
        SELECT id FROM sub;
END$$;

-- The breadcrumb: a page's ancestors, root first.
CREATE OR REPLACE FUNCTION sp_page_ancestors(p_page_id uuid)
    RETURNS TABLE (page_id uuid, plain_title text, depth integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH RECURSIVE up AS (
        SELECT p.parent_page_id AS id, 1 AS depth FROM pages p WHERE p.id = p_page_id
        UNION ALL
        SELECT p.parent_page_id, up.depth + 1 FROM pages p JOIN up ON p.id = up.id WHERE up.id IS NOT NULL AND up.depth < 200)
    SELECT p.id, p.plain_title, up.depth FROM up JOIN pages p ON p.id = up.id ORDER BY up.depth DESC;
$$;

-- A recent visit (the last 50 kept per member).
CREATE OR REPLACE FUNCTION sp_page_visited(p_page_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL THEN RETURN; END IF;
    INSERT INTO page_recents (member_id, page_id) VALUES (me, p_page_id)
    ON CONFLICT (member_id, page_id) DO UPDATE SET visited_at = now();
    DELETE FROM page_recents r WHERE r.member_id = me AND r.page_id IN (
        SELECT page_id FROM page_recents WHERE member_id = me ORDER BY visited_at DESC OFFSET 50);
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON pages, page_permissions, page_links, page_favorites, page_recents, page_publications TO spaces_rw;
GRANT SELECT, INSERT, DELETE ON page_versions TO spaces_rw;
GRANT EXECUTE ON FUNCTION sp_position_between(text, text), sp_compute_permission_root(uuid), sp_page_level(uuid, bigint), sp_can_see_page(uuid),
    sp_can_comment_page(uuid), sp_can_edit_content(uuid), sp_can_edit_page(uuid), sp_has_full_page(uuid), sp_visible_page_ids(),
    sp_visible_member_ids(), sp_page_ancestors(uuid), sp_public_page_ids(bigint)
    TO spaces_rw, spaces_records_ro, spaces_activity_ro;
REVOKE ALL ON FUNCTION sp_recompute_permission_roots(uuid), sp_page_restore(uuid), sp_public_page_lookup(text), sp_page_visited(uuid), sp_page_restrict(uuid), sp_page_unrestrict(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sp_recompute_permission_roots(uuid), sp_page_restore(uuid), sp_public_page_lookup(text), sp_page_visited(uuid), sp_page_restrict(uuid), sp_page_unrestrict(uuid) TO spaces_rw;

COMMIT;
