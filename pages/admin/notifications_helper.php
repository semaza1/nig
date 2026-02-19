<?php
// Lightweight helper to create notifications from other admin APIs.
// Uses mysqli connection ($mysqli) and is_admin flag from users table.

function nig_create_notification(mysqli $mysqli, int $user_id, string $type, string $message, string $channel = 'in_app', string $status = 'queued', ?string $scheduled_for = null): bool {
    if ($user_id <= 0) return false;
    if ($type === '' || $message === '') return false;

    $scheduled = null;
    if ($scheduled_for !== null && trim($scheduled_for) !== '') {
        $ts = strtotime($scheduled_for);
        if ($ts !== false) {
            $scheduled = date('Y-m-d H:i:s', $ts);
        }
    }

    $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, type, message, channel, status, scheduled_for) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param('isssss', $user_id, $type, $message, $channel, $status, $scheduled);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function nig_notify_admins(mysqli $mysqli, string $type, string $message, string $channel = 'in_app', string $status = 'queued'): void {
    $res = $mysqli->query("SELECT id FROM users WHERE is_admin = 1");
    if (!$res) return;
    while ($r = $res->fetch_assoc()) {
        $aid = (int)($r['id'] ?? 0);
        if ($aid > 0) {
            nig_create_notification($mysqli, $aid, $type, $message, $channel, $status);
        }
    }
}

?>
