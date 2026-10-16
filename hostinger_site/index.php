<?php
/**
 * Buyback price dashboard for Hostinger shared hosting (PHP + MySQL).
 *
 * Upload this folder to public_html: the dashboard runs at / and the price
 * API at /api (api/index.php), sharing api/config.php.
 *
 * Everyone signs in (php/auth.php). An admin sees prices, downloads Excel,
 * starts scrapes and manages accounts; a user sees prices and downloads
 * Excel. The very first visit creates the admin account.
 *
 * Routes (see .htaccess); every one also works as index.php?action=<name>:
 *   /                              the dashboard
 *   ?action=login | logout         sign in and out
 *   ?action=install                no usable api/config.php yet: database form
 *   ?action=setup                  first visit only: create the admin
 *   ?action=account                change your own password
 *   ?action=users                  admin: add, change and delete accounts
 *   /models.json                   model names for the export picker
 *   /export.csv, /export.xlsx      exports (scope=all|view|model)
 *   /check-now, /discover-all,
 *   /discover-scope (POST)         admin: start a scrape (php/jobs.php)
 *   /job-status                    admin: progress of that scrape
 */
declare(strict_types=1);

require __DIR__ . '/php/data.php';
require __DIR__ . '/php/render.php';
require __DIR__ . '/php/exports.php';
require __DIR__ . '/php/jobs.php';
require __DIR__ . '/php/auth.php';
require __DIR__ . '/php/auth_views.php';
require __DIR__ . '/php/setup.php';

const BB_SCRAPE_ACTIONS = ['check-now', 'discover-all', 'discover-scope'];

function bb_wants_json(): bool
{
    return trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')) === 'application/json';
}

function bb_send_json(int $status, $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bb_send_html(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    echo $html;
}

function bb_redirect(string $to): void
{
    header('Cache-Control: no-store');
    header('Location: ' . $to);
}

/**
 * A style or script address that changes whenever the file does. The host
 * lets browsers keep these files for a week, so without this a visitor goes
 * on seeing the old look after an upload.
 */
function bb_asset(string $path): string
{
    $file = __DIR__ . '/' . $path;
    return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
}

function bb_send_download(string $filename, string $contentType, string $bytes): void
{
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
}

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$action = str_replace('.json', '', $action);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isPost = $method === 'POST';
// Routes the page script calls and expects JSON back from.
$isDataRoute = bb_wants_json() || in_array($action, ['models', 'job-status'], true);

try {
    // ------------------------- no usable api/config.php yet: the setup form
    $configProblem = bb_config_problem();
    if ($configProblem !== null) {
        if ($isDataRoute) {
            bb_send_json(503, ['error' => 'api/config.php is not set up.', 'message' => 'The site is not set up yet.', 'running' => false]);
            return;
        }
        bb_session_start();
        if ($action === 'install' && $isPost) {
            $typed = ['database' => (string) ($_POST['database'] ?? ''), 'user' => (string) ($_POST['db_user'] ?? '')];
            if (!bb_csrf_ok()) {
                bb_send_html(bb_setup_form_page($configProblem, 'The form expired. Please try again.', $typed), 400);
                return;
            }
            [$problem, $apiKey] = bb_save_setup($typed['database'], $typed['user'], (string) ($_POST['db_password'] ?? ''), (string) ($_POST['api_key'] ?? ''));
            if ($problem !== null) {
                usleep(400000);
                bb_send_html(bb_setup_form_page($configProblem, $problem, $typed), 400);
                return;
            }
            bb_send_html(bb_setup_done_page($apiKey));
            return;
        }
        bb_send_html(bb_setup_form_page($configProblem), 503);
        return;
    }
    bb_session_start();

    // ------------------------------------- first visit: nobody can administer yet
    // (also the way back in if the admin row is ever deleted in phpMyAdmin)
    if (bb_admin_count() === 0) {
        if ($isDataRoute) {
            bb_send_json(401, ['error' => 'The site has no accounts yet. Open it in a browser to create the admin.']);
            return;
        }
        if ($action === 'setup' && $isPost) {
            $username = (string) ($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $problem = null;
            if (!bb_csrf_ok()) {
                $problem = 'The form expired. Please try again.';
            } elseif (!hash_equals((string) (bb_config()['api_key'] ?? ''), (string) ($_POST['setup_code'] ?? ''))) {
                usleep(400000);
                $problem = 'The setup code is not the api_key from api/config.php.';
            } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
                $problem = 'The two passwords are not the same.';
            } else {
                $problem = bb_create_user($username, $password, 'admin');
            }
            if ($problem === null) {
                bb_sign_in(bb_user_by_name($username));
                bb_flash('ok', 'The admin account is ready. Add other people under Accounts.');
                bb_redirect('index.php');
                return;
            }
            bb_send_html(bb_setup_page(['type' => 'error', 'text' => $problem], $username));
            return;
        }
        bb_send_html(bb_setup_page(bb_take_flash()));
        return;
    }
    if ($action === 'setup') {
        bb_redirect('index.php');
        return;
    }

    $user = bb_current_user();

    // ---------------------------------------------------------------- sign in
    if ($action === 'login') {
        if ($user) {
            bb_redirect('index.php');
            return;
        }
        if ($isPost) {
            $username = (string) ($_POST['username'] ?? '');
            if (!bb_csrf_ok()) {
                bb_send_html(bb_login_page(['type' => 'error', 'text' => 'The form expired. Please try again.'], $username));
                return;
            }
            [$account, $message] = bb_try_login($username, (string) ($_POST['password'] ?? ''));
            if ($account) {
                bb_sign_in($account);
                bb_redirect('index.php');
                return;
            }
            bb_send_html(bb_login_page(['type' => 'error', 'text' => $message], $username), 401);
            return;
        }
        bb_send_html(bb_login_page(bb_take_flash()));
        return;
    }

    if (!$user) {
        if ($isDataRoute) {
            bb_send_json(401, ['error' => 'Sign in first.', 'message' => 'Signed out. Reload the page and sign in.', 'running' => false]);
        } else {
            bb_redirect('index.php?action=login');
        }
        return;
    }

    // Every form a signed-in person submits must carry the page's token.
    if ($isPost && !bb_csrf_ok()) {
        if ($isDataRoute) {
            bb_send_json(403, ['error' => 'The page is out of date. Reload it and try again.', 'message' => 'The page is out of date. Reload it and try again.', 'running' => false]);
        } else {
            bb_flash('error', 'The form expired. Please try again.');
            bb_redirect('index.php');
        }
        return;
    }

    $isAdmin = bb_is_admin();

    switch ($action) {
        case 'logout':
            if ($isPost) {
                bb_sign_out();
            }
            bb_redirect('index.php?action=login');
            break;

        case 'account':
            if ($isPost) {
                $stored = bb_user_by_name($user['username']);
                $password = (string) ($_POST['password'] ?? '');
                if (!$stored || !password_verify((string) ($_POST['current'] ?? ''), $stored['password_hash'])) {
                    usleep(400000);
                    bb_flash('error', 'The current password is not right.');
                } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
                    bb_flash('error', 'The two new passwords are not the same.');
                } elseif (($problem = bb_set_password((int) $user['id'], $password)) !== null) {
                    bb_flash('error', $problem);
                } else {
                    session_regenerate_id(true);
                    bb_flash('ok', 'Your password is changed.');
                }
                bb_redirect('index.php?action=account');
                break;
            }
            bb_send_html(bb_account_page($user, bb_take_flash()));
            break;

        case 'users':
            if (!$isAdmin) {
                bb_flash('error', 'Only an admin can manage accounts.');
                bb_redirect('index.php');
                break;
            }
            if ($isPost) {
                $id = (int) ($_POST['id'] ?? 0);
                $problem = match ((string) ($_POST['op'] ?? '')) {
                    'create' => bb_create_user((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['role'] ?? 'user')),
                    'role' => bb_set_role($id, (string) ($_POST['role'] ?? '')),
                    'password' => bb_user_by_id($id) ? bb_set_password($id, (string) ($_POST['password'] ?? '')) : 'That account does not exist.',
                    'delete' => bb_delete_user($id, (int) $user['id']),
                    default => 'Unknown request.',
                };
                $done = [
                    'create' => 'The account was added.', 'role' => 'The role was changed.',
                    'password' => 'The password was changed.', 'delete' => 'The account was deleted.',
                ][(string) ($_POST['op'] ?? '')] ?? 'Done.';
                bb_flash($problem === null ? 'ok' : 'error', $problem ?? $done);
                bb_redirect('index.php?action=users');
                break;
            }
            bb_send_html(bb_users_page($user, bb_all_users(), bb_take_flash()));
            break;

        case 'models':
            bb_send_json(200, bb_load_price_data()['model_options']);
            break;

        case 'export.csv':
            [$rows, $label] = bb_export_rows((string) ($_GET['scope'] ?? 'view'), (string) ($_GET['model'] ?? ''), bb_parse_filters($_GET));
            bb_send_download("buyback_$label.csv", 'text/csv; charset=utf-8', bb_rows_to_csv($rows));
            break;

        case 'export.xlsx':
            [$rows, $label] = bb_export_rows((string) ($_GET['scope'] ?? 'all'), (string) ($_GET['model'] ?? ''), bb_parse_filters($_GET));
            $bytes = bb_rows_to_excel($rows);
            if ($bytes === null) {
                // No ZipArchive on this PHP: fall back to CSV so the export still works.
                bb_send_download("buyback_$label.csv", 'text/csv; charset=utf-8', bb_rows_to_csv($rows));
            } else {
                bb_send_download("buyback_$label.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $bytes);
            }
            break;

        case 'check-now':
        case 'discover-all':
        case 'discover-scope':
            if (!$isAdmin) {
                bb_send_json(403, ['error' => 'Only an admin can start a scrape.', 'message' => 'Only an admin can start a scrape.', 'running' => false]);
                break;
            }
            if (!$isPost) {
                bb_redirect('index.php');
                break;
            }
            $platform = 'all';
            $brand = 'all';
            $model = '';
            if ($action === 'discover-scope') {
                $platform = strtolower(trim((string) ($_POST['scrape_platform'] ?? ''))) ?: 'all';
                $platform = array_key_exists($platform, BB_SCRAPE_PLATFORMS) ? $platform : 'all';
                $brand = trim((string) ($_POST['scrape_brand'] ?? '')) ?: 'all';
                $brand = in_array($brand, BB_SCRAPE_BRANDS, true) ? $brand : 'all';
                $model = mb_substr(trim((string) ($_POST['scrape_model'] ?? '')), 0, 100);
            }
            $github = bb_github_config();
            if ($github) {
                // Every button starts the GitHub workflow; "Check All Prices" is a full scrape.
                [$status, $state] = bb_start_github_job($github, $platform, $brand, $model);
            } else {
                $form = $action === 'discover-scope' ? ['scrape_platform' => $platform, 'scrape_brand' => $brand, 'scrape_model' => $model] : [];
                [$status, $state] = bb_proxy_job('/' . $action, 'POST', $form);
            }
            if (bb_wants_json()) {
                bb_send_json($status, bb_public_job_state($state));
            } else {
                bb_redirect('index.php');
            }
            break;

        case 'job-status':
            if (!$isAdmin) {
                bb_send_json(403, ['error' => 'Only an admin can see scrape progress.', 'message' => 'Only an admin can see scrape progress.', 'running' => false]);
                break;
            }
            $github = bb_github_config();
            [$status, $state] = $github ? bb_refresh_github_job($github) : bb_proxy_job('/job-status', 'GET');
            bb_send_json($status, bb_public_job_state($state));
            break;

        default:
            $filters = bb_parse_filters($_GET);
            $data = bb_load_dashboard([
                'search' => $filters['search'], 'selectedBrands' => $filters['brands'], 'compare' => $filters['compare'],
                'page' => $filters['page'], 'perPage' => $filters['per_page'], 'status' => $filters['status'],
                'platform' => $filters['platform'], 'sort' => $filters['sort'], 'sortDir' => $filters['sort_dir'],
                'historyPage' => $filters['history_page'], 'historyPerPage' => $filters['history_per_page'],
            ]);
            $baseQuery = bb_build_query($filters);
            $historyQuery = bb_build_query($filters, ['page' => $data['pagination']['page']]);
            unset($historyQuery['hpage']);
            bb_send_html(bb_render_page($data + [
                'job' => $isAdmin ? bb_job_state() : bb_default_job_state(),
                'current_user' => $user,
                'can_scrape' => $isAdmin,
                'csrf' => bb_csrf_token(),
                'flash' => bb_take_flash(),
                'search' => $filters['search'],
                'selected_per_page' => $data['pagination']['per_page'],
                'active_filters' => bb_active_filters($filters),
                'sort_headers' => bb_sort_headers($filters),
                'latest_links' => bb_pager_links($data['pagination'], $baseQuery, 'page', ''),
                'history_links' => bb_pager_links($data['history_pagination'], $historyQuery, 'hpage', 'recent-checks'),
                'urls' => [
                    'index' => 'index.php',
                    'css' => bb_asset('public/dashboard.css'),
                    'js' => bb_asset('public/dashboard.js'),
                    'models_json' => 'index.php?action=models',
                    'export_all' => bb_url('index.php?action=export.xlsx', ['scope' => 'all']),
                    'export_view' => bb_url('index.php?action=export.xlsx', ['scope' => 'view'] + $baseQuery),
                    'export_base' => 'index.php',
                    'check_now' => 'index.php?action=check-now',
                    'discover_scope' => 'index.php?action=discover-scope',
                    'job_status' => 'index.php?action=job-status',
                    'users' => 'index.php?action=users',
                    'account' => 'index.php?action=account',
                    'logout' => 'index.php?action=logout',
                ],
            ]));
    }
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Dashboard error: ' . $error->getMessage();
}
