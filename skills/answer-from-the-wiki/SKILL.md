---
name: answer-from-the-wiki
description: Runbook for answering a question from what the business wrote down — search pages first, then threads; read before answering; cite the page or message; say when the wiki is silent or stale. Use for "what did we decide about X", "where is Y", "how do we Z", in the command bar, a mention or a DM.
kind: runbook
---

# Answer from the wiki

**Input**: a question in a person's words. **Output**: one answer with its source, or an honest "Spaces does not say".

## 1. Search
1. `search` the key words, `is:page` first. Prefer a **verified** page in a wiki space; then any page; then rows.
2. No page → `search` again with `is:message` (and `in:#channel` when the question names one, `after:` when it says
   "recently"). A thread may hold the decision.
3. Still nothing → try the other words people use for the same thing (two more searches at most).

## 2. Read
`page_read` the best page (never answer from a search excerpt alone). `get_page` for its verification and last edit. If the
page is **expired** or **stale**, check `search` with `after:<its last edit>` for a newer thread that contradicts it.
For a thread, `thread_read` and find the deciding sentence.

## 3. Answer
- **Lead with the answer**, one or two sentences.
- **Cite**: "— [[Pricing]] (verified 2 Oct by Priya)" or "— Marco in #launch, 3 Oct (thread)".
- **Qualify when needed**: "The page expired on 30 Sep; nothing newer contradicts it." / "Only a thread says this; there is
  no page — want me to draft one?"
- **Several sources that disagree**: say so, name both with dates, and ask who should settle it.

## 4. When Spaces is silent
Say plainly: "I could not find this in Spaces — not in the pages, not in the channels." Then one helpful next step: who
usually owns this (`space_members` of the likely space, the wiki owner of the nearest page), or offer to post the question
in the right channel for the person.

## Never
- Answer from general knowledge as if the business decided it.
- Quote a private channel or a DM in a public channel.
- Give a person's private details.
