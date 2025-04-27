<?php
require_once "../config/dbconn.php";
require_once "../pages/header.php";


$barangay_id = $_SESSION['barangay_id'] ?? 1;


// Fetch metrics
$sql = "SELECT COUNT(*) AS total_residents FROM Users WHERE role_id = 8 AND barangay_id = :bid";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$totalResidents = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(DISTINCT a.user_id) FROM Address a JOIN Users u ON a.user_id = u.user_id WHERE u.barangay_id = :bid";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$totalHouseholds = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) FROM DocumentRequest dr JOIN Users u ON dr.user_id = u.user_id WHERE dr.status = 'Pending' AND u.barangay_id = :bid";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$pendingRequests = (int) $stmt->fetchColumn();

$sql = "SELECT COUNT(*) FROM AuditTrail a JOIN Users u ON a.admin_user_id = u.user_id WHERE u.barangay_id = :bid AND a.action_timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$recentActivities = (int) $stmt->fetchColumn();

// Gender data
$sql = "SELECT gender, COUNT(*) AS count FROM Users WHERE role_id = 3 AND barangay_id = :bid GROUP BY gender";
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

// Document requests
$sql = "SELECT dt.document_name, COUNT(*) AS count FROM DocumentRequest dr JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id JOIN Users u ON dr.user_id = u.user_id WHERE u.barangay_id = :bid GROUP BY dt.document_name ORDER BY count DESC LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$docTypeData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$docLabels = [];
$docCounts = [];
foreach ($docTypeData as $d) {
    $docLabels[] = $d['document_name'];
    $docCounts[] = (int) $d['count'];
}
if (empty($docLabels)) {
    $docLabels = ['No Data'];
    $docCounts = [0];
}

// Recent requests
$sql = "SELECT dr.document_request_id, dt.document_name, CONCAT(u.first_name, ' ', u.last_name) AS requester, dr.status, dr.request_date FROM DocumentRequest dr JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id JOIN Users u ON dr.user_id = u.user_id WHERE u.barangay_id = :bid ORDER BY dr.request_date DESC LIMIT 5";
$stmt = $pdo->prepare($sql);
$stmt->execute([':bid' => $barangay_id]);
$recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch events
$sql = "SELECT * FROM Events WHERE barangay_id = :bid ORDER BY start_datetime";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
</head>
<body>
<main>
<section id="dashboard" class="p-4">
  <header class="mb-6">
    <h1 class="text-3xl font-bold text-blue-800">Barangay Dashboard</h1>
    <p class="text-gray-600">Overview of Barangay Activities and Statistics</p>
  </header>

  <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
    <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
      <div class="text-3xl font-bold text-blue-800"><?= $totalResidents ?></div>
      <div class="mt-2 text-gray-600">Residents</div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
      <div class="text-3xl font-bold text-blue-800"><?= $totalHouseholds ?></div>
      <div class="mt-2 text-gray-600">Households</div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
      <div class="text-3xl font-bold text-blue-800"><?= $pendingRequests ?></div>
      <div class="mt-2 text-gray-600">Pending Requests</div>
    </div>
    <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
      <div class="text-3xl font-bold text-blue-800"><?= $recentActivities ?></div>
      <div class="mt-2 text-gray-600">Recent Activities</div>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
      <h2 class="text-xl font-semibold text-gray-800 mb-4">Gender Distribution</h2>
      <canvas id="genderChart" width="400" height="300"></canvas>
    </div>
    <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
      <h2 class="text-xl font-semibold text-gray-800 mb-4">Top Document Requests</h2>
      <canvas id="docTypeChart" width="400" height="300"></canvas>
    </div>
  </div>

  <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg mb-6">
    <h2 class="text-xl font-semibold text-gray-800 mb-4">Barangay Calendar</h2>
    <div id="calendar"></div>
  </div>

  <div class="bg-white p-6 rounded-lg shadow hover:shadow-lg">
  <h2 class="text-xl font-semibold text-gray-800 mb-4">Recent Requests</h2>
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
            <td class="px-4 py-2 border-b"><?= htmlspecialchars($req['document_name']) ?></td>
            <td class="px-4 py-2 border-b"><?= htmlspecialchars($req['status']) ?></td>
            <td class="px-4 py-2 border-b"><?= htmlspecialchars($req['request_date']) ?></td>
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
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const genderCtx = document.getElementById('genderChart').getContext('2d');
    new Chart(genderCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($genderLabels) ?>,
            datasets: [{
                data: <?= json_encode($genderCounts) ?>,
                backgroundColor: ['rgba(54, 162, 235, 0.7)','rgba(255, 99, 132, 0.7)','rgba(255, 206, 86, 0.7)']
            }]
        }
    });

    const docCtx = document.getElementById('docTypeChart').getContext('2d');
    new Chart(docCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($docLabels) ?>,
            datasets: [{
                label: 'Requests',
                data: <?= json_encode($docCounts) ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.6)'
            }]
        },
        options: {
            scales: { y: { beginAtZero: true } }
        }
    });

    const calendarEl = document.getElementById('calendar');
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        events: <?= json_encode($calendarEvents) ?>,
        eventDidMount: function(info) {
            const location = info.event.extendedProps.location;
            info.el.querySelector('.fc-event-title').insertAdjacentHTML('afterend', 
                `<div class="fc-event-location text-xs">${location}</div>`);
        }
    });
    calendar.render();
});
</script>
</main>
</body>
</html>