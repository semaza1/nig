<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
session_start();
header('Content-Type: application/json; charset=utf-8');

// ── Auth ────────────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── DB ───────────────────────────────────────────────────────────
$mysqli = require __DIR__ . '/../../config/db.php';
if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// helper: run a query and return single value
function scalar(mysqli $db, string $sql): float {
    $r = $db->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_row();
    return (float)($row[0] ?? 0);
}

// ── Stats cards ──────────────────────────────────────────────────
$totalMembers  = (int) scalar($mysqli, "SELECT COUNT(*) FROM users WHERE is_member = 1");
$totalLoans    = (int) scalar($mysqli, "SELECT COUNT(*) FROM loans");
$activeLoans   = (int) scalar($mysqli, "SELECT COUNT(*) FROM loans WHERE status IN ('disbursed','approved')");

$totalInterest = scalar($mysqli, "
    SELECT COALESCE(SUM(
        CASE
            WHEN status = 'closed'
                THEN principal_amount * monthly_rate * term_months
            WHEN status IN ('disbursed','approved')
                THEN principal_amount * monthly_rate *
                     LEAST(TIMESTAMPDIFF(MONTH, start_date, CURDATE()), term_months)
            ELSE 0
        END
    ), 0)
    FROM loans
");

$totalExpenses = scalar($mysqli,
    "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date) = YEAR(CURDATE())"
);

$totalContributions = scalar($mysqli,
    "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type = 'contribution'"
);

$totalAssets = scalar($mysqli,
    "SELECT COALESCE(SUM(purchase_value),0) FROM assets"
);

// ── Pending loan approvals ────────────────────────────────────────
$pendingLoans = [];
$res = $mysqli->query("
    SELECT l.loan_id, u.names AS borrower_name,
           l.principal_amount, l.start_date, l.status
    FROM loans l
    JOIN users u ON u.id = l.borrower_user_id
    WHERE l.status IN ('requested','approved')
    ORDER BY l.created_at DESC
    LIMIT 10
");
while ($row = $res->fetch_assoc()) $pendingLoans[] = $row;

// ── Recent loan payments ──────────────────────────────────────────
$recentPayments = [];
$res = $mysqli->query("
    SELECT t.trans_id, t.tx_date, t.amount, t.type,
           u.names AS user_name
    FROM transactions t
    JOIN users u ON u.id = t.user_id
    WHERE t.type = 'loan_payment'
    ORDER BY t.tx_date DESC, t.trans_id DESC
    LIMIT 10
");
while ($row = $res->fetch_assoc()) $recentPayments[] = $row;

// ── 6-month portfolio chart ───────────────────────────────────────
$chartLabels   = [];
$chartInvest   = [];
$chartLoans    = [];
$chartAssets   = [];
$chartExpenses = [];

for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-{$i} months");
    $y  = (int) date('Y', $ts);
    $m  = (int) date('n', $ts);
    $chartLabels[] = date('M Y', $ts);

    // Cumulative contributions up to end of that month
    $stmt = $mysqli->prepare("
        SELECT COALESCE(SUM(amount),0) FROM transactions
        WHERE type='contribution'
          AND (YEAR(tx_date) < ? OR (YEAR(tx_date) = ? AND MONTH(tx_date) <= ?))
    ");
    $stmt->bind_param('iii', $y, $y, $m);
    $stmt->execute();
    $chartInvest[] = (float)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    // Active loan principal up to that month
    $stmt = $mysqli->prepare("
        SELECT COALESCE(SUM(principal_amount),0) FROM loans
        WHERE status IN ('disbursed','approved','requested')
          AND (YEAR(start_date) < ? OR (YEAR(start_date) = ? AND MONTH(start_date) <= ?))
    ");
    $stmt->bind_param('iii', $y, $y, $m);
    $stmt->execute();
    $chartLoans[] = (float)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    // Cumulative assets up to that month
    $stmt = $mysqli->prepare("
        SELECT COALESCE(SUM(purchase_value),0) FROM assets
        WHERE (YEAR(purchase_date) < ? OR (YEAR(purchase_date) = ? AND MONTH(purchase_date) <= ?))
    ");
    $stmt->bind_param('iii', $y, $y, $m);
    $stmt->execute();
    $chartAssets[] = (float)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    // Expenses that specific month only
    $stmt = $mysqli->prepare("
        SELECT COALESCE(SUM(amount),0) FROM expenses
        WHERE YEAR(expense_date) = ? AND MONTH(expense_date) = ?
    ");
    $stmt->bind_param('ii', $y, $m);
    $stmt->execute();
    $chartExpenses[] = (float)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
}

echo json_encode([
    'success' => true,
    'stats'   => [
        'total_members'       => $totalMembers,
        'total_loans'         => $totalLoans,
        'active_loans'        => $activeLoans,
        'total_interest'      => round($totalInterest, 2),
        'total_expenses'      => round($totalExpenses, 2),
        'total_contributions' => round($totalContributions, 2),
        'total_assets'        => round($totalAssets, 2),
    ],
    'pending_loans'   => $pendingLoans,
    'recent_payments' => $recentPayments,
    'chart' => [
        'labels'   => $chartLabels,
        'invest'   => $chartInvest,
        'loans'    => $chartLoans,
        'assets'   => $chartAssets,
        'expenses' => $chartExpenses,
    ],
]);