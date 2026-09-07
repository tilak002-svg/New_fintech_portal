<?php
/**
 * Expects $user, $route, $pageTitle in scope.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · Verapay</title>
    <link rel="icon" type="image/png" href="/assets/icons/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/app.build.css">
</head>
<body class="bg-surface-muted text-text-primary antialiased">
<a href="#main-content" class="skip-link">Skip to main content</a>

<?php require __DIR__ . '/sidebar.php'; ?>

<div class="lg:pl-64 min-h-screen flex flex-col">
    <?php require __DIR__ . '/navbar.php'; ?>

    <main id="main-content" class="flex-1 px-4 py-6 sm:px-6 lg:px-8 max-w-[1440px] w-full mx-auto">
