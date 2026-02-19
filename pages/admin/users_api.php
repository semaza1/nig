<?php
// API for admin to manage users. Supports GET (list) and POST (create/update/delete)
header('Content-Type: application/json; charset=utf-8');
session_start();

$mysqli = require __DIR__ . '/../../config/db.php';

// simple admin check - requires login via pages/login.php which sets is_admin
if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // If id provided, return single user
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $mysqli->prepare("SELECT id, names, nid_passport, email, password, phone1, phone2, guarantee_name, guarantee_nid_passport, guarantee_email, guarantee_phone1, guarantee_phone2, is_member, is_admin FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            if ($row) {
                $row['is_member'] = (int)$row['is_member'];
                $row['is_admin'] = (int)$row['is_admin'];
                echo json_encode(['success' => true, 'data' => $row]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Not found']);
            }
            exit;
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 10));
    $q = trim($_GET['q'] ?? '');

    $where = '';
    if ($q !== '') {
        $esc = $mysqli->real_escape_string($q);
        $where = " WHERE names LIKE '%$esc%' OR email LIKE '%$esc%' OR phone1 LIKE '%$esc%' OR phone2 LIKE '%$esc%' OR nid_passport LIKE '%$esc%'";
    }

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM users $where");
    $totalRow = $totalRes->fetch_assoc();
    $total = (int)$totalRow['cnt'];

    $offset = ($page - 1) * $per_page;
    $res = $mysqli->query("SELECT id, names, nid_passport, email, password, phone1, phone2, guarantee_name, guarantee_nid_passport, guarantee_email, guarantee_phone1, guarantee_phone2, is_member, is_admin FROM users $where ORDER BY id DESC LIMIT $offset, $per_page");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $r['is_member'] = (int)$r['is_member'];
        $r['is_admin'] = (int)$r['is_admin'];
        $rows[] = $r;
    }
    echo json_encode(['success' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page]);
    exit;
}

// POST actions
$action = $_POST['action'] ?? '';
if ($action === 'create') {
    $names = trim($_POST['names'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone1 = trim($_POST['phone1'] ?? '');
    $phone2 = trim($_POST['phone2'] ?? '');
    $nid = trim($_POST['nid_passport'] ?? '');
    $password = $_POST['password'] ?? '';
    $guarantee_name = trim($_POST['guarantee_name'] ?? '');
    $guarantee_nid = trim($_POST['guarantee_nid_passport'] ?? '');
    $guarantee_email = trim($_POST['guarantee_email'] ?? '');
    $guarantee_phone1 = trim($_POST['guarantee_phone1'] ?? '');
    $guarantee_phone2 = trim($_POST['guarantee_phone2'] ?? '');
    $is_member = !empty($_POST['is_member']) ? 1 : 0;
    $is_admin = !empty($_POST['is_admin']) ? 1 : 0;

    if ($names === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Amazina na ijambo ry\'ibanga ariyongerwa']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("INSERT INTO users (names, nid_passport, email, password, phone1, phone2, guarantee_name, guarantee_nid_passport, guarantee_email, guarantee_phone1, guarantee_phone2, is_member, is_admin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sssssssssssii', $names, $nid, $email, $hash, $phone1, $phone2, $guarantee_name, $guarantee_nid, $guarantee_email, $guarantee_phone1, $guarantee_phone2, $is_member, $is_admin);
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $stmt->close();
        $res = $mysqli->query("SELECT id, names, nid_passport, email, password, phone1, phone2, guarantee_name, guarantee_nid_passport, guarantee_email, guarantee_phone1, guarantee_phone2, is_member, is_admin FROM users WHERE id = " . (int)$id);
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
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid id']);
        exit;
    }
    $names = trim($_POST['names'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone1 = trim($_POST['phone1'] ?? '');
    $phone2 = trim($_POST['phone2'] ?? '');
    $nid = trim($_POST['nid_passport'] ?? '');
    $password = $_POST['password'] ?? null;
    $guarantee_name = trim($_POST['guarantee_name'] ?? '');
    $guarantee_nid = trim($_POST['guarantee_nid_passport'] ?? '');
    $guarantee_email = trim($_POST['guarantee_email'] ?? '');
    $guarantee_phone1 = trim($_POST['guarantee_phone1'] ?? '');
    $guarantee_phone2 = trim($_POST['guarantee_phone2'] ?? '');
    $is_member = !empty($_POST['is_member']) ? 1 : 0;
    $is_admin = !empty($_POST['is_admin']) ? 1 : 0;

    if ($password !== null && $password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("UPDATE users SET names = ?, nid_passport = ?, email = ?, password = ?, phone1 = ?, phone2 = ?, guarantee_name = ?, guarantee_nid_passport = ?, guarantee_email = ?, guarantee_phone1 = ?, guarantee_phone2 = ?, is_member = ?, is_admin = ? WHERE id = ?");
        $stmt->bind_param('sssssssssssiii', $names, $nid, $email, $hash, $phone1, $phone2, $guarantee_name, $guarantee_nid, $guarantee_email, $guarantee_phone1, $guarantee_phone2, $is_member, $is_admin, $id);
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET names = ?, nid_passport = ?, email = ?, phone1 = ?, phone2 = ?, guarantee_name = ?, guarantee_nid_passport = ?, guarantee_email = ?, guarantee_phone1 = ?, guarantee_phone2 = ?, is_member = ?, is_admin = ? WHERE id = ?");
        $stmt->bind_param('ssssssssssiii', $names, $nid, $email, $phone1, $phone2, $guarantee_name, $guarantee_nid, $guarantee_email, $guarantee_phone1, $guarantee_phone2, $is_member, $is_admin, $id);
    }
    if ($stmt->execute()) {
        $stmt->close();
        $res = $mysqli->query("SELECT id, names, nid_passport, email, password, phone1, phone2, guarantee_name, guarantee_nid_passport, guarantee_email, guarantee_phone1, guarantee_phone2, is_member, is_admin FROM users WHERE id = " . (int)$id);
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
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid id']);
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
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
