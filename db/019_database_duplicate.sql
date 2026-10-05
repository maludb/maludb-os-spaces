-- 019: DUPLICATING A DATABASE (found by slice 5, databases and views).
--
-- db/008's sp_page_duplicate() copies a page's blocks and child pages but makes a page of kind 'database' WITHOUT its `databases` row: the
-- copy had no schema, no views and no rows, so a database template (Vendor list, Decision log, …) applied by slice 2's template_apply — or chosen
-- in slice 5's database_create — came out as a broken database. This redefines the one function (CREATE OR REPLACE keeps its grants) with the
-- database branch added: the schema (a relation or a rollup is NOT copied — it points into another database's rows and the copy has none),
-- the views of the database itself (a linked view stays with the page it is embedded on), and the live rows with their blocks (the unique ids
-- renumbered by the guard, the relations left out). A page that is not a database is duplicated exactly as before.
BEGIN;

CREATE OR REPLACE FUNCTION sp_page_duplicate(p_page_id uuid, p_space_id bigint, p_parent_page_id uuid, p_title jsonb DEFAULT NULL, p_as_template boolean DEFAULT false) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE src pages%ROWTYPE; nid uuid; r record; idmap jsonb := '{}'::jsonb; newb uuid; newparent uuid; newchild uuid; c jsonb; schema jsonb; strip text[];
BEGIN
    SELECT * INTO src FROM pages WHERE id = p_page_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'The page does not exist' USING ERRCODE = 'P0001'; END IF;
    nid := sp_page_create(p_space_id, p_parent_page_id, COALESCE(p_title, src.title), src.kind, src.icon);
    UPDATE pages SET properties = src.properties, is_template = p_as_template, cover_attachment_id = src.cover_attachment_id WHERE id = nid;
    IF src.kind = 'database' THEN
        INSERT INTO databases (id, description, is_inline, properties)
        SELECT nid, d.description, d.is_inline,
               COALESCE((SELECT jsonb_object_agg(e.key, e.value) FROM jsonb_each(d.properties) e WHERE e.value->>'type' NOT IN ('relation', 'rollup')), '{}'::jsonb)
          FROM databases d WHERE d.id = p_page_id;
        INSERT INTO database_views (database_id, name, layout, filter, sort, group_by, sub_group_by, visible_properties, calendar_by, timeline_start, timeline_end,
                                    card_size, card_cover, wrap, position, created_by)
        SELECT nid, v.name, v.layout, v.filter, v.sort, v.group_by, v.sub_group_by, v.visible_properties, v.calendar_by, v.timeline_start, v.timeline_end,
               v.card_size, v.card_cover, v.wrap, v.position, app_current_member_id()
          FROM database_views v WHERE v.database_id = p_page_id AND v.linked_from_page_id IS NULL;
        IF NOT EXISTS (SELECT 1 FROM database_views WHERE database_id = nid) THEN
            INSERT INTO database_views (database_id, name, layout, created_by) VALUES (nid, 'Table', 'table', app_current_member_id());
        END IF;
    END IF;
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
    IF src.kind = 'database' THEN
        SELECT properties INTO schema FROM databases WHERE id = p_page_id;
        SELECT COALESCE(array_agg(e.key), '{}') INTO strip FROM jsonb_each(schema) e WHERE e.value->>'type' IN ('unique_id', 'relation', 'rollup');
        FOR r IN SELECT id, properties, is_template FROM pages WHERE parent_database_id = p_page_id AND archived_at IS NULL ORDER BY position, created_at LOOP
            newchild := sp_row_create(nid, r.properties - strip, r.id);          -- the row's blocks are copied from the source row
            IF r.is_template THEN UPDATE pages SET is_template = true WHERE id = newchild; END IF;
        END LOOP;
    END IF;
    RETURN nid;
END$$;

COMMIT;
