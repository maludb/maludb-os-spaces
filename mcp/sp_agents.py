"""Agents (G1-G3): agents_here, agent_dispatches, librarian_proposals. An agent is a member: it appears here as the people do, with the rooms it is in and its last reply."""
from __future__ import annotations

from pydantic import Field

from sp_common import RO, Ref, BadArg, Where, _Base, channel_ref, contains_words, dumps, err, lim, member_ref, must_be_member, plain, q_alone


class LimitIn(_Base):
    limit: int = Field(25, ge=1, le=100)


class DispatchesIn(_Base):
    status: str | None = Field(None, description="pending, running, answered, failed or one of the other states a dispatch passes through")
    agent: Ref | None = Field(None, description="An agent (member id or name)")
    channel: Ref | None = Field(None, description="A channel (id or #name)")
    limit: int = Field(25, ge=1, le=100)


class ProposalsIn(_Base):
    q: str | None = Field(None, description="Words of a proposal's title. `q` alone resolves one: a plain list (proposal_id, title)")
    status: str | None = Field(None, description="proposed, accepted or dismissed")
    kind: str | None = Field(None, description="The proposal's kind (for example thread_to_page)")
    limit: int = Field(25, ge=1, le=100)


def register(mcp, fetch) -> None:
    @mcp.tool(name="agents_here", annotations={"title": "The agents that are members", **RO})
    async def agents_here(params: LimitIn | None = None) -> str:
        """The agents that are members here: the spaces and channels each is in, its last reply, and how many dispatches (a mention or a direct message waiting for an answer) are pending."""
        if (no := await must_be_member(fetch)) is not None:
            return err(no)
        n = lim(params.limit if params else 25)
        rows = await fetch(f"""SELECT m.member_id, m.display_name, m.job_title, m.is_active_now, m.last_seen_at,
                                      COALESCE((SELECT jsonb_agg(jsonb_build_object('space_id', sm.space_id, 'name', s.name) ORDER BY s.name) FROM mcp_space_members sm JOIN mcp_spaces s ON s.space_id = sm.space_id WHERE sm.member_id = m.member_id), '[]'::jsonb) AS spaces,
                                      COALESCE((SELECT jsonb_agg(jsonb_build_object('channel_id', cm.channel_id, 'name', c.name) ORDER BY c.name) FROM mcp_channel_members cm JOIN mcp_channels c ON c.channel_id = cm.channel_id WHERE cm.member_id = m.member_id AND c.kind IN ('public', 'private')), '[]'::jsonb) AS channels,
                                      (SELECT max(d.answered_at) FROM mcp_agent_dispatches d WHERE d.agent_member_id = m.member_id) AS last_reply_at,
                                      (SELECT count(*) FROM mcp_agent_dispatches d WHERE d.agent_member_id = m.member_id AND d.status IN ('pending', 'running')) AS pending_dispatches
                                 FROM mcp_members m WHERE m.is_agent ORDER BY lower(m.display_name) LIMIT {n}""")
        return dumps(rows)

    @mcp.tool(name="agent_dispatches", annotations={"title": "Dispatches to agents", **RO})
    async def agent_dispatches(params: DispatchesIn) -> str:
        """Dispatches to agents — a mention or a direct message turned into one chat turn — pending, running, answered or failed, each with the message, who asked, the run and the reply's excerpt. The admin sees all; a member the ones they caused or may read."""
        w = Where()
        try:
            if params.agent:
                w.add("d.agent_member_id = {}", await member_ref(fetch, params.agent))
            if params.channel:
                w.add("d.channel_id = {}", await channel_ref(fetch, params.channel))
        except BadArg as ex:
            return err(str(ex))
        if params.status:
            w.add("d.status = {}", params.status)
        rows = await fetch(f"""SELECT d.dispatch_id, d.record_type, d.record_id, d.agent_member_id, d.agent_name, d.kind, d.via, d.acting_member_id, a.display_name AS asked_by, d.run_id, d.request_id, d.status, d.reply_excerpt, d.detail, d.attempts,
                                      d.created_at, d.answered_at, d.channel_id, d.reply_message_id
                                 FROM mcp_agent_dispatches d LEFT JOIN mcp_members a ON a.member_id = d.acting_member_id WHERE {w.sql()} ORDER BY d.created_at DESC LIMIT {lim(params.limit)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="librarian_proposals", annotations={"title": "The Librarian's proposals", **RO})
    async def librarian_proposals(params: ProposalsIn) -> str:
        """The Librarian's proposals — make this thread a page, verify this page — and their fate: proposed, accepted (with the page it became) or dismissed (with the note). `q` alone resolves a proposal by title (a plain list of proposal_id and title)."""
        if (no := await must_be_member(fetch)) is not None:
            return err(no)
        w = Where()
        if params.status:
            w.add("l.status = {}", params.status)
        if params.kind:
            w.add("l.kind = {}", params.kind)
        contains_words(w, params.q, "l.title")
        rows = await fetch(f"""SELECT l.proposal_id, l.kind, l.title, l.reason, l.status, l.subject_page_id, l.subject_message_id, l.subject_channel_id, l.proposed_page_id, l.proposed_by, l.decided_by, l.decided_at, l.decision_note, l.created_at
                                 FROM mcp_librarian_proposals l WHERE {w.sql()} ORDER BY l.created_at DESC LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "proposal_id", "title")
        return dumps(rows)
