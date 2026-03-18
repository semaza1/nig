<?php
/**
 * pages/admin/expenses_api.php
 *
 * Expense-only backend
 * - Handles ONLY type = expense
 * - Supports:
 *   - list
 *   - single retrieve by id
 *   - create
 *   - update
 *   - delete
 *   - accounts list with balances
 *   - proof upload stored inside database (LONGBLOB)
 *   - inline proof view endpoint
 *   - proof download endpoint
 *
 * Notes:
 * - expenses are OUT transactions only
 * - expense has no user_id and no loan_id
 * - account balance is checked before create/update
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_start();
ob_start();

function send_json($data, int $code = 200): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

register_shutdown_function(function () {
    $err = error_get_last();
    $buf = '';

    while (ob_get_level() > 0) {
        $buf .= (string)ob_get_clean();
    }

    $logFile = __DIR__ . '/expenses_debug.log';

    if (trim($buf) !== '') {
        @file_put_contents(
            $logFile,
            date('c') . " - NON-JSON OUTPUT:\n" . $buf . "\n\n",
            FILE_APPEND | LOCK_EX
        );
    }

    if ($err) {
        $msg = ($err['message'] ?? 'Unknown error') . ' in ' . ($err['file'] ?? '') . ' on line ' . ($err['line'] ?? '');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error',
            'error'   => $msg,
            'debug_log' => 'pages/admin/expenses_debug.log'
        ]);
        exit;
    }

    if (trim($buf) !== '') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Server produced unexpected output. See debug log: pages/admin/expenses_debug.log'
        ]);
        exit;
    }
});

$mysqli = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/notifications_helper.php';

if (!$mysqli) send_json(['success' => false, 'message' => 'DB connection failed'], 500);
if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) send_json(['success' => false, 'message' => 'Access denied'], 403);

$admin_id = (int)($_SESSION['user_id'] ?? 0);
if ($admin_id <= 0) send_json(['success' => false, 'message' => 'Missing admin session user_id'], 500);

/* =========================================================
   Helpers
========================================================= */

function safe_datetime($s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;

    $s = str_replace('T', ' ', $s);

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) {
        return $s . ':00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) {
        return $s;
    }
    return null;
}

function money($x): float {
    return (float)number_format((float)$x, 2, '.', '');
}

function get_account_balance(mysqli $mysqli, int $account_id): float {
    if ($account_id <= 0) return 0.0;

    $sql = "
        SELECT
          COALESCE(SUM(CASE WHEN direction='IN'  THEN amount ELSE 0 END),0) -
          COALESCE(SUM(CASE WHEN direction='OUT' THEN amount ELSE 0 END),0) AS bal
        FROM transactions
        WHERE account_id = ?
    ";
    $st = $mysqli->prepare($sql);
    if (!$st) return 0.0;

    $st->bind_param('i', $account_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return (float)($row['bal'] ?? 0);
}

function allowed_proof_mimes(): array {
    return [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf'
    ];
}

function get_uploaded_proof(string $field = 'proof_file', bool $required = false): array {
    if (!isset($_FILES[$field])) {
        if ($required) return [false, null, 'Proof file is required'];
        return [true, null, null];
    }

    $file = $_FILES[$field];
    $errCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errCode === UPLOAD_ERR_NO_FILE) {
        if ($required) return [false, null, 'Proof file is required'];
        return [true, null, null];
    }

    if ($errCode !== UPLOAD_ERR_OK) {
        $msg = match ($errCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file is too large',
            UPLOAD_ERR_PARTIAL => 'File upload was incomplete',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded file',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by server extension',
            default => 'Upload failed'
        };
        return [false, null, $msg];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, null, 'Invalid uploaded file'];
    }

    $tmp  = $file['tmp_name'];
    $name = mb_substr(trim((string)($file['name'] ?? 'proof')), 0, 255);
    $size = (int)($file['size'] ?? 0);

    if ($size <= 0) {
        return [false, null, 'Uploaded file is empty'];
    }

    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($size > $maxSize) {
        return [false, null, 'File too large. Maximum allowed is 10 MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (finfo_file($finfo, $tmp) ?: 'application/octet-stream') : 'application/octet-stream';
    if ($finfo) finfo_close($finfo);

    if (!in_array($mime, allowed_proof_mimes(), true)) {
        return [false, null, 'Only JPG, PNG, GIF, WEBP, or PDF files are allowed'];
    }

    $blob = file_get_contents($tmp);
    if ($blob === false || $blob === '') {
        return [false, null, 'Could not read uploaded file'];
    }

    $hash = hash('sha256', $blob);

    return [true, [
        'name' => $name,
        'type' => $mime,
        'size' => $size,
        'blob' => $blob,
        'hash' => $hash,
    ], null];
}

function update_proof(mysqli $mysqli, int $tx_id, string $blob, string $name, string $type, int $size, string $hash): void {
    $sql = "UPDATE transactions
            SET proof_name=?, proof_type=?, proof_size=?, proof_hash=?, proof_data=?
            WHERE transaction_id=?";
    $st = $mysqli->prepare($sql);
    if (!$st) {
        throw new RuntimeException('Prepare proof update failed: ' . $mysqli->error);
    }

    // $null = null;
    $st->bind_param('ssissi', $name, $type, $size, $hash, $blob, $tx_id);
    $st->send_long_data(4, $blob);

    if (!$st->execute()) {
        $err = $st->error ?: $mysqli->error;
        $st->close();
        throw new RuntimeException('Proof update failed: ' . $err);
    }

    $st->close();
}

function clear_proof(mysqli $mysqli, int $tx_id): void {
    $sql = "UPDATE transactions
            SET proof_name=NULL, proof_type=NULL, proof_size=NULL, proof_hash=NULL, proof_data=NULL
            WHERE transaction_id=?";
    $st = $mysqli->prepare($sql);
    if (!$st) {
        throw new RuntimeException('Prepare clear proof failed: ' . $mysqli->error);
    }

    $st->bind_param('i', $tx_id);
    if (!$st->execute()) {
        $err = $st->error ?: $mysqli->error;
        $st->close();
        throw new RuntimeException('Clear proof failed: ' . $err);
    }

    $st->close();
}

function get_transaction_by_id(mysqli $mysqli, int $transaction_id): ?array {
    $st = $mysqli->prepare("
        SELECT
          t.transaction_id, t.tx_date,
          t.user_id, u.names AS user_name,
          t.loan_id,
          t.account_id, a.name AS account_name,
          t.type, t.direction, t.amount, t.description,
          t.proof_name, t.proof_type, t.proof_size, t.proof_hash,
          CASE WHEN t.proof_data IS NOT NULL THEN 1 ELSE 0 END AS has_proof,
          t.created_by, t.created_at
        FROM transactions t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN accounts a ON a.account_id = t.account_id
        WHERE t.transaction_id = ?
          AND t.type = 'expense'
        LIMIT 1
    ");
    if (!$st) return null;

    $st->bind_param('i', $transaction_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row && (int)($row['has_proof'] ?? 0) === 1) {
        $row['proof_view_url'] = 'expenses_api.php?action=view_proof&id=' . $transaction_id;
        $row['proof_download_url'] = 'expenses_api.php?action=download_proof&id=' . $transaction_id;
    }

    return $row ?: null;
}

/* =========================================================
   PROOF VIEW / DOWNLOAD
========================================================= */

if (isset($_GET['action']) && $_GET['action'] === 'download_proof') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid expense id'], 400);

    $st = $mysqli->prepare("
        SELECT proof_name, proof_type, proof_data
        FROM transactions
        WHERE transaction_id = ?
          AND type = 'expense'
        LIMIT 1
    ");
    if (!$st) send_json(['success' => false, 'message' => 'Prepare failed'], 500);

    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || empty($row['proof_data'])) {
        send_json(['success' => false, 'message' => 'No proof found'], 404);
    }

    while (ob_get_level() > 0) { @ob_end_clean(); }

    $fname = $row['proof_name'] ?: ("expense_proof_" . $id);
    $mime  = $row['proof_type'] ?: 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fname) . '"');
    echo $row['proof_data'];
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'view_proof') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid expense id'], 400);

    $st = $mysqli->prepare("
        SELECT proof_name, proof_type, proof_data
        FROM transactions
        WHERE transaction_id = ?
          AND type = 'expense'
        LIMIT 1
    ");
    if (!$st) send_json(['success' => false, 'message' => 'Prepare failed'], 500);

    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || empty($row['proof_data'])) {
        send_json(['success' => false, 'message' => 'No proof found'], 404);
    }

    while (ob_get_level() > 0) { @ob_end_clean(); }

    $mime = $row['proof_type'] ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . str_replace('"', '', ($row['proof_name'] ?: 'proof')) . '"');
    echo $row['proof_data'];
    exit;
}

/* =========================================================
   GET
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (isset($_GET['id'])) {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid expense id'], 400);

        $row = get_transaction_by_id($mysqli, $id);
        if (!$row) send_json(['success' => false, 'message' => 'Expense not found'], 404);

        send_json(['success' => true, 'data' => $row]);
    }

    if (isset($_GET['accounts'])) {
        $rs = $mysqli->query("SELECT account_id, name, type, account_number FROM accounts ORDER BY name ASC");
        $rows = [];

        if ($rs) {
            while ($r = $rs->fetch_assoc()) {
                $r['balance'] = money(get_account_balance($mysqli, (int)$r['account_id']));
                $rows[] = $r;
            }
        }

        send_json(['success' => true, 'data' => $rows]);
    }

    $page       = max(1, (int)($_GET['page'] ?? 1));
    $per_page   = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
    $q          = trim((string)($_GET['q'] ?? ''));
    $account_id = (int)($_GET['account_id'] ?? 0);

    $where  = " WHERE t.type = 'expense' ";
    $params = [];
    $types  = '';

    if ($q !== '') {
        $where .= " AND (t.description LIKE ? OR a.name LIKE ?) ";
        $like = "%{$q}%";
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    if ($account_id > 0) {
        $where .= " AND t.account_id = ? ";
        $params[] = $account_id;
        $types .= 'i';
    }

    $countSql = "
      SELECT COUNT(*) AS cnt
      FROM transactions t
      LEFT JOIN accounts a ON a.account_id = t.account_id
      $where
    ";
    $st = $mysqli->prepare($countSql);
    if (!$st) send_json(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error], 500);

    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $total = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
    $st->close();

    $offset = ($page - 1) * $per_page;

    $listSql = "
      SELECT
        t.transaction_id, t.tx_date,
        t.account_id, a.name AS account_name,
        t.type, t.direction, t.amount, t.description,
        t.proof_name, t.proof_type, t.proof_size,
        CASE WHEN t.proof_data IS NOT NULL THEN 1 ELSE 0 END AS has_proof,
        t.created_by, t.created_at
      FROM transactions t
      LEFT JOIN accounts a ON a.account_id = t.account_id
      $where
      ORDER BY t.tx_date DESC, t.transaction_id DESC
      LIMIT ? OFFSET ?
    ";
    $st = $mysqli->prepare($listSql);
    if (!$st) send_json(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error], 500);

    $params2 = $params;
    $types2  = $types . 'ii';
    $params2[] = $per_page;
    $params2[] = $offset;

    $st->bind_param($types2, ...$params2);
    $st->execute();
    $rs = $st->get_result();

    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        if ((int)($r['has_proof'] ?? 0) === 1) {
            $r['proof_view_url'] = 'expenses_api.php?action=view_proof&id=' . $r['transaction_id'];
            $r['proof_download_url'] = 'expenses_api.php?action=download_proof&id=' . $r['transaction_id'];
        }
        $rows[] = $r;
    }
    $st->close();

    send_json([
        'success'  => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page
    ]);
}

/* =========================================================
   POST
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'message' => 'Invalid request'], 400);
}

$action = (string)($_POST['action'] ?? '');

/* ---------------- CREATE EXPENSE ---------------- */

if ($action === 'create') {
    $account_id   = (int)($_POST['account_id'] ?? 0);
    $amount       = (float)($_POST['amount'] ?? 0);
    $tx_date      = safe_datetime($_POST['tx_date'] ?? '') ?: date('Y-m-d H:i:s');
    $description  = trim((string)($_POST['description'] ?? ''));

    if ($account_id <= 0) send_json(['success' => false, 'message' => 'Select account'], 400);
    if ($amount <= 0) send_json(['success' => false, 'message' => 'Invalid amount'], 400);

    [$proofOk, $proofData, $proofErr] = get_uploaded_proof('proof_file', true);
    if (!$proofOk) send_json(['success' => false, 'message' => $proofErr], 400);

    $bal = get_account_balance($mysqli, $account_id);
    if ($amount > $bal + 0.00001) {
        send_json(['success' => false, 'message' => 'Insufficient account balance. Balance=' . money($bal)], 400);
    }

    $mysqli->begin_transaction();
    try {
        $sql = "
          INSERT INTO transactions
          (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
          VALUES (?, NULL, NULL, ?, 'expense', 'OUT', ?, ?, ?)
        ";
        $st = $mysqli->prepare($sql);
        if (!$st) throw new Exception($mysqli->error);

        $st->bind_param('sidsi', $tx_date, $account_id, $amount, $description, $admin_id);
        if (!$st->execute()) {
            $err = $st->error ?: $mysqli->error;
            $st->close();
            throw new Exception($err);
        }

        $expense_id = (int)$mysqli->insert_id;
        $st->close();

        if ($proofData) {
            update_proof(
                $mysqli,
                $expense_id,
                $proofData['blob'],
                $proofData['name'],
                $proofData['type'],
                $proofData['size'],
                $proofData['hash']
            );
        }

        $mysqli->commit();

        notify_admins($mysqli, 'expense_recorded', "Expense (#TX-$expense_id) amount=" . number_format($amount, 2) . " Frw");

        send_json([
            'success' => true,
            'transaction_id' => $expense_id,
            'data' => get_transaction_by_id($mysqli, $expense_id)
        ]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => 'Failed to create expense', 'error' => $e->getMessage()], 500);
    }
}

/* ---------------- UPDATE EXPENSE ---------------- */

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Missing expense id'], 400);

    $existing = get_transaction_by_id($mysqli, $id);
    if (!$existing) send_json(['success' => false, 'message' => 'Expense not found'], 404);

    $account_id   = (int)($_POST['account_id'] ?? 0);
    $amount       = (float)($_POST['amount'] ?? 0);
    $tx_date      = safe_datetime($_POST['tx_date'] ?? '') ?: date('Y-m-d H:i:s');
    $description  = trim((string)($_POST['description'] ?? ''));
    $remove_proof = (int)($_POST['remove_proof'] ?? 0) === 1;

    if ($account_id <= 0) send_json(['success' => false, 'message' => 'Select account'], 400);
    if ($amount <= 0) send_json(['success' => false, 'message' => 'Invalid amount'], 400);

    [$proofOk, $proofData, $proofErr] = get_uploaded_proof('proof_file', false);
    if (!$proofOk) send_json(['success' => false, 'message' => $proofErr], 400);

    $oldImpact = 0.0;
    if ((int)($existing['account_id'] ?? 0) === $account_id) {
        $oldImpact = (float)($existing['amount'] ?? 0);
    }

    $bal = get_account_balance($mysqli, $account_id) + $oldImpact;
    if ($amount > $bal + 0.00001) {
        send_json(['success' => false, 'message' => 'Insufficient account balance. Balance=' . money($bal)], 400);
    }

    $mysqli->begin_transaction();
    try {
        $sql = "
          UPDATE transactions
          SET tx_date = ?,
              user_id = NULL,
              loan_id = NULL,
              account_id = ?,
              type = 'expense',
              direction = 'OUT',
              amount = ?,
              description = ?
          WHERE transaction_id = ?
            AND type = 'expense'
        ";
        $st = $mysqli->prepare($sql);
        if (!$st) throw new Exception($mysqli->error);

        $st->bind_param('sidsi', $tx_date, $account_id, $amount, $description, $id);
        if (!$st->execute()) {
            $err = $st->error ?: $mysqli->error;
            $st->close();
            throw new Exception($err);
        }
        $st->close();

        if ($remove_proof) {
            clear_proof($mysqli, $id);
        }

        if ($proofData) {
            update_proof(
                $mysqli,
                $id,
                $proofData['blob'],
                $proofData['name'],
                $proofData['type'],
                $proofData['size'],
                $proofData['hash']
            );
        }

        $mysqli->commit();

        send_json([
            'success' => true,
            'transaction_id' => $id,
            'data' => get_transaction_by_id($mysqli, $id)
        ]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => 'Failed to update expense', 'error' => $e->getMessage()], 500);
    }
}

/* ---------------- DELETE EXPENSE ---------------- */

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Missing expense id'], 400);

    $existing = get_transaction_by_id($mysqli, $id);
    if (!$existing) send_json(['success' => false, 'message' => 'Expense not found'], 404);

    $st = $mysqli->prepare("DELETE FROM transactions WHERE transaction_id = ? AND type = 'expense'");
    if (!$st) send_json(['success' => false, 'message' => $mysqli->error], 500);

    $st->bind_param('i', $id);
    if (!$st->execute()) {
        $err = $st->error ?: $mysqli->error;
        $st->close();
        send_json(['success' => false, 'message' => 'Delete failed', 'error' => $err], 500);
    }
    $st->close();

    send_json(['success' => true, 'transaction_id' => $id]);
}

send_json(['success' => false, 'message' => 'Invalid request'], 400);
?>