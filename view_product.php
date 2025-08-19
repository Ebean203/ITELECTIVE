    <?php
    session_start();
    include('conn.php');
    if (!isset($_SESSION['admin_id']) && !isset($_SESSION['customer_id'])) {
        header("Location: login.php");
        exit;
    }

    // Get logged-in user's name
    $user_name = '';

    if (isset($_SESSION['customer_id'])) {
        $result = mysqli_query($conn, "SELECT CONCAT(first_name, ' ', last_name) AS name FROM customers WHERE customer_id = " . intval($_SESSION['customer_id']));
        if ($row = mysqli_fetch_assoc($result)) {
            $user_name = $row['name'];
        }
    } elseif (isset($_SESSION['admin_id'])) {
        $result = mysqli_query($conn, "SELECT username AS name FROM admins WHERE admin_id = " . intval($_SESSION['admin_id']));
        if ($row = mysqli_fetch_assoc($result)) {
            $user_name = 'Admin: ' . $row['name'];
        }
    } else {
        header("Location: login.php");
        exit;
    }

    $isCustomer = isset($_SESSION['customer_id']);

    // Set charset
    mysqli_set_charset($conn, "utf8");

    // Fetch categories
    function getCategories($conn) {
        $sql = "SELECT category_id, category_name FROM category ORDER BY category_name";
        $result = mysqli_query($conn, $sql);
        $categories = [];
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $categories[] = $row;
            }
        }
        return $categories;
    }

    function getProductsByCategory($conn, $category_id) {
        $sql = "SELECT p.product_id, p.product_name, p.description, p.photo, c.category_name 
                FROM products p 
                LEFT JOIN category c ON p.category_id = c.category_id 
                WHERE p.category_id = ? 
                ORDER BY p.product_name";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $category_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $products = [];
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $products[] = $row;
            }
        }
        return $products;
    }

    function getProductImages($conn, $product_id) {
        $sql = "SELECT image_id, image_filename, is_featured 
                FROM product_images 
                WHERE product_id = ? 
                ORDER BY is_featured DESC, image_id ASC";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $images = [];
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $images[] = $row;
            }
        }
        return $images;
    }

    function getProductById($conn, $product_id) {
        $sql = "SELECT p.product_id, p.product_name, p.description, p.photo, p.price, p.quantity, c.category_name, c.category_id 
            FROM products p 
            LEFT JOIN category c ON p.category_id = c.category_id 
            WHERE p.product_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $product_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result && mysqli_num_rows($result) > 0) {
            $product = mysqli_fetch_assoc($result);
            // Get additional images
            $product['images'] = getProductImages($conn, $product_id);
            return $product;
        }
        return null;
    }

    function countProductsInCategory($conn, $category_id) {
        $sql = "SELECT COUNT(*) as count FROM products WHERE category_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $category_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        return $row['count'];
    }

    // ---- Cart, Voucher and Order Utilities ----
    function &getCartRef() {
        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        return $_SESSION['cart'];
    }

    function getCartCount() {
        $cart = getCartRef();
        $count = 0;
        foreach ($cart as $productId => $quantity) {
            $count += max(0, (int)$quantity);
        }
        return $count;
    }

    function getCartSummary($conn) {
        $cart = getCartRef();
        $items = [];
        $total = 0.0;
        if (empty($cart)) {
            return [
                'items' => [],
                'total' => 0.0,
                'discount' => 0.0,
                'final_total' => 0.0,
                'count' => 0,
                'voucher' => null
            ];
        }

        // Prepare statement once
        $stmt = mysqli_prepare($conn, "SELECT product_id, product_name, price, quantity, photo FROM products WHERE product_id = ?");
        foreach ($cart as $productId => $quantity) {
            $pid = (int)$productId;
            $qty = max(1, (int)$quantity);
            mysqli_stmt_bind_param($stmt, "i", $pid);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($result && $product = mysqli_fetch_assoc($result)) {
                $availableQty = (int)$product['quantity'];
                $price = (float)$product['price'];
                $clampedQty = min($qty, max(0, $availableQty));
                $subtotal = $price * $clampedQty;
                $total += $subtotal;
                $items[] = [
                    'product_id' => $pid,
                    'product_name' => $product['product_name'],
                    'price' => $price,
                    'requested_qty' => $qty,
                    'quantity' => $clampedQty,
                    'max_quantity' => $availableQty,
                    'subtotal' => $subtotal,
                    'photo' => $product['photo']
                ];
            }
        }
        // Try to apply voucher if available in session
        $voucherInfo = null;
        $discount = 0.0;
        if (isset($_SESSION['voucher_code']) && $_SESSION['voucher_code'] !== '') {
            $evaluation = evaluateVoucher($conn, $_SESSION['voucher_code'], isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null, $total);
            if ($evaluation['valid']) {
                $discount = min($evaluation['discount'], $total);
                $voucherInfo = [
                    'code' => $evaluation['voucher']['code'],
                    'label' => $evaluation['label'],
                    'discount' => $discount
                ];
            } else {
                // If voucher invalid, clear it silently from session
                unset($_SESSION['voucher_code']);
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'discount' => $discount,
            'final_total' => max(0.0, $total - $discount),
            'count' => getCartCount(),
            'voucher' => $voucherInfo
        ];
    }

    function addToCart($productId, $quantity) {
        $cart = getCartRef();
        $pid = (int)$productId;
        $qty = max(1, (int)$quantity);
        if (isset($cart[$pid])) {
            $cart[$pid] += $qty;
        } else {
            $cart[$pid] = $qty;
        }
        $_SESSION['cart'] = $cart;
        return true;
    }

    function updateCartQuantity($productId, $quantity) {
        $cart = getCartRef();
        $pid = (int)$productId;
        $qty = max(0, (int)$quantity);
        if ($qty <= 0) {
            unset($cart[$pid]);
        } else {
            $cart[$pid] = $qty;
        }
        $_SESSION['cart'] = $cart;
        return true;
    }

    function removeCartItem($productId) {
        $cart = getCartRef();
        $pid = (int)$productId;
        if (isset($cart[$pid])) {
            unset($cart[$pid]);
            $_SESSION['cart'] = $cart;
        }
        return true;
    }

    function clearCart() {
        $_SESSION['cart'] = [];
    }

    function ensureOrderTables($conn) {
        $ordersSql = "CREATE TABLE IF NOT EXISTS orders (\n            order_id INT AUTO_INCREMENT PRIMARY KEY,\n            customer_id INT NOT NULL,\n            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n            final_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n            voucher_code VARCHAR(64) DEFAULT NULL,\n            status VARCHAR(20) NOT NULL DEFAULT 'PLACED',\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $orderItemsSql = "CREATE TABLE IF NOT EXISTS order_items (\n            order_item_id INT AUTO_INCREMENT PRIMARY KEY,\n            order_id INT NOT NULL,\n            product_id INT NOT NULL,\n            quantity INT NOT NULL,\n            price DECIMAL(10,2) NOT NULL,\n            subtotal DECIMAL(10,2) NOT NULL,\n            CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        mysqli_query($conn, $ordersSql);
        mysqli_query($conn, $orderItemsSql);

        // Attempt to add new columns if table already existed without them
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN final_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN voucher_code VARCHAR(64) DEFAULT NULL"); } catch (Throwable $e) {}

        // Add checkout detail columns (safe if already exist)
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN contact_email VARCHAR(255) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(32) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN shipping_full_name VARCHAR(255) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN shipping_street VARCHAR(255) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN shipping_city VARCHAR(128) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN shipping_province VARCHAR(128) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN shipping_postal_code VARCHAR(32) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN shipping_phone VARCHAR(32) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN billing_same_as_shipping TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN billing_full_name VARCHAR(255) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN billing_street VARCHAR(255) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN billing_city VARCHAR(128) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN billing_province VARCHAR(128) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN billing_postal_code VARCHAR(32) NULL"); } catch (Throwable $e) {}
        try { mysqli_query($conn, "ALTER TABLE orders ADD COLUMN billing_phone VARCHAR(32) NULL"); } catch (Throwable $e) {}
    }

    function placeOrder($conn, $customerId, $orderData = []) {
        ensureOrderTables($conn);
        ensureVoucherTables($conn);

        $summary = getCartSummary($conn);
        if (empty($summary['items'])) {
            return ['success' => false, 'message' => 'Cart is empty'];
        }

        // Validate stock
        foreach ($summary['items'] as $item) {
            if ($item['quantity'] <= 0) {
                return ['success' => false, 'message' => 'Insufficient stock for ' . $item['product_name']];
            }
            if ($item['requested_qty'] > $item['max_quantity']) {
                return ['success' => false, 'message' => 'Not enough stock for ' . $item['product_name'] . '. Available: ' . $item['max_quantity']];
            }
        }

        // Re-evaluate voucher at order time to be safe
        $voucherCode = isset($_SESSION['voucher_code']) ? $_SESSION['voucher_code'] : null;
        $orderDiscount = 0.0;
        $voucherRow = null;
        $voucherLabel = '';
        if ($voucherCode) {
            $evaluation = evaluateVoucher($conn, $voucherCode, $customerId, $summary['total']);
            if ($evaluation['valid']) {
                $orderDiscount = min($evaluation['discount'], $summary['total']);
                $voucherRow = $evaluation['voucher'];
                $voucherLabel = $evaluation['label'];
            } else {
                // If invalid now, do not apply
                $voucherCode = null;
                $orderDiscount = 0.0;
            }
        }

        mysqli_begin_transaction($conn);
        try {
            // Create order
            $insertOrder = mysqli_prepare($conn, "INSERT INTO orders (customer_id, total_amount, discount_amount, final_amount, voucher_code, status) VALUES (?, ?, ?, ?, ?, 'PLACED')");
            $totalAmount = $summary['total'];
            $finalAmount = max(0.0, $totalAmount - $orderDiscount);
            mysqli_stmt_bind_param($insertOrder, "iddds", $customerId, $totalAmount, $orderDiscount, $finalAmount, $voucherCode);
            mysqli_stmt_execute($insertOrder);
            $orderId = mysqli_insert_id($conn);

            // Insert items and update stock
            $insertItem = mysqli_prepare($conn, "INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
            $updateStock = mysqli_prepare($conn, "UPDATE products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?");

            foreach ($summary['items'] as $item) {
                $pid = $item['product_id'];
                $qty = $item['requested_qty'];
                $price = $item['price'];
                $subtotal = $price * $qty;

                // Update stock atomically
                mysqli_stmt_bind_param($updateStock, "iii", $qty, $pid, $qty);
                mysqli_stmt_execute($updateStock);
                if (mysqli_stmt_affected_rows($updateStock) !== 1) {
                    throw new Exception('Insufficient stock for ' . $item['product_name']);
                }

                mysqli_stmt_bind_param($insertItem, "iiidd", $orderId, $pid, $qty, $price, $subtotal);
                mysqli_stmt_execute($insertItem);
            }

            // Save checkout details to order if provided
            $allowedFields = [
                'contact_email', 'payment_method',
                'shipping_full_name', 'shipping_street', 'shipping_city', 'shipping_province', 'shipping_postal_code', 'shipping_phone',
                'billing_same_as_shipping', 'billing_full_name', 'billing_street', 'billing_city', 'billing_province', 'billing_postal_code', 'billing_phone'
            ];
            $updateValues = [];
            foreach ($allowedFields as $f) {
                $updateValues[$f] = isset($orderData[$f]) ? trim((string)$orderData[$f]) : '';
            }

            // If billing_same_as_shipping is truthy, copy shipping to billing
            $billingSame = !empty($orderData['billing_same_as_shipping']) && (string)$orderData['billing_same_as_shipping'] !== '0';
            if ($billingSame) {
                $updateValues['billing_full_name'] = $updateValues['shipping_full_name'];
                $updateValues['billing_street'] = $updateValues['shipping_street'];
                $updateValues['billing_city'] = $updateValues['shipping_city'];
                $updateValues['billing_province'] = $updateValues['shipping_province'];
                $updateValues['billing_postal_code'] = $updateValues['shipping_postal_code'];
                $updateValues['billing_phone'] = $updateValues['shipping_phone'];
            }

            // Update order row with checkout details
            $upd = mysqli_prepare($conn, "UPDATE orders SET 
                contact_email = ?, payment_method = ?,
                shipping_full_name = ?, shipping_street = ?, shipping_city = ?, shipping_province = ?, shipping_postal_code = ?, shipping_phone = ?,
                billing_same_as_shipping = ?, billing_full_name = ?, billing_street = ?, billing_city = ?, billing_province = ?, billing_postal_code = ?, billing_phone = ?
                WHERE order_id = ?");
            $billingSameInt = $billingSame ? 1 : 0;
            mysqli_stmt_bind_param(
                $upd,
                "ssssssssisissssi",
                $updateValues['contact_email'], $updateValues['payment_method'],
                $updateValues['shipping_full_name'], $updateValues['shipping_street'], $updateValues['shipping_city'], $updateValues['shipping_province'], $updateValues['shipping_postal_code'], $updateValues['shipping_phone'],
                $billingSameInt, $updateValues['billing_full_name'], $updateValues['billing_street'], $updateValues['billing_city'], $updateValues['billing_province'], $updateValues['billing_postal_code'], $updateValues['billing_phone'],
                $orderId
            );
            mysqli_stmt_execute($upd);

            // Record voucher usage
            if ($voucherCode && $voucherRow) {
                $insUsage = mysqli_prepare($conn, "INSERT INTO voucher_usages (voucher_id, customer_id, order_id) VALUES (?, ?, ?)");
                $voucherId = (int)$voucherRow['voucher_id'];
                mysqli_stmt_bind_param($insUsage, "iii", $voucherId, $customerId, $orderId);
                mysqli_stmt_execute($insUsage);
                // Increment times_used
                $updTimes = mysqli_prepare($conn, "UPDATE vouchers SET times_used = times_used + 1 WHERE voucher_id = ?");
                mysqli_stmt_bind_param($updTimes, "i", $voucherId);
                mysqli_stmt_execute($updTimes);
            }

            mysqli_commit($conn);
            
            // Send email notifications after successful order placement
            try {
                // Include email utilities
                include_once('email_utils.php');
                
                // Get customer details
                $customerDetails = getCustomerDetailsForEmail($conn, $customerId);
                $customerEmail = $updateValues['contact_email'] ?: ($customerDetails['email'] ?? '');
                $customerName = $updateValues['shipping_full_name'] ?: ($customerDetails['name'] ?? 'Customer');
                
                // Get order items for email
                $orderItems = getOrderItemsForEmail($conn, $orderId);
                
                // Prepare order details for email
                $emailOrderDetails = [
                    'order_id' => $orderId,
                    'total' => $finalAmount,
                    'discount' => $orderDiscount,
                    'voucher_code' => $voucherCode,
                    'payment_method' => $updateValues['payment_method'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'shipping_full_name' => $updateValues['shipping_full_name'],
                    'shipping_street' => $updateValues['shipping_street'],
                    'shipping_city' => $updateValues['shipping_city'],
                    'shipping_province' => $updateValues['shipping_province'],
                    'shipping_postal_code' => $updateValues['shipping_postal_code'],
                    'shipping_phone' => $updateValues['shipping_phone'],
                    'contact_email' => $customerEmail,
                    'customer_name' => $customerName,
                    'items' => $orderItems
                ];
                
                // Send customer confirmation email
                if ($customerEmail) {
                    $emailSent = sendOrderConfirmationEmail($customerEmail, $customerName, $emailOrderDetails);
                    error_log("Customer email notification for order #$orderId: " . ($emailSent ? 'SENT' : 'FAILED'));
                }
                
                // Send admin notification email
                $adminEmailSent = sendAdminOrderNotification($emailOrderDetails);
                error_log("Admin email notification for order #$orderId: " . ($adminEmailSent ? 'SENT' : 'FAILED'));
                
            } catch (Exception $emailError) {
                // Don't fail the order if email fails, just log it
                error_log("Email notification error for order #$orderId: " . $emailError->getMessage());
            }
            
            clearCart();
            unset($_SESSION['voucher_code']);
            return ['success' => true, 'order_id' => $orderId, 'total' => $finalAmount, 'discount' => $orderDiscount, 'voucher' => $voucherCode, 'label' => $voucherLabel];
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ---- Voucher helpers ----
    function ensureVoucherTables($conn) {
        $voucherSql = "CREATE TABLE IF NOT EXISTS vouchers (\n            voucher_id INT AUTO_INCREMENT PRIMARY KEY,\n            code VARCHAR(64) NOT NULL UNIQUE,\n            discount_type ENUM('PERCENT','FIXED') NOT NULL DEFAULT 'FIXED',\n            discount_value DECIMAL(10,2) NOT NULL,\n            max_discount DECIMAL(10,2) DEFAULT NULL,\n            min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n            starts_at DATETIME DEFAULT NULL,\n            expires_at DATETIME DEFAULT NULL,\n            usage_limit INT DEFAULT NULL,\n            per_customer_limit INT DEFAULT NULL,\n            times_used INT NOT NULL DEFAULT 0,\n            active TINYINT(1) NOT NULL DEFAULT 1,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $usageSql = "CREATE TABLE IF NOT EXISTS voucher_usages (\n            usage_id INT AUTO_INCREMENT PRIMARY KEY,\n            voucher_id INT NOT NULL,\n            customer_id INT NOT NULL,\n            order_id INT DEFAULT NULL,\n            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            CONSTRAINT fk_voucher_usages_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(voucher_id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        mysqli_query($conn, $voucherSql);
        mysqli_query($conn, $usageSql);
        // Backfill created_at column if table existed without it
        try { mysqli_query($conn, "ALTER TABLE vouchers ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
    }

    // Ensure tables exist at runtime (creates if missing)
    try { ensureOrderTables($conn); } catch (Throwable $e) {}
    try { ensureVoucherTables($conn); } catch (Throwable $e) {}

    function evaluateVoucher($conn, $code, $customerId, $subtotal) {
        ensureVoucherTables($conn);
        $code = trim($code);
        if ($code === '') {
            return ['valid' => false, 'message' => 'Voucher code is empty'];
        }
        $stmt = mysqli_prepare($conn, "SELECT * FROM vouchers WHERE code = ? AND active = 1");
        mysqli_stmt_bind_param($stmt, "s", $code);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $voucher = $res ? mysqli_fetch_assoc($res) : null;
        if (!$voucher) return ['valid' => false, 'message' => 'Voucher not found or inactive'];

        // Check dates
        $now = new DateTime('now');
        if (!empty($voucher['starts_at']) && $now < new DateTime($voucher['starts_at'])) {
            return ['valid' => false, 'message' => 'Voucher not started yet'];
        }
        if (!empty($voucher['expires_at']) && $now > new DateTime($voucher['expires_at'])) {
            return ['valid' => false, 'message' => 'Voucher expired'];
        }

        // Usage limits
        if (!is_null($voucher['usage_limit']) && $voucher['usage_limit'] !== '' && (int)$voucher['times_used'] >= (int)$voucher['usage_limit']) {
            return ['valid' => false, 'message' => 'Voucher usage limit reached'];
        }
        if ($customerId && !is_null($voucher['per_customer_limit']) && $voucher['per_customer_limit'] !== '') {
            $q = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM voucher_usages WHERE voucher_id = ? AND customer_id = ?");
            $vid = (int)$voucher['voucher_id'];
            mysqli_stmt_bind_param($q, "ii", $vid, $customerId);
            mysqli_stmt_execute($q);
            $r = mysqli_stmt_get_result($q);
            $row = $r ? mysqli_fetch_assoc($r) : ['c' => 0];
            if ((int)$row['c'] >= (int)$voucher['per_customer_limit']) {
                return ['valid' => false, 'message' => 'You have already used this voucher'];
            }
        }

        // Min order amount
        if ($subtotal < (float)$voucher['min_order_amount']) {
            return ['valid' => false, 'message' => 'Order does not meet minimum amount'];
        }

        // Compute discount
        $discount = 0.0;
        $label = '';
        if ($voucher['discount_type'] === 'PERCENT') {
            $discount = $subtotal * ((float)$voucher['discount_value'] / 100.0);
            $label = (float)$voucher['discount_value'] . '% off';
            if (!is_null($voucher['max_discount'])) {
                $discount = min($discount, (float)$voucher['max_discount']);
            }
        } else { // FIXED
            $discount = (float)$voucher['discount_value'];
            $label = '₱' . number_format($discount, 2) . ' off';
        }
        $discount = max(0.0, min($discount, $subtotal));

        return [
            'valid' => true,
            'voucher' => $voucher,
            'discount' => $discount,
            'label' => $label
        ];
    }

    // Load categories for AJAX
    $categories = getCategories($conn);

    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        switch ($_POST['action']) {
            case 'get_categories':
                $response = array_map(function($category) use ($conn) {
                    $category['product_count'] = countProductsInCategory($conn, $category['category_id']);
                    return $category;
                }, $categories);
                echo json_encode($response);
                exit;
            case 'get_products':
                $products = getProductsByCategory($conn, (int)$_POST['category_id']);
                echo json_encode($products);
                exit;
            case 'get_product':
                $product = getProductById($conn, (int)$_POST['product_id']);
                echo json_encode($product);
                exit;
            // ---- Cart AJAX endpoints ----
            case 'cart_add':
                $pid = (int)($_POST['product_id'] ?? 0);
                $qty = (int)($_POST['quantity'] ?? 1);
                if ($pid <= 0 || $qty <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid product or quantity']);
                    exit;
                }
                addToCart($pid, $qty);
                $summary = getCartSummary($conn);
                echo json_encode(['success' => true, 'summary' => $summary]);
                exit;
            case 'cart_get':
                echo json_encode(['success' => true, 'summary' => getCartSummary($conn)]);
                exit;
            case 'cart_update':
                $pid = (int)($_POST['product_id'] ?? 0);
                $qty = (int)($_POST['quantity'] ?? 1);
                updateCartQuantity($pid, $qty);
                echo json_encode(['success' => true, 'summary' => getCartSummary($conn)]);
                exit;
            case 'cart_remove':
                $pid = (int)($_POST['product_id'] ?? 0);
                removeCartItem($pid);
                echo json_encode(['success' => true, 'summary' => getCartSummary($conn)]);
                exit;
            case 'cart_clear':
                clearCart();
                echo json_encode(['success' => true, 'summary' => getCartSummary($conn)]);
                exit;
            case 'cart_merge':
                // Merge local cart items from client into session cart
                // IMPORTANT: Set quantities instead of adding to avoid duplication on refresh
                $itemsJson = $_POST['items'] ?? '[]';
                $items = json_decode($itemsJson, true);
                if (is_array($items)) {
                    foreach ($items as $it) {
                        $pid = isset($it['product_id']) ? (int)$it['product_id'] : 0;
                        $qty = isset($it['quantity']) ? (int)$it['quantity'] : 0;
                        if ($pid > 0 && $qty > 0) {
                            updateCartQuantity($pid, $qty);
                        }
                    }
                }
                echo json_encode(['success' => true, 'summary' => getCartSummary($conn)]);
                exit;
            case 'cart_apply_voucher':
                $code = trim($_POST['code'] ?? '');
                if ($code === '') {
                    echo json_encode(['success' => false, 'message' => 'Enter a voucher code']);
                    exit;
                }
                $summaryBefore = getCartSummary($conn);
                $subtotal = $summaryBefore['total'];
                if ($subtotal <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Cart is empty']);
                    exit;
                }
                $custId = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
                $evaluation = evaluateVoucher($conn, $code, $custId, $subtotal);
                if (!$evaluation['valid']) {
                    echo json_encode(['success' => false, 'message' => $evaluation['message']]);
                    exit;
                }
                $_SESSION['voucher_code'] = $code;
                echo json_encode(['success' => true, 'summary' => getCartSummary($conn)]);
                exit;
            case 'cart_remove_voucher':
                unset($_SESSION['voucher_code']);
                echo json_encode(['success' => true, 'summary' => getCartSummary($conn)]);
                exit;
            case 'cart_place_order':
                if (!isset($_SESSION['customer_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Only customers can place orders']);
                    exit;
                }
                // Collect optional checkout fields from POST
                $orderData = [
                    'contact_email' => $_POST['contact_email'] ?? '',
                    'payment_method' => $_POST['payment_method'] ?? '',
                    'shipping_full_name' => $_POST['shipping_full_name'] ?? '',
                    'shipping_street' => $_POST['shipping_street'] ?? '',
                    'shipping_city' => $_POST['shipping_city'] ?? '',
                    'shipping_province' => $_POST['shipping_province'] ?? '',
                    'shipping_postal_code' => $_POST['shipping_postal_code'] ?? '',
                    'shipping_phone' => $_POST['shipping_phone'] ?? '',
                    'billing_same_as_shipping' => $_POST['billing_same_as_shipping'] ?? '1',
                    'billing_full_name' => $_POST['billing_full_name'] ?? '',
                    'billing_street' => $_POST['billing_street'] ?? '',
                    'billing_city' => $_POST['billing_city'] ?? '',
                    'billing_province' => $_POST['billing_province'] ?? '',
                    'billing_postal_code' => $_POST['billing_postal_code'] ?? '',
                    'billing_phone' => $_POST['billing_phone'] ?? ''
                ];
                $result = placeOrder($conn, (int)$_SESSION['customer_id'], $orderData);
                echo json_encode($result);
                exit;
            case 'get_last_checkout_details':
                if (!isset($_SESSION['customer_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Not a customer']);
                    exit;
                }
                // Fetch the most recent order for this customer
                $cid = (int)$_SESSION['customer_id'];
                $stmt = mysqli_prepare($conn, "SELECT contact_email, payment_method, shipping_full_name, shipping_street, shipping_city, shipping_province, shipping_postal_code, shipping_phone, billing_same_as_shipping, billing_full_name, billing_street, billing_city, billing_province, billing_postal_code, billing_phone FROM orders WHERE customer_id = ? ORDER BY created_at DESC, order_id DESC LIMIT 1");
                mysqli_stmt_bind_param($stmt, 'i', $cid);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $row = $res ? mysqli_fetch_assoc($res) : null;
                echo json_encode(['success' => true, 'data' => $row]);
                exit;
        }
    }
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Browse Products - Customer Portal</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <style>
            body {
                background: linear-gradient(135deg,rgb(18, 129, 156) 0%,rgb(30, 154, 192) 100%);
                min-height: 100vh;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .main-container {
                background: rgba(255, 255, 255, 0.95);
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
                backdrop-filter: blur(10px);
                margin: 30px auto;
                max-width: 1200px;
                min-height: 80vh;
            }
            .page-header {
                background: linear-gradient(135deg,rgb(101, 163, 214) 0%,rgb(120, 162, 224) 100%);
                color: white;
                padding: 30px;
                border-radius: 20px 20px 0 0;
                text-align: center;
            }
            .page-header h1 {
                margin: 0;
                font-weight: 300;
                font-size: 2.5rem;
            }
            .content-section {
                padding: 30px;
            }
            .category-card {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                margin-bottom: 20px;
                transition: all 0.3s ease;
                cursor: pointer;
                overflow: hidden;
            }
            .category-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
            }
            .category-card .card-body {
                padding: 30px;
                text-align: center;
            }
            .category-icon {
                font-size: 3rem;
                color: #667eea;
                margin-bottom: 15px;
            }
            .category-name {
                font-size: 1.5rem;
                font-weight: 600;
                color: #495057;
                margin: 0;
            }
            .product-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .product-card {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                transition: all 0.3s ease;
                cursor: pointer;
            }
            .product-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
            }
            .product-image {
                width: 100%;
                height: 200px;
                object-fit: cover;
            }
            .product-image-placeholder {
                width: 100%;
                height: 200px;
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                color: #6c757d;
                font-size: 0.9rem;
                flex-direction: column;
            }
            .product-image-placeholder i {
                font-size: 3rem;
                margin-bottom: 10px;
                opacity: 0.5;
            }
            .product-card .card-body {
                padding: 20px;
            }
            .product-name {
                font-size: 1.2rem;
                font-weight: 600;
                color: #495057;
                margin-bottom: 10px;
            }
            .product-description {
                color: #6c757d;
                font-size: 0.9rem;
                margin-bottom: 15px;
                height: 60px;
                overflow: hidden;
                display: -webkit-box;
                -webkit-line-clamp: 3;
                -webkit-box-orient: vertical;
            }
            .back-btn {
                background: linear-gradient(135deg,rgb(84, 141, 187) 0%,rgb(78, 131, 201) 100%);
                color: white;
                border: none;
                padding: 12px 25px;
                border-radius: 25px;
                font-weight: 500;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            }
            .back-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
                color: white;
            }
            .section-title {
                color: #495057;
                font-weight: 600;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 3px solid #667eea;
                display: inline-block;
            }
            .product-detail {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                margin-top: 20px;
            }
            .product-detail-image {
                width: 100%;
                height: 400px;
                object-fit: cover;
            }
            .product-detail-info {
                padding: 30px;
            }
            .product-detail-name {
                font-size: 2rem;
                font-weight: 700;
                color: #495057;
                margin-bottom: 15px;
            }
            .product-detail-description {
                color: #6c757d;
                font-size: 1.1rem;
                line-height: 1.6;
                margin-bottom: 20px;
            }
            .category-breadcrumb {
                background: #f8f9fa;
                padding: 15px 30px;
                border-bottom: 1px solid #e9ecef;
            }
            .breadcrumb {
                margin: 0;
                background: transparent;
            }
            .breadcrumb-item a {
                color: #667eea;
                text-decoration: none;
            }
            .breadcrumb-item a:hover {
                text-decoration: underline;
            }
            .empty-state {
                text-align: center;
                padding: 60px 30px;
                color: #6c757d;
            }
            .empty-state i {
                font-size: 4rem;
                margin-bottom: 20px;
                color: #dee2e6;
            }
            .loading-spinner {
                text-align: center;
                padding: 40px;
            }
            .spinner-border {
                color: #667eea;
            }
            .database-info {
                padding: 15px;
                background: #d1ecf1;
                border: 1px solid #bee5eb;
                border-radius: 10px;
                margin-bottom: 20px;
            }
            .database-info h6 {
                color: #0c5460;
                margin-bottom: 10px;
            }
            .database-info p {
                color: #0c5460;
                margin: 0;
                font-size: 0.9rem;
            }
            
            /* Product Gallery Styles */
            .product-gallery {
                display: flex;
                gap: 15px;
            }

            .thumbnail-container {
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 80px;
            }

            .thumbnail-image {
                width: 70px;
                height: 70px;
                object-fit: cover;
                border-radius: 8px;
                cursor: pointer;
                border: 2px solid transparent;
                transition: all 0.3s ease;
            }

            .thumbnail-image:hover,
            .thumbnail-image.active {
                border-color: #667eea;
                transform: scale(1.05);
            }

            .main-image-container {
                flex: 1;
                position: relative;
            }

            .main-product-image {
                width: 100%;
                height: 400px;
                object-fit: cover;
                border-radius: 15px;
            }

            .image-placeholder {
                width: 100%;
                height: 400px;
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                color: #6c757d;
                font-size: 1.2rem;
                flex-direction: column;
                border-radius: 15px;
            }

            .image-placeholder i {
                font-size: 4rem;
                margin-bottom: 15px;
                opacity: 0.5;
            }

            @media (max-width: 768px) {
                .product-gallery {
                    flex-direction: column-reverse;
                }
                
                .thumbnail-container {
                    flex-direction: row;
                    max-width: 100%;
                    overflow-x: auto;
                    padding-bottom: 10px;
                }
                
                .thumbnail-image {
                    min-width: 70px;
                }
            }
            
            /* Payment Method Styles */
            #payment_method {
                font-size: 1rem;
            }
            
            #payment_method option {
                padding: 10px;
            }
            
            .payment-form-card {
                border: 1px solid #e0e0e0;
                border-radius: 12px;
                transition: all 0.3s ease;
            }
            
            .payment-form-card:hover {
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            
            #paymentInstructions .alert {
                border-radius: 10px;
                border-left: 4px solid #17a2b8;
            }
            
            #creditCardForm input,
            #eWalletForm input {
                border-radius: 8px;
                border: 1px solid #ddd;
                transition: border-color 0.3s ease;
            }
            
            #creditCardForm input:focus,
            #eWalletForm input:focus {
                border-color: #667eea;
                box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            }
            
            /* Format card number input */
            #card_number {
                font-family: 'Courier New', monospace;
                letter-spacing: 1px;
            }
            
            /* Payment method icons */
            .payment-icon {
                font-size: 1.2rem;
                margin-right: 8px;
            }
        </style>
    </head>
    <body>
        <div class="main-container">
            <!-- 🔐 User Bar (Top Right) -->
                <div class="d-flex justify-content-end p-3">
                    <div class="d-flex align-items-center gap-3 bg-white shadow-sm px-4 py-2 rounded-pill">
                        <i class="fas fa-user-circle fa-lg text-primary"></i>
                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars($user_name); ?></span>
                        <?php if ($isCustomer): ?>
                        <a href="order_history.php" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-receipt"></i>
                        </a>
                        <button type="button" class="btn btn-outline-primary btn-sm position-relative" id="openCartBtn" data-bs-toggle="modal" data-bs-target="#cartModal">
                            <i class="fas fa-shopping-cart"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="cartCountBadge">0</span>
                        </button>
                        <?php endif; ?>
                        <form action="logout.php" method="POST" class="mb-0">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-sign-out-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
            
            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-store"></i> Product Catalog</h1>
                <p class="mb-0">Browse our products by category</p>
            </div>
            <!-- Main Content -->
            <div id="mainContent" class="content-section">
                <!-- Categories View -->
                <div id="categoriesView">
                    <h3 class="section-title"><i class="fas fa-th-large"></i> Shop by Category</h3>
                    <div class="row" id="categoriesContainer">
                        <div class="loading-spinner">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p>Loading categories...</p>
                        </div>
                    </div>
                </div>

                <!-- Products View -->
                <div id="productsView" style="display: none;">
                    <div class="category-breadcrumb">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="#" onclick="showCategories()">Categories</a></li>
                                <li class="breadcrumb-item active" aria-current="page" id="currentCategory">Current Category</li>
                            </ol>
                        </nav>
                    </div>
                    
                    <a href="#" class="back-btn" onclick="showCategories()">
                        <i class="fas fa-arrow-left"></i> Back to Categories
                    </a>
                    
                    <div class="product-grid" id="productsContainer">
                        <!-- Products will be loaded here -->
                    </div>
                </div>

                <!-- Product Detail View -->
                <div id="productDetailView" style="display: none;">
                    <div class="category-breadcrumb">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="#" onclick="showCategories()">Categories</a></li>
                                <li class="breadcrumb-item"><a href="#" onclick="showCategoryProducts(currentCategoryId)" id="categoryBreadcrumb">Category</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Product Details</li>
                            </ol>
                        </nav>
                    </div>
                    
                    <a href="#" class="back-btn" onclick="showCategoryProducts(currentCategoryId)" id="backToCategory">
                        <i class="fas fa-arrow-left"></i> Back to Category
                    </a>
                    
                    <div id="productDetailContainer">
                        <!-- Product detail will be loaded here -->
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <!-- PayPal SDK (Sandbox) -->
        <script src="https://www.paypal.com/sdk/js?client-id=Aa0DH5qYxnWuGgOKcWOkUx9YfTsfgtwEvlVE3gCb2u1XQun4h3zTfEkFnEVo8Hi2eKilvknGlBhNpkJN&currency=PHP"></script>
        <script>
            const isCustomer = <?php echo $isCustomer ? 'true' : 'false'; ?>;
            let currentView = 'categories';
            let currentCategoryId = null;
            let currentCategoryName = '';
            let checkoutSummary = { subtotal: 0, discount: 0, total: 0 };

            function getCategoryIcon(categoryName) {
                const icons = {
                    'Clothing': 'fas fa-tshirt',
                    'Running': 'fas fa-running',
                    'Shorts': 'fas fa-user-ninja'
                };
                return icons[categoryName] || 'fas fa-box';
            }

            function showCategories() {
                currentView = 'categories';
                document.getElementById('categoriesView').style.display = 'block';
                document.getElementById('productsView').style.display = 'none';
                document.getElementById('productDetailView').style.display = 'none';
                loadCategories();
            }

            function showCategoryProducts(categoryId, categoryName) {
                currentView = 'products';
                currentCategoryId = categoryId;
                currentCategoryName = categoryName;
                
                document.getElementById('categoriesView').style.display = 'none';
                document.getElementById('productsView').style.display = 'block';
                document.getElementById('productDetailView').style.display = 'none';
                
                document.getElementById('currentCategory').textContent = categoryName;
                document.getElementById('categoryBreadcrumb').textContent = categoryName;
                
                loadProducts(categoryId);
            }

            function showProductDetail(productId) {
                currentView = 'productDetail';
                
                document.getElementById('categoriesView').style.display = 'none';
                document.getElementById('productsView').style.display = 'none';
                document.getElementById('productDetailView').style.display = 'block';
                
                loadProductDetail(productId);
            }

            function loadCategories() {
                const container = document.getElementById('categoriesContainer');
                container.innerHTML = `
                    <div class="loading-spinner">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p>Loading categories...</p>
                    </div>
                `;
                
                // AJAX request to get categories
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_categories'
                })
                .then(response => response.json())
                .then(categories => {
                    container.innerHTML = '';
                    
                    if (categories.length === 0) {
                        container.innerHTML = `
                            <div class="col-12">
                                <div class="empty-state">
                                    <i class="fas fa-folder-open"></i>
                                    <h4>No categories found</h4>
                                    <p>No categories are available in the database.</p>
                                </div>
                            </div>
                        `;
                        return;
                    }
                    
                    categories.forEach(category => {
                        const categoryCard = `
                            <div class="col-md-4">
                                <div class="category-card" onclick="showCategoryProducts(${category.category_id}, '${category.category_name}')">
                                    <div class="card-body">
                                        <div class="category-icon">
                                            <i class="${getCategoryIcon(category.category_name)}"></i>
                                        </div>
                                        <h5 class="category-name">${category.category_name}</h5>
                                        <p class="text-muted">${category.product_count} products available</p>
                                    </div>
                                </div>
                            </div>
                        `;
                        container.innerHTML += categoryCard;
                    });
                })
                .catch(error => {
                    console.error('Error loading categories:', error);
                    container.innerHTML = `
                        <div class="col-12">
                            <div class="empty-state">
                                <i class="fas fa-exclamation-triangle"></i>
                                <h4>Error loading categories</h4>
                                <p>There was an error loading the categories. Please try again.</p>
                            </div>
                        </div>
                    `;
                });
            }

            function loadProducts(categoryId) {
                const container = document.getElementById('productsContainer');
                container.innerHTML = `
                    <div class="loading-spinner">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p>Loading products...</p>
                    </div>
                `;
                
                // AJAX request to get products
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_products&category_id=${categoryId}`
                })
                .then(response => response.json())
                .then(products => {
                    container.innerHTML = '';
                    
                    if (products.length === 0) {
                        container.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <h4>No products found</h4>
                                <p>No products are available in this category yet.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    products.forEach(product => {
                        const imageHtml = product.photo ? 
                            `<img src="${product.photo}" alt="${product.product_name}" class="product-image" onerror="this.parentElement.innerHTML='<div class=\\'product-image-placeholder\\'><i class=\\'fas fa-image\\'></i><span>No Image</span></div>'">` :
                            `<div class="product-image-placeholder"><i class="fas fa-image"></i><span>No Image Available</span></div>`;
                        
                        const productCard = `
                            <div class="product-card" onclick="showProductDetail(${product.product_id})">
                                ${imageHtml}
                                <div class="card-body">
                                    <h5 class="product-name">${product.product_name}</h5>
                                    <p class="product-description">${product.description || 'No description available'}</p>
                                </div>
                            </div>
                        `;
                        container.innerHTML += productCard;
                    });
                })
                .catch(error => {
                    console.error('Error loading products:', error);
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h4>Error loading products</h4>
                            <p>There was an error loading the products. Please try again.</p>
                        </div>
                    `;
                });
            }

            // Product detail loading function with image gallery support
            function loadProductDetail(productId) {
                const container = document.getElementById('productDetailContainer');
                container.innerHTML = `
                    <div class="loading-spinner">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p>Loading product details...</p>
                    </div>
                `;
                
                // AJAX request to get product details
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_product&product_id=${productId}`
                })
                .then(response => response.json())
                .then(product => {
                    if (!product) {
                        container.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-exclamation-triangle"></i>
                                <h4>Product not found</h4>
                                <p>The requested product could not be found.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    // Prepare images
                    let mainImage = '';
                    let thumbnails = '';
                    
                    if (product.images && product.images.length > 0) {
                        // Use featured image as main image, or first image if no featured
                        const featuredImage = product.images.find(img => img.is_featured == 1) || product.images[0];
                        mainImage = `<img src="image/${featuredImage.image_filename}" alt="${product.product_name}" class="main-product-image" id="mainProductImage" onerror="this.parentElement.innerHTML='<div class=\\'image-placeholder\\'><i class=\\'fas fa-image\\'></i><span>Image not found</span></div>'">`;
                        
                        // Generate thumbnails if there are multiple images
                        if (product.images.length > 1) {
                            thumbnails = '<div class="thumbnail-container">';
                            product.images.forEach((image, index) => {
                                const activeClass = (image.is_featured == 1 || (index === 0 && !product.images.some(img => img.is_featured == 1))) ? 'active' : '';
                                thumbnails += `<img src="image/${image.image_filename}" alt="Thumbnail ${index + 1}" class="thumbnail-image ${activeClass}" onclick="changeMainImage('image/${image.image_filename}', this)" onerror="this.style.display='none'">`;
                            });
                            thumbnails += '</div>';
                        }
                    } else if (product.photo) {
                        // Fallback to old photo field
                        mainImage = `<img src="${product.photo}" alt="${product.product_name}" class="main-product-image" id="mainProductImage" onerror="this.parentElement.innerHTML='<div class=\\'image-placeholder\\'><i class=\\'fas fa-image\\'></i><span>Image not found</span></div>'">`;
                    } else {
                        // No image available
                        mainImage = `<div class="image-placeholder"><i class="fas fa-image"></i><span>No Image Available</span></div>`;
                    }

                    const cartControls = isCustomer ? `
                        <div class="d-flex align-items-center gap-2 mt-3">
                            <label for="addToCartQty" class="form-label mb-0 me-2">Qty</label>
                            <div class="input-group" style="width: 140px;">
                                <button class="btn btn-outline-secondary" type="button" id="qtyMinusBtn">-</button>
                                <input type="number" class="form-control text-center" id="addToCartQty" value="1" min="1" max="${product.quantity}">
                                <button class="btn btn-outline-secondary" type="button" id="qtyPlusBtn">+</button>
                            </div>
                            <button class="btn btn-primary" id="addToCartBtn"><i class="fas fa-cart-plus"></i> Add to Cart</button>
                        </div>
                    ` : '';
                    
                    const productDetail = `
                        <div class="product-detail">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="product-gallery">
                                        ${thumbnails}
                                        <div class="main-image-container">
                                            ${mainImage}
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="product-detail-info">
                                        <h1 class="product-detail-name">${product.product_name}</h1>
                                        <div class="product-detail-description">
                                            <h5>Description</h5>
                                            <p>${product.description || 'No description available'}</p>
                                        </div>
                                        <div class="mt-3">
                                            <p><strong>Price:</strong> ₱${parseFloat(product.price).toFixed(2)}</p>
                                            <p><strong>Available Stock:</strong> ${product.quantity}</p>
                                        </div>
                                        <div class="mt-4">
                                                                                        <span class="badge bg-primary rounded-pill">
                                                ${product.category_name}
                                            </span>
                                        </div>
                                        ${cartControls}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    container.innerHTML = productDetail;

                    if (isCustomer) {
                        const qtyInput = document.getElementById('addToCartQty');
                        const minusBtn = document.getElementById('qtyMinusBtn');
                        const plusBtn = document.getElementById('qtyPlusBtn');
                        const addBtn = document.getElementById('addToCartBtn');
                        if (minusBtn && plusBtn && qtyInput) {
                            minusBtn.addEventListener('click', () => {
                                const current = parseInt(qtyInput.value || '1', 10);
                                qtyInput.value = Math.max(1, current - 1);
                            });
                            plusBtn.addEventListener('click', () => {
                                const current = parseInt(qtyInput.value || '1', 10);
                                const max = parseInt(qtyInput.max || '9999', 10);
                                qtyInput.value = Math.min(max, current + 1);
                            });
                        }
                        if (addBtn) {
                            addBtn.addEventListener('click', () => {
                                const qty = parseInt(document.getElementById('addToCartQty').value || '1', 10);
                                addToCart(product.product_id, qty);
                            });
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading product details:', error);
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle"></i>
                            <h4>Error loading product</h4>
                            <p>There was an error loading this product's details. Please try again.</p>
                        </div>
                    `;
                });
            }

            // Change main image on thumbnail click
            function changeMainImage(src, thumbnail) {
                const mainImage = document.getElementById('mainProductImage');
                if (mainImage) {
                    mainImage.src = src;
                }

                // Update active thumbnail
                document.querySelectorAll('.thumbnail-image').forEach(img => {
                    img.classList.remove('active');
                });
                thumbnail.classList.add('active');
            }

            // On page load, show categories
            document.addEventListener('DOMContentLoaded', () => {
                showCategories();
                if (isCustomer) {
                    refreshCartBadge();
                    // Only merge local cart if server cart is empty; then clear local to avoid repeated merges
                    try {
                        fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=cart_get'
                        })
                        .then(r => r.json())
                        .then(serverData => {
                            const serverCount = serverData?.summary?.count || 0;
                            if (serverCount === 0) {
                                const local = JSON.parse(localStorage.getItem('cart_items') || '[]');
                                if (Array.isArray(local) && local.length > 0) {
                                    fetch('', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: 'action=cart_merge&items=' + encodeURIComponent(JSON.stringify(local))
                                    }).then(() => {
                                        try { localStorage.removeItem('cart_items'); } catch (_) {}
                                        refreshCartBadge();
                                    });
                                }
                            }
                        });
                    } catch (_) {}
                }
            });

            // ---- Cart Frontend Logic ----
            function refreshCartBadge() {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=cart_get'
                })
                .then(r => r.json())
                .then(data => {
                    const count = data?.summary?.count || 0;
                    const badge = document.getElementById('cartCountBadge');
                    if (badge) badge.textContent = count;
                })
                .catch(() => {});
            }

            function addToCart(productId, quantity = 1) {
                // Persist to localStorage for guest-like persistence across sessions
                try {
                    const local = JSON.parse(localStorage.getItem('cart_items') || '[]');
                    const idx = local.findIndex(x => x.product_id === productId);
                    if (idx >= 0) {
                        // Update to latest chosen quantity (non-accumulating) to reduce duplication on reload flows
                        local[idx].quantity = (local[idx].quantity || 0) + quantity;
                    } else {
                        local.push({ product_id: productId, quantity });
                    }
                    localStorage.setItem('cart_items', JSON.stringify(local));
                } catch (_) {}
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cart_add&product_id=${productId}&quantity=${quantity}`
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        alert(data.message || 'Failed to add to cart');
                        return;
                    }
                    refreshCartBadge();
                    // Open cart after adding
                    const cartModalEl = document.getElementById('cartModal');
                    if (cartModalEl) {
                        const modal = bootstrap.Modal.getOrCreateInstance(cartModalEl);
                        loadCart();
                        modal.show();
                    }
                })
                .catch(() => alert('Failed to add to cart'));
            }

            function loadCart() {
                const list = document.getElementById('cartItemsContainer');
                const subtotalEl = document.getElementById('cartSubtotal');
                const discountEl = document.getElementById('cartDiscount');
                const totalEl = document.getElementById('cartTotal');
                const voucherInfoText = document.getElementById('voucherInfoText');
                const cartModalTotal = document.getElementById('cartModalTotal');
                if (!list) return;
                list.innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div><p class="mt-2">Loading cart...</p></div>';
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=cart_get'
                })
                .then(r => r.json())
                .then(data => {
                    const items = data?.summary?.items || [];
                    const subtotal = data?.summary?.total || 0;
                    const discount = data?.summary?.discount || 0;
                    const finalTotal = data?.summary?.final_total || subtotal;
                    const voucher = data?.summary?.voucher || null;
                    if (items.length === 0) {
                        list.innerHTML = '<div class="empty-state"><i class="fas fa-box-open"></i><h4>Your cart is empty</h4><p>Browse products and add them to your cart.</p></div>';
                        if (subtotalEl) subtotalEl.textContent = '₱0.00';
                        if (discountEl) discountEl.textContent = '-₱0.00';
                        if (totalEl) totalEl.textContent = '₱0.00';
                        if (cartModalTotal) cartModalTotal.textContent = '₱0.00';
                        if (voucherInfoText) voucherInfoText.textContent = '';
                        return;
                    }
                    list.innerHTML = '';
                    items.forEach(it => {
                        const disabledNote = (it.requested_qty > it.max_quantity) ? `<div class=\"text-danger small\">Only ${it.max_quantity} left in stock</div>` : '';
                        const imageHtml = it.photo ? `<img src=\"${it.photo}\" class=\"rounded\" style=\"width:60px;height:60px;object-fit:cover;\">` : `<div class=\"product-image-placeholder\" style=\"width:60px;height:60px;border-radius:8px;\"> <i class=\"fas fa-image\"></i></div>`;
                        list.innerHTML += `
                            <div class=\"d-flex align-items-center justify-content-between py-2 border-bottom\">
                                <div class=\"d-flex align-items-center gap-3\">
                                    ${imageHtml}
                                    <div>
                                        <div class=\"fw-semibold\">${it.product_name}</div>
                                        <div class=\"text-muted small\">₱${it.price.toFixed(2)} each</div>
                                        ${disabledNote}
                                    </div>
                                </div>
                                <div class=\"d-flex align-items-center gap-2\">
                                    <div class=\"input-group input-group-sm\" style=\"width: 130px;\">\n                                         <button class=\"btn btn-outline-secondary\" type=\"button\" onclick=\"cartQtyChange(${it.product_id}, -1)\">-</button>\n                                         <input type=\"number\" class=\"form-control text-center\" value=\"${it.requested_qty}\" min=\"1\" max=\"${it.max_quantity}\" onchange=\"cartQtySet(${it.product_id}, this.value)\">\n                                         <button class=\"btn btn-outline-secondary\" type=\"button\" onclick=\"cartQtyChange(${it.product_id}, 1)\">+</button>\n                                     </div>
                                    <div class=\"ms-3 fw-semibold\" style=\"width: 100px; text-align: right;\">₱${(it.price * it.requested_qty).toFixed(2)}</div>
                                    <button class=\"btn btn-sm btn-outline-danger ms-2\" onclick=\"removeCartItem(${it.product_id})\"><i class=\"fas fa-trash\"></i></button>
                                </div>
                            </div>
                        `;
                    });
                    if (subtotalEl) subtotalEl.textContent = `₱${Number(subtotal).toFixed(2)}`;
                    if (discountEl) discountEl.textContent = `-₱${Number(discount).toFixed(2)}`;
                    if (totalEl) totalEl.textContent = `₱${Number(finalTotal).toFixed(2)}`;
                    if (cartModalTotal) cartModalTotal.textContent = `₱${Number(finalTotal).toFixed(2)}`;
                    if (voucherInfoText) {
                        voucherInfoText.textContent = voucher ? `${voucher.code} applied (${voucher.label}), discount ₱${Number(voucher.discount).toFixed(2)}` : '';
                    }
                })
                .catch(() => {
                    list.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h4>Failed to load cart</h4></div>';
                });
            }

            function cartQtyChange(productId, delta) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cart_get`
                })
                .then(r => r.json())
                .then(data => {
                    const item = (data?.summary?.items || []).find(x => x.product_id === productId);
                    const current = item ? parseInt(item.requested_qty, 10) : 1;
                    cartQtySet(productId, current + delta);
                });
            }

            function cartQtySet(productId, quantity) {
                const qty = Math.max(1, parseInt(quantity || '1', 10));
                // Update localStorage copy
                try {
                    const local = JSON.parse(localStorage.getItem('cart_items') || '[]');
                    const idx = local.findIndex(x => x.product_id === productId);
                    if (idx >= 0) local[idx].quantity = qty; else local.push({ product_id: productId, quantity: qty });
                    localStorage.setItem('cart_items', JSON.stringify(local));
                } catch (_) {}
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cart_update&product_id=${productId}&quantity=${qty}`
                })
                .then(() => {
                    loadCart();
                    refreshCartBadge();
                });
            }

            function removeCartItem(productId) {
                // Remove from localStorage copy
                try {
                    let local = JSON.parse(localStorage.getItem('cart_items') || '[]');
                    local = local.filter(x => x.product_id !== productId);
                    localStorage.setItem('cart_items', JSON.stringify(local));
                } catch (_) {}
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cart_remove&product_id=${productId}`
                })
                .then(() => {
                    loadCart();
                    refreshCartBadge();
                });
            }

            function placeOrder() {
                if (!confirm('Place order now?')) return;
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=cart_place_order'
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        alert(data.message || 'Failed to place order');
                        return;
                    }
                    // Clear localStorage cart after successful order
                    try { localStorage.removeItem('cart_items'); } catch (_) {}
                    refreshCartBadge();
                    const cartModalEl = document.getElementById('cartModal');
                    if (cartModalEl) {
                        const modal = bootstrap.Modal.getOrCreateInstance(cartModalEl);
                        modal.hide();
                    }
                    alert(`Order #${data.order_id} placed successfully! Total: ₱${Number(data.total).toFixed(2)}`);
                })
                .catch(() => alert('Failed to place order'));
            }

            // Toggle billing section
            document.addEventListener('change', (e) => {
                if (e.target && e.target.id === 'billing_same_as_shipping') {
                    const checked = e.target.checked;
                    const billing = document.getElementById('billingSection');
                    if (billing) billing.style.display = checked ? 'none' : 'block';
                    setBillingRequired(!checked);
                }
            });

            function setBillingRequired(required) {
                const ids = ['billing_full_name','billing_street','billing_city','billing_province','billing_postal_code','billing_phone'];
                ids.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) {
                        if (required) el.setAttribute('required', 'required');
                        else el.removeAttribute('required');
                    }
                });
            }

            // Payment method validation
            function validatePaymentMethod(paymentMethod) {
                switch (paymentMethod) {
                    case 'Credit Card':
                        const cardNumber = document.getElementById('card_number').value.replace(/\s/g, '');
                        const cardExpiry = document.getElementById('card_expiry').value;
                        const cardCvv = document.getElementById('card_cvv').value;
                        const cardHolder = document.getElementById('card_holder_name').value.trim();
                        
                        if (cardNumber.length < 13 || cardNumber.length > 19) {
                            alert('Please enter a valid card number');
                            return false;
                        }
                        if (!/^\d{2}\/\d{2}$/.test(cardExpiry)) {
                            alert('Please enter a valid expiry date (MM/YY)');
                            return false;
                        }
                        if (cardCvv.length < 3 || cardCvv.length > 4) {
                            alert('Please enter a valid CVV');
                            return false;
                        }
                        if (cardHolder.length < 2) {
                            alert('Please enter the cardholder name');
                            return false;
                        }
                        break;
                        
                    case 'GCash':
                    case 'Maya':
                        const mobile = document.getElementById('ewallet_mobile').value;
                        if (!/^09\d{9}$/.test(mobile)) {
                            alert('Please enter a valid Philippine mobile number (09XXXXXXXXX)');
                            return false;
                        }
                        break;
                        
                    case 'Cash on Delivery':
                    case 'Bank Transfer':
                    case 'Online Banking':
                    case 'PayPal':
                        // No additional validation needed
                        break;
                        
                    default:
                        alert('Please select a payment method');
                        return false;
                }
                return true;
            }

            function placeOrderWithDetails(forcedMethod = null, paypalOrderId = null) {
                const form = document.getElementById('checkoutForm');
                if (!form) return;
                
                // Validate payment method specific requirements
                const paymentMethod = forcedMethod || document.getElementById('payment_method').value;
                if (!validatePaymentMethod(paymentMethod)) {
                    return;
                }
                
                // Ensure required fields are enforced depending on billing toggle
                const billingSameCheckbox = document.getElementById('billing_same_as_shipping');
                const billingSame = billingSameCheckbox && billingSameCheckbox.checked;
                setBillingRequired(!billingSame);
                // Run HTML5 validity
                if (!form.checkValidity()) { form.reportValidity(); return; }
                const data = new URLSearchParams();
                data.append('action', 'cart_place_order');
                const ids = [
                    'contact_email','payment_method',
                    'shipping_full_name','shipping_street','shipping_city','shipping_province','shipping_postal_code','shipping_phone',
                    'billing_full_name','billing_street','billing_city','billing_province','billing_postal_code','billing_phone'
                ];
                ids.forEach(id => {
                    const el = document.getElementById(id);
                    if (el) data.append(id, el.value || '');
                });
                
                // Add payment method specific data
                if (paymentMethod === 'Credit Card') {
                    const cardFields = ['card_number', 'card_expiry', 'card_cvv', 'card_holder_name'];
                    cardFields.forEach(field => {
                        const el = document.getElementById(field);
                        if (el) data.append(field, el.value || '');
                    });
                } else if (paymentMethod === 'GCash' || paymentMethod === 'Maya') {
                    const el = document.getElementById('ewallet_mobile');
                    if (el) data.append('ewallet_mobile', el.value || '');
                }
                
                data.append('billing_same_as_shipping', billingSame ? '1' : '0');
                if (forcedMethod) data.set('payment_method', forcedMethod);
                if (paypalOrderId) data.append('paypal_order_id', paypalOrderId);

                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: data.toString()
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { alert(data.message || 'Failed to place order'); return; }
                    try { localStorage.removeItem('cart_items'); } catch (_) {}
                    refreshCartBadge();
                    const checkoutEl = document.getElementById('checkoutModal');
                    if (checkoutEl) {
                        const modal = bootstrap.Modal.getOrCreateInstance(checkoutEl);
                        modal.hide();
                    }
                    
                    // Show payment-specific success message
                    const orderPaymentMethod = forcedMethod || document.getElementById('payment_method').value;
                    let successMessage = `Order #${data.order_id} placed successfully! Total: ₱${Number(data.total).toFixed(2)}\n\n`;
                    
                    switch (orderPaymentMethod) {
                        case 'Cash on Delivery':
                            successMessage += 'Payment Method: Cash on Delivery\nPrepare exact amount for delivery. Additional COD fee applies.';
                            break;
                        case 'Credit Card':
                            successMessage += 'Payment Method: Credit Card\nYour card will be charged upon order confirmation.';
                            break;
                        case 'GCash':
                            successMessage += 'Payment Method: GCash\nYou will receive a payment request via SMS. Please complete payment within 15 minutes.';
                            break;
                        case 'Maya':
                            successMessage += 'Payment Method: Maya (PayMaya)\nYou will receive a payment request notification. Please complete payment within 15 minutes.';
                            break;
                        case 'Bank Transfer':
                            successMessage += 'Payment Method: Bank Transfer\nPlease transfer the exact amount to our bank account and send proof of payment.';
                            break;
                        case 'Online Banking':
                            successMessage += 'Payment Method: Online Banking\nComplete your payment through your bank\'s online portal.';
                            break;
                        case 'PayPal':
                            successMessage += 'Payment Method: PayPal\nPayment completed successfully through PayPal.';
                            break;
                        default:
                            successMessage += `Payment Method: ${orderPaymentMethod}`;
                    }
                    
                    alert(successMessage);
                })
                .catch(() => alert('Failed to place order'));
            }

            function loadCheckoutSummary() {
                const subtotalEl = document.getElementById('cartSubtotal');
                const discountEl = document.getElementById('cartDiscount');
                const totalEl = document.getElementById('cartTotal');
                const voucherInfoText = document.getElementById('voucherInfoText');
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=cart_get'
                })
                .then(r => r.json())
                .then(data => {
                    const subtotal = data?.summary?.total || 0;
                    const discount = data?.summary?.discount || 0;
                    const finalTotal = data?.summary?.final_total || subtotal;
                    const voucher = data?.summary?.voucher || null;
                    checkoutSummary = { subtotal, discount, total: finalTotal };
                    if (subtotalEl) subtotalEl.textContent = `₱${Number(subtotal).toFixed(2)}`;
                    if (discountEl) discountEl.textContent = `-₱${Number(discount).toFixed(2)}`;
                    if (totalEl) totalEl.textContent = `₱${Number(finalTotal).toFixed(2)}`;
                    if (voucherInfoText) voucherInfoText.textContent = voucher ? `${voucher.code} applied (${voucher.label}), discount ₱${Number(voucher.discount).toFixed(2)}` : '';
                    maybeRenderPayPal();
                });
            }

            function openCheckout() {
                const cartModalEl = document.getElementById('cartModal');
                if (cartModalEl) {
                    const cm = bootstrap.Modal.getOrCreateInstance(cartModalEl);
                    cm.hide();
                }
                const checkoutEl = document.getElementById('checkoutModal');
                if (checkoutEl) {
                    const modal = bootstrap.Modal.getOrCreateInstance(checkoutEl);
                    loadCheckoutSummary();
                    // Prefill last checkout details for this customer
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=get_last_checkout_details'
                    })
                    .then(r => r.json())
                    .then(data => {
                        const d = data?.data || null;
                        if (!d) return;
                        const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
                        setVal('contact_email', d.contact_email);
                        setVal('payment_method', d.payment_method);
                        setVal('shipping_full_name', d.shipping_full_name);
                        setVal('shipping_street', d.shipping_street);
                        setVal('shipping_city', d.shipping_city);
                        setVal('shipping_province', d.shipping_province);
                        setVal('shipping_postal_code', d.shipping_postal_code);
                        setVal('shipping_phone', d.shipping_phone);
                        const billingSame = document.getElementById('billing_same_as_shipping');
                        if (billingSame) {
                            const isSame = String(d.billing_same_as_shipping) === '1';
                            billingSame.checked = isSame;
                            const billing = document.getElementById('billingSection');
                            if (billing) billing.style.display = isSame ? 'none' : 'block';
                            setBillingRequired(!isSame);
                        }
                        setVal('billing_full_name', d.billing_full_name);
                        setVal('billing_street', d.billing_street);
                        setVal('billing_city', d.billing_city);
                        setVal('billing_province', d.billing_province);
                        setVal('billing_postal_code', d.billing_postal_code);
                        setVal('billing_phone', d.billing_phone);
                        // Ensure PayPal buttons reflect chosen method
                        maybeRenderPayPal();
                    })
                    .catch(() => {});
                    modal.show();
                    // Initialize required state on first open
                    const billingSame = document.getElementById('billing_same_as_shipping');
                    setBillingRequired(!(billingSame && billingSame.checked));
                }
            }

            function handlePaymentMethodChange() {
                const methodSelect = document.getElementById('payment_method');
                const paypalContainer = document.getElementById('paypalButtons');
                const creditCardForm = document.getElementById('creditCardForm');
                const eWalletForm = document.getElementById('eWalletForm');
                const bankTransferForm = document.getElementById('bankTransferForm');
                const paymentInstructions = document.getElementById('paymentInstructions');
                const paymentInstructionText = document.getElementById('paymentInstructionText');
                const eWalletTitle = document.getElementById('eWalletTitle');
                
                if (!methodSelect) return;
                
                // Hide all payment forms first
                if (paypalContainer) paypalContainer.style.display = 'none';
                if (creditCardForm) creditCardForm.style.display = 'none';
                if (eWalletForm) eWalletForm.style.display = 'none';
                if (bankTransferForm) bankTransferForm.style.display = 'none';
                if (paymentInstructions) paymentInstructions.style.display = 'none';
                
                const selectedMethod = methodSelect.value;
                
                switch (selectedMethod) {
                    case 'Cash on Delivery':
                        paymentInstructions.style.display = 'block';
                        paymentInstructionText.innerHTML = `
                            <strong>Cash on Delivery (COD)</strong><br>
                            • Pay cash when your order is delivered<br>
                            • Additional COD fee: ₱50<br>
                            • Please prepare exact amount if possible<br>
                            • Available for Metro Manila and nearby provinces only
                        `;
                        break;
                        
                    case 'PayPal':
                        if (paypalContainer && checkoutSummary.total > 0) {
                            paypalContainer.style.display = 'block';
                            paypalContainer.innerHTML = '';
                            paypal.Buttons({
                                createOrder: function(data, actions) {
                                    return actions.order.create({
                                        purchase_units: [{ amount: { value: Number(checkoutSummary.total).toFixed(2) } }]
                                    });
                                },
                                onApprove: function(data, actions) {
                                    return actions.order.capture().then(function(details) {
                                        placeOrderWithDetails('PayPal', details.id);
                                    });
                                },
                                onError: function(err) { alert('PayPal error: ' + err); }
                            }).render('#paypalButtons');
                        }
                        break;
                        
                    case 'Credit Card':
                        creditCardForm.style.display = 'block';
                        paymentInstructions.style.display = 'block';
                        paymentInstructionText.innerHTML = `
                            <strong>Credit/Debit Card</strong><br>
                            • We accept Visa, Mastercard, and local cards<br>
                            • Your payment is secured with SSL encryption<br>
                            • Please ensure your card is enabled for online transactions
                        `;
                        break;
                        
                    case 'GCash':
                        eWalletForm.style.display = 'block';
                        eWalletTitle.textContent = 'GCash Payment';
                        paymentInstructions.style.display = 'block';
                        paymentInstructionText.innerHTML = `
                            <strong>GCash Payment</strong><br>
                            • Make sure you have sufficient GCash balance<br>
                            • You'll receive a payment request via SMS<br>
                            • Complete payment within 15 minutes
                        `;
                        break;
                        
                    case 'Maya':
                        eWalletForm.style.display = 'block';
                        eWalletTitle.textContent = 'Maya (PayMaya) Payment';
                        paymentInstructions.style.display = 'block';
                        paymentInstructionText.innerHTML = `
                            <strong>Maya (PayMaya) Payment</strong><br>
                            • Ensure your Maya wallet has sufficient balance<br>
                            • You'll receive a payment request notification<br>
                            • Complete payment within 15 minutes
                        `;
                        break;
                        
                    case 'Bank Transfer':
                        bankTransferForm.style.display = 'block';
                        break;
                        
                    case 'Online Banking':
                        paymentInstructions.style.display = 'block';
                        paymentInstructionText.innerHTML = `
                            <strong>Online Banking</strong><br>
                            • Available for BPI, BDO, Metrobank, UnionBank, and more<br>
                            • You'll be redirected to your bank's website<br>
                            • Make sure your online banking is activated<br>
                            • Keep your transaction reference number
                        `;
                        break;
                }
            }
            
            // For backward compatibility
            function maybeRenderPayPal() {
                handlePaymentMethodChange();
            }

            function applyVoucher() {
                const input = document.getElementById('voucherCodeInput');
                const code = (input?.value || '').trim();
                if (!code) { alert('Enter a voucher code'); return; }
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cart_apply_voucher&code=${encodeURIComponent(code)}`
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { alert(data.message || 'Invalid voucher'); return; }
                    loadCheckoutSummary();
                })
                .catch(() => alert('Failed to apply voucher'));
            }

            function removeVoucher() {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=cart_remove_voucher'
                })
                .then(r => r.json())
                .then(() => loadCheckoutSummary())
                .catch(() => {});
            }
            
            // Card formatting functions
            function formatCardNumber(value) {
                return value.replace(/\s/g, '').replace(/(\d{4})/g, '$1 ').trim();
            }
            
            function formatExpiryDate(value) {
                return value.replace(/\D/g, '').replace(/(\d{2})(\d)/, '$1/$2');
            }
            
            // Add event listeners for card formatting
            document.addEventListener('DOMContentLoaded', function() {
                const cardNumberInput = document.getElementById('card_number');
                const cardExpiryInput = document.getElementById('card_expiry');
                const cardCvvInput = document.getElementById('card_cvv');
                const ewalletMobileInput = document.getElementById('ewallet_mobile');
                
                if (cardNumberInput) {
                    cardNumberInput.addEventListener('input', function(e) {
                        const cursorPos = e.target.selectionStart;
                        const oldValue = e.target.value;
                        const newValue = formatCardNumber(e.target.value.replace(/\s/g, ''));
                        e.target.value = newValue;
                        
                        // Maintain cursor position
                        if (newValue.length > oldValue.length) {
                            e.target.setSelectionRange(cursorPos + 1, cursorPos + 1);
                        }
                    });
                }
                
                if (cardExpiryInput) {
                    cardExpiryInput.addEventListener('input', function(e) {
                        e.target.value = formatExpiryDate(e.target.value);
                    });
                }
                
                if (cardCvvInput) {
                    cardCvvInput.addEventListener('input', function(e) {
                        e.target.value = e.target.value.replace(/\D/g, '');
                    });
                }
                
                if (ewalletMobileInput) {
                    ewalletMobileInput.addEventListener('input', function(e) {
                        // Format mobile number (Philippine format)
                        let value = e.target.value.replace(/\D/g, '');
                        if (value.startsWith('63')) {
                            value = '0' + value.substring(2);
                        }
                        if (value.length > 11) {
                            value = value.substring(0, 11);
                        }
                        e.target.value = value;
                    });
                }
            });
        </script>
        
        <?php if ($isCustomer): ?>
        <!-- Cart Modal: items only -->
        <div class="modal fade" id="cartModal" tabindex="-1" aria-labelledby="cartModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="cartModalLabel"><i class="fas fa-shopping-cart me-2"></i>Your Cart</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <h6 class="mb-3"><i class="fas fa-list me-2"></i>Items in Cart</h6>
                        <div id="cartItemsContainer"></div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <div class="fw-bold">Total: <span id="cartModalTotal">₱0.00</span></div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary" onclick="loadCart()"><i class="fas fa-sync"></i> Refresh</button>
                            <button type="button" class="btn btn-primary" onclick="openCheckout()"><i class="fas fa-arrow-right"></i> Proceed to Checkout</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
            const cartModalEl = document.getElementById('cartModal');
            if (cartModalEl) {
                cartModalEl.addEventListener('show.bs.modal', () => {
                    loadCart();
                });
            }
        </script>

        <!-- Checkout Modal: details and place order -->
        <div class="modal fade" id="checkoutModal" tabindex="-1" aria-labelledby="checkoutModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="checkoutModalLabel"><i class="fas fa-clipboard-check me-2"></i>Checkout Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="checkoutForm" onsubmit="event.preventDefault(); placeOrderWithDetails();">
                            <div class="mb-3">
                                <label class="form-label">Email for Order Confirmation</label>
                                <input type="email" class="form-control" id="contact_email" placeholder="name@example.com" required>
                                <div class="form-text">If checking out as guest, provide a valid email.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Payment Method</label>
                                <select class="form-select" id="payment_method" required onchange="handlePaymentMethodChange()">
                                    <option value="" disabled selected>Select payment method...</option>
                                    <option value="Cash on Delivery">💰 Cash on Delivery (COD)</option>
                                    <option value="PayPal">💳 PayPal</option>
                                    <option value="Credit Card">💳 Credit/Debit Card</option>
                                    <option value="GCash">📱 GCash</option>
                                    <option value="Maya">📱 Maya (PayMaya)</option>
                                    <option value="Bank Transfer">🏦 Bank Transfer</option>
                                    <option value="Online Banking">🌐 Online Banking</option>
                                </select>
                            </div>
                            
                            <!-- Payment Instructions Container -->
                            <div id="paymentInstructions" class="mb-3" style="display: none;">
                                <div class="alert alert-info">
                                    <div id="paymentInstructionText"></div>
                                </div>
                            </div>
                            
                            <!-- PayPal Buttons -->
                            <div id="paypalButtons" class="mb-3" style="display: none;"></div>
                            
                            <!-- Credit Card Form -->
                            <div id="creditCardForm" class="mb-3" style="display: none;">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">Credit/Debit Card Details</h6>
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Card Number</label>
                                                <input type="text" class="form-control" id="card_number" placeholder="1234 5678 9012 3456" maxlength="19">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">Expiry Date</label>
                                                <input type="text" class="form-control" id="card_expiry" placeholder="MM/YY" maxlength="5">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">CVV</label>
                                                <input type="text" class="form-control" id="card_cvv" placeholder="123" maxlength="4">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Cardholder Name</label>
                                                <input type="text" class="form-control" id="card_holder_name" placeholder="Name on card">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- E-Wallet Form -->
                            <div id="eWalletForm" class="mb-3" style="display: none;">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title" id="eWalletTitle">E-Wallet Details</h6>
                                        <div class="mb-3">
                                            <label class="form-label">Mobile Number</label>
                                            <input type="text" class="form-control" id="ewallet_mobile" placeholder="09XXXXXXXXX">
                                        </div>
                                        <div class="alert alert-warning">
                                            <small>You will receive a payment request on your mobile number after placing the order.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Bank Transfer Form -->
                            <div id="bankTransferForm" class="mb-3" style="display: none;">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">Bank Transfer Details</h6>
                                        <div class="alert alert-info">
                                            <strong>Our Bank Details:</strong><br>
                                            Bank: BPI (Bank of the Philippine Islands)<br>
                                            Account Name: Your Store Name<br>
                                            Account Number: 1234-5678-90<br>
                                            <br>
                                            <small>Please transfer the exact amount and send proof of payment to our email.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <h6 class="mt-3 mb-2">Shipping Address</h6>
                            <div class="mb-2"><input type="text" class="form-control" id="shipping_full_name" placeholder="Full name (recipient)" required></div>
                            <div class="mb-2"><input type="text" class="form-control" id="shipping_street" placeholder="Street address" required></div>
                            <div class="row g-2 mb-2">
                                <div class="col-6"><input type="text" class="form-control" id="shipping_city" placeholder="City" required></div>
                                <div class="col-6"><input type="text" class="form-control" id="shipping_province" placeholder="Province" required></div>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6"><input type="text" class="form-control" id="shipping_postal_code" placeholder="Zip/Postal code" required></div>
                                <div class="col-6"><input type="text" class="form-control" id="shipping_phone" placeholder="Phone number" required></div>
                            </div>
                            <div class="form-check my-2">
                                <input class="form-check-input" type="checkbox" value="1" id="billing_same_as_shipping" checked>
                                <label class="form-check-label" for="billing_same_as_shipping">Billing same as shipping</label>
                            </div>
                            <div id="billingSection" style="display:none;">
                                <h6 class="mt-3 mb-2">Billing Address</h6>
                                <div class="mb-2"><input type="text" class="form-control" id="billing_full_name" placeholder="Full name"></div>
                                <div class="mb-2"><input type="text" class="form-control" id="billing_street" placeholder="Street address"></div>
                                <div class="row g-2 mb-2">
                                    <div class="col-6"><input type="text" class="form-control" id="billing_city" placeholder="City"></div>
                                    <div class="col-6"><input type="text" class="form-control" id="billing_province" placeholder="Province"></div>
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-6"><input type="text" class="form-control" id="billing_postal_code" placeholder="Zip/Postal code"></div>
                                    <div class="col-6"><input type="text" class="form-control" id="billing_phone" placeholder="Phone number"></div>
                                </div>
                            </div>
                            <div class="mt-3 mb-3">
                                <label class="form-label">Promo Code</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" placeholder="Voucher code" id="voucherCodeInput">
                                    <button class="btn btn-outline-primary" type="button" onclick="applyVoucher()">Apply</button>
                                    <button class="btn btn-outline-secondary" type="button" onclick="removeVoucher()">Remove</button>
                                </div>
                                <div class="form-text" id="voucherInfoText"></div>
                            </div>
                            <div class="bg-light rounded p-3">
                                <div class="d-flex justify-content-between small text-muted"><span>Subtotal</span> <span id="cartSubtotal">₱0.00</span></div>
                                <div class="d-flex justify-content-between small text-success"><span>Discount</span> <span id="cartDiscount">-₱0.00</span></div>
                                <div class="d-flex justify-content-between fw-bold"><span>Total</span> <span id="cartTotal">₱0.00</span></div>
                            </div>
                            <div class="d-grid mt-3">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-credit-card me-1"></i> Place Order</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

       
    </body>
    </html>
