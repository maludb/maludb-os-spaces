-- 016: TEMPLATES (seeded), IMPORTS and EXPORTS, VERSIONS (save, restore), and THE WORKER's passes — scheduled messages,
-- reminders, snapshots, retention, the trash, wiki expiry, dispatch pick-up (design §6, §9, §14; D9, D12).
--
-- The worker is one timer a minute (bin/worker.php) calling these in order; every pass is idempotent and advisory-locked
-- by the caller. Nothing here sends mail or texts — the outbox rows are the worker's to deliver (MaluMail, K6).
--
-- THE ESTATE'S DATA MODEL (design §6.1): imports and exports are new (no sibling keeps them); the worker's shape is the
-- siblings' (one pass a minute, advisory lock, `--only`).
BEGIN;

CREATE TABLE imports (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('markdown_zip', 'notion_zip', 'csv', 'markdown')),
    file_name     text NOT NULL,
    target_space_id bigint REFERENCES spaces(id) ON DELETE SET NULL,
    target_page_id  uuid REFERENCES pages(id) ON DELETE SET NULL,
    target_database_id uuid REFERENCES databases(id) ON DELETE SET NULL,
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    pages_made    integer NOT NULL DEFAULT 0,
    rows_made     integer NOT NULL DEFAULT 0,
    blocks_made   integer NOT NULL DEFAULT 0,
    unsupported   integer NOT NULL DEFAULT 0,
    log           text,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz
);
CREATE INDEX imports_status_idx ON imports (status, created_at);

CREATE TABLE exports (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('page', 'space', 'channel', 'database', 'all')),
    format        text NOT NULL CHECK (format IN ('md', 'html', 'csv', 'json', 'zip')),
    page_id       uuid REFERENCES pages(id) ON DELETE SET NULL,
    space_id      bigint REFERENCES spaces(id) ON DELETE SET NULL,
    channel_id    bigint REFERENCES channels(id) ON DELETE SET NULL,
    include_subpages boolean NOT NULL DEFAULT true,
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    storage_path  text,
    byte_size     bigint,
    item_count    integer NOT NULL DEFAULT 0,
    log           text,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    expires_at    timestamptz NOT NULL DEFAULT now() + interval '7 days',
    downloaded_at timestamptz
);
CREATE INDEX exports_owner_idx ON exports (created_by, created_at DESC);
CREATE INDEX exports_expiry_idx ON exports (expires_at) WHERE storage_path IS NOT NULL;

-- One row per worker pass (the siblings' shape): what each step did.
CREATE TABLE worker_passes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    started_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    steps         jsonb NOT NULL DEFAULT '{}'::jsonb,
    error         text
);

-- ---------------------------------------------------------------------------------------------
-- Versions.
-- ---------------------------------------------------------------------------------------------
-- Save a snapshot now (manual, before a restore, on lock, on import, by the interval pass). Returns the version number.
CREATE OR REPLACE FUNCTION sp_version_save(p_page_id uuid, p_reason text DEFAULT 'manual') RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; vn integer; snap jsonb;
BEGIN
    SELECT * INTO p FROM pages WHERE id = p_page_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'The page does not exist' USING ERRCODE = 'P0001'; END IF;
    IF p_reason = 'interval' AND p.snapshot_rev = p.content_rev THEN RETURN p.version_no; END IF;   -- nothing changed since the last one
    snap := sp_page_snapshot(p_page_id);
    vn := p.version_no + 1;
    INSERT INTO page_versions (page_id, version_no, content_rev, reason, snapshot, plain_text, saved_by)
    VALUES (p_page_id, vn, p.content_rev, p_reason, snap, left(sp_page_search_body(p_page_id), 20000), app_current_member_id());
    UPDATE pages SET version_no = vn, snapshot_rev = content_rev WHERE id = p_page_id;
    RETURN vn;
END$$;

-- Rebuild a page's blocks from a snapshot's tree (new block ids keep the snapshot's where possible).
CREATE OR REPLACE FUNCTION sp_blocks_from_snapshot(p_page_id uuid, p_blocks jsonb, p_parent uuid) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b jsonb; bid uuid; n integer := 0;
BEGIN
    FOR b IN SELECT x FROM jsonb_array_elements(COALESCE(p_blocks, '[]'::jsonb)) x LOOP
        bid := COALESCE((b->>'id')::uuid, gen_random_uuid());
        IF EXISTS (SELECT 1 FROM blocks WHERE id = bid) THEN bid := gen_random_uuid(); END IF;
        INSERT INTO blocks (id, page_id, parent_block_id, type, position, content, synced_from)
        VALUES (bid, p_page_id, p_parent, b->>'type', COALESCE(b->>'position', 'a0'), COALESCE(b->'content', '{}'::jsonb),
                CASE WHEN b->>'type' = 'synced_block' AND (b->>'synced_from') IS NOT NULL AND EXISTS (SELECT 1 FROM blocks o WHERE o.id = (b->>'synced_from')::uuid) THEN (b->>'synced_from')::uuid END);
        n := n + 1;
        IF b->>'type' NOT IN ('synced_block') OR (b->>'synced_from') IS NULL THEN
            n := n + sp_blocks_from_snapshot(p_page_id, b->'children', bid);
        END IF;
    END LOOP;
    RETURN n;
END$$;

-- Restore a version: snapshot the present first, then the title, icon, properties and the whole tree from the version.
-- Child pages are not touched (they are their own pages); the edges to them are re-created only for pages still live.
CREATE OR REPLACE FUNCTION sp_version_restore(p_version_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v page_versions%ROWTYPE; p pages%ROWTYPE; snap jsonb; n integer; vn integer;
BEGIN
    SELECT * INTO v FROM page_versions WHERE id = p_version_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'The version does not exist' USING ERRCODE = 'P0001'; END IF;
    SELECT * INTO p FROM pages WHERE id = v.page_id FOR UPDATE;
    IF p.archived_at IS NOT NULL THEN RAISE EXCEPTION 'Restore the page from the trash first' USING ERRCODE = 'P0001'; END IF;
    IF p.is_locked THEN RAISE EXCEPTION 'Page "%" is locked: unlock it first', p.plain_title USING ERRCODE = 'P0001'; END IF;
    PERFORM sp_version_save(v.page_id, 'before_restore');
    snap := v.snapshot;
    -- the edges to live child pages are kept out of the wipe: child_page blocks whose page is live stay
    PERFORM set_config('app.sp_purging', '1', true);
    DELETE FROM blocks b WHERE b.page_id = v.page_id
       AND NOT (b.type IN ('child_page', 'child_database') AND EXISTS (SELECT 1 FROM pages c WHERE c.id = COALESCE((b.content->>'page_id')::uuid, (b.content->>'database_id')::uuid) AND c.archived_at IS NULL));
    PERFORM set_config('app.sp_purging', '', true);
    -- the snapshot's edge blocks for pages that still exist are skipped when an edge already stands
    n := sp_blocks_from_snapshot(v.page_id, (SELECT COALESCE(jsonb_agg(x), '[]'::jsonb) FROM jsonb_array_elements(COALESCE(snap->'blocks', '[]'::jsonb)) x
                                              WHERE NOT (x->>'type' IN ('child_page', 'child_database') AND EXISTS (SELECT 1 FROM blocks e WHERE e.page_id = v.page_id AND e.type = x->>'type'
                                                         AND COALESCE((e.content->>'page_id'), (e.content->>'database_id')) = COALESCE((x->'content'->>'page_id'), (x->'content'->>'database_id'))))), NULL);
    UPDATE pages SET title = COALESCE(snap->'title', title), icon = snap->>'icon', properties = COALESCE(snap->'properties', properties) WHERE id = v.page_id;
    vn := sp_version_save(v.page_id, 'restore');
    RETURN vn;
END$$;

-- Two versions' texts, for the diff screen (the diff itself is PHP's — line by line on the Markdown).
CREATE OR REPLACE FUNCTION sp_version_markdown(p_version_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v page_versions%ROWTYPE; out text := ''; k jsonb; n integer := 0;
BEGIN
    SELECT * INTO v FROM page_versions WHERE id = p_version_id;
    IF NOT FOUND OR NOT sp_can_see_page(v.page_id) THEN RETURN NULL; END IF;
    out := '# ' || COALESCE(NULLIF(sp_rich_text_markdown(v.snapshot->'title'), ''), 'Untitled') || E'\n\n';
    FOR k IN SELECT x FROM jsonb_array_elements(COALESCE(v.snapshot->'blocks', '[]'::jsonb)) x LOOP
        IF k->>'type' = 'numbered_list_item' THEN n := n + 1; ELSE n := 0; END IF;
        out := out || sp_block_markdown(k, 0, n);
    END LOOP;
    RETURN regexp_replace(out, E'\n{3,}', E'\n\n', 'g');
END$$;

-- ---------------------------------------------------------------------------------------------
-- The worker's passes. Each returns what it did, for the pass row.
-- ---------------------------------------------------------------------------------------------
-- Scheduled messages whose time has come are sent (the triggers count, dispatch and notify on sent_at).
CREATE OR REPLACE FUNCTION sp_pass_scheduled() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    PERFORM set_config('app.sp_worker', '1', true);
    UPDATE messages SET sent_at = now() WHERE sent_at IS NULL AND scheduled_for IS NOT NULL AND scheduled_for <= now() AND deleted_at IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    PERFORM set_config('app.sp_worker', '', true);
    RETURN n;
END$$;

-- Reminders fallen due become a bell notice (and the outbox, by the member's choices).
CREATE OR REPLACE FUNCTION sp_pass_reminders() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r reminders%ROWTYPE; n integer := 0; ttl text; cid bigint;
BEGIN
    FOR r IN SELECT * FROM reminders WHERE done_at IS NULL AND notified_at IS NULL AND remind_at <= now() ORDER BY remind_at LIMIT 200 LOOP
        cid := NULL;
        IF r.message_id IS NOT NULL THEN
            SELECT channel_id, 'Reminder: ' || left(plain_text, 100) INTO cid, ttl FROM messages WHERE id = r.message_id;
        ELSIF r.page_id IS NOT NULL THEN
            SELECT 'Reminder: ' || COALESCE(NULLIF(plain_title, ''), 'a page') INTO ttl FROM pages WHERE id = r.page_id;
        END IF;
        ttl := COALESCE(ttl, 'Reminder: ' || COALESCE(r.text, ''));
        PERFORM sp_notify(r.member_id, 'reminder', ttl, r.text, 'reminder', r.id, r.page_id, cid, r.message_id);
        UPDATE reminders SET notified_at = now() WHERE id = r.id;
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;

-- Pages edited since their last snapshot, quiet for the interval: one snapshot each.
CREATE OR REPLACE FUNCTION sp_pass_snapshots(p_limit integer DEFAULT 200) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; n integer := 0; mins integer := sp_setting_int('version_snapshot_minutes');
BEGIN
    FOR r IN SELECT p.id FROM pages p WHERE p.archived_at IS NULL AND p.content_rev > p.snapshot_rev
              AND p.last_edited_at < now() - make_interval(mins => mins) ORDER BY p.last_edited_at LIMIT p_limit LOOP
        PERFORM sp_version_save(r.id, 'interval'); n := n + 1;
    END LOOP;
    RETURN n;
END$$;

-- Versions older than the retention go, the newest of each page always kept.
CREATE OR REPLACE FUNCTION sp_pass_version_prune() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer; days integer := sp_setting_int('version_retention_days');
BEGIN
    DELETE FROM page_versions v WHERE v.created_at < now() - make_interval(days => days)
       AND v.id <> (SELECT max(x.id) FROM page_versions x WHERE x.page_id = v.page_id);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- Retention: a channel with retention_days loses its messages older than that (hard delete — D9: never by default).
CREATE OR REPLACE FUNCTION sp_pass_retention() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    PERFORM set_config('app.sp_worker', '1', true);
    DELETE FROM messages m USING channels c
     WHERE c.id = m.channel_id AND c.retention_days IS NOT NULL AND m.created_at < now() - make_interval(days => c.retention_days);
    GET DIAGNOSTICS n = ROW_COUNT;
    PERFORM set_config('app.sp_worker', '', true);
    RETURN n;
END$$;

-- The trash: pages archived longer than the retention are purged for good.
CREATE OR REPLACE FUNCTION sp_pass_trash_purge() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; n integer := 0; days integer := sp_setting_int('trash_retention_days');
BEGIN
    FOR r IN SELECT id FROM pages WHERE archived_at IS NOT NULL AND archived_via IS NULL AND archived_at < now() - make_interval(days => days) LOOP
        n := n + sp_page_purge(r.id);
    END LOOP;
    RETURN n;
END$$;

-- Wiki: verified pages past their date become expired (a badge, not a wall — D12); the owner is told once.
CREATE OR REPLACE FUNCTION sp_pass_wiki_expire() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; n integer := 0;
BEGIN
    FOR r IN SELECT id, plain_title, wiki_owner_member_id FROM pages WHERE verification_state = 'verified' AND verify_until IS NOT NULL AND verify_until < now() AND archived_at IS NULL LOOP
        UPDATE pages SET verification_state = 'expired' WHERE id = r.id;
        IF r.wiki_owner_member_id IS NOT NULL THEN
            PERFORM sp_notify(r.wiki_owner_member_id, 'verification', 'Verification expired: ' || COALESCE(NULLIF(r.plain_title, ''), 'a page'), 'Review it and verify it again, or hand it to someone else.', NULL, NULL, r.id, NULL, NULL, 'wiki_expired:' || r.id);
        END IF;
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;

-- Exports past their life: the rows are kept, the files are the worker's to remove — returns the paths to delete.
CREATE OR REPLACE FUNCTION sp_pass_exports_expire() RETURNS SETOF text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        UPDATE exports SET storage_path = NULL WHERE storage_path IS NOT NULL AND expires_at < now() RETURNING storage_path;
END$$;

-- The dispatches ready for a chat turn (the worker calls the kernel for each): the message, its channel, the thread context.
CREATE OR REPLACE FUNCTION sp_dispatches_due(p_limit integer DEFAULT 10)
    RETURNS TABLE (dispatch_id bigint, agent_member_id bigint, acting_member_id bigint, kind text, channel_id bigint, channel_kind text, channel_name text,
                   message_id bigint, thread_root_id bigint, conversation_id text, utterance text, context text, attempts integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.agent_member_id, d.acting_member_id, d.kind, d.channel_id, c.kind, c.name, m.id, COALESCE(m.thread_root_id, m.id), d.conversation_id,
           sp_rich_text_markdown(m.body), sp_thread_context(COALESCE(m.thread_root_id, m.id), 12), d.attempts
      FROM agent_dispatches d JOIN messages m ON m.id = d.record_id AND d.record_type = 'message' JOIN channels c ON c.id = m.channel_id
     WHERE d.status = 'sent' AND d.run_id IS NULL AND d.next_attempt_at <= now() AND d.attempts <= 5 AND m.deleted_at IS NULL
     ORDER BY d.created_at LIMIT greatest(1, p_limit);
$$;

-- The worker's record of a dispatch: a run started (202), answered (the reply posted as the agent, in the thread), refused, failed.
CREATE OR REPLACE FUNCTION sp_dispatch_record(p_dispatch_id bigint, p_status text, p_run_id bigint DEFAULT NULL, p_request_id text DEFAULT NULL,
                                              p_reply jsonb DEFAULT NULL, p_detail text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d agent_dispatches%ROWTYPE; m messages%ROWTYPE; rid bigint;
BEGIN
    SELECT * INTO d FROM agent_dispatches WHERE id = p_dispatch_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'The dispatch does not exist' USING ERRCODE = 'P0001'; END IF;
    SELECT * INTO m FROM messages WHERE id = d.record_id;
    PERFORM set_config('app.sp_worker', '1', true);
    IF p_status = 'running' THEN
        UPDATE agent_dispatches SET run_id = p_run_id, request_id = COALESCE(p_request_id, request_id) WHERE id = p_dispatch_id;
        IF d.pending_message_id IS NULL THEN
            INSERT INTO messages (channel_id, thread_root_id, author_member_id, kind, body, agent_run_id)
            VALUES (m.channel_id, COALESCE(m.thread_root_id, m.id), d.agent_member_id, 'agent_pending', sp_rich_text('…'), p_run_id) RETURNING id INTO rid;
            UPDATE agent_dispatches SET pending_message_id = rid WHERE id = p_dispatch_id;
        END IF;
    ELSIF p_status = 'answered' THEN
        IF d.pending_message_id IS NOT NULL THEN
            UPDATE messages SET kind = 'message', body = COALESCE(p_reply, sp_rich_text('(no reply)')), agent_run_id = COALESCE(p_run_id, agent_run_id), sent_at = now() WHERE id = d.pending_message_id;
            rid := d.pending_message_id;
        ELSE
            INSERT INTO messages (channel_id, thread_root_id, author_member_id, kind, body, agent_run_id)
            VALUES (m.channel_id, COALESCE(m.thread_root_id, m.id), d.agent_member_id, 'message', COALESCE(p_reply, sp_rich_text('(no reply)')), p_run_id) RETURNING id INTO rid;
        END IF;
        UPDATE agent_dispatches SET status = 'answered', run_id = COALESCE(p_run_id, run_id), request_id = COALESCE(p_request_id, request_id),
               reply_message_id = rid, reply_excerpt = left(sp_rich_text_plain(p_reply), 200), answered_at = now(), detail = p_detail, pending_message_id = NULL
         WHERE id = p_dispatch_id;
        IF m.author_member_id IS NOT NULL THEN
            PERFORM sp_notify(m.author_member_id, 'agent_replied', (SELECT display_name FROM members WHERE id = d.agent_member_id) || ' replied', left(sp_rich_text_plain(p_reply), 120), 'message', rid, NULL, m.channel_id, rid);
        END IF;
    ELSIF p_status IN ('refused', 'failed', 'awaiting_approval') THEN
        IF d.pending_message_id IS NOT NULL AND p_status <> 'awaiting_approval' THEN
            DELETE FROM messages WHERE id = d.pending_message_id;
        END IF;
        UPDATE agent_dispatches SET status = CASE WHEN p_status = 'failed' AND d.attempts < 5 THEN 'sent' ELSE p_status END,
               attempts = attempts + 1, next_attempt_at = now() + make_interval(mins => least(60, 2 ^ d.attempts)::integer), run_id = NULL,
               detail = p_detail, pending_message_id = CASE WHEN p_status = 'awaiting_approval' THEN pending_message_id END
         WHERE id = p_dispatch_id;
        rid := NULL;
    END IF;
    PERFORM set_config('app.sp_worker', '', true);
    RETURN rid;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Seeded templates (design §6): page templates and database templates in the default space, is_template = true, so they
-- show in the Templates gallery and never in a sidebar or a search. template_apply = sp_page_duplicate.
-- ---------------------------------------------------------------------------------------------
DO $$
DECLARE gen bigint; pid uuid; did uuid; b uuid;
BEGIN
    SELECT id INTO gen FROM spaces WHERE is_default;
    IF gen IS NULL THEN RETURN; END IF;
    PERFORM set_config('app.sp_bulk', '1', true);

    pid := sp_page_create(gen, NULL, sp_rich_text('Meeting notes'), 'page', '📝'); UPDATE pages SET is_template = true WHERE id = pid;
    b := sp_block_insert(pid, NULL, NULL, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Attendees')));
    b := sp_block_insert(pid, NULL, b, 'bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('@ ')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Agenda')));
    b := sp_block_insert(pid, NULL, b, 'numbered_list_item', jsonb_build_object('rich_text', sp_rich_text('')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Notes')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', '[]'::jsonb));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Action items')));
    b := sp_block_insert(pid, NULL, b, 'to_do', jsonb_build_object('rich_text', sp_rich_text(''), 'checked', false));

    pid := sp_page_create(gen, NULL, sp_rich_text('Decision record'), 'page', '⚖️'); UPDATE pages SET is_template = true WHERE id = pid;
    b := sp_block_insert(pid, NULL, NULL, 'callout', jsonb_build_object('rich_text', sp_rich_text('Status: proposed · Decided by: · Date:'), 'icon', jsonb_build_object('emoji', '📌')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Context')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', '[]'::jsonb));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Decision')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', '[]'::jsonb));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Options considered')));
    b := sp_block_insert(pid, NULL, b, 'bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Consequences')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', '[]'::jsonb));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Where it was discussed')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Link the thread here.')));

    pid := sp_page_create(gen, NULL, sp_rich_text('Project brief'), 'page', '🎯'); UPDATE pages SET is_template = true WHERE id = pid;
    b := sp_block_insert(pid, NULL, NULL, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Why')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', '[]'::jsonb));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('What done looks like')));
    b := sp_block_insert(pid, NULL, b, 'bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Who')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Owner: · Team: · Stakeholders:')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Milestones')));
    b := sp_block_insert(pid, NULL, b, 'to_do', jsonb_build_object('rich_text', sp_rich_text(''), 'checked', false));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Risks')));
    b := sp_block_insert(pid, NULL, b, 'bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('')));

    pid := sp_page_create(gen, NULL, sp_rich_text('Runbook'), 'page', '🛠️'); UPDATE pages SET is_template = true WHERE id = pid;
    b := sp_block_insert(pid, NULL, NULL, 'callout', jsonb_build_object('rich_text', sp_rich_text('When to use this runbook, and who may run it.'), 'icon', jsonb_build_object('emoji', '⚠️')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Before you start')));
    b := sp_block_insert(pid, NULL, b, 'to_do', jsonb_build_object('rich_text', sp_rich_text(''), 'checked', false));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Steps')));
    b := sp_block_insert(pid, NULL, b, 'numbered_list_item', jsonb_build_object('rich_text', sp_rich_text('')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('If it goes wrong')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', '[]'::jsonb));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Last verified')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', sp_rich_text('Verify this page after each run.')));

    pid := sp_page_create(gen, NULL, sp_rich_text('Weekly update'), 'page', '📅'); UPDATE pages SET is_template = true WHERE id = pid;
    b := sp_block_insert(pid, NULL, NULL, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Done this week')));
    b := sp_block_insert(pid, NULL, b, 'bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Next week')));
    b := sp_block_insert(pid, NULL, b, 'bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Blocked on')));
    b := sp_block_insert(pid, NULL, b, 'bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('')));

    pid := sp_page_create(gen, NULL, sp_rich_text('1:1'), 'page', '☕'); UPDATE pages SET is_template = true WHERE id = pid;
    b := sp_block_insert(pid, NULL, NULL, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('How are things')));
    b := sp_block_insert(pid, NULL, b, 'paragraph', jsonb_build_object('rich_text', '[]'::jsonb));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Topics')));
    b := sp_block_insert(pid, NULL, b, 'bulleted_list_item', jsonb_build_object('rich_text', sp_rich_text('')));
    b := sp_block_insert(pid, NULL, b, 'heading_2', jsonb_build_object('rich_text', sp_rich_text('Follow-ups')));
    b := sp_block_insert(pid, NULL, b, 'to_do', jsonb_build_object('rich_text', sp_rich_text(''), 'checked', false));

    -- database templates
    did := sp_database_create(gen, NULL, sp_rich_text('Vendor list'), jsonb_build_object(
        'title', '{"id":"title","name":"Vendor","type":"title"}'::jsonb,
        'Category', '{"id":"category","name":"Category","type":"select","select":{"options":[{"name":"Software","color":"blue"},{"name":"Hosting","color":"green"},{"name":"Services","color":"orange"},{"name":"Other","color":"gray"}]}}'::jsonb,
        'Owner', '{"id":"owner","name":"Owner","type":"people"}'::jsonb,
        'Contact', '{"id":"contact","name":"Contact","type":"email"}'::jsonb,
        'Website', '{"id":"website","name":"Website","type":"url"}'::jsonb,
        'Renewal', '{"id":"renewal","name":"Renewal","type":"date"}'::jsonb,
        'Monthly cost', '{"id":"cost","name":"Monthly cost","type":"number","number":{"format":"dollar"}}'::jsonb,
        'Notes', '{"id":"notes","name":"Notes","type":"rich_text"}'::jsonb));
    UPDATE pages SET is_template = true, icon = '🏷️' WHERE id = did;

    did := sp_database_create(gen, NULL, sp_rich_text('Decision log'), jsonb_build_object(
        'title', '{"id":"title","name":"Decision","type":"title"}'::jsonb,
        'Status', '{"id":"status","name":"Status","type":"status","status":{"options":[{"name":"Proposed","color":"yellow"},{"name":"Decided","color":"green"},{"name":"Superseded","color":"gray"}]}}'::jsonb,
        'Decided on', '{"id":"decided_on","name":"Decided on","type":"date"}'::jsonb,
        'Decided by', '{"id":"decided_by","name":"Decided by","type":"people"}'::jsonb,
        'Area', '{"id":"area","name":"Area","type":"multi_select","multi_select":{"options":[{"name":"Product"},{"name":"Engineering"},{"name":"Finance"},{"name":"People"},{"name":"Operations"}]}}'::jsonb,
        'Ref', '{"id":"ref","name":"Ref","type":"unique_id","unique_id":{"prefix":"DEC"}}'::jsonb));
    UPDATE pages SET is_template = true, icon = '⚖️' WHERE id = did;

    did := sp_database_create(gen, NULL, sp_rich_text('Reading list'), jsonb_build_object(
        'title', '{"id":"title","name":"Title","type":"title"}'::jsonb,
        'Link', '{"id":"link","name":"Link","type":"url"}'::jsonb,
        'Type', '{"id":"type","name":"Type","type":"select","select":{"options":[{"name":"Article"},{"name":"Book"},{"name":"Paper"},{"name":"Video"}]}}'::jsonb,
        'Status', '{"id":"status","name":"Status","type":"status","status":{"options":[{"name":"To read"},{"name":"Reading"},{"name":"Read"}]}}'::jsonb,
        'Added by', '{"id":"added_by","name":"Added by","type":"created_by"}'::jsonb,
        'Rating', '{"id":"rating","name":"Rating","type":"select","select":{"options":[{"name":"★"},{"name":"★★"},{"name":"★★★"},{"name":"★★★★"},{"name":"★★★★★"}]}}'::jsonb));
    UPDATE pages SET is_template = true, icon = '📚' WHERE id = did;

    did := sp_database_create(gen, NULL, sp_rich_text('Content calendar'), jsonb_build_object(
        'title', '{"id":"title","name":"Piece","type":"title"}'::jsonb,
        'Channel', '{"id":"channel","name":"Channel","type":"select","select":{"options":[{"name":"Blog"},{"name":"Newsletter"},{"name":"Social"},{"name":"Press"}]}}'::jsonb,
        'Status', '{"id":"status","name":"Status","type":"status","status":{"options":[{"name":"Idea"},{"name":"Drafting"},{"name":"Review"},{"name":"Scheduled"},{"name":"Published"}]}}'::jsonb,
        'Publish on', '{"id":"publish_on","name":"Publish on","type":"date"}'::jsonb,
        'Writer', '{"id":"writer","name":"Writer","type":"people"}'::jsonb,
        'Draft', '{"id":"draft","name":"Draft","type":"files"}'::jsonb));
    UPDATE pages SET is_template = true, icon = '🗓️' WHERE id = did;
    INSERT INTO database_views (database_id, name, layout, calendar_by, position) VALUES (did, 'Calendar', 'calendar', 'Publish on', 'a1');
    INSERT INTO database_views (database_id, name, layout, group_by, position) VALUES (did, 'Board', 'board', 'Status', 'a2');

    PERFORM set_config('app.sp_bulk', '', true);
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON imports, exports, worker_passes TO spaces_rw;
GRANT EXECUTE ON FUNCTION sp_version_markdown(bigint) TO spaces_rw, spaces_records_ro;
REVOKE ALL ON FUNCTION sp_version_save(uuid, text), sp_version_restore(bigint), sp_blocks_from_snapshot(uuid, jsonb, uuid), sp_pass_scheduled(), sp_pass_reminders(),
    sp_pass_snapshots(integer), sp_pass_version_prune(), sp_pass_retention(), sp_pass_trash_purge(), sp_pass_wiki_expire(), sp_pass_exports_expire(),
    sp_dispatches_due(integer), sp_dispatch_record(bigint, text, bigint, text, jsonb, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sp_version_save(uuid, text), sp_version_restore(bigint), sp_blocks_from_snapshot(uuid, jsonb, uuid), sp_pass_scheduled(), sp_pass_reminders(),
    sp_pass_snapshots(integer), sp_pass_version_prune(), sp_pass_retention(), sp_pass_trash_purge(), sp_pass_wiki_expire(), sp_pass_exports_expire(),
    sp_dispatches_due(integer), sp_dispatch_record(bigint, text, bigint, text, jsonb, text) TO spaces_rw;

COMMIT;
