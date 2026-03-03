<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
session_start();
ob_start();

function send_json($data, int $code = 200) {
    while (ob_get_level() > 0) { ob_end_clean(); }   // ✅ always clean buffers
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

register_shutdown_function(function(){
    $err = error_get_last();
    $buf = '';

    while (ob_get_level() > 0) {
        $buf .= ob_get_clean();
    }

    $logFile = __DIR__ . '/loans_debug.log';
    if (trim($buf) !== '') {
        @file_put_contents($logFile, date('c')." - BUFFERED OUTPUT:\n".$buf."\n\n", FILE_APPEND|LOCK_EX);
    }

    if ($err) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'=>false,
            'message'=>'Fatal error',
            'error'=>$err,
        ]);
        exit;
    }
});

$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) send_json(['success'=>false,'message'=>'Database connection failed'], 500);

require_once __DIR__ . '/notifications_helper.php';

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    send_json(['success'=>false,'message'=>'Access denied'], 403);
}

$admin_user_id = (int)($_SESSION['user_id'] ?? 0);

// Use phone1 as display phone (your schema)
const USER_PHONE_COL = 'phone1';
const USER_PHONE2_COL = 'phone2';

function table_exists(mysqli $mysqli, string $table): bool {
    $t = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$t'");
    return $res && $res->num_rows > 0;
}

/**
 * Net = contribution + interests_received - (total_loans + withdrawals + guaranteed_to_others + 120000)
 * Returns breakdown array; net is clamped to >= 0.
 */
function get_user_net(mysqli $mysqli, int $user_id): array {
    $uid = (int)$user_id;

    $contrib = 0.0;
    $withdraw = 0.0;
    $interest = 0.0;

    $q1 = $mysqli->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='contribution' THEN amount ELSE 0 END),0) AS contrib,
            COALESCE(SUM(CASE WHEN type='withdrawal_deduction' THEN amount ELSE 0 END),0) AS withdraws,
            COALESCE(SUM(CASE WHEN type='interest_received' THEN amount ELSE 0 END),0) AS interests
        FROM transactions
        WHERE user_id = ?
    ");
    if ($q1) {
        $q1->bind_param('i', $uid);
        $q1->execute();
        $r = $q1->get_result()->fetch_assoc();
        $contrib  = (float)($r['contrib'] ?? 0);
        $withdraw = (float)($r['withdraws'] ?? 0);
        $interest = (float)($r['interests'] ?? 0);
        $q1->close();
    }

    // total loans principal (exclude rejected)
    $loans = 0.0;
    $q2 = $mysqli->prepare("
        SELECT COALESCE(SUM(principal_amount),0) AS total_loans
        FROM loans
        WHERE borrower_user_id = ?
          AND status <> 'rejected'
    ");
    if ($q2) {
        $q2->bind_param('i', $uid);
        $q2->execute();
        $r = $q2->get_result()->fetch_assoc();
        $loans = (float)($r['total_loans'] ?? 0);
        $q2->close();
    }

    // guaranteed_to_others
    $guaranteed = 0.0;
    $q3 = $mysqli->prepare("
        SELECT COALESCE(SUM(lg.guarantee_amount),0) AS total_guaranteed
        FROM loan_guarantors lg
        INNER JOIN loans l ON l.loan_id = lg.loan_id
        WHERE lg.guarantor_user_id = ?
          AND lg.status = 'approved'
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

    $net_raw = ($contrib + $interest) - ($loans + $withdraw + $guaranteed + $reserve);
    $net = max(0.0, $net_raw);

    return [
        'contrib' => $contrib,
        'withdrawals' => $withdraw,
        'interest' => $interest,
        'loans_principal' => $loans,
        'guaranteed_to_others' => $guaranteed,
        'reserve' => $reserve,
        'net_raw' => $net_raw,
        'net' => $net,
    ];
}

function get_user_unpaid_loans(mysqli $mysqli, int $user_id): float {
    $uid = (int)$user_id;
    $hasPayments = table_exists($mysqli, 'loan_payments');

    if ($hasPayments) {
        $sql = "
            SELECT COALESCE(SUM(GREATEST(l.principal_amount - COALESCE(p.paid,0),0)),0) AS unpaid
            FROM loans l
            LEFT JOIN (
                SELECT loan_id, COALESCE(SUM(amount),0) AS paid
                FROM loan_payments
                GROUP BY loan_id
            ) p ON p.loan_id = l.loan_id
            WHERE l.borrower_user_id = ?
              AND l.status IN ('approved','defaulted')
        ";
        $st = $mysqli->prepare($sql);
        if ($st) {
            $st->bind_param('i', $uid);
            $st->execute();
            $r = $st->get_result()->fetch_assoc();
            $st->close();
            return (float)($r['unpaid'] ?? 0);
        }
        return 0.0;
    }

    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(principal_amount),0) AS unpaid
        FROM loans
        WHERE borrower_user_id = ?
          AND status IN ('approved','defaulted')
    ");
    if ($st) {
        $st->bind_param('i', $uid);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return (float)($r['unpaid'] ?? 0);
    }
    return 0.0;
}

// ---------------------------
// GET
// ---------------------------
$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // search users (borrower select)
    if ($action === 'search_users') {
        $q = trim($_GET['q'] ?? '');
        $limit = 20;
        $qLike = '%' . $q . '%';

        $stmt = $mysqli->prepare("
            SELECT id, names, " . USER_PHONE_COL . " AS phone, " . USER_PHONE2_COL . " AS phone2, is_member
            FROM users
            WHERE (names LIKE ? OR " . USER_PHONE_COL . " LIKE ? OR " . USER_PHONE2_COL . " LIKE ?)
            ORDER BY names ASC
            LIMIT $limit
        ");
        if (!$stmt) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error], 500);
        $stmt->bind_param('sss', $qLike, $qLike, $qLike);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        send_json(['success'=>true,'data'=>$rows]);
    }

    // borrower summary
    if ($action === 'borrower_summary') {
        $uid = (int)($_GET['user_id'] ?? 0);
        if ($uid <= 0) send_json(['success'=>false,'message'=>'Invalid user'], 400);

        $uRes = $mysqli->query("
            SELECT id, names, " . USER_PHONE_COL . " AS phone, " . USER_PHONE2_COL . " AS phone2, is_member
            FROM users
            WHERE id=".(int)$uid." LIMIT 1
        ");
        $u = $uRes ? $uRes->fetch_assoc() : null;
        if (!$u) send_json(['success'=>false,'message'=>'User not found'], 404);

        $fin = get_user_net($mysqli, $uid);          // ✅ breakdown
        $unpaid = get_user_unpaid_loans($mysqli, $uid);

        send_json([
            'success'=>true,
            'data'=>[
                'id'=>(int)$u['id'],
                'names'=>$u['names'],
                'phone'=>$u['phone'] ?? '',
                'phone2'=>$u['phone2'] ?? '',
                'is_member'=>(int)($u['is_member'] ?? 0),

                // ✅ numeric net for simple use
                'net_value'=>(float)$fin['net'],

                // ✅ full breakdown for UI explanation
                'net_breakdown'=>$fin,

                'unpaid_loans'=>$unpaid
            ]
        ]);
    }

    // eligible guarantors
    if ($action === 'eligible_guarantors') {
        $q = trim($_GET['q'] ?? '');
        $borrower_id = (int)($_GET['borrower_id'] ?? 0);
        $limit = 30;
        $qLike = '%' . $q . '%';

        $stmt = $mysqli->prepare("
            SELECT id, names, " . USER_PHONE_COL . " AS phone, " . USER_PHONE2_COL . " AS phone2
            FROM users
            WHERE is_member = 1
              AND id <> ?
              AND (names LIKE ? OR " . USER_PHONE_COL . " LIKE ? OR " . USER_PHONE2_COL . " LIKE ?)
            ORDER BY names ASC
            LIMIT $limit
        ");
        if (!$stmt) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error], 500);
        $stmt->bind_param('isss', $borrower_id, $qLike, $qLike, $qLike);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $fin = get_user_net($mysqli, (int)$r['id']);
            $net = (float)$fin['net']; // ✅ numeric
            if ($net > 0) {
                $r['net_value'] = $net;
                $r['net_breakdown'] = $fin; // optional: remove if you want lighter response
                $rows[] = $r;
            }
        }
        $stmt->close();
        send_json(['success'=>true,'data'=>$rows]);
    }

    // loan details
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];

        $stmt = $mysqli->prepare("
            SELECT
              l.*,
              u.names AS borrower_name,
              CONCAT_WS(' / ', u." . USER_PHONE_COL . ", u." . USER_PHONE2_COL . ") AS borrower_phone,
              u.is_member AS borrower_is_member,
              a.name AS account_name,
              ap.names AS approved_by_name
            FROM loans l
            LEFT JOIN users u ON l.borrower_user_id = u.id
            LEFT JOIN accounts a ON l.account_id = a.account_id
            LEFT JOIN users ap ON l.approved_by = ap.id
            WHERE l.loan_id = ?
            LIMIT 1
        ");
        if (!$stmt) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error], 500);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) send_json(['success'=>false,'message'=>'Not found'], 404);

        // guarantors
        $gua = [];
        $gStmt = $mysqli->prepare("
            SELECT lg.loan_guarantor_id, lg.guarantor_user_id, lg.guarantee_amount, lg.status,
                   u.names AS guarantor_name,
                   CONCAT_WS(' / ', u." . USER_PHONE_COL . ", u." . USER_PHONE2_COL . ") AS guarantor_phone
            FROM loan_guarantors lg
            LEFT JOIN users u ON lg.guarantor_user_id = u.id
            WHERE lg.loan_id = ?
            ORDER BY lg.loan_guarantor_id ASC
        ");
        if ($gStmt) {
            $gStmt->bind_param('i', $id);
            $gStmt->execute();
            $gr = $gStmt->get_result();
            while ($g = $gr->fetch_assoc()) {
                $gFin = get_user_net($mysqli, (int)$g['guarantor_user_id']);
                $g['guarantor_net'] = (float)$gFin['net'];      // ✅ numeric for quick UI
                $g['guarantor_breakdown'] = $gFin;              // ✅ explain
                $gua[] = $g;
            }
            $gStmt->close();
        }
        $row['guarantors'] = $gua;

        $bFin = get_user_net($mysqli, (int)$row['borrower_user_id']);
        $bUnpaid = get_user_unpaid_loans($mysqli, (int)$row['borrower_user_id']);
        $row['borrower_net_value'] = (float)$bFin['net'];
        $row['borrower_net_breakdown'] = $bFin;
        $row['borrower_unpaid_loans'] = $bUnpaid;

        send_json(['success'=>true,'data'=>$row]);
    }

    // list loans (table)
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 50));
    $q = trim($_GET['q'] ?? '');

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
    $totalRow = $totalRes ? $totalRes->fetch_assoc() : ['cnt'=>0];
    $total = (int)($totalRow['cnt'] ?? 0);

    $offset = ($page - 1) * $per_page;

    $sql = "
        SELECT
          l.loan_id,
          l.borrower_user_id,
          l.principal_amount,
          l.start_date,
          l.status,
          u.names AS borrower_name,
          CONCAT_WS(' / ', u." . USER_PHONE_COL . ", u." . USER_PHONE2_COL . ") AS borrower_phone
        FROM loans l
        LEFT JOIN users u ON l.borrower_user_id = u.id
        $where
        ORDER BY l.loan_id DESC
        LIMIT $offset, $per_page
    ";
    $res = $mysqli->query($sql);
    if (!$res) send_json(['success'=>false,'message'=>'Query error: '.$mysqli->error], 500);

    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;

    send_json(['success'=>true,'data'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$per_page]);
}

// ---------------------------
// POST actions
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['success'=>false,'message'=>'Invalid request'], 400);
}

$action = $_POST['action'] ?? '';

function parse_guarantors(string $json): array {
    $arr = json_decode($json, true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $g) {
        $uid = (int)($g['user_id'] ?? 0);
        $amt = (float)($g['amount'] ?? 0);
        if ($uid > 0 && $amt > 0) $out[] = ['user_id'=>$uid,'amount'=>$amt];
    }
    return $out;
}

function validate_guarantees(mysqli $mysqli, int $borrower_id, float $principal, array $guarantors): array {
    $uRes = $mysqli->query("SELECT id, is_member FROM users WHERE id=".(int)$borrower_id." LIMIT 1");
    $u = $uRes ? $uRes->fetch_assoc() : null;
    if (!$u) return [false, "Borrower not found"];

    $is_member = (int)($u['is_member'] ?? 0);

    $bFin = get_user_net($mysqli, $borrower_id);
    $borrower_net = (float)$bFin['net']; // ✅ numeric net only

    $required = ($is_member === 1)
        ? max(0.0, $principal - $borrower_net)
        : $principal;

    if ($required <= 0.0) return [true, null];

    if (count($guarantors) === 0) return [false, "This borrower needs guarantor(s) to cover ".number_format($required)." Frw"];

    $sum = 0.0;
    $seen = [];
    foreach ($guarantors as $g) {
        $gid = (int)$g['user_id'];
        $amt = (float)$g['amount'];

        if ($gid === $borrower_id) return [false, "Borrower cannot guarantee own loan"];
        if (isset($seen[$gid])) return [false, "Duplicate guarantor selected"];
        $seen[$gid] = true;

        $guRes = $mysqli->query("SELECT id, is_member FROM users WHERE id=".(int)$gid." LIMIT 1");
        $gu = $guRes ? $guRes->fetch_assoc() : null;
        if (!$gu) return [false, "Guarantor not found (ID $gid)"];
        if ((int)$gu['is_member'] !== 1) return [false, "Guarantor must be a member"];

        $gFin = get_user_net($mysqli, $gid);
        $gNet = (float)$gFin['net'];

        if ($gNet <= 0) return [false, "Guarantor has no net value available"];
        if ($amt > $gNet) return [false, "Guarantor amount exceeds guarantor net value (max ".number_format($gNet)." Frw)"];

        $sum += $amt;
    }

    if ($sum + 0.00001 < $required) {
        return [false, "Guarantors total ".number_format($sum)." Frw is not enough. Required ".number_format($required)." Frw"];
    }

    return [true, null];
}

function save_guarantors(mysqli $mysqli, int $loan_id, array $guarantors): bool {
    $del = $mysqli->prepare("DELETE FROM loan_guarantors WHERE loan_id = ?");
    if (!$del) return false;
    $del->bind_param('i', $loan_id);
    if (!$del->execute()) return false;
    $del->close();

    foreach ($guarantors as $g) {
        $gid = (int)$g['user_id'];
        $amt = (float)$g['amount'];
        $ins = $mysqli->prepare("
            INSERT INTO loan_guarantors (loan_id, guarantor_user_id, guarantee_amount, status)
            VALUES (?, ?, ?, 'pending')
        ");
        if (!$ins) return false;
        $ins->bind_param('iid', $loan_id, $gid, $amt);
        if (!$ins->execute()) return false;
        $ins->close();
    }
    return true;
}

// CREATE
if ($action === 'create') {
    $account_id = (int)($_POST['account_id'] ?? 0);
    $borrower_id = (int)($_POST['borrower_user_id'] ?? 0);
    $principal = (float)($_POST['principal_amount'] ?? 0);
    $monthly_rate = (float)($_POST['monthly_rate'] ?? 0);
    $term = (int)($_POST['term_months'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($account_id<=0 || $borrower_id<=0 || $principal<=0 || $term<=0) {
        send_json(['success'=>false,'message'=>'Imirima yibanze irabuze cyangwa ifite agaciro kadasobanutse'], 400);
    }

    $guarantors = parse_guarantors($_POST['guarantors'] ?? '[]');
    [$ok, $msg] = validate_guarantees($mysqli, $borrower_id, $principal, $guarantors);
    if (!$ok) send_json(['success'=>false,'message'=>$msg], 400);

    $ref_name = null; $ref_mime = null; $ref_blob = null;
    if (!empty($_FILES['reference_file']) && is_uploaded_file($_FILES['reference_file']['tmp_name'])) {
        $ref_name = $_FILES['reference_file']['name'];
        $ref_mime = $_FILES['reference_file']['type'];
        $ref_blob = file_get_contents($_FILES['reference_file']['tmp_name']);
    }

    $mysqli->begin_transaction();
    try {
        if ($ref_blob !== null) {
            // Insert without blob first, then update blob (safer)
            $sql = "INSERT INTO loans
                (account_id, borrower_user_id, principal_amount, monthly_rate, term_months, start_date, status, approved_by, notes, reference_name, reference_mime)
                VALUES (?, ?, ?, ?, ?, NULL, 'requested', NULL, ?, ?, ?)";
            $st = $mysqli->prepare($sql);
            if (!$st) throw new Exception($mysqli->error);

            // i i d d i s s s  => "iiddisss"
            $st->bind_param('iiddisss', $account_id, $borrower_id, $principal, $monthly_rate, $term, $notes, $ref_name, $ref_mime);
            if (!$st->execute()) throw new Exception($st->error);
            $loan_id = (int)$st->insert_id;
            $st->close();

            $up = $mysqli->prepare("UPDATE loans SET reference_file = ? WHERE loan_id = ?");
            if (!$up) throw new Exception($mysqli->error);

            $null = null;
            $up->bind_param('bi', $null, $loan_id);
            $up->send_long_data(0, $ref_blob);
            if (!$up->execute()) throw new Exception($up->error);
            $up->close();
        } else {
            $sql = "INSERT INTO loans
                (account_id, borrower_user_id, principal_amount, monthly_rate, term_months, start_date, status, approved_by, notes, reference_name, reference_mime)
                VALUES (?, ?, ?, ?, ?, NULL, 'requested', NULL, ?, ?, ?)";
            $st = $mysqli->prepare($sql);
            if (!$st) throw new Exception($mysqli->error);

            $st->bind_param('iiddisss', $account_id, $borrower_id, $principal, $monthly_rate, $term, $notes, $ref_name, $ref_mime);
            if (!$st->execute()) throw new Exception($st->error);
            $loan_id = (int)$st->insert_id;
            $st->close();
        }

        if (!save_guarantors($mysqli, $loan_id, $guarantors)) throw new Exception("Error saving guarantors");

        $mysqli->commit();

        $msgN = "Inguzanyo nshya yanditswe (#LN-$loan_id): " . number_format((float)$principal) . " Frw";
        notify_admins($mysqli, 'loan_requested', $msgN);
        create_notification($mysqli, (int)$borrower_id, 'loan_requested', $msgN);

        send_json(['success'=>true,'data'=>['loan_id'=>$loan_id]]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// UPDATE
if ($action === 'update') {
    $loan_id = (int)($_POST['id'] ?? 0);
    if ($loan_id<=0) send_json(['success'=>false,'message'=>'Invalid id'], 400);

    $account_id = (int)($_POST['account_id'] ?? 0);
    $borrower_id = (int)($_POST['borrower_user_id'] ?? 0);
    $principal = (float)($_POST['principal_amount'] ?? 0);
    $monthly_rate = (float)($_POST['monthly_rate'] ?? 0);
    $term = (int)($_POST['term_months'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($account_id<=0 || $borrower_id<=0 || $principal<=0 || $term<=0) {
        send_json(['success'=>false,'message'=>'Imirima yibanze irabuze cyangwa ifite agaciro kadasobanutse'], 400);
    }

    $guarantors = parse_guarantors($_POST['guarantors'] ?? '[]');
    [$ok, $msg] = validate_guarantees($mysqli, $borrower_id, $principal, $guarantors);
    if (!$ok) send_json(['success'=>false,'message'=>$msg], 400);

    $mysqli->begin_transaction();
    try {
        $st = $mysqli->prepare("
            UPDATE loans
            SET account_id=?, borrower_user_id=?, principal_amount=?, monthly_rate=?, term_months=?, notes=?
            WHERE loan_id=?
        ");
        if (!$st) throw new Exception($mysqli->error);

        // i i d d i s i  => "iiddisi"
        $st->bind_param('iiddisi', $account_id, $borrower_id, $principal, $monthly_rate, $term, $notes, $loan_id);

        if (!$st->execute()) throw new Exception($st->error);
        $st->close();

        if (!save_guarantors($mysqli, $loan_id, $guarantors)) throw new Exception("Error saving guarantors");

        $mysqli->commit();
        send_json(['success'=>true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// CHANGE STATUS
if ($action === 'change_status') {
    $loan_id = (int)($_POST['id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    $allowed = ['requested','approved','disbursed','closed','rejected','defaulted'];
    if ($loan_id<=0 || !in_array($newStatus, $allowed, true)) {
        send_json(['success'=>false,'message'=>'Invalid request'], 400);
    }
    if ($admin_user_id<=0) send_json(['success'=>false,'message'=>'Missing admin session user_id'], 500);

    $oldRes = $mysqli->query("SELECT status, borrower_user_id, start_date FROM loans WHERE loan_id=".(int)$loan_id." LIMIT 1");
    $oldRow = $oldRes ? $oldRes->fetch_assoc() : null;
    if (!$oldRow) send_json(['success'=>false,'message'=>'Loan not found'], 404);

    $oldStatus  = $oldRow['status'] ?? '';
    $borrower_id = (int)($oldRow['borrower_user_id'] ?? 0);
    $start_date = $oldRow['start_date'] ?? null;

    $mysqli->begin_transaction();
    try {
        if ($newStatus === 'approved' && (empty($start_date) || $start_date === '0000-00-00')) {
            // ✅ set start_date now
            $st = $mysqli->prepare("UPDATE loans SET status=?, approved_by=?, start_date=CURDATE() WHERE loan_id=?");
            if (!$st) throw new Exception($mysqli->error);
            $st->bind_param('sii', $newStatus, $admin_user_id, $loan_id);
        } else {
            // ✅ keep start_date as-is
            $st = $mysqli->prepare("UPDATE loans SET status=?, approved_by=? WHERE loan_id=?");
            if (!$st) throw new Exception($mysqli->error);
            $st->bind_param('sii', $newStatus, $admin_user_id, $loan_id);
        }

        if (!$st->execute()) throw new Exception($st->error);
        $st->close();

        $mysqli->commit();

        if ($oldStatus !== $newStatus) {
            $msgN = "Status y'inguzanyo (#LN-$loan_id) yahindutse: $oldStatus → $newStatus";
            if ($borrower_id>0) create_notification($mysqli, $borrower_id, 'loan_status_changed', $msgN);
            notify_admins($mysqli, 'loan_status_changed', $msgN);
        }

        send_json(['success'=>true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// DELETE
if ($action === 'delete') {
    $loan_id = (int)($_POST['id'] ?? 0);
    if ($loan_id<=0) send_json(['success'=>false,'message'=>'Invalid id'], 400);

    $mysqli->begin_transaction();
    try {
        $g = $mysqli->prepare("DELETE FROM loan_guarantors WHERE loan_id=?");
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
        send_json(['success'=>true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

send_json(['success'=>false,'message'=>'Invalid request'], 400);