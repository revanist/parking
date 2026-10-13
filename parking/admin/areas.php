<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/../includes/dbconn.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle='Parking Areas'; $role='Admin'; $activeItem='areas';
$error=''; $success='';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid or expired form token. Please go back, refresh the page, and try again.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action=$_POST['action']??'';
  try {
    if($action==='add_area'){
      $name=trim($_POST['area_name']??''); $desc=trim($_POST['description']??''); $type=$_POST['area_type']??'general';
      if($name==='') throw new RuntimeException('Area name is required.');
      if(!in_array($type,['student','faculty','visitor','motorcycle','general'],true)) throw new RuntimeException('Invalid area type.');
      $stmt=$pdo->prepare('INSERT INTO parking_areas (area_name,description,area_type) VALUES (?,?,?)'); $stmt->execute([$name,$desc?:null,$type]); $success='Parking area created.';
    } elseif($action==='add_slot'){
      $areaId=(int)($_POST['parking_area_id']??0); $code=strtoupper(trim($_POST['slot_code']??''));
      if(!$areaId || $code==='') throw new RuntimeException('Area and slot code are required.');
      $stmt=$pdo->prepare("SELECT id FROM parking_areas WHERE id=? AND status='active'"); $stmt->execute([$areaId]); if(!$stmt->fetch()) throw new RuntimeException('Select an active parking area.');
      $stmt=$pdo->prepare('INSERT INTO parking_slots (parking_area_id,slot_code) VALUES (?,?)'); $stmt->execute([$areaId,$code]); $success='Parking slot created.';
    } elseif($action==='toggle_area'){
      $id=(int)($_POST['id']??0); $stmt=$pdo->prepare("UPDATE parking_areas SET status=CASE WHEN status='active' THEN 'inactive' ELSE 'active' END WHERE id=?"); $stmt->execute([$id]); $success='Parking area status updated.';
    } elseif($action==='toggle_slot'){
      $id=(int)($_POST['id']??0); $stmt=$pdo->prepare("SELECT status FROM parking_slots WHERE id=?"); $stmt->execute([$id]); $status=$stmt->fetchColumn();
      if(!$status) throw new RuntimeException('Slot not found.');
      if($status==='reserved') throw new RuntimeException('A reserved slot is controlled by its approved permit.');
      if($status==='maintenance') $new='available'; elseif($status==='available') $new='maintenance'; else $new='available';
      $pdo->prepare('UPDATE parking_slots SET status=? WHERE id=?')->execute([$new,$id]); $success='Parking slot status updated.';
    }
  } catch (Throwable $e) { $error=$e instanceof RuntimeException?$e->getMessage():'Could not complete the parking-area action. The name/code may already exist.'; }
}
$areas=$pdo->query("SELECT pa.*, COUNT(ps.id) slot_count, SUM(ps.status='available') available_count, SUM(ps.status='reserved') reserved_count, SUM(ps.status='maintenance') maintenance_count FROM parking_areas pa LEFT JOIN parking_slots ps ON ps.parking_area_id=pa.id GROUP BY pa.id ORDER BY pa.area_name")->fetchAll();
$areaList=$pdo->query("SELECT id,area_name,area_type FROM parking_areas WHERE status='active' ORDER BY area_name")->fetchAll();
$filterArea = (int)($_GET['filter_area'] ?? $_POST['filter_area'] ?? 0);
$filterStatus = trim($_GET['filter_status'] ?? $_POST['filter_status'] ?? '');
$slotSearch = trim($_GET['slot_search'] ?? $_POST['slot_search'] ?? '');

$slotWhere = [];
$slotParams = [];

if ($filterArea > 0) {
    $slotWhere[] = 'ps.parking_area_id = ?';
    $slotParams[] = $filterArea;
}
if (in_array($filterStatus, ['available', 'reserved', 'maintenance', 'inactive'], true)) {
    $slotWhere[] = 'ps.status = ?';
    $slotParams[] = $filterStatus;
}
if ($slotSearch !== '') {
    $slotWhere[] = '(ps.slot_code LIKE ? OR pa.area_name LIKE ?)';
    $slotParams[] = '%' . $slotSearch . '%';
    $slotParams[] = '%' . $slotSearch . '%';
}

$slotSql = "SELECT ps.*, pa.area_name, pa.area_type FROM parking_slots ps JOIN parking_areas pa ON pa.id = ps.parking_area_id"
    . ($slotWhere ? ' WHERE ' . implode(' AND ', $slotWhere) : '')
    . " ORDER BY pa.area_name, ps.slot_code";

$slotStmt = $pdo->prepare($slotSql);
$slotStmt->execute($slotParams);
$slots = $slotStmt->fetchAll();

$totalSlotsCount = (int)$pdo->query("SELECT COUNT(*) FROM parking_slots")->fetchColumn();

include __DIR__.'/../layouts/dashboard_header.php'; include __DIR__.'/../includes/sidebar.php';
?>
<div class="px-4 py-6 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full">
<div class="mb-8"><p class="text-xs font-bold uppercase tracking-[.18em] text-slate-500">Administration</p><h1 class="mt-2 text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Parking Areas & Slots</h1><p class="mt-1.5 text-xs sm:text-sm text-slate-500">Create parking areas and control which individual slots are available for permit assignment.</p></div>
<?php if($error): ?><div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 flex items-start gap-3"><i class="fas fa-circle-exclamation text-rose-500 mt-0.5"></i><span><?=h($error)?></span></div><?php endif; ?>
<?php if($success): ?><div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 flex items-start gap-3"><i class="fas fa-circle-check text-emerald-500 mt-0.5"></i><span><?=h($success)?></span></div><?php endif; ?>
<div class="grid gap-6 xl:grid-cols-2">
<section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm"><h2 class="text-lg font-bold text-slate-900">Create parking area</h2><form method="post" class="mt-5 space-y-4"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_area"><input name="area_name" required placeholder="Area Name (e.g. Student Parking)" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><textarea name="description" placeholder="Description / Location Notes" rows="2" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"></textarea><select name="area_type" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><?php foreach(['student','faculty','visitor','motorcycle','general'] as $t): ?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach; ?></select><button class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95 w-full sm:w-auto">Create area</button></form></section>
<section class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6 shadow-sm"><h2 class="text-lg font-bold text-slate-900">Add parking slot</h2><form method="post" class="mt-5 space-y-4"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_slot"><select name="parking_area_id" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><option value="">Select area</option><?php foreach($areaList as $a): ?><option value="<?=$a['id']?>"><?=h($a['area_name'])?> (<?=h($a['area_type'])?>)</option><?php endforeach; ?></select><input name="slot_code" required maxlength="30" placeholder="Slot Code (e.g. STU-04)" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 uppercase text-sm focus:outline-none focus:ring-2 focus:ring-slate-300"><button class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition active:scale-95 w-full sm:w-auto">Add slot</button></form></section>
</div>
<section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden"><div class="p-5 sm:p-6 border-b border-slate-100"><h2 class="text-lg font-bold text-slate-900">Areas</h2></div><div class="overflow-x-auto"><table class="w-full text-left text-sm min-w-[620px]"><thead class="bg-slate-50/50 text-xs uppercase tracking-wider text-slate-400"><tr><th class="px-6 py-4">Area</th><th class="px-6 py-4">Type</th><th class="px-6 py-4">Slots</th><th class="px-6 py-4">Status</th><th class="px-6 py-4 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-100"><?php if(!$areas): ?><tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">No parking areas found.</td></tr><?php endif; ?><?php foreach($areas as $a): ?><tr class="hover:bg-slate-50/60 transition"><td class="px-6 py-4 font-semibold text-slate-800"><?=h($a['area_name'])?><div class="text-xs font-normal text-slate-400"><?=h($a['description']??'')?></div></td><td class="px-6 py-4 text-slate-700"><?=h(ucfirst($a['area_type']))?></td><td class="px-6 py-4 text-slate-600"><span class="font-semibold text-slate-900"><?=h((string)$a['slot_count'])?></span> total · <?=h((string)$a['available_count'])?> available · <?=h((string)$a['reserved_count'])?> reserved</td><td class="px-6 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold <?=$a['status']==='active'?'bg-emerald-50 text-emerald-700':'bg-slate-100 text-slate-600'?>"><?=h(ucfirst($a['status']))?></span></td><td class="px-6 py-4 text-right"><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_area"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50 transition"><?=$a['status']==='active'?'Deactivate':'Activate'?></button></form></td></tr><?php endforeach; ?></tbody></table></div></section>

<!-- Filterable Slots Section -->
<section class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden" id="slotsSection">
  <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
    <div>
      <div class="flex items-center gap-3">
        <h2 class="text-lg font-bold text-slate-900">Slots</h2>
        <span id="slotsCountBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
          Showing <?= count($slots) ?> of <?= $totalSlotsCount ?> slots
        </span>
      </div>
      <p class="mt-1 text-xs sm:text-sm text-slate-500">Reserved means assigned to an approved permit; maintenance means unavailable for assignment.</p>
    </div>

    <!-- Quick Area Filter Buttons -->
    <div class="flex flex-wrap items-center gap-1.5" id="quickAreaFilters">
      <button type="button" data-area-id="" class="quick-area-btn rounded-xl px-3 py-1.5 text-xs font-semibold transition <?= $filterArea === 0 ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
        All (<?= $totalSlotsCount ?>)
      </button>
      <?php foreach($areas as $a): ?>
        <button type="button" data-area-id="<?= (int)$a['id'] ?>" class="quick-area-btn rounded-xl px-3 py-1.5 text-xs font-semibold transition <?= $filterArea === (int)$a['id'] ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
          <?= h($a['area_name']) ?> <span class="opacity-75">(<?= (int)$a['slot_count'] ?>)</span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Filter & Search Controls Bar -->
  <div class="p-4 sm:p-5 bg-slate-50/70 border-b border-slate-100">
    <form method="get" id="slotFilterForm" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
      <!-- Search Input -->
      <div class="relative flex-1">
        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
          <i class="fas fa-search text-xs"></i>
        </span>
        <input type="text" id="slotSearchInput" name="slot_search" value="<?= h($slotSearch) ?>" placeholder="Search slot code (e.g. STU-01, FAC, MOT)..." class="w-full rounded-xl border border-slate-200 bg-white pl-9 pr-8 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
        <button type="button" id="clearSearchBtn" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 transition <?= empty($slotSearch) ? 'hidden' : '' ?>" title="Clear search">
          <i class="fas fa-times-circle text-sm"></i>
        </button>
      </div>

      <!-- Area Select Dropdown -->
      <div class="w-full sm:w-52">
        <select id="slotAreaSelect" name="filter_area" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
          <option value="">All Parking Areas</option>
          <?php foreach($areas as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $filterArea === (int)$a['id'] ? 'selected' : '' ?>>
              <?= h($a['area_name']) ?> (<?= (int)$a['slot_count'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Status Select Dropdown -->
      <div class="w-full sm:w-44">
        <select id="slotStatusSelect" name="filter_status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">
          <option value="">All Statuses</option>
          <option value="available" <?= $filterStatus === 'available' ? 'selected' : '' ?>>Available</option>
          <option value="reserved" <?= $filterStatus === 'reserved' ? 'selected' : '' ?>>Reserved</option>
          <option value="maintenance" <?= $filterStatus === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
        </select>
      </div>

      <!-- Reset Filters Button -->
      <button type="button" id="resetFiltersBtn" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition whitespace-nowrap active:scale-95 shadow-sm" title="Reset all filters">
        <i class="fas fa-rotate-left text-xs"></i> Reset
      </button>
    </form>
  </div>

  <!-- Slots Table -->
  <div class="overflow-x-auto">
    <table class="w-full text-left text-sm min-w-[540px]">
      <thead class="bg-slate-50/50 text-xs uppercase tracking-wider text-slate-400">
        <tr>
          <th class="px-6 py-4">Slot</th>
          <th class="px-6 py-4">Area</th>
          <th class="px-6 py-4">Status</th>
          <th class="px-6 py-4 text-right">Action</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100" id="slotsTableBody">
        <?php foreach($slots as $s): ?>
          <tr class="slot-row hover:bg-slate-50/60 transition"
              data-slot="<?= h(strtoupper($s['slot_code'])) ?>"
              data-area-id="<?= (int)$s['parking_area_id'] ?>"
              data-area-name="<?= h(strtolower($s['area_name'])) ?>"
              data-status="<?= h(strtolower($s['status'])) ?>">
            <td class="px-6 py-4 font-semibold font-mono text-slate-900"><?= h($s['slot_code']) ?></td>
            <td class="px-6 py-4 text-slate-600"><?= h($s['area_name']) ?></td>
            <td class="px-6 py-4">
              <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?= $s['status']==='available' ? 'bg-emerald-50 text-emerald-700' : ($s['status']==='reserved' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700') ?>">
                <?= h(ucfirst($s['status'])) ?>
              </span>
            </td>
            <td class="px-6 py-4 text-right">
              <?php if($s['status']!=='reserved'): ?>
                <form method="post" class="inline-block">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle_slot">
                  <input type="hidden" name="id" value="<?= $s['id'] ?>">
                  <input type="hidden" name="filter_area" value="<?= (int)$filterArea ?>">
                  <input type="hidden" name="filter_status" value="<?= h($filterStatus) ?>">
                  <input type="hidden" name="slot_search" value="<?= h($slotSearch) ?>">
                  <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50 transition">
                    <?= $s['status']==='available' ? 'Set maintenance' : 'Make available' ?>
                  </button>
                </form>
              <?php else: ?>
                <span class="text-xs text-slate-400">Assigned to permit</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr id="noSlotsMatchRow" class="<?= count($slots) === 0 ? '' : 'hidden' ?>">
          <td colspan="4" class="px-6 py-12 text-center text-slate-400">
            <div class="flex flex-col items-center justify-center gap-2">
              <i class="fas fa-filter-circle-xmark text-2xl text-slate-300"></i>
              <p class="font-semibold text-slate-700 text-sm">No parking slots match the selected filters.</p>
              <button type="button" onclick="resetAllSlotFilters()" class="mt-2 text-xs font-semibold text-slate-900 hover:underline">
                Clear all filters
              </button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</section>
</div>

<script>
(function() {
  var searchInput = document.getElementById('slotSearchInput');
  var clearSearchBtn = document.getElementById('clearSearchBtn');
  var areaSelect = document.getElementById('slotAreaSelect');
  var statusSelect = document.getElementById('slotStatusSelect');
  var resetBtn = document.getElementById('resetFiltersBtn');
  var rows = document.querySelectorAll('.slot-row');
  var badge = document.getElementById('slotsCountBadge');
  var noMatchRow = document.getElementById('noSlotsMatchRow');
  var quickButtons = document.querySelectorAll('.quick-area-btn');
  var totalRows = <?= (int)$totalSlotsCount ?>;

  function filterSlots() {
    var query = (searchInput ? searchInput.value : '').trim().toUpperCase();
    var areaId = (areaSelect ? areaSelect.value : '').trim();
    var status = (statusSelect ? statusSelect.value : '').trim().toLowerCase();

    if (clearSearchBtn) {
      if (query.length > 0) {
        clearSearchBtn.classList.remove('hidden');
      } else {
        clearSearchBtn.classList.add('hidden');
      }
    }

    var visibleCount = 0;
    rows.forEach(function(row) {
      var rowSlot = row.getAttribute('data-slot') || '';
      var rowAreaId = row.getAttribute('data-area-id') || '';
      var rowAreaName = (row.getAttribute('data-area-name') || '').toUpperCase();
      var rowStatus = row.getAttribute('data-status') || '';

      var matchesSearch = !query || rowSlot.indexOf(query) !== -1 || rowAreaName.indexOf(query) !== -1;
      var matchesArea = !areaId || rowAreaId === areaId;
      var matchesStatus = !status || rowStatus === status;

      if (matchesSearch && matchesArea && matchesStatus) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    if (badge) {
      badge.textContent = 'Showing ' + visibleCount + ' of ' + totalRows + ' slots';
    }

    if (noMatchRow) {
      if (visibleCount === 0) {
        noMatchRow.classList.remove('hidden');
      } else {
        noMatchRow.classList.add('hidden');
      }
    }

    // Update quick buttons active state
    quickButtons.forEach(function(btn) {
      var btnArea = btn.getAttribute('data-area-id') || '';
      if (btnArea === areaId) {
        btn.className = 'quick-area-btn rounded-xl px-3 py-1.5 text-xs font-semibold transition bg-slate-900 text-white shadow-sm';
      } else {
        btn.className = 'quick-area-btn rounded-xl px-3 py-1.5 text-xs font-semibold transition bg-slate-100 text-slate-600 hover:bg-slate-200';
      }
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', filterSlots);
  }
  if (clearSearchBtn) {
    clearSearchBtn.addEventListener('click', function() {
      if (searchInput) searchInput.value = '';
      filterSlots();
      if (searchInput) searchInput.focus();
    });
  }
  if (areaSelect) {
    areaSelect.addEventListener('change', filterSlots);
  }
  if (statusSelect) {
    statusSelect.addEventListener('change', filterSlots);
  }

  quickButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var areaId = this.getAttribute('data-area-id') || '';
      if (areaSelect) areaSelect.value = areaId;
      filterSlots();
    });
  });

  window.resetAllSlotFilters = function() {
    if (searchInput) searchInput.value = '';
    if (areaSelect) areaSelect.value = '';
    if (statusSelect) statusSelect.value = '';
    filterSlots();
  };

  if (resetBtn) {
    resetBtn.addEventListener('click', function(e) {
      e.preventDefault();
      resetAllSlotFilters();
    });
  }

  // Initial pass to sync quick button active states
  filterSlots();
})();
</script>

<?php include __DIR__.'/../layouts/dashboard_footer.php'; ?>
