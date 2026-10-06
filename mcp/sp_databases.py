"""Databases (D1-D6): find_databases, get_database, database_rows, database_query, row_read, my_rows, rows_due, row_relations. A database is a page whose children are rows with typed properties, shown through views;
a view's filter, sort and group are applied in SQL by sp_database_rows(), relations come with titles, rollups computed and people with names; rich text is returned as plain text, a row as Markdown (docs/spaces-mcp-tool-surface.md)."""
from __future__ import annotations

import datetime
import json
import zoneinfo

import asyncpg
from pydantic import Field

from sp_common import RO, Ref, BadArg, as_uuid, Where, _Base, dumps, err, flat_props, lim, match_words, me, page_gate, plain, q_alone, space_ref, uid


class FindDatabasesIn(_Base):
    q: str | None = Field(None, description="Words of the title. `q` alone resolves a database: a plain list (database_id, title)")
    space: Ref | None = Field(None, description="A space (id, slug or name)")
    limit: int = Field(25, ge=1, le=100)


class GetDatabaseIn(_Base):
    database: str | None = Field(None, description="The database's id (a UUID)")
    q: str | None = Field(None, description="Words of a view's name. `q` alone resolves a view: a plain list (view_id, name); with `database`, narrows that database's views")
    part: str | None = Field(None, description="schema, views or both (the default)")


class RowsIn(_Base):
    database: str | None = Field(None, description="The database's id (a UUID): all its rows")
    view: str | None = Field(None, description="A view's id (a UUID): the rows that view shows, its filter, sort and group applied")
    cursor: str | None = Field(None, description="The next_cursor of the previous page")
    limit: int = Field(25, ge=1, le=100)


class QueryIn(_Base):
    database: str = Field(..., description="The database's id (a UUID)")
    filter: dict | str | None = Field(None, description='A Notion filter object, e.g. {"property": "Status", "status": {"equals": "Done"}} or {"and": [...]} / {"or": [...]}')
    sort: list | dict | str | None = Field(None, description='A sort list, e.g. [{"property": "Due", "direction": "ascending"}]')
    cursor: str | None = None
    limit: int = Field(25, ge=1, le=100)


class RowIn(_Base):
    row: str = Field(..., description="The row's id (a UUID)")


class MyRowsIn(_Base):
    database: str | None = Field(None, description="One database (a UUID); all I can see when left out")
    property: str | None = Field(None, description="A people property (name or key); every people property when left out")
    limit: int = Field(50, ge=1, le=100)


class DueIn(_Base):
    database: str | None = Field(None, description="One database (a UUID); all I can see when left out")
    property: str | None = Field(None, description="A date property (name or key); every date property when left out")
    within_days: int = Field(7, ge=0, le=366, description="Due from today through this many days ahead")
    overdue: bool = Field(False, description="true: only the rows whose date is past")
    status: str | None = Field(None, description="Only rows whose status (or select) property is this")
    limit: int = Field(50, ge=1, le=100)


class RelationsIn(_Base):
    row: str = Field(..., description="The row's id (a UUID)")
    property: str = Field(..., description="The relation property (name or key)")


def _json(v, what: str):
    if v is None or v == "":
        return None
    if isinstance(v, str):
        try:
            return json.loads(v)
        except ValueError as ex:
            raise BadArg(f"{what} is JSON: {ex}") from ex
    return v


def _offset(cursor: str | None) -> int:
    if not cursor:
        return 0
    if not str(cursor).isdigit():
        raise BadArg("cursor is the next_cursor an earlier answer gave.")
    return int(cursor)


def schema_out(props: dict) -> list[dict]:
    out = []
    for key, d in sorted((props or {}).items(), key=lambda kv: (kv[1].get("order", 100000), kv[0])):
        t = d.get("type")
        e = {"key": key, "name": d.get("name", key), "type": t}
        if t in ("select", "multi_select", "status"):
            e["options"] = [{k: o[k] for k in ("name", "color") if k in o} for o in (d.get(t) or {}).get("options", [])]
        elif t == "relation":
            e["relation"] = d.get("relation")
        elif t == "rollup":
            e["rollup"] = d.get("rollup")
        elif t == "number":
            e["format"] = (d.get("number") or {}).get("format")
        elif t == "unique_id":
            e["prefix"] = (d.get("unique_id") or {}).get("prefix")
        out.append(e)
    return out


def prop_key(props: dict, name: str | None, types: tuple[str, ...] | None = None) -> str | None:
    """A property's key by key or by name (case-insensitive)."""
    for k, d in (props or {}).items():
        if (types is None or d.get("type") in types) and name and (k == name or str(d.get("name", "")).lower() == name.lower()):
            return k
    return None


def register(mcp, fetch) -> None:
    async def database_row(database: str) -> dict:
        did = uid(database, "database")
        rows = await fetch("SELECT d.database_id, d.title, d.properties, d.space_id FROM mcp_databases d WHERE d.database_id = $1::uuid", did)
        if not rows:
            raise BadArg("No database you can see has that id.")
        return rows[0]

    async def today() -> datetime.date:
        rows = await fetch("SELECT timezone FROM mcp_settings")
        try:
            return datetime.datetime.now(zoneinfo.ZoneInfo((rows[0]["timezone"] if rows else None) or "UTC")).date()
        except Exception:  # noqa: BLE001
            return datetime.datetime.now(datetime.timezone.utc).date()

    def shape(rows: list[dict], offset: int, limit: int) -> dict:
        total = int(rows[0]["total"]) if rows else 0
        out = [{"row_id": r["row_id"], "title": r["title"], "icon": r["icon"], "properties": flat_props(r["properties"]), "group": r["group_value"], "sub_group": r["sub_group_value"],
                "created_at": r["created_at"], "last_edited_at": r["last_edited_at"]} for r in rows]
        more = offset + len(rows) < total
        return {"total": total, "rows": out, "next_cursor": str(offset + len(rows)) if more else None}

    async def run_rows(did: str, view: str | None, flt, sort, cursor, limit):
        off = _offset(cursor)
        n = lim(limit, 25)
        rows = await fetch("SELECT * FROM sp_database_rows($1::uuid, $2::uuid, $3::jsonb, $4::jsonb, $5, $6)", did, view, flt, sort, n, off)
        return shape(rows, off, n)

    @mcp.tool(name="find_databases", annotations={"title": "Find databases", **RO})
    async def find_databases(params: FindDatabasesIn) -> str:
        """The databases in my spaces with their row counts. `q` alone resolves a database by title (a plain list of database_id and title)."""
        known = as_uuid(params.q) if q_alone(params) else None
        if known:                                     # the resolver was handed an id: that database
            return plain(await fetch("SELECT database_id, title FROM mcp_databases WHERE database_id = $1::uuid", known), "database_id", "title")
        w = Where().raw("d.archived_at IS NULL").raw("NOT p.is_template")
        try:
            if params.space:
                w.add("d.space_id = {}", await space_ref(fetch, params.space))
        except BadArg as ex:
            return err(str(ex))
        match_words(w, params.q, "d.title")
        rows = await fetch(f"""SELECT d.database_id, d.title, d.icon, d.space_id, s.name AS space_name, d.description, d.is_inline, d.row_count, p.my_level, d.updated_at
                                 FROM mcp_databases d JOIN mcp_pages p ON p.page_id = d.database_id LEFT JOIN mcp_spaces s ON s.space_id = d.space_id
                                WHERE {w.sql()} ORDER BY lower(d.title) LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "database_id", "title")
        return dumps(rows)

    @mcp.tool(name="get_database", annotations={"title": "A database's schema and views", **RO})
    async def get_database(params: GetDatabaseIn) -> str:
        """A database's schema — every property with its type, options, relation target and rollup — and its views (layout, filter, sort, group). `q` alone resolves a VIEW by name (a plain list of view_id and name);
        `q` with `database` narrows that database's views; `part` is schema, views or both."""
        if params.part and params.part not in ("schema", "views"):
            return err("part is schema or views.")
        if params.database is None:
            if not params.q:
                return err("Give the database (its id), or words of a view's name in q.")
            w = Where()
            known = as_uuid(params.q)
            if known:                                 # the resolver was handed a view id
                w.add("v.view_id = {}::uuid", known)
            else:
                match_words(w, params.q, "v.name")
            rows = await fetch(f"""SELECT v.view_id, v.name, v.database_id, d.title AS database FROM mcp_database_views v JOIN mcp_databases d ON d.database_id = v.database_id
                                    WHERE d.archived_at IS NULL AND {w.sql()} ORDER BY lower(d.title), v.position LIMIT 50""", *w.args)
            return dumps(rows)
        try:
            d = await database_row(params.database)
        except BadArg as ex:
            return err(str(ex))
        out: dict = {"database_id": d["database_id"], "title": d["title"], "title_property_key": None}
        if params.part != "views":
            out["properties"] = schema_out(d["properties"])
        if params.part != "schema":
            w = Where().add("v.database_id = {}::uuid", str(d["database_id"]))
            match_words(w, params.q, "v.name")
            out["views"] = await fetch(f"""SELECT v.view_id, v.name, v.layout, v.filter, v.sort, v.group_by, v.sub_group_by, v.visible_properties, v.calendar_by, v.timeline_start, v.timeline_end, v.linked_from_page_id
                                             FROM mcp_database_views v WHERE {w.sql()} ORDER BY v.position, v.created_at""", *w.args)
        return dumps(out)

    @mcp.tool(name="database_rows", annotations={"title": "A database's rows", **RO})
    async def database_rows(params: RowsIn) -> str:
        """The rows a view shows — its filter, sort and group applied in SQL — or all of a database's rows, paged (`cursor`), each row's properties resolved: relations with titles, rollups computed, people with names, rich text as plain text."""
        try:
            if bool(params.database) == bool(params.view):
                return err("Give either database or view.")
            if params.view:
                vid = uid(params.view, "view")
                v = await fetch("SELECT database_id FROM mcp_database_views WHERE view_id = $1::uuid", vid)
                if not v:
                    return err("No view you can see has that id.")
                return dumps(await run_rows(str(v[0]["database_id"]), vid, None, None, params.cursor, params.limit))
            d = await database_row(params.database)
            return dumps(await run_rows(str(d["database_id"]), None, None, None, params.cursor, params.limit))
        except BadArg as ex:
            return err(str(ex))

    @mcp.tool(name="database_query", annotations={"title": "Query a database", **RO})
    async def database_query(params: QueryIn) -> str:
        """The rows of a database that match an ad-hoc Notion filter object and sort, paged. Property names or keys work in the filter; operators are equals, does_not_equal, contains, starts_with, greater_than, before, after, on_or_before,
        is_empty, is_not_empty, past_week, this_week … and `and` / `or` groups."""
        try:
            d = await database_row(params.database)
            flt, srt = _json(params.filter, "filter"), _json(params.sort, "sort")
            return dumps(await run_rows(str(d["database_id"]), None, flt, srt, params.cursor, params.limit))
        except BadArg as ex:
            return err(str(ex))
        except asyncpg.PostgresError as ex:
            return err(f"The filter or sort could not be applied: {ex}")

    @mcp.tool(name="row_read", annotations={"title": "Read a database row", **RO})
    async def row_read(params: RowIn) -> str:
        """One database row as Markdown: its properties as a list (relations as links, rollups computed) and its body, with the row's facts."""
        try:
            rid = uid(params.row, "row")
            head = await page_gate(fetch, rid)
        except BadArg as ex:
            return err(str(ex))
        if head["parent_database_id"] is None:
            return err("That page is not a database row; read it with page_read.")
        md = (await fetch("SELECT sp_page_markdown($1::uuid, true) AS md", rid))[0]["md"]
        props = (await fetch("SELECT sp_row_resolved($1::uuid) AS r", rid))[0]["r"]
        facts = (await fetch("""SELECT p.page_id AS row_id, p.plain_title AS title, p.icon, p.parent_database_id AS database_id, d.title AS database_title, p.my_level, p.last_edited_at, e.display_name AS last_edited_by_name
                                  FROM mcp_pages p LEFT JOIN mcp_databases d ON d.database_id = p.parent_database_id LEFT JOIN mcp_members e ON e.member_id = p.last_edited_by WHERE p.page_id = $1::uuid""", rid))[0]
        return dumps({**facts, "markdown": md, "properties": flat_props(props)})

    async def databases_for(database: str | None) -> list[dict]:
        if database:
            return [await database_row(database)]
        return await fetch("""SELECT d.database_id, d.title, d.properties FROM mcp_databases d JOIN mcp_pages p ON p.page_id = d.database_id WHERE d.archived_at IS NULL AND NOT p.is_template ORDER BY lower(d.title) LIMIT 60""")

    @mcp.tool(name="my_rows", annotations={"title": "Rows that name me", **RO})
    async def my_rows(params: MyRowsIn) -> str:
        """The database rows that name me in a people property (an owner, an assignee), across my databases or one — each with the database, the property and the row's resolved properties."""
        mid = await me(fetch)
        try:
            dbs = await databases_for(params.database)
        except BadArg as ex:
            return err(str(ex))
        found = []
        for d in dbs:
            keys = [k for k, p in (d["properties"] or {}).items() if p.get("type") == "people" and (not params.property or k == prop_key(d["properties"], params.property))]
            if not keys:
                continue
            flt = {"or": [{"property": k, "people": {"contains": str(mid)}} for k in keys]}
            rows = await fetch("SELECT * FROM sp_database_rows($1::uuid, NULL, $2::jsonb, NULL, $3, 0)", str(d["database_id"]), flt, lim(params.limit, 50))
            for r in shape(rows, 0, lim(params.limit, 50))["rows"]:
                found.append({"database_id": d["database_id"], "database": d["title"], "people_properties": keys, **r})
        return dumps(found[: lim(params.limit, 50)])

    @mcp.tool(name="rows_due", annotations={"title": "Rows due", **RO})
    async def rows_due(params: DueIn) -> str:
        """Rows whose date property falls within N days from today (7 by default) — or, with overdue, is already past — across my databases or one, optionally only those with a given status. Each row says which database and date."""
        t = await today()
        try:
            dbs = await databases_for(params.database)
        except BadArg as ex:
            return err(str(ex))
        found = []
        for d in dbs:
            props = d["properties"] or {}
            dates = [k for k, p in props.items() if p.get("type") == "date" and (not params.property or k == prop_key(props, params.property))]
            if not dates:
                continue
            if params.overdue:
                dflt = {"or": [{"property": k, "date": {"before": t.isoformat()}} for k in dates]}
            else:
                dflt = {"or": [{"and": [{"property": k, "date": {"on_or_after": t.isoformat()}}, {"property": k, "date": {"on_or_before": (t + datetime.timedelta(days=params.within_days)).isoformat()}}]} for k in dates]}
            flt = dflt
            if params.status:
                st = [k for k, p in props.items() if p.get("type") in ("status", "select") and (k == "status" or str(p.get("name", "")).lower() == "status")]
                if not st:
                    continue
                flt = {"and": [dflt, {"property": st[0], props[st[0]]["type"]: {"equals": params.status}}]}
            rows = await fetch("SELECT * FROM sp_database_rows($1::uuid, NULL, $2::jsonb, NULL, $3, 0)", str(d["database_id"]), flt, lim(params.limit, 50))
            for r in shape(rows, 0, lim(params.limit, 50))["rows"]:
                found.append({"database_id": d["database_id"], "database": d["title"], "date_properties": dates, **r})
        return dumps(found[: lim(params.limit, 50)])

    @mcp.tool(name="row_relations", annotations={"title": "A row's relations", **RO})
    async def row_relations(params: RelationsIn) -> str:
        """The rows related to a row through a relation property, with their titles."""
        try:
            rid = uid(params.row, "row")
            head = await page_gate(fetch, rid)
            if head["parent_database_id"] is None:
                return err("That page is not a database row.")
            d = await database_row(str(head["parent_database_id"]))
        except BadArg as ex:
            return err(str(ex))
        key = prop_key(d["properties"], params.property, ("relation",))
        if key is None:
            return err("That database has no relation property by that name.")
        rows = await fetch("""SELECT r.to_row_id AS row_id, p.plain_title AS title, p.icon, p.parent_database_id AS database_id, r.position FROM mcp_row_relations r JOIN mcp_pages p ON p.page_id = r.to_row_id
                               WHERE r.from_row_id = $1::uuid AND r.property_key = $2 AND p.archived_at IS NULL ORDER BY r.position""", rid, key)
        return dumps({"row_id": rid, "property": key, "related": rows})
