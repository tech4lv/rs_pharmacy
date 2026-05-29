<?php
require_once 'includes/auth.php';
requireRole(['admin'], 'login.php');
$user = getCurrentUser();
$db   = getDB();

// Filters
$search     = sanitize($_GET['search']   ?? '');
$filterSev  = sanitize($_GET['severity'] ?? '');
$filterType = sanitize($_GET['type']     ?? '');
$filterDate = sanitize($_GET['date']     ?? '');

$where = "WHERE 1";
if ($search)     $where .= " AND (action LIKE '%$search%' OR user_name LIKE '%$search%' OR audit_id LIKE '%$search%')";
if ($filterSev)  $where .= " AND severity='$filterSev'";
if ($filterType) $where .= " AND action_type='$filterType'";
if ($filterDate) $where .= " AND DATE(created_at)='$filterDate'";

$logs = [];
$res = $db->query("SELECT * FROM audit_logs $where ORDER BY created_at DESC LIMIT 200");
while ($row = $res->fetch_assoc()) $logs[] = $row;

// Stats
$totalLogs  = $db->query("SELECT COUNT(*) as c FROM audit_logs")->fetch_assoc()['c'];
$criticalCnt = $db->query("SELECT COUNT(*) as c FROM audit_logs WHERE severity='CRITICAL'")->fetch_assoc()['c'];
$warningCnt  = $db->query("SELECT COUNT(*) as c FROM audit_logs WHERE severity='WARNING'")->fetch_assoc()['c'];
$todayCnt    = $db->query("SELECT COUNT(*) as c FROM audit_logs WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'];

$severityColors = ['INFO'=>'info','WARNING'=>'warning','CRITICAL'=>'danger'];
$actionTypeIcons = [
    'CREATE'=>['fa-plus-circle','teal'],'READ'=>['fa-eye','gray'],'UPDATE'=>['fa-pen','blue'],
    'DELETE'=>['fa-trash','danger'],'LOGIN'=>['fa-sign-in-alt','success'],'LOGOUT'=>['fa-sign-out-alt','gray'],
    'STOCK_IN'=>['fa-arrow-up','success'],'STOCK_OUT'=>['fa-arrow-down','danger'],'SALE'=>['fa-cash-register','primary'],
    'WARNING'=>['fa-triangle-exclamation','warning'],'CRITICAL'=>['fa-skull-crossbones','danger'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Audit Logs — RS Pharmacy</title>
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
                <div class="page-breadcrumb">RS Pharmacy / <span>Audit Logs</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div><div class="page-title">Audit Logs</div><div class="page-subtitle">Complete system activity trail and security monitoring</div></div>
                <a href="?export=csv" class="btn btn-outline"><i class="fas fa-download"></i> Export CSV</a>
            </div>

            <!-- Stats -->
            <div class="stat-grid stat-grid-4" style="margin-bottom:20px;">
                <div class="stat-card teal"><div class="stat-icon teal"><i class="fas fa-shield-check"></i></div><div class="stat-value"><?=$totalLogs?></div><div class="stat-label">Total Events</div><div class="stat-progress"><div class="stat-progress-bar teal" style="width:80%"></div></div></div>
                <div class="stat-card red"><div class="stat-icon red"><i class="fas fa-skull-crossbones"></i></div><div class="stat-value"><?=$criticalCnt?></div><div class="stat-label">Critical Alerts</div><div class="stat-progress"><div class="stat-progress-bar red" style="width:<?=min(100,$criticalCnt*10)?>%"></div></div></div>
                <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-value"><?=$warningCnt?></div><div class="stat-label">Warnings</div><div class="stat-progress"><div class="stat-progress-bar orange" style="width:<?=min(100,$warningCnt*8)?>%"></div></div></div>
                <div class="stat-card blue"><div class="stat-icon blue"><i class="fas fa-calendar-day"></i></div><div class="stat-value"><?=$todayCnt?></div><div class="stat-label">Today's Activity</div><div class="stat-progress"><div class="stat-progress-bar blue" style="width:<?=min(100,$todayCnt*5)?>%"></div></div></div>
            </div>

            <div class="table-wrapper">
                <div class="table-toolbar">
                    <div class="table-toolbar-left">
                        <div class="search-bar"><i class="fas fa-search"></i><input type="text" id="logSearch" placeholder="Search logs..." value="<?=htmlspecialchars($search)?>"></div>
                        <select class="filter-select" id="severityFilter">
                            <option value="">All Severity</option>
                            <?php foreach(['INFO','WARNING','CRITICAL'] as $s): ?><option value="<?=$s?>" <?=$filterSev===$s?'selected':''?>><?=$s?></option><?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="typeFilter">
                            <option value="">All Types</option>
                            <?php foreach(['CREATE','READ','UPDATE','DELETE','LOGIN','LOGOUT','STOCK_IN','STOCK_OUT','SALE','WARNING','CRITICAL'] as $t): ?><option value="<?=$t?>" <?=$filterType===$t?'selected':''?>><?=$t?></option><?php endforeach; ?>
                        </select>
                        <input type="date" class="filter-select" id="dateFilter" value="<?=htmlspecialchars($filterDate)?>" title="Filter by date">
                    </div>
                    <div class="table-toolbar-right"><span class="text-muted"><?=count($logs)?> records</span></div>
                </div>
                <div class="table-responsive">
                    <table id="auditTable">
                        <thead>
                            <tr>
                                <th>Audit ID</th>
                                <th>Action</th>
                                <th>Type</th>
                                <th>Severity</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Table</th>
                                <th>IP Address</th>
                                <th>Timestamp</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log):
                                $icons = $actionTypeIcons[$log['action_type']] ?? ['fa-circle','gray'];
                            ?>
                            <tr style="<?=$log['severity']==='CRITICAL'?'background:rgba(255,59,48,0.03);':($log['severity']==='WARNING'?'background:rgba(255,159,10,0.03);':'')?>">
                                <td><span style="font-family:monospace;font-size:11px;color:var(--gray);"><?=sanitize($log['audit_id']??'—')?></span></td>
                                <td>
                                    <div style="font-weight:600;font-size:13px;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?=htmlspecialchars($log['action'])?>"><?=sanitize($log['action'])?></div>
                                    <?php if($log['notes']): ?><div style="font-size:11px;color:var(--gray);max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=sanitize($log['notes'])?></div><?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?=$icons[1]?>"><i class="fas <?=$icons[0]?>"></i> <?=str_replace('_',' ',$log['action_type']??'')?></span>
                                </td>
                                <td>
                                    <span class="badge badge-<?=$severityColors[$log['severity']]??'gray'?>"><?=$log['severity']?></span>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div class="avatar avatar-sm" style="background:var(--teal);"><?=strtoupper(substr($log['user_name']??'?',0,1))?></div>
                                        <span style="font-size:13px;"><?=sanitize($log['user_name']??'System')?></span>
                                    </div>
                                </td>
                                <td><span class="badge badge-gray"><?=ucfirst($log['user_role']??'—')?></span></td>
                                <td class="text-muted"><?=sanitize($log['affected_table']??'—')?><?=$log['record_id']?' #'.$log['record_id']:''?></td>
                                <td style="font-family:monospace;font-size:11px;color:var(--gray);"><?=sanitize($log['ip_address']??'—')?></td>
                                <td class="text-muted"><?=formatDateTime($log['created_at'])?></td>
                                <td>
                                    <?php if($log['old_values'] || $log['new_values']): ?>
                                    <button class="action-link action-view" onclick='viewLogDetails(<?=json_encode($log, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)?>)'><i class="fas fa-code"></i></button>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($logs)): ?><tr><td colspan="10"><div class="empty-state"><i class="fas fa-shield-check"></i><p>No audit logs found</p></div></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Log Details Modal -->
<div class="modal-backdrop" id="logDetailModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-code"></i> Log Details</div>
            <button class="modal-close" onclick="closeModal('logDetailModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="logDetailBody"></div>
        <div class="modal-footer"><button class="btn btn-outline" onclick="closeModal('logDetailModal')">Close</button></div>
    </div>
</div>

<div class="toast-container"></div>
<script src="assets/js/main.js"></script>
<script>
// Search and filters
document.getElementById('logSearch').addEventListener('keyup', function() {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#auditTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
});

function applyFilters() {
    const sev  = document.getElementById('severityFilter').value;
    const type = document.getElementById('typeFilter').value;
    const date = document.getElementById('dateFilter').value;
    const q    = document.getElementById('logSearch').value;
    window.location.href = `?severity=${sev}&type=${type}&date=${date}&search=${q}`;
}
document.getElementById('severityFilter').addEventListener('change', applyFilters);
document.getElementById('typeFilter').addEventListener('change', applyFilters);
document.getElementById('dateFilter').addEventListener('change', applyFilters);

function viewLogDetails(log) {
    const severityClass = {INFO:'info',WARNING:'warning',CRITICAL:'danger'};
    let html = `
        <div style="background:var(--gray-ultra);border-radius:var(--radius-sm);padding:14px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <span style="font-family:monospace;font-size:14px;font-weight:700;">${log.audit_id||'—'}</span>
                <div style="display:flex;gap:6px;">
                    <span class="badge badge-${severityClass[log.severity]||'gray'}">${log.severity}</span>
                    <span class="badge badge-gray">${log.action_type}</span>
                </div>
            </div>
            <div style="font-size:14px;font-weight:600;margin-bottom:8px;">${log.action}</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;color:var(--gray);">
                <span><i class="fas fa-user"></i> ${log.user_name||'System'} (${log.user_role||'—'})</span>
                <span><i class="fas fa-table"></i> ${log.affected_table||'—'}${log.record_id?' #'+log.record_id:''}</span>
                <span><i class="fas fa-globe"></i> ${log.ip_address||'—'}</span>
                <span><i class="fas fa-clock"></i> ${log.created_at||'—'}</span>
            </div>
        </div>
    `;
    if (log.old_values) {
        html += `<div style="margin-bottom:12px;"><div style="font-size:12px;font-weight:700;color:var(--danger);text-transform:uppercase;margin-bottom:6px;"><i class="fas fa-arrow-left"></i> Before</div><pre style="background:rgba(255,59,48,0.05);border:1px solid rgba(255,59,48,0.15);border-radius:var(--radius-sm);padding:12px;font-size:12px;overflow-x:auto;margin:0;">${JSON.stringify(JSON.parse(log.old_values), null, 2)}</pre></div>`;
    }
    if (log.new_values) {
        html += `<div><div style="font-size:12px;font-weight:700;color:var(--success);text-transform:uppercase;margin-bottom:6px;"><i class="fas fa-arrow-right"></i> After</div><pre style="background:rgba(48,209,88,0.05);border:1px solid rgba(48,209,88,0.15);border-radius:var(--radius-sm);padding:12px;font-size:12px;overflow-x:auto;margin:0;">${JSON.stringify(JSON.parse(log.new_values), null, 2)}</pre></div>`;
    }
    if (log.notes) {
        html += `<div style="margin-top:12px;padding:10px;background:rgba(10,132,255,0.05);border:1px solid rgba(10,132,255,0.15);border-radius:var(--radius-sm);font-size:13px;color:var(--info);"><i class="fas fa-sticky-note"></i> ${log.notes}</div>`;
    }
    document.getElementById('logDetailBody').innerHTML = html;
    openModal('logDetailModal');
}
</script>
</body>
</html>
