<?php
// returns basic session info (names, email, is_admin)
header('Content-Type: application/json; charset=utf-8');
session_start();

if (isset($_SESSION['user_id'])) {
    echo json_encode(['success' => true, 'data' => [
        'id' => $_SESSION['user_id'],
        'names' => $_SESSION['names'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'is_member' => isset($_SESSION['is_member']) ? (int)$_SESSION['is_member'] : 0,
        'is_admin' => isset($_SESSION['is_admin']) ? (int)$_SESSION['is_admin'] : 0,
    ]]);
    exit;
}

echo json_encode(['success' => false]);
exit;
?>