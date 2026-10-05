<?php
declare(strict_types=1);
/** Action `channel_guest_add` (log `channel.guest_add`; confirm; agent approval `external_send`): an owner with share.guest adds an external member holding Guest. */
require_once dirname(__DIR__, 3) . '/app/features/channels/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$c = channel_from_request($pdo);
require_channel_owner($c);
require_right('share.guest');
if ($c['kind'] === 'dm') { refuse(422, 'A direct message has its two people.'); }
$errors = [];
$guestId = sp_ref($pdo, 'guest', null, "SELECT 1 FROM mcp_members WHERE member_id = :id AND status = 'active' AND is_guest", 'a guest (an external member holding Guest)', $errors, false);
if ($errors !== []) { sp_refuse_fields($errors); }
sp_guard($pdo, static function () use ($pdo, $c, $guestId, $me): void {
    $pdo->beginTransaction();
    add_channel_member($pdo, $c['channel_id'], $guestId, $me);
    channel_log($pdo, 'channel.guest_add', $c, ['after' => ['member_id' => $guestId]]);
    $pdo->commit();
});
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $guestId]);
sp_done('Added the guest ' . $name . ' to ' . $c['label'], $c['channel_id'], sp_land(return_path('/channels/' . $c['channel_id'] . '/members'), 'member'), 'channelChanged', ['member_id' => $guestId]);
