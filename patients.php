<?php
require_once 'includes/auth.php';
requireRole(['admin','pharmacist','staff'], 'login.php');
$user = getCurrentUser();
$db = getDB();

$message = ''; $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_patient') {
        $editId   = (int)($_POST['edit_id'] ?? 0);
        $fields = [
            'first_name'            => sanitize($_POST['first_name'] ?? ''),
            'last_name'             => sanitize($_POST['last_name'] ?? ''),
            'date_of_birth'         => $_POST['date_of_birth'] ?? null,
            'sex'                   => $_POST['sex'] ?? 'Male',
            'blood_type'            => $_POST['blood_type'] ?? null,
            'phone'                 => sanitize($_POST['phone'] ?? ''),
            'address'               => sanitize($_POST['address'] ?? ''),
            'medical_history'       => sanitize($_POST['medical_history'] ?? ''),
            'allergies'             => sanitize($_POST['allergies'] ?? ''),
            'emergency_contact_name'  => sanitize($_POST['emergency_contact_name'] ?? ''),
            'emergency_contact_phone' => sanitize($_POST['emergency_contact_phone'] ?? ''),
            'assigned_doctor'       => sanitize($_POST['assigned_doctor'] ?? ''),
            'status'                => $_POST['status'] ?? 'active',
        ];

        if ($fields['first_name'] && $fields['last_name']) {
            if ($editId > 0) {
                $stmt = $db->prepare("UPDATE patients SET first_name=?, last_name=?, date_of_birth=?, sex=?, blood_type=?, phone=?, address=?, medical_history=?, allergies=?, emergency_contact_name=?, emergency_contact_phone=?, assigned_doctor=?, status=? WHERE id=?");
                $params = array_merge(array_values($fields), [$editId]);
                $stmt->bind_param('sssssssssssssi', ...$params);
                $stmt->execute();
                logAudit($user['id'], "Patient updated: {$fields['first_name']} {$fields['last_name']}", 'UPDATE', 'patients', $editId);
                $message = 'Patient record updated!'; $messageType = 'success';
            } else {
                $stmt = $db->prepare("INSERT INTO patients (first_name, last_name, date_of_birth, sex, blood_type, phone, address, medical_history, allergies, emergency_contact_name, emergency_contact_phone, assigned_doctor, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $fieldValues = array_values($fields);
                $stmt->bind_param('sssssssssssss', ...$fieldValues);
                $stmt->execute();
                logAudit($user['id'], "Patient added: {$fields['first_name']} {$fields['last_name']}", 'CREATE', 'patients', $db->insert_id);
                $message = 'Patient added successfully!'; $messageType = 'success';
            }
        } else { $message = 'First and last name are required.'; $messageType = 'danger'; }
    } elseif ($action === 'delete_patient') {
        $id = (int)($_POST['delete_id'] ?? 0);
        if ($id > 0) { $db->query("DELETE FROM patients WHERE id=$id"); $message = 'Patient deleted.'; $messageType = 'success'; }
    }
}

$search = sanitize($_GET['search'] ?? '');
$filter = sanitize($_GET['filter'] ?? '');

$where = "WHERE 1";
if ($search) $where .= " AND (p.first_name LIKE '%$search%' OR p.last_name LIKE '%$search%' OR p.phone LIKE '%$search%')";
if ($filter === 'active')      $where .= " AND p.status='active'";
elseif ($filter === 'inactive') $where .= " AND p.status='inactive'";
elseif ($filter === 'allergies') $where .= " AND p.allergies IS NOT NULL AND p.allergies != '' AND p.allergies != 'None'";

$patients = [];
$res = $db->query("SELECT p.*, (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id AND a.status='Confirmed' AND a.appointment_date >= CURDATE()) as upcoming_appts FROM patients p $where ORDER BY p.first_name");
while ($row = $res->fetch_assoc()) $patients[] = $row;

$totalP  = $db->query("SELECT COUNT(*) as c FROM patients")->fetch_assoc()['c'];
$activeP = $db->query("SELECT COUNT(*) as c FROM patients WHERE status='active'")->fetch_assoc()['c'];
$todayA  = $db->query("SELECT COUNT(*) as c FROM appointments WHERE appointment_date=CURDATE()")->fetch_assoc()['c'];
$allergyP = $db->query("SELECT COUNT(*) as c FROM patients WHERE allergies IS NOT NULL AND allergies != '' AND allergies != 'None'")->fetch_assoc()['c'];

$doctors = ['Dr. Maria Cruz','Dr. Jose Ramos','Dr. Ana Lopez','Dr. Santos'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patients — RS Pharmacy</title>
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
                <div class="page-breadcrumb">RS Pharmacy / <span>Patients</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">Patient Management</div><div class="page-subtitle">Manage patient records, history, and medical information</div></div>
                <button class="btn btn-primary" onclick="openModal('patientModal')"><i class="fas fa-plus"></i> Add Patient</button>
            </div>

            <?php if ($message): ?><div class="alert alert-<?= $messageType ?>" data-auto-dismiss="4000"><i class="fas fa-info-circle"></i> <?= $message ?></div><?php endif; ?>

            <div class="stat-grid stat-grid-4" style="margin-bottom:20px;">
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-users"></i></div><div class="stat-value"><?= $totalP ?></div><div class="stat-label">Total Patients</div><div class="stat-progress"><div class="stat-progress-bar teal" style="width:80%"></div></div></div>
                <div class="stat-card green"><div class="stat-icon green"><i class="fas fa-user-check"></i></div><div class="stat-value"><?= $activeP ?></div><div class="stat-label">Active Patients</div><div class="stat-progress"><div class="stat-progress-bar green" style="width:70%"></div></div></div>
                <div class="stat-card blue"><div class="stat-icon blue"><i class="fas fa-calendar-day"></i></div><div class="stat-value"><?= $todayA ?></div><div class="stat-label">Today's Appointments</div><div class="stat-progress"><div class="stat-progress-bar blue" style="width:40%"></div></div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-value"><?= $allergyP ?></div><div class="stat-label">With Allergies</div><div class="stat-progress"><div class="stat-progress-bar orange" style="width:60%"></div></div></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 220px;gap:20px;">
                <div>
                    <!-- Toolbar -->
                    <div class="table-wrapper" style="margin-bottom:16px;">
                        <div class="table-toolbar">
                            <div class="table-toolbar-left">
                                <div class="search-bar"><i class="fas fa-search"></i><input type="text" id="patientSearch" placeholder="Search by name, phone..." value="<?= htmlspecialchars($search) ?>"></div>
                            </div>
                            <div class="table-toolbar-right">
                                <span class="text-muted"><?= count($patients) ?> records</span>
                                <a href="?export=csv" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> Export</a>
                            </div>
                        </div>
                    </div>

                    <!-- Patient Cards -->
                    <div id="patientList" style="display:flex;flex-direction:column;gap:12px;">
                        <?php foreach ($patients as $p):
                            $hasAllergy = $p['allergies'] && $p['allergies'] !== 'None';
                            $age = $p['date_of_birth'] ? floor((time() - strtotime($p['date_of_birth'])) / (365.25 * 86400)) : null;
                        ?>
                        <div class="card patient-card" data-name="<?= strtolower(sanitize($p['first_name'].' '.$p['last_name'])) ?>">
                            <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;">
                                <div class="avatar avatar-lg" style="background:var(--teal);flex-shrink:0;"><?= strtoupper(substr($p['first_name'],0,1).substr($p['last_name'],0,1)) ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                        <div style="font-weight:700;font-size:15px;"><?= sanitize($p['first_name'].' '.$p['last_name']) ?></div>
                                        <span class="badge badge-<?= $p['status']==='active'?'success':'gray' ?>"><?= ucfirst($p['status']) ?></span>
                                        <?php if ($hasAllergy): ?><span class="badge badge-warning"><i class="fas fa-triangle-exclamation"></i> Allergy: <?= sanitize($p['allergies']) ?></span><?php endif; ?>
                                    </div>
                                    <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap;">
                                        <?php if ($age): ?><span class="text-muted"><i class="fas fa-cake-candles"></i> <?= $age ?> yrs</span><?php endif; ?>
                                        <?php if ($p['sex']): ?><span class="text-muted"><i class="fas fa-venus-mars"></i> <?= $p['sex'] ?></span><?php endif; ?>
                                        <?php if ($p['blood_type']): ?><span class="text-muted"><i class="fas fa-tint"></i> <?= $p['blood_type'] ?></span><?php endif; ?>
                                        <?php if ($p['phone']): ?><span class="text-muted"><i class="fas fa-phone"></i> <?= sanitize($p['phone']) ?></span><?php endif; ?>
                                    </div>
                                    <div style="display:flex;gap:16px;margin-top:4px;flex-wrap:wrap;">
                                        <?php if ($p['assigned_doctor']): ?><span class="text-muted"><i class="fas fa-user-md"></i> <?= sanitize($p['assigned_doctor']) ?></span><?php endif; ?>
                                        <?php if ($p['upcoming_appts'] > 0): ?><span class="text-muted"><i class="fas fa-calendar-check" style="color:var(--success);"></i> <?= $p['upcoming_appts'] ?> upcoming appt(s)</span><?php endif; ?>
                                        <?php if ($p['medical_history']): ?><span class="text-muted"><i class="fas fa-notes-medical"></i> <?= sanitize(substr($p['medical_history'],0,40)) ?><?= strlen($p['medical_history'])>40?'...':'' ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="action-links" style="flex-shrink:0;">
                                    <button class="action-link action-view" onclick='viewPatient(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fas fa-eye"></i> Profile</button>
                                    <button class="action-link action-edit" onclick='editPatient(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this patient record?')">
                                        <input type="hidden" name="action" value="delete_patient">
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="action-link action-delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($patients)): ?><div class="empty-state"><i class="fas fa-user-slash"></i><p>No patients found</p></div><?php endif; ?>
                    </div>
                </div>

                <!-- Sidebar Filters -->
                <div>
                    <div class="card" style="margin-bottom:16px;">
                        <div class="card-header"><div class="card-title">Quick Filters</div></div>
                        <div class="card-body" style="padding:12px;">
                            <?php foreach ([['','All Patients','users'],['active','Active Only','user-check'],['inactive','Inactive','user-slash'],['allergies','Has Allergies','triangle-exclamation']] as [$val,$label,$icon]): ?>
                            <a href="?filter=<?= $val ?>&search=<?= urlencode($search) ?>" class="btn <?= $filter===$val?'btn-primary':'btn-ghost' ?> btn-sm w-100" style="margin-bottom:4px;justify-content:flex-start;">
                                <i class="fas fa-<?= $icon ?>"></i> <?= $label ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div class="card-title">Doctors on Roster</div></div>
                        <div class="card-body" style="padding:12px;">
                            <?php foreach ($doctors as $doc): ?>
                            <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--gray-ultra);">
                                <div class="avatar avatar-sm" style="background:var(--primary);"><?= strtoupper(substr($doc, 3, 1)) ?></div>
                                <div>
                                    <div style="font-size:12px;font-weight:600;"><?= $doc ?></div>
                                    <div style="font-size:11px;color:var(--gray);">Available</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Patient Modal -->
<div class="modal-backdrop" id="patientModal">
    <div class="modal modal-xl">
        <div class="modal-header">
            <div class="modal-title" id="patientModalTitle"><i class="fas fa-user-plus"></i> Add Patient</div>
            <button class="modal-close" onclick="closeModal('patientModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="patientForm">
            <input type="hidden" name="action" value="save_patient">
            <input type="hidden" name="edit_id" id="pEditId" value="0">
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                    <div>
                        <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin-bottom:14px;">Personal Information</h4>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">First Name *</label><input type="text" name="first_name" id="pFirst" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">Last Name *</label><input type="text" name="last_name" id="pLast" class="form-control" required></div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Date of Birth</label><input type="date" name="date_of_birth" id="pDob" class="form-control"></div>
                            <div class="form-group"><label class="form-label">Sex</label>
                                <select name="sex" id="pSex" class="form-control"><option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option></select>
                            </div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Blood Type</label>
                                <select name="blood_type" id="pBlood" class="form-control"><option value="">Select</option><?php foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?><option value="<?=$bt?>"><?=$bt?></option><?php endforeach; ?></select>
                            </div>
                            <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" id="pPhone" class="form-control" placeholder="09XX XXX XXXX"></div>
                        </div>
                        <div class="form-group"><label class="form-label">Address</label><textarea name="address" id="pAddress" class="form-control" rows="2"></textarea></div>
                        <div class="form-group"><label class="form-label">Assigned Doctor</label>
                            <select name="assigned_doctor" id="pDoctor" class="form-control"><option value="">Select Doctor</option><?php foreach($doctors as $d): ?><option value="<?=$d?>"><?=$d?></option><?php endforeach; ?></select>
                        </div>
                        <div class="form-group"><label class="form-label">Status</label>
                            <select name="status" id="pStatus" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                        </div>
                    </div>
                    <div>
                        <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin-bottom:14px;">Medical Information</h4>
                        <div class="form-group"><label class="form-label">Medical History</label><textarea name="medical_history" id="pHistory" class="form-control" rows="3" placeholder="Existing conditions, diagnoses..."></textarea></div>
                        <div class="form-group"><label class="form-label">Allergies</label><input type="text" name="allergies" id="pAllergies" class="form-control" placeholder="e.g. Penicillin, Sulfa drugs (or None)"></div>
                        <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin:16px 0 14px;">Emergency Contact</h4>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Contact Name</label><input type="text" name="emergency_contact_name" id="pEcName" class="form-control"></div>
                            <div class="form-group"><label class="form-label">Contact Phone</label><input type="text" name="emergency_contact_phone" id="pEcPhone" class="form-control"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('patientModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Patient</button>
            </div>
        </form>
    </div>
</div>

<!-- View Patient Modal -->
<div class="modal-backdrop" id="viewPatientModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-user-injured"></i> Patient Profile</div>
            <button class="modal-close" onclick="closeModal('viewPatientModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="viewPatientBody"></div>
        <div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('viewPatientModal')">Close</button></div>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
document.getElementById('patientSearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('.patient-card').forEach(card => {
        card.style.display = card.dataset.name.includes(val) ? '' : 'none';
    });
});

function editPatient(p) {
    document.getElementById('patientModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Patient';
    document.getElementById('pEditId').value = p.id;
    document.getElementById('pFirst').value = p.first_name || '';
    document.getElementById('pLast').value = p.last_name || '';
    document.getElementById('pDob').value = p.date_of_birth || '';
    document.getElementById('pSex').value = p.sex || 'Male';
    document.getElementById('pBlood').value = p.blood_type || '';
    document.getElementById('pPhone').value = p.phone || '';
    document.getElementById('pAddress').value = p.address || '';
    document.getElementById('pHistory').value = p.medical_history || '';
    document.getElementById('pAllergies').value = p.allergies || '';
    document.getElementById('pEcName').value = p.emergency_contact_name || '';
    document.getElementById('pEcPhone').value = p.emergency_contact_phone || '';
    document.getElementById('pDoctor').value = p.assigned_doctor || '';
    document.getElementById('pStatus').value = p.status || 'active';
    openModal('patientModal');
}

function viewPatient(p) {
    const age = p.date_of_birth ? Math.floor((Date.now() - new Date(p.date_of_birth)) / (365.25*86400*1000)) : null;
    const hasAllergy = p.allergies && p.allergies !== 'None';
    document.getElementById('viewPatientBody').innerHTML = `
        <div style="display:flex;gap:20px;align-items:flex-start;margin-bottom:20px;">
            <div class="avatar avatar-lg" style="width:60px;height:60px;font-size:20px;background:var(--teal);flex-shrink:0;">${(p.first_name?.[0]||'')+(p.last_name?.[0]||'')}</div>
            <div>
                <div style="font-weight:700;font-size:18px;">${p.first_name} ${p.last_name}</div>
                <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;">
                    ${age ? `<span class="badge badge-gray">${age} years old</span>` : ''}
                    ${p.sex ? `<span class="badge badge-info">${p.sex}</span>` : ''}
                    ${p.blood_type ? `<span class="badge badge-primary">${p.blood_type}</span>` : ''}
                    <span class="badge badge-${p.status==='active'?'success':'gray'}">${p.status}</span>
                    ${hasAllergy ? `<span class="badge badge-warning"><i class="fas fa-triangle-exclamation"></i> Has Allergies</span>` : ''}
                </div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <div>
                <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin-bottom:10px;">Contact Info</h4>
                ${detailRow('Phone', p.phone)}
                ${detailRow('Address', p.address)}
                ${detailRow('Assigned Doctor', p.assigned_doctor)}
                <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin:14px 0 10px;">Emergency Contact</h4>
                ${detailRow('Name', p.emergency_contact_name)}
                ${detailRow('Phone', p.emergency_contact_phone)}
            </div>
            <div>
                <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin-bottom:10px;">Medical Info</h4>
                ${detailRow('Medical History', p.medical_history)}
                <div style="padding:8px 0;border-bottom:1px solid var(--gray-ultra);">
                    <span style="font-size:11px;font-weight:600;color:var(--gray);text-transform:uppercase;">Allergies</span>
                    <div style="margin-top:4px;">${hasAllergy ? `<span class="badge badge-warning">${p.allergies}</span>` : '<span class="badge badge-gray">None</span>'}</div>
                </div>
            </div>
        </div>
    `;
    openModal('viewPatientModal');
}

function detailRow(label, value) {
    return `<div style="padding:7px 0;border-bottom:1px solid var(--gray-ultra);">
        <span style="font-size:11px;font-weight:600;color:var(--gray);text-transform:uppercase;display:block;">${label}</span>
        <span style="font-size:13px;">${value || '—'}</span>
    </div>`;
}

document.querySelector('[onclick="openModal(\'patientModal\')"]')?.addEventListener('click', () => {
    document.getElementById('patientModalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add Patient';
    document.getElementById('pEditId').value = '0';
    document.getElementById('patientForm').reset();
});
</script>
</body>
</html>
