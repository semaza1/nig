<?php
/**
 * pages/admin/transactions_api.php
 *
 * Transactions API (Admin)
 * - CRUD (create/retrieve/update/delete)
 * - Proof download
 * - Loan payment splitting:
 *    When creating type=loan_principal, user enters TOTAL paid.
 *    System calculates interest due (full month rule) and splits:
 *      interest first, remaining to principal
 *    Saves up to 2 rows: loan_interest and loan_principal (same proof).
 *
 * DB schema expected (your current):
 * transactions:
 *  - transaction_id BIGINT PK AUTO_INCREMENT
 *  - tx_date DATETIME
 *  - user_id INT NULL
 *  - loan_id BIGINT NULL
 *  - account_id INT/BIGINT NULL
 *  - type ENUM('contribution','withdrawal','loan_principal','loan_interest','expense','other_income','other_out')
 *  - direction ENUM('IN','OUT')
 *  - amount DECIMAL
 *  - description VARCHAR(255)
 *  - proof_name, proof_type, proof_size, proof_hash, proof_data (LONGBLOB)
 *  - created_by, created_at
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

session_start();
ob_start();

/* ---------------- Core JSON helpers ---------------- */

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
    while (ob_get_level() > 0) { $buf .= (string)ob_get_clean(); }

    if (trim($buf) !== '') {
        $logFile = __DIR__ . '/transactions_debug.log';
        @file_put_contents($logFile, date('c') . " - BUFFERED OUTPUT:\n" . $buf . "\n\n", FILE_APPEND | LOCK_EX);
    }

    if ($err) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Fatal error', 'error' => $err]);
        exit;
    }
});

/* ---------------- DB + Auth ---------------- */

$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) send_json(['success' => false, 'message' => 'Database connection failed'], 500);

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    send_json(['success' => false, 'message' => 'Access denied'], 403);
}

$admin_user_id = (int)($_SESSION['user_id'] ?? 0);
if ($admin_user_id <= 0) send_json(['success' => false, 'message' => 'Missing admin session user_id'], 500);

/* ---------------- Generic helpers ---------------- */

function is_valid_date_ymd(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function is_valid_datetime(string $dt): bool {
    // Accept: YYYY-MM-DD HH:MM(:SS) or YYYY-MM-DDTHH:MM(:SS)
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $dt) !== 1) return false;
    $dt = str_replace('T', ' ', $dt);
    return is_valid_date_ymd(explode(' ', $dt)[0] ?? '');
}

/* ---------------- Domain helpers ---------------- */

function month_index(string $ymd): int {
    $y = (int)substr($ymd, 0, 4);
    $m = (int)substr($ymd, 5, 2);
    return $y * 12 + $m;
}

function normalize_monthly_rate($rate): float {
    $r = (float)$rate;
    // if stored as "18" meaning 18%, convert to 0.18
    if ($r > 1.0) $r = $r / 100.0;
    return max(0.0, $r);
}

/**
 * FULL MONTH interest once month begins:
 * months_due = asOfMonthIndex - startMonthIndex
 */
function calendar_months_due_from_start(string $startYmd, string $asOfYmd): int {
    return max(0, month_index($asOfYmd) - month_index($startYmd));
}

/**
 * Reducing: months due since last interest payment month (or start month).
 */
function calendar_months_due_since_last_interest(string $startYmd, ?string $lastInterestDatetime, string $asOfYmd): int {
    $from = $startYmd;
    if ($lastInterestDatetime) $from = substr($lastInterestDatetime, 0, 10);
    return max(0, month_index($asOfYmd) - month_index($from));
}

function require_user(mysqli $mysqli, int $user_id): array {
    $st = $mysqli->prepare("SELECT id, names, is_member FROM users WHERE id=? LIMIT 1");
    $st->bind_param('i', $user_id);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();
    return $u ? [true, $u, null] : [false, null, "User not found"];
}

function require_account(mysqli $mysqli, int $account_id): array {
    $st = $mysqli->prepare("SELECT account_id, name FROM accounts WHERE account_id=? LIMIT 1");
    $st->bind_param('i', $account_id);
    $st->execute();
    $a = $st->get_result()->fetch_assoc();
    $st->close();
    return $a ? [true, $a, null] : [false, null, "Account not found"];
}

function require_loan(mysqli $mysqli, int $loan_id): array {
    $st = $mysqli->prepare("
        SELECT loan_id, borrower_user_id, status, principal, monthly_interest_rate, interest_method, start_date, approved_at
        FROM loans WHERE loan_id=? LIMIT 1
    ");
    $st->bind_param('i', $loan_id);
    $st->execute();
    $l = $st->get_result()->fetch_assoc();
    $st->close();
    return $l ? [true, $l, null] : [false, null, "Loan not found"];
}

function normalize_type_direction(string $type, string $direction): array {
    $type = trim(strtolower($type));
    $direction = trim(strtoupper($direction));

    $allowed = ['contribution','withdrawal','loan_principal','loan_interest','expense','other_income','other_out'];
    if (!in_array($type, $allowed, true)) return [false, null, null, 'Invalid type'];

    // default if not provided
    if ($direction !== 'IN' && $direction !== 'OUT') {
        $direction = in_array($type, ['withdrawal','expense','other_out'], true) ? 'OUT' : 'IN';
    }

    // enforce direction
    if ($type === 'contribution' && $direction !== 'IN') return [false, null, null, 'Contribution must be IN'];
    if ($type === 'withdrawal' && $direction !== 'OUT') return [false, null, null, 'Withdrawal must be OUT'];
    if ($type === 'expense' && $direction !== 'OUT') return [false, null, null, 'Expense must be OUT'];
    if ($type === 'other_income' && $direction !== 'IN') return [false, null, null, 'Other income must be IN'];
    if ($type === 'other_out' && $direction !== 'OUT') return [false, null, null, 'Other out must be OUT'];
    if (in_array($type, ['loan_principal','loan_interest'], true) && $direction !== 'IN') {
        return [false, null, null, 'Loan payments must be IN'];
    }

    return [true, $type, $direction, null];
}

function fetch_tx(mysqli $mysqli, int $id): ?array {
    $sql = "SELECT
              t.transaction_id, t.tx_date, t.user_id, u.names AS user_name,
              t.loan_id,
              t.account_id, a.name AS account_name,
              t.type, t.direction, t.amount, t.description,
              t.proof_name, t.proof_type, t.proof_size, t.proof_hash,
              t.created_by, uc.names AS created_by_name,
              t.created_at
            FROM transactions t
            LEFT JOIN users u ON t.user_id=u.id
            LEFT JOIN accounts a ON t.account_id=a.account_id
            LEFT JOIN users uc ON t.created_by=uc.id
            WHERE t.transaction_id=? LIMIT 1";
    $st = $mysqli->prepare($sql);
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function update_proof(mysqli $mysqli, int $tx_id, string $blob, string $name, string $type, int $size, string $hash): void {
    $sql = "UPDATE transactions
            SET proof_name=?, proof_type=?, proof_size=?, proof_hash=?, proof_data=?
            WHERE transaction_id=?";
    $st = $mysqli->prepare($sql);
    $null = null;
    $st->bind_param('ssissi', $name, $type, $size, $hash, $null, $tx_id);
    $st->send_long_data(4, $blob);
    $st->execute();
    $st->close();
}

/**
 * Interest due as of tx_date using FULL MONTH rule:
 * Flat:
 *   accrued = principal * rate * months_due_from_start
 *   due = accrued - paid_interest
 * Reducing:
 *   due = outstanding_principal * rate * months_due_since_last_interest_payment_month
 */
function compute_interest_due_calendar(mysqli $mysqli, array $loanRow, int $loan_id, int $user_id, string $tx_datetime): float {
    $asOfDate = substr(str_replace('T', ' ', $tx_datetime), 0, 10);

    if ((int)($loanRow['borrower_user_id'] ?? 0) !== (int)$user_id) {
        throw new RuntimeException("Loan ownership mismatch");
    }
    if (($loanRow['status'] ?? '') !== 'approved') {
        throw new RuntimeException("Loan must be approved to accept payments");
    }

    $principal = (float)($loanRow['principal'] ?? 0);
    $rate = normalize_monthly_rate($loanRow['monthly_interest_rate'] ?? 0);
    $method = (string)($loanRow['interest_method'] ?? 'flat');

    // start_date is core for your rule
    $start = (string)($loanRow['start_date'] ?? '');
    if ($start === '' || $start === '0000-00-00') {
        $approved_at = (string)($loanRow['approved_at'] ?? '');
        $start = $approved_at ? substr($approved_at, 0, 10) : $asOfDate;
    }

    // paid interest
    $st = $mysqli->prepare("SELECT COALESCE(SUM(amount),0) AS paid_interest
                            FROM transactions WHERE loan_id=? AND type='loan_interest'");
    $st->bind_param('i', $loan_id);
    $st->execute();
    $paid_interest = (float)($st->get_result()->fetch_assoc()['paid_interest'] ?? 0);
    $st->close();

    // paid principal
    $st = $mysqli->prepare("SELECT COALESCE(SUM(amount),0) AS paid_principal
                            FROM transactions WHERE loan_id=? AND type='loan_principal'");
    $st->bind_param('i', $loan_id);
    $st->execute();
    $paid_principal = (float)($st->get_result()->fetch_assoc()['paid_principal'] ?? 0);
    $st->close();

    $outstanding = max(0.0, $principal - $paid_principal);
    if ($rate <= 0.0) return 0.0;

    if ($method === 'flat') {
        $months_due = calendar_months_due_from_start($start, $asOfDate);
        $accrued = $principal * $rate * $months_due;
        return round(max(0.0, $accrued - $paid_interest), 2);
    }

    // reducing: months since last interest payment month
    $st = $mysqli->prepare("SELECT MAX(tx_date) AS last_interest_date
                            FROM transactions WHERE loan_id=? AND type='loan_interest'");
    $st->bind_param('i', $loan_id);
    $st->execute();
    $last_interest_date = (string)($st->get_result()->fetch_assoc()['last_interest_date'] ?? '');
    $st->close();

    $months_due = calendar_months_due_since_last_interest($start, $last_interest_date ?: null, $asOfDate);
    $due = $outstanding * $rate * $months_due;
    return round(max(0.0, $due), 2);
}

/* =======================================================================
   PROOF DOWNLOAD (binary)
   ======================================================================= */

if (isset($_GET['action']) && $_GET['action'] === 'download_proof') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);

    $st = $mysqli->prepare("SELECT proof_name, proof_type, proof_data FROM transactions WHERE transaction_id=? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || empty($row['proof_data'])) send_json(['success' => false, 'message' => 'No proof found'], 404);

    while (ob_get_level() > 0) { @ob_end_clean(); }

    $fname = $row['proof_name'] ?: ("proof_tx_" . $id);
    $mime  = $row['proof_type'] ?: 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fname) . '"');
    echo $row['proof_data'];
    exit;
}

/* =======================================================================
   GET: LIST + SINGLE
   ======================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Single record
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);

        $row = fetch_tx($mysqli, $id);
        if (!$row) send_json(['success' => false, 'message' => 'Not found'], 404);

        send_json(['success' => true, 'data' => $row]);
    }

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
    $offset   = ($page - 1) * $per_page;

    $q = trim((string)($_GET['q'] ?? ''));

    $where = [];
    $params = [];
    $types = '';

    if ($q !== '') {
        $where[] = "(u.names LIKE ? OR t.type LIKE ? OR a.name LIKE ? OR t.description LIKE ?)";
        $like = "%{$q}%";
        $params[] = $like; $types .= 's';
        $params[] = $like; $types .= 's';
        $params[] = $like; $types .= 's';
        $params[] = $like; $types .= 's';
    }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Total count
    $countSql = "SELECT COUNT(*) AS cnt
                 FROM transactions t
                 LEFT JOIN users u ON t.user_id=u.id
                 LEFT JOIN accounts a ON t.account_id=a.account_id
                 $whereSql";
    $st = $mysqli->prepare($countSql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $total = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
    $st->close();

    // Rows
    $dataSql = "SELECT
                  t.transaction_id, t.tx_date, t.type, t.direction, t.amount,
                  t.user_id, u.names AS user_name,
                  t.loan_id,
                  t.account_id, a.name AS account_name,
                  uc.names AS created_by_name
                FROM transactions t
                LEFT JOIN users u ON t.user_id=u.id
                LEFT JOIN accounts a ON t.account_id=a.account_id
                LEFT JOIN users uc ON t.created_by=uc.id
                $whereSql
                ORDER BY t.tx_date DESC, t.transaction_id DESC
                LIMIT ? OFFSET ?";
    $st = $mysqli->prepare($dataSql);
    $params2 = $params;
    $types2 = $types . 'ii';
    $params2[] = $per_page;
    $params2[] = $offset;
    $st->bind_param($types2, ...$params2);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    send_json([
        'success' => true,
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page
    ]);
}

/* =======================================================================
   POST: CREATE / UPDATE / DELETE
   ======================================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'message' => 'Invalid request'], 400);
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
if (!in_array($action, ['create', 'update', 'delete'], true)) {
    send_json(['success' => false, 'message' => 'Invalid action'], 400);
}

/* ---------------- DELETE ---------------- */

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);

    $row = fetch_tx($mysqli, $id);
    if (!$row) send_json(['success' => false, 'message' => 'Not found'], 404);

    $st = $mysqli->prepare("DELETE FROM transactions WHERE transaction_id=?");
    $st->bind_param('i', $id);
    if (!$st->execute()) {
        $err = $st->error ?: $mysqli->error;
        $st->close();
        send_json(['success' => false, 'message' => 'Delete failed', 'error' => $err], 500);
    }
    $st->close();

    send_json(['success' => true, 'id' => $id]);
}

/* ---------------- CREATE / UPDATE common parsing ---------------- */

$id = (int)($_POST['id'] ?? 0);

$user_id_raw = ($_POST['user_id'] ?? '');
$loan_id_raw = ($_POST['loan_id'] ?? '');

$user_id = ($user_id_raw === '' ? null : (int)$user_id_raw);
$loan_id = ($loan_id_raw === '' ? null : (int)$loan_id_raw);

$account_id  = (int)($_POST['account_id'] ?? 0);
$type        = trim((string)($_POST['type'] ?? ''));
$direction   = trim((string)($_POST['direction'] ?? ''));
$amount      = (float)($_POST['amount'] ?? 0);
$description = trim((string)($_POST['description'] ?? ''));

$tx_date = trim((string)($_POST['tx_date'] ?? ''));
$tx_date = ($tx_date === '') ? date('Y-m-d H:i:s') : str_replace('T', ' ', $tx_date);
if (!is_valid_datetime($tx_date)) send_json(['success' => false, 'message' => 'tx_date must be valid datetime'], 400);

if ($account_id <= 0) send_json(['success' => false, 'message' => 'account_id is required'], 400);
if ($type === '' || $amount <= 0) send_json(['success' => false, 'message' => 'type and amount are required'], 400);

[$okA, $_acc, $msgA] = require_account($mysqli, $account_id);
if (!$okA) send_json(['success' => false, 'message' => $msgA], 400);

[$okTD, $typeN, $dirN, $msgTD] = normalize_type_direction($type, $direction);
if (!$okTD) send_json(['success' => false, 'message' => $msgTD], 400);

/* user rules */
$needsUser = in_array($typeN, ['contribution','withdrawal','loan_principal','loan_interest'], true);
$u = null;

if ($needsUser) {
    if (!$user_id || $user_id <= 0) send_json(['success' => false, 'message' => 'user_id required for this type'], 400);
    [$okU, $u, $msgU] = require_user($mysqli, (int)$user_id);
    if (!$okU) send_json(['success' => false, 'message' => $msgU], 400);

    if (in_array($typeN, ['contribution','withdrawal'], true) && (int)($u['is_member'] ?? 0) !== 1) {
        send_json(['success' => false, 'message' => 'Non-member cannot contribute/withdraw'], 400);
    }
} else {
    $user_id = null;
}

/* loan rules */
$loan_id_final = null;
$loanRow = null;

if (in_array($typeN, ['loan_principal','loan_interest'], true)) {
    if (!$loan_id || $loan_id <= 0) send_json(['success' => false, 'message' => 'loan_id required'], 400);

    [$okL, $loanRow, $msgL] = require_loan($mysqli, (int)$loan_id);
    if (!$okL) send_json(['success' => false, 'message' => $msgL], 400);

    if ((int)($loanRow['borrower_user_id'] ?? 0) !== (int)$user_id) {
        send_json(['success' => false, 'message' => 'Loan ownership mismatch'], 400);
    }
    if (($loanRow['status'] ?? '') !== 'approved') {
        send_json(['success' => false, 'message' => 'Loan must be approved to accept payments'], 400);
    }

    $loan_id_final = (int)$loan_id;
}

/* proof rules */
$has_file = (!empty($_FILES['proof_file']) && is_uploaded_file($_FILES['proof_file']['tmp_name']));
if ($action === 'create' && !$has_file) {
    send_json(['success' => false, 'message' => 'Proof file is required'], 400);
}

/* ---------------- CREATE ---------------- */

if ($action === 'create') {

    // Read proof once (used for 1 or 2 rows)
    $proof_name = (string)($_FILES['proof_file']['name'] ?? 'proof');
    $proof_type = (string)($_FILES['proof_file']['type'] ?? 'application/octet-stream');
    $proof_size = (int)($_FILES['proof_file']['size'] ?? 0);
    $proof_blob = file_get_contents($_FILES['proof_file']['tmp_name']);
    if ($proof_blob === false || $proof_blob === '') send_json(['success' => false, 'message' => 'Invalid proof file'], 400);
    $proof_hash = hash('sha256', $proof_blob);

    $mysqli->begin_transaction();
    try {

        // SPECIAL: loan_principal => TOTAL paid; split interest first then principal
        if ($typeN === 'loan_principal') {

            $interest_due = compute_interest_due_calendar($mysqli, $loanRow, (int)$loan_id_final, (int)$user_id, $tx_date);

            $total_paid = (float)$amount;
            $interest_paid  = min($total_paid, max(0.0, $interest_due));
            $principal_paid = max(0.0, $total_paid - $interest_paid);

            $tx_interest_id = null;
            $tx_principal_id = null;

            // 1) interest transaction if > 0
            if ($interest_paid > 0) {
                $sql = "INSERT INTO transactions
                        (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
                        VALUES (?, ?, ?, ?, 'loan_interest', 'IN', ?, 'Loan interest (auto-split)', ?)";
                $st = $mysqli->prepare($sql);
                $uid = (int)$user_id;
                $lid = (int)$loan_id_final;
                $st->bind_param('siiidi', $tx_date, $uid, $lid, $account_id, $interest_paid, $admin_user_id);
                $st->execute();
                $tx_interest_id = (int)$mysqli->insert_id;
                $st->close();

                update_proof($mysqli, $tx_interest_id, $proof_blob, $proof_name, $proof_type, $proof_size, $proof_hash);
            }

            // 2) principal transaction if > 0
            if ($principal_paid > 0) {
                $sql = "INSERT INTO transactions
                        (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
                        VALUES (?, ?, ?, ?, 'loan_principal', 'IN', ?, ?, ?)";
                $st = $mysqli->prepare($sql);
                $uid = (int)$user_id;
                $lid = (int)$loan_id_final;
                $descP = ($description !== '') ? $description : 'Loan principal (auto-split)';
                $st->bind_param('siiidsi', $tx_date, $uid, $lid, $account_id, $principal_paid, $descP, $admin_user_id);
                $st->execute();
                $tx_principal_id = (int)$mysqli->insert_id;
                $st->close();

                update_proof($mysqli, $tx_principal_id, $proof_blob, $proof_name, $proof_type, $proof_size, $proof_hash);
            }

            if (!$tx_interest_id && !$tx_principal_id) {
                throw new RuntimeException("Nothing to save (interest/principal both zero).");
            }

            $mysqli->commit();

            send_json([
                'success' => true,
                'data' => [
                    'interest_due'   => $interest_due,
                    'total_paid'     => $total_paid,
                    'interest_paid'  => $interest_paid,
                    'principal_paid' => $principal_paid,
                    'interest'       => $tx_interest_id ? fetch_tx($mysqli, $tx_interest_id) : null,
                    'principal'      => $tx_principal_id ? fetch_tx($mysqli, $tx_principal_id) : null,
                ]
            ]);
        }

        // NORMAL: single transaction create
        $sql = "INSERT INTO transactions
                (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $st = $mysqli->prepare($sql);
        $uid = $user_id;
        $lid = $loan_id_final;
        $st->bind_param('siiissdsi', $tx_date, $uid, $lid, $account_id, $typeN, $dirN, $amount, $description, $admin_user_id);
        $st->execute();
        $tx_id = (int)$mysqli->insert_id;
        $st->close();

        update_proof($mysqli, $tx_id, $proof_blob, $proof_name, $proof_type, $proof_size, $proof_hash);

        $mysqli->commit();
        send_json(['success' => true, 'data' => fetch_tx($mysqli, $tx_id)]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => 'Create failed', 'error' => $e->getMessage()], 500);
    }
}

/* ---------------- UPDATE ---------------- */

if ($action === 'update') {
    if ($id <= 0) send_json(['success' => false, 'message' => 'id is required for update'], 400);

    $existing = fetch_tx($mysqli, $id);
    if (!$existing) send_json(['success' => false, 'message' => 'Not found'], 404);

    // NOTE: Update does not auto-split or create extra rows.
    // It updates only THIS row safely.

    $mysqli->begin_transaction();
    try {
        $sql = "UPDATE transactions
                SET tx_date=?, user_id=?, loan_id=?, account_id=?, type=?, direction=?, amount=?, description=?
                WHERE transaction_id=?";
        $st = $mysqli->prepare($sql);
        $uid = $user_id;
        $lid = $loan_id_final;
        $st->bind_param('siiissdsi', $tx_date, $uid, $lid, $account_id, $typeN, $dirN, $amount, $description, $id);
        $st->execute();
        $st->close();

        if ($has_file) {
            $proof_name = (string)($_FILES['proof_file']['name'] ?? 'proof');
            $proof_type = (string)($_FILES['proof_file']['type'] ?? 'application/octet-stream');
            $proof_size = (int)($_FILES['proof_file']['size'] ?? 0);
            $proof_blob = file_get_contents($_FILES['proof_file']['tmp_name']);
            if ($proof_blob === false || $proof_blob === '') throw new RuntimeException('Invalid proof file');
            $proof_hash = hash('sha256', $proof_blob);

            update_proof($mysqli, $id, $proof_blob, $proof_name, $proof_type, $proof_size, $proof_hash);
        }

        $mysqli->commit();
        send_json(['success' => true, 'data' => fetch_tx($mysqli, $id)]);
    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => 'Update failed', 'error' => $e->getMessage()], 500);
    }
}

send_json(['success' => false, 'message' => 'Invalid action'], 400);