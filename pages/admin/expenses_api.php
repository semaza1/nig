<?php
/**
 * pages/admin/expenses_api.php  (FULL BACKEND - UPDATED with INTEREST DISTRIBUTION)
 *
 * ✅ No DB changes.
 * ✅ User search by names / NID / phone.
 * ✅ Withdrawable formula:
 *    withdrawable_max = max(0, contributions_net + interest_received - principal_outstanding - locked_guarantees - 120000)
 *
 * ✅ Loan payment split:
 *    - user enters TOTAL
 *    - system pays interest first then principal
 *    - interest receipt saved ONCE to cash account
 *    - then interest is DISTRIBUTED to members based on their net savings at that time
 *      (contribution + previous interest - withdrawals)
 *
 * ✅ Distribution entries use account_id = NULL to avoid inflating cash balance.
 *
 * Allowed types only (Other types disabled):
 *   contribution, withdrawal, loan_principal, loan_interest, expense
 */

header('Content-Type: application/json; charset=utf-8');
session_start();
ob_start();

function send_json($data, int $code = 200) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

register_shutdown_function(function(){
    $err = error_get_last();
    $buf = '';
    while (ob_get_level() > 0) { $buf .= ob_get_clean(); }

    $logFile = __DIR__ . '/expenses_debug.log';

    if (trim($buf) !== '') {
        @file_put_contents(
            $logFile,
            date('c') . " - NON-JSON OUTPUT:\n" . $buf . "\n\n",
            FILE_APPEND | LOCK_EX
        );
    }

    if ($err) {
        $msg = $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line'];
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error',
            'error'   => $msg,
            'debug_log' => 'pages/admin/expenses_debug.log'
        ]);
        exit;
    }

    if (trim($buf) !== '') {
        echo json_encode([
            'success' => false,
            'message' => 'Server produced unexpected output. See debug log: pages/admin/expenses_debug.log'
        ]);
        exit;
    }
});

$mysqli = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/notifications_helper.php';

if (!$mysqli) send_json(['success'=>false,'message'=>'DB connection failed'], 500);
if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) send_json(['success'=>false,'message'=>'Access denied'], 403);

$admin_id = (int)($_SESSION['user_id'] ?? 0);

function safe_date($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}
function money($x) { return (float)number_format((float)$x, 2, '.', ''); }

function allowed_types() {
    return ['contribution','withdrawal','loan_principal','loan_interest','expense'];
}
function direction_for_type($type) {
    switch ($type) {
        case 'contribution':   return 'IN';
        case 'withdrawal':     return 'OUT';
        case 'loan_principal': return 'IN';
        case 'loan_interest':  return 'IN';
        case 'expense':        return 'OUT';
        default: return null;
    }
}

function get_account_balance(mysqli $mysqli, int $account_id): float {
    if ($account_id <= 0) return 0.0;
    $sql = "
        SELECT
          COALESCE(SUM(CASE WHEN direction='IN'  THEN amount ELSE 0 END),0) -
          COALESCE(SUM(CASE WHEN direction='OUT' THEN amount ELSE 0 END),0)
        AS bal
        FROM transactions
        WHERE account_id = ?
    ";
    $st = $mysqli->prepare($sql);
    if (!$st) return 0.0;
    $st->bind_param('i', $account_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return (float)($row['bal'] ?? 0);
}

function months_between(DateTime $from, DateTime $to): int {
    if ($to < $from) return 0;
    $y = (int)$to->format('Y') - (int)$from->format('Y');
    $m = (int)$to->format('m') - (int)$from->format('m');
    $months = $y * 12 + $m;
    if ((int)$to->format('d') < (int)$from->format('d')) $months--;
    return max(0, $months);
}

function get_loan_summary(mysqli $mysqli, int $loan_id, ?string $as_of_date = null): array {
    $out = [
        'loan_id' => $loan_id,
        'borrower_user_id' => 0,
        'status' => null,
        'interest_method' => null,
        'monthly_interest_rate' => 0.0,
        'start_date' => null,
        'principal_total' => 0.0,
        'principal_paid' => 0.0,
        'principal_remaining' => 0.0,
        'interest_paid' => 0.0,
        'interest_accrued' => 0.0,
        'interest_due' => 0.0,
    ];

    $st = $mysqli->prepare("
        SELECT loan_id, borrower_user_id, status, principal, monthly_interest_rate, interest_method, start_date
        FROM loans WHERE loan_id=? LIMIT 1
    ");
    if (!$st) return $out;
    $st->bind_param('i', $loan_id);
    $st->execute();
    $l = $st->get_result()->fetch_assoc();
    if (!$l) return $out;

    $out['borrower_user_id'] = (int)($l['borrower_user_id'] ?? 0);
    $out['status'] = $l['status'] ?? null;
    $out['interest_method'] = $l['interest_method'] ?? null;
    $out['monthly_interest_rate'] = (float)($l['monthly_interest_rate'] ?? 0);
    $out['start_date'] = $l['start_date'] ?? null;
    $out['principal_total'] = money((float)($l['principal'] ?? 0));

    $st = $mysqli->prepare("
      SELECT
        COALESCE(SUM(CASE WHEN type='loan_principal' AND direction='IN' THEN amount ELSE 0 END),0) AS p_paid,
        COALESCE(SUM(CASE WHEN type='loan_interest'  AND direction='IN' THEN amount ELSE 0 END),0) AS i_paid
      FROM transactions
      WHERE loan_id=?
    ");
    if ($st) {
        $st->bind_param('i', $loan_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $out['principal_paid'] = money((float)($row['p_paid'] ?? 0));
        $out['interest_paid']  = money((float)($row['i_paid'] ?? 0));
    }

    $out['principal_remaining'] = money(max(0, $out['principal_total'] - $out['principal_paid']));

    $start = $out['start_date'] ? DateTime::createFromFormat('Y-m-d', $out['start_date']) : null;
    if (!$start) return $out;

    $asOf = null;
    if ($as_of_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of_date)) {
        $asOf = DateTime::createFromFormat('Y-m-d', $as_of_date);
    }
    if (!$asOf) $asOf = new DateTime();

    $months = months_between($start, $asOf);
    $rate = ((float)$out['monthly_interest_rate']) / 100.0;

    $interest_accrued = 0.0;
    if ($months > 0 && $rate > 0) {
        if ($out['interest_method'] === 'flat') {
            $interest_accrued = $out['principal_total'] * $rate * $months;
        } else {
            $pays = [];
            $st = $mysqli->prepare("
              SELECT tx_date, amount
              FROM transactions
              WHERE loan_id=? AND type='loan_principal' AND direction='IN'
              ORDER BY tx_date ASC
            ");
            if ($st) {
                $st->bind_param('i', $loan_id);
                $st->execute();
                $rs = $st->get_result();
                while ($r = $rs->fetch_assoc()) $pays[] = ['dt'=>new DateTime($r['tx_date']), 'amt'=>(float)$r['amount']];
            }

            $outstanding = $out['principal_total'];
            $cursor = clone $start;

            for ($k=0; $k<$months; $k++) {
                $interest_accrued += ($outstanding * $rate);
                $next = (clone $cursor)->modify('+1 month');

                foreach ($pays as $p) {
                    if ($p['dt'] >= $cursor && $p['dt'] < $next) {
                        $outstanding -= $p['amt'];
                        if ($outstanding < 0) $outstanding = 0;
                    }
                }
                $cursor = $next;
            }
        }
    }

    $out['interest_accrued'] = money($interest_accrued);
    $out['interest_due'] = money(max(0, $out['interest_accrued'] - $out['interest_paid']));
    return $out;
}

/**
 * Member weight at a moment:
 * weight = contribution_IN - withdrawal_OUT + (distributed loan_interest IN)  (all with user_id)
 * Note: We purposely DO NOT include account_id, because distributed interest uses account_id NULL.
 */
function get_member_weights(mysqli $mysqli): array {
    $sql = "
      SELECT
        u.id AS user_id,
        u.names,
        COALESCE(SUM(CASE WHEN t.type='contribution' AND t.direction='IN'  THEN t.amount ELSE 0 END),0) AS c_in,
        COALESCE(SUM(CASE WHEN t.type='withdrawal'   AND t.direction='OUT' THEN t.amount ELSE 0 END),0) AS w_out,
        COALESCE(SUM(CASE WHEN t.type='loan_interest' AND t.direction='IN' THEN t.amount ELSE 0 END),0) AS i_in
      FROM users u
      LEFT JOIN transactions t ON t.user_id = u.id
      WHERE u.is_member=1
      GROUP BY u.id, u.names
      ORDER BY u.names ASC
    ";
    $rs = $mysqli->query($sql);
    $rows = [];
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $weight = (float)$r['c_in'] - (float)$r['w_out'] + (float)$r['i_in'];
            $rows[] = [
                'user_id' => (int)$r['user_id'],
                'names' => $r['names'],
                'weight' => money(max(0, $weight))
            ];
        }
    }
    return $rows;
}

/**
 * Distribute interest amount to members proportional to weights.
 * Creates transactions:
 *  type='loan_interest', direction='IN', user_id=member, loan_id=..., account_id=NULL
 */
function distribute_interest(mysqli $mysqli, int $loan_id, float $interest_amount, string $tx_date, int $admin_id, string $note = ''): array {
    $interest_amount = (float)$interest_amount;
    if ($loan_id <= 0 || $interest_amount <= 0) {
        return ['ok'=>false, 'message'=>'Invalid loan/interest amount'];
    }

    $loan = $mysqli->prepare("SELECT status FROM loans WHERE loan_id=? LIMIT 1");
    if (!$loan) return ['ok'=>false,'message'=>'Prepare error'];
    $loan->bind_param('i', $loan_id);
    $loan->execute();
    $lr = $loan->get_result()->fetch_assoc();
    if (!$lr || ($lr['status'] ?? '') !== 'approved') {
        return ['ok'=>false, 'message'=>'Loan must be approved to distribute interest'];
    }

    $members = get_member_weights($mysqli);
    $sumW = 0.0;
    foreach ($members as $m) $sumW += (float)$m['weight'];

    if ($sumW <= 0.0) {
        return ['ok'=>false, 'message'=>'No eligible members (weights are zero).'];
    }

    // Allocate with rounding, last member gets remainder
    $allocations = [];
    $remaining = $interest_amount;

    // filter only positive weights
    $eligible = array_values(array_filter($members, fn($m) => (float)$m['weight'] > 0));

    $n = count($eligible);
    if ($n === 0) return ['ok'=>false,'message'=>'No eligible members (weights are zero).'];

    for ($i=0; $i<$n; $i++) {
        $m = $eligible[$i];
        if ($i === $n - 1) {
            $share = $remaining; // remainder
        } else {
            $share = round(($interest_amount * ((float)$m['weight'] / $sumW)), 2);
            if ($share > $remaining) $share = $remaining;
        }
        $remaining = round($remaining - $share, 2);

        if ($share > 0) {
            $allocations[] = [
                'user_id' => (int)$m['user_id'],
                'names' => $m['names'],
                'amount' => money($share)
            ];
        }
    }

    if (empty($allocations)) {
        return ['ok'=>false,'message'=>'Distribution resulted in 0 allocations'];
    }

    // Insert distribution ledger entries (account_id NULL)
    $stmt = $mysqli->prepare("
      INSERT INTO transactions
      (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
      VALUES (?, ?, ?, NULL, 'loan_interest', 'IN', ?, ?, ?)
    ");
    if (!$stmt) return ['ok'=>false,'message'=>$mysqli->error];

    $descBase = trim($note) !== '' ? $note : "Interest distributed (loan#$loan_id)";

    $created_ids = [];
    foreach ($allocations as $a) {
        $uid = (int)$a['user_id'];
        $amt = (float)$a['amount'];
        $desc = $descBase . " -> " . $a['names'];
        $stmt->bind_param('siidsi', $tx_date, $uid, $loan_id, $amt, $desc, $admin_id);
        if (!$stmt->execute()) {
            return ['ok'=>false,'message'=>$mysqli->error];
        }
        $created_ids[] = (int)$stmt->insert_id;
    }

    return [
        'ok'=>true,
        'allocations'=>$allocations,
        'created_transaction_ids'=>$created_ids
    ];
}

/**
 * Withdrawable + locks (reserve + guarantees + loan outstanding)
 */
function get_user_summary(mysqli $mysqli, int $user_id): array {
    $out = [
        'user_id' => $user_id,
        'is_member' => 0,
        'contributions_net' => 0.0,
        'interest_received' => 0.0,
        'approved_loans_total' => 0.0,
        'principal_paid' => 0.0,
        'principal_outstanding' => 0.0,
        'locked_guarantees' => 0.0,
        'reserve_min' => 120000.0,
        'withdrawable_max' => 0.0,
        'types_allowed' => []
    ];

    $st = $mysqli->prepare("SELECT is_member FROM users WHERE id=? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $user_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $out['is_member'] = (int)($r['is_member'] ?? 0);
    }

    $st = $mysqli->prepare("
      SELECT
        COALESCE(SUM(CASE WHEN type='contribution' AND direction='IN'  THEN amount ELSE 0 END),0) AS c_in,
        COALESCE(SUM(CASE WHEN type='withdrawal'   AND direction='OUT' THEN amount ELSE 0 END),0) AS w_out
      FROM transactions WHERE user_id=?
    ");
    if ($st) {
        $st->bind_param('i', $user_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $out['contributions_net'] = money(((float)$row['c_in']) - ((float)$row['w_out']));
    }

    // interest_received (distributed interest entries use account_id NULL, still counted)
    $st = $mysqli->prepare("
      SELECT COALESCE(SUM(amount),0) AS s
      FROM transactions
      WHERE user_id=? AND type='loan_interest' AND direction='IN'
    ");
    if ($st) {
        $st->bind_param('i', $user_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $out['interest_received'] = money((float)($row['s'] ?? 0));
    }

    $st = $mysqli->prepare("
      SELECT COALESCE(SUM(principal),0) AS s
      FROM loans
      WHERE borrower_user_id=? AND status='approved'
    ");
    if ($st) {
        $st->bind_param('i', $user_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $out['approved_loans_total'] = money((float)($row['s'] ?? 0));
    }

    $st = $mysqli->prepare("
      SELECT COALESCE(SUM(t.amount),0) AS s
      FROM transactions t
      INNER JOIN loans l ON l.loan_id=t.loan_id
      WHERE l.borrower_user_id=?
        AND l.status='approved'
        AND t.type='loan_principal'
        AND t.direction='IN'
    ");
    if ($st) {
        $st->bind_param('i', $user_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $out['principal_paid'] = money((float)($row['s'] ?? 0));
    }

    $out['principal_outstanding'] = money(max(0, $out['approved_loans_total'] - $out['principal_paid']));

    $st = $mysqli->prepare("
      SELECT COALESCE(SUM(lg.guarantee_amount),0) AS s
      FROM loan_guaranters lg
      INNER JOIN loans l ON l.loan_id = lg.loan_id
      WHERE lg.guarantor_user_id = ?
        AND lg.status = 'accepted'
        AND l.status = 'approved'
    ");
    if ($st) {
        $st->bind_param('i', $user_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $out['locked_guarantees'] = money((float)($row['s'] ?? 0));
    }

    $out['reserve_min'] = money($out['reserve_min']);

    $withdrawable = max(
        0,
        ($out['contributions_net'] + $out['interest_received'])
        - $out['principal_outstanding']
        - $out['locked_guarantees']
        - $out['reserve_min']
    );
    $out['withdrawable_max'] = money($withdrawable);

    $types = [];
    if ($out['is_member'] === 1) { $types[] = 'contribution'; $types[] = 'withdrawal'; }
    if ($out['approved_loans_total'] > 0.0) { $types[] = 'loan_principal'; $types[] = 'loan_interest'; }
    $types[] = 'expense';
    $out['types_allowed'] = array_values(array_unique($types));

    return $out;
}

/* =========================
   GET
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Search users
    if (isset($_GET['users'])) {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') send_json(['success'=>true,'data'=>[]]);
        $like = "%{$q}%";
        $st = $mysqli->prepare("
          SELECT id, names, nid_passport, phone1, phone2, is_member
          FROM users
          WHERE names LIKE ? OR nid_passport LIKE ? OR phone1 LIKE ? OR phone2 LIKE ?
          ORDER BY names ASC LIMIT 50
        ");
        if(!$st) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error], 500);
        $st->bind_param('ssss', $like, $like, $like, $like);
        $st->execute();
        $rs = $st->get_result();
        $rows = [];
        while($r = $rs->fetch_assoc()) $rows[] = $r;
        send_json(['success'=>true,'data'=>$rows]);
    }

    // Accounts list with balances
    if (isset($_GET['accounts'])) {
        $rs = $mysqli->query("SELECT account_id, name, type, account_number FROM accounts ORDER BY name ASC");
        $rows = [];
        if ($rs) {
            while ($r = $rs->fetch_assoc()) {
                $r['balance'] = money(get_account_balance($mysqli, (int)$r['account_id']));
                $rows[] = $r;
            }
        }
        send_json(['success'=>true,'data'=>$rows]);
    }

    // User summary
    if (isset($_GET['user_summary'])) {
        $uid = (int)($_GET['user_id'] ?? 0);
        if ($uid <= 0) send_json(['success'=>false,'message'=>'Invalid user_id'], 400);
        send_json(['success'=>true,'data'=>get_user_summary($mysqli, $uid)]);
    }

    // Loan summary
    if (isset($_GET['loan_summary'])) {
        $loan_id = (int)($_GET['loan_id'] ?? 0);
        $as_of = safe_date($_GET['as_of'] ?? '');
        if ($loan_id <= 0) send_json(['success'=>false,'message'=>'Invalid loan_id'], 400);
        send_json(['success'=>true,'data'=>get_loan_summary($mysqli, $loan_id, $as_of)]);
    }

    // Approved loans for user
    if (isset($_GET['user_loans'])) {
        $uid = (int)($_GET['user_id'] ?? 0);
        if ($uid <= 0) send_json(['success'=>false,'message'=>'Invalid user_id'], 400);

        $st = $mysqli->prepare("
          SELECT loan_id, principal, monthly_interest_rate, interest_method, start_date, status
          FROM loans
          WHERE borrower_user_id=? AND status='approved'
          ORDER BY approved_at DESC, loan_id DESC
        ");
        if(!$st) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error], 500);
        $st->bind_param('i', $uid);
        $st->execute();
        $rs = $st->get_result();
        $rows = [];
        while($r = $rs->fetch_assoc()) {
            $sum = get_loan_summary($mysqli, (int)$r['loan_id'], null);
            $r['principal_remaining'] = $sum['principal_remaining'];
            $r['interest_due'] = $sum['interest_due'];
            $rows[] = $r;
        }
        send_json(['success'=>true,'data'=>$rows]);
    }

    // Interest distribution preview (for disable-save conditions)
    // ?interest_preview=1&interest_amount=5000&loan_id=12
    if (isset($_GET['interest_preview'])) {
        $loan_id = (int)($_GET['loan_id'] ?? 0);
        $amt = (float)($_GET['interest_amount'] ?? 0);
        if ($loan_id <= 0 || $amt <= 0) send_json(['success'=>false,'message'=>'Invalid loan_id/interest_amount'], 400);

        $members = get_member_weights($mysqli);
        $sumW = 0.0;
        foreach ($members as $m) $sumW += (float)$m['weight'];

        if ($sumW <= 0) {
            send_json([
                'success'=>false,
                'message'=>'No eligible members (weights are zero). Disable save.',
                'data'=>['sum_weights'=>0,'members'=>$members]
            ], 400);
        }

        // build allocations same way (without insert)
        $eligible = array_values(array_filter($members, fn($m) => (float)$m['weight'] > 0));
        $alloc = [];
        $remaining = $amt;
        $n = count($eligible);

        for ($i=0; $i<$n; $i++) {
            $m = $eligible[$i];
            if ($i === $n - 1) $share = $remaining;
            else {
                $share = round(($amt * ((float)$m['weight'] / $sumW)), 2);
                if ($share > $remaining) $share = $remaining;
            }
            $remaining = round($remaining - $share, 2);
            if ($share > 0) $alloc[] = ['user_id'=>$m['user_id'],'names'=>$m['names'],'amount'=>money($share)];
        }

        send_json(['success'=>true,'data'=>[
            'interest_amount'=>money($amt),
            'sum_weights'=>money($sumW),
            'allocations'=>$alloc
        ]]);
    }

    // Default list transactions (same as before, short)
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, (int)($_GET['per_page'] ?? 50));
    $q        = trim((string)($_GET['q'] ?? ''));
    $type     = trim((string)($_GET['type'] ?? ''));
    $user_id  = (int)($_GET['user_id'] ?? 0);
    $account_id = (int)($_GET['account_id'] ?? 0);
    $loan_id  = (int)($_GET['loan_id'] ?? 0);

    $where = " WHERE 1=1 ";
    $params = [];
    $types  = "";

    if ($q !== '') {
        $where .= " AND (t.description LIKE ? OR u.names LIKE ? OR u.nid_passport LIKE ? OR u.phone1 LIKE ? OR u.phone2 LIKE ? OR a.name LIKE ?) ";
        $like = "%{$q}%";
        $params = array_merge($params, [$like,$like,$like,$like,$like,$like]);
        $types .= "ssssss";
    }
    if ($type !== '') {
        if (!in_array($type, allowed_types(), true)) send_json(['success'=>false,'message'=>'Type not allowed'], 400);
        $where .= " AND t.type=? ";
        $params[] = $type;
        $types .= "s";
    }
    if ($user_id > 0) { $where .= " AND t.user_id=? "; $params[] = $user_id; $types .= "i"; }
    if ($account_id > 0) { $where .= " AND t.account_id=? "; $params[] = $account_id; $types .= "i"; }
    if ($loan_id > 0) { $where .= " AND t.loan_id=? "; $params[] = $loan_id; $types .= "i"; }

    $countSql = "
      SELECT COUNT(*) AS cnt
      FROM transactions t
      LEFT JOIN users u ON u.id=t.user_id
      LEFT JOIN accounts a ON a.account_id=t.account_id
      $where
    ";
    $st = $mysqli->prepare($countSql);
    if(!$st) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error], 500);
    if ($types) $st->bind_param($types, ...$params);
    $st->execute();
    $total = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);

    $offset = ($page - 1) * $per_page;

    $listSql = "
      SELECT
        t.transaction_id, t.tx_date,
        t.user_id, u.names AS user_name, u.nid_passport, u.phone1, u.phone2,
        t.loan_id,
        t.account_id, a.name AS account_name,
        t.type, t.direction, t.amount, t.description,
        t.created_by, t.created_at
      FROM transactions t
      LEFT JOIN users u ON u.id=t.user_id
      LEFT JOIN accounts a ON a.account_id=t.account_id
      $where
      ORDER BY t.tx_date DESC, t.transaction_id DESC
      LIMIT $offset, $per_page
    ";
    $st = $mysqli->prepare($listSql);
    if(!$st) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error], 500);
    if ($types) $st->bind_param($types, ...$params);
    $st->execute();
    $rs = $st->get_result();
    $rows = [];
    while($r = $rs->fetch_assoc()) $rows[] = $r;

    send_json(['success'=>true,'data'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$per_page]);
}

/* =========================
   POST
========================= */
$action = (string)($_POST['action'] ?? '');

/**
 * Create single transaction (contribution/withdrawal/expense/manual loan_principal or loan_interest)
 */
if ($action === 'create') {

    $account_id = (int)($_POST['account_id'] ?? 0);
    $type = trim((string)($_POST['type'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $tx_day = safe_date($_POST['tx_date'] ?? '') ?: date('Y-m-d');
    $description = trim((string)($_POST['description'] ?? ''));

    $user_id = (int)($_POST['user_id'] ?? 0);
    $loan_id = (int)($_POST['loan_id'] ?? 0);

    if ($account_id <= 0) send_json(['success'=>false,'message'=>'Select account'], 400);
    if ($amount <= 0) send_json(['success'=>false,'message'=>'Invalid amount'], 400);

    if (!in_array($type, allowed_types(), true)) send_json(['success'=>false,'message'=>'Type not allowed'], 400);
    $direction = direction_for_type($type);
    if (!$direction) send_json(['success'=>false,'message'=>'Invalid type'], 400);

    if (in_array($type, ['contribution','withdrawal'], true)) {
        if ($user_id <= 0) send_json(['success'=>false,'message'=>'Select user first'], 400);
    } else if (in_array($type, ['loan_principal','loan_interest'], true)) {
        if ($loan_id <= 0) send_json(['success'=>false,'message'=>'Select loan first'], 400);
        if ($user_id <= 0) {
            $st = $mysqli->prepare("SELECT borrower_user_id FROM loans WHERE loan_id=? LIMIT 1");
            if ($st) { $st->bind_param('i', $loan_id); $st->execute(); $row = $st->get_result()->fetch_assoc(); $user_id = (int)($row['borrower_user_id'] ?? 0); }
        }
        if ($user_id <= 0) send_json(['success'=>false,'message'=>'Loan borrower not found'], 400);
    } else if ($type === 'expense') {
        $user_id = 0; $loan_id = 0;
    }

    // OUT <= account balance
    if ($direction === 'OUT') {
        $bal = get_account_balance($mysqli, $account_id);
        if ($amount > $bal + 0.00001) send_json(['success'=>false,'message'=>"Insufficient account balance. Balance=".money($bal)], 400);
    }

    // Withdrawal <= withdrawable_max
    if ($type === 'withdrawal') {
        $sum = get_user_summary($mysqli, $user_id);
        if ($amount > (float)$sum['withdrawable_max'] + 0.00001) {
            send_json(['success'=>false,'message'=>"Withdrawal exceeds allowed limit. Max=".money($sum['withdrawable_max']), 'data'=>$sum], 400);
        }
    }

    $tx_date = $tx_day . ' ' . date('H:i:s');

    $mysqli->begin_transaction();
    try {
        // Insert main row (cash movement if account_id provided)
        $sql = "
          INSERT INTO transactions
          (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
          VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?, ?, ?)
        ";
        $st = $mysqli->prepare($sql);
        if(!$st) throw new Exception($mysqli->error);

        $st->bind_param('siiissdsi', $tx_date, $user_id, $loan_id, $account_id, $type, $direction, $amount, $description, $admin_id);
        if(!$st->execute()) throw new Exception($mysqli->error);
        $main_id = (int)$st->insert_id;

        // If this is a loan_interest receipt, distribute immediately
        if ($type === 'loan_interest' && $loan_id > 0) {
            $dist = distribute_interest($mysqli, $loan_id, $amount, $tx_date, $admin_id, "Interest distribution from TX#$main_id (loan#$loan_id)");
            if (!$dist['ok']) throw new Exception("Interest distribution failed: ".$dist['message']);
        }

        $mysqli->commit();

        nig_notify_admins($mysqli, 'transaction_recorded', "Transaction (#TX-$main_id) type=$type amount=" . number_format($amount) . " Frw");

        send_json(['success'=>true,'transaction_id'=>$main_id]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success'=>false,'message'=>'Failed', 'error'=>$e->getMessage()], 500);
    }
}

/**
 * Loan payment split TOTAL: interest first then principal
 * - saves interest receipt to account_id once
 * - distributes that interest to members
 * - saves principal payment to account_id
 */
if ($action === 'loan_payment_split') {

    $loan_id = (int)($_POST['loan_id'] ?? 0);
    $account_id = (int)($_POST['account_id'] ?? 0);
    $total = (float)($_POST['total_amount'] ?? 0);
    $tx_day = safe_date($_POST['tx_date'] ?? '') ?: date('Y-m-d');
    $description = trim((string)($_POST['description'] ?? 'Loan payment'));

    if ($loan_id <= 0 || $account_id <= 0 || $total <= 0) send_json(['success'=>false,'message'=>'Missing loan/account/amount'], 400);

    $sum = get_loan_summary($mysqli, $loan_id, $tx_day);
    if (($sum['status'] ?? '') !== 'approved') send_json(['success'=>false,'message'=>'Loan must be approved'], 400);

    $interest_due = (float)$sum['interest_due'];
    $principal_remaining = (float)$sum['principal_remaining'];

    $pay_interest = min($total, $interest_due);
    $remain_after_interest = $total - $pay_interest;
    $pay_principal = min($remain_after_interest, $principal_remaining);

    if ($pay_interest <= 0 && $pay_principal <= 0) send_json(['success'=>false,'message'=>'Nothing to pay'], 400);

    $borrower_id = (int)$sum['borrower_user_id'];
    if ($borrower_id <= 0) send_json(['success'=>false,'message'=>'Borrower not found'], 400);

    $tx_date = $tx_day . ' ' . date('H:i:s');

    $mysqli->begin_transaction();
    try {
        $created_ids = [];

        // 1) Interest receipt (cash) + distribution
        if ($pay_interest > 0) {
            $st = $mysqli->prepare("
              INSERT INTO transactions
              (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
              VALUES (?, ?, ?, ?, 'loan_interest', 'IN', ?, ?, ?)
            ");
            if(!$st) throw new Exception($mysqli->error);

            $descI = $description . " (interest receipt)";
            $st->bind_param('siiidsi', $tx_date, $borrower_id, $loan_id, $account_id, $pay_interest, $descI, $admin_id);
            if(!$st->execute()) throw new Exception($mysqli->error);
            $interest_tx_id = (int)$st->insert_id;
            $created_ids[] = $interest_tx_id;

            // Distribute interest to members (ledger entries account_id NULL)
            $dist = distribute_interest($mysqli, $loan_id, $pay_interest, $tx_date, $admin_id, "Interest distribution from TX#$interest_tx_id (loan#$loan_id)");
            if (!$dist['ok']) throw new Exception("Interest distribution failed: ".$dist['message']);
            $created_ids = array_merge($created_ids, $dist['created_transaction_ids']);
        }

        // 2) Principal receipt (cash)
        if ($pay_principal > 0) {
            $st = $mysqli->prepare("
              INSERT INTO transactions
              (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
              VALUES (?, ?, ?, ?, 'loan_principal', 'IN', ?, ?, ?)
            ");
            if(!$st) throw new Exception($mysqli->error);

            $descP = $description . " (principal)";
            $st->bind_param('siiidsi', $tx_date, $borrower_id, $loan_id, $account_id, $pay_principal, $descP, $admin_id);
            if(!$st->execute()) throw new Exception($mysqli->error);
            $created_ids[] = (int)$st->insert_id;
        }

        $mysqli->commit();

        $sum2 = get_loan_summary($mysqli, $loan_id, $tx_day);

        send_json([
            'success'=>true,
            'created_transaction_ids'=>$created_ids,
            'split'=>[
                'interest_paid_now'=>money($pay_interest),
                'principal_paid_now'=>money($pay_principal),
                'total_received'=>money($pay_interest + $pay_principal),
            ],
            'loan_summary_after'=>$sum2
        ]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success'=>false,'message'=>'Failed to save payment', 'error'=>$e->getMessage()], 500);
    }
}

send_json(['success'=>false,'message'=>'Invalid request'], 400);
?>