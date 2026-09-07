<?php
/**
 * Reached after deny_suspended() destroys the session and redirects here.
 * No authenticated user data is available at this point by design.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account suspended · Verapay</title>
    <link rel="icon" type="image/png" href="/assets/icons/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/app.build.css">
</head>
<body class="bg-surface-muted min-h-screen flex items-center justify-center px-4 py-10 antialiased">
    <div class="w-full max-w-md card text-center">
        <span class="mx-auto mb-4 flex items-center justify-center w-12 h-12 rounded-full bg-danger-bg text-danger">
            <?= icon('alert-circle', 'w-6 h-6') ?>
        </span>
        <h1 class="text-3xl font-semibold text-text-primary mb-2">Account suspended</h1>
        <p class="text-md text-text-secondary mb-6">
            Your Verapay account has been suspended and you have been signed out.
            If you believe this is a mistake, contact support for assistance.
        </p>
        <a href="/login" class="btn-primary w-full">Back to sign in</a>
    </div>
</body>
</html>
