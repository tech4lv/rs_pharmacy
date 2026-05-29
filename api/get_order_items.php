<?php
require_once '../includes/auth.php';
requireLogin('../login.php');
header('Content-Type: application/json');

$db = getDB();
$orderId = (int)($_GET['order_id'] ?? 0);

if (!$orderId) { echo json_encode(['items'=>[]]); exit; }

$items = [];
$res = $db->query("SELECT oi.*, p.name as product_name_db FROM order_items oi LEFT JOIN products p ON oi.product_id=p.id WHERE oi.order_id=$orderId ORDER BY oi.id");
while ($row = $res->fetch_assoc()) {
    $row['product_name'] = $row['product_name'] ?: $row['product_name_db'] ?: 'Unknown';
    $items[] = $row;
}

echo json_encode(['items' => $items]);
