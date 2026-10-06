"""spaces_records_mcp — Spaces' record-memory server (PostgreSQL, the mcp_* views and the sp_* read functions, read-only).

Tool surface: docs/spaces-mcp-tool-surface.md (spec: docs/build-specs/mcp-servers.md). Every tool reads as the asking member, so a person or an agent sees what the screens show them: the pages the permission tree admits, the
rows of a database they may see, the channels they are in or may read, never a message of a channel they are not in. The views and the functions decide; the server only trims. An agent sees only the tools the kernel granted it
(server_common). The kernel's own token reaches app_roles and the two shared tools (pages_index, page_markdown). Writes are never here: they are the kernel's Actions MCP, built from mcp/action_registry.json.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP

import db
from sp_common import RO, dumps

mcp = FastMCP("spaces_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


@mcp.tool(name="app_roles", annotations={"title": "Spaces' roles and rights", **RO})
async def app_roles() -> str:
    """The roles Spaces offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1, maludb-os-integration 0.4.x): Guest, Member, Space owner and Spaces admin, with
    the 21 rights. The catalogue is not about anyone, so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM sp_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


import sp_agents  # noqa: E402
import sp_channels  # noqa: E402
import sp_databases  # noqa: E402
import sp_misc  # noqa: E402
import sp_pages  # noqa: E402
import sp_search  # noqa: E402
import sp_shares  # noqa: E402
import sp_spaces  # noqa: E402

sp_spaces.register(mcp, fetch)
sp_pages.register(mcp, fetch)
sp_databases.register(mcp, fetch)
sp_channels.register(mcp, fetch)
sp_search.register(mcp, fetch)
sp_agents.register(mcp, fetch)
sp_misc.register(mcp, fetch)
sp_shares.register(mcp, fetch)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
