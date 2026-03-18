<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
session_start();

$mysqli = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/notifications_helper.php';

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   Helpers
───────────────────────────────────────────────────────────── */
function db_query(mysqli $db, string $sql): array {
    $res = $db->query($sql);
    if (!$res) return [];
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

function db_query_one(mysqli $db, string $sql): ?array {
    $res = $db->query($sql);
    if (!$res) return null;
    return $res->fetch_assoc() ?: null;
}

function loan_unpaid(mysqli $db, int $loanId, float $principal, string $status): float {
    if (!in_array($status, ['approved', 'defaulted', 'closed'], true)) return 0.0;

    $id  = (int)$loanId;
    $row = db_query_one($db, "
        SELECT COALESCE(SUM(amount), 0) AS paid
        FROM transactions
        WHERE loan_id = $id
          AND type = 'loan_principal'
          AND direction = 'IN'
    ");
    $paid = (float)($row['paid'] ?? 0);
    return max(0.0, $principal - $paid);
}

function user_select_fields(bool $includeImageFlags = true): string {
    $base = "
        id, names, nid_passport, email, phone1, phone2,
        guarantee_name, guarantee_nid_passport, guarantee_email,
        guarantee_phone1, guarantee_phone2, is_member, is_admin
    ";

    if ($includeImageFlags) {
        $base .= ",
            profile_image_name, profile_image_mime,
            nid_image_name, nid_image_mime
        ";
    }

    return $base;
}

function normalize_user_row(array $row): array {
    $row['is_member'] = (int)($row['is_member'] ?? 0);
    $row['is_admin']  = (int)($row['is_admin'] ?? 0);

    $row['has_profile_image'] = !empty($row['profile_image_name']) && !empty($row['profile_image_mime']);
    $row['has_nid_image']     = !empty($row['nid_image_name']) && !empty($row['nid_image_mime']);

    return $row;
}

function file_blob_data(string $field): array {
    if (
        !isset($_FILES[$field]) ||
        !is_array($_FILES[$field]) ||
        ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return [
            'uploaded' => false,
            'name' => null,
            'mime' => null,
            'data' => null,
            'error' => null
        ];
    }

    $f = $_FILES[$field];

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [
            'uploaded' => false,
            'name' => null,
            'mime' => null,
            'data' => null,
            'error' => 'Upload failed for ' . $field
        ];
    }

    $tmp  = $f['tmp_name'];
    $name = $f['name'] ?? null;
    $mime = mime_content_type($tmp) ?: ($f['type'] ?? 'application/octet-stream');
    $data = file_get_contents($tmp);

    return [
        'uploaded' => true,
        'name' => $name,
        'mime' => $mime,
        'data' => $data,
        'error' => null
    ];
}

/* ─────────────────────────────────────────────────────────────
   Contribution / expense / interest helpers
───────────────────────────────────────────────────────────── */

function get_member_contribution_total(mysqli $db, int $userId): float {
    $uid = (int)$userId;
    $row = db_query_one($db, "
        SELECT COALESCE(SUM(amount), 0) AS total_in
        FROM transactions
        WHERE user_id = $uid
          AND type = 'contribution'
          AND direction = 'IN'
    ");
    return (float)($row['total_in'] ?? 0);
}

function get_member_withdraw_total(mysqli $db, int $userId): float {
    $uid = (int)$userId;
    $row = db_query_one($db, "
        SELECT COALESCE(SUM(amount), 0) AS total_out
        FROM transactions
        WHERE user_id = $uid
          AND type IN ('withdrawal', 'withdrawal_deduction')
          AND direction = 'OUT'
    ");
    return (float)($row['total_out'] ?? 0);
}

function get_user_expense_portion_total(mysqli $db, int $userId): float {
    $uid = (int)$userId;
    $row = db_query_one($db, "
        SELECT COALESCE(SUM(amount), 0) AS total_expense
        FROM transactions
        WHERE user_id = $uid
          AND type = 'expense'
          AND direction = 'OUT'
    ");
    return (float)($row['total_expense'] ?? 0);
}

function get_member_contribution_base(mysqli $db, int $userId): float {
    $contrib  = get_member_contribution_total($db, $userId);
    $withdraw = get_member_withdraw_total($db, $userId);
    $expense  = get_user_expense_portion_total($db, $userId);

    // base = contribution - withdraw - expense portion
    $base = $contrib - $withdraw - $expense;
    return max(0.0, $base);
}

function calculate_member_interest_shares(mysqli $db): array {
    $members = db_query($db, "
        SELECT id, names
        FROM users
        WHERE is_member = 1
        ORDER BY id ASC
    ");

    if (!$members) return [];

    $state = [];
    foreach ($members as $m) {
        $uid = (int)$m['id'];
        $state[$uid] = [
            'user_id' => $uid,
            'names' => $m['names'],
            'base' => get_member_contribution_base($db, $uid),
            'earned_interest' => 0.0,
            'weight' => 0.0,
        ];
    }

    // Incoming loan interest is shared proportionally:
    // weight = contribution_base + previously earned interest
    $interestRows = db_query($db, "
        SELECT transaction_id, tx_date, amount
        FROM transactions
        WHERE type = 'loan_interest'
          AND direction = 'IN'
        ORDER BY tx_date ASC, transaction_id ASC
    ");

    foreach ($interestRows as $tx) {
        $amount = (float)($tx['amount'] ?? 0);
        if ($amount <= 0) continue;

        $totalWeight = 0.0;

        foreach ($state as $uid => $member) {
            $weight = max(0.0, (float)$member['base'] + (float)$member['earned_interest']);
            $state[$uid]['weight'] = $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) continue;

        foreach ($state as $uid => $member) {
            $share = ($member['weight'] / $totalWeight) * $amount;
            $state[$uid]['earned_interest'] += $share;
        }
    }

    $result = [];
    foreach ($state as $uid => $member) {
        $result[$uid] = round((float)$member['earned_interest'], 2);
    }

    return $result;
}

/* ─────────────────────────────────────────────────────────────
   Running balance / loan history
───────────────────────────────────────────────────────────── */

function calculate_running_balance(array $transactions): array {
    // oldest to newest for statement-style running balance
    usort($transactions, function ($a, $b) {
        $cmp = strcmp((string)($a['tx_date'] ?? ''), (string)($b['tx_date'] ?? ''));
        if ($cmp !== 0) return $cmp;
        return ((int)($a['transaction_id'] ?? 0)) <=> ((int)($b['transaction_id'] ?? 0));
    });

    $balance = 0.0;
    $rows = [];

    foreach ($transactions as $t) {
        $amount = (float)($t['amount'] ?? 0);
        $dir = strtoupper((string)($t['direction'] ?? ''));

        if ($dir === 'IN') {
            $balance += $amount;
        } else {
            $balance -= $amount;
        }

        $t['running_balance'] = round($balance, 2);
        $rows[] = $t;
    }

    return $rows;
}

function get_loan_payment_history(mysqli $db, int $loanId): array {
    $lid = (int)$loanId;

    return db_query($db, "
        SELECT transaction_id, tx_date, type, direction, amount, description
        FROM transactions
        WHERE loan_id = $lid
          AND type IN ('loan_principal', 'loan_interest', 'penalty')
        ORDER BY tx_date ASC, transaction_id ASC
    ");
}

/* ─────────────────────────────────────────────────────────────
   GET
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    /* ── Serve image ───────────────────────────────────────── */
    if (isset($_GET['id'], $_GET['image'])) {
        $id = (int)$_GET['id'];
        $imageType = $_GET['image'] === 'nid' ? 'nid' : 'profile';

        if ($imageType === 'profile') {
            $stmt = $mysqli->prepare("
                SELECT profile_image_name AS file_name,
                       profile_image_mime AS file_mime,
                       profile_image_data AS file_data
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
        } else {
            $stmt = $mysqli->prepare("
                SELECT nid_image_name AS file_name,
                       nid_image_mime AS file_mime,
                       nid_image_data AS file_data
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
        }

        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $mysqli->error]);
            exit;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['file_data'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Image not found']);
            exit;
        }

        header_remove('Content-Type');
        header('Content-Type: ' . ($row['file_mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . strlen($row['file_data']));
        header('Content-Disposition: inline; filename="' . basename($row['file_name'] ?: 'image') . '"');
        echo $row['file_data'];
        exit;
    }

    /* ── Single user for edit form ────────────────────────── */
    if (isset($_GET['id']) && !isset($_GET['full'])) {
        $id = (int)$_GET['id'];

        $stmt = $mysqli->prepare("
            SELECT " . user_select_fields(true) . "
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $mysqli->error]);
            exit;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $row = normalize_user_row($row);
            echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'message' => 'Not found']);
        }
        exit;
    }

    /* ── Full profile ─────────────────────────────────────── */
    if (isset($_GET['id'], $_GET['full']) && $_GET['full'] === '1') {
        $id = (int)$_GET['id'];

        $stmt = $mysqli->prepare("
            SELECT " . user_select_fields(true) . "
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $mysqli->error]);
            exit;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        $user = normalize_user_row($user);

        // User transactions
        $transactions = db_query($mysqli, "
            SELECT t.transaction_id, t.tx_date, t.type, t.direction,
                   t.amount, t.description, t.loan_id,
                   a.name AS account_name
            FROM transactions t
            LEFT JOIN accounts a ON a.account_id = t.account_id
            WHERE t.user_id = $id
            ORDER BY t.tx_date DESC, t.transaction_id DESC
        ");
        $transactions = calculate_running_balance($transactions);

        // User loans
        $loan_rows = db_query($mysqli, "
            SELECT loan_id, principal, interest_rate, status,
                   start_date, end_date, created_at
            FROM loans
            WHERE borrower_user_id = $id
            ORDER BY loan_id DESC
        ");

        $loans = [];
        foreach ($loan_rows as $r) {
            $loanId = (int)$r['loan_id'];
            $r['unpaid_principal'] = round(
                loan_unpaid($mysqli, $loanId, (float)$r['principal'], (string)$r['status']),
                2
            );
            $r['payments'] = get_loan_payment_history($mysqli, $loanId);
            $loans[] = $r;
        }

        // Guaranteed loans (exclude closed loans)
        $guaranteed = [];
        $tbl_check = $mysqli->query("SHOW TABLES LIKE 'loan_guaranters'");
        if ($tbl_check && $tbl_check->num_rows > 0) {
            $guaranteed = db_query($mysqli, "
                SELECT lg.loan_id, lg.guarantee_amount, lg.status,
                       lg.created_at AS since,
                       l.status      AS loan_status,
                       l.principal   AS loan_principal,
                       u.names       AS borrower_name
                FROM loan_guaranters lg
                INNER JOIN loans l ON l.loan_id = lg.loan_id
                INNER JOIN users u ON u.id      = l.borrower_user_id
                WHERE lg.guarantor_user_id = $id
                  AND l.status <> 'closed'
                ORDER BY lg.loan_id DESC
            ");
        }

        // Assets
        $assets = [];
        $tbl_check2 = $mysqli->query("SHOW TABLES LIKE 'asset_holders'");
        if ($tbl_check2 && $tbl_check2->num_rows > 0) {
            $assets = db_query($mysqli, "
                SELECT a.asset_id, a.name, a.purchase_date,
                       a.purchase_value, a.location, a.sold_value
                FROM asset_holders ah
                INNER JOIN assets a ON a.asset_id = ah.asset_id
                WHERE ah.user_id = $id
                ORDER BY a.asset_id DESC
            ");
        }

        // Summary
        $interestShares = calculate_member_interest_shares($mysqli);
        $userInterestGained = (float)($interestShares[$id] ?? 0);
        $userContributionBase = get_member_contribution_base($mysqli, $id);
        $userExpensePortion = get_user_expense_portion_total($mysqli, $id);
        $userContributionTotal = get_member_contribution_total($mysqli, $id);
        $userWithdrawTotal = get_member_withdraw_total($mysqli, $id);

        $summary = [
            'interest_gained'    => round($userInterestGained, 2),
            'contribution_base'  => round($userContributionBase, 2),
            'expense_portion'    => round($userExpensePortion, 2),
            'total_contribution' => round($userContributionTotal, 2),
            'total_withdraw'     => round($userWithdrawTotal, 2),
        ];

        echo json_encode([
            'success'      => true,
            'data'         => $user,
            'transactions' => $transactions,
            'loans'        => $loans,
            'guaranteed'   => $guaranteed,
            'assets'       => $assets,
            'summary'      => $summary,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ── Paginated list ───────────────────────────────────── */
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, (int)($_GET['per_page'] ?? 10));
    $q        = trim($_GET['q'] ?? '');

    $where = '';
    $params = [];
    $types = '';

    if ($q !== '') {
        $where = " WHERE names LIKE ? OR email LIKE ? OR phone1 LIKE ? OR phone2 LIKE ? OR nid_passport LIKE ? ";
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like, $like];
        $types = 'sssss';
    }

    $countSql = "SELECT COUNT(*) AS total FROM users" . $where;
    $stmt = $mysqli->prepare($countSql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $mysqli->error]);
        exit;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $totalRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total  = (int)($totalRow['total'] ?? 0);
    $offset = ($page - 1) * $per_page;

    $listSql = "
        SELECT " . user_select_fields(true) . "
        FROM users
        $where
        ORDER BY id DESC
        LIMIT ?, ?
    ";

    $stmt = $mysqli->prepare($listSql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $mysqli->error]);
        exit;
    }

    if ($types !== '') {
        $fullTypes = $types . 'ii';
        $bindValues = array_merge($params, [$offset, $per_page]);
        $stmt->bind_param($fullTypes, ...$bindValues);
    } else {
        $stmt->bind_param('ii', $offset, $per_page);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];

    while ($r = $res->fetch_assoc()) {
        $rows[] = normalize_user_row($r);
    }
    $stmt->close();

    echo json_encode([
        'success'  => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   POST – create / update / delete
───────────────────────────────────────────────────────────── */
$action = $_POST['action'] ?? '';

/* ── CREATE ──────────────────────────────────────────────── */
if ($action === 'create') {
    $names            = trim($_POST['names'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone1           = trim($_POST['phone1'] ?? '');
    $phone2           = trim($_POST['phone2'] ?? '');
    $nid              = trim($_POST['nid_passport'] ?? '');
    $password         = $_POST['password'] ?? '';
    $guarantee_name   = trim($_POST['guarantee_name'] ?? '');
    $guarantee_nid    = trim($_POST['guarantee_nid_passport'] ?? '');
    $guarantee_email  = trim($_POST['guarantee_email'] ?? '');
    $guarantee_phone1 = trim($_POST['guarantee_phone1'] ?? '');
    $guarantee_phone2 = trim($_POST['guarantee_phone2'] ?? '');
    $is_member        = !empty($_POST['is_member']) ? 1 : 0;
    $is_admin         = !empty($_POST['is_admin']) ? 1 : 0;

    if ($names === '' || $password === '' || $nid === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Names, NID/Passport, and password are required'
        ]);
        exit;
    }

    $check = $mysqli->prepare("SELECT id FROM users WHERE nid_passport = ? LIMIT 1");
    if (!$check) {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
        exit;
    }
    $check->bind_param('s', $nid);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        echo json_encode(['success' => false, 'message' => 'NID/Passport already exists']);
        exit;
    }

    if ($email !== '') {
        $check = $mysqli->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if (!$check) {
            echo json_encode(['success' => false, 'message' => $mysqli->error]);
            exit;
        }
        $check->bind_param('s', $email);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit;
        }
    }

    $profile = file_blob_data('profile_image');
    if ($profile['error']) {
        echo json_encode(['success' => false, 'message' => $profile['error']]);
        exit;
    }

    $nidImg = file_blob_data('nid_image');
    if ($nidImg['error']) {
        echo json_encode(['success' => false, 'message' => $nidImg['error']]);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $mysqli->prepare("
        INSERT INTO users (
            names, nid_passport, email, password, phone1, phone2,
            guarantee_name, guarantee_nid_passport, guarantee_email,
            guarantee_phone1, guarantee_phone2, is_member, is_admin,
            profile_image_name, profile_image_mime, profile_image_data,
            nid_image_name, nid_image_mime, nid_image_data
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
        exit;
    }

    $stmt->bind_param(
        'sssssssssssiissbssb',
        $names,
        $nid,
        $email,
        $hash,
        $phone1,
        $phone2,
        $guarantee_name,
        $guarantee_nid,
        $guarantee_email,
        $guarantee_phone1,
        $guarantee_phone2,
        $is_member,
        $is_admin,
        $profile['name'],
        $profile['mime'],
        $profile['data'],
        $nidImg['name'],
        $nidImg['mime'],
        $nidImg['data']
    );

    if ($profile['uploaded'] && $profile['data'] !== null) {
        $stmt->send_long_data(15, $profile['data']);
    }
    if ($nidImg['uploaded'] && $nidImg['data'] !== null) {
        $stmt->send_long_data(18, $nidImg['data']);
    }

    if ($stmt->execute()) {
        $newid = $stmt->insert_id;
        $stmt->close();

        notify_admins($mysqli, 'user_event', "User mushya yanditswe (#U-$newid): $names");

        $stmt2 = $mysqli->prepare("
            SELECT " . user_select_fields(true) . "
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt2->bind_param('i', $newid);
        $stmt2->execute();
        $row = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        echo json_encode([
            'success' => true,
            'data'    => normalize_user_row($row)
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    exit;
}

/* ── UPDATE ──────────────────────────────────────────────── */
if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid id']);
        exit;
    }

    $names            = trim($_POST['names'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $phone1           = trim($_POST['phone1'] ?? '');
    $phone2           = trim($_POST['phone2'] ?? '');
    $nid              = trim($_POST['nid_passport'] ?? '');
    $password         = $_POST['password'] ?? '';
    $guarantee_name   = trim($_POST['guarantee_name'] ?? '');
    $guarantee_nid    = trim($_POST['guarantee_nid_passport'] ?? '');
    $guarantee_email  = trim($_POST['guarantee_email'] ?? '');
    $guarantee_phone1 = trim($_POST['guarantee_phone1'] ?? '');
    $guarantee_phone2 = trim($_POST['guarantee_phone2'] ?? '');
    $is_member        = !empty($_POST['is_member']) ? 1 : 0;
    $is_admin         = !empty($_POST['is_admin']) ? 1 : 0;

    if ($names === '' || $nid === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Names and NID/Passport are required'
        ]);
        exit;
    }

    $check = $mysqli->prepare("SELECT id FROM users WHERE nid_passport = ? AND id <> ? LIMIT 1");
    if (!$check) {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
        exit;
    }
    $check->bind_param('si', $nid, $id);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();

    if ($exists) {
        echo json_encode(['success' => false, 'message' => 'NID/Passport already exists']);
        exit;
    }

    if ($email !== '') {
        $check = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        if (!$check) {
            echo json_encode(['success' => false, 'message' => $mysqli->error]);
            exit;
        }
        $check->bind_param('si', $email, $id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit;
        }
    }

    $profile = file_blob_data('profile_image');
    if ($profile['error']) {
        echo json_encode(['success' => false, 'message' => $profile['error']]);
        exit;
    }

    $nidImg = file_blob_data('nid_image');
    if ($nidImg['error']) {
        echo json_encode(['success' => false, 'message' => $nidImg['error']]);
        exit;
    }

    $sql = "
        UPDATE users SET
            names = ?,
            nid_passport = ?,
            email = ?,
            phone1 = ?,
            phone2 = ?,
            guarantee_name = ?,
            guarantee_nid_passport = ?,
            guarantee_email = ?,
            guarantee_phone1 = ?,
            guarantee_phone2 = ?,
            is_member = ?,
            is_admin = ?
    ";

    $params = [
        $names,
        $nid,
        $email,
        $phone1,
        $phone2,
        $guarantee_name,
        $guarantee_nid,
        $guarantee_email,
        $guarantee_phone1,
        $guarantee_phone2,
        $is_member,
        $is_admin
    ];
    $types = 'ssssssssssii';

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $sql .= ", password = ?";
        $params[] = $hash;
        $types .= 's';
    }

    if ($profile['uploaded']) {
        $sql .= ", profile_image_name = ?, profile_image_mime = ?, profile_image_data = ?";
        $params[] = $profile['name'];
        $params[] = $profile['mime'];
        $params[] = $profile['data'];
        $types .= 'ssb';
    }

    if ($nidImg['uploaded']) {
        $sql .= ", nid_image_name = ?, nid_image_mime = ?, nid_image_data = ?";
        $params[] = $nidImg['name'];
        $params[] = $nidImg['mime'];
        $params[] = $nidImg['data'];
        $types .= 'ssb';
    }

    $sql .= " WHERE id = ?";
    $params[] = $id;
    $types .= 'i';

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
        exit;
    }

    $stmt->bind_param($types, ...$params);

    for ($i = 0; $i < strlen($types); $i++) {
        if ($types[$i] === 'b') {
            $stmt->send_long_data($i, $params[$i]);
        }
    }

    if ($stmt->execute()) {
        $stmt->close();

        notify_admins($mysqli, 'user_event', "User yahinduwe (#U-$id): $names");

        $stmt2 = $mysqli->prepare("
            SELECT " . user_select_fields(true) . "
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $row = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        echo json_encode([
            'success' => true,
            'data'    => normalize_user_row($row)
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    exit;
}

/* ── DELETE ──────────────────────────────────────────────── */
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid id']);
        exit;
    }

    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
        exit;
    }

    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        $stmt->close();
        notify_admins($mysqli, 'user_event', "User yasibwe (#U-$id)");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
exit;