<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
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
    $logFile = __DIR__ . '/loans_debug.log';
    if (trim($buf) !== '') {
        @file_put_contents($logFile, date('c') . " - NON-JSON OUTPUT:\n" . $buf . "\n\n", FILE_APPEND | LOCK_EX);
    }
    if ($err) {
        send_json(['success'=>false,'message'=>'Fatal error','error'=>$err,'output'=>substr($buf,0,2000),'debug_log'=>'pages/admin/loans_debug.log']);
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
        echo json_encode(['success'=>false,'message'=>'Server produced unexpected output. See debug log: pages/admin/loans_debug.log']);
        exit;
    }
});

$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) { http_response_code(500); send_json(['success'=>false,'message'=>'Database connection failed']); }

require_once __DIR__ . '/notifications_helper.php';

// Debug: Log session info
error_log('Session data: ' . json_encode($_SESSION));

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    error_log('Admin check failed: is_admin=' . (isset($_SESSION['is_admin']) ? $_SESSION['is_admin'] : 'not set'));
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

// Also provide accounts for dropdowns
if (isset($_GET['action']) && $_GET['action'] === 'accounts') {
    $res = $mysqli->query("SELECT account_id, name FROM accounts ORDER BY name ASC");
    if (!$res) send_json(['success'=>false,'message'=>'Query error: ' . $mysqli->error]);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    send_json(['success'=>true,'data'=>$rows]);
}

// Get eligible guarantors (members who haven't been guarantors before, with available balance > 0)
if (isset($_GET['action']) && $_GET['action'] === 'eligible_guarantors') {
    // Get all members except those who have already been guarantors
    $query = "
        SELECT u.id, u.names,
               COALESCE(SUM(CASE WHEN t.type='withdrawal_deduction' THEN t.amount ELSE 0 END), 0) as total_deductions,
               COALESCE(SUM(CASE WHEN t.type IN ('contribution','loan_payment') THEN t.amount ELSE 0 END), 0) as total_contributions_and_payments,
               COALESCE((SELECT SUM(principal_amount) FROM loans WHERE borrower_user_id = u.id), 0) as total_loan_principal,
               (COALESCE(SUM(CASE WHEN t.type IN ('contribution','loan_payment') THEN t.amount ELSE 0 END), 0) - 
                COALESCE(SUM(CASE WHEN t.type='withdrawal_deduction' THEN t.amount ELSE 0 END), 0) - 
                COALESCE((SELECT SUM(principal_amount) FROM loans WHERE borrower_user_id = u.id), 0) - 120000) as available_guarantee
        FROM users u
        LEFT JOIN transactions t ON u.id = t.user_id
        WHERE u.is_member = 1 
        AND u.id NOT IN (SELECT DISTINCT guarantor_user_id FROM loan_guarantors)
        GROUP BY u.id, u.names
        HAVING available_guarantee > 0
        ORDER BY u.names ASC
    ";
    $res = $mysqli->query($query);
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
        
        $stmt = $mysqli->prepare("SELECT l.*, a.name as account_name, u.names as borrower_name, ap.names as approved_by_name, db.names as disbursed_by_name FROM loans l LEFT JOIN accounts a ON l.account_id = a.account_id LEFT JOIN users u ON l.borrower_user_id = u.id LEFT JOIN users ap ON l.approved_by = ap.id LEFT JOIN users db ON l.disbursed_by = db.id WHERE l.loan_id = ? LIMIT 1");
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
        error_log('Loan result: ' . json_encode($row));
        if ($row) {
            // Fetch guarantors for this loan
            $gua_stmt = $mysqli->prepare("SELECT lg.*, u.names as guarantor_name FROM loan_guarantors lg LEFT JOIN users u ON lg.guarantor_user_id = u.id WHERE lg.loan_id = ? ORDER BY lg.loan_guarantor_id");
            if ($gua_stmt) {
                $gua_stmt->bind_param('i', $id);
                if ($gua_stmt->execute()) {
                    $gua_res = $gua_stmt->get_result();
                    $guarantors = [];
                    while ($g = $gua_res->fetch_assoc()) {
                        $guarantors[] = $g;
                    }
                    $row['guarantors'] = $guarantors;
                }
            }
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
        $where = " WHERE u.names LIKE '%$esc%' OR l.reference_name LIKE '%$esc%'";
    }

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM loans l LEFT JOIN users u ON l.borrower_user_id = u.id $where");
    $totalRow = $totalRes->fetch_assoc();
    $total = (int)$totalRow['cnt'];

    $offset = ($page - 1) * $per_page;
    $sql = "SELECT l.loan_id, l.reference_name, l.principal_amount, l.monthly_rate, l.term_months, l.start_date, l.status, a.name as account_name, u.names as borrower_name, ap.names as approved_by_name, db.names as disbursed_by_name FROM loans l LEFT JOIN accounts a ON l.account_id = a.account_id LEFT JOIN users u ON l.borrower_user_id = u.id LEFT JOIN users ap ON l.approved_by = ap.id LEFT JOIN users db ON l.disbursed_by = db.id $where ORDER BY l.loan_id DESC LIMIT $offset, $per_page";
    $res = $mysqli->query($sql);
    if (!$res) send_json(['success'=>false,'message'=>'Query error: ' . $mysqli->error]);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    send_json(['success'=>true,'data'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$per_page]);
}

$action = $_POST['action'] ?? '';

// Function to save guarantors
function saveGuarantors($mysqli, $loan_id, $guarantors_json) {
    if (!$guarantors_json) return true;
    
    try {
        $guarantors = json_decode($guarantors_json, true);
        if (!is_array($guarantors)) return true;
        
        // Delete existing guarantors for this loan
        $del_stmt = $mysqli->prepare("DELETE FROM loan_guarantors WHERE loan_id = ?");
        if (!$del_stmt) { error_log('Delete prepare error: ' . $mysqli->error); return false; }
        $del_stmt->bind_param('i', $loan_id);
        if (!$del_stmt->execute()) { error_log('Delete execute error: ' . $del_stmt->error); return false; }
        
        // Insert new guarantors
        foreach ($guarantors as $g) {
            $guarantor_user_id = intval($g['user_id'] ?? 0);
            $guarantee_amount = floatval($g['amount'] ?? 0);
            
            if ($guarantor_user_id <= 0 || $guarantee_amount <= 0) continue;
            
            $ins_stmt = $mysqli->prepare("INSERT INTO loan_guarantors (loan_id, guarantor_user_id, guarantee_amount, status) VALUES (?, ?, ?, 'pending')");
            if (!$ins_stmt) { error_log('Insert prepare error: ' . $mysqli->error); continue; }
            $ins_stmt->bind_param('iid', $loan_id, $guarantor_user_id, $guarantee_amount);
            if (!$ins_stmt->execute()) { error_log('Insert execute error: ' . $ins_stmt->error); }
        }
        return true;
    } catch (Exception $e) {
        error_log('Guarantor save error: ' . $e->getMessage());
        return false;
    }
}

if ($action === 'create') {
    $account_id = intval($_POST['account_id'] ?? 0);
    $borrower = intval($_POST['borrower_user_id'] ?? 0);
    $principal = floatval($_POST['principal_amount'] ?? 0);
    $monthly_rate = floatval($_POST['monthly_rate'] ?? 0);
    $term = intval($_POST['term_months'] ?? 0);
    $start_date = trim($_POST['start_date'] ?? '');
    $status = trim($_POST['status'] ?? 'requested');
    $approved_by = !empty($_POST['approved_by']) ? intval($_POST['approved_by']) : null;
    $disbursed_by = !empty($_POST['disbursed_by']) ? intval($_POST['disbursed_by']) : null;
    $notes = trim($_POST['notes'] ?? null);

    if ($account_id <= 0 || $borrower <= 0 || $principal <= 0 || $term <= 0 || $start_date === '') {
        send_json(['success'=>false,'message'=>'Imirima yibanze irabuze cyangwa ifite agaciro kadasobanutse']);
    }

    // handle optional file upload
    $ref_name = null; $ref_mime = null; $ref_blob = null;
    if (!empty($_FILES['reference_file']) && is_uploaded_file($_FILES['reference_file']['tmp_name'])) {
        $ref_name = $_FILES['reference_file']['name'];
        $ref_mime = $_FILES['reference_file']['type'];
        $ref_blob = file_get_contents($_FILES['reference_file']['tmp_name']);
    }

    if ($ref_blob !== null) {
        $mysqli->begin_transaction();
        $sql = "INSERT INTO loans (account_id, borrower_user_id, principal_amount, monthly_rate, term_months, start_date, status, approved_by, disbursed_by, notes, reference_name, reference_mime, reference_file) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt2 = $mysqli->prepare($sql);
        if (!$stmt2) { $mysqli->rollback(); send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]); }
        $null = null;
        $stmt2->bind_param('iidddssiiisss', $account_id, $borrower, $principal, $monthly_rate, $term, $start_date, $status, $approved_by, $disbursed_by, $notes, $ref_name, $ref_mime, $null);
        if (!$stmt2->execute()) { $mysqli->rollback(); send_json(['success'=>false,'message'=>'Execute error: '.$mysqli->error]); }
        $id = $stmt2->insert_id;
        $stmt_up = $mysqli->prepare("UPDATE loans SET reference_file = ? WHERE loan_id = ?");
        if (!$stmt_up) { $mysqli->rollback(); send_json(['success'=>false,'message'=>'Prepare update error: '.$mysqli->error]); }
        $stmt_up->bind_param('bi', $null, $id);
        $stmt_up->send_long_data(0, $ref_blob);
        if (!$stmt_up->execute()) { $mysqli->rollback(); send_json(['success'=>false,'message'=>'Blob update error: '.$mysqli->error]); }
        
        // Save guarantors
        $guarantorsJson = $_POST['guarantors'] ?? '[]';
        if (!saveGuarantors($mysqli, $id, $guarantorsJson)) {
            $mysqli->rollback();
            send_json(['success'=>false,'message'=>'Error saving guarantors']);
        }
        
        $mysqli->commit();
        $res = $mysqli->query("SELECT l.loan_id, l.principal_amount, l.term_months, l.monthly_rate, l.start_date, l.status, a.name as account_name, u.names as borrower_name FROM loans l LEFT JOIN accounts a ON l.account_id = a.account_id LEFT JOIN users u ON l.borrower_user_id = u.id WHERE l.loan_id = " . (int)$id);
        $row = $res->fetch_assoc();
        // Notifications
        $msg = "Inguzanyo nshya yanditswe (#LN-$id): " . number_format((float)$principal) . " Frw";
        nig_notify_admins($mysqli, 'loan_requested', $msg);
        nig_create_notification($mysqli, (int)$borrower, 'loan_requested', $msg);
        send_json(['success'=>true,'data'=>$row]);
    } else {
        $stmtS = $mysqli->prepare("INSERT INTO loans (account_id, borrower_user_id, principal_amount, monthly_rate, term_months, start_date, status, approved_by, disbursed_by, notes, reference_name, reference_mime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmtS) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]);
        $stmtS->bind_param('iidddssiiiss', $account_id, $borrower, $principal, $monthly_rate, $term, $start_date, $status, $approved_by, $disbursed_by, $notes, $ref_name, $ref_mime);
        if ($stmtS->execute()) {
            $id = $stmtS->insert_id;
            
            // Save guarantors
            $guarantorsJson = $_POST['guarantors'] ?? '[]';
            if (!saveGuarantors($mysqli, $id, $guarantorsJson)) {
                send_json(['success'=>false,'message'=>'Error saving guarantors']);
            }
            
            $res = $mysqli->query("SELECT l.loan_id, l.principal_amount, l.term_months, l.monthly_rate, l.start_date, l.status, a.name as account_name, u.names as borrower_name FROM loans l LEFT JOIN accounts a ON l.account_id = a.account_id LEFT JOIN users u ON l.borrower_user_id = u.id WHERE l.loan_id = " . (int)$id);
            $row = $res->fetch_assoc();
            // Notifications
            $msg = "Inguzanyo nshya yanditswe (#LN-$id): " . number_format((float)$principal) . " Frw";
            nig_notify_admins($mysqli, 'loan_requested', $msg);
            nig_create_notification($mysqli, (int)$borrower, 'loan_requested', $msg);
            send_json(['success'=>true,'data'=>$row]);
        } else {
            send_json(['success'=>false,'message'=>$mysqli->error]);
        }
    }
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id']);

    // Detect status changes before updating
    $oldStatus   = null;
    $oldBorrower = null;
    $oldRes = $mysqli->query("SELECT status, borrower_user_id FROM loans WHERE loan_id = " . (int)$id . " LIMIT 1");
    if ($oldRes) {
        $oldRow      = $oldRes->fetch_assoc();
        $oldStatus   = $oldRow['status']           ?? null;
        $oldBorrower = isset($oldRow['borrower_user_id']) ? (int)$oldRow['borrower_user_id'] : null;
    }

    $account_id   = intval($_POST['account_id']       ?? 0);
    $borrower     = intval($_POST['borrower_user_id']  ?? 0);
    $principal    = floatval($_POST['principal_amount'] ?? 0);
    $monthly_rate = floatval($_POST['monthly_rate']    ?? 0);
    $term         = intval($_POST['term_months']       ?? 0);
    $start_date   = trim($_POST['start_date']          ?? '');
    $status       = trim($_POST['status']              ?? 'requested');
    $approved_by  = !empty($_POST['approved_by'])  ? intval($_POST['approved_by'])  : null;
    $disbursed_by = !empty($_POST['disbursed_by']) ? intval($_POST['disbursed_by']) : null;
    $notes        = trim($_POST['notes']               ?? '');

    if ($account_id <= 0 || $borrower <= 0 || $principal <= 0 || $term <= 0 || $start_date === '') {
        send_json(['success' => false, 'message' => 'Imirima yibanze irabuze cyangwa ifite agaciro kadasobanutse']);
    }

    $mysqli->begin_transaction();
    try {
        // 1. Update the loan record
        $stmt = $mysqli->prepare(
            "UPDATE loans
             SET account_id = ?, borrower_user_id = ?, principal_amount = ?,
                 monthly_rate = ?, term_months = ?, start_date = ?, status = ?,
                 approved_by = ?, disbursed_by = ?, notes = ?
             WHERE loan_id = ?"
        );
        if (!$stmt) throw new Exception('Prepare error: ' . $mysqli->error);
        $stmt->bind_param(
            'iidddssiiisi',
            $account_id, $borrower, $principal, $monthly_rate,
            $term, $start_date, $status, $approved_by,
            $disbursed_by, $notes, $id
        );
        if (!$stmt->execute()) throw new Exception('Update error: ' . $stmt->error);
        $stmt->close();

        // 2. Save guarantors (delete old + insert new inside transaction)
        $stmtG = $mysqli->prepare("DELETE FROM loan_guarantors WHERE loan_id = ?");
        if (!$stmtG) throw new Exception('Prepare guarantors delete error: ' . $mysqli->error);
        $stmtG->bind_param('i', $id);
        if (!$stmtG->execute()) throw new Exception('Delete guarantors error: ' . $stmtG->error);
        $stmtG->close();

        $guarantorsJson = $_POST['guarantors'] ?? '[]';
        $guarantors = json_decode($guarantorsJson, true);
        if (is_array($guarantors)) {
            foreach ($guarantors as $g) {
                $gUserId = intval($g['user_id'] ?? 0);
                $gAmount = floatval($g['amount'] ?? 0);
                if ($gUserId <= 0 || $gAmount <= 0) continue;
                $insG = $mysqli->prepare(
                    "INSERT INTO loan_guarantors (loan_id, guarantor_user_id, guarantee_amount, status)
                     VALUES (?, ?, ?, 'pending')"
                );
                if (!$insG) throw new Exception('Prepare guarantor insert error: ' . $mysqli->error);
                $insG->bind_param('iid', $id, $gUserId, $gAmount);
                if (!$insG->execute()) throw new Exception('Insert guarantor error: ' . $insG->error);
                $insG->close();
            }
        }

        $mysqli->commit();

        // 3. Fetch updated row to return
        $res = $mysqli->query(
            "SELECT l.loan_id, l.principal_amount, l.term_months, l.monthly_rate,
                    l.start_date, l.status, a.name AS account_name, u.names AS borrower_name
             FROM loans l
             LEFT JOIN accounts a ON l.account_id = a.account_id
             LEFT JOIN users u ON l.borrower_user_id = u.id
             WHERE l.loan_id = " . (int)$id
        );
        $row = $res ? $res->fetch_assoc() : null;

        // 4. Send notification if status changed
        if ($oldStatus !== null && $oldStatus !== $status) {
            $bId = $borrower > 0 ? (int)$borrower : (int)($oldBorrower ?? 0);
            $msg = "Status y'inguzanyo (#LN-$id) yahindutse: $oldStatus → $status";
            if ($bId > 0) nig_create_notification($mysqli, $bId, 'loan_status_changed', $msg);
            nig_notify_admins($mysqli, 'loan_status_changed', $msg);
        }

        send_json(['success' => true, 'data' => $row]);

    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success' => false, 'message' => 'Invalid id']);

    $mysqli->begin_transaction();
    try {
        // 1. Delete guarantors first (removes FK constraint)
        $stmtG = $mysqli->prepare("DELETE FROM loan_guarantors WHERE loan_id = ?");
        if (!$stmtG) throw new Exception('Prepare guarantors error: ' . $mysqli->error);
        $stmtG->bind_param('i', $id);
        if (!$stmtG->execute()) throw new Exception('Delete guarantors error: ' . $stmtG->error);
        $stmtG->close();

        // 2. Delete the loan itself
        $stmtL = $mysqli->prepare("DELETE FROM loans WHERE loan_id = ?");
        if (!$stmtL) throw new Exception('Prepare loan error: ' . $mysqli->error);
        $stmtL->bind_param('i', $id);
        if (!$stmtL->execute()) throw new Exception('Delete loan error: ' . $stmtL->error);
        $stmtL->close();

        $mysqli->commit();
        nig_notify_admins($mysqli, 'loan_status_changed', "Inguzanyo yasibwe (#LN-$id)");
        send_json(['success' => true]);
    } catch (Exception $e) {
        $mysqli->rollback();
        send_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

send_json(['success'=>false,'message'=>'Invalid request']);

?>
