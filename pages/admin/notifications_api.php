<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

$mysqli = require __DIR__ . '/../../config/db.php';

if (!$mysqli) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'DB connection failed']);
  exit;
}

// admin check (matches your other APIs)
if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Access denied']);
  exit;
}

function out($success, $data=null, $message='') {
  echo json_encode(['success'=>$success,'data'=>$data,'message'=>$message]);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  // single item
  if (!empty($_GET['id'])) {
    $id = (int)$_GET['id'];

    $sql = "SELECT n.*, u.names AS user_name
            FROM notifications n
            LEFT JOIN users u ON u.id = n.user_id
            WHERE n.notification_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    if (!$row) out(false, null, "Notification not found");
    out(true, $row, "");
  }

  // list
  $page = max(1, (int)($_GET['page'] ?? 1));
  $per_page = max(1, min(200, (int)($_GET['per_page'] ?? 10)));
  $q = trim($_GET['q'] ?? '');
  $status = trim($_GET['status'] ?? '');
  $channel = trim($_GET['channel'] ?? '');

  $where = [];
  $types = "";
  $params = [];

  if ($q !== '') {
    $where[] = "(n.message LIKE ? OR n.type LIKE ? OR u.names LIKE ?)";
    $like = "%{$q}%";
    $types .= "sss";
    $params[] = $like; $params[] = $like; $params[] = $like;
  }
  if ($status !== '') {
    $where[] = "n.status = ?";
    $types .= "s";
    $params[] = $status;
  }
  if ($channel !== '') {
    $where[] = "n.channel = ?";
    $types .= "s";
    $params[] = $channel;
  }

  $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";
  $offset = ($page - 1) * $per_page;

  // count
  $countSql = "SELECT COUNT(*) AS total
               FROM notifications n
               LEFT JOIN users u ON u.id = n.user_id
               $whereSql";
  $countStmt = $mysqli->prepare($countSql);
  if ($types !== "") $countStmt->bind_param($types, ...$params);
  $countStmt->execute();
  $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

  // rows
  $listSql = "SELECT n.*, u.names AS user_name
              FROM notifications n
              LEFT JOIN users u ON u.id = n.user_id
              $whereSql
              ORDER BY n.notification_id DESC
              LIMIT ? OFFSET ?";
  $listStmt = $mysqli->prepare($listSql);

  // add limit/offset to params
  $types2 = $types . "ii";
  $params2 = $params;
  $params2[] = $per_page;
  $params2[] = $offset;

  $listStmt->bind_param($types2, ...$params2);
  $listStmt->execute();
  $rows = [];
  $r = $listStmt->get_result();
  while ($row = $r->fetch_assoc()) $rows[] = $row;

  out(true, ['rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$per_page], "");
}

if ($method === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $type = trim($_POST['type'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $channel = trim($_POST['channel'] ?? 'in_app');
    $status = trim($_POST['status'] ?? 'queued');
    $scheduled_for = trim($_POST['scheduled_for'] ?? '');

    if ($user_id <= 0 || $type === '' || $message === '') out(false, null, "Missing required fields");

    $scheduled = null;
    if ($scheduled_for !== '') {
      // accept datetime-local or normal
      $scheduled = date('Y-m-d H:i:s', strtotime(str_replace('T',' ', $scheduled_for)));
    }

    $sql = "INSERT INTO notifications (user_id, type, message, channel, status, scheduled_for, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, NULL)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("isssss", $user_id, $type, $message, $channel, $status, $scheduled);
    $stmt->execute();

    out(true, ['id'=>$mysqli->insert_id], "Created");
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $user_id = (int)($_POST['user_id'] ?? 0);
    $type = trim($_POST['type'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $channel = trim($_POST['channel'] ?? 'in_app');
    $status = trim($_POST['status'] ?? 'queued');
    $scheduled_for = trim($_POST['scheduled_for'] ?? '');

    if ($id <= 0) out(false, null, "Missing id");
    if ($user_id <= 0 || $type === '' || $message === '') out(false, null, "Missing required fields");

    $scheduled = null;
    if ($scheduled_for !== '') {
      $scheduled = date('Y-m-d H:i:s', strtotime(str_replace('T',' ', $scheduled_for)));
    }

    // set sent_at automatically if status becomes sent and sent_at is null
    $currentSent = null;
    $chk = $mysqli->prepare("SELECT sent_at FROM notifications WHERE notification_id = ?");
    $chk->bind_param("i", $id);
    $chk->execute();
    $cur = $chk->get_result()->fetch_assoc();
    if (!$cur) out(false, null, "Notification not found");
    $currentSent = $cur['sent_at'];

    $sent_at = $currentSent;
    if ($status === 'sent' && empty($currentSent)) {
      $sent_at = date('Y-m-d H:i:s');
    }

    $sql = "UPDATE notifications
            SET user_id=?, type=?, message=?, channel=?, status=?, scheduled_for=?, sent_at=?
            WHERE notification_id=?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("issssssi", $user_id, $type, $message, $channel, $status, $scheduled, $sent_at, $id);
    $stmt->execute();

    out(true, null, "Updated");
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) out(false, null, "Missing id");

    $stmt = $mysqli->prepare("DELETE FROM notifications WHERE notification_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    out(true, null, "Deleted");
  }

  out(false, null, "Unknown action");
}

out(false, null, "Unsupported method");
?>