<?php
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/settings.js'];

render_hero_banner($user, 'Account security', 'Manage your password and account security.');
?>

<div class="card max-w-xl">
    <div class="flex items-center gap-2.5 mb-5">
        <span class="icon-chip-md icon-chip-brand"><?= icon('shield', 'w-4 h-4') ?></span>
        <h2 class="card-title">Change password</h2>
    </div>
    <form id="password-form" novalidate>
        <div class="mb-5">
            <label for="current-password" class="field-label">Current password<span class="text-danger" aria-hidden="true"> *</span></label>
            <input type="password" id="current-password" name="current_password" class="field-input" autocomplete="current-password" required>
        </div>
        <div class="mb-5">
            <label for="new-password" class="field-label">New password<span class="text-danger" aria-hidden="true"> *</span></label>
            <input type="password" id="new-password" name="new_password" class="field-input" autocomplete="new-password" required aria-describedby="new-password-help">
            <p id="new-password-help" class="field-help">At least 10 characters.</p>
        </div>
        <div class="mb-6">
            <label for="confirm-password" class="field-label">Confirm new password<span class="text-danger" aria-hidden="true"> *</span></label>
            <input type="password" id="confirm-password" name="confirm_password" class="field-input" autocomplete="new-password" required>
        </div>
        <p id="password-error" class="field-error hidden mb-4"></p>
        <button type="submit" id="password-submit" class="btn-primary">Update password</button>
    </form>
</div>

<?php if ($user['role'] === 'customer'): ?>
<div class="card max-w-xl mt-5 !p-5 flex items-center justify-between gap-4">
    <div class="flex items-center gap-2.5">
        <span class="icon-chip-md icon-chip-brand"><?= icon('key', 'w-4 h-4') ?></span>
        <div>
            <p class="text-md font-medium text-text-primary">API access</p>
            <p class="text-sm text-text-secondary">Base URL, credentials, and callback URLs moved here.</p>
        </div>
    </div>
    <a href="/api-access" class="btn-secondary shrink-0">Open</a>
</div>
<?php endif; ?>
