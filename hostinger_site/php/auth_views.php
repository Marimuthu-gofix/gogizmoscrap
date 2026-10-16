<?php
/**
 * The pages around signing in: first-time setup, sign in, the admin's
 * accounts page, and "change my password". They share the dashboard's
 * stylesheet.
 */
declare(strict_types=1);

function bb_auth_page(string $title, string $body, bool $narrow = true): string
{
    $css = e(bb_asset('public/dashboard.css'));
    $icons = bb_favicon_links();
    $class = $narrow ? 'auth-wrap' : 'auth-wrap auth-wide';
    return <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{$title} · Buyback</title>
    {$icons}
    <link rel="stylesheet" href="{$css}">
  </head>
  <body class="auth-body">
    <main class="{$class}">
{$body}
    </main>
  </body>
</html>
HTML;
}

function bb_alert_html(?array $flash): string
{
    if (!$flash || ($flash['text'] ?? '') === '') {
        return '';
    }
    $type = ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error';
    return '<div class="auth-alert ' . $type . '" role="alert">' . e($flash['text']) . '</div>';
}

function bb_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(bb_csrf_token()) . '">';
}

/** Shown until api/config.php exists: what to do next, step by step. */
function bb_missing_config_page(): string
{
    $body = <<<'HTML'
      <section class="auth-card">
        <p class="eyebrow">Buyback</p>
        <h1>Connect the database</h1>
        <p class="auth-note">The site is uploaded. One file is still needed: <code>api/config.php</code>, which holds the database login and the API key.</p>
        <ol class="setup-steps">
          <li><strong>Create a database.</strong> hPanel &rsaquo; Databases &rsaquo; Management: create a MySQL database and note its name, user and password.
            Optional: in phpMyAdmin, Import the file <code>schema.sql</code> from the zip to create the tables now (the site also creates them by itself).</li>
          <li><strong>Create the config file.</strong> hPanel &rsaquo; Files &rsaquo; File Manager &rsaquo; <code>public_html/api</code>: copy <code>config.example.php</code>, name the copy <code>config.php</code>, and edit these lines:
            <pre>'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
'user' => 'YOUR_DATABASE_USER',
'password' => 'YOUR_DATABASE_PASSWORD',
'api_key' => 'A_LONG_RANDOM_SECRET',</pre></li>
          <li><strong>Reload this page.</strong> You will be asked to create the admin account; the setup code is the <code>api_key</code>.</li>
        </ol>
        <p class="auth-note">Full instructions: <code>api/SETUP.txt</code> in the zip.</p>
      </section>
HTML;
    return bb_auth_page('Setup needed', $body, false);
}

function bb_login_page(?array $flash, string $username = ''): string
{
    $alert = bb_alert_html($flash);
    $csrf = bb_csrf_field();
    $name = e($username);
    $body = <<<HTML
      <section class="auth-card">
        <p class="eyebrow">Buyback</p>
        <h1>Sign in</h1>
        <p class="auth-note">Mobile buyback price dashboard</p>
        {$alert}
        <form method="post" action="index.php?action=login" class="auth-form">
          {$csrf}
          <label for="username">User name</label>
          <input id="username" name="username" value="{$name}" autocomplete="username" autocapitalize="none" required autofocus>
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
          <button type="submit">Sign in</button>
        </form>
      </section>
HTML;
    return bb_auth_page('Sign in', $body);
}

function bb_setup_page(?array $flash, string $username = ''): string
{
    $alert = bb_alert_html($flash);
    $csrf = bb_csrf_field();
    $name = e($username);
    $min = BB_PASSWORD_MIN;
    $body = <<<HTML
      <section class="auth-card">
        <p class="eyebrow">Buyback</p>
        <h1>Create the admin account</h1>
        <p class="auth-note">This is the first visit. Create the admin who can scrape prices and add other people. To prove the site is yours, enter the <strong>api_key</strong> from <code>api/config.php</code>.</p>
        {$alert}
        <form method="post" action="index.php?action=setup" class="auth-form">
          {$csrf}
          <label for="setup_code">Setup code (the api_key)</label>
          <input id="setup_code" name="setup_code" type="password" autocomplete="off" required autofocus>
          <label for="username">Admin user name</label>
          <input id="username" name="username" value="{$name}" autocomplete="username" autocapitalize="none" required>
          <label for="password">Password (at least {$min} characters)</label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="{$min}" required>
          <label for="password2">Password again</label>
          <input id="password2" name="password2" type="password" autocomplete="new-password" minlength="{$min}" required>
          <button type="submit">Create admin and sign in</button>
        </form>
      </section>
HTML;
    return bb_auth_page('First-time setup', $body);
}

function bb_account_page(array $user, ?array $flash): string
{
    $alert = bb_alert_html($flash);
    $csrf = bb_csrf_field();
    $name = e($user['username']);
    $role = e(BB_ROLES[$user['role']] ?? $user['role']);
    $min = BB_PASSWORD_MIN;
    $body = <<<HTML
      <section class="auth-card">
        <p class="eyebrow">Buyback</p>
        <h1>Change password</h1>
        <p class="auth-note">Signed in as <strong>{$name}</strong> ({$role}).</p>
        {$alert}
        <form method="post" action="index.php?action=account" class="auth-form">
          {$csrf}
          <label for="current">Current password</label>
          <input id="current" name="current" type="password" autocomplete="current-password" required autofocus>
          <label for="password">New password (at least {$min} characters)</label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="{$min}" required>
          <label for="password2">New password again</label>
          <input id="password2" name="password2" type="password" autocomplete="new-password" minlength="{$min}" required>
          <button type="submit">Save new password</button>
        </form>
        <p class="auth-foot"><a href="index.php">&larr; Back to the dashboard</a></p>
      </section>
HTML;
    return bb_auth_page('Change password', $body);
}

function bb_users_page(array $currentUser, array $users, ?array $flash): string
{
    $alert = bb_alert_html($flash);
    $csrf = bb_csrf_field();
    $min = BB_PASSWORD_MIN;
    $rows = '';
    foreach ($users as $user) {
        $id = (int) $user['id'];
        $name = e($user['username']);
        $isSelf = $id === (int) $currentUser['id'];
        $created = e(bb_local_date_short(str_replace(' ', 'T', (string) $user['created_at']) . 'Z'));
        $options = '';
        foreach (BB_ROLES as $value => $label) {
            $selected = $user['role'] === $value ? ' selected' : '';
            $options .= '<option value="' . e($value) . '"' . $selected . '>' . e($label) . '</option>';
        }
        $you = $isSelf ? ' <span class="badge badge-brand">you</span>' : '';
        $delete = $isSelf
            ? '<span class="auth-muted">&ndash;</span>'
            : <<<HTML
<form method="post" action="index.php?action=users" onsubmit="return confirm('Delete the account {$name}?');">
                {$csrf}<input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="{$id}">
                <button type="submit" class="danger-btn">Delete</button>
              </form>
HTML;
        $rows .= <<<HTML
          <tr>
            <td data-label="User name"><strong>{$name}</strong>{$you}</td>
            <td data-label="Role">
              <form method="post" action="index.php?action=users" class="inline-form">
                {$csrf}<input type="hidden" name="op" value="role"><input type="hidden" name="id" value="{$id}">
                <select name="role" aria-label="Role for {$name}">{$options}</select>
                <button type="submit" class="ghost-btn">Save</button>
              </form>
            </td>
            <td data-label="New password">
              <form method="post" action="index.php?action=users" class="inline-form">
                {$csrf}<input type="hidden" name="op" value="password"><input type="hidden" name="id" value="{$id}">
                <input name="password" type="password" placeholder="New password" autocomplete="new-password" minlength="{$min}" required aria-label="New password for {$name}">
                <button type="submit" class="ghost-btn">Set</button>
              </form>
            </td>
            <td data-label="Created">{$created}</td>
            <td data-label="Delete">{$delete}</td>
          </tr>

HTML;
    }
    $roleOptions = '';
    foreach (BB_ROLES as $value => $label) {
        $selected = $value === 'user' ? ' selected' : '';
        $roleOptions .= '<option value="' . e($value) . '"' . $selected . '>' . e($label) . '</option>';
    }
    $body = <<<HTML
      <section class="auth-card">
        <div class="auth-head">
          <div>
            <p class="eyebrow">Buyback</p>
            <h1>Accounts</h1>
          </div>
          <a class="ghost-btn" href="index.php">&larr; Dashboard</a>
        </div>
        <p class="auth-note"><strong>Admin</strong> can scrape prices and manage accounts. <strong>User</strong> can view prices and download Excel.</p>
        {$alert}
        <div class="table-wrap">
          <table class="data-table users-table">
            <thead>
              <tr><th>User name</th><th>Role</th><th>New password</th><th>Created</th><th>Delete</th></tr>
            </thead>
            <tbody>
{$rows}            </tbody>
          </table>
        </div>

        <h2 class="auth-subhead">Add an account</h2>
        <form method="post" action="index.php?action=users" class="add-user-form">
          {$csrf}<input type="hidden" name="op" value="create">
          <input name="username" placeholder="User name" autocomplete="off" autocapitalize="none" required aria-label="User name">
          <input name="password" type="password" placeholder="Password (at least {$min})" autocomplete="new-password" minlength="{$min}" required aria-label="Password">
          <select name="role" aria-label="Role">{$roleOptions}</select>
          <button type="submit">Add account</button>
        </form>
      </section>
HTML;
    return bb_auth_page('Accounts', $body, false);
}
