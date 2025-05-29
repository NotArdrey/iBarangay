<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #333; padding: 6px; text-align: center; }
        th { background: #eee; }
    </style>
</head>
<body>
    <h1>Monthly Report – <?= htmlspecialchars("$month/$year") ?></h1>
    <p>
        Prepared by <?= htmlspecialchars($report['prepared_by_name'] ?? 'N/A') ?>
        on <?= !empty($report['submitted_at']) && $report['submitted_at'] !== '0000-00-00 00:00:00'
                ? date('M j, Y g:i A', strtotime($report['submitted_at']))
                : 'N/A' ?>
    </p>
    <table>
        <thead>
            <tr>
                <th>Nature of case</th>
                <th>Total number of case reported</th>
                <th>M/CSWD</th>
                <th>PNP</th>
                <th>COURT</th>
                <th>ISSUED BPOs</th>
                <th>MEDICAL</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($details as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['category_name']) ?></td>
                <td><?= $row['total_cases'] ?></td>
                <td><?= $row['mcwsd'] ?></td>
                <td><?= $row['total_pnp'] ?></td>
                <td><?= $row['total_court'] ?></td>
                <td><?= $row['total_bpo'] ?></td>
                <td><?= $row['total_medical'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
