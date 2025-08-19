<?php
/**
 * Email Testing Script
 * Use this to test your email configuration before going live
 * DELETE THIS FILE after testing for security
 */

// Include the email utilities
include_once('email_utils.php');

// Test email configuration
$testEmail = 'your-test-email@gmail.com'; // UPDATE THIS with your test email
$testOrderDetails = [
    'order_id' => 'TEST123',
    'total' => 1500.00,
    'discount' => 100.00,
    'payment_method' => 'Test Payment',
    'created_at' => date('Y-m-d H:i:s'),
    'shipping_full_name' => 'Test Customer',
    'shipping_street' => '123 Test Street',
    'shipping_city' => 'Test City',
    'shipping_province' => 'Test Province',
    'shipping_postal_code' => '1234',
    'shipping_phone' => '09123456789',
    'contact_email' => $testEmail,
    'customer_name' => 'Test Customer',
    'items' => [
        [
            'product_name' => 'Test Product 1',
            'quantity' => 2,
            'price' => 500.00
        ],
        [
            'product_name' => 'Test Product 2',
            'quantity' => 1,
            'price' => 600.00
        ]
    ]
];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .result { margin: 20px 0; padding: 15px; border-radius: 5px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
    </style>
</head>
<body>
    <h1>📧 Email Configuration Test</h1>
    
    <div class="warning">
        <strong>⚠️ Important:</strong> Update the $testEmail variable above with your email address before testing.
    </div>
    
    <?php if (isset($_POST['test_customer_email'])): ?>
        <?php
        $result = sendOrderConfirmationEmail($testEmail, 'Test Customer', $testOrderDetails);
        ?>
        <div class="result <?php echo $result ? 'success' : 'error'; ?>">
            <?php if ($result): ?>
                ✅ <strong>Customer email sent successfully!</strong><br>
                Check your inbox at: <?php echo htmlspecialchars($testEmail); ?>
            <?php else: ?>
                ❌ <strong>Customer email failed to send.</strong><br>
                Check your email configuration and server error logs.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_POST['test_admin_email'])): ?>
        <?php
        $result = sendAdminOrderNotification($testOrderDetails);
        ?>
        <div class="result <?php echo $result ? 'success' : 'error'; ?>">
            <?php if ($result): ?>
                ✅ <strong>Admin email sent successfully!</strong><br>
                Check your admin inbox.
            <?php else: ?>
                ❌ <strong>Admin email failed to send.</strong><br>
                Check your email configuration and server error logs.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <h2>Test Email Functions</h2>
    
    <form method="POST" style="margin: 20px 0;">
        <button type="submit" name="test_customer_email" class="button">
            📨 Test Customer Confirmation Email
        </button>
    </form>
    
    <form method="POST" style="margin: 20px 0;">
        <button type="submit" name="test_admin_email" class="button">
            📫 Test Admin Notification Email
        </button>
    </form>
    
    <h2>Current Configuration</h2>
    <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; font-family: monospace;">
        SMTP Host: <?php echo SMTP_HOST; ?><br>
        SMTP Port: <?php echo SMTP_PORT; ?><br>
        From Email: <?php echo FROM_EMAIL; ?><br>
        From Name: <?php echo FROM_NAME; ?><br>
        Test Email: <?php echo htmlspecialchars($testEmail); ?>
    </div>
    
    <h2>Setup Checklist</h2>
    <ul>
        <li>✅ Update email configuration in email_utils.php</li>
        <li>✅ Set up Gmail App Password (if using Gmail)</li>
        <li>✅ Update $testEmail variable in this file</li>
        <li>⬜ Test customer email (click button above)</li>
        <li>⬜ Test admin email (click button above)</li>
        <li>⬜ Place actual test order</li>
        <li>⬜ Delete this test file for security</li>
    </ul>
    
    <div class="warning" style="margin-top: 30px;">
        <strong>🔒 Security:</strong> Delete this file (email_test.php) after testing is complete.
    </div>
</body>
</html>
