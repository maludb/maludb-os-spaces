-- 004: Spaces' roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- sp_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose: the installer's
-- --grant-standing-departments gives the standing departments the role keyed member/user/write, and EVERYONE in the business
-- belongs in Spaces (D3) — so the switch is right here.
--
-- Rights are application-wide capabilities; WHO SEES WHICH PAGE OR CHANNEL is the permission tree (db/007, db/010): a space's
-- members, a page's explicit principals, a channel's kind. `space_owner` is the capability to own spaces one did not create;
-- owning a given space is space_members.role = 'owner' (db/006). A Spaces admin sees every space, private ones included —
-- logged `space.admin_view` (D2). A guest holds `spaces.guest` and nothing else: they reach what is explicitly shared.
--
-- THE ESTATE'S DATA MODEL (design §6.1): the shape is Consultant Tracking db/004 (ct_ → sp_); the roles and rights are this
-- application's.
BEGIN;

CREATE TABLE sp_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE sp_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX sp_roles_one_admin ON sp_roles (is_admin) WHERE is_admin;
CREATE TABLE sp_role_rights (
    role_key  text NOT NULL REFERENCES sp_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES sp_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO sp_rights (right_key, description, sort_order) VALUES
 ('spaces.guest',      'Reach only the pages and channels explicitly shared with them; write where the share says edit or comment', 1),
 ('spaces.join',       'Join open spaces; request to join closed ones', 2),
 ('pages.write',       'Create and edit pages where the tree allows (the space''s member level, an explicit permission)', 3),
 ('pages.private',     'Keep private pages of their own, and share them', 4),
 ('databases.write',   'Create databases, change their schema and views where they may edit the page', 5),
 ('channels.write',    'Post, reply, react, pin, save and schedule in channels they are a member of', 6),
 ('channels.create',   'Create channels — public or private — in the spaces they are in', 7),
 ('dm.write',          'Open and write direct and group messages', 8),
 ('share.member',      'Share a page they fully control with a member or a department', 9),
 ('files.write',       'Attach files and images to blocks, messages and comments', 10),
 ('export.own',        'Export a page they can see, as Markdown', 11),
 ('space.manage',      'Own spaces: settings, kind, members, channels, templates, the wiki, the root permission', 12),
 ('share.guest',       'Share a page or a channel to a guest (pauses for an agent)', 13),
 ('channel.manage',    'Archive channels, set their retention, pin pages in them', 14),
 ('export.space',      'Export a whole space or channel they own', 15),
 ('settings.manage',   'Run Spaces: the workspace settings, templates, published pages, retention, every space', 16),
 ('publish.web',       'Publish a page to the public web (pauses for an agent)', 17),
 ('retention.manage',  'Set retention on any channel, version and trash retention', 18),
 ('trash.purge',       'Empty the trash before its time', 19),
 ('export.all',        'Export everything', 20),
 ('agents.settings',   'See every agent''s dispatches and the Librarian''s proposals; link to the kernel for hires and grants', 21);

INSERT INTO sp_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('guest',       'Guest',        'An outsider (a kernel member flagged external): sees nothing by default; reaches only pages and channels shared with them explicitly.', 'read',  false, 1),
 ('user',        'Member',       'Everyone inside the business: reads and writes in the spaces they belong to, keeps private pages, talks in channels and DMs.', 'write', false, 2),
 ('space_owner', 'Space owner',  'A Member who may own spaces they did not create: settings, members, channels, the wiki, sharing to guests.', 'write', false, 3),
 ('admin',       'Spaces admin', 'Runs Spaces: workspace settings, every space (private ones logged), retention, exports, published pages, the agents'' settings. A super-admin holds this role.', 'admin', true, 4);

INSERT INTO sp_role_rights (role_key, right_key)
SELECT 'guest', 'spaces.guest'
UNION ALL SELECT 'user', r FROM unnest(ARRAY['spaces.join','pages.write','pages.private','databases.write','channels.write','channels.create',
                                              'dm.write','share.member','files.write','export.own']) r
UNION ALL SELECT 'space_owner', r FROM unnest(ARRAY['spaces.join','pages.write','pages.private','databases.write','channels.write','channels.create',
                                                     'dm.write','share.member','files.write','export.own',
                                                     'space.manage','share.guest','channel.manage','export.space']) r
UNION ALL SELECT 'admin', right_key FROM sp_rights WHERE right_key <> 'spaces.guest';

-- The rule: a right the member's roles give. The member must be active and admitted. A member the kernel has not yet
-- sent roles for (roles = '{}') is read from the capability: admin = admin, read = guest, anything else = user. A super-admin
-- holds every right but spaces.guest (the kernel lists the admin role for them; this is the belt to that brace).
CREATE OR REPLACE FUNCTION sp_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM members m
          JOIN sp_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (m.roles)
                    OR (cardinality(m.roles) = 0 AND rr.role_key = CASE m.capability WHEN 'admin' THEN 'admin' WHEN 'read' THEN 'guest' ELSE 'user' END)
                    OR (m.member_kind = 'human' AND m.business_role = 'super_admin' AND rr.role_key = 'admin'))
         WHERE m.id = app_current_member_id() AND m.status = 'active' AND m.capability IS NOT NULL);
END$$;

-- Runs Spaces: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION sp_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND sp_has_right('settings.manage'));
END$$;

-- A guest: admitted, external, and holding no role above Guest (the people picker, the sidebar and every default deny them).
CREATE OR REPLACE FUNCTION sp_is_guest() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = app_current_member_id() AND m.status = 'active' AND m.capability IS NOT NULL
                     AND m.is_external
                     AND NOT (m.roles && ARRAY['user', 'space_owner', 'admin'])
                     AND NOT (cardinality(m.roles) = 0 AND m.capability IN ('write', 'admin')));
END$$;

-- Someone who belongs in the workspace: admitted and not a guest (a Member, a Space owner, an admin, an agent granted Member).
CREATE OR REPLACE FUNCTION sp_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member() AND NOT sp_is_guest();
END$$;

-- The same three, about ANOTHER member (the resolvers walk principals: a share to a department reaches its members).
CREATE OR REPLACE FUNCTION sp_member_is_guest(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL
                     AND m.is_external
                     AND NOT (m.roles && ARRAY['user', 'space_owner', 'admin'])
                     AND NOT (cardinality(m.roles) = 0 AND m.capability IN ('write', 'admin')));
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM sp_role_rights rr
                   JOIN sp_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM sp_roles r;

GRANT SELECT ON sp_rights, sp_roles, sp_role_rights, mcp_app_roles TO spaces_rw, spaces_records_ro;
GRANT EXECUTE ON FUNCTION sp_has_right(text), sp_is_admin(), sp_is_guest(), sp_is_member_here(), sp_member_is_guest(bigint)
    TO spaces_rw, spaces_records_ro, spaces_activity_ro;

COMMIT;
