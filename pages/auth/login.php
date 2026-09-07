<?php
/**
 * Standalone page (no sidebar/navbar chrome). $route === 'login', reached
 * only when unauthenticated (public/index.php redirects logged-in users away).
 */
$error = null;
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title>Sign in · Verapay</title>
    <link rel="icon" type="image/png" href="/assets/icons/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/app.build.css">
</head>
<body class="min-h-screen antialiased">
    <div class="min-h-screen lg:grid lg:grid-cols-2">

        <!-- Brand panel — hidden on mobile. No video file (stays crisp at any size,
             costs nothing to load): a handful of dots float gently at their own
             pace for constant ambient motion, and the headline/copy auto-advance
             through real capabilities on a timer, like an autoplaying feature
             demo. Flat brand color throughout — motion carries the "alive"
             feeling, not gradients or moving lines. -->
        <div class="hidden lg:flex relative overflow-hidden flex-col justify-between p-12 bg-brand">
            <svg class="absolute inset-0 w-full h-full opacity-[0.15]" aria-hidden="true">
                <defs>
                    <pattern id="login-dot-grid" width="22" height="22" patternUnits="userSpaceOnUse">
                        <circle cx="1.5" cy="1.5" r="1.5" fill="#ffffff"/>
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#login-dot-grid)"/>
            </svg>
            <div class="absolute inset-0 pointer-events-none" aria-hidden="true">
                <span class="login-float absolute rounded-full bg-white/15" style="top:14%; left:62%; width:8px; height:8px; animation-duration:7.5s;"></span>
                <span class="login-float absolute rounded-full bg-white/10" style="top:24%; left:82%; width:14px; height:14px; animation-duration:10s; animation-delay:.6s;"></span>
                <span class="login-float absolute rounded-full bg-white/10" style="top:46%; left:71%; width:6px; height:6px; animation-duration:6.5s; animation-delay:1.4s;"></span>
                <span class="login-float absolute rounded-full bg-white/15" style="top:58%; left:90%; width:10px; height:10px; animation-duration:8.5s; animation-delay:.3s;"></span>
                <span class="login-float absolute rounded-full bg-white/10" style="top:76%; left:66%; width:7px; height:7px; animation-duration:9s; animation-delay:2.1s;"></span>
                <span class="login-float absolute rounded-full bg-white/10" style="top:10%; left:38%; width:5px; height:5px; animation-duration:7s; animation-delay:1s;"></span>
            </div>
            <img src="/assets/images/logo-mark.png" alt="" class="login-float absolute -right-16 -bottom-20 w-[32rem] opacity-[0.08]" style="animation-duration:13s; filter: brightness(0) invert(1);" aria-hidden="true">

            <div class="relative flex items-center gap-3">
                <span class="flex items-center justify-center h-11 px-2.5 rounded-md bg-white shrink-0" aria-hidden="true"><?= brand_mark('h-6 w-auto') ?></span>
                <span class="leading-tight">
                    <span class="block text-2xl font-bold text-white tracking-tight">Verapay</span>
                    <span class="block text-[10px] font-semibold tracking-[0.18em] text-white/60 uppercase">Gateway Orchestration</span>
                </span>
            </div>

            <div class="relative max-w-md">
                <div id="login-scenes" class="relative min-h-[10rem]">
                    <div class="login-scene is-active" data-scene="0">
                        <h1 class="text-4xl font-semibold text-white leading-tight mb-4">Every rupee, accounted for.</h1>
                        <p class="text-md text-white/75">Verapay keeps PayIns, PayOuts, and settlements reconciled to the paisa.</p>
                    </div>
                    <div class="login-scene" data-scene="1">
                        <h1 class="text-4xl font-semibold text-white leading-tight mb-4">Routes to the healthiest gateway.</h1>
                        <p class="text-md text-white/75">Traffic shifts automatically the moment a provider slows down or fails.</p>
                    </div>
                    <div class="login-scene" data-scene="2">
                        <h1 class="text-4xl font-semibold text-white leading-tight mb-4">Settlements that reconcile themselves.</h1>
                        <p class="text-md text-white/75">Every payout is matched against your ledger, automatically.</p>
                    </div>
                    <div class="login-scene" data-scene="3">
                        <h1 class="text-4xl font-semibold text-white leading-tight mb-4">Chargebacks, handled — not chased.</h1>
                        <p class="text-md text-white/75">Track every dispute from first notice through to resolution.</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs font-semibold tracking-wide uppercase mt-8 mb-4">
                    <span class="login-tag is-active" data-tag="0">Payments</span>
                    <span aria-hidden="true" class="text-white/30">·</span>
                    <span class="login-tag" data-tag="1">Routing</span>
                    <span aria-hidden="true" class="text-white/30">·</span>
                    <span class="login-tag" data-tag="2">Settlements</span>
                    <span aria-hidden="true" class="text-white/30">·</span>
                    <span class="login-tag" data-tag="3">Chargebacks</span>
                </div>

                <div class="flex items-center gap-2" role="tablist" aria-label="Feature highlights">
                    <button type="button" class="login-progress-track" data-scene-btn="0" role="tab" aria-selected="true" aria-label="Payments"><span class="login-progress-fill"></span></button>
                    <button type="button" class="login-progress-track" data-scene-btn="1" role="tab" aria-selected="false" aria-label="Routing"><span class="login-progress-fill"></span></button>
                    <button type="button" class="login-progress-track" data-scene-btn="2" role="tab" aria-selected="false" aria-label="Settlements"><span class="login-progress-fill"></span></button>
                    <button type="button" class="login-progress-track" data-scene-btn="3" role="tab" aria-selected="false" aria-label="Chargebacks"><span class="login-progress-fill"></span></button>
                </div>
            </div>
        </div>

        <!-- Form panel -->
        <div class="flex items-center justify-center px-4 py-10 sm:py-16 bg-surface-strong lg:bg-surface-muted">
            <div class="w-full max-w-md">
                <div class="flex items-center justify-center gap-2.5 mb-8 lg:hidden">
                    <span class="flex items-center shrink-0" aria-hidden="true"><?= brand_mark('h-8 w-auto') ?></span>
                    <span class="text-3xl font-bold text-text-inverse tracking-tight">Verapay</span>
                </div>

                <!-- Extends .card with a full-bleed top accent strip (the padded
                     .card can't do that on its own), a touch more room, and a
                     one-time entrance rise — this is the single focal point of
                     the whole page, so it earns a little more presence. -->
                <div class="login-card-enter rounded-md overflow-hidden shadow-card border border-border-strong bg-surface-raised">
                    <div class="h-1.5 bg-brand"></div>
                    <div class="p-8 sm:p-10">
                        <h1 class="text-3xl font-semibold text-text-primary mb-1.5">Sign in</h1>
                        <p class="text-md text-text-secondary mb-6">Secure access to your Verapay account.</p>

                        <?php if ($error): ?>
                            <div class="mb-5 flex items-start gap-2.5 rounded-sm border border-danger/30 bg-danger-bg px-4 py-3 text-md text-danger" role="alert">
                                <?= icon('alert-circle', 'w-5 h-5 shrink-0 mt-0.5') ?>
                                <span><?= e($error) ?></span>
                            </div>
                        <?php endif; ?>

                        <form id="login-form" action="/api/auth/login.php" method="post" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                            <div class="mb-5">
                                <label for="email" class="field-label">Email address</label>
                                <input type="email" id="email" name="email" class="field-input" autocomplete="username" required aria-describedby="email-error">
                                <p id="email-error" class="field-error hidden"></p>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="field-label">Password</label>
                                <div class="relative">
                                    <input type="password" id="password" name="password" class="field-input pr-11" autocomplete="current-password" required aria-describedby="password-error">
                                    <button type="button" id="toggle-password" class="absolute inset-y-0 right-0 flex items-center px-3 text-text-secondary" aria-label="Show password" aria-pressed="false">
                                        <?= icon('eye', 'w-5 h-5') ?>
                                    </button>
                                </div>
                                <p id="password-error" class="field-error hidden"></p>
                            </div>

                            <button type="submit" id="login-submit" class="btn-primary w-full mt-6">Sign in</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="toast-region" class="fixed bottom-4 right-4 z-50 flex flex-col gap-3 w-full max-w-sm" role="region" aria-label="Notifications" aria-live="polite"></div>

    <script>
        // Brand panel scene carousel — auto-advances like an autoplaying demo
        // reel, but stays user-controllable (click/keyboard a progress segment)
        // and respects prefers-reduced-motion by disabling autoplay entirely.
        (function () {
            const scenes = Array.from(document.querySelectorAll('.login-scene'));
            const tags = Array.from(document.querySelectorAll('.login-tag'));
            const progressBtns = Array.from(document.querySelectorAll('[data-scene-btn]'));
            if (!scenes.length) return;

            const SCENE_MS = 5000;
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let current = 0;
            let timer = null;

            function showScene(index) {
                current = index;
                scenes.forEach((el, i) => el.classList.toggle('is-active', i === index));
                tags.forEach((el) => el.classList.toggle('is-active', Number(el.dataset.tag) === index));
                progressBtns.forEach((btn, i) => {
                    btn.setAttribute('aria-selected', i === index ? 'true' : 'false');
                    const fill = btn.querySelector('.login-progress-fill');
                    if (!fill) return;
                    fill.style.animation = 'none';
                    fill.style.width = i < index ? '100%' : '0%';
                    if (i === index && !reduceMotion) {
                        void fill.offsetWidth; // restart the keyframe animation
                        fill.style.animation = `login-progress-fill ${SCENE_MS}ms linear forwards`;
                    }
                });
            }

            function startAutoplay() {
                clearInterval(timer);
                if (reduceMotion || scenes.length < 2) return;
                timer = setInterval(() => showScene((current + 1) % scenes.length), SCENE_MS);
            }

            progressBtns.forEach((btn, i) => {
                btn.addEventListener('click', () => {
                    showScene(i);
                    startAutoplay();
                });
            });

            showScene(0);
            startAutoplay();
        })();

        document.getElementById('toggle-password').addEventListener('click', function () {
            const input = document.getElementById('password');
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            this.setAttribute('aria-pressed', String(!showing));
            this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        });

        document.getElementById('login-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = document.getElementById('login-submit');
            const emailError = document.getElementById('email-error');
            const passwordError = document.getElementById('password-error');
            emailError.classList.add('hidden');
            passwordError.classList.add('hidden');
            document.getElementById('email').setAttribute('aria-invalid', 'false');
            document.getElementById('password').setAttribute('aria-invalid', 'false');

            submitBtn.disabled = true;
            submitBtn.classList.add('btn-loading');

            try {
                let res = await fetch(form.action, { method: 'POST', body: new FormData(form) });

                // A stale embedded CSRF token (tab left open past the idle
                // session timeout) is silently recoverable — fetch a fresh
                // token and retry once, rather than making the user
                // manually refresh the page just to try again.
                if (res.status === 419) {
                    const tokenRes = await fetch('/api/auth/csrf-token.php');
                    const tokenBody = await tokenRes.json();
                    if (tokenBody.success) {
                        form.querySelector('input[name="csrf_token"]').value = tokenBody.data.csrf_token;
                        res = await fetch(form.action, { method: 'POST', body: new FormData(form) });
                    }
                }

                const body = await res.json();
                if (body.success) {
                    window.location.href = '/dashboard';
                    return;
                }
                passwordError.textContent = body.message || 'Unable to sign in.';
                passwordError.classList.remove('hidden');
                document.getElementById('password').setAttribute('aria-invalid', 'true');
            } catch (err) {
                passwordError.textContent = 'Network error. Please try again.';
                passwordError.classList.remove('hidden');
            } finally {
                submitBtn.disabled = false;
                submitBtn.classList.remove('btn-loading');
            }
        });
    </script>
</body>
</html>