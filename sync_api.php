<?php
/**
 * sync_api.php — REST sync endpoint for the DAE Family Android app.
 *
 * Place this file on Render.com Web Service. Reads DB config directly
 * from environment variables set on Render to connect to Aiven MySQL.
 *
 *   GET  sync_api.php?action=pull&table=transactions&since=1699999999000
 *   POST sync_api.php?action=push&table=transactions   (body: JSON array of rows)
 *
 * Auth: send header X-Api-Token or Authorization matching SYNC_API_TOKEN below.
 */

// ---------------------------------------------------------------------------
// Set your API Token here (must match the token in your Android app settings)
// ---------------------------------------------------------------------------
define('SYNC_API_TOKEN', 'ecedc100821fe075045f25969059428');

header('Content-Type: application/json; charset=utf-8');

function respond_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function respond_success($data = []) {
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

// ---- Database Connection via Environment Variables ----
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'defaultdb';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Disable SSL certificate verification so Aiven connects cleanly without requiring local ca.pem
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $pdoOptions);
} catch (PDOException $e) {
    respond_error('Database connection failed: ' . $e->getMessage(), 500);
}

// ---- Auth check (supports X-Api-Token, Authorization header, or query param) ----
$headers = getallheaders();
$token = $_SERVER['HTTP_X_API_TOKEN'] 
    ?? $headers['X-Api-Token'] 
    ?? $headers['x-api-token'] 
    ?? $_GET['token'] 
    ?? '';

if (!hash_equals(SYNC_API_TOKEN, $token)) {
    respond_error('Invalid or missing API token', 401);
}

// ---- Table whitelist + column definitions ----
$TABLES = [
    'users' => ['id', 'username', 'passwordHash', 'isAdmin', 'banned', 'createdAt', 'updatedAt', 'isDeleted'],
    'categories' => ['id', 'name', 'updatedAt', 'isDeleted'],
    'transactions' => ['id', 'userId', 'date', 'description', 'categoryId', 'type', 'amount', 'updatedAt', 'isDeleted'],
    'assets' => ['id', 'userId', 'name', 'purchaseDate', 'value', 'type', 'serialNo', 'policyNo', 'expiryDate', 'attachmentPath', 'updatedAt', 'isDeleted'],
    'tasks' => ['id', 'userId', 'assignedToUserId', 'taskDescription', 'dueDate', 'status', 'updatedAt', 'isDeleted'],
    'task_comments' => ['id', 'taskId', 'userId', 'commentText', 'createdAt', 'updatedAt', 'isDeleted'],
];

$action = $_GET['action'] ?? '';
$table = $_GET['table'] ?? '';

if (!array_key_exists($table, $TABLES)) {
    respond_error('Unknown table');
}
$columns = $TABLES[$table];

function now_millis() {
    return (int) round(microtime(true) * 1000);
}

// =============================================================================
// PULL — return rows changed after `since` (epoch millis)
// =============================================================================
if ($action === 'pull') {
    $since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
    $colList = implode(', ', $columns);

    $stmt = $pdo->prepare("SELECT $colList FROM `$table` WHERE updatedAt > ? ORDER BY updatedAt ASC LIMIT 2000");
    $stmt->execute([$since]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        foreach (['isAdmin', 'banned', 'isDeleted'] as $boolCol) {
            if (array_key_exists($boolCol, $row)) $row[$boolCol] = (bool) $row[$boolCol];
        }
        foreach (['createdAt', 'updatedAt'] as $longCol) {
            if (array_key_exists($longCol, $row)) $row[$longCol] = (int) $row[$longCol];
        }
        if (array_key_exists('amount', $row)) $row['amount'] = (float) $row['amount'];
        if (array_key_exists('value', $row)) $row['value'] = (float) $row['value'];
    }

    respond_success(['data' => $rows, 'serverTime' => now_millis()]);
}

// =============================================================================
// PUSH — upsert incoming rows, last-write-wins by updatedAt
// =============================================================================
if ($action === 'push') {
    $body = file_get_contents('php://input');
    $records = json_decode($body, true);
    if (!is_array($records)) {
        respond_error('Expected a JSON array of records');
    }

    $pdo->beginTransaction();
    try {
        foreach ($records as $record) {
            $cols = [];
            $placeholders = [];
            $updateClauses = [];
            $params = [];

            foreach ($columns as $col) {
                if (!array_key_exists($col, $record)) {
                    respond_error("Missing field '$col' in a $table record");
                }
                $cols[] = "`$col`";
                $placeholders[] = ":$col";
                $params[":$col"] = $record[$col];
                if ($col !== 'id') {
                    $updateClauses[] = "`$col` = IF(VALUES(`updatedAt`) >= `$col`, VALUES(`$col`), `$col`)";
                }
            }

            $sql = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")
                    ON DUPLICATE KEY UPDATE " . implode(', ', $updateClauses);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        respond_error('Push failed: ' . $e->getMessage(), 500);
    }

    respond_success(['serverTime' => now_millis()]);
}

respond_error('Unknown action');
