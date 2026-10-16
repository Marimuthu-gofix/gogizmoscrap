<?php
/**
 * Data layer for the PHP dashboard: reads buyback_prices straight from the
 * database (the api/config.php connection), pairs every Cashify variant with
 * the same phone on DOFY, and does search, sorting and paging.
 *
 * A port of the data functions in dashboard.py, kept in the same order so
 * the two stay easy to compare.
 */
declare(strict_types=1);

const BB_DISPLAY_TIMEZONE = 'Asia/Kolkata';
const BB_TABLE = 'buyback_prices';
const BB_PLATFORMS = ['Cashify', 'DOFY'];
const BB_SCRAPE_PLATFORMS = ['all' => 'Cashify + DOFY', 'cashify' => 'Cashify', 'dofy' => 'DOFY'];
const BB_SCRAPE_BRANDS = [
    'Apple', 'Samsung', 'Google', 'OnePlus', 'Xiaomi', 'POCO', 'OPPO', 'vivo', 'realme', 'Motorola', 'iQOO',
    'Nothing', 'CMF', 'Infinix', 'Tecno', 'Nokia', 'Honor', 'Asus', 'Lava', 'Huawei', 'LG', 'Nubia', 'Lenovo',
];
const BB_PER_PAGE_OPTIONS = [25, 50, 100, 250];
const BB_HISTORY_PER_PAGE_OPTIONS = [10, 25, 50, 100];
const BB_HISTORY_PER_PAGE_DEFAULT = 10;
const BB_COMPARE_OPTIONS = [
    '' => 'All variants',
    'both' => 'On both platforms',
    'cashify_higher' => 'Cashify pays more',
    'dofy_higher' => 'DOFY pays more',
    'cashify_only' => 'Only on Cashify',
    'dofy_only' => 'Only on DOFY',
];
const BB_SORT_KEYS = ['brand', 'model', 'variant', 'cashify', 'dofy', 'gap', 'date'];
// One spelling per brand, as in buyback.py.
const BB_BRAND_NAMES = [
    'apple' => 'Apple', 'samsung' => 'Samsung', 'google' => 'Google', 'google pixel' => 'Google', 'pixel' => 'Google',
    'oneplus' => 'OnePlus', 'oppo' => 'OPPO', 'vivo' => 'vivo', 'xiaomi' => 'Xiaomi', 'mi' => 'Xiaomi', 'redmi' => 'Xiaomi',
    'poco' => 'POCO', 'realme' => 'realme', 'motorola' => 'Motorola', 'moto' => 'Motorola', 'iqoo' => 'iQOO',
    'nothing' => 'Nothing', 'cmf' => 'CMF', 'infinix' => 'Infinix', 'tecno' => 'Tecno', 'nokia' => 'Nokia', 'lava' => 'Lava',
    'honor' => 'Honor', 'huawei' => 'Huawei', 'lg' => 'LG', 'nubia' => 'Nubia', 'asus' => 'Asus', 'lenovo' => 'Lenovo',
];
// Leading words that only repeat the brand ("Xiaomi Redmi 10" vs "Redmi 10").
const BB_MODEL_PREFIX_WORDS = [
    'apple', 'samsung', 'google', 'xiaomi', 'oppo', 'vivo', 'realme', 'oneplus', 'motorola', 'iqoo', 'nothing',
    'poco', 'infinix', 'tecno', 'nokia', 'lava', 'honor', 'huawei', 'lg', 'nubia', 'asus', 'lenovo', 'cmf',
];
const BB_NETWORK_WORDS = ['5g', '4g', 'lte'];
const BB_SIZE_PATTERN = '/(\d+(?:\.\d+)?)\s*(TB|GB|MB)\b/i';
const BB_CACHE_DIR = __DIR__ . '/cache';

// ------------------------------------------------------------ configuration
function bb_config(): array
{
    static $config = null;
    if ($config === null) {
        // On the server the API folder sits next to this one (public_html/api).
        // In the repository it is ../hostinger_api, used when trying the site on a PC.
        $file = dirname(__DIR__) . '/api/config.php';
        if (!is_file($file)) {
            $file = dirname(__DIR__, 2) . '/hostinger_api/config.php';
        }
        if (!is_file($file)) {
            throw new RuntimeException('api/config.php is missing; copy api/config.example.php and fill it in');
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException('api/config.php must return an array');
        }
    }
    return $config;
}

/** Same rule as api/index.php: accept a bare database name and fill in defaults. */
function bb_normalize_dsn(string $dsn, string $user): string
{
    if (!str_starts_with($dsn, 'mysql:')) {
        return $dsn;
    }
    $pairs = [];
    $bare = null;
    foreach (array_filter(array_map('trim', explode(';', substr($dsn, 6))), 'strlen') as $part) {
        if (str_contains($part, '=')) {
            [$key, $value] = explode('=', $part, 2);
            $pairs[strtolower(trim($key))] = trim($value);
        } elseif ($bare === null) {
            $bare = $part;
        }
    }
    if (empty($pairs['dbname'])) {
        $pairs['dbname'] = $bare ?? $user;
    }
    $pairs += ['host' => 'localhost', 'charset' => 'utf8mb4'];
    $out = [];
    foreach ($pairs as $key => $value) {
        $out[] = $key . '=' . $value;
    }
    return 'mysql:' . implode(';', $out);
}

function bb_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $config = bb_config();
        $user = (string) ($config['user'] ?? '');
        $pdo = new PDO(bb_normalize_dsn((string) ($config['dsn'] ?? ''), $user), $user, (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        bb_prices_schema($pdo);
    }
    return $pdo;
}

/**
 * The price table, so the page works before the first scrape has reached
 * the API. The same definition as in api/index.php.
 */
function bb_prices_schema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS buyback_prices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                date VARCHAR(40) NOT NULL, platform VARCHAR(20) NOT NULL, brand VARCHAR(100) NOT NULL,
                model VARCHAR(191) NOT NULL, variant VARCHAR(100) NOT NULL DEFAULT \'\', price VARCHAR(255) NULL,
                price_value DOUBLE NULL, currency VARCHAR(10) NULL, url TEXT NOT NULL, status VARCHAR(20) NOT NULL,
                error TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_observation (date, platform, brand, model, variant),
                KEY idx_lookup (platform, brand, model, variant, date),
                KEY idx_status_date (status, date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS buyback_prices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL, platform TEXT NOT NULL, brand TEXT NOT NULL, model TEXT NOT NULL,
            variant TEXT NOT NULL DEFAULT \'\', price TEXT, price_value REAL, currency TEXT, url TEXT NOT NULL,
            status TEXT NOT NULL, error TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (date, platform, brand, model, variant)
        )',
    );
}

function bb_db_label(): string
{
    $dsn = (string) (bb_config()['dsn'] ?? '');
    if (str_starts_with($dsn, 'mysql:')) {
        return 'MySQL on ' . (preg_match('/host=([^;]+)/', $dsn, $m) ? $m[1] : 'localhost');
    }
    return 'local file ' . basename(substr($dsn, (int) strpos($dsn, ':') + 1));
}

// ------------------------------------------------------------- formatting
function bb_money($value, ?string $currency = 'INR'): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $prefix = $currency === null || $currency === 'INR' ? 'Rs ' : $currency . ' ';
    return $prefix . number_format((float) round((float) $value), 0, '.', ',');
}

function bb_parse_iso($value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }
    try {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
}

/** "28 Sep 2026, 11:17 AM" in India time, like the Python dashboard. */
function bb_local_date($value): string
{
    $date = bb_parse_iso($value);
    return $date ? $date->setTimezone(new DateTimeZone(BB_DISPLAY_TIMEZONE))->format('d M Y, h:i A') : (string) ($value ?? '');
}

/** "30 Sep, 06:03 PM" in India time, for tight spaces. */
function bb_local_date_short($value): string
{
    $date = bb_parse_iso($value);
    return $date ? $date->setTimezone(new DateTimeZone(BB_DISPLAY_TIMEZONE))->format('d M, h:i A') : (string) ($value ?? '');
}

function bb_local_day($value): string
{
    $date = $value instanceof DateTimeInterface ? $value : bb_parse_iso($value);
    return $date ? $date->setTimezone(new DateTimeZone(BB_DISPLAY_TIMEZONE))->format('Y-m-d') : '';
}

// ---------------------------------------------------------- name matching
/** Lowercase, replace non-breaking spaces and collapse whitespace. */
function bb_clean_text($value): string
{
    $text = (string) ($value ?? '');
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text)) ?? $text));
}

/** Display text with non-breaking spaces and doubled whitespace removed. */
function bb_clean_label($value): string
{
    $text = (string) ($value ?? '');
    return trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text)) ?? $text);
}

function bb_canonical_brand($brand): string
{
    $cleaned = bb_clean_label($brand);
    return BB_BRAND_NAMES[mb_strtolower($cleaned)] ?? $cleaned;
}

function bb_brand_key($brand): string
{
    return mb_strtolower(bb_canonical_brand($brand));
}

/**
 * "Samsung Galaxy S24+ 5G" -> "galaxy s24 plus 5g" (loose: "galaxy s24 plus").
 * The exact key keeps "5G" because some brands sell a 4G and a 5G phone
 * under one name; the loose key is only used when it points at a single
 * model on each platform.
 */
function bb_model_key($model, bool $loose = false): string
{
    static $memo = [];
    $memoKey = ($loose ? '1|' : '0|') . $model;
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }
    $text = str_replace('+', ' plus ', bb_clean_text($model));
    $words = preg_split('/\s+/', trim(preg_replace('/[^a-z0-9 ]+/', ' ', $text) ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    while (count($words) > 1 && in_array($words[0], BB_MODEL_PREFIX_WORDS, true)) {
        array_shift($words);
    }
    if ($loose) {
        $kept = array_values(array_filter($words, fn ($word) => !in_array($word, BB_NETWORK_WORDS, true)));
        $words = $kept ?: $words;
    }
    $key = implode(' ', $words);
    if (count($memo) < 60000) {
        $memo[$memoKey] = $key;
    }
    return $key;
}

/** [RAM GB, storage GB] from "8 GB/256 GB"; a single size is storage. */
function bb_variant_sizes($variant): array
{
    preg_match_all(BB_SIZE_PATTERN, (string) ($variant ?? ''), $matches, PREG_SET_ORDER);
    $sizes = [];
    foreach ($matches as [, $number, $unit]) {
        $factor = ['TB' => 1024.0, 'GB' => 1.0, 'MB' => 1 / 1024][strtoupper($unit)];
        $sizes[] = (float) $number * $factor;
    }
    if (!$sizes) {
        return [null, null];
    }
    if (count($sizes) === 1) {
        return [null, $sizes[0]];
    }
    return [$sizes[0], $sizes[count($sizes) - 1]];
}

/** Every word of the search must appear somewhere in the row. */
function bb_matches_search(array $entry, string $search): bool
{
    foreach (preg_split('/\s+/', bb_clean_text($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        if (!str_contains($entry['search_text'], $word)) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------- loading
function bb_status(PDO $pdo): array
{
    $row = $pdo->query('SELECT COUNT(*) AS count, MAX(id) AS max_id, MAX(date) AS max_date FROM ' . BB_TABLE)->fetch();
    return ['count' => (int) $row['count'], 'max_id' => (int) ($row['max_id'] ?? 0), 'max_date' => $row['max_date']];
}

/** The newest successful price of every (platform, brand, model, variant). */
function bb_query_latest(PDO $pdo): array
{
    return $pdo->query(
        'SELECT p.id, p.date, p.platform, p.brand, p.model, p.variant, p.price, p.price_value, p.currency, p.url, p.status, p.error
         FROM ' . BB_TABLE . ' p
         JOIN (SELECT platform, brand, model, variant, MAX(date) AS date FROM ' . BB_TABLE . ' WHERE status = \'ok\'
               GROUP BY platform, brand, model, variant) m
           ON m.platform = p.platform AND m.brand = p.brand AND m.model = p.model AND m.variant = p.variant AND m.date = p.date
         WHERE p.status = \'ok\'',
    )->fetchAll();
}

/** One page of the check history, newest first: [rows, total]. */
function bb_checks(PDO $pdo, array $filters, int $offset, int $limit): array
{
    $where = [];
    $params = [];
    if ($filters['brands']) {
        $where[] = 'LOWER(brand) IN (' . implode(',', array_fill(0, count($filters['brands']), '?')) . ')';
        foreach ($filters['brands'] as $brand) {
            $params[] = mb_strtolower($brand);
        }
    }
    if ($filters['status'] === 'ok' || $filters['status'] === 'error') {
        $where[] = 'status = ?';
        $params[] = $filters['status'];
    }
    if (in_array($filters['platform'], BB_PLATFORMS, true)) {
        $where[] = 'platform = ?';
        $params[] = $filters['platform'];
    }
    foreach (array_slice(preg_split('/\s+/', bb_clean_text($filters['search']), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $word) {
        // "!" escapes LIKE wildcards the same way in MySQL and SQLite.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
        $where[] = "(LOWER(brand) LIKE ? ESCAPE '!' OR LOWER(model) LIKE ? ESCAPE '!' OR LOWER(variant) LIKE ? ESCAPE '!' OR LOWER(platform) LIKE ? ESCAPE '!')";
        array_push($params, $like, $like, $like, $like);
    }
    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM ' . BB_TABLE . $sqlWhere);
    $count->execute($params);
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $stmt = $pdo->prepare(
        'SELECT date, platform, brand, model, variant, price, price_value, currency, url, status, error FROM ' . BB_TABLE . $sqlWhere
        . " ORDER BY date DESC, id DESC LIMIT $limit OFFSET $offset",
    );
    $stmt->execute($params);
    return [$stmt->fetchAll(), (int) $count->fetchColumn()];
}

// ------------------------------------------------------------ comparison
/**
 * Pair every Cashify variant with the same phone and storage on DOFY.
 * Models are paired by brand and exact model key first, then by the loose
 * key ("5G" dropped) when it is unambiguous on both sides. Inside a model
 * pair, variants are paired by storage, and by RAM when both sides state it.
 * Anything left over is listed for its one platform.
 */
function bb_compare_rows(array $latest): array
{
    $models = ['Cashify' => [], 'DOFY' => []];
    foreach ($latest as $row) {
        if (!isset($models[$row['platform'] ?? '']) || $row['price_value'] === null || $row['price_value'] === '') {
            continue;
        }
        $row['price_value'] = (float) $row['price_value'];
        [$row['ram'], $row['storage']] = bb_variant_sizes($row['variant'] ?? '');
        $key = bb_brand_key($row['brand']) . '|' . bb_model_key($row['model']);
        $models[$row['platform']][$key][] = $row;
    }
    $cashify = $models['Cashify'];
    $dofy = $models['DOFY'];

    $pairs = [];
    $openC = array_fill_keys(array_keys($cashify), true);
    $openD = array_fill_keys(array_keys($dofy), true);
    $shared = array_keys(array_intersect_key($cashify, $dofy));
    sort($shared, SORT_STRING);
    foreach ($shared as $key) {
        $pairs[] = [$key, $key];
        unset($openC[$key], $openD[$key]);
    }
    $loose = function (array $open): array {
        $out = [];
        foreach (array_keys($open) as $key) {
            [$brand, $model] = explode('|', (string) $key, 2);
            $out[$brand . '|' . bb_model_key($model, true)][] = (string) $key;
        }
        return $out;
    };
    $looseD = $loose($openD);
    foreach ($loose($openC) as $looseKey => $keys) {
        $others = $looseD[$looseKey] ?? [];
        if (count($keys) === 1 && count($others) === 1) {
            $pairs[] = [$keys[0], $others[0]];
            unset($openC[$keys[0]], $openD[$others[0]]);
        }
    }
    $restC = array_map('strval', array_keys($openC));
    $restD = array_map('strval', array_keys($openD));
    sort($restC, SORT_STRING);
    sort($restD, SORT_STRING);
    foreach ($restC as $key) {
        $pairs[] = [$key, null];
    }
    foreach ($restD as $key) {
        $pairs[] = [null, $key];
    }

    $bySize = fn ($a, $b) => [$a['storage'] ?? 0, $a['ram'] ?? 0] <=> [$b['storage'] ?? 0, $b['ram'] ?? 0];
    $entries = [];
    foreach ($pairs as [$cKey, $dKey]) {
        $cRows = $cKey !== null ? $cashify[$cKey] : [];
        $dRows = $dKey !== null ? $dofy[$dKey] : [];
        usort($cRows, $bySize);
        usort($dRows, $bySize);
        $group = (string) ($cKey ?? $dKey);
        $used = [];
        foreach ($cRows as $cRow) {
            $match = null;
            foreach ($dRows as $index => $dRow) {
                if (isset($used[$index]) || $dRow['storage'] !== $cRow['storage']) {
                    continue;
                }
                if ($cRow['ram'] !== null && $dRow['ram'] !== null && $cRow['ram'] !== $dRow['ram']) {
                    continue;
                }
                if ($match === null || ($dRow['ram'] === $cRow['ram'] && $dRows[$match]['ram'] !== $cRow['ram'])) {
                    $match = $index;
                }
            }
            if ($match !== null) {
                $used[$match] = true;
            }
            $entries[] = bb_make_entry($group, $cRow, $match !== null ? $dRows[$match] : null);
        }
        foreach ($dRows as $index => $dRow) {
            if (!isset($used[$index])) {
                $entries[] = bb_make_entry($group, null, $dRow);
            }
        }
    }
    return $entries;
}

function bb_make_entry(string $group, ?array $cashify, ?array $dofy): array
{
    $first = $cashify ?? $dofy;
    $variants = [];
    foreach ([$cashify, $dofy] as $row) {
        if ($row && ($row['variant'] ?? '') !== '') {
            $variants[] = (string) $row['variant'];
        }
    }
    // The more specific name wins: "4 GB/128 GB" over "128 GB".
    $variant = '';
    $bestRank = [-1, -1];
    foreach ($variants as $candidate) {
        $rank = [preg_match_all(BB_SIZE_PATTERN, $candidate), mb_strlen($candidate)];
        if ($rank > $bestRank) {
            [$variant, $bestRank] = [$candidate, $rank];
        }
    }
    $cValue = $cashify ? $cashify['price_value'] : null;
    $dValue = $dofy ? $dofy['price_value'] : null;
    $best = $gap = $gapPct = null;
    if ($cValue !== null && $dValue !== null) {
        $gap = abs($cValue - $dValue);
        $best = $gap == 0 ? 'Same' : ($cValue > $dValue ? 'Cashify' : 'DOFY');
        $low = min($cValue, $dValue);
        $gapPct = $low ? round(100 * $gap / $low, 1) : null;
    }
    $dates = [];
    foreach ([$cashify, $dofy] as $row) {
        if ($row) {
            $dates[] = (string) $row['date'];
        }
    }
    $ram = null;
    foreach ([$cashify, $dofy] as $row) {
        if ($row && $row['ram'] !== null) {
            $ram = $row['ram'];
            break;
        }
    }
    $names = [];
    foreach ([$cashify, $dofy] as $row) {
        if ($row) {
            $names[] = $row['model'];
        }
    }
    $entry = [
        'group' => $group,
        'brand' => bb_canonical_brand($first['brand']),
        'brand_key' => bb_brand_key($first['brand']),
        'model' => bb_clean_label($first['model']),
        'model_key' => explode('|', $group, 2)[1] ?? '',
        'variant' => $variant,
        'storage' => $first['storage'],
        'ram' => $ram,
        'cashify' => $cashify,
        'dofy' => $dofy,
        'cashify_value' => $cValue,
        'dofy_value' => $dValue,
        'best' => $best,
        'gap' => $gap,
        'gap_pct' => $gapPct,
        'best_value' => max(array_filter([$cValue, $dValue], fn ($v) => $v !== null)),
        'date' => max($dates),
        'platforms' => $cashify && $dofy ? 'both' : ($cashify ? 'cashify' : 'dofy'),
    ];
    $entry['day'] = bb_local_day($entry['date']);
    $entry['search_text'] = bb_clean_text($entry['brand'] . ' ' . implode(' ', $names) . ' ' . $variant . ' ' . implode(' ', $variants));
    return $entry;
}

function bb_compare_filter(array $entry, string $compare): bool
{
    return match ($compare) {
        'both' => $entry['platforms'] === 'both',
        'cashify_higher' => $entry['best'] === 'Cashify',
        'dofy_higher' => $entry['best'] === 'DOFY',
        'cashify_only' => $entry['platforms'] === 'cashify',
        'dofy_only' => $entry['platforms'] === 'dofy',
        default => true,
    };
}

/**
 * How many prices the most recent scrape refreshed, and when. Counting back
 * from the newest price, rows belong to the same scrape while consecutive
 * check times are less than $gapMinutes apart.
 */
function bb_last_update(array $latest, int $gapMinutes = 15): array
{
    $stamps = [];
    foreach ($latest as $row) {
        $date = bb_parse_iso($row['date'] ?? null);
        if ($date) {
            $stamps[] = $date->getTimestamp();
        }
    }
    if (!$stamps) {
        return ['count' => 0, 'date' => null];
    }
    rsort($stamps);
    $count = 1;
    for ($i = 1, $n = count($stamps); $i < $n; $i++) {
        if ($stamps[$i - 1] - $stamps[$i] > $gapMinutes * 60) {
            break;
        }
        $count++;
    }
    return ['count' => $count, 'date' => gmdate('Y-m-d\TH:i:s+00:00', $stamps[0])];
}

/**
 * Comparison rows plus the picker lists, cached on disk until the table
 * changes (row count or max id), so a page load is one cheap status query
 * plus a file read.
 */
function bb_load_price_data(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $pdo = bb_db();
    $status = bb_status($pdo);
    $stamp = 'v1|' . $status['count'] . '|' . $status['max_id'];
    $cacheFile = BB_CACHE_DIR . '/latest.ser';
    if (is_file($cacheFile)) {
        $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]);
        if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp) {
            return $data = $cached;
        }
    }

    $latest = bb_query_latest($pdo);
    $entries = bb_compare_rows($latest);
    $modelOptions = array_values(array_unique(array_column($entries, 'model')));
    usort($modelOptions, 'strcasecmp');
    $brands = array_values(array_unique(array_merge(BB_SCRAPE_BRANDS, array_column($entries, 'brand'))));
    usort($brands, 'strcasecmp');

    $data = [
        'stamp' => $stamp, 'source' => bb_db_label(), 'total_rows' => $status['count'], 'entries' => $entries,
        'last_update' => bb_last_update($latest),
        'brands' => $brands, 'model_options' => $modelOptions, 'suggestions' => array_slice($modelOptions, 0, 800),
    ];
    if (is_dir(BB_CACHE_DIR) || @mkdir(BB_CACHE_DIR, 0755, true)) {
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, serialize($data)) !== false) {
            @rename($tmp, $cacheFile);
        }
    }
    return $data;
}

function bb_sort_value(array $entry, string $sort)
{
    $missing = -INF;
    return match ($sort) {
        'brand' => [$entry['brand_key'], bb_clean_text($entry['model']), $entry['storage'] ?? 0],
        'variant' => [$entry['storage'] ?? 0, $entry['ram'] ?? 0],
        'cashify' => $entry['cashify_value'] ?? $missing,
        'dofy' => $entry['dofy_value'] ?? $missing,
        'gap' => $entry['gap'] ?? $missing,
        'date' => (string) ($entry['date'] ?? ''),
        default => [bb_clean_text($entry['model']), $entry['brand_key'], $entry['storage'] ?? 0, $entry['ram'] ?? 0],
    };
}

/** Tag consecutive rows of one model so the page can fold its variants together. */
function bb_group_by_model(array $entries): array
{
    $entries = array_values($entries);
    $count = count($entries);
    $grouped = [];
    $groupNumber = 0;
    for ($i = 0; $i < $count;) {
        $j = $i;
        while ($j < $count && $entries[$j]['group'] === $entries[$i]['group']) {
            $j++;
        }
        $platforms = array_unique(array_column(array_slice($entries, $i, $j - $i), 'platforms'));
        sort($platforms);
        $coverage = in_array('both', $platforms, true) || $platforms === ['cashify', 'dofy'] ? 'Cashify + DOFY'
            : ($platforms === ['cashify'] ? 'Cashify only' : 'DOFY only');
        for ($k = $i; $k < $j; $k++) {
            $grouped[] = [
                'row' => $entries[$k], 'groupStart' => $k === $i, 'groupSize' => $j - $i, 'groupId' => 'g' . $groupNumber,
                'coverage' => $coverage,
            ];
        }
        $groupNumber++;
        $i = $j;
    }
    return $grouped;
}

function bb_page_info(int $total, int $page, int $perPage, int $window = 2): array
{
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = min(max($page, 1), $totalPages);
    $start = ($page - 1) * $perPage;
    $end = min($start + $perPage, $total);
    $numbers = [1 => true, $totalPages => true];
    for ($p = $page - $window; $p <= $page + $window; $p++) {
        if ($p >= 1 && $p <= $totalPages) {
            $numbers[$p] = true;
        }
    }
    $pageNumbers = array_keys($numbers);
    sort($pageNumbers);
    return [
        'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages,
        'slice_start' => $start, 'slice_end' => $end, 'start' => $total ? $start + 1 : 0, 'end' => $end,
        'has_prev' => $page > 1, 'has_next' => $page < $totalPages,
        'prev_page' => max(1, $page - 1), 'next_page' => min($totalPages, $page + 1), 'page_numbers' => $pageNumbers,
    ];
}

function bb_load_dashboard(array $options = []): array
{
    $search = trim((string) ($options['search'] ?? ''));
    $selectedBrands = $options['selectedBrands'] ?? [];
    $compare = (string) ($options['compare'] ?? '');
    $page = (int) ($options['page'] ?? 1);
    $perPage = (int) ($options['perPage'] ?? 100);
    $status = (string) ($options['status'] ?? '');
    $platform = (string) ($options['platform'] ?? '');
    $sort = (string) ($options['sort'] ?? 'model');
    $sortDir = (string) ($options['sortDir'] ?? 'asc');
    $paginate = $options['paginate'] ?? true;
    $withHistory = $options['withHistory'] ?? true;
    $historyPage = (int) ($options['historyPage'] ?? 1);
    $historyPerPage = (int) ($options['historyPerPage'] ?? BB_HISTORY_PER_PAGE_DEFAULT);

    $data = bb_load_price_data();
    $allEntries = $data['entries'];
    $wanted = array_flip(array_map('mb_strtolower', $selectedBrands));
    $entries = array_values(array_filter($allEntries, fn ($entry) => (!$wanted || isset($wanted[mb_strtolower($entry['brand'])]))
        && bb_compare_filter($entry, $compare)
        && bb_matches_search($entry, $search)));

    $sortKey = in_array($sort, BB_SORT_KEYS, true) ? $sort : 'model';
    $reverse = $sortDir === 'desc';
    usort($entries, function ($a, $b) use ($sortKey, $reverse) {
        $result = bb_sort_value($a, $sortKey) <=> bb_sort_value($b, $sortKey);
        return $reverse ? -$result : $result;
    });
    if (in_array($sortKey, ['model', 'brand', 'date'], true)) {
        // Keep every model's variants together, smallest storage first, in
        // the order the models first appear.
        $firstSeen = [];
        foreach ($entries as $entry) {
            $firstSeen[$entry['group']] ??= count($firstSeen);
        }
        usort($entries, fn ($a, $b) => [$firstSeen[$a['group']], $a['storage'] ?? 0, $a['ram'] ?? 0]
            <=> [$firstSeen[$b['group']], $b['storage'] ?? 0, $b['ram'] ?? 0]);
    }

    $total = count($entries);
    $perPage = in_array($perPage, BB_PER_PAGE_OPTIONS, true) ? $perPage : 100;
    if (!$paginate) {
        $perPage = max($total, 1);
        $page = 1;
    }
    $pagination = bb_page_info($total, $page, $perPage);
    $paged = bb_group_by_model(array_slice($entries, $pagination['slice_start'], $pagination['slice_end'] - $pagination['slice_start']));

    $history = [];
    $historyPagination = bb_page_info(0, 1, BB_HISTORY_PER_PAGE_DEFAULT);
    if ($withHistory) {
        if (!in_array($historyPerPage, BB_HISTORY_PER_PAGE_OPTIONS, true)) {
            $historyPerPage = BB_HISTORY_PER_PAGE_DEFAULT;
        }
        $filters = ['brands' => $selectedBrands, 'status' => $status, 'search' => $search, 'platform' => $platform];
        [$history, $totalChecks] = bb_checks(bb_db(), $filters, (max($historyPage, 1) - 1) * $historyPerPage, $historyPerPage);
        $historyPagination = bb_page_info($totalChecks, $historyPage, $historyPerPage);
        if (!$history && $totalChecks) {
            // The requested page is past the end; show the last one instead.
            [$history, $totalChecks] = bb_checks(bb_db(), $filters, $historyPagination['slice_start'], $historyPerPage);
        }
    }

    $shared = array_values(array_filter($entries, fn ($entry) => $entry['platforms'] === 'both'));
    $today = bb_local_day(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $updatedToday = 0;
    foreach ($allEntries as $entry) {
        if ($entry['day'] === $today) {
            $updatedToday++;
        }
    }
    return [
        'latest' => $paged,
        'history' => $history,
        'history_pagination' => $historyPagination,
        'brands' => $data['brands'],
        'per_page_options' => BB_PER_PAGE_OPTIONS,
        'history_per_page_options' => BB_HISTORY_PER_PAGE_OPTIONS,
        'suggestions' => $data['suggestions'],
        'model_options' => $data['model_options'],
        'sort' => $sortKey, 'sort_dir' => $reverse ? 'desc' : 'asc', 'selected_status' => $status,
        'selected_platform' => $platform, 'selected_brands' => $selectedBrands, 'selected_compare' => $compare,
        'pagination' => $pagination,
        'stats' => [
            'tracked' => $total,
            'models' => count(array_unique(array_column($entries, 'group'))),
            'brands' => count(array_unique(array_column($entries, 'brand'))),
            'both' => count($shared),
            'cashify_higher' => count(array_filter($shared, fn ($entry) => $entry['best'] === 'Cashify')),
            'dofy_higher' => count(array_filter($shared, fn ($entry) => $entry['best'] === 'DOFY')),
            'updated_today' => $updatedToday,
            'last_update' => $data['last_update'],
        ],
        'data_source' => $data['source'],
    ];
}

/** Comparison rows for an export plus a short name for the file. */
function bb_export_rows(string $scope, string $model, array $filters): array
{
    if ($scope === 'view') {
        $data = bb_load_dashboard([
            'search' => $filters['search'], 'selectedBrands' => $filters['brands'], 'compare' => $filters['compare'],
            'sort' => 'model', 'sortDir' => 'asc', 'paginate' => false, 'withHistory' => false,
        ]);
        return [array_column($data['latest'], 'row'), 'current_view'];
    }
    $data = bb_load_dashboard(['sort' => 'model', 'sortDir' => 'asc', 'paginate' => false, 'withHistory' => false]);
    $rows = array_column($data['latest'], 'row');
    if ($scope !== 'model' || trim($model) === '') {
        return [$rows, 'all_models'];
    }
    $wanted = bb_model_key($model);
    $chosen = array_values(array_filter($rows, fn ($row) => $row['model_key'] === $wanted));
    if (!$chosen) {
        $loose = bb_model_key($model, true);
        $chosen = array_values(array_filter($rows, fn ($row) => bb_model_key($row['model'], true) === $loose));
    }
    if (!$chosen) {
        $chosen = array_values(array_filter($rows, fn ($row) => bb_matches_search($row, $model)));
    }
    $label = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(bb_clean_label($model))) ?? '', '_') ?: 'model';
    return [$chosen, $label];
}
