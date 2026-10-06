<?php /** The morning digest (the outbox, kind digest). Data: title, items [{title, who, when, link}], more, link, business. */ ?>
<!doctype html>
<html><body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#283c50">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px">
<tr><td style="padding:24px 24px 8px 24px;font-size:18px;font-weight:bold"><?= e($title) ?></td></tr>
<?php foreach ($items as $i): ?>
<tr><td style="padding:8px 24px;font-size:14px;line-height:1.4;border-bottom:1px solid #eef0f4"><a href="<?= e($i['link']) ?>" style="color:#3454d1;text-decoration:none;font-weight:bold"><?= e($i['title']) ?></a><br><span style="font-size:12px;color:#748a9e"><?php if ($i['who'] !== ''): ?><?= e($i['who']) ?> &middot; <?php endif; ?><?= e($i['when']) ?></span></td></tr>
<?php endforeach; ?>
<?php if ($more > 0): ?><tr><td style="padding:8px 24px;font-size:13px;color:#748a9e"><?= (int) $more ?> more in your bell.</td></tr><?php endif; ?>
<tr><td style="padding:16px 24px 24px 24px"><a href="<?= e($link) ?>" style="display:inline-block;background:#3454d1;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-size:14px">Open the bell</a></td></tr>
<tr><td style="padding:12px 24px;font-size:12px;color:#748a9e;border-top:1px solid #e5e7eb"><?= e($business) ?><?= $business !== app_name() ? ' &middot; ' . e(app_name()) : '' ?></td></tr>
</table></td></tr></table>
</body></html>
