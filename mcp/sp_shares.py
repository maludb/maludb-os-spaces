"""The two shares[] of maludb-os.json (design §8, K7): pages_index and page_markdown — the kernel's own token reaches them (and app_roles), and so does any admitted person or agent for what they may read. ONE truth, two doors: the
tools do not rebuild the documents, they ask the application's internal door (POST /api/v1/shares/read.php on APP_INTERNAL_PORT, signed with ACTIONS_RELAY_KEY) to call the functions in app/features/shares/queries.php. A kernel call
runs as the requesting application's expert agent, which the consumer names in `as_agent` (the kernel passes it no identity); a person's or agent's own call reads as themselves. The kernel's call logs `share.read` there."""
from __future__ import annotations

import hashlib
import hmac
import json
import time

import httpx
from mcp.server.fastmcp.exceptions import ToolError
from pydantic import Field

import db
from sp_common import RO, _Share

SHARES = ("pages_index", "page_markdown")


class PagesIndexIn(_Share):
    q: str | None = Field(None, description="Words of a page's title")
    cursor: str | None = Field(None, description="The next_cursor of the previous page")
    limit: int = Field(25, ge=1, le=100)
    as_agent: int | None = Field(None, description="The kernel's call only: the member id of the requesting application's expert agent, which the share runs as")


class PageMarkdownIn(_Share):
    page: str = Field(..., description="The page's id (a UUID)")
    as_agent: int | None = Field(None, description="The kernel's call only: the member id of the requesting application's expert agent")


async def relay(tool: str, arguments: dict) -> str:
    """The document, exactly as the PHP function built it (a JSON string), or a ToolError in its sentence."""
    key = db.ENV.get("ACTIONS_RELAY_KEY", "")
    port = db.ENV.get("APP_INTERNAL_PORT", "")
    if len(key) < 32 or not port:
        raise ToolError("The shared tools are not configured (ACTIONS_RELAY_KEY and APP_INTERNAL_PORT).")
    kernel = db.request_is_kernel.get()
    member = db.request_member_id.get()
    if not kernel and member is None:
        raise ToolError("Who is asking? No member is behind this call.")
    args = {k: v for k, v in arguments.items() if v is not None}
    if not kernel:
        args.pop("as_agent", None)                      # a person or an agent reads as themselves, never as another
    body = json.dumps({"tool": tool, "caller": "kernel" if kernel else "person", "member_id": None if kernel else member, "arguments": args}, separators=(",", ":")).encode()
    ts = str(int(time.time()))
    sig = hmac.new(key.encode(), f"share:{ts}:{hashlib.sha256(body).hexdigest()}".encode(), hashlib.sha256).hexdigest()
    try:
        async with httpx.AsyncClient(timeout=30) as client:
            r = await client.post(f"http://127.0.0.1:{port}/api/v1/shares/read.php", content=body,
                                  headers={"Content-Type": "application/json", "X-Share-Time": ts, "X-Share-Signature": sig})
    except httpx.HTTPError as ex:
        raise ToolError("The application's own door could not be reached; nothing was shared.") from ex
    if r.status_code == 200:
        return r.text
    try:
        message = str(r.json().get("error") or "")
    except ValueError:
        message = ""
    raise ToolError(message or f"The document could not be built ({r.status_code}).")


def register(mcp, fetch) -> None:
    @mcp.tool(name="pages_index", annotations={"title": "Pages index (share)", **RO})
    async def pages_index(params: PagesIndexIn) -> str:
        """A shared tool (Help Desk, Projects, Consultant Tracking — the document os.spaces-pages/1): the pages the requesting application's expert agent may see — page_id, title, kind, space, path (the breadcrumb), last_edited_at,
        verification_state — newest edit first; `q` searches titles; paged by cursor. Nothing of the body, nobody's id. The kernel's token over an approved connection (name the agent in as_agent; none named reads nothing), or a person or agent for what they may see."""
        given = {k: getattr(params, k) for k in params.model_fields_set}          # only what the caller gave: the log records the argument KEYS
        return await relay("pages_index", given)

    @mcp.tool(name="page_markdown", annotations={"title": "A page as Markdown (share)", **RO})
    async def page_markdown(params: PageMarkdownIn) -> str:
        """A shared tool (the document os.spaces-page/1): one page as Markdown (at most 200 kB) with its properties as text, its breadcrumb, last edit and verification, as the requesting application's expert agent may see it. The kernel's token
        (as_agent names the agent), or a person or agent for what they may read."""
        return await relay("page_markdown", {k: getattr(params, k) for k in params.model_fields_set})
