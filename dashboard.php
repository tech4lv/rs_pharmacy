<?php
require_once 'includes/auth.php';
requireLogin('login.php');
$user = getCurrentUser();
$db = getDB();

// Fetch stats
$totalUsers    = $db->query("SELECT COUNT(*) as c FROM users WHERE status='active'")->fetch_assoc()['c'];
$totalOrders   = $db->query("SELECT COUNT(*) as c FROM orders")->fetch_assoc()['c'];
$totalAppts    = $db->query("SELECT COUNT(*) as c FROM appointments")->fetch_assoc()['c'];
$totalPatients = $db->query("SELECT COUNT(*) as c FROM patients")->fetch_assoc()['c'];
$lowStock      = $db->query("SELECT COUNT(*) as c FROM inventory i WHERE i.quantity <= i.reorder_level")->fetch_assoc()['c'];
$pendingRx     = $db->query("SELECT COUNT(*) as c FROM prescriptions WHERE status='Pending'")->fetch_assoc()['c'];
$todayRevenue  = $db->query("SELECT COALESCE(SUM(total_amount),0) as c FROM transactions WHERE DATE(created_at)=CURDATE() AND payment_status='Completed'")->fetch_assoc()['c'];
$monthRevenue  = $db->query("SELECT COALESCE(SUM(total_amount),0) as c FROM transactions WHERE MONTH(created_at)=MONTH(CURDATE()) AND payment_status='Completed'")->fetch_assoc()['c'];
$notifCount    = getNotificationCount($user['id']);

// Recent products
$products = [];
$res = $db->query("SELECT p.*, c.name as category, i.quantity, i.reorder_level, i.expiry_date FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN inventory i ON p.id=i.product_id WHERE p.status='active' ORDER BY p.created_at DESC LIMIT 6");
while ($row = $res->fetch_assoc()) $products[] = $row;

// Recent users
$recentUsers = [];
$res2 = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
while ($row = $res2->fetch_assoc()) $recentUsers[] = $row;

// Audit logs
$auditLogs = [];
$res3 = $db->query("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 5");
while ($row = $res3->fetch_assoc()) $auditLogs[] = $row;

// Today appointments
$todayAppts = [];
$res4 = $db->query("SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name FROM appointments a LEFT JOIN patients p ON a.patient_id=p.id WHERE a.appointment_date=CURDATE() LIMIT 5");
while ($row = $res4->fetch_assoc()) $todayAppts[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — RS Pharmacy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="topbar-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <div class="page-breadcrumb">RS Pharmacy / <span>Dashboard</span></div>
            </div>
            <div class="topbar-right">
                <div class="topbar-search">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search...">
                </div>
                <a href="notifications.php" class="topbar-btn">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?>
                </a>
                <a href="logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>

        <div class="page-content">
            <div class="page-header">
                <div>
                    <div class="page-title">Good <?= date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening') ?>, <?= sanitize($user['first_name']) ?>! 👋</div>
                    <div class="page-subtitle">Here's what's happening at RS Pharmacy today, <?= date('F j, Y') ?></div>
                </div>
                <?php if ($user['role'] !== 'patient'): ?>
                <div style="display:flex;gap:8px;">
                    <a href="pos.php" class="btn btn-primary"><i class="fas fa-cash-register"></i> Open POS</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($user['role'] === 'admin'): ?>
            <!-- Admin Stats -->
            <div class="stat-grid stat-grid-4">
                <div class="stat-card red">
                    <div class="stat-icon red"><i class="fas fa-users"></i></div>
                    <div class="stat-value"><?= $totalUsers ?></div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-progress"><div class="stat-progress-bar red" style="width:70%"></div></div>
                </div>
                <div class="stat-card teal">
                    <div class="stat-icon teal"><i class="fas fa-shopping-bag"></i></div>
                    <div class="stat-value"><?= $totalOrders ?></div>
                    <div class="stat-label">Total Orders</div>
                    <div class="stat-progress"><div class="stat-progress-bar teal" style="width:55%"></div></div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div>
                    <div class="stat-value"><?= $totalAppts ?></div>
                    <div class="stat-label">Appointments</div>
                    <div class="stat-progress"><div class="stat-progress-bar blue" style="width:40%"></div></div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon orange"><i class="fas fa-bell"></i></div>
                    <div class="stat-value"><?= $notifCount ?></div>
                    <div class="stat-label">Notifications</div>
                    <div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?= min(100, $notifCount * 20) ?>%"></div></div>
                </div>
            </div>

            <div class="stat-grid stat-grid-4">
                <div class="stat-card green">
                    <div class="stat-icon green"><i class="fas fa-peso-sign"></i></div>
                    <div class="stat-value"><?= formatCurrency($todayRevenue) ?></div>
                    <div class="stat-label">Today's Revenue</div>
                    <div class="stat-progress"><div class="stat-progress-bar green" style="width:65%"></div></div>
                </div>
                <div class="stat-card teal">
                    <div class="stat-icon teal"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-value"><?= formatCurrency($monthRevenue) ?></div>
                    <div class="stat-label">Month Revenue</div>
                    <div class="stat-progress"><div class="stat-progress-bar teal" style="width:75%"></div></div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon orange"><i class="fas fa-boxes-stacked"></i></div>
                    <div class="stat-value"><?= $lowStock ?></div>
                    <div class="stat-label">Low Stock Items</div>
                    <div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?= min(100, $lowStock * 15) ?>%"></div></div>
                </div>
                <div class="stat-card red">
                    <div class="stat-icon red"><i class="fas fa-file-prescription"></i></div>
                    <div class="stat-value"><?= $pendingRx ?></div>
                    <div class="stat-label">Pending Rx</div>
                    <div class="stat-progress"><div class="stat-progress-bar red" style="width:<?= min(100, $pendingRx * 20) ?>%"></div></div>
                </div>
            </div>

            <?php elseif ($user['role'] === 'pharmacist'): ?>
            <div class="stat-grid stat-grid-3">
                <div class="stat-card teal">
                    <div class="stat-icon teal"><i class="fas fa-shopping-bag"></i></div>
                    <div class="stat-value"><?= $db->query("SELECT COUNT(*) as c FROM orders WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?></div>
                    <div class="stat-label">Today's Orders</div>
                    <div class="stat-progress"><div class="stat-progress-bar teal" style="width:60%"></div></div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon orange"><i class="fas fa-file-prescription"></i></div>
                    <div class="stat-value"><?= $pendingRx ?></div>
                    <div class="stat-label">Pending Prescriptions</div>
                    <div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?= min(100, $pendingRx * 25) ?>%"></div></div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-icon blue"><i class="fas fa-calendar-day"></i></div>
                    <div class="stat-value"><?= count($todayAppts) ?></div>
                    <div class="stat-label">Appointments Today</div>
                    <div class="stat-progress"><div class="stat-progress-bar blue" style="width:<?= min(100, count($todayAppts) * 20) ?>%"></div></div>
                </div>
            </div>

            <?php elseif ($user['role'] === 'staff'): ?>
            <div class="stat-grid stat-grid-3">
                <div class="stat-card red">
                    <div class="stat-icon red"><i class="fas fa-walking"></i></div>
                    <div class="stat-value"><?= $db->query("SELECT COUNT(*) as c FROM orders WHERE order_type='walk-in' AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'] ?></div>
                    <div class="stat-label">Walk-in Orders Today</div>
                    <div class="stat-progress"><div class="stat-progress-bar red" style="width:50%"></div></div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                    <div class="stat-value"><?= $db->query("SELECT COUNT(*) as c FROM orders WHERE status='Pending'")->fetch_assoc()['c'] ?></div>
                    <div class="stat-label">Pending Orders</div>
                    <div class="stat-progress"><div class="stat-progress-bar orange" style="width:40%"></div></div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-icon blue"><i class="fas fa-bell"></i></div>
                    <div class="stat-value"><?= $notifCount ?></div>
                    <div class="stat-label">Notifications</div>
                    <div class="stat-progress"><div class="stat-progress-bar blue" style="width:<?= min(100, $notifCount * 20) ?>%"></div></div>
                </div>
            </div>

            <?php else: // Patient ?>
            <div class="stat-grid stat-grid-3">
                <div class="stat-card teal">
                    <div class="stat-icon teal"><i class="fas fa-shopping-bag"></i></div>
                    <div class="stat-value"><?= $db->query("SELECT COUNT(*) as c FROM orders o JOIN patients p ON o.patient_id=p.id WHERE p.user_id={$user['id']}")->fetch_assoc()['c'] ?></div>
                    <div class="stat-label">My Orders</div>
                    <div class="stat-progress"><div class="stat-progress-bar teal" style="width:60%"></div></div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div>
                    <div class="stat-value"><?= $db->query("SELECT COUNT(*) as c FROM appointments a JOIN patients p ON a.patient_id=p.id WHERE p.user_id={$user['id']} AND a.status='Confirmed'")->fetch_assoc()['c'] ?></div>
                    <div class="stat-label">Upcoming Appointments</div>
                    <div class="stat-progress"><div class="stat-progress-bar blue" style="width:40%"></div></div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon orange"><i class="fas fa-file-prescription"></i></div>
                    <div class="stat-value"><?= $db->query("SELECT COUNT(*) as c FROM prescriptions pr JOIN patients p ON pr.patient_id=p.id WHERE p.user_id={$user['id']} AND pr.status='Issued'")->fetch_assoc()['c'] ?></div>
                    <div class="stat-label">Active Prescriptions</div>
                    <div class="stat-progress"><div class="stat-progress-bar orange" style="width:30%"></div></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Main Content Grid -->
            <div style="display:grid;grid-template-columns:<?= $user['role'] === 'patient' ? '1fr' : '1fr 1fr' ?>;gap:20px;margin-bottom:20px;">
                <?php if ($user['role'] !== 'patient'): ?>
                <!-- Product Management -->
                <div class="table-wrapper">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-box-open" style="color:var(--teal);margin-right:8px;"></i> Product Management</div>
                        <a href="products.php" class="btn btn-outline btn-sm">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Stock</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $p):
                                    $status = getStockStatus($p['quantity'] ?? 0, $p['reorder_level'] ?? 10);
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;font-size:13px;"><?= sanitize($p['name']) ?></div>
                                        <div class="text-muted"><?= sanitize($p['generic_name'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <span style="font-weight:600;color:<?= $status['class'] === 'danger' ? 'var(--danger)' : ($status['class'] === 'warning' ? 'var(--warning)' : 'var(--success)') ?>"><?= $p['quantity'] ?? 0 ?></span>
                                    </td>
                                    <td><?= formatCurrency($p['price']) ?></td>
                                    <td><span class="badge badge-<?= $status['class'] ?>"><?= $status['label'] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- User Management / Today's Appointments -->
                <?php if ($user['role'] === 'admin' || $user['role'] === 'staff'): ?>
                <div class="table-wrapper">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-users-cog" style="color:var(--primary);margin-right:8px;"></i> User Management</div>
                        <a href="users.php" class="btn btn-outline btn-sm">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>User</th><th>Role</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($recentUsers as $u): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div class="avatar avatar-sm" style="background:<?= ['admin'=>'var(--primary)','pharmacist'=>'var(--teal)','staff'=>'var(--warning)','patient'=>'var(--info)'][$u['role']] ?? 'var(--gray)' ?>"><?= strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1)) ?></div>
                                            <div>
                                                <div style="font-weight:600;font-size:13px;"><?= sanitize($u['first_name'].' '.$u['last_name']) ?></div>
                                                <div class="text-muted"><?= sanitize($u['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge badge-<?= ['admin'=>'primary','pharmacist'=>'teal','staff'=>'warning','patient'=>'info'][$u['role']] ?? 'gray' ?>"><?= ucfirst($u['role']) ?></span></td>
                                    <td><span class="badge badge-<?= $u['status']==='active' ? 'success' : 'gray' ?>"><?= ucfirst($u['status']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="table-wrapper">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-calendar-day" style="color:var(--blue);margin-right:8px;"></i> Today's Appointments</div>
                        <a href="appointments.php" class="btn btn-outline btn-sm">View All</a>
                    </div>
                    <?php if (empty($todayAppts)): ?>
                    <div class="empty-state"><i class="fas fa-calendar-times"></i><p>No appointments today</p></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>Patient</th><th>Time</th><th>Purpose</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($todayAppts as $a): ?>
                                <tr>
                                    <td style="font-weight:600;"><?= sanitize($a['patient_name'] ?? 'Unknown') ?></td>
                                    <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                                    <td class="text-muted"><?= sanitize($a['purpose'] ?? '') ?></td>
                                    <td><span class="badge badge-<?= ['Confirmed'=>'success','Pending'=>'warning','Completed'=>'info','Cancelled'=>'danger'][$a['status']] ?? 'gray' ?>"><?= $a['status'] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($user['role'] !== 'patient'): ?>
            <!-- Audit Logs -->
            <div class="table-wrapper">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-shield-check" style="color:var(--gray);margin-right:8px;"></i> Recent Audit Logs</div>
                    <a href="audit_logs.php" class="btn btn-outline btn-sm">View All</a>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Severity</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Affected Table</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditLogs as $log): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;font-size:13px;"><?= sanitize($log['action']) ?></div>
                                    <div class="text-muted"><?= sanitize($log['audit_id']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-<?= ['INFO'=>'info','WARNING'=>'warning','CRITICAL'=>'danger'][$log['severity']] ?? 'gray' ?>"><?= $log['severity'] ?></span>
                                </td>
                                <td><?= sanitize($log['user_name'] ?? '—') ?></td>
                                <td><span class="badge badge-gray"><?= ucfirst($log['user_role'] ?? '') ?></span></td>
                                <td class="text-muted"><?= sanitize($log['affected_table'] ?? '—') ?></td>
                                <td class="text-muted"><?= formatDateTime($log['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/js/main.js"></script>
</body>
</html>
