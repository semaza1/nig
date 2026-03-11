<?php
/**
 * pages/admin/transactions_api.php
 *
 * Updated:
 * - CRUD
 * - proof download
 * - borrower-wide loan payment allocation
 * - payment preview endpoint
 *
 * Loan repayment rule:
 *   - entered amount is TOTAL borrower payment
 *   - pay all unpaid interest first across borrower's approved/defaulted loans
 *   - then pay principal
 *   - continue to next loan if money remains
 *   - oldest loans first (start_date ASC, loan_id ASC)
 *
 * Interest rule:
 *   - first month interest is due immediately on approval
 *   - unpaid interest = initial_interest_due - paid_interest
 *
 * Transactions created:
 *   - loan_interest (IN)
 *   - loan_principal (IN)
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
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $dt) !== 1) return false;
    $dt = str_replace('T', ' ', $dt);
    return is_valid_date_ymd(explode(' ', $dt)[0] ?? '');
}

/* ---------------- Domain helpers ---------------- */

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
        FROM loans
        WHERE loan_id=? LIMIT 1
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

    if ($direction !== 'IN' && $direction !== 'OUT') {
        $direction = in_array($type, ['withdrawal','expense','other_out'], true) ? 'OUT' : 'IN';
    }

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

/* ---------------- Loan calculation helpers ---------------- */

function get_loan_row(mysqli $mysqli, int $loan_id): ?array {
    $st = $mysqli->prepare("
        SELECT loan_id, borrower_user_id, account_id, principal, monthly_interest_rate, interest_method,
               term_months, status, start_date, approved_at, end_date
        FROM loans
        WHERE loan_id=?
        LIMIT 1
    ");
    if (!$st) return null;
    $st->bind_param('i', $loan_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function get_loan_paid_principal(mysqli $mysqli, int $loan_id): float {
    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_paid
        FROM transactions
        WHERE loan_id=?
          AND type='loan_principal'
          AND direction='IN'
    ");
    if (!$st) return 0.0;
    $st->bind_param('i', $loan_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (float)($row['total_paid'] ?? 0);
}

function get_loan_paid_interest(mysqli $mysqli, int $loan_id): float {
    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_paid
        FROM transactions
        WHERE loan_id=?
          AND type='loan_interest'
          AND direction='IN'
    ");
    if (!$st) return 0.0;
    $st->bind_param('i', $loan_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (float)($row['total_paid'] ?? 0);
}

function get_loan_initial_interest_due(mysqli $mysqli, int $loan_id): float {
    $loan = get_loan_row($mysqli, $loan_id);
    if (!$loan) return 0.0;

    $status = (string)($loan['status'] ?? 'requested');
    if (!in_array($status, ['approved', 'defaulted', 'closed'], true)) {
        return 0.0;
    }

    $principal = (float)($loan['principal'] ?? 0);
    $rate      = (float)($loan['monthly_interest_rate'] ?? 0);

    if ($principal <= 0 || $rate <= 0) return 0.0;

    return round(($principal * $rate) / 100, 2);
}

function get_loan_unpaid_interest(mysqli $mysqli, int $loan_id): float {
    $due  = get_loan_initial_interest_due($mysqli, $loan_id);
    $paid = get_loan_paid_interest($mysqli, $loan_id);
    return (float)max(0, $due - $paid);
}

function get_loan_unpaid_principal(mysqli $mysqli, int $loan_id): float {
    $loan = get_loan_row($mysqli, $loan_id);
    if (!$loan) return 0.0;

    $status = (string)($loan['status'] ?? 'requested');
    if (!in_array($status, ['approved', 'defaulted', 'closed'], true)) {
        return 0.0;
    }

    $principal = (float)($loan['principal'] ?? 0);
    $paidPrincipal = get_loan_paid_principal($mysqli, $loan_id);

    return (float)max(0, $principal - $paidPrincipal);
}

function get_borrower_active_loans(mysqli $mysqli, int $user_id): array {
    $uid = (int)$user_id;

    $st = $mysqli->prepare("
        SELECT loan_id, borrower_user_id, account_id, principal, monthly_interest_rate, interest_method,
               term_months, status, start_date, approved_at, end_date
        FROM loans
        WHERE borrower_user_id=?
          AND status IN ('approved','defaulted','closed')
        ORDER BY
          CASE WHEN start_date IS NULL OR start_date='0000-00-00' THEN 1 ELSE 0 END ASC,
          start_date ASC,
          loan_id ASC
    ");
    if (!$st) return [];

    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $loanId = (int)$r['loan_id'];
        $r['paid_interest'] = get_loan_paid_interest($mysqli, $loanId);
        $r['paid_principal'] = get_loan_paid_principal($mysqli, $loanId);
        $r['unpaid_interest'] = get_loan_unpaid_interest($mysqli, $loanId);
        $r['unpaid_principal'] = get_loan_unpaid_principal($mysqli, $loanId);
        $rows[] = $r;
    }

    $st->close();
    return $rows;
}

/**
 * Borrower-wide payment allocation:
 * 1. pay all unpaid interest first across all eligible loans
 * 2. then pay principal
 * 3. oldest loans first
 */
function allocate_borrower_payment(mysqli $mysqli, int $user_id, float $amount): array {
    $remaining = round(max(0.0, $amount), 2);
    $loans = get_borrower_active_loans($mysqli, $user_id);

    $allocations = [];
    $loanSummaries = [];

    foreach ($loans as $loan) {
        $loanId = (int)$loan['loan_id'];
        $loanSummaries[$loanId] = [
            'loan_id' => $loanId,
            'interest_before' => (float)$loan['unpaid_interest'],
            'principal_before' => (float)$loan['unpaid_principal'],
            'interest_paid' => 0.0,
            'principal_paid' => 0.0,
            'interest_after' => (float)$loan['unpaid_interest'],
            'principal_after' => (float)$loan['unpaid_principal'],
        ];
    }

    // pass 1: interest first
    foreach ($loans as $loan) {
        if ($remaining <= 0) break;

        $loanId = (int)$loan['loan_id'];
        $unpaidInterest = (float)$loanSummaries[$loanId]['interest_after'];

        if ($unpaidInterest <= 0) continue;

        $pay = min($remaining, $unpaidInterest);
        if ($pay <= 0) continue;

        $allocations[] = [
            'loan_id' => $loanId,
            'type' => 'loan_interest',
            'amount' => round($pay, 2),
            'description' => 'Loan interest (auto-allocated)',
        ];

        $loanSummaries[$loanId]['interest_paid'] += round($pay, 2);
        $loanSummaries[$loanId]['interest_after'] = round(max(0.0, $loanSummaries[$loanId]['interest_after'] - $pay), 2);

        $remaining = round($remaining - $pay, 2);
    }

    // pass 2: principal
    foreach ($loans as $loan) {
        if ($remaining <= 0) break;

        $loanId = (int)$loan['loan_id'];
        $unpaidPrincipal = (float)$loanSummaries[$loanId]['principal_after'];

        if ($unpaidPrincipal <= 0) continue;

        $pay = min($remaining, $unpaidPrincipal);
        if ($pay <= 0) continue;

        $allocations[] = [
            'loan_id' => $loanId,
            'type' => 'loan_principal',
            'amount' => round($pay, 2),
            'description' => 'Loan principal (auto-allocated)',
        ];

        $loanSummaries[$loanId]['principal_paid'] += round($pay, 2);
        $loanSummaries[$loanId]['principal_after'] = round(max(0.0, $loanSummaries[$loanId]['principal_after'] - $pay), 2);

        $remaining = round($remaining - $pay, 2);
    }

    return [
        'total_entered' => round($amount, 2),
        'allocations' => $allocations,
        'remaining_unallocated' => round(max(0.0, $remaining), 2),
        'loan_summaries' => array_values($loanSummaries),
    ];
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
   GET: LIST + SINGLE + PAYMENT PREVIEW
   ======================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (isset($_GET['action']) && $_GET['action'] === 'payment_preview') {
        $user_id = (int)($_GET['user_id'] ?? 0);
        $amount = (float)($_GET['amount'] ?? 0);

        if ($user_id <= 0) send_json(['success' => false, 'message' => 'user_id is required'], 400);
        if ($amount <= 0) send_json(['success' => false, 'message' => 'amount must be greater than zero'], 400);

        [$okU, $u, $msgU] = require_user($mysqli, $user_id);
        if (!$okU) send_json(['success' => false, 'message' => $msgU], 400);

        $preview = allocate_borrower_payment($mysqli, $user_id, $amount);

        send_json([
            'success' => true,
            'data' => [
                'user_id' => $user_id,
                'user_name' => $u['names'] ?? '',
                'preview' => $preview
            ]
        ]);
    }

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

$loan_id_final = null;
$loanRow = null;

if ($typeN === 'loan_interest') {
    if (!$loan_id || $loan_id <= 0) send_json(['success' => false, 'message' => 'loan_id required'], 400);

    [$okL, $loanRow, $msgL] = require_loan($mysqli, (int)$loan_id);
    if (!$okL) send_json(['success' => false, 'message' => $msgL], 400);

    if ((int)($loanRow['borrower_user_id'] ?? 0) !== (int)$user_id) {
        send_json(['success' => false, 'message' => 'Loan ownership mismatch'], 400);
    }

    if (!in_array((string)($loanRow['status'] ?? ''), ['approved','defaulted','closed'], true)) {
        send_json(['success' => false, 'message' => 'Loan is not eligible for payment'], 400);
    }

    $loan_id_final = (int)$loan_id;
}

$has_file = (!empty($_FILES['proof_file']) && is_uploaded_file($_FILES['proof_file']['tmp_name']));
if ($action === 'create' && !$has_file) {
    send_json(['success' => false, 'message' => 'Proof file is required'], 400);
}

/* ---------------- CREATE ---------------- */

if ($action === 'create') {

    $proof_name = (string)($_FILES['proof_file']['name'] ?? 'proof');
    $proof_type = (string)($_FILES['proof_file']['type'] ?? 'application/octet-stream');
    $proof_size = (int)($_FILES['proof_file']['size'] ?? 0);
    $proof_blob = file_get_contents($_FILES['proof_file']['tmp_name']);
    if ($proof_blob === false || $proof_blob === '') send_json(['success' => false, 'message' => 'Invalid proof file'], 400);
    $proof_hash = hash('sha256', $proof_blob);

    $mysqli->begin_transaction();
    try {

        /**
         * SPECIAL:
         * type=loan_principal means entered amount is TOTAL borrower payment.
         * Ignore selected loan_id for allocation logic.
         */
        if ($typeN === 'loan_principal') {

            if (!$user_id || $user_id <= 0) {
                throw new RuntimeException('user_id is required for borrower-wide loan payment');
            }

            $preview = allocate_borrower_payment($mysqli, (int)$user_id, (float)$amount);
            $allocations = $preview['allocations'];

            if (empty($allocations)) {
                throw new RuntimeException('Nothing to save. No unpaid interest or principal found for this borrower.');
            }

            $savedRows = [];

            foreach ($allocations as $alloc) {
                $loanId = (int)$alloc['loan_id'];
                $allocType = (string)$alloc['type'];
                $allocAmount = (float)$alloc['amount'];
                $allocDesc = (string)$alloc['description'];

                if ($allocAmount <= 0) continue;

                $sql = "INSERT INTO transactions
                        (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
                        VALUES (?, ?, ?, ?, ?, 'IN', ?, ?, ?)";
                $st = $mysqli->prepare($sql);
                if (!$st) {
                    throw new RuntimeException('Prepare failed: ' . $mysqli->error);
                }

                $uid = (int)$user_id;
                $lid = $loanId;
                $descFinal = ($description !== '') ? ($description . ' | ' . $allocDesc) : $allocDesc;

                $st->bind_param('siiisdsi', $tx_date, $uid, $lid, $account_id, $allocType, $allocAmount, $descFinal, $admin_user_id);
                if (!$st->execute()) {
                    $err = $st->error ?: $mysqli->error;
                    $st->close();
                    throw new RuntimeException('Insert failed: ' . $err);
                }

                $txId = (int)$mysqli->insert_id;
                $st->close();

                update_proof($mysqli, $txId, $proof_blob, $proof_name, $proof_type, $proof_size, $proof_hash);

                $savedRows[] = fetch_tx($mysqli, $txId);
            }

            $mysqli->commit();

            send_json([
                'success' => true,
                'data' => [
                    'total_entered' => $preview['total_entered'],
                    'remaining_unallocated' => $preview['remaining_unallocated'],
                    'loan_summaries' => $preview['loan_summaries'],
                    'saved_rows' => $savedRows,
                ]
            ]);
        }

        // NORMAL single transaction create
        $sql = "INSERT INTO transactions
                (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $st = $mysqli->prepare($sql);
        if (!$st) {
            throw new RuntimeException('Prepare failed: ' . $mysqli->error);
        }

        $uid = $user_id;
        $lid = $loan_id_final;
        $st->bind_param('siiissdsi', $tx_date, $uid, $lid, $account_id, $typeN, $dirN, $amount, $description, $admin_user_id);
        if (!$st->execute()) {
            $err = $st->error ?: $mysqli->error;
            $st->close();
            throw new RuntimeException('Insert failed: ' . $err);
        }

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

    $mysqli->begin_transaction();
    try {
        $sql = "UPDATE transactions
                SET tx_date=?, user_id=?, loan_id=?, account_id=?, type=?, direction=?, amount=?, description=?
                WHERE transaction_id=?";
        $st = $mysqli->prepare($sql);
        if (!$st) {
            throw new RuntimeException('Prepare failed: ' . $mysqli->error);
        }

        $uid = $user_id;
        $lid = $loan_id_final;
        $st->bind_param('siiissdsi', $tx_date, $uid, $lid, $account_id, $typeN, $dirN, $amount, $description, $id);
        if (!$st->execute()) {
            $err = $st->error ?: $mysqli->error;
            $st->close();
            throw new RuntimeException('Update failed: ' . $err);
        }
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