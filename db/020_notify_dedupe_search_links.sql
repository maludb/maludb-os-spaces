-- 020: A NOTICE'S DEDUPE KEY NOW HOLDS, and `has:link` FINDS LINKS (both found by slice 6, notifications, search and the wiki's reports).
--
-- 1. db/012's sp_notify() checked `notification_outbox.dedupe_key = p_dedupe`, but the outbox rows it queues carry `p_dedupe || ':email'` / `':text'`, and a
-- member who is not away or has both channels off queues no outbox row at all. So a second call with the same key was NOT a no-op: it wrote a second bell
-- row, and for an away member the second outbox insert hit the unique index and raised 23505 (a 500). The wiki nudge ("a second nudge the same week queues
-- nothing", spec notify-search-wiki) and db/016's `wiki_expired:<page>` rely on the key holding.
-- The key is now kept where it always applies — the bell row: notifications gains `dedupe_key` with a unique index on (member_id, dedupe_key), sp_notify()
-- returns NULL when that member already holds the key, and the outbox keys carry the member too (`<key>:<channel>:<member>`) so two recipients of one key
-- never collide. A second, smaller defect rides along: the text row's body now starts "Spaces: " as the slice 6 spec says (db/012 left the prefix out). The
-- signature is unchanged (CREATE OR REPLACE keeps its grants); everything else in the function is db/012's, word for word.
--
-- 2. db/013 decided `has_link` with `body::text LIKE '%"link":"http%'`, but jsonb::text writes `"link": {"url": "…"}` — a colon, a space, an object — so the pattern
-- never matched and `has:link` found nothing, whatever was typed. The three index functions are redefined with a test that matches what the format really holds
-- (a run's `"link": {…}`, an `"href": "http…"`, or an http(s) address in the plain text), and every page, message and comment already indexed is indexed again.
--
-- Additive: one column, one index, four functions replaced in place.
BEGIN;

ALTER TABLE notifications ADD COLUMN dedupe_key text;
CREATE UNIQUE INDEX notifications_dedupe ON notifications (member_id, dedupe_key) WHERE dedupe_key IS NOT NULL;

CREATE OR REPLACE FUNCTION sp_notify(p_member_id bigint, p_kind text, p_title text, p_body text DEFAULT NULL,
                                     p_record_type text DEFAULT NULL, p_record_id bigint DEFAULT NULL, p_record_uuid uuid DEFAULT NULL,
                                     p_channel_id bigint DEFAULT NULL, p_message_id bigint DEFAULT NULL, p_dedupe text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); nid bigint; prefs notification_prefs%ROWTYPE; cm channel_members%ROWTYPE; m members%ROWTYPE;
        away integer; is_away boolean;
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
    IF p_dedupe IS NOT NULL AND (EXISTS (SELECT 1 FROM notifications WHERE member_id = p_member_id AND dedupe_key = p_dedupe)
                                 OR EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe)) THEN RETURN NULL; END IF;
    INSERT INTO notifications (member_id, kind, title, body, record_type, record_id, record_uuid, channel_id, message_id, actor_member_id, dedupe_key)
    VALUES (p_member_id, p_kind, left(p_title, 200), left(p_body, 500), p_record_type, p_record_id, p_record_uuid, p_channel_id, p_message_id, me, p_dedupe)
    RETURNING id INTO nid;
    SELECT * INTO prefs FROM notification_prefs WHERE member_id = p_member_id;
    IF NOT FOUND THEN
        INSERT INTO notification_prefs (member_id) VALUES (p_member_id) RETURNING * INTO prefs;
    END IF;
    away := COALESCE(prefs.away_minutes, (SELECT away_minutes FROM sp_settings WHERE id = 1));
    is_away := m.last_seen_at IS NULL OR m.last_seen_at < now() - make_interval(mins => away);
    IF prefs.email_enabled AND p_kind = ANY (prefs.kinds) AND NOT prefs.digest AND is_away AND m.email IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, m.email, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':email:' || p_member_id END,
                left(p_title, 200), COALESCE(p_body, p_title));
    END IF;
    IF prefs.text_enabled AND p_kind = ANY (prefs.text_kinds) AND is_away THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text:' || p_member_id END,
                left('Spaces: ' || p_title || CASE WHEN p_body IS NULL THEN '' ELSE ': ' || left(p_body, 120) END, 480));
    END IF;
    RETURN nid;
END$$;


CREATE OR REPLACE FUNCTION sp_index_page(p_page_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; body text; kind text;
BEGIN
    SELECT * INTO p FROM pages WHERE id = p_page_id;
    IF NOT FOUND OR p.archived_at IS NOT NULL OR p.is_template THEN
        DELETE FROM search_index WHERE entity_uuid = p_page_id AND entity_kind IN ('page', 'row');
        RETURN;
    END IF;
    kind := CASE WHEN p.parent_database_id IS NOT NULL THEN 'row' ELSE 'page' END;
    body := sp_page_search_body(p_page_id);
    DELETE FROM search_index WHERE entity_uuid = p_page_id AND entity_kind <> kind;
    INSERT INTO search_index (entity_kind, entity_uuid, page_id, space_id, author_member_id, title, body, tsv, has_link, has_file, occurred_at, updated_at)
    VALUES (kind, p_page_id, p_page_id, p.space_id, p.last_edited_by, p.plain_title, body, sp_search_tsv(p.plain_title, body),
            EXISTS (SELECT 1 FROM blocks b WHERE b.page_id = p_page_id AND (b.type IN ('bookmark', 'embed', 'link_to_page', 'link_preview') OR b.content::text ~ '"link": *\{' OR b.content::text ~ '"href": *"https?:' OR b.plain_text ~* 'https?://')),
            EXISTS (SELECT 1 FROM blocks b WHERE b.page_id = p_page_id AND b.type IN ('image', 'file', 'pdf', 'video', 'audio')),
            p.last_edited_at, now())
    ON CONFLICT (entity_kind, entity_uuid) WHERE entity_uuid IS NOT NULL DO UPDATE
       SET space_id = EXCLUDED.space_id, author_member_id = EXCLUDED.author_member_id, title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv,
           has_link = EXCLUDED.has_link, has_file = EXCLUDED.has_file, occurred_at = EXCLUDED.occurred_at, updated_at = now();
END$$;

CREATE OR REPLACE FUNCTION sp_index_message(p_message_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE m messages%ROWTYPE; c channels%ROWTYPE;
BEGIN
    SELECT * INTO m FROM messages WHERE id = p_message_id;
    IF NOT FOUND OR m.deleted_at IS NOT NULL OR m.sent_at IS NULL OR m.kind <> 'message' THEN
        DELETE FROM search_index WHERE entity_kind = 'message' AND entity_id = p_message_id;
        RETURN;
    END IF;
    SELECT * INTO c FROM channels WHERE id = m.channel_id;
    INSERT INTO search_index (entity_kind, entity_id, space_id, channel_id, author_member_id, title, body, tsv, has_link, has_file, occurred_at, updated_at)
    VALUES ('message', p_message_id, c.space_id, m.channel_id, m.author_member_id, NULL, m.plain_text, sp_search_tsv(NULL, m.plain_text),
            m.body::text ~ '"link": *\{' OR m.body::text ~ '"href": *"https?:' OR m.plain_text ~* 'https?://' OR EXISTS (SELECT 1 FROM message_links ml WHERE ml.message_id = p_message_id),
            m.attachment_count > 0, m.sent_at, now())
    ON CONFLICT (entity_kind, entity_id) WHERE entity_id IS NOT NULL DO UPDATE
       SET body = EXCLUDED.body, tsv = EXCLUDED.tsv, has_link = EXCLUDED.has_link, has_file = EXCLUDED.has_file, occurred_at = EXCLUDED.occurred_at, updated_at = now();
END$$;

CREATE OR REPLACE FUNCTION sp_index_comment(p_comment_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cm comments%ROWTYPE; p pages%ROWTYPE;
BEGIN
    SELECT * INTO cm FROM comments WHERE id = p_comment_id;
    IF NOT FOUND OR cm.deleted_at IS NOT NULL THEN
        DELETE FROM search_index WHERE entity_kind = 'comment' AND entity_uuid = p_comment_id;
        RETURN;
    END IF;
    SELECT * INTO p FROM pages WHERE id = cm.page_id;
    INSERT INTO search_index (entity_kind, entity_uuid, page_id, space_id, author_member_id, title, body, tsv, has_link, has_file, occurred_at, updated_at)
    VALUES ('comment', p_comment_id, cm.page_id, p.space_id, cm.author_member_id, p.plain_title, cm.plain_text, sp_search_tsv(NULL, cm.plain_text),
            cm.body::text ~ '"link": *\{' OR cm.body::text ~ '"href": *"https?:' OR cm.plain_text ~* 'https?://', false, cm.created_at, now())
    ON CONFLICT (entity_kind, entity_uuid) WHERE entity_uuid IS NOT NULL DO UPDATE
       SET body = EXCLUDED.body, tsv = EXCLUDED.tsv, has_link = EXCLUDED.has_link, occurred_at = EXCLUDED.occurred_at, updated_at = now();
END$$;

-- The pages, messages and comments already in the index are indexed again with the new test (nothing on a new installation).
DO $$
DECLARE r record;
BEGIN
    FOR r IN SELECT id FROM pages WHERE archived_at IS NULL AND NOT is_template LOOP PERFORM sp_index_page(r.id); END LOOP;
    FOR r IN SELECT id FROM messages WHERE deleted_at IS NULL AND sent_at IS NOT NULL AND kind = 'message' LOOP PERFORM sp_index_message(r.id); END LOOP;
    FOR r IN SELECT id FROM comments WHERE deleted_at IS NULL LOOP PERFORM sp_index_comment(r.id); END LOOP;
END$$;

COMMIT;
