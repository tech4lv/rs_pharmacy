<?php
require_once 'includes/auth.php';
requireRole(['admin'], 'login.php');
$user = getCurrentUser();
$db   = getDB();

$message = ''; $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_user') {
        $editId    = (int)($_POST['edit_id'] ?? 0);
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName  = sanitize($_POST['last_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      = $_POST['role'] ?? 'staff';
        $phone     = sanitize($_POST['phone'] ?? '');
        $deptId    = sanitize($_POST['department_id'] ?? '');
        $status    = $_POST['status'] ?? 'active';
        $password  = $_POST['password'] ?? '';

        if ($firstName && $lastName && $email) {
            if ($editId > 0) {
                if ($password) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET first_name=?,last_name=?,email=?,role=?,phone=?,department_id=?,status=?,password=? WHERE id=?");
                    $stmt->bind_param('ssssssssi', $firstName,$lastName,$email,$role,$phone,$deptId,$status,$hashed,$editId);
                } else {
                    $stmt = $db->prepare("UPDATE users SET first_name=?,last_name=?,email=?,role=?,phone=?,department_id=?,status=? WHERE id=?");
                    $stmt->bind_param('sssssssi', $firstName,$lastName,$email,$role,$phone,$deptId,$status,$editId);
                }
                $stmt->execute();
                // If pharmacist, update pharmacists table
                if ($role === 'pharmacist') {
                    $licNum = sanitize($_POST['license_number'] ?? '');
                    $spec   = sanitize($_POST['specialization'] ?? '');
                    $exists = $db->query("SELECT id FROM pharmacists WHERE user_id=$editId")->fetch_assoc();
                    if ($exists) {
                        $db->query("UPDATE pharmacists SET license_number='$licNum', specialization='$spec' WHERE user_id=$editId");
                    } else {
                        $db->query("INSERT INTO pharmacists (user_id, license_number, specialization) VALUES ($editId, '$licNum', '$spec')");
                    }
                }
                logAudit($user['id'],"User updated: $email",'UPDATE','users',$editId);
                $message = 'User updated!'; $messageType = 'success';
            } else {
                $check = $db->prepare("SELECT id FROM users WHERE email=?");
                $check->bind_param('s', $email); $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $message = 'Email already exists.'; $messageType = 'danger';
                } else {
                    $hashed = password_hash($password ?: 'password123', PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (first_name,last_name,email,password,role,phone,department_id,status) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('ssssssss', $firstName,$lastName,$email,$hashed,$role,$phone,$deptId,$status);
                    $stmt->execute();
                    $newId = $db->insert_id;
                    if ($role === 'pharmacist') {
                        $licNum = sanitize($_POST['license_number'] ?? '');
                        $spec   = sanitize($_POST['specialization'] ?? '');
                        $db->query("INSERT INTO pharmacists (user_id, license_number, specialization) VALUES ($newId, '$licNum', '$spec')");
                    }
                    // If patient role, create patient record
                    if ($role === 'patient') {
                        $db->query("INSERT INTO patients (user_id, first_name, last_name, phone, status) VALUES ($newId, '$firstName', '$lastName', '$phone', 'active')");
                    }
                    logAudit($user['id'],"User created: $email",'CREATE','users',$newId);
                    $message = 'User created! Default password: password123'; $messageType = 'success';
                }
            }
        } else { $message = 'Please fill required fields.'; $messageType = 'danger'; }

    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['user_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'active';
        if ($id && $id !== $user['id']) {
            $db->query("UPDATE users SET status='$newStatus' WHERE id=$id");
            $message = 'User status updated.'; $messageType = 'success';
        }
    } elseif ($action === 'delete_user') {
        $id = (int)($_POST['delete_id'] ?? 0);
        if ($id && $id !== $user['id']) {
            $u = $db->query("SELECT email FROM users WHERE id=$id")->fetch_assoc();
            $db->query("DELETE FROM users WHERE id=$id");
            logAudit($user['id'],"User deleted: {$u['email']}",'DELETE','users',$id,null,null,'WARNING');
            $message = 'User deleted.'; $messageType = 'success';
        } else { $message = 'Cannot delete your own account.'; $messageType = 'danger'; }
    }
}

// Fetch
$search     = sanitize($_GET['search'] ?? '');
$filterRole = sanitize($_GET['role'] ?? '');

$where = "WHERE 1";
if ($search)     $where .= " AND (u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%' OR u.email LIKE '%$search%')";
if ($filterRole) $where .= " AND u.role='$filterRole'";

$users = [];
$res = $db->query("SELECT u.*, ph.license_number, ph.specialization FROM users u LEFT JOIN pharmacists ph ON u.id=ph.user_id $where ORDER BY u.created_at DESC");
while ($row = $res->fetch_assoc()) $users[] = $row;

// Stats
$totalU   = $db->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$activeU  = $db->query("SELECT COUNT(*) as c FROM users WHERE status='active'")->fetch_assoc()['c'];
$pharmacU = $db->query("SELECT COUNT(*) as c FROM users WHERE role='pharmacist'")->fetch_assoc()['c'];
$patientU = $db->query("SELECT COUNT(*) as c FROM users WHERE role='patient'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>User Management — RS Pharmacy</title>
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
                <div class="page-breadcrumb">RS Pharmacy / <span>User Management</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">User Management</div><div class="page-subtitle">Manage system users, roles, and permissions</div></div>
                <button class="btn btn-primary" onclick="openUserModal()"><i class="fas fa-user-plus"></i> Add User</button>
            </div>

            <?php if ($message): ?><div class="alert alert-<?=$messageType?>" data-auto-dismiss="5000"><i class="fas fa-info-circle"></i> <?=$message?></div><?php endif; ?>

            <!-- Stats -->
            <div class="stat-grid stat-grid-4" style="margin-bottom:20px;">
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-users"></i></div><div class="stat-value"><?=$totalU?></div><div class="stat-label">Total Users</div><div class="stat-progress"><div class="stat-progress-bar teal" style="width:80%"></div></div></div>
                <div class="stat-card green"><div class="stat-icon green"><i class="fas fa-user-check"></i></div><div class="stat-value"><?=$activeU?></div><div class="stat-label">Active Users</div><div class="stat-progress"><div class="stat-progress-bar green" style="width:<?=min(100,$totalU>0?$activeU/$totalU*100:0)?>%"></div></div></div>
                <div class="stat-card blue"><div class="stat-icon blue"><i class="fas fa-user-nurse"></i></div><div class="stat-value"><?=$pharmacU?></div><div class="stat-label">Pharmacists</div><div class="stat-progress"><div class="stat-progress-bar blue" style="width:<?=min(100,$pharmacU*25)?>%"></div></div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-user-injured"></i></div><div class="stat-value"><?=$patientU?></div><div class="stat-label">Patients</div><div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?=min(100,$patientU*10)?>%"></div></div></div>
            </div>

            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="table-toolbar-left">
                        <div class="search-bar"><i class="fas fa-search"></i><input type="text" id="userSearch" placeholder="Search by name, email..." value="<?=htmlspecialchars($search)?>"></div>
                        <select class="filter-select" id="roleFilter">
                            <option value="">All Roles</option>
                            <?php foreach(['admin','pharmacist','staff','patient'] as $r): ?><option value="<?=$r?>" <?=$filterRole===$r?'selected':''?>><?=ucfirst($r)?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="table-toolbar-right">
                        <span class="text-muted"><?=count($users)?> users</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="usersTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Phone</th>
                                <th>Last Login</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u):
                                $roleColors = ['admin'=>'primary','pharmacist'=>'teal','staff'=>'warning','patient'=>'info'];
                                $roleColor  = $roleColors[$u['role']] ?? 'gray';
                                $isSelf     = $u['id'] == $user['id'];
                            ?>
                            <tr>
                                <td>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div class="avatar" style="background:<?=['admin'=>'var(--primary)','pharmacist'=>'var(--teal)','staff'=>'var(--warning)','patient'=>'var(--info)'][$u['role']]??'var(--gray)'?>;"><?=strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1))?></div>
                                        <div>
                                            <div style="font-weight:600;"><?=sanitize($u['first_name'].' '.$u['last_name'])?> <?=$isSelf?'<span class="badge badge-teal" style="font-size:9px;">YOU</span>':''?></div>
                                            <div class="text-muted"><?=sanitize($u['email'])?></div>
                                            <?php if($u['role']==='pharmacist' && $u['license_number']): ?><div style="font-size:10px;color:var(--teal);">License: <?=sanitize($u['license_number'])?></div><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge badge-<?=$roleColor?>"><?=ucfirst($u['role'])?></span></td>
                                <td class="text-muted"><?=sanitize($u['department_id']??'—')?></td>
                                <td class="text-muted"><?=sanitize($u['phone']??'—')?></td>
                                <td class="text-muted"><?=$u['last_login']?formatDateTime($u['last_login']):'Never'?></td>
                                <td>
                                    <span class="badge badge-<?=$u['status']==='active'?'success':'gray'?>"><?=ucfirst($u['status'])?></span>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <button class="action-link action-edit" onclick='editUser(<?=json_encode($u, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-pen"></i></button>
                                        <?php if (!$isSelf): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?=$u['id']?>">
                                            <input type="hidden" name="new_status" value="<?=$u['status']==='active'?'inactive':'active'?>">
                                            <button type="submit" class="action-link <?=$u['status']==='active'?'action-reject':'action-approve'?>">
                                                <i class="fas fa-<?=$u['status']==='active'?'ban':'check'?>"></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete user <?=sanitize($u['first_name'])?>?')">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="delete_id" value="<?=$u['id']?>">
                                            <button type="submit" class="action-link action-delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($users)): ?><tr><td colspan="7"><div class="empty-state"><i class="fas fa-users-slash"></i><p>No users found</p></div></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal-backdrop" id="userModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title" id="userModalTitle"><i class="fas fa-user-plus"></i> Add User</div>
            <button class="modal-close" onclick="closeModal('userModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="userForm">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="edit_id" id="uEditId" value="0">
            <div class="modal-body">
                <div class="form-row form-row-2">
                    <div class="form-group"><label class="form-label">First Name *</label><input type="text" name="first_name" id="uFirst" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Last Name *</label><input type="text" name="last_name" id="uLast" class="form-control" required></div>
                </div>
                <div class="form-group"><label class="form-label">Email Address *</label><input type="email" name="email" id="uEmail" class="form-control" required></div>
                <div class="form-row form-row-2">
                    <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" id="uPhone" class="form-control" placeholder="09XX XXX XXXX"></div>
                    <div class="form-group"><label class="form-label">Department ID</label><input type="text" name="department_id" id="uDept" class="form-control" placeholder="e.g. PHA-001"></div>
                </div>
                <div class="form-row form-row-3">
                    <div class="form-group"><label class="form-label">Role *</label>
                        <select name="role" id="uRole" class="form-control" onchange="togglePharmacistFields()">
                            <?php foreach(['admin','pharmacist','staff','patient'] as $r): ?><option value="<?=$r?>"><?=ucfirst($r)?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" id="uStatus" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                    <div class="form-group"><label class="form-label">Password <span id="pwdHint" style="font-size:10px;color:var(--gray);text-transform:none;font-weight:400;">(leave blank = keep current)</span></label>
                        <input type="password" name="password" id="uPassword" class="form-control" placeholder="Min. 6 characters">
                    </div>
                </div>
                <!-- Pharmacist Fields -->
                <div id="pharmacistFields" style="display:none;background:rgba(0,137,123,0.05);border:1.5px solid rgba(0,137,123,0.15);border-radius:var(--radius-sm);padding:14px;">
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--teal);margin-bottom:10px;">Pharmacist Details</div>
                    <div class="form-row form-row-2">
                        <div class="form-group"><label class="form-label">License Number</label><input type="text" name="license_number" id="uLicense" class="form-control" placeholder="PH-2024-XXXX"></div>
                        <div class="form-group"><label class="form-label">Specialization</label><input type="text" name="specialization" id="uSpec" class="form-control" placeholder="e.g. Clinical Pharmacy"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('userModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save User</button>
            </div>
        </form>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
document.getElementById('userSearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#usersTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
});
document.getElementById('roleFilter').addEventListener('change', function() {
    window.location.href = `?role=${this.value}&search=${document.getElementById('userSearch').value}`;
});

function togglePharmacistFields() {
    const role = document.getElementById('uRole').value;
    document.getElementById('pharmacistFields').style.display = role === 'pharmacist' ? 'block' : 'none';
}

function openUserModal() {
    document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add User';
    document.getElementById('uEditId').value = '0';
    document.getElementById('userForm').reset();
    document.getElementById('pwdHint').style.display = 'none';
    document.getElementById('uPassword').placeholder = 'Min. 6 characters (required)';
    togglePharmacistFields();
    openModal('userModal');
}

function editUser(u) {
    document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit User';
    document.getElementById('uEditId').value = u.id;
    document.getElementById('uFirst').value = u.first_name || '';
    document.getElementById('uLast').value = u.last_name || '';
    document.getElementById('uEmail').value = u.email || '';
    document.getElementById('uPhone').value = u.phone || '';
    document.getElementById('uDept').value = u.department_id || '';
    document.getElementById('uRole').value = u.role || 'staff';
    document.getElementById('uStatus').value = u.status || 'active';
    document.getElementById('uPassword').value = '';
    document.getElementById('pwdHint').style.display = 'inline';
    document.getElementById('uLicense').value = u.license_number || '';
    document.getElementById('uSpec').value = u.specialization || '';
    togglePharmacistFields();
    openModal('userModal');
}
</script>
</body>
</html>
