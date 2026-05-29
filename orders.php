<?php
require_once 'includes/auth.php';
requireLogin('login.php');
$user = getCurrentUser();
$db   = getDB();

if ($user['role'] === 'patient') {
    header('Location: appointments.php');
    exit;
}

$message = ''; $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_order') {
        $editId    = (int)($_POST['edit_id'] ?? 0);
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $orderType = $_POST['order_type'] ?? 'walk-in';
        $status    = $_POST['status'] ?? 'Pending';
        $rxId      = (int)($_POST['prescription_id'] ?? 0) ?: null;
        $notes     = sanitize($_POST['notes'] ?? '');

        $productIds = $_POST['product_id']  ?? [];
        $quantities  = $_POST['quantity']    ?? [];
        $unitPrices  = $_POST['unit_price']  ?? [];
        $discounts   = $_POST['discount']    ?? [];

        if ($patientId) {
            if ($editId > 0) {
                $stmt = $db->prepare("UPDATE orders SET patient_id=?,order_type=?,status=?,prescription_id=?,notes=? WHERE id=?");
                $stmt->bind_param('issssi', $patientId,$orderType,$status,$rxId,$notes,$editId);
                $stmt->execute();
                $db->query("DELETE FROM order_items WHERE order_id=$editId");
                $orderId = $editId;
                $message = 'Order updated!'; $messageType = 'success';
            } else {
                $orderNum = 'ORD-' . strtoupper(substr(uniqid(), -6));
                $stmt = $db->prepare("INSERT INTO orders (order_number,patient_id,order_type,status,prescription_id,notes,created_by) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('sissisi', $orderNum,$patientId,$orderType,$status,$rxId,$notes,$user['id']);
                $stmt->execute();
                $orderId = $db->insert_id;
                logAudit($user['id'],"Order created: $orderNum",'CREATE','orders',$orderId);
                $message = 'Order created!'; $messageType = 'success';
            }

            foreach ($productIds as $idx => $pid) {
                $pid  = (int)$pid;
                $qty  = (int)($quantities[$idx] ?? 1);
                $price = (float)($unitPrices[$idx] ?? 0);
                $disc  = (float)($discounts[$idx] ?? 0);
                if (!$pid || $qty <= 0) continue;
                $subtotal = ($qty * $price) - $disc;
                $prodName = $db->query("SELECT name FROM products WHERE id=$pid")->fetch_assoc()['name'] ?? '';
                $stmt2 = $db->prepare("INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price,discount,subtotal) VALUES (?,?,?,?,?,?,?)");
                $stmt2->bind_param('iisiddd', $orderId,$pid,$prodName,$qty,$price,$disc,$subtotal);
                $stmt2->execute();
            }
        } else { $message = 'Please select a patient.'; $messageType = 'danger'; }

    } elseif ($action === 'update_status') {
        $id = (int)($_POST['order_id'] ?? 0); $status = sanitize($_POST['status'] ?? '');
        if ($id && $status) {
            $db->query("UPDATE orders SET status='$status' WHERE id=$id");
            logAudit($user['id'],"Order status updated to $status",'UPDATE','orders',$id);
            $message = 'Status updated.'; $messageType = 'success';
        }
    } elseif ($action === 'delete_order') {
        $id = (int)($_POST['delete_id'] ?? 0);
        if ($id) { $db->query("DELETE FROM orders WHERE id=$id"); $message = 'Order deleted.'; $messageType = 'success'; }
    }
}

// Filters
$search  = sanitize($_GET['search'] ?? '');
$fStatus = sanitize($_GET['status'] ?? '');
$fType   = sanitize($_GET['type']   ?? '');

$where = "WHERE 1";
if ($user['role'] === 'patient') $where .= " AND p.user_id={$user['id']}";
if ($search)  $where .= " AND (o.order_number LIKE '%$search%' OR CONCAT(p.first_name,' ',p.last_name) LIKE '%$search%')";
if ($fStatus) $where .= " AND o.status='$fStatus'";
if ($fType)   $where .= " AND o.order_type='$fType'";

$orders = [];
$res = $db->query("SELECT o.*, CONCAT(p.first_name,' ',p.last_name) as patient_name, p.phone as patient_phone, CONCAT(u.first_name,' ',u.last_name) as created_by_name, rx.rx_number FROM orders o LEFT JOIN patients p ON o.patient_id=p.id LEFT JOIN users u ON o.created_by=u.id LEFT JOIN prescriptions rx ON o.prescription_id=rx.id $where ORDER BY o.created_at DESC");
while ($row = $res->fetch_assoc()) $orders[] = $row;

$patients = [];
$res2 = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name FROM patients WHERE status='active' ORDER BY first_name");
while ($row = $res2->fetch_assoc()) $patients[] = $row;

$products = [];
$res3 = $db->query("SELECT p.id, p.name, p.price, p.product_type, i.quantity FROM products p LEFT JOIN inventory i ON p.id=i.product_id WHERE p.status='active' ORDER BY p.name");
while ($row = $res3->fetch_assoc()) $products[] = $row;

$prescriptions = [];
$res4 = $db->query("SELECT rx.id, rx.rx_number, CONCAT(p.first_name,' ',p.last_name) as patient_name FROM prescriptions rx LEFT JOIN patients p ON rx.patient_id=p.id WHERE rx.status IN ('Issued','Pending') ORDER BY rx.created_at DESC");
while ($row = $res4->fetch_assoc()) $prescriptions[] = $row;

// Stats
$totalOrders    = $db->query("SELECT COUNT(*) as c FROM orders")->fetch_assoc()['c'];
$pendingOrders  = $db->query("SELECT COUNT(*) as c FROM orders WHERE status='Pending'")->fetch_assoc()['c'];
$processingOrders = $db->query("SELECT COUNT(*) as c FROM orders WHERE status='Processing'")->fetch_assoc()['c'];
$completedOrders = $db->query("SELECT COUNT(*) as c FROM orders WHERE status='Completed'")->fetch_assoc()['c'];

$statusColors = ['Pending'=>'warning','Processing'=>'info','Completed'=>'success','Cancelled'=>'danger'];
$typeColors   = ['walk-in'=>'teal','online'=>'blue','reservation'=>'orange'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Orders — RS Pharmacy</title>
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
                <div class="page-breadcrumb">RS Pharmacy / <span>Orders</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">Order Management</div><div class="page-subtitle">Track walk-in, online, and reservation orders</div></div>
                <?php if (in_array($user['role'],['admin','pharmacist','staff'])): ?>
                <div style="display:flex;gap:8px;">
                    <a href="pos.php" class="btn btn-outline"><i class="fas fa-cash-register"></i> Open POS</a>
                    <button class="btn btn-primary" onclick="openOrderModal()"><i class="fas fa-plus"></i> New Order</button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($message): ?><div class="alert alert-<?=$messageType?>" data-auto-dismiss="4000"><i class="fas fa-info-circle"></i> <?=$message?></div><?php endif; ?>

            <!-- Stats -->
            <div class="stat-grid stat-grid-4" style="margin-bottom:20px;">
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-shopping-bag"></i></div><div class="stat-value"><?=$totalOrders?></div><div class="stat-label">Total Orders</div><div class="stat-progress"><div class="stat-progress-bar teal" style="width:80%"></div></div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-clock"></i></div><div class="stat-value"><?=$pendingOrders?></div><div class="stat-label">Pending</div><div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?=min(100,$pendingOrders*10)?>%"></div></div></div>
                <div class="stat-card blue"><div class="stat-icon blue"><i class="fas fa-spinner"></i></div><div class="stat-value"><?=$processingOrders?></div><div class="stat-label">Processing</div><div class="stat-progress"><div class="stat-progress-bar blue" style="width:<?=min(100,$processingOrders*10)?>%"></div></div></div>
                <div class="stat-card green"><div class="stat-icon green"><i class="fas fa-circle-check"></i></div><div class="stat-value"><?=$completedOrders?></div><div class="stat-label">Completed</div><div class="stat-progress"><div class="stat-progress-bar green" style="width:<?=min(100,$completedOrders*5)?>%"></div></div></div>
            </div>

            <!-- Filters & Table -->
            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="table-toolbar-left">
                        <div class="search-bar"><i class="fas fa-search"></i><input type="text" id="orderSearch" placeholder="Search order #, patient..." value="<?=htmlspecialchars($search)?>"></div>
                        <select class="filter-select" id="statusFilter">
                            <option value="">All Status</option>
                            <?php foreach(['Pending','Processing','Completed','Cancelled'] as $s): ?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=$s?></option><?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="typeFilter">
                            <option value="">All Types</option>
                            <?php foreach(['walk-in','online','reservation'] as $t): ?><option value="<?=$t?>" <?=$fType===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="table-toolbar-right"><span class="text-muted"><?=count($orders)?> orders</span></div>
                </div>

                <div class="table-responsive">
                    <table id="ordersTable">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Patient</th>
                                <th>Type</th>
                                <th>Rx</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Created By</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $o):
                                // Fetch items summary
                                $items = $db->query("SELECT COUNT(*) as cnt, COALESCE(SUM(subtotal),0) as total FROM order_items WHERE order_id={$o['id']}")->fetch_assoc();
                                $itemCount = $items['cnt'] ?? 0;
                                $orderTotal = $items['total'] ?? 0;
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700;font-family:monospace;font-size:13px;"><?=sanitize($o['order_number']??'—')?></div>
                                    <div style="font-size:11px;color:var(--gray);"><?=formatDate($o['created_at'])?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600;"><?=sanitize($o['patient_name']??'—')?></div>
                                    <div style="font-size:11px;color:var(--gray);"><?=sanitize($o['patient_phone']??'')?></div>
                                </td>
                                <td><span class="badge badge-<?=$typeColors[$o['order_type']]??'gray'?>"><?=ucfirst($o['order_type'])?></span></td>
                                <td>
                                    <?php if($o['rx_number']): ?>
                                    <span class="badge badge-teal" style="font-family:monospace;"><?=sanitize($o['rx_number'])?></span>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-gray"><?=$itemCount?> item<?=$itemCount!=1?'s':''?></span>
                                </td>
                                <td style="font-weight:700;color:var(--primary);"><?=formatCurrency($orderTotal)?></td>
                                <td style="font-size:12px;color:var(--gray);"><?=sanitize($o['created_by_name']??'—')?></td>
                                <td class="text-muted"><?=formatDate($o['created_at'])?></td>
                                <td><span class="badge badge-<?=$statusColors[$o['status']]??'gray'?>"><?=$o['status']?></span></td>
                                <td>
                                    <div class="action-links">
                                        <button class="action-link action-view" onclick='viewOrder(<?=$o['id']?>,<?=json_encode($o, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-eye"></i></button>
                                        <?php if (in_array($user['role'],['admin','pharmacist','staff'])): ?>
                                        <button class="action-link action-edit" onclick='editOrder(<?=json_encode($o, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-pen"></i></button>
                                        <?php if ($o['status']==='Pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?=$o['id']?>">
                                            <input type="hidden" name="status" value="Processing">
                                            <button type="submit" class="action-link action-approve" title="Mark Processing"><i class="fas fa-play"></i></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($o['status']==='Processing'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?=$o['id']?>">
                                            <input type="hidden" name="status" value="Completed">
                                            <button type="submit" class="action-link action-fulfill" title="Mark Completed"><i class="fas fa-check"></i></button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this order?')">
                                            <input type="hidden" name="action" value="delete_order">
                                            <input type="hidden" name="delete_id" value="<?=$o['id']?>">
                                            <button type="submit" class="action-link action-delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($orders)): ?><tr><td colspan="10"><div class="empty-state"><i class="fas fa-shopping-bag"></i><p>No orders found</p></div></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Order Modal -->
<div class="modal-backdrop" id="orderModal">
    <div class="modal modal-xl">
        <div class="modal-header">
            <div class="modal-title" id="orderModalTitle"><i class="fas fa-shopping-bag"></i> New Order</div>
            <button class="modal-close" onclick="closeModal('orderModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="orderForm">
            <input type="hidden" name="action" value="save_order">
            <input type="hidden" name="edit_id" id="ordEditId" value="0">
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                    <div>
                        <div class="form-group"><label class="form-label">Patient *</label>
                            <select name="patient_id" id="ordPatient" class="form-control" required>
                                <option value="">Select Patient</option>
                                <?php foreach($patients as $pt): ?><option value="<?=$pt['id']?>"><?=sanitize($pt['name'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Order Type</label>
                                <select name="order_type" id="ordType" class="form-control">
                                    <?php foreach(['walk-in','online','reservation'] as $t): ?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label class="form-label">Status</label>
                                <select name="status" id="ordStatus" class="form-control">
                                    <?php foreach(['Pending','Processing','Completed','Cancelled'] as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="form-group"><label class="form-label">Linked Prescription</label>
                            <select name="prescription_id" id="ordRx" class="form-control">
                                <option value="">None</option>
                                <?php foreach($prescriptions as $rx): ?><option value="<?=$rx['id']?>"><?=sanitize($rx['rx_number'])?> — <?=sanitize($rx['patient_name'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="ordNotes" class="form-control" rows="2" placeholder="Special instructions..."></textarea></div>
                    </div>
                </div>

                <!-- Order Items -->
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);">Order Items</h4>
                        <button type="button" class="btn btn-teal btn-sm" onclick="addOrderItem()"><i class="fas fa-plus"></i> Add Item</button>
                    </div>
                    <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:8px 10px;margin-bottom:6px;display:grid;grid-template-columns:2fr 100px 120px 100px 32px;gap:8px;">
                        <?php foreach(['Product','Qty','Unit Price','Discount',''] as $h): ?><span style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;"><?=$h?></span><?php endforeach; ?>
                    </div>
                    <div id="orderItemRows"></div>
                    <div style="text-align:right;margin-top:10px;font-size:15px;font-weight:700;color:var(--primary);">
                        Total: <span id="ordTotal">₱0.00</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('orderModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Order</button>
            </div>
        </form>
    </div>
</div>

<!-- View Order Modal -->
<div class="modal-backdrop" id="viewOrderModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-shopping-bag"></i> Order Details</div>
            <button class="modal-close" onclick="closeModal('viewOrderModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="viewOrderBody"></div>
        <div class="modal-footer" id="viewOrderFooter">
            <button class="btn btn-outline" onclick="closeModal('viewOrderModal')">Close</button>
        </div>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
const allProducts = <?=json_encode(array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name'],'price'=>$p['price'],'stock'=>$p['quantity']],  $products))?>;
let itemRowCount = 0;

document.getElementById('orderSearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#ordersTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
});
document.getElementById('statusFilter').addEventListener('change', applyFilters);
document.getElementById('typeFilter').addEventListener('change', applyFilters);
function applyFilters() {
    const s = document.getElementById('statusFilter').value;
    const t = document.getElementById('typeFilter').value;
    const q = document.getElementById('orderSearch').value;
    window.location.href = `?status=${s}&type=${t}&search=${q}`;
}

function openOrderModal() {
    document.getElementById('orderModalTitle').innerHTML = '<i class="fas fa-shopping-bag"></i> New Order';
    document.getElementById('ordEditId').value = '0';
    document.getElementById('orderForm').reset();
    document.getElementById('orderItemRows').innerHTML = '';
    itemRowCount = 0;
    addOrderItem();
    openModal('orderModal');
}

function addOrderItem(data = {}) {
    const idx = itemRowCount++;
    const opts = allProducts.map(p => `<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock}" ${data.product_id==p.id?'selected':''}>${p.name} (${p.stock} in stock)</option>`).join('');
    const row = document.createElement('div');
    row.id = `ord-row-${idx}`;
    row.style.cssText = 'display:grid;grid-template-columns:2fr 100px 120px 100px 32px;gap:8px;margin-bottom:6px;align-items:center;';
    row.innerHTML = `
        <select name="product_id[]" class="form-control" style="font-size:12px;" onchange="fillPrice(this,${idx})" required>
            <option value="">Select Product</option>${opts}
        </select>
        <input type="number" name="quantity[]" id="ord-qty-${idx}" class="form-control" style="font-size:12px;" value="${data.quantity||1}" min="1" oninput="recalcTotal()">
        <div class="input-group"><span class="input-prefix" style="padding:6px 8px;font-size:11px;">₱</span><input type="number" name="unit_price[]" id="ord-price-${idx}" class="form-control" style="font-size:12px;" value="${data.unit_price||''}" step="0.01" min="0" oninput="recalcTotal()"></div>
        <div class="input-group"><span class="input-prefix" style="padding:6px 8px;font-size:11px;">₱</span><input type="number" name="discount[]" class="form-control" style="font-size:12px;" value="${data.discount||0}" step="0.01" min="0" oninput="recalcTotal()"></div>
        <button type="button" onclick="document.getElementById('ord-row-${idx}').remove();recalcTotal();" style="background:none;border:none;cursor:pointer;color:var(--danger);"><i class="fas fa-trash"></i></button>
    `;
    document.getElementById('orderItemRows').appendChild(row);
}

function fillPrice(select, idx) {
    const opt = select.options[select.selectedIndex];
    const price = opt.dataset.price || 0;
    const priceInput = document.getElementById(`ord-price-${idx}`);
    if (priceInput) priceInput.value = price;
    recalcTotal();
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('[name="quantity[]"]').forEach((qtyEl, i) => {
        const price = parseFloat(document.querySelectorAll('[name="unit_price[]"]')[i]?.value) || 0;
        const disc  = parseFloat(document.querySelectorAll('[name="discount[]"]')[i]?.value) || 0;
        const qty   = parseInt(qtyEl.value) || 0;
        total += (qty * price) - disc;
    });
    document.getElementById('ordTotal').textContent = '₱' + Math.max(0, total).toFixed(2);
}

function editOrder(o) {
    document.getElementById('orderModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Order';
    document.getElementById('ordEditId').value = o.id;
    document.getElementById('ordPatient').value = o.patient_id || '';
    document.getElementById('ordType').value = o.order_type || 'walk-in';
    document.getElementById('ordStatus').value = o.status || 'Pending';
    document.getElementById('ordRx').value = o.prescription_id || '';
    document.getElementById('ordNotes').value = o.notes || '';
    document.getElementById('orderItemRows').innerHTML = '';
    itemRowCount = 0;
    addOrderItem();
    openModal('orderModal');
}

function viewOrder(orderId, o) {
    const statusClass = {Pending:'warning',Processing:'info',Completed:'success',Cancelled:'danger'};
    const typeClass   = {'walk-in':'teal','online':'blue','reservation':'orange'};

    document.getElementById('viewOrderBody').innerHTML = `
        <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:16px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div>
                    <div style="font-weight:800;font-size:18px;font-family:monospace;">${o.order_number||'—'}</div>
                    <div style="font-size:12px;color:var(--gray);">${o.created_at||''}</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <span class="badge badge-${typeClass[o.order_type]||'gray'}">${o.order_type||''}</span>
                    <span class="badge badge-${statusClass[o.status]||'gray'}">${o.status||''}</span>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">
                <div><span style="color:var(--gray);">Patient: </span><strong>${o.patient_name||'—'}</strong></div>
                <div><span style="color:var(--gray);">Phone: </span><strong>${o.patient_phone||'—'}</strong></div>
                <div><span style="color:var(--gray);">Created By: </span><strong>${o.created_by_name||'—'}</strong></div>
                ${o.rx_number ? `<div><span style="color:var(--gray);">Rx: </span><span class="badge badge-teal" style="font-family:monospace;">${o.rx_number}</span></div>` : ''}
            </div>
            ${o.notes ? `<div style="margin-top:10px;font-size:12px;color:var(--gray);font-style:italic;"><i class="fas fa-sticky-note"></i> ${o.notes}</div>` : ''}
        </div>
        <div id="orderItemsLoading" style="text-align:center;padding:20px;color:var(--gray);">Loading items...</div>
    `;

    document.getElementById('viewOrderFooter').innerHTML = `
        <form method="POST" style="display:flex;gap:8px;flex:1;">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" value="${o.id}">
            <select name="status" class="filter-select" style="flex:1;">
                ${['Pending','Processing','Completed','Cancelled'].map(s=>`<option value="${s}" ${s===o.status?'selected':''}>${s}</option>`).join('')}
            </select>
            <button type="submit" class="btn btn-teal btn-sm"><i class="fas fa-check"></i> Update</button>
        </form>
        <button class="btn btn-outline" onclick="closeModal('viewOrderModal')">Close</button>
    `;

    openModal('viewOrderModal');

    // Load items via fetch
    fetch(`api/get_order_items.php?order_id=${orderId}`)
        .then(r => r.json())
        .then(data => {
            if (data.items && data.items.length > 0) {
                let html = '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr>';
                ['Product','Qty','Unit Price','Discount','Subtotal'].forEach(h => html += `<th style="padding:8px;background:var(--gray-ultra);font-size:11px;font-weight:700;text-transform:uppercase;color:var(--gray);text-align:left;">${h}</th>`);
                html += '</tr></thead><tbody>';
                let grandTotal = 0;
                data.items.forEach(item => {
                    grandTotal += parseFloat(item.subtotal);
                    html += `<tr style="border-bottom:1px solid var(--gray-ultra);">
                        <td style="padding:8px;font-weight:600;">${item.product_name}</td>
                        <td style="padding:8px;">${item.quantity}</td>
                        <td style="padding:8px;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td style="padding:8px;color:var(--success);">-₱${parseFloat(item.discount||0).toFixed(2)}</td>
                        <td style="padding:8px;font-weight:700;color:var(--primary);">₱${parseFloat(item.subtotal).toFixed(2)}</td>
                    </tr>`;
                });
                html += `</tbody><tfoot><tr><td colspan="4" style="padding:10px;text-align:right;font-weight:700;">Grand Total</td><td style="padding:10px;font-weight:800;font-size:16px;color:var(--primary);">₱${grandTotal.toFixed(2)}</td></tr></tfoot></table>`;
                document.getElementById('orderItemsLoading').outerHTML = html;
            } else {
                document.getElementById('orderItemsLoading').innerHTML = '<div class="empty-state" style="padding:20px;"><i class="fas fa-box-open"></i><p>No items in this order</p></div>';
            }
        })
        .catch(() => { document.getElementById('orderItemsLoading').innerHTML = '<p style="color:var(--danger);text-align:center;">Failed to load items.</p>'; });
}
</script>
</body>
</html>
