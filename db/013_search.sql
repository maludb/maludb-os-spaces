-- 013: ONE SEARCH across pages, rows, messages and comments (design §0.2, §6, §7 Q1) with Slack's modifiers.
--
-- search_index holds one row per searchable entity with its tsvector, maintained by triggers from the plain text the
-- generated columns already carry. sp_search(q, filters) applies the modifiers in SQL — in:#channel, from:@member, has:link|file,
-- before:/after: a date, is:page|row|message|comment — ranks, filters by what the caller may see (one set each, once per
-- statement), and pages. PHP parses the typed query into the filters object; the records tool takes the same object.
--
-- THE ESTATE'S DATA MODEL (design §6.1): new — no sibling keeps a cross-entity index; canonical.
BEGIN;

CREATE TABLE search_index (
    entity_kind   text NOT NULL CHECK (entity_kind IN ('page', 'row', 'message', 'comment')),
    entity_uuid   uuid,                                                    -- page, row, comment
    entity_id     bigint,                                                  -- message
    page_id       uuid,                                                    -- the page (for a row: itself; for a comment: its page)
    space_id      bigint,
    channel_id    bigint,
    author_member_id bigint,
    title         text,
    body          text,
    tsv           tsvector,
    has_link      boolean NOT NULL DEFAULT false,
    has_file      boolean NOT NULL DEFAULT false,
    occurred_at   timestamptz NOT NULL,
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((entity_uuid IS NULL) <> (entity_id IS NULL))
);
CREATE UNIQUE INDEX search_index_uuid ON search_index (entity_kind, entity_uuid) WHERE entity_uuid IS NOT NULL;
CREATE UNIQUE INDEX search_index_id   ON search_index (entity_kind, entity_id) WHERE entity_id IS NOT NULL;
CREATE INDEX search_index_tsv  ON search_index USING gin (tsv);
CREATE INDEX search_index_time ON search_index (occurred_at DESC);
CREATE INDEX search_index_page ON search_index (page_id);
CREATE INDEX search_index_channel ON search_index (channel_id);

CREATE OR REPLACE FUNCTION sp_search_tsv(p_title text, p_body text) RETURNS tsvector
    LANGUAGE sql IMMUTABLE AS $$
    SELECT setweight(to_tsvector('simple', unaccent(COALESCE(p_title, ''))), 'A') || setweight(to_tsvector('simple', unaccent(left(COALESCE(p_body, ''), 200000))), 'B');
$$;

-- The body of a page = its blocks' plain text in order (the row's property text too).
CREATE OR REPLACE FUNCTION sp_page_search_body(p_page_id uuid) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT left(COALESCE((SELECT string_agg(b.plain_text, E'\n' ORDER BY b.position) FROM blocks b WHERE b.page_id = p_page_id AND b.plain_text <> ''), '')
           || E'\n' || COALESCE((SELECT string_agg(CASE WHEN jsonb_typeof(v) = 'array' THEN sp_rich_text_plain(v) WHEN jsonb_typeof(v) = 'string' THEN v #>> '{}' ELSE '' END, ' ')
                                 FROM pages p, jsonb_each(p.properties) AS e(k, v) WHERE p.id = p_page_id), ''), 200000);
$$;

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
            EXISTS (SELECT 1 FROM blocks b WHERE b.page_id = p_page_id AND (b.type IN ('bookmark', 'embed', 'link_to_page', 'link_preview') OR b.content::text LIKE '%"link":"http%' OR b.content::text LIKE '%"href":"http%')),
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
            m.body::text LIKE '%"link":"http%' OR m.body::text LIKE '%"href":"http%' OR EXISTS (SELECT 1 FROM message_links ml WHERE ml.message_id = p_message_id),
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
            cm.body::text LIKE '%"link":"http%', false, cm.created_at, now())
    ON CONFLICT (entity_kind, entity_uuid) WHERE entity_uuid IS NOT NULL DO UPDATE
       SET body = EXCLUDED.body, tsv = EXCLUDED.tsv, has_link = EXCLUDED.has_link, occurred_at = EXCLUDED.occurred_at, updated_at = now();
END$$;

-- Triggers: a page on its own change; a block changes its page's entry; a message; a comment.
CREATE OR REPLACE FUNCTION sp_search_page_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN DELETE FROM search_index WHERE entity_uuid = OLD.id OR page_id = OLD.id; RETURN NULL; END IF;
    IF current_setting('app.sp_bulk', true) = '1' THEN RETURN NULL; END IF;   -- an import indexes once at the end
    PERFORM sp_index_page(NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER pages_search AFTER INSERT OR UPDATE OF title, properties, archived_at, is_template, space_id, last_edited_at OR DELETE ON pages FOR EACH ROW EXECUTE FUNCTION sp_search_page_trg();

CREATE OR REPLACE FUNCTION sp_search_block_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF current_setting('app.sp_bulk', true) = '1' OR current_setting('app.sp_purging', true) = '1' THEN RETURN NULL; END IF;
    PERFORM sp_index_page(COALESCE(NEW.page_id, OLD.page_id));
    RETURN NULL;
END$$;
CREATE TRIGGER blocks_search AFTER INSERT OR UPDATE OF content, type OR DELETE ON blocks FOR EACH ROW EXECUTE FUNCTION sp_search_block_trg();

CREATE OR REPLACE FUNCTION sp_search_message_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN DELETE FROM search_index WHERE entity_kind = 'message' AND entity_id = OLD.id; RETURN NULL; END IF;
    PERFORM sp_index_message(NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER messages_search AFTER INSERT OR UPDATE OF body, deleted_at, sent_at, attachment_count OR DELETE ON messages FOR EACH ROW EXECUTE FUNCTION sp_search_message_trg();

CREATE OR REPLACE FUNCTION sp_search_comment_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN DELETE FROM search_index WHERE entity_kind = 'comment' AND entity_uuid = OLD.id; RETURN NULL; END IF;
    PERFORM sp_index_comment(NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER comments_search AFTER INSERT OR UPDATE OF body, deleted_at OR DELETE ON comments FOR EACH ROW EXECUTE FUNCTION sp_search_comment_trg();

-- The catch-up the worker runs (after an import, or if a trigger was bypassed): every page whose index is older than its edit.
CREATE OR REPLACE FUNCTION sp_search_catch_up(p_limit integer DEFAULT 500) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record;
BEGIN
    FOR r IN SELECT p.id FROM pages p LEFT JOIN search_index si ON si.entity_uuid = p.id AND si.entity_kind IN ('page', 'row')
              WHERE p.archived_at IS NULL AND NOT p.is_template AND (si.entity_uuid IS NULL OR si.updated_at < p.last_edited_at) LIMIT p_limit LOOP
        PERFORM sp_index_page(r.id); n := n + 1;
    END LOOP;
    FOR r IN SELECT m.id FROM messages m LEFT JOIN search_index si ON si.entity_id = m.id AND si.entity_kind = 'message'
              WHERE m.deleted_at IS NULL AND m.sent_at IS NOT NULL AND m.kind = 'message' AND si.entity_id IS NULL LIMIT p_limit LOOP
        PERFORM sp_index_message(r.id); n := n + 1;
    END LOOP;
    RETURN n;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The search. filters: {"in": <channel id | "#name">, "from": <member id>, "has": "link"|"file", "before": "YYYY-MM-DD",
-- "after": "YYYY-MM-DD", "is": "page"|"row"|"message"|"comment", "space": <space id>}. Ranked by ts_rank, then recency.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_search(p_q text, p_filters jsonb DEFAULT '{}'::jsonb, p_limit integer DEFAULT 25, p_offset integer DEFAULT 0)
    RETURNS TABLE (entity_kind text, entity_uuid uuid, entity_id bigint, page_id uuid, space_id bigint, channel_id bigint, author_member_id bigint,
                   title text, excerpt text, occurred_at timestamptz, rank real, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q tsquery; f jsonb := COALESCE(p_filters, '{}'::jsonb); in_ch bigint; from_m bigint; sp bigint; before_d timestamptz; after_d timestamptz;
BEGIN
    IF p_q IS NULL OR btrim(p_q) = '' THEN q := NULL; ELSE q := websearch_to_tsquery('simple', unaccent(p_q)); END IF;
    IF f->>'in' IS NOT NULL THEN
        IF (f->>'in') ~ '^[0-9]+$' THEN in_ch := (f->>'in')::bigint;
        ELSE SELECT c.id INTO in_ch FROM channels c WHERE c.name = ltrim(f->>'in', '#') AND c.id IN (SELECT sp_visible_channel_ids()) LIMIT 1;
             IF in_ch IS NULL THEN in_ch := -1; END IF;
        END IF;
    END IF;
    IF (f->>'from') ~ '^[0-9]+$' THEN from_m := (f->>'from')::bigint; END IF;
    IF (f->>'space') ~ '^[0-9]+$' THEN sp := (f->>'space')::bigint; END IF;
    IF f->>'before' IS NOT NULL THEN before_d := (f->>'before')::date::timestamptz; END IF;
    IF f->>'after' IS NOT NULL THEN after_d := ((f->>'after')::date + 1)::timestamptz; END IF;
    RETURN QUERY
    WITH vp AS (SELECT sp_visible_page_ids() AS id), vc AS (SELECT sp_visible_channel_ids() AS id),
         hits AS (
        SELECT si.*, CASE WHEN q IS NULL THEN 0::real ELSE ts_rank(si.tsv, q) END AS r
          FROM search_index si
         WHERE (q IS NULL OR si.tsv @@ q)
           AND (f->>'is' IS NULL OR si.entity_kind = f->>'is')
           AND (in_ch IS NULL OR si.channel_id = in_ch)
           AND (from_m IS NULL OR si.author_member_id = from_m)
           AND (sp IS NULL OR si.space_id = sp)
           AND (f->>'has' IS DISTINCT FROM 'link' OR si.has_link)
           AND (f->>'has' IS DISTINCT FROM 'file' OR si.has_file)
           AND (before_d IS NULL OR si.occurred_at < before_d)
           AND (after_d IS NULL OR si.occurred_at >= after_d)
           AND ((si.entity_kind IN ('page', 'row', 'comment') AND si.page_id IN (SELECT id FROM vp))
                OR (si.entity_kind = 'message' AND si.channel_id IN (SELECT id FROM vc))))
    SELECT h.entity_kind, h.entity_uuid, h.entity_id, h.page_id, h.space_id, h.channel_id, h.author_member_id, h.title,
           CASE WHEN q IS NULL THEN left(h.body, 200) ELSE ts_headline('simple', h.body, q, 'MaxFragments=2, MaxWords=24, MinWords=8, StartSel=<mark>, StopSel=</mark>') END,
           h.occurred_at, h.r, count(*) OVER () AS total
      FROM hits h
     ORDER BY h.r DESC, h.occurred_at DESC
     LIMIT greatest(1, least(p_limit, 100)) OFFSET greatest(0, p_offset);
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON search_index TO spaces_rw;
GRANT EXECUTE ON FUNCTION sp_search(text, jsonb, integer, integer), sp_search_tsv(text, text) TO spaces_rw, spaces_records_ro, spaces_activity_ro;
REVOKE ALL ON FUNCTION sp_index_page(uuid), sp_index_message(bigint), sp_index_comment(uuid), sp_search_catch_up(integer), sp_page_search_body(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sp_index_page(uuid), sp_index_message(bigint), sp_index_comment(uuid), sp_search_catch_up(integer), sp_page_search_body(uuid) TO spaces_rw;

COMMIT;
