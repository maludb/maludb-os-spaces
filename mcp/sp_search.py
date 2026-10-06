"""Search and comments (Q1-Q2): search, page_comments, my_open_discussions. One search over the visible sets (sp_search answers as the caller); comments through mcp_comments, as Markdown with the block they sit on."""
from __future__ import annotations

import asyncpg
from pydantic import Field

from sp_common import RO, Ref, BadArg, Where, _Base, channel_ref, dumps, err, lim, me, member_ref, page_gate, space_ref, uid, when


class SearchIn(_Base):
    q: str | None = Field(None, description="Words to find (quotes, - and OR work as in a web search). May be empty when a modifier narrows it")
    in_: Ref | None = Field(None, alias="in", description="A channel (id or #name)")
    from_: Ref | None = Field(None, alias="from", description="A member (id or name) — who wrote it")
    has: str | None = Field(None, description="link or file")
    before: str | None = Field(None, description="A day: older than this")
    after: str | None = Field(None, description="A day: on or after this")
    is_: str | None = Field(None, alias="is", description="page, row, message or comment")
    space: Ref | None = Field(None, description="A space (id, slug or name)")
    cursor: str | None = Field(None, description="The next_cursor of the previous page")
    limit: int = Field(25, ge=1, le=100)


class PageCommentsIn(_Base):
    page: str = Field(..., description="The page's id (a UUID)")
    open_only: bool = False
    limit: int = Field(50, ge=1, le=100)


class LimitIn(_Base):
    limit: int = Field(25, ge=1, le=100)


def register(mcp, fetch) -> None:
    @mcp.tool(name="search", annotations={"title": "Search everything", **RO})
    async def search(params: SearchIn) -> str:
        """Find anything — pages, database rows, messages, comments — with Slack's modifiers as fields: `in` (a channel), `from` (a member), `has` (link or file), `before`, `after` (days), `is` (page, row, message, comment) and `space`.
        Ranked, the match marked <mark>…</mark> in an excerpt; only what you may see can appear. Page through with `cursor`."""
        f: dict = {}
        try:
            if params.in_:
                f["in"] = await channel_ref(fetch, params.in_)
            if params.from_:
                f["from"] = await member_ref(fetch, params.from_)
            if params.space:
                f["space"] = await space_ref(fetch, params.space)
            if params.has:
                if params.has not in ("link", "file"):
                    raise BadArg("has is link or file.")
                f["has"] = params.has
            if params.is_:
                if params.is_ not in ("page", "row", "message", "comment"):
                    raise BadArg("is is page, row, message or comment.")
                f["is"] = params.is_
            for k, v in (("before", params.before), ("after", params.after)):
                if v:
                    f[k] = str(when(v, k).date())
            off = int(params.cursor) if params.cursor and str(params.cursor).isdigit() else 0
            if params.cursor and not str(params.cursor).isdigit():
                raise BadArg("cursor is the next_cursor an earlier answer gave.")
        except BadArg as ex:
            return err(str(ex))
        if not (params.q or "").strip() and not f:
            return err("Give words to find in q, or at least one modifier.")
        n = lim(params.limit)
        try:
            rows = await fetch("""SELECT s.entity_kind, s.entity_uuid, s.entity_id, s.page_id, s.space_id, s.channel_id, s.author_member_id, a.display_name AS author, s.title, s.excerpt, s.occurred_at, s.rank, s.total
                                    FROM sp_search($1, $2::jsonb, $3, $4) s LEFT JOIN mcp_members a ON a.member_id = s.author_member_id""", params.q or "", f, n, off)
        except asyncpg.PostgresError as ex:
            return err(f"The search could not be run: {ex}")
        total = int(rows[0]["total"]) if rows else 0
        out = [{"kind": r["entity_kind"], "page_id": r["page_id"], "message_id": r["entity_id"] if r["entity_kind"] == "message" else None, "comment_id": r["entity_uuid"] if r["entity_kind"] == "comment" else None,
                "space_id": r["space_id"], "channel_id": r["channel_id"], "author": r["author"], "title": r["title"], "excerpt": r["excerpt"], "occurred_at": r["occurred_at"], "rank": r["rank"]} for r in rows]
        return dumps({"total": total, "results": out, "next_cursor": str(off + len(rows)) if off + len(rows) < total else None})

    @mcp.tool(name="page_comments", annotations={"title": "A page's comments", **RO})
    async def page_comments(params: PageCommentsIn) -> str:
        """A page's comments and discussions, open ones first, each as Markdown with the block it is on (an excerpt of that block); replies carry their parent_comment_id."""
        try:
            pid = uid(params.page)
            await page_gate(fetch, pid)
        except BadArg as ex:
            return err(str(ex))
        w = Where().add("c.page_id = {}::uuid", pid).raw("c.deleted_at IS NULL")
        if params.open_only:
            w.raw("c.resolved_at IS NULL")
        rows = await fetch(f"""SELECT c.comment_id, c.parent_comment_id, c.block_id, left(b.plain_text, 140) AS block_excerpt, c.author_member_id, c.author_name, c.author_is_agent, sp_rich_text_markdown(c.body) AS markdown,
                                      c.resolved_at, c.created_at, c.edited_at
                                 FROM mcp_comments c LEFT JOIN mcp_blocks b ON b.block_id = c.block_id WHERE {w.sql()}
                                ORDER BY (c.resolved_at IS NOT NULL), c.created_at LIMIT {lim(params.limit, 50)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="my_open_discussions", annotations={"title": "My open discussions", **RO})
    async def my_open_discussions(params: LimitIn | None = None) -> str:
        """Unresolved discussions that mention me or sit on pages I own, newest first, each as Markdown with its page."""
        n = lim(params.limit if params else 25)
        mid = await me(fetch)
        rows = await fetch(f"""SELECT c.comment_id, c.page_id, p.plain_title AS page_title, c.block_id, c.author_name, sp_rich_text_markdown(c.body) AS markdown, c.created_at,
                                      ($1 = ANY (sp_rich_text_mentions(c.body, 'member'))) AS mentions_me, (p.owner_member_id = $1 OR p.wiki_owner_member_id = $1) AS on_my_page
                                 FROM mcp_comments c JOIN mcp_pages p ON p.page_id = c.page_id
                                WHERE c.deleted_at IS NULL AND c.resolved_at IS NULL AND c.parent_comment_id IS NULL
                                  AND ($1 = ANY (sp_rich_text_mentions(c.body, 'member')) OR p.owner_member_id = $1 OR p.wiki_owner_member_id = $1) ORDER BY c.created_at DESC LIMIT {n}""", mid)
        return dumps(rows)
