# Build spec: databases and views (slice 5)

What exists at the end: a member makes a database in a space or inside a page, shapes its properties (every kept type; relations
two-way; rollups computed), looks at its rows through table, board, gallery, list, calendar and timeline views with filters, sorts
and groups, edits a cell in place, drags a card to another column, opens a row as a page with its properties in a panel, links rows
through relations, and embeds a linked view on any page. The rows are pages; the filter and the sort are the database's.
Schema: `databases`, `database_views`, `row_relations`, `unique_id_sequences`, `sp_databases_guard()`, `sp_rows_guard()`,
`sp_row_relations_guard()`, `sp_database_create()`, `sp_row_create()`, `sp_row_relation_set()`, `sp_database_property_save()`,
`sp_database_property_remove()` (db/009); `pages.properties`, `sp_page_trash()`, `sp_page_restore()`, `sp_position_between()` (db/007,
db/008); `sp_property_types()`, `sp_view_layouts()`, `sp_rollup_functions()` (db/005); `sp_database_rows()`, `sp_row_resolved()`,
`sp_row_matches()`, `sp_prop_key()`, `sp_prop_text()`, `sp_property_markdown()`, `sp_page_markdown()` (db/014); the views
`mcp_databases`, `mcp_database_views`, `mcp_row_relations`, `mcp_pages`, `mcp_members`, `mcp_attachments`, `mcp_activity_log` (db/017).
Never modify them. **The database is the referee**: one title property; a type we keep (formula, button and place refused in words);
a relation names a database and a rollup goes through a relation with a function we have; a row's value matches its type; a unique id
is numbered on insert; a dual relation is one link read from both sides; a removed property keeps the rows' values until purged —
the handlers call the function inside `sp_guard()` and show the `P0001` sentence as a 422; PHP never evaluates a filter: `sp_database_rows()` does.

## Screens (375 px first; the table scrolls horizontally inside its card; board, gallery and list are cards; a full-page form; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `database-list` | `/databases/?space=&q=` | cards (`mcp_databases`): icon, title, space, breadcrumb when inside a page, row count, property count, last edited; **New database** |
| `database-view` | `/databases/{id}?view=` | the view tabs (`mcp_database_views` in `position`); the active layout over `sp_database_rows(database, view)` paged 100 (**Load more**); the toolbar: **New row**, filter (the editor below), sort, group, properties (hide/show), **Views** (add, edit, reorder, delete), **Schema**; the **table** (a real `<table>`, one column per visible property, the title column fixed, a cell editable in place on tap — a form field posting `row_update` with one property; relations as `[[titles]]` opening the picker; rollups, created/edited and unique ids read-only `text-muted`); the **board** (a column per group value, cards with the title and the visible properties; SortableJS drag between columns posts `row_update` of the group property; "No <group>" column for empty); the **gallery** (cards with the cover — `card_cover` — and the visible properties, `card_size`); the **list** (one line per row); the **calendar** (a month grid by `calendar_by`, rows on their start date, a day's overflow "+n"; ◂ ▸ months); the **timeline** (week columns by `timeline_start`–`timeline_end`, a bar per row; ◂ ▸ weeks); at 375 px the table and the timeline scroll horizontally inside the card, the board scrolls columns horizontally, the calendar shows a week list instead of the grid |
| `database-schema` | `/databases/{id}/schema` | the properties as a table: name, key, type, options (a chip list), relation target and two-way, rollup source and function, prefix, format; **Add** (a form below: name, type, the type's fields), **Rename**, **Retype** (where lossless — below), **Remove** (confirm; "keep the values" or "purge them"); the title row has no Remove |
| `view-add` / `view-edit` | `/databases/{id}/views/new`, `/databases/{id}/views/{view}/edit` | the form: name, layout, the layout's needs (calendar: the date property; timeline: start and end; board: the group property — select, status or people), **filter** (the editor: rows of property · condition · value joined by and/or, nested one level; the conditions per type from `sp_row_matches()`'s list; it produces Notion's filter object as JSON in a hidden field), **sort** (up to three: property · direction), group and sub-group, visible properties (checkboxes), card size and cover, wrap, linked from (a page — a linked view) |
| `row-view` | `/databases/{id}/rows/{row}` | slice 2's `page-view` with a **properties panel** above the body: one line per property in schema order, each editable in place by its type (text, number, a select chip picker, a multi-select chip list, a status chip, a date picker with optional end, a people picker over `find_members`, files via slice 3's `file_upload`, a checkbox, url/email/phone text, a relation picker, rollups and computed ones read-only); the body is the reader (slice 2) or the editor (slice 3) |

## Databases and the schema
- **Create** (`database_create`): `space` → `member` of the space with `databases.write`; `parent` → `edit` on the page; `sp_database_create(space, parent, title, properties, inline)`;
  `template` → `sp_page_duplicate(template, …)` (a database template copies schema, views and template rows); `properties` as JSON keyed by name (the handler
  turns names into keys: lowercase, `_`); a Name title when none. Location `/databases/{uuid}`.
- **Update** (`database_update`): `edit`; title (`pages.title`), description, inline.
- **Schema save** (`database_schema_save`): `edit`; the whole `properties` object → one UPDATE on `databases` (the guard validates); confirmed; **other** for agents.
  **Property save** (`database_property_save`): `edit`; `sp_database_property_save(database, key, def)` where `def` is built from `type`, `options[]` (each `{name, color}`),
  `relation_database` + `two_way`, `rollup_relation` + `rollup_property` + `rollup_function`, `prefix`, `number_format`; `key` may be a name (`sp_prop_key()`).
- **Retype** (part of `database_property_save` with an existing key and a new `type`): **lossless only** — `number → rich_text`, `select → multi_select`, `url | email |
  phone_number → rich_text`, anything → `rich_text` (the value becomes text), `checkbox → select` (Yes/No options); `rich_text ↔ title` refused ("the title is the title"),
  `relation`, `rollup`, `unique_id`, `people`, `files`, `date` to anything else refused in words ("Due is a date; make a new property instead"); the handler converts each
  row's value in one transaction (`pages.properties`), the guard checks the shape.
- **Remove** (`database_property_remove`): `edit`; `sp_database_property_remove(database, key, purge_values)`; the title refused, a relation a rollup depends on refused (the
  function's words); **other** for agents.
- **Delete** (`database_delete`): `full`; `sp_page_trash(database)` — rows and views go to the trash with it; **deletion**.

## Rows
- **Create** (`row_create`): `edit_content`; `sp_row_create(database, properties, template)`; `title` into the title property; `properties` keyed by name or key (`sp_prop_key()`),
  values shaped by type (the form fields and the agents' JSON alike: a select by option name; people by member ids or names resolved with `find_members`; a date `{start, end}`);
  `markdown` → `block_append` (slice 3); a unique id numbered by the guard. Location `/databases/{uuid}/rows/{row}`.
- **Update** (`row_update`): `edit_content`; a partial update of `pages.properties` (`properties || changed`) — one cell save sends one property; the board's drag sends the
  group property; the title property changes the page title (the guard keeps them in step); a computed property (rollup, created_*, last_edited_*) in the POST is ignored
  by the guard, not refused.
- **Delete** (`row_delete`): `edit_content`; `sp_page_trash(row)`; **deletion**. Restore is slice 2's `page_restore`.
- **Relation set** (`row_relation_set`): `edit_content`; `sp_row_relation_set(row, property, targets[])` — the whole list; the picker searches `find_pages` within the
  target database; the dual side follows by trigger; a row of another database → the guard's words.
- **Rollups, created/edited, unique ids**: read-only everywhere; shown from `sp_row_resolved()` (`display`).

## Views
- **Save** (`view_save`): `edit`; a `database_views` row; `layout` from `sp_view_layouts()`; a calendar needs `calendar_by`, a timeline `timeline_start`, a board `group_by`
  (the CHECKs' words shown); `filter` is Notion's object (the editor builds it; an agent sends it); `sort` `[{property, direction}]`; `visible_properties[]`;
  `linked_from` makes a **linked view** (`linked_from_page_id`): slice 3's editor embeds it on a page as a `child_database` block pointing at the database with `view` in
  content — the page shows the view read-only through this slice's partial.
- **Delete** (`view_delete`): the last view of a database refused ("a database keeps one view"). **Reorder** (`view_reorder`): `position` by `sp_position_between`.
- **The table's inline edit** is Pattern B on `#database-rows`: a cell is a small form; its POST re-renders the row (`row-row` partial) and the group header when grouped.

## Files (exactly these)
- `html/databases/index.php` (`database-list`) · `view.php` (`database-view`) · `schema.php` (GET `database-schema`, POST `database_schema_save`) · `save.php` · `delete.php`
- `html/databases/properties/save.php` · `remove.php` · `html/databases/rows/view.php` (`row-view`) · `save.php` · `delete.php` · `relation.php`
- `html/databases/views/form.php` (`view-add`, `view-edit`) · `save.php` · `delete.php` · `reorder.php`
- `app/features/databases/{queries,present,write,handler,filters}.php` (`handler.php`: load the database or the row, `require_page_level()`, `database_log()` — `entity_uuid`, `space_id` on every row; `filters.php`: the filter editor's conditions per type and the form ↔ JSON mapping)
- `app/views/databases/{index,view,schema,views/form,rows/view,partials/database-card,partials/toolbar,partials/layout-table,partials/layout-board,partials/layout-gallery,partials/layout-list,partials/layout-calendar,partials/layout-timeline,partials/row-row,partials/row-card,partials/cell,partials/properties-panel,partials/filter-editor,partials/relation-picker}.php`

## Query functions (signatures fixed)
- `find_databases(PDO, array $filters, int $limit = 100): array` (`mcp_databases`; `space`, `q`) · `find_database(PDO, string $uuid): ?array` (`mcp_databases` + views + `my_level`) · `database_views(PDO, string $uuid): array` · `find_view(PDO, string $viewUuid): ?array`
- `database_rows(PDO, string $uuid, ?string $viewUuid, ?array $filter, ?array $sort, int $limit, int $offset): array` (`sp_database_rows()`; rows with `properties` resolved, `group_value`, `total`) · `find_row(PDO, string $rowUuid): ?array` (`mcp_pages` + `sp_row_resolved()`) · `row_relations(PDO, string $rowUuid, string $key): array`
- `create_database(PDO, ?int $spaceId, ?string $parentUuid, string $title, ?array $properties, bool $inline, ?string $templateUuid, int $by): string` · `update_database(PDO, string $uuid, array $fields, int $by): void` · `save_schema(PDO, string $uuid, array $properties, int $by): void` · `save_property(PDO, string $uuid, string $key, array $def, int $by): array` (the def written; `retyped` rows) · `remove_property(PDO, string $uuid, string $key, bool $purge, int $by): void` · `delete_database(PDO, string $uuid, int $by): void`
- `create_row(PDO, string $databaseUuid, array $properties, ?string $templateUuid, int $by): string` · `update_row(PDO, string $rowUuid, array $properties, int $by): array` (the changed keys) · `delete_row(PDO, string $rowUuid, int $by): void` · `set_row_relation(PDO, string $rowUuid, string $key, array $targets, int $by): void`
- `save_view(PDO, string $databaseUuid, ?string $viewUuid, array $fields, int $by): string` · `delete_view(PDO, string $viewUuid, int $by): void` · `reorder_view(PDO, string $viewUuid, ?string $afterUuid, int $by): void`
- `property_conditions(string $type): array` · `filter_from_form(array $post, array $schema): ?array` · `filter_to_form(?array $filter, array $schema): array` · `coerce_value(string $type, mixed $raw, array $def, PDO $pdo): mixed` (a select name → `{name}`, a person's name → id, a date string → `{start, end}`, a number string → number; a bad value → a field error in words)

## Handlers (every one: `sp_handler_begin()`; `require_page_level()`; `sp_guard()`; `log_activity` with `entity_uuid` and `space_id`; `sp_done()`; `HX-Trigger: databaseChanged` — rows `rowChanged`)
- `save.php` (`database_create` without `database`, `database_update` with): `database.create` (`after`: title, space_id, parent_page_id, inline, property_count, template) / `database.update` (`sp_diff()`).
- `schema.php` (POST `database_schema_save`): `database.schema_save` (`properties_added[]`, `properties_removed[]`, `properties_retyped[]`); **other**. `properties/save.php`: `database.property_save` (key, type, retyped_rows). `properties/remove.php`: `database.property_remove` (key, purge_values); **other**. `delete.php`: `database.delete` (row_count); **deletion**.
- `rows/save.php` (`row_create` / `row_update`): `row.create` (database_id, title, property keys) / `row.update` (the keys changed — never values). `rows/delete.php`: `row.delete`; **deletion**. `rows/relation.php`: `row.relation_set` (key, target_count).
- `views/save.php` / `delete.php` / `reorder.php`: `view.save` (name, layout, has_filter, sort_count, group_by) / `view.delete` / `view.reorder`.

## Action manifest entries
Screens: `database-list`, `database-view`, `database-schema`, `view-add`, `view-edit`, `row-view`.
Actions (13): `database_create`, `database_update`, `database_schema_save`, `database_property_save`, `database_property_remove`, `database_delete`, `row_create`, `row_update`, `row_delete`, `row_relation_set`, `view_save`, `view_delete`, `view_reorder`. Agent approvals: `database_schema_save`, `database_property_remove` (`other`); `database_delete`, `row_delete` (`deletion`). `PARTIAL_UPDATE_TARGETS` gains `/databases/save.php => ['pages', 'database', 'mcp_databases', 'database_id']` and `/databases/rows/save.php => ['pages', 'row', 'mcp_pages', 'page_id']` (UUID ids; a row's partial update is the properties merge above, so the prefill applies to title and the page fields only).

## Activity log events (every row: `entity_uuid` = the database, the row or the view; `space_id`)
`database.create|update|schema_save|property_save|property_remove|delete`, `row.create|update|delete|relation_set`, `view.save|delete|reorder`, `screen.view`. **No row carries a property value, a row's text or a filter's values** (keys, names, types, counts).

## Notifications this slice queues
| Step | Who is told | Kind |
|---|---|---|
| a `people` property names a member on a row (create or update) | the member ("Marco assigned you Build the board in Tasks") | `mention` |

## Status vocabulary
Property type chips `light` with the type name; a select/status option chip in its colour class (Notion's nine → `bg-*-subtle`); a checkbox ☑/☐; a rollup `text-muted` with a tooltip of its function; the title cell `fw-semibold` linking to the row. Ids: `database-list`, `database-card-{uuid}`, `database-toolbar`, `database-views`, `view-tab-{uuid}`, `database-rows`, `row-row-{uuid}`, `row-card-{uuid}`, `cell-{row}-{key}`, `board-column-{value}`, `calendar-day-{date}`, `timeline-row-{uuid}`, `schema-table`, `property-row-{key}`, `view-form`, `view-form-field-{name}`, `filter-editor`, `filter-row-{n}`, `properties-panel`, `property-field-{key}`, `relation-picker`.

## Out of scope for this slice
The row's body editor and file upload (3), CSV import and export (8 — `import_start` with a database target, `export_database`), charts and formulas (Extended), a public database's layout (slice 2's publish renders a table or gallery through this slice's partials, read-only).

## Proof (`tests/phase3/slice5/run.sh`: the scratch database `sp_dev5`, members through dev hand-offs, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
The world (`tests/phase3/slice5/lib.php` `databases_world()`): slice 2's world; in Product a "Tasks" database with Task (title), Notes, Points, Kind (select), Tags (multi-select), Status, Due, Owner (people), Files, Urgent (checkbox), Link, Mail, Phone, Created, Creator, Edited, Editor, Ref (TSK-); an "Epics" database with a two-way relation Tasks and rollups Points (sum), Done (percent checked), Count, Latest (latest date); three tasks, one epic.
- [ ] **Create and schema**: Marco creates Tasks with every property type (18); a formula → "Extended" in words; a second title → the guard's words; Priya (edit) renames Points to Effort, retypes `number → rich_text` (the values become text), `select → multi_select` (one option becomes a list), `date → text` refused in words, `rich_text → title` refused; removes Tags keeping the values (the rows still carry them; the schema does not) then purges; Dana (comment) → 403; `database.schema_save` logs the keys, never values; the schema page lists each property with its chips.
- [ ] **Rows**: a row by the form with every type (a select by name, people by picker, a date with an end); its Ref TSK-4; a bad number → a field error in words; an unknown option → in words; the title cell changes the page title; a cell edit in the table re-renders the row only; `row.update` logs the key changed, never the value; a computed property in the POST ignored; the row page shows the panel in schema order with rollups read-only; a row trashed and restored.
- [ ] **Relations and rollups**: Epic Launch related to two tasks through the picker (`find_pages` within Tasks); both sides show it; Points sums 11, Done 50.0%, Count 2, Latest the later date; removing a target drops both sides; a task of another database refused; a rollup through a non-relation refused in the function's words.
- [ ] **Views**: Table, a Board by Status (cards in Todo and Done, a drag moves a card and posts `row_update` of Status, the column headers count), a Gallery with the cover, a List, a Calendar by Due (the two dated rows on their days, the undated absent, ◂ ▸ months), a Timeline by Due–Due; a filter built in the editor (Status is Todo and Points > 4 → one row) stored as Notion's object and applied by `sp_database_rows()`; a sort by Points desc then Task; group by Kind with sub-group Status; hidden properties absent from the table; views reordered; the last view cannot be deleted (in words); a linked view on the handbook page lists the same rows read-only; a calendar without a date property → the CHECK's words.
- [ ] **Who sees**: Dana (comment on the handbook subtree) reads the rows and cannot edit a cell (403 in words); Ann (guest, nothing shared) → 404; the admin sees all; `mcp_databases` and `mcp_database_views` answer each exactly what the screen shows.
- [ ] **JSON mode**: every handler under a signed action token answers the contract; `row_create` with `properties` keyed by display names and a person by name; `database_query`'s filter object round-trips through `view_save`; `_partial=1` on `database_update` keeps the description; the expert (run token + relay) creates a row and sets a relation, `source agent`; its `database_delete` pauses `deletion` on the MCP path (Phase 4).
- [ ] **375 × 740 and 1280 × 800**: the table scrolls inside its card (`scrollWidth` of the page = viewport), the board's columns scroll horizontally, the calendar becomes the week list, the gallery and list stack; the inline cell editor and the chip pickers are popovers; every control ≥ 44 px, no console errors; JavaScript off saves a cell through its form; the registry reads 6 screens and 13 actions built.

## Built and proven (2026-10-05)
Built as the Files list says, plus the pieces the screens needed. `app/features/databases/{queries,present,write,handler,filters,render}.php` (`DATABASE_SELECT` over `mcp_databases` joined to `mcp_pages` for the level and
the editor; `database_cast()` orders the schema; `database_rows()` is `sp_database_rows()` decoded; `coerce_value()` / `coerce_row_values()` turn a form field, an agent's JSON value or a name into a stored value and
file each refusal under `p[key]`; `save_property()` converts every row in the retype's transaction; `render_layout()` is the one place the six layouts are chosen, for the screen and for a linked view;
`display_value()` / `render_value()` show a value as text / HTML), 14 controllers under `html/databases/` (`index`, `view`, `schema`, `save`, `delete`, `properties/{save,remove}`, `rows/{view,save,delete,relation}`,
`views/{form,save,delete,reorder}`), the views with their partials (`field`, `cell`, `row-row`, `row-card`, the six `layout-*`, `toolbar`, `properties-panel`, `filter-editor`, `relation-picker`, `database-card`),
`html/assets/js/databases.js`, `databases.css`; `app/features/pages/screen.php` (slice 2's page screen as a function, shared by `page-view` and `row-view`); `html/pages/view.php` sends a browser asked for a database or a
row to its own page (JSON still answers as a page); `html/files/upload.php` learned `row` + `property` (an attachment of kind `row_files`, its id appended to the files property); the vhost, the dev router and the
registry builder learned `/databases/{id}/views/new`, `/views/{view}/edit` and `/rows/{row}`; `PARTIAL_UPDATE_TARGETS` gained both entries and **the prefill now skips the jsonb columns `title` and `properties`** (a copied
JSON string would have been read as new text); the shell menu gained **Databases** (the list needed a way in).
**Decisions taken in the build (the spec was silent or the schema forbade the literal reading):**
- **`db/019_database_duplicate.sql`** — `sp_page_duplicate()` made a page of kind `database` with no `databases` row, so a database template (or Duplicate) came out broken. It now copies the schema (a relation or rollup is
  left out: it points into another database), the database's own views and its live rows with their blocks (unique ids renumbered). Slice 2's `template_apply` of a database template is fixed with it.
- **A linked view is a `link_to_page` block carrying `database_id` and `view`, not a `child_database` block**: db/008's edge trigger re-parents the database under the page that holds a `child_database` block and
  `sp_page_duplicate` would deep-copy it. See Open questions.
- **Property order**: jsonb keeps keys by length and name, so each property carries `order` (set when added; a seeded or dual one follows); `schema_ordered()` sorts everywhere, the title first.
- **Rename** is `database_property_save` with a new optional **`name`** (manifest and registry updated); keys are the lowercase slug of the name (`Related to …` for a two-way mirror, written by the database).
- Values: a select/status is `{name, color}`, a multi-select a list of them, people member ids (by id or by name), a date `{start, end}`, files attachment ids; a unique id, a rollup, a relation and the created/edited
  ones in a POST are ignored. A row may be untitled. A retype is lossless only: number, select, multi-select, status, url, email, phone and checkbox may become text; select may become multi-select; checkbox may become
  select (Yes/No); date, people, files, relation, rollup and unique id never change type (the spec's own sentence), the title never.
- Rows' handlers refuse in "You may not edit the rows of this database."; a trashed row is restored with `page_restore` (needs `full`, slice 2's rule).
- The filter editor posts `f[n]`, `fj` and `g[i]` (the server builds Notion's object); the hidden `filter` keeps the JSON for what it cannot draw; an agent's `filter` is validated for shape and property names and kept as sent.
- A board groups by select, status or people only (the sentence says so); a view's CHECKs are shown in words by constraint name.
- The calendar draws a month grid (≥ 576 px) and a week list (below); the timeline 8 weeks, bars as a percentage of the window; Load more raises `?limit=` by 100.
**Proven by `tests/phase3/slice5/run.sh` — CREATE, ROWS, RELATIONS, VIEWS, ACCESS, JSON, BROWSER (367 checks green (create 49, rows 50, relations 33, views 86, access 52, json 38, browser 59); Phase 2 (312), slices 1, 2, 3 and 4 re-run green).** Every box of the checklist has at least one `ok()` line.

## Open questions
- (Decided by the planning model 2026-10-05, for the owner to overrule.) **Linked views stay `link_to_page` blocks carrying `database_id` and `view`**: a `child_database` block is the tree's edge by db/008 (it moves the database under the page) and `sp_page_duplicate` copies the database it names, so an embedded view that is not the database's home cannot be one without a migration that gives `child_database` a second meaning. The spec's wording is corrected by this note; the converter and the renderer treat a `link_to_page` with a `view` as the embedded view.
