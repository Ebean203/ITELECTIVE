<?php
session_start();
include 'conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Ensure notifications table exists
function ensureNotificationsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS notifications (\n        notification_id INT AUTO_INCREMENT PRIMARY KEY,\n        customer_id INT NOT NULL,\n        order_id INT NULL,\n        type VARCHAR(50) NOT NULL,\n        message TEXT NOT NULL,\n        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n        read_at TIMESTAMP NULL DEFAULT NULL,\n        INDEX idx_notifications_customer (customer_id, created_at)\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $sql);
}
ensureNotificationsTable($conn);

$action = $_POST['action'] ?? '';

if ($action === 'update_status') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $allowed = ['PLACED','SHIPPED','DELIVERED','CANCELLED'];
    if ($orderId <= 0 || !in_array($status, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }
    // Update status
    $upd = mysqli_prepare($conn, "UPDATE orders SET status = ? WHERE order_id = ?");
    mysqli_stmt_bind_param($upd, 'si', $status, $orderId);
    if (!mysqli_stmt_execute($upd)) {
        echo json_encode(['success' => false, 'message' => 'Update failed']);
        exit;
    }
    // Get customer for notification
    $res = mysqli_query($conn, "SELECT customer_id FROM orders WHERE order_id = " . $orderId);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    $customerId = $row ? (int)$row['customer_id'] : 0;
    if ($customerId > 0) {
        $msg = "Your order #$orderId status is now $status.";
        $ins = mysqli_prepare($conn, "INSERT INTO notifications (customer_id, order_id, type, message) VALUES (?, ?, 'order_status', ?)");
        mysqli_stmt_bind_param($ins, 'iis', $customerId, $orderId, $msg);
        mysqli_stmt_execute($ins);
    }
    echo json_encode(['success' => true, 'message' => "Order #$orderId updated to $status."]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
exit;

