<?php
/**
 * Email Configuration File
 * 
 * Instructions to set up email notifications:
 * 
 * 1. FOR GMAIL SETUP:
 *    - Enable 2-Factor Authentication on your Gmail account
 *    - Generate an App Password: Google Account > Security > App passwords
 *    - Use the App Password (not your regular Gmail password)
 *    - Update the settings below
 * 
 * 2. FOR OTHER EMAIL PROVIDERS:
 *    - Contact your email provider for SMTP settings
 *    - Update the SMTP_HOST and SMTP_PORT accordingly
 * 
 * 3. TESTING:
 *    - After configuration, place a test order to verify emails are working
 *    - Check spam/junk folders if emails don't arrive
 */

// =============================================
// EMAIL CONFIGURATION - UPDATE THESE SETTINGS
// =============================================

// Your business email settings
$emailConfig = [
    'smtp_host' => 'smtp.gmail.com',           // Gmail SMTP server
    'smtp_port' => 587,                        // Gmail SMTP port
    'smtp_username' => 'yourbusiness@gmail.com', // Your business email
    'smtp_password' => 'your-16-digit-app-password', // Gmail App Password
    'from_email' => 'yourbusiness@gmail.com',   // Must match username
    'from_name' => 'Your Store Name',           // Your business name
];

// Alternative email providers:
// 
// OUTLOOK/HOTMAIL:
// 'smtp_host' => 'smtp-mail.outlook.com'
// 'smtp_port' => 587
// 
// YAHOO:
// 'smtp_host' => 'smtp.mail.yahoo.com'
// 'smtp_port' => 587
// 
// CUSTOM HOSTING:
// Check with your hosting provider for SMTP settings

// =============================================
// EMAIL TEMPLATES CUSTOMIZATION
// =============================================

$emailTemplates = [
    'store_logo_url' => 'https://yourdomain.com/logo.png', // Optional logo
    'store_website' => 'https://yourdomain.com',
    'support_email' => 'support@yourbusiness.com',
    'support_phone' => '+63 XXX XXX XXXX',
];

// =============================================
// NOTIFICATION SETTINGS
// =============================================

$notificationSettings = [
    'send_customer_email' => true,    // Send confirmation to customer
    'send_admin_email' => true,       // Send notification to admin
    'admin_email' => 'admin@yourbusiness.com', // Admin notification email
    'enable_sms' => false,            // Future: SMS notifications
];

?>

<!-- HTML Instructions for Easy Setup -->
<!DOCTYPE html>
<html>
<head>
    <title>Email Setup Instructions</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; padding: 20px; line-height: 1.6; }
        .step { background: #f8f9fa; padding: 15px; margin: 10px 0; border-left: 4px solid #007bff; }
        .code { background: #f4f4f4; padding: 10px; border-radius: 5px; font-family: monospace; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>🚀 Email Notification Setup Guide</h1>
    
    <div class="warning">
        <strong>⚠️ Important:</strong> This is a setup guide. Delete this file after configuration for security.
    </div>
    
    <h2>Step 1: Gmail App Password Setup</h2>
    <div class="step">
        <ol>
            <li>Go to your Google Account settings</li>
            <li>Click on "Security" in the left sidebar</li>
            <li>Enable "2-Step Verification" if not already enabled</li>
            <li>Once 2FA is enabled, click on "App passwords"</li>
            <li>Select "Mail" and generate a password</li>
            <li>Copy the 16-digit password (it will look like: abcd efgh ijkl mnop)</li>
        </ol>
    </div>
    
    <h2>Step 2: Update Email Configuration</h2>
    <div class="step">
        <p>Edit the <code>email_utils.php</code> file and update these lines:</p>
        <div class="code">
define('SMTP_USERNAME', 'yourbusiness@gmail.com'); // Your Gmail address<br>
define('SMTP_PASSWORD', 'abcd efgh ijkl mnop'); // Your 16-digit app password<br>
define('FROM_EMAIL', 'yourbusiness@gmail.com'); // Same as username<br>
define('FROM_NAME', 'Your Business Name'); // Your store name
        </div>
    </div>
    
    <h2>Step 3: Test Email System</h2>
    <div class="step">
        <ol>
            <li>Save your changes to email_utils.php</li>
            <li>Place a test order on your website</li>
            <li>Check your email for the order confirmation</li>
            <li>Check spam/junk folder if email doesn't arrive</li>
            <li>Check server error logs if there are issues</li>
        </ol>
    </div>
    
    <h2>Step 4: Email Features</h2>
    <div class="step">
        <h3>Customer receives:</h3>
        <ul>
            <li>✅ Order confirmation with details</li>
            <li>✅ Payment method information</li>
            <li>✅ Shipping address confirmation</li>
            <li>✅ Order items list with prices</li>
            <li>✅ Professional HTML email template</li>
        </ul>
        
        <h3>Admin receives:</h3>
        <ul>
            <li>✅ New order notification</li>
            <li>✅ Customer details</li>
            <li>✅ Order summary</li>
            <li>✅ Action items reminder</li>
        </ul>
    </div>
    
    <h2>Troubleshooting</h2>
    <div class="step">
        <p><strong>Emails not sending?</strong></p>
        <ul>
            <li>Check your App Password is correct (16 digits, no spaces in code)</li>
            <li>Verify 2FA is enabled on Gmail</li>
            <li>Check server error logs</li>
            <li>Test with a simple PHP mail script first</li>
        </ul>
        
        <p><strong>Emails going to spam?</strong></p>
        <ul>
            <li>Use a business domain email instead of Gmail</li>
            <li>Set up SPF/DKIM records (ask your hosting provider)</li>
            <li>Send test emails to different providers</li>
        </ul>
    </div>
    
    <div class="warning">
        <strong>🔒 Security Note:</strong> Delete this file (email_config.php) after setup to protect your configuration.
    </div>
</body>
</html>
