<?php
/**
 * Buyback price storage API for Hostinger Business (PHP + MySQL).
 *
 * Upload this folder to public_html/api (or any folder), copy
 * config.example.php to config.php and fill it in. The table is created on
 * first use.
 *
 * Routes (all JSON, all except /health need an X-API-Key header):
 *   GET  /health                        liveness check
 *   GET  /status                        {count, max_id, max_date}
 *   GET  /prices?after_id=0&limit=5000  rows with id > after_id, oldest first
 *   POST /prices  {"rows":[...]}        bulk insert; duplicates are ignored
 *   GET  /latest                        newest successful price of every
 *                                       (platform, brand, model, variant)
 *   GET  /checks?offset=0&limit=25      check history, newest first, with
 *        &status=ok|error&platform=Cashify|DOFY&q=words&brand[]=Apple
 *                                       {rows, total}
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MAX_ROWS_PER_REQUEST = 5000;
const TABLE = 'buyback_prices';
const ROW_FIELDS = ['date', 'platform', 'brand', 'model', 'variant', 'price', 'price_value', 'currency', 'url', 'status', 'error'];

function respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    respond(500, ['error' => 'config.php is missing; copy config.example.php and fill it in']);
}
$config = require $configFile;

// ---------------------------------------------------------------- routing
// All routes are a single word, so the last segment of the path is enough.
// This does not depend on SCRIPT_NAME or on where the folder is installed,
// which differ between Apache, LiteSpeed and PHP's built-in server.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = strtolower(basename(trim($uri, '/')));
if ($route === '' || $route === 'index.php' || $route === 'api') {
    $route = '';
}
if (isset($_GET['route']) && is_string($_GET['route'])) {
    $route = strtolower(trim($_GET['route'], '/'));
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($route === 'health') {
    respond(200, ['status' => 'ok']);
}

// ------------------------------------------------------------------- auth
$expectedKey = (string) ($config['api_key'] ?? '');
$givenKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
    respond(401, ['error' => 'Missing or invalid X-API-Key header']);
}

// --------------------------------------------------------------- database
function normalizeDsn(string $dsn, string $user): string
{
    // Accept "mysql:host=localhost;u123_db;charset=utf8mb4" (a name without
    // the dbname= label) and fill in dbname from the user name when missing,
    // which is Hostinger's usual naming.
    if (!str_starts_with($dsn, 'mysql:')) {
        return $dsn;
    }
    $parts = array_filter(array_map('trim', explode(';', substr($dsn, strlen('mysql:')))), 'strlen');
    $pairs = [];
    $bare = null;
    foreach ($parts as $part) {
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

try {
    $pdo = new PDO(normalizeDsn((string) $config['dsn'], (string) ($config['user'] ?? '')), (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    respond(500, ['error' => 'Database connection failed: ' . $e->getMessage()]);
}
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$isMysql = $driver === 'mysql';

function ensureSchema(PDO $pdo, bool $isMysql): void
{
    if ($isMysql) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS buyback_prices (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                date        VARCHAR(40)  NOT NULL,
                platform    VARCHAR(20)  NOT NULL,
                brand       VARCHAR(100) NOT NULL,
                model       VARCHAR(191) NOT NULL,
                variant     VARCHAR(100) NOT NULL DEFAULT '',
                price       VARCHAR(255) NULL,
                price_value DOUBLE NULL,
                currency    VARCHAR(10) NULL,
                url         TEXT NOT NULL,
                status      VARCHAR(20) NOT NULL,
                error       TEXT NULL,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_observation (date, platform, brand, model, variant),
                KEY idx_lookup (platform, brand, model, variant, date),
                KEY idx_status_date (status, date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    } else {
        // SQLite is only used for local testing of this API.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS buyback_prices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT NOT NULL, platform TEXT NOT NULL, brand TEXT NOT NULL, model TEXT NOT NULL,
                variant TEXT NOT NULL DEFAULT '', price TEXT, price_value REAL, currency TEXT, url TEXT NOT NULL,
                status TEXT NOT NULL, error TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (date, platform, brand, model, variant)
            )
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lookup ON buyback_prices (platform, brand, model, variant, date)');
    }
}

try {
    ensureSchema($pdo, $isMysql);
} catch (PDOException $e) {
    respond(500, ['error' => 'Could not create the buyback_prices table: ' . $e->getMessage()]);
}

/** Numbers as numbers in the JSON, like the scraper sent them. */
function typedRows(array $rows): array
{
    foreach ($rows as &$r) {
        if (isset($r['id'])) {
            $r['id'] = (int) $r['id'];
        }
        $r['price_value'] = $r['price_value'] === null ? null : (float) $r['price_value'];
    }
    unset($r);
    return $rows;
}

/** WHERE clause for /checks; dashboard.py builds the same one for SQLite. */
function checksWhere(array $query): array
{
    $where = [];
    $params = [];
    $brands = $query['brand'] ?? [];
    $brands = array_values(array_filter(array_map(fn ($b) => mb_strtolower(trim((string) $b)), is_array($brands) ? $brands : [$brands]), 'strlen'));
    if ($brands) {
        $where[] = 'LOWER(brand) IN (' . implode(',', array_fill(0, count($brands), '?')) . ')';
        array_push($params, ...$brands);
    }
    $status = (string) ($query['status'] ?? '');
    if ($status === 'ok' || $status === 'error') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $platform = (string) ($query['platform'] ?? '');
    if ($platform === 'Cashify' || $platform === 'DOFY') {
        $where[] = 'platform = ?';
        $params[] = $platform;
    }
    $words = preg_split('/\s+/u', mb_strtolower(trim((string) ($query['q'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (array_slice($words, 0, 6) as $word) {
        // "!" escapes LIKE wildcards the same way in MySQL and SQLite.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
        $where[] = "(LOWER(brand) LIKE ? ESCAPE '!' OR LOWER(model) LIKE ? ESCAPE '!' OR LOWER(variant) LIKE ? ESCAPE '!' OR LOWER(platform) LIKE ? ESCAPE '!')";
        array_push($params, $like, $like, $like, $like);
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

// ----------------------------------------------------------------- routes
try {
    if ($route === 'status' && $method === 'GET') {
        $row = $pdo->query('SELECT COUNT(*) AS count, MAX(id) AS max_id, MAX(date) AS max_date FROM ' . TABLE)->fetch();
        respond(200, [
            'count' => (int) $row['count'],
            'max_id' => $row['max_id'] === null ? 0 : (int) $row['max_id'],
            'max_date' => $row['max_date'],
        ]);
    }

    if ($route === 'latest' && $method === 'GET') {
        $rows = $pdo->query(
            'SELECT p.id, p.date, p.platform, p.brand, p.model, p.variant, p.price, p.price_value, p.currency, p.url, p.status, p.error
             FROM ' . TABLE . ' p
             JOIN (SELECT platform, brand, model, variant, MAX(date) AS date FROM ' . TABLE . ' WHERE status = \'ok\'
                   GROUP BY platform, brand, model, variant) m
               ON m.platform = p.platform AND m.brand = p.brand AND m.model = p.model AND m.variant = p.variant AND m.date = p.date
             WHERE p.status = \'ok\'',
        )->fetchAll();
        respond(200, ['rows' => typedRows($rows)]);
    }

    if ($route === 'checks' && $method === 'GET') {
        [$where, $params] = checksWhere($_GET);
        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 25)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . TABLE . $where);
        $count->execute($params);
        $stmt = $pdo->prepare(
            'SELECT date, platform, brand, model, variant, price, price_value, currency, url, status, error FROM ' . TABLE . $where
            . " ORDER BY date DESC, id DESC LIMIT $limit OFFSET $offset",
        );
        $stmt->execute($params);
        respond(200, ['rows' => typedRows($stmt->fetchAll()), 'total' => (int) $count->fetchColumn()]);
    }

    if ($route === 'prices' && $method === 'GET') {
        $afterId = max(0, (int) ($_GET['after_id'] ?? 0));
        $limit = (int) ($_GET['limit'] ?? MAX_ROWS_PER_REQUEST);
        $limit = max(1, min(MAX_ROWS_PER_REQUEST, $limit));
        $stmt = $pdo->prepare(
            'SELECT id, ' . implode(', ', ROW_FIELDS) . ' FROM ' . TABLE . ' WHERE id > :after ORDER BY id LIMIT ' . $limit
        );
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = typedRows($stmt->fetchAll());
        $last = $rows ? $rows[count($rows) - 1]['id'] : $afterId;
        respond(200, ['rows' => $rows, 'next_after_id' => $last, 'has_more' => count($rows) === $limit]);
    }

    if ($route === 'prices' && $method === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body) || !isset($body['rows']) || !is_array($body['rows'])) {
            respond(400, ['error' => 'Body must be JSON like {"rows": [...]}']);
        }
        $rows = $body['rows'];
        if (count($rows) > MAX_ROWS_PER_REQUEST) {
            respond(413, ['error' => 'At most ' . MAX_ROWS_PER_REQUEST . ' rows per request']);
        }
        $verb = $isMysql ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO';
        $stmt = $pdo->prepare(
            $verb . ' ' . TABLE . ' (date, platform, brand, model, variant, price, price_value, currency, url, status, error)
             VALUES (:date, :platform, :brand, :model, :variant, :price, :price_value, :currency, :url, :status, :error)'
        );
        $inserted = 0;
        $pdo->beginTransaction();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $pdo->rollBack();
                respond(400, ['error' => "Row $index is not an object"]);
            }
            foreach (['date', 'platform', 'brand', 'model', 'url', 'status'] as $required) {
                if (!isset($row[$required]) || trim((string) $row[$required]) === '') {
                    $pdo->rollBack();
                    respond(400, ['error' => "Row $index is missing '$required'"]);
                }
            }
            $stmt->execute([
                ':date' => (string) $row['date'],
                ':platform' => mb_substr((string) $row['platform'], 0, 20),
                ':brand' => mb_substr((string) $row['brand'], 0, 100),
                ':model' => mb_substr((string) $row['model'], 0, 191),
                ':variant' => mb_substr((string) ($row['variant'] ?? ''), 0, 100),
                ':price' => isset($row['price']) ? mb_substr((string) $row['price'], 0, 255) : null,
                ':price_value' => isset($row['price_value']) && $row['price_value'] !== '' ? (float) $row['price_value'] : null,
                ':currency' => isset($row['currency']) ? mb_substr((string) $row['currency'], 0, 10) : null,
                ':url' => (string) $row['url'],
                ':status' => mb_substr((string) $row['status'], 0, 20),
                ':error' => isset($row['error']) ? (string) $row['error'] : null,
            ]);
            $inserted += $stmt->rowCount() > 0 ? 1 : 0;
        }
        $pdo->commit();
        respond(200, ['received' => count($rows), 'inserted' => $inserted]);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(500, ['error' => 'Database error: ' . $e->getMessage()]);
}

respond(404, ['error' => "Unknown route '$route'", 'routes' => ['GET /health', 'GET /status', 'GET /prices', 'POST /prices', 'GET /latest', 'GET /checks']]);
