"""Pages and the wiki (P1-P11): page_tree, find_pages, page_read, get_page, page_blocks, backlinks, pages_changed_since, page_permissions, page_versions, page_version_read, page_diff, wiki_status, stale_pages,
orphan_pages, duplicate_titles, broken_links, my_favorites, my_recents, trash, published_pages. Reads the mcp_* views and the SQL read functions the screens read (sp_page_markdown, sp_page_children, sp_backlinks, sp_wiki_status …);
every page reaches an agent as Markdown beside its facts."""
from __future__ import annotations

import difflib

from pydantic import Field

from sp_common import RO, Ref, BadArg, as_uuid, Where, _Base, dumps, err, flag, flat_props, lim, localize, caller_tz, match_words, page_gate, plain, q_alone, rank, space_ref, uid, when, member_ref


class PageTreeIn(_Base):
    space: Ref | None = Field(None, description="A space (id, slug or name): its sections and root pages")
    page: str | None = Field(None, description="A page: the subpages under it")
    depth: int = Field(2, ge=1, le=5, description="How many levels down (1-5)")


class FindPagesIn(_Base):
    q: str | None = Field(None, description="Words of the title. `q` alone resolves a page: a plain list (page_id, title), newest edited first")
    space: Ref | None = Field(None, description="A space (id, slug or name)")
    parent: str | None = Field(None, description="A parent page id: only its direct children")
    kind: str | None = Field(None, description="page, database or row")
    changed_since: str | None = Field(None, description="A day or an ISO time")
    limit: int = Field(25, ge=1, le=100)


class PageIn(_Base):
    page: str = Field(..., description="The page's id (a UUID; find_pages finds it by title)")


class PageReadIn(_Base):
    page: str = Field(..., description="The page's id (a UUID)")
    with_subpages: bool = Field(False, description="Also list the pages under it")
    with_backlinks: bool = Field(False, description="Also list what links to it")


class PageBlocksIn(_Base):
    page: str = Field(..., description="The page's id (a UUID)")
    parent: str | None = Field(None, description="A block id: only that block's children")


class BacklinksIn(_Base):
    page: str = Field(..., description="The page's id (a UUID)")
    limit: int = Field(50, ge=1, le=100)


class ChangedIn(_Base):
    since: str = Field(..., description="A day or an ISO time")
    space: Ref | None = None
    limit: int = Field(25, ge=1, le=100)


class VersionsIn(_Base):
    page: str | None = Field(None, description="The page's id (a UUID)")
    q: str | None = Field(None, description="Words of a page's title (with `q` alone: the versions of the pages that match, a plain list of version_id and label)")
    limit: int = Field(25, ge=1, le=100)


class VersionIn(_Base):
    version: int = Field(..., description="A version's id (page_versions lists them)")


class DiffIn(_Base):
    page: str = Field(..., description="The page's id (a UUID)")
    from_version: int = Field(..., description="The version NUMBER (v3 is 3) to start from")
    to_version: int | None = Field(None, description="The version number to compare with; the present page when left out")


class WikiIn(_Base):
    space: Ref | None = Field(None, description="A wiki space (id, slug or name); every wiki space when left out")
    state: str | None = Field(None, description="verified, expired or never")
    owner: Ref | None = Field(None, description="A page owner (member id or name)")
    limit: int = Field(50, ge=1, le=100)


class StaleIn(_Base):
    days: int | None = Field(None, ge=1, le=3650, description="Not edited for this many days (the workspace's setting by default)")
    space: Ref | None = None
    limit: int = Field(50, ge=1, le=100)


class SpaceListIn(_Base):
    space: Ref | None = None
    limit: int = Field(50, ge=1, le=100)


class LimitIn(_Base):
    limit: int = Field(25, ge=1, le=100)


class TrashIn(_Base):
    space: Ref | None = None
    limit: int = Field(50, ge=1, le=100)


STATE_WORDS = {"never": "none", "none": "none", "verified": "verified", "expired": "expired"}


def register(mcp, fetch) -> None:
    async def space_id_or_none(value):
        return None if not value else await space_ref(fetch, value)

    async def children(page_id: str, depth: int) -> list[dict]:
        rows = await fetch("SELECT page_id, title, icon, kind, has_children FROM sp_page_children($1::uuid)", page_id)
        if depth > 1:
            for r in rows:
                r["children"] = await children(str(r["page_id"]), depth - 1) if r["has_children"] else []
        return rows

    @mcp.tool(name="page_tree", annotations={"title": "The page tree", **RO})
    async def page_tree(params: PageTreeIn) -> str:
        """The pages of a space in sidebar order — its sections and root pages — or the subpages under a page, `depth` levels down (1-5, 2 by default). Only pages you may see appear."""
        try:
            if bool(params.space) == bool(params.page):
                return err("Give either space or page.")
            if params.page:
                pid = uid(params.page)
                head = await page_gate(fetch, pid)
                return dumps({"page_id": pid, "title": head["plain_title"], "children": await children(pid, params.depth)})
            sid = await space_ref(fetch, params.space)
        except BadArg as ex:
            return err(str(ex))
        sections = await fetch("SELECT section_id, name FROM mcp_space_sections WHERE space_id = $1 ORDER BY position, section_id", sid)
        roots = await fetch("""SELECT p.page_id, p.plain_title AS title, p.icon, p.kind, p.section_id, p.child_count > 0 AS has_children FROM mcp_pages p
                                WHERE p.space_id = $1 AND p.parent_page_id IS NULL AND p.parent_database_id IS NULL AND p.archived_at IS NULL AND NOT p.is_template ORDER BY p.position, p.created_at""", sid)
        for r in roots:
            if params.depth > 1 and r["has_children"]:
                r["children"] = await children(str(r["page_id"]), params.depth - 1)
        for s in sections:
            s["pages"] = [r for r in roots if r["section_id"] == s["section_id"]]
        return dumps({"space_id": sid, "sections": sections, "pages": [r for r in roots if r["section_id"] is None]})

    @mcp.tool(name="find_pages", annotations={"title": "Find pages", **RO})
    async def find_pages(params: FindPagesIn) -> str:
        """Pages by title words — in a space, under a parent, by kind (page, database, row), changed since a time — newest edited first. `q` alone resolves a page by title (a plain list of page_id and title). Templates and the trash are not listed."""
        known = as_uuid(params.q) if q_alone(params) else None
        if known:                                     # the resolver was handed an id (read from page_read, say): that page, trash and all
            rows = await fetch("SELECT page_id, plain_title AS title FROM mcp_pages WHERE page_id = $1::uuid", known)
            return plain(rows, "page_id", "title")
        w = Where().raw("p.archived_at IS NULL").raw("NOT p.is_template")
        try:
            sid = await space_id_or_none(params.space)
            if sid is not None:
                w.add("p.space_id = {}", sid)
            if params.parent:
                w.add("p.parent_page_id = {}::uuid", uid(params.parent, "parent"))
            if params.changed_since:
                w.add("p.last_edited_at >= {}", when(params.changed_since, "changed_since"))
        except BadArg as ex:
            return err(str(ex))
        if params.kind:
            if params.kind == "row":
                w.raw("p.parent_database_id IS NOT NULL")
            elif params.kind in ("page", "database"):
                w.add("p.kind = {}", params.kind).raw("p.parent_database_id IS NULL")
            else:
                return err("kind is page, database or row.")
        match_words(w, params.q, "p.plain_title")
        rows = await fetch(f"""SELECT p.page_id, p.plain_title AS title, p.icon, p.kind, (p.parent_database_id IS NOT NULL) AS is_row, p.space_id, s.name AS space_name, p.parent_page_id, p.parent_database_id, p.my_level, p.verification_state,
                                      p.last_edited_at, e.display_name AS last_edited_by_name
                                 FROM mcp_pages p LEFT JOIN mcp_spaces s ON s.space_id = p.space_id LEFT JOIN mcp_members e ON e.member_id = p.last_edited_by
                                WHERE {w.sql()} ORDER BY p.last_edited_at DESC LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "page_id", "title")
        return dumps(rows)

    @mcp.tool(name="page_read", annotations={"title": "Read a page", **RO})
    async def page_read(params: PageReadIn) -> str:
        """What a page says: its body as Markdown, with its facts — owner, verification, where it sits (the breadcrumb) and, for a database row, its properties. `with_subpages` lists the pages under it, `with_backlinks` what links to it.
        This is the tool an agent reads with; page_blocks is for editing one block."""
        try:
            pid = uid(params.page)
            head = await page_gate(fetch, pid)
        except BadArg as ex:
            return err(str(ex))
        md = (await fetch("SELECT sp_page_markdown($1::uuid, false) AS md", pid))[0]["md"]
        crumbs = await fetch("SELECT page_id, plain_title AS title FROM sp_page_ancestors($1::uuid) ORDER BY depth DESC", pid)
        facts = (await fetch("""SELECT p.page_id, p.plain_title AS title, p.icon, p.kind, p.space_id, s.name AS space_name, p.parent_page_id, p.parent_database_id, p.is_locked, p.my_level, p.is_published,
                                       p.verification_state, p.verified_at, p.verify_until, o.display_name AS wiki_owner_name, p.last_edited_at, e.display_name AS last_edited_by_name, p.archived_at, p.open_comment_count
                                  FROM mcp_pages p LEFT JOIN mcp_spaces s ON s.space_id = p.space_id LEFT JOIN mcp_members e ON e.member_id = p.last_edited_by LEFT JOIN mcp_members o ON o.member_id = p.wiki_owner_member_id
                                 WHERE p.page_id = $1::uuid""", pid))[0]
        out = {**facts, "breadcrumb": [c["title"] for c in crumbs if str(c["page_id"]) != pid], "markdown": md}
        if head["parent_database_id"] is not None:
            props = (await fetch("SELECT sp_row_resolved($1::uuid) AS r", pid))[0]["r"]
            out["properties"] = flat_props(props)
        if params.with_subpages:
            out["subpages"] = await fetch("SELECT page_id, title, icon, kind FROM sp_page_children($1::uuid)", pid)
        if params.with_backlinks:
            out["backlinks"] = await fetch("SELECT from_page_id, from_title, kind, message_id, channel_id FROM sp_backlinks($1::uuid) LIMIT 100", pid)
        return dumps(out)

    @mcp.tool(name="get_page", annotations={"title": "A page's facts", **RO})
    async def get_page(params: PageIn) -> str:
        """A page's facts without the body: title, icon, where it sits (space, parent, section), my level, lock, verification, how many versions it has, whether it is published, and its last edit."""
        try:
            pid = uid(params.page)
            await page_gate(fetch, pid)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("""SELECT p.page_id, p.plain_title AS title, p.icon, p.kind, p.is_template, p.space_id, s.name AS space_name, p.parent_page_id, pp.plain_title AS parent_title, p.parent_database_id, p.section_id, p.is_locked, p.my_level,
                                     p.is_published, p.is_favorite, p.child_count, p.open_comment_count, p.verification_state, p.verified_at, p.verify_until, p.wiki_owner_member_id, o.display_name AS wiki_owner_name,
                                     p.owner_member_id, p.version_no, (SELECT count(*) FROM mcp_page_versions v WHERE v.page_id = p.page_id) AS versions_count,
                                     p.created_at, p.created_by, p.last_edited_at, p.last_edited_by, e.display_name AS last_edited_by_name, p.archived_at
                                FROM mcp_pages p LEFT JOIN mcp_spaces s ON s.space_id = p.space_id LEFT JOIN mcp_pages pp ON pp.page_id = p.parent_page_id
                                LEFT JOIN mcp_members e ON e.member_id = p.last_edited_by LEFT JOIN mcp_members o ON o.member_id = p.wiki_owner_member_id WHERE p.page_id = $1::uuid""", pid)
        return dumps(rows[0])

    @mcp.tool(name="page_blocks", annotations={"title": "A page's blocks", **RO})
    async def page_blocks(params: PageBlocksIn) -> str:
        """The block tree as JSON — ids, types, versions, content — for an agent that edits one block (block_update needs the block's id and its version). `parent` limits it to one block's children."""
        try:
            pid = uid(params.page)
            await page_gate(fetch, pid)
            parent = uid(params.parent, "parent") if params.parent else None
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("SELECT sp_block_children_json($1::uuid, $2::uuid, 0) AS tree", pid, parent)
        return dumps({"page_id": pid, "parent": parent, "blocks": rows[0]["tree"]})

    @mcp.tool(name="backlinks", annotations={"title": "What links here", **RO})
    async def backlinks(params: BacklinksIn) -> str:
        """What links to a page: pages (with the block that holds the link), database rows (a relation) and messages that mention it."""
        try:
            pid = uid(params.page)
            await page_gate(fetch, pid)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch(f"SELECT from_page_id, from_title, from_space_id, kind, from_block_id, message_id, channel_id FROM sp_backlinks($1::uuid) LIMIT {lim(params.limit, 50)}", pid)
        return dumps(rows)

    @mcp.tool(name="pages_changed_since", annotations={"title": "Pages changed since", **RO})
    async def pages_changed_since(params: ChangedIn) -> str:
        """Pages edited since a time in my spaces (or one), newest first, with who edited."""
        try:
            s = when(params.since, "since")
            sid = await space_id_or_none(params.space)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("SELECT page_id, title, space_id, kind, last_edited_at, last_edited_by, editor_name, is_row FROM sp_pages_changed_since($1, $2, $3)", s, sid, lim(params.limit))
        return dumps(rows)

    @mcp.tool(name="page_permissions", annotations={"title": "Who may see or edit a page", **RO})
    async def page_permissions(params: PageIn) -> str:
        """Who may see or edit a page and why: the space's rule or the inherited explicit set, each principal with its level and where it comes from. Needs edit access to the page."""
        try:
            pid = uid(params.page)
            await page_gate(fetch, pid, "edit")
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("SELECT source, source_page_id, source_title, principal_kind, principal_id, principal_name, level FROM sp_page_permissions_explained($1::uuid)", pid)
        return dumps(rows)

    @mcp.tool(name="page_versions", annotations={"title": "A page's versions", **RO})
    async def page_versions(params: VersionsIn) -> str:
        """A page's versions: number, when, why (reason), by whom. With `q` alone (no page) the versions of the pages whose title matches — a plain list of version_id and a label such as "v3 — manual, 3 Oct 14:02"."""
        w = Where()
        if params.page:
            try:
                pid = uid(params.page)
                await page_gate(fetch, pid)
            except BadArg as ex:
                return err(str(ex))
            w.add("v.page_id = {}::uuid", pid)
        elif not params.q:
            return err("Give the page (its id), or words of its title in q.")
        match_words(w, params.q, "p.plain_title")
        rows = await fetch(f"""SELECT v.version_id, v.page_id, p.plain_title AS page_title, v.version_no, v.reason, v.saved_by, m.display_name AS saved_by_name, v.created_at, v.text_length,
                                      'v' || v.version_no || ' — ' || v.reason || ', ' || to_char(v.created_at AT TIME ZONE 'UTC', 'FMDD Mon HH24:MI') || CASE WHEN {'FALSE' if params.page else 'TRUE'} THEN ' (' || p.plain_title || ')' ELSE '' END AS label
                                 FROM mcp_page_versions v JOIN mcp_pages p ON p.page_id = v.page_id LEFT JOIN mcp_members m ON m.member_id = v.saved_by
                                WHERE {w.sql()} ORDER BY v.created_at DESC, v.version_id DESC LIMIT {lim(params.limit)}""", *w.args)
        if not params.page and q_alone(params):
            return plain(rows, "version_id", "label")
        return dumps(rows)

    @mcp.tool(name="page_version_read", annotations={"title": "Read an old version", **RO})
    async def page_version_read(params: VersionIn) -> str:
        """One version of a page as Markdown, as it was."""
        rows = await fetch("SELECT v.version_id, v.page_id, v.version_no, v.reason, v.created_at FROM mcp_page_versions v WHERE v.version_id = $1", params.version)
        if not rows:
            return err("No version of a page you can see has that id.")
        md = (await fetch("SELECT sp_version_markdown($1) AS md", params.version))[0]["md"]
        return dumps({**rows[0], "markdown": md})

    @mcp.tool(name="page_diff", annotations={"title": "Compare two versions", **RO})
    async def page_diff(params: DiffIn) -> str:
        """Two versions of a page (or a version and the present) compared, line by line, as a unified diff of their Markdown. Versions are named by their number (page_versions lists them)."""
        try:
            pid = uid(params.page)
            await page_gate(fetch, pid)
        except BadArg as ex:
            return err(str(ex))

        async def text_of(no: int) -> str | None:
            r = await fetch("SELECT version_id FROM mcp_page_versions WHERE page_id = $1::uuid AND version_no = $2", pid, no)
            return (await fetch("SELECT sp_version_markdown($1) AS md", r[0]["version_id"]))[0]["md"] if r else None

        a = await text_of(params.from_version)
        if a is None:
            return err(f"The page has no version {params.from_version}.")
        if params.to_version is None:
            b, to_label = (await fetch("SELECT sp_page_markdown($1::uuid, true) AS md", pid))[0]["md"], "present"
        else:
            b, to_label = await text_of(params.to_version), f"v{params.to_version}"
            if b is None:
                return err(f"The page has no version {params.to_version}.")
        a, b = (a or "").strip(), (b or "").strip()          # a snapshot carries the title heading, so the present is read with it; trailing blank lines are not a difference
        diff = "\n".join(difflib.unified_diff(a.splitlines(), b.splitlines(), f"v{params.from_version}", to_label, lineterm="", n=2))
        return dumps({"page_id": pid, "from": f"v{params.from_version}", "to": to_label, "identical": a == b, "diff": diff})

    @mcp.tool(name="wiki_status", annotations={"title": "The wiki's verification", **RO})
    async def wiki_status(params: WikiIn) -> str:
        """A wiki space's pages with owner and verification state — verified, expired (verification ran out) or never (state `none` in the rows) — and the days since the last edit. Every wiki space I see when none is named."""
        try:
            sid = await space_id_or_none(params.space)
            owner = await member_ref(fetch, params.owner) if params.owner else None
        except BadArg as ex:
            return err(str(ex))
        state = None
        if params.state:
            state = STATE_WORDS.get(params.state)
            if state is None:
                return err("state is verified, expired or never.")
        rows = await fetch("SELECT page_id, title, space_id, space_name, verification_state, verified_at, verify_until, wiki_owner_member_id, owner_name, last_edited_at, days_since_edit FROM sp_wiki_status($1)", sid)
        rows = [r for r in rows if (state is None or r["verification_state"] == state) and (owner is None or r["wiki_owner_member_id"] == owner)]
        return dumps(rows[: lim(params.limit, 50)])

    @mcp.tool(name="stale_pages", annotations={"title": "Stale pages", **RO})
    async def stale_pages(params: StaleIn) -> str:
        """Pages not edited in N days (the workspace's setting by default) that are still in a sidebar, oldest edit first."""
        try:
            sid = await space_id_or_none(params.space)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("SELECT page_id, title, space_id, last_edited_at, days_since_edit, owner_member_id FROM sp_stale_pages($1)", params.days)
        return dumps([r for r in rows if sid is None or r["space_id"] == sid][: lim(params.limit, 50)])

    @mcp.tool(name="orphan_pages", annotations={"title": "Orphan pages", **RO})
    async def orphan_pages(params: SpaceListIn) -> str:
        """Pages nothing links to and no sidebar holds."""
        try:
            sid = await space_id_or_none(params.space)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("SELECT page_id, title, space_id, last_edited_at FROM sp_orphan_pages()")
        return dumps([r for r in rows if sid is None or r["space_id"] == sid][: lim(params.limit, 50)])

    @mcp.tool(name="duplicate_titles", annotations={"title": "Duplicate titles", **RO})
    async def duplicate_titles(params: SpaceListIn) -> str:
        """Pages that share a title, regardless of case: the title, how many, their ids and the spaces they sit in."""
        try:
            sid = await space_id_or_none(params.space)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("SELECT title, page_ids, space_ids, n FROM sp_duplicate_titles()")
        return dumps([r for r in rows if sid is None or sid in (r["space_ids"] or [])][: lim(params.limit, 50)])

    @mcp.tool(name="broken_links", annotations={"title": "Broken links", **RO})
    async def broken_links(params: SpaceListIn) -> str:
        """Links to the trash or to nothing, with the page and the block that carry them."""
        try:
            sid = await space_id_or_none(params.space)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch("""SELECT b.from_page_id, b.from_title, b.from_block_id, b.to_page_id, b.to_title, b.reason, p.space_id FROM sp_broken_links() b LEFT JOIN mcp_pages p ON p.page_id = b.from_page_id
                               WHERE ($1::bigint IS NULL OR p.space_id = $1)""", sid)
        return dumps(rows[: lim(params.limit, 50)])

    @mcp.tool(name="my_favorites", annotations={"title": "My favorites", **RO})
    async def my_favorites() -> str:
        """My favorite pages, in my order — the pages I starred, each with its title, icon, kind and space. Takes no arguments."""
        rows = await fetch("""SELECT p.page_id, p.plain_title AS title, p.icon, p.kind, p.space_id, f.position FROM mcp_page_favorites f JOIN mcp_pages p ON p.page_id = f.page_id
                               WHERE f.member_id = (SELECT nullif(current_setting('app.member_id', true), '')::bigint) AND p.archived_at IS NULL ORDER BY f.position, f.created_at""")
        return dumps(rows)

    @mcp.tool(name="my_recents", annotations={"title": "My recent pages", **RO})
    async def my_recents(params: LimitIn | None = None) -> str:
        """The pages I opened recently, newest first."""
        n = lim(params.limit if params else 25)
        rows = await fetch(f"""SELECT p.page_id, p.plain_title AS title, p.icon, p.kind, p.space_id, r.visited_at FROM mcp_page_recents r JOIN mcp_pages p ON p.page_id = r.page_id
                                WHERE r.member_id = (SELECT nullif(current_setting('app.member_id', true), '')::bigint) AND p.archived_at IS NULL ORDER BY r.visited_at DESC LIMIT {n}""")
        return dumps(rows)

    @mcp.tool(name="trash", annotations={"title": "The trash", **RO})
    async def trash(params: TrashIn | None = None) -> str:
        """What is in the trash that I may restore — pages I hold full access on (the admin: all) — and when each is purged for good."""
        params = params or TrashIn()
        try:
            sid = await space_id_or_none(params.space)
        except BadArg as ex:
            return err(str(ex))
        rows = await fetch(f"""SELECT t.page_id, t.plain_title AS title, t.icon, t.kind, t.space_id, t.archived_at, t.archived_by, m.display_name AS archived_by_name, t.purge_at
                                 FROM mcp_trash t LEFT JOIN mcp_members m ON m.member_id = t.archived_by
                                WHERE ((SELECT sp_is_admin()) OR sp_has_full_page(t.page_id)) AND ($1::bigint IS NULL OR t.space_id = $1) ORDER BY t.archived_at DESC LIMIT {lim(params.limit, 50)}""", sid)
        return dumps(rows)

    @mcp.tool(name="published_pages", annotations={"title": "Published pages", **RO})
    async def published_pages(params: LimitIn | None = None) -> str:
        """The pages published to the web: when, by whom, whether subpages are included, how often opened and last opened — never the address's token. The admin sees all; otherwise the pages I hold full access on."""
        n = lim(params.limit if params else 25)
        rows = await fetch(f"""SELECT b.publication_id, b.page_id, p.plain_title AS title, b.include_subpages, b.noindex, b.published_by, m.display_name AS published_by_name, b.published_at, b.views, b.last_viewed_at
                                 FROM mcp_page_publications b JOIN mcp_pages p ON p.page_id = b.page_id LEFT JOIN mcp_members m ON m.member_id = b.published_by
                                WHERE b.revoked_at IS NULL AND ((SELECT sp_is_admin()) OR sp_has_full_page(b.page_id)) ORDER BY b.published_at DESC LIMIT {n}""")
        return dumps(rows)
