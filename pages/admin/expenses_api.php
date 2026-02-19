<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

// Start output buffering to capture any unexpected HTML/PHP output (warnings, notices)
ob_start();

function send_json($data) {
    // Clear any buffered output (warnings/notices) to ensure pure JSON response
    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Catch fatal errors and any stray output and return as JSON for debugging
register_shutdown_function(function() {
    $err = error_get_last();
    $buf = '';
    if (ob_get_length() !== false && ob_get_length() > 0) {
        $buf = ob_get_clean();
    }
    // persist debug output for inspection
    $logFile = __DIR__ . '/expenses_debug.log';
    if (trim($buf) !== '') {
        $entry = date('c') . " - NON-JSON OUTPUT:\n" . $buf . "\n\n";
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
    if ($err) {
        $msg = $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line'];
        header('Content-Type: application/json; charset=utf-8');
        send_json(['success' => false, 'message' => 'Fatal error', 'error' => $msg, 'output' => substr($buf, 0, 2000), 'debug_log' => 'pages/admin/expenses_debug.log']);
        exit;
    }
    if (trim($buf) !== '') {
        // If the buffered output itself is valid JSON, return it directly
        $trimmed = trim($buf);
        $firstChar = $trimmed[0] ?? '';
        if ($firstChar === '{' || $firstChar === '[') {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                header('Content-Type: application/json; charset=utf-8');
                // echo the original JSON payload (prevents double-wrapping)
                echo $trimmed;
                exit;
            }
        }

        // Otherwise persist a cleaned log and return a short JSON pointing to the debug log
        @file_put_contents($logFile, date('c') . " - SHUTDOWN NON-JSON (cleaned):\n" . substr($buf, 0, 2000) . "\n\n", FILE_APPEND | LOCK_EX);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Server produced unexpected output. See debug log: pages/admin/expenses_debug.log']);
        exit;
    }
});

$mysqli = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/notifications_helper.php';

// Check for database connection errors
if (!$mysqli) {
    http_response_code(500);
    send_json(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    send_json(['success' => false, 'message' => 'Access denied']);
    exit;
}

$admin_id = $_SESSION['user_id'] ?? 0;

// Get list of accounts for dropdown
if (isset($_GET['action']) && $_GET['action'] === 'accounts') {
    $res = $mysqli->query("SELECT account_id, name FROM accounts ORDER BY name ASC");
    if (!$res) {
        send_json(['success' => false, 'message' => 'Query error: ' . $mysqli->error]);
        exit;
    }
    $accounts = [];
    while ($r = $res->fetch_assoc()) {
        $accounts[] = $r;
    }
    send_json(['success' => true, 'data' => $accounts]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $mysqli->prepare("SELECT expense_id, account_id, expense_date, category, amount, description, created_by, created_at FROM expenses WHERE expense_id = ? LIMIT 1");
        if (!$stmt) {
            send_json(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error]);
            exit;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        if ($row) {
            send_json(['success' => true, 'data' => $row]);
        } else {
            send_json(['success' => false, 'message' => 'Not found']);
        }
        exit;
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 50));
    $q = trim($_GET['q'] ?? '');

    $where = '';
    if ($q !== '') {
        $esc = $mysqli->real_escape_string($q);
        $where = " WHERE category LIKE '%$esc%' OR description LIKE '%$esc%'";
    }

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM expenses $where");
    if (!$totalRes) {
        send_json(['success' => false, 'message' => 'Query error: ' . $mysqli->error]);
        exit;
    }
    $totalRow = $totalRes->fetch_assoc();
    $total = (int)$totalRow['cnt'];

    $offset = ($page - 1) * $per_page;
    $res = $mysqli->query("SELECT e.expense_id, e.account_id, a.name as account_name, e.expense_date, e.category, e.amount, e.description, e.created_by, e.created_at FROM expenses e LEFT JOIN accounts a ON e.account_id = a.account_id $where ORDER BY e.expense_id DESC LIMIT $offset, $per_page");
    if (!$res) {
        send_json(['success' => false, 'message' => 'Query error: ' . $mysqli->error]);
        exit;
    }
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    send_json(['success' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page]);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $account_id = intval($_POST['account_id'] ?? 0);
    $expense_date = trim($_POST['expense_date'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? null);

    if ($account_id <= 0 || $expense_date === '' || $category === '' || $amount <= 0) {
        send_json(['success' => false, 'message' => 'Missing or invalid required fields']);
        exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO expenses (account_id, expense_date, category, amount, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        send_json(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error]);
        exit;
    }
    $stmt->bind_param('issdsi', $account_id, $expense_date, $category, $amount, $description, $admin_id);
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $res = $mysqli->query("SELECT e.expense_id, e.account_id, a.name as account_name, e.expense_date, e.category, e.amount, e.description, e.created_by, e.created_at FROM expenses e LEFT JOIN accounts a ON e.account_id = a.account_id WHERE e.expense_id = " . (int)$id);
        if (!$res) {
            send_json(['success' => false, 'message' => 'Query error: ' . $mysqli->error]);
            exit;
        }
        $row = $res->fetch_assoc();
        $msg = "Expense yanditswe (#EX-$id): $category - " . number_format((float)$amount) . " Frw";
        nig_notify_admins($mysqli, 'expense_recorded', $msg);
        send_json(['success' => true, 'data' => $row]);
        exit;
    } else {
        send_json(['success' => false, 'message' => $mysqli->error]);
        exit;
    }
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        send_json(['success' => false, 'message' => 'Invalid id']);
        exit;
    }
    $account_id = intval($_POST['account_id'] ?? 0);
    $expense_date = trim($_POST['expense_date'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? null);

    if ($account_id <= 0 || $expense_date === '' || $category === '' || $amount <= 0) {
        send_json(['success' => false, 'message' => 'Missing or invalid required fields']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE expenses SET account_id = ?, expense_date = ?, category = ?, amount = ?, description = ? WHERE expense_id = ?");
    if (!$stmt) {
        send_json(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error]);
        exit;
    }
    $stmt->bind_param('issdsi', $account_id, $expense_date, $category, $amount, $description, $id);
    
    if ($stmt->execute()) {
        $res = $mysqli->query("SELECT e.expense_id, e.account_id, a.name as account_name, e.expense_date, e.category, e.amount, e.description, e.created_by, e.created_at FROM expenses e LEFT JOIN accounts a ON e.account_id = a.account_id WHERE e.expense_id = " . (int)$id);
        if (!$res) {
            send_json(['success' => false, 'message' => 'Query error: ' . $mysqli->error]);
            exit;
        }
        $row = $res->fetch_assoc();
        $msg = "Expense yahinduwe (#EX-$id): $category - " . number_format((float)$amount) . " Frw";
        nig_notify_admins($mysqli, 'expense_recorded', $msg);
        send_json(['success' => true, 'data' => $row]);
        exit;
    } else {
        send_json(['success' => false, 'message' => $mysqli->error]);
        exit;
    }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        send_json(['success' => false, 'message' => 'Invalid id']);
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM expenses WHERE expense_id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        nig_notify_admins($mysqli, 'expense_recorded', "Expense yasibwe (#EX-$id)");
        send_json(['success' => true]);
        exit;
    } else {
        send_json(['success' => false, 'message' => $mysqli->error]);
        exit;
    }
}

send_json(['success' => false, 'message' => 'Invalid request']);
exit;

?>
