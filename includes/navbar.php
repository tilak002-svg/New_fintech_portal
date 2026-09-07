<?php
/**
 * Expects $user, $pageTitle in scope.
 */
$unreadStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadStmt->execute([$user['id']]);
$unreadCount = (int) $unreadStmt->fetchColumn();
?>
<header class="sticky top-0 z-20 flex items-center justify-between gap-4 h-16 px-4 sm:px-6 lg:px-8 bg-surface-raised border-b border-border-strong shadow-soft">
    <div class="flex items-center gap-3 min-w-0">
        <button type="button" id="sidebar-toggle" class="btn-icon lg:hidden" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-sidebar">
            <?= icon('menu', 'w-5 h-5') ?>
        </button>
        <span class="hidden sm:block w-1 h-6 rounded-full bg-brand shrink-0" aria-hidden="true"></span>
        <h1 class="text-3xl font-semibold text-text-primary truncate"><?= e($pageTitle) ?></h1>
    </div>

    <div class="flex items-center gap-1 shrink-0">
        <div class="relative">
            <button type="button" id="notif-toggle" class="btn-icon relative" aria-label="Notifications<?= $unreadCount ? ", {$unreadCount} unread" : '' ?>" aria-haspopup="true" aria-expanded="false" aria-controls="notif-panel">
                <?= icon('notification', 'w-5 h-5') ?>
                <?php if ($unreadCount > 0): ?>
                    <span class="absolute -top-1 -right-1 flex items-center justify-center min-w-[1.15rem] h-[1.15rem] px-1 rounded-full bg-danger text-white text-[10px] font-semibold leading-none" aria-hidden="true"><?= $unreadCount > 9 ? '9+' : e((string) $unreadCount) ?></span>
                <?php endif; ?>
            </button>
            <div id="notif-panel" class="hidden absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] bg-surface-raised border border-border-strong rounded-md shadow-card" role="menu" aria-label="Notifications">
                <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                    <span class="text-md font-semibold text-text-primary">Notifications</span>
                    <a href="/notifications" class="text-sm text-brand font-medium hover:underline">View all</a>
                </div>
                <div id="notif-panel-list" class="max-h-80 overflow-y-auto divide-y divide-border">
                    <div class="px-4 py-6 text-sm text-text-secondary text-center">Loading…</div>
                </div>
            </div>
        </div>

        <span class="w-px h-6 bg-border-strong mx-2 shrink-0" aria-hidden="true"></span>

        <div class="relative">
            <button type="button" id="profile-toggle" class="flex items-center gap-2.5 rounded-sm px-2 py-1.5 hover:bg-surface-muted transition-colors duration-instant" aria-haspopup="true" aria-expanded="false" aria-controls="profile-panel">
                <span class="flex items-center justify-center w-10 h-10 rounded-full bg-brand-muted text-brand-emphasis font-semibold text-sm shrink-0" aria-hidden="true"><?= user_avatar_markup($user) ?></span>
                <span class="hidden sm:block text-left leading-tight">
                    <span class="block text-md font-semibold text-text-primary"><?= e($user['name']) ?></span>
                    <span class="block text-sm text-text-secondary capitalize"><?= e($user['role']) ?></span>
                </span>
                <?= icon('chevron-down', 'w-4 h-4 text-text-secondary hidden sm:block') ?>
            </button>
            <div id="profile-panel" class="hidden absolute right-0 mt-2 w-52 bg-surface-raised border border-border-strong rounded-md shadow-card py-1.5" role="menu" aria-label="Account menu">
                <a href="/profile" class="flex items-center gap-2.5 px-4 py-2.5 text-md text-text-primary hover:bg-surface-muted" role="menuitem"><?= icon('profile', 'w-4 h-4') ?>Profile</a>
                <a href="/settings" class="flex items-center gap-2.5 px-4 py-2.5 text-md text-text-primary hover:bg-surface-muted" role="menuitem"><?= icon('settings', 'w-4 h-4') ?>Settings</a>
                <form action="/api/auth/logout.php" method="post" class="border-t border-border mt-1.5 pt-1.5">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <button type="submit" class="flex items-center gap-2.5 w-full px-4 py-2.5 text-md text-danger hover:bg-danger-bg" role="menuitem"><?= icon('logout', 'w-4 h-4') ?>Log out</button>
                </form>
            </div>
        </div>
    </div>
</header>
