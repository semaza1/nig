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
   Generic DB helpers
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

function table_exists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$table}'");
    return $res && $res->num_rows > 0;
}

function respond_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   Users / images helpers
───────────────────────────────────────────────────────────── */
function user_select_fields(bool $includeImageMeta = true): string {
    $fields = "
        id, names, nid_passport, email, phone1, phone2,
        guarantee_name, guarantee_nid_passport, guarantee_email,
        guarantee_phone1, guarantee_phone2, is_member, is_admin
    ";

    if ($includeImageMeta) {
        $fields .= ",
            profile_image_name, profile_image_mime,
            nid_image_name, nid_image_mime
        ";
    }

    return $fields;
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
            'error' => null,
        ];
    }

    $f = $_FILES[$field];

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [
            'uploaded' => false,
            'name' => null,
            'mime' => null,
            'data' => null,
            'error' => 'Upload failed for ' . $field,
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
        'error' => null,
    ];
}

function fetch_user_by_id(mysqli $db, int $id, bool $includeImageMeta = true): ?array {
    $stmt = $db->prepare("
        SELECT " . user_select_fields($includeImageMeta) . "
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? normalize_user_row($row) : null;
}

function check_unique_user_fields(mysqli $db, string $nid, string $email = '', ?int $excludeId = null): ?string {
    if ($excludeId === null) {
        $stmt = $db->prepare("SELECT id FROM users WHERE nid_passport = ? LIMIT 1");
        if (!$stmt) return $db->error;
        $stmt->bind_param('s', $nid);
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE nid_passport = ? AND id <> ? LIMIT 1");
        if (!$stmt) return $db->error;
        $stmt->bind_param('si', $nid, $excludeId);
    }
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($exists) return 'NID/Passport already exists';

    if ($email !== '') {
        if ($excludeId === null) {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            if (!$stmt) return $db->error;
            $stmt->bind_param('s', $email);
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            if (!$stmt) return $db->error;
            $stmt->bind_param('si', $email, $excludeId);
        }
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) return 'Email already exists';
    }

    return null;
}

/* ─────────────────────────────────────────────────────────────
   Finance helpers
───────────────────────────────────────────────────────────── */
function loan_unpaid(mysqli $db, int $loanId, float $principal, string $status): float {
    if (!in_array($status, ['approved', 'defaulted', 'closed'], true)) return 0.0;

    $row = db_query_one($db, "
        SELECT COALESCE(SUM(amount), 0) AS paid
        FROM transactions
        WHERE loan_id = {$loanId}
          AND type = 'loan_principal'
          AND direction = 'IN'
    ");
    $paid = (float)($row['paid'] ?? 0);

    return max(0.0, $principal - $paid);
}

function calculate_running_balance(array $transactions): array {
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

        if ($dir === 'IN') $balance += $amount;
        else $balance -= $amount;

        $t['running_balance'] = round($balance, 2);
        $rows[] = $t;
    }

    return $rows;
}

function get_loan_payment_history(mysqli $db, int $loanId): array {
    return db_query($db, "
        SELECT transaction_id, tx_date, type, direction, amount, description
        FROM transactions
        WHERE loan_id = {$loanId}
          AND type IN ('loan_principal', 'loan_interest', 'penalty')
        ORDER BY tx_date ASC, transaction_id ASC
    ");
}

/* ─────────────────────────────────────────────────────────────
   Allocation engine with event-by-event history
   Base at any moment:
   (contribution - withdraw) + interest_gained - expense_portion
───────────────────────────────────────────────────────────── */
function nig_member_allocation_cache(mysqli $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $members = db_query($db, "
        SELECT id, names
        FROM users
        WHERE is_member = 1
        ORDER BY id ASC
    ");

    $state = [];
    foreach ($members as $m) {
        $uid = (int)$m['id'];
        $state[$uid] = [
            'user_id' => $uid,
            'names' => $m['names'],
            'contribution' => 0.0,
            'withdraw' => 0.0,
            'interest_gained' => 0.0,
            'expense_portion' => 0.0,
            'contribution_base' => 0.0,
            'interest_history' => [],
            'expense_history' => [],
        ];
    }

    if (!$state) {
        $cache = [];
        return $cache;
    }

    $events = db_query($db, "
        SELECT
            t.transaction_id,
            t.tx_date,
            t.user_id,
            t.loan_id,
            t.type,
            t.direction,
            t.amount,
            t.description,
            COALESCE(bu.names, '') AS loan_borrower_name
        FROM transactions t
        LEFT JOIN loans l ON l.loan_id = t.loan_id
        LEFT JOIN users bu ON bu.id = l.borrower_user_id
        WHERE t.type IN (
            'contribution',
            'withdrawal',
            'withdrawal_deduction',
            'loan_interest',
            'expense'
        )
        ORDER BY t.tx_date ASC, t.transaction_id ASC
    ");

    foreach ($events as $ev) {
        $type = (string)($ev['type'] ?? '');
        $dir  = strtoupper((string)($ev['direction'] ?? ''));
        $amt  = (float)($ev['amount'] ?? 0);
        $uid  = isset($ev['user_id']) ? (int)$ev['user_id'] : 0;

        if ($amt <= 0) continue;

        if ($type === 'contribution' && $dir === 'IN' && $uid > 0 && isset($state[$uid])) {
            $state[$uid]['contribution'] += $amt;
            continue;
        }

        if (in_array($type, ['withdrawal', 'withdrawal_deduction'], true) && $dir === 'OUT' && $uid > 0 && isset($state[$uid])) {
            $state[$uid]['withdraw'] += $amt;
            continue;
        }

        if ($type === 'loan_interest' && $dir === 'IN') {
            $weights = [];
            $totalWeight = 0.0;

            foreach ($state as $memberId => $m) {
                $base = ($m['contribution'] - $m['withdraw']) + $m['interest_gained'] - $m['expense_portion'];
                $weight = max(0.0, $base);
                $weights[$memberId] = $weight;
                $totalWeight += $weight;
            }

            if ($totalWeight <= 0) continue;

            foreach ($state as $memberId => $m) {
                $memberBase = $weights[$memberId];
                $share = ($memberBase / $totalWeight) * $amt;
                $state[$memberId]['interest_gained'] += $share;

                $state[$memberId]['interest_history'][] = [
                    'transaction_id' => (int)$ev['transaction_id'],
                    'tx_date' => $ev['tx_date'],
                    'loan_id' => $ev['loan_id'],
                    'borrower_name' => $ev['loan_borrower_name'] ?: null,
                    'description' => $ev['description'],
                    'source_amount' => round($amt, 2),
                    'member_base' => round($memberBase, 2),
                    'total_base' => round($totalWeight, 2),
                    'member_share' => round($share, 2),
                ];
            }
            continue;
        }

        if ($type === 'expense' && $dir === 'OUT') {
            $weights = [];
            $totalWeight = 0.0;

            foreach ($state as $memberId => $m) {
                $base = ($m['contribution'] - $m['withdraw']) + $m['interest_gained'] - $m['expense_portion'];
                $weight = max(0.0, $base);
                $weights[$memberId] = $weight;
                $totalWeight += $weight;
            }

            if ($totalWeight <= 0) continue;

            foreach ($state as $memberId => $m) {
                $memberBase = $weights[$memberId];
                $share = ($memberBase / $totalWeight) * $amt;
                $state[$memberId]['expense_portion'] += $share;

                $state[$memberId]['expense_history'][] = [
                    'transaction_id' => (int)$ev['transaction_id'],
                    'tx_date' => $ev['tx_date'],
                    'description' => $ev['description'],
                    'source_amount' => round($amt, 2),
                    'member_base' => round($memberBase, 2),
                    'total_base' => round($totalWeight, 2),
                    'member_share' => round($share, 2),
                ];
            }
            continue;
        }
    }

    foreach ($state as $memberId => $m) {
        $base = ($m['contribution'] - $m['withdraw']) + $m['interest_gained'] - $m['expense_portion'];
        $state[$memberId]['contribution_base'] = max(0.0, $base);

        $state[$memberId]['contribution']      = round($state[$memberId]['contribution'], 2);
        $state[$memberId]['withdraw']          = round($state[$memberId]['withdraw'], 2);
        $state[$memberId]['interest_gained']   = round($state[$memberId]['interest_gained'], 2);
        $state[$memberId]['expense_portion']   = round($state[$memberId]['expense_portion'], 2);
        $state[$memberId]['contribution_base'] = round($state[$memberId]['contribution_base'], 2);
    }

    $cache = $state;
    return $cache;
}

function get_member_contribution_total(mysqli $db, int $userId): float {
    $alloc = nig_member_allocation_cache($db);
    return (float)($alloc[$userId]['contribution'] ?? 0.0);
}

function get_member_withdraw_total(mysqli $db, int $userId): float {
    $alloc = nig_member_allocation_cache($db);
    return (float)($alloc[$userId]['withdraw'] ?? 0.0);
}

function get_user_expense_portion_total(mysqli $db, int $userId): float {
    $alloc = nig_member_allocation_cache($db);
    return (float)($alloc[$userId]['expense_portion'] ?? 0.0);
}

function get_member_contribution_base(mysqli $db, int $userId): float {
    $alloc = nig_member_allocation_cache($db);
    return (float)($alloc[$userId]['contribution_base'] ?? 0.0);
}

function calculate_member_interest_shares(mysqli $db): array {
    $alloc = nig_member_allocation_cache($db);
    $result = [];

    foreach ($alloc as $uid => $row) {
        $result[$uid] = round((float)($row['interest_gained'] ?? 0.0), 2);
    }

    return $result;
}

function get_user_interest_history(mysqli $db, int $userId): array {
    $alloc = nig_member_allocation_cache($db);
    return $alloc[$userId]['interest_history'] ?? [];
}

function get_user_expense_history(mysqli $db, int $userId): array {
    $alloc = nig_member_allocation_cache($db);
    return $alloc[$userId]['expense_history'] ?? [];
}

/* ─────────────────────────────────────────────────────────────
   GET
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

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

        if (!$stmt) respond_json(['success' => false, 'message' => 'DB error: ' . $mysqli->error], 500);

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['file_data'])) {
            respond_json(['success' => false, 'message' => 'Image not found'], 404);
        }

        header_remove('Content-Type');
        header('Content-Type: ' . ($row['file_mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . strlen($row['file_data']));
        header('Content-Disposition: inline; filename="' . basename($row['file_name'] ?: 'image') . '"');
        echo $row['file_data'];
        exit;
    }

    if (isset($_GET['id']) && !isset($_GET['full'])) {
        $id = (int)$_GET['id'];
        $row = fetch_user_by_id($mysqli, $id, true);

        if ($row) respond_json(['success' => true, 'data' => $row]);
        respond_json(['success' => false, 'message' => 'Not found']);
    }

    if (isset($_GET['id'], $_GET['full']) && $_GET['full'] === '1') {
        $id = (int)$_GET['id'];
        $user = fetch_user_by_id($mysqli, $id, true);

        if (!$user) respond_json(['success' => false, 'message' => 'User not found']);

        $transactions = db_query($mysqli, "
            SELECT t.transaction_id, t.tx_date, t.type, t.direction,
                   t.amount, t.description, t.loan_id,
                   a.name AS account_name
            FROM transactions t
            LEFT JOIN accounts a ON a.account_id = t.account_id
            WHERE t.user_id = {$id}
            ORDER BY t.tx_date DESC, t.transaction_id DESC
        ");
        $transactions = calculate_running_balance($transactions);

        $loan_rows = db_query($mysqli, "
            SELECT loan_id, principal, interest_rate, status,
                   start_date, end_date, created_at
            FROM loans
            WHERE borrower_user_id = {$id}
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

        $guaranteed = [];
        if (table_exists($mysqli, 'loan_guaranters')) {
            $guaranteed = db_query($mysqli, "
                SELECT lg.loan_id, lg.guarantee_amount, lg.status,
                       lg.created_at AS since,
                       l.status      AS loan_status,
                       l.principal   AS loan_principal,
                       u.names       AS borrower_name
                FROM loan_guaranters lg
                INNER JOIN loans l ON l.loan_id = lg.loan_id
                INNER JOIN users u ON u.id = l.borrower_user_id
                WHERE lg.guarantor_user_id = {$id}
                  AND l.status <> 'closed'
                ORDER BY lg.loan_id DESC
            ");
        }

        $assets = [];
        if (table_exists($mysqli, 'asset_holders')) {
            $assets = db_query($mysqli, "
                SELECT a.asset_id, a.name, a.purchase_date,
                       a.purchase_value, a.location, a.sold_value
                FROM asset_holders ah
                INNER JOIN assets a ON a.asset_id = ah.asset_id
                WHERE ah.user_id = {$id}
                ORDER BY a.asset_id DESC
            ");
        }

        $interestShares        = calculate_member_interest_shares($mysqli);
        $userInterestGained    = (float)($interestShares[$id] ?? 0.0);
        $userContributionBase  = get_member_contribution_base($mysqli, $id);
        $userExpensePortion    = get_user_expense_portion_total($mysqli, $id);
        $userContributionTotal = get_member_contribution_total($mysqli, $id);
        $userWithdrawTotal     = get_member_withdraw_total($mysqli, $id);
        $interestHistory       = get_user_interest_history($mysqli, $id);
        $expenseHistory        = get_user_expense_history($mysqli, $id);

        $summary = [
            'interest_gained'    => round($userInterestGained, 2),
            'contribution_base'  => round($userContributionBase, 2),
            'expense_portion'    => round($userExpensePortion, 2),
            'total_contribution' => round($userContributionTotal, 2),
            'total_withdraw'     => round($userWithdrawTotal, 2),
        ];

        respond_json([
            'success'          => true,
            'data'             => $user,
            'transactions'     => $transactions,
            'loans'            => $loans,
            'guaranteed'       => $guaranteed,
            'assets'           => $assets,
            'summary'          => $summary,
            'interest_history' => $interestHistory,
            'expense_history'  => $expenseHistory,
        ]);
    }

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, (int)($_GET['per_page'] ?? 10));
    $q        = trim($_GET['q'] ?? '');

    $where  = '';
    $params = [];
    $types  = '';

    if ($q !== '') {
        $where = " WHERE names LIKE ? OR email LIKE ? OR phone1 LIKE ? OR phone2 LIKE ? OR nid_passport LIKE ? ";
        $like  = '%' . $q . '%';
        $params = [$like, $like, $like, $like, $like];
        $types  = 'sssss';
    }

    $stmt = $mysqli->prepare("SELECT COUNT(*) AS total FROM users{$where}");
    if (!$stmt) respond_json(['success' => false, 'message' => 'DB error: ' . $mysqli->error]);

    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total  = (int)($totalRow['total'] ?? 0);
    $offset = ($page - 1) * $per_page;

    $listSql = "
        SELECT " . user_select_fields(true) . "
        FROM users
        {$where}
        ORDER BY id DESC
        LIMIT ?, ?
    ";

    $stmt = $mysqli->prepare($listSql);
    if (!$stmt) respond_json(['success' => false, 'message' => 'DB error: ' . $mysqli->error]);

    if ($types !== '') {
        $bindValues = array_merge($params, [$offset, $per_page]);
        $stmt->bind_param($types . 'ii', ...$bindValues);
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

    respond_json([
        'success'  => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ]);
}

/* ─────────────────────────────────────────────────────────────
   POST
───────────────────────────────────────────────────────────── */
$action = $_POST['action'] ?? '';

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
        respond_json(['success' => false, 'message' => 'Names, NID/Passport, and password are required']);
    }

    $uniqueError = check_unique_user_fields($mysqli, $nid, $email, null);
    if ($uniqueError) respond_json(['success' => false, 'message' => $uniqueError]);

    $profile = file_blob_data('profile_image');
    if ($profile['error']) respond_json(['success' => false, 'message' => $profile['error']]);

    $nidImg = file_blob_data('nid_image');
    if ($nidImg['error']) respond_json(['success' => false, 'message' => $nidImg['error']]);

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
    if (!$stmt) respond_json(['success' => false, 'message' => $mysqli->error]);

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

    if ($profile['uploaded'] && $profile['data'] !== null) $stmt->send_long_data(15, $profile['data']);
    if ($nidImg['uploaded'] && $nidImg['data'] !== null) $stmt->send_long_data(18, $nidImg['data']);

    if (!$stmt->execute()) respond_json(['success' => false, 'message' => $stmt->error]);

    $newId = (int)$stmt->insert_id;
    $stmt->close();

    notify_admins($mysqli, 'user_event', "User mushya yanditswe (#U-{$newId}): {$names}");

    $row = fetch_user_by_id($mysqli, $newId, true);
    respond_json(['success' => true, 'data' => $row]);
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) respond_json(['success' => false, 'message' => 'Invalid id']);

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
        respond_json(['success' => false, 'message' => 'Names and NID/Passport are required']);
    }

    $uniqueError = check_unique_user_fields($mysqli, $nid, $email, $id);
    if ($uniqueError) respond_json(['success' => false, 'message' => $uniqueError]);

    $profile = file_blob_data('profile_image');
    if ($profile['error']) respond_json(['success' => false, 'message' => $profile['error']]);

    $nidImg = file_blob_data('nid_image');
    if ($nidImg['error']) respond_json(['success' => false, 'message' => $nidImg['error']]);

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
        $is_admin,
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
    if (!$stmt) respond_json(['success' => false, 'message' => $mysqli->error]);

    $stmt->bind_param($types, ...$params);

    for ($i = 0; $i < strlen($types); $i++) {
        if ($types[$i] === 'b') $stmt->send_long_data($i, $params[$i]);
    }

    if (!$stmt->execute()) respond_json(['success' => false, 'message' => $stmt->error]);

    $stmt->close();

    notify_admins($mysqli, 'user_event', "User yahinduwe (#U-{$id}): {$names}");

    $row = fetch_user_by_id($mysqli, $id, true);
    respond_json(['success' => true, 'data' => $row]);
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) respond_json(['success' => false, 'message' => 'Invalid id']);

    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
    if (!$stmt) respond_json(['success' => false, 'message' => $mysqli->error]);

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) respond_json(['success' => false, 'message' => $stmt->error]);

    $stmt->close();

    notify_admins($mysqli, 'user_event', "User yasibwe (#U-{$id})");
    respond_json(['success' => true]);
}

respond_json(['success' => false, 'message' => 'Invalid request']);