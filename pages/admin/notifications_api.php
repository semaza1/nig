<?php
// Admin Notifications API (CRUD + search + filters + pagination)
header('Content-Type: application/json; charset=utf-8');
session_start();

$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database connection failed']);
    exit;
}

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access denied']);
    exit;
}

function send_json($arr){
    echo json_encode($arr);
    exit;
}

// Small endpoint for dropdowns
if (isset($_GET['action']) && $_GET['action'] === 'users') {
    $res = $mysqli->query("SELECT id, names FROM users ORDER BY names ASC");
    if (!$res) send_json(['success'=>false,'message'=>'Query error: '.$mysqli->error]);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    send_json(['success'=>true,'data'=>$rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $mysqli->prepare("SELECT n.*, u.names AS user_name FROM notifications n LEFT JOIN users u ON u.id = n.user_id WHERE n.notification_id = ? LIMIT 1");
        if (!$stmt) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]);
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) send_json(['success'=>false,'message'=>'Execute error: '.$stmt->error]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) send_json(['success'=>true,'data'=>$row]);
        send_json(['success'=>false,'message'=>'Not found']);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, min(200, (int)($_GET['per_page'] ?? 10)));
    $q = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $channel = trim($_GET['channel'] ?? '');
    $type = trim($_GET['type'] ?? '');

    $where = [];
    if ($q !== '') {
        $esc = $mysqli->real_escape_string($q);
        $where[] = "(n.message LIKE '%$esc%' OR u.names LIKE '%$esc%' OR n.type LIKE '%$esc%')";
    }
    if ($status !== '') {
        $esc = $mysqli->real_escape_string($status);
        $where[] = "n.status = '$esc'";
    }
    if ($channel !== '') {
        $esc = $mysqli->real_escape_string($channel);
        $where[] = "n.channel = '$esc'";
    }
    if ($type !== '') {
        $esc = $mysqli->real_escape_string($type);
        $where[] = "n.type = '$esc'";
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM notifications n LEFT JOIN users u ON u.id = n.user_id $whereSql");
    if (!$totalRes) send_json(['success'=>false,'message'=>'Count error: '.$mysqli->error]);
    $total = (int)($totalRes->fetch_assoc()['cnt'] ?? 0);

    $offset = ($page - 1) * $per_page;
    $sql = "SELECT n.notification_id, n.user_id, u.names AS user_name, n.type, n.channel, n.status, n.scheduled_for, n.sent_at, n.created_at, n.message
            FROM notifications n
            LEFT JOIN users u ON u.id = n.user_id
            $whereSql
            ORDER BY n.notification_id DESC
            LIMIT $offset, $per_page";
    $res = $mysqli->query($sql);
    if (!$res) send_json(['success'=>false,'message'=>'Query error: '.$mysqli->error]);
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;

    send_json(['success'=>true,'data'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$per_page]);
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $type = trim($_POST['type'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $channel = trim($_POST['channel'] ?? 'in_app');
    $status = trim($_POST['status'] ?? 'queued');
    $scheduled_for = trim($_POST['scheduled_for'] ?? '');

    if ($user_id <= 0 || $type === '' || $message === '') {
        send_json(['success'=>false,'message'=>'Imirima yibanze irabuze']);
    }

    $sched = null;
    if ($scheduled_for !== '') {
        $ts = strtotime($scheduled_for);
        if ($ts !== false) $sched = date('Y-m-d H:i:s', $ts);
    }

    $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, type, message, channel, status, scheduled_for) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]);
    $stmt->bind_param('isssss', $user_id, $type, $message, $channel, $status, $sched);
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $stmt->close();
        $res = $mysqli->query("SELECT n.*, u.names AS user_name FROM notifications n LEFT JOIN users u ON u.id = n.user_id WHERE n.notification_id = " . (int)$id);
        $row = $res ? $res->fetch_assoc() : null;
        send_json(['success'=>true,'data'=>$row]);
    }
    send_json(['success'=>false,'message'=>$stmt->error ?: $mysqli->error]);
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $user_id = (int)($_POST['user_id'] ?? 0);
    $type = trim($_POST['type'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $channel = trim($_POST['channel'] ?? 'in_app');
    $status = trim($_POST['status'] ?? 'queued');
    $scheduled_for = trim($_POST['scheduled_for'] ?? '');

    if ($id <= 0) send_json(['success'=>false,'message'=>'Invalid id']);
    if ($user_id <= 0 || $type === '' || $message === '') send_json(['success'=>false,'message'=>'Imirima yibanze irabuze']);

    $sched = null;
    if ($scheduled_for !== '') {
        $ts = strtotime($scheduled_for);
        if ($ts !== false) $sched = date('Y-m-d H:i:s', $ts);
    }

    // auto-set sent_at when status becomes sent (if not already set)
    $current = null;
    $curRes = $mysqli->query("SELECT sent_at FROM notifications WHERE notification_id = " . (int)$id . " LIMIT 1");
    if ($curRes) $current = $curRes->fetch_assoc();
    if (!$current) send_json(['success'=>false,'message'=>'Not found']);
    $sent_at = $current['sent_at'] ?? null;
    if ($status === 'sent' && empty($sent_at)) {
        $sent_at = date('Y-m-d H:i:s');
    }

    $stmt = $mysqli->prepare("UPDATE notifications SET user_id=?, type=?, message=?, channel=?, status=?, scheduled_for=?, sent_at=? WHERE notification_id=?");
    if (!$stmt) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]);
    $stmt->bind_param('issssssi', $user_id, $type, $message, $channel, $status, $sched, $sent_at, $id);
    if ($stmt->execute()) {
        $stmt->close();
        $res = $mysqli->query("SELECT n.*, u.names AS user_name FROM notifications n LEFT JOIN users u ON u.id = n.user_id WHERE n.notification_id = " . (int)$id);
        $row = $res ? $res->fetch_assoc() : null;
        send_json(['success'=>true,'data'=>$row]);
    }
    send_json(['success'=>false,'message'=>$stmt->error ?: $mysqli->error]);
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) send_json(['success'=>false,'message'=>'Invalid id']);
    $stmt = $mysqli->prepare("DELETE FROM notifications WHERE notification_id = ?");
    if (!$stmt) send_json(['success'=>false,'message'=>'Prepare error: '.$mysqli->error]);
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) send_json(['success'=>true]);
    send_json(['success'=>false,'message'=>$stmt->error ?: $mysqli->error]);
}

send_json(['success'=>false,'message'=>'Invalid request']);

?>
