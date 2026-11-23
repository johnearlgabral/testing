<?php
/**
 * superadmin/reference_data.php - Institutional Records
 *
 * Super Admin tool for the official Departments / Courses / Organizations
 * used to verify registrations. Redesigned as three tabs with search,
 * pagination, an Add modal, and a dependency-checked Delete action
 * (see actions/manage_reference.php for the server-side delete rules).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_recovery.php'; // recoveryCsrfToken() for the delete dialog
requireSuperAdmin();
$pageTitle='Institutional Records'; $activeNav='reference_data';
$departments=$pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();
$courses=$pdo->query('SELECT c.*,d.department_name FROM courses c JOIN departments d ON d.id=c.department_id ORDER BY d.department_name,c.course_name')->fetchAll();
$organizations=$pdo->query('SELECT o.*,d.department_name FROM organizations o LEFT JOIN departments d ON d.id=o.department_id ORDER BY o.organization_name')->fetchAll();
include __DIR__.'/../includes/header.php'; include __DIR__.'/../includes/sidebar.php';

/** One tab panel: toolbar (search + Add) + compact record table + pager. */
function refPanel($type,$title,$rows,$nameCol,$showDept){ ?>
<div class="ref-panel" id="ref-panel-<?= $type ?>"<?= $type==='department'?'':' hidden' ?>>
  <div class="ref-toolbar">
    <h2><?= e($title) ?> <span class="small-note">(<?= count($rows) ?>)</span></h2>
    <div class="ref-toolbar-actions">
      <input class="ref-search" id="ref-search-<?= $type ?>" placeholder="Search <?= e(strtolower($title)) ?>..." oninput="refSearch('<?= $type ?>',this.value)">
      <button class="btn btn-primary" onclick="openAddRef('<?= $type ?>')">+ Add <?= e($type==='department'?'Department':($type==='course'?'Course':'Organization')) ?></button>
    </div>
  </div>
  <div class="card"><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><?php if($showDept): ?><th>Department</th><?php endif; ?><th>Status</th><th></th></tr></thead><tbody id="ref-rows-<?= $type ?>">
  <?php foreach($rows as $row): $label=$row[$nameCol]; $dept=$row['department_name']??''; ?>
    <tr data-search="<?= e(strtolower($label.' '.$dept)) ?>">
      <td id="ref-name-<?= $type ?>-<?= (int)$row['id'] ?>"><?= e($label) ?></td>
      <?php if($showDept): ?><td><?= e($dept ?: 'All departments') ?></td><?php endif; ?>
      <td><span class="badge <?= $row['is_active']?'badge-approved':'badge-default' ?>"><?= $row['is_active']?'Active':'Inactive' ?></span></td>
      <td style="white-space:nowrap">
        <button class="btn btn-sm" onclick="renameRef('<?= $type ?>',<?= (int)$row['id'] ?>)">Rename</button>
        <button class="btn btn-sm" onclick="toggleRef('<?= $type ?>',<?= (int)$row['id'] ?>)"><?= $row['is_active']?'Deactivate':'Activate' ?></button>
        <button class="btn btn-sm btn-danger" data-del-type="<?= $type ?>" data-del-id="<?= (int)$row['id'] ?>" data-del-name="<?= e($label) ?>" onclick="openDeleteRef(this)">Delete</button>
      </td>
    </tr>
  <?php endforeach; ?>
    <tr id="ref-empty-<?= $type ?>" style="display:none"><td colspan="<?= $showDept?4:3 ?>" class="small-note" style="text-align:center;padding:18px">No matching records.</td></tr>
  </tbody></table></div></div>
  <div class="ref-pager">
    <span class="ref-pager-info" id="ref-info-<?= $type ?>"></span>
    <span class="ref-pager-buttons"><button class="btn btn-sm" id="ref-prev-<?= $type ?>" onclick="refPage('<?= $type ?>',-1)">Previous</button><button class="btn btn-sm" id="ref-next-<?= $type ?>" onclick="refPage('<?= $type ?>',1)">Next</button></span>
  </div>
</div>
<?php }
?><main class="main-content">
  <div class="page-header"><div><h1>Institutional Records</h1><p class="page-subtitle">Manage official departments, courses, and organizations.</p></div></div>

  <div class="ref-counts">
    <div class="ref-count-chip"><div class="ref-count-label">Departments</div><div class="ref-count-value"><?= count($departments) ?></div></div>
    <div class="ref-count-chip"><div class="ref-count-label">Courses</div><div class="ref-count-value"><?= count($courses) ?></div></div>
    <div class="ref-count-chip"><div class="ref-count-label">Organizations</div><div class="ref-count-value"><?= count($organizations) ?></div></div>
  </div>

  <div class="ref-tabs">
    <button class="ref-tab active" id="ref-tab-department" onclick="showRefTab('department')">Departments</button>
    <button class="ref-tab" id="ref-tab-course" onclick="showRefTab('course')">Courses</button>
    <button class="ref-tab" id="ref-tab-organization" onclick="showRefTab('organization')">Organizations</button>
  </div>

  <?php refPanel('department','Departments',$departments,'department_name',false); ?>
  <?php refPanel('course','Courses',$courses,'course_name',true); ?>
  <?php refPanel('organization','Organizations',$organizations,'organization_name',true); ?>

  <div class="modal-overlay" id="refAddModal">
    <div class="modal-box">
      <div class="modal-header"><h3 id="refAddTitle">Add record</h3><button class="modal-close" data-close-modal="refAddModal">&times;</button></div>
      <form onsubmit="submitAddRef(event)">
        <div class="modal-body">
          <div class="form-group"><label for="refAddName">Official name</label><input id="refAddName" required maxlength="150"></div>
          <div class="form-group" id="refAddDeptGroup" hidden><label for="refAddDept">Department</label><select id="refAddDept"><option value="">All / optional</option><?php foreach($departments as $d): if($d['is_active']): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['department_name']) ?></option><?php endif; endforeach; ?></select></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn" data-close-modal="refAddModal">Cancel</button><button class="btn btn-primary">Add</button></div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="refDeleteModal">
    <div class="modal-box">
      <div class="modal-header"><h3>Delete record</h3><button class="modal-close" data-close-modal="refDeleteModal">&times;</button></div>
      <div class="modal-body">
        <p>You are about to permanently delete <strong id="refDelName"></strong>.</p>
        <p class="small-note">Deletion is only possible when nothing references the record. If accounts, courses, organizations, or activities still use it, the delete is blocked and you should Deactivate it instead - inactive records are hidden from registration while all existing data is kept.</p>
      </div>
      <div class="modal-footer"><button class="btn" data-close-modal="refDeleteModal">Cancel</button><button class="btn btn-danger" onclick="confirmDeleteRef()">Delete permanently</button></div>
    </div>
  </div>
</main>

<?php $extraScripts = '<script>window.REF_CSRF = ' . json_encode(recoveryCsrfToken()) . ';</script>' . <<<'JS'
<script>
var REF_PER_PAGE = 10;
var refState = { department:{q:'',page:1}, course:{q:'',page:1}, organization:{q:'',page:1} };
var refAddType = 'department';
var refDeleteTarget = null;

function refPost(p) { return postJSON('/UBMSConnect/actions/manage_reference.php', new URLSearchParams(p).toString()); }

function renderRef(t) {
  var s = refState[t];
  var rows = Array.prototype.slice.call(document.querySelectorAll('#ref-rows-' + t + ' tr[data-search]'));
  var matched = rows.filter(function (tr) { return !s.q || tr.getAttribute('data-search').indexOf(s.q) !== -1; });
  var pages = Math.max(1, Math.ceil(matched.length / REF_PER_PAGE));
  if (s.page > pages) s.page = pages;
  if (s.page < 1) s.page = 1;
  var start = (s.page - 1) * REF_PER_PAGE;
  rows.forEach(function (tr) { tr.style.display = 'none'; });
  matched.slice(start, start + REF_PER_PAGE).forEach(function (tr) { tr.style.display = ''; });
  document.getElementById('ref-empty-' + t).style.display = matched.length ? 'none' : '';
  document.getElementById('ref-info-' + t).textContent = matched.length
    ? ('Showing ' + (start + 1) + '-' + Math.min(start + REF_PER_PAGE, matched.length) + ' of ' + matched.length)
    : 'No matching records';
  document.getElementById('ref-prev-' + t).disabled = s.page <= 1;
  document.getElementById('ref-next-' + t).disabled = s.page >= pages;
}
function refSearch(t, v) { refState[t].q = v.toLowerCase().trim(); refState[t].page = 1; renderRef(t); }
function refPage(t, d) { refState[t].page += d; renderRef(t); }

function showRefTab(t) {
  ['department','course','organization'].forEach(function (x) {
    document.getElementById('ref-panel-' + x).hidden = (x !== t);
    document.getElementById('ref-tab-' + x).classList.toggle('active', x === t);
  });
}

function openAddRef(t) {
  refAddType = t;
  var names = { department:'Department', course:'Course', organization:'Organization' };
  document.getElementById('refAddTitle').textContent = 'Add ' + names[t];
  document.getElementById('refAddName').value = '';
  var group = document.getElementById('refAddDeptGroup');
  var sel = document.getElementById('refAddDept');
  group.hidden = (t === 'department');
  sel.value = '';
  sel.required = (t === 'course'); // courses must belong to a department (server enforces it too)
  document.getElementById('refAddModal').classList.add('open');
}
function submitAddRef(e) {
  e.preventDefault();
  var p = { action:'add', type:refAddType, name:document.getElementById('refAddName').value };
  var d = document.getElementById('refAddDept').value;
  if (refAddType !== 'department' && d) p.department_id = d;
  refPost(p).then(function (x) {
    if (x.success) location.reload();
    else alert(x.message);
  });
}

function renameRef(t, id) {
  var el = document.getElementById('ref-name-' + t + '-' + id);
  var n = prompt('Official name:', el.textContent);
  if (n) refPost({ action:'rename', type:t, id:id, name:n }).then(function (x) { if (x.success) location.reload(); else alert(x.message); });
}
function toggleRef(t, id) {
  refPost({ action:'toggle', type:t, id:id }).then(function (x) { if (x.success) location.reload(); else alert(x.message); });
}

function openDeleteRef(btn) {
  refDeleteTarget = { t: btn.getAttribute('data-del-type'), id: parseInt(btn.getAttribute('data-del-id'), 10) };
  document.getElementById('refDelName').textContent = btn.getAttribute('data-del-name');
  document.getElementById('refDeleteModal').classList.add('open');
}
function confirmDeleteRef() {
  if (!refDeleteTarget) return;
  refPost({ action:'delete', type:refDeleteTarget.t, id:refDeleteTarget.id, csrf: window.REF_CSRF || '' }).then(function (x) {
    document.getElementById('refDeleteModal').classList.remove('open');
    refDeleteTarget = null;
    if (x.success) location.reload();
    else alert(x.message); // blocked deletes explain exactly what still references the record
  });
}

['department','course','organization'].forEach(function (t) { renderRef(t); });
</script>
JS;
include __DIR__.'/../includes/footer.php'; ?>
