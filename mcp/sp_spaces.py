"""Spaces and people (S1-S4): find_spaces, get_space, space_members, find_members, find_departments, get_settings, find_templates. Reads the mcp_* views the screens read; the views decide who sees a space,
a member, a department (docs/spaces-mcp-tool-surface.md)."""
from __future__ import annotations

from pydantic import Field

from sp_common import RO, Ref, BadArg, as_uuid, Where, _Base, dumps, err, lim, match_words, must_be_member, plain, q_alone, space_ref, uid


class FindSpacesIn(_Base):
    q: str | None = Field(None, description="Words of the space's name. `q` alone resolves a space: a plain list (space_id, name)")
    kind: str | None = Field(None, description="open, closed or private")
    mine: bool | None = Field(None, description="true: only the spaces I am a member of")
    include_archived: bool = False
    limit: int = Field(25, ge=1, le=100)


class SpaceIn(_Base):
    space: Ref = Field(..., description="The space's id, slug or exact name")


class SpaceMembersIn(_Base):
    space: Ref = Field(..., description="The space's id, slug or exact name")
    q: str | None = Field(None, description="Words of a member's name")
    limit: int = Field(50, ge=1, le=100)


class FindMembersIn(_Base):
    q: str | None = Field(None, description="Words of a name. `q` alone resolves a person or an agent: a plain list (member_id, display_name)")
    kind: str | None = Field(None, description="human or agent")
    space: Ref | None = Field(None, description="Only the members of this space (id, slug or name)")
    limit: int = Field(25, ge=1, le=100)


class FindDepartmentsIn(_Base):
    q: str | None = Field(None, description="Words of a department's name. `q` alone resolves one: a plain list (department_id, name)")
    include_archived: bool = False
    limit: int = Field(50, ge=1, le=100)


class FindTemplatesIn(_Base):
    q: str | None = Field(None, description="Words of the template's title. `q` alone resolves one: a plain list (page_id, title)")
    kind: str | None = Field(None, description="page or database")
    space: Ref | None = Field(None, description="A space's templates (and the workspace's)")
    limit: int = Field(25, ge=1, le=100)


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_spaces", annotations={"title": "Find spaces", **RO})
    async def find_spaces(params: FindSpacesIn) -> str:
        """The spaces by name — mine, the open ones to join, the closed ones to request — each with its kind (open, closed, private), whether I am a member or an owner, and its counts. `q` alone resolves a space by name
        (a plain list of space_id and name). A guest sees only the spaces that hold something shared with them."""
        w = Where()
        if not params.include_archived:
            w.raw("s.archived_at IS NULL")
        if params.kind:
            if params.kind not in ("open", "closed", "private"):
                return err("kind is open, closed or private.")
            w.add("s.kind = {}", params.kind)
        if params.mine:
            w.raw("s.i_am_member")
        match_words(w, params.q, "s.name", "s.slug")
        rows = await fetch(f"""SELECT s.space_id, s.name, s.slug, s.icon, s.description, s.kind, s.is_default, s.is_wiki, s.department_id, s.i_am_member, s.i_am_owner, s.member_count, s.page_count, s.channel_count, s.archived_at
                                 FROM mcp_spaces s WHERE {w.sql()} ORDER BY s.archived_at IS NOT NULL, s.is_default DESC, lower(s.name) LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "space_id", "name")
        return dumps(rows)

    @mcp.tool(name="get_space", annotations={"title": "One space", **RO})
    async def get_space(params: SpaceIn) -> str:
        """One space in full: description, kind, the levels (member_level, everyone_level), the wiki setting, its default channel, its sections with their root pages, the channels I may see and how many members it has."""
        try:
            sid = await space_ref(fetch, params.space)
        except BadArg as ex:
            return err(str(ex))
        space = (await fetch("""SELECT s.space_id, s.name, s.slug, s.icon, s.description, s.kind, s.is_default, s.department_id, s.member_level, s.everyone_level, s.is_wiki, s.wiki_default_verify_months,
                                       s.default_channel_id, s.archived_at, s.created_at, s.i_am_member, s.i_am_owner, s.member_count, s.page_count, s.channel_count
                                  FROM mcp_spaces s WHERE s.space_id = $1""", sid))[0]
        sections = await fetch("SELECT section_id, name, position FROM mcp_space_sections WHERE space_id = $1 ORDER BY position, section_id", sid)
        roots = await fetch("""SELECT p.page_id, p.plain_title AS title, p.icon, p.kind, p.section_id, p.my_level FROM mcp_pages p
                                WHERE p.space_id = $1 AND p.parent_page_id IS NULL AND p.parent_database_id IS NULL AND p.archived_at IS NULL AND NOT p.is_template ORDER BY p.position, p.created_at""", sid)
        channels = await fetch("""SELECT c.channel_id, c.name, c.kind, c.topic, c.is_default, c.i_follow, c.archived_at FROM mcp_channels c WHERE c.space_id = $1 ORDER BY c.archived_at IS NOT NULL, c.is_default DESC, lower(c.name)""", sid)
        owners = await fetch("SELECT member_id, display_name, is_agent FROM mcp_space_members WHERE space_id = $1 AND role = 'owner' ORDER BY display_name", sid)
        for s in sections:
            s["root_pages"] = [r for r in roots if r["section_id"] == s["section_id"]]
        space["owners"] = owners
        space["sections"] = sections
        space["root_pages_without_section"] = [r for r in roots if r["section_id"] is None]
        space["channels"] = channels
        return dumps(space)

    @mcp.tool(name="space_members", annotations={"title": "Who is in a space", **RO})
    async def space_members(params: SpaceMembersIn) -> str:
        """Who is in a space and who owns it, agents marked; a member who is one only because a department is (derived) is marked so."""
        try:
            sid = await space_ref(fetch, params.space)
        except BadArg as ex:
            return err(str(ex))
        w = Where().add("m.space_id = {}", sid)
        match_words(w, params.q, "m.display_name")
        rows = await fetch(f"""SELECT m.member_id, m.display_name, m.is_agent, m.is_guest, m.role, m.joined_at, m.derived FROM mcp_space_members m WHERE {w.sql()}
                                ORDER BY (m.role = 'owner') DESC, lower(m.display_name) LIMIT {lim(params.limit, 50)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="find_members", annotations={"title": "Find people and agents", **RO})
    async def find_members(params: FindMembersIn) -> str:
        """Who is who — the members, the agents and the guests I may see — with the status line and presence. Nobody's email is shown but your own. `q` alone resolves a person or an agent by name (a plain list of member_id and display_name)."""
        w = Where()
        if params.kind:
            if params.kind not in ("human", "agent"):
                return err("kind is human or agent.")
            w.raw("m.is_agent" if params.kind == "agent" else "NOT m.is_agent")
        if params.space:
            try:
                sid = await space_ref(fetch, params.space)
            except BadArg as ex:
                return err(str(ex))
            w.add("m.member_id IN (SELECT sm.member_id FROM mcp_space_members sm WHERE sm.space_id = {})", sid)
        match_words(w, params.q, "m.display_name")
        rows = await fetch(f"""SELECT m.member_id, m.display_name, m.member_kind, m.is_agent, m.is_guest, m.business_role, m.is_external, m.roles, m.job_title, m.timezone, m.email,
                                      m.status_text, m.status_emoji, m.status_until, m.last_seen_at, m.is_active_now, m.department_ids
                                 FROM mcp_members m WHERE {w.sql()} ORDER BY lower(m.display_name) LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "member_id", "display_name")
        return dumps(rows)

    @mcp.tool(name="find_departments", annotations={"title": "Find departments", **RO})
    async def find_departments(params: FindDepartmentsIn) -> str:
        """The departments as principals and `@handles` — each with the space it has, when it has one. `q` alone resolves a department by name (a plain list of department_id and name)."""
        if (no := await must_be_member(fetch)) is not None:
            return err(no)
        w = Where()
        if not params.include_archived:
            w.raw("d.archived_at IS NULL")
        match_words(w, params.q, "d.name")
        rows = await fetch(f"""SELECT d.department_id, d.name, ('@' || regexp_replace(lower(d.name), '[^a-z0-9]+', '-', 'g')) AS handle, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at, d.space_id
                                 FROM mcp_departments d WHERE {w.sql()} ORDER BY lower(d.name) LIMIT {lim(params.limit, 50)}""", *w.args)
        if q_alone(params):
            return plain(rows, "department_id", "name")
        return dumps(rows)

    @mcp.tool(name="get_settings", annotations={"title": "The workspace's settings", **RO})
    async def get_settings() -> str:
        """The workspace's public settings: name, the default kinds and levels, how long versions and the trash are kept, the days after which a page is stale, the hours before a question counts as unanswered, the verification default,
        the attachment limit and the admin channel's name."""
        if (no := await must_be_member(fetch)) is not None:
            return err(no)
        rows = await fetch("SELECT * FROM mcp_settings")
        return dumps(rows[0] if rows else {})

    @mcp.tool(name="find_templates", annotations={"title": "Find templates", **RO})
    async def find_templates(params: FindTemplatesIn) -> str:
        """The templates: page templates and database templates, the workspace's and a space's. `q` alone resolves one by title (a plain list of page_id and title)."""
        w = Where().raw("p.is_template").raw("p.archived_at IS NULL")
        known = as_uuid(params.q) if q_alone(params) else None
        if known:                                     # the resolver was handed a template's id
            return plain(await fetch("SELECT page_id, plain_title AS title FROM mcp_pages WHERE page_id = $1::uuid AND is_template", known), "page_id", "title")
        if params.kind:
            if params.kind == "page":
                w.raw("p.kind = 'page' AND p.template_of_database_id IS NULL")
            elif params.kind == "database":
                w.raw("p.kind = 'database'")
            else:
                return err("kind is page or database.")
        if params.space:
            try:
                sid = await space_ref(fetch, params.space)
            except BadArg as ex:
                return err(str(ex))
            w.add("(p.space_id = {} OR p.space_id IN (SELECT space_id FROM mcp_spaces WHERE is_default))", sid)
        match_words(w, params.q, "p.plain_title")
        rows = await fetch(f"""SELECT p.page_id, p.plain_title AS title, p.icon, p.kind, p.space_id, s.name AS space_name, p.template_of_database_id, p.last_edited_at
                                 FROM mcp_pages p LEFT JOIN mcp_spaces s ON s.space_id = p.space_id WHERE {w.sql()} ORDER BY lower(p.plain_title) LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "page_id", "title")
        return dumps(rows)
