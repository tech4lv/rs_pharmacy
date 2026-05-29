<?php
$user = getCurrentUser();
$notifCount = getNotificationCount($user['id']);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <i class="fas fa-pills"></i>
        </div>
        <div class="brand-text">
            <span class="brand-name">RS Pharmacy</span>
            <span class="brand-sub">Management System</span>
        </div>
    </div>

    <div class="sidebar-user">
        <div class="user-avatar">
            <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
        </div>
        <div class="user-info">
            <span class="user-name"><?= sanitize($user['first_name'] . ' ' . $user['last_name']) ?></span>
            <span class="user-role badge-role-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
        </div>
    </div>

    <div class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="fas fa-chart-grid-2"></i><span>Dashboard</span>
        </a>

        <?php if ($user['role'] === 'admin'): ?>
        <div class="nav-section-label">Management</div>
        <a href="products.php" class="nav-item <?= $currentPage === 'products' ? 'active' : '' ?>">
            <i class="fas fa-box-open"></i><span>Products</span>
        </a>
        <a href="inventory.php" class="nav-item <?= $currentPage === 'inventory' ? 'active' : '' ?>">
            <i class="fas fa-warehouse"></i><span>Product Management</span>
        </a>
        <a href="stock_supply.php" class="nav-item <?= $currentPage === 'stock_supply' ? 'active' : '' ?>">
            <i class="fas fa-truck"></i><span>Stock Supply</span>
        </a>
        <a href="orders.php" class="nav-item <?= $currentPage === 'orders' ? 'active' : '' ?>">
            <i class="fas fa-shopping-bag"></i><span>Orders</span>
        </a>
        <a href="transactions.php" class="nav-item <?= $currentPage === 'transactions' ? 'active' : '' ?>">
            <i class="fas fa-receipt"></i><span>Transactions</span>
        </a>

        <div class="nav-section-label">Clinical</div>
        <a href="patients.php" class="nav-item <?= $currentPage === 'patients' ? 'active' : '' ?>">
            <i class="fas fa-user-injured"></i><span>Patients</span>
        </a>
        <a href="appointments.php" class="nav-item <?= $currentPage === 'appointments' ? 'active' : '' ?>">
            <i class="fas fa-calendar-check"></i><span>Appointments</span>
        </a>
        <a href="prescriptions.php" class="nav-item <?= $currentPage === 'prescriptions' ? 'active' : '' ?>">
            <i class="fas fa-file-prescription"></i><span>Prescriptions</span>
        </a>
        <a href="pos.php" class="nav-item <?= $currentPage === 'pos' ? 'active' : '' ?>">
            <i class="fas fa-cash-register"></i><span>Point of Sale</span>
        </a>

        <div class="nav-section-label">Analytics</div>
        <a href="sales_analytics.php" class="nav-item <?= $currentPage === 'sales_analytics' ? 'active' : '' ?>">
            <i class="fas fa-chart-line"></i><span>Sales Analytics</span>
        </a>

        <div class="nav-section-label">System</div>
        <a href="users.php" class="nav-item <?= $currentPage === 'users' ? 'active' : '' ?>">
            <i class="fas fa-users-cog"></i><span>User Management</span>
        </a>
        <a href="audit_logs.php" class="nav-item <?= $currentPage === 'audit_logs' ? 'active' : '' ?>">
            <i class="fas fa-shield-check"></i><span>Audit Logs</span>
        </a>

        <?php elseif ($user['role'] === 'staff'): ?>
        <div class="nav-section-label">Operations</div>
        <a href="products.php" class="nav-item <?= $currentPage === 'products' ? 'active' : '' ?>">
            <i class="fas fa-box-open"></i><span>Products</span>
        </a>
        <a href="pos.php" class="nav-item <?= $currentPage === 'pos' ? 'active' : '' ?>">
            <i class="fas fa-cash-register"></i><span>POS</span>
        </a>
        <a href="transactions.php" class="nav-item <?= $currentPage === 'transactions' ? 'active' : '' ?>">
            <i class="fas fa-receipt"></i><span>Transactions</span>
        </a>
        <a href="appointments.php" class="nav-item <?= $currentPage === 'appointments' ? 'active' : '' ?>">
            <i class="fas fa-calendar-check"></i><span>Appointments</span>
        </a>
        <a href="patients.php" class="nav-item <?= $currentPage === 'patients' ? 'active' : '' ?>">
            <i class="fas fa-user-injured"></i><span>Patients</span>
        </a>
        <a href="prescriptions.php" class="nav-item <?= $currentPage === 'prescriptions' ? 'active' : '' ?>">
            <i class="fas fa-file-prescription"></i><span>Prescriptions</span>
        </a>

        <?php elseif ($user['role'] === 'pharmacist'): ?>
        <div class="nav-section-label">Operations</div>
        <a href="products.php" class="nav-item <?= $currentPage === 'products' ? 'active' : '' ?>">
            <i class="fas fa-box-open"></i><span>Products</span>
        </a>
        <a href="orders.php" class="nav-item <?= $currentPage === 'orders' ? 'active' : '' ?>">
            <i class="fas fa-shopping-bag"></i><span>Orders</span>
        </a>
        <a href="stock_supply.php" class="nav-item <?= $currentPage === 'stock_supply' ? 'active' : '' ?>">
            <i class="fas fa-truck"></i><span>Stock Supply</span>
        </a>
        <a href="inventory.php" class="nav-item <?= $currentPage === 'inventory' ? 'active' : '' ?>">
            <i class="fas fa-warehouse"></i><span>Product Management</span>
        </a>
        <a href="prescriptions.php" class="nav-item <?= $currentPage === 'prescriptions' ? 'active' : '' ?>">
            <i class="fas fa-file-prescription"></i><span>Prescriptions</span>
        </a>

        <?php elseif ($user['role'] === 'patient'): ?>
        <div class="nav-section-label">My Account</div>
        <a href="appointments.php" class="nav-item <?= $currentPage === 'appointments' ? 'active' : '' ?>">
            <i class="fas fa-calendar-check"></i><span>Appointments</span>
        </a>
        <?php endif; ?>

        <div class="nav-section-label">Account</div>
        <a href="logout.php" class="nav-item text-danger-nav">
            <i class="fas fa-right-from-bracket"></i><span>Logout</span>
        </a>
    </div>
</nav>
