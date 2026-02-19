<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

// Start output buffering to capture unexpected output
ob_start();

function send_json($data) {
    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

register_shutdown_function(function(){
    $err = error_get_last();
    $buf = '';
    if (ob_get_length() !== false && ob_get_length() > 0) {
        $buf = ob_get_clean();
    }
    $logFile = __DIR__ . '/transactions_debug.log';
    if (trim($buf) !== '') {
        @file_put_contents($logFile, date('c') . " - NON-JSON OUTPUT:\n" . $buf . "\n\n", FILE_APPEND | LOCK_EX);
    }
    if ($err) {
        send_json(['success'=>false,'message'=>'Fatal error','error'=>$err,'output'=>substr($buf,0,2000),'debug_log'=>'pages/admin/transactions_debug.log']);
        exit;
    }
    if (trim($buf) !== '') {
        $trimmed = trim($buf);
        $first = $trimmed[0] ?? '';
        if ($first === '{' || $first === '[') {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                header('Content-Type: application/json; charset=utf-8');
                echo $trimmed;
                exit;
            }
        }
        @file_put_contents($logFile, date('c') . " - SHUTDOWN NON-JSON (cleaned):\n" . substr($buf,0,2000) . "\n\n", FILE_APPEND | LOCK_EX);
        echo json_encode(['success'=>false,'message'=>'Server produced unexpected output. See debug log: pages/admin/transactions_debug.log']);
        exit;
    }
});

$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) { http_response_code(500); send_json(['success'=>false,'message'=>'Database connection failed']); }

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    send_json(['success'=>false,'message'=>'Access denied']);
}

// Simple helper: fetch users for dropdowns
if (isset($_GET['action']) && $_GET['action'] === 'users') {
    $res = $mysqli->query("SELECT id, names FROM users ORDER BY names ASC");
    if (!$res) send_json(['success'=>false,'message'=>'Query error: ' . $mysqli->error]);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    send_json(['success'=>true,'data'=>$rows]);
}

// Provide accounts for dropdowns
if (isset($_GET['action']) && $_GET['action'] === 'accounts') {
    $res = $mysqli->query("SELECT account_id, name FROM accounts ORDER BY name ASC");
    if (!$res) send_json(['success'=>false,'message'=>'Query error: ' . $mysqli->error]);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    send_json(['success'=>true,'data'=>$rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        error_log('API GET ?id request received with id: ' . $id);
        
        if (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        $stmt = $mysqli->prepare("SELECT t.*, u.names as user_name, c.name as account_name, uc.names as created_by_name FROM transactions t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN accounts c ON t.account_id = c.account_id LEFT JOIN users uc ON t.created_by = uc.id WHERE t.trans_id = ? LIMIT 1");
        if (!$stmt) { 
            error_log('Prepare error: ' . $mysqli->error); 
            echo json_encode(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]);
            exit;
        }
        $stmt->bind_param('i',$id);
        if (!$stmt->execute()) { 
            error_log('Execute error: ' . $stmt->error); 
            echo json_encode(['success'=>false,'message'=>'Execute error: '.$stmt->error]);
            exit;
        }
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        error_log('Transaction result: ' . json_encode($row));
        if ($row) { 
            echo json_encode(['success'=>true,'data'=>$row]);
            exit;
        }
        echo json_encode(['success'=>false,'message'=>'Not found']);
        exit;
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 50));
    $q = trim($_GET['q'] ?? '');

    $where = '';
    if ($q !== '') {
        $esc = $mysqli->real_escape_string($q);
        $where = " WHERE u.names LIKE '%$esc%' OR t.type LIKE '%$esc%'";
    }

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM transactions t LEFT JOIN users u ON t.user_id = u.id $where");
    $totalRow = $totalRes->fetch_assoc();
    $total = (int)$totalRow['cnt'];

    $offset = ($page - 1) * $per_page;
    $sql = "SELECT t.trans_id, t.tx_date, t.type, t.amount, u.names as user_name, c.name as account_name, uc.names as created_by_name FROM transactions t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN accounts c ON t.account_id = c.account_id LEFT JOIN users uc ON t.created_by = uc.id $where ORDER BY t.trans_id DESC LIMIT $offset, $per_page";
    $res = $mysqli->query($sql);
    if (!$res) send_json(['success'=>false,'message'=>'Query error: ' . $mysqli->error]);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    send_json(['success'=>true,'data'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$per_page]);
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 0);
    $tx_date = trim($_POST['tx_date'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $reference_name = null;
    $reference_mime = null;
    $reference_blob = null;

    if ($user_id <= 0 || $account_id <= 0 || $tx_date === '' || $type === '' || $amount <= 0) {
        send_json(['success'=>false,'message'=>'Imirima yibanze irabuze cyangwa ifite agaciro kadasobanutse']);
    }

    // handle optional file upload
    if (!empty($_FILES['reference_file']) && is_uploaded_file($_FILES['reference_file']['tmp_name'])) {
        $reference_name = $_FILES['reference_file']['name'];
        $reference_mime = $_FILES['reference_file']['type'];
        $reference_blob = file_get_contents($_FILES['reference_file']['tmp_name']);
    }

    if ($reference_blob !== null) {
        $mysqli->begin_transaction();
        $sql = "INSERT INTO transactions (tx_date, user_id, account_id, type, amount, reference_name, reference_mime, reference_file, created_by) VALUES (?,?,?,?,?,?,?,?,?)";
        $stmt2 = $mysqli->prepare($sql);
        if (!$stmt2) { $mysqli->rollback(); send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]); }
        $null = null;
        $created_by = $_SESSION['id'] ?? 1;
        $stmt2->bind_param('siisdsssi', $tx_date, $user_id, $account_id, $type, $amount, $reference_name, $reference_mime, $null, $created_by);
        if (!$stmt2->execute()) { $mysqli->rollback(); send_json(['success'=>false,'message'=>'Execute error: '.$mysqli->error]); }
        $id = $stmt2->insert_id;
        $stmt_up = $mysqli->prepare("UPDATE transactions SET reference_file = ? WHERE trans_id = ?");
        if (!$stmt_up) { $mysqli->rollback(); send_json(['success'=>false,'message'=>'Prepare update error: '.$mysqli->error]); }
        $stmt_up->bind_param('bi', $null, $id);
        $stmt_up->send_long_data(0, $reference_blob);
        if (!$stmt_up->execute()) { $mysqli->rollback(); send_json(['success'=>false,'message'=>'Blob update error: '.$mysqli->error]); }
        $mysqli->commit();
        $res = $mysqli->query("SELECT t.trans_id, t.tx_date, t.type, t.amount, u.names as user_name, c.name as account_name FROM transactions t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN accounts c ON t.account_id = c.account_id WHERE t.trans_id = " . (int)$id);
        $row = $res->fetch_assoc();
        send_json(['success'=>true,'data'=>$row]);
    } else {
        $created_by = $_SESSION['id'] ?? 1;
        $stmtS = $mysqli->prepare("INSERT INTO transactions (tx_date, user_id, account_id, type, amount, reference_name, reference_mime, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmtS) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]);
        $stmtS->bind_param('siisdssi', $tx_date, $user_id, $account_id, $type, $amount, $reference_name, $reference_mime, $created_by);
        if ($stmtS->execute()) {
            $id = $stmtS->insert_id;
            $res = $mysqli->query("SELECT t.trans_id, t.tx_date, t.type, t.amount, u.names as user_name, c.name as account_name FROM transactions t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN accounts c ON t.account_id = c.account_id WHERE t.trans_id = " . (int)$id);
            $row = $res->fetch_assoc();
            send_json(['success'=>true,'data'=>$row]);
        } else {
            send_json(['success'=>false,'message'=>$mysqli->error]);
        }
    }
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success'=>false,'message'=>'Invalid id']);
    $user_id = intval($_POST['user_id'] ?? 0);
    $account_id = intval($_POST['account_id'] ?? 0);
    $tx_date = trim($_POST['tx_date'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);

    if ($user_id <= 0 || $account_id <= 0 || $tx_date === '' || $type === '' || $amount <= 0) {
        send_json(['success'=>false,'message'=>'Imirima yibanze irabuze cyangwa ifite agaciro kadasobanutse']);
    }

    $stmt = $mysqli->prepare("UPDATE transactions SET tx_date = ?, user_id = ?, account_id = ?, type = ?, amount = ? WHERE trans_id = ?");
    if (!$stmt) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]);
    $stmt->bind_param('siisdii', $tx_date, $user_id, $account_id, $type, $amount, $id);
    if ($stmt->execute()) {
        $res = $mysqli->query("SELECT t.trans_id, t.tx_date, t.type, t.amount, u.names as user_name, c.name as account_name FROM transactions t LEFT JOIN users u ON t.user_id = u.id LEFT JOIN accounts c ON t.account_id = c.account_id WHERE t.trans_id = " . (int)$id);
        $row = $res->fetch_assoc();
        send_json(['success'=>true,'data'=>$row]);
    } else {
        send_json(['success'=>false,'message'=>$mysqli->error]);
    }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success'=>false,'message'=>'Invalid id']);
    $stmt = $mysqli->prepare("DELETE FROM transactions WHERE trans_id = ?");
    $stmt->bind_param('i',$id);
    if ($stmt->execute()) send_json(['success'=>true]);
    else send_json(['success'=>false,'message'=>$mysqli->error]);
}

send_json(['success'=>false,'message'=>'Invalid request']);

?>
