<?php
require_once 'includes/auth.php';
requireLogin('login.php');
$user = getCurrentUser();
$db = getDB();

$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $name       = sanitize($_POST['name'] ?? '');
        $genericName = sanitize($_POST['generic_name'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $brandType  = $_POST['brand_type'] ?? 'Generic';
        $productType = $_POST['product_type'] ?? 'OTC';
        $dosageForm = $_POST['dosage_form'] ?? 'Tablet';
        $price      = (float)($_POST['price'] ?? 0);
        $requiresRx = isset($_POST['requires_prescription']) ? 1 : 0;
        $shelfLoc   = sanitize($_POST['shelf_location'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $sku        = sanitize($_POST['sku'] ?? '');
        $editId     = (int)($_POST['edit_id'] ?? 0);

        // Inventory fields
        $batchNo    = sanitize($_POST['batch_number'] ?? '');
        $initQty    = (int)($_POST['initial_quantity'] ?? 0);
        $expiryDate = $_POST['expiry_date'] ?? '';
        $reorderLvl = (int)($_POST['reorder_level'] ?? 10);
        $mfgDate    = $_POST['manufacturing_date'] ?? null;

        if ($name && $price > 0) {
            if ($editId > 0) {
                $stmt = $db->prepare("UPDATE products SET name=?, generic_name=?, category_id=?, supplier_id=?, brand_type=?, product_type=?, dosage_form=?, price=?, requires_prescription=?, shelf_location=?, description=?, sku=? WHERE id=?");
                $stmt->bind_param('ssiisssdissi', $name, $genericName, $categoryId, $supplierId, $brandType, $productType, $dosageForm, $price, $requiresRx, $shelfLoc, $description, $sku, $editId);
                $stmt->execute();
                // Update inventory
                $stmt2 = $db->prepare("UPDATE inventory SET batch_number=?, quantity=?, expiry_date=?, reorder_level=?, storage_location=?, manufacturing_date=? WHERE product_id=?");
                $stmt2->bind_param('sissssi', $batchNo, $initQty, $expiryDate, $reorderLvl, $shelfLoc, $mfgDate, $editId);
                $stmt2->execute();
                logAudit($user['id'], "Product updated: $name", 'UPDATE', 'products', $editId, null, null, 'INFO');
                $message = 'Product updated successfully!';
            } else {
                // Generate SKU if empty
                if (!$sku) $sku = 'SKU-' . strtoupper(substr(uniqid(), -6));
                $stmt = $db->prepare("INSERT INTO products (name, generic_name, category_id, supplier_id, brand_type, product_type, dosage_form, price, requires_prescription, shelf_location, description, sku) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssiisssdiss', $name, $genericName, $categoryId, $supplierId, $brandType, $productType, $dosageForm, $price, $requiresRx, $shelfLoc, $description, $sku);
                $stmt->execute();
                $productId = $db->insert_id;
                // Add inventory
                $stmt2 = $db->prepare("INSERT INTO inventory (product_id, batch_number, quantity, expiry_date, reorder_level, storage_location, manufacturing_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt2->bind_param('isisiss', $productId, $batchNo, $initQty, $expiryDate, $reorderLvl, $shelfLoc, $mfgDate);
                $stmt2->execute();
                // Log stock movement
                $stmt3 = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, user_id, notes) VALUES (?, 'in', ?, 'initial', ?, 'Initial stock entry')");
                $stmt3->bind_param('iii', $productId, $initQty, $user['id']);
                $stmt3->execute();
                logAudit($user['id'], "Product added: $name", 'CREATE', 'products', $productId, null, null, 'INFO');
                $message = 'Product added successfully!';
            }
            $messageType = 'success';
        } else {
            $message = 'Please fill in all required fields.';
            $messageType = 'danger';
        }
    } elseif ($action === 'delete_product') {
        $id = (int)($_POST['delete_id'] ?? 0);
        if ($id > 0) {
            $prod = $db->query("SELECT name FROM products WHERE id=$id")->fetch_assoc();
            $db->query("DELETE FROM products WHERE id=$id");
            logAudit($user['id'], "Product deleted: {$prod['name']}", 'DELETE', 'products', $id, null, null, 'WARNING');
            $message = 'Product deleted successfully!';
            $messageType = 'success';
        }
    }
}

// Fetch data
$search     = sanitize($_GET['search'] ?? '');
$filterType = sanitize($_GET['type'] ?? '');
$filterCat  = (int)($_GET['category'] ?? 0);

$where = "WHERE p.status='active'";
if ($search)     $where .= " AND (p.name LIKE '%$search%' OR p.generic_name LIKE '%$search%' OR p.sku LIKE '%$search%')";
if ($filterType) $where .= " AND p.product_type='$filterType'";
if ($filterCat)  $where .= " AND p.category_id=$filterCat";

$products = [];
$res = $db->query("SELECT p.*, c.name as category_name, s.name as supplier_name, i.quantity, i.reorder_level, i.expiry_date, i.batch_number, i.manufacturing_date FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN suppliers s ON p.supplier_id=s.id LEFT JOIN inventory i ON p.id=i.product_id $where ORDER BY p.created_at DESC");
while ($row = $res->fetch_assoc()) $products[] = $row;

$categories = [];
$res2 = $db->query("SELECT * FROM categories WHERE status='active' ORDER BY name");
while ($row = $res2->fetch_assoc()) $categories[] = $row;

$suppliers = [];
$res3 = $db->query("SELECT * FROM suppliers WHERE status='active' ORDER BY name");
while ($row = $res3->fetch_assoc()) $suppliers[] = $row;

$totalProducts = count($products);
$lowStockCount = 0; $expiringCount = 0; $expiredCount = 0; $rxCount = 0;
foreach ($products as $p) {
    if (($p['quantity'] ?? 0) <= ($p['reorder_level'] ?? 10)) $lowStockCount++;
    if ($p['expiry_date'] && strtotime($p['expiry_date']) < strtotime('+90 days') && strtotime($p['expiry_date']) > time()) $expiringCount++;
    if ($p['expiry_date'] && strtotime($p['expiry_date']) < time()) $expiredCount++;
    if ($p['requires_prescription']) $rxCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products — RS Pharmacy</title>
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
                <div class="page-breadcrumb">RS Pharmacy / <span>Products</span></div>
            </div>
            <div class="topbar-right">
                <a href="notifications.php" class="topbar-btn"><i class="fas fa-bell"></i><?php if(getNotificationCount($user['id'])>0): ?><span class="notif-badge"><?=getNotificationCount($user['id'])?></span><?php endif;?></a>
                <a href="logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>

        <div class="page-content">
            <div class="page-header">
                <div>
                    <div class="page-title">Products & Inventory</div>
                    <div class="page-subtitle">Manage pharmacy products and inventory records</div>
                </div>
                <?php if (in_array($user['role'], ['admin','pharmacist','staff'])): ?>
                <button class="btn btn-primary" onclick="openModal('productModal')">
                    <i class="fas fa-plus"></i> New Product
                </button>
                <?php endif; ?>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>" data-auto-dismiss="4000"><i class="fas fa-<?= $messageType==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= $message ?></div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stat-grid stat-grid-5" style="margin-bottom:20px;">
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-boxes-stacked"></i></div><div class="stat-value"><?= $totalProducts ?></div><div class="stat-label">Total Products</div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-value"><?= $lowStockCount ?></div><div class="stat-label">Low/Reorder Stock</div></div>
                <div class="stat-card warning"><div class="stat-icon orange"><i class="fas fa-calendar-xmark"></i></div><div class="stat-value"><?= $expiringCount ?></div><div class="stat-label">Expiring (90 days)</div></div>
                <div class="stat-card red"><div class="stat-icon red"><i class="fas fa-ban"></i></div><div class="stat-value"><?= $expiredCount ?></div><div class="stat-label">Expired Items</div></div>
                <div class="stat-card blue"><div class="stat-icon blue"><i class="fas fa-prescription"></i></div><div class="stat-value"><?= $rxCount ?></div><div class="stat-label">Prescription Only</div></div>
            </div>

            <!-- Table -->
            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="table-toolbar-left">
                        <div class="search-bar">
                            <i class="fas fa-search"></i>
                            <input type="text" id="productSearch" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <select class="filter-select" id="typeFilter">
                            <option value="">All Types</option>
                            <option value="OTC" <?= $filterType==='OTC'?'selected':'' ?>>OTC</option>
                            <option value="Rx" <?= $filterType==='Rx'?'selected':'' ?>>Rx Only</option>
                        </select>
                        <select class="filter-select" id="categoryFilter">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $filterCat==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="table-toolbar-right">
                        <span class="text-muted"><?= $totalProducts ?> products</span>
                        <a href="?export=csv" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> Export CSV</a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="productsTable">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Shelf</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Expiry</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p):
                                $status = getStockStatus($p['quantity'] ?? 0, $p['reorder_level'] ?? 10);
                                $expired = $p['expiry_date'] && strtotime($p['expiry_date']) < time();
                                $expiring = !$expired && $p['expiry_date'] && strtotime($p['expiry_date']) < strtotime('+90 days');
                                $stockPct = min(100, (($p['quantity'] ?? 0) / max(1, ($p['reorder_level'] ?? 10) * 3)) * 100);
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?= sanitize($p['name']) ?></div>
                                    <div class="text-muted"><?= sanitize($p['generic_name'] ?? '') ?> · <?= sanitize($p['dosage_form'] ?? '') ?></div>
                                </td>
                                <td class="text-muted"><?= sanitize($p['sku'] ?? '') ?></td>
                                <td>
                                    <span class="badge badge-<?= $p['product_type']==='OTC'?'info':'teal' ?>"><?= $p['product_type'] ?></span>
                                    <span class="badge badge-gray"><?= $p['brand_type'] ?></span>
                                </td>
                                <td class="text-muted"><?= sanitize($p['category_name'] ?? '—') ?></td>
                                <td class="text-muted"><?= sanitize($p['shelf_location'] ?? '—') ?></td>
                                <td style="font-weight:700;"><?= formatCurrency($p['price']) ?></td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span style="font-weight:600;color:<?= $status['class']==='danger'?'var(--danger)':($status['class']==='warning'?'var(--warning)':'var(--black)') ?>"><?= $p['quantity'] ?? 0 ?></span>
                                        <div class="stock-bar-wrap"><div class="stock-bar" style="width:<?= $stockPct ?>%;background:<?= $status['class']==='danger'?'var(--danger)':($status['class']==='warning'?'var(--warning)':'var(--success)') ?>"></div></div>
                                    </div>
                                    <span class="badge badge-<?= $status['class'] ?>" style="margin-top:3px;"><?= $status['label'] ?></span>
                                </td>
                                <td>
                                    <?php if ($p['expiry_date']): ?>
                                    <span class="badge badge-<?= $expired?'danger':($expiring?'warning':'success') ?>"><?= formatDate($p['expiry_date']) ?></span>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <button class="action-link action-view" onclick='viewProduct(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fas fa-eye"></i></button>
                                        <?php if (in_array($user['role'], ['admin','pharmacist'])): ?>
                                        <button class="action-link action-edit" onclick='editProduct(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this product?')">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="action-link action-delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($products)): ?>
                            <tr><td colspan="9"><div class="empty-state"><i class="fas fa-box-open"></i><p>No products found</p></div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Product Modal -->
<div class="modal-backdrop" id="productModal">
    <div class="modal modal-xl">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Add New Product</div>
            <button class="modal-close" onclick="closeModal('productModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="productForm">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="edit_id" id="editId" value="0">
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                    <div>
                        <h4 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--gray);margin-bottom:16px;">Product Details</h4>
                        <div class="form-group">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="name" id="fName" class="form-control" placeholder="e.g. Paracetamol 500mg" required>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group">
                                <label class="form-label">Generic Name</label>
                                <input type="text" name="generic_name" id="fGeneric" class="form-control" placeholder="Generic name">
                            </div>
                            <div class="form-group">
                                <label class="form-label">SKU</label>
                                <input type="text" name="sku" id="fSku" class="form-control" placeholder="Auto-generated">
                            </div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group">
                                <label class="form-label">Category</label>
                                <select name="category_id" id="fCategory" class="form-control">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" id="fSupplier" class="form-control">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row form-row-3">
                            <div class="form-group">
                                <label class="form-label">Product Type</label>
                                <select name="product_type" id="fType" class="form-control">
                                    <option value="OTC">OTC</option>
                                    <option value="Rx">Rx Only</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Brand Type</label>
                                <select name="brand_type" id="fBrand" class="form-control">
                                    <option value="Generic">Generic</option>
                                    <option value="Brand">Brand</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Dosage Form</label>
                                <select name="dosage_form" id="fDosage" class="form-control">
                                    <?php foreach (['Tablet','Capsule','Syrup','Injectable','Cream','Drops','Inhaler','Patch','Other'] as $form): ?>
                                    <option value="<?= $form ?>"><?= $form ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group">
                                <label class="form-label">Price (₱) *</label>
                                <div class="input-group">
                                    <span class="input-prefix">₱</span>
                                    <input type="number" name="price" id="fPrice" class="form-control" placeholder="0.00" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Shelf Location</label>
                                <input type="text" name="shelf_location" id="fShelf" class="form-control" placeholder="e.g. A1">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="check-custom">
                                <input type="checkbox" name="requires_prescription" id="fRx">
                                <span>Requires Prescription</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <h4 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--gray);margin-bottom:16px;">Inventory Details</h4>
                        <div class="form-group">
                            <label class="form-label">Batch Number</label>
                            <input type="text" name="batch_number" id="fBatch" class="form-control" placeholder="e.g. BATCH-2024-001">
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group">
                                <label class="form-label">Initial Quantity</label>
                                <input type="number" name="initial_quantity" id="fQty" class="form-control" placeholder="0" min="0">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" id="fReorder" class="form-control" value="10" min="0">
                            </div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group">
                                <label class="form-label">Manufacturing Date</label>
                                <input type="date" name="manufacturing_date" id="fMfg" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Expiry Date</label>
                                <input type="date" name="expiry_date" id="fExpiry" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description / Notes</label>
                            <textarea name="description" id="fDesc" class="form-control" rows="4" placeholder="Product description or notes..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('productModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- View Product Modal -->
<div class="modal-backdrop" id="viewModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-box-open"></i> Product Details</div>
            <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="viewBody"></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<div class="toast-container"></div>

<script src="assets/js/main.js"></script>
<script>
// Search filter
document.getElementById('productSearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#productsTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
});

// Type/Category filters redirect
document.getElementById('typeFilter').addEventListener('change', applyFilters);
document.getElementById('categoryFilter').addEventListener('change', applyFilters);
function applyFilters() {
    const type = document.getElementById('typeFilter').value;
    const cat  = document.getElementById('categoryFilter').value;
    window.location.href = `?type=${type}&category=${cat}&search=${document.getElementById('productSearch').value}`;
}

// Edit product
function editProduct(p) {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Product';
    document.getElementById('editId').value = p.id;
    document.getElementById('fName').value = p.name || '';
    document.getElementById('fGeneric').value = p.generic_name || '';
    document.getElementById('fSku').value = p.sku || '';
    document.getElementById('fCategory').value = p.category_id || '';
    document.getElementById('fSupplier').value = p.supplier_id || '';
    document.getElementById('fType').value = p.product_type || 'OTC';
    document.getElementById('fBrand').value = p.brand_type || 'Generic';
    document.getElementById('fDosage').value = p.dosage_form || 'Tablet';
    document.getElementById('fPrice').value = p.price || '';
    document.getElementById('fShelf').value = p.shelf_location || '';
    document.getElementById('fRx').checked = p.requires_prescription == 1;
    document.getElementById('fBatch').value = p.batch_number || '';
    document.getElementById('fQty').value = p.quantity || 0;
    document.getElementById('fReorder').value = p.reorder_level || 10;
    document.getElementById('fMfg').value = p.manufacturing_date || '';
    document.getElementById('fExpiry').value = p.expiry_date || '';
    document.getElementById('fDesc').value = p.description || '';
    openModal('productModal');
}

// View product
function viewProduct(p) {
    const statusColors = { 'In Stock': 'success', 'Low Stock': 'warning', 'Out of Stock': 'danger' };
    let qty = parseInt(p.quantity) || 0;
    let reorder = parseInt(p.reorder_level) || 10;
    let label = qty <= 0 ? 'Out of Stock' : (qty <= reorder ? 'Low Stock' : 'In Stock');
    document.getElementById('viewBody').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:24px;text-align:center;margin-bottom:16px;">
                    <div style="font-size:48px;">💊</div>
                    <div style="font-weight:700;font-size:16px;margin-top:8px;">${p.name}</div>
                    <div style="color:var(--gray);font-size:13px;">${p.generic_name || ''}</div>
                    <div style="margin-top:10px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                        <span class="badge badge-${p.product_type==='OTC'?'info':'teal'}">${p.product_type}</span>
                        <span class="badge badge-gray">${p.brand_type}</span>
                        <span class="badge badge-${statusColors[label]}">${label}</span>
                    </div>
                </div>
                <div style="font-size:28px;font-weight:700;color:var(--primary);text-align:center;">₱${parseFloat(p.price).toFixed(2)}</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px;">
                ${row('SKU', p.sku)}
                ${row('Category', p.category_name)}
                ${row('Supplier', p.supplier_name)}
                ${row('Dosage Form', p.dosage_form)}
                ${row('Shelf Location', p.shelf_location)}
                ${row('Batch Number', p.batch_number)}
                ${row('Stock Quantity', `<strong>${p.quantity}</strong> units`)}
                ${row('Reorder Level', p.reorder_level + ' units')}
                ${row('Expiry Date', p.expiry_date || '—')}
                ${row('Requires Rx', p.requires_prescription == 1 ? '<span class="badge badge-teal">Yes</span>' : '<span class="badge badge-gray">No</span>')}
            </div>
        </div>
        ${p.description ? `<div style="margin-top:16px;padding:12px;background:var(--gray-ultra);border-radius:var(--radius-sm);font-size:13px;color:var(--gray);">${p.description}</div>` : ''}
    `;
    openModal('viewModal');
}

function row(label, value) {
    return `<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--gray-ultra);">
        <span style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:0.04em;">${label}</span>
        <span style="font-size:13px;font-weight:500;">${value || '—'}</span>
    </div>`;
}

// Reset modal on new
document.querySelector('[onclick="openModal(\'productModal\')"]')?.addEventListener('click', function() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add New Product';
    document.getElementById('editId').value = '0';
    document.getElementById('productForm').reset();
});
</script>
</body>
</html>
