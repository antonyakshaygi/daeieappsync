<?php

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// Return clean JSON on fatal server errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fatal Error: ' . $error['message']]);
        exit;
    }
});

// Return clean JSON on uncaught exceptions
set_exception_handler(function ($e) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});
/**
 * DAE Family multi-device sync API (Render + Aiven MySQL Edition)
 *
 * Direct PDO connection using Render environment variables & Aiven SSL options.
 * Handles schema mapping: updatedAt -> updated_at, isDeleted -> is_deleted.
 */

// ---------------------------------------------------------------------------
// Configuration & Headers
// ---------------------------------------------------------------------------
define('SYNC_API_TOKEN', getenv('SYNC_API_TOKEN') ?: 'ecedc100821fe075045f25969059428');

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

function now_millis() {
    return (int) floor(microtime(true) * 1000);
}

// ---------------------------------------------------------------------------
// Database Connection (Aiven MySQL via Render Environment Variables)
// ---------------------------------------------------------------------------
$host   = getenv('DB_HOST') ?: '';
$port   = getenv('DB_PORT') ?: '';
$dbname = getenv('DB_NAME') ?: '';
$user   = getenv('DB_USER') ?: '';
$pass   = getenv('DB_PASS') ?: '';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    respond_error('Database connection failed: ' . $e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// Auth Verification
// ---------------------------------------------------------------------------
$headers = function_exists('getallheaders') ? getallheaders() : [];
$token = $_SERVER['HTTP_X_API_TOKEN'] 
    ?? $headers['X-Api-Token'] 
    ?? $headers['x-api-token'] 
    ?? $_GET['token'] 
    ?? '';

if (!hash_equals(SYNC_API_TOKEN, $token)) {
    respond_error('Invalid or missing API token', 401);
}

// ---------------------------------------------------------------------------
// Table Schema Mapping (API Key => Database Column Name)
// ---------------------------------------------------------------------------
$TABLES = [
    'users' => [
        'id' => 'id', 'username' => 'username', 'passwordHash' => 'passwordHash',
        'isAdmin' => 'isAdmin', 'banned' => 'banned', 'createdAt' => 'createdAt',
        'updatedAt' => 'updated_at', 'isDeleted' => 'is_deleted'
    ],
    'categories' => [
        'id' => 'id', 'name' => 'name', 'updatedAt' => 'updated_at', 'isDeleted' => 'is_deleted'
    ],
    'transactions' => [
        'id' => 'id', 'userId' => 'userId', 'date' => 'date', 'description' => 'description',
        'categoryId' => 'categoryId', 'type' => 'type', 'amount' => 'amount',
        'updatedAt' => 'updated_at', 'isDeleted' => 'is_deleted'
    ],
    'assets' => [
        'id' => 'id', 'userId' => 'userId', 'name' => 'name', 'purchaseDate' => 'purchaseDate',
        'value' => 'value', 'type' => 'type', 'serialNo' => 'serialNo', 'policyNo' => 'policyNo',
        'expiryDate' => 'expiryDate', 'attachmentPath' => 'attachmentPath',
        'updatedAt' => 'updated_at', 'isDeleted' => 'is_deleted'
    ],
    'tasks' => [
        'id' => 'id', 'userId' => 'userId', 'assignedToUserId' => 'assignedToUserId',
        'taskDescription' => 'taskDescription', 'dueDate' => 'dueDate', 'status' => 'status',
        'updatedAt' => 'updated_at', 'isDeleted' => 'is_deleted'
    ],
    'task_comments' => [
        'id' => 'id', 'taskId' => 'taskId', 'userId' => 'userId', 'commentText' => 'commentText',
        'createdAt' => 'createdAt', 'updatedAt' => 'updated_at', 'isDeleted' => 'is_deleted'
    ],
];

$action = $_GET['action'] ?? '';
$table  = $_GET['table'] ?? '';

if (!isset($TABLES[$table])) {
    respond_error('Unknown table');
}
$fields = $TABLES[$table];

// ---------------------------------------------------------------------------
// PULL ACTION
// ---------------------------------------------------------------------------
if ($action === 'pull') {
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

    $select = [];
    foreach ($fields as $api => $db) {
        $select[] = ($api === $db) ? "`$db`" : "`$db` AS `$api`";
    }

    $cutoff = now_millis();
    $updatedDb = $fields['updatedAt'];
    
    $sql = "SELECT " . implode(', ', $select) .
           " FROM `$table` WHERE `$updatedDb` > ? AND `$updatedDb` <= ? ORDER BY `$updatedDb` ASC LIMIT 2000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$since, $cutoff]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        foreach (['isAdmin', 'banned', 'isDeleted'] as $boolCol) {
            if (array_key_exists($boolCol, $row)) $row[$boolCol] = (bool)$row[$boolCol];
        }
        foreach (['createdAt', 'updatedAt'] as $longCol) {
            if (array_key_exists($longCol, $row)) $row[$longCol] = (int)$row[$longCol];
        }
        foreach (['amount', 'value'] as $decimalCol) {
            if (array_key_exists($decimalCol, $row)) $row[$decimalCol] = (float)$row[$decimalCol];
        }
    }
    unset($row);

    respond_success(['data' => $rows, 'serverTime' => $cutoff]);
}

// ---------------------------------------------------------------------------
// PUSH ACTION
// ---------------------------------------------------------------------------
if ($action === 'push') {
    $records = json_decode(file_get_contents('php://input'), true);
    if (!is_array($records)) {
        respond_error('Expected a JSON array of records');
    }

    $pdo->beginTransaction();
    try {
        $maxServerTime = now_millis();

        foreach ($records as $record) {
            foreach ($fields as $api => $_db) {
                if (!array_key_exists($api, $record)) {
                    throw new Exception("Missing field '$api' in a $table record");
                }
            }

            $serverNow = now_millis();
            $maxServerTime = max($maxServerTime, $serverNow);

            $dbCols = array_values($fields);
            $placeholders = [];
            $params = [];

            foreach ($fields as $api => $db) {
                $ph = ':p_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $api);
                $val = $record[$api];

                // Type Sanitization to comply with MySQL 8 Strict Mode
                if ($api === 'updatedAt') {
                    $val = $serverNow;
                } elseif (in_array($api, ['isAdmin', 'banned', 'isDeleted'])) {
                    $val = ($val === '' || $val === null) ? 0 : (int)(bool)$val;
                } elseif (in_array($api, ['createdAt', 'dueDate', 'date', 'purchaseDate', 'expiryDate'])) {
                    if ($val === '') $val = null;
                }

                $params[$ph] = $val;
                $placeholders[] = $ph;
            }

            $quotedCols = array_map(fn($c) => "`$c`", $dbCols);
            $updateClauses = [];
            
            foreach ($fields as $api => $db) {
                if ($api === 'id' || $api === 'updatedAt') continue;
                // Qualified table references prevent type coercion errors during UPSERT
                $updateClauses[] = "`$db` = IF(VALUES(`updated_at`) >= `$table`.`updated_at`, VALUES(`$db`), `$table`.`$db`)";
            }
            $updateClauses[] = "`updated_at` = IF(VALUES(`updated_at`) >= `$table`.`updated_at`, VALUES(`updated_at`), `$table`.`updated_at`)";

            $sql = "INSERT INTO `$table` (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")\n" .
                   "ON DUPLICATE KEY UPDATE " . implode(', ', $updateClauses);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respond_error('Push failed: ' . $e->getMessage(), 500);
    }

    respond_success(['serverTime' => $maxServerTime]);
}

respond_error('Unknown action');
