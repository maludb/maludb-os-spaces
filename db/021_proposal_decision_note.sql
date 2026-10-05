-- 021: a dismissed proposal keeps the reason it was dismissed for (slice 7, docs/build-specs/agents-in-spaces.md "The Librarian's proposals").
-- The spec says `proposal_dismiss` keeps the reason, and the Librarian reads dismissed proposals so as not to propose them again within
-- 90 days — but librarian_proposals (db/012) has no column for it: `reason` is the Librarian's own reason for PROPOSING. A defect of the
-- schema, closed additively: one nullable column and the view that shows it, appended last (the grants of the view are kept).
BEGIN;

ALTER TABLE librarian_proposals ADD COLUMN decision_note text CHECK (decision_note IS NULL OR length(decision_note) <= 500);

CREATE OR REPLACE VIEW mcp_librarian_proposals WITH (security_barrier = true) AS
SELECT l.id AS proposal_id, l.kind, l.subject_page_id, l.subject_message_id, l.subject_channel_id, l.proposed_page_id, l.title, l.reason, l.status, l.proposed_by,
       l.decided_by, l.decided_at, l.created_at, l.decision_note
  FROM librarian_proposals l
 WHERE (SELECT sp_is_member_here())
   AND (l.subject_page_id IS NULL OR l.subject_page_id IN (SELECT sp_visible_page_ids()))
   AND (l.subject_channel_id IS NULL OR l.subject_channel_id IN (SELECT sp_visible_channel_ids()));

COMMIT;
