-- 005: the workspace's SETTINGS (one row) and the seeded VOCABULARY: emoji shortcodes (Unicode — custom emoji are Extended),
-- the rich-text colours, the block types, the property types and the view layouts as CHECK-able lists (design §0.3, D6, D8).
--
-- THE ESTATE'S DATA MODEL (design §6.1): sp_settings is the siblings' one-row `app_settings` skeleton (id smallint = 1,
-- updated_at) with this application's columns; the lists below are this application's.
BEGIN;

CREATE TABLE sp_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,                                             -- on public pages and exports
    default_space_kind          text NOT NULL DEFAULT 'closed' CHECK (default_space_kind IN ('open', 'closed', 'private')),
    default_member_level        text NOT NULL DEFAULT 'edit' CHECK (default_member_level IN ('view', 'comment', 'edit_content', 'edit', 'full')),   -- D5
    default_everyone_level      text NOT NULL DEFAULT 'view' CHECK (default_everyone_level IN ('none', 'view', 'comment', 'edit_content', 'edit')),  -- D5: open spaces
    version_snapshot_minutes    integer NOT NULL DEFAULT 10 CHECK (version_snapshot_minutes BETWEEN 1 AND 1440),
    version_retention_days      integer NOT NULL DEFAULT 90 CHECK (version_retention_days BETWEEN 1 AND 3650),
    trash_retention_days        integer NOT NULL DEFAULT 30 CHECK (trash_retention_days BETWEEN 1 AND 365),
    stale_page_days             integer NOT NULL DEFAULT 90 CHECK (stale_page_days BETWEEN 7 AND 3650),                 -- D12
    unanswered_hours            integer NOT NULL DEFAULT 24 CHECK (unanswered_hours BETWEEN 1 AND 720),                 -- D12
    wiki_default_verify_months  integer NOT NULL DEFAULT 6 CHECK (wiki_default_verify_months IN (1, 3, 6, 12)),         -- D12
    max_attachment_bytes        bigint NOT NULL DEFAULT 26214400 CHECK (max_attachment_bytes BETWEEN 1048576 AND 1073741824),   -- D14: 25 MB
    allowed_embed_hosts         text[] NOT NULL DEFAULT '{www.youtube.com,youtube.com,youtu.be,vimeo.com,player.vimeo.com,www.figma.com,codepen.io,gist.github.com,www.loom.com,docs.google.com}',
    public_pages_noindex        boolean NOT NULL DEFAULT true,                                                            -- D13
    public_base_url             text,                                                                                    -- SP_PUBLIC_BASE_URL may override
    digest_hour                 smallint NOT NULL DEFAULT 8 CHECK (digest_hour BETWEEN 0 AND 23),
    week_start_dow              smallint NOT NULL DEFAULT 1 CHECK (week_start_dow BETWEEN 0 AND 6),                     -- 1 = Monday
    timezone                    text NOT NULL DEFAULT 'UTC',
    group_dm_max_members        smallint NOT NULL DEFAULT 9 CHECK (group_dm_max_members BETWEEN 3 AND 50),               -- D9
    away_minutes                integer NOT NULL DEFAULT 15 CHECK (away_minutes BETWEEN 1 AND 1440),                     -- §8: a text only when away this long
    admin_channel_name          text NOT NULL DEFAULT 'spaces-admin',                                                    -- the Librarian's Monday note goes here
    agents_join_default_space   boolean NOT NULL DEFAULT true,                                                           -- D4: agents join General on hire
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO sp_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER sp_settings_touch BEFORE UPDATE ON sp_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION sp_setting_int(p_name text) RETURNS integer
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v integer;
BEGIN
    EXECUTE format('SELECT %I::integer FROM sp_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The vocabularies of design §0.3 as functions, so a CHECK and a converter agree in one place.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_block_types() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT ARRAY['paragraph','heading_1','heading_2','heading_3','bulleted_list_item','numbered_list_item','to_do','toggle','quote',
                 'callout','code','divider','image','video','audio','file','pdf','bookmark','embed','equation','table_of_contents',
                 'breadcrumb','column_list','column','synced_block','template','link_to_page','child_page','child_database','table',
                 'table_row','link_preview','unsupported'];
$$;
-- Types that may hold children (design §0.3): list items, to_do, toggle, quote, callout, paragraph, toggleable headings,
-- column, synced_block, template, table (rows only), child_page and child_database (their children are the page's own tree).
CREATE OR REPLACE FUNCTION sp_block_types_with_children() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT ARRAY['paragraph','heading_1','heading_2','heading_3','bulleted_list_item','numbered_list_item','to_do','toggle','quote',
                 'callout','column','column_list','synced_block','template','table'];
$$;
CREATE OR REPLACE FUNCTION sp_property_types() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT ARRAY['title','rich_text','number','select','multi_select','status','date','people','files','checkbox','url','email',
                 'phone_number','relation','rollup','created_time','created_by','last_edited_time','last_edited_by','unique_id','verification'];
$$;
CREATE OR REPLACE FUNCTION sp_view_layouts() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['table','board','gallery','list','calendar','timeline']; $$;
CREATE OR REPLACE FUNCTION sp_rollup_functions() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['count','sum','min','max','earliest_date','latest_date','percent_checked','show_original']; $$;
CREATE OR REPLACE FUNCTION sp_text_colors() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT ARRAY['default','gray','brown','orange','yellow','green','blue','purple','pink','red',
                 'gray_background','brown_background','orange_background','yellow_background','green_background','blue_background',
                 'purple_background','pink_background','red_background'];
$$;
CREATE OR REPLACE FUNCTION sp_permission_levels() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['none','view','comment','edit_content','edit','full']; $$;
-- The rank of a level: the tree resolves to the HIGHEST level any principal gives the caller.
CREATE OR REPLACE FUNCTION sp_level_rank(p_level text) RETURNS smallint
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_level WHEN 'none' THEN 0 WHEN 'view' THEN 1 WHEN 'comment' THEN 2 WHEN 'edit_content' THEN 3 WHEN 'edit' THEN 4 WHEN 'full' THEN 5 ELSE 0 END::smallint;
$$;

-- ---------------------------------------------------------------------------------------------
-- Rich text: the one format (design §0.3). A JSON ARRAY of runs. These two functions are the SQL half of the converter
-- (app/richtext/ is the PHP half): plain_text for the generated columns and the search index; a shape check for the CHECKs.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION sp_rich_text_plain(p jsonb) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(string_agg(
             COALESCE(r->>'plain_text',
                      CASE r->>'type'
                        WHEN 'text' THEN r->'text'->>'content'
                        WHEN 'equation' THEN r->'equation'->>'expression'
                        WHEN 'mention' THEN COALESCE(r->'mention'->>'plain_text', '@' || COALESCE(r->'mention'->>'name', r->'mention'->>'type'))
                        ELSE ''
                      END, ''), '' ORDER BY ord), '')
      FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p) = 'array' THEN p ELSE '[]'::jsonb END) WITH ORDINALITY AS t(r, ord);
$$;
CREATE OR REPLACE FUNCTION sp_is_rich_text(p jsonb) RETURNS boolean
    LANGUAGE sql IMMUTABLE AS $$
    SELECT p IS NOT NULL AND jsonb_typeof(p) = 'array'
       AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(p) r
                        WHERE jsonb_typeof(r) <> 'object' OR r->>'type' NOT IN ('text', 'mention', 'equation'));
$$;
-- A rich-text array mentions a principal of a kind (member, agent, department, channel, here, everyone, page, database, date, message).
CREATE OR REPLACE FUNCTION sp_rich_text_mentions(p jsonb, p_kind text) RETURNS bigint[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(array_agg(DISTINCT (r->'mention'->>'id')::bigint), '{}')
      FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p) = 'array' THEN p ELSE '[]'::jsonb END) r
     WHERE r->>'type' = 'mention' AND r->'mention'->>'type' = p_kind AND (r->'mention'->>'id') ~ '^[0-9]+$';
$$;
CREATE OR REPLACE FUNCTION sp_rich_text_has_mention(p jsonb, p_kind text) RETURNS boolean
    LANGUAGE sql IMMUTABLE AS $$
    SELECT EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p) = 'array' THEN p ELSE '[]'::jsonb END) r
                    WHERE r->>'type' = 'mention' AND r->'mention'->>'type' = p_kind);
$$;
-- A plain string as a one-run rich text (the seeds and the agents' simplest writes).
CREATE OR REPLACE FUNCTION sp_rich_text(p text) RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p IS NULL OR p = '' THEN '[]'::jsonb
                ELSE jsonb_build_array(jsonb_build_object('type', 'text', 'text', jsonb_build_object('content', p, 'link', NULL),
                        'annotations', jsonb_build_object('bold', false, 'italic', false, 'strikethrough', false, 'underline', false, 'code', false, 'color', 'default'),
                        'plain_text', p, 'href', NULL)) END;
$$;

-- ---------------------------------------------------------------------------------------------
-- Emoji: Slack's :shortcode: in the composer and the reaction picker (Unicode; custom emoji are Extended). Seeded with the
-- common set; a person may add more under settings.manage.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE emoji_shortcodes (
    shortcode   text PRIMARY KEY CHECK (shortcode ~ '^[a-z0-9_+-]{1,40}$'),
    emoji       text NOT NULL,
    keywords    text[] NOT NULL DEFAULT '{}',
    sort_order  integer NOT NULL DEFAULT 0
);
INSERT INTO emoji_shortcodes (shortcode, emoji, keywords, sort_order) VALUES
 ('+1','👍','{thumbs,up,yes,agree}',1), ('-1','👎','{thumbs,down,no}',2), ('heart','❤️','{love}',3), ('tada','🎉','{party,celebrate}',4),
 ('eyes','👀','{looking,watching}',5), ('white_check_mark','✅','{done,check,yes}',6), ('x','❌','{no,cross}',7), ('raised_hands','🙌','{hooray}',8),
 ('pray','🙏','{thanks,please}',9), ('clap','👏','{applause}',10), ('fire','🔥','{hot,lit}',11), ('rocket','🚀','{ship,launch}',12),
 ('thinking_face','🤔','{hmm,thinking}',13), ('joy','😂','{laugh,lol}',14), ('smile','😄','{happy}',15), ('slightly_smiling_face','🙂','{smile}',16),
 ('wink','😉','{}',17), ('sweat_smile','😅','{phew}',18), ('cry','😢','{sad}',19), ('sob','😭','{sad,crying}',20),
 ('angry','😠','{mad}',21), ('confused','😕','{}',22), ('neutral_face','😐','{meh}',23), ('exploding_head','🤯','{mind,blown}',24),
 ('wave','👋','{hello,hi,bye}',25), ('ok_hand','👌','{ok}',26), ('point_up','☝️','{}',27), ('muscle','💪','{strong}',28),
 ('100','💯','{hundred,perfect}',29), ('warning','⚠️','{careful}',30), ('question','❓','{}',31), ('exclamation','❗','{}',32),
 ('bulb','💡','{idea}',33), ('memo','📝','{note,write}',34), ('book','📖','{read,wiki}',35), ('calendar','📅','{date}',36),
 ('clock','🕐','{time}',37), ('hourglass','⏳','{waiting}',38), ('lock','🔒','{private}',39), ('unlock','🔓','{}',40),
 ('bell','🔔','{notify}',41), ('mega','📣','{announce}',42), ('speech_balloon','💬','{chat}',43), ('link','🔗','{}',44),
 ('pushpin','📌','{pin}',45), ('bookmark','🔖','{save}',46), ('star','⭐','{favorite}',47), ('sparkles','✨','{}',48),
 ('bug','🐛','{}',49), ('wrench','🔧','{fix}',50), ('hammer','🔨','{build}',51), ('gear','⚙️','{settings}',52),
 ('robot_face','🤖','{agent,bot,ai}',53), ('coffee','☕','{}',54), ('pizza','🍕','{}',55), ('beer','🍺','{}',56),
 ('sunny','☀️','{}',57), ('zap','⚡','{fast}',58), ('seedling','🌱','{new,grow}',59), ('house','🏠','{home}',60),
 ('office','🏢','{}',61), ('chart_with_upwards_trend','📈','{up}',62), ('chart_with_downwards_trend','📉','{down}',63), ('money_with_wings','💸','{}',64),
 ('dart','🎯','{goal,target}',65), ('trophy','🏆','{win}',66), ('medal','🏅','{}',67), ('checkered_flag','🏁','{finish}',68),
 ('see_no_evil','🙈','{oops}',69), ('shrug','🤷','{dunno}',70), ('facepalm','🤦','{}',71), ('handshake','🤝','{deal,agree}',72)
ON CONFLICT DO NOTHING;

GRANT SELECT, INSERT, UPDATE, DELETE ON sp_settings, emoji_shortcodes TO spaces_rw;
GRANT EXECUTE ON FUNCTION sp_setting_int(text), sp_block_types(), sp_block_types_with_children(), sp_property_types(), sp_view_layouts(),
    sp_rollup_functions(), sp_text_colors(), sp_permission_levels(), sp_level_rank(text), sp_rich_text_plain(jsonb), sp_is_rich_text(jsonb),
    sp_rich_text_mentions(jsonb, text), sp_rich_text_has_mention(jsonb, text), sp_rich_text(text)
    TO spaces_rw, spaces_records_ro, spaces_activity_ro;

COMMIT;
