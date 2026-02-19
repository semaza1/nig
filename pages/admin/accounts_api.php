<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

$mysqli = require __DIR__ . '/../../config/db.php';

if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $mysqli->prepare("SELECT account_id, name, type, account_number, created_at FROM accounts WHERE account_id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        if ($row) {
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Not found']);
        }
        exit;
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 50));
    $q = trim($_GET['q'] ?? '');

    $where = '';
    if ($q !== '') {
        $esc = $mysqli->real_escape_string($q);
        $where = " WHERE name LIKE '%$esc%' OR account_number LIKE '%$esc%'";
    }

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM accounts $where");
    $totalRow = $totalRes->fetch_assoc();
    $total = (int)$totalRow['cnt'];

    $offset = ($page - 1) * $per_page;
    $res = $mysqli->query("SELECT account_id, name, type, account_number, created_at FROM accounts $where ORDER BY account_id DESC LIMIT $offset, $per_page");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    echo json_encode(['success' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page]);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $account_number = trim($_POST['account_number'] ?? null);

    if ($name === '' || $type === '') {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO accounts (name, type, account_number) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $name, $type, $account_number);
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $res = $mysqli->query("SELECT account_id, name, type, account_number, created_at FROM accounts WHERE account_id = " . (int)$id);
        $row = $res->fetch_assoc();
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
        exit;
    }
}

if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit; }
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $account_number = trim($_POST['account_number'] ?? null);

    if ($name === '' || $type === '') {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE accounts SET name = ?, type = ?, account_number = ? WHERE account_id = ?");
    $stmt->bind_param('sssi', $name, $type, $account_number, $id);
    if ($stmt->execute()) {
        $res = $mysqli->query("SELECT account_id, name, type, account_number, created_at FROM accounts WHERE account_id = " . (int)$id);
        $row = $res->fetch_assoc();
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
        exit;
    }
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid id']); exit; }
    $stmt = $mysqli->prepare("DELETE FROM accounts WHERE account_id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
exit;
?>
