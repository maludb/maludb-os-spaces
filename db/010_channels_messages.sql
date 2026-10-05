-- 010: CHANNELS and MESSAGES — Slack's conversations in the OS's form (design §0.2, §3, §6; D9, D10).
--
-- A channel lives in a space (public: readable and writable by every member of the space — channel_members is who FOLLOWS
-- it; private: by its members only) or outside any space (dm: exactly two members; group_dm: 3–N). A message has a body in
-- the one rich-text format, a thread root (one level, as Slack), a tombstone on delete, an "also send to channel" flag on a
-- reply, a schedule, and an author who alone edits it. Unread is a per-member cursor. Retention is per channel, off by
-- default (D9). Mentions are extracted from the body by trigger; a mention of an agent or a DM to one becomes a dispatch (db/012).
--
-- THE ESTATE'S DATA MODEL (design §6.1): new. Help Desk's `messages` (a ticket's correspondence) is a different record under
-- the same family — recorded as a sibling, not reused; these become the canonical tables for conversations.
BEGIN;

CREATE TABLE channels (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    space_id          bigint REFERENCES spaces(id) ON DELETE RESTRICT,                 -- NULL = a DM or group DM
    kind              text NOT NULL CHECK (kind IN ('public', 'private', 'dm', 'group_dm')),
    name              text CHECK (name IS NULL OR name ~ '^[a-z0-9][a-z0-9_-]{0,79}$'),  -- #name; NULL for a DM
    topic             text CHECK (topic IS NULL OR length(topic) <= 250),
    purpose           text CHECK (purpose IS NULL OR length(purpose) <= 250),
    is_default        boolean NOT NULL DEFAULT false,                                   -- the space's #general
    created_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_at       timestamptz,
    archived_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    retention_days    integer CHECK (retention_days IS NULL OR retention_days BETWEEN 1 AND 3650),   -- NULL = keep forever (D9)
    last_message_at   timestamptz,
    message_count     bigint NOT NULL DEFAULT 0,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind IN ('public', 'private')) = (space_id IS NOT NULL)),
    CHECK ((kind IN ('public', 'private')) = (name IS NOT NULL)),
    CHECK (NOT is_default OR kind = 'public')
);
CREATE UNIQUE INDEX channels_name_in_space ON channels (space_id, name) WHERE space_id IS NOT NULL;
CREATE UNIQUE INDEX channels_one_default_per_space ON channels (space_id) WHERE is_default;
CREATE INDEX channels_space_idx ON channels (space_id, archived_at);
CREATE TRIGGER channels_touch BEFORE UPDATE ON channels FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE spaces ADD CONSTRAINT spaces_default_channel_fk FOREIGN KEY (default_channel_id) REFERENCES channels(id) ON DELETE SET NULL;

CREATE TABLE channel_members (
    channel_id            bigint NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    member_id             bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    joined_at             timestamptz NOT NULL DEFAULT now(),
    added_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    last_read_message_id  bigint NOT NULL DEFAULT 0,
    last_read_at          timestamptz,
    muted_until           timestamptz,
    notify                text NOT NULL DEFAULT 'all' CHECK (notify IN ('all', 'mentions', 'none')),
    starred               boolean NOT NULL DEFAULT false,
    section               text CHECK (section IS NULL OR length(section) <= 40),         -- the member's own sidebar section
    PRIMARY KEY (channel_id, member_id)
);
CREATE INDEX channel_members_member_idx ON channel_members (member_id);

-- A DM is found by its pair (ordered ids).
CREATE TABLE dm_pairs (
    member_a    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    member_b    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    channel_id  bigint NOT NULL UNIQUE REFERENCES channels(id) ON DELETE CASCADE,
    PRIMARY KEY (member_a, member_b),
    CHECK (member_a < member_b)
);

CREATE TABLE messages (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_id        bigint NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    thread_root_id    bigint REFERENCES messages(id) ON DELETE CASCADE,                 -- a reply's root; NULL = a top-level message
    author_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,
    kind              text NOT NULL DEFAULT 'message' CHECK (kind IN ('message', 'system', 'agent_pending')),
    body              jsonb NOT NULL DEFAULT '[]' CHECK (sp_is_rich_text(body)),
    plain_text        text GENERATED ALWAYS AS (sp_rich_text_plain(body)) STORED,
    reply_count       integer NOT NULL DEFAULT 0,
    last_reply_at     timestamptz,
    also_to_channel   boolean NOT NULL DEFAULT false,                                   -- a reply shown in the channel too
    edited_at         timestamptz,
    deleted_at        timestamptz,                                                       -- a tombstone: the body is emptied, the shape kept
    deleted_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    scheduled_for     timestamptz,                                                       -- a draft until then (the worker sends it)
    sent_at           timestamptz,                                                       -- NULL while scheduled
    agent_run_id      bigint,                                                            -- the kernel's run that wrote it (no FK)
    attachment_count  integer NOT NULL DEFAULT 0,
    created_at        timestamptz NOT NULL DEFAULT now()                                 -- a scheduled message may be sent early ("send now")
);
CREATE INDEX messages_channel_idx   ON messages (channel_id, id) WHERE thread_root_id IS NULL OR also_to_channel;
CREATE INDEX messages_thread_idx    ON messages (thread_root_id, id) WHERE thread_root_id IS NOT NULL;
CREATE INDEX messages_author_idx    ON messages (author_member_id, created_at DESC);
CREATE INDEX messages_scheduled_idx ON messages (scheduled_for) WHERE sent_at IS NULL AND scheduled_for IS NOT NULL;
CREATE INDEX messages_text_trgm_idx ON messages USING gin (plain_text gin_trgm_ops);
CREATE INDEX messages_retention_idx ON messages (channel_id, created_at);

CREATE TABLE message_mentions (
    message_id   bigint NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('member', 'agent', 'department', 'channel', 'here', 'everyone')),
    principal_id bigint                                                                  -- NULL for channel/here/everyone
);
CREATE UNIQUE INDEX message_mentions_one ON message_mentions (message_id, kind, COALESCE(principal_id, 0));
CREATE INDEX message_mentions_principal_idx ON message_mentions (kind, principal_id, message_id DESC);

CREATE TABLE message_reactions (
    message_id  bigint NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    emoji       text NOT NULL CHECK (length(emoji) BETWEEN 1 AND 16),
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (message_id, member_id, emoji)
);

CREATE TABLE saved_messages (
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    message_id  bigint NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, message_id)
);

-- Internal links in a message, for unfurls and backlinks (a page or database mentioned; a message linked).
CREATE TABLE message_links (
    message_id   bigint NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    to_page_id   uuid,
    to_message_id bigint,
    kind         text NOT NULL CHECK (kind IN ('page', 'database', 'message')),
    CHECK ((to_page_id IS NULL) <> (to_message_id IS NULL))
);
CREATE INDEX message_links_page_idx ON message_links (to_page_id) WHERE to_page_id IS NOT NULL;
CREATE INDEX message_links_message_idx ON message_links (to_message_id) WHERE to_message_id IS NOT NULL;

CREATE TABLE channel_pins (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_id  bigint NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    message_id  bigint REFERENCES messages(id) ON DELETE CASCADE,
    page_id     uuid REFERENCES pages(id) ON DELETE CASCADE,
    pinned_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    CHECK ((message_id IS NULL) <> (page_id IS NULL))
);
CREATE UNIQUE INDEX channel_pins_message ON channel_pins (channel_id, message_id) WHERE message_id IS NOT NULL;
CREATE UNIQUE INDEX channel_pins_page ON channel_pins (channel_id, page_id) WHERE page_id IS NOT NULL;

CREATE TABLE channel_bookmarks (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_id  bigint NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    title       text NOT NULL CHECK (length(title) BETWEEN 1 AND 100),
    url         text,
    page_id     uuid REFERENCES pages(id) ON DELETE CASCADE,
    emoji       text,
    position    text NOT NULL DEFAULT 'a0',
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    CHECK ((url IS NULL) <> (page_id IS NULL))
);
CREATE INDEX channel_bookmarks_channel_idx ON channel_bookmarks (channel_id, position);

CREATE TABLE reminders (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    message_id  bigint REFERENCES messages(id) ON DELETE CASCADE,
    page_id     uuid REFERENCES pages(id) ON DELETE CASCADE,
    text        text CHECK (text IS NULL OR length(text) <= 500),
    remind_at   timestamptz NOT NULL,
    done_at     timestamptz,
    notified_at timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now(),
    CHECK (message_id IS NOT NULL OR page_id IS NOT NULL OR text IS NOT NULL)
);
CREATE INDEX reminders_due_idx ON reminders (remind_at) WHERE done_at IS NULL AND notified_at IS NULL;
CREATE INDEX reminders_member_idx ON reminders (member_id, remind_at) WHERE done_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- Who is in a channel, and what the caller may read. ONE query each.
-- ---------------------------------------------------------------------------------------------
-- A member of the channel: a public channel's members are the space's; private/dm/group_dm by explicit rows.
CREATE OR REPLACE FUNCTION sp_in_channel(p_channel_id bigint, p_member_id bigint DEFAULT app_current_member_id()) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE c channels%ROWTYPE;
BEGIN
    IF p_member_id IS NULL THEN RETURN false; END IF;
    SELECT * INTO c FROM channels WHERE id = p_channel_id;
    IF NOT FOUND THEN RETURN false; END IF;
    IF c.kind = 'public' THEN
        RETURN (NOT sp_member_is_guest(p_member_id) AND c.space_id IN (SELECT sp_member_space_ids(p_member_id)))
            OR EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = p_channel_id AND cm.member_id = p_member_id);
    END IF;
    RETURN EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = p_channel_id AND cm.member_id = p_member_id);
END$$;

-- Every channel the caller may READ: public channels of their spaces (and of open spaces, at everyone level ≥ view), the
-- private ones, DMs and group DMs they are in; a Spaces admin reads every space channel (never a DM they are not in).
CREATE OR REPLACE FUNCTION sp_visible_channel_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); is_guest boolean; is_admin boolean;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    is_guest := sp_is_guest(); is_admin := sp_is_admin();
    RETURN QUERY
        SELECT c.id FROM channels c
         WHERE (c.kind = 'public' AND NOT is_guest AND (is_admin OR c.space_id IN (SELECT sp_member_space_ids(me))
                                                         OR c.space_id IN (SELECT s.id FROM spaces s WHERE s.kind = 'open' AND s.everyone_level <> 'none')))
            OR (c.kind = 'private' AND is_admin AND NOT is_guest)
        UNION
        SELECT cm.channel_id FROM channel_members cm WHERE cm.member_id = me;
END$$;

-- Where the caller may WRITE: a member of the channel, the channel live, the caller not a guest in a public channel they were
-- not added to (sp_in_channel already says so).
CREATE OR REPLACE FUNCTION sp_can_post(p_channel_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE c channels%ROWTYPE;
BEGIN
    SELECT * INTO c FROM channels WHERE id = p_channel_id;
    IF NOT FOUND OR c.archived_at IS NOT NULL THEN RETURN false; END IF;
    IF c.space_id IS NOT NULL AND EXISTS (SELECT 1 FROM spaces s WHERE s.id = c.space_id AND s.archived_at IS NOT NULL) THEN RETURN false; END IF;
    RETURN sp_in_channel(p_channel_id) AND (sp_has_right('channels.write') OR (sp_is_guest() AND sp_has_right('spaces.guest')) OR (c.kind IN ('dm', 'group_dm') AND sp_has_right('dm.write')));
END$$;

-- The people a guest may see also include everyone in the channels they are in (extends db/007's rule).
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
           AND pp.page_id IN (SELECT permission_root_id FROM pages WHERE id IN (SELECT sp_visible_page_ids()))
        UNION
        SELECT cm.member_id FROM channel_members cm WHERE cm.channel_id IN (SELECT sp_visible_channel_ids())
        UNION
        SELECT m.author_member_id FROM messages m WHERE m.channel_id IN (SELECT sp_visible_channel_ids()) AND m.author_member_id IS NOT NULL;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The referee.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_channels_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' AND NEW.created_by IS NULL THEN NEW.created_by := app_current_member_id(); END IF;
    IF TG_OP = 'UPDATE' THEN
        IF NEW.kind <> OLD.kind AND NOT (OLD.kind IN ('public', 'private') AND NEW.kind IN ('public', 'private')) THEN
            RAISE EXCEPTION 'A % cannot become a %', OLD.kind, NEW.kind USING ERRCODE = 'P0001';
        END IF;
        IF NEW.space_id IS DISTINCT FROM OLD.space_id THEN RAISE EXCEPTION 'A channel stays in its space' USING ERRCODE = 'P0001'; END IF;
        IF OLD.is_default AND NOT NEW.is_default THEN RAISE EXCEPTION 'The space''s default channel stays its default: make another the default' USING ERRCODE = 'P0001'; END IF;
        IF OLD.is_default AND NEW.archived_at IS NOT NULL THEN RAISE EXCEPTION 'The space''s default channel cannot be archived' USING ERRCODE = 'P0001'; END IF;
        IF OLD.is_default AND NEW.kind <> 'public' THEN RAISE EXCEPTION 'The default channel stays public' USING ERRCODE = 'P0001'; END IF;
        IF OLD.archived_at IS NOT NULL AND NEW.archived_at IS NOT NULL AND (NEW.name, NEW.topic, NEW.purpose, NEW.kind) IS DISTINCT FROM (OLD.name, OLD.topic, OLD.purpose, OLD.kind) THEN
            RAISE EXCEPTION 'Channel #% is archived: unarchive it to change it', OLD.name USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER channels_guard BEFORE INSERT OR UPDATE ON channels FOR EACH ROW EXECUTE FUNCTION sp_channels_guard();

-- Membership: a DM has exactly two (never changed); a group DM 3–N; a guest joins a public channel only by being added.
CREATE OR REPLACE FUNCTION sp_channel_members_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c channels%ROWTYPE; n integer; mx integer;
BEGIN
    SELECT * INTO c FROM channels WHERE id = COALESCE(NEW.channel_id, OLD.channel_id);
    IF TG_OP = 'DELETE' THEN
        IF c.kind = 'dm' THEN RAISE EXCEPTION 'A direct message has its two people' USING ERRCODE = 'P0001'; END IF;
        IF c.is_default AND EXISTS (SELECT 1 FROM spaces s WHERE s.id = c.space_id AND s.is_default) THEN
            RAISE EXCEPTION 'Nobody leaves the default space''s #general' USING ERRCODE = 'P0001';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.channel_id, NEW.member_id) IS DISTINCT FROM (OLD.channel_id, OLD.member_id) THEN
        RAISE EXCEPTION 'A membership is added or removed, never moved' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.added_by IS NULL THEN NEW.added_by := app_current_member_id(); END IF;
        IF NOT EXISTS (SELECT 1 FROM members m WHERE m.id = NEW.member_id AND m.status = 'active' AND m.capability IS NOT NULL) THEN
            RAISE EXCEPTION 'Only an admitted member joins a channel' USING ERRCODE = 'P0001';
        END IF;
        SELECT count(*) INTO n FROM channel_members WHERE channel_id = NEW.channel_id;
        IF c.kind = 'dm' AND n >= 2 THEN RAISE EXCEPTION 'A direct message has exactly two people (start a group message instead)' USING ERRCODE = 'P0001'; END IF;
        IF c.kind = 'group_dm' THEN
            SELECT group_dm_max_members INTO mx FROM sp_settings WHERE id = 1;
            IF n >= mx THEN RAISE EXCEPTION 'A group message holds at most % people', mx USING ERRCODE = 'P0001'; END IF;
        END IF;
        IF c.kind IN ('public', 'private') AND c.space_id IS NOT NULL AND NOT sp_member_is_guest(NEW.member_id)
           AND NEW.member_id NOT IN (SELECT sp_space_member_ids(c.space_id)) AND NOT EXISTS (SELECT 1 FROM spaces s WHERE s.id = c.space_id AND s.kind = 'open') THEN
            RAISE EXCEPTION 'Join the space first' USING ERRCODE = 'P0001';
        END IF;
        IF c.archived_at IS NOT NULL THEN RAISE EXCEPTION 'Channel #% is archived', c.name USING ERRCODE = 'P0001'; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER channel_members_guard BEFORE INSERT OR UPDATE OR DELETE ON channel_members FOR EACH ROW EXECUTE FUNCTION sp_channel_members_guard();

-- Messages: a thread's root is a top-level message of the same channel; the author alone edits; a delete is a tombstone;
-- a scheduled message is unsent until the worker sends it; counters on the channel and the root follow.
CREATE OR REPLACE FUNCTION sp_messages_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE root messages%ROWTYPE; c channels%ROWTYPE; me bigint := app_current_member_id();
BEGIN
    SELECT * INTO c FROM channels WHERE id = NEW.channel_id;
    IF TG_OP = 'INSERT' THEN
        IF NEW.author_member_id IS NULL AND NEW.kind <> 'system' THEN NEW.author_member_id := me; END IF;
        IF c.archived_at IS NOT NULL THEN RAISE EXCEPTION 'Channel #% is archived: nothing is posted in it', c.name USING ERRCODE = 'P0001'; END IF;
        IF NEW.kind <> 'system' AND NEW.author_member_id IS NOT NULL AND NOT sp_in_channel(NEW.channel_id, NEW.author_member_id) THEN
            RAISE EXCEPTION 'Only a member of the channel posts in it' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.scheduled_for IS NULL THEN NEW.sent_at := COALESCE(NEW.sent_at, now()); ELSE NEW.sent_at := NULL; END IF;
        IF NEW.scheduled_for IS NOT NULL AND NEW.scheduled_for <= now() THEN NEW.scheduled_for := NULL; NEW.sent_at := now(); END IF;
    END IF;
    IF NEW.thread_root_id IS NOT NULL THEN
        SELECT * INTO root FROM messages WHERE id = NEW.thread_root_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'The thread''s message does not exist' USING ERRCODE = 'P0001'; END IF;
        IF root.channel_id <> NEW.channel_id THEN RAISE EXCEPTION 'A reply goes in its thread''s channel' USING ERRCODE = 'P0001'; END IF;
        IF root.thread_root_id IS NOT NULL THEN RAISE EXCEPTION 'A thread is one level deep: reply to the thread''s first message' USING ERRCODE = 'P0001'; END IF;
        IF root.deleted_at IS NOT NULL AND TG_OP = 'INSERT' THEN RAISE EXCEPTION 'That message was deleted' USING ERRCODE = 'P0001'; END IF;
    ELSIF NEW.also_to_channel THEN
        NEW.also_to_channel := false;   -- only a reply has somewhere else to be
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF (NEW.channel_id, NEW.thread_root_id, NEW.author_member_id, NEW.created_at) IS DISTINCT FROM (OLD.channel_id, OLD.thread_root_id, OLD.author_member_id, OLD.created_at) THEN
            RAISE EXCEPTION 'A message stays where and whose it is' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.body IS DISTINCT FROM OLD.body THEN
            IF OLD.deleted_at IS NOT NULL THEN RAISE EXCEPTION 'A deleted message is not edited' USING ERRCODE = 'P0001'; END IF;
            IF NEW.deleted_at IS NULL THEN
                IF OLD.author_member_id IS DISTINCT FROM me AND current_setting('app.sp_worker', true) IS DISTINCT FROM '1' THEN
                    RAISE EXCEPTION 'Only the author edits a message' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.sent_at IS NOT NULL THEN NEW.edited_at := now(); END IF;
            END IF;
        END IF;
        IF NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL THEN
            NEW.body := '[]'::jsonb;                                  -- the tombstone keeps the shape, not the words
            NEW.deleted_by := COALESCE(NEW.deleted_by, me);
            NEW.attachment_count := 0;
        END IF;
        IF OLD.sent_at IS NOT NULL AND NEW.scheduled_for IS DISTINCT FROM OLD.scheduled_for THEN
            RAISE EXCEPTION 'A sent message is not rescheduled' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER messages_guard BEFORE INSERT OR UPDATE ON messages FOR EACH ROW EXECUTE FUNCTION sp_messages_guard();

CREATE OR REPLACE FUNCTION sp_messages_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r jsonb; txt text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        -- a hard delete is retention's (db/014): counters follow
        IF OLD.sent_at IS NOT NULL THEN
            UPDATE channels SET message_count = greatest(message_count - 1, 0) WHERE id = OLD.channel_id;
            IF OLD.thread_root_id IS NOT NULL THEN UPDATE messages SET reply_count = greatest(reply_count - 1, 0) WHERE id = OLD.thread_root_id; END IF;
        END IF;
        RETURN NULL;
    END IF;
    -- sent now (an insert, or a scheduled one the worker released)
    IF NEW.sent_at IS NOT NULL AND (TG_OP = 'INSERT' OR OLD.sent_at IS NULL) THEN
        UPDATE channels SET message_count = message_count + 1, last_message_at = NEW.sent_at WHERE id = NEW.channel_id;
        IF NEW.thread_root_id IS NOT NULL THEN
            UPDATE messages SET reply_count = reply_count + 1, last_reply_at = NEW.sent_at WHERE id = NEW.thread_root_id;
        END IF;
        -- the author has read their own message
        UPDATE channel_members SET last_read_message_id = greatest(last_read_message_id, NEW.id), last_read_at = now()
         WHERE channel_id = NEW.channel_id AND member_id = NEW.author_member_id;
    END IF;
    -- mentions and links from the body (on every body write)
    IF TG_OP = 'INSERT' OR NEW.body IS DISTINCT FROM OLD.body THEN
        DELETE FROM message_mentions WHERE message_id = NEW.id;
        DELETE FROM message_links WHERE message_id = NEW.id;
        FOR r IN SELECT x FROM jsonb_array_elements(NEW.body) x WHERE x->>'type' = 'mention' LOOP
            CASE r->'mention'->>'type'
                WHEN 'member' THEN
                    IF (r->'mention'->>'id') ~ '^[0-9]+$' THEN
                        INSERT INTO message_mentions (message_id, kind, principal_id)
                        SELECT NEW.id, CASE WHEN m.member_kind = 'agent' THEN 'agent' ELSE 'member' END, m.id
                          FROM members m WHERE m.id = (r->'mention'->>'id')::bigint ON CONFLICT DO NOTHING;
                    END IF;
                WHEN 'agent' THEN
                    IF (r->'mention'->>'id') ~ '^[0-9]+$' THEN
                        INSERT INTO message_mentions (message_id, kind, principal_id) VALUES (NEW.id, 'agent', (r->'mention'->>'id')::bigint) ON CONFLICT DO NOTHING;
                    END IF;
                WHEN 'department' THEN
                    IF (r->'mention'->>'id') ~ '^[0-9]+$' THEN
                        INSERT INTO message_mentions (message_id, kind, principal_id) VALUES (NEW.id, 'department', (r->'mention'->>'id')::bigint) ON CONFLICT DO NOTHING;
                    END IF;
                WHEN 'channel' THEN INSERT INTO message_mentions (message_id, kind) VALUES (NEW.id, 'channel') ON CONFLICT DO NOTHING;
                WHEN 'here' THEN INSERT INTO message_mentions (message_id, kind) VALUES (NEW.id, 'here') ON CONFLICT DO NOTHING;
                WHEN 'everyone' THEN INSERT INTO message_mentions (message_id, kind) VALUES (NEW.id, 'everyone') ON CONFLICT DO NOTHING;
                WHEN 'page' THEN
                    IF (r->'mention'->>'id') ~ '^[0-9a-f-]{36}$' THEN INSERT INTO message_links (message_id, to_page_id, kind) VALUES (NEW.id, (r->'mention'->>'id')::uuid, 'page'); END IF;
                WHEN 'database' THEN
                    IF (r->'mention'->>'id') ~ '^[0-9a-f-]{36}$' THEN INSERT INTO message_links (message_id, to_page_id, kind) VALUES (NEW.id, (r->'mention'->>'id')::uuid, 'database'); END IF;
                WHEN 'message' THEN
                    IF (r->'mention'->>'id') ~ '^[0-9]+$' THEN INSERT INTO message_links (message_id, to_message_id, kind) VALUES (NEW.id, (r->'mention'->>'id')::bigint, 'message'); END IF;
                ELSE NULL;
            END CASE;
        END LOOP;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER messages_after AFTER INSERT OR UPDATE OR DELETE ON messages FOR EACH ROW EXECUTE FUNCTION sp_messages_after();

-- A reaction, a save, a pin only by someone in the channel; a pin of a page only one the pinner may see.
CREATE OR REPLACE FUNCTION sp_message_side_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cid bigint;
BEGIN
    IF TG_TABLE_NAME = 'message_reactions' OR TG_TABLE_NAME = 'saved_messages' THEN
        SELECT channel_id INTO cid FROM messages WHERE id = NEW.message_id;
        IF NOT sp_in_channel(cid, NEW.member_id) THEN RAISE EXCEPTION 'Only a member of the channel does that' USING ERRCODE = 'P0001'; END IF;
    ELSIF TG_TABLE_NAME = 'channel_pins' THEN
        IF NEW.message_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.id = NEW.message_id AND m.channel_id = NEW.channel_id) THEN
            RAISE EXCEPTION 'A pinned message belongs to the channel' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.pinned_by IS NULL THEN NEW.pinned_by := app_current_member_id(); END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER message_reactions_guard BEFORE INSERT ON message_reactions FOR EACH ROW EXECUTE FUNCTION sp_message_side_guard();
CREATE TRIGGER saved_messages_guard BEFORE INSERT ON saved_messages FOR EACH ROW EXECUTE FUNCTION sp_message_side_guard();
CREATE TRIGGER channel_pins_guard BEFORE INSERT ON channel_pins FOR EACH ROW EXECUTE FUNCTION sp_message_side_guard();

-- ---------------------------------------------------------------------------------------------
-- Writers.
-- ---------------------------------------------------------------------------------------------
-- A space gets its #general when it is made (the seeded ones too), the creator a member of it.
CREATE OR REPLACE FUNCTION sp_space_default_channel() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cid bigint;
BEGIN
    IF NEW.default_channel_id IS NULL THEN
        INSERT INTO channels (space_id, kind, name, purpose, is_default, created_by)
        VALUES (NEW.id, 'public', 'general', 'Everything in ' || NEW.name, true, NEW.created_by) RETURNING id INTO cid;
        UPDATE spaces SET default_channel_id = cid WHERE id = NEW.id;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER spaces_default_channel AFTER INSERT ON spaces FOR EACH ROW EXECUTE FUNCTION sp_space_default_channel();
-- the General space seeded in db/006 gets its channel now
INSERT INTO channels (space_id, kind, name, purpose, is_default) SELECT s.id, 'public', 'general', 'Everything in ' || s.name, true FROM spaces s WHERE s.default_channel_id IS NULL;
UPDATE spaces s SET default_channel_id = c.id FROM channels c WHERE c.space_id = s.id AND c.is_default AND s.default_channel_id IS NULL;

-- Open (or find) the DM between the caller and another member.
CREATE OR REPLACE FUNCTION sp_dm_open(p_other bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); a bigint; b bigint; cid bigint;
BEGIN
    IF me IS NULL OR p_other IS NULL OR me = p_other THEN RAISE EXCEPTION 'A direct message is with someone else' USING ERRCODE = 'P0001'; END IF;
    IF NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_other AND m.status = 'active' AND m.capability IS NOT NULL) THEN
        RAISE EXCEPTION 'That member is not here' USING ERRCODE = 'P0001';
    END IF;
    IF p_other NOT IN (SELECT sp_visible_member_ids()) THEN RAISE EXCEPTION 'That member is not someone you can see' USING ERRCODE = 'P0001'; END IF;
    a := least(me, p_other); b := greatest(me, p_other);
    SELECT channel_id INTO cid FROM dm_pairs WHERE member_a = a AND member_b = b;
    IF cid IS NOT NULL THEN RETURN cid; END IF;
    INSERT INTO channels (kind, created_by) VALUES ('dm', me) RETURNING id INTO cid;
    INSERT INTO channel_members (channel_id, member_id, added_by) VALUES (cid, a, me), (cid, b, me);
    INSERT INTO dm_pairs (member_a, member_b, channel_id) VALUES (a, b, cid);
    RETURN cid;
END$$;

-- A group DM (3–N people, the caller included).
CREATE OR REPLACE FUNCTION sp_group_dm_open(p_members bigint[]) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); cid bigint; ids bigint[]; m bigint;
BEGIN
    ids := ARRAY(SELECT DISTINCT x FROM unnest(array_append(COALESCE(p_members, '{}'), me)) x ORDER BY x);
    IF cardinality(ids) < 3 THEN RAISE EXCEPTION 'A group message needs at least three people' USING ERRCODE = 'P0001'; END IF;
    -- an existing group with exactly these members is reused
    SELECT c.id INTO cid FROM channels c WHERE c.kind = 'group_dm'
       AND ids = (SELECT array_agg(cm.member_id ORDER BY cm.member_id) FROM channel_members cm WHERE cm.channel_id = c.id) LIMIT 1;
    IF cid IS NOT NULL THEN RETURN cid; END IF;
    INSERT INTO channels (kind, created_by) VALUES ('group_dm', me) RETURNING id INTO cid;
    FOREACH m IN ARRAY ids LOOP
        INSERT INTO channel_members (channel_id, member_id, added_by) VALUES (cid, m, me);
    END LOOP;
    RETURN cid;
END$$;

-- Mark read up to a message.
CREATE OR REPLACE FUNCTION sp_channel_mark_read(p_channel_id bigint, p_message_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    INSERT INTO channel_members (channel_id, member_id, last_read_message_id, last_read_at)
    SELECT p_channel_id, me, p_message_id, now() WHERE sp_in_channel(p_channel_id, me)
    ON CONFLICT (channel_id, member_id) DO UPDATE SET last_read_message_id = greatest(channel_members.last_read_message_id, EXCLUDED.last_read_message_id), last_read_at = now();
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON channels, channel_members, dm_pairs, messages, message_mentions, message_reactions, saved_messages, message_links,
    channel_pins, channel_bookmarks, reminders TO spaces_rw;
GRANT EXECUTE ON FUNCTION sp_in_channel(bigint, bigint), sp_visible_channel_ids(), sp_can_post(bigint), sp_visible_member_ids()
    TO spaces_rw, spaces_records_ro, spaces_activity_ro;
REVOKE ALL ON FUNCTION sp_dm_open(bigint), sp_group_dm_open(bigint[]), sp_channel_mark_read(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sp_dm_open(bigint), sp_group_dm_open(bigint[]), sp_channel_mark_read(bigint, bigint) TO spaces_rw;

COMMIT;
