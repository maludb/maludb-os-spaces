-- 018: a guard must step aside when its parent is being deleted (found by slice 1's proof, 2026-10-05).
--
-- Deleting a space (space_delete: archived, no live page) cascades to space_members; sp_space_members_guard (db/006) then read a
-- space row that was already gone ("Space "<NULL>" needs an owner") and refused the cascade, so no space could ever be deleted.
-- The rule stands for a row deleted on its own; when the space itself is gone there is nothing left to keep an owner for.
-- Additive: the function is replaced, the trigger unchanged. Never modify db/006.
BEGIN;

CREATE OR REPLACE FUNCTION sp_space_members_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s spaces%ROWTYPE;
BEGIN
    SELECT * INTO s FROM spaces WHERE id = COALESCE(NEW.space_id, OLD.space_id);
    IF TG_OP = 'DELETE' THEN
        IF NOT FOUND THEN RETURN OLD; END IF;                       -- the space is being deleted: the rows go with it
        IF s.is_default AND NOT sp_member_is_guest(OLD.member_id) AND EXISTS (SELECT 1 FROM members m WHERE m.id = OLD.member_id AND m.status = 'active' AND m.capability IS NOT NULL) THEN
            RAISE EXCEPTION 'Nobody leaves the default space' USING ERRCODE = 'P0001';
        END IF;
        IF OLD.role = 'owner' AND NOT EXISTS (SELECT 1 FROM space_members sm WHERE sm.space_id = OLD.space_id AND sm.role = 'owner' AND sm.member_id <> OLD.member_id) THEN
            RAISE EXCEPTION 'Space "%" needs an owner: name another owner first', s.name USING ERRCODE = 'P0001';
        END IF;
        RETURN OLD;
    END IF;
    IF sp_member_is_guest(NEW.member_id) AND NEW.role = 'owner' THEN
        RAISE EXCEPTION 'A guest never owns a space' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.role = 'owner' AND NEW.role <> 'owner'
       AND NOT EXISTS (SELECT 1 FROM space_members sm WHERE sm.space_id = NEW.space_id AND sm.role = 'owner' AND sm.member_id <> NEW.member_id) THEN
        RAISE EXCEPTION 'Space "%" needs an owner: name another owner first', s.name USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END$$;

COMMIT;
