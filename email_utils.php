<?php
/**
 * Email Utility Functions for Order Notifications
 * 
 * CONFIGURATION INSTRUCTIONS:
 * 1. Update the email settings below with your actual email configuration
 * 2. For Gmail: Enable 2FA and create an App Password
 * 3. For other providers: Update SMTP settings accordingly
 * 4. Test email sending before going live
 */

// Email configuration - UPDATE THESE WITH YOUR ACTUAL SETTINGS
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'yourbusiness@gmail.com'); // Your business email
define('SMTP_PASSWORD', 'your-app-password'); // Gmail App Password (not regular password)
define('FROM_EMAIL', 'yourbusiness@gmail.com'); // Must match SMTP_USERNAME
define('FROM_NAME', 'Your E-Commerce Store'); // Your store/business name

/**
 * Simple email sending function using PHP mail()
 * For production, consider using PHPMailer or similar library
 */
function sendOrderConfirmationEmail($customerEmail, $customerName, $orderDetails) {
    $subject = "Order Confirmation - Order #" . $orderDetails['order_id'];
    
    // Create email content
    $message = createOrderEmailTemplate($customerName, $orderDetails);
    
    // Email headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . FROM_NAME . " <" . FROM_EMAIL . ">" . "\r\n";
    $headers .= "Reply-To: " . FROM_EMAIL . "\r\n";
    
    // Send email
    $result = mail($customerEmail, $subject, $message, $headers);
    
    // Log email attempt (for debugging)
    error_log("Email sent to $customerEmail for order #{$orderDetails['order_id']}: " . ($result ? 'SUCCESS' : 'FAILED'));
    
    return $result;
}

/**
 * Send admin notification email
 */
function sendAdminOrderNotification($orderDetails) {
    $adminEmail = FROM_EMAIL; // Admin receives notifications
    $subject = "New Order Received - Order #" . $orderDetails['order_id'];
    
    $message = createAdminOrderTemplate($orderDetails);
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . FROM_NAME . " <" . FROM_EMAIL . ">" . "\r\n";
    
    return mail($adminEmail, $subject, $message, $headers);
}

/**
 * Create HTML email template for customer order confirmation
 */
function createOrderEmailTemplate($customerName, $orderDetails) {
    $orderId = $orderDetails['order_id'];
    $orderTotal = number_format($orderDetails['total'], 2);
    $orderDate = date('F j, Y g:i A', strtotime($orderDetails['created_at']));
    $paymentMethod = $orderDetails['payment_method'];
    
    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .order-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea; }
            .total { font-size: 1.2em; font-weight: bold; color: #667eea; }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 0.9em; }
            .button { display: inline-block; background: #667eea; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎉 Order Confirmation</h1>
                <p>Thank you for your order!</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($customerName) . ",</h2>
                <p>We've received your order and it's being processed. Here are your order details:</p>
                
                <div class='order-details'>
                    <h3>Order Information</h3>
                    <p><strong>Order Number:</strong> #$orderId</p>
                    <p><strong>Order Date:</strong> $orderDate</p>
                    <p><strong>Payment Method:</strong> $paymentMethod</p>
                    <p class='total'><strong>Total Amount:</strong> ₱$orderTotal</p>
                </div>
                
                <h3>Order Items</h3>
                <div style='background: white; padding: 15px; border-radius: 8px; margin: 10px 0;'>
    ";
    
    // Add order items if available
    if (isset($orderDetails['items']) && is_array($orderDetails['items'])) {
        foreach ($orderDetails['items'] as $item) {
            $itemTotal = number_format($item['price'] * $item['quantity'], 2);
            $html .= "
                    <div style='border-bottom: 1px solid #eee; padding: 10px 0; display: flex; justify-content: space-between;'>
                        <div>
                            <strong>" . htmlspecialchars($item['product_name']) . "</strong><br>
                            <small>Quantity: " . $item['quantity'] . " × ₱" . number_format($item['price'], 2) . "</small>
                        </div>
                        <div style='text-align: right;'>
                            <strong>₱$itemTotal</strong>
                        </div>
                    </div>
            ";
        }
    }
    
    $html .= "
                </div>
                
                <h3>Shipping Address</h3>
                <div style='background: white; padding: 15px; border-radius: 8px; margin: 10px 0;'>
                    <p>" . htmlspecialchars($orderDetails['shipping_full_name'] ?? 'N/A') . "<br>
                    " . htmlspecialchars($orderDetails['shipping_street'] ?? 'N/A') . "<br>
                    " . htmlspecialchars($orderDetails['shipping_city'] ?? 'N/A') . ", " . htmlspecialchars($orderDetails['shipping_province'] ?? 'N/A') . " " . htmlspecialchars($orderDetails['shipping_postal_code'] ?? 'N/A') . "<br>
                    Phone: " . htmlspecialchars($orderDetails['shipping_phone'] ?? 'N/A') . "</p>
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <p>We'll send you another email when your order ships!</p>
                    <a href='#' class='button'>Track Your Order</a>
                </div>
                
                <div class='footer'>
                    <p>Thank you for shopping with us!<br>
                    If you have any questions, please contact us at " . FROM_EMAIL . "</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return $html;
}

/**
 * Create admin notification template
 */
function createAdminOrderTemplate($orderDetails) {
    $orderId = $orderDetails['order_id'];
    $orderTotal = number_format($orderDetails['total'], 2);
    $orderDate = date('F j, Y g:i A', strtotime($orderDetails['created_at']));
    $customerName = $orderDetails['customer_name'] ?? 'N/A';
    $customerEmail = $orderDetails['contact_email'] ?? 'N/A';
    $paymentMethod = $orderDetails['payment_method'];
    
    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #ff6b35; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .alert { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .order-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🛒 New Order Alert</h1>
                <p>A new order has been placed!</p>
            </div>
            <div class='content'>
                <div class='alert'>
                    <strong>Action Required:</strong> A new order #$orderId needs your attention.
                </div>
                
                <div class='order-details'>
                    <h3>Order Details</h3>
                    <p><strong>Order ID:</strong> #$orderId</p>
                    <p><strong>Customer:</strong> $customerName</p>
                    <p><strong>Email:</strong> $customerEmail</p>
                    <p><strong>Order Date:</strong> $orderDate</p>
                    <p><strong>Payment Method:</strong> $paymentMethod</p>
                    <p><strong>Total:</strong> ₱$orderTotal</p>
                </div>
                
                <p><strong>Next Steps:</strong></p>
                <ul>
                    <li>Review the order details in your admin panel</li>
                    <li>Process the payment (if required)</li>
                    <li>Prepare items for shipping</li>
                    <li>Update order status</li>
                </ul>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return $html;
}

/**
 * Get customer details for email
 */
function getCustomerDetailsForEmail($conn, $customerId) {
    $stmt = mysqli_prepare($conn, "SELECT CONCAT(first_name, ' ', last_name) as name, email FROM customers WHERE customer_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $customerId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

/**
 * Get order items for email
 */
function getOrderItemsForEmail($conn, $orderId) {
    $stmt = mysqli_prepare($conn, "
        SELECT oi.quantity, oi.price, p.product_name 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.product_id 
        WHERE oi.order_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $orderId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = $row;
    }
    return $items;
}
?>
