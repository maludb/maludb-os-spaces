# The record picker (2026-10-09)

The owner's decision (builder plugin 0.8.0, `design-system/references/record-picker.md`; the plan in the plugin's
`docs/record-picker-plan.md`): a form chooses a **record** — a person, a page, a space, anything people add to without limit —
through one searchable modal, never a `<select>` that lists every row. ProcessCore's purchase order was the exemplar, the cidery
the first retrofit (its `docs/18-record-picker.md`); Spaces is the second, mechanical from the reference. The picker is the one
exemption to Spaces' no-modal rule: it only chooses and closes, shows no record and holds no form.

## What was added
- `app/picker.php` (`picker_field()`, `picker_label()`, `picker_source()`, `picker_result_from_list()`), required from the bootstrap;
  `app/pickers.php`, the registry; `html/pick/index.php`, `GET /pick/`; `app/views/shared/picker-rows.php`; `html/assets/js/record-picker.js`;
  the RECORD PICKER section of `app-overrides.css`; the one `#record-picker` modal in `layout.php` after `</main>` (outside `#page-content`
  and the right pane, so swaps keep it) and the script after `common-init.min.js`. The shell has no unsaved-changes guard, so none is touched.
- Spaces writes its form rows by hand: each field calls `picker_field()` inside the existing label markup, and the label's `for` points at
  `{id}-open`. The hidden input keeps each select's **name and id**, so no handler, `hx-include`, manifest row or MCP tool changed; the filter
  bars (`hx-trigger="change, submit, …"` on the form) still reload on a pick because the hidden input's `change` bubbles to the form.
- `find_members_for_pick()` in `app/handler.php` (the directory list `members_for_pick()` with a search, a space to leave out and a SQL page).

## Differences from the reference (Spaces' keys and rules)
- **Two kinds of key.** A space, a member and a channel are integers; a page and a database are UUIDs. A source's `params` is
  `name => 'int'|'uuid'` (the reference has a list of integer names), the endpoint reads `selected` as digits or a UUID, and `label()`
  receives an int or a lower-case UUID string.
- **`gate` instead of `roles`.** Spaces has rights and a permission tree, not roles. Each source's `gate(array $params): ?string` is the
  authorization the screens that use the field apply (null = allowed, else the refusal's words). The endpoint requires a signed-in
  person (an agent is refused — it uses the tools), answers every refusal as a fragment (401, 403, 404, 405), never a redirect to the launcher.
- **Visibility is the database's.** Spaces, pages and databases come from the `mcp_*` views, so what the caller may SEE is the permission
  tree's answer. The gates add only what the screen itself asked for: the `member` source needs the space's owner (`space`) or a full page
  (`page`) and refuses a guest; `channel_member` / `channel_guest` need the channel's add right; `guest` needs `share.guest` and a full page.
- **Membership wall on pages.** The `page` source takes `space` as an integer param and lists nothing of a space the caller does not belong
  to; with `editable=1` (a page that something goes UNDER) it lists only pages of spaces the caller belongs to, or their own private pages —
  the old select also listed pages of an open space where "everyone may edit" without membership; the picker does not.
- **Short lists are filtered in PHP.** Spaces, a channel's candidates, and the pages (the feature's `find_pages()`, at most 500 by
  recency) come back whole from the feature's own query and `picker_result_from_list()` filters and slices them; only the member list pages in SQL.

## Sources (`app/pickers.php`)
`member` (space | page), `channel_member`, `channel_guest` (channel), `guest` (page), `space`, `space_member`, `space_page`, `space_export`,
`space_import`, `page` (space, editable, pages_only, except), `database` (editable), `channel_export`.

## Converted (field — screen — source)
| Field | Screen | Source |
|---|---|---|
| member | `/spaces/{id}/members` add | `member` space={id} |
| member, guest | `/channels/{id}/members` | `channel_member`, `channel_guest` channel={id} |
| member, guest | `/pages/{id}/share` | `member` page={id}, `guest` page={id} |
| space, parent | `/pages/new` | `space_page`, `page` editable |
| space, parent | `/pages/{id}/move` | `space_page`, `page` editable except={id} |
| space | `/channels/new` | `space_member` (begins on the first space, as the select did) |
| page, page | `/channels/{id}/pins` (bookmark, pin) | `page` |
| space, parent | `/databases/` new | `space_page`, `page` editable pages_only |
| linked_from | `/databases/{id}/views/new` | `page` editable pages_only |
| relation_database | `/databases/{id}/schema` | `database` |
| page, database, space, channel | `/exports/` | `page` pages_only, `database`, `space_export`, `channel_export` |
| space, database | `/import` | `space_import`, `database` editable |
| space (filter) | `/pages/`, `/databases/` | `space` |
| space (filter) | `/channels/` | `space_member` |

## Still selects, by the rule (D7)
Departments, roles/levels, kinds, formats, layouts, section, template (a short list of copies), property and rollup selects (a database's
own schema), number formats, emoji; the search page's filters (`space:`, `in:`, `from:`, `@me` are the query language's words, not ids);
the admin trash's space filter (the spaces that HAVE trash — a derived list); the admin dispatches' agent filter (a handful, auto-submits);
the proposal's "where the draft goes" (one list of two kinds of record, `space:N` or a page). Owed if wanted: a second pass on those two.

## Proven
`tests/picker/run.sh` on a scratch database (`sp_picker`; the harness of the slice proofs: a fake kernel, a fake MaluDB): `endpoint.php` (the
refusals, the gates, search, escaping and the LIKE wildcards, paging past 25 for spaces, pages and members, every param, the membership
walls), `forms.php` (every converted form renders its picker under the old name and id, the first render keeps the chosen label, an
unseen id shows the placeholder, what stays a select), `saves.php` (each form fetched, parsed and submitted as a browser does, with the
id the picker's own rows carry), `browser.mjs` (headless Chromium: open, focus, search, Show more, keyboard, Clear, a filter bar that
reloads on a pick, a pick after an HTMX navigation, 375 px, dark mode). The slice proofs that touch these forms were re-run; the `.modal`
checks of slices 1 and 2 now exclude `#record-picker`.
