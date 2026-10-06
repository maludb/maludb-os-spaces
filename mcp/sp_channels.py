"""Channels and messages (C1-C12): find_channels, get_channel, channel_history, thread_read, my_unread, my_activity, my_saved, my_reminders, channel_pins, unanswered_questions, thread_candidates, my_scheduled, my_dms,
channel_members. A message reaches an agent as Markdown with its author, time, reactions and attachments; a channel or a direct conversation the caller is not in does not exist for them (the views and sp_visible_channel_ids())."""
from __future__ import annotations

import datetime

from pydantic import Field

from sp_common import RO, Ref, BadArg, Where, _Base, caller_tz, channel_ref, contains_words, dumps, err, lim, localize, match_words, me, must_be_member, plain, q_alone, space_ref, when


class FindChannelsIn(_Base):
    q: str | None = Field(None, description="Words of the channel's name. `q` alone resolves a channel: a plain list (channel_id, name)")
    space: Ref | None = Field(None, description="A space (id, slug or name)")
    kind: str | None = Field(None, description="public or private (direct messages are my_dms)")
    mine: bool | None = Field(None, description="true: only the channels I am in")
    include_archived: bool = False
    limit: int = Field(25, ge=1, le=100)


class ChannelIn(_Base):
    channel: Ref = Field(..., description="The channel's id or #name")


class HistoryIn(_Base):
    channel: Ref | None = Field(None, description="The channel's id or #name (or a direct conversation's id)")
    q: str | None = Field(None, description="Words of a message. `q` alone (no channel) resolves a message: a plain list (message_id, excerpt) over the channels I may read")
    since: str | None = Field(None, description="A time (a day or ISO) or a message id: only what came after it")
    before: str | None = Field(None, description="A message id: the page of messages before it (to read back)")
    limit: int = Field(50, ge=1, le=200)


class ThreadIn(_Base):
    message: int = Field(..., description="Any message of the thread (the first one or a reply)")


class LimitIn(_Base):
    limit: int = Field(25, ge=1, le=100)


class ActivityIn(_Base):
    since: str | None = Field(None, description="A day or ISO time; the last 7 days by default")
    kind: str | None = Field(None, description="mention, reply, reaction or comment")
    limit: int = Field(25, ge=1, le=100)


class RemindersIn(_Base):
    include_done: bool = False
    limit: int = Field(25, ge=1, le=100)


class UnansweredIn(_Base):
    hours: int | None = Field(None, ge=1, le=2000, description="Unanswered for this many hours (the workspace's setting by default)")
    channel: Ref | None = None
    space: Ref | None = None
    limit: int = Field(25, ge=1, le=100)


class CandidatesIn(_Base):
    min_replies: int = Field(8, ge=1, le=500)
    days: int = Field(30, ge=1, le=3650)
    space: Ref | None = None
    limit: int = Field(25, ge=1, le=100)


class MembersIn(_Base):
    channel: Ref = Field(..., description="The channel's id or #name")
    limit: int = Field(50, ge=1, le=100)


def _ts(v):
    """The row's ISO string as a datetime, so the caller's local time can sit beside it."""
    try:
        return datetime.datetime.fromisoformat(v) if isinstance(v, str) else v
    except ValueError:
        return v


def trim(m: dict, names: dict[int, str]) -> dict:
    """A message row for an agent: Markdown, who, when, reactions, attachments — the stored run array dropped."""
    return {"message_id": m["message_id"], "channel_id": m["channel_id"], "thread_root_id": m["thread_root_id"], "kind": m["kind"], "author_member_id": m["author_member_id"], "author": m["author_name"],
            "author_is_agent": m["author_is_agent"], "markdown": m["markdown"], "created_at": _ts(m["created_at"]), "edited_at": _ts(m["edited_at"]), "deleted": m["deleted_at"] is not None,
            "reply_count": m["reply_count"], "last_reply_at": _ts(m["last_reply_at"]), "repliers": [names.get(int(i), str(i)) for i in (m.get("repliers") or [])], "also_to_channel": m["also_to_channel"],
            "reactions": [{"emoji": r["emoji"], "count": r["count"], "by_me": r["mine"]} for r in (m.get("reactions") or [])],
            "attachments": [{k: a.get(k) for k in ("attachment_id", "filename", "mime_type", "byte_size")} for a in (m.get("attachments") or [])], "is_pinned": m.get("is_pinned", False)}


def register(mcp, fetch) -> None:
    async def names_for(rows: list[dict]) -> dict[int, str]:
        ids = {int(i) for m in rows for i in (m.get("repliers") or [])}
        if not ids:
            return {}
        got = await fetch("SELECT member_id, display_name FROM mcp_members WHERE member_id = ANY($1::bigint[])", list(ids))
        return {int(r["member_id"]): r["display_name"] for r in got}

    @mcp.tool(name="find_channels", annotations={"title": "Find channels", **RO})
    async def find_channels(params: FindChannelsIn) -> str:
        """The channels of a space (or all of mine): kind, topic, whether I follow it, how much is unread, archived ones apart. `q` alone resolves a channel by name (a plain list of channel_id and name)."""
        w = Where().raw("c.kind IN ('public', 'private')")
        if not params.include_archived:
            w.raw("c.archived_at IS NULL")
        if params.kind:
            if params.kind not in ("public", "private"):
                return err("kind is public or private (direct messages are my_dms).")
            w.add("c.kind = {}", params.kind)
        if params.mine:
            w.raw("c.i_am_member")
        try:
            if params.space:
                w.add("c.space_id = {}", await space_ref(fetch, params.space))
        except BadArg as ex:
            return err(str(ex))
        match_words(w, params.q, "c.name")
        rows = await fetch(f"""SELECT c.channel_id, c.space_id, s.name AS space_name, c.name, c.kind, c.topic, c.purpose, c.is_default, c.i_am_member, c.i_follow, c.starred, c.member_count, c.last_message_at, c.archived_at,
                                      COALESCE(u.unread_count, 0) AS unread_count, COALESCE(u.mention_count, 0) AS mention_count
                                 FROM mcp_channels c LEFT JOIN mcp_spaces s ON s.space_id = c.space_id
                                 LEFT JOIN sp_unread((SELECT nullif(current_setting('app.member_id', true), '')::bigint)) u ON u.channel_id = c.channel_id
                                WHERE {w.sql()} ORDER BY c.archived_at IS NOT NULL, c.is_default DESC, lower(c.name) LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "channel_id", "name")
        return dumps(rows)

    @mcp.tool(name="get_channel", annotations={"title": "One channel", **RO})
    async def get_channel(params: ChannelIn) -> str:
        """One channel: topic, purpose, how many members, how many pins, its retention, my notify setting and the time of the last message."""
        try:
            cid = await channel_ref(fetch, params.channel)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("""SELECT c.channel_id, c.space_id, s.name AS space_name, c.name, c.kind, c.topic, c.purpose, c.is_default, c.archived_at, c.retention_days, c.i_am_member, c.i_follow, c.starred, c.notify AS my_notify,
                                     c.muted_until, c.member_count, c.message_count, c.last_message_at, c.created_at,
                                     (SELECT count(*) FROM mcp_channel_pins p WHERE p.channel_id = c.channel_id) AS pins_count
                                FROM mcp_channels c LEFT JOIN mcp_spaces s ON s.space_id = c.space_id WHERE c.channel_id = $1""", cid)
        return dumps(rows[0])

    @mcp.tool(name="channel_history", annotations={"title": "What was said", **RO})
    async def channel_history(params: HistoryIn) -> str:
        """What was said: the channel's messages oldest first (a thread collapsed to its first message with the reply count and who replied), each as Markdown with author, time, reactions and attachments. `since` (a time or a message id)
        gives what came after it — the newest `limit` of them —, `before` pages back. `q` alone (no channel) resolves a message by words: a plain list of message_id and excerpt such as "Priya, 3 Oct: Are we launching…"."""
        if params.channel is None:
            if not params.q:
                return err("Give the channel (its id or #name), or words of a message in q.")
            w = Where().raw("m.sent_at IS NOT NULL").raw("m.deleted_at IS NULL")
            contains_words(w, params.q, "m.plain_text")
            rows = await fetch(f"""SELECT m.message_id, coalesce(m.author_name, 'someone') || ', ' || to_char(m.created_at AT TIME ZONE 'UTC', 'FMDD Mon') || ': ' || left(m.plain_text, 60) || CASE WHEN length(m.plain_text) > 60 THEN '…' ELSE '' END AS excerpt,
                                          m.channel_id FROM mcp_messages m WHERE {w.sql()} ORDER BY m.created_at DESC LIMIT {lim(params.limit, 25)}""", *w.args)
            return dumps(rows) if not q_alone(params) else plain(rows, "message_id", "excerpt")
        try:
            cid = await channel_ref(fetch, params.channel)
            since_id = None
            if params.since:
                if str(params.since).isdigit():
                    since_id = int(params.since)
                else:
                    t = when(params.since, "since")
                    first = await fetch("SELECT min(message_id) AS m FROM mcp_messages WHERE channel_id = $1 AND created_at >= $2 AND sent_at IS NOT NULL", cid, t)
                    since_id = (int(first[0]["m"]) - 1) if first and first[0]["m"] is not None else 2**62
            before_id = int(params.before) if params.before and str(params.before).isdigit() else None
            if params.before and before_id is None:
                raise BadArg("before is a message id.")
        except BadArg as ex:
            return err(str(ex))
        rows = [r["m"] for r in await fetch("SELECT sp_channel_history($1, $2, $3, $4) AS m", cid, before_id, since_id, lim(params.limit, 50, 200))]
        names = await names_for(rows)
        tz = await caller_tz(fetch)
        return dumps({"channel_id": cid, "timezone": tz, "messages": localize([trim(m, names) for m in rows], tz, "created_at")})

    @mcp.tool(name="thread_read", annotations={"title": "Read a thread", **RO})
    async def thread_read(params: ThreadIn) -> str:
        """A thread in order: the first message and every reply, as Markdown. Give any message of the thread."""
        row = await fetch("SELECT message_id, thread_root_id FROM mcp_messages WHERE message_id = $1", params.message)
        if not row:
            return err("No message you can read has that id.")
        root = int(row[0]["thread_root_id"] or row[0]["message_id"])
        rows = [r["m"] for r in await fetch("SELECT sp_thread($1) AS m", root)]
        names = await names_for(rows)
        tz = await caller_tz(fetch)
        return dumps({"root_message_id": root, "timezone": tz, "messages": localize([trim(m, names) for m in rows], tz, "created_at")})

    @mcp.tool(name="my_unread", annotations={"title": "What is unread", **RO})
    async def my_unread() -> str:
        """What is unread for me, by channel: the count, the first unread message and the mentions among them — direct messages first."""
        rows = await fetch("""SELECT u.channel_id, c.name, c.kind, c.space_id, u.unread_count, u.first_unread_id, u.mention_count, u.last_message_at, d.names AS with_people
                                FROM sp_unread((SELECT nullif(current_setting('app.member_id', true), '')::bigint)) u JOIN mcp_channels c ON c.channel_id = u.channel_id
                                LEFT JOIN sp_my_dms() d ON d.channel_id = u.channel_id
                               WHERE u.unread_count > 0 ORDER BY (c.kind IN ('dm', 'group_dm')) DESC, u.mention_count DESC, u.last_message_at DESC""")
        return dumps(rows)

    @mcp.tool(name="my_activity", annotations={"title": "My activity", **RO})
    async def my_activity(params: ActivityIn | None = None) -> str:
        """Who mentioned me, replied to me, reacted to me or commented on my pages since a time (7 days by default), newest first."""
        params = params or ActivityIn()
        try:
            t = when(params.since, "since") if params.since else None
        except BadArg as ex:
            return err(str(ex))
        import datetime
        t = t or (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=7))
        rows = await fetch("SELECT kind, occurred_at, actor_member_id, actor_name, actor_is_agent, channel_id, message_id, thread_root_id, page_id, excerpt FROM sp_activity_feed($1, $2)", t, 100)
        if params.kind:
            rows = [r for r in rows if r["kind"] == params.kind]
        return dumps(rows[: lim(params.limit)])

    @mcp.tool(name="my_saved", annotations={"title": "My saved messages", **RO})
    async def my_saved(params: LimitIn | None = None) -> str:
        """The messages I saved, newest first, as Markdown with where they are."""
        n = lim(params.limit if params else 25)
        rows = await fetch(f"""SELECT m.message_id, m.channel_id, c.name AS channel_name, m.author_name, sp_rich_text_markdown(m.body) AS markdown, m.created_at, s.created_at AS saved_at
                                 FROM mcp_saved_messages s JOIN mcp_messages m ON m.message_id = s.message_id LEFT JOIN mcp_channels c ON c.channel_id = m.channel_id
                                WHERE s.member_id = (SELECT nullif(current_setting('app.member_id', true), '')::bigint) ORDER BY s.created_at DESC LIMIT {n}""")
        return dumps(rows)

    @mcp.tool(name="my_reminders", annotations={"title": "My reminders", **RO})
    async def my_reminders(params: RemindersIn | None = None) -> str:
        """My reminders due and coming, soonest first, with what each is about (a message or a page)."""
        params = params or RemindersIn()
        w = Where().raw("r.member_id = (SELECT nullif(current_setting('app.member_id', true), '')::bigint)")
        if not params.include_done:
            w.raw("r.done_at IS NULL")
        rows = await fetch(f"""SELECT r.reminder_id, r.text, r.remind_at, r.done_at, r.message_id, left(m.plain_text, 120) AS message_excerpt, r.page_id, p.plain_title AS page_title
                                 FROM mcp_reminders r LEFT JOIN mcp_messages m ON m.message_id = r.message_id LEFT JOIN mcp_pages p ON p.page_id = r.page_id
                                WHERE {w.sql()} ORDER BY r.done_at IS NOT NULL, r.remind_at LIMIT {lim(params.limit)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="channel_pins", annotations={"title": "Pins and bookmarks", **RO})
    async def channel_pins(params: ChannelIn) -> str:
        """What is pinned in a channel (messages and pages) and what is bookmarked."""
        try:
            cid = await channel_ref(fetch, params.channel)
        except BadArg as ex:
            return err(str(ex))
        pins = await fetch("""SELECT p.pin_id, p.message_id, sp_rich_text_markdown(m.body) AS message_markdown, m.author_name, p.page_id, g.plain_title AS page_title, p.pinned_by, p.created_at
                                FROM mcp_channel_pins p LEFT JOIN mcp_messages m ON m.message_id = p.message_id LEFT JOIN mcp_pages g ON g.page_id = p.page_id WHERE p.channel_id = $1 ORDER BY p.created_at DESC""", cid)
        marks = await fetch("SELECT bookmark_id, title, url, page_id, emoji, position FROM mcp_channel_bookmarks WHERE channel_id = $1 ORDER BY position, bookmark_id", cid)
        return dumps({"channel_id": cid, "pins": pins, "bookmarks": marks})

    @mcp.tool(name="unanswered_questions", annotations={"title": "Unanswered questions", **RO})
    async def unanswered_questions(params: UnansweredIn) -> str:
        """Questions in public channels nobody answered within N hours (the workspace's setting by default), oldest first, with how long each has been open."""
        try:
            cid = await channel_ref(fetch, params.channel) if params.channel else None
            sid = await space_ref(fetch, params.space) if params.space else None
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("SELECT message_id, channel_id, channel_name, space_id, author_member_id, author_name, asked_at, excerpt, hours_open FROM sp_unanswered_questions($1)", params.hours)
        rows = [r for r in rows if (cid is None or r["channel_id"] == cid) and (sid is None or r["space_id"] == sid)]
        return dumps(rows[: lim(params.limit)])

    @mcp.tool(name="thread_candidates", annotations={"title": "Threads that decided something", **RO})
    async def thread_candidates(params: CandidatesIn) -> str:
        """Threads that decided something — decision words, many replies — that no page cites, so each could become a page: the lines that carry the decision and whether the thread was ever sent to the channel."""
        try:
            sid = await space_ref(fetch, params.space) if params.space else None
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("SELECT message_id, channel_id, channel_name, space_id, reply_count, last_reply_at, excerpt, decision_lines, already_sent_to_channel FROM sp_thread_candidates($1, $2)", params.min_replies, params.days)
        return dumps([r for r in rows if sid is None or r["space_id"] == sid][: lim(params.limit)])

    @mcp.tool(name="my_scheduled", annotations={"title": "My scheduled messages", **RO})
    async def my_scheduled(params: LimitIn | None = None) -> str:
        """My messages scheduled to go out, and when."""
        n = lim(params.limit if params else 25)
        rows = await fetch(f"""SELECT m.message_id, m.channel_id, c.name AS channel_name, sp_rich_text_markdown(m.body) AS markdown, m.scheduled_for FROM mcp_messages m LEFT JOIN mcp_channels c ON c.channel_id = m.channel_id
                                WHERE m.sent_at IS NULL AND m.scheduled_for IS NOT NULL AND m.author_member_id = (SELECT nullif(current_setting('app.member_id', true), '')::bigint) ORDER BY m.scheduled_for LIMIT {n}""")
        return dumps(rows)

    @mcp.tool(name="my_dms", annotations={"title": "My direct messages", **RO})
    async def my_dms(params: LimitIn | None = None) -> str:
        """My direct and group messages with the people in them: the last line and the unread count, newest first."""
        n = lim(params.limit if params else 25)
        rows = await fetch(f"SELECT channel_id, kind, member_ids, names, last_message_at, last_line, unread_count FROM sp_my_dms() ORDER BY last_message_at DESC NULLS LAST LIMIT {n}")
        return dumps(rows)

    @mcp.tool(name="channel_members", annotations={"title": "Who is in a channel", **RO})
    async def channel_members(params: MembersIn) -> str:
        """Who is in a channel (a private one's members, a public one's followers), agents marked."""
        try:
            cid = await channel_ref(fetch, params.channel)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch(f"SELECT member_id, display_name, is_agent, joined_at FROM mcp_channel_members WHERE channel_id = $1 ORDER BY lower(display_name) LIMIT {lim(params.limit, 50)}", cid)
        return dumps(rows)
