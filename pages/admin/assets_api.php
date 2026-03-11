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
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error',
            'error'   => ($err['message'] ?? 'Unknown error') . ' in ' . ($err['file'] ?? '') . ' on line ' . ($err['line'] ?? ''),
        ]);
        exit;
    }

    if (trim($buf) !== '') {
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
   Member net calculations
========================================================= */

function get_loan_unpaid_principal(mysqli $mysqli, int $loan_id): float {
    $st = $mysqli->prepare("SELECT principal, status FROM loans WHERE loan_id=? LIMIT 1");
    if (!$st) return 0.0;

    $st->bind_param('i', $loan_id);
    $st->execute();
    $loan = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$loan) return 0.0;
    if (!in_array((string)($loan['status'] ?? ''), ['approved', 'defaulted'], true)) return 0.0;

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
          AND status IN ('approved','defaulted')
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

function get_user_net(mysqli $mysqli, int $user_id): array {
    $contrib = 0.0;
    $withdraw = 0.0;
    $interest = 0.0;

    $st = $mysqli->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN type='contribution' AND direction='IN' THEN amount ELSE 0 END),0) AS c_in,
          COALESCE(SUM(CASE WHEN type='withdrawal'   AND direction='OUT' THEN amount ELSE 0 END),0) AS w_out,
          COALESCE(SUM(CASE WHEN type='loan_interest' AND direction='IN' THEN amount ELSE 0 END),0) AS i_in
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
        $interest = (float)($row['i_in'] ?? 0);
    }

    $loans_unpaid = get_user_unpaid_loans($mysqli, $user_id);
    $guaranteed = get_user_locked_guarantees($mysqli, $user_id);
    $reserve = 120000.0;

    $net_raw = ($contrib + $interest) - ($withdraw + $loans_unpaid + $guaranteed + $reserve);
    $net = max(0.0, $net_raw);

    return [
        'contrib' => money($contrib),
        'interest_received' => money($interest),
        'withdrawals' => money($withdraw),
        'loans_unpaid' => money($loans_unpaid),
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
        $rows[] = $r;
    }
    $stmt->close();

    return $rows;
}

function fetch_asset(mysqli $mysqli, int $id): ?array {
    $stmt = $mysqli->prepare("
        SELECT
            a.asset_id,
            a.name,
            a.purchase_date,
            a.purchase_value,
            a.location,
            a.notes,
            a.created_by,
            a.created_at,
            a.certificate_name,
            a.certificate_mime,
            IF(a.certificate_file IS NOT NULL, HEX(a.certificate_file), NULL) AS certificate_file,
            a.sold_value,
            a.sold_date,
            (
              SELECT COUNT(*)
              FROM asset_holders ah
              WHERE ah.asset_id = a.asset_id
            ) AS holders_count
        FROM assets a
        WHERE a.asset_id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return null;

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

function save_asset_holders(mysqli $mysqli, int $asset_id, string $asset_name, float $purchase_value, array $holders, int $admin_id): void {
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
          (NOW(), ?, NULL, NULL, 'withdrawal', 'OUT', ?, ?, ?)
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

        $insTx->bind_param('idsi', $uid, $amt, $desc, $admin_id);
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
            a.name,
            a.purchase_date,
            a.purchase_value,
            a.location,
            a.notes,
            a.created_by,
            a.created_at,
            a.certificate_name,
            a.certificate_mime,
            IF(a.certificate_file IS NOT NULL, HEX(a.certificate_file), NULL) AS certificate_file,
            a.sold_value,
            a.sold_date,
            (
              SELECT COUNT(*)
              FROM asset_holders ah
              WHERE ah.asset_id = a.asset_id
            ) AS holders_count
        FROM assets a
        $where
        ORDER BY a.asset_id DESC
        LIMIT $offset, $per_page
    ";

    $res = $mysqli->query($sql);
    if (!$res) send_json(['success' => false, 'message' => $mysqli->error], 500);

    $rows = [];
    while ($r = $res->fetch_assoc()) {
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
    $name = trim((string)($_POST['name'] ?? ''));
    $purchase_date = valid_date_or_null($_POST['purchase_date'] ?? null);
    $purchase_value = (float)($_POST['purchase_value'] ?? 0);

    $location = clean_str($_POST['location'] ?? null);
    $notes = clean_str($_POST['notes'] ?? null);

    $sold_value_raw = clean_str($_POST['sold_value'] ?? null);
    $sold_value = ($sold_value_raw === null) ? null : (float)$sold_value_raw;
    $sold_date = valid_date_or_null($_POST['sold_date'] ?? null);

    $holders = parse_holders_json($_POST['holders'] ?? '[]');

    if ($name === '' || $purchase_date === null || $purchase_value <= 0) {
        send_json(['success' => false, 'message' => 'Missing or invalid required fields'], 400);
    }

    if ($sold_value !== null && $sold_value < 0) {
        send_json(['success' => false, 'message' => 'sold_value cannot be negative'], 400);
    }

    if ($sold_date !== null && $sold_value === null) {
        send_json(['success' => false, 'message' => 'sold_value is required if sold_date is provided'], 400);
    }

    $certificate_name = null;
    $certificate_mime = null;
    $certificate_blob = null;

    if (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['certificate_file'];
        $certificate_blob = @file_get_contents($file['tmp_name']);
        $certificate_name = clean_str($file['name'] ?? null);
        $certificate_mime = clean_str($file['type'] ?? null);
    } else {
        $certificate_name = clean_str($_POST['certificate_name'] ?? null);
    }

    $mysqli->begin_transaction();

    try {
        $stmt = $mysqli->prepare("
            INSERT INTO assets
                (name, purchase_date, purchase_value, location, notes, certificate_name, certificate_mime, sold_value, sold_date, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) throw new Exception($mysqli->error);

        $stmt->bind_param(
            'ssdssssssi',
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

        save_asset_holders($mysqli, $id, $name, $purchase_value, $holders, $admin_id);

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

    $name = trim((string)($_POST['name'] ?? ''));
    $purchase_date = valid_date_or_null($_POST['purchase_date'] ?? null);
    $purchase_value = (float)($_POST['purchase_value'] ?? 0);

    $location = clean_str($_POST['location'] ?? null);
    $notes = clean_str($_POST['notes'] ?? null);

    $sold_value_raw = clean_str($_POST['sold_value'] ?? null);
    $sold_value = ($sold_value_raw === null) ? null : (float)$sold_value_raw;
    $sold_date = valid_date_or_null($_POST['sold_date'] ?? null);

    $holders = parse_holders_json($_POST['holders'] ?? '[]');

    if ($name === '' || $purchase_date === null || $purchase_value <= 0) {
        send_json(['success' => false, 'message' => 'Missing or invalid required fields'], 400);
    }

    if ($sold_value !== null && $sold_value < 0) {
        send_json(['success' => false, 'message' => 'sold_value cannot be negative'], 400);
    }

    if ($sold_date !== null && $sold_value === null) {
        send_json(['success' => false, 'message' => 'sold_value is required if sold_date is provided'], 400);
    }

    $certificate_name = clean_str($_POST['certificate_name'] ?? null);
    $certificate_mime = null;
    $certificate_blob = null;
    $hasNewFile = false;

    if (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['certificate_file'];
        $certificate_blob = @file_get_contents($file['tmp_name']);
        $certificate_name = clean_str($file['name'] ?? null);
        $certificate_mime = clean_str($file['type'] ?? null);
        $hasNewFile = true;
    }

    $mysqli->begin_transaction();

    try {
        if ($hasNewFile) {
            $stmt = $mysqli->prepare("
                UPDATE assets
                SET
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
                'ssdssssssi',
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
                'ssdsssssi',
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

        if ($hasNewFile && $certificate_blob !== null) {
            update_asset_blob($mysqli, $id, $certificate_blob);
        }

        save_asset_holders($mysqli, $id, $name, $purchase_value, $holders, $admin_id);

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