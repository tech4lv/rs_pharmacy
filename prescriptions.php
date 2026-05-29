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

    if ($action === 'save_prescription') {
        $editId      = (int)($_POST['edit_id'] ?? 0);
        $patientId   = (int)($_POST['patient_id'] ?? 0);
        $doctorName  = sanitize($_POST['doctor_name'] ?? '');
        $doctorLic   = sanitize($_POST['doctor_license'] ?? '');
        $issueDate   = $_POST['issue_date'] ?? date('Y-m-d');
        $expiryDate  = $_POST['expiry_date'] ?? '';
        $status      = $_POST['status'] ?? 'Pending';
        $source      = $_POST['source'] ?? 'Walk-In';
        $rxType      = $_POST['prescription_type'] ?? 'regular';
        $notes       = sanitize($_POST['notes'] ?? '');

        // items
        $medications = $_POST['medication_name']  ?? [];
        $dosages     = $_POST['dosage']            ?? [];
        $frequencies = $_POST['frequency']         ?? [];
        $durations   = $_POST['duration']          ?? [];
        $quantities  = $_POST['quantity']          ?? [];
        $instructions = $_POST['instructions']    ?? [];

        if ($patientId && $doctorName && $issueDate) {
            if ($editId > 0) {
                $stmt = $db->prepare("UPDATE prescriptions SET patient_id=?,doctor_name=?,doctor_license=?,issue_date=?,expiry_date=?,status=?,source=?,prescription_type=?,notes=? WHERE id=?");
                $stmt->bind_param('issssssssi', $patientId,$doctorName,$doctorLic,$issueDate,$expiryDate,$status,$source,$rxType,$notes,$editId);
                $stmt->execute();
                $db->query("DELETE FROM prescription_items WHERE prescription_id=$editId");
                $rxId = $editId;
                $message = 'Prescription updated!'; $messageType = 'success';
            } else {
                $rxNum = 'RX-' . str_pad($db->query("SELECT COUNT(*)+200 as c FROM prescriptions")->fetch_assoc()['c'], 3, '0', STR_PAD_LEFT);
                $stmt = $db->prepare("INSERT INTO prescriptions (rx_number,patient_id,doctor_name,doctor_license,issue_date,expiry_date,status,source,prescription_type,notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sisssssss s', $rxNum,$patientId,$doctorName,$doctorLic,$issueDate,$expiryDate,$status,$source,$rxType,$notes);
                $stmt->execute();
                $rxId = $db->insert_id;
                logAudit($user['id'],"Prescription created: $rxNum",'CREATE','prescriptions',$rxId);
                $message = 'Prescription created!'; $messageType = 'success';
            }
            // save items
            foreach ($medications as $idx => $med) {
                if (empty(trim($med))) continue;
                $stmt2 = $db->prepare("INSERT INTO prescription_items (prescription_id,medication_name,dosage,frequency,duration,quantity,instructions) VALUES (?,?,?,?,?,?,?)");
                $qty = (int)($quantities[$idx] ?? 1);
                $dosage = $dosages[$idx] ?? '';
                $frequency = $frequencies[$idx] ?? '';
                $duration = $durations[$idx] ?? '';
                $instructions = $instructions[$idx] ?? '';
                $stmt2->bind_param('issssiss',$rxId,$med,$dosage,$frequency,$duration,$qty,$instructions);
                $stmt2->execute();
            }
        } else { $message = 'Please fill required fields.'; $messageType = 'danger'; }

    } elseif ($action === 'fulfill_prescription') {
        $id = (int)($_POST['rx_id'] ?? 0);
        if ($id) {
            $db->query("UPDATE prescriptions SET status='Fulfilled' WHERE id=$id");
            $rx = $db->query("SELECT rx_number FROM prescriptions WHERE id=$id")->fetch_assoc();
            logAudit($user['id'],"Prescription fulfilled: {$rx['rx_number']}",'UPDATE','prescriptions',$id,null,null,'INFO');
            $message = 'Prescription marked as fulfilled.'; $messageType = 'success';
        }
    } elseif ($action === 'update_status') {
        $id = (int)($_POST['rx_id'] ?? 0); $status = sanitize($_POST['status'] ?? '');
        if ($id && $status) { $db->query("UPDATE prescriptions SET status='$status' WHERE id=$id"); $message = 'Status updated.'; $messageType = 'success'; }
    } elseif ($action === 'delete_rx') {
        $id = (int)($_POST['delete_id'] ?? 0);
        if ($id) { $db->query("DELETE FROM prescriptions WHERE id=$id"); $message = 'Prescription deleted.'; $messageType = 'success'; }
    }
}

// Filters
$search     = sanitize($_GET['search'] ?? '');
$filterStatus = sanitize($_GET['status'] ?? '');
$filterSource = sanitize($_GET['source'] ?? '');

$where = "WHERE 1";
if ($search)       $where .= " AND (rx.rx_number LIKE '%$search%' OR CONCAT(p.first_name,' ',p.last_name) LIKE '%$search%' OR rx.doctor_name LIKE '%$search%')";
if ($filterStatus) $where .= " AND rx.status='$filterStatus'";
if ($filterSource) $where .= " AND rx.source='$filterSource'";

// Role-based filter for patient
if ($user['role'] === 'patient') {
    $where .= " AND p.user_id={$user['id']}";
}

$prescriptions = [];
$res = $db->query("SELECT rx.*, CONCAT(p.first_name,' ',p.last_name) as patient_name, p.phone as patient_phone, CONCAT(ph_u.first_name,' ',ph_u.last_name) as pharmacist_name FROM prescriptions rx LEFT JOIN patients p ON rx.patient_id=p.id LEFT JOIN pharmacists ph ON rx.pharmacist_id=ph.id LEFT JOIN users ph_u ON ph.user_id=ph_u.id $where ORDER BY rx.created_at DESC");
while ($row = $res->fetch_assoc()) $prescriptions[] = $row;

$patients = [];
$res2 = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name FROM patients WHERE status='active' ORDER BY first_name");
while ($row = $res2->fetch_assoc()) $patients[] = $row;

$products = [];
$res3 = $db->query("SELECT id, name, generic_name, requires_prescription FROM products WHERE status='active' ORDER BY name");
while ($row = $res3->fetch_assoc()) $products[] = $row;

// Stats
$totalRx  = $db->query("SELECT COUNT(*) as c FROM prescriptions")->fetch_assoc()['c'];
$pendingRx = $db->query("SELECT COUNT(*) as c FROM prescriptions WHERE status='Pending'")->fetch_assoc()['c'];
$issuedRx  = $db->query("SELECT COUNT(*) as c FROM prescriptions WHERE status='Issued'")->fetch_assoc()['c'];
$todayRx   = $db->query("SELECT COUNT(*) as c FROM prescriptions WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Prescriptions — RS Pharmacy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .rx-item-row { display:grid; grid-template-columns:2fr 1fr 1fr 1fr 80px 1fr 32px; gap:8px; align-items:center; margin-bottom:8px; }
        .rx-card { border-left:4px solid transparent; }
        .rx-card.Pending   { border-left-color:var(--warning); }
        .rx-card.Issued    { border-left-color:var(--teal); }
        .rx-card.Fulfilled { border-left-color:var(--success); }
        .rx-card.Cancelled { border-left-color:var(--gray); }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="topbar-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <div class="page-breadcrumb">RS Pharmacy / <span>Prescriptions</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">Prescription Management</div><div class="page-subtitle">Create, track, and fulfill patient prescriptions</div></div>
                <?php if (in_array($user['role'],['admin','pharmacist','staff'])): ?>
                <button class="btn btn-primary" onclick="openRxModal()"><i class="fas fa-plus"></i> New Prescription</button>
                <?php endif; ?>
            </div>

            <?php if ($message): ?><div class="alert alert-<?=$messageType?>" data-auto-dismiss="4000"><i class="fas fa-<?=$messageType==='success'?'check-circle':'exclamation-circle'?>"></i> <?=$message?></div><?php endif; ?>

            <!-- Stats -->
            <div class="stat-grid stat-grid-4" style="margin-bottom:20px;">
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-file-prescription"></i></div><div class="stat-value"><?=$totalRx?></div><div class="stat-label">Total Prescriptions</div><div class="stat-progress"><div class="stat-progress-bar teal" style="width:80%"></div></div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-clock"></i></div><div class="stat-value"><?=$pendingRx?></div><div class="stat-label">Pending Review</div><div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?=min(100,$pendingRx*20)?>%"></div></div></div>
                <div class="stat-card blue"><div class="stat-icon blue"><i class="fas fa-circle-check"></i></div><div class="stat-value"><?=$issuedRx?></div><div class="stat-label">Issued (Active)</div><div class="stat-progress"><div class="stat-progress-bar blue" style="width:<?=min(100,$issuedRx*20)?>%"></div></div></div>
                <div class="stat-card red"><div class="stat-icon red"><i class="fas fa-calendar-day"></i></div><div class="stat-value"><?=$todayRx?></div><div class="stat-label">Today's Prescriptions</div><div class="stat-progress"><div class="stat-progress-bar red" style="width:<?=min(100,$todayRx*20)?>%"></div></div></div>
            </div>

            <!-- Filters -->
            <div class="table-wrapper" style="margin-bottom:16px;">
                <div class="table-toolbar">
                    <div class="table-toolbar-left">
                        <div class="search-bar"><i class="fas fa-search"></i><input type="text" id="rxSearch" placeholder="Search RX number, patient, doctor..." value="<?=htmlspecialchars($search)?>"></div>
                        <select class="filter-select" id="statusFilter"><option value="">All Status</option><?php foreach(['Pending','Issued','Fulfilled','Cancelled'] as $s): ?><option value="<?=$s?>" <?=$filterStatus===$s?'selected':''?>><?=$s?></option><?php endforeach; ?></select>
                        <select class="filter-select" id="sourceFilter"><option value="">All Sources</option><?php foreach(['Walk-In','Online Order','Reservation'] as $s): ?><option value="<?=$s?>" <?=$filterSource===$s?'selected':''?>><?=$s?></option><?php endforeach; ?></select>
                    </div>
                    <div class="table-toolbar-right">
                        <span class="text-muted"><?=count($prescriptions)?> prescriptions</span>
                    </div>
                </div>
            </div>

            <!-- Prescription Cards -->
            <div style="display:flex;flex-direction:column;gap:12px;" id="rxList">
                <?php foreach ($prescriptions as $rx):
                    $expired = $rx['expiry_date'] && strtotime($rx['expiry_date']) < time();
                    $badgeMap = ['Pending'=>'warning','Issued'=>'teal','Fulfilled'=>'success','Cancelled'=>'gray'];
                    $sourceIconMap = ['Walk-In'=>'fa-person-walking','Online Order'=>'fa-globe','Reservation'=>'fa-bookmark'];
                ?>
                <div class="card rx-card <?=$rx['status']?>" data-search="<?=strtolower(sanitize($rx['rx_number'].' '.$rx['patient_name'].' '.$rx['doctor_name']))?>">
                    <div class="card-body">
                        <div style="display:flex;gap:16px;align-items:flex-start;">
                            <!-- Rx Badge -->
                            <div style="background:rgba(192,57,43,0.08);border:1.5px dashed var(--primary);border-radius:var(--radius-sm);padding:12px 16px;text-align:center;flex-shrink:0;min-width:100px;">
                                <div style="font-size:10px;color:var(--gray);font-weight:600;letter-spacing:0.06em;text-transform:uppercase;">Rx No.</div>
                                <div style="font-size:16px;font-weight:800;color:var(--primary);font-family:monospace;"><?=sanitize($rx['rx_number']??'—')?></div>
                                <span class="badge badge-<?=$badgeMap[$rx['status']]??'gray'?>" style="margin-top:4px;"><?=$rx['status']?></span>
                            </div>

                            <!-- Details -->
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                                    <div style="font-weight:700;font-size:15px;"><?=sanitize($rx['patient_name']??'Unknown Patient')?></div>
                                    <span class="badge badge-<?=$rx['prescription_type']==='controlled'?'danger':($rx['prescription_type']==='yellow_pad'?'warning':'gray')?>">
                                        <?=ucfirst(str_replace('_',' ',$rx['prescription_type']??'regular'))?>
                                    </span>
                                    <span class="badge badge-info"><i class="fas <?=$sourceIconMap[$rx['source']]??'fa-hospital'?>"></i> <?=$rx['source']?></span>
                                    <?php if($expired && $rx['status']!=='Fulfilled'): ?><span class="badge badge-danger"><i class="fas fa-clock"></i> Expired</span><?php endif; ?>
                                </div>
                                <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px;color:var(--gray);">
                                    <span><i class="fas fa-user-md"></i> <?=sanitize($rx['doctor_name']??'—')?><?=$rx['doctor_license']?' ('.$rx['doctor_license'].')':''?></span>
                                    <span><i class="fas fa-calendar"></i> Issued: <?=formatDate($rx['issue_date'])?></span>
                                    <?php if($rx['expiry_date']): ?><span><i class="fas fa-calendar-xmark" style="color:<?=$expired?'var(--danger)':'var(--warning)'?>"></i> Expires: <?=formatDate($rx['expiry_date'])?></span><?php endif; ?>
                                    <?php if($rx['pharmacist_name']): ?><span><i class="fas fa-user-nurse"></i> <?=sanitize($rx['pharmacist_name'])?></span><?php endif; ?>
                                </div>
                                <?php if($rx['notes']): ?><div style="margin-top:6px;font-size:12px;color:var(--gray);font-style:italic;"><?=sanitize($rx['notes'])?></div><?php endif; ?>
                            </div>

                            <!-- Actions -->
                            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                                <button class="action-link action-view" onclick='viewRx(<?=json_encode($rx, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-eye"></i> View</button>
                                <?php if (in_array($user['role'],['admin','pharmacist','staff'])): ?>
                                <?php if ($rx['status']==='Issued' || $rx['status']==='Pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="fulfill_prescription">
                                    <input type="hidden" name="rx_id" value="<?=$rx['id']?>">
                                    <button type="submit" class="action-link action-fulfill" style="width:100%;justify-content:center;"><i class="fas fa-check"></i> Fulfill</button>
                                </form>
                                <?php endif; ?>
                                <button class="action-link action-edit" onclick='editRx(<?=json_encode($rx, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-pen"></i> Edit</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this prescription?')">
                                    <input type="hidden" name="action" value="delete_rx">
                                    <input type="hidden" name="delete_id" value="<?=$rx['id']?>">
                                    <button type="submit" class="action-link action-delete" style="width:100%;justify-content:center;"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(empty($prescriptions)): ?><div class="empty-state" style="background:var(--white);border-radius:var(--radius);padding:48px;"><i class="fas fa-file-prescription"></i><p>No prescriptions found</p></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Rx Modal -->
<div class="modal-backdrop" id="rxModal">
    <div class="modal modal-xl">
        <div class="modal-header">
            <div class="modal-title" id="rxModalTitle"><i class="fas fa-file-prescription"></i> New Prescription</div>
            <button class="modal-close" onclick="closeModal('rxModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="rxForm">
            <input type="hidden" name="action" value="save_prescription">
            <input type="hidden" name="edit_id" id="rxEditId" value="0">
            <div class="modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:20px;">
                    <div>
                        <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin-bottom:12px;">Prescription Details</h4>
                        <div class="form-group"><label class="form-label">Patient *</label>
                            <select name="patient_id" id="rxPatient" class="form-control" required>
                                <option value="">Select Patient</option>
                                <?php foreach($patients as $pt): ?><option value="<?=$pt['id']?>"><?=sanitize($pt['name'])?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Doctor Name *</label><input type="text" name="doctor_name" id="rxDoctor" class="form-control" required placeholder="Dr. Juan Cruz"></div>
                            <div class="form-group"><label class="form-label">License No.</label><input type="text" name="doctor_license" id="rxLicense" class="form-control" placeholder="PRC-XXXXX"></div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Issue Date</label><input type="date" name="issue_date" id="rxIssue" class="form-control" value="<?=date('Y-m-d')?>"></div>
                            <div class="form-group"><label class="form-label">Expiry Date</label><input type="date" name="expiry_date" id="rxExpiry" class="form-control"></div>
                        </div>
                    </div>
                    <div>
                        <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);margin-bottom:12px;">Classification</h4>
                        <div class="form-row form-row-2">
                            <div class="form-group"><label class="form-label">Source</label>
                                <select name="source" id="rxSource" class="form-control">
                                    <?php foreach(['Walk-In','Online Order','Reservation'] as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label class="form-label">Rx Type</label>
                                <select name="prescription_type" id="rxType" class="form-control">
                                    <option value="regular">Regular</option>
                                    <option value="yellow_pad">Yellow Pad</option>
                                    <option value="controlled">Controlled</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group"><label class="form-label">Status</label>
                            <select name="status" id="rxStatus" class="form-control">
                                <?php foreach(['Pending','Issued','Fulfilled','Cancelled'] as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="rxNotes" class="form-control" rows="3" placeholder="Additional notes..."></textarea></div>
                    </div>
                </div>

                <!-- Medication Items -->
                <div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <h4 style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--gray);">Medications</h4>
                        <button type="button" class="btn btn-teal btn-sm" onclick="addMedRow()"><i class="fas fa-plus"></i> Add Medication</button>
                    </div>
                    <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:10px;margin-bottom:8px;">
                        <div class="rx-item-row" style="margin-bottom:0;">
                            <span style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;">Medication</span>
                            <span style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;">Dosage</span>
                            <span style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;">Frequency</span>
                            <span style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;">Duration</span>
                            <span style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;">Qty</span>
                            <span style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;">Instructions</span>
                            <span></span>
                        </div>
                    </div>
                    <div id="medRows">
                        <!-- rows injected by JS -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('rxModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Prescription</button>
            </div>
        </form>
    </div>
</div>

<!-- View Rx Modal -->
<div class="modal-backdrop" id="viewRxModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-file-prescription"></i> Prescription Details</div>
            <button class="modal-close" onclick="closeModal('viewRxModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="viewRxBody"></div>
        <div class="modal-footer"><button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button><button class="btn btn-primary" onclick="closeModal('viewRxModal')">Close</button></div>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
// Search filter
document.getElementById('rxSearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#rxList .rx-card').forEach(card => {
        card.style.display = card.dataset.search?.includes(val) ? '' : 'none';
    });
});
document.getElementById('statusFilter').addEventListener('change', () => applyFilters());
document.getElementById('sourceFilter').addEventListener('change', () => applyFilters());
function applyFilters() {
    const s = document.getElementById('statusFilter').value;
    const src = document.getElementById('sourceFilter').value;
    const q = document.getElementById('rxSearch').value;
    window.location.href = `?status=${s}&source=${src}&search=${q}`;
}

let medRowCount = 0;
const products = <?=json_encode(array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name']],array_filter($products,fn($p)=>$p['requires_prescription'])))?>;

function addMedRow(data = {}) {
    const idx = medRowCount++;
    const row = document.createElement('div');
    row.className = 'rx-item-row';
    row.id = `med-row-${idx}`;

    const productOptions = `<option value="">Custom</option>` + products.map(p => `<option value="${p.name}" ${data.medication_name===p.name?'selected':''}>${p.name}</option>`).join('');

    row.innerHTML = `
        <select name="medication_name[]" class="form-control" style="font-size:12px;" required>
            ${productOptions}
        </select>
        <input type="text" name="dosage[]" class="form-control" style="font-size:12px;" placeholder="500mg" value="${data.dosage||''}">
        <input type="text" name="frequency[]" class="form-control" style="font-size:12px;" placeholder="3x/day" value="${data.frequency||''}">
        <input type="text" name="duration[]" class="form-control" style="font-size:12px;" placeholder="7 days" value="${data.duration||''}">
        <input type="number" name="quantity[]" class="form-control" style="font-size:12px;" placeholder="1" value="${data.quantity||1}" min="1">
        <input type="text" name="instructions[]" class="form-control" style="font-size:12px;" placeholder="Take after meals" value="${data.instructions||''}">
        <button type="button" onclick="document.getElementById('med-row-${idx}').remove()" style="background:none;border:none;cursor:pointer;color:var(--danger);padding:4px;"><i class="fas fa-trash"></i></button>
    `;
    document.getElementById('medRows').appendChild(row);
}

function openRxModal() {
    document.getElementById('rxModalTitle').innerHTML = '<i class="fas fa-file-prescription"></i> New Prescription';
    document.getElementById('rxEditId').value = '0';
    document.getElementById('rxForm').reset();
    document.getElementById('rxIssue').value = '<?=date('Y-m-d')?>';
    document.getElementById('medRows').innerHTML = '';
    medRowCount = 0;
    addMedRow();
    openModal('rxModal');
}

function editRx(rx) {
    document.getElementById('rxModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Prescription';
    document.getElementById('rxEditId').value = rx.id;
    document.getElementById('rxPatient').value = rx.patient_id || '';
    document.getElementById('rxDoctor').value = rx.doctor_name || '';
    document.getElementById('rxLicense').value = rx.doctor_license || '';
    document.getElementById('rxIssue').value = rx.issue_date || '';
    document.getElementById('rxExpiry').value = rx.expiry_date || '';
    document.getElementById('rxSource').value = rx.source || 'Walk-In';
    document.getElementById('rxType').value = rx.prescription_type || 'regular';
    document.getElementById('rxStatus').value = rx.status || 'Pending';
    document.getElementById('rxNotes').value = rx.notes || '';
    document.getElementById('medRows').innerHTML = '';
    medRowCount = 0;
    addMedRow();
    openModal('rxModal');
}

function viewRx(rx) {
    const statusClass = {Pending:'warning',Issued:'teal',Fulfilled:'success',Cancelled:'gray'};
    document.getElementById('viewRxBody').innerHTML = `
        <div style="border:2px dashed var(--gray-light);border-radius:var(--radius);padding:24px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
                <div>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <i class="fas fa-pills" style="font-size:24px;color:var(--primary);"></i>
                        <div><div style="font-weight:700;font-size:18px;">RS Pharmacy</div><div style="font-size:12px;color:var(--gray);">Official Prescription</div></div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:22px;font-weight:800;color:var(--primary);font-family:monospace;">${rx.rx_number||'—'}</div>
                    <span class="badge badge-${statusClass[rx.status]||'gray'}">${rx.status}</span>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:12px;">
                    <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;margin-bottom:8px;">Patient</div>
                    <div style="font-weight:700;font-size:15px;">${rx.patient_name||'—'}</div>
                    ${rx.patient_phone ? `<div style="font-size:12px;color:var(--gray);">${rx.patient_phone}</div>` : ''}
                </div>
                <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:12px;">
                    <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;margin-bottom:8px;">Prescribing Doctor</div>
                    <div style="font-weight:700;font-size:15px;">${rx.doctor_name||'—'}</div>
                    ${rx.doctor_license ? `<div style="font-size:12px;color:var(--gray);">License: ${rx.doctor_license}</div>` : ''}
                </div>
            </div>
            <div style="display:flex;gap:20px;font-size:13px;color:var(--gray);margin-bottom:16px;flex-wrap:wrap;">
                <span><i class="fas fa-calendar"></i> Issued: <strong style="color:var(--black);">${rx.issue_date||'—'}</strong></span>
                ${rx.expiry_date ? `<span><i class="fas fa-calendar-xmark"></i> Expires: <strong style="color:var(--black);">${rx.expiry_date}</strong></span>` : ''}
                <span><i class="fas fa-tag"></i> ${rx.source||'Walk-In'}</span>
                <span><i class="fas fa-prescription"></i> ${rx.prescription_type||'regular'}</span>
            </div>
            ${rx.notes ? `<div style="background:rgba(255,159,10,0.08);border:1px solid rgba(255,159,10,0.2);border-radius:var(--radius-sm);padding:10px;font-size:13px;color:#C47000;margin-bottom:16px;"><i class="fas fa-sticky-note"></i> ${rx.notes}</div>` : ''}
            <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;margin-bottom:8px;">Medications</div>
            <div style="font-size:13px;color:var(--gray);font-style:italic;">Load prescription items from database...</div>
        </div>
    `;
    openModal('viewRxModal');
}
</script>
</body>
</html>
