-- 011: COMMENTS and discussions on a page or a block (design §0.1, §6).
--
-- A page's comments show at its top; a block's in the margin; a reply hangs off a comment (one level, a discussion); a
-- discussion is resolved as a whole. A `comment` permission level writes comments and nothing else (db/007).
--
-- THE ESTATE'S DATA MODEL (design §6.1): new, recorded. Projects' `comments` (bigint, on an issue, Markdown) was compared:
-- the family is the same — the record key, the author, a body, edited_at, deleted_at — the key is a UUID page or block and
-- the body is the one rich text; the name is kept, as every sibling's `comments` means "a comment on one of my records".
BEGIN;

CREATE TABLE comments (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    page_id            uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    block_id           uuid REFERENCES blocks(id) ON DELETE CASCADE,                   -- an inline comment; NULL = on the page
    parent_comment_id  uuid REFERENCES comments(id) ON DELETE CASCADE,                 -- a reply in a discussion
    author_member_id   bigint REFERENCES members(id) ON DELETE SET NULL,
    body               jsonb NOT NULL CHECK (sp_is_rich_text(body)),
    plain_text         text GENERATED ALWAYS AS (sp_rich_text_plain(body)) STORED,
    resolved_at        timestamptz,
    resolved_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    edited_at          timestamptz,
    deleted_at         timestamptz,
    deleted_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    agent_run_id       bigint,
    created_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX comments_page_idx   ON comments (page_id, created_at);
CREATE INDEX comments_block_idx  ON comments (block_id) WHERE block_id IS NOT NULL;
CREATE INDEX comments_parent_idx ON comments (parent_comment_id) WHERE parent_comment_id IS NOT NULL;
CREATE INDEX comments_author_idx ON comments (author_member_id, created_at DESC);
CREATE INDEX comments_open_idx   ON comments (page_id) WHERE resolved_at IS NULL AND parent_comment_id IS NULL AND deleted_at IS NULL;

CREATE TABLE comment_mentions (
    comment_id   uuid NOT NULL REFERENCES comments(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('member', 'agent', 'department')),
    principal_id bigint NOT NULL,
    PRIMARY KEY (comment_id, kind, principal_id)
);
CREATE INDEX comment_mentions_principal_idx ON comment_mentions (kind, principal_id);

CREATE OR REPLACE FUNCTION sp_comments_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE par comments%ROWTYPE; me bigint := app_current_member_id();
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.author_member_id IS NULL THEN NEW.author_member_id := me; END IF;
        IF NEW.block_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM blocks b WHERE b.id = NEW.block_id AND b.page_id = NEW.page_id) THEN
            RAISE EXCEPTION 'The block is not on this page' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.parent_comment_id IS NOT NULL THEN
            SELECT * INTO par FROM comments WHERE id = NEW.parent_comment_id;
            IF NOT FOUND THEN RAISE EXCEPTION 'The discussion does not exist' USING ERRCODE = 'P0001'; END IF;
            IF par.parent_comment_id IS NOT NULL THEN RAISE EXCEPTION 'A discussion is one level deep: reply to its first comment' USING ERRCODE = 'P0001'; END IF;
            IF par.page_id <> NEW.page_id THEN RAISE EXCEPTION 'A reply stays in its discussion''s page' USING ERRCODE = 'P0001'; END IF;
            NEW.block_id := par.block_id;
            IF par.resolved_at IS NOT NULL THEN RAISE EXCEPTION 'That discussion is resolved: reopen it to reply' USING ERRCODE = 'P0001'; END IF;
        END IF;
        IF EXISTS (SELECT 1 FROM pages p WHERE p.id = NEW.page_id AND p.archived_at IS NOT NULL) THEN
            RAISE EXCEPTION 'The page is in the trash' USING ERRCODE = 'P0001';
        END IF;
    ELSE
        IF (NEW.page_id, NEW.block_id, NEW.parent_comment_id, NEW.author_member_id, NEW.created_at) IS DISTINCT FROM (OLD.page_id, OLD.block_id, OLD.parent_comment_id, OLD.author_member_id, OLD.created_at) THEN
            RAISE EXCEPTION 'A comment stays where and whose it is' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.body IS DISTINCT FROM OLD.body THEN
            IF OLD.deleted_at IS NOT NULL THEN RAISE EXCEPTION 'A deleted comment is not edited' USING ERRCODE = 'P0001'; END IF;
            IF NEW.deleted_at IS NULL AND OLD.author_member_id IS DISTINCT FROM me THEN RAISE EXCEPTION 'Only the author edits a comment' USING ERRCODE = 'P0001'; END IF;
            IF NEW.deleted_at IS NULL THEN NEW.edited_at := now(); END IF;
        END IF;
        IF NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL THEN
            NEW.body := '[]'::jsonb; NEW.deleted_by := COALESCE(NEW.deleted_by, me);
        END IF;
        IF NEW.resolved_at IS NOT NULL AND OLD.resolved_at IS NULL THEN
            IF NEW.parent_comment_id IS NOT NULL THEN RAISE EXCEPTION 'Resolve the discussion, not a reply' USING ERRCODE = 'P0001'; END IF;
            NEW.resolved_by := COALESCE(NEW.resolved_by, me);
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER comments_guard BEFORE INSERT OR UPDATE ON comments FOR EACH ROW EXECUTE FUNCTION sp_comments_guard();

CREATE OR REPLACE FUNCTION sp_comments_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r jsonb;
BEGIN
    IF TG_OP = 'INSERT' OR NEW.body IS DISTINCT FROM OLD.body THEN
        DELETE FROM comment_mentions WHERE comment_id = NEW.id;
        FOR r IN SELECT x FROM jsonb_array_elements(NEW.body) x WHERE x->>'type' = 'mention' AND (x->'mention'->>'id') ~ '^[0-9]+$' LOOP
            IF r->'mention'->>'type' IN ('member', 'agent') THEN
                INSERT INTO comment_mentions (comment_id, kind, principal_id)
                SELECT NEW.id, CASE WHEN m.member_kind = 'agent' THEN 'agent' ELSE 'member' END, m.id FROM members m WHERE m.id = (r->'mention'->>'id')::bigint
                ON CONFLICT DO NOTHING;
            ELSIF r->'mention'->>'type' = 'department' THEN
                INSERT INTO comment_mentions (comment_id, kind, principal_id) VALUES (NEW.id, 'department', (r->'mention'->>'id')::bigint) ON CONFLICT DO NOTHING;
            END IF;
        END LOOP;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER comments_after AFTER INSERT OR UPDATE ON comments FOR EACH ROW EXECUTE FUNCTION sp_comments_after();

GRANT SELECT, INSERT, UPDATE, DELETE ON comments, comment_mentions TO spaces_rw;

COMMIT;
