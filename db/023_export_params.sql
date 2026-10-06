-- 023: two defects slice 8's proof found in db/016 (docs/build-specs/worker-import-export.md).
--
-- 1. An export carries what it was asked for.
--
-- WHY: db/016's `exports` row names the page, the space or the channel, but the spec's `export_database` takes a `view` (the view's visible properties and filter) and
-- `export_channel` takes a period `from`/`to`; a space or channel export runs later in the worker, so those parameters must live on the row — there is no column for them. A
-- jsonb `params` (view_id, from, to) is appended, never modifying 016, and the read view gains it LAST (a view's columns are only ever appended).
BEGIN;

ALTER TABLE exports ADD COLUMN params jsonb NOT NULL DEFAULT '{}'::jsonb;

CREATE OR REPLACE VIEW mcp_exports WITH (security_barrier = true) AS
SELECT e.id AS export_id, e.kind, e.format, e.page_id, e.space_id, e.channel_id, e.include_subpages, e.status, e.byte_size, e.item_count, e.log, e.created_by, e.created_at,
       e.finished_at, e.expires_at, e.downloaded_at, e.storage_path IS NOT NULL AS available, e.params
  FROM exports e WHERE e.created_by = app_current_member_id() OR (SELECT sp_is_admin());

-- 2. sp_pass_exports_expire() was meant to answer the paths of the files to remove, but `UPDATE … SET storage_path = NULL … RETURNING storage_path` returns the NEW value: it always answered
--    NULLs and the worker never knew which files to unlink. It now answers the paths the rows HELD (read first, then cleared), the same signature, the same grants.
CREATE OR REPLACE FUNCTION sp_pass_exports_expire() RETURNS SETOF text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        WITH old AS (SELECT x.id, x.storage_path FROM exports x WHERE x.storage_path IS NOT NULL AND x.expires_at < now() FOR UPDATE),
             upd AS (UPDATE exports e SET storage_path = NULL FROM old WHERE e.id = old.id RETURNING old.storage_path AS path)
        SELECT path FROM upd;
END$$;

COMMIT;
