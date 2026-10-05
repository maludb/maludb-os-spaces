-- 012: TALKING to people (the bell, preferences, the outbox — email through MaluMail and texts through the kernel's K6 as
-- independent rows), FILES (attachments on a block, a message, a comment, a page's cover or a row's files property), and
-- DISPATCHING to agents (a mention of an agent or a DM to one becomes a dispatch the worker turns into one chat turn — D10).
--
-- THE ESTATE'S DATA MODEL (design §6.1), the reused tables of this file:
--   notifications, notification_prefs, notification_outbox — General Ledger db/014 CANONICAL. The `kind` lists are ours;
--     notification_prefs APPENDS `digest` and `away_minutes`; the outbox has NO external-party column — every recipient here is
--     a member (a guest is a member) — so member_id is NOT NULL and the pair CHECK is dropped (the substitution, recorded).
--   attachments — General Ledger db/009 CANONICAL (record_type/record_id, filename, mime_type, byte_size, sha256, storage_path,
--     uploaded_by) APPENDING record_uuid (a block, page, comment or row), width, height, thumbnail_path. ONE DEVIATION:
--     record_id is nullable with CHECK ((record_id IS NULL) <> (record_uuid IS NULL)) — proposed to the catalogue as the
--     optional UUID record key.
--   agent_dispatches — General Ledger db/014 = Consultant Tracking db/013 CANONICAL; `kind` widened to mention/dm/duty_proposal/ask;
--     APPENDS record_uuid (a page), conversation_id (the thread root), reply_message_id.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- Talking.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('mention', 'reply', 'reaction', 'comment', 'share', 'page_changed', 'verification', 'reminder',
                                               'dm', 'channel', 'join_request', 'join_decided', 'proposal', 'agent_replied', 'digest')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.1): the UUID record, and where to go
    record_uuid  uuid,
    channel_id   bigint REFERENCES channels(id) ON DELETE CASCADE,
    message_id   bigint REFERENCES messages(id) ON DELETE CASCADE,
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);
CREATE INDEX notifications_member_all_idx ON notifications (member_id, created_at DESC);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,                -- K6: the kernel keeps the opt-out; this is the person's choice here
    kinds          text[] NOT NULL DEFAULT '{mention,reply,comment,share,verification,reminder,dm,join_request,join_decided,proposal,agent_replied}',
    text_kinds     text[] NOT NULL DEFAULT '{dm,mention}',
    updated_at     timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.1)
    digest         boolean NOT NULL DEFAULT false,                -- one morning email instead of one per event
    away_minutes   integer CHECK (away_minutes IS NULL OR away_minutes BETWEEN 1 AND 1440)   -- NULL = the workspace's (sp_settings.away_minutes)
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per recipient AND channel; a K6 refusal is a SKIP with the code and the email still goes.
CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,                                                 -- the member's address at send time
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,                                                   -- MaluMail's message id, or the kernel's notification id
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);
CREATE INDEX notification_outbox_record_idx ON notification_outbox (record_type, record_id);

-- ---------------------------------------------------------------------------------------------
-- Files.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('block', 'message', 'comment', 'page_cover', 'page_icon', 'row_files', 'space_icon', 'import', 'export')),
    record_id     bigint,                                                  -- a message, a space, an import, an export
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,
    uploaded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.1)
    record_uuid   uuid,                                                    -- a block, a page, a comment, a row
    width         integer CHECK (width IS NULL OR width > 0),
    height        integer CHECK (height IS NULL OR height > 0),
    thumbnail_path text,
    CHECK ((record_id IS NULL) <> (record_uuid IS NULL))                   -- the one deviation from the canonical, recorded
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);
CREATE INDEX attachments_record_uuid_idx ON attachments (record_type, record_uuid);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);
CREATE INDEX attachments_sha_idx ON attachments (sha256);
ALTER TABLE pages ADD CONSTRAINT pages_cover_fk FOREIGN KEY (cover_attachment_id) REFERENCES attachments(id) ON DELETE SET NULL;

-- A message's attachment count follows.
CREATE OR REPLACE FUNCTION sp_attachments_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF COALESCE(NEW.record_type, OLD.record_type) = 'message' THEN
        UPDATE messages SET attachment_count = (SELECT count(*) FROM attachments a WHERE a.record_type = 'message' AND a.record_id = COALESCE(NEW.record_id, OLD.record_id))
         WHERE id = COALESCE(NEW.record_id, OLD.record_id);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER attachments_after AFTER INSERT OR DELETE ON attachments FOR EACH ROW EXECUTE FUNCTION sp_attachments_after();

-- The attachments the caller may see: on a page they may see, a message in a channel they may read, a comment on such a page.
CREATE OR REPLACE FUNCTION sp_can_see_attachment(p_attachment_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE a attachments%ROWTYPE; pid uuid;
BEGIN
    SELECT * INTO a FROM attachments WHERE id = p_attachment_id;
    IF NOT FOUND THEN RETURN false; END IF;
    CASE a.record_type
        WHEN 'block' THEN SELECT page_id INTO pid FROM blocks WHERE id = a.record_uuid; RETURN pid IS NOT NULL AND sp_can_see_page(pid);
        WHEN 'page_cover', 'page_icon', 'row_files' THEN RETURN sp_can_see_page(a.record_uuid);
        WHEN 'comment' THEN SELECT page_id INTO pid FROM comments WHERE id = a.record_uuid; RETURN pid IS NOT NULL AND sp_can_see_page(pid);
        WHEN 'message' THEN RETURN EXISTS (SELECT 1 FROM messages m WHERE m.id = a.record_id AND m.channel_id IN (SELECT sp_visible_channel_ids()));
        WHEN 'space_icon' THEN RETURN a.record_id IN (SELECT sp_visible_space_ids());
        WHEN 'export' THEN RETURN EXISTS (SELECT 1 FROM exports e WHERE e.id = a.record_id AND e.created_by = app_current_member_id()) OR sp_is_admin();
        WHEN 'import' THEN RETURN sp_is_admin() OR EXISTS (SELECT 1 FROM imports i WHERE i.id = a.record_id AND i.created_by = app_current_member_id());
        ELSE RETURN false;
    END CASE;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Dispatching to agents (design §5, D10).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,                                                 -- 'message' (a mention or a DM) | 'page' (a duty's proposal)
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('mention', 'dm', 'duty_proposal', 'ask')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,                                                 -- at most 200 characters
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    -- appended (design §6.1)
    record_uuid      uuid,                                                 -- a page, for a duty's proposal
    channel_id       bigint REFERENCES channels(id) ON DELETE CASCADE,
    conversation_id  text,                                                 -- the thread root as the kernel's conversation key
    reply_message_id bigint REFERENCES messages(id) ON DELETE SET NULL,
    pending_message_id bigint REFERENCES messages(id) ON DELETE SET NULL,  -- the "Seamus is thinking…" placeholder
    next_attempt_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);
CREATE INDEX agent_dispatches_acting_idx ON agent_dispatches (acting_member_id);
CREATE INDEX agent_dispatches_queue_idx ON agent_dispatches (next_attempt_at) WHERE status = 'sent' AND run_id IS NULL;
CREATE INDEX agent_dispatches_running_idx ON agent_dispatches (run_id) WHERE status = 'sent' AND run_id IS NOT NULL;
CREATE UNIQUE INDEX agent_dispatches_one_per_message_agent ON agent_dispatches (record_id, agent_member_id) WHERE record_type = 'message';

-- A message that mentions an agent, or a DM / group DM to an agent, writes a dispatch — when the message is SENT (not while
-- scheduled, not a system message, not an agent's own words to avoid a loop between agents).
CREATE OR REPLACE FUNCTION sp_dispatch_from_message() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c channels%ROWTYPE; author_kind text; agent_id bigint; root bigint;
BEGIN
    IF NEW.sent_at IS NULL OR NEW.kind <> 'message' OR NEW.deleted_at IS NOT NULL THEN RETURN NULL; END IF;
    IF TG_OP = 'UPDATE' AND OLD.sent_at IS NOT NULL THEN RETURN NULL; END IF;   -- only the moment it is sent
    SELECT member_kind INTO author_kind FROM members WHERE id = NEW.author_member_id;
    IF author_kind = 'agent' THEN RETURN NULL; END IF;
    SELECT * INTO c FROM channels WHERE id = NEW.channel_id;
    root := COALESCE(NEW.thread_root_id, NEW.id);
    -- mentions of agents
    FOR agent_id IN SELECT mm.principal_id FROM message_mentions mm WHERE mm.message_id = NEW.id AND mm.kind = 'agent' LOOP
        IF sp_in_channel(NEW.channel_id, agent_id) THEN
            INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, channel_id, conversation_id)
            VALUES ('message', NEW.id, agent_id, 'mention', 'chat', NEW.author_member_id, NEW.channel_id, 'spaces:thread:' || root)
            ON CONFLICT DO NOTHING;
        END IF;
    END LOOP;
    -- a DM or group DM: every agent in it that was not already mentioned
    IF c.kind IN ('dm', 'group_dm') THEN
        FOR agent_id IN SELECT cm.member_id FROM channel_members cm JOIN members m ON m.id = cm.member_id AND m.member_kind = 'agent'
                         WHERE cm.channel_id = NEW.channel_id AND cm.member_id <> NEW.author_member_id LOOP
            INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, channel_id, conversation_id)
            VALUES ('message', NEW.id, agent_id, 'dm', 'chat', NEW.author_member_id, NEW.channel_id, 'spaces:thread:' || root)
            ON CONFLICT DO NOTHING;
        END LOOP;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER messages_dispatch AFTER INSERT OR UPDATE OF sent_at ON messages FOR EACH ROW EXECUTE FUNCTION sp_dispatch_from_message();

-- The Librarian's proposals (design §5): a thread that should be a page, a page to verify, an orphan, a duplicate.
CREATE TABLE librarian_proposals (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind               text NOT NULL CHECK (kind IN ('thread_to_page', 'verify', 'orphan', 'duplicate', 'broken_link', 'unanswered', 'stale')),
    subject_page_id    uuid REFERENCES pages(id) ON DELETE CASCADE,
    subject_message_id bigint REFERENCES messages(id) ON DELETE CASCADE,
    subject_channel_id bigint REFERENCES channels(id) ON DELETE CASCADE,
    proposed_page_id   uuid REFERENCES pages(id) ON DELETE SET NULL,       -- the draft it made
    title              text NOT NULL,
    reason             text NOT NULL,
    status             text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'dismissed')),
    proposed_by        bigint REFERENCES members(id) ON DELETE SET NULL,  -- the Librarian
    decided_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at         timestamptz,
    dispatch_id        bigint REFERENCES agent_dispatches(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX librarian_proposals_status_idx ON librarian_proposals (status, created_at DESC);
CREATE UNIQUE INDEX librarian_proposals_one_open_per_subject ON librarian_proposals (kind, COALESCE(subject_page_id, '00000000-0000-0000-0000-000000000000'::uuid), COALESCE(subject_message_id, 0)) WHERE status = 'proposed';

-- ---------------------------------------------------------------------------------------------
-- Notifying: one function the writers call. It decides per recipient from channel_members.notify, muted_until, and the
-- member's preferences; the outbox rows for email and text are queued here; the bell row always (unless muted).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_notify(p_member_id bigint, p_kind text, p_title text, p_body text DEFAULT NULL,
                                     p_record_type text DEFAULT NULL, p_record_id bigint DEFAULT NULL, p_record_uuid uuid DEFAULT NULL,
                                     p_channel_id bigint DEFAULT NULL, p_message_id bigint DEFAULT NULL, p_dedupe text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); nid bigint; prefs notification_prefs%ROWTYPE; cm channel_members%ROWTYPE; m members%ROWTYPE;
        away integer; is_away boolean; link text;
BEGIN
    IF p_member_id IS NULL OR p_member_id = me AND p_kind NOT IN ('reminder', 'digest') THEN RETURN NULL; END IF;
    SELECT * INTO m FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
    IF NOT FOUND OR m.member_kind = 'agent' THEN RETURN NULL; END IF;         -- agents are dispatched, not notified
    IF p_channel_id IS NOT NULL THEN
        SELECT * INTO cm FROM channel_members WHERE channel_id = p_channel_id AND member_id = p_member_id;
        IF FOUND THEN
            IF cm.muted_until IS NOT NULL AND cm.muted_until > now() THEN RETURN NULL; END IF;
            IF cm.notify = 'none' THEN RETURN NULL; END IF;
            IF cm.notify = 'mentions' AND p_kind NOT IN ('mention', 'reply', 'dm') THEN RETURN NULL; END IF;
        END IF;
    END IF;
    IF p_dedupe IS NOT NULL AND EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe) THEN RETURN NULL; END IF;
    INSERT INTO notifications (member_id, kind, title, body, record_type, record_id, record_uuid, channel_id, message_id, actor_member_id)
    VALUES (p_member_id, p_kind, left(p_title, 200), left(p_body, 500), p_record_type, p_record_id, p_record_uuid, p_channel_id, p_message_id, me)
    RETURNING id INTO nid;
    SELECT * INTO prefs FROM notification_prefs WHERE member_id = p_member_id;
    IF NOT FOUND THEN
        INSERT INTO notification_prefs (member_id) VALUES (p_member_id) RETURNING * INTO prefs;
    END IF;
    away := COALESCE(prefs.away_minutes, (SELECT away_minutes FROM sp_settings WHERE id = 1));
    is_away := m.last_seen_at IS NULL OR m.last_seen_at < now() - make_interval(mins => away);
    IF prefs.email_enabled AND p_kind = ANY (prefs.kinds) AND NOT prefs.digest AND is_away AND m.email IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, m.email, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':email' END,
                left(p_title, 200), COALESCE(p_body, p_title));
    END IF;
    IF prefs.text_enabled AND p_kind = ANY (prefs.text_kinds) AND is_away THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text' END,
                left(p_title || CASE WHEN p_body IS NULL THEN '' ELSE ': ' || left(p_body, 120) END, 480));
    END IF;
    RETURN nid;
END$$;

-- Who a sent message notifies: mentioned members and departments' members, the thread's participants on a reply, every
-- member of a DM/group DM, @channel/@here/@everyone the whole channel (a person's shout; an agent's is paused by the kernel).
CREATE OR REPLACE FUNCTION sp_message_notify() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c channels%ROWTYPE; who text; excerpt text; rid bigint; ch_name text;
BEGIN
    IF NEW.sent_at IS NULL OR NEW.kind <> 'message' OR NEW.deleted_at IS NOT NULL THEN RETURN NULL; END IF;
    IF TG_OP = 'UPDATE' AND OLD.sent_at IS NOT NULL THEN RETURN NULL; END IF;
    SELECT * INTO c FROM channels WHERE id = NEW.channel_id;
    SELECT display_name INTO who FROM members WHERE id = NEW.author_member_id;
    excerpt := left(NEW.plain_text, 120);
    ch_name := CASE WHEN c.name IS NULL THEN 'a direct message' ELSE '#' || c.name END;
    -- mentions
    FOR rid IN SELECT DISTINCT x FROM (
                 SELECT mm.principal_id AS x FROM message_mentions mm WHERE mm.message_id = NEW.id AND mm.kind = 'member'
                 UNION SELECT dm.member_id FROM message_mentions mm JOIN department_members dm ON dm.department_id = mm.principal_id AND dm.left_at IS NULL
                        WHERE mm.message_id = NEW.id AND mm.kind = 'department'
                 UNION SELECT x FROM message_mentions mm, LATERAL (SELECT cm.member_id AS x FROM channel_members cm WHERE cm.channel_id = NEW.channel_id
                                                                      UNION SELECT y FROM sp_space_member_ids(c.space_id) y WHERE c.kind = 'public') s
                        WHERE mm.message_id = NEW.id AND mm.kind IN ('channel', 'everyone')
                 UNION SELECT x FROM message_mentions mm, LATERAL (SELECT cm.member_id AS x FROM channel_members cm WHERE cm.channel_id = NEW.channel_id
                                                                      UNION SELECT y FROM sp_space_member_ids(c.space_id) y WHERE c.kind = 'public') s
                        JOIN members m ON m.id = s.x AND m.last_seen_at > now() - interval '5 minutes'
                        WHERE mm.message_id = NEW.id AND mm.kind = 'here') t
               WHERE x <> NEW.author_member_id AND sp_in_channel(NEW.channel_id, x) LOOP
        PERFORM sp_notify(rid, 'mention', who || ' mentioned you in ' || ch_name, excerpt, 'message', NEW.id, NULL, NEW.channel_id, NEW.id);
    END LOOP;
    -- a reply notifies the thread's author and earlier repliers
    IF NEW.thread_root_id IS NOT NULL THEN
        FOR rid IN SELECT DISTINCT author_member_id FROM messages WHERE (id = NEW.thread_root_id OR thread_root_id = NEW.thread_root_id)
                    AND author_member_id IS NOT NULL AND author_member_id <> NEW.author_member_id
                    AND author_member_id NOT IN (SELECT principal_id FROM message_mentions WHERE message_id = NEW.id AND kind = 'member') LOOP
            PERFORM sp_notify(rid, 'reply', who || ' replied in ' || ch_name, excerpt, 'message', NEW.id, NULL, NEW.channel_id, NEW.id);
        END LOOP;
    END IF;
    -- a DM or group DM notifies everyone else in it
    IF c.kind IN ('dm', 'group_dm') THEN
        FOR rid IN SELECT cm.member_id FROM channel_members cm WHERE cm.channel_id = NEW.channel_id AND cm.member_id <> NEW.author_member_id
                    AND cm.member_id NOT IN (SELECT principal_id FROM message_mentions WHERE message_id = NEW.id AND kind = 'member') LOOP
            PERFORM sp_notify(rid, 'dm', 'New message from ' || who, excerpt, 'message', NEW.id, NULL, NEW.channel_id, NEW.id);
        END LOOP;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER messages_notify AFTER INSERT OR UPDATE OF sent_at ON messages FOR EACH ROW EXECUTE FUNCTION sp_message_notify();

-- A comment notifies the page's owner, the discussion's participants and the mentioned.
CREATE OR REPLACE FUNCTION sp_comment_notify() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; who text; rid bigint;
BEGIN
    SELECT * INTO p FROM pages WHERE id = NEW.page_id;
    SELECT display_name INTO who FROM members WHERE id = NEW.author_member_id;
    FOR rid IN SELECT DISTINCT x FROM (
                 SELECT cm.principal_id AS x FROM comment_mentions cm WHERE cm.comment_id = NEW.id AND cm.kind = 'member'
                 UNION SELECT dm.member_id FROM comment_mentions cm JOIN department_members dm ON dm.department_id = cm.principal_id AND dm.left_at IS NULL WHERE cm.comment_id = NEW.id AND cm.kind = 'department'
                 UNION SELECT p.owner_member_id UNION SELECT p.wiki_owner_member_id
                 UNION SELECT c.author_member_id FROM comments c WHERE NEW.parent_comment_id IS NOT NULL AND (c.id = NEW.parent_comment_id OR c.parent_comment_id = NEW.parent_comment_id)) t
               WHERE x IS NOT NULL AND x <> NEW.author_member_id AND sp_level_rank(sp_page_level(NEW.page_id, x)) >= 1 LOOP
        PERFORM sp_notify(rid, 'comment', who || ' commented on ' || COALESCE(NULLIF(p.plain_title, ''), 'a page'), left(NEW.plain_text, 120), NULL, NULL, NEW.page_id);
    END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER comments_notify AFTER INSERT ON comments FOR EACH ROW EXECUTE FUNCTION sp_comment_notify();

-- A page shared to a member or a department notifies them.
CREATE OR REPLACE FUNCTION sp_share_notify() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; who text; rid bigint;
BEGIN
    IF NEW.level = 'none' THEN RETURN NULL; END IF;
    SELECT * INTO p FROM pages WHERE id = NEW.page_id;
    SELECT display_name INTO who FROM members WHERE id = app_current_member_id();
    IF NEW.principal_kind IN ('member', 'guest') THEN
        PERFORM sp_notify(NEW.principal_id, 'share', COALESCE(who, 'Someone') || ' shared "' || COALESCE(NULLIF(p.plain_title, ''), 'Untitled') || '" with you', 'You can ' || NEW.level, NULL, NULL, NEW.page_id);
    ELSIF NEW.principal_kind = 'department' THEN
        FOR rid IN SELECT dm.member_id FROM department_members dm WHERE dm.department_id = NEW.principal_id AND dm.left_at IS NULL LOOP
            PERFORM sp_notify(rid, 'share', COALESCE(who, 'Someone') || ' shared "' || COALESCE(NULLIF(p.plain_title, ''), 'Untitled') || '" with your department', 'You can ' || NEW.level, NULL, NULL, NEW.page_id);
        END LOOP;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER page_permissions_notify AFTER INSERT ON page_permissions FOR EACH ROW EXECUTE FUNCTION sp_share_notify();

GRANT SELECT, INSERT, UPDATE, DELETE ON notifications, notification_prefs, notification_outbox, attachments, agent_dispatches, librarian_proposals TO spaces_rw;
GRANT EXECUTE ON FUNCTION sp_can_see_attachment(bigint) TO spaces_rw, spaces_records_ro, spaces_activity_ro;
REVOKE ALL ON FUNCTION sp_notify(bigint, text, text, text, text, bigint, uuid, bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sp_notify(bigint, text, text, text, text, bigint, uuid, bigint, bigint, text) TO spaces_rw;

COMMIT;
