<?php
/**
 * audit_trail.php ― role filter added
 *  ▸ live global search, column sorting
 *  ▸ automatic filters (table name, role, user, date range)
 *  ▸ table renamed to #auditTrailTable, “Table” → “Table Name”
 */
require_once "../pages/header.php";
require "../config/dbconn.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/index.php");
    exit;
}

$bid = $_SESSION['barangay_id'];

/* ── fetch audit-trail rows + role names ────────────────────────── */
$stmt = $pdo->prepare("
    SELECT a.*,
           CONCAT(u.first_name,' ',u.last_name) AS admin_name,
           r.role_name
    FROM   AuditTrail a
    JOIN   Users u ON u.user_id  = a.admin_user_id
    JOIN   Role  r ON r.role_id  = u.role_id
    WHERE  u.barangay_id = :bid
    ORDER  BY a.action_timestamp DESC
");
$stmt->execute([':bid' => $bid]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── build filter lists ─────────────────────────────────────────── */
$tables=$users=$roles=[];
$allowedRoles=[
    'Barangay Captain',
    'Barangay Secretary',
    'Barangay Treasurer',
    'Barangay Councilors',
    'Chief Officer'
];
foreach($records as $r){
    $tables[$r['table_name']] = true;
    $users[$r['admin_name']]  = true;
    if(in_array($r['role_name'],$allowedRoles))
        $roles[$r['role_name']] = true;
}
$tables=array_keys($tables);
$users =array_keys($users);
$roles =array_keys($roles);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Audit Trail</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 p-4 md:p-6">

<section class="max-w-7xl mx-auto">
<header class="mb-6 space-y-4">
  <div class="flex justify-between items-center flex-wrap gap-4">
    <h1 class="text-3xl font-bold text-blue-800">Audit Trail</h1>

    <div class="w-full md:w-64 relative">
      <input id="search" type="text" placeholder="Search…"
             class="w-full pl-10 pr-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
      <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
    </div>
  </div>

  <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
    <div class="flex flex-wrap items-end gap-4">
      <div class="min-w-[160px]">
        <label for="tableFilter" class="block text-sm font-medium text-gray-700 mb-1">Table Name</label>
        <select id="tableFilter"
                class="w-full p-2 border rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
          <option value="">All Tables</option>
          <?php foreach($tables as $t):?><option><?=htmlspecialchars($t)?></option><?php endforeach;?>
        </select>
      </div>

      <div class="min-w-[160px]">
        <label for="roleFilter" class="block text-sm font-medium text-gray-700 mb-1">Role</label>
        <select id="roleFilter"
                class="w-full p-2 border rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
          <option value="">All Roles</option>
          <?php foreach($roles as $r):?><option><?=htmlspecialchars($r)?></option><?php endforeach;?>
        </select>
      </div>

      <div class="min-w-[160px]">
        <label for="userFilter" class="block text-sm font-medium text-gray-700 mb-1">User</label>
        <select id="userFilter"
                class="w-full p-2 border rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
          <option value="">All Users</option>
          <?php foreach($users as $u):?><option><?=htmlspecialchars($u)?></option><?php endforeach;?>
        </select>
      </div>

      <div class="min-w-[220px]">
        <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
        <div class="flex gap-2">
          <input id="startDate" type="date"
                 class="flex-1 p-2 border rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
          <input id="endDate" type="date"
                 class="flex-1 p-2 border rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
        </div>
      </div>

      <button type="button" onclick="resetFilters()"
              class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-md text-sm font-medium">
        Reset
      </button>
    </div>
  </div>
</header>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
<div class="overflow-x-auto">
<table id="auditTrailTable" class="min-w-full divide-y divide-gray-200">
  <thead class="bg-gray-50">
    <tr>
      <?php
        $heads=['Table Name','User','Description','Timestamp'];
        $sortable=[true,true,false,true];
        foreach($heads as $i=>$h):?>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider
                     <?=$sortable[$i]?'sortable cursor-pointer':''?>">
            <?=$h?>
            <?php if($sortable[$i]):?><i class="sort-arrow fas fa-sort ml-1"></i><?php endif;?>
          </th>
      <?php endforeach;?>
    </tr>
  </thead>
  <tbody class="bg-white divide-y divide-gray-200">
    <?php if($records): foreach($records as $r): ?>
    <tr data-table="<?=strtolower($r['table_name'])?>"
        data-role="<?=strtolower($r['role_name'])?>"
        data-user="<?=strtolower($r['admin_name'])?>"
        data-ts="<?=$r['action_timestamp']?>">
      <td class="px-4 py-3 text-sm text-gray-600 border-b"><?=htmlspecialchars($r['table_name'])?></td>
      <td class="px-4 py-3 text-sm text-gray-600 border-b"><?=htmlspecialchars($r['admin_name'])?></td>
      <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate border-b"><?=htmlspecialchars($r['description'])?></td>
      <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap border-b">
        <?=date('M j, Y H:i',strtotime($r['action_timestamp']))?>
      </td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">No audit records found.</td></tr>
    <?php endif;?>
  </tbody>
</table>
</div>
</div>
</section>

<script>
const searchInput=document.getElementById('search');
['keyup','change'].forEach(e=>searchInput.addEventListener(e,filter));
['tableFilter','roleFilter','userFilter','startDate','endDate'].forEach(id=>
  document.getElementById(id).addEventListener('change',filter));

function filter(){
  const term = searchInput.value.toLowerCase();
  const tbl  = document.getElementById('tableFilter').value.toLowerCase();
  const role = document.getElementById('roleFilter').value.toLowerCase();
  const usr  = document.getElementById('userFilter').value.toLowerCase();
  const sd   = document.getElementById('startDate').value;
  const ed   = document.getElementById('endDate').value;
  const start= sd?new Date(sd+'T00:00:00'):null;
  const end  = ed?new Date(ed+'T23:59:59'):null;

  document.querySelectorAll('#auditTrailTable tbody tr').forEach(row=>{
    const rowTbl = row.dataset.table;
    const rowRole= row.dataset.role;
    const rowUsr = row.dataset.user;
    const rowTs  = new Date(row.dataset.ts);
    const text   = row.innerText.toLowerCase();

    let show = true;
    if(term && !text.includes(term)) show=false;
    if(tbl  && rowTbl!==tbl)         show=false;
    if(role && rowRole!==role)       show=false;
    if(usr  && rowUsr!==usr)         show=false;
    if(start&& rowTs<start)          show=false;
    if(end  && rowTs>end)            show=false;

    row.style.display=show?'':'none';
  });
}

// sortable headers
document.querySelectorAll('#auditTrailTable thead th.sortable').forEach((th,colIdx)=>{
  th.addEventListener('click',()=>{
    document.querySelectorAll('#auditTrailTable thead th.sortable').forEach(h=>{
      h.dataset.dir='';
      h.querySelector('.sort-arrow').className='sort-arrow fas fa-sort';
    });
    const dir = th.dataset.dir==='asc'?'desc':'asc';
    th.dataset.dir=dir;
    th.querySelector('.sort-arrow').className=
      dir==='asc'?'sort-arrow fas fa-sort-up':'sort-arrow fas fa-sort-down';
    sort(colIdx,dir);
  });
});
function sort(col,dir){
  const tbody=document.querySelector('#auditTrailTable tbody');
  Array.from(tbody.rows).sort((a,b)=>{
    const isDate=col===3;
    let A=isDate?new Date(a.dataset.ts):a.cells[col].innerText.toLowerCase();
    let B=isDate?new Date(b.dataset.ts):b.cells[col].innerText.toLowerCase();
    return (A>B?1:A<B?-1:0)*(dir==='asc'?1:-1);
  }).forEach(r=>tbody.appendChild(r));
}

function resetFilters(){
  ['search','tableFilter','roleFilter','userFilter','startDate','endDate']
    .forEach(id=>document.getElementById(id).value='');
  filter();
}
filter();
</script>
</body>
</html>
