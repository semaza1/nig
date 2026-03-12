<?php
// API for admin to manage users. Supports GET (list) and POST (create/update/delete)
header('Content-Type: application/json; charset=utf-8');
session_start();

$mysqli = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/notifications_helper.php';

// simple admin check - requires login via pages/login.php which sets is_admin
if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   Helper: calculate unpaid principal for a single loan
───────────────────────────────────────────────────────────── */
function loan_unpaid(mysqli $db, int $loanId, float $principal, string $status): float {
    if (!in_array($status, ['approved', 'defaulted', 'closed'], true)) return 0.0;
    $ps = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) AS paid
        FROM transactions
        WHERE loan_id = ? AND type = 'loan_principal' AND direction = 'IN'
    ");
    $ps->bind_param('i', $loanId);
    $ps->execute();
    $paid = (float)($ps->get_result()->fetch_assoc()['paid'] ?? 0);
    $ps->close();
    return max(0.0, $principal - $paid);
}

/* ─────────────────────────────────────────────────────────────
   GET requests
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    /* ── Single user (plain – for edit form) ──────────────── */
    if (isset($_GET['id']) && !isset($_GET['full'])) {
        $id   = (int)$_GET['id'];
        $stmt = $mysqli->prepare("
            SELECT id, names, nid_passport, email, password, phone1, phone2,
                   guarantee_name, guarantee_nid_passport, guarantee_email,
                   guarantee_phone1, guarantee_phone2, is_member, is_admin
            FROM users WHERE id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $row['is_member'] = (int)$row['is_member'];
            $row['is_admin']  = (int)$row['is_admin'];
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Not found']);
        }
        exit;
    }

    /* ── Single user FULL profile (view popup) ─────────────── */
    if (isset($_GET['id']) && isset($_GET['full']) && $_GET['full'] === '1') {
        $id = (int)$_GET['id'];

        // Base user record
        $stmt = $mysqli->prepare("
            SELECT id, names, nid_passport, email, phone1, phone2,
                   guarantee_name, guarantee_nid_passport, guarantee_email,
                   guarantee_phone1, guarantee_phone2, is_member, is_admin
            FROM users WHERE id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        $user['is_member'] = (int)$user['is_member'];
        $user['is_admin']  = (int)$user['is_admin'];

        // ── All transactions ──────────────────────────────────
        $transactions = [];
        $res = $mysqli->prepare("
            SELECT
                t.transaction_id,
                t.tx_date,
                t.type,
                t.direction,
                t.amount,
                t.description,
                t.loan_id,
                a.name AS account_name
            FROM transactions t
            LEFT JOIN accounts a ON a.account_id = t.account_id
            WHERE t.user_id = ?
            ORDER BY t.tx_date DESC, t.transaction_id DESC
        ");
        $res->bind_param('i', $id);
        $res->execute();
        $rows = $res->get_result();
        while ($r = $rows->fetch_assoc()) $transactions[] = $r;
        $res->close();

        // ── Loans borrowed ────────────────────────────────────
        $loans = [];
        $res = $mysqli->prepare("
            SELECT
                loan_id, principal, interest_rate,
                status, start_date, end_date, created_at
            FROM loans
            WHERE borrower_user_id = ?
            ORDER BY loan_id DESC
        ");
        $res->bind_param('i', $id);
        $res->execute();
        $rows = $res->get_result();
        while ($r = $rows->fetch_assoc()) {
            $r['unpaid_principal'] = round(
                loan_unpaid($mysqli, (int)$r['loan_id'], (float)$r['principal'], (string)$r['status']),
                2
            );
            $loans[] = $r;
        }
        $res->close();

        // ── Loans this user has guaranteed ────────────────────
        $guarantors = [];
        $res = $mysqli->prepare("
            SELECT
                lg.loan_id,
                lg.guarantee_amount,
                lg.status,
                lg.created_at   AS start_date,
                l.status        AS loan_status,
                u.names         AS borrower_name
            FROM loan_guaranters lg
            INNER JOIN loans l ON l.loan_id        = lg.loan_id
            INNER JOIN users u ON u.id             = l.borrower_user_id
            WHERE lg.guarantor_user_id = ?
            ORDER BY lg.loan_id DESC
        ");
        $res->bind_param('i', $id);
        $res->execute();
        $rows = $res->get_result();
        while ($r = $rows->fetch_assoc()) $guarantors[] = $r;
        $res->close();

        // ── Assets held by this user ──────────────────────────
        $assets = [];
        $res = $mysqli->prepare("
            SELECT
                a.asset_id, a.name, a.purchase_date,
                a.purchase_value, a.location, a.sold_value
            FROM asset_holders ah
            INNER JOIN assets a ON a.asset_id = ah.asset_id
            WHERE ah.user_id = ?
            ORDER BY a.asset_id DESC
        ");
        $res->bind_param('i', $id);
        $res->execute();
        $rows = $res->get_result();
        while ($r = $rows->fetch_assoc()) $assets[] = $r;
        $res->close();

        echo json_encode([
            'success'      => true,
            'data'         => $user,
            'transactions' => $transactions,
            'loans'        => $loans,
            'guarantors'   => $guarantors,
            'assets'       => $assets,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ── Paginated user list ────────────────────────────────── */
    $page     = max(1, intval($_GET['page']     ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 10));
    $q        = trim($_GET['q'] ?? '');

    $where = '';
    if ($q !== '') {
        $esc   = $mysqli->real_escape_string($q);
        $where = " WHERE names LIKE '%$esc%' OR email LIKE '%$esc%' OR phone1 LIKE '%$esc%' OR phone2 LIKE '%$esc%' OR nid_passport LIKE '%$esc%'";
    }

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM users $where");
    $total    = (int)$totalRes->fetch_assoc()['cnt'];
    $offset   = ($page - 1) * $per_page;

    $res  = $mysqli->query("
        SELECT id, names, nid_passport, email, phone1, phone2,
               guarantee_name, guarantee_nid_passport, guarantee_email,
               guarantee_phone1, guarantee_phone2, is_member, is_admin
        FROM users $where
        ORDER BY id DESC
        LIMIT $offset, $per_page
    ");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['is_member'] = (int)$r['is_member'];
        $r['is_admin']  = (int)$r['is_admin'];
        $rows[] = $r;
    }
    echo json_encode([
        'success'  => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
    ]);
    exit;
}

/* ─────────────────────────────────────────────────────────────
   POST – create / update / delete
───────────────────────────────────────────────────────────── */
$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $names            = trim($_POST['names']                  ?? '');
    $email            = trim($_POST['email']                  ?? '');
    $phone1           = trim($_POST['phone1']                 ?? '');
    $phone2           = trim($_POST['phone2']                 ?? '');
    $nid              = trim($_POST['nid_passport']           ?? '');
    $password         = $_POST['password']                    ?? '';
    $guarantee_name   = trim($_POST['guarantee_name']         ?? '');
    $guarantee_nid    = trim($_POST['guarantee_nid_passport'] ?? '');
    $guarantee_email  = trim($_POST['guarantee_email']        ?? '');
    $guarantee_phone1 = trim($_POST['guarantee_phone1']       ?? '');
    $guarantee_phone2 = trim($_POST['guarantee_phone2']       ?? '');
    $is_member        = !empty($_POST['is_member'])            ? 1 : 0;
    $is_admin         = !empty($_POST['is_admin'])             ? 1 : 0;

    if ($names === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Amazina na ijambo ry\'ibanga ariyongerwa']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("
        INSERT INTO users
            (names, nid_passport, email, password, phone1, phone2,
             guarantee_name, guarantee_nid_passport, guarantee_email,
             guarantee_phone1, guarantee_phone2, is_member, is_admin)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'sssssssssssii',
        $names, $nid, $email, $hash, $phone1, $phone2,
        $guarantee_name, $guarantee_nid, $guarantee_email,
        $guarantee_phone1, $guarantee_phone2, $is_member, $is_admin
    );
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $stmt->close();
        notify_admins($mysqli, 'user_event', "User mushya yanditswe (#U-$id): $names");
        $res = $mysqli->query("
            SELECT id, names, nid_passport, email, phone1, phone2,
                   guarantee_name, guarantee_nid_passport, guarantee_email,
                   guarantee_phone1, guarantee_phone2, is_member, is_admin
            FROM users WHERE id = " . (int)$id
        );
        echo json_encode(['success' => true, 'data' => $res->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid id']);
        exit;
    }

    $names            = trim($_POST['names']                  ?? '');
    $email            = trim($_POST['email']                  ?? '');
    $phone1           = trim($_POST['phone1']                 ?? '');
    $phone2           = trim($_POST['phone2']                 ?? '');
    $nid              = trim($_POST['nid_passport']           ?? '');
    $password         = $_POST['password']                    ?? null;
    $guarantee_name   = trim($_POST['guarantee_name']         ?? '');
    $guarantee_nid    = trim($_POST['guarantee_nid_passport'] ?? '');
    $guarantee_email  = trim($_POST['guarantee_email']        ?? '');
    $guarantee_phone1 = trim($_POST['guarantee_phone1']       ?? '');
    $guarantee_phone2 = trim($_POST['guarantee_phone2']       ?? '');
    $is_member        = !empty($_POST['is_member'])            ? 1 : 0;
    $is_admin         = !empty($_POST['is_admin'])             ? 1 : 0;

    if ($password !== null && $password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("
            UPDATE users
            SET names=?, nid_passport=?, email=?, password=?,
                phone1=?, phone2=?, guarantee_name=?, guarantee_nid_passport=?,
                guarantee_email=?, guarantee_phone1=?, guarantee_phone2=?,
                is_member=?, is_admin=?
            WHERE id=?
        ");
        $stmt->bind_param(
            'sssssssssssiii',
            $names, $nid, $email, $hash, $phone1, $phone2,
            $guarantee_name, $guarantee_nid, $guarantee_email,
            $guarantee_phone1, $guarantee_phone2, $is_member, $is_admin, $id
        );
    } else {
        $stmt = $mysqli->prepare("
            UPDATE users
            SET names=?, nid_passport=?, email=?,
                phone1=?, phone2=?, guarantee_name=?, guarantee_nid_passport=?,
                guarantee_email=?, guarantee_phone1=?, guarantee_phone2=?,
                is_member=?, is_admin=?
            WHERE id=?
        ");
        $stmt->bind_param(
            'ssssssssssiii',
            $names, $nid, $email, $phone1, $phone2,
            $guarantee_name, $guarantee_nid, $guarantee_email,
            $guarantee_phone1, $guarantee_phone2, $is_member, $is_admin, $id
        );
    }

    if ($stmt->execute()) {
        $stmt->close();
        notify_admins($mysqli, 'user_event', "User yahinduwe (#U-$id): $names");
        $res = $mysqli->query("
            SELECT id, names, nid_passport, email, phone1, phone2,
                   guarantee_name, guarantee_nid_passport, guarantee_email,
                   guarantee_phone1, guarantee_phone2, is_member, is_admin
            FROM users WHERE id = " . (int)$id
        );
        echo json_encode(['success' => true, 'data' => $res->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid id']);
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        notify_admins($mysqli, 'user_event', "User yasibwe (#U-$id)");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
exit;