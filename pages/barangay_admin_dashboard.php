<?php
ob_start(); // start output buffering to prevent header issues
require_once "../config/dbconn.php";
require_once "../components/header.php";

$barangay_id = $_SESSION['barangay_id'] ?? 1;

// Basic Statistics
$sql = "SELECT COUNT(*) AS total_residents FROM users WHERE role_id = 8 AND barangay_id = :bid";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$totalResidents = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(DISTINCT p.user_id) FROM addresses a 
        JOIN persons p ON a.person_id = p.id 
        JOIN users u ON p.user_id = u.id 
        WHERE u.barangay_id = :bid";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$totalHouseholds = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) FROM document_requests dr 
        JOIN persons p ON dr.person_id = p.id 
        LEFT JOIN users u ON p.user_id = u.id 
        WHERE dr.status = 'pending' AND (u.barangay_id = :bid1 OR dr.barangay_id = :bid2)";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid1' => $barangay_id, ':bid2' => $barangay_id]);
$pendingRequests = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) FROM audit_trails a JOIN users u ON a.admin_user_id = u.id WHERE u.barangay_id = :bid AND a.action_timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$recentActivities = (int) $stmt->fetchColumn();

// Enhanced Blotter Analytics
// 1. Basic Blotter Counts
$sql = "SELECT COUNT(*) AS total_blotters FROM blotter_cases WHERE barangay_id = :bid";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$totalBlotters = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) AS open_blotters FROM blotter_cases WHERE barangay_id = :bid AND status IN ('pending', 'open')";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$openBlotters = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) AS closed_blotters FROM blotter_cases WHERE barangay_id = :bid AND status IN ('closed', 'completed')";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$closedBlotters = (int) $stmt->fetchColumn();

// 2. Resolution Rate
$resolutionRate = $totalBlotters > 0 ? round(($closedBlotters / $totalBlotters) * 100, 1) : 0;

// 3. Cases by Status Distribution
$sql = "SELECT status, COUNT(*) as count FROM blotter_cases WHERE barangay_id = :bid GROUP BY status";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = [];
$statusCounts = [];
foreach ($statusData as $status) {
    $statusLabels[] = ucfirst($status['status']);
    $statusCounts[] = (int) $status['count'];
}
if (empty($statusLabels)) {
    $statusLabels = ['No Data'];
    $statusCounts = [0];
}

// 4. Cases by Category
$sql = "SELECT cc.name, COUNT(*) as count 
        FROM blotter_cases bc 
        JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id 
        JOIN case_categories cc ON bcc.category_id = cc.id 
        WHERE bc.barangay_id = :bid 
        GROUP BY cc.name 
        ORDER BY count DESC 
        LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$categoryData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryLabels = [];
$categoryCounts = [];
foreach ($categoryData as $cat) {
    $categoryLabels[] = $cat['name'];
    $categoryCounts[] = (int) $cat['count'];
}
if (empty($categoryLabels)) {
    $categoryLabels = ['No Data'];
    $categoryCounts = [0];
}

// 5. Monthly Trends (Last 6 months)
$sql = "SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM blotter_cases 
        WHERE barangay_id = :bid 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$monthlyLabels = [];
$monthlyCounts = [];
foreach ($monthlyData as $month) {
    $monthlyLabels[] = date('M Y', strtotime($month['month'] . '-01'));
    $monthlyCounts[] = (int) $month['count'];
}
if (empty($monthlyLabels)) {
    $monthlyLabels = ['No Data'];
    $monthlyCounts = [0];
}

// 6. Average Resolution Time
$sql = "SELECT AVG(DATEDIFF(resolved_at, created_at)) as avg_days 
        FROM blotter_cases 
        WHERE barangay_id = :bid 
        AND resolved_at IS NOT NULL";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$avgResolutionDays = (int) $stmt->fetchColumn();

// 7. Recent Blotter Cases
$sql = "SELECT bc.case_number, bc.description, bc.status, bc.incident_date,
               CONCAT(p.first_name, ' ', p.last_name) as reporter
        FROM blotter_cases bc
        LEFT JOIN persons p ON bc.reported_by_person_id = p.id
        WHERE bc.barangay_id = :bid
        ORDER BY bc.created_at DESC
        LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$recentBlotters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 8. High Priority Cases (Open for more than 30 days)
$sql = "SELECT COUNT(*) FROM blotter_cases 
        WHERE barangay_id = :bid 
        AND status IN ('pending', 'open') 
        AND created_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$urgentCases = (int) $stmt->fetchColumn();

// Gender distribution (existing)
$sql = "SELECT gender, COUNT(*) AS count FROM users WHERE role_id = 8 AND barangay_id = :bid GROUP BY gender";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$genderData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$genderLabels = [];
$genderCounts = [];
foreach ($genderData as $g) {
    if (!empty($g['gender'])) {
        $genderLabels[] = $g['gender'];
        $genderCounts[] = (int) $g['count'];
    }
}
if (empty($genderLabels)) {
    $genderLabels = ['Male', 'Female', 'Others'];
    $genderCounts = [0,0,0];
}

// Document requests (existing)
$sql = "SELECT dt.name, COUNT(*) AS count FROM document_requests dr 
        JOIN document_types dt ON dr.document_type_id = dt.id 
        JOIN persons p ON dr.person_id = p.id 
        LEFT JOIN users u ON p.user_id = u.id 
        WHERE (u.barangay_id = :bid1 OR dr.barangay_id = :bid2) 
        GROUP BY dt.name ORDER BY count DESC LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid1' => $barangay_id, ':bid2' => $barangay_id]);
$docTypeData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$docLabels = [];
$docCounts = [];
foreach ($docTypeData as $d) {
    $docLabels[] = $d['name'];
    $docCounts[] = (int) $d['count'];
}
if (empty($docLabels)) {
    $docLabels = ['No Data'];
    $docCounts = [0];
}

// Recent requests (existing) - FIXED to use new table structure
$sql = "SELECT dr.id, dt.name, CONCAT(p.first_name, ' ', p.last_name) AS requester, dr.status, dr.request_date 
        FROM document_requests dr 
        JOIN document_types dt ON dr.document_type_id = dt.id 
        JOIN persons p ON dr.person_id = p.id
        LEFT JOIN users u ON p.user_id = u.id
        WHERE dr.barangay_id = :bid 
        ORDER BY dr.request_date DESC LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Events (existing)
$sql = "SELECT * FROM events WHERE barangay_id = :bid ORDER BY start_datetime";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$calendarEvents = [];
foreach ($events as $event) {
    $calendarEvents[] = [
        'title' => $event['title'],
        'start' => $event['start_datetime'],
        'end' => $event['end_datetime'],
        'location' => $event['location']
    ];
}

// Modified Emergency Contact update logic with redirect anchor added to remain in the contact section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_barangay_contact'])) {
    $localContact = trim($_POST['local_contact'] ?? '');
    $phonePattern = '/^(0|\+?63)\d{10}$/';
    if (!preg_match($phonePattern, $localContact)) {
        $_SESSION['error'] = 'Invalid phone number. Please enter a valid Philippine phone number.';
    } else {
        $updateStmt = $pdo->prepare("UPDATE barangay_settings SET local_barangay_contact = ? WHERE barangay_id = ?");
        $updateStmt->execute([$localContact, $barangay_id]);
        $_SESSION['success'] = 'Barangay Contact updated successfully';
    }
    header("Location: barangay_admin_dashboard.php#emergencyContact");
    exit;
}

// Fetch local barangay contact from barangay_settings (existing)
$contactStmt = $pdo->prepare("SELECT local_barangay_contact FROM barangay_settings WHERE barangay_id = ?");
$contactStmt->execute([$barangay_id]);
$barangayContact = $contactStmt->fetchColumn() ?: '';

$currentContact = $barangayContact ? htmlspecialchars($barangayContact) : 'Not Set';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Barangay Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .metric-card {
            transition: all 0.3s ease;
            border-left: 4px solid #3B82F6;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .metric-card.urgent {
            border-left-color: #EF4444;
        }
        .metric-card.success {
            border-left-color: #10B981;
        }
        .metric-card.warning {
            border-left-color: #F59E0B;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-pending { background-color: #FEF3C7; color: #92400E; }
        .status-open { background-color: #DBEAFE; color: #1E40AF; }
        .status-closed { background-color: #D1FAE5; color: #065F46; }
        .status-completed { background-color: #D1FAE5; color: #065F46; }
        .blotter-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>
<body>
<?php
// Show SweetAlert2 for error or success messages after form submission
if (isset($_SESSION['error'])) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '" . addslashes($_SESSION['error']) . "'
            });
        });
    </script>";
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '" . addslashes($_SESSION['success']) . "'
            });
        });
    </script>";
    unset($_SESSION['success']);
}
?>
<main>
<div class="container mx-auto p-4 max-w-7xl">
    <section id="dashboard" class="p-4">
        <header class="mb-8">
            <h1 class="text-4xl font-bold text-blue-800 mb-2">
                <i class="fas fa-tachometer-alt mr-3"></i>Barangay Dashboard
            </h1>
            <p class="text-gray-600 text-lg">Comprehensive Overview of Barangay Activities, Statistics & Analytics</p>
        </header>

        <!-- Key Metrics Row -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="metric-card bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <div class="flex items-center">
                    <div class="flex-1">
                        <div class="text-3xl font-bold text-blue-800"><?= $totalResidents ?></div>
                        <div class="mt-2 text-gray-600">Total Residents</div>
                    </div>
                    <div class="text-blue-500 text-3xl">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="metric-card bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <div class="flex items-center">
                    <div class="flex-1">
                        <div class="text-3xl font-bold text-blue-800"><?= $totalHouseholds ?></div>
                        <div class="mt-2 text-gray-600">Households</div>
                    </div>
                    <div class="text-green-500 text-3xl">
                        <i class="fas fa-home"></i>
                    </div>
                </div>
            </div>
            <div class="metric-card bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <div class="flex items-center">
                    <div class="flex-1">
                        <div class="text-3xl font-bold text-blue-800"><?= $pendingRequests ?></div>
                        <div class="mt-2 text-gray-600">Pending Requests</div>
                    </div>
                    <div class="text-amber-500 text-3xl">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
            </div>
            <div class="metric-card bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <div class="flex items-center">
                    <div class="flex-1">
                        <div class="text-3xl font-bold text-blue-800"><?= $recentActivities ?></div>
                        <div class="mt-2 text-gray-600">Recent Activities</div>
                    </div>
                    <div class="text-purple-500 text-3xl">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Blotter Analytics Section -->
        <div class="bg-white rounded-lg shadow-lg mb-8 overflow-hidden">
            <div class="blotter-summary text-white p-6">
                <h2 class="text-2xl font-bold mb-4">
                    <i class="fas fa-gavel mr-2"></i>Blotter Analytics Dashboard
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="text-center">
                        <div class="text-3xl font-bold"><?= $totalBlotters ?></div>
                        <div class="text-sm opacity-90">Total Cases</div>
                    </div>
                    <div class="text-center border-l border-white border-opacity-30">
                        <div class="text-3xl font-bold"><?= $openBlotters ?></div>
                        <div class="text-sm opacity-90">Open Cases</div>
                    </div>
                    <div class="text-center border-l border-white border-opacity-30">
                        <div class="text-3xl font-bold"><?= $closedBlotters ?></div>
                        <div class="text-sm opacity-90">Resolved Cases</div>
                    </div>
                    <div class="text-center border-l border-white border-opacity-30">
                        <div class="text-3xl font-bold"><?= $resolutionRate ?>%</div>
                        <div class="text-sm opacity-90">Resolution Rate</div>
                    </div>
                    <div class="text-center border-l border-white border-opacity-30">
                        <div class="text-3xl font-bold <?= $urgentCases > 0 ? 'text-red-300' : '' ?>"><?= $urgentCases ?></div>
                        <div class="text-sm opacity-90">Urgent Cases</div>
                        <div class="text-xs opacity-75">(>30 days old)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Blotter Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-pie mr-2 text-blue-600"></i>Cases by Status
                </h3>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-tags mr-2 text-green-600"></i>Top Case Categories
                </h3>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Monthly Trends and Resolution Time -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-line mr-2 text-purple-600"></i>Monthly Case Trends (Last 6 Months)
                </h3>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-clock mr-2 text-orange-600"></i>Resolution Metrics
                </h3>
                <div class="space-y-6">
                    <div class="text-center">
                        <div class="text-4xl font-bold text-blue-800"><?= $avgResolutionDays ?></div>
                        <div class="text-gray-600">Average Resolution Days</div>
                    </div>
                    <div class="border-t pt-4">
                        <div class="text-lg font-semibold text-gray-700 mb-2">Quick Stats</div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span>This Month:</span>
                                <span class="font-semibold"><?= end($monthlyCounts) ?: 0 ?> cases</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Success Rate:</span>
                                <span class="font-semibold text-green-600"><?= $resolutionRate ?>%</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Pending Review:</span>
                                <span class="font-semibold text-orange-600"><?= $openBlotters ?> cases</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Blotter Cases -->
        <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg mb-8">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fas fa-history mr-2 text-indigo-600"></i>Recent Blotter Cases
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Case #</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reporter</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Incident Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if(!empty($recentBlotters)): ?>
                            <?php foreach($recentBlotters as $blotter): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($blotter['case_number']) ?>
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-900">
                                    <?= htmlspecialchars(substr($blotter['description'], 0, 50)) ?><?= strlen($blotter['description']) > 50 ? '...' : '' ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= htmlspecialchars($blotter['reporter'] ?: 'N/A') ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="status-badge status-<?= $blotter['status'] ?>">
                                        <?= ucfirst($blotter['status']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= date('M j, Y', strtotime($blotter['incident_date'])) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                    <i class="fas fa-inbox text-4xl mb-2 opacity-50"></i>
                                    <div>No blotter cases found.</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Document Requests and Demographics -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-file-text mr-2 text-blue-600"></i>Top Document Requests
                </h3>
                <div class="chart-container">
                    <canvas id="docTypeChart"></canvas>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-venus-mars mr-2 text-pink-600"></i>Population by Gender
                </h3>
                <div class="chart-container">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Calendar Section -->
        <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg mb-8">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fas fa-calendar-alt mr-2 text-green-600"></i>Barangay Events Calendar
            </h3>
            <div id="calendar"></div>
        </div>

        <!-- Recent Document Requests -->
        <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg mb-8">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fas fa-clock mr-2 text-blue-600"></i>Recent Document Requests
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full border border-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 border-b text-left">Requester</th>
                            <th class="px-4 py-2 border-b text-left">Document</th>
                            <th class="px-4 py-2 border-b text-left">Status</th>
                            <th class="px-4 py-2 border-b text-left">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($recentRequests)): ?>
                            <?php foreach($recentRequests as $req): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 border-b"><?= htmlspecialchars($req['requester']) ?></td>
                                <td class="px-4 py-2 border-b"><?= htmlspecialchars($req['name']) ?></td>
                                <td class="px-4 py-2 border-b">
                                    <span class="status-badge status-<?= $req['status'] ?>">
                                        <?= ucfirst($req['status']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 border-b"><?= date('M j, Y', strtotime($req['request_date'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="px-4 py-2 text-center text-gray-600">No recent requests.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Emergency Contact Update -->
        <div id="emergencyContact" class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fas fa-phone-alt mr-2 text-red-600"></i>Emergency Contact Management
            </h3>
            <!-- Display current emergency contact number -->
            <p class="mb-2 text-sm text-gray-700">
                Current Emergency Contact: <span class="font-semibold"><?= $currentContact ?></span>
            </p>
            <form id="emergencyContactForm" method="POST" class="flex flex-col gap-4">
                <div class="flex gap-4">
                    <input type="text" name="local_contact" value="<?= htmlspecialchars($barangayContact) ?>" 
                           placeholder="Enter new Barangay Emergency Contact" 
                           required
                           class="flex-1 px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <button type="submit" name="update_barangay_contact" 
                            class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-6 rounded-lg transition-colors">
                        <i class="fas fa-save mr-2"></i>Update Contact
                    </button>
                </div>
                <p class="text-sm text-gray-600">
                    <i class="fas fa-info-circle mr-1"></i>
                    This contact will be displayed to residents for emergency situations.
                </p>
            </form>
        </div>
    </section>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Chart.js default configuration
    Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';
    Chart.defaults.plugins.legend.position = 'bottom';
    
    // Enhanced color palettes
    const statusColors = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6'];
    const categoryColors = ['#EC4899', '#06B6D4', '#84CC16', '#F59E0B', '#6366F1'];
    
    // Cases by Status Pie Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($statusLabels) ?>,
            datasets: [{
                data: <?= json_encode($statusCounts) ?>,
                backgroundColor: statusColors,
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 20 }
                }
            }
        }
    });

    // Cases by Category Bar Chart
    const categoryCtx = document.getElementById('categoryChart').getContext('2d');
    new Chart(categoryCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($categoryLabels) ?>,
            datasets: [{
                label: 'Cases',
                data: <?= json_encode($categoryCounts) ?>,
                backgroundColor: categoryColors,
                borderRadius: 4,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });

    // Monthly Trends Line Chart
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($monthlyLabels) ?>,
            datasets: [{
                label: 'Cases per Month',
                data: <?= json_encode($monthlyCounts) ?>,
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#3B82F6',
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });

    // Document Requests Bar Chart
    const docCtx = document.getElementById('docTypeChart').getContext('2d');
    new Chart(docCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($docLabels) ?>,
            datasets: [{
                label: 'Requests',
                data: <?= json_encode($docCounts) ?>,
                backgroundColor: '#10B981',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });

    // Gender Distribution Doughnut Chart
    const genderCtx = document.getElementById('genderChart').getContext('2d');
    new Chart(genderCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($genderLabels) ?>,
            datasets: [{
                data: <?= json_encode($genderCounts) ?>,
                backgroundColor: ['#3B82F6', '#EC4899', '#8B5CF6'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 20 }
                }
            }
        }
    });

    // FullCalendar initialization with event display fix
    const calendarEl = document.getElementById('calendar');
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        events: <?= json_encode($calendarEvents) ?>,
        eventDidMount: function(info) {
            const location = info.event.extendedProps.location;
            if (location) {
                const titleEl = info.el.querySelector('.fc-event-title');
                if (titleEl) {
                    titleEl.insertAdjacentHTML('afterend', 
                        `<div class="fc-event-location text-xs opacity-75">${location}</div>`);
                }
            }
        },
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,listWeek'
        }
    });
    calendar.render();

    // Add validation and form clearing for Emergency Contact Update form
    const emergencyForm = document.getElementById('emergencyContactForm');
    if (emergencyForm) {
        emergencyForm.addEventListener('submit', function(event) {
            const input = emergencyForm.querySelector("input[name='local_contact']");
            if (!input.value.trim()) {
                event.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Emergency Contact is required.'
                });
                return;
            }
            const phRegex = /^(0|\+?63)\d{10}$/;
            if (!phRegex.test(input.value.trim())) {
                event.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Please enter a valid Philippine phone number.'
                });
                return;
            }
            // Optionally, clear the field after successful submission.
            // Since the page is reloaded on header redirect, a brief delay ensures
            // clearing in case of Ajax implementation in future.
            setTimeout(() => {
                emergencyForm.reset();
            }, 500);
        });
    }
});
</script>
</body>
</html>