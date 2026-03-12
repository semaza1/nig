<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
session_start();

/* AUTH */
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

/* DB */
$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

/* HELPERS */
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function scalar(mysqli $db, string $sql): float {
    $r = $db->query($sql);
    if (!$r) return 0.0;
    $row = $r->fetch_row();
    return (float)($row[0] ?? 0);
}

function money(float $v): float {
    return (float)number_format($v, 2, '.', '');
}

function esc_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csv_download(string $filename, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if (!$out) exit;

    if (!empty($rows)) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($out, $row);
    } else {
        fputcsv($out, ['No data']);
    }
    fclose($out);
    exit;
}

function xls_download(string $filename, array $rows): void {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "<table border='1'>";
    if (!empty($rows)) {
        echo "<tr>";
        foreach (array_keys($rows[0]) as $h) echo "<th>" . esc_html((string)$h) . "</th>";
        echo "</tr>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($row as $v) echo "<td>" . esc_html((string)$v) . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td>No data</td></tr>";
    }
    echo "</table>";
    exit;
}

function blob_to_data_uri(?string $mime, $blob): ?string {
    if ($blob === null || $blob === '') return null;
    $mime = $mime ?: 'application/octet-stream';
    return 'data:' . $mime . ';base64,' . base64_encode($blob);
}

function loan_unpaid_principal(mysqli $db, int $loanId): float {
    $st = $db->prepare("SELECT principal, status FROM loans WHERE loan_id=? LIMIT 1");
    if (!$st) return 0.0;
    $st->bind_param('i', $loanId);
    $st->execute();
    $loan = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$loan) return 0.0;

    $principal = (float)($loan['principal'] ?? 0);
    $status = (string)($loan['status'] ?? '');

    if (!in_array($status, ['approved', 'defaulted', 'closed'], true)) {
        return 0.0;
    }

    $paid = 0.0;
    $p = $db->prepare("
        SELECT COALESCE(SUM(amount),0) AS paid
        FROM transactions
        WHERE loan_id=?
          AND type='loan_principal'
          AND direction='IN'
    ");
    if ($p) {
        $p->bind_param('i', $loanId);
        $p->execute();
        $r = $p->get_result()->fetch_assoc();
        $paid = (float)($r['paid'] ?? 0);
        $p->close();
    }

    return max(0.0, $principal - $paid);
}

function total_active_loans_unpaid(mysqli $db): float {
    $res = $db->query("SELECT loan_id FROM loans WHERE status IN ('approved','defaulted')");
    if (!$res) return 0.0;
    $sum = 0.0;
    while ($row = $res->fetch_assoc()) {
        $sum += loan_unpaid_principal($db, (int)$row['loan_id']);
    }
    return money($sum);
}

function get_user_net(mysqli $db, int $user_id): float {
    $st = $db->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN type='contribution' AND direction='IN' THEN amount ELSE 0 END),0) AS c_in,
          COALESCE(SUM(CASE WHEN type='withdrawal' AND direction='OUT' THEN amount ELSE 0 END),0) AS w_out,
          COALESCE(SUM(CASE WHEN type='loan_interest' AND direction='IN' THEN amount ELSE 0 END),0) AS i_in
        FROM transactions
        WHERE user_id=?
    ");
    if (!$st) return 0.0;
    $st->bind_param('i', $user_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $contrib = (float)($row['c_in'] ?? 0);
    $withdraw = (float)($row['w_out'] ?? 0);
    $interest = (float)($row['i_in'] ?? 0);

    $loanRes = $db->prepare("SELECT loan_id FROM loans WHERE borrower_user_id=? AND status IN ('approved','defaulted')");
    $loans_unpaid = 0.0;
    if ($loanRes) {
        $loanRes->bind_param('i', $user_id);
        $loanRes->execute();
        $rs = $loanRes->get_result();
        while ($r = $rs->fetch_assoc()) {
            $loans_unpaid += loan_unpaid_principal($db, (int)$r['loan_id']);
        }
        $loanRes->close();
    }

    $guar = 0.0;
    $g = $db->prepare("
        SELECT COALESCE(SUM(lg.guarantee_amount),0) AS total_guaranteed
        FROM loan_guaranters lg
        INNER JOIN loans l ON l.loan_id = lg.loan_id
        WHERE lg.guarantor_user_id = ?
          AND lg.status = 'accepted'
          AND l.status IN ('approved','defaulted')
    ");
    if ($g) {
        $g->bind_param('i', $user_id);
        $g->execute();
        $gr = $g->get_result()->fetch_assoc();
        $guar = (float)($gr['total_guaranteed'] ?? 0);
        $g->close();
    }

    return money(max(0, ($contrib + $interest) - ($withdraw + $loans_unpaid + $guar + 120000)));
}

function build_recent_activity(mysqli $db): array {
    $items = [];
    $txRes = $db->query("
        SELECT
            t.tx_date,
            t.type,
            t.amount,
            t.description,
            u.names AS user_name
        FROM transactions t
        LEFT JOIN users u ON u.id = t.user_id
        ORDER BY t.tx_date DESC, t.transaction_id DESC
        LIMIT 8
    ");

    if ($txRes) {
        while ($row = $txRes->fetch_assoc()) {
            $type = (string)($row['type'] ?? 'transaction');
            $amount = number_format((float)($row['amount'] ?? 0), 2);
            $user = $row['user_name'] ?: 'System';

            $title = match ($type) {
                'contribution'   => 'Contribution recorded',
                'withdrawal'     => 'Withdrawal recorded',
                'loan_principal' => 'Loan principal payment',
                'loan_interest'  => 'Loan interest payment',
                'expense'        => 'Expense recorded',
                default          => 'Transaction recorded',
            };

            $items[] = [
                'title' => $title,
                'description' => $user . ' • ' . $amount . ' Frw',
                'when' => (string)($row['tx_date'] ?? ''),
            ];
        }
    }

    return $items;
}

/* DETAIL DATASET FUNCTIONS */
function detail_members(mysqli $db): array {
    $items = [];
    $res = $db->query("
        SELECT id, names, phone1, phone2, email, nid_passport, is_member,
               profile_image_name, profile_image_mime,
               CASE WHEN profile_image_data IS NOT NULL THEN 1 ELSE 0 END AS has_profile_image
        FROM users
        WHERE is_member = 1
        ORDER BY names ASC
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['net_value'] = get_user_net($db, (int)$r['id']);
            $items[] = $r;
        }
    }

    return [
        'title' => 'Abanyamuryango bose',
        'subtitle' => 'All available member information',
        'data' => ['items' => $items]
    ];
}

function detail_cash(mysqli $db): array {
    $items = [];
    $res = $db->query("
        SELECT
            t.transaction_id,
            t.tx_date,
            t.type,
            t.direction,
            t.amount,
            t.description,
            t.proof_name,
            t.proof_type,
            CASE WHEN t.proof_data IS NOT NULL AND t.proof_type LIKE 'image/%' THEN 1 ELSE 0 END AS has_proof_image,
            u.names AS user_name,
            a.name AS account_name
        FROM transactions t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN accounts a ON a.account_id = t.account_id
        WHERE t.account_id IS NOT NULL
        ORDER BY t.tx_date DESC, t.transaction_id DESC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

    $contributions = scalar($db, "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='contribution' AND direction='IN'");
    $interest = scalar($db, "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='loan_interest' AND direction='IN' AND account_id IS NOT NULL");
    $expenses = scalar($db, "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND direction='OUT'");
    $cash = scalar($db, "
        SELECT COALESCE(SUM(bal),0)
        FROM (
            SELECT account_id,
                   COALESCE(SUM(CASE WHEN direction='IN' THEN amount ELSE 0 END),0) -
                   COALESCE(SUM(CASE WHEN direction='OUT' THEN amount ELSE 0 END),0) AS bal
            FROM transactions
            WHERE account_id IS NOT NULL
            GROUP BY account_id
        ) x
    ");

    return [
        'title' => 'Amafaranga dufite',
        'subtitle' => 'All account transactions and calculation',
        'data' => [
            'items' => $items,
            'calculation' => [
                'contributions' => money($contributions),
                'interest' => money($interest),
                'expenses' => money($expenses),
                'cash' => money($cash),
            ]
        ]
    ];
}

function detail_interest(mysqli $db): array {
    $items = [];
    $res = $db->query("
        SELECT
            t.transaction_id,
            t.tx_date,
            t.loan_id,
            t.amount,
            t.description,
            t.proof_name,
            t.proof_type,
            CASE WHEN t.proof_data IS NOT NULL AND t.proof_type LIKE 'image/%' THEN 1 ELSE 0 END AS has_proof_image,
            u.names AS user_name,
            a.name AS account_name
        FROM transactions t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN accounts a ON a.account_id = t.account_id
        WHERE t.type='loan_interest' AND t.direction='IN'
        ORDER BY t.tx_date DESC, t.transaction_id DESC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

    return [
        'title' => 'Inyungu twabonye',
        'subtitle' => 'All interest transactions',
        'data' => ['items' => $items]
    ];
}

function detail_expenses(mysqli $db): array {
    $items = [];
    $res = $db->query("
        SELECT
            t.transaction_id,
            t.tx_date,
            t.amount,
            t.description,
            t.proof_name,
            t.proof_type,
            CASE WHEN t.proof_data IS NOT NULL AND t.proof_type LIKE 'image/%' THEN 1 ELSE 0 END AS has_proof_image,
            a.name AS account_name,
            uc.names AS created_by_name
        FROM transactions t
        LEFT JOIN accounts a ON a.account_id = t.account_id
        LEFT JOIN users uc ON uc.id = t.created_by
        WHERE t.type='expense' AND t.direction='OUT'
        ORDER BY t.tx_date DESC, t.transaction_id DESC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

    return [
        'title' => 'Expenses',
        'subtitle' => 'All expenses information',
        'data' => ['items' => $items]
    ];
}

function detail_loans(mysqli $db): array {
    $items = [];
    $res = $db->query("
        SELECT
            l.loan_id,
            l.principal,
            l.monthly_interest_rate,
            l.interest_method,
            l.status,
            l.start_date,
            l.end_date,
            u.names AS borrower_name,
            CONCAT_WS(' / ', u.phone1, u.phone2) AS borrower_phone
        FROM loans l
        LEFT JOIN users u ON u.id = l.borrower_user_id
        WHERE l.status='approved'
        ORDER BY l.loan_id DESC
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['unpaid_principal'] = loan_unpaid_principal($db, (int)$r['loan_id']);
            $items[] = $r;
        }
    }

    return [
        'title' => 'Inguzanyo zemejwe',
        'subtitle' => 'Current approved loans',
        'data' => ['items' => $items]
    ];
}

function detail_requested_loans(mysqli $db): array {
    $items = [];
    $res = $db->query("
        SELECT
            l.loan_id,
            l.principal,
            l.monthly_interest_rate,
            l.interest_method,
            l.status,
            l.created_at,
            u.names AS borrower_name,
            CONCAT_WS(' / ', u.phone1, u.phone2) AS borrower_phone
        FROM loans l
        LEFT JOIN users u ON u.id = l.borrower_user_id
        WHERE l.status='requested'
        ORDER BY l.loan_id DESC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

    return [
        'title' => 'Inguzanyo zitaremezwa',
        'subtitle' => 'Requested loans only',
        'data' => ['items' => $items]
    ];
}

function detail_assets(mysqli $db): array {
    $items = [];
    $res = $db->query("
        SELECT
            a.asset_id,
            a.name,
            a.purchase_date,
            a.purchase_value,
            a.location,
            a.sold_value,
            (
              SELECT COUNT(*)
              FROM asset_holders ah
              WHERE ah.asset_id = a.asset_id
            ) AS holders_count
        FROM assets a
        ORDER BY a.asset_id DESC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

    return [
        'title' => 'Assets',
        'subtitle' => 'All asset records',
        'data' => ['items' => $items]
    ];
}

function detail_guarantors(mysqli $db): array {
    $items = [];
    $res = $db->query("
        SELECT
            u.id,
            u.names,
            u.phone1,
            COALESCE(SUM(lg.guarantee_amount),0) AS total_guaranteed,
            COUNT(DISTINCT lg.loan_id) AS active_loans
        FROM loan_guaranters lg
        INNER JOIN users u ON u.id = lg.guarantor_user_id
        INNER JOIN loans l ON l.loan_id = lg.loan_id
        WHERE l.status IN ('approved','defaulted')
        GROUP BY u.id, u.names, u.phone1
        ORDER BY u.names ASC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $items[] = $r;

    return [
        'title' => 'Guarantors',
        'subtitle' => 'Current guarantors information',
        'data' => ['items' => $items]
    ];
}

function detail_by_type(mysqli $db, string $type): array {
    return match ($type) {
        'members' => detail_members($db),
        'cash' => detail_cash($db),
        'interest' => detail_interest($db),
        'expenses' => detail_expenses($db),
        'loans' => detail_loans($db),
        'requested_loans' => detail_requested_loans($db),
        'assets' => detail_assets($db),
        'guarantors' => detail_guarantors($db),
        default => ['success' => false, 'message' => 'Invalid detail type']
    };
}

function member_profile_image(mysqli $db, int $id): void {
    $st = $db->prepare("SELECT profile_image_mime, profile_image_data FROM users WHERE id=? LIMIT 1");
    if (!$st) { http_response_code(404); exit; }
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || empty($row['profile_image_data'])) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . ($row['profile_image_mime'] ?: 'application/octet-stream'));
    echo $row['profile_image_data'];
    exit;
}

function proof_image(mysqli $db, int $txId): void {
    $st = $db->prepare("SELECT proof_type, proof_data FROM transactions WHERE transaction_id=? LIMIT 1");
    if (!$st) { http_response_code(404); exit; }
    $st->bind_param('i', $txId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || empty($row['proof_data']) || stripos((string)$row['proof_type'], 'image/') !== 0) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $row['proof_type']);
    echo $row['proof_data'];
    exit;
}

function build_html_report(string $title, string $subtitle, array $items, string $type, mysqli $db): string {
    $rowsHtml = '';

    if ($type === 'members') {
        foreach ($items as $r) {
            $img = '';
            if (!empty($r['has_profile_image'])) {
                $st = $db->prepare("SELECT profile_image_mime, profile_image_data FROM users WHERE id=? LIMIT 1");
                if ($st) {
                    $uid = (int)$r['id'];
                    $st->bind_param('i', $uid);
                    $st->execute();
                    $imgRow = $st->get_result()->fetch_assoc();
                    $st->close();
                    $uri = blob_to_data_uri($imgRow['profile_image_mime'] ?? null, $imgRow['profile_image_data'] ?? null);
                    if ($uri) $img = '<img src="' . $uri . '" style="width:60px;height:60px;object-fit:cover;border:1px solid #ccc;border-radius:6px;">';
                }
            }

            $rowsHtml .= '<tr>
                <td>'.$img.'</td>
                <td>'.esc_html((string)($r['names'] ?? '')).'</td>
                <td>'.esc_html((string)($r['phone1'] ?? '')).'</td>
                <td>'.esc_html((string)($r['phone2'] ?? '')).'</td>
                <td>'.esc_html((string)($r['email'] ?? '')).'</td>
                <td>'.esc_html((string)($r['nid_passport'] ?? '')).'</td>
                <td>'.money((float)($r['net_value'] ?? 0)).'</td>
            </tr>';
        }

        $thead = '<tr><th>Image</th><th>Names</th><th>Phone1</th><th>Phone2</th><th>Email</th><th>NID</th><th>Net</th></tr>';
    } else {
        foreach ($items as $r) {
            $proof = '';
            if (!empty($r['proof_type']) && !empty($r['transaction_id'])) {
                if (stripos((string)$r['proof_type'], 'image/') === 0) {
                    $st = $db->prepare("SELECT proof_type, proof_data FROM transactions WHERE transaction_id=? LIMIT 1");
                    if ($st) {
                        $txId = (int)$r['transaction_id'];
                        $st->bind_param('i', $txId);
                        $st->execute();
                        $p = $st->get_result()->fetch_assoc();
                        $st->close();
                        $uri = blob_to_data_uri($p['proof_type'] ?? null, $p['proof_data'] ?? null);
                        if ($uri) $proof = '<img src="'.$uri.'" style="width:60px;height:60px;object-fit:cover;border:1px solid #ccc;border-radius:6px;">';
                    }
                } else {
                    $proof = esc_html((string)($r['proof_name'] ?? ''));
                }
            }

            $cells = '';
            foreach ($r as $k => $v) {
                if (in_array($k, ['proof_type', 'proof_name', 'has_proof_image'], true)) continue;
                if ($k === 'transaction_id') continue;
                $cells .= '<td>' . esc_html((string)$v) . '</td>';
            }
            if ($proof !== '') $cells .= '<td>'.$proof.'</td>';

            $rowsHtml .= '<tr>' . $cells . '</tr>';
        }

        $first = $items[0] ?? [];
        $theadCells = '';
        foreach ($first as $k => $v) {
            if (in_array($k, ['proof_type', 'proof_name', 'has_proof_image'], true)) continue;
            if ($k === 'transaction_id') continue;
            $theadCells .= '<th>' . esc_html($k) . '</th>';
        }
        if (!empty($first['proof_type']) || !empty($first['proof_name']) || !empty($first['has_proof_image'])) {
            $theadCells .= '<th>Proof</th>';
        }
        $thead = '<tr>' . $theadCells . '</tr>';
    }

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>'.esc_html($title).'</title>
<style>
body{font-family:Arial,sans-serif;font-size:12px;color:#111;margin:20px}
h1{font-size:22px;margin:0 0 6px 0}
p{margin:0 0 14px 0;color:#555}
table{width:100%;border-collapse:collapse;margin-top:14px}
th,td{border:1px solid #ccc;padding:6px;vertical-align:top;text-align:left}
th{background:#f3f4f6}
.small{font-size:11px;color:#666}
@media print{button{display:none}}
</style>
</head>
<body>
<h1>'.esc_html($title).'</h1>
<p>'.esc_html($subtitle).'</p>
<p class="small">Generated at: '.date('Y-m-d H:i:s').'</p>
<table>
<thead>'.$thead.'</thead>
<tbody>'.$rowsHtml.'</tbody>
</table>
<script>
window.onload = function(){ if(new URLSearchParams(window.location.search).get("format")==="pdf"){ window.print(); } };
</script>
</body>
</html>';
}

/* ACTIONS */
$action = $_GET['action'] ?? '';

if ($action === 'image') {
    $type = (string)($_GET['type'] ?? '');
    if ($type === 'member_profile') {
        member_profile_image($mysqli, (int)($_GET['id'] ?? 0));
    }
    if ($type === 'proof') {
        proof_image($mysqli, (int)($_GET['tx_id'] ?? 0));
    }
    http_response_code(404);
    exit;
}

if ($action === 'detail') {
    $type = trim((string)($_GET['type'] ?? ''));
    $detail = detail_by_type($mysqli, $type);
    if (isset($detail['success']) && $detail['success'] === false) json_out($detail, 400);
    json_out([
        'success' => true,
        'title' => $detail['title'] ?? 'Details',
        'subtitle' => $detail['subtitle'] ?? '',
        'data' => $detail['data'] ?? ['items' => []]
    ]);
}

if ($action === 'report') {
    $type = trim((string)($_GET['type'] ?? ''));
    $format = trim((string)($_GET['format'] ?? 'excel'));
    $detail = detail_by_type($mysqli, $type);
    if (isset($detail['success']) && $detail['success'] === false) json_out($detail, 400);

    $rows = $detail['data']['items'] ?? [];
    $title = $detail['title'] ?? 'Report';
    $subtitle = $detail['subtitle'] ?? '';

    if ($format === 'excel') {
        xls_download('overview_' . $type . '.xls', $rows);
    }

    if ($format === 'pdf') {
        header('Content-Type: text/html; charset=utf-8');
        echo build_html_report($title, $subtitle, $rows, $type, $mysqli);
        exit;
    }

    json_out(['success' => false, 'message' => 'Invalid format'], 400);
}

/* DASHBOARD DATA */
$totalMembers = (int)scalar($mysqli, "SELECT COUNT(*) FROM users WHERE is_member = 1");
$totalContributions = scalar($mysqli, "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='contribution' AND direction='IN'");
$totalInterest = scalar($mysqli, "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='loan_interest' AND direction='IN' AND account_id IS NOT NULL");
$totalExpenses = scalar($mysqli, "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND direction='OUT'");
$totalCash = scalar($mysqli, "
    SELECT COALESCE(SUM(bal),0)
    FROM (
        SELECT account_id,
               COALESCE(SUM(CASE WHEN direction='IN' THEN amount ELSE 0 END),0) -
               COALESCE(SUM(CASE WHEN direction='OUT' THEN amount ELSE 0 END),0) AS bal
        FROM transactions
        WHERE account_id IS NOT NULL
        GROUP BY account_id
    ) x
");
$totalAssetsValue = scalar($mysqli, "SELECT COALESCE(SUM(purchase_value),0) FROM assets");
$requestedLoans = (int)scalar($mysqli, "SELECT COUNT(*) FROM loans WHERE status='requested'");
$totalGuarantors = (int)scalar($mysqli, "SELECT COUNT(DISTINCT guarantor_user_id) FROM loan_guaranters");
$activeLoansUnpaid = total_active_loans_unpaid($mysqli);
$totalIncome = $totalContributions + $totalInterest;

$portfolioLabels = [];
$portfolioContributions = [];
$portfolioLoans = [];
$portfolioAssets = [];
$portfolioInterest = [];
$portfolioExpenses = [];

$incomeExpenseLabels = [];
$incomeSeries = [];
$expenseSeries = [];

for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-{$i} months");
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    $label = date('M Y', $ts);

    $portfolioLabels[] = $label;
    $incomeExpenseLabels[] = $label;

    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM transactions
        WHERE type='contribution'
          AND direction='IN'
          AND (YEAR(tx_date) < ? OR (YEAR(tx_date)=? AND MONTH(tx_date) <= ?))
    ");
    $st->bind_param('iii', $y, $y, $m);
    $st->execute();
    $portfolioContributions[] = (float)($st->get_result()->fetch_row()[0] ?? 0);
    $st->close();

    $st = $mysqli->prepare("
        SELECT loan_id
        FROM loans
        WHERE status IN ('approved','defaulted','closed')
          AND (YEAR(COALESCE(start_date, created_at)) < ? OR (YEAR(COALESCE(start_date, created_at))=? AND MONTH(COALESCE(start_date, created_at)) <= ?))
    ");
    $st->bind_param('iii', $y, $y, $m);
    $st->execute();
    $res = $st->get_result();
    $loanMonthTotal = 0.0;
    while ($row = $res->fetch_assoc()) {
        $loanMonthTotal += loan_unpaid_principal($mysqli, (int)$row['loan_id']);
    }
    $portfolioLoans[] = money($loanMonthTotal);
    $st->close();

    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(purchase_value),0)
        FROM assets
        WHERE (YEAR(purchase_date) < ? OR (YEAR(purchase_date)=? AND MONTH(purchase_date) <= ?))
    ");
    $st->bind_param('iii', $y, $y, $m);
    $st->execute();
    $portfolioAssets[] = (float)($st->get_result()->fetch_row()[0] ?? 0);
    $st->close();

    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM transactions
        WHERE type='loan_interest'
          AND direction='IN'
          AND YEAR(tx_date)=?
          AND MONTH(tx_date)=?
    ");
    $st->bind_param('ii', $y, $m);
    $st->execute();
    $portfolioInterest[] = (float)($st->get_result()->fetch_row()[0] ?? 0);
    $st->close();

    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM transactions
        WHERE type='expense'
          AND direction='OUT'
          AND YEAR(tx_date)=?
          AND MONTH(tx_date)=?
    ");
    $st->bind_param('ii', $y, $m);
    $st->execute();
    $monthExpense = (float)($st->get_result()->fetch_row()[0] ?? 0);
    $portfolioExpenses[] = $monthExpense;
    $expenseSeries[] = $monthExpense;
    $st->close();

    $st = $mysqli->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM transactions
        WHERE direction='IN'
          AND account_id IS NOT NULL
          AND type IN ('contribution','loan_interest','other_income')
          AND YEAR(tx_date)=?
          AND MONTH(tx_date)=?
    ");
    $st->bind_param('ii', $y, $m);
    $st->execute();
    $incomeSeries[] = (float)($st->get_result()->fetch_row()[0] ?? 0);
    $st->close();
}

$loanStatusLabels = ['Requested', 'Approved', 'Closed', 'Defaulted', 'Rejected'];
$loanStatusValues = [
    (int)scalar($mysqli, "SELECT COUNT(*) FROM loans WHERE status='requested'"),
    (int)scalar($mysqli, "SELECT COUNT(*) FROM loans WHERE status='approved'"),
    (int)scalar($mysqli, "SELECT COUNT(*) FROM loans WHERE status='closed'"),
    (int)scalar($mysqli, "SELECT COUNT(*) FROM loans WHERE status='defaulted'"),
    (int)scalar($mysqli, "SELECT COUNT(*) FROM loans WHERE status='rejected'")
];

json_out([
    'success' => true,
    'stats' => [
        'total_members'       => $totalMembers,
        'total_cash'          => money($totalCash),
        'total_interest'      => money($totalInterest),
        'total_expenses'      => money($totalExpenses),
        'total_loans_issued'  => money($activeLoansUnpaid),
        'requested_loans'     => $requestedLoans,
        'total_assets_value'  => money($totalAssetsValue),
        'total_guarantors'    => $totalGuarantors,
        'total_income'        => money($totalIncome),
    ],
    'recent_activity' => build_recent_activity($mysqli),
    'portfolio_chart' => [
        'labels'        => $portfolioLabels,
        'contributions' => $portfolioContributions,
        'loans'         => $portfolioLoans,
        'assets'        => $portfolioAssets,
        'interest'      => $portfolioInterest,
        'expenses'      => $portfolioExpenses,
    ],
    'income_expense_chart' => [
        'labels'   => $incomeExpenseLabels,
        'income'   => $incomeSeries,
        'expenses' => $expenseSeries,
    ],
    'loan_status_chart' => [
        'labels' => $loanStatusLabels,
        'values' => $loanStatusValues,
    ],
]);