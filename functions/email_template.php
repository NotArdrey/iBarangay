<?php
/**
 * Email template functions for iBarangay system
 */

function getEmailTemplate($title, $content, $buttonText = null, $buttonLink = null) {
    $logoPath = 'https://localhost/iBarangay/photo/logo.png';
    
    $template = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($title) . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                margin: 0;
                padding: 0;
                background-color: #f4f4f4;
            }
            .email-container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
                background-color: #ffffff;
            }
            .header {
                text-align: center;
                padding: 20px 0;
                background-color: #0a2240;
                border-radius: 8px 8px 0 0;
            }
            .logo {
                max-width: 150px;
                height: auto;
            }
            .content {
                padding: 30px 20px;
                background-color: #ffffff;
            }
            .title {
                color: #0a2240;
                font-size: 24px;
                margin-bottom: 20px;
                text-align: center;
            }
            .message {
                color: #333;
                font-size: 16px;
                margin-bottom: 30px;
            }
            .button {
                display: inline-block;
                padding: 12px 24px;
                background-color: #0a2240;
                color: #ffffff;
                text-decoration: none;
                border-radius: 4px;
                font-weight: bold;
                margin: 20px 0;
            }
            .footer {
                text-align: center;
                padding: 20px;
                background-color: #f8f9fa;
                border-top: 1px solid #eee;
                color: #666;
                font-size: 14px;
            }
            .verification-code {
                background-color: #f8f9fa;
                padding: 15px;
                border-radius: 4px;
                text-align: center;
                font-size: 24px;
                font-weight: bold;
                color: #0a2240;
                margin: 20px 0;
            }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="header">
                <img src="' . $logoPath . '" alt="iBarangay Logo" class="logo">
            </div>
            <div class="content">
                <h1 class="title">' . htmlspecialchars($title) . '</h1>
                <div class="message">' . $content . '</div>';
    
    if ($buttonText && $buttonLink) {
        $template .= '
                <div style="text-align: center;">
                    <a href="' . htmlspecialchars($buttonLink) . '" class="button">' . htmlspecialchars($buttonText) . '</a>
                </div>';
    }
    
    $template .= '
            </div>
            <div class="footer">
                <p>This is an automated message from iBarangay System. Please do not reply to this email.</p>
                <p>&copy; ' . date('Y') . ' iBarangay. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return $template;
}

function getVerificationCodeTemplate($code) {
    $title = "Verification Code";
    $content = '
        <p>Please use the following verification code to complete your request:</p>
        <div class="verification-code">' . $code . '</div>
        <p>This code will expire in 15 minutes.</p>
        <p>If you did not request this code, please ignore this email and ensure your account is secure.</p>';
    
    return getEmailTemplate($title, $content);
}

function getPasswordResetTemplate($resetLink) {
    $title = "Password Reset Request";
    $content = '
        <p>We received a request to reset your password. Click the button below to reset your password:</p>
        <p>If you did not request a password reset, please ignore this email.</p>';
    
    return getEmailTemplate($title, $content, "Reset Password", $resetLink);
}

function getVerificationEmailTemplate($verificationLink) {
    $title = "Email Verification";
    $content = '
        <p>Thank you for registering with iBarangay. Please verify your email address by clicking the button below:</p>
        <p>This link will expire in 24 hours.</p>';
    
    return getEmailTemplate($title, $content, "Verify Email", $verificationLink);
}

function getDocumentReadyTemplate($documentName, $isCedula = false) {
    $title = $isCedula ? "Community Tax Certificate (Cedula) Ready for Pickup" : "Document Ready";
    $content = $isCedula ? '
        <p>Your Community Tax Certificate (Cedula) request has been processed and is ready for pickup at the Barangay Hall.</p>
        <p>Please note that Cedula must be obtained in person. Bring a valid ID when claiming your document.</p>
        <p><strong>Office Hours:</strong> Monday to Friday, 8:00 AM to 5:00 PM</p>' : 
        '<p>Your requested document "' . htmlspecialchars($documentName) . '" is ready.</p>';
    
    return getEmailTemplate($title, $content);
}

function getEventNotificationTemplate($eventTitle, $eventDetails, $isPostponed = false) {
    $title = $isPostponed ? "Event Postponed: " . $eventTitle : "New Event: " . $eventTitle;
    $content = '
        <p>' . ($isPostponed ? "The following event has been postponed:" : "A new event has been scheduled:") . '</p>
        <div style="background-color: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0;">
            ' . $eventDetails . '
        </div>';
    
    return getEmailTemplate($title, $content);
}

function getAccountSuspendedTemplate($userName, $reason) {
    $title = "Account Suspension Notice";
    $content = '
        <p>Hello ' . htmlspecialchars($userName) . ',</p>
        <p>Your account has been suspended for the following reason:</p>
        <div style="background-color: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0;">
            ' . htmlspecialchars($reason) . '
        </div>
        <p>If you believe this is a mistake, please contact your barangay administrator.</p>';
    
    return getEmailTemplate($title, $content);
} 