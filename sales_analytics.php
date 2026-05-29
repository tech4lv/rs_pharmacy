<?php
require_once 'includes/auth.php';
requireRole(['admin'], 'login.php');
$user = getCurrentUser();
$db = getDB();

// Summary stats
$totalRevenue = $db->query("SELECT COALESCE(SUM(total_amount),0) as c FROM transactions WHERE payment_status='Completed'")->fetch_assoc()['c'];
$totalTxns    = $db->query("SELECT COUNT(*) as c FROM transactions WHERE payment_status='Completed'")->fetch_assoc()['c'];
$avgOrder     = $totalTxns > 0 ? $totalRevenue / $totalTxns : 0;
$cashRevenue  = $db->query("SELECT COALESCE(SUM(total_amount),0) as c FROM transactions WHERE payment_method='Cash' AND payment_status='Completed'")->fetch_assoc()['c'];
$posRevenue   = $db->query("SELECT COALESCE(SUM(total_amount),0) as c FROM transactions WHERE channel='POS' AND payment_status='Completed'")->fetch_assoc()['c'];
$onlineRevenue = $totalRevenue - $posRevenue;

// Days filter
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 60, 90])) $days = 30;

// Daily revenue for chart
$dailyRevenue = [];
$dailyLabels  = [];
$res = $db->query("SELECT DATE(created_at) as d, COALESCE(SUM(total_amount),0) as rev FROM transactions WHERE payment_status='Completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL $days DAY) GROUP BY DATE(created_at) ORDER BY d");
while ($row = $res->fetch_assoc()) {
    $dailyLabels[]  = date('M j', strtotime($row['d']));
    $dailyRevenue[] = (float)$row['rev'];
}

// Payment breakdown
$paymentBreak = [];
$res2 = $db->query("SELECT payment_method, COALESCE(SUM(total_amount),0) as rev, COUNT(*) as cnt FROM transactions WHERE payment_status='Completed' GROUP BY payment_method");
while ($row = $res2->fetch_assoc()) $paymentBreak[] = $row;

// Top products
$topProducts = [];
$res3 = $db->query("SELECT oi.product_name, COALESCE(SUM(oi.subtotal),0) as rev, COALESCE(SUM(oi.quantity),0) as qty FROM order_items oi JOIN orders o ON oi.order_id=o.id WHERE o.status='Completed' OR o.id IS NOT NULL GROUP BY oi.product_name ORDER BY rev DESC LIMIT 7");
while ($row = $res3->fetch_assoc()) $topProducts[] = $row;

// Staff leaderboard
$leaderboard = [];
$res4 = $db->query("SELECT CONCAT(u.first_name,' ',u.last_name) as name, u.role, COALESCE(SUM(t.total_amount),0) as rev, COUNT(*) as orders FROM transactions t JOIN users u ON t.cashier_id=u.id WHERE t.payment_status='Completed' GROUP BY t.cashier_id ORDER BY rev DESC LIMIT 5");
while ($row = $res4->fetch_assoc()) $leaderboard[] = $row;

// Recent transactions
$recentTxns = [];
$res5 = $db->query("SELECT t.*, CONCAT(u.first_name,' ',u.last_name) as cashier_name FROM transactions t LEFT JOIN users u ON t.cashier_id=u.id ORDER BY t.created_at DESC LIMIT 10");
while ($row = $res5->fetch_assoc()) $recentTxns[] = $row;

$prevRevenue = $db->query("SELECT COALESCE(SUM(total_amount),0) as c FROM transactions WHERE payment_status='Completed' AND created_at BETWEEN DATE_SUB(CURDATE(), INTERVAL $days*2 DAY) AND DATE_SUB(CURDATE(), INTERVAL $days DAY)")->fetch_assoc()['c'];
$growth = $prevRevenue > 0 ? (($totalRevenue - $prevRevenue) / $prevRevenue * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales Analytics — RS Pharmacy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="topbar-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <div class="page-breadcrumb">RS Pharmacy / <span>Sales Analytics</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">Sales Trends & Analytics</div><div class="page-subtitle">Revenue performance, channel breakdown, and product insights</div></div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <?php foreach ([7,30,60,90] as $d): ?>
                    <a href="?days=<?= $d ?>" class="btn <?= $days==$d?'btn-dark':'btn-outline' ?> btn-sm"><?= $d ?>d</a>
                    <?php endforeach; ?>
                    <a href="?export=csv" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> Export</a>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="stat-grid stat-grid-4" style="margin-bottom:20px;">
                <div class="stat-card red">
                    <div class="stat-icon red"><i class="fas fa-peso-sign"></i></div>
                    <div class="stat-value"><?= formatCurrency($totalRevenue) ?></div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-sub"><?= $totalTxns ?> transactions · <?= ($growth >= 0 ? '+' : '') . number_format($growth, 1) ?>% vs prev period</div>
                    <div class="stat-progress"><div class="stat-progress-bar red" style="width:78%"></div></div>
                </div>
                <div class="stat-card teal">
                    <div class="stat-icon teal"><i class="fas fa-cash-register"></i></div>
                    <div class="stat-value"><?= formatCurrency($posRevenue) ?></div>
                    <div class="stat-label">POS Revenue</div>
                    <div class="stat-sub">Walk-in sales channel</div>
                    <div class="stat-progress"><div class="stat-progress-bar teal" style="width:<?= $totalRevenue > 0 ? ($posRevenue/$totalRevenue*100) : 0 ?>%"></div></div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-icon blue"><i class="fas fa-globe"></i></div>
                    <div class="stat-value"><?= formatCurrency($onlineRevenue) ?></div>
                    <div class="stat-label">Online Revenue</div>
                    <div class="stat-sub">E-commerce channel</div>
                    <div class="stat-progress"><div class="stat-progress-bar blue" style="width:<?= $totalRevenue > 0 ? ($onlineRevenue/$totalRevenue*100) : 0 ?>%"></div></div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon green"><i class="fas fa-chart-bar"></i></div>
                    <div class="stat-value"><?= formatCurrency($avgOrder) ?></div>
                    <div class="stat-label">Avg. Order Value</div>
                    <div class="stat-sub">Per transaction average</div>
                    <div class="stat-progress"><div class="stat-progress-bar green" style="width:60%"></div></div>
                </div>
            </div>

            <!-- Revenue Chart -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-chart-line" style="color:var(--teal);margin-right:8px;"></i> Revenue Over Time</div>
                    <span class="text-muted">Last <?= $days ?> days</span>
                </div>
                <div class="card-body">
                    <div style="height:280px;"><canvas id="revenueChart"></canvas></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                <!-- Channel & Payment Breakdown -->
                <div class="card">
                    <div class="card-header"><div class="card-title">Channel & Payment Breakdown</div></div>
                    <div class="card-body">
                        <div style="margin-bottom:16px;">
                            <div style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:10px;">By Channel</div>
                            <?php
                            $channels = [['POS', $posRevenue, 'teal'], ['Online', $onlineRevenue, 'blue']];
                            $maxCh = max(array_column($channels, 1)) ?: 1;
                            foreach ($channels as [$label, $amt, $color]):
                            ?>
                            <div style="margin-bottom:10px;">
                                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;"><span><?= $label ?></span><strong><?= formatCurrency($amt) ?></strong></div>
                                <div class="stat-progress"><div class="stat-progress-bar <?= $color ?>" style="width:<?= $maxCh>0?($amt/$maxCh*100):0 ?>%"></div></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <div style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:10px;">By Payment Method</div>
                            <div style="height:180px;"><canvas id="paymentChart"></canvas></div>
                        </div>
                    </div>
                </div>

                <!-- Top Products -->
                <div class="card">
                    <div class="card-header"><div class="card-title">Top Products by Revenue</div></div>
                    <div class="card-body">
                        <div style="height:260px;"><canvas id="productsChart"></canvas></div>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                <!-- Staff Leaderboard -->
                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="fas fa-trophy" style="color:var(--warning);margin-right:8px;"></i> Staff Leaderboard</div></div>
                    <div class="card-body">
                        <?php foreach ($leaderboard as $i => $staff): ?>
                        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--gray-ultra);">
                            <div style="width:24px;height:24px;border-radius:50%;background:<?= ['var(--warning)','var(--gray-light)','rgba(180,120,60,0.4)'][$i]??'var(--gray-ultra)' ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:<?= $i<3?'var(--white)':'var(--gray)' ?>;"><?= $i+1 ?></div>
                            <div class="avatar avatar-sm" style="background:var(--teal);"><?= strtoupper(substr($staff['name'],0,1)) ?></div>
                            <div style="flex:1;">
                                <div style="font-weight:600;font-size:13px;"><?= sanitize($staff['name']) ?></div>
                                <div style="font-size:11px;color:var(--gray);"><?= $staff['orders'] ?> orders · <?= ucfirst($staff['role']) ?></div>
                            </div>
                            <div style="font-weight:700;color:var(--primary);"><?= formatCurrency($staff['rev']) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($leaderboard)): ?><div class="empty-state" style="padding:20px;"><i class="fas fa-trophy"></i><p>No data yet</p></div><?php endif; ?>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-header"><div class="card-title">Recent Transactions</div><a href="transactions.php" class="btn btn-outline btn-sm">View All</a></div>
                    <div style="overflow-x:auto;">
                        <table>
                            <thead><tr><th>Receipt</th><th>Cashier</th><th>Method</th><th>Status</th><th>Amount</th></tr></thead>
                            <tbody>
                                <?php foreach ($recentTxns as $t): ?>
                                <tr>
                                    <td><div style="font-weight:600;font-size:12px;"><?= sanitize($t['receipt_number'] ?? $t['transaction_id']) ?></div><div class="text-muted"><?= formatDate($t['created_at']) ?></div></td>
                                    <td style="font-size:12px;"><?= sanitize($t['cashier_name'] ?? '—') ?></td>
                                    <td><span class="badge badge-<?= ['Cash'=>'gray','Card'=>'info','GCash'=>'teal'][$t['payment_method']]??'gray' ?>"><?= $t['payment_method'] ?></span></td>
                                    <td><span class="badge badge-<?= ['Completed'=>'success','Refunded'=>'warning','Void'=>'danger','Pending'=>'warning'][$t['payment_status']]??'gray' ?>"><?= $t['payment_status'] ?></span></td>
                                    <td style="font-weight:700;color:var(--primary);"><?= formatCurrency($t['total_amount']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentTxns)): ?><tr><td colspan="5"><div class="empty-state"><i class="fas fa-receipt"></i><p>No transactions yet</p></div></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/main.js"></script>
<script>
const labels   = <?= json_encode($dailyLabels) ?>;
const revenue  = <?= json_encode($dailyRevenue) ?>;
const payData  = <?= json_encode(array_column($paymentBreak, 'rev')) ?>;
const payLabels = <?= json_encode(array_column($paymentBreak, 'payment_method')) ?>;
const topNames = <?= json_encode(array_column($topProducts, 'product_name')) ?>;
const topRevs  = <?= json_encode(array_column($topProducts, 'rev')) ?>;

Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.color = '#8E8E93';

// Revenue Line Chart
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [{
            label: 'Revenue (₱)',
            data: revenue,
            borderColor: '#C0392B',
            backgroundColor: 'rgba(192,57,43,0.06)',
            borderWidth: 2.5,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#C0392B',
            pointRadius: 3,
            pointHoverRadius: 5,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '₱' + ctx.raw.toFixed(2) } } },
        scales: {
            x: { grid: { color: 'rgba(0,0,0,0.04)' } },
            y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { callback: v => '₱' + v.toFixed(0) } }
        }
    }
});

// Payment Donut
new Chart(document.getElementById('paymentChart'), {
    type: 'doughnut',
    data: {
        labels: payLabels,
        datasets: [{
            data: payData,
            backgroundColor: ['#1C1C1E','#C0392B','#00897B'],
            borderWidth: 0,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 12, font: { size: 12 } } },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ₱' + parseFloat(ctx.raw).toFixed(2) } }
        },
        cutout: '65%'
    }
});

// Top Products Bar
new Chart(document.getElementById('productsChart'), {
    type: 'bar',
    data: {
        labels: topNames,
        datasets: [{
            label: 'Revenue',
            data: topRevs,
            backgroundColor: '#00897B',
            borderRadius: 6,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '₱' + parseFloat(ctx.raw).toFixed(2) } } },
        scales: {
            x: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { callback: v => '₱' + v } },
            y: { grid: { display: false } }
        }
    }
});
</script>
</body>
</html>
