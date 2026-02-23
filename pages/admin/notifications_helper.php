<?php
function create_notification($mysqli, int $user_id, string $type, string $message, string $channel='in_app', string $status='queued', $scheduled_for=null){
  $scheduled = null;
  if (!empty($scheduled_for)) {
    $scheduled = date('Y-m-d H:i:s', strtotime(str_replace('T',' ', $scheduled_for)));
  }

  $sql = "INSERT INTO notifications (user_id, type, message, channel, status, scheduled_for, sent_at)
          VALUES (?, ?, ?, ?, ?, ?, NULL)";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param("isssss", $user_id, $type, $message, $channel, $status, $scheduled);
  $stmt->execute();
}

function notify_admins($mysqli, string $type, string $message, string $channel='in_app'){
  $res = $mysqli->query("SELECT id FROM users WHERE is_admin = 1");
  while($row = $res->fetch_assoc()){
    create_notification($mysqli, (int)$row['id'], $type, $message, $channel);
  }
}
?>