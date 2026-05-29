<?php
require_once 'includes/auth.php';
requireRole(['admin','pharmacist'], 'login.php');
$user = getCurrentUser();
$db   = getDB();

$message = ''; $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_supply') {
        $editId       = (int)($_POST['edit_id'] ?? 0);
        $supplierId   = (int)($_POST['supplier_id'] ?? 0);
        $status       = $_POST['status'] ?? 'Pending';
        $frequency    = $_POST['frequency'] ?? 'monthly';
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $agentName    = sanitize($_POST['agent_name'] ?? '');
        $orderDate    = $_POST['order_date'] ?? date('Y-m-d');
        $deliveryDate = $_POST['delivery_date'] ?? null;
        $invoiceNo    = sanitize($_POST['invoice_number'] ?? '');
        $totalCost    = (float)($_POST['total_cost'] ?? 0);
        $estimProfit  = (float)($_POST['estimated_profit'] ?? 0);
        $notes        = sanitize($_POST['notes'] ?? '');

        if ($supplierId) {
            if ($editId > 0) {
                $stmt = $db->prepare("UPDATE stock_supply SET supplier_id=?,status=?,frequency=?,payment_method=?,agent_name=?,order_date=?,delivery_date=?,invoice_number=?,total_cost=?,estimated_profit=?,notes=? WHERE id=?");
                $stmt->bind_param('isssssssddsi', $supplierId,$status,$frequency,$paymentMethod,$agentName,$orderDate,$deliveryDate,$invoiceNo,$totalCost,$estimProfit,$notes,$editId);
                $stmt->execute();
                $message = 'Supply record updated!'; $messageType = 'success';
            } else {
                $txnId = 'TX-' . strtoupper(substr(uniqid(), -6));
                $stmt = $db->prepare("INSERT INTO stock_supply (supplier_id,transaction_id,status,frequency,payment_method,agent_name,order_date,delivery_date,invoice_number,total_cost,estimated_profit,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('issssssssdds', $supplierId,$txnId,$status,$frequency,$paymentMethod,$agentName,$orderDate,$deliveryDate,$invoiceNo,$totalCost,$estimProfit,$notes);
                $stmt->execute();
                logAudit($user['id'],"Stock supply order created: $txnId",'CREATE','stock_supply',$db->insert_id);
                $message = 'Supply order created!'; $messageType = 'success';
            }
        } else { $message = 'Please select a supplier.'; $messageType = 'danger'; }

    } elseif ($action === 'save_supplier') {
        $editId  = (int)($_POST['edit_id'] ?? 0);
        $name    = sanitize($_POST['name'] ?? '');
        $contact = sanitize($_POST['contact_person'] ?? '');
        $phone   = sanitize($_POST['phone'] ?? '');
        $email   = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $terms   = sanitize($_POST['payment_terms'] ?? '');
        $status  = $_POST['status'] ?? 'active';

        if ($name) {
            if ($editId > 0) {
                $stmt = $db->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,payment_terms=?,status=? WHERE id=?");
                $stmt->bind_param('sssssssi', $name,$contact,$phone,$email,$address,$terms,$status,$editId);
                $stmt->execute();
                $message = 'Supplier updated!'; $messageType = 'success';
            } else {
                $stmt = $db->prepare("INSERT INTO suppliers (name,contact_person,phone,email,address,payment_terms,status) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('sssssss', $name,$contact,$phone,$email,$address,$terms,$status);
                $stmt->execute();
                $message = 'Supplier added!'; $messageType = 'success';
            }
        } else { $message = 'Supplier name is required.'; $messageType = 'danger'; }

    } elseif ($action === 'update_supply_status') {
        $id = (int)($_POST['supply_id'] ?? 0); $status = sanitize($_POST['status'] ?? '');
        if ($id && $status) { $db->query("UPDATE stock_supply SET status='$status' WHERE id=$id"); $message = 'Status updated.'; $messageType = 'success'; }

    } elseif ($action === 'delete_supply') {
        $id = (int)($_POST['delete_id'] ?? 0);
        if ($id) { $db->query("DELETE FROM stock_supply WHERE id=$id"); $message = 'Record deleted.'; $messageType = 'success'; }
    }
}

// Fetch supply records
$search = sanitize($_GET['search'] ?? '');
$filterStatus = sanitize($_GET['status'] ?? '');

$where = "WHERE 1";
if ($search)       $where .= " AND (ss.transaction_id LIKE '%$search%' OR s.name LIKE '%$search%' OR ss.agent_name LIKE '%$search%' OR ss.invoice_number LIKE '%$search%')";
if ($filterStatus) $where .= " AND ss.status='$filterStatus'";

$supplyRecords = [];
$res = $db->query("SELECT ss.*, s.name as supplier_name, s.contact_person, s.phone as supplier_phone FROM stock_supply ss LEFT JOIN suppliers s ON ss.supplier_id=s.id $where ORDER BY ss.created_at DESC");
while ($row = $res->fetch_assoc()) $supplyRecords[] = $row;

$suppliers = [];
$res2 = $db->query("SELECT * FROM suppliers WHERE status='active' ORDER BY name");
while ($row = $res2->fetch_assoc()) $suppliers[] = $row;

$allSuppliers = [];
$res3 = $db->query("SELECT * FROM suppliers ORDER BY name");
while ($row = $res3->fetch_assoc()) $allSuppliers[] = $row;

// Stats
$totalOrders = count($supplyRecords);
$totalCost   = $db->query("SELECT COALESCE(SUM(total_cost),0) as c FROM stock_supply")->fetch_assoc()['c'];
$totalProfit = $db->query("SELECT COALESCE(SUM(estimated_profit),0) as c FROM stock_supply")->fetch_assoc()['c'];
$pendingOrders = $db->query("SELECT COUNT(*) as c FROM stock_supply WHERE status IN ('Ordered','Pending')")->fetch_assoc()['c'];

$statusColors = ['Ordered'=>'info','In Transit'=>'warning','Received'=>'success','Cancelled'=>'danger','Pending'=>'warning'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Stock Supply — RS Pharmacy</title>
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
                <div class="page-breadcrumb">RS Pharmacy / <span>Stock Supply</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">Stock Supply Management</div><div class="page-subtitle">Manage suppliers and supply transactions</div></div>
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-outline" onclick="openModal('supplierModal')"><i class="fas fa-building"></i> Manage Suppliers</button>
                    <button class="btn btn-primary" onclick="openSupplyModal()"><i class="fas fa-plus"></i> New Supply Order</button>
                </div>
            </div>

            <?php if ($message): ?><div class="alert alert-<?=$messageType?>" data-auto-dismiss="4000"><i class="fas fa-info-circle"></i> <?=$message?></div><?php endif; ?>

            <!-- Stats -->
            <div class="stat-grid stat-grid-4" style="margin-bottom:20px;">
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-truck"></i></div><div class="stat-value"><?=$totalOrders?></div><div class="stat-label">Total Orders</div><div class="stat-progress"><div class="stat-progress-bar teal" style="width:80%"></div></div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-clock"></i></div><div class="stat-value"><?=$pendingOrders?></div><div class="stat-label">Pending/Ordered</div><div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?=min(100,$pendingOrders*15)?>%"></div></div></div>
                <div class="stat-card red"><div class="stat-icon red"><i class="fas fa-peso-sign"></i></div><div class="stat-value"><?=formatCurrency($totalCost)?></div><div class="stat-label">Total Supply Cost</div><div class="stat-progress"><div class="stat-progress-bar red" style="width:75%"></div></div></div>
                <div class="stat-card green"><div class="stat-icon green"><i class="fas fa-chart-line"></i></div><div class="stat-value"><?=formatCurrency($totalProfit)?></div><div class="stat-label">Est. Profit</div><div class="stat-progress"><div class="stat-progress-bar green" style="width:65%"></div></div></div>
            </div>

            <!-- Supplier Quick Cards -->
            <?php if (!empty($suppliers)): ?>
            <div style="display:flex;gap:12px;margin-bottom:20px;overflow-x:auto;padding-bottom:4px;">
                <?php foreach ($suppliers as $s): ?>
                <div class="card" style="flex-shrink:0;width:220px;padding:14px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <div class="avatar" style="background:var(--teal);"><?=strtoupper(substr($s['name'],0,2))?></div>
                        <div>
                            <div style="font-weight:700;font-size:13px;"><?=sanitize(substr($s['name'],0,20))?></div>
                            <div style="font-size:11px;color:var(--gray);"><?=sanitize($s['contact_person']??'—')?></div>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--gray);">
                        <span><i class="fas fa-star" style="color:var(--warning);"></i> <?=number_format($s['rating']??5,1)?></span>
                        <span><?=sanitize($s['payment_terms']??'—')?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Filters + Table -->
            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="table-toolbar-left">
                        <div class="search-bar"><i class="fas fa-search"></i><input type="text" id="supplySearch" placeholder="Search TXN ID, supplier, agent..." value="<?=htmlspecialchars($search)?>"></div>
                        <select class="filter-select" id="supplyStatusFilter">
                            <option value="">All Status</option>
                            <?php foreach(['Pending','Ordered','In Transit','Received','Cancelled'] as $s): ?><option value="<?=$s?>" <?=$filterStatus===$s?'selected':''?>><?=$s?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="table-toolbar-right"><span class="text-muted"><?=count($supplyRecords)?> records</span></div>
                </div>
                <div class="table-responsive">
                    <table id="supplyTable">
                        <thead>
                            <tr>
                                <th>Transaction</th>
                                <th>Supplier</th>
                                <th>Agent</th>
                                <th>Frequency</th>
                                <th>Payment</th>
                                <th>Order Date</th>
                                <th>Delivery</th>
                                <th>Cost</th>
                                <th>Est. Profit</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supplyRecords as $sr): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700;font-family:monospace;font-size:13px;"><?=sanitize($sr['transaction_id']??'—')?></div>
                                    <div style="font-size:11px;color:var(--gray);"><?=sanitize($sr['invoice_number']??'')?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?=sanitize($sr['supplier_name']??'—')?></div>
                                    <div style="font-size:11px;color:var(--gray);"><?=sanitize($sr['supplier_phone']??'')?></div>
                                </td>
                                <td class="text-muted"><?=sanitize($sr['agent_name']??'—')?></td>
                                <td><span class="badge badge-gray"><?=ucfirst($sr['frequency']??'—')?></span></td>
                                <td>
                                    <?php $pmColors = ['bank'=>'blue','cash'=>'success','credit'=>'warning','check'=>'gray']; ?>
                                    <span class="badge badge-<?=$pmColors[$sr['payment_method']]??'gray'?>"><?=ucfirst($sr['payment_method']??'—')?></span>
                                </td>
                                <td class="text-muted"><?=formatDate($sr['order_date'])?></td>
                                <td class="text-muted"><?=$sr['delivery_date']?formatDate($sr['delivery_date']):'—'?></td>
                                <td style="font-weight:600;"><?=formatCurrency($sr['total_cost'])?></td>
                                <td style="font-weight:600;color:var(--success);"><?=formatCurrency($sr['estimated_profit'])?></td>
                                <td>
                                    <span class="badge badge-<?=$statusColors[$sr['status']]??'gray'?>"><?=$sr['status']?></span>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <button class="action-link action-edit" onclick='editSupply(<?=json_encode($sr, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-pen"></i></button>
                                        <?php if ($sr['status'] !== 'Received' && $sr['status'] !== 'Cancelled'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="update_supply_status">
                                            <input type="hidden" name="supply_id" value="<?=$sr['id']?>">
                                            <input type="hidden" name="status" value="Received">
                                            <button type="submit" class="action-link action-approve" title="Mark Received"><i class="fas fa-check"></i></button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this supply record?')">
                                            <input type="hidden" name="action" value="delete_supply">
                                            <input type="hidden" name="delete_id" value="<?=$sr['id']?>">
                                            <button type="submit" class="action-link action-delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($supplyRecords)): ?><tr><td colspan="11"><div class="empty-state"><i class="fas fa-truck"></i><p>No supply records found</p></div></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Supply Order Modal -->
<div class="modal-backdrop" id="supplyModal">
    <div class="modal modal-xl">
        <div class="modal-header">
            <div class="modal-title" id="supplyModalTitle"><i class="fas fa-truck"></i> New Supply Order</div>
            <button class="modal-close" onclick="closeModal('supplyModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="supplyForm">
            <input type="hidden" name="action" value="save_supply">
            <input type="hidden" name="edit_id" id="ssEditId" value="0">
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                    <div>
                        <div class="form-group"><label class="form-label">Supplier *</label>
                            <select name="supplier_id" id="ssSupplier" class="form-control" required>
                                <option value="">Select Supplier</option>
                                <?php foreach($suppliers as $s): ?><option value="<?=$s['id']?>"><?=sanitize($s['name'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Order Date</label><input type="date" name="order_date" id="ssOrderDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
                            <div class="form-group"><label class="form-label">Expected Delivery</label><input type="date" name="delivery_date" id="ssDelivery" class="form-control"></div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Invoice Number</label><input type="text" name="invoice_number" id="ssInvoice" class="form-control" placeholder="INV-XXXX"></div>
                            <div class="form-group"><label class="form-label">Agent Name</label><input type="text" name="agent_name" id="ssAgent" class="form-control" placeholder="Sales agent name"></div>
                        </div>
                    </div>
                    <div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Frequency</label>
                                <select name="frequency" id="ssFreq" class="form-control">
                                    <?php foreach(['weekly','monthly','quarterly','one-time'] as $f): ?><option value="<?=$f?>"><?=ucfirst($f)?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label class="form-label">Payment Method</label>
                                <select name="payment_method" id="ssPay" class="form-control">
                                    <?php foreach(['cash','bank','credit','check'] as $p): ?><option value="<?=$p?>"><?=ucfirst($p)?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Total Cost (₱)</label>
                                <div class="input-group"><span class="input-prefix">₱</span><input type="number" name="total_cost" id="ssCost" class="form-control" placeholder="0.00" step="0.01" min="0"></div>
                            </div>
                            <div class="form-group"><label class="form-label">Est. Profit (₱)</label>
                                <div class="input-group"><span class="input-prefix">₱</span><input type="number" name="estimated_profit" id="ssProfit" class="form-control" placeholder="0.00" step="0.01" min="0"></div>
                            </div>
                        </div>
                        <div class="form-group"><label class="form-label">Status</label>
                            <select name="status" id="ssStatus" class="form-control">
                                <?php foreach(['Pending','Ordered','In Transit','Received','Cancelled'] as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="ssNotes" class="form-control" rows="2" placeholder="Special instructions..."></textarea></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('supplyModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Suppliers List Modal -->
<div class="modal-backdrop" id="supplierModal">
    <div class="modal modal-xl">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-building"></i> Supplier Directory</div>
            <button class="modal-close" onclick="closeModal('supplierModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <!-- Add Supplier Form -->
            <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:16px;margin-bottom:16px;">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin-bottom:12px;">Add / Edit Supplier</div>
                <form method="POST" id="supplierForm">
                    <input type="hidden" name="action" value="save_supplier">
                    <input type="hidden" name="edit_id" id="supEditId" value="0">
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
                        <div class="form-group" style="margin:0;"><label class="form-label">Name *</label><input type="text" name="name" id="supName" class="form-control" required></div>
                        <div class="form-group" style="margin:0;"><label class="form-label">Contact Person</label><input type="text" name="contact_person" id="supContact" class="form-control"></div>
                        <div class="form-group" style="margin:0;"><label class="form-label">Phone</label><input type="text" name="phone" id="supPhone" class="form-control"></div>
                        <div class="form-group" style="margin:0;"><label class="form-label">Email</label><input type="email" name="email" id="supEmail" class="form-control"></div>
                        <div class="form-group" style="margin:0;"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" id="supTerms" class="form-control" placeholder="e.g. Net 30"></div>
                        <div class="form-group" style="margin:0;"><label class="form-label">Status</label><select name="status" id="supStatus" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    </div>
                    <div class="form-group" style="margin-top:10px;margin-bottom:0;"><label class="form-label">Address</label><input type="text" name="address" id="supAddress" class="form-control"></div>
                    <div style="margin-top:12px;display:flex;gap:8px;">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save Supplier</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="resetSupplierForm()">Reset</button>
                    </div>
                </form>
            </div>
            <!-- Suppliers List -->
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr><?php foreach(['Supplier','Contact','Phone','Payment Terms','Rating','Status','Edit'] as $h): ?><th style="padding:10px;background:var(--gray-ultra);font-size:11px;font-weight:700;text-transform:uppercase;color:var(--gray);text-align:left;"><?=$h?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ($allSuppliers as $s): ?>
                    <tr style="border-bottom:1px solid var(--gray-ultra);">
                        <td style="padding:10px;font-weight:600;"><?=sanitize($s['name'])?></td>
                        <td style="padding:10px;color:var(--gray);font-size:13px;"><?=sanitize($s['contact_person']??'—')?></td>
                        <td style="padding:10px;color:var(--gray);font-size:13px;"><?=sanitize($s['phone']??'—')?></td>
                        <td style="padding:10px;"><span class="badge badge-gray"><?=sanitize($s['payment_terms']??'—')?></span></td>
                        <td style="padding:10px;"><span style="color:var(--warning);"><i class="fas fa-star"></i> <?=number_format($s['rating']??5,1)?></span></td>
                        <td style="padding:10px;"><span class="badge badge-<?=$s['status']==='active'?'success':'gray'?>"><?=ucfirst($s['status'])?></span></td>
                        <td style="padding:10px;"><button class="action-link action-edit" onclick='fillSupplierForm(<?=json_encode($s, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-pen"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($allSuppliers)): ?><tr><td colspan="7"><div class="empty-state"><i class="fas fa-building"></i><p>No suppliers found</p></div></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('supplierModal')">Close</button></div>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
document.getElementById('supplySearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#supplyTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
});
document.getElementById('supplyStatusFilter').addEventListener('change', function() {
    window.location.href = `?status=${this.value}&search=${document.getElementById('supplySearch').value}`;
});

function openSupplyModal() {
    document.getElementById('supplyModalTitle').innerHTML = '<i class="fas fa-truck"></i> New Supply Order';
    document.getElementById('ssEditId').value = '0';
    document.getElementById('supplyForm').reset();
    document.getElementById('ssOrderDate').value = '<?=date('Y-m-d')?>';
    openModal('supplyModal');
}

function editSupply(sr) {
    document.getElementById('supplyModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Supply Order';
    document.getElementById('ssEditId').value = sr.id;
    document.getElementById('ssSupplier').value = sr.supplier_id || '';
    document.getElementById('ssOrderDate').value = sr.order_date || '';
    document.getElementById('ssDelivery').value = sr.delivery_date || '';
    document.getElementById('ssInvoice').value = sr.invoice_number || '';
    document.getElementById('ssAgent').value = sr.agent_name || '';
    document.getElementById('ssFreq').value = sr.frequency || 'monthly';
    document.getElementById('ssPay').value = sr.payment_method || 'cash';
    document.getElementById('ssCost').value = sr.total_cost || '';
    document.getElementById('ssProfit').value = sr.estimated_profit || '';
    document.getElementById('ssStatus').value = sr.status || 'Pending';
    document.getElementById('ssNotes').value = sr.notes || '';
    openModal('supplyModal');
}

function fillSupplierForm(s) {
    document.getElementById('supEditId').value = s.id;
    document.getElementById('supName').value = s.name || '';
    document.getElementById('supContact').value = s.contact_person || '';
    document.getElementById('supPhone').value = s.phone || '';
    document.getElementById('supEmail').value = s.email || '';
    document.getElementById('supTerms').value = s.payment_terms || '';
    document.getElementById('supStatus').value = s.status || 'active';
    document.getElementById('supAddress').value = s.address || '';
}

function resetSupplierForm() {
    document.getElementById('supEditId').value = '0';
    document.getElementById('supplierForm').reset();
}
</script>
</body>
</html>
