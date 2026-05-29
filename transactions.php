<?php
require_once 'includes/auth.php';
requireRole(['admin','pharmacist','staff'], 'login.php');
$user = getCurrentUser();
$db   = getDB();

$message = ''; $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'void_transaction') {
        $id = (int)($_POST['txn_id'] ?? 0);
        if ($id) {
            $db->query("UPDATE transactions SET payment_status='Void' WHERE id=$id");
            $t = $db->query("SELECT transaction_id FROM transactions WHERE id=$id")->fetch_assoc();
            logAudit($user['id'],"Transaction voided: {$t['transaction_id']}",'UPDATE','transactions',$id,null,null,'WARNING');
            $message = 'Transaction voided.'; $messageType = 'success';
        }
    } elseif ($action === 'refund_transaction') {
        $id = (int)($_POST['txn_id'] ?? 0);
        if ($id) {
            $db->query("UPDATE transactions SET payment_status='Refunded' WHERE id=$id");
            $t = $db->query("SELECT transaction_id FROM transactions WHERE id=$id")->fetch_assoc();
            logAudit($user['id'],"Transaction refunded: {$t['transaction_id']}",'UPDATE','transactions',$id,null,null,'WARNING');
            $message = 'Transaction marked as refunded.'; $messageType = 'success';
        }
    }
}

// Filters
$search  = sanitize($_GET['search']  ?? '');
$fStatus = sanitize($_GET['status']  ?? '');
$fMethod = sanitize($_GET['method']  ?? '');
$fChan   = sanitize($_GET['channel'] ?? '');
$fDate   = sanitize($_GET['date']    ?? '');

$where = "WHERE 1";
if ($search)  $where .= " AND (t.transaction_id LIKE '%$search%' OR t.receipt_number LIKE '%$search%' OR CONCAT(u.first_name,' ',u.last_name) LIKE '%$search%')";
if ($fStatus) $where .= " AND t.payment_status='$fStatus'";
if ($fMethod) $where .= " AND t.payment_method='$fMethod'";
if ($fChan)   $where .= " AND t.channel='$fChan'";
if ($fDate)   $where .= " AND DATE(t.created_at)='$fDate'";

$transactions = [];
$res = $db->query("SELECT t.*, CONCAT(u.first_name,' ',u.last_name) as cashier_name, o.order_number FROM transactions t LEFT JOIN users u ON t.cashier_id=u.id LEFT JOIN orders o ON t.order_id=o.id $where ORDER BY t.created_at DESC LIMIT 500");
while ($row = $res->fetch_assoc()) $transactions[] = $row;

// Summary stats
$totalRev  = $db->query("SELECT COALESCE(SUM(total_amount),0) as c FROM transactions WHERE payment_status='Completed'")->fetch_assoc()['c'];
$todayRev  = $db->query("SELECT COALESCE(SUM(total_amount),0) as c FROM transactions WHERE payment_status='Completed' AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'];
$totalTxns = $db->query("SELECT COUNT(*) as c FROM transactions WHERE payment_status='Completed'")->fetch_assoc()['c'];
$voidedTxns = $db->query("SELECT COUNT(*) as c FROM transactions WHERE payment_status='Void'")->fetch_assoc()['c'];

$statusColors = ['Completed'=>'success','Pending'=>'warning','Refunded'=>'info','Void'=>'danger'];
$methodColors = ['Cash'=>'gray','Card'=>'blue','GCash'=>'teal'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Transactions — RS Pharmacy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="topbar-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <div class="page-breadcrumb">RS Pharmacy / <span>Transactions</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">Transaction Records</div><div class="page-subtitle">View, filter and manage all payment transactions</div></div>
                <div style="display:flex;gap:8px;">
                    <a href="sales_analytics.php" class="btn btn-outline"><i class="fas fa-chart-line"></i> Analytics</a>
                    <a href="pos.php" class="btn btn-primary"><i class="fas fa-cash-register"></i> Open POS</a>
                </div>
            </div>

            <?php if ($message): ?><div class="alert alert-<?=$messageType?>" data-auto-dismiss="4000"><i class="fas fa-info-circle"></i> <?=$message?></div><?php endif; ?>

            <!-- Stats -->
            <div class="stat-grid stat-grid-4" style="margin-bottom:20px;">
                <div class="stat-card red"><div class="stat-icon red"><i class="fas fa-peso-sign"></i></div><div class="stat-value"><?=formatCurrency($totalRev)?></div><div class="stat-label">Total Revenue</div><div class="stat-progress"><div class="stat-progress-bar red" style="width:80%"></div></div></div>
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-calendar-day"></i></div><div class="stat-value"><?=formatCurrency($todayRev)?></div><div class="stat-label">Today's Revenue</div><div class="stat-progress"><div class="stat-progress-bar teal" style="width:65%"></div></div></div>
                <div class="stat-card green"><div class="stat-icon green"><i class="fas fa-receipt"></i></div><div class="stat-value"><?=$totalTxns?></div><div class="stat-label">Completed Transactions</div><div class="stat-progress"><div class="stat-progress-bar green" style="width:70%"></div></div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-ban"></i></div><div class="stat-value"><?=$voidedTxns?></div><div class="stat-label">Voided</div><div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?=min(100,$voidedTxns*10)?>%"></div></div></div>
            </div>

            <!-- Filters & Table -->
            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="table-toolbar-left">
                        <div class="search-bar"><i class="fas fa-search"></i><input type="text" id="txnSearch" placeholder="Search TXN ID, receipt, cashier..." value="<?=htmlspecialchars($search)?>"></div>
                        <select class="filter-select" id="statusFilter">
                            <option value="">All Status</option>
                            <?php foreach(['Completed','Pending','Refunded','Void'] as $s): ?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=$s?></option><?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="methodFilter">
                            <option value="">All Methods</option>
                            <?php foreach(['Cash','Card','GCash'] as $m): ?><option value="<?=$m?>" <?=$fMethod===$m?'selected':''?>><?=$m?></option><?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="channelFilter">
                            <option value="">All Channels</option>
                            <option value="POS" <?=$fChan==='POS'?'selected':''?>>POS</option>
                            <option value="Online" <?=$fChan==='Online'?'selected':''?>>Online</option>
                        </select>
                        <input type="date" class="filter-select" id="dateFilter" value="<?=htmlspecialchars($fDate)?>" title="Filter by date">
                    </div>
                    <div class="table-toolbar-right">
                        <span class="text-muted"><?=count($transactions)?> records</span>
                        <a href="?export=csv" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> Export</a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="txnTable">
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                <th>Receipt</th>
                                <th>Order</th>
                                <th>Cashier</th>
                                <th>Method</th>
                                <th>Channel</th>
                                <th>Subtotal</th>
                                <th>Discount</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                            <tr style="<?=$t['payment_status']==='Void'?'opacity:0.55;':''?>">
                                <td>
                                    <div style="font-weight:700;font-family:monospace;font-size:13px;"><?=sanitize($t['transaction_id']??'—')?></div>
                                </td>
                                <td style="font-family:monospace;font-size:12px;color:var(--gray);"><?=sanitize($t['receipt_number']??'—')?></td>
                                <td>
                                    <?php if($t['order_number']): ?>
                                    <span class="badge badge-gray" style="font-family:monospace;"><?=sanitize($t['order_number'])?></span>
                                    <?php else: ?><span class="text-muted">POS Direct</span><?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div class="avatar avatar-sm" style="background:var(--teal);"><?=strtoupper(substr($t['cashier_name']??'?',0,1))?></div>
                                        <span style="font-size:13px;"><?=sanitize($t['cashier_name']??'—')?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-<?=$methodColors[$t['payment_method']]??'gray'?>">
                                        <i class="fas fa-<?=$t['payment_method']==='Cash'?'money-bill':($t['payment_method']==='Card'?'credit-card':'mobile-screen')?>"></i>
                                        <?=$t['payment_method']?>
                                    </span>
                                </td>
                                <td><span class="badge badge-<?=$t['channel']==='POS'?'dark':'info'?>"><?=$t['channel']?></span></td>
                                <td class="text-muted"><?=formatCurrency($t['subtotal'])?></td>
                                <td style="color:var(--success);"><?=$t['discount']>0?'-'.formatCurrency($t['discount']):'—'?></td>
                                <td style="font-weight:700;font-size:15px;color:var(--primary);"><?=formatCurrency($t['total_amount'])?></td>
                                <td><span class="badge badge-<?=$statusColors[$t['payment_status']]??'gray'?>"><?=$t['payment_status']?></span></td>
                                <td class="text-muted"><?=formatDateTime($t['created_at'])?></td>
                                <td>
                                    <div class="action-links">
                                        <button class="action-link action-print" onclick='printReceipt(<?=json_encode($t, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-print"></i></button>
                                        <?php if ($t['payment_status']==='Completed' && $user['role']==='admin'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Void this transaction?')">
                                            <input type="hidden" name="action" value="void_transaction">
                                            <input type="hidden" name="txn_id" value="<?=$t['id']?>">
                                            <button type="submit" class="action-link action-delete" title="Void"><i class="fas fa-ban"></i></button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark as refunded?')">
                                            <input type="hidden" name="action" value="refund_transaction">
                                            <input type="hidden" name="txn_id" value="<?=$t['id']?>">
                                            <button type="submit" class="action-link action-view" title="Refund"><i class="fas fa-rotate-left"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($transactions)): ?><tr><td colspan="12"><div class="empty-state"><i class="fas fa-receipt"></i><p>No transactions found</p></div></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Print Receipt Modal -->
<div class="modal-backdrop" id="receiptModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-receipt"></i> Transaction Receipt</div>
            <button class="modal-close" onclick="closeModal('receiptModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="receiptBody"></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <button class="btn btn-primary" onclick="closeModal('receiptModal')">Close</button>
        </div>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
document.getElementById('txnSearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#txnTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
});

function applyFilters() {
    const s = document.getElementById('statusFilter').value;
    const m = document.getElementById('methodFilter').value;
    const c = document.getElementById('channelFilter').value;
    const d = document.getElementById('dateFilter').value;
    const q = document.getElementById('txnSearch').value;
    window.location.href = `?status=${s}&method=${m}&channel=${c}&date=${d}&search=${q}`;
}

['statusFilter','methodFilter','channelFilter','dateFilter'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', applyFilters);
});

function printReceipt(t) {
    const statusClass = {Completed:'success',Pending:'warning',Refunded:'info',Void:'danger'};
    document.getElementById('receiptBody').innerHTML = `
        <div style="text-align:center;margin-bottom:20px;">
            <i class="fas fa-pills" style="font-size:28px;color:var(--primary);"></i>
            <div style="font-weight:700;font-size:18px;margin-top:4px;">RS Pharmacy</div>
            <div style="color:var(--gray);font-size:12px;">Official Receipt</div>
        </div>
        <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:12px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px;"><span style="color:var(--gray);">Transaction ID</span><strong style="font-family:monospace;">${t.transaction_id}</strong></div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px;"><span style="color:var(--gray);">Receipt No.</span><strong style="font-family:monospace;">${t.receipt_number||'—'}</strong></div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px;"><span style="color:var(--gray);">Date</span><strong>${t.created_at}</strong></div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px;"><span style="color:var(--gray);">Cashier</span><strong>${t.cashier_name||'—'}</strong></div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px;"><span style="color:var(--gray);">Payment</span><strong>${t.payment_method} · ${t.channel}</strong></div>
            <div style="display:flex;justify-content:space-between;font-size:13px;"><span style="color:var(--gray);">Status</span><span class="badge badge-${statusClass[t.payment_status]||'gray'}">${t.payment_status}</span></div>
        </div>
        <div style="border-top:1px solid var(--gray-light);padding-top:12px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;"><span>Subtotal</span><span>₱${parseFloat(t.subtotal).toFixed(2)}</span></div>
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;"><span>Discount</span><span style="color:var(--success);">-₱${parseFloat(t.discount||0).toFixed(2)}</span></div>
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;"><span>Tax</span><span>₱${parseFloat(t.tax||0).toFixed(2)}</span></div>
            <div style="display:flex;justify-content:space-between;font-size:20px;font-weight:800;color:var(--primary);margin-top:8px;"><span>TOTAL</span><span>₱${parseFloat(t.total_amount).toFixed(2)}</span></div>
        </div>
        <div style="text-align:center;margin-top:16px;font-size:11px;color:var(--gray);">Thank you for choosing RS Pharmacy!<br>Stay healthy — we care for your wellness.</div>
    `;
    openModal('receiptModal');
}
</script>
</body>
</html>
