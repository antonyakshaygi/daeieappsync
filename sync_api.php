<?php
/**
 * DAE Family multi-device sync API.
 *
 * IMPORTANT: this version expects the schema produced by migration.sql:
 *   - updated_at  (BIGINT)
 *   - is_deleted  (TINYINT)
 * while the Android JSON API continues to use updatedAt / isDeleted.
 *
 * Use HTTPS and replace SYNC_API_TOKEN before deployment.
 */

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

function now_millis() {
    return (int) floor(microtime(true) * 1000);
}

$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
if (!hash_equals(SYNC_API_TOKEN, $token)) {
    respond_error('Invalid or missing API token', 401);
}

/*
 * API field => database field.
 * Only the two sync metadata fields differ in the migrated schema.
 */
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
$table = $_GET['table'] ?? '';
if (!isset($TABLES[$table])) respond_error('Unknown table');
$fields = $TABLES[$table];

// -----------------------------------------------------------------------------
// PULL
// -----------------------------------------------------------------------------
if ($action === 'pull') {
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

    $select = [];
    foreach ($fields as $api => $db) {
        if ($api === $db) {
            $select[] = "`$db`";
        } else {
            $select[] = "`$db` AS `$api`";
        }
    }

    // Take the cursor BEFORE reading rows. The query is bounded by this cutoff,
    // so anything written after the cutoff is guaranteed to be picked up by the
    // next sync instead of being skipped by an advanced cursor.
    $cutoff = now_millis();
    $updatedDb = $fields['updatedAt'];
    $sql = "SELECT " . implode(', ', $select) .
           " FROM `$table` WHERE `$updatedDb` > ? AND `$updatedDb` <= ? ORDER BY `$updatedDb` ASC LIMIT 2000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$since, $cutoff]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    // The cursor is server time. Push assigns server time to accepted records,
    // preventing device clock differences from making rows invisible to another device.
    respond_success(['data' => $rows, 'serverTime' => $cutoff]);
}

// -----------------------------------------------------------------------------
// PUSH
// -----------------------------------------------------------------------------
if ($action === 'push') {
    $records = json_decode(file_get_contents('php://input'), true);
    if (!is_array($records)) respond_error('Expected a JSON array of records');

    $pdo->beginTransaction();
    try {
        $maxServerTime = now_millis();

        foreach ($records as $record) {
            foreach ($fields as $api => $_db) {
                if (!array_key_exists($api, $record)) {
                    throw new Exception("Missing field '$api' in a $table record");
                }
            }

            // Every accepted write gets an authoritative server timestamp.
            // This avoids device-clock differences and makes deletes/updates
            // visible to every other device on the next pull.
            $serverNow = now_millis();
            $maxServerTime = max($maxServerTime, $serverNow);

            $dbCols = array_values($fields);
            $placeholders = [];
            $params = [];

            foreach ($fields as $api => $db) {
                $ph = ':p_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $api);
                if ($api === 'updatedAt') {
                    $params[$ph] = $serverNow;
                } else {
                    $params[$ph] = $record[$api];
                }
                $placeholders[] = $ph;
            }

            $quotedCols = array_map(fn($c) => "`$c`", $dbCols);
            $updateClauses = [];
            foreach ($fields as $api => $db) {
                if ($api === 'id' || $api === 'updatedAt') continue;
                // Only replace the existing row when this incoming write is newer.
                $updateClauses[] = "`$db` = IF(VALUES(`updated_at`) > `$table`.`updated_at`, VALUES(`$db`), `$table`.`$db`)";
            }
            $updateClauses[] = "`updated_at` = IF(VALUES(`updated_at`) > `$table`.`updated_at`, VALUES(`updated_at`), `$table`.`updated_at`)";

            $sql = "INSERT INTO `$table` (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")\n" .
                   "ON DUPLICATE KEY UPDATE " . implode(', ', $updateClauses);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond_error('Push failed: ' . $e->getMessage(), 500);
    }

    respond_success(['serverTime' => $maxServerTime]);
}

respond_error('Unknown action');
