"""spaces_activity_mcp — Spaces' activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/spaces-mcp-tool-surface.md, "Activity server". Every query runs as the asking member; the view decides which rows they see: their own, and the rows about records they may see (a page the tree admits, a channel
they may read, a space they may see) — the admin sees every row. The activity role reads mcp_activity_log plus mcp_members, mcp_spaces, mcp_pages, mcp_channels and mcp_messages for names and record facts. A message body, a page's text,
a publication's token and a token's value are never in a payload (the writers' rule), so nothing here can return one.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from sp_common import RO, Ref, BadArg, Where, _Base, caller_tz, channel_ref, contains_words, dumps, err, flag, lim, localize, me, member_ref, page_gate, rank, space_ref, uid, when

mcp = FastMCP("spaces_activity_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


COLS = """l.activity_id, l.occurred_at, l.actor_member_id, l.actor_name, l.actor_is_agent, l.source, l.action, l.entity_type, l.entity_id, l.entity_uuid, l.space_id, l.channel_id, l.message_id, l.before, l.after, l.agent_run_id,
       CASE WHEN l.actor_name IS NULL THEN l.source WHEN l.actor_is_agent THEN l.actor_name || ' (agent)' ELSE l.actor_name END AS actor_label"""


class HistoryIn(_Base):
    entity_type: str = Field(..., description="page, block, database, view, row, comment (a UUID record); space, channel, message, reminder, export (an integer record)")
    entity_id: int | None = Field(None, description="The integer id of a space, channel, message …")
    entity_uuid: str | None = Field(None, description="The UUID of a page, block, database, view, row or comment. For a page: its block edits are included")
    limit: int = Field(100, ge=1, le=200)


class TimelineIn(_Base):
    member: Ref = Field(..., description="A person or an agent (id or name)")
    since: str | None = Field(None, description="A day or ISO time; the last 7 days by default")
    limit: int = Field(100, ge=1, le=200)


class RecentIn(_Base):
    since: str = Field(..., description="A day or ISO time")
    space: Ref | None = Field(None, description="A space (id, slug or name)")
    limit: int = Field(100, ge=1, le=200)


class TouchedIn(_Base):
    space: Ref | None = Field(None, description="A space (id, slug or name) — for its owner and the admin")
    page: str | None = Field(None, description="A page id (a UUID) — for those who can edit it")
    channel: Ref | None = Field(None, description="A channel (id or #name) — for those who may read it")
    since: str | None = Field(None, description="A day or ISO time; the last 30 days by default")
    limit: int = Field(50, ge=1, le=200)


class ShareReadsIn(_Base):
    since: str | None = Field(None, description="A day or ISO time; the last 30 days by default")
    limit: int = Field(50, ge=1, le=200)


class ActivitySearchIn(_Base):
    q: str = Field(..., description="Words to find in an action, a title or a channel's name")
    since: str | None = Field(None, description="A day or ISO time")
    limit: int = Field(50, ge=1, le=200)


def _default_since(days: int):
    import datetime
    return datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=days)


async def space_ref_a(value) -> int:
    return await space_ref(fetch, value)


@mcp.tool(name="record_history", annotations={"title": "A record's history", **RO})
async def record_history(params: HistoryIn) -> str:
    """Who did what to one record, in order: a page (its edits block by block, shares, moves, locks, publication), a database, a channel, a message, a space — each row worded with the actor (a person, an agent, the worker) and the
    payload (ids, titles and counts, never the text). Give entity_type and entity_id (an integer record) or entity_uuid (a UUID record). You see the rows the record's own rule admits."""
    w = Where().raw("l.action <> 'screen.view'")
    try:
        if params.entity_uuid:
            u = uid(params.entity_uuid, "entity_uuid")
            w.parts.append(f"(l.entity_uuid = {w.p(u)}::uuid OR l.after->>'page_id' = {w.p(u)}::text OR l.after->>'database_id' = {w.p(u)}::text)")
        elif params.entity_id is not None:
            if params.entity_type == "space":
                w.add("l.space_id = {}", params.entity_id)
            elif params.entity_type == "channel":
                w.add("l.channel_id = {}", params.entity_id)
            elif params.entity_type == "message":
                w.add("l.message_id = {}", params.entity_id)
            else:
                w.add("l.entity_type = {}", params.entity_type).add("l.entity_id = {}", params.entity_id)
        else:
            return err("Give entity_id (a space, channel, message …) or entity_uuid (a page, database, block …).")
    except BadArg as ex:
        return err(str(ex))
    tz = await caller_tz(fetch)
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    rows.reverse()
    return dumps({"timezone": tz, "events": localize(rows, tz, "occurred_at")})


@mcp.tool(name="actor_timeline", annotations={"title": "What a person or an agent did", **RO})
async def actor_timeline(params: TimelineIn) -> str:
    """What a person or an agent did here since a time (7 days by default) — every row, newest first, with a count by action. An agent's trail is open to any member; a person's to themselves, to a space owner for the spaces they own,
    and to the admin."""
    try:
        target = await member_ref(fetch, params.member)
        s = when(params.since, "since") if params.since else _default_since(7)
    except BadArg as ex:
        return err(str(ex))
    who = await me(fetch)
    info = (await fetch("SELECT is_agent FROM mcp_members WHERE member_id = $1", target))[0]
    w = Where().add("l.actor_member_id = {}", target).add("l.occurred_at >= {}", s).raw("l.action <> 'screen.view'")
    if not info["is_agent"] and target != who and not await flag(fetch, "sp_is_admin"):
        owned = [r["space_id"] for r in await fetch("SELECT space_id FROM mcp_spaces WHERE i_am_owner")]
        if not owned:
            return err("A person's trail is theirs to read, a space owner's for the spaces they own, and the admin's.")
        w.add("l.space_id = ANY({}::bigint[])", owned)
    tz = await caller_tz(fetch)
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    by = await fetch(f"SELECT l.action, count(*)::int AS n FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1 ORDER BY n DESC, 1 LIMIT 60", *w.args)
    return dumps({"timezone": tz, "member_id": target, "by_action": by, "events": localize(rows, tz, "occurred_at")})


@mcp.tool(name="recent_activity", annotations={"title": "What changed", **RO})
async def recent_activity(params: RecentIn) -> str:
    """What changed since a time across everything you may see (or one space) — newest first, with a count by action and the spaces they touched."""
    try:
        s = when(params.since, "since")
        sid = await space_ref_a(params.space) if params.space else None
    except BadArg as ex:
        return err(str(ex))
    w = Where().add("l.occurred_at >= {}", s).raw("l.action <> 'screen.view'")
    if sid is not None:
        w.add("l.space_id = {}", sid)
    tz = await caller_tz(fetch)
    rows = await fetch(f"SELECT {COLS}, sp.name AS space_name FROM mcp_activity_log l LEFT JOIN mcp_spaces sp ON sp.space_id = l.space_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    by = await fetch(f"SELECT l.action, count(*)::int AS n FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1 ORDER BY n DESC, 1 LIMIT 60", *w.args)
    return dumps({"timezone": tz, "since": s, "by_action": by, "events": localize(rows, tz, "occurred_at")})


@mcp.tool(name="who_touched", annotations={"title": "Who acted here", **RO})
async def who_touched(params: TouchedIn) -> str:
    """Everyone who acted in a space, on a page or in a channel in a period (30 days by default), with how many times each, the first and last time, and the last thing done. Give space (for its owner and the admin), page (for those
    who can edit it) or channel (for those who may read it)."""
    given = [x for x in (params.space, params.page, params.channel) if x]
    if len(given) != 1:
        return err("Give exactly one of space, page or channel.")
    w = Where().raw("l.action <> 'screen.view'")
    try:
        s = when(params.since, "since") if params.since else _default_since(30)
        w.add("l.occurred_at >= {}", s)
        if params.space:
            sid = await space_ref_a(params.space)
            if not (await fetch("SELECT i_am_owner FROM mcp_spaces WHERE space_id = $1", sid))[0]["i_am_owner"] and not await flag(fetch, "sp_is_admin"):
                return err("Who acted in a space is for its owners and the admin.")
            w.add("l.space_id = {}", sid)
        elif params.page:
            pid = uid(params.page)
            await page_gate(fetch, pid, "edit")
            w.parts.append(f"(l.entity_uuid = {w.p(pid)}::uuid OR l.after->>'page_id' = {w.p(pid)}::text)")
        else:
            w.add("l.channel_id = {}", await channel_ref(fetch, params.channel))
    except BadArg as ex:
        return err(str(ex))
    tz = await caller_tz(fetch)
    people = await fetch(f"""SELECT l.actor_member_id, l.actor_name, l.actor_is_agent, l.source, count(*)::int AS actions, min(l.occurred_at) AS first_at, max(l.occurred_at) AS last_at
                               FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1, 2, 3, 4 ORDER BY last_at DESC LIMIT {lim(params.limit, 50, 200)}""", *w.args)
    last = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT 1", *w.args)
    return dumps({"timezone": tz, "since": s, "last": localize(last, tz, "occurred_at")[0] if last else None, "everyone": localize(people, tz, "first_at", "last_at")})


@mcp.tool(name="share_reads", annotations={"title": "What siblings read of ours", **RO})
async def share_reads(params: ShareReadsIn | None = None) -> str:
    """What sibling applications read of ours through the kernel, and when — the share.read rows: the tool, the argument keys, the rows answered and the agent it ran as. The admin's."""
    if not await flag(fetch, "sp_is_admin"):
        return err("What siblings read of ours is the Spaces admin's to see.")
    params = params or ShareReadsIn()
    try:
        s = when(params.since, "since") if params.since else _default_since(30)
    except BadArg as ex:
        return err(str(ex))
    w = Where().raw("l.action = 'share.read'").add("l.occurred_at >= {}", s)
    tz = await caller_tz(fetch)
    rows = await fetch(f"SELECT {COLS}, l.after->>'tool' AS tool, l.after->>'direction' AS direction FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 50, 200)}", *w.args)
    return dumps({"timezone": tz, "reads": localize(rows, tz, "occurred_at")})


@mcp.tool(name="activity_search", annotations={"title": "Search the trail", **RO})
async def activity_search(params: ActivitySearchIn) -> str:
    """The trail by a phrase: words are looked for in the action (page.share), in the titles and names the payloads carry, and in the names of the pages and channels the rows are about. Newest first; only rows you may see."""
    w = Where().raw("l.action <> 'screen.view'")
    try:
        if params.since:
            w.add("l.occurred_at >= {}", when(params.since, "since"))
    except BadArg as ex:
        return err(str(ex))
    contains_words(w, params.q, "l.action", "COALESCE(l.after->>'title', '')", "COALESCE(l.after->>'name', '')", "COALESCE(l.before->>'title', '')", "COALESCE(pg.plain_title, '')", "COALESCE(ch.name, '')")
    tz = await caller_tz(fetch)
    rows = await fetch(f"""SELECT {COLS}, pg.plain_title AS page_title, ch.name AS channel_name FROM mcp_activity_log l LEFT JOIN mcp_pages pg ON pg.page_id = l.entity_uuid LEFT JOIN mcp_channels ch ON ch.channel_id = l.channel_id
                            WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 50, 200)}""", *w.args)
    return dumps({"timezone": tz, "events": localize(rows, tz, "occurred_at")})


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
