<?php
session_start();
include 'conn.php';

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int)$_SESSION['customer_id'];

// Ensure tables exist (safe if already created by other pages)
function ensureOrderTables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS orders (\n        order_id INT AUTO_INCREMENT PRIMARY KEY,\n        customer_id INT NOT NULL,\n        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n        discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n        final_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n        voucher_code VARCHAR(64) DEFAULT NULL,\n        status VARCHAR(20) NOT NULL DEFAULT 'PLACED',\n        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n        contact_email VARCHAR(255) NULL,\n        payment_method VARCHAR(32) NULL,\n        shipping_full_name VARCHAR(255) NULL,\n        shipping_street VARCHAR(255) NULL,\n        shipping_city VARCHAR(128) NULL,\n        shipping_province VARCHAR(128) NULL,\n        shipping_postal_code VARCHAR(32) NULL,\n        shipping_phone VARCHAR(32) NULL,\n        billing_same_as_shipping TINYINT(1) NOT NULL DEFAULT 1,\n        billing_full_name VARCHAR(255) NULL,\n        billing_street VARCHAR(255) NULL,\n        billing_city VARCHAR(128) NULL,\n        billing_province VARCHAR(128) NULL,\n        billing_postal_code VARCHAR(32) NULL,\n        billing_phone VARCHAR(32) NULL\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS order_items (\n        order_item_id INT AUTO_INCREMENT PRIMARY KEY,\n        order_id INT NOT NULL,\n        product_id INT NOT NULL,\n        quantity INT NOT NULL,\n        price DECIMAL(10,2) NOT NULL,\n        subtotal DECIMAL(10,2) NOT NULL,\n        CONSTRAINT oh_fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureOrderTables($conn);

// Fetch customer name for header
$user_name = '';
$res = mysqli_query($conn, "SELECT CONCAT(first_name,' ',last_name) AS name FROM customers WHERE customer_id = " . $customerId);
if ($res && ($r = mysqli_fetch_assoc($res))) { $user_name = $r['name']; }

// Fetch orders with summary
$orders = [];
$ordRes = mysqli_query($conn, "SELECT * FROM orders WHERE customer_id = $customerId ORDER BY created_at DESC, order_id DESC");
if ($ordRes) {
    while ($o = mysqli_fetch_assoc($ordRes)) {
        $orders[] = $o;
    }
}

// Preload products for order items mapping
function getOrderItems($conn, $orderId) {
    $items = [];
    $stmt = mysqli_prepare($conn, "SELECT oi.*, p.product_name, p.photo FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = ? ORDER BY oi.order_item_id");
    mysqli_stmt_bind_param($stmt, 'i', $orderId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) { $items[] = $row; }
    }
    return $items;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg,rgb(18,129,156) 0%,rgb(30,154,192) 100%); min-height: 100vh; }
        .main-container { background: rgba(255,255,255,0.95); border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); margin: 30px auto; max-width: 1100px; }
        .page-header { background: linear-gradient(135deg,rgb(101,163,214) 0%,rgb(120,162,224) 100%); color: white; padding: 30px; border-radius: 20px 20px 0 0; text-align:center; }
        .order-card { background:white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); margin-bottom: 20px; overflow:hidden; }
        .order-header { background:#f8f9fa; padding: 15px 20px; border-bottom: 1px solid #e9ecef; }
        .order-items { padding: 15px 20px; }
        .product-thumb { width:60px; height:60px; object-fit:cover; border-radius:8px; }
        .empty-state { text-align:center; padding: 50px 20px; color: #6c757d; }
        .badge-status { font-size: .85rem; }
    </style>
    </head>
<body>
    <div class="main-container">
        <div class="d-flex justify-content-end p-3">
            <div class="d-flex align-items-center gap-3 bg-white shadow-sm px-4 py-2 rounded-pill">
                <i class="fas fa-user-circle fa-lg text-primary"></i>
                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($user_name); ?></span>
                <a href="view_product.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-store"></i></a>
                <form action="logout.php" method="POST" class="mb-0">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-sign-out-alt"></i></button>
                </form>
            </div>
        </div>
        <div class="page-header">
            <h1><i class="fas fa-receipt me-2"></i>Your Order History</h1>
            <p class="mb-0">Track your previous purchases and their status</p>
        </div>
        <div class="p-4">
            <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x mb-3" style="color:#dee2e6"></i>
                    <h4>No orders yet</h4>
                    <p>When you place orders, they'll show up here.</p>
                    <a class="btn btn-primary mt-2" href="view_product.php"><i class="fas fa-shopping-bag me-1"></i> Shop now</a>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <?php $items = getOrderItems($conn, (int)$order['order_id']); ?>
                    <div class="order-card">
                        <div class="order-header d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">Order #<?php echo (int)$order['order_id']; ?></div>
                                <div class="text-muted small">Placed on <?php echo htmlspecialchars($order['created_at']); ?></div>
                            </div>
                            <div class="text-end">
                                <div>
                                    <span class="badge badge-status <?php echo ($order['status']==='PLACED'?'bg-primary':($order['status']==='CANCELLED'?'bg-danger':'bg-secondary')); ?>"><?php echo htmlspecialchars($order['status']); ?></span>
                                </div>
                                <?php if (!empty($order['voucher_code'])): ?>
                                    <div class="small text-success">Voucher: <?php echo htmlspecialchars($order['voucher_code']); ?></div>
                                <?php endif; ?>
                                <div class="fw-bold">Total: ₱<?php echo number_format((float)$order['final_amount'], 2); ?></div>
                                <?php if ((float)$order['discount_amount'] > 0): ?>
                                    <div class="text-success small">You saved ₱<?php echo number_format((float)$order['discount_amount'], 2); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="order-items">
                            <?php foreach ($items as $it): ?>
                                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($it['photo'])): ?>
                                            <img src="<?php echo htmlspecialchars($it['photo']); ?>" class="product-thumb" alt="product">
                                        <?php else: ?>
                                            <div class="bg-light d-flex align-items-center justify-content-center product-thumb" style="color:#adb5bd"><i class="fas fa-image"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($it['product_name'] ?? ('Product #' . (int)$it['product_id'])); ?></div>
                                            <div class="text-muted small">Qty: <?php echo (int)$it['quantity']; ?> × ₱<?php echo number_format((float)$it['price'], 2); ?></div>
                                        </div>
                                    </div>
                                    <div class="fw-semibold">₱<?php echo number_format((float)$it['subtotal'], 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="px-3 py-2 small text-muted">
                            <span>Contact: <?php echo htmlspecialchars($order['contact_email'] ?? ''); ?></span>
                            <?php if (!empty($order['payment_method'])): ?>
                                <span class="ms-3">Payment: <?php echo htmlspecialchars($order['payment_method']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

