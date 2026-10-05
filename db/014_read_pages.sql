-- 014: READING pages — the block tree, the Markdown rendering, a database view's rows. SQL functions, so a screen, a tool
-- and an export never disagree (design §6; D6, D8).
--
--   sp_page_tree(page)            the block tree in order, children nested, as JSON (one recursive query)
--   sp_rich_text_markdown(rt)     the one rich-text array as Markdown runs
--   sp_page_markdown(page)        the tree rendered to Markdown — the agents' way of reading, the export's body
--   sp_row_resolved(row)          a row's properties with relations, rollups, people and the computed four resolved
--   sp_row_matches(row, filter)   Notion's filter object evaluated against a row
--   sp_database_rows(view, …)     the view's filter, sort and group applied in SQL, rollups computed, paged
--
-- THE ESTATE'S DATA MODEL (design §6.1): new.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- The tree.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_block_children_json(p_page_id uuid, p_parent uuid, p_depth integer DEFAULT 0) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE out jsonb := '[]'::jsonb; b record; orig record; kids jsonb;
BEGIN
    IF p_depth > 60 THEN RETURN out; END IF;
    FOR b IN SELECT * FROM blocks WHERE page_id = p_page_id AND parent_block_id IS NOT DISTINCT FROM p_parent ORDER BY position, created_at LOOP
        kids := CASE WHEN b.has_children THEN sp_block_children_json(p_page_id, b.id, p_depth + 1) ELSE '[]'::jsonb END;
        IF b.type = 'synced_block' AND b.synced_from IS NOT NULL THEN
            SELECT * INTO orig FROM blocks WHERE id = b.synced_from;
            IF FOUND AND sp_can_see_page(orig.page_id) THEN
                kids := sp_block_children_json(orig.page_id, orig.id, p_depth + 1);
            ELSE
                kids := '[]'::jsonb;
            END IF;
        END IF;
        out := out || jsonb_build_object('id', b.id, 'type', b.type, 'content', b.content, 'has_children', b.has_children, 'version', b.version,
                                         'position', b.position, 'synced_from', b.synced_from, 'created_by', b.created_by, 'last_edited_by', b.last_edited_by,
                                         'last_edited_at', b.last_edited_at, 'children', kids);
    END LOOP;
    RETURN out;
END$$;

CREATE OR REPLACE FUNCTION sp_page_tree(p_page_id uuid) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT sp_block_children_json(p_page_id, NULL, 0);
$$;

-- The whole page as the snapshot keeps it: title, icon, properties and the tree.
CREATE OR REPLACE FUNCTION sp_page_snapshot(p_page_id uuid) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT jsonb_build_object('page_id', p.id, 'title', p.title, 'icon', p.icon, 'cover_attachment_id', p.cover_attachment_id, 'properties', p.properties,
                              'content_rev', p.content_rev, 'blocks', sp_page_tree(p.id))
      FROM pages p WHERE p.id = p_page_id;
$$;

-- ---------------------------------------------------------------------------------------------
-- Markdown.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_md_escape(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$ SELECT COALESCE(p, '') $$;

CREATE OR REPLACE FUNCTION sp_rich_text_markdown(p jsonb) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $fn$
DECLARE r jsonb; out text := ''; t text; a jsonb; href text; mt text; mid text; nm text;
BEGIN
    IF p IS NULL OR jsonb_typeof(p) <> 'array' THEN RETURN ''; END IF;
    FOR r IN SELECT x FROM jsonb_array_elements(p) x LOOP
        a := COALESCE(r->'annotations', '{}'::jsonb);
        CASE r->>'type'
            WHEN 'text' THEN
                t := COALESCE(r->'text'->>'content', r->>'plain_text', '');
                href := COALESCE(r->'text'->'link'->>'url', CASE WHEN jsonb_typeof(r->'text'->'link') = 'string' THEN r->'text'->>'link' END, r->>'href');
            WHEN 'equation' THEN
                t := '$' || COALESCE(r->'equation'->>'expression', '') || '$'; href := NULL;
            WHEN 'mention' THEN
                mt := r->'mention'->>'type'; mid := r->'mention'->>'id';
                CASE mt
                    WHEN 'member', 'agent' THEN
                        SELECT display_name INTO nm FROM members WHERE id = NULLIF(regexp_replace(mid, '[^0-9]', '', 'g'), '')::bigint;
                        t := '@' || COALESCE(nm, r->>'plain_text', 'someone');
                    WHEN 'department' THEN
                        SELECT name INTO nm FROM departments WHERE id = NULLIF(regexp_replace(mid, '[^0-9]', '', 'g'), '')::bigint;
                        t := '@' || COALESCE(nm, r->>'plain_text', 'department');
                    WHEN 'page', 'database' THEN
                        SELECT plain_title INTO nm FROM pages WHERE id::text = mid;
                        t := '[[' || COALESCE(NULLIF(nm, ''), r->>'plain_text', 'Untitled') || ']]';
                    WHEN 'date' THEN
                        t := COALESCE(r->'mention'->'date'->>'start', r->>'plain_text', '') || CASE WHEN r->'mention'->'date'->>'end' IS NOT NULL THEN ' → ' || (r->'mention'->'date'->>'end') ELSE '' END;
                    WHEN 'channel' THEN t := '@channel';
                    WHEN 'here' THEN t := '@here';
                    WHEN 'everyone' THEN t := '@everyone';
                    WHEN 'message' THEN t := COALESCE(r->>'plain_text', '(a message)');
                    ELSE t := COALESCE(r->>'plain_text', '');
                END CASE;
                href := NULL;
            ELSE t := COALESCE(r->>'plain_text', ''); href := NULL;
        END CASE;
        IF t = '' THEN CONTINUE; END IF;
        -- whitespace stays outside the markers (" bold " → " **bold** ")
        DECLARE lead text := substring(t FROM '^\s*'); trail text := substring(t FROM '\s*$'); core text := btrim(t);
        BEGIN
            IF core = '' THEN out := out || t; CONTINUE; END IF;
            t := core;
            IF COALESCE((a->>'code')::boolean, false) THEN t := '`' || t || '`'; END IF;
            IF COALESCE((a->>'bold')::boolean, false) THEN t := '**' || t || '**'; END IF;
            IF COALESCE((a->>'italic')::boolean, false) THEN t := '*' || t || '*'; END IF;
            IF COALESCE((a->>'strikethrough')::boolean, false) THEN t := '~~' || t || '~~'; END IF;
            IF COALESCE((a->>'underline')::boolean, false) THEN t := '<u>' || t || '</u>'; END IF;
            IF href IS NOT NULL AND href <> '' THEN t := '[' || t || '](' || href || ')'; END IF;
            out := out || lead || t || trail;
        END;
        CONTINUE;
        IF COALESCE((a->>'code')::boolean, false) THEN t := '`' || t || '`'; END IF;
        IF COALESCE((a->>'bold')::boolean, false) THEN t := '**' || t || '**'; END IF;
        IF COALESCE((a->>'italic')::boolean, false) THEN t := '*' || t || '*'; END IF;
        IF COALESCE((a->>'strikethrough')::boolean, false) THEN t := '~~' || t || '~~'; END IF;
        IF COALESCE((a->>'underline')::boolean, false) THEN t := '<u>' || t || '</u>'; END IF;
        IF href IS NOT NULL AND href <> '' THEN t := '[' || t || '](' || href || ')'; END IF;
        out := out || t;
    END LOOP;
    RETURN out;
END$fn$;

-- One block (and its children) as Markdown lines; p_indent = the nesting depth for lists.
CREATE OR REPLACE FUNCTION sp_block_markdown(p_block jsonb, p_indent integer, p_number integer) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $fn$
DECLARE t text := p_block->>'type'; c jsonb := COALESCE(p_block->'content', '{}'::jsonb); pad text := repeat('    ', greatest(p_indent, 0));
        rt text; out text := ''; kids text; k jsonb; n integer := 0; row jsonb; cell jsonb; line text; url text; cap text; nm text; i integer;
BEGIN
    rt := sp_rich_text_markdown(c->'rich_text');
    url := COALESCE(c->>'url', c->'external'->>'url', c->'file'->>'url');
    IF url IS NULL AND c->>'attachment_id' IS NOT NULL THEN url := '/files/' || (c->>'attachment_id'); END IF;
    cap := sp_rich_text_markdown(c->'caption');
    -- children first (rendered one level deeper)
    kids := '';
    IF jsonb_typeof(p_block->'children') = 'array' AND jsonb_array_length(p_block->'children') > 0 AND t NOT IN ('table', 'column_list') THEN
        FOR k IN SELECT x FROM jsonb_array_elements(p_block->'children') x LOOP
            IF k->>'type' = 'numbered_list_item' THEN n := n + 1; ELSE n := 0; END IF;
            kids := kids || sp_block_markdown(k, CASE WHEN t IN ('bulleted_list_item', 'numbered_list_item', 'to_do', 'toggle', 'quote', 'callout') THEN p_indent + 1 ELSE p_indent END, n);
        END LOOP;
    END IF;
    CASE t
        WHEN 'paragraph' THEN out := CASE WHEN rt = '' THEN E'\n' ELSE pad || rt || E'\n\n' END || kids;
        WHEN 'heading_1' THEN out := E'\n' || pad || '# ' || rt || E'\n\n' || kids;
        WHEN 'heading_2' THEN out := E'\n' || pad || '## ' || rt || E'\n\n' || kids;
        WHEN 'heading_3' THEN out := E'\n' || pad || '### ' || rt || E'\n\n' || kids;
        WHEN 'bulleted_list_item' THEN out := pad || '- ' || rt || E'\n' || kids;
        WHEN 'numbered_list_item' THEN out := pad || greatest(p_number, 1)::text || '. ' || rt || E'\n' || kids;
        WHEN 'to_do' THEN out := pad || CASE WHEN COALESCE((c->>'checked')::boolean, false) THEN '- [x] ' ELSE '- [ ] ' END || rt || E'\n' || kids;
        WHEN 'toggle' THEN out := pad || '<details><summary>' || rt || '</summary>' || E'\n\n' || kids || E'\n' || pad || '</details>' || E'\n\n';
        WHEN 'quote' THEN out := pad || '> ' || replace(rt, E'\n', E'\n' || pad || '> ') || E'\n' || regexp_replace(kids, '(^|\n)(?!$)', E'\\1' || pad || '> ', 'g') || E'\n';
        WHEN 'callout' THEN out := pad || '> [!NOTE] ' || COALESCE(c->'icon'->>'emoji', '') || CASE WHEN c->'icon'->>'emoji' IS NULL THEN '' ELSE ' ' END || rt || E'\n' || regexp_replace(kids, '(^|\n)(?!$)', E'\\1' || pad || '> ', 'g') || E'\n';
        WHEN 'code' THEN out := pad || '```' || COALESCE(c->>'language', '') || E'\n' || sp_rich_text_plain(c->'rich_text') || E'\n' || pad || '```' || E'\n\n';
        WHEN 'divider' THEN out := pad || '---' || E'\n\n';
        WHEN 'image' THEN out := pad || '![' || cap || '](' || COALESCE(url, '') || ')' || E'\n\n';
        WHEN 'video', 'audio', 'file', 'pdf' THEN
            nm := COALESCE(NULLIF(cap, ''), c->>'name', t);
            out := pad || '[' || nm || '](' || COALESCE(url, '') || ')' || E'\n\n';
        WHEN 'bookmark', 'embed', 'link_preview' THEN out := pad || CASE WHEN cap = '' THEN '<' || COALESCE(url, '') || '>' ELSE '[' || cap || '](' || COALESCE(url, '') || ')' END || E'\n\n';
        WHEN 'equation' THEN out := pad || '$$' || E'\n' || COALESCE(c->>'expression', '') || E'\n' || '$$' || E'\n\n';
        WHEN 'table_of_contents', 'breadcrumb', 'template' THEN out := '';
        WHEN 'column_list' THEN
            -- columns are flattened in reading order (lost on the way out, by design)
            FOR k IN SELECT x FROM jsonb_array_elements(COALESCE(p_block->'children', '[]'::jsonb)) x LOOP
                out := out || sp_block_markdown(k, p_indent, 0);
            END LOOP;
        WHEN 'column' THEN out := kids;
        WHEN 'synced_block' THEN out := kids;
        WHEN 'link_to_page' THEN
            SELECT plain_title INTO nm FROM pages WHERE id = COALESCE((c->>'page_id')::uuid, (c->>'database_id')::uuid);
            out := pad || '[[' || COALESCE(NULLIF(nm, ''), 'Untitled') || ']]' || E'\n\n';
        WHEN 'child_page', 'child_database' THEN
            out := pad || '- [[' || COALESCE(NULLIF(c->>'title', ''), 'Untitled') || ']]' || CASE WHEN t = 'child_database' THEN ' (database)' ELSE '' END || E'\n';
        WHEN 'table' THEN
            i := 0;
            FOR row IN SELECT x FROM jsonb_array_elements(COALESCE(p_block->'children', '[]'::jsonb)) x WHERE x->>'type' = 'table_row' LOOP
                line := '|';
                FOR cell IN SELECT y FROM jsonb_array_elements(COALESCE(row->'content'->'cells', '[]'::jsonb)) y LOOP
                    line := line || ' ' || replace(sp_rich_text_markdown(cell), '|', '\|') || ' |';
                END LOOP;
                out := out || pad || line || E'\n';
                IF i = 0 THEN
                    out := out || pad || '|' || repeat(' --- |', greatest(COALESCE((c->>'table_width')::integer, jsonb_array_length(COALESCE(row->'content'->'cells', '[]'::jsonb))), 1)) || E'\n';
                END IF;
                i := i + 1;
            END LOOP;
            out := out || E'\n';
        WHEN 'table_row' THEN out := '';
        WHEN 'unsupported' THEN out := pad || '<!-- unsupported block' || COALESCE(' ' || (c->>'original_type'), '') || ' -->' || E'\n\n';
        ELSE out := pad || rt || E'\n\n' || kids;
    END CASE;
    RETURN out;
END$fn$;

-- A row's property as Markdown text (the front matter of a row page and the database tables).
CREATE OR REPLACE FUNCTION sp_property_markdown(p_type text, p_value jsonb) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE out text; x jsonb; nm text;
BEGIN
    IF p_value IS NULL OR jsonb_typeof(p_value) = 'null' THEN RETURN ''; END IF;
    CASE p_type
        WHEN 'title', 'rich_text' THEN RETURN sp_rich_text_markdown(p_value);
        WHEN 'number' THEN RETURN p_value #>> '{}';
        WHEN 'checkbox' THEN RETURN CASE WHEN (p_value #>> '{}')::boolean THEN '☑' ELSE '☐' END;
        WHEN 'select', 'status' THEN RETURN COALESCE(p_value->>'name', p_value #>> '{}');
        WHEN 'multi_select' THEN
            SELECT string_agg(COALESCE(e->>'name', e #>> '{}'), ', ') INTO out FROM jsonb_array_elements(p_value) e; RETURN COALESCE(out, '');
        WHEN 'date' THEN RETURN COALESCE(p_value->>'start', '') || CASE WHEN p_value->>'end' IS NOT NULL THEN ' → ' || (p_value->>'end') ELSE '' END;
        WHEN 'people', 'created_by', 'last_edited_by' THEN
            SELECT string_agg('@' || COALESCE(m.display_name, e #>> '{}'), ', ') INTO out
              FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p_value) = 'array' THEN p_value ELSE jsonb_build_array(p_value) END) e
              LEFT JOIN members m ON m.id::text = COALESCE(e->>'id', e #>> '{}');
            RETURN COALESCE(out, '');
        WHEN 'files' THEN
            SELECT string_agg('[' || COALESCE(a.filename, e #>> '{}') || '](/files/' || COALESCE(a.id::text, e #>> '{}') || ')', ', ') INTO out
              FROM jsonb_array_elements(p_value) e LEFT JOIN attachments a ON a.id::text = COALESCE(e->>'id', e #>> '{}');
            RETURN COALESCE(out, '');
        WHEN 'relation' THEN
            SELECT string_agg('[[' || COALESCE(NULLIF(p.plain_title, ''), 'Untitled') || ']]', ', ') INTO out
              FROM jsonb_array_elements(p_value) e JOIN pages p ON p.id::text = COALESCE(e->>'id', e #>> '{}');
            RETURN COALESCE(out, '');
        WHEN 'rollup' THEN
            IF jsonb_typeof(p_value) = 'object' THEN RETURN COALESCE(p_value->>'display', p_value->>'value', ''); END IF;
            RETURN p_value #>> '{}';
        WHEN 'unique_id' THEN RETURN COALESCE(p_value->>'prefix', '') || CASE WHEN p_value->>'prefix' IS NULL THEN '' ELSE '-' END || COALESCE(p_value->>'number', '');
        WHEN 'created_time', 'last_edited_time' THEN RETURN p_value #>> '{}';
        WHEN 'verification' THEN RETURN COALESCE(p_value->>'state', '');
        ELSE RETURN CASE WHEN jsonb_typeof(p_value) = 'string' THEN p_value #>> '{}' ELSE p_value::text END;
    END CASE;
END$$;

-- The page as Markdown: the title as H1, a row's properties as a list, then the tree. Blocks the caller may not see
-- (another page's synced original) are left out by sp_page_tree.
CREATE OR REPLACE FUNCTION sp_page_markdown(p_page_id uuid, p_with_title boolean DEFAULT true) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; out text := ''; k jsonb; n integer := 0; props jsonb; e record; schema jsonb;
BEGIN
    SELECT * INTO p FROM pages WHERE id = p_page_id;
    IF NOT FOUND THEN RETURN NULL; END IF;
    IF p_with_title THEN
        out := '# ' || COALESCE(p.icon || ' ', '') || COALESCE(NULLIF(sp_rich_text_markdown(p.title), ''), 'Untitled') || E'\n\n';
    END IF;
    IF p.parent_database_id IS NOT NULL THEN
        props := sp_row_resolved(p.id);
        SELECT properties INTO schema FROM databases WHERE id = p.parent_database_id;
        FOR e IN SELECT key, value FROM jsonb_each(COALESCE(schema, '{}'::jsonb)) WHERE value->>'type' <> 'title' ORDER BY key LOOP
            out := out || '- **' || COALESCE(e.value->>'name', e.key) || ':** ' || sp_property_markdown(e.value->>'type', props->e.key) || E'\n';
        END LOOP;
        out := out || E'\n';
    END IF;
    FOR k IN SELECT x FROM jsonb_array_elements(sp_page_tree(p_page_id)) x LOOP
        IF k->>'type' = 'numbered_list_item' THEN n := n + 1; ELSE n := 0; END IF;
        out := out || sp_block_markdown(k, 0, n);
    END LOOP;
    RETURN regexp_replace(out, E'\n{3,}', E'\n\n', 'g');
END$$;

-- ---------------------------------------------------------------------------------------------
-- Rows: values resolved, filters, sorts.
-- ---------------------------------------------------------------------------------------------
-- A rollup through a relation: the related rows' property aggregated by the function.
CREATE OR REPLACE FUNCTION sp_row_rollup(p_row_id uuid, p_schema jsonb, p_key text) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE def jsonb := p_schema->p_key; rel text; prop text; fn text; tdb uuid; tschema jsonb; ttype text; vals jsonb; n numeric; cnt integer; d text;
BEGIN
    IF def IS NULL OR def->>'type' <> 'rollup' THEN RETURN NULL; END IF;
    rel := def->'rollup'->>'relation'; prop := def->'rollup'->>'property'; fn := def->'rollup'->>'function';
    tdb := (p_schema->rel->'relation'->>'database_id')::uuid;
    SELECT properties INTO tschema FROM databases WHERE id = tdb;
    ttype := tschema->prop->>'type';
    SELECT COALESCE(jsonb_agg(CASE WHEN ttype = 'title' THEN p.title ELSE p.properties->prop END ORDER BY rr.position), '[]'::jsonb), count(*)
      INTO vals, cnt
      FROM row_relations rr JOIN pages p ON p.id = rr.to_row_id AND p.archived_at IS NULL
     WHERE rr.from_row_id = p_row_id AND rr.property_key = rel;
    CASE fn
        WHEN 'count' THEN RETURN jsonb_build_object('type', 'number', 'value', cnt, 'display', cnt::text);
        WHEN 'show_original' THEN RETURN jsonb_build_object('type', 'array', 'value', vals, 'display', (SELECT string_agg(sp_property_markdown(ttype, v), ', ') FROM jsonb_array_elements(vals) v));
        WHEN 'percent_checked' THEN
            SELECT CASE WHEN count(*) = 0 THEN 0 ELSE round(100.0 * count(*) FILTER (WHERE (v #>> '{}')::boolean) / count(*), 1) END INTO n FROM jsonb_array_elements(vals) v WHERE jsonb_typeof(v) = 'boolean';
            RETURN jsonb_build_object('type', 'number', 'value', n, 'display', n::text || '%');
        WHEN 'sum', 'min', 'max' THEN
            SELECT CASE fn WHEN 'sum' THEN sum((v #>> '{}')::numeric) WHEN 'min' THEN min((v #>> '{}')::numeric) ELSE max((v #>> '{}')::numeric) END INTO n
              FROM jsonb_array_elements(vals) v WHERE jsonb_typeof(v) = 'number';
            RETURN jsonb_build_object('type', 'number', 'value', n, 'display', COALESCE(n::text, ''));
        WHEN 'earliest_date', 'latest_date' THEN
            SELECT CASE fn WHEN 'earliest_date' THEN min(v->>'start') ELSE max(v->>'start') END INTO d FROM jsonb_array_elements(vals) v WHERE jsonb_typeof(v) = 'object';
            RETURN jsonb_build_object('type', 'date', 'value', to_jsonb(d), 'display', COALESCE(d, ''));
        ELSE RETURN NULL;
    END CASE;
END$$;

-- A row's properties, resolved: relations as [{id,title}], rollups computed, people as [{id,name}], the four computed ones.
CREATE OR REPLACE FUNCTION sp_row_resolved(p_row_id uuid) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; schema jsonb; out jsonb := '{}'::jsonb; e record; v jsonb;
BEGIN
    SELECT * INTO p FROM pages WHERE id = p_row_id;
    IF NOT FOUND OR p.parent_database_id IS NULL THEN RETURN NULL; END IF;
    SELECT properties INTO schema FROM databases WHERE id = p.parent_database_id;
    FOR e IN SELECT key, value FROM jsonb_each(schema) LOOP
        CASE e.value->>'type'
            WHEN 'title' THEN v := p.title;
            WHEN 'relation' THEN
                SELECT COALESCE(jsonb_agg(jsonb_build_object('id', rr.to_row_id, 'title', t.plain_title) ORDER BY rr.position), '[]'::jsonb) INTO v
                  FROM row_relations rr JOIN pages t ON t.id = rr.to_row_id AND t.archived_at IS NULL WHERE rr.from_row_id = p_row_id AND rr.property_key = e.key;
            WHEN 'rollup' THEN v := sp_row_rollup(p_row_id, schema, e.key);
            WHEN 'people' THEN
                SELECT COALESCE(jsonb_agg(jsonb_build_object('id', m.id, 'name', m.display_name, 'is_agent', m.member_kind = 'agent')), '[]'::jsonb) INTO v
                  FROM jsonb_array_elements(COALESCE(p.properties->e.key, '[]'::jsonb)) x JOIN members m ON m.id::text = COALESCE(x->>'id', x #>> '{}');
            WHEN 'files' THEN
                SELECT COALESCE(jsonb_agg(jsonb_build_object('id', a.id, 'filename', a.filename, 'mime_type', a.mime_type)), '[]'::jsonb) INTO v
                  FROM jsonb_array_elements(COALESCE(p.properties->e.key, '[]'::jsonb)) x JOIN attachments a ON a.id::text = COALESCE(x->>'id', x #>> '{}');
            WHEN 'created_time' THEN v := to_jsonb(p.created_at);
            WHEN 'last_edited_time' THEN v := to_jsonb(p.last_edited_at);
            WHEN 'created_by' THEN SELECT jsonb_build_object('id', m.id, 'name', m.display_name) INTO v FROM members m WHERE m.id = p.created_by;
            WHEN 'last_edited_by' THEN SELECT jsonb_build_object('id', m.id, 'name', m.display_name) INTO v FROM members m WHERE m.id = p.last_edited_by;
            WHEN 'verification' THEN v := jsonb_build_object('state', p.verification_state, 'verified_at', p.verified_at, 'verify_until', p.verify_until, 'verified_by', p.verified_by);
            ELSE v := p.properties->e.key;
        END CASE;
        out := out || jsonb_build_object(e.key, v);
    END LOOP;
    RETURN out;
END$$;

-- The comparable text / number / date of a property value (for filters and sorts).
CREATE OR REPLACE FUNCTION sp_prop_text(p_type text, p_value jsonb) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE
        WHEN p_value IS NULL OR jsonb_typeof(p_value) = 'null' THEN NULL
        WHEN p_type IN ('title', 'rich_text') THEN NULLIF(sp_rich_text_plain(p_value), '')
        WHEN p_type IN ('select', 'status') THEN COALESCE(p_value->>'name', p_value #>> '{}')
        WHEN p_type = 'multi_select' THEN (SELECT string_agg(COALESCE(e->>'name', e #>> '{}'), ',') FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p_value) = 'array' THEN p_value ELSE '[]'::jsonb END) e)
        WHEN p_type = 'date' THEN p_value->>'start'
        WHEN p_type = 'unique_id' THEN lpad(COALESCE(p_value->>'number', '0'), 12, '0')
        WHEN p_type = 'rollup' THEN COALESCE(p_value->>'display', p_value #>> '{}')
        WHEN p_type IN ('people', 'relation', 'files') THEN (SELECT string_agg(COALESCE(e->>'title', e->>'name', e->>'filename', e->>'id', e #>> '{}'), ',') FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p_value) = 'array' THEN p_value ELSE '[]'::jsonb END) e)
        WHEN jsonb_typeof(p_value) = 'string' THEN p_value #>> '{}'
        ELSE p_value::text END;
$$;
CREATE OR REPLACE FUNCTION sp_prop_number(p_type text, p_value jsonb) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT CASE
        WHEN p_value IS NULL OR jsonb_typeof(p_value) = 'null' THEN NULL
        WHEN p_type = 'number' AND jsonb_typeof(p_value) = 'number' THEN (p_value #>> '{}')::numeric
        WHEN p_type = 'rollup' AND jsonb_typeof(p_value->'value') = 'number' THEN (p_value->>'value')::numeric
        WHEN p_type = 'unique_id' THEN NULLIF(p_value->>'number', '')::numeric
        WHEN p_type = 'checkbox' THEN CASE WHEN (p_value #>> '{}')::boolean THEN 1 ELSE 0 END
        ELSE NULL END;
$$;
CREATE OR REPLACE FUNCTION sp_prop_date(p_type text, p_value jsonb) RETURNS timestamptz
    LANGUAGE plpgsql STABLE AS $$
BEGIN
    IF p_value IS NULL OR jsonb_typeof(p_value) = 'null' THEN RETURN NULL; END IF;
    IF p_type IN ('date') THEN RETURN NULLIF(p_value->>'start', '')::timestamptz; END IF;
    IF p_type IN ('created_time', 'last_edited_time') THEN RETURN (p_value #>> '{}')::timestamptz; END IF;
    IF p_type = 'rollup' AND p_value->>'type' = 'date' THEN RETURN NULLIF(p_value->>'value', '')::timestamptz; END IF;
    RETURN NULL;
EXCEPTION WHEN OTHERS THEN RETURN NULL;
END$$;

-- A property named by its key, its display name or its id (Notion's filter and sort objects accept any of the three).
CREATE OR REPLACE FUNCTION sp_prop_key(p_schema jsonb, p_name text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(
        CASE WHEN p_schema ? p_name THEN p_name END,
        (SELECT key FROM jsonb_each(COALESCE(p_schema, '{}'::jsonb)) WHERE lower(value->>'name') = lower(p_name) LIMIT 1),
        (SELECT key FROM jsonb_each(COALESCE(p_schema, '{}'::jsonb)) WHERE value->>'id' = p_name LIMIT 1));
$$;

-- Notion's filter object against one row's RESOLVED properties.
CREATE OR REPLACE FUNCTION sp_row_matches(p_resolved jsonb, p_schema jsonb, p_filter jsonb) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sub jsonb; key text; ptype text; cond jsonb; op text; want jsonb; val jsonb; txt text; num numeric; ts timestamptz; wnum numeric; wts timestamptz; wtxt text;
        arr_ids text[]; today date := current_date;
BEGIN
    IF p_filter IS NULL OR p_filter = '{}'::jsonb THEN RETURN true; END IF;
    IF p_filter ? 'and' THEN
        FOR sub IN SELECT x FROM jsonb_array_elements(p_filter->'and') x LOOP
            IF NOT sp_row_matches(p_resolved, p_schema, sub) THEN RETURN false; END IF;
        END LOOP;
        RETURN true;
    END IF;
    IF p_filter ? 'or' THEN
        FOR sub IN SELECT x FROM jsonb_array_elements(p_filter->'or') x LOOP
            IF sp_row_matches(p_resolved, p_schema, sub) THEN RETURN true; END IF;
        END LOOP;
        RETURN false;
    END IF;
    key := sp_prop_key(p_schema, p_filter->>'property');
    IF key IS NULL THEN RETURN (p_filter->>'property') IS NULL; END IF;
    ptype := p_schema->key->>'type';
    IF ptype IS NULL THEN RETURN false; END IF;
    -- the condition object is under the type's name (Notion), or under "condition"
    cond := COALESCE(p_filter->ptype, p_filter->'condition', p_filter->'rich_text', p_filter->'text', p_filter->'number', p_filter->'date', p_filter->'select', p_filter->'multi_select',
                     p_filter->'status', p_filter->'checkbox', p_filter->'people', p_filter->'relation', p_filter->'files', p_filter->'formula');
    IF cond IS NULL OR jsonb_typeof(cond) <> 'object' THEN RETURN true; END IF;
    SELECT k, v INTO op, want FROM jsonb_each(cond) AS t(k, v) LIMIT 1;
    val := p_resolved->key;
    IF val IS NOT NULL AND jsonb_typeof(val) = 'null' THEN val := NULL; END IF;   -- a JSON null is an empty value
    txt := sp_prop_text(ptype, val); num := sp_prop_number(ptype, val); ts := sp_prop_date(ptype, val);
    wtxt := CASE WHEN want IS NULL THEN NULL WHEN jsonb_typeof(want) = 'string' THEN want #>> '{}' ELSE want::text END;
    BEGIN wnum := CASE WHEN jsonb_typeof(want) = 'number' THEN (want #>> '{}')::numeric WHEN jsonb_typeof(want) = 'string' THEN NULLIF(wtxt, '')::numeric END; EXCEPTION WHEN OTHERS THEN wnum := NULL; END;
    BEGIN wts := CASE WHEN jsonb_typeof(want) = 'string' THEN NULLIF(wtxt, '')::timestamptz END; EXCEPTION WHEN OTHERS THEN wts := NULL; END;
    CASE op
        WHEN 'is_empty' THEN RETURN val IS NULL OR jsonb_typeof(val) = 'null' OR (jsonb_typeof(val) = 'array' AND jsonb_array_length(val) = 0) OR COALESCE(txt, '') = '';
        WHEN 'is_not_empty' THEN RETURN NOT (val IS NULL OR jsonb_typeof(val) = 'null' OR (jsonb_typeof(val) = 'array' AND jsonb_array_length(val) = 0) OR COALESCE(txt, '') = '');
        WHEN 'equals' THEN
            IF ptype = 'checkbox' THEN RETURN COALESCE((val #>> '{}')::boolean, false) = COALESCE((want #>> '{}')::boolean, false); END IF;
            IF ptype IN ('number', 'unique_id') THEN RETURN num = wnum; END IF;
            IF ptype IN ('date', 'created_time', 'last_edited_time') THEN RETURN ts::date = wts::date; END IF;
            RETURN lower(COALESCE(txt, '')) = lower(COALESCE(wtxt, ''));
        WHEN 'does_not_equal' THEN
            IF ptype = 'checkbox' THEN RETURN COALESCE((val #>> '{}')::boolean, false) <> COALESCE((want #>> '{}')::boolean, false); END IF;
            IF ptype IN ('number', 'unique_id') THEN RETURN num IS DISTINCT FROM wnum; END IF;
            RETURN lower(COALESCE(txt, '')) <> lower(COALESCE(wtxt, ''));
        WHEN 'contains' THEN
            IF ptype IN ('people', 'relation', 'files') THEN
                RETURN EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(val) = 'array' THEN val ELSE '[]'::jsonb END) e WHERE COALESCE(e->>'id', e #>> '{}') = wtxt OR lower(COALESCE(e->>'title', e->>'name', '')) = lower(COALESCE(wtxt, '')));
            END IF;
            IF ptype = 'multi_select' THEN RETURN EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(val) = 'array' THEN val ELSE '[]'::jsonb END) e WHERE lower(COALESCE(e->>'name', e #>> '{}')) = lower(COALESCE(wtxt, ''))); END IF;
            RETURN position(lower(COALESCE(wtxt, '')) IN lower(COALESCE(txt, ''))) > 0;
        WHEN 'does_not_contain' THEN
            IF ptype IN ('people', 'relation', 'files') THEN
                RETURN NOT EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(val) = 'array' THEN val ELSE '[]'::jsonb END) e WHERE COALESCE(e->>'id', e #>> '{}') = wtxt OR lower(COALESCE(e->>'title', e->>'name', '')) = lower(COALESCE(wtxt, '')));
            END IF;
            IF ptype = 'multi_select' THEN RETURN NOT EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(val) = 'array' THEN val ELSE '[]'::jsonb END) e WHERE lower(COALESCE(e->>'name', e #>> '{}')) = lower(COALESCE(wtxt, ''))); END IF;
            RETURN position(lower(COALESCE(wtxt, '')) IN lower(COALESCE(txt, ''))) = 0;
        WHEN 'starts_with' THEN RETURN lower(COALESCE(txt, '')) LIKE lower(COALESCE(wtxt, '')) || '%';
        WHEN 'ends_with' THEN RETURN lower(COALESCE(txt, '')) LIKE '%' || lower(COALESCE(wtxt, ''));
        WHEN 'greater_than' THEN RETURN num > wnum;
        WHEN 'less_than' THEN RETURN num < wnum;
        WHEN 'greater_than_or_equal_to' THEN RETURN num >= wnum;
        WHEN 'less_than_or_equal_to' THEN RETURN num <= wnum;
        WHEN 'before' THEN RETURN ts < wts;
        WHEN 'after' THEN RETURN ts > wts;
        WHEN 'on_or_before' THEN RETURN ts::date <= wts::date;
        WHEN 'on_or_after' THEN RETURN ts::date >= wts::date;
        WHEN 'past_week' THEN RETURN ts >= today - 7 AND ts < today + 1;
        WHEN 'past_month' THEN RETURN ts >= today - 30 AND ts < today + 1;
        WHEN 'past_year' THEN RETURN ts >= today - 365 AND ts < today + 1;
        WHEN 'this_week' THEN RETURN ts::date >= date_trunc('week', today)::date AND ts::date < date_trunc('week', today)::date + 7;
        WHEN 'next_week' THEN RETURN ts::date > today AND ts::date <= today + 7;
        WHEN 'next_month' THEN RETURN ts::date > today AND ts::date <= today + 30;
        WHEN 'next_year' THEN RETURN ts::date > today AND ts::date <= today + 365;
        ELSE RETURN true;
    END CASE;
END$$;

-- The rows of a view (or of a database with an ad-hoc filter and sort), paged. group_value is the first group_by property's
-- text so the board, the grouped table and the calendar place the row; the caller groups. total = the count before paging.
CREATE OR REPLACE FUNCTION sp_database_rows(p_database_id uuid, p_view_id uuid DEFAULT NULL, p_filter jsonb DEFAULT NULL, p_sort jsonb DEFAULT NULL,
                                            p_limit integer DEFAULT 100, p_offset integer DEFAULT 0)
    RETURNS TABLE (row_id uuid, title text, icon text, properties jsonb, group_value text, sub_group_value text, row_position text,
                   created_at timestamptz, last_edited_at timestamptz, last_edited_by bigint, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v database_views%ROWTYPE; schema jsonb; fil jsonb; srt jsonb; s1 jsonb; s2 jsonb; s3 jsonb; gb text; sgb text; t1 text; t2 text; t3 text;
BEGIN
    IF NOT sp_can_see_page(p_database_id) THEN RETURN; END IF;
    SELECT d.properties INTO schema FROM databases d WHERE d.id = p_database_id;
    IF p_view_id IS NOT NULL THEN SELECT * INTO v FROM database_views WHERE id = p_view_id AND database_id = p_database_id; END IF;
    fil := COALESCE(p_filter, v.filter, '{}'::jsonb);
    srt := COALESCE(p_sort, v.sort, '[]'::jsonb);
    s1 := srt->0; s2 := srt->1; s3 := srt->2;
    IF s1 IS NOT NULL THEN s1 := s1 || jsonb_build_object('property', sp_prop_key(schema, s1->>'property')); END IF;
    IF s2 IS NOT NULL THEN s2 := s2 || jsonb_build_object('property', sp_prop_key(schema, s2->>'property')); END IF;
    IF s3 IS NOT NULL THEN s3 := s3 || jsonb_build_object('property', sp_prop_key(schema, s3->>'property')); END IF;
    t1 := schema->(s1->>'property')->>'type'; t2 := schema->(s2->>'property')->>'type'; t3 := schema->(s3->>'property')->>'type';
    gb := sp_prop_key(schema, v.group_by); sgb := sp_prop_key(schema, v.sub_group_by);
    RETURN QUERY
    WITH rows AS (
        SELECT p.id, p.plain_title, p.icon, p.position, p.created_at, p.last_edited_at, p.last_edited_by, sp_row_resolved(p.id) AS res
          FROM pages p
         WHERE p.parent_database_id = p_database_id AND p.archived_at IS NULL AND NOT p.is_template
    ), matched AS (
        SELECT r.* FROM rows r WHERE sp_row_matches(r.res, schema, fil)
    ), keyed AS (
        SELECT m.*,
               CASE WHEN s1 IS NULL THEN NULL ELSE sp_prop_text(t1, m.res->(s1->>'property')) END AS k1t,
               CASE WHEN s1 IS NULL THEN NULL ELSE sp_prop_number(t1, m.res->(s1->>'property')) END AS k1n,
               CASE WHEN s1 IS NULL THEN NULL ELSE sp_prop_date(t1, m.res->(s1->>'property')) END AS k1d,
               CASE WHEN s2 IS NULL THEN NULL ELSE sp_prop_text(t2, m.res->(s2->>'property')) END AS k2t,
               CASE WHEN s2 IS NULL THEN NULL ELSE sp_prop_number(t2, m.res->(s2->>'property')) END AS k2n,
               CASE WHEN s2 IS NULL THEN NULL ELSE sp_prop_date(t2, m.res->(s2->>'property')) END AS k2d,
               CASE WHEN s3 IS NULL THEN NULL ELSE sp_prop_text(t3, m.res->(s3->>'property')) END AS k3t,
               count(*) OVER () AS tot
          FROM matched m
    )
    SELECT k.id, k.plain_title, k.icon, k.res,
           CASE WHEN gb IS NULL THEN NULL ELSE sp_prop_text(schema->gb->>'type', k.res->gb) END,
           CASE WHEN sgb IS NULL THEN NULL ELSE sp_prop_text(schema->sgb->>'type', k.res->sgb) END,
           k.position, k.created_at, k.last_edited_at, k.last_edited_by, k.tot
      FROM keyed k
     ORDER BY
           CASE WHEN s1->>'direction' = 'descending' THEN k.k1n END DESC NULLS LAST, CASE WHEN s1->>'direction' = 'descending' THEN k.k1d END DESC NULLS LAST, CASE WHEN s1->>'direction' = 'descending' THEN k.k1t END DESC NULLS LAST,
           CASE WHEN s1->>'direction' IS DISTINCT FROM 'descending' THEN k.k1n END ASC NULLS LAST, CASE WHEN s1->>'direction' IS DISTINCT FROM 'descending' THEN k.k1d END ASC NULLS LAST, CASE WHEN s1->>'direction' IS DISTINCT FROM 'descending' THEN k.k1t END ASC NULLS LAST,
           CASE WHEN s2->>'direction' = 'descending' THEN k.k2n END DESC NULLS LAST, CASE WHEN s2->>'direction' = 'descending' THEN k.k2d END DESC NULLS LAST, CASE WHEN s2->>'direction' = 'descending' THEN k.k2t END DESC NULLS LAST,
           CASE WHEN s2->>'direction' IS DISTINCT FROM 'descending' THEN k.k2n END ASC NULLS LAST, CASE WHEN s2->>'direction' IS DISTINCT FROM 'descending' THEN k.k2d END ASC NULLS LAST, CASE WHEN s2->>'direction' IS DISTINCT FROM 'descending' THEN k.k2t END ASC NULLS LAST,
           CASE WHEN s3->>'direction' = 'descending' THEN k.k3t END DESC NULLS LAST, CASE WHEN s3->>'direction' IS DISTINCT FROM 'descending' THEN k.k3t END ASC NULLS LAST,
           k.position, k.created_at
     LIMIT greatest(1, least(p_limit, 500)) OFFSET greatest(0, p_offset);
END$$;

GRANT EXECUTE ON FUNCTION sp_block_children_json(uuid, uuid, integer), sp_page_tree(uuid), sp_page_snapshot(uuid), sp_md_escape(text), sp_rich_text_markdown(jsonb),
    sp_block_markdown(jsonb, integer, integer), sp_property_markdown(text, jsonb), sp_page_markdown(uuid, boolean), sp_row_rollup(uuid, jsonb, text), sp_row_resolved(uuid),
    sp_prop_key(jsonb, text), sp_prop_text(text, jsonb), sp_prop_number(text, jsonb), sp_prop_date(text, jsonb), sp_row_matches(jsonb, jsonb, jsonb),
    sp_database_rows(uuid, uuid, jsonb, jsonb, integer, integer)
    TO spaces_rw, spaces_records_ro, spaces_activity_ro;

COMMIT;
