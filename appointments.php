<?php
require_once 'includes/auth.php';
requireLogin('login.php');
$user = getCurrentUser();
$db = getDB();

$message = ''; $messageType = '';

$patientRecord = null;
$patientId = 0;
if ($user['role'] === 'patient') {
    $stmt = $db->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $patientRecord = $stmt->get_result()->fetch_assoc();
    $patientId = $patientRecord['id'] ?? 0;

    if (!$patientId) {
        $insertPatient = $db->prepare("INSERT INTO patients (user_id, first_name, last_name, phone, status) VALUES (?, ?, ?, ?, 'active')");
        $insertPatient->bind_param('isss', $user['id'], $user['first_name'], $user['last_name'], $user['phone']);
        if ($insertPatient->execute()) {
            $patientId = $db->insert_id;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_appointment') {
        $editId      = (int)($_POST['edit_id'] ?? 0);
        $patientIdPost   = (int)($_POST['patient_id'] ?? 0);
        $doctorName  = sanitize($_POST['doctor_name'] ?? '');
        $apptDate    = $_POST['appointment_date'] ?? '';
        $apptTime    = $_POST['appointment_time'] ?? '';
        $duration    = (int)($_POST['duration_minutes'] ?? 30);
        $purpose     = sanitize($_POST['purpose'] ?? '');
        $serviceType = sanitize($_POST['service_type'] ?? '');
        $status      = $_POST['status'] ?? 'Pending';
        $notes       = sanitize($_POST['notes'] ?? '');

        if (!$doctorName) {
            $doctorName = 'TBD';
        }

        if ($user['role'] === 'patient') {
            if ($patientId && $serviceType && $apptDate && $apptTime) {
                $stmt = $db->prepare("INSERT INTO appointments (patient_id, doctor_name, appointment_date, appointment_time, duration_minutes, purpose, service_type, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('isssissss', $patientId, $doctorName, $apptDate, $apptTime, $duration, $purpose, $serviceType, $status, $notes);
                $stmt->execute();
                logAudit($user['id'], "Appointment requested by patient #$patientId for $apptDate", 'CREATE', 'appointments', $db->insert_id);
                $message = 'Appointment request sent. Check your appointment status below.'; $messageType = 'success';
            } else {
                $message = $patientId ? 'Please choose a service, date, and time.' : 'No patient profile found. Please contact support.';
                $messageType = 'danger';
            }
        } else {
            if ($patientIdPost && $apptDate && $apptTime) {
                if ($editId > 0) {
                    $stmt = $db->prepare("UPDATE appointments SET patient_id=?, doctor_name=?, appointment_date=?, appointment_time=?, duration_minutes=?, purpose=?, service_type=?, status=?, notes=? WHERE id=?");
                    $stmt->bind_param('isssissssi', $patientIdPost, $doctorName, $apptDate, $apptTime, $duration, $purpose, $serviceType, $status, $notes, $editId);
                    $stmt->execute();
                    $message = 'Appointment updated!'; $messageType = 'success';
                } else {
                    $stmt = $db->prepare("INSERT INTO appointments (patient_id, doctor_name, appointment_date, appointment_time, duration_minutes, purpose, service_type, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('isssissss', $patientIdPost, $doctorName, $apptDate, $apptTime, $duration, $purpose, $serviceType, $status, $notes);
                    $stmt->execute();
                    logAudit($user['id'], "Appointment booked for patient #$patientIdPost on $apptDate", 'CREATE', 'appointments', $db->insert_id);
                    $message = 'Appointment booked!'; $messageType = 'success';
                }
            } else {
                $message = 'Please fill in all required fields.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'update_status') {
        if (in_array($user['role'], ['admin','pharmacist','staff'])) {
            $id     = (int)($_POST['appt_id'] ?? 0);
            $status = sanitize($_POST['status'] ?? '');
            if ($id && $status) { $db->query("UPDATE appointments SET status='$status' WHERE id=$id"); $message = 'Status updated.'; $messageType = 'success'; }
        }
    } elseif ($action === 'delete_appointment') {
        if (in_array($user['role'], ['admin','pharmacist','staff'])) {
            $id = (int)($_POST['delete_id'] ?? 0);
            if ($id) { $db->query("DELETE FROM appointments WHERE id=$id"); $message = 'Appointment deleted.'; $messageType = 'success'; }
        }
    }
}

// Calendar month
$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

// Fetch appointments for this month
$allAppts = [];
$appts = [];
$res = $db->query("SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name, p.user_id as patient_user_id FROM appointments a LEFT JOIN patients p ON a.patient_id=p.id WHERE MONTH(a.appointment_date)=$month AND YEAR(a.appointment_date)=$year ORDER BY a.appointment_date, a.appointment_time");
while ($row = $res->fetch_assoc()) {
    $row['is_own'] = ($user['role'] === 'patient' && $row['patient_user_id'] == $user['id']) ? 1 : 0;
    if ($user['role'] === 'patient' && !$row['is_own']) {
        $row['patient_name'] = 'Booked Slot';
        $row['purpose'] = '';
    }
    $allAppts[] = $row;
    if ($user['role'] === 'patient') {
        if ($row['is_own']) { $appts[] = $row; }
    } else {
        $appts[] = $row;
    }
}

// Group by date
$apptsByDate = [];
foreach ($allAppts as $a) { $apptsByDate[$a['appointment_date']][] = $a; }

$patients = [];
if ($user['role'] !== 'patient') {
    $res2 = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name FROM patients WHERE status='active' ORDER BY first_name");
    while ($row = $res2->fetch_assoc()) $patients[] = $row;
}

// Stats
if ($user['role'] === 'patient') {
    $todayCount = $db->query("SELECT COUNT(*) as c FROM appointments a JOIN patients p ON a.patient_id=p.id WHERE p.user_id={$user['id']} AND a.appointment_date=CURDATE()")->fetch_assoc()['c'];
    $upcomingCount = $db->query("SELECT COUNT(*) as c FROM appointments a JOIN patients p ON a.patient_id=p.id WHERE p.user_id={$user['id']} AND a.appointment_date > CURDATE() AND a.status IN ('Pending','Confirmed')")->fetch_assoc()['c'];
    $completedCount = $db->query("SELECT COUNT(*) as c FROM appointments a JOIN patients p ON a.patient_id=p.id WHERE p.user_id={$user['id']} AND a.status='Completed'")->fetch_assoc()['c'];
} else {
    $todayCount    = $db->query("SELECT COUNT(*) as c FROM appointments WHERE appointment_date=CURDATE()")->fetch_assoc()['c'];
    $upcomingCount = $db->query("SELECT COUNT(*) as c FROM appointments WHERE appointment_date > CURDATE() AND status IN ('Pending','Confirmed')")->fetch_assoc()['c'];
    $completedCount = $db->query("SELECT COUNT(*) as c FROM appointments WHERE status='Completed'")->fetch_assoc()['c'];
}

$doctors = ['Dr. Maria Cruz','Dr. Jose Ramos','Dr. Ana Lopez','Dr. Santos'];
$services = ['Doctor Consultation','Prescription Management','Vaccination Services','Health Monitoring','Medication Reservation'];

$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfMonth = date('N', mktime(0,0,0,$month,1,$year)); // 1=Mon, 7=Sun
$firstDayOfMonth = $firstDayOfMonth % 7; // 0=Sun
$dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$monthName = date('F Y', mktime(0,0,0,$month,1,$year));
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Appointments — RS Pharmacy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:1px; background:var(--gray-light); border:1px solid var(--gray-light); border-radius:var(--radius-sm); overflow:hidden; }
        .cal-header-cell { background:var(--gray-ultra); padding:10px; text-align:center; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--gray); }
        .cal-day-cell { background:var(--white); min-height:80px; padding:6px; position:relative; }
        .cal-day-cell:hover { background:rgba(0,137,123,0.03); }
        .cal-day-cell.other-month { background:var(--gray-ultra); opacity:0.5; }
        .cal-day-cell.today { background:rgba(192,57,43,0.03); }
        .cal-day-num { font-size:12px; font-weight:700; width:24px; height:24px; display:flex; align-items:center; justify-content:center; border-radius:50%; margin-bottom:4px; }
        .today .cal-day-num { background:var(--primary); color:white; }
        .cal-event { font-size:10px; font-weight:600; padding:2px 5px; border-radius:3px; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; }
        .cal-event.Confirmed { background:rgba(48,209,88,0.15); color:#1A8A38; }
        .cal-event.Pending   { background:rgba(255,59,48,0.12);  color:var(--danger); }
        .cal-event.Completed { background:rgba(10,132,255,0.12); color:var(--info); }
        .cal-event.Cancelled { background:rgba(142,142,147,0.15);color:var(--gray); }
        .cal-available { font-size:10px; color:var(--success); font-weight:600; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="topbar-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <div class="page-breadcrumb">RS Pharmacy / <span>Appointments</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title"><?= $user['role']==='patient' ? 'My Appointments' : 'Appointment Calendar' ?></div><div class="page-subtitle"><?= $user['role']==='patient' ? 'Select a service, check availability, and track your appointment status.' : 'Schedule and manage patient appointments' ?></div></div>
                <?php if (in_array($user['role'],['admin','pharmacist','staff'])): ?>
                <button class="btn btn-primary" onclick="openModal('apptModal')"><i class="fas fa-plus"></i> New Appointment</button>
                <?php endif; ?>
            </div>

            <?php if ($message): ?><div class="alert alert-<?= $messageType ?>" data-auto-dismiss="4000"><i class="fas fa-info-circle"></i> <?= $message ?></div><?php endif; ?>

            <?php if ($user['role'] === 'patient'): ?>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header"><div class="card-title">Request an Appointment</div></div>
                <div class="card-body">
                    <?php if (!$patientId): ?>
                        <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> We could not locate your patient profile. Please contact support to complete your booking.</div>
                    <?php else: ?>
                        <form method="POST" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:end;">
                            <input type="hidden" name="action" value="save_appointment">
                            <div class="form-group">
                                <label class="form-label">Service</label>
                                <select name="service_type" class="form-control" required>
                                    <option value="">Select a service</option>
                                    <?php foreach ($services as $s): ?><option value="<?= sanitize($s) ?>"><?= sanitize($s) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Preferred Date</label>
                                <input type="date" name="appointment_date" class="form-control" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Preferred Time</label>
                                <input type="time" name="appointment_time" class="form-control" required>
                            </div>
                            <div class="form-group" style="grid-column:1 / -1;">
                                <label class="form-label">Reason / Notes</label>
                                <input type="text" name="purpose" class="form-control" placeholder="Describe your concern or service request">
                            </div>
                            <div style="grid-column:1 / -1;display:flex;justify-content:flex-end;">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-calendar-plus"></i> Request Appointment</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="stat-grid stat-grid-3" style="margin-bottom:20px;">
                <div class="stat-card blue"><div class="stat-icon blue"><i class="fas fa-calendar-day"></i></div><div class="stat-value"><?= $todayCount ?></div><div class="stat-label">Today's Appointments</div><div class="stat-progress"><div class="stat-progress-bar blue" style="width:<?= min(100,$todayCount*20) ?>%"></div></div></div>
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-calendar-plus"></i></div><div class="stat-value"><?= $upcomingCount ?></div><div class="stat-label">Upcoming</div><div class="stat-progress"><div class="stat-progress-bar teal" style="width:<?= min(100,$upcomingCount*10) ?>%"></div></div></div>
                <div class="stat-card green"><div class="stat-icon green"><i class="fas fa-calendar-check"></i></div><div class="stat-value"><?= $completedCount ?></div><div class="stat-label">Completed</div><div class="stat-progress"><div class="stat-progress-bar green" style="width:<?= min(100,$completedCount*8) ?>%"></div></div></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 260px;gap:20px;">
                <!-- Calendar -->
                <div class="card">
                    <div class="card-header">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <a href="?month=<?= $month-1 ?>&year=<?= $year ?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-left"></i></a>
                            <div class="card-title"><?= $monthName ?></div>
                            <a href="?month=<?= $month+1 ?>&year=<?= $year ?>" class="btn btn-ghost btn-sm"><i class="fas fa-chevron-right"></i></a>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-outline btn-sm">Today</a>
                            <button class="btn btn-outline btn-sm" onclick="toggleView()"><i class="fas fa-list"></i> List</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Calendar Grid -->
                        <div class="cal-grid" id="calendarView">
                            <?php foreach ($dayNames as $d): ?>
                            <div class="cal-header-cell"><?= $d ?></div>
                            <?php endforeach; ?>

                            <?php
                            // Empty cells before month start
                            for ($i = 0; $i < $firstDayOfMonth; $i++) {
                                echo '<div class="cal-day-cell other-month"></div>';
                            }
                            for ($day = 1; $day <= $daysInMonth; $day++) {
                                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                $isToday = $dateStr === $today;
                                $dayAppts = $apptsByDate[$dateStr] ?? [];
                                echo '<div class="cal-day-cell ' . ($isToday ? 'today' : '') . '">';
                                echo '<div class="cal-day-num">' . $day . '</div>';
                                foreach (array_slice($dayAppts, 0, 3) as $a) {
                                    $name = $a['patient_name'] ? explode(' ', $a['patient_name'])[0] : 'Patient';
                                    $time = date('g:iA', strtotime($a['appointment_time']));
                                    echo "<div class='cal-event {$a['status']}' onclick='viewAppt(" . json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) . ")' title='{$a['status']}: {$a['patient_name']}'>$time {$name}</div>";
                                }
                                if (count($dayAppts) > 3) echo '<div class="cal-event Confirmed">+' . (count($dayAppts)-3) . ' more</div>';
                                if (empty($dayAppts) && !$isToday) echo '<div class="cal-available">Available</div>';
                                echo '</div>';
                            }
                            // Remaining cells
                            $remaining = (7 - ($firstDayOfMonth + $daysInMonth) % 7) % 7;
                            for ($i = 0; $i < $remaining; $i++) echo '<div class="cal-day-cell other-month"></div>';
                            ?>
                        </div>

                        <!-- List View (hidden by default) -->
                        <div id="listView" style="display:none;">
                            <table style="width:100%;border-collapse:collapse;">
                                <thead><tr><?php foreach(['Date','Time','Patient','Doctor','Purpose','Status','Actions'] as $h): ?><th style="padding:10px;background:var(--gray-ultra);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--gray);text-align:left;"><?=$h?></th><?php endforeach; ?></tr></thead>
                                <tbody>
                                    <?php foreach ($appts as $a): ?>
                                    <tr style="border-bottom:1px solid var(--gray-ultra);">
                                        <td style="padding:10px;"><?= formatDate($a['appointment_date']) ?></td>
                                        <td style="padding:10px;"><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                                        <td style="padding:10px;font-weight:600;"><?= sanitize($a['patient_name'] ?? '—') ?></td>
                                        <td style="padding:10px;color:var(--gray);font-size:12px;"><?= sanitize($a['doctor_name'] ?? '—') ?></td>
                                        <td style="padding:10px;color:var(--gray);font-size:12px;"><?= sanitize($a['purpose'] ?? '') ?></td>
                                        <td style="padding:10px;"><span class="badge badge-<?= ['Confirmed'=>'success','Pending'=>'warning','Completed'=>'info','Cancelled'=>'danger'][$a['status']]??'gray' ?>"><?= $a['status'] ?></span></td>
                                        <td style="padding:10px;">
                                            <div class="action-links">
                                                <button class="action-link action-view" onclick='viewAppt(<?= json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fas fa-eye"></i></button>
                                                <?php if (in_array($user['role'],['admin','pharmacist','staff'])): ?>
                                                <button class="action-link action-edit" onclick='editAppt(<?= json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?')">
                                                    <input type="hidden" name="action" value="delete_appointment">
                                                    <input type="hidden" name="delete_id" value="<?= $a['id'] ?>">
                                                    <button type="submit" class="action-link action-delete"><i class="fas fa-trash"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($appts)): ?><tr><td colspan="7"><div class="empty-state"><i class="fas fa-calendar-times"></i><p>No appointments this month</p></div></td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div>
                    <!-- Upcoming -->
                    <div class="card" style="margin-bottom:16px;">
                        <div class="card-header"><div class="card-title">Upcoming</div></div>
                        <div class="card-body" style="padding:12px;">
                            <?php
                            $upcoming = $db->query("SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name FROM appointments a LEFT JOIN patients p ON a.patient_id=p.id WHERE a.appointment_date >= CURDATE() AND a.status IN ('Pending','Confirmed') ORDER BY a.appointment_date, a.appointment_time LIMIT 5");
                            $hasUpcoming = false;
                            while ($ua = $upcoming->fetch_assoc()):
                                $hasUpcoming = true;
                            ?>
                            <div style="padding:8px 0;border-bottom:1px solid var(--gray-ultra);">
                                <div style="font-weight:600;font-size:13px;"><?= sanitize($ua['patient_name'] ?? 'Unknown') ?></div>
                                <div style="font-size:11px;color:var(--gray);"><?= formatDate($ua['appointment_date']) ?> · <?= date('g:i A', strtotime($ua['appointment_time'])) ?></div>
                                <span class="badge badge-<?= $ua['status']==='Confirmed'?'success':'warning' ?>" style="margin-top:4px;"><?= $ua['status'] ?></span>
                            </div>
                            <?php endwhile;
                            if (!$hasUpcoming) echo '<div style="text-align:center;padding:16px;color:var(--gray);font-size:13px;">No upcoming appointments</div>';
                            ?>
                        </div>
                    </div>

                    <!-- Doctors Roster -->
                    <div class="card">
                        <div class="card-header"><div class="card-title">Doctors on Roster</div></div>
                        <div class="card-body" style="padding:12px;">
                            <?php foreach ($doctors as $doc): ?>
                            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--gray-ultra);">
                                <div class="avatar avatar-sm" style="background:var(--teal);"><?= strtoupper(substr($doc,3,1)) ?></div>
                                <div>
                                    <div style="font-size:12px;font-weight:600;"><?= $doc ?></div>
                                    <div style="font-size:10px;color:var(--gray);">8:00 AM – 5:00 PM</div>
                                </div>
                                <span class="badge badge-success" style="margin-left:auto;">Available</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-backdrop" id="apptModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title" id="apptModalTitle"><i class="fas fa-calendar-plus"></i> New Appointment</div>
            <button class="modal-close" onclick="closeModal('apptModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="apptForm">
            <input type="hidden" name="action" value="save_appointment">
            <input type="hidden" name="edit_id" id="aEditId" value="0">
            <div class="modal-body">
                <div class="form-row form-row-2">
                    <div class="form-group"><label class="form-label">Patient *</label>
                        <select name="patient_id" id="aPatient" class="form-control" required>
                            <option value="">Select Patient</option>
                            <?php foreach ($patients as $pt): ?><option value="<?= $pt['id'] ?>"><?= sanitize($pt['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Doctor</label>
                        <select name="doctor_name" id="aDoctor" class="form-control">
                            <option value="">Select Doctor</option>
                            <?php foreach ($doctors as $d): ?><option value="<?= $d ?>"><?= $d ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row form-row-3">
                    <div class="form-group"><label class="form-label">Date *</label><input type="date" name="appointment_date" id="aDate" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Time *</label><input type="time" name="appointment_time" id="aTime" class="form-control" required></div>
                    <div class="form-group"><label class="form-label">Duration (min)</label><input type="number" name="duration_minutes" id="aDuration" class="form-control" value="30" min="15" step="15"></div>
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group"><label class="form-label">Service Type</label>
                        <select name="service_type" id="aService" class="form-control">
                            <?php foreach ($services as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" id="aStatus" class="form-control">
                            <option value="Pending">Pending</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Purpose / Notes</label><textarea name="purpose" id="aPurpose" class="form-control" rows="2" placeholder="Reason for visit..."></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('apptModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Save Appointment</button>
            </div>
        </form>
    </div>
</div>

<!-- View Appointment Modal -->
<div class="modal-backdrop" id="viewApptModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-calendar-check"></i> Appointment Details</div>
            <button class="modal-close" onclick="closeModal('viewApptModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="viewApptBody"></div>
        <div class="modal-footer" id="viewApptFooter">
            <button class="btn btn-outline" onclick="closeModal('viewApptModal')">Close</button>
        </div>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
let calendarVisible = true;
function toggleView() {
    calendarVisible = !calendarVisible;
    document.getElementById('calendarView').style.display = calendarVisible ? 'grid' : 'none';
    document.getElementById('listView').style.display = calendarVisible ? 'none' : 'block';
}

function editAppt(a) {
    document.getElementById('apptModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Appointment';
    document.getElementById('aEditId').value = a.id;
    document.getElementById('aPatient').value = a.patient_id || '';
    document.getElementById('aDoctor').value = a.doctor_name || '';
    document.getElementById('aDate').value = a.appointment_date || '';
    document.getElementById('aTime').value = a.appointment_time || '';
    document.getElementById('aDuration').value = a.duration_minutes || 30;
    document.getElementById('aService').value = a.service_type || '';
    document.getElementById('aStatus').value = a.status || 'Pending';
    document.getElementById('aPurpose').value = a.purpose || '';
    openModal('apptModal');
}

function viewAppt(a) {
    const statusClass = {Confirmed:'success',Pending:'warning',Completed:'info',Cancelled:'danger'};
    const isPatientUser = <?= $user['role'] === 'patient' ? 'true' : 'false' ?>;
    const isOwnAppt = a.is_own === 1 || a.is_own === true;

    if (isPatientUser && !isOwnAppt) {
        document.getElementById('viewApptBody').innerHTML = `
            <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:16px;">
                <div style="font-weight:700;font-size:16px;">Booked Slot</div>
                <div style="margin-top:10px;color:var(--gray);">This time is currently occupied. Please choose another available slot or request a different date.</div>
            </div>
        `;
        document.getElementById('viewApptFooter').innerHTML = `
            <button class="btn btn-outline" onclick="closeModal('viewApptModal')">Close</button>
        `;
    } else {
        document.getElementById('viewApptBody').innerHTML = `
            <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:16px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <div style="font-weight:700;font-size:16px;">${a.patient_name || 'Unknown Patient'}</div>
                    <span class="badge badge-${statusClass[a.status]||'gray'}">${a.status}</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
                    <div><span style="color:var(--gray);">Date: </span><strong>${a.appointment_date}</strong></div>
                    <div><span style="color:var(--gray);">Time: </span><strong>${a.appointment_time}</strong></div>
                    <div><span style="color:var(--gray);">Doctor: </span><strong>${a.doctor_name || 'TBD'}</strong></div>
                    <div><span style="color:var(--gray);">Duration: </span><strong>${a.duration_minutes} min</strong></div>
                    <div><span style="color:var(--gray);">Service: </span><strong>${a.service_type || '—'}</strong></div>
                    <div><span style="color:var(--gray);">Reason: </span><strong>${a.purpose || '—'}</strong></div>
                </div>
            </div>
        `;
        if (isPatientUser) {
            document.getElementById('viewApptFooter').innerHTML = `
                <div style="display:flex;gap:8px;align-items:center;">
                    <span style="color:var(--gray);font-size:13px;">Your appointment is listed here. Status updates will appear once confirmed.</span>
                    <button class="btn btn-outline" onclick="closeModal('viewApptModal')">Close</button>
                </div>
            `;
        } else {
            document.getElementById('viewApptFooter').innerHTML = `
                <form method="POST" style="display:flex;gap:8px;align-items:center;flex:1;">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="appt_id" value="${a.id}">
                    <select name="status" class="filter-select" style="flex:1;">
                        ${['Pending','Confirmed','Completed','Cancelled'].map(s => `<option value="${s}" ${s===a.status?'selected':''}>${s}</option>`).join('')}
                    </select>
                    <button type="submit" class="btn btn-teal btn-sm"><i class="fas fa-check"></i> Update</button>
                </form>
                <button class="btn btn-outline" onclick="closeModal('viewApptModal')">Close</button>
            `;
        }
    }
    openModal('viewApptModal');
}

document.querySelector('[onclick="openModal(\'apptModal\')"]')?.addEventListener('click', () => {
    document.getElementById('apptModalTitle').innerHTML = '<i class="fas fa-calendar-plus"></i> New Appointment';
    document.getElementById('aEditId').value = '0';
    document.getElementById('apptForm').reset();
    document.getElementById('aDate').value = '<?= date('Y-m-d') ?>';
});
</script>
</body>
</html>
