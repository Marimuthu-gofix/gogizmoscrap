<?php
/**
 * First-time setup in the browser: while api/config.php is missing, broken
 * or still holds the example values, the site shows a form for the
 * database name, user and password instead of the dashboard. The form tests
 * the connection, creates the tables and writes api/config.php itself, so
 * nobody has to edit PHP by hand.
 *
 * Once api/config.php works the form is gone; delete that file to see it
 * again.
 */
declare(strict_types=1);

const BB_DEFAULT_GITHUB_REPO = 'Marimuthu-gofix/gogizmoscrap';
// Values from config.example.php and the setup notes that are not real yet.
const BB_PLACEHOLDER_SECRETS = [
    '', 'change-me', 'PUT-YOUR-DATABASE-PASSWORD-HERE', 'YOUR-DATABASE-PASSWORD', 'your-real-database-password', 'your-real-password',
];

/**
 * Why the site cannot run yet: 'missing', 'broken' (PHP cannot read the
 * file) or 'unfilled' (example values) - or null when api/config.php is usable.
 */
function bb_config_problem(): ?string
{
    $file = bb_config_file();
    if ($file === null) {
        return 'missing';
    }
    try {
        $config = (static fn (string $path) => require $path)($file);
    } catch (ParseError) {
        return 'broken';
    }
    if (!is_array($config)) {
        return 'broken';
    }
    $dsn = (string) ($config['dsn'] ?? '');
    if (str_starts_with($dsn, 'mysql:')
        && (str_contains($dsn, 'u123456789') || in_array((string) ($config['password'] ?? ''), BB_PLACEHOLDER_SECRETS, true))) {
        return 'unfilled';
    }
    if (in_array((string) ($config['api_key'] ?? ''), BB_PLACEHOLDER_SECRETS, true)) {
        return 'unfilled';
    }
    return null;
}

/** Where the form writes api/config.php (the existing file if there is one). */
function bb_config_target(): string
{
    $existing = bb_config_file();
    if ($existing !== null) {
        return $existing;
    }
    $serverApi = dirname(__DIR__) . '/api';
    return is_dir($serverApi) ? $serverApi . '/config.php' : dirname(__DIR__, 2) . '/hostinger_api/config.php';
}

/**
 * Values that can be read from an existing (even broken) config.php, so the
 * form only asks for what is missing: database name, user, API key and any
 * GitHub settings. The password is never read back.
 */
function bb_config_hints(): array
{
    $file = bb_config_file();
    $text = $file !== null ? (string) @file_get_contents($file) : '';
    $pick = function (string $key) use ($text): string {
        if (preg_match("/'" . preg_quote($key, '/') . "'\s*=>\s*'([^'\\\\]*)'/", $text, $m) && !in_array($m[1], BB_PLACEHOLDER_SECRETS, true)) {
            return $m[1];
        }
        return '';
    };
    $database = preg_match('/dbname=([A-Za-z0-9_]+)/', $text, $m) && !str_contains($m[1], 'u123456789') ? $m[1] : '';
    $user = $pick('user');
    return [
        'database' => $database,
        'user' => str_contains($user, 'u123456789') ? '' : $user,
        'api_key' => $pick('api_key'),
        'github_token' => str_starts_with($pick('github_token'), 'github_pat_') && !str_ends_with($pick('github_token'), '...') ? $pick('github_token') : '',
        'github_repo' => $pick('github_repo') ?: BB_DEFAULT_GITHUB_REPO,
    ];
}

/** A new API key: 43 URL-safe characters, like secrets.token_urlsafe(32). */
function bb_new_api_key(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * Test the database details, create the tables and write api/config.php.
 * Returns [error message or null, the API key in use].
 */
function bb_save_setup(string $database, string $user, string $password, string $apiKey): array
{
    $database = trim($database);
    $user = trim($user);
    $apiKey = trim($apiKey);
    $hints = bb_config_hints();
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
        return ['Enter the database name exactly as hPanel shows it (letters, digits and _ only).', ''];
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $user)) {
        return ['Enter the database user exactly as hPanel shows it (letters, digits and _ only).', ''];
    }
    if (in_array($password, BB_PLACEHOLDER_SECRETS, true)) {
        return ['Enter the real database password.', ''];
    }
    if ($apiKey === '') {
        $apiKey = strlen($hints['api_key']) >= 20 ? $hints['api_key'] : bb_new_api_key();
    } elseif (strlen($apiKey) < 20 || !preg_match('/^[A-Za-z0-9_-]+$/', $apiKey)) {
        return ['The API key must be at least 20 letters, digits, - or _. Leave it empty to keep the current one or make a new one.', ''];
    }

    $dsn = "mysql:host=localhost;dbname=$database;charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        bb_prices_schema($pdo);
    } catch (PDOException $e) {
        return ['Could not connect with these details: ' . $e->getMessage(), ''];
    }

    $values = ['dsn' => $dsn, 'user' => $user, 'password' => $password, 'api_key' => $apiKey];
    if ($hints['github_token'] !== '') {
        $values['github_token'] = $hints['github_token'];
    }
    $values['github_repo'] = $hints['github_repo'];
    $problem = bb_write_config($values);
    return $problem === null ? [null, $apiKey] : ['The details work, but ' . $problem, ''];
}

/**
 * Write api/config.php from $values. var_export quotes every value
 * correctly, whatever a password or token contains. Returns an error or null.
 */
function bb_write_config(array $values): ?string
{
    $php = "<?php\n"
        . '// Written by the dashboard on ' . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\n"
        . "// Edit a value here to change it, or delete this file to run the setup page again.\n"
        . "return [\n";
    foreach ($values as $key => $value) {
        $php .= '    ' . var_export((string) $key, true) . ' => ' . var_export($value, true) . ",\n";
    }
    $php .= "];\n";

    $target = bb_config_target();
    if (@file_put_contents($target, $php, LOCK_EX) === false) {
        return basename(dirname($target)) . '/config.php could not be saved. Check that the api folder is writable in File Manager.';
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($target, true);
    }
    return null;
}

// --------------------------------------------------- GitHub scrape settings
/**
 * Check that a token can see the repository and its scrape workflow.
 * Returns an error in plain words, or null when it looks right. (Starting a
 * run is the only full test of "write" access, and that would start a scrape.)
 */
function bb_check_github(string $token, string $repo): ?string
{
    $github = [
        'token' => $token, 'repo' => bb_github_repo_name($repo), 'workflow' => 'scrape.yml', 'ref' => 'main',
        'api' => rtrim(trim((string) (bb_config()['github_api'] ?? 'https://api.github.com')), '/'), 'poll_seconds' => 8,
    ];
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $github['repo'])) {
        return 'Enter the repository as owner/name, for example Marimuthu-gofix/gogizmoscrap.';
    }
    [$status, , $error] = bb_github_api($github, 'GET', '');
    if ($status === 401) {
        return 'GitHub says this token is not valid. It may be mistyped, expired or deleted; create a new one.';
    }
    if ($status !== 200) {
        return "This token cannot see the repository {$github['repo']}. When creating the token, choose \"Only select repositories\" and pick it."
            . ($status === 0 ? " (GitHub could not be reached: $error)" : '');
    }
    [$status] = bb_github_api($github, 'GET', '/actions/workflows/scrape.yml');
    if ($status === 404) {
        return "The token works, but GitHub shows no scrape workflow in {$github['repo']}. Check the repository name, and that "
            . 'the token has the permission Actions: Read and write.';
    }
    if ($status !== 200) {
        return 'The token cannot read GitHub Actions. Give it the repository permission Actions: Read and write.';
    }
    return null;
}

/** Save the GitHub token and repository into api/config.php, keeping everything else. */
function bb_save_github_settings(string $token, string $repo): ?string
{
    $config = bb_config();
    $token = trim($token, " \t\n\r\"'");
    if ($token === '') {
        $token = trim((string) ($config['github_token'] ?? ''));
        if ($token === '') {
            return 'Paste the GitHub token.';
        }
    }
    $repo = bb_github_repo_name($repo !== '' ? $repo : BB_DEFAULT_GITHUB_REPO);
    $problem = bb_check_github($token, $repo);
    if ($problem !== null) {
        return $problem;
    }
    $config['github_token'] = $token;
    $config['github_repo'] = $repo;
    return bb_write_config($config);
}

function bb_settings_page(array $user, ?array $flash): string
{
    $github = bb_github_config();
    $alert = bb_alert_html($flash);
    $csrf = bb_csrf_field();
    $repo = e($github['repo'] ?? (string) (bb_config()['github_repo'] ?? BB_DEFAULT_GITHUB_REPO));
    $status = $github
        ? '<div class="auth-alert ok">Connected. "Check All Prices" and "Scrape Selected" start the scrape on GitHub (' . $repo . ').</div>'
        : '<div class="auth-alert error">Not connected yet. Add a GitHub token below to make the scrape buttons work.</div>';
    $tokenNote = $github ? 'A token is saved. Leave this empty to keep it, or paste a new one to replace it.' : 'Starts with github_pat_';
    $body = <<<HTML
      <section class="auth-card">
        <p class="eyebrow">Buyback</p>
        <h1>Scrape settings</h1>
        <p class="auth-note">The scrape runs on GitHub Actions. The dashboard's buttons need a GitHub token that may start it.</p>
        {$status}
        {$alert}
        <form method="post" action="index.php?action=settings" class="auth-form" autocomplete="off">
          {$csrf}
          <label for="github_token">GitHub token</label>
          <input id="github_token" name="github_token" type="password" placeholder="github_pat_..." autocomplete="off" spellcheck="false">
          <p class="auth-note">{$tokenNote}</p>
          <label for="github_repo">Repository</label>
          <input id="github_repo" name="github_repo" value="{$repo}" required spellcheck="false">
          <button type="submit">Check and save</button>
        </form>
        <h4 class="detail-subheading">How to make the token</h4>
        <ol class="setup-steps">
          <li>On GitHub: your photo (top right) &rsaquo; <strong>Settings</strong> &rsaquo; <strong>Developer settings</strong> &rsaquo; <strong>Personal access tokens</strong> &rsaquo; <strong>Fine-grained tokens</strong> &rsaquo; <strong>Generate new token</strong>.</li>
          <li>Name it, for example <code>gogizmoscrape dashboard</code>, and pick an expiry (up to a year).</li>
          <li>Repository access: <strong>Only select repositories</strong> &rsaquo; <code>{$repo}</code>.</li>
          <li>Permissions &rsaquo; Repository permissions &rsaquo; <strong>Actions: Read and write</strong>.</li>
          <li><strong>Generate token</strong>, copy it, paste it above and press <strong>Check and save</strong>.</li>
        </ol>
        <p class="auth-note"><a href="index.php">Back to the dashboard</a></p>
      </section>
HTML;
    return bb_auth_page('Scrape settings', $body, false);
}

// ------------------------------------------------------------------- pages
function bb_setup_form_page(string $problem, ?string $error = null, array $typed = []): string
{
    $hints = bb_config_hints();
    $reason = [
        'missing' => 'The site is uploaded. It now needs the database login, which is saved in <code>api/config.php</code>.',
        'broken' => 'The file <code>api/config.php</code> has a typing mistake, so the site cannot read it. Fill in this form and the site writes a correct one.',
        'unfilled' => 'The file <code>api/config.php</code> still has example values instead of the real database login. Fill in this form to finish it.',
    ][$problem] ?? '';
    $alert = $error !== null ? '<div class="auth-alert error" role="alert">' . e($error) . '</div>' : '';
    $database = e($typed['database'] ?? $hints['database']);
    $user = e($typed['user'] ?? $hints['user']);
    $keyNote = $hints['api_key'] !== ''
        ? 'Leave empty to keep the key already in api/config.php (the one in the GitHub secret PRICE_API_KEY).'
        : 'Leave empty to make a new one. If you already put a key in the GitHub secret PRICE_API_KEY, paste the same one here.';
    $csrf = bb_csrf_field();
    $body = <<<HTML
      <section class="auth-card">
        <p class="eyebrow">Buyback</p>
        <h1>Connect the database</h1>
        <p class="auth-note">{$reason}</p>
        {$alert}
        <form method="post" action="index.php?action=install" class="auth-form" autocomplete="off">
          {$csrf}
          <label for="database">Database name</label>
          <input id="database" name="database" value="{$database}" placeholder="u123456789_name" required autocapitalize="none" spellcheck="false">
          <label for="db_user">Database user</label>
          <input id="db_user" name="db_user" value="{$user}" placeholder="u123456789_name" required autocapitalize="none" spellcheck="false">
          <label for="db_password">Database password</label>
          <input id="db_password" name="db_password" type="password" required autocomplete="new-password">
          <label for="api_key">API key (optional)</label>
          <input id="api_key" name="api_key" autocapitalize="none" spellcheck="false">
          <p class="auth-note">{$keyNote}</p>
          <button type="submit">Test and save</button>
        </form>
        <p class="auth-note">The name, user and password are in hPanel &rsaquo; Databases &rsaquo; Management. Forgot the password? Use <strong>Change password</strong> there and type the new one here.</p>
      </section>
HTML;
    return bb_auth_page('Connect the database', $body);
}

function bb_setup_done_page(string $apiKey): string
{
    $key = e($apiKey);
    $body = <<<HTML
      <section class="auth-card">
        <p class="eyebrow">Buyback</p>
        <h1>Database connected</h1>
        <p class="auth-note">The connection works, the tables are created and <code>api/config.php</code> is saved.</p>
        <ol class="setup-steps">
          <li><strong>API key.</strong> The GitHub secret <code>PRICE_API_KEY</code> must hold exactly this value:
            <pre>{$key}</pre></li>
          <li><strong>Create the admin account</strong> on the next page. The setup code it asks for is the same API key.</li>
        </ol>
        <form method="get" action="index.php" class="auth-form">
          <button type="submit">Continue</button>
        </form>
      </section>
HTML;
    return bb_auth_page('Database connected', $body);
}
