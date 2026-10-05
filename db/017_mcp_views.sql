-- 017: WHO SEES WHAT — the mcp_* visibility views, the only thing the two read roles see (memory.md §1; design §3, §6).
-- Every WHERE clause IS the row rule; the servers never filter in Python. Caller checks are scalar subqueries or uncorrelated
-- sets tested ONCE per statement (the kernel's db/160 lesson): sp_visible_page_ids(), sp_visible_channel_ids(),
-- sp_visible_space_ids(), sp_visible_member_ids(). Plus the two functions the MCP servers need at first contact.
--
-- The primary key is re-aliased <entity>_id; raw columns that hide internals are not selected (a publication's token hash,
-- a session hash, an outbox body). A message body and a page's blocks ARE content the caller may read — they are here.
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO spaces_records_ro, spaces_activity_ro, spaces_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO spaces_records_ro, spaces_activity_ro, spaces_rw;

-- ---------------------------------------------------------------------------------------------
-- People, departments, settings, vocabulary.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.member_kind = 'agent' AS is_agent, m.business_role, m.is_external, sp_member_is_guest(m.id) AS is_guest,
       m.status, m.capability, m.roles, m.job_title, m.timezone,
       CASE WHEN m.id = app_current_member_id() THEN m.email::text END AS email,
       m.status_text, m.status_emoji, m.status_until, m.last_seen_at, m.last_seen_at > now() - interval '5 minutes' AS is_active_now,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m
 WHERE m.status = 'active' AND m.capability IS NOT NULL AND m.id IN (SELECT sp_visible_member_ids());

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at,
       (SELECT s.id FROM spaces s WHERE s.department_id = d.id) AS space_id
  FROM departments d WHERE (SELECT sp_is_member_here());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.default_space_kind, s.default_member_level, s.default_everyone_level, s.version_snapshot_minutes, s.version_retention_days,
       s.trash_retention_days, s.stale_page_days, s.unanswered_hours, s.wiki_default_verify_months, s.max_attachment_bytes, s.public_pages_noindex,
       s.digest_hour, s.week_start_dow, s.timezone, s.group_dm_max_members, s.away_minutes, s.admin_channel_name, s.updated_at
  FROM sp_settings s WHERE s.id = 1 AND (SELECT app_is_active_member());

CREATE OR REPLACE VIEW mcp_emoji WITH (security_barrier = true) AS
SELECT e.shortcode, e.emoji, e.keywords FROM emoji_shortcodes e WHERE (SELECT app_is_active_member());

-- ---------------------------------------------------------------------------------------------
-- Spaces.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_spaces WITH (security_barrier = true) AS
SELECT s.id AS space_id, s.name, s.slug, s.icon, s.description, s.kind, s.is_default, s.department_id, s.member_level, s.everyone_level, s.is_wiki,
       s.wiki_default_verify_months, s.default_channel_id, s.created_by, s.archived_at, s.created_at, s.updated_at,
       s.id IN (SELECT sp_member_space_ids()) AS i_am_member,
       sp_is_space_owner(s.id) AS i_am_owner,
       (SELECT count(*) FROM sp_space_member_ids(s.id)) AS member_count,
       (SELECT count(*) FROM pages p WHERE p.space_id = s.id AND p.archived_at IS NULL AND NOT p.is_template AND p.parent_database_id IS NULL) AS page_count,
       (SELECT count(*) FROM channels c WHERE c.space_id = s.id AND c.archived_at IS NULL) AS channel_count
  FROM spaces s WHERE s.id IN (SELECT sp_visible_space_ids());

CREATE OR REPLACE VIEW mcp_space_members WITH (security_barrier = true) AS
SELECT s.id AS space_id, x.member_id, m.display_name, m.member_kind = 'agent' AS is_agent, sp_member_is_guest(m.id) AS is_guest,
       COALESCE(sm.role, 'member') AS role, sm.joined_at, sm.member_id IS NULL AS derived
  FROM spaces s CROSS JOIN LATERAL sp_space_member_ids(s.id) AS x(member_id)
  JOIN members m ON m.id = x.member_id
  LEFT JOIN space_members sm ON sm.space_id = s.id AND sm.member_id = x.member_id
 WHERE s.id IN (SELECT sp_visible_space_ids()) AND x.member_id IN (SELECT sp_visible_member_ids());

CREATE OR REPLACE VIEW mcp_space_join_requests WITH (security_barrier = true) AS
SELECT r.id AS join_request_id, r.space_id, r.member_id, m.display_name, r.message, r.status, r.decided_by, r.decided_at, r.created_at
  FROM space_join_requests r JOIN members m ON m.id = r.member_id
 WHERE r.member_id = app_current_member_id() OR sp_is_space_owner(r.space_id);

CREATE OR REPLACE VIEW mcp_space_sections WITH (security_barrier = true) AS
SELECT x.id AS section_id, x.space_id, x.name, x.position FROM space_sections x WHERE x.space_id IN (SELECT sp_visible_space_ids());

-- ---------------------------------------------------------------------------------------------
-- Pages, blocks, versions, links, favorites, recents, the trash.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_pages WITH (security_barrier = true) AS
SELECT p.id AS page_id, p.space_id, p.parent_page_id, p.parent_database_id, p.kind, p.title, p.plain_title, p.icon, p.cover_attachment_id, p.is_locked, p.is_template,
       p.template_of_database_id, p.owner_member_id, p.wiki_owner_member_id,
       CASE WHEN p.verification_state = 'verified' AND p.verify_until < now() THEN 'expired' ELSE p.verification_state END AS verification_state,
       p.verified_at, p.verified_by, p.verify_until, p.properties, p.permission_root_id, p.position, p.section_id, p.content_rev, p.version_no,
       p.created_by, p.created_at, p.last_edited_by, p.last_edited_at, p.archived_at, p.archived_by, p.updated_at,
       sp_page_level(p.id) AS my_level,
       EXISTS (SELECT 1 FROM page_publications pb WHERE pb.page_id = p.id AND pb.revoked_at IS NULL) AS is_published,
       EXISTS (SELECT 1 FROM page_favorites f WHERE f.page_id = p.id AND f.member_id = app_current_member_id()) AS is_favorite,
       (SELECT count(*) FROM pages c WHERE c.parent_page_id = p.id AND c.archived_at IS NULL AND c.parent_database_id IS NULL) AS child_count,
       (SELECT count(*) FROM comments c WHERE c.page_id = p.id AND c.resolved_at IS NULL AND c.deleted_at IS NULL AND c.parent_comment_id IS NULL) AS open_comment_count
  FROM pages p WHERE p.id IN (SELECT sp_visible_page_ids());

CREATE OR REPLACE VIEW mcp_blocks WITH (security_barrier = true) AS
SELECT b.id AS block_id, b.page_id, b.parent_block_id, b.type, b.position, b.content, b.plain_text, b.has_children, b.version, b.synced_from,
       b.created_by, b.created_at, b.last_edited_by, b.last_edited_at
  FROM blocks b WHERE b.page_id IN (SELECT sp_visible_page_ids());

CREATE OR REPLACE VIEW mcp_page_permissions WITH (security_barrier = true) AS
SELECT pp.page_id, pp.principal_kind, pp.principal_id, pp.level, pp.granted_by, pp.created_at
  FROM page_permissions pp WHERE pp.page_id IN (SELECT sp_visible_page_ids()) AND sp_level_rank(sp_page_level(pp.page_id)) >= 4;

CREATE OR REPLACE VIEW mcp_page_links WITH (security_barrier = true) AS
SELECT l.from_page_id, l.from_block_id, l.to_page_id, l.kind
  FROM page_links l WHERE l.from_page_id IN (SELECT sp_visible_page_ids()) OR l.to_page_id IN (SELECT sp_visible_page_ids());

CREATE OR REPLACE VIEW mcp_page_versions WITH (security_barrier = true) AS
SELECT v.id AS version_id, v.page_id, v.version_no, v.content_rev, v.reason, v.saved_by, v.created_at, length(v.plain_text) AS text_length
  FROM page_versions v WHERE v.page_id IN (SELECT sp_visible_page_ids());

CREATE OR REPLACE VIEW mcp_page_favorites WITH (security_barrier = true) AS
SELECT f.member_id, f.page_id, f.position, f.created_at FROM page_favorites f WHERE f.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_page_recents WITH (security_barrier = true) AS
SELECT r.member_id, r.page_id, r.visited_at FROM page_recents r WHERE r.member_id = app_current_member_id() AND r.page_id IN (SELECT sp_visible_page_ids());

CREATE OR REPLACE VIEW mcp_page_publications WITH (security_barrier = true) AS
SELECT pb.id AS publication_id, pb.page_id, pb.include_subpages, pb.noindex, pb.public_properties, pb.layout, pb.published_by, pb.published_at, pb.revoked_at, pb.revoked_by,
       pb.views, pb.last_viewed_at
  FROM page_publications pb WHERE (SELECT sp_is_admin()) OR (pb.page_id IN (SELECT sp_visible_page_ids()) AND sp_has_full_page(pb.page_id));

CREATE OR REPLACE VIEW mcp_trash WITH (security_barrier = true) AS
SELECT p.id AS page_id, p.plain_title, p.icon, p.kind, p.space_id, p.archived_at, p.archived_by, p.archived_via,
       p.archived_at + make_interval(days => (SELECT trash_retention_days FROM sp_settings WHERE id = 1)) AS purge_at
  FROM pages p WHERE p.archived_at IS NOT NULL AND p.archived_via IS NULL AND p.id IN (SELECT sp_visible_page_ids());

-- ---------------------------------------------------------------------------------------------
-- Databases.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_databases WITH (security_barrier = true) AS
SELECT d.id AS database_id, p.plain_title AS title, p.icon, p.space_id, p.parent_page_id, d.description, d.is_inline, d.properties, d.title_property_key, d.row_template_id,
       d.created_at, d.updated_at, p.archived_at,
       (SELECT count(*) FROM pages r WHERE r.parent_database_id = d.id AND r.archived_at IS NULL AND NOT r.is_template) AS row_count
  FROM databases d JOIN pages p ON p.id = d.id WHERE d.id IN (SELECT sp_visible_page_ids());

CREATE OR REPLACE VIEW mcp_database_views WITH (security_barrier = true) AS
SELECT v.id AS view_id, v.database_id, v.name, v.layout, v.filter, v.sort, v.group_by, v.sub_group_by, v.visible_properties, v.calendar_by, v.timeline_start, v.timeline_end,
       v.card_size, v.card_cover, v.wrap, v.linked_from_page_id, v.position, v.created_by, v.created_at, v.updated_at
  FROM database_views v WHERE v.database_id IN (SELECT sp_visible_page_ids());

CREATE OR REPLACE VIEW mcp_row_relations WITH (security_barrier = true) AS
SELECT rr.from_row_id, rr.property_key, rr.to_row_id, rr.position
  FROM row_relations rr WHERE rr.from_row_id IN (SELECT sp_visible_page_ids()) AND rr.to_row_id IN (SELECT sp_visible_page_ids());

-- ---------------------------------------------------------------------------------------------
-- Channels and messages.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_channels WITH (security_barrier = true) AS
SELECT c.id AS channel_id, c.space_id, c.kind, c.name, c.topic, c.purpose, c.is_default, c.created_by, c.archived_at, c.retention_days, c.last_message_at, c.message_count,
       c.created_at, c.updated_at,
       sp_in_channel(c.id) AS i_am_member,
       EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = c.id AND cm.member_id = app_current_member_id()) AS i_follow,
       (SELECT cm.starred FROM channel_members cm WHERE cm.channel_id = c.id AND cm.member_id = app_current_member_id()) AS starred,
       (SELECT cm.notify FROM channel_members cm WHERE cm.channel_id = c.id AND cm.member_id = app_current_member_id()) AS notify,
       (SELECT cm.muted_until FROM channel_members cm WHERE cm.channel_id = c.id AND cm.member_id = app_current_member_id()) AS muted_until,
       (SELECT count(*) FROM channel_members cm WHERE cm.channel_id = c.id) AS member_count
  FROM channels c WHERE c.id IN (SELECT sp_visible_channel_ids());

CREATE OR REPLACE VIEW mcp_channel_members WITH (security_barrier = true) AS
SELECT cm.channel_id, cm.member_id, m.display_name, m.member_kind = 'agent' AS is_agent, cm.joined_at, cm.added_by,
       CASE WHEN cm.member_id = app_current_member_id() THEN cm.last_read_message_id END AS last_read_message_id
  FROM channel_members cm JOIN members m ON m.id = cm.member_id
 WHERE cm.channel_id IN (SELECT sp_visible_channel_ids()) AND cm.member_id IN (SELECT sp_visible_member_ids());

CREATE OR REPLACE VIEW mcp_messages WITH (security_barrier = true) AS
SELECT m.id AS message_id, m.channel_id, m.thread_root_id, m.author_member_id, a.display_name AS author_name, a.member_kind = 'agent' AS author_is_agent, m.kind,
       CASE WHEN m.deleted_at IS NULL THEN m.body ELSE '[]'::jsonb END AS body,
       CASE WHEN m.deleted_at IS NULL THEN m.plain_text ELSE '' END AS plain_text,
       m.reply_count, m.last_reply_at, m.also_to_channel, m.edited_at, m.deleted_at, m.scheduled_for, m.sent_at, m.agent_run_id, m.attachment_count, m.created_at
  FROM messages m LEFT JOIN members a ON a.id = m.author_member_id
 WHERE m.channel_id IN (SELECT sp_visible_channel_ids()) AND (m.sent_at IS NOT NULL OR m.author_member_id = app_current_member_id());

CREATE OR REPLACE VIEW mcp_message_reactions WITH (security_barrier = true) AS
SELECT r.message_id, r.member_id, r.emoji, r.created_at
  FROM message_reactions r JOIN messages m ON m.id = r.message_id WHERE m.channel_id IN (SELECT sp_visible_channel_ids());

CREATE OR REPLACE VIEW mcp_message_mentions WITH (security_barrier = true) AS
SELECT mm.message_id, mm.kind, mm.principal_id
  FROM message_mentions mm JOIN messages m ON m.id = mm.message_id WHERE m.channel_id IN (SELECT sp_visible_channel_ids());

CREATE OR REPLACE VIEW mcp_saved_messages WITH (security_barrier = true) AS
SELECT s.member_id, s.message_id, s.created_at FROM saved_messages s WHERE s.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_channel_pins WITH (security_barrier = true) AS
SELECT p.id AS pin_id, p.channel_id, p.message_id, p.page_id, p.pinned_by, p.created_at FROM channel_pins p WHERE p.channel_id IN (SELECT sp_visible_channel_ids());

CREATE OR REPLACE VIEW mcp_channel_bookmarks WITH (security_barrier = true) AS
SELECT b.id AS bookmark_id, b.channel_id, b.title, b.url, b.page_id, b.emoji, b.position, b.created_by, b.created_at FROM channel_bookmarks b WHERE b.channel_id IN (SELECT sp_visible_channel_ids());

CREATE OR REPLACE VIEW mcp_reminders WITH (security_barrier = true) AS
SELECT r.id AS reminder_id, r.member_id, r.message_id, r.page_id, r.text, r.remind_at, r.done_at, r.notified_at, r.created_at FROM reminders r WHERE r.member_id = app_current_member_id();

-- ---------------------------------------------------------------------------------------------
-- Comments, notifications, files, agents, imports and exports.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_comments WITH (security_barrier = true) AS
SELECT c.id AS comment_id, c.page_id, c.block_id, c.parent_comment_id, c.author_member_id, a.display_name AS author_name, a.member_kind = 'agent' AS author_is_agent,
       CASE WHEN c.deleted_at IS NULL THEN c.body ELSE '[]'::jsonb END AS body, CASE WHEN c.deleted_at IS NULL THEN c.plain_text ELSE '' END AS plain_text,
       c.resolved_at, c.resolved_by, c.edited_at, c.deleted_at, c.agent_run_id, c.created_at
  FROM comments c LEFT JOIN members a ON a.id = c.author_member_id WHERE c.page_id IN (SELECT sp_visible_page_ids());

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.member_id, n.kind, n.title, n.body, n.record_type, n.record_id, n.record_uuid, n.channel_id, n.message_id, n.actor_member_id, n.read_at, n.created_at
  FROM notifications n WHERE n.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_notification_prefs WITH (security_barrier = true) AS
SELECT p.member_id, p.email_enabled, p.text_enabled, p.kinds, p.text_kinds, p.digest, p.away_minutes, p.updated_at FROM notification_prefs p WHERE p.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.record_type, a.record_id, a.record_uuid, a.filename, a.mime_type, a.byte_size, a.width, a.height, a.thumbnail_path IS NOT NULL AS has_thumbnail,
       a.uploaded_by, a.created_at
  FROM attachments a WHERE sp_can_see_attachment(a.id);

CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS dispatch_id, d.record_type, d.record_id, d.record_uuid, d.agent_member_id, m.display_name AS agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.request_id,
       d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at, d.channel_id, d.conversation_id, d.reply_message_id
  FROM agent_dispatches d JOIN members m ON m.id = d.agent_member_id
 WHERE (SELECT sp_is_admin()) OR d.acting_member_id = app_current_member_id() OR d.agent_member_id = app_current_member_id()
    OR (d.channel_id IS NOT NULL AND d.channel_id IN (SELECT sp_visible_channel_ids()));

CREATE OR REPLACE VIEW mcp_librarian_proposals WITH (security_barrier = true) AS
SELECT l.id AS proposal_id, l.kind, l.subject_page_id, l.subject_message_id, l.subject_channel_id, l.proposed_page_id, l.title, l.reason, l.status, l.proposed_by,
       l.decided_by, l.decided_at, l.created_at
  FROM librarian_proposals l
 WHERE (SELECT sp_is_member_here())
   AND (l.subject_page_id IS NULL OR l.subject_page_id IN (SELECT sp_visible_page_ids()))
   AND (l.subject_channel_id IS NULL OR l.subject_channel_id IN (SELECT sp_visible_channel_ids()));

CREATE OR REPLACE VIEW mcp_imports WITH (security_barrier = true) AS
SELECT i.id AS import_id, i.kind, i.file_name, i.target_space_id, i.target_page_id, i.target_database_id, i.status, i.pages_made, i.rows_made, i.blocks_made, i.unsupported, i.log,
       i.created_by, i.created_at, i.finished_at
  FROM imports i WHERE i.created_by = app_current_member_id() OR (SELECT sp_is_admin());

CREATE OR REPLACE VIEW mcp_exports WITH (security_barrier = true) AS
SELECT e.id AS export_id, e.kind, e.format, e.page_id, e.space_id, e.channel_id, e.include_subpages, e.status, e.byte_size, e.item_count, e.log, e.created_by, e.created_at,
       e.finished_at, e.expires_at, e.downloaded_at, e.storage_path IS NOT NULL AS available
  FROM exports e WHERE e.created_by = app_current_member_id() OR (SELECT sp_is_admin());

CREATE OR REPLACE VIEW mcp_access_tokens_mine WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.label, t.scope, t.last_used_at, t.expires_at, t.revoked_at, t.created_at FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id();

-- ---------------------------------------------------------------------------------------------
-- Activity: a record's history is the log (design §6). A message body or page text is never in a payload, so nothing to hide
-- but the request internals. The caller sees rows about records they may see, and their own rows.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.occurred_at, a.actor_member_id, m.display_name AS actor_name, m.member_kind = 'agent' AS actor_is_agent, a.source, a.action, a.screen, a.route,
       a.entity_type, a.entity_id, a.entity_uuid, a.before, a.after, a.request_id, a.agent_run_id, a.department_id, a.space_id, a.channel_id, a.message_id
  FROM activity_log a LEFT JOIN members m ON m.id = a.actor_member_id
 WHERE (SELECT app_is_active_member())
   AND (a.actor_member_id = app_current_member_id()
        OR (SELECT sp_is_admin())
        OR (a.entity_uuid IS NOT NULL AND a.entity_uuid IN (SELECT sp_visible_page_ids()))
        OR (a.entity_uuid IS NOT NULL AND a.entity_uuid IN (SELECT b.id FROM blocks b WHERE b.page_id IN (SELECT sp_visible_page_ids())))
        OR (a.entity_uuid IS NOT NULL AND a.entity_uuid IN (SELECT c.id FROM comments c WHERE c.page_id IN (SELECT sp_visible_page_ids())))
        OR (a.channel_id IS NOT NULL AND a.channel_id IN (SELECT sp_visible_channel_ids()))
        OR (a.space_id IS NOT NULL AND a.entity_uuid IS NULL AND a.channel_id IS NULL AND a.space_id IN (SELECT sp_visible_space_ids())));

-- ---------------------------------------------------------------------------------------------
-- Grants: the read roles see the views and nothing else; the writer reads them too (one shape for screens and tools).
-- ---------------------------------------------------------------------------------------------
GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_emoji, mcp_spaces, mcp_space_members, mcp_space_join_requests, mcp_space_sections,
    mcp_pages, mcp_blocks, mcp_page_permissions, mcp_page_links, mcp_page_versions, mcp_page_favorites, mcp_page_recents, mcp_page_publications, mcp_trash,
    mcp_databases, mcp_database_views, mcp_row_relations, mcp_channels, mcp_channel_members, mcp_messages, mcp_message_reactions, mcp_message_mentions,
    mcp_saved_messages, mcp_channel_pins, mcp_channel_bookmarks, mcp_reminders, mcp_comments, mcp_notifications, mcp_notification_prefs, mcp_attachments,
    mcp_agent_dispatches, mcp_librarian_proposals, mcp_imports, mcp_exports, mcp_access_tokens_mine
    TO spaces_records_ro, spaces_rw;
GRANT SELECT ON mcp_activity_log TO spaces_activity_ro, spaces_rw;
GRANT SELECT ON mcp_members, mcp_departments, mcp_spaces, mcp_pages, mcp_channels, mcp_messages TO spaces_activity_ro;   -- names and record facts beside an activity row

COMMIT;
