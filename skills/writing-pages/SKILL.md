---
name: writing-pages
description: How to write a good page in Spaces as an agent — structure, headings, callouts, when a database beats a list, which template to apply, how to cite a thread, what never goes on a page. Use when asked to draft, write, expand or tidy a page, or to turn a thread or a conversation into a page.
---

# Writing pages

A page is read by a person who looks before reading. Give them the shape first: a one-line lead, then headings, then short
sections. Markdown is your language; the editor turns it into blocks.

## Shape
1. **Lead**: one sentence saying what the page is and what it decides or holds. Not "This page describes…" — the thing itself.
2. **Headings** (`##`) for each part a reader would jump to. Three to six for most pages.
3. **Lists** for parallel things; **numbered** for steps in order; **to-dos** (`- [ ]`) for things someone will tick.
4. **A table** when items have the same attributes (name · owner · date · status). Five or more rows with several attributes →
   suggest a **database** instead (`database_create` with properties), so people can sort, filter and see it as a board.
5. **A callout** (`> [!NOTE] ⚠️ …`) for the one thing a reader must not miss. One per page, two at most.
6. **Code fences** with a language for anything typed into a machine.
7. **Links**: `[[Page title]]` for pages here; `@Name` for a person who owns something; a plain URL for the outside.

## Templates
When the page is a known kind, apply the template first (`find_templates`, `template_apply`) and fill it: Meeting notes,
Decision record, Project brief, Runbook, Weekly update, 1:1; and the database templates Vendor list, Decision log, Reading
list, Content calendar. A template's headings are the business's agreed shape — keep them.

## Citing a thread
When a page comes from a conversation, say so at the top: "Decided in #launch on 2026-10-03 (thread)" with the message
mentioned (the converter links it), the people who decided named with `@`. Quote the deciding sentence verbatim in a quote
block. A page that cites its thread can be verified by the people who were there.

## The wiki
In a wiki space, a page has an **owner** and a **verification date**. When you draft one, name who should own it and leave
verification to them — you do not verify pages you wrote. When you change a verified page, say in the page or the thread
what changed; the owner re-verifies.

## Never
- A secret, a token, a password, a private phone number or home address.
- A decision the business did not make ("we will…" when nobody said so). Write "proposed:" instead.
- A wall of prose where a table or a list would do; a heading with nothing under it.
- `@channel` in a page. Mentions in pages are for owners and people responsible, not for noise.

## Before you finish
Read the page back (`page_read`). Does the lead say what it is? Can a reader find the one thing they came for in ten seconds?
Is every link a real page? Then tell the person where it is and what they should check.
