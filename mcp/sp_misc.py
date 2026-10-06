"""The long tail: records_search, my_tokens, my_exports, my_notifications. All of them read through the views, which scope them to the caller."""
from __future__ import annotations

from pydantic import Field

from sp_common import RO, Where, _Base, dumps, err, lim, match_words, plain, q_alone

KINDS = ("space", "page", "database", "channel", "member")


class RecordsSearchIn(_Base):
    q: str = Field(..., description="Words of a name or title")
    kinds: list[str] | None = Field(None, description="Which kinds to look in: space, page, database, channel, member (all by default)")
    limit: int = Field(25, ge=1, le=100)


class ExportsIn(_Base):
    q: str | None = Field(None, description="Words of the export's label. `q` alone resolves one: a plain list (export_id, label)")
    limit: int = Field(25, ge=1, le=100)


class NotificationsIn(_Base):
    unread_only: bool = False
    limit: int = Field(25, ge=1, le=100)


def register(mcp, fetch) -> None:
    @mcp.tool(name="records_search", annotations={"title": "Search the records by name", **RO})
    async def records_search(params: RecordsSearchIn) -> str:
        """A guarded search across every view by words — the contract's one generic search: spaces, pages, databases, channels and members by name or title, in one list (kind, id, name, where). Only what you may see appears. For words INSIDE pages and messages use `search`."""
        kinds = params.kinds or list(KINDS)
        bad = [k for k in kinds if k not in KINDS]
        if bad:
            return err("kinds are space, page, database, channel, member.")
        n = lim(params.limit)
        out: list[dict] = []

        async def part(kind: str, sql_from: str, id_col: str, name_col: str, extra: str, *wheres: str) -> None:
            w = Where()
            for x in wheres:
                w.raw(x)
            match_words(w, params.q, name_col)
            out.extend(await fetch(f"SELECT '{kind}' AS kind, {id_col}::text AS id, {name_col} AS name, {extra} AS detail FROM {sql_from} WHERE {w.sql()} LIMIT {n}", *w.args))

        if "space" in kinds:
            await part("space", "mcp_spaces s", "s.space_id", "s.name", "s.kind", "s.archived_at IS NULL")
        if "page" in kinds:
            await part("page", "mcp_pages p LEFT JOIN mcp_spaces ps ON ps.space_id = p.space_id", "p.page_id", "p.plain_title", "ps.name", "p.archived_at IS NULL", "NOT p.is_template", "p.kind = 'page'")
        if "database" in kinds:
            await part("database", "mcp_databases d LEFT JOIN mcp_spaces ds ON ds.space_id = d.space_id", "d.database_id", "d.title", "ds.name", "d.archived_at IS NULL")
        if "channel" in kinds:
            await part("channel", "mcp_channels c LEFT JOIN mcp_spaces cs ON cs.space_id = c.space_id", "c.channel_id", "c.name", "cs.name", "c.archived_at IS NULL", "c.kind IN ('public', 'private')")
        if "member" in kinds:
            await part("member", "mcp_members m", "m.member_id", "m.display_name", "m.member_kind", "TRUE")
        return dumps(out[: n * len(kinds)])

    @mcp.tool(name="my_tokens", annotations={"title": "My MCP tokens", **RO})
    async def my_tokens() -> str:
        """My own MCP tokens: label, scope, last use, expiry and whether revoked — never a value (a token's value is shown once, when it is minted)."""
        rows = await fetch("SELECT token_id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens_mine ORDER BY created_at DESC")
        return dumps(rows)

    @mcp.tool(name="my_exports", annotations={"title": "My exports", **RO})
    async def my_exports(params: ExportsIn | None = None) -> str:
        """My exports — pages, spaces, channels, everything — and whether each is still downloadable (they are kept 7 days). `q` alone resolves one by its label (a plain list of export_id and label)."""
        params = params or ExportsIn()
        w = Where().raw("e.created_by = (SELECT nullif(current_setting('app.member_id', true), '')::bigint)")
        match_words(w, params.q, "(e.kind || ' ' || e.format || ' ' || COALESCE(p.plain_title, ''))")
        rows = await fetch(f"""SELECT e.export_id, e.kind, e.format, e.page_id, p.plain_title AS page_title, e.space_id, e.channel_id, e.include_subpages, e.status, e.byte_size, e.item_count, e.created_at, e.finished_at, e.expires_at, e.available,
                                      e.kind || ' export' || COALESCE(' of ' || p.plain_title, '') || ' (' || e.format || '), ' || to_char(e.created_at AT TIME ZONE 'UTC', 'FMDD Mon HH24:MI') AS label
                                 FROM mcp_exports e LEFT JOIN mcp_pages p ON p.page_id = e.page_id WHERE {w.sql()} ORDER BY e.created_at DESC LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "export_id", "label")
        return dumps(rows)

    @mcp.tool(name="my_notifications", annotations={"title": "My notifications", **RO})
    async def my_notifications(params: NotificationsIn | None = None) -> str:
        """The bell: my notices, unread first, newest first — kind, title, body, and what it is about."""
        params = params or NotificationsIn()
        w = Where()
        if params.unread_only:
            w.raw("n.read_at IS NULL")
        rows = await fetch(f"""SELECT n.notification_id, n.kind, n.title, n.body, n.record_type, n.record_uuid, n.channel_id, n.message_id, a.display_name AS actor, n.read_at, n.created_at
                                 FROM mcp_notifications n LEFT JOIN mcp_members a ON a.member_id = n.actor_member_id WHERE {w.sql()} ORDER BY (n.read_at IS NOT NULL), n.created_at DESC LIMIT {lim(params.limit)}""", *w.args)
        return dumps(rows)
