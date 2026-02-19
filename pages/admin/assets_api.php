<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

$mysqli = require __DIR__ . '/../../config/db.php';

if (empty($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$admin_id = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $mysqli->prepare("SELECT asset_id, name, purchase_date, purchase_value, location, notes, created_by, created_at, certificate_name, certificate_mime, IF(certificate_file IS NOT NULL, HEX(certificate_file), NULL) as certificate_file, sold_value, sold_date FROM assets WHERE asset_id = ? LIMIT 1");
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
        $where = " WHERE name LIKE '%$esc%' OR location LIKE '%$esc%'";
    }

    $totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM assets $where");
    $totalRow = $totalRes->fetch_assoc();
    $total = (int)$totalRow['cnt'];

    $offset = ($page - 1) * $per_page;
    $res = $mysqli->query("SELECT asset_id, name, purchase_date, purchase_value, location, notes, created_by, created_at, certificate_name, certificate_mime, IF(certificate_file IS NOT NULL, HEX(certificate_file), NULL) as certificate_file, sold_value, sold_date FROM assets $where ORDER BY asset_id DESC LIMIT $offset, $per_page");
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
    $purchase_date = trim($_POST['purchase_date'] ?? '');
    $purchase_value = floatval($_POST['purchase_value'] ?? 0);
    $location = trim($_POST['location'] ?? null);
    $notes = trim($_POST['notes'] ?? null);
    $certificate_name = trim($_POST['certificate_name'] ?? null);
    $sold_value = !empty($_POST['sold_value']) ? floatval($_POST['sold_value']) : null;
    $sold_date = !empty($_POST['sold_date']) ? trim($_POST['sold_date']) : null;
    $certificate_file = null;
    $certificate_mime = null;

    if ($name === '' || $purchase_date === '' || $purchase_value <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing or invalid required fields']);
        exit;
    }

    // Handle certificate file upload
    if (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['certificate_file'];
        $certificate_file = file_get_contents($file['tmp_name']);
        $certificate_mime = $file['type'];
    }

    $stmt = $mysqli->prepare("INSERT INTO assets (name, purchase_date, purchase_value, location, notes, certificate_file, certificate_name, certificate_mime, sold_value, sold_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssdsssbsssi', $name, $purchase_date, $purchase_value, $location, $notes, $certificate_file, $certificate_name, $certificate_mime, $sold_value, $sold_date, $admin_id);
    
    if ($certificate_file) {
        $stmt->send_long_data(5, $certificate_file);
    }
    
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $res = $mysqli->query("SELECT asset_id, name, purchase_date, purchase_value, location, notes, created_by, created_at, certificate_name, certificate_mime, HEX(certificate_file) as certificate_file, sold_value, sold_date FROM assets WHERE asset_id = " . (int)$id);
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
    $name = trim($_POST['name'] ?? '');
    $purchase_date = trim($_POST['purchase_date'] ?? '');
    $purchase_value = floatval($_POST['purchase_value'] ?? 0);
    $location = trim($_POST['location'] ?? null);
    $notes = trim($_POST['notes'] ?? null);
    $certificate_name = trim($_POST['certificate_name'] ?? null);
    $sold_value = !empty($_POST['sold_value']) ? floatval($_POST['sold_value']) : null;
    $sold_date = !empty($_POST['sold_date']) ? trim($_POST['sold_date']) : null;
    $certificate_file = null;
    $certificate_mime = null;

    if ($name === '' || $purchase_date === '' || $purchase_value <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing or invalid required fields']);
        exit;
    }

    // Handle certificate file upload
    if (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['certificate_file'];
        $certificate_file = file_get_contents($file['tmp_name']);
        $certificate_mime = $file['type'];
    }

    if ($certificate_file) {
        $stmt = $mysqli->prepare("UPDATE assets SET name = ?, purchase_date = ?, purchase_value = ?, location = ?, notes = ?, certificate_file = ?, certificate_name = ?, certificate_mime = ?, sold_value = ?, sold_date = ? WHERE asset_id = ?");
        $stmt->bind_param('ssdsssbssdi', $name, $purchase_date, $purchase_value, $location, $notes, $certificate_file, $certificate_name, $certificate_mime, $sold_value, $sold_date, $id);
        $stmt->send_long_data(5, $certificate_file);
    } else {
        $stmt = $mysqli->prepare("UPDATE assets SET name = ?, purchase_date = ?, purchase_value = ?, location = ?, notes = ?, certificate_name = ?, sold_value = ?, sold_date = ? WHERE asset_id = ?");
        $stmt->bind_param('ssdssssdi', $name, $purchase_date, $purchase_value, $location, $notes, $certificate_name, $sold_value, $sold_date, $id);
    }
    if ($stmt->execute()) {
        $res = $mysqli->query("SELECT asset_id, name, purchase_date, purchase_value, location, notes, created_by, created_at, certificate_name, certificate_mime, HEX(certificate_file) as certificate_file, sold_value, sold_date FROM assets WHERE asset_id = " . (int)$id);
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
    $stmt = $mysqli->prepare("DELETE FROM assets WHERE asset_id = ?");
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
