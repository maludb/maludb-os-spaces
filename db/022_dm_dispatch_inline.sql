-- 022: THE THREE OPEN QUESTIONS OF SLICE 7, decided by the planning model 2026-10-05 (docs/build-specs/agents-in-spaces.md "Built and proven").
-- db/012 and db/016 are not modified; their functions are redefined here (CREATE OR REPLACE keeps the grants, restated below).
--
-- 1. A DM IS THE CONVERSATION AND THE REPLY IS INLINE. db/012 gave every dispatch the conversation 'spaces:thread:<root>' and db/016 put the
--    placeholder and the reply in the message's thread, so in a DM or a group DM the agent's answer hid under the person's message ("1 reply") and every DM
--    message was its own conversation with no memory of the turns before it. Now: for a channel of kind dm or group_dm the conversation is
--    'spaces:dm:<channel_id>' (a space channel keeps 'spaces:thread:<root>'); the "thinking" placeholder and the reply are TOP-LEVEL messages of the
--    channel (thread_root_id NULL); and sp_dispatches_due() gives the DM's context from sp_dm_context() — the channel's last 12 sent, undeleted turns of kind
--    'message' as "Name: text" lines — with thread_root_id NULL.
-- 2. AN AWAITING DISPATCH IS POLLED UNTIL THE PERSON DECIDES. 'awaiting_approval' now keeps run_id and request_id and the placeholder (born here when the
--    dispatch has none), does not bump attempts, and sets next_attempt_at: one minute on the first call; on a repeated call (the status is already
--    awaiting_approval) half the dispatch's age, never under one minute and never over thirty — so the wait between polls roughly doubles each time and stops at
--    half an hour. sp_dispatches_waiting() offers the awaiting dispatches that have a run and are due for a poll; the worker asks the kernel for the run:
--    answered → 'answered', refused / declined / gone → 'refused', still going → 'awaiting_approval' again.
-- 3. (409 is a worker rule, not the database's: the agent is busy, so the worker records 'failed' and the backoff db/016 keeps retries it.)
BEGIN;

-- The channel's last turns as "Name: text" lines, oldest first (the same shape as sp_thread_context()).
CREATE OR REPLACE FUNCTION sp_dm_context(p_channel_id bigint, p_limit integer DEFAULT 12) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT string_agg(COALESCE(mm.display_name, 'someone') || ': ' || left(m.plain_text, 1500), E'\n' ORDER BY m.id)
      FROM (SELECT * FROM messages x WHERE x.channel_id = p_channel_id AND x.sent_at IS NOT NULL AND x.deleted_at IS NULL AND x.kind = 'message'
             ORDER BY x.id DESC LIMIT greatest(1, p_limit)) m
      LEFT JOIN members mm ON mm.id = m.author_member_id;
$$;

CREATE OR REPLACE FUNCTION sp_dispatch_from_message() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c channels%ROWTYPE; author_kind text; agent_id bigint; root bigint; conv text;
BEGIN
    IF NEW.sent_at IS NULL OR NEW.kind <> 'message' OR NEW.deleted_at IS NOT NULL THEN RETURN NULL; END IF;
    IF TG_OP = 'UPDATE' AND OLD.sent_at IS NOT NULL THEN RETURN NULL; END IF;   -- only the moment it is sent
    SELECT member_kind INTO author_kind FROM members WHERE id = NEW.author_member_id;
    IF author_kind = 'agent' THEN RETURN NULL; END IF;
    SELECT * INTO c FROM channels WHERE id = NEW.channel_id;
    root := COALESCE(NEW.thread_root_id, NEW.id);
    conv := CASE WHEN c.kind IN ('dm', 'group_dm') THEN 'spaces:dm:' || NEW.channel_id ELSE 'spaces:thread:' || root END;
    FOR agent_id IN SELECT mm.principal_id FROM message_mentions mm WHERE mm.message_id = NEW.id AND mm.kind = 'agent' LOOP
        IF sp_in_channel(NEW.channel_id, agent_id) THEN
            INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, channel_id, conversation_id)
            VALUES ('message', NEW.id, agent_id, 'mention', 'chat', NEW.author_member_id, NEW.channel_id, conv)
            ON CONFLICT DO NOTHING;
        END IF;
    END LOOP;
    IF c.kind IN ('dm', 'group_dm') THEN
        FOR agent_id IN SELECT cm.member_id FROM channel_members cm JOIN members m ON m.id = cm.member_id AND m.member_kind = 'agent'
                         WHERE cm.channel_id = NEW.channel_id AND cm.member_id <> NEW.author_member_id LOOP
            INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, channel_id, conversation_id)
            VALUES ('message', NEW.id, agent_id, 'dm', 'chat', NEW.author_member_id, NEW.channel_id, conv)
            ON CONFLICT DO NOTHING;
        END LOOP;
    END IF;
    RETURN NULL;
END$$;

CREATE OR REPLACE FUNCTION sp_dispatches_due(p_limit integer DEFAULT 10)
    RETURNS TABLE (dispatch_id bigint, agent_member_id bigint, acting_member_id bigint, kind text, channel_id bigint, channel_kind text, channel_name text,
                   message_id bigint, thread_root_id bigint, conversation_id text, utterance text, context text, attempts integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.agent_member_id, d.acting_member_id, d.kind, d.channel_id, c.kind, c.name, m.id,
           CASE WHEN c.kind IN ('dm', 'group_dm') THEN NULL ELSE COALESCE(m.thread_root_id, m.id) END, d.conversation_id,
           sp_rich_text_markdown(m.body),
           CASE WHEN c.kind IN ('dm', 'group_dm') THEN sp_dm_context(c.id, 12) ELSE sp_thread_context(COALESCE(m.thread_root_id, m.id), 12) END, d.attempts
      FROM agent_dispatches d JOIN messages m ON m.id = d.record_id AND d.record_type = 'message' JOIN channels c ON c.id = m.channel_id
     WHERE d.status = 'sent' AND d.run_id IS NULL AND d.next_attempt_at <= now() AND d.attempts <= 5 AND m.deleted_at IS NULL
     ORDER BY d.created_at LIMIT greatest(1, p_limit);
$$;

-- The awaiting dispatches that have a run and are due for a poll.
CREATE OR REPLACE FUNCTION sp_dispatches_waiting(p_limit integer DEFAULT 10)
    RETURNS TABLE (dispatch_id bigint, agent_member_id bigint, acting_member_id bigint, channel_id bigint, message_id bigint, run_id bigint, request_id text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.agent_member_id, d.acting_member_id, d.channel_id, d.record_id, d.run_id, d.request_id
      FROM agent_dispatches d
     WHERE d.status = 'awaiting_approval' AND d.run_id IS NOT NULL AND d.record_type = 'message' AND d.next_attempt_at <= now()
     ORDER BY d.next_attempt_at LIMIT greatest(1, p_limit);
$$;

CREATE OR REPLACE FUNCTION sp_dispatch_record(p_dispatch_id bigint, p_status text, p_run_id bigint DEFAULT NULL, p_request_id text DEFAULT NULL,
                                              p_reply jsonb DEFAULT NULL, p_detail text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d agent_dispatches%ROWTYPE; m messages%ROWTYPE; rid bigint; root bigint; wait interval;
BEGIN
    SELECT * INTO d FROM agent_dispatches WHERE id = p_dispatch_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'The dispatch does not exist' USING ERRCODE = 'P0001'; END IF;
    SELECT * INTO m FROM messages WHERE id = d.record_id;
    -- a DM's placeholder and reply are top-level messages of the channel; a space channel's are in the message's thread
    root := CASE WHEN EXISTS (SELECT 1 FROM channels c WHERE c.id = m.channel_id AND c.kind IN ('dm', 'group_dm')) THEN NULL ELSE COALESCE(m.thread_root_id, m.id) END;
    PERFORM set_config('app.sp_worker', '1', true);
    IF p_status IN ('running', 'awaiting_approval') THEN
        IF p_status = 'running' THEN
            UPDATE agent_dispatches SET run_id = p_run_id, request_id = COALESCE(p_request_id, request_id) WHERE id = p_dispatch_id;
        ELSE
            wait := CASE WHEN d.status = 'awaiting_approval' THEN least(interval '30 minutes', greatest(interval '1 minute', (now() - d.created_at) / 2)) ELSE interval '1 minute' END;
            UPDATE agent_dispatches SET status = 'awaiting_approval', run_id = COALESCE(p_run_id, run_id), request_id = COALESCE(p_request_id, request_id),
                   detail = COALESCE(p_detail, detail), next_attempt_at = now() + wait WHERE id = p_dispatch_id;
        END IF;
        IF d.pending_message_id IS NULL THEN
            INSERT INTO messages (channel_id, thread_root_id, author_member_id, kind, body, agent_run_id)
            VALUES (m.channel_id, root, d.agent_member_id, 'agent_pending', sp_rich_text('…'), COALESCE(p_run_id, d.run_id)) RETURNING id INTO rid;
            UPDATE agent_dispatches SET pending_message_id = rid WHERE id = p_dispatch_id;
        END IF;
    ELSIF p_status = 'answered' THEN
        IF d.pending_message_id IS NOT NULL THEN
            UPDATE messages SET kind = 'message', body = COALESCE(p_reply, sp_rich_text('(no reply)')), agent_run_id = COALESCE(p_run_id, agent_run_id), sent_at = now() WHERE id = d.pending_message_id;
            rid := d.pending_message_id;
        ELSE
            INSERT INTO messages (channel_id, thread_root_id, author_member_id, kind, body, agent_run_id)
            VALUES (m.channel_id, root, d.agent_member_id, 'message', COALESCE(p_reply, sp_rich_text('(no reply)')), p_run_id) RETURNING id INTO rid;
        END IF;
        UPDATE agent_dispatches SET status = 'answered', run_id = COALESCE(p_run_id, run_id), request_id = COALESCE(p_request_id, request_id),
               reply_message_id = rid, reply_excerpt = left(sp_rich_text_plain(p_reply), 200), answered_at = now(), detail = p_detail, pending_message_id = NULL
         WHERE id = p_dispatch_id;
        IF m.author_member_id IS NOT NULL THEN
            PERFORM sp_notify(m.author_member_id, 'agent_replied', (SELECT display_name FROM members WHERE id = d.agent_member_id) || ' replied', left(sp_rich_text_plain(p_reply), 120), 'message', rid, NULL, m.channel_id, rid);
        END IF;
    ELSIF p_status IN ('refused', 'failed') THEN
        IF d.pending_message_id IS NOT NULL THEN
            DELETE FROM messages WHERE id = d.pending_message_id;
        END IF;
        UPDATE agent_dispatches SET status = CASE WHEN p_status = 'failed' AND d.attempts < 5 THEN 'sent' ELSE p_status END,
               attempts = attempts + 1, next_attempt_at = now() + make_interval(mins => least(60, 2 ^ d.attempts)::integer), run_id = NULL,
               detail = p_detail, pending_message_id = NULL
         WHERE id = p_dispatch_id;
        rid := NULL;
    END IF;
    PERFORM set_config('app.sp_worker', '', true);
    RETURN rid;
END$$;

REVOKE ALL ON FUNCTION sp_dm_context(bigint, integer), sp_dispatches_waiting(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sp_dm_context(bigint, integer) TO spaces_rw, spaces_records_ro, spaces_activity_ro;
GRANT EXECUTE ON FUNCTION sp_dispatches_waiting(integer) TO spaces_rw;

COMMIT;
