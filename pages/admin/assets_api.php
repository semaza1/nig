<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function send_json($data, int $code = 200): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
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
        $buf .= (string)ob_get_clean();
    }

    $logFile = __DIR__ . '/assets_debug.log';

    if (trim($buf) !== '') {
        @file_put_contents(
            $logFile,
            date('c') . " - NON-JSON OUTPUT:\n" . $buf . "\n\n",
            FILE_APPEND | LOCK_EX
        );
    }

    if ($err) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error',
            'error'   => ($err['message'] ?? 'Unknown error') . ' in ' . ($err['file'] ?? '') . ' on line ' . ($err['line'] ?? ''),
        ]);
        exit;
    }

    if (trim($buf) !== '') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Unexpected output detected. See assets_debug.log'
        ]);
        exit;
    }
});

$mysqli = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/notifications_helper.php';

if (!$mysqli) {
    send_json(['success' => false, 'message' => 'Database connection failed'], 500);
}

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    send_json(['success' => false, 'message' => 'Access denied'], 403);
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);
if ($admin_id <= 0) {
    send_json(['success' => false, 'message' => 'Missing admin session user_id'], 500);
}

/* =========================================================
   Helpers
========================================================= */

function clean_str($value): ?string {
    if ($value === null) return null;
    $v = trim((string)$value);
    return $v === '' ? null : $v;
}

function valid_date_or_null($value): ?string {
    $v = clean_str($value);
    if ($v === null) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    return $v;
}

function money($x): float {
    return (float)number_format((float)$x, 2, '.', '');
}

function allowed_certificate_mimes(): array {
    return [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf'
    ];
}

function get_uploaded_certificate(string $field = 'certificate_file', bool $required = false): array {
    if (!isset($_FILES[$field])) {
        if ($required) return [false, null, 'Certificate file is required'];
        return [true, null, null];
    }

    $file = $_FILES[$field];
    $errCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errCode === UPLOAD_ERR_NO_FILE) {
        if ($required) return [false, null, 'Certificate file is required'];
        return [true, null, null];
    }

    if ($errCode !== UPLOAD_ERR_OK) {
        $msg = match ($errCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded certificate file is too large',
            UPLOAD_ERR_PARTIAL => 'Certificate upload was incomplete',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write uploaded certificate file',
            UPLOAD_ERR_EXTENSION => 'Certificate upload stopped by server extension',
            default => 'Certificate upload failed'
        };
        return [false, null, $msg];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, null, 'Invalid uploaded certificate file'];
    }

    $tmp  = $file['tmp_name'];
    $name = mb_substr(trim((string)($file['name'] ?? 'certificate')), 0, 255);
    $size = (int)($file['size'] ?? 0);

    if ($size <= 0) {
        return [false, null, 'Uploaded certificate file is empty'];
    }

    $maxSize = 10 * 1024 * 1024;
    if ($size > $maxSize) {
        return [false, null, 'Certificate file too large. Maximum allowed is 10 MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (finfo_file($finfo, $tmp) ?: 'application/octet-stream') : 'application/octet-stream';
    if ($finfo) finfo_close($finfo);

    if (!in_array($mime, allowed_certificate_mimes(), true)) {
        return [false, null, 'Only JPG, PNG, GIF, WEBP, or PDF files are allowed'];
    }

    $blob = file_get_contents($tmp);
    if ($blob === false || $blob === '') {
        return [false, null, 'Could not read uploaded certificate file'];
    }

    return [true, [
        'name' => $name,
        'type' => $mime,
        'size' => $size,
        'blob' => $blob,
    ], null];
}

function update_asset_blob(mysqli $mysqli, int $asset_id, string $blob): void {
    $stmt = $mysqli->prepare("UPDATE assets SET certificate_file = ? WHERE asset_id = ?");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }

    $null = null;
    $stmt->bind_param('bi', $null, $asset_id);
    $stmt->send_long_data(0, $blob);

    if (!$stmt->execute()) {
        $err = $stmt->error ?: $mysqli->error;
        $stmt->close();
        throw new Exception('Failed to save certificate file: ' . $err);
    }

    $stmt->close();
}

function clear_asset_blob(mysqli $mysqli, int $asset_id): void {
    $stmt = $mysqli->prepare("
        UPDATE assets
        SET certificate_name = NULL,
            certificate_mime = NULL,
            certificate_file = NULL
        WHERE asset_id = ?
    ");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('i', $asset_id);

    if (!$stmt->execute()) {
        $err = $stmt->error ?: $mysqli->error;
        $stmt->close();
        throw new Exception('Failed clearing certificate file: ' . $err);
    }

    $stmt->close();
}

function parse_holders_json($raw): array {
    $arr = json_decode((string)$raw, true);
    if (!is_array($arr)) return [];

    $out = [];
    foreach ($arr as $h) {
        $uid = (int)($h['user_id'] ?? 0);
        $amt = (float)($h['contribution'] ?? 0);
        $notes = clean_str($h['notes'] ?? null);

        if ($uid > 0 && $amt > 0) {
            $out[] = [
                'user_id' => $uid,
                'contribution' => money($amt),
                'notes' => $notes
            ];
        }
    }
    return $out;
}

/* =========================================================
   Member net calculations with:
   - dynamic interest share
   - dynamic expense partition
========================================================= */

function get_loan_unpaid_principal(mysqli $mysqli, int $loan_id): float {
    $st = $mysqli->prepare("SELECT principal, status FROM loans WHERE loan_id=? LIMIT 1");
    if (!$st) return 0.0;

    $st->bind_param('i', $loan_id);
    $st->execute();
    $loan = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$loan) return 0.0;
    if (!in_array((string)($loan['status'] ?? ''), ['approved', 'defaulted', 'closed'], true)) return 0.0;

    $principal = (float)($loan['principal'] ?? 0);

    $p = $mysqli->prepare("
        SELECT COALESCE(SUM(amount), 0) AS paid
        FROM transactions
        WHERE loan_id = ?
          AND type = 'loan_principal'
          AND direction = 'IN'
    ");
    if (!$p) return $principal;

    $p->bind_param('i', $loan_id);
    $p->execute();
    $row = $p->get_result()->fetch_assoc();
    $p->close();

    $paid = (float)($row['paid'] ?? 0);
    return max(0.0, $principal - $paid);
}

function get_user_unpaid_loans(mysqli $mysqli, int $user_id): float {
    $st = $mysqli->prepare("
        SELECT loan_id
        FROM loans
        WHERE borrower_user_id = ?
          AND status IN ('approved','defaulted','closed')
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $user_id);
    $st->execute();
    $rs = $st->get_result();

    $sum = 0.0;
    while ($row = $rs->fetch_assoc()) {
        $sum += get_loan_unpaid_principal($mysqli, (int)$row['loan_id']);
    }
    $st->close();

    return money($sum);
}

function get_user_unpaid_interest_total(mysqli $mysqli, int $user_id): float {
    $st = $mysqli->prepare("
        SELECT l.loan_id
        FROM loans l
        WHERE l.borrower_user_id = ?
          AND l.status IN ('approved','defaulted','closed')
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $user_id);
    $st->execute();
    $rs = $st->get_result();

    $sum = 0.0;
    while ($row = $rs->fetch_assoc()) {
        $loan_id = (int)$row['loan_id'];

        $loanSt = $mysqli->prepare("
            SELECT principal, monthly_interest_rate
            FROM loans
            WHERE loan_id = ?
            LIMIT 1
        ");
        if (!$loanSt) continue;

        $loanSt->bind_param('i', $loan_id);
        $loanSt->execute();
        $loan = $loanSt->get_result()->fetch_assoc();
        $loanSt->close();

        if (!$loan) continue;

        $due = ((float)($loan['principal'] ?? 0) * (float)($loan['monthly_interest_rate'] ?? 0)) / 100;

        $paidSt = $mysqli->prepare("
            SELECT COALESCE(SUM(amount),0) AS paid_interest
            FROM transactions
            WHERE loan_id = ?
              AND type = 'loan_interest'
              AND direction = 'IN'
        ");
        if (!$paidSt) continue;

        $paidSt->bind_param('i', $loan_id);
        $paidSt->execute();
        $paidRow = $paidSt->get_result()->fetch_assoc();
        $paidSt->close();

        $paid = (float)($paidRow['paid_interest'] ?? 0);
        $sum += max(0.0, $due - $paid);
    }

    $st->close();
    return money($sum);
}

function get_user_locked_guarantees(mysqli $mysqli, int $user_id): float {
    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(lg.guarantee_amount),0) AS total_guaranteed
        FROM loan_guaranters lg
        INNER JOIN loans l ON l.loan_id = lg.loan_id
        WHERE lg.guarantor_user_id = ?
          AND lg.status = 'accepted'
          AND l.status IN ('approved','defaulted')
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return money((float)($row['total_guaranteed'] ?? 0));
}

function get_member_base_contributions(mysqli $mysqli, int $user_id): float {
    $st = $mysqli->prepare("
        SELECT
            COALESCE(SUM(CASE
                WHEN type='contribution' AND direction='IN' THEN amount
                ELSE 0
            END), 0) AS contrib_in,
            COALESCE(SUM(CASE
                WHEN type='withdrawal' AND direction='OUT' THEN amount
                ELSE 0
            END), 0) AS withdraw_out
        FROM transactions
        WHERE user_id=?
    ");
    if (!$st) return 0.0;

    $st->bind_param('i', $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $base = (float)($row['contrib_in'] ?? 0) - (float)($row['withdraw_out'] ?? 0);
    return max(0.0, money($base));
}

function calculate_member_financial_shares(mysqli $mysqli): array {
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
        $base = get_member_base_contributions($mysqli, $uid);

        $members[$uid] = [
            'base'             => $base,
            'earned_interest'  => 0.0,
            'expense_share'    => 0.0,
            'current_weight'   => $base,
        ];
    }

    if (empty($members)) return [];

    $eventSql = "
        SELECT transaction_id, tx_date, type, amount
        FROM transactions
        WHERE (
            (type='loan_interest' AND direction='IN')
            OR
            (type='expense' AND direction='OUT')
        )
        ORDER BY tx_date ASC, transaction_id ASC
    ";
    $eventRes = $mysqli->query($eventSql);
    if (!$eventRes) return $members;

    while ($ev = $eventRes->fetch_assoc()) {
        $type   = (string)($ev['type'] ?? '');
        $amount = (float)($ev['amount'] ?? 0);

        if ($amount <= 0) continue;

        $totalWeight = 0.0;

        foreach ($members as $uid => $m) {
            $weight = max(
                0.0,
                (float)$m['base']
                + (float)$m['earned_interest']
                - (float)$m['expense_share']
            );
            $members[$uid]['current_weight'] = money($weight);
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) continue;

        if ($type === 'loan_interest') {
            foreach ($members as $uid => $m) {
                $share = ($m['current_weight'] / $totalWeight) * $amount;
                $members[$uid]['earned_interest'] = money($members[$uid]['earned_interest'] + $share);
            }
        }

        if ($type === 'expense') {
            foreach ($members as $uid => $m) {
                $share = ($m['current_weight'] / $totalWeight) * $amount;
                $members[$uid]['expense_share'] = money($members[$uid]['expense_share'] + $share);
            }
        }
    }

    foreach ($members as $uid => $m) {
        $members[$uid]['net_participation'] = money(
            max(
                0.0,
                (float)$m['base']
                + (float)$m['earned_interest']
                - (float)$m['expense_share']
            )
        );
    }

    return $members;
}

function get_member_calculated_interest(mysqli $mysqli, int $user_id): float {
    $all = calculate_member_financial_shares($mysqli);
    return money((float)($all[$user_id]['earned_interest'] ?? 0.0));
}

function get_member_expense_partition(mysqli $mysqli, int $user_id): float {
    $all = calculate_member_financial_shares($mysqli);
    return money((float)($all[$user_id]['expense_share'] ?? 0.0));
}

function get_user_net(mysqli $mysqli, int $user_id): array {
    $contrib = 0.0;
    $withdraw = 0.0;

    $st = $mysqli->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN type='contribution' AND direction='IN' THEN amount ELSE 0 END),0) AS c_in,
          COALESCE(SUM(CASE WHEN type='withdrawal'   AND direction='OUT' THEN amount ELSE 0 END),0) AS w_out
        FROM transactions
        WHERE user_id = ?
    ");
    if ($st) {
        $st->bind_param('i', $user_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        $contrib = (float)($row['c_in'] ?? 0);
        $withdraw = (float)($row['w_out'] ?? 0);
    }

    $interest = get_member_calculated_interest($mysqli, $user_id);
    $expense_partition = get_member_expense_partition($mysqli, $user_id);

    $loans_unpaid = get_user_unpaid_loans($mysqli, $user_id);
    $interest_unpaid = get_user_unpaid_interest_total($mysqli, $user_id);
    $guaranteed = get_user_locked_guarantees($mysqli, $user_id);
    $reserve = 120000.0;

    $participation_net = ($contrib + $interest) - ($withdraw + $expense_partition);
    $net_raw = $participation_net - ($loans_unpaid + $interest_unpaid + $guaranteed + $reserve);
    $net = max(0.0, $net_raw);

    return [
        'contrib' => money($contrib),
        'interest_received' => money($interest),
        'expense_partition' => money($expense_partition),
        'withdrawals' => money($withdraw),
        'participation_net' => money($participation_net),
        'loans_unpaid' => money($loans_unpaid),
        'interest_unpaid' => money($interest_unpaid),
        'locked_guarantees' => money($guaranteed),
        'reserve' => money($reserve),
        'net_raw' => money($net_raw),
        'net' => money($net),
    ];
}

/* =========================================================
   Asset holders
========================================================= */

function fetch_asset_holders(mysqli $mysqli, int $asset_id): array {
    $stmt = $mysqli->prepare("
        SELECT
            ah.asset_id,
            ah.user_id,
            ah.contribution_amount,
            ah.notes,
            u.names,
            u.phone1,
            u.phone2
        FROM asset_holders ah
        INNER JOIN users u ON u.id = ah.user_id
        WHERE ah.asset_id = ?
    ");
    if (!$stmt) return [];

    $stmt->bind_param('i', $asset_id);
    $stmt->execute();
    $rs = $stmt->get_result();

    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        $net = get_user_net($mysqli, (int)$r['user_id']);
        $r['net_value'] = $net['net'];
        $r['expense_partition'] = $net['expense_partition'];
        $r['participation_net'] = $net['participation_net'];
        $rows[] = $r;
    }
    $stmt->close();

    return $rows;
}

function fetch_asset(mysqli $mysqli, int $id): ?array {
    $stmt = $mysqli->prepare("
        SELECT
            a.asset_id,
            a.account_id,
            ac.name AS account_name,
            a.name,
            a.purchase_date,
            a.purchase_value,
            a.location,
            a.notes,
            a.created_by,
            a.created_at,
            a.certificate_name,
            a.certificate_mime,
            CASE WHEN a.certificate_file IS NOT NULL THEN 1 ELSE 0 END AS has_certificate,
            a.sold_value,
            a.sold_date,
            (
              SELECT COUNT(*)
              FROM asset_holders ah
              WHERE ah.asset_id = a.asset_id
            ) AS holders_count
        FROM assets a
        LEFT JOIN accounts ac ON ac.account_id = a.account_id
        WHERE a.asset_id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return null;

    if ((int)($row['has_certificate'] ?? 0) === 1) {
        $row['certificate_view_url'] = 'assets_api.php?action=view_certificate&id=' . $id;
        $row['certificate_download_url'] = 'assets_api.php?action=download_certificate&id=' . $id;
    }

    $row['holders'] = fetch_asset_holders($mysqli, $id);
    return $row;
}

function delete_linked_asset_holder_transactions(mysqli $mysqli, int $asset_id): void {
    $prefix = "Asset holder contribution | Asset #AS-$asset_id |%";

    $stmt = $mysqli->prepare("
        DELETE FROM transactions
        WHERE type = 'withdrawal'
          AND direction = 'OUT'
          AND description LIKE ?
    ");
    if (!$stmt) {
        throw new Exception($mysqli->error);
    }

    $stmt->bind_param('s', $prefix);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: $mysqli->error;
        $stmt->close();
        throw new Exception($err);
    }
    $stmt->close();
}

function save_asset_holders(mysqli $mysqli, int $asset_id, int $account_id, string $asset_name, float $purchase_value, array $holders, int $admin_id): void {
    if ($account_id <= 0) {
        throw new Exception('Account is required for asset holder transactions');
    }

    if (empty($holders)) {
        throw new Exception('Add at least one holder');
    }

    $sum = 0.0;
    $seen = [];

    foreach ($holders as $h) {
        $uid = (int)$h['user_id'];
        $amt = (float)$h['contribution'];

        if ($uid <= 0 || $amt <= 0) {
            throw new Exception('Each holder must have user and positive contribution');
        }
        if (isset($seen[$uid])) {
            throw new Exception('Duplicate holder selected');
        }
        $seen[$uid] = true;

        $u = $mysqli->prepare("SELECT id, is_member FROM users WHERE id=? LIMIT 1");
        if (!$u) throw new Exception($mysqli->error);
        $u->bind_param('i', $uid);
        $u->execute();
        $user = $u->get_result()->fetch_assoc();
        $u->close();

        if (!$user) {
            throw new Exception("Holder not found (ID $uid)");
        }
        if ((int)($user['is_member'] ?? 0) !== 1) {
            throw new Exception("Holder must be a member");
        }

        $sum += $amt;
    }

    if (abs(money($sum) - money($purchase_value)) > 0.009) {
        throw new Exception('Total holder contribution must equal asset purchase value');
    }

    $del = $mysqli->prepare("DELETE FROM asset_holders WHERE asset_id = ?");
    if (!$del) throw new Exception($mysqli->error);
    $del->bind_param('i', $asset_id);
    if (!$del->execute()) {
        $err = $del->error ?: $mysqli->error;
        $del->close();
        throw new Exception($err);
    }
    $del->close();

    delete_linked_asset_holder_transactions($mysqli, $asset_id);

    $insHolder = $mysqli->prepare("
        INSERT INTO asset_holders (asset_id, user_id, contribution_amount, notes, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$insHolder) throw new Exception($mysqli->error);

    $insTx = $mysqli->prepare("
        INSERT INTO transactions
          (tx_date, user_id, loan_id, account_id, type, direction, amount, description, created_by)
        VALUES
          (NOW(), ?, NULL, ?, 'withdrawal', 'OUT', ?, ?, ?)
    ");
    if (!$insTx) throw new Exception($mysqli->error);

    foreach ($holders as $h) {
        $uid = (int)$h['user_id'];
        $amt = money((float)$h['contribution']);
        $notes = clean_str($h['notes'] ?? null);

        $insHolder->bind_param('iidsi', $asset_id, $uid, $amt, $notes, $admin_id);
        if (!$insHolder->execute()) {
            $err = $insHolder->error ?: $mysqli->error;
            $insHolder->close();
            $insTx->close();
            throw new Exception($err);
        }

        $desc = "Asset holder contribution | Asset #AS-$asset_id | Holder #$uid | $asset_name";
        if ($notes) {
            $desc .= " | " . $notes;
        }

        $insTx->bind_param('iidsi', $uid, $account_id, $amt, $desc, $admin_id);
        if (!$insTx->execute()) {
            $err = $insTx->error ?: $mysqli->error;
            $insHolder->close();
            $insTx->close();
            throw new Exception($err);
        }
    }

    $insHolder->close();
    $insTx->close();
}

/* =========================================================
   FILE VIEW / DOWNLOAD
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'view_certificate')) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid asset id'], 400);

    $stmt = $mysqli->prepare("
        SELECT certificate_name, certificate_mime, certificate_file
        FROM assets
        WHERE asset_id = ?
        LIMIT 1
    ");
    if (!$stmt) send_json(['success' => false, 'message' => 'Prepare failed'], 500);

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['certificate_file'])) {
        send_json(['success' => false, 'message' => 'No certificate found'], 404);
    }

    while (ob_get_level() > 0) { @ob_end_clean(); }

    $mime = $row['certificate_mime'] ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . str_replace('"', '', ($row['certificate_name'] ?: 'certificate')) . '"');
    echo $row['certificate_file'];
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'download_certificate')) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid asset id'], 400);

    $stmt = $mysqli->prepare("
        SELECT certificate_name, certificate_mime, certificate_file
        FROM assets
        WHERE asset_id = ?
        LIMIT 1
    ");
    if (!$stmt) send_json(['success' => false, 'message' => 'Prepare failed'], 500);

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['certificate_file'])) {
        send_json(['success' => false, 'message' => 'No certificate found'], 404);
    }

    while (ob_get_level() > 0) { @ob_end_clean(); }

    $fname = $row['certificate_name'] ?: ("asset_certificate_" . $id);
    $mime  = $row['certificate_mime'] ?: 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fname) . '"');
    echo $row['certificate_file'];
    exit;
}

/* =========================================================
   GET
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'search_members') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            send_json(['success' => true, 'data' => []]);
        }

        $like = "%{$q}%";
        $stmt = $mysqli->prepare("
            SELECT id, names, phone1, phone2
            FROM users
            WHERE is_member = 1
              AND (names LIKE ? OR phone1 LIKE ? OR phone2 LIKE ?)
            ORDER BY names ASC
            LIMIT 25
        ");
        if (!$stmt) send_json(['success' => false, 'message' => $mysqli->error], 500);

        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $rs = $stmt->get_result();

        $rows = [];
        while ($r = $rs->fetch_assoc()) {
            $net = get_user_net($mysqli, (int)$r['id']);
            $r['net_value'] = $net['net'];
            $r['expense_partition'] = $net['expense_partition'];
            $r['participation_net'] = $net['participation_net'];
            $rows[] = $r;
        }
        $stmt->close();

        send_json(['success' => true, 'data' => $rows]);
    }

    if (isset($_GET['id'])) {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);

        $row = fetch_asset($mysqli, $id);
        if ($row) {
            send_json(['success' => true, 'data' => $row]);
        } else {
            send_json(['success' => false, 'message' => 'Not found'], 404);
        }
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
    $q = trim((string)($_GET['q'] ?? ''));

    $where = '';
    if ($q !== '') {
        $esc = $mysqli->real_escape_string($q);
        $where = " WHERE a.name LIKE '%$esc%' OR a.location LIKE '%$esc%' OR a.notes LIKE '%$esc%'";
    }

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM assets a $where");
    if (!$totalRes) send_json(['success' => false, 'message' => $mysqli->error], 500);

    $total = (int)($totalRes->fetch_assoc()['cnt'] ?? 0);
    $offset = ($page - 1) * $per_page;

    $sql = "
        SELECT
            a.asset_id,
            a.account_id,
            ac.name AS account_name,
            a.name,
            a.purchase_date,
            a.purchase_value,
            a.location,
            a.notes,
            a.created_by,
            a.created_at,
            a.certificate_name,
            a.certificate_mime,
            CASE WHEN a.certificate_file IS NOT NULL THEN 1 ELSE 0 END AS has_certificate,
            a.sold_value,
            a.sold_date,
            (
              SELECT COUNT(*)
              FROM asset_holders ah
              WHERE ah.asset_id = a.asset_id
            ) AS holders_count
        FROM assets a
        LEFT JOIN accounts ac ON ac.account_id = a.account_id
        $where
        ORDER BY a.asset_id DESC
        LIMIT $offset, $per_page
    ";

    $res = $mysqli->query($sql);
    if (!$res) send_json(['success' => false, 'message' => $mysqli->error], 500);

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        if ((int)($r['has_certificate'] ?? 0) === 1) {
            $r['certificate_view_url'] = 'assets_api.php?action=view_certificate&id=' . $r['asset_id'];
            $r['certificate_download_url'] = 'assets_api.php?action=download_certificate&id=' . $r['asset_id'];
        }
        $rows[] = $r;
    }

    send_json([
        'success' => true,
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page
    ]);
}

/* =========================================================
   POST
========================================================= */

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $account_id = (int)($_POST['account_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $purchase_date = valid_date_or_null($_POST['purchase_date'] ?? null);
    $purchase_value = (float)($_POST['purchase_value'] ?? 0);

    $location = clean_str($_POST['location'] ?? null);
    $notes = clean_str($_POST['notes'] ?? null);

    $sold_value_raw = clean_str($_POST['sold_value'] ?? null);
    $sold_value = ($sold_value_raw === null) ? null : (float)$sold_value_raw;
    $sold_date = valid_date_or_null($_POST['sold_date'] ?? null);

    $holders = parse_holders_json($_POST['holders'] ?? '[]');

    if ($account_id <= 0 || $name === '' || $purchase_date === null || $purchase_value <= 0) {
        send_json(['success' => false, 'message' => 'Missing or invalid required fields'], 400);
    }

    if ($sold_value !== null && $sold_value < 0) {
        send_json(['success' => false, 'message' => 'sold_value cannot be negative'], 400);
    }

    if ($sold_date !== null && $sold_value === null) {
        send_json(['success' => false, 'message' => 'sold_value is required if sold_date is provided'], 400);
    }

    [$certOk, $certData, $certErr] = get_uploaded_certificate('certificate_file', false);
    if (!$certOk) {
        send_json(['success' => false, 'message' => $certErr], 400);
    }

    $certificate_name = $certData['name'] ?? clean_str($_POST['certificate_name'] ?? null);
    $certificate_mime = $certData['type'] ?? null;
    $certificate_blob = $certData['blob'] ?? null;

    $mysqli->begin_transaction();

    try {
        $stmt = $mysqli->prepare("
            INSERT INTO assets
                (account_id, name, purchase_date, purchase_value, location, notes, certificate_name, certificate_mime, sold_value, sold_date, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) throw new Exception($mysqli->error);

        $stmt->bind_param(
            'issdssssssi',
            $account_id,
            $name,
            $purchase_date,
            $purchase_value,
            $location,
            $notes,
            $certificate_name,
            $certificate_mime,
            $sold_value,
            $sold_date,
            $admin_id
        );

        if (!$stmt->execute()) {
            $err = $stmt->error ?: $mysqli->error;
            $stmt->close();
            throw new Exception($err);
        }

        $id = (int)$stmt->insert_id;
        $stmt->close();

        if ($certificate_blob !== null) {
            update_asset_blob($mysqli, $id, $certificate_blob);
        }

        save_asset_holders($mysqli, $id, $account_id, $name, $purchase_value, $holders, $admin_id);

        $mysqli->commit();

        $row = fetch_asset($mysqli, $id);
        notify_admins($mysqli, 'asset_recorded', "Asset yanditswe (#AS-$id): $name - " . number_format((float)$purchase_value) . " Frw");

        send_json(['success' => true, 'data' => $row]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);

    $existing = fetch_asset($mysqli, $id);
    if (!$existing) send_json(['success' => false, 'message' => 'Asset not found'], 404);

    $account_id = (int)($_POST['account_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $purchase_date = valid_date_or_null($_POST['purchase_date'] ?? null);
    $purchase_value = (float)($_POST['purchase_value'] ?? 0);

    $location = clean_str($_POST['location'] ?? null);
    $notes = clean_str($_POST['notes'] ?? null);

    $sold_value_raw = clean_str($_POST['sold_value'] ?? null);
    $sold_value = ($sold_value_raw === null) ? null : (float)$sold_value_raw;
    $sold_date = valid_date_or_null($_POST['sold_date'] ?? null);
    $remove_certificate = (int)($_POST['remove_certificate'] ?? 0) === 1;

    $holders = parse_holders_json($_POST['holders'] ?? '[]');

    if ($account_id <= 0 || $name === '' || $purchase_date === null || $purchase_value <= 0) {
        send_json(['success' => false, 'message' => 'Missing or invalid required fields'], 400);
    }

    if ($sold_value !== null && $sold_value < 0) {
        send_json(['success' => false, 'message' => 'sold_value cannot be negative'], 400);
    }

    if ($sold_date !== null && $sold_value === null) {
        send_json(['success' => false, 'message' => 'sold_value is required if sold_date is provided'], 400);
    }

    [$certOk, $certData, $certErr] = get_uploaded_certificate('certificate_file', false);
    if (!$certOk) {
        send_json(['success' => false, 'message' => $certErr], 400);
    }

    $hasNewFile = !!$certData;
    $certificate_name = $certData['name'] ?? clean_str($_POST['certificate_name'] ?? null);
    $certificate_mime = $certData['type'] ?? null;
    $certificate_blob = $certData['blob'] ?? null;

    $mysqli->begin_transaction();

    try {
        if ($hasNewFile) {
            $stmt = $mysqli->prepare("
                UPDATE assets
                SET
                    account_id = ?,
                    name = ?,
                    purchase_date = ?,
                    purchase_value = ?,
                    location = ?,
                    notes = ?,
                    certificate_name = ?,
                    certificate_mime = ?,
                    sold_value = ?,
                    sold_date = ?
                WHERE asset_id = ?
            ");
            if (!$stmt) throw new Exception($mysqli->error);

            $stmt->bind_param(
                'issdssssssi',
                $account_id,
                $name,
                $purchase_date,
                $purchase_value,
                $location,
                $notes,
                $certificate_name,
                $certificate_mime,
                $sold_value,
                $sold_date,
                $id
            );
        } else {
            $stmt = $mysqli->prepare("
                UPDATE assets
                SET
                    account_id = ?,
                    name = ?,
                    purchase_date = ?,
                    purchase_value = ?,
                    location = ?,
                    notes = ?,
                    certificate_name = ?,
                    sold_value = ?,
                    sold_date = ?
                WHERE asset_id = ?
            ");
            if (!$stmt) throw new Exception($mysqli->error);

            $stmt->bind_param(
                'issdsssssi',
                $account_id,
                $name,
                $purchase_date,
                $purchase_value,
                $location,
                $notes,
                $certificate_name,
                $sold_value,
                $sold_date,
                $id
            );
        }

        if (!$stmt->execute()) {
            $err = $stmt->error ?: $mysqli->error;
            $stmt->close();
            throw new Exception($err);
        }
        $stmt->close();

        if ($remove_certificate) {
            clear_asset_blob($mysqli, $id);
        }

        if ($hasNewFile && $certificate_blob !== null) {
            update_asset_blob($mysqli, $id, $certificate_blob);
        }

        save_asset_holders($mysqli, $id, $account_id, $name, $purchase_value, $holders, $admin_id);

        $mysqli->commit();

        $row = fetch_asset($mysqli, $id);
        notify_admins($mysqli, 'asset_recorded', "Asset yahinduwe (#AS-$id): $name");

        send_json(['success' => true, 'data' => $row]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id'], 400);

    $existing = fetch_asset($mysqli, $id);
    if (!$existing) send_json(['success' => false, 'message' => 'Asset not found'], 404);

    $mysqli->begin_transaction();

    try {
        delete_linked_asset_holder_transactions($mysqli, $id);

        $delH = $mysqli->prepare("DELETE FROM asset_holders WHERE asset_id = ?");
        if (!$delH) throw new Exception($mysqli->error);
        $delH->bind_param('i', $id);
        if (!$delH->execute()) {
            $err = $delH->error ?: $mysqli->error;
            $delH->close();
            throw new Exception($err);
        }
        $delH->close();

        $stmt = $mysqli->prepare("DELETE FROM assets WHERE asset_id = ?");
        if (!$stmt) throw new Exception($mysqli->error);

        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $err = $stmt->error ?: $mysqli->error;
            $stmt->close();
            throw new Exception($err);
        }
        $stmt->close();

        $mysqli->commit();

        notify_admins($mysqli, 'asset_recorded', "Asset yasibwe (#AS-$id)");
        send_json(['success' => true]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

send_json(['success' => false, 'message' => 'Invalid request'], 400);
?>