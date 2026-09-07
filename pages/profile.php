<?php
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/profile.js'];

render_hero_banner($user, 'Your profile', 'Manage your personal information and account security.');
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <div class="lg:col-span-1 space-y-5">
        <div class="card text-center">
            <span class="mx-auto mb-4 flex items-center justify-center w-24 h-24 rounded-full bg-brand-muted text-brand-emphasis font-semibold text-3xl"><?= user_avatar_markup($user) ?></span>
            <p class="text-3xl font-semibold text-text-primary truncate"><?= e($user['name']) ?></p>
            <p class="text-md text-text-secondary truncate"><?= e($user['email']) ?></p>
            <div class="flex items-center justify-center gap-2 mt-3">
                <span class="badge-neutral capitalize"><?= e($user['role']) ?></span>
                <span class="badge-success">Active</span>
            </div>
            <p class="text-sm text-text-secondary mt-4 pt-4 border-t border-border">Member since <?= e(date('M j, Y', strtotime($user['created_at']))) ?></p>
        </div>

        <div class="card">
            <h2 class="card-title mb-3">Security</h2>
            <p class="text-sm text-text-secondary mb-4">Your password was last changed via <a href="/settings" class="text-brand hover:underline font-medium">Settings</a>. We recommend using a unique password you don't reuse elsewhere.</p>
            <a href="/settings" class="btn-secondary w-full justify-center"><?= icon('shield', 'w-4 h-4') ?>Change password</a>
        </div>
    </div>

    <div class="card lg:col-span-2">
        <h2 class="card-title mb-5">Personal information</h2>
        <form id="profile-form" novalidate>
            <div class="mb-5">
                <label for="p-name" class="field-label">Full name<span class="text-danger" aria-hidden="true"> *</span></label>
                <input type="text" id="p-name" name="name" class="field-input" value="<?= e($user['name']) ?>" maxlength="120" required>
                <p id="p-name-error" class="field-error hidden"></p>
            </div>
            <div class="mb-6">
                <label for="p-email" class="field-label">Email address</label>
                <input type="email" id="p-email" class="field-input" value="<?= e($user['email']) ?>" disabled>
                <p class="field-help">Contact support to change the email associated with your account.</p>
            </div>
            <div class="mb-6">
                <label for="p-gender" class="field-label">Gender</label>
                <select id="p-gender" name="gender" class="field-input">
                    <option value="" <?= empty($user['gender']) ? 'selected' : '' ?>>Prefer not to say</option>
                    <option value="male" <?= ($user['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= ($user['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                    <option value="other" <?= ($user['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            <button type="submit" id="profile-submit" class="btn-primary">Save changes</button>
        </form>
    </div>
</div>

<?php if ($user['role'] === 'customer'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
    <div class="card">
        <div class="flex items-center gap-2.5 mb-5">
            <span class="icon-chip-md icon-chip-brand"><?= icon('profile', 'w-5 h-5') ?></span>
            <div>
                <h2 class="card-title">Business &amp; KYC information</h2>
                <p class="card-subtitle">Used to verify your account for higher transaction limits.</p>
            </div>
        </div>
        <form id="business-form" novalidate>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">
                <div class="sm:col-span-2">
                    <label for="b-company-name" class="field-label">Legal company name</label>
                    <input type="text" id="b-company-name" class="field-input" maxlength="160" placeholder="e.g. Verapay Traders Pvt Ltd">
                </div>
                <div>
                    <label for="b-company-type" class="field-label">Company type</label>
                    <select id="b-company-type" class="field-input">
                        <option value="">Select…</option>
                        <option>Individual / Proprietorship</option>
                        <option>Partnership</option>
                        <option>LLP</option>
                        <option>Private Limited</option>
                        <option>Public Limited</option>
                    </select>
                </div>
                <div>
                    <label for="b-pan" class="field-label">PAN number</label>
                    <input type="text" id="b-pan" class="field-input font-mono uppercase" maxlength="10" placeholder="AAAAA0000A">
                    <p class="field-help">10 characters, e.g. AAAAA0000A.</p>
                </div>
                <div>
                    <label for="b-mobile" class="field-label">Mobile number</label>
                    <input type="tel" id="b-mobile" class="field-input" maxlength="15" placeholder="98765 43210">
                </div>
                <div>
                    <label for="b-whatsapp" class="field-label">WhatsApp number</label>
                    <input type="tel" id="b-whatsapp" class="field-input" maxlength="15" placeholder="98765 43210">
                </div>
                <div class="sm:col-span-2">
                    <label for="b-gstin" class="field-label">GSTIN</label>
                    <input type="text" id="b-gstin" class="field-input font-mono uppercase" maxlength="15" placeholder="15-character GSTIN">
                </div>
                <div class="sm:col-span-2">
                    <label for="b-address" class="field-label">Office address</label>
                    <textarea id="b-address" class="field-input" rows="2" maxlength="255" placeholder="Building, street, city, state, PIN"></textarea>
                </div>
                <div class="sm:col-span-2">
                    <label for="b-identity" class="field-label">Identity document number</label>
                    <input type="text" id="b-identity" class="field-input font-mono" inputmode="numeric" maxlength="12" placeholder="12-digit number" autocomplete="off">
                    <p id="b-identity-help" class="field-help">Stored as a one-way hash — only the last 4 digits are ever shown again. Leave blank to keep what's on file.</p>
                </div>
            </div>
            <p id="business-error" class="field-error hidden mb-4"></p>
            <button type="submit" id="business-submit" class="btn-primary">Save business information</button>
        </form>
    </div>

    <div class="card">
        <div class="flex items-center gap-2.5 mb-5">
            <span class="icon-chip-md icon-chip-brand"><?= icon('wallet', 'w-5 h-5') ?></span>
            <div>
                <h2 class="card-title">Settlement bank</h2>
                <p class="card-subtitle">Where withdrawals are sent.</p>
            </div>
        </div>
        <form id="bank-form" novalidate>
            <div class="mb-5">
                <label for="bank-holder" class="field-label">Account holder name</label>
                <input type="text" id="bank-holder" class="field-input" maxlength="120" placeholder="As it appears on the bank account">
            </div>
            <div class="mb-5">
                <label for="bank-account" class="field-label">Account number</label>
                <input type="text" id="bank-account" class="field-input font-mono" inputmode="numeric" maxlength="20" placeholder="Account number" autocomplete="off">
                <p id="bank-account-help" class="field-help">Stored as a one-way hash — only the last 4 digits are ever shown again. Leave blank to keep what's on file.</p>
            </div>
            <div class="mb-6">
                <label for="bank-ifsc" class="field-label">IFSC code</label>
                <input type="text" id="bank-ifsc" class="field-input font-mono uppercase" maxlength="11" placeholder="e.g. HDFC0001234">
            </div>
            <p id="bank-error" class="field-error hidden mb-4"></p>
            <button type="submit" id="bank-submit" class="btn-primary">Save settlement bank</button>
        </form>
    </div>
</div>
<?php endif; ?>
