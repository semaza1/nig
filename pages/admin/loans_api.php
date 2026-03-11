<?php
/**
 * pages/admin/loans_api.php
 *
 * Updated:
 * - loans must have account_id
 * - statuses: requested, approved, closed, rejected, defaulted
 * - auto create one OUT transaction (loan_disbursement) on approval
 * - unpaid principal = principal - SUM(loan_principal IN)
 * - immediate first-month interest is due once a loan is approved
 * - member interest share is NOT stored; it is calculated dynamically
 * - member share uses: current contribution balance + previously calculated interest
 * - user net includes calculated interest share
 * - optional reference_file blob if columns exist
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
session_start();
ob_start();

/* -----------------------------
   Helpers
------------------------------*/
function send_json($data, int $code = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

register_shutdown_function(function () {
    $err = error_get_last();
    $buf = '';

    while (ob_get_level() > 0) {
        $buf .= ob_get_clean();
    }

    $logFile = __DIR__ . '/loans_debug.log';

    if (trim($buf) !== '') {
        @file_put_contents(
            $logFile,
            date('c') . " - BUFFERED OUTPUT:\n" . $buf . "\n\n",
            FILE_APPEND | LOCK_EX
        );
    }

    if ($err) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error',
            'error'   => $err
        ]);
        exit;
    }
});

/* -----------------------------
   Bootstrap
------------------------------*/
$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) {
    send_json(['success' => false, 'message' => 'Database connection failed'], 500);
}

require_once __DIR__ . '/notifications_helper.php';

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    send_json(['success' => false, 'message' => 'Access denied'], 403);
}

$admin_user_id = (int)($_SESSION['user_id'] ?? 0);

const USER_PHONE_COL  = 'phone1';
const USER_PHONE2_COL = 'phone2';

/* -----------------------------
   DB helpers
------------------------------*/
function loan_reference_columns_exist(mysqli $mysqli): bool {
    $res = $mysqli->query("SHOW COLUMNS FROM loans LIKE 'reference_file'");
    return $res && $res->num_rows > 0;
}

/* -----------------------------
   Loan finance helpers
------------------------------*/
function get_loan_row(mysqli $mysqli, int $loan_id): ?array {
    $lid = (int)$loan_id;

    $st = $mysqli->prepare("
        SELECT loan_id, borrower_user_id, account_id, principal, monthly_interest_rate, interest_method,
               term_months, status, start_date, approved_at, end_date
        FROM loans
        WHERE loan_id=?
        LIMIT 1
    ");
    if (!$st) return null;

    $st->bind_param('i', $lid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row ?: null;
}

function get_loan_paid_principal(mysqli $mysqli, int $loan_id): float {
    $lid = (int)$loan_id;

    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_paid
        FROM transactions
        WHERE loan_id=?
          AND type='loan_principal'
          AND direction='IN'
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $lid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return (float)($row['total_paid'] ?? 0);
}

function get_loan_paid_interest(mysqli $mysqli, int $loan_id): float {
    $lid = (int)$loan_id;

    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_paid
        FROM transactions
        WHERE loan_id=?
          AND type='loan_interest'
          AND direction='IN'
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $lid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return (float)($row['total_paid'] ?? 0);
}

/**
 * Immediate first-month interest due:
 * once approved, first month interest is counted immediately.
 *
 * This version computes that due dynamically and does NOT store member shares.
 * It also does NOT create artificial interest charge rows in transactions.
 */
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

    $principal = (float)($loan['principal'] ?? 0);
    $status    = (string)($loan['status'] ?? 'requested');

    if (!in_array($status, ['approved', 'defaulted', 'closed'], true)) {
        return 0.0;
    }

    $paidPrincipal = get_loan_paid_principal($mysqli, $loan_id);
    return (float)max(0, $principal - $paidPrincipal);
}

function get_user_unpaid_loans(mysqli $mysqli, int $user_id): float {
    $uid = (int)$user_id;

    $st = $mysqli->prepare("
        SELECT loan_id
        FROM loans
        WHERE borrower_user_id=?
          AND status IN ('approved','defaulted','closed')
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();

    $sum = 0.0;
    while ($row = $res->fetch_assoc()) {
        $sum += get_loan_unpaid_principal($mysqli, (int)$row['loan_id']);
    }

    $st->close();
    return (float)$sum;
}

function get_user_unpaid_interest_total(mysqli $mysqli, int $user_id): float {
    $uid = (int)$user_id;

    $st = $mysqli->prepare("
        SELECT loan_id
        FROM loans
        WHERE borrower_user_id=?
          AND status IN ('approved','defaulted','closed')
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $uid);
    $st->execute();
    $res = $st->get_result();

    $sum = 0.0;
    while ($row = $res->fetch_assoc()) {
        $sum += get_loan_unpaid_interest($mysqli, (int)$row['loan_id']);
    }

    $st->close();
    return (float)$sum;
}

/* -----------------------------
   Member interest share (dynamic)
------------------------------*/
/**
 * Current contribution balance:
 * contributions IN minus withdrawals/deductions OUT
 *
 * Adjust the included types here if your schema uses other names.
 */
function get_member_contribution_base(mysqli $mysqli, int $user_id): float {
    $uid = (int)$user_id;

    $st = $mysqli->prepare("
        SELECT
            COALESCE(SUM(CASE
                WHEN type='contribution' AND direction='IN' THEN amount
                ELSE 0
            END), 0) AS contrib_in,
            COALESCE(SUM(CASE
                WHEN type IN ('withdrawal','withdrawal_deduction') AND direction='OUT' THEN amount
                ELSE 0
            END), 0) AS withdraw_out
        FROM transactions
        WHERE user_id=?
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $base = (float)($row['contrib_in'] ?? 0) - (float)($row['withdraw_out'] ?? 0);
    return max(0.0, $base);
}

/**
 * Calculates all members' interest shares dynamically.
 *
 * Each incoming borrower interest payment is distributed proportionally by:
 *   current contribution base + previously earned calculated interest
 *
 * Interest shares are NOT stored in DB.
 */
function calculate_member_interest_shares(mysqli $mysqli, ?string $asOfDate = null): array {
    $members = [];

    $memberRes = $mysqli->query("
        SELECT id
        FROM users
        WHERE is_member = 1
        ORDER BY id ASC
    ");
    if (!$memberRes) return [];

    while ($m = $memberRes->fetch_assoc()) {
        $uid = (int)$m['id'];
        $members[$uid] = [
            'base'            => get_member_contribution_base($mysqli, $uid),
            'earned_interest' => 0.0,
            'current_weight'  => 0.0,
        ];
    }

    if (empty($members)) return [];

    $dateClause = '';
    if ($asOfDate !== null && trim($asOfDate) !== '') {
        $safeDate = $mysqli->real_escape_string($asOfDate);
        $dateClause = " AND tx_date <= '{$safeDate} 23:59:59' ";
    }

    $interestRows = $mysqli->query("
        SELECT transaction_id, amount, tx_date
        FROM transactions
        WHERE type='loan_interest'
          AND direction='IN'
          {$dateClause}
        ORDER BY tx_date ASC, transaction_id ASC
    ");
    if (!$interestRows) {
        $result = [];
        foreach ($members as $uid => $m) {
            $result[$uid] = 0.0;
        }
        return $result;
    }

    while ($row = $interestRows->fetch_assoc()) {
        $interestAmount = (float)($row['amount'] ?? 0);
        if ($interestAmount <= 0) continue;

        $totalWeight = 0.0;

        foreach ($members as $uid => $m) {
            $weight = max(0.0, (float)$m['base'] + (float)$m['earned_interest']);
            $members[$uid]['current_weight'] = $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) {
            continue;
        }

        foreach ($members as $uid => $m) {
            $share = ($m['current_weight'] / $totalWeight) * $interestAmount;
            $members[$uid]['earned_interest'] += $share;
        }
    }

    $result = [];
    foreach ($members as $uid => $m) {
        $result[$uid] = round((float)$m['earned_interest'], 2);
    }

    return $result;
}

function get_member_calculated_interest(mysqli $mysqli, int $user_id, ?string $asOfDate = null): float {
    $all = calculate_member_interest_shares($mysqli, $asOfDate);
    return (float)($all[$user_id] ?? 0.0);
}

/* -----------------------------
   User net
------------------------------*/
function get_user_net(mysqli $mysqli, int $user_id): array {
    $uid = (int)$user_id;

    $contrib  = 0.0;
    $withdraw = 0.0;

    $q1 = $mysqli->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='contribution' AND direction='IN' THEN amount ELSE 0 END),0) AS contrib,
            COALESCE(SUM(CASE WHEN type='withdrawal' AND direction='OUT' THEN amount ELSE 0 END),0) AS withdraws
        FROM transactions
        WHERE user_id=?
    ");
    if ($q1) {
        $q1->bind_param('i', $uid);
        $q1->execute();
        $r = $q1->get_result()->fetch_assoc();
        $contrib  = (float)($r['contrib'] ?? 0);
        $withdraw = (float)($r['withdraws'] ?? 0);
        $q1->close();
    }

    $calculated_interest = get_member_calculated_interest($mysqli, $uid);

    $loans_unpaid = get_user_unpaid_loans($mysqli, $uid);
    $interest_unpaid = get_user_unpaid_interest_total($mysqli, $uid);

    $guaranteed = 0.0;
    $q3 = $mysqli->prepare("
        SELECT COALESCE(SUM(lg.guarantee_amount),0) AS total_guaranteed
        FROM loan_guaranters lg
        INNER JOIN loans l ON l.loan_id = lg.loan_id
        WHERE lg.guarantor_user_id=?
          AND lg.status='accepted'
          AND l.status IN ('approved','defaulted')
    ");
    if ($q3) {
        $q3->bind_param('i', $uid);
        $q3->execute();
        $r = $q3->get_result()->fetch_assoc();
        $guaranteed = (float)($r['total_guaranteed'] ?? 0);
        $q3->close();
    }

    $reserve = 120000.0;

    $net_raw = ($contrib + $calculated_interest) - ($withdraw + $loans_unpaid + $interest_unpaid + $guaranteed + $reserve);
    $net     = max(0.0, $net_raw);

    return [
        'contrib'              => $contrib,
        'calculated_interest'  => $calculated_interest,
        'withdrawals'          => $withdraw,
        'loans_principal'      => $loans_unpaid,
        'loans_interest'       => $interest_unpaid,
        'guaranteed_to_others' => $guaranteed,
        'reserve'              => $reserve,
        'net_raw'              => $net_raw,
        'net'                  => $net,
    ];
}

/* -----------------------------
   Disbursement on approval
------------------------------*/
function ensure_loan_disbursement(mysqli $mysqli, int $loan_id): void {
    $lid = (int)$loan_id;

    $st = $mysqli->prepare("
        SELECT loan_id, account_id, borrower_user_id, principal
        FROM loans
        WHERE loan_id=?
        LIMIT 1
    ");
    if (!$st) {
        throw new Exception("Prepare error: " . $mysqli->error);
    }

    $st->bind_param('i', $lid);
    $st->execute();
    $loan = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$loan) {
        throw new Exception("Loan not found");
    }

    $chk = $mysqli->prepare("
        SELECT COUNT(*) AS c
        FROM transactions
        WHERE loan_id=?
          AND type='loan_disbursement'
          AND direction='OUT'
        LIMIT 1
    ");
    if (!$chk) {
        throw new Exception("Prepare error: " . $mysqli->error);
    }

    $chk->bind_param('i', $lid);
    $chk->execute();
    $cRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ((int)($cRow['c'] ?? 0) > 0) {
        return;
    }

    $borrower_id = (int)($loan['borrower_user_id'] ?? 0);
    $account_id  = (int)($loan['account_id'] ?? 0);
    $amount      = (float)($loan['principal'] ?? 0);

    if ($borrower_id <= 0 || $account_id <= 0 || $amount <= 0) {
        throw new Exception("Invalid loan data for disbursement");
    }

    $desc = "Loan disbursement for #LN-" . $lid;

    $ins = $mysqli->prepare("
        INSERT INTO transactions
            (user_id, account_id, loan_id, type, direction, amount, tx_date, description)
        VALUES
            (?, ?, ?, 'loan_disbursement', 'OUT', ?, NOW(), ?)
    ");
    if (!$ins) {
        throw new Exception("Prepare error: " . $mysqli->error);
    }

    $ins->bind_param('iiids', $borrower_id, $account_id, $lid, $amount, $desc);
    if (!$ins->execute()) {
        $e = $ins->error ?: $mysqli->error;
        $ins->close();
        throw new Exception("Failed inserting disbursement: " . $e);
    }
    $ins->close();
}

/* -----------------------------
   POST utility helpers
------------------------------*/
function parse_guarantors(string $json): array {
    $arr = json_decode($json, true);
    if (!is_array($arr)) return [];

    $out = [];
    foreach ($arr as $g) {
        $uid = (int)($g['user_id'] ?? 0);
        $amt = (float)($g['amount'] ?? 0);
        if ($uid > 0 && $amt > 0) {
            $out[] = [
                'user_id' => $uid,
                'amount'  => $amt
            ];
        }
    }
    return $out;
}

function validate_guarantees(mysqli $mysqli, int $borrower_id, float $principal, array $guarantors): array {
    $uRes = $mysqli->query("SELECT id, is_member FROM users WHERE id=" . (int)$borrower_id . " LIMIT 1");
    $u = $uRes ? $uRes->fetch_assoc() : null;
    if (!$u) return [false, "Borrower not found"];

    $is_member = (int)($u['is_member'] ?? 0);

    $bFin = get_user_net($mysqli, $borrower_id);
    $borrower_net = (float)($bFin['net']);

    $required = ($is_member === 1) ? max(0.0, $principal - $borrower_net) : $principal;
    if ($required <= 0.0) return [true, null];

    if (count($guarantors) === 0) {
        return [false, "This borrower needs guarantor(s) to cover " . number_format($required, 2) . " Frw"];
    }

    $sum = 0.0;
    $seen = [];

    foreach ($guarantors as $g) {
        $gid = (int)$g['user_id'];
        $amt = (float)$g['amount'];

        if ($gid === $borrower_id) return [false, "Borrower cannot guarantee own loan"];
        if (isset($seen[$gid])) return [false, "Duplicate guarantor selected"];
        $seen[$gid] = true;

        $guRes = $mysqli->query("SELECT id, is_member FROM users WHERE id=" . (int)$gid . " LIMIT 1");
        $gu = $guRes ? $guRes->fetch_assoc() : null;
        if (!$gu) return [false, "Guarantor not found (ID $gid)"];
        if ((int)$gu['is_member'] !== 1) return [false, "Guarantor must be a member"];

        $gFin = get_user_net($mysqli, $gid);
        $gNet = (float)($gFin['net']);

        if ($gNet <= 0) return [false, "Guarantor has no net value available"];
        if ($amt > $gNet) return [false, "Guarantor amount exceeds guarantor net value (max " . number_format($gNet, 2) . " Frw)"];

        $sum += $amt;
    }

    if ($sum + 0.00001 < $required) {
        return [false, "Guarantors total " . number_format($sum, 2) . " Frw is not enough. Required " . number_format($required, 2) . " Frw"];
    }

    return [true, null];
}

function save_guarantors(mysqli $mysqli, int $loan_id, array $guarantors): bool {
    $del = $mysqli->prepare("DELETE FROM loan_guaranters WHERE loan_id=?");
    if (!$del) return false;

    $del->bind_param('i', $loan_id);
    if (!$del->execute()) {
        $del->close();
        return false;
    }
    $del->close();

    foreach ($guarantors as $g) {
        $gid = (int)$g['user_id'];
        $amt = (float)$g['amount'];

        $ins = $mysqli->prepare("
            INSERT INTO loan_guaranters (loan_id, guarantor_user_id, guarantee_amount, status)
            VALUES (?, ?, ?, 'pending')
        ");
        if (!$ins) return false;

        $ins->bind_param('iid', $loan_id, $gid, $amt);
        if (!$ins->execute()) {
            $ins->close();
            return false;
        }
        $ins->close();
    }

    return true;
}

/* -----------------------------
   Request routing
------------------------------*/
$action = $_REQUEST['action'] ?? '';

/* =========================================================
   GET endpoints
=========================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($action === 'search_users') {
        $q = trim($_GET['q'] ?? '');
        $limit = 20;
        $qLike = '%' . $q . '%';

        $stmt = $mysqli->prepare("
            SELECT id, names,
                   " . USER_PHONE_COL . " AS phone,
                   " . USER_PHONE2_COL . " AS phone2,
                   is_member
            FROM users
            WHERE (names LIKE ? OR " . USER_PHONE_COL . " LIKE ? OR " . USER_PHONE2_COL . " LIKE ?)
            ORDER BY names ASC
            LIMIT $limit
        ");
        if (!$stmt) {
            send_json(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error], 500);
        }

        $stmt->bind_param('sss', $qLike, $qLike, $qLike);
        $stmt->execute();

        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }

        $stmt->close();
        send_json(['success' => true, 'data' => $rows]);
    }

    if ($action === 'borrower_summary') {
        $uid = (int)($_GET['user_id'] ?? 0);
        if ($uid <= 0) {
            send_json(['success' => false, 'message' => 'Invalid user'], 400);
        }

        $uRes = $mysqli->query("
            SELECT id, names,
                   " . USER_PHONE_COL . " AS phone,
                   " . USER_PHONE2_COL . " AS phone2,
                   is_member
            FROM users
            WHERE id=" . (int)$uid . "
            LIMIT 1
        ");
        $u = $uRes ? $uRes->fetch_assoc() : null;
        if (!$u) {
            send_json(['success' => false, 'message' => 'User not found'], 404);
        }

        $fin    = get_user_net($mysqli, $uid);
        $unpaid = get_user_unpaid_loans($mysqli, $uid);
        $unpaidInterest = get_user_unpaid_interest_total($mysqli, $uid);

        send_json([
            'success' => true,
            'data' => [
                'id'                => (int)$u['id'],
                'names'             => $u['names'],
                'phone'             => $u['phone'] ?? '',
                'phone2'            => $u['phone2'] ?? '',
                'is_member'         => (int)($u['is_member'] ?? 0),
                'net_value'         => (float)$fin['net'],
                'net_breakdown'     => $fin,
                'unpaid_loans'      => $unpaid,
                'unpaid_interest'   => $unpaidInterest,
                'calculated_interest' => (float)($fin['calculated_interest'] ?? 0),
            ]
        ]);
    }

    if ($action === 'eligible_guarantors') {
        $q = trim($_GET['q'] ?? '');
        $borrower_id = (int)($_GET['borrower_id'] ?? 0);
        $limit = 30;
        $qLike = '%' . $q . '%';

        $stmt = $mysqli->prepare("
            SELECT id, names,
                   " . USER_PHONE_COL . " AS phone,
                   " . USER_PHONE2_COL . " AS phone2
            FROM users
            WHERE is_member=1
              AND id <> ?
              AND (names LIKE ? OR " . USER_PHONE_COL . " LIKE ? OR " . USER_PHONE2_COL . " LIKE ?)
            ORDER BY names ASC
            LIMIT $limit
        ");
        if (!$stmt) {
            send_json(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error], 500);
        }

        $stmt->bind_param('isss', $borrower_id, $qLike, $qLike, $qLike);
        $stmt->execute();

        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $fin = get_user_net($mysqli, (int)$r['id']);
            $net = (float)($fin['net'] ?? 0);
            if ($net > 0) {
                $r['net_value'] = $net;
                $r['calculated_interest'] = (float)($fin['calculated_interest'] ?? 0);
                $rows[] = $r;
            }
        }

        $stmt->close();
        send_json(['success' => true, 'data' => $rows]);
    }

    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];

        $stmt = $mysqli->prepare("
            SELECT l.*,
                   u.names AS borrower_name,
                   CONCAT_WS(' / ', u." . USER_PHONE_COL . ", u." . USER_PHONE2_COL . ") AS borrower_phone,
                   u.is_member AS borrower_is_member,
                   a.name AS account_name
            FROM loans l
            LEFT JOIN users u ON l.borrower_user_id = u.id
            LEFT JOIN accounts a ON l.account_id = a.account_id
            WHERE l.loan_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            send_json(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error], 500);
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            send_json(['success' => false, 'message' => 'Not found'], 404);
        }

        $gua = [];
        $gStmt = $mysqli->prepare("
            SELECT lg.guarantor_id, lg.guarantor_user_id, lg.guarantee_amount, lg.status,
                   u.names AS guarantor_name,
                   CONCAT_WS(' / ', u." . USER_PHONE_COL . ", u." . USER_PHONE2_COL . ") AS guarantor_phone
            FROM loan_guaranters lg
            LEFT JOIN users u ON lg.guarantor_user_id = u.id
            WHERE lg.loan_id = ?
            ORDER BY lg.guarantor_id ASC
        ");
        if ($gStmt) {
            $gStmt->bind_param('i', $id);
            $gStmt->execute();
            $gr = $gStmt->get_result();

            while ($g = $gr->fetch_assoc()) {
                $gFin = get_user_net($mysqli, (int)$g['guarantor_user_id']);
                $g['guarantor_net'] = (float)($gFin['net'] ?? 0);
                $g['guarantor_interest_share'] = (float)($gFin['calculated_interest'] ?? 0);
                $gua[] = $g;
            }
            $gStmt->close();
        }

        $row['guarantors'] = $gua;
        $row['paid_principal']     = get_loan_paid_principal($mysqli, (int)$row['loan_id']);
        $row['unpaid_principal']   = get_loan_unpaid_principal($mysqli, (int)$row['loan_id']);
        $row['initial_interest_due'] = get_loan_initial_interest_due($mysqli, (int)$row['loan_id']);
        $row['paid_interest']      = get_loan_paid_interest($mysqli, (int)$row['loan_id']);
        $row['unpaid_interest']    = get_loan_unpaid_interest($mysqli, (int)$row['loan_id']);
        $row['total_due']          = (float)$row['unpaid_principal'] + (float)$row['unpaid_interest'];

        send_json(['success' => true, 'data' => $row]);
    }

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, (int)($_GET['per_page'] ?? 50));
    $q        = trim($_GET['q'] ?? '');

    $where = '';
    if ($q !== '') {
        $esc = $mysqli->real_escape_string($q);
        $where = " WHERE (u.names LIKE '%$esc%' OR u." . USER_PHONE_COL . " LIKE '%$esc%' OR u." . USER_PHONE2_COL . " LIKE '%$esc%') ";
    }

    $totalRes = $mysqli->query("
        SELECT COUNT(*) AS cnt
        FROM loans l
        LEFT JOIN users u ON l.borrower_user_id = u.id
        $where
    ");
    $totalRow = $totalRes ? $totalRes->fetch_assoc() : ['cnt' => 0];
    $total    = (int)($totalRow['cnt'] ?? 0);

    $offset = ($page - 1) * $per_page;

    $sql = "
        SELECT l.loan_id, l.account_id, a.name AS account_name,
               l.borrower_user_id, l.principal, l.monthly_interest_rate, l.start_date, l.status, l.end_date,
               u.names AS borrower_name,
               CONCAT_WS(' / ', u." . USER_PHONE_COL . ", u." . USER_PHONE2_COL . ") AS borrower_phone
        FROM loans l
        LEFT JOIN users u ON l.borrower_user_id = u.id
        LEFT JOIN accounts a ON l.account_id = a.account_id
        $where
        ORDER BY l.loan_id DESC
        LIMIT $offset, $per_page
    ";

    $res = $mysqli->query($sql);
    if (!$res) {
        send_json(['success' => false, 'message' => 'Query error: ' . $mysqli->error], 500);
    }

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $loanId = (int)$r['loan_id'];
        $r['paid_principal']       = get_loan_paid_principal($mysqli, $loanId);
        $r['unpaid_principal']     = get_loan_unpaid_principal($mysqli, $loanId);
        $r['initial_interest_due'] = get_loan_initial_interest_due($mysqli, $loanId);
        $r['paid_interest']        = get_loan_paid_interest($mysqli, $loanId);
        $r['unpaid_interest']      = get_loan_unpaid_interest($mysqli, $loanId);
        $r['total_due']            = (float)$r['unpaid_principal'] + (float)$r['unpaid_interest'];
        $rows[] = $r;
    }

    send_json([
        'success'  => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page
    ]);
}

/* =========================================================
   POST endpoints
=========================================================*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success' => false, 'message' => 'Invalid request'], 400);
}

$action = $_POST['action'] ?? '';

/* =========================================================
   POST: CREATE
=========================================================*/
if ($action === 'create') {
    $account_id      = (int)($_POST['account_id'] ?? 0);
    $borrower_id     = (int)($_POST['borrower_user_id'] ?? 0);
    $principal       = (float)($_POST['principal'] ?? 0);
    $monthly_rate    = (float)($_POST['monthly_interest_rate'] ?? 0);
    $interest_method = trim((string)($_POST['interest_method'] ?? 'reducing'));
    $term            = (int)($_POST['term_months'] ?? 0);
    $notes           = trim((string)($_POST['notes'] ?? ''));

    if ($account_id <= 0 || $borrower_id <= 0 || $principal <= 0 || $term <= 0) {
        send_json(['success' => false, 'message' => 'Imirima yibanze irabuze cyangwa ifite agaciro kadasobanutse'], 400);
    }

    if (!in_array($interest_method, ['flat', 'reducing'], true)) {
        send_json(['success' => false, 'message' => 'Invalid interest method'], 400);
    }

    $acc = $mysqli->query("SELECT account_id FROM accounts WHERE account_id=" . (int)$account_id . " LIMIT 1");
    if (!$acc || $acc->num_rows === 0) {
        send_json(['success' => false, 'message' => 'Account not found'], 400);
    }

    $guarantors = parse_guarantors($_POST['guarantors'] ?? '[]');
    [$ok, $msg] = validate_guarantees($mysqli, $borrower_id, $principal, $guarantors);
    if (!$ok) {
        send_json(['success' => false, 'message' => $msg], 400);
    }

    $hasRefCols = loan_reference_columns_exist($mysqli);
    $ref_name = null;
    $ref_mime = null;
    $ref_blob = null;

    if ($hasRefCols && !empty($_FILES['reference_file']) && is_uploaded_file($_FILES['reference_file']['tmp_name'])) {
        $ref_name = $_FILES['reference_file']['name'] ?? null;
        $ref_mime = $_FILES['reference_file']['type'] ?? null;
        $ref_blob = @file_get_contents($_FILES['reference_file']['tmp_name']);
    }

    $mysqli->begin_transaction();
    try {
        if ($hasRefCols) {
            $sql = "
                INSERT INTO loans
                    (account_id, borrower_user_id, principal, monthly_interest_rate, interest_method, term_months,
                     status, start_date, end_date, notes, created_by, reference_name, reference_mime)
                VALUES
                    (?, ?, ?, ?, ?, ?, 'requested', NULL, NULL, ?, ?, ?, ?)
            ";
            $st = $mysqli->prepare($sql);
            if (!$st) throw new Exception($mysqli->error);

            $st->bind_param(
                'iiddsisiss',
                $account_id,
                $borrower_id,
                $principal,
                $monthly_rate,
                $interest_method,
                $term,
                $notes,
                $admin_user_id,
                $ref_name,
                $ref_mime
            );

            if (!$st->execute()) throw new Exception($st->error);
            $loan_id = (int)$st->insert_id;
            $st->close();

            if ($ref_blob !== null) {
                $up = $mysqli->prepare("UPDATE loans SET reference_file=? WHERE loan_id=?");
                if (!$up) throw new Exception($mysqli->error);

                $null = null;
                $up->bind_param('bi', $null, $loan_id);
                $up->send_long_data(0, $ref_blob);

                if (!$up->execute()) throw new Exception($up->error);
                $up->close();
            }
        } else {
            $sql = "
                INSERT INTO loans
                    (account_id, borrower_user_id, principal, monthly_interest_rate, interest_method, term_months,
                     status, start_date, end_date, notes, created_by)
                VALUES
                    (?, ?, ?, ?, ?, ?, 'requested', NULL, NULL, ?, ?)
            ";
            $st = $mysqli->prepare($sql);
            if (!$st) throw new Exception($mysqli->error);

            $st->bind_param(
                'iiddsisi',
                $account_id,
                $borrower_id,
                $principal,
                $monthly_rate,
                $interest_method,
                $term,
                $notes,
                $admin_user_id
            );

            if (!$st->execute()) throw new Exception($st->error);
            $loan_id = (int)$st->insert_id;
            $st->close();
        }

        if (!save_guarantors($mysqli, $loan_id, $guarantors)) {
            throw new Exception("Error saving guarantors");
        }

        $mysqli->commit();

        $msgN = "Inguzanyo nshya yanditswe (#LN-$loan_id): " . number_format((float)$principal) . " Frw";
        notify_admins($mysqli, 'loan_requested', $msgN);
        create_notification($mysqli, (int)$borrower_id, 'loan_requested', $msgN);

        send_json(['success' => true, 'data' => ['loan_id' => $loan_id]]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

/* =========================================================
   POST: UPDATE
=========================================================*/
if ($action === 'update') {
    $loan_id         = (int)($_POST['id'] ?? 0);
    $account_id      = (int)($_POST['account_id'] ?? 0);
    $borrower_id     = (int)($_POST['borrower_user_id'] ?? 0);
    $principal       = (float)($_POST['principal'] ?? 0);
    $monthly_rate    = (float)($_POST['monthly_interest_rate'] ?? 0);
    $interest_method = trim((string)($_POST['interest_method'] ?? 'reducing'));
    $term            = (int)($_POST['term_months'] ?? 0);
    $notes           = trim((string)($_POST['notes'] ?? ''));

    if ($loan_id <= 0) {
        send_json(['success' => false, 'message' => 'Invalid id'], 400);
    }

    if ($account_id <= 0 || $borrower_id <= 0 || $principal <= 0 || $term <= 0) {
        send_json(['success' => false, 'message' => 'Imirima yibanze irabuze cyangwa ifite agaciro kadasobanutse'], 400);
    }

    if (!in_array($interest_method, ['flat', 'reducing'], true)) {
        send_json(['success' => false, 'message' => 'Invalid interest method'], 400);
    }

    $acc = $mysqli->query("SELECT account_id FROM accounts WHERE account_id=" . (int)$account_id . " LIMIT 1");
    if (!$acc || $acc->num_rows === 0) {
        send_json(['success' => false, 'message' => 'Account not found'], 400);
    }

    $guarantors = parse_guarantors($_POST['guarantors'] ?? '[]');
    [$ok, $msg] = validate_guarantees($mysqli, $borrower_id, $principal, $guarantors);
    if (!$ok) {
        send_json(['success' => false, 'message' => $msg], 400);
    }

    $hasRefCols = loan_reference_columns_exist($mysqli);
    $ref_name   = null;
    $ref_mime   = null;
    $ref_blob   = null;
    $hasNewFile = false;

    if ($hasRefCols && !empty($_FILES['reference_file']) && is_uploaded_file($_FILES['reference_file']['tmp_name'])) {
        $ref_name   = $_FILES['reference_file']['name'] ?? null;
        $ref_mime   = $_FILES['reference_file']['type'] ?? null;
        $ref_blob   = @file_get_contents($_FILES['reference_file']['tmp_name']);
        $hasNewFile = true;
    }

    $mysqli->begin_transaction();
    try {
        if ($hasRefCols && $hasNewFile) {
            $st = $mysqli->prepare("
                UPDATE loans
                SET account_id=?,
                    borrower_user_id=?,
                    principal=?,
                    monthly_interest_rate=?,
                    interest_method=?,
                    term_months=?,
                    notes=?,
                    reference_name=?,
                    reference_mime=?
                WHERE loan_id=?
            ");
            if (!$st) throw new Exception($mysqli->error);

            $st->bind_param(
                'iiddsisssi',
                $account_id,
                $borrower_id,
                $principal,
                $monthly_rate,
                $interest_method,
                $term,
                $notes,
                $ref_name,
                $ref_mime,
                $loan_id
            );

            if (!$st->execute()) throw new Exception($st->error);
            $st->close();

            $up = $mysqli->prepare("UPDATE loans SET reference_file=? WHERE loan_id=?");
            if (!$up) throw new Exception($mysqli->error);

            $null = null;
            $up->bind_param('bi', $null, $loan_id);
            $up->send_long_data(0, $ref_blob);

            if (!$up->execute()) throw new Exception($up->error);
            $up->close();
        } else {
            $st = $mysqli->prepare("
                UPDATE loans
                SET account_id=?,
                    borrower_user_id=?,
                    principal=?,
                    monthly_interest_rate=?,
                    interest_method=?,
                    term_months=?,
                    notes=?
                WHERE loan_id=?
            ");
            if (!$st) throw new Exception($mysqli->error);

            $st->bind_param(
                'iiddsisi',
                $account_id,
                $borrower_id,
                $principal,
                $monthly_rate,
                $interest_method,
                $term,
                $notes,
                $loan_id
            );

            if (!$st->execute()) throw new Exception($st->error);
            $st->close();
        }

        if (!save_guarantors($mysqli, $loan_id, $guarantors)) {
            throw new Exception("Error saving guarantors");
        }

        $mysqli->commit();
        send_json(['success' => true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

/* =========================================================
   POST: CHANGE STATUS
=========================================================*/
if ($action === 'change_status') {
    $loan_id   = (int)($_POST['id'] ?? 0);
    $newStatus = trim((string)($_POST['status'] ?? ''));

    $allowed = ['requested', 'approved', 'closed', 'rejected', 'defaulted'];

    if ($loan_id <= 0 || !in_array($newStatus, $allowed, true)) {
        send_json(['success' => false, 'message' => 'Invalid request'], 400);
    }

    if ($admin_user_id <= 0) {
        send_json(['success' => false, 'message' => 'Missing admin session user_id'], 500);
    }

    $oldRes = $mysqli->query("
        SELECT status, borrower_user_id, start_date, end_date
        FROM loans
        WHERE loan_id=" . (int)$loan_id . "
        LIMIT 1
    ");
    $oldRow = $oldRes ? $oldRes->fetch_assoc() : null;
    if (!$oldRow) {
        send_json(['success' => false, 'message' => 'Loan not found'], 404);
    }

    $oldStatus   = $oldRow['status'] ?? '';
    $borrower_id = (int)($oldRow['borrower_user_id'] ?? 0);
    $start_date  = $oldRow['start_date'] ?? null;

    $mysqli->begin_transaction();
    try {
        if ($newStatus === 'approved') {
            if (empty($start_date) || $start_date === '0000-00-00') {
                $st = $mysqli->prepare("
                    UPDATE loans
                    SET status=?,
                        start_date=CURDATE(),
                        end_date=NULL,
                        approved_at=NOW(),
                        closed_at=NULL,
                        rejected_at=NULL,
                        defaulted_at=NULL
                    WHERE loan_id=?
                ");
            } else {
                $st = $mysqli->prepare("
                    UPDATE loans
                    SET status=?,
                        end_date=NULL,
                        approved_at=COALESCE(approved_at, NOW()),
                        closed_at=NULL,
                        rejected_at=NULL,
                        defaulted_at=NULL
                    WHERE loan_id=?
                ");
            }

            if (!$st) throw new Exception($mysqli->error);
            $st->bind_param('si', $newStatus, $loan_id);
            if (!$st->execute()) throw new Exception($st->error);
            $st->close();

            ensure_loan_disbursement($mysqli, $loan_id);

        } elseif ($newStatus === 'closed') {
            $st = $mysqli->prepare("
                UPDATE loans
                SET status=?, end_date=CURDATE(), closed_at=NOW()
                WHERE loan_id=?
            ");
            if (!$st) throw new Exception($mysqli->error);
            $st->bind_param('si', $newStatus, $loan_id);
            if (!$st->execute()) throw new Exception($st->error);
            $st->close();

        } elseif ($newStatus === 'rejected') {
            $st = $mysqli->prepare("
                UPDATE loans
                SET status=?, end_date=CURDATE(), rejected_at=NOW()
                WHERE loan_id=?
            ");
            if (!$st) throw new Exception($mysqli->error);
            $st->bind_param('si', $newStatus, $loan_id);
            if (!$st->execute()) throw new Exception($st->error);
            $st->close();

        } elseif ($newStatus === 'defaulted') {
            $st = $mysqli->prepare("
                UPDATE loans
                SET status=?, end_date=CURDATE(), defaulted_at=NOW()
                WHERE loan_id=?
            ");
            if (!$st) throw new Exception($mysqli->error);
            $st->bind_param('si', $newStatus, $loan_id);
            if (!$st->execute()) throw new Exception($st->error);
            $st->close();

        } else {
            $st = $mysqli->prepare("
                UPDATE loans
                SET status=?,
                    end_date=NULL,
                    closed_at=NULL,
                    rejected_at=NULL,
                    defaulted_at=NULL
                WHERE loan_id=?
            ");
            if (!$st) throw new Exception($mysqli->error);
            $st->bind_param('si', $newStatus, $loan_id);
            if (!$st->execute()) throw new Exception($st->error);
            $st->close();
        }

        $mysqli->commit();

        if ($oldStatus !== $newStatus) {
            $msgN = "Status y'inguzanyo (#LN-$loan_id) yahindutse: $oldStatus → $newStatus";
            if ($borrower_id > 0) {
                create_notification($mysqli, $borrower_id, 'loan_status_changed', $msgN);
            }
            notify_admins($mysqli, 'loan_status_changed', $msgN);
        }

        send_json(['success' => true]);

    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

/* =========================================================
   POST: DELETE
=========================================================*/
if ($action === 'delete') {
    $loan_id = (int)($_POST['id'] ?? 0);
    if ($loan_id <= 0) {
        send_json(['success' => false, 'message' => 'Invalid id'], 400);
    }

    $mysqli->begin_transaction();
    try {
        $g = $mysqli->prepare("DELETE FROM loan_guaranters WHERE loan_id=?");
        if (!$g) throw new Exception($mysqli->error);
        $g->bind_param('i', $loan_id);
        if (!$g->execute()) throw new Exception($g->error);
        $g->close();

        $l = $mysqli->prepare("DELETE FROM loans WHERE loan_id=?");
        if (!$l) throw new Exception($mysqli->error);
        $l->bind_param('i', $loan_id);
        if (!$l->execute()) throw new Exception($l->error);
        $l->close();

        $mysqli->commit();

        notify_admins($mysqli, 'loan_deleted', "Inguzanyo yasibwe (#LN-$loan_id)");
        send_json(['success' => true]);

    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

send_json(['success' => false, 'message' => 'Invalid request'], 400);