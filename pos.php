<?php
require_once 'includes/auth.php';
requireRole(['admin','pharmacist','staff'], 'login.php');
$user = getCurrentUser();
$db = getDB();

$message = '';

// Handle checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    $cartJson     = $_POST['cart_json'] ?? '[]';
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'Cash');
    $patientName  = sanitize($_POST['patient_name'] ?? 'Walk-in Customer');
    $discount     = (float)($_POST['discount'] ?? 0);

    $cart = json_decode($cartJson, true);
    if (!empty($cart)) {
        // Create order
        $orderNum = 'ORD-' . strtoupper(substr(uniqid(), -6));
        $stmt = $db->prepare("INSERT INTO orders (order_number, order_type, status, created_by) VALUES (?, 'walk-in', 'Completed', ?)");
        $stmt->bind_param('si', $orderNum, $user['id']);
        $stmt->execute();
        $orderId = $db->insert_id;

        $total = 0;
        foreach ($cart as $item) {
            $productId = (int)$item['id'];
            $qty       = (int)$item['quantity'];
            $price     = (float)$item['price'];
            $subtotal  = $qty * $price;
            $total    += $subtotal;

            // Insert order item
            $stmt2 = $db->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt2->bind_param('iisdid', $orderId, $productId, $item['name'], $qty, $price, $subtotal);
            $stmt2->execute();

            // Deduct stock
            $db->query("UPDATE inventory SET quantity = GREATEST(0, quantity - $qty) WHERE product_id = $productId");
            // Log movement
            $stmt3 = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_id, reference_type, user_id, notes) VALUES (?, 'out', ?, ?, 'order', ?, 'POS Sale')");
            $stmt3->bind_param('iiii', $productId, $qty, $orderId, $user['id']);
            $stmt3->execute();
        }

        $totalAfterDiscount = $total - $discount;
        $receiptNum = 'R-' . strtoupper(substr(uniqid(), -6));
        $txnId = 'TXN-' . strtoupper(substr(uniqid(), -6));

        $stmt4 = $db->prepare("INSERT INTO transactions (transaction_id, order_id, cashier_id, subtotal, discount, total_amount, payment_method, payment_status, channel, receipt_number) VALUES (?, ?, ?, ?, ?, ?, ?, 'Completed', 'POS', ?)");
        $stmt4->bind_param('siidddss', $txnId, $orderId, $user['id'], $total, $discount, $totalAfterDiscount, $paymentMethod, $receiptNum);
        $stmt4->execute();

        logAudit($user['id'], "POS Sale: $receiptNum — Total ₱$totalAfterDiscount", 'SALE', 'transactions', $db->insert_id, null, null, 'INFO');

        echo json_encode(['success' => true, 'receipt' => $receiptNum, 'total' => $totalAfterDiscount, 'order_id' => $orderId]);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Cart is empty']);
    exit;
}

// Fetch products for POS
$search = sanitize($_GET['search'] ?? '');
$typeFilter = sanitize($_GET['type'] ?? '');

$where = "WHERE p.status='active' AND (i.quantity IS NULL OR i.quantity > 0)";
if ($search)     $where .= " AND (p.name LIKE '%$search%' OR p.generic_name LIKE '%$search%')";
if ($typeFilter) $where .= " AND p.product_type='$typeFilter'";

$products = [];
$res = $db->query("SELECT p.*, c.name as category_name, i.quantity, i.expiry_date FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN inventory i ON p.id=i.product_id $where ORDER BY p.name");
while ($row = $res->fetch_assoc()) $products[] = $row;

$patients = [];
$res2 = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name FROM patients WHERE status='active' ORDER BY first_name");
while ($row = $res2->fetch_assoc()) $patients[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Point of Sale — RS Pharmacy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .pos-layout { display: grid; grid-template-columns: 1fr 360px; gap: 20px; }
        @media (max-width: 900px) { .pos-layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="topbar-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <div class="page-breadcrumb">RS Pharmacy / <span>Point of Sale</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>

        <div class="page-content">
            <div class="page-header">
                <div>
                    <div class="page-title">Point of Sale</div>
                    <div class="page-subtitle">Process in-store transactions quickly and efficiently</div>
                </div>
                <a href="products.php" class="btn btn-outline"><i class="fas fa-box-open"></i> Add Product</a>
            </div>

            <div class="pos-layout">
                <!-- Products Panel -->
                <div>
                    <div class="card" style="margin-bottom:16px;">
                        <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;">
                            <div class="search-bar" style="flex:1;min-width:200px;">
                                <i class="fas fa-search"></i>
                                <input type="text" id="posSearch" placeholder="Search medicines..." oninput="filterProducts(this.value)">
                            </div>
                            <select class="filter-select" onchange="filterByType(this.value)">
                                <option value="">All Types</option>
                                <option value="OTC">OTC</option>
                                <option value="Rx">Rx Only</option>
                            </select>
                        </div>
                    </div>

                    <div class="pos-product-grid" id="productGrid">
                        <?php foreach ($products as $p):
                            $expired = $p['expiry_date'] && strtotime($p['expiry_date']) < time();
                            if ($expired) continue;
                        ?>
                        <div class="pos-product-card" data-name="<?= strtolower(sanitize($p['name'])) ?>" data-type="<?= $p['product_type'] ?>" data-product='<?= htmlspecialchars(json_encode(['id'=>$p['id'],'name'=>$p['name'],'price'=>$p['price'],'stock'=>$p['quantity'],'type'=>$p['product_type']], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>'>
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                                <span class="badge badge-<?= $p['product_type']==='OTC'?'info':'teal' ?>"><?= $p['product_type'] ?></span>
                                <span style="font-size:10px;color:var(--gray);"><?= sanitize($p['shelf_location'] ?? '') ?></span>
                            </div>
                            <div class="pos-product-name"><?= sanitize($p['name']) ?></div>
                            <div style="font-size:11px;color:var(--gray);margin-bottom:8px;"><?= sanitize($p['generic_name'] ?? '') ?> · <?= sanitize($p['dosage_form'] ?? '') ?></div>
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <div class="pos-product-price"><?= formatCurrency($p['price']) ?></div>
                                <div class="pos-product-stock"><?= $p['quantity'] ?? 0 ?> in stock</div>
                            </div>
                            <button type="button" class="btn btn-teal btn-sm btn-add w-100" style="margin-top:10px;justify-content:center;">
                                <i class="fas fa-cart-plus"></i> Add to Cart
                            </button>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?>
                        <div class="empty-state" style="grid-column:1/-1;"><i class="fas fa-box-open"></i><p>No products available</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Cart Panel -->
                <div>
                    <div class="cart-panel">
                        <div class="cart-header">
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <div class="cart-title"><i class="fas fa-shopping-cart" style="color:var(--primary);margin-right:8px;"></i> Cart</div>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="clearCart()"><i class="fas fa-trash"></i> Clear</button>
                            </div>
                        </div>

                        <div class="cart-items" id="cartItems">
                            <div class="cart-empty-state" id="cartEmpty">
                                <i class="fas fa-shopping-cart"></i>
                                <p>Your cart is empty<br><small>Click a product to add it</small></p>
                            </div>
                        </div>

                        <div class="cart-footer">
                            <div style="padding:12px 0;border-top:1px solid var(--gray-ultra);">
                                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                                    <span>Subtotal</span><span id="cartSubtotal">₱0.00</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                                    <span>Discount</span>
                                    <div class="input-group" style="width:100px;">
                                        <span class="input-prefix" style="padding:4px 8px;font-size:12px;">₱</span>
                                        <input type="number" id="discountInput" value="0" min="0" step="0.01" class="form-control" style="padding:4px 6px;font-size:12px;height:auto;" oninput="updateTotal()">
                                    </div>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                                    <span>Amount</span>
                                    <div class="input-group" style="width:120px;">
                                        <span class="input-prefix" style="padding:4px 8px;font-size:12px;">₱</span>
                                        <input type="number" id="tenderedInput" value="0" min="0" step="0.01" class="form-control" style="padding:4px 6px;font-size:12px;height:auto;" oninput="updateTotal()">
                                    </div>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                                    <span>Change</span><span id="changeAmount">₱0.00</span>
                                </div>
                                <div class="cart-total">
                                    <span>Total</span><span id="cartTotal">₱0.00</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Patient Name</label>
                                <input type="text" id="patientName" class="form-control" placeholder="Walk-in Customer" list="patientsList">
                                <datalist id="patientsList">
                                    <?php foreach ($patients as $pt): ?>
                                    <option value="<?= sanitize($pt['name']) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Payment Method</label>
                                <select id="paymentMethod" class="form-control">
                                    <option value="Cash">Cash</option>
                                    <option value="Card">Card</option>
                                    <option value="GCash">GCash</option>
                                </select>
                            </div>

                            <button type="button" class="btn btn-primary w-100" style="justify-content:center;height:44px;" onclick="processCheckout()">
                                <i class="fas fa-credit-card"></i> Pay & Print Receipt
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal-backdrop" id="receiptModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-receipt"></i> Transaction Complete</div>
            <button class="modal-close" onclick="closeModal('receiptModal');clearCart()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="receiptBody">
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <button class="btn btn-primary" onclick="closeModal('receiptModal');clearCart()"><i class="fas fa-check"></i> Done</button>
        </div>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
let cart = {};

function addToCart(product) {
    const id = String(product.id);
    const price = parseFloat(product.price) || 0;
    const stock = product.stock === null ? null : Number(product.stock);
    if (stock !== null && stock <= 0) { showToast('This product is out of stock.', 'error'); return; }
    if (cart[id]) {
        if (stock !== null && cart[id].quantity >= stock) { showToast('Cannot add more — insufficient stock.', 'warning'); return; }
        cart[id].quantity++;
    } else {
        cart[id] = { ...product, id, price, stock, quantity: 1 };
    }
    renderCart();
    showToast(`${product.name} added to cart`, 'success');
}

function addToCartFromElement(el) {
    if (!el) return;
    const raw = el.getAttribute('data-product');
    if (!raw) {
        console.error('addToCartFromElement: data-product missing', el);
        showToast('Product data missing. Open console for details.', 'error');
        return;
    }

    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch (e) {
        console.error('addToCartFromElement: failed to parse data-product', e, raw);
        showToast('Unable to read product data.', 'error');
        return;
    }

    const rawId = parsed.id ?? parsed.product_id ?? parsed.pid;
    const product = {
        id: rawId == null ? null : String(rawId),
        name: parsed.name ?? parsed.product_name ?? 'Product',
        price: parseFloat(parsed.price) || 0,
        stock: parsed.stock === '' ? null : (parsed.stock == null ? null : Number(parsed.stock)),
        type: parsed.type || ''
    };

    if (!product.id) {
        console.error('addToCartFromElement: missing product id', parsed, el);
        showToast('Product id is missing. Open console for details.', 'error');
        return;
    }

    console.debug('Adding product', product);
    addToCart(product);
}

function removeFromCart(id) {
    delete cart[id];
    renderCart();
}

function updateQty(id, delta) {
    if (!cart[id]) return;
    cart[id].quantity += delta;
    if (cart[id].quantity <= 0) { removeFromCart(id); return; }
    if (cart[id].stock !== null && cart[id].quantity > cart[id].stock) { cart[id].quantity = cart[id].stock; showToast('Insufficient stock', 'warning'); }
    renderCart();
}

function renderCart() {
    const items = Object.values(cart);
    const container = document.getElementById('cartItems');
    const emptyHtml = `
        <div class="cart-empty-state" id="cartEmpty">
            <i class="fas fa-shopping-cart"></i>
            <p>Your cart is empty<br><small>Click a product to add it</small></p>
        </div>
    `;

    if (items.length === 0) {
        container.innerHTML = emptyHtml;
        updateTotal();
        return;
    }

    let html = '';
    items.forEach(item => {
        html += `<div class="cart-item" data-id="${item.id}">
            <div class="cart-item-name">${item.name}</div>
            <div class="cart-qty-controls">
                <button type="button" class="cart-qty-btn btn-decrease" data-id="${item.id}">−</button>
                <span class="cart-qty-num">${item.quantity}</span>
                <button type="button" class="cart-qty-btn btn-increase" data-id="${item.id}">+</button>
            </div>
            <div class="cart-item-price">₱${(item.price * item.quantity).toFixed(2)}</div>
            <button type="button" class="btn-remove" data-id="${item.id}" style="background:none;border:none;color:var(--gray);cursor:pointer;padding:2px 4px;"><i class="fas fa-times"></i></button>
        </div>`;
    });
    container.innerHTML = html;
    updateTotal();
}

// Delegated listeners for product cards and cart buttons
document.addEventListener('DOMContentLoaded', function() {
    const grid = document.getElementById('productGrid');
    if (grid) {
        grid.addEventListener('click', function(e) {
            const addButton = e.target.closest('.btn-add');
            if (!addButton) return;
            const card = addButton.closest('.pos-product-card');
            if (!card) return;
            e.preventDefault();
            addToCartFromElement(card);
        });
    }

    const cartContainer = document.getElementById('cartItems');
    if (cartContainer) {
        cartContainer.addEventListener('click', function(e) {
            const decrease = e.target.closest('.btn-decrease');
            if (decrease) { e.preventDefault(); updateQty(decrease.dataset.id, -1); return; }
            const increase = e.target.closest('.btn-increase');
            if (increase) { e.preventDefault(); updateQty(increase.dataset.id, 1); return; }
            const remove = e.target.closest('.btn-remove');
            if (remove) { e.preventDefault(); removeFromCart(remove.dataset.id); }
        });
    }
});

function updateTotal() {
    const items = Object.values(cart);
    const subtotal = items.reduce((sum, item) => sum + item.price * item.quantity, 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const tendered = parseFloat(document.getElementById('tenderedInput').value) || 0;
    const total = Math.max(0, subtotal - discount);
    const change = tendered - total;

    document.getElementById('cartSubtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('cartTotal').textContent = '₱' + total.toFixed(2);
    document.getElementById('changeAmount').textContent = '₱' + change.toFixed(2);
}

function clearCart() {
    cart = {};
    renderCart();
    document.getElementById('discountInput').value = 0;
    document.getElementById('tenderedInput').value = 0;
    document.getElementById('patientName').value = '';
}

function filterProducts(val) {
    val = val.toLowerCase();
    document.querySelectorAll('.pos-product-card').forEach(card => {
        card.style.display = card.dataset.name.includes(val) ? '' : 'none';
    });
}

function filterByType(type) {
    document.querySelectorAll('.pos-product-card').forEach(card => {
        card.style.display = (!type || card.dataset.type === type) ? '' : 'none';
    });
}

async function processCheckout() {
    const items = Object.values(cart);
    if (items.length === 0) { showToast('Cart is empty!', 'error'); return; }

    const tendered = parseFloat(document.getElementById('tenderedInput').value) || 0;
    const subtotal = items.reduce((s, i) => s + i.price * i.quantity, 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const total = Math.max(0, subtotal - discount);
    const paymentMethod = document.getElementById('paymentMethod').value;

    if (paymentMethod === 'Cash' && tendered < total) {
        showToast('Amount tendered must cover total for cash payments.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'checkout');
    formData.append('cart_json', JSON.stringify(items));
    formData.append('payment_method', paymentMethod);
    formData.append('patient_name', document.getElementById('patientName').value || 'Walk-in Customer');
    formData.append('discount', discount);
    formData.append('amount_tendered', tendered);

    try {
        const res = await fetch('pos.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const patient = document.getElementById('patientName').value || 'Walk-in Customer';
            const method  = document.getElementById('paymentMethod').value;
            const discount = parseFloat(document.getElementById('discountInput').value) || 0;
            const tendered = parseFloat(document.getElementById('tenderedInput').value) || 0;
            const subtotal = items.reduce((s, i) => s + i.price * i.quantity, 0);
            const change = tendered - data.total;

            let itemsHtml = items.map(i => `<tr><td>${i.name}</td><td style="text-align:right;">${i.quantity}</td><td style="text-align:right;">₱${(i.price*i.quantity).toFixed(2)}</td></tr>`).join('');

            document.getElementById('receiptBody').innerHTML = `
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="font-size:28px;">💊</div>
                    <div style="font-weight:700;font-size:17px;">RS Pharmacy</div>
                    <div style="color:var(--gray);font-size:12px;">Official Receipt</div>
                </div>
                <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:16px;font-size:12px;">
                    <div style="display:flex;justify-content:space-between;"><span>Receipt No.</span><strong>${data.receipt}</strong></div>
                    <div style="display:flex;justify-content:space-between;"><span>Date</span><strong>${new Date().toLocaleString()}</strong></div>
                    <div style="display:flex;justify-content:space-between;"><span>Customer</span><strong>${patient}</strong></div>
                    <div style="display:flex;justify-content:space-between;"><span>Cashier</span><strong><?= sanitize($user['first_name'].' '.$user['last_name']) ?></strong></div>
                    <div style="display:flex;justify-content:space-between;"><span>Payment</span><strong>${method}</strong></div>
                </div>
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead><tr style="border-bottom:1px solid var(--gray-light);">
                        <th style="padding:6px 0;text-align:left;">Item</th>
                        <th style="padding:6px 0;text-align:right;">Qty</th>
                        <th style="padding:6px 0;text-align:right;">Amount</th>
                    </tr></thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
                <div style="border-top:1px solid var(--gray-light);margin-top:12px;padding-top:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;"><span>Subtotal</span><span>₱${subtotal.toFixed(2)}</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;"><span>Discount</span><span>-₱${discount.toFixed(2)}</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;"><span>Amount Tendered</span><span>₱${tendered.toFixed(2)}</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;"><span>Change</span><span>₱${change.toFixed(2)}</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:700;color:var(--primary);"><span>TOTAL</span><span>₱${data.total.toFixed(2)}</span></div>
                </div>
                <div style="text-align:center;margin-top:16px;font-size:11px;color:var(--gray);">Thank you for your purchase!<br>Stay healthy with RS Pharmacy.</div>
            `;
            clearCart();
            openModal('receiptModal');
        } else {
            showToast(data.message || 'Checkout failed', 'error');
        }
    } catch (e) {
        showToast('An error occurred. Please try again.', 'error');
    }
}
</script>
</body>
</html>
