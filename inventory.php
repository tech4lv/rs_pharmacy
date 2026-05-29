<?php
require_once 'includes/auth.php';
requireRole(['admin','pharmacist'], 'login.php');
$user = getCurrentUser();
$db   = getDB();

$message = ''; $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'stock_in') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty       = (int)($_POST['quantity'] ?? 0);
        $batchNo   = sanitize($_POST['batch_number'] ?? '');
        $expiryDate = $_POST['expiry_date'] ?? '';
        $notes     = sanitize($_POST['notes'] ?? '');

        if ($productId && $qty > 0) {
            // Update or insert inventory
            $existing = $db->query("SELECT id FROM inventory WHERE product_id=$productId")->fetch_assoc();
            if ($existing) {
                $db->query("UPDATE inventory SET quantity=quantity+$qty, batch_number='$batchNo', expiry_date='$expiryDate', updated_at=NOW() WHERE product_id=$productId");
            } else {
                $stmt = $db->prepare("INSERT INTO inventory (product_id, batch_number, quantity, expiry_date) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('isis', $productId, $batchNo, $qty, $expiryDate);
                $stmt->execute();
            }
            // Log movement
            $stmt2 = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, user_id, notes) VALUES (?, 'in', ?, ?, ?)");
            $stmt2->bind_param('iiis', $productId, $qty, $user['id'], $notes);
            $stmt2->execute();
            $prod = $db->query("SELECT name FROM products WHERE id=$productId")->fetch_assoc();
            logAudit($user['id'], "Stock IN: {$prod['name']} +$qty units", 'STOCK_IN', 'inventory', $productId, null, null, 'INFO');
            $message = "Stock added successfully ($qty units)!"; $messageType = 'success';
        } else { $message = 'Invalid product or quantity.'; $messageType = 'danger'; }

    } elseif ($action === 'stock_out') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty       = (int)($_POST['quantity'] ?? 0);
        $notes     = sanitize($_POST['notes'] ?? '');

        if ($productId && $qty > 0) {
            $inv = $db->query("SELECT quantity FROM inventory WHERE product_id=$productId")->fetch_assoc();
            if ($inv && $inv['quantity'] >= $qty) {
                $db->query("UPDATE inventory SET quantity=quantity-$qty, updated_at=NOW() WHERE product_id=$productId");
                $stmt2 = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, user_id, notes) VALUES (?, 'out', ?, ?, ?)");
                $stmt2->bind_param('iiis', $productId, $qty, $user['id'], $notes);
                $stmt2->execute();
                $prod = $db->query("SELECT name FROM products WHERE id=$productId")->fetch_assoc();
                logAudit($user['id'], "Stock OUT: {$prod['name']} -$qty units", 'STOCK_OUT', 'inventory', $productId, null, null, 'WARNING');
                $message = "Stock removed ($qty units)."; $messageType = 'success';
            } else { $message = 'Insufficient stock.'; $messageType = 'danger'; }
        }

    } elseif ($action === 'adjust_reorder') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $level     = (int)($_POST['reorder_level'] ?? 10);
        if ($productId) {
            $db->query("UPDATE inventory SET reorder_level=$level WHERE product_id=$productId");
            $message = 'Reorder level updated.'; $messageType = 'success';
        }
    }
}

// Fetch inventory
$search = sanitize($_GET['search'] ?? '');
$filter = sanitize($_GET['filter'] ?? '');

$where = "WHERE p.status='active'";
if ($search) $where .= " AND (p.name LIKE '%$search%' OR p.generic_name LIKE '%$search%')";
if ($filter === 'low')      $where .= " AND i.quantity <= i.reorder_level AND i.quantity > 0";
elseif ($filter === 'out')  $where .= " AND (i.quantity IS NULL OR i.quantity = 0)";
elseif ($filter === 'expiring') $where .= " AND i.expiry_date IS NOT NULL AND i.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
elseif ($filter === 'expired')  $where .= " AND i.expiry_date IS NOT NULL AND i.expiry_date < CURDATE()";

$inventory = [];
$res = $db->query("SELECT p.id as product_id, p.name, p.generic_name, p.dosage_form, p.price, p.shelf_location, c.name as category, i.id as inv_id, i.quantity, i.reorder_level, i.expiry_date, i.batch_number, i.manufacturing_date, i.storage_location FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN inventory i ON p.id=i.product_id $where ORDER BY i.quantity ASC, p.name");
while ($row = $res->fetch_assoc()) $inventory[] = $row;

// Stats
$totalItems   = $db->query("SELECT COUNT(*) as c FROM inventory")->fetch_assoc()['c'];
$lowStock     = $db->query("SELECT COUNT(*) as c FROM inventory WHERE quantity <= reorder_level AND quantity > 0")->fetch_assoc()['c'];
$outOfStock   = $db->query("SELECT COUNT(*) as c FROM inventory WHERE quantity = 0")->fetch_assoc()['c'];
$expiring90   = $db->query("SELECT COUNT(*) as c FROM inventory WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)")->fetch_assoc()['c'];
$expired      = $db->query("SELECT COUNT(*) as c FROM inventory WHERE expiry_date < CURDATE()")->fetch_assoc()['c'];
$totalValue   = $db->query("SELECT COALESCE(SUM(i.quantity * p.price),0) as c FROM inventory i JOIN products p ON i.product_id=p.id")->fetch_assoc()['c'];

// Stock movements recent
$movements = [];
$res2 = $db->query("SELECT sm.*, p.name as product_name, CONCAT(u.first_name,' ',u.last_name) as user_name FROM stock_movements sm JOIN products p ON sm.product_id=p.id LEFT JOIN users u ON sm.user_id=u.id ORDER BY sm.created_at DESC LIMIT 10");
while ($row = $res2->fetch_assoc()) $movements[] = $row;

$allProducts = [];
$res3 = $db->query("SELECT id, name, generic_name FROM products WHERE status='active' ORDER BY name");
while ($row = $res3->fetch_assoc()) $allProducts[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inventory — RS Pharmacy</title>
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
                <div class="page-breadcrumb">RS Pharmacy / <span>Product Management</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">Product Management</div><div class="page-subtitle">Monitor and manage inventory stock levels</div></div>
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-teal" onclick="openModal('stockInModal')"><i class="fas fa-arrow-up"></i> Stock In</button>
                    <button class="btn btn-outline" onclick="openModal('stockOutModal')"><i class="fas fa-arrow-down"></i> Stock Out</button>
                </div>
            </div>

            <?php if ($message): ?><div class="alert alert-<?=$messageType?>" data-auto-dismiss="4000"><i class="fas fa-<?=$messageType==='success'?'check-circle':'exclamation-circle'?>"></i> <?=$message?></div><?php endif; ?>

            <!-- Stats -->
            <div class="stat-grid stat-grid-6" style="margin-bottom:20px;">
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-boxes-stacked"></i></div><div class="stat-value"><?=$totalItems?></div><div class="stat-label">Total Items</div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-value"><?=$lowStock?></div><div class="stat-label">Low Stock</div></div>
                <div class="stat-card red"><div class="stat-icon red"><i class="fas fa-ban"></i></div><div class="stat-value"><?=$outOfStock?></div><div class="stat-label">Out of Stock</div></div>
                <div class="stat-card warning"><div class="stat-icon orange"><i class="fas fa-calendar-minus"></i></div><div class="stat-value"><?=$expiring90?></div><div class="stat-label">Expiring Soon</div></div>
                <div class="stat-card danger"><div class="stat-icon red"><i class="fas fa-calendar-xmark"></i></div><div class="stat-value"><?=$expired?></div><div class="stat-label">Expired</div></div>
                <div class="stat-card green"><div class="stat-icon green"><i class="fas fa-peso-sign"></i></div><div class="stat-value"><?=formatCurrency($totalValue)?></div><div class="stat-label">Total Value</div></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 280px;gap:20px;">
                <!-- Inventory Table -->
                <div>
                    <!-- Filters -->
                    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
                        <?php foreach([['','All Items','boxes-stacked'],['low','Low Stock','triangle-exclamation'],['out','Out of Stock','ban'],['expiring','Expiring Soon','calendar-minus'],['expired','Expired','calendar-xmark']] as [$v,$l,$i]): ?>
                        <a href="?filter=<?=$v?>&search=<?=urlencode($search)?>" class="btn <?=$filter===$v?'btn-dark':'btn-outline'?> btn-sm"><i class="fas fa-<?=$i?>"></i> <?=$l?></a>
                        <?php endforeach; ?>
                        <div class="search-bar" style="flex:1;min-width:180px;">
                            <i class="fas fa-search"></i>
                            <input type="text" id="invSearch" placeholder="Search inventory..." value="<?=htmlspecialchars($search)?>">
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <div class="table-responsive">
                            <table id="inventoryTable">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Batch</th>
                                        <th>Location</th>
                                        <th>Stock</th>
                                        <th>Reorder</th>
                                        <th>Expiry</th>
                                        <th>Value</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory as $item):
                                        $qty = (int)($item['quantity'] ?? 0);
                                        $reorder = (int)($item['reorder_level'] ?? 10);
                                        $status = getStockStatus($qty, $reorder);
                                        $expired = $item['expiry_date'] && strtotime($item['expiry_date']) < time();
                                        $expiring = !$expired && $item['expiry_date'] && strtotime($item['expiry_date']) < strtotime('+90 days');
                                        $pct = min(100, $reorder > 0 ? ($qty / ($reorder * 3)) * 100 : ($qty > 0 ? 100 : 0));
                                        $value = $qty * $item['price'];
                                    ?>
                                    <tr style="<?=$expired?'background:rgba(255,59,48,0.03);':($expiring?'background:rgba(255,159,10,0.03);':'')?>">
                                        <td>
                                            <div style="font-weight:600;"><?=sanitize($item['name'])?></div>
                                            <div style="font-size:11px;color:var(--gray);"><?=sanitize($item['generic_name']??'')?> · <?=sanitize($item['dosage_form']??'')?> · <?=sanitize($item['category']??'')?></div>
                                        </td>
                                        <td class="text-muted"><?=sanitize($item['batch_number']??'—')?></td>
                                        <td class="text-muted"><?=sanitize($item['shelf_location']??$item['storage_location']??'—')?></td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <span style="font-weight:700;font-size:16px;color:<?=$status['class']==='danger'?'var(--danger)':($status['class']==='warning'?'var(--warning)':'var(--black)')?>;"><?=$qty?></span>
                                                <span style="font-size:11px;color:var(--gray);">units</span>
                                            </div>
                                            <div style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                                                <div class="stock-bar-wrap"><div class="stock-bar" style="width:<?=$pct?>%;background:<?=$status['class']==='danger'?'var(--danger)':($status['class']==='warning'?'var(--warning)':'var(--success)')?>"></div></div>
                                                <span class="badge badge-<?=$status['class']?>"><?=$status['label']?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:6px;">
                                                <span><?=$reorder?> units</span>
                                                <button class="action-link action-edit" onclick="adjustReorder(<?=$item['product_id']?>,<?=$reorder?>)" style="padding:3px 6px;"><i class="fas fa-pen"></i></button>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if($item['expiry_date']): ?>
                                            <span class="badge badge-<?=$expired?'danger':($expiring?'warning':'success')?>"><?=formatDate($item['expiry_date'])?></span>
                                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                        </td>
                                        <td style="font-weight:600;"><?=formatCurrency($value)?></td>
                                        <td>
                                            <div class="action-links">
                                                <button class="action-link action-approve" onclick="quickStockIn(<?=$item['product_id']?>, '<?=addslashes(sanitize($item['name']))?>')" title="Add Stock"><i class="fas fa-plus"></i></button>
                                                <button class="action-link action-delete" onclick="quickStockOut(<?=$item['product_id']?>, '<?=addslashes(sanitize($item['name']))?>')" title="Remove Stock"><i class="fas fa-minus"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($inventory)): ?><tr><td colspan="8"><div class="empty-state"><i class="fas fa-boxes-stacked"></i><p>No inventory records found</p></div></td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Side Panel -->
                <div>
                    <!-- Alerts -->
                    <?php if ($lowStock > 0 || $outOfStock > 0 || $expired > 0): ?>
                    <div class="card" style="margin-bottom:16px;">
                        <div class="card-header"><div class="card-title" style="color:var(--danger);"><i class="fas fa-bell"></i> Alerts</div></div>
                        <div class="card-body" style="padding:12px;">
                            <?php if($outOfStock > 0): ?><div style="display:flex;align-items:center;gap:8px;padding:8px;background:rgba(255,59,48,0.06);border-radius:var(--radius-sm);margin-bottom:6px;"><i class="fas fa-ban" style="color:var(--danger);"></i><div><div style="font-size:12px;font-weight:600;color:var(--danger);"><?=$outOfStock?> Out of Stock</div><div style="font-size:11px;color:var(--gray);">Immediate reorder needed</div></div></div><?php endif; ?>
                            <?php if($lowStock > 0): ?><div style="display:flex;align-items:center;gap:8px;padding:8px;background:rgba(255,159,10,0.06);border-radius:var(--radius-sm);margin-bottom:6px;"><i class="fas fa-triangle-exclamation" style="color:var(--warning);"></i><div><div style="font-size:12px;font-weight:600;color:var(--warning);"><?=$lowStock?> Low Stock</div><div style="font-size:11px;color:var(--gray);">Below reorder level</div></div></div><?php endif; ?>
                            <?php if($expired > 0): ?><div style="display:flex;align-items:center;gap:8px;padding:8px;background:rgba(255,59,48,0.06);border-radius:var(--radius-sm);margin-bottom:6px;"><i class="fas fa-calendar-xmark" style="color:var(--danger);"></i><div><div style="font-size:12px;font-weight:600;color:var(--danger);"><?=$expired?> Expired Items</div><div style="font-size:11px;color:var(--gray);">Remove from shelf</div></div></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Stock Movements -->
                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="fas fa-history" style="color:var(--teal);margin-right:6px;"></i> Recent Movements</div></div>
                        <div class="card-body" style="padding:12px;max-height:420px;overflow-y:auto;">
                            <?php foreach ($movements as $m):
                                $isIn = $m['movement_type'] === 'in';
                            ?>
                            <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--gray-ultra);">
                                <div style="width:26px;height:26px;border-radius:50%;background:<?=$isIn?'rgba(48,209,88,0.1)':'rgba(255,59,48,0.1)'?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-arrow-<?=$isIn?'up':'down'?>" style="font-size:10px;color:<?=$isIn?'var(--success)':'var(--danger)'?>;"></i>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=sanitize($m['product_name'])?></div>
                                    <div style="font-size:11px;color:var(--gray);"><?=$m['user_name']??'System'?> · <?=formatDate($m['created_at'])?></div>
                                    <?php if($m['notes']): ?><div style="font-size:10px;color:var(--gray);font-style:italic;"><?=sanitize(substr($m['notes'],0,30))?></div><?php endif; ?>
                                </div>
                                <div style="font-weight:700;font-size:13px;color:<?=$isIn?'var(--success)':'var(--danger)'?>;flex-shrink:0;"><?=$isIn?'+':'-'?><?=$m['quantity']?></div>
                            </div>
                            <?php endforeach; ?>
                            <?php if(empty($movements)): ?><div class="empty-state" style="padding:20px;"><i class="fas fa-history"></i><p>No movements yet</p></div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stock In Modal -->
<div class="modal-backdrop" id="stockInModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" style="color:var(--success);"><i class="fas fa-arrow-up"></i> Stock In</div>
            <button class="modal-close" onclick="closeModal('stockInModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="stock_in">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Product *</label>
                    <select name="product_id" id="siProduct" class="form-control" required>
                        <option value="">Select Product</option>
                        <?php foreach($allProducts as $p): ?><option value="<?=$p['id']?>"><?=sanitize($p['name'])?> <?=$p['generic_name']?'('.$p['generic_name'].')':''?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group"><label class="form-label">Quantity *</label><input type="number" name="quantity" class="form-control" min="1" required placeholder="0"></div>
                    <div class="form-group"><label class="form-label">Batch Number</label><input type="text" name="batch_number" class="form-control" placeholder="BATCH-XXXX"></div>
                </div>
                <div class="form-group"><label class="form-label">Expiry Date</label><input type="date" name="expiry_date" class="form-control"></div>
                <div class="form-group"><label class="form-label">Notes / Reason</label><textarea name="notes" class="form-control" rows="2" placeholder="e.g. Delivery from PharmaCo"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('stockInModal')">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-arrow-up"></i> Add Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Stock Out Modal -->
<div class="modal-backdrop" id="stockOutModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" style="color:var(--danger);"><i class="fas fa-arrow-down"></i> Stock Out</div>
            <button class="modal-close" onclick="closeModal('stockOutModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="stock_out">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Product *</label>
                    <select name="product_id" id="soProduct" class="form-control" required>
                        <option value="">Select Product</option>
                        <?php foreach($allProducts as $p): ?><option value="<?=$p['id']?>"><?=sanitize($p['name'])?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Quantity *</label><input type="number" name="quantity" class="form-control" min="1" required placeholder="0"></div>
                <div class="form-group"><label class="form-label">Reason *</label>
                    <select name="notes" class="form-control">
                        <option value="Expired — Disposed">Expired — Disposed</option>
                        <option value="Damaged — Removed">Damaged — Removed</option>
                        <option value="Returned to Supplier">Returned to Supplier</option>
                        <option value="Manual Adjustment">Manual Adjustment</option>
                        <option value="Sample / Testing">Sample / Testing</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('stockOutModal')">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-arrow-down"></i> Remove Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Reorder Level Modal -->
<div class="modal-backdrop" id="reorderModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-sliders"></i> Adjust Reorder Level</div>
            <button class="modal-close" onclick="closeModal('reorderModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="adjust_reorder">
            <input type="hidden" name="product_id" id="roProductId">
            <div class="modal-body">
                <p style="color:var(--gray);font-size:13px;margin-bottom:16px;">Set the minimum stock level at which a reorder alert is triggered.</p>
                <div class="form-group"><label class="form-label">Reorder Level (units)</label><input type="number" name="reorder_level" id="roLevel" class="form-control" min="0" required></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('reorderModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
document.getElementById('invSearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#inventoryTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
});

function quickStockIn(id, name) {
    document.getElementById('siProduct').value = id;
    openModal('stockInModal');
}

function quickStockOut(id, name) {
    document.getElementById('soProduct').value = id;
    openModal('stockOutModal');
}

function adjustReorder(productId, currentLevel) {
    document.getElementById('roProductId').value = productId;
    document.getElementById('roLevel').value = currentLevel;
    openModal('reorderModal');
}
</script>
</body>
</html>
