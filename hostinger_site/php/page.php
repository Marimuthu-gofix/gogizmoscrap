<?php
/** The dashboard page. A line-for-line port of templates/dashboard.html (Jinja) to PHP. */
declare(strict_types=1);
/** @var array $job, $stats, $pagination, $history_pagination, $latest, $history, $brands, $selected_brands, $per_page_options, $history_per_page_options, $suggestions, $active_filters, $sort_headers, $latest_links, $history_links, $urls */
/** @var string $search, $sort, $sort_dir, $selected_status, $selected_platform, $selected_compare, $data_source */
/** @var int $selected_per_page */
/** @var array $current_user  the signed-in account; @var bool $can_scrape  true for an admin; @var string $csrf; @var ?array $flash */

$jobPct = $job['progress']['percent'] ?? null;
$priceCell = function (?array $source, ?array $other): string {
    if (!$source) {
        return '<span class="platform-missing">-</span>';
    }
    $best = $other && $source['price_value'] > $other['price_value'] ? 'is-best' : '';
    return '<span class="platform-price ' . $best . '">' . e(bb_money($source['price_value'])) . '</span>'
        . '<a class="source-link" href="' . e($source['url']) . '" target="_blank" rel="noreferrer" onclick="event.stopPropagation()" title="Open on ' . e($source['platform']) . '">&#8599;</a>';
};
$offerCell = function (array $row): string {
    if ($row['best'] === 'Same') {
        return '<span class="badge badge-offer-same">Same price</span>';
    }
    if ($row['best']) {
        $pct = $row['gap_pct'] !== null ? ' (' . e($row['gap_pct']) . '%)' : '';
        return '<span class="offer"><span class="badge badge-offer-' . e(strtolower($row['best'])) . '">' . e($row['best']) . '</span>'
            . '<small>+' . e(bb_money($row['gap'])) . $pct . '</small></span>';
    }
    return '<span class="platform-missing">Only on ' . ($row['cashify'] ? 'Cashify' : 'DOFY') . '</span>';
};
$pager = function (array $pagination, array $links, string $label): string {
    $html = '<nav class="pagination" aria-label="' . e($label) . '">';
    if ($pagination['has_prev']) {
        $html .= '<a href="' . e($links['first']) . '">First</a><a href="' . e($links['prev']) . '">Prev</a>';
    } else {
        $html .= '<span class="disabled">First</span><span class="disabled">Prev</span>';
    }
    foreach ($links['pages'] as $link) {
        if ($link['gap']) {
            $html .= '<span class="ellipsis">&hellip;</span>';
        }
        $html .= $link['current'] ? '<strong class="current-page">' . (int) $link['number'] . '</strong>'
            : '<a href="' . e($link['url']) . '">' . (int) $link['number'] . '</a>';
    }
    if ($pagination['has_next']) {
        $html .= '<a href="' . e($links['next']) . '">Next</a><a href="' . e($links['last']) . '">Last</a>';
    } else {
        $html .= '<span class="disabled">Next</span><span class="disabled">Last</span>';
    }
    return $html . '</nav>';
};
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buyback Price Dashboard</title>
    <?= bb_favicon_links() ?>

    <link rel="stylesheet" href="<?= e($urls['css']) ?>">
  </head>
  <body>
    <div id="topLoader" class="top-loader" aria-hidden="true"></div>

    <header class="topbar">
      <div class="brand-block">
        <p class="eyebrow">Buyback</p>
        <h1>Mobile Buyback Price Dashboard</h1>
      </div>
      <div class="header-actions">
        <button type="button" class="icon-btn" data-action="refresh" title="Refresh">
          <?= bb_icon('refresh') ?>
          <span>Refresh</span>
        </button>
        <details class="export-menu">
          <summary class="icon-btn" title="Export prices to Excel">
            <?= bb_icon('download') ?>
            <span>Export Excel</span>
            <?= bb_icon('chevron') ?>
          </summary>
          <div class="export-panel">
            <a class="export-option" href="<?= e($urls['export_all']) ?>">
              <strong>All models</strong>
              <span>Every variant, Cashify and DOFY side by side</span>
            </a>
            <a class="export-option" href="<?= e($urls['export_view']) ?>">
              <strong>Current view</strong>
              <span>Only what the filters below are showing</span>
            </a>
            <form class="export-model" method="get" action="<?= e($urls['export_base']) ?>">
              <input type="hidden" name="action" value="export.xlsx">
              <input type="hidden" name="scope" value="model">
              <label for="exportModel"><strong>Particular model</strong></label>
              <input id="exportModel" name="model" list="exportModelOptions" placeholder="Type a model, e.g. Galaxy S24" autocomplete="off" required>
              <datalist id="exportModelOptions" data-source="<?= e($urls['models_json']) ?>"></datalist>
              <button type="submit">Export this model</button>
            </form>
          </div>
        </details>
        <span class="mode-pill live" title="Data source: <?= e($data_source) ?>">
          <?= bb_icon('dot') ?>
          Hostinger (live)
          &middot; <?= str_starts_with($data_source, 'MySQL') ? 'MySQL' : 'SQLite file' ?>
        </span>
        <details class="export-menu user-menu">
          <summary class="icon-btn" title="Signed in as <?= e($current_user['username']) ?>">
            <?= bb_icon('user') ?>
            <span><?= e($current_user['username']) ?></span>
            <?= bb_icon('chevron') ?>
          </summary>
          <div class="export-panel">
            <div class="user-menu-who">
              <strong><?= e($current_user['username']) ?></strong>
              <span class="role-tag role-<?= e($current_user['role']) ?>"><?= e(BB_ROLES[$current_user['role']] ?? $current_user['role']) ?></span>
            </div>
            <?php if ($can_scrape): ?>
              <a class="export-option" href="<?= e($urls['users']) ?>">
                <strong>Accounts</strong>
                <span>Add people, change roles and passwords</span>
              </a>
            <?php endif; ?>
            <a class="export-option" href="<?= e($urls['account']) ?>">
              <strong>Change password</strong>
              <span>Set a new password for this account</span>
            </a>
            <form method="post" action="<?= e($urls['logout']) ?>" class="logout-form">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <button type="submit">Sign out</button>
            </form>
          </div>
        </details>
      </div>
    </header>

    <main>
      <?php if (!empty($flash['text'])): ?>
        <div class="auth-alert <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>" role="alert"><?= e($flash['text']) ?></div>
      <?php endif; ?>

      <?php if ($can_scrape): ?>
      <section id="jobStatus" class="job-status <?= $job['running'] ? 'running' : '' ?>" data-running="<?= $job['running'] ? 'true' : 'false' ?>" data-status-url="<?= e($urls['job_status']) ?>">
        <div class="job-status-text">
          <strong><?= e($job['message']) ?></strong>
          <span>
            <?php if ($job['running'] && $jobPct !== null): ?>
              Progress: <?= (int) round((float) $jobPct) ?>% | Rows saved: <?= (int) ($job['progress']['rows'] ?? 0) ?> | Errors: <?= (int) ($job['progress']['errors'] ?? 0) ?>
            <?php elseif ($job['running']): ?>
              Time: calculating... | Done: <?= (int) ($job['progress']['done'] ?? 0) ?> models | Rows saved: <?= (int) ($job['progress']['rows'] ?? 0) ?> | Errors: <?= (int) ($job['progress']['errors'] ?? 0) ?><?php if (!empty($job['progress']['current'])): ?> | Current: <?= e($job['progress']['current']) ?><?php endif; ?>
            <?php elseif (!empty($job['started_at'])): ?>
              Started <?= e(bb_local_date($job['started_at'])) ?>
            <?php else: ?>
              Check every phone on Cashify and DOFY, or pick a platform, brand and model and press Scrape Selected.
            <?php endif; ?>
          </span>
        </div>
        <?php if ($job['running'] && $jobPct !== null): ?>
          <div class="job-bar determinate" style="--job-pct: <?= max(0, min(100, (float) $jobPct)) ?>%"><i></i></div>
        <?php elseif ($job['running']): ?>
          <div class="job-bar"><i></i></div>
        <?php endif; ?>
      </section>

      <section class="scrape-toolbar">
        <form action="<?= e($urls['check_now']) ?>" method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button data-job-button data-idle-label="Check All Prices" data-running-label="Checking..." data-start-label="Checking every phone on Cashify and DOFY..." id="checkAllButton" class="primary-action" type="submit" <?= $job['running'] ? 'disabled' : '' ?>>
            <?= bb_icon('play') ?> <?= $job['running'] ? 'Checking...' : 'Check All Prices' ?>
          </button>
        </form>
        <form action="<?= e($urls['discover_scope']) ?>" method="post" class="scope-form">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <select name="scrape_platform" aria-label="Scrape platform">
            <?php foreach (BB_SCRAPE_PLATFORMS as $value => $label): ?>
              <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="scrape_brand" aria-label="Scrape brand">
            <option value="all">All brands</option>
            <?php foreach (BB_SCRAPE_BRANDS as $brand): ?>
              <option value="<?= e($brand) ?>"><?= e($brand) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="scrape_model" placeholder="Model (optional), e.g. iPhone 13" maxlength="100">
          <button data-job-button data-idle-label="Scrape Selected" data-running-label="Scraping..." data-start-label="Scraping the selected phones..." type="submit" <?= $job['running'] ? 'disabled' : '' ?>>
            <?= bb_icon('target') ?> Scrape Selected
          </button>
        </form>
      </section>
      <?php endif; ?>

      <section class="stats" aria-label="Dashboard summary">
        <article>
          <?= bb_icon('box') ?>
          <div>
            <strong><?= (int) $stats['tracked'] ?></strong>
            <span>Variants tracked</span>
          </div>
        </article>
        <article>
          <?= bb_icon('layers') ?>
          <div>
            <strong><?= (int) $stats['models'] ?></strong>
            <span>Models across <?= (int) $stats['brands'] ?> brands</span>
          </div>
        </article>
        <article>
          <?= bb_icon('scale') ?>
          <div>
            <strong><?= (int) $stats['both'] ?></strong>
            <span>Priced on both platforms</span>
          </div>
        </article>
        <article>
          <?= bb_icon('refresh') ?>
          <div>
            <strong><?= (int) $stats['last_update']['count'] ?></strong>
            <span>Prices in last update</span>
            <?php if (!empty($stats['last_update']['date'])): ?><small title="<?= e(bb_local_date($stats['last_update']['date'])) ?>"><?= e(bb_local_date_short($stats['last_update']['date'])) ?></small><?php endif; ?>
          </div>
        </article>
        <article>
          <?= bb_icon('tag') ?>
          <div>
            <strong class="offer-split"><?= (int) $stats['cashify_higher'] ?> <b>vs</b> <?= (int) $stats['dofy_higher'] ?></strong>
            <span>Higher offer: Cashify vs DOFY</span>
          </div>
        </article>
      </section>

      <section class="panel">
        <div class="panel-heading">
          <div>
            <h2>Cashify vs DOFY</h2>
            <p>
              <?php if ($pagination['total']): ?>
                Showing <?= $pagination['start'] ?>-<?= $pagination['end'] ?> of <?= $pagination['total'] ?> variants. Prices are the "Get Upto" offer for a phone in the best condition.
              <?php else: ?>
                0 variants
              <?php endif; ?>
            </p>
          </div>
          <button type="button" class="ghost-btn" id="toggleGroupsButton" data-collapsed-label="Expand all models" data-expanded-label="Collapse all models" hidden>Expand all models</button>
        </div>

        <form class="filter-bar" method="get" id="filterForm" action="<?= e($urls['index']) ?>">
          <input type="hidden" name="sort" value="<?= e($sort) ?>">
          <input type="hidden" name="dir" value="<?= e($sort_dir) ?>">
          <input type="hidden" name="hper_page" value="<?= (int) $history_pagination['per_page'] ?>">
          <?php if ($selected_status !== ''): ?><input type="hidden" name="status" value="<?= e($selected_status) ?>"><?php endif; ?>
          <?php if ($selected_platform !== ''): ?><input type="hidden" name="platform" value="<?= e($selected_platform) ?>"><?php endif; ?>
          <div class="filter-search">
            <?= bb_icon('search-sm') ?>
            <input name="q" value="<?= e($search) ?>" placeholder="Search model or variant, e.g. S24 256" list="modelSuggestions" autocomplete="off">
            <datalist id="modelSuggestions">
              <?php foreach ($suggestions as $suggestion): ?>
                <option value="<?= e($suggestion) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <details class="brand-select">
            <summary aria-label="Filter by brand">
              <?php if (!$selected_brands): ?>
                All brands
              <?php elseif (count($selected_brands) === 1): ?>
                <?= e($selected_brands[0]) ?>
              <?php else: ?>
                <?= count($selected_brands) ?> brands
              <?php endif; ?>
              <?= bb_icon('chevron') ?>
            </summary>
            <div class="brand-select-panel">
              <?php foreach ($brands as $brand): ?>
                <label>
                  <input type="checkbox" name="brand[]" value="<?= e($brand) ?>" <?= in_array($brand, $selected_brands, true) ? 'checked' : '' ?>>
                  <?= e($brand) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
          <select name="compare" aria-label="Compare">
            <?php foreach (BB_COMPARE_OPTIONS as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $selected_compare === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="per_page" aria-label="Rows per page">
            <?php foreach ($per_page_options as $option): ?>
              <option value="<?= (int) $option ?>" <?= $selected_per_page === $option ? 'selected' : '' ?>><?= (int) $option ?> rows</option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="page" value="1">
          <div class="filter-actions">
            <a class="ghost-btn" href="<?= e($urls['index']) ?>">Reset</a>
            <button type="submit">Apply Filters</button>
          </div>
        </form>

        <?php if ($active_filters): ?>
          <div class="filter-chips">
            <span class="filter-chips-label">Active filters:</span>
            <?php foreach ($active_filters as $filter): ?>
              <a class="chip" href="<?= e($filter['remove_url']) ?>"><?= e($filter['label']) ?> <span aria-hidden="true">&times;</span></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="table-wrap">
          <table class="data-table" data-expandable>
            <thead>
              <tr>
                <th></th>
                <?php foreach ($sort_headers as $header): ?>
                  <th class="sortable">
                    <a class="sort-link <?= $header['active'] ? 'active' : '' ?>" href="<?= e($header['url']) ?>">
                      <?= e($header['label']) ?>
                      <span class="sort-icon"><?= bb_icon($header['icon']) ?></span>
                    </a>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (!$latest): ?>
                <tr class="empty-row">
                  <td colspan="8">
                    <div class="empty-state">
                      <?= bb_icon('empty') ?>
                      <strong>No phones found.</strong>
                      <span>Try changing your filters or search terms, or run a scrape.</span>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
              <?php foreach ($latest as $item): $row = $item['row']; $grouped = $item['groupSize'] > 1; ?>
                <?php if ($item['groupStart'] && $grouped): ?>
                  <tr class="group-header" data-group="<?= e($item['groupId']) ?>" tabindex="0" role="button" aria-expanded="false">
                    <td class="expand-cell"><?= bb_icon('chevron') ?></td>
                    <td colspan="2"><span class="badge badge-brand"><?= e($row['brand']) ?></span> <strong class="group-model"><?= e($row['model']) ?></strong></td>
                    <td colspan="5" class="group-meta"><?= (int) $item['groupSize'] ?> variants &middot; <span class="compare-flag"><?= e($item['coverage']) ?></span></td>
                  </tr>
                <?php endif; ?>
                <tr class="row-toggle <?= $grouped ? 'group-child' : '' ?>" data-group="<?= $grouped ? e($item['groupId']) : '' ?>" data-drawer-title="<?= e($row['model']) ?> &middot; <?= e($row['variant'] !== '' ? $row['variant'] : 'Single variant') ?>" <?= $grouped ? 'hidden' : '' ?> tabindex="0">
                  <td class="expand-cell"><?= bb_icon('chevron') ?></td>
                  <td data-label="Brand" class="<?= $grouped ? 'grouped-cell' : '' ?>"><span class="badge badge-brand"><?= e($row['brand']) ?></span></td>
                  <td data-label="Model" class="<?= $grouped ? 'grouped-cell' : '' ?>"><?= e($row['model']) ?></td>
                  <td data-label="Variant" class="variant-cell"><?= e($row['variant'] !== '' ? $row['variant'] : '-') ?></td>
                  <td data-label="Cashify" class="price"><?= $priceCell($row['cashify'], $row['dofy']) ?></td>
                  <td data-label="DOFY" class="price"><?= $priceCell($row['dofy'], $row['cashify']) ?></td>
                  <td data-label="Best Offer"><?= $offerCell($row) ?></td>
                  <td data-label="Last Checked"><?= e(bb_local_date($row['date'])) ?></td>
                </tr>
                <template class="row-detail-template">
                  <div class="detail-grid">
                    <div><span>Brand</span><strong><?= e($row['brand']) ?></strong></div>
                    <div><span>Variant</span><strong><?= e($row['variant'] !== '' ? $row['variant'] : '-') ?></strong></div>
                    <div><span>Best offer</span><strong><?php if ($row['best'] === 'Same'): ?>Same on both<?php elseif ($row['best']): ?><?= e($row['best']) ?>, by <?= e(bb_money($row['gap'])) ?><?php if ($row['gap_pct'] !== null): ?> (<?= e($row['gap_pct']) ?>%)<?php endif; ?><?php else: ?>Only one platform lists it<?php endif; ?></strong></div>
                  </div>
                  <?php foreach ([$row['cashify'], $row['dofy']] as $source): ?>
                    <?php if ($source): ?>
                      <h4 class="detail-subheading"><?= e($source['platform']) ?></h4>
                      <div class="detail-grid">
                        <div><span>Get Upto price</span><strong><?= e(bb_money($source['price_value'])) ?></strong></div>
                        <div><span>Listed as</span><strong><?= e($source['model']) ?><?php if (($source['variant'] ?? '') !== ''): ?> (<?= e($source['variant']) ?>)<?php endif; ?></strong></div>
                        <div><span>Last checked</span><strong><?= e(bb_local_date($source['date'])) ?></strong></div>
                        <div><span>Source URL</span><strong class="truncate"><a href="<?= e($source['url']) ?>" target="_blank" rel="noreferrer"><?= e($source['url']) ?></a></strong></div>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </template>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="table-footer">
          <span class="footer-summary">
            <?php if ($pagination['total']): ?>
              Showing <?= $pagination['start'] ?>-<?= $pagination['end'] ?> of <?= $pagination['total'] ?> variants
            <?php else: ?>
              Showing 0 of 0 variants
            <?php endif; ?>
          </span>
          <?= $pager($pagination, $latest_links, 'Comparison pages') ?>
        </div>
      </section>

      <section class="panel" id="recent-checks">
        <div class="panel-heading">
          <h2>Recent Checks</h2>
          <p>
            <?php if ($history_pagination['total']): ?>
              Showing <?= $history_pagination['start'] ?>-<?= $history_pagination['end'] ?> of <?= $history_pagination['total'] ?> checks, including errors
            <?php else: ?>
              0 checks
            <?php endif; ?>
          </p>
        </div>

        <form class="filter-bar filter-bar-compact" method="get" action="<?= e($urls['index']) ?>#recent-checks">
          <input type="hidden" name="q" value="<?= e($search) ?>">
          <?php foreach ($selected_brands as $brand): ?>
            <input type="hidden" name="brand[]" value="<?= e($brand) ?>">
          <?php endforeach; ?>
          <input type="hidden" name="compare" value="<?= e($selected_compare) ?>">
          <input type="hidden" name="per_page" value="<?= (int) $selected_per_page ?>">
          <input type="hidden" name="page" value="<?= (int) $pagination['page'] ?>">
          <input type="hidden" name="sort" value="<?= e($sort) ?>">
          <input type="hidden" name="dir" value="<?= e($sort_dir) ?>">
          <select name="platform" aria-label="Filter by platform" onchange="this.form.submit()">
            <option value="" <?= $selected_platform === '' ? 'selected' : '' ?>>Both platforms</option>
            <?php foreach (BB_PLATFORMS as $platform): ?>
              <option value="<?= e($platform) ?>" <?= $selected_platform === $platform ? 'selected' : '' ?>><?= e($platform) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" aria-label="Filter by status" onchange="this.form.submit()">
            <option value="" <?= $selected_status === '' ? 'selected' : '' ?>>All statuses</option>
            <option value="ok" <?= $selected_status === 'ok' ? 'selected' : '' ?>>Available (ok)</option>
            <option value="error" <?= $selected_status === 'error' ? 'selected' : '' ?>>Failed (error)</option>
          </select>
          <select name="hper_page" aria-label="Checks per page" onchange="this.form.submit()">
            <?php foreach ($history_per_page_options as $option): ?>
              <option value="<?= (int) $option ?>" <?= $history_pagination['per_page'] === $option ? 'selected' : '' ?>><?= (int) $option ?> rows</option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="table-wrap">
          <table class="data-table" data-expandable>
            <thead>
              <tr>
                <th></th>
                <th>Date</th>
                <th>Platform</th>
                <th>Brand</th>
                <th>Model</th>
                <th>Variant</th>
                <th>Recorded Text</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$history): ?>
                <tr class="empty-row">
                  <td colspan="8">
                    <div class="empty-state">
                      <?= bb_icon('empty') ?>
                      <strong>No checks recorded yet.</strong>
                      <span>Run a scrape to populate this list.</span>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
              <?php foreach ($history as $row): $ok = $row['status'] === 'ok'; ?>
                <tr class="row-toggle" data-drawer-title="<?= e($row['platform']) ?> &middot; <?= e($row['model']) ?> <?= e($row['variant']) ?>" tabindex="0">
                  <td class="expand-cell"><?= bb_icon('chevron') ?></td>
                  <td data-label="Date"><?= e(bb_local_date($row['date'])) ?></td>
                  <td data-label="Platform"><span class="badge badge-platform-<?= e(strtolower((string) $row['platform'])) ?>"><?= e($row['platform']) ?></span></td>
                  <td data-label="Brand"><span class="badge badge-brand"><?= e($row['brand']) ?></span></td>
                  <td data-label="Model"><?= e($row['model']) ?></td>
                  <td data-label="Variant"><?= e(($row['variant'] ?? '') !== '' ? $row['variant'] : '-') ?></td>
                  <td data-label="Recorded Text"><?= e($row['price'] ?: ($row['error'] ?: '-')) ?></td>
                  <td data-label="Status">
                    <span class="badge badge-status-<?= e($row['status']) ?>">
                      <?= bb_icon($ok ? 'check' : 'alert') ?>
                      <?= $ok ? 'Available' : 'Error' ?>
                    </span>
                  </td>
                </tr>
                <template class="row-detail-template">
                  <div class="detail-grid">
                    <div><span>Checked</span><strong><?= e(bb_local_date($row['date'])) ?></strong></div>
                    <div><span>Price</span><strong><?= $row['price_value'] !== null ? e(bb_money($row['price_value'])) : '-' ?></strong></div>
                    <div><span>Status</span><strong><?= e($row['status']) ?></strong></div>
                    <div><span>Error</span><strong><?= e($row['error'] ?: '-') ?></strong></div>
                    <div><span>Source URL</span><strong class="truncate"><a href="<?= e($row['url']) ?>" target="_blank" rel="noreferrer"><?= e($row['url']) ?></a></strong></div>
                  </div>
                </template>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="table-footer">
          <span class="footer-summary">
            <?php if ($history_pagination['total']): ?>
              Showing <?= $history_pagination['start'] ?>-<?= $history_pagination['end'] ?> of <?= $history_pagination['total'] ?> checks
            <?php else: ?>
              Showing 0 of 0 checks
            <?php endif; ?>
          </span>
          <?= $pager($history_pagination, $history_links, 'Recent checks pages') ?>
        </div>
      </section>
    </main>

    <div id="drawerBackdrop" class="drawer-backdrop" hidden></div>
    <aside id="detailDrawer" class="drawer" hidden aria-hidden="true" role="dialog" aria-label="Row details">
      <div class="drawer-header">
        <h3 id="drawerTitle"></h3>
        <button type="button" class="drawer-close" id="drawerClose" aria-label="Close details"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="drawer-body" id="drawerBody"></div>
    </aside>

    <script src="<?= e($urls['js']) ?>"></script>
  </body>
</html>
