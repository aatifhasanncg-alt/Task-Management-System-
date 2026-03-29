// app.js — MISPro Main Application JS

const APP = {
  url: window.APP_URL || '',
  userId: window.APP_USER_ID || 0,
  role: window.APP_ROLE || 'staff',
};

// ── Sidebar ───────────────────────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar')?.classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const s = document.getElementById('sidebar');
  if (!s) return;
  if (!s.contains(e.target) && !e.target.closest('.sidebar-toggle')) {
    s.classList.remove('open');
  }
});

// ── Flash auto-dismiss ────────────────────────────────────────
setTimeout(() => {
  document.querySelectorAll('.auto-dismiss').forEach(el => {
    el.style.transition = 'opacity .5s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 500);
  });
}, 4000);

// ── Toast helper ──────────────────────────────────────────────
function showToast(message, type = 'success') {
  const icons = { success: 'fa-check-circle', error: 'fa-times-circle', warn: 'fa-exclamation-triangle' };
  const colors = { success: '#10b981', error: '#ef4444', warn: '#f59e0b' };
  const el = document.createElement('div');
  el.className = `toast-mis ${type !== 'success' ? type : ''}`;
  el.innerHTML = `<i class="fas ${icons[type]}" style="color:${colors[type]};font-size:1.1rem;flex-shrink:0;"></i>
    <span style="font-size:.87rem;color:#1f2937;">${message}</span>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;color:#9ca3af;cursor:pointer;margin-left:.5rem;"><i class="fas fa-times"></i></button>`;
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .5s'; setTimeout(() => el.remove(), 500); }, 4000);
}

// ── Password visibility ───────────────────────────────────────
function togglePass(inputId, btn) {
  const inp = document.getElementById(inputId);
  const icon = btn.querySelector('i');
  if (!inp) return;
  inp.type = inp.type === 'password' ? 'text' : 'password';
  icon.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

// ── Password strength ─────────────────────────────────────────
function checkPwStrength(val) {
  const rules = {
    'r-len':     val.length >= 8,
    'r-upper':   /[A-Z]/.test(val),
    'r-lower':   /[a-z]/.test(val),
    'r-num':     /[0-9]/.test(val),
    'r-special': /[\W_]/.test(val),
  };
  Object.entries(rules).forEach(([id, ok]) => {
    const el = document.getElementById(id);
    if (el) {
      el.style.color = ok ? '#10b981' : '#ef4444';
      const ic = el.querySelector('i');
      if (ic) ic.className = ok ? 'fas fa-check-circle' : 'fas fa-circle';
    }
  });
}

// ── Confirm util ──────────────────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', function(e) {
    if (!confirm(this.dataset.confirm)) e.preventDefault();
  });
});

// ── AJAX Update Task Status ───────────────────────────────────
function updateTaskStatus(taskId, action, extraData = {}) {
  const formData = new FormData();
  formData.append('task_id', taskId);
  formData.append('action', action);
  Object.entries(extraData).forEach(([k, v]) => formData.append(k, v));
  formData.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');

  return fetch(APP.url + '/ajax/update_task_status.php', {
    method: 'POST',
    body: formData,
  }).then(r => r.json());
}

// ── Transfer Staff Modal ──────────────────────────────────────
function openTransferModal(taskId, deptId, branchId) {
  const modal = document.getElementById('transferModal');
  if (!modal) return;
  document.getElementById('transfer_task_id').value  = taskId;
  document.getElementById('transfer_dept_id').value  = deptId;
  document.getElementById('transfer_branch_id').value = branchId;
  // Load staff
  fetch(`${APP.url}/ajax/get_staff_by_admin.php?dept_id=${deptId}&branch_id=${branchId}`)
    .then(r => r.json())
    .then(data => {
      const sel = document.getElementById('transfer_to_user');
      if (!sel) return;
      sel.innerHTML = '<option value="">-- Select Staff --</option>';
      data.forEach(s => {
        sel.innerHTML += `<option value="${s.id}">${s.full_name} (${s.employee_id || ''})</option>`;
      });
    });
  new bootstrap.Modal(modal).show();
}

// ── Notifications are handled by notifications.js ────────────
// (removed duplicate code — see assets/js/notifications.js)

// ── DataTable helper ──────────────────────────────────────────
function initDT(id, opts = {}) {
  if (typeof $.fn?.DataTable === 'undefined') return;
  return $(`#${id}`).DataTable({
    pageLength: 25, responsive: true,
    dom: '<"d-flex justify-content-between align-items-center mb-3"lf>rtip',
    language: { search: '', searchPlaceholder: 'Search...', lengthMenu: 'Show _MENU_' },
    ...opts
  });
}

// ── Auto-uppercase ────────────────────────────────────────────
document.querySelectorAll('[data-uppercase]').forEach(el => {
  el.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
});

// ── Currency format on blur ───────────────────────────────────
document.querySelectorAll('[data-currency]').forEach(el => {
  el.addEventListener('blur', function() {
    const v = parseFloat(this.value);
    if (!isNaN(v)) this.value = v.toFixed(2);
  });
});

// ── CSRF meta tag reader ──────────────────────────────────────
function getCsrf() {
  return document.querySelector('meta[name="csrf"]')?.content || '';
}
