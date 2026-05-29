<?php
require_once 'includes/auth.php';
requireLogin('login.php');
$user = getCurrentUser();
$db   = getDB();

// Mark all as read
if (isset($_GET['mark_all'])) {
    $db->query("UPDATE notifications SET is_read=1 WHERE user_id={$user['id']}");
    header('Location: notifications.php');
    exit;
}

// Mark single as read
if (isset($_GET['read'])) {
    $id = (int)$_GET['read'];
    $db->query("UPDATE notifications SET is_read=1 WHERE id=$id AND user_id={$user['id']}");
}

// Delete notification
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_notif') {
    $id = (int)($_POST['notif_id']??0);
    if ($id) $db->query("DELETE FROM notifications WHERE id=$id AND user_id={$user['id']}");
    header('Location: notifications.php');
    exit;
}

// Fetch notifications
$notifications = [];
$res = $db->query("SELECT * FROM notifications WHERE user_id={$user['id']} ORDER BY created_at DESC");
while ($row = $res->fetch_assoc()) $notifications[] = $row;

$unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));

$typeIcons = ['info'=>['fa-circle-info','info'],'warning'=>['fa-triangle-exclamation','warning'],'alert'=>['fa-bell','danger'],'success'=>['fa-circle-check','success']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Notifications — RS Pharmacy</title>
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
                <div class="page-breadcrumb">RS Pharmacy / <span>Notifications</span></div>
            </div>
            <div class="topbar-right">
                <a href="./logout.php" class="topbar-btn"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>
        <div class="page-content">
            <div class="page-header">
                <div>
                    <div class="page-title">Notifications</div>
                    <div class="page-subtitle"><?=$unreadCount?> unread notification<?=$unreadCount!=1?'s':''?></div>
                </div>
                <?php if ($unreadCount > 0): ?>
                <a href="?mark_all=1" class="btn btn-outline"><i class="fas fa-check-double"></i> Mark All Read</a>
                <?php endif; ?>
            </div>

            <div style="max-width:720px;display:flex;flex-direction:column;gap:10px;">
                <?php if(empty($notifications)): ?>
                <div class="empty-state" style="background:var(--white);border-radius:var(--radius);padding:60px;"><i class="fas fa-bell-slash"></i><p>No notifications yet</p></div>
                <?php endif; ?>

                <?php foreach ($notifications as $n):
                    $icons = $typeIcons[$n['type']] ?? ['fa-bell','gray'];
                    $isUnread = !$n['is_read'];
                ?>
                <div class="card" style="border-left:4px solid <?=$isUnread?'var(--'.($n['type']==='alert'?'danger':($n['type']==='warning'?'warning':'teal')).')':'var(--gray-light)'?>;<?=$isUnread?'background:rgba(0,0,0,0.01);':''?>">
                    <div class="card-body" style="display:flex;gap:14px;align-items:flex-start;padding:14px 16px;">
                        <div style="width:36px;height:36px;border-radius:50%;background:rgba(<?=$icons[1]==='danger'?'255,59,48':($icons[1]==='warning'?'255,159,10':($icons[1]==='success'?'48,209,88':'10,132,255'))?>,0.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fas <?=$icons[0]?>" style="color:var(--<?=$icons[1]?>);font-size:15px;"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;">
                                <div style="font-weight:<?=$isUnread?'700':'600'?>;font-size:14px;"><?=sanitize($n['title']??'Notification')?></div>
                                <?php if($isUnread): ?><span style="width:7px;height:7px;border-radius:50%;background:var(--primary);display:inline-block;flex-shrink:0;"></span><?php endif; ?>
                            </div>
                            <div style="font-size:13px;color:var(--gray);line-height:1.5;"><?=sanitize($n['message'])?></div>
                            <div style="font-size:11px;color:var(--gray-light);margin-top:6px;"><?=formatDateTime($n['created_at'])?></div>
                        </div>
                        <div style="display:flex;gap:6px;flex-shrink:0;">
                            <?php if ($isUnread): ?>
                            <a href="?read=<?=$n['id']?>" class="action-link action-approve" title="Mark Read"><i class="fas fa-check"></i></a>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this notification?')">
                                <input type="hidden" name="action" value="delete_notif">
                                <input type="hidden" name="notif_id" value="<?=$n['id']?>">
                                <button type="submit" class="action-link action-delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/main.js"></script>
</body>
</html>
