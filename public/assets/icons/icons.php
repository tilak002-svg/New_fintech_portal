<?php
/**
 * Minimal original icon set â€” 1.75px stroke, 24x24 viewBox, no fill.
 * Kept as static trusted SVG markup (not user input, safe to echo raw).
 */

function icon(string $name, string $class = 'w-5 h-5'): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="16.5" cy="14.5" r="1"/>',
        'deposit' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8.5 11.5 12 15l3.5-3.5"/>',
        'withdrawal' => '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8.5 12.5 12 9l3.5 3.5"/>',
        'transactions' => '<path d="M4 6h16M4 12h16M4 18h10"/>',
        'users' => '<circle cx="9" cy="8" r="3.25"/><path d="M2.75 19c0-3 2.8-5.25 6.25-5.25S15.25 16 15.25 19"/><circle cx="17" cy="8.5" r="2.5"/><path d="M15 13.75c2.6.2 4.5 2.05 4.5 4.75"/>',
        'support' => '<path d="M4 5.5h16v10H9.5L5 19v-3.5H4Z"/><path d="M8 9.5h8M8 12.5h5"/>',
        'notification' => '<path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 13 6 9Z"/><path d="M9.5 17.5a2.5 2.5 0 0 0 5 0"/>',
        'profile' => '<circle cx="12" cy="8.5" r="3.5"/><path d="M4.75 19.5c0-3.6 3.25-6.25 7.25-6.25s7.25 2.65 7.25 6.25"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 13.5a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V19.5a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H4.5a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 6.1 8.6a1.65 1.65 0 0 0-.33-1.82l-.06-.06A2 2 0 1 1 8.54 3.9l.06.06a1.65 1.65 0 0 0 1.82.33H10.5a1.65 1.65 0 0 0 1-1.51V2.5a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.09a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V8.5a1.65 1.65 0 0 0 1.51 1H19.5a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'logout' => '<path d="M9 19H5.5a1.5 1.5 0 0 1-1.5-1.5v-11A1.5 1.5 0 0 1 5.5 5H9"/><path d="M16 15.5 20 12l-4-3.5"/><path d="M20 12H9"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.5-4.5"/>',
        'filter' => '<path d="M4 6h16M7 12h10M10.5 18h3"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>',
        'alert-circle' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'external-link' => '<path d="M14 5h5v5"/><path d="M19 5 10 14"/><path d="M18 13v5.5A1.5 1.5 0 0 1 16.5 20h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H11"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.75"/>',
        'eye-off' => '<path d="M3 3l18 18"/><path d="M10.6 5.6A10.6 10.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a15.6 15.6 0 0 1-3.3 4.1M6.6 6.6C4 8.3 2.5 12 2.5 12s3.5 6.5 9.5 6.5a9.6 9.6 0 0 0 3.5-.65"/><path d="M9.5 12a2.5 2.5 0 0 0 3.6 2.24"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'trash' => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m2 0-.7 12.1a2 2 0 0 1-2 1.9H9.7a2 2 0 0 1-2-1.9L7 7h10Z"/>',
        'send' => '<path d="M4 12 20 4l-6.5 16-2.8-6.7L4 12Z"/>',
        'shield' => '<path d="M12 3.5 19.5 6.5V11c0 5-3.2 8.2-7.5 9.5C7.7 19.2 4.5 16 4.5 11V6.5L12 3.5Z"/>',
        'gateway' => '<rect x="3" y="9" width="7" height="7" rx="1.5"/><rect x="14" y="9" width="7" height="7" rx="1.5"/><path d="M10 12.5h4"/><path d="M6.5 9V6a1.5 1.5 0 0 1 1.5-1.5h8A1.5 1.5 0 0 1 17.5 6v3"/>',
        'key' => '<circle cx="8" cy="15.5" r="4"/><path d="M11.2 12.3 19 4.5M19 4.5h-3.2M19 4.5v3.2M15.5 8 17.5 10"/>',
        'upload' => '<path d="M12 15V4M8 8l4-4 4 4"/><path d="M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/>',
        'download' => '<path d="M12 4v11M8.5 11.5 12 15l3.5-3.5"/><path d="M4 16v3.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V16"/>',
        'treasury' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><circle cx="12" cy="12" r="4"/><path d="M12 9.8V12l1.6 1"/><path d="M3 9h2.2M3 15h2.2M18.8 9H21M18.8 15H21"/>',
        'documentation' => '<path d="M7 3.5h7L18 7v13.5H7Z"/><path d="M14 3.5V7h4"/><path d="M9.5 12h5M9.5 15.5h5"/>',
        'inbox' => '<path d="M4 12h4l2 3h4l2-3h4"/><path d="M5.5 5h13L21 12v6a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18v-6Z"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17"/><path d="M8 3v3M16 3v3"/>',
        'circle' => '<circle cx="12" cy="12" r="8"/>',
        'copy' => '<rect x="8.5" y="8.5" width="12" height="12" rx="1.5"/><path d="M15.5 8.5V5.5A1.5 1.5 0 0 0 14 4H5.5A1.5 1.5 0 0 0 4 5.5V14a1.5 1.5 0 0 0 1.5 1.5h3"/>',
    ];

    $path = $paths[$name] ?? $paths['alert-circle'];
    return "<svg class=\"{$class}\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\">{$path}</svg>";
}

/**
 * The Verapay mark: the supplied navy-to-cyan "V" wordmark icon
 * (public/assets/images/logo-mark.png), background removed. It's a
 * two-tone gradient asset, not a flat currentColor icon like icon()
 * above, so it can't sit directly on brand-blue chrome — its own navy
 * tone loses contrast there. Callers on a brand-blue ground must wrap it
 * in a light/white plate; on dark or light neutral surfaces it can sit
 * directly. $class sizes it (a height + w-auto is usually right, since
 * the source art is wider than it is tall).
 */
function brand_mark(string $class = 'h-6 w-auto'): string
{
    return "<img src=\"/assets/images/logo-mark.png\" alt=\"\" class=\"{$class} object-contain\">";
}
