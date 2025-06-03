<?php
/**
 * Email template functions for iBarangay system
 */

function getEmailTemplate($title, $content, $buttonText = null, $buttonLink = null) {
    $primaryColor = '#0a2240';
    $headerBg = $primaryColor;
    $headerText = '#fff';
    $bodyBg = '#f9fafb';
    $borderColor = '#e5e7eb';
    $titleColor = '#1f2937';
    $messageColor = '#4b5563';
    $footerColor = '#6b7280';
    $buttonBg = $primaryColor;
    $buttonHover = '#143366';
    $buttonText = $buttonText ?? '';
    $buttonLink = $buttonLink ?? '';

    $template = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>" . htmlspecialchars($title) . "</title>
        <style>
            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                background: #f4f4f4;
                margin: 0;
                padding: 0;
            }
            .email-container {
                max-width: 600px;
                margin: 40px auto;
                background: #fff;
                border-radius: 10px;
                box-shadow: 0 4px 24px rgba(10,34,64,0.08);
                overflow: hidden;
            }
            .header {
                background: $headerBg;
                color: $headerText;
                padding: 20px 0 10px 0;
                text-align: center;
                border-radius: 10px 10px 0 0;
            }
            .content {
                background: $bodyBg;
                padding: 32px 32px 16px 32px;
                border: 1px solid $borderColor;
                border-top: none;
                border-radius: 0 0 10px 10px;
            }
            .title {
                color: $titleColor;
                font-size: 24px;
                font-weight: 700;
                margin-top: 0;
                margin-bottom: 24px;
                text-align: center;
            }
            .message {
                color: $messageColor;
                font-size: 16px;
                margin-bottom: 32px;
                white-space: pre-line;
            }
            .button {
                display: inline-block;
                padding: 14px 32px;
                background: $buttonBg;
                color: #fff !important;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                font-size: 17px;
                margin: 32px 0 0 0;
                transition: background 0.2s;
                border: none;
                text-align: center;
            }
            .button:hover {
                background: $buttonHover;
            }
            .footer {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid $borderColor;
                color: $footerColor;
                font-size: 0.95em;
                text-align: center;
                background: $bodyBg;
                border-radius: 0 0 10px 10px;
            }
            @media (max-width: 600px) {
                .email-container { border-radius: 0; }
                .content { padding: 20px 8px 8px 8px; }
                .footer { padding: 16px 4px; font-size: 13px; }
            }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <div class='header'>
                <h2 style='margin: 0;'>$title</h2>
            </div>
            <div class='content'>
                <div class='message'>$content</div>";
    if ($buttonText && $buttonLink) {
        $template .= "<div style='text-align: center;'><a href='" . htmlspecialchars($buttonLink) . "' class='button'>" . htmlspecialchars($buttonText) . "</a></div>";
    }
    $template .= "
            </div>
            <div class='footer'>
                This is an automated message from the iBarangay System. Please do not reply to this email.<br><br>
                &copy; " . date('Y') . " iBarangay. All rights reserved.
            </div>
        </div>
    </body>
    </html>";
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

function getAccountReactivatedTemplate($name) {
    $title = "Account Reactivated";
    $content = "
        <p>Hello " . htmlspecialchars($name) . ",</p>
        <p>Your account has been reactivated. You can now log in and continue using the system.</p>
    ";
    return getEmailTemplate($title, $content);
} 