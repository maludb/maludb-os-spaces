-- 015: READING channels, the inbox and the wiki — the functions the screens, the tools and the Librarian call (design §6, §7).
--
--   sp_channel_history(channel, before, limit)   the channel's messages (top-level and also-to-channel), newest last
--   sp_thread(root)                               a thread in order
--   sp_thread_context(root, limit)                the last N turns as the chat endpoint's conversation
--   sp_unread(member)                             every channel's unread count and first unread id
--   sp_activity_feed(member, since)               mentions, replies, reactions to me
--   sp_my_dms(member)                             the open DMs with their last line
--   sp_sidebar(member)                            spaces → sections → root pages, channels; Shared; Private; DMs
--   sp_backlinks(page)                            what links here
--   sp_wiki_status(space), sp_stale_pages(days), sp_orphan_pages(), sp_broken_links(), sp_duplicate_titles(),
--   sp_unanswered_questions(hours), sp_thread_candidates(min_replies)   the Librarian's questions (design §5)
--
-- Every function answers as the CALLER: the visible sets are computed once per call.
BEGIN;

CREATE OR REPLACE FUNCTION sp_message_row(m messages) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT jsonb_build_object(
        'message_id', m.id, 'channel_id', m.channel_id, 'thread_root_id', m.thread_root_id, 'kind', m.kind,
        'author_member_id', m.author_member_id, 'author_name', (SELECT display_name FROM members WHERE id = m.author_member_id),
        'author_is_agent', (SELECT member_kind = 'agent' FROM members WHERE id = m.author_member_id),
        'body', CASE WHEN m.deleted_at IS NULL THEN m.body ELSE '[]'::jsonb END, 'plain_text', CASE WHEN m.deleted_at IS NULL THEN m.plain_text ELSE '' END,
        'markdown', CASE WHEN m.deleted_at IS NULL THEN sp_rich_text_markdown(m.body) ELSE '' END,
        'reply_count', m.reply_count, 'last_reply_at', m.last_reply_at, 'also_to_channel', m.also_to_channel,
        'edited_at', m.edited_at, 'deleted_at', m.deleted_at, 'scheduled_for', m.scheduled_for, 'sent_at', m.sent_at, 'created_at', m.created_at,
        'agent_run_id', m.agent_run_id, 'attachment_count', m.attachment_count,
        'reactions', (SELECT COALESCE(jsonb_agg(jsonb_build_object('emoji', r.emoji, 'count', r.n, 'member_ids', r.ids, 'mine', app_current_member_id() = ANY (r.ids)) ORDER BY r.first), '[]'::jsonb)
                        FROM (SELECT emoji, count(*) AS n, array_agg(member_id) AS ids, min(created_at) AS first FROM message_reactions WHERE message_id = m.id GROUP BY emoji) r),
        'attachments', (SELECT COALESCE(jsonb_agg(jsonb_build_object('attachment_id', a.id, 'filename', a.filename, 'mime_type', a.mime_type, 'byte_size', a.byte_size, 'width', a.width, 'height', a.height)), '[]'::jsonb)
                          FROM attachments a WHERE a.record_type = 'message' AND a.record_id = m.id),
        'repliers', (SELECT COALESCE(jsonb_agg(DISTINCT x.author_member_id), '[]'::jsonb) FROM (SELECT author_member_id FROM messages WHERE thread_root_id = m.id AND deleted_at IS NULL LIMIT 5) x),
        'is_saved', EXISTS (SELECT 1 FROM saved_messages s WHERE s.message_id = m.id AND s.member_id = app_current_member_id()),
        'is_pinned', EXISTS (SELECT 1 FROM channel_pins p WHERE p.message_id = m.id));
$$;

-- The channel's stream: top-level messages and replies sent also to the channel; `before` pages backwards; `since` polls forwards.
CREATE OR REPLACE FUNCTION sp_channel_history(p_channel_id bigint, p_before bigint DEFAULT NULL, p_since bigint DEFAULT NULL, p_limit integer DEFAULT 50)
    RETURNS SETOF jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_channel_id NOT IN (SELECT sp_visible_channel_ids()) THEN RETURN; END IF;
    RETURN QUERY
        SELECT sp_message_row(m) FROM (
            SELECT m.* FROM messages m
             WHERE m.channel_id = p_channel_id AND m.sent_at IS NOT NULL AND (m.thread_root_id IS NULL OR m.also_to_channel)
               AND (p_before IS NULL OR m.id < p_before) AND (p_since IS NULL OR m.id > p_since)
             ORDER BY m.id DESC LIMIT greatest(1, least(p_limit, 200))) m
         ORDER BY m.id;
END$$;

CREATE OR REPLACE FUNCTION sp_thread(p_root_id bigint) RETURNS SETOF jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE cid bigint;
BEGIN
    SELECT channel_id INTO cid FROM messages WHERE id = p_root_id;
    IF cid IS NULL OR cid NOT IN (SELECT sp_visible_channel_ids()) THEN RETURN; END IF;
    RETURN QUERY
        SELECT sp_message_row(m) FROM messages m WHERE (m.id = p_root_id OR m.thread_root_id = p_root_id) AND m.sent_at IS NOT NULL ORDER BY m.id;
END$$;

-- The conversation the chat endpoint gets: the thread's last turns as "Name: text" lines (the agent's own replies too).
CREATE OR REPLACE FUNCTION sp_thread_context(p_root_id bigint, p_limit integer DEFAULT 12) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT string_agg(COALESCE(mm.display_name, 'someone') || ': ' || left(m.plain_text, 1500), E'\n' ORDER BY m.id)
      FROM (SELECT * FROM messages x WHERE (x.id = p_root_id OR x.thread_root_id = p_root_id) AND x.sent_at IS NOT NULL AND x.deleted_at IS NULL AND x.kind = 'message'
             ORDER BY x.id DESC LIMIT greatest(1, p_limit)) m
      LEFT JOIN members mm ON mm.id = m.author_member_id;
$$;

-- Unread per channel: messages after the member's cursor (top-level and also-to-channel), the first unread id, mentions among them.
CREATE OR REPLACE FUNCTION sp_unread(p_member_id bigint DEFAULT app_current_member_id())
    RETURNS TABLE (channel_id bigint, unread_count bigint, first_unread_id bigint, mention_count bigint, last_message_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_member_id IS DISTINCT FROM app_current_member_id() THEN RETURN; END IF;
    RETURN QUERY
        SELECT c.id,
               count(m.id) FILTER (WHERE m.id > COALESCE(cm.last_read_message_id, 0)),
               min(m.id) FILTER (WHERE m.id > COALESCE(cm.last_read_message_id, 0)),
               count(m.id) FILTER (WHERE m.id > COALESCE(cm.last_read_message_id, 0) AND EXISTS (
                   SELECT 1 FROM message_mentions mm WHERE mm.message_id = m.id AND ((mm.kind IN ('member', 'agent') AND mm.principal_id = p_member_id)
                      OR (mm.kind = 'department' AND mm.principal_id IN (SELECT dm.department_id FROM department_members dm WHERE dm.member_id = p_member_id AND dm.left_at IS NULL))
                      OR mm.kind IN ('channel', 'everyone', 'here')))),
               c.last_message_at
          FROM channels c
          LEFT JOIN channel_members cm ON cm.channel_id = c.id AND cm.member_id = p_member_id
          LEFT JOIN messages m ON m.channel_id = c.id AND m.sent_at IS NOT NULL AND m.deleted_at IS NULL AND (m.thread_root_id IS NULL OR m.also_to_channel)
                                  AND m.author_member_id IS DISTINCT FROM p_member_id
         WHERE c.id IN (SELECT sp_visible_channel_ids()) AND c.archived_at IS NULL
           AND (cm.muted_until IS NULL OR cm.muted_until < now())
         GROUP BY c.id, cm.last_read_message_id, c.last_message_at;
END$$;

-- Activity: what happened to me — mentions, replies to my messages or threads, reactions to my messages, comments on my pages.
CREATE OR REPLACE FUNCTION sp_activity_feed(p_since timestamptz DEFAULT now() - interval '7 days', p_limit integer DEFAULT 50)
    RETURNS TABLE (kind text, occurred_at timestamptz, actor_member_id bigint, actor_name text, actor_is_agent boolean, channel_id bigint, message_id bigint, thread_root_id bigint, page_id uuid, excerpt text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL THEN RETURN; END IF;
    RETURN QUERY
    WITH vc AS (SELECT sp_visible_channel_ids() AS id),
    ev AS (
        SELECT 'mention'::text AS kind, m.sent_at AS at, m.author_member_id AS actor, m.channel_id, m.id AS mid, m.thread_root_id, NULL::uuid AS pid, left(m.plain_text, 160) AS ex
          FROM messages m JOIN message_mentions mm ON mm.message_id = m.id
         WHERE m.sent_at >= p_since AND m.deleted_at IS NULL AND m.author_member_id IS DISTINCT FROM me AND m.channel_id IN (SELECT id FROM vc)
           AND ((mm.kind IN ('member', 'agent') AND mm.principal_id = me)
                OR (mm.kind = 'department' AND mm.principal_id IN (SELECT dm.department_id FROM department_members dm WHERE dm.member_id = me AND dm.left_at IS NULL)))
        UNION ALL
        SELECT 'reply', r.sent_at, r.author_member_id, r.channel_id, r.id, r.thread_root_id, NULL, left(r.plain_text, 160)
          FROM messages r JOIN messages root ON root.id = r.thread_root_id
         WHERE r.sent_at >= p_since AND r.deleted_at IS NULL AND r.author_member_id IS DISTINCT FROM me AND r.channel_id IN (SELECT id FROM vc)
           AND (root.author_member_id = me OR EXISTS (SELECT 1 FROM messages x WHERE x.thread_root_id = root.id AND x.author_member_id = me AND x.id < r.id))
        UNION ALL
        SELECT 'reaction', mr.created_at, mr.member_id, m.channel_id, m.id, m.thread_root_id, NULL, mr.emoji || ' ' || left(m.plain_text, 120)
          FROM message_reactions mr JOIN messages m ON m.id = mr.message_id
         WHERE mr.created_at >= p_since AND m.author_member_id = me AND mr.member_id <> me AND m.channel_id IN (SELECT id FROM vc)
        UNION ALL
        SELECT 'comment', c.created_at, c.author_member_id, NULL, NULL, NULL, c.page_id, left(c.plain_text, 160)
          FROM comments c JOIN pages p ON p.id = c.page_id
         WHERE c.created_at >= p_since AND c.deleted_at IS NULL AND c.author_member_id IS DISTINCT FROM me
           AND (p.owner_member_id = me OR p.wiki_owner_member_id = me OR EXISTS (SELECT 1 FROM comment_mentions cm WHERE cm.comment_id = c.id AND cm.kind IN ('member', 'agent') AND cm.principal_id = me))
           AND sp_can_see_page(c.page_id)
    )
    SELECT ev.kind, ev.at, ev.actor, mm.display_name, mm.member_kind = 'agent', ev.channel_id, ev.mid, ev.thread_root_id, ev.pid, ev.ex
      FROM ev LEFT JOIN members mm ON mm.id = ev.actor
     ORDER BY ev.at DESC LIMIT greatest(1, least(p_limit, 200));
END$$;

-- The open DMs and group DMs, with the other people and the last line.
CREATE OR REPLACE FUNCTION sp_my_dms()
    RETURNS TABLE (channel_id bigint, kind text, member_ids bigint[], names text, last_message_at timestamptz, last_line text, unread_count bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id();
BEGIN
    IF me IS NULL THEN RETURN; END IF;
    RETURN QUERY
        SELECT c.id, c.kind,
               (SELECT array_agg(cm.member_id ORDER BY cm.member_id) FROM channel_members cm WHERE cm.channel_id = c.id AND cm.member_id <> me),
               (SELECT string_agg(m.display_name, ', ' ORDER BY m.display_name) FROM channel_members cm JOIN members m ON m.id = cm.member_id WHERE cm.channel_id = c.id AND cm.member_id <> me),
               c.last_message_at,
               (SELECT left(x.plain_text, 120) FROM messages x WHERE x.channel_id = c.id AND x.sent_at IS NOT NULL AND x.deleted_at IS NULL ORDER BY x.id DESC LIMIT 1),
               (SELECT count(*) FROM messages x, channel_members me_cm WHERE me_cm.channel_id = c.id AND me_cm.member_id = me AND x.channel_id = c.id AND x.sent_at IS NOT NULL AND x.id > me_cm.last_read_message_id AND x.author_member_id IS DISTINCT FROM me)
          FROM channels c JOIN channel_members mine ON mine.channel_id = c.id AND mine.member_id = me
         WHERE c.kind IN ('dm', 'group_dm') AND c.archived_at IS NULL
         ORDER BY c.last_message_at DESC NULLS LAST, c.id DESC;
END$$;

-- The sidebar, in one call: spaces the caller is in (sections → root pages, channels followed), Shared with me, Private, Favorites.
CREATE OR REPLACE FUNCTION sp_sidebar() RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); vis uuid[]; out jsonb;
BEGIN
    IF me IS NULL THEN RETURN '{}'::jsonb; END IF;
    SELECT array_agg(x) INTO vis FROM sp_visible_page_ids() x;
    vis := COALESCE(vis, '{}');
    SELECT jsonb_build_object(
        'favorites', COALESCE((SELECT jsonb_agg(jsonb_build_object('page_id', p.id, 'title', p.plain_title, 'icon', p.icon, 'kind', p.kind, 'space_id', p.space_id) ORDER BY f.position)
                                 FROM page_favorites f JOIN pages p ON p.id = f.page_id WHERE f.member_id = me AND p.archived_at IS NULL AND p.id = ANY (vis)), '[]'::jsonb),
        'spaces', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                     'space_id', s.id, 'name', s.name, 'slug', s.slug, 'icon', s.icon, 'kind', s.kind, 'is_default', s.is_default, 'is_wiki', s.is_wiki,
                     'is_owner', EXISTS (SELECT 1 FROM space_members sm WHERE sm.space_id = s.id AND sm.member_id = me AND sm.role = 'owner'),
                     'sections', COALESCE((SELECT jsonb_agg(jsonb_build_object('section_id', sec.id, 'name', sec.name,
                                     'pages', COALESCE((SELECT jsonb_agg(jsonb_build_object('page_id', p.id, 'title', p.plain_title, 'icon', p.icon, 'kind', p.kind, 'has_children', EXISTS (SELECT 1 FROM pages c WHERE c.parent_page_id = p.id AND c.archived_at IS NULL AND c.parent_database_id IS NULL)) ORDER BY p.position)
                                                        FROM pages p WHERE p.space_id = s.id AND p.parent_page_id IS NULL AND p.section_id = sec.id AND p.archived_at IS NULL AND NOT p.is_template AND p.id = ANY (vis)), '[]'::jsonb)) ORDER BY sec.position)
                                   FROM space_sections sec WHERE sec.space_id = s.id), '[]'::jsonb),
                     'pages', COALESCE((SELECT jsonb_agg(jsonb_build_object('page_id', p.id, 'title', p.plain_title, 'icon', p.icon, 'kind', p.kind, 'has_children', EXISTS (SELECT 1 FROM pages c WHERE c.parent_page_id = p.id AND c.archived_at IS NULL AND c.parent_database_id IS NULL)) ORDER BY p.position)
                                        FROM pages p WHERE p.space_id = s.id AND p.parent_page_id IS NULL AND p.section_id IS NULL AND p.archived_at IS NULL AND NOT p.is_template AND p.id = ANY (vis)), '[]'::jsonb),
                     'channels', COALESCE((SELECT jsonb_agg(jsonb_build_object('channel_id', c.id, 'name', c.name, 'kind', c.kind, 'is_default', c.is_default, 'starred', COALESCE(cm.starred, false), 'muted', cm.muted_until > now(), 'section', cm.section) ORDER BY c.is_default DESC, c.name)
                                           FROM channels c LEFT JOIN channel_members cm ON cm.channel_id = c.id AND cm.member_id = me
                                          WHERE c.space_id = s.id AND c.archived_at IS NULL AND c.id IN (SELECT sp_visible_channel_ids())
                                            AND (c.kind = 'private' OR c.is_default OR cm.member_id IS NOT NULL)), '[]'::jsonb)
                   ) ORDER BY s.is_default DESC, s.name)
                   FROM spaces s WHERE s.archived_at IS NULL AND s.id IN (SELECT sp_member_space_ids(me))), '[]'::jsonb),
        'shared', COALESCE((SELECT jsonb_agg(jsonb_build_object('page_id', p.id, 'title', p.plain_title, 'icon', p.icon, 'kind', p.kind, 'owner_member_id', p.owner_member_id) ORDER BY p.last_edited_at DESC)
                              FROM pages p WHERE p.id = ANY (vis) AND p.archived_at IS NULL AND p.parent_database_id IS NULL AND NOT p.is_template
                               AND ((p.space_id IS NULL AND p.owner_member_id <> me)
                                    OR (p.space_id IS NOT NULL AND p.space_id NOT IN (SELECT sp_member_space_ids(me)) AND NOT sp_is_admin()))
                               AND (p.parent_page_id IS NULL OR NOT (p.parent_page_id = ANY (vis)))), '[]'::jsonb),
        'private', COALESCE((SELECT jsonb_agg(jsonb_build_object('page_id', p.id, 'title', p.plain_title, 'icon', p.icon, 'kind', p.kind, 'has_children', EXISTS (SELECT 1 FROM pages c WHERE c.parent_page_id = p.id AND c.archived_at IS NULL)) ORDER BY p.position)
                               FROM pages p WHERE p.space_id IS NULL AND p.owner_member_id = me AND p.parent_page_id IS NULL AND p.archived_at IS NULL AND NOT p.is_template), '[]'::jsonb),
        'dms', COALESCE((SELECT jsonb_agg(to_jsonb(d)) FROM sp_my_dms() d), '[]'::jsonb),
        'joinable', COALESCE((SELECT jsonb_agg(jsonb_build_object('space_id', s.id, 'name', s.name, 'icon', s.icon, 'kind', s.kind) ORDER BY s.name)
                                FROM spaces s WHERE s.archived_at IS NULL AND s.kind IN ('open', 'closed') AND s.id NOT IN (SELECT sp_member_space_ids(me)) AND NOT sp_is_guest()), '[]'::jsonb)
    ) INTO out;
    RETURN out;
END$$;

-- Children of a page, for the sidebar's lazy expansion (pages only, not rows).
CREATE OR REPLACE FUNCTION sp_page_children(p_page_id uuid)
    RETURNS TABLE (page_id uuid, title text, icon text, kind text, row_position text, has_children boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT c.id, c.plain_title, c.icon, c.kind, c.position,
           EXISTS (SELECT 1 FROM pages g WHERE g.parent_page_id = c.id AND g.archived_at IS NULL AND g.parent_database_id IS NULL)
      FROM pages c
     WHERE c.parent_page_id = p_page_id AND c.parent_database_id IS NULL AND c.archived_at IS NULL AND NOT c.is_template
       AND c.id IN (SELECT sp_visible_page_ids())
     ORDER BY c.position;
$$;

-- ---------------------------------------------------------------------------------------------
-- Links and the wiki.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_backlinks(p_page_id uuid)
    RETURNS TABLE (from_page_id uuid, from_title text, from_space_id bigint, kind text, from_block_id uuid, message_id bigint, channel_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.from_page_id, p.plain_title, p.space_id, l.kind, l.from_block_id, NULL::bigint, NULL::bigint
      FROM page_links l JOIN pages p ON p.id = l.from_page_id
     WHERE l.to_page_id = p_page_id AND l.kind NOT IN ('child_page', 'child_database') AND p.archived_at IS NULL AND p.id IN (SELECT sp_visible_page_ids())
    UNION ALL
    SELECT NULL, NULL, c.space_id, 'message', NULL, m.id, m.channel_id
      FROM message_links ml JOIN messages m ON m.id = ml.message_id JOIN channels c ON c.id = m.channel_id
     WHERE ml.to_page_id = p_page_id AND m.deleted_at IS NULL AND m.channel_id IN (SELECT sp_visible_channel_ids());
$$;

-- The wiki's state per page in a space (or every wiki space when NULL): verified / expired / none, owner, days since edit.
CREATE OR REPLACE FUNCTION sp_wiki_status(p_space_id bigint DEFAULT NULL)
    RETURNS TABLE (page_id uuid, title text, space_id bigint, space_name text, verification_state text, verified_at timestamptz, verify_until timestamptz,
                   wiki_owner_member_id bigint, owner_name text, last_edited_at timestamptz, days_since_edit integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT p.id, p.plain_title, p.space_id, s.name,
           CASE WHEN p.verification_state = 'verified' AND p.verify_until IS NOT NULL AND p.verify_until < now() THEN 'expired' ELSE p.verification_state END,
           p.verified_at, p.verify_until, p.wiki_owner_member_id, m.display_name, p.last_edited_at, (current_date - p.last_edited_at::date)
      FROM pages p JOIN spaces s ON s.id = p.space_id LEFT JOIN members m ON m.id = p.wiki_owner_member_id
     WHERE s.is_wiki AND (p_space_id IS NULL OR p.space_id = p_space_id) AND p.archived_at IS NULL AND NOT p.is_template AND p.parent_database_id IS NULL
       AND p.id IN (SELECT sp_visible_page_ids())
     ORDER BY s.name, p.plain_title;
$$;

-- Not edited in N days (the setting when NULL), still reachable from a sidebar (a root page or under one).
CREATE OR REPLACE FUNCTION sp_stale_pages(p_days integer DEFAULT NULL)
    RETURNS TABLE (page_id uuid, title text, space_id bigint, last_edited_at timestamptz, days_since_edit integer, owner_member_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT p.id, p.plain_title, p.space_id, p.last_edited_at, (current_date - p.last_edited_at::date), COALESCE(p.wiki_owner_member_id, p.owner_member_id)
      FROM pages p
     WHERE p.space_id IS NOT NULL AND p.archived_at IS NULL AND NOT p.is_template AND p.parent_database_id IS NULL AND p.kind = 'page'
       AND p.last_edited_at < now() - make_interval(days => COALESCE(p_days, sp_setting_int('stale_page_days')))
       AND p.id IN (SELECT sp_visible_page_ids())
     ORDER BY p.last_edited_at;
$$;

-- Nothing links to it and it is not in a sidebar (not a root page, and its parent chain lost it: no child_page edge).
CREATE OR REPLACE FUNCTION sp_orphan_pages()
    RETURNS TABLE (page_id uuid, title text, space_id bigint, last_edited_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT p.id, p.plain_title, p.space_id, p.last_edited_at
      FROM pages p
     WHERE p.archived_at IS NULL AND NOT p.is_template AND p.parent_database_id IS NULL AND p.parent_page_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM page_links l WHERE l.to_page_id = p.id)
       AND NOT EXISTS (SELECT 1 FROM message_links ml WHERE ml.to_page_id = p.id)
       AND p.id IN (SELECT sp_visible_page_ids())
     ORDER BY p.last_edited_at;
$$;

-- A link to the trash or to nothing.
CREATE OR REPLACE FUNCTION sp_broken_links()
    RETURNS TABLE (from_page_id uuid, from_title text, from_block_id uuid, to_page_id uuid, to_title text, reason text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.from_page_id, fp.plain_title, l.from_block_id, l.to_page_id, tp.plain_title,
           CASE WHEN tp.id IS NULL THEN 'deleted' ELSE 'in the trash' END
      FROM page_links l JOIN pages fp ON fp.id = l.from_page_id LEFT JOIN pages tp ON tp.id = l.to_page_id
     WHERE l.kind IN ('link_to_page', 'mention') AND fp.archived_at IS NULL AND (tp.id IS NULL OR tp.archived_at IS NOT NULL)
       AND fp.id IN (SELECT sp_visible_page_ids())
     ORDER BY fp.plain_title;
$$;

CREATE OR REPLACE FUNCTION sp_duplicate_titles()
    RETURNS TABLE (title text, page_ids uuid[], space_ids bigint[], n bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT lower(p.plain_title), array_agg(p.id ORDER BY p.created_at), array_agg(p.space_id ORDER BY p.created_at), count(*)
      FROM pages p
     WHERE p.archived_at IS NULL AND NOT p.is_template AND p.parent_database_id IS NULL AND p.plain_title <> '' AND p.id IN (SELECT sp_visible_page_ids())
     GROUP BY lower(p.plain_title) HAVING count(*) > 1
     ORDER BY count(*) DESC, 1;
$$;

-- A question (ends in ?) in a public channel with no reply within N hours.
CREATE OR REPLACE FUNCTION sp_unanswered_questions(p_hours integer DEFAULT NULL)
    RETURNS TABLE (message_id bigint, channel_id bigint, channel_name text, space_id bigint, author_member_id bigint, author_name text, asked_at timestamptz, excerpt text, hours_open numeric)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT m.id, m.channel_id, c.name, c.space_id, m.author_member_id, mm.display_name, m.sent_at, left(m.plain_text, 200),
           round(EXTRACT(EPOCH FROM now() - m.sent_at) / 3600, 1)
      FROM messages m JOIN channels c ON c.id = m.channel_id LEFT JOIN members mm ON mm.id = m.author_member_id
     WHERE c.kind = 'public' AND c.archived_at IS NULL AND m.thread_root_id IS NULL AND m.deleted_at IS NULL AND m.kind = 'message'
       AND m.sent_at IS NOT NULL AND m.reply_count = 0
       AND rtrim(m.plain_text) LIKE '%?'
       AND m.sent_at < now() - make_interval(hours => COALESCE(p_hours, sp_setting_int('unanswered_hours')))
       AND NOT EXISTS (SELECT 1 FROM message_reactions r WHERE r.message_id = m.id AND r.emoji IN ('✅', '☑️', '👍'))
       AND m.channel_id IN (SELECT sp_visible_channel_ids())
     ORDER BY m.sent_at;
$$;

-- Threads that decided something and have no page citing them: N+ replies, decision words, no message_links/page_links from a page.
CREATE OR REPLACE FUNCTION sp_thread_candidates(p_min_replies integer DEFAULT 8, p_days integer DEFAULT 30)
    RETURNS TABLE (message_id bigint, channel_id bigint, channel_name text, space_id bigint, reply_count integer, last_reply_at timestamptz, excerpt text, decision_lines text[], already_sent_to_channel boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT m.id, m.channel_id, c.name, c.space_id, m.reply_count, m.last_reply_at, left(m.plain_text, 200),
           (SELECT array_agg(left(r.plain_text, 160) ORDER BY r.id) FROM messages r WHERE r.thread_root_id = m.id AND r.deleted_at IS NULL
              AND r.plain_text ~* '(we decided|let''s go with|decision:|agreed:|we will|we''ll go with|final answer)'),
           EXISTS (SELECT 1 FROM messages r WHERE r.thread_root_id = m.id AND r.also_to_channel)
      FROM messages m JOIN channels c ON c.id = m.channel_id
     WHERE c.kind IN ('public', 'private') AND c.archived_at IS NULL AND m.thread_root_id IS NULL AND m.deleted_at IS NULL
       AND m.last_reply_at > now() - make_interval(days => p_days)
       AND (m.reply_count >= p_min_replies
            OR EXISTS (SELECT 1 FROM messages r WHERE r.thread_root_id = m.id AND r.deleted_at IS NULL AND r.plain_text ~* '(we decided|let''s go with|decision:|agreed:)'))
       AND NOT EXISTS (SELECT 1 FROM page_links pl JOIN blocks b ON b.id = pl.from_block_id WHERE b.content::text LIKE '%"message":%' AND (b.content->'rich_text') @> jsonb_build_array(jsonb_build_object('mention', jsonb_build_object('type', 'message', 'id', m.id::text))))
       AND m.channel_id IN (SELECT sp_visible_channel_ids())
     ORDER BY m.reply_count DESC, m.last_reply_at DESC;
$$;

-- Pages that changed since a time, in the caller's spaces.
CREATE OR REPLACE FUNCTION sp_pages_changed_since(p_since timestamptz, p_space_id bigint DEFAULT NULL, p_limit integer DEFAULT 50)
    RETURNS TABLE (page_id uuid, title text, space_id bigint, kind text, last_edited_at timestamptz, last_edited_by bigint, editor_name text, is_row boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT p.id, p.plain_title, p.space_id, p.kind, p.last_edited_at, p.last_edited_by, m.display_name, p.parent_database_id IS NOT NULL
      FROM pages p LEFT JOIN members m ON m.id = p.last_edited_by
     WHERE p.last_edited_at >= p_since AND p.archived_at IS NULL AND NOT p.is_template AND (p_space_id IS NULL OR p.space_id = p_space_id)
       AND p.id IN (SELECT sp_visible_page_ids())
     ORDER BY p.last_edited_at DESC LIMIT greatest(1, least(p_limit, 200));
$$;

-- Who may see a page and why: the principals of its permission root (or the space's rule), resolved.
CREATE OR REPLACE FUNCTION sp_page_permissions_explained(p_page_id uuid)
    RETURNS TABLE (source text, source_page_id uuid, source_title text, principal_kind text, principal_id bigint, principal_name text, level text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE p pages%ROWTYPE; s spaces%ROWTYPE;
BEGIN
    IF NOT sp_can_see_page(p_page_id) THEN RETURN; END IF;
    SELECT * INTO p FROM pages WHERE id = p_page_id;
    IF p.space_id IS NULL THEN
        RETURN QUERY SELECT 'owner'::text, p.id, p.plain_title, 'member'::text, p.owner_member_id, (SELECT display_name FROM members WHERE id = p.owner_member_id), 'full'::text;
    ELSE
        SELECT * INTO s FROM spaces WHERE id = p.space_id;
        RETURN QUERY SELECT 'space owners'::text, NULL::uuid, s.name, 'owner'::text, sm.member_id, m.display_name, 'full'::text
                       FROM space_members sm JOIN members m ON m.id = sm.member_id WHERE sm.space_id = s.id AND sm.role = 'owner';
        IF p.permission_root_id IS NULL THEN
            RETURN QUERY SELECT 'space'::text, NULL::uuid, s.name, 'everyone_in_space'::text, NULL::bigint, s.name || ' members', s.member_level;
            IF s.kind = 'open' AND s.everyone_level <> 'none' THEN
                RETURN QUERY SELECT 'space'::text, NULL::uuid, s.name, 'everyone'::text, NULL::bigint, 'Everyone in the business', s.everyone_level;
            END IF;
        END IF;
    END IF;
    IF p.permission_root_id IS NOT NULL THEN
        RETURN QUERY
            SELECT CASE WHEN pp.page_id = p.id THEN 'this page' ELSE 'inherited' END, pp.page_id, rp.plain_title, pp.principal_kind, pp.principal_id,
                   CASE pp.principal_kind WHEN 'department' THEN (SELECT name FROM departments WHERE id = pp.principal_id)
                                          WHEN 'everyone_in_space' THEN (SELECT name || ' members' FROM spaces WHERE id = p.space_id)
                                          WHEN 'everyone' THEN 'Everyone in the business'
                                          ELSE (SELECT display_name FROM members WHERE id = pp.principal_id) END,
                   pp.level
              FROM page_permissions pp JOIN pages rp ON rp.id = pp.page_id WHERE pp.page_id = p.permission_root_id
             ORDER BY sp_level_rank(pp.level) DESC;
    END IF;
END$$;

GRANT EXECUTE ON FUNCTION sp_message_row(messages), sp_channel_history(bigint, bigint, bigint, integer), sp_thread(bigint), sp_thread_context(bigint, integer),
    sp_unread(bigint), sp_activity_feed(timestamptz, integer), sp_my_dms(), sp_sidebar(), sp_page_children(uuid), sp_backlinks(uuid), sp_wiki_status(bigint),
    sp_stale_pages(integer), sp_orphan_pages(), sp_broken_links(), sp_duplicate_titles(), sp_unanswered_questions(integer), sp_thread_candidates(integer, integer),
    sp_pages_changed_since(timestamptz, bigint, integer), sp_page_permissions_explained(uuid)
    TO spaces_rw, spaces_records_ro, spaces_activity_ro;

COMMIT;
