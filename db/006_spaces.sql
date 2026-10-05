-- 006: SPACES (Notion's teamspaces) and who is in them (design §3, §6; D4, D5).
--
-- A space is a place: open (anyone in the business joins; everyone sees it at everyone_level), closed (visible; joined on
-- request, the owner approves), private (invisible but to its members — and to a Spaces admin, logged). `General` is the
-- default: everyone is a member and cannot leave; agents join it on hire (D4). One closed space per standing department is
-- made when the directory delivers the department (a trigger below): its members are the department's — derived, so the
-- directory, not a copy, decides (department_members is the mirror; space_members holds the owners and anyone added by hand).
-- A space may be a wiki (D12): then its pages carry an owner and a verification state (db/007).
--
-- THE ESTATE'S DATA MODEL (design §6.1): new tables — nothing in the estate is close; these become the canonical shape.
BEGIN;

CREATE TABLE spaces (
    id                          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                        text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    slug                        text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9][a-z0-9-]{0,78}$'),
    icon                        text,                                                   -- an emoji, or 'file:<attachment id>'
    description                 text CHECK (description IS NULL OR length(description) <= 2000),
    kind                        text NOT NULL DEFAULT 'closed' CHECK (kind IN ('open', 'closed', 'private')),
    is_default                  boolean NOT NULL DEFAULT false,                        -- General: everyone is in it and cannot leave
    department_id               bigint REFERENCES departments(id) ON DELETE SET NULL,  -- the standing department's space; members derived
    member_level                text NOT NULL DEFAULT 'edit' CHECK (member_level IN ('view', 'comment', 'edit_content', 'edit', 'full')),
    everyone_level              text NOT NULL DEFAULT 'view' CHECK (everyone_level IN ('none', 'view', 'comment', 'edit_content', 'edit')),
    is_wiki                     boolean NOT NULL DEFAULT false,
    wiki_default_verify_months  integer CHECK (wiki_default_verify_months IS NULL OR wiki_default_verify_months IN (1, 3, 6, 12)),
    default_channel_id          bigint,                                                 -- the space's #general (FK added in db/010)
    created_by                  bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_at                 timestamptz,
    archived_by                 bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    CHECK (kind = 'open' OR everyone_level = 'none'),                                   -- D5: only an open space reaches everyone
    CHECK (NOT is_default OR kind = 'open')
);
CREATE UNIQUE INDEX spaces_one_default ON spaces (is_default) WHERE is_default;
CREATE UNIQUE INDEX spaces_one_per_department ON spaces (department_id) WHERE department_id IS NOT NULL;
CREATE INDEX spaces_name_trgm_idx ON spaces USING gin (name gin_trgm_ops);
CREATE TRIGGER spaces_touch BEFORE UPDATE ON spaces FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE space_members (
    space_id      bigint NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    member_id     bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    role          text NOT NULL DEFAULT 'member' CHECK (role IN ('owner', 'member')),
    notify        text NOT NULL DEFAULT 'all' CHECK (notify IN ('all', 'mentions', 'none')),   -- the default for the space's channels
    added_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    joined_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (space_id, member_id)
);
CREATE INDEX space_members_member_idx ON space_members (member_id);

-- A closed space is joined on request; the owner approves (D5).
CREATE TABLE space_join_requests (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    space_id      bigint NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    member_id     bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    message       text CHECK (message IS NULL OR length(message) <= 500),
    status        text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'declined', 'withdrawn')),
    decided_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX space_join_requests_one_pending ON space_join_requests (space_id, member_id) WHERE status = 'pending';

-- The sidebar's ordered groups of root pages within a space (Notion's teamspace sections).
CREATE TABLE space_sections (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    space_id      bigint NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    name          text NOT NULL CHECK (length(name) BETWEEN 1 AND 60),
    position      text NOT NULL DEFAULT 'a0',                                            -- a fractional key, like a block's
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (space_id, name)
);

-- ---------------------------------------------------------------------------------------------
-- Who is in a space, and at what level. ONE PL/pgSQL function each, one query (the kernel's db/160 lesson); the views and
-- the page resolver (db/007) call these as uncorrelated sets tested once per statement.
-- ---------------------------------------------------------------------------------------------
-- The caller is a member of the space: General (everyone but guests), an explicit row, or the department's members for a
-- department space. Archived spaces keep their membership (readable, never writable — the writers check archived_at).
CREATE OR REPLACE FUNCTION sp_member_space_ids(p_member_id bigint DEFAULT app_current_member_id()) RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_member_id IS NULL OR sp_member_is_guest(p_member_id) THEN
        RETURN QUERY SELECT sm.space_id FROM space_members sm WHERE sm.member_id = p_member_id;   -- a guest only where added by hand
        RETURN;
    END IF;
    RETURN QUERY
        SELECT s.id FROM spaces s WHERE s.is_default
        UNION
        SELECT sm.space_id FROM space_members sm WHERE sm.member_id = p_member_id
        UNION
        SELECT s.id FROM spaces s JOIN department_members dm ON dm.department_id = s.department_id
         WHERE dm.member_id = p_member_id AND dm.left_at IS NULL;
END$$;

-- The spaces the caller SEES (not necessarily joins): their own, every open and closed one; private ones only as a member —
-- or as a Spaces admin (D2; the screen logs space.admin_view when it opens one).
CREATE OR REPLACE FUNCTION sp_visible_space_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT app_is_active_member() THEN RETURN; END IF;
    IF sp_is_guest() THEN
        RETURN QUERY SELECT * FROM sp_member_space_ids();
        RETURN;
    END IF;
    RETURN QUERY
        SELECT s.id FROM spaces s WHERE s.kind IN ('open', 'closed') OR sp_is_admin()
        UNION
        SELECT * FROM sp_member_space_ids();
END$$;

-- The level a space gives a member on its root pages: owner → full; member → member_level; everyone in the business → the
-- open space's everyone_level; else none. Guests get nothing from a space they are not in.
CREATE OR REPLACE FUNCTION sp_space_level(p_space_id bigint, p_member_id bigint DEFAULT app_current_member_id()) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE s spaces%ROWTYPE; r text;
BEGIN
    SELECT * INTO s FROM spaces WHERE id = p_space_id;
    IF NOT FOUND OR p_member_id IS NULL THEN RETURN 'none'; END IF;
    IF p_member_id = app_current_member_id() AND sp_is_admin() THEN RETURN 'full'; END IF;   -- D2: the Spaces admin, everywhere
    SELECT sm.role INTO r FROM space_members sm WHERE sm.space_id = p_space_id AND sm.member_id = p_member_id;
    IF r = 'owner' THEN RETURN 'full'; END IF;
    IF r = 'member' THEN RETURN s.member_level; END IF;
    IF sp_member_is_guest(p_member_id) THEN RETURN 'none'; END IF;
    IF s.is_default THEN RETURN s.member_level; END IF;
    IF s.department_id IS NOT NULL AND EXISTS (SELECT 1 FROM department_members dm WHERE dm.department_id = s.department_id
                                                  AND dm.member_id = p_member_id AND dm.left_at IS NULL) THEN
        RETURN s.member_level;
    END IF;
    IF s.kind = 'open' THEN RETURN s.everyone_level; END IF;
    RETURN 'none';
END$$;

CREATE OR REPLACE FUNCTION sp_is_space_owner(p_space_id bigint, p_member_id bigint DEFAULT app_current_member_id()) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM space_members sm WHERE sm.space_id = p_space_id AND sm.member_id = p_member_id AND sm.role = 'owner')
        OR (p_member_id = app_current_member_id() AND sp_is_admin());
END$$;

-- Everyone in a space (for the people picker, @channel, the member list): explicit rows ∪ the department ∪ everyone for General.
CREATE OR REPLACE FUNCTION sp_space_member_ids(p_space_id bigint) RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE s spaces%ROWTYPE;
BEGIN
    SELECT * INTO s FROM spaces WHERE id = p_space_id;
    IF NOT FOUND THEN RETURN; END IF;
    RETURN QUERY
        SELECT sm.member_id FROM space_members sm WHERE sm.space_id = p_space_id
        UNION
        SELECT dm.member_id FROM department_members dm JOIN members m ON m.id = dm.member_id
         WHERE s.department_id IS NOT NULL AND dm.department_id = s.department_id AND dm.left_at IS NULL
           AND m.status = 'active' AND m.capability IS NOT NULL
        UNION
        SELECT m.id FROM members m WHERE s.is_default AND m.status = 'active' AND m.capability IS NOT NULL AND NOT sp_member_is_guest(m.id);
END$$;

-- ---------------------------------------------------------------------------------------------
-- The referee.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_spaces_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.is_default AND NOT NEW.is_default THEN
            RAISE EXCEPTION 'The default space stays the default: make another space the default instead' USING ERRCODE = 'P0001';
        END IF;
        IF OLD.is_default AND NEW.kind <> 'open' THEN
            RAISE EXCEPTION 'The default space is open to everyone in the business' USING ERRCODE = 'P0001';
        END IF;
        IF OLD.archived_at IS NOT NULL AND NEW.archived_at IS NOT NULL AND (NEW.name, NEW.kind, NEW.member_level, NEW.everyone_level) IS DISTINCT FROM (OLD.name, OLD.kind, OLD.member_level, OLD.everyone_level) THEN
            RAISE EXCEPTION 'Space "%" is archived: restore it before changing it', OLD.name USING ERRCODE = 'P0001';
        END IF;
        IF NEW.is_default AND NEW.archived_at IS NOT NULL THEN
            RAISE EXCEPTION 'The default space cannot be archived' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    IF NEW.slug IS NULL OR NEW.slug = '' THEN
        NEW.slug := sp_slugify(NEW.name);
    END IF;
    RETURN NEW;
END$$;

CREATE OR REPLACE FUNCTION sp_slugify(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(NULLIF(trim(both '-' from regexp_replace(lower(unaccent(p)), '[^a-z0-9]+', '-', 'g')), ''), 'space');
$$;
CREATE TRIGGER spaces_guard BEFORE INSERT OR UPDATE ON spaces FOR EACH ROW EXECUTE FUNCTION sp_spaces_guard();

-- A unique slug for a new space (the name, then name-2, name-3 …).
CREATE OR REPLACE FUNCTION sp_unique_space_slug(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE base text := sp_slugify(p_name); cand text := sp_slugify(p_name); n integer := 1;
BEGIN
    WHILE EXISTS (SELECT 1 FROM spaces WHERE slug = cand) LOOP
        n := n + 1; cand := left(base, 70) || '-' || n;
    END LOOP;
    RETURN cand;
END$$;

CREATE OR REPLACE FUNCTION sp_space_members_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s spaces%ROWTYPE;
BEGIN
    SELECT * INTO s FROM spaces WHERE id = COALESCE(NEW.space_id, OLD.space_id);
    IF TG_OP = 'DELETE' THEN
        IF s.is_default AND NOT sp_member_is_guest(OLD.member_id) AND EXISTS (SELECT 1 FROM members m WHERE m.id = OLD.member_id AND m.status = 'active' AND m.capability IS NOT NULL) THEN
            RAISE EXCEPTION 'Nobody leaves the default space' USING ERRCODE = 'P0001';
        END IF;
        IF OLD.role = 'owner' AND NOT EXISTS (SELECT 1 FROM space_members sm WHERE sm.space_id = OLD.space_id AND sm.role = 'owner' AND sm.member_id <> OLD.member_id) THEN
            RAISE EXCEPTION 'Space "%" needs an owner: name another owner first', s.name USING ERRCODE = 'P0001';
        END IF;
        RETURN OLD;
    END IF;
    IF sp_member_is_guest(NEW.member_id) AND NEW.role = 'owner' THEN
        RAISE EXCEPTION 'A guest never owns a space' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.role = 'owner' AND NEW.role <> 'owner'
       AND NOT EXISTS (SELECT 1 FROM space_members sm WHERE sm.space_id = NEW.space_id AND sm.role = 'owner' AND sm.member_id <> NEW.member_id) THEN
        RAISE EXCEPTION 'Space "%" needs an owner: name another owner first', s.name USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER space_members_guard BEFORE INSERT OR UPDATE OR DELETE ON space_members FOR EACH ROW EXECUTE FUNCTION sp_space_members_guard();

-- ---------------------------------------------------------------------------------------------
-- Seeds. General exists from the first migration (D4). A department's space is made when the directory delivers a STANDING
-- department (is_system), named after it, closed, its members the department's (derived above); the department's manager, when
-- known, is its owner. The sync's upsert fires this; a manager named later is added by the same trigger on update.
-- ---------------------------------------------------------------------------------------------
INSERT INTO spaces (name, slug, icon, description, kind, is_default, member_level, everyone_level)
VALUES ('General', 'general', '🏠', 'Everyone in the business. Announcements, questions, and the pages everyone needs.', 'open', true, 'edit', 'view')
ON CONFLICT (slug) DO NOTHING;

CREATE OR REPLACE FUNCTION sp_department_space_seed() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sid bigint;
BEGIN
    IF NOT NEW.is_system OR NEW.archived_at IS NOT NULL THEN RETURN NEW; END IF;
    SELECT id INTO sid FROM spaces WHERE department_id = NEW.id;
    IF sid IS NULL THEN
        INSERT INTO spaces (name, slug, icon, description, kind, department_id, member_level, everyone_level)
        VALUES (NEW.name, sp_unique_space_slug(NEW.name), '🏢', 'The ' || NEW.name || ' department''s space.', 'closed', NEW.id, 'edit', 'none')
        RETURNING id INTO sid;
    END IF;
    IF NEW.manager_member_id IS NOT NULL AND EXISTS (SELECT 1 FROM members m WHERE m.id = NEW.manager_member_id AND m.status = 'active' AND m.capability IS NOT NULL) THEN
        INSERT INTO space_members (space_id, member_id, role) VALUES (sid, NEW.manager_member_id, 'owner')
        ON CONFLICT (space_id, member_id) DO UPDATE SET role = 'owner';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER departments_space_seed AFTER INSERT OR UPDATE OF is_system, manager_member_id, archived_at ON departments
    FOR EACH ROW EXECUTE FUNCTION sp_department_space_seed();

-- An agent granted Member joins General on hire (D4): the mirror's capability going from NULL to a value, for an agent.
-- (General's membership is derived — every admitted non-guest is in it — so nothing to write; the row below is for the
-- "joined_at" the member list shows.)
CREATE OR REPLACE FUNCTION sp_member_admitted() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE gid bigint;
BEGIN
    SELECT id INTO gid FROM spaces WHERE is_default;
    IF gid IS NULL THEN RETURN NEW; END IF;
    IF NEW.capability IS NOT NULL AND NEW.status = 'active' AND NOT sp_member_is_guest(NEW.id) THEN
        IF NEW.member_kind = 'human' OR (SELECT agents_join_default_space FROM sp_settings WHERE id = 1) THEN
            INSERT INTO space_members (space_id, member_id, role) VALUES (gid, NEW.id, 'member') ON CONFLICT DO NOTHING;
        END IF;
        -- the directory feed admits members AFTER it delivers departments: a manager admitted now owns the department spaces they manage
        INSERT INTO space_members (space_id, member_id, role)
        SELECT s.id, NEW.id, 'owner' FROM spaces s JOIN departments d ON d.id = s.department_id WHERE d.manager_member_id = NEW.id AND d.archived_at IS NULL
        ON CONFLICT (space_id, member_id) DO UPDATE SET role = 'owner';
    ELSIF TG_OP = 'UPDATE' AND (NEW.capability IS NULL OR sp_member_is_guest(NEW.id)) THEN
        DELETE FROM space_members WHERE space_id = gid AND member_id = NEW.id AND role = 'member';   -- a guest, or no longer admitted
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER members_admitted AFTER INSERT OR UPDATE OF capability, is_external, roles, status ON members FOR EACH ROW EXECUTE FUNCTION sp_member_admitted();

GRANT SELECT, INSERT, UPDATE, DELETE ON spaces, space_members, space_join_requests, space_sections TO spaces_rw;
GRANT EXECUTE ON FUNCTION sp_member_space_ids(bigint), sp_visible_space_ids(), sp_space_level(bigint, bigint), sp_is_space_owner(bigint, bigint),
    sp_space_member_ids(bigint), sp_slugify(text), sp_unique_space_slug(text)
    TO spaces_rw, spaces_records_ro, spaces_activity_ro;

COMMIT;
