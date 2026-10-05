-- 008: BLOCKS — a page's content, Notion's block model verbatim (design §0.3, §6; D6, D7).
--
-- A block belongs to one page and, below the root, one parent block of that page. Its `content` is the type's object exactly
-- as Notion's API shapes it (`rich_text`, `checked`, `language`, `caption`, `icon`, `color`, `is_toggleable`, `url`,
-- `expression`, `has_column_header`, `synced_from`, `page_id` …), so an export is a transcription. Order is a fractional
-- position among siblings. Saves are OPTIMISTIC: an UPDATE carries the `version` the editor read; a mismatch is refused
-- ('stale') and the editor reloads the block (D7). A locked page and a trashed page refuse every block write. The tree's
-- edges to child pages (`child_page`, `child_database`) and the backlinks (page_links) are kept in step by trigger.
--
-- THE ESTATE'S DATA MODEL (design §6.1): new; canonical for blocks.
BEGIN;

-- The plain text of a block for search and excerpts: its rich text, its caption, a table row's cells, a child page's title.
CREATE OR REPLACE FUNCTION sp_block_plain_text(p_type text, p_content jsonb) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE
        WHEN p_type IN ('paragraph','heading_1','heading_2','heading_3','bulleted_list_item','numbered_list_item','to_do','toggle','quote','callout','code','template')
            THEN sp_rich_text_plain(p_content->'rich_text')
        WHEN p_type IN ('image','video','audio','file','pdf','bookmark','embed','link_preview')
            THEN trim(both ' ' from COALESCE(sp_rich_text_plain(p_content->'caption'), '') || ' ' || COALESCE(p_content->>'url', p_content->'external'->>'url', p_content->>'name', ''))
        WHEN p_type = 'equation' THEN COALESCE(p_content->>'expression', '')
        WHEN p_type = 'table_row'
            THEN (SELECT COALESCE(string_agg(sp_rich_text_plain(c), E'\t' ORDER BY ord), '') FROM jsonb_array_elements(COALESCE(p_content->'cells', '[]'::jsonb)) WITH ORDINALITY AS t(c, ord))
        WHEN p_type IN ('child_page','child_database') THEN COALESCE(p_content->>'title', '')
        ELSE ''
    END;
$$;

CREATE TABLE blocks (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    page_id          uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    parent_block_id  uuid REFERENCES blocks(id) ON DELETE CASCADE,                      -- NULL = a root block of the page
    type             text NOT NULL CHECK (type = ANY (sp_block_types())),
    position         text NOT NULL DEFAULT 'a0',
    content          jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(content) = 'object'),
    plain_text       text GENERATED ALWAYS AS (sp_block_plain_text(type, content)) STORED,
    has_children     boolean NOT NULL DEFAULT false,
    version          integer NOT NULL DEFAULT 1,
    synced_from      uuid REFERENCES blocks(id) ON DELETE SET NULL,                     -- a synced_block copy points at its original
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    last_edited_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    last_edited_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (synced_from IS NULL OR type = 'synced_block'),
    CHECK (type <> 'code' OR (content->>'language') IS NOT NULL),
    CHECK (type NOT IN ('paragraph','heading_1','heading_2','heading_3','bulleted_list_item','numbered_list_item','to_do','toggle','quote','callout','code')
           OR sp_is_rich_text(COALESCE(content->'rich_text', '[]'::jsonb))),
    CHECK (type <> 'to_do' OR jsonb_typeof(COALESCE(content->'checked', 'false'::jsonb)) = 'boolean'),
    CHECK (type NOT IN ('link_to_page','child_page','child_database') OR (content->>'page_id') IS NOT NULL OR (content->>'database_id') IS NOT NULL),
    CHECK (type <> 'table_row' OR jsonb_typeof(COALESCE(content->'cells', '[]'::jsonb)) = 'array'),
    CHECK ((content->>'color') IS NULL OR (content->>'color') = ANY (sp_text_colors()))
);
CREATE INDEX blocks_page_parent_idx ON blocks (page_id, parent_block_id, position);
CREATE INDEX blocks_parent_idx      ON blocks (parent_block_id);
CREATE INDEX blocks_synced_idx      ON blocks (synced_from) WHERE synced_from IS NOT NULL;
CREATE INDEX blocks_type_idx        ON blocks (type) WHERE type IN ('child_page', 'child_database', 'link_to_page');
CREATE INDEX blocks_text_trgm_idx   ON blocks USING gin (plain_text gin_trgm_ops);

-- ---------------------------------------------------------------------------------------------
-- The referee on blocks.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_blocks_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pg pages%ROWTYPE; par blocks%ROWTYPE; orig blocks%ROWTYPE; me bigint := app_current_member_id();
BEGIN
    -- a purge (sp_page_purge, a cascade from a deleted page) and a move's edge-block swap bypass the content rules
    IF current_setting('app.sp_purging', true) = '1' THEN RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END; END IF;
    SELECT * INTO pg FROM pages WHERE id = COALESCE(NEW.page_id, OLD.page_id);
    IF NOT FOUND THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;      -- the page is going (a cascade)
        RAISE EXCEPTION 'The page does not exist' USING ERRCODE = 'P0001';
    END IF;
    IF pg.is_locked AND current_setting('app.sp_unlocked', true) IS DISTINCT FROM '1' THEN
        RAISE EXCEPTION 'Page "%" is locked: unlock it to edit', pg.plain_title USING ERRCODE = 'P0001';
    END IF;
    IF pg.archived_at IS NOT NULL AND current_setting('app.sp_purging', true) IS DISTINCT FROM '1' THEN
        RAISE EXCEPTION 'Page "%" is in the trash: restore it to edit', pg.plain_title USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF OLD.type IN ('child_page', 'child_database') AND current_setting('app.sp_purging', true) IS DISTINCT FROM '1'
           AND EXISTS (SELECT 1 FROM pages c WHERE c.id = COALESCE((OLD.content->>'page_id')::uuid, (OLD.content->>'database_id')::uuid) AND c.archived_at IS NULL) THEN
            RAISE EXCEPTION 'Move or trash the subpage first; its block goes with it' USING ERRCODE = 'P0001';
        END IF;
        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.page_id <> OLD.page_id THEN RAISE EXCEPTION 'A block stays on its page (duplicate it to another)' USING ERRCODE = 'P0001'; END IF;
        IF NEW.type <> OLD.type AND (OLD.type IN ('child_page','child_database','table','table_row','column_list','column','synced_block') OR NEW.type IN ('child_page','child_database','table','table_row','column_list','column','synced_block')) THEN
            RAISE EXCEPTION 'A % block cannot be turned into a % block', OLD.type, NEW.type USING ERRCODE = 'P0001';
        END IF;
        -- optimistic save: the writer sends the version it read; a mismatch is stale (D7)
        IF NEW.version <> OLD.version THEN
            RAISE EXCEPTION 'stale: block % is at version %, you edited version %', OLD.id, OLD.version, NEW.version USING ERRCODE = 'P0001';
        END IF;
        IF (NEW.content, NEW.type) IS DISTINCT FROM (OLD.content, OLD.type) THEN
            NEW.version := OLD.version + 1;
            NEW.last_edited_at := now();
            NEW.last_edited_by := COALESCE(me, OLD.last_edited_by);
        END IF;
    ELSE
        IF NEW.created_by IS NULL THEN NEW.created_by := me; END IF;
        IF NEW.last_edited_by IS NULL THEN NEW.last_edited_by := NEW.created_by; END IF;
        NEW.version := 1;
    END IF;

    -- the parent: same page, a type that holds children, the structural pairs
    IF NEW.parent_block_id IS NOT NULL THEN
        IF NEW.parent_block_id = NEW.id THEN RAISE EXCEPTION 'A block cannot be its own parent' USING ERRCODE = 'P0001'; END IF;
        SELECT * INTO par FROM blocks WHERE id = NEW.parent_block_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'The parent block does not exist' USING ERRCODE = 'P0001'; END IF;
        IF par.page_id <> NEW.page_id THEN RAISE EXCEPTION 'A block''s parent is on the same page' USING ERRCODE = 'P0001'; END IF;
        IF NOT (par.type = ANY (sp_block_types_with_children())) THEN
            RAISE EXCEPTION 'A % block holds no children', par.type USING ERRCODE = 'P0001';
        END IF;
        IF par.type IN ('heading_1','heading_2','heading_3') AND NOT COALESCE((par.content->>'is_toggleable')::boolean, false) THEN
            RAISE EXCEPTION 'Only a toggleable heading holds children' USING ERRCODE = 'P0001';
        END IF;
        IF par.type = 'table' AND NEW.type <> 'table_row' THEN RAISE EXCEPTION 'A table holds only table rows' USING ERRCODE = 'P0001'; END IF;
        IF par.type = 'column_list' AND NEW.type <> 'column' THEN RAISE EXCEPTION 'A column list holds only columns' USING ERRCODE = 'P0001'; END IF;
        IF TG_OP = 'UPDATE' AND NEW.parent_block_id IS DISTINCT FROM OLD.parent_block_id AND EXISTS (
               WITH RECURSIVE up AS (SELECT id, parent_block_id FROM blocks WHERE id = NEW.parent_block_id
                                     UNION ALL SELECT b.id, b.parent_block_id FROM blocks b JOIN up ON b.id = up.parent_block_id)
               SELECT 1 FROM up WHERE id = NEW.id) THEN
            RAISE EXCEPTION 'A block cannot be moved under its own child' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    IF NEW.type = 'table_row' AND (NEW.parent_block_id IS NULL OR par.type <> 'table') THEN
        RAISE EXCEPTION 'A table row lives under a table' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.type = 'column' AND (NEW.parent_block_id IS NULL OR par.type <> 'column_list') THEN
        RAISE EXCEPTION 'A column lives under a column list' USING ERRCODE = 'P0001';
    END IF;
    -- a synced copy points at an original (an original has synced_from NULL)
    IF NEW.synced_from IS NOT NULL THEN
        SELECT * INTO orig FROM blocks WHERE id = NEW.synced_from;
        IF NOT FOUND OR orig.type <> 'synced_block' OR orig.synced_from IS NOT NULL THEN
            RAISE EXCEPTION 'A synced block copies an original synced block' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.synced_from = NEW.id THEN RAISE EXCEPTION 'A synced block cannot copy itself' USING ERRCODE = 'P0001'; END IF;
    END IF;
    IF NEW.type = 'synced_block' THEN
        NEW.content := NEW.content || jsonb_build_object('synced_from', CASE WHEN NEW.synced_from IS NULL THEN NULL ELSE jsonb_build_object('block_id', NEW.synced_from) END);
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER blocks_guard BEFORE INSERT OR UPDATE OR DELETE ON blocks FOR EACH ROW EXECUTE FUNCTION sp_blocks_guard();

-- After a block changes: has_children on the parents, the page's content_rev and last edit, the child-page edge, the links.
CREATE OR REPLACE FUNCTION sp_refresh_page_links(p_block_id uuid, p_page_id uuid, p_type text, p_content jsonb) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE rt jsonb;
BEGIN
    DELETE FROM page_links WHERE from_block_id = p_block_id;
    IF p_type = 'link_to_page' THEN
        INSERT INTO page_links (from_page_id, from_block_id, to_page_id, kind)
        VALUES (p_page_id, p_block_id, COALESCE((p_content->>'page_id')::uuid, (p_content->>'database_id')::uuid), 'link_to_page');
    ELSIF p_type IN ('child_page', 'child_database') THEN
        INSERT INTO page_links (from_page_id, from_block_id, to_page_id, kind)
        VALUES (p_page_id, p_block_id, COALESCE((p_content->>'page_id')::uuid, (p_content->>'database_id')::uuid), p_type);
    END IF;
    rt := CASE WHEN p_type = 'table_row' THEN (SELECT COALESCE(jsonb_agg(x), '[]'::jsonb) FROM jsonb_array_elements(COALESCE(p_content->'cells', '[]'::jsonb)) c, jsonb_array_elements(c) x)
               ELSE COALESCE(p_content->'rich_text', p_content->'caption', '[]'::jsonb) END;
    INSERT INTO page_links (from_page_id, from_block_id, to_page_id, kind)
    SELECT DISTINCT p_page_id, p_block_id, (r->'mention'->>'id')::uuid, 'mention'
      FROM jsonb_array_elements(CASE WHEN jsonb_typeof(rt) = 'array' THEN rt ELSE '[]'::jsonb END) r
     WHERE r->>'type' = 'mention' AND r->'mention'->>'type' IN ('page', 'database')
       AND (r->'mention'->>'id') ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';
END$$;

CREATE OR REPLACE FUNCTION sp_blocks_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pid uuid := COALESCE(NEW.page_id, OLD.page_id); child uuid;
BEGIN
    -- has_children on the old and new parents
    IF TG_OP IN ('UPDATE', 'DELETE') AND OLD.parent_block_id IS NOT NULL THEN
        UPDATE blocks SET has_children = EXISTS (SELECT 1 FROM blocks c WHERE c.parent_block_id = OLD.parent_block_id) WHERE id = OLD.parent_block_id;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') AND NEW.parent_block_id IS NOT NULL THEN
        UPDATE blocks SET has_children = true WHERE id = NEW.parent_block_id AND NOT has_children;
    END IF;
    -- the page moved on
    IF current_setting('app.sp_purging', true) IS DISTINCT FROM '1' THEN
        UPDATE pages SET content_rev = content_rev + 1, last_edited_at = now(),
               last_edited_by = COALESCE(app_current_member_id(), CASE WHEN TG_OP = 'DELETE' THEN OLD.last_edited_by ELSE NEW.last_edited_by END)
         WHERE id = pid;
    END IF;
    -- the child-page edge: a child_page block says where the page sits
    IF TG_OP IN ('INSERT', 'UPDATE') AND NEW.type IN ('child_page', 'child_database') THEN
        child := COALESCE((NEW.content->>'page_id')::uuid, (NEW.content->>'database_id')::uuid);
        UPDATE pages SET parent_page_id = NEW.page_id, position = NEW.position
         WHERE id = child AND (parent_page_id IS DISTINCT FROM NEW.page_id OR position IS DISTINCT FROM NEW.position) AND archived_at IS NULL;
    END IF;
    -- links
    IF TG_OP = 'DELETE' THEN
        DELETE FROM page_links WHERE from_block_id = OLD.id;
    ELSE
        PERFORM sp_refresh_page_links(NEW.id, NEW.page_id, NEW.type, NEW.content);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER blocks_after AFTER INSERT OR UPDATE OR DELETE ON blocks FOR EACH ROW EXECUTE FUNCTION sp_blocks_after();

-- ---------------------------------------------------------------------------------------------
-- Writers the handlers and the converter call (the database decides; PHP shows the sentence).
-- ---------------------------------------------------------------------------------------------
-- Create a page in a space or privately, with its child_page block under the parent (the tree's edge). Returns the page id.
CREATE OR REPLACE FUNCTION sp_page_create(p_space_id bigint, p_parent_page_id uuid, p_title jsonb, p_kind text DEFAULT 'page',
                                          p_icon text DEFAULT NULL, p_after_block uuid DEFAULT NULL, p_id uuid DEFAULT NULL) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pid uuid := COALESCE(p_id, gen_random_uuid()); pos text; prev text; nxt text; me bigint := app_current_member_id();
BEGIN
    IF p_parent_page_id IS NOT NULL THEN
        -- position among the parent's root blocks: after p_after_block, else at the end
        IF p_after_block IS NOT NULL THEN
            SELECT b.position INTO prev FROM blocks b WHERE b.id = p_after_block AND b.page_id = p_parent_page_id;
            SELECT min(b.position) INTO nxt FROM blocks b WHERE b.page_id = p_parent_page_id AND b.parent_block_id IS NULL AND b.position > prev;
        ELSE
            SELECT max(b.position) INTO prev FROM blocks b WHERE b.page_id = p_parent_page_id AND b.parent_block_id IS NULL;
        END IF;
        pos := sp_position_between(prev, nxt);
    ELSE
        SELECT max(p.position) INTO prev FROM pages p WHERE p.space_id IS NOT DISTINCT FROM p_space_id AND p.parent_page_id IS NULL
           AND (p_space_id IS NOT NULL OR p.owner_member_id = me) AND p.archived_at IS NULL;
        pos := sp_position_between(prev, NULL);
    END IF;
    INSERT INTO pages (id, space_id, parent_page_id, kind, title, icon, position, owner_member_id, created_by)
    VALUES (pid, p_space_id, p_parent_page_id, p_kind, COALESCE(p_title, '[]'::jsonb), p_icon, pos, me, me);
    IF p_parent_page_id IS NOT NULL THEN
        INSERT INTO blocks (page_id, parent_block_id, type, position, content, created_by)
        VALUES (p_parent_page_id, NULL, CASE WHEN p_kind = 'database' THEN 'child_database' ELSE 'child_page' END, pos,
                CASE WHEN p_kind = 'database' THEN jsonb_build_object('database_id', pid, 'title', sp_rich_text_plain(p_title))
                     ELSE jsonb_build_object('page_id', pid, 'title', sp_rich_text_plain(p_title)) END, me);
    END IF;
    RETURN pid;
END$$;

-- Move a page under another parent (or to a space's root): the old edge block goes, a new one is made, the roots recomputed by trigger.
CREATE OR REPLACE FUNCTION sp_page_move(p_page_id uuid, p_new_parent uuid, p_new_space bigint DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; target_space bigint; pos text; prev text; me bigint := app_current_member_id();
BEGIN
    SELECT * INTO p FROM pages WHERE id = p_page_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'The page does not exist' USING ERRCODE = 'P0001'; END IF;
    IF p.parent_database_id IS NOT NULL THEN RAISE EXCEPTION 'A database row stays in its database' USING ERRCODE = 'P0001'; END IF;
    IF p_new_parent IS NOT NULL THEN
        SELECT space_id INTO target_space FROM pages WHERE id = p_new_parent;
    ELSE
        target_space := COALESCE(p_new_space, p.space_id);
    END IF;
    PERFORM set_config('app.sp_purging', '1', true);     -- the edge block of a moved page may go even if the page is live
    DELETE FROM blocks b WHERE b.type IN ('child_page', 'child_database') AND COALESCE((b.content->>'page_id')::uuid, (b.content->>'database_id')::uuid) = p_page_id;
    PERFORM set_config('app.sp_purging', '', true);
    -- detach the page and move it first (no parent, so the guard has nothing to compare), THEN its subtree follows into the
    -- space (each child's parent is already there), then the page is attached under its new parent.
    UPDATE pages SET space_id = target_space, parent_page_id = NULL WHERE id = p_page_id;
    WITH RECURSIVE sub AS (SELECT id FROM pages WHERE parent_page_id = p_page_id UNION ALL SELECT c.id FROM pages c JOIN sub ON c.parent_page_id = sub.id)
    UPDATE pages x SET space_id = target_space FROM sub WHERE x.id = sub.id AND x.space_id IS DISTINCT FROM target_space;
    IF p_new_parent IS NOT NULL THEN
        SELECT max(b.position) INTO prev FROM blocks b WHERE b.page_id = p_new_parent AND b.parent_block_id IS NULL;
        pos := sp_position_between(prev, NULL);
        UPDATE pages SET parent_page_id = p_new_parent, position = pos WHERE id = p_page_id;
        INSERT INTO blocks (page_id, parent_block_id, type, position, content, created_by)
        VALUES (p_new_parent, NULL, CASE WHEN p.kind = 'database' THEN 'child_database' ELSE 'child_page' END, pos,
                CASE WHEN p.kind = 'database' THEN jsonb_build_object('database_id', p_page_id, 'title', p.plain_title)
                     ELSE jsonb_build_object('page_id', p_page_id, 'title', p.plain_title) END, me);
    ELSE
        SELECT max(x.position) INTO prev FROM pages x WHERE x.space_id IS NOT DISTINCT FROM target_space AND x.parent_page_id IS NULL AND x.archived_at IS NULL AND x.id <> p_page_id;
        UPDATE pages SET position = sp_position_between(prev, NULL) WHERE id = p_page_id;
    END IF;
    PERFORM sp_recompute_permission_roots(p_page_id);
END$$;

-- Trash a page: the subtree follows (db/007's trigger); its edge block in the parent goes.
CREATE OR REPLACE FUNCTION sp_page_trash(p_page_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    UPDATE pages SET archived_at = now(), archived_by = me WHERE id = p_page_id AND archived_at IS NULL;
    IF NOT FOUND THEN RAISE EXCEPTION 'The page is already in the trash' USING ERRCODE = 'P0001'; END IF;
    PERFORM set_config('app.sp_purging', '1', true);
    DELETE FROM blocks b WHERE b.type IN ('child_page', 'child_database') AND COALESCE((b.content->>'page_id')::uuid, (b.content->>'database_id')::uuid) = p_page_id;
    PERFORM set_config('app.sp_purging', '', true);
END$$;

-- Purge: delete a trashed page and its subtree for good (the worker after retention, or trash.purge).
CREATE OR REPLACE FUNCTION sp_page_purge(p_page_id uuid) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pages WHERE id = p_page_id AND archived_at IS NOT NULL) THEN
        RAISE EXCEPTION 'Only a page in the trash is purged' USING ERRCODE = 'P0001';
    END IF;
    PERFORM set_config('app.sp_purging', '1', true);
    WITH RECURSIVE sub AS (SELECT id FROM pages WHERE id = p_page_id UNION ALL SELECT c.id FROM pages c JOIN sub ON c.parent_page_id = sub.id),
         del AS (DELETE FROM pages p USING sub WHERE p.id = sub.id RETURNING p.id)
    SELECT count(*) INTO n FROM del;
    PERFORM set_config('app.sp_purging', '', true);
    RETURN n;
END$$;

-- Lock and unlock (the lock takes a snapshot first — the worker's snapshot writer is db/014; here the flag).
CREATE OR REPLACE FUNCTION sp_page_set_locked(p_page_id uuid, p_locked boolean) RETURNS void
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE pages SET is_locked = p_locked WHERE id = p_page_id;
$$;

-- Insert a block after a sibling (or first, or last) — the position is computed here so two editors never collide on it.
CREATE OR REPLACE FUNCTION sp_block_insert(p_page_id uuid, p_parent_block uuid, p_after_block uuid, p_type text, p_content jsonb,
                                           p_id uuid DEFAULT NULL, p_at_start boolean DEFAULT false) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE bid uuid := COALESCE(p_id, gen_random_uuid()); prev text; nxt text;
BEGIN
    IF p_at_start THEN
        SELECT min(b.position) INTO nxt FROM blocks b WHERE b.page_id = p_page_id AND b.parent_block_id IS NOT DISTINCT FROM p_parent_block;
    ELSIF p_after_block IS NOT NULL THEN
        SELECT b.position INTO prev FROM blocks b WHERE b.id = p_after_block AND b.page_id = p_page_id AND b.parent_block_id IS NOT DISTINCT FROM p_parent_block;
        IF prev IS NULL THEN RAISE EXCEPTION 'The block to insert after is not a sibling here' USING ERRCODE = 'P0001'; END IF;
        SELECT min(b.position) INTO nxt FROM blocks b WHERE b.page_id = p_page_id AND b.parent_block_id IS NOT DISTINCT FROM p_parent_block AND b.position > prev;
    ELSE
        SELECT max(b.position) INTO prev FROM blocks b WHERE b.page_id = p_page_id AND b.parent_block_id IS NOT DISTINCT FROM p_parent_block;
    END IF;
    INSERT INTO blocks (id, page_id, parent_block_id, type, position, content)
    VALUES (bid, p_page_id, p_parent_block, p_type, sp_position_between(prev, nxt), COALESCE(p_content, '{}'::jsonb));
    RETURN bid;
END$$;

-- Move a block: a new parent and/or a new place among siblings (after p_after_block; NULL = first).
CREATE OR REPLACE FUNCTION sp_block_move(p_block_id uuid, p_new_parent uuid, p_after_block uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b blocks%ROWTYPE; prev text; nxt text;
BEGIN
    SELECT * INTO b FROM blocks WHERE id = p_block_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'The block does not exist' USING ERRCODE = 'P0001'; END IF;
    IF p_after_block IS NOT NULL THEN
        SELECT x.position INTO prev FROM blocks x WHERE x.id = p_after_block AND x.page_id = b.page_id AND x.parent_block_id IS NOT DISTINCT FROM p_new_parent AND x.id <> p_block_id;
        IF prev IS NULL THEN RAISE EXCEPTION 'The block to place after is not a sibling there' USING ERRCODE = 'P0001'; END IF;
        SELECT min(x.position) INTO nxt FROM blocks x WHERE x.page_id = b.page_id AND x.parent_block_id IS NOT DISTINCT FROM p_new_parent AND x.position > prev AND x.id <> p_block_id;
    ELSE
        SELECT min(x.position) INTO nxt FROM blocks x WHERE x.page_id = b.page_id AND x.parent_block_id IS NOT DISTINCT FROM p_new_parent AND x.id <> p_block_id;
    END IF;
    UPDATE blocks SET parent_block_id = p_new_parent, position = sp_position_between(prev, nxt) WHERE id = p_block_id;
END$$;

-- Duplicate a page's whole tree (templates, "Duplicate"): new ids, same content; synced copies stay copies; child pages are
-- duplicated too (recursively), the new edges pointing at the new pages.
CREATE OR REPLACE FUNCTION sp_page_duplicate(p_page_id uuid, p_space_id bigint, p_parent_page_id uuid, p_title jsonb DEFAULT NULL, p_as_template boolean DEFAULT false) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE src pages%ROWTYPE; nid uuid; r record; idmap jsonb := '{}'::jsonb; newb uuid; newparent uuid; newchild uuid; c jsonb;
BEGIN
    SELECT * INTO src FROM pages WHERE id = p_page_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'The page does not exist' USING ERRCODE = 'P0001'; END IF;
    nid := sp_page_create(p_space_id, p_parent_page_id, COALESCE(p_title, src.title), src.kind, src.icon);
    UPDATE pages SET properties = src.properties, is_template = p_as_template, cover_attachment_id = src.cover_attachment_id WHERE id = nid;
    FOR r IN WITH RECURSIVE t AS (
                 SELECT b.*, 0 AS depth FROM blocks b WHERE b.page_id = p_page_id AND b.parent_block_id IS NULL
                 UNION ALL
                 SELECT b.*, t.depth + 1 FROM blocks b JOIN t ON b.parent_block_id = t.id)
             SELECT * FROM t ORDER BY depth, position
    LOOP
        newb := gen_random_uuid();
        newparent := CASE WHEN r.parent_block_id IS NULL THEN NULL ELSE (idmap->>r.parent_block_id::text)::uuid END;
        c := r.content;
        IF r.type IN ('child_page', 'child_database') THEN
            newchild := sp_page_duplicate(COALESCE((c->>'page_id')::uuid, (c->>'database_id')::uuid), p_space_id, nid, NULL, false);
            -- sp_page_create already made the edge block for the child under nid; skip making a second
            idmap := idmap || jsonb_build_object(r.id::text, (SELECT id FROM blocks WHERE page_id = nid AND type = r.type
                                                               AND COALESCE((content->>'page_id')::uuid, (content->>'database_id')::uuid) = newchild));
            CONTINUE;
        END IF;
        INSERT INTO blocks (id, page_id, parent_block_id, type, position, content, synced_from)
        VALUES (newb, nid, newparent, r.type, r.position, c, COALESCE(r.synced_from, CASE WHEN r.type = 'synced_block' THEN r.id END));
        idmap := idmap || jsonb_build_object(r.id::text, newb);
    END LOOP;
    RETURN nid;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON blocks TO spaces_rw;
GRANT EXECUTE ON FUNCTION sp_block_plain_text(text, jsonb) TO spaces_rw, spaces_records_ro, spaces_activity_ro;
REVOKE ALL ON FUNCTION sp_page_create(bigint, uuid, jsonb, text, text, uuid, uuid), sp_page_move(uuid, uuid, bigint), sp_page_trash(uuid), sp_page_purge(uuid),
    sp_page_set_locked(uuid, boolean), sp_block_insert(uuid, uuid, uuid, text, jsonb, uuid, boolean), sp_block_move(uuid, uuid, uuid),
    sp_page_duplicate(uuid, bigint, uuid, jsonb, boolean), sp_refresh_page_links(uuid, uuid, text, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sp_page_create(bigint, uuid, jsonb, text, text, uuid, uuid), sp_page_move(uuid, uuid, bigint), sp_page_trash(uuid), sp_page_purge(uuid),
    sp_page_set_locked(uuid, boolean), sp_block_insert(uuid, uuid, uuid, text, jsonb, uuid, boolean), sp_block_move(uuid, uuid, uuid),
    sp_page_duplicate(uuid, bigint, uuid, jsonb, boolean) TO spaces_rw;

COMMIT;
