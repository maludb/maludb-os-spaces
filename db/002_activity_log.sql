-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "spaces" first). A RECORD'S HISTORY IS THIS TABLE (design §6): there is no audit table —
-- except page CONTENT, whose history is its versions (db/007). `before`/`after` carry what the timeline shows: ids, titles,
-- counts, a block's type, a channel's name. A MESSAGE BODY OR A PAGE'S TEXT IS NEVER HERE; a public token never.
--
-- THE ESTATE'S DATA MODEL (design §6.1): the contract table (memory.md / Consultant Tracking db/002) with Spaces' audit
-- keys APPENDED: space_id, channel_id, message_id, and entity_uuid — pages, blocks, databases, views and comments are
-- UUID-keyed (D6), so a row about one of them carries entity_uuid and leaves entity_id NULL.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only; amount + currency on every money event
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (a department's space, a share to it)
    location_id     bigint,                        -- the contract's (unused: scope none)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.1): Spaces' audit keys (no FKs — history outlives a record)
    space_id        bigint,                        -- the space the event concerns
    channel_id      bigint,                        -- the channel (or DM) the event concerns
    message_id      bigint,                        -- the message the event concerns (post, edit, react, pin, reply …)
    entity_uuid     uuid                           -- the UUID record the event concerns (a page, block, database, view, comment)
);
CREATE INDEX activity_log_actor_idx   ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx  ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx  ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx    ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx ON activity_log (request_id);
CREATE INDEX activity_log_space_idx   ON activity_log (space_id, occurred_at DESC);
CREATE INDEX activity_log_channel_idx ON activity_log (channel_id, occurred_at DESC);
CREATE INDEX activity_log_message_idx ON activity_log (message_id, occurred_at DESC);
CREATE INDEX activity_log_uuid_idx    ON activity_log (entity_uuid, occurred_at DESC);

GRANT INSERT, SELECT ON activity_log TO spaces_rw;
REVOKE ALL ON activity_log FROM spaces_records_ro, spaces_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO spaces_rw;

COMMIT;
