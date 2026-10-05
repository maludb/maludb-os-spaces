-- 009: DATABASES, their schema, views and relations (design §0.3, §6; D8).
--
-- A database is a page of kind 'database' (the same id); its ROWS are pages with parent_database_id = the database and their
-- typed values in pages.properties ({property_key: value}, values shaped as Notion's API shapes them). The schema is
-- databases.properties: {key: {id, name, type, options[], number_format, relation:{database_id, dual_property}, rollup:{relation,
-- property, function}, unique_id:{prefix}}}. Every Notion property type but formula, button and place (Extended). Rollups and
-- the four created_*/last_edited_* are COMPUTED in the read function (db/016), never stored. Relations live in ONE table,
-- row_relations; a dual property is the same link read from the other side. One database, one schema (no "data sources").
--
-- THE ESTATE'S DATA MODEL (design §6.1): new; canonical for databases.
BEGIN;

CREATE TABLE databases (
    id                  uuid PRIMARY KEY REFERENCES pages(id) ON DELETE CASCADE,         -- = its page's id
    description         jsonb NOT NULL DEFAULT '[]' CHECK (sp_is_rich_text(description)),
    is_inline           boolean NOT NULL DEFAULT false,                                  -- shown inside its parent page, not as a page of its own
    properties          jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(properties) = 'object'),
    title_property_key  text NOT NULL DEFAULT 'title',
    row_template_id     uuid,                                                            -- the default template for a new row (a page with template_of_database_id)
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER databases_touch BEFORE UPDATE ON databases FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

ALTER TABLE pages ADD CONSTRAINT pages_parent_database_fk FOREIGN KEY (parent_database_id) REFERENCES databases(id) ON DELETE RESTRICT;
ALTER TABLE pages ADD CONSTRAINT pages_template_of_database_fk FOREIGN KEY (template_of_database_id) REFERENCES databases(id) ON DELETE CASCADE;

CREATE TABLE database_views (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    database_id         uuid NOT NULL REFERENCES databases(id) ON DELETE CASCADE,
    name                text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    layout              text NOT NULL DEFAULT 'table' CHECK (layout = ANY (sp_view_layouts())),
    filter              jsonb,                                                           -- Notion's filter object: {and:[...]} | {or:[...]} | {property, <type>:{condition: value}}
    sort                jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(sort) = 'array'), -- [{property, direction: ascending|descending}]
    group_by            text,                                                            -- a property key (board columns, table groups)
    sub_group_by        text,
    visible_properties  text[],                                                          -- NULL = all
    calendar_by         text,                                                            -- a date property (calendar)
    timeline_start      text,                                                            -- a date property (timeline)
    timeline_end        text,
    card_size           text CHECK (card_size IS NULL OR card_size IN ('small', 'medium', 'large')),
    card_cover          text,                                                            -- 'page_cover' | 'page_icon' | a files property key | NULL
    wrap                boolean NOT NULL DEFAULT false,
    linked_from_page_id uuid REFERENCES pages(id) ON DELETE CASCADE,                     -- a linked view embedded on another page
    position            text NOT NULL DEFAULT 'a0',
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (layout <> 'calendar' OR calendar_by IS NOT NULL),
    CHECK (layout <> 'timeline' OR timeline_start IS NOT NULL),
    CHECK (layout <> 'board' OR group_by IS NOT NULL)
);
CREATE INDEX database_views_db_idx ON database_views (database_id, position);
CREATE INDEX database_views_linked_idx ON database_views (linked_from_page_id) WHERE linked_from_page_id IS NOT NULL;
CREATE TRIGGER database_views_touch BEFORE UPDATE ON database_views FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The one table behind every relation property. A dual relation (two-way) is one row read from both sides:
-- (from_row, from_property) ↔ (to_row, the dual property). from/to are both rows (pages).
CREATE TABLE row_relations (
    from_row_id    uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    property_key   text NOT NULL,
    to_row_id      uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    position       text NOT NULL DEFAULT 'a0',
    created_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (from_row_id, property_key, to_row_id)
);
CREATE INDEX row_relations_to_idx ON row_relations (to_row_id, property_key);

CREATE TABLE unique_id_sequences (
    database_id    uuid NOT NULL REFERENCES databases(id) ON DELETE CASCADE,
    property_key   text NOT NULL,
    prefix         text NOT NULL DEFAULT '',
    last_value     bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (database_id, property_key)
);

-- ---------------------------------------------------------------------------------------------
-- The referee.
-- ---------------------------------------------------------------------------------------------
-- The schema: every property has a type we know; exactly one title; a relation names a database; a rollup names a relation
-- property of this schema and a function we know; a select's options are {id, name, color}.
CREATE OR REPLACE FUNCTION sp_databases_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE k text; v jsonb; titles integer := 0; rel jsonb;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pages WHERE id = NEW.id AND kind = 'database') THEN
        RAISE EXCEPTION 'A database is a page of kind database' USING ERRCODE = 'P0001';
    END IF;
    FOR k, v IN SELECT * FROM jsonb_each(NEW.properties) LOOP
        IF jsonb_typeof(v) <> 'object' OR v->>'type' IS NULL THEN RAISE EXCEPTION 'Property "%" has no type', k USING ERRCODE = 'P0001'; END IF;
        IF NOT ((v->>'type') = ANY (sp_property_types())) THEN RAISE EXCEPTION 'Property "%": type "%" is not one we keep (formula, button and place are Extended)', k, v->>'type' USING ERRCODE = 'P0001'; END IF;
        IF v->>'type' = 'title' THEN titles := titles + 1; END IF;
        IF v->>'type' = 'relation' THEN
            IF (v->'relation'->>'database_id') IS NULL THEN RAISE EXCEPTION 'Relation "%" names no database', k USING ERRCODE = 'P0001'; END IF;
            IF NOT EXISTS (SELECT 1 FROM databases d WHERE d.id = (v->'relation'->>'database_id')::uuid) AND (v->'relation'->>'database_id')::uuid <> NEW.id THEN
                RAISE EXCEPTION 'Relation "%" names a database that does not exist', k USING ERRCODE = 'P0001';
            END IF;
        END IF;
        IF v->>'type' = 'rollup' THEN
            rel := NEW.properties->(v->'rollup'->>'relation');
            IF rel IS NULL OR rel->>'type' <> 'relation' THEN RAISE EXCEPTION 'Rollup "%" must go through a relation property of this database', k USING ERRCODE = 'P0001'; END IF;
            IF NOT ((v->'rollup'->>'function') = ANY (sp_rollup_functions())) THEN RAISE EXCEPTION 'Rollup "%": function "%" is not one of %', k, v->'rollup'->>'function', array_to_string(sp_rollup_functions(), ', ') USING ERRCODE = 'P0001'; END IF;
            IF (v->'rollup'->>'property') IS NULL THEN RAISE EXCEPTION 'Rollup "%" names no property to roll up', k USING ERRCODE = 'P0001'; END IF;
        END IF;
        IF v->>'type' IN ('select', 'multi_select', 'status') AND v->(v->>'type') IS NOT NULL AND jsonb_typeof(COALESCE(v->(v->>'type')->'options', '[]'::jsonb)) <> 'array' THEN
            RAISE EXCEPTION 'Property "%": options must be a list', k USING ERRCODE = 'P0001';
        END IF;
    END LOOP;
    IF titles <> 1 THEN RAISE EXCEPTION 'A database has exactly one title property (found %)', titles USING ERRCODE = 'P0001'; END IF;
    IF NEW.properties->NEW.title_property_key IS NULL OR (NEW.properties->NEW.title_property_key->>'type') <> 'title' THEN
        SELECT key INTO NEW.title_property_key FROM jsonb_each(NEW.properties) WHERE value->>'type' = 'title' LIMIT 1;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER databases_guard BEFORE INSERT OR UPDATE ON databases FOR EACH ROW EXECUTE FUNCTION sp_databases_guard();

-- A row's values: every key is a property of the schema (or kept from a removed property — Notion keeps the values until a
-- person purges); a value's shape matches its type loosely (the editor and the converter validate the detail); people are
-- member ids; files are attachment ids; a unique_id is taken from the sequence on insert; a title is rich text.
CREATE OR REPLACE FUNCTION sp_rows_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d databases%ROWTYPE; k text; v jsonb; pt text; nextv bigint; pf text;
BEGIN
    IF NEW.parent_database_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO d FROM databases WHERE id = NEW.parent_database_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'The database does not exist' USING ERRCODE = 'P0001'; END IF;
    IF NEW.parent_page_id IS DISTINCT FROM NEW.parent_database_id THEN NEW.parent_page_id := NEW.parent_database_id; END IF;
    IF NEW.kind <> 'page' THEN RAISE EXCEPTION 'A row is a page' USING ERRCODE = 'P0001'; END IF;
    FOR k, v IN SELECT * FROM jsonb_each(NEW.properties) LOOP
        pt := d.properties->k->>'type';
        IF pt IS NULL THEN CONTINUE; END IF;   -- a removed property's value, kept
        CASE pt
            WHEN 'title', 'rich_text' THEN IF NOT sp_is_rich_text(v) THEN RAISE EXCEPTION 'Property "%" takes rich text', k USING ERRCODE = 'P0001'; END IF;
            WHEN 'number' THEN IF jsonb_typeof(v) NOT IN ('number', 'null') THEN RAISE EXCEPTION 'Property "%" takes a number', k USING ERRCODE = 'P0001'; END IF;
            WHEN 'checkbox' THEN IF jsonb_typeof(v) <> 'boolean' THEN RAISE EXCEPTION 'Property "%" takes true or false', k USING ERRCODE = 'P0001'; END IF;
            WHEN 'select', 'status' THEN IF jsonb_typeof(v) NOT IN ('string', 'null', 'object') THEN RAISE EXCEPTION 'Property "%" takes one option', k USING ERRCODE = 'P0001'; END IF;
            WHEN 'multi_select', 'people', 'files' THEN IF jsonb_typeof(v) <> 'array' THEN RAISE EXCEPTION 'Property "%" takes a list', k USING ERRCODE = 'P0001'; END IF;
            WHEN 'date' THEN IF jsonb_typeof(v) NOT IN ('object', 'null') THEN RAISE EXCEPTION 'Property "%" takes {start, end}', k USING ERRCODE = 'P0001'; END IF;
            WHEN 'url', 'email', 'phone_number' THEN IF jsonb_typeof(v) NOT IN ('string', 'null') THEN RAISE EXCEPTION 'Property "%" takes text', k USING ERRCODE = 'P0001'; END IF;
            WHEN 'relation', 'rollup', 'created_time', 'created_by', 'last_edited_time', 'last_edited_by', 'verification' THEN
                NEW.properties := NEW.properties - k;   -- computed or kept elsewhere, never stored as a value
            WHEN 'unique_id' THEN NULL;
            ELSE NULL;
        END CASE;
    END LOOP;
    -- the title property mirrors the page title
    IF d.title_property_key IS NOT NULL THEN
        IF NEW.properties->d.title_property_key IS NOT NULL AND NEW.properties->d.title_property_key IS DISTINCT FROM NEW.title THEN
            NEW.title := NEW.properties->d.title_property_key;
        END IF;
        NEW.properties := NEW.properties || jsonb_build_object(d.title_property_key, NEW.title);
    END IF;
    -- unique ids on insert
    IF TG_OP = 'INSERT' THEN
        FOR k, v IN SELECT * FROM jsonb_each(d.properties) WHERE value->>'type' = 'unique_id' LOOP
            IF NEW.properties->k IS NULL OR jsonb_typeof(NEW.properties->k) = 'null' THEN
                pf := COALESCE(v->'unique_id'->>'prefix', '');
                INSERT INTO unique_id_sequences (database_id, property_key, prefix, last_value) VALUES (d.id, k, pf, 1)
                ON CONFLICT (database_id, property_key) DO UPDATE SET last_value = unique_id_sequences.last_value + 1
                RETURNING last_value INTO nextv;
                NEW.properties := NEW.properties || jsonb_build_object(k, jsonb_build_object('prefix', NULLIF(pf, ''), 'number', nextv));
            END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER pages_row_guard BEFORE INSERT OR UPDATE OF properties, title, parent_database_id ON pages FOR EACH ROW EXECUTE FUNCTION sp_rows_guard();

-- A relation links two rows through a relation property of the from-row's database, into the database it names.
CREATE OR REPLACE FUNCTION sp_row_relations_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE fdb uuid; tdb uuid; prop jsonb;
BEGIN
    SELECT parent_database_id INTO fdb FROM pages WHERE id = NEW.from_row_id;
    SELECT parent_database_id INTO tdb FROM pages WHERE id = NEW.to_row_id;
    IF fdb IS NULL OR tdb IS NULL THEN RAISE EXCEPTION 'A relation links two database rows' USING ERRCODE = 'P0001'; END IF;
    SELECT properties->NEW.property_key INTO prop FROM databases WHERE id = fdb;
    IF prop IS NULL OR prop->>'type' <> 'relation' THEN RAISE EXCEPTION 'Property "%" is not a relation of this database', NEW.property_key USING ERRCODE = 'P0001'; END IF;
    IF (prop->'relation'->>'database_id')::uuid <> tdb THEN RAISE EXCEPTION 'Relation "%" links to another database', NEW.property_key USING ERRCODE = 'P0001'; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER row_relations_guard BEFORE INSERT OR UPDATE ON row_relations FOR EACH ROW EXECUTE FUNCTION sp_row_relations_guard();

-- A dual relation is kept in step: a link written from one side appears from the other.
CREATE OR REPLACE FUNCTION sp_row_relations_dual() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE fdb uuid; prop jsonb; dual text;
BEGIN
    SELECT parent_database_id INTO fdb FROM pages WHERE id = COALESCE(NEW.from_row_id, OLD.from_row_id);
    SELECT properties->COALESCE(NEW.property_key, OLD.property_key) INTO prop FROM databases WHERE id = fdb;
    dual := prop->'relation'->>'dual_property';
    IF dual IS NULL THEN RETURN NULL; END IF;
    IF TG_OP = 'INSERT' THEN
        INSERT INTO row_relations (from_row_id, property_key, to_row_id) VALUES (NEW.to_row_id, dual, NEW.from_row_id) ON CONFLICT DO NOTHING;
    ELSIF TG_OP = 'DELETE' THEN
        DELETE FROM row_relations WHERE from_row_id = OLD.to_row_id AND property_key = dual AND to_row_id = OLD.from_row_id;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER row_relations_dual AFTER INSERT OR DELETE ON row_relations FOR EACH ROW EXECUTE FUNCTION sp_row_relations_dual();

-- A relation's rows become page_links (backlinks between rows).
CREATE OR REPLACE FUNCTION sp_row_relations_links() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO page_links (from_page_id, from_block_id, to_page_id, kind) VALUES (NEW.from_row_id, NULL, NEW.to_row_id, 'relation');
    ELSE
        DELETE FROM page_links WHERE from_page_id = OLD.from_row_id AND to_page_id = OLD.to_row_id AND kind = 'relation' AND from_block_id IS NULL;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER row_relations_links AFTER INSERT OR DELETE ON row_relations FOR EACH ROW EXECUTE FUNCTION sp_row_relations_links();

-- ---------------------------------------------------------------------------------------------
-- Writers.
-- ---------------------------------------------------------------------------------------------
-- Create a database (a page of kind database with its schema and a first table view). Returns the id.
CREATE OR REPLACE FUNCTION sp_database_create(p_space_id bigint, p_parent_page_id uuid, p_title jsonb, p_properties jsonb DEFAULT NULL,
                                              p_is_inline boolean DEFAULT false, p_id uuid DEFAULT NULL) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE did uuid; props jsonb;
BEGIN
    did := sp_page_create(p_space_id, p_parent_page_id, p_title, 'database', NULL, NULL, p_id);
    props := COALESCE(p_properties, jsonb_build_object('title', jsonb_build_object('id', 'title', 'name', 'Name', 'type', 'title')));
    INSERT INTO databases (id, properties, is_inline) VALUES (did, props, p_is_inline);
    INSERT INTO database_views (database_id, name, layout, created_by) VALUES (did, 'Table', 'table', app_current_member_id());
    RETURN did;
END$$;

-- Create a row (optionally from a template page: its blocks are copied).
CREATE OR REPLACE FUNCTION sp_row_create(p_database_id uuid, p_properties jsonb, p_template_id uuid DEFAULT NULL, p_id uuid DEFAULT NULL) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d databases%ROWTYPE; dp pages%ROWTYPE; rid uuid := COALESCE(p_id, gen_random_uuid()); ttl jsonb; me bigint := app_current_member_id(); r record; idmap jsonb := '{}'::jsonb; newb uuid;
BEGIN
    SELECT * INTO d FROM databases WHERE id = p_database_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'The database does not exist' USING ERRCODE = 'P0001'; END IF;
    SELECT * INTO dp FROM pages WHERE id = p_database_id;
    ttl := COALESCE(p_properties->d.title_property_key, '[]'::jsonb);
    INSERT INTO pages (id, space_id, parent_page_id, parent_database_id, kind, title, properties, position, owner_member_id, created_by)
    VALUES (rid, dp.space_id, p_database_id, p_database_id, 'page', ttl, COALESCE(p_properties, '{}'::jsonb),
            sp_position_between((SELECT max(position) FROM pages WHERE parent_database_id = p_database_id), NULL), COALESCE(dp.owner_member_id, me), me);
    IF p_template_id IS NOT NULL THEN
        FOR r IN WITH RECURSIVE t AS (
                     SELECT b.*, 0 AS depth FROM blocks b WHERE b.page_id = p_template_id AND b.parent_block_id IS NULL
                     UNION ALL SELECT b.*, t.depth + 1 FROM blocks b JOIN t ON b.parent_block_id = t.id)
                 SELECT * FROM t WHERE type NOT IN ('child_page', 'child_database') ORDER BY depth, position
        LOOP
            newb := gen_random_uuid();
            INSERT INTO blocks (id, page_id, parent_block_id, type, position, content, synced_from)
            VALUES (newb, rid, CASE WHEN r.parent_block_id IS NULL THEN NULL ELSE (idmap->>r.parent_block_id::text)::uuid END, r.type, r.position, r.content, r.synced_from);
            idmap := idmap || jsonb_build_object(r.id::text, newb);
        END LOOP;
    END IF;
    RETURN rid;
END$$;

-- Set a relation property's targets wholesale (the editor sends the list; dual links follow by trigger).
CREATE OR REPLACE FUNCTION sp_row_relation_set(p_row_id uuid, p_property_key text, p_targets uuid[]) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t uuid; i integer := 0; prev text := NULL;
BEGIN
    DELETE FROM row_relations WHERE from_row_id = p_row_id AND property_key = p_property_key AND NOT (to_row_id = ANY (COALESCE(p_targets, '{}')));
    FOREACH t IN ARRAY COALESCE(p_targets, '{}') LOOP
        prev := sp_position_between(prev, NULL);
        INSERT INTO row_relations (from_row_id, property_key, to_row_id, position) VALUES (p_row_id, p_property_key, t, prev)
        ON CONFLICT (from_row_id, property_key, to_row_id) DO UPDATE SET position = EXCLUDED.position;
    END LOOP;
END$$;

-- Add or change a property of the schema; a relation with dual = true writes the mirror property into the other database.
CREATE OR REPLACE FUNCTION sp_database_property_save(p_database_id uuid, p_key text, p_def jsonb) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE other uuid; dual text; mine jsonb := p_def;
BEGIN
    IF p_key !~ '^[A-Za-z0-9_][A-Za-z0-9_ -]{0,59}$' THEN RAISE EXCEPTION 'A property key is letters, digits, spaces, _ or -' USING ERRCODE = 'P0001'; END IF;
    IF mine->>'type' = 'relation' AND COALESCE((mine->'relation'->>'two_way')::boolean, false) THEN
        other := (mine->'relation'->>'database_id')::uuid;
        dual := COALESCE(mine->'relation'->>'dual_property', 'Related to ' || p_key);
        mine := jsonb_set(mine, '{relation,dual_property}', to_jsonb(dual));
        UPDATE databases SET properties = properties || jsonb_build_object(dual, jsonb_build_object('id', dual, 'name', dual, 'type', 'relation',
                 'relation', jsonb_build_object('database_id', p_database_id, 'dual_property', p_key, 'two_way', true)))
         WHERE id = other AND other <> p_database_id;
    END IF;
    UPDATE databases SET properties = properties || jsonb_build_object(p_key, mine || jsonb_build_object('id', COALESCE(mine->>'id', p_key), 'name', COALESCE(mine->>'name', p_key))) WHERE id = p_database_id;
END$$;

-- Remove a property from the schema (the rows keep their values until purged — Notion's behaviour); a dual's mirror goes too.
CREATE OR REPLACE FUNCTION sp_database_property_remove(p_database_id uuid, p_key text, p_purge_values boolean DEFAULT false) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE def jsonb;
BEGIN
    SELECT properties->p_key INTO def FROM databases WHERE id = p_database_id;
    IF def IS NULL THEN RETURN; END IF;
    IF def->>'type' = 'title' THEN RAISE EXCEPTION 'The title property stays' USING ERRCODE = 'P0001'; END IF;
    IF EXISTS (SELECT 1 FROM databases d, jsonb_each(d.properties) e WHERE d.id = p_database_id AND e.value->>'type' = 'rollup' AND e.value->'rollup'->>'relation' = p_key) THEN
        RAISE EXCEPTION 'A rollup goes through "%": remove the rollup first', p_key USING ERRCODE = 'P0001';
    END IF;
    IF def->>'type' = 'relation' AND def->'relation'->>'dual_property' IS NOT NULL THEN
        UPDATE databases SET properties = properties - (def->'relation'->>'dual_property') WHERE id = (def->'relation'->>'database_id')::uuid;
        DELETE FROM row_relations WHERE property_key = p_key AND from_row_id IN (SELECT id FROM pages WHERE parent_database_id = p_database_id);
    END IF;
    UPDATE databases SET properties = properties - p_key WHERE id = p_database_id;
    IF p_purge_values THEN
        UPDATE pages SET properties = properties - p_key WHERE parent_database_id = p_database_id AND properties ? p_key;
    END IF;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON databases, database_views, row_relations, unique_id_sequences TO spaces_rw;
REVOKE ALL ON FUNCTION sp_database_create(bigint, uuid, jsonb, jsonb, boolean, uuid), sp_row_create(uuid, jsonb, uuid, uuid), sp_row_relation_set(uuid, text, uuid[]),
    sp_database_property_save(uuid, text, jsonb), sp_database_property_remove(uuid, text, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sp_database_create(bigint, uuid, jsonb, jsonb, boolean, uuid), sp_row_create(uuid, jsonb, uuid, uuid), sp_row_relation_set(uuid, text, uuid[]),
    sp_database_property_save(uuid, text, jsonb), sp_database_property_remove(uuid, text, boolean) TO spaces_rw;

COMMIT;
