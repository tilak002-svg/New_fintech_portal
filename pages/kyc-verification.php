<?php
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/kyc-verification.js'];
$docTypes = kyc_document_types();

render_hero_banner(
    $user,
    'KYC Verification',
    'Securely upload and verify your business identity.',
    [
        ['id' => 'hero-account-id', 'label' => 'Account ID #' . (int) $user['id'], 'tone' => 'neutral'],
    ]
);
?>
<div class="card mb-5">
    <div class="flex items-center justify-between gap-4 mb-2">
        <p class="text-md font-semibold text-text-primary">Document checklist</p>
        <p class="text-sm text-text-secondary"><span id="kyc-progress-count">0</span> of <?= count($docTypes) ?> uploaded</p>
    </div>
    <div class="progress-track">
        <div id="kyc-progress-fill" class="progress-fill" style="width: 0%"></div>
    </div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <?php foreach ($docTypes as $type => $label): ?>
    <div class="card" data-doc-card="<?= e($type) ?>">
        <div class="flex items-start justify-between gap-3 mb-3">
            <h2 class="card-title"><?= e($label) ?></h2>
            <span data-doc-badge class="badge-neutral hidden"></span>
        </div>

        <form data-doc-form data-doc-type="<?= e($type) ?>" novalidate>
            <input type="file" data-doc-input accept=".pdf,.jpg,.jpeg,.png" class="sr-only" aria-label="Choose <?= e($label) ?> file">

            <!-- Empty state: drag-and-drop zone -->
            <div data-doc-dropzone class="rounded-md border-2 border-dashed border-border hover:border-brand hover:bg-brand-muted/30 transition-colors duration-fast cursor-pointer px-4 py-6 text-center">
                <span class="flex items-center justify-center w-9 h-9 rounded-full bg-surface-muted text-text-secondary mx-auto mb-2"><?= icon('upload', 'w-4 h-4') ?></span>
                <p class="text-sm text-text-primary"><span class="font-medium text-brand-emphasis">Choose a file</span> or drag and drop</p>
                <p class="text-xs text-text-secondary mt-1">PDF, JPG, PNG &middot; Max 5MB</p>
            </div>

            <!-- Filled state: uploaded document -->
            <div data-doc-filename class="hidden rounded-md border border-border px-4 py-3">
                <div class="flex items-center gap-3">
                    <span class="icon-chip-sm icon-chip-success shrink-0"><?= icon('check-circle', 'w-4 h-4') ?></span>
                    <div class="min-w-0 flex-1">
                        <p data-doc-filename-text class="text-sm font-medium text-text-primary truncate"></p>
                        <p class="text-xs text-text-secondary">Uploaded successfully</p>
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-3 pl-11">
                    <a data-doc-view href="#" target="_blank" rel="noopener" class="text-sm text-brand-emphasis hover:underline">Preview</a>
                    <button type="button" data-doc-replace class="text-sm text-brand-emphasis hover:underline">Replace</button>
                </div>
            </div>

            <button type="submit" data-doc-submit class="hidden"></button>
            <p data-doc-error class="field-error hidden mt-2"></p>
        </form>
    </div>
    <?php endforeach; ?>
</div>
