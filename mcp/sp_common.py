"""Shared pieces for Spaces' tool modules: annotations, the input base, the where-builder, ids, times, the plain-list answer the kernel's entity resolver reads, and the
rich-text flattening that turns a stored run array into the Markdown or plain text an agent reads. Every query runs as the asking member (db.fetch_scoped sets app.member_id from
the verified bearer), so the mcp_* views and the sp_* read functions decide the rows; nothing here filters by who the caller is, and what a view blanked is passed through as the
null it is — never filled."""
from __future__ import annotations

import datetime
import re
import uuid as _uuid
import zoneinfo

from pydantic import BaseModel, ConfigDict

import db

RO = {"readOnlyHint": True, "openWorldHint": False}


Ref = int | str          # an id or a name: a space, a channel, a member


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


class _Share(BaseModel):
    # The kernel adds fields of its own to a share's call; a share ignores what it does not know.
    model_config = ConfigDict(str_strip_whitespace=True, extra="ignore", populate_by_name=True)


def dumps(obj) -> str:
    return db.to_json(obj)


def err(message: str, **extra) -> str:
    return dumps({"error": message, **extra})


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        """`sql` uses {} for each value in order, e.g. add('p.space_id = {}', 4)."""
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def words(q: str | None) -> list[str]:
    return [w for w in re.split(r"\s+", (q or "").strip()) if w][:6]


def word_start(w: str) -> str:
    """A regex that matches a word beginning with w (names match at the start of a word)."""
    return "\\m" + re.escape(w[:40])


def match_words(w: Where, q: str | None, *columns: str) -> Where:
    """Every word of q must start a word in one of the columns."""
    for x in words(q):
        n = w.p(word_start(x))
        w.raw("(" + " OR ".join(f"{c} ~* {n}" for c in columns) + ")")
    return w


def contains_words(w: Where, q: str | None, *columns: str) -> Where:
    """Every word of q must appear (anywhere) in one of the columns — titles and message excerpts."""
    for x in words(q):
        n = w.p("%" + x.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%")
        w.raw("(" + " OR ".join(f"{c} ILIKE {n}" for c in columns) + ")")
    return w


def q_alone(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given — the kernel's entity resolver's call, answered as a plain list of {id, label} rows."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}


def plain(rows: list[dict], id_field: str, label_field: str) -> str:
    return dumps([{id_field: r[id_field], label_field: r[label_field]} for r in rows])


class BadArg(ValueError):
    """An argument the caller can fix: its message is the answer."""


def uid(value, what: str = "page") -> str:
    """A UUID as its canonical string, or a sentence."""
    try:
        return str(_uuid.UUID(str(value)))
    except (ValueError, AttributeError, TypeError) as ex:
        raise BadArg(f"{what} is an id (a UUID such as 3f2b9c1e-…); find it by name with the find_ tool.") from ex


def as_uuid(value) -> str | None:
    """The canonical UUID when `value` is one (the kernel's resolver passes whatever the agent gave — an id read from page_read included — as `q`), else None."""
    try:
        return str(_uuid.UUID(str(value).strip())) if value else None
    except (ValueError, AttributeError, TypeError):
        return None


def when(s: str | datetime.datetime | None, what: str = "The time", end_of_day: bool = False) -> datetime.datetime | None:
    """A YYYY-MM-DD day or an ISO 8601 time as an aware datetime (None stays None); anything else is a sentence."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.datetime):
        return s if s.tzinfo else s.replace(tzinfo=datetime.timezone.utc)
    t = str(s).strip()
    if re.fullmatch(r"\d{4}-\d{2}-\d{2}", t):
        try:
            d = datetime.date.fromisoformat(t)
        except ValueError as ex:
            raise BadArg(f"{what} is a day (2026-03-02) or an ISO time (2026-03-02T09:30:00Z).") from ex
        return datetime.datetime.combine(d + datetime.timedelta(days=1 if end_of_day else 0), datetime.time.min, tzinfo=datetime.timezone.utc)
    try:
        v = datetime.datetime.fromisoformat(t.replace("Z", "+00:00"))
    except ValueError as ex:
        raise BadArg(f"{what} is a day (2026-03-02) or an ISO time (2026-03-02T09:30:00Z).") from ex
    return v if v.tzinfo else v.replace(tzinfo=datetime.timezone.utc)


async def me(fetch) -> int | None:
    rows = await fetch("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m")
    return rows[0]["m"] if rows else None


async def flag(fetch, fn: str) -> bool:
    """One of the caller-only booleans: sp_is_admin, sp_is_guest, sp_is_member_here."""
    assert fn in ("sp_is_admin", "sp_is_guest", "sp_is_member_here")
    rows = await fetch(f"SELECT {fn}() AS ok")
    return bool(rows and rows[0]["ok"])


async def must_be_member(fetch) -> str | None:
    """The refusal sentence for a guest on a workspace-level tool, else None."""
    return None if await flag(fetch, "sp_is_member_here") else "That is a member's tool: a guest sees only what is shared with them (the pages and channels they were given)."


async def caller_tz(fetch) -> str:
    rows = await fetch("SELECT timezone FROM mcp_members WHERE member_id = (SELECT nullif(current_setting('app.member_id', true), '')::bigint)")
    tz = (rows[0]["timezone"] if rows else None) or "UTC"
    try:
        zoneinfo.ZoneInfo(tz)
    except Exception:  # noqa: BLE001
        return "UTC"
    return tz


def localize(rows: list[dict], tz: str, *fields: str) -> list[dict]:
    """Add `<field>_local` ('YYYY-MM-DDTHH:MM' in the caller's zone) beside each UTC timestamp."""
    z = zoneinfo.ZoneInfo(tz)
    for r in rows:
        for f in fields:
            v = r.get(f)
            if isinstance(v, datetime.datetime):
                r[f + "_local"] = v.astimezone(z).strftime("%Y-%m-%dT%H:%M")
    return rows


def rank(level: str | None) -> int:
    """The tree's order, as sp_level_rank() (db/007) numbers it: none 0, view 1, comment 2, edit_content 3, edit 4, full 5 — the tool only trims what the view showed."""
    return {"none": 0, "view": 1, "comment": 2, "edit_content": 3, "edit": 4, "full": 5}.get(level or "none", 0)


async def space_ref(fetch, value) -> int:
    """A space by id, slug or exact name — through mcp_spaces, so a space the caller may not see does not exist for them."""
    if value is None or value == "":
        raise BadArg("Name the space (its id, or its name).")
    if isinstance(value, int) or str(value).isdigit():
        rows = await fetch("SELECT space_id FROM mcp_spaces WHERE space_id = $1", int(value))
    else:
        v = str(value).strip()
        rows = await fetch("SELECT space_id FROM mcp_spaces WHERE lower(name) = lower($1) OR slug = lower($1) ORDER BY archived_at NULLS FIRST LIMIT 2", v)
        if len(rows) > 1:
            raise BadArg(f"More than one space is called '{v}'; give its id (find_spaces).")
    if not rows:
        raise BadArg("No space you can see matches that.")
    return int(rows[0]["space_id"])


async def channel_ref(fetch, value) -> int:
    """A channel by id or `#name` (a name must be unambiguous among the channels the caller may see)."""
    if value is None or value == "":
        raise BadArg("Name the channel (its id, or #name).")
    if isinstance(value, int) or str(value).isdigit():
        rows = await fetch("SELECT channel_id FROM mcp_channels WHERE channel_id = $1", int(value))
    else:
        v = str(value).strip().lstrip("#")
        rows = await fetch("SELECT channel_id FROM mcp_channels WHERE lower(name) = lower($1) AND kind IN ('public', 'private') ORDER BY archived_at NULLS FIRST LIMIT 2", v)
        if len(rows) > 1:
            raise BadArg(f"More than one channel is called #{v}; give its id (find_channels).")
    if not rows:
        raise BadArg("No channel you can see matches that.")
    return int(rows[0]["channel_id"])


async def member_ref(fetch, value) -> int:
    """A member (person or agent) by id or exact display name, among those the caller may see."""
    if value is None or value == "":
        raise BadArg("Name the member (their id, or their name).")
    if isinstance(value, int) or str(value).isdigit():
        rows = await fetch("SELECT member_id FROM mcp_members WHERE member_id = $1", int(value))
    else:
        rows = await fetch("SELECT member_id FROM mcp_members WHERE lower(display_name) = lower($1) LIMIT 2", str(value).strip())
        if len(rows) > 1:
            raise BadArg(f"More than one member is called '{value}'; give an id (find_members).")
    if not rows:
        raise BadArg("No member you can see matches that.")
    return int(rows[0]["member_id"])


# ---- rich text and property values -------------------------------------------------------------------------------------------------

def runs_plain(v) -> str:
    """The plain text of a stored rich-text array (a message body, a title, a rich_text property)."""
    if isinstance(v, list):
        return "".join(str(r.get("plain_text") or "") for r in v if isinstance(r, dict))
    return "" if v is None else str(v)


def is_runs(v) -> bool:
    return isinstance(v, list) and bool(v) and all(isinstance(r, dict) and "plain_text" in r for r in v)


def flat_value(v):
    """A resolved property value without the rich-text machinery: a run array becomes its plain text, a list of those the same, everything else is passed through."""
    if is_runs(v):
        return runs_plain(v)
    if isinstance(v, list):
        return [flat_value(x) for x in v]
    if isinstance(v, dict):
        return {k: flat_value(x) for k, x in v.items()}
    return v


def flat_props(props: dict | None) -> dict:
    return {k: flat_value(v) for k, v in (props or {}).items()}


async def page_gate(fetch, page: str, need: str = "view") -> dict:
    """The page's row as the caller sees it (mcp_pages), or a BadArg sentence; `need` is the least level the tool wants (view, comment, edit, full)."""
    rows = await fetch("SELECT page_id, space_id, kind, plain_title, icon, my_level, archived_at, is_template, parent_page_id, parent_database_id FROM mcp_pages WHERE page_id = $1::uuid", page)
    if not rows:
        raise BadArg("No page you can see has that id.")
    if rank(rows[0]["my_level"]) < rank(need):
        raise BadArg(f"That needs {need} access to the page; you have {rows[0]['my_level']}.")
    return rows[0]
