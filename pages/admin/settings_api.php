<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
session_start();
header('Content-Type: application/json; charset=utf-8');

// ── Auth ─────────────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$adminId = (int) $_SESSION['user_id'];

// ── DB ───────────────────────────────────────────────────────────
$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// ── Canonical settings
// Actual table columns: setting_id, setting_type, setting_value, updated_by, updated_at
// "setting_type" is the key column (varchar, e.g. "member_interest_rate")
// ────────────────────────────────────────────────────────────────
$DEFAULTS = [
    // rates
    'member_interest_rate'        => ['label' => '% Inyungu ku Munyamuryango',             'type' => 'number',  'group' => 'rates',         'default' => '18'],
    'non_member_interest_rate'    => ['label' => '% Inyungu ku Utari Umunyamuryango',      'type' => 'number',  'group' => 'rates',         'default' => '24'],
    'share_withdrawal_rate'       => ['label' => '% yo Kuvana Imigabane',                  'type' => 'number',  'group' => 'rates',         'default' => '5'],
    'default_loan_term_months'    => ['label' => 'Loan Term (amezi)',                       'type' => 'number',  'group' => 'rates',         'default' => '12'],
    'loan_calculation_method'     => ['label' => 'Method yo Kubara Inguzanyo',             'type' => 'text',    'group' => 'rates',         'default' => 'Reducing Balance'],
    // org
    'org_name'                    => ['label' => "Izina ry'Ikimina",                       'type' => 'text',    'group' => 'org',           'default' => 'NIG Ikimina'],
    'org_email'                   => ['label' => "Email y'Ikimina",                        'type' => 'email',   'group' => 'org',           'default' => ''],
    'org_phone'                   => ['label' => "Telefoni y'Ikimina",                     'type' => 'text',    'group' => 'org',           'default' => ''],
    'org_address'                 => ['label' => "Aderesi y'Ikimina",                      'type' => 'text',    'group' => 'org',           'default' => ''],
    'fiscal_year_start_month'     => ['label' => "Ukwezi gutangira umwaka w'imari (1-12)", 'type' => 'number',  'group' => 'org',           'default' => '1'],
    // contributions
    'monthly_contribution_amount' => ['label' => 'Imigabane buri kwezi (Frw)',             'type' => 'number',  'group' => 'contributions', 'default' => '10000'],
    'meeting_day'                 => ['label' => "Umunsi w'Inama (1=Mbere…7=Cyumweru)",    'type' => 'number',  'group' => 'contributions', 'default' => '6'],
    'late_payment_penalty_rate'   => ['label' => '% Ihazabu ku Gutinda Kwishyura',         'type' => 'number',  'group' => 'contributions', 'default' => '2'],
    // notifications
    'loan_due_reminder_days'      => ['label' => 'Iminsi mbere yo Kuburira Inguzanyo',     'type' => 'number',  'group' => 'notifications', 'default' => '7'],
    'enable_sms_notifications'    => ['label' => 'Gushyira Interahamwa SMS',               'type' => 'boolean', 'group' => 'notifications', 'default' => '0'],
    'enable_email_notifications'  => ['label' => 'Gushyira Interahamwa Email',             'type' => 'boolean', 'group' => 'notifications', 'default' => '0'],
    'sms_sender_id'               => ['label' => 'SMS Sender ID',                          'type' => 'text',    'group' => 'notifications', 'default' => 'NIG'],
];

// ── Seed missing canonical keys ──────────────────────────────────
// Uses setting_type as the unique key (matches real schema)
function seedDefaults(mysqli $db, array $defaults, int $adminId): void {
    $stmt = $db->prepare(
        "INSERT IGNORE INTO settings (setting_type, setting_value, updated_by)
         VALUES (?, ?, ?)"
    );
    foreach ($defaults as $key => $meta) {
        $v = $meta['default'];
        $stmt->bind_param('ssi', $key, $v, $adminId);
        $stmt->execute();
    }
    $stmt->close();
}

// ── Enrich a DB row with metadata ────────────────────────────────
function enrichRow(array $row, array $defaults): array {
    $k = $row['setting_type'];
    $row['label']          = $defaults[$k]['label']   ?? $k;
    $row['type']           = $defaults[$k]['type']    ?? 'text';
    $row['group']          = $defaults[$k]['group']   ?? 'custom';
    $row['default']        = $defaults[$k]['default'] ?? '';
    $row['readonly']       = ($k === 'loan_calculation_method');
    $row['is_default_key'] = isset($defaults[$k]);
    // alias for JS compatibility
    $row['setting_key']    = $k;
    return $row;
}

function clean(string $v): string { return trim($v); }

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════ GET ════════════════════════════════════
if ($method === 'GET') {
    seedDefaults($mysqli, $DEFAULTS, $adminId);

    // Single row by type/key
    if (!empty($_GET['key'])) {
        $key  = clean($_GET['key']);
        $stmt = $mysqli->prepare("SELECT * FROM settings WHERE setting_type = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) $row = enrichRow($row, $DEFAULTS);
        echo json_encode(['success' => (bool)$row, 'data' => $row ?: null]);
        exit;
    }

    // All rows
    $result  = $mysqli->query("SELECT * FROM settings ORDER BY setting_type");
    $flat    = [];
    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $row = enrichRow($row, $DEFAULTS);
        $flat[] = $row;
        $grouped[$row['group']][] = $row;
    }
    echo json_encode(['success' => true, 'data' => $grouped, 'flat' => $flat]);
    exit;
}

// ════════════════════════ POST ═══════════════════════════════════
if ($method === 'POST') {
    $action = clean($_POST['action'] ?? '');

    // ── CREATE custom key ────────────────────────────────────────
    if ($action === 'create') {
        $key   = strtolower(clean($_POST['setting_key'] ?? ''));
        $value = clean($_POST['setting_value'] ?? '');

        if (!$key) { echo json_encode(['success' => false, 'message' => 'Key irakenewe']); exit; }
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            echo json_encode(['success' => false, 'message' => "Key: inyuguti z'i minsi gusa (a-z 0-9 _)"]);
            exit;
        }
        if (isset($DEFAULTS[$key])) {
            echo json_encode(['success' => false, 'message' => 'Iyi key ni ya default – koresha Update']);
            exit;
        }
        // Check duplicate
        $chk = $mysqli->prepare("SELECT setting_id FROM settings WHERE setting_type = ?");
        $chk->bind_param('s', $key);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            echo json_encode(['success' => false, 'message' => 'Iyi key isanzwe iri mu makuru']);
            exit;
        }
        $chk->close();

        $stmt = $mysqli->prepare(
            "INSERT INTO settings (setting_type, setting_value, updated_by) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('ssi', $key, $value, $adminId);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Setting nshya yongewe', 'key' => $key]);
        exit;
    }

    // ── UPDATE single ────────────────────────────────────────────
    if ($action === 'update') {
        $key   = clean($_POST['setting_key']   ?? '');
        $value = clean($_POST['setting_value'] ?? '');

        if (!$key) { echo json_encode(['success' => false, 'message' => 'Key irakenewe']); exit; }
        if ($key === 'loan_calculation_method') {
            echo json_encode(['success' => false, 'message' => 'Iyi setting ntishobora guhindurwa']);
            exit;
        }
        if (isset($DEFAULTS[$key])) {
            $type = $DEFAULTS[$key]['type'];
            if ($type === 'number' && !is_numeric($value)) {
                echo json_encode(['success' => false, 'message' => 'Value igomba kuba numero']);
                exit;
            }
            if ($type === 'boolean') {
                $value = ($value === '1' || strtolower($value) === 'true') ? '1' : '0';
            }
        }

        $stmt = $mysqli->prepare(
            "INSERT INTO settings (setting_type, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
        );
        $stmt->bind_param('ssi', $key, $value, $adminId);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Byahinduwe']);
        exit;
    }

    // ── UPDATE GROUP ─────────────────────────────────────────────
    if ($action === 'update_group') {
        $group = clean($_POST['group'] ?? '');
        if (!$group) { echo json_encode(['success' => false, 'message' => 'Group irakenewe']); exit; }

        $stmt = $mysqli->prepare(
            "INSERT INTO settings (setting_type, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
        );
        $saved = 0;
        foreach ($DEFAULTS as $k => $m) {
            if ($m['group'] !== $group)           continue;
            if (!isset($_POST[$k]))               continue;
            if ($k === 'loan_calculation_method') continue;

            $val = clean($_POST[$k]);
            if ($m['type'] === 'boolean') $val = ($val === '1') ? '1' : '0';
            if ($m['type'] === 'number' && !is_numeric($val)) continue;

            $stmt->bind_param('ssi', $k, $val, $adminId);
            $stmt->execute();
            $saved++;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'message' => "Setting {$saved} zabitswe"]);
        exit;
    }

    // ── DELETE (custom keys only) ────────────────────────────────
    if ($action === 'delete') {
        $key = clean($_POST['setting_key'] ?? '');
        if (!$key) { echo json_encode(['success' => false, 'message' => 'Key irakenewe']); exit; }
        if (isset($DEFAULTS[$key])) {
            echo json_encode(['success' => false, 'message' => 'Ntushobora gusiba setting ya default']);
            exit;
        }
        $stmt = $mysqli->prepare("DELETE FROM settings WHERE setting_type = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Setting yasibwe']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action ntizwi: ' . $action]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method ntiyemewe']);