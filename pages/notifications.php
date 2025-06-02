<?php
require_once '../config/dbconn.php';
require_once '../functions/notification_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle mark as read actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
        markNotificationAsRead($_POST['notification_id'], $user_id);
    } elseif (isset($_POST['mark_all_read'])) {
        markAllNotificationsAsRead($user_id);
    }
    header('Location: notifications.php');
    exit();
}

// Fetch notifications
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications
$unread_count = getUnreadNotificationCount($user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - iBarangay</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0056b3;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }

        body, * {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .notifications-header h1 {
            margin: 0;
            color: var(--primary-color);
            font-size: 24px;
        }

        .mark-all-read {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .mark-all-read:hover {
            background: #004494;
        }

        .notification-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .notification-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            transition: transform 0.2s;
        }

        .notification-item:hover {
            transform: translateY(-2px);
        }

        .notification-item.unread {
            border-left: 4px solid var(--primary-color);
        }

        .notification-content {
            flex: 1;
        }

        .notification-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .notification-title {
            font-weight: 600;
            color: var(--dark-color);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 60%;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notification-meta {
            color: var(--secondary-color);
            font-size: 0.9em;
            display: flex;
            gap: 15px;
            align-items: center;
            white-space: nowrap;
        }

        .notification-message {
            color: var(--secondary-color);
            margin-bottom: 10px;
        }

        .notification-actions {
            display: flex;
            gap: 10px;
        }

        .mark-read-btn {
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .mark-read-btn:hover {
            background: #f0f0f0;
        }

        .notification-type {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .type-general { background: #e9ecef; color: #495057; }
        .type-urgent { background: #dc3545; color: white; }
        .type-high { background: #fd7e14; color: white; }
        .type-medium { background: #17a2b8; color: white; }
        .type-low { background: #28a745; color: white; }

        .no-notifications {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            color: var(--secondary-color);
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .notifications-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .notification-item {
                flex-direction: column;
            }

            .notification-actions {
                margin-top: 10px;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <?php include '../components/navbar.php'; ?>

    <div class="container">
        <div class="notifications-header">
            <h1>Notifications <?php echo $unread_count > 0 ? "($unread_count unread)" : ""; ?></h1>
            <?php if ($unread_count > 0): ?>
            <form method="POST" style="margin: 0;">
                <button type="submit" name="mark_all_read" class="mark-all-read">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="notification-list">
            <?php if (empty($notifications)): ?>
                <div class="no-notifications">
                    <i class="fas fa-bell-slash" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                    <h2>No Notifications</h2>
                    <p>You don't have any notifications at the moment.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?php echo !$notification['is_read'] ? 'unread' : ''; ?>">
                        <div class="notification-content">
                            <div class="notification-header-row">
                                <div class="notification-title">
                                    <?php echo htmlspecialchars($notification['title']); ?>
                                    <span class="notification-type type-<?php echo strtolower($notification['priority']); ?>">
                                        <?php echo ucfirst($notification['priority']); ?>
                                    </span>
                                </div>
                                <div class="notification-meta">
                                    <span><i class="far fa-clock"></i> <?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?></span>
                                    <?php if ($notification['action_url']): ?>
                                        <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" style="color: var(--primary-color);">
                                            <i class="fas fa-external-link-alt"></i> View Details
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="notification-message">
                                <?php echo htmlspecialchars($notification['message']); ?>
                            </div>
                        </div>
                        <?php if (!$notification['is_read']): ?>
                            <div class="notification-actions">
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                    <button type="submit" name="mark_read" class="mark-read-btn" title="Mark as read">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Add any JavaScript functionality here if needed
    </script>
</body>
</html> 