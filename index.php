<?php
session_start();
include('conn.php');

if (!isset($_SESSION['admin_id'])) {
    echo '
    <!DOCTYPE html>
    <html>
    <head>
        <title>Access Denied</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
        <script src="https://cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>
    </head>
    <body>
        <script>
            alertify.set("notifier","position", "top-center");
            alertify.error("Access Denied: You are not an admin.");
            setTimeout(function() {
                window.location.href = "view_product.php";
            }, 2500);
        </script>
    </body>
    </html>';
    exit;
}

// Ensure voucher tables exist for admin management
function ensureVoucherTables($conn) {
    $voucherSql = "CREATE TABLE IF NOT EXISTS vouchers (\n        voucher_id INT AUTO_INCREMENT PRIMARY KEY,\n        code VARCHAR(64) NOT NULL UNIQUE,\n        discount_type ENUM('PERCENT','FIXED') NOT NULL DEFAULT 'FIXED',\n        discount_value DECIMAL(10,2) NOT NULL,\n        max_discount DECIMAL(10,2) DEFAULT NULL,\n        min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n        starts_at DATETIME DEFAULT NULL,\n        expires_at DATETIME DEFAULT NULL,\n        usage_limit INT DEFAULT NULL,\n        per_customer_limit INT DEFAULT NULL,\n        times_used INT NOT NULL DEFAULT 0,\n        active TINYINT(1) NOT NULL DEFAULT 1,\n        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $usageSql = "CREATE TABLE IF NOT EXISTS voucher_usages (\n        usage_id INT AUTO_INCREMENT PRIMARY KEY,\n        voucher_id INT NOT NULL,\n        customer_id INT NOT NULL,\n        order_id INT DEFAULT NULL,\n        used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n        CONSTRAINT fk_voucher_usages_voucher_admin FOREIGN KEY (voucher_id) REFERENCES vouchers(voucher_id) ON DELETE CASCADE\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $voucherSql);
    mysqli_query($conn, $usageSql);
    // Backfill created_at column if table existed without it
    try { mysqli_query($conn, "ALTER TABLE vouchers ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
}
// Ensure notifications table exists
function ensureNotificationsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS notifications (\n        notification_id INT AUTO_INCREMENT PRIMARY KEY,\n        customer_id INT NOT NULL,\n        order_id INT NULL,\n        type VARCHAR(50) NOT NULL,\n        message TEXT NOT NULL,\n        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n        read_at TIMESTAMP NULL DEFAULT NULL,\n        INDEX idx_notifications_customer (customer_id, created_at)\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $sql);
}
ensureNotificationsTable($conn);
ensureVoucherTables($conn);

// Download an image from a remote URL into the image/ folder
function downloadRemoteImage($url, $destDir = 'image') {
    $url = trim((string)$url);
    if ($url === '') return null;
    if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }
    // Derive a safe filename
    $path = parse_url($url, PHP_URL_PATH);
    $base = basename($path ?: 'image');
    $ext = pathinfo($base, PATHINFO_EXTENSION);
    if ($ext === '') { $ext = 'jpg'; }
    $safeBase = preg_replace('/[^a-zA-Z0-9_\.-]+/', '_', pathinfo($base, PATHINFO_FILENAME));
    $filename = $safeBase . '_' . uniqid() . '.' . $ext;
    $destPath = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    // Try cURL first
    $data = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 CSV Importer'
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code >= 400 || !$data) { $data = null; }
        // Quick content-type check
        if ($data && $ct && stripos($ct, 'image/') === false) { $data = null; }
    }
    // Fallback
    if ($data === null) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: Mozilla/5.0\r\n"],
            'https' => ['timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: Mozilla/5.0\r\n", 'verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $data = @file_get_contents($url, false, $ctx);
    }

    if ($data === false || $data === null) return null;
    if (@file_put_contents($destPath, $data) === false) return null;
    return 'image/' . $filename;
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
// Add Category
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_category'])) {
    $category_name = $_POST['category_name'];
    $stmt = $conn->prepare("INSERT INTO category (category_name) VALUES (?)");
    $stmt->bind_param("s", $category_name);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Category added successfully!";
    } else {
        $_SESSION['error'] = "Failed to add category.";
    }
    $stmt->close();
    header("Location: index.php");
    exit();
}

// Update Category
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_category'])) {
    $category_id = $_POST['edit_category_id'];
    $category_name = $_POST['edit_category_name'];
    $stmt = $conn->prepare("UPDATE category SET category_name = ? WHERE category_id = ?");
    $stmt->bind_param("si", $category_name, $category_id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Category updated successfully!";
    } else {
        $_SESSION['error'] = "Failed to update category.";
    }
    $stmt->close();
    header("Location: index.php");
    exit();
}

// Delete Category
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_category_id'])) {
    $category_id = $_POST['delete_category_id'];
    
    // Check if category has products
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
    $check_stmt->bind_param("i", $category_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $check_stmt->close();
    
    if ($count > 0) {
        $_SESSION['error'] = "Cannot delete category. It contains products.";
    } else {
        $stmt = $conn->prepare("DELETE FROM category WHERE category_id = ?");
        $stmt->bind_param("i", $category_id);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Category deleted successfully!";
        } else {
            $_SESSION['error'] = "Failed to delete category.";
        }
        $stmt->close();
    }
    header("Location: index.php");
    exit();
}


// Add Product - Replace the existing add product code with this
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_product'])) {
    $product_name = $_POST['product_name'];
    $description = $_POST['description'];
    $category_id = $_POST['category_id'];
    $price = $_POST['price'] ?? 0.00;
    $quantity = $_POST['quantity'] ?? 0;

    // Handle featured image (main product photo)
    $photo_path = null;
    if (!empty($_FILES['featured_image']['name'])) {
        $featured_image = $_FILES['featured_image'];
        $featured_filename = basename($featured_image['name']);
        $featured_tmp = $featured_image['tmp_name'];
        $photo_path = "image/" . $featured_filename;

        // Check if upload was successful
        if (!move_uploaded_file($featured_tmp, $photo_path)) {
            $_SESSION['error'] = "Failed to upload featured image.";
            header("Location: index.php");
            exit();
        }
    }

    // Insert into products table
    $stmt = $conn->prepare("INSERT INTO products (product_name, description, category_id, photo, price, quantity) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssissd", $product_name, $description, $category_id, $photo_path, $price, $quantity);

    if ($stmt->execute()) {
        $product_id = $stmt->insert_id;
        $stmt->close();

        // Handle gallery images
        if (!empty($_FILES['gallery_images']['name'][0])) {
            $gallery_images = $_FILES['gallery_images'];
            $upload_success = true;

            for ($i = 0; $i < count($gallery_images['name']); $i++) {
                // Skip empty files
                if (empty($gallery_images['name'][$i])) continue;

                $filename = basename($gallery_images['name'][$i]);
                $tmp_name = $gallery_images['tmp_name'][$i];
                $path = "image/" . $filename; // Use 'image' folder as specified

                if (move_uploaded_file($tmp_name, $path)) {
                    // Insert into product_images table
                    $stmt_img = $conn->prepare("INSERT INTO product_images (product_id, image_filename, is_featured) VALUES (?, ?, 0)");
                    $stmt_img->bind_param("is", $product_id, $filename);
                    
                    if (!$stmt_img->execute()) {
                        $upload_success = false;
                        error_log("Failed to insert image record for: " . $filename);
                    }
                    $stmt_img->close();
                } else {
                    $upload_success = false;
                    error_log("Failed to upload gallery image: " . $filename);
                }
            }

            if (!$upload_success) {
                $_SESSION['error'] = "Product added but some gallery images failed to upload.";
            } else {
                $_SESSION['success'] = "Product and all images added successfully!";
            }
        } else {
            $_SESSION['success'] = "Product added successfully!";
        }
    } else {
        $_SESSION['error'] = "Failed to insert product: " . $conn->error;
    }

    header("Location: index.php");
    exit();
}




// Update Product
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_product'])) {
    $id = $_POST['edit_product_id'];
    $name = $_POST['edit_product_name'];
    $desc = $_POST['edit_description'];
    $cat = $_POST['edit_category_id'];

    $photo_path = '';
    if (!empty($_FILES['edit_photo']['name'])) {
        $img_name = $_FILES['edit_photo']['name'];
        $tmp = $_FILES['edit_photo']['tmp_name'];
        $photo_path = "image/" . basename($img_name);
        move_uploaded_file($tmp, $photo_path);
    }

    if ($photo_path) {
        $stmt = $conn->prepare("UPDATE products SET product_name = ?, description = ?, category_id = ?, photo = ? WHERE product_id = ?");
        $stmt->bind_param("ssisi", $name, $desc, $cat, $photo_path, $id);
    } else {
        $stmt = $conn->prepare("UPDATE products SET product_name = ?, description = ?, category_id = ? WHERE product_id = ?");
        $stmt->bind_param("ssii", $name, $desc, $cat, $id);
    }

    if ($stmt->execute()) {
        $_SESSION['success'] = "Product updated successfully!";
    } else {
        $_SESSION['error'] = "Failed to update product.";
    }
    $stmt->close();
    header("Location: index.php");
    exit();
}

// Delete Product
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_product_id'])) {
    $id = $_POST['delete_product_id'];
    $stmt = $conn->prepare("DELETE FROM products WHERE product_id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Product deleted successfully!";
    } else {
        $_SESSION['error'] = "Failed to delete product.";
    }
    $stmt->close();
    header("Location: index.php");
    exit();
}

// ---- Voucher CRUD ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_voucher'])) {
    $code = trim($_POST['code']);
    $discount_type = $_POST['discount_type'];
    $discount_value = (float)($_POST['discount_value'] ?? 0);
    $max_discount = ($_POST['max_discount'] === '' ? null : (float)$_POST['max_discount']);
    $min_order_amount = (float)($_POST['min_order_amount'] ?? 0);
    $starts_at = $_POST['starts_at'] !== '' ? $_POST['starts_at'] : null;
    $expires_at = $_POST['expires_at'] !== '' ? $_POST['expires_at'] : null;
    $usage_limit = ($_POST['usage_limit'] === '' ? null : (int)$_POST['usage_limit']);
    $per_customer_limit = ($_POST['per_customer_limit'] === '' ? null : (int)$_POST['per_customer_limit']);
    $active = isset($_POST['active']) ? 1 : 0;

    if ($code === '') { $_SESSION['error'] = 'Voucher code is required.'; header('Location: index.php'); exit; }
    $stmt = $conn->prepare("INSERT INTO vouchers (code, discount_type, discount_value, max_discount, min_order_amount, starts_at, expires_at, usage_limit, per_customer_limit, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssddssssii", $code, $discount_type, $discount_value, $max_discount, $min_order_amount, $starts_at, $expires_at, $usage_limit, $per_customer_limit, $active);
    if ($stmt->execute()) { $_SESSION['success'] = 'Voucher created.'; } else { $_SESSION['error'] = 'Failed to create voucher: ' . $conn->error; }
    $stmt->close();
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_voucher'])) {
    $voucher_id = (int)$_POST['voucher_id'];
    $code = trim($_POST['code']);
    $discount_type = $_POST['discount_type'];
    $discount_value = (float)($_POST['discount_value'] ?? 0);
    $max_discount = ($_POST['max_discount'] === '' ? null : (float)$_POST['max_discount']);
    $min_order_amount = (float)($_POST['min_order_amount'] ?? 0);
    $starts_at = $_POST['starts_at'] !== '' ? $_POST['starts_at'] : null;
    $expires_at = $_POST['expires_at'] !== '' ? $_POST['expires_at'] : null;
    $usage_limit = ($_POST['usage_limit'] === '' ? null : (int)$_POST['usage_limit']);
    $per_customer_limit = ($_POST['per_customer_limit'] === '' ? null : (int)$_POST['per_customer_limit']);
    $active = isset($_POST['active']) ? 1 : 0;

    if ($code === '') { $_SESSION['error'] = 'Voucher code is required.'; header('Location: index.php'); exit; }
    $stmt = $conn->prepare("UPDATE vouchers SET code = ?, discount_type = ?, discount_value = ?, max_discount = ?, min_order_amount = ?, starts_at = ?, expires_at = ?, usage_limit = ?, per_customer_limit = ?, active = ? WHERE voucher_id = ?");
    $stmt->bind_param("ssddssssiii", $code, $discount_type, $discount_value, $max_discount, $min_order_amount, $starts_at, $expires_at, $usage_limit, $per_customer_limit, $active, $voucher_id);
    if ($stmt->execute()) { $_SESSION['success'] = 'Voucher updated.'; } else { $_SESSION['error'] = 'Failed to update voucher: ' . $conn->error; }
    $stmt->close();
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voucher_id'])) {
    $voucher_id = (int)$_POST['delete_voucher_id'];
    $stmt = $conn->prepare("DELETE FROM vouchers WHERE voucher_id = ?");
    $stmt->bind_param("i", $voucher_id);
    if ($stmt->execute()) { $_SESSION['success'] = 'Voucher deleted.'; } else { $_SESSION['error'] = 'Failed to delete voucher.'; }
    $stmt->close();
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_voucher_id'])) {
    $voucher_id = (int)$_POST['toggle_voucher_id'];
    $stmt = $conn->prepare("UPDATE vouchers SET active = IF(active=1,0,1) WHERE voucher_id = ?");
    $stmt->bind_param("i", $voucher_id);
    if ($stmt->execute()) { $_SESSION['success'] = 'Voucher status updated.'; } else { $_SESSION['error'] = 'Failed to update voucher status.'; }
    $stmt->close();
    header('Location: index.php'); exit;
}

// Load vouchers for listing
$vouchers = $conn->query("SELECT * FROM vouchers ORDER BY created_at DESC, code ASC");
// Load orders for admin list
function loadOrdersWithCustomer($conn) {
    $sql = "SELECT o.*, CONCAT(c.first_name,' ',c.last_name) AS customer_name, c.email AS customer_email\n            FROM orders o\n            LEFT JOIN customers c ON o.customer_id = c.customer_id\n            ORDER BY o.created_at DESC, o.order_id DESC";
    return mysqli_query($conn, $sql);
}
$ordersList = @loadOrdersWithCustomer($conn);
// Filter Products
$filter_category = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';
$categories = $conn->query("SELECT * FROM category ORDER BY category_name");

if (!empty($filter_category)) {
    $stmt = $conn->prepare("SELECT p.*, c.category_name FROM products p LEFT JOIN category c ON p.category_id = c.category_id WHERE p.category_id = ? ORDER BY p.product_name");
    $stmt->bind_param("i", $filter_category);
    $stmt->execute();
    $products = $stmt->get_result();
    $stmt->close();
} else {
    $products = $conn->query("SELECT p.*, c.category_name FROM products p LEFT JOIN category c ON p.category_id = c.category_id ORDER BY p.product_name");
}

// CSV Import handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    if (!isset($_FILES['products_csv']) || $_FILES['products_csv']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'CSV upload failed.';
        header('Location: index.php'); exit;
    }
    $tmp = $_FILES['products_csv']['tmp_name'];
    $fh = fopen($tmp, 'r');
    if (!$fh) { $_SESSION['error'] = 'Cannot read CSV.'; header('Location: index.php'); exit; }
    // Read header
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); $_SESSION['error'] = 'Empty CSV.'; header('Location: index.php'); exit; }
    $map = [];
    foreach ($header as $i => $col) { $map[strtolower(trim($col))] = $i; }
    $required = ['product_name','description','category','price','quantity'];
    foreach ($required as $r) { if (!array_key_exists($r, $map)) { fclose($fh); $_SESSION['error'] = 'Missing column: ' . $r; header('Location: index.php'); exit; } }

    // Prepare helpers
    $selCat = $conn->prepare("SELECT category_id FROM category WHERE category_name = ? LIMIT 1");
    $insCat = $conn->prepare("INSERT INTO category (category_name) VALUES (?)");
    $selProd = $conn->prepare("SELECT product_id FROM products WHERE product_name = ? LIMIT 1");
    $insProd = $conn->prepare("INSERT INTO products (product_name, description, category_id, photo, price, quantity) VALUES (?, ?, ?, ?, ?, ?) ");
    $insImg = $conn->prepare("INSERT INTO product_images (product_id, image_filename, is_featured) VALUES (?, ?, ?) ");

    $created = 0; $updated = 0; $rows = 0;
    while (($row = fgetcsv($fh)) !== false) {
        $rows++;
        $name = trim($row[$map['product_name']] ?? '');
        $desc = trim($row[$map['description']] ?? '');
        $catName = trim($row[$map['category']] ?? '');
        $price = (float)($row[$map['price']] ?? 0);
        $qty = (int)($row[$map['quantity']] ?? 0);
        if ($name === '' || $desc === '' || $catName === '') continue;

        // Ensure category
        $selCat->bind_param('s', $catName);
        $selCat->execute();
        $res = $selCat->get_result();
        if ($r = $res->fetch_assoc()) { $catId = (int)$r['category_id']; }
        else { $insCat->bind_param('s', $catName); $insCat->execute(); $catId = $insCat->insert_id; }

        // Download main image if present
        $photo = null;
        if (isset($map['image_url'])) {
            $photo = downloadRemoteImage(trim($row[$map['image_url']] ?? ''));
        }

        // Upsert product by name
        $selProd->bind_param('s', $name);
        $selProd->execute();
        $exist = $selProd->get_result()->fetch_assoc();
        if ($exist) {
            $pid = (int)$exist['product_id'];
            // Update existing product basic fields; keep old photo if new download failed
            $stmt = $conn->prepare("UPDATE products SET description=?, category_id=?, price=?, quantity=?" . ($photo ? ", photo=?" : "") . " WHERE product_id=?");
            if ($photo) { $stmt->bind_param('sidisi', $desc, $catId, $price, $qty, $photo, $pid); } else { $stmt->bind_param('sidii', $desc, $catId, $price, $qty, $pid); }
            $stmt->execute();
            $stmt->close();
            $updated++;
        } else {
            $insProd->bind_param('ssissd', $name, $desc, $catId, $photo, $price, $qty);
            $insProd->execute();
            $pid = $insProd->insert_id;
            $created++;
        }

        // Gallery images
        for ($i = 1; $i <= 5; $i++) {
            $col = 'gallery_url_' . $i;
            if (!isset($map[$col])) continue;
            $url = trim($row[$map[$col]] ?? '');
            if ($url === '') continue;
            $saved = downloadRemoteImage($url);
            if ($saved) {
                $file = basename($saved);
                $isFeatured = 0;
                $insImg->bind_param('isi', $pid, $file, $isFeatured);
                @$insImg->execute();
            }
        }

        // Always ensure a featured image if we downloaded a main image
        if ($photo) {
            $file = basename($photo);
            $isFeatured = 1;
            // Check existing featured
            $check = $conn->prepare("SELECT image_id FROM product_images WHERE product_id = ? AND image_filename = ? LIMIT 1");
            $check->bind_param('is', $pid, $file);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$exists) {
                $insImg->bind_param('isi', $pid, $file, $isFeatured);
                @$insImg->execute();
            }
        }
    }
    fclose($fh);
    $_SESSION['success'] = "CSV processed. Rows: $rows, Created: $created, Updated: $updated";
    header('Location: index.php'); exit;
}
// Seed sample categories and products (with gallery images)
if ($_SERVER["REQUEST_METHOD"] === 'POST' && isset($_POST['seed_sample'])) {
    // Ensure product_images table
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_images (\n        image_id INT AUTO_INCREMENT PRIMARY KEY,\n        product_id INT NOT NULL,\n        image_filename VARCHAR(255) NOT NULL,\n        is_featured TINYINT(1) DEFAULT 0,\n        uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n        KEY product_id (product_id)\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Categories map
    $categoriesToEnsure = ['Clothing','Running','Shorts','Accessories','Footwear','Jackets','Tops','Bottoms'];
    $categoryNameToId = [];
    foreach ($categoriesToEnsure as $catName) {
        $stmt = $conn->prepare("SELECT category_id FROM category WHERE category_name = ? LIMIT 1");
        $stmt->bind_param('s', $catName);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $categoryNameToId[$catName] = (int)$row['category_id'];
        } else {
            $ins = $conn->prepare("INSERT INTO category (category_name) VALUES (?)");
            $ins->bind_param('s', $catName);
            $ins->execute();
            $categoryNameToId[$catName] = $ins->insert_id;
            $ins->close();
        }
        $stmt->close();
    }

    // Local images available for reuse
    $imgChu = 'image/chu.jpg';
    $imgDownload = 'image/download.jpg';
    $imgShopping = 'image/shopping.png';
    $img31 = 'image/31.png';
    $img3232 = 'image/3232.png';

    // Products dataset
    $samples = [
        ['Custom Corporate Jacket','Premium embroidered corporate jacket for teams and events.', 'Jackets', $imgChu, 1500.00, 80],
        ['Executive Windbreaker','Lightweight windbreaker with water-resistant shell.', 'Jackets', $imgChu, 1799.00, 60],
        ['Black Performance Shorts','Versatile black shorts for daily training.', 'Shorts', $imgShopping, 499.00, 120],
        ['Classic Running Shorts','Breathable mesh-lined shorts.', 'Shorts', $img31, 449.00, 150],
        ['Saucony Running Shoes','Lightweight performance running shoes.', 'Running', $imgDownload, 8999.00, 35],
        ['Road Runner Shoes','Cushioned shoes for road running.', 'Running', $imgDownload, 7999.00, 40],
        ['Everyday Tee','Soft cotton tee for everyday wear.', 'Tops', $img3232, 299.00, 200],
        ['Dri-Fit Training Shirt','Moisture-wicking training shirt.', 'Tops', $img31, 399.00, 180],
        ['Slim Fit Chino Pants','Smart casual pants for work or play.', 'Bottoms', $img3232, 1199.00, 90],
        ['Relaxed Joggers','Comfortable fleece joggers.', 'Bottoms', $img31, 899.00, 100],
        ['Running Cap','Breathable cap for runners.', 'Accessories', $img31, 299.00, 80],
        ['Hydration Belt','Carry water and keys while running.', 'Accessories', $img3232, 499.00, 50],
        ['Compression Socks','Supportive running socks.', 'Accessories', $img31, 199.00, 150],
        ['Trail Shoes','Durable trail running shoes.', 'Footwear', $imgDownload, 7499.00, 25],
        ['Court Sneakers','Classic sneakers for everyday wear.', 'Footwear', $imgDownload, 3999.00, 70]
    ];

    // Insert products if not exists by name
    $selProd = $conn->prepare("SELECT product_id FROM products WHERE product_name = ? LIMIT 1");
    $insProd = $conn->prepare("INSERT INTO products (product_name, description, category_id, photo, price, quantity) VALUES (?, ?, ?, ?, ?, ?) ");
    $insImg = $conn->prepare("INSERT INTO product_images (product_id, image_filename, is_featured) VALUES (?, ?, ?) ");

    foreach ($samples as $s) {
        [$name,$desc,$catName,$photo,$price,$qty] = $s;
        $catId = $categoryNameToId[$catName] ?? null;
        if (!$catId) continue;

        // Skip if product already exists
        $selProd->bind_param('s', $name);
        $selProd->execute();
        $existing = $selProd->get_result()->fetch_assoc();
        if ($existing) {
            $productId = (int)$existing['product_id'];
        } else {
            $insProd->bind_param('ssissd', $name, $desc, $catId, $photo, $price, $qty);
            $insProd->execute();
            $productId = $insProd->insert_id;
        }

        // Insert a small gallery using available images
        if ($productId > 0) {
            // Always ensure at least one featured image
            $gallery = [
                [$photo ? basename($photo) : basename($imgShopping), 1],
                [basename($img31), 0],
                [basename($img3232), 0]
            ];
            foreach ($gallery as $g) {
                [$file,$featured] = $g;
                // Check if this image already exists for this product
                $check = $conn->prepare("SELECT image_id FROM product_images WHERE product_id = ? AND image_filename = ? LIMIT 1");
                $check->bind_param('is', $productId, $file);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();
                if (!$exists) {
                    $insImg->bind_param('isi', $productId, $file, $featured);
                    @$insImg->execute();
                }
            }
        }
    }

    $_SESSION['success'] = 'Sample categories and products (with images) have been seeded.';
    header('Location: index.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product and Category Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- AlertifyJS CSS & JS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/css/alertify.min.css"/>
    <script src="https://cdn.jsdelivr.net/npm/alertifyjs@1.13.1/build/alertify.min.js"></script>

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
        .action-buttons {
            padding: 30px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }
        .btn-custom {
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }
        .filter-section {
            padding: 20px 30px;
            background: #f8f9fa;
        }
        .content-section {
            padding: 30px;
        }
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        .table thead th {
            background: linear-gradient(135deg,rgb(84, 141, 187) 0%,rgb(78, 131, 201) 100%);
            color: white;
            border: none;
            padding: 20px 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }
        .table tbody td {
            padding: 20px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        .product-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 25px;
        }
        .modal-title {
            font-weight: 600;
            font-size: 1.3rem;
        }
        .modal-body {
            padding: 30px;
        }
        .modal-footer {
            padding: 25px;
            border-top: 1px solid #e9ecef;
        }
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-action {
            border-radius: 20px;
            padding: 10px 15px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            width: 80px;
            text-align: center;
            display: inline-block;
            margin: 2px;
        }
        .btn-warning.btn-action {
            background-color: #20b2aa !important;
            border-color: #20b2aa !important;
            color: white !important;
        }
        .btn-warning.btn-action:hover {
            background-color: #1a9b96 !important;
            border-color: #1a9b96 !important;
        }
        .btn-danger.btn-action {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
            color: white !important;
        }
        .btn-danger.btn-action:hover {
            background-color: #c82333 !important;
            border-color: #c82333 !important;
        }
        .btn-action:hover {
            transform: translateY(-1px);
        }
        .alert {
            border-radius: 15px;
            padding: 20px;
            margin: 20px 30px;
            border: none;
            font-weight: 500;
        }
        .section-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
            display: inline-block;
        }
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #667eea;
            margin: 0;
        }
        .stats-label {
            color: #6c757d;
            font-size: 1.1rem;
            margin: 0;
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
                    <form action="logout.php" method="POST" class="mb-0">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </form>
                </div>
            </div>
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-boxes"></i> Product & Category Manager</h1>
            <p class="mb-0">Manage your products and categories efficiently</p>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= $_SESSION['success'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-primary btn-custom me-3" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="fas fa-plus"></i> Add Category
            </button>
            <button class="btn btn-success btn-custom" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="fas fa-plus"></i> Add Product
            </button>
            <a href="#" class="btn btn-outline-dark btn-custom ms-3" data-bs-toggle="modal" data-bs-target="#ordersModal">
                <i class="fas fa-receipt"></i> View Orders
            </a>
            <button class="btn btn-outline-primary btn-custom ms-3" data-bs-toggle="modal" data-bs-target="#csvImportModal">
                <i class="fas fa-file-csv"></i> Import CSV
            </button>
        </div>

        <!-- Statistics -->
        <div class="content-section">
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="stats-card">
                        <p class="stats-number"><?= $products->num_rows ?></p>
                        <p class="stats-label">Total Products</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card">
                        <p class="stats-number"><?= $categories->num_rows ?></p>
                        <p class="stats-label">Total Categories</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-section pt-0">
            <form method="POST" class="text-center">
                <button type="submit" name="seed_sample" class="btn btn-outline-secondary">
                    <i class="fas fa-database"></i> Seed Sample Data
                </button>
            </form>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="row align-items-center">
                <div class="col-md-8">
                    <select name="filter_category" class="form-select" onchange="this.form.submit()">
                        <option value="">🔍 Filter by Category - Show All</option>
                        <?php mysqli_data_seek($categories, 0); while ($cat = $categories->fetch_assoc()): ?>
                            <option value="<?= $cat['category_id'] ?>" <?= ($filter_category == $cat['category_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <?php if (!empty($filter_category)): ?>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Clear Filter
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Products Section -->
        <div class="content-section">
            <h3 class="section-title"><i class="fas fa-box"></i> Products</h3>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Photo</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        mysqli_data_seek($products, 0);
                        $i = 1; 
                        while ($row = $products->fetch_assoc()): 
                        ?>
                            <tr>
                                <td><strong><?= $i++ ?></strong></td>
                                <td>
                                    <?php if (!empty($row['photo'])): ?>
                                        <img src="<?= $row['photo'] ?>" alt="product" class="product-image">
                                    <?php else: ?>
                                        <div class="product-image d-flex align-items-center justify-content-center bg-light">
                                            <i class="fas fa-image text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars($row['product_name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['description']) ?></td>
                                <td>
                                    <span class="badge bg-primary rounded-pill">
                                        <?= htmlspecialchars($row['category_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-warning btn-action me-2" onclick="editProduct(<?= $row['product_id'] ?>, '<?= htmlspecialchars($row['product_name']) ?>', '<?= htmlspecialchars($row['description']) ?>', <?= $row['category_id'] ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                        <input type="hidden" name="delete_product_id" value="<?= $row['product_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-action">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Categories Section -->
            <h3 class="section-title mt-5"><i class="fas fa-tags"></i> Categories</h3>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Category Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php mysqli_data_seek($categories, 0); while ($cat = $categories->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= $cat['category_id'] ?></strong></td>
                                <td><strong><?= htmlspecialchars($cat['category_name']) ?></strong></td>
                                <td>
                                    <button class="btn btn-warning btn-action me-2" onclick="editCategory(<?= $cat['category_id'] ?>, '<?= htmlspecialchars($cat['category_name']) ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this category? This will only work if the category has no products.');">
                                        <input type="hidden" name="delete_category_id" value="<?= $cat['category_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-action">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Vouchers Section -->
            <h3 class="section-title mt-5"><i class="fas fa-ticket-alt"></i> Vouchers</h3>
            <div class="d-flex justify-content-end mb-3">
                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addVoucherModal"><i class="fas fa-plus"></i> Add Voucher</button>
            </div>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Value</th>
                            <th>Max</th>
                            <th>Min Order</th>
                            <th>Starts</th>
                            <th>Expires</th>
                            <th>Usage</th>
                            <th>Per Customer</th>
                            <th>Used</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($vouchers && $vouchers->num_rows > 0): while($v = $vouchers->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($v['code']) ?></strong></td>
                            <td><?= htmlspecialchars($v['discount_type']) ?></td>
                            <td><?= number_format($v['discount_value'], 2) ?></td>
                            <td><?= is_null($v['max_discount']) ? '-' : number_format($v['max_discount'], 2) ?></td>
                            <td><?= number_format($v['min_order_amount'], 2) ?></td>
                            <td><?= $v['starts_at'] ?: '-' ?></td>
                            <td><?= $v['expires_at'] ?: '-' ?></td>
                            <td><?= $v['usage_limit'] ?: '-' ?></td>
                            <td><?= $v['per_customer_limit'] ?: '-' ?></td>
                            <td><?= (int)$v['times_used'] ?></td>
                            <td>
                                <span class="badge <?= $v['active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $v['active'] ? 'Active' : 'Inactive' ?></span>
                            </td>
                            <td>
                                <button class="btn btn-warning btn-action" onclick='openEditVoucher(<?= json_encode($v, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'><i class="fas fa-edit"></i> Edit</button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this voucher?');">
                                    <input type="hidden" name="delete_voucher_id" value="<?= $v['voucher_id'] ?>">
                                    <button class="btn btn-danger btn-action"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="toggle_voucher_id" value="<?= $v['voucher_id'] ?>">
                                    <button class="btn btn-secondary btn-action"><i class="fas fa-toggle-on"></i> Toggle</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="12" class="text-center text-muted">No vouchers yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus"></i> Add New Category
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name</label>
                        <input type="text" name="category_name" class="form-control" placeholder="Enter category name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_category" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Orders Modal (Admin) -->
    <div class="modal fade" id="ordersModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Orders</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Total</th>
                    <th>Discount</th>
                    <th>Final</th>
                    <th>Status</th>
                    <th>Voucher</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($ordersList && mysqli_num_rows($ordersList) > 0): while($o = mysqli_fetch_assoc($ordersList)): ?>
                  <tr id="order-row-<?= (int)$o['order_id'] ?>">
                    <td><strong><?= (int)$o['order_id'] ?></strong></td>
                    <td><?= htmlspecialchars($o['created_at']) ?></td>
                    <td><?= htmlspecialchars($o['customer_name'] ?? 'Unknown') ?></td>
                    <td><?= htmlspecialchars($o['contact_email'] ?: ($o['customer_email'] ?? '')) ?></td>
                    <td>₱<?= number_format((float)$o['total_amount'], 2) ?></td>
                    <td class="text-success">-₱<?= number_format((float)$o['discount_amount'], 2) ?></td>
                    <td class="fw-semibold">₱<?= number_format((float)$o['final_amount'], 2) ?></td>
                    <td><span class="badge <?= $o['status']==='PLACED'?'bg-primary':($o['status']==='SHIPPED'?'bg-info':($o['status']==='DELIVERED'?'bg-success':($o['status']==='CANCELLED'?'bg-danger':'bg-secondary'))) ?>"><?= htmlspecialchars($o['status']) ?></span></td>
                    <td><?= htmlspecialchars($o['voucher_code'] ?: '-') ?></td>
                    <td>
                      <div class="btn-group">
                        <form method="post" class="d-inline admin-status-form" onsubmit="return updateOrderStatus(event, <?= (int)$o['order_id'] ?>, 'SHIPPED')">
                          <button class="btn btn-sm btn-outline-info">Ship</button>
                        </form>
                        <form method="post" class="d-inline admin-status-form" onsubmit="return updateOrderStatus(event, <?= (int)$o['order_id'] ?>, 'DELIVERED')">
                          <button class="btn btn-sm btn-outline-success">Deliver</button>
                        </form>
                        <form method="post" class="d-inline admin-status-form" onsubmit="return updateOrderStatus(event, <?= (int)$o['order_id'] ?>, 'CANCELLED')" >
                          <button class="btn btn-sm btn-outline-danger">Cancel</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                  <?php endwhile; else: ?>
                    <tr><td colspan="10" class="text-center text-muted">No orders yet</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- CSV Import Modal -->
    <div class="modal fade" id="csvImportModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-file-csv me-2"></i>Import Products from CSV</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
              <p class="text-muted">Upload a CSV file with a header row. Supported columns:</p>
              <ul>
                <li><code>product_name</code> (required)</li>
                <li><code>description</code> (required)</li>
                <li><code>category</code> (required)</li>
                <li><code>price</code> (required)</li>
                <li><code>quantity</code> (required)</li>
                <li><code>image_url</code> (optional; main image to download)</li>
                <li><code>gallery_url_1..gallery_url_5</code> (optional; up to 5 gallery images)</li>
              </ul>
              <div class="mb-3">
                <label class="form-label">CSV File</label>
                <input type="file" class="form-control" name="products_csv" accept=".csv" required>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary" name="import_csv">Import</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Product Modal - Replace your existing modal with this -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus"></i> Add New Product
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="product_name" class="form-control" placeholder="Enter product name" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Category *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">Select a category</option>
                                <?php mysqli_data_seek($categories, 0); while ($cat = $categories->fetch_assoc()): ?>
                                    <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Price</label>
                            <input type="number" name="price" class="form-control" placeholder="0.00" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-control" placeholder="0" min="0">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Description *</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Enter product description" required></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Featured Image (Main Photo)</label>
                    <input type="file" name="featured_image" class="form-control" accept="image/*">
                    <small class="form-text text-muted">This will be the main product photo displayed in the table</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Gallery Images (Additional Photos)</label>
                    <input type="file" name="gallery_images[]" class="form-control" accept="image/*" multiple>
                    <small class="form-text text-muted">You can select multiple images for the product gallery</small>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="add_product" class="btn btn-success">
                    <i class="fas fa-save"></i> Save Product
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Voucher Modal -->
<div class="modal fade" id="addVoucherModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-ticket-alt"></i> Add Voucher</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Code</label>
          <input type="text" name="code" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Type</label>
            <select name="discount_type" class="form-select" required>
              <option value="FIXED">FIXED</option>
              <option value="PERCENT">PERCENT</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Value</label>
            <input type="number" step="0.01" min="0" name="discount_value" class="form-control" required>
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="form-label">Max Discount (optional)</label>
            <input type="number" step="0.01" min="0" name="max_discount" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Min Order Amount</label>
            <input type="number" step="0.01" min="0" name="min_order_amount" class="form-control" value="0">
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="form-label">Starts At</label>
            <input type="datetime-local" name="starts_at" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Expires At</label>
            <input type="datetime-local" name="expires_at" class="form-control">
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="form-label">Usage Limit (optional)</label>
            <input type="number" min="0" name="usage_limit" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Per Customer Limit (optional)</label>
            <input type="number" min="0" name="per_customer_limit" class="form-control">
          </div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="active" id="add_active" checked>
          <label class="form-check-label" for="add_active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="add_voucher" class="btn btn-primary">Save Voucher</button>
      </div>
    </form>
  </div>
  </div>

<!-- Edit Voucher Modal -->
<div class="modal fade" id="editVoucherModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Voucher</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="voucher_id" id="ev_voucher_id">
        <div class="mb-2">
          <label class="form-label">Code</label>
          <input type="text" name="code" id="ev_code" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Type</label>
            <select name="discount_type" id="ev_discount_type" class="form-select" required>
              <option value="FIXED">FIXED</option>
              <option value="PERCENT">PERCENT</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Value</label>
            <input type="number" step="0.01" min="0" name="discount_value" id="ev_discount_value" class="form-control" required>
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="form-label">Max Discount (optional)</label>
            <input type="number" step="0.01" min="0" name="max_discount" id="ev_max_discount" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Min Order Amount</label>
            <input type="number" step="0.01" min="0" name="min_order_amount" id="ev_min_order_amount" class="form-control" value="0">
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="form-label">Starts At</label>
            <input type="datetime-local" name="starts_at" id="ev_starts_at" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Expires At</label>
            <input type="datetime-local" name="expires_at" id="ev_expires_at" class="form-control">
          </div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-6">
            <label class="form-label">Usage Limit (optional)</label>
            <input type="number" min="0" name="usage_limit" id="ev_usage_limit" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Per Customer Limit (optional)</label>
            <input type="number" min="0" name="per_customer_limit" id="ev_per_customer_limit" class="form-control">
          </div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="active" id="ev_active">
          <label class="form-check-label" for="ev_active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="update_voucher" class="btn btn-warning">Update Voucher</button>
      </div>
    </form>
  </div>
  </div>

    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form method="POST" enctype="multipart/form-data" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Edit Product
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_product_id" id="editProductId">
                    
                    <div class="mb-3">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="edit_product_name" id="editProductName" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="edit_description" id="editProductDescription" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="edit_category_id" id="editProductCategory" class="form-select" required>
                            <?php mysqli_data_seek($categories, 0); while ($cat = $categories->fetch_assoc()): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Photo (optional)</label>
                        <input type="file" name="edit_photo" class="form-control" accept="image/*">
                        <small class="form-text text-muted">Leave empty to keep current photo</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_product" class="btn btn-warning">
                        <i class="fas fa-save"></i> Update Product
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Edit Category
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_category_id" id="editCategoryId">
                    
                    <div class="mb-3">
                        <label class="form-label">Category Name</label>
                        <input type="text" name="edit_category_name" id="editCategoryName" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_category" class="btn btn-warning">
                        <i class="fas fa-save"></i> Update Category
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function editProduct(id, name, description, categoryId) {
            document.getElementById('editProductId').value = id;
            document.getElementById('editProductName').value = name;
            document.getElementById('editProductDescription').value = description;
            document.getElementById('editProductCategory').value = categoryId;
            
            var modal = new bootstrap.Modal(document.getElementById('editProductModal'));
            modal.show();
        }

        function editCategory(id, name) {
            document.getElementById('editCategoryId').value = id;
            document.getElementById('editCategoryName').value = name;
            
            var modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
            modal.show();
        }

        function openEditVoucher(v) {
            const modalEl = document.getElementById('editVoucherModal');
            document.getElementById('ev_voucher_id').value = v.voucher_id;
            document.getElementById('ev_code').value = v.code;
            document.getElementById('ev_discount_type').value = v.discount_type;
            document.getElementById('ev_discount_value').value = v.discount_value;
            document.getElementById('ev_max_discount').value = v.max_discount ?? '';
            document.getElementById('ev_min_order_amount').value = v.min_order_amount;
            document.getElementById('ev_starts_at').value = v.starts_at ? v.starts_at.replace(' ', 'T') : '';
            document.getElementById('ev_expires_at').value = v.expires_at ? v.expires_at.replace(' ', 'T') : '';
            document.getElementById('ev_usage_limit').value = v.usage_limit ?? '';
            document.getElementById('ev_per_customer_limit').value = v.per_customer_limit ?? '';
            document.getElementById('ev_active').checked = !!Number(v.active);
            new bootstrap.Modal(modalEl).show();
        }
        // Admin: update order status via fetch
        function updateOrderStatus(e, orderId, newStatus) {
            e.preventDefault();
            const row = document.getElementById('order-row-' + orderId);
            if (row) row.querySelectorAll('button').forEach(b => b.disabled = true);
            fetch('admin_orders_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_status&order_id=${orderId}&status=${encodeURIComponent(newStatus)}`
            })
            .then(r => r.json())
            .then(data => {
                alertify.set('notifier','position', 'top-center');
                if (!data.success) { alertify.error(data.message || 'Failed to update'); return; }
                alertify.success(data.message || 'Order status updated.');
                // Update row UI without reloading
                if (row) {
                    const statusBadge = row.querySelector('td:nth-child(8) span');
                    if (statusBadge) {
                        const cls = { PLACED: 'bg-primary', SHIPPED: 'bg-info', DELIVERED: 'bg-success', CANCELLED: 'bg-danger' };
                        statusBadge.className = 'badge ' + (cls[newStatus] || 'bg-secondary');
                        statusBadge.textContent = newStatus;
                    }
                    row.classList.add('table-success');
                    setTimeout(() => row.classList.remove('table-success'), 1200);
                }
            })
            .catch(() => { alertify.set('notifier','position', 'top-center'); alertify.error('Failed to update'); })
            .finally(() => { if (row) row.querySelectorAll('button').forEach(b => b.disabled = false); });
            return false;
        }
    </script>
</body>
</html>